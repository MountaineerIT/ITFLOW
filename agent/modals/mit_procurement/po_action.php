<?php

/*
 * Mountaineer IT - the smaller forms on a purchase order (agent/mit_purchase_order.php):
 * ?action= approve | reject | ordered | email | tracking | details | bill. Handlers in agent/post/mit_procurement.php.
 */

require_once '../../../includes/modal_header.php';

mitModuleRequire('procurement', true);

$action = (string) ($_GET['action'] ?? '');
$levels = ['approve' => 3, 'reject' => 3, 'ordered' => 2, 'email' => 2, 'tracking' => 2, 'details' => 2, 'bill' => 2];
if (!isset($levels[$action])) {
    exit(json_encode(['error' => 'Unknown action.']));
}
enforceUserPermission('module_procurement', $levels[$action]);
if (($action === 'approve' || $action === 'reject') && ($po_check = mitPoRow($_GET['po_id'] ?? 0)) && !mitPoMayApprove($po_check)) {
    exit(json_encode(['error' => 'Someone else approves a PO you made.']));
}
if ($action === 'bill') {
    enforceUserPermission('module_financial', 2);
}
$po = mitPoRow($_GET['po_id'] ?? 0);
if (!$po || !mitPoAccessible($po['po_id'])) {
    exit(json_encode(['error' => 'That purchase order does not exist, or it is for a client you cannot see.']));
}
$po_id = intval($po['po_id']);
$reference = escapeHtml(mitPoReference($po));
$titles = [
    'approve' => ['fa-check', "Approve $reference"],
    'reject' => ['fa-undo', "Send $reference back"],
    'ordered' => ['fa-shopping-cart', "Mark $reference ordered"],
    'email' => ['fa-paper-plane', "Email $reference to the vendor"],
    'tracking' => ['fa-shipping-fast', "Add tracking to $reference"],
    'details' => ['fa-pen', "$reference details"],
    'bill' => ['fa-file-invoice', "Record the vendor bill for $reference"],
];
[$icon, $title] = $titles[$action];

ob_start();

?>

<div class="modal-header bg-dark">
    <h5 class="modal-title"><i class="fas fa-fw <?= $icon ?> me-2"></i><?= $title ?></h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="po_id" value="<?= $po_id ?>">
    <div class="modal-body">

        <?php if ($action === 'approve' || $action === 'reject') { ?>
            <p class="mb-3"><?= escapeHtml($po['vendor_name']) ?> · <strong><?= mitMoney($po['total']) ?></strong> · from <?= escapeHtml($po['created_by_name'] ?: 'someone') ?></p>
            <input type="hidden" name="decision" value="<?= $action ?>">
            <input type="hidden" name="total" value="<?= escapeHtml((string) $po['total']) ?>"><?php // what the approver saw - refused if it changed since ?>
            <label class="form-label"><?= $action === 'approve' ? 'Note (optional)' : 'Why - they see this on the PO' ?></label>
            <input type="text" class="form-control" name="note" maxlength="255" <?= $action === 'reject' ? 'required' : '' ?>>

        <?php } elseif ($action === 'ordered') { ?>
            <div class="row g-2">
                <div class="col-sm-5">
                    <label class="form-label">Order date</label>
                    <input type="date" class="form-control" name="order_date" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="col-sm-7">
                    <label class="form-label">Vendor's order # <span class="text-muted small">(can add later)</span></label>
                    <input type="text" class="form-control" name="vendor_order_number" maxlength="100" value="<?= escapeHtml($po['vendor_order_number']) ?>">
                </div>
            </div>

        <?php } elseif ($action === 'email') {
            $to = $po['vendor_email'] ?: $po['vendor_contact_email']; ?>
            <label class="form-label">Send to</label>
            <input type="email" class="form-control" name="email" value="<?= escapeHtml($to) ?>" required>
            <div class="form-text">The PO goes as a PDF, asking them to quote <?= $reference ?> on their invoice and packing slip.</div>

        <?php } elseif ($action === 'tracking') { ?>
            <div class="row g-2 mb-3">
                <div class="col-sm-4">
                    <label class="form-label">Carrier</label>
                    <select class="form-select" name="carrier">
                        <?php foreach (MIT_CARRIERS as $key => [$label]) { ?>
                            <option value="<?= $key ?>"><?= escapeHtml($label) ?></option>
                        <?php } ?>
                    </select>
                </div>
                <div class="col-sm-8">
                    <label class="form-label">Tracking number</label>
                    <input type="text" class="form-control font-monospace" name="tracking_number" maxlength="100" required>
                </div>
            </div>
            <div class="row g-2">
                <div class="col-sm-8">
                    <label class="form-label">What is in it <span class="text-muted small">(optional)</span></label>
                    <input type="text" class="form-control" name="note" maxlength="255" placeholder="e.g. 2 boxes - the PCs and the switch">
                </div>
                <div class="col-sm-4">
                    <label class="form-label">Expected</label>
                    <input type="date" class="form-control" name="eta">
                </div>
            </div>

        <?php } elseif ($action === 'details') {
            $vendors = mitProcVendorRows(); ?>
            <?php if ($po['status'] === 'draft') { ?>
                <div class="mb-3">
                    <label class="form-label">Vendor</label>
                    <select class="form-select select2" name="vendor_id">
                        <?php foreach ($vendors as $vendor) { ?>
                            <option value="<?= intval($vendor['vendor_id']) ?>" <?= intval($vendor['vendor_id']) === intval($po['vendor_id']) ? 'selected' : '' ?>><?= escapeHtml($vendor['vendor_name']) ?></option>
                        <?php } ?>
                    </select>
                </div>
            <?php } ?>
            <div class="row g-2 mb-3">
                <div class="col-sm-7">
                    <label class="form-label">Vendor's order #</label>
                    <input type="text" class="form-control" name="vendor_order_number" maxlength="100" value="<?= escapeHtml($po['vendor_order_number']) ?>">
                </div>
                <div class="col-sm-5">
                    <label class="form-label">Order date</label>
                    <input type="date" class="form-control" name="order_date" value="<?= escapeHtml($po['order_date']) ?>">
                </div>
            </div>
            <div class="mb-3" data-mit-po-ship>
                <label class="form-label">Ship to</label>
                <?php
                $ship_offices = function_exists('mitOfficesAll') && mitOfficesReady() ? mitOfficesAll() : [];
                $ship_current = function_exists('mitOfficeFromShipTo') ? mitOfficeFromShipTo($po['ship_to']) : null;
                if (count($ship_offices) > 1) { ?>
                    <select class="form-select mb-2" name="ship_to_office" data-mit-po-ship-office>
                        <?php foreach ($ship_offices as $ship_id => $ship_office) { ?>
                            <option value="<?= $ship_id ?>" data-address="<?= escapeHtml(mitOfficeShipTo($ship_id)) ?>" <?= $ship_current === $ship_id ? 'selected' : '' ?>><?= escapeHtml($ship_office['name']) ?></option>
                        <?php } ?>
                        <option value="other" <?= $ship_current === null ? 'selected' : '' ?>>Somewhere else - typed below</option>
                    </select>
                <?php } ?>
                <textarea class="form-control" name="ship_to_address" rows="3" data-mit-po-ship-address><?= escapeHtml($po['ship_to_address']) ?></textarea>
                <div class="form-text"><?= count($ship_offices) > 1 ? 'Pick an office, or type a client\'s site to have it drop-shipped.' : 'Our office by default - type a client\'s site to have it drop-shipped.' ?></div>
                <script>
                    (function () {
                        var box = document.currentScript.closest('[data-mit-po-ship]');
                        var pick = box.querySelector('[data-mit-po-ship-office]'), text = box.querySelector('[data-mit-po-ship-address]');
                        if (!pick) return;
                        pick.addEventListener('change', function () {
                            var option = pick.options[pick.selectedIndex];
                            if (option.dataset.address !== undefined) text.value = option.dataset.address;
                            else text.focus();
                        });
                        text.addEventListener('input', function () {
                            var option = pick.options[pick.selectedIndex];
                            if (option.dataset.address !== undefined && option.dataset.address !== text.value) pick.value = 'other';
                        });
                    })();
                </script>
            </div>
            <div>
                <label class="form-label">Note to the vendor <span class="text-muted small">(on the PDF)</span></label>
                <textarea class="form-control" name="note" rows="2"><?= escapeHtml($po['note']) ?></textarea>
            </div>
        <?php } elseif ($action === 'bill') {
            $categories = mysqli_fetch_all(mysqli_query($mysqli, "SELECT category_id, category_name FROM categories WHERE category_type = 'Expense' AND category_archived_at IS NULL ORDER BY category_name"), MYSQLI_ASSOC);
            $accounts = mysqli_fetch_all(mysqli_query($mysqli, "SELECT account_id, account_name FROM accounts WHERE account_archived_at IS NULL ORDER BY account_name"), MYSQLI_ASSOC); ?>
            <?php if (mitPoExpense($po)) { ?>
                <div class="alert alert-info mb-0">This PO's vendor bill is already recorded.</div>
            <?php } else { ?>
                <p class="text-muted small">Makes an expense in Finance > Expenses for <?= escapeHtml($po['vendor_name']) ?>, linked to this PO.</p>
                <div class="row g-2 mb-3">
                    <div class="col-sm-6">
                        <label class="form-label">Amount</label>
                        <input type="text" inputmode="decimal" class="form-control" name="amount" value="<?= number_format((float) $po['total'], 2, '.', '') ?>" required>
                        <div class="form-text">The PO total - change it to match their bill (shipping, tax).</div>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label">Bill date</label>
                        <input type="date" class="form-control" name="date" value="<?= date('Y-m-d') ?>" required>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Their invoice #</label>
                    <input type="text" class="form-control" name="reference" maxlength="200" placeholder="From their bill - the PO number if left empty">
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-sm-6">
                        <label class="form-label">Expense category</label>
                        <select class="form-select" name="category" required>
                            <?php foreach ($categories as $category) { ?>
                                <option value="<?= intval($category['category_id']) ?>"><?= escapeHtml($category['category_name']) ?></option>
                            <?php } ?>
                        </select>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label">Paid from</label>
                        <select class="form-select" name="account" required>
                            <option value="">- Pick the account -</option>
                            <?php foreach ($accounts as $account) { ?>
                                <option value="<?= intval($account['account_id']) ?>"><?= escapeHtml($account['account_name']) ?></option>
                            <?php } ?>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="form-label">Description</label>
                    <input type="text" class="form-control" name="description" maxlength="255" value="<?= escapeHtml(mitPoReference($po) . ' - ' . $po['vendor_name']) ?>">
                </div>
            <?php } ?>
        <?php } ?>

    </div>
    <div class="modal-footer">
        <?php
        $buttons = [
            'approve' => ['mit_po_decide', 'btn-success', 'Approve'],
            'reject' => ['mit_po_decide', 'btn-danger', 'Send back'],
            'ordered' => ['mit_po_ordered', 'btn-primary', 'Mark ordered'],
            'email' => ['mit_po_email', 'btn-primary', 'Send'],
            'tracking' => ['mit_po_tracking_add', 'btn-primary', 'Add'],
            'details' => ['mit_po_details', 'btn-primary', 'Save'],
            'bill' => ['mit_po_bill', 'btn-primary', 'Record expense'],
        ];
        [$name, $class, $label] = $buttons[$action]; ?>
        <button type="submit" name="<?= $name ?>" class="btn <?= $class ?> text-bold"><i class="fas fa-check me-2"></i><?= $label ?></button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i>Cancel</button>
    </div>
</form>

<?php
require_once '../../../includes/modal_footer.php';
