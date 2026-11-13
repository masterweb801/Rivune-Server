# RIVUNE — Public MP3 Streaming

RIVUNE is now a PHP/MySQL MP3 streaming website. The public can browse and listen without accounts. Only the administrator can upload or delete music.

## Stack
- PHP 7.4+ (PHP 8.x recommended)
- MySQL 5.7+ / MariaDB
- HTML, CSS and vanilla JavaScript
- HTTP byte-range audio streaming for seeking

## Features
- Public searchable MP3 library
- Persistent browser audio player with play/pause, previous/next, shuffle, seek and volume
- Admin login with password hashing and session protection
- Admin-only MP3 upload and delete
- Song title, artist and album fields
- One-time web installer
- Protected storage directory; audio is streamed through stream.php

## Installation
1. Create a MySQL database and database user in your hosting control panel.
2. Upload the repository contents to the website document root. Keep the storage/ directory writable by PHP; do not expose its contents publicly.
3. Open /install.php in your browser.
4. Enter the MySQL host, database name, database username/password, and choose an administrator username/password (12+ characters).
5. Sign in at /admin.php and upload MP3 files.
6. Visit the home page to listen.

The installer creates the tracks table and writes database/admin settings to storage/config.php. It refuses to run again once that file exists. Back up the database and storage/ directory together.

## Hosting configuration
- Enable PHP PDO MySQL and Fileinfo extensions.
- Set upload_max_filesize and post_max_size to at least 26M (or larger if you raise the application upload limit).
- Ensure PHP can write to storage/ and storage/music/.
- Keep .htaccess enabled on Apache hosting. On Nginx, configure equivalent deny rules for /storage/.
- Use HTTPS for the admin login.
- The application upload limit is currently 25 MB per MP3.

## Security notes
- Admin passwords are stored using PHP password_hash().
- Upload and delete operations require an authenticated admin session and CSRF token.
- Uploaded files are restricted to MP3 extension/MIME checks and receive random server-side filenames.
- Audio files are not directly addressable by their stored path; the stream endpoint checks the database record.
- Do not commit storage/config.php, database dumps, or uploaded music to Git.

## Project status
The dev branch is the active PHP website implementation. The old Node.js/LAN discovery files remain in the repository for now but are not used by this PHP entry point. The main branch has not been changed.
