'use strict';

const path = require('node:path');
const TYPES = {
  '.mp3': 'audio/mpeg', '.m4a': 'audio/mp4', '.mp4': 'audio/mp4',
  '.aac': 'audio/aac', '.ogg': 'audio/ogg', '.opus': 'audio/ogg',
  '.flac': 'audio/flac', '.wav': 'audio/wav', '.aiff': 'audio/aiff',
  '.wma': 'audio/x-ms-wma', '.html': 'text/html; charset=utf-8',
  '.css': 'text/css; charset=utf-8', '.js': 'text/javascript; charset=utf-8',
  '.json': 'application/json; charset=utf-8', '.svg': 'image/svg+xml',
  '.png': 'image/png', '.jpg': 'image/jpeg', '.jpeg': 'image/jpeg',
  '.webp': 'image/webp', '.ico': 'image/x-icon'
};
function contentTypeFor(file) { return TYPES[path.extname(file).toLowerCase()] || 'application/octet-stream'; }
module.exports = { contentTypeFor };
