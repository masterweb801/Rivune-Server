(() => {
  const audio=document.getElementById('audio'), list=document.getElementById('tracks');
  const search=document.getElementById('search');
  let tracks=[], queue=[], current=-1, shuffled=false, pointerStart=null;
  let nativeMode=false, nativePlaybackState=null;
  const $=id=>document.getElementById(id);
  const fmt=n=>Number.isFinite(n)&&n>0?Math.floor(n/60)+':'+String(Math.floor(n%60)).padStart(2,'0'):'0:00';

  function snapshot(){
    const t=queue[current]||null;
    return {
      contract:'rivune.playback',version:1,
      tracks:tracks.map(track=>({...track})),
      queueTrackIds:queue.map(track=>Number(track.id)),
      currentTrackId:t?Number(t.id):null,currentIndex:current,
      shuffleEnabled:shuffled,
      isPlaying:nativeMode?!!nativePlaybackState?.isPlaying:!audio.paused,
      positionMs:nativeMode?Number(nativePlaybackState?.positionMs||0):Math.round((audio.currentTime||0)*1000),
      durationMs:nativeMode?Number(nativePlaybackState?.durationMs||0):Math.round((audio.duration||0)*1000),
      volume:nativeMode?Number(nativePlaybackState?.volume??0.8):audio.volume
    };
  }
  function sendNative(type,payload={}){
    if(!nativeMode||!window.RivuneNative||typeof window.RivuneNative.postMessage!=='function')return false;
    try{window.RivuneNative.postMessage(JSON.stringify({contract:'rivune.playback',version:1,type,payload}));return true;}
    catch(error){console.warn('RIVUNE native bridge message failed',error);return false;}
  }
  function publishQueue(){sendNative('queueChanged',snapshot());}
  function render(){
    const q=search.value.trim().toLowerCase();
    const shown=tracks.filter(t=>(t.title+' '+t.artist+' '+t.album).toLowerCase().includes(q));
    $('count').textContent=tracks.length+' '+(tracks.length===1?'track':'tracks');list.replaceChildren();
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
    const t=queue[current],playing=nativeMode?!!nativePlaybackState?.isPlaying:!audio.paused;
    $('now-title').textContent=t?.title||'Nothing playing';$('now-artist').textContent=t?.artist||'Choose a track';
    $('expanded-title').textContent=t?.title||'Nothing playing';$('expanded-artist').textContent=t?.artist||'Choose a track';
    $('toggle').textContent=playing?'Ⅱ':'▶';$('expanded-toggle').textContent=playing?'Ⅱ':'▶';
    $('toggle').setAttribute('aria-label',playing?'Pause':'Play');$('expanded-toggle').setAttribute('aria-label',playing?'Pause':'Play');
    $('expanded-shuffle').classList.toggle('selected',shuffled);
    $('expanded-shuffle').setAttribute('aria-pressed',shuffled?'true':'false');
    render();
  }
  function playTrack(t){
    if(shuffled){queue=[t,...tracks.filter(x=>x.id!==t.id).sort(()=>Math.random()-.5)];current=0;}
    else{queue=[...tracks];current=queue.findIndex(x=>x.id===t.id);}
    if(nativeMode){
      nativePlaybackState={...(nativePlaybackState||{}),isPlaying:true,positionMs:0};
      sendNative('playTrack',{track:{...t},queueTrackIds:queue.map(x=>Number(x.id)),currentIndex:current});
      sync();updateTimeline();publishQueue();return;
    }
    audio.src=t.stream_url;audio.play().then(sync).catch(sync);sync();
  }
  function next(){
    if(!queue.length)return;
    if(nativeMode){sendNative('next');return;}
    current=(current+1)%queue.length;audio.src=queue[current].stream_url;audio.play().catch(()=>{});sync();
  }
  function previous(){
    if(nativeMode){if(Number(nativePlaybackState?.positionMs||0)>3000){sendNative('seek',{positionMs:0});return;}sendNative('previous');return;}
    if(audio.currentTime>3){audio.currentTime=0;return;}
    if(!queue.length)return;current=(current-1+queue.length)%queue.length;audio.src=queue[current].stream_url;audio.play().catch(()=>{});sync();
  }
  function togglePlayback(){
    if(nativeMode){
      if(current<0&&queue.length){playTrack(queue[0]);return;}
      sendNative(nativePlaybackState?.isPlaying?'pause':'play');return;
    }
    if(!audio.src&&tracks.length){playTrack(tracks[0]);return;}
    if(audio.paused)audio.play().then(sync).catch(()=>{});else audio.pause();sync();
  }
  function setExpanded(open){
    const panel=$('expanded-player');panel.classList.toggle('open',open);
    panel.setAttribute('aria-hidden',open?'false':'true');document.body.classList.toggle('player-expanded',open);
  }
  function toggleShuffle(){
    shuffled=!shuffled;const now=queue[current]||null;
    if(shuffled){const rest=tracks.filter(t=>!now||t.id!==now.id).sort(()=>Math.random()-.5);queue=now?[now,...rest]:rest;current=now?0:-1;}
    else{queue=[...tracks];current=now?queue.findIndex(t=>t.id===now.id):-1;}
    if(nativeMode){sendNative('shuffleChanged',{enabled:shuffled});publishQueue();}
    sync();
  }
  function playRandom(){
    if(!tracks.length)return;
    let choices=tracks;if(tracks.length>1&&queue[current])choices=tracks.filter(t=>t.id!==queue[current].id);
    playTrack(choices[Math.floor(Math.random()*choices.length)]);
  }
  function seekTo(value){
    if(nativeMode){const duration=Number(nativePlaybackState?.durationMs||0);if(duration)sendNative('seek',{positionMs:Math.round(duration*Number(value)/1000)});return;}
    if(audio.duration)audio.currentTime=audio.duration*Number(value)/1000;
  }
  function updateTimeline(){
    const position=nativeMode?Number(nativePlaybackState?.positionMs||0)/1000:audio.currentTime;
    const duration=nativeMode?Number(nativePlaybackState?.durationMs||0)/1000:audio.duration;
    $('expanded-elapsed').textContent=fmt(position);$('expanded-duration').textContent=fmt(duration);
    const progress=duration?position/duration*1000:0;$('expanded-seek').value=String(progress);
    $('mini-progress').style.width=(duration?progress/10:0)+'%';
  }
  function setNativeMode(enabled){
    const available=!!(window.RivuneNative&&typeof window.RivuneNative.postMessage==='function');
    nativeMode=!!enabled&&available;
    if(nativeMode){
      audio.pause();audio.removeAttribute('src');audio.load();
      nativePlaybackState=nativePlaybackState||{isPlaying:false,positionMs:0,durationMs:0};
      sendNative('webReady',snapshot());publishQueue();
    }else{nativePlaybackState=null;sync();updateTimeline();}
    return nativeMode;
  }
  function onNativeStateChanged(input){
    if(!nativeMode)return false;
    let state=input;
    if(typeof input==='string'){try{state=JSON.parse(input);}catch(_){return false;}}
    if(!state||typeof state!=='object')return false;
    if(Array.isArray(state.queueTrackIds)){
      const byId=new Map(tracks.map(t=>[String(t.id),t]));
      const nextQueue=state.queueTrackIds.map(id=>byId.get(String(id))).filter(Boolean);
      if(nextQueue.length)queue=nextQueue;
    }
    if(state.trackId!==undefined&&state.trackId!==null){
      const idx=queue.findIndex(t=>String(t.id)===String(state.trackId));
      if(idx>=0)current=idx;
    }else if(Number.isInteger(state.currentIndex)&&state.currentIndex>=0&&state.currentIndex<queue.length){current=state.currentIndex;}
    if(typeof state.shuffleEnabled==='boolean')shuffled=state.shuffleEnabled;
    nativePlaybackState={
      isPlaying:!!state.isPlaying,
      positionMs:Math.max(0,Number(state.positionMs)||0),
      durationMs:Math.max(0,Number(state.durationMs)||0),
      volume:Math.min(1,Math.max(0,Number(state.volume??0.8)))
    };
    $('expanded-volume').value=String(nativePlaybackState.volume);sync();updateTimeline();return true;
  }
  function onNativeError(input){
    const message=typeof input==='string'?input:String(input?.message||'Native playback error');
    console.warn('RIVUNE native playback:',message);
    return true;
  }
  window.RivunePlayback={
    contractVersion:1,
    getState:snapshot,
    setNativeMode,
    onNativeStateChanged,
    onNativeError
  };
  async function load(){
    try{
      const r=await fetch('api.php',{headers:{Accept:'application/json'}});
      if(!r.ok)throw new Error('Library request failed');
      const data=await r.json();tracks=Array.isArray(data.tracks)?data.tracks:[];queue=[...tracks];render();
      if(nativeMode){sendNative('libraryChanged',{tracks:tracks.map(t=>({...t}))});publishQueue();}
    }catch(e){
      list.replaceChildren();const p=document.createElement('p');p.className='empty';
      p.textContent='Could not load the music library. Please refresh in a moment.';list.append(p);$('count').textContent='Unavailable';
    }
  }
  search.addEventListener('input',render);
  $('toggle').addEventListener('click',togglePlayback);$('expanded-toggle').addEventListener('click',togglePlayback);
  $('next').addEventListener('click',next);$('expanded-next').addEventListener('click',next);
  $('prev').addEventListener('click',previous);$('expanded-prev').addEventListener('click',previous);
  $('expanded-shuffle').addEventListener('click',toggleShuffle);$('random-play').addEventListener('click',playRandom);
  $('expanded-seek').addEventListener('input',e=>seekTo(e.target.value));
  $('expanded-volume').addEventListener('input',e=>{
    const value=Number(e.target.value);
    if(nativeMode)sendNative('setVolume',{volume:value});else audio.volume=value;
  });
  audio.volume=.8;
  audio.addEventListener('timeupdate',updateTimeline);audio.addEventListener('loadedmetadata',updateTimeline);
  audio.addEventListener('play',sync);audio.addEventListener('pause',sync);audio.addEventListener('ended',()=>{if(!nativeMode)next();});
  $('collapse-player').addEventListener('click',()=>setExpanded(false));
  $('mini-now').addEventListener('click',()=>setExpanded(true));
  $('mini-now').addEventListener('keydown',e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();setExpanded(true);}});
  const mini=$('mini-player');
  mini.addEventListener('pointerdown',e=>{pointerStart={x:e.clientX,y:e.clientY,id:e.pointerId};if(mini.setPointerCapture)mini.setPointerCapture(e.pointerId);});
  mini.addEventListener('pointerup',e=>{if(!pointerStart)return;const dy=e.clientY-pointerStart.y;pointerStart=null;if(dy < -28){e.preventDefault();setExpanded(true);}});
  mini.addEventListener('pointercancel',()=>{pointerStart=null;});
  mini.addEventListener('click',e=>{if(!e.target.closest('button,input,label'))setExpanded(true);});
  const expanded=$('expanded-player');let expandedStart=null;
  expanded.addEventListener('pointerdown',e=>{if(e.target.closest('input')){expandedStart=null;return;}expandedStart={y:e.clientY,id:e.pointerId};if(expanded.setPointerCapture)expanded.setPointerCapture(e.pointerId);});
  expanded.addEventListener('pointerup',e=>{if(expandedStart&&e.clientY-expandedStart.y>35){e.preventDefault();setExpanded(false);}expandedStart=null;});
  expanded.addEventListener('pointercancel',()=>{expandedStart=null;});
  const saved=localStorage.getItem('rivune-theme');if(saved==='light')document.documentElement.dataset.theme='light';
  $('theme').addEventListener('click',()=>{const v=document.documentElement.dataset.theme==='light'?'dark':'light';document.documentElement.dataset.theme=v;localStorage.setItem('rivune-theme',v);});
  load();updateTimeline();
})();