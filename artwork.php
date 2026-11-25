<?php
declare(strict_types=1);
require __DIR__.'/lib/bootstrap.php';
require_once __DIR__.'/lib/maintenance.php';
rivune_maintenance_enforce($pdo,'text');
if (session_status()===PHP_SESSION_ACTIVE) session_write_close();
$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);
if (!$id || $id<1) { http_response_code(404); exit; }
$directory=__DIR__.'/storage/artwork';
$file=null; $mime=null;
$types=['jpg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp'];
foreach ($types as $extension=>$contentType) {
  $candidate=$directory.'/'.$id.'.'.$extension;
  if (is_file($candidate) && is_readable($candidate)) { $file=$candidate; $mime=$contentType; break; }
}
if (!$file || !$mime) { http_response_code(404); exit; }
$size=filesize($file);
if ($size===false || $size<1) { http_response_code(404); exit; }
header('Content-Type: '.$mime);
header('Content-Length: '.$size);
header('Cache-Control: public, max-age=31536000, immutable');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="rivune-artwork-'.$id.'.'.pathinfo($file,PATHINFO_EXTENSION).'"');
readfile($file);
