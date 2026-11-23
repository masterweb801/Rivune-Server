<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function rivune_play_event_allow(PDO $pdo, string $identity): array {
  $pdo->exec("CREATE TABLE IF NOT EXISTS play_event_limits (
    identity_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    event_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    window_started_at DATETIME NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_play_event_limits_updated (updated_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

  $insert = $pdo->prepare('INSERT IGNORE INTO play_event_limits (identity_hash,event_count,window_started_at) VALUES (?,0,CURRENT_TIMESTAMP)');
  $insert->execute([$identity]);
  $update = $pdo->prepare("UPDATE play_event_limits
    SET event_count = IF(window_started_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 10 MINUTE), 1, event_count + 1),
        window_started_at = IF(window_started_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 10 MINUTE), CURRENT_TIMESTAMP, window_started_at)
    WHERE identity_hash = ?");
  $update->execute([$identity]);
  $read = $pdo->prepare('SELECT event_count,window_started_at FROM play_event_limits WHERE identity_hash=? LIMIT 1');
  $read->execute([$identity]);
  $row = $read->fetch(PDO::FETCH_ASSOC);
  if (!$row) throw new RuntimeException('Rate-limit state unavailable.');
  $count = (int)$row['event_count'];
  $started = strtotime((string)$row['window_started_at']) ?: time();
  return [
    'allowed' => $count <= 30,
    'retry_after' => max(1, 600 - (time() - $started))
  ];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Allow: POST');
  http_response_code(405);
  echo json_encode(['error'=>'POST required']);
  exit;
}

$trackId = filter_var($_POST['track_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
$playedSeconds = filter_var($_POST['played_seconds'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>30,'max_range'=>86400]]);
$playIdValue = $_POST['play_id'] ?? null;
$playId = is_string($playIdValue) ? trim($playIdValue) : '';
if (!$trackId || $playedSeconds === false || !$playedSeconds || !preg_match('/^[a-zA-Z0-9-]{8,80}$/D', $playId)) {
  http_response_code(400);
  echo json_encode(['error'=>'A valid track, playback ID and 30 to 86400 listened seconds are required.']);
  exit;
}

try {
  $_SESSION['rivune_recorded_plays'] = is_array($_SESSION['rivune_recorded_plays'] ?? null) ? $_SESSION['rivune_recorded_plays'] : [];
  $key = hash('sha256', (int)$trackId . ':' . $playId);
  if (isset($_SESSION['rivune_recorded_plays'][$key])) {
    echo json_encode(['ok'=>true,'counted'=>false]);
    exit;
  }

  // The IP is never stored; only a keyed digest is retained. The persistent
  // limit prevents a client from bypassing session deduplication by rotating IDs.
  $secret = (string)($config['admin_pass_hash'] ?? '');
  if ($secret === '') throw new RuntimeException('Rate-limit secret unavailable.');
  $address = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
  $identity = hash_hmac('sha256', $address, $secret);
  $limit = rivune_play_event_allow($pdo, $identity);
  if (!$limit['allowed']) {
    http_response_code(429);
    header('Retry-After: ' . (int)$limit['retry_after']);
    echo json_encode(['error'=>'Play statistics are temporarily rate-limited.','retry_after'=>(int)$limit['retry_after']]);
    exit;
  }

  $exists = $pdo->prepare('SELECT id FROM tracks WHERE id=? LIMIT 1');
  $exists->execute([$trackId]);
  if (!$exists->fetchColumn()) {
    http_response_code(404);
    echo json_encode(['error'=>'Track not found']);
    exit;
  }

  $stmt = $pdo->prepare('INSERT INTO track_plays (track_id,play_count,last_played_at) VALUES (?,1,CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE play_count=play_count+1,last_played_at=CURRENT_TIMESTAMP');
  $stmt->execute([$trackId]);
  $_SESSION['rivune_recorded_plays'][$key] = time();
  if (count($_SESSION['rivune_recorded_plays']) > 100) {
    asort($_SESSION['rivune_recorded_plays']);
    $_SESSION['rivune_recorded_plays'] = array_slice($_SESSION['rivune_recorded_plays'], -80, null, true);
  }
  echo json_encode(['ok'=>true,'counted'=>true]);
} catch (Throwable $e) {
  http_response_code(503);
  echo json_encode(['error'=>'Play statistics are temporarily unavailable.']);
}
