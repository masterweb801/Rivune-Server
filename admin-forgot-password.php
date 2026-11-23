<?php
declare(strict_types=1);
require __DIR__.'/lib/bootstrap.php';
require_once __DIR__.'/lib/admin_recovery.php';
if (!isset($pdo)) { header('Location: install.php'); exit; }
header('Cache-Control: no-store, private, max-age=0');
header('Pragma: no-cache');

$error = '';
$success = '';
$verified = !empty($_SESSION['rivune_recovery_verified_at']) && time() - (int)$_SESSION['rivune_recovery_verified_at'] <= 300;
if (!$verified) unset($_SESSION['rivune_recovery_verified_at']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';
  if ($action === 'verify_answers' && !$verified) {
    $passwordHash = (string)($config['admin_pass_hash'] ?? '');
    $limit = rivune_admin_login_is_limited($pdo, '__recovery__', $passwordHash);
    if ($limit['limited']) {
      $error = 'Recovery is temporarily unavailable. Please wait before trying again.';
    } elseif (rivune_recovery_answers_match($config, $_POST)) {
      rivune_admin_login_clear($pdo, '__recovery__', $passwordHash);
      session_regenerate_id(true);
      $_SESSION['csrf'] = bin2hex(random_bytes(32));
      $_SESSION['rivune_recovery_verified_at'] = time();
      $verified = true;
    } else {
      rivune_admin_login_record_failure($pdo, '__recovery__', $passwordHash);
      $error = 'The answers could not be verified. Check your answers and try again.';
    }
  } elseif ($action === 'reset_password' && $verified) {
    if (time() - (int)$_SESSION['rivune_recovery_verified_at'] > 300) {
      unset($_SESSION['rivune_recovery_verified_at']);
      $verified = false;
      $error = 'Your recovery verification expired. Please answer the questions again.';
    } else {
      $new = isset($_POST['new_password']) && is_string($_POST['new_password']) ? $_POST['new_password'] : '';
      $confirm = isset($_POST['confirm_password']) && is_string($_POST['confirm_password']) ? $_POST['confirm_password'] : '';
      if (strlen($new) < 12) $error = 'The new password must be at least 12 characters long.';
      elseif ($new !== $confirm) $error = 'The new password and confirmation do not match.';
      else {
        $updatedConfig = $config;
        $updatedConfig['admin_pass_hash'] = password_hash($new, PASSWORD_DEFAULT);
        $updatedConfig['admin_session_version'] = max(1, (int)($config['admin_session_version'] ?? 1)) + 1;
        if (!rivune_admin_save_config($updatedConfig)) {
          $error = 'Could not safely update the administrator password. Please contact your hosting administrator.';
        } else {
          // End this recovery session and require a fresh normal administrator login.
          $_SESSION = [];
          session_regenerate_id(true);
          $_SESSION['csrf'] = bin2hex(random_bytes(32));
          $verified = false;
          $success = 'Your password has been reset. You can now sign in with your new password.';
        }
      }
    }
  } else {
    $error = 'This recovery step is no longer valid. Start again.';
    unset($_SESSION['rivune_recovery_verified_at']);
    $verified = false;
  }
}
?><!doctype html>
<html lang="en">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>Recover Admin Password | RIVUNE</title>
<link rel="icon" type="image/png" href="public/assets/rivune-logo.png">
<link rel="stylesheet" href="assets/css/tokens.css?v=design-tokens-1">
<link rel="stylesheet" href="assets/css/auth.css?v=auth-split-1">
<body>
<main class="auth-wrap">
  <a class="brand" href="admin.php"><img class="brand-logo" src="public/assets/rivune-logo.png" alt="RIVUNE logo"><span>RIVUNE<small>ADMIN ACCOUNT RECOVERY</small></span></a>
  <section class="panel">
    <p class="eyebrow">ACCOUNT RECOVERY</p>
    <h1>Reset administrator password</h1>
    <p class="muted">Answer all three security questions to verify your identity. Recovery verification expires after five minutes.</p>
    <?php if ($error): ?><p class="error"><?= h($error) ?></p><?php endif; ?>
    <?php if ($success): ?><p class="success"><?= h($success) ?></p><p><a class="text-link" href="admin.php">Return to administrator login →</a></p>
    <?php elseif ($verified): ?>
      <form method="post" class="form" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="reset_password">
        <label>New password<input type="password" name="new_password" required minlength="12" autocomplete="new-password"></label>
        <label>Confirm new password<input type="password" name="confirm_password" required minlength="12" autocomplete="new-password"></label>
        <button class="primary" type="submit">Reset password</button>
      </form>
    <?php else: ?>
      <form method="post" class="form" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="verify_answers">
        <label>My favourite color?<input type="password" name="color" required maxlength="500" autocomplete="off"></label>
        <label>My first cat's name?<input type="password" name="cat" required maxlength="500" autocomplete="off"></label>
        <label>LOML?<input type="password" name="loml" required maxlength="500" autocomplete="off"></label>
        <button class="primary" type="submit">Verify answers</button>
      </form>
    <?php endif; ?>
    <?php if (!$success): ?><p class="muted"><a class="text-link" href="admin.php">← Back to administrator login</a></p><?php endif; ?>
  </section>
</main>
</body>
</html>
