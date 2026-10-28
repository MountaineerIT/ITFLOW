<?php

/*
 * Mountaineer IT - one purchase order (functions/mit_procurement.php): its lines (editable while a
 * draft), approval, ordering, tracking and what has arrived. Actions post to agent/post/mit_procurement.php;
 * the smaller forms are in agent/modals/mit_procurement/po_action.php.
 */

require_once "includes/inc_all.php";

mitModuleRequire('procurement');

enforceUserPermission('module_procurement');
$po = mitPoRow($_GET['po_id'] ?? 0);
if (!$po || !mitPoAccessible($po['po_id'])) {
    echo '<div class="alert alert-warning">That purchase order does not exist, or it is for a client you cannot see. <a href="mit_purchase_orders.php">Purchase orders</a></div>';
    require_once "../includes/footer.php";
    exit;
}
$po_id = intval($po['po_id']);
$level = mitProcLevel();
$can_see_expense = lookupUserPermission('module_financial') >= 1;
$can_bill = lookupUserPermission('module_financial') >= 2;
$expense = mitPoExpense($po);
$status = $po['status'];
$lines = mitPoLines($po_id);
$tracking = mitPoTracking($po_id);
$history = mysqli_fetch_all(mysqli_query($mysqli, "SELECT h.history_id, h.po_id, h.user_id, h.description, h.created_at, user_name FROM mit_po_history h LEFT JOIN users ON users.user_id = h.user_id WHERE po_id = $po_id ORDER BY history_id DESC LIMIT 50"), MYSQLI_ASSOC);
$received_any = array_sum(array_map(function ($line) {
    return (float) $line['received_qty'];
}, $lines)) > 0;
$qty_total = array_sum(array_column($lines, 'quantity'));
$qty_received = array_sum(array_map(function ($line) {
    return min((float) $line['received_qty'], (float) $line['quantity']);
}, $lines));
$needs_approval = mitPoNeedsApproval($po['total']);
$approval_over = mitProcSettings()['approval_over'];
$is_draft = $status === 'draft';
$csrf = $_SESSION['csrf_token'];
$modal = function ($action, $size = '') use ($po_id) {
    // Only the data- attributes: each link has its own class, with ajax-modal in it (two class attributes - the second is ignored)
    return 'data-modal-url="modals/mit_procurement/po_action.php?po_id=' . $po_id . '&action=' . $action . '"' . ($size ? ' data-modal-size="' . $size . '"' : '');
};

// The steps along the top
$steps = ['draft' => 'Draft', 'pending' => 'Approval', 'approved' => 'Approved', 'ordered' => 'Ordered', 'received' => 'Received'];
$order = ['draft' => 0, 'pending' => 1, 'approved' => 2, 'ordered' => 3, 'partial' => 3, 'received' => 4, 'cancelled' => -1];
$at = $order[$status] ?? 0;

?>

<ol class="breadcrumb d-print-none">
    <li class="breadcrumb-item"><a href="mit_purchase_orders.php">Purchase orders</a></li>
    <li class="breadcrumb-item active"><?= escapeHtml(mitPoReference($po)) ?></li>
</ol>

<div class="card mb-3">
    <div class="card-body">
        <div class="d-flex flex-wrap align-items-center gap-2">
            <h2 class="h3 mb-0 me-2"><?= escapeHtml(mitPoReference($po)) ?> <span class="text-muted fw-normal">· <?= escapeHtml($po['vendor_name'] ?: 'No vendor') ?></span></h2>
            <?= mitPoBadge($status) ?>
            <div class="ms-auto d-flex flex-wrap gap-2">
                <a href="post.php?mit_po_pdf=<?= $po_id ?>" target="_blank" class="btn btn-light border"><i class="fas fa-file-pdf me-2"></i>PDF</a>
                <?php if ($level >= 2 && in_array($status, ['approved', 'ordered', 'partial'], true)) { ?>
                    <a href="#" <?= $modal('email') ?> class="ajax-modal btn btn-light border"><i class="fas fa-paper-plane me-2"></i>Email to vendor</a>
                <?php } ?>
                <?php if ($level >= 2 && $is_draft) { ?>
                    <a href="post.php?mit_po_submit=<?= $po_id ?>&csrf_token=<?= $csrf ?>" class="btn btn-primary <?= $lines ? '' : 'disabled' ?>">
                        <i class="fas fa-paper-plane me-2"></i><?= $needs_approval ? 'Send for approval' : 'Submit' ?>
                    </a>
                <?php } ?>
                <?php if ($status === 'pending' && mitPoMayApprove($po)) { ?>
                    <a href="#" <?= $modal('approve') ?> class="ajax-modal btn btn-success"><i class="fas fa-check me-2"></i>Approve</a>
                    <a href="#" <?= $modal('reject') ?> class="ajax-modal btn btn-outline-danger">Send back</a>
                <?php } ?>
                <?php if ($level >= 2 && $status === 'approved') { ?>
                    <a href="#" <?= $modal('ordered') ?> class="ajax-modal btn btn-primary"><i class="fas fa-shopping-cart me-2"></i>Mark ordered</a>
                <?php } ?>
                <?php if ($level >= 2 && in_array($status, ['ordered', 'partial'], true)) { ?>
                    <a href="mit_receiving.php?po_id=<?= $po_id ?>" class="btn btn-success"><i class="fas fa-truck-loading me-2"></i>Receive items</a>
                <?php } ?>
                <?php if ($level >= 2 && $can_bill && !$expense && in_array($status, ['ordered', 'partial', 'received'], true)) { ?>
                    <a href="#" <?= $modal('bill') ?> class="ajax-modal btn btn-light border"><i class="fas fa-file-invoice me-2"></i>Record vendor bill</a>
                <?php } ?>
                <?php if ($level >= 2 && !in_array($status, ['received', 'cancelled'], true)) { ?>
                    <div class="dropdown">
                        <button class="btn btn-light border" type="button" data-bs-toggle="dropdown" aria-label="More"><i class="fas fa-ellipsis-v"></i></button>
                        <div class="dropdown-menu dropdown-menu-end">
                            <a href="#" class="dropdown-item ajax-modal" <?= $modal('details') ?>><i class="fas fa-fw fa-pen me-2"></i>Edit details</a>
                            <?php if (in_array($status, ['pending', 'approved'], true)) { ?>
                                <a class="dropdown-item confirm-link" href="post.php?mit_po_back_to_draft=<?= $po_id ?>&csrf_token=<?= $csrf ?>"><i class="fas fa-fw fa-undo me-2"></i>Back to draft (to change items)</a>
                            <?php } ?>
                            <?php if ($is_draft) { ?>
                                <div class="dropdown-divider"></div>
                                <a class="dropdown-item text-danger confirm-link" href="post.php?mit_po_delete=<?= $po_id ?>&csrf_token=<?= $csrf ?>"><i class="fas fa-fw fa-trash me-2"></i>Delete draft (items back to the list)</a>
                            <?php } elseif ($level >= 3 && !$received_any) { ?>
                                <div class="dropdown-divider"></div>
                                <a class="dropdown-item text-danger confirm-link" href="post.php?mit_po_cancel=<?= $po_id ?>&csrf_token=<?= $csrf ?>"><i class="fas fa-fw fa-ban me-2"></i>Cancel PO (items back to the list)</a>
                            <?php } ?>
                        </div>
                    </div>
                <?php } ?>
            </div>
        </div>

        <?php if ($status !== 'cancelled') { ?>
            <div class="d-flex align-items-center mt-3 small flex-wrap gap-1">
                <?php $i = 0;
                foreach ($steps as $key => $label) {
                    if ($key === 'pending' && !$needs_approval && $at !== 1) {
                        $i++;
                        continue;
                    }
                    $done = $i < $at || ($status === 'received' && $key === 'received');
                    $current = $i === $at && $status !== 'received';
                    $text = $label;
                    if ($key === 'received' && $status === 'partial') {
                        $text = 'Received ' . mitQty($qty_received) . ' of ' . mitQty($qty_total);
                    } elseif ($key === 'ordered' && $po['order_date']) {
                        $text = 'Ordered ' . date('M j', strtotime($po['order_date']));
                    }
                    if ($i > 0) { ?>
                        <span class="flex-grow-1 border-top border-2 <?= $done || $current ? 'border-success' : '' ?>" style="min-width: 24px"></span>
                    <?php } ?>
                    <span class="<?= $done ? 'text-success fw-semibold' : ($current ? 'fw-semibold text-body' : 'text-muted') ?>">
                        <?= $done ? '<i class="fas fa-check me-1"></i>' : ($current ? '<i class="fas fa-circle me-1 small text-warning"></i>' : '') ?><?= escapeHtml($text) ?>
                    </span>
                    <?php $i++;
                } ?>
            </div>
        <?php } ?>

        <?php if ($status === 'pending') { ?>
            <div class="alert alert-warning mt-3 mb-0">
                <i class="fas fa-hourglass-half me-2"></i>Waiting for approval - the total is over <?= mitMoney($approval_over) ?>, or a line has no cost.
                <?php if (mitPoMayApprove($po)) { ?>
                    <?= intval($po['created_by']) === intval($session_user_id) ? 'You made it - as an admin you can approve it yourself (the history notes it).' : 'You can approve it.' ?>
                <?php } else {
                    $mit_approvers = mitPoApproverNames($po); ?>
                    <?= $level >= 3 ? 'You made it, so someone else approves it' : 'Someone with Full Procurement access approves it' ?><?= $mit_approvers !== '' ? ': ' . escapeHtml($mit_approvers) . '.' : '.' ?>
                <?php } ?>
            </div>
        <?php } elseif ($po['approval_note'] && $is_draft) { ?>
            <div class="alert alert-danger mt-3 mb-0"><i class="fas fa-undo me-2"></i>Sent back: <?= escapeHtml($po['approval_note']) ?></div>
        <?php } ?>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header"><h3 class="card-title"><i class="fas fa-fw fa-list me-2"></i>Items</h3></div>
            <form action="post.php" method="post" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="po_id" value="<?= $po_id ?>">
                <div class="table-responsive">
                    <table class="table table-borderless mb-0 align-middle">
                        <thead class="text-nowrap border-bottom">
                            <tr>
                                <th>Item</th>
                                <th>For</th>
                                <th class="text-center" style="width: 90px">Qty</th>
                                <th class="text-end" style="width: 130px">Unit cost</th>
                                <th class="text-end">Amount</th>
                                <?php if (!$is_draft) { ?><th>Received</th><?php } ?>
                                <?php if ($is_draft && $level >= 2) { ?><th></th><?php } ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($lines as $line) {
                                $line_id = intval($line['line_id']);
                                $got = (float) $line['received_qty'];
                                $want = (float) $line['quantity']; ?>
                                <tr class="border-bottom">
                                    <td><strong><?= escapeHtml($line['name']) ?></strong><?php if ($line['description']) { ?><div class="small text-muted"><?= nl2br(escapeHtml($line['description'])) ?></div><?php } ?></td>
                                    <td class="small"><?= mitProcForHtml($line) ?></td>
                                    <?php if ($is_draft && $level >= 2) { ?>
                                        <td><input type="text" inputmode="decimal" class="form-control form-control-sm text-center" name="lines[<?= $line_id ?>][qty]" value="<?= mitQty($want) ?>" aria-label="Quantity"></td>
                                        <td><input type="text" inputmode="decimal" class="form-control form-control-sm text-end" name="lines[<?= $line_id ?>][cost]" value="<?= number_format((float) $line['unit_cost'], 2, '.', '') ?>" aria-label="Unit cost"></td>
                                    <?php } else { ?>
                                        <td class="text-center"><?= mitQty($want) ?></td>
                                        <td class="text-end"><?= mitMoney($line['unit_cost']) ?></td>
                                    <?php } ?>
                                    <td class="text-end"><?= mitMoney($want * (float) $line['unit_cost']) ?></td>
                                    <?php if (!$is_draft) { ?>
                                        <td class="text-nowrap">
                                            <?php if (in_array($status, ['ordered', 'partial', 'received'], true)) { ?>
                                                <span class="badge rounded-pill <?= $got + 0.001 >= $want ? 'text-bg-success' : ($got > 0 ? 'text-bg-warning' : 'text-bg-light border') ?>"><?= mitQty($got) ?> of <?= mitQty($want) ?></span>
                                            <?php } else { ?>
                                                <span class="text-muted">-</span>
                                            <?php } ?>
                                        </td>
                                    <?php } ?>
                                    <?php if ($is_draft && $level >= 2) { ?>
                                        <td class="text-end"><a class="btn btn-sm btn-light confirm-link" href="post.php?mit_po_remove_line=<?= $line_id ?>&po_id=<?= $po_id ?>&csrf_token=<?= $csrf ?>" title="Take off the PO"><i class="fas fa-times"></i></a></td>
                                    <?php } ?>
                                </tr>
                            <?php } ?>
                            <?php if (!$lines) { ?>
                                <tr><td colspan="7" class="text-center text-muted py-4">No items. Add some from <a href="mit_procurement.php">Items to order</a>.</td></tr>
                            <?php } ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="4" class="text-end">Shipping</td>
                                <td class="text-end" style="width: 140px">
                                    <?php if ($is_draft && $level >= 2) { ?>
                                        <input type="text" inputmode="decimal" class="form-control form-control-sm text-end" name="shipping" value="<?= number_format((float) $po['shipping'], 2, '.', '') ?>" aria-label="Shipping">
                                    <?php } else { ?>
                                        <?= mitMoney($po['shipping']) ?>
                                    <?php } ?>
                                </td>
                                <td colspan="2"></td>
                            </tr>
                            <tr class="fw-bold">
                                <td colspan="4" class="text-end">Total</td>
                                <td class="text-end fs-5"><?= mitMoney($po['total']) ?></td>
                                <td colspan="2"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <?php if ($is_draft && $level >= 2 && $lines) { ?>
                    <div class="card-footer d-flex align-items-center gap-2">
                        <button type="submit" name="mit_po_save_lines" class="btn btn-outline-primary btn-sm"><i class="fas fa-check me-2"></i>Save quantities &amp; costs</button>
                        <span class="small text-muted ms-auto"><?= !mitProcSettings()['approval_on'] ? 'Approval is switched off - approved when submitted.' : ($needs_approval ? 'Over ' . mitMoney($approval_over) . ' - needs approval when sent.' : 'Under ' . mitMoney($approval_over) . ' - no approval needed.') ?></span>
                    </div>
                <?php } ?>
            </form>
        </div>

        <?php if (in_array($status, ['approved', 'ordered', 'partial', 'received'], true) || $tracking) { ?>
            <div class="card">
                <div class="card-header d-flex align-items-center">
                    <h3 class="card-title"><i class="fas fa-fw fa-shipping-fast me-2"></i>Shipments &amp; tracking</h3>
                    <?php if ($level >= 2 && $status !== 'cancelled') { ?>
                        <div class="card-tools ms-auto"><a href="#" class="btn btn-sm btn-outline-primary ajax-modal" <?= $modal('tracking') ?>><i class="fas fa-plus me-1"></i>Add tracking</a></div>
                    <?php } ?>
                </div>
                <div class="list-group list-group-flush">
                    <?php foreach ($tracking as $t) { ?>
                        <div class="list-group-item d-flex flex-wrap align-items-center gap-2">
                            <div class="me-auto">
                                <?= mitTrackingHtml($t) ?>
                                <?php if ($t['note']) { ?><div class="small text-muted"><?= escapeHtml($t['note']) ?></div><?php } ?>
                            </div>
                            <?php if (intval($t['delivered'])) { ?>
                                <span class="badge rounded-pill text-bg-success">Delivered</span>
                            <?php } elseif ($t['eta']) { ?>
                                <span class="badge rounded-pill text-bg-light border">Expected <?= escapeHtml(date('M j', strtotime($t['eta']))) ?></span>
                            <?php } ?>
                            <?php if ($level >= 2) { ?>
                                <?php if (!intval($t['delivered'])) { ?>
                                    <a class="btn btn-sm btn-light" href="post.php?mit_po_tracking_delivered=<?= intval($t['tracking_id']) ?>&csrf_token=<?= $csrf ?>" title="Mark delivered"><i class="fas fa-check"></i></a>
                                <?php } ?>
                                <a class="btn btn-sm btn-light confirm-link" href="post.php?mit_po_tracking_delete=<?= intval($t['tracking_id']) ?>&csrf_token=<?= $csrf ?>" title="Remove"><i class="fas fa-trash"></i></a>
                            <?php } ?>
                        </div>
                    <?php } ?>
                    <?php if (!$tracking) { ?>
                        <div class="list-group-item text-muted small">No tracking yet - add it when the vendor sends it.</div>
                    <?php } ?>
                </div>
            </div>
        <?php } ?>
    </div>

    <div class="col-lg-4">
        <div class="card">
            <div class="card-header d-flex align-items-center">
                <h3 class="card-title"><i class="fas fa-fw fa-info-circle me-2"></i>Details</h3>
                <?php if ($level >= 2 && $status !== 'cancelled') { ?>
                    <div class="card-tools ms-auto"><a href="#" class="btn btn-sm btn-light ajax-modal" <?= $modal('details') ?> title="Edit details"><i class="fas fa-pen"></i></a></div>
                <?php } ?>
            </div>
            <div class="card-body small">
                <dl class="row mb-0">
                    <dt class="col-5 fw-normal text-muted">Vendor</dt>
                    <dd class="col-7"><?= escapeHtml($po['vendor_name'] ?: '-') ?><?php if ($po['vendor_account_number']) { ?><div class="text-muted">Account <?= escapeHtml($po['vendor_account_number']) ?></div><?php } ?></dd>
                    <dt class="col-5 fw-normal text-muted">Vendor order #</dt>
                    <dd class="col-7"><?= $po['vendor_order_number'] ? '<span class="font-monospace">' . escapeHtml($po['vendor_order_number']) . '</span>' : '<span class="text-muted">-</span>' ?></dd>
                    <dt class="col-5 fw-normal text-muted">Ordered</dt>
                    <dd class="col-7"><?= $po['order_date'] ? escapeHtml($po['order_date']) : '<span class="text-muted">Not yet</span>' ?></dd>
                    <dt class="col-5 fw-normal text-muted">Ship to</dt>
                    <dd class="col-7"><?php $ship_office_id = function_exists('mitOfficeFromShipTo') && mitOffices() ? mitOfficeFromShipTo($po['ship_to']) : null; $ship_office = $ship_office_id !== null ? mitOffice($ship_office_id, true) : null; ?><?= $ship_office ? '<div class="fw-semibold" data-mit-po-ship-office-name>' . escapeHtml($ship_office['name']) . '</div>' : '' ?><?= nl2br(escapeHtml($po['ship_to_address'] ?: '-')) ?></dd>
                    <dt class="col-5 fw-normal text-muted">Created by</dt>
                    <dd class="col-7"><?= escapeHtml($po['created_by_name'] ?: '-') ?> <span class="text-muted"><?= escapeHtml(date('M j, Y', strtotime($po['created_at']))) ?></span></dd>
                    <?php if ($po['approved_at']) { ?>
                        <dt class="col-5 fw-normal text-muted">Approved</dt>
                        <dd class="col-7"><?= escapeHtml($po['approved_by_name'] ?: '-') ?> <span class="text-muted"><?= escapeHtml(date('M j', strtotime($po['approved_at']))) ?><?= $po['approval_note'] ? ' - ' . escapeHtml($po['approval_note']) : '' ?></span></dd>
                    <?php } ?>
                    <?php if ($expense && $can_see_expense) { ?>
                        <dt class="col-5 fw-normal text-muted">Vendor bill</dt>
                        <dd class="col-7">
                            <a href="expenses.php?q=<?= urlencode(mitPoReference($po)) ?>"><?= mitMoney($expense['expense_amount']) ?></a>
                            <span class="text-muted"><?= escapeHtml($expense['expense_date']) ?><?= $expense['expense_reference'] ? ' · ' . escapeHtml($expense['expense_reference']) : '' ?></span>
                        </dd>
                    <?php } ?>
                    <?php if (trim((string) $po['note']) !== '') { ?>
                        <dt class="col-5 fw-normal text-muted">Note to vendor</dt>
                        <dd class="col-7"><?= nl2br(escapeHtml($po['note'])) ?></dd>
                    <?php } ?>
                </dl>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h3 class="card-title"><i class="fas fa-fw fa-history me-2"></i>Activity</h3></div>
            <div class="card-body small">
                <?php foreach ($history as $entry) { ?>
                    <div class="mb-2"><span class="text-muted"><?= escapeHtml(date('M j, g:ia', strtotime($entry['created_at']))) ?> ·</span> <?= escapeHtml($entry['description']) ?></div>
                <?php } ?>
            </div>
        </div>
    </div>
</div>

<?php
require_once "../includes/footer.php";
