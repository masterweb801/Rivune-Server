# RIVUNE Production Readiness Audit

**Branch reviewed:** `dev`  
**Audit date:** 2026-11-20  
**Application:** PHP/MySQL website for InfinityFree  
**Scope:** repository tree, installer/schema, authentication and recovery, admin routes, upload/delete, public API, byte-range streaming, maintenance mode, integrity scanner, storage metrics, alerts/logs, backups, frontend player, and Android playback bridge.

## Executive summary

The active PHP application is present on `dev`. The repository also contains legacy Node.js/LAN-discovery files and a legacy static frontend under `public/`; the PHP website entry point does not use the old Node server. The ZIP supplied with this review contains only the older Node.js/LAN-server snapshot and is not a valid source snapshot for the current PHP website.

One fresh-install defect was confirmed and fixed in this audit: the installer created the `tracks` table without `file_hash`, although uploads and the integrity scanner query that column. The installer now creates the column and index and performs an additive migration if an existing `tracks` table lacks them. Existing rows are not dropped or rewritten.

No live InfinityFree, production MySQL, real browser, or Android-device tests were performed as part of this source review. Findings below distinguish source-confirmed behavior from runtime checks still required.

## Confirmed fix applied

### P1 — Fresh installation omitted the SHA-256 column

**File:** `install.php`  
**Impact:** A fresh installation could fail on the first upload because `admin.php` selects and inserts `tracks.file_hash`, and `admin-integrity.php` also reads it.

**Fix on `dev`:**
- Fresh `tracks` table creation now includes nullable ASCII `file_hash CHAR(64)`.
- Adds `idx_tracks_file_hash` for duplicate-hash lookups.
- If an existing table lacks the column, installer adds it without deleting or rewriting track records.
- If the index is absent, installer adds it.

The installer must not be rerun on the live site merely to apply this code change. Existing installations should continue using their existing configuration and database.

## Findings requiring follow-up

### Fixed — SHA-256 duplicate-upload race

**File:** `admin.php`  
Each upload now acquires a MySQL named lock derived from a 200-bit prefix of the SHA-256 digest before checking `tracks.file_hash`. The lock remains held through the file move and database insert, then is released in a `finally` block. Concurrent requests for identical audio therefore serialize: the later request sees the earlier insert and skips the duplicate.

The upload fails closed for that file if MySQL cannot grant the named lock or duplicate verification fails. This avoids silently falling back to the original race. Existing duplicate records are not deleted or modified, and no unique-index migration is required. This strategy assumes all application upload paths use the same lock protocol.

### Fixed — Revoke administrator sessions after password change/reset

**Files:** `lib/bootstrap.php`, `admin-change-password.php`, `admin-forgot-password.php`, `admin.php`, `install.php`  
Admin sessions now store a version number from protected configuration. `require_admin()` rejects sessions whose version is missing or differs from the current config version. Password changes and recovery resets increment the version; the current password-change session is updated to the new version, while all other sessions are forced to sign in again. Recovery resets continue to require a fresh normal sign-in.

New installations initialize the version to 1. Existing configurations without the setting use version 1; existing sessions without a session-version value are deliberately invalidated on their next protected request. No database or session-file access is needed.

### P2 — Integrity scan has scalability and coverage gaps

**File:** `admin-integrity.php`
- The duplicate-hash aggregation runs as one unbounded grouped query at scan start. It is capped to 100 returned groups, but the database may still need to scan/group the full hash column.
- Directory batches restart a `DirectoryIterator` from the beginning and skip the saved offset on every request. This repeats work as the library grows.
- The scanner groups stored database hashes but does not recompute SHA-256 for each MP3 and compare it with the stored hash. It can therefore miss a file whose bytes changed while remaining a valid MP3.
- Artwork-reference checks are not implemented. The current track schema has no per-track artwork field, so there are no track artwork references to validate yet.
- MP3 validation checks for an MPEG frame in the first 1 MB; it is a lightweight signature check, not a full audio decode/corruption test.

**Recommendation:** Use a resumable deterministic scan cursor and bounded duplicate/hash batches. Add optional cached hash verification in small batches, with clear progress and no automatic repair/deletion.

### P2 — Storage estimate excludes some application files

**File:** `lib/admin_center.php`  
The estimate includes music, `public/assets`, `assets`, and measurable database size. It does not include root PHP files, documentation, or all other account files. Each directory scan is capped at 8,000 files and can be partial.

The UI already labels the 4.5 GB figure as an owner-supplied reference rather than a live InfinityFree quota. Keep that qualification. Do not present the estimate as total hosting-account usage.

**Recommendation:** Include measurable application code directories/files or label the figure more narrowly as measured music/assets/database usage. Preserve the partial-scan indicator.

### P2 — Activity logs and alert/history tables have no retention cleanup

**Files:** `lib/admin_logs.php`, `lib/admin_alerts.php`, `admin-backups.php`  
Activity logs, resolved alerts, and backup-history records can grow indefinitely. This conflicts with the approved retention goal and can increase database size over time.

**Recommendation:** Add bounded, administrator-configurable or documented retention cleanup. Cleanup must be limited per request and must never remove unresolved alerts or current backup evidence unexpectedly.

### P2 — Public library API returns the complete catalogue

**File:** `api.php`  
The API selects every track and returns all metadata in one JSON response. The website also sends the loaded catalogue/queue to the Android bridge. This is simple and correct for a small library but grows in response size and memory use as the catalogue expands.

**Recommendation:** Introduce pagination or a bounded response strategy only if library size makes it necessary. Preserve the current bridge contract or version it deliberately; do not silently break Android compatibility.

### P2 — Stream path should validate the stored filename

**File:** `stream.php`  
The stream path is assembled from the database's `stored_name`. Normal uploads generate random server-side names, and storage is denied direct web access, but the stream endpoint does not independently enforce a basename/allowed filename pattern.

**Recommendation:** Validate that the stored name is a basename and matches the expected generated MP3 filename format before opening it. Return a generic not-found response on invalid values.

### P3 — System diagnostics are narrower than the approved specification

**File:** `lib/admin_center.php`  
Current checks cover database connectivity, the tracks table, PHP version/extensions, and directory existence/access. They do not verify all expected track columns, the streaming endpoint's range behavior, or every operational admin table.

**Recommendation:** Expand diagnostics with safe read-only schema and endpoint checks. Do not run expensive full scans during normal dashboard loads.

### P3 — Security headers remain incomplete

**File:** `.htaccess`  
The root configuration sets `nosniff`, `Referrer-Policy`, and `X-Frame-Options`. A restrictive Content-Security-Policy and HSTS are not configured. They were previously deferred because inline scripts/styles and hosting HTTPS behavior need validation.

**Recommendation:** Test a report-only CSP first. Add HSTS only after confirming HTTPS is enforced for the whole production hostname and all relevant subdomains.

## Source-reviewed protections

- Admin-only pages call `require_admin()`.
- State-changing admin forms use CSRF validation.
- Passwords and recovery answers use PHP password hashing.
- Recovery requires all three fixed answers; the verification window is five minutes and attempts share the login-throttle mechanism.
- Login throttling uses a keyed hash of client address and username; if database table operations fail, the fallback is session-local and weaker.
- Admin sessions have 30-minute idle and 12-hour absolute limits.
- Storage has an Apache deny rule; `storage/config.php` and music are excluded from Git.
- Uploads use random stored filenames, a 25 MB per-file application limit, MP3-frame checks, and SHA-256 duplicate lookup.
- Streaming supports byte ranges and releases the PHP session lock before sending audio.
- Maintenance mode is enforced by the PHP homepage, public API, and stream endpoint. It fails open if the maintenance setting cannot be read, avoiding accidental public/admin lockout.
- SQL and CSV exports are downloadable; automated restore is intentionally disabled. SQL exports do not include MP3 files or `storage/config.php`.
- The Android playback bridge remains optional; browser playback is retained when the native bridge is absent.

## Required runtime regression checklist

Run these on the live site after deploying relevant code, using a small test MP3 where possible:

1. Sign in, sign out, idle expiry, and absolute session expiry.
2. Change password and verify the old password no longer works.
3. Configure all three recovery answers, verify a failed answer, then verify successful recovery and normal sign-in.
4. Upload one MP3, upload the same audio under a different filename, and confirm the duplicate is skipped.
5. Upload a deliberately invalid file renamed to `.mp3`; confirm it is rejected.
6. Confirm upload progress, disabled submit button during upload, and completion sound.
7. Delete only a disposable test track and verify its record/file handling.
8. Request a stream normally and with `Range: bytes=0-1023`; expect HTTP 206 and a 1,024-byte body for a sufficiently large test file.
9. Test an invalid track ID and an unsatisfiable range.
10. Run the integrity scanner through completion on a small library; confirm it does not modify files or rows.
11. Download SQL and CSV exports; store them off-host and verify the SQL file is non-empty.
12. Enable maintenance mode and confirm homepage, API, and stream return 503 while admin remains accessible; disable it.
13. Confirm alerts and activity logs record events and that the Android app still plays, seeks, and receives next/previous commands.

## Deployment safety

For the confirmed installer fix only, the changed file is `install.php`. It is relevant to fresh setup or a deliberate install-time schema migration. Do not rerun the installer on an already-configured live site.

Never overwrite or delete:
- `storage/config.php`
- `storage/music/`
- the live MySQL database or its track records
- artwork and other user-uploaded assets

Do not deploy the legacy Node.js/LAN server files as a replacement for the PHP website. Keep all follow-up work on `dev`; do not modify `main` without explicit approval.
