<?php

/*
 * Mountaineer IT - restoring a backup on a running install. Since 26.12 ITFlow does it itself (functions/backup_restore.php: Restore in
 * a backup's menu or an upload in pieces under Restoring on Maintenance > Backup, queued and run by cron, then update_cli for the
 * database - which runs ours after ITFlow's), built from ours. What stays here:
 *  - mitRestoreUseTempDir(): ITFlow unpacks into sys_get_temp_dir() - /tmp, on Debian 13 a RAM disk of half the memory, too small for a
 *    big backup (ZipArchive then fails with "No error" - Oct 2026, a 1.1 GB backup). The restore's temporary folder is moved next to
 *    the backups (same disk as ITFlow's free-space check). One guarded line at the start of ITFlow's backupRestoreRunQueued.
 *    (A php.ini sys_temp_dir still wins - scripts/install.sh sets one for the CLI.)
 *  - mitRestoreItflowUpdates() (functions/mit_common.php): the setup wizard's restore and scripts/restore_cli.php run no database
 *    updates in ITFlow - ours run ITFlow's then ours there.
 */

/** Points PHP's temporary folder at <backups>/mit_tmp for this run; leftovers older than a day go. Returns the folder in use. */
function mitRestoreUseTempDir() {
    $dir = backupStorageDir() . '/mit_tmp';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    // Left by a restore that was stopped half way (a server restart)
    foreach (glob($dir . '/*') ?: [] as $old) {
        if (filemtime($old) < time() - 86400) {
            is_dir($old) ? backupDeleteDirectory($old) : @unlink($old);
        }
    }
    if (is_dir($dir) && is_writable($dir)) {
        putenv("TMPDIR=$dir");
        $_ENV['TMPDIR'] = $dir;
    }
    return rtrim(sys_get_temp_dir(), '/');
}
