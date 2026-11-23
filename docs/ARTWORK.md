# RIVUNE Track Artwork

## What is included

- On every MP3 upload, RIVUNE reads the embedded ID3v2 APIC (and ID3v2.2 PIC) cover frame and extracts a validated JPEG, PNG, or WebP image automatically. It prefers the ID3 front-cover frame. No separate artwork upload is needed for tracks that already contain album art.
- The admin library includes a batched scan at `admin-extract-artwork.php` to extract covers from MP3s uploaded before this feature. It processes 10 tracks per request and preserves any artwork already present, including manual overrides.
- Embedded images are limited to 5 MB and 6000 × 6000 pixels / 24 megapixels. When PHP GD is available, images are resized to a maximum 1200 px edge and encoded as WebP (quality 80) when that produces a smaller file; JPEG/PNG encoding is used as a fallback. If image processing is unavailable, the original validated image is retained.
- The admin artwork maintenance page can optimize existing artwork in batches of 10 tracks, without replacing its image content with MP3 metadata.
- The admin artwork upload remains optional and acts as a manual override. A newly uploaded MP3 with an embedded cover replaces the existing cover for that track; an MP3 without a cover does not erase an existing manual cover.

- Admin-only artwork management at `admin-artwork.php?id=TRACK_ID`.
- Public artwork delivery through `artwork.php?id=TRACK_ID`; the raw artwork directory remains under protected `storage/`.
- JPEG, PNG, and WebP uploads up to 5 MB, with image signature/MIME and dimension checks.
- One artwork image per track. Replacing an image safely swaps the file; removing it returns the player to its built-in music-note fallback.
- Public library API returns an optional `artwork_url` alongside each track's existing `stream_url`.
- The website renders artwork in library rows, the mini-player, and the expanded player. Library thumbnails use native lazy loading and asynchronous decoding; current-player artwork is loaded eagerly at high priority.
- Versioned artwork URLs are cached for one year. The version includes modification time and file size so a changed cover receives a new URL.
- Playback UI state updates no longer rebuild the entire library DOM. Shuffle uses a linear-time Fisher–Yates shuffle and updates only the active-row state; the current audio source is not changed when shuffle is toggled. The browser's media playback pipeline remains responsible for audio decoding/output independently of page JavaScript.
- The Android bridge's existing track objects now also carry `artwork_url`. The URL is relative in the same way as `stream_url`; the Android app should resolve it against the configured RIVUNE site base URL when adding notification/lock-screen artwork support.

## Storage layout

Artwork files are stored as `storage/artwork/{track-id}.{jpg|png|webp}`. No track rows, MP3 files, or existing metadata are rewritten. No database schema change or installer rerun is required.

## Deploy to an existing InfinityFree site

Upload/replace these files from the `dev` branch:

- `api.php`
- `index.php`
- `admin.php`
- `assets/js/app.js`
- `assets/css/app.css` (if not already deployed with the previous admin layout fix)
- `assets/css/app.css`
- `admin-artwork.php` (new)
- `admin-extract-artwork.php` (new)
- `lib/mp3_artwork.php` (new)
- `lib/admin_logs.php` (replace; records artwork operations)
- `artwork.php` (new)

The first artwork upload creates `storage/artwork/` automatically. If your host does not permit PHP to create it, create that folder manually under `storage/` and set permissions consistent with the existing `storage/music/` folder.

Do not overwrite or delete `storage/config.php`, `storage/music/`, existing artwork/assets, or the MySQL database. Do not rerun `install.php`.

## Manual test

1. Upload an MP3 that has embedded album art in its ID3 tag.
2. Confirm the uploaded track receives artwork automatically in the library, mini-player, and expanded player.
3. For older uploads, open Admin → Song Deletion Centre → Extract embedded covers from existing songs and choose **Extract missing embedded covers**; continue batches until complete.
4. Choose **Optimize existing covers** on the same page to resize/compress artwork already stored. This is also processed in batches of 10.
5. Upload an MP3 with no embedded cover and confirm the fallback appears.
6. Optionally open the track's Artwork admin page to apply a manual override.
7. While a track is playing, toggle shuffle on and off repeatedly. Confirm playback continues without a source reload or pause, and check that the current track remains selected.
8. Confirm playback, seek, next/previous, and the Android bridge still receive the same track/queue fields plus the optional artwork URL.

Source implementation only; live InfinityFree and Android-device tests must still be performed after deployment.
