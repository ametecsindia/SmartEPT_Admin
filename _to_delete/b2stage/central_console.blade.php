<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>SmartEPT Central — Ametecs Super Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
<style>
:root{--accent:#0E7C8F;--accent2:#22B8CF;--weak:#E3F4F7;--deep:#0B6373;--ink:#15171C;--ink2:#565A66;
--ink3:#878C99;--canvas:#F4F6F9;--card:#fff;--card2:#FAFBFC;--border:#E7E9EF;--border2:#DCDFE7;
--hair:#F0F1F4;--ok:#08875D;--ok-w:#E6F5EE;--warn:#B7791F;--warn-w:#FBF3E2;--danger:#D02748;
--danger-w:#FBE9ED;--info:#0B72C9;--info-w:#E6F1FB;--navy1:#04252C;--navy2:#0B4A56}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Inter','Segoe UI',sans-serif;background:var(--canvas);color:var(--ink);font-size:14px;display:flex;min-height:100vh}
button{font-family:inherit;cursor:pointer}
h1,h2,h3,h4,.brand b,.nav-sec{font-family:'Plus Jakarta Sans','Inter','Segoe UI',sans-serif;letter-spacing:-.01em}
input,select,textarea{font-family:inherit;font-size:13.5px;padding:9px 11px;border:1.5px solid var(--border2);border-radius:8px;width:100%;background:#fff;color:var(--ink)}
input:focus,select:focus,textarea:focus{outline:none;border-color:var(--accent)}
label{display:block;font-size:12px;font-weight:700;color:var(--ink2);margin:10px 0 4px}
/* sidebar */
aside{width:232px;background:linear-gradient(175deg,var(--navy1),#083039);color:#C9E2E7;padding:20px 14px;display:flex;flex-direction:column;position:sticky;top:0;height:100vh}
.brand{display:flex;align-items:center;gap:10px;padding:2px 8px 18px;border-bottom:1px solid rgba(255,255,255,.1)}
.brand .mk{width:36px;height:36px;border-radius:9px;background:linear-gradient(135deg,var(--accent),var(--accent2));display:flex;align-items:center;justify-content:center;font-weight:800;font-size:12px;color:#fff}
.brand b{font-size:15px;color:#fff}.brand small{display:block;font-size:8.5px;letter-spacing:2px;color:#7FA8AF}
nav{flex:1;margin-top:14px;overflow-y:auto;min-height:0;scrollbar-width:thin;scrollbar-color:rgba(255,255,255,.2) transparent}
nav::-webkit-scrollbar{width:5px}nav::-webkit-scrollbar-thumb{background:rgba(255,255,255,.18);border-radius:3px}
.nav-item{display:flex;align-items:center;gap:11px;padding:9.5px 12px;border-radius:9px;font-size:13.5px;font-weight:600;color:#A9CBD1;cursor:pointer;margin-bottom:2px}
.nav-item:hover{background:rgba(255,255,255,.06);color:#fff}
.nav-item.on{background:linear-gradient(135deg,var(--accent),#1899AE);color:#fff}
.nav-ic{display:flex;align-items:center;opacity:.85}.nav-item.on .nav-ic{opacity:1}
.nav-sec{font-size:10px;letter-spacing:1.8px;color:#5E858C;text-transform:uppercase;font-weight:800;margin:16px 12px 6px}
.me{border-top:1px solid rgba(255,255,255,.1);padding-top:12px;display:flex;flex-direction:column;gap:10px}
.me-row{display:flex;align-items:center;gap:9px}
.me .av{width:32px;height:32px;border-radius:50%;background:var(--accent);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:12px;color:#fff}
.me b{font-size:12.5px;color:#fff;display:block}.me span{font-size:10.5px;color:#7FA8AF;text-transform:capitalize}
.me form{width:100%}
.me button{width:100%;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.28);color:#fff;font-size:12px;font-weight:600;padding:9px;border-radius:8px;cursor:pointer}
.me button:hover{background:var(--accent);border-color:var(--accent)}
/* main */
main{flex:1;padding:26px 30px;max-width:calc(100vw - 232px)}
.topbar{display:flex;align-items:center;gap:14px;margin-bottom:20px}
.topbar h1{font-size:21px;font-weight:800}
.help-btn{width:26px;height:26px;border-radius:50%;border:1.5px solid var(--accent);color:var(--accent);background:#fff;font-weight:800;font-size:13px}
.topbar .sp{flex:1}
.btn{padding:9px 16px;border-radius:8px;border:none;font-weight:700;font-size:13px}
.btn-p{background:linear-gradient(135deg,var(--accent),#1899AE);color:#fff}
.btn-l{background:#fff;border:1.5px solid var(--border2);color:var(--deep)}
.btn-d{background:var(--danger-w);color:var(--danger)}
.btn:disabled{opacity:.5;cursor:not-allowed}
/* cards & stats */
.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:20px}
.stat{background:#fff;border:1px solid var(--border);border-radius:13px;padding:16px 18px}
.stat .l{font-size:11px;color:var(--ink3);text-transform:uppercase;letter-spacing:1px;font-weight:700}
.stat .v{font-size:26px;font-weight:800;margin-top:3px}
.stat .v.teal{color:var(--accent)}.stat .v.ok{color:var(--ok)}.stat .v.warn{color:var(--warn)}
.card{background:#fff;border:1px solid var(--border);border-radius:13px;padding:18px 20px;margin-bottom:16px}
.card h3{font-size:14.5px;font-weight:800;margin-bottom:12px;color:var(--deep)}
/* tables */
table{width:100%;border-collapse:collapse;font-size:13px}
th{text-align:left;background:var(--weak);color:var(--deep);padding:9px 11px;font-size:11.5px;text-transform:uppercase;letter-spacing:.6px}
th:first-child{border-radius:8px 0 0 8px}th:last-child{border-radius:0 8px 8px 0}
td{padding:10px 11px;border-bottom:1px solid var(--hair);vertical-align:middle}
tr:hover td{background:var(--card2)}
.pill{display:inline-block;padding:3px 10px;border-radius:999px;font-size:11px;font-weight:700}
.p-ok{background:var(--ok-w);color:var(--ok)}.p-warn{background:var(--warn-w);color:var(--warn)}
.p-dang{background:var(--danger-w);color:var(--danger)}.p-info{background:var(--info-w);color:var(--info)}
.p-mut{background:var(--hair);color:var(--ink3)}
.mini{font-size:11.5px;color:var(--ink3)}
.link{color:var(--accent);font-weight:700;cursor:pointer;background:none;border:none;font-size:12.5px}
.filters{display:flex;gap:10px;margin-bottom:14px;flex-wrap:wrap}
.filters input,.filters select{width:auto;min-width:160px}
/* modal */
.overlay{position:fixed;inset:0;background:rgba(4,37,44,.55);display:none;align-items:flex-start;justify-content:center;z-index:40;padding:40px 16px;overflow-y:auto}
.overlay.show{display:flex}
.modal{background:#fff;border-radius:16px;width:100%;max-width:560px;padding:24px 26px;box-shadow:0 30px 80px rgba(0,0,0,.35)}
.modal.wide{max-width:760px}
.modal h2{font-size:17px;font-weight:800;margin-bottom:4px}
.modal .sub{font-size:12.5px;color:var(--ink3);margin-bottom:12px}
.modal .row{display:grid;grid-template-columns:1fr 1fr;gap:0 14px}
.modal .foot{display:flex;justify-content:flex-end;gap:10px;margin-top:18px}
/* help modal */
.help-head{background:linear-gradient(135deg,var(--navy1),var(--navy2));color:#fff;margin:-24px -26px 14px;padding:18px 26px;border-radius:16px 16px 0 0;display:flex;align-items:center;justify-content:space-between}
.help-head b{font-size:15px}.help-head span{font-size:11px;color:#9FC5CC;display:block}
.help-head button{background:none;border:none;color:#fff;font-size:20px}
.help-tabs{display:flex;gap:6px;margin-bottom:14px}
.help-tabs button{padding:7px 14px;border-radius:8px;border:1.5px solid var(--border2);background:#fff;font-weight:700;font-size:12px;color:var(--ink2)}
.help-tabs button.on{background:var(--weak);border-color:var(--accent);color:var(--deep)}
.help-body{font-size:13px;line-height:1.65;color:var(--ink2);max-height:52vh;overflow-y:auto}
.help-body h4{color:var(--deep);margin:12px 0 6px;font-size:13px}
.help-body ol{padding-left:20px}.help-body li{margin-bottom:5px}
.tip{background:var(--warn-w);border-left:3.5px solid var(--warn);padding:9px 12px;border-radius:0 8px 8px 0;margin-top:10px;color:#7A5614}
.scen{background:var(--navy1);color:#DFF0F3;padding:12px 14px;border-radius:10px;margin:10px 0;font-size:12.5px}
.gain{color:var(--ok)}
/* quote box */
.quote-box{background:var(--weak);border-radius:10px;padding:12px 14px;margin-top:12px;font-size:12.5px;color:var(--deep)}
.quote-box .ln{display:flex;justify-content:space-between;padding:3px 0}
.quote-box .tt{border-top:1.5px solid rgba(11,99,115,.25);margin-top:6px;padding-top:6px;font-weight:800;font-size:14px}
.toast{position:fixed;bottom:22px;right:22px;background:var(--navy1);color:#fff;padding:12px 18px;border-radius:10px;font-size:13px;display:none;z-index:60;box-shadow:0 12px 30px rgba(0,0,0,.3)}
.pager{display:flex;gap:8px;margin-top:12px;align-items:center;font-size:12.5px;color:var(--ink3)}
@media(max-width:1100px){.stats{grid-template-columns:1fr 1fr}.modal .row{grid-template-columns:1fr}}
</style>
</head>
<body data-role="{{ $user->role }}">

<aside>
  <div class="brand"><div class="mk"><img src="data:image/png;base64,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" alt="SmartEPT" style="width:72%;height:72%;object-fit:contain;display:block"></div><div><b>SmartEPT Central</b><small>AMETECS SUPER ADMIN</small></div></div>
  <nav id="nav">
    <div class="nav-sec">Business</div>
    <div class="nav-item on" data-page="dashboard"><span class="nav-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;flex:none"><rect x="3" y="3" width="7.5" height="7.5" rx="1.6"/><rect x="13.5" y="3" width="7.5" height="7.5" rx="1.6"/><rect x="3" y="13.5" width="7.5" height="7.5" rx="1.6"/><rect x="13.5" y="13.5" width="7.5" height="7.5" rx="1.6"/></svg></span> Dashboard</div>
    <div class="nav-item" data-page="tenants"><span class="nav-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;flex:none"><circle cx="9" cy="8.2" r="3.4"/><path d="M2.8 20.2a6.2 6.2 0 0 1 12.4 0"/><circle cx="17.2" cy="9.4" r="2.6"/><path d="M16 15.6a5 5 0 0 1 5.2 4.6"/></svg></span> Clients / Tenants</div>
    <div class="nav-item" data-page="trials"><span class="nav-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;flex:none"><circle cx="12" cy="12" r="8.6"/><path d="M12 7.4V12l3.2 1.9"/></svg></span> Trials</div>
    <div class="nav-item" data-page="leads"><span class="nav-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;flex:none"><path d="M3 8l9 6 9-6"/><rect x="3" y="5" width="18" height="14" rx="2"/></svg></span> Leads</div>
    <div class="nav-sec">Licensing</div>
    <div class="nav-item" data-page="licences"><span class="nav-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;flex:none"><circle cx="8" cy="12" r="4.6"/><path d="M12.6 12H21M17.5 12v3.4M21 12v2.4"/></svg></span> Licences</div>
    <div class="nav-item" data-page="plans"><span class="nav-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;flex:none"><path d="M3 7h18M3 12h18M3 17h12"/></svg></span> Plans &amp; Pricing</div>
    <div class="nav-sec">Money</div>
    <div class="nav-item" data-page="orders"><span class="nav-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;flex:none"><circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M3 4h2.2l2.2 11.2a1.4 1.4 0 0 0 1.4 1.1h8.2a1.4 1.4 0 0 0 1.4-1.1L21 8H6"/></svg></span> Orders &amp; Payments</div>
    <div class="nav-item" data-page="credit"><span class="nav-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;flex:none"><rect x="3" y="5.5" width="18" height="13" rx="2"/><path d="M3 10h18M6.5 14.5h4"/></svg></span> Credit Clients</div>
    <div class="nav-item" data-page="invoices"><span class="nav-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;flex:none"><path d="M6 2.5h9l4 4V21a.9.9 0 0 1-.9.9H6a.9.9 0 0 1-.9-.9V3.4a.9.9 0 0 1 .9-.9z"/><path d="M15 2.5V7h4.5M9 12h6M9 16h6"/></svg></span> Invoices</div>
    <div class="nav-item" data-page="storage"><span class="nav-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;flex:none"><ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v12c0 1.7 3.6 3 8 3s8-1.3 8-3V6M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/></svg></span> Cloud Storage</div>
    <div class="nav-item" data-page="coupons"><span class="nav-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;flex:none"><path d="M4 8a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v2a2 2 0 0 0 0 4v2a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-2a2 2 0 0 0 0-4z"/><path d="M14 6v12" stroke-dasharray="2 2"/></svg></span> Coupons</div>
    <div class="nav-sec">System</div>
    <div class="nav-item" data-page="cms" data-super="1"><span class="nav-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;flex:none"><rect x="3" y="4.5" width="18" height="15" rx="2"/><path d="M3 9h18M7 13h6M7 16h10"/></svg></span> Landing CMS</div>
    <div class="nav-item" data-page="settings" data-super="1"><span class="nav-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;flex:none"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-2.7 1.1V21a2 2 0 1 1-4 0v-.1A1.6 1.6 0 0 0 6.6 19l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1A1.6 1.6 0 0 0 3 13.4H3a2 2 0 1 1 0-4h.1A1.6 1.6 0 0 0 4.6 6.6l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1A1.6 1.6 0 0 0 10 3V3a2 2 0 1 1 4 0v.1a1.6 1.6 0 0 0 2.7 1.1l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.6 1.6 0 0 0 1.1 2.7H21a2 2 0 1 1 0 4h-.1a1.6 1.6 0 0 0-1.5 1z"/></svg></span> Settings</div>
    <div class="nav-item" data-page="audit"><span class="nav-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;flex:none"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 12l2 2 4-4"/></svg></span> Audit Log</div>
  </nav>
  <div class="me">
    <div class="me-row">
      <div class="av">{{ strtoupper(substr($user->name, 0, 2)) }}</div>
      <div><b>{{ $user->name }}</b><span>{{ $user->role }}</span></div>
    </div>
    <form method="POST" action="/admin/logout">@csrf<button type="submit">&#x23FB;&nbsp; Sign out</button></form>
  </div>
</aside>

<main>
  <div class="topbar">
    <h1 id="pageTitle">Dashboard</h1>
    <button class="help-btn" onclick="openHelp()" title="Screen help">i</button>
    <div class="sp"></div>
    <div id="pageActions"></div>
  </div>
  <div id="page"></div>
</main>

<!-- generic modal -->
<div class="overlay" id="modalOv"><div class="modal" id="modalBox"></div></div>
<!-- help modal -->
<div class="overlay" id="helpOv"><div class="modal">
  <div class="help-head"><div><b id="helpTitle"></b><span>SmartEPT Central · Screen Help</span></div>
  <button onclick="closeHelp()">×</button></div>
  <div class="help-tabs">
    <button class="on" onclick="helpTab(0,this)">How to use it</button>
    <button onclick="helpTab(1,this)">Why it matters</button>
    <button onclick="helpTab(2,this)">Do it right</button>
  </div>
  <div class="help-body" id="helpBody"></div>
</div></div>
<div class="toast" id="toast"></div>

<script>
const CSRF = document.querySelector('meta[name=csrf-token]').content;
const ROLE = document.body.dataset.role;
const CAN_WRITE = ROLE === 'super' || ROLE === 'sales';
const fmtMoney = (n, c='INR') => (c==='INR'?'₹':'$') + Number(n).toLocaleString('en-IN', {maximumFractionDigits:2});
const esc = s => String(s ?? '').replace(/[&<>"]/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[m]));
const toast = m => { const t=document.getElementById('toast'); t.textContent=m; t.style.display='block'; setTimeout(()=>t.style.display='none', 3200); };

async function api(path, opts={}) {
  const res = await fetch('/admin/api/' + path, {
    headers: {'Content-Type':'application/json','X-CSRF-TOKEN':CSRF,'Accept':'application/json'},
    ...opts, body: opts.body ? JSON.stringify(opts.body) : undefined,
  });
  if (res.status === 401) { location.href = '/admin/login'; return; }
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw (data.error || data.message || 'Request failed');
  return data;
}

// ---------- modal helpers ----------
const ov = document.getElementById('modalOv'), box = document.getElementById('modalBox');
function openModal(html, wide=false) { box.className = 'modal' + (wide?' wide':''); box.innerHTML = html; ov.classList.add('show'); }
function closeModal() { ov.classList.remove('show'); }
ov.addEventListener('click', e => { if (e.target === ov) closeModal(); });

// ---------- pages ----------
let PAGE = 'dashboard';
const TITLES = {dashboard:'Dashboard',tenants:'Clients / Tenants',trials:'Trials',leads:'Leads',licences:'Licences',
plans:'Plans & Pricing',orders:'Orders & Payments',credit:'Credit Clients — Balance Outstanding',invoices:'Invoices',
storage:'Cloud Storage',coupons:'Coupons',cms:'Landing CMS',settings:'Settings',audit:'Audit Log'};
const LEAD_STATUSES = ['NEW','CONTACTED','DEMO_SCHEDULED','QUOTED','WON','LOST'];

document.querySelectorAll('.nav-item').forEach(el => {
  if (el.dataset.super && ROLE !== 'super') { el.style.display = 'none'; return; }
  el.onclick = () => go(el.dataset.page);
});
function go(page) {
  PAGE = page;
  document.querySelectorAll('.nav-item').forEach(e => e.classList.toggle('on', e.dataset.page === page));
  document.getElementById('pageTitle').textContent = TITLES[page];
  document.getElementById('pageActions').innerHTML = '';
  RENDER[page]();
}
const P = document.getElementById('page');
const ACTIONS = document.getElementById('pageActions');
const statusPill = s => ({quote:'p-info',active:'p-ok',trial:'p-info',paid:'p-ok',created:'p-warn',suspended:'p-warn',
  expired:'p-dang',revoked:'p-dang',failed:'p-dang',refunded:'p-mut',churned:'p-mut',issued:'p-info',
  cancelled:'p-mut',draft:'p-mut'}[s] || 'p-mut');
const pill = s => `<span class="pill ${statusPill(s)}">${esc(s)}</span>`;

const RENDER = {

// ============ DASHBOARD ============
async dashboard() {
  P.innerHTML = '<div class="mini">Loading…</div>';
  const d = await api('dashboard');
  P.innerHTML = `
  <div class="stats">
    <div class="stat"><div class="l">Active Clients</div><div class="v teal">${d.tenants_active}</div><div class="mini">${d.tenants_trial} on trial · ${d.tenants_total} total</div></div>
    <div class="stat"><div class="l">Revenue This Month</div><div class="v ok">${fmtMoney(d.revenue_this_month)}</div><div class="mini">MRR est. ${fmtMoney(d.mrr_estimate)}</div></div>
    <div class="stat"><div class="l">Active Licences</div><div class="v">${d.licences_active}</div><div class="mini">${d.devices_active} devices activated</div></div>
    <div class="stat"><div class="l">Needs Attention</div><div class="v warn">${d.trials_expiring_7d + d.orders_pending}</div><div class="mini">${d.trials_expiring_7d} trials expiring · ${d.orders_pending} unpaid orders</div></div>
  </div>
  <div class="card"><h3>Recent Orders</h3><table><tr><th>Order</th><th>Client</th><th>Description</th><th>Total</th><th>Status</th></tr>
  ${d.recent_orders.map(o => `<tr><td><b>${esc(o.number)}</b></td><td>${esc(o.tenant?.company_name)}</td><td class="mini">${esc(o.description)}</td><td>${fmtMoney(o.total, o.currency)}</td><td>${pill(o.status)}</td></tr>`).join('') || '<tr><td colspan="5" class="mini">No orders yet</td></tr>'}</table></div>
  <div class="card"><h3>Recent Licence Activations</h3><table><tr><th>Key</th><th>Client</th><th>Activated</th></tr>
  ${d.recent_activations.map(l => `<tr><td class="mini">${esc(l.key)}</td><td>${esc(l.tenant?.company_name)}</td><td class="mini">${new Date(l.activated_at).toLocaleString()}</td></tr>`).join('') || '<tr><td colspan="3" class="mini">No activations yet</td></tr>'}</table></div>`;
},

// ============ TENANTS ============
async tenants() {
  if (CAN_WRITE) ACTIONS.innerHTML = '<button class="btn btn-p" onclick="newTenant()">+ New Client</button>';
  P.innerHTML = `<div class="filters">
    <input id="tq" placeholder="Search company / email / phone…">
    <select id="tst"><option value="">All statuses</option><option>trial</option><option>active</option><option>suspended</option><option>expired</option><option>churned</option></select>
    <button class="btn btn-l" onclick="loadTenants()">Search</button></div><div id="tlist"></div>`;
  loadTenants();
},

// ============ TRIALS ============
async trials() {
  const d = await api('trials');
  const row = t => `<tr><td><b>${esc(t.company_name)}</b><div class="mini">${esc(t.email)}</div></td>
    <td>${esc(t.contact_name||'—')}<div class="mini">${esc(t.phone||'')}</div></td>
    <td>${t.trial_ends_at ? new Date(t.trial_ends_at).toLocaleDateString() : '—'}</td>
    <td>${CAN_WRITE ? `<button class="link" onclick="extendTrial(${t.id})">Extend</button>
    <button class="link" onclick="convertTrial(${t.id}, '${esc(t.company_name)}')">Convert to Paid</button>` : ''}</td></tr>`;
  P.innerHTML = `
  <div class="card"><h3>Active Trials (${d.active.length})</h3><table><tr><th>Company</th><th>Contact</th><th>Ends</th><th></th></tr>
  ${d.active.map(row).join('') || '<tr><td colspan="4" class="mini">No active trials</td></tr>'}</table></div>
  <div class="card"><h3>Expired Trials (${d.expired.length})</h3><table><tr><th>Company</th><th>Contact</th><th>Ended</th><th></th></tr>
  ${d.expired.map(row).join('') || '<tr><td colspan="4" class="mini">None</td></tr>'}</table></div>`;
},

// ============ LEADS (R3-7) ============
async leads() {
  if (CAN_WRITE) ACTIONS.innerHTML = '<button class="btn btn-p" onclick="newLead()">+ Add Lead</button>';
  P.innerHTML = `<div class="filters">
    <input id="ldq" placeholder="Search name / company / email / phone…">
    <select id="ldst"><option value="">All statuses</option>${LEAD_STATUSES.map(s=>`<option>${s}</option>`).join('')}</select>
    <button class="btn btn-l" onclick="loadLeads()">Search</button></div><div id="ldlist"></div>`;
  loadLeads();
},

// ============ COUPONS (R3-7) ============
async coupons() {
  if (CAN_WRITE) ACTIONS.innerHTML = '<button class="btn btn-p" onclick="newCoupon()">+ New Coupon</button>';
  P.innerHTML = '<div id="cplist" class="mini">Loading…</div>';
  loadCoupons();
},

// ============ LICENCES ============
async licences() {
  if (CAN_WRITE) ACTIONS.innerHTML = '<button class="btn btn-p" onclick="issueLicence()">+ Issue Licence</button>';
  P.innerHTML = `<div class="filters">
    <input id="lq" placeholder="Search key / company…">
    <select id="lst"><option value="">All statuses</option><option>active</option><option>suspended</option><option>revoked</option><option>expired</option></select>
    <select id="lkind"><option value="">All kinds</option><option>trial</option><option>subscription</option><option>perpetual</option></select>
    <button class="btn btn-l" onclick="loadLicences()">Search</button></div><div id="llist"></div>`;
  loadLicences();
},

// ============ PLANS ============
async plans() {
  const resp = await api('plans');
  const plans = Array.isArray(resp) ? resp : (resp.data || []);
  P.innerHTML = plans.map(p => `<div class="card"><h3>${esc(p.name)} <span class="mini">(${esc(p.code)})</span> ${p.active?'':pill('suspended')}</h3>
  <table><tr><th>INR Annual</th><th>INR Monthly</th><th>USD Annual</th><th>Perpetual/Device</th><th>Server Licence</th><th>Min Devices</th><th></th></tr>
  <tr><td>₹${p.inr_annual}</td><td>₹${p.inr_monthly}</td><td>$${p.usd_annual}</td><td>₹${Number(p.perpetual_device_inr).toLocaleString('en-IN')}</td><td>₹${Number(p.perpetual_server_inr).toLocaleString('en-IN')}</td><td>${p.min_devices}</td>
  <td>${ROLE==='super' ? `<button class="link" onclick='editPlan(${JSON.stringify(p).replace(/'/g,"&#39;")})'>Edit</button>`:''}</td></tr></table>
  ${p.volume_tiers?.length ? `<div class="mini" style="margin-top:8px"><b>Volume tiers:</b> ${p.volume_tiers.map(t=>`${t.min_devices}–${t.max_devices??'∞'}: ₹${t.rate_inr_annual}`).join(' · ')}</div>`:''}
  <div class="mini" style="margin-top:6px"><b>Features:</b> ${Object.entries(p.features||{}).map(([k,v])=>`${k}=${v}`).join(', ')}</div></div>`).join('');
},

// ============ ORDERS ============
async orders() {
  if (CAN_WRITE) ACTIONS.innerHTML = '<button class="btn btn-p" onclick="newOrder()">+ New Order / Quote</button>';
  P.innerHTML = `<div class="filters">
    <select id="ost"><option value="">All statuses</option><option>quote</option><option>created</option><option>paid</option><option>failed</option><option>refunded</option></select>
    <select id="ogw"><option value="">All gateways</option><option>razorpay</option><option>stripe</option><option>manual</option></select>
    <button class="btn btn-l" onclick="loadOrders()">Filter</button></div><div id="olist"></div>`;
  loadOrders();
},

// ============ CREDIT CLIENTS (master prompt §10) ============
async credit() {
  P.innerHTML = '<div class="mini">Loading…</div>';
  const d = await api('credit-clients');
  const rows = (d.data||[]).map(c => `<tr>
    <td><b>${esc(c.tenant?.company_name)}</b><div class="mini">${esc(c.quote_number || c.number)}${c.invoice_number ? ' · ' + esc(c.invoice_number) : ''}</div></td>
    <td class="mini">${esc(c.description)}</td>
    <td><b>${fmtMoney(c.total, c.currency)}</b></td>
    <td style="color:var(--ok);font-weight:700">${fmtMoney(c.received, c.currency)}</td>
    <td><b style="color:${c.overdue ? 'var(--danger)' : 'var(--ink)'}">${fmtMoney(c.balance, c.currency)}</b></td>
    <td style="${c.overdue ? 'color:var(--danger);font-weight:800' : ''}">${c.credit_due_date ? new Date(c.credit_due_date).toLocaleDateString() : '—'}${c.overdue ? ' ⚠ OVERDUE' : ''}</td>
    <td style="white-space:nowrap">${CAN_WRITE ? `<button class="link" onclick="recordBalance(${c.id}, ${c.balance}, '${esc(c.tenant?.company_name)}')">Record balance</button>` : ''}
      <button class="link" onclick="navigator.clipboard.writeText('${esc(c.pay_url)}');toast('Pay-balance link copied — the client\\'s original link stays alive')">Pay link</button></td></tr>`).join('');
  P.innerHTML = `<div class="card"><h3>Provisioned on credit — balance outstanding (${(d.data||[]).length})</h3>
  <table><tr><th>Client</th><th>Order</th><th>Total</th><th>Received</th><th>Balance</th><th>Payable by</th><th></th></tr>
  ${rows || '<tr><td colspan="7" class="mini">No credit balances — every provisioned order is fully paid. 🎉</td></tr>'}</table>
  <div class="mini" style="margin-top:10px">Overdue turns red as a reminder for <b>manual follow-up only</b> — nothing locks automatically. Credit is a commercial judgement. The client's original pay link stays alive and collects exactly the balance.</div></div>`;
},

// ============ INVOICES ============
async invoices() {
  const d = await api('invoices');
  P.innerHTML = `<div class="card"><table><tr><th>Invoice</th><th>Date</th><th>Client</th><th>Subtotal</th><th>GST</th><th>Total</th><th>Status</th><th></th></tr>
  ${d.data.map(i => `<tr><td><b>${esc(i.number)}</b></td><td class="mini">${i.date?.slice(0,10)}</td>
  <td>${esc(i.tenant?.company_name)}</td><td>${fmtMoney(i.subtotal, i.currency)}</td>
  <td class="mini">${fmtMoney(i.gst_amount, i.currency)} (${i.gst_rate}%)</td>
  <td><b>${fmtMoney(i.total, i.currency)}</b></td>
  <td>${i.status==='issued' && i.due_date ? `<span class="pill p-warn">DUE</span><div class="mini">by ${i.due_date.slice(0,10)}</div>` : pill(i.status)}</td>
  <td><a class="link" href="/admin/invoices/${i.id}/print" target="_blank">Print</a></td></tr>`).join('') || '<tr><td colspan="8" class="mini">No invoices yet</td></tr>'}</table></div>`;
},

// ============ STORAGE ============
async storage() {
  if (CAN_WRITE) ACTIONS.innerHTML = '<button class="btn btn-p" onclick="recordStorage()">+ Record Usage</button>';
  const month = new Date().toISOString().slice(0,7);
  const d = await api('storage?month=' + month);
  P.innerHTML = `<div class="card"><h3>Cloud Storage — ${d.month} <span class="mini">(₹3/GB to 500 GB · ₹2.50 to 2 TB · ₹2 above · min 50 GB)</span></h3>
  <table><tr><th>Cloud Tenant</th><th>Avg GB (month)</th><th>Billable GB</th><th>Monthly Charge</th></tr>
  ${d.tenants.map(t => `<tr><td><b>${esc(t.company_name)}</b></td><td>${t.avg_gb}</td><td>${t.billable_gb}</td><td><b>${t.monthly_charge ? fmtMoney(t.monthly_charge) : '—'}</b></td></tr>`).join('') || '<tr><td colspan="4" class="mini">No cloud tenants</td></tr>'}</table>
  <div class="mini" style="margin-top:10px">Automatic daily metering arrives with the Phase-3 cloud platform — until then usage can be recorded manually or imported.</div></div>`;
},

// ============ LANDING CMS (master prompt §5) ============
async cms() {
  const s = await api('settings');
  const f = (k, label, ph='') => `<label>${label}</label><input id="set_${k}" value="${esc(s[k]||'')}" placeholder="${esc(ph)}">`;
  P.innerHTML = `
  <div class="card"><h3>Hero &amp; announcement — leave a field blank to keep the landing page's built-in text</h3>
  ${f('landing_hero_title','Hero headline','e.g. Know exactly how your teams work — without standing behind them')}
  ${f('landing_hero_subtitle','Hero sub-headline','one persuasive sentence under the headline')}
  ${f('landing_announcement','Announcement bar (offers/news — blank hides it)','e.g. Diwali offer: 25% off annual plans with code DIWALI25')}</div>
  <div class="card"><h3>Contact &amp; lead alerts</h3><div style="display:grid;grid-template-columns:1fr 1fr;gap:0 16px">
  ${f('landing_contact_phone','Contact phone shown on the site','+91 96666 12424')}
  ${f('landing_contact_email','Contact email shown on the site','sales@ametecsindia.com')}
  ${f('whatsapp_number','WhatsApp number (all buttons)','919000098877')}
  ${f('sales_email','Lead alerts go to (email)','sales@ametecsindia.com')}</div></div>
  <div class="card"><h3>Testimonials (JSON list)</h3>
  <label>Format: [{"quote":"…","name":"…","role":"…"}] — blank keeps built-in testimonials</label>
  <textarea id="set_landing_testimonials" rows="5" style="font-family:monospace;font-size:12px">${esc(s.landing_testimonials||'')}</textarea>
  <div class="mini" style="margin-top:6px">Tip: keep 2–4 short quotes with Indian names and concrete outcomes ("caught 3 idle hours/day across 40 PCs").</div></div>
  <button class="btn btn-p" onclick="saveSettings().then(()=>toast('Saved — the public landing picks it up on next refresh'))">Save Landing Content</button>`;
},

// ============ SETTINGS ============
async settings() {
  const s = await api('settings');
  const f = (k, label, type='text') => `<label>${label}</label><input id="set_${k}" type="${type}" value="${esc(s[k]||'')}">`;
  P.innerHTML = `
  <div class="card"><h3>Company & Tax</h3><div class="modal-body row" style="display:grid;grid-template-columns:1fr 1fr;gap:0 16px">
  ${f('company_name','Company name')}${f('company_gstin','GSTIN')}${f('company_phone','Phone')}${f('company_email','Email')}
  ${f('gst_rate','GST rate %')}${f('whatsapp_number','WhatsApp number')}${f('invoice_prefix','Invoice prefix (EPT → EPT-2026-27-07-0001)')}${f('quote_prefix','Quote prefix (EPT-Q)')}${f('order_prefix','Order number prefix')}</div>
  <label>Registered address</label><textarea id="set_company_address" rows="2">${esc(s.company_address||'')}</textarea></div>
  <div class="card"><h3>Razorpay (INR) — test keys work; paste live keys when ready</h3><div style="display:grid;grid-template-columns:1fr 1fr;gap:0 16px">
  ${f('razorpay_key_id','Key ID (rzp_test_… / rzp_live_…)')}${f('razorpay_key_secret','Key Secret','password')}${f('razorpay_webhook_secret','Webhook Secret','password')}</div>
  <div class="mini">Webhook URL for the Razorpay dashboard: <b>${location.origin}/webhooks/razorpay</b> · event: payment.captured</div></div>
  <div class="card"><h3>Stripe (USD international)</h3><div style="display:grid;grid-template-columns:1fr 1fr;gap:0 16px">
  ${f('stripe_publishable_key','Publishable key')}${f('stripe_secret_key','Secret key','password')}${f('stripe_webhook_secret','Webhook signing secret','password')}</div>
  <div class="mini">Webhook URL: <b>${location.origin}/webhooks/stripe</b> · event: checkout.session.completed</div></div>
  <button class="btn btn-p" onclick="saveSettings()">Save Settings</button>`;
},

// ============ AUDIT ============
async audit() {
  const d = await api('audit');
  P.innerHTML = `<div class="card"><table><tr><th>When</th><th>Who</th><th>Action</th><th>Subject</th><th>Details</th></tr>
  ${d.data.map(a => `<tr><td class="mini">${new Date(a.created_at).toLocaleString()}</td>
  <td>${esc(a.admin_user?.name || 'system')}</td><td><b>${esc(a.action)}</b></td>
  <td class="mini">${esc((a.subject_type||'').split('\\\\').pop())} #${a.subject_id||''}</td>
  <td class="mini">${esc(JSON.stringify(a.meta||{}).slice(0,80))}</td></tr>`).join('')}</table></div>`;
},
};

// ---------- tenants helpers ----------
async function loadTenants() {
  const q = document.getElementById('tq').value, st = document.getElementById('tst').value;
  const d = await api(`tenants?q=${encodeURIComponent(q)}&status=${st}`);
  document.getElementById('tlist').innerHTML = `<div class="card"><table>
  <tr><th>Company</th><th>Contact</th><th>Deployment</th><th>Plan</th><th>Status</th><th></th></tr>
  ${d.data.map(t => `<tr><td><b>${esc(t.company_name)}</b><div class="mini">${esc(t.email)}</div></td>
  <td>${esc(t.contact_name||'—')}<div class="mini">${esc(t.phone||'')}</div></td>
  <td>${t.deployment === 'cloud' ? '<span class="pill p-info">SmartEPT Cloud</span>' : '<span class="pill p-mut">Client-hosted</span>'}${t.ecosystem_customer?' <span class="pill p-warn">Ecosystem</span>':''}</td>
  <td class="mini">${esc(t.active_licence?.plan?.name || '—')}</td><td>${pill(t.status)}</td>
  <td><button class="link" onclick="showTenant(${t.id})">Open</button></td></tr>`).join('') || '<tr><td colspan="6" class="mini">No clients found</td></tr>'}</table></div>`;
}

async function showTenant(id) {
  const t = await api('tenants/' + id);
  openModal(`<h2>${esc(t.company_name)} ${pill(t.status)}</h2>
  <div class="sub">${esc(t.contact_name||'')} · ${esc(t.email)} · ${esc(t.phone||'')} · GSTIN: ${esc(t.gstin||'—')}<br>
  ${t.deployment==='cloud'?'SmartEPT-Managed Cloud':'Client-Hosted'} · ${esc(t.currency)} · Setup fee: ${t.setup_fee_paid?'<span class="gain">paid ✓</span>':'not yet charged'}${t.deployment==='cloud'?'<br>Console: '+(t.console_url?`<a class="link" target="_blank" href="${esc(t.console_url)}">${esc(t.console_url)}</a>`:'<span style="color:#C77">not provisioned — set it via Edit</span>'):''}</div>
  <h4 style="color:var(--deep);margin:10px 0 6px">Licences</h4>
  <table><tr><th>Key</th><th>Plan</th><th>Kind</th><th>Devices</th><th>Expires</th><th>Status</th></tr>
  ${t.licences.map(l => `<tr><td class="mini">${esc(l.key)}</td><td>${esc(l.plan?.name)}</td><td>${esc(l.kind)}</td>
  <td>${(l.devices||[]).filter(d=>d.status==='active').length}/${l.device_limit}</td>
  <td class="mini">${l.expires_at ? l.expires_at.slice(0,10) : 'perpetual'}</td><td>${pill(l.status)}</td></tr>`).join('') || '<tr><td colspan="6" class="mini">No licences</td></tr>'}</table>
  <h4 style="color:var(--deep);margin:12px 0 6px">Recent Orders</h4>
  <table><tr><th>Order</th><th>Total</th><th>Status</th></tr>
  ${(t.orders||[]).slice(0,6).map(o => `<tr><td class="mini">${esc(o.number)} — ${esc(o.description)}</td><td>${fmtMoney(o.total,o.currency)}</td><td>${pill(o.status)}</td></tr>`).join('') || '<tr><td colspan="3" class="mini">None</td></tr>'}</table>
  <div class="foot">${CAN_WRITE?`<button class="btn btn-l" onclick='editTenant(${JSON.stringify(t).replace(/'/g,"&#39;")})'>Edit</button>
  <button class="btn btn-l" onclick="raiseSetup(${t.id}, ${JSON.stringify(t.company_name)})">Raise installation invoice</button>`:''}
  <button class="btn btn-p" onclick="closeModal()">Close</button></div>`, true);
}

function tenantForm(t = {}) {
  return `<div class="row">
  <div><label>Company name *</label><input id="f_company" value="${esc(t.company_name||'')}"></div>
  <div><label>Contact person</label><input id="f_contact" value="${esc(t.contact_name||'')}"></div>
  <div><label>Email *</label><input id="f_email" value="${esc(t.email||'')}"></div>
  <div><label>Phone</label><input id="f_phone" value="${esc(t.phone||'')}"></div>
  <div><label>GSTIN</label><input id="f_gstin" maxlength="15" placeholder="36AAHCT0971F1ZB" value="${esc(t.gstin||'')}"></div>
  <div><label>GST state code (place of supply)</label><input id="f_state" maxlength="2" placeholder="36 = Telangana" value="${esc(t.state_code||'')}"></div>
  <div><label>Currency</label><select id="f_currency"><option ${t.currency==='INR'?'selected':''}>INR</option><option ${t.currency==='USD'?'selected':''}>USD</option></select></div>
  <div><label>Deployment</label><select id="f_deploy"><option value="client_hosted" ${t.deployment!=='cloud'?'selected':''}>Client-Hosted</option><option value="cloud" ${t.deployment==='cloud'?'selected':''}>SmartEPT Cloud</option></select></div>
  <div><label>Ecosystem customer (SmartDCM/PRS)</label><select id="f_eco"><option value="0" ${!t.ecosystem_customer?'selected':''}>No</option><option value="1" ${t.ecosystem_customer?'selected':''}>Yes — ₹39 intro eligible</option></select></div>
  </div><label>Hosted console URL <span style="font-weight:400;color:#7A8B90">(SmartEPT-Cloud clients only — the address of their Ametecs-hosted admin console; the client portal links to it)</span></label>
  <input id="f_console" placeholder="https://client.smartept.cloud/admin" value="${esc(t.console_url||'')}">
  <label>Address</label><textarea id="f_addr" rows="2">${esc(t.address||'')}</textarea>
  <label>Billing address (printed on GST invoices — leave blank to use address above)</label><textarea id="f_billaddr" rows="2">${esc(t.billing_address||'')}</textarea>
  <label>Notes</label><textarea id="f_notes" rows="2">${esc(t.notes||'')}</textarea>`;
}
function readTenantForm() {
  return { company_name: f_company.value, contact_name: f_contact.value, email: f_email.value,
    phone: f_phone.value, gstin: f_gstin.value, state_code: f_state.value, currency: f_currency.value,
    deployment: f_deploy.value, ecosystem_customer: f_eco.value === '1',
    console_url: f_console.value || null,
    address: f_addr.value, billing_address: f_billaddr.value, notes: f_notes.value };
}
function newTenant() {
  openModal(`<h2>New Client</h2><div class="sub">Create the client record — then issue a licence or start a trial.</div>
  ${tenantForm()}<label>Start 7-day trial immediately?</label>
  <select id="f_trial"><option value="0">No</option><option value="1">Yes — provision trial licence (10 devices)</option></select>
  <div class="foot"><button class="btn btn-l" onclick="closeModal()">Cancel</button>
  <button class="btn btn-p" onclick="saveTenant()">Create Client</button></div>`, true);
}
async function saveTenant() {
  try {
    const body = readTenantForm();
    body.start_trial = f_trial.value === '1';
    await api('tenants', {method:'POST', body});
    closeModal(); toast('Client created'); loadTenants();
  } catch (e) { toast('Error: ' + e); }
}
async function editTenant(t) {
  openModal(`<h2>Edit — ${esc(t.company_name)}</h2>${tenantForm(t)}
  <label>Status</label><select id="f_status">${['trial','active','suspended','expired','churned'].map(s=>`<option ${t.status===s?'selected':''}>${s}</option>`).join('')}</select>
  <div class="foot"><button class="btn btn-l" onclick="closeModal()">Cancel</button>
  <button class="btn btn-p" onclick="updateTenant(${t.id})">Save</button></div>`, true);
}
async function updateTenant(id) {
  try {
    await api('tenants/' + id, {method:'PUT', body: {...readTenantForm(), status: f_status.value}});
    closeModal(); toast('Client updated'); loadTenants();
  } catch (e) { toast('Error: ' + e); }
}

// ---------- licences helpers ----------
async function loadLicences() {
  const d = await api(`licences?q=${encodeURIComponent(lq.value)}&status=${lst.value}&kind=${lkind.value}`);
  document.getElementById('llist').innerHTML = `<div class="card"><table>
  <tr><th>Key</th><th>Client</th><th>Plan</th><th>Kind</th><th>Devices</th><th>Expires</th><th>Status</th><th></th></tr>
  ${d.data.map(l => `<tr><td class="mini"><b>${esc(l.key)}</b></td><td>${esc(l.tenant?.company_name)}</td>
  <td>${esc(l.plan?.name)}</td><td>${esc(l.kind)}${l.kind==='perpetual'?`<div class="mini">AMC: ${l.amc_expires_at?l.amc_expires_at.slice(0,10):'lapsed'}</div>`:''}</td>
  <td>${l.active_devices_count}/${l.device_limit}</td>
  <td class="mini">${l.expires_at ? l.expires_at.slice(0,10) : '—'}</td><td>${pill(l.status)}</td>
  <td>${CAN_WRITE ? `<button class="link" onclick="licAction(${l.id},'renew')">Renew</button>
  ${l.status==='active'?`<button class="link" onclick="licAction(${l.id},'suspend')">Suspend</button>`:`<button class="link" onclick="licAction(${l.id},'resume')">Resume</button>`}
  ${l.kind==='perpetual'?`<button class="link" onclick="licAction(${l.id},'renew_amc')">Renew AMC</button>`:''}` : ''}</td></tr>`).join('') || '<tr><td colspan="8" class="mini">No licences</td></tr>'}</table></div>`;
}
async function licAction(id, action) {
  if (action === 'revoke' && !confirm('Revoke this licence permanently?')) return;
  try { await api(`licences/${id}/action`, {method:'POST', body:{action}}); toast('Licence ' + action + ' done'); loadLicences(); }
  catch (e) { toast('Error: ' + e); }
}
async function issueLicence() {
  const tenants = await api('tenants?status=');
  openModal(`<h2>Issue Licence</h2><div class="sub">Direct issue without an order — use Orders for the full payment flow.</div>
  <label>Client</label><select id="il_tenant">${tenants.data.map(t=>`<option value="${t.id}">${esc(t.company_name)}</option>`).join('')}</select>
  <div class="row"><div><label>Plan</label><select id="il_plan"><option value="core">Core</option><option value="professional" selected>Professional</option><option value="enterprise">Enterprise</option></select></div>
  <div><label>Kind</label><select id="il_kind"><option>subscription</option><option>perpetual</option><option>trial</option></select></div>
  <div><label>Billing period</label><select id="il_billing"><option value="annual">Annual — 12 months (best price, 25% off base)</option><option value="half_yearly">Half-yearly — 6 months (10% off base)</option><option value="quarterly">Quarterly — 3 months (base rate)</option></select></div>
  <div><label>Deployment</label><select id="il_deploy"><option value="client_hosted">Client-Hosted</option><option value="cloud">Cloud</option></select></div>
  <div><label>Device limit</label><input id="il_devices" type="number" value="10" min="1"></div></div>
  <div class="foot"><button class="btn btn-l" onclick="closeModal()">Cancel</button>
  <button class="btn btn-p" onclick="doIssue()">Issue Licence</button></div>`);
}
async function doIssue() {
  try {
    const l = await api('licences', {method:'POST', body:{tenant_id:+il_tenant.value, plan_code:il_plan.value,
      kind:il_kind.value, billing:il_billing.value, deployment:il_deploy.value, device_limit:+il_devices.value}});
    closeModal(); toast('Licence issued: ' + l.key); go('licences');
  } catch (e) { toast('Error: ' + e); }
}

// ---------- orders helpers ----------
async function loadOrders() {
  const d = await api(`orders?status=${ost.value}&gateway=${ogw.value}`);
  document.getElementById('olist').innerHTML = `<div class="card"><table>
  <tr><th>Order</th><th>Client</th><th>Description</th><th>Total</th><th>Gateway</th><th>Status</th><th></th></tr>
  ${d.data.map(o => `<tr><td><b>${esc(o.quote_number || o.number)}</b><div class="mini">${o.quote_number ? esc(o.number) + ' · ' : ''}${new Date(o.created_at).toLocaleDateString()}${o.requested_by ? '<br>req: ' + esc(o.requested_by) : ''}</div></td>
  <td>${esc(o.tenant?.company_name)}</td><td class="mini">${esc(o.description)}</td>
  <td><b>${fmtMoney(o.total, o.currency)}</b><div class="mini">incl. tax ${fmtMoney(o.tax_amount, o.currency)}</div>
  ${o.provisioned_at && o.status!=='paid' && o.balance>0 ? `<div class="mini" style="color:var(--danger);font-weight:700">balance ${fmtMoney(o.balance, o.currency)}${o.credit_due_date?' by '+o.credit_due_date.slice(0,10):''}</div>`:''}</td>
  <td class="mini">${esc(o.gateway)}${o.manual_method?` (${esc(o.manual_method)})`:''}</td>
  <td>${pill(o.status)}${o.provisioned_at && o.status!=='paid' ? '<div class="mini" style="color:var(--warn);font-weight:700">on credit ✓ live</div>':''}</td>
  <td>${o.status==='quote' && CAN_WRITE ? `<a class="link" href="/admin/orders/${o.id}/quote-print" target="_blank">Print Quote</a>
  <button class="link" onclick="approveQuote(${o.id})">Approve</button>
  <button class="link" onclick="markPaid(${o.id},'${esc(o.quote_number||o.number)}',${o.balance ?? o.total})">Record Payment</button>` :
  o.status==='created' && CAN_WRITE ? `${o.provisioned_at && o.balance>0
    ? `<button class="link" onclick="recordBalance(${o.id}, ${o.balance}, '${esc(o.tenant?.company_name)}')">Record balance</button>`
    : `<button class="link" onclick="markPaid(${o.id},'${esc(o.number)}',${o.balance ?? o.total})">Record Payment</button>`}
  <button class="link" onclick="copyPayLink('${esc(o.number)}')">Pay Link</button>${o.quote_number?`<a class="link" href="/admin/orders/${o.id}/quote-print" target="_blank">Quote</a>`:''}` :
  (o.invoice?`<a class="link" href="/admin/invoices/${o.invoice.id}/print" target="_blank">Invoice</a>`:'')}</td></tr>`).join('') || '<tr><td colspan="7" class="mini">No orders</td></tr>'}</table></div>`;
}
async function approveQuote(id) {
  try { await api(`orders/${id}/approve-quote`, {method:'POST'}); toast('Quotation approved — now payable'); loadOrders(); }
  catch (e) { toast('Error: ' + e); }
}
async function copyPayLink(number) {
  toast('Payment link: ' + location.origin + '/pay/' + number + '/<token> — token shown in order meta (or share from the client record)');
}
async function newOrder() {
  const tenants = await api('tenants?status=');
  openModal(`<h2>New Order / Quote</h2><div class="sub">Live quote updates as you type — includes the one-time Setup &amp; Onboarding fee automatically on a client's first paid order.</div>
  <label>Client</label><select id="no_tenant" onchange="refreshQuote()">${tenants.data.map(t=>`<option value="${t.id}">${esc(t.company_name)}</option>`).join('')}</select>
  <div class="row">
  <div><label>Plan</label><select id="no_plan" onchange="refreshQuote()"><option value="core">Core</option><option value="professional" selected>Professional</option><option value="enterprise">Enterprise</option></select></div>
  <div><label>Kind</label><select id="no_kind" onchange="refreshQuote()"><option value="subscription">Subscription</option><option value="perpetual">Perpetual</option></select></div>
  <div><label>Billing period</label><select id="no_billing" onchange="refreshQuote()"><option value="annual">Annual — 12 months (25% off base)</option><option value="half_yearly">Half-yearly — 6 months (10% off base)</option><option value="quarterly">Quarterly — 3 months (base rate)</option></select></div>
  <div><label>Deployment</label><select id="no_deploy" onchange="refreshQuote()"><option value="client_hosted">Client-Hosted</option><option value="cloud">SmartEPT Cloud</option></select></div>
  <div><label>Devices</label><input id="no_devices" type="number" value="25" min="1" oninput="refreshQuote()"></div>
  <div><label>Create as</label><select id="no_asquote"><option value="0">Order — payable now</option><option value="1">Quotation — management pays later</option></select></div>
  <div><label>Requested by (manager/employee)</label><input id="no_reqby" placeholder="e.g. Rajesh Kumar, Ops Manager"></div>
  <div><label>Coupon code (optional)</label><input id="no_coupon" placeholder="e.g. DIWALI25" style="text-transform:uppercase" onchange="refreshQuote()"></div></div>
  <div class="quote-box" id="quoteBox">…</div>
  <div class="mini" id="couponNote" style="margin-top:6px"></div>
  <div class="foot"><button class="btn btn-l" onclick="closeModal()">Cancel</button>
  <button class="btn btn-p" onclick="createOrder()">Create Order</button></div>`, true);
  refreshQuote();
}
async function refreshQuote() {
  try {
    const coupon = (document.getElementById('no_coupon')?.value || '').trim().toUpperCase() || null;
    const q = await api('quote', {method:'POST', body:{tenant_id:+no_tenant.value, plan_code:no_plan.value,
      devices:+no_devices.value, kind:no_kind.value, billing:no_billing.value, deployment:no_deploy.value,
      coupon_code:coupon}});
    document.getElementById('quoteBox').innerHTML = q.lines.map(l =>
      `<div class="ln"><span>${esc(l.description)}</span><b>${fmtMoney(l.amount, q.currency)}</b></div>`).join('') +
      `<div class="ln"><span>GST ${q.gst_rate}%</span><b>${fmtMoney(q.tax, q.currency)}</b></div>
      <div class="ln tt"><span>Total</span><span>${fmtMoney(q.total, q.currency)}</span></div>`;
    const note = document.getElementById('couponNote');
    if (note) note.innerHTML = !coupon ? '' : (q.coupon?.ok
      ? '<span style="color:var(--ok);font-weight:700">✓ Coupon ' + esc(q.coupon.code) + ' applied — saves ' + fmtMoney(q.coupon.discount, q.currency) + ' (locked into a quotation even if the code expires later)</span>'
      : '<span style="color:var(--danger);font-weight:700">✗ Coupon not applied: ' + esc(q.coupon?.reason || 'not valid') + '</span>');
  } catch (e) { document.getElementById('quoteBox').textContent = 'Quote error: ' + e; }
}
async function createOrder() {
  try {
    const o = await api('orders', {method:'POST', body:{tenant_id:+no_tenant.value, plan_code:no_plan.value,
      devices:+no_devices.value, kind:no_kind.value, billing:no_billing.value, deployment:no_deploy.value,
      as_quote:no_asquote.value==='1', requested_by:no_reqby.value||null,
      coupon_code:(document.getElementById('no_coupon')?.value || '').trim().toUpperCase() || null}});
    closeModal(); toast((o.quote_number?'Quotation created: '+o.quote_number:'Order created: '+o.number)); go('orders');
  } catch (e) { toast('Error: ' + e); }
}
async function raiseSetup(tenantId, company) {
  openModal(`<h2>Installation &amp; Onboarding invoice</h2>
  <div class="sub">For <b>${esc(company)}</b>. Use this when a client did not buy setup up front and now wants Ametecs to install &amp; onboard. It creates a standalone invoice (one-time ₹5,000 for 25 devices + ₹100 per extra) with a pay link you can send.</div>
  <label>Number of devices to install</label><input id="su_dev" type="number" min="1" value="25">
  <label style="display:flex;align-items:center;gap:8px;margin-top:8px"><input type="checkbox" id="su_quote" style="width:auto"> Raise as a quotation first (management approves before payment)</label>
  <div class="foot"><button class="btn btn-l" onclick="closeModal()">Cancel</button>
  <button class="btn btn-p" onclick="doRaiseSetup(${tenantId})">Create invoice &amp; get pay link</button></div>`);
}
async function doRaiseSetup(tenantId) {
  try {
    const r = await api('setup-invoice', {method:'POST', body:{tenant_id:tenantId, devices:+document.getElementById('su_dev').value, as_quote:document.getElementById('su_quote').checked}});
    const o = r.order, url = r.pay_url;
    openModal(`<h2>Installation invoice created</h2>
    <div class="sub">${esc(o.number)}${o.quote_number?' · '+esc(o.quote_number):''} — total ${fmtMoney(o.total,o.currency)} (incl. GST)</div>
    <label>Send this pay link to the client</label>
    <div style="display:flex;gap:8px;align-items:center"><input id="su_link" value="${esc(url)}" readonly style="flex:1"><button class="btn btn-l" onclick="navigator.clipboard.writeText(document.getElementById('su_link').value);toast('Link copied')">Copy</button></div>
    <div style="margin-top:10px"><a class="btn btn-l" target="_blank" href="https://wa.me/?text=${encodeURIComponent('Your SmartEPT installation & onboarding invoice '+o.number+': '+url)}">Send on WhatsApp</a></div>
    <div class="foot"><button class="btn btn-p" onclick="closeModal();go('orders')">Done</button></div>`);
  } catch (e) { toast('Error: ' + e); }
}
async function markPaid(id, number, balance) {
  openModal(`<h2>Record Payment — ${esc(number)}</h2>
  <div class="sub">For money received directly (NEFT / UPI / cheque / cash) — or to provision a trusted client on CREDIT. Any choice below activates the licence and issues the GST invoice <b>immediately</b>; the invoice shows DUE until the balance reaches zero.</div>
  <label>Payment received</label>
  <select id="mp_status" onchange="mpStatusUi()">
    <option value="paid">Paid in full — ${fmtMoney(balance||0)}</option>
    <option value="partial">Partial — part now, balance on credit</option>
    <option value="due">Due — whole amount on credit (₹0 now)</option>
  </select>
  <div id="mp_amt_row" style="display:none"><label>Amount received now (₹)</label><input id="mp_amount" type="number" min="1" step="0.01" placeholder="e.g. 20000"></div>
  <div id="mp_pay_rows"><div class="row">
    <div><label>Method</label><select id="mp_method"><option>NEFT</option><option>UPI</option><option>cheque</option><option>cash</option><option>other</option></select></div>
    <div><label>Reference (UTR / cheque no.)</label><input id="mp_ref" placeholder="e.g. UTR 12345678"></div>
  </div></div>
  <div id="mp_due_row" style="display:none"><label>Balance payable by (credit due date)</label><input id="mp_due" type="date"></div>
  <div class="foot"><button class="btn btn-l" onclick="closeModal()">Cancel</button>
  <button class="btn btn-p" onclick="doMarkPaid(${id})">Save &amp; provision</button></div>`);
}
function mpStatusUi() {
  const s = document.getElementById('mp_status').value;
  document.getElementById('mp_amt_row').style.display = s === 'partial' ? '' : 'none';
  document.getElementById('mp_due_row').style.display = s === 'paid' ? 'none' : '';
  document.getElementById('mp_pay_rows').style.display = s === 'due' ? 'none' : '';
}
async function doMarkPaid(id) {
  const s = document.getElementById('mp_status').value;
  const body = {payment_status: s};
  if (s !== 'due') { body.manual_method = mp_method.value; body.manual_reference = mp_ref.value; }
  if (s === 'partial') body.amount = +document.getElementById('mp_amount').value;
  if (s !== 'paid') body.credit_due_date = document.getElementById('mp_due').value || null;
  try {
    if (s === 'partial' && !(body.amount > 0)) throw 'Enter the amount received now.';
    if (s !== 'paid' && !body.credit_due_date) throw 'Pick the credit due date — the balance needs a payable-by date.';
    await api(`orders/${id}/mark-paid`, {method:'POST', body});
    closeModal();
    toast(s === 'paid' ? 'Payment recorded — licence active, invoice issued, receipt emailed'
      : 'Provisioned on credit — licence active, invoice issued as DUE. Track it in Credit Clients.');
    go('orders');
  } catch (e) { toast('Error: ' + e); }
}
async function recordBalance(id, balance, company) {
  openModal(`<h2>Record balance — ${esc(company || '')}</h2>
  <div class="sub">Outstanding balance: <b>${fmtMoney(balance)}</b>. Record an offline instalment here — the receipt and PAID invoice go out automatically the moment the balance reaches zero.</div>
  <div class="row">
    <div><label>Amount received (₹)</label><input id="rb_amount" type="number" min="1" step="0.01" value="${balance}"></div>
    <div><label>Method</label><select id="rb_method"><option>NEFT</option><option>UPI</option><option>cheque</option><option>cash</option><option>other</option></select></div>
  </div>
  <label>Reference (UTR / cheque no.)</label><input id="rb_ref" placeholder="e.g. UTR 12345678">
  <div class="foot"><button class="btn btn-l" onclick="closeModal()">Cancel</button>
  <button class="btn btn-p" onclick="doRecordBalance(${id})">Record instalment</button></div>`);
}
async function doRecordBalance(id) {
  try {
    const r = await api(`orders/${id}/record-balance`, {method:'POST', body:{
      amount:+document.getElementById('rb_amount').value,
      manual_method:document.getElementById('rb_method').value,
      manual_reference:document.getElementById('rb_ref').value}});
    closeModal();
    toast(r.settled ? 'Balance cleared — invoice PAID, receipt emailed 🎉' : 'Instalment recorded — balance now ' + fmtMoney(r.balance));
    go(PAGE);
  } catch (e) { toast('Error: ' + e); }
}

// ---------- trials helpers ----------
async function extendTrial(id) {
  const days = prompt('Extend trial by how many days? (1–30)', '7');
  if (!days) return;
  try { await api(`trials/${id}/extend`, {method:'POST', body:{days:+days}}); toast('Trial extended'); go('trials'); }
  catch (e) { toast('Error: ' + e); }
}
function convertTrial(id, name) {
  go('orders'); setTimeout(() => { newOrder().then(() => { no_tenant.value = id; refreshQuote(); }); }, 300);
}

// ---------- storage helpers ----------
async function recordStorage() {
  const tenants = await api('tenants?status=');
  openModal(`<h2>Record Storage Usage</h2><div class="sub">Manual entry until Phase-3 automatic metering.</div>
  <label>Cloud tenant</label><select id="su_tenant">${tenants.data.filter(t=>t.deployment==='cloud').map(t=>`<option value="${t.id}">${esc(t.company_name)}</option>`).join('')}</select>
  <div class="row"><div><label>Date</label><input id="su_date" type="date" value="${new Date().toISOString().slice(0,10)}"></div>
  <div><label>GB used</label><input id="su_gb" type="number" step="0.1" min="0" value="50"></div></div>
  <div class="foot"><button class="btn btn-l" onclick="closeModal()">Cancel</button>
  <button class="btn btn-p" onclick="doRecordStorage()">Save</button></div>`);
}
async function doRecordStorage() {
  try {
    await api('storage', {method:'POST', body:{tenant_id:+su_tenant.value, date:su_date.value, gb_used:+su_gb.value}});
    closeModal(); toast('Usage recorded'); go('storage');
  } catch (e) { toast('Error: ' + e); }
}

// ---------- settings / plans ----------
async function saveSettings() {
  const body = {};
  document.querySelectorAll('[id^=set_]').forEach(el => body[el.id.slice(4)] = el.value);
  try { await api('settings', {method:'PUT', body}); toast('Settings saved'); }
  catch (e) { toast('Error: ' + e); }
}
function editPlan(p) {
  openModal(`<h2>Edit ${esc(p.name)}</h2><div class="row">
  <div><label>INR annual /device/mo</label><input id="ep_ia" type="number" value="${p.inr_annual}"></div>
  <div><label>INR monthly</label><input id="ep_im" type="number" value="${p.inr_monthly}"></div>
  <div><label>USD annual</label><input id="ep_ua" type="number" step="0.01" value="${p.usd_annual}"></div>
  <div><label>USD monthly</label><input id="ep_um" type="number" step="0.01" value="${p.usd_monthly}"></div>
  <div><label>Perpetual / device ₹</label><input id="ep_pd" type="number" value="${p.perpetual_device_inr}"></div>
  <div><label>Server licence ₹</label><input id="ep_ps" type="number" value="${p.perpetual_server_inr}"></div>
  <div><label>Min devices</label><input id="ep_md" type="number" value="${p.min_devices}"></div></div>
  <div class="foot"><button class="btn btn-l" onclick="closeModal()">Cancel</button>
  <button class="btn btn-p" onclick="savePlan(${p.id})">Save</button></div>`);
}
async function savePlan(id) {
  try {
    await api('plans/' + id, {method:'PUT', body:{inr_annual:+ep_ia.value, inr_monthly:+ep_im.value,
      usd_annual:+ep_ua.value, usd_monthly:+ep_um.value, perpetual_device_inr:+ep_pd.value,
      perpetual_server_inr:+ep_ps.value, min_devices:+ep_md.value}});
    closeModal(); toast('Plan updated'); go('plans');
  } catch (e) { toast('Error: ' + e); }
}

// ---------- ⓘ HELP (3-tab, per screen) ----------
const HELP = {
dashboard: {
 use: `<h4>What is this screen for?</h4><p>Your business at a glance — clients, money, licences and what needs attention today.</p>
 <h4>What you can do here</h4><p>Monitor revenue this month, estimated MRR, trials expiring within 7 days and unpaid orders.</p>
 <ol><li>Check <b>Needs Attention</b> first every morning</li><li>Open <b>Trials</b> to follow up expiring evaluations</li><li>Open <b>Orders</b> to chase unpaid orders</li></ol>
 <div class="tip"><b>Good to know:</b> MRR counts annual contracts divided by 12 — it is an estimate for trend-watching, not your GST turnover.</div>
 <h4>Who can use it</h4><p>Super Admin · Sales · Support (read-only)</p>`,
 why: `<h4>Why this exists</h4><p>A licensing business dies quietly when trials expire un-followed and invoices sit unpaid. This screen makes silence impossible.</p>
 <div class="scen"><b>Picture this:</b> Monday 9:15 AM. Dashboard shows 3 trials expiring this week — one is Krishna NBFC with 40 potential devices (₹49 × 40 = ₹1,960/month). Your sales call converts them before Friday. That one glance was worth ₹23,520 a year.</div>
 <p class="gain">✓ Never lose a trial silently ✓ Cash follow-up same day ✓ One screen for the whole business</p>`,
 right: `<h4>Mistake → Impact → Right way</h4>
 <p>❌ <b>Checking only revenue.</b> → Trials quietly expire. → ✅ Treat "Needs Attention" as your daily to-do list.</p>
 <p>❌ <b>Reading MRR as turnover.</b> → Wrong GST expectations. → ✅ Use Invoices for accounting figures.</p>`,
},
tenants: {
 use: `<h4>What is this screen for?</h4><p>The master register of every SmartEPT customer — client-hosted and cloud.</p>
 <ol><li>Press <b>+ New Client</b> and fill company, contact, GSTIN</li><li>Choose deployment: Client-Hosted or SmartEPT Cloud</li><li>Mark <b>Ecosystem customer</b> if they already use SmartDCM/SmartPRS (₹39 intro rate applies automatically)</li><li>Optionally start a 7-day trial immediately</li><li>Open any client to see licences, devices, orders, invoices in one place</li></ol>
 <div class="tip"><b>Good to know:</b> the one-time Setup &amp; Onboarding fee (₹5,000 up to 25 devices + ₹100/extra) is charged automatically on the client's FIRST paid order — the system tracks who has paid it.</div>`,
 why: `<h4>Why this exists</h4><p>Every licence, payment and support call hangs off the client record. Clean records here = clean invoices and easy renewals later.</p>
 <div class="scen"><b>Picture this:</b> ABC Recoveries calls about adding 20 devices. You open their record: Professional, 50 devices at ₹49 volume rate, setup fee already paid, renewal in March. You quote 70 devices × ₹49 = ₹3,430/month in ten seconds, no setup fee again.</div>
 <p class="gain">✓ One source of truth ✓ Instant quotes ✓ GSTIN captured for clean invoices</p>`,
 right: `<p>❌ <b>Skipping GSTIN at creation.</b> → Invoice corrections later. → ✅ Capture GSTIN on day one.</p>
 <p>❌ <b>Forgetting the Ecosystem flag.</b> → Customer misses ₹39 intro rate, feels cheated. → ✅ Ask "do you use SmartDCM or SmartPRS?" on every call.</p>
 <p>❌ <b>Deleting clients.</b> → Orphaned invoices. → ✅ Use status churned instead.</p>`,
},
trials: {
 use: `<h4>What is this screen for?</h4><p>Follow every 7-day evaluation from signup to conversion (or expiry).</p>
 <ol><li>Active trials are sorted by expiry — call the nearest first</li><li><b>Extend</b> adds days (max 30) when a genuine evaluation needs more time</li><li><b>Convert to Paid</b> opens a pre-filled order</li></ol>
 <div class="tip"><b>Good to know:</b> trial data auto-purges after the grace period (7 days post expiry) — extensions also move the purge date.</div>`,
 why: `<h4>Why this exists</h4><p>The framework rule is deliberate: no permanent free plan. That only works if every trial gets a decision — buy, extend, or expire.</p>
 <div class="scen"><b>Picture this:</b> Krishna NBFC's trial ends Thursday. You call Wednesday: "How did the Gate-to-PC test go?" They loved it but need MD sign-off — you extend 7 days instead of losing them to an expiry.</div>
 <p class="gain">✓ No trial dies unworked ✓ Extensions logged and auditable</p>`,
 right: `<p>❌ <b>Extending repeatedly without a reason.</b> → Free plan through the back door. → ✅ One extension, with a note.</p>
 <p>❌ <b>Converting without checking devices used.</b> → Underquoted deal. → ✅ Check actual device count in the client record first.</p>`,
},
licences: {
 use: `<h4>What is this screen for?</h4><p>Every licence key: status, devices used vs limit, expiry, AMC.</p>
 <ol><li><b>Issue Licence</b> for direct issues; use Orders for paid flow</li><li><b>Renew</b> extends by the billing period</li><li><b>Suspend / Resume</b> for payment disputes</li><li>Perpetual keys show AMC — <b>Renew AMC</b> after payment (18% of licence value)</li></ol>
 <div class="tip"><b>Good to know:</b> the product server phones home to validate the key — licence metadata ONLY travels; client operational data never leaves their server.</div>`,
 why: `<h4>Why this exists</h4><p>The licence key is the product's cash register. Device caps, feature flags and expiry all enforce what was actually paid for.</p>
 <div class="scen"><b>Picture this:</b> A customer paid for 50 devices but IT quietly installs 60. The 51st agent activation is refused with "device limit reached" — the customer calls, and it becomes a ₹5,880/year upsell instead of silent revenue leakage.</div>
 <p class="gain">✓ Zero over-deployment ✓ AMC renewals visible ✓ Clean suspend/resume for disputes</p>`,
 right: `<p>❌ <b>Revoking for a late payment.</b> → Angry customer, dead server. → ✅ Suspend first — it's reversible.</p>
 <p>❌ <b>Raising device limit as a favour.</b> → Unbilled seats forever. → ✅ Create an order for the difference, then raise.</p>`,
},
plans: {
 use: `<h4>What is this screen for?</h4><p>The commercial matrix: Core ₹29 / Professional ₹59 / Enterprise ₹99, volume tiers and feature entitlements.</p>
 <ol><li>Review rates and perpetual prices per plan</li><li>Super Admin can <b>Edit</b> rates — new orders use new rates immediately</li></ol>
 <div class="tip"><b>Good to know:</b> existing paid orders and active licences are NOT retro-changed by a price edit — only new quotes.</div>`,
 why: `<h4>Why this exists</h4><p>Prices live in the database, not in code — approved changes go live without a developer.</p>
 <div class="scen"><b>Picture this:</b> Management approves Professional at ₹69 from next quarter. You edit one field; the landing page calculator, quotes and checkout all follow.</div>`,
 right: `<p>❌ <b>Editing prices mid-negotiation.</b> → Quote confusion. → ✅ Create the order first (locks its price), then edit.</p>`,
},
orders: {
 use: `<h4>What is this screen for?</h4><p>Create quotations/orders and record payments — gateway, manual, or on credit.</p>
 <ol><li><b>+ New Order/Quote</b> → pick client, plan, devices (+ optional coupon) → live totals show licence + setup fee + discount + GST</li><li>Choose <b>Quotation</b> when a manager requests but MANAGEMENT pays later — quote number EPT-Q-2026-27-07-0001, printable, with the pay link printed on it</li><li>Management approves (or pays the link directly); <b>Record Payment</b> for NEFT/UPI/cheque offers three choices: <b>Paid</b> (full), <b>Partial</b> (part now, balance on credit) or <b>Due</b> (whole amount on credit)</li><li>ANY of the three provisions the licence and GST invoice immediately — credit clients appear in the <b>Credit Clients</b> screen until their balance is zero</li></ol>
 <div class="tip"><b>Good to know:</b> the Setup &amp; Onboarding fee appears automatically on a client's first paid order only. A coupon captured on a quotation is LOCKED into that quote — the pay link honours it even if the code expires meanwhile.</div>`,
 why: `<h4>Why this exists</h4><p>One golden automation: money in → licence out → invoice out. No manual steps to forget, no licence issued before payment.</p>
 <div class="scen"><b>Picture this:</b> Godavari Finserv pays ₹58,800 by NEFT at 4 PM. Accounts enters the UTR at 4:05. By 4:06 their licence key is active, the GST invoice is numbered, and their server validates on the next phone-home. Zero emails between departments.</div>
 <p class="gain">✓ No unpaid activations ✓ No paid-but-forgotten clients ✓ Every rupee has an invoice</p>`,
 right: `<p>❌ <b>Issuing the licence first, collecting later.</b> → Aging receivables. → ✅ Payment first; use a trial for evaluations.</p>
 <p>❌ <b>Recording manual payment without UTR.</b> → Unreconcilable books. → ✅ Always paste the reference.</p>`,
},
invoices: {
 use: `<h4>What is this screen for?</h4><p>Every GST invoice — numbered EPT-{FY}-{month}-{count}, e.g. EPT-2026-27-07-0001. The count runs <b>consecutively through the financial year</b> (GST style): July's last invoice 0009 → August starts at 0010; the series resets only on 1 April. One shared series covers subscription, renewal, upgrade and installation invoices.</p>
 <ol><li><b>Print</b> opens the branded invoice — use the browser's Save as PDF</li><li>Invoices issued on credit show <b>DUE</b> with the payable-by date, and flip to PAID automatically when the balance reaches zero</li><li>Series prefix and GST rate are configured in Settings</li></ol>`,
 why: `<h4>Why this exists</h4><p>Indian B2B buyers pay against proper GST invoices. Automatic, consecutive, FY-correct numbering keeps your CA happy and audits painless — a gap in the series is the first thing an auditor asks about.</p>
 <div class="scen"><b>Picture this:</b> your CA files GSTR-1 for July. Every EPT-2026-27 invoice is in one unbroken series across the year — Telangana buyers show CGST 9% + SGST 9%, others IGST 18%, exactly as the return needs. Zero manual reconciliation.</div>`,
 right: `<p>❌ <b>Manually editing invoice JSON.</b> → Broken audit trail. → ✅ Cancel and re-issue via a corrected order.</p>
 <p>❌ <b>Deleting an invoice to "fix" a number.</b> → A hole in a GST series. → ✅ Numbers are MAX+1 within the FY — deletions never cause duplicates, and cancelled invoices stay on record.</p>`,
},
credit: {
 use: `<h4>What is this screen for?</h4><p>Every client who was provisioned <b>before paying in full</b> — the credit book. Total, received, balance and the payable-by date, in one table. Overdue rows turn red.</p>
 <ol><li>Collections work both ways: <b>Record balance</b> for NEFT/UPI/cheque instalments you receive, or <b>Pay link</b> to resend the client's original link — it stays alive and collects exactly the balance online</li><li>The receipt and PAID invoice go out automatically the moment the balance reaches zero</li><li>Overdue (red) = your call list for the day</li></ol>
 <div class="tip"><b>Good to know:</b> nothing locks automatically on overdue — credit is a commercial judgement (Ejaz's standing rule). The licence stays live; the red row is your reminder to call.</div>
 <h4>Who can use it</h4><p>Super Admin · Sales record payments; Support sees the list read-only.</p>`,
 why: `<h4>Why this exists</h4><p>Real business does not always pay online first. Banks' recovery partners and NBFCs often need the software running while finance processes the payment — refusing credit loses the deal; forgetting credit loses the money. This table makes forgetting impossible.</p>
 <div class="scen"><b>Picture this:</b> Krishna NBFC signs for 50 devices — ₹40,000 quote. Their MD approves but accounts pays in two parts: ₹25,000 NEFT today, balance by 15 August. You record Partial ₹25,000 — their licence goes live the same minute, the invoice prints DUE ₹15,000 by 15-Aug. On 16 August the row turns red, you call, they pay the link. Receipt goes out on its own. Deal saved, money tracked, zero spreadsheets.</div>
 <p class="gain">✓ Deals close on the client's payment rhythm ✓ Every rupee outstanding is visible daily ✓ Receipts are automatic at zero</p>`,
 right: `<p>❌ <b>Provisioning on credit for a stranger.</b> → Bad debt. → ✅ Credit is for known/repeat clients — new names pay first or use the trial.</p>
 <p>❌ <b>Collecting an instalment and noting it in a diary.</b> → Books disagree with reality. → ✅ Record it here the same minute — the balance and invoice update themselves.</p>
 <p>❌ <b>Suspending a licence because a due date passed.</b> → Dead product at the client, angry MD. → ✅ Call first; suspension is a last resort you do manually and deliberately.</p>`,
},
cms: {
 use: `<h4>What is this screen for?</h4><p>Edit the public landing page's words — hero headline, announcement bar, contact details, testimonials — without touching code or calling a developer.</p>
 <ol><li>Type new text and press <b>Save Landing Content</b></li><li>Blank fields keep the landing page's built-in copy — you can never break the page</li><li>The announcement bar is perfect for festival offers ("25% off with DIWALI25") — blank hides it</li><li>Lead alerts email goes to whatever address you set here</li></ol>
 <div class="tip"><b>Good to know:</b> the landing page picks changes up within 5 minutes (or instantly on a hard refresh) — the content travels through the same live API as prices.</div>
 <h4>Who can use it</h4><p>Super Admin only.</p>`,
 why: `<h4>Why this exists</h4><p>Marketing must move at the owner's speed, not the developer's calendar. A festival campaign that needs a deploy is a campaign that launches late.</p>
 <div class="scen"><b>Picture this:</b> Wednesday 6 PM, management approves a Diwali push. You type the announcement line, create coupon DIWALI25, and share the ad on WhatsApp. The landing page is already carrying the offer before the tea gets cold.</div>`,
 right: `<p>❌ <b>Writing long paragraphs in the hero.</b> → Visitors bounce. → ✅ One sharp headline + one persuasive sentence.</p>
 <p>❌ <b>Leaving a finished offer in the announcement bar.</b> → Stale site, awkward calls. → ✅ Blank the field the day the offer ends.</p>`,
},
storage: {
 use: `<h4>What is this screen for?</h4><p>Cloud tenants' storage: average GB this month and the rental charge (₹3/GB slab pricing, 50 GB minimum).</p>
 <ol><li>Until Phase-3 automatic metering, <b>Record Usage</b> manually or via import</li><li>Monthly charge = average daily GB → rounded up → slab rates</li></ol>`,
 why: `<h4>Why this exists</h4><p>The framework promise is transparent storage billing — customers pay for what they keep, and you never subsidise hoarders.</p>
 <div class="scen"><b>Picture this:</b> Godavari keeps 210 GB average. Bill: 210 × ₹3 = ₹630/month, shown as its own invoice line. They shorten screenshot retention to 30 days; next month it's 140 GB = ₹420. They feel in control — because they are.</div>`,
 right: `<p>❌ <b>Billing peak-day usage.</b> → Disputes. → ✅ The system averages the month — record daily values honestly.</p>`,
},
settings: {
 use: `<h4>What is this screen for?</h4><p>Company details, GST rate, invoice series, and payment gateway keys.</p>
 <ol><li>Paste Razorpay keys (test rzp_test_… or live) — webhook URL shown below the fields</li><li>Paste Stripe keys for international USD</li><li>Saved secrets display masked — leaving a masked value unchanged keeps the old secret</li></ol>
 <div class="tip"><b>Good to know:</b> configure the webhook URLs in each gateway's dashboard, or payments will succeed but licences won't auto-activate.</div>`,
 why: `<h4>Why this exists</h4><p>Key rotation and price-of-business settings shouldn't need a developer or a deploy.</p>`,
 right: `<p>❌ <b>Sharing secret keys on WhatsApp/chat.</b> → Compromise risk. → ✅ Paste directly here, only over HTTPS, only Super Admin.</p>`,
},
leads: {
 use: `<h4>What is this screen for?</h4><p>Your sales pipeline: every enquiry from the website form, WhatsApp, referrals or walk-ins, with a status that moves NEW → CONTACTED → DEMO_SCHEDULED → QUOTED → WON/LOST.</p><ol><li>Change the status right in the row as the conversation progresses</li><li>Set a follow-up date — overdue follow-ups turn red and float to the top</li><li>Keep call notes on the lead so anyone can pick it up</li></ol>`,
 why: `<h4>Why this exists</h4><p>Leads that live in one person's WhatsApp die there. <b>Picture this:</b> Suresh from a Vijayawada NBFC fills the website form on Monday asking about 60 devices (₹28,000+ a year). Without a pipeline, that email sits unread over a busy week and he signs with a competitor. With Leads, sales@ gets the mail instantly, the lead shows NEW at the top, and Friday's follow-up date makes sure someone calls.</p><p>✔ No enquiry ever lost &nbsp;✔ Handovers survive leave days &nbsp;✔ WON converts to a Client with the story attached</p>`,
 right: `<p>❌ <b>Marking WON without creating the client.</b> → Pipeline says money that billing can't see. → ✅ On WON, immediately create the tenant from Clients and link it.</p><p>❌ <b>Leaving follow-up blank.</b> → "Someday" never comes. → ✅ Every open lead carries a date.</p>`,
},
coupons: {
 use: `<h4>What is this screen for?</h4><p>Discount coupons for campaigns: percent or flat ₹ off, with optional caps (max uses, minimum devices, expiry). The same code works at public signup, in the client portal and on admin-raised orders.</p>`,
 why: `<h4>Why this exists</h4><p>A festival offer needs a switch you control, not a price edit that forgets to get reverted. <b>Picture this:</b> Diwali campaign — DIWALI25 gives 25% off annual plans of 25+ devices, capped at 20 redemptions, auto-expiring Nov 15. The discount appears as its own line on the quote and GST invoice, so accounts stay clean.</p><p>✔ Usage counted only on PAID orders &nbsp;✔ Full audit trail &nbsp;✔ Disable instantly any time</p>`,
 right: `<p>❌ <b>Editing plan prices for a campaign.</b> → Old quotes break, prices never come back. → ✅ Coupon with an expiry date.</p><p>❌ <b>Unlimited uses on a deep discount.</b> → One viral WhatsApp forward = margin gone. → ✅ Always set max uses.</p>`,
},
audit: {
 use: `<h4>What is this screen for?</h4><p>The immutable trail: who issued, renewed, suspended, edited, or recorded what — and when.</p>`,
 why: `<h4>Why this exists</h4><p>When a licence "mysteriously" changed or a price was edited, this answers who and when in one search. Trust, but verify.</p>`,
 right: `<p>❌ <b>Sharing one admin login.</b> → The log says "Ejaz did everything". → ✅ One account per person, role-appropriate.</p>`,
},
};
let helpTabIdx = 0;
function openHelp() {
  document.getElementById('helpTitle').textContent = TITLES[PAGE];
  helpTab(0, document.querySelector('.help-tabs button'));
  document.getElementById('helpOv').classList.add('show');
}
function closeHelp() { document.getElementById('helpOv').classList.remove('show'); }
document.getElementById('helpOv').addEventListener('click', e => { if (e.target.id === 'helpOv') closeHelp(); });
function helpTab(i, btn) {
  helpTabIdx = i;
  document.querySelectorAll('.help-tabs button').forEach((b, j) => b.classList.toggle('on', j === i));
  const h = HELP[PAGE] || {use:'<p>General screen.</p>', why:'<p></p>', right:'<p></p>'};
  document.getElementById('helpBody').innerHTML = [h.use, h.why, h.right][i] || '<p>—</p>';
}

// ---------- leads helpers (R3-7) ----------
const leadPill = s => ({NEW:'p-info',CONTACTED:'p-warn',DEMO_SCHEDULED:'p-warn',QUOTED:'p-info',WON:'p-ok',LOST:'p-mut'}[s] || 'p-mut');
async function loadLeads() {
  const q = encodeURIComponent(document.getElementById('ldq')?.value || '');
  const st = encodeURIComponent(document.getElementById('ldst')?.value || '');
  const d = await api(`leads?q=${q}&status=${st}`);
  const rows = (d.data || []).map(l => {
    const overdue = l.follow_up_at && new Date(l.follow_up_at) <= new Date();
    return `<tr>
      <td><b>${esc(l.name)}</b><div class="mini">${esc(l.company || '')}</div></td>
      <td class="mini">${esc(l.email || '—')}<div>${esc(l.phone || '')}</div></td>
      <td class="mini">${esc(l.city || '—')}<div>${l.devices_interested ? l.devices_interested + ' devices' : ''}</div></td>
      <td class="mini">${esc(l.source || '')}</td>
      <td>${CAN_WRITE
        ? `<select onchange="setLeadStatus(${l.id}, this.value)">${LEAD_STATUSES.map(s => `<option ${s === l.status ? 'selected' : ''}>${s}</option>`).join('')}</select>`
        : `<span class="pill ${leadPill(l.status)}">${esc(l.status)}</span>`}</td>
      <td class="mini" style="${overdue ? 'color:#C0392B;font-weight:700' : ''}">${l.follow_up_at ? new Date(l.follow_up_at).toLocaleDateString() : '—'}</td>
      <td class="mini" style="max-width:240px">${esc((l.notes || l.message || '').slice(0, 120))}</td>
      <td>${CAN_WRITE ? `<button class="link" onclick="editLead(${l.id})">Edit</button>` : ''}</td></tr>`;
  }).join('');
  document.getElementById('ldlist').innerHTML = `<div class="card">
    <table><tr><th>Lead</th><th>Contact</th><th>City / Size</th><th>Source</th><th>Status</th><th>Follow-up</th><th>Notes</th><th></th></tr>
    ${rows || '<tr><td colspan="8" class="mini">No leads yet. They arrive from the website form, or add one with + Add Lead.</td></tr>'}</table></div>`;
  window._LEADS = d.data || [];
}
async function setLeadStatus(id, status) {
  try { await api('leads/' + id, {method:'PUT', body:{status}}); toast('Lead → ' + status); loadLeads(); }
  catch (e) { toast('Error: ' + e); }
}
function leadForm(l = {}) {
  return `<div class="row">
  <div><label>Name *</label><input id="lf-name" value="${esc(l.name || '')}"></div>
  <div><label>Company</label><input id="lf-company" value="${esc(l.company || '')}"></div>
  <div><label>Email</label><input id="lf-email" value="${esc(l.email || '')}"></div>
  <div><label>Phone</label><input id="lf-phone" value="${esc(l.phone || '')}"></div>
  <div><label>City</label><input id="lf-city" value="${esc(l.city || '')}"></div>
  <div><label>Devices interested</label><input id="lf-dev" type="number" min="1" value="${l.devices_interested || ''}"></div>
  <div><label>Follow-up date</label><input id="lf-fu" type="date" value="${l.follow_up_at ? String(l.follow_up_at).slice(0, 10) : ''}"></div>
  <div><label>Assigned to</label><input id="lf-asg" value="${esc(l.assigned_to || '')}"></div>
  </div>
  <label>Notes</label><textarea id="lf-notes" rows="3" style="width:100%">${esc(l.notes || '')}</textarea>`;
}
function leadFormBody() {
  const v = id => document.getElementById(id).value.trim();
  return {name:v('lf-name'), company:v('lf-company') || null, email:v('lf-email') || null, phone:v('lf-phone') || null,
    city:v('lf-city') || null, devices_interested:v('lf-dev') || null, follow_up_at:v('lf-fu') || null,
    assigned_to:v('lf-asg') || null, notes:v('lf-notes') || null};
}
function newLead() {
  openModal(`<h2>Add lead</h2><div class="sub">Walk-in, referral or phone enquiry — website leads arrive here automatically.</div>${leadForm()}
    <div class="foot"><button class="btn btn-l" onclick="closeModal()">Cancel</button>
    <button class="btn btn-p" onclick="saveLead(null)">Save lead</button></div>`, true);
}
function editLead(id) {
  const l = (window._LEADS || []).find(x => x.id === id) || {};
  openModal(`<h2>Edit lead — ${esc(l.name || '')}</h2>${leadForm(l)}
    <div class="foot"><button class="btn btn-l" onclick="closeModal()">Cancel</button>
    <button class="btn btn-p" onclick="saveLead(${id})">Save changes</button></div>`, true);
}
async function saveLead(id) {
  try {
    const body = leadFormBody();
    if (!body.name) { toast('Name is required'); return; }
    await api(id ? 'leads/' + id : 'leads', {method: id ? 'PUT' : 'POST', body});
    closeModal(); toast('Lead saved'); loadLeads();
  } catch (e) { toast('Error: ' + e); }
}

// ---------- coupons helpers (R3-7) ----------
async function loadCoupons() {
  const d = await api('coupons');
  const rows = (d.data || []).map(c => `<tr>
    <td><b>${esc(c.code)}</b><div class="mini">${esc(c.description || '')}</div></td>
    <td>${c.type === 'percent' ? Number(c.value) + '% off' : '₹' + Number(c.value).toLocaleString('en-IN') + ' off'}</td>
    <td class="mini">${c.used_count}${c.max_uses ? ' / ' + c.max_uses : ''} used</td>
    <td class="mini">${c.min_devices ? '≥ ' + c.min_devices + ' devices' : '—'}${c.exclusive_email ? '<div style="color:var(--warn);font-weight:700">exclusive: ' + esc(c.exclusive_email) + '</div>' : ''}</td>
    <td class="mini">${c.valid_until ? 'till ' + String(c.valid_until).slice(0, 10) : 'no expiry'}</td>
    <td><span class="pill ${c.active ? 'p-ok' : 'p-mut'}">${c.active ? 'active' : 'off'}</span></td>
    <td>${CAN_WRITE ? `<button class="link" onclick="toggleCoupon(${c.id}, ${c.active ? 0 : 1})">${c.active ? 'Disable' : 'Enable'}</button>` : ''}</td></tr>`).join('');
  document.getElementById('cplist').innerHTML = `<div class="card"><h3>Discount coupons</h3>
    <table><tr><th>Code</th><th>Discount</th><th>Usage</th><th>Condition</th><th>Validity</th><th>Status</th><th></th></tr>
    ${rows || '<tr><td colspan="7" class="mini">No coupons yet. Create one and share the code in campaigns — it applies at signup, in the client portal and on admin orders.</td></tr>'}</table></div>`;
}
function newCoupon() {
  openModal(`<h2>New coupon</h2><div class="sub">Applies at signup, in the client portal and on admin orders — usage counts only when the order is PAID.</div>
  <div class="row">
  <div><label>Code * (e.g. DIWALI25)</label><input id="cf-code" style="text-transform:uppercase"></div>
  <div><label>Type *</label><select id="cf-type"><option value="percent">% percent off</option><option value="flat">₹ flat off</option></select></div>
  <div><label>Value *</label><input id="cf-value" type="number" min="0.01" step="0.01" placeholder="10 = 10% or ₹10"></div>
  <div><label>Max uses (blank = unlimited)</label><input id="cf-max" type="number" min="1"></div>
  <div><label>Min devices (blank = any)</label><input id="cf-min" type="number" min="1"></div>
  <div><label>Valid until (blank = no expiry)</label><input id="cf-until" type="date"></div>
  <div><label>Exclusive to one email (blank = public)</label><input id="cf-excl" type="email" placeholder="cfo@bigclient.in"></div>
  </div>
  <label>Description</label><input id="cf-desc" placeholder="Diwali festive offer" style="width:100%">
  <div class="mini" style="margin-top:6px">Exclusive coupons auto-apply the moment that email is typed at signup (the "exclusive-offer catch") and are invisible to everyone else. Note: coupons stack AFTER the advance-period discount — price campaigns knowingly.</div>
  <div class="foot"><button class="btn btn-l" onclick="closeModal()">Cancel</button>
  <button class="btn btn-p" onclick="saveCoupon()">Create coupon</button></div>`, true);
}
async function saveCoupon() {
  const v = id => document.getElementById(id).value.trim();
  try {
    if (!v('cf-code') || !v('cf-value')) { toast('Code and value are required'); return; }
    await api('coupons', {method:'POST', body:{code:v('cf-code'), type:v('cf-type'), value:v('cf-value'),
      max_uses:v('cf-max') || null, min_devices:v('cf-min') || null, valid_until:v('cf-until') || null,
      exclusive_email:v('cf-excl') || null, description:v('cf-desc') || null, active:true}});
    closeModal(); toast('Coupon created'); loadCoupons();
  } catch (e) { toast('Error: ' + e); }
}
async function toggleCoupon(id, active) {
  try { await api('coupons/' + id, {method:'PUT', body:{active: !!active}}); loadCoupons(); }
  catch (e) { toast('Error: ' + e); }
}

go('dashboard');
</script>
</body>
</html>
