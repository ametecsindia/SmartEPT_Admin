{{--
  03-Oct-2026 — SmartEPT Endpoint Security screen (Enforcer + Commander).
  Kept OUT of admin.blade.php's main <script> on purpose: everything below lives in
  one IIFE and exposes only window.initEndSec / window.applyEndSecNav, so no name
  here can ever collide with (and break) the main console script. Uses the
  console's own helpers at call time: api, apiBlob, esc, dt, $, toast, ME.
--}}
<div class="view" id="v-endsec">
  <div class="card" id="es-card">
    <h3>Endpoint Security <span class="hint">Protected by Microsoft Defender · Monitored by SmartEPT</span>
      <span class="tag t-info" id="es-level" style="float:right"></span></h3>
    <div class="kpis" id="es-kpis"></div>
    <div class="filters" style="margin-bottom:10px">
      <input id="es-q" placeholder="Search employee or device" style="min-width:220px">
      <select id="es-f" autocomplete="off">
        <option value="">All endpoints</option>
        <option value="COMPLIANT">Protected</option>
        <option value="ACTION_REQUIRED">Attention required</option>
        <option value="NON_COMPLIANT">Non-compliant</option>
        <option value="UNKNOWN">Unable to verify</option>
      </select>
      {{-- 05-Oct-2026: report controls in their own wrapping groups, so nothing runs past the window --}}
      <span class="es-grp" style="margin-left:auto"><select id="es-rep"></select>
        <button class="btn" id="es-export">Export CSV</button></span>
      <span class="es-grp"><span class="mut" style="padding:0">Compliance report</span>
        <input type="date" id="es-cr-from" title="From"><input type="date" id="es-cr-to" title="To">
        <button class="btn solid" id="es-cr" title="Every PC, every checkpoint, for the chosen period — audit-ready PDF">Download PDF</button></span>
    </div>
    <div class="es-scroll"><table><thead><tr><th>Employee</th><th>Device</th><th>Antivirus</th><th>Status</th><th>Real-Time</th><th>Definitions</th>
      <th>Last Scan</th><th>Threats</th><th>Firewall</th><th>Compliance</th><th>Last Seen</th><th></th></tr></thead>
      <tbody id="es-rows"><tr><td colspan="12" class="mut">Loading…</td></tr></tbody></table></div>
  </div>

  <div class="card" id="es-cmd-card" style="display:none">
    <h3>Command history <span class="hint">every remote security action, who asked for it and what happened</span></h3>
    <div class="es-scroll"><table><thead><tr><th>Requested</th><th>Administrator</th><th>Device</th><th>Employee</th><th>Action</th><th>Status</th><th>Completed</th><th>Result</th></tr></thead>
      <tbody id="es-cmd-rows"></tbody></table></div>
  </div>

  <div class="card" id="es-pol-card" style="display:none">
    <h3>Security compliance policy <span class="hint" id="es-pol-hint"></span></h3>
    <div class="fgrid" id="es-pol"></div>
    <div style="margin-top:12px"><button class="btn solid" id="es-pol-save">Save policy</button> <span class="mut" id="es-pol-msg"></span></div>
  </div>
</div>

<style>
  /* 05-Oct-2026: page fits the window — wide tables scroll inside their card, toolbar groups wrap. */
  #v-endsec .es-scroll{overflow-x:auto;max-width:100%}
  #v-endsec .es-grp{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
  #v-endsec .filters input[type=date]{min-width:0}
  #v-endsec .filters select#es-rep{min-width:0;max-width:240px}
  #v-endsec td .btn{white-space:nowrap}
  #v-endsec #es-rows td:last-child{position:sticky;right:0;background:var(--card)}
  /* 04-Oct-2026: "View report" — banner, tiles and section cards instead of plain text. */
  .esr-banner{display:flex;gap:14px;align-items:center;padding:16px 18px;border-radius:12px;margin-bottom:14px;border:1px solid}
  .esr-banner .ic{width:44px;height:44px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:22px;font-weight:800;color:#fff;flex:none}
  .esr-banner b{display:block;font-size:16px;font-family:var(--font-head)} .esr-banner span{color:var(--ink-2);font-size:13px}
  .esr-ok{background:var(--ok-w);border-color:var(--ok)} .esr-ok .ic{background:var(--ok)}
  .esr-bad{background:var(--danger-w);border-color:var(--danger)} .esr-bad .ic{background:var(--danger)}
  .esr-run{background:var(--info-w);border-color:var(--info)} .esr-run .ic{background:var(--info)}
  .esr-warn{background:var(--warn-w);border-color:var(--warn)} .esr-warn .ic{background:var(--warn)}
  .esr-tiles{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:14px}
  .esr-tile{border:1px solid var(--border);border-radius:10px;padding:10px 12px;background:var(--card)}
  .esr-tile .l{font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:var(--ink-3)}
  .esr-tile .v{font-size:20px;font-weight:700;font-family:var(--font-head);margin-top:2px}
  .esr-tile.ok .v{color:var(--ok)} .esr-tile.bad .v{color:var(--danger)} .esr-tile.warn .v{color:var(--warn)}
  .esr-card{border:1px solid var(--border);border-radius:12px;margin-bottom:12px;overflow:hidden;background:var(--card)}
  .esr-card h5{margin:0;padding:10px 14px;font-size:13px;background:var(--card-2);border-bottom:1px solid var(--hairline);display:flex;justify-content:space-between;align-items:center}
  .esr-kv{display:grid;grid-template-columns:repeat(2,1fr)}
  .esr-kv div{padding:9px 14px;border-bottom:1px solid var(--hairline)} .esr-kv div .k{display:block;font-size:11px;color:var(--ink-3);text-transform:uppercase;letter-spacing:.04em}
  .esr-empty{padding:14px;color:var(--ink-2);font-size:13px}
  .esr-card table{margin:0} .esr-card td,.esr-card th{padding:8px 14px}
  @media (max-width:700px){.esr-tiles{grid-template-columns:repeat(2,1fr)}.esr-kv{grid-template-columns:1fr}}
</style>
<div class="ovl" id="es-ovl"><div class="modal" style="width:820px">
  <div class="mhead"><div class="mt"><b id="es-m-title">Endpoint Security</b><span>Protected by Microsoft Defender · Monitored by SmartEPT</span></div>
    <button class="x" id="es-x">&#10005;</button></div>
  <div class="mbody" id="es-m-body" style="overflow:auto"></div>
</div></div>

<script>
(function () {
  'use strict';
  var ACCESS = null, ROWS = [], CUR = null, REPORT = null;
  var REPORTS = [['summary', 'Endpoint Security Summary'], ['antivirus', 'Antivirus Status Report'], ['signatures', 'Outdated Signature Report'],
    ['threats', 'Threat Detection Report'], ['firewall', 'Firewall Health Report'], ['compliance', 'Compliance Report']];
  var REPORTS_ADV = [['actions', 'Security Action History'], ['audit', 'Administrator Security Audit']];
  var TYPE_LABEL = { AV_STATUS_REFRESH: 'Refresh status', AV_QUICK_SCAN: 'Microsoft Defender Quick Scan', AV_FULL_SCAN: 'Microsoft Defender Full Scan',
    AV_CUSTOM_SCAN: 'Microsoft Defender Custom Scan', AV_SIGNATURE_UPDATE: 'Signature update' };
  var COMP = { COMPLIANT: ['t-ok', 'Protected'], ACTION_REQUIRED: ['t-warn', 'Attention required'], NON_COMPLIANT: ['t-danger', 'Non-compliant'],
    UNKNOWN: ['t-off', 'Unknown / Unable to verify'] };
  function el(s) { return document.querySelector(s); }
  function can(c) { return !!(ACCESS && ACCESS.capabilities.indexOf(c) !== -1); }
  function yn(v, on, off) { return v === true ? '<span class="tag t-ok">' + (on || 'On') + '</span>' : v === false ? '<span class="tag t-danger">' + (off || 'Off') + '</span>' : '<span class="tag t-off">Unknown</span>'; }
  function ago(iso) { return iso ? dt(iso) : '—'; }

  async function loadAccess() {
    try { ACCESS = (await api('/endpoint-security/access')).data; } catch (e) { ACCESS = null; }
    return ACCESS;
  }
  window.applyEndSecNav = async function () {
    var nav = el('.nav[data-view="endsec"]');
    if (!nav) return;
    var a = await loadAccess();
    nav.style.display = a && a.can_view ? '' : 'none';
  };

  window.initEndSec = async function () {
    if (!ACCESS) await loadAccess();
    if (!ACCESS || !ACCESS.can_view) { el('#es-rows').innerHTML = deniedCard(); el('#es-kpis').innerHTML = ''; return; }
    el('#es-level').textContent = ACCESS.level === 'advanced' ? 'SMARTEPT COMMANDER' : 'SMARTEPT ENFORCER';
    el('#es-rep').innerHTML = REPORTS.concat(can('advanced_reports') ? REPORTS_ADV : [])
      .map(function (r) { return '<option value="' + r[0] + '">' + esc(r[1]) + '</option>'; }).join('');
    el('#es-cmd-card').style.display = can('command_history') ? '' : 'none';
    el('#es-pol-card').style.display = ACCESS.can_view_policy ? '' : 'none';
    await loadRows();
    if (can('command_history')) loadCommands();
    if (ACCESS.can_view_policy) loadPolicy();
  };

  async function loadRows() {
    try {
      var d = await api('/endpoint-security/overview');
      ROWS = d.data || [];
      var s = d.summary;
      var K = [['Total endpoints', s.total, 'k-total', 'ALL'], ['Protected', s.protected, 'k-ok', 'COMPLIANT'],
        ['Attention required', s.attention, 'k-away', 'ACTION_REQUIRED'], ['Non-compliant', s.non_compliant, 'k-viol', 'NON_COMPLIANT'],
        ['Real-time protection off', s.realtime_disabled, 'k-cam', ''], ['Signatures outdated', s.signatures_outdated, 'k-shot', ''],
        ['Threats detected', s.threats, 'k-viol', ''], ['Firewall issues', s.firewall_issues, 'k-away', '']];
      el('#es-kpis').innerHTML = K.map(function (k) {
        return '<div class="kpi' + (k[3] ? ' drill' : '') + ' ' + k[2] + '" data-f="' + k[3] + '"><div class="kside"><div class="l">' + esc(k[0])
          + '</div></div><div class="kmain"><div class="v">' + esc(String(k[1])) + '</div>' + (k[3] ? '<span class="go">' + (k[3] === 'ALL' ? 'Show all' : 'Filter') + '</span>' : '') + '</div></div>';
      }).join('') + (s.upgrade_required ? '<div class="mut" style="grid-column:1/-1;padding:0">' + s.upgrade_required
        + ' endpoint(s) show <b>Endpoint Security Agent Upgrade Required</b> — install the latest SmartEPT Agent on them.</div>' : '')
        + (s.service_offline ? '<div class="mut" style="grid-column:1/-1;padding:0;color:var(--danger)">' + s.service_offline
        + ' endpoint record(s) whose <b>SmartEPT Agent Service has not reported for over a day</b> — the service is stopped, removed, or the PC was renamed / reinstalled and now reports as a new record.</div>' : '');
      renderRows();
    } catch (e) {
      el('#es-rows').innerHTML = isDenied(e) ? deniedCard() : '<tr><td colspan="12" class="mut">' + esc(e.message) + '</td></tr>';
    }
  }

  function renderRows() {
    var q = (el('#es-q').value || '').toLowerCase(), f = el('#es-f').value;
    var rows = ROWS.filter(function (r) {
      return (!f || r.compliance === f) && (!q || ((r.employee ? r.employee.name : '') + ' ' + r.device).toLowerCase().indexOf(q) !== -1);
    });
    el('#es-rows').innerHTML = rows.length ? rows.map(function (r) {
      var c = COMP[r.compliance] || COMP.UNKNOWN;
      var comp = r.waiting ? '<span class="tag t-info">Enrolled — first report within a minute</span>'
        : (r.upgrade_required && r.service_offline) ? '<span class="tag t-danger" title="SmartEPT Agent Service ' + esc(r.service_version || 'unknown') + '">Service not reporting since ' + esc(ago(r.service_last_seen)) + '</span>'
        : r.upgrade_required ? '<span class="tag t-off" title="Agent Service ' + esc(r.service_version || 'unknown') + ', last heartbeat ' + esc(ago(r.service_last_seen)) + '">Agent upgrade required</span>' : '<span class="tag ' + c[0] + '">' + c[1] + '</span>';
      var av = r.provider ? esc(r.provider) + (r.monitoring_only ? ' <span class="tag t-info">Monitoring only</span>' : '') : '<span class="mut">—</span>';
      var thr = r.active_threats == null ? '—' : (r.active_threats > 0 ? '<span class="tag t-danger">' + r.active_threats + ' active</span>' : '0');
      var fw = r.firewall === 'on' ? '<span class="tag t-ok">On</span>' : r.firewall === 'off' ? '<span class="tag t-danger">Off</span>' : '<span class="tag t-off">Unknown</span>';
      var pend = r.pending ? ' <span class="tag t-info" title="' + esc(TYPE_LABEL[r.pending.type] || r.pending.type) + '">' + esc(r.pending.status) + '</span>' : '';
      return '<tr class="clk" data-id="' + r.machine_id + '"><td><b>' + esc(r.employee ? r.employee.name : '—') + '</b></td><td>' + esc(r.device) + '</td><td>' + av
        + '</td><td>' + yn(r.antivirus_enabled, 'On', 'Off') + '</td><td>' + (r.monitoring_only ? '<span class="mut">n/a</span>' : yn(r.realtime))
        + '</td><td>' + esc(r.signature_version || '—') + '</td><td>' + ago(r.last_scan) + '</td><td>' + thr + '</td><td>' + fw
        + '</td><td>' + comp + pend + '</td><td>' + ago(r.last_seen) + '</td><td><button class="btn" data-open="' + r.machine_id + '">Details</button></td></tr>';
    }).join('') : '<tr><td colspan="12" class="mut">' + (ROWS.length
      // 04-Oct-2026: with a filter (or a clicked tile) active, an empty table said "No endpoints yet"
      // while the tiles said 5 — read as "the PC is not detected". Say what is really happening.
      ? 'No endpoint matches this filter. ' + ROWS.length + ' endpoint(s) in total — <a href="#" data-es-all>show all</a>.'
      : 'No endpoints yet. Endpoints appear once the SmartEPT Agent Service on each PC reports in.') + '</td></tr>';
  }

  // 04-Oct-2026: every scan / signature update has a report (what ran, how long, what Defender found).
  function reportBtn(c) {
    return ' <button class="btn" data-report="' + c.id + '" style="margin-left:6px">View report</button>';
  }
  function dur(s) { return s == null ? '—' : (s >= 3600 ? Math.floor(s / 3600) + 'h ' : '') + (s >= 60 ? Math.floor(s % 3600 / 60) + 'm ' : '') + (s % 60) + 's'; }
  var EV_LABEL = { scan_started: ['t-info', 'Scan started'], scan_completed: ['t-ok', 'Scan completed'], scan_cancelled: ['t-warn', 'Scan cancelled'],
    threat_detected: ['t-danger', 'Threat detected'], threat_remediated: ['t-ok', 'Threat removed'], signature_updated: ['t-ok', 'Signatures updated'],
    signature_update_failed: ['t-danger', 'Signature update failed'], protection_enabled: ['t-ok', 'Protection enabled'], protection_disabled: ['t-danger', 'Protection disabled'] };
  function sevTag(n) {
    var m = n >= 5 ? ['t-danger', 'Severe'] : n >= 4 ? ['t-danger', 'High'] : n >= 2 ? ['t-warn', 'Moderate'] : n ? ['t-info', 'Low'] : ['t-off', '—'];
    return '<span class="tag ' + m[0] + '">' + m[1] + '</span>';
  }
  function hoursSince(iso) { var t = Date.parse(iso || ''); return isNaN(t) ? null : (Date.now() - t) / 36e5; }
  async function openReport(id) {
    el('#es-ovl').classList.add('open');
    el('#es-m-body').innerHTML = '<div class="mut">Loading…</div>';
    try {
      var r = (await api('/endpoint-security/commands/' + id + '/report')).data;
      CUR = r.machine_id;
      var isScan = r.type !== 'AV_SIGNATURE_UPDATE', open = ['queued', 'received', 'running'].indexOf(r.status) !== -1;
      var nThreat = r.threats.length, activeLeft = r.threats.filter(function (t) { return t.active; }).length;
      // A signature update that changed nothing: Defender already had the newest definitions.
      var unchanged = !isScan && r.status === 'completed' && r.after.signature_updated_at && r.started_at
        && Date.parse(r.after.signature_updated_at) < Date.parse(r.started_at) - 60000;
      var b = open ? ['esr-run', '…', 'In progress', 'Running on the PC — open this report again when it completes.']
        : r.status !== 'completed' ? ['esr-bad', '!', 'Did not complete', r.outcome]
        : r.type === 'AV_STATUS_REFRESH' ? ['esr-ok', '&#10003;', 'Status refreshed', 'SmartEPT read the latest Microsoft Defender status from this PC — see "This PC now" below.']
        : nThreat ? [activeLeft ? 'esr-bad' : 'esr-warn', '!', nThreat + ' threat' + (nThreat > 1 ? 's' : '') + ' found',
          activeLeft ? activeLeft + ' still active — review the threats below.' : 'Microsoft Defender dealt with every threat it found.']
        : unchanged ? ['esr-ok', '&#10003;', 'Already up to date', 'Microsoft Defender already had the newest security intelligence — nothing to download.']
        : ['esr-ok', '&#10003;', isScan ? 'Clean — no threats found' : 'Signatures updated', isScan ? 'Microsoft Defender found nothing during this scan.' : 'Microsoft Defender downloaded the latest security intelligence.'];
      el('#es-m-title').textContent = (TYPE_LABEL[r.type] || r.type) + ' — ' + r.device;
      function tile(l, v, cls) { return '<div class="esr-tile ' + (cls || '') + '"><div class="l">' + esc(l) + '</div><div class="v">' + v + '</div></div>'; }
      function kv(k, v) { return '<div><span class="k">' + esc(k) + '</span>' + v + '</div>'; }
      function card(t, right, body) { return '<div class="esr-card"><h5><span>' + esc(t) + '</span>' + (right || '') + '</h5>' + body + '</div>'; }
      var sigAge = hoursSince(r.after.signature_updated_at), scanAge = hoursSince(r.after.last_quick_scan_at);

      var html = '<div class="esr-banner ' + b[0] + '"><div class="ic">' + b[1] + '</div><div><b>' + esc(b[2]) + '</b><span>' + esc(b[3]) + '</span></div></div>'
        + '<div class="esr-tiles">'
          + tile('Status', cmdStatus(r))
          + tile('Duration', dur(r.duration_seconds))
          + tile('Threats found', String(nThreat), nThreat ? 'bad' : 'ok')
          + tile('Active threats now', r.after.active_threats == null ? '—' : String(r.after.active_threats), r.after.active_threats ? 'bad' : 'ok')
        + '</div>'
        + card('Action', '', '<div class="esr-kv">'
          + kv('Action', esc(TYPE_LABEL[r.type] || r.type)) + kv('Device', esc(r.device) + (r.employee ? ' · ' + esc(r.employee) : ''))
          + kv('Requested', ago(r.requested_at)) + kv('Requested by', esc(r.requested_by || '—'))
          + kv('Started on the PC', ago(r.started_at)) + kv('Completed', ago(r.completed_at))
          + (r.parameters && r.parameters.path ? kv('Scanned folder', esc(r.parameters.path)) : '')
          + (r.error_message ? kv('Reason', '<span style="color:var(--danger)">' + esc(r.error_message) + '</span>') : '')
          + '</div>')
        + card('Threats found during this action', '<span class="tag ' + (nThreat ? 't-danger' : 't-ok') + '">' + nThreat + '</span>', nThreat
          ? '<table><thead><tr><th>Threat</th><th>Severity</th><th>Defender status</th><th>Action</th><th>Detected</th><th>Files</th></tr></thead><tbody>'
            + r.threats.map(function (t) {
              return '<tr><td><b>' + esc(t.name) + '</b></td><td>' + sevTag(t.severity) + '</td><td><span class="tag ' + (t.active ? 't-danger' : 't-ok') + '">' + esc(t.status.replace(/_/g, ' ')) + '</span></td><td>'
                + (t.action_success === true ? '<span class="tag t-ok">Succeeded</span>' : t.action_success === false ? '<span class="tag t-danger">Failed</span>' : '—')
                + '</td><td>' + ago(t.detected_at) + '</td><td style="word-break:break-all">' + (t.resources || []).map(esc).join('<br>') + '</td></tr>'; }).join('') + '</tbody></table>'
          : '<div class="esr-empty">&#10003; None — Microsoft Defender reported no threats during this action.</div>')
        + card('Microsoft Defender events during this action', '<span class="tag t-off">' + r.events.length + '</span>', r.events.length
          ? '<table><thead><tr><th style="width:40%">When</th><th>Event</th></tr></thead><tbody>' + r.events.map(function (e) {
              var l = EV_LABEL[e.kind] || ['t-off', e.kind.replace(/_/g, ' ')];
              return '<tr><td>' + ago(e.occurred_at) + '</td><td><span class="tag ' + l[0] + '">' + esc(l[1]) + '</span>' + (e.event_id ? ' <span class="mut" style="padding:0">Event ' + e.event_id + '</span>' : '') + '</td></tr>'; }).join('') + '</tbody></table>'
          : '<div class="esr-empty">No Defender events in this window' + (unchanged ? ' — nothing was downloaded, so Defender logged nothing.' : open ? ' yet.' : '.') + '</div>')
        + card('This PC now', '', '<div class="esr-kv">'
          + kv('Last Quick Scan', ago(r.after.last_quick_scan_at) + (scanAge != null && scanAge > 24 * 7 ? ' <span class="tag t-warn">over a week ago</span>' : ''))
          + kv('Last Full Scan', ago(r.after.last_full_scan_at) + (!r.after.last_full_scan_at ? ' <span class="tag t-off">never</span>' : ''))
          + kv('Signature version', esc(r.after.signature_version || '—'))
          + kv('Signatures updated', ago(r.after.signature_updated_at) + (sigAge == null ? '' : sigAge > 48 ? ' <span class="tag t-danger">outdated</span>' : ' <span class="tag t-ok">current</span>'))
          + '</div>');
      REPORT = { title: el('#es-m-title').textContent, html: html, r: r };
      el('#es-m-body').innerHTML = '<div style="display:flex;gap:8px;margin-bottom:12px"><button class="btn" data-open-dev="' + r.machine_id + '">&larr; Back to ' + esc(r.device) + '</button>'
        + '<button class="btn solid" data-report-pdf style="margin-left:auto">Download PDF</button></div>' + html;
    } catch (e) { el('#es-m-body').innerHTML = '<div class="mut">' + esc(e.message) + '</div>'; }
  }

  // 04-Oct-2026: ONE audit-ready PDF layout for every SmartEPT report (Endpoint Security and the
  // PC Audit Log use it): reference number, organisation, report details, the content, a records
  // declaration, a sign-off block, and "Confidential · Ref · Page n of N" on every page.
  // Saved through the browser's own PDF writer - no library, works on an offline LAN server.
  window.eptAuditPdf = function (o) {
    var co = el('#company-name') ? el('#company-name').textContent.trim() : '';
    var me = (typeof ME !== 'undefined' && ME) ? ME : {};
    var now = new Date(), tz = (Intl.DateTimeFormat().resolvedOptions().timeZone || '');
    var pad = function (n) { return String(n).padStart(2, '0'); };
    var ref = (o.refPrefix || 'EPT') + '-' + now.getFullYear() + pad(now.getMonth() + 1) + pad(now.getDate()) + '-' + pad(now.getHours()) + pad(now.getMinutes()) + pad(now.getSeconds());
    var meta = [['Organisation', co], ['Report type', o.type]].concat(o.meta || [])
      .concat([['Generated by', (me.name || '—') + (me.role_name ? ' (' + me.role_name + ')' : '')], ['Generated on', now.toLocaleString()], ['Time zone', tz]]);
    var w = window.open('', '_blank');
    if (!w) { toast('✕ Allow pop-ups for this site to download the PDF'); return; }
    w.document.write('<!doctype html><html><head><meta charset="utf-8"><title>' + esc(o.file) + '</title><style>'
      + ':root{--navy:#003352;--teal:#006699;--card:#fff;--card-2:#F7F8F6;--border:#DCE3E6;--hairline:#E9EDEF;--ink:#0F1E26;--ink-2:#4A5A66;--ink-3:#7B8A95;'
      + '--ok:#0A9464;--ok-w:#E3F6EE;--warn:#B7791F;--warn-w:#FBF3E2;--danger:#D22A4C;--danger-w:#FBE9ED;--info:#0B72C9;--info-w:#E6F1FB;--font-head:"Plus Jakarta Sans",Inter,"Segoe UI",Arial,sans-serif}'
      + '@page{size:A4;margin:14mm 12mm 18mm 12mm;@bottom-left{content:"Confidential \\2014 ' + esc(co).replace(/"/g, '') + ' \\00B7 Ref ' + ref + '";font:9px Inter,Arial,sans-serif;color:#7B8A95}'
      + '@bottom-right{content:"Page " counter(page) " of " counter(pages);font:9px Inter,Arial,sans-serif;color:#7B8A95}}'
      + '*{box-sizing:border-box;-webkit-print-color-adjust:exact;print-color-adjust:exact}'
      + 'body{font-family:Inter,"Segoe UI",Arial,sans-serif;color:var(--ink);font-size:11px;margin:0;line-height:1.45}'
      + '.brand{display:flex;justify-content:space-between;align-items:center;background:var(--navy);color:#fff;padding:14px 18px;border-radius:8px}'
      + '.brand .lg{font:800 18px var(--font-head);letter-spacing:.02em}.brand .lg span{color:#4FB3E0}.brand .by{font-size:9.5px;color:#9FD8E2;margin-top:2px}'
      + '.brand .rf{text-align:right;font-size:9.5px;color:#CFE7EC}.brand .rf b{display:block;font-size:12px;color:#fff;letter-spacing:.03em}'
      + 'h1{font:700 19px var(--font-head);margin:16px 0 2px;color:var(--navy)}.sub{color:var(--ink-2);font-size:11.5px;margin-bottom:12px}'
      + '.meta{display:grid;grid-template-columns:1fr 1fr;border:1px solid var(--border);border-radius:8px;overflow:hidden;margin-bottom:14px}'
      + '.meta div{padding:7px 12px;border-bottom:1px solid var(--hairline)}.meta div:nth-child(odd){border-right:1px solid var(--hairline)}.meta .k{display:block;font-size:8.5px;text-transform:uppercase;letter-spacing:.06em;color:var(--ink-3)}.meta .v{font-weight:600}'
      + 'h2{font:700 12.5px var(--font-head);color:var(--navy);margin:16px 0 6px;padding-bottom:4px;border-bottom:2px solid var(--teal)}'
      + 'table{width:100%;border-collapse:collapse;font-size:10px}thead{display:table-header-group}tr{break-inside:avoid}'
      + 'th{background:#E0F0F8;color:#00527A;text-align:left;font-size:8.5px;text-transform:uppercase;letter-spacing:.04em;padding:6px 8px;border-bottom:1px solid #C9E6EC}'
      + 'td{padding:5px 8px;border-bottom:1px solid var(--hairline);vertical-align:top}tbody tr:nth-child(even) td{background:#FAFBFB}tr.bad td{background:#FDECEC !important;color:#B42318}tr.bad td b{color:#B42318}'
      + '.tag{font-size:8.5px;font-weight:700;padding:2px 7px;border-radius:10px;display:inline-block;white-space:nowrap}'
      + '.t-ok{background:var(--ok-w);color:var(--ok)}.t-warn{background:var(--warn-w);color:var(--warn)}.t-off{background:#EDF1F4;color:var(--ink-2)}.t-danger{background:var(--danger-w);color:var(--danger)}.t-info{background:var(--info-w);color:var(--info)}'
      + '.mut{color:var(--ink-3)}.num{text-align:right;font-variant-numeric:tabular-nums}'
      + '.decl{margin-top:16px;border:1px solid var(--border);border-left:4px solid var(--teal);background:var(--card-2);border-radius:6px;padding:10px 12px;font-size:9.5px;color:var(--ink-2);break-inside:avoid}'
      + '.sign{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-top:18px;break-inside:avoid}.sign>div{border:1px solid var(--border);border-radius:6px;padding:10px 12px;height:86px}.close{break-inside:avoid}'
      + '.sign .k{font-size:8.5px;text-transform:uppercase;letter-spacing:.06em;color:var(--ink-3)}.sign .ln{margin-top:34px;border-top:1px solid var(--ink-3);padding-top:3px;font-size:8.5px;color:var(--ink-3)}'
      + (o.css || '') + '</style></head><body>'
      + '<div class="brand"><div><div class="lg">Smart<span>EPT</span></div><div class="by">by Ametecs India Private Limited</div></div>'
      + '<div class="rf">REPORT REFERENCE<b>' + ref + '</b>' + esc(now.toLocaleString()) + '</div></div>'
      + '<h1>' + esc(o.title) + '</h1><div class="sub">' + esc(o.subtitle || '') + '</div>'
      + '<div class="meta">' + meta.map(function (m) { return '<div><span class="k">' + esc(m[0]) + '</span><span class="v">' + esc(m[1] == null || m[1] === '' ? '—' : m[1]) + '</span></div>'; }).join('')
      + (meta.length % 2 ? '<div></div>' : '') + '</div>'
      + o.body
      + '<div class="close"><div class="decl"><b>Declaration of records.</b> This report was generated automatically by SmartEPT from records captured on the endpoint by the SmartEPT Agent and the SmartEPT Agent Service'
      + (o.declaration ? ' ' + esc(o.declaration) : '') + '. Entries are reproduced exactly as recorded; nothing has been added, edited or removed. All times are shown in ' + esc(tz || 'the server\'s time zone') + '. Report reference ' + ref + '.</div>'
      + '<div class="sign"><div><span class="k">Prepared by</span><div class="ln">Name · Signature · Date</div></div><div><span class="k">Reviewed / approved by</span><div class="ln">Name · Signature · Date</div></div></div></div>'
      + '</body></html>');
    w.document.close();
    var done = false;
    function go() { if (done) return; done = true; try { w.focus(); w.print(); } catch (e) { /* window closed */ } }
    w.onload = go;
    setTimeout(go, 800); // some browsers never fire load for a written document
  };

  function reportPdf() {
    if (!REPORT) return;
    var r = REPORT.r, label = TYPE_LABEL[r.type] || r.type;
    var css = Array.prototype.map.call(document.querySelectorAll('style'), function (x) { return x.textContent.indexOf('.esr-') !== -1 ? x.textContent : ''; }).join('');
    window.eptAuditPdf({
      file: 'SmartEPT-' + label.replace(/[^A-Za-z0-9]+/g, '-') + '-' + r.device + '-' + String(r.requested_at || '').slice(0, 10),
      refPrefix: 'EPT-ES', type: 'Endpoint Security — ' + label, title: label + ' report', subtitle: r.device + (r.employee ? ' · ' + r.employee : ''),
      meta: [['PC', r.device], ['Employee', r.employee], ['Action requested', (r.requested_at ? new Date(r.requested_at).toLocaleString() : '—') + ' by ' + (r.requested_by || '—')], ['Antivirus', 'Microsoft Defender']],
      css: css + '.esr-card,.esr-banner,.esr-tile{break-inside:avoid}.esr-card td,.esr-card th{padding:6px 10px}',
      body: REPORT.html,
      declaration: 'and by Microsoft Defender on that PC',
    });
  }

  // 04-Oct-2026: Company Compliance Report — every PC × every checkpoint + the period's activity.
  async function compliancePdf() {
    var from = el('#es-cr-from').value, to = el('#es-cr-to').value;
    if (!from || !to || from > to) { toast('✕ Choose a From and To date'); return; }
    var btn = el('#es-cr'); btn.disabled = true; btn.textContent = 'Preparing…';
    try {
      var d = (await api('/endpoint-security/compliance-report?from=' + from + '&to=' + to)).data, t = d.totals, keys = Object.keys(d.checks);
      var SYM = { pass: ['t-ok', '✓'], fail: ['t-danger', '✗'], warn: ['t-warn', '!'], unknown: ['t-off', '?'] };
      var mark = function (c) { var m = SYM[c.s] || SYM.unknown; return '<span class="tag ' + m[0] + '" title="' + esc(c.t) + '">' + m[1] + '</span>'; };
      var who = function (p) { return '<b>' + esc(p.device) + '</b>' + (p.employee ? '<br><span class="mut">' + esc(p.employee) + (p.employee_code ? ' · ' + esc(p.employee_code) : '') + '</span>' : ''); };
      var tile = function (k, v, cls) { return '<div class="kt ' + (cls || '') + '"><span>' + esc(k) + '</span><b>' + v + '</b></div>'; };
      var dmy = function (x) { return new Date(x + 'T00:00:00').toLocaleDateString(undefined, { day: '2-digit', month: 'short', year: 'numeric' }); };
      var pct = t.pcs ? Math.round(100 * t.fully_compliant / t.pcs) : 0;
      var ex = [];
      d.pcs.forEach(function (p) { keys.forEach(function (k) { var c = p.checks[k]; if (c.s === 'fail' || c.s === 'warn') ex.push([p, k, c]); }); });
      var body = '<h2>1. Executive summary</h2>'
        + '<div class="kg">' + tile('PCs covered', t.pcs) + tile('Pass every checkpoint', t.fully_compliant + ' (' + pct + '%)', t.fully_compliant === t.pcs ? 'ok' : '')
          + tile('PCs with a failed checkpoint', t.with_failures, t.with_failures ? 'bad' : 'ok') + tile('Threats detected', t.threats, t.threats ? 'bad' : 'ok')
          + tile('Scans run from SmartEPT', t.scans) + tile('USB storage connected', t.usb + (t.usb_blocked ? ' · ' + t.usb_blocked + ' blocked' : ''))
          + tile('Policy violations', t.violations, t.violations ? 'warn' : 'ok') + tile('Agent tamper attempts', t.tamper, t.tamper ? 'bad' : 'ok') + '</div>'
        // 05-Oct-2026: say WHY PCs do not pass, on page 1 — "0 (0%)" alone read as a report fault.
        + (t.with_failures ? '<p><b>Why ' + t.with_failures + ' of ' + t.pcs + ' PC(s) do not pass:</b> '
          + keys.filter(function (k) { return d.check_totals[k].fail; }).sort(function (a, b) { return d.check_totals[b].fail - d.check_totals[a].fail; })
            .map(function (k) { return esc(d.checks[k]) + ' — failed on ' + d.check_totals[k].fail + ' PC(s)'; }).join('; ')
          + '. A PC passes only when none of the ' + keys.length + ' checkpoints fails; a checkpoint failed by every PC (for example a company-wide setting) keeps the pass count at 0.</p>' : '')
        + '<p class="mut">Checkpoint status is each PC\'s state as last reported. Activity figures cover ' + esc(dmy(d.from)) + ' to ' + esc(dmy(d.to)) + '. '
          + '✓ pass · ✗ fail · ! review · ? not reported by the PC (older SmartEPT Agent Service, or Windows could not tell).</p>'
        + '<h2>2. Checkpoint summary</h2><table><thead><tr><th>Checkpoint</th><th class="num">Pass</th><th class="num">Fail</th><th class="num">Not reported / review</th><th class="num">Pass rate</th></tr></thead><tbody>'
        + keys.map(function (k) { var c = d.check_totals[k], n = c.pass + c.fail;
            return '<tr><td>' + esc(d.checks[k]) + '</td><td class="num">' + c.pass + '</td><td class="num">' + (c.fail ? '<span class="tag t-danger">' + c.fail + '</span>' : '0') + '</td><td class="num">' + c.unknown
              + '</td><td class="num">' + (n ? Math.round(100 * c.pass / n) + '%' : '—') + '</td></tr>'; }).join('') + '</tbody></table>'
        + '<h2>3. Status of every PC (checkpoint matrix)</h2><table class="mx"><thead><tr><th>PC / employee</th>' + keys.map(function (k) { return '<th>' + esc(d.checks[k]) + '</th>'; }).join('') + '</tr></thead><tbody>'
        + (d.pcs.length ? d.pcs.map(function (p) { return '<tr><td>' + who(p) + '</td>' + keys.map(function (k) { return '<td style="text-align:center">' + mark(p.checks[k]) + '</td>'; }).join('') + '</tr>'; }).join('')
          : '<tr><td colspan="' + (keys.length + 1) + '" class="mut">No PCs with the SmartEPT Agent Service.</td></tr>') + '</tbody></table>'
        + '<h2>4. Items requiring action (' + ex.length + ')</h2>'
        + (ex.length ? '<table><thead><tr><th>PC / employee</th><th>Checkpoint</th><th>Status</th><th>Found</th></tr></thead><tbody>' + ex.map(function (e) {
            return '<tr><td>' + who(e[0]) + '</td><td>' + esc(d.checks[e[1]]) + '</td><td>' + (e[2].s === 'fail' ? '<span class="tag t-danger">Fail</span>' : '<span class="tag t-warn">Review</span>') + '</td><td>' + esc(e[2].t) + '</td></tr>'; }).join('') + '</tbody></table>'
          : '<p>✓ None — every reported checkpoint passed.</p>')
        + '<h2>5. Activity in the period, per PC</h2><table><thead><tr><th>PC / employee</th><th class="num">Threats</th><th class="num">Scans</th><th class="num">USB connected</th><th class="num">USB blocked</th><th class="num">Files to USB</th>'
          + '<th class="num">Downloads</th><th class="num">Software changes</th><th class="num">Violations</th><th class="num">Programs blocked</th><th class="num">Tamper</th></tr></thead><tbody>'
        + d.pcs.map(function (p) { var a = p.period, n = function (v, bad) { return '<td class="num">' + (v && bad ? '<span class="tag t-danger">' + v + '</span>' : v) + '</td>'; };
            return '<tr><td>' + who(p) + '</td>' + n(a.threats, 1) + n(a.scans) + n(a.usb) + n(a.usb_blocked) + n(a.files_to_usb) + n(a.downloads) + n(a.software) + n(a.violations, 1) + n(a.blocked_apps) + n(a.tamper, 1) + '</tr>'; }).join('')
        + '</tbody></table>'
        + '<h2>6. Reported values per PC</h2><table><thead><tr><th>PC / employee</th><th>Windows</th><th>Last patch</th><th>Antivirus</th><th>Definitions</th><th>Last scan</th><th>BitLocker</th><th>Screen lock</th><th>Local administrators</th><th>Last report</th></tr></thead><tbody>'
        + d.pcs.map(function (p) { var c = p.checks;
            return '<tr><td>' + who(p) + '</td><td>' + esc(c.os.t) + '</td><td>' + esc(c.patch.t) + '</td><td>' + esc(c.antivirus.t) + '</td><td>' + esc(c.definitions.t) + '</td><td>' + esc(c.scan.t)
              + '</td><td>' + esc(c.bitlocker.t) + '</td><td>' + esc(c.lock.t) + '</td><td>' + esc(c.admins.t) + '</td><td>' + esc(c.agent.t) + '</td></tr>'; }).join('')
        + '</tbody></table>';
      window.eptAuditPdf({
        file: 'SmartEPT-Compliance-Report-' + (d.company || '').replace(/[^A-Za-z0-9]+/g, '-') + '-' + d.from + '-to-' + d.to,
        refPrefix: 'EPT-CR', type: 'Endpoint Compliance Report — all PCs', title: 'Endpoint Compliance Report',
        subtitle: (d.company || '') + ' · ' + dmy(d.from) + ' to ' + dmy(d.to) + ' · ' + t.pcs + ' PCs',
        meta: [['Period', dmy(d.from) + ' to ' + dmy(d.to)], ['PCs covered', String(t.pcs)], ['Checkpoints', String(keys.length)], ['Overall', pct + '% of PCs pass every checkpoint']],
        css: '@page{size:A4 landscape}.kg{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin:6px 0 8px}.kt{border:1px solid var(--border);border-radius:8px;padding:8px 10px;break-inside:avoid}'
          + '.kt span{display:block;font-size:8.5px;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-3)}.kt b{font:700 16px var(--font-head)}.kt.ok b{color:var(--ok)}.kt.bad b{color:var(--danger)}.kt.warn b{color:var(--warn)}'
          + '.mx th{font-size:7.5px;padding:5px 3px;text-align:center;vertical-align:bottom}.mx th:first-child{text-align:left}.mx td{padding:4px 3px}.mx td:first-child{min-width:120px}.mx .tag{min-width:18px;text-align:center}',
        body: body,
        declaration: 'and by Windows and Microsoft Defender on each PC',
      });
    } catch (e) { toast('✕ Report failed (' + e.message + ')'); }
    finally { btn.disabled = false; btn.textContent = 'Download PDF'; }
  }

  async function loadCommands() {
    try {
      var rows = (await api('/endpoint-security/commands')).data || [];
      el('#es-cmd-rows').innerHTML = rows.length ? rows.map(cmdRow).join('') : '<tr><td colspan="8" class="mut">No security actions yet.</td></tr>';
    } catch (e) { el('#es-cmd-rows').innerHTML = '<tr><td colspan="8" class="mut">' + esc(e.message) + '</td></tr>'; }
  }
  function cmdStatus(c) {
    var cls = { completed: 't-ok', failed: 't-danger', expired: 't-off', cancelled: 't-off', running: 't-info', received: 't-info', queued: 't-warn' }[c.status] || 't-off';
    return '<span class="tag ' + cls + '">' + esc(c.status) + '</span>';
  }
  function cmdRow(c) {
    var what = esc(TYPE_LABEL[c.type] || c.type) + (c.parameters && c.parameters.path ? '<br><span class="mut" style="padding:0">' + esc(c.parameters.path) + '</span>' : '');
    return '<tr><td>' + ago(c.requested_at) + '</td><td>' + esc(c.requested_by || '—') + '</td><td>' + esc(c.device || '') + '</td><td>' + esc(c.employee || '')
      + '</td><td>' + what + '</td><td>' + cmdStatus(c) + '</td><td>' + ago(c.completed_at) + '</td><td>' + esc(c.error_message || (c.status === 'completed' ? 'Completed successfully' : '')) + reportBtn(c) + '</td></tr>';
  }

  var POL = [['requireAntivirus', 'Require an active antivirus', 'bool'], ['requireRealtimeProtection', 'Require real-time protection', 'bool'],
    ['maximumSignatureAgeHours', 'Maximum signature age (hours)', 'num'], ['requireFirewall', 'Require Windows Firewall on every profile', 'bool'],
    ['allowThirdPartyAntivirus', 'Accept a third-party antivirus (Sophos, Quick Heal, …)', 'bool'], ['pathRedaction', 'Threat file paths uploaded as', 'sel']];
  var RED = [['REDACT_USER', 'C:\\Users\\***\\… (hide user name)'], ['FILENAME_ONLY', 'File name only'], ['NO_PATH', 'No path at all'], ['FULL_PATH', 'Full path']];
  async function loadPolicy() {
    try {
      var d = (await api('/endpoint-security/policy')).data, edit = ACCESS.can_edit_policy, s = d.settings;
      el('#es-pol-hint').textContent = edit ? 'applies to every endpoint in your company' : 'defaults — editable in SmartEPT Commander';
      el('#es-pol').innerHTML = POL.map(function (p) {
        var k = p[0], dis = edit ? '' : ' disabled';
        if (p[2] === 'bool') return '<div class="fbool"><input type="checkbox" data-k="' + k + '"' + (s[k] ? ' checked' : '') + dis + '> ' + esc(p[1]) + '</div>';
        if (p[2] === 'num') return '<div><label>' + esc(p[1]) + '</label><input type="number" min="1" max="720" data-k="' + k + '" value="' + esc(s[k]) + '"' + dis + '></div>';
        return '<div><label>' + esc(p[1]) + '</label><select data-k="' + k + '"' + dis + '>' + RED.map(function (o) {
          return '<option value="' + o[0] + '"' + (s[k] === o[0] ? ' selected' : '') + '>' + esc(o[1]) + '</option>'; }).join('') + '</select></div>';
      }).join('');
      el('#es-pol-save').style.display = edit ? '' : 'none';
    } catch (e) { el('#es-pol').innerHTML = '<div class="mut">' + esc(e.message) + '</div>'; }
  }
  async function savePolicy() {
    var body = {};
    document.querySelectorAll('#es-pol [data-k]').forEach(function (x) {
      body[x.dataset.k] = x.type === 'checkbox' ? x.checked : (x.type === 'number' ? parseInt(x.value, 10) : x.value);
    });
    var m = el('#es-pol-msg'); m.textContent = 'Saving…';
    try { await api('/endpoint-security/policy', { method: 'PUT', body: JSON.stringify(body) }); m.textContent = '\u2713 Saved — every endpoint re-checked'; loadRows(); }
    catch (e) { m.textContent = '\u2715 ' + e.message; }
  }

  async function openDetail(id) {
    CUR = id;
    el('#es-ovl').classList.add('open');
    el('#es-m-body').innerHTML = '<div class="mut">Loading…</div>';
    try {
      var d = (await api('/endpoint-security/devices/' + id)).data;
      el('#es-m-title').textContent = d.device;
      var c = COMP[d.compliance] || COMP.UNKNOWN;
      function line(l, v) { return '<tr><td style="width:42%" class="mut">' + esc(l) + '</td><td>' + v + '</td></tr>'; }
      function sec(t, rows) { return '<h4 style="margin:16px 0 6px;font-size:13px">' + esc(t) + '</h4><table>' + rows + '</table>'; }
      var html = sec('Endpoint', line('Employee', esc(d.employee ? d.employee.name + ' (' + d.employee.code + ')' : '—')) + line('Device', esc(d.device))
          + line('Operating System', esc(d.os_version || '—')) + line('Last Seen', ago(d.last_seen))
          + line('SmartEPT Agent Service', esc(d.service_version || 'unknown') + ' · last heartbeat ' + ago(d.service_last_seen)))
        + sec('Antivirus', line('Provider', esc(d.provider || 'Unknown') + (d.monitoring_only ? ' <span class="tag t-info">Management Integration: Monitoring Only</span>' : ''))
          + line('Status', yn(d.antivirus_enabled, 'Active', 'Off')) + line('Running Mode', esc(d.running_mode || '—'))
          + (d.monitoring_only ? '' : line('Real-Time Protection', yn(d.realtime)) + line('Behavior Monitoring', yn(d.behavior)) + line('IOAV Protection', yn(d.ioav))
          + line('Signature Version', esc(d.signature_version || '—')) + line('Signature Last Updated', ago(d.signature_updated_at))
          + line('Signature Status', d.issues.indexOf('SECURITY_SIGNATURE_OUTDATED') !== -1 ? '<span class="tag t-warn">Outdated</span>' : (d.signature_version ? '<span class="tag t-ok">Up to date</span>' : '—'))
          + line('Last Quick Scan', ago(d.last_quick_scan_at)) + line('Last Full Scan', ago(d.last_full_scan_at))))
        + sec('Firewall', line('Domain', yn(d.firewall_profiles.domain)) + line('Private', yn(d.firewall_profiles.private)) + line('Public', yn(d.firewall_profiles.public)))
        + sec('Threats', line('Active Threats', d.active_threats == null ? '—' : String(d.active_threats)) + line('Resolved Threats', String(d.resolved_threats)))
        + sec('Compliance', line('Status', (d.upgrade_required && d.service_offline) ? '<span class="tag t-danger">SmartEPT Agent Service not reporting since ' + esc(ago(d.service_last_seen)) + '</span>' : d.upgrade_required ? '<span class="tag t-off">Endpoint Security Agent Upgrade Required</span>' : '<span class="tag ' + c[0] + '">' + c[1] + '</span>')
          + line('Issues', d.issue_labels.length ? d.issue_labels.map(esc).join('<br>') : '—')
          + (d.errors.length ? line('Notes', d.errors.map(esc).join('<br>')) : ''));

      var acts = [];
      if (ACCESS.can_act) {
        acts.push('<button class="btn" data-act="refresh">Refresh Security Status</button>');
        if (!d.monitoring_only) {
          if (can('quick_scan')) acts.push('<button class="btn solid" data-act="quick-scan">Run Quick Scan</button>');
          if (can('full_scan')) acts.push('<button class="btn" data-act="full-scan">Run Full Scan</button>');
          if (can('signature_update')) acts.push('<button class="btn" data-act="update-signatures">Update Signatures</button>');
        }
      }
      acts.push('<button class="btn" data-sub="threats">View Threats</button>');
      if (can('events')) acts.push('<button class="btn" data-sub="events">View Security Events</button>');
      if (can('command_history')) acts.push('<button class="btn" data-sub="audit">View Audit History</button>');
      html += '<h4 style="margin:16px 0 6px;font-size:13px">Actions</h4><div style="display:flex;gap:8px;flex-wrap:wrap">' + acts.join('') + '</div>';
      if (ACCESS.can_act && can('custom_scan') && !d.monitoring_only) {
        html += '<div style="display:flex;gap:8px;margin-top:10px"><input id="es-path" placeholder="C:\\Users\\Public\\Downloads" style="flex:1">'
          + '<button class="btn" data-act="custom-scan">Run Custom Scan</button></div>';
      }
      html += '<div id="es-sub" style="margin-top:14px"></div>';
      el('#es-m-body').innerHTML = html;
      showSub('audit', d.recent_commands);
    } catch (e) { el('#es-m-body').innerHTML = '<div class="mut">' + esc(e.message) + '</div>'; }
  }

  async function showSub(kind, preload) {
    var box = el('#es-sub'); if (!box) return;
    try {
      if (kind === 'audit') {
        var cmds = preload || (await api('/endpoint-security/devices/' + CUR)).data.recent_commands;
        box.innerHTML = '<h4 style="margin:0 0 6px;font-size:13px">Recent security actions</h4><table><thead><tr><th>Requested</th><th>Administrator</th><th>Action</th><th>Status</th><th>Received</th><th>Started</th><th>Completed</th></tr></thead><tbody>'
          + (cmds.length ? cmds.map(function (c) { return '<tr><td>' + ago(c.requested_at) + '</td><td>' + esc(c.requested_by || '—') + '</td><td>' + esc(TYPE_LABEL[c.type] || c.type)
            + '</td><td>' + cmdStatus(c) + (c.error_message ? '<br><span class="mut" style="padding:0">' + esc(c.error_message) + '</span>' : '') + '</td><td>' + ago(c.received_at)
            + '</td><td>' + ago(c.started_at) + '</td><td>' + ago(c.completed_at) + reportBtn(c) + '</td></tr>'; }).join('') : '<tr><td colspan="7" class="mut">None yet.</td></tr>') + '</tbody></table>';
      } else if (kind === 'threats') {
        var ts = (await api('/endpoint-security/devices/' + CUR + '/threats')).data;
        box.innerHTML = '<h4 style="margin:0 0 6px;font-size:13px">Threat history</h4><table><thead><tr><th>Threat</th><th>Status</th><th>Detected</th><th>Last change</th><th>Resources</th></tr></thead><tbody>'
          + (ts.length ? ts.map(function (t) { return '<tr><td><b>' + esc(t.threat_name || t.threat_id) + '</b></td><td><span class="tag ' + (t.active ? 't-danger' : 't-ok') + '">' + esc(t.status)
            + '</span></td><td>' + ago(t.detected_at) + '</td><td>' + ago(t.status_changed_at) + '</td><td>' + (t.resources || []).map(esc).join('<br>') + '</td></tr>'; }).join('')
            : '<tr><td colspan="5" class="mut">No threats reported by Microsoft Defender.</td></tr>') + '</tbody></table>';
      } else if (kind === 'events') {
        var ev = (await api('/endpoint-security/devices/' + CUR + '/events')).data;
        box.innerHTML = '<h4 style="margin:0 0 6px;font-size:13px">Security events</h4><table><thead><tr><th>When</th><th>Event</th><th>Detail</th></tr></thead><tbody>'
          + (ev.length ? ev.map(function (e) { return '<tr><td>' + ago(e.occurred_at) + '</td><td>' + esc(e.kind.replace(/_/g, ' ')) + (e.event_id ? ' <span class="mut" style="padding:0">(' + e.event_id + ')</span>' : '')
            + '</td><td>' + esc(e.detail || '') + '</td></tr>'; }).join('') : '<tr><td colspan="3" class="mut">No events yet.</td></tr>') + '</tbody></table>';
      }
    } catch (e) { box.innerHTML = '<div class="mut">' + esc(e.message) + '</div>'; }
  }

  async function runAction(act) {
    var r = ROWS.filter(function (x) { return x.machine_id === CUR; })[0] || { device: 'this PC' }, body = {};
    var ask = { 'quick-scan': 'Run Quick Scan on ' + r.device + '?',
      'full-scan': 'A Full Scan may consume system resources and take significant time.\n\nRun Full Scan on ' + r.device + '?',
      'update-signatures': 'Update Microsoft Defender signatures on ' + r.device + '?' }[act];
    if (act === 'custom-scan') {
      body.path = (el('#es-path').value || '').trim();
      if (!body.path) { toast('Enter a folder path to scan'); return; }
      ask = 'Scan:\n' + body.path + '\n\non ' + r.device + '\n\nContinue?';
    }
    if (ask && !confirm(ask)) return;
    try {
      await api('/endpoint-security/devices/' + CUR + '/actions/' + act, { method: 'POST', body: JSON.stringify(body) });
      toast('\u2713 Sent to ' + r.device + ' — it runs when the PC next checks in (about a minute)');
      showSub('audit'); loadRows(); if (can('command_history')) loadCommands();
    } catch (e) { toast('\u2715 ' + e.message); }
  }

  function bind() {
    if (!el('#es-card') || el('#es-card').dataset.bound) return;
    el('#es-card').dataset.bound = '1';
    el('#es-q').oninput = renderRows;
    el('#es-f').onchange = renderRows;
    el('#es-kpis').addEventListener('click', function (e) { var k = e.target.closest('.kpi.drill'); if (k) { el('#es-f').value = k.dataset.f === 'ALL' ? '' : k.dataset.f; renderRows(); } });
    el('#es-rows').addEventListener('click', function (e) {
      if (e.target.closest('[data-es-all]')) { e.preventDefault(); el('#es-f').value = ''; el('#es-q').value = ''; renderRows(); return; }
      var b = e.target.closest('[data-open]') || e.target.closest('tr[data-id]'); if (b) openDetail(+(b.dataset.open || b.dataset.id)); });
    el('#es-m-body').addEventListener('click', function (e) {
      var rp = e.target.closest('[data-report]'); if (rp) return openReport(+rp.dataset.report);
      if (e.target.closest('[data-report-pdf]')) return reportPdf();
      var bk = e.target.closest('[data-open-dev]'); if (bk) return openDetail(+bk.dataset.openDev);
      var a = e.target.closest('[data-act]'); if (a) return runAction(a.dataset.act);
      var s = e.target.closest('[data-sub]'); if (s) showSub(s.dataset.sub);
    });
    el('#es-cmd-rows').addEventListener('click', function (e) { var rp = e.target.closest('[data-report]'); if (rp) openReport(+rp.dataset.report); });
    el('#es-x').onclick = function () { el('#es-ovl').classList.remove('open'); };
    el('#es-pol-save').onclick = savePolicy;
    var iso = function (dt) { return dt.getFullYear() + '-' + String(dt.getMonth() + 1).padStart(2, '0') + '-' + String(dt.getDate()).padStart(2, '0'); };
    el('#es-cr-to').value = iso(new Date()); el('#es-cr-from').value = iso(new Date(Date.now() - 29 * 864e5));
    el('#es-cr').onclick = compliancePdf;
    el('#es-export').onclick = async function () {
      var type = el('#es-rep').value;
      try {
        var blob = await apiBlob('/endpoint-security/reports/' + type), a = document.createElement('a');
        a.href = URL.createObjectURL(blob); a.download = 'endpoint-security-' + type + '.csv'; a.click();
        setTimeout(function () { URL.revokeObjectURL(a.href); }, 4000);
      } catch (e) { toast('\u2715 Export failed (' + e.message + ')'); }
    };
  }
  document.addEventListener('DOMContentLoaded', bind);
  if (document.readyState !== 'loading') bind();
})();
</script>
