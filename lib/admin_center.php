<?php
declare(strict_types=1);

/**
 * Lightweight, request-time admin metrics for shared PHP hosting.
 * These measurements cover only paths the PHP process can inspect.
 */
function rivune_admin_dir_size(string $directory, int $maxFiles = 8000): array {
  $result = ['bytes'=>0, 'files'=>0, 'truncated'=>false, 'largest'=>[]];
  if (!is_dir($directory) || !is_readable($directory)) return $result;
  try {
    $iterator = new RecursiveIteratorIterator(
      new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
      RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($iterator as $file) {
      if (!$file->isFile() || $file->isLink()) continue;
      $size = $file->getSize();
      if ($size === false) continue;
      $result['bytes'] += $size;
      $result['files']++;
      $result['largest'][] = ['name'=>$file->getFilename(), 'path'=>$file->getPathname(), 'bytes'=>$size];
      if ($result['files'] >= $maxFiles) { $result['truncated'] = true; break; }
    }
  } catch (Throwable $e) {
    $result['error'] = true;
  }
  usort($result['largest'], static fn($a,$b) => $b['bytes'] <=> $a['bytes']);
  $result['largest'] = array_slice($result['largest'], 0, 5);
  return $result;
}

function rivune_admin_format_bytes(int $bytes): string {
  if ($bytes <= 0) return '0 B';
  $units = ['B','KB','MB','GB','TB'];
  $power = min((int)floor(log($bytes, 1024)), count($units)-1);
  return number_format($bytes / (1024 ** $power), $power === 0 ? 0 : 2) . ' ' . $units[$power];
}

function rivune_admin_storage(PDO $pdo): array {
  // InfinityFree does not expose account quota to PHP. Use the owner's stated
  // 4.5 GB allowance as a reference, not as a live hosting-panel measurement.
  $quotaReference = 4.5 * 1024 * 1024 * 1024;
  if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['rivune_storage_cache'], $_SESSION['rivune_storage_cache_at']) && (int)($_SESSION['rivune_storage_cache']['quota_reference_bytes'] ?? 0) === (int)$quotaReference && time() - (int)$_SESSION['rivune_storage_cache_at'] < 900) {
    return $_SESSION['rivune_storage_cache'];
  }
  $root = dirname(__DIR__);
  $music = rivune_admin_dir_size($root . '/storage/music');
  $artwork = rivune_admin_dir_size($root . '/public/assets');
  $assets = rivune_admin_dir_size($root . '/assets');
  $dbBytes = null;
  try {
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(data_length + index_length),0) FROM information_schema.tables WHERE table_schema = DATABASE()');
    $stmt->execute();
    $dbBytes = (int)$stmt->fetchColumn();
  } catch (Throwable $e) {
    // Shared hosts may deny information_schema access; report unavailable, never guess.
  }
  $measured = $music['bytes'] + $artwork['bytes'] + $assets['bytes'] + ($dbBytes ?? 0);
  $remaining = max(0, (int)$quotaReference - $measured);
  $usagePercent = ($measured / $quotaReference) * 100;
  $result = [
    'music'=>$music, 'artwork'=>$artwork, 'assets'=>$assets,
    'database'=>$dbBytes, 'measured'=>$measured,
    'hosting_quota'=>(int)$quotaReference, 'hosting_used'=>$measured,
    'hosting_remaining'=>$remaining, 'hosting_percent'=>$usagePercent,
    'quota_reference_bytes'=>(int)$quotaReference,
    'quota_is_live'=>false,
    'partial'=>$music['truncated'] || $artwork['truncated'] || $assets['truncated']
  ];
  if (session_status() === PHP_SESSION_ACTIVE) {
    $_SESSION['rivune_storage_cache'] = $result;
    $_SESSION['rivune_storage_cache_at'] = time();
  }
  return $result;
}

function rivune_admin_diagnostics(PDO $pdo): array {
  if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['rivune_health_cache'], $_SESSION['rivune_health_cache_at']) && time() - (int)$_SESSION['rivune_health_cache_at'] < 900) return $_SESSION['rivune_health_cache'];
  $checks = [];
  $checks[] = ['label'=>'Database connection', 'ok'=>true, 'detail'=>'Connected and query execution available.'];
  try {
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM);
    $names = array_map(static fn($row)=>(string)$row[0], $tables);
    $checks[] = ['label'=>'Tracks table', 'ok'=>in_array('tracks',$names,true), 'detail'=>in_array('tracks',$names,true)?'Required table is present.':'Required tracks table was not found.'];
    $throttleReady = in_array('admin_login_throttle', $names, true);
    $checks[] = ['label'=>'Persistent admin login throttle', 'ok'=>$throttleReady, 'detail'=>$throttleReady?'Database-backed login throttling is available.':'Throttle table is not present; verify database CREATE/INSERT/UPDATE permissions. Until it is available, login protection falls back to the current PHP session.'];
  } catch (Throwable $e) {
    $checks[] = ['label'=>'Database tables', 'ok'=>false, 'detail'=>'Could not inspect required tables.'];
  }
  foreach ([
    ['PHP version', PHP_VERSION, version_compare(PHP_VERSION,'7.4.0','>=')],
    ['PDO MySQL extension', extension_loaded('pdo_mysql')?'Available':'Unavailable', extension_loaded('pdo_mysql')],
    ['Fileinfo extension', extension_loaded('fileinfo')?'Available':'Unavailable', extension_loaded('fileinfo')]
  ] as $item) $checks[] = ['label'=>$item[0], 'ok'=>$item[2], 'detail'=>$item[1]];
  $root = dirname(__DIR__);
  foreach ([
    ['Music directory', $root.'/storage/music', true],
    ['Artwork directory', $root.'/public/assets', false],
    ['Frontend assets', $root.'/assets', false],
    ['Storage directory', $root.'/storage', true]
  ] as $item) {
    $exists = is_dir($item[1]);
    $readable = $exists && is_readable($item[1]);
    $writable = $exists && is_writable($item[1]);
    $ok = $exists && $readable && (!$item[2] || $writable);
    $detail = !$exists ? 'Directory is missing.' : ($item[2] ? ($writable ? 'Exists, readable and writable.' : 'Exists but is not writable.') : ($readable ? 'Exists and readable.' : 'Not readable.'));
    $checks[] = ['label'=>$item[0], 'ok'=>$ok, 'detail'=>$detail];
  }
  $okCount = count(array_filter($checks, static fn($check)=>$check['ok']));
  $result = ['checks'=>$checks, 'passed'=>$okCount, 'total'=>count($checks), 'healthy'=>$okCount===count($checks)];
  if (session_status() === PHP_SESSION_ACTIVE) { $_SESSION['rivune_health_cache']=$result; $_SESSION['rivune_health_cache_at']=time(); }
  return $result;
}
