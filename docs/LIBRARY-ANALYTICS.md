# RIVUNE Library Pagination & Song Analytics

- Public site fetches 50 tracks per page and automatically loads more near the bottom, with skeleton placeholders and a manual fallback.
- Search is debounced and server-paginated. Artwork retains lazy loading.
- Shuffle and Random fetch the full library on demand to remain global.
- Android receives the legacy unpaginated API response when no pagination parameters are supplied.
- Explicit play-start events increment a separate track_plays counter. Range requests, seeks, and buffering do not count.
- A 30-second per-session/per-track cooldown reduces accidental duplicate counting. Native state transitions are observed for Android notification-driven track changes.
- Admin → Songs → Song analytics initializes the additive track_plays table on existing installs and displays totals, top and least-played tracks.
- New installs create the table during installation. Existing sites must visit admin-analytics.php once after deployment. Do not rerun install.php.
- Track deletion cascades only its analytics row; MP3 and artwork storage is not modified by analytics.

Deploy api.php, index.php, assets/js/app.js, assets/css/app.css, lib/admin_navigation.php, install.php, plus new play-event.php, admin-analytics.php and docs/LIBRARY-ANALYTICS.md. Do not touch storage/config.php, storage/music/, storage/artwork/, or existing records.
