<?php
declare(strict_types=1);

/**
 * Best-effort admin audit log. Logging failures must never break uploads,
 * authentication, playback, or other admin operations.
 */
function rivune_admin_logs_ensure(PDO $pdo): bool {
  static $attempted = false;
  static $ready = false;
  if ($attempted) return $ready;
  $attempted = true;
  try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_activity_logs (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      event_type VARCHAR(48) NOT NULL,
      status VARCHAR(16) NOT NULL,
      message VARCHAR(255) NOT NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_admin_logs_created (created_at),
      INDEX idx_admin_logs_event (event_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Gradually prune old audit rows; bounded work avoids long shared-host requests.
    $pdo->exec("DELETE FROM admin_activity_logs WHERE created_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 180 DAY) LIMIT 200");
    $ready = true;
  } catch (Throwable $e) {
    $ready = false;
  }
  return $ready;
}

function rivune_admin_log(PDO $pdo, string $event, string $status, string $message): bool {
  if (!rivune_admin_logs_ensure($pdo)) return false;
  try {
    $allowedEvents = ['login','logout','upload','delete','artwork','integrity_scan','backup','restore','maintenance','alert'];
    $allowedStatuses = ['success','failure','warning','info'];
    if (!in_array($event, $allowedEvents, true)) $event = 'admin_action';
    if (!in_array($status, $allowedStatuses, true)) $status = 'info';
    $message = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $message) ?? '');
    $message = substr($message, 0, 255);
    $stmt = $pdo->prepare('INSERT INTO admin_activity_logs (event_type,status,message) VALUES (?,?,?)');
    $stmt->execute([$event, $status, $message]);
    return true;
  } catch (Throwable $e) {
    return false;
  }
}
