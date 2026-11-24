<?php
declare(strict_types=1);
require __DIR__.'/lib/bootstrap.php';
require_once __DIR__.'/lib/upload_limits.php';
$uploadLimits = rivune_upload_limits();
if (!isset($pdo)) { header('Location: install.php'); exit; }
require_admin();
$notice=(string)($_SESSION['rivune_admin_notice']??''); unset($_SESSION['rivune_admin_notice']);
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><title>Upload songs | RIVUNE Admin</title><link rel="icon" type="image/png" href="public/assets/rivune-logo.png"><link rel="stylesheet" href="assets/css/tokens.css?v=design-tokens-2">
<link rel="stylesheet" href="assets/css/app.css?v=app-core-5">
<link rel="stylesheet" href="assets/css/controls.css?v=controls-1">
<link rel="stylesheet" href="assets/css/forms.css?v=forms-1">
<link rel="stylesheet" href="assets/css/admin.css?v=admin-module-2"></head><body><main class="admin-wrap"><?php $adminActivePage='song_upload'; require __DIR__.'/lib/admin_navigation.php'; ?>
<section class="panel"><p class="eyebrow">SONGS · UPLOAD</p><h1>Upload songs</h1><p class="muted">Upload multiple MP3 files in one batch. Current safe limits: <?= h(rivune_format_upload_mb($uploadLimits['max_file_bytes'])) ?> MB per file, <?= h(rivune_format_upload_mb($uploadLimits['max_batch_bytes'])) ?> MB per batch, up to <?= (int)$uploadLimits['max_files'] ?> files. Embedded MP3 title, artist and album tags are read after upload when available. The artist and album fields here override embedded tags. Identical audio is skipped automatically using SHA-256.</p>
<?php if($notice): ?><p class="success"><?= h($notice) ?></p><?php endif; ?><div id="upload-message" aria-live="polite"></div>
<form method="post" action="admin.php?ajax_upload=1" enctype="multipart/form-data" class="form" id="song-upload-form"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="upload"><input type="hidden" name="ajax_upload" value="1"><label>MP3 files<input type="file" name="mp3[]" accept=".mp3,audio/mpeg" multiple required data-max-file-bytes="<?= (int)$uploadLimits['max_file_bytes'] ?>" data-max-batch-bytes="<?= (int)$uploadLimits['max_batch_bytes'] ?>" data-max-files="<?= (int)$uploadLimits['max_files'] ?>"></label><div class="form-grid"><label>Artist for all tracks<input name="artist" placeholder="Optional — Unknown artist"></label><label>Album for all tracks<input name="album" placeholder="Optional — Unknown album"></label></div><div id="upload-progress" style="display:none"><progress max="100" value="0" style="display:block;width:100%;height:12px"></progress><p class="muted" id="upload-progress-label">0%</p></div><p class="muted" id="upload-status" role="status"></p><button class="primary" type="submit">Upload selected tracks</button></form></section></main>
<script>
(function(){
 const form=document.getElementById('song-upload-form');
 const fileInput=form.querySelector('[type=file]');
 const button=form.querySelector('button');
 const progress=document.querySelector('#upload-progress progress');
 const wrap=document.getElementById('upload-progress');
 const label=document.getElementById('upload-progress-label');
 const status=document.getElementById('upload-status');
 const message=document.getElementById('upload-message');
 const maxFileBytes=Number(fileInput.dataset.maxFileBytes||0);
 const maxBatchBytes=Number(fileInput.dataset.maxBatchBytes||0);
 const maxFiles=Number(fileInput.dataset.maxFiles||20);
 const AudioCtx=window.AudioContext||window.webkitAudioContext;
 let audioContext=null;
 function unlock(){if(!AudioCtx)return;try{if(!audioContext)audioContext=new AudioCtx();if(audioContext.state!=='running')audioContext.resume().catch(()=>{});}catch(e){}}
 button.addEventListener('pointerdown',unlock);
 button.addEventListener('touchstart',unlock,{passive:true});
 function chime(){
   if(!audioContext||audioContext.state!=='running')return;
   try{
     const now=audioContext.currentTime;
     [660,880,1100].forEach((f,i)=>{
       const o=audioContext.createOscillator(),g=audioContext.createGain(),t=now+i*.15;
       o.type='sine';o.frequency.value=f;
       g.gain.setValueAtTime(.0001,t);
       g.gain.exponentialRampToValueAtTime(.14,t+.025);
       g.gain.exponentialRampToValueAtTime(.0001,t+.23);
       o.connect(g);g.connect(audioContext.destination);o.start(t);o.stop(t+.25);
     });
   }catch(e){}
 }
 function showError(detail){
   const node=document.createElement('p');node.className='error';
   node.textContent='Upload failed. '+detail;
   message.replaceChildren(node);status.textContent='Upload failed.';
   button.disabled=false;button.textContent='Upload selected tracks';
 }
 async function runProcessingQueue(){
   const token=form.querySelector('[name="csrf"]').value;
   let totalProcessed=0;
   for(let i=0;i<200;i++){
     try{
       const body=new URLSearchParams({csrf:token});
       const response=await fetch('admin-process-queue.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'},body:body.toString()});
       const result=await response.json();
       if(!response.ok||!result.ok)throw new Error(result.error||'Queue request failed.');
       totalProcessed+=Number(result.processed||0);
       if(Number(result.remaining)===0){
         if(totalProcessed>0)status.textContent=Number(result.failed)>0?'Upload complete. Background processing finished with '+result.failed+' item(s) needing attention.':'Upload complete. Background metadata and artwork processing finished.';
         return;
       }
       status.textContent='Upload complete. Processing metadata and artwork… '+totalProcessed+' completed; '+result.remaining+' waiting.';
     }catch(error){
       status.textContent='Upload complete. Background processing is queued and will resume when the admin page is opened again.';
       return;
     }
   }
   status.textContent='Upload complete. Remaining tracks are safely queued for later processing.';
 }
 form.addEventListener('submit',function(event){
   event.preventDefault();
   const files=Array.from(fileInput.files||[]);
   if(!files.length)return;
   if(files.length>maxFiles){showError('This server currently accepts up to '+maxFiles+' files per batch.');return;}
   const oversized=files.find(file=>file.size>maxFileBytes);
   if(oversized){showError('"'+oversized.name+'" exceeds the current per-file limit of '+(maxFileBytes/1048576).toFixed(1)+' MB.');return;}
   const totalBytes=files.reduce((sum,file)=>sum+file.size,0);
   if(totalBytes>maxBatchBytes){showError('Selected files total '+(totalBytes/1048576).toFixed(1)+' MB; the current batch limit is '+(maxBatchBytes/1048576).toFixed(1)+' MB. Select fewer files.');return;}
   unlock();button.disabled=true;button.textContent='Uploading…';
   message.textContent='';wrap.style.display='block';progress.value=0;label.textContent='0%';status.textContent='Starting upload…';
   const xhr=new XMLHttpRequest();let completed=false;const requestStartedAt=Date.now();let lastLoaded=0,lastTotal=0;
   xhr.open('POST',form.action,true);xhr.withCredentials=true;
   xhr.setRequestHeader('X-Requested-With','XMLHttpRequest');xhr.timeout=180000;
   xhr.upload.addEventListener('progress',e=>{
     if(e.lengthComputable){lastLoaded=e.loaded;lastTotal=e.total;}
     if(!e.lengthComputable){status.textContent='Uploading files…';return;}
     const p=Math.round(e.loaded/e.total*100);progress.value=p;label.textContent=p+'%';
     status.textContent=p>=100?'Upload received. Saving tracks…':'Uploading files…';
   });
   xhr.addEventListener('load',()=>{
     if(completed)return;
     let result=null;try{result=JSON.parse(xhr.responseText||'');}catch(e){}
     if(!result||typeof result!=='object'){showError(xhr.status===0?'The connection was interrupted before the server returned a response (readyState '+xhr.readyState+', sent '+lastLoaded+'/'+lastTotal+' bytes, '+Math.round((Date.now()-requestStartedAt)/1000)+' seconds).':'The server returned an unreadable response (HTTP '+xhr.status+').');completed=true;return;}
     if(xhr.status<200||xhr.status>=300){showError(result.error||result.notice||('The server rejected the upload (HTTP '+xhr.status+').'));completed=true;return;}
     completed=true;progress.value=100;label.textContent='100%';
     const text=[result.notice,result.error].filter(Boolean).join(' ');
     const node=document.createElement('p');node.className=result.error?'error':'success';
     node.textContent=text||'Upload request completed.';message.replaceChildren(node);
     if(Number(result.saved)>0)chime();
     if(result.ok&&!result.error)form.reset();
     status.textContent=Number(result.saved)>0?'Upload complete. Background metadata and artwork processing is queued.':(result.error?'Some files need attention.':'Upload request finished.');
     button.disabled=false;button.textContent='Upload selected tracks';
     if(Number(result.saved)>0)runProcessingQueue();
   });
   xhr.addEventListener('error',()=>{if(!completed){completed=true;showError('The network connection was interrupted (HTTP '+xhr.status+', readyState '+xhr.readyState+', sent '+lastLoaded+'/'+lastTotal+' bytes, '+Math.round((Date.now()-requestStartedAt)/1000)+' seconds).');}});
   xhr.addEventListener('abort',()=>{if(!completed){completed=true;showError('The upload request was cancelled (sent '+lastLoaded+'/'+lastTotal+' bytes, '+Math.round((Date.now()-requestStartedAt)/1000)+' seconds).');}});
   xhr.addEventListener('timeout',()=>{if(!completed){completed=true;showError('The request exceeded the 3-minute browser timeout (sent '+lastLoaded+'/'+lastTotal+' bytes).');}});
   xhr.send(new FormData(form));
 });
})();
</script></body></html>