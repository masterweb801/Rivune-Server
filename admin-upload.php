<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/upload_limits.php';
if (!isset($pdo)) { header('Location: install.php'); exit; }
require_admin();
$limits = rivune_upload_limits();
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>Upload songs | RIVUNE Admin</title>
<link rel="icon" type="image/png" href="public/assets/rivune-logo.png">
<link rel="stylesheet" href="assets/css/tokens.css?v=design-tokens-2">
<link rel="stylesheet" href="assets/css/app.css?v=app-core-5">
<link rel="stylesheet" href="assets/css/controls.css?v=controls-1">
<link rel="stylesheet" href="assets/css/forms.css?v=forms-1">
<link rel="stylesheet" href="assets/css/admin.css?v=admin-module-2">
<style>
.upload-drop{display:grid;gap:10px;padding:24px;border:1px dashed var(--border, #555);border-radius:14px;text-align:center}
.upload-drop input{max-width:100%}.upload-result{margin-top:16px}.upload-meter{width:100%;height:12px}
.upload-meta{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap}
</style>
</head>
<body>
<main class="admin-wrap">
<?php $adminActivePage='song_upload'; require __DIR__ . '/lib/admin_navigation.php'; ?>
<section class="panel">
<p class="eyebrow">SONGS · UPLOAD</p>
<h1>Upload songs</h1>
<p class="muted">Choose one or more MP3 files. Files are validated and saved first; artwork and metadata extraction runs afterward in the processing queue. SHA-256 is used to skip identical audio.</p>
<form id="rivune-upload-form" class="form" method="post" action="admin-upload-handler.php" enctype="multipart/form-data">
<input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
<label class="upload-drop">
<strong>Select MP3 files</strong>
<span class="muted">Multiple files are supported.</span>
<input id="rivune-mp3-files" type="file" name="mp3[]" accept=".mp3,audio/mpeg" multiple required
data-max-file="<?= (int)$limits['max_file_bytes'] ?>"
data-max-batch="<?= (int)$limits['max_batch_bytes'] ?>"
data-max-files="<?= (int)$limits['max_files'] ?>">
</label>
<div class="form-grid">
<label>Artist for all tracks<input name="artist" placeholder="Optional — Unknown artist"></label>
<label>Album for all tracks<input name="album" placeholder="Optional — Unknown album"></label>
</div>
<div id="rivune-upload-progress" hidden>
<div class="upload-meta"><span id="rivune-upload-status" class="muted" role="status">Ready</span><strong id="rivune-upload-percent">0%</strong></div>
<progress id="rivune-upload-meter" class="upload-meter" max="100" value="0"></progress>
</div>
<div id="rivune-upload-result" class="upload-result" aria-live="polite"></div>
<button id="rivune-upload-submit" class="primary" type="submit">Upload selected tracks</button>
</form>
<p class="muted">Limits: <?= h(rivune_format_upload_mb($limits['max_file_bytes'])) ?> MB per file · <?= h(rivune_format_upload_mb($limits['max_batch_bytes'])) ?> MB per batch · <?= (int)$limits['max_files'] ?> files maximum.</p>
</section>
</main>
<script>
(function(){
'use strict';
const form=document.getElementById('rivune-upload-form');
const picker=document.getElementById('rivune-mp3-files');
const submit=document.getElementById('rivune-upload-submit');
const progressWrap=document.getElementById('rivune-upload-progress');
const meter=document.getElementById('rivune-upload-meter');
const percent=document.getElementById('rivune-upload-percent');
const status=document.getElementById('rivune-upload-status');
const resultBox=document.getElementById('rivune-upload-result');
const maxFile=Number(picker.dataset.maxFile), maxBatch=Number(picker.dataset.maxBatch), maxFiles=Number(picker.dataset.maxFiles);
let active=false;
function showResult(message,kind){
 const p=document.createElement('p');p.className=kind==='error'?'error':'success';p.textContent=message;resultBox.replaceChildren(p);
}
function setBusy(value){active=value;submit.disabled=value;submit.textContent=value?'Uploading…':'Upload selected tracks';}
function processQueue(){
 const token=form.elements.csrf.value;let processed=0;
 async function step(){
  try{
   const response=await fetch('admin-process-queue.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'},body:new URLSearchParams({csrf:token})});
   const data=await response.json();
   if(!response.ok||!data.ok)throw new Error('queue');
   processed+=Number(data.processed||0);
   if(Number(data.remaining)>0){status.textContent='Processing artwork and metadata… '+processed+' completed';return step();}
   status.textContent=processed?'Upload complete · background processing finished':'Upload complete · processing queue is clear';
  }catch(e){status.textContent='Upload complete · remaining processing is queued for a later admin visit';}
 }
 step();
}
form.addEventListener('submit',function(event){
 event.preventDefault();if(active)return;
 const files=Array.from(picker.files||[]);
 if(!files.length)return;
 if(files.length>maxFiles){showResult('Select no more than '+maxFiles+' files.','error');return;}
 const tooLarge=files.find(f=>f.size>maxFile);
 if(tooLarge){showResult(tooLarge.name+' exceeds the per-file limit.','error');return;}
 const total=files.reduce((sum,f)=>sum+f.size,0);
 if(total>maxBatch){showResult('The selected files exceed the total batch limit.','error');return;}
 resultBox.replaceChildren();progressWrap.hidden=false;meter.value=0;percent.textContent='0%';status.textContent='Connecting…';setBusy(true);
 const xhr=new XMLHttpRequest();let finished=false;
 xhr.open('POST',form.action,true);xhr.withCredentials=true;xhr.timeout=240000;
 xhr.setRequestHeader('X-Requested-With','XMLHttpRequest');
 xhr.upload.addEventListener('progress',e=>{
  if(!e.lengthComputable)return;
  const value=Math.round(e.loaded/e.total*100);meter.value=value;percent.textContent=value+'%';
  status.textContent=value===100?'File received · saving on server…':'Uploading files…';
 });
 xhr.addEventListener('load',()=>{
  if(finished)return;finished=true;
  let data=null;try{data=JSON.parse(xhr.responseText||'');}catch(e){}
  if(!data||xhr.status<200||xhr.status>=300){
   showResult(data&&data.error?data.error:'The server did not return a valid upload response (HTTP '+xhr.status+').','error');
   status.textContent='Upload failed';setBusy(false);return;
  }
  meter.value=100;percent.textContent='100%';
  if(data.error){showResult([data.notice,data.error].filter(Boolean).join(' '),'error');}
  else{showResult(data.notice||'Upload completed.','success');}
  setBusy(false);
  if(data.saved>0){form.reset();status.textContent='Upload saved · preparing background processing…';processQueue();}
  else{status.textContent=data.duplicates>0?'No new files needed saving':'Upload finished';}
 });
 xhr.addEventListener('error',()=>{if(finished)return;finished=true;showResult('The connection was interrupted before the server returned a response. No success has been assumed.','error');status.textContent='Connection interrupted';setBusy(false);});
 xhr.addEventListener('timeout',()=>{if(finished)return;finished=true;showResult('The upload request timed out. Check the song library before retrying.','error');status.textContent='Request timed out';setBusy(false);});
 xhr.addEventListener('abort',()=>{if(finished)return;finished=true;showResult('The upload request was cancelled.','error');status.textContent='Upload cancelled';setBusy(false);});
 xhr.send(new FormData(form));
});
})();
</script>
</body>
</html>
