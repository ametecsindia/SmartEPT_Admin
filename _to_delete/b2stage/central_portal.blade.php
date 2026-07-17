<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>{{ $user->tenant->company_name }} — SmartEPT Client Portal</title>
<style>
:root{--accent:#0E7C8F;--accent2:#22B8CF;--weak:#E3F4F7;--deep:#0B6373;--ink:#15171C;--ink2:#565A66;
--ink3:#878C99;--canvas:#F4F6F9;--card:#fff;--card2:#FAFBFC;--border:#E7E9EF;--border2:#DCDFE7;
--hair:#F0F1F4;--ok:#08875D;--ok-w:#E6F5EE;--warn:#B7791F;--warn-w:#FBF3E2;--danger:#D02748;
--danger-w:#FBE9ED;--info:#0B72C9;--info-w:#E6F1FB;--navy1:#04252C;--navy2:#0B4A56}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Inter','Segoe UI',sans-serif;background:var(--canvas);color:var(--ink);font-size:14px;display:flex;min-height:100vh}
button{font-family:inherit;cursor:pointer}
input,select{font-family:inherit;font-size:13.5px;padding:9px 11px;border:1.5px solid var(--border2);border-radius:8px;width:100%;background:#fff;color:var(--ink)}
input:focus,select:focus{outline:none;border-color:var(--accent)}
label{display:block;font-size:12px;font-weight:700;color:var(--ink2);margin:10px 0 4px}
aside{width:232px;background:linear-gradient(175deg,var(--navy1),#083039);color:#C9E2E7;padding:20px 14px;display:flex;flex-direction:column;position:sticky;top:0;height:100vh}
.brand{display:flex;align-items:center;gap:10px;padding:2px 8px 18px;border-bottom:1px solid rgba(255,255,255,.1)}
.brand .mk{width:36px;height:36px;border-radius:9px;background:linear-gradient(135deg,var(--accent),var(--accent2));display:flex;align-items:center;justify-content:center;font-weight:800;font-size:12px;color:#fff}
.brand b{font-size:15px;color:#fff}.brand small{display:block;font-size:8.5px;letter-spacing:2px;color:#7FA8AF}
nav{flex:1;margin-top:14px}
.nav-item{display:flex;align-items:center;gap:10px;padding:9.5px 12px;border-radius:9px;font-size:13.5px;font-weight:600;color:#A9CBD1;cursor:pointer;margin-bottom:2px}
.nav-item svg{width:17px;height:17px;flex:none}
.nav-item:hover{background:rgba(255,255,255,.06);color:#fff}
.nav-item.on{background:linear-gradient(135deg,var(--accent),#1899AE);color:#fff}
.nav-sec{font-size:10px;letter-spacing:1.8px;color:#5E858C;text-transform:uppercase;font-weight:800;margin:16px 12px 6px}
.me{border-top:1px solid rgba(255,255,255,.1);padding-top:12px;display:flex;align-items:center;gap:9px}
.me .av{width:32px;height:32px;border-radius:50%;background:var(--accent);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:12px;color:#fff}
.me b{font-size:12.5px;color:#fff;display:block}.me span{font-size:10.5px;color:#7FA8AF}
.me form{margin-left:auto}
.me button{background:none;border:1px solid rgba(255,255,255,.2);color:#A9CBD1;font-size:10.5px;padding:5px 9px;border-radius:7px}
main{flex:1;padding:26px 30px;max-width:calc(100vw - 232px)}
.topbar{display:flex;align-items:center;gap:14px;margin-bottom:20px}
.topbar h1{font-size:21px;font-weight:800}
.help-btn{width:26px;height:26px;border-radius:50%;border:1.5px solid var(--accent);color:var(--accent);background:#fff;font-weight:800;font-size:13px}
.topbar .sp{flex:1}
.btn{padding:9px 16px;border-radius:8px;border:none;font-weight:700;font-size:13px}
.btn-p{background:linear-gradient(135deg,var(--accent),#1899AE);color:#fff}
.btn-l{background:#fff;border:1.5px solid var(--border2);color:var(--deep)}
.btn:disabled{opacity:.5;cursor:not-allowed}
.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:20px}
.stat{background:#fff;border:1px solid var(--border);border-radius:13px;padding:16px 18px}
.stat .l{font-size:11px;color:var(--ink3);text-transform:uppercase;letter-spacing:1px;font-weight:700}
.stat .v{font-size:24px;font-weight:800;margin-top:3px}
.stat .v.teal{color:var(--accent)}.stat .v.ok{color:var(--ok)}.stat .v.warn{color:var(--warn)}.stat .v.dang{color:var(--danger)}
.stat .m{font-size:11.5px;color:var(--ink3);margin-top:2px}
.card{background:#fff;border:1px solid var(--border);border-radius:13px;padding:18px 20px;margin-bottom:16px}
.card h3{font-size:14.5px;font-weight:800;margin-bottom:12px;color:var(--deep)}
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
.link{color:var(--accent);font-weight:700;cursor:pointer;background:none;border:none;font-size:12.5px;text-decoration:none}
.banner{border-radius:12px;padding:14px 18px;margin-bottom:16px;font-size:13px;display:flex;align-items:center;gap:14px}
.banner.trial{background:linear-gradient(135deg,var(--navy1),var(--navy2));color:#DFF0F3}
.banner.due{background:var(--warn-w);color:#7A5614}
.banner b{font-size:14px}
.banner .sp{flex:1}
.plan-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:14px}
.plan-card{border:1.5px solid var(--border2);border-radius:12px;padding:14px 16px;cursor:pointer}
.plan-card.on{border-color:var(--accent);background:var(--weak)}
.plan-card b{font-size:14px;display:block}
.plan-card .pr{font-size:20px;font-weight:800;color:var(--deep);margin:4px 0 2px}
.plan-card .pr small{font-size:11px;font-weight:600;color:var(--ink3)}
.seg{display:inline-flex;border:1.5px solid var(--border2);border-radius:9px;overflow:hidden}
.seg button{padding:8px 16px;border:none;background:#fff;font-weight:700;font-size:12.5px;color:var(--ink2)}
.seg button.on{background:var(--weak);color:var(--deep)}
.quote-box{background:var(--weak);border-radius:10px;padding:12px 14px;margin-top:12px;font-size:12.5px;color:var(--deep)}
.quote-box .ln{display:flex;justify-content:space-between;padding:3px 0;gap:14px}
.quote-box .tt{border-top:1.5px solid rgba(11,99,115,.25);margin-top:6px;padding-top:6px;font-weight:800;font-size:14px}
.overlay{position:fixed;inset:0;background:rgba(4,37,44,.55);display:none;align-items:flex-start;justify-content:center;z-index:40;padding:40px 16px;overflow-y:auto}
.overlay.show{display:flex}
.modal{background:#fff;border-radius:16px;width:100%;max-width:560px;padding:24px 26px;box-shadow:0 30px 80px rgba(0,0,0,.35)}
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
.toast{position:fixed;bottom:22px;right:22px;background:var(--navy1);color:#fff;padding:12px 18px;border-radius:10px;font-size:13px;display:none;z-index:60;box-shadow:0 12px 30px rgba(0,0,0,.3)}
.keybox{font-family:ui-monospace,Consolas,monospace;background:var(--hair);border-radius:7px;padding:3px 8px;font-size:12px}
@media(max-width:1100px){.stats{grid-template-columns:1fr 1fr}.plan-grid{grid-template-columns:1fr}}
.dgrid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.dcard{display:flex;flex-direction:column}
.dhead{display:flex;align-items:center;gap:12px;margin-bottom:8px}
.dicon{width:44px;height:44px;border-radius:11px;background:#E3F4F7;display:flex;align-items:center;justify-content:center;font-size:22px}
.steps{margin:8px 0 14px;padding-left:18px;font-size:12.5px;color:#565A66;line-height:1.7}
.dcard .btn{margin-top:auto;text-align:center;text-decoration:none}
@media(max-width:820px){.dgrid{grid-template-columns:1fr}}
</style>
</head>
<body>

<aside>
  <div class="brand"><div class="mk"><img src="data:image/png;base64,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" alt="SmartEPT" style="width:72%;height:72%;object-fit:contain;display:block"></div><div><b>SmartEPT</b><small>CLIENT PORTAL</small></div></div>
  <nav id="nav">
    <div class="nav-sec">My account</div>
    <div class="nav-item on" data-page="overview">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7.5" height="7.5" rx="1.5"/><rect x="13.5" y="3" width="7.5" height="7.5" rx="1.5"/><rect x="3" y="13.5" width="7.5" height="7.5" rx="1.5"/><rect x="13.5" y="13.5" width="7.5" height="7.5" rx="1.5"/></svg>
      Overview</div>
    <div class="nav-item" data-page="licence">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="12" r="5"/><path d="M13 12h8M18 12v4M21 12v3"/></svg>
      Licence &amp; Devices</div>
    <div class="nav-item" data-page="install">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12m0 0l-4-4m4 4l4-4M5 21h14"/></svg>
      Install &amp; Downloads</div>
    <div class="nav-sec">Billing</div>
    <div class="nav-item" data-page="buy">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="20" r="1.6"/><circle cx="18" cy="20" r="1.6"/><path d="M3 4h2.5l2.4 12.2a1.5 1.5 0 0 0 1.5 1.3h8.6a1.5 1.5 0 0 0 1.5-1.2L21 8H6"/></svg>
      Buy &amp; Renew</div>
    <div class="nav-item" data-page="billing">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2.5h9l4 4V21a.9.9 0 0 1-.9.9H6a.9.9 0 0 1-.9-.9V3.4a.9.9 0 0 1 .9-.9z"/><path d="M15 2.5v4.5h4.5M9 12h6M9 16h6"/></svg>
      Orders &amp; Invoices</div>
    <div class="nav-item" data-page="storage">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M17.5 18.5a4.5 4.5 0 0 0 .5-8.97A6 6 0 0 0 6.3 8.6 4.8 4.8 0 0 0 7 18.5h10.5z"/></svg>
      Cloud Storage</div>
    <div class="nav-sec">Settings</div>
    <div class="nav-item" data-page="account">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="3.6"/><path d="M4.5 20.5a7.5 7.5 0 0 1 15 0"/></svg>
      My Account</div>
  </nav>
  <div class="me">
    <div class="av">{{ strtoupper(substr($user->name, 0, 2)) }}</div>
    <div><b>{{ $user->name }}</b><span>{{ $user->tenant->company_name }}</span></div>
    <form method="POST" action="/client/logout">@csrf<button type="submit">Logout</button></form>
  </div>
</aside>

<main>
  <div class="topbar">
    <h1 id="pageTitle">Overview</h1>
    <button class="help-btn" onclick="openHelp()" title="Screen help">i</button>
    <div class="sp"></div>
    <div id="pageActions"></div>
  </div>
  <div id="page"></div>
</main>

<!-- generic modal -->
<div class="overlay" id="modalOv"><div class="modal" id="modalBox"></div></div>

@if ($user->must_set_password ?? false)
<!-- Forced create-your-own-password (master prompt §11): the temp password was
     a backup only. This overlay cannot be dismissed until a new password is set. -->
<div class="overlay show" id="forcePwOv" style="z-index:70">
  <div class="modal" style="max-width:440px">
    <h2 style="font-size:17px;font-weight:800;margin-bottom:4px">Create your own password</h2>
    <p class="mini" style="margin-bottom:12px">Welcome, {{ $user->name }}! You signed in with a temporary password.
    For your security, please create your own password now — it takes ten seconds.</p>
    <label>Temporary password (the one you just used)</label>
    <input type="password" id="fpCur" autocomplete="current-password">
    <label>Your new password (min 8 characters)</label>
    <input type="password" id="fpNew" autocomplete="new-password">
    <label>Type the new password again</label>
    <input type="password" id="fpNew2" autocomplete="new-password">
    <div class="mini" id="fpMsg" style="color:#D02748;margin-top:8px"></div>
    <button class="btn btn-p" style="width:100%;margin-top:14px" onclick="forceSetPassword()">Save my password &amp; continue →</button>
  </div>
</div>
<script>
async function forceSetPassword() {
  const cur = document.getElementById('fpCur').value, nw = document.getElementById('fpNew').value, nw2 = document.getElementById('fpNew2').value;
  const msg = document.getElementById('fpMsg');
  if (nw.length < 8) { msg.textContent = 'The new password needs at least 8 characters.'; return; }
  if (nw !== nw2) { msg.textContent = 'The two new passwords do not match.'; return; }
  try {
    const res = await fetch('/client/api/account/password', {method:'POST',
      headers:{'Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content,'Accept':'application/json'},
      body: JSON.stringify({current_password: cur, password: nw})});
    const body = await res.json().catch(()=>({}));
    if (!res.ok) throw new Error(body.error || 'Could not update the password.');
    document.getElementById('forcePwOv').remove();
  } catch (e) { msg.textContent = e.message; }
}
</script>
@endif
<!-- help modal -->
<div class="overlay" id="helpOv"><div class="modal">
  <div class="help-head"><div><b id="helpTitle"></b><span>SmartEPT Client Portal · Screen Help</span></div>
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
const fmtMoney = (n, c='INR') => (c==='INR'?'₹':'$') + Number(n).toLocaleString('en-IN', {maximumFractionDigits:2});
const esc = s => String(s ?? '').replace(/[&<>"]/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[m]));
const toast = m => { const t=document.getElementById('toast'); t.textContent=m; t.style.display='block'; setTimeout(()=>t.style.display='none', 3200); };

async function api(path, opts={}) {
  const res = await fetch('/client/api/' + path, {
    headers: {'Content-Type':'application/json','X-CSRF-TOKEN':CSRF,'Accept':'application/json'},
    ...opts, body: opts.body ? JSON.stringify(opts.body) : undefined,
  });
  const body = await res.json().catch(() => ({}));
  if (res.status === 401) { location.href = '/client/login'; throw new Error('signed out'); }
  if (!res.ok) throw new Error(body.error || (body.errors ? Object.values(body.errors).flat().join(' ') : 'Request failed'));
  return body;
}

let PAGE = 'overview', OV = null;

// ---------- navigation ----------
document.getElementById('nav').addEventListener('click', e => {
  const item = e.target.closest('.nav-item');
  if (!item) return;
  document.querySelectorAll('.nav-item').forEach(n => n.classList.toggle('on', n === item));
  PAGE = item.dataset.page;
  render();
});

const TITLES = {overview:'Overview', licence:'Licence & Devices', install:'Install & Downloads', buy:'Buy & Renew',
  billing:'Orders & Invoices', storage:'Cloud Storage', account:'My Account'};

function render() {
  document.getElementById('pageTitle').textContent = TITLES[PAGE];
  document.getElementById('pageActions').innerHTML = '';
  ({overview:pgOverview, licence:pgLicence, install:pgInstall, buy:pgBuy, billing:pgBilling, storage:pgStorage, account:pgAccount})[PAGE]();
}

function statusPill(s) {
  const map = {active:'p-ok', paid:'p-ok', trial:'p-info', created:'p-warn', quote:'p-info',
    suspended:'p-dang', expired:'p-dang', revoked:'p-dang', failed:'p-dang', deactivated:'p-mut', churned:'p-mut'};
  return `<span class="pill ${map[s] || 'p-mut'}">${esc(s)}</span>`;
}

// ---------- Overview ----------
async function pgOverview() {
  const el = document.getElementById('page');
  el.innerHTML = '<p class="mini">Loading…</p>';
  OV = await api('overview');
  const t = OV.tenant, l = OV.licence;

  let banner = '';
  if (t.status === 'trial' && l) {
    banner = `<div class="banner trial"><div><b>Your free trial is live${l.days_left !== null ? ' — ' + Math.max(0, l.days_left) + ' day(s) left' : ''}.</b><br>
    Professional features, up to ${l.device_limit} devices. Like it? Move to a paid plan in two clicks — your data continues seamlessly.</div>
    <div class="sp"></div><button class="btn btn-p" onclick="goBuy()">Choose a plan →</button></div>`;
  } else if (OV.counts.unpaid > 0) {
    banner = `<div class="banner due"><div><b>${OV.counts.unpaid} order(s) awaiting payment.</b> Pay online or by NEFT/UPI — your licence activates the moment payment lands.</div>
    <div class="sp"></div><button class="btn btn-l" onclick="goPage('billing')">View orders →</button></div>`;
  }

  const daysClass = l && l.days_left !== null ? (l.days_left <= 7 ? 'dang' : l.days_left <= 30 ? 'warn' : 'ok') : 'teal';

  el.innerHTML = banner + `
  <div class="stats">
    <div class="stat"><div class="l">Plan</div><div class="v teal">${l ? esc(l.plan) : '—'}</div><div class="m">${l ? esc(l.kind) + ' · ' + esc(l.billing) : 'no active licence'}</div></div>
    <div class="stat"><div class="l">Devices</div><div class="v">${l ? l.devices_active + ' / ' + l.device_limit : '—'}</div><div class="m">active endpoint devices</div></div>
    <div class="stat"><div class="l">${l && l.kind === 'trial' ? 'Trial ends' : 'Licence expires'}</div>
      <div class="v ${daysClass}">${l && l.days_left !== null ? Math.max(0, l.days_left) + 'd' : '—'}</div>
      <div class="m">${l && l.expires_at ? 'on ' + l.expires_at : ''}</div></div>
    <div class="stat"><div class="l">Account status</div><div class="v" style="font-size:16px;padding-top:6px">${statusPill(t.status)}</div><div class="m">${esc(t.deployment).replace('_','-')}</div></div>
  </div>
  <div class="card">
    <h3>Company</h3>
    <table><tbody>
      <tr><td class="mini" style="width:180px">Company</td><td><b>${esc(t.company_name)}</b></td></tr>
      <tr><td class="mini">Contact</td><td>${esc(t.contact_name || '—')} · ${esc(t.phone || '')}</td></tr>
      <tr><td class="mini">Email</td><td>${esc(t.email)}</td></tr>
      <tr><td class="mini">GSTIN</td><td>${esc(t.gstin || 'not provided — add it under My Account → Billing profile to claim input tax credit')}</td></tr>
    </tbody></table>
    <p class="mini" style="margin-top:10px">Something wrong here? Message us on WhatsApp
    <a class="link" href="https://wa.me/${esc(OV.whatsapp)}" target="_blank">${esc(OV.whatsapp)}</a> and we will fix it.</p>
  </div>`;
}
function goBuy() { document.querySelector('[data-page=buy]').click(); }
function goPage(p) { document.querySelector('[data-page=' + p + ']').click(); }

// ---------- Install & Downloads ----------
async function pgInstall() {
  const el = document.getElementById('page');
  el.innerHTML = '<p class="mini">Loading…</p>';
  let d = {};
  try { d = await api('downloads'); } catch (e) {}
  const key = d.licence_key || '—';
  const isCloud = d.deployment === 'cloud';
  const dlBtn = (art, ready) => ready
    ? `<a class="btn btn-p" href="/client/download/${art}">Download installer →</a>`
    : `<button class="btn btn-l" onclick="installSoon('${art}')">Notify me / request build</button>`;

  const keyCard = `
  <div class="card" style="margin-bottom:16px">
    <h3>Your licence key</h3>
    <p class="mini">Activates SmartEPT for your account — for licensing only. No monitoring data ever leaves your environment.</p>
    <div style="display:flex;gap:10px;align-items:center;margin-top:8px">
      <code style="flex:1;background:#F2F7F8;border:1px solid #DCEAEC;border-radius:8px;padding:11px 13px;font-size:14px;letter-spacing:1px;color:#0B4A56">${esc(key)}</code>
      <button class="btn btn-l" onclick="copyKey('${esc(key)}')">Copy</button>
    </div>
  </div>`;

  if (isCloud) {
    const url = d.console_url;
    const consoleCard = url
      ? `<div class="dhead"><div class="dicon">🛡️</div><div><h3 style="margin:0">Your SmartEPT Console</h3><span class="mini">Hosted by Ametecs · live monitoring &amp; admin</span></div></div>
         <p class="mini">Your dashboard, employee tracking, policies, screenshots, biometric and reports — all here. Sign in with your admin credentials.</p>
         <a class="btn btn-p" target="_blank" href="${esc(url)}">Open my SmartEPT Console →</a>`
      : `<div class="dhead"><div class="dicon">🛠️</div><div><h3 style="margin:0">Your SmartEPT Console</h3><span class="mini">Managed cloud · being provisioned</span></div></div>
         <p class="mini">Your hosted SmartEPT workspace is being set up on Ametecs cloud. We'll email your console link and admin login shortly — usually within one business day of activation.</p>
         <button class="btn btn-l" onclick="installSoon('console')">Ask for my console link</button>`;

    el.innerHTML = keyCard + `
    <div class="dgrid">
      <div class="card dcard">${consoleCard}</div>
      <div class="card dcard">
        <div class="dhead"><div class="dicon">💻</div><div><h3 style="margin:0">SmartEPT Employee Agent</h3><span class="mini">Install on each employee PC</span></div></div>
        <p class="mini">The lightweight agent for employee workstations. It reports to your hosted console — set the Server URL to your console address when installing.</p>
        <ol class="steps">
          <li>Run this installer on each employee PC (or push via your IT tools).</li>
          <li>Set the Server URL to your SmartEPT Console address (shown to the left).</li>
          <li>The employee signs in &amp; consents; the device uses one seat.</li>
        </ol>
        ${dlBtn('agent', d.agent_ready)}
      </div>
    </div>
    <div class="card" style="margin-top:16px"><h3>Managed cloud — nothing to self-host</h3>
    <p class="mini">On SmartEPT-Managed Cloud, Ametecs runs and maintains your Admin Server, backups and updates. You only install the agent on employee PCs. Need help? WhatsApp <a class="link" href="https://wa.me/919000098877" target="_blank">90000 98877</a>.</p></div>`;
    return;
  }

  el.innerHTML = keyCard + `
  <div class="dgrid">
    <div class="card dcard">
      <div class="dhead"><div class="dicon">🖥️</div><div><h3 style="margin:0">SmartEPT Admin Server</h3><span class="mini">On-premises / your cloud · install first</span></div></div>
      <p class="mini">The management server your team logs into and where all monitoring data is stored — on your own infrastructure. The installer bundles everything it needs and sets up the console at <b>http://your-server/admin</b>.</p>
      <ol class="steps">
        <li>Run the installer on your server (Windows Server or a Windows PC).</li>
        <li>It installs the runtime + database and creates the SmartEPT database automatically.</li>
        <li>Paste your licence key when asked to activate.</li>
        <li>Open the admin console and create your team logins.</li>
      </ol>
      ${dlBtn('admin', d.admin_ready)}
    </div>
    <div class="card dcard">
      <div class="dhead"><div class="dicon">💻</div><div><h3 style="margin:0">SmartEPT Employee Agent</h3><span class="mini">Install on each employee PC · after the server</span></div></div>
      <p class="mini">The lightweight agent for employee workstations — attendance-linked access, activity, app &amp; website usage. It registers to your Admin Server and uses one seat.</p>
      <ol class="steps">
        <li>Install the Admin Server first (above).</li>
        <li>Run this installer on each employee PC (or push via your IT tools).</li>
        <li>Set the Server URL to your Admin Server address; the employee signs in &amp; consents.</li>
        <li>The device appears under Licence &amp; Devices, using one seat.</li>
      </ol>
      ${dlBtn('agent', d.agent_ready)}
    </div>
  </div>
  <div class="card" style="margin-top:16px"><h3>Need help deploying?</h3>
  <p class="mini">Our team can install &amp; configure SmartEPT for you remotely. WhatsApp <a class="link" href="https://wa.me/919000098877" target="_blank">90000 98877</a> or email <a class="link" href="mailto:sales@ametecsindia.com">sales@ametecsindia.com</a>. (Didn't buy setup up front? Ask us to raise an installation invoice.)</p></div>`;
}
function installSoon(art) {
  const label = art === 'admin' ? 'the Admin Server installer' : (art === 'console' ? 'my hosted SmartEPT Console link' : 'the Employee Agent installer');
  const msg = 'Hi Ametecs, please send me ' + label + ' for my SmartEPT account.';
  window.open('https://wa.me/919000098877?text=' + encodeURIComponent(msg), '_blank');
}
function copyKey(k) { navigator.clipboard?.writeText(k); toast && toast('Licence key copied'); }

// ---------- Licence & Devices ----------
async function pgLicence() {
  const el = document.getElementById('page');
  el.innerHTML = '<p class="mini">Loading…</p>';
  const rows = await api('licences');

  if (!rows.length) { el.innerHTML = '<div class="card"><h3>No licences yet</h3><p class="mini">Buy a plan on the Buy &amp; Renew screen — your licence key appears here instantly after payment.</p></div>'; return; }

  el.innerHTML = rows.map(l => `
  <div class="card">
    <h3>${esc(l.plan)} · <span class="keybox">${esc(l.key)}</span> ${statusPill(l.status)}</h3>
    <p class="mini" style="margin-bottom:10px">${esc(l.kind)} · ${esc(l.billing)} · ${esc(l.deployment).replace('_','-')} ·
      up to <b>${l.device_limit}</b> devices${l.expires_at ? ' · expires ' + l.expires_at : ''}</p>
    ${l.devices.length ? `<table><thead><tr><th>Device</th><th>Hostname</th><th>Status</th><th>Activated</th></tr></thead><tbody>
      ${l.devices.map(d => `<tr><td>${esc(d.device_uid)}</td><td>${esc(d.hostname || '—')}</td><td>${statusPill(d.status)}</td><td class="mini">${esc(d.activated_at || '—')}</td></tr>`).join('')}
    </tbody></table>` : '<p class="mini">No devices activated yet. Install the SmartEPT agent on a workstation and it appears here with its licence seat.</p>'}
    <p class="mini" style="margin-top:10px">Need to move a licence to a replacement PC? Deactivation frees the seat — ask us on WhatsApp, it takes a minute.</p>
  </div>`).join('');
}

// ---------- Buy & Renew ----------
let BUY = {plan:'professional', devices:25, billing:'annual', deployment:'client_hosted', plans:[], coupon:''};

async function pgBuy() {
  const el = document.getElementById('page');
  el.innerHTML = '<p class="mini">Loading…</p>';
  if (!OV) OV = await api('overview');
  BUY.plans = await api('plans');
  BUY.deployment = OV.tenant.deployment || 'client_hosted';
  const l = OV.licence;
  if (l && l.plan_code) BUY.plan = l.plan_code;
  if (l && l.device_limit && l.kind !== 'trial') BUY.devices = l.device_limit;

  const renewCard = (l && l.kind === 'subscription' && ['active'].includes(l.status)) ? `
  <div class="card">
    <h3>Quick renewal</h3>
    <p class="mini" style="margin-bottom:10px">Extend your current licence — same plan (<b>${esc(l.plan)}</b>), same ${l.device_limit} devices, one more ${l.billing === 'annual' ? 'year' : 'month'}. The new period starts where the old one ends, so renewing early never wastes days.</p>
    <button class="btn btn-p" onclick="doRenew(${l.id})">Renew now →</button>
  </div>` : '';

  el.innerHTML = renewCard + `
  <div class="card">
    <h3>Price calculator — buy or upgrade</h3>
    <div class="plan-grid" id="planGrid"></div>
    <div class="modal-row" style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:0 14px">
      <div><label>Number of devices</label><input type="number" id="buyDevices" min="1" value="${BUY.devices}" onchange="BUY.devices=Math.max(1,parseInt(this.value)||1);calcQuote()"></div>
      <div><label>Advance period</label><div class="seg" id="segBilling">
        <button data-v="quarterly">Quarterly</button><button data-v="half_yearly">6 mo (10% off)</button><button data-v="annual" class="on">12 mo (25% off)</button></div></div>
      <div><label>Hosting</label><div class="seg" id="segDeploy">
        <button data-v="client_hosted" class="on">Your server</button><button data-v="cloud">SmartEPT Cloud</button></div></div>
    </div>
    <div style="display:grid;grid-template-columns:1fr auto;gap:10px;align-items:end;max-width:420px">
      <div><label>Coupon code (optional)</label><input id="buyCoupon" placeholder="e.g. DIWALI25" style="text-transform:uppercase" value="${esc(BUY.coupon)}"></div>
      <button class="btn btn-l" onclick="BUY.coupon=document.getElementById('buyCoupon').value.trim().toUpperCase();calcQuote()">Apply</button>
    </div>
    <div class="mini" id="couponNote" style="margin-top:4px"></div>
    <div class="quote-box" id="quoteBox"><span class="mini">Calculating…</span></div>
    <div style="display:flex;gap:10px;margin-top:14px;flex-wrap:wrap">
      <button class="btn btn-p" onclick="doBuy(false)">Pay now →</button>
      <button class="btn btn-l" onclick="doBuy(true)">Raise quotation for my management</button>
    </div>
    <p class="mini" style="margin-top:10px">Pay now opens a secure page — UPI, card, NetBanking or international card. A quotation gives you a branded PDF (valid 15 days) carrying a pay link your management can settle directly. Prefer NEFT/UPI transfer? Create the order, then WhatsApp us the UTR.</p>
  </div>`;

  document.getElementById('segBilling').onclick = e => seg(e, 'segBilling', v => { BUY.billing = v; calcQuote(); });
  document.getElementById('segDeploy').onclick = e => seg(e, 'segDeploy', v => { BUY.deployment = v; calcQuote(); });
  drawPlans();
  calcQuote();
}
function seg(e, id, cb) {
  const b = e.target.closest('button');
  if (!b) return;
  document.querySelectorAll('#' + id + ' button').forEach(x => x.classList.toggle('on', x === b));
  cb(b.dataset.v);
}
function drawPlans() {
  document.getElementById('planGrid').innerHTML = BUY.plans.map(p => `
    <div class="plan-card ${p.code === BUY.plan ? 'on' : ''}" onclick="BUY.plan='${esc(p.code)}';drawPlans();calcQuote()">
      <b>${esc(p.name)}${p.code === 'professional' ? ' ★' : ''}</b>
      <div class="pr">₹${Number(p.inr_annual)}<small> /device/month · annual</small></div>
      <span class="mini">₹${Number(p.inr_monthly)} monthly · min ${p.min_devices} devices</span>
    </div>`).join('');
}
async function calcQuote() {
  const box = document.getElementById('quoteBox');
  if (!box) return;
  try {
    const q = await api('quote', {method:'POST', body:{plan_code:BUY.plan, devices:BUY.devices, billing:BUY.billing, deployment:BUY.deployment, coupon_code:BUY.coupon || null}});
    box.innerHTML = q.lines.map(l => `<div class="ln"><span>${esc(l.description)}</span><b>${fmtMoney(l.amount, q.currency)}</b></div>`).join('')
      + `<div class="ln"><span>GST ${q.gst_rate}%</span><b>${fmtMoney(q.tax, q.currency)}</b></div>`
      + `<div class="ln tt"><span>Total payable</span><span>${fmtMoney(q.total, q.currency)}</span></div>`;
    const note = document.getElementById('couponNote');
    if (note) note.innerHTML = !BUY.coupon ? '' : (q.coupon?.ok
      ? '<span style="color:var(--ok);font-weight:700">✓ Coupon ' + esc(q.coupon.code) + ' applied — you save ' + fmtMoney(q.coupon.discount, q.currency) + '</span>'
      : '<span style="color:#D02748;font-weight:700">✗ Coupon not applied (' + esc(q.coupon?.reason || 'not valid') + ')</span>');
  } catch (err) { box.innerHTML = '<span class="mini">' + esc(err.message) + '</span>'; }
}
async function doBuy(asQuote) {
  try {
    const out = await api('orders', {method:'POST', body:{plan_code:BUY.plan, devices:BUY.devices, billing:BUY.billing, deployment:BUY.deployment, as_quote:asQuote, coupon_code:BUY.coupon || null}});
    if (asQuote) {
      modal(`<h2 style="font-size:17px;font-weight:800;margin-bottom:4px">Quotation ${esc(out.order.quote_number)} raised</h2>
      <p class="mini" style="margin-bottom:14px">Print it or send the pay link to your management — the moment they pay, your licence activates automatically.</p>
      <div style="display:flex;gap:10px;flex-wrap:wrap">
        <a class="btn btn-p" style="text-decoration:none" href="${esc(out.quote_print_url)}" target="_blank">Print quotation</a>
        <button class="btn btn-l" onclick="navigator.clipboard.writeText('${esc(out.pay_url)}').then(()=>toast('Pay link copied'))">Copy pay link</button>
        <button class="btn btn-l" onclick="closeModal()">Done</button>
      </div>`);
    } else {
      location.href = out.pay_url;
    }
  } catch (err) { toast(err.message); }
}
async function doRenew(id) {
  try {
    const out = await api('licences/' + id + '/renew', {method:'POST'});
    location.href = out.pay_url;
  } catch (err) { toast(err.message); }
}

// ---------- Orders & Invoices ----------
async function pgBilling() {
  const el = document.getElementById('page');
  el.innerHTML = '<p class="mini">Loading…</p>';
  const [orders, invoices] = await Promise.all([api('orders'), api('invoices')]);

  el.innerHTML = `
  <div class="card">
    <h3>Orders &amp; quotations</h3>
    ${orders.length ? `<table><thead><tr><th>Number</th><th>Description</th><th>Total</th><th>Status</th><th></th></tr></thead><tbody>
    ${orders.map(o => `<tr>
      <td><b>${esc(o.quote_number || o.number)}</b>${o.quote_number ? '<div class="mini">' + esc(o.number) + '</div>' : ''}
        ${o.requested_by ? '<div class="mini">req: ' + esc(o.requested_by) + '</div>' : ''}</td>
      <td>${esc(o.description)}<div class="mini">${esc(o.created_at)}</div></td>
      <td><b>${fmtMoney(o.total, o.currency)}</b>
        ${o.provisioned && o.status !== 'paid' && o.balance > 0 ? `<div class="mini" style="color:#D02748;font-weight:700">balance ${fmtMoney(o.balance, o.currency)}${o.credit_due_date ? ' by ' + esc(o.credit_due_date) : ''}</div><div class="mini" style="color:#08875D">received ${fmtMoney(o.received, o.currency)}</div>` : ''}</td>
      <td>${statusPill(o.status)}${o.invoice_number ? '<div class="mini">' + esc(o.invoice_number) + '</div>' : ''}</td>
      <td style="white-space:nowrap">
        ${o.pay_url ? `<a class="link" href="${esc(o.pay_url)}">${o.provisioned && o.received > 0 ? 'Pay balance' : 'Pay'}</a> ` : ''}
        ${o.quote_number ? `<a class="link" href="/client/orders/${o.id}/quote-print" target="_blank">Print quote</a>` : ''}
      </td></tr>`).join('')}
    </tbody></table>` : '<p class="mini">No orders yet — your purchases will appear here.</p>'}
  </div>
  <div class="card">
    <h3>GST invoices</h3>
    ${invoices.length ? `<table><thead><tr><th>Invoice</th><th>Date</th><th>Total</th><th>Status</th><th></th></tr></thead><tbody>
    ${invoices.map(i => `<tr><td><b>${esc(i.number)}</b></td><td>${esc(i.date)}</td>
      <td><b>${fmtMoney(i.total, i.currency)}</b></td><td>${statusPill(i.status)}</td>
      <td><a class="link" href="/client/invoices/${i.id}/print" target="_blank">Print / PDF</a></td></tr>`).join('')}
    </tbody></table>` : '<p class="mini">Invoices appear here automatically the moment a payment is received.</p>'}
  </div>`;
}

// ---------- Cloud Storage ----------
async function pgStorage() {
  const el = document.getElementById('page');
  el.innerHTML = '<p class="mini">Loading…</p>';
  const s = await api('storage');

  if (!s.is_cloud) {
    el.innerHTML = `<div class="card"><h3>You are on client-hosted SmartEPT</h3>
    <p class="mini" style="line-height:1.7">Your screenshots, activity and camera data live on <b>your own server</b> — storage is yours, so there is nothing to bill here. "Your Infrastructure. Your Data. Our Intelligence."<br><br>
    Curious about SmartEPT-Managed Cloud (we host everything, you just log in)? Use the calculator on Buy &amp; Renew with Hosting = SmartEPT Cloud, or WhatsApp us for a walkthrough.</p></div>`;
    return;
  }

  el.innerHTML = `
  <div class="stats" style="grid-template-columns:repeat(3,1fr)">
    <div class="stat"><div class="l">Average used — ${esc(s.month)}</div><div class="v teal">${s.avg_gb} GB</div></div>
    <div class="stat"><div class="l">Billable (min 50 GB)</div><div class="v">${s.billable_gb} GB</div></div>
    <div class="stat"><div class="l">This month's storage rent</div><div class="v ok">${fmtMoney(s.monthly_charge)}</div><div class="m">₹3/GB to 500 GB · ₹2.50 to 2 TB · ₹2 beyond</div></div>
  </div>
  <div class="card">
    <h3>Daily readings</h3>
    ${s.rows.length ? `<table><thead><tr><th>Date</th><th>GB used</th></tr></thead><tbody>
      ${s.rows.map(r => `<tr><td>${esc(r.date)}</td><td>${Number(r.gb_used).toFixed(1)} GB</td></tr>`).join('')}
    </tbody></table>` : '<p class="mini">No readings recorded yet this month.</p>'}
    <p class="mini" style="margin-top:10px">Storage is billed monthly on the AVERAGE daily usage — one heavy day does not spike your bill. Reduce usage anytime by shortening screenshot retention in your SmartEPT policy settings.</p>
  </div>`;
}

// ---------- My Account ----------
async function pgAccount() {
  document.getElementById('page').innerHTML = `
  <div class="card">
    <h3>Signed in as</h3>
    <p class="mini" style="line-height:1.8"><b style="color:var(--ink)">{{ $user->name }}</b> · {{ $user->email }}<br>
    Company: <b style="color:var(--ink)">{{ $user->tenant->company_name }}</b></p>
  </div>
  <div class="card" id="billCard">
    <h3>Billing profile</h3>
    <p class="mini">Loading…</p>
  </div>
  <div class="card">
    <h3>Change password</h3>
    <div style="max-width:380px">
      <label>Current password</label><input type="password" id="curPass" autocomplete="current-password">
      <label>New password (min 8 characters)</label><input type="password" id="newPass" autocomplete="new-password">
      <button class="btn btn-p" style="margin-top:14px" onclick="changePass()">Update password</button>
    </div>
  </div>
  <div class="card">
    <h3>Need help?</h3>
    <p class="mini" style="line-height:1.7">WhatsApp <a class="link" href="https://wa.me/919000098877" target="_blank">90000 98877</a> · sales@ametecsindia.com<br>
    Ametecs India Private Limited, Kondapur, Hyderabad · GST 36AAHCT0971F1ZB<br>
    <a class="link" href="/privacy" target="_blank">Privacy</a> · <a class="link" href="/terms" target="_blank">Terms</a> ·
    <a class="link" href="/refunds" target="_blank">Refunds</a> · <a class="link" href="/contact" target="_blank">Contact</a></p>
  </div>`;

  // Billing profile loads after the shell so the rest of the page never waits on it.
  try {
    const b = await api('account/billing');
    document.getElementById('billCard').innerHTML = `
    <h3>Billing profile</h3>
    <div style="max-width:460px">
      <label>GSTIN</label><input id="bpGstin" maxlength="15" placeholder="e.g. 36AAHCT0971F1ZB" value="${esc(b.gstin || '')}">
      <label>State (place of supply)</label>
      <select id="bpState"><option value="">— select your GST state —</option>
        ${Object.entries(b.states).map(([c, n]) => `<option value="${c}" ${b.state_code === c ? 'selected' : ''}>${c} — ${esc(n)}</option>`).join('')}
      </select>
      <label>Billing address (printed on tax invoices)</label>
      <textarea id="bpAddr" rows="3" style="font-family:inherit;font-size:13.5px;padding:9px 11px;border:1.5px solid var(--border2);border-radius:8px;width:100%">${esc(b.billing_address || '')}</textarea>
      <p class="mini" style="margin-top:8px">GSTIN required to claim input tax credit. Telangana (36) customers are invoiced CGST&nbsp;9% + SGST&nbsp;9%; other states IGST&nbsp;18%. Already-issued invoices keep their original details.</p>
      <button class="btn btn-p" style="margin-top:10px" onclick="saveBilling()">Save billing profile</button>
    </div>`;
  } catch (err) {
    document.getElementById('billCard').innerHTML = '<h3>Billing profile</h3><p class="mini">' + esc(err.message) + '</p>';
  }
}
async function saveBilling() {
  try {
    await api('account/billing', {method:'PUT', body:{
      gstin: document.getElementById('bpGstin').value.trim().toUpperCase() || null,
      state_code: document.getElementById('bpState').value || null,
      billing_address: document.getElementById('bpAddr').value.trim() || null,
    }});
    toast('Billing profile saved — it will appear on your next invoice');
  } catch (err) { toast(err.message); }
}
async function changePass() {
  try {
    await api('account/password', {method:'POST', body:{current_password:document.getElementById('curPass').value, password:document.getElementById('newPass').value}});
    toast('Password updated');
    document.getElementById('curPass').value = document.getElementById('newPass').value = '';
  } catch (err) { toast(err.message); }
}

// ---------- modal ----------
function modal(html) { document.getElementById('modalBox').innerHTML = html; document.getElementById('modalOv').classList.add('show'); }
function closeModal() { document.getElementById('modalOv').classList.remove('show'); }
document.getElementById('modalOv').addEventListener('click', e => { if (e.target.id === 'modalOv') closeModal(); });

// ---------- ⓘ HELP (3-tab, per screen) ----------
const HELP = {
  overview: {
    use: `<h4>What is this screen for?</h4><p>Your SmartEPT account at a glance — plan, devices, expiry and company details.</p>
    <h4>Step by step</h4><ol><li>Check the four cards on top: plan, devices used, days left, status.</li>
    <li>On trial? The teal banner shows days remaining — click <b>Choose a plan</b> when ready.</li>
    <li>Unpaid orders show an amber banner — clear them so the licence never lapses.</li></ol>
    <div class="tip"><b>Good to know:</b> the expiry card turns amber at 30 days and red at 7 — renew before it turns red and you lose zero days.</div>`,
    why: `<p>Nobody should need to call their vendor to know "when does my licence end and what do I owe?" This screen keeps you in control of your SmartEPT spend the way netbanking keeps you in control of your account.</p>
    <div class="scen"><b>Picture this:</b> ABC Recoveries' accounts head opens the portal on the 1st, sees "43 days left, 48/50 devices", and plans next quarter's budget of ₹36,900 renewal in two minutes — no emails, no waiting.</div>
    <h4>What you gain</h4><p><span class="gain">✔</span> Zero surprises on expiry &nbsp;<span class="gain">✔</span> One place for licence + billing truth &nbsp;<span class="gain">✔</span> Renew before agents ever notice</p>`,
    right: `<h4>Do it right</h4><p><b>Mistake:</b> waiting for the licence to expire before paying. <b>Impact:</b> after the grace period, tracking pauses on all devices. <b>Right way:</b> renew when the card turns amber — the new period stacks on top, you lose nothing.</p>`,
  },
  licence: {
    use: `<h4>What is this screen for?</h4><p>Every licence key you own and every endpoint device consuming a seat.</p>
    <h4>Step by step</h4><ol><li>Copy your licence key (the grey box) — you enter it once in your SmartEPT server setup.</li>
    <li>Each workstation the agent is installed on appears below with its seat status.</li>
    <li>Replacing a PC? Ask us to deactivate the old device — the seat frees instantly for the new one.</li></ol>
    <div class="tip"><b>Good to know:</b> shifts share seats — one workstation used by 3 shift agents = 1 licence, not 3.</div>`,
    why: `<p>You pay per DEVICE, not per employee — this screen is the proof. Watching your seat usage tells you exactly when to buy more (and when not to).</p>
    <div class="scen"><b>Picture this:</b> Godavari Finserv runs 120 seats. Their ops head sees 118 active before peak season and buys 30 more devices from Buy &amp; Renew the same evening — agents onboard next morning without a single support ticket.</div>
    <h4>What you gain</h4><p><span class="gain">✔</span> Pay only for devices actually used &nbsp;<span class="gain">✔</span> Instant seat reassignment &nbsp;<span class="gain">✔</span> No audit-day surprises</p>`,
    right: `<h4>Do it right</h4><p><b>Mistake:</b> sharing your licence key on email chains. <b>Impact:</b> anyone with the key could try activating rogue devices against your seats. <b>Right way:</b> the key lives only inside your SmartEPT server settings; treat it like a bank password.</p>`,
  },
  buy: {
    use: `<h4>What is this screen for?</h4><p>Buy, upgrade or renew — with the exact same pricing engine our own sales team uses.</p>
    <h4>Step by step</h4><ol><li>Pick a plan card (Professional ★ is the flagship).</li>
    <li>Enter devices, choose Annual/Monthly and hosting.</li>
    <li>Watch the live total — volume discounts apply automatically.</li>
    <li><b>Pay now</b> for instant activation, or <b>Raise quotation</b> if management pays.</li></ol>
    <div class="tip"><b>Good to know:</b> the one-time Setup &amp; Onboarding fee (₹5,000 covering 25 devices, +₹100/extra) appears only on your FIRST invoice — never again.</div>`,
    why: `<p>Buying software in India usually means three calls, two emails and a week of waiting. Here the price is public, the discount is automatic, and payment activates the licence in seconds — even at 11 pm on a Sunday.</p>
    <div class="scen"><b>Picture this:</b> Krishna NBFC's manager configures 50 Professional devices annual: 50 × ₹49 × 12 = ₹29,400, + ₹7,500 setup, + GST = <b>₹43,542</b>. He raises a quotation, WhatsApps the PDF to his MD, the MD taps the pay link, pays by UPI — and the licence key is live before their tea gets cold.</div>
    <h4>What you gain</h4><p><span class="gain">✔</span> Transparent volume pricing &nbsp;<span class="gain">✔</span> Instant activation, 24×7 &nbsp;<span class="gain">✔</span> Manager-proposes / management-pays flow built in</p>`,
    right: `<h4>Do it right</h4><p><b>Mistake:</b> buying monthly "to be safe" for a permanent team. <b>Impact:</b> you pay ₹79 instead of ₹59 per device — 34% more for the same thing. <b>Right way:</b> annual for the stable core team; monthly only for short campaigns.</p>
    <p style="margin-top:8px"><b>Mistake:</b> underbuying devices and juggling seats weekly. <b>Impact:</b> agents blocked at login, supervisors firefighting. <b>Right way:</b> buy for your real desk count — volume tiers make extra seats cheaper, not dearer.</p>`,
  },
  billing: {
    use: `<h4>What is this screen for?</h4><p>Every order, quotation and GST invoice of your account — payable, printable, downloadable.</p>
    <h4>Step by step</h4><ol><li>Unpaid orders show a <b>Pay</b> link — it opens the secure checkout.</li>
    <li>Quotations show <b>Print quote</b> — a branded PDF valid 15 days, carrying the pay link.</li>
    <li>Invoices have <b>Print / PDF</b> — GST-complete, ready for your accountant.</li></ol>
    <div class="tip"><b>Good to know:</b> invoices generate AUTOMATICALLY the second a payment lands — Razorpay, Stripe, or NEFT recorded by our team. Numbering follows EPT-FY-MM-#### so your auditor can trace every rupee.</div>`,
    why: `<p>GST filing time should not mean emailing vendors for missing invoices. Everything your CA needs is here, numbered in a clean financial-year series, one click to print.</p>
    <div class="scen"><b>Picture this:</b> during March closing, ABC Recoveries' accountant downloads all FY invoices in ten minutes and reconciles ₹4.2 lakh of SmartEPT spend without a single phone call.</div>
    <h4>What you gain</h4><p><span class="gain">✔</span> Audit-ready GST paper trail &nbsp;<span class="gain">✔</span> Quotation-to-payment in one thread &nbsp;<span class="gain">✔</span> No lost invoices, ever</p>`,
    right: `<h4>Do it right</h4><p><b>Mistake:</b> paying by NEFT and not telling anyone. <b>Impact:</b> money sits unmatched; licence stays inactive. <b>Right way:</b> after NEFT/UPI transfer, WhatsApp us the UTR — we record it and the same golden automation issues your licence + invoice.</p>`,
  },
  storage: {
    use: `<h4>What is this screen for?</h4><p>For SmartEPT-Managed Cloud customers: what you stored, what is billable, what this month costs.</p>
    <h4>Step by step</h4><ol><li>Top cards: average GB, billable GB (minimum 50), and the month's rent.</li>
    <li>The table shows daily readings — spot growth trends early.</li>
    <li>Client-hosted? This screen simply explains why you owe nothing here.</li></ol>
    <div class="tip"><b>Good to know:</b> billing uses your monthly AVERAGE, not the peak — a one-day spike will not spike your bill.</div>`,
    why: `<p>Cloud bills elsewhere are famous for surprises. SmartEPT storage is deliberately boring: ₹3/GB/month (cheaper at scale), a 50 GB minimum, shown to you daily — you always know before we bill.</p>
    <div class="scen"><b>Picture this:</b> Godavari Finserv averages 200 GB → 200 × ₹3 = <b>₹600/month</b> for fully-managed, backed-up evidence storage — less than one chai per agent.</div>
    <h4>What you gain</h4><p><span class="gain">✔</span> Daily visibility, monthly billing &nbsp;<span class="gain">✔</span> Slab rates drop as you grow &nbsp;<span class="gain">✔</span> Control the bill via retention policy</p>`,
    right: `<h4>Do it right</h4><p><b>Mistake:</b> keeping every screenshot forever "just in case". <b>Impact:</b> storage grows ~15–30 GB/month per 100 devices and so does the rent. <b>Right way:</b> set retention to your compliance need (say 90 days) — evidence stays defensible, bill stays flat.</p>`,
  },
  account: {
    use: `<h4>What is this screen for?</h4><p>Your login identity and password.</p>
    <h4>Step by step</h4><ol><li>Check the account and company you are signed in as.</li>
    <li>Change your password — current password needed, new one min 8 characters.</li>
    <li>Forgot it entirely? Log out and use "Forgot password" — a code comes by email.</li></ol>`,
    why: `<p>This portal can spend company money and read licence keys — it deserves a real password, changed by the person who owns it, without raising a ticket.</p>
    <h4>What you gain</h4><p><span class="gain">✔</span> Self-service security &nbsp;<span class="gain">✔</span> OTP-backed recovery &nbsp;<span class="gain">✔</span> No shared logins</p>`,
    right: `<h4>Do it right</h4><p><b>Mistake:</b> one login shared across accounts, ops and IT. <b>Impact:</b> no accountability for who bought what. <b>Right way:</b> keep the owner login with the decision-maker; ask us to add separate manager logins (coming to this screen soon).</p>`,
  },
};
let helpTabIdx = 0;
function openHelp() {
  helpTabIdx = 0;
  document.querySelectorAll('.help-tabs button').forEach((b, i) => b.classList.toggle('on', i === 0));
  document.getElementById('helpTitle').textContent = TITLES[PAGE];
  drawHelp();
  document.getElementById('helpOv').classList.add('show');
}
function closeHelp() { document.getElementById('helpOv').classList.remove('show'); }
function helpTab(i, btn) {
  helpTabIdx = i;
  document.querySelectorAll('.help-tabs button').forEach(b => b.classList.toggle('on', b === btn));
  drawHelp();
}
function drawHelp() {
  const h = HELP[PAGE] || {use:'<p>General screen.</p>', why:'<p></p>', right:'<p></p>'};
  document.getElementById('helpBody').innerHTML = [h.use, h.why, h.right][helpTabIdx];
}
document.getElementById('helpOv').addEventListener('click', e => { if (e.target.id === 'helpOv') closeHelp(); });

render();
</script>
</body>
</html>
