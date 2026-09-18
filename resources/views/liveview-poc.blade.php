<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>SmartEPT LiveView — Phase 3 POC</title>
<style>
  body { font-family: system-ui, sans-serif; background: #10151c; color: #e6e9ee; margin: 0; padding: 24px; }
  h1 { font-size: 18px; margin: 0 0 4px; }
  .sub { color: #8b95a3; font-size: 13px; margin-bottom: 20px; }
  .card { background: #1a212b; border: 1px solid #2a333f; border-radius: 8px; padding: 16px; max-width: 640px; margin-bottom: 16px; }
  label { display: block; font-size: 12px; color: #8b95a3; margin-bottom: 4px; }
  input, select, button { font-size: 14px; padding: 8px 10px; border-radius: 6px; border: 1px solid #2a333f; background: #10151c; color: #e6e9ee; margin-bottom: 10px; width: 100%; box-sizing: border-box; }
  button { background: #2f6fed; border: none; cursor: pointer; width: auto; padding: 8px 18px; }
  button:disabled { opacity: .5; cursor: not-allowed; }
  button.stop { background: #d64545; }
  .row { display: flex; gap: 10px; }
  .row > * { flex: 1; }
  #status { font-size: 13px; color: #8b95a3; margin-top: 8px; }
  #viewer { background: #000; border-radius: 8px; max-width: 100%; display: none; }
  .hidden { display: none; }
</style>
</head>
<body>
  <h1>SmartEPT LiveView — Phase 3 Proof of Concept</h1>
  <div class="sub">Throwaway test page. Not linked from the console nav. Proves capture → relay → browser only.</div>

  <div class="card" id="loginCard">
    <label>Email</label><input id="email" type="email" autocomplete="username">
    <label>Password</label><input id="password" type="password" autocomplete="current-password">
    <button id="loginBtn">Sign in</button>
    <div id="loginStatus" style="color:#d64545;font-size:13px;"></div>
  </div>

  <div class="card hidden" id="pickCard">
    <label>Employee</label>
    <select id="employee"></select>
    <div class="row">
      <div>
        <label>Monitor</label>
        <select id="monitor"><option value="0">Primary</option></select>
      </div>
      <div>
        <label>Quality</label>
        <select id="quality">
          <option value="data_saver">Data Saver (480p)</option>
          <option value="low" selected>Low (720p)</option>
          <option value="high">High (1080p)</option>
        </select>
      </div>
    </div>
    <button id="startBtn">Start</button>
    <button id="stopBtn" class="stop hidden">Stop</button>
    <div id="status"></div>
  </div>

  <canvas id="viewer" width="1280" height="720"></canvas>

<script>
(function () {
  const API = '/api';
  let TOKEN = null, ws = null, sessionId = null;

  const $ = (id) => document.getElementById(id);
  const setStatus = (t) => { $('status').textContent = t; };

  async function api(method, path, body) {
    const r = await fetch(API + path, {
      method,
      headers: Object.assign({ 'Content-Type': 'application/json', Accept: 'application/json' }, TOKEN ? { Authorization: 'Bearer ' + TOKEN } : {}),
      body: body ? JSON.stringify(body) : undefined,
    });
    const data = await r.json().catch(() => null);
    // data.error.message is our own controllers' shape; data.message is what Laravel's
    // default abort()/abort_if() and validation responses use — check both, or a real
    // server-side reason (like a misconfigured secret) silently collapses into "HTTP 500".
    if (!r.ok) throw Object.assign(new Error((data && ((data.error && data.error.message) || data.message)) || ('HTTP ' + r.status)), { status: r.status, data });
    return data;
  }

  $('loginBtn').addEventListener('click', async () => {
    $('loginStatus').textContent = '';
    try {
      const res = await api('POST', '/auth/login', { email: $('email').value, password: $('password').value });
      TOKEN = res.token;
      $('loginCard').classList.add('hidden');
      $('pickCard').classList.remove('hidden');
      await loadEmployees();
    } catch (e) {
      $('loginStatus').textContent = e.message || 'Login failed.';
    }
  });

  async function loadEmployees() {
    const res = await api('GET', '/employees?per_page=200');
    const sel = $('employee');
    sel.innerHTML = '';
    (res.data || []).forEach((emp) => {
      const opt = document.createElement('option');
      opt.value = emp.id;
      opt.textContent = [emp.first_name, emp.last_name].filter(Boolean).join(' ') + (emp.employee_code ? ' (' + emp.employee_code + ')' : '');
      sel.appendChild(opt);
    });
  }

  $('startBtn').addEventListener('click', async () => {
    setStatus('Requesting session…');
    try {
      const res = await api('POST', '/liveview/session/start', {
        employee_id: parseInt($('employee').value, 10),
        monitor_index: parseInt($('monitor').value, 10),
        quality: $('quality').value,
      });
      sessionId = res.session_id;
      connectViewer(res.relay_url, res.view_token);
      $('startBtn').classList.add('hidden');
      $('stopBtn').classList.remove('hidden');
    } catch (e) {
      setStatus('Start failed: ' + (e.message || 'unknown error'));
    }
  });

  $('stopBtn').addEventListener('click', async () => {
    if (ws) { try { ws.close(); } catch (e) {} ws = null; }
    if (sessionId) { try { await api('POST', '/liveview/session/' + sessionId + '/stop'); } catch (e) {} }
    sessionId = null;
    $('viewer').style.display = 'none';
    $('startBtn').classList.remove('hidden');
    $('stopBtn').classList.add('hidden');
    setStatus('Stopped.');
  });

  function connectViewer(relayUrl, viewToken) {
    setStatus('Connecting to relay…');
    ws = new WebSocket(relayUrl.replace(/\/$/, '') + '/relay/view?token=' + encodeURIComponent(viewToken));
    ws.binaryType = 'arraybuffer';

    const canvas = $('viewer');
    const ctx = canvas.getContext('2d');
    let frames = 0, lastLabel = Date.now();

    ws.onopen = () => setStatus('Connected. Waiting for the Agent to start sending frames…');
    ws.onclose = () => setStatus('Relay connection closed.');
    ws.onerror = () => setStatus('Relay connection error.');
    ws.onmessage = (evt) => {
      const blob = new Blob([evt.data], { type: 'image/jpeg' });
      const url = URL.createObjectURL(blob);
      const img = new Image();
      img.onload = () => {
        if (canvas.width !== img.width || canvas.height !== img.height) {
          canvas.width = img.width; canvas.height = img.height;
        }
        ctx.drawImage(img, 0, 0);
        URL.revokeObjectURL(url);
        canvas.style.display = 'block';
        frames++;
        if (Date.now() - lastLabel > 1000) {
          setStatus('● LIVE — ' + frames + ' fps (last second)');
          frames = 0; lastLabel = Date.now();
        }
      };
      img.src = url;
    };
  }
})();
</script>
</body>
</html>
