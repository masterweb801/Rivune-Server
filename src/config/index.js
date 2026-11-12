'use strict';

const path = require('node:path');

function loadConfig() {
  const projectRoot = path.resolve(__dirname, '../../');
  return Object.freeze({
    host: process.env.HOST || '0.0.0.0',
    port: Number.parseInt(process.env.PORT || '8080', 10),
    musicDir: path.resolve(projectRoot, process.env.MUSIC_DIR || 'music'),
    publicDir: path.resolve(projectRoot, process.env.PUBLIC_DIR || 'public'),
    cacheFile: path.resolve(projectRoot, 'data/library-cache.json'),
    maxWsPayload: Number.parseInt(process.env.MAX_WS_PAYLOAD || '16384', 10),
    maxWsClients: Number.parseInt(process.env.MAX_WS_CLIENTS || '50', 10)
  });
}

module.exports = { loadConfig };
