'use strict';

const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const mm = require('music-metadata');

const AUDIO_EXTENSIONS = new Set(['.mp3', '.m4a', '.aac', '.ogg', '.opus', '.flac', '.wav', '.aiff', '.wma']);

function createLibraryService(config) {
  let tracks = [];
  const listeners = new Set();
  const artworkDir = path.resolve(path.dirname(config.cacheFile), 'artwork');

  async function scanDirectory(dir) {
    const found = [];
    async function walk(current) {
      let entries;
      try { entries = await fs.promises.readdir(current, { withFileTypes: true }); }
      catch { return; }
      for (const entry of entries) {
        const full = path.join(current, entry.name);
        if (entry.isSymbolicLink()) continue;
        if (entry.isDirectory()) await walk(full);
        else if (entry.isFile() && AUDIO_EXTENSIONS.has(path.extname(entry.name).toLowerCase())) found.push(full);
      }
    }
    await walk(dir);
    return found;
  }

  async function buildTrack(filePath) {
    const stat = await fs.promises.stat(filePath);
    let metadata = {};
    try { metadata = await mm.parseFile(filePath, { duration: true }); } catch {}
    const common = metadata.common || {};
    const relativePath = path.relative(config.musicDir, filePath);
    const id = crypto.createHash('sha256').update(relativePath).digest('hex').slice(0, 32);
    let artworkPath = null;

    const picture = common.picture?.[0];
    if (picture?.data?.length) {
      const extByMime = { 'image/jpeg': '.jpg', 'image/png': '.png', 'image/webp': '.webp', 'image/gif': '.gif' };
      const ext = extByMime[picture.format] || '.img';
      artworkPath = path.join(artworkDir, `${id}${ext}`);
      try { await fs.promises.writeFile(artworkPath, picture.data); }
      catch { artworkPath = null; }
    }

    return {
      id,
      title: common.title || path.basename(filePath, path.extname(filePath)),
      artist: common.artist || 'Unknown artist',
      album: common.album || 'Unknown album',
      duration: metadata.format?.duration || 0,
      format: metadata.format?.codec || path.extname(filePath).slice(1).toUpperCase(),
      size: stat.size,
      artworkPath,
      _filePath: filePath
    };
  }

  function publicTrack(track) {
    const { _filePath, artworkPath, ...safe } = track;
    return {
      ...safe,
      artworkUrl: artworkPath ? `/api/tracks/${track.id}/artwork` : null,
      streamUrl: `/api/tracks/${track.id}/stream`
    };
  }

  async function refresh() {
    const paths = await scanDirectory(config.musicDir);
      const next = await Promise.all(paths.map(buildTrack));

        next.sort((a, b) => a.title.localeCompare(b.title));
          tracks = next;

            const publicTracks = tracks.map(publicTrack);

              for (const listener of listeners) {
                  listener(publicTracks);
                    }

                      return publicTracks;
                      }

  return {
    async initialize() {
      await fs.promises.mkdir(artworkDir, { recursive: true });
      await refresh();
    },
    refresh,
    getAll() { return tracks.map(publicTrack); },
    getById(id) { return tracks.find((track) => track.id === id) || null; },
    onUpdated(callback) { listeners.add(callback); return () => listeners.delete(callback); }
  };
}

module.exports = { createLibraryService };
