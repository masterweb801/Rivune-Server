'use strict';

const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const { Transform } = require('node:stream');
const { pipeline } = require('node:stream/promises');
const { sendJson, sendError } = require('../utils/http');
const { streamTrack } = require('../streaming/streamController');
const { contentTypeFor } = require('../streaming/mimeTypes');

const AUDIO_EXTENSIONS = new Set(['.mp3', '.m4a', '.aac', '.ogg', '.opus', '.flac', '.wav', '.aiff', '.wma']);
const MAX_UPLOAD_BYTES = 100 * 1024 * 1024;

function safeUploadName(value) {
  if (typeof value !== 'string' || !value.trim() || value.length > 180) return null;
  const name = value.trim();
  if (name !== path.basename(name) || name.includes('/') || name.includes('\\') || name === '.' || name === '..') return null;
  if (!AUDIO_EXTENSIONS.has(path.extname(name).toLowerCase())) return null;
  return name;
}

async function saveUploadedTrack(req, config, filename) {
  const destination = path.resolve(config.musicDir, filename);
  if (!destination.startsWith(path.resolve(config.musicDir) + path.sep)) {
    const error = new Error('Invalid filename');
    error.statusCode = 400;
    throw error;
  }
  try {
    await fs.promises.access(destination);
    const error = new Error('A file with that name already exists');
    error.statusCode = 409;
    throw error;
  } catch (error) {
    if (error.statusCode) throw error;
    if (error.code !== 'ENOENT') throw error;
  }

  const temporary = path.join(config.musicDir, `.upload-${crypto.randomUUID()}.tmp`);
  let size = 0;
  const limiter = new Transform({
    transform(chunk, encoding, callback) {
      size += chunk.length;
      if (size > MAX_UPLOAD_BYTES) {
        const error = new Error('Upload exceeds the 100 MB limit');
        error.statusCode = 413;
        callback(error);
        return;
      }
      callback(null, chunk);
    }
  });

  try {
    await pipeline(req, limiter, fs.createWriteStream(temporary, { flags: 'wx' }));
    if (!size) {
      const error = new Error('The uploaded file is empty');
      error.statusCode = 400;
      throw error;
    }
    await fs.promises.rename(temporary, destination);
  } catch (error) {
    await fs.promises.rm(temporary, { force: true }).catch(() => {});
    throw error;
  }
}

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
      if (url.pathname === '/api/tracks/upload' && req.method === 'POST') {
        const filename = safeUploadName(url.searchParams.get('filename'));
        if (!filename) return sendError(res, 400, 'Choose a supported audio file');
        const declaredSize = Number(req.headers['content-length'] || 0);
        if (declaredSize > MAX_UPLOAD_BYTES) return sendError(res, 413, 'Upload exceeds the 100 MB limit');
        await saveUploadedTrack(req, config, filename);
        await library.refresh();
        return sendJson(res, 201, { uploaded: true, tracks: library.getAll().length });
      }
      const deleteMatch = url.pathname.match(/^\/api\/tracks\/([a-f0-9-]+)$/i);
      if (deleteMatch && req.method === 'DELETE') {
        const track = library.getById(deleteMatch[1]);
        if (!track) return sendError(res, 404, 'Track not found');
        const musicRoot = path.resolve(config.musicDir) + path.sep;
        const filePath = path.resolve(track._filePath);
        if (!filePath.startsWith(musicRoot)) return sendError(res, 403, 'Forbidden');
        await fs.promises.unlink(filePath);
        await library.refresh();
        return sendJson(res, 200, { deleted: true, tracks: library.getAll().length });
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
      if (!res.headersSent) sendError(res, error.statusCode || 500, error.statusCode ? error.message : 'Internal server error');
      else res.destroy(error);
      if (!error.statusCode || error.statusCode >= 500) console.error('HTTP request error:', error);
    }
  };
}

module.exports = { createHttpHandler };
