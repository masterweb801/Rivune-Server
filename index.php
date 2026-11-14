<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
?><!doctype html>
<html lang="en" data-theme="dark">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#11111d"><meta name="description" content="RIVUNE — discover and stream music.">
<title>RIVUNE — Music Without Boundaries</title><link rel="icon" type="image/png" href="public/assets/rivune-logo.png"><link rel="stylesheet" href="assets/css/app.css">
</head><body>
<header class="top"><a class="brand" href="./"><img class="brand-logo" src="public/assets/rivune-logo.png" alt="RIVUNE logo"><span>RIVUNE<small>MUSIC WITHOUT BOUNDARIES</small></span></a><div class="top-actions"><span class="public-tag">PUBLIC LISTENING</span><button id="theme" class="icon-btn" aria-label="Toggle theme">◐</button></div></header>
<main class="wrap">
<section class="hero"><div><p class="eyebrow">YOUR SOUND. YOUR SPACE.</p><h1>Find your<br><span>next favourite.</span></h1><p class="lead">A music space made for listening. Explore the library and press play.</p><a href="#library" class="jump">Explore the library ↓</a></div><div class="hero-art" aria-hidden="true"><div class="disc"><span>R</span></div><i></i><b></b></div></section>
<section id="library" class="library"><div class="section-head"><div><p class="eyebrow">THE COLLECTION</p><h2>All music</h2></div><span id="count" class="muted">Loading…</span></div>
<label class="search"><span>⌕</span><input id="search" type="search" placeholder="Search songs, artists or albums" autocomplete="off"></label>
<div id="tracks" class="tracks"><div class="empty">Loading the music library…</div></div></section>
</main>
<footer><span>RIVUNE</span><span>Music for everyone.</span></footer>
<aside class="player" aria-label="Audio player"><div class="player-inner"><div class="now"><div id="cover" class="cover">♫</div><div class="now-copy"><strong id="now-title">Nothing playing</strong><span id="now-artist">Choose a track</span></div></div><div class="transport"><div class="controls"><button id="shuffle" class="control" title="Shuffle" aria-label="Shuffle">⤨</button><button id="prev" class="control" aria-label="Previous">|◀</button><button id="toggle" class="play" aria-label="Play">▶</button><button id="next" class="control" aria-label="Next">▶|</button></div><div class="timeline"><time id="elapsed">0:00</time><input id="seek" type="range" min="0" max="1000" value="0"><time id="duration">0:00</time></div></div><label class="volume">♫ <input id="volume" type="range" min="0" max="1" step=".01" value=".8"></label></div></aside>
<audio id="audio" preload="metadata"></audio><script src="assets/js/app.js" defer></script>
</body></html>