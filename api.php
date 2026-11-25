<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/maintenance.php';
rivune_maintenance_enforce($pdo, 'json');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=15, stale-while-revalidate=30');
$cacheTtl = 20;
$cacheDir = __DIR__ . '/storage/cache/library';
$cacheKey = hash('sha256', json_encode([
  'q' => substr(trim((string)($_GET['q'] ?? '')), 0, 100),
  'page' => max(1, (int)($_GET['page'] ?? 1)),
  'limit' => max(1, min(100, (int)($_GET['limit'] ?? 50))),
  'sort' => (string)($_GET['sort'] ?? 'popular')
], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
$cacheFile = $cacheDir . '/' . $cacheKey . '.json';
$cachedBody = null;
if (is_dir($cacheDir) || @mkdir($cacheDir, 0755, true)) {
  if (is_file($cacheFile) && (time() - (int)@filemtime($cacheFile)) <= $cacheTtl) {
    $cachedBody = @file_get_contents($cacheFile);
  }
}
if (is_string($cachedBody) && $cachedBody !== '') {
  $etag = '"' . hash('sha256', $cachedBody) . '"';
  header('ETag: ' . $etag);
  if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
  }
  echo $cachedBody;
  exit;
}
try {
  $paginated = isset($_GET['limit']) || isset($_GET['page']) || isset($_GET['q']) || isset($_GET['sort']);
  $limit = max(1, min(100, (int)($_GET['limit'] ?? 50)));
  $page = max(1, (int)($_GET['page'] ?? 1));
  $offset = ($page - 1) * $limit;
  $search = substr(trim((string)($_GET['q'] ?? '')), 0, 100);
  $sort = (string)($_GET['sort'] ?? 'popular');
  if (!in_array($sort, ['popular','alphabetical','newest'], true)) $sort = 'popular';
  $hasPlayStats = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='track_plays'")->fetchColumn() > 0;
  $playCountExpr = $hasPlayStats ? 'COALESCE(tp.play_count,0)' : '0';
  $playJoin = $hasPlayStats ? ' LEFT JOIN track_plays tp ON tp.track_id=t.id' : '';
  // Do not use the literal fallback "0" in ORDER BY: MySQL treats numeric ORDER BY values as column positions, and position 0 is invalid. Without analytics, use a stable ID order.
  $order = $sort === 'newest' ? 't.id DESC' : ($sort === 'alphabetical' ? 't.title COLLATE utf8mb4_unicode_ci ASC, t.id ASC' : ($hasPlayStats ? 'COALESCE(tp.play_count,0) DESC, t.title COLLATE utf8mb4_unicode_ci ASC, t.id DESC' : 't.title COLLATE utf8mb4_unicode_ci ASC, t.id ASC'));
  $where = $search !== '' ? " WHERE t.title LIKE ? OR t.artist LIKE ? OR t.album LIKE ?" : '';
  $countStmt = $pdo->prepare('SELECT COUNT(*) FROM tracks t'.$where);
  if ($search !== '') { $term = '%'.$search.'%'; $countStmt->execute([$term,$term,$term]); } else { $countStmt->execute(); }
  $total = (int)$countStmt->fetchColumn();
  $sql = 'SELECT t.id,t.title,t.artist,t.album,t.original_name,t.created_at,'.$playCountExpr.' AS play_count FROM tracks t'.$playJoin.$where.' ORDER BY '.$order;
  if ($paginated) $sql .= ' LIMIT '.$limit.' OFFSET '.$offset;
  $stmt = $pdo->prepare($sql);
  if ($search !== '') $stmt->execute([$term,$term,$term]); else $stmt->execute();
  $tracks=[]; $rows=$stmt->fetchAll();
  foreach ($rows as $row) {
    $id=(int)$row['id'];
    $row['play_count']=(int)$row['play_count'];
    $row['stream_url']='stream.php?id='.$id;
    $artworkDir=__DIR__.'/storage/artwork';
    $artworkFile=null;
    foreach (['jpg','png','webp'] as $extension) {
      $candidate=$artworkDir.'/'.$id.'.'.$extension;
      if (is_file($candidate) && is_readable($candidate)) { $artworkFile=$candidate; break; }
    }
    $row['artwork_url']=$artworkFile ? 'artwork.php?id='.$id.'&v='.(int)filemtime($artworkFile).'-'.(int)filesize($artworkFile) : null;
    $tracks[]=$row;
  }
  $response=['tracks'=>$tracks];
  if ($paginated) $response['pagination']=['page'=>$page,'limit'=>$limit,'total'=>$total,'has_more'=>($offset+count($tracks))<$total];
  $body = json_encode($response, JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
  if (!is_string($body)) throw new RuntimeException('Unable to encode library response.');
  if (isset($cacheDir) && (is_dir($cacheDir) || @mkdir($cacheDir, 0755, true))) {
    $temporary = $cacheFile . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($temporary, $body, LOCK_EX) !== false) @rename($temporary, $cacheFile);
    if (is_file($temporary)) @unlink($temporary);
  }
  $etag = '"' . hash('sha256', $body) . '"';
  header('ETag: ' . $etag);
  echo $body;
} catch (Throwable $e) {
  header('Cache-Control: no-store');
  http_response_code(500);
  echo json_encode(['error'=>'Unable to load the library.']);
}
