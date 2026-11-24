<?php
declare(strict_types=1);

/**
 * Private, bounded upload diagnostics for shared hosting where PHP error logs
 * are not available to the site administrator. The file lives under storage,
 * which is blocked from direct HTTP access by storage/.htaccess.
 */
function rivune_upload_diag(string $requestId, string $phase, array $details = []): void {
  $path = dirname(__DIR__) . '/storage/upload-diagnostics.log';
  $parts = [];
  foreach ($details as $key => $value) {
    if (!is_scalar($value) && $value !== null) continue;
    $text = str_replace(["\r", "\n", "\t"], ' ', (string)$value);
    $parts[] = preg_replace('/[^a-zA-Z0-9_.:;=\- ]/', '?', (string)$key) . '=' . substr($text, 0, 180);
  }
  $line = '[' . date('Y-m-d H:i:s') . '] ' . $requestId . ' ' . $phase;
  if ($parts) $line .= ' ' . implode(' ', $parts);
  $line .= PHP_EOL;

  // Keep the private log bounded on low-quota shared hosting.
  if (is_file($path) && @filesize($path) > 262144) {
    @file_put_contents($path, "[log rotated] " . date('Y-m-d H:i:s') . PHP_EOL, LOCK_EX);
  }
  @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
  error_log('[RIVUNE upload ' . $requestId . '] ' . $phase . ($parts ? ' ' . implode(' ', $parts) : ''));
}
