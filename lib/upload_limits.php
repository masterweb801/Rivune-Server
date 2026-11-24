<?php
declare(strict_types=1);
function rivune_ini_size_bytes($value): int {
  $value = trim((string)$value);
  if ($value === '' || $value === '-1') return 0;
  if (!preg_match('/^([0-9]+(?:\\.[0-9]+)?)\\s*([KMG]?)$/i', $value, $match)) return 0;
  $bytes = (float)$match[1];
  $unit = strtoupper($match[2] ?? '');
  if ($unit === 'K') $bytes *= 1024;
  elseif ($unit === 'M') $bytes *= 1024 * 1024;
  elseif ($unit === 'G') $bytes *= 1024 * 1024 * 1024;
  return (int)min(PHP_INT_MAX, floor($bytes));
}
function rivune_upload_limits(): array {
  $maxFile = 9 * 1024 * 1024;
  $phpFile = rivune_ini_size_bytes(ini_get('upload_max_filesize'));
  if ($phpFile > 0) $maxFile = min($maxFile, $phpFile);
  $maxBatch = 18 * 1024 * 1024;
  $phpPost = rivune_ini_size_bytes(ini_get('post_max_size'));
  if ($phpPost > 0) $maxBatch = min($maxBatch, max(0, $phpPost - 1024 * 1024));
  $maxFiles = (int)ini_get('max_file_uploads');
  if ($maxFiles < 1) $maxFiles = 20;
  return [
    'max_file_bytes'=>$maxFile,
    'max_batch_bytes'=>$maxBatch,
    'max_files'=>$maxFiles,
    'php_upload_max'=>$phpFile,
    'php_post_max'=>$phpPost,
    'php_max_input_time'=>(int)ini_get('max_input_time'),
    'php_max_execution_time'=>(int)ini_get('max_execution_time')
  ];
}
function rivune_format_upload_mb(int $bytes): string {
  return number_format($bytes / (1024 * 1024), 1);
}
