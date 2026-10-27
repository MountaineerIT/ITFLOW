<?php
/*
 * Mountaineer IT - the Procurement section of the agent side menu (functions/mit_procurement.php),
 * required from agent/includes/side_nav.php. Only for roles with the Procurement permission.
 */

if (function_exists('mitProcLevel') && mitProcLevel() >= 1) {
    $mit_nav_page = basename($_SERVER['PHP_SELF']);
    $mit_nav_sql = "SELECT
        (SELECT COUNT(*) FROM mit_procurement_items proc WHERE status = 'waiting'" . clientScopeSql('proc.client_id') . ") AS to_order,
        (SELECT COUNT(*) FROM mit_purchase_orders po WHERE status = 'pending'" . mitPoScopeSql('po.po_id') . ") AS pending,
        (SELECT COUNT(*) FROM mit_po_lines l JOIN mit_purchase_orders po ON po.po_id = l.po_id WHERE po.status IN ('ordered', 'partial')
            AND l.received_qty < l.quantity" . mitPoScopeSql('po.po_id') . ") AS arriving";
    $mit_nav_counts = mitNavCachedCount("procurement:$mit_nav_sql", fn() => mysqli_fetch_assoc(mysqli_query($mysqli, $mit_nav_sql)),
        in_array($mit_nav_page, ['mit_procurement.php', 'mit_purchase_orders.php', 'mit_purchase_order.php', 'mit_receiving.php'], true));
    $mit_nav_links = [
        ['mit_procurement.php', 'fa-cart-plus', 'To Order', intval($mit_nav_counts['to_order']), 'Items waiting to be put on a PO', []],
        ['mit_purchase_orders.php', 'fa-file-signature', 'Purchase Orders', intval($mit_nav_counts['pending']), 'Waiting for approval', ['mit_purchase_order.php']],
        ['mit_receiving.php', 'fa-truck-loading', 'Receiving', intval($mit_nav_counts['arriving']), 'Items on ordered POs still to arrive', []],
    ];
    ?>
    <li class="nav-header mt-3">PROCUREMENT</li>
    <?php foreach ($mit_nav_links as [$mit_file, $mit_icon, $mit_label, $mit_count, $mit_title, $mit_also]) { ?>
        <li class="nav-item">
            <a href="/agent/<?= $mit_file ?>" class="nav-link <?= $mit_nav_page === $mit_file || in_array($mit_nav_page, $mit_also, true) ? 'active' : '' ?>">
                <i class="nav-icon fas <?= $mit_icon ?>"></i>
                <p>
                    <?= $mit_label ?>
                    <?php if ($mit_count) { ?>
                        <span class="right badge text-light" data-bs-toggle="tooltip" title="<?= $mit_title ?>"><?= $mit_count ?></span>
                    <?php } ?>
                </p>
            </a>
        </li>
    <?php }
}
