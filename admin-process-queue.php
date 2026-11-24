<?php
declare(strict_types=1);
require __DIR__.'/lib/bootstrap.php';
require_once __DIR__.'/lib/track_processing.php';
require_once __DIR__.'/lib/mp3_artwork.php';
if (!isset($pdo)) { http_response_code(503); exit; }
require_admin();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok'=>false,'error'=>'POST required.']);
  exit;
}
csrf_check();
try {
  rivune_processing_ensure_schema($pdo);
  // Recover jobs left behind by a terminated worker after a safe lease period.
  $pdo->exec("UPDATE track_processing_jobs SET status='pending' WHERE status='processing' AND updated_at < (NOW() - INTERVAL 10 MINUTE)");
  $job = $pdo->query("SELECT track_id FROM track_processing_jobs WHERE status='pending' ORDER BY created_at, track_id LIMIT 1")->fetch();
  $processed = 0;
  if ($job) {
    $trackId = (int)$job['track_id'];
    $claim = $pdo->prepare("UPDATE track_processing_jobs SET status='processing', attempts=attempts+1, last_error=NULL WHERE track_id=? AND status='pending'");
    $claim->execute([$trackId]);
    if ($claim->rowCount() === 1) {
      $trackStmt = $pdo->prepare('SELECT id,stored_name,artist,album FROM tracks WHERE id=?');
      $trackStmt->execute([$trackId]);
      $track = $trackStmt->fetch();
      try {
        if (!$track) {
          $pdo->prepare('DELETE FROM track_processing_jobs WHERE track_id=?')->execute([$trackId]);
        } else {
          $path = __DIR__.'/storage/music/'.(string)$track['stored_name'];
          if (!preg_match('/\A[a-f0-9]{40}\.mp3\z/i', (string)$track['stored_name']) || !is_file($path) || !is_readable($path)) {
            throw new RuntimeException('The stored MP3 file is missing or invalid.');
          }
          $metadata = rivune_extract_mp3_metadata($path);
          if ($metadata) {
            $update = $pdo->prepare("UPDATE tracks SET
              title=CASE WHEN ?<>'' THEN ? ELSE title END,
              artist=CASE WHEN artist='Unknown artist' AND ?<>'' THEN ? ELSE artist END,
              album=CASE WHEN album='Unknown album' AND ?<>'' THEN ? ELSE album END
              WHERE id=?");
            $title=(string)($metadata['title']??'');
            $artist=(string)($metadata['artist']??'');
            $album=(string)($metadata['album']??'');
            $update->execute([$title,$title,$artist,$artist,$album,$album,$trackId]);
          }
          // A manually uploaded cover always wins over an embedded cover.
          $hasArtwork=false;
          foreach (['webp','jpg','png'] as $extension) {
            if (is_file(__DIR__.'/storage/artwork/'.$trackId.'.'.$extension)) { $hasArtwork=true; break; }
          }
          if (!$hasArtwork) {
            $artwork = rivune_extract_mp3_artwork($path);
            if ($artwork !== null && !rivune_save_embedded_artwork($trackId, $artwork)) {
              error_log('[RIVUNE queue] Artwork could not be saved for track #'.$trackId.'.');
            }
          }
          $pdo->prepare("UPDATE track_processing_jobs SET status='completed',last_error=NULL WHERE track_id=?")->execute([$trackId]);
          $processed = 1;
        }
      } catch (Throwable $e) {
        error_log('[RIVUNE queue] Track #'.$trackId.' processing failed: '.$e->getMessage());
        $pdo->prepare("UPDATE track_processing_jobs SET status='failed',last_error=? WHERE track_id=?")->execute([substr($e->getMessage(),0,255),$trackId]);
        $processed = 1;
      }
    }
  }
  $remaining = (int)$pdo->query("SELECT COUNT(*) FROM track_processing_jobs WHERE status IN ('pending','processing')")->fetchColumn();
  $failed = (int)$pdo->query("SELECT COUNT(*) FROM track_processing_jobs WHERE status='failed'")->fetchColumn();
  echo json_encode(['ok'=>true,'processed'=>$processed,'remaining'=>$remaining,'failed'=>$failed]);
} catch (Throwable $e) {
  error_log('[RIVUNE queue] Worker request failed: '.$e->getMessage());
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'The background processing queue is temporarily unavailable.']);
}
