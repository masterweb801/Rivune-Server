<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/admin_logs.php';
require_once __DIR__ . '/lib/admin_alerts.php';
require_admin();

function rivune_integrity_is_mp3(string $path): bool {
  $fp = @fopen($path, 'rb');
  if (!$fp) return false;
  $data = fread($fp, 1048576);
  fclose($fp);
  if ($data === false || strlen($data) < 4) return false;
  $length = strlen($data);
  for ($i = 0; $i <= $length - 4; $i++) {
    $b1 = ord($data[$i]);
    $b2 = ord($data[$i + 1]);
    $b3 = ord($data[$i + 2]);
    if ($b1 !== 0xFF || ($b2 & 0xE0) !== 0xE0) continue;
    $version = ($b2 >> 3) & 0x03;
    $layer = ($b2 >> 1) & 0x03;
    $bitrate = ($b3 >> 4) & 0x0F;
    $sampleRate = ($b3 >> 2) & 0x03;
    if ($version !== 0x01 && $layer !== 0 && $bitrate !== 0 && $bitrate !== 0x0F && $sampleRate !== 0x03) return true;
  }
  return false;
}

function rivune_integrity_finding(array &$scan, string $type, string $message): void {
  $scan['counts'][$type] = ($scan['counts'][$type] ?? 0) + 1;
  if (count($scan['findings']) < 200) {
    $scan['findings'][] = ['type'=>$type, 'message'=>$message];
  }
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = (string)($_POST['action'] ?? '');
  if ($action === 'start') {
    $scan = [
      'started_at'=>date('Y-m-d H:i:s'),
      'track_last_id'=>0, 'tracks_done'=>false, 'tracks_checked'=>0,
      'file_offset'=>0, 'files_done'=>false, 'files_checked'=>0,
      'counts'=>[], 'findings'=>[], 'duplicate_groups'=>0
    ];
    try {
      $dupes = $pdo->query("SELECT file_hash, COUNT(*) AS copies, MIN(id) AS first_id FROM tracks WHERE file_hash IS NOT NULL AND file_hash <> '' GROUP BY file_hash HAVING COUNT(*) > 1 ORDER BY copies DESC LIMIT 100")->fetchAll();
      foreach ($dupes as $dupe) {
        $scan['duplicate_groups']++;
        rivune_integrity_finding($scan, 'duplicates', 'SHA-256 duplicate group: ' . (int)$dupe['copies'] . ' tracks share a hash (first track ID ' . (int)$dupe['first_id'] . ').');
      }
      $_SESSION['rivune_integrity_scan'] = $scan;
      rivune_admin_log($pdo, 'integrity_scan', 'info', 'Started a read-only music library integrity scan.');
    } catch (Throwable $e) {
      $error = 'Could not start the integrity scan. Check database permissions and try again.';
    }
  } elseif ($action === 'step') {
    if (empty($_SESSION['rivune_integrity_scan']) || !is_array($_SESSION['rivune_integrity_scan'])) {
      $error = 'There is no active scan. Start a new scan first.';
    } else {
      $scan = $_SESSION['rivune_integrity_scan'];
      try {
        if (!$scan['tracks_done']) {
          $q = $pdo->prepare('SELECT id,title,artist,album,stored_name,file_hash FROM tracks WHERE id > ? ORDER BY id ASC LIMIT 25');
          $q->execute([(int)$scan['track_last_id']]);
          $rows = $q->fetchAll();
          foreach ($rows as $track) {
            $id = (int)$track['id'];
            $scan['track_last_id'] = $id;
            $scan['tracks_checked']++;
            $stored = basename((string)$track['stored_name']);
            $path = __DIR__ . '/storage/music/' . $stored;
            if ($stored === '' || !is_file($path)) {
              rivune_integrity_finding($scan, 'missing', 'Track #' . $id . ' (' . (string)$track['title'] . '): stored MP3 file is missing.');
              continue;
            }
            $size = @filesize($path);
            if ($size === false || $size === 0) {
              rivune_integrity_finding($scan, 'empty', 'Track #' . $id . ' (' . (string)$track['title'] . '): file is empty or unreadable.');
            }
            if (strtolower(pathinfo($stored, PATHINFO_EXTENSION)) !== 'mp3' || !rivune_integrity_is_mp3($path)) {
              rivune_integrity_finding($scan, 'invalid', 'Track #' . $id . ' (' . (string)$track['title'] . '): stored file does not appear to contain valid MP3 audio.');
            }
            if (trim((string)$track['title']) === '' || trim((string)$track['artist']) === '' || trim((string)$track['album']) === '') {
              rivune_integrity_finding($scan, 'metadata', 'Track #' . $id . ': title, artist, or album metadata is incomplete.');
            }
          }
          if (count($rows) < 25) $scan['tracks_done'] = true;
        }

        if (!$scan['files_done']) {
          $directory = __DIR__ . '/storage/music';
          if (!is_dir($directory) || !is_readable($directory)) {
            $error = 'The music directory is missing or unreadable; the file scan could not continue.';
            $scan['files_done'] = true;
          } else {
            $offset = (int)$scan['file_offset'];
            $seen = 0;
            $processed = 0;
            $iterator = new DirectoryIterator($directory);
            foreach ($iterator as $entry) {
              if ($entry->isDot()) continue;
              if ($seen++ < $offset) continue;
              if ($processed >= 40) break;
              $processed++;
              $scan['file_offset']++;
              if (!$entry->isFile() || $entry->isLink()) continue;
              $scan['files_checked']++;
              $name = $entry->getFilename();
              $path = $entry->getPathname();
              $size = $entry->getSize();
              if ($size === 0) rivune_integrity_finding($scan, 'empty', 'File ' . $name . ': zero-byte file found.');
              if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'mp3' || !rivune_integrity_is_mp3($path)) {
                rivune_integrity_finding($scan, 'invalid', 'File ' . $name . ': unexpected file type or invalid MP3 content.');
              }
              $exists = $pdo->prepare('SELECT id FROM tracks WHERE stored_name = ? LIMIT 1');
              $exists->execute([$name]);
              if (!$exists->fetchColumn()) {
                rivune_integrity_finding($scan, 'orphan', 'File ' . $name . ': no matching database track was found.');
              }
            }
            if ($processed < 40) $scan['files_done'] = true;
          }
        }
        $_SESSION['rivune_integrity_scan'] = $scan;
        if (!empty($scan['tracks_done']) && !empty($scan['files_done'])) {
          rivune_admin_log($pdo, 'integrity_scan', 'success', 'Completed integrity scan: '.(int)$scan['tracks_checked'].' database records and '.(int)$scan['files_checked'].' files checked; '.array_sum($scan['counts']).' finding(s).');
          $alertMap = [
            'missing'=>['critical','Missing music files detected'],
            'empty'=>['critical','Empty music files detected'],
            'invalid'=>['critical','Invalid MP3 files detected'],
            'orphan'=>['warning','Untracked music files detected'],
            'duplicates'=>['warning','Duplicate music hashes detected'],
            'metadata'=>['warning','Incomplete track metadata detected']
          ];
          foreach ($alertMap as $kind=>$definition) {
            $count = (int)($scan['counts'][$kind] ?? 0);
            if ($count > 0) rivune_alert_raise($pdo, 'integrity_'.$kind, $definition[0], $definition[1], $count.' finding(s) reported by the latest completed read-only integrity scan. Review File Integrity for details.');
          }
        }
      } catch (Throwable $e) {
        $error = 'The scan encountered an error. Completed findings have been preserved; you can retry the next batch.';
        $_SESSION['rivune_integrity_scan'] = $scan;
      }
    }
  } elseif ($action === 'clear') {
    unset($_SESSION['rivune_integrity_scan']);
    rivune_admin_log($pdo, 'integrity_scan', 'info', 'Cleared the integrity scan report from the session.');
  }
}

$scan = $_SESSION['rivune_integrity_scan'] ?? null;
$complete = is_array($scan) && !empty($scan['tracks_done']) && !empty($scan['files_done']);
$checked = is_array($scan) ? (int)$scan['tracks_checked'] + (int)$scan['files_checked'] : 0;
$issueCount = is_array($scan) ? array_sum($scan['counts']) : 0;
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>RIVUNE | File Integrity</title>
<link rel="icon" type="image/png" href="public/assets/rivune-logo.png">
<link rel="stylesheet" href="assets/css/tokens.css?v=design-tokens-1">
<link rel="stylesheet" href="assets/css/app.css?v=app-core-4">
<link rel="stylesheet" href="assets/css/controls.css?v=controls-1">
<link rel="stylesheet" href="assets/css/forms.css?v=forms-1">
<link rel="stylesheet" href="assets/css/admin.css?v=admin-module-1">
<style>
.integrity-wrap{width:min(900px,calc(100% - 32px));margin:40px auto 70px}
.integrity-top{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:28px}
.integrity-card{padding:24px;margin:18px 0;border:1px solid var(--line);border-radius:18px;background:var(--surface)}
.integrity-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin:18px 0}
.integrity-stat{padding:14px;border:1px solid var(--line);border-radius:12px;background:var(--bg)}
.integrity-stat strong{display:block;font-size:23px;margin-top:6px}
.integrity-stat span,.integrity-finding small{color:var(--muted);font-size:11px}
.integrity-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}
.integrity-findings{display:grid;gap:8px;margin-top:14px}
.integrity-finding{padding:12px;border:1px solid var(--line);border-radius:10px;font-size:12px;overflow-wrap:anywhere}
.integrity-finding strong{display:block;margin-bottom:4px;text-transform:capitalize;color:var(--accent)}
.integrity-note{padding:13px;border:1px solid #d9a44155;border-radius:10px;color:var(--muted);font-size:12px;line-height:1.6}
@media(max-width:600px){.integrity-stats{grid-template-columns:repeat(2,minmax(0,1fr))}.integrity-top{align-items:flex-start;flex-direction:column}.integrity-card{padding:18px}}
</style>
</head>
<body>
<main class="integrity-wrap">
<?php $adminActivePage = 'integrity'; require __DIR__ . '/lib/admin_navigation.php'; ?>
<section class="integrity-card">
  <p class="eyebrow">READ-ONLY DIAGNOSTICS</p>
  <h1>File integrity scanner</h1>
  <p class="muted">Inspect the music database and storage directory for missing files, orphaned files, duplicate hashes, empty audio, invalid MP3 content, and incomplete metadata.</p>
  <div class="integrity-note"><strong>Safety:</strong> This scanner never deletes, moves, renames, repairs, or overwrites music or database records. It checks a limited batch per request. Findings are reports only.</div>
  <?php if ($error): ?><p class="error"><?= h($error) ?></p><?php endif; ?>
  <?php if (!is_array($scan)): ?>
    <form method="post" class="integrity-actions"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="start"><button class="primary" type="submit">Start integrity scan</button></form>
  <?php else: ?>
    <div class="integrity-stats">
      <div class="integrity-stat"><span>Records checked</span><strong><?= (int)$scan['tracks_checked'] ?></strong></div>
      <div class="integrity-stat"><span>Files checked</span><strong><?= (int)$scan['files_checked'] ?></strong></div>
      <div class="integrity-stat"><span>Issue count</span><strong><?= (int)$issueCount ?></strong></div>
      <div class="integrity-stat"><span>Duplicate groups</span><strong><?= (int)$scan['duplicate_groups'] ?></strong></div>
    </div>
    <p class="muted">Started <?= h((string)$scan['started_at']) ?> · <?= $complete ? 'Scan complete' : 'Scan in progress' ?></p>
    <?php if (!$complete): ?>
      <form method="post" class="integrity-actions"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="step"><button class="primary" type="submit">Scan next batch</button></form>
      <p class="muted">Each batch checks up to 25 database records and 40 directory entries. Continue until the scan is complete.</p>
    <?php endif; ?>
    <?php if ($scan['counts']): ?>
      <div class="integrity-stats">
        <?php foreach ($scan['counts'] as $type=>$count): ?><div class="integrity-stat"><span><?= h(ucfirst((string)$type)) ?></span><strong><?= (int)$count ?></strong></div><?php endforeach; ?>
      </div>
    <?php endif; ?>
    <?php if ($scan['findings']): ?>
      <h2>Findings<?= $issueCount > count($scan['findings']) ? ' (first ' . count($scan['findings']) . ' shown)' : '' ?></h2>
      <div class="integrity-findings">
        <?php foreach ($scan['findings'] as $finding): ?><div class="integrity-finding"><strong><?= h($finding['type']) ?></strong><?= h($finding['message']) ?></div><?php endforeach; ?>
      </div>
    <?php elseif ($complete): ?><p class="success">No integrity issues were found in the scanned records and files.</p><?php endif; ?>
    <form method="post" class="integrity-actions" onsubmit="return confirm('Clear this scan report? This will not change any music or database data.');"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="clear"><button class="secondary" type="submit">Clear report</button></form>
  <?php endif; ?>
</section>
</main>
</body>
</html>
