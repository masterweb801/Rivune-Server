'use strict';

function attachWebSocketServer({ server, wss, config, library }) {
  const heartbeat = new Map();

  server.on('upgrade', (req, socket, head) => {
    let pathname;
    try { pathname = new URL(req.url, 'http://localhost').pathname; }
    catch { socket.destroy(); return; }

    // Same-origin browser connections only. LAN clients use the page's own origin.
    const origin = req.headers.origin;
    const host = req.headers.host;
    if (pathname !== '/ws' || !origin || !host) {
      socket.write('HTTP/1.1 403 Forbidden\r\n\r\n');
      socket.destroy();
      return;
    }
    let originHost;
    try { originHost = new URL(origin).host; } catch {}
    if (originHost !== host) {
      socket.write('HTTP/1.1 403 Forbidden\r\n\r\n');
      socket.destroy();
      return;
    }
    if (wss.clients.size >= config.maxWsClients) {
      socket.write('HTTP/1.1 503 Service Unavailable\r\n\r\n');
      socket.destroy();
      return;
    }
    wss.handleUpgrade(req, socket, head, (ws) => wss.emit('connection', ws, req));
  });

  wss.on('connection', (ws) => {
    heartbeat.set(ws, Date.now());
    ws.send(JSON.stringify({ type: 'server:hello', data: { connected: true } }));

    ws.on('message', (raw) => {
      let message;
      try { message = JSON.parse(raw.toString()); } catch { return; }
      if (!message || typeof message.type !== 'string') return;
      if (message.type === 'client:heartbeat') {
        heartbeat.set(ws, Date.now());
        ws.send(JSON.stringify({ type: 'server:heartbeat', data: { at: Date.now() } }));
      }
      // Playback commands intentionally remain local to each browser.
    });

    ws.on('close', () => heartbeat.delete(ws));
    ws.on('error', () => heartbeat.delete(ws));
  });

  const timer = setInterval(() => {
    const now = Date.now();
    for (const [ws, lastSeen] of heartbeat) {
      if (now - lastSeen > 45000) {
        heartbeat.delete(ws);
        ws.terminate();
      }
    }
  }, 15000);
  timer.unref();

  const unsubscribe = library.onUpdated((tracks) => {
    const payload = JSON.stringify({ type: 'library:updated', data: { count: tracks.length } });
    for (const client of wss.clients) {
      if (client.readyState === 1) client.send(payload);
    }
  });

  return () => {
    clearInterval(timer);
    unsubscribe();
  };
}

module.exports = { attachWebSocketServer };
