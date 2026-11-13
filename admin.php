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
    if (empty($_FILES['mp3']) || $_FILES['mp3']['error']!==UPLOAD_ERR_OK) $error='Choose an MP3 file (check the server upload size limit).';
    elseif ($_FILES['mp3']['size']>25*1024*1024) $error='Each MP3 must be 25 MB or smaller.';
    else {
      $file=$_FILES['mp3']; $ext=strtolower(pathinfo($file['name'],PATHINFO_EXTENSION)); $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
      if ($ext!=='mp3' || !in_array($mime,['audio/mpeg','audio/mp3','application/octet-stream'],true)) $error='Only valid MP3 files are accepted.';
      else {
        $title=trim($_POST['title']??'') ?: pathinfo($file['name'],PATHINFO_FILENAME); $artist=trim($_POST['artist']??'') ?: 'Unknown artist'; $album=trim($_POST['album']??'') ?: 'Unknown album';
        $stored=bin2hex(random_bytes(20)).'.mp3'; $dir=__DIR__.'/storage/music';
        if (!is_dir($dir)) mkdir($dir,0750,true);
        if (move_uploaded_file($file['tmp_name'],$dir.'/'.$stored)) {
          $q=$pdo->prepare('INSERT INTO tracks(title,artist,album,original_name,stored_name) VALUES(?,?,?,?,?)'); $q->execute([mb_substr($title,0,190),mb_substr($artist,0,190),mb_substr($album,0,190),basename($file['name']),$stored]); $notice='Track uploaded successfully.';
        } else $error='Could not save the uploaded file. Check storage/music permissions.';
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
<?php else: ?><section class="admin-content"><div class="section-head"><div><p class="eyebrow">LIBRARY MANAGEMENT</p><h1>Music studio</h1></div><form method="post"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="logout"><button class="secondary">Sign out</button></form></div><section class="panel"><h2>Upload an MP3</h2><p class="muted">MP3 only · 25 MB maximum per file. Listening is public; only administrators can change the library.</p><form method="post" enctype="multipart/form-data" class="form"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="upload"><label>MP3 file<input type="file" name="mp3" accept=".mp3,audio/mpeg" required></label><div class="form-grid"><label>Song title<input name="title" placeholder="Optional"></label><label>Artist<input name="artist" placeholder="Optional"></label><label>Album<input name="album" placeholder="Optional"></label></div><button class="primary">Upload track</button></form></section><section class="panel"><div class="section-head"><h2>Current library</h2><span class="muted"><?= count($tracks) ?> tracks</span></div><?php if(!$tracks): ?><p class="muted">No music uploaded yet.</p><?php else: ?><div class="admin-list"><?php foreach($tracks as $t): ?><div class="admin-track"><div><strong><?= h($t['title']) ?></strong><small><?= h($t['artist']) ?> · <?= h($t['album']) ?></small></div><form method="post" onsubmit="return confirm('Permanently delete this track?')"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><button class="danger">Delete</button></form></div><?php endforeach; ?></div><?php endif; ?></section></section><?php endif; ?></main></body></html>