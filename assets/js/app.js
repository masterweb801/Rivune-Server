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
  headerOverlay.append(publicTop);

  // Keep search in its natural library position at the top of the page.
  // Only bring the real search field into the floating header while scrolling up.
  let searchInOverlay=false;
  function moveSearchToOverlay(visible){
    if(visible&&!searchInOverlay){
      headerOverlay.append(publicSearch);
      searchInOverlay=true;
    }else if(!visible&&searchInOverlay){
      searchSpacer.after(publicSearch);
      searchInOverlay=false;
    }
  }

  let previousPageY=window.pageYOffset||document.documentElement.scrollTop||0;
  let directionTravel=0;
  const directionThreshold=6;
  function handlePublicScroll(){
    const currentPageY=window.pageYOffset||document.documentElement.scrollTop||document.body.scrollTop||0;
    const delta=currentPageY-previousPageY;
    previousPageY=currentPageY;
    if(document.body.classList.contains('player-expanded')){
      document.body.classList.remove('nav-hidden');
      moveSearchToOverlay(false);
      directionTravel=0;
      return;
    }
    if(currentPageY<=20){
      document.body.classList.remove('nav-hidden');
      moveSearchToOverlay(false);
      directionTravel=0;
      return;
    }
    if(!delta)return;
    directionTravel= Math.sign(directionTravel)===Math.sign(delta) ? directionTravel+delta : delta;
    if(directionTravel>=directionThreshold){
      document.body.classList.add('nav-hidden');
      moveSearchToOverlay(false);
      directionTravel=0;
    }else if(directionTravel<=-directionThreshold){
      moveSearchToOverlay(true);
      document.body.classList.remove('nav-hidden');
      directionTravel=0;
    }
  }
  document.addEventListener('scroll',handlePublicScroll,{passive:true,capture:true});

  let tracks=[], displayTracks=[], queue=[], current=-1, playbackMode='loop-all', shuffled=false, pointerStart=null;
  let nativeMode=false, nativePlaybackState=null;
  const $=id=>document.getElementById(id);
  const fmt=n=>Number.isFinite(n)&&n>0?Math.floor(n/60)+':'+String(Math.floor(n%60)).padStart(2,'0'):'0:00';
  const iconPaths={
    music:'<path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/>',
    play:'<path d="m8 5 11 7-11 7z" fill="currentColor" stroke="none"/>',
    pause:'<path d="M8 5h3v14H8zM15 5h3v14h-3z" fill="currentColor" stroke="none"/>',
    previous:'<path d="M6 5v14"/><path d="m19 5-10 7 10 7z"/>',
    next:'<path d="M18 5v14"/><path d="m5 5 10 7-10 7z"/>',
    repeat:'<path d="m17 2 4 4-4 4"/><path d="M3 11V9a3 3 0 0 1 3-3h15"/><path d="m7 22-4-4 4-4"/><path d="M21 13v2a3 3 0 0 1-3 3H3"/>',
    repeatOne:'<path d="m17 2 4 4-4 4"/><path d="M3 11V9a3 3 0 0 1 3-3h15"/><path d="m7 22-4-4 4-4"/><path d="M21 13v2a3 3 0 0 1-3 3H3"/><path d="M12 10v5m-1.5-3.5L12 10l1.5 1.5"/>',
    shuffle:'<path d="m18 14 4 4-4 4"/><path d="M2 18h2.5a6 6 0 0 0 4.7-2.3l5.6-7.4A6 6 0 0 1 19.5 6H22"/><path d="m18 2 4 4-4 4"/><path d="M2 6h2.5a6 6 0 0 1 3.2.9"/>',
    moon:'<path d="M20.9 13A9 9 0 0 1 11 3.1 9 9 0 1 0 20.9 13Z"/>',
    sun:'<circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M4.93 4.93l1.42 1.42m11.3 11.3 1.42 1.42M2 12h2m16 0h2M4.93 19.07l1.42-1.42m11.3-11.3 1.42-1.42"/>'
  };
  function icon(name,size=20){
    const svg=document.createElementNS('http://www.w3.org/2000/svg','svg');
    svg.setAttribute('class','ui-icon');svg.setAttribute('viewBox','0 0 24 24');
    svg.setAttribute('width',String(size));svg.setAttribute('height',String(size));
    svg.setAttribute('fill','none');svg.setAttribute('stroke','currentColor');
    svg.setAttribute('stroke-width','1.8');svg.setAttribute('stroke-linecap','round');
    svg.setAttribute('stroke-linejoin','round');svg.setAttribute('aria-hidden','true');
    svg.innerHTML=iconPaths[name]||iconPaths.music;return svg;
  }
  function setArtwork(element,url,alt,priority=false){
    if(!element)return;
    const normalizedUrl=url?String(url):'';
    if(element.dataset.artworkUrl===normalizedUrl)return;
    element.dataset.artworkUrl=normalizedUrl;
    element.replaceChildren();
    if(url){
      const image=document.createElement('img');image.src=String(url);image.alt=alt||'Track artwork';image.loading=priority?'eager':'lazy';image.decoding='async';if(priority)image.fetchPriority='high';
      image.addEventListener('error',()=>{element.replaceChildren();element.append(icon('music',22));},{once:true});
      element.append(image);
    }else{element.append(icon('music',22));}
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
      repeatMode:playbackMode,
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
  let page=1,hasMore=false,loadingPage=false,searchTimer=null,searchQuery='',libraryTotal=0,fullLibraryLoaded=false,requestSequence=0,activeRequestController=null;
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
      const play=document.createElement('span');play.className='track-play';play.append(icon('play',17));info.append(title,sub);row.append(art,info,play);row.addEventListener('click',()=>playTrack(t));list.append(row);
    });
  }
  async function fetchPage({reset=false}={}){
    // A new search must supersede an older library request, even if the
    // initial page or a previous search is still loading.
    if(loadingPage&&!reset)return;
    if(reset){
      if(activeRequestController)activeRequestController.abort();
      loadingPage=false;
      requestSequence++;
    }
    const requestId=++requestSequence;
    const controller=new AbortController();
    activeRequestController=controller;
    loadingPage=true;$('load-more').hidden=true;
    if(reset){page=1;hasMore=false;displayTracks=[];if(!searchQuery)tracks=[];skeletonRows(6);}else $('library-status').textContent='Loading more songs…';
    try{
      const params=new URLSearchParams({page:String(page),limit:'50',q:searchQuery,sort:'popular'});
      const response=await fetch('api.php?'+params.toString(),{headers:{Accept:'application/json'},signal:controller.signal});
      if(!response.ok)throw new Error('Library request failed (HTTP '+response.status+')');
      const data=await response.json();
      if(requestId!==requestSequence)return;
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
    }catch(error){
      if(error&&error.name==='AbortError')return;
      if(requestId!==requestSequence)return;
      if(reset){list.replaceChildren();const p=document.createElement('p');p.className='empty';p.textContent='Could not load the music library. Please refresh in a moment.';list.append(p);$('count').textContent='Unavailable';}
      $('library-status').textContent='Could not load songs. Please try again.';$('load-more').hidden=false;
      console.error('RIVUNE library request failed:',error);
    }finally{
      if(requestId===requestSequence){loadingPage=false;activeRequestController=null;}
    }
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
    $('toggle').replaceChildren(icon(playing?'pause':'play',20));$('expanded-toggle').replaceChildren(icon(playing?'pause':'play',22));
    $('toggle').setAttribute('aria-label',playing?'Pause':'Play');$('expanded-toggle').setAttribute('aria-label',playing?'Pause':'Play');
    const modeButton=$('expanded-shuffle');
    const modePresentation={'loop-all':{icon:'repeat',label:'Loop all'},'loop-one':{icon:'repeatOne',label:'Loop one'},'shuffle':{icon:'shuffle',label:'Shuffle'}}[playbackMode];
    modeButton.replaceChildren(icon(modePresentation.icon,20));
    modeButton.setAttribute('aria-label',modePresentation.label);
    modeButton.setAttribute('title',modePresentation.label);
    modeButton.setAttribute('aria-pressed',playbackMode==='shuffle'?'true':'false');
    modeButton.dataset.mode=playbackMode;
    modeButton.classList.toggle('selected',playbackMode!=='loop-all');
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
    panel.setAttribute('aria-hidden',open?'false':'true');
    document.body.classList.toggle('player-expanded',open);
    if(open){
      document.body.classList.remove('nav-hidden');
      moveSearchToOverlay(false);
      directionTravel=0;
    }
  }
  async function cyclePlaybackMode(){
    const modes=['loop-all','loop-one','shuffle'];
    const nextMode=modes[(modes.indexOf(playbackMode)+1)%modes.length];
    if(nextMode==='shuffle'&&!fullLibraryLoaded){
      try{await ensureFullLibrary();}catch(error){console.warn('Full-library shuffle unavailable',error);return;}
    }
    const now=queue[current]||null;
    playbackMode=nextMode;
    shuffled=playbackMode==='shuffle';
    if(playbackMode==='shuffle'){
      const rest=shuffledCopy(tracks.filter(track=>!now||String(track.id)!==String(now.id)));
      queue=now?[now,...rest]:rest;
      current=now?0:-1;
    }else{
      queue=[...tracks];
      current=now?queue.findIndex(track=>String(track.id)===String(now.id)):-1;
    }
    if(nativeMode){
      sendNative('repeatModeChanged',{mode:playbackMode});
      sendNative('shuffleChanged',{enabled:shuffled});
      publishQueue();
    }
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
    if(typeof state.repeatMode==='string'&&['loop-all','loop-one','shuffle'].includes(state.repeatMode)){
      playbackMode=state.repeatMode;
      shuffled=playbackMode==='shuffle';
    }else if(typeof state.shuffleEnabled==='boolean'){
      shuffled=state.shuffleEnabled;
      if(shuffled)playbackMode='shuffle';
    }
    nativePlaybackState={
      isPlaying:!!state.isPlaying,
      positionMs:Math.max(0,Number(state.positionMs)||0),
      durationMs:Math.max(0,Number(state.durationMs)||0),
      volume:Math.min(1,Math.max(0,Number(state.volume??0.8)))
    };
    const activeNativeTrackId=queue[current]?.id==null?null:String(queue[current].id);
    if(activeNativeTrackId&&activeNativeTrackId!==previousNativeTrackId)resetListenTracker(activeNativeTrackId,nativePlaybackState.positionMs);
    accumulateListening(activeNativeTrackId,nativePlaybackState.positionMs,nativePlaybackState.isPlaying);
    sync();updateTimeline();return true;
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
  $('expanded-shuffle').addEventListener('click',cyclePlaybackMode);$('random-play').addEventListener('click',playRandom);
  $('expanded-seek').addEventListener('input',e=>seekTo(e.target.value));
  audio.volume=.8;
  audio.addEventListener('timeupdate',()=>{const id=queue[current]?.id;accumulateListening(id,audio.currentTime*1000,!audio.paused);updateTimeline();});audio.addEventListener('seeking',()=>{listenTracker.lastPositionMs=audio.currentTime*1000;});audio.addEventListener('seeked',()=>{listenTracker.lastPositionMs=audio.currentTime*1000;});audio.addEventListener('loadedmetadata',updateTimeline);
  audio.addEventListener('play',()=>{const id=queue[current]?.id;if(id!=null&&listenTracker.trackId!==String(id))resetListenTracker(id,audio.currentTime*1000);sync();});audio.addEventListener('pause',sync);audio.addEventListener('ended',()=>{
    if(nativeMode)return;
    if(playbackMode==='loop-one'){
      audio.currentTime=0;
      audio.play().catch(()=>{});
      return;
    }
    next();
  });
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
  const themeButton=$('theme');
  function syncThemeIcon(){const light=document.documentElement.dataset.theme==='light';themeButton.replaceChildren(icon(light?'sun':'moon',19));themeButton.setAttribute('aria-label',light?'Switch to dark theme':'Switch to light theme');}
  syncThemeIcon();
  themeButton.addEventListener('click',()=>{const v=document.documentElement.dataset.theme==='light'?'dark':'light';document.documentElement.dataset.theme=v;localStorage.setItem('rivune-theme',v);syncThemeIcon();});
  load();updateTimeline();
})();