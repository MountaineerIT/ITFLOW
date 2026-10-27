<?php

/*
 * Mountaineer IT - Procurement > Purchase orders (functions/mit_procurement.php).
 */

require_once "includes/inc_all.php";

mitModuleRequire('procurement');

enforceUserPermission('module_procurement');

$tabs = [
    'open' => ['Open', "po.status IN ('draft', 'pending', 'approved', 'ordered', 'partial')"],
    'pending' => ['Awaiting approval', "po.status = 'pending'"],
    'arriving' => ['On order', "po.status IN ('ordered', 'partial')"],
    'received' => ['Received', "po.status = 'received'"],
    'cancelled' => ['Cancelled', "po.status = 'cancelled'"],
    'all' => ['All', '1 = 1'],
];
$tab = isset($tabs[$_GET['show'] ?? '']) ? $_GET['show'] : 'open';
$q = trim((string) ($_GET['q'] ?? ''));
$search = '';
if ($q !== '') {
    $like = escapeSql('%' . addcslashes($q, '%_\\') . '%');
    $search = " AND (CONCAT(po.po_prefix, po.po_number) LIKE '$like' OR vendor_name LIKE '$like' OR po.vendor_order_number LIKE '$like'
        OR po.po_id IN (SELECT po_id FROM mit_po_tracking WHERE tracking_number LIKE '$like')
        OR po.po_id IN (SELECT l.po_id FROM mit_po_lines l LEFT JOIN clients c ON c.client_id = l.client_id WHERE l.name LIKE '$like' OR c.client_name LIKE '$like'))";
}
$scope = mitPoScopeSql('po.po_id');

$counts = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT " . implode(', ', array_map(function ($key) use ($tabs) {
    return "COALESCE(SUM({$tabs[$key][1]}), 0) AS `$key`";
}, array_keys($tabs))) . " FROM mit_purchase_orders po LEFT JOIN vendors ON vendors.vendor_id = po.vendor_id WHERE 1 = 1 $scope $search"));

$pos = mysqli_fetch_all(mysqli_query($mysqli, "SELECT po.po_id, po.po_prefix, po.po_number, po.vendor_id, po.status, po.order_date, po.vendor_order_number, po.ship_to, po.ship_to_address, po.note, po.shipping, po.total, po.created_by, po.submitted_at, po.approved_by, po.approved_at, po.approval_note, po.ordered_by, po.ordered_at, po.received_at, po.cancelled_at, po.expense_id, po.created_at, po.updated_at, vendor_name,
        (SELECT GROUP_CONCAT(DISTINCT c.client_name ORDER BY c.client_name SEPARATOR ', ') FROM mit_po_lines l JOIN clients c ON c.client_id = l.client_id WHERE l.po_id = po.po_id) AS client_names,
        (SELECT COALESCE(SUM(quantity), 0) FROM mit_po_lines WHERE po_id = po.po_id) AS qty,
        (SELECT COALESCE(SUM(LEAST(received_qty, quantity)), 0) FROM mit_po_lines WHERE po_id = po.po_id) AS received
    FROM mit_purchase_orders po
    LEFT JOIN vendors ON vendors.vendor_id = po.vendor_id
    WHERE {$tabs[$tab][1]} $scope $search
    ORDER BY FIELD(po.status, 'pending', 'draft', 'approved', 'partial', 'ordered', 'received', 'cancelled'), po.po_id DESC
    LIMIT 500"), MYSQLI_ASSOC);

?>

<div class="card">
    <div class="card-header bg-dark py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-file-signature me-2"></i>Purchase orders</h3>
        <div class="card-tools">
            <a href="mit_procurement.php" class="btn btn-primary"><i class="fas fa-cart-plus me-2"></i>Items to order</a>
        </div>
    </div>
    <div class="card-body">
        <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
            <div class="btn-group flex-wrap">
                <?php foreach ($tabs as $key => [$label]) { ?>
                    <a href="?show=<?= $key ?>&q=<?= urlencode($q) ?>" class="btn btn-sm <?= $tab === $key ? 'btn-dark' : 'btn-outline-dark' ?>">
                        <?= $label ?> <span class="badge <?= $key === 'pending' && intval($counts[$key]) ? 'text-bg-warning' : 'text-bg-light' ?> ms-1"><?= intval($counts[$key]) ?></span>
                    </a>
                <?php } ?>
            </div>
            <form class="ms-auto" method="get" autocomplete="off">
                <input type="hidden" name="show" value="<?= $tab ?>">
                <div class="input-group input-group-sm">
                    <input type="search" class="form-control" name="q" value="<?= escapeHtml($q) ?>" placeholder="PO, vendor, order #, tracking, client, item" aria-label="Search">
                    <button class="btn btn-dark" type="submit"><i class="fas fa-search"></i></button>
                </div>
            </form>
        </div>

        <?php if (!$pos) { ?>
            <div class="text-center text-muted py-5">No purchase orders here.</div>
        <?php } else { ?>
            <div class="table-responsive">
                <table class="table table-striped table-borderless table-hover mb-0">
                    <thead class="text-nowrap">
                        <tr>
                            <th>PO</th>
                            <th>Vendor</th>
                            <th>For</th>
                            <th class="text-end">Total</th>
                            <th>Status</th>
                            <th>Ordered</th>
                            <th>Vendor order #</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pos as $po) { ?>
                            <tr>
                                <td class="text-nowrap"><a href="mit_purchase_order.php?po_id=<?= intval($po['po_id']) ?>" class="fw-semibold"><?= escapeHtml(mitPoReference($po)) ?></a></td>
                                <td><?= escapeHtml($po['vendor_name'] ?: '-') ?></td>
                                <td><?= $po['client_names'] ? escapeHtml($po['client_names']) : '<span class="text-muted">Our stock</span>' ?></td>
                                <td class="text-end"><?= mitMoney($po['total']) ?></td>
                                <td class="text-nowrap">
                                    <?= mitPoBadge($po['status']) ?>
                                    <?php if ($po['status'] === 'partial') { ?><span class="small text-muted ms-1"><?= mitQty($po['received']) ?>/<?= mitQty($po['qty']) ?></span><?php } ?>
                                </td>
                                <td class="text-nowrap"><?= $po['order_date'] ? escapeHtml($po['order_date']) : '<span class="text-muted">-</span>' ?></td>
                                <td><?= $po['vendor_order_number'] ? escapeHtml($po['vendor_order_number']) : '<span class="text-muted">-</span>' ?></td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        <?php } ?>
    </div>
</div>

<?php
require_once "../includes/footer.php";
