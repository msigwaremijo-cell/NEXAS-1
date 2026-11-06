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
    // The report the Reports section is showing, or '' when it is showing the
    // hub. It is part of the address — /financial-manager/reports/<report> — so
    // that a reload, a bookmark or a shared link lands on the report itself.
    report: '',
    idleTimer: null,
    idleWarn: null,
    countdown: null,
    signingOut: false
  };

  // Small caches. The calendar and the chart of accounts change rarely and are
  // read by three screens each. The budgets screen keeps its own copy of the
  // chart because it reads it by account type rather than whole.
  const cache = { periods: null, accounts: null, budgetAccounts: null, costCentres: null };

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
    S.report = '';
    cache.periods = null;
    cache.accounts = null;
    cache.budgetAccounts = null;
    cache.costCentres = null;
    // What this browser remembered for the person signing out — their starred
    // reports, what they opened here and the filters they left behind — goes
    // with the session, so a shared machine does not carry one person's
    // reading habits into the next.
    reportStoreClear();
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
    // The address the member arrived on decides the screen — and, when it names
    // a report, the report — so a bookmark to /financial-manager/dashboard
    // opens the dashboard and a link to /financial-manager/reports/trial-balance
    // opens that report after signing in.
    const route = routeFromLocation();
    go(route.screen, { replace: true, report: route.report });
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
    budgets: { title: 'Budget Management', sub: 'Plan, manage, monitor, and control organizational financial budgets.', draw: drawBudgets },
    reports: { title: 'Reports', sub: 'Every statement, ageing report and the ledger behind them', draw: drawReports },
    periods: { title: 'Period close', sub: 'Closing the year, reopening a period, and signing off reconciliations', draw: drawPeriods },
    audit: { title: 'Audit log', sub: 'Who changed what, and what it held before', draw: drawAudit }
  };

  /**
   * The portal navigates by URL rather than by hidden state, so each section
   * has an address: the dashboard is /financial-manager/dashboard, and a
   * reload, a bookmark or a click in the sidebar lands on the section it names.
   * A report goes one step deeper — /financial-manager/reports/<report> — so a
   * reload or a shared link lands on the report itself rather than on the hub.
   * The page reads its own path; the server only decides which paths are real.
   */
  function routeFromLocation() {
    const nested = /^\/financial-manager\/reports\/([a-z][a-z-]*)\/?$/.exec(window.location.pathname);
    if (nested) {
      const key = nested[1];
      // A name the catalogue does not carry is the hub, not a broken report.
      const known = REPORT_DEFS.some(d => d.key === key && !d.deferred);
      return { screen: 'reports', report: known ? key : '' };
    }
    const match = /^\/financial-manager\/([a-z-]+)\/?$/.exec(window.location.pathname);
    const name = match ? match[1] : '';
    return { screen: SCREENS[name] ? name : 'dashboard', report: '' };
  }

  function syncUrl(screen, opts) {
    const report = (opts && opts.report) || '';
    const path = report
      ? '/financial-manager/reports/' + report
      : '/financial-manager/' + screen;
    if (window.location.pathname === path) return;
    const method = (opts && opts.replace) ? 'replaceState' : 'pushState';
    try { window.history[method]({ screen: screen, report: report }, '', path); } catch (e) { /* history is a courtesy */ }
  }

  /**
   * The breadcrumb names where the reader is: the section, and, when a report
   * is open, the report under it. The extra segment is added and taken away
   * here, so leaving a report leaves no trace of it in the trail.
   */
  function renderCrumbs(def) {
    const nav = $('#crumbs');
    const crumb = $('#crumb-current');
    if (!nav || !crumb) return;
    $$('.crumbs__mid', nav).forEach(node => node.remove());

    if (def) {
      const mid = document.createElement('span');
      mid.className = 'crumbs__mid';
      mid.innerHTML = '<a href="/financial-manager/reports">Reports</a>' +
        '<span class="crumbs__sep" aria-hidden="true">&sol;</span>';
      nav.insertBefore(mid, crumb);
      crumb.textContent = def.label;
    } else {
      crumb.textContent = SCREENS[S.screen] ? SCREENS[S.screen].title : 'Dashboard';
    }
  }

  function go(name, opts) {
    const screen = SCREENS[name] ? name : 'dashboard';
    const report = (opts && opts.report) || '';
    S.screen = screen;
    S.report = report;
    $$('.nav__item').forEach(b => b.classList.toggle('is-active', b.dataset.screen === screen));

    const def = report ? REPORT_DEFS.find(d => d.key === report) : null;
    $('#screen-title').textContent = def ? def.label : SCREENS[screen].title;
    $('#screen-sub').textContent = def ? def.description : SCREENS[screen].sub;
    renderCrumbs(def);

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
    // visited — and into and out of a report — because each is an address
    // rather than a hidden state.
    window.addEventListener('popstate', () => {
      if (!S.token) return;
      const route = routeFromLocation();
      go(route.screen, { replace: true, report: route.report });
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

  // The scenarios the endpoint knows by name. Nothing is sent when the reader
  // has made no choice, so a caller who holds only the forecast right never has
  // a scenario parameter on the wire: the endpoint refuses on the presence of
  // one rather than on its value.
  const FORECAST_SCENARIOS = [
    { name: 'base', label: 'Base (as planned)' },
    { name: 'optimistic', label: 'Optimistic' },
    { name: 'pessimistic', label: 'Pessimistic' }
  ];

  // Some measures read better when they are higher (earning more) and some when
  // they are lower (spending less). The direction the endpoint reports is the
  // sign of the projection against the plan, so which sign is good news is
  // decided here, per measure, rather than coloured by the sign alone.
  const FORECAST_HIGHER_IS_BETTER = {
    revenue: true, expense: false, net_profit: true,
    margin: true, year_end_cash: true, break_even: null
  };

  // The three assumptions a scenario can hold, named as the endpoint names them.
  const SCENARIO_TYPES = [
    { name: 'base', label: 'Base (as planned)' },
    { name: 'optimistic', label: 'Optimistic' },
    { name: 'pessimistic', label: 'Pessimistic' }
  ];

  const forecastState = {
    // An ad-hoc run: the named scenario and the rates typed beside it.
    scenario: '',
    revenue_growth: '',
    expense_growth: '',
    // A saved scenario being replayed, held as the assumptions it carries
    // rather than as an identity — see forecastQuery(). Null when the page is
    // showing the plain read or an ad-hoc run.
    saved: null,
    // The last projection the endpoint answered with, kept so opening a
    // history or a comparison does not re-ask for a projection whose
    // assumptions have not moved.
    payload: null,
    // The saved scenarios, or the error their read returned, or null when the
    // caller may not keep them.
    scenarios: null
  };

  // The saved-scenario panel's own state: the open scenario's history, and the
  // comparison the reader asked for.
  const scenarioState = { detail: null, comparison: null };

  /**
   * The query the projection is read with.
   *
   * A saved scenario has no name the report endpoint knows — it keeps the three
   * named scenarios only — so it is replayed as the assumptions it holds: the
   * two growth rates and the per-account adjustments. The endpoint then
   * recalculates it against the ledger as it stands, which is what reopening a
   * saved scenario means, and why no figure from when it was saved survives.
   */
  function forecastQuery() {
    const text = forecastQueryParams().toString();
    return text ? '?' + text : '';
  }

  function forecastQueryParams() {
    const q = new URLSearchParams();
    const saved = forecastState.saved;

    if (saved) {
      if (saved.revenue_growth !== '' && saved.revenue_growth !== null && saved.revenue_growth !== undefined) {
        q.set('revenue_growth', String(saved.revenue_growth));
      }
      if (saved.expense_growth !== '' && saved.expense_growth !== null && saved.expense_growth !== undefined) {
        q.set('expense_growth', String(saved.expense_growth));
      }
      Object.keys(saved.adjustments || {}).forEach((account, index) => {
        q.set('adjustments[' + index + '][account_id]', String(account));
        q.set('adjustments[' + index + '][amount]', String(saved.adjustments[account]));
      });
      return q;
    }

    if (forecastState.scenario) q.set('scenario', forecastState.scenario);
    if (forecastState.revenue_growth !== '') q.set('revenue_growth', forecastState.revenue_growth);
    if (forecastState.expense_growth !== '') q.set('expense_growth', forecastState.expense_growth);
    return q;
  }

  async function drawForecast(container) {
    setStatus('Building the projection');
    await load(container, async () => {
      const payload = await api('/reports/forecast' + forecastQuery());
      const capabilities = (payload && payload.capabilities) || {};

      // The saved scenarios are a second, independent read. A caller who may
      // not keep them is given no panel at all rather than an empty one, and a
      // refusal here is held as the panel's own message so it can never take
      // the projection off the screen.
      forecastState.payload = payload;
      forecastState.scenarios = null;
      scenarioState.detail = null;
      scenarioState.comparison = null;

      if (capabilities.scenario) {
        try {
          const list = await api('/forecast/scenarios');
          forecastState.scenarios = (list && list.data) || [];
        } catch (error) {
          forecastState.scenarios = error;
        }
      }

      setStatus('Ready');
      return renderForecast(payload, forecastState.scenarios);
    });
  }

  /**
   * A redraw from what is already held.
   *
   * Opening a scenario's history or its comparison does not change the
   * assumptions the projection was built on, so re-asking for it would spend a
   * round trip to redraw the same figures. Anything that does change them goes
   * through `go('forecast')` instead, so what is drawn and what it was drawn
   * from never drift apart.
   */
  function redrawForecast() {
    const view = $('#view');
    if (!view || !forecastState.payload || S.screen !== 'forecast') { go('forecast'); return; }
    view.innerHTML = renderForecast(forecastState.payload, forecastState.scenarios);
  }

  /**
   * The forecast page is one request. The endpoint answers with a section per
   * question it was asked and each section carries its own error, so a read that
   * failed leaves its own card standing with the reason printed on it while the
   * rest of the page renders — the arrangement the dashboard uses, for the same
   * reason. Nothing here is posted or stored.
   */
  function renderForecast(payload, scenarios) {
    const sections = (payload && payload.sections) || {};
    const capabilities = (payload && payload.capabilities) || {};

    return forecastHead(payload) +
      forecastScenarios(scenarios) +
      forecastHistory(payload && payload.history) +
      forecastKpis(sections.kpis) +
      forecastChart(sections.chart) +
      forecastRunway(sections.cash_runway, capabilities.scenario === true) +
      '<div class="grid grid--halves" style="margin-top:16px">' +
        forecastCashOutlook(sections.cash_outlook) + forecastWorkingCapital(sections.working_capital) +
      '</div>' +
      '<div class="grid grid--halves" style="margin-top:16px">' +
        forecastBreakEven(sections.break_even) + forecastRiskAlerts(sections.risk_alerts) +
      '</div>' +
      '<div style="margin-top:16px">' + forecastBudgetVsForecast(sections.budget_vs_forecast) + '</div>' +
      (sections.scenario_comparison
        ? '<div style="margin-top:16px">' + forecastComparison(sections.scenario_comparison) + '</div>'
        : '') +
      '<div style="margin-top:16px">' + forecastPeriods(sections.projection) + '</div>' +
      '<div style="margin-top:16px">' + forecastAssumptions(sections.projection) + '</div>';
  }

  function forecastHead(payload) {
    const company = (payload && payload.company) || {};
    const win = (payload && payload.window) || {};
    const capabilities = (payload && payload.capabilities) || {};
    const scenario = (payload && payload.scenario) || {};

    return `<section class="card forecast">
      <div class="card__head">
        <div>
          <h3>Forecast to the end of the open year</h3>
          <p>Every figure is a projection unless it is labelled an actual; nothing here is posted or stored.</p>
        </div>
        <div class="spacer"><span class="forecast__flag">FORECAST \u2014 NOT ACTUAL</span></div>
      </div>
      ${forecastSavedBanner()}
      <div class="facts">
        <div class="fact"><span class="fact__k">Company</span>
          <strong class="fact__v">${esc(company.name || '\u2014')}</strong>
          <small>${esc(company.currency || CURRENCY)}</small></div>
        <div class="fact"><span class="fact__k">Open year</span>
          <strong class="fact__v">${esc(win.fiscal_year || '\u2014')}</strong>
          <small>ends ${esc(fmtDate(win.ends_on))}</small></div>
        <div class="fact"><span class="fact__k">Projected from</span>
          <strong class="fact__v">${esc(win.from_period || '\u2014')} \u2192 ${esc(win.to_period || '\u2014')}</strong>
          <small>${esc(win.period_count || 0)} periods \u00b7 as at ${esc(fmtDate(win.as_of))}</small></div>
        <div class="fact"><span class="fact__k">Built</span>
          <strong class="fact__v">${esc(fmtDateTime(payload && payload.generated_at))}</strong>
          <small>a read, not a stored run</small></div>
      </div>
      <div class="dash-head__tools">
        ${capabilities.scenario ? forecastScenarioTools(scenario) : ''}
        <button class="btn btn--quiet btn--sm" data-act="forecast-refresh">Refresh</button>
        <button class="btn btn--quiet btn--sm" data-act="forecast-export" data-format="csv">Export CSV</button>
        <button class="btn btn--quiet btn--sm" data-act="forecast-export" data-format="pdf">Export PDF</button>
      </div>
    </section>`;
  }

  /**
   * The notice a reader gets when the page is showing a saved scenario.
   *
   * A saved scenario is a stance, not a result: the figures beneath it were
   * rebuilt from today's posted ledger, so this says so where the figures are,
   * rather than leaving a reader to assume they are looking at what the
   * scenario produced when it was saved.
   */
  function forecastSavedBanner() {
    const saved = forecastState.saved;
    if (!saved) return '';

    return `<div class="note note--ok saved-banner">
      <div>
        <strong>Viewing the saved scenario \u201c${esc(saved.name)}\u201d</strong>
        ${badge('info', 'v' + esc(saved.version_no))}
        ${badge(scenarioTypeKind(saved.type), scenarioTypeLabel(saved.type))}
      </div>
      <p>Its assumptions are held, but its figures are not: reopening a saved scenario recalculates
        the projection against the ledger as it stands, so what is below comes from today\u2019s posted
        actuals and not from the day the scenario was saved.</p>
      <button class="btn btn--quiet btn--sm" data-act="forecast-clear-saved">Back to the plain read</button>
    </div>`;
  }

  function scenarioTypeLabel(type) {
    const found = SCENARIO_TYPES.find(t => t.name === type);
    return found ? found.label : (type || '\u2014');
  }

  function scenarioTypeKind(type) {
    return type === 'optimistic' ? 'ok' : (type === 'pessimistic' ? 'bad' : 'muted');
  }

  // The scenario controls are only drawn for a caller who holds the scenario
  // right; everyone else is shown the projection and nothing to change it with.
  function forecastScenarioTools(scenario) {
    const current = scenario.name || 'base';
    const options = FORECAST_SCENARIOS.map(s =>
      `<option value="${esc(s.name)}"${current === s.name ? ' selected' : ''}>${esc(s.label)}</option>`).join('');

    return `<label class="field" style="min-width:200px"><span class="field__label">Scenario</span>
        <select data-act="forecast-scenario">${options}</select></label>
      <label class="field field--date"><span class="field__label">Revenue growth %</span>
        <input type="text" inputmode="decimal" data-act="forecast-revenue" value="${esc(forecastState.revenue_growth)}"
          placeholder="from the scenario"></label>
      <label class="field field--date"><span class="field__label">Expense growth %</span>
        <input type="text" inputmode="decimal" data-act="forecast-expense" value="${esc(forecastState.expense_growth)}"
          placeholder="from the scenario"></label>
      <button class="btn btn--primary btn--sm" data-act="forecast-run">Run scenario</button>
      <button class="btn btn--quiet btn--sm" data-act="forecast-reset">Reset</button>`;
  }

  /* --------------------------------------------------- forecast: measures */

  function forecastKpis(section) {
    if (!section || section.error) {
      return sectionProblem('Forecast measures', section, 'The projected measures could not be read.');
    }

    const data = section.data || {};
    const cards = data.cards || [];

    if (!cards.length) {
      return card('Forecast measures', '', emptyState('\u25CB', 'Nothing to project',
        'There is no open year left to project.'));
    }

    return card('Forecast measures', 'Each projection beside the plan it was measured against',
      `<div class="grid grid--metrics">${cards.map(forecastKpiTile).join('')}</div>` +
      (data.note ? `<div class="note note--warn" style="margin-top:14px">${esc(data.note)}</div>` : ''));
  }

  function forecastCardValue(card, value) {
    if (value === null || value === undefined || value === '') return '\u2014';
    if (card.format === 'percent') return Number(value).toFixed(2) + '%';
    if (card.format === 'month') return String(value);
    return fmtMoney(value);
  }

  function forecastKpiTile(card) {
    const tone = forecastTone(card);

    const variance = (card.variance === null || card.variance === undefined)
      ? '<span class="kpi__delta kpi__delta--flat">No plan to compare with</span>'
      : `<span class="kpi__delta kpi__delta--${tone}">${forecastArrow(tone)}
           ${esc(forecastCardValue(card, card.variance))} <small>vs plan</small></span>`;

    const plan = (card.budget === null || card.budget === undefined)
      ? 'No plan was written for the horizon'
      : 'Plan ' + forecastCardValue(card, card.budget);

    const money = card.format === 'money';

    return `<section class="kpi kpi--static">
      <span class="kpi__label">${esc(card.label)}</span>
      <span class="kpi__value ${money ? moneyClass(card.forecast) : ''}">${esc(forecastCardValue(card, card.forecast))}</span>
      ${variance}
      <span class="metric__note">${esc(plan)}</span>
    </section>`;
  }

  /**
   * Whether the projection against the plan is good news, which is not the same
   * as the sign of the difference: earning more than planned is good and
   * spending more than planned is not, so the measure decides.
   */
  function forecastTone(card) {
    if (card.variance === null || card.variance === undefined) return 'flat';

    const n = Number(card.variance);
    if (!isFinite(n) || n === 0) return 'flat';

    const better = FORECAST_HIGHER_IS_BETTER[card.key];
    if (better === null || better === undefined) return 'neutral';

    return (n > 0) === better ? 'good' : 'bad';
  }

  function forecastArrow(tone) {
    return tone === 'good' ? '\u25B2' : (tone === 'bad' ? '\u25BC' : '\u2013');
  }

  /* ------------------------------------------------------ forecast: chart */

  function forecastChart(section) {
    if (!section || section.error) {
      return sectionProblem('Revenue and net profit', section, 'The projection could not be charted.');
    }

    const data = section.data || {};

    return card('Revenue and net profit, actual then projected',
      'Solid is what the ledger posted; dashed is what the company intends, and the band is the range',
      forecastChartSvg(data) +
      (data.note ? `<div class="note" style="margin-top:12px">${esc(data.note)}</div>` : ''));
  }

  /**
   * The projection chart, drawn by hand as SVG like the dashboard's charts, so
   * it inherits the portal's own colours and plots the ledger's decimals without
   * reformatting them.
   *
   * The posted line is drawn only through periods that have happened and the
   * projected line only through periods that have not, so the two are never
   * joined into one line and the future is never presented as if it had
   * occurred. The shaded band is the pessimistic-to-optimistic envelope and is
   * drawn only behind the projected points.
   */
  function forecastChartSvg(data) {
    const periods = data.periods || [];
    const series = data.series || [];
    const n = periods.length;

    if (!n) {
      return emptyState('\u25CB', 'Nothing to chart', 'There are no periods in the open year to plot.');
    }

    const W = 780, H = 300;
    const padL = 72, padR = 20, padT = 18, padB = 48;
    const plotW = W - padL - padR;
    const plotH = H - padT - padB;

    let min = 0, max = 0;

    series.forEach(s => (s.points || []).forEach(p => {
      [p.actual, p.forecast, p.low, p.high].forEach(v => {
        const x = Number(v);
        if (!isFinite(x) || v === null) return;
        if (x < min) min = x;
        if (x > max) max = x;
      });
    }));

    if (max === min) max = min + 1;
    const headroom = (max - min) * 0.1;
    max += headroom;
    if (min < 0) min -= headroom;

    const yOf = v => padT + plotH - ((Number(v) - min) / (max - min)) * plotH;
    const band = plotW / n;
    const cx = i => padL + band * (i + 0.5);

    let grid = '';

    for (let s = 0; s <= 4; s++) {
      const value = min + ((max - min) * s) / 4;
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

    const colours = { revenue: 'var(--chart-1)', net: 'var(--chart-3)' };
    let drawn = '';

    series.forEach((s, index) => {
      const colour = colours[s.key] || ('var(--chart-' + ((index % 4) + 1) + ')');
      const points = s.points || [];
      const posted = [];
      const projected = [];
      const bandPoints = [];

      points.forEach((p, i) => {
        if (p.is_forecast !== true && p.actual !== null && p.actual !== undefined) {
          posted.push([cx(i), yOf(p.actual)]);
        }
        if (p.is_forecast === true && p.forecast !== null && p.forecast !== undefined) {
          projected.push([cx(i), yOf(p.forecast)]);
        }
        if (p.is_forecast === true && p.low !== null && p.low !== undefined && p.high !== null && p.high !== undefined) {
          bandPoints.push([cx(i), yOf(p.low), yOf(p.high)]);
        }
      });

      const path = pts => pts.map((p, i) => (i ? 'L' : 'M') + p[0].toFixed(1) + ' ' + p[1].toFixed(1)).join(' ');

      if (bandPoints.length) {
        const top = bandPoints.map(p => p[0].toFixed(1) + ' ' + p[2].toFixed(1));
        const bottom = bandPoints.slice().reverse().map(p => p[0].toFixed(1) + ' ' + p[1].toFixed(1));
        drawn += `<path class="chart__band" d="M ${top.join(' L ')} L ${bottom.join(' L ')} Z" style="fill:${colour}"/>`;
      }

      if (posted.length > 1) drawn += `<path class="chart__line" d="${path(posted)}" fill="none" style="stroke:${colour}"/>`;
      if (projected.length > 1) {
        drawn += `<path class="chart__line chart__line--projected" d="${path(projected)}" fill="none" style="stroke:${colour}"/>`;
      }

      posted.concat(projected).forEach(p => {
        drawn += `<circle class="chart__dot" cx="${p[0].toFixed(1)}" cy="${p[1].toFixed(1)}" r="2.6" style="fill:${colour}"/>`;
      });
    });

    const legend = '<div class="chart__legend">' +
      series.map(s => `<span class="chart__key"><i class="chart__swatch chart__swatch--line" ` +
        `style="background:${colours[s.key] || 'var(--acc)'}"></i>${esc(s.label)}</span>`).join('') +
      '<span class="chart__key"><i class="chart__swatch chart__swatch--dashed"></i>Projected</span>' +
      '<span class="chart__key"><i class="chart__swatch chart__swatch--band"></i>Pessimistic\u2013optimistic range</span>' +
      '</div>';

    return `<div class="chart">${legend}
      <div class="chart__scroll">
        <svg class="chart__svg" viewBox="0 0 ${W} ${H}" role="img"
          aria-label="Revenue and net profit, actual then projected, by fiscal period">
          ${grid}${zero}${drawn}${xAxis}
        </svg>
      </div></div>`;
  }

  /* ------------------------------------------- forecast: cash and capital */

  const forecastRate = value => (value === null || value === undefined || value === '')
    ? '\u2014'
    : (Number(value) * 100).toFixed(1) + '%';

  function forecastCashOutlook(section) {
    if (!section || section.error) {
      return sectionProblem('Cash outlook', section, 'The cash outlook could not be estimated.');
    }

    const d = section.data || {};

    const rows = [
      ['Cash at the start', fmtMoney(d.opening_cash), ''],
      ['Projected net profit for the horizon', fmtMoney(d.projected_net_profit), ''],
      ['Collected from customers (' + forecastRate(d.collection_rate) + ' of receivables)',
        fmtMoney(d.expected_collections), ''],
      ['Paid to suppliers (' + forecastRate(d.payment_rate) + ' of payables)',
        fmtMoney(d.expected_payments === null || d.expected_payments === undefined
          ? null : -Math.abs(Number(d.expected_payments))), '']
    ];

    const body = `<div class="table-wrap"><table class="data">
        <thead><tr><th>Line</th><th class="num">Amount</th></tr></thead>
        <tbody>${rows.map(r => `<tr><td>${esc(r[0])}</td><td class="num">${esc(r[1])}</td></tr>`).join('')}</tbody>
        <tfoot>
          <tr><td>Projected movement</td><td class="num ${moneyClass(d.projected_movement)}">${esc(fmtMoney(d.projected_movement))}</td></tr>
          <tr><td>Projected closing cash</td><td class="num ${moneyClass(d.projected_closing_cash)}">${esc(fmtMoney(d.projected_closing_cash))}</td></tr>
        </tfoot>
      </table></div>` +
      (d.note ? `<div class="note note--warn" style="margin-top:14px">${esc(d.note)}</div>` : '');

    return card('Cash outlook', 'An estimate, on assumptions held in configuration', body);
  }

  function forecastWorkingCapital(section) {
    if (!section || section.error) {
      return sectionProblem('Working capital', section, 'The working capital could not be estimated.');
    }

    const d = section.data || {};

    const body = `<div class="table-wrap"><table class="data">
        <thead><tr><th>Measure</th><th class="num">Now</th><th class="num">Projected year end</th></tr></thead>
        <tbody>
          <tr><td>Receivables owed to us</td>
            <td class="num">${esc(fmtMoney(d.receivables))}</td>
            <td class="num"><em>${esc(fmtMoney(d.projected_receivables))}</em></td></tr>
          <tr><td>Payables we owe</td>
            <td class="num">${esc(fmtMoney(d.payables))}</td>
            <td class="num"><em>${esc(fmtMoney(d.projected_payables))}</em></td></tr>
          <tr><td>Working capital</td>
            <td class="num ${moneyClass(d.working_capital)}">${esc(fmtMoney(d.working_capital))}</td>
            <td class="num ${moneyClass(d.projected_working_capital)}"><em>${esc(fmtMoney(d.projected_working_capital))}</em></td></tr>
        </tbody>
        <tfoot><tr><td>Projected cash</td><td class="num" colspan="2">${esc(fmtMoney(d.projected_cash))}</td></tr></tfoot>
      </table></div>` +
      (d.note ? `<div class="note" style="margin-top:14px">${esc(d.note)}</div>` : '');

    return card('Working capital', 'What is owed, and what it is projected to be at the year end', body);
  }

  /* ---------------------------------------------- forecast: break-even */

  function forecastBreakEven(section) {
    if (!section || section.error) {
      return sectionProblem('Break-even', section, 'The break-even point could not be read.');
    }

    const d = section.data || {};
    const trend = d.trend || [];
    const budgetTrend = d.budget_trend || [];

    const headline = d.profitable
      ? `<div class="callout"><strong>${esc(d.month)}</strong> is the first projected month the year to date turns profitable` +
        (d.budget_month ? `, against <strong>${esc(d.budget_month)}</strong> on the plan` : ', and the plan does not reach it') + '.</div>'
      : `<div class="note note--warn"><strong>The year to date is never profitable on this projection.</strong>
           Not one projected month turns the running total positive.</div>`;

    const rows = trend.map((p, i) => {
      const plan = budgetTrend[i] || {};
      return `<tr>
        <td>${esc(p.period)}</td>
        <td class="num">${esc(fmtMoney(p.net))}</td>
        <td class="num ${moneyClass(p.cumulative)}">${esc(fmtMoney(p.cumulative))}</td>
        <td class="num">${esc(plan.cumulative === undefined ? '\u2014' : fmtMoney(plan.cumulative))}</td>
      </tr>`;
    }).join('');

    const body = headline + (trend.length
      ? `<div class="table-wrap" style="margin-top:12px"><table class="data">
          <thead><tr><th>Period</th><th class="num">Projected net</th><th class="num">Running total</th>
            <th class="num">Running total on plan</th></tr></thead>
          <tbody>${rows}</tbody></table></div>`
      : '') +
      (d.note ? `<div class="note" style="margin-top:12px">${esc(d.note)}</div>` : '');

    return card('Break-even', 'The running total from the current period, projected', body);
  }

  /* ------------------------------------ forecast: plan against projection */

  function forecastBudgetVsForecast(section) {
    if (!section || section.error) {
      return sectionProblem('Plan against projection', section, 'The comparison could not be read.');
    }

    const data = section.data || {};
    const rows = data.rows || [];

    if (!rows.length) {
      return card('Plan against projection', '', emptyState('\u25CB', 'Nothing to compare',
        'There is no projected period to hold against the plan.'));
    }

    const sourceBadge = row => row.source === 'budget' ? badge('info', 'From the plan')
      : row.source === 'run_rate' ? badge('muted', 'Trailing run-rate')
      : row.source === 'none' ? badge('muted', 'No basis')
      : badge('muted', 'Mixed');

    const body = `<div class="table-wrap"><table class="data">
        <thead><tr><th>Period</th><th class="num">Projected net</th><th class="num">Plan net</th>
          <th class="num">Variance</th><th class="num">%</th><th>Reading</th><th>Basis</th></tr></thead>
        <tbody>${rows.map(row => {
          const tone = row.variance === null ? 'flat'
            : (Number(row.variance) === 0 ? 'flat' : (Number(row.variance) > 0 ? 'good' : 'bad'));
          const pct = row.variance_percent === null || row.variance_percent === undefined
            ? '\u2014' : Number(row.variance_percent).toFixed(2) + '%';
          return `<tr>
            <td>${esc(row.period)}</td>
            <td class="num ${moneyClass(row.net)}">${esc(fmtMoney(row.net))}</td>
            <td class="num">${esc(row.budget === null ? '\u2014' : fmtMoney(row.budget))}</td>
            <td class="num ${row.variance === null ? '' : (tone === 'good' ? 'is-positive' : tone === 'bad' ? 'is-negative' : '')}">${esc(row.variance === null ? '\u2014' : fmtMoney(row.variance))}</td>
            <td class="num">${esc(pct)}</td>
            <td>${esc(row.direction === 'unknown' ? '\u2014' : row.direction)}</td>
            <td>${sourceBadge(row)}</td>
          </tr>`;
        }).join('')}</tbody></table></div>` +
      (data.note ? `<div class="note" style="margin-top:14px">${esc(data.note)}</div>` : '');

    return card('Plan against projection', 'The projection beside the plan the company wrote, period by period', body);
  }

  /* -------------------------------------------- forecast: risk and scenarios */

  function forecastRiskAlerts(section) {
    if (!section || section.error) {
      return sectionProblem('What could go wrong', section, 'The readings could not be taken.');
    }

    const data = section.data || {};
    const alerts = data.alerts || [];

    const list = alerts.length
      ? alerts.map(a => {
        const kind = a.level === 'critical' ? 'bad' : (a.level === 'warning' ? 'pending' : 'info');
        const amount = (a.amount === null || a.amount === undefined)
          ? '' : ` <strong class="${moneyClass(a.amount)}">${esc(fmtMoney(a.amount))}</strong>`;
        return `<div class="card alert alert--${esc(a.level)}" style="padding:12px 14px;margin-bottom:10px">
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
              ${badge(kind, a.title)}${amount}
            </div>
            <p style="margin:8px 0 0;color:var(--mut);font-size:12.5px">${esc(a.detail)}</p>
          </div>`;
      }).join('')
      : emptyState('\u2714', 'Nothing flagged', 'No reading of the projection crossed a threshold this build watches.');

    return card('What could go wrong', alerts.length ? `${alerts.length} reading(s) worth a look` : 'Readings, not controls',
      list + (data.note ? `<div class="note" style="margin-top:6px">${esc(data.note)}</div>` : ''));
  }

  function forecastComparison(section) {
    if (!section || section.error) {
      return sectionProblem('Scenario comparison', section, 'The scenarios could not be compared.');
    }

    const data = section.data || {};
    const scenarios = data.scenarios || [];

    const body = `<div class="table-wrap"><table class="data">
        <thead><tr><th>Scenario</th><th class="num">Revenue growth</th><th class="num">Expense growth</th>
          <th class="num">Projected net</th><th class="num">Margin</th><th class="num">Plan net</th><th class="num">Variance</th></tr></thead>
        <tbody>${scenarios.map(s => `<tr${s.selected ? ' style="background:var(--info-bg)"' : ''}>
          <td>${esc(s.label)}${s.selected ? ' ' + badge('info', 'Running') : ''}</td>
          <td class="num">${esc(Number(s.revenue_growth).toFixed(2))}%</td>
          <td class="num">${esc(Number(s.expense_growth).toFixed(2))}%</td>
          <td class="num ${moneyClass(s.net)}">${esc(fmtMoney(s.net))}</td>
          <td class="num">${esc(s.margin === null ? '\u2014' : Number(s.margin).toFixed(2) + '%')}</td>
          <td class="num">${esc(fmtMoney(s.budget))}</td>
          <td class="num">${esc(fmtMoney(s.variance))}</td>
        </tr>`).join('')}</tbody></table></div>` +
      (data.note ? `<div class="note note--warn" style="margin-top:14px">${esc(data.note)}</div>` : '');

    return card('Scenarios side by side', 'The same year under each set of assumptions, none of them stored', body);
  }

  /* ------------------------------------------ forecast: projection detail */

  function forecastPeriods(section) {
    if (!section || section.error) {
      return sectionProblem('Period by period', section, 'The projection detail could not be read.');
    }

    const periods = (section.data || {}).periods || [];

    if (!periods.length) {
      return card('Period by period', '', emptyState('\u25CB', 'Nothing to project',
        'The open year holds no periods to project.'));
    }

    const blocks = periods.map(block => {
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
              <td class="num">${esc(fmtMoney(r.actual))}</td>
              <td class="num"><em>${r.forecast === null ? '\u2014' : esc(fmtMoney(r.forecast))}</em></td>
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
          ? badge('pending', 'Projected') + ' <span style="color:var(--mut);font-size:12.5px">forecast figures only</span>'
          : badge('ok', 'Actual') + ' <span style="color:var(--mut);font-size:12.5px">reported as it happened; nothing is projected onto a past period</span>'}</div>` +
        body +
        `<p style="margin-top:10px;color:var(--mut);font-size:12.5px">Net: actual <strong>${esc(fmtMoney(block.net && block.net.actual))}</strong>` +
        ` \u00b7 forecast <em>${block.net && block.net.forecast === null ? '\u2014' : esc(fmtMoney(block.net && block.net.forecast))}</em></p>`
      );
    }).join('');

    return card('Period by period', 'Every account the projection touches, period by period', blocks);
  }

  function forecastAssumptions(section) {
    if (!section || section.error) {
      return sectionProblem('What the projection assumes', section, 'The assumptions could not be read.');
    }

    const data = section.data || {};
    const runRate = data.run_rate || {};
    const assumptions = data.assumptions || [];

    const facts = `<div class="facts">
        <div class="fact"><span class="fact__k">Basis</span>
          <strong class="fact__v">${esc(data.basis || '\u2014')}</strong></div>
        <div class="fact"><span class="fact__k">Trailing run-rate</span>
          <strong class="fact__v">${runRate.used
            ? 'Read over ' + esc(runRate.period_count || 0) + ' completed periods'
            : 'No completed periods to average yet'}</strong></div>
        <div class="fact"><span class="fact__k">As at</span>
          <strong class="fact__v">${esc(fmtDate(data.as_of))}</strong></div>
      </div>`;

    const list = assumptions.length
      ? `<ul style="margin:14px 0 0;padding-left:20px;font-size:12.5px;color:var(--mut)">` +
        assumptions.map(a => `<li>${esc(a)}</li>`).join('') + `</ul>`
      : '<p style="color:var(--mut);font-size:12.5px;margin:14px 0 0">The projection carries no stated assumptions beyond the plan and the run-rate.</p>';

    return card('What the projection assumes', 'Stated rather than left to be inferred',
      facts + list +
      (data.note ? `<div class="note note--warn" style="margin-top:14px">${esc(data.note)}</div>` : ''));
  }

  function startForecastExport(format) {
    toast('info', 'Export requested', 'The forecast is being rendered as a ' + String(format).toUpperCase() + ' file.');
    api('/exports', { method: 'POST', key: newKey(), body: { type: 'forecast', format: format } })
      .then(record => pollExport(record.data.id))
      .then(record => downloadExport(record))
      .catch(error => toast('bad', error.message, 'The export did not finish.'));
  }

  /* ---------------------------------------------- forecast: saved scenarios */

  /**
   * The company's saved scenarios and the history of the one being inspected.
   *
   * `scenarios` is the list the endpoint returned, the error its read threw, or
   * null when the caller holds no scenario right — in which case the panel is
   * left off the page rather than drawn empty, because a panel a reader can
   * never use is noise.
   */
  function forecastScenarios(scenarios) {
    if (scenarios === null || scenarios === undefined) return '';

    if (scenarios instanceof Error) {
      const forbidden = scenarios.status === 403;
      return card('Saved scenarios', 'The assumptions the company keeps, and how they changed',
        `<div class="note note--warn">${esc(forbidden
          ? 'You may read the projection but not keep scenarios, so none are shown here.'
          : scenarios.message)}</div>`);
    }

    const tools =
      (scenarios.length >= 2 ? '<button class="btn btn--quiet btn--sm" data-act="scenario-compare">Compare</button>' : '') +
      '<button class="btn btn--primary btn--sm" data-act="scenario-new">Save current assumptions</button>';

    const body = scenarios.length
      ? `<div class="grid grid--thirds">${scenarios.map(scenarioCard).join('')}</div>` + scenarioDetail()
      : emptyState('\u25CB', 'No saved scenarios',
        'Nothing has been saved yet. Set assumptions with the controls above, then save them under a name — a saved scenario is recalculated against the ledger whenever it is reopened.');

    return card('Saved scenarios', 'Assumptions the company keeps, each with an immutable version history', body, tools);
  }

  function scenarioCard(s) {
    const live = s.current_version || {};
    const open = forecastState.saved && forecastState.saved.ulid === s.ulid;
    const viewing = scenarioState.detail && scenarioState.detail.ulid === s.ulid;

    return `<section class="card scenario${open ? ' scenario--open' : ''}">
      <div class="scenario__head">
        <strong>${esc(s.name)}</strong>
        ${badge(scenarioTypeKind(s.type), s.type_label)}
        ${open ? badge('info', 'On screen') : ''}
      </div>
      <div class="facts">
        <div class="fact"><span class="fact__k">Live version</span>
          <strong class="fact__v">v${esc(s.current_version_no)}</strong>
          <small>${esc(s.version_count)} kept</small></div>
        <div class="fact"><span class="fact__k">Revenue growth</span>
          <strong class="fact__v">${esc(scenarioPercent(live.revenue_growth))}</strong></div>
        <div class="fact"><span class="fact__k">Expense growth</span>
          <strong class="fact__v">${esc(scenarioPercent(live.expense_growth))}</strong></div>
        <div class="fact"><span class="fact__k">Adjustments</span>
          <strong class="fact__v">${esc(Object.keys(live.adjustments || {}).length)}</strong></div>
        <div class="fact"><span class="fact__k">Author</span>
          <strong class="fact__v">${esc(authorName(live.created_by))}</strong>
          <small>updated ${esc(fmtDateTime(s.updated_at))}</small></div>
      </div>
      <div class="toolbar" style="margin-top:12px">
        <button class="btn btn--primary btn--sm" data-act="scenario-open" data-ulid="${esc(s.ulid)}">Open</button>
        <button class="btn btn--quiet btn--sm" data-act="scenario-history" data-ulid="${esc(s.ulid)}">${viewing ? 'Hide history' : 'History'}</button>
        <button class="btn btn--quiet btn--sm" data-act="scenario-revise" data-ulid="${esc(s.ulid)}">New version</button>
      </div>
    </section>`;
  }

  /**
   * One scenario's version history, newest first.
   *
   * Nothing here can be edited or removed: the write actions are "save a new
   * version" and "restore an old one", and both of them only ever add. The
   * live version is marked where it sits rather than moved to the top, so the
   * order the reader sees is the order the versions were written.
   */
  function scenarioDetail() {
    const detail = scenarioState.detail;
    if (!detail) return '';

    const versions = detail.versions || [];

    const rows = versions.map(v => `<tr${v.version_no === detail.current_version_no ? ' style="background:var(--info-bg)"' : ''}>
      <td>v${esc(v.version_no)}${v.version_no === detail.current_version_no ? ' ' + badge('info', 'Live') : ''}</td>
      <td>${badge(scenarioTypeKind(v.type), scenarioTypeLabel(v.type))}</td>
      <td class="num">${esc(scenarioPercent(v.revenue_growth))}</td>
      <td class="num">${esc(scenarioPercent(v.expense_growth))}</td>
      <td>${esc(scenarioAdjustmentsText(v.adjustments))}</td>
      <td>${esc(authorName(v.created_by))}</td>
      <td>${esc(fmtDateTime(v.created_at))}</td>
      <td>${v.note ? esc(v.note) : '\u2014'}</td>
      <td class="num">${v.version_no === detail.current_version_no
        ? '<span class="cell__sub">live</span>'
        : `<button class="btn btn--quiet btn--sm" data-act="scenario-restore" data-ulid="${esc(detail.ulid)}" data-version="${esc(v.ulid)}" data-version-no="${esc(v.version_no)}">Restore</button>`}</td>
    </tr>`).join('');

    return `<div class="scenario-detail">
      <div class="card__head" style="margin-top:18px">
        <div><h4 style="font-size:14px">${esc(detail.name)} \u2014 version history</h4>
          <p>Newest first. A version is never edited or deleted; restoring one writes a new version that copies it.</p></div>
        <div class="spacer"><button class="btn btn--quiet btn--sm" data-act="scenario-history-close">Close history</button></div>
      </div>
      <div class="note note--warn">${esc(detail.note || '')}</div>
      <div class="table-wrap" style="margin-top:12px"><table class="data">
        <thead><tr><th>Version</th><th>Type</th><th class="num">Revenue growth</th><th class="num">Expense growth</th>
          <th>Adjustments</th><th>Author</th><th>Saved</th><th>Note</th><th></th></tr></thead>
        <tbody>${rows}</tbody></table></div>
    </div>`;
  }

  function scenarioPercent(value) {
    if (value === null || value === undefined || value === '') return '\u2014';
    const n = Number(value);
    return isFinite(n) ? n.toFixed(2) + '%' : String(value);
  }

  function scenarioAdjustmentsText(adjustments) {
    const keys = Object.keys(adjustments || {});
    if (!keys.length) return '\u2014';
    return keys.map(k => '#' + k + ' ' + fmtMoney(adjustments[k])).join(', ');
  }

  function authorName(author) {
    return author && author.name ? author.name : '\u2014';
  }

  /* ------------------------------------------------- forecast: runway */

  /**
   * How long the cash lasts on the projection on screen.
   *
   * The panel is read, never computed here: every figure arrived from the
   * `cash_runway` section. Where the endpoint could not answer — no cash
   * account to read a position from, no burn to divide by, or no month that
   * turns negative — the panel says so in words rather than printing a zero or
   * a date that was never observed.
   */
  function forecastRunway(section, maySetThreshold) {
    if (!section || section.error) {
      return sectionProblem('Cash runway', section, 'How long the cash lasts could not be estimated.');
    }

    const d = section.data || {};
    const status = d.status || 'unknown';
    const kind = { critical: 'bad', watch: 'pending', healthy: 'ok' }[status] || 'muted';
    const hasCover = d.months_of_cover !== null && d.months_of_cover !== undefined;

    const tools = maySetThreshold
      ? `<label class="field field--date" style="min-width:220px"><span class="field__label">Low-cash threshold (${esc(thresholdSource(d.threshold_source))})</span>
           <input type="text" inputmode="decimal" data-act="runway-threshold" value="${esc(d.threshold || '0')}"></label>
         <button class="btn btn--quiet btn--sm" data-act="runway-threshold-save">Save threshold</button>`
      : '';

    const facts = `<div class="facts">
      <div class="fact"><span class="fact__k">Cash position</span>
        <strong class="fact__v ${moneyClass(d.opening_cash)}">${esc(fmtMoney(d.opening_cash))}</strong>
        <small>as at ${esc(fmtDate(d.as_of))}${d.is_estimate ? ' \u00b7 an estimate' : ''}</small></div>
      <div class="fact"><span class="fact__k">Average monthly ${d.is_burning ? 'burn' : 'movement'}</span>
        <strong class="fact__v">${esc(fmtMoney(d.average_monthly_movement))}</strong>
        <small>${d.is_burning ? 'the company is burning cash' : 'the company is not burning cash'}</small></div>
      <div class="fact"><span class="fact__k">Months of cover</span>
        <strong class="fact__v">${hasCover ? esc(Number(d.months_of_cover).toFixed(1)) : '\u2014'}</strong>
        <small>${hasCover ? 'on the average burn' : (d.has_cash_data ? 'nothing to divide by' : 'no cash position to read')}</small></div>
      <div class="fact"><span class="fact__k">Cash turns negative</span>
        <strong class="fact__v">${d.negative_month ? esc(d.negative_month)
          : (d.has_cash_data ? 'Never in the horizon' : 'Cannot be read')}</strong>
        <small>${d.negative_month ? 'the first month it does' : (d.has_cash_data ? 'no projected month turns negative' : 'no cash accounts set up')}</small></div>
      <div class="fact"><span class="fact__k">Lowest projected cash</span>
        <strong class="fact__v ${moneyClass(d.lowest_cash)}">${esc(fmtMoney(d.lowest_cash))}</strong>
        <small>${esc(d.lowest_cash_month || '\u2014')}</small></div>
      <div class="fact"><span class="fact__k">Runway status</span>
        <span class="fact__v">${badge(kind, d.status_label || status)}</span></div>
    </div>`;

    const months = d.months || [];
    const table = months.length
      ? `<div class="table-wrap" style="margin-top:14px"><table class="data">
          <thead><tr><th>Month</th><th class="num">Projected movement</th><th class="num">Closing cash</th><th>Reading</th></tr></thead>
          <tbody>${months.map(m => `<tr>
            <td>${esc(m.period)}</td>
            <td class="num ${moneyClass(m.movement)}">${esc(fmtMoney(m.movement))}</td>
            <td class="num ${moneyClass(m.closing_cash)}">${esc(fmtMoney(m.closing_cash))}</td>
            <td>${m.negative ? badge('bad', 'Below zero') : (m.below_threshold ? badge('pending', 'Below threshold') : badge('ok', 'Above threshold'))}</td>
          </tr>`).join('')}</tbody></table></div>`
      : '<div class="note" style="margin-top:14px">No closing cash is projected: there is no cash position to close from.</div>';

    const assumptions = (d.assumptions || []).length
      ? `<details class="runway__how"><summary>How this is calculated</summary>
           <ul>${(d.assumptions || []).map(a => `<li>${esc(a)}</li>`).join('')}</ul></details>`
      : '';

    return card('Cash runway', 'How long the cash lasts on this projection, and how it was worked out',
      facts + (d.note ? `<div class="note note--warn" style="margin-top:14px">${esc(d.note)}</div>` : '') + table + assumptions, tools);
  }

  function thresholdSource(source) {
    return source === 'company' ? 'company setting' : 'built-in default';
  }

  /* ---------------------------------------- forecast: historical data */

  /**
   * The label the projection has to earn the right to print.
   *
   * The sample size, the window it covers and the basis are the endpoint's own
   * — read from the completed periods of the fiscal year — and the limitations
   * are stated beside them rather than left for a reader to work out.
   */
  function forecastHistory(history) {
    if (!history) return '';

    const periods = history.periods || [];
    const label = history.label || ('Based on ' + (history.months || 0) + ' months of historical data');
    const limitations = (history.limitations || []).length
      ? `<ul class="runway__bounds">${(history.limitations || []).map(l => `<li>${esc(l)}</li>`).join('')}</ul>`
      : '';

    return card('Historical data', 'What the projection was averaged from, and what it cannot tell you',
      `<div class="facts">
        <div class="fact"><span class="fact__k">Sample</span>
          <strong class="fact__v">${esc(label)}</strong>
          <small>${history.available ? 'a trailing run-rate is in use' : 'no completed periods to average'}</small></div>
        <div class="fact"><span class="fact__k">Period covered</span>
          <strong class="fact__v">${periods.length ? esc(fmtDate(history.from) + ' \u2192 ' + fmtDate(history.to)) : '\u2014'}</strong>
          <small>${esc(periods.length)} completed period(s)</small></div>
        <div class="fact"><span class="fact__k">Availability</span>
          <span class="fact__v">${badge(history.available ? 'ok' : 'muted', history.available ? 'Available' : 'Not available')}</span></div>
      </div>
      <p class="runway__basis">${esc(history.basis || '')}</p>
      ${limitations}`);
  }

  /* ------------------------------------- forecast: scenario actions */

  async function openScenario(ulid) {
    const s = (forecastState.scenarios || []).find(x => x.ulid === ulid);
    if (!s) return;

    const live = s.current_version || {};

    forecastState.saved = {
      ulid: s.ulid,
      name: s.name,
      type: live.type || s.type,
      version_no: s.current_version_no,
      revenue_growth: live.revenue_growth === null || live.revenue_growth === undefined ? '' : String(live.revenue_growth),
      expense_growth: live.expense_growth === null || live.expense_growth === undefined ? '' : String(live.expense_growth),
      adjustments: live.adjustments || {}
    };

    // An open saved scenario replaces any ad-hoc run: two sets of assumptions
    // cannot both be on screen, and the ones drawn beside the figures are the
    // ones the figures were built from.
    forecastState.scenario = '';
    forecastState.revenue_growth = '';
    forecastState.expense_growth = '';
    scenarioState.detail = null;
    go('forecast');
  }

  function clearSavedScenario() {
    forecastState.saved = null;
    scenarioState.detail = null;
    go('forecast');
  }

  async function showScenarioHistory(ulid) {
    if (scenarioState.detail && scenarioState.detail.ulid === ulid) {
      scenarioState.detail = null;
      redrawForecast();
      return;
    }

    try {
      const payload = await api('/forecast/scenarios/' + encodeURIComponent(ulid));
      scenarioState.detail = (payload && payload.data) || null;
      redrawForecast();
    } catch (error) {
      toast('bad', error.message, 'The version history could not be read.');
    }
  }

  async function newScenario() {
    const seed = {
      name: '',
      type: 'base',
      revenue_growth: forecastState.revenue_growth,
      expense_growth: forecastState.expense_growth,
      adjustments: {}
    };

    const form = await scenarioFormModal({
      title: 'Save these assumptions as a scenario',
      okLabel: 'Save scenario',
      seed,
      requireName: true
    });
    if (!form) return;

    try {
      await api('/forecast/scenarios', {
        method: 'POST',
        key: newKey(),
        body: scenarioRequestBody(form)
      });
      toast('ok', 'Scenario saved', 'Its first version was written; saving again adds a version rather than editing one.');
      go('forecast');
    } catch (error) {
      toast('bad', error.message, 'The scenario was not saved.');
    }
  }

  async function reviseScenario(ulid) {
    const s = (forecastState.scenarios || []).find(x => x.ulid === ulid);
    if (!s) return;

    const live = s.current_version || {};
    const seed = {
      name: s.name,
      type: live.type || s.type,
      revenue_growth: live.revenue_growth,
      expense_growth: live.expense_growth,
      adjustments: live.adjustments || {}
    };

    const form = await scenarioFormModal({
      title: 'Save a new version of \u201c' + s.name + '\u201d',
      okLabel: 'Save new version',
      seed,
      requireName: false
    });
    if (!form) return;

    try {
      await api('/forecast/scenarios/' + encodeURIComponent(ulid) + '/versions', {
        method: 'POST',
        key: newKey(),
        body: scenarioRequestBody(form)
      });
      toast('ok', 'New version saved', 'The version it was saved from is kept; nothing was edited in place.');
      go('forecast');
    } catch (error) {
      toast('bad', error.message, 'The new version was not saved.');
    }
  }

  function restoreScenarioVersion(ulid, versionUlid, versionNo) {
    confirmModal({
      title: 'Restore version ' + versionNo + '?',
      body: '<p>Restoring does not edit the old version. It writes a new version that copies it, so the history keeps both what was saved then and that it was brought back now.</p>' +
        '<p>The restored assumptions are then recalculated against today\u2019s posted ledger.</p>',
      okLabel: 'Restore',
      tone: 'primary'
    }).then(async confirmed => {
      if (!confirmed) return;

      try {
        const payload = await api('/forecast/scenarios/' + encodeURIComponent(ulid) + '/versions/' + encodeURIComponent(versionUlid) + '/restore', {
          method: 'POST',
          key: newKey(),
          body: {}
        });
        const version = (payload && payload.data) || {};

        // The live version just moved, so an open scenario is re-pointed at it
        // rather than left replaying the assumptions it had a moment ago.
        if (forecastState.saved && forecastState.saved.ulid === ulid) {
          forecastState.saved = {
            ulid,
            name: forecastState.saved.name,
            type: version.type || forecastState.saved.type,
            version_no: version.version_no,
            revenue_growth: String(version.revenue_growth || ''),
            expense_growth: String(version.expense_growth || ''),
            adjustments: version.adjustments || {}
          };
        }

        toast('ok', 'Version restored', 'A new version now carries those assumptions.');
        go('forecast');
      } catch (error) {
        toast('bad', error.message, 'The version was not restored.');
      }
    });
  }

  function scenarioRequestBody(form) {
    return {
      name: form.name || null,
      type: form.type,
      revenue_growth: form.revenue_growth === '' ? null : form.revenue_growth,
      expense_growth: form.expense_growth === '' ? null : form.expense_growth,
      adjustments: form.adjustments,
      note: form.note || null
    };
  }

  async function runScenarioComparison() {
    const list = Array.isArray(forecastState.scenarios) ? forecastState.scenarios : [];
    if (list.length < 2) {
      toast('info', 'Two scenarios are needed', 'Save a second scenario to hold the two side by side.');
      return;
    }

    const query = list.slice(0, 5).map(s => 'scenarios[]=' + encodeURIComponent(s.ulid)).join('&');

    try {
      const payload = await api('/forecast/scenarios/comparison?' + query);
      openModal('Saved scenarios side by side', scenarioComparisonBody((payload && payload.data) || {}), 'Close', 'quiet');
    } catch (error) {
      toast('bad', error.message, 'The scenarios could not be compared.');
    }
  }

  function scenarioComparisonBody(data) {
    const scenarios = data.scenarios || [];

    const rows = scenarios.map(s => {
      const live = s.current_version || {};
      return `<tr${forecastState.saved && forecastState.saved.ulid === s.ulid ? ' style="background:var(--info-bg)"' : ''}>
        <td>${esc(s.name)} ${badge(scenarioTypeKind(s.type), s.type_label)}</td>
        <td class="num">${esc(scenarioPercent(live.revenue_growth))}</td>
        <td class="num">${esc(scenarioPercent(live.expense_growth))}</td>
        <td class="num">${esc(Object.keys(live.adjustments || {}).length)}</td>
        <td>v${esc(s.current_version_no)} of ${esc(s.version_count)}</td>
        <td>${esc(authorName(live.created_by))}</td>
        <td>${esc(fmtDateTime(s.updated_at))}</td>
      </tr>`;
    }).join('');

    return `<div class="note note--warn">${esc(data.note || '')}</div>
      <div class="table-wrap" style="margin-top:12px"><table class="data">
        <thead><tr><th>Scenario</th><th class="num">Revenue growth</th><th class="num">Expense growth</th>
          <th class="num">Adjustments</th><th>Live version</th><th>Author</th><th>Updated</th></tr></thead>
        <tbody>${rows}</tbody></table></div>`;
  }

  /* ------------------------------------ forecast: scenario form */

  async function scenarioFormModal(options) {
    const chart = await accounts();
    const revenue = chart.filter(a => a.account_type === 'revenue');
    const expense = chart.filter(a => a.account_type === 'expense');

    const promise = openModal(
      options.title,
      scenarioFormBody(options.seed || {}, revenue, expense),
      options.okLabel,
      'primary'
    );

    const ok = $('#modal-ok');
    const nameInput = $('#scenario-name');
    if (nameInput) nameInput.focus();

    ok.onclick = () => {
      const read = scenarioReadForm(options);
      const error = $('[data-error="scenario"]');
      if (read.error) {
        if (error) error.textContent = read.error;
        return;
      }
      if (error) error.textContent = '';
      ok.onclick = null;
      closeModal(read.value);
    };

    return promise;
  }

  function scenarioFormBody(seed, revenueAccounts, expenseAccounts) {
    const typeOptions = SCENARIO_TYPES.map(t =>
      `<option value="${esc(t.name)}"${seed.type === t.name ? ' selected' : ''}>${esc(t.label)}</option>`).join('');

    const adjustments = Object.keys(seed.adjustments || {})
      .map(account => ({ account_id: account, amount: String(seed.adjustments[account]) }));
    if (!adjustments.length) adjustments.push({ account_id: '', amount: '' });

    return `<label class="field"><span class="field__label">Scenario name</span>
        <input id="scenario-name" value="${esc(seed.name || '')}" maxlength="120" placeholder="Board case"></label>
      <label class="field" style="margin-top:12px"><span class="field__label">Type</span>
        <select id="scenario-type">${typeOptions}</select></label>
      <div class="toolbar" style="margin-top:12px">
        <label class="field field--date"><span class="field__label">Revenue growth %</span>
          <input id="scenario-revenue" inputmode="decimal" value="${esc(seed.revenue_growth || '')}" placeholder="0"></label>
        <label class="field field--date"><span class="field__label">Expense growth %</span>
          <input id="scenario-expense" inputmode="decimal" value="${esc(seed.expense_growth || '')}" placeholder="0"></label>
      </div>
      <div class="note" style="margin-top:12px">A rate is a plain decimal percentage: 12 is twelve per cent and -5 is minus five. Leave one blank to hold it at zero.</div>
      <span class="field__label" style="display:block;margin-top:14px">Per-account adjustments</span>
      <div class="table-wrap" style="margin-top:8px"><table class="data">
        <thead><tr><th style="min-width:300px">Account (revenue or expense)</th><th class="num" style="min-width:150px">Adjustment</th><th></th></tr></thead>
        <tbody id="scenario-adjustments">
          ${adjustments.map((a, index) => scenarioAdjustmentRow(a, index, revenueAccounts, expenseAccounts)).join('')}
        </tbody>
      </table></div>
      <div class="toolbar" style="margin-top:10px">
        <button class="btn btn--quiet btn--sm" data-act="scenario-add-adjustment">Add an adjustment</button>
      </div>
      <label class="field" style="margin-top:12px"><span class="field__label">Note (optional)</span>
        <textarea id="scenario-note" rows="2" maxlength="500"></textarea></label>
      <em class="field__error" data-error="scenario"></em>`;
  }

  function scenarioAdjustmentRow(row, index, revenueAccounts, expenseAccounts) {
    const options = `<option value="">Choose an account\u2026</option>` +
      `<optgroup label="Revenue">${accountOptions(revenueAccounts, row.account_id)}</optgroup>` +
      `<optgroup label="Expense">${accountOptions(expenseAccounts, row.account_id)}</optgroup>`;

    return `<tr data-adjustment-row="${index}">
      <td><select data-scenario-field="account_id">${options}</select></td>
      <td class="num"><input data-scenario-field="amount" value="${esc(row.amount)}" inputmode="decimal" placeholder="0.00"
        style="text-align:right;width:100%;background:transparent;border:1px solid var(--bd);border-radius:8px;padding:6px 8px;color:inherit"></td>
      <td class="num"><button class="btn btn--quiet btn--sm" data-act="scenario-remove-adjustment" data-index="${index}">Remove</button></td>
    </tr>`;
  }

  function scenarioAddAdjustment() {
    const body = $('#scenario-adjustments');
    if (!body) return;

    const chart = cache.accounts || [];
    const index = $$('#scenario-adjustments tr').length;
    body.insertAdjacentHTML('beforeend', scenarioAdjustmentRow({ account_id: '', amount: '' }, index,
      chart.filter(a => a.account_type === 'revenue'), chart.filter(a => a.account_type === 'expense')));
  }

  function scenarioRemoveAdjustment(index) {
    const row = $('#scenario-adjustments tr[data-adjustment-row="' + index + '"]');
    if (row) row.remove();
  }

  function scenarioReadForm(options) {
    const value = id => (($(id) || {}).value || '').trim();
    const decimal = /^-?\d+(\.\d{1,4})?$/;

    const name = value('#scenario-name');
    const type = value('#scenario-type') || 'base';
    const revenue = value('#scenario-revenue');
    const expense = value('#scenario-expense');
    const note = value('#scenario-note');

    if (options.requireName && !name) {
      return { error: 'A scenario needs a name.' };
    }
    if (revenue && !decimal.test(revenue)) {
      return { error: 'The revenue growth must be a plain decimal, like 12 or -5.5.' };
    }
    if (expense && !decimal.test(expense)) {
      return { error: 'The expense growth must be a plain decimal, like 12 or -5.5.' };
    }

    const adjustments = [];
    let badAdjustment = false;

    $$('#scenario-adjustments tr').forEach(row => {
      const account = ($('[data-scenario-field="account_id"]', row) || {}).value || '';
      const amount = (($('[data-scenario-field="amount"]', row) || {}).value || '').trim();
      if (!account && !amount) return;
      if (!account || !decimal.test(amount)) { badAdjustment = true; return; }
      adjustments.push({ account_id: Number(account), amount: String(amount) });
    });

    if (badAdjustment) {
      return { error: 'Each adjustment needs an account and a plain decimal amount.' };
    }

    return { value: { name, type, revenue_growth: revenue, expense_growth: expense, adjustments, note } };
  }

  /* ------------------------------------- forecast: low-cash threshold */

  async function saveLowCashThreshold() {
    const input = $('[data-act="runway-threshold"]');
    if (!input) return;

    const value = String(input.value || '').trim();

    if (!/^\d+(\.\d{1,4})?$/.test(value)) {
      toast('bad', 'A threshold is a non-negative decimal.', 'Try 7500 or 7500.00.');
      return;
    }

    const button = $('[data-act="runway-threshold-save"]');
    if (button) button.disabled = true;

    try {
      await api('/forecast/settings', {
        method: 'PUT',
        key: newKey(),
        body: { forecast_low_cash_threshold: value }
      });
      toast('ok', 'Threshold saved', 'It is a company setting, merged with the others and written to the audit chain.');
      go('forecast');
    } catch (error) {
      toast('bad', error.message, 'The threshold was not saved.');
    } finally {
      if (button) button.disabled = false;
    }
  }

  /* ----------------------------------------------------- screen: budgets */

  /**
   * The plan for a fiscal year, as a version that has a life.
   *
   * A budget is written as a version of a year's plan and never as a posting:
   * nothing this screen sends reaches the ledger. Two reads build it — the
   * history of the year's versions, and the one version being shown — and the
   * newest version, which is the plan the reports read, is shown by default.
   * The plan-against-actual card at the foot is a third read, for one period,
   * and only ever reads the posted ledger.
   */
  const budgetState = {
    year: '',
    version: '',
    detail: null,
    summaries: [],
    yearMeta: null,
    bvaPeriod: '',
    bva: null
  };

  function budgetYearOf(period) {
    return period && period.fiscal_year ? period.fiscal_year.ulid : '';
  }

  // The fiscal years the calendar holds, in the order it holds them.
  function budgetYears(list) {
    const seen = {};
    const years = [];

    (list || []).forEach(p => {
      const year = p.fiscal_year;
      if (!year || seen[year.ulid]) return;
      seen[year.ulid] = true;
      years.push(year);
    });

    return years;
  }

  function budgetAccountIndex(chart) {
    const index = {};
    (chart || []).forEach(a => { index[Number(a.id)] = a; });
    return index;
  }

  function budgetStatusBadge(status) {
    return {
      draft: badge('muted', 'Draft'),
      pending_approval: badge('pending', 'Pending approval'),
      approved: badge('approved', 'Approved'),
      locked: badge('info', 'Locked')
    }[status] || badge('muted', status || '\u2014');
  }

  async function drawBudgets(container) {
    setStatus('Reading the plan');
    await load(container, async () => {
      const list = await periods();

      if (!list.length) {
        setStatus('Ready');
        return card('Budget Management', 'One plan per fiscal year',
          emptyState('\u25CB', 'No fiscal calendar', 'There are no periods to plan against yet.'));
      }

      const years = budgetYears(list);
      const thisPeriod = currentPeriod(list);
      const fallback = budgetYearOf(thisPeriod)
        || budgetYearOf(list[list.length - 1])
        || (years[0] && years[0].ulid)
        || '';

      budgetState.year = years.some(y => y.ulid === budgetState.year) ? budgetState.year : fallback;

      const yearPeriods = periodsOfYear(list, budgetState.year);

      const payload = await api('/budgets/versions?fiscal_year=' + encodeURIComponent(budgetState.year));
      budgetState.summaries = (payload && payload.data) || [];
      budgetState.yearMeta = (payload && payload.fiscal_year) || null;

      // The version on screen: the one asked for if the year still holds it,
      // otherwise the newest, which is the standing plan.
      const wanted = budgetState.summaries.some(s => s.ulid === budgetState.version)
        ? budgetState.version
        : (budgetState.summaries[0] && budgetState.summaries[0].ulid) || '';

      budgetState.version = wanted;
      budgetState.detail = null;

      if (wanted) {
        const one = await api('/budgets/versions/' + encodeURIComponent(wanted));
        budgetState.detail = (one && one.data) || null;
      }

      await budgetReadVariance(yearPeriods);

      const chart = await accounts();

      setStatus('Ready');
      return renderBudgets(list, yearPeriods, chart);
    });
  }

  /**
   * The plan beside what a period actually did.
   *
   * Held as the card's own value — the payload, or the error its read threw —
   * so a refusal can never take the rest of the screen off the page.
   */
  async function budgetReadVariance(yearPeriods) {
    const list = yearPeriods || [];
    const thisPeriod = currentPeriod(list);
    const wanted = list.some(p => p.ulid === budgetState.bvaPeriod)
      ? budgetState.bvaPeriod
      : ((thisPeriod && thisPeriod.ulid) || (list[list.length - 1] && list[list.length - 1].ulid) || '');

    budgetState.bvaPeriod = wanted;
    budgetState.bva = null;

    if (!wanted) return;

    try {
      budgetState.bva = await api('/reports/budget-vs-actual?fiscal_period=' + encodeURIComponent(wanted));
    } catch (error) {
      budgetState.bva = error;
    }
  }

  function renderBudgets(list, yearPeriods, chart) {
    return budgetHead(list, yearPeriods) +
      budgetKpis(budgetState.detail, chart, yearPeriods) +
      '<div style="margin-top:16px">' + budgetGrid(budgetState.detail, yearPeriods, chart) + '</div>' +
      '<div style="margin-top:16px">' + budgetLifecycle() + '</div>' +
      '<div style="margin-top:16px">' + budgetVariance(yearPeriods) + '</div>';
  }

  /* -------------------------------------------------------- budgets: head */

  function budgetHead(list, yearPeriods) {
    const year = budgetState.yearMeta || {};
    const detail = budgetState.detail;
    const years = budgetYears(list);
    const kept = budgetState.summaries.length;

    const yearPicker = years.length > 1
      ? `<label class="field" style="min-width:190px"><span class="field__label">Fiscal year</span>
           <select data-act="budget-year">${years.map(y =>
             `<option value="${esc(y.ulid)}"${y.ulid === budgetState.year ? ' selected' : ''}>${esc(y.name)}</option>`).join('')}</select></label>`
      : '';

    const writeLabel = !detail ? 'Write the year\u2019s plan'
      : (detail.is_editable ? 'Edit the draft' : 'Write the next version');

    return `<section class="card budget">
      <div class="card__head">
        <div>
          <h3>${esc(year.name || 'The fiscal year')} plan</h3>
          <p>The plan the company intends to follow. It is a plan and never a posting: writing one posts no journal and the ledger does not know it exists.</p>
        </div>
        <div class="spacer"><span class="forecast__flag">PLAN \u2014 NOT POSTED</span></div>
      </div>
      <div class="facts">
        <div class="fact"><span class="fact__k">Fiscal year</span>
          <strong class="fact__v">${esc(year.name || '\u2014')}</strong>
          <small>${esc(fmtDate(year.starts_on))} \u2192 ${esc(fmtDate(year.ends_on))}</small></div>
        <div class="fact"><span class="fact__k">Periods planned over</span>
          <strong class="fact__v">${esc(yearPeriods.length)}</strong>
          <small>${esc(budgetRange(yearPeriods))}</small></div>
        <div class="fact"><span class="fact__k">Standing version</span>
          <strong class="fact__v">${detail ? 'v' + esc(detail.version_no) : '\u2014'}</strong>
          <small>${kept ? esc(kept) + ' kept in the history' : 'no plan written yet'}</small></div>
        <div class="fact"><span class="fact__k">Status</span>
          <span class="fact__v">${detail ? budgetStatusBadge(detail.status) : badge('muted', 'No plan')}</span></div>
        <div class="fact"><span class="fact__k">Last written</span>
          <strong class="fact__v">${esc(detail ? fmtDateTime(detail.updated_at) : '\u2014')}</strong>
          <small>${detail && detail.created_by_user_id ? 'by member #' + esc(detail.created_by_user_id) : 'a read, not a stored run'}</small></div>
      </div>
      <div class="dash-head__tools">
        ${yearPicker}
        <button class="btn btn--primary btn--sm" data-act="budget-new">${writeLabel}</button>
        <button class="btn btn--quiet btn--sm" data-act="budget-refresh">Refresh</button>
      </div>
    </section>`;
  }

  function budgetRange(yearPeriods) {
    const list = yearPeriods || [];
    if (!list.length) return 'no periods';
    const first = list[0];
    const last = list[list.length - 1];
    return fmtDate(first.starts_on) + ' \u2192 ' + fmtDate(last.ends_on);
  }

  /* -------------------------------------------------------- budgets: KPIs */

  /**
   * What the year's plan adds up to. Read from the version on screen and the
   * company's own chart, which is what says whether an account is revenue or
   * expense — the plan itself records only the account.
   */
  function budgetTotals(detail, chart) {
    const index = budgetAccountIndex(chart);
    const accounts = {};
    const periods = {};
    let revenue = 0;
    let expense = 0;

    ((detail && detail.lines) || []).forEach(line => {
      const account = index[Number(line.account_id)];
      const type = account ? account.account_type : '';
      if (type !== 'revenue' && type !== 'expense') return;

      const amount = Number(line.amount);
      if (!isFinite(amount) || amount === 0) return;

      if (type === 'revenue') revenue += amount;
      else expense += amount;

      accounts[Number(line.account_id)] = true;
      periods[String(line.fiscal_period_id)] = true;
    });

    const net = revenue - expense;

    return {
      revenue: revenue,
      expense: expense,
      net: net,
      margin: revenue !== 0 ? (net / revenue) * 100 : null,
      accounts: Object.keys(accounts).length,
      months: Object.keys(periods).length
    };
  }

  function budgetKpis(detail, chart, yearPeriods) {
    const t = budgetTotals(detail, chart);
    const months = (yearPeriods || []).length;

    const tiles = [
      budgetKpiTile('Planned revenue', fmtMoney(t.revenue), moneyClass(t.revenue), 'Revenue accounts, whole year'),
      budgetKpiTile('Planned expense', fmtMoney(t.expense), moneyClass(t.expense), 'Expense accounts, whole year'),
      budgetKpiTile('Planned net', fmtMoney(t.net), moneyClass(t.net), 'Revenue less expense'),
      budgetKpiTile('Planned margin', t.margin === null ? '\u2014' : t.margin.toFixed(2) + '%', '', 'Of planned revenue'),
      budgetKpiTile('Accounts planned', String(t.accounts), '', 'Revenue and expense accounts with a figure'),
      budgetKpiTile('Months planned', months ? t.months + ' of ' + months : String(t.months), '', 'Periods the plan touches')
    ].join('');

    const body = `<div class="grid grid--kpis">${tiles}</div>` +
      (detail ? '' : `<div class="note note--warn" style="margin-top:14px">No plan has been written for this fiscal year yet, so every measure reads zero. Write a version to fill them in.</div>`);

    return card('Plan measures', detail
      ? 'What version v' + esc(detail.version_no) + ' of the year\u2019s plan adds up to'
      : 'What the year\u2019s plan adds up to', body);
  }

  function budgetKpiTile(label, value, cls, note) {
    return `<section class="kpi kpi--static">
      <span class="kpi__label">${esc(label)}</span>
      <span class="kpi__value ${cls || ''}">${esc(value)}</span>
      <span class="metric__note">${esc(note)}</span>
    </section>`;
  }

  /* -------------------------------------------------------- budgets: grid */

  function budgetPeriodLabel(period) {
    const starts = period && period.starts_on ? String(period.starts_on) : '';
    const date = starts ? new Date(starts + 'T00:00:00') : null;
    if (date && !isNaN(date.getTime())) return date.toLocaleString('en', { month: 'short' });
    return (period && period.name) || '\u2014';
  }

  function budgetTrim(value) {
    const n = Number(value);
    if (!isFinite(n)) return String(value);
    return String(Number(n.toFixed(4)));
  }

  /**
   * The year's plan, month by month: one row per account and one column per
   * period, with a year total on each row and a planned revenue, expense and
   * net on each column. It is a reading grid — an account the company planned
   * nothing against is left out rather than drawn as a row of zeroes.
   */
  function budgetGrid(detail, yearPeriods, chart) {
    const periods = (yearPeriods || []).slice()
      .sort((a, b) => Number(a.period_no) - Number(b.period_no));

    if (!periods.length) {
      return card('Month by month', '',
        emptyState('\u25CB', 'No periods in this year', 'The fiscal year holds no periods to plan over.'));
    }

    const index = budgetAccountIndex(chart);
    const cell = {};
    const used = {};

    ((detail && detail.lines) || []).forEach(line => {
      const account = index[Number(line.account_id)];
      const type = account ? account.account_type : '';
      if (type !== 'revenue' && type !== 'expense') return;

      const amount = Number(line.amount);
      if (!isFinite(amount) || amount === 0) return;

      const key = Number(line.account_id);
      cell[key] = cell[key] || {};
      cell[key][Number(line.fiscal_period_id)] = (cell[key][Number(line.fiscal_period_id)] || 0) + amount;
      used[key] = true;
    });

    const rowsOf = type => Object.keys(used)
      .map(Number)
      .filter(id => index[id] && index[id].account_type === type)
      .sort((a, b) => String(index[a].code).localeCompare(String(index[b].code)));

    const revenue = rowsOf('revenue');
    const expense = rowsOf('expense');

    const span = periods.length + 3;

    const accountRow = id => {
      const account = index[id];
      const cells = periods.map(p => {
        const value = (cell[id] || {})[Number(p.id)];
        return `<td class="num">${value === undefined
          ? '<span class="cell__sub">\u00b7</span>' : esc(fmtMoney(value))}</td>`;
      }).join('');
      const total = periods.reduce((sum, p) => sum + ((cell[id] || {})[Number(p.id)] || 0), 0);

      return `<tr>
        <td class="budget-grid__code">${esc(account.code)}</td>
        <td>${esc(account.name)}</td>
        ${cells}
        <td class="num budget-grid__total ${moneyClass(total)}">${esc(fmtMoney(total))}</td>
      </tr>`;
    };

    const columnTotals = periods.map(p => {
      const id = Number(p.id);
      const rev = revenue.reduce((sum, a) => sum + ((cell[a] || {})[id] || 0), 0);
      const exp = expense.reduce((sum, a) => sum + ((cell[a] || {})[id] || 0), 0);
      return { rev: rev, exp: exp, net: rev - exp };
    });

    const year = columnTotals.reduce((s, c) => ({ rev: s.rev + c.rev, exp: s.exp + c.exp }), { rev: 0, exp: 0 });

    const subtitle = detail
      ? 'Version v' + esc(detail.version_no) + ' \u00b7 ' + esc(detail.status_label || detail.status)
      : 'The plan the company intends to follow';

    if (!revenue.length && !expense.length) {
      return card('Month by month', subtitle, emptyState('\u25CB', 'Nothing planned yet',
        detail
          ? 'This version carries no amounts against a revenue or expense account.'
          : 'No plan has been written for this fiscal year yet.'));
    }

    const header = periods.map(p =>
      `<th class="num">${esc(budgetPeriodLabel(p))}<span class="cell__sub">${esc(p.name)}</span></th>`).join('');

    const body = `<div class="table-wrap"><table class="data budget-grid">
        <thead><tr><th>Code</th><th>Account</th>${header}<th class="num">Year</th></tr></thead>
        <tbody>
          ${revenue.length ? `<tr class="budget-grid__section"><td colspan="${span}">Revenue</td></tr>` : ''}
          ${revenue.map(accountRow).join('')}
          ${expense.length ? `<tr class="budget-grid__section"><td colspan="${span}">Expense</td></tr>` : ''}
          ${expense.map(accountRow).join('')}
        </tbody>
        <tfoot>
          <tr><td colspan="2">Planned revenue</td>
            ${columnTotals.map(c => `<td class="num">${esc(fmtMoney(c.rev))}</td>`).join('')}
            <td class="num budget-grid__total">${esc(fmtMoney(year.rev))}</td></tr>
          <tr><td colspan="2">Planned expense</td>
            ${columnTotals.map(c => `<td class="num">${esc(fmtMoney(c.exp))}</td>`).join('')}
            <td class="num budget-grid__total">${esc(fmtMoney(year.exp))}</td></tr>
          <tr class="budget-grid__net"><td colspan="2">Planned net</td>
            ${columnTotals.map(c => `<td class="num ${moneyClass(c.net)}">${esc(fmtMoney(c.net))}</td>`).join('')}
            <td class="num ${moneyClass(year.rev - year.exp)}">${esc(fmtMoney(year.rev - year.exp))}</td></tr>
        </tfoot>
      </table></div>`;

    return card('Month by month', subtitle, body);
  }

  /* --------------------------------------------------- budgets: lifecycle */

  /**
   * Where each version of the year's plan stands, and the acts that move it.
   *
   * The acts offered are the ones the status allows — a draft is raised, a
   * plan waiting on a decision is decided, an agreed plan is closed and a
   * closed one reopened. Whether the caller actually holds the right is the
   * server's answer, and a refusal is shown as it arrives rather than being
   * hidden behind a missing button.
   */
  function budgetLifecycle() {
    const summaries = budgetState.summaries || [];

    if (!summaries.length) {
      return card('Lifecycle', 'Draft, raised, agreed to, closed',
        emptyState('\u25CB', 'No versions yet', 'Write the year\u2019s first plan to start its life.'));
    }

    const rows = summaries.map(s => {
      const open = budgetState.detail && budgetState.detail.ulid === s.ulid;
      const decision = s.decision_reason || s.reason;

      return `<tr${open ? ' style="background:var(--info-bg)"' : ''}>
        <td>v${esc(s.version_no)}${open ? ' ' + badge('info', 'On screen') : ''}</td>
        <td>${budgetStatusBadge(s.status)}</td>
        <td>${esc(s.name || '\u2014')}</td>
        <td>${decision ? esc(decision) : '\u2014'}</td>
        <td>${s.decided_at ? esc(fmtDateTime(s.decided_at)) : '\u2014'}</td>
        <td>${esc(fmtDateTime(s.updated_at))}</td>
        <td class="num">${budgetRowActions(s)}</td>
      </tr>`;
    }).join('');

    return card('Lifecycle', 'Each version\u2019s state, and the acts that move it',
      `<div class="note">A plan is drafted, raised for a decision, agreed to and closed. Once a version has been raised its amounts are frozen: a change to an agreed plan is written as the next version, which leaves the one that was agreed to untouched.</div>
       <div class="table-wrap" style="margin-top:12px"><table class="data">
        <thead><tr><th>Version</th><th>Status</th><th>Name</th><th>Reason / decision</th><th>Decided</th><th>Written</th><th class="num">Acts</th></tr></thead>
        <tbody>${rows}</tbody></table></div>`);
  }

  function budgetRowActions(s) {
    const buttons = [
      `<button class="btn btn--quiet btn--sm" data-act="budget-open" data-ulid="${esc(s.ulid)}">Open</button>`
    ];

    if (s.status === 'draft') {
      buttons.push(`<button class="btn btn--primary btn--sm" data-act="budget-submit" data-ulid="${esc(s.ulid)}">Raise for approval</button>`);
    }
    if (s.status === 'pending_approval') {
      buttons.push(`<button class="btn btn--primary btn--sm" data-act="budget-approve" data-ulid="${esc(s.ulid)}">Approve</button>`);
      buttons.push(`<button class="btn btn--quiet btn--sm" data-act="budget-reject" data-ulid="${esc(s.ulid)}">Send back</button>`);
    }
    if (s.status === 'approved') {
      buttons.push(`<button class="btn btn--quiet btn--sm" data-act="budget-lock" data-ulid="${esc(s.ulid)}">Close</button>`);
    }
    if (s.status === 'locked') {
      buttons.push(`<button class="btn btn--quiet btn--sm" data-act="budget-unlock" data-ulid="${esc(s.ulid)}">Reopen</button>`);
    }

    return buttons.join(' ');
  }

  /* -------------------------------------------- budgets: plan vs actual */

  /**
   * The plan the company wrote for one period, beside what the ledger posted.
   *
   * Every figure is read: the plan column is the live plan the version was
   * published into, and the actual column is the period's posted ledger, read
   * through the same profit and loss statement the reports use. The variance
   * is the actual less the plan, so a positive figure is above plan —
   * favourable on revenue and unfavourable on expense.
   */
  function budgetVariance(yearPeriods) {
    const periods = (yearPeriods || []).slice()
      .sort((a, b) => Number(a.period_no) - Number(b.period_no));

    if (!periods.length) {
      return card('Plan against actual', '', emptyState('\u25CB', 'No periods in this year',
        'The fiscal year holds no periods to compare over.'));
    }

    const tools = `<label class="field" style="min-width:230px"><span class="field__label">Fiscal period</span>
        <select data-act="budget-bva-period">${periods.map(p =>
          `<option value="${esc(p.ulid)}"${p.ulid === budgetState.bvaPeriod ? ' selected' : ''}>${esc(p.name)} \u00b7 ${esc(p.starts_on)}</option>`).join('')}</select></label>
      <button class="btn btn--quiet btn--sm" data-act="budget-bva-run">Read it</button>`;

    const title = 'Plan against actual';
    const subtitle = 'The plan the company wrote for a period, beside what the ledger actually posted';

    const bva = budgetState.bva;

    if (bva instanceof Error) {
      return card(title, subtitle, `<div class="note note--warn">${esc(bva.message)}</div>`, tools);
    }

    if (!bva) {
      return card(title, subtitle,
        emptyState('\u25CB', 'Nothing read yet', 'Choose a period and read the comparison.'), tools);
    }

    const revenue = (bva.revenue && bva.revenue.accounts) || [];
    const expense = (bva.expense && bva.expense.accounts) || [];

    if (!revenue.length && !expense.length) {
      return card(title, subtitle, emptyState('\u25CB', 'Nothing to compare',
        'Neither the plan nor the ledger holds a revenue or expense figure for this period.'), tools);
    }

    const totals = '<div class="grid grid--thirds">' +
      budgetVarianceTotal('Revenue', bva.revenue) +
      budgetVarianceTotal('Expense', bva.expense) +
      budgetVarianceTotal('Net', bva.net) +
      '</div>';

    const chart = (label, rows) => {
      if (!rows.length) {
        return card(label, '', emptyState('\u25CB', 'Nothing in this section',
          'Neither the plan nor the ledger holds a figure here.'));
      }

      const measures = [
        { label: 'Plan', kind: 'bar', values: rows.map(r => Number(r.budget)), color: 1 },
        { label: 'Actual', kind: 'bar', values: rows.map(r => Number(r.actual)), color: 2 }
      ];

      return card(label, 'Per account, the plan beside what was posted',
        chartSvg({ periods: rows.map(r => ({ name: r.code })) }, measures));
    };

    const rows = revenue.concat(expense).map(r => `<tr>
      <td class="budget-grid__code">${esc(r.code)}</td>
      <td>${esc(r.name)}</td>
      <td>${badge(r.account_type === 'revenue' ? 'info' : 'muted', r.account_type === 'revenue' ? 'Revenue' : 'Expense')}</td>
      <td class="num">${esc(fmtMoney(r.budget))}</td>
      <td class="num">${esc(fmtMoney(r.actual))}</td>
      <td class="num ${moneyClass(r.variance)}">${esc(fmtMoney(r.variance))}</td>
      <td class="num">${r.variance_percent === null || r.variance_percent === undefined
        ? '\u2014' : esc(Number(r.variance_percent).toFixed(2) + '%')}</td>
    </tr>`).join('');

    const body = totals +
      '<div class="grid grid--halves" style="margin-top:16px">' +
        chart('Revenue accounts', revenue) + chart('Expense accounts', expense) +
      '</div>' +
      `<div class="table-wrap" style="margin-top:16px"><table class="data">
        <thead><tr><th>Code</th><th>Account</th><th>Section</th><th class="num">Plan</th>
          <th class="num">Actual</th><th class="num">Variance</th><th class="num">%</th></tr></thead>
        <tbody>${rows}</tbody></table></div>` +
      (bva.note ? `<div class="note" style="margin-top:14px">${esc(bva.note)}</div>` : '');

    return card(title, subtitle, body, tools);
  }

  function budgetVarianceTotal(label, totals) {
    const t = totals || {};
    const percent = t.variance_percent === null || t.variance_percent === undefined
      ? '' : ` (${Number(t.variance_percent).toFixed(2)}%)`;

    return `<section class="card budget-bva__total">
      <span class="kpi__label">${esc(label)}</span>
      <div class="facts">
        <div class="fact"><span class="fact__k">Plan</span>
          <strong class="fact__v">${esc(fmtMoney(t.budget))}</strong></div>
        <div class="fact"><span class="fact__k">Actual</span>
          <strong class="fact__v">${esc(fmtMoney(t.actual))}</strong></div>
        <div class="fact"><span class="fact__k">Variance</span>
          <strong class="fact__v ${moneyClass(t.variance)}">${esc(fmtMoney(t.variance))}${esc(percent)}</strong></div>
      </div>
    </section>`;
  }

  /* ------------------------------------------------ budgets: the editor */

  /**
   * Open the plan editor for the year.
   *
   * What the editor does is decided by the newest version rather than by the
   * caller: a draft is edited in place, an agreed plan is superseded by the
   * next version and has to say why, and a plan that is waiting on a decision
   * or has been closed refuses a write outright. The refusal is explained here
   * as well as enforced by the server.
   */
  async function budgetOpenEditor() {
    const latest = budgetState.summaries[0] || null;

    if (latest && (latest.status === 'pending_approval' || latest.status === 'locked')) {
      toast('bad',
        latest.status === 'locked' ? 'The plan is closed' : 'The plan is waiting on a decision',
        latest.status === 'locked'
          ? 'Reopen the closed version before writing another.'
          : 'A version that has been raised cannot be changed; it has to be decided first.');
      return;
    }

    const list = await periods();
    const yearPeriods = periodsOfYear(list, budgetState.year).slice()
      .sort((a, b) => Number(a.period_no) - Number(b.period_no));
    const chart = await accounts();

    if (!yearPeriods.length) {
      toast('bad', 'No periods to plan over', 'The fiscal year holds no periods.');
      return;
    }

    const plannable = chart
      .filter(a => a.account_type === 'revenue' || a.account_type === 'expense')
      .sort((a, b) => String(a.code).localeCompare(String(b.code)));

    if (!plannable.length) {
      toast('bad', 'No plannable accounts', 'A plan is made of revenue and expense accounts, and the chart holds none.');
      return;
    }

    const editing = latest !== null && latest.status === 'draft';
    const superseding = latest !== null && latest.status === 'approved';
    const seed = editing ? budgetState.detail : null;

    const form = await budgetPlanModal({
      title: editing ? 'Edit draft version v' + latest.version_no
        : (superseding ? 'Write the next version of the plan' : 'Write the year\u2019s plan'),
      okLabel: editing ? 'Save the draft' : (superseding ? 'Save the new version' : 'Save the plan'),
      requireReason: superseding,
      seed: seed,
      periods: yearPeriods,
      accounts: plannable
    });

    if (!form) return;

    const body = {
      fiscal_year: budgetState.year,
      name: form.name,
      reason: form.reason,
      lines: form.lines
    };

    if (editing && seed) body.expected_row_version = seed.row_version;

    try {
      const payload = await api('/budgets/versions', { method: 'PUT', key: newKey(), body: body });
      const saved = (payload && payload.data) || {};
      budgetState.version = saved.ulid || '';
      toast('ok', editing ? 'The draft is saved' : 'The plan is saved',
        'Version ' + saved.version_no + ' \u00b7 it posts nothing to the ledger.');
      go('budgets');
    } catch (error) {
      toast('bad', error.message, 'The plan was not saved.');
    }
  }

  function budgetPlanModal(options) {
    const promise = openModal(options.title, budgetPlanBody(options), options.okLabel, 'primary');

    const ok = $('#modal-ok');
    const nameInput = $('#budget-plan-name');
    if (nameInput) nameInput.focus();

    ok.onclick = () => {
      const read = budgetReadPlanForm();
      const error = $('[data-error="budget-plan"]');
      if (read.error) {
        if (error) error.textContent = read.error;
        return;
      }
      if (error) error.textContent = '';
      ok.onclick = null;
      closeModal(read.value);
    };

    return promise;
  }

  function budgetPlanBody(options) {
    const seed = options.seed || {};
    const periods = options.periods || [];
    const accounts = options.accounts || [];

    // A version's lines are one per account, period and cost centre; the grid
    // has no cost-centre column, so the amounts are summed across them.
    const amounts = {};
    (seed.lines || []).forEach(line => {
      const key = Number(line.account_id) + ':' + Number(line.fiscal_period_id);
      amounts[key] = (amounts[key] || 0) + Number(line.amount);
    });

    const header = periods.map(p => `<th class="num">${esc(budgetPeriodLabel(p))}</th>`).join('');

    const rows = accounts.map(a => {
      const cells = periods.map(p => {
        const value = amounts[Number(a.id) + ':' + Number(p.id)];
        return `<td class="num"><input class="budget-input" inputmode="decimal"
          data-act="budget-cell" data-account="${esc(a.id)}" data-period="${esc(p.ulid)}"
          value="${value ? esc(budgetTrim(value)) : ''}" placeholder="0"
          aria-label="${esc(a.code + ' ' + a.name + ', ' + p.name)}"></td>`;
      }).join('');

      return `<tr><td class="budget-grid__code">${esc(a.code)}</td><td>${esc(a.name)}</td>${cells}</tr>`;
    }).join('');

    return `<label class="field"><span class="field__label">Plan name</span>
        <input id="budget-plan-name" value="${esc(seed.name || '')}" maxlength="255" placeholder="Annual plan"></label>
      ${options.requireReason ? `<label class="field" style="margin-top:12px"><span class="field__label">Why the plan is being changed</span>
        <textarea id="budget-plan-reason" rows="2" maxlength="500" placeholder="The board revised the revenue target for the year."></textarea></label>` : ''}
      <div class="note" style="margin-top:12px">Enter an amount in any month; leave a cell blank to plan nothing for it. Amounts are plain decimals, and a contra account is planned as a negative figure.</div>
      <div class="table-wrap" style="margin-top:10px;max-height:340px;overflow:auto"><table class="data budget-plan">
        <thead><tr><th>Code</th><th>Account</th>${header}</tr></thead>
        <tbody>${rows}</tbody></table></div>
      <em class="field__error" data-error="budget-plan"></em>`;
  }

  function budgetReadPlanForm() {
    const inputs = $$('#modal-body [data-act="budget-cell"]');
    const lines = [];
    let bad = '';

    inputs.forEach(input => {
      const raw = String(input.value || '').trim();
      if (raw === '') return;

      if (!/^-?\d+(\.\d+)?$/.test(raw)) {
        bad = bad || 'Every amount must be a plain decimal \u2014 try 12500 or -500.';
        return;
      }

      if (Number(raw) === 0) return;

      lines.push({
        fiscal_period: input.dataset.period,
        account_id: Number(input.dataset.account),
        amount: raw
      });
    });

    if (bad) return { error: bad };
    if (!lines.length) return { error: 'A plan needs at least one amount. Enter a figure in any month.' };

    const reasonInput = $('#budget-plan-reason');
    const name = (($('#budget-plan-name') || {}).value || '').trim();
    const reason = reasonInput ? reasonInput.value.trim() : '';

    if (reasonInput && reason.length < 3) {
      return { error: 'A change to an agreed plan has to say why \u2014 at least three characters.' };
    }

    return { value: { name: name || null, reason: reason || null, lines: lines } };
  }

  /* --------------------------------------------- budgets: the acts */

  async function budgetSubmit(ulid) {
    await budgetMove(ulid, 'submit', 'Plan raised for approval',
      'It now waits in the approvals queue for a member other than the one who wrote it.');
  }

  async function budgetApprove(ulid) {
    await budgetMove(ulid, 'approve', 'Plan approved',
      'It is now the version the company means to follow.');
  }

  async function budgetLockVersion(ulid) {
    await budgetMove(ulid, 'lock', 'Plan closed',
      'Nothing more may be raised against it until it is reopened.');
  }

  async function budgetUnlockVersion(ulid) {
    await budgetMove(ulid, 'unlock', 'Plan reopened',
      'It returns to Approved, and the next version may be started from it.');
  }

  async function budgetReject(ulid) {
    const reason = await promptModal({
      title: 'Send the plan back',
      label: 'Why it is not accepted',
      okLabel: 'Send it back',
      minLength: 3,
      hint: 'The plan returns to Draft with this reason, and its author can correct it.'
    });

    if (!reason) return;

    await budgetMove(ulid, 'reject', 'Plan sent back',
      'It is a draft again, with the reason kept on the version.', { reason: reason });
  }

  /**
   * One move, one idempotency key, one round trip. The key travels with the
   * request so a double click decides the plan once, and the screen is re-read
   * afterwards rather than redrawn from what it held before.
   */
  async function budgetMove(ulid, action, okText, okDetail, body) {
    try {
      await api('/budgets/versions/' + encodeURIComponent(ulid) + '/' + action, {
        method: 'POST',
        key: newKey(),
        body: body || {}
      });
      toast('ok', okText, okDetail);
      go('budgets');
    } catch (error) {
      toast('bad', error.message, 'The plan was not moved.');
    }
  }

  /* ----------------------------------------------------- screen: reports */

  // The catalogue the hub is built from. Every key is both the path the API
  // serves (/api/v1/reports/<key>) and the last segment of the report's own
  // address (/financial-manager/reports/<key>), so a card, a link and an
  // endpoint are three views of one name. `params` is what the report needs
  // asked of it; `export` is the type the export registry knows it by.
  const REPORT_CATEGORIES = [
    { key: 'statements', label: 'Financial statements', note: 'The statements a set of books is judged by' },
    { key: 'ledgers', label: 'Ledgers and analysis', note: 'The detail a statement is summarised from' },
    { key: 'operational', label: 'Operational and management', note: 'Stock, plans, cash and reconciliations' }
  ];

  const REPORT_DEFS = [
    { key: 'profit-and-loss', label: 'Profit and loss', category: 'statements', glyph: '\u25A4',
      description: 'Revenue and expenses over a period, and the net result they leave.',
      params: ['from', 'to', 'compare'], export: 'profit_and_loss' },
    { key: 'balance-sheet', label: 'Balance sheet', category: 'statements', glyph: '\u2696',
      description: 'What the company owns, what it owes, and what it is worth at a date.',
      params: ['as_of', 'compare'], export: 'balance_sheet' },
    { key: 'cash-flow', label: 'Cash flow (direct)', category: 'statements', glyph: '\u21C4',
      description: 'Money in and money out, read from the cash accounts themselves.',
      params: ['from', 'to'] },
    { key: 'cash-flow-indirect', label: 'Cash flow (indirect)', category: 'statements', glyph: '\u21C6',
      description: 'The same movement, walked back from profit to the change in cash.',
      params: ['from', 'to'] },
    { key: 'trial-balance', label: 'Trial balance', category: 'statements', glyph: '\u2261',
      description: 'Every account with its debits and credits, and proof the two sides agree.',
      params: ['from', 'to', 'compare'], export: 'trial_balance' },

    { key: 'general-ledger', label: 'General ledger', category: 'ledgers', glyph: '\u25A6',
      description: 'Every posted line to one account, with its running balance.',
      params: ['account_id', 'from', 'to'] },
    { key: 'receivables-ageing', label: 'Receivables ageing', category: 'ledgers', glyph: '\u23F3',
      description: 'Who owes what, bucketed by how late each balance is.',
      params: ['as_of'] },
    { key: 'payables-ageing', label: 'Payables ageing', category: 'ledgers', glyph: '\u23F3',
      description: 'What is owed to whom, bucketed by how late each balance is.',
      params: ['as_of'] },

    { key: 'stock-valuation', label: 'Stock valuation', category: 'operational', glyph: '\u25C8',
      description: 'What the stock on hand is worth, priced from the stock layers.',
      params: ['as_of'] },
    { key: 'budget-vs-actual', label: 'Budget versus actual', category: 'operational', glyph: '\u25D1',
      description: 'The plan the company wrote beside the ledger it actually posted.',
      params: ['fiscal_period', 'compare'] },
    { key: 'cash-position', label: 'Cash position', category: 'operational', glyph: '\u25C9',
      description: 'Every cash and bank account, and what it holds, as at a date.',
      params: ['as_of'] },
    // Named and described, but without a viewer: the reconciliation summary is
    // scheduled for the gate that reads the existing reconciliation records.
    // It carries no button and no figures, because a card that opens nothing
    // would be a promise the module does not yet keep.
    { key: 'bank-reconciliation', label: 'Bank reconciliation summary', category: 'operational', glyph: '\u21D4',
      description: 'Bank statements matched to the ledger, reconciled against unreconciled.',
      deferred: true }
  ];

  const reportDef = key => REPORT_DEFS.find(d => d.key === key) || null;
  const categoryLabel = key => (REPORT_CATEGORIES.find(c => c.key === key) || {}).label || '';

  /* ------------------------------------------ reports: what this browser
     remembers

     Starred reports, what was opened here and the filters left behind are kept
     per company AND per person, so a change of company or of the person at the
     keyboard does not carry one reader's habits into another's. Only names and
     filter values are kept — never a token, never a figure. Every read is
     defensive: a missing, half-written or hand-edited entry reads as no entry
     rather than as an error. */

  const REPORT_STORE_PREFIX = 'nexas.fm.reports.';

  function reportStoreId() {
    const userId = S.user && S.user.id;
    if (!S.companyId || !userId) return null;
    return REPORT_STORE_PREFIX + S.companyId + '.' + userId;
  }

  function blankStore() {
    return { favourites: [], recent: [], presets: {} };
  }

  function reportStoreRead() {
    const id = reportStoreId();
    if (!id) return blankStore();

    let raw = null;
    try { raw = localStorage.getItem(id); } catch (e) { return blankStore(); }
    if (!raw) return blankStore();

    let parsed = null;
    try { parsed = JSON.parse(raw); } catch (e) { return blankStore(); }
    if (!parsed || typeof parsed !== 'object') return blankStore();

    const favourites = Array.isArray(parsed.favourites)
      ? parsed.favourites.filter(k => typeof k === 'string' && reportDef(k))
      : [];

    const recent = Array.isArray(parsed.recent)
      ? parsed.recent.filter(r => r && typeof r.key === 'string' && reportDef(r.key) && !reportDef(r.key).deferred)
      : [];

    const presets = {};
    if (parsed.presets && typeof parsed.presets === 'object') {
      Object.keys(parsed.presets).forEach(k => {
        const def = reportDef(k);
        const value = parsed.presets[k];
        if (def && !def.deferred && value && typeof value === 'object') presets[k] = value;
      });
    }

    return { favourites: favourites, recent: recent, presets: presets };
  }

  function reportStoreWrite(store) {
    const id = reportStoreId();
    if (!id) return;
    try { localStorage.setItem(id, JSON.stringify(store)); } catch (e) { /* storage is a convenience, not a promise */ }
  }

  function reportStoreClear() {
    try {
      const doomed = [];
      for (let i = 0; i < localStorage.length; i++) {
        const key = localStorage.key(i);
        if (key && key.indexOf(REPORT_STORE_PREFIX) === 0) doomed.push(key);
      }
      doomed.forEach(key => localStorage.removeItem(key));
    } catch (e) { /* ignore */ }
  }

  const reportIsFavourite = (store, key) => store.favourites.indexOf(key) >= 0;

  function reportLastOpened(store, key) {
    const entry = store.recent.find(r => r.key === key);
    return entry ? entry.at : '';
  }

  function reportToggleFavourite(key) {
    const store = reportStoreRead();
    store.favourites = reportIsFavourite(store, key)
      ? store.favourites.filter(k => k !== key)
      : [key].concat(store.favourites);
    reportStoreWrite(store);
  }

  // Opening a report is what records it. The list is most-recent-first and
  // bounded, because it is a convenience and not a history.
  function reportMarkOpened(key) {
    const store = reportStoreRead();
    store.recent = [{ key: key, at: new Date().toISOString() }]
      .concat(store.recent.filter(r => r.key !== key))
      .slice(0, 8);
    reportStoreWrite(store);
  }

  function reportSaveFilters(key, params) {
    const store = reportStoreRead();
    store.presets[key] = Object.assign({}, params);
    reportStoreWrite(store);
  }

  function reportReadFilters(key) {
    return reportStoreRead().presets[key] || null;
  }

  function reportForgetFilters(key) {
    const store = reportStoreRead();
    delete store.presets[key];
    reportStoreWrite(store);
  }

  // What the hub is filtered by. It lives outside the DOM so a redraw — a
  // star, a category — does not lose the word that was typed in the search box.
  const hubState = { query: '', category: 'all', favouritesOnly: false };

  // The open report: which one, the filters it was asked with, and the html it
  // last answered with.
  const reportState = { key: '', params: {}, html: '', seeded: false };

  /**
   * The Reports section is two screens behind one address. With no report named
   * it is the hub; with one named it is that report, and the renderer below is
   * the one the section has always used.
   */
  async function drawReports(container) {
    const def = S.report ? reportDef(S.report) : null;
    if (def && !def.deferred) return drawReportViewer(container, def);
    drawReportHub(container);
  }

  /* ---------------------------------------------------------- reports: hub */

  /**
   * Opening a report gives it its own address, so a reload, a bookmark or a
   * shared link lands on the report rather than on the hub. A name the hub does
   * not carry — a report the API named before the catalogue knew it, say —
   * lands on the hub rather than on a link that does nothing.
   */
  function openReport(key, opts) {
    const def = reportDef(key);
    if (!def || def.deferred) { go('reports'); return; }
    const incoming = (opts && opts.params) || null;
    if (reportState.key !== key || incoming) {
      reportState.params = incoming ? Object.assign({}, incoming) : {};
      reportState.html = '';
      // A drill-down arrives carrying the window it was opened from. That
      // window is the report's subject rather than a leftover to be cleared
      // when the report changes, so it is flagged and the viewer keeps it.
      reportState.seeded = Boolean(incoming);
    }
    go('reports', { report: key });
  }

  function drawReportHub(container) {
    // The hub is where a report is chosen from, so whatever was read last is
    // let go: returning to a report re-reads the ledger rather than showing a
    // figure that may since have moved.
    reportState.key = '';
    reportState.params = {};
    reportState.html = '';
    container.innerHTML = hubHead() + '<div id="report-hub">' + hubBody() + '</div>';
    filterHub();
  }

  function hubHead() {
    const cats = [{ key: 'all', label: 'All' }].concat(REPORT_CATEGORIES);
    const segs = cats.map(c =>
      `<button class="seg__btn${hubState.category === c.key ? ' is-active' : ''}" data-act="hub-category"
        data-category="${esc(c.key)}" aria-pressed="${hubState.category === c.key ? 'true' : 'false'}">${esc(c.label)}</button>`
    ).join('');

    return `<section class="card report-hub__head">
      <div class="card__head">
        <div><h3>Reports</h3><p>Every statement, ageing report and the ledger behind them</p></div>
        <div class="spacer">
          <label class="field report-hub__search">
            <span class="field__label">Search reports</span>
            <input type="search" data-act="hub-search" value="${esc(hubState.query)}"
              placeholder="Search by name or description" autocomplete="off" spellcheck="false">
          </label>
        </div>
      </div>
      <div class="toolbar report-hub__filters">
        <div class="seg" role="group" aria-label="Report category">${segs}</div>
        <button class="btn btn--quiet btn--sm${hubState.favouritesOnly ? ' is-active' : ''}" data-act="hub-favourites"
          aria-pressed="${hubState.favouritesOnly ? 'true' : 'false'}">
          <span aria-hidden="true">\u2605</span> Favourites only</button>
      </div>
    </section>`;
  }

  function hubBody() {
    const store = reportStoreRead();
    const favourites = store.favourites.filter(k => { const d = reportDef(k); return d && !d.deferred; });
    const recent = store.recent.slice(0, 6);
    const presetKeys = Object.keys(store.presets).filter(k => { const d = reportDef(k); return d && !d.deferred; });

    const blocks = [];

    if (favourites.length) {
      blocks.push(hubSection('favourites', 'Favourites', 'The reports you starred',
        favourites.map(k => reportCard(reportDef(k), store)).join('')));
    }
    if (recent.length) {
      blocks.push(hubSection('recent', 'Opened here recently', 'This browser only, most recent first',
        recent.map(r => reportRecentRow(reportDef(r.key), store, r.at)).join('')));
    }
    if (presetKeys.length) {
      blocks.push(hubSection('saved', 'Saved filters', 'The filters you left each report with',
        presetKeys.map(k => reportSavedRow(reportDef(k), store.presets[k], store)).join('')));
    }

    REPORT_CATEGORIES.forEach(cat => {
      blocks.push(hubSection(cat.key, cat.label, cat.note,
        REPORT_DEFS.filter(d => d.category === cat.key).map(d => reportCard(d, store)).join('')));
    });

    blocks.push(`<div id="hub-empty" hidden>${emptyState('\u2315', 'No reports match',
      'Nothing matched that search. Try another word, or clear the filter.')}</div>`);

    return blocks.join('');
  }

  function hubSection(kind, title, note, inner) {
    return `<section class="report-section" data-hub-section="${esc(kind)}">
      <div class="report-section__head"><h4>${esc(title)}</h4><p>${esc(note)}</p></div>
      <div class="report-cards">${inner}</div>
    </section>`;
  }

  // What a card is searched by: its name, its description and its category.
  const searchText = def => (def.label + ' ' + def.description + ' ' + categoryLabel(def.category)).toLowerCase();

  function reportCard(def, store) {
    const fav = reportIsFavourite(store, def.key);
    const last = reportLastOpened(store, def.key);
    const saved = Boolean(store.presets[def.key]);

    const action = def.deferred
      ? `<button class="btn btn--quiet btn--sm" disabled
           title="The reconciliation summary is scheduled for Gate 2">Coming in Gate 2</button>`
      : `<button class="btn btn--primary btn--sm" data-act="open-report" data-report="${esc(def.key)}">Open report</button>`;

    return `<article class="report-card${def.deferred ? ' is-deferred' : ''}"
      data-hub-card="${esc(def.key)}" data-hub-cat="${esc(def.category)}"
      data-hub-fav="${fav ? '1' : '0'}" data-hub-text="${esc(searchText(def))}">
      <div class="report-card__head">
        <span class="report-card__glyph" aria-hidden="true">${def.glyph}</span>
        <button class="report-card__star${fav ? ' is-on' : ''}" data-act="hub-fav" data-key="${esc(def.key)}"
          aria-pressed="${fav ? 'true' : 'false'}"
          aria-label="${fav ? 'Remove from favourites' : 'Add to favourites'}: ${esc(def.label)}">${fav ? '\u2605' : '\u2606'}</button>
      </div>
      <h4 class="report-card__title">${esc(def.label)}</h4>
      <p class="report-card__desc">${esc(def.description)}</p>
      <div class="report-card__meta">
        <span class="badge ${def.deferred ? 'badge--pending' : 'badge--muted'}">${esc(def.deferred ? 'Gate 2' : categoryLabel(def.category))}</span>
        <span class="report-card__last">${last ? 'Last opened here \u00b7 ' + esc(fmtDateTime(last)) : 'Not opened here yet'}</span>
      </div>
      <div class="report-card__foot">
        ${action}
        ${saved ? `<button class="btn btn--quiet btn--sm" data-act="hub-forget" data-key="${esc(def.key)}">Forget filters</button>` : ''}
      </div>
    </article>`;
  }

  function reportRecentRow(def, store, at) {
    if (!def) return '';
    return `<article class="report-card is-compact" data-hub-card="${esc(def.key)}"
      data-hub-cat="${esc(def.category)}" data-hub-fav="${reportIsFavourite(store, def.key) ? '1' : '0'}"
      data-hub-text="${esc(searchText(def))}">
      <div class="report-card__row">
        <span class="report-card__glyph" aria-hidden="true">${def.glyph}</span>
        <div class="report-card__rowmain">
          <strong>${esc(def.label)}</strong>
          <small>Last opened here \u00b7 ${esc(fmtDateTime(at))}</small>
        </div>
        <button class="btn btn--quiet btn--sm" data-act="open-report" data-report="${esc(def.key)}">Open</button>
      </div>
    </article>`;
  }

  function reportSavedRow(def, params, store) {
    if (!def) return '';
    return `<article class="report-card is-compact" data-hub-card="${esc(def.key)}"
      data-hub-cat="${esc(def.category)}" data-hub-fav="${reportIsFavourite(store, def.key) ? '1' : '0'}"
      data-hub-text="${esc(searchText(def))}">
      <div class="report-card__row">
        <span class="report-card__glyph" aria-hidden="true">${def.glyph}</span>
        <div class="report-card__rowmain">
          <strong>${esc(def.label)}</strong>
          <small>${filterSummary(params)}</small>
        </div>
        <button class="btn btn--quiet btn--sm" data-act="open-report" data-report="${esc(def.key)}">Resume</button>
        <button class="btn btn--quiet btn--sm" data-act="hub-forget" data-key="${esc(def.key)}">Forget</button>
      </div>
    </article>`;
  }

  function filterSummary(params) {
    const names = { from: 'From', to: 'To', as_of: 'As at', account_id: 'Account', fiscal_period: 'Period', compare: 'Comparison' };
    const parts = [];
    Object.keys(params || {}).forEach(k => {
      const value = params[k];
      if (value === undefined || value === null || value === '' || value === false) return;
      const shown = value === true ? 'on' : String(value);
      parts.push((names[k] || k) + ' ' + shown);
    });
    return parts.length ? esc(parts.join(' \u00b7 ')) : 'No filters saved';
  }

  /**
   * The three filters — a search word, a category and a favourites-only view —
   * narrow the same set of cards. A card carries what it can be narrowed by in
   * its own data attributes, so filtering never re-reads anything.
   */
  function filterHub() {
    const hub = $('#report-hub');
    if (!hub) return;

    const query = hubState.query.trim().toLowerCase();
    const favouriteHunting = hubState.favouritesOnly;
    const category = hubState.category;

    // The standing sections only speak for the whole catalogue, so a search or
    // a chosen category steps over them rather than leaving them contradicting
    // the cards beside them.
    const standing = !query && category === 'all';
    const modes = {
      favourites: standing,
      recent: standing && !favouriteHunting,
      saved: standing && !favouriteHunting
    };
    REPORT_CATEGORIES.forEach(c => { modes[c.key] = !favouriteHunting && (category === 'all' || category === c.key); });

    let visible = 0;

    $$('[data-hub-section]', hub).forEach(section => {
      const on = modes[section.dataset.hubSection] !== false;
      section.hidden = !on;

      $$('[data-hub-card]', section).forEach(card => {
        let show = on;
        if (show && query && card.dataset.hubText.indexOf(query) === -1) show = false;
        if (show && favouriteHunting && card.dataset.hubFav !== '1') show = false;
        card.hidden = !show;
        if (show) visible++;
      });
    });

    const empty = $('#hub-empty');
    if (empty) empty.hidden = visible > 0;
  }

  // Starring a report, choosing a category or switching the favourites view
  // redraws the hub from the store. The search box is left alone, so a word
  // half-typed is never thrown away.
  function redrawHub() {
    if (S.screen !== 'reports' || S.report) return;
    const head = $('.report-hub__head');
    const hub = $('#report-hub');
    if (!head || !hub) return;
    head.outerHTML = hubHead();
    hub.innerHTML = hubBody();
    filterHub();
  }

  /* ------------------------------------------------------- reports: viewer */

  /**
   * One report, drawn through the renderer the section has always used. Its
   * address names it, the breadcrumb and the page title follow, and its filters
   * are the ones the reader left it with last time.
   */
  async function drawReportViewer(container, def) {
    // A drill-down arrives with the window it was opened from already in hand;
    // that window is the report's subject, so it is not cleared the way a
    // leftover from another report would be.
    if (reportState.key !== def.key && !reportState.seeded) { reportState.params = {}; reportState.html = ''; }
    reportState.key = def.key;
    reportState.seeded = false;

    // Only the fields this report actually asks about are restored, so a saved
    // filter for another report can never leak into this one. A value already
    // in hand is left alone: what the reader clicked through from outranks what
    // they last left here.
    const saved = reportReadFilters(def.key);
    if (saved) {
      Object.keys(saved).forEach(k => {
        if (def.params.indexOf(k) >= 0 && reportState.params[k] === undefined) reportState.params[k] = saved[k];
      });
    }

    setStatus('Reading ' + def.label.toLowerCase());
    const toolbar = await buildReportToolbar(def);
    const output = reportState.html || emptyState('\u25CB', 'Nothing read yet', 'Run the report to read it.');

    container.innerHTML =
      reportViewerBar(def) +
      card(def.label, def.description, toolbar) +
      `<div id="report-tie" style="margin-top:16px"></div>` +
      `<div id="report-output" style="margin-top:16px">${output}</div>`;

    wireReportToolbar(def);
    // The tie-out stands beside the figures rather than inside them: it is a
    // claim about the company's ledger, read again whenever the report is.
    if (reportState.html) runTieOut(); else runReport(def);
  }

  function reportViewerBar(def) {
    return `<div class="report-viewer__bar">
      <button class="btn btn--quiet btn--sm" data-act="report-back">\u2190 All reports</button>
      <span class="badge badge--muted">${esc(categoryLabel(def.category))}</span>
      <span class="report-viewer__addr" aria-hidden="true">/financial-manager/reports/${esc(def.key)}</span>
    </div>`;
  }

  async function buildReportToolbar(def) {
    const list = await periods();
    const chart = def.params.indexOf('account_id') >= 0 ? await accounts() : [];
    const base = { from: yearStart(), to: today(), as_of: today(), compare: false };
    def.params.forEach(p => { if (reportState.params[p] === undefined) reportState.params[p] = base[p]; });

    // A sensible first pick so the toolbar is never empty of a required value.
    // A period or an account remembered from an earlier visit may since have
    // been closed or removed, so a value the list no longer carries falls back
    // to a real one rather than being sent and refused.
    if (def.params.indexOf('fiscal_period') >= 0) {
      const known = list.some(p => p.ulid === reportState.params.fiscal_period);
      if (!known) {
        const current = currentPeriod(list);
        reportState.params.fiscal_period = current ? current.ulid : (list[0] && list[0].ulid) || '';
      }
    }
    if (def.params.indexOf('account_id') >= 0) {
      const postable = chart.filter(a => a.is_postable);
      const known = postable.some(a => Number(a.id) === Number(reportState.params.account_id));
      if (!known) reportState.params.account_id = (postable[0] || chart[0] || {}).id || '';
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
          ${REPORT_DEFS.filter(d => !d.deferred).map(d => `<option value="${d.key}"${d.key === def.key ? ' selected' : ''}>${esc(d.label)}</option>`).join('')}
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

    // Reading the report and reading whether it ties to the ledger are two
    // questions about the same moment, so both are asked now.
    runTieOut();
    out.innerHTML = skeleton(6);
    try {
      const payload = await api('/reports/' + def.key + '?' + reportQuery(def));
      reportState.html = renderReport(def.key, payload);
      out.innerHTML = reportState.html;
      // The report answered, so this is what "last opened here" means, and
      // these are the filters worth finding again the next time it is opened.
      reportMarkOpened(def.key);
      reportSaveFilters(def.key, reportState.params);
      setStatus('Ready');
    } catch (error) {
      reportState.html = errorState(error);
      out.innerHTML = reportState.html;
      setStatus('Ready');
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

  /**
   * An account name that opens that account's ledger over the window the
   * figure came from. The window travels with the click, so the ledger that
   * opens is the same span as the row it was clicked from; without it the
   * drill would quietly show a different period from the figure above it.
   * A row with no account behind it, or a statement with no window, stays
   * plain text rather than a link that would open the wrong books.
   */
  function accountDrill(row, window) {
    const name = esc(row.name);
    const from = (window && window.from) || '';
    const to = (window && window.to) || '';

    if (row.account_id === undefined || row.account_id === null || (!from && !to)) return name;

    return `<button class="drill" data-act="drill-account" data-account-id="${esc(row.account_id)}"` +
      ` data-from="${esc(from)}" data-to="${esc(to)}"` +
      ` title="Open this account's general ledger for the same period">${name}</button>`;
  }

  function accountRowsTable(block, label, totalLabel, window) {
    const rows = (block && block.accounts) || [];
    return rowsTable(label, [
      { label: 'Code', render: r => esc(r.code) },
      { label: 'Account', render: r => accountDrill(r, window) },
      { label: 'Amount', num: true, render: r => fmtMoney(r.amount) }
    ], rows, {
      0: '', 1: totalLabel, 2: fmtMoney(block && block.total)
    });
  }

  function comparisonNote(report) {
    if (!report || !report.comparison || !report.variance) return '';
    return `<div class="note" style="margin-top:12px">A prior-period column was asked for and is included in the payload below.</div>`;
  }

  /* --------------------------------------------------- reports: the tie-out */

  /**
   * The three answers the ledger tie-out can give.
   *
   * The middle one is the trap. The endpoint answers `consistent: true` when
   * nothing contradicted the ledger — and nothing contradicts it when there is
   * nothing to compare, because no period has been snapshotted yet. A check
   * that compared nothing is not a pass, so the number of cached balances read
   * back is asked first, and a run that compared nothing is reported as not
   * checked whatever `consistent` says.
   */
  function tieOutState(verification) {
    const readBack = Number((verification && verification.snapshots_checked) || 0);

    if (!(readBack > 0)) return 'not-checked';

    return verification.consistent === true ? 'verified' : 'mismatch';
  }

  /**
   * Money read as an integer at the ledger's own scale, so the difference
   * between two four-decimal figures is exact rather than a floating-point
   * near miss. Only the difference is computed this way; the figures
   * themselves are shown as the ledger gave them.
   */
  function moneyUnits(value) {
    const text = String(value === null || value === undefined ? '0' : value).trim();
    const match = /^(-?)(\d*)(?:\.(\d*))?$/.exec(text);

    if (!match) return null;

    const scale = 10000;
    const fraction = (match[3] || '').padEnd(4, '0').slice(0, 4);
    const units = (Number(match[2] || '0') * scale) + Number(fraction);

    return (match[1] === '-' ? -1 : 1) * units;
  }

  function moneyFromUnits(units) {
    const scale = 10000;
    const abs = Math.abs(units);

    return (units < 0 ? '-' : '') + Math.floor(abs / scale) + '.' + String(abs % scale).padStart(4, '0');
  }

  /**
   * What the check found, in money: the ledger's figure less the cached one,
   * totalled over the divergences that are money. `missing_from_ledger` says a
   * cached row has no ledger behind it at all, which is a presence problem
   * rather than a difference, so it is counted but not added.
   */
  function tieOutDifference(divergences) {
    let units = 0;
    let counted = 0;

    (divergences || []).forEach(d => {
      if (d.check === 'missing_from_ledger') return;

      const ledger = moneyUnits(d.ledger);
      const cached = moneyUnits(d.snapshot);

      if (ledger === null || cached === null) return;

      units += ledger - cached;
      counted++;
    });

    return counted ? { difference: moneyFromUnits(units), counted: counted } : null;
  }

  const TIE_OUT_CHECKS = {
    opening_debit: 'Opening debit',
    opening_credit: 'Opening credit',
    period_debit: 'Period debit',
    period_credit: 'Period credit',
    closing_debit: 'Closing debit',
    closing_credit: 'Closing credit',
    closing_net: 'Closing balance, read from the ledger',
    missing_from_ledger: 'No ledger behind the cached row'
  };

  /**
   * The badge, and the sentence that keeps it honest. It is grey when nothing
   * was checked, red and itemised when the two disagree, and green only when
   * balances were actually read back and agreed.
   */
  function tieOutPanel(verification, failure) {
    const readAt = esc(fmtDateTime(new Date().toISOString()));

    if (failure) {
      return `<div class="tie tie--unread" data-tie-state="unread">
        <span class="tie__label">Ties to ledger</span>
        ${badge('muted', 'Could not be read')}
        <p class="tie__text">The check did not answer, so nothing is claimed here about the ledger
          and its cached balances.</p>
      </div>`;
    }

    const state = tieOutState(verification);
    const divergences = verification.divergences || [];

    if (state === 'not-checked') {
      return `<div class="tie tie--unchecked" data-tie-state="not-checked">
        <span class="tie__label">Ties to ledger</span>
        ${badge('muted', 'Not checked')}
        <p class="tie__text">Nothing checked yet: the check reads the ledger's cached balances back
          and compares them with the ledger itself, and this company has none cached, so there was
          nothing to compare. This is not a pass.</p>
        <span class="tie__when">Read ${readAt}</span>
      </div>`;
    }

    if (state === 'verified') {
      const rows = number.format(Number(verification.snapshots_checked || 0));
      const periods = number.format(Number(verification.periods_checked || 0));

      return `<div class="tie tie--verified" data-tie-state="verified">
        <span class="tie__label">Ties to ledger</span>
        ${badge('ok', 'Verified')}
        <p class="tie__text">${rows} cached balance${rows === '1' ? '' : 's'} across ${periods}
          fiscal period${periods === '1' ? '' : 's'} were read back and every one agrees with the
          posted ledger.</p>
        <span class="tie__when">Read ${readAt}</span>
      </div>`;
    }

    const difference = tieOutDifference(divergences);
    const shown = divergences.slice(0, 12);

    return `<div class="tie tie--mismatch" data-tie-state="mismatch">
      <span class="tie__label">Ties to ledger</span>
      ${badge('bad', 'Mismatch')}
      <p class="tie__text">The cached balances and the posted ledger disagree on
        ${number.format(divergences.length)} figure${divergences.length === 1 ? '' : 's'}${difference
          ? `, a net difference of <strong>${esc(fmtMoney(difference.difference))}</strong> ${esc(CURRENCY)}` : ''}.
        The report below is read from the posted ledger; the cached figure is the wrong one, and
        rebuilding the snapshot is what repairs it.</p>
      <span class="tie__when">Read ${readAt}</span>
      <div class="tie__wrap"><table class="data">
        <thead><tr><th>Fiscal period</th><th>Account</th><th>Figure</th>
          <th class="num">Cached</th><th class="num">Ledger</th><th class="num">Difference</th></tr></thead>
        <tbody>${shown.map(d => {
          const presence = d.check === 'missing_from_ledger';
          const ledger = moneyUnits(d.ledger);
          const cached = moneyUnits(d.snapshot);
          const diff = !presence && ledger !== null && cached !== null ? moneyFromUnits(ledger - cached) : '';

          return `<tr><td>${esc(d.period || '')}</td><td>#${esc(d.account_id)}</td>
            <td>${esc(TIE_OUT_CHECKS[d.check] || d.check)}</td>
            <td class="num">${presence ? '\u2014' : fmtMoney(d.snapshot)}</td>
            <td class="num">${presence ? '\u2014' : fmtMoney(d.ledger)}</td>
            <td class="num ${diff ? moneyClass(diff) : ''}">${diff ? fmtMoney(diff) : '\u2014'}</td></tr>`;
        }).join('')}</tbody>
      </table></div>
      ${divergences.length > shown.length
        ? `<p class="tie__text">Showing the first ${shown.length} of ${number.format(divergences.length)}.</p>`
        : ''}
    </div>`;
  }

  /**
   * The tie-out is a claim about the company's ledger and its cached balances
   * as a whole rather than about one report's rows, so it is read from the
   * endpoint the nightly check uses and shown beside the report rather than
   * inside it. It is read whenever the report is drawn or run, so the claim and
   * the figures beside it are as fresh as each other.
   */
  async function runTieOut() {
    const slot = $('#report-tie');
    if (!slot) return;

    slot.innerHTML = `<div class="tie tie--reading"><span class="tie__label">Ties to ledger</span>` +
      `${badge('muted', 'Checking\u2026')}</div>`;

    try {
      slot.innerHTML = tieOutPanel(await api('/reports/ledger-verification'), null);
    } catch (error) {
      if (error && error.status === 401) return;
      slot.innerHTML = tieOutPanel(null, error);
    }
  }

  function renderReport(key, report) {
    if (key === 'profit-and-loss') {
      const window = { from: report.from, to: report.to };
      return card('Profit and loss', `${esc(report.from || 'beginning')} to ${esc(report.to || 'now')}`,
        accountRowsTable(report.revenue, 'Revenue', 'Total revenue', window) + '<div style="height:14px"></div>' +
        accountRowsTable(report.expense, 'Expense', 'Total expense', window) +
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
          { label: 'Account', render: r => accountDrill(r, { from: meta.from, to: meta.to }) },
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
        <textarea id="modal-reason" rows="${options.rows || 4}" maxlength="${options.maxLength || 500}"
          placeholder="${esc(options.placeholder || 'Say why, in a sentence somebody else could act on.')}">${esc(options.value || '')}</textarea></label>
       <em class="field__error" data-error="reason"></em>
       <p>${esc(options.hint || '')}</p>`;
    const promise = openModal(options.title, body, options.okLabel, 'primary');

    const ok = $('#modal-ok');
    const textarea = $('#modal-reason');
    textarea.focus();
    textarea.select();

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
      if (act === 'run-report') { runReport(reportDef(reportState.key)); return; }
      if (act === 'export') { startExport(); return; }

      // The reports hub. Starring and filtering redraw from what the browser
      // holds, so none of them costs a round trip; opening a report is what
      // gives the report its own address.
      if (act === 'report-back') { go('reports'); return; }
      if (act === 'hub-category') {
        hubState.category = trigger.dataset.category || 'all';
        hubState.favouritesOnly = false;
        redrawHub();
        return;
      }
      if (act === 'hub-favourites') {
        hubState.favouritesOnly = !hubState.favouritesOnly;
        if (hubState.favouritesOnly) hubState.category = 'all';
        redrawHub();
        return;
      }
      if (act === 'hub-fav') { reportToggleFavourite(trigger.dataset.key); redrawHub(); return; }
      if (act === 'hub-forget') { reportForgetFilters(trigger.dataset.key); redrawHub(); return; }
      if (act === 'budget-refresh') { go('budgets'); return; }
      if (act === 'budget-new') { budgetOpenEditor(); return; }
      if (act === 'budget-open') {
        budgetState.version = trigger.dataset.ulid;
        go('budgets');
        return;
      }
      if (act === 'budget-submit') { budgetSubmit(trigger.dataset.ulid); return; }
      if (act === 'budget-approve') { budgetApprove(trigger.dataset.ulid); return; }
      if (act === 'budget-reject') { budgetReject(trigger.dataset.ulid); return; }
      if (act === 'budget-lock') { budgetLockVersion(trigger.dataset.ulid); return; }
      if (act === 'budget-unlock') { budgetUnlockVersion(trigger.dataset.ulid); return; }
      if (act === 'budget-bva-run') { go('budgets'); return; }
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

      // Forecast controls. A scenario is a set of assumptions handed to a read,
      // so running one re-asks the endpoint with them on the query string and
      // nothing is written. Reset takes the query back to a plain read of the
      // projection as the company expects it.
      if (act === 'forecast-refresh') { go('forecast'); return; }
      if (act === 'forecast-run') {
        const revenue = ($('[data-act="forecast-revenue"]') || {}).value || '';
        const expense = ($('[data-act="forecast-expense"]') || {}).value || '';
        forecastState.revenue_growth = revenue.trim();
        forecastState.expense_growth = expense.trim();
        // Typing a rate is an ad-hoc run, so any saved scenario on screen is
        // let go: the assumptions drawn must be the ones the figures came from.
        forecastState.saved = null;
        scenarioState.detail = null;
        go('forecast');
        return;
      }
      if (act === 'forecast-reset') {
        forecastState.scenario = '';
        forecastState.revenue_growth = '';
        forecastState.expense_growth = '';
        forecastState.saved = null;
        scenarioState.detail = null;
        go('forecast');
        return;
      }
      if (act === 'forecast-export') { startForecastExport(trigger.dataset.format || 'csv'); return; }

      // Saved scenarios. Opening one runs it as a scenario; the rest read and
      // write the history, and every write carries an idempotency key.
      if (act === 'forecast-clear-saved') { clearSavedScenario(); return; }
      if (act === 'scenario-open') { openScenario(trigger.dataset.ulid); return; }
      if (act === 'scenario-history') { showScenarioHistory(trigger.dataset.ulid); return; }
      if (act === 'scenario-history-close') { scenarioState.detail = null; redrawForecast(); return; }
      if (act === 'scenario-new') { newScenario(); return; }
      if (act === 'scenario-revise') { reviseScenario(trigger.dataset.ulid); return; }
      if (act === 'scenario-compare') { runScenarioComparison(); return; }
      if (act === 'scenario-restore') {
        restoreScenarioVersion(trigger.dataset.ulid, trigger.dataset.version, trigger.dataset.versionNo);
        return;
      }
      if (act === 'runway-threshold-save') { saveLowCashThreshold(); return; }
      if (act === 'open-report') {
        // A hub card, a KPI and an alert all walk to the report the API named.
        // The report is given its own address, so where the reader lands is a
        // place they can reload, bookmark or share.
        openReport(trigger.dataset.report);
        return;
      }
      if (act === 'drill-account') {
        // A figure on a statement is a doorway: the row names an account and
        // the report names a window, and together they point at one ledger.
        // Both travel with the click, so the ledger opens on the same period
        // the row was read over rather than on whatever was last used.
        const accountId = Number(trigger.dataset.accountId);
        if (!accountId) return;
        const params = { account_id: accountId };
        if (trigger.dataset.from) params.from = trigger.dataset.from;
        if (trigger.dataset.to) params.to = trigger.dataset.to;
        openReport('general-ledger', { params: params });
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
        // Switching report from inside the viewer is the same move as opening
        // one from the hub: it gets its own address.
        openReport(target.value);
        return;
      }
      // A different fiscal year is a different year's plan, so the version on
      // screen is let go and the newest is read for the year chosen.
      if (target.dataset.act === 'budget-year') {
        budgetState.year = target.value;
        budgetState.version = '';
        go('budgets');
        return;
      }
      // Reading one period's plan against its actuals leaves the rest of the
      // screen exactly as it stands: only the comparison is re-read.
      if (target.dataset.act === 'budget-bva-period') {
        budgetState.bvaPeriod = target.value;
        go('budgets');
        return;
      }
      // Choosing a scenario re-runs the projection under it straight away, so
      // the figures on screen always belong to the scenario named above them.
      if (target.dataset.act === 'forecast-scenario') {
        forecastState.scenario = target.value;
        go('forecast');
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

    // The hub's search filters the cards in place as it is typed, so the word
    // being typed is never interrupted by a redraw.
    $('#view').addEventListener('input', event => {
      if (event.target.dataset.act === 'hub-search') {
        hubState.query = event.target.value;
        filterHub();
      }
    });

    // The scenario form's own rows live inside the dialog, which is outside the
    // view, so its add and remove controls are delegated from the dialog body
    // rather than from #view.
    $('#modal-body').addEventListener('click', event => {
      const trigger = event.target.closest('[data-act]');
      if (!trigger) return;
      if (trigger.dataset.act === 'scenario-add-adjustment') { scenarioAddAdjustment(); return; }
      if (trigger.dataset.act === 'scenario-remove-adjustment') { scenarioRemoveAdjustment(Number(trigger.dataset.index)); return; }
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
