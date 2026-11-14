<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
// Streaming is public: release the PHP session lock before sending audio bytes.
if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
// Avoid PHP output buffering delaying the first audio bytes.
while (ob_get_level() > 0) { ob_end_clean(); }

$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);
if (!$id || $id<1) { http_response_code(400); exit('Invalid track.'); }
$stmt=$pdo->prepare('SELECT stored_name,original_name FROM tracks WHERE id=?'); $stmt->execute([$id]); $track=$stmt->fetch();
if (!$track) { http_response_code(404); exit('Track not found.'); }
$file=__DIR__.'/storage/music/'.$track['stored_name'];
if (!is_file($file) || !is_readable($file)) { http_response_code(404); exit('Audio file missing.'); }
$size=filesize($file); if ($size===false || $size<1) { http_response_code(404); exit('Audio file is empty.'); }
$start=0; $end=$size-1;
header('Content-Type: audio/mpeg'); header('Accept-Ranges: bytes'); header('Content-Disposition: inline; filename="'.rawurlencode($track['original_name']).'"');
header('Cache-Control: public, max-age=3600'); header('X-Content-Type-Options: nosniff'); header('X-Accel-Buffering: no');
if (isset($_SERVER['HTTP_RANGE']) && preg_match('/^bytes=(\d*)-(\d*)$/', trim($_SERVER['HTTP_RANGE']), $m)) {
  if ($m[1]==='' && $m[2]==='') { http_response_code(416); header("Content-Range: bytes */$size"); exit; }
  if ($m[1]==='') { $start=max(0,$size-(int)$m[2]); } else { $start=(int)$m[1]; }
  if ($m[2]!=='' && $m[1]!=='') $end=min($end,(int)$m[2]);
  if ($start>$end || $start>=$size) { http_response_code(416); header("Content-Range: bytes */$size"); exit; }
  http_response_code(206); header("Content-Range: bytes $start-$end/$size");
}
$length=$end-$start+1; header('Content-Length: '.$length);
$fp=fopen($file,'rb');
if ($fp === false) { http_response_code(500); exit; }
if ($start > 0 && fseek($fp,$start) !== 0) { fclose($fp); http_response_code(500); exit; }
$remaining=$length;
// Larger reads reduce PHP loop/flush overhead while preserving exact Range boundaries.
$chunkSize=262144;
while ($remaining>0 && !feof($fp)) {
  $chunk=fread($fp,min($chunkSize,$remaining));
  if ($chunk===false || $chunk==='') break;
  echo $chunk;
  $remaining-=strlen($chunk);
}
fclose($fp);
