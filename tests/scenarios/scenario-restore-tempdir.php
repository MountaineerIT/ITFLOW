<?php
/*
 * Restoring a backup (ITFlow's, queued and run by cron since 26.12) unpacks next to the backups, not in a small /tmp: our hook at the
 * start of backupRestoreRunQueued (functions/mit_backup_restore.php mitRestoreUseTempDir) points PHP's temporary folder at
 * <backups>/mit_tmp and clears leftovers older than a day.
 */
require __DIR__ . '/bootstrap.php';

// ITFlow's queued restore really calls the hook (Nov 23 hook proof: the text search here passed with the hook switched off). A request too
// old to start is refused at once - nothing restored - after the hook has run; the status file is put back as it was.
$request_file = backupRestoreRequestFile();
if (!is_file($request_file)) {
    $status_file = backupRestoreStatusFile();
    $status_was = is_file($status_file) ? file_get_contents($status_file) : null;
    putenv('TMPDIR');
    unset($_ENV['TMPDIR']);
    try {
        @mkdir(dirname($request_file), 0700, true);
        file_put_contents($request_file, json_encode(['file' => 'zz-none.zip', 'label' => 'zz', 'who' => 'Scenario', 'queued' => date('Y-m-d H:i:s', strtotime('-2 days'))]));
        $result = backupRestoreRunQueued($mysqli);
        scenarioCheck('an old queued request is refused, nothing restored', [$result[0] ?? null, stripos((string) ($result[1] ?? ''), 'nothing was changed') !== false], [false, true]);
        scenarioCheck('... after ITFlow\'s queued restore set TMPDIR next to the backups (our hook)', rtrim((string) getenv('TMPDIR'), '/'), backupStorageDir() . '/mit_tmp');
    } finally {
        @unlink($request_file);
        $status_was === null ? @unlink($status_file) : file_put_contents($status_file, $status_was);
    }
} else {
    echo "  info: a real restore is queued - the hook check through it is left out\n";
}
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
