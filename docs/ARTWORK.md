# RIVUNE Track Artwork

## What is included

- On every MP3 upload, RIVUNE reads the embedded ID3v2 APIC (and ID3v2.2 PIC) cover frame and extracts a validated JPEG, PNG, or WebP image automatically. No separate artwork upload is needed for tracks that already contain album art.
- Embedded images are limited to 5 MB and 6000 × 6000 pixels / 24 megapixels. Unsupported, malformed, oversized, or missing covers are safely ignored; the player shows its fallback.
- The admin artwork upload remains optional and acts as a manual override. A newly uploaded MP3 with an embedded cover replaces the existing cover for that track; an MP3 without a cover does not erase an existing manual cover.

- Admin-only artwork management at `admin-artwork.php?id=TRACK_ID`.
- Public artwork delivery through `artwork.php?id=TRACK_ID`; the raw artwork directory remains under protected `storage/`.
- JPEG, PNG, and WebP uploads up to 5 MB, with image signature/MIME and dimension checks.
- One artwork image per track. Replacing an image safely swaps the file; removing it returns the player to its built-in music-note fallback.
- Public library API returns an optional `artwork_url` alongside each track's existing `stream_url`.
- The website renders artwork in library rows, the mini-player, and the expanded player.
- The Android bridge's existing track objects now also carry `artwork_url`. The URL is relative in the same way as `stream_url`; the Android app should resolve it against the configured RIVUNE site base URL when adding notification/lock-screen artwork support.

## Storage layout

Artwork files are stored as `storage/artwork/{track-id}.{jpg|png|webp}`. No track rows, MP3 files, or existing metadata are rewritten. No database schema change or installer rerun is required.

## Deploy to an existing InfinityFree site

Upload/replace these files from the `dev` branch:

- `api.php`
- `index.php`
- `admin.php`
- `assets/js/app.js`
- `assets/css/app.css`
- `admin-artwork.php` (new)
- `artwork.php` (new)

The first artwork upload creates `storage/artwork/` automatically. If your host does not permit PHP to create it, create that folder manually under `storage/` and set permissions consistent with the existing `storage/music/` folder.

Do not overwrite or delete `storage/config.php`, `storage/music/`, existing artwork/assets, or the MySQL database. Do not rerun `install.php`.

## Manual test

1. Upload an MP3 that has embedded album art in its ID3 tag.
2. Confirm the uploaded track receives artwork automatically in the library, mini-player, and expanded player.
3. Upload an MP3 with no embedded cover and confirm the fallback appears.
4. Optionally open the track's Artwork admin page to apply a manual override.
5. Confirm playback, seek, next/previous, and the Android bridge still receive the same track/queue fields plus the optional artwork URL.

Source implementation only; live InfinityFree and Android-device tests must still be performed after deployment.
