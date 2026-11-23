<?php
declare(strict_types=1);
require __DIR__.'/lib/bootstrap.php';
require_once __DIR__.'/lib/mp3_artwork.php';
require_once __DIR__.'/lib/admin_logs.php';
if (!isset($pdo)) { header('Location: install.php'); exit; }
require_admin();

$batchSize=10;
$offset=max(0,(int)($_POST['offset']??0));
$mode=($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['mode']??'')==='optimize')?'optimize':'extract';
$processed=0; $extracted=0; $optimized=0; $withoutCover=0; $skipped=0; $errors=0;
$total=(int)$pdo->query('SELECT COUNT(*) FROM tracks')->fetchColumn();
$gdAvailable=extension_loaded('gd') && function_exists('imagecreatefromstring');
$webpAvailable=function_exists('imagewebp');
if ($_SERVER['REQUEST_METHOD']==='POST') {
  csrf_check();
  $stmt=$pdo->prepare('SELECT id,stored_name FROM tracks ORDER BY id ASC LIMIT '.$batchSize.' OFFSET '.$offset);
  $stmt->execute();
  foreach ($stmt->fetchAll() as $track) {
    $processed++;
    $id=(int)$track['id'];
    $stored=(string)$track['stored_name'];
    if ($id<1 || !preg_match('/\A[a-f0-9]{40}\.mp3\z/i',$stored)) { $skipped++; continue; }
    $coverPath=null; $coverExtension=null;
    foreach (['webp','jpg','png'] as $extension) {
      $candidate=__DIR__.'/storage/artwork/'.$id.'.'.$extension;
      if (is_file($candidate) && is_readable($candidate)) { $coverPath=$candidate; $coverExtension=$extension; break; }
    }
    if ($mode==='optimize') {
      if ($coverPath===null) { $skipped++; continue; }
      $beforeHash=@hash_file('sha256',$coverPath);
      $bytes=@file_get_contents($coverPath);
      if ($bytes===false || $beforeHash===false) { $errors++; continue; }
      if (!rivune_save_embedded_artwork($id,['extension'=>$coverExtension,'data'=>$bytes])) { $errors++; continue; }
      $newPath=null;
      foreach (['webp','jpg','png'] as $extension) {
        $candidate=__DIR__.'/storage/artwork/'.$id.'.'.$extension;
        if (is_file($candidate)) { $newPath=$candidate; break; }
      }
      $afterHash=$newPath!==null?@hash_file('sha256',$newPath):false;
      if ($afterHash!==false && !hash_equals($beforeHash,$afterHash)) $optimized++;
      else $skipped++;
      continue;
    }
    if ($coverPath!==null) { $skipped++; continue; }
    $mp3=__DIR__.'/storage/music/'.$stored;
    if (!is_file($mp3) || !is_readable($mp3)) { $errors++; continue; }
    $artwork=rivune_extract_mp3_artwork($mp3);
    if ($artwork===null) { $withoutCover++; continue; }
    if (rivune_save_embedded_artwork($id,$artwork)) $extracted++;
    else $errors++;
  }
  rivune_admin_log($pdo,'artwork',$errors?'warning':'success',($mode==='optimize'?'Artwork optimization batch: ':'Embedded artwork scan batch: ').$processed.' checked, '.$extracted.' extracted, '.$optimized.' optimized, '.$skipped.' skipped, '.$errors.' errors.');
  $offset+=$processed;
}
$done=$offset >= $total || ($_SERVER['REQUEST_METHOD']==='POST' && $processed===0);
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><title>Extract embedded artwork | RIVUNE Admin</title><link rel="stylesheet" href="assets/css/app.css?v=responsive-controls-1"></head>
<body><main class="admin-wrap"><?php $adminActivePage='artwork'; require __DIR__.'/lib/admin_navigation.php'; ?>
<section class="panel"><p class="eyebrow">MUSIC LIBRARY</p><h1><?= $mode==='optimize'?'Optimize existing covers':'Extract embedded covers' ?></h1>
<p class="muted">Extract missing covers from MP3 ID3 tags, or optimize covers already stored in your library. Optimization preserves the same image while resizing large artwork and compressing it to WebP when server support is available. Manual covers are preserved as images; their content is not replaced with MP3 metadata.</p>
<p class="muted">Image processing: <?= $gdAvailable ? ($webpAvailable?'GD and WebP encoding available.':'GD available; JPEG/PNG compression is available, WebP encoding is not.') : 'GD is unavailable; existing validated images will be retained without re-encoding.' ?></p>
<div class="admin-metric-grid"><article class="admin-metric"><span>Total tracks</span><strong><?= $total ?></strong></article><article class="admin-metric"><span>Records processed in this request</span><strong><?= $processed ?></strong></article><article class="admin-metric"><span><?= $mode==='optimize'?'Covers optimized':'New covers extracted' ?></span><strong><?= $mode==='optimize'?$optimized:$extracted ?></strong></article><article class="admin-metric"><span><?= $mode==='optimize'?'Skipped / unchanged':'Tracks without a cover' ?></span><strong><?= $mode==='optimize'?$skipped:$withoutCover ?></strong></article></div>
<?php if ($_SERVER['REQUEST_METHOD']==='POST'): ?><p class="success">Batch complete: <?= $mode==='optimize'?$optimized.' cover(s) optimized':$extracted.' cover(s) extracted' ?>, <?= $skipped ?> skipped, <?= $errors ?> error(s).</p><?php endif; ?>
<?php if ($done): ?><p class="success">The existing-library scan has reached the end. Tracks without embedded covers will continue to use the fallback artwork.</p><?php else: ?><p class="muted">Progress: <?= min($offset,$total) ?> of <?= $total ?> records visited. This tool processes <?= $batchSize ?> tracks per request to avoid hosting timeouts.</p><form method="post"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="offset" value="<?= $offset ?>"><input type="hidden" name="mode" value="<?= h($mode) ?>"><button class="primary" type="submit"><?= $_SERVER['REQUEST_METHOD']==='POST'?'Continue':'Start' ?> <?= $mode==='optimize'?'optimization':'scan' ?> (<?= $batchSize ?> tracks per batch)</button></form><?php endif; ?>
<div class="artwork-actions"><form method="post"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="offset" value="0"><input type="hidden" name="mode" value="extract"><button class="secondary" type="submit">Extract missing embedded covers</button></form><form method="post"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="offset" value="0"><input type="hidden" name="mode" value="optimize"><button class="secondary" type="submit">Optimize existing covers</button></form></div>
<p class="artwork-actions"><a class="text-link" href="admin-songs.php">← Back to music library</a></p></section></main></body></html>