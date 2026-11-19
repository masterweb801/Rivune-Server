<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/admin_logs.php';
require_once __DIR__ . '/lib/admin_alerts.php';
require_admin();

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = (string)($_POST['action'] ?? '');
  $id = filter_var($_POST['alert_id'] ?? null, FILTER_VALIDATE_INT);
  if ($action === 'resolve' && $id) {
    try {
      rivune_alerts_ensure($pdo);
      $stmt = $pdo->prepare('UPDATE admin_alerts SET resolved_at=CURRENT_TIMESTAMP WHERE id=? AND resolved_at IS NULL');
      $stmt->execute([$id]);
      if ($stmt->rowCount() > 0) rivune_admin_log($pdo, 'alert', 'success', 'Resolved alert #'.(int)$id.'.');
      header('Location: admin-alerts.php?view=open'); exit;
    } catch (Throwable $e) { $error = 'Could not resolve this alert. Check database permissions.'; }
  }
}
$view = (string)($_GET['view'] ?? 'open');
if (!in_array($view, ['open','all','resolved'], true)) $view = 'open';
$rows = []; $openCount = 0; $total = 0;
try {
  rivune_alerts_ensure($pdo);
  $openCount = rivune_alert_open_count($pdo);
  $where = $view === 'open' ? ' WHERE resolved_at IS NULL' : ($view === 'resolved' ? ' WHERE resolved_at IS NOT NULL' : '');
  $count = $pdo->query('SELECT COUNT(*) FROM admin_alerts'.$where);
  $total = (int)$count->fetchColumn();
  $stmt = $pdo->query('SELECT id,severity,title,message,created_at,resolved_at FROM admin_alerts'.$where.' ORDER BY id DESC LIMIT 100');
  $rows = $stmt->fetchAll();
} catch (Throwable $e) { $error = 'Alerts are unavailable. The database user may not have permission to create or read the admin_alerts table.'; }
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>RIVUNE | Alerts</title><link rel="icon" type="image/png" href="public/assets/rivune-logo.png"><link rel="stylesheet" href="assets/css/app.css">
<style>
.alerts-wrap{width:min(1000px,calc(100% - 32px));margin:40px auto 70px}.alerts-top{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:28px}.alerts-card{padding:22px;margin:18px 0;border:1px solid var(--line);border-radius:18px;background:var(--surface)}.alerts-filters{display:flex;gap:10px;flex-wrap:wrap;margin:18px 0}.alerts-filters a{padding:8px 12px;border:1px solid var(--line);border-radius:9px;color:inherit;text-decoration:none}.alert-row{display:flex;justify-content:space-between;gap:18px;padding:16px 0;border-top:1px solid var(--line)}.alert-row h3{margin:0 0 6px;font-size:15px}.alert-row p{margin:0;color:var(--muted);font-size:13px;line-height:1.5}.alert-meta{font-size:11px;color:var(--muted);margin-top:8px}.alert-severity{font-size:10px;text-transform:uppercase;letter-spacing:.08em}.alert-critical{color:#e06b6b}.alert-warning{color:#d6a343}.alert-info{color:var(--muted)}.alert-row form{flex-shrink:0;align-self:center}@media(max-width:600px){.alerts-top{align-items:flex-start;flex-direction:column}.alerts-card{padding:16px}.alert-row{flex-direction:column}.alert-row form{align-self:flex-start}}
</style></head><body><main class="alerts-wrap">
<header class="alerts-top"><a class="brand" href="admin.php"><img class="brand-logo" src="public/assets/rivune-logo.png" alt="RIVUNE logo"><span>RIVUNE<small>SYSTEM ALERTS</small></span></a><a class="text-link" href="admin.php">← Back to Admin</a></header>
<section class="alerts-card"><p class="eyebrow">CONTROL CENTER</p><h1>Alerts & notifications</h1><p class="muted">Open alerts: <?= (int)$openCount ?> · Showing up to 100 entries. Alerts are evaluated during normal admin requests and manual scans; no background worker or cron is required.</p>
<?php if($error): ?><p class="error"><?= h($error) ?></p><?php endif; ?>
<nav class="alerts-filters"><a href="admin-alerts.php?view=open">Open</a><a href="admin-alerts.php?view=all">All history</a><a href="admin-alerts.php?view=resolved">Resolved</a></nav>
<?php if(!$error && !$rows): ?><p class="muted">No alerts in this view.</p><?php endif; ?>
<?php foreach($rows as $row): ?><article class="alert-row"><div><span class="alert-severity alert-<?= h((string)$row['severity']) ?>"><?= h((string)$row['severity']) ?></span><h3><?= h((string)$row['title']) ?></h3><p><?= h((string)$row['message']) ?></p><div class="alert-meta">Created <?= h((string)$row['created_at']) ?><?= $row['resolved_at'] ? ' · Resolved '.h((string)$row['resolved_at']) : '' ?></div></div>
<?php if(!$row['resolved_at']): ?><form method="post" onsubmit="return confirm('Resolve this alert?');"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="resolve"><input type="hidden" name="alert_id" value="<?= (int)$row['id'] ?>"><button class="secondary" type="submit">Resolve</button></form><?php endif; ?></article><?php endforeach; ?>
</section></main></body></html>