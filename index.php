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
<title>RIVUNE | Music Without Boundaries</title><link rel="icon" type="image/png" href="public/assets/rivune-logo.png"><link rel="stylesheet" href="assets/css/tokens.css?v=design-tokens-1">
<link rel="stylesheet" href="assets/css/app.css?v=app-core-4">
<link rel="stylesheet" href="assets/css/controls.css?v=controls-1">
<link rel="stylesheet" href="assets/css/forms.css?v=forms-1">
<link rel="stylesheet" href="assets/css/library.css?v=library-1">
<link rel="stylesheet" href="assets/css/player.css?v=player-module-1">
</head><body>
<header class="top"><a class="brand" href="./"><img class="brand-logo" src="public/assets/rivune-logo.png" alt="RIVUNE logo"><span>RIVUNE<small>MUSIC WITHOUT BOUNDARIES</small></span></a><div class="top-actions"><button id="random-play" class="random-btn" type="button" aria-label="Play a random song">⚄ <span>Random</span></button><span class="public-tag">PUBLIC LISTENING</span><button id="theme" class="icon-btn" aria-label="Toggle theme">◐</button></div></header>
<main class="wrap">
<section class="hero"><div><p class="eyebrow">YOUR SOUND. YOUR SPACE.</p><h1>Find your<br><span>next favourite.</span></h1><p class="lead">A music space made for listening. Explore the library and press play.</p><a href="#library" class="jump">Explore the library ↓</a></div><div class="hero-art" aria-hidden="true"><div class="disc"><span>R</span></div><i></i><b></b></div></section>
<section id="library" class="library"><div class="section-head"><div><p class="eyebrow">THE COLLECTION</p><h2>All music</h2></div><span id="count" class="muted">Loading…</span></div>
<label class="search"><span>⌕</span><input id="search" type="search" placeholder="Search songs, artists or albums" autocomplete="off"></label>
<div id="tracks" class="tracks" aria-live="polite"><div class="library-skeleton" aria-hidden="true"><div class="skeleton-row" aria-hidden="true"><span></span><div><i></i><b></b></div></div><div class="skeleton-row" aria-hidden="true"><span></span><div><i></i><b></b></div></div><div class="skeleton-row" aria-hidden="true"><span></span><div><i></i><b></b></div></div><div class="skeleton-row" aria-hidden="true"><span></span><div><i></i><b></b></div></div><div class="skeleton-row" aria-hidden="true"><span></span><div><i></i><b></b></div></div><div class="skeleton-row" aria-hidden="true"><span></span><div><i></i><b></b></div></div></div></div><div id="load-more-sentinel" class="load-more-sentinel" aria-hidden="true"></div><button id="load-more" class="secondary load-more-btn" type="button" hidden>Load more songs</button><p id="library-status" class="muted library-status" aria-live="polite"></p></section>
</main>
<footer><span>RIVUNE</span><span>Music for everyone.</span></footer>
<aside class="player" id="player" aria-label="Audio player">
  <div class="player-inner" id="mini-player">
    <div class="mini-now" id="mini-now" role="button" tabindex="0" aria-label="Open full screen player">
      <div id="cover" class="cover">♫</div>
      <div class="now-copy"><strong id="now-title">Nothing playing</strong><span id="now-artist">Choose a track</span></div>
    </div>
    <div class="mini-controls">
      <button id="prev" class="control" aria-label="Previous">|◀</button>
      <button id="toggle" class="play" aria-label="Play">▶</button>
      <button id="next" class="control" aria-label="Next">▶|</button>
    </div>
    <div class="mini-progress"><span id="mini-progress"></span></div>
  </div>

</aside>
  <section class="expanded-player" id="expanded-player" aria-hidden="true" aria-label="Full screen player">
    <div class="expanded-top"><button id="collapse-player" class="collapse-player" aria-label="Collapse player">⌄</button><span>NOW PLAYING</span><button id="expanded-shuffle" class="control" aria-label="Shuffle">⤨</button></div>
    <div class="expanded-content">
      <div class="expanded-art" id="expanded-art"><span aria-hidden="true">♫</span></div>
      <div class="expanded-track"><strong id="expanded-title">Nothing playing</strong><span id="expanded-artist">Choose a track</span></div>
      <div class="expanded-timeline"><time id="expanded-elapsed">0:00</time><input id="expanded-seek" type="range" min="0" max="1000" value="0"><time id="expanded-duration">0:00</time></div>
      <div class="expanded-controls"><button id="expanded-prev" class="control" aria-label="Previous">|◀</button><button id="expanded-toggle" class="play" aria-label="Play">▶</button><button id="expanded-next" class="control" aria-label="Next">▶|</button></div>
      <label class="expanded-volume">♫ <input id="expanded-volume" type="range" min="0" max="1" step=".01" value=".8"></label>
    </div>
    <p class="expanded-hint">Swipe down or tap the arrow to close</p>
  </section>
<audio id="audio" preload="none" playsinline></audio><script src="assets/js/app.js?v=library-analytics-1" defer></script>
</body></html>
