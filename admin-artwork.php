<?php
declare(strict_types=1);
require __DIR__.'/lib/bootstrap.php';
require_once __DIR__.'/lib/admin_logs.php';
if (!isset($pdo)) { header('Location: install.php'); exit; }
require_admin();

$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);
if (!$id || $id<1) { http_response_code(400); exit('Invalid track.'); }
$stmt=$pdo->prepare('SELECT id,title,artist,album FROM tracks WHERE id=?');
$stmt->execute([$id]);
$track=$stmt->fetch();
if (!$track) { http_response_code(404); exit('Track not found.'); }

function rivune_artwork_path(int $trackId): ?string {
  foreach (['jpg','png','webp'] as $extension) {
    $path=__DIR__.'/storage/artwork/'.$trackId.'.'.$extension;
    if (is_file($path)) return $path;
  }
  return null;
}
$error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  csrf_check();
  $action=(string)($_POST['action']??'upload');
  $directory=__DIR__.'/storage/artwork';
  if ($action==='remove') {
    foreach (['jpg','png','webp'] as $extension) {
      $path=$directory.'/'.$id.'.'.$extension;
      if (is_file($path)) @unlink($path);
    }
    rivune_admin_log($pdo,'artwork','success','Removed artwork for track #'.(int)$id.'.');
    header('Location: admin-artwork.php?id='.$id.'&removed=1'); exit;
  }
  $file=$_FILES['artwork']??null;
  if (!$file || !isset($file['tmp_name']) || (int)($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) {
    $error='Choose an image file to upload.';
  } elseif (!is_uploaded_file($file['tmp_name']) || (int)$file['size']<1 || (int)$file['size']>5*1024*1024) {
    $error='Artwork must be a valid upload no larger than 5 MB.';
  } else {
    $info=@getimagesize($file['tmp_name']);
    $finfo=new finfo(FILEINFO_MIME_TYPE);
    $mime=$finfo->file($file['tmp_name']);
    $types=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    if (!$info || !isset($types[$mime]) || (int)$info[0]<1 || (int)$info[1]<1 || (int)$info[0]>6000 || (int)$info[1]>6000 || (int)$info[0]*(int)$info[1]>24000000) {
      $error='Use a valid JPEG, PNG, or WebP image up to 6000 × 6000 pixels.';
    } else {
      if (!is_dir($directory) && !mkdir($directory,0750,true) && !is_dir($directory)) {
        $error='Could not create the artwork folder. Check storage permissions.';
      } else {
        require_once __DIR__.'/lib/mp3_artwork.php';
        $payload=['extension'=>$types[$mime],'data'=>(string)file_get_contents($file['tmp_name'])];
        if (!rivune_save_embedded_artwork((int)$id,$payload)) {
          $error='Could not optimize or save the artwork. Check image support and storage permissions.';
        } else {
          rivune_admin_log($pdo,'artwork','success','Updated and optimized artwork for track #'.(int)$id.'.');
          header('Location: admin-artwork.php?id='.$id.'&saved=1'); exit;
        }
      }
    }
  }
}
$currentArtwork=rivune_artwork_path((int)$id);
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><title>Track artwork | RIVUNE Admin</title><link rel="icon" type="image/png" href="public/assets/rivune-logo.png"><link rel="stylesheet" href="assets/css/tokens.css?v=design-tokens-1">
<link rel="stylesheet" href="assets/css/app.css?v=app-split-2"></head>
<body><main class="admin-wrap"><?php $adminActivePage='song_manage'; require __DIR__.'/lib/admin_navigation.php'; ?>
<section class="panel"><p class="eyebrow">MUSIC LIBRARY</p><h1>Track artwork</h1><p class="muted"><?= h((string)$track['title']) ?> · <?= h((string)$track['artist']) ?></p><p class="muted">RIVUNE automatically extracts supported embedded album art from MP3 uploads. Uploading an image here sets a manual cover override.</p>
<?php if ($error): ?><p class="error"><?= h($error) ?></p><?php endif; ?>
<?php if (isset($_GET['saved'])): ?><p class="success">Artwork updated. It will appear in the public player.</p><?php elseif (isset($_GET['removed'])): ?><p class="success">Artwork removed. The player will use its fallback cover.</p><?php endif; ?>
<?php if ($currentArtwork): ?><img class="artwork-preview" src="artwork.php?id=<?= (int)$id ?>&amp;v=<?= (int)filemtime($currentArtwork) ?>" alt="Current track artwork"><form method="post" class="artwork-actions" onsubmit="return confirm('Remove this track artwork?')"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="remove"><button class="danger" type="submit">Remove artwork</button></form><?php else: ?><div class="artwork-preview" style="display:grid;place-items:center;color:var(--accent);font-size:64px">♫</div><p class="muted">No artwork is set for this track yet.</p><?php endif; ?>
<form method="post" enctype="multipart/form-data" class="form"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="upload"><label>Choose artwork<input type="file" name="artwork" accept="image/jpeg,image/png,image/webp" required><small>JPEG, PNG, or WebP · maximum 5 MB. RIVUNE automatically resizes and compresses artwork when server image support is available.</small></label><button class="primary" type="submit">Save artwork</button></form>
<p class="artwork-actions"><a class="text-link" href="admin-songs.php">← Back to music library</a></p></section></main></body></html>
