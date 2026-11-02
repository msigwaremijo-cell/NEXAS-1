<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Nexas – Accounting &amp; Business Management</title>
<style>
:root{--acc:#2563eb;--acc2:#06b6d4;--bg:#f4f7fb;--bg2:#e6edf8;--surf:#fff;--glass:rgba(255,255,255,.62);--bd:rgba(15,23,42,.1);--tx:#0b1220;--mut:#586579;--ok:#087f5b;--warn:#b45309;--bad:#c0262d;--sh:0 8px 30px rgba(15,23,42,.08);--pad:18px;--blur:18px;--sbw:264px;--rad:14px;color-scheme:light;
 box-sizing:border-box;padding-top:env(safe-area-inset-top,0px);padding-bottom:env(safe-area-inset-bottom,0px)}
@media (prefers-color-scheme:dark){:root:not([data-theme="light"]){--bg:#0b1220;--bg2:#111c32;--surf:#111c32;--glass:rgba(17,28,50,.6);--bd:rgba(148,174,220,.16);--tx:#e8eefb;--mut:#92a0b8;--ok:#34d399;--warn:#fbbf24;--bad:#f87171;--sh:0 10px 34px rgba(0,0,0,.4);color-scheme:dark}}
:root[data-theme="dark"]{--bg:#0b1220;--bg2:#111c32;--surf:#111c32;--glass:rgba(17,28,50,.6);--bd:rgba(148,174,220,.16);--tx:#e8eefb;--mut:#92a0b8;--ok:#34d399;--warn:#fbbf24;--bad:#f87171;--sh:0 10px 34px rgba(0,0,0,.4);color-scheme:dark}
:root[data-accent="cyan"]{--acc:#0891b2;--acc2:#2563eb}:root[data-accent="violet"]{--acc:#7c3aed;--acc2:#db2777}:root[data-accent="emerald"]{--acc:#059669;--acc2:#06b6d4}:root[data-accent="amber"]{--acc:#d97706;--acc2:#e11d48}
:root[data-density="compact"]{--pad:11px}:root[data-glass="off"]{--blur:0px;--glass:var(--surf)}
*{box-sizing:border-box}html,body{height:100%;margin:0}
body{font:14px/1.5 Inter,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:var(--tx);background:var(--bg);overflow-x:hidden;font-variant-numeric:tabular-nums}
.bgfx{position:fixed;inset:0;z-index:-1;pointer-events:none;background:radial-gradient(60% 50% at 12% 8%,color-mix(in srgb,var(--acc) 26%,transparent),transparent 70%),radial-gradient(50% 45% at 92% 12%,color-mix(in srgb,var(--acc2) 22%,transparent),transparent 70%),linear-gradient(var(--bd) 1px,transparent 1px) 0 0/44px 44px,linear-gradient(90deg,var(--bd) 1px,transparent 1px) 0 0/44px 44px;background-color:var(--bg);animation:drift 28s ease-in-out infinite alternate}
@keyframes drift{to{background-position:6% 4%,-5% 6%,0 0,0 0}}
:root[data-fx="off"] .bgfx{background:var(--bg);animation:none}
:root[data-motion="reduce"] *{animation:none!important;transition:none!important}
@media (prefers-reduced-motion:reduce){*{animation:none!important;transition:none!important}}
button,input,select{font:inherit;color:inherit}button{cursor:pointer}
:focus-visible{outline:2px solid var(--acc);outline-offset:2px}
.app{display:grid;grid-template-columns:var(--sbw) 1fr;grid-template-rows:auto 1fr auto;grid-template-areas:"side head" "side main" "side foot";min-height:100%;transition:grid-template-columns .25s}
[data-sidebar="compact"] .app{--sbw:72px}
.glass{background:var(--glass);backdrop-filter:blur(var(--blur)) saturate(1.4);-webkit-backdrop-filter:blur(var(--blur)) saturate(1.4);border:1px solid var(--bd)}
header{grid-area:head;position:sticky;top:0;z-index:30;display:flex;align-items:center;gap:10px;padding:10px 18px;border-width:0 0 1px}
aside{grid-area:side;position:sticky;top:0;height:100vh;overflow-y:auto;overflow-x:hidden;z-index:40;border-width:0 1px 0 0;padding:14px 10px;display:flex;flex-direction:column;gap:4px}
main{grid-area:main;padding:22px 22px 30px;min-width:0}
footer{grid-area:foot;display:flex;flex-wrap:wrap;gap:8px 18px;justify-content:space-between;padding:12px 22px;border-width:1px 0 0;color:var(--mut);font-size:12px}
.brand{display:flex;align-items:center;gap:10px;padding:4px 8px 14px;font-weight:700;font-size:18px;letter-spacing:.2px}
.logo{width:34px;height:34px;border-radius:10px;flex:none;display:grid;place-items:center;color:#fff;background:linear-gradient(135deg,var(--acc),var(--acc2));box-shadow:0 4px 14px color-mix(in srgb,var(--acc) 50%,transparent)}
.grp>button{width:100%;display:flex;align-items:center;gap:10px;padding:8px 10px;border:0;background:none;border-radius:10px;color:var(--mut);font-weight:600;text-align:left}
.grp>button:hover,.nav a:hover{background:color-mix(in srgb,var(--acc) 10%,transparent)}
.grp .chev{margin-left:auto;transition:transform .2s}.grp.open .chev{transform:rotate(90deg)}
.nav{display:none;padding-left:14px;margin-left:12px;border-left:1px solid var(--bd)}.grp.open .nav{display:block}
.nav a{display:block;padding:6px 10px;border-radius:8px;color:var(--tx);text-decoration:none;opacity:.85;white-space:nowrap}
.nav a[aria-current=page]{background:linear-gradient(90deg,color-mix(in srgb,var(--acc) 22%,transparent),transparent);box-shadow:inset 2px 0 var(--acc);opacity:1;font-weight:600}
[data-sidebar="compact"] .lbl,[data-sidebar="compact"] .chev{display:none}[data-sidebar="compact"] .nav{display:none!important}[data-sidebar="compact"] .brand span{display:none}
.ib{width:36px;height:36px;display:grid;place-items:center;border-radius:10px;border:1px solid var(--bd);background:var(--glass);position:relative}
.ib:hover{border-color:var(--acc)}.dot{position:absolute;top:6px;right:7px;width:8px;height:8px;border-radius:50%;background:var(--bad)}
.search{flex:1;max-width:420px;display:flex;align-items:center;gap:8px;padding:0 12px;height:36px;border-radius:10px;border:1px solid var(--bd);background:var(--glass);color:var(--mut)}
.search kbd,.kbd{font-size:11px;border:1px solid var(--bd);border-radius:5px;padding:0 5px;margin-left:auto}
.btn{display:inline-flex;align-items:center;gap:6px;height:36px;padding:0 14px;border-radius:10px;border:1px solid var(--bd);background:var(--surf);font-weight:600}
.btn.pri{background:linear-gradient(135deg,var(--acc),var(--acc2));color:#fff;border:0}.btn:disabled{opacity:.45;cursor:not-allowed}
.sp{flex:1}.user{display:flex;gap:8px;align-items:center;font-size:12px;line-height:1.2}.av{width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,var(--acc2),var(--acc));display:grid;place-items:center;color:#fff;font-weight:700}
.pill{font-size:11px;padding:2px 8px;border-radius:99px;border:1px solid var(--bd);color:var(--mut);white-space:nowrap}
.pill.demo{color:var(--warn);border-color:currentColor}
h1{font-size:22px;margin:0}.crumb{color:var(--mut);font-size:12px;margin-bottom:4px}.top{display:flex;flex-wrap:wrap;gap:10px;align-items:end;justify-content:space-between;margin-bottom:18px}
.seg{display:inline-flex;border:1px solid var(--bd);border-radius:10px;overflow:hidden;background:var(--glass)}.seg button{border:0;background:none;padding:7px 12px}.seg button[aria-pressed=true]{background:var(--acc);color:#fff}
.grid{display:grid;gap:14px}.kpis{grid-template-columns:repeat(auto-fit,minmax(190px,1fr))}.two{grid-template-columns:minmax(0,2fr) minmax(0,1fr);margin-top:14px}
.card{border-radius:var(--rad);padding:var(--pad);background:var(--surf);border:1px solid var(--bd);box-shadow:var(--sh);min-width:0}
.card.g{background:var(--glass);backdrop-filter:blur(var(--blur));-webkit-backdrop-filter:blur(var(--blur))}
.k small{color:var(--mut)}.k b{display:block;font-size:24px;margin:4px 0;letter-spacing:-.5px}.up{color:var(--ok)}.dn{color:var(--bad)}
h2{font-size:15px;margin:0 0 10px}
.tw{overflow-x:auto}table{width:100%;border-collapse:collapse;min-width:560px}th,td{padding:calc(var(--pad)/2) 10px;text-align:left;border-bottom:1px solid var(--bd);white-space:nowrap}
th{color:var(--mut);font-weight:600;cursor:pointer;user-select:none}td.n,th.n{text-align:right}
.st{padding:2px 9px;border-radius:99px;font-size:12px;font-weight:600;background:color-mix(in srgb,currentColor 14%,transparent)}.paid{color:var(--ok)}.part{color:var(--warn)}.over{color:var(--bad)}
.bar{height:8px;border-radius:9px;background:var(--bd);overflow:hidden;margin:4px 0 12px}.bar i{display:block;height:100%;background:linear-gradient(90deg,var(--acc),var(--acc2))}
svg text{fill:var(--mut);font-size:11px}.chart{width:100%;height:auto}
input,select{background:var(--surf);border:1px solid var(--bd);border-radius:9px;padding:7px 10px;min-width:0}input.err{border-color:var(--bad)}
.jl{display:grid;grid-template-columns:2fr 1fr 1fr auto;gap:8px;margin-bottom:8px}.tot{display:flex;gap:18px;flex-wrap:wrap;align-items:center;margin-top:12px}
.alert{display:flex;gap:8px;padding:8px 0;border-bottom:1px solid var(--bd)}.alert:last-child{border:0}
.pal{position:fixed;inset:0;z-index:100;display:none;align-items:flex-start;justify-content:center;padding-top:12vh;background:rgba(5,10,20,.5)}.pal.on{display:flex}
.pal .box{width:min(560px,92vw);border-radius:16px;padding:10px;box-shadow:0 30px 80px rgba(0,0,0,.45);background:var(--glass);backdrop-filter:blur(24px)}
.pal input{width:100%;padding:12px;font-size:16px}.pal ul{list-style:none;margin:8px 0 0;padding:0;max-height:320px;overflow:auto}.pal li{padding:9px 12px;border-radius:99px;cursor:pointer}.pal li[aria-selected=true],.pal li:hover{background:color-mix(in srgb,var(--acc) 18%,transparent)}
.sw{display:flex;gap:10px}.sw button{width:30px;height:30px;border-radius:50%;border:2px solid transparent}.sw button[aria-pressed=true]{border-color:var(--tx);box-shadow:0 0 0 2px var(--surf) inset}
.row{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:12px 0;border-bottom:1px solid var(--bd);flex-wrap:wrap}.row:last-child{border:0}
.sw2{width:42px;height:24px;border-radius:99px;border:0;background:var(--bd);position:relative}.sw2::after{content:"";position:absolute;top:3px;left:3px;width:18px;height:18px;border-radius:50%;background:#fff;transition:left .2s}.sw2[aria-checked=true]{background:var(--acc)}.sw2[aria-checked=true]::after{left:21px}
#toasts{position:fixed;right:16px;bottom:16px;z-index:200;display:grid;gap:8px}.toast{padding:10px 14px;border-radius:12px;box-shadow:var(--sh)}
.scrim{display:none}.menu{display:none}
@media (max-width:1000px){.two{grid-template-columns:1fr}}
@media (max-width:820px){.menu{display:grid}.app{grid-template-columns:1fr;grid-template-areas:"head" "main" "foot"}
 aside{position:fixed;left:0;top:0;width:280px;transform:translateX(-102%);transition:transform .25s;background:var(--surf)}
 [data-drawer="open"] aside{transform:none}[data-drawer="open"] .scrim{display:block;position:fixed;inset:0;z-index:35;background:rgba(5,10,20,.5)}
 [data-sidebar="compact"] .lbl,[data-sidebar="compact"] .chev,[data-sidebar="compact"] .brand span{display:initial}[data-sidebar="compact"] .nav{display:none!important}
 .user div,.hide-m{display:none}main{padding:16px 12px}.jl{grid-template-columns:1fr 1fr}.jl input:first-child{grid-column:1/-1}}
</style>
</head>
<body>
<div class="bgfx" aria-hidden="true"></div>
<div class="app">
<aside class="glass" id="side" aria-label="Main navigation">
 <div class="brand"><div class="logo"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M5 19V5l14 14V5"/></svg></div><span>Nexas</span></div>
 <div id="nav"></div>
</aside>
<div class="scrim" id="scrim"></div>
<header class="glass">
 <button class="ib menu" id="menuBtn" aria-label="Open menu">☰</button>
 <button class="ib hide-m" id="collapse" aria-label="Toggle compact sidebar" title="Compact sidebar">⇔</button>
 <button class="search" id="openPal" aria-label="Search or run a command"><span>⌕</span><span class="hide-m">Search or jump to…</span><span class="kbd">Ctrl K</span></button>
 <span class="sp"></span>
 <span class="pill hide-m">Acme Traders Ltd ▾</span><span class="pill hide-m">FY 2026 · Oct</span>
 <button class="btn pri" id="quick">＋ <span class="hide-m">New entry</span></button>
 <button class="ib" id="modeBtn" aria-label="Toggle light or dark mode" title="Light / dark">◐</button>
 <button class="ib" id="bell" aria-label="Notifications">🔔<i class="dot"></i></button>
 <div class="user"><div class="av">AM</div><div><b>Amina M.</b><br><span style="color:var(--mut)">Accountant</span></div></div>
</header>
<main id="view" tabindex="-1"></main>
<footer class="glass"><span>© 2026 Nexas · Prototype v0.1</span><span><span class="pill demo">Demo data</span> No backend connected. Nothing is saved to a ledger.</span><span>Base currency: TZS</span></footer>
</div>
<div class="pal" id="pal" role="dialog" aria-modal="true" aria-label="Command palette"><div class="box"><input id="palIn" placeholder="Type a page or action…" aria-label="Command"><ul id="palList" role="listbox"></ul></div></div>
<div id="toasts" role="status" aria-live="polite"></div>
<script>
const $=(s,r=document)=>r.querySelector(s),R=document.documentElement;
const fmt=n=>'TSh '+Math.round(n).toLocaleString('en-US');
const DEF={mode:'system',accent:'blue',glass:'on',fx:'on',density:'comfortable',sidebar:'expanded',motion:'off'};
let P;try{P={...DEF,...JSON.parse(localStorage.getItem('nexas.prefs')||'{}')}}catch(e){P={...DEF}}
function apply(){R.dataset.theme=P.mode==='system'?'':P.mode;if(P.mode==='system')delete R.dataset.theme;
 R.dataset.accent=P.accent;R.dataset.glass=P.glass;R.dataset.fx=P.fx;R.dataset.density=P.density;R.dataset.sidebar=P.sidebar;R.dataset.motion=P.motion==='on'?'reduce':'';
 try{localStorage.setItem('nexas.prefs',JSON.stringify(P))}catch(e){}}
function toast(m,c){const t=document.createElement('div');t.className='toast glass';t.textContent=m;$('#toasts').append(t);setTimeout(()=>t.remove(),2800)}
/* ---- demo ledger: every figure on screen derives from this ---- */
const D=[ // id,date,party,type,amount,paid
['INV-1041','2026-10-24','Kilimanjaro Coffee Co','Sale',4800000,4800000],['INV-1040','2026-10-18','Serengeti Lodges','Sale',9250000,3000000],
['BILL-318','2026-10-15','Moshi Packaging','Purchase',2100000,2100000],['INV-1039','2026-09-29','Arusha Hardware','Sale',3600000,0],
['INV-1038','2026-09-20','Zanzibar Spice Traders','Sale',5400000,5400000],['BILL-317','2026-09-12','Mbeya Agro Supplies','Purchase',3300000,1000000],
['EXP-077','2026-09-05','Office rent','Expense',1500000,1500000],['INV-1037','2026-08-27','Dodoma Retail','Sale',7200000,7200000],
['BILL-316','2026-08-14','Moshi Packaging','Purchase',2800000,2800000],['EXP-076','2026-08-03','Salaries','Expense',3900000,3900000],
['INV-1036','2026-07-22','Kilimanjaro Coffee Co','Sale',6100000,6100000],['EXP-075','2026-07-04','Utilities','Expense',620000,620000]];
const T=D.map(([id,date,party,type,amt,paid])=>({id,date,party,type,amt,paid}));
const today=new Date('2026-10-27');
const status=t=>t.paid>=t.amt?['Paid','paid']:(new Date(t.date).getTime()+30*864e5<today&&t.type==='Sale'?['Overdue','over']:t.paid>0?['Partial','part']:['Unpaid','part']);
const sum=(f,k='amt')=>T.filter(f).reduce((a,t)=>a+(k==='out'?t.amt-t.paid:t[k]),0);
const OPEN=15000000; // opening cash
const K=()=>{const rev=sum(t=>t.type==='Sale'),exp=sum(t=>t.type!=='Sale'),cash=OPEN+sum(t=>t.type==='Sale','paid')-sum(t=>t.type!=='Sale','paid');
 return{rev,exp,net:rev-exp,cash,ar:sum(t=>t.type==='Sale','out'),ap:sum(t=>t.type==='Purchase','out')}};
const MON=['Jul','Aug','Sep','Oct'];const monthly=()=>MON.map((m,i)=>({m,r:sum(t=>t.type==='Sale'&&+t.date.slice(5,7)===7+i),e:sum(t=>t.type!=='Sale'&&+t.date.slice(5,7)===7+i)}));
/* journal ledger (posted entries) */
let J=[];let jn=1;
const GROUPS=[['Overview','◧',['Dashboard','Business overview','Financial performance']],['Accounting','▤',['Chart of accounts','General ledger','Journal entries','Trial balance','Bank reconciliation']],['Sales','↗',['Customers','Sales invoices','Credit notes','Receivables']],['Purchases','↙',['Suppliers','Supplier bills','Payables']],['Inventory','▣',['Products','Stock movements','Valuation']],['Reports','▥',['Profit and loss','Balance sheet','Cash flow']],['Administration','⚙',['Appearance','Users and permissions','Audit logs']]];
const REAL=['Dashboard','Journal entries','Appearance'];
let cur='Dashboard',openG='Overview',sort={k:'date',d:-1},q='',range='quarter';
function nav(){$('#nav').innerHTML=GROUPS.map(([g,ic,items])=>`<div class="grp ${openG===g?'open':''}"><button data-g="${g}" aria-expanded="${openG===g}"><span>${ic}</span><span class="lbl">${g}</span><span class="chev">›</span></button><div class="nav">${items.map(i=>`<a href="#${encodeURIComponent(i)}" data-p="${i}" ${i===cur?'aria-current="page"':''}>${i}</a>`).join('')}</div></div>`).join('')}
$('#nav').addEventListener('click',e=>{const b=e.target.closest('[data-g]');if(b){openG=openG===b.dataset.g?'':b.dataset.g;if(P.sidebar==='compact'&&innerWidth>820){P.sidebar='expanded';apply()}nav()}const a=e.target.closest('[data-p]');if(a){e.preventDefault();go(a.dataset.p)}});
function go(p){cur=p;const g=GROUPS.find(x=>x[2].includes(p));if(g)openG=g[0];R.dataset.drawer='';nav();render();location.hash=encodeURIComponent(p);$('#view').focus({preventScroll:true});scrollTo(0,0)}
function head(t,extra=''){const g=GROUPS.find(x=>x[2].includes(cur));return`<div class="top"><div><div class="crumb">Nexas / ${g?g[0]:''} / ${t}</div><h1>${t}</h1></div>${extra}</div>`}
function render(){const v=$('#view');({'Dashboard':dash,'Journal entries':journal,'Appearance':appearance}[cur]||stub)(v)}
function stub(v){v.innerHTML=head(cur)+`<div class="card g" style="text-align:center;padding:50px 20px"><h2>${cur} is part of the planned build</h2><p style="color:var(--mut);max-width:46ch;margin:0 auto 16px">This module needs the Laravel and MySQL backend to store real records. The dashboard, journal entries and appearance pages are working in this prototype.</p><button class="btn pri" data-p="Dashboard">Back to dashboard</button></div>`}
/* ---- dashboard ---- */
function chart(){const m=monthly(),mx=Math.max(...m.flatMap(x=>[x.r,x.e]))*1.1,W=600,H=230,bw=34;
 const y=v=>H-30-(v/mx)*(H-50);let s=`<svg class="chart" viewBox="0 0 ${W} ${H}" role="img" aria-label="Revenue and expenses by month">`;
 [0,.5,1].forEach(f=>{const yy=y(mx*f);s+=`<line x1="40" x2="${W}" y1="${yy}" y2="${yy}" stroke="var(--bd)"/><text x="0" y="${yy+4}">${(mx*f/1e6).toFixed(0)}M</text>`});
 m.forEach((d,i)=>{const x=70+i*140;s+=`<rect x="${x}" y="${y(d.r)}" width="${bw}" height="${H-30-y(d.r)}" rx="5" fill="var(--acc)"><title>${d.m} revenue ${fmt(d.r)}</title></rect><rect x="${x+bw+6}" y="${y(d.e)}" width="${bw}" height="${H-30-y(d.e)}" rx="5" fill="var(--acc2)" opacity=".75"><title>${d.m} expenses ${fmt(d.e)}</title></rect><text x="${x+8}" y="${H-10}">${d.m}</text>`});
 const pts=m.map((d,i)=>`${87+i*140+19},${y(Math.max(d.r-d.e,0))}`).join(' ');return s+`<polyline points="${pts}" fill="none" stroke="var(--ok)" stroke-width="2.5" stroke-dasharray="5 4"/></svg>`}
function dash(v){const k=K(),pct=(a,b)=>Math.round(a/b*100);
 const kp=[['Revenue',k.rev,'up','▲ from sales invoices'],['Expenses',k.exp,'dn','bills and expenses'],['Net profit',k.net,k.net>=0?'up':'dn',pct(k.net,k.rev)+'% margin'],['Cash and bank',k.cash,'up','opening 15M + receipts − payments'],['Receivable',k.ar,'dn','unpaid sales'],['Payable',k.ap,'dn','unpaid bills']];
 v.innerHTML=head('Dashboard',`<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center"><span class="pill demo">Demonstration data</span><div class="seg" role="group" aria-label="Period">${[['month','Month'],['quarter','Quarter'],['year','Year']].map(([a,b])=>`<button data-r="${a}" aria-pressed="${range===a}">${b}</button>`).join('')}</div></div>`)+
 `<div class="grid kpis">${kp.map(([a,b,c,d],i)=>`<div class="card k ${i<3?'g':''}"><small>${a}</small><b>${fmt(b)}</b><span class="${c}" style="font-size:12px">${d}</span></div>`).join('')}</div>
 <div class="grid two"><div class="card"><h2>Revenue, expenses and profit</h2>${chart()}<small style="color:var(--mut)">Bars: revenue and expenses. Dashed line: profit. Totals match the cards above.</small></div>
 <div class="card"><h2>Alerts</h2>${alerts()}</div></div>
 <div class="card" style="margin-top:14px"><div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:space-between;margin-bottom:8px"><h2>Recent transactions</h2><input id="flt" type="search" placeholder="Filter by name, number or type" aria-label="Filter transactions" value="${q}"></div><div class="tw"><table><thead><tr>${[['id','Number'],['date','Date'],['party','Party'],['type','Type'],['amt','Amount','n']].map(([a,b,c])=>`<th class="${c||''}" data-s="${a}" tabindex="0" aria-sort="${sort.k===a?(sort.d>0?'ascending':'descending'):'none'}">${b}${sort.k===a?(sort.d>0?' ▲':' ▼'):''}</th>`).join('')}<th>Status</th></tr></thead><tbody id="tb"></tbody></table></div><div style="display:flex;justify-content:space-between;margin-top:10px"><button class="btn" id="csv">Export CSV</button><small id="cnt" style="color:var(--mut)"></small></div></div>`;rows()}
function alerts(){const od=T.filter(t=>status(t)[1]==='over'),bills=T.filter(t=>t.type==='Purchase'&&t.paid<t.amt);
 return[`<span class="over">●</span><span>${od.length} overdue invoice${od.length===1?'':'s'}: ${fmt(od.reduce((a,t)=>a+t.amt-t.paid,0))}</span>`,`<span class="part">●</span><span>${bills.length} supplier bill${bills.length===1?'':'s'} awaiting payment</span>`,`<span class="part">●</span><span>3 products below reorder level (sample)</span>`,`<span class="part">●</span><span>12 bank lines unreconciled (sample)</span>`].map(x=>`<div class="alert">${x}</div>`).join('')}
function rows(){const f=T.filter(t=>(t.id+t.party+t.type).toLowerCase().includes(q.toLowerCase())).sort((a,b)=>(a[sort.k]>b[sort.k]?1:-1)*sort.d);
 $('#tb').innerHTML=f.length?f.map(t=>{const[s,c]=status(t);return`<tr><td>${t.id}</td><td>${t.date}</td><td>${t.party}</td><td>${t.type}</td><td class="n">${fmt(t.amt)}</td><td><span class="st ${c}">${s}</span></td></tr>`}).join(''):`<tr><td colspan="6" style="text-align:center;color:var(--mut);padding:26px">No transactions match “${q.replace(/</g,'&lt;')}”. Clear the filter to see all.</td></tr>`;$('#cnt').textContent=f.length+' of '+T.length+' shown'}
/* ---- journal: balanced-entry validation ---- */
const ACC=['1010 Cash','1020 Bank','1100 Receivables','1200 Inventory','2100 Payables','3000 Equity','4000 Sales revenue','5000 Cost of sales','6100 Rent expense'];
function journal(v){v.innerHTML=head('Journal entries','<span class="pill demo">Simulated posting</span>')+`<div class="card g"><h2>New entry JE-${String(jn).padStart(4,'0')}</h2><div id="lines"></div><button class="btn" id="addL">＋ Add line</button>
 <div class="tot"><span>Debits <b id="td"></b></span><span>Credits <b id="tc"></b></span><span id="bal" class="pill"></span><span class="sp"></span><button class="btn pri" id="post" disabled>Post entry</button></div></div>
 <div class="card" style="margin-top:14px"><h2>Posted this session</h2><div class="tw"><table><thead><tr><th>Number</th><th>Date</th><th>Lines</th><th class="n">Amount</th><th></th></tr></thead><tbody id="jb"></tbody></table></div><small style="color:var(--mut)">Posted entries are never edited. Use Reverse to create an offsetting entry. Production enforcement happens on the server in a database transaction.</small></div>`;
 lines=[{a:ACC[0],d:'',c:''},{a:ACC[6],d:'',c:''}];drawL();drawJ()}
let lines=[];
function drawL(){$('#lines').innerHTML=lines.map((l,i)=>`<div class="jl" data-i="${i}"><select aria-label="Account">${ACC.map(a=>`<option ${a===l.a?'selected':''}>${a}</option>`).join('')}</select><input inputmode="decimal" placeholder="Debit" aria-label="Debit" data-f="d" value="${l.d}"><input inputmode="decimal" placeholder="Credit" aria-label="Credit" data-f="c" value="${l.c}"><button class="ib" data-del="${i}" aria-label="Remove line">✕</button></div>`).join('');tally()}
function tally(){const d=lines.reduce((a,l)=>a+(+l.d||0),0),c=lines.reduce((a,l)=>a+(+l.c||0),0),ok=d>0&&Math.abs(d-c)<.005&&lines.every(l=>!(+l.d&&+l.c));
 $('#td').textContent=fmt(d);$('#tc').textContent=fmt(c);const b=$('#bal');b.textContent=ok?'Balanced':d===c?'Enter amounts':'Out by '+fmt(Math.abs(d-c));b.className='pill '+(ok?'paid':'over');b.style.color=ok?'var(--ok)':'var(--bad)';$('#post').disabled=!ok}
function drawJ(){$('#jb').innerHTML=J.length?J.map(j=>`<tr><td>${j.id}</td><td>${j.date}</td><td>${j.lines}</td><td class="n">${fmt(j.amt)}</td><td>${j.rev?'<span class="pill">Reversed</span>':`<button class="btn" data-rev="${j.id}">Reverse</button>`}</td></tr>`).join(''):'<tr><td colspan="5" style="text-align:center;color:var(--mut);padding:22px">Nothing posted yet. Enter equal debits and credits, then post.</td></tr>'}
/* ---- appearance ---- */
function appearance(v){const seg=(k,opts)=>`<div class="seg" role="group">${opts.map(([a,b])=>`<button data-k="${k}" data-v="${a}" aria-pressed="${P[k]===a}">${b}</button>`).join('')}</div>`,tg=(k,on='on')=>`<button class="sw2" role="switch" data-t="${k}" aria-checked="${P[k]===on}"></button>`;
 v.innerHTML=head('Appearance','<button class="btn" id="reset">Reset to defaults</button>')+`<div class="card g"><div class="row"><div><b>Mode</b><br><small style="color:var(--mut)">System follows your device setting</small></div>${seg('mode',[['light','Light'],['dark','Dark'],['system','System']])}</div>
 <div class="row"><b>Accent colour</b><div class="sw">${[['blue','#2563eb'],['cyan','#0891b2'],['violet','#7c3aed'],['emerald','#059669'],['amber','#d97706']].map(([a,c])=>`<button data-k="accent" data-v="${a}" style="background:${c}" aria-label="${a}" aria-pressed="${P.accent===a}"></button>`).join('')}</div></div>
 <div class="row"><b>Sidebar</b>${seg('sidebar',[['expanded','Expanded'],['compact','Compact']])}</div><div class="row"><b>Density</b>${seg('density',[['comfortable','Comfortable'],['compact','Compact']])}</div>
 <div class="row"><div><b>Glass effects</b><br><small style="color:var(--mut)">Blurred translucent panels</small></div>${tg('glass')}</div><div class="row"><b>Background effects</b>${tg('fx')}</div><div class="row"><b>Reduce motion</b>${tg('motion')}</div></div>
 <p style="color:var(--mut)">Preferences are stored in this browser only.</p>`}
/* ---- palette ---- */
let pi=0,items=[];
function palOpen(){$('#pal').classList.add('on');$('#palIn').value='';palFill('');$('#palIn').focus()}
function palFill(t){const all=[...GROUPS.flatMap(g=>g[2].map(i=>({l:'Go to '+i,f:()=>go(i)}))),{l:'Toggle light / dark',f:toggleMode},{l:'Compact sidebar',f:()=>{P.sidebar=P.sidebar==='compact'?'expanded':'compact';apply()}}];items=all.filter(x=>x.l.toLowerCase().includes(t.toLowerCase())).slice(0,8);pi=0;palDraw()}
function palDraw(){$('#palList').innerHTML=items.length?items.map((x,i)=>`<li role="option" data-i="${i}" aria-selected="${i===pi}">${x.l}</li>`).join(''):'<li>No matches. Try a module name.</li>'}
const palClose=()=>$('#pal').classList.remove('on');
function toggleMode(){const dark=P.mode==='dark'||(P.mode==='system'&&matchMedia('(prefers-color-scheme:dark)').matches);P.mode=dark?'light':'dark';apply();if(cur==='Appearance')render();toast('Mode: '+P.mode)}
/* ---- events ---- */
document.addEventListener('click',e=>{const t=e.target;let x;
 if(x=t.closest('[data-r]')){range=x.dataset.r;render();toast('Demo data is not date-filtered in this prototype')}
 else if(x=t.closest('[data-s]')){sort={k:x.dataset.s,d:sort.k===x.dataset.s?-sort.d:1};render()}
 else if(t.closest('#csv')){const b=new Blob([['Number,Date,Party,Type,Amount,Paid',...T.map(r=>[r.id,r.date,`"${r.party}"`,r.type,r.amt,r.paid].join(','))].join('\n')],{type:'text/csv'});const a=document.createElement('a');a.href=URL.createObjectURL(b);a.download='nexas-demo-transactions.csv';a.click();toast('CSV exported')}
 else if(x=t.closest('[data-k]')){P[x.dataset.k]=x.dataset.v;apply();render()}
 else if(x=t.closest('[data-t]')){const k=x.dataset.t;P[k]=P[k]==='on'?'off':'on';apply();render()}
 else if(t.closest('#reset')){P={...DEF};apply();render();toast('Appearance reset')}
 else if(t.closest('#addL')){lines.push({a:ACC[0],d:'',c:''});drawL()}
 else if(x=t.closest('[data-del]')){if(lines.length>2){lines.splice(+x.dataset.del,1);drawL()}else toast('An entry needs at least two lines')}
 else if(t.closest('#post')){const d=lines.reduce((a,l)=>a+(+l.d||0),0);J.unshift({id:'JE-'+String(jn++).padStart(4,'0'),date:'2026-10-27',lines:lines.length,amt:d,rev:false});toast('Entry posted (simulated)');journal($('#view'))}
 else if(x=t.closest('[data-rev]')){const j=J.find(z=>z.id===x.dataset.rev);j.rev=true;J.unshift({id:'JE-'+String(jn++).padStart(4,'0'),date:'2026-10-27',lines:j.lines,amt:j.amt,rev:true});drawJ();toast('Reversal created for '+j.id)}
 else if(t.closest('#modeBtn'))toggleMode();else if(t.closest('#collapse')){P.sidebar=P.sidebar==='compact'?'expanded':'compact';apply()}
 else if(t.closest('#menuBtn'))R.dataset.drawer='open';else if(t.closest('#scrim'))R.dataset.drawer='';
 else if(t.closest('#openPal'))palOpen();else if(t.closest('#quick'))go('Journal entries');else if(t.closest('#bell'))toast('2 overdue invoices, 1 bill due soon');
 else if(x=t.closest('#palList li[data-i]')){palClose();items[+x.dataset.i].f()}
 else if(t.id==='pal')palClose();
 else if(x=t.closest('[data-p]')&&!t.closest('#nav'))go(x.dataset.p)});
document.addEventListener('input',e=>{const t=e.target;if(t.id==='flt'){clearTimeout(window._d);window._d=setTimeout(()=>{q=t.value;rows()},150)}
 else if(t.id==='palIn')palFill(t.value);else if(t.dataset.f){const l=lines[+t.closest('[data-i]').dataset.i];l[t.dataset.f]=t.value;t.classList.toggle('err',t.value!==''&&!(+t.value>=0));tally()}});
document.addEventListener('change',e=>{if(e.target.matches('.jl select'))lines[+e.target.closest('[data-i]').dataset.i].a=e.target.value});
document.addEventListener('keydown',e=>{
 if((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==='k'){e.preventDefault();palOpen();return}
 if(e.key==='Escape'){palClose();R.dataset.drawer='';return}
 if($('#pal').classList.contains('on')){if(e.key==='ArrowDown'){e.preventDefault();pi=Math.min(pi+1,items.length-1);palDraw()}else if(e.key==='ArrowUp'){e.preventDefault();pi=Math.max(pi-1,0);palDraw()}else if(e.key==='Enter'&&items[pi]){palClose();items[pi].f()}return}
 if(e.key==='Enter'&&e.target.matches('th[data-s]'))e.target.click()});
matchMedia('(prefers-color-scheme:dark)').addEventListener('change',()=>{if(P.mode==='system')apply()});
apply();const h=decodeURIComponent(location.hash.slice(1));if(GROUPS.some(g=>g[2].includes(h)))cur=h;nav();render();
</script>
</body>
</html>
