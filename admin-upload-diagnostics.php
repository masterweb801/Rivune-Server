<?php
declare(strict_types=1);
require __DIR__.'/lib/bootstrap.php';
if (!isset($pdo)) { header('Location: install.php'); exit; }
require_admin();
header('Cache-Control: no-store, private, max-age=0');
header('Pragma: no-cache');
$path = __DIR__ . '/storage/upload-diagnostics.log';
$lines = is_file($path) ? @file($path, FILE_IGNORE_NEW_LINES) : [];
if (!is_array($lines)) $lines = [];
$lines = array_slice($lines, -80);
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><title>Upload diagnostics | RIVUNE Admin"><link rel="icon" type="image/png" href="public/assets/rivune-logo.png"><link rel="stylesheet" href="assets/css/tokens.css?v=design-tokens-2"><link rel="stylesheet" href="assets/css/app.css?v=app-core-5"><link rel="stylesheet" href="assets/css/admin.css?v=admin-module-2"></head><body><main class="admin-wrap"><?php $adminActivePage='song_upload'; require __DIR__.'/lib/admin_navigation.php'; ?><section class="panel"><p class="eyebrow">SYSTEM DIAGNOSTICS</p><h1>Upload diagnostics</h1><p class="muted">Private server-side checkpoints for the latest upload requests. This helps locate where the hosting server stops responding. Only the most recent 80 entries are shown.</p><p><a class="text-link" href="admin-upload.php">← Back to Upload Songs</a></p><?php if (!$lines): ?><p class="muted">No upload diagnostic entries yet. Try one upload, then reload this page.</p><?php else: ?><pre style="white-space:pre-wrap;overflow-wrap:anywhere;max-height:65vh;overflow:auto;padding:14px;border-radius:10px;background:var(--surface-2,#111);font-size:12px;line-height:1.5"><?= h(implode("\n", $lines)) ?></pre><?php endif; ?><p class="muted">These entries contain request timing and processing phases, not audio contents or administrator credentials.</p></section></main></body></html>
