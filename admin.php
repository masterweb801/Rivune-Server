<?php
declare(strict_types=1);
require_once __DIR__.'/lib/upload_diagnostics.php';
$earlyAjaxUpload = (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') && ((string)($_GET['ajax_upload'] ?? '') === '1' || (string)($_POST['ajax_upload'] ?? '') === '1');
$earlyUploadRequestId = '';
if ($earlyAjaxUpload) {
  try { $earlyUploadRequestId = bin2hex(random_bytes(6)); } catch (Throwable $e) { $earlyUploadRequestId = substr(sha1(uniqid('', true)), 0, 12); }
  rivune_upload_diag($earlyUploadRequestId, 'script_entered', ['content_length'=>(int)($_SERVER['CONTENT_LENGTH'] ?? 0), 'max_input_time'=>ini_get('max_input_time'), 'max_execution_time'=>ini_get('max_execution_time'), 'memory_limit'=>ini_get('memory_limit')]);
}
require __DIR__.'/lib/bootstrap.php';
require_once __DIR__.'/lib/upload_limits.php';
require_once __DIR__.'/lib/admin_center.php';
require_once __DIR__.'/lib/admin_logs.php';
require_once __DIR__.'/lib/admin_alerts.php';
require_once __DIR__.'/lib/admin_auth.php';
require_once __DIR__.'/lib/track_processing.php';
header('Cache-Control: no-store, private, max-age=0');
header('Pragma: no-cache');
if (!isset($pdo)) { header('Location: install.php'); exit; }

// Hash column is provisioned during installation; legacy hashes are not backfilled on page startup.

// Detect real MPEG audio frames instead of relying on the hosting server's MIME label.
function rivune_is_mp3(string $path): bool {
  $fp = @fopen($path, 'rb');
  if (!$fp) return false;

  // Skip the complete ID3v2 tag before scanning for MPEG frames. Reading only
  // the first megabyte can reject valid MP3s with large embedded cover art.
  $header = fread($fp, 10);
  if ($header === false) { fclose($fp); return false; }
  if (strlen($header) >= 3 && substr($header, 0, 3) === 'ID3') {
    if (strlen($header) !== 10 || !in_array(ord($header[3]), [2,3,4], true)) {
      fclose($fp);
      return false;
    }
    $tagSize = 0;
    for ($i = 6; $i < 10; $i++) {
      $byte = ord($header[$i]);
      if (($byte & 0x80) !== 0) { fclose($fp); return false; }
      $tagSize = ($tagSize << 7) | $byte;
    }
    if (fseek($fp, 10 + $tagSize, SEEK_SET) !== 0) { fclose($fp); return false; }
  } else {
    rewind($fp);
  }

  $data = fread($fp, 1048576);
  fclose($fp);
  if ($data === false || strlen($data) < 4) return false;
  $length = strlen($data);
  for ($i = 0; $i <= $length - 4; $i++) {
    $b1 = ord($data[$i]);
    $b2 = ord($data[$i + 1]);
    $b3 = ord($data[$i + 2]);
    if ($b1 !== 0xFF || ($b2 & 0xE0) !== 0xE0) continue;
    $version = ($b2 >> 3) & 0x03;
    $layer = ($b2 >> 1) & 0x03;
    $bitrate = ($b3 >> 4) & 0x0F;
    $sampleRate = ($b3 >> 2) & 0x03;
    if ($version !== 0x01 && $layer !== 0 && $bitrate !== 0 && $bitrate !== 0x0F && $sampleRate !== 0x03) return true;
  }
  return false;
}
$error=''; $notice=(string)($_SESSION['rivune_admin_notice']??''); unset($_SESSION['rivune_admin_notice']); $saved=0; $duplicates=[]; $failed=[]; $search=trim((string)($_GET['q']??''));
$page=max(1,(int)($_GET['page']??1)); $perPage=25;
$uploadLimits = rivune_upload_limits();
$ajaxUploadRequest = ($_SERVER['REQUEST_METHOD'] === 'POST') && ((string)($_GET['ajax_upload'] ?? '') === '1' || (string)($_POST['ajax_upload'] ?? '') === '1');
if ($ajaxUploadRequest) {
  // Keep fatal-error output inside the JSON response instead of allowing the
  // web server to close the connection with an empty/partial response.
  if (!headers_sent()) { @ini_set('display_errors', '0'); }
  ob_start();
  $uploadRequestId = $earlyUploadRequestId !== '' ? $earlyUploadRequestId : bin2hex(random_bytes(6));
  $uploadStartedAt = microtime(true);
  rivune_upload_diag($uploadRequestId, 'bootstrap_and_includes_complete', ['upload_max_filesize'=>ini_get('upload_max_filesize'), 'post_max_size'=>ini_get('post_max_size'), 'max_input_time'=>ini_get('max_input_time')]);
  @set_time_limit(180);
  set_exception_handler(static function (Throwable $exception) use ($uploadRequestId, $uploadStartedAt): void {
    rivune_upload_diag($uploadRequestId, 'uncaught_exception', ['elapsed_seconds'=>round(microtime(true)-$uploadStartedAt,2), 'class'=>get_class($exception), 'message'=>$exception->getMessage(), 'line'=>$exception->getLine()]);
    while (ob_get_level() > 0) { @ob_end_clean(); }
    if (!headers_sent()) {
      http_response_code(500);
      header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['ok'=>false,'saved'=>0,'duplicates'=>0,'notice'=>'','error'=>'The server could not finish processing this upload. Reference: '.$uploadRequestId.'. Please retry with one smaller batch.'], JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
  });
  register_shutdown_function(static function () use ($uploadRequestId, $uploadStartedAt): void {
    $lastError = error_get_last();
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!$lastError || !in_array($lastError['type'], $fatalTypes, true)) return;
    rivune_upload_diag($uploadRequestId, 'fatal_error', ['elapsed_seconds'=>round(microtime(true)-$uploadStartedAt,2), 'message'=>$lastError['message'], 'line'=>$lastError['line']]);
    while (ob_get_level() > 0) { @ob_end_clean(); }
    if (!headers_sent()) {
      http_response_code(500);
      header('Content-Type: application/json; charset=utf-8');
      header('Connection: close');
      echo json_encode(['ok'=>false,'saved'=>0,'duplicates'=>0,'notice'=>'','error'=>'The server encountered an internal error while processing this upload. Reference: '.$uploadRequestId.'.']);
    }
  });
}
// PHP empties $_POST and $_FILES when post_max_size is exceeded. Detect this
// before CSRF parsing so the AJAX client receives a useful JSON response.
if ($ajaxUploadRequest) {
  require_admin();
  rivune_upload_diag($uploadRequestId, 'ajax_authentication_passed');
  $requestBytes = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
  $postLimitBytes = rivune_ini_size_bytes(ini_get('post_max_size'));
  if ($postLimitBytes > 0 && $requestBytes > $postLimitBytes) {
    while (ob_get_level() > 0) { @ob_end_clean(); }
    http_response_code(413);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'saved'=>0,'duplicates'=>0,'notice'=>'','error'=>'The upload batch exceeds this server’s PHP request-size limit. Select fewer or smaller files.']);
    exit;
  }
  // Safe diagnostic mode: receive the same mp3[] field at admin.php, but never
  // move, hash, insert, or otherwise save the uploaded file.
  if ((string)($_GET['upload_probe'] ?? '') === '1') {
    if (isset($_POST['csrf'])) csrf_check();
    $probeBatch = $_FILES['mp3'] ?? null;
    $probeNames = is_array($probeBatch['name'] ?? null) ? $probeBatch['name'] : [];
    $probeSizes = is_array($probeBatch['size'] ?? null) ? $probeBatch['size'] : [];
    $probeErrors = is_array($probeBatch['error'] ?? null) ? $probeBatch['error'] : [];
    $probeFiles = [];
    foreach ($probeNames as $i => $probeName) {
      $probeTmp = (string)($probeBatch['tmp_name'][$i] ?? '');
      $probeFiles[] = [
        'name' => basename((string)$probeName),
        'bytes' => (int)($probeSizes[$i] ?? 0),
        'upload_error' => (int)($probeErrors[$i] ?? UPLOAD_ERR_NO_FILE),
        'is_uploaded_file' => $probeTmp !== '' && is_uploaded_file($probeTmp),
      ];
    }
    rivune_upload_diag($uploadRequestId, 'admin_endpoint_probe_complete', [
      'file_count' => count($probeFiles),
      'total_file_bytes' => array_sum(array_map(static fn($f) => $f['bytes'], $probeFiles)),
      'post_fields' => count($_POST),
    ]);
    while (ob_get_level() > 0) { @ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
      'ok' => count($probeFiles) > 0 && !array_filter($probeFiles, static fn($f) => !$f['is_uploaded_file'] || $f['upload_error'] !== UPLOAD_ERR_OK),
      'received' => count($probeFiles) > 0,
      'content_length' => $requestBytes,
      'files' => $probeFiles,
      'post_fields_received' => count($_POST),
      'message' => 'Diagnostic only: no file was saved and no song/database record was created.',
      'request_id' => $uploadRequestId,
    ], JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
  }
}
if ($_SERVER['REQUEST_METHOD']==='POST') {
  csrf_check(); $action=$_POST['action']??'';
  if ($action==='login') {
    $loginUsername = isset($_POST['username']) && is_string($_POST['username']) ? trim($_POST['username']) : '';
    $loginPassword = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
    $passwordHash = (string)($config['admin_pass_hash'] ?? '');
    $throttle = rivune_admin_login_is_limited($pdo, $loginUsername, $passwordHash);
    if ($throttle['limited']) {
      $error = 'Too many sign-in attempts. Please wait before trying again.';
    } elseif (hash_equals((string)$config['admin_user'], $loginUsername) && password_verify($loginPassword, $passwordHash)) {
      rivune_admin_login_clear($pdo, $loginUsername, $passwordHash);
      rivune_admin_log($pdo, 'login', 'success', 'Administrator signed in.');
      session_regenerate_id(true);
      $_SESSION['admin_id'] = 1;
      $_SESSION['admin_authenticated_at'] = time();
      $_SESSION['admin_last_activity'] = time();
      $_SESSION['admin_session_version'] = max(1, (int)($config['admin_session_version'] ?? 1));
      $_SESSION['csrf'] = bin2hex(random_bytes(32));
      header('Location: admin.php');
      exit;
    } else {
      rivune_admin_login_record_failure($pdo, $loginUsername, $passwordHash);
      rivune_admin_log($pdo, 'login', 'failure', 'An administrator sign-in attempt failed.');
      $error = 'Incorrect username or password.';
    }
  } elseif ($action==='logout') { require_admin(); rivune_admin_log($pdo, 'logout', 'success', 'Administrator signed out.'); $_SESSION=[]; session_regenerate_id(true); header('Location: admin.php'); exit; }
  elseif ($action==='upload') {
    require_admin();
    rivune_upload_diag($uploadRequestId, 'csrf_and_admin_checks_passed');
    // Authentication and CSRF checks are complete. Release PHP's per-session
    // lock before hashing files, moving uploads, and waiting on MySQL so other
    // admin requests cannot queue behind this potentially slow request.
    if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
    rivune_upload_diag($uploadRequestId, 'session_released');
    rivune_processing_ensure_schema($pdo);
    rivune_upload_diag($uploadRequestId, 'processing_queue_schema_ready');
    $batch=$_FILES['mp3']??null;
    rivune_upload_diag($uploadRequestId, 'files_parsed', ['file_count'=>is_array($batch['name'] ?? null) ? count($batch['name']) : 0, 'total_file_bytes'=>is_array($batch['size'] ?? null) ? array_sum(array_map('intval', $batch['size'])) : 0]);
    if (!$batch || !isset($batch['name']) || !is_array($batch['name'])) {
      $error='Choose one or more MP3 files.';
    } else {
      $sharedArtist=trim($_POST['artist']??'') ?: 'Unknown artist';
      $sharedAlbum=trim($_POST['album']??'') ?: 'Unknown album';
      $dir=__DIR__.'/storage/music';
      if (!is_dir($dir) && !mkdir($dir,0750,true) && !is_dir($dir)) {
        $error='Could not create storage/music. Check folder permissions.';
      } else {
        $saved=0; $failed=[]; $duplicates=[];
        $total=count($batch['name']);
        $totalBytes=array_sum(array_map('intval', $batch['size'] ?? []));
        $batchTooLarge=$totalBytes>$uploadLimits['max_batch_bytes'];
        if ($batchTooLarge) $failed[]='Selected batch exceeds the current server-safe total of '.rivune_format_upload_mb($uploadLimits['max_batch_bytes']).' MB';
        for ($i=0;$i<$total && !$batchTooLarge;$i++) {
          $name=(string)$batch['name'][$i];
          $tmp=(string)$batch['tmp_name'][$i];
          $code=(int)$batch['error'][$i];
          if ($code!==UPLOAD_ERR_OK) {
            if ($code===UPLOAD_ERR_INI_SIZE || $code===UPLOAD_ERR_FORM_SIZE) $reason='file exceeds the server upload limit';
            elseif ($code===UPLOAD_ERR_PARTIAL) $reason='upload was interrupted';
            elseif ($code===UPLOAD_ERR_NO_FILE) $reason='no file selected';
            else $reason='upload error '.$code;
            $failed[]=$name.': '.$reason; continue;
          }
          if (!is_uploaded_file($tmp) || (int)$batch['size'][$i]<1 || (int)$batch['size'][$i]>$uploadLimits['max_file_bytes']) {
            $failed[]=$name.': file must be between 1 byte and '.rivune_format_upload_mb($uploadLimits['max_file_bytes']).' MB'; continue;
          }
          error_log('[RIVUNE upload '.$uploadRequestId.'] file '.($i+1).'/'.$total.' received: '.basename($name).' ('.(int)$batch['size'][$i].' bytes)');
          $ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));
          if ($ext!=='mp3' || !rivune_is_mp3($tmp)) {
            $failed[]=$name.': file does not contain a valid MP3 audio frame'; continue;
          }
          // Artwork and metadata are intentionally deferred to the background queue.
          rivune_upload_diag($uploadRequestId, 'mp3_validation_passed', ['file_index'=>$i+1, 'file_bytes'=>(int)$batch['size'][$i]]);
          $contentHash=hash_file('sha256',$tmp);
          if ($contentHash===false) {
            $failed[]=$name.': could not calculate file hash'; continue;
          }
          // Serialize identical hashes across concurrent requests. The lock is
          // held until the duplicate check and database insert have completed.
          // Fail closed if MySQL named locks are unavailable: accepting the file
          // without a lock would reintroduce the duplicate-upload race.
          rivune_upload_diag($uploadRequestId, 'sha256_calculated', ['file_index'=>$i+1]);
          $uploadLock = 'rivune_upload_' . substr($contentHash, 0, 50);
          $lockAcquired = false;
          try {
            $lockQuery = $pdo->prepare('SELECT GET_LOCK(?, 5)');
            $lockQuery->execute([$uploadLock]);
            $lockAcquired = (int)$lockQuery->fetchColumn() === 1;
          } catch (Throwable $e) {
            error_log('[RIVUNE upload] Database lock request failed: '.$e->getMessage());
            $lockAcquired = false;
          }
          if (!$lockAcquired) {
            $failed[] = $name . ': could not safely verify duplicates because the database upload lock was unavailable';
            continue;
          }

          try {
            $dupe=$pdo->prepare('SELECT id FROM tracks WHERE file_hash=? LIMIT 1');
            $dupe->execute([$contentHash]);
            if ($dupe->fetch()) {
              $duplicates[]=$name;
              continue;
            }

            error_log('[RIVUNE upload '.$uploadRequestId.'] duplicate check passed: '.basename($name));
            $title=pathinfo($name,PATHINFO_FILENAME);
            $stored=bin2hex(random_bytes(20)).'.mp3';
            if (!move_uploaded_file($tmp,$dir.'/'.$stored)) {
              $failed[]=$name.': could not save file';
              continue;
            }
            try {
              $pdo->beginTransaction();
              $q=$pdo->prepare('INSERT INTO tracks(title,artist,album,original_name,stored_name,file_hash) VALUES(?,?,?,?,?,?)');
              $q->execute([substr($title,0,190),substr($sharedArtist,0,190),substr($sharedAlbum,0,190),basename($name),$stored,$contentHash]);
              $trackId=(int)$pdo->lastInsertId();
              $queueInsert=$pdo->prepare("INSERT INTO track_processing_jobs(track_id,status) VALUES(?,'pending')");
              $queueInsert->execute([$trackId]);
              $pdo->commit();
              $saved++;
              rivune_upload_diag($uploadRequestId, 'database_save_completed', ['file_index'=>$i+1, 'track_id'=>$trackId]);
            } catch (Throwable $e) {
              error_log('[RIVUNE upload] Database insert failed: '.$e->getMessage());
              if ($pdo->inTransaction()) $pdo->rollBack();
              @unlink($dir.'/'.$stored);
              $failed[]=$name.': database or processing-queue insert failed';
            }
          } catch (Throwable $e) {
            error_log('[RIVUNE upload] Duplicate verification failed: '.$e->getMessage());
            $failed[] = $name . ': duplicate verification failed; file was not accepted';
          } finally {
            try {
              $releaseLock = $pdo->prepare('SELECT RELEASE_LOCK(?)');
              $releaseLock->execute([$uploadLock]);
            } catch (Throwable $e) {
              // The connection closing also releases any remaining named lock.
            }
          }
        }
        if ($saved>0) { $notice=$saved.' track'.($saved===1?'':'s').' uploaded successfully.'; $uploadSound=true; }
        if ($duplicates) $notice.=($notice?' ':'').count($duplicates).' duplicate file'.(count($duplicates)===1?' was':'s were').' skipped (identical audio already exists).';
        if ($failed) $error=count($failed).' file'.(count($failed)===1?'':'s').' could not be uploaded: '.implode(' | ',array_slice($failed,0,5)).(count($failed)>5?' | and '.(count($failed)-5).' more':'');
        if ($saved===0 && !$failed && !$duplicates) $error='No files were uploaded.';
        if ($saved===0 && !$failed && $duplicates) $notice='All selected files were duplicates and were skipped.';
      }
    }
    if ($saved > 0 || $duplicates || $failed) {
      $uploadStatus = $failed ? (($saved > 0 || $duplicates) ? 'warning' : 'failure') : 'success';
      rivune_admin_log($pdo, 'upload', $uploadStatus, 'Upload batch: '.$saved.' saved, '.count($duplicates).' duplicate(s) skipped, '.count($failed).' failed.');
      if ($failed) rivune_alert_raise($pdo, 'upload_failure', 'warning', 'One or more uploads failed', count($failed).' file(s) failed in the latest upload batch. Review the upload result and activity log.');
      if ($duplicates) rivune_alert_raise($pdo, 'upload_duplicate', 'info', 'Duplicate music was skipped', count($duplicates).' identical file(s) were skipped by SHA-256 duplicate detection.');
    }
    if ($ajaxUploadRequest) {
      while (ob_get_level() > 0) { @ob_end_clean(); }
      header('Content-Type: application/json; charset=utf-8');
      rivune_upload_diag($uploadRequestId, 'json_response_ready', ['elapsed_seconds'=>round(microtime(true)-$uploadStartedAt,2), 'saved'=>$saved, 'duplicates'=>count($duplicates), 'failed'=>count($failed)]);
      echo json_encode([
        'ok' => $saved > 0 || (!empty($duplicates) && empty($failed)),
        'saved' => $saved ?? 0,
        'duplicates' => count($duplicates ?? []),
        'notice' => $notice,
        'error' => $error
      ], JSON_INVALID_UTF8_SUBSTITUTE);
      exit;
    }
  } elseif ($action==='refresh_storage') {
    require_admin();
    unset($_SESSION['rivune_storage_cache'], $_SESSION['rivune_storage_cache_at']);
    $snapshot=rivune_admin_storage($pdo);
    rivune_alert_check_storage($pdo,$snapshot,$config);
    $_SESSION['rivune_admin_notice']='Storage measurements refreshed.';
    header('Location: admin.php'); exit;
  } elseif ($action==='refresh_health') {
    require_admin();
    unset($_SESSION['rivune_health_cache'], $_SESSION['rivune_health_cache_at']);
    $health=rivune_admin_diagnostics($pdo);
    foreach ($health['checks'] as $diagnostic) if (!$diagnostic['ok']) rivune_alert_raise($pdo,'system_'.substr(sha1($diagnostic['label']),0,12),'critical','System check failed: '.$diagnostic['label'],$diagnostic['detail']);
    $_SESSION['rivune_admin_notice']='System diagnostics completed.';
    header('Location: admin.php'); exit;
  } elseif ($action==='delete') {
    require_admin(); $id=filter_var($_POST['id']??null,FILTER_VALIDATE_INT);
    if ($id) {
      $q=$pdo->prepare('SELECT stored_name FROM tracks WHERE id=?');
      $q->execute([$id]);
      $track=$q->fetch();
      if ($track) {
        $path=rivune_music_storage_path((string)$track['stored_name']);
        if ($path === null) {
          $error='This track has an invalid stored filename. Its record and files were left untouched; run the integrity scanner.';
        } else {
          $pdo->prepare('DELETE FROM tracks WHERE id=?')->execute([$id]);
          if (is_file($path) && !@unlink($path)) {
            rivune_admin_log($pdo, 'delete', 'warning', 'Deleted track record #'.(int)$id.' but its validated MP3 file could not be removed.');
          }
          foreach (['jpg','png','webp'] as $artExt) {
            $artPath=__DIR__.'/storage/artwork/'.(int)$id.'.'.$artExt;
            if (is_file($artPath) && !is_link($artPath)) @unlink($artPath);
          }
          rivune_admin_log($pdo, 'delete', 'success', 'Deleted track record #'.(int)$id.'.');
          $notice='Track deleted.';
        }
      }
    }
  }
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['return_to'] ?? '') === 'admin-songs.php') {
  $_SESSION['rivune_admin_notice'] = $notice !== '' ? $notice : 'Song management updated.';
  $returnQuery = isset($_POST['return_q']) && is_string($_POST['return_q']) ? trim($_POST['return_q']) : '';
  $returnPage = max(1, (int)($_POST['return_page'] ?? 1));
  header('Location: admin-songs.php?' . http_build_query(['q'=>$returnQuery,'page'=>$returnPage]));
  exit;
}
if (is_admin()) require_admin();

$where = $search !== '' ? " WHERE title LIKE ? OR artist LIKE ? OR album LIKE ?" : '';
$countTracks = $pdo->prepare('SELECT COUNT(*) FROM tracks'.$where);
if ($search !== '') { $term='%'.$search.'%'; $countTracks->execute([$term,$term,$term]); } else { $countTracks->execute(); }
$totalTracks=(int)$countTracks->fetchColumn();
$totalPages=max(1,(int)ceil($totalTracks/$perPage));
$page=min($page,$totalPages);
$listQuery=$pdo->prepare('SELECT id,title,artist,album FROM tracks'.$where.' ORDER BY id DESC LIMIT '.$perPage.' OFFSET '.(($page-1)*$perPage));
if ($search !== '') $listQuery->execute([$term,$term,$term]); else $listQuery->execute();
$tracks=$listQuery->fetchAll();
?><!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><title>RIVUNE | Admin</title><link rel="icon" type="image/png" href="public/assets/rivune-logo.png"><link rel="stylesheet" href="assets/css/tokens.css?v=design-tokens-2">
<link rel="stylesheet" href="assets/css/app.css?v=app-core-5">
<link rel="stylesheet" href="assets/css/controls.css?v=controls-1">
<link rel="stylesheet" href="assets/css/forms.css?v=forms-1">
<link rel="stylesheet" href="assets/css/admin.css?v=admin-module-2"><body><main class="admin-wrap"><?php $adminActivePage = 'overview'; require __DIR__ . '/lib/admin_navigation.php'; ?><?php if(isset($_GET['installed'])): ?><p class="success">Installation complete. Sign in to upload your first tracks.</p><?php endif; ?><?php if($error): ?><p class="error"><?= h($error) ?></p><?php endif; ?><?php if($notice): ?><p class="success"><?= h($notice) ?></p><?php endif; ?>
<?php if(!is_admin()): ?><section class="panel auth-panel"><p class="eyebrow">RESTRICTED AREA</p><h1>Administrator login</h1><form method="post" class="form"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="login"><label>Username<input name="username" required autocomplete="username"></label><label>Password<input type="password" name="password" required autocomplete="current-password"></label><button class="primary" type="submit">Sign in</button><a class="text-link" href="admin-forgot-password.php">Forgot password?</a></form></section>
<?php else:
$adminStorage = $_SESSION['rivune_storage_cache'] ?? null;
$adminDiagnostics = $_SESSION['rivune_health_cache'] ?? null;
?>
<section class="admin-content"><section class="admin-overview">
<div class="admin-storage-panel"><h2>System tools</h2><p class="muted">Cached results are shown immediately. Checks run only when requested.</p>
<div class="admin-tool-actions">
<form method="post"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="refresh_storage"><button class="secondary">Refresh storage</button></form>
<form method="post"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="refresh_health"><button class="secondary">Run system diagnostics</button></form>
</div><a class="text-link" href="admin-alerts.php">View alerts →</a></div>
<div class="section-head"><div><p class="eyebrow">CONTROL CENTER</p><h1>System overview</h1></div><span class="muted"><?= $adminStorage && !empty($_SESSION['rivune_storage_cache_at']) ? 'Cached · ' . h(date('M j, Y · g:i A', (int)$_SESSION['rivune_storage_cache_at'])) : 'No saved storage snapshot' ?></span></div>
<?php if ($adminStorage): ?>
<div class="admin-metric-grid"><article class="admin-metric"><span>RIVUNE measured storage</span><strong><?= h(rivune_admin_format_bytes((int)$adminStorage['measured'])) ?></strong></article><article class="admin-metric"><span>Music library</span><strong><?= h(rivune_admin_format_bytes((int)$adminStorage['music']['bytes'])) ?></strong></article><article class="admin-metric"><span>Database size</span><strong><?= $adminStorage['database'] === null ? 'Unavailable' : h(rivune_admin_format_bytes((int)$adminStorage['database'])) ?></strong></article><article class="admin-metric"><span>Estimated remaining</span><strong><?= h(rivune_admin_format_bytes((int)($adminStorage['hosting_remaining'] ?? 0))) ?></strong><small><?= number_format((float)($adminStorage['hosting_percent'] ?? 0), 1) ?>% of 4.5 GB reference used</small></article></div>
<?php else: ?><div class="admin-storage-panel"><p class="muted">No saved storage snapshot. Use Refresh storage when needed.</p></div><?php endif; ?><p class="muted" style="font-size:12px">Usage is an estimate based on files and database size RIVUNE can measure. InfinityFree does not expose live account quota to PHP; 4.5 GB is your supplied reference, not a live hosting measurement. Other account files may not be included.</p>
<?php if ($adminDiagnostics): ?><div class="admin-storage-panel"><h2>System health · Cached</h2><p class="muted"><?= (int)$adminDiagnostics['passed'] ?> / <?= (int)$adminDiagnostics['total'] ?> checks passed</p><div class="admin-check-grid"><?php foreach ($adminDiagnostics['checks'] as $check): ?><div class="admin-check"><strong><?= h($check['label']) ?></strong><small><?= h($check['detail']) ?></small></div><?php endforeach; ?></div></div><?php else: ?><div class="admin-storage-panel"><p class="muted">No saved diagnostics. Run checks when needed.</p></div><?php endif; ?>
</section>
<section class="admin-storage-panel"><p class="eyebrow">SONG MANAGEMENT</p><h2>Music library tools</h2><p class="muted">Upload new music, manage existing tracks, or maintain album artwork from the Songs section in the navigation.</p><div class="admin-tool-actions"><a class="text-link" href="admin-upload.php">Upload songs →</a><a class="text-link" href="admin-songs.php">Manage songs →</a><a class="text-link" href="admin-extract-artwork.php">Artwork maintenance →</a></div></section></section><?php endif; ?></main>

</body></html>
