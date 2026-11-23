# RIVUNE Library Pagination & Song Analytics

- Public site fetches 50 tracks per page and automatically loads more near the bottom, with skeleton placeholders and a manual fallback.
- Search is debounced and server-paginated. Artwork retains lazy loading.
- Shuffle and Random fetch the full library on demand to remain global.
- Android receives the legacy unpaginated API response when no pagination parameters are supplied.
- A track counts as played only after at least 30 seconds of plausible forward listening time in the active playback session. Starting a track, shuffle selection, random selection, buffering, and seeking do not count on their own.
- Large position jumps are treated as seeks/stalls and ignored. Each qualifying playback instance has a unique ID, preventing duplicate event submissions. Native playback position updates are observed for Android notification-driven playback.
- Admin → Songs → Song analytics initializes the additive track_plays table on existing installs and displays totals, top and least-played tracks.
- New installs create the table during installation. Existing sites must visit admin-analytics.php once after deployment. Do not rerun install.php.
- Track deletion cascades only its analytics row; MP3 and artwork storage is not modified by analytics.

Deploy api.php, index.php, assets/js/app.js, assets/css/app.css, lib/admin_navigation.php, install.php, plus new play-event.php, admin-analytics.php and docs/LIBRARY-ANALYTICS.md. Do not touch storage/config.php, storage/music/, storage/artwork/, or existing records.
