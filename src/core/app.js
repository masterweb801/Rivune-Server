'use strict';

const http = require('node:http');
const fs = require('node:fs');
const path = require('node:path');
const { WebSocketServer } = require('ws');
const { createLibraryService } = require('../library/libraryService');
const { createHttpHandler } = require('../server/httpServer');
const { attachWebSocketServer } = require('../server/websocketServer');
const { createDiscoveryServer } = require('../server/discoveryServer');

async function createApp(config) {
  await fs.promises.mkdir(config.musicDir, { recursive: true });
  await fs.promises.mkdir(path.dirname(config.cacheFile), { recursive: true });

  const library = createLibraryService(config);
  await library.initialize();

  const server = http.createServer(createHttpHandler({ config, library }));
  const wss = new WebSocketServer({
    noServer: true,
    maxPayload: config.maxWsPayload,
    perMessageDeflate: false
  });

  const detachWebSocket = attachWebSocketServer({ server, wss, config, library });

  const discoveryServer = createDiscoveryServer({
    httpPort: config.port
  });

  await discoveryServer.start();

  return {
    server,
    async close() {
      await discoveryServer.close();
      detachWebSocket();
      for (const client of wss.clients) client.close(1001, 'Server shutting down');
      await new Promise((resolve) => server.close(resolve));
    }
  };
}

module.exports = { createApp };
