<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) {
  ini_set('session.use_strict_mode', '1');
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
function csrf_check(): void { if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { http_response_code(419); exit('Invalid security token. Refresh and try again.'); } }
function is_admin(): bool { return !empty($_SESSION['admin_id']); }
function require_admin(): void { if (!is_admin()) { http_response_code(401); exit('Admin login required.'); } }
function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
