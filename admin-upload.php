<?php
declare(strict_types=1);
require __DIR__.'/lib/bootstrap.php';
if (!isset($pdo)) { header('Location: install.php'); exit; }
require_admin();
$notice=(string)($_SESSION['rivune_admin_notice']??''); unset($_SESSION['rivune_admin_notice']);
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><title>Upload songs | RIVUNE Admin</title><link rel="icon" type="image/png" href="public/assets/rivune-logo.png"><link rel="stylesheet" href="assets/css/tokens.css?v=design-tokens-2">
<link rel="stylesheet" href="assets/css/app.css?v=app-core-5">
<link rel="stylesheet" href="assets/css/controls.css?v=controls-1">
<link rel="stylesheet" href="assets/css/forms.css?v=forms-1">
<link rel="stylesheet" href="assets/css/admin.css?v=admin-module-2"></head><body><main class="admin-wrap"><?php $adminActivePage='song_upload'; require __DIR__.'/lib/admin_navigation.php'; ?>
<section class="panel"><p class="eyebrow">SONGS · UPLOAD</p><h1>Upload songs</h1><p class="muted">Upload multiple MP3 files in one batch. Each file can be up to 25 MB. Titles are taken from filenames; artist and album values apply to every selected track. Identical audio is skipped automatically using SHA-256.</p>
<?php if($notice): ?><p class="success"><?= h($notice) ?></p><?php endif; ?><div id="upload-message" aria-live="polite"></div>
<form method="post" action="admin.php" enctype="multipart/form-data" class="form" id="song-upload-form"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="upload"><input type="hidden" name="ajax_upload" value="1"><label>MP3 files<input type="file" name="mp3[]" accept=".mp3,audio/mpeg" multiple required></label><div class="form-grid"><label>Artist for all tracks<input name="artist" placeholder="Optional — Unknown artist"></label><label>Album for all tracks<input name="album" placeholder="Optional — Unknown album"></label></div><div id="upload-progress" style="display:none"><progress max="100" value="0" style="display:block;width:100%;height:12px"></progress><p class="muted" id="upload-progress-label">0%</p></div><p class="muted" id="upload-status" role="status"></p><button class="primary" type="submit">Upload selected tracks</button></form></section></main>
<script>
(function(){
 const form=document.getElementById('song-upload-form'),button=form.querySelector('button'),progress=document.querySelector('#upload-progress progress'),wrap=document.getElementById('upload-progress'),label=document.getElementById('upload-progress-label'),status=document.getElementById('upload-status'),message=document.getElementById('upload-message');
 const AudioCtx=window.AudioContext||window.webkitAudioContext;let audioContext=null;
 function unlock(){if(!AudioCtx)return;try{if(!audioContext)audioContext=new AudioCtx();if(audioContext.state!=='running')audioContext.resume().catch(()=>{});}catch(e){}}
 button.addEventListener('pointerdown',unlock);button.addEventListener('touchstart',unlock,{passive:true});
 function chime(){if(!audioContext||audioContext.state!=='running')return;try{const now=audioContext.currentTime;[660,880,1100].forEach((f,i)=>{const o=audioContext.createOscillator(),g=audioContext.createGain(),t=now+i*.15;o.type='sine';o.frequency.value=f;g.gain.setValueAtTime(.0001,t);g.gain.exponentialRampToValueAtTime(.14,t+.025);g.gain.exponentialRampToValueAtTime(.0001,t+.23);o.connect(g);g.connect(audioContext.destination);o.start(t);o.stop(t+.25);});}catch(e){}}
 form.addEventListener('submit',function(event){event.preventDefault();if(!form.querySelector('[type=file]').files.length)return;unlock();button.disabled=true;button.textContent='Uploading…';message.textContent='';wrap.style.display='block';progress.value=0;label.textContent='0%';status.textContent='Starting upload…';
 const xhr=new XMLHttpRequest();xhr.open('POST',form.action,true);xhr.withCredentials=true;xhr.setRequestHeader('X-Requested-With','XMLHttpRequest');
 xhr.upload.addEventListener('progress',e=>{if(!e.lengthComputable){status.textContent='Uploading files…';return;}const p=Math.round(e.loaded/e.total*100);progress.value=p;label.textContent=p+'%';status.textContent=p>=100?'Saving tracks…':'Uploading files…';});
 xhr.addEventListener('load',()=>{let result;try{result=JSON.parse(xhr.responseText);}catch(e){finishError();return;}if(xhr.status<200||xhr.status>=300){finishError();return;}progress.value=100;label.textContent='100%';const text=[result.notice,result.error].filter(Boolean).join(' ');const node=document.createElement('p');node.className=result.error?'error':'success';node.textContent=text||'Upload request completed.';message.replaceChildren(node);if(result.saved>0)chime();if(result.ok)form.reset();status.textContent=result.saved>0?'Upload complete.':'Upload request finished.';button.disabled=false;button.textContent='Upload selected tracks';});
 xhr.addEventListener('error',finishError);xhr.addEventListener('abort',finishError);xhr.send(new FormData(form));
 function finishError(){
   const node=document.createElement('p');node.className='error';
   let detail='The server did not return a valid upload response.';
   if(xhr.status) detail='Server response: HTTP '+xhr.status+'.';
   const raw=(xhr.responseText||'').replace(/<script\\b[^>]*>[\\s\\S]*?<\\/script>/gi,' ').replace(/<style\\b[^>]*>[\\s\\S]*?<\\/style>/gi,' ');
   const holder=document.createElement('div');holder.innerHTML=raw;
   const body=(holder.textContent||holder.innerText||'').replace(/\\s+/g,' ').trim().slice(0,240);
   if(body) detail+=' '+body;
   else if(xhr.status===413) detail+=' The hosting server rejected the request as too large.';
   else if(xhr.status===403) detail+=' The hosting security layer may have blocked this request.';
   else if(xhr.status===0) detail='The connection was interrupted before the server returned a response.';
   node.textContent='Upload failed. '+detail;
   message.replaceChildren(node);status.textContent='Upload failed.';
   button.disabled=false;button.textContent='Upload selected tracks';
  }
 });
})();
</script></body></html>