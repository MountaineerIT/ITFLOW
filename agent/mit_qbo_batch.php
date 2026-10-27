<?php

/*
 * Mountaineer IT - one QuickBooks batch (functions/mit_qbo_billing.php): its invoices and how each went, Send (waiting and
 * failed ones), taking an invoice out (one in QuickBooks: void it there, or leave it there), renaming, deleting an unsent batch.
 */

require_once "includes/inc_all.php";

enforceUserPermission('module_sales');

if (!mitQboReady()) {
    echo '<div class="alert alert-warning">QuickBooks Online needs the database update - Admin > Update.</div>';
    require_once "../includes/footer.php";
    exit;
}

$batch_id = intval($_GET['batch_id'] ?? 0);
$batch = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT b.*, u.user_name AS created_name, s.user_name AS sent_name FROM mit_qbo_batches b
    LEFT JOIN users u ON u.user_id = b.created_by LEFT JOIN users s ON s.user_id = b.sent_by WHERE b.batch_id = $batch_id"));
if (!$batch) {
    echo '<div class="alert alert-warning">That batch does not exist. <a href="/agent/mit_qbo_batches.php">Back to the batches</a></div>';
    require_once "../includes/footer.php";
    exit;
}
$can = mitQboCan(2);
$connected = mitQboConnected();
$csrf = $_SESSION['csrf_token'];
// Invoices of clients the agent can't see are counted but not listed
$entries = mysqli_fetch_all(mysqli_query($mysqli, "SELECT e.*, invoice_prefix, invoice_number, invoice_status, invoice_currency_code, client_name, r.user_name AS removed_name
    FROM mit_qbo_batch_invoices e JOIN invoices ON invoices.invoice_id = e.invoice_id JOIN clients ON client_id = invoice_client_id
    LEFT JOIN users r ON r.user_id = e.removed_by
    WHERE e.batch_id = $batch_id" . clientScopeSql('invoices.invoice_client_id') . " ORDER BY e.entry_status = 'removed', client_name, invoice_number"), MYSQLI_ASSOC);
$hidden = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM mit_qbo_batch_invoices WHERE batch_id = $batch_id"))[0]) - count($entries);
$live = array_filter($entries, fn($e) => $e['entry_status'] !== 'removed');
$removed = array_filter($entries, fn($e) => $e['entry_status'] === 'removed');
$waiting = count(array_filter($live, fn($e) => in_array($e['entry_status'], ['queued', 'failed'], true)));
$any_sent = (bool) array_filter($live, fn($e) => $e['entry_status'] === 'sent');
[$status_label, $status_class] = MIT_QBO_BATCH_STATUSES[$batch['batch_status']] ?? [$batch['batch_status'], 'text-bg-secondary'];
$total = array_sum(array_column($live, 'amount'));

?>

<div class="card">
    <div class="card-header bg-dark py-2 d-flex flex-wrap align-items-center gap-2">
        <h3 class="card-title mt-2 me-auto"><i class="fas fa-fw fa-layer-group me-2"></i><?= escapeHtml($batch['batch_name']) ?>
            <span class="badge <?= $status_class ?> ms-2" data-mit-batch-status><?= $status_label ?></span></h3>
        <a href="/agent/mit_qbo_batches.php" class="btn btn-light btn-sm"><i class="fas fa-arrow-left me-2"></i>Batches</a>
        <?php if ($can && $waiting && $connected) { ?>
            <form action="post.php" method="post" class="d-inline">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="batch_id" value="<?= $batch_id ?>">
                <button type="submit" name="mit_qbo_batch_send" class="btn btn-success btn-sm confirm-link"><i class="fas fa-paper-plane me-2"></i>Send <?= $waiting ?> to QuickBooks</button>
            </form>
        <?php } ?>
    </div>
    <div class="card-body">
        <div class="d-flex flex-wrap gap-4 mb-3 small">
            <div><span class="text-muted">Invoices</span><div class="fs-5 fw-semibold"><?= count($live) ?><?= $hidden > 0 ? " <span class=\"fs-6 text-muted\">+ $hidden of clients you can't see</span>" : '' ?></div></div>
            <div><span class="text-muted">Total</span><div class="fs-5 fw-semibold"><?= mitMoney($total) ?></div></div>
            <div><span class="text-muted">Emails</span><div class="fs-6"><?= intval($batch['batch_email']) ? 'QuickBooks emails each invoice' : 'Not emailed' ?></div></div>
            <div><span class="text-muted">Made</span><div class="fs-6"><?= escapeHtml(date('M j, Y g:i A', strtotime($batch['created_at']))) ?> · <?= escapeHtml((string) $batch['created_name']) ?></div></div>
            <?php if ($batch['sent_at']) { ?>
                <div><span class="text-muted">Last sent</span><div class="fs-6"><?= escapeHtml(date('M j, Y g:i A', strtotime($batch['sent_at']))) ?> · <?= escapeHtml((string) $batch['sent_name']) ?></div></div>
            <?php } ?>
            <?php if ($can && !$any_sent) { ?>
                <div class="ms-auto d-flex gap-2 align-items-start">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="collapse" data-bs-target="#mitBatchEdit"><i class="fas fa-edit me-1"></i>Edit</button>
                    <form action="post.php" method="post">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <input type="hidden" name="batch_id" value="<?= $batch_id ?>">
                        <button type="submit" name="mit_qbo_batch_delete" class="btn btn-outline-danger btn-sm confirm-link"><i class="fas fa-trash me-1"></i>Delete batch</button>
                    </form>
                </div>
            <?php } ?>
        </div>
        <?php if ($can && !$any_sent) { ?>
            <div class="collapse mb-3" id="mitBatchEdit">
                <form action="post.php" method="post" class="row g-2 align-items-end border rounded p-2 bg-body-tertiary" style="max-width: 44rem">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="batch_id" value="<?= $batch_id ?>">
                    <div class="col-md-6"><label class="form-label small mb-1">Name</label>
                        <input type="text" class="form-control form-control-sm" name="batch_name" maxlength="200" value="<?= escapeHtml($batch['batch_name']) ?>" required></div>
                    <div class="col-md-4"><div class="form-check">
                        <input class="form-check-input" type="checkbox" name="batch_email" value="1" id="mitBatchEditEmail" <?= intval($batch['batch_email']) ? 'checked' : '' ?>>
                        <label class="form-check-label small" for="mitBatchEditEmail">QuickBooks emails them</label></div></div>
                    <div class="col-md-2"><button type="submit" name="mit_qbo_batch_edit" class="btn btn-primary btn-sm w-100">Save</button></div>
                </form>
            </div>
        <?php } ?>
        <?php if (!$connected && $waiting) { ?>
            <div class="alert alert-info">Connect QuickBooks Online (Admin > Integrations > QuickBooks Online) to send this batch.</div>
        <?php } ?>

        <div class="table-responsive">
            <table class="table table-sm align-middle" data-mit-batch-entries>
                <thead><tr><th>Invoice</th><th>Client</th><th class="text-end">Amount</th><th>QuickBooks</th><th class="text-end">Balance there</th><th>Notes</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($live as $entry) {
                    [$label, $class] = MIT_QBO_ENTRY_STATUSES[$entry['entry_status']] ?? [$entry['entry_status'], 'text-bg-secondary'];
                    $entry_id = intval($entry['entry_id']); ?>
                    <tr data-entry="<?= $entry_id ?>">
                        <td><a href="/agent/invoice.php?invoice_id=<?= intval($entry['invoice_id']) ?>"><?= escapeHtml($entry['invoice_prefix'] . $entry['invoice_number']) ?></a>
                            <div class="small text-muted"><?= escapeHtml($entry['invoice_status']) ?></div></td>
                        <td><?= escapeHtml($entry['client_name']) ?></td>
                        <td class="text-end"><?= numfmt_format_currency($currency_format, floatval($entry['amount']), $entry['invoice_currency_code']) ?></td>
                        <td><span class="badge <?= $class ?>"><?= $label ?></span>
                            <?php if ($entry['qbo_doc_number']) { ?><div class="small text-muted">No. <?= escapeHtml($entry['qbo_doc_number']) ?><?= $entry['emailed_at'] ? ' · emailed' : '' ?></div><?php } ?></td>
                        <td class="text-end"><?php if ($entry['qbo_balance'] !== null) {
                                echo numfmt_format_currency($currency_format, floatval($entry['qbo_balance']), $entry['invoice_currency_code']);
                                if ($entry['qbo_total'] !== null && abs(floatval($entry['qbo_total']) - floatval($entry['amount'])) > 0.005) { ?>
                                    <div class="small text-muted">of <?= numfmt_format_currency($currency_format, floatval($entry['qbo_total']), $entry['invoice_currency_code']) ?></div>
                                <?php }
                            } ?></td>
                        <td class="small" style="max-width: 26rem">
                            <?php if ($entry['error']) { ?><div class="text-danger"><?= escapeHtml($entry['error']) ?></div><?php } ?>
                            <?php if ($entry['warning']) { ?><div class="text-warning-emphasis"><?= escapeHtml($entry['warning']) ?></div><?php } ?>
                        </td>
                        <td class="text-end text-nowrap">
                            <?php if ($can) { ?>
                                <form action="post.php" method="post" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                    <input type="hidden" name="entry_id" value="<?= $entry_id ?>">
                                    <?php if ($entry['entry_status'] === 'sent') { ?>
                                        <div class="dropdown">
                                            <button type="button" class="btn btn-outline-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown">Take out</button>
                                            <div class="dropdown-menu dropdown-menu-end">
                                                <button type="submit" name="mit_qbo_entry_remove" value="void" class="dropdown-item confirm-link">Void it in QuickBooks and take it out</button>
                                                <button type="submit" name="mit_qbo_entry_remove" value="unlink" class="dropdown-item confirm-link">Take it out, leave it in QuickBooks</button>
                                                <div class="dropdown-item-text small text-muted" style="max-width: 20rem">Either way the ITFlow invoice unlocks, goes back to Draft and needs approving again.</div>
                                            </div>
                                        </div>
                                    <?php } else { ?>
                                        <button type="submit" name="mit_qbo_entry_remove" value="out" class="btn btn-outline-secondary btn-sm">Take out</button>
                                    <?php } ?>
                                </form>
                            <?php } ?>
                        </td>
                    </tr>
                <?php } ?>
                <?php if (!$live) { ?><tr><td colspan="7" class="text-muted py-3 text-center">No invoices in this batch.</td></tr><?php } ?>
                </tbody>
            </table>
        </div>

        <?php if ($removed) { ?>
            <details class="mt-2">
                <summary class="small text-muted"><?= count($removed) ?> taken out</summary>
                <table class="table table-sm small mt-2">
                    <tbody>
                    <?php foreach ($removed as $entry) { ?>
                        <tr class="text-muted">
                            <td><a href="/agent/invoice.php?invoice_id=<?= intval($entry['invoice_id']) ?>"><?= escapeHtml($entry['invoice_prefix'] . $entry['invoice_number']) ?></a></td>
                            <td><?= escapeHtml($entry['client_name']) ?></td>
                            <td><?= ['voided' => 'Voided in QuickBooks', 'unlinked' => 'Left in QuickBooks', 'removed' => 'Taken out before sending'][$entry['removed_how']] ?? escapeHtml((string) $entry['removed_how']) ?></td>
                            <td><?= $entry['removed_at'] ? escapeHtml(date('M j, Y g:i A', strtotime($entry['removed_at']))) : '' ?> · <?= escapeHtml((string) $entry['removed_name']) ?></td>
                        </tr>
                    <?php } ?>
                    </tbody>
                </table>
            </details>
        <?php } ?>
    </div>
</div>

<?php
require_once "../includes/footer.php";
