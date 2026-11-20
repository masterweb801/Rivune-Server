<?php
// Shared authenticated admin navigation. Set $adminActivePage before including.
$adminActivePage = isset($adminActivePage) ? (string)$adminActivePage : 'overview';
$adminNavItems = [
  'overview' => ['Overview & Music', 'admin.php'],
  'integrity' => ['File integrity', 'admin-integrity.php'],
  'logs' => ['Activity logs', 'admin-logs.php'],
  'alerts' => ['Alerts', 'admin-alerts.php'],
  'backups' => ['Backup & Export', 'admin-backups.php'],
  'maintenance' => ['Maintenance', 'admin-maintenance.php'],
  'security' => ['Change password', 'admin-change-password.php'],
];
$adminBrandLabels = [
  'overview'=>'ADMIN STUDIO','integrity'=>'FILE INTEGRITY','logs'=>'ACTIVITY LOGS',
  'alerts'=>'SYSTEM ALERTS','backups'=>'BACKUP & EXPORT','maintenance'=>'MAINTENANCE CONTROL','security'=>'ACCOUNT SECURITY'
];
?>
<?php if (is_admin()): ?>
<header class="rivune-admin-header">
  <a class="brand" href="admin.php"><img class="brand-logo" src="public/assets/rivune-logo.png" alt="RIVUNE logo"><span>RIVUNE<small><?= h($adminBrandLabels[$adminActivePage] ?? 'ADMIN STUDIO') ?></small></span></a>
  <details class="rivune-admin-mobile-nav">
    <summary aria-label="Open admin navigation"><span class="rivune-menu-icon">☰</span><span>Menu</span></summary>
    <nav class="rivune-admin-menu" aria-label="Admin navigation">
      <?php foreach ($adminNavItems as $key => $item): ?><a href="<?= h($item[1]) ?>"<?= $adminActivePage === $key ? ' class="is-active" aria-current="page"' : '' ?>><?= h($item[0]) ?></a><?php endforeach; ?>
      <a href="./">← Public site</a>
      <form method="post" action="admin.php" onsubmit="return confirm('Sign out of RIVUNE Admin?');"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="logout"><button type="submit" class="rivune-nav-logout">Sign out</button></form>
    </nav>
  </details>
  <nav class="rivune-admin-desktop-nav" aria-label="Admin navigation">
    <?php foreach ($adminNavItems as $key => $item): ?><a href="<?= h($item[1]) ?>"<?= $adminActivePage === $key ? ' class="is-active" aria-current="page"' : '' ?>><?= h($item[0]) ?></a><?php endforeach; ?>
    <a href="./">Public site</a>
    <form method="post" action="admin.php" onsubmit="return confirm('Sign out of RIVUNE Admin?');"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="logout"><button type="submit" class="rivune-nav-logout">Sign out</button></form>
  </nav>
</header>
<?php endif; ?>