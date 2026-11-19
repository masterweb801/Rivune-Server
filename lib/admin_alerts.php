<?php
declare(strict_types=1);

/**
 * Best-effort request-time alerts for shared hosting. Alert failures must never
 * interrupt playback, uploads, authentication, or other administrator actions.
 */
function rivune_alerts_ensure(PDO $pdo): bool {
  static $attempted = false;
  static $ready = false;
  if ($attempted) return $ready;
  $attempted = true;
  try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_alerts (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      alert_key VARCHAR(96) NOT NULL,
      severity VARCHAR(16) NOT NULL,
      title VARCHAR(120) NOT NULL,
      message VARCHAR(255) NOT NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      resolved_at TIMESTAMP NULL DEFAULT NULL,
      INDEX idx_admin_alerts_open (resolved_at, id),
      INDEX idx_admin_alerts_key (alert_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $ready = true;
  } catch (Throwable $e) { $ready = false; }
  return $ready;
}

function rivune_alert_raise(PDO $pdo, string $key, string $severity, string $title, string $message): bool {
  if (!rivune_alerts_ensure($pdo)) return false;
  $severity = in_array($severity, ['info','warning','critical'], true) ? $severity : 'warning';
  $key = substr(preg_replace('/[^a-zA-Z0-9_.:-]/', '_', $key) ?? 'general', 0, 96);
  $clean = static function(string $value, int $limit): string {
    $value = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value) ?? '');
    return substr($value, 0, $limit);
  };
  try {
    $check = $pdo->prepare('SELECT id FROM admin_alerts WHERE alert_key=? AND resolved_at IS NULL LIMIT 1');
    $check->execute([$key]);
    if ($check->fetchColumn()) return true;
    $insert = $pdo->prepare('INSERT INTO admin_alerts (alert_key,severity,title,message) VALUES (?,?,?,?)');
    $insert->execute([$key,$severity,$clean($title,120),$clean($message,255)]);
    if (function_exists('rivune_admin_log')) rivune_admin_log($pdo, 'alert', $severity === 'critical' ? 'failure' : 'warning', $clean($title . ': ' . $message, 255));
    return true;
  } catch (Throwable $e) { return false; }
}

function rivune_alert_open_count(PDO $pdo): int {
  if (!rivune_alerts_ensure($pdo)) return 0;
  try { return (int)$pdo->query('SELECT COUNT(*) FROM admin_alerts WHERE resolved_at IS NULL')->fetchColumn(); }
  catch (Throwable $e) { return 0; }
}

function rivune_alert_check_storage(PDO $pdo, array $storage, array $config): void {
  // Hosting quota is intentionally not inferred. Only use an explicit app limit
  // configured by the site owner, and only when the measurement is complete.
  $limit = isset($config['rivune_app_storage_limit_bytes']) ? (int)$config['rivune_app_storage_limit_bytes'] : 0;
  if ($limit <= 0 || !empty($storage['partial'])) return;
  $used = (int)($storage['measured'] ?? 0);
  $percent = ($used / $limit) * 100;
  $threshold = $percent >= 95 ? 95 : ($percent >= 90 ? 90 : ($percent >= 75 ? 75 : 0));
  if ($threshold > 0) {
    $severity = $threshold >= 95 ? 'critical' : 'warning';
    rivune_alert_raise($pdo, 'storage_'.$threshold, $severity, 'RIVUNE storage at '.$threshold.'%', 'Measured application storage is '.number_format($percent,1).'% of the explicitly configured application limit.');
  }
}
