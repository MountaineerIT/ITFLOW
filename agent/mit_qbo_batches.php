<?php

/*
 * Mountaineer IT - Billing > QuickBooks Batches (functions/mit_qbo_billing.php): approved invoices waiting for a batch (tick
 * them and add them to a new or open batch, or mark ones entered in QuickBooks by hand as "Already in QuickBooks"), and the
 * batches (open, partly sent, sent) - each opens agent/mit_qbo_batch.php.
 */

require_once "includes/inc_all.php";

enforceUserPermission('module_sales');

if (!mitQboReady()) {
    echo '<div class="alert alert-warning">QuickBooks Online needs the database update - Admin > Update.</div>';
    require_once "../includes/footer.php";
    exit;
}

$can_batch = mitQboCan(2);
$csrf = $_SESSION['csrf_token'];
$approved = mitQboReadyInvoices();
$ready = array_filter($approved, fn($row) => $row['approval'] === 'approved');
$changed = count(array_filter($approved, fn($row) => $row['approval'] === 'changed'));
$open_batches = mysqli_fetch_all(mysqli_query($mysqli, "SELECT batch_id, batch_name FROM mit_qbo_batches WHERE batch_status != 'sent' ORDER BY batch_id DESC"), MYSQLI_ASSOC);
$batches = mysqli_fetch_all(mysqli_query($mysqli, "SELECT b.*, u.user_name AS created_name, s.user_name AS sent_name,
        SUM(e.entry_status != 'removed') AS invoices, SUM(e.entry_status = 'queued') AS queued, SUM(e.entry_status = 'sent') AS sent,
        SUM(e.entry_status = 'failed') AS failed, SUM(IF(e.entry_status != 'removed', e.amount, 0)) AS total
    FROM mit_qbo_batches b LEFT JOIN mit_qbo_batch_invoices e ON e.batch_id = b.batch_id
        AND e.invoice_id IN (SELECT invoice_id FROM invoices WHERE 1 = 1" . clientScopeSql('invoice_client_id') . ")
    LEFT JOIN users u ON u.user_id = b.created_by LEFT JOIN users s ON s.user_id = b.sent_by
    GROUP BY b.batch_id ORDER BY b.batch_id DESC LIMIT 200"), MYSQLI_ASSOC);

?>

<div class="card mb-3">
    <div class="card-header bg-dark py-2 d-flex align-items-center">
        <h3 class="card-title mt-2 me-auto"><i class="fas fa-fw fa-layer-group me-2"></i>QuickBooks batches</h3>
        <a href="/agent/mit_invoice_approvals.php" class="btn btn-light btn-sm"><i class="fas fa-clipboard-check me-2"></i>Invoice approvals</a>
    </div>
    <div class="card-body">
        <?php if (!mitQboConnected()) { ?>
            <div class="alert alert-info">QuickBooks Online is not connected (Admin > Integrations > QuickBooks Online) - batches can be made but not sent.</div>
        <?php } ?>
        <h6 class="mb-2">Approved, waiting for a batch</h6>
        <?php if ($changed) { ?>
            <div class="small text-warning-emphasis mb-2"><i class="fas fa-exclamation-triangle me-1"></i><?= $changed ?> approved invoice<?= $changed === 1 ? ' has' : 's have' ?>
                changed since - <a href="/agent/mit_invoice_approvals.php?show=changed">approve again</a>.</div>
        <?php } ?>
        <form action="post.php" method="post" id="mitBatchAddForm">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle" data-mit-ready>
                    <thead>
                    <tr>
                        <?php if ($can_batch) { ?><th style="width: 2rem"><input type="checkbox" class="form-check-input" aria-label="All" checked
                            onclick="document.querySelectorAll('#mitBatchAddForm input[name=\'invoice_ids[]\']').forEach(c => c.checked = this.checked)"></th><?php } ?>
                        <th>Invoice</th><th>Client</th><th>Date</th><th>Due</th><th class="text-end">Amount</th><th>Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($ready as $id => $row) { ?>
                        <tr>
                            <?php if ($can_batch) { ?><td><input type="checkbox" class="form-check-input" name="invoice_ids[]" value="<?= intval($id) ?>" checked aria-label="Select"></td><?php } ?>
                            <td><a href="/agent/invoice.php?invoice_id=<?= intval($id) ?>"><?= escapeHtml($row['invoice_prefix'] . $row['invoice_number']) ?></a></td>
                            <td><?= escapeHtml($row['client_name']) ?></td>
                            <td><?= escapeHtml($row['invoice_date']) ?></td>
                            <td><?= escapeHtml($row['invoice_due']) ?></td>
                            <td class="text-end"><?= numfmt_format_currency($currency_format, floatval($row['invoice_amount']), $row['invoice_currency_code']) ?></td>
                            <td><?= escapeHtml($row['invoice_status']) ?></td>
                        </tr>
                    <?php } ?>
                    <?php if (!$ready) { ?>
                        <tr><td colspan="7" class="text-muted py-3 text-center">No approved invoices waiting.</td></tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
            <?php if ($can_batch && $ready) { ?>
                <div class="row g-2 align-items-end" style="max-width: 56rem">
                    <div class="col-md-4">
                        <label class="form-label small mb-1">Add to</label>
                        <select class="form-select form-select-sm" name="batch_id" id="mitBatchTarget">
                            <option value="0">A new batch</option>
                            <?php foreach ($open_batches as $batch) { ?>
                                <option value="<?= intval($batch['batch_id']) ?>"><?= escapeHtml($batch['batch_name']) ?></option>
                            <?php } ?>
                        </select>
                    </div>
                    <div class="col-md-4" data-mit-new-batch>
                        <label class="form-label small mb-1">New batch's name</label>
                        <input type="text" class="form-control form-control-sm" name="batch_name" maxlength="200" placeholder="Batch <?= date('M j, Y') ?>">
                    </div>
                    <div class="col-md-2" data-mit-new-batch>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="batch_email" value="1" id="mitBatchEmail" <?= mitQboSettings()['email_default'] ? 'checked' : '' ?>>
                            <label class="form-check-label small" for="mitBatchEmail">QuickBooks emails them</label>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" name="mit_qbo_batch_add" class="btn btn-primary btn-sm w-100"><i class="fas fa-plus me-1"></i>Add to batch</button>
                    </div>
                </div>
                <?php if (mitQboManualReady()) { ?>
                    <div class="mt-2 small text-muted">Entered some of these in QuickBooks by hand?
                        <button type="submit" name="mit_qbo_mark_manual" class="btn btn-outline-success btn-sm ms-1 confirm-link" data-mit-qbo-mark-bulk
                            data-confirm-title="Already in QuickBooks?" data-confirm-button="Mark them" data-confirm-icon="question"
                            data-confirm-text="The ticked invoices leave this list and are never sent from here. Their payments are recorded in ITFlow."><i class="fas fa-check-double me-1"></i>Already in QuickBooks</button>
                        <a href="/agent/mit_invoice_approvals.php?show=manual" class="ms-2">See marked invoices</a></div>
                <?php } ?>
                <script>
                    document.getElementById('mitBatchTarget').addEventListener('change', function () {
                        document.querySelectorAll('[data-mit-new-batch]').forEach(el => el.classList.toggle('d-none', this.value !== '0'));
                    });
                </script>
            <?php } ?>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header py-2"><h3 class="card-title mt-1">Batches</h3></div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0" data-mit-batches>
                <thead><tr><th>Batch</th><th>Status</th><th class="text-end">Invoices</th><th class="text-end">Total</th><th>Made</th><th>Sent</th></tr></thead>
                <tbody>
                <?php foreach ($batches as $batch) {
                    [$label, $class] = MIT_QBO_BATCH_STATUSES[$batch['batch_status']] ?? [$batch['batch_status'], 'text-bg-secondary']; ?>
                    <tr>
                        <td><a href="/agent/mit_qbo_batch.php?batch_id=<?= intval($batch['batch_id']) ?>"><?= escapeHtml($batch['batch_name']) ?></a>
                            <?php if (!intval($batch['batch_email'])) { ?><span class="badge text-bg-light border ms-1">not emailed</span><?php } ?></td>
                        <td><span class="badge <?= $class ?>"><?= $label ?></span>
                            <?php if (intval($batch['failed'])) { ?><span class="badge text-bg-danger ms-1"><?= intval($batch['failed']) ?> failed</span><?php } ?></td>
                        <td class="text-end"><?= intval($batch['invoices']) ?><?php if (intval($batch['sent']) && intval($batch['sent']) < intval($batch['invoices'])) { ?>
                            <span class="small text-muted">(<?= intval($batch['sent']) ?> sent)</span><?php } ?></td>
                        <td class="text-end"><?= mitMoney($batch['total']) ?></td>
                        <td class="small"><?= escapeHtml(date('M j, Y', strtotime($batch['created_at']))) ?> · <?= escapeHtml((string) $batch['created_name']) ?></td>
                        <td class="small"><?= $batch['sent_at'] ? escapeHtml(date('M j, Y g:i A', strtotime($batch['sent_at']))) . ' · ' . escapeHtml((string) $batch['sent_name']) : '' ?></td>
                    </tr>
                <?php } ?>
                <?php if (!$batches) { ?><tr><td colspan="6" class="text-muted py-3 text-center">No batches yet.</td></tr><?php } ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
require_once "../includes/footer.php";
