<?php
/*
 * Restoring a backup (ITFlow's, queued and run by cron since 26.12) unpacks next to the backups, not in a small /tmp: our hook at the
 * start of backupRestoreRunQueued (functions/mit_backup_restore.php mitRestoreUseTempDir) points PHP's temporary folder at
 * <backups>/mit_tmp and clears leftovers older than a day.
 */
require __DIR__ . '/bootstrap.php';

scenarioCheck('ITFlow\'s queued restore calls our hook', strpos((string) file_get_contents(getcwd() . '/functions/backup_restore.php'), 'mitRestoreUseTempDir()') !== false, true);
$dir = backupStorageDir() . '/mit_tmp';
@mkdir($dir, 0700, true);
$old = "$dir/zz-old-" . bin2hex(random_bytes(3));
$new = "$dir/zz-new-" . bin2hex(random_bytes(3));
file_put_contents($old, 'x');
file_put_contents($new, 'x');
touch($old, time() - 2 * 86400);
$used = mitRestoreUseTempDir();
scenarioCheck('PHP\'s temporary folder is the one next to the backups (' . $used . ')', $used === rtrim($dir, '/') || ini_get('sys_temp_dir') !== '', true);
scenarioCheck('a leftover older than a day is cleared', file_exists($old), false);
scenarioCheck('a fresh one is left', file_exists($new), true);
@unlink($new);
