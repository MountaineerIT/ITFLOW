<?php

/*
 * Mountaineer IT - Admin > Backup: upload a backup, and restore one over this install (functions/mit_backup_restore.php).
 * Admins only (admin/post.php).
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['mit_dev_mode_save'])) {

    validateCSRFToken();

    $on = !empty($_POST['dev_mode']);
    $to = trim((string) ($_POST['dev_mail_to'] ?? ''));
    if ($to !== '' && !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        flashAlert('The test inbox is not a valid email address.', 'error');
        redirect("backup.php");
    }
    if ($on && $to === '') {
        flashAlert('Give a test inbox - in development mode every email goes there.', 'error');
        redirect("backup.php");
    }
    $why = mitConfigSet('config_mit_dev_mail_to', $to) ?: mitConfigSet('config_mit_dev_mode', $on ? 1 : 0);
    if ($why !== '') {
        flashAlert(escapeHtml($why), 'error');
        redirect("backup.php");
    }
    logAudit("Settings", "Edit", escapeSql(stripslashes((string) $session_name) . ' turned development mode ' . ($on ? "on (mail to $to)" : 'off')));
    flashAlert($on ? 'Development mode is on - every email goes to ' . escapeHtml($to) . '.' : 'Development mode is off - email and the integrations work normally again.', $on ? 'warning' : 'success');
    redirect("backup.php");
}

if (isset($_POST['mit_backup_upload'])) {

    validateCSRFToken();

    [$backup_id, $message] = mitRestoreUpload($_FILES['backup_file'] ?? [], $_POST['restore_key'] ?? '');
    if ($backup_id) {
        logAudit("Backup", "Upload", escapeSql(stripslashes((string) $session_name) . " uploaded a backup to restore"));
        flashAlert(escapeHtml($message) . ' Check it and confirm below.');
        redirect("backup.php?mit_restore=$backup_id");
    }
    flashAlert(escapeHtml($message), 'error');
    redirect("backup.php");
}

// A big backup from the browser, in pieces (admin/includes/mit_restore_card.php's script) - JSON answers
if (isset($_POST['mit_backup_chunk_start']) || isset($_POST['mit_backup_chunk']) || isset($_POST['mit_backup_chunk_finish'])) {

    validateCSRFToken();

    header('Content-Type: application/json');
    try {
        if (isset($_POST['mit_backup_chunk_start'])) {
            [$id, $chunk] = mitRestoreChunkStart($_POST['name'] ?? '', $_POST['size'] ?? 0);
            echo json_encode(['id' => $id, 'chunk' => $chunk]);
        } elseif (isset($_POST['mit_backup_chunk'])) {
            echo json_encode(['received' => mitRestoreChunkAppend($_POST['id'] ?? '', $_POST['offset'] ?? -1, $_FILES['chunk'] ?? [])]);
        } else {
            [$backup_id, $message] = mitRestoreChunkFinish($_POST['id'] ?? '', $_POST['restore_key'] ?? '');
            if (!$backup_id) {
                throw new RuntimeException($message);
            }
            logAudit("Backup", "Upload", escapeSql(stripslashes((string) $session_name) . " uploaded a backup to restore"));
            flashAlert(escapeHtml($message) . ' Check it and confirm below.');
            echo json_encode(['redirect' => "backup.php?mit_restore=$backup_id"]);
        }
    } catch (Throwable $e) {
        http_response_code(422);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

if (isset($_POST['mit_backup_restore'])) {

    validateCSRFToken();

    $who = stripslashes((string) $session_name);
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT user_password FROM users WHERE user_id = " . intval($session_user_id)));
    if (!$row || !password_verify((string) ($_POST['password'] ?? ''), $row['user_password'])) {
        logAudit("Backup", "Restore", escapeSql("$who tried to restore a backup with a wrong password"));
        flashAlert('Incorrect password - nothing was changed.', 'error');
        redirect("backup.php");
    }
    if (($_POST['confirm'] ?? '') !== 'RESTORE') {
        flashAlert('Type RESTORE to confirm - nothing was changed.', 'error');
        redirect("backup.php");
    }
    [$backup, $path] = mitRestoreStored($_POST['backup_id'] ?? 0);
    if (!$backup) {
        flashAlert(escapeHtml($path), 'error');
        redirect("backup.php");
    }

    // Other tabs of this agent must not wait on the session lock for minutes
    session_write_close();
    [$ok, $message] = mitRestoreRun($path, mitRestoreKey($_POST['restore_key'] ?? ''), $who, $backup);
    if (!$ok) {
        // ITFlow may have restored the database before something later failed - bring it up to this code's version
        if (mitRestoreItflowUpdates() === '') {
            define('MIT_FROM_RESTORE', true);
            require __DIR__ . "/../database_updates_mountaineerit.php";
        }
        session_start();
        logAudit("Backup", "Restore", escapeSql("$who could not restore " . $backup['backup_file_name'] . ": $message"));
        flashAlert(escapeHtml($message), 'error');
        redirect("backup.php");
    }

    // The restored database may be older than this code - ITFlow's updates, then ours (as scripts/restore_cli.php)
    $warnings = [];
    $mit_itflow_error = mitRestoreItflowUpdates();
    if ($mit_itflow_error !== '') {
        $warnings[] = "ITFlow's database update stopped at $mit_itflow_error - run Admin > Update to finish.";
    } else {
        define('MIT_FROM_RESTORE', true);
        require __DIR__ . "/../database_updates_mountaineerit.php";
        if ($mit_updates_error) {
            $warnings[] = "Mountaineer IT database update stopped at $mit_updates_error - run Admin > Update to retry.";
        }
    }
    logAudit("Backup", "Restore", escapeSql("$who restored " . $backup['backup_file_name'] . " from Admin > Backup"));
    mitRestoreSetStatus(['state' => 'done', 'who' => $who, 'file' => $backup['backup_file_name'], 'message' => $message, 'warnings' => $warnings]);

    // Everyone signs in again - the user list is the backup's now
    session_start();
    $_SESSION = [];
    session_destroy();
    ?>
    <!DOCTYPE html>
    <html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Backup restored</title>
    <link rel="stylesheet" href="/libs/adminlte/css/adminlte.min.css"></head>
    <body class="bg-body-tertiary"><div class="container py-5" style="max-width: 640px">
        <div class="card shadow-sm"><div class="card-body p-4" data-mit-restored>
            <h4 class="mb-3">Backup restored</h4>
            <p><?= escapeHtml($message) ?></p>
            <?php foreach ($warnings as $warning) { ?><div class="alert alert-warning"><?= escapeHtml($warning) ?></div><?php } ?>
            <p class="text-muted">You have been signed out. Sign in with an account from the restored backup.</p>
            <a href="/login.php" class="btn btn-primary">Sign in</a>
        </div></div>
    </div></body></html>
    <?php
    exit;
}
