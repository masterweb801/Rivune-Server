<?php
declare(strict_types=1);
require __DIR__.'/lib/bootstrap.php';
require_once __DIR__.'/lib/admin_logs.php';
require_once __DIR__.'/lib/admin_recovery.php';
if (!isset($pdo)) { header('Location: install.php'); exit; }
require_admin();

$error = '';
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';
  $current = isset($_POST['current_password']) && is_string($_POST['current_password']) ? $_POST['current_password'] : '';
  $currentHash = (string)($config['admin_pass_hash'] ?? '');

  if (!password_verify($current, $currentHash)) {
    $error = 'Your current password is incorrect.';
    rivune_admin_log($pdo, 'admin_action', 'failure', 'Administrator security settings change failed: current password did not match.');
  } elseif ($action === 'change_password') {
    $new = isset($_POST['new_password']) && is_string($_POST['new_password']) ? $_POST['new_password'] : '';
    $confirm = isset($_POST['confirm_password']) && is_string($_POST['confirm_password']) ? $_POST['confirm_password'] : '';
    if (strlen($new) < 12) $error = 'The new password must be at least 12 characters long.';
    elseif ($new !== $confirm) $error = 'The new password and confirmation do not match.';
    elseif (hash_equals($current, $new)) $error = 'Choose a new password different from your current password.';
    else {
      $updatedConfig = $config;
      $updatedConfig['admin_pass_hash'] = password_hash($new, PASSWORD_DEFAULT);
      $updatedConfig['admin_session_version'] = max(1, (int)($config['admin_session_version'] ?? 1)) + 1;
      if (!rivune_admin_save_config($updatedConfig)) $error = 'Could not safely update the administrator password. Check storage folder permissions and try again.';
      else {
        $config = $updatedConfig;
        session_regenerate_id(true);
        $_SESSION['admin_session_version'] = $updatedConfig['admin_session_version'];
        $_SESSION['admin_authenticated_at'] = time();
        $_SESSION['admin_last_activity'] = time();
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        rivune_admin_log($pdo, 'admin_action', 'success', 'Administrator password changed successfully.');
        $success = 'Your administrator password has been changed.';
      }
    }
  } elseif ($action === 'change_recovery_answers') {
    $answers = [];
    foreach (['color','cat','loml'] as $key) {
      $value = isset($_POST[$key]) && is_string($_POST[$key]) ? rivune_recovery_normalize($_POST[$key]) : '';
      if ($value === '' || strlen($value) > 500) { $error = 'Enter an answer for all three questions. Each answer must be 500 characters or fewer.'; break; }
      $answers[$key] = password_hash($value, PASSWORD_DEFAULT);
    }
    if ($error === '') {
      $updatedConfig = $config;
      $updatedConfig['admin_recovery_answers'] = $answers;
      if (!rivune_admin_save_config($updatedConfig)) $error = 'Could not safely save the recovery answers. Check storage folder permissions and try again.';
      else {
        $config = $updatedConfig;
        session_regenerate_id(true);
        $_SESSION['admin_authenticated_at'] = time();
        $_SESSION['admin_last_activity'] = time();
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        rivune_admin_log($pdo, 'admin_action', 'success', 'Administrator recovery answers updated.');
        $success = 'Your recovery answers have been saved securely.';
      }
    }
  } else {
    $error = 'Invalid security settings request.';
  }
}
?><!doctype html>
<html lang="en">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>Account Security | RIVUNE Admin</title>
<link rel="icon" type="image/png" href="public/assets/rivune-logo.png">
<link rel="stylesheet" href="assets/css/tokens.css?v=design-tokens-1">
<link rel="stylesheet" href="assets/css/app.css?v=modular-theme-1">
<body>
<main class="admin-wrap">
<?php $adminActivePage = 'security'; require __DIR__.'/lib/admin_navigation.php'; ?>
<section class="admin-content">
  <div class="section-head"><div><p class="eyebrow">ACCOUNT SECURITY</p><h1>Account security</h1></div></div>
  <?php if ($error): ?><p class="error"><?= h($error) ?></p><?php endif; ?>
  <?php if ($success): ?><p class="success"><?= h($success) ?></p><?php endif; ?>
  <section class="panel auth-panel">
    <h2>Change password</h2>
    <p class="muted">Confirm your current password before choosing a new one. Use at least 12 characters.</p>
    <form method="post" class="form" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="change_password">
      <label>Current password<input type="password" name="current_password" required autocomplete="current-password"></label>
      <label>New password<input type="password" name="new_password" required minlength="12" autocomplete="new-password"></label>
      <label>Confirm new password<input type="password" name="confirm_password" required minlength="12" autocomplete="new-password"></label>
      <button class="primary" type="submit">Update password</button>
    </form>
  </section>
  <section class="panel auth-panel">
    <h2>Forgot-password recovery answers</h2>
    <p class="muted">These questions are fixed. Only their answers can be changed here. Your current admin password is required to update them. Answers are stored as password hashes and are never displayed again.</p>
    <?php if (!rivune_recovery_answers_ready($config)): ?><p class="admin-warning">Recovery questions are not configured yet. Set all three answers below to enable password recovery.</p><?php else: ?><p class="success">Recovery questions are configured.</p><?php endif; ?>
    <form method="post" class="form" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="change_recovery_answers">
      <label>My favourite color?<input type="password" name="color" required maxlength="500" autocomplete="new-password" placeholder="Enter a new answer"></label>
      <label>My first cat's name?<input type="password" name="cat" required maxlength="500" autocomplete="new-password" placeholder="Enter a new answer"></label>
      <label>LOML?<input type="password" name="loml" required maxlength="500" autocomplete="new-password" placeholder="Enter a new answer"></label>
      <label>Confirm current admin password<input type="password" name="current_password" required autocomplete="current-password"></label>
      <button class="primary" type="submit">Save recovery answers</button>
    </form>
  </section>
</section>
</main>
</body>
</html>
