<?php
declare(strict_types=1);
require_once __DIR__.'/lib/upload_diagnostics.php';
require __DIR__.'/lib/bootstrap.php';
if (!isset($pdo)) { header('Location: install.php'); exit; }
require_admin();
// Record page access to verify that the diagnostics logger can write.
rivune_upload_diag('diagnostics', 'diagnostics_page_open', ['method'=>$_SERVER['REQUEST_METHOD'] ?? 'GET']);
header('Cache-Control: no-store, private, max-age=0');
header('Pragma: no-cache');

$lines = [];
$storagePath = rivune_upload_diag_paths()[0];
$tempPath = rivune_upload_diag_paths()[1] ?? '';
$storageWritable = is_dir(dirname($storagePath)) && is_writable(dirname($storagePath));
$tempWritable = $tempPath !== '' && is_dir(dirname($tempPath)) && is_writable(dirname($tempPath));
foreach (rivune_upload_diag_paths() as $path) {
  if (is_file($path) && is_readable($path)) {
    $entries = @file($path, FILE_IGNORE_NEW_LINES);
    if (is_array($entries)) $lines = array_merge($lines, $entries);
  }
}
$lines = array_slice($lines, -80);
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><title>Upload diagnostics | RIVUNE Admin</title><link rel="icon" type="image/png" href="public/assets/rivune-logo.png"><link rel="stylesheet" href="assets/css/tokens.css?v=design-tokens-2"><link rel="stylesheet" href="assets/css/app.css?v=app-core-5"><link rel="stylesheet" href="assets/css/admin.css?v=admin-module-2"></head><body><main class="admin-wrap"><?php $adminActivePage='song_upload'; require __DIR__.'/lib/admin_navigation.php'; ?><section class="panel"><p class="eyebrow">SYSTEM DIAGNOSTICS</p><h1>Upload diagnostics</h1><p class="muted">Private server-side checkpoints for the latest upload requests. This helps locate where the hosting server stops responding. Only the most recent 80 entries are shown.</p><p><a class="text-link" href="admin-upload.php">← Back to Upload Songs</a></p>
<div class="admin-tool-actions" style="margin:14px 0">
  <button type="button" class="secondary" id="upload-endpoint-test">Test upload endpoint (no file)</button>
</div>
<p id="upload-endpoint-result" class="muted" role="status">The empty-request test checks the existing endpoint without uploading a file.</p>
<div class="admin-tool-actions" style="margin:14px 0;align-items:center">
  <input type="file" id="multipart-probe-file" accept=".mp3,audio/mpeg">
  <button type="button" class="secondary" id="multipart-probe-test">Test PHP file reception</button>
</div>
<p id="multipart-probe-result" class="muted" role="status">Choose one MP3. This test sends it to the real admin.php endpoint in no-save mode; it does not add a song or change the music database.</p>
<div class="admin-tool-actions" style="margin:14px 0">
  <button type="button" class="secondary" id="xhr-probe-test">Test normal XHR transport (no save)</button>
</div>
<p id="xhr-probe-result" class="muted" role="status">This reproduces the Upload Songs page's XMLHttpRequest transport without saving the file.</p>
<script>
(function () {
  const csrf = <?= json_encode(csrf_token()) ?>;
  const emptyButton = document.getElementById('upload-endpoint-test');
  const emptyOutput = document.getElementById('upload-endpoint-result');
  if (emptyButton && emptyOutput) {
    emptyButton.addEventListener('click', async function () {
      emptyButton.disabled = true;
      emptyOutput.textContent = 'Testing the upload endpoint…';
      const data = new FormData();
      data.append('csrf', csrf);
      data.append('action', 'upload');
      data.append('ajax_upload', '1');
      try {
        const response = await fetch('admin.php?ajax_upload=1', {
          method: 'POST',
          credentials: 'same-origin',
          headers: {'X-Requested-With': 'XMLHttpRequest'},
          body: data
        });
        const body = await response.text();
        emptyOutput.textContent = 'HTTP ' + response.status + ' · ' + body.slice(0, 500);
      } catch (error) {
        emptyOutput.textContent = 'Request failed before a response: ' + String(error);
      } finally {
        emptyButton.disabled = false;
      }
    });
  }

  const xhrButton = document.getElementById('xhr-probe-test');
  const xhrOutput = document.getElementById('xhr-probe-result');
  if (xhrButton && xhrOutput) {
    xhrButton.addEventListener('click', function () {
      const file = document.getElementById('multipart-probe-file').files?.[0];
      if (!file) { xhrOutput.textContent = 'Please choose one MP3 file first.'; return; }
      xhrButton.disabled = true;
      xhrOutput.textContent = 'Testing the same XHR transport used by Upload Songs…';
      const data = new FormData();
      data.append('csrf', csrf);
      data.append('action', 'upload');
      data.append('ajax_upload', '1');
      data.append('artist', '');
      data.append('album', '');
      data.append('mp3[]', file, file.name);
      const xhr = new XMLHttpRequest();
      const started = Date.now();
      let loaded = 0, total = 0;
      xhr.open('POST', 'admin.php?ajax_upload=1&upload_probe=1', true);
      xhr.withCredentials = true;
      xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
      xhr.timeout = 180000;
      xhr.upload.addEventListener('progress', e => { if (e.lengthComputable) { loaded=e.loaded; total=e.total; xhrOutput.textContent='Sending test file… '+Math.round(e.loaded/e.total*100)+'%'; } });
      xhr.addEventListener('load', () => {
        xhrOutput.textContent = 'HTTP '+xhr.status+' · '+(xhr.responseText||'').slice(0,700);
        xhrButton.disabled = false;
      });
      xhr.addEventListener('error', () => {
        xhrOutput.textContent = 'XHR network error (HTTP '+xhr.status+', readyState '+xhr.readyState+', sent '+loaded+'/'+total+' bytes, '+Math.round((Date.now()-started)/1000)+' seconds).';
        xhrButton.disabled = false;
      });
      xhr.addEventListener('timeout', () => {
        xhrOutput.textContent = 'XHR timed out after '+Math.round((Date.now()-started)/1000)+' seconds.';
        xhrButton.disabled = false;
      });
      xhr.addEventListener('abort', () => {
        xhrOutput.textContent = 'XHR was aborted.';
        xhrButton.disabled = false;
      });
      xhr.send(data);
    });
  }

  const fileInput = document.getElementById('multipart-probe-file');
  const fileButton = document.getElementById('multipart-probe-test');
  const fileOutput = document.getElementById('multipart-probe-result');
  if (!fileInput || !fileButton || !fileOutput) return;
  fileButton.addEventListener('click', async function () {
    const file = fileInput.files && fileInput.files[0];
    if (!file) {
      fileOutput.textContent = 'Please choose one MP3 file first.';
      return;
    }
    fileButton.disabled = true;
    fileOutput.textContent = 'Sending ' + file.name + ' (' + file.size + ' bytes) to admin.php in no-save mode…';
    const data = new FormData();
    data.append('csrf', csrf);
    data.append('action', 'upload_probe');
    data.append('ajax_upload', '1');
    data.append('mp3[]', file, file.name);
    try {
      const response = await fetch('admin.php?ajax_upload=1&upload_probe=1', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {'X-Requested-With': 'XMLHttpRequest'},
        body: data
      });
      const body = await response.text();
      fileOutput.textContent = 'HTTP ' + response.status + ' · ' + body.slice(0, 700);
    } catch (error) {
      fileOutput.textContent = 'Request failed before a response: ' + String(error);
    } finally {
      fileButton.disabled = false;
    }
  });
})();
</script><?php if (!$lines): ?><p class="muted">No upload diagnostic entries yet. After deploying the latest dev branch, make one small test upload and reload this page.</p><?php else: ?><pre style="white-space:pre-wrap;overflow-wrap:anywhere;max-height:65vh;overflow:auto;padding:14px;border-radius:10px;background:var(--surface-2,#111);font-size:12px;line-height:1.5"><?= h(implode("\\n", $lines)) ?></pre><?php endif; ?><details style="margin-top:16px"><summary>Diagnostic storage status</summary><p class="muted">Private storage folder writable: <strong><?= $storageWritable ? 'Yes' : 'No' ?></strong></p><p class="muted">PHP temporary folder writable: <strong><?= $tempWritable ? 'Yes' : 'No' ?></strong></p><p class="muted">This status checks whether PHP can write diagnostic files; it does not expose their filesystem paths.</p></details><p class="muted">These entries contain request timing and processing phases, not audio contents or administrator credentials.</p></section></main></body></html>
