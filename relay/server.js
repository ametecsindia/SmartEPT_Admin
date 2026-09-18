'use strict';
// SmartEPT LiveView relay — Phase 3 POC.
//
// Deliberately dumb (finding 1.6 / plan §3): it moves frame bytes from one Agent
// "stream" leg to one or more Admin "view" legs for the session id a signed token
// names, and nothing else. It never talks to the Laravel app, never touches a
// database, and makes no authorisation decision beyond "is this token's signature
// and expiry valid" — every business rule (licence, RBAC, capacity) was already
// decided by LiveViewController before the token was minted.
//
// Local dev only: npm install && cp .env.example .env (fill RELAY_SECRET — must match
// smartept/.env's LIVEVIEW_RELAY_SECRET exactly) && npm start
// Production: this ships pre-compiled (npm run build) as smartept-relay-win.exe / -linux /
// -macos — no Node.js on the client machine — and INSTALL.bat / install-linux.sh /
// install-macos.sh register that binary as a boot-time service. See build.js.

const crypto = require('crypto');
const fs = require('fs');
const path = require('path');
const { WebSocketServer, WebSocket } = require('ws');

// Production packaging (14-Sep-2026): compiled with `pkg` into a standalone exe/binary
// (see build.js) so the client machine needs no Node.js/npm at all. pkg's one real gotcha:
// __dirname inside a pkg binary points at the READ-ONLY virtual snapshot bundled into the
// exe, not the real folder the exe lives in — so relay-secret.txt / .env next to the actual
// exe would silently not be found. process.pkg only exists inside a pkg binary; there,
// process.execPath is the real exe's path on disk. Plain `node server.js` (dev) is unaffected.
const APP_DIR = process.pkg ? path.dirname(process.execPath) : __dirname;
require('dotenv').config({ path: path.join(APP_DIR, '.env') });

// Some Windows editors/tools can't create a leading-dot .env file, so fall back to a
// plain relay-secret.txt next to this file (first line, trimmed) when RELAY_SECRET
// isn't in the environment. Same secret, no dotfile required.
function loadSecret() {
  if (process.env.RELAY_SECRET) return process.env.RELAY_SECRET;
  try { return fs.readFileSync(path.join(APP_DIR, 'relay-secret.txt'), 'utf8').split('\n')[0].trim(); } catch { return ''; }
}

const SECRET = loadSecret();
const PORT = parseInt(process.env.RELAY_PORT || '8098', 10);

if (!SECRET || SECRET === 'change-me-generate-a-long-random-string') {
  console.error('[smartept-relay] RELAY_SECRET is not set (see .env.example). Refusing to start.');
  process.exit(1);
}

/**
 * Token shape (mirrors LiveViewTokenService on the Laravel side):
 *   base64url(json payload) + '.' + base64url(hmac-sha256 of that string, SECRET)
 * payload: { sid, role: 'stream'|'view', exp }  (exp = unix seconds)
 */
function verifyToken(token, expectRole) {
  if (typeof token !== 'string' || !token.includes('.')) return null;
  const [body, sig] = token.split('.');
  const expected = crypto.createHmac('sha256', SECRET).update(body).digest('base64url');
  // timing-safe compare
  const a = Buffer.from(sig || '');
  const b = Buffer.from(expected);
  if (a.length !== b.length || !crypto.timingSafeEqual(a, b)) return null;

  let payload;
  try { payload = JSON.parse(Buffer.from(body, 'base64url').toString('utf8')); } catch { return null; }
  if (!payload || payload.role !== expectRole || !payload.sid) return null;
  if (typeof payload.exp !== 'number' || payload.exp < Math.floor(Date.now() / 1000)) return null;
  return payload;
}

// sid -> { streamSocket, viewSockets: Set<WebSocket> }
const sessions = new Map();
function sessionFor(sid) {
  let s = sessions.get(sid);
  if (!s) { s = { streamSocket: null, viewSockets: new Set() }; sessions.set(sid, s); }
  return s;
}
function dropIfEmpty(sid) {
  const s = sessions.get(sid);
  if (s && !s.streamSocket && s.viewSockets.size === 0) sessions.delete(sid);
}

const wss = new WebSocketServer({ noServer: true });
const server = require('http').createServer((req, res) => { res.writeHead(404); res.end(); });

server.on('upgrade', (req, socket, head) => {
  const url = new URL(req.url, 'http://relay');
  const token = url.searchParams.get('token') || '';

  if (url.pathname === '/relay/stream') {
    const payload = verifyToken(token, 'stream');
    if (!payload) { socket.destroy(); return; }
    wss.handleUpgrade(req, socket, head, (ws) => onStream(ws, payload.sid));
    return;
  }
  if (url.pathname === '/relay/view') {
    const payload = verifyToken(token, 'view');
    if (!payload) { socket.destroy(); return; }
    wss.handleUpgrade(req, socket, head, (ws) => onView(ws, payload.sid));
    return;
  }
  socket.destroy();
});

function onStream(ws, sid) {
  const s = sessionFor(sid);
  // Only one Agent leg per session — a reconnect replaces the old socket.
  if (s.streamSocket && s.streamSocket !== ws) { try { s.streamSocket.close(); } catch {} }
  s.streamSocket = ws;
  console.log(`[relay] stream connected  sid=${sid}`);

  ws.on('message', (data, isBinary) => {
    if (!isBinary) return; // frames only; ignore any stray text/control payloads
    // Fan-out to every viewer, freshest-frame-wins: if a viewer's outbound buffer is
    // still draining the previous frame, this frame is skipped for THAT viewer rather
    // than queued — never let one slow viewer add latency for everyone else.
    for (const viewer of s.viewSockets) {
      if (viewer.readyState === WebSocket.OPEN && viewer.bufferedAmount < 1_000_000) {
        try { viewer.send(data, { binary: true }); } catch {}
      }
    }
  });
  ws.on('close', () => {
    if (s.streamSocket === ws) s.streamSocket = null;
    console.log(`[relay] stream disconnected  sid=${sid}`);
    dropIfEmpty(sid);
  });
  ws.on('error', () => { try { ws.close(); } catch {} });
}

function onView(ws, sid) {
  const s = sessionFor(sid);
  s.viewSockets.add(ws);
  console.log(`[relay] viewer connected  sid=${sid}  (${s.viewSockets.size} watching)`);

  ws.on('close', () => {
    s.viewSockets.delete(ws);
    console.log(`[relay] viewer disconnected  sid=${sid}  (${s.viewSockets.size} watching)`);
    dropIfEmpty(sid);
  });
  ws.on('error', () => { try { ws.close(); } catch {} });
}

// Debug instrumentation (13-Sep-2026 troubleshooting): writes ground-truth status to a
// plain file, since terminal output has been unreliable to relay back and forth. Not
// part of the POC's design — safe to delete once the connection issue is resolved.
function writeStatus(obj) {
  try { fs.writeFileSync(path.join(APP_DIR, 'relay-status.txt'), JSON.stringify({ ...obj, at: new Date().toISOString(), pid: process.pid }, null, 2)); } catch {}
}
// Exit cleanly on a listen error (most commonly EADDRINUSE — another copy of the relay,
// dev or auto-started, already holds this port) instead of hanging around doing nothing.
// The auto-start watchdog (daemon/relay-watchdog.vbs) waits for this process to exit
// before it ever relaunches it, so a clean exit here is what keeps that loop honest.
server.on('error', (e) => {
  writeStatus({ event: 'error', code: e.code, message: String(e && e.message || e) });
  console.error(`[smartept-relay] listen error: ${e && e.message || e}`);
  process.exit(1);
});
server.listen(PORT, '0.0.0.0', () => {
  console.log(`[smartept-relay] listening on ${PORT}`);
  writeStatus({ event: 'listening', port: PORT, address: server.address() });
});
