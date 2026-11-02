/* ------------------------------------------------------------------------
   Nexas — Financial Manager portal

   A window onto the existing /api/v1 surface, not a second backend. It holds
   a Sanctum token in sessionStorage, signs itself out after fifteen minutes
   of inactivity, and calls the same endpoints the rest of the system uses.

   Two rules shape the whole file:

   1. Nothing is decided by what is drawn. Every screen still calls the
      endpoint, and the endpoint's policy is what answers. A button that would
      be refused is a button whose request comes back 403, and the 403 is
      shown rather than hidden.
   2. Every decision carries an idempotency key. A key is minted when a person
      commits to a decision and travels with the request, so a double click,
      a retry after a dropped connection, or a replayed request decides the
      document once.
   ------------------------------------------------------------------------ */

'use strict';

(function () {

  const API = '/api/v1';
  const IDLE_LIMIT_MS = 15 * 60 * 1000;   // the fifteen minutes the role is promised
  const IDLE_WARN_MS = 60 * 1000;         // ... and the minute it warns first
  const CURRENCY = 'TZS';
  const APP_VERSION = 'fm-portal/1.0';

  const KEYS = {
    token: 'nexas.fm.token',
    company: 'nexas.fm.company',
    user: 'nexas.fm.user',
    theme: 'nexas.fm.theme',
    accent: 'nexas.fm.accent',
    glass: 'nexas.fm.glass',
    motion: 'nexas.fm.motion'
  };

  const S = {
    token: null,
    companyId: null,
    user: null,
    screen: 'dashboard',
    idleTimer: null,
    idleWarn: null,
    countdown: null,
    signingOut: false
  };

  // Small caches. The calendar and the chart of accounts change rarely and are
  // read by three screens each.
  const cache = { periods: null, accounts: null };

  /* ------------------------------------------------------------- helpers */

  const $ = (sel, root) => (root || document).querySelector(sel);
  const $$ = (sel, root) => Array.prototype.slice.call((root || document).querySelectorAll(sel));

  function esc(value) {
    return String(value === null || value === undefined ? '' : value).replace(
      /[&<>"']/g,
      c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])
    );
  }

  const money = new Intl.NumberFormat('en-TZ', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const number = new Intl.NumberFormat('en-TZ');

  function fmtMoney(value) {
    if (value === null || value === undefined || value === '') return '\u2014';
    const n = Number(value);
    if (!isFinite(n)) return String(value);
    return money.format(n);
  }

  function fmtQty(value) {
    if (value === null || value === undefined || value === '') return '\u2014';
    const n = Number(value);
    return isFinite(n) ? number.format(n) : String(value);
  }

  function moneyClass(value) {
    const n = Number(value);
    if (!isFinite(n) || n === 0) return '';
    return n > 0 ? 'is-positive' : 'is-negative';
  }

  function fmtDateTime(value) {
    if (!value) return '\u2014';
    const d = new Date(value);
    if (isNaN(d)) return String(value);
    return d.toLocaleString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
  }

  function fmtDate(value) {
    if (!value) return '\u2014';
    return String(value).slice(0, 10);
  }

  function today() {
    const d = new Date();
    const p = n => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
  }

  function yearStart() {
    return `${new Date().getFullYear()}-01-01`;
  }

  function newKey() {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') return window.crypto.randomUUID();
    return 'k-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 12);
  }

  const sleep = ms => new Promise(r => setTimeout(r, ms));

  function json(value) {
    try { return JSON.stringify(value, null, 2); } catch (e) { return String(value); }
  }

  /* ---------------------------------------------------------------- api */

  function ApiError(status, message, errors) {
    this.name = 'ApiError';
    this.status = status;
    this.message = message || 'The request could not be completed.';
    this.errors = errors || null;
  }
  ApiError.prototype = Object.create(Error.prototype);

  const statusMessage = status => ({
    400: 'The request was not understood.',
    401: 'Your session has ended. Please sign in again.',
    403: 'You are not allowed to do that.',
    404: 'That record could not be found.',
    409: 'It changed while you were looking at it, or a decision has already been made.',
    422: 'Some of what was sent could not be accepted.',
    429: 'Too many attempts. Please wait a moment and try again.',
    500: 'Something went wrong on the server.'
  }[status] || 'The request could not be completed.');

  function headers(body, key) {
    const h = { Accept: 'application/json' };
    if (S.token) h.Authorization = 'Bearer ' + S.token;
    if (S.companyId) h['X-Company-Id'] = String(S.companyId);
    if (key) h['Idempotency-Key'] = key;
    if (body !== undefined) h['Content-Type'] = 'application/json';
    return h;
  }

  async function api(path, opts) {
    opts = opts || {};
    const method = opts.method || 'GET';
    const res = await fetch(API + path, {
      method,
      headers: headers(opts.body, opts.key),
      body: opts.body === undefined ? undefined : JSON.stringify(opts.body)
    });

    if (res.status === 401) {
      sessionExpired();
      throw new ApiError(401, statusMessage(401));
    }

    let payload = null;
    const text = await res.text();
    if (text) {
      try { payload = JSON.parse(text); } catch (e) { payload = null; }
    }

    if (!res.ok) {
      // A validation failure names its fields; a domain refusal carries a
      // sentence meant for a person. Both are shown as they arrived.
      let message = (payload && payload.message) || statusMessage(res.status);
      if (payload && payload.errors) {
        const first = Object.keys(payload.errors)[0];
        if (first && payload.errors[first] && payload.errors[first][0]) message = payload.errors[first][0];
      }
      throw new ApiError(res.status, message, payload && payload.errors);
    }
    return payload;
  }

  window.addEventListener('unhandledrejection', e => {
    if (e.reason && e.reason.name === 'ApiError' && e.reason.status === 401) e.preventDefault();
  });

  /* -------------------------------------------------------------- toasts */

  function toast(kind, text, detail) {
    const glyph = { ok: '\u2714', bad: '\u26A0', info: '\u2139' }[kind] || '\u2139';
    const node = document.createElement('div');
    node.className = 'toast toast--' + kind;
    node.innerHTML =
      `<span class="toast__glyph" aria-hidden="true">${glyph}</span>` +
      `<span class="toast__text">${esc(text)}${detail ? `<small>${esc(detail)}</small>` : ''}</span>`;
    $('#toasts').appendChild(node);
    setTimeout(() => node.classList.add('is-going'), 6000);
    setTimeout(() => node.remove(), 6600);
  }

  /* --------------------------------------------------------------- theme */

  function applyTheme(theme) {
    document.documentElement.setAttribute('data-theme', theme);
    try { localStorage.setItem(KEYS.theme, theme); } catch (e) { /* ignore */ }
    $$('[data-theme-set]').forEach(b => b.classList.toggle('is-active', b.dataset.themeSet === theme));
  }

  function applyAccent(accent) {
    document.documentElement.setAttribute('data-accent', accent);
    try { localStorage.setItem(KEYS.accent, accent); } catch (e) { /* ignore */ }
    $$('[data-accent-set]').forEach(b => b.classList.toggle('is-active', b.dataset.accentSet === accent));
  }

  function applyGlass(mode) {
    document.documentElement.setAttribute('data-glass', mode);
    try { localStorage.setItem(KEYS.glass, mode); } catch (e) { /* ignore */ }
  }

  function initTheme() {
    const root = document.documentElement;
    applyTheme(root.getAttribute('data-theme') || 'system');
    applyAccent(root.getAttribute('data-accent') || 'cyan');
    $$('[data-theme-set]').forEach(b => b.addEventListener('click', () => applyTheme(b.dataset.themeSet)));
    $$('[data-accent-set]').forEach(b => b.addEventListener('click', () => applyAccent(b.dataset.accentSet)));
    $$('[data-glass-toggle]').forEach(b => b.addEventListener('click', () => {
      applyGlass(document.documentElement.getAttribute('data-glass') === 'off' ? 'on' : 'off');
    }));
  }

  /* ---------------------------------------------------------------- auth */

  function storeSession(payload) {
    S.token = payload.token;
    S.companyId = payload.company_id;
    S.user = payload.user;
    try {
      sessionStorage.setItem(KEYS.token, S.token);
      sessionStorage.setItem(KEYS.company, String(S.companyId));
      sessionStorage.setItem(KEYS.user, JSON.stringify(S.user));
    } catch (e) { /* a browser refusing storage still works for this tab */ }
  }

  function restoreSession() {
    try {
      S.token = sessionStorage.getItem(KEYS.token);
      const c = sessionStorage.getItem(KEYS.company);
      S.companyId = c ? Number(c) : null;
      const u = sessionStorage.getItem(KEYS.user);
      S.user = u ? JSON.parse(u) : null;
    } catch (e) { S.token = null; }
    return Boolean(S.token);
  }

  function clearSession() {
    S.token = null;
    S.companyId = null;
    S.user = null;
    cache.periods = null;
    cache.accounts = null;
    try {
      sessionStorage.removeItem(KEYS.token);
      sessionStorage.removeItem(KEYS.company);
      sessionStorage.removeItem(KEYS.user);
    } catch (e) { /* ignore */ }
  }

  function showLogin() {
    $('#app-screen').hidden = true;
    $('#login-screen').hidden = false;
    document.body.classList.remove('drawer-open');
    $('#login-password').value = '';
    $('#login-email').focus();
  }

  function showApp() {
    $('#login-screen').hidden = true;
    $('#app-screen').hidden = false;
    const user = S.user || {};
    $('#who-name').textContent = user.name || 'Financial Manager';
    $('#who-role').textContent = (user.roles && user.roles.join(', ')) || 'Financial Manager';
    $('#who-avatar').textContent = (user.name || 'FM').split(/\s+/).map(w => w[0]).slice(0, 2).join('').toUpperCase();
    $('#foot-company').textContent = S.companyId ? 'Company #' + S.companyId : '';
    go('dashboard');
  }

  function sessionExpired() {
    if (S.signingOut) return;
    clearSession();
    showLogin();
    setLoginError('Your session has ended. Please sign in again.');
  }

  async function signOut(options) {
    if (S.signingOut) return;
    S.signingOut = true;
    stopIdleWatch();
    const message = (options && options.message) || 'Signed out safely.';

    try {
      if (S.token) await api('/auth/logout', { method: 'POST' });
    } catch (e) {
      // A token that has already expired is still a token this window is
      // finished with, so the local session is dropped either way.
    }

    clearSession();
    showLogin();
    S.signingOut = false;
    toast('ok', message, 'The token this window was holding has been revoked.');
  }

  function setLoginError(text, field) {
    const box = $('[data-error="login"]');
    if (box) {
      box.hidden = !text;
      box.textContent = text || '';
    }
    if (field) {
      const target = $(`[data-error="${field}"]`);
      const input = field === 'email' ? $('#login-email') : $('#login-password');
      if (target) target.textContent = text || '';
      if (input) input.setAttribute('aria-invalid', text ? 'true' : 'false');
    }
  }

  function clearLoginErrors() {
    setLoginError('');
    $$('.field__error').forEach(e => { e.textContent = ''; });
    $$('#login-form input').forEach(i => i.removeAttribute('aria-invalid'));
  }

  /* ---------------------------------------------------------- idle watch */

  function startIdleWatch() {
    stopIdleWatch();
    resetIdle();
    ['mousemove', 'mousedown', 'keydown', 'touchstart', 'scroll', 'focus'].forEach(evt =>
      document.addEventListener(evt, resetIdle, { passive: true })
    );
    S.countdown = setInterval(tickIdle, 1000);
  }

  function stopIdleWatch() {
    ['mousemove', 'mousedown', 'keydown', 'touchstart', 'scroll', 'focus'].forEach(evt =>
      document.removeEventListener(evt, resetIdle)
    );
    clearTimeout(S.idleTimer);
    clearTimeout(S.idleWarn);
    clearInterval(S.countdown);
    S.idleTimer = S.idleWarn = S.countdown = null;
  }

  function resetIdle() {
    if (!S.token) return;
    clearTimeout(S.idleTimer);
    clearTimeout(S.idleWarn);
    S.idleAt = Date.now() + IDLE_LIMIT_MS;
    S.idleWarn = setTimeout(() => {
      toast('info', 'One minute of inactivity left', 'This window will sign itself out shortly.');
    }, IDLE_LIMIT_MS - IDLE_WARN_MS);
    S.idleTimer = setTimeout(() => {
      signOut({ message: 'Signed out safely after fifteen minutes of inactivity.' });
    }, IDLE_LIMIT_MS);
  }

  function tickIdle() {
    const note = $('#idle-note');
    if (!note || !S.idleAt) return;
    const left = Math.max(0, Math.round((S.idleAt - Date.now()) / 1000));
    const m = Math.floor(left / 60);
    const s = String(left % 60).padStart(2, '0');
    note.textContent = `Signs out in ${m}:${s} if idle`;
  }

  /* -------------------------------------------------------------- login */

  function wireLogin() {
    $('#login-reveal').addEventListener('click', () => {
      const input = $('#login-password');
      const showing = input.type === 'text';
      input.type = showing ? 'password' : 'text';
      $('#login-reveal').textContent = showing ? 'Show' : 'Hide';
    });

    $('#login-form').addEventListener('submit', async event => {
      event.preventDefault();
      clearLoginErrors();

      const email = $('#login-email').value.trim();
      const password = $('#login-password').value;
      const button = $('#login-submit');

      if (!email) { setLoginError('Enter the email address you sign in with.', 'email'); return; }
      if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) { setLoginError('That does not look like an email address.', 'email'); return; }
      if (!password) { setLoginError('Enter your password.', 'password'); return; }

      button.disabled = true;
      $('.spinner', button).hidden = false;
      $('.btn__label', button).textContent = 'Signing in';

      try {
        const payload = await api('/auth/financial-manager/login', {
          method: 'POST',
          body: { email, password, device_name: 'Financial Manager portal' }
        });

        if (payload.two_factor) {
          button.disabled = false;
          $('.spinner', button).hidden = true;
          $('.btn__label', button).textContent = 'Sign in';
          openTwoFactor(payload.challenge_token, email);
          return;
        }

        storeSession(payload);
        startIdleWatch();
        showApp();
        toast('ok', 'Signed in', 'Welcome, ' + ((payload.user && payload.user.name) || email) + '.');
        pollApprovals();
      } catch (error) {
        button.disabled = false;
        $('.spinner', button).hidden = true;
        $('.btn__label', button).textContent = 'Sign in';
        const field = error.errors && error.errors.email ? 'email' : (error.errors && error.errors.password ? 'password' : null);
        setLoginError(error.message, field);
      }
    });
  }

  // An account carrying a second factor is not signed in by its password. The
  // portal asks for the code rather than leaving the member at a dead end.
  function openTwoFactor(challengeToken, email) {
    let box = $('#login-two-factor');
    if (!box) {
      box = document.createElement('label');
      box.id = 'login-two-factor';
      box.className = 'field';
      box.innerHTML = '<span class="field__label">Code from your authenticator app</span>' +
        '<input id="login-code" inputmode="numeric" autocomplete="one-time-code" placeholder="123456">' +
        '<em class="field__error" data-error="code"></em>';
      $('#login-form').insertBefore(box, $('#login-submit'));
    }
    box.hidden = false;
    $('#login-code').value = '';
    $('#login-code').focus();
    setLoginError('Enter the six-digit code for ' + email + '.');

    $('#login-code').onkeydown = async event => {
      if (event.key !== 'Enter') return;
      event.preventDefault();
      const code = $('#login-code').value.trim();
      if (!code) return;
      try {
        const payload = await api('/auth/two-factor/challenge', {
          method: 'POST',
          body: { challenge_token: challengeToken, code, device_name: 'Financial Manager portal' }
        });
        clearLoginErrors();
        box.hidden = true;
        storeSession(payload);
        startIdleWatch();
        showApp();
        toast('ok', 'Signed in', 'The second factor was accepted.');
        pollApprovals();
      } catch (error) {
        setLoginError(error.message, 'code');
      }
    };
  }

  /* --------------------------------------------------------------- shell */

  const SCREENS = {
    dashboard: { title: 'Dashboard', sub: 'Where the books stand right now', draw: drawDashboard },
    approvals: { title: 'Approvals', sub: 'The queue waiting on a second pair of hands', draw: drawApprovals },
    forecast: { title: 'Forecast', sub: 'The rest of the open year, projected — and labelled as projections', draw: drawForecast },
    budgets: { title: 'Budgets', sub: 'What the company plans to earn and spend, period by period', draw: drawBudgets },
    reports: { title: 'Reports', sub: 'Every statement, ageing report and the ledger behind them', draw: drawReports },
    periods: { title: 'Period close', sub: 'Closing the year, reopening a period, and signing off reconciliations', draw: drawPeriods },
    audit: { title: 'Audit log', sub: 'Who changed what, and what it held before', draw: drawAudit }
  };

  function go(name) {
    const screen = SCREENS[name] ? name : 'dashboard';
    S.screen = screen;
    $$('.nav__item').forEach(b => b.classList.toggle('is-active', b.dataset.screen === screen));
    $('#screen-title').textContent = SCREENS[screen].title;
    $('#screen-sub').textContent = SCREENS[screen].sub;
    document.body.classList.remove('drawer-open');
    closeDrawer();
    const view = $('#view');
    view.innerHTML = '';
    SCREENS[screen].draw(view);
  }

  function openDrawer() {
    document.body.classList.add('drawer-open');
    $('#scrim').hidden = false;
    $('#menu').setAttribute('aria-expanded', 'true');
  }

  function closeDrawer() {
    document.body.classList.remove('drawer-open');
    $('#scrim').hidden = true;
    const menu = $('#menu');
    if (menu) menu.setAttribute('aria-expanded', 'false');
  }

  function wireShell() {
    $$('.nav__item').forEach(b => b.addEventListener('click', () => go(b.dataset.screen)));
    $('#menu').addEventListener('click', openDrawer);
    $('#scrim').addEventListener('click', closeDrawer);
    $('#signout').addEventListener('click', () => signOut({}));
    document.addEventListener('keydown', e => { if (e.key === 'Escape') { closeDrawer(); closeModal(); } });
  }

  async function pollApprovals() {
    try {
      const payload = await api('/approval-requests?status=pending&per_page=1');
      const total = payload && payload.meta ? payload.meta.total : 0;
      const pill = $('#nav-approvals-count');
      pill.hidden = !total;
      pill.textContent = String(total);
    } catch (e) { /* the badge is a courtesy; the Approvals screen reports properly */ }
  }

  function setStatus(text) { $('#foot-status').textContent = text; }

  /* -------------------------------------------------------- view plumbing */

  function skeleton(lines) {
    const rows = Array.from({ length: lines || 5 }, () => '<div class="sk sk--row"></div>').join('');
    return `<div class="card"><div class="skeleton"><div class="sk sk--title"></div>${rows}</div></div>`;
  }

  function emptyState(glyph, title, text) {
    return `<div class="state"><span class="state__glyph" aria-hidden="true">${glyph}</span>` +
      `<h4>${esc(title)}</h4><p>${esc(text)}</p></div>`;
  }

  function errorState(error) {
    const forbidden = error && error.status === 403;
    return `<div class="card">${emptyState(
      forbidden ? '\u26D4' : '\u26A0',
      forbidden ? 'Not allowed' : 'That did not load',
      (error && error.message) || 'Something went wrong while reading this.'
    )}</div>`;
  }

  /**
   * Every screen is drawn the same way: a skeleton while the reads are open,
   * the error the endpoint returned if any of them refused, and the screen
   * itself if they all answered.
   */
  async function load(container, produce) {
    container.innerHTML = skeleton();
    try {
      const html = await produce();
      container.innerHTML = html || emptyState('\u25CB', 'Nothing to show', 'There is nothing here yet.');
    } catch (error) {
      if (error && error.status === 401) return;
      container.innerHTML = errorState(error);
    }
  }

  const card = (title, subtitle, body, tools) =>
    `<section class="card"><div class="card__head"><div><h3>${esc(title)}</h3>` +
    (subtitle ? `<p>${esc(subtitle)}</p>` : '') + '</div>' +
    (tools ? `<div class="spacer">${tools}</div>` : '') + `</div>${body}</section>`;

  const badge = (kind, text) => `<span class="badge badge--${kind}">${esc(text)}</span>`;

  const statusBadge = status => ({
    pending: badge('pending', 'Pending'),
    approved: badge('approved', 'Approved'),
    rejected: badge('rejected', 'Rejected'),
    open: badge('ok', 'Open'),
    closed: badge('muted', 'Closed'),
    locked: badge('info', 'Locked'),
    active: badge('ok', 'Active'),
    draft: badge('muted', 'Draft'),
    posted: badge('ok', 'Posted'),
    queued: badge('pending', 'Queued'),
    running: badge('info', 'Building'),
    done: badge('approved', 'Ready'),
    failed: badge('rejected', 'Failed')
  }[status] || badge('muted', status || '\u2014'));

  const spinnerButton = (label, act, attrs, style) =>
    `<button class="btn ${style || 'btn--quiet'} btn--sm" data-act="${act}" ${attrs || ''}>${esc(label)}</button>`;

  /* ----------------------------------------------------------- calendar */

  async function periods() {
    if (!cache.periods) {
      const payload = await api('/fiscal-periods');
      cache.periods = (payload && payload.data) || [];
    }
    return cache.periods;
  }

  function currentPeriod(list) {
    const t = today();
    return list.find(p => p.starts_on <= t && t <= p.ends_on) || list[list.length - 1] || null;
  }

  function periodsOfYear(list, yearUlid) {
    return list.filter(p => p.fiscal_year && p.fiscal_year.ulid === yearUlid);
  }

  async function accounts() {
    if (!cache.accounts) {
      const payload = await api('/accounts?per_page=100');
      cache.accounts = (payload && payload.data) || [];
    }
    return cache.accounts;
  }

  const accountOptions = (list, selected) =>
    list.map(a => `<option value="${a.id}"${Number(selected) === Number(a.id) ? ' selected' : ''}>` +
      `${esc(a.code)} \u00b7 ${esc(a.name)}</option>`).join('');

  /* --------------------------------------------------- screen: dashboard */

  async function drawDashboard(container) {
    setStatus('Reading the books');
    await load(container, async () => {
      const list = await periods();
      const current = currentPeriod(list);
      const t = today();

      const yearPeriods = current ? periodsOfYear(list, current.fiscal_year && current.fiscal_year.ulid) : [];
      const from = (yearPeriods[0] && yearPeriods[0].starts_on) || yearStart();

      const results = await Promise.allSettled([
        api(`/reports/profit-and-loss?from=${from}&to=${t}`),
        api(`/reports/cash-position?as_of=${t}`),
        api('/approval-requests?status=pending&per_page=5'),
        api('/audit-logs?per_page=6')
      ]);

      const pl = results[0].status === 'fulfilled' ? results[0].value : null;
      const cash = results[1].status === 'fulfilled' ? results[1].value : null;
      const queue = results[2].status === 'fulfilled' ? results[2].value : null;
      const audit = results[3].status === 'fulfilled' ? results[3].value : null;

      const revenue = pl ? pl.revenue.total : null;
      const expense = pl ? pl.expense.total : null;
      const net = pl ? pl.net_profit : null;

      const metrics = `
        <div class="grid grid--metrics">
          <section class="card"><div class="metric__label">Revenue</div>
            <div class="metric__value">${fmtMoney(revenue)}</div>
            <p class="metric__note">${esc(from)} to ${esc(t)} \u00b7 ${CURRENCY}</p></section>
          <section class="card"><div class="metric__label">Expenses</div>
            <div class="metric__value">${fmtMoney(expense)}</div>
            <p class="metric__note">The same period, read the other way</p></section>
          <section class="card"><div class="metric__label">Net profit</div>
            <div class="metric__value ${moneyClass(net)}">${fmtMoney(net)}</div>
            <p class="metric__note">Revenue less expenses, as the ledger holds it</p></section>
          <section class="card"><div class="metric__label">Cash and bank</div>
            <div class="metric__value">${fmtMoney(cash ? cash.total : null)}</div>
            <p class="metric__note">As at ${esc(t)}</p></section>
        </div>`;

      const accountsRows = cash && cash.accounts && cash.accounts.length
        ? `<div class="table-wrap"><table class="data"><thead><tr><th>Code</th><th>Account</th><th>Kind</th><th class="num">Balance</th></tr></thead><tbody>` +
          cash.accounts.map(a => `<tr><td>${esc(a.code)}</td><td>${esc(a.name)}</td>` +
            `<td>${a.is_bank ? 'Bank' : (a.is_cash ? 'Cash' : '\u2014')}</td>` +
            `<td class="num">${fmtMoney(a.balance)}</td></tr>`).join('') +
          `</tbody><tfoot><tr><td colspan="3">Total</td><td class="num">${fmtMoney(cash.total)}</td></tr></tfoot></table></div>`
        : emptyState('\u25CB', 'No cash accounts', 'Nothing is classified as cash or bank in the chart yet.');

      const queueRows = queue && queue.data && queue.data.length
        ? `<div class="inline-list">` + queue.data.map(r => `
            <div class="inline-row">
              <div class="inline-row__main">
                <strong>${esc(r.document_type_label)} \u00b7 ${esc(r.document_reference || r.document_ulid)}</strong>
                <small>Raised ${esc(fmtDateTime(r.created_at))} \u00b7 decision by no one yet</small>
              </div>
              <div class="inline-row__actions">
                ${statusBadge(r.status)}
                <button class="btn btn--quiet btn--sm" data-act="open-screen" data-screen="approvals">Open</button>
              </div>
            </div>`).join('') + `</div>`
        : emptyState('\u2714', 'The queue is clear', 'Nothing is waiting for a decision.');

      const auditRows = audit && audit.data && audit.data.length
        ? `<div class="table-wrap"><table class="data"><thead><tr><th>When</th><th>Action</th><th>Subject</th><th>Who</th><th>Chain</th></tr></thead><tbody>` +
          audit.data.map(r => `<tr><td>${esc(fmtDateTime(r.created_at))}</td><td>${esc(r.action)}</td>` +
            `<td>${esc(r.subject_type || '\u2014')}${r.subject_id ? ' #' + esc(r.subject_id) : ''}</td>` +
            `<td>${esc(r.user ? r.user.name : '\u2014')}</td>` +
            `<td>${r.chain_valid === true ? badge('ok', 'Verified') : badge('bad', 'Broken')}</td></tr>`).join('') +
          `</tbody></table></div>`
        : emptyState('\u25CB', 'No trail yet', 'Nothing has been written to the audit chain.');

      setStatus('Ready');
      return metrics +
        card('Cash position', 'What sits in each cash and bank account, and the total', accountsRows) +
        card('Waiting on a decision', 'The head of the approvals queue', queueRows) +
        card('Latest in the audit chain', 'Newest first; each link checked as it is read', auditRows);
    });
  }

  /* --------------------------------------------------- screen: approvals */

  const approvalFilters = { status: 'pending', document_type: '', page: 1 };

  async function drawApprovals(container) {
    setStatus('Reading the queue');
    await load(container, async () => {
      const query = new URLSearchParams();
      if (approvalFilters.status) query.set('status', approvalFilters.status);
      if (approvalFilters.document_type) query.set('document_type', approvalFilters.document_type);
      query.set('page', String(approvalFilters.page));
      query.set('per_page', '15');

      const payload = await api('/approval-requests?' + query.toString());
      const rows = (payload && payload.data) || [];
      const meta = (payload && payload.meta) || {};

      const toolbar = `
        <div class="toolbar">
          <label class="field"><span class="field__label">Status</span>
            <select data-act="approval-filter" data-name="status">
              ${['pending', 'approved', 'rejected', ''].map(v =>
                `<option value="${v}"${approvalFilters.status === v ? ' selected' : ''}>${v === '' ? 'Every decision' : v.charAt(0).toUpperCase() + v.slice(1)}</option>`
              ).join('')}
            </select></label>
          <label class="field"><span class="field__label">Document</span>
            <select data-act="approval-filter" data-name="document_type">
              ${[['', 'Every kind'], ['journal', 'Journal'], ['bill', 'Supplier bill'], ['payment', 'Payment'],
                 ['credit_note', 'Credit note'], ['debit_note', 'Debit note'], ['asset_disposal', 'Asset disposal']]
                .map(([v, label]) => `<option value="${v}"${approvalFilters.document_type === v ? ' selected' : ''}>${label}</option>`).join('')}
            </select></label>
          <span class="spacer"></span>
          <span class="muted" style="color:var(--mut);font-size:12.5px">${esc(meta.total || 0)} in the line</span>
        </div>`;

      const table = rows.length
        ? `<div class="table-wrap"><table class="data">
            <thead><tr><th>Document</th><th>Reference</th><th>Raised by</th><th>Raised</th><th>Status</th><th>Decision</th><th></th></tr></thead>
            <tbody>` + rows.map(r => `
              <tr>
                <td>${esc(r.document_type_label)}</td>
                <td>${esc(r.document_reference || '\u2014')}<div class="muted" style="color:var(--mut);font-size:11.5px">${esc(r.document_ulid)}</div></td>
                <td>${r.requested_by_user_id === null ? '\u2014' : 'User #' + esc(r.requested_by_user_id)}</td>
                <td>${esc(fmtDateTime(r.created_at))}</td>
                <td>${statusBadge(r.status)}</td>
                <td>${r.decision_reason ? esc(r.decision_reason) : '<span class="muted">\u2014</span>'}</td>
                <td class="num">${r.status === 'pending' ? `
                  <button class="btn btn--ok btn--sm" data-act="approve" data-ulid="${esc(r.ulid)}"
                    data-ref="${esc(r.document_type_label + ' ' + (r.document_reference || r.document_ulid))}">Approve</button>
                  <button class="btn btn--bad btn--sm" data-act="reject" data-ulid="${esc(r.ulid)}"
                    data-ref="${esc(r.document_type_label + ' ' + (r.document_reference || r.document_ulid))}">Reject</button>
                ` : badge('muted', 'Decided')}</td>
              </tr>`).join('') + `</tbody></table></div>`
        : emptyState('\u2714', 'Nothing in this view',
            approvalFilters.status === 'pending'
              ? 'No document is waiting for a decision.'
              : 'No decision matches this filter.');

      const pager = (meta.last_page || 1) > 1
        ? `<div class="toolbar" style="margin-top:12px">
            <button class="btn btn--quiet btn--sm" data-act="approval-page" data-page="${Math.max(1, approvalFilters.page - 1)}"
              ${approvalFilters.page <= 1 ? 'disabled' : ''}>Previous</button>
            <span style="color:var(--mut);font-size:12.5px">Page ${esc(meta.current_page || 1)} of ${esc(meta.last_page || 1)}</span>
            <button class="btn btn--quiet btn--sm" data-act="approval-page" data-page="${Math.min(meta.last_page || 1, approvalFilters.page + 1)}"
              ${approvalFilters.page >= (meta.last_page || 1) ? 'disabled' : ''}>Next</button>
           </div>`
        : '';

      const raise = `
        <div class="toolbar">
          <label class="field"><span class="field__label">Document kind</span>
            <select id="raise-type">
              <option value="journal">Journal</option>
              <option value="bill">Supplier bill</option>
              <option value="payment">Payment</option>
              <option value="credit_note">Credit note</option>
              <option value="debit_note">Debit note</option>
              <option value="asset_disposal">Asset disposal</option>
            </select></label>
          <label class="field" style="min-width:320px"><span class="field__label">Document ULID</span>
            <input id="raise-ulid" maxlength="26" placeholder="01Jxxxxxxxxxxxxxxxxxxxxxxx"></label>
          <button class="btn btn--primary" data-act="raise">Put it in the queue</button>
        </div>
        <div class="note" style="margin-top:12px">
          The queue is what makes a decision a second pair of hands. Raising a document here and then
          approving it is refused — the same person may not be both signatures — and that refusal is
          what the portal will show you.
        </div>`;

      setStatus('Ready');
      return card('The queue', 'Deciding accepts or turns down what somebody else entered', toolbar + table + pager) +
        card('Raise a document', 'Put a journal, bill, payment or note in the line for a decision', raise);
    });
  }

  async function runApprovalFilter(event) {
    const sel = event.target;
    approvalFilters[sel.dataset.name] = sel.value;
    approvalFilters.page = 1;
    go('approvals');
  }

  async function raiseRequest() {
    const type = $('#raise-type').value;
    const ulid = $('#raise-ulid').value.trim();
    if (ulid.length !== 26) { toast('bad', 'A document ULID is 26 characters.', 'Check the identifier and try again.'); return; }

    const button = $('[data-act="raise"]');
    button.disabled = true;
    try {
      await api('/approval-requests', {
        method: 'POST',
        key: newKey(),
        body: { document_type: type, document_ulid: ulid }
      });
      toast('ok', 'In the queue', 'The document now waits for a decision.');
      approvalFilters.status = 'pending';
      approvalFilters.page = 1;
      cache.periods = cache.periods; // untouched; only the queue changed
      go('approvals');
      pollApprovals();
    } catch (error) {
      toast('bad', error.message, 'The document was not queued.');
      if (error.status === 409) { go('approvals'); pollApprovals(); }
    } finally {
      button.disabled = false;
    }
  }

  function approveRequest(ulid, reference) {
    confirmModal({
      title: 'Approve ' + (reference || 'this document') + '?',
      body: '<p>The decision is final and is written to the audit chain with your name on it.</p>' +
            '<p>The request carries an idempotency key, so clicking twice decides it once.</p>',
      okLabel: 'Approve',
      tone: 'primary'
    }).then(async confirmed => {
      if (!confirmed) return;
      try {
        const payload = await api(`/approval-requests/${ulid}/approve`, { method: 'POST', key: newKey() });
        toast('ok', 'Approved', 'Decided at ' + fmtDateTime(payload && payload.data && payload.data.decided_at) + '.');
        go('approvals');
        pollApprovals();
      } catch (error) {
        toast('bad', error.message, 'The decision was not recorded.');
        go('approvals');
      }
    });
  }

  function rejectRequest(ulid, reference) {
    promptModal({
      title: 'Reject ' + (reference || 'this document') + '?',
      label: 'Why it is being turned down',
      hint: 'At least 3 characters. The reason is written into the audit chain beside the decision.',
      minLength: 3,
      maxLength: 500,
      okLabel: 'Reject'
    }).then(async reason => {
      if (reason === null) return;
      try {
        const payload = await api(`/approval-requests/${ulid}/reject`, {
          method: 'POST', key: newKey(), body: { reason }
        });
        toast('ok', 'Rejected', 'The reason was recorded with the decision.');
        void payload;
        go('approvals');
        pollApprovals();
      } catch (error) {
        toast('bad', error.message, 'The rejection was not recorded.');
        go('approvals');
      }
    });
  }

  /* ---------------------------------------------------- screen: forecast */

  async function drawForecast(container) {
    setStatus('Building the projection');
    await load(container, async () => {
      const report = await api('/reports/forecast');

      const banner = `
        <div class="note note--warn">
          <strong>These figures are forecasts, not actuals.</strong>
          Nothing here is stored and no journal is posted. The actual column is the posted ledger;
          the forecast column is what the company intends. The two are never mixed.
        </div>
        <div class="note" style="margin-top:12px">${esc(report.note || '')}</div>`;

      const horizon = report.horizon || {};
      const runRate = report.run_rate || {};

      const summary = `
        <div class="grid grid--metrics">
          <section class="card"><div class="metric__label">Open year</div>
            <div class="metric__value" style="font-size:18px">${esc((report.fiscal_year || {}).name || '\u2014')}</div>
            <p class="metric__note">${esc((report.fiscal_year || {}).starts_on)} to ${esc((report.fiscal_year || {}).ends_on)}</p></section>
          <section class="card"><div class="metric__label">Projected from</div>
            <div class="metric__value" style="font-size:18px">${esc(horizon.from_period_name || '\u2014')}</div>
            <p class="metric__note">to ${esc(horizon.to_period_name || '\u2014')} \u00b7 ${esc(horizon.period_count || 0)} periods</p></section>
          <section class="card"><div class="metric__label">Basis</div>
            <div class="metric__value" style="font-size:14px;line-height:1.4">${esc(report.basis || '\u2014')}</div>
            <p class="metric__note">${runRate.used ? 'Trailing run-rate read over ' + esc(runRate.period_count || 0) + ' completed periods' : 'No completed periods to average yet'}</p></section>
          <section class="card"><div class="metric__label">Net for the horizon</div>
            <div class="metric__value ${moneyClass((report.horizon_totals || {}).net && report.horizon_totals.net.forecast)}">${fmtMoney((report.horizon_totals || {}).net && report.horizon_totals.net.forecast)}</div>
            <p class="metric__note">Projected, not posted</p></section>
        </div>`;

      const totals = report.horizon_totals || {};
      const totalsTable = `
        <div class="table-wrap"><table class="data">
          <thead><tr><th>Measure</th><th class="num">Actual (posted)</th><th class="num">Forecast (projected)</th></tr></thead>
          <tbody>
            ${[['Revenue', totals.revenue], ['Expense', totals.expense], ['Net', totals.net]].map(([label, row]) =>
              `<tr><td>${label}</td><td class="num">${fmtMoney(row && row.actual)}</td>` +
              `<td class="num"><em>${fmtMoney(row && row.forecast)}</em></td></tr>`).join('')}
          </tbody>
        </table></div>`;

      const periodBlocks = (report.periods || []).map(block => {
        const isForecast = block.is_forecast === true;
        const rows = [].concat(
          (block.revenue.accounts || []).map(a => Object.assign({ section: 'Revenue' }, a)),
          (block.expense.accounts || []).map(a => Object.assign({ section: 'Expense' }, a))
        );

        const body = rows.length
          ? `<div class="table-wrap"><table class="data">
              <thead><tr><th>Section</th><th>Code</th><th>Account</th><th class="num">Actual</th><th class="num">Forecast</th><th>Basis</th></tr></thead>
              <tbody>` + rows.map(r => `<tr>
                <td>${esc(r.section)}</td>
                <td>${esc(r.code)}</td>
                <td>${esc(r.name)}</td>
                <td class="num">${fmtMoney(r.actual)}</td>
                <td class="num"><em>${r.forecast === null ? '\u2014' : fmtMoney(r.forecast)}</em></td>
                <td>${r.source === 'budget' ? badge('info', 'From the plan')
                      : r.source === 'run_rate' ? badge('muted', 'Trailing run-rate')
                      : r.source === 'none' ? badge('muted', 'No basis')
                      : badge('ok', 'Actual')}</td>
              </tr>`).join('') + `</tbody></table></div>`
          : emptyState('\u25CB', 'Nothing in this period', 'No revenue or expense is recorded or planned for it.');

        return card(
          (block.period && block.period.name) || 'Period',
          (block.period && (block.period.starts_on + ' to ' + block.period.ends_on)) || '',
          `<div style="margin-bottom:10px">${isForecast
            ? badge('warn', 'Projected') + ' <span style="color:var(--mut);font-size:12.5px">forecast figures only</span>'
            : badge('ok', 'Actual') + ' <span style="color:var(--mut);font-size:12.5px">reported as it happened; nothing is projected onto a past period</span>'}</div>` +
          body +
          `<p style="margin-top:10px;color:var(--mut);font-size:12.5px">Net: actual <strong>${fmtMoney(block.net && block.net.actual)}</strong>` +
          ` \u00b7 forecast <em>${block.net && block.net.forecast === null ? '\u2014' : fmtMoney(block.net && block.net.forecast)}</em></p>`
        );
      }).join('');

      const assumptions = (report.assumptions || []).length
        ? `<ul style="margin:0;padding-left:20px;font-size:12.5px;color:var(--mut)">` +
          report.assumptions.map(a => `<li>${esc(a)}</li>`).join('') + `</ul>`
        : '';

      setStatus('Ready');
      return banner + summary +
        card('The horizon in totals', 'Posted actuals beside projected figures, never added together', totalsTable) +
        periodBlocks +
        card('What the projection assumes', 'Stated rather than left to be inferred', assumptions);
    });
  }

  /* ----------------------------------------------------- screen: budgets */

  const budgetState = { period: '', name: '', lines: [] };

  async function drawBudgets(container) {
    setStatus('Reading the plan');
    await load(container, async () => {
      const list = await periods();
      const chart = await accounts();
      const current = currentPeriod(list);
      if (!budgetState.period) budgetState.period = current ? current.ulid : (list[0] && list[0].ulid) || '';

      const periodPicker = `
        <div class="toolbar">
          <label class="field" style="min-width:240px"><span class="field__label">Fiscal period</span>
            <select data-act="budget-period">
              ${list.map(p => `<option value="${esc(p.ulid)}"${budgetState.period === p.ulid ? ' selected' : ''}>` +
                `${esc(p.name)} \u00b7 ${esc(p.starts_on)} to ${esc(p.ends_on)}</option>`).join('')}
            </select></label>
          <button class="btn btn--quiet" data-act="budget-load">Load the plan</button>
        </div>`;

      if (!budgetState.period) {
        return card('Budgets', 'One plan per period', periodPicker +
          emptyState('\u25CB', 'No fiscal calendar', 'There are no periods to plan for yet.'));
      }

      // The plan for the chosen period, if one has been written.
      let existing = null;
      let lines = budgetState.lines;
      let name = budgetState.name;

      if (!budgetState.loaded) {
        const payload = await api('/budgets?fiscal_period=' + encodeURIComponent(budgetState.period) + '&per_page=1');
        existing = payload && payload.data && payload.data[0] ? payload.data[0] : null;
        if (existing) {
          name = existing.name || '';
          lines = (existing.lines || []).map(l => ({ account_id: l.account_id, amount: String(l.amount) }));
        } else {
          name = '';
          lines = [{ account_id: '', amount: '' }];
        }
        budgetState.loaded = true;
        budgetState.name = name;
        budgetState.lines = lines;
      } else {
        const payload = await api('/budgets?fiscal_period=' + encodeURIComponent(budgetState.period) + '&per_page=1');
        existing = payload && payload.data && payload.data[0] ? payload.data[0] : null;
      }

      const revenueAccounts = chart.filter(a => a.account_type === 'revenue');
      const expenseAccounts = chart.filter(a => a.account_type === 'expense');

      const editor = `
        <div class="note">A budget is a plan and never a posting. Nothing written here reaches the ledger.</div>
        <div class="toolbar" style="margin-top:12px">
          <label class="field" style="min-width:260px"><span class="field__label">Plan name</span>
            <input id="budget-name" value="${esc(budgetState.name)}" maxlength="255" placeholder="Annual plan 2026"></label>
        </div>
        <div class="table-wrap" style="margin-top:12px"><table class="data">
          <thead><tr><th style="min-width:320px">Account (revenue or expense)</th><th class="num" style="min-width:170px">Amount</th><th></th></tr></thead>
          <tbody id="budget-lines">
            ${budgetState.lines.map((line, index) => budgetLineRow(line, index, revenueAccounts, expenseAccounts)).join('')}
          </tbody>
        </table></div>
        <div class="toolbar" style="margin-top:12px">
          <button class="btn btn--quiet" data-act="budget-add-line">Add a line</button>
          <span class="spacer"></span>
          <button class="btn btn--primary" data-act="budget-save">
            ${existing ? 'Replace the plan' : 'Write the plan'}
          </button>
        </div>`;

      const existingNotice = existing
        ? `<div class="note note--ok">A plan for ${esc(existing.fiscal_period ? existing.fiscal_period.name : '')} already exists ` +
          `(version ${esc(existing.row_version)}). Saving replaces it, and the replacement is written to the audit chain.</div>`
        : `<div class="note note--warn">No plan has been written for this period. Saving will create one.</div>`;

      setStatus('Ready');
      return card('Budgets', 'Write what a period is expected to earn and spend', periodPicker + existingNotice + editor);
    });
  }

  function budgetLineRow(line, index, revenueAccounts, expenseAccounts) {
    const options = (selected) =>
      `<option value="">Choose an account\u2026</option>` +
      `<optgroup label="Revenue">${accountOptions(revenueAccounts, selected)}</optgroup>` +
      `<optgroup label="Expense">${accountOptions(expenseAccounts, selected)}</optgroup>`;
    return `<tr data-line="${index}">
      <td><select data-budget-field="account_id">${options(line.account_id)}</select></td>
      <td class="num"><input data-budget-field="amount" value="${esc(line.amount)}" inputmode="decimal" placeholder="0.00"
        style="text-align:right;width:100%;background:transparent;border:1px solid var(--bd);border-radius:8px;padding:6px 8px;color:inherit"></td>
      <td class="num"><button class="btn btn--quiet btn--sm" data-act="budget-remove-line" data-index="${index}">Remove</button></td>
    </tr>`;
  }

  function readBudgetLines() {
    const rows = $$('#budget-lines tr');
    const lines = [];
    rows.forEach(row => {
      const account = $('[data-budget-field="account_id"]', row).value;
      const amount = $('[data-budget-field="amount"]', row).value.trim();
      if (!account && !amount) return;
      lines.push({ account_id: Number(account), amount: String(amount) });
    });
    return lines;
  }

  function budgetAddLine() {
    const chart = cache.accounts || [];
    budgetState.lines = readBudgetLines().concat([{ account_id: '', amount: '' }]);
    const body = $('#budget-lines');
    if (!body) return;
    const index = budgetState.lines.length - 1;
    body.insertAdjacentHTML('beforeend', budgetLineRow(budgetState.lines[index], index,
      chart.filter(a => a.account_type === 'revenue'), chart.filter(a => a.account_type === 'expense')));
  }

  function budgetRemoveLine(index) {
    const row = $(`#budget-lines tr[data-line="${index}"]`);
    if (row) row.remove();
  }

  async function budgetSave() {
    const lines = readBudgetLines();
    if (!lines.length) { toast('bad', 'A plan needs at least one line.', 'Add an account and an amount.'); return; }
    const bad = lines.find(l => !l.account_id || !/^-?\d+(\.\d+)?$/.test(l.amount));
    if (bad) { toast('bad', 'Each line needs an account and a plain decimal amount.', 'Try -5000 or 12500.00.'); return; }

    const button = $('[data-act="budget-save"]');
    button.disabled = true;
    try {
      const payload = await api('/budgets', {
        method: 'PUT',
        key: newKey(),
        body: {
          fiscal_period: budgetState.period,
          name: $('#budget-name').value.trim() || null,
          lines
        }
      });
      const data = payload && payload.data;
      budgetState.loaded = false;
      budgetState.lines = (data.lines || []).map(l => ({ account_id: l.account_id, amount: String(l.amount) }));
      budgetState.name = data.name || '';
      toast('ok', 'The plan is saved', 'Version ' + data.row_version + ' \u00b7 it posts nothing to the ledger.');
      go('budgets');
    } catch (error) {
      toast('bad', error.message, 'The plan was not saved.');
    } finally {
      button.disabled = false;
    }
  }

  /* ----------------------------------------------------- screen: reports */

  const REPORT_DEFS = [
    { key: 'profit-and-loss', label: 'Profit and loss', params: ['from', 'to', 'compare'], export: 'profit_and_loss' },
    { key: 'balance-sheet', label: 'Balance sheet', params: ['as_of', 'compare'], export: 'balance_sheet' },
    { key: 'cash-flow', label: 'Cash flow (direct)', params: ['from', 'to'] },
    { key: 'cash-flow-indirect', label: 'Cash flow (indirect)', params: ['from', 'to'] },
    { key: 'trial-balance', label: 'Trial balance', params: ['from', 'to', 'compare'], export: 'trial_balance' },
    { key: 'general-ledger', label: 'General ledger', params: ['account_id', 'from', 'to'] },
    { key: 'receivables-ageing', label: 'Receivables ageing', params: ['as_of'] },
    { key: 'payables-ageing', label: 'Payables ageing', params: ['as_of'] },
    { key: 'budget-vs-actual', label: 'Budget versus actual', params: ['fiscal_period', 'compare'] },
    { key: 'cash-position', label: 'Cash position', params: ['as_of'] }
  ];

  const reportState = { key: 'profit-and-loss', params: {}, html: '' };

  async function drawReports(container) {
    const def = REPORT_DEFS.find(d => d.key === reportState.key) || REPORT_DEFS[0];
    reportState.key = def.key;

    const toolbar = await buildReportToolbar(def);
    const output = reportState.html || emptyState('\u25CB', 'Nothing read yet', 'Choose a report and run it.');

    container.innerHTML =
      card('Reports', 'Every figure is the ledger read a second way; nothing is computed here', toolbar) +
      `<div id="report-output" style="margin-top:16px">${output}</div>`;

    wireReportToolbar(def);
    if (!reportState.html) runReport(def);
  }

  async function buildReportToolbar(def) {
    const list = await periods();
    const chart = def.params.indexOf('account_id') >= 0 ? await accounts() : [];
    const base = { from: yearStart(), to: today(), as_of: today(), compare: false };
    def.params.forEach(p => { if (reportState.params[p] === undefined) reportState.params[p] = base[p]; });

    // A sensible first pick so the toolbar is never empty of a required value.
    if (def.params.indexOf('fiscal_period') >= 0 && !reportState.params.fiscal_period) {
      const current = currentPeriod(list);
      reportState.params.fiscal_period = current ? current.ulid : (list[0] && list[0].ulid) || '';
    }
    if (def.params.indexOf('account_id') >= 0 && !reportState.params.account_id) {
      const postable = chart.filter(a => a.is_postable);
      reportState.params.account_id = (postable[0] || chart[0] || {}).id || '';
    }

    const fields = [];
    if (def.params.indexOf('from') >= 0) {
      fields.push(`<label class="field"><span class="field__label">From</span>
        <input type="date" data-report-param="from" value="${esc(reportState.params.from || '')}"></label>`);
    }
    if (def.params.indexOf('to') >= 0) {
      fields.push(`<label class="field"><span class="field__label">To</span>
        <input type="date" data-report-param="to" value="${esc(reportState.params.to || '')}"></label>`);
    }
    if (def.params.indexOf('as_of') >= 0) {
      fields.push(`<label class="field"><span class="field__label">As at</span>
        <input type="date" data-report-param="as_of" value="${esc(reportState.params.as_of || '')}"></label>`);
    }
    if (def.params.indexOf('account_id') >= 0) {
      fields.push(`<label class="field" style="min-width:280px"><span class="field__label">Ledger account</span>
        <select data-report-param="account_id">
          ${chart.filter(a => a.is_postable).map(a => `<option value="${a.id}"${Number(reportState.params.account_id) === Number(a.id) ? ' selected' : ''}>` +
            `${esc(a.code)} \u00b7 ${esc(a.name)}</option>`).join('')}
        </select></label>`);
    }
    if (def.params.indexOf('fiscal_period') >= 0) {
      fields.push(`<label class="field" style="min-width:240px"><span class="field__label">Fiscal period</span>
        <select data-report-param="fiscal_period">
          ${list.map(p => `<option value="${esc(p.ulid)}"${reportState.params.fiscal_period === p.ulid ? ' selected' : ''}>` +
            `${esc(p.name)}</option>`).join('')}
        </select></label>`);
    }
    if (def.params.indexOf('compare') >= 0) {
      fields.push(`<label class="field"><span class="field__label">Compare</span>
        <select data-report-param="compare">
          <option value="0"${reportState.params.compare ? '' : ' selected'}>Single column</option>
          <option value="1"${reportState.params.compare ? ' selected' : ''}>With prior period</option>
        </select></label>`);
    }

    return `<div class="toolbar">
      <label class="field" style="min-width:220px"><span class="field__label">Report</span>
        <select data-act="report-select">
          ${REPORT_DEFS.map(d => `<option value="${d.key}"${d.key === def.key ? ' selected' : ''}>${esc(d.label)}</option>`).join('')}
        </select></label>
      ${fields.join('')}
      <button class="btn btn--primary" data-act="run-report">Run</button>
      ${def.export ? `<button class="btn btn--quiet" data-act="export" data-type="${def.export}">Export as CSV</button>` : ''}
    </div>`;
  }

  function wireReportToolbar(def) {
    $$('[data-report-param]').forEach(input => {
      input.addEventListener('change', () => {
        const value = input.value;
        reportState.params[input.dataset.reportParam] = input.dataset.reportParam === 'compare' ? value === '1' : value;
      });
    });
    void def;
  }

  function reportQuery(def) {
    const query = new URLSearchParams();
    def.params.forEach(p => {
      const value = reportState.params[p];
      if (value === undefined || value === null || value === '') return;
      if (p === 'compare') { if (value) query.set('compare', '1'); return; }
      query.set(p, String(value));
    });
    return query.toString();
  }

  async function runReport(def) {
    const out = $('#report-output');
    if (!out) return;
    out.innerHTML = skeleton(6);
    try {
      const payload = await api('/reports/' + def.key + '?' + reportQuery(def));
      reportState.html = renderReport(def.key, payload);
      out.innerHTML = reportState.html;
    } catch (error) {
      reportState.html = errorState(error);
      out.innerHTML = reportState.html;
    }
  }

  function rowsTable(caption, columns, rows, total) {
    const head = columns.map(c => `<th class="${c.num ? 'num' : ''}">${esc(c.label)}</th>`).join('');
    const body = rows.length
      ? rows.map(r => `<tr>${columns.map(c => `<td class="${c.num ? 'num' : ''}">${c.render(r)}</td>`).join('')}</tr>`).join('')
      : `<tr><td colspan="${columns.length}"><span class="muted" style="color:var(--mut)">Nothing to show.</span></td></tr>`;
    const foot = total
      ? `<tfoot><tr>${columns.map(c => `<td class="${c.num ? 'num' : ''}">${c.foot === undefined || c.foot === null ? '' : esc(c.foot)}</td>`).join('')}</tr></tfoot>`
      : '';
    return `<div class="table-wrap"><table class="data">${caption ? `<caption>${esc(caption)}</caption>` : ''}` +
      `<thead><tr>${head}</tr></thead><tbody>${body}</tbody>${foot}</table></div>`;
  }

  function accountRowsTable(block, label, totalLabel) {
    const rows = (block && block.accounts) || [];
    return rowsTable(label, [
      { label: 'Code', render: r => esc(r.code) },
      { label: 'Account', render: r => esc(r.name) },
      { label: 'Amount', num: true, render: r => fmtMoney(r.amount) }
    ], rows, {
      0: '', 1: totalLabel, 2: fmtMoney(block && block.total)
    });
  }

  function comparisonNote(report) {
    if (!report || !report.comparison || !report.variance) return '';
    return `<div class="note" style="margin-top:12px">A prior-period column was asked for and is included in the payload below.</div>`;
  }

  function renderReport(key, report) {
    if (key === 'profit-and-loss') {
      return card('Profit and loss', `${esc(report.from || 'beginning')} to ${esc(report.to || 'now')}`,
        accountRowsTable(report.revenue, 'Revenue', 'Total revenue') + '<div style="height:14px"></div>' +
        accountRowsTable(report.expense, 'Expense', 'Total expense') +
        `<div class="note ${Number(report.net_profit) < 0 ? 'note--bad' : 'note--ok'}" style="margin-top:12px">
          <strong>Net profit:</strong> ${fmtMoney(report.net_profit)} ${esc(CURRENCY)}</div>` + comparisonNote(report));
    }

    if (key === 'balance-sheet') {
      return card('Balance sheet', 'As at ' + esc(report.as_of),
        accountRowsTable(report.assets, 'Assets', 'Total assets') + '<div style="height:14px"></div>' +
        accountRowsTable(report.liabilities, 'Liabilities', 'Total liabilities') + '<div style="height:14px"></div>' +
        accountRowsTable(report.equity, 'Equity', 'Total equity') +
        `<div class="note ${report.balanced ? 'note--ok' : 'note--bad'}" style="margin-top:12px">
          Assets <strong>${fmtMoney(report.total_assets)}</strong> \u00b7
          liabilities and equity <strong>${fmtMoney(report.total_liabilities_and_equity)}</strong> \u00b7
          ${report.balanced ? 'the statement balances' : 'the statement does not balance'}
        </div>
        <div class="note" style="margin-top:12px">${esc(report.note || '')}</div>` + comparisonNote(report));
    }

    if (key === 'trial-balance') {
      const rows = (report.data || []).map(r => r);
      const meta = report.meta || {};
      return card('Trial balance', (meta.from || 'beginning') + ' to ' + (meta.to || 'now'),
        rowsTable('Accounts', [
          { label: 'Code', render: r => esc(r.code) },
          { label: 'Account', render: r => esc(r.name) },
          { label: 'Type', render: r => esc(r.account_type) },
          { label: 'Debit', num: true, render: r => fmtMoney(r.debit) },
          { label: 'Credit', num: true, render: r => fmtMoney(r.credit) },
          { label: 'Net', num: true, render: r => fmtMoney(r.net) }
        ], rows, { 0: '', 1: 'Totals', 2: '', 3: fmtMoney(meta.total_debit), 4: fmtMoney(meta.total_credit),
                   5: fmtMoney(Number(meta.total_debit) - Number(meta.total_credit)) }) +
        `<div class="note ${meta.balanced ? 'note--ok' : 'note--bad'}" style="margin-top:12px">
          Debits <strong>${fmtMoney(meta.total_debit)}</strong> \u00b7 credits <strong>${fmtMoney(meta.total_credit)}</strong> \u00b7
          ${meta.balanced ? 'balanced' : 'out of balance'}</div>`);
    }

    if (key === 'cash-flow' || key === 'cash-flow-indirect') {
      const activities = (report.activities || []).concat(
        report.internal_transfers ? [report.internal_transfers] : [],
        report.unclassified ? [report.unclassified] : []
      );
      return card(key === 'cash-flow' ? 'Cash flow (direct)' : 'Cash flow (indirect)',
        esc(report.from || '') + ' to ' + esc(report.to || ''),
        rowsTable('Activities', [
          { label: 'Activity', render: r => esc(r.label || r.category) },
          { label: 'In', num: true, render: r => fmtMoney(r.inflow) },
          { label: 'Out', num: true, render: r => fmtMoney(r.outflow) },
          { label: 'Net', num: true, render: r => fmtMoney(r.net) }
        ], activities, { 0: 'Net movement', 1: '', 2: '', 3: fmtMoney(report.net_movement) }) +
        `<div class="grid grid--thirds" style="margin-top:14px">
          <div><div class="metric__label">Opening cash</div><div class="metric__value" style="font-size:19px">${fmtMoney(report.opening_cash)}</div></div>
          <div><div class="metric__label">Closing cash</div><div class="metric__value" style="font-size:19px">${fmtMoney(report.closing_cash)}</div></div>
          <div><div class="metric__label">Complete</div><div class="metric__value" style="font-size:19px">${report.complete ? 'Yes' : 'No'}</div></div>
        </div>` +
        `<div class="note" style="margin-top:12px">${esc(report.note || '')}</div>`);
    }

    if (key === 'cash-position') {
      const rows = report.accounts || [];
      return card('Cash position', 'As at ' + esc(report.as_of),
        rowsTable('Accounts', [
          { label: 'Code', render: r => esc(r.code) },
          { label: 'Account', render: r => esc(r.name) },
          { label: 'Kind', render: r => r.is_bank ? 'Bank' : (r.is_cash ? 'Cash' : '\u2014') },
          { label: 'Balance', num: true, render: r => fmtMoney(r.balance) }
        ], rows, { 0: '', 1: 'Total', 2: '', 3: fmtMoney(report.total) }));
    }

    if (key === 'general-ledger') {
      const rows = report.data || [];
      const meta = report.meta || {};
      return card('General ledger', `${esc(meta.account_code || '')} ${esc(meta.account_name || '')}`,
        `<div class="grid grid--metrics" style="margin-bottom:14px">
          <div><div class="metric__label">Opening</div><div class="metric__value" style="font-size:19px">${fmtMoney(meta.opening_balance)}</div></div>
          <div><div class="metric__label">Debits</div><div class="metric__value" style="font-size:19px">${fmtMoney(meta.period_debit)}</div></div>
          <div><div class="metric__label">Credits</div><div class="metric__value" style="font-size:19px">${fmtMoney(meta.period_credit)}</div></div>
          <div><div class="metric__label">Closing</div><div class="metric__value" style="font-size:19px">${fmtMoney(meta.closing_balance)}</div></div>
        </div>` +
        rowsTable('Movements', [
          { label: 'Date', render: r => esc(fmtDate(r.posting_date)) },
          { label: 'Journal', render: r => esc(r.journal_no || '') },
          { label: 'Reference', render: r => esc(r.reference || '') },
          { label: 'Description', render: r => esc(r.description || '') },
          { label: 'Debit', num: true, render: r => fmtMoney(r.debit) },
          { label: 'Credit', num: true, render: r => fmtMoney(r.credit) },
          { label: 'Balance', num: true, render: r => fmtMoney(r.running_balance) }
        ], rows));
    }

    if (key === 'receivables-ageing' || key === 'payables-ageing') {
      const rows = report.data || [];
      const label = key === 'receivables-ageing' ? 'Receivables' : 'Payables';
      return card(label + ' ageing', 'How late each party is, bucketed',
        rowsTable(label, [
          { label: 'Code', render: r => esc(r.party_code || '') },
          { label: 'Party', render: r => esc(r.party_name || '') },
          { label: 'Current', num: true, render: r => fmtMoney(r.current) },
          { label: '1\u201330', num: true, render: r => fmtMoney(r['1_30']) },
          { label: '31\u201360', num: true, render: r => fmtMoney(r['31_60']) },
          { label: '61\u201390', num: true, render: r => fmtMoney(r['61_90']) },
          { label: '90+', num: true, render: r => fmtMoney(r.over_90) },
          { label: 'Total', num: true, render: r => fmtMoney(r.total) }
        ], rows));
    }

    if (key === 'budget-vs-actual') {
      const sections = ['revenue', 'expense'].map(name => {
        const block = report[name] || {};
        return accountRowsTable({ accounts: (block.accounts || []).map(a => ({
          code: a.code, name: a.name, amount: a.actual
        })), total: block.actual }, name === 'revenue' ? 'Revenue \u00b7 actual' : 'Expense \u00b7 actual');
      }).join('<div style="height:14px"></div>');

      const comparison = `
        <div class="table-wrap" style="margin-top:14px"><table class="data">
          <thead><tr><th>Measure</th><th class="num">Budget (plan)</th><th class="num">Actual (ledger)</th><th class="num">Variance</th></tr></thead>
          <tbody>
            ${['revenue', 'expense', 'net'].map(name => {
              const b = report[name] || {};
              return `<tr><td>${name.charAt(0).toUpperCase() + name.slice(1)}</td>
                <td class="num"><em>${fmtMoney(b.budget)}</em></td>
                <td class="num">${fmtMoney(b.actual)}</td>
                <td class="num ${moneyClass(b.variance)}">${fmtMoney(b.variance)}</td></tr>`;
            }).join('')}
          </tbody></table></div>`;

      return card('Budget versus actual',
        (report.period && report.period.name ? report.period.name : '') + ' \u00b7 basis: ' + esc(report.variance_basis || ''),
        `<div class="note note--warn">The budget column is the plan the company wrote; the actual column is the
          posted ledger. They are shown side by side and never added together.</div>` +
        comparison + '<div style="height:14px"></div>' + sections +
        `<div class="note" style="margin-top:12px">${esc(report.note || '')}</div>`);
    }

    return `<section class="card"><details class="raw" open><summary>Raw payload</summary><pre>${esc(json(report))}</pre></details></section>`;
  }

  function startExport() {
    const def = REPORT_DEFS.find(d => d.key === reportState.key);
    if (!def || !def.export) return;

    const body = { type: def.export, format: 'csv' };
    ['from', 'to', 'as_of'].forEach(p => {
      if (def.params.indexOf(p) >= 0 && reportState.params[p]) body[p] = reportState.params[p];
    });
    if (def.params.indexOf('compare') >= 0 && reportState.params.compare) body.compare = true;

    toast('info', 'Export requested', 'The report is being rendered as a file.');
    api('/exports', { method: 'POST', key: newKey(), body })
      .then(record => pollExport(record.data.id))
      .then(record => downloadExport(record))
      .catch(error => toast('bad', error.message, 'The export did not finish.'));
  }

  async function pollExport(id) {
    for (let attempt = 0; attempt < 40; attempt++) {
      const payload = await api('/exports/' + encodeURIComponent(id));
      const record = payload.data;
      if (record.status === 'done') return record;
      if (record.status === 'failed') throw new ApiError(500, record.error || 'The export failed.');
      await sleep(900);
    }
    throw new ApiError(504, 'The export is taking longer than expected.');
  }

  /**
   * The download link is a signed, expiring URL behind the same authentication
   * as everything else, so it is read with the token rather than opened in a
   * tab — a browser following the link would send no Authorization header.
   */
  async function downloadExport(record) {
    if (!record.download_url) throw new ApiError(404, 'That export is no longer available.');

    const res = await fetch(record.download_url, { headers: headers() });
    if (!res.ok) throw new ApiError(res.status, 'The file could not be read.');

    const blob = await res.blob();
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = record.file_name || ('report-' + record.id + '.csv');
    document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 4000);
    toast('ok', 'Export ready', 'The file has been handed to your browser.');
  }

  /* ------------------------------------------------- screen: period close */

  async function drawPeriods(container) {
    setStatus('Reading the calendar');
    await load(container, async () => {
      const list = await periods();
      cache.periods = list;

      if (!list.length) {
        return card('Period close', 'The fiscal calendar', emptyState('\u25CB', 'No fiscal calendar',
          'No periods exist for this company, so there is nothing to close.'));
      }

      const years = [];
      list.forEach(p => {
        const y = p.fiscal_year;
        if (!y) return;
        let entry = years.find(e => e.ulid === y.ulid);
        if (!entry) { entry = { ulid: y.ulid, name: y.name, status: y.status, periods: [] }; years.push(entry); }
        entry.periods.push(p);
      });

      const yearCards = years.map(year => {
        const open = year.periods.filter(p => p.status === 'open').length;
        const reopenable = year.periods.filter(p => p.can_be_reopened);

        const rows = year.periods.map(p => `<tr>
          <td>${esc(p.name)}</td>
          <td>${esc(p.starts_on)} to ${esc(p.ends_on)}</td>
          <td>${statusBadge(p.status)}</td>
          <td>${p.closed_at ? esc(fmtDateTime(p.closed_at)) : '<span class="muted">\u2014</span>'}</td>
          <td class="num">${p.can_be_reopened
            ? `<button class="btn btn--quiet btn--sm" data-act="reopen" data-ulid="${esc(p.ulid)}" data-name="${esc(p.name)}">Reopen</button>`
            : badge('muted', 'Not reopenable')}</td>
        </tr>`).join('');

        return card(year.name + ' \u00b7 ' + year.status,
          open + ' open \u00b7 ' + reopenable.length + ' could still be reopened',
          `<div class="table-wrap"><table class="data">
            <thead><tr><th>Period</th><th>Window</th><th>Status</th><th>Closed</th><th></th></tr></thead>
            <tbody>${rows}</tbody></table></div>` +
          `<div class="toolbar" style="margin-top:12px">
            <button class="btn btn--primary" data-act="close-year" data-ulid="${esc(year.ulid)}" data-name="${esc(year.name)}"
              ${open === 0 ? 'disabled' : ''}>Close ${esc(year.name)}</button>
            <span style="color:var(--mut);font-size:12.5px">
              Closing writes the closing journal, shuts every period of the year and locks the year against further postings.
            </span>
          </div>`);
      }).join('');

      // Bank reconciliations the role signs off.
      let recon;
      try {
        const banks = await api('/bank-accounts?per_page=50');
        const accounts = banks.data || [];
        if (!accounts.length) {
          recon = emptyState('\u25CB', 'No bank accounts', 'No cash or bank account has been opened yet.');
        } else {
          const summaries = await Promise.all(accounts.map(a =>
            api(`/bank-accounts/${a.ulid}/reconciliation`).then(r => ({ account: a, recon: r.data }))
              .catch(() => ({ account: a, recon: null }))
          ));
          recon = `<div class="inline-list">` + summaries.map(({ account, recon: data }) => {
            const summary = (data && data.summary) || {};
            const open = (data && data.lines ? data.lines.filter(l => l.status !== 'matched' && l.status !== 'ignored').length : null);
            return `<div class="inline-row">
              <div class="inline-row__main">
                <strong>${esc(account.name)}${account.bank_name ? ' \u00b7 ' + esc(account.bank_name) : ''}</strong>
                <small>
                  Statement closing ${fmtMoney(summary.statement_closing_balance)} \u00b7
                  ledger closing ${fmtMoney(summary.ledger_closing_balance)} \u00b7
                  ${open === null ? 'standing unavailable' : open + ' line(s) still open'}
                </small>
              </div>
              <div class="inline-row__actions">
                ${summary.is_reconciled ? badge('ok', 'Reconciled') : badge('pending', 'Not yet agreed')}
                <button class="btn btn--ok btn--sm" data-act="recon-signoff" data-ulid="${esc(account.ulid)}"
                  data-name="${esc(account.name)}">Sign off</button>
              </div>
            </div>`;
          }).join('') + `</div>` +
          `<div class="note" style="margin-top:12px">Signing off stamps every match and freezes what the reconciliation
            covered. It touches no ledger entry.</div>`;
        }
      } catch (error) {
        recon = errorState(error);
      }

      setStatus('Ready');
      return card('The calendar', 'Closing the year and reopening a period — both decided here, both written to the trail',
          `<div class="note">Reopening a period is the undo of a close and puts a reported period back into the books.
            It will not start without a reason of at least ten characters, and that reason is what the trail says the
            books were opened again for.</div>`) +
        yearCards +
        card('Bank reconciliations', 'Where each account stands, and the sign-off', recon);
    });
  }

  function closeYear(ulid, name) {
    confirmModal({
      title: 'Close ' + (name || 'the fiscal year') + '?',
      body: '<p>Closing writes the closing journal, shuts every period of the year and locks the year ' +
            'against further postings. It is reversible only by reopening a period.</p>',
      okLabel: 'Close the year',
      tone: 'primary'
    }).then(async confirmed => {
      if (!confirmed) return;
      try {
        const payload = await api(`/fiscal-years/${ulid}/close`, { method: 'POST' });
        const data = (payload && payload.data) || {};
        toast('ok', 'Year closed', (data.periods_closed !== undefined ? data.periods_closed + ' period(s) shut. ' : '') +
          'Closing journal: ' + ((data.closing_journal && data.closing_journal.journal_no) || '\u2014'));
        cache.periods = null;
        go('periods');
      } catch (error) {
        toast('bad', error.message, 'The year was not closed.');
      }
    });
  }

  function reopenPeriod(ulid, name) {
    promptModal({
      title: 'Reopen ' + (name || 'this period') + '?',
      label: 'Why the period is being reopened',
      hint: 'At least 10 characters. The reason is recorded in the audit chain against the reopen.',
      minLength: 10,
      maxLength: 500,
      okLabel: 'Reopen the period'
    }).then(async reason => {
      if (reason === null) return;
      try {
        await api(`/fiscal-periods/${ulid}/reopen`, { method: 'POST', body: { reason }, key: newKey() });
        toast('ok', 'Period reopened', 'The reason was recorded with the reopen.');
        cache.periods = null;
        go('periods');
      } catch (error) {
        toast('bad', error.message, 'The period was not reopened.');
      }
    });
  }

  function signOffReconciliation(ulid, name) {
    confirmModal({
      title: 'Sign off ' + (name || 'this reconciliation') + '?',
      body: '<p>This stamps every match and freezes what the reconciliation covered. Nothing reaches the ledger.</p>',
      okLabel: 'Sign off',
      tone: 'primary'
    }).then(async confirmed => {
      if (!confirmed) return;
      try {
        await api(`/bank-accounts/${ulid}/reconciliation/completion`, { method: 'POST', key: newKey() });
        toast('ok', 'Reconciliation signed off', 'The standing was recorded with your name against it.');
        cache.periods = cache.periods;
        go('periods');
      } catch (error) {
        toast('bad', error.message, 'The reconciliation was not signed off.');
      }
    });
  }

  /* -------------------------------------------------------- screen: audit */

  const auditState = { page: 1, action: '', subject: '' };

  async function drawAudit(container) {
    setStatus('Reading the chain');
    await load(container, async () => {
      const query = new URLSearchParams({ page: String(auditState.page), per_page: '25' });
      if (auditState.action) query.set('action', auditState.action);
      if (auditState.subject) query.set('subject', auditState.subject);

      const payload = await api('/audit-logs?' + query.toString());
      const rows = payload.data || [];
      const meta = payload.meta || {};
      const chain = meta.chain || {};

      const toolbar = `
        <div class="toolbar">
          <label class="field"><span class="field__label">Action</span>
            <input id="audit-action" value="${esc(auditState.action)}" placeholder="journal.posted"></label>
          <label class="field"><span class="field__label">Subject</span>
            <input id="audit-subject" value="${esc(auditState.subject)}" placeholder="ApprovalRequest"></label>
          <button class="btn btn--quiet" data-act="audit-apply">Apply</button>
        </div>`;

      const chainNote = `<div class="note ${chain.intact ? 'note--ok' : 'note--bad'}">
        Page checked against the hash chain: ${chain.checked || 0} link(s) read,
        ${chain.links_checked || 0} link(s) compared \u2014
        <strong>${chain.intact ? 'intact' : 'broken at id(s) ' + esc((chain.broken_ids || []).join(', '))}</strong>.
        This is a page-scoped check; <code>php artisan audit:verify</code> is the authoritative one.
      </div>`;

      const table = rows.length
        ? `<div class="table-wrap"><table class="data">
            <thead><tr><th>When</th><th>Action</th><th>Subject</th><th>Who</th><th>Where from</th><th>Chain</th><th></th></tr></thead>
            <tbody>` + rows.map(r => `<tr>
              <td>${esc(fmtDateTime(r.created_at))}</td>
              <td>${esc(r.action)}</td>
              <td>${esc(r.subject_type || '\u2014')}${r.subject_id ? ' #' + esc(r.subject_id) : ''}</td>
              <td>${esc(r.user ? r.user.name : '\u2014')}</td>
              <td>${esc(r.ip_address || '\u2014')}</td>
              <td>${r.chain_valid === true ? badge('ok', 'Verified') : badge('bad', 'Broken')}</td>
              <td class="num"><details class="raw" style="display:inline-block">
                <summary>Held before</summary>
                <pre>${esc(json({ before: r.before, after: r.after }))}</pre></details></td>
            </tr>`).join('') + `</tbody></table></div>`
        : emptyState('\u25CB', 'Nothing recorded', 'No audit entry matches this filter.');

      const pager = (meta.last_page || 1) > 1
        ? `<div class="toolbar" style="margin-top:12px">
            <button class="btn btn--quiet btn--sm" data-act="audit-page" data-page="${Math.max(1, auditState.page - 1)}"
              ${auditState.page <= 1 ? 'disabled' : ''}>Previous</button>
            <span style="color:var(--mut);font-size:12.5px">Page ${esc(meta.current_page || 1)} of ${esc(meta.last_page || 1)} \u00b7 ${esc(meta.total || 0)} entries</span>
            <button class="btn btn--quiet btn--sm" data-act="audit-page" data-page="${Math.min(meta.last_page || 1, auditState.page + 1)}"
              ${auditState.page >= (meta.last_page || 1) ? 'disabled' : ''}>Next</button>
           </div>`
        : '';

      setStatus('Ready');
      return card('Audit log', 'Append-only; a trigger rejects every update and delete', toolbar + chainNote + table + pager);
    });
  }

  /* ---------------------------------------------------------------- modal */

  let modalResolve = null;

  function openModal(title, bodyHtml, okLabel, tone) {
    $('#modal-title').textContent = title;
    $('#modal-body').innerHTML = bodyHtml;
    const ok = $('#modal-ok');
    ok.textContent = okLabel || 'Confirm';
    ok.className = 'btn ' + (tone === 'primary' ? 'btn--primary' : 'btn--quiet');
    $('#modal').hidden = false;
    return new Promise(resolve => { modalResolve = resolve; });
  }

  function closeModal(value) {
    if ($('#modal').hidden) return;
    $('#modal').hidden = true;
    const resolve = modalResolve;
    modalResolve = null;
    if (resolve) resolve(value === undefined ? false : value);
  }

  function confirmModal(options) {
    return openModal(options.title, options.body || '', options.okLabel, options.tone);
  }

  function promptModal(options) {
    const body =
      `<label class="field"><span class="field__label">${esc(options.label || 'Reason')}</span>
        <textarea id="modal-reason" rows="4" maxlength="${options.maxLength || 500}"
          placeholder="Say why, in a sentence somebody else could act on."></textarea></label>
       <em class="field__error" data-error="reason"></em>
       <p>${esc(options.hint || '')}</p>`;
    const promise = openModal(options.title, body, options.okLabel, 'primary');

    const ok = $('#modal-ok');
    const textarea = $('#modal-reason');
    textarea.focus();

    // A rejection without a reason is refused by the server; refusing it here
    // as well saves a round trip, and the server remains the authority.
    ok.onclick = () => {
      const value = textarea.value.trim();
      const error = $('[data-error="reason"]');
      if (value.length < (options.minLength || 1)) {
        if (error) error.textContent = `A reason is required — at least ${options.minLength || 1} characters.`;
        textarea.setAttribute('aria-invalid', 'true');
        return;
      }
      ok.onclick = null;
      closeModal(value);
    };

    return promise;
  }

  /* --------------------------------------------------------- delegation */

  function wireView() {
    $('#view').addEventListener('click', event => {
      const trigger = event.target.closest('[data-act]');
      if (!trigger || trigger.disabled) return;
      const act = trigger.dataset.act;

      if (act === 'open-screen') { go(trigger.dataset.screen); return; }
      if (act === 'approve') { approveRequest(trigger.dataset.ulid, trigger.dataset.ref); return; }
      if (act === 'reject') { rejectRequest(trigger.dataset.ulid, trigger.dataset.ref); return; }
      if (act === 'raise') { raiseRequest(); return; }
      if (act === 'approval-page') { approvalFilters.page = Number(trigger.dataset.page); go('approvals'); return; }
      if (act === 'run-report') { runReport(REPORT_DEFS.find(d => d.key === reportState.key)); return; }
      if (act === 'export') { startExport(); return; }
      if (act === 'budget-load' || act === 'budget-period') { budgetState.loaded = false; go('budgets'); return; }
      if (act === 'budget-save') { budgetSave(); return; }
      if (act === 'budget-add-line') { budgetAddLine(); return; }
      if (act === 'budget-remove-line') { budgetRemoveLine(Number(trigger.dataset.index)); return; }
      if (act === 'close-year') { closeYear(trigger.dataset.ulid, trigger.dataset.name); return; }
      if (act === 'reopen') { reopenPeriod(trigger.dataset.ulid, trigger.dataset.name); return; }
      if (act === 'recon-signoff') { signOffReconciliation(trigger.dataset.ulid, trigger.dataset.name); return; }
      if (act === 'audit-apply') {
        auditState.action = ($('#audit-action') || {}).value || '';
        auditState.subject = ($('#audit-subject') || {}).value || '';
        auditState.page = 1;
        go('audit');
        return;
      }
      if (act === 'audit-page') { auditState.page = Number(trigger.dataset.page); go('audit'); return; }
    });

    $('#view').addEventListener('change', event => {
      const target = event.target;

      if (target.dataset.act === 'approval-filter') { runApprovalFilter(event); return; }
      if (target.dataset.act === 'report-select') {
        reportState.key = target.value;
        reportState.params = {};
        reportState.html = '';
        go('reports');
        return;
      }
      if (target.dataset.act === 'budget-period') {
        budgetState.period = target.value;
        budgetState.loaded = false;
        budgetState.lines = [];
        budgetState.name = '';
        go('budgets');
        return;
      }
    });

    $('#modal-cancel').addEventListener('click', () => { $('#modal-ok').onclick = null; closeModal(false); });
    $('#modal').addEventListener('click', event => { if (event.target === $('#modal')) { $('#modal-ok').onclick = null; closeModal(false); } });
  }

  /* ------------------------------------------------------------ start-up */

  function boot() {
    initTheme();
    wireLogin();
    wireShell();
    wireView();

    // The portal keeps a token only for this tab; a fresh tab starts fresh.
    if (restoreSession()) {
      api('/auth/me')
        .then(payload => {
          S.user = payload.user;
          S.companyId = payload.company_id;
          const roles = (payload.user && payload.user.roles) || [];
          if (roles.indexOf('Financial Manager') === -1) {
            return signOut({ message: 'Signed out safely.' }).then(() =>
              setLoginError('This portal is for Financial Managers only.', 'email'));
          }
          startIdleWatch();
          showApp();
          pollApprovals();
        })
        .catch(() => { clearSession(); showLogin(); });
    } else {
      showLogin();
    }

    setInterval(() => { if (S.token) pollApprovals(); }, 60000);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }

  // Exposed for the verification runs the portal is checked with.
  window.nexasFm = { version: APP_VERSION, state: S, go: go, signOut: signOut };
})();
