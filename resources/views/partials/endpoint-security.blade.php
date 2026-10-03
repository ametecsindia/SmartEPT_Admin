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
    <div class="kpis" id="es-kpis" style="grid-template-columns:repeat(4,1fr)"></div>
    <div class="filters" style="margin-bottom:10px">
      <input id="es-q" placeholder="Search employee or device" style="min-width:220px">
      <select id="es-f">
        <option value="">All endpoints</option>
        <option value="COMPLIANT">Protected</option>
        <option value="ACTION_REQUIRED">Attention required</option>
        <option value="NON_COMPLIANT">Non-compliant</option>
        <option value="UNKNOWN">Unable to verify</option>
      </select>
      <span style="margin-left:auto"></span>
      <select id="es-rep"></select>
      <button class="btn" id="es-export">Export CSV</button>
    </div>
    <table><thead><tr><th>Employee</th><th>Device</th><th>Antivirus</th><th>Status</th><th>Real-Time</th><th>Definitions</th>
      <th>Last Scan</th><th>Threats</th><th>Firewall</th><th>Compliance</th><th>Last Seen</th><th></th></tr></thead>
      <tbody id="es-rows"><tr><td colspan="12" class="mut">Loading…</td></tr></tbody></table>
  </div>

  <div class="card" id="es-cmd-card" style="display:none">
    <h3>Command history <span class="hint">every remote security action, who asked for it and what happened</span></h3>
    <table><thead><tr><th>Requested</th><th>Administrator</th><th>Device</th><th>Employee</th><th>Action</th><th>Status</th><th>Completed</th><th>Result</th></tr></thead>
      <tbody id="es-cmd-rows"></tbody></table>
  </div>

  <div class="card" id="es-pol-card" style="display:none">
    <h3>Security compliance policy <span class="hint" id="es-pol-hint"></span></h3>
    <div class="fgrid" id="es-pol"></div>
    <div style="margin-top:12px"><button class="btn solid" id="es-pol-save">Save policy</button> <span class="mut" id="es-pol-msg"></span></div>
  </div>
</div>

<div class="ovl" id="es-ovl"><div class="modal" style="width:820px">
  <div class="mhead"><div class="mt"><b id="es-m-title">Endpoint Security</b><span>Protected by Microsoft Defender · Monitored by SmartEPT</span></div>
    <button class="x" id="es-x">&#10005;</button></div>
  <div class="mbody" id="es-m-body" style="overflow:auto"></div>
</div></div>

<script>
(function () {
  'use strict';
  var ACCESS = null, ROWS = [], CUR = null;
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
      var K = [['Total endpoints', s.total, 'k-total', ''], ['Protected', s.protected, 'k-ok', 'COMPLIANT'],
        ['Attention required', s.attention, 'k-away', 'ACTION_REQUIRED'], ['Non-compliant', s.non_compliant, 'k-viol', 'NON_COMPLIANT'],
        ['Real-time protection off', s.realtime_disabled, 'k-cam', ''], ['Signatures outdated', s.signatures_outdated, 'k-shot', ''],
        ['Threats detected', s.threats, 'k-viol', ''], ['Firewall issues', s.firewall_issues, 'k-away', '']];
      el('#es-kpis').innerHTML = K.map(function (k) {
        return '<div class="kpi' + (k[3] ? ' drill' : '') + ' ' + k[2] + '" data-f="' + k[3] + '"><div class="kside"><div class="l">' + esc(k[0])
          + '</div></div><div class="kmain"><div class="v">' + esc(String(k[1])) + '</div>' + (k[3] ? '<span class="go">Filter</span>' : '') + '</div></div>';
      }).join('') + (s.upgrade_required ? '<div class="mut" style="grid-column:1/-1;padding:0">' + s.upgrade_required
        + ' endpoint(s) show <b>Endpoint Security Agent Upgrade Required</b> — install the latest SmartEPT Agent on them.</div>' : '');
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
      var comp = r.upgrade_required ? '<span class="tag t-off">Agent upgrade required</span>' : '<span class="tag ' + c[0] + '">' + c[1] + '</span>';
      var av = r.provider ? esc(r.provider) + (r.monitoring_only ? ' <span class="tag t-info">Monitoring only</span>' : '') : '<span class="mut">—</span>';
      var thr = r.active_threats == null ? '—' : (r.active_threats > 0 ? '<span class="tag t-danger">' + r.active_threats + ' active</span>' : '0');
      var fw = r.firewall === 'on' ? '<span class="tag t-ok">On</span>' : r.firewall === 'off' ? '<span class="tag t-danger">Off</span>' : '<span class="tag t-off">Unknown</span>';
      var pend = r.pending ? ' <span class="tag t-info" title="' + esc(TYPE_LABEL[r.pending.type] || r.pending.type) + '">' + esc(r.pending.status) + '</span>' : '';
      return '<tr class="clk" data-id="' + r.machine_id + '"><td><b>' + esc(r.employee ? r.employee.name : '—') + '</b></td><td>' + esc(r.device) + '</td><td>' + av
        + '</td><td>' + yn(r.antivirus_enabled, 'On', 'Off') + '</td><td>' + (r.monitoring_only ? '<span class="mut">n/a</span>' : yn(r.realtime))
        + '</td><td>' + esc(r.signature_version || '—') + '</td><td>' + ago(r.last_scan) + '</td><td>' + thr + '</td><td>' + fw
        + '</td><td>' + comp + pend + '</td><td>' + ago(r.last_seen) + '</td><td><button class="btn" data-open="' + r.machine_id + '">Details</button></td></tr>';
    }).join('') : '<tr><td colspan="12" class="mut">No endpoints yet. Endpoints appear once the SmartEPT Agent Service on each PC reports in.</td></tr>';
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
      + '</td><td>' + what + '</td><td>' + cmdStatus(c) + '</td><td>' + ago(c.completed_at) + '</td><td>' + esc(c.error_message || (c.status === 'completed' ? 'Completed successfully' : '')) + '</td></tr>';
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
          + line('Operating System', esc(d.os_version || '—')) + line('Last Seen', ago(d.last_seen)))
        + sec('Antivirus', line('Provider', esc(d.provider || 'Unknown') + (d.monitoring_only ? ' <span class="tag t-info">Management Integration: Monitoring Only</span>' : ''))
          + line('Status', yn(d.antivirus_enabled, 'Active', 'Off')) + line('Running Mode', esc(d.running_mode || '—'))
          + (d.monitoring_only ? '' : line('Real-Time Protection', yn(d.realtime)) + line('Behavior Monitoring', yn(d.behavior)) + line('IOAV Protection', yn(d.ioav))
          + line('Signature Version', esc(d.signature_version || '—')) + line('Signature Last Updated', ago(d.signature_updated_at))
          + line('Signature Status', d.issues.indexOf('SECURITY_SIGNATURE_OUTDATED') !== -1 ? '<span class="tag t-warn">Outdated</span>' : (d.signature_version ? '<span class="tag t-ok">Up to date</span>' : '—'))
          + line('Last Quick Scan', ago(d.last_quick_scan_at)) + line('Last Full Scan', ago(d.last_full_scan_at))))
        + sec('Firewall', line('Domain', yn(d.firewall_profiles.domain)) + line('Private', yn(d.firewall_profiles.private)) + line('Public', yn(d.firewall_profiles.public)))
        + sec('Threats', line('Active Threats', d.active_threats == null ? '—' : String(d.active_threats)) + line('Resolved Threats', String(d.resolved_threats)))
        + sec('Compliance', line('Status', d.upgrade_required ? '<span class="tag t-off">Endpoint Security Agent Upgrade Required</span>' : '<span class="tag ' + c[0] + '">' + c[1] + '</span>')
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
            + '</td><td>' + ago(c.started_at) + '</td><td>' + ago(c.completed_at) + '</td></tr>'; }).join('') : '<tr><td colspan="7" class="mut">None yet.</td></tr>') + '</tbody></table>';
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
    el('#es-kpis').addEventListener('click', function (e) { var k = e.target.closest('.kpi.drill'); if (k) { el('#es-f').value = k.dataset.f; renderRows(); } });
    el('#es-rows').addEventListener('click', function (e) { var b = e.target.closest('[data-open]') || e.target.closest('tr[data-id]'); if (b) openDetail(+(b.dataset.open || b.dataset.id)); });
    el('#es-m-body').addEventListener('click', function (e) {
      var a = e.target.closest('[data-act]'); if (a) return runAction(a.dataset.act);
      var s = e.target.closest('[data-sub]'); if (s) showSub(s.dataset.sub);
    });
    el('#es-x').onclick = function () { el('#es-ovl').classList.remove('open'); };
    el('#es-pol-save').onclick = savePolicy;
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
