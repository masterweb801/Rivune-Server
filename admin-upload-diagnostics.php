<?php
declare(strict_types=1);
require_once __DIR__.'/lib/upload_diagnostics.php';
require __DIR__.'/lib/bootstrap.php';
if (!isset($pdo)) { header('Location: install.php'); exit; }
require_admin();
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
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><title>Upload diagnostics | RIVUNE Admin</title><link rel="icon" type="image/png" href="public/assets/rivune-logo.png"><link rel="stylesheet" href="assets/css/tokens.css?v=design-tokens-2"><link rel="stylesheet" href="assets/css/app.css?v=app-core-5"><link rel="stylesheet" href="assets/css/admin.css?v=admin-module-2"></head><body><main class="admin-wrap"><?php $adminActivePage='song_upload'; require __DIR__.'/lib/admin_navigation.php'; ?><section class="panel"><p class="eyebrow">SYSTEM DIAGNOSTICS</p><h1>Upload diagnostics</h1><p class="muted">Private server-side checkpoints for the latest upload requests. This helps locate where the hosting server stops responding. Only the most recent 80 entries are shown.</p><p><a class="text-link" href="admin-upload.php">← Back to Upload Songs</a></p><?php if (!$lines): ?><p class="muted">No upload diagnostic entries yet. After deploying the latest dev branch, make one small test upload and reload this page.</p><?php else: ?><pre style="white-space:pre-wrap;overflow-wrap:anywhere;max-height:65vh;overflow:auto;padding:14px;border-radius:10px;background:var(--surface-2,#111);font-size:12px;line-height:1.5"><?= h(implode("\\n", $lines)) ?></pre><?php endif; ?><details style="margin-top:16px"><summary>Diagnostic storage status</summary><p class="muted">Private storage folder writable: <strong><?= $storageWritable ? 'Yes' : 'No' ?></strong></p><p class="muted">PHP temporary folder writable: <strong><?= $tempWritable ? 'Yes' : 'No' ?></strong></p><p class="muted">This status checks whether PHP can write diagnostic files; it does not expose their filesystem paths.</p></details><p class="muted">These entries contain request timing and processing phases, not audio contents or administrator credentials.</p></section></main></body></html>
