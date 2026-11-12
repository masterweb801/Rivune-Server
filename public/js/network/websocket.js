export function createSocket(onStatus, onMessage) {
  let socket;
  let retry = 1000;
  let heartbeat;
  function connect() {
    const protocol = location.protocol === 'https:' ? 'wss:' : 'ws:';
    socket = new WebSocket(`${protocol}//${location.host}/ws`);
    socket.addEventListener('open', () => {
      retry = 1000;
      onStatus(true);
      heartbeat = setInterval(() => {
        if (socket.readyState === WebSocket.OPEN)
          socket.send(JSON.stringify({ type: 'client:heartbeat' }));
      }, 20000);
    });
    socket.addEventListener('message', event => {
      try { onMessage(JSON.parse(event.data)); } catch {}
    });
    socket.addEventListener('close', () => {
      clearInterval(heartbeat);
      onStatus(false);
      setTimeout(connect, retry);
      retry = Math.min(retry * 2, 15000);
    });
    socket.addEventListener('error', () => socket.close());
  }
  connect();
  return { close: () => socket?.close() };
}
