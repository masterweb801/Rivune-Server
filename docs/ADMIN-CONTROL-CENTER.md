# RIVUNE Admin Control Center

Status: approved feature specification  
Target branch: `dev`  
Target hosting: InfinityFree (PHP + MySQL)

This document records the approved admin-panel scope. It does not claim that the features below are already implemented.

## Goals

Expand the existing administrator page into a Control Center while preserving the public, account-free listening experience, existing MP3 upload/delete workflow, SHA-256 duplicate detection, byte-range streaming, and Android playback bridge.

## Navigation

1. Overview
2. Music Library
3. Upload Manager
4. Storage & Resources
5. Listening Analytics
6. System Health
7. Security
8. Activity Logs
9. Backup & Maintenance

Existing music management and listening analytics remain available; this project adds the operational and maintenance tools below.

## Approved features

### 1. Storage & Resources

Show two clearly separated measurements:

- Hosting account storage: official quota, used bytes, remaining bytes, and percentage, only when a trustworthy hosting-provided metric is available.
- RIVUNE application storage: measured music, artwork, other public assets, application files, and database usage where measurable.

Additional views:
- Largest files and folders.
- Storage usage history and growth trend.
- Estimated time to capacity only when a meaningful quota and measured growth history exist.
- Configurable warning thresholds (default 75%, 90%, and 95%).

Important: PHP filesystem scans only measure files the application can access. Do not label RIVUNE's measured directory total as the entire hosting-account usage. If InfinityFree does not expose an account quota through a supported interface, show the hosting quota as "Unavailable" and continue showing measured RIVUNE usage.

### 2. System Health

On-demand diagnostics with timestamped results:

- Database connection and a lightweight query.
- Required database tables.
- PHP version and required extensions (PDO MySQL and Fileinfo).
- Music and artwork directory existence, readability, and writability where applicable.
- Streaming endpoint file presence and a safe local configuration check.
- Relevant storage permissions.
- Last successful diagnostic and detailed failure explanations.

No permanent background process or cron dependency. Checks run on admin page requests or explicit manual scans.

### 3. File Integrity Scanner

Report:
- Database tracks whose stored MP3 file is missing.
- MP3 files in the music directory with no database record (orphans).
- Duplicate audio content using SHA-256.
- Missing or broken artwork references.
- Zero-byte files.
- Invalid file types and incomplete metadata.

Safety:
- Scanning is read-only.
- Never delete or repair files automatically.
- Show findings and require a separate explicit admin action for any future cleanup.
- Process large libraries in bounded batches to avoid PHP execution-time and memory limits.
- Do not hash the same unchanged file repeatedly if a reliable cached hash is available.

### 4. Backup & Export

- Downloadable SQL export of the RIVUNE database.
- CSV export of track metadata.
- Backup history with timestamp, size, and outcome when history can be stored safely.
- Validate generated exports before reporting success.
- Restore workflow, if added, must require explicit confirmation and a pre-restore backup.

Database exports do not include MP3 files. Music files need a separate backup procedure. Prefer streaming the generated export to the administrator rather than retaining unlimited backup copies on shared hosting.

Never commit database credentials, SQL dumps, uploaded music, or `storage/config.php` to Git.

### 5. Alerts

An admin alert center with read/unread state, timestamps, history, and dismissal.

Initial alert events:
- Application storage threshold reached (75%, 90%, 95%).
- Upload failure.
- Database or required-table failure.
- Missing music file.
- Duplicate file found.
- Backup failure.

Do not generate repeated duplicate alerts for the same unresolved condition. Hosting-quota alerts require a reliable official quota source; application-usage alerts must be labelled as such.

Alerts are evaluated during page requests and manual scans, not by an assumed background worker.

### 6. Maintenance Mode

- Branded public maintenance page and configurable message.
- Admin can continue to access the admin area and disable maintenance mode.
- Public page, public library API, and public audio streaming are unavailable while maintenance is enabled.
- Maintenance state is persisted server-side.
- Fail safely if the setting cannot be read: do not accidentally lock the administrator out.
- Protect state changes with admin authentication and CSRF validation.

### 7. Activity Logs

Record important events:
- Successful and failed admin login.
- Logout.
- Track upload, duplicate skip, and deletion.
- Metadata changes.
- Integrity scan start/completion and summary.
- Database export/restore.
- Maintenance mode enable/disable.
- Security and system alerts.

Record timestamp, event type, concise message, and relevant track/operation identifier. Never log passwords, session IDs, CSRF tokens, database credentials, or other secrets. Apply pagination and a retention policy to prevent unbounded table growth.

## InfinityFree implementation constraints

- Use the existing PHP/MySQL application; no Node.js server, WebSocket server, cron job, daemon, or persistent worker.
- Use bounded/paginated queries and small filesystem batches.
- Avoid expensive full-tree scans on every dashboard load.
- Treat PHP filesystem measurements as measurements of accessible application files, not proof of total account quota.
- Do not assume shell commands, arbitrary PHP extensions, or hosting control-panel APIs are available.
- Keep all admin actions behind the existing session authentication and CSRF protection.
- Keep public listening account-free.
- Preserve byte-range streaming and the Android `rivune.playback` bridge.
- Keep `main` unchanged; all development work belongs on `dev`.

## Suggested implementation sequence

1. Shared admin layout/navigation and safe activity-log foundation.
2. Storage measurements and overview cards.
3. On-demand system diagnostics.
4. Read-only integrity scanner with bounded batches.
5. Downloadable SQL and CSV exports.
6. Alert center and threshold evaluation.
7. Maintenance mode enforcement across public entry points.
8. Integration, security, and regression testing.

## Acceptance criteria

- Existing public playback, seeking, upload, delete, duplicate detection, and Android playback remain functional.
- No admin endpoint is accessible without a valid admin session.
- State-changing actions require CSRF validation.
- Scans never delete files automatically.
- Hosting quota is never fabricated or inferred from application directory sizes.
- Backup exports are downloadable and validated; music files are clearly excluded.
- Maintenance mode blocks public endpoints but never locks out an authenticated administrator.
- Long-running operations use bounded batches and provide clear progress/results.
- No secrets or user music are added to the Git repository.
