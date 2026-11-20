<?php
declare(strict_types=1);
require __DIR__.'/lib/bootstrap.php';
require_once __DIR__.'/lib/admin_logs.php';
if (!isset($pdo)) { header('Location: install.php'); exit; }
require_admin();

$error = '';
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $current = isset($_POST['current_password']) && is_string($_POST['current_password']) ? $_POST['current_password'] : '';
  $new = isset($_POST['new_password']) && is_string($_POST['new_password']) ? $_POST['new_password'] : '';
  $confirm = isset($_POST['confirm_password']) && is_string($_POST['confirm_password']) ? $_POST['confirm_password'] : '';
  $currentHash = (string)($config['admin_pass_hash'] ?? '');

  if (!password_verify($current, $currentHash)) {
    $error = 'Your current password is incorrect.';
    rivune_admin_log($pdo, 'admin_action', 'failure', 'Administrator password change failed: current password did not match.');
  } elseif (strlen($new) < 12) {
    $error = 'The new password must be at least 12 characters long.';
  } elseif ($new !== $confirm) {
    $error = 'The new password and confirmation do not match.';
  } elseif (hash_equals($current, $new)) {
    $error = 'Choose a new password different from your current password.';
  } else {
    $configFile = __DIR__.'/storage/config.php';
    $tempFile = $configFile.'.'.bin2hex(random_bytes(8)).'.tmp';
    $updatedConfig = $config;
    $updatedConfig['admin_pass_hash'] = password_hash($new, PASSWORD_DEFAULT);
    $php = "<?php\nreturn ".var_export($updatedConfig, true).";\n";
    $written = @file_put_contents($tempFile, $php, LOCK_EX);
    if ($written === false || !@rename($tempFile, $configFile)) {
      if (is_file($tempFile)) @unlink($tempFile);
      $error = 'Could not safely update the administrator password. Check storage folder permissions and try again.';
      rivune_admin_log($pdo, 'admin_action', 'failure', 'Administrator password change failed: configuration write failed.');
    } else {
      @chmod($configFile, 0600);
      $config = $updatedConfig;
      session_regenerate_id(true);
      $_SESSION['admin_authenticated_at'] = time();
      $_SESSION['admin_last_activity'] = time();
      $_SESSION['csrf'] = bin2hex(random_bytes(32));
      rivune_admin_log($pdo, 'admin_action', 'success', 'Administrator password changed successfully.');
      $success = 'Your administrator password has been changed.';
    }
  }
}
?><!doctype html>
<html lang="en">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Change Password | RIVUNE Admin</title>
<link rel="icon" type="image/png" href="public/assets/rivune-logo.png">
<link rel="stylesheet" href="assets/css/app.css">
<body>
<main class="admin-wrap">
<?php $adminActivePage = 'security'; require __DIR__.'/lib/admin_navigation.php'; ?>
<section class="admin-content">
  <div class="section-head"><div><p class="eyebrow">ACCOUNT SECURITY</p><h1>Change password</h1></div></div>
  <section class="panel auth-panel">
    <p class="muted">Confirm your current password before choosing a new one. Use at least 12 characters.</p>
    <?php if ($error): ?><p class="error"><?= h($error) ?></p><?php endif; ?>
    <?php if ($success): ?><p class="success"><?= h($success) ?></p><?php endif; ?>
    <form method="post" class="form" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <label>Current password<input type="password" name="current_password" required autocomplete="current-password"></label>
      <label>New password<input type="password" name="new_password" required minlength="12" autocomplete="new-password"></label>
      <label>Confirm new password<input type="password" name="confirm_password" required minlength="12" autocomplete="new-password"></label>
      <button class="primary" type="submit">Update password</button>
    </form>
  </section>
</section>
</main>
</body>
</html>
