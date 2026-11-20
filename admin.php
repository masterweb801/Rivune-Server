<?php
declare(strict_types=1);
require __DIR__.'/lib/bootstrap.php';
require_once __DIR__.'/lib/admin_center.php';
require_once __DIR__.'/lib/admin_logs.php';
require_once __DIR__.'/lib/admin_alerts.php';
require_once __DIR__.'/lib/admin_auth.php';
header('Cache-Control: no-store, private, max-age=0');
header('Pragma: no-cache');
if (!isset($pdo)) { header('Location: install.php'); exit; }

// Hash column is provisioned during installation; legacy hashes are not backfilled on page startup.

// Detect real MPEG audio frames instead of relying on the hosting server's MIME label.
function rivune_is_mp3(string $path): bool {
  $fp = @fopen($path, 'rb');
  if (!$fp) return false;
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
  } elseif ($action==='logout') { rivune_admin_log($pdo, 'logout', 'success', 'Administrator signed out.'); $_SESSION=[]; session_regenerate_id(true); header('Location: admin.php'); exit; }
  elseif ($action==='upload') {
    require_admin();
    $batch=$_FILES['mp3']??null;
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
        for ($i=0;$i<$total;$i++) {
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
          if (!is_uploaded_file($tmp) || (int)$batch['size'][$i]<1 || (int)$batch['size'][$i]>25*1024*1024) {
            $failed[]=$name.': file must be between 1 byte and 25 MB'; continue;
          }
          $ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));
          if ($ext!=='mp3' || !rivune_is_mp3($tmp)) {
            $failed[]=$name.': file does not contain a valid MP3 audio frame'; continue;
          }
          $contentHash=hash_file('sha256',$tmp);
          if ($contentHash===false) {
            $failed[]=$name.': could not calculate file hash'; continue;
          }
          // Serialize identical hashes across concurrent requests. The lock is
          // held until the duplicate check and database insert have completed.
          // Fail closed if MySQL named locks are unavailable: accepting the file
          // without a lock would reintroduce the duplicate-upload race.
          $uploadLock = 'rivune_upload_' . substr($contentHash, 0, 50);
          $lockAcquired = false;
          try {
            $lockQuery = $pdo->prepare('SELECT GET_LOCK(?, 5)');
            $lockQuery->execute([$uploadLock]);
            $lockAcquired = (int)$lockQuery->fetchColumn() === 1;
          } catch (Throwable $e) {
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

            $title=pathinfo($name,PATHINFO_FILENAME);
            $stored=bin2hex(random_bytes(20)).'.mp3';
            if (!move_uploaded_file($tmp,$dir.'/'.$stored)) {
              $failed[]=$name.': could not save file';
              continue;
            }
            try {
              $q=$pdo->prepare('INSERT INTO tracks(title,artist,album,original_name,stored_name,file_hash) VALUES(?,?,?,?,?,?)');
              $q->execute([substr($title,0,190),substr($sharedArtist,0,190),substr($sharedAlbum,0,190),basename($name),$stored,$contentHash]);
              $saved++;
            } catch (Throwable $e) {
              @unlink($dir.'/'.$stored);
              $failed[]=$name.': database insert failed';
            }
          } catch (Throwable $e) {
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
    if (($_POST['ajax_upload']??'')==='1') {
      header('Content-Type: application/json; charset=utf-8');
      $listQuery = $search !== ''
        ? $pdo->prepare("SELECT id,title,artist,album FROM tracks WHERE title LIKE ? OR artist LIKE ? OR album LIKE ? ORDER BY id DESC LIMIT 25 OFFSET ".(($page-1)*25))
        : $pdo->prepare("SELECT id,title,artist,album FROM tracks ORDER BY id DESC LIMIT 25 OFFSET ".(($page-1)*25));
      if ($search !== '') {
        $term = '%'.$search.'%';
        $listQuery->execute([$term,$term,$term]);
      } else {
        $listQuery->execute();
      }
      echo json_encode([
        'ok' => $saved > 0 || (!empty($duplicates) && empty($failed)),
        'saved' => $saved ?? 0,
        'duplicates' => count($duplicates ?? []),
        'notice' => $notice,
        'error' => $error,
        'tracks' => $listQuery->fetchAll()
      ]);
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
    if ($id) { $q=$pdo->prepare('SELECT stored_name FROM tracks WHERE id=?'); $q->execute([$id]); $track=$q->fetch(); if ($track) { $pdo->prepare('DELETE FROM tracks WHERE id=?')->execute([$id]); $path=__DIR__.'/storage/music/'.$track['stored_name']; if (is_file($path)) unlink($path); rivune_admin_log($pdo, 'delete', 'success', 'Deleted track record #'.(int)$id.'.'); $notice='Track deleted.'; } }
  }
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
?><!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>RIVUNE | Admin</title><link rel="icon" type="image/png" href="public/assets/rivune-logo.png"><link rel="stylesheet" href="assets/css/app.css"><body><main class="admin-wrap"><?php $adminActivePage = 'overview'; require __DIR__ . '/lib/admin_navigation.php'; ?><?php if(isset($_GET['installed'])): ?><p class="success">Installation complete. Sign in to upload your first tracks.</p><?php endif; ?><?php if($error): ?><p class="error"><?= h($error) ?></p><?php endif; ?><?php if($notice): ?><p class="success"><?= h($notice) ?></p><?php endif; ?>
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
<div class="section-head"><div><p class="eyebrow">LIBRARY MANAGEMENT</p><h1>Music studio</h1></div></div><section class="panel"><h2>Upload multiple MP3s</h2><p class="muted">Select as many MP3 files as your hosting limits allow. Each file can be up to 25 MB. Titles are taken from filenames; artist and album below apply to every selected track.</p><form method="post" enctype="multipart/form-data" class="form" data-ajax-upload><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="upload"><input type="hidden" name="ajax_upload" value="1"><label>MP3 files<input type="file" name="mp3[]" accept=".mp3,audio/mpeg" multiple required></label><div class="form-grid"><label>Artist for all tracks<input name="artist" placeholder="Optional — Unknown artist"></label><label>Album for all tracks<input name="album" placeholder="Optional — Unknown album"></label></div><button class="primary">Upload selected tracks</button></form></section><section class="panel" id="song-deletion"><div class="section-head"><h2>Song Deletion Centre</h2><span class="muted"><?= (int)$totalTracks ?><?= $search!==''?' matching':'' ?> tracks · Page <?= (int)$page ?> / <?= (int)$totalPages ?></span></div><form method="get" action="admin.php#song-deletion" class="form" style="margin:0 0 18px"><label>Search songs<input type="search" name="q" value="<?= h($search) ?>" placeholder="Search title, artist or album" autocomplete="off"></label><div style="display:flex;gap:10px;flex-wrap:wrap"><button class="secondary" type="submit">Search</button><?php if($search!==''): ?><a class="text-link" href="admin.php#song-deletion">Clear search</a><?php endif; ?></div></form><?php if(!$tracks): ?><p class="muted"><?= $search!==''?'No matching songs found.':'No music uploaded yet.' ?></p><?php else: ?><div class="admin-list"><?php foreach($tracks as $t): ?><div class="admin-track"><div><strong><?= h($t['title']) ?></strong><small><?= h($t['artist']) ?> · <?= h($t['album']) ?></small></div><form method="post" action="admin.php?<?= $search!==''?'q='.rawurlencode($search).'&amp;':'' ?>page=<?= (int)$page ?>#song-deletion" onsubmit="return confirm('Permanently delete this track?')"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><button class="danger">Delete</button></form></div><?php endforeach; ?></div><?php endif; ?><?php if($totalPages>1): ?><nav class="admin-pagination" aria-label="Song list pages"><?php if($page>1): ?><a href="admin.php?<?= $search!==''?'q='.rawurlencode($search).'&amp;':'' ?>page=<?= $page-1 ?>#song-deletion">← Previous</a><?php endif; ?><span>Page <?= $page ?> of <?= $totalPages ?></span><?php if($page<$totalPages): ?><a href="admin.php?<?= $search!==''?'q='.rawurlencode($search).'&amp;':'' ?>page=<?= $page+1 ?>#song-deletion">Next →</a><?php endif; ?></nav><?php endif; ?></section></section><?php endif; ?></main>
<?php if (is_admin()): ?>
<script>
(function () {
  var form = document.querySelector('[data-ajax-upload]');
  if (!form) return;

  var AudioCtx = window.AudioContext || window.webkitAudioContext;
  var audioContext = null;
  var audioUnlockPromise = Promise.resolve();
  var progressWrap = document.createElement('div');
  progressWrap.style.cssText = 'display:none;margin-top:12px;';
  var progress = document.createElement('progress');
  progress.max = 100;
  progress.value = 0;
  progress.style.cssText = 'display:block;width:100%;height:12px;accent-color:var(--accent,#a78bfa);';
  var progressLabel = document.createElement('p');
  progressLabel.className = 'muted';
  progressLabel.style.cssText = 'margin:6px 0 0;font-size:13px;';
  progressLabel.textContent = '0%';
  progressWrap.appendChild(progress);
  progressWrap.appendChild(progressLabel);
  form.appendChild(progressWrap);

  var status = document.createElement('p');
  status.className = 'muted';
  status.setAttribute('role', 'status');
  status.setAttribute('aria-live', 'polite');
  form.appendChild(status);

  // Create/resume audio directly during the user's upload gesture.
  function unlockAudio() {
    if (!AudioCtx) return;
    try {
      if (!audioContext) audioContext = new AudioCtx();
      if (audioContext.state !== 'running') {
        audioUnlockPromise = audioContext.resume().catch(function () {});
      }
    } catch (e) {}
  }

  var uploadButton = form.querySelector('button[type="submit"],button:not([type])');
  if (uploadButton) {
    uploadButton.addEventListener('pointerdown', unlockAudio);
    uploadButton.addEventListener('touchstart', unlockAudio, {passive:true});
  }

  function playUploadChime() {
    if (!audioContext || audioContext.state !== 'running') return;
    try {
      var now = audioContext.currentTime;
      [660, 880, 1100].forEach(function (frequency, index) {
        var oscillator = audioContext.createOscillator();
        var gain = audioContext.createGain();
        var start = now + index * 0.15;
        oscillator.type = 'sine';
        oscillator.frequency.value = frequency;
        gain.gain.setValueAtTime(0.0001, start);
        gain.gain.exponentialRampToValueAtTime(0.14, start + 0.025);
        gain.gain.exponentialRampToValueAtTime(0.0001, start + 0.23);
        oscillator.connect(gain);
        gain.connect(audioContext.destination);
        oscillator.start(start);
        oscillator.stop(start + 0.25);
      });
    } catch (e) {}
  }

  function showMessage(className, message) {
    var existing = document.querySelector('.ajax-upload-message');
    if (existing) existing.remove();
    if (!message) return;
    var node = document.createElement('p');
    node.className = className + ' ajax-upload-message';
    node.textContent = message;
    form.parentNode.insertBefore(node, form);
  }

  function refreshTrackList(tracks) {
    var list = document.querySelector('.admin-list');
    var empty = document.querySelector('.panel .muted');
    if (!list) {
      list = document.createElement('div');
      list.className = 'admin-list';
      var libraryPanel = form.parentNode.nextElementSibling;
      if (!libraryPanel) return;
      var searchForm = libraryPanel.querySelector('form');
      if (searchForm) searchForm.insertAdjacentElement('afterend', list);
      else libraryPanel.appendChild(list);
    }
    list.replaceChildren();
    tracks.forEach(function (track) {
      var item = document.createElement('div');
      item.className = 'admin-track';
      var details = document.createElement('div');
      var title = document.createElement('strong');
      title.textContent = track.title;
      var meta = document.createElement('small');
      meta.textContent = track.artist + ' · ' + track.album;
      details.appendChild(title);
      details.appendChild(meta);
      item.appendChild(details);
      var deleteForm = document.createElement('form');
      deleteForm.method = 'post';
      deleteForm.action = 'admin.php';
      deleteForm.onsubmit = function () { return confirm('Permanently delete this track?'); };
      [['csrf', form.querySelector('[name="csrf"]').value], ['action', 'delete'], ['id', track.id]].forEach(function (pair) {
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = pair[0];
        input.value = pair[1];
        deleteForm.appendChild(input);
      });
      var button = document.createElement('button');
      button.className = 'danger';
      button.textContent = 'Delete';
      deleteForm.appendChild(button);
      item.appendChild(deleteForm);
      list.appendChild(item);
    });
    var count = document.querySelector('.panel .section-head .muted');
    if (count) count.textContent = tracks.length + (new URLSearchParams(window.location.search).has('q') ? ' matching' : '') + ' tracks';
    if (!tracks.length) {
      list.textContent = 'No music uploaded yet.';
    }
  }

  form.addEventListener('submit', async function (event) {
    event.preventDefault();
    unlockAudio();
    var fileInput = form.querySelector('input[type="file"]');
    if (!fileInput || !fileInput.files.length) return;
    var button = uploadButton;
    if (button) {
      button.disabled = true;
      button.textContent = 'Uploading…';
    }
    status.textContent = 'Uploading ' + fileInput.files.length + ' file(s)…';
    showMessage('', '');
    progressWrap.style.display = 'block';
    progress.value = 0;
    progressLabel.textContent = '0%';
    status.textContent = 'Starting upload…';
    try {
      var result = await new Promise(function (resolve, reject) {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', window.location.href, true);
        xhr.withCredentials = true;
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.upload.addEventListener('progress', function (event) {
          if (!event.lengthComputable) {
            progressLabel.textContent = 'Uploading…';
            status.textContent = 'Uploading your files…';
            return;
          }
          var percent = Math.min(100, Math.round((event.loaded / event.total) * 100));
          progress.value = percent;
          progressLabel.textContent = percent + '%';
          status.textContent = percent >= 100 ? 'Upload sent. Saving tracks…' : 'Uploading your files…';
        });
        xhr.addEventListener('load', function () {
          if (xhr.status < 200 || xhr.status >= 300) {
            reject(new Error('HTTP ' + xhr.status));
            return;
          }
          try {
            resolve(JSON.parse(xhr.responseText));
          } catch (e) {
            reject(e);
          }
        });
        xhr.addEventListener('error', function () { reject(new Error('Network error')); });
        xhr.addEventListener('abort', function () { reject(new Error('Upload cancelled')); });
        xhr.send(new FormData(form));
      });
      progress.value = 100;
      progressLabel.textContent = '100%';
      showMessage(result.error ? 'error' : 'success', [result.notice, result.error].filter(Boolean).join(' '));
      if (result.saved > 0) {
        await audioUnlockPromise;
        playUploadChime();
      }
      if (result.tracks) refreshTrackList(result.tracks);
      if (result.ok) form.reset();
      status.textContent = result.saved > 0 ? 'Upload complete.' : 'Upload request finished.';
    } catch (error) {
      showMessage('error', 'Upload could not be completed. Check your connection and hosting upload limits, then try again.');
      status.textContent = 'Upload failed.';
    } finally {
      if (button) {
        button.disabled = false;
        button.textContent = 'Upload selected tracks';
      }
    }
  });
})();
</script>
<?php endif; ?>
</body></html>
