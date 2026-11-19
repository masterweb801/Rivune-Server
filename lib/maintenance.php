<?php
declare(strict_types=1);

/**
 * Request-time maintenance switch.
 * Public checks fail open if the setting table cannot be read, so a missing
 * setting/table never creates an accidental site-wide lockout.
 */
function rivune_maintenance_ensure(PDO $pdo): bool {
  try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS rivune_maintenance (
      id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
      enabled TINYINT(1) NOT NULL DEFAULT 0,
      message VARCHAR(500) NOT NULL DEFAULT '',
      updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("INSERT IGNORE INTO rivune_maintenance (id,enabled,message) VALUES (1,0,'We are making a few improvements. Please check back soon.')");
    return true;
  } catch (Throwable $e) {
    return false;
  }
}

function rivune_maintenance_state(PDO $pdo): array {
  try {
    $row = $pdo->query('SELECT enabled,message,updated_at FROM rivune_maintenance WHERE id=1')->fetch();
    if (!$row) return ['enabled'=>false,'message'=>'We are making a few improvements. Please check back soon.','updated_at'=>null];
    $message = trim((string)($row['message'] ?? ''));
    return [
      'enabled'=>(int)$row['enabled'] === 1,
      'message'=>$message !== '' ? $message : 'We are making a few improvements. Please check back soon.',
      'updated_at'=>$row['updated_at'] ?? null
    ];
  } catch (Throwable $e) {
    return ['enabled'=>false,'message'=>'We are making a few improvements. Please check back soon.','updated_at'=>null];
  }
}

function rivune_maintenance_save(PDO $pdo, bool $enabled, string $message): bool {
  try {
    $stmt = $pdo->prepare('INSERT INTO rivune_maintenance (id,enabled,message) VALUES (1,?,?) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled), message=VALUES(message)');
    $stmt->execute([$enabled ? 1 : 0, $message]);
    return true;
  } catch (Throwable $e) {
    return false;
  }
}

function rivune_maintenance_enforce(PDO $pdo, string $responseType = 'html'): void {
  $state = rivune_maintenance_state($pdo);
  if (!$state['enabled']) return;

  http_response_code(503);
  header('Retry-After: 3600');
  header('Cache-Control: no-store, no-cache, must-revalidate');
  header('X-Robots-Tag: noindex, nofollow');

  if ($responseType === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
      'error'=>'RIVUNE is temporarily under maintenance.',
      'message'=>$state['message'],
      'maintenance'=>true
    ], JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
  }

  if ($responseType === 'text') {
    header('Content-Type: text/plain; charset=utf-8');
    exit("RIVUNE is temporarily under maintenance.\n".$state['message']);
  }

  header('Content-Type: text/html; charset=utf-8');
  $safeMessage = nl2br(htmlspecialchars($state['message'], ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'));
  echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#11111d"><meta name="robots" content="noindex,nofollow"><title>RIVUNE | Maintenance</title><link rel="icon" type="image/png" href="public/assets/rivune-logo.png"><style>*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;background:#10101a;color:#f4f1ff;font-family:Inter,system-ui,-apple-system,Segoe UI,sans-serif}.maintenance{width:min(560px,100%);text-align:center;padding:44px 30px;border:1px solid #ffffff18;border-radius:26px;background:linear-gradient(145deg,#1c1b2b,#141421);box-shadow:0 24px 80px #0007}.logo{width:76px;height:76px;object-fit:contain;margin-bottom:20px}.eyebrow{font-size:11px;letter-spacing:.22em;color:#b7a5ff;font-weight:700}.maintenance h1{font-size:clamp(30px,7vw,46px);margin:12px 0}.maintenance p{color:#c2bfd0;line-height:1.8;margin:14px 0 0}.line{width:54px;height:3px;border-radius:9px;background:#a78bfa;margin:24px auto 0}</style></head><body><main class="maintenance"><img class="logo" src="public/assets/rivune-logo.png" alt="RIVUNE"><div class="eyebrow">RIVUNE · MUSIC WITHOUT BOUNDARIES</div><h1>We’ll be right back.</h1><p>'.$safeMessage.'</p><div class="line"></div></main></body></html>';
  exit;
}
