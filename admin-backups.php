<?php
declare(strict_types=1);
require __DIR__.'/lib/bootstrap.php';
require_once __DIR__.'/lib/admin_logs.php';
require_admin();

function rivune_backup_history_ensure(PDO $pdo): bool {
  try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_backup_history (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      backup_type VARCHAR(16) NOT NULL,
      file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
      checksum CHAR(64) NULL,
      outcome VARCHAR(16) NOT NULL,
      note VARCHAR(255) NOT NULL DEFAULT '',
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_backup_history_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    return true;
  } catch (Throwable $e) { return false; }
}
function rivune_backup_record(PDO $pdo,string $type,int $size,?string $hash,string $outcome,string $note): void {
  try {
    if (!rivune_backup_history_ensure($pdo)) return;
    $q=$pdo->prepare('INSERT INTO admin_backup_history (backup_type,file_size,checksum,outcome,note) VALUES (?,?,?,?,?)');
    $q->execute([$type,$size,$hash,$outcome,substr($note,0,255)]);
  } catch(Throwable $e) {}
}
function rivune_ident(string $v): string { return '`'.str_replace('`','``',$v).'`'; }
function rivune_sql_value(PDO $pdo,$v): string { return $v===null?'NULL':$pdo->quote((string)$v); }
function rivune_make_sql(PDO $pdo,string $path): array {
  $f=@fopen($path,'wb'); if(!$f) throw new RuntimeException('Temporary backup file unavailable.');
  $put=static function(string $s) use($f): void { if(fwrite($f,$s)===false) throw new RuntimeException('Backup write failed.'); };
  $put("-- RIVUNE SQL backup\n-- Generated ".gmdate('c')."\nSET FOREIGN_KEY_CHECKS=0;\n");
  $tables=$pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
  $rowsTotal=0; $tableTotal=0;
  foreach($tables as $entry) {
    $table=(string)$entry[0]; $qtable=rivune_ident($table); $tableTotal++;
    $schema=$pdo->query('SHOW CREATE TABLE '.$qtable)->fetch(PDO::FETCH_NUM);
    if(!$schema || empty($schema[1])) throw new RuntimeException('Could not read a table definition.');
    $put("\n-- Table ".$table."\nDROP TABLE IF EXISTS ".$qtable.";\n".$schema[1].";\n");
    $cols=$pdo->query('SHOW COLUMNS FROM '.$qtable)->fetchAll(PDO::FETCH_ASSOC);
    $names=[]; foreach($cols as $col) if(stripos((string)($col['Extra']??''),'generated')===false) $names[]=(string)$col['Field'];
    if(!$names) continue;
    $colSql=implode(',',array_map('rivune_ident',$names)); $offset=0;
    do {
      $batch=$pdo->query('SELECT '.$colSql.' FROM '.$qtable.' LIMIT 100 OFFSET '.(int)$offset)->fetchAll(PDO::FETCH_ASSOC);
      foreach($batch as $row) {
        $values=[]; foreach($names as $name) $values[]=rivune_sql_value($pdo,$row[$name]??null);
        $put('INSERT INTO '.$qtable.' ('.$colSql.') VALUES ('.implode(',',$values).');'."\n"); $rowsTotal++;
      }
      $offset+=count($batch);
    } while(count($batch)===100);
  }
  $put("\nSET FOREIGN_KEY_CHECKS=1;\n");
  if(!fflush($f)) throw new RuntimeException('Backup flush failed.');
  fclose($f); clearstatcache(true,$path);
  $size=(int)filesize($path); $hash=hash_file('sha256',$path);
  if($tableTotal<1 || $size<100 || $hash===false) throw new RuntimeException('Backup validation failed.');
  return ['size'=>$size,'hash'=>$hash,'tables'=>$tableTotal,'rows'=>$rowsTotal];
}
function rivune_csv_export(PDO $pdo,string $path): array {
  $available=$pdo->query('SHOW COLUMNS FROM tracks')->fetchAll(PDO::FETCH_COLUMN);
  $cols=['id','title','artist','album','original_name','stored_name','created_at'];
  if(in_array('file_hash',$available,true)) $cols[]='file_hash';
  $select=implode(',',array_map('rivune_ident',$cols));
  $stmt=$pdo->query('SELECT '.$select.' FROM tracks ORDER BY id ASC');
  $f=@fopen($path,'wb'); if(!$f) throw new RuntimeException('Temporary CSV file unavailable.');
  fwrite($f,"\xEF\xBB\xBF"); fputcsv($f,$cols); $count=0;
  while($row=$stmt->fetch(PDO::FETCH_ASSOC)){ fputcsv($f,$row); $count++; }
  fclose($f); clearstatcache(true,$path);
  $size=(int)filesize($path); $hash=hash_file('sha256',$path);
  if($size<1 || $hash===false) throw new RuntimeException('CSV validation failed.');
  return ['size'=>$size,'hash'=>$hash,'rows'=>$count];
}

$error='';
if($_SERVER['REQUEST_METHOD']==='POST') {
  csrf_check(); $action=(string)($_POST['action']??'');
  $temp=@tempnam(sys_get_temp_dir(),'rivune-export-');
  try {
    if($temp===false) throw new RuntimeException('Temporary storage is unavailable.');
    if($action==='sql') {
      $meta=rivune_make_sql($pdo,$temp); $type='sql';
      $note=$meta['tables'].' tables; '.$meta['rows'].' rows.';
      $filename='rivune-database-'.date('Ymd-His').'.sql'; $mime='application/sql; charset=utf-8';
    } elseif($action==='csv') {
      $meta=rivune_csv_export($pdo,$temp); $type='csv';
      $note=$meta['rows'].' track records.';
      $filename='rivune-track-metadata-'.date('Ymd-His').'.csv'; $mime='text/csv; charset=utf-8';
    } else throw new RuntimeException('Unknown export action.');
    rivune_backup_record($pdo,$type,$meta['size'],$meta['hash'],'success',$note);
    rivune_admin_log($pdo,'backup','success','Generated '.$type.' export: '.$note);
    header('Content-Type: '.$mime); header('Content-Disposition: attachment; filename="'.$filename.'"');
    header('Content-Length: '.$meta['size']); header('X-Content-Type-Options: nosniff');
    readfile($temp); @unlink($temp); exit;
  } catch(Throwable $e) {
    if($temp!==false && is_file($temp)) @unlink($temp);
    rivune_backup_record($pdo,($action==='csv'?'csv':'sql'),0,null,'failure','Export generation or validation failed.');
    rivune_admin_log($pdo,'backup','failure','A database or metadata export failed.');
    $error='Export failed. Check database permissions and available temporary storage, then retry.';
  }
}
$history=[];
try { if(rivune_backup_history_ensure($pdo)) $history=$pdo->query('SELECT backup_type,file_size,checksum,outcome,note,created_at FROM admin_backup_history ORDER BY id DESC LIMIT 30')->fetchAll(); } catch(Throwable $e) {}
function rivune_bytes(int $n): string { if($n<1024)return $n.' B'; $u=['KB','MB','GB'];$v=(float)$n;$i=-1;do{$v/=1024;$i++;}while($v>=1024&&$i<2);return number_format($v,2).' '.($u[$i]??'GB'); }
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>RIVUNE | Backup & Export</title><link rel="icon" type="image/png" href="public/assets/rivune-logo.png"><link rel="stylesheet" href="assets/css/app.css"><style>
.bwrap{width:min(1000px,calc(100% - 32px));margin:40px auto 70px}.btop{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:28px}.bcard{padding:22px;margin:18px 0;border:1px solid var(--line);border-radius:18px;background:var(--surface)}.bgrid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;margin-top:18px}.boption{padding:18px;border:1px solid var(--line);border-radius:14px}.boption p,.bnote{color:var(--muted);font-size:13px;line-height:1.6}.bnote{padding:14px;border:1px solid #d9a44155;border-radius:10px}.btable{width:100%;border-collapse:collapse;font-size:12px}.btable th,.btable td{text-align:left;padding:11px 8px;border-top:1px solid var(--line);overflow-wrap:anywhere}.btable th{color:var(--muted)}@media(max-width:650px){.btop{align-items:flex-start;flex-direction:column}.bgrid{grid-template-columns:1fr}.bcard{padding:16px}.btable{font-size:10px}}
</style></head><body><main class="bwrap">
<?php $adminActivePage = 'backups'; require __DIR__ . '/lib/admin_navigation.php'; ?>
<section class="bcard"><p class="eyebrow">DATA PROTECTION</p><h1>Backup & export</h1><p class="muted">Generate downloadable database and track metadata exports. Files are streamed to your device and not retained on shared hosting.</p><?php if($error): ?><p class="error"><?= h($error) ?></p><?php endif; ?>
<div class="bnote"><strong>Important:</strong> SQL includes database tables and records only. It does not include MP3 files, artwork, or <code>storage/config.php</code>. Keep separate copies of your music and artwork. Never place SQL backups in the public website directory.</div>
<div class="bgrid"><article class="boption"><h2>Full database backup</h2><p>SQL table structures and records, with SHA-256 validation and a backup-history entry.</p><form method="post"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="sql"><button class="primary">Download SQL backup</button></form></article>
<article class="boption"><h2>Track metadata export</h2><p>CSV catalogue with IDs, titles, artist, album, filenames, timestamps, and hashes when available.</p><form method="post"><input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="csv"><button class="secondary">Download CSV</button></form></article></div></section>
<section class="bcard"><div class="section-head"><div><p class="eyebrow">EXPORT HISTORY</p><h2>Recent backups & exports</h2></div><span class="muted">Latest 30</span></div><?php if(!$history): ?><p class="muted">No export history yet.</p><?php else: ?><div style="overflow-x:auto"><table class="btable"><thead><tr><th>Date</th><th>Type</th><th>Size</th><th>Result</th><th>Details</th></tr></thead><tbody><?php foreach($history as $hrow): ?><tr><td><?= h((string)$hrow['created_at']) ?></td><td><?= h(strtoupper((string)$hrow['backup_type'])) ?></td><td><?= h(rivune_bytes((int)$hrow['file_size'])) ?></td><td><?= h((string)$hrow['outcome']) ?></td><td><?= h((string)$hrow['note']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
<section class="bcard"><p class="eyebrow">RESTORE SAFETY</p><h2>Database restoration</h2><p class="muted">Automated restore is deliberately not enabled yet. A safe restore needs an independently stored pre-restore backup, strict dump validation, and a recoverable rollback procedure. Until then, use your hosting provider's database tools with a verified backup. This avoids risking your live music catalogue.</p></section>
</main></body></html>