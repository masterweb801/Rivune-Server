<?php
declare(strict_types=1);

/**
 * Private, bounded upload diagnostics. Prefer the private storage directory,
 * but fall back to PHP's temporary directory if shared-host permissions prevent
 * writing there. The fallback is outside the public document root.
 */
function rivune_upload_diag_paths(): array {
  $root = dirname(__DIR__);
  $paths = [$root . '/storage/upload-diagnostics.log'];
  $temp = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
  if ($temp !== '') {
    $paths[] = $temp . '/rivune-' . substr(hash('sha256', $root), 0, 16) . '-upload-diagnostics.log';
  }
  return $paths;
}

function rivune_upload_diag(string $requestId, string $phase, array $details = []): void {
  $parts = [];
  foreach ($details as $key => $value) {
    if (!is_scalar($value) && $value !== null) continue;
    $text = str_replace(["\\r", "\\n", "\\t"], ' ', (string)$value);
    $parts[] = preg_replace('/[^a-zA-Z0-9_.:;=\\- ]/', '?', (string)$key) . '=' . substr($text, 0, 180);
  }
  $line = '[' . date('Y-m-d H:i:s') . '] ' . $requestId . ' ' . $phase;
  if ($parts) $line .= ' ' . implode(' ', $parts);
  $line .= PHP_EOL;

  foreach (rivune_upload_diag_paths() as $path) {
    if (is_file($path) && @filesize($path) > 262144) {
      @file_put_contents($path, "[log rotated] " . date('Y-m-d H:i:s') . PHP_EOL, LOCK_EX);
    }
    if (@file_put_contents($path, $line, FILE_APPEND | LOCK_EX) !== false) break;
  }
  error_log('[RIVUNE upload ' . $requestId . '] ' . $phase . ($parts ? ' ' . implode(' ', $parts) : ''));
}
