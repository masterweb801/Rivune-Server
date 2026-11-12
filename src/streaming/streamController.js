'use strict';

const fs = require('node:fs');
const { contentTypeFor } = require('./mimeTypes');

function streamTrack(req, res, track) {
  const filePath = track._filePath;
  fs.stat(filePath, (error, stat) => {
    if (error || !stat.isFile()) {
      res.writeHead(404).end('File not found');
      return;
    }
    const range = req.headers.range;
    const headers = {
      'Content-Type': contentTypeFor(filePath),
      'Accept-Ranges': 'bytes',
      'Cache-Control': 'private, no-cache',
      'X-Content-Type-Options': 'nosniff'
    };

    if (!range) {
      res.writeHead(200, { ...headers, 'Content-Length': stat.size });
      fs.createReadStream(filePath).pipe(res);
      return;
    }

    const match = /^bytes=(\d*)-(\d*)$/.exec(range);
    if (!match || (!match[1] && !match[2])) {
      res.writeHead(416, { 'Content-Range': `bytes */${stat.size}` }).end();
      return;
    }

    let start, end;
    if (!match[1]) {
      const suffix = Number(match[2]);
      start = Math.max(0, stat.size - suffix);
      end = stat.size - 1;
    } else {
      start = Number(match[1]);
      end = match[2] ? Number(match[2]) : stat.size - 1;
    }
    if (!Number.isSafeInteger(start) || !Number.isSafeInteger(end) ||
        start < 0 || start >= stat.size || end < start) {
      res.writeHead(416, { 'Content-Range': `bytes */${stat.size}` }).end();
      return;
    }
    end = Math.min(end, stat.size - 1);
    res.writeHead(206, {
      ...headers,
      'Content-Length': end - start + 1,
      'Content-Range': `bytes ${start}-${end}/${stat.size}`
    });
    const stream = fs.createReadStream(filePath, { start, end });
    res.on('close', () => { if (!res.writableEnded) stream.destroy(); });
    stream.pipe(res);
  });
}

module.exports = { streamTrack };
