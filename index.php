<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/maintenance.php';
rivune_maintenance_enforce($pdo, 'html');
?><!doctype html>
<html lang="en" data-theme="dark">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#11111d"><meta name="description" content="RIVUNE — discover and stream music.">
<title>RIVUNE | Music Without Boundaries</title><link rel="icon" type="image/png" href="public/assets/rivune-logo.png"><link rel="stylesheet" href="assets/css/tokens.css?v=signature-nav-1">
<link rel="stylesheet" href="assets/css/app.css?v=ios-layout-nav-fix-2">
<link rel="stylesheet" href="assets/css/controls.css?v=svg-icons-1">
<link rel="stylesheet" href="assets/css/forms.css?v=forms-1">
<link rel="stylesheet" href="assets/css/library.css?v=library-1">
<link rel="stylesheet" href="assets/css/player.css?v=ios-layout-nav-fix-2">
</head><body>
<header class="top"><a class="brand" href="./"><img class="brand-logo" src="public/assets/rivune-logo.png" alt="RIVUNE logo"><span>RIVUNE<small>MUSIC WITHOUT BOUNDARIES</small></span></a><div class="top-actions"><button id="search-toggle" class="icon-btn nav-search-btn" type="button" aria-label="Open search" aria-expanded="false"><svg class="ui-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg></button><button id="random-play" class="random-btn" type="button" aria-label="Play a random song"><svg class="ui-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m18 14 4 4-4 4"/><path d="M2 18h2.5a6 6 0 0 0 4.7-2.3l5.6-7.4A6 6 0 0 1 19.5 6H22"/><path d="m18 2 4 4-4 4"/><path d="M2 6h2.5a6 6 0 0 1 3.2.9"/></svg> <span>Random</span></button><span class="public-tag">PUBLIC LISTENING</span><button id="theme" class="icon-btn" aria-label="Toggle theme"><svg class="ui-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.9 13A9 9 0 0 1 11 3.1 9 9 0 1 0 20.9 13Z"/></svg></button></div></header>
<label class="search"><span class="search-icon"><svg class="ui-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg></span><input id="search" type="search" placeholder="Search songs, artists or albums" autocomplete="off"><button id="search-close" class="search-close" type="button" aria-label="Close search"><svg class="ui-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m18 6-12 12M6 6l12 12"/></svg></button></label>
<main class="wrap">
<section class="hero"><div><p class="eyebrow">YOUR SOUND. YOUR SPACE.</p><h1>Find your<br><span>next favourite.</span></h1><p class="lead">A music space made for listening. Explore the library and press play.</p><a href="#library" class="jump">Explore the library <svg class="ui-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg></a></div><div class="hero-art" aria-hidden="true"><div class="disc"><span>R</span></div><i></i><b></b></div></section>
<section id="library" class="library"><div class="section-head"><div><p class="eyebrow">THE COLLECTION</p><h2>All music</h2></div><div class="library-tools"><span id="count" class="muted">Loading…</span><label class="sort-control"><span>Sort</span><select id="library-sort" aria-label="Sort music"><option value="popular">Most viewed</option><option value="alphabetical">Alphabetical</option></select></label></div></div>
<div id="tracks" class="tracks" aria-live="polite"><div class="library-skeleton" aria-hidden="true"><div class="skeleton-row" aria-hidden="true"><span></span><div><i></i><b></b></div></div><div class="skeleton-row" aria-hidden="true"><span></span><div><i></i><b></b></div></div><div class="skeleton-row" aria-hidden="true"><span></span><div><i></i><b></b></div></div><div class="skeleton-row" aria-hidden="true"><span></span><div><i></i><b></b></div></div><div class="skeleton-row" aria-hidden="true"><span></span><div><i></i><b></b></div></div><div class="skeleton-row" aria-hidden="true"><span></span><div><i></i><b></b></div></div></div></div><div id="load-more-sentinel" class="load-more-sentinel" aria-hidden="true"></div><button id="load-more" class="secondary load-more-btn" type="button" hidden>Load more songs</button><p id="library-status" class="muted library-status" aria-live="polite"></p></section>
</main>
<footer><span>RIVUNE</span><span>Music for everyone.</span></footer>
<aside class="player" id="player" aria-label="Audio player">
  <div class="player-inner" id="mini-player">
    <div class="mini-now" id="mini-now" role="button" tabindex="0" aria-label="Open full screen player">
      <div id="cover" class="cover"><svg class="ui-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg></div>
      <div class="now-copy"><strong id="now-title">Nothing playing</strong><span id="now-artist">Choose a track</span></div>
    </div>
    <div class="mini-controls">
      <button id="prev" class="control" aria-label="Previous"><svg class="ui-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 5v14"/><path d="m19 5-10 7 10 7z"/></svg></button>
      <button id="toggle" class="play" aria-label="Play"><svg class="ui-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m8 5 11 7-11 7z" fill="currentColor" stroke="none"/></svg></button>
      <button id="next" class="control" aria-label="Next"><svg class="ui-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 5v14"/><path d="m5 5 10 7-10 7z"/></svg></button>
    </div>
    <div class="mini-progress"><span id="mini-progress"></span></div>
  </div>

</aside>
  <section class="expanded-player" id="expanded-player" aria-hidden="true" aria-label="Full screen player">
    <div class="expanded-top"><button id="collapse-player" class="collapse-player" aria-label="Collapse player"><svg class="ui-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></button><span>NOW PLAYING</span><button id="expanded-shuffle" class="control" aria-label="Loop all" aria-pressed="false"><svg class="ui-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m17 2 4 4-4 4"/><path d="M3 11V9a3 3 0 0 1 3-3h15"/><path d="m7 22-4-4 4-4"/><path d="M21 13v2a3 3 0 0 1-3 3H3"/></svg></button></div>
    <div class="expanded-content">
      <div class="expanded-art" id="expanded-art"><svg class="ui-icon" viewBox="0 0 24 24" width="44" height="44" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg></div>
      <div class="expanded-track"><strong id="expanded-title">Nothing playing</strong><span id="expanded-artist">Choose a track</span></div>
      <div class="expanded-timeline"><time id="expanded-elapsed">0:00</time><input id="expanded-seek" type="range" min="0" max="1000" value="0"><time id="expanded-duration">0:00</time></div>
      <div class="expanded-controls"><button id="expanded-prev" class="control" aria-label="Previous"><svg class="ui-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 5v14"/><path d="m19 5-10 7 10 7z"/></svg></button><button id="expanded-toggle" class="play" aria-label="Play"><svg class="ui-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m8 5 11 7-11 7z" fill="currentColor" stroke="none"/></svg></button><button id="expanded-next" class="control" aria-label="Next"><svg class="ui-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 5v14"/><path d="m5 5 10 7-10 7z"/></svg></button></div>
    </div>
    <p class="expanded-hint">Swipe down or tap the arrow to close</p>
  </section>
<audio id="audio" preload="none" playsinline></audio><script src="assets/js/app.js?v=playback-recovery-1" defer></script>
</body></html>
