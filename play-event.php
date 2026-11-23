<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error'=>'POST required']); exit; }
$trackId=filter_var($_POST['track_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
$playedSeconds=filter_var($_POST['played_seconds']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>30]]);
$playId=trim((string)($_POST['play_id']??''));
if(!$trackId||!$playedSeconds||!preg_match('/^[a-zA-Z0-9-]{8,80}$/',$playId)){
  http_response_code(400);echo json_encode(['error'=>'A track, playback ID and at least 30 listened seconds are required.']);exit;
}
try{
  $_SESSION['rivune_recorded_plays']=is_array($_SESSION['rivune_recorded_plays']??null)?$_SESSION['rivune_recorded_plays']:[];
  $key=$playId;
  if(isset($_SESSION['rivune_recorded_plays'][$key])){echo json_encode(['ok'=>true,'counted'=>false]);exit;}
  $exists=$pdo->prepare('SELECT id FROM tracks WHERE id=?');$exists->execute([$trackId]);
  if(!$exists->fetchColumn()){http_response_code(404);echo json_encode(['error'=>'Track not found']);exit;}
  $stmt=$pdo->prepare('INSERT INTO track_plays (track_id,play_count,last_played_at) VALUES (?,1,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE play_count=play_count+1,last_played_at=CURRENT_TIMESTAMP');
  $stmt->execute([$trackId]);
  $_SESSION['rivune_recorded_plays'][$key]=time();
  if(count($_SESSION['rivune_recorded_plays'])>100){asort($_SESSION['rivune_recorded_plays']);$_SESSION['rivune_recorded_plays']=array_slice($_SESSION['rivune_recorded_plays'],-80,null,true);}
  echo json_encode(['ok'=>true,'counted'=>true]);
}catch(Throwable $e){
  http_response_code(503);echo json_encode(['error'=>'Play statistics are temporarily unavailable.']);
}
