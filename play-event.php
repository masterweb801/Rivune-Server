<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error'=>'POST required']); exit; }
$trackId=filter_var($_POST['track_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
if(!$trackId){http_response_code(400);echo json_encode(['error'=>'Invalid track']);exit;}
try{
  $_SESSION['rivune_play_events']=is_array($_SESSION['rivune_play_events']??null)?$_SESSION['rivune_play_events']:[];
  $now=time();$key=(string)$trackId;$last=(int)($_SESSION['rivune_play_events'][$key]??0);
  if($now-$last<30){echo json_encode(['ok'=>true,'counted'=>false]);exit;}
  $exists=$pdo->prepare('SELECT id FROM tracks WHERE id=?');$exists->execute([$trackId]);
  if(!$exists->fetchColumn()){http_response_code(404);echo json_encode(['error'=>'Track not found']);exit;}
  $stmt=$pdo->prepare('INSERT INTO track_plays (track_id,play_count,last_played_at) VALUES (?,1,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE play_count=play_count+1,last_played_at=CURRENT_TIMESTAMP');$stmt->execute([$trackId]);
  $_SESSION['rivune_play_events'][$key]=$now;
  if(count($_SESSION['rivune_play_events'])>40){asort($_SESSION['rivune_play_events']);$_SESSION['rivune_play_events']=array_slice($_SESSION['rivune_play_events'],-30,null,true);}
  echo json_encode(['ok'=>true,'counted'=>true]);
}catch(Throwable $e){http_response_code(503);echo json_encode(['error'=>'Play statistics are temporarily unavailable.']);}
