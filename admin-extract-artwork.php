<?php
declare(strict_types=1);
require __DIR__.'/lib/bootstrap.php';
require_once __DIR__.'/lib/mp3_artwork.php';
require_once __DIR__.'/lib/admin_logs.php';
if (!isset($pdo)) { header('Location: install.php'); exit; }
require_admin();

$batchSize=10;
$offset=max(0,(int)($_POST['offset']??0));
$processed=0; $extracted=0; $withoutCover=0; $skipped=0; $errors=0;
$total=(int)$pdo->query('SELECT COUNT(*) FROM tracks')->fetchColumn();
if ($_SERVER['REQUEST_METHOD']==='POST') {
  csrf_check();
  $stmt=$pdo->prepare('SELECT id,stored_name FROM tracks ORDER BY id ASC LIMIT '.$batchSize.' OFFSET '.$offset);
  $stmt->execute();
  foreach ($stmt->fetchAll() as $track) {
    $processed++;
    $id=(int)$track['id'];
    $stored=(string)$track['stored_name'];
    if ($id<1 || !preg_match('/\A[a-f0-9]{40}\.mp3\z/i',$stored)) { $skipped++; continue; }
    $existing=false;
    foreach (['jpg','png','webp'] as $extension) {
      if (is_file(__DIR__.'/storage/artwork/'.$id.'.'.$extension)) { $existing=true; break; }
    }
    if ($existing) { $skipped++; continue; }
    $mp3=__DIR__.'/storage/music/'.$stored;
    if (!is_file($mp3) || !is_readable($mp3)) { $errors++; continue; }
    $artwork=rivune_extract_mp3_artwork($mp3);
    if ($artwork===null) { $withoutCover++; continue; }
    if (rivune_save_embedded_artwork($id,$artwork)) $extracted++;
    else $errors++;
  }
  rivune_admin_log($pdo,'artwork',$errors?'warning':'success','Embedded artwork scan batch: '.$processed.' checked, '.$extracted.' extracted, '.$skipped.' skipped, '.$errors.' errors.');
  $offset+=$processed;
}
$done=$offset >= $total || $processed===0;
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Extract embedded artwork | RIVUNE Admin</title><link rel="stylesheet" href="assets/css/app.css"></head>
<body><main class="admin-wrap"><?php $adminActivePage='overview'; require __DIR__.'/lib/admin_navigation.php'; ?>
<section class="panel"><p class="eyebrow">MUSIC LIBRARY</p><h1>Extract embedded covers</h1>
<p class="muted">Scan MP3 files already in your library and extract their embedded ID3 album art. Existing covers, including manual overrides, are left untouched.</p>
<div class="admin-metric-grid"><article class="admin-metric"><span>Total tracks</span><strong><?= $total ?></strong></article><article class="admin-metric"><span>Records processed in this request</span><strong><?= $processed ?></strong></article><article class="admin-metric"><span>New covers extracted</span><strong><?= $extracted ?></strong></article><article class="admin-metric"><span>Tracks without a cover</span><strong><?= $withoutCover ?></strong></article></div>
<?php if ($_SERVER['REQUEST_METHOD']==='POST'): ?><p class="success">Batch complete: <?= $extracted ?> cover(s) extracted, <?= $skipped ?> skipped, <?= $errors ?> error(s).</p><?php endif; ?>
<?php if ($done): ?><p class="success">The existing-library scan has reached the end. Tracks without embedded covers will continue to use the fallback artwork.</p><?php else: ?><p class="muted">Progress: <?= min($offset,$total) ?> of <?= $total ?> records visited. This tool processes <?= $batchSize ?> tracks per request to avoid hosting timeouts.</p><form method="post"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="offset" value="<?= $offset ?>"><button class="primary" type="submit">Continue scan (next <?= $batchSize ?>)</button></form><?php endif; ?>
<p class="artwork-actions"><a class="text-link" href="admin.php#song-deletion">← Back to music library</a></p></section></main></body></html>