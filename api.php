<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/maintenance.php';
rivune_maintenance_enforce($pdo, 'json');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
try {
  $stmt=$pdo->query('SELECT id,title,artist,album,original_name,created_at FROM tracks ORDER BY title COLLATE utf8mb4_unicode_ci, id DESC');
  $tracks=[];
  foreach ($stmt as $row) {
    $id=(int)$row['id'];
    $row['stream_url']='stream.php?id='.$id;
    $artworkDir=__DIR__.'/storage/artwork';
    $artworkFile=null;
    foreach (['jpg','png','webp'] as $extension) {
      $candidate=$artworkDir.'/'.$id.'.'.$extension;
      if (is_file($candidate) && is_readable($candidate)) { $artworkFile=$candidate; break; }
    }
    $row['artwork_url']=$artworkFile ? 'artwork.php?id='.$id.'&v='.(int)filemtime($artworkFile) : null;
    $tracks[]=$row;
  }
  echo json_encode(['tracks'=>$tracks], JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) { http_response_code(500); echo json_encode(['error'=>'Unable to load the library.']); }
