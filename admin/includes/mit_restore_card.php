<?php
/*
 * Mountaineer IT - Admin > Backup, "Restoring" box: restore from here (functions/mit_backup_restore.php). Required from
 * admin/backup.php. Restore (the undo button) on a backup in the list, or upload a .zip - it is added to the list and its
 * Restore window opens (?mit_restore=<backup id>).
 */
$mit_restore_open = intval($_GET['mit_restore'] ?? 0);
$mit_restore_last = mitRestoreLastStatus();
?>
<?php if ($mit_restore_last && strtotime($mit_restore_last['at'] ?? '') > time() - 7 * 86400) {
    $state = $mit_restore_last['state'] ?? '';
    $tone = ['done' => empty($mit_restore_last['warnings']) ? 'success' : 'warning', 'failed' => 'danger', 'running' => 'info'][$state] ?? 'secondary'; ?>
    <div class="alert alert-<?= $tone ?> small" data-mit-last-restore="<?= escapeHtml($state) ?>">
        <strong>Last restore:</strong>
        <?php if ($state === 'running') { ?>
            started <?= escapeHtml($mit_restore_last['started'] ?? '') ?> by <?= escapeHtml($mit_restore_last['who'] ?? '') ?> and still running
            (or stopped without finishing) - a big backup takes a while; reload this page in a few minutes.
        <?php } else { ?>
            <?= $state === 'done' ? 'finished' : 'failed' ?> <?= escapeHtml($mit_restore_last['at'] ?? '') ?> (<?= escapeHtml($mit_restore_last['who'] ?? '') ?>) -
            <?= escapeHtml($mit_restore_last['message'] ?? '') ?>
            <?php foreach ((array) ($mit_restore_last['warnings'] ?? []) as $warning) { ?><div><?= escapeHtml($warning) ?></div><?php } ?>
        <?php } ?>
    </div>
<?php } ?>
<div class="border rounded p-3 mb-4 <?= mitDevMode() ? 'border-danger' : '' ?>" data-mit-dev-card>
    <h6 class="mb-1"><i class="fas fa-fw fa-flask me-2"></i>Development mode <?= mitDevMode() ? '<span class="badge text-bg-danger ms-1">On</span>' : '<span class="badge text-bg-secondary ms-1">Off</span>' ?></h6>
    <p class="small text-muted mb-2">Turn this on <strong>before</strong> restoring production's backup onto a test server. Every email then goes to the test inbox
        instead of clients and staff; the ticket mailbox is not read; Stripe card payments and custom actions are switched off; NinjaOne, Bitwarden,
        Ingram and Outlook busy times still sync in, but nothing is written back to them; QuickBooks works only with a sandbox company.
        It is kept in this server's config.php, so a restore cannot switch it off. Never turn it on in production.</p>
    <form action="post.php" method="post" class="row g-2 align-items-end" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        <div class="col-md-3">
            <div class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" role="switch" name="dev_mode" value="1" id="mitDevMode" <?= mitDevMode() ? 'checked' : '' ?>>
                <label class="form-check-label" for="mitDevMode">Development mode</label>
            </div>
        </div>
        <div class="col-md-6">
            <label class="form-label small mb-1">Test inbox - every email goes here</label>
            <input type="email" class="form-control" name="dev_mail_to" value="<?= escapeHtml(mitDevMailTo()) ?>" placeholder="dev@yourcompany.com">
        </div>
        <div class="col-md-3">
            <button type="submit" name="mit_dev_mode_save" class="btn btn-outline-danger w-100"><i class="fas fa-fw fa-check me-2"></i>Save</button>
        </div>
    </form>
</div>
<p><strong>From here</strong> - press <span class="badge text-bg-warning"><i class="fas fa-fw fa-undo"></i></span> on a backup above, or upload one
    (a backup from this or another ITFlow server, up to <?= escapeHtml(backupFormatBytes(MIT_RESTORE_MAX_BYTES)) ?> - it is sent in pieces). You see what
    is in it before anything changes; a Full backup of the system as it is now is kept as "Before restore", and everyone signs in again afterwards.
    A restore needs free disk of about three times the backup's size.</p>
<form action="post.php" method="post" enctype="multipart/form-data" class="row g-2 align-items-end mb-4" autocomplete="off" data-mit-backup-upload
      data-max="<?= MIT_RESTORE_MAX_BYTES ?>">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <div class="col-md-5">
        <label class="form-label small mb-1">Backup file (.zip)</label>
        <input type="file" class="form-control" name="backup_file" accept=".zip,application/zip" required>
    </div>
    <div class="col-md-4">
        <label class="form-label small mb-1">Its encryption key <span class="text-muted">(blank = this install's)</span></label>
        <input type="password" class="form-control" name="restore_key" autocomplete="off">
    </div>
    <div class="col-md-3">
        <button type="submit" name="mit_backup_upload" class="btn btn-outline-primary w-100"><i class="fas fa-fw fa-upload me-2"></i>Upload to restore</button>
    </div>
    <div class="col-12 d-none" data-mit-upload-progress>
        <div class="progress" role="progressbar" aria-label="Upload"><div class="progress-bar progress-bar-striped progress-bar-animated" style="width: 0%"></div></div>
        <div class="small text-muted mt-1" data-mit-upload-text></div>
    </div>
</form>
<script>
// Mountaineer IT - send the backup in pieces (admin/post/mit_backup_restore.php mit_backup_chunk_*), so a file bigger than PHP's or
// a proxy's request limit gets through. Keep this page open until it is done.
(function () {
    var form = document.querySelector('[data-mit-backup-upload]');
    if (!form || !window.fetch || !window.FormData || !window.Blob) {
        return;
    }
    var box = form.querySelector('[data-mit-upload-progress]'), bar = box.querySelector('.progress-bar'), text = box.querySelector('[data-mit-upload-text]');
    var button = form.querySelector('button[name=mit_backup_upload]');
    function post(fields, file) {
        var data = new FormData();
        data.append('csrf_token', form.querySelector('[name=csrf_token]').value);
        Object.keys(fields).forEach(function (k) { data.append(k, fields[k]); });
        if (file) {
            data.append('chunk', file, 'chunk');
        }
        return fetch(form.getAttribute('action'), { method: 'POST', body: data, credentials: 'same-origin' }).then(function (r) {
            return r.text().then(function (body) {
                var j = null;
                try { j = JSON.parse(body); } catch (e) { j = null; }
                if (j && r.ok && !j.error) {
                    return j;
                }
                throw new Error(j && j.error ? j.error : refused(r.status, body));
            });
        });
    }
    // What answered when it was not our JSON - ITFlow's own page (a sign-in that no longer matches), a proxy such as Cloudflare, or PHP
    function refused(status, body) {
        var doc = new DOMParser().parseFromString(body || '', 'text/html');
        var said = ((doc.title || '') + ' ' + ((doc.querySelector('p, h1, h2') || {}).textContent || '')).replace(/\s+/g, ' ').trim().slice(0, 200);
        var why = 'The server answered HTTP ' + status + (said ? ': "' + said + '"' : '') + '.';
        if (/csrf/i.test(body)) {
            return why + ' Your sign-in no longer matches this page (it timed out, or you signed in again elsewhere) - reload the page and upload again.';
        }
        if (/cloudflare|cf-ray|attention required/i.test(body)) {
            return why + ' Cloudflare stopped the upload - allow uploads to /admin/post.php in its firewall, or restore from the command line below.';
        }
        if (status === 413) {
            return why + ' A piece is over the web server\'s request limit - raise it, or restore from the command line below.';
        }
        return why;
    }
    function mb(n) { return (n / 1048576).toFixed(n < 104857600 ? 1 : 0) + ' MB'; }
    form.addEventListener('submit', function (e) {
        var file = form.querySelector('[name=backup_file]').files[0];
        if (!file) {
            return;
        }
        e.preventDefault();
        if (file.size > Number(form.dataset.max)) {
            alert('A backup up to ' + mb(Number(form.dataset.max)) + ' can be uploaded here.');
            return;
        }
        button.disabled = true;
        box.classList.remove('d-none');
        var key = form.querySelector('[name=restore_key]').value, id, size;
        post({ mit_backup_chunk_start: 1, name: file.name, size: file.size }).then(function (start) {
            id = start.id;
            size = start.chunk;
            var send = function (offset) {
                if (offset >= file.size) {
                    text.textContent = 'Checking the backup...';
                    return post({ mit_backup_chunk_finish: 1, id: id, restore_key: key });
                }
                return post({ mit_backup_chunk: 1, id: id, offset: offset }, file.slice(offset, offset + size)).catch(function (err) {
                    throw new Error('Piece at ' + mb(offset) + ' of ' + mb(file.size) + ': ' + err.message);
                }).then(function (r) {
                    bar.style.width = Math.floor(100 * r.received / file.size) + '%';
                    text.textContent = mb(r.received) + ' of ' + mb(file.size) + ' sent - keep this page open';
                    return send(r.received);
                });
            };
            return send(0);
        }).then(function (done) {
            window.location.href = done.redirect;
        }).catch(function (err) {
            button.disabled = false;
            bar.classList.add('bg-danger');
            text.textContent = 'Upload stopped: ' + err.message;
        });
    });
})();
</script>
<?php if ($mit_restore_open) { ?>
    <button type="button" class="d-none ajax-modal" data-mit-restore-open data-modal-url="modals/mit_backup/restore.php?backup_id=<?= $mit_restore_open ?>"></button>
    <script>window.addEventListener('load', function () { document.querySelector('[data-mit-restore-open]').click(); });</script>
<?php } ?>
