<?php
declare(strict_types=1);
require __DIR__.'/lib/bootstrap.php';
require_admin();
require_once __DIR__.'/lib/maintenance.php';
require_once __DIR__.'/lib/admin_logs.php';

$ready = rivune_maintenance_ensure($pdo);
$error = '';
if (!$ready) {
  $error = 'Maintenance settings could not be prepared. Check that the database user can create and update tables.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $enabled = isset($_POST['enabled']) && $_POST['enabled'] === '1';
  $message = trim((string)($_POST['message'] ?? ''));
  if ($message === '') $message = 'We are making a few improvements. Please check back soon.';
  $message = preg_replace('/^(.{0,500}).*$/us', '$1', $message) ?? substr($message, 0, 500);
  if (rivune_maintenance_save($pdo, $enabled, $message)) {
    $eventMessage = $enabled ? 'Maintenance mode enabled for public visitors.' : 'Maintenance mode disabled; public listening restored.';
    rivune_admin_log($pdo, 'maintenance', 'success', $eventMessage);
    header('Location: admin-maintenance.php?saved=1');
    exit;
  }
  $error = 'Could not save the maintenance setting. No change has been confirmed.';
}
$state = $ready ? rivune_maintenance_state($pdo) : ['enabled'=>false,'message'=>'We are making a few improvements. Please check back soon.','updated_at'=>null];
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>RIVUNE | Maintenance Mode</title>
<link rel="icon" type="image/png" href="public/assets/rivune-logo.png">
<link rel="stylesheet" href="assets/css/tokens.css?v=design-tokens-1">
<link rel="stylesheet" href="assets/css/app.css?v=admin-split-3">
<link rel="stylesheet" href="assets/css/admin.css?v=admin-module-1">
<style>
.mwrap{width:min(900px,calc(100% - 32px));margin:40px auto 70px}.mtop{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:28px}.mcard{padding:24px;margin:18px 0;border:1px solid var(--line);border-radius:18px;background:var(--surface)}.mstatus{display:flex;align-items:center;gap:10px;font-weight:700}.mdot{width:10px;height:10px;border-radius:50%;background:#22c55e}.mdot.on{background:#f59e0b}.mtoggle{display:flex;align-items:center;gap:12px;padding:16px;border:1px solid var(--line);border-radius:14px;margin:18px 0}.mtoggle input{width:20px;height:20px;accent-color:var(--accent,#a78bfa)}.mcard textarea{width:100%;min-height:130px;resize:vertical;padding:14px;border:1px solid var(--line);border-radius:12px;background:var(--background,#11111d);color:var(--text);font:inherit;line-height:1.6}.mhelp{font-size:13px;color:var(--muted);line-height:1.7}.mactions{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-top:18px}@media(max-width:600px){.mtop{align-items:flex-start;flex-direction:column}.mcard{padding:17px}}
</style>
</head>
<body><main class="mwrap">
<?php $adminActivePage = 'maintenance'; require __DIR__ . '/lib/admin_navigation.php'; ?>
<section class="mcard">
<p class="eyebrow">PUBLIC AVAILABILITY</p><h1>Maintenance mode</h1>
<?php if(isset($_GET['saved'])): ?><p class="success">Maintenance settings saved.</p><?php endif; ?>
<?php if($error): ?><p class="error"><?= h($error) ?></p><?php endif; ?>
<div class="mstatus"><span class="mdot <?= $state['enabled']?'on':'' ?>"></span><?= $state['enabled']?'Maintenance is ON — public visitors are blocked.':'Maintenance is OFF — public listening is available.' ?></div>
<p class="mhelp">When enabled, the public homepage, library API, and MP3 streaming endpoint return a maintenance response. The admin area remains available so you can turn it off. If the maintenance setting cannot be read, public routes fail open rather than accidentally locking the site.</p>
<?php if($ready): ?>
<form method="post" onsubmit="return !document.getElementById('maintenance-enabled').checked || confirm('Enable maintenance mode? Public visitors will temporarily lose access to the site and audio streaming.');">
<input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
<label class="mtoggle"><input id="maintenance-enabled" type="checkbox" name="enabled" value="1" <?= $state['enabled']?'checked':'' ?>><span><strong>Enable maintenance mode</strong><br><small class="mhelp">This setting takes effect immediately after saving.</small></span></label>
<label for="maintenance-message"><strong>Public maintenance message</strong></label>
<textarea id="maintenance-message" name="message" maxlength="500" required><?= h($state['message']) ?></textarea>
<p class="mhelp">Up to 500 characters. This text is escaped before display, so HTML or scripts cannot run.</p>
<div class="mactions"><button class="primary" type="submit">Save maintenance settings</button><a class="text-link" href="./" target="_blank" rel="noopener">Open public site</a></div>
</form>
<?php endif; ?>
</section>
<section class="mcard"><p class="eyebrow">SAFETY</p><h2>What this affects</h2><ul><li>Public homepage: blocked with a branded HTTP 503 page.</li><li>Public library API: returns a JSON maintenance response with HTTP 503.</li><li>MP3 streaming: blocked with HTTP 503; normal byte-range streaming is unchanged when maintenance is off.</li><li>Admin login and management pages: remain accessible.</li><li>Music files, artwork, track records, and configuration: never modified by this feature.</li></ul></section>
</main></body></html>
