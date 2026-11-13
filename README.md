# RIVUNE Server

**Music Without Boundaries.**

RIVUNE Server is the Node.js side of the RIVUNE local-network music
streaming system. It serves the web player and music library to devices
on the same Wi-Fi/LAN, provides HTTP API endpoints, synchronizes
playback through WebSockets, and advertises itself to the Android app
through UDP discovery.

## Features

-   Local HTTP server and browser-based music player.
-   Music-library scanning and track information.
-   Audio streaming to connected clients.
-   WebSocket-based playback and client synchronization.
-   UDP broadcast discovery for the RIVUNE Android app.
-   Health endpoint for client-side server verification.

## Requirements

-   Node.js with support for the built-in `node:` modules used by the
    project.
-   npm.
-   A folder containing the music files you want to make available at `/musics`
- server device and client devices connected to the same local
    network.

## Installation and startup

1.  Extract the server project.

2.  Open a terminal in the project directory.

3.  Install dependencies:

    ``` bash
    npm install
    ```

4.  Start the server using the start script defined in `package.json`:

    ``` bash
    npm start
    ```

If the project does not define an `npm start` script, check
`package.json` for the configured entry point and run that entry point
with Node.js. Keep the terminal session running while you use RIVUNE.

> The exact startup script and music-directory configuration depend on
> the `package.json` and configuration files included in your server
> project. Use those project settings rather than assuming an
> environment-variable name.

## Connecting from the Android app

1.  Start RIVUNE Server.
2.  Connect the Android device to the same Wi-Fi network as the server.
3.  Open the RIVUNE app and run server discovery.
4.  Select the server when it appears. The app checks the server's
    `/api/health` endpoint before opening its web player.

If discovery does not work, check that:

-   Both devices are on the same LAN/Wi-Fi network.
-   The router does not enable client/AP isolation between wireless
    devices.
-   The server device's firewall allows inbound TCP traffic on port
    `8080` and UDP traffic on port `39876`.
-   The server process is still running.
-   The network is not a guest network that blocks device-to-device
    communication.

## Project layout

The project is organized into focused modules. The following are key
areas:

``` text
src/
  core/
    app.js
  server/
    discoveryServer.js
    httpServer.js
  library/
    libraryService.js
public/
  index.html
  js/
    app.js
    network/
      websocket.js
    player/
      playbackController.js
```

-   `src/core/app.js` coordinates application startup.
-   `src/server/discoveryServer.js` handles UDP discovery requests.
-   `src/server/httpServer.js` handles HTTP requests and serves the web
    application/API.
-   `src/library/libraryService.js` manages the music library.
-   `public/` contains the browser-based player interface and its
    client-side modules.

## Health check

With the server running on its default port, open:

``` text
http://localhost:8080/api/health
```

A healthy server returns JSON containing the service identifier, status,
and track count.

## Troubleshooting

**The Android app cannot find the server**

-   Confirm both devices are on the same Wi-Fi/LAN.
-   Check router AP/client isolation and firewall rules.
-   Watch the server terminal for UDP discovery request logs.
-   Retry discovery after the server has fully started.

**The app finds the server but cannot connect**

-   Check that the HTTP server is listening on the advertised port.
-   Open `http://SERVER_IP:8080/api/health` from a browser on the
    Android device, replacing `SERVER_IP` with the server's LAN IP.
-   Check firewall rules for TCP port `8080`.

**The web player opens but tracks do not appear**

-   Confirm the configured music folder contains supported audio files.
-   Check server terminal output for file access or scanning errors.
-   Reload the web player after the library has finished loading.

**Playback synchronization or streaming problems**

-   Keep the server running and avoid switching networks during
    playback.
-   Check the browser console and server terminal for WebSocket or
    streaming errors.
-   Ensure the network is stable and devices can communicate directly.

## Security and privacy notes

RIVUNE is designed for use on a trusted local network. Do not expose the
server directly to the public internet unless you have independently
added and reviewed appropriate authentication, transport security, and
access controls. Anyone who can reach an unprotected server may be able
to access the music service it provides.

## Development notes

Keep the discovery protocol and service identifier compatible with the
Android client unless both projects are updated together:

-   UDP request: `MOBS_JUKEBOX_DISCOVER_V1`
-   Service identifier: `mobs-jukebox`
-   Default HTTP port: `8080`
-   Default UDP port: `39876`

When modifying playback, WebSocket, or library modules, test the
complete flow with at least one Android client and one browser client.



## Music manager and playback controls

The browser player supports adding audio files directly to the server library. Use **Add music** in the library section to upload supported audio files (MP3, M4A, AAC, OGG, OPUS, FLAC, WAV, AIFF, and WMA). Individual uploads are limited to 100 MB. Tracks can be removed with the delete control beside each library item; deleting a track removes the underlying file from the configured music directory.

The player also includes shuffle playback, previous/next navigation, seeking, volume control, and an expanded full-screen listening view. The playback queue remains local to each browser session.

### Music manager API

- `GET /api/library` — list library tracks.
- `POST /api/tracks/upload?filename=track.mp3` — upload one audio file as the raw request body.
- `DELETE /api/tracks/:id` — permanently delete a library track.
- `GET /api/tracks/:id/stream` — stream a track.

The upload and delete endpoints are intended for a trusted private network. This project does not yet include user accounts or authentication; do not expose an unprotected instance to the public internet.

## Hosting compatibility

The full RIVUNE server requires a persistent Node.js process, WebSockets, and UDP discovery for the Android app. InfinityFree's free shared hosting does not support Node.js server processes or WebSocket hosting, and its platform does not provide UDP ports for this use case. The current server therefore cannot be deployed there as-is.

For the complete experience, use a Node.js-capable host or a VPS. An InfinityFree-compatible edition would require a separate PHP-based HTTP/API backend and an alternative to the current WebSocket and UDP discovery features; that should be treated as a separate deployment target so the existing LAN server and Android compatibility are preserved.
