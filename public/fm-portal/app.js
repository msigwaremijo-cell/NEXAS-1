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
    // The address the member arrived on decides the screen, so a bookmark to
    // /financial-manager/dashboard opens the dashboard after signing in.
    go(screenFromLocation(), { replace: true });
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

  /**
   * The portal navigates by URL rather than by hidden state, so each section
   * has an address: the dashboard is /financial-manager/dashboard, and a
   * reload, a bookmark or a click in the sidebar lands on the section it names.
   * The page reads its own path — the server only decides which paths are real.
   */
  function screenFromLocation() {
    const match = /^\/financial-manager\/([a-z-]+)\/?$/.exec(window.location.pathname);
    const name = match ? match[1] : '';
    return SCREENS[name] ? name : 'dashboard';
  }

  function syncUrl(screen, opts) {
    const path = '/financial-manager/' + screen;
    if (window.location.pathname === path) return;
    const method = (opts && opts.replace) ? 'replaceState' : 'pushState';
    try { window.history[method]({ screen: screen }, '', path); } catch (e) { /* history is a courtesy */ }
  }

  function go(name, opts) {
    const screen = SCREENS[name] ? name : 'dashboard';
    S.screen = screen;
    $$('.nav__item').forEach(b => b.classList.toggle('is-active', b.dataset.screen === screen));
    $('#screen-title').textContent = SCREENS[screen].title;
    $('#screen-sub').textContent = SCREENS[screen].sub;
    const crumb = $('#crumb-current');
    if (crumb) crumb.textContent = SCREENS[screen].title;
    syncUrl(screen, opts);
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
    // The back and forward buttons move between the sections the sidebar
    // visited, because each section is an address rather than a hidden state.
    window.addEventListener('popstate', () => {
      if (!S.token) return;
      go(screenFromLocation(), { replace: true });
    });
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

  // Month, Quarter and Year are the calendar's own divisions counted back from
  // today; Custom is whatever the member typed. The window they name is sent
  // to /reports/dashboard, which reads the posted ledger over it.
  const DASH_FILTERS = [
    { key: 'month', label: 'Month' },
    { key: 'quarter', label: 'Quarter' },
    { key: 'year', label: 'Year' },
    { key: 'custom', label: 'Custom' }
  ];

  // The window survives a change of screen, so leaving the dashboard and
  // coming back does not quietly reset the filter to something else.
  const dashState = { filter: 'year', from: '', to: '' };

  function dashRange() {
    const t = today();
    const now = new Date();
    const y = now.getFullYear();
    const p = n => String(n).padStart(2, '0');

    if (dashState.filter === 'month') {
      return { from: `${y}-${p(now.getMonth() + 1)}-01`, to: t };
    }
    if (dashState.filter === 'quarter') {
      const q = Math.floor(now.getMonth() / 3) * 3;
      return { from: `${y}-${p(q + 1)}-01`, to: t };
    }
    if (dashState.filter === 'year') {
      return { from: `${y}-01-01`, to: t };
    }
    return { from: dashState.from || yearStart(), to: dashState.to || t };
  }

  function dashQuery() {
    const range = dashRange();
    const query = new URLSearchParams();
    if (range.from) query.set('from', range.from);
    if (range.to) query.set('to', range.to);
    return query.toString();
  }

  async function drawDashboard(container) {
    setStatus('Reading the books');
    await load(container, async () => {
      const payload = await api('/reports/dashboard?' + dashQuery());
      setStatus('Ready');
      return renderDashboard(payload);
    });
  }

  /**
   * The dashboard is one request. The endpoint answers with a section per
   * question it was asked, and each section carries its own error, so a read
   * that failed leaves its own card standing with the refusal printed on it
   * while every other card still renders. Nothing on this page is invented:
   * every figure arrived from /api/v1/reports/dashboard, which reads the
   * posted ledger and nothing else.
   */
  function renderDashboard(payload) {
    const sections = (payload && payload.sections) || {};

    return dashboardHead(payload) +
      dashboardKpis(sections.kpis) +
      dashboardCharts(sections) +
      dashboardAgeing(sections.ageing) +
      '<div class="grid grid--halves" style="margin-top:16px">' +
        dashboardApprovals(sections.approvals) + dashboardAlerts(sections.alerts) +
      '</div>' +
      '<div style="margin-top:16px">' + dashboardForecast(sections.forecast) + '</div>' +
      '<div style="margin-top:16px">' + dashboardReconciliation(sections.reconciliation) + '</div>';
  }

  /* ------------------------------------------------------------ dashboard head */

  function dashboardHead(payload) {
    const company = (payload && payload.company) || {};
    const win = (payload && payload.window) || {};
    const period = win.period || null;
    const prior = win.prior || null;
    const range = dashRange();

    const filters = DASH_FILTERS.map(f =>
      `<button class="seg__btn${dashState.filter === f.key ? ' is-active' : ''}" data-act="dash-filter"
        data-filter="${esc(f.key)}" aria-pressed="${dashState.filter === f.key ? 'true' : 'false'}">${esc(f.label)}</button>`
    ).join('');

    const custom = dashState.filter === 'custom'
      ? `<label class="field field--date"><span class="field__label">From</span>
           <input type="date" data-act="dash-from" value="${esc(range.from || '')}"></label>
         <label class="field field--date"><span class="field__label">To</span>
           <input type="date" data-act="dash-to" value="${esc(range.to || '')}"></label>`
      : '';

    return `
      <section class="card dash-head">
        <div class="facts">
          <div class="fact"><span class="fact__k">Company</span>
            <strong class="fact__v">${esc(company.name || '\u2014')}</strong>
            <small>${esc(company.currency_code || CURRENCY)}</small></div>
          <div class="fact"><span class="fact__k">Financial period</span>
            <strong class="fact__v">${esc(period ? period.name : 'No period covers today')}</strong>
            <small>${period ? esc(fmtDate(period.starts_on) + ' \u2192 ' + fmtDate(period.ends_on)) : '\u2014'}</small></div>
          <div class="fact"><span class="fact__k">Period status</span>
            <span class="fact__v">${statusBadge(win.period_status || 'unknown')}</span></div>
          <div class="fact"><span class="fact__k">Last refreshed</span>
            <strong class="fact__v">${esc(fmtDateTime(payload && payload.generated_at))}</strong></div>
          <div class="fact"><span class="fact__k">Compared with</span>
            <strong class="fact__v">${prior ? esc(fmtDate(prior.from) + ' \u2192 ' + fmtDate(prior.to)) : '\u2014'}</strong>
            <small>the equivalent window immediately before</small></div>
        </div>
        <div class="dash-head__tools">
          <div class="seg" role="group" aria-label="Period filter">${filters}</div>
          ${custom}
          <button class="btn btn--quiet btn--sm" data-act="dash-refresh">Refresh</button>
          <button class="btn btn--quiet btn--sm" data-act="dash-export" data-format="csv">Export CSV</button>
          <button class="btn btn--quiet btn--sm" data-act="dash-export" data-format="pdf">Export PDF</button>
        </div>
      </section>`;
  }

  /* -------------------------------------------------------------- dashboard KPI */

  function dashboardKpis(section) {
    if (!section || section.error) {
      return sectionProblem('Financial KPIs', section, 'The eight executive measures could not be read.');
    }

    const cards = ((section.data || {}).cards) || [];

    if (!cards.length) {
      return card('Financial KPIs', '', emptyState('\u25CB', 'Nothing to measure',
        'The ledger holds no posted movement over this window.'));
    }

    return card('Financial KPIs', 'The posted ledger over the window, with the equivalent window before it',
      `<div class="grid grid--kpis">${cards.map(dashKpiTile).join('')}</div>`);
  }

  function dashKpiTile(kpi) {
    const isPercent = kpi.unit === 'percent';
    const value = isPercent
      ? (kpi.value === null || kpi.value === undefined ? '\u2014' : Number(kpi.value).toFixed(2) + '%')
      : fmtMoney(kpi.value);

    const tone = dashDeltaTone(kpi);
    const direction = kpi.direction || 'unknown';
    const arrow = direction === 'up' ? '\u25B2' : (direction === 'down' ? '\u25BC' : '\u2013');
    const priorText = isPercent
      ? (kpi.prior === null || kpi.prior === undefined ? '\u2014' : Number(kpi.prior).toFixed(2) + '%')
      : fmtMoney(kpi.prior);

    const delta = (kpi.variance_percent === null || kpi.variance_percent === undefined)
      ? `<span class="kpi__delta kpi__delta--flat">No prior window to compare</span>`
      : `<span class="kpi__delta kpi__delta--${tone}">${arrow} ${Number(kpi.variance_percent) >= 0 ? '+' : ''}${Number(kpi.variance_percent).toFixed(2)}%
           <small>vs ${priorText}</small></span>`;

    const report = kpi.report || {};
    const target = report.key ? dashReportKey(report.key) : '';

    return `
      <button class="kpi" data-act="open-report" data-report="${esc(target)}">
        <span class="kpi__label">${esc(kpi.label)}</span>
        <span class="kpi__value ${isPercent ? '' : moneyClass(kpi.value)}">${value}</span>
        ${delta}
        ${sparkline(kpi.sparkline, tone)}
        <span class="kpi__link">${esc(report.label || 'Open the report')} \u2192</span>
      </button>`;
  }

  /**
   * Whether a movement is good news. Rising revenue is good and rising expense
   * is not, so the measure itself says which way is better; a balance with no
   * direction of its own is left neutral rather than coloured by a guess.
   */
  function dashDeltaTone(kpi) {
    const direction = kpi.direction;
    if (direction === 'unknown' || direction === 'flat') return 'flat';
    const better = kpi.higher_is_better;
    if (better === null || better === undefined) return 'neutral';
    if (direction === 'up') return better ? 'good' : 'bad';
    return better ? 'bad' : 'good';
  }

  // The API names its reports with underscores ('profit_and_loss'); the reports
  // screen names them with hyphens ('profit-and-loss'). One separator apart.
  function dashReportKey(key) {
    return String(key || '').replace(/_/g, '-');
  }

  function sectionProblem(title, section, fallback) {
    const message = (section && section.error && section.error.message) || fallback ||
      'This part of the dashboard could not be read.';
    return card(title, '', `<div class="note note--warn">${esc(message)}</div>`);
  }

  /* ------------------------------------------------------------- dashboard charts */

  function dashboardCharts(sections) {
    const trend = sections.trend;
    const cash = sections.cash_flow;
    const budget = sections.budget;

    return '<div class="grid grid--halves" style="margin-top:16px">' +
      dashChartCard('Revenue, expenses and net profit', 'One point per fiscal period in the window', trend, [
        ['revenue', 'Revenue', 'area'],
        ['expense', 'Expenses', 'area'],
        ['net_profit', 'Net profit', 'line']
      ]) +
      dashChartCard('Cash movement', 'What came in, what went out, and the movement between them', cash, [
        ['cash_in', 'Inflows', 'bar'],
        ['cash_out', 'Outflows', 'bar'],
        ['cash_net', 'Net movement', 'line']
      ]) +
      '</div>' +
      '<div style="margin-top:16px">' +
      dashChartCard('Budget against actual', 'The plan the company wrote, beside what the ledger posted', budget, [
        ['budget_revenue', 'Budget revenue', 'bar'],
        ['actual_revenue', 'Actual revenue', 'bar'],
        ['budget_expense', 'Budget expense', 'bar'],
        ['actual_expense', 'Actual expense', 'bar']
      ], budget && budget.data ? budget.data.note : '') +
      '</div>';
  }

  function dashChartCard(title, subtitle, section, defs, note) {
    if (!section || section.error) return sectionProblem(title, section, subtitle);

    const data = section.data || {};
    const series = data.series || {};
    const measures = defs.map((def, index) => ({
      label: def[1],
      kind: def[2],
      values: series[def[0]] || [],
      color: (index % 4) + 1
    }));

    const body = chartSvg(series, measures) +
      (series.truncated ? `<div class="note note--warn" style="margin-top:12px">
        Only the most recent periods are charted; the window holds more than can be read at once.</div>` : '') +
      (note ? `<div class="note" style="margin-top:12px">${esc(note)}</div>` : '');

    return card(title, subtitle, body);
  }

  /* ------------------------------------------------------------------- charts */

  /**
   * One chart, drawn by hand as SVG.
   *
   * It is drawn here rather than by a charting library so it inherits the
   * portal's own colours through the --chart-* variables, carries no extra
   * download, and plots the ledger's own decimals without reformatting them.
   */
  function chartSvg(series, measures) {
    const periods = (series && series.periods) || [];
    const n = periods.length;

    if (!n) {
      return emptyState('\u25CB', 'No periods to chart',
        'No fiscal period covers the window you chose, so there is nothing to plot.');
    }

    const W = 780;
    const H = 280;
    const padL = 70, padR = 20, padT = 18, padB = 46;
    const plotW = W - padL - padR;
    const plotH = H - padT - padB;

    let min = 0;
    let max = 0;

    measures.forEach(m => (m.values || []).forEach(v => {
      const x = Number(v);
      if (!isFinite(x)) return;
      if (x < min) min = x;
      if (x > max) max = x;
    }));

    if (max === min) max = min + 1;
    const headroom = (max - min) * 0.1;
    max += headroom;
    if (min < 0) min -= headroom;

    const yOf = v => padT + plotH - ((Number(v) - min) / (max - min)) * plotH;
    const band = plotW / n;
    const cx = i => padL + band * (i + 0.5);

    const steps = 4;
    let grid = '';

    for (let s = 0; s <= steps; s++) {
      const value = min + ((max - min) * s) / steps;
      const gy = yOf(value);
      grid += `<line class="chart__grid" x1="${padL}" y1="${gy.toFixed(1)}" x2="${(W - padR).toFixed(1)}" y2="${gy.toFixed(1)}"/>` +
        `<text class="chart__axis" x="${padL - 10}" y="${(gy + 4).toFixed(1)}" text-anchor="end">${esc(shortMoney(value))}</text>`;
    }

    const labelStep = Math.ceil(n / 8);
    let xAxis = '';

    periods.forEach((period, i) => {
      if (i % labelStep !== 0 && i !== n - 1) return;
      xAxis += `<text class="chart__axis" x="${cx(i).toFixed(1)}" y="${(H - padB + 22).toFixed(1)}" text-anchor="middle">` +
        `${esc(periodShort(period))}</text>`;
    });

    const zero = (min < 0 && max > 0)
      ? `<line class="chart__zero" x1="${padL}" y1="${yOf(0).toFixed(1)}" x2="${(W - padR).toFixed(1)}" y2="${yOf(0).toFixed(1)}"/>`
      : '';

    const bars = measures.filter(m => m.kind === 'bar');
    const barW = bars.length ? Math.max(4, Math.min((band * 0.68) / bars.length, 26)) : 0;
    const groupW = barW * bars.length;

    let drawn = '';

    measures.forEach((m, index) => {
      const color = 'var(--chart-' + m.color + ')';
      const values = (m.values || []).slice(0, n).map(v => (isFinite(Number(v)) ? Number(v) : 0));

      if (m.kind === 'bar') {
        const slot = bars.indexOf(m);
        values.forEach((v, i) => {
          const x = cx(i) - groupW / 2 + slot * barW;
          const top = Math.min(yOf(v), yOf(0));
          const height = Math.max(1, Math.abs(yOf(v) - yOf(0)));
          drawn += `<rect class="chart__bar" x="${x.toFixed(1)}" y="${top.toFixed(1)}" width="${Math.max(1, barW - 2).toFixed(1)}" height="${height.toFixed(1)}" rx="2" style="fill:${color}"/>`;
        });
        return;
      }

      const points = values.map((v, i) => [cx(i), yOf(v)]);
      const line = points.map((p, i) => (i ? 'L' : 'M') + p[0].toFixed(1) + ' ' + p[1].toFixed(1)).join(' ');

      if (m.kind === 'area' && points.length) {
        const gradient = 'chartGrad' + index;
        const base = yOf(Math.max(min, 0)).toFixed(1);
        const area = line + ' L ' + points[points.length - 1][0].toFixed(1) + ' ' + base +
          ' L ' + points[0][0].toFixed(1) + ' ' + base + ' Z';
        drawn += `<defs><linearGradient id="${gradient}" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stop-color="${color}" stop-opacity=".32"/>
            <stop offset="100%" stop-color="${color}" stop-opacity="0"/>
          </linearGradient></defs>` +
          `<path class="chart__area" d="${area}" style="fill:url(#${gradient})"/>`;
      }

      drawn += `<path class="chart__line" d="${line}" fill="none" style="stroke:${color}"/>`;

      if (n <= 14) {
        points.forEach(p => {
          drawn += `<circle class="chart__dot" cx="${p[0].toFixed(1)}" cy="${p[1].toFixed(1)}" r="2.6" style="fill:${color}"/>`;
        });
      }
    });

    const legend = '<div class="chart__legend">' + measures.map(m =>
      `<span class="chart__key"><i class="chart__swatch chart__swatch--${esc(m.kind)}" style="background:var(--chart-${m.color})"></i>${esc(m.label)}</span>`
    ).join('') + '</div>';

    return `<div class="chart">${legend}
      <div class="chart__scroll">
        <svg class="chart__svg" viewBox="0 0 ${W} ${H}" role="img"
          aria-label="${esc(measures.map(m => m.label).join(', '))} by fiscal period">
          ${grid}${zero}${drawn}${xAxis}
        </svg>
      </div></div>`;
  }

  function shortMoney(value) {
    const n = Number(value);
    if (!isFinite(n)) return '';
    const abs = Math.abs(n);
    if (abs >= 1e9) return (n / 1e9).toFixed(1) + 'B';
    if (abs >= 1e6) return (n / 1e6).toFixed(1) + 'M';
    if (abs >= 1e3) return (n / 1e3).toFixed(0) + 'k';
    return n.toFixed(0);
  }

  function periodShort(period) {
    const name = String((period && (period.name || period.starts_on)) || '');
    return name.length > 12 ? name.slice(0, 12) : name;
  }

  /**
   * A sparkline: the same series as a card's chart, at the size of a thumbnail.
   * It carries no axis because it is a shape rather than a reading — the number
   * above it is the reading.
   */
  function sparkline(values, tone) {
    const points = (values || []).map(Number).filter(v => isFinite(v));
    if (points.length < 2) return '<span class="spark spark--empty" aria-hidden="true"></span>';

    const W = 132;
    const H = 34;
    const pad = 3;
    const min = Math.min.apply(null, points);
    const max = Math.max.apply(null, points);
    const span = (max - min) || 1;
    const step = (W - pad * 2) / (points.length - 1);
    const coords = points.map((v, i) => [pad + i * step, H - pad - ((v - min) / span) * (H - pad * 2)]);
    const line = coords.map((c, i) => (i ? 'L' : 'M') + c[0].toFixed(1) + ' ' + c[1].toFixed(1)).join(' ');
    const id = 'spark' + Math.random().toString(36).slice(2, 9);

    return `<svg class="spark spark--${esc(tone || 'neutral')}" viewBox="0 0 ${W} ${H}"
      preserveAspectRatio="none" aria-hidden="true">
      <defs><linearGradient id="${id}" x1="0" y1="0" x2="0" y2="1">
        <stop offset="0%" stop-color="currentColor" stop-opacity=".30"/>
        <stop offset="100%" stop-color="currentColor" stop-opacity="0"/>
      </linearGradient></defs>
      <path d="${line} L ${coords[coords.length - 1][0].toFixed(1)} ${H} L ${coords[0][0].toFixed(1)} ${H} Z" fill="url(#${id})"/>
      <path d="${line}" fill="none" stroke="currentColor" stroke-width="1.6" vector-effect="non-scaling-stroke"/>
    </svg>`;
  }

  /* ------------------------------------------------------------------- ageing */

  const AGE_BUCKET_LABELS = {
    current: 'Current', '1_30': '1\u201330', '31_60': '31\u201360',
    '61_90': '61\u201390', over_90: 'Over 90'
  };

  const AGE_OVERDUE = ['1_30', '31_60', '61_90', 'over_90'];

  function dashboardAgeing(section) {
    if (!section || section.error) {
      return sectionProblem('Ageing', section, 'The receivables and payables ageing could not be read.');
    }

    const data = section.data || {};
    const links = data.links || {};
    const buckets = data.buckets || [];

    return card('Ageing', 'As at ' + esc(fmtDate(data.as_of)) + ' \u00b7 overdue balances are marked',
      '<div class="grid grid--halves">' +
        ageingTable('Receivables \u2014 what customers owe', data.receivables, buckets, links.receivables) +
        ageingTable('Payables \u2014 what the company owes', data.payables, buckets, links.payables) +
      '</div>');
  }

  function ageingTable(title, block, buckets, link) {
    const parties = (block && block.parties) || [];
    const totals = (block && block.totals) || {};
    const shown = parties.slice(0, 8);

    const overdue = key => AGE_OVERDUE.indexOf(key) >= 0;

    const head = '<tr><th>Party</th>' +
      buckets.map(b => `<th class="num">${esc(AGE_BUCKET_LABELS[b] || b)}</th>`).join('') +
      '<th class="num">Total</th></tr>';

    const rows = shown.length
      ? shown.map(p => '<tr><td>' + esc(p.party_name || p.party_code || '#' + p.party_id) + '</td>' +
          buckets.map(b => `<td class="num${overdue(b) && Number(p[b]) !== 0 ? ' age--overdue' : ''}">${fmtMoney(p[b])}</td>`).join('') +
          `<td class="num">${fmtMoney(p.total)}</td></tr>`).join('')
      : `<tr><td colspan="${buckets.length + 2}"><span class="cell__sub">Nothing outstanding.</span></td></tr>`;

    const foot = '<tr><td>Total</td>' +
      buckets.map(b => `<td class="num${overdue(b) && Number(totals[b]) !== 0 ? ' age--overdue' : ''}">${fmtMoney(totals[b])}</td>`).join('') +
      `<td class="num">${fmtMoney(totals.total)}</td></tr>`;

    const more = parties.length > shown.length
      ? `<p class="metric__note">Showing the ${shown.length} largest of ${parties.length} parties; the full list is in the ageing report.</p>`
      : '';

    return `<section class="ageing">
      <div class="ageing__head">
        <h4>${esc(title)}</h4>
        ${link ? routeToAction(link.route) : ''}
      </div>
      <div class="table-wrap"><table class="data">
        <thead>${head}</thead><tbody>${rows}</tbody><tfoot>${foot}</tfoot>
      </table></div>${more}</section>`;
  }

  /* --------------------------------------------------- approvals and alerts */

  function dashboardApprovals(section) {
    if (!section || section.error) {
      return sectionProblem('Pending approvals', section, 'The approvals queue could not be read.');
    }

    const data = section.data || {};
    const items = data.items || [];

    const body = (data.total
      ? `<div class="callout"><strong>${esc(data.total)}</strong> document(s) are waiting for a decision.</div>`
      : '') +
      (items.length
        ? '<div class="inline-list">' + items.map(r => `
            <div class="inline-row">
              <div class="inline-row__main">
                <strong>${esc(r.document_type_label)} \u00b7 ${esc(r.document_reference || r.document_ulid)}</strong>
                <small>Raised ${esc(fmtDateTime(r.created_at))}</small>
              </div>
              <div class="inline-row__actions">${statusBadge(r.status)}</div>
            </div>`).join('') + '</div>'
        : emptyState('\u2714', 'The queue is clear', 'Nothing is waiting for a decision.')) +
      `<div class="card__foot"><button class="btn btn--quiet btn--sm" data-act="open-screen"
        data-screen="approvals">Open the approvals page</button></div>`;

    return card('Pending approvals', 'The latest five items in the queue', body);
  }

  const SEVERITY_BADGE = { critical: 'bad', warning: 'pending', info: 'info', ok: 'ok' };

  function dashboardAlerts(section) {
    if (!section || section.error) {
      return sectionProblem('Financial alerts', section, 'The alert list could not be read.');
    }

    const data = section.data || {};
    const items = data.items || [];

    return card('Financial alerts', 'As at ' + esc(fmtDate(data.as_of)),
      items.length
        ? '<div class="inline-list">' + items.map(alertRow).join('') + '</div>'
        : emptyState('\u2714', 'Nothing to flag', 'No alert is raised.'));
  }

  function alertRow(item) {
    const severity = item.severity || 'ok';
    const kind = SEVERITY_BADGE[severity] || 'muted';
    const total = item.total === null || item.total === undefined
      ? ''
      : `<small>${esc(fmtMoney(item.total))} ${esc(CURRENCY)}</small>`;
    const count = item.count
      ? `<span class="badge badge--${kind}">${esc(item.count)}</span>`
      : '';

    return `<div class="inline-row alert alert--${esc(severity)}">
      <div class="inline-row__main">
        <strong>${esc(item.label)} ${count}</strong>
        <small>${esc(item.detail)}</small>${total}
      </div>
      <div class="inline-row__actions">${routeToAction(item.route)}</div>
    </div>`;
  }

  /**
   * A route the API named, turned into the control that walks there: a report
   * route opens the reports screen on that report, a section route opens the
   * section. A route the portal does not know becomes no button at all rather
   * than a dead one.
   */
  function routeToAction(route) {
    const text = String(route || '');
    const report = /[?&]report=([a-z_]+)/.exec(text);

    if (report) {
      return `<button class="btn btn--quiet btn--sm" data-act="open-report"
        data-report="${esc(dashReportKey(report[1]))}">Open</button>`;
    }

    const screen = /\/([a-z-]+)\/?$/.exec(text);

    if (screen && SCREENS[screen[1]]) {
      return `<button class="btn btn--quiet btn--sm" data-act="open-screen"
        data-screen="${esc(screen[1])}">Open</button>`;
    }

    return '';
  }

  /* ---------------------------------------------------------------- forecast */

  function dashboardForecast(section) {
    if (!section || section.error) {
      return sectionProblem('Forecast', section, 'The forecast could not be built.');
    }

    const data = section.data || {};
    const horizon = data.horizon || {};
    const totals = data.horizon_totals || {};
    const revenue = totals.revenue || {};
    const expense = totals.expense || {};
    const net = totals.net || {};
    const projected = (data.periods || []).filter(p => p.is_forecast);
    const value = v => (v === null || v === undefined ? '\u2014' : fmtMoney(v));

    const facts = `<div class="grid grid--thirds">
      <div class="fact"><span class="fact__k">Projected revenue</span>
        <strong class="fact__v">${value(revenue.forecast)}</strong>
        <small>for the ${esc(horizon.period_count || 0)} periods left</small></div>
      <div class="fact"><span class="fact__k">Projected expenses</span>
        <strong class="fact__v">${value(expense.forecast)}</strong></div>
      <div class="fact"><span class="fact__k">Projected net</span>
        <strong class="fact__v ${moneyClass(net.forecast)}">${value(net.forecast)}</strong></div>
    </div>`;

    const rows = projected.length
      ? `<div class="table-wrap" style="margin-top:14px"><table class="data">
          <thead><tr><th>Period</th><th>Ends</th><th class="num">Projected revenue</th>
            <th class="num">Projected expense</th><th class="num">Projected net</th></tr></thead>
          <tbody>${projected.map(p => `<tr>
            <td>${esc((p.period || {}).name)}</td><td>${esc(fmtDate((p.period || {}).ends_on))}</td>
            <td class="num">${value((p.revenue || {}).forecast)}</td>
            <td class="num">${value((p.expense || {}).forecast)}</td>
            <td class="num">${value((p.net || {}).forecast)}</td></tr>`).join('')}</tbody>
        </table></div>`
      : emptyState('\u25CB', 'Nothing left to project',
          'Every period in the open fiscal year has already happened.');

    return `<section class="card forecast">
      <div class="card__head">
        <div>
          <h3>Forecast to the end of the fiscal year</h3>
          <p>${esc(horizon.from_period_name || 'no period')} to ${esc(horizon.to_period_name || 'no period')}
             \u00b7 ends ${esc(fmtDate(horizon.ends_on))}</p>
        </div>
        <div class="spacer"><span class="forecast__flag">FORECAST \u2014 NOT ACTUAL</span></div>
      </div>
      ${facts}${rows}
      <div class="note note--warn" style="margin-top:14px">
        <strong>These figures are projections, not actuals.</strong>
        Nothing here is posted, stored, or part of any trial balance. ${esc(data.note || '')}</div>
    </section>`;
  }

  /* ---------------------------------------------------------- reconciliation */

  function dashboardReconciliation(section) {
    if (!section || section.error) {
      return sectionProblem('Reconciliation', section, 'The reconciliation checks could not be run.');
    }

    const data = section.data || {};
    const checks = data.checks || [];
    const skipped = data.skipped || {};

    const rows = checks.map(c => `<tr>
      <td>${esc(c.label)}<div class="cell__sub">${esc(c.basis || '')}</div></td>
      <td class="num">${fmtMoney(c.expected)}<div class="cell__sub">${esc(c.expected_label || '')}</div></td>
      <td class="num">${fmtMoney(c.actual)}<div class="cell__sub">${esc(c.actual_label || '')}</div></td>
      <td class="num ${Number(c.difference) === 0 ? '' : 'is-negative'}">${fmtMoney(c.difference)}</td>
      <td>${c.reconciled ? badge('ok', 'Reconciled') : badge('bad', 'Difference')}</td>
    </tr>`).join('');

    const skippedNotes = Object.keys(skipped).map(key =>
      `<div class="note note--warn" style="margin-top:10px">${esc(skipped[key])}</div>`).join('');

    return card('Reconciliation', 'Each check compares two figures reached by different routes',
      (data.reconciled
        ? `<div class="note note--ok"><strong>Every check agrees.</strong> The dashboard states the same
            figures as the trial balance, the ageing control accounts and the cash position report.</div>`
        : `<div class="note note--bad"><strong>A check does not agree.</strong> The difference is reported
            below as it stands; nothing was adjusted to force it away.</div>`) +
      `<div class="table-wrap" style="margin-top:14px"><table class="data">
        <thead><tr><th>Check</th><th class="num">Expected</th><th class="num">Actual</th>
          <th class="num">Difference</th><th>Result</th></tr></thead>
        <tbody>${rows || '<tr><td colspan="5"><span class="cell__sub">No check was run.</span></td></tr>'}</tbody>
      </table></div>${skippedNotes}
      <div class="note" style="margin-top:12px">${esc(data.note || '')}</div>`);
  }

  /**
   * The dashboard exports through the same pipeline every other report uses: a
   * queued render, a record to poll, and a signed expiring download. The file
   * is built from the same endpoint the page reads, so the two cannot disagree.
   */
  function startDashboardExport(format) {
    const range = dashRange();
    const body = { type: 'financial_manager_dashboard', format: format };
    if (range.from) body.from = range.from;
    if (range.to) body.to = range.to;

    toast('info', 'Export requested', 'The dashboard is being rendered as a ' + String(format).toUpperCase() + ' file.');
    api('/exports', { method: 'POST', key: newKey(), body })
      .then(record => pollExport(record.data.id))
      .then(record => downloadExport(record))
      .catch(error => toast('bad', error.message, 'The export did not finish.'));
  }

  /* --------------------------------------------------- screen: approvals */

  // What the screen is looking at, in one object rather than scattered
  // globals: a saved view is a snapshot of it, and restoring a view is
  // replacing it.
  const EMPTY_APPROVAL_FILTERS = () => ({
    document_type: '', preparer_id: '', amount_min: '', amount_max: '',
    date_from: '', date_to: '', age_min: '', search: ''
  });

  const approvalState = {
    tab: 'pending',
    filters: EMPTY_APPROVAL_FILTERS(),
    sort: 'age',
    direction: 'asc',
    page: 1,
    view: 'pending',
    selected: null,   // the ULID of the row open beside the line
    detail: null,     // the detail payload for `selected`
    checked: {},      // ULID -> true, the rows gathered for a bulk approval
    rows: [],         // the page as it was read, so a batch can be valued
    meta: null,       // the last answer's meta, so a redraw costs no round trip
    config: { normal_threshold: null, bulk_limit: null, bulk_max_items: null }
  };

  // The views the portal ships with. A view is a starting point rather than a
  // mode: the filters under it stay editable, and "Save view" keeps one of the
  // reader's own beside them.
  const APPROVAL_VIEWS = [
    { key: 'pending', label: 'Waiting for a decision', tab: 'pending', sort: 'age', direction: 'asc', filters: {} },
    { key: 'stale', label: 'Waiting over a week', tab: 'pending', sort: 'age', direction: 'asc', filters: { age_min: '7' } },
    { key: 'journals', label: 'Journals only', tab: 'pending', sort: 'age', direction: 'asc', filters: { document_type: 'journal' } },
    { key: 'approved', label: 'Approved', tab: 'approved', sort: 'age', direction: 'desc', filters: {} },
    { key: 'rejected', label: 'Rejected', tab: 'rejected', sort: 'age', direction: 'desc', filters: {} }
  ];

  const APPROVAL_VIEWS_KEY = 'nexas.fm.approval.views';

  function savedApprovalViews() {
    try {
      const raw = localStorage.getItem(APPROVAL_VIEWS_KEY);
      const list = raw ? JSON.parse(raw) : [];
      return Array.isArray(list) ? list : [];
    } catch (e) { return []; }
  }

  function writeApprovalViews(list) {
    try { localStorage.setItem(APPROVAL_VIEWS_KEY, JSON.stringify(list)); } catch (e) { /* ignore */ }
  }

  function allApprovalViews() {
    return APPROVAL_VIEWS.concat(savedApprovalViews().map(v => Object.assign({ saved: true }, v)));
  }

  function applyApprovalView(key) {
    const view = allApprovalViews().find(v => v.key === key);
    if (!view) return;
    approvalState.tab = view.tab || 'pending';
    approvalState.sort = view.sort || 'age';
    approvalState.direction = view.direction || 'asc';
    approvalState.filters = Object.assign(EMPTY_APPROVAL_FILTERS(), view.filters || {});
    approvalState.page = 1;
    approvalState.view = key;
    approvalState.selected = null;
    approvalState.detail = null;
    approvalState.checked = {};
  }

  /**
   * The query the line is read with. Every filter is answered by the server, so
   * the page on screen is a page of the set the reader asked for.
   */
  function approvalQuery() {
    const q = new URLSearchParams();
    if (approvalState.tab !== 'all') q.set('status', approvalState.tab);

    const f = approvalState.filters;

    if (f.document_type) q.set('document_type', f.document_type);
    if (f.preparer_id) q.set('preparer_id', f.preparer_id);
    if (f.amount_min) q.set('amount_min', f.amount_min);
    if (f.amount_max) q.set('amount_max', f.amount_max);
    if (f.date_from) q.set('date_from', f.date_from);
    if (f.date_to) q.set('date_to', f.date_to);
    if (f.age_min) q.set('age_min', f.age_min);
    if (f.search) q.set('search', f.search);

    q.set('sort', approvalState.sort);
    q.set('direction', approvalState.direction);
    q.set('page', String(approvalState.page));
    q.set('per_page', '20');

    return q;
  }

  const approvalWarningTone = level =>
    ({ bad: 'critical', warn: 'warning', info: 'info' }[level] || 'info');

  async function drawApprovals(container) {
    setStatus('Reading the queue');

    await load(container, async () => {
      const payload = await api('/approval-requests?' + approvalQuery().toString());
      const rows = (payload && payload.data) || [];
      const meta = (payload && payload.meta) || {};

      approvalState.rows = rows;
      approvalState.meta = meta;
      if (meta.config) approvalState.config = meta.config;

      // A row that has left the page takes its detail with it, so the panel on
      // the right is never describing something the line no longer holds.
      if (approvalState.selected && !rows.some(r => r.ulid === approvalState.selected)) {
        approvalState.selected = null;
        approvalState.detail = null;
      }

      if (approvalState.selected) {
        await readApprovalDetail(approvalState.selected);
      }

      setStatus('Ready');
      return approvalMarkup();
    });
  }

  /**
   * Redraw from what is already in hand. Choosing a row or ticking a box
   * changes nothing about the query, so neither should cost a round trip.
   */
  function renderApprovals() {
    const view = $('#view');
    if (!view || S.screen !== 'approvals') return;
    const scroll = window.scrollY;
    view.innerHTML = approvalMarkup();
    window.scrollTo(0, scroll);
  }

  function approvalMarkup() {
    const meta = approvalState.meta || {};
    return approvalHeader(meta) + approvalToolbar(meta) + approvalFiltersCard(meta) +
      approvalBody(meta) + approvalRaiseCard();
  }

  /* ---- what the header states ---- */

  function approvalHeader(meta) {
    const s = (meta && meta.summary) || {};
    const config = (meta && meta.config) || approvalState.config || {};
    const oldest = s.oldest_pending_days;

    return card('The queue at a glance', 'Read over the whole company rather than over the page',
      `<div class="grid grid--metrics">
        <div><div class="metric__label">Waiting</div>
          <div class="metric__value">${esc(s.pending_count || 0)}</div>
          <p class="metric__note">Document(s) in the line right now</p></div>
        <div><div class="metric__label">Value waiting</div>
          <div class="metric__value" style="font-size:19px">${esc(fmtMoney(s.pending_value))}</div>
          <p class="metric__note">The documents' own totals &mdash; nothing here is posted</p></div>
        <div><div class="metric__label">Oldest</div>
          <div class="metric__value">${oldest === null || oldest === undefined ? '\u2014' : esc(oldest) + 'd'}</div>
          <p class="metric__note">The longest any one document has waited</p></div>
        <div><div class="metric__label">Review threshold</div>
          <div class="metric__value" style="font-size:19px">${esc(fmtMoney(config.normal_threshold))}</div>
          <p class="metric__note">A batch may carry ${esc(fmtMoney(config.bulk_limit))} across ${esc(config.bulk_max_items)} items</p></div>
      </div>`);
  }

  /* ---- the toolbar: tabs, sorting, refresh ---- */

  const APPROVAL_TABS = [
    { key: 'pending', label: 'Waiting' },
    { key: 'approved', label: 'Approved' },
    { key: 'rejected', label: 'Rejected' },
    { key: 'all', label: 'Every decision' }
  ];

  const APPROVAL_SORTS = [
    { key: 'age', label: 'Age' },
    { key: 'amount', label: 'Amount' },
    { key: 'reference', label: 'Reference' },
    { key: 'type', label: 'Kind' }
  ];

  function approvalToolbar(meta) {
    const total = (meta && meta.total) || 0;

    const tabs = APPROVAL_TABS.map(t =>
      `<button class="seg__btn${approvalState.tab === t.key ? ' is-active' : ''}" role="tab"
        aria-selected="${approvalState.tab === t.key ? 'true' : 'false'}"
        data-act="approval-tab" data-tab="${esc(t.key)}">${esc(t.label)}</button>`).join('');

    const sorts = APPROVAL_SORTS.map(s =>
      `<button class="btn btn--quiet btn--sm${approvalState.sort === s.key ? ' is-active' : ''}"
        data-act="approval-sort" data-sort="${esc(s.key)}">${esc(s.label)}${
          approvalState.sort === s.key ? (approvalState.direction === 'asc' ? ' \u2191' : ' \u2193') : ''
        }</button>`).join('');

    return `<div class="toolbar approval-bar">
      <div class="seg" role="tablist">${tabs}</div>
      <span class="spacer"></span>
      <span class="approval-bar__count">${esc(total)} in this view</span>
      <span class="approval-bar__sorts">${sorts}</span>
      <button class="btn btn--quiet btn--sm" data-act="approval-refresh">Refresh</button>
    </div>`;
  }

  /* ---- saved views and the filters under them ---- */

  function approvalFiltersCard(meta) {
    const f = approvalState.filters;
    const preparers = (meta && meta.preparers) || [];
    const types = (meta && meta.document_types) || [];

    const chips = allApprovalViews().map(v => {
      const active = approvalState.view === v.key ? ' is-active' : '';
      return v.saved
        ? `<span class="chip chip--saved${active}">
             <button class="chip__label" data-act="approval-view" data-key="${esc(v.key)}">${esc(v.label)}</button>
             <button class="chip__x" data-act="approval-view-delete" data-key="${esc(v.key)}"
               title="Forget this view" aria-label="Forget this view">\u00d7</button>
           </span>`
        : `<button class="chip${active}" data-act="approval-view" data-key="${esc(v.key)}">${esc(v.label)}</button>`;
    }).join('');

    const field = (label, control) =>
      `<label class="field"><span class="field__label">${esc(label)}</span>${control}</label>`;

    return card('Narrow the line', 'Every filter is answered by the server, so a page is a page of the set you asked for',
      `<div class="chips">${chips}
        <button class="chip chip--add" data-act="approval-view-save">+ Save this view</button></div>
       <div class="toolbar" style="margin-top:12px">
         ${field('Document kind',
           `<select data-act="approval-filter" data-name="document_type">
              <option value="">Every kind</option>
              ${types.map(t => `<option value="${esc(t.value)}"${f.document_type === t.value ? ' selected' : ''}>${esc(t.label)}</option>`).join('')}
            </select>`)}
         ${field('Raised by',
           `<select data-act="approval-filter" data-name="preparer_id">
              <option value="">Anyone</option>
              ${preparers.map(p => `<option value="${esc(p.id)}"${String(f.preparer_id) === String(p.id) ? ' selected' : ''}>${esc(p.name)}</option>`).join('')}
            </select>`)}
         ${field('Amount from',
           `<input type="number" min="0" step="0.01" value="${esc(f.amount_min)}" data-act="approval-filter" data-name="amount_min" placeholder="0.00">`)}
         ${field('Amount to',
           `<input type="number" min="0" step="0.01" value="${esc(f.amount_max)}" data-act="approval-filter" data-name="amount_max" placeholder="Any">`)}
         ${field('Raised from',
           `<input type="date" value="${esc(f.date_from)}" data-act="approval-filter" data-name="date_from">`)}
         ${field('Raised to',
           `<input type="date" value="${esc(f.date_to)}" data-act="approval-filter" data-name="date_to">`)}
         ${field('Waited at least',
           `<input type="number" min="0" step="1" value="${esc(f.age_min)}" data-act="approval-filter" data-name="age_min" placeholder="Days">`)}
         ${field('Search',
           `<input type="search" value="${esc(f.search)}" data-act="approval-filter" data-name="search" placeholder="Reference or party">`)}
         <span class="spacer"></span>
         <button class="btn btn--quiet btn--sm" data-act="approval-clear">Clear filters</button>
       </div>`);
  }

  /* ---- the line on the left, the detail on the right ---- */

  function approvalBody(meta) {
    const rows = approvalState.rows || [];

    const listCard = card('The line', `${esc((meta && meta.total) || 0)} in this view`,
      approvalTable(rows) + approvalPager(meta),
      `<button class="btn btn--quiet btn--sm" data-act="approval-check-page">${
        approvalsAllChecked(rows) ? 'Untick the page' : 'Tick the page'}</button>`);

    return `<div class="approval-split">
      <div class="approval-split__list">${approvalBulkBar()}${listCard}</div>
      <div class="approval-split__detail">${approvalDetailCard()}</div>
    </div>`;
  }

  function approvalTable(rows) {
    if (!rows.length) {
      return emptyState('\u2714', 'Nothing in this view',
        'No document matches what you asked for. Widen the filters, or clear them.');
    }

    return `<div class="table-wrap"><table class="data approval-table">
      <thead><tr>
        <th class="approval-check"><span class="approval-sr">Gather</span></th>
        <th>Document</th><th>Party</th><th class="num">Amount</th>
        <th>Raised by</th><th class="num">Waited</th><th>Status</th>
      </tr></thead>
      <tbody>` + rows.map(r => `
        <tr class="approval-row${approvalState.selected === r.ulid ? ' is-selected' : ''}"
            data-act="approval-select" data-ulid="${esc(r.ulid)}">
          <td class="approval-check">
            <input type="checkbox" data-act="approval-check" data-ulid="${esc(r.ulid)}"
              ${approvalState.checked[r.ulid] ? 'checked' : ''}
              aria-label="Gather this row for a bulk decision">
          </td>
          <td><strong>${esc(r.document_type_label)}</strong>
            <div class="cell__sub">${esc(r.document_reference || r.ulid)}</div></td>
          <td>${esc(r.party_name || '\u2014')}</td>
          <td class="num">${esc(fmtMoney(r.amount))}</td>
          <td>${esc(r.preparer_name || '\u2014')}</td>
          <td class="num">${approvalAge(r.age_days)}</td>
          <td>${statusBadge(r.status)}</td>
        </tr>`).join('') + `</tbody></table></div>`;
  }

  const approvalAge = days =>
    days === null || days === undefined ? '\u2014' : esc(days) + 'd';

  function approvalsAllChecked(rows) {
    return rows.length > 0 && rows.every(r => approvalState.checked[r.ulid]);
  }

  function approvalPager(meta) {
    const last = (meta && meta.last_page) || 1;
    const current = (meta && meta.current_page) || 1;
    if (last <= 1) return '';

    return `<div class="toolbar approval-pager">
      <button class="btn btn--quiet btn--sm" data-act="approval-page" data-page="${Math.max(1, current - 1)}"
        ${current <= 1 ? 'disabled' : ''}>Previous</button>
      <span class="approval-pager__text">Page ${esc(current)} of ${esc(last)}</span>
      <button class="btn btn--quiet btn--sm" data-act="approval-page" data-page="${Math.min(last, current + 1)}"
        ${current >= last ? 'disabled' : ''}>Next</button>
    </div>`;
  }

  /**
   * The value of what has been gathered. Summed over the rows on this page,
   * because those are the only ones whose amount the screen holds — the server
   * values the whole batch again before it decides anything, so this figure is
   * a courtesy and never the gate.
   */
  function approvalCheckedValue() {
    const rows = approvalState.rows || [];
    let total = 0;
    rows.forEach(r => {
      if (approvalState.checked[r.ulid] && r.amount !== null && r.amount !== undefined) {
        const n = Number(r.amount);
        if (isFinite(n)) total += n;
      }
    });
    return total;
  }

  function approvalBulkBar() {
    const ulids = Object.keys(approvalState.checked);
    if (!ulids.length) return '';

    const config = approvalState.config || {};
    const value = approvalCheckedValue();
    const overLimit = config.bulk_limit != null && Number(value) > Number(config.bulk_limit);
    const tooMany = config.bulk_max_items != null && ulids.length > Number(config.bulk_max_items);

    const refusal = tooMany
      ? 'A batch may name at most ' + esc(config.bulk_max_items) + ' documents. Untick a few.'
      : 'A batch may carry at most ' + esc(fmtMoney(config.bulk_limit)) + '. Untick a few.';

    return `<div class="approval-bulk">
      <div>
        <strong>${esc(ulids.length)} gathered</strong>
        <span class="approval-bulk__value">${esc(fmtMoney(value))}</span>
        <span class="approval-bulk__limit">measured on this page</span>
      </div>
      <span class="spacer"></span>
      <button class="btn btn--ok" data-act="approval-bulk-approve" ${(overLimit || tooMany) ? 'disabled' : ''}>Approve the batch</button>
      <button class="btn btn--quiet btn--sm" data-act="approval-bulk-clear">Untick all</button>
      ${(overLimit || tooMany) ? `<p class="note note--bad">${refusal}</p>` : ''}
    </div>`;
  }

  function approvalDetailCard() {
    if (!approvalState.selected) {
      return card('The detail', 'Pick a row and it opens here',
        emptyState('\u25CB', 'Nothing chosen',
          'Choose a document in the line to read what it says, what it is worth and how it got here.'));
    }

    const detail = approvalState.detail;

    if (!detail) {
      return card('The detail', 'Reading\u2026', skeleton(4));
    }

    if (detail.error) {
      return card('The detail', 'That could not be read',
        `<div class="note note--bad">${esc(detail.error)}</div>`);
    }

    const s = detail.summary || {};
    const title = [s.document_type_label, s.document_reference].filter(Boolean).join(' ') || 'The detail';

    return card(title, `${esc(s.status_label || '')} \u00b7 raised ${esc(fmtDateTime(s.created_at))}`,
      approvalWarnings(detail.warnings || []) +
      approvalSummaryFacts(s) +
      approvalImpact(detail.financial_impact) +
      approvalPreview(detail.preview) +
      approvalAttachments(detail.attachments || []) +
      approvalTimeline(detail.timeline || []) +
      approvalDetailActions(s),
      `<button class="btn btn--quiet btn--sm" data-act="approval-detail-close">Close</button>`);
  }

  const APPROVAL_WARNING_TITLES = {
    document_missing: 'The document is missing',
    self_approval: 'You raised this yourself',
    already_decided: 'Already decided',
    over_threshold: 'Over the review threshold',
    unbalanced: 'The journal does not balance',
    stale: 'Waiting a long time'
  };

  function approvalWarnings(warnings) {
    const body = warnings.length
      ? warnings.map(w => `<div class="alert alert--${esc(approvalWarningTone(w.level))}">
          <strong>${esc(APPROVAL_WARNING_TITLES[w.code] || 'Worth knowing')}</strong>
          <span>${esc(w.message)}</span></div>`).join('')
      : `<div class="alert alert--ok"><strong>Nothing to flag</strong>
          <span>No warning is raised on this document.</span></div>`;

    return `<section class="approval-block"><h4>Before you decide</h4>${body}</section>`;
  }

  function approvalSummaryFacts(s) {
    const facts = [
      ['Reference', s.document_reference || s.document_ulid],
      ['Status', s.status_label],
      ['Raised by', s.preparer_name || '\u2014'],
      ['Raised', fmtDateTime(s.created_at)],
      ['Waited', s.age_days === null || s.age_days === undefined ? '\u2014' : s.age_days + ' days'],
      ['Decided by', s.decided_by_name || '\u2014'],
      ['Decided', s.decided_at ? fmtDateTime(s.decided_at) : '\u2014']
    ];

    if (s.decision_reason) facts.push(['Why', s.decision_reason]);

    return `<section class="approval-block"><h4>The request</h4>${approvalFactGrid(facts)}</section>`;
  }

  function approvalFactGrid(rows) {
    return `<div class="approval-facts">` + rows.map(([label, value]) =>
      `<div class="approval-facts__row"><span class="approval-facts__label">${esc(label)}</span>
        <span class="approval-facts__value">${esc(value === null || value === undefined || value === '' ? '\u2014' : value)}</span></div>`
    ).join('') + `</div>`;
  }

  function approvalImpact(impact) {
    if (!impact) return '';

    const rows = [
      ['Amount', impact.amount === null || impact.amount === undefined
        ? '\u2014'
        : fmtMoney(impact.amount) + ' ' + (impact.currency_code || '')],
      ['Direction', impact.direction
        ? (impact.direction === 'debit' ? 'Debit to the books' : 'Credit to the books')
        : '\u2014'],
      ['Party', impact.party_name || '\u2014']
    ];

    if (impact.debit_total !== null && impact.debit_total !== undefined) {
      rows.push(['Total debits', fmtMoney(impact.debit_total)]);
      rows.push(['Total credits', fmtMoney(impact.credit_total)]);
      rows.push(['Balanced', impact.balanced ? 'Yes' : 'No']);
    }
    if (impact.subtotal !== null && impact.subtotal !== undefined) {
      rows.push(['Subtotal', fmtMoney(impact.subtotal)]);
      rows.push(['Tax', fmtMoney(impact.tax_total)]);
    }
    if (impact.net_book_value !== null && impact.net_book_value !== undefined) {
      rows.push(['Net book value', fmtMoney(impact.net_book_value)]);
      rows.push(['Gain on disposal', fmtMoney(impact.gain_on_disposal)]);
    }

    rows.push(['Review threshold',
      fmtMoney(impact.threshold) + (impact.exceeds_threshold ? ' \u2014 exceeded' : '')]);

    return `<section class="approval-block"><h4>Financial impact</h4>${approvalFactGrid(rows)}
      <p class="metric__note">${esc(impact.note || '')}</p></section>`;
  }

  const approvalNumericColumn = name =>
    /(debit|credit|total|price|quantity|amount)/i.test(String(name));

  function approvalPreview(preview) {
    if (!preview) return '';

    const fields = (preview.fields || [])
      .filter(f => f.value !== '' && f.value !== null && f.value !== undefined);
    const lines = preview.lines || [];

    const fieldsHtml = fields.length
      ? approvalFactGrid(fields.map(f => [f.label, f.value]))
      : '<p class="metric__note">This document carries no header fields to read.</p>';

    let linesHtml = '';
    if (lines.length) {
      const cols = Object.keys(lines[0]);
      linesHtml = `<div class="table-wrap" style="margin-top:12px"><table class="data">
        <thead><tr>` + cols.map(c =>
          `<th class="${approvalNumericColumn(c) ? 'num' : ''}">${esc(c)}</th>`).join('') +
        `</tr></thead><tbody>` + lines.map(line =>
          `<tr>` + cols.map(c =>
            `<td class="${approvalNumericColumn(c) ? 'num' : ''}">${esc(line[c])}</td>`).join('') + `</tr>`).join('') +
        `</tbody></table></div>`;
    }

    return `<section class="approval-block"><h4>${esc(preview.title || 'The document')}</h4>
      ${fieldsHtml}${linesHtml}</section>`;
  }

  function approvalAttachments(files) {
    const body = files.length
      ? `<ul class="approval-files">` + files.map(f => `
          <li><span class="approval-files__name">${esc(f.file_name)}</span>
            <span class="approval-files__meta">${esc(f.mime_type || 'file')} \u00b7
              ${esc(fmtQty(f.size_bytes))} bytes \u00b7 ${esc(fmtDate(f.created_at))}</span></li>`).join('') + `</ul>`
      : `<p class="metric__note">No file is filed beside this document.</p>`;

    return `<section class="approval-block"><h4>Filed alongside</h4>${body}</section>`;
  }

  function approvalTimeline(events) {
    const body = events.length
      ? `<ol class="timeline">` + events.map(e => `
          <li class="timeline__item">
            <span class="timeline__dot" aria-hidden="true"></span>
            <div class="timeline__body">
              <strong>${esc(e.label || e.action)}</strong>
              <span class="timeline__meta">${esc(e.user_name || 'System')} \u00b7 ${esc(fmtDateTime(e.at))}</span>
              ${e.detail ? `<p>${esc(e.detail)}</p>` : ''}
            </div>
          </li>`).join('') + `</ol>`
      : `<p class="metric__note">No trail is recorded for this request yet.</p>`;

    return `<section class="approval-block"><h4>How it got here</h4>${body}</section>`;
  }

  function approvalDetailActions(s) {
    if (s.can_decide === true) {
      const ref = [(s.document_type_label || 'Document'),
        (s.document_reference || s.document_ulid)].join(' ').trim();
      return `<div class="approval-actions">
        <button class="btn btn--ok" data-act="approve" data-ulid="${esc(s.ulid)}" data-ref="${esc(ref)}">Approve</button>
        <button class="btn btn--bad" data-act="reject" data-ulid="${esc(s.ulid)}" data-ref="${esc(ref)}">Reject</button>
      </div>`;
    }

    const line = s.is_pending === true
      ? (s.raised_by_reader === true
          ? 'You raised this document, and nobody may approve their own entry. Another approver has to decide it.'
          : 'This document is not yours to decide.')
      : 'This request already carries a decision, and a decision is final. A correction is raised as a new request.';

    return `<div class="note">${esc(line)}</div>`;
  }

  function approvalRaiseCard() {
    const types = (approvalState.meta && approvalState.meta.document_types) || [
      { value: 'journal', label: 'Journal' },
      { value: 'bill', label: 'Supplier bill' },
      { value: 'payment', label: 'Payment' },
      { value: 'credit_note', label: 'Credit note' },
      { value: 'debit_note', label: 'Debit note' },
      { value: 'asset_disposal', label: 'Asset disposal' }
    ];

    return card('Raise a document', 'Put a journal, bill, payment or note in the line for a second pair of hands',
      `<div class="toolbar">
        <label class="field"><span class="field__label">Document kind</span>
          <select id="raise-type">
            ${types.map(t => `<option value="${esc(t.value)}">${esc(t.label)}</option>`).join('')}
          </select></label>
        <label class="field" style="min-width:320px"><span class="field__label">Document ULID</span>
          <input id="raise-ulid" maxlength="26" placeholder="01Jxxxxxxxxxxxxxxxxxxxxxxx"></label>
        <button class="btn btn--primary" data-act="raise">Put it in the queue</button>
      </div>
      <div class="note" style="margin-top:12px">
        The queue is what makes a decision a second pair of hands. Raising a document here and then
        approving it is refused &mdash; the same person may not be both signatures &mdash; and that
        refusal is what the portal will show you.
      </div>`);
  }

  /* ---- the actions ---- */

  async function readApprovalDetail(ulid) {
    try {
      const payload = await api('/approval-requests/' + encodeURIComponent(ulid) + '/detail');
      approvalState.detail = (payload && payload.data) || null;
    } catch (error) {
      approvalState.detail = { error: (error && error.message) || 'The detail could not be read.' };
    }
  }

  function approvalSelect(ulid) {
    approvalState.selected = approvalState.selected === ulid ? null : ulid;
    approvalState.detail = null;
    renderApprovals();
    if (!approvalState.selected) return;
    readApprovalDetail(approvalState.selected).then(renderApprovals);
  }

  function approvalCheck(ulid, checked) {
    if (checked) approvalState.checked[ulid] = true;
    else delete approvalState.checked[ulid];
    renderApprovals();
  }

  function approvalCheckPage(rows) {
    const all = approvalsAllChecked(rows);
    rows.forEach(r => {
      if (all) delete approvalState.checked[r.ulid];
      else approvalState.checked[r.ulid] = true;
    });
    renderApprovals();
  }

  function approvalTab(tab) {
    approvalState.tab = tab;
    approvalState.page = 1;
    approvalState.view = '';
    approvalState.selected = null;
    approvalState.detail = null;
    approvalState.checked = {};
    go('approvals');
  }

  function approvalSort(key) {
    if (approvalState.sort === key) {
      approvalState.direction = approvalState.direction === 'asc' ? 'desc' : 'asc';
    } else {
      approvalState.sort = key;
      approvalState.direction = key === 'age' ? 'asc' : 'desc';
    }
    approvalState.page = 1;
    go('approvals');
  }

  function approvalFilterChange(event) {
    const sel = event.target;
    if (!sel.dataset.name) return;
    approvalState.filters[sel.dataset.name] = sel.value;
    approvalState.page = 1;
    approvalState.view = '';
    go('approvals');
  }

  function approvalClearFilters() {
    approvalState.filters = EMPTY_APPROVAL_FILTERS();
    approvalState.page = 1;
    approvalState.view = '';
    go('approvals');
  }

  function approvalViewApply(key) {
    applyApprovalView(key);
    go('approvals');
  }

  function approvalViewSave() {
    promptModal({
      title: 'Save this view',
      label: 'What to call it',
      hint: 'The name is kept in this browser only, beside the views the portal ships.',
      minLength: 2,
      maxLength: 40,
      okLabel: 'Save the view'
    }).then(name => {
      if (typeof name !== 'string' || !name) return;
      const list = savedApprovalViews();
      const key = 'saved-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 6);
      list.push({
        key: key,
        label: name,
        tab: approvalState.tab,
        sort: approvalState.sort,
        direction: approvalState.direction,
        filters: Object.assign({}, approvalState.filters)
      });
      writeApprovalViews(list.slice(-12));
      approvalState.view = key;
      toast('ok', 'View saved', 'It sits beside the views the portal ships.');
      renderApprovals();
    });
  }

  function approvalViewDelete(key) {
    writeApprovalViews(savedApprovalViews().filter(v => v.key !== key));
    if (approvalState.view === key) approvalState.view = '';
    toast('info', 'View forgotten', 'It no longer sits beside the shipped views.');
    renderApprovals();
  }

  async function raiseRequest() {
    const type = ($('#raise-type') || {}).value || 'journal';
    const ulid = (($('#raise-ulid') || {}).value || '').trim();
    if (ulid.length !== 26) {
      toast('bad', 'A document ULID is 26 characters.', 'Check the identifier and try again.');
      return;
    }

    const button = $('[data-act="raise"]');
    if (button) button.disabled = true;
    try {
      await api('/approval-requests', {
        method: 'POST',
        key: newKey(),
        body: { document_type: type, document_ulid: ulid }
      });
      toast('ok', 'In the queue', 'The document now waits for a decision.');
      approvalState.tab = 'pending';
      approvalState.page = 1;
      approvalState.selected = null;
      approvalState.detail = null;
      go('approvals');
      pollApprovals();
    } catch (error) {
      toast('bad', error.message, 'The document was not queued.');
      if (error.status === 409) { go('approvals'); pollApprovals(); }
    } finally {
      if (button) button.disabled = false;
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
        await api('/approval-requests/' + encodeURIComponent(ulid) + '/approve', { method: 'POST', key: newKey() });
        toast('ok', 'Approved', 'The decision is recorded with your name on it.');
        approvalState.checked = {};
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
      if (typeof reason !== 'string' || !reason) return;
      try {
        await api('/approval-requests/' + encodeURIComponent(ulid) + '/reject', {
          method: 'POST', key: newKey(), body: { reason: reason }
        });
        toast('ok', 'Rejected', 'The reason was recorded with the decision.');
        approvalState.checked = {};
        go('approvals');
        pollApprovals();
      } catch (error) {
        toast('bad', error.message, 'The rejection was not recorded.');
        go('approvals');
      }
    });
  }

  function bulkApproveRequest() {
    const ulids = Object.keys(approvalState.checked);
    if (!ulids.length) {
      toast('info', 'Nothing gathered', 'Tick the rows you want to decide in one batch.');
      return;
    }

    const config = approvalState.config || {};
    const value = approvalCheckedValue();
    const overLimit = config.bulk_limit != null && Number(value) > Number(config.bulk_limit);
    const tooMany = config.bulk_max_items != null && ulids.length > Number(config.bulk_max_items);

    confirmModal({
      title: 'Approve ' + ulids.length + ' document(s)?',
      body: `<p>This batch carries <strong>${esc(fmtMoney(value))}</strong> across ${esc(ulids.length)} document(s).</p>` +
        (tooMany
          ? `<p class="note note--bad">A batch may name at most ${esc(config.bulk_max_items)} documents; the server would refuse this one whole.</p>`
          : '') +
        (overLimit
          ? `<p class="note note--bad">A batch may carry at most ${esc(fmtMoney(config.bulk_limit))}; the server would refuse this one whole.</p>`
          : '') +
        '<p>Each document is decided on its own, and a refusal is reported per document rather than discarding the batch.</p>',
      okLabel: 'Approve the batch',
      tone: 'primary'
    }).then(async confirmed => {
      if (!confirmed) return;
      try {
        const payload = await api('/approval-requests/bulk-approve', {
          method: 'POST', key: newKey(), body: { ulids: ulids }
        });
        const result = (payload && payload.data) || {};
        const failed = result.failed || 0;
        toast(failed ? 'bad' : 'ok',
          (result.approved || 0) + ' approved' + (failed ? ', ' + failed + ' refused' : ''),
          'The batch carried ' + fmtMoney(result.value) + '.');
        if (failed) showBulkRefusals(result);
        approvalState.checked = {};
        go('approvals');
        pollApprovals();
      } catch (error) {
        toast('bad', error.message, 'The batch was not decided.');
        go('approvals');
      }
    });
  }

  function showBulkRefusals(result) {
    const refused = (result.results || []).filter(r => r.status !== 'approved');
    if (!refused.length) return;

    const body = '<p>These were not approved, and the reason is the server\'s own:</p>' +
      '<div class="table-wrap"><table class="data"><thead><tr><th>Document</th><th>Reason</th></tr></thead><tbody>' +
      refused.map(r => `<tr><td>${esc([r.document_type_label, r.document_reference].filter(Boolean).join(' ') || r.ulid)}</td>
        <td>${esc(r.message || '\u2014')}</td></tr>`).join('') +
      '</tbody></table></div>';

    openModal('Some documents were refused', body, 'Close', 'quiet');
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
    { key: 'cash-position', label: 'Cash position', params: ['as_of'] },
    { key: 'stock-valuation', label: 'Stock valuation', params: ['as_of'] }
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

    if (key === 'stock-valuation') {
      const lines = report.lines || [];
      const control = report.control || [];
      return card('Stock valuation', 'What the stock on hand is worth as at ' + esc(report.as_of),
        rowsTable('Items', [
          { label: 'SKU', render: r => esc(r.product_sku || '') },
          { label: 'Product', render: r => esc(r.product_name || '') },
          { label: 'Warehouse', render: r => esc(r.warehouse_name || r.warehouse_code || '') },
          { label: 'Quantity', num: true, render: r => fmtQty(r.quantity) },
          { label: 'Unit cost', num: true, render: r => fmtMoney(r.unit_cost) },
          { label: 'Value', num: true, render: r => fmtMoney(r.value) }
        ], lines, { 0: '', 1: '', 2: 'Total', 3: '', 4: '', 5: fmtMoney(report.total_value) }) +
        (control.length
          ? '<div style="height:14px"></div>' + rowsTable('Inventory accounts', [
              { label: 'Code', render: r => esc(r.account_code || '') },
              { label: 'Account', render: r => esc(r.account_name || '') },
              { label: 'Stock value', num: true, render: r => fmtMoney(r.stock_value) },
              { label: 'Ledger balance', num: true, render: r => fmtMoney(r.ledger_balance) },
              { label: 'Difference', num: true, render: r => fmtMoney(r.difference) }
            ], control)
          : '') +
        `<div class="note ${report.reconciled ? 'note--ok' : 'note--bad'}" style="margin-top:12px">
          The valuation is priced from the stock layers; the ledger balance is the inventory accounts
          read from the posted journals. ${report.reconciled
            ? 'The two agree at this date.'
            : 'They disagree at this date, and the difference is shown rather than adjusted away.'}</div>`);
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
    // The plain question the dialog asks is answered by "yes"; promptModal
    // replaces this with a handler that validates its textarea first, and
    // Cancel clears it. Without this the promise below would never settle.
    ok.onclick = () => { ok.onclick = null; closeModal(true); };
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

      // Approvals. Ticking a row arrives as a `change`, not a click, so the
      // checkbox is deliberately absent here; everything else on the screen
      // is a click. Selecting a row and ticking a box both redraw from what is
      // already held, so neither costs a round trip.
      if (act === 'approval-tab') { approvalTab(trigger.dataset.tab); return; }
      if (act === 'approval-sort') { approvalSort(trigger.dataset.sort); return; }
      if (act === 'approval-refresh') { go('approvals'); return; }
      if (act === 'approval-select') { approvalSelect(trigger.dataset.ulid); return; }
      if (act === 'approval-check-page') { approvalCheckPage(approvalState.rows || []); return; }
      if (act === 'approval-page') { approvalState.page = Number(trigger.dataset.page); go('approvals'); return; }
      if (act === 'approval-clear') { approvalClearFilters(); return; }
      if (act === 'approval-view') { approvalViewApply(trigger.dataset.key); return; }
      if (act === 'approval-view-save') { approvalViewSave(); return; }
      if (act === 'approval-view-delete') { approvalViewDelete(trigger.dataset.key); return; }
      if (act === 'approval-detail-close') {
        approvalState.selected = null;
        approvalState.detail = null;
        renderApprovals();
        return;
      }
      if (act === 'approval-bulk-approve') { bulkApproveRequest(); return; }
      if (act === 'approval-bulk-clear') { approvalState.checked = {}; renderApprovals(); return; }
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

      // Dashboard controls. Each one re-asks the dashboard endpoint rather
      // than redrawing from a stale payload, so what is on screen is what the
      // ledger holds now.
      if (act === 'dash-filter') {
        dashState.filter = trigger.dataset.filter || 'year';
        go('dashboard');
        return;
      }
      if (act === 'dash-refresh') { go('dashboard'); return; }
      if (act === 'dash-export') { startDashboardExport(trigger.dataset.format || 'csv'); return; }
      if (act === 'open-report') {
        // A KPI and an alert both walk to the report the API named; the report
        // screen runs it on arrival, so the figure can be read where it came from.
        const key = trigger.dataset.report;
        if (key && REPORT_DEFS.some(d => d.key === key)) reportState.key = key;
        reportState.params = {};
        reportState.html = '';
        go('reports');
        return;
      }
    });

    $('#view').addEventListener('change', event => {
      const target = event.target;

      if (target.dataset.act === 'approval-filter') { approvalFilterChange(event); return; }
      if (target.dataset.act === 'approval-check') {
        approvalCheck(target.dataset.ulid, target.checked);
        return;
      }
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
      // Custom dates put the dashboard on the custom filter, whatever the
      // segmented control held before, so the window on screen matches the input.
      if (target.dataset.act === 'dash-from' || target.dataset.act === 'dash-to') {
        if (target.dataset.act === 'dash-from') dashState.from = target.value;
        else dashState.to = target.value;
        dashState.filter = 'custom';
        go('dashboard');
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
