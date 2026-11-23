<?php
declare(strict_types=1);
/**
 * Read an embedded ID3v2 APIC/PIC cover from an MP3 upload.
 * Returns a validated image payload; malformed or unsupported tags are ignored.
 */
function rivune_extract_mp3_artwork(string $path): ?array {
  $fp=@fopen($path,'rb');
  if (!$fp) return null;
  $header=fread($fp,10);
  if ($header===false || strlen($header)!==10 || substr($header,0,3)!=='ID3') { fclose($fp); return null; }
  $major=ord($header[3]);
  if (!in_array($major,[2,3,4],true)) { fclose($fp); return null; }
  $tagSize=0;
  for ($i=6;$i<10;$i++) {
    $byte=ord($header[$i]);
    if (($byte&0x80)!==0) { fclose($fp); return null; }
    $tagSize=($tagSize<<7)|$byte;
  }
  if ($tagSize<1 || $tagSize>8*1024*1024) { fclose($fp); return null; }
  $body=fread($fp,$tagSize);
  fclose($fp);
  if ($body===false || strlen($body)!==$tagSize) return null;
  if ((ord($header[5])&0x80)!==0) $body=str_replace("\xFF\x00","\xFF",$body);
  $bodyOffset=0;
  if ($major===3 && (ord($header[5])&0x40)!==0 && strlen($body)>=4) {
    $extended=unpack("N",substr($body,0,4))[1];
    $bodyOffset=4+$extended;
  } elseif ($major===4 && (ord($header[5])&0x40)!==0 && strlen($body)>=4) {
    $extended=0;
    for ($j=0;$j<4;$j++) $extended=($extended<<7)|ord($body[$j]);
    $bodyOffset=$extended;
  }
  if ($bodyOffset<0 || $bodyOffset>strlen($body)) return null;

  $offset=$bodyOffset; $bodyLength=strlen($body);
  while ($offset+($major===2?6:10)<=$bodyLength) {
    if ($major===2) {
      $frameId=substr($body,$offset,3);
      if ($frameId==="\0\0\0") break;
      $size=ord($body[$offset+3])*65536+ord($body[$offset+4])*256+ord($body[$offset+5]);
      $frameHeader=6;
    } else {
      $frameId=substr($body,$offset,4);
      if ($frameId==="\0\0\0\0") break;
      $size=0;
      if ($major===4) {
        for ($j=0;$j<4;$j++) {
          $b=ord($body[$offset+4+$j]);
          if (($b&0x80)!==0) { $size=0; break; }
          $size=($size<<7)|$b;
        }
      } else {
        $size=unpack('N',substr($body,$offset+4,4))[1];
      }
      $frameHeader=10;
    }
    if ($size<1 || $size>$bodyLength-$offset-$frameHeader) break;
    $payload=substr($body,$offset+$frameHeader,$size);
    $offset+=$frameHeader+$size;
    if (($major===2 && $frameId!=='PIC') || ($major!==2 && $frameId!=='APIC')) continue;
    if (strlen($payload)<5) continue;

    $encoding=ord($payload[0]);
    if ($major===2) {
      $format=strtoupper(substr($payload,1,3));
      $mime=$format==='JPG'?'image/jpeg':($format==='PNG'?'image/png':'');
      $cursor=4;
    } else {
      $mimeEnd=strpos($payload,"\0",1);
      if ($mimeEnd===false) continue;
      $mime=strtolower(substr($payload,1,$mimeEnd-1));
      $cursor=$mimeEnd+1;
    }
    if ($cursor>=strlen($payload)) continue;
    $pictureType=ord($payload[$cursor]);
    $cursor++;
    if ($encoding===1 || $encoding===2) {
      $terminator=false;
      for ($scan=$cursor;$scan+1<strlen($payload);$scan+=2) {
        if ($payload[$scan]==="\0" && $payload[$scan+1]==="\0") { $terminator=$scan; break; }
      }
      if ($terminator===false) continue;
      $cursor=$terminator+2;
    } else {
      $terminator=strpos($payload,"\0",$cursor);
      if ($terminator===false) continue;
      $cursor=$terminator+1;
    }
    $image=substr($payload,$cursor);
    if ($image==='' || strlen($image)>5*1024*1024) continue;
    $info=@getimagesizefromstring($image);
    $allowed=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    if (!$info || !isset($allowed[$mime]) || $info['mime']!==$mime) continue;
    $width=(int)$info[0]; $height=(int)$info[1];
    if ($width<1 || $height<1 || $width>6000 || $height>6000 || $width*$height>24000000) continue;
    $validArtwork=['extension'=>$allowed[$mime],'data'=>$image];
    if ($pictureType===3) return $validArtwork; // Prefer the ID3 front-cover frame.
    if (!isset($fallbackArtwork)) $fallbackArtwork=$validArtwork;
  }
  return $fallbackArtwork??null;
}

/** Store a validated extracted cover without risking the uploaded MP3. */
function rivune_save_embedded_artwork(int $trackId, array $artwork): bool {
  if ($trackId<1 || !isset($artwork['extension'],$artwork['data'])) return false;
  $extensions=['jpg','png','webp'];
  $extension=(string)$artwork['extension'];
  $data=$artwork['data'];
  if (!in_array($extension,$extensions,true) || !is_string($data) || $data==='' || strlen($data)>5*1024*1024) return false;
  $directory=dirname(__DIR__).'/storage/artwork';
  if (!is_dir($directory) && !@mkdir($directory,0750,true) && !is_dir($directory)) return false;
  $temporary=$directory.'/'.bin2hex(random_bytes(16)).'.tmp';
  if (@file_put_contents($temporary,$data,LOCK_EX)!==strlen($data)) { @unlink($temporary); return false; }
  $destination=$directory.'/'.$trackId.'.'.$extension;
  if (!@rename($temporary,$destination)) { @unlink($temporary); return false; }
  foreach ($extensions as $other) {
    if ($other===$extension) continue;
    $old=$directory.'/'.$trackId.'.'.$other;
    if (is_file($old)) @unlink($old);
  }
  return true;
}

