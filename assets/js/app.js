(() => {
  const audio=document.getElementById('audio'), list=document.getElementById('tracks');
  const search=document.getElementById('search');
  // Fixed navigation with an on-demand search overlay.
  const publicTop=document.querySelector('.top');
  const publicSearch=document.querySelector('.search');
  publicSearch.hidden=true;
  const headerOverlay=document.createElement('div');
  headerOverlay.className='scroll-header';
  headerOverlay.setAttribute('aria-label','Navigation and music search');
  const headerSpacer=document.createElement('div');
  headerSpacer.className='top-spacer';
  headerSpacer.setAttribute('aria-hidden','true');
  publicTop.before(headerSpacer);
  document.body.prepend(headerOverlay);
  headerOverlay.append(publicTop,publicSearch);
  const searchToggle=document.getElementById('search-toggle');
  const searchClose=document.getElementById('search-close');
  function clearSearchOnClose(){
    clearTimeout(searchTimer);
    search.value='';
    searchQuery='';
    if(!searchDirty)return;
    searchDirty=false;
    if(searchQuery===''&&activeRequestController&&activeRequestQuery==='')return;
    if(searchQuery===''&&hasFetchedDefaultPage&&lastFetchedQuery==='')return;
    fetchPage({reset:true});
  }
  function setSearchOpen(open){
    if(!open)clearSearchOnClose();
    headerOverlay.classList.toggle('search-open',open);
    publicSearch.hidden=!open;
    searchToggle.setAttribute('aria-expanded',open?'true':'false');
    searchToggle.setAttribute('aria-label',open?'Close search':'Open search');
    if(open){
      document.body.classList.remove('nav-hidden');
      // Explicitly focus after the overlay is visible so Android WebView opens
      // the keyboard and accepts text on the first tap.
      requestAnimationFrame(()=>{if(headerOverlay.classList.contains('search-open'))search.focus({preventScroll:true});});
    }else{search.blur();}
  }
  searchToggle.addEventListener('click',()=>setSearchOpen(!headerOverlay.classList.contains('search-open')));
  searchClose.addEventListener('click',()=>setSearchOpen(false));
  search.addEventListener('keydown',event=>{if(event.key==='Escape')setSearchOpen(false);});

  // Keep search inline only when the navbar has enough real space for it.
  // This is content-based rather than a fixed viewport breakpoint, like YouTube's navbar.
  let inlineSearchFrame=0;
  function syncSearchLayout(){
    if(inlineSearchFrame)cancelAnimationFrame(inlineSearchFrame);
    inlineSearchFrame=requestAnimationFrame(()=>{
      inlineSearchFrame=0;
      const headerWidth=headerOverlay.clientWidth;
      const wideHeader=window.innerWidth>=1500;
      const horizontalPadding=Math.max(wideHeader?48:32,(headerWidth-(wideHeader?1440:1320))/2);
      const availableWidth=headerWidth-horizontalPadding*2;
      const brandWidth=publicTop.querySelector('.brand')?.getBoundingClientRect().width||0;
      const actions=publicTop.querySelector('.top-actions');
      const actionItems=actions?[...actions.children].filter(item=>item!==searchToggle&&!item.hidden):[];
      const actionGap=actions?parseFloat(getComputedStyle(actions).gap)||0:0;
      const actionsWidth=actionItems.reduce((total,item)=>total+item.getBoundingClientRect().width,0)+Math.max(0,actionItems.length-1)*actionGap;
      const searchMinimum=260;
      const breathingRoom=48;
      const searchGap=24;
      const inline=availableWidth >= brandWidth+actionsWidth+searchMinimum+breathingRoom+searchGap;
      headerOverlay.classList.toggle('search-inline',inline);
      if(inline){
        publicSearch.hidden=false;
        headerOverlay.classList.remove('search-open');
        searchToggle.hidden=true;
        searchToggle.setAttribute('aria-expanded','false');
        searchToggle.setAttribute('aria-label','Search');
      }else{
        searchToggle.hidden=false;
        publicSearch.hidden=!headerOverlay.classList.contains('search-open');
        searchToggle.setAttribute('aria-expanded',headerOverlay.classList.contains('search-open')?'true':'false');
        searchToggle.setAttribute('aria-label',headerOverlay.classList.contains('search-open')?'Close search':'Open search');
      }
    });
  }
  if('ResizeObserver' in window){
    const navbarResizeObserver=new ResizeObserver(syncSearchLayout);
    navbarResizeObserver.observe(headerOverlay);
    navbarResizeObserver.observe(publicTop);
    const brandElement=publicTop.querySelector('.brand');
    const actionsElement=publicTop.querySelector('.top-actions');
    if(brandElement)navbarResizeObserver.observe(brandElement);
    if(actionsElement)navbarResizeObserver.observe(actionsElement);
  }else{
    window.addEventListener('resize',syncSearchLayout,{passive:true});
  }
  syncSearchLayout();

  let previousPageY=window.pageYOffset||document.documentElement.scrollTop||0;
  let directionTravel=0;
  const directionThreshold=6;
  function handlePublicScroll(){
    const currentPageY=window.pageYOffset||document.documentElement.scrollTop||document.body.scrollTop||0;
    const delta=currentPageY-previousPageY;
    previousPageY=currentPageY;
    if(headerOverlay.classList.contains('search-open')){document.body.classList.remove('nav-hidden');directionTravel=0;return;}
    if(document.body.classList.contains('player-expanded')){
      document.body.classList.remove('nav-hidden');
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
  let page=1,hasMore=false,loadingPage=false,searchTimer=null,searchQuery='',searchDirty=false,sortMode='popular',libraryTotal=0,fullLibraryLoaded=false,requestSequence=0,activeRequestController=null,activeRequestQuery='',lastFetchedQuery='',hasFetchedDefaultPage=false;

  const SEARCH_CACHE_STORAGE_KEY='rivune.search-cache.v1';
  const SEARCH_CACHE_TTL=3*24*60*60*1000;
  const SEARCH_CACHE_MAX_ENTRIES=30;
  function searchCacheEntryKey(query,sort,page){
    return JSON.stringify([String(query).trim().toLocaleLowerCase(),String(sort),Number(page),50]);
  }
  function readSearchCache(query,sort,page){
    if(!query)return null;
    try{
      const now=Date.now();
      const cache=JSON.parse(localStorage.getItem(SEARCH_CACHE_STORAGE_KEY)||'{}');
      let changed=false;
      Object.keys(cache).forEach(key=>{
        if(!cache[key]||!Number.isFinite(cache[key].savedAt)||now-cache[key].savedAt>SEARCH_CACHE_TTL){
          delete cache[key];changed=true;
        }
      });
      const key=searchCacheEntryKey(query,sort,page);
      const entry=cache[key];
      if(changed)localStorage.setItem(SEARCH_CACHE_STORAGE_KEY,JSON.stringify(cache));
      return entry&&entry.data?entry.data:null;
    }catch(_){return null;}
  }
  function writeSearchCache(query,sort,page,data){
    if(!query||!data||!Array.isArray(data.tracks))return;
    try{
      const now=Date.now();
      const cache=JSON.parse(localStorage.getItem(SEARCH_CACHE_STORAGE_KEY)||'{}');
      Object.keys(cache).forEach(key=>{
        if(!cache[key]||!Number.isFinite(cache[key].savedAt)||now-cache[key].savedAt>SEARCH_CACHE_TTL)delete cache[key];
      });
      cache[searchCacheEntryKey(query,sort,page)]={savedAt:now,data};
      const entries=Object.entries(cache).sort((a,b)=>b[1].savedAt-a[1].savedAt).slice(0,SEARCH_CACHE_MAX_ENTRIES);
      localStorage.setItem(SEARCH_CACHE_STORAGE_KEY,JSON.stringify(Object.fromEntries(entries)));
    }catch(_){
      try{localStorage.removeItem(SEARCH_CACHE_STORAGE_KEY);}catch(__){}
    }
  }
  function skeletonRows(count=5){list.innerHTML=Array.from({length:count},()=>'<div class="skeleton-row" aria-hidden="true"><span></span><div><i></i><b></b></div></div>').join('');}
  function render(){
    const shown=displayTracks;
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
    const requestedQuery=searchQuery;
    activeRequestQuery=requestedQuery;
    try{
      const cachedData=!nativeMode?readSearchCache(requestedQuery,sortMode,page):null;
      let data=cachedData;
      if(!data){
        const params=new URLSearchParams({page:String(page),limit:'50',q:requestedQuery,sort:sortMode});
        const response=await fetch('api.php?'+params.toString(),{headers:{Accept:'application/json'},signal:controller.signal});
        if(!response.ok)throw new Error('Library request failed (HTTP '+response.status+')');
        data=await response.json();
        if(requestedQuery&&!nativeMode)writeSearchCache(requestedQuery,sortMode,page,data);
      }
      if(requestId!==requestSequence)return;
      lastFetchedQuery=requestedQuery;
      if(requestedQuery==='')hasFetchedDefaultPage=true;
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
      if(requestId===requestSequence){loadingPage=false;activeRequestController=null;activeRequestQuery='';}
    }
  }
  async function ensureFullLibrary(){
    if(fullLibraryLoaded)return;
    const activeId=queue[current]?.id==null?null:String(queue[current].id);
    const allTracks=[];
    const known=new Set();
    let nextPage=1,total=libraryTotal,more=true;
    // api.php is paginated (50 by default, 100 max). Fetch every page before
    // marking the library complete so Shuffle never silently uses page one only.
    while(more){
      const params=new URLSearchParams({page:String(nextPage),limit:'100',sort:sortMode});
      const response=await fetch('api.php?'+params.toString(),{headers:{Accept:'application/json'}});
      if(!response.ok)throw new Error('Could not load full library (page '+nextPage+')');
      const data=await response.json();
      const incoming=Array.isArray(data.tracks)?data.tracks:[];
      incoming.forEach(track=>{const id=String(track.id);if(!known.has(id)){known.add(id);allTracks.push(track);}});
      total=Number(data.pagination?.total??total??allTracks.length);
      more=!!data.pagination?.has_more;
      nextPage++;
      if(nextPage>1000)throw new Error('Library pagination limit exceeded');
      if(!data.pagination)more=false;
    }
    const stillActive=activeId===null?-1:allTracks.findIndex(t=>String(t.id)===activeId);
    tracks=allTracks;
    queue=[...allTracks];
    current=stillActive;
    // Keep the visible list lazy-loaded. Only the playback catalogue/queue
    // becomes complete; displayTracks remains the pages already rendered.
    libraryTotal=total;
    fullLibraryLoaded=true;
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
  const playbackRecovery={trackId:null,retries:0,maxRetries:2,watchdog:null,advanceTimer:null};
  function clearPlaybackRecoveryTimers(){
    clearTimeout(playbackRecovery.watchdog);playbackRecovery.watchdog=null;
    clearTimeout(playbackRecovery.advanceTimer);playbackRecovery.advanceTimer=null;
  }
  function resetPlaybackRecovery(trackId){
    clearPlaybackRecoveryTimers();
    playbackRecovery.trackId=trackId==null?null:String(trackId);
    playbackRecovery.retries=0;
  }
  function reportPlaybackFailure(track,reason){
    if(!track||!track.id)return;
    const payload=new URLSearchParams({
      event:'playback_error',
      track_id:String(track.id),
      reason:reason,
      position_seconds:String(Math.max(0,Math.floor(Number(audio.currentTime)||0))),
      duration_seconds:String(Math.max(0,Math.floor(Number(audio.duration)||0)))
    });
    fetch('play-event.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:payload.toString(),keepalive:true,credentials:'same-origin'}).catch(()=>{});
  }
  function recoverInterruptedPlayback(reason){
    if(nativeMode)return false;
    const track=queue[current];
    if(!track)return false;
    if(playbackRecovery.trackId!==String(track.id))resetPlaybackRecovery(track.id);
    if(playbackRecovery.advanceTimer)return true;
    if(playbackRecovery.retries>=playbackRecovery.maxRetries){
      reportPlaybackFailure(track,reason==='stream ended before duration'?'early_end':(reason==='media error'?'media_error':(reason==='stream stalled'?'stream_stalled':'recovery_failed')));
      $('library-status').textContent='Could not continue "'+(track.title||'this song')+'". Moving to the next song…';
      playbackRecovery.advanceTimer=setTimeout(()=>{playbackRecovery.advanceTimer=null;next();},1800);
      return true;
    }
    clearTimeout(playbackRecovery.watchdog);playbackRecovery.watchdog=null;
    const resumeAt=Math.max(0,Number(audio.currentTime)||0);
    playbackRecovery.retries++;
    $('library-status').textContent='Connection interrupted. Recovering the current song… (attempt '+playbackRecovery.retries+'/'+playbackRecovery.maxRetries+')';
    try{
      const retryUrl=new URL(track.stream_url,document.baseURI);
      retryUrl.searchParams.set('_rivune_retry',String(Date.now()));
      audio.src=retryUrl.toString();
      audio.load();
      audio.addEventListener('loadedmetadata',()=>{
        if(playbackRecovery.trackId!==String(track.id))return;
        if(resumeAt>0&&Number.isFinite(audio.duration)&&audio.duration>0){
          audio.currentTime=Math.min(resumeAt,Math.max(0,audio.duration-0.25));
          listenTracker.lastPositionMs=audio.currentTime*1000;
        }
        audio.play().then(sync).catch(()=>{});
      },{once:true});
      audio.play().then(sync).catch(()=>{});
      sync();
    }catch(error){
      console.warn('RIVUNE could not retry the interrupted stream:',error);
      recoverInterruptedPlayback('retry setup failed');
    }
    return true;
  }
  function armPlaybackWatchdog(){
    if(nativeMode||audio.paused)return;
    clearTimeout(playbackRecovery.watchdog);
    playbackRecovery.watchdog=setTimeout(()=>{
      if(!audio.paused&&audio.readyState<3)recoverInterruptedPlayback('stream stalled');
    },15000);
  }
  function playTrack(t){
    resetPlaybackRecovery(t.id);
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
    resetPlaybackRecovery(null);
    if(nativeMode){sendNative('next');return;}
    current=(current+1)%queue.length;resetListenTracker(queue[current].id,0);audio.src=queue[current].stream_url;audio.play().catch(()=>{});sync();
  }
  function previous(){
    if(nativeMode){if(Number(nativePlaybackState?.positionMs||0)>3000){sendNative('seek',{positionMs:0});return;}sendNative('previous');return;}
    if(audio.currentTime>3){audio.currentTime=0;return;}
    if(!queue.length)return;current=(current-1+queue.length)%queue.length;resetPlaybackRecovery(queue[current].id);resetListenTracker(queue[current].id,0);audio.src=queue[current].stream_url;audio.play().catch(()=>{});sync();
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
    let choices=tracks;if(tracks.length>1&&queue[current])choices=tracks.filter(t=>String(t.id)!==String(queue[current].id));
    playbackMode='shuffle';shuffled=true;
    if(nativeMode){
      sendNative('repeatModeChanged',{mode:'shuffle'});
      sendNative('shuffleChanged',{enabled:true});
    }
    const selected=choices[Math.floor(Math.random()*choices.length)];
    playTrack(selected);
    sync();publishQueue();
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
  search.addEventListener('input',()=>{searchDirty=true;searchQuery=search.value.trim();clearTimeout(searchTimer);searchTimer=setTimeout(()=>fetchPage({reset:true}),350);});
  $('library-sort').addEventListener('change',event=>{sortMode=['alphabetical','newest'].includes(event.target.value)?event.target.value:'popular';fetchPage({reset:true});});
  $('load-more').addEventListener('click',()=>fetchPage());
  const sentinel=$('load-more-sentinel');
  if('IntersectionObserver' in window){const observer=new IntersectionObserver(entries=>{if(entries.some(entry=>entry.isIntersecting)&&hasMore&&!loadingPage)fetchPage();},{rootMargin:'700px 0px'});observer.observe(sentinel);}
  $('toggle').addEventListener('click',togglePlayback);$('expanded-toggle').addEventListener('click',togglePlayback);
  $('next').addEventListener('click',next);$('expanded-next').addEventListener('click',next);
  $('prev').addEventListener('click',previous);$('expanded-prev').addEventListener('click',previous);
  $('expanded-shuffle').addEventListener('click',cyclePlaybackMode);$('random-play').addEventListener('click',playRandom);
  $('expanded-seek').addEventListener('input',e=>seekTo(e.target.value));
  audio.volume=.8;
  audio.addEventListener('timeupdate',()=>{const id=queue[current]?.id;accumulateListening(id,audio.currentTime*1000,!audio.paused);if(!audio.paused&&audio.readyState>=3){clearTimeout(playbackRecovery.watchdog);playbackRecovery.watchdog=null;}updateTimeline();});audio.addEventListener('seeking',()=>{listenTracker.lastPositionMs=audio.currentTime*1000;});audio.addEventListener('seeked',()=>{listenTracker.lastPositionMs=audio.currentTime*1000;});audio.addEventListener('loadedmetadata',updateTimeline);audio.addEventListener('waiting',armPlaybackWatchdog);audio.addEventListener('stalled',armPlaybackWatchdog);audio.addEventListener('playing',()=>{clearTimeout(playbackRecovery.watchdog);playbackRecovery.watchdog=null;});audio.addEventListener('canplay',()=>{clearTimeout(playbackRecovery.watchdog);playbackRecovery.watchdog=null;});audio.addEventListener('error',()=>{if(!nativeMode&&queue[current])recoverInterruptedPlayback('media error');});
  audio.addEventListener('play',()=>{const id=queue[current]?.id;if(id!=null&&listenTracker.trackId!==String(id))resetListenTracker(id,audio.currentTime*1000);sync();});audio.addEventListener('pause',sync);audio.addEventListener('ended',()=>{
    if(nativeMode)return;
    if(Number.isFinite(audio.duration)&&audio.duration>0&&audio.currentTime<audio.duration-2.5){recoverInterruptedPlayback('stream ended before duration');return;}
    resetPlaybackRecovery(queue[current]?.id);
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
  let miniGesture=null;
  // Pointer events cover mouse/pen. Touch Events are used separately because
  // iOS Safari and embedded WebViews can cancel pointer streams inconsistently.
  mini.addEventListener('pointerdown',e=>{
    if(e.pointerType==='touch'||!e.isPrimary||e.button!==0||e.target.closest('button,input,a,select,textarea'))return;
    miniGesture={id:e.pointerId,x:e.clientX,y:e.clientY};
  });
  mini.addEventListener('pointermove',e=>{
    if(e.pointerType==='touch'||!miniGesture||miniGesture.id!==e.pointerId)return;
    const dx=e.clientX-miniGesture.x,dy=e.clientY-miniGesture.y;
    if(dy < -24&&Math.abs(dy)>Math.abs(dx)*0.8){miniGesture=null;setExpanded(true);}
  });
  const clearMiniGesture=()=>{miniGesture=null;};
  mini.addEventListener('pointerup',clearMiniGesture);
  mini.addEventListener('pointercancel',clearMiniGesture);
  mini.addEventListener('lostpointercapture',clearMiniGesture);
  let miniTouch=null;
  mini.addEventListener('touchstart',e=>{
    if(e.touches.length!==1||e.target.closest('button,input,a,select,textarea')){miniTouch=null;return;}
    const t=e.touches[0];miniTouch={id:t.identifier,x:t.clientX,y:t.clientY};
  },{passive:true});
  mini.addEventListener('touchmove',e=>{
    if(!miniTouch)return;
    const t=Array.from(e.changedTouches).find(x=>x.identifier===miniTouch.id);
    if(!t)return;
    const dx=t.clientX-miniTouch.x,dy=t.clientY-miniTouch.y;
    if(dy < -24&&Math.abs(dy)>Math.abs(dx)*0.8){
      e.preventDefault();miniTouch=null;setExpanded(true);
    }
  },{passive:false});
  const clearMiniTouch=()=>{miniTouch=null;};
  mini.addEventListener('touchend',clearMiniTouch,{passive:true});
  mini.addEventListener('touchcancel',clearMiniTouch,{passive:true});
  mini.addEventListener('click',e=>{if(!e.target.closest('button,input,label'))setExpanded(true);});

  const expanded=$('expanded-player');
  let expandedGesture=null;
  expanded.addEventListener('pointerdown',e=>{
    if(e.pointerType==='touch'||!e.isPrimary||e.button!==0||e.target.closest('button,input,a,select,textarea'))return;
    expandedGesture={id:e.pointerId,x:e.clientX,y:e.clientY};
  });
  expanded.addEventListener('pointermove',e=>{
    if(e.pointerType==='touch'||!expandedGesture||expandedGesture.id!==e.pointerId)return;
    const dx=e.clientX-expandedGesture.x,dy=e.clientY-expandedGesture.y;
    if(dy>28&&Math.abs(dy)>Math.abs(dx)*0.8){expandedGesture=null;setExpanded(false);}
  });
  const clearExpandedGesture=()=>{expandedGesture=null;};
  expanded.addEventListener('pointerup',clearExpandedGesture);
  expanded.addEventListener('pointercancel',clearExpandedGesture);
  expanded.addEventListener('lostpointercapture',clearExpandedGesture);
  let expandedTouch=null;
  expanded.addEventListener('touchstart',e=>{
    if(e.touches.length!==1||e.target.closest('button,input,a,select,textarea')){expandedTouch=null;return;}
    const t=e.touches[0];expandedTouch={id:t.identifier,x:t.clientX,y:t.clientY};
  },{passive:true});
  expanded.addEventListener('touchmove',e=>{
    if(!expandedTouch)return;
    const t=Array.from(e.changedTouches).find(x=>x.identifier===expandedTouch.id);
    if(!t)return;
    const dx=t.clientX-expandedTouch.x,dy=t.clientY-expandedTouch.y;
    if(dy>28&&Math.abs(dy)>Math.abs(dx)*0.8){
      e.preventDefault();expandedTouch=null;setExpanded(false);
    }
  },{passive:false});
  const clearExpandedTouch=()=>{expandedTouch=null;};
  expanded.addEventListener('touchend',clearExpandedTouch,{passive:true});
  expanded.addEventListener('touchcancel',clearExpandedTouch,{passive:true});
  const saved=localStorage.getItem('rivune-theme');if(saved==='light')document.documentElement.dataset.theme='light';
  const themeButton=$('theme');
  function syncThemeIcon(){const light=document.documentElement.dataset.theme==='light';themeButton.replaceChildren(icon(light?'sun':'moon',19));themeButton.setAttribute('aria-label',light?'Switch to dark theme':'Switch to light theme');}
  syncThemeIcon();
  themeButton.addEventListener('click',()=>{const v=document.documentElement.dataset.theme==='light'?'dark':'light';document.documentElement.dataset.theme=v;localStorage.setItem('rivune-theme',v);syncThemeIcon();});
  load();updateTimeline();
})();