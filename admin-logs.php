<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/admin_logs.php';
require_admin();

// Initialize the audit table without recording page views as activity.
rivune_admin_logs_ensure($pdo);

$allowedEvents = ['all','login','logout','upload','delete','integrity_scan','backup','restore','maintenance','alert','admin_action'];
$allowedStatuses = ['all','success','failure','warning','info'];
$event = (string)($_GET['event'] ?? 'all');
$status = (string)($_GET['status'] ?? 'all');
if (!in_array($event, $allowedEvents, true)) $event = 'all';
if (!in_array($status, $allowedStatuses, true)) $status = 'all';
$page = max(1, min(100000, (int)($_GET['page'] ?? 1)));
$perPage = 50;
$offset = ($page - 1) * $perPage;
$error = '';
$rows = [];
$total = 0;
try {
  $where = [];
  $params = [];
  if ($event !== 'all') { $where[] = 'event_type = ?'; $params[] = $event; }
  if ($status !== 'all') { $where[] = 'status = ?'; $params[] = $status; }
  $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
  $count = $pdo->prepare('SELECT COUNT(*) FROM admin_activity_logs' . $whereSql);
  $count->execute($params);
  $total = (int)$count->fetchColumn();
  $query = $pdo->prepare('SELECT id,event_type,status,message,created_at FROM admin_activity_logs' . $whereSql . ' ORDER BY id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset);
  $query->execute($params);
  $rows = $query->fetchAll();
} catch (Throwable $e) {
  $error = 'Activity logs are not available yet. Check that the database user can create and read the admin_activity_logs table.';
}
$pages = max(1, (int)ceil($total / $perPage));
$base = 'admin-logs.php?event=' . rawurlencode($event) . '&status=' . rawurlencode($status);
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>RIVUNE | Activity Logs</title>
<link rel="icon" type="image/png" href="public/assets/rivune-logo.png">
<link rel="stylesheet" href="assets/css/tokens.css?v=design-tokens-1">
<link rel="stylesheet" href="assets/css/app.css?v=modular-theme-1">
<style>
.logs-wrap{width:min(1000px,calc(100% - 32px));margin:40px auto 70px}
.logs-top{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:28px}
.logs-card{padding:22px;margin:18px 0;border:1px solid var(--line);border-radius:18px;background:var(--surface)}
.logs-filters{display:flex;gap:12px;align-items:end;flex-wrap:wrap}
.logs-filters label{display:grid;gap:6px;color:var(--muted);font-size:12px}
.logs-filters select{min-width:150px;padding:10px;border:1px solid var(--line);border-radius:9px;background:var(--bg);color:inherit}
.logs-table-wrap{overflow-x:auto}
.logs-table{width:100%;border-collapse:collapse;font-size:13px}
.logs-table th,.logs-table td{padding:12px 10px;border-bottom:1px solid var(--line);text-align:left;vertical-align:top}
.logs-table th{color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:.06em}
.logs-table td:last-child{min-width:260px;overflow-wrap:anywhere}
.logs-pill{display:inline-block;padding:4px 8px;border:1px solid var(--line);border-radius:99px;font-size:11px;text-transform:capitalize}
.logs-status-success{color:#36b37e}.logs-status-failure{color:#e06b6b}.logs-status-warning{color:#d6a343}
.logs-pagination{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-top:18px;flex-wrap:wrap}
@media(max-width:600px){.logs-card{padding:14px}.logs-top{align-items:flex-start;flex-direction:column}.logs-table{font-size:12px}}
</style>
</head>
<body>
<main class="logs-wrap">
<?php $adminActivePage = 'logs'; require __DIR__ . '/lib/admin_navigation.php'; ?>
<section class="logs-card">
  <p class="eyebrow">ADMIN AUDIT TRAIL</p>
  <h1>Activity logs</h1>
  <p class="muted">A record of administrator actions and important Control Center operations. Passwords, session IDs, CSRF tokens and uploaded file contents are never recorded.</p>
  <?php if ($error): ?><p class="error"><?= h($error) ?></p><?php else: ?>
  <form method="get" class="logs-filters">
    <label>Event type<select name="event">
      <?php foreach ($allowedEvents as $option): ?><option value="<?= h($option) ?>" <?= $event===$option?'selected':'' ?>><?= h($option==='all'?'All events':ucwords(str_replace('_',' ',$option))) ?></option><?php endforeach; ?>
    </select></label>
    <label>Status<select name="status">
      <?php foreach ($allowedStatuses as $option): ?><option value="<?= h($option) ?>" <?= $status===$option?'selected':'' ?>><?= h($option==='all'?'All statuses':ucfirst($option)) ?></option><?php endforeach; ?>
    </select></label>
    <button class="secondary" type="submit">Apply filters</button>
  </form>
  <p class="muted"><?= number_format($total) ?> matching entries · Newest first</p>
  <div class="logs-table-wrap">
    <table class="logs-table">
      <thead><tr><th>Time</th><th>Event</th><th>Status</th><th>Details</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="4" class="muted">No activity entries match these filters.</td></tr>
      <?php else: foreach ($rows as $row): ?>
        <tr>
          <td><?= h((string)$row['created_at']) ?></td>
          <td><?= h(ucwords(str_replace('_',' ',(string)$row['event_type']))) ?></td>
          <td><span class="logs-pill logs-status-<?= h((string)$row['status']) ?>"><?= h((string)$row['status']) ?></span></td>
          <td><?= h((string)$row['message']) ?></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <div class="logs-pagination">
    <span class="muted">Page <?= (int)$page ?> of <?= (int)$pages ?></span>
    <div style="display:flex;gap:8px">
      <?php if ($page > 1): ?><a class="secondary" href="<?= h($base . '&page=' . ($page-1)) ?>">← Newer</a><?php endif; ?>
      <?php if ($page < $pages): ?><a class="secondary" href="<?= h($base . '&page=' . ($page+1)) ?>">Older →</a><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</section>
</main>
</body>
</html>
