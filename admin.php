<?php
declare(strict_types=1);
require __DIR__.'/lib/bootstrap.php';
if (!isset($pdo)) { header('Location: install.php'); exit; }

// Add content hashes safely to existing installations, then backfill older tracks.
try {
  $columns = $pdo->query("SHOW COLUMNS FROM tracks LIKE 'file_hash'")->fetchAll();
  if (!$columns) {
    $pdo->exec("ALTER TABLE tracks ADD COLUMN file_hash CHAR(64) NULL");
  }
  $legacy = $pdo->query("SELECT id, stored_name FROM tracks WHERE file_hash IS NULL")->fetchAll();
  if ($legacy) {
    $saveHash = $pdo->prepare("UPDATE tracks SET file_hash=? WHERE id=? AND file_hash IS NULL");
    foreach ($legacy as $oldTrack) {
      $oldPath = __DIR__ . '/storage/music/' . basename((string)$oldTrack['stored_name']);
      if (is_file($oldPath)) {
        $oldHash = hash_file('sha256', $oldPath);
        if ($oldHash !== false) $saveHash->execute([$oldHash, (int)$oldTrack['id']]);
      }
    }
  }
} catch (Throwable $e) {
  http_response_code(500);
  exit('RIVUNE could not prepare duplicate detection. Check that the database user has permission to alter the tracks table.');
}

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
$error=''; $notice=''; $saved=0; $duplicates=[]; $failed=[]; $search=trim((string)($_GET['q']??''));
if ($_SERVER['REQUEST_METHOD']==='POST') {
  csrf_check(); $action=$_POST['action']??'';
  if ($action==='login') {
    if (hash_equals($config['admin_user'],trim($_POST['username']??'')) && password_verify((string)($_POST['password']??''),$config['admin_pass_hash'])) {
      session_regenerate_id(true); $_SESSION['admin_id']=1; $_SESSION['csrf']=bin2hex(random_bytes(32)); header('Location: admin.php'); exit;
    } else $error='Incorrect username or password.';
  } elseif ($action==='logout') { $_SESSION=[]; session_regenerate_id(true); header('Location: admin.php'); exit; }
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
          $dupe=$pdo->prepare('SELECT id FROM tracks WHERE file_hash=? LIMIT 1');
          $dupe->execute([$contentHash]);
          if ($dupe->fetch()) {
            $duplicates[]=$name; continue;
          }
          $title=pathinfo($name,PATHINFO_FILENAME);
          $stored=bin2hex(random_bytes(20)).'.mp3';
          if (!move_uploaded_file($tmp,$dir.'/'.$stored)) {
            $failed[]=$name.': could not save file'; continue;
          }
          try {
            $q=$pdo->prepare('INSERT INTO tracks(title,artist,album,original_name,stored_name,file_hash) VALUES(?,?,?,?,?,?)');
            $q->execute([substr($title,0,190),substr($sharedArtist,0,190),substr($sharedAlbum,0,190),basename($name),$stored,$contentHash]);
            $saved++;
          } catch (Throwable $e) {
            @unlink($dir.'/'.$stored);
            $failed[]=$name.': database insert failed';
          }
        }
        if ($saved>0) { $notice=$saved.' track'.($saved===1?'':'s').' uploaded successfully.'; $uploadSound=true; }
        if ($duplicates) $notice.=($notice?' ':'').count($duplicates).' duplicate file'.(count($duplicates)===1?' was':'s were').' skipped (identical audio already exists).';
        if ($failed) $error=count($failed).' file'.(count($failed)===1?'':'s').' could not be uploaded: '.implode(' | ',array_slice($failed,0,5)).(count($failed)>5?' | and '.(count($failed)-5).' more':'');
        if ($saved===0 && !$failed && !$duplicates) $error='No files were uploaded.';
        if ($saved===0 && !$failed && $duplicates) $notice='All selected files were duplicates and were skipped.';
      }
    }
    if (($_POST['ajax_upload']??'')==='1') {
      header('Content-Type: application/json; charset=utf-8');
      $listQuery = $search !== ''
        ? $pdo->prepare("SELECT id,title,artist,album FROM tracks WHERE title LIKE ? OR artist LIKE ? OR album LIKE ? ORDER BY id DESC")
        : $pdo->prepare("SELECT id,title,artist,album FROM tracks ORDER BY id DESC");
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
  } elseif ($action==='delete') {
    require_admin(); $id=filter_var($_POST['id']??null,FILTER_VALIDATE_INT);
    if ($id) { $q=$pdo->prepare('SELECT stored_name FROM tracks WHERE id=?'); $q->execute([$id]); $track=$q->fetch(); if ($track) { $pdo->prepare('DELETE FROM tracks WHERE id=?')->execute([$id]); $path=__DIR__.'/storage/music/'.$track['stored_name']; if (is_file($path)) unlink($path); $notice='Track deleted.'; } }
  }
}
if ($search!=='') {
  $q=$pdo->prepare("SELECT id,title,artist,album FROM tracks WHERE title LIKE ? OR artist LIKE ? OR album LIKE ? ORDER BY id DESC");
  $term='%'.$search.'%'; $q->execute([$term,$term,$term]); $tracks=$q->fetchAll();
} else {
  $tracks=$pdo->query('SELECT id,title,artist,album FROM tracks ORDER BY id DESC')->fetchAll();
}
?><!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>RIVUNE Admin</title><link rel="icon" type="image/svg+xml" href="assets/favicon.svg"><link rel="stylesheet" href="assets/css/app.css"><body><main class="admin-wrap"><header class="admin-head"><a class="brand" href="./"><span class="brand-icon">♫</span><span>RIVUNE<small>ADMIN STUDIO</small></span></a><a href="./" class="text-link">← Public site</a></header><?php if(isset($_GET['installed'])): ?><p class="success">Installation complete. Sign in to upload your first tracks.</p><?php endif; ?><?php if($error): ?><p class="error"><?= h($error) ?></p><?php endif; ?><?php if($notice): ?><p class="success"><?= h($notice) ?></p><?php endif; ?>
<?php if(!is_admin()): ?><section class="panel auth-panel"><p class="eyebrow">RESTRICTED AREA</p><h1>Administrator login</h1><form method="post" class="form"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="login"><label>Username<input name="username" required autocomplete="username"></label><label>Password<input type="password" name="password" required autocomplete="current-password"></label><button class="primary">Sign in</button></form></section>
<?php else: ?><section class="admin-content"><div class="section-head"><div><p class="eyebrow">LIBRARY MANAGEMENT</p><h1>Music studio</h1></div><form method="post"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="logout"><button class="secondary">Sign out</button></form></div><section class="panel"><h2>Upload multiple MP3s</h2><p class="muted">Select as many MP3 files as your hosting limits allow. Each file can be up to 25 MB. Titles are taken from filenames; artist and album below apply to every selected track.</p><form method="post" enctype="multipart/form-data" class="form" data-ajax-upload><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="upload"><input type="hidden" name="ajax_upload" value="1"><label>MP3 files<input type="file" name="mp3[]" accept=".mp3,audio/mpeg" multiple required></label><div class="form-grid"><label>Artist for all tracks<input name="artist" placeholder="Optional — Unknown artist"></label><label>Album for all tracks<input name="album" placeholder="Optional — Unknown album"></label></div><button class="primary">Upload selected tracks</button></form></section><section class="panel"><div class="section-head"><h2>Current library</h2><span class="muted"><?= count($tracks) ?><?= $search!==''?' matching':'' ?> tracks</span></div><form method="get" action="admin.php" class="form" style="margin:0 0 18px"><label>Search songs<input type="search" name="q" value="<?= h($search) ?>" placeholder="Search title, artist or album" autocomplete="off"></label><div style="display:flex;gap:10px;flex-wrap:wrap"><button class="secondary" type="submit">Search</button><?php if($search!==''): ?><a class="text-link" href="admin.php">Clear search</a><?php endif; ?></div></form><?php if(!$tracks): ?><p class="muted"><?= $search!==''?'No matching songs found.':'No music uploaded yet.' ?></p><?php else: ?><div class="admin-list"><?php foreach($tracks as $t): ?><div class="admin-track"><div><strong><?= h($t['title']) ?></strong><small><?= h($t['artist']) ?> · <?= h($t['album']) ?></small></div><form method="post" action="admin.php<?= $search!==''?'?q='.rawurlencode($search):'' ?>" onsubmit="return confirm('Permanently delete this track?')"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><button class="danger">Delete</button></form></div><?php endforeach; ?></div><?php endif; ?></section></section><?php endif; ?></main>
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
