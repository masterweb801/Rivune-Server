# RIVUNE Security Audit — Initial Hardening

**Branch:** `dev`  
**Scope:** PHP authentication, sessions, CSRF validation, admin exports, storage access rules, public API and streaming entry points.

## Changes applied

### 1. Administrator login throttling
- Added `lib/admin_auth.php`.
- Five failed attempts for the same keyed client-address/username identity within a 15-minute window trigger a 15-minute lockout.
- The database stores only an HMAC-SHA-256 identity key, not the raw client IP address.
- The throttle table is created automatically when the first login attempt is made.
- If the hosting database account cannot create/use the throttle table, a per-session fallback is used. This fallback is weaker because it does not persist across browser sessions.

### 2. Admin session hardening
- Enabled strict session ID mode and cookie-only session handling.
- Retained HttpOnly, HTTPS-only Secure (when HTTPS is detected), and SameSite=Lax cookie attributes.
- Added a 30-minute idle timeout and a 12-hour absolute admin session lifetime.
- Admin pages send no-store cache headers.
- Dashboard views now enforce the same expiry check as the other admin pages.
- Login already regenerated the session ID; the expiry and logout flows also rotate it.

### 3. CSRF input validation
- CSRF validation now rejects missing, non-string, and empty tokens before calling `hash_equals`.
- This avoids malformed array input causing a PHP type error.

### 4. CSV export formula-injection protection
- Track metadata values beginning with spreadsheet formula characters (including after leading control/whitespace characters) are prefixed with an apostrophe in CSV exports.
- This reduces the risk of formulas executing when an administrator opens exported metadata in spreadsheet software.

### 5. Existing protections verified in source
- `storage/.htaccess` denies direct web access to configuration and music storage.
- `storage/config.php` is excluded from Git by `.gitignore`.
- Sensitive admin actions use CSRF checks and prepared SQL statements.
- Admin-only pages call the admin authorization guard.
- Public streaming serves files from the private storage directory through `stream.php`.

## Deployment files

Upload/replace only these production files:

1. `admin.php`
2. `admin-backups.php`
3. `lib/bootstrap.php`
4. `lib/admin_auth.php` (new file)

Do not replace or delete `storage/config.php`, `storage/music/`, database tables/records, artwork, or existing assets. No manual database migration is required; the login-throttle table is created on demand.

## Post-deployment checks

1. Sign in with the correct admin credentials.
2. Confirm Overview, Integrity, Logs, Alerts, Backup & Export, and Maintenance pages still open.
3. Download a CSV export and confirm normal titles/artist names remain readable.
4. For a controlled lockout test, use the same browser/network identity and submit five incorrect passwords. Further attempts should be blocked temporarily. The lockout lasts 15 minutes; avoid this test if you need uninterrupted admin access.
5. Sign out, then sign back in and confirm the session works normally.

## Limitations and follow-up

- This is a source-level audit and hardening pass. The live InfinityFree site, real MySQL permissions, PHP runtime, and Android WebView have not been tested from this change.
- Persistent login throttling depends on the hosting database account allowing table creation. If it does not, the code falls back to a per-session limit.
- A restrictive Content-Security-Policy and HSTS policy were not added in this pass because they require careful validation against the site's inline scripts, styles, and hosting HTTPS behavior.
- Automated database restore remains intentionally disabled; the existing backup page explains why.

## Administrator security-question recovery

- The login page's **Forgot password?** link opens `admin-forgot-password.php`.
- The fixed questions are “My favourite color?”, “My first cat's name?”, and “LOML?”; only the answers are editable from the authenticated Account Security page.
- Updating answers requires the current administrator password. Answers are normalized for case and whitespace, then stored as individual `password_hash()` hashes in the protected `storage/config.php`; they are never displayed back.
- Recovery requires all three answers. A successful verification grants a five-minute, session-bound reset window; the administrator must then sign in normally with the new password.
- Failed recovery attempts use the shared database-backed login throttle (five failures in 15 minutes trigger a 15-minute lockout where the throttle table is available). No answer values are written to logs.
- This is a convenience recovery method, not multi-factor authentication. Security-question answers can be guessed or discovered; choose answers that are private, hard to research, and not obvious from public profiles. A strong unique admin password remains essential.

### Recovery deployment files

Upload/replace these files for the recovery feature:

1. `admin.php` (contains the Forgot password link)
2. `admin-change-password.php` (password and recovery-answer settings)
3. `admin-forgot-password.php` (new recovery page)
4. `lib/admin_recovery.php` (new helper)
5. `lib/admin_auth.php` (shared attempt throttling; new from the earlier security pass)

Also deploy `lib/bootstrap.php` if the earlier session/CSRF hardening has not yet been deployed. Do not overwrite `storage/config.php`, `storage/music/`, database contents, artwork, or unrelated assets.


## Administrator route authorization review — 2026-11-24

### Route inventory

The following dedicated administrator routes were source-reviewed and each invokes `require_admin()` before accessing protected data or processing an administrator action:

- `admin-alerts.php`
- `admin-analytics.php`
- `admin-artwork.php`
- `admin-backups.php`
- `admin-change-password.php`
- `admin-extract-artwork.php`
- `admin-integrity.php`
- `admin-logs.php`
- `admin-maintenance.php`
- `admin-songs.php`
- `admin-upload.php`

`admin.php` is intentionally the public sign-in endpoint. Its dashboard is shown only after the session passes `require_admin()`; privileged POST actions (upload, delete, storage refresh, diagnostics, and logout) are individually guarded. The logout action now explicitly checks authorization before invalidating the session.

`admin-forgot-password.php` is intentionally public and exposes only the recovery workflow. It requires the configured recovery answers, CSRF validation, a shared attempt throttle, and a five-minute session-bound verification before accepting a password reset. It does not grant an admin session; a fresh normal sign-in is required after reset.

`install.php` is only available before `storage/config.php` exists; once installed, it redirects to `admin.php`.

### Guard behavior hardened

- `require_admin()` now sends unauthenticated direct requests to `admin.php?login=required` using HTTP 303.
- Protected responses receive `Cache-Control: no-store`, `Pragma: no-cache`, and `Vary: Cookie` before authorization is evaluated.
- Existing session-version revocation, 30-minute idle timeout, and 12-hour absolute lifetime remain enforced.
- Added `lib/.htaccess` to deny direct HTTP access to internal PHP helper files. PHP filesystem includes continue to work normally.

### Verification limits

This is a source-level route and authorization review. The repository integration does not provide a live unauthenticated HTTP session against the deployed InfinityFree site, so actual HTTP status/redirect behavior, Apache `.htaccess` support, and browser cache behavior still require deployment testing. No claim is made that those runtime checks have been performed.
