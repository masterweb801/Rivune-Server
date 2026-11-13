(() => {
  const audio=document.getElementById('audio'), list=document.getElementById('tracks');
  const search=document.getElementById('search'); let tracks=[], queue=[], current=-1, shuffled=false;
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
    const t=queue[current];$('now-title').textContent=t?.title||'Nothing playing';$('now-artist').textContent=t?.artist||'Choose a track';
    $('toggle').textContent=audio.paused?'▶':'Ⅱ';$('toggle').setAttribute('aria-label',audio.paused?'Play':'Pause');
    $('shuffle').classList.toggle('selected',shuffled);render();
  }
  function playTrack(t){queue=shuffled?[...tracks].sort(()=>Math.random()-.5):[...tracks];current=queue.findIndex(x=>x.id===t.id);audio.src=t.stream_url;audio.play().then(sync).catch(sync);sync();}
  function next(){if(!queue.length)return;current=(current+1)%queue.length;audio.src=queue[current].stream_url;audio.play().catch(()=>{});sync();}
  async function load(){
    try{const r=await fetch('api.php',{headers:{Accept:'application/json'}});if(!r.ok)throw new Error('Library request failed');const data=await r.json();tracks=Array.isArray(data.tracks)?data.tracks:[];queue=[...tracks];render();}
    catch(e){list.replaceChildren();const p=document.createElement('p');p.className='empty';p.textContent='Could not load the music library. Please refresh in a moment.';list.append(p);$('count').textContent='Unavailable';}
  }
  search.addEventListener('input',render);
  $('toggle').addEventListener('click',()=>{if(!audio.src&&tracks.length){playTrack(tracks[0]);return;}if(audio.paused)audio.play().then(sync).catch(()=>{});else audio.pause();sync();});
  $('next').addEventListener('click',next);
  $('prev').addEventListener('click',()=>{if(audio.currentTime>3){audio.currentTime=0;return;}if(!queue.length)return;current=(current-1+queue.length)%queue.length;audio.src=queue[current].stream_url;audio.play().catch(()=>{});sync();});
  $('shuffle').addEventListener('click',()=>{shuffled=!shuffled;const now=queue[current];queue=shuffled?[...tracks].sort(()=>Math.random()-.5):[...tracks];current=now?queue.findIndex(t=>t.id===now.id):-1;sync();});
  $('seek').addEventListener('input',e=>{if(audio.duration)audio.currentTime=audio.duration*Number(e.target.value)/1000;});
  $('volume').addEventListener('input',e=>audio.volume=Number(e.target.value));audio.volume=.8;
  audio.addEventListener('timeupdate',()=>{$('elapsed').textContent=fmt(audio.currentTime);$('duration').textContent=fmt(audio.duration);if(audio.duration)$('seek').value=String(audio.currentTime/audio.duration*1000);});
  audio.addEventListener('play',sync);audio.addEventListener('pause',sync);audio.addEventListener('ended',next);
  const saved=localStorage.getItem('rivune-theme');if(saved==='light')document.documentElement.dataset.theme='light';
  $('theme').addEventListener('click',()=>{const v=document.documentElement.dataset.theme==='light'?'dark':'light';document.documentElement.dataset.theme=v;localStorage.setItem('rivune-theme',v);});
  load();
})();