<?php

/*
 * Mountaineer IT - Admin > Integrations > Customer Thermometer (functions/mit_ct.php; handlers admin/post/mit_customer_thermometer.php).
 * The API key, which thermometer and recipient list the surveys go out with (read from the account), which service boards'
 * tickets get one and from which day, on / off, Run now, and the latest surveys.
 */

require_once "includes/inc_all_admin.php";

if (!mitCtReady()) {
    echo '<div class="alert alert-warning">Customer Thermometer needs the database update - Admin > Update.</div>';
    require_once "../includes/footer.php";
    exit;
}

$settings = mitCtSettings();
$csrf = $_SESSION['csrf_token'];
$choices = json_decode((string) mitSetting('ct_choices', '{}'), true) ?: [];
$thermometers = $choices['thermometers'] ?? [];
$lists = $choices['lists'] ?? [];
$boards = mitBoards();
$missing = mitCtMissing();
$counts = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COUNT(*) AS total, SUM(status = 'sent') AS sent, SUM(status != 'sent') AS other,
    SUM(temperature IS NOT NULL) AS answered FROM mit_ct_surveys"));
$recent = mysqli_query($mysqli, "SELECT s.*, cl.client_name FROM mit_ct_surveys s LEFT JOIN clients cl ON cl.client_id = s.client_id ORDER BY s.survey_id DESC LIMIT 25");
$due = $settings['enabled'] && $missing === '' ? count(mitCtDueTickets(100)) : 0;

?>

<div class="card">
    <div class="card-header bg-dark py-2 d-flex flex-wrap align-items-center gap-2">
        <h3 class="card-title mt-2 me-auto"><i class="fas fa-fw fa-thermometer-half me-2"></i>Customer Thermometer</h3>
        <?php if ($missing === '') { ?>
            <form action="post.php" method="post">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <button type="submit" name="mit_ct_run" class="btn btn-light btn-sm"><i class="fas fa-sync-alt me-2"></i>Run now</button>
            </form>
        <?php } ?>
    </div>
    <div class="card-body">
        <p class="text-muted small">When a ticket on one of the boards below is resolved, its contact is emailed your thermometer by Customer
            Thermometer, carrying the tech's name, the ticket number and its subject (custom fields 1, 2 and 3 - add them to your
            thermometer's reporting). Responses come back every 15 minutes and show as Happiness on the dashboard and each client's overview.</p>
        <?php if (mitDevMode()) { ?>
            <div class="alert alert-danger small" data-mit-ct-dev><i class="fas fa-flask me-2"></i><strong>Development mode:</strong> every survey goes to the
                test inbox (<?= escapeHtml(mitDevMailTo() ?: 'none set - surveys are skipped') ?>), not to the customer.</div>
        <?php } ?>
        <?php if ($settings['last_run']) { ?>
            <div class="small mb-3 <?= strpos($settings['last_result'], 'Failed') === 0 ? 'text-danger' : 'text-muted' ?>" data-mit-ct-last>
                Last run <?= escapeHtml($settings['last_run']) ?>: <?= escapeHtml($settings['last_result']) ?></div>
        <?php } ?>

        <h6 class="mt-2">1. Connection</h6>
        <form action="post.php" method="post" class="row g-2 align-items-end mb-4" style="max-width: 46rem" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <div class="col-md-8">
                <label class="form-label small mb-1">API key <span class="text-muted">(Customer Thermometer > Account > Integrations)</span></label>
                <input type="password" class="form-control" name="ct_api_key" placeholder="<?= $settings['has_key'] ? 'Saved - leave blank to keep it' : 'Paste the API key' ?>" autocomplete="off">
            </div>
            <div class="col-md-4">
                <button type="submit" name="mit_ct_connect" class="btn btn-outline-primary w-100"><i class="fas fa-plug me-2"></i>Save and connect</button>
            </div>
            <?php if ($settings['has_key']) { ?>
                <div class="col-12 small text-muted">Connected - <?= count($thermometers) ?> thermometer<?= count($thermometers) === 1 ? '' : 's' ?> and
                    <?= count($lists) ?> recipient list<?= count($lists) === 1 ? '' : 's' ?> read from the account. Connect again after adding one there.</div>
            <?php } ?>
        </form>

        <h6>2. What to send</h6>
        <form action="post.php" method="post" style="max-width: 46rem">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" role="switch" name="ct_enabled" value="1" id="ctEnabled" <?= $settings['enabled'] ? 'checked' : '' ?>>
                <label class="form-check-label" for="ctEnabled">Send surveys</label>
            </div>
            <div class="row g-2 mb-3">
                <div class="col-md-6">
                    <label class="form-label small mb-1">Thermometer</label>
                    <select class="form-select" name="ct_thermometer_id">
                        <option value="">- Pick one -</option>
                        <?php foreach ($thermometers as $id => $name) { ?>
                            <option value="<?= escapeHtml($id) ?>" <?= (string) $id === $settings['thermometer_id'] ? 'selected' : '' ?>><?= escapeHtml($name) ?></option>
                        <?php } ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label small mb-1">Recipient list <span class="text-muted">(Customer Thermometer needs one to send through)</span></label>
                    <select class="form-select" name="ct_list_id">
                        <option value="">- Pick one -</option>
                        <?php foreach ($lists as $id => $name) { ?>
                            <option value="<?= escapeHtml($id) ?>" <?= (string) $id === $settings['list_id'] ? 'selected' : '' ?>><?= escapeHtml($name) ?></option>
                        <?php } ?>
                    </select>
                </div>
            </div>
            <label class="form-label small mb-1">Service boards whose tickets get a survey</label>
            <div class="mb-3 d-flex flex-wrap gap-3">
                <?php foreach ($boards as $id => $board) { ?>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="ct_boards[]" value="<?= intval($id) ?>" id="ctBoard<?= intval($id) ?>" <?= in_array(intval($id), $settings['boards'], true) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="ctBoard<?= intval($id) ?>"><?= escapeHtml($board['board_name']) ?></label>
                    </div>
                <?php } ?>
            </div>
            <div class="mb-3" style="max-width: 16rem">
                <label class="form-label small mb-1">Start date</label>
                <input type="date" class="form-control" name="ct_start" value="<?= escapeHtml($settings['start'] ?: date('Y-m-d')) ?>">
                <div class="form-text">Only tickets resolved on or after this day get a survey - older ones are never sent.</div>
            </div>
            <button type="submit" name="mit_ct_save" class="btn btn-primary"><i class="fas fa-check me-2"></i>Save</button>
            <?php if ($settings['enabled'] && $missing !== '') { ?>
                <span class="text-danger small ms-2">Nothing is sent until you set <?= escapeHtml($missing) ?>.</span>
            <?php } elseif ($due) { ?>
                <span class="text-muted small ms-2" data-mit-ct-due><?= $due ?> ticket<?= $due === 1 ? '' : 's' ?> waiting for a survey (sent within 5 minutes).</span>
            <?php } ?>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header py-2"><h3 class="card-title mt-1">Latest surveys
        <span class="small text-muted ms-2"><?= intval($counts['sent']) ?> sent · <?= intval($counts['answered']) ?> answered<?= intval($counts['other']) ? ' · ' . intval($counts['other']) . ' not sent' : '' ?></span></h3></div>
    <div class="card-body p-0">
        <table class="table table-sm table-hover mb-0" data-mit-ct-surveys>
            <thead><tr><th>Sent</th><th>Ticket</th><th>Client</th><th>To</th><th>Tech</th><th>Status</th><th>Response</th></tr></thead>
            <tbody>
            <?php while ($row = mysqli_fetch_assoc($recent)) { ?>
                <tr>
                    <td class="text-nowrap small"><?= escapeHtml($row['sent_at']) ?></td>
                    <td><?= $row['ticket_id'] ? '<a href="/agent/ticket.php?client_id=' . intval($row['client_id']) . '&ticket_id=' . intval($row['ticket_id']) . '">' . escapeHtml($row['ticket_ref']) . '</a>' : escapeHtml($row['ticket_ref']) ?></td>
                    <td><?= escapeHtml((string) $row['client_name']) ?></td>
                    <td class="small"><?= escapeHtml($row['email']) ?></td>
                    <td><?= escapeHtml($row['tech_name']) ?></td>
                    <td><?= $row['status'] === 'sent' ? '<span class="badge text-bg-success">Sent</span>'
                        : '<span class="badge text-bg-' . ($row['status'] === 'failed' ? 'danger' : 'secondary') . '" title="' . escapeHtml((string) $row['error']) . '">' . ucfirst(escapeHtml($row['status'])) . '</span>' ?></td>
                    <td><?= $row['temperature'] ? mitCtBadge($row['temperature']) . ($row['comment'] ? ' <span class="small">' . escapeHtml(mb_substr($row['comment'], 0, 80)) . '</span>' : '') : '<span class="text-muted small">-</span>' ?></td>
                </tr>
            <?php } ?>
            <?php if (!intval($counts['total'])) { ?>
                <tr><td colspan="7" class="text-center text-muted py-3">No surveys yet.</td></tr>
            <?php } ?>
            </tbody>
        </table>
    </div>
    <?php if ((string) mitSetting('ct_last_sample', '') !== '') { ?>
        <div class="card-footer">
            <details class="small"><summary class="text-muted">What Customer Thermometer last sent back (for checking field names)</summary>
                <pre class="mt-2 mb-0" style="max-height: 20rem; white-space: pre-wrap"><?= escapeHtml((string) mitSetting('ct_last_sample', '')) ?></pre></details>
        </div>
    <?php } ?>
</div>

<?php
require_once "../includes/footer.php";
