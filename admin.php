<?php
declare(strict_types=1);
require __DIR__.'/lib/bootstrap.php';
if (!isset($pdo)) { header('Location: install.php'); exit; }
$error=''; $notice='';
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
        $saved=0; $failed=[];
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
          $mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmp);
          if ($ext!=='mp3' || !in_array($mime,['audio/mpeg','audio/mp3','application/octet-stream'],true)) {
            $failed[]=$name.': not recognized as an MP3 file'; continue;
          }
          $title=pathinfo($name,PATHINFO_FILENAME);
          $stored=bin2hex(random_bytes(20)).'.mp3';
          if (!move_uploaded_file($tmp,$dir.'/'.$stored)) {
            $failed[]=$name.': could not save file'; continue;
          }
          try {
            $q=$pdo->prepare('INSERT INTO tracks(title,artist,album,original_name,stored_name) VALUES(?,?,?,?,?)');
            $q->execute([substr($title,0,190),substr($sharedArtist,0,190),substr($sharedAlbum,0,190),basename($name),$stored]);
            $saved++;
          } catch (Throwable $e) {
            @unlink($dir.'/'.$stored);
            $failed[]=$name.': database insert failed';
          }
        }
        if ($saved>0) $notice=$saved.' track'.($saved===1?'':'s').' uploaded successfully.';
        if ($failed) $error=count($failed).' file'.(count($failed)===1?'':'s').' could not be uploaded: '.implode(' | ',array_slice($failed,0,5)).(count($failed)>5?' | and '.(count($failed)-5).' more':'');
        if ($saved===0 && !$failed) $error='No files were uploaded.';
      }
    }
  } elseif ($action==='delete') {
    require_admin(); $id=filter_var($_POST['id']??null,FILTER_VALIDATE_INT);
    if ($id) { $q=$pdo->prepare('SELECT stored_name FROM tracks WHERE id=?'); $q->execute([$id]); $track=$q->fetch(); if ($track) { $pdo->prepare('DELETE FROM tracks WHERE id=?')->execute([$id]); $path=__DIR__.'/storage/music/'.$track['stored_name']; if (is_file($path)) unlink($path); $notice='Track deleted.'; } }
  }
}
$tracks=$pdo->query('SELECT id,title,artist,album FROM tracks ORDER BY id DESC')->fetchAll();
?><!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>RIVUNE Admin</title><link rel="stylesheet" href="assets/css/app.css"><body><main class="admin-wrap"><header class="admin-head"><a class="brand" href="./"><span class="brand-icon">♫</span><span>RIVUNE<small>ADMIN STUDIO</small></span></a><a href="./" class="text-link">← Public site</a></header><?php if(isset($_GET['installed'])): ?><p class="success">Installation complete. Sign in to upload your first tracks.</p><?php endif; ?><?php if($error): ?><p class="error"><?= h($error) ?></p><?php endif; ?><?php if($notice): ?><p class="success"><?= h($notice) ?></p><?php endif; ?>
<?php if(!is_admin()): ?><section class="panel auth-panel"><p class="eyebrow">RESTRICTED AREA</p><h1>Administrator login</h1><form method="post" class="form"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="login"><label>Username<input name="username" required autocomplete="username"></label><label>Password<input type="password" name="password" required autocomplete="current-password"></label><button class="primary">Sign in</button></form></section>
<?php else: ?><section class="admin-content"><div class="section-head"><div><p class="eyebrow">LIBRARY MANAGEMENT</p><h1>Music studio</h1></div><form method="post"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="logout"><button class="secondary">Sign out</button></form></div><section class="panel"><h2>Upload multiple MP3s</h2><p class="muted">Select as many MP3 files as your hosting limits allow. Each file can be up to 25 MB. Titles are taken from filenames; artist and album below apply to every selected track.</p><form method="post" enctype="multipart/form-data" class="form"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="upload"><label>MP3 files<input type="file" name="mp3[]" accept=".mp3,audio/mpeg" multiple required></label><div class="form-grid"><label>Artist for all tracks<input name="artist" placeholder="Optional — Unknown artist"></label><label>Album for all tracks<input name="album" placeholder="Optional — Unknown album"></label></div><button class="primary">Upload selected tracks</button></form></section><section class="panel"><div class="section-head"><h2>Current library</h2><span class="muted"><?= count($tracks) ?> tracks</span></div><?php if(!$tracks): ?><p class="muted">No music uploaded yet.</p><?php else: ?><div class="admin-list"><?php foreach($tracks as $t): ?><div class="admin-track"><div><strong><?= h($t['title']) ?></strong><small><?= h($t['artist']) ?> · <?= h($t['album']) ?></small></div><form method="post" onsubmit="return confirm('Permanently delete this track?')"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><button class="danger">Delete</button></form></div><?php endforeach; ?></div><?php endif; ?></section></section><?php endif; ?></main></body></html>
