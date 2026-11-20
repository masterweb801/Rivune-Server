<?php
declare(strict_types=1);
// Public audio delivery does not use sessions; avoid session I/O and locking on every Range request.
$isPublicStreamRequest = basename($_SERVER['SCRIPT_NAME'] ?? '') === 'stream.php';
if (!$isPublicStreamRequest && session_status() !== PHP_SESSION_ACTIVE) {
  ini_set('session.use_strict_mode', '1');
  ini_set('session.use_only_cookies', '1');
  session_set_cookie_params(['httponly'=>true,'secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off','samesite'=>'Lax']);
  session_start();
}
$configFile = dirname(__DIR__) . '/storage/config.php';
if (!is_file($configFile)) {
  if (basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'install.php') { http_response_code(503); exit('RIVUNE is not installed yet. Open /install.php to configure it.'); }
  return;
}
$config = require $configFile;
try {
  $pdo = new PDO(
    'mysql:host='.$config['db_host'].';dbname='.$config['db_name'].';charset=utf8mb4',
    $config['db_user'], $config['db_pass'],
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]
  );
} catch (Throwable $e) { http_response_code(500); exit('Database connection failed. Check the database settings.'); }
function csrf_token(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function csrf_check(): void {
  $sessionToken = $_SESSION['csrf'] ?? null;
  $postedToken = $_POST['csrf'] ?? null;
  if (!is_string($sessionToken) || $sessionToken === '' || !is_string($postedToken) || !hash_equals($sessionToken, $postedToken)) {
    http_response_code(419);
    exit('Invalid security token. Refresh and try again.');
  }
}
function is_admin(): bool { return !empty($_SESSION['admin_id']); }
function require_admin(): void {
  if (!is_admin()) { http_response_code(401); exit('Admin login required.'); }
  header('Cache-Control: no-store, private, max-age=0');
  header('Pragma: no-cache');

  // A config-backed version revokes every previously issued admin session
  // without needing access to PHP's server-managed session files.
  global $config;
  $expectedVersion = max(1, (int)($config['admin_session_version'] ?? 1));
  $sessionVersion = isset($_SESSION['admin_session_version']) ? (int)$_SESSION['admin_session_version'] : 0;
  if ($sessionVersion !== $expectedVersion) {
    unset($_SESSION['admin_id'], $_SESSION['admin_authenticated_at'], $_SESSION['admin_last_activity'], $_SESSION['admin_session_version']);
    session_regenerate_id(true);
    header('Location: admin.php?session=revoked', true, 303);
    exit;
  }

  $now = time();
  $lastActivity = (int)($_SESSION['admin_last_activity'] ?? $now);
  $authenticatedAt = (int)($_SESSION['admin_authenticated_at'] ?? $now);
  if ($now - $lastActivity > 1800 || $now - $authenticatedAt > 43200) {
    unset($_SESSION['admin_id'], $_SESSION['admin_last_activity'], $_SESSION['admin_authenticated_at'], $_SESSION['admin_session_version']);
    session_regenerate_id(true);
    header('Location: admin.php?session=expired', true, 303);
    exit;
  }
  $_SESSION['admin_authenticated_at'] = $authenticatedAt;
  $_SESSION['admin_last_activity'] = $now;
}
function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
