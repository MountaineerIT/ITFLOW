<?php

/*
 * Mountaineer IT - Billing > Invoice Approvals (functions/mit_qbo_billing.php): invoices that need approving before they can
 * go to QuickBooks Online - never approved, or changed since. Tick and approve; approved ones wait in Billing > QuickBooks
 * Batches. ?show=awaiting (default) | changed | all | manual (marked "Already in QuickBooks" - entered there by hand, with Undo),
 * ?client= one client.
 */

require_once "includes/inc_all.php";

enforceUserPermission('module_sales');

if (!mitQboReady()) {
    echo '<div class="alert alert-warning">QuickBooks Online needs the database update - Admin > Update.</div>';
    require_once "../includes/footer.php";
    exit;
}

$show = in_array($_GET['show'] ?? '', ['awaiting', 'changed', 'all', 'manual'], true) ? $_GET['show'] : 'awaiting';
$client_filter = intval($_GET['client'] ?? 0);
$can_approve = mitQboCan(2);
$csrf = $_SESSION['csrf_token'];

// Invoices that could go to QuickBooks and are not in a batch; their approval decides which list they are on - those marked
// "Already in QuickBooks" have their own list
$rows = mysqli_fetch_all(mysqli_query($mysqli, "SELECT invoices.invoice_id, invoice_prefix, invoice_number, invoice_scope, invoice_date, invoice_due, invoice_amount,
        invoice_status, invoice_currency_code, client_id, client_name
    FROM invoices JOIN clients ON client_id = invoice_client_id
    WHERE " . MIT_QBO_BATCHABLE_SQL . ($client_filter ? " AND invoice_client_id = $client_filter" : '') . clientScopeSql('invoices.invoice_client_id') . "
        AND NOT EXISTS (SELECT 1 FROM mit_qbo_batch_invoices e WHERE e.invoice_id = invoices.invoice_id AND e.entry_status != 'removed')
    ORDER BY invoice_date, invoice_number LIMIT 1000"), MYSQLI_ASSOC);
$states = mitInvoiceApprovalStates(array_column($rows, 'invoice_id'));
$manual = mitQboManualRows(array_column($rows, 'invoice_id'));
$counts = ['awaiting' => 0, 'changed' => 0, 'approved' => 0, 'manual' => 0];
$list = [];
foreach ($rows as $row) {
    $id = intval($row['invoice_id']);
    $state = isset($manual[$id]) ? 'manual' : ($states[$id] ?? 'none');
    $counts[$state === 'none' ? 'awaiting' : $state]++;
    if (($show === 'all' && $state !== 'manual') || ($show === 'awaiting' && $state === 'none') || ($show === $state && in_array($state, ['changed', 'manual'], true))) {
        $row['approval'] = $state;
        $row['manual'] = $manual[$id] ?? null;
        $list[] = $row;
    }
}
$clients = mysqli_fetch_all(mysqli_query($mysqli, "SELECT DISTINCT client_id, client_name FROM invoices JOIN clients ON client_id = invoice_client_id
    WHERE " . MIT_QBO_BATCHABLE_SQL . clientScopeSql('invoices.invoice_client_id') . " ORDER BY client_name"), MYSQLI_ASSOC);
$badges = [
    'none' => '<span class="badge text-bg-secondary">Awaiting approval</span>',
    'changed' => '<span class="badge text-bg-warning">Changed since approval</span>',
    'approved' => '<span class="badge text-bg-primary">Approved</span>',
    'manual' => '<span class="badge text-bg-success">Already in QuickBooks</span>',
];

?>

<div class="card">
    <div class="card-header bg-dark py-2 d-flex align-items-center">
        <h3 class="card-title mt-2 me-auto"><i class="fas fa-fw fa-clipboard-check me-2"></i>Invoice approvals</h3>
        <a href="/agent/mit_qbo_batches.php" class="btn btn-light btn-sm"><i class="fas fa-layer-group me-2"></i>QuickBooks batches
            <?php if ($counts['approved']) { ?><span class="badge text-bg-primary ms-1"><?= $counts['approved'] ?> ready</span><?php } ?></a>
    </div>
    <div class="card-body">
        <?php if (!mitQboConnected()) { ?>
            <div class="alert alert-info">QuickBooks Online is not connected (Admin > Integrations > QuickBooks Online) - invoices can be approved, but
                batches can't be sent yet.</div>
        <?php } ?>
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
            <div class="btn-group">
                <?php foreach (['awaiting' => 'Awaiting approval', 'changed' => 'Changed since approval', 'all' => 'All open', 'manual' => 'Already in QuickBooks'] as $key => $label) { ?>
                    <a class="btn btn-sm <?= $show === $key ? 'btn-primary' : 'btn-outline-secondary' ?>" href="?show=<?= $key ?>&client=<?= $client_filter ?>"><?= $label ?>
                        <?php if ($key !== 'all' && $counts[$key]) { ?><span class="badge text-bg-light ms-1"><?= $counts[$key] ?></span><?php } ?></a>
                <?php } ?>
            </div>
            <form method="get" class="ms-auto">
                <input type="hidden" name="show" value="<?= $show ?>">
                <select class="form-select form-select-sm" name="client" onchange="this.form.submit()" aria-label="Client">
                    <option value="0">All clients</option>
                    <?php foreach ($clients as $client) { ?>
                        <option value="<?= intval($client['client_id']) ?>" <?= $client_filter === intval($client['client_id']) ? 'selected' : '' ?>><?= escapeHtml($client['client_name']) ?></option>
                    <?php } ?>
                </select>
            </form>
        </div>

        <form action="post.php" method="post" id="mitApproveForm">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle" data-mit-approvals>
                    <thead>
                    <tr>
                        <?php if ($can_approve) { ?><th style="width: 2rem"><input type="checkbox" class="form-check-input" aria-label="All"
                            onclick="document.querySelectorAll('#mitApproveForm input[name=\'invoice_ids[]\']').forEach(c => c.checked = this.checked)"></th><?php } ?>
                        <th>Invoice</th><th>Client</th><th>Date</th><th>Due</th><th class="text-end">Amount</th><th>Status</th><th>QuickBooks</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($list as $row) {
                        $id = intval($row['invoice_id']); ?>
                        <tr>
                            <?php if ($can_approve) { ?><td><input type="checkbox" class="form-check-input" name="invoice_ids[]" value="<?= $id ?>" aria-label="Select"></td><?php } ?>
                            <td><a href="/agent/invoice.php?invoice_id=<?= $id ?>"><?= escapeHtml($row['invoice_prefix'] . $row['invoice_number']) ?></a>
                                <?php if ($row['invoice_scope'] !== '') { ?><div class="small text-muted text-truncate" style="max-width: 20rem"><?= escapeHtml($row['invoice_scope']) ?></div><?php } ?></td>
                            <td><?= escapeHtml($row['client_name']) ?></td>
                            <td><?= escapeHtml($row['invoice_date']) ?></td>
                            <td><?= escapeHtml($row['invoice_due']) ?></td>
                            <td class="text-end"><?= numfmt_format_currency($currency_format, floatval($row['invoice_amount']), $row['invoice_currency_code']) ?></td>
                            <td><?= escapeHtml($row['invoice_status']) ?></td>
                            <td><?= $badges[$row['approval']] ?? '' ?>
                                <?php if ($row['manual']) { ?><div class="small text-muted"><?= escapeHtml((string) ($row['manual']['user_name'] ?? '')) ?> · <?= escapeHtml(date('M j, Y', strtotime($row['manual']['marked_at']))) ?></div><?php } ?></td>
                        </tr>
                    <?php } ?>
                    <?php if (!$list) { ?>
                        <tr><td colspan="8" class="text-muted py-4 text-center">Nothing here<?= $show === 'awaiting' ? ' - every open invoice is approved or batched' : '' ?>.</td></tr>
                    <?php } ?>
                    </tbody>
                </table>
            </div>
            <?php if ($can_approve && $list && $show === 'manual') { ?>
                <button type="submit" name="mit_qbo_unmark_manual_bulk" class="btn btn-outline-secondary" data-mit-qbo-unmark-bulk><i class="fas fa-undo me-2"></i>Not in QuickBooks - put back</button>
                <span class="small text-muted ms-2">Entered in QuickBooks by hand: never sent from here, and their payments are recorded in ITFlow.</span>
            <?php } elseif ($can_approve && $list) { ?>
                <button type="submit" name="mit_invoice_approve_bulk" class="btn btn-primary"><i class="fas fa-check me-2"></i>Approve selected</button>
                <?php if (mitQboManualReady()) { ?>
                    <button type="submit" name="mit_qbo_mark_manual" class="btn btn-outline-success ms-1 confirm-link" data-mit-qbo-mark-bulk data-confirm-title="Already in QuickBooks?"
                        data-confirm-text="For invoices entered in QuickBooks by hand: they leave the approval and batch lists and are never sent from here. Their payments are recorded in ITFlow."
                        data-confirm-button="Mark them" data-confirm-icon="question"><i class="fas fa-check-double me-2"></i>Already in QuickBooks</button>
                <?php } ?>
                <span class="small text-muted ms-2">Approving keeps the invoice as it is now - a later change sends it back here.</span>
            <?php } ?>
        </form>
    </div>
</div>

<?php
require_once "../includes/footer.php";
