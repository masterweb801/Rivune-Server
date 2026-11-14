# RIVUNE Android Playback Bridge — Contract v1

Status: website-side bridge is implemented on the `dev` branch. Native Media3 playback is not implemented by this repository; the Android application must implement the receiving side.

## 1. Architecture

- Browser visitors continue using the existing HTML audio player.
- The Android app injects a JavaScript interface named `RivuneNative` into its trusted WebView.
- The interface exposes one method: `postMessage(String message)`, annotated with Android's `@JavascriptInterface`.
- After the page has loaded and the native Media3 service is ready to accept commands, Android calls `window.RivunePlayback.setNativeMode(true)`.
- In native mode, website controls send commands to Android. The HTML audio element is paused and cleared so two players do not play simultaneously.
- Android sends state updates back through `window.RivunePlayback.onNativeStateChanged(state)`.

The website does not activate native mode just because it is opened in a browser. If the bridge object is absent, the ordinary web player remains in control.

## 2. JavaScript API exposed by the website

The page exposes `window.RivunePlayback`:

| Method | Purpose |
|---|---|
| `getState()` | Returns the current library, queue and playback snapshot |
| `setNativeMode(true)` | Enables native ownership; returns false if the Android interface is unavailable |
| `setNativeMode(false)` | Disables native mode and returns control to the web player |
| `onNativeStateChanged(state)` | Applies a native playback update to the website UI |
| `onNativeError(error)` | Logs a native playback error |

Enable native mode only after the native service is initialized. Android can invoke the method using WebView `evaluateJavascript`:

```java
webView.evaluateJavascript(
    "window.RivunePlayback && window.RivunePlayback.setNativeMode(true)",
    null
);
```

## 3. JavaScript-to-Android message envelope

Every message delivered to `RivuneNative.postMessage` is JSON:

```json
{
  "contract": "rivune.playback",
  "version": 1,
  "type": "playTrack",
  "payload": {}
}
```

The Android receiver should ignore unknown message types and reject unsupported contract versions.

### Message types

| Type | Payload | Meaning |
|---|---|---|
| `webReady` | Full state snapshot | Page is ready for native mode |
| `libraryChanged` | `{ tracks: [...] }` | Library loaded or refreshed |
| `queueChanged` | Full state snapshot | Queue/order/current selection changed |
| `playTrack` | `{ track, queueTrackIds, currentIndex }` | Play selected track |
| `play` | `{}` | Resume current native item |
| `pause` | `{}` | Pause native item |
| `next` | `{}` | Advance in native queue |
| `previous` | `{}` | Previous item (website requests seek-to-zero first when position exceeds 3 seconds) |
| `seek` | `{ positionMs }` | Seek to absolute position |
| `setVolume` | `{ volume }` | Set volume from 0.0 to 1.0 |
| `shuffleChanged` | `{ enabled }` | Shuffle mode changed |

### Track object

The current `api.php` response provides these fields:

- `id`: stable database track ID (integer)
- `title`, `artist`, `album`
- `original_name`, `created_at`
- `stream_url`: relative URL such as `stream.php?id=42`

Resolve `stream_url` against the current trusted WebView page URL before passing it to Media3. The endpoint supports HTTP byte ranges. The current library does not provide per-track artwork URLs; use a neutral RIVUNE placeholder until artwork support is added.

A `playTrack` message includes the full selected track object, ordered `queueTrackIds`, and `currentIndex`. The full state snapshot includes the entire track list as well as queue IDs.

## 4. Android-to-JavaScript state updates

Android should call `onNativeStateChanged` whenever the current item, playback state, position, duration, queue, shuffle mode or volume changes. Pass a JSON object (or a JSON string) with this shape:

```json
{
  "trackId": 42,
  "queueTrackIds": [42, 19, 8],
  "currentIndex": 0,
  "isPlaying": true,
  "positionMs": 12500,
  "durationMs": 213000,
  "shuffleEnabled": false,
  "volume": 0.8
}
```

Fields:

- `trackId`: current track ID, or null when nothing is selected
- `queueTrackIds`: ordered queue IDs
- `currentIndex`: current index in that queue
- `isPlaying`: boolean
- `positionMs`, `durationMs`: milliseconds
- `shuffleEnabled`: boolean
- `volume`: 0.0–1.0

Example Android callback:

```java
String stateJson = /* serialize the state object with JSONObject/Gson */;
webView.post(() -> webView.evaluateJavascript(
    "window.RivunePlayback && window.RivunePlayback.onNativeStateChanged(" + stateJson + ")",
    null
));
```

Serialize the state as valid JSON; do not concatenate untrusted strings into executable JavaScript. For a JSON string instead of an object, safely quote/escape it before passing it to JavaScript.

Position updates around 500–1000 ms while playing are sufficient for the website progress display. Also send immediate updates for track changes, play/pause, seek, queue changes and interruptions.

## 5. Native playback ownership and lifecycle

1. Initialize Media3 and the foreground playback service.
2. Load the WebView page and wait for its JavaScript to finish initializing.
3. Call `setNativeMode(true)`; the page pauses and clears its HTML audio element.
4. Handle website messages in the native app and execute playback through Media3.
5. Send state updates back to `onNativeStateChanged`.
6. Keep native playback alive independently of the WebView lifecycle. Do not destroy or pause the Media3 service merely because the WebView is backgrounded, recreated, or the Activity is stopped.
7. On a deliberate hand-back to web playback, first stop/release native playback as appropriate, then call `setNativeMode(false)`. Do not let both players own audio at once.

## 6. WebView security requirements

- Add the JavaScript interface only to the trusted RIVUNE origin.
- Do not expose it while loading arbitrary external pages; remove it before navigating away from the trusted origin.
- Keep cleartext traffic disabled if the production site uses HTTPS.
- Validate incoming message envelope, version, type, track IDs and URLs on Android.
- Resolve only same-origin stream URLs from the trusted RIVUNE host. Do not accept arbitrary URLs from JavaScript messages.
- Use `WebView.post` / the UI thread for WebView callbacks as required by Android.

## 7. Current limitations

- This contract does not itself create the Android Media3 service, notification, lock-screen controls, Bluetooth handling, audio focus, or headset event handling. Those remain Android responsibilities.
- The website bridge is optional and browser fallback remains intact.
- The current public API has metadata but no artwork endpoint and no server-persisted user queue.
- The bridge currently synchronizes the in-memory website queue. If the page is recreated, Android should retain its native queue and send its state back after the page reconnects.
