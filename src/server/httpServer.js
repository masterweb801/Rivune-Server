'use strict';

const fs = require('node:fs');
const path = require('node:path');
const { sendJson, sendError } = require('../utils/http');
const { streamTrack } = require('../streaming/streamController');
const { contentTypeFor } = require('../streaming/mimeTypes');

function createHttpHandler({ config, library }) {
  return async (req, res) => {
    try {
      const url = new URL(req.url, `http://${req.headers.host || 'localhost'}`);
      if (url.pathname === '/api/health') {
        return sendJson(res, 200, { service: 'mobs-jukebox', status: 'ok', tracks: library.getAll().length });
      }
      if (url.pathname === '/api/library' && req.method === 'GET') {
        return sendJson(res, 200, { tracks: library.getAll() });
      }
      const trackMatch = url.pathname.match(/^\/api\/tracks\/([a-f0-9-]+)$/i);
      if (trackMatch && req.method === 'GET') {
        const track = library.getById(trackMatch[1]);
        if (!track) return sendError(res, 404, 'Track not found');
        return sendJson(res, 200, track);
      }
      const streamMatch = url.pathname.match(/^\/api\/tracks\/([a-f0-9-]+)\/stream$/i);
      if (streamMatch && req.method === 'GET') {
        const track = library.getById(streamMatch[1]);
        if (!track) return sendError(res, 404, 'Track not found');
        return streamTrack(req, res, track);
      }
      const artworkMatch = url.pathname.match(/^\/api\/tracks\/([a-f0-9-]+)\/artwork$/i);
      if (artworkMatch && req.method === 'GET') {
        const track = library.getById(artworkMatch[1]);
        if (!track || !track.artworkPath) return sendError(res, 404, 'Artwork not found');
        res.writeHead(200, { 'Content-Type': contentTypeFor(track.artworkPath), 'Cache-Control': 'public, max-age=3600', 'X-Content-Type-Options': 'nosniff' });
        return fs.createReadStream(track.artworkPath).pipe(res);
      }
      if (url.pathname.startsWith('/api/')) return sendError(res, 404, 'API route not found');

      const requested = url.pathname === '/' ? 'index.html' : decodeURIComponent(url.pathname).replace(/^\/+/, '');
      const target = path.resolve(config.publicDir, requested);
      if (!target.startsWith(config.publicDir + path.sep) && target !== config.publicDir) {
        return sendError(res, 403, 'Forbidden');
      }
      let stat;
      try { stat = await fs.promises.stat(target); } catch { return sendError(res, 404, 'Not found'); }
      if (!stat.isFile()) return sendError(res, 404, 'Not found');
      res.writeHead(200, {
        'Content-Type': contentTypeFor(target),
        'Content-Length': stat.size,
        'X-Content-Type-Options': 'nosniff',
        'Cache-Control': 'no-cache'
      });
      fs.createReadStream(target).pipe(res);
    } catch (error) {
      if (!res.headersSent) sendError(res, 500, 'Internal server error');
      else res.destroy(error);
      console.error('HTTP request error:', error);
    }
  };
}

module.exports = { createHttpHandler };
