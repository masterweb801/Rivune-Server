<?php
declare(strict_types=1);

/**
 * Shared-hosting-compatible administrator login throttling.
 * The client address is never stored; only a keyed SHA-256 identity is kept.
 */
function rivune_admin_login_key(string $username, string $secret): string {
  $address = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
  return hash_hmac('sha256', $address . "\0" . strtolower(trim($username)), $secret);
}

function rivune_admin_login_throttle_ensure(PDO $pdo): bool {
  static $ready = null;
  if ($ready !== null) return $ready;
  try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_login_throttle (
      identity_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
      failures SMALLINT UNSIGNED NOT NULL DEFAULT 0,
      window_started_at DATETIME NOT NULL,
      locked_until DATETIME NULL,
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX idx_login_throttle_updated (updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $ready = true;
  } catch (Throwable $e) {
    $ready = false;
  }
  return $ready;
}

function rivune_admin_login_fallback_check(): array {
  $now = time();
  $started = (int)($_SESSION['rivune_login_window_started'] ?? 0);
  $failures = (int)($_SESSION['rivune_login_failures'] ?? 0);
  if ($started < 1 || $now - $started >= 900) {
    unset($_SESSION['rivune_login_window_started'], $_SESSION['rivune_login_failures']);
    return ['limited'=>false, 'retry_after'=>0];
  }
  return $failures >= 5
    ? ['limited'=>true, 'retry_after'=>max(1, 900 - ($now - $started))]
    : ['limited'=>false, 'retry_after'=>0];
}

function rivune_admin_login_is_limited(PDO $pdo, string $username, string $secret): array {
  if (!rivune_admin_login_throttle_ensure($pdo)) return rivune_admin_login_fallback_check();
  $key = rivune_admin_login_key($username, $secret);
  try {
    $insert = $pdo->prepare('INSERT IGNORE INTO admin_login_throttle (identity_hash, failures, window_started_at) VALUES (?, 0, CURRENT_TIMESTAMP)');
    $insert->execute([$key]);
    $stmt = $pdo->prepare('SELECT failures, window_started_at, locked_until FROM admin_login_throttle WHERE identity_hash = ? LIMIT 1');
    $stmt->execute([$key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return rivune_admin_login_fallback_check();

    $started = strtotime((string)$row['window_started_at']) ?: time();
    if (time() - $started >= 900) {
      $reset = $pdo->prepare('UPDATE admin_login_throttle SET failures=0, window_started_at=CURRENT_TIMESTAMP, locked_until=NULL WHERE identity_hash=?');
      $reset->execute([$key]);
      return ['limited'=>false, 'retry_after'=>0];
    }

    $lockedUntil = !empty($row['locked_until']) ? (strtotime((string)$row['locked_until']) ?: 0) : 0;
    if ($lockedUntil > time()) return ['limited'=>true, 'retry_after'=>$lockedUntil - time()];

    // Bound table maintenance; stale rows are harmless and are removed gradually.
    $pdo->exec("DELETE FROM admin_login_throttle WHERE updated_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 30 DAY) LIMIT 50");
    return ['limited'=>false, 'retry_after'=>0];
  } catch (Throwable $e) {
    return rivune_admin_login_fallback_check();
  }
}

function rivune_admin_login_record_failure(PDO $pdo, string $username, string $secret): void {
  if (!rivune_admin_login_throttle_ensure($pdo)) {
    if (empty($_SESSION['rivune_login_window_started']) || time() - (int)$_SESSION['rivune_login_window_started'] >= 900) {
      $_SESSION['rivune_login_window_started'] = time();
      $_SESSION['rivune_login_failures'] = 1;
    } else {
      $_SESSION['rivune_login_failures'] = (int)($_SESSION['rivune_login_failures'] ?? 0) + 1;
    }
    return;
  }

  $key = rivune_admin_login_key($username, $secret);
  try {
    $insert = $pdo->prepare('INSERT IGNORE INTO admin_login_throttle (identity_hash, failures, window_started_at) VALUES (?, 0, CURRENT_TIMESTAMP)');
    $insert->execute([$key]);
    $update = $pdo->prepare("UPDATE admin_login_throttle
      SET locked_until = CASE
        WHEN window_started_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 15 MINUTE) THEN NULL
        WHEN failures + 1 >= 5 THEN DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 15 MINUTE)
        ELSE NULL END,
      failures = CASE
        WHEN window_started_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 15 MINUTE) THEN 1
        ELSE failures + 1 END,
      window_started_at = CASE
        WHEN window_started_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 15 MINUTE) THEN CURRENT_TIMESTAMP
        ELSE window_started_at END
      WHERE identity_hash = ?");
    $update->execute([$key]);
  } catch (Throwable $e) {
    if (empty($_SESSION['rivune_login_window_started']) || time() - (int)$_SESSION['rivune_login_window_started'] >= 900) {
      $_SESSION['rivune_login_window_started'] = time();
      $_SESSION['rivune_login_failures'] = 1;
    } else {
      $_SESSION['rivune_login_failures'] = (int)($_SESSION['rivune_login_failures'] ?? 0) + 1;
    }
  }
}

function rivune_admin_login_clear(PDO $pdo, string $username, string $secret): void {
  unset($_SESSION['rivune_login_window_started'], $_SESSION['rivune_login_failures']);
  if (!rivune_admin_login_throttle_ensure($pdo)) return;
  try {
    $stmt = $pdo->prepare('DELETE FROM admin_login_throttle WHERE identity_hash=?');
    $stmt->execute([rivune_admin_login_key($username, $secret)]);
  } catch (Throwable $e) {
    // A throttle cleanup failure must not block a valid administrator login.
  }
}
