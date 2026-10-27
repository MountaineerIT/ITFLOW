<?php

/*
 * Mountaineer IT - restore a backup on a running install from Admin > Backup (ITFlow only restores in the setup wizard on a
 * fresh install, or from the command line). Required from functions/mit_common.php. The work is ITFlow's own
 * backupRestoreArchive() (functions/backup.php - the current database is dumped first and put back if the import fails);
 * around it:
 *   - a stored backup is picked from the list, or a .zip uploaded and added to the list (source "Uploaded")
 *   - before anything changes, a Full backup of the system as it is ("Before restore") - kept, so a restore can be undone
 *   - after it, the "Before restore" row is put back in the list (the restore brought back the backups table as it was)
 * The handler (admin/post/mit_backup_restore.php) then runs ITFlow's and our database updates, logs it and signs the admin out.
 * Admins only (the admin pages); the admin's own password and typing RESTORE confirm it.
 * Big backups (up to MIT_RESTORE_MAX_BYTES): the browser sends the file in pieces (mitRestoreChunk*, the script in
 * admin/includes/mit_restore_card.php), so PHP's upload limit and a proxy's request limit don't matter. Free disk is checked
 * before the upload and before the restore. Each restore writes how it went to mit_last_restore.json in the backups folder,
 * shown on Admin > Backup - a long restore carries on when a proxy stops waiting for the page.
 */

const MIT_RESTORE_SOURCE = 'Before restore';
const MIT_RESTORE_MAX_BYTES = 5 * 1024 * 1024 * 1024;  // biggest backup the browser upload takes (sent in pieces)
const MIT_RESTORE_CHUNK_BYTES = 32 * 1024 * 1024;      // one piece - under PHP's and proxies' (Cloudflare: 100 MB) request limits

/** A stored backup that can be restored: [row, path] or [null, why]. */
function mitRestoreStored($backup_id) {
    global $mysqli;
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM backups WHERE backup_id = " . intval($backup_id)));
    if (!$row || $row['backup_status'] !== 'Complete') {
        return [null, 'That backup is not there, or not complete.'];
    }
    if ($row['backup_type'] === BACKUP_TYPE_MASTER_KEY) {
        return [null, 'A master key export cannot be restored.'];
    }
    $path = backupResolvePath($row['backup_file_name']);
    if ($path === false || !is_file($path)) {
        return [null, 'The backup file is missing from the server.'];
    }
    return [$row, $path];
}

/** The key to open an archive with: the one typed, else this install's. */
function mitRestoreKey($typed) {
    $typed = trim((string) $typed);
    return $typed !== '' ? $typed : backupEncryptionKey();
}

/**
 * What is in an archive, and whether it may be restored here: [meta, problem] - problem '' when it can be restored.
 * A backup from a newer database version than this code is refused (the app would break after it).
 */
function mitRestoreCheck($path, $key) {
    global $mysqli;
    $error = null;
    $meta = backupInspectArchive($path, $key, $error);
    if ($meta === false) {
        return [null, (string) $error];
    }
    $current = backupCurrentDatabaseVersion($mysqli);
    if ($meta['database_version'] !== 'Unknown' && version_compare($meta['database_version'], $current, '>')) {
        return [$meta, "This backup is from a newer database version ({$meta['database_version']}) than this install ($current) - update ITFlow first."];
    }
    return [$meta, ''];
}

/** An uploaded .zip ($_FILES entry, a small file in one request): checked, stored with the other backups and listed. Returns [backup_id, message]. */
function mitRestoreUpload(array $file, $typed_key) {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
        $too_big = in_array($file['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
        return [0, $too_big ? 'The file is bigger than this server takes in one go (' . backupFormatBytes(backupMaxUploadBytes()) . ') - upload it from Admin > Backup with JavaScript on (it goes in pieces), or copy it to the server and use the command line restore.' : 'Pick a backup .zip to upload.'];
    }
    return mitRestoreAdopt($file['tmp_name'], $typed_key, true);
}

/**
 * A backup file on the server (an upload): checked with its key, moved in with the other backups under a backup's name and
 * listed (source "Uploaded"). The file is removed when it is not a backup. Returns [backup_id, message].
 */
function mitRestoreAdopt($tmp, $typed_key, $uploaded = false) {
    global $mysqli, $session_name;
    [$meta, $problem] = mitRestoreCheck($tmp, mitRestoreKey($typed_key));
    if (!$meta) {
        $uploaded || @unlink($tmp);
        return [0, "That is not a backup that can be read: $problem"];
    }
    $type = in_array($meta['type'], backupAllTypes(), true) ? $meta['type'] : BACKUP_TYPE_FULL;
    $name = backupBuildFileName($type, randomString(32));
    $path = backupStorageDir() . '/' . $name;
    if (!($uploaded ? move_uploaded_file($tmp, $path) : rename($tmp, $path))) {
        $uploaded || @unlink($tmp);
        return [0, 'The file could not be saved with the backups (is the backup folder writable?).'];
    }
    @chmod($path, 0600);
    $size = filesize($path) ?: 0;
    $sha = hash_file('sha256', $path);
    mysqli_query($mysqli, "INSERT INTO backups SET backup_type = '" . escapeSql($type) . "', backup_file_name = '" . escapeSql($name) . "', backup_size = $size,
        backup_sha256 = '$sha', backup_status = 'Complete', backup_source = 'Uploaded', backup_created_by = '" . escapeSql(stripslashes((string) $session_name)) . "',
        backup_completed_at = NOW()");
    return [intval(mysqli_insert_id($mysqli)), 'Backup uploaded (' . backupTypeLabel($type) . ', ' . backupFormatBytes($size) . ', taken ' . $meta['generated'] . ').' . ($problem !== '' ? " $problem" : '')];
}

/** '' when the backups folder's disk has $bytes free, else why not. */
function mitRestoreDiskProblem($bytes, $for) {
    $free = @disk_free_space(backupStorageDir());
    if ($free === false || $free >= $bytes) {
        return '';
    }
    return "Not enough disk space $for: about " . backupFormatBytes($bytes) . ' is needed, ' . backupFormatBytes($free) . ' is free.';
}

/** The size of one piece of a browser upload - under PHP's request limit on this server. */
function mitRestoreChunkBytes() {
    $limit = backupMaxUploadBytes();
    return $limit > 0 ? max(512 * 1024, min(MIT_RESTORE_CHUNK_BYTES, $limit - 256 * 1024)) : MIT_RESTORE_CHUNK_BYTES;
}

function mitRestorePartPath($id) {
    return backupStorageDir() . '/mit_upload_' . preg_replace('/[^a-f0-9]/', '', (string) $id) . '.part';
}

/** A browser upload in pieces starts: [id, piece size] - throws with why not. Kept in the admin's session. */
function mitRestoreChunkStart($name, $size) {
    $size = intval($size);
    if (!preg_match('/\.zip$/i', (string) $name)) {
        throw new RuntimeException('Pick a backup .zip to upload.');
    }
    if ($size <= 0 || $size > MIT_RESTORE_MAX_BYTES) {
        throw new RuntimeException('A backup up to ' . backupFormatBytes(MIT_RESTORE_MAX_BYTES) . ' can be uploaded here - copy a bigger one to the server and use the command line restore.');
    }
    // Pieces of uploads that never finished (a closed browser) go after a day
    foreach (glob(backupStorageDir() . '/mit_upload_*.part') ?: [] as $old) {
        if (filemtime($old) < time() - 86400) {
            @unlink($old);
        }
    }
    if (!empty($_SESSION['mit_restore_upload']['id'])) {
        @unlink(mitRestorePartPath($_SESSION['mit_restore_upload']['id']));
    }
    $problem = mitRestoreDiskProblem($size + 512 * 1024 * 1024, 'for the upload');
    if ($problem !== '') {
        throw new RuntimeException($problem);
    }
    $id = bin2hex(random_bytes(16));
    if (@file_put_contents(mitRestorePartPath($id), '') === false) {
        throw new RuntimeException('The backup folder is not writable.');
    }
    @chmod(mitRestorePartPath($id), 0600);
    $_SESSION['mit_restore_upload'] = ['id' => $id, 'size' => $size, 'received' => 0];
    return [$id, mitRestoreChunkBytes()];
}

/** One piece ($_FILES entry) at $offset - returns the bytes received so far; throws with why not. */
function mitRestoreChunkAppend($id, $offset, array $file) {
    $upload = $_SESSION['mit_restore_upload'] ?? null;
    if (!$upload || !hash_equals($upload['id'], (string) $id)) {
        throw new RuntimeException('This upload is no longer going on - start it again.');
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
        throw new RuntimeException('A piece of the file did not arrive (error ' . intval($file['error'] ?? 0) . ').');
    }
    if (intval($offset) !== intval($upload['received'])) {
        throw new RuntimeException('A piece arrived out of order - start the upload again.');
    }
    $length = filesize($file['tmp_name']);
    if ($length <= 0 || $length > mitRestoreChunkBytes() || $upload['received'] + $length > $upload['size']) {
        throw new RuntimeException('A piece of the file was not the expected size - start the upload again.');
    }
    $in = fopen($file['tmp_name'], 'rb');
    $out = fopen(mitRestorePartPath($id), 'ab');
    $copied = ($in && $out) ? stream_copy_to_stream($in, $out) : false;
    $in && fclose($in);
    $out && fclose($out);
    if ($copied !== $length) {
        throw new RuntimeException('A piece could not be written to the backup folder (disk full?).');
    }
    $_SESSION['mit_restore_upload']['received'] += $length;
    return $_SESSION['mit_restore_upload']['received'];
}

/** Every piece is in: checked and listed like a one-go upload. Returns [backup_id, message]. */
function mitRestoreChunkFinish($id, $typed_key) {
    $upload = $_SESSION['mit_restore_upload'] ?? null;
    unset($_SESSION['mit_restore_upload']);
    if (!$upload || !hash_equals($upload['id'], (string) $id)) {
        return [0, 'This upload is no longer going on - start it again.'];
    }
    $part = mitRestorePartPath($id);
    if (intval($upload['received']) !== intval($upload['size']) || !is_file($part) || filesize($part) !== intval($upload['size'])) {
        @unlink($part);
        return [0, 'The upload did not finish - start it again.'];
    }
    return mitRestoreAdopt($part, $typed_key);
}

/**
 * ITFlow's backup and restore work in PHP's temporary folder (sys_get_temp_dir - /tmp). On Debian 13 /tmp is a RAM disk of half
 * the memory, too small to unpack a big backup (ZipArchive then fails with "No error"). For our restore that folder is moved
 * next to the backups (same disk as the free-space check; ITFlow's backups leave the backups folder out): PHP reads TMPDIR the
 * first time sys_get_temp_dir() is asked in a request. Returns the folder in use.
 */
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

/** How the last restore went (mit_last_restore.json in the backups folder - it outlives the restore): array or null. */
function mitRestoreLastStatus() {
    $raw = @file_get_contents(backupStorageDir() . '/mit_last_restore.json');
    $status = $raw ? json_decode($raw, true) : null;
    return is_array($status) ? $status : null;
}

function mitRestoreSetStatus(array $status) {
    $file = backupStorageDir() . '/mit_last_restore.json';
    @file_put_contents($file, json_encode($status + ['at' => date('Y-m-d H:i:s')]), LOCK_EX);
    @chmod($file, 0600);
}

/**
 * Restore an archive over this install: a "Before restore" Full backup first, then ITFlow's restore, then the "Before
 * restore" row put back in the restored list. Returns [ok, message]. The database updates are the caller's (they are
 * included files that run at file scope).
 */
function mitRestoreRun($path, $key, $who, array $from = []) {
    global $mysqli;
    @set_time_limit(0);
    ignore_user_abort(true); // a browser or proxy that gives up must not stop it half way

    [$meta, $problem] = mitRestoreCheck($path, $key);
    if (!$meta || $problem !== '') {
        return [false, $problem];
    }
    $temp = mitRestoreUseTempDir();
    // The "Before restore" copy (about the size of what is here now), the unpacked archive and the import
    $problem = mitRestoreDiskProblem(3 * (filesize($path) ?: 0), 'to restore it');
    // Unpacking somewhere else (PHP had already chosen its temporary folder): that disk needs room too
    $temp_free = $temp !== rtrim(backupStorageDir(), '/') . '/mit_tmp' ? @disk_free_space($temp) : false;
    if ($problem === '' && $temp_free !== false && $temp_free < 1.5 * (filesize($path) ?: 0)) {
        $problem = "Not enough space in the temporary folder $temp to unpack it: about " . backupFormatBytes(1.5 * filesize($path)) . ' is needed, '
            . backupFormatBytes($temp_free) . ' is free.';
    }
    if ($problem !== '') {
        return [false, "Nothing was changed - $problem"];
    }
    if (!mitLock('backup_restore', 1)) {
        return [false, 'A restore is already running.'];
    }
    mitRestoreSetStatus(['state' => 'running', 'who' => (string) $who, 'file' => basename($path), 'started' => date('Y-m-d H:i:s')]);

    // The system as it is now, kept - so this can be undone
    $error = null;
    $safety_id = backupCreate($mysqli, BACKUP_TYPE_FULL, $who, MIT_RESTORE_SOURCE, $error);
    if (!$safety_id) {
        mitUnlock('backup_restore');
        mitRestoreSetStatus(['state' => 'failed', 'who' => (string) $who, 'file' => basename($path), 'message' => 'Nothing was changed - the safety backup of the current system failed: ' . $error]);
        return [false, 'Nothing was changed - the safety backup of the current system failed: ' . $error];
    }
    $safety = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM backups WHERE backup_id = $safety_id"));

    $error = null;
    $ok = backupRestoreArchive($mysqli, $path, $key, $error);
    $mysqli = backupDbEnsure($mysqli);

    // The restored list is the backup's own, from while it was being made (so its row says Running) - put its row and the
    // safety copy's right (others made since are adopted by ITFlow's retention)
    foreach (array_filter([$from, $safety]) as $keep) {
        $set = [];
        foreach (['backup_type', 'backup_file_name', 'backup_size', 'backup_sha256', 'backup_status', 'backup_source', 'backup_created_by', 'backup_created_at', 'backup_completed_at'] as $column) {
            $set[] = "$column = " . ($keep[$column] === null ? 'NULL' : "'" . escapeSql($keep[$column]) . "'");
        }
        $name = escapeSql($keep['backup_file_name']);
        if (mysqli_num_rows(mysqli_query($mysqli, "SELECT 1 FROM backups WHERE backup_file_name = '$name'"))) {
            mysqli_query($mysqli, "UPDATE backups SET " . implode(', ', $set) . ", backup_error = NULL WHERE backup_file_name = '$name'");
        } else {
            mysqli_query($mysqli, "INSERT INTO backups SET " . implode(', ', $set));
        }
    }
    // A backup from another server lists the one it was making then (its file is not here) - not left as Running
    $running = mysqli_query($mysqli, "SELECT backup_id, backup_file_name FROM backups WHERE backup_status IN ('Running', 'Pending')");
    while ($row = mysqli_fetch_assoc($running)) {
        $file = backupResolvePath($row['backup_file_name']);
        if ($file === false || !is_file($file)) {
            mysqli_query($mysqli, "UPDATE backups SET backup_status = 'Failed', backup_error = 'Came with a restored backup - the file is not on this server', backup_completed_at = NOW() WHERE backup_id = " . intval($row['backup_id']));
        }
    }
    if (!$ok) {
        // The list is put right on a failure too - ITFlow may have restored the database before the uploads failed
        mitUnlock('backup_restore');
        mitRestoreSetStatus(['state' => 'failed', 'who' => (string) $who, 'file' => basename($path), 'message' => "Restore failed: $error"]);
        return [false, "Restore failed: $error"];
    }
    mitUnlock('backup_restore');
    return [true, backupTypeLabel($meta['type']) . ' taken ' . $meta['generated'] . ' restored. The system as it was is kept as the "' . MIT_RESTORE_SOURCE . '" backup.'];
}

/** Admin > Backup: the Restore button on a backup's row (one line in admin/backup.php). */
function mitRestoreRowButton(array $backup) {
    if ($backup['backup_status'] !== 'Complete' || $backup['backup_type'] === BACKUP_TYPE_MASTER_KEY) {
        return '';
    }
    return '<button type="button" class="btn btn-sm btn-warning ajax-modal" title="Restore this backup" data-modal-url="modals/mit_backup/restore.php?backup_id='
        . intval($backup['backup_id']) . '"><i class="fas fa-fw fa-undo"></i></button>';
}
