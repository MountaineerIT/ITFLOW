<?php

/*
 * Mountaineer IT - restore a stored backup over this install (functions/mit_backup_restore.php, handler
 * admin/post/mit_backup_restore.php). ?backup_id= - the Restore button on Admin > Backup. Admins only (modal_header).
 */

require_once '../../includes/modal_header.php';

[$row, $path_or_why] = mitRestoreStored($_GET['backup_id'] ?? 0);
$meta = null;
$problem = '';
$needs_key = false;
if ($row) {
    [$meta, $problem] = mitRestoreCheck($path_or_why, mitRestoreKey(''));
    // Made with another install's key - read it once that key is typed
    $needs_key = !$meta && stripos($problem, 'key') !== false;
}
?>
<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fa fa-fw fa-undo me-2"></i>Restore a backup</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<form action="post.php" method="post" autocomplete="off" data-mit-restore>
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="backup_id" value="<?= intval($row['backup_id'] ?? 0) ?>">
    <div class="modal-body">
        <?php if (!$row) { ?>
            <div class="alert alert-danger mb-0"><?= escapeHtml($path_or_why) ?></div>
        <?php } else { ?>
            <dl class="row small mb-3">
                <dt class="col-4">Backup</dt><dd class="col-8"><?= escapeHtml(backupTypeLabel($row['backup_type'])) ?> · <?= escapeHtml(backupFormatBytes($row['backup_size'])) ?> · <?= escapeHtml($row['backup_source']) ?></dd>
                <?php if ($meta) { ?>
                    <dt class="col-4">Taken</dt><dd class="col-8"><?= escapeHtml($meta['generated']) ?></dd>
                    <dt class="col-4">ITFlow / database</dt><dd class="col-8"><?= escapeHtml($meta['app_version']) ?> / <?= escapeHtml($meta['database_version']) ?> <span class="text-muted">(this install: <?= escapeHtml(backupCurrentDatabaseVersion($mysqli)) ?>)</span></dd>
                    <dt class="col-4">Contains</dt><dd class="col-8"><?= in_array('uploads.zip', $meta['entries'], true) ? 'Database and uploads' : 'Database only' ?></dd>
                <?php } ?>
            </dl>
            <?php if ($problem !== '' && !$needs_key) { ?>
                <div class="alert alert-danger"><?= escapeHtml($problem) ?></div>
            <?php } else { ?>
                <div class="alert alert-warning small">
                    <strong>This replaces everything</strong> - the database<?= !$meta || in_array('uploads.zip', $meta['entries'], true) ? ' and the uploads folder (attachments, logos, receipts)' : '' ?> - with what is in the backup.
                    First a Full backup of the system as it is now is made and kept as <strong>"Before restore"</strong>, so this can be undone.
                    Everyone is signed out afterwards; sign in with the accounts that were in the backup. Anyone working meanwhile may see errors - it takes a minute or more (a big backup longer: if this page stops waiting, the
                    restore carries on - sign in again in a few minutes and Admin > Backup shows how it went).
                </div>
                <div class="mb-3">
                    <label class="form-label">Encryption key<?= $needs_key ? '' : ' <span class="text-muted small">(blank = this install\'s)</span>' ?></label>
                    <input type="password" class="form-control" name="restore_key" autocomplete="off" <?= $needs_key ? 'required' : '' ?>>
                    <div class="form-text"><?= $needs_key ? 'This backup was made with another install\'s key - type that key (Admin > Backup on that install).' : 'Only needed for a backup made by another install. Its saved connection secrets (Microsoft 365, NinjaOne, QuickBooks...) will need entering again.' ?></div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Your password</label>
                    <input type="password" class="form-control" name="password" autocomplete="current-password" required>
                </div>
                <div class="mb-0">
                    <label class="form-label">Type <strong>RESTORE</strong> to confirm</label>
                    <input type="text" class="form-control" name="confirm" pattern="RESTORE" required autocomplete="off">
                </div>
            <?php } ?>
        <?php } ?>
    </div>
    <div class="modal-footer">
        <?php if ($row && ($problem === '' || $needs_key)) { ?>
            <input type="hidden" name="mit_backup_restore" value="1">
            <button type="submit" class="btn btn-danger" data-mit-restore-go onclick="this.form.addEventListener('submit', () => { this.disabled = true; this.innerHTML = '<span class=\'spinner-border spinner-border-sm me-2\'></span>Restoring - do not close this page'; })"><i class="fa fa-undo me-2"></i>Restore</button>
        <?php } ?>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>
</form>

<?php
require_once '../../../includes/modal_footer.php';
