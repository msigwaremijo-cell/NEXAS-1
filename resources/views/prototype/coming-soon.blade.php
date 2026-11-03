<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="light dark">
<meta name="description" content="Your workspace is being built — Nexas">
<title>Coming soon · Nexas</title>
<style>
/* Same design tokens as the sign-in screen, so the placeholder belongs to the
   same system rather than looking like a dead end. */
:root{
 --acc:#2563eb;--acc2:#06b6d4;--bg:#f4f7fb;--bg2:#e6edf8;--surf:#fff;
 --glass:rgba(255,255,255,.62);--bd:rgba(15,23,42,.1);--tx:#0b1220;--mut:#586579;
 --bad:#c0262d;--sh:0 8px 30px rgba(15,23,42,.08);--blur:18px;--rad:14px;color-scheme:light;
}
@media (prefers-color-scheme:dark){:root:not([data-theme="light"]){
 --bg:#0b1220;--surf:#111c32;--glass:rgba(17,28,50,.6);
 --bd:rgba(148,174,220,.16);--tx:#e8eefb;--mut:#92a0b8;--bad:#f87171;color-scheme:dark}}
:root[data-theme="dark"]{
 --bg:#0b1220;--surf:#111c32;--glass:rgba(17,28,50,.6);
 --bd:rgba(148,174,220,.16);--tx:#e8eefb;--mut:#92a0b8;--bad:#f87171;color-scheme:dark}
*,*::before,*::after{box-sizing:border-box}
html,body{height:100%;margin:0}
body{
 font:14px/1.5 Inter,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;
 color:var(--tx);background:var(--bg);display:grid;place-items:center;padding:26px 18px;
}
:focus-visible{outline:2px solid var(--acc);outline-offset:2px;border-radius:6px}
.card{
 width:min(560px,100%);padding:40px 36px;border-radius:22px;border:1px solid var(--bd);
 background:var(--glass);backdrop-filter:blur(var(--blur)) saturate(1.5);
 -webkit-backdrop-filter:blur(var(--blur)) saturate(1.5);
 box-shadow:0 30px 90px rgba(2,6,23,.30);
}
.brand{display:flex;align-items:center;gap:12px;font-weight:700;font-size:19px;margin-bottom:26px}
.logo{
 width:38px;height:38px;border-radius:11px;flex:none;display:grid;place-items:center;color:#fff;
 background:linear-gradient(135deg,var(--acc),var(--acc2));
 box-shadow:0 6px 20px color-mix(in srgb,var(--acc) 55%,transparent);
}
h1{margin:0 0 10px;font-size:24px;letter-spacing:-.3px}
p{margin:0 0 18px;color:var(--mut)}
.who{
 margin:0 0 22px;padding:12px 14px;border:1px solid var(--bd);border-radius:11px;font-size:13px;
 background:color-mix(in srgb,var(--surf) 70%,transparent);
}
.roles{color:var(--mut)}
.row{display:flex;gap:12px;flex-wrap:wrap;align-items:center}
.btn{
 display:inline-flex;align-items:center;justify-content:center;gap:9px;height:44px;padding:0 18px;
 border-radius:11px;border:1px solid var(--bd);background:var(--surf);color:var(--tx);
 font:inherit;font-weight:600;cursor:pointer;text-decoration:none;
}
.btn.pri{border:0;color:#fff;background:linear-gradient(135deg,var(--acc),var(--acc2));
 box-shadow:0 8px 22px color-mix(in srgb,var(--acc) 38%,transparent)}
.btn:disabled{cursor:progress;opacity:.9}
.notice{display:none;margin-top:18px;font-size:13px;color:var(--bad)}
.notice.on{display:block}
</style>
</head>
<body>
<main class="card">
 <div class="brand">
  <span class="logo" aria-hidden="true">
   <svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M5 19V5l14 14V5"/></svg>
  </span>
  <span>Nexas</span>
 </div>

 <h1>Your workspace is being built</h1>
 <p>You are signed in. A dedicated portal for your role is not ready yet, so there is nothing to show here beyond your session.</p>

 <p class="who" id="who" hidden>
  Signed in as <strong id="whoName"></strong> <span class="roles" id="whoRoles"></span>
 </p>

 <div class="row">
  <button class="btn pri" id="signOut" type="button">Sign out</button>
  <a class="btn" href="/">Back to sign in</a>
 </div>

 <p class="notice" id="notice" role="alert" aria-live="assertive"></p>
</main>

<script>
(function () {
  'use strict';

  // The session the sign-in screen wrote. The Financial Manager portal reads
  // these exact keys, so the same three carry a session for every role.
  const KEYS = { token: 'nexas.fm.token', company: 'nexas.fm.company', user: 'nexas.fm.user' };

  let session = { token: null, companyId: null, user: null };
  try {
    session.token = sessionStorage.getItem(KEYS.token);
    const c = sessionStorage.getItem(KEYS.company);
    session.companyId = c ? Number(c) : null;
    const u = sessionStorage.getItem(KEYS.user);
    session.user = u ? JSON.parse(u) : null;
  } catch (e) { /* a browser refusing storage simply shows the page bare */ }

  if (session.user) {
    document.getElementById('whoName').textContent = session.user.name || session.user.email || 'member';
    document.getElementById('whoRoles').textContent =
      session.user.roles && session.user.roles.length ? '· ' + session.user.roles.join(', ') : '';
    document.getElementById('who').hidden = false;
  }

  function clearSession() {
    try {
      sessionStorage.removeItem(KEYS.token);
      sessionStorage.removeItem(KEYS.company);
      sessionStorage.removeItem(KEYS.user);
    } catch (e) { /* ignore */ }
  }

  const button = document.getElementById('signOut');
  const notice = document.getElementById('notice');

  button.addEventListener('click', async () => {
    button.disabled = true;
    button.textContent = 'Signing out…';

    try {
      if (session.token) {
        await fetch('/api/v1/auth/logout', {
          method: 'POST',
          headers: {
            Accept: 'application/json',
            Authorization: 'Bearer ' + session.token,
            ...(session.companyId ? { 'X-Company-Id': String(session.companyId) } : {})
          }
        });
      }
    } catch (e) { /* the local session is dropped either way */ }

    clearSession();
    window.location.replace('/');
  });
})();
</script>
</body>
</html>
