{{--
  04-Oct-2026 — PC Audit Log (Enforcer + Commander). Own file, own IIFE: exposes only
  window.initPcAudit / window.applyPcAuditNav. Uses the console helpers at call time:
  api, apiBlob, esc, toast. Nav visibility comes from /endpoint-security/access (capability pc_audit).
--}}
<div class="view" id="v-pcaudit">
  <div class="card" id="pa-card">
    <h3>PC Audit Log <span class="hint">everything that happened on each PC where the SmartEPT Agent is installed</span></h3>
    <div class="filters" style="margin-bottom:10px">
      <label class="mut" style="padding:0">From</label> <input type="date" id="pa-from">
      <label class="mut" style="padding:0">To</label> <input type="date" id="pa-to">
      <input id="pa-q" placeholder="Search PC or employee" style="min-width:200px">
      <span style="margin-left:auto"></span>
      <button class="btn solid" id="pa-all">Run audit for all PCs</button>
    </div>
    <table><thead><tr><th>PC</th><th>Employee</th><th>Windows</th><th>Agent</th><th>Agent Service</th><th>Last seen</th><th></th></tr></thead>
      <tbody id="pa-rows"><tr><td colspan="7" class="mut">Loading…</td></tr></tbody></table>
  </div>

  <div class="card">
    <h3>All-PC audit reports <span class="hint">built in the background — download when ready</span></h3>
    <table><thead><tr><th>Requested</th><th>By</th><th>Date range</th><th>Status</th><th>PCs</th><th>Entries</th><th></th></tr></thead>
      <tbody id="pa-reps"><tr><td colspan="7" class="mut">Loading…</td></tr></tbody></table>
  </div>
</div>

<style>
  /* 04-Oct-2026: the per-PC log opens full-window as a timeline: day > hour > 10 minutes > entries. */
  #pa-ovl .modal { width:100vw; max-width:100vw; height:100vh; max-height:100vh; border-radius:0; display:flex; flex-direction:column; }
  #pa-ovl .mbody { flex:1; overflow:auto; }
  #pa-ovl .pa-bar { display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-bottom:10px; }
  #pa-ovl .pa-g { border:1px solid var(--line, #e3e8ec); border-radius:8px; margin:6px 0; background:var(--card, #fff); }
  #pa-ovl .pa-h { display:flex; gap:10px; align-items:center; padding:9px 12px; cursor:pointer; user-select:none; }
  #pa-ovl .pa-h:hover { background:rgba(0,0,0,.03); }
  #pa-ovl .pa-pm { width:22px; height:22px; border-radius:6px; display:inline-flex; align-items:center; justify-content:center; font-weight:700; border:1px solid currentColor; opacity:.75; flex:none; }
  #pa-ovl .pa-day > .pa-h { font-size:15px; font-weight:700; }
  #pa-ovl .pa-hour { margin-left:22px; }
  #pa-ovl .pa-slot { margin-left:44px; }
  #pa-ovl .pa-sum { margin-left:auto; display:flex; gap:6px; flex-wrap:wrap; justify-content:flex-end; }
  #pa-ovl .pa-sum .tag { font-size:11px; }
  #pa-ovl .pa-in { padding:0 12px 10px 12px; }
  #pa-ovl .pa-in table td { vertical-align:top; }
  #pa-ovl .pa-dev { display:grid; grid-template-columns:repeat(6,minmax(0,1fr)); border:1px solid var(--border); border-radius:10px; margin-bottom:12px; background:var(--card); overflow:hidden; }
  #pa-ovl .pa-dev div { padding:9px 12px; border-right:1px solid var(--hairline); min-width:0; }
  #pa-ovl .pa-dev div:last-child { border-right:0; }
  #pa-ovl .pa-dev .k { display:block; font-size:10.5px; text-transform:uppercase; letter-spacing:.05em; color:var(--ink-3); }
  #pa-ovl .pa-dev .v { font-weight:700; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; display:block; }
  #pa-ovl .pa-bar { background:var(--card-2); border:1px solid var(--border); border-radius:10px; padding:10px 12px; }
  #pa-ovl .pa-bar label { display:inline; margin:0; padding:0; font-size:12px; color:var(--ink-2); font-weight:600; }
  #pa-ovl .pa-bar input[type=date] { width:auto; min-width:150px; flex:none; margin:0; }
  #pa-ovl .pa-bar input#pa-mq { width:auto; margin:0; }
  #pa-ovl .pa-bar .sep { width:1px; height:26px; background:var(--border); margin:0 4px; }
  #pa-ovl .pa-dl { border:1px solid var(--accent,#006699); background:var(--accent-weak,#E0F0F8); border-radius:7px; height:26px; padding:0 8px; gap:4px; display:inline-flex; align-items:center; cursor:pointer; color:var(--accent-ink,#00527A); font-size:11px; font-weight:700; flex:none; }
  #pa-ovl .pa-dl:hover { background:var(--accent,#006699); color:#fff; }
  #pa-ovl .pa-dl svg { width:15px; height:15px; }
  #pa-ovl tr.pa-bad td { background:var(--danger-w,#FDECEC); color:var(--danger,#B42318); }
  #pa-ovl tr.pa-bad td b { color:var(--danger,#B42318); }
  @media (max-width:900px){ #pa-ovl .pa-dev { grid-template-columns:repeat(2,1fr); } }
</style>
<div class="ovl" id="pa-ovl"><div class="modal">
  <div class="mhead"><div class="mt"><b id="pa-m-title">PC Audit Log</b><span id="pa-m-sub"></span></div>
    <button class="x" id="pa-x">&#10005;</button></div>
  <div class="mbody">
    <div class="pa-dev" id="pa-dev"></div>
    <div class="pa-bar">
      <label for="pa-mfrom">From</label> <input type="date" id="pa-mfrom">
      <label for="pa-mto">To</label> <input type="date" id="pa-mto">
      <button class="btn solid" id="pa-mapply">Show</button>
      <span class="sep"></span>
      <input id="pa-mq" placeholder="Search in these logs" style="flex:1;min-width:180px">
      <button class="btn" id="pa-expand">Expand all</button>
      <button class="btn" id="pa-collapse">Collapse all</button>
      <span class="sep"></span>
      <button class="btn" id="pa-csv">Export CSV</button>
      <button class="btn solid" id="pa-pdf">Export PDF</button>
    </div>
    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px" id="pa-chips"></div>
    <div class="mut" id="pa-note" style="padding:0 0 6px"></div>
    <div id="pa-tree"></div>
  </div>
</div></div>

<script>
(function () {
  'use strict';
  var DEVS = [], LOG = null, CUR = null, CAT = '', POLL = null, WAS_BUSY = {}, OPEN = {}, TREE = [], GROUPS = {};
  function el(s) { return document.querySelector(s); }
  function today() { var d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
  function when(iso) { if (!iso) return '—'; var d = new Date(iso); return isNaN(d) ? esc(iso) : d.toLocaleString(); }
  function range() { return 'from=' + encodeURIComponent(el('#pa-from').value) + '&to=' + encodeURIComponent(el('#pa-to').value); }
  // 05-Oct-2026: a USB drive / device Windows or SmartEPT refused = someone tried to connect it. Red row.
  function devBlocked(r) { return r.category === 'device' && /^blocked/i.test(r.outcome || ''); }
  function outTag(o) {
    if (!o) return '';
    var cls = /blocked/i.test(o) ? 't-danger' : /allowed|completed|succeeded/i.test(o) ? 't-ok' : /violation|restricted|failed|would/i.test(o) ? 't-warn' : 't-info';
    return '<span class="tag ' + cls + '">' + esc(o) + '</span>';
  }

  window.applyPcAuditNav = async function () {
    var nav = el('.nav[data-view="pcaudit"]');
    if (!nav) return;
    try {
      var a = (await api('/endpoint-security/access')).data;
      nav.style.display = a && a.can_view && a.capabilities.indexOf('pc_audit') !== -1 ? '' : 'none';
    } catch (e) { nav.style.display = 'none'; }
  };

  window.initPcAudit = async function () {
    if (!el('#pa-from').value) { el('#pa-from').value = today(); el('#pa-to').value = today(); }
    loadReports();
    try {
      DEVS = (await api('/pc-audit/devices')).data || [];
      renderDevs();
    } catch (e) { el('#pa-rows').innerHTML = '<tr><td colspan="7" class="mut">' + esc(e.message) + '</td></tr>'; }
  };

  function renderDevs() {
    var q = (el('#pa-q').value || '').toLowerCase();
    var rows = DEVS.filter(function (d) { return !q || ((d.device || '') + ' ' + (d.employee || '')).toLowerCase().indexOf(q) !== -1; });
    el('#pa-rows').innerHTML = rows.length ? rows.map(function (d) {
      return '<tr><td><b>' + esc(d.device) + '</b></td><td>' + esc(d.employee || '—') + (d.employee_code ? ' <span class="mut" style="padding:0">(' + esc(d.employee_code) + ')</span>' : '')
        + '</td><td>' + esc(d.os || '—') + '</td><td>' + esc(d.agent_version || '—') + '</td><td>' + esc(d.service_version || '—') + '</td><td>' + when(d.last_seen)
        + '</td><td><button class="btn" data-view-logs="' + esc(d.device_uuid) + '">View logs</button></td></tr>';
    }).join('') : '<tr><td colspan="7" class="mut">No PCs with the SmartEPT Agent yet.</td></tr>';
  }

  function mrange() { return 'from=' + encodeURIComponent(el('#pa-mfrom').value) + '&to=' + encodeURIComponent(el('#pa-mto').value); }
  async function openLogs(uuid) {
    CUR = uuid; CAT = ''; el('#pa-mq').value = ''; OPEN = {};
    el('#pa-mfrom').value = el('#pa-from').value; el('#pa-mto').value = el('#pa-to').value;
    el('#pa-ovl').classList.add('open');
    return loadLog();
  }
  async function loadLog() {
    el('#pa-tree').innerHTML = '<div class="mut">Loading…</div>';
    el('#pa-chips').innerHTML = ''; el('#pa-note').textContent = '';
    try {
      LOG = await api('/pc-audit/devices/' + encodeURIComponent(CUR) + '?' + mrange());
      el('#pa-m-title').textContent = LOG.device;
      var d = devInfo();
      el('#pa-dev').innerHTML = [['PC', LOG.device], ['Employee', d.employee ? d.employee + (d.employee_code ? ' (' + d.employee_code + ')' : '') : '—'], ['Windows', d.os || '—'],
        ['SmartEPT Agent', d.agent_version || '—'], ['Agent Service', d.service_version || '—'], ['Last seen', d.last_seen ? when(d.last_seen) : '—']]
        .map(function (x) { return '<div><span class="k">' + esc(x[0]) + '</span><span class="v" title="' + esc(x[1]) + '">' + esc(x[1]) + '</span></div>'; }).join('');
      el('#pa-m-sub').textContent = LOG.from === LOG.to ? LOG.from : LOG.from + ' to ' + LOG.to;
      el('#pa-note').textContent = LOG.total + ' entries' + (LOG.shown < LOG.total ? ' — showing the newest ' + LOG.shown + '; Download CSV has all of them' : '');
      var days = {}; LOG.data.forEach(function (x) { days[x.at.slice(0, 10)] = 1; });
      if (Object.keys(days).length === 1) OPEN['d' + Object.keys(days)[0]] = true; // one day: open it
      renderLog();
    } catch (e) { el('#pa-tree').innerHTML = '<div class="mut">' + esc(e.message) + '</div>'; }
  }

  function renderLog() {
    var c = LOG.counts || {}, chips = ['<button class="btn' + (CAT ? '' : ' solid') + '" data-cat="">All (' + LOG.total + ')</button>'];
    Object.keys(LOG.categories).forEach(function (k) {
      chips.push('<button class="btn' + (CAT === k ? ' solid' : '') + '" data-cat="' + k + '">' + esc(LOG.categories[k]) + ' (' + (c[k] || 0) + ')</button>');
    });
    el('#pa-chips').innerHTML = chips.join('');
    var q = (el('#pa-mq').value || '').toLowerCase();
    var rows = LOG.data.filter(function (r) {
      return (!CAT || r.category === CAT) && (!q || (r.event + ' ' + r.detail + ' ' + r.outcome + ' ' + (r.employee || '')).toLowerCase().indexOf(q) !== -1);
    });
    if (!rows.length) { el('#pa-tree').innerHTML = '<div class="mut">Nothing recorded for this PC with these filters.</div>'; return; }

    // day > hour > 10-minute slot, newest first (LOG.data already is). Only open groups render
    // their children, so a busy day stays a short list of headers.
    var tree = [], idx = {};
    rows.forEach(function (r) {
      var d = r.at.slice(0, 10), h = r.at.slice(11, 13), m = String(Math.floor(+r.at.slice(14, 16) / 10) * 10).padStart(2, '0');
      var dk = 'd' + d, hk = dk + 'h' + h, sk = hk + 'm' + m;
      if (!idx[dk]) { idx[dk] = { key: dk, label: d, rows: [], kids: [] }; tree.push(idx[dk]); }
      if (!idx[hk]) { idx[hk] = { key: hk, label: h + ':00 – ' + h + ':59', rows: [], kids: [] }; idx[dk].kids.push(idx[hk]); }
      if (!idx[sk]) { idx[sk] = { key: sk, label: h + ':' + m + ' – ' + h + ':' + String(+m + 9).padStart(2, '0'), rows: [], kids: null }; idx[hk].kids.push(idx[sk]); }
      idx[dk].rows.push(r); idx[hk].rows.push(r); idx[sk].rows.push(r);
    });
    function summary(list) {
      var n = {}, bad = 0;
      list.forEach(function (r) { n[r.category] = (n[r.category] || 0) + 1; if (/blocked|violation/i.test(r.outcome || '') || r.category === 'blocked' || r.category === 'tamper') bad++; });
      return Object.keys(n).sort(function (a, b) { return n[b] - n[a]; }).map(function (k) {
        return '<span class="tag t-off">' + esc(LOG.categories[k] || k) + ' ' + n[k] + '</span>'; }).join('')
        + (bad ? '<span class="tag t-danger">' + bad + ' to review</span>' : '');
    }
    function day(d) { var t = new Date(d + 'T00:00:00'); return isNaN(t) ? d : t.toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }); }
    function group(g, cls, label) {
      var open = !!OPEN[g.key];
      var html = '<div class="pa-g ' + cls + '"><div class="pa-h" data-key="' + g.key + '"><span class="pa-pm">' + (open ? '&minus;' : '+') + '</span><span>' + label
        + '</span><span class="mut" style="padding:0">' + g.rows.length + ' entr' + (g.rows.length === 1 ? 'y' : 'ies') + '</span>'
        // Download PDF for exactly this date / hour / 10 minutes - right next to the count.
        + '<button class="pa-dl" data-pdf="' + g.key + '" title="Download this period as PDF"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4v11M7.5 10.5 12 15l4.5-4.5"/><path d="M5 19h14"/></svg><span>PDF</span></button>'
        + '<span class="pa-sum">' + summary(g.rows) + '</span></div>';
      if (open) {
        html += g.kids ? g.kids.map(function (k) { return group(k, k.kids ? 'pa-hour' : 'pa-slot', esc(k.label)); }).join('')
          : '<div class="pa-in"><table><thead><tr><th style="width:80px">Time</th><th style="width:130px">Category</th><th>Event</th><th>Details</th><th>Outcome</th><th>Employee</th></tr></thead><tbody>'
            + g.rows.map(function (r) {
              return '<tr' + (devBlocked(r) ? ' class="pa-bad"' : '') + '><td>' + esc(r.at.slice(11)) + '</td><td>' + esc(LOG.categories[r.category] || r.category) + '</td><td><b>' + esc(r.event) + '</b></td><td style="word-break:break-word">'
                + esc(r.detail) + '</td><td>' + outTag(r.outcome) + '</td><td>' + esc(r.employee || '') + '</td></tr>'; }).join('') + '</tbody></table></div>';
      }
      return html + '</div>';
    }
    TREE = tree; GROUPS = idx;
    el('#pa-tree').innerHTML = tree.map(function (d) { return group(d, 'pa-day', esc(day(d.label))); }).join('');
  }

  function devInfo() { return DEVS.filter(function (d) { return d.device_uuid === CUR; })[0] || {}; }
  function filtered() {
    var q = (el('#pa-mq').value || '').toLowerCase();
    return LOG.data.filter(function (r) {
      return (!CAT || r.category === CAT) && (!q || (r.event + ' ' + r.detail + ' ' + r.outcome + ' ' + (r.employee || '')).toLowerCase().indexOf(q) !== -1);
    });
  }
  // "d2026-10-04h15m30" -> "2026-10-04, 15:30 – 15:39"
  function periodLabel(k) {
    var m = /^d(\d{4}-\d{2}-\d{2})(?:h(\d{2}))?(?:m(\d{2}))?$/.exec(k) || [];
    if (!m[2]) return m[1];
    if (!m[3]) return m[1] + ', ' + m[2] + ':00 – ' + m[2] + ':59';
    return m[1] + ', ' + m[2] + ':' + m[3] + ' – ' + m[2] + ':' + String(+m[3] + 9).padStart(2, '0');
  }
  // 04-Oct-2026: audit-ready PDF of any period (the whole range, a day, an hour or 10 minutes).
  function logPdf(rows, period) {
    if (!window.eptAuditPdf) { toast('\u2715 PDF export is not available on this screen'); return; }
    if (!rows.length) { toast('Nothing to export for this period'); return; }
    var d = devInfo(), cats = LOG.categories, asc = rows.slice().sort(function (a, b) { return a.at < b.at ? -1 : a.at > b.at ? 1 : 0; });
    var n = {}, emps = {}, review = [];
    asc.forEach(function (r) {
      n[r.category] = (n[r.category] || 0) + 1; if (r.employee) emps[r.employee] = 1;
      if (r.category === 'blocked' || r.category === 'tamper' || /blocked|violation/i.test(r.outcome || '')) review.push(r);
    });
    function tag(o) { if (!o) return ''; var c = /blocked/i.test(o) ? 't-danger' : /allowed|completed|succeeded/i.test(o) ? 't-ok' : /violation|restricted|failed|would/i.test(o) ? 't-warn' : 't-info'; return '<span class="tag ' + c + '">' + esc(o) + '</span>'; }
    function table(list) {
      return '<table><thead><tr><th style="width:17%">Date / time</th><th style="width:12%">Category</th><th style="width:25%">Event</th><th>Details</th><th style="width:13%">Outcome</th><th style="width:11%">Employee</th></tr></thead><tbody>'
        + list.map(function (r) { return '<tr' + (devBlocked(r) ? ' class="bad"' : '') + '><td>' + esc(r.at) + '</td><td>' + esc(cats[r.category] || r.category) + '</td><td><b>' + esc(r.event) + '</b></td><td style="word-break:break-word">'
          + esc(r.detail) + '</td><td>' + tag(r.outcome) + '</td><td>' + esc(r.employee || '') + '</td></tr>'; }).join('') + '</tbody></table>';
    }
    var hours = {};
    asc.forEach(function (r) { var h = r.at.slice(0, 13); hours[h] = (hours[h] || 0) + 1; });
    var hourKeys = Object.keys(hours);
    var body = '<h2>Summary</h2><table><thead><tr><th>Category</th><th class="num" style="width:20%">Entries</th></tr></thead><tbody>'
      + Object.keys(n).sort(function (a, b) { return n[b] - n[a]; }).map(function (k) { return '<tr><td>' + esc(cats[k] || k) + '</td><td class="num">' + n[k] + '</td></tr>'; }).join('')
      + '<tr><td><b>Total</b></td><td class="num"><b>' + asc.length + '</b></td></tr></tbody></table>'
      + (hourKeys.length > 1 ? '<h2>Activity by hour</h2><table><thead><tr><th>Hour</th><th class="num" style="width:20%">Entries</th></tr></thead><tbody>'
        + hourKeys.map(function (h) { return '<tr><td>' + esc(h.slice(0, 10) + ', ' + h.slice(11) + ':00 – ' + h.slice(11) + ':59') + '</td><td class="num">' + hours[h] + '</td></tr>'; }).join('') + '</tbody></table>' : '')
      + '<h2>Items to review (' + review.length + ')</h2>' + (review.length ? table(review) : '<p class="mut">None — no blocked, violation or tamper entries in this period.</p>')
      + '<h2>Complete activity log (' + asc.length + ' entries, oldest first)</h2>' + table(asc);
    window.eptAuditPdf({
      file: 'SmartEPT-PC-Audit-' + LOG.device + '-' + period.replace(/[^0-9A-Za-z]+/g, '-'),
      refPrefix: 'EPT-PCA', type: 'PC Audit Log', title: 'PC Audit Log — ' + LOG.device, subtitle: 'Period: ' + period + (CAT ? ' · Category: ' + (cats[CAT] || CAT) : '') + ((el('#pa-mq').value || '').trim() ? ' · Search: "' + el('#pa-mq').value.trim() + '"' : ''),
      meta: [['PC', LOG.device], ['Employee', Object.keys(emps).join(', ') || d.employee || '—'], ['Employee code', d.employee_code || '—'], ['Windows', d.os || '—'],
        ['SmartEPT Agent / Service', (d.agent_version || '—') + ' / ' + (d.service_version || '—')], ['Period', period], ['Entries in report', String(asc.length)], ['Items to review', String(review.length)]],
      body: body,
    });
  }

  function setAll(open) {
    OPEN = {};
    if (open) (function walk(list) { list.forEach(function (g) { OPEN[g.key] = true; if (g.kids) walk(g.kids); }); })(TREE);
    renderLog();
  }

  async function save(path, name) {
    try {
      var blob = await apiBlob(path), a = document.createElement('a');
      a.href = URL.createObjectURL(blob); a.download = name; a.click();
      setTimeout(function () { URL.revokeObjectURL(a.href); }, 4000);
    } catch (e) { toast('✕ Download failed (' + e.message + ')'); }
  }

  async function runAll() {
    if (!confirm('Run the audit for ALL PCs from ' + el('#pa-from').value + ' to ' + el('#pa-to').value + '?\n\nIt runs in the background — you can keep working. The report appears below when done.')) return;
    // Not awaited: the request returns at once; the report list polls for the result.
    api('/pc-audit/reports', { method: 'POST', body: JSON.stringify({ from: el('#pa-from').value, to: el('#pa-to').value }) })
      .then(function () { loadReports(); }).catch(function (e) { toast('✕ ' + e.message); loadReports(); });
    toast('✓ Audit of all PCs started — it will appear below when done');
    setTimeout(loadReports, 1500);
  }

  async function loadReports() {
    try {
      var rows = (await api('/pc-audit/reports')).data || [], busy = false;
      el('#pa-reps').innerHTML = rows.length ? rows.map(function (r) {
        var st = { queued: ['t-warn', 'Queued'], running: ['t-info', 'Running…'], done: ['t-ok', 'Ready'], failed: ['t-danger', 'Failed'] }[r.status] || ['t-off', r.status];
        if (r.status === 'queued' || r.status === 'running') { busy = true; WAS_BUSY[r.id] = 1; }
        else if (WAS_BUSY[r.id]) { delete WAS_BUSY[r.id]; toast(r.status === 'done' ? '✓ All-PC audit is ready to download' : '✕ All-PC audit failed'); }
        return '<tr><td>' + when(r.requested_at) + '</td><td>' + esc(r.requested_by || '—') + '</td><td>' + esc(r.from === r.to ? r.from : r.from + ' to ' + r.to)
          + '</td><td><span class="tag ' + st[0] + '">' + st[1] + '</span>' + (r.error ? '<br><span class="mut" style="padding:0">' + esc(r.error) + '</span>' : '')
          + '</td><td>' + (r.status === 'done' ? r.devices : '') + '</td><td>' + (r.status === 'done' ? r.rows : '') + '</td><td>'
          + (r.status === 'done' ? '<button class="btn" data-rep="' + r.id + '" data-name="pc-audit-all-pcs-' + esc(r.from) + '-to-' + esc(r.to) + '.csv">Download</button>' : '') + '</td></tr>';
      }).join('') : '<tr><td colspan="7" class="mut">No all-PC audits yet. Pick a date range and click <b>Run audit for all PCs</b>.</td></tr>';
      clearTimeout(POLL);
      if (busy) POLL = setTimeout(loadReports, 5000);
    } catch (e) { el('#pa-reps').innerHTML = '<tr><td colspan="7" class="mut">' + esc(e.message) + '</td></tr>'; }
  }

  function bind() {
    if (!el('#pa-card') || el('#pa-card').dataset.bound) return;
    el('#pa-card').dataset.bound = '1';
    el('#pa-q').oninput = renderDevs;
    el('#pa-all').onclick = runAll;
    el('#pa-rows').addEventListener('click', function (e) { var b = e.target.closest('[data-view-logs]'); if (b) openLogs(b.dataset.viewLogs); });
    el('#pa-reps').addEventListener('click', function (e) { var b = e.target.closest('[data-rep]'); if (b) save('/pc-audit/reports/' + b.dataset.rep + '/download', b.dataset.name); });
    el('#pa-chips').addEventListener('click', function (e) { var b = e.target.closest('[data-cat]'); if (b && LOG) { CAT = b.dataset.cat; renderLog(); } });
    el('#pa-mq').oninput = function () { if (LOG) renderLog(); };
    el('#pa-tree').addEventListener('click', function (e) {
      var dl = e.target.closest('[data-pdf]');
      if (dl) { e.stopPropagation(); var g = GROUPS[dl.dataset.pdf]; if (g) logPdf(g.rows, periodLabel(dl.dataset.pdf)); return; }
      var h = e.target.closest('.pa-h'); if (!h) return;
      OPEN[h.dataset.key] = !OPEN[h.dataset.key]; renderLog();
    });
    el('#pa-mapply').onclick = function () { OPEN = {}; loadLog(); };
    el('#pa-expand').onclick = function () { setAll(true); };
    el('#pa-pdf').onclick = function () { if (LOG) logPdf(filtered(), LOG.from === LOG.to ? LOG.from : LOG.from + ' to ' + LOG.to); };
    el('#pa-collapse').onclick = function () { setAll(false); };
    el('#pa-csv').onclick = function () {
      if (!LOG) return;
      save('/pc-audit/devices/' + encodeURIComponent(CUR) + '/csv?from=' + LOG.from + '&to=' + LOG.to + (CAT ? '&category=' + CAT : ''),
        'pc-audit-' + LOG.device + '-' + LOG.from + '-to-' + LOG.to + '.csv');
    };
    el('#pa-x').onclick = function () { el('#pa-ovl').classList.remove('open'); };
  }
  document.addEventListener('DOMContentLoaded', bind);
  if (document.readyState !== 'loading') bind();
})();
</script>
