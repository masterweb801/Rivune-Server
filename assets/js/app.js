(() => {
  const audio=document.getElementById('audio'), list=document.getElementById('tracks');
  const search=document.getElementById('search');
  let tracks=[], queue=[], current=-1, shuffled=false, pointerStart=null;
  const $=id=>document.getElementById(id);
  const fmt=n=>Number.isFinite(n)&&n>0?Math.floor(n/60)+':'+String(Math.floor(n%60)).padStart(2,'0'):'0:00';
  function render(){
    const q=search.value.trim().toLowerCase();
    const shown=tracks.filter(t=>(t.title+' '+t.artist+' '+t.album).toLowerCase().includes(q));
    $('count').textContent=tracks.length+' '+(tracks.length===1?'track':'tracks'); list.replaceChildren();
    if(!shown.length){const p=document.createElement('p');p.className='empty';p.textContent=tracks.length?'No tracks match your search.':'The library is empty for now. Check back soon.';list.append(p);return;}
    shown.forEach(t=>{
      const row=document.createElement('button');row.type='button';row.className='track'+(queue[current]?.id===t.id?' active':'');
      const art=document.createElement('span');art.className='track-art';art.textContent='♫';
      const info=document.createElement('span');info.className='track-meta';
      const title=document.createElement('strong');title.textContent=String(t.title||'Untitled');
      const sub=document.createElement('small');sub.textContent=String(t.artist||'Unknown artist')+' · '+String(t.album||'Unknown album');
      const play=document.createElement('span');play.className='track-play';play.textContent='▶';
      info.append(title,sub);row.append(art,info,play);row.addEventListener('click',()=>playTrack(t));list.append(row);
    });
  }
  function sync(){
    const t=queue[current], playing=!audio.paused;
    $('now-title').textContent=t?.title||'Nothing playing';$('now-artist').textContent=t?.artist||'Choose a track';
    $('expanded-title').textContent=t?.title||'Nothing playing';$('expanded-artist').textContent=t?.artist||'Choose a track';
    $('toggle').textContent=playing?'Ⅱ':'▶';$('expanded-toggle').textContent=playing?'Ⅱ':'▶';
    $('toggle').setAttribute('aria-label',playing?'Pause':'Play');$('expanded-toggle').setAttribute('aria-label',playing?'Pause':'Play');
    $('shuffle').classList.toggle('selected',shuffled);$('expanded-shuffle').classList.toggle('selected',shuffled);
    render();
  }
  function playTrack(t){
    queue=shuffled?[...tracks].sort(()=>Math.random()-.5):[...tracks];
    current=queue.findIndex(x=>x.id===t.id);
    audio.src=t.stream_url;
    audio.play().then(sync).catch(sync);
    sync();
  }
  function next(){if(!queue.length)return;current=(current+1)%queue.length;audio.src=queue[current].stream_url;audio.play().catch(()=>{});sync();}
  function previous(){if(audio.currentTime>3){audio.currentTime=0;return;}if(!queue.length)return;current=(current-1+queue.length)%queue.length;audio.src=queue[current].stream_url;audio.play().catch(()=>{});sync();}
  function togglePlayback(){
    if(!audio.src&&tracks.length){playTrack(tracks[0]);return;}
    if(audio.paused)audio.play().then(sync).catch(()=>{});else audio.pause();
    sync();
  }
  function setExpanded(open){
    const panel=$('expanded-player');
    panel.classList.toggle('open',open);
    panel.setAttribute('aria-hidden',open?'false':'true');
    document.body.classList.toggle('player-expanded',open);
  }
  function toggleShuffle(){
    shuffled=!shuffled;const now=queue[current];
    queue=shuffled?[...tracks].sort(()=>Math.random()-.5):[...tracks];
    current=now?queue.findIndex(t=>t.id===now.id):-1;sync();
  }
  function seekTo(value){if(audio.duration)audio.currentTime=audio.duration*Number(value)/1000;}
  function updateTimeline(){
    const elapsed=fmt(audio.currentTime), duration=fmt(audio.duration);
    $('elapsed').textContent=elapsed;$('duration').textContent=duration;
    $('expanded-elapsed').textContent=elapsed;$('expanded-duration').textContent=duration;
    const progress=audio.duration?audio.currentTime/audio.duration*1000:0;
    $('seek').value=String(progress);$('expanded-seek').value=String(progress);
    $('mini-progress').style.width=(audio.duration?progress/10:0)+'%';
  }
  async function load(){
    try{const r=await fetch('api.php',{headers:{Accept:'application/json'}});if(!r.ok)throw new Error('Library request failed');const data=await r.json();tracks=Array.isArray(data.tracks)?data.tracks:[];queue=[...tracks];render();}
    catch(e){list.replaceChildren();const p=document.createElement('p');p.className='empty';p.textContent='Could not load the music library. Please refresh in a moment.';list.append(p);$('count').textContent='Unavailable';}
  }
  search.addEventListener('input',render);
  $('toggle').addEventListener('click',togglePlayback);$('expanded-toggle').addEventListener('click',togglePlayback);
  $('next').addEventListener('click',next);$('expanded-next').addEventListener('click',next);
  $('prev').addEventListener('click',previous);$('expanded-prev').addEventListener('click',previous);
  $('shuffle').addEventListener('click',toggleShuffle);$('expanded-shuffle').addEventListener('click',toggleShuffle);
  $('seek').addEventListener('input',e=>seekTo(e.target.value));
  $('expanded-seek').addEventListener('input',e=>seekTo(e.target.value));
  $('volume').addEventListener('input',e=>{$('expanded-volume').value=e.target.value;audio.volume=Number(e.target.value);});
  $('expanded-volume').addEventListener('input',e=>{$('volume').value=e.target.value;audio.volume=Number(e.target.value);});
  audio.volume=.8;
  audio.addEventListener('timeupdate',updateTimeline);
  audio.addEventListener('loadedmetadata',updateTimeline);
  audio.addEventListener('play',sync);audio.addEventListener('pause',sync);audio.addEventListener('ended',next);
  $('collapse-player').addEventListener('click',()=>setExpanded(false));
  $('mini-now').addEventListener('click',()=>setExpanded(true));
  $('mini-now').addEventListener('keydown',e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();setExpanded(true);}});
  const mini=$('mini-player');
  mini.addEventListener('pointerdown',e=>{
    if(e.target.closest('button,input,label')){pointerStart=null;return;}
    pointerStart={x:e.clientX,y:e.clientY};
    if(mini.setPointerCapture)mini.setPointerCapture(e.pointerId);
  });
  mini.addEventListener('pointerup',e=>{
    if(!pointerStart)return;
    const dy=e.clientY-pointerStart.y;pointerStart=null;
    if(dy < -35)setExpanded(true);
  });
  mini.addEventListener('click',e=>{if(!e.target.closest('button,input,label'))setExpanded(true);});
  const expanded=$('expanded-player');let expandedStart=null;
  expanded.addEventListener('pointerdown',e=>{if(e.target.closest('button,input,label')){expandedStart=null;return;}expandedStart={y:e.clientY};});
  expanded.addEventListener('pointerup',e=>{if(expandedStart&&e.clientY-expandedStart.y>55)setExpanded(false);expandedStart=null;});
  const saved=localStorage.getItem('rivune-theme');if(saved==='light')document.documentElement.dataset.theme='light';
  $('theme').addEventListener('click',()=>{const v=document.documentElement.dataset.theme==='light'?'dark':'light';document.documentElement.dataset.theme=v;localStorage.setItem('rivune-theme',v);});
  load();updateTimeline();
})();