<?php
declare(strict_types=1);
require __DIR__.'/lib/bootstrap.php';
if (is_file(__DIR__.'/storage/config.php')) { header('Location: admin.php'); exit; }
$error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  csrf_check();
  $host=trim($_POST['db_host']??''); $name=trim($_POST['db_name']??''); $user=trim($_POST['db_user']??''); $pass=(string)($_POST['db_pass']??'');
  $adminUser=trim($_POST['admin_user']??'admin'); $adminPass=(string)($_POST['admin_pass']??'');
  if ($host==='' || $name==='' || $user==='' || $adminUser==='' || strlen($adminPass)<12) $error='Complete all database fields and use an admin password of at least 12 characters.';
  else {
    try {
      $pdoInstall=new PDO('mysql:host='.$host.';dbname='.$name.';charset=utf8mb4',$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
      $pdoInstall->exec("CREATE TABLE IF NOT EXISTS tracks (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,title VARCHAR(190) NOT NULL,artist VARCHAR(190) NOT NULL DEFAULT 'Unknown artist',album VARCHAR(190) NOT NULL DEFAULT 'Unknown album',original_name VARCHAR(255) NOT NULL,stored_name VARCHAR(80) NOT NULL UNIQUE,file_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX(title),INDEX(artist),INDEX idx_tracks_file_hash (file_hash)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
      // Additive compatibility migration for an existing tracks table. Never
      // drop or rewrite existing rows during installation.
      $hashColumn = $pdoInstall->query("SHOW COLUMNS FROM tracks LIKE 'file_hash'")->fetch(PDO::FETCH_ASSOC);
      if (!$hashColumn) {
        $pdoInstall->exec("ALTER TABLE tracks ADD COLUMN file_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL");
      }
      $hashIndex = $pdoInstall->query("SHOW INDEX FROM tracks WHERE Key_name = 'idx_tracks_file_hash'")->fetch(PDO::FETCH_ASSOC);
      if (!$hashIndex) {
        $pdoInstall->exec("CREATE INDEX idx_tracks_file_hash ON tracks (file_hash)");
      }
      $config=['db_host'=>$host,'db_name'=>$name,'db_user'=>$user,'db_pass'=>$pass,'admin_user'=>$adminUser,'admin_pass_hash'=>password_hash($adminPass,PASSWORD_DEFAULT),'admin_session_version'=>1];
      $dir=__DIR__.'/storage'; if (!is_dir($dir)) mkdir($dir,0750,true);
      if (!is_dir($dir.'/music')) mkdir($dir.'/music',0750,true);
      $php="<?php\nreturn ".var_export($config,true).";\n";
      if (file_put_contents($dir.'/config.php',$php,LOCK_EX)===false) throw new RuntimeException('Could not save configuration. Check storage folder permissions.');
      @chmod($dir.'/config.php',0600);
      header('Location: admin.php?installed=1'); exit;
    } catch (Throwable $e) { $error='Setup failed. Verify the database details and storage folder permissions, then try again.'; }
  }
}
?><!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Install RIVUNE</title><link rel="stylesheet" href="assets/css/app.css"><body><main class="auth-wrap"><a class="brand" href="./"><span class="brand-icon">♫</span><span>RIVUNE<small>FIRST-TIME SETUP</small></span></a><section class="panel"><p class="eyebrow">INSTALLATION</p><h1>Set up your music site</h1><p class="muted">Enter your hosting provider's MySQL details and create the administrator login. These credentials are stored in the protected storage folder.</p><?php if($error): ?><p class="error"><?= $error ?></p><?php endif; ?><form method="post" class="form"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><h2>MySQL database</h2><label>Database host<input name="db_host" required placeholder="localhost" value="<?= h($_POST['db_host']??'') ?>"></label><label>Database name<input name="db_name" required value="<?= h($_POST['db_name']??'') ?>"></label><label>Database username<input name="db_user" required value="<?= h($_POST['db_user']??'') ?>"></label><label>Database password<input name="db_pass" type="password" autocomplete="new-password"></label><h2>Administrator</h2><label>Admin username<input name="admin_user" required value="<?= h($_POST['admin_user']??'admin') ?>"></label><label>Admin password<input name="admin_pass" type="password" minlength="12" required autocomplete="new-password"><small>At least 12 characters.</small></label><button class="primary" type="submit">Install RIVUNE</button></form></section></main></body></html>