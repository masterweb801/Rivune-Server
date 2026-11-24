(() => {
  const audio=document.getElementById('audio'), list=document.getElementById('tracks');
  const search=document.getElementById('search');
  // Direction-aware public header: navigation and search travel as one unit.
  const publicTop=document.querySelector('.top');
  const publicSearch=document.querySelector('.search');
  const headerOverlay=document.createElement('div');
  headerOverlay.className='scroll-header';
  headerOverlay.setAttribute('aria-label','Navigation and music search');
  const headerSpacer=document.createElement('div');
  headerSpacer.className='top-spacer';
  headerSpacer.setAttribute('aria-hidden','true');
  publicTop.before(headerSpacer);
  const searchSpacer=document.createElement('div');
  searchSpacer.className='search-spacer';
  searchSpacer.setAttribute('aria-hidden','true');
  publicSearch.before(searchSpacer);
  document.body.prepend(headerOverlay);
  headerOverlay.append(publicTop,publicSearch);

  let previousPageY=window.pageYOffset||document.documentElement.scrollTop||0;
  let directionTravel=0;
  const directionThreshold=6;
  function handlePublicScroll(){
    const currentPageY=window.pageYOffset||document.documentElement.scrollTop||document.body.scrollTop||0;
    const delta=currentPageY-previousPageY;
    previousPageY=currentPageY;
    if(document.body.classList.contains('player-expanded')){
      document.body.classList.add('nav-hidden');
      directionTravel=0;
      return;
    }
    if(currentPageY<=20){
      document.body.classList.remove('nav-hidden');
      directionTravel=0;
      return;
    }
    if(!delta)return;
    directionTravel= Math.sign(directionTravel)===Math.sign(delta) ? directionTravel+delta : delta;
    if(directionTravel>=directionThreshold){
      document.body.classList.add('nav-hidden');
      directionTravel=0;
    }else if(directionTravel<=-directionThreshold){
      document.body.classList.remove('nav-hidden');
      directionTravel=0;
    }
  }
  document.addEventListener('scroll',handlePublicScroll,{passive:true,capture:true});

  let tracks=[], displayTracks=[], queue=[], current=-1, shuffled=false, pointerStart=null;
  let nativeMode=false, nativePlaybackState=null;
  const $=id=>document.getElementById(id);
  const fmt=n=>Number.isFinite(n)&&n>0?Math.floor(n/60)+':'+String(Math.floor(n%60)).padStart(2,'0'):'0:00';
  function setArtwork(element,url,alt,priority=false){
    if(!element)return;
    const normalizedUrl=url?String(url):'';
    if(element.dataset.artworkUrl===normalizedUrl)return;
    element.dataset.artworkUrl=normalizedUrl;
    element.replaceChildren();
    if(url){
      const image=document.createElement('img');image.src=String(url);image.alt=alt||'Track artwork';image.loading=priority?'eager':'lazy';image.decoding='async';if(priority)image.fetchPriority='high';
      image.addEventListener('error',()=>{element.replaceChildren();const fallback=document.createElement('span');fallback.textContent='♫';fallback.setAttribute('aria-hidden','true');element.append(fallback);},{once:true});
      element.append(image);
    }else{const fallback=document.createElement('span');fallback.textContent='♫';fallback.setAttribute('aria-hidden','true');element.append(fallback);}
  }

  function shuffledCopy(items){
    const result=items.slice();
    for(let i=result.length-1;i>0;i--){const j=Math.floor(Math.random()*(i+1));[result[i],result[j]]=[result[j],result[i]];}
    return result;
  }
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
  let page=1,hasMore=false,loadingPage=false,searchTimer=null,searchQuery='',libraryTotal=0,fullLibraryLoaded=false;
  function skeletonRows(count=5){list.innerHTML=Array.from({length:count},()=>'<div class="skeleton-row" aria-hidden="true"><span></span><div><i></i><b></b></div></div>').join('');}
  function render(){
    const source=searchQuery?displayTracks:tracks,shown=source;
    $('count').textContent=(searchQuery?libraryTotal:libraryTotal)+' '+(libraryTotal===1?'track':'tracks');list.replaceChildren();
    if(!shown.length){const p=document.createElement('p');p.className='empty';p.textContent=searchQuery?'No tracks match your search.':(libraryTotal?'No songs loaded yet.':'The library is empty for now. Check back soon.');list.append(p);return;}
    shown.forEach(t=>{
      const row=document.createElement('button');row.type='button';row.dataset.trackId=String(t.id);row.className='track'+(queue[current]?.id===t.id?' active':'');
      const art=document.createElement('span');art.className='track-art';setArtwork(art,t.artwork_url,(t.title||'Track')+' artwork');
      const info=document.createElement('span');info.className='track-meta';const title=document.createElement('strong');title.textContent=String(t.title||'Untitled');
      const sub=document.createElement('small');sub.textContent=String(t.artist||'Unknown artist')+' · '+String(t.album||'Unknown album');
      const play=document.createElement('span');play.className='track-play';play.textContent='▶';info.append(title,sub);row.append(art,info,play);row.addEventListener('click',()=>playTrack(t));list.append(row);
    });
  }
  async function fetchPage({reset=false}={}){
    if(loadingPage||(!hasMore&&!reset&&page>1))return;
    loadingPage=true;$('load-more').hidden=true;
    if(reset){page=1;hasMore=false;displayTracks=[];if(!searchQuery)tracks=[];skeletonRows(6);}else $('library-status').textContent='Loading more songs…';
    try{
      const params=new URLSearchParams({page:String(page),limit:'50',q:searchQuery,sort:'popular'});
      const response=await fetch('api.php?'+params.toString(),{headers:{Accept:'application/json'}});if(!response.ok)throw new Error('Library request failed');
      const data=await response.json();
      if(nativeMode){await ensureFullLibrary();return;}
      const incoming=Array.isArray(data.tracks)?data.tracks:[];
      if(reset)displayTracks=incoming;else{const knownDisplay=new Set(displayTracks.map(t=>String(t.id)));displayTracks.push(...incoming.filter(t=>!knownDisplay.has(String(t.id))));}
      if(!searchQuery){
        if(reset)tracks=incoming;else{const known=new Set(tracks.map(t=>String(t.id)));tracks.push(...incoming.filter(t=>!known.has(String(t.id))));}
        const activeId=queue[current]?.id==null?null:String(queue[current].id);
        const queued=new Set(queue.map(t=>String(t.id)));
        queue.push(...incoming.filter(t=>!queued.has(String(t.id))));
        if(activeId!==null)current=queue.findIndex(t=>String(t.id)===activeId);
      }
      libraryTotal=Number(data.pagination?.total??displayTracks.length);hasMore=!!data.pagination?.has_more;page+=1;fullLibraryLoaded=!hasMore&&!searchQuery;render();
      $('library-status').textContent=hasMore?'Scroll to load more songs.':(libraryTotal+' songs loaded');$('load-more').hidden=!hasMore;
      if(nativeMode){sendNative('libraryChanged',{tracks:tracks.map(track=>({...track}))});publishQueue();}
    }catch(error){if(reset){list.replaceChildren();const p=document.createElement('p');p.className='empty';p.textContent='Could not load the music library. Please refresh in a moment.';list.append(p);$('count').textContent='Unavailable';}$('library-status').textContent='Could not load more songs. Please try again.';$('load-more').hidden=false;}
    finally{loadingPage=false;}
  }
  async function ensureFullLibrary(){
    if(fullLibraryLoaded)return;
    const response=await fetch('api.php',{headers:{Accept:'application/json'}});if(!response.ok)throw new Error('Could not load full library');
    const data=await response.json();const activeId=queue[current]?.id==null?null:String(queue[current].id);tracks=Array.isArray(data.tracks)?data.tracks:[];queue=[...tracks];current=activeId===null?-1:queue.findIndex(t=>String(t.id)===activeId);displayTracks=tracks;libraryTotal=tracks.length;hasMore=false;fullLibraryLoaded=true;render();
    if(nativeMode){sendNative('libraryChanged',{tracks:tracks.map(track=>({...track}))});publishQueue();}
  }
  function load(){fetchPage({reset:true});}
  function updateActiveTrack(){
    const activeId=queue[current]?String(queue[current].id):'';
    list.querySelectorAll('.track[data-track-id]').forEach(row=>row.classList.toggle('active',row.dataset.trackId===activeId));
  }
  function sync(){
    const t=queue[current],playing=nativeMode?!!nativePlaybackState?.isPlaying:!audio.paused;
    $('now-title').textContent=t?.title||'Nothing playing';$('now-artist').textContent=t?.artist||'Choose a track';
    setArtwork($('cover'),t?.artwork_url,(t?.title||'Track')+' artwork',true);
    setArtwork($('expanded-art'),t?.artwork_url,(t?.title||'Track')+' artwork',true);
    $('expanded-title').textContent=t?.title||'Nothing playing';$('expanded-artist').textContent=t?.artist||'Choose a track';
    $('toggle').textContent=playing?'Ⅱ':'▶';$('expanded-toggle').textContent=playing?'Ⅱ':'▶';
    $('toggle').setAttribute('aria-label',playing?'Pause':'Play');$('expanded-toggle').setAttribute('aria-label',playing?'Pause':'Play');
    $('expanded-shuffle').classList.toggle('selected',shuffled);
    $('expanded-shuffle').setAttribute('aria-pressed',shuffled?'true':'false');
    updateActiveTrack();
  }
  const listenTracker={trackId:null,lastPositionMs:null,listenedMs:0,playId:null,recorded:false};
  function resetListenTracker(trackId,positionMs=0){
    listenTracker.trackId=trackId==null?null:String(trackId);
    listenTracker.lastPositionMs=Number.isFinite(Number(positionMs))?Number(positionMs):0;
    listenTracker.listenedMs=0;
    listenTracker.playId=(window.crypto&&crypto.randomUUID)?crypto.randomUUID():String(Date.now())+'-'+Math.random().toString(36).slice(2);
    listenTracker.recorded=false;
  }
  function accumulateListening(trackId,positionMs,isPlaying){
    if(!isPlaying||trackId==null||!Number.isFinite(positionMs))return;
    const id=String(trackId);
    if(listenTracker.trackId!==id)resetListenTracker(id,positionMs);
    if(listenTracker.lastPositionMs===null){listenTracker.lastPositionMs=positionMs;return;}
    const delta=positionMs-listenTracker.lastPositionMs;
    listenTracker.lastPositionMs=positionMs;
    // Ignore seeks, stalls and large timer gaps. Only count plausible forward playback.
    if(delta>0&&delta<=2500&&!listenTracker.recorded){
      listenTracker.listenedMs+=delta;
      if(listenTracker.listenedMs>=30000){
        listenTracker.recorded=true;
        const body=new URLSearchParams({track_id:id,play_id:listenTracker.playId,played_seconds:'30'});
        fetch('play-event.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body,keepalive:true}).catch(()=>{});
      }
    }
  }
  function playTrack(t){
    resetListenTracker(t.id,0);
    if(shuffled){queue=[t,...shuffledCopy(tracks.filter(x=>x.id!==t.id))];current=0;}
    else{queue=[...tracks];if(!queue.some(x=>String(x.id)===String(t.id)))queue.unshift(t);current=queue.findIndex(x=>String(x.id)===String(t.id));}
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
    current=(current+1)%queue.length;resetListenTracker(queue[current].id,0);audio.src=queue[current].stream_url;audio.play().catch(()=>{});sync();
  }
  function previous(){
    if(nativeMode){if(Number(nativePlaybackState?.positionMs||0)>3000){sendNative('seek',{positionMs:0});return;}sendNative('previous');return;}
    if(audio.currentTime>3){audio.currentTime=0;return;}
    if(!queue.length)return;current=(current-1+queue.length)%queue.length;resetListenTracker(queue[current].id,0);audio.src=queue[current].stream_url;audio.play().catch(()=>{});sync();
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
  async function toggleShuffle(){
    if(!fullLibraryLoaded){try{await ensureFullLibrary();}catch(error){console.warn('Full-library shuffle unavailable',error);}}
    shuffled=!shuffled;const now=queue[current]||null;
    if(shuffled){const rest=tracks.filter(t=>!now||t.id!==now.id);
      for(let i=rest.length-1;i>0;i--){const j=Math.floor(Math.random()*(i+1));[rest[i],rest[j]]=[rest[j],rest[i]];}
      queue=now?[now,...rest]:rest;current=now?0:-1;}
    else{queue=[...tracks];current=now?queue.findIndex(t=>t.id===now.id):-1;}
    if(nativeMode){sendNative('shuffleChanged',{enabled:shuffled});publishQueue();}
    sync();
  }
  async function playRandom(){
    if(!fullLibraryLoaded){try{await ensureFullLibrary();}catch(error){return;}}
    if(!tracks.length)return;
    let choices=tracks;if(tracks.length>1&&queue[current])choices=tracks.filter(t=>t.id!==queue[current].id);
    playTrack(choices[Math.floor(Math.random()*choices.length)]);
  }
  function seekTo(value){
    if(nativeMode){const duration=Number(nativePlaybackState?.durationMs||0);if(duration){const target=Math.round(duration*Number(value)/1000);listenTracker.lastPositionMs=target;sendNative('seek',{positionMs:target});}return;}
    if(audio.duration){const target=audio.duration*Number(value)/1000;listenTracker.lastPositionMs=target*1000;audio.currentTime=target;}
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
    const previousNativeTrackId=queue[current]?.id==null?null:String(queue[current].id);
    let state=input;
    if(typeof input==='string'){try{state=JSON.parse(input);}catch(_){return false;}}
    if(!state||typeof state!=='object')return false;
    if(Array.isArray(state.queueTrackIds)){
      const byId=new Map(tracks.map(t=>[String(t.id),t]));
      const nextQueue=state.queueTrackIds.map(id=>byId.get(String(id))).filter(Boolean);
      queue=nextQueue;
      if(!queue.length)current=-1;
    }
    if(state.trackId!==undefined&&state.trackId!==null){
      const idx=queue.findIndex(t=>String(t.id)===String(state.trackId));
      current=idx;
    }else if(state.trackId===null){current=-1;}
    else if(Number.isInteger(state.currentIndex)&&state.currentIndex>=0&&state.currentIndex<queue.length){current=state.currentIndex;}
    if(typeof state.shuffleEnabled==='boolean')shuffled=state.shuffleEnabled;
    nativePlaybackState={
      isPlaying:!!state.isPlaying,
      positionMs:Math.max(0,Number(state.positionMs)||0),
      durationMs:Math.max(0,Number(state.durationMs)||0),
      volume:Math.min(1,Math.max(0,Number(state.volume??0.8)))
    };
    const activeNativeTrackId=queue[current]?.id==null?null:String(queue[current].id);
    if(activeNativeTrackId&&activeNativeTrackId!==previousNativeTrackId)resetListenTracker(activeNativeTrackId,nativePlaybackState.positionMs);
    accumulateListening(activeNativeTrackId,nativePlaybackState.positionMs,nativePlaybackState.isPlaying);
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
  search.addEventListener('input',()=>{searchQuery=search.value.trim();clearTimeout(searchTimer);searchTimer=setTimeout(()=>fetchPage({reset:true}),250);});
  $('load-more').addEventListener('click',()=>fetchPage());
  const sentinel=$('load-more-sentinel');
  if('IntersectionObserver' in window){const observer=new IntersectionObserver(entries=>{if(entries.some(entry=>entry.isIntersecting)&&hasMore&&!loadingPage)fetchPage();},{rootMargin:'700px 0px'});observer.observe(sentinel);}
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
  audio.addEventListener('timeupdate',()=>{const id=queue[current]?.id;accumulateListening(id,audio.currentTime*1000,!audio.paused);updateTimeline();});audio.addEventListener('seeking',()=>{listenTracker.lastPositionMs=audio.currentTime*1000;});audio.addEventListener('seeked',()=>{listenTracker.lastPositionMs=audio.currentTime*1000;});audio.addEventListener('loadedmetadata',updateTimeline);
  audio.addEventListener('play',()=>{const id=queue[current]?.id;if(id!=null&&listenTracker.trackId!==String(id))resetListenTracker(id,audio.currentTime*1000);sync();});audio.addEventListener('pause',sync);audio.addEventListener('ended',()=>{if(!nativeMode)next();});
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