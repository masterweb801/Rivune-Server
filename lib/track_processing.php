<?php
declare(strict_types=1);

/** Create the durable processing queue without altering existing track rows. */
function rivune_processing_ensure_schema(PDO $pdo): void {
  $pdo->exec("CREATE TABLE IF NOT EXISTS track_processing_jobs (
    track_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    status ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    last_error VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_track_processing_status (status, created_at),
    CONSTRAINT fk_track_processing_track FOREIGN KEY (track_id) REFERENCES tracks(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function rivune_id3_text_value(string $payload): string {
  if ($payload === '') return '';
  $encoding = ord($payload[0]);
  $value = substr($payload, 1);
  if ($encoding === 1 && function_exists('iconv')) {
    $value = iconv('UTF-16', 'UTF-8//IGNORE', $value) ?: '';
  } elseif ($encoding === 2 && function_exists('iconv')) {
    $value = iconv('UTF-16BE', 'UTF-8//IGNORE', $value) ?: '';
  } elseif ($encoding === 0 && function_exists('iconv')) {
    $value = iconv('ISO-8859-1', 'UTF-8//IGNORE', $value) ?: '';
  } elseif ($encoding === 0 && function_exists('mb_convert_encoding')) {
    $value = mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');
  } elseif ($encoding === 3 && !preg_match('//u', $value)) {
    $value = '';
  }
  $value = str_replace("\0", '', $value);
  return trim($value);
}

/** Read only the small ID3 text fields needed for the library. */
function rivune_extract_mp3_metadata(string $path): array {
  $fp = @fopen($path, 'rb');
  if (!$fp) return [];
  $header = fread($fp, 10);
  if ($header === false || strlen($header) !== 10 || substr($header, 0, 3) !== 'ID3') {
    fclose($fp);
    return [];
  }
  $major = ord($header[3]);
  if (!in_array($major, [2, 3, 4], true)) { fclose($fp); return []; }
  $tagSize = 0;
  for ($i = 6; $i < 10; $i++) {
    $byte = ord($header[$i]);
    if (($byte & 0x80) !== 0) { fclose($fp); return []; }
    $tagSize = ($tagSize << 7) | $byte;
  }
  if ($tagSize < 1 || $tagSize > 8 * 1024 * 1024) { fclose($fp); return []; }
  $body = fread($fp, $tagSize);
  fclose($fp);
  if ($body === false || strlen($body) !== $tagSize) return [];
  if ((ord($header[5]) & 0x80) !== 0) $body = str_replace("\xFF\x00", "\xFF", $body);

  $offset = 0;
  if (($major === 3 || $major === 4) && (ord($header[5]) & 0x40) !== 0 && strlen($body) >= 4) {
    if ($major === 3) {
      $extended = unpack('N', substr($body, 0, 4))[1];
      $offset = 4 + $extended;
    } else {
      $extended = 0;
      for ($i = 0; $i < 4; $i++) $extended = ($extended << 7) | ord($body[$i]);
      $offset = $extended;
    }
  }
  if ($offset < 0 || $offset > strlen($body)) return [];
  $map = $major === 2
    ? ['TT2' => 'title', 'TP1' => 'artist', 'TAL' => 'album']
    : ['TIT2' => 'title', 'TPE1' => 'artist', 'TALB' => 'album'];
  $found = [];
  $length = strlen($body);
  while ($offset + ($major === 2 ? 6 : 10) <= $length) {
    if ($major === 2) {
      $frameId = substr($body, $offset, 3);
      if ($frameId === "\0\0\0") break;
      $size = ord($body[$offset + 3]) * 65536 + ord($body[$offset + 4]) * 256 + ord($body[$offset + 5]);
      $frameHeader = 6;
    } else {
      $frameId = substr($body, $offset, 4);
      if ($frameId === "\0\0\0\0") break;
      if ($major === 4) {
        $size = 0;
        for ($i = 0; $i < 4; $i++) {
          $byte = ord($body[$offset + 4 + $i]);
          if (($byte & 0x80) !== 0) { $size = 0; break; }
          $size = ($size << 7) | $byte;
        }
      } else {
        $size = unpack('N', substr($body, $offset + 4, 4))[1];
      }
      $frameHeader = 10;
    }
    if ($size < 1 || $size > $length - $offset - $frameHeader) break;
    $payload = substr($body, $offset + $frameHeader, $size);
    $offset += $frameHeader + $size;
    if (!isset($map[$frameId]) || isset($found[$map[$frameId]])) continue;
    $value = rivune_id3_text_value($payload);
    if ($value !== '') $found[$map[$frameId]] = function_exists('mb_substr') ? mb_substr($value, 0, 190, 'UTF-8') : substr($value, 0, 190);
  }
  return $found;
}
