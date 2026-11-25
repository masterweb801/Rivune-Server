<?php
declare(strict_types=1);

// Dedicated RIVUNE upload endpoint. This endpoint intentionally does not load
// the admin dashboard or song-list rendering code.
$requestId = bin2hex(random_bytes(6));
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');
@ini_set('display_errors', '0');
@set_time_limit(180);
ignore_user_abort(true);
ob_start();

function upload_reply(array $data, int $status = 200): void {
    while (ob_get_level() > 0) { @ob_end_clean(); }
    http_response_code($status);
    echo json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function upload_is_mp3(string $path): bool {
    $fp = @fopen($path, 'rb');
    if (!$fp) return false;
    $header = fread($fp, 10);
    if ($header === false) { fclose($fp); return false; }
    if (strlen($header) >= 3 && substr($header, 0, 3) === 'ID3') {
        if (strlen($header) !== 10 || !in_array(ord($header[3]), [2, 3, 4], true)) { fclose($fp); return false; }
        $tagSize = 0;
        for ($i = 6; $i < 10; $i++) {
            $byte = ord($header[$i]);
            if (($byte & 0x80) !== 0) { fclose($fp); return false; }
            $tagSize = ($tagSize << 7) | $byte;
        }
        if (fseek($fp, 10 + $tagSize, SEEK_SET) !== 0) { fclose($fp); return false; }
    } else { rewind($fp); }
    $data = fread($fp, 1048576);
    fclose($fp);
    if ($data === false || strlen($data) < 4) return false;
    for ($i = 0, $n = strlen($data) - 3; $i <= $n; $i++) {
        $b1 = ord($data[$i]); $b2 = ord($data[$i + 1]); $b3 = ord($data[$i + 2]);
        if ($b1 !== 0xFF || ($b2 & 0xE0) !== 0xE0) continue;
        $version = ($b2 >> 3) & 3; $layer = ($b2 >> 1) & 3;
        $bitrate = ($b3 >> 4) & 15; $sampleRate = ($b3 >> 2) & 3;
        if ($version !== 1 && $layer !== 0 && $bitrate !== 0 && $bitrate !== 15 && $sampleRate !== 3) return true;
    }
    return false;
}

try {
    require __DIR__ . '/lib/bootstrap.php';
    require_once __DIR__ . '/lib/upload_limits.php';
    require_once __DIR__ . '/lib/admin_auth.php';
    require_once __DIR__ . '/lib/track_processing.php';
    require_once __DIR__ . '/lib/admin_logs.php';
    require_once __DIR__ . '/lib/admin_alerts.php';

    if (!isset($pdo)) upload_reply(['ok'=>false,'error'=>'RIVUNE is not installed.'], 503);
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') upload_reply(['ok'=>false,'error'=>'POST required.'], 405);
    require_admin();

    // Check this before CSRF: PHP clears $_POST and $_FILES when post_max_size
    // is exceeded, so the token is no longer available in that case.
    $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    $postLimit = rivune_ini_size_bytes(ini_get('post_max_size'));
    if ($postLimit > 0 && $contentLength > $postLimit) {
        upload_reply(['ok'=>false,'error'=>'The selected batch exceeds the server request-size limit.'], 413);
    }
    csrf_check();

    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

    $limits = rivune_upload_limits();
    $batch = $_FILES['mp3'] ?? null;
    if (!is_array($batch) || !is_array($batch['name'] ?? null)) {
        upload_reply(['ok'=>false,'saved'=>0,'duplicates'=>0,'error'=>'Choose one or more MP3 files.'], 400);
    }
    $count = count($batch['name']);
    $totalBytes = array_sum(array_map('intval', $batch['size'] ?? []));
    if ($count < 1 || $count > $limits['max_files']) upload_reply(['ok'=>false,'error'=>'Select between 1 and '.(int)$limits['max_files'].' files.'], 400);
    if ($totalBytes > $limits['max_batch_bytes']) upload_reply(['ok'=>false,'error'=>'The selected batch exceeds '.rivune_format_upload_mb($limits['max_batch_bytes']).' MB.'], 413);

    rivune_processing_ensure_schema($pdo);
    $artist = trim((string)($_POST['artist'] ?? '')) ?: 'Unknown artist';
    $album = trim((string)($_POST['album'] ?? '')) ?: 'Unknown album';
    $dir = __DIR__ . '/storage/music';
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        upload_reply(['ok'=>false,'error'=>'Could not prepare music storage. Check folder permissions.'], 500);
    }

    $saved = 0; $duplicates = 0; $failed = [];
    for ($i = 0; $i < $count; $i++) {
        $name = basename((string)($batch['name'][$i] ?? 'track.mp3'));
        $tmp = (string)($batch['tmp_name'][$i] ?? '');
        $code = (int)($batch['error'][$i] ?? UPLOAD_ERR_NO_FILE);
        $size = (int)($batch['size'][$i] ?? 0);
        if ($code !== UPLOAD_ERR_OK) {
            $failed[] = $name . ': upload error ' . $code;
            continue;
        }
        if (!is_uploaded_file($tmp) || $size < 1 || $size > $limits['max_file_bytes']) {
            $failed[] = $name . ': file is empty or exceeds the per-file limit';
            continue;
        }
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'mp3' || !upload_is_mp3($tmp)) {
            $failed[] = $name . ': not a valid MP3 audio file';
            continue;
        }
        $hash = hash_file('sha256', $tmp);
        if ($hash === false) { $failed[] = $name . ': could not calculate SHA-256'; continue; }

        $lockName = 'rivune_upload_' . substr($hash, 0, 50);
        $lock = $pdo->prepare('SELECT GET_LOCK(?, 5)');
        $lock->execute([$lockName]);
        if ((int)$lock->fetchColumn() !== 1) { $failed[] = $name . ': could not safely check duplicates'; continue; }

        $stored = '';
        try {
            $dupe = $pdo->prepare('SELECT id FROM tracks WHERE file_hash=? LIMIT 1');
            $dupe->execute([$hash]);
            if ($dupe->fetch()) { $duplicates++; continue; }

            $stored = bin2hex(random_bytes(20)) . '.mp3';
            if (!move_uploaded_file($tmp, $dir . '/' . $stored)) {
                $failed[] = $name . ': could not save the uploaded file';
                continue;
            }
            try {
                $pdo->beginTransaction();
                $insert = $pdo->prepare('INSERT INTO tracks(title,artist,album,original_name,stored_name,file_hash) VALUES(?,?,?,?,?,?)');
                $insert->execute([
                    substr(pathinfo($name, PATHINFO_FILENAME), 0, 190),
                    substr($artist, 0, 190),
                    substr($album, 0, 190),
                    $name,
                    $stored,
                    $hash
                ]);
                $trackId = (int)$pdo->lastInsertId();
                $queue = $pdo->prepare("INSERT INTO track_processing_jobs(track_id,status) VALUES(?,'pending')");
                $queue->execute([$trackId]);
                $pdo->commit();
                $saved++;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                @unlink($dir . '/' . $stored);
                throw $e;
            }
        } catch (Throwable $e) {
            error_log('[RIVUNE dedicated upload '.$requestId.'] '.$e->getMessage());
            $failed[] = $name . ': database save or duplicate check failed';
        } finally {
            try { $release = $pdo->prepare('SELECT RELEASE_LOCK(?)'); $release->execute([$lockName]); } catch (Throwable $ignored) {}
        }
    }

    if ($saved || $duplicates || $failed) {
        $status = $failed ? (($saved || $duplicates) ? 'warning' : 'failure') : 'success';
        rivune_admin_log($pdo, 'upload', $status, 'Upload batch: '.$saved.' saved, '.$duplicates.' duplicate(s) skipped, '.count($failed).' failed.');
        if ($failed) rivune_alert_raise($pdo, 'upload_failure', 'warning', 'One or more uploads failed', count($failed).' file(s) failed in the latest upload batch.');
        if ($duplicates) rivune_alert_raise($pdo, 'upload_duplicate', 'info', 'Duplicate music was skipped', $duplicates.' identical file(s) were skipped by SHA-256 detection.');
    }
    upload_reply([
        'ok' => $saved > 0 || ($duplicates > 0 && !$failed),
        'saved' => $saved,
        'duplicates' => $duplicates,
        'notice' => trim(($saved ? $saved.' track(s) uploaded. ' : '').($duplicates ? $duplicates.' duplicate(s) skipped.' : '')),
        'error' => $failed ? implode(' | ', array_slice($failed, 0, 5)) : '',
        'request_id' => $requestId
    ]);
} catch (Throwable $e) {
    error_log('[RIVUNE dedicated upload '.$requestId.'] '.$e->getMessage());
    upload_reply(['ok'=>false,'saved'=>0,'duplicates'=>0,'error'=>'The upload could not be completed. Reference: '.$requestId.'.'], 500);
}
