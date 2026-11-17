<?php
/*
 * Mountaineer IT - a bulk action on the invoices ticked on the invoices list (functions/mit_invoice_bulk.php): what it does, the invoices it
 * goes to, the ones left alone and why, and for Mark Sent / Mark Paid the details ITFlow's own windows ask. ?action=&invoice_ids[]=
 */

require_once '../../../includes/modal_header.php';

$action = (string) ($_GET['action'] ?? '');
$actions = mitInvoiceBulkActions();
if (!isset($actions[$action])) {
    exit(json_encode(['error' => 'Unknown bulk action.']));
}
if (!mitInvoiceBulkCan($action)) {
    enforceUserPermission('module_sales', $action === 'delete' ? 3 : 2);
    if ($action === 'pay') {
        enforceBillingMoney('take');
    }
    exit(json_encode(['error' => 'That bulk action is not available.']));
}
[$label, $icon, $about, $button] = $actions[$action];
$plan = mitInvoiceBulkPlan($action, (array) ($_GET['invoice_ids'] ?? []));
$count = count($plan['do']);
$owed = [];
foreach ($plan['do'] as $row) {
    $owed[$row['invoice_currency_code']] = ($owed[$row['invoice_currency_code']] ?? 0) + $row['balance_cents'];
}

ob_start();

?>
<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fas fa-fw <?= $icon ?> me-2"></i><?= escapeHtml($label) ?> <span class="fw-normal text-white-50 ms-1"><?= $count ?> invoice<?= $count === 1 ? '' : 's' ?></span></h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<form action="post.php" method="post" autocomplete="off" data-mit-invoice-bulk-form="<?= $action ?>">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="mit_bulk_action" value="<?= $action ?>">
    <?php foreach ($plan['do'] as $row) { ?><input type="hidden" name="invoice_ids[]" value="<?= intval($row['invoice_id']) ?>"><?php } ?>

    <div class="modal-body">
        <p class="small text-body-secondary"><?= escapeHtml($about) ?></p>
        <?php if ($count) { ?>
            <div class="list-group list-group-flush border rounded mb-3" style="max-height: 12rem; overflow-y: auto;" data-mit-bulk-do>
                <?php foreach ($plan['do'] as $row) { ?>
                    <div class="list-group-item d-flex align-items-center gap-2 py-1" data-invoice-id="<?= intval($row['invoice_id']) ?>">
                        <span class="fw-semibold"><?= escapeHtml($row['invoice_prefix'] . $row['invoice_number']) ?></span>
                        <span class="small text-body-secondary text-truncate flex-grow-1"><?= escapeHtml($row['client_name']) ?><?= $row['invoice_scope'] !== '' ? ' - ' . escapeHtml($row['invoice_scope']) : '' ?></span>
                        <span class="badge text-bg-light"><?= escapeHtml($row['invoice_status']) ?></span>
                        <?php if ($action === 'pay') { ?><span class="itflow-money small fw-semibold"><?= escapeHtml(billingMoney($row['balance_cents'] / 100, $row['invoice_currency_code'])) ?></span><?php } ?>
                    </div>
                <?php } ?>
            </div>
            <?php if ($action === 'pay') { ?>
                <p class="fw-semibold" data-mit-bulk-total>Total: <?= escapeHtml(implode(', ', array_map(function ($currency, $cents) { return billingMoney($cents / 100, $currency); }, array_keys($owed), $owed))) ?></p>
            <?php } ?>
        <?php } else { ?>
            <div class="alert alert-warning" data-mit-bulk-none>None of the invoices picked can be <?= escapeHtml($actions[$action][4]) ?>.</div>
        <?php } ?>

        <?php if ($plan['skip']) { ?>
            <details class="mb-3" data-mit-bulk-skip <?= $count ? '' : 'open' ?>>
                <summary class="small"><?= count($plan['skip']) ?> left alone</summary>
                <ul class="small mb-0 mt-1" style="max-height: 9rem; overflow-y: auto;">
                    <?php foreach ($plan['skip'] as [$row, $why]) { ?>
                        <li><?= escapeHtml($row['invoice_prefix'] . $row['invoice_number']) ?> - <?= escapeHtml($why) ?></li>
                    <?php } ?>
                </ul>
            </details>
        <?php } ?>

        <?php if ($count && $action === 'sent') { ?>
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label">How were they sent? <strong class="text-danger">*</strong></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fa fa-fw fa-paper-plane"></i></span>
                        <select class="form-select" name="sent_method" required>
                            <?php foreach (getSentMethods() as $sent_method) { ?>
                                <option value="<?= escapeHtml($sent_method) ?>"><?= escapeHtml($sent_method) ?></option>
                            <?php } ?>
                        </select>
                    </div>
                </div>
                <div class="col-12">
                    <label class="form-label">Note</label>
                    <textarea class="form-control" name="note" rows="2" maxlength="500" placeholder="Goes in each invoice's history"></textarea>
                </div>
            </div>
        <?php } elseif ($count && $action === 'pay') {
            $pay_methods = paymentMethods('in'); ?>
            <div class="row g-3">
                <div class="col-sm-6">
                    <label class="form-label">Date <strong class="text-danger">*</strong></label>
                    <input type="date" class="form-control" name="date" max="2999-12-31" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="col-sm-6">
                    <label class="form-label">Method <strong class="text-danger">*</strong></label>
                    <select class="form-select" name="payment_method" required>
                        <option value="">- How it was paid -</option>
                        <?php foreach ($pay_methods as $method) { ?>
                            <option <?= $method === $config_default_payment_method ? 'selected' : '' ?>><?= escapeHtml($method) ?></option>
                        <?php } ?>
                    </select>
                </div>
                <div class="col-sm-6">
                    <label class="form-label">Into account <strong class="text-danger">*</strong></label>
                    <select class="form-select" name="account" required>
                        <option value="">- Select an account -</option>
                        <?php foreach (paymentAccounts() as $account_id => $account) {
                            if ($account['account_archived_at']) { continue; } ?>
                            <option value="<?= $account_id ?>" <?= intval($config_default_payment_account) === $account_id ? 'selected' : '' ?>><?= escapeHtml($account['account_name']) ?></option>
                        <?php } ?>
                    </select>
                </div>
                <div class="col-sm-6">
                    <label class="form-label">Reference</label>
                    <input type="text" class="form-control" name="reference" maxlength="200" placeholder="Check number, transaction ID">
                </div>
                <?php if (!empty($config_smtp_provider)) { ?>
                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input type="checkbox" class="form-check-input" role="switch" id="mitBulkReceipt" name="email_receipt" value="1">
                            <label class="form-check-label" for="mitBulkReceipt">Email each client a receipt (one per client, listing its invoices)</label>
                        </div>
                    </div>
                <?php } ?>
            </div>
        <?php } elseif ($count && $action === 'delete') { ?>
            <div class="alert alert-danger mb-0">This can't be undone. Cancelling keeps them on record instead.</div>
        <?php } ?>
    </div>
    <div class="modal-footer">
        <?php if ($count) { ?>
            <button type="submit" name="mit_invoice_bulk" class="btn <?= $button ?> text-bold"><i class="fas <?= $icon ?> me-2"></i><?= escapeHtml($label) ?> <?= $count ?></button>
        <?php } ?>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i>Close</button>
    </div>
</form>

<?php
require_once '../../../includes/modal_footer.php';
