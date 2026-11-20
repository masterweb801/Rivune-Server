<?php
declare(strict_types=1);

require_once __DIR__.'/admin_auth.php';

function rivune_recovery_normalize(string $answer): string {
  $answer = trim(preg_replace('/\\s+/u', ' ', $answer) ?? trim($answer));
  return function_exists('mb_strtolower') ? mb_strtolower($answer, 'UTF-8') : strtolower($answer);
}

function rivune_recovery_answers_ready(array $config): bool {
  $answers = $config['admin_recovery_answers'] ?? null;
  if (!is_array($answers)) return false;
  foreach (['color','cat','loml'] as $key) {
    if (!isset($answers[$key]) || !is_string($answers[$key]) || $answers[$key] === '') return false;
  }
  return true;
}

function rivune_recovery_answers_match(array $config, array $submitted): bool {
  if (!rivune_recovery_answers_ready($config)) return false;
  $stored = $config['admin_recovery_answers'];
  foreach (['color','cat','loml'] as $key) {
    $answer = $submitted[$key] ?? null;
    if (!is_string($answer) || $answer === '') return false;
    $normalized = rivune_recovery_normalize($answer);
    if ($normalized === '' || !password_verify($normalized, (string)$stored[$key])) return false;
  }
  return true;
}

/** Safely replace the protected PHP configuration without exposing secrets. */
function rivune_admin_save_config(array $updated): bool {
  $configFile = dirname(__DIR__).'/storage/config.php';
  $tempFile = $configFile.'.'.bin2hex(random_bytes(8)).'.tmp';
  $php = "<?php\nreturn ".var_export($updated, true).";\n";
  $written = @file_put_contents($tempFile, $php, LOCK_EX);
  if ($written === false) {
    if (is_file($tempFile)) @unlink($tempFile);
    return false;
  }
  @chmod($tempFile, 0600);
  if (!@rename($tempFile, $configFile)) {
    @unlink($tempFile);
    return false;
  }
  @chmod($configFile, 0600);
  return true;
}
