# RIVUNE — Public MP3 Streaming

RIVUNE is a PHP/MySQL MP3 streaming website. The public can browse and listen without accounts. Only the administrator can upload or delete music.

## Stack
- PHP 7.4+ (PHP 8.x recommended)
- MySQL 5.7+ / MariaDB
- HTML, CSS and vanilla JavaScript
- HTTP byte-range audio streaming for seeking

## Features
- Public searchable MP3 library
- Persistent browser audio player with play/pause, previous/next, shuffle, seek and volume
- Admin login with password hashing and session protection
- Admin-only single-batch multi-file MP3 upload and delete
- SHA-256 duplicate detection: identical audio content is skipped, even when filenames differ
- Track titles automatically use filenames; optional artist and album values apply to the whole batch
- One-time web installer
- Protected persistent storage directory; audio is streamed through stream.php

## Installation
1. Create a MySQL database and database user in your hosting control panel.
2. Upload the repository contents to the website document root. Keep the existing `storage/` directory and its contents safe; it contains your database configuration and uploaded music.
3. Open `/install.php` in your browser.
4. Enter the MySQL host, database name, database username/password, and choose an administrator username/password (12+ characters).
5. Visit `/admin.php` directly to sign in and manage music. There is deliberately no admin link on the public page.
6. Visit the home page to listen.

## Uploading music
- Open `https://your-domain.example/admin.php`, sign in, choose multiple MP3 files in the file picker, then upload them together.
- Each file is limited to 25 MB by the application. PHP/hosting limits also apply: set `upload_max_filesize` to at least `25M`, `post_max_size` higher than the combined batch size (for example `256M`), and `max_file_uploads` high enough for your batch (for example `100`).
- If the host has a lower total request limit, upload fewer files per batch.
- Titles come from filenames. Optional artist and album fields are shared by all files in that batch.

## Updating the website without losing songs
Your music is stored in `storage/music/` and database settings are in `storage/config.php`. These are server-side data, not application code. When deploying a code update, replace the application files but **do not delete or overwrite `storage/`**, and do not run the installer again. Never upload a fresh empty `storage/` folder over the existing one. Back up the database and `storage/music/` together.

The Git repository ignores `storage/config.php` and uploaded music, so normal Git code updates do not include or replace your songs. If using a hosting file manager or FTP, preserve the existing storage directory manually.

## Hosting configuration
- Enable PHP PDO MySQL and Fileinfo extensions.
- Ensure PHP can write to `storage/` and `storage/music/`.
- Keep `.htaccess` enabled on Apache hosting. On Nginx, configure equivalent deny rules for `/storage/`.
- Use HTTPS for the admin login.

## Security notes
- Admin passwords are stored using PHP `password_hash()`.
- Upload and delete operations require an authenticated admin session and CSRF token.
- Uploaded files receive random server-side filenames.
- Audio files are not directly addressable by their stored path; the stream endpoint checks the database record.
- Do not commit `storage/config.php`, database dumps, or uploaded music to Git.

## Project status
The `dev` branch is the active PHP website implementation. The old Node.js/LAN discovery files remain in the repository but are not used by this PHP entry point. The `main` branch has not been changed.
