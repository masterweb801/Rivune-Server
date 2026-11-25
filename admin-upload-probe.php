<?php
declare(strict_types=1);

// This endpoint deliberately does not save files or touch the music database.
// Its only purpose is to determine whether PHP receives multipart file uploads.
require_once __DIR__ . '/lib/upload_diagnostics.php';
require_once __DIR__ . '/lib/upload_limits.php';

$requestId = '';
try {
  $requestId = bin2hex(random_bytes(6));
} catch (Throwable $e) {
  $requestId = substr(sha1(uniqid('', true)), 0, 12);
}

rivune_upload_diag($requestId, 'probe_script_entered', [
  'content_length' => (int)($_SERVER['CONTENT_LENGTH'] ?? 0),
  'content_type' => $_SERVER['CONTENT_TYPE'] ?? '',
  'post_max_size' => ini_get('post_max_size'),
  'upload_max_filesize' => ini_get('upload_max_filesize'),
]);

require __DIR__ . '/lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');
header('Pragma: no-cache');

if (!isset($pdo)) {
  http_response_code(503);
  echo json_encode(['ok' => false, 'error' => 'RIVUNE is not installed.']);
  exit;
}

require_admin();
rivune_upload_diag($requestId, 'probe_admin_authenticated');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  http_response_code(405);
  header('Allow: POST');
  echo json_encode(['ok' => false, 'error' => 'POST required.']);
  exit;
}

// PHP leaves both $_POST and $_FILES empty when post_max_size is exceeded.
$requestBytes = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
$postLimit = rivune_ini_size_bytes(ini_get('post_max_size'));
if ($postLimit > 0 && $requestBytes > $postLimit) {
  rivune_upload_diag($requestId, 'probe_post_limit_exceeded', ['request_bytes' => $requestBytes, 'limit_bytes' => $postLimit]);
  http_response_code(413);
  echo json_encode(['ok' => false, 'error' => 'Request exceeded PHP post_max_size.']);
  exit;
}

csrf_check();
rivune_upload_diag($requestId, 'probe_csrf_passed');

$file = $_FILES['probe_file'] ?? null;
if (!is_array($file)) {
  rivune_upload_diag($requestId, 'probe_file_missing', ['files_count' => count($_FILES)]);
  echo json_encode(['ok' => false, 'error' => 'PHP received the request, but no uploaded file was present.', 'request_id' => $requestId]);
  exit;
}

$errorCode = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
$fileSize = (int)($file['size'] ?? 0);
rivune_upload_diag($requestId, 'probe_file_parsed', ['upload_error' => $errorCode, 'file_bytes' => $fileSize]);

if ($errorCode !== UPLOAD_ERR_OK) {
  $messages = [
    UPLOAD_ERR_INI_SIZE => 'File exceeded upload_max_filesize.',
    UPLOAD_ERR_FORM_SIZE => 'File exceeded the form upload limit.',
    UPLOAD_ERR_PARTIAL => 'PHP received only part of the file.',
    UPLOAD_ERR_NO_FILE => 'No file was selected.',
    UPLOAD_ERR_NO_TMP_DIR => 'PHP temporary upload directory is missing.',
    UPLOAD_ERR_CANT_WRITE => 'PHP could not write the temporary upload.',
    UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the upload.',
  ];
  $message = $messages[$errorCode] ?? ('Unknown PHP upload error ' . $errorCode . '.');
  rivune_upload_diag($requestId, 'probe_upload_error', ['upload_error' => $errorCode]);
  echo json_encode(['ok' => false, 'error' => $message, 'request_id' => $requestId]);
  exit;
}

$tmpName = (string)($file['tmp_name'] ?? '');
$isUploaded = $tmpName !== '' && is_uploaded_file($tmpName);
rivune_upload_diag($requestId, 'probe_complete', ['file_bytes' => $fileSize, 'is_uploaded_file' => $isUploaded ? 'yes' : 'no']);

echo json_encode([
  'ok' => $isUploaded && $fileSize > 0,
  'received' => $isUploaded,
  'file_bytes' => $fileSize,
  'message' => $isUploaded ? 'PHP successfully received the test file. No file was saved.' : 'PHP ran, but the temporary upload was not valid.',
  'request_id' => $requestId,
]);
