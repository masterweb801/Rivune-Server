<?php
declare(strict_types=1);
require __DIR__.'/lib/bootstrap.php';
require_once __DIR__.'/lib/track_processing.php';
if (!isset($pdo)) { header('Location: install.php'); exit; }
require_admin();
rivune_processing_ensure_schema($pdo);
$notice=(string)($_SESSION['rivune_admin_notice']??''); unset($_SESSION['rivune_admin_notice']);
$search=trim((string)($_GET['q']??''));$page=max(1,(int)($_GET['page']??1));$perPage=25;
$where=$search!==''?' WHERE title LIKE ? OR artist LIKE ? OR album LIKE ?':'';
$listWhere=$search!==''?' WHERE t.title LIKE ? OR t.artist LIKE ? OR t.album LIKE ?':'';
$count=$pdo->prepare('SELECT COUNT(*) FROM tracks'.$where);if($search!==''){$term='%'.$search.'%';$count->execute([$term,$term,$term]);}else{$count->execute();}
$total=(int)$count->fetchColumn();$pages=max(1,(int)ceil($total/$perPage));$page=min($page,$pages);
$stmt=$pdo->prepare('SELECT t.id,t.title,t.artist,t.album,j.status AS processing_status FROM tracks t LEFT JOIN track_processing_jobs j ON j.track_id=t.id'.$listWhere.' ORDER BY t.id DESC LIMIT '.$perPage.' OFFSET '.(($page-1)*$perPage));if($search!=='')$stmt->execute([$term,$term,$term]);else$stmt->execute();$tracks=$stmt->fetchAll();
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><title>Manage songs | RIVUNE Admin</title><link rel="icon" type="image/png" href="public/assets/rivune-logo.png"><link rel="stylesheet" href="assets/css/tokens.css?v=design-tokens-2">
<link rel="stylesheet" href="assets/css/app.css?v=app-core-5">
<link rel="stylesheet" href="assets/css/controls.css?v=controls-1">
<link rel="stylesheet" href="assets/css/forms.css?v=forms-1">
<link rel="stylesheet" href="assets/css/admin.css?v=admin-module-2"></head><body><main class="admin-wrap"><?php $adminActivePage='song_manage';require __DIR__.'/lib/admin_navigation.php';?>
<section class="panel"><p class="eyebrow">SONGS · MANAGEMENT</p><div class="section-head"><h1>Manage songs</h1><span class="muted"><?= $total ?><?= $search!==''?' matching':'' ?> tracks · Page <?= $page ?> / <?= $pages ?></span></div>
<?php if($notice):?><p class="success"><?=h($notice)?></p><?php endif;?>
<form method="get" action="admin-songs.php" class="form" style="margin:0 0 18px"><label>Search songs<input type="search" name="q" value="<?=h($search)?>" placeholder="Search title, artist or album" autocomplete="off"></label><div style="display:flex;gap:10px;flex-wrap:wrap"><button class="secondary" type="submit">Search</button><?php if($search!==''):?><a class="text-link" href="admin-songs.php">Clear search</a><?php endif;?></div></form>
<?php if(!$tracks):?><p class="muted"><?= $search!==''?'No matching songs found.':'No music uploaded yet.'?></p><?php else:?><div class="admin-list"><?php foreach($tracks as $t):?><div class="admin-track"><div><strong><?=h($t['title'])?></strong><small><?=h($t['artist'])?> · <?=h($t['album'])?><?php if(($t['processing_status']??'')==='pending'||($t['processing_status']??'')==='processing'):?> · <span>Metadata/artwork queued</span><?php elseif(($t['processing_status']??'')==='failed'):?> · <span>Processing needs attention</span><?php endif;?></small></div><div class="admin-track-actions"><a class="text-link" href="admin-artwork.php?id=<?=(int)$t['id']?>">Artwork</a><form method="post" action="admin.php" onsubmit="return confirm('Permanently delete this track and its artwork?')"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=(int)$t['id']?>"><input type="hidden" name="return_to" value="admin-songs.php"><input type="hidden" name="return_q" value="<?=h($search)?>"><input type="hidden" name="return_page" value="<?=$page?>"><button class="danger" type="submit">Delete</button></form></div></div><?php endforeach;?></div><?php endif;?>
<?php if($pages>1):?><nav class="admin-pagination" aria-label="Song list pages"><?php if($page>1):?><a href="admin-songs.php?<?= $search!==''?'q='.rawurlencode($search).'&':''?>page=<?=$page-1?>">← Previous</a><?php endif;?><span>Page <?=$page?> of <?=$pages?></span><?php if($page<$pages):?><a href="admin-songs.php?<?= $search!==''?'q='.rawurlencode($search).'&':''?>page=<?=$page+1?>">Next →</a><?php endif;?></nav><?php endif;?>
<p class="muted">Select Artwork to replace or remove a track's cover. Artwork maintenance for embedded covers is available as a separate route.</p><a class="text-link" href="admin-extract-artwork.php">Open artwork maintenance →</a></section></main>
<script>
(function(){
 const token=<?= json_encode(csrf_token()) ?>;
 async function resumeQueue(){
   let touched=false;
   for(let i=0;i<200;i++){
     try{
       const body=new URLSearchParams({csrf:token});
       const response=await fetch('admin-process-queue.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'},body:body.toString()});
       const result=await response.json();
       if(!response.ok||!result.ok)return;
       touched=touched||Number(result.processed)>0;
       if(Number(result.remaining)===0){if(touched)location.reload();return;}
     }catch(e){return;}
   }
 })();
})();
</script></body></html>