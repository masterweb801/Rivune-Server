<?php
declare(strict_types=1);
require __DIR__.'/lib/bootstrap.php';
require_once __DIR__.'/lib/admin_center.php';
require_once __DIR__.'/lib/admin_logs.php';
require_once __DIR__.'/lib/admin_alerts.php';
require_once __DIR__.'/lib/admin_auth.php';
header('Cache-Control: no-store, private, max-age=0');
header('Pragma: no-cache');
if (!isset($pdo)) { header('Location: install.php'); exit; }

// Hash column is provisioned during installation; legacy hashes are not backfilled on page startup.

$error=''; $notice=(string)($_SESSION['rivune_admin_notice']??''); unset($_SESSION['rivune_admin_notice']); $saved=0; $duplicates=[]; $failed=[]; $search=trim((string)($_GET['q']??''));
$page=max(1,(int)($_GET['page']??1)); $perPage=25;
if ($_SERVER['REQUEST_METHOD']==='POST') {
  csrf_check(); $action=$_POST['action']??'';
  if ($action==='login') {
    $loginUsername = isset($_POST['username']) && is_string($_POST['username']) ? trim($_POST['username']) : '';
    $loginPassword = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
    $passwordHash = (string)($config['admin_pass_hash'] ?? '');
    $throttle = rivune_admin_login_is_limited($pdo, $loginUsername, $passwordHash);
    if ($throttle['limited']) {
      $error = 'Too many sign-in attempts. Please wait before trying again.';
    } elseif (hash_equals((string)$config['admin_user'], $loginUsername) && password_verify($loginPassword, $passwordHash)) {
      rivune_admin_login_clear($pdo, $loginUsername, $passwordHash);
      rivune_admin_log($pdo, 'login', 'success', 'Administrator signed in.');
      session_regenerate_id(true);
      $_SESSION['admin_id'] = 1;
      $_SESSION['admin_authenticated_at'] = time();
      $_SESSION['admin_last_activity'] = time();
      $_SESSION['admin_session_version'] = max(1, (int)($config['admin_session_version'] ?? 1));
      $_SESSION['csrf'] = bin2hex(random_bytes(32));
      header('Location: admin.php');
      exit;
    } else {
      rivune_admin_login_record_failure($pdo, $loginUsername, $passwordHash);
      rivune_admin_log($pdo, 'login', 'failure', 'An administrator sign-in attempt failed.');
      $error = 'Incorrect username or password.';
    }
  } elseif ($action==='logout') { require_admin(); rivune_admin_log($pdo, 'logout', 'success', 'Administrator signed out.'); $_SESSION=[]; session_regenerate_id(true); header('Location: admin.php'); exit; }
  elseif ($action==='refresh_storage') {
    require_admin();
    unset($_SESSION['rivune_storage_cache'], $_SESSION['rivune_storage_cache_at']);
    $snapshot=rivune_admin_storage($pdo);
    rivune_alert_check_storage($pdo,$snapshot,$config);
    $_SESSION['rivune_admin_notice']='Storage measurements refreshed.';
    header('Location: admin.php'); exit;
  } elseif ($action==='refresh_health') {
    require_admin();
    unset($_SESSION['rivune_health_cache'], $_SESSION['rivune_health_cache_at']);
    $health=rivune_admin_diagnostics($pdo);
    foreach ($health['checks'] as $diagnostic) if (!$diagnostic['ok']) rivune_alert_raise($pdo,'system_'.substr(sha1($diagnostic['label']),0,12),'critical','System check failed: '.$diagnostic['label'],$diagnostic['detail']);
    $_SESSION['rivune_admin_notice']='System diagnostics completed.';
    header('Location: admin.php'); exit;
  } elseif ($action==='delete') {
    require_admin(); $id=filter_var($_POST['id']??null,FILTER_VALIDATE_INT);
    if ($id) {
      $q=$pdo->prepare('SELECT stored_name FROM tracks WHERE id=?');
      $q->execute([$id]);
      $track=$q->fetch();
      if ($track) {
        $path=rivune_music_storage_path((string)$track['stored_name']);
        if ($path === null) {
          $error='This track has an invalid stored filename. Its record and files were left untouched; run the integrity scanner.';
        } else {
          $pdo->prepare('DELETE FROM tracks WHERE id=?')->execute([$id]);
          if (is_file($path) && !@unlink($path)) {
            rivune_admin_log($pdo, 'delete', 'warning', 'Deleted track record #'.(int)$id.' but its validated MP3 file could not be removed.');
          }
          foreach (['jpg','png','webp'] as $artExt) {
            $artPath=__DIR__.'/storage/artwork/'.(int)$id.'.'.$artExt;
            if (is_file($artPath) && !is_link($artPath)) @unlink($artPath);
          }
          rivune_admin_log($pdo, 'delete', 'success', 'Deleted track record #'.(int)$id.'.');
          $notice='Track deleted.';
        }
      }
    }
  }
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['return_to'] ?? '') === 'admin-songs.php') {
  $_SESSION['rivune_admin_notice'] = $notice !== '' ? $notice : 'Song management updated.';
  $returnQuery = isset($_POST['return_q']) && is_string($_POST['return_q']) ? trim($_POST['return_q']) : '';
  $returnPage = max(1, (int)($_POST['return_page'] ?? 1));
  header('Location: admin-songs.php?' . http_build_query(['q'=>$returnQuery,'page'=>$returnPage]));
  exit;
}
if (is_admin()) require_admin();

$where = $search !== '' ? " WHERE title LIKE ? OR artist LIKE ? OR album LIKE ?" : '';
$countTracks = $pdo->prepare('SELECT COUNT(*) FROM tracks'.$where);
if ($search !== '') { $term='%'.$search.'%'; $countTracks->execute([$term,$term,$term]); } else { $countTracks->execute(); }
$totalTracks=(int)$countTracks->fetchColumn();
$totalPages=max(1,(int)ceil($totalTracks/$perPage));
$page=min($page,$totalPages);
$listQuery=$pdo->prepare('SELECT id,title,artist,album FROM tracks'.$where.' ORDER BY id DESC LIMIT '.$perPage.' OFFSET '.(($page-1)*$perPage));
if ($search !== '') $listQuery->execute([$term,$term,$term]); else $listQuery->execute();
$tracks=$listQuery->fetchAll();
?><!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><title>RIVUNE | Admin</title><link rel="icon" type="image/png" href="/public/assets/rivune-logo.png"><link rel="apple-touch-icon" href="/public/assets/rivune-logo.png"><link rel="stylesheet" href="assets/css/tokens.css?v=design-tokens-2">
<link rel="stylesheet" href="assets/css/app.css?v=app-core-5">
<link rel="stylesheet" href="assets/css/controls.css?v=controls-1">
<link rel="stylesheet" href="assets/css/forms.css?v=forms-1">
<link rel="stylesheet" href="assets/css/admin.css?v=admin-module-2"><body><main class="admin-wrap"><?php $adminActivePage = 'overview'; require __DIR__ . '/lib/admin_navigation.php'; ?><?php if(isset($_GET['installed'])): ?><p class="success">Installation complete. Sign in to upload your first tracks.</p><?php endif; ?><?php if($error): ?><p class="error"><?= h($error) ?></p><?php endif; ?><?php if($notice): ?><p class="success"><?= h($notice) ?></p><?php endif; ?>
<?php if(!is_admin()): ?><section class="panel auth-panel"><p class="eyebrow">RESTRICTED AREA</p><h1>Administrator login</h1><form method="post" class="form"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="login"><label>Username<input name="username" required autocomplete="username"></label><label>Password<input type="password" name="password" required autocomplete="current-password"></label><button class="primary" type="submit">Sign in</button><a class="text-link" href="admin-forgot-password.php">Forgot password?</a></form></section>
<?php else:
$adminStorage = $_SESSION['rivune_storage_cache'] ?? null;
$adminDiagnostics = $_SESSION['rivune_health_cache'] ?? null;
?>
<section class="admin-content"><section class="admin-overview">
<div class="admin-storage-panel"><h2>System tools</h2><p class="muted">Cached results are shown immediately. Checks run only when requested.</p>
<div class="admin-tool-actions">
<form method="post"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="refresh_storage"><button class="secondary">Refresh storage</button></form>
<form method="post"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="refresh_health"><button class="secondary">Run system diagnostics</button></form>
</div><a class="text-link" href="admin-alerts.php">View alerts →</a></div>
<div class="section-head"><div><p class="eyebrow">CONTROL CENTER</p><h1>System overview</h1></div><span class="muted"><?= $adminStorage && !empty($_SESSION['rivune_storage_cache_at']) ? 'Cached · ' . h(date('M j, Y · g:i A', (int)$_SESSION['rivune_storage_cache_at'])) : 'No saved storage snapshot' ?></span></div>
<?php if ($adminStorage): ?>
<div class="admin-metric-grid"><article class="admin-metric"><span>RIVUNE measured storage</span><strong><?= h(rivune_admin_format_bytes((int)$adminStorage['measured'])) ?></strong></article><article class="admin-metric"><span>Music library</span><strong><?= h(rivune_admin_format_bytes((int)$adminStorage['music']['bytes'])) ?></strong></article><article class="admin-metric"><span>Database size</span><strong><?= $adminStorage['database'] === null ? 'Unavailable' : h(rivune_admin_format_bytes((int)$adminStorage['database'])) ?></strong></article><article class="admin-metric"><span>Estimated remaining</span><strong><?= h(rivune_admin_format_bytes((int)($adminStorage['hosting_remaining'] ?? 0))) ?></strong><small><?= number_format((float)($adminStorage['hosting_percent'] ?? 0), 1) ?>% of 4.5 GB reference used</small></article></div>
<?php else: ?><div class="admin-storage-panel"><p class="muted">No saved storage snapshot. Use Refresh storage when needed.</p></div><?php endif; ?><p class="muted" style="font-size:12px">Usage is an estimate based on files and database size RIVUNE can measure. InfinityFree does not expose live account quota to PHP; 4.5 GB is your supplied reference, not a live hosting measurement. Other account files may not be included.</p>
<?php if ($adminDiagnostics): ?><div class="admin-storage-panel"><h2>System health · Cached</h2><p class="muted"><?= (int)$adminDiagnostics['passed'] ?> / <?= (int)$adminDiagnostics['total'] ?> checks passed</p><div class="admin-check-grid"><?php foreach ($adminDiagnostics['checks'] as $check): ?><div class="admin-check"><strong><?= h($check['label']) ?></strong><small><?= h($check['detail']) ?></small></div><?php endforeach; ?></div></div><?php else: ?><div class="admin-storage-panel"><p class="muted">No saved diagnostics. Run checks when needed.</p></div><?php endif; ?>
</section>
<section class="admin-storage-panel"><p class="eyebrow">SONG MANAGEMENT</p><h2>Music library tools</h2><p class="muted">Upload new music, manage existing tracks, or maintain album artwork from the Songs section in the navigation.</p><div class="admin-tool-actions"><a class="text-link" href="admin-upload.php">Upload songs →</a><a class="text-link" href="admin-songs.php">Manage songs →</a><a class="text-link" href="admin-extract-artwork.php">Artwork maintenance →</a></div></section></section><?php endif; ?></main>

</body></html>
