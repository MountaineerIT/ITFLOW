<?php

/*
 * Mountaineer IT - Billing > Unbilled parts (functions/mit_procurement_parts.php): every part put on a ticket
 * that is not on an invoice yet, by client and ticket, oldest first - so equipment that went out is not forgotten.
 * Bill a ticket's parts from here (the same window as the ticket's Parts box). ?client= narrows it to one client.
 */

require_once "includes/inc_all.php";

mitModuleRequire('procurement');

// A billing page: Sales View to see it (the Procurement permission is not needed). Billing opens the ticket's own
// Bill parts window, which takes Sales Edit and Support Edit; ticket subjects and links only with Support View.
enforceUserPermission('module_sales');

$client_filter = intval($_GET['client'] ?? 0);
$parts = mitUnbilledParts($client_filter);
$can_see_tickets = lookupUserPermission('module_support') >= 1;
$can_bill = lookupUserPermission('module_sales') >= 2 && lookupUserPermission('module_support') >= 2;
$today = strtotime(date('Y-m-d'));

// By client, then ticket
$groups = [];
$total = 0;
$oldest = null;
foreach ($parts as $part) {
    $client = intval($part['client_id']);
    $ticket = intval($part['ticket_id']);
    $groups[$client]['name'] = $part['client_name'] ?: 'No client';
    $groups[$client]['tickets'][$ticket][] = $part;
    $groups[$client]['total'] = ($groups[$client]['total'] ?? 0) + $part['quantity'] * $part['unit_price'];
    $total += $part['quantity'] * $part['unit_price'];
    $oldest = $oldest === null ? $part['created_at'] : min($oldest, $part['created_at']);
}
uasort($groups, function ($a, $b) {
    return strcasecmp($a['name'], $b['name']);
});
$clients = mysqli_fetch_all(mysqli_query($mysqli, "SELECT DISTINCT clients.client_id, client_name FROM mit_ticket_parts parts LEFT JOIN tickets ON tickets.ticket_id = parts.ticket_id
    JOIN clients ON clients.client_id = " . MIT_PART_CLIENT_SQL . "
    LEFT JOIN invoices ON invoices.invoice_id = parts.invoice_id AND parts.invoice_id > 0
    WHERE (parts.invoice_id = 0 OR invoices.invoice_id IS NULL)" . clientScopeSql(MIT_PART_CLIENT_SQL) . " ORDER BY client_name"), MYSQLI_ASSOC);

?>

<div class="card">
    <div class="card-header bg-dark py-2">
        <h3 class="card-title mt-2"><i class="fas fa-fw fa-file-invoice-dollar me-2"></i>Unbilled parts</h3>
    </div>

    <div class="card-body">
        <div class="d-flex flex-wrap align-items-center gap-3 mb-3">
            <div>
                <div class="fs-4 fw-semibold"><?= count($parts) ?> part<?= count($parts) === 1 ? '' : 's' ?> · <?= mitMoney($total) ?></div>
                <div class="small text-muted">
                    Put on tickets and not on an invoice yet<?= $oldest ? ' - the oldest added ' . escapeHtml(date('M j, Y', strtotime($oldest))) : '' ?>.
                </div>
            </div>
            <form class="ms-auto" method="get">
                <select class="form-select" name="client" onchange="this.form.submit()" aria-label="Client">
                    <option value="0">All clients</option>
                    <?php foreach ($clients as $client) { ?>
                        <option value="<?= intval($client['client_id']) ?>" <?= intval($client['client_id']) === $client_filter ? 'selected' : '' ?>><?= escapeHtml($client['client_name']) ?></option>
                    <?php } ?>
                </select>
            </form>
        </div>

        <?php if (!$parts) { ?>
            <div class="text-center text-muted py-5"><i class="fas fa-check-circle fa-2x text-success mb-2 d-block"></i>Every part on a ticket is billed.</div>
        <?php } ?>

        <?php foreach ($groups as $client_id => $group) { ?>
            <div class="d-flex align-items-baseline mt-4 mb-2 border-bottom pb-1">
                <h5 class="mb-0">
                    <?php if ($client_id) { ?><a href="client_overview.php?client_id=<?= $client_id ?>" class="text-reset"><?= escapeHtml($group['name']) ?></a><?php } else { ?><?= escapeHtml($group['name']) ?><?php } ?>
                </h5>
                <span class="ms-auto text-muted small"><?= mitMoney($group['total']) ?></span>
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="small text-muted">
                        <tr><th>Ticket</th><th>Part</th><th>Where it is</th><th class="text-center">Added</th><th class="text-end">Amount</th><th style="width: 130px"></th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($group['tickets'] as $ticket_id => $ticket_parts) {
                            foreach ($ticket_parts as $i => $part) {
                                $days = intval(round(($today - strtotime(date('Y-m-d', strtotime($part['created_at'])))) / 86400));
                                $closed = $part['ticket_closed_at'] || $part['ticket_resolved_at']; ?>
                                <tr>
                                    <?php if ($i === 0) { ?>
                                        <td rowspan="<?= count($ticket_parts) ?>" class="align-top" style="max-width: 280px">
                                            <?php if ($can_see_tickets) { ?>
                                                <a href="ticket.php?client_id=<?= intval($part['client_id']) ?>&ticket_id=<?= $ticket_id ?>" class="fw-semibold"><?= escapeHtml(ticketReference($part)) ?></a>
                                                <div class="small text-muted text-truncate" title="<?= escapeHtml($part['ticket_subject']) ?>"><?= escapeHtml($part['ticket_subject']) ?></div>
                                            <?php } else { ?>
                                                <span class="fw-semibold"><?= escapeHtml(ticketReference($part)) ?></span>
                                            <?php } ?>
                                            <?php if ($closed) { ?><span class="badge text-bg-warning">Ticket <?= $part['ticket_closed_at'] ? 'closed' : 'resolved' ?></span><?php } ?>
                                        </td>
                                    <?php } ?>
                                    <td><?= mitQty($part['quantity']) ?> × <?= escapeHtml($part['name']) ?></td>
                                    <td class="small"><?= mitTicketPartStatus($part) ?></td>
                                    <td class="text-center small <?= $days > 30 ? 'text-danger fw-semibold' : ($days > 7 ? 'text-warning-emphasis' : 'text-muted') ?>" title="<?= escapeHtml(date('M j, Y', strtotime($part['created_at']))) ?>">
                                        <?= $days === 0 ? 'Today' : ($days === 1 ? 'Yesterday' : $days . ' days ago') ?>
                                    </td>
                                    <td class="text-end text-nowrap"><?= mitMoney($part['quantity'] * $part['unit_price']) ?></td>
                                    <?php if ($i === 0) { ?>
                                        <td rowspan="<?= count($ticket_parts) ?>" class="text-end align-top">
                                            <?php if ($can_bill && $client_id) { ?>
                                                <button type="button" class="btn btn-sm btn-outline-success ajax-modal" data-modal-url="modals/mit_procurement/parts_bill.php?ticket_id=<?= $ticket_id ?>">
                                                    <i class="fas fa-file-invoice-dollar me-1"></i>Bill parts
                                                </button>
                                            <?php } ?>
                                        </td>
                                    <?php } ?>
                                </tr>
                            <?php }
                        } ?>
                    </tbody>
                </table>
            </div>
        <?php } ?>

        <?php if ($parts && !$can_bill) { ?>
            <p class="small text-muted mt-3 mb-0">Billing parts takes Sales edit and Support edit access - ask someone who invoices.</p>
        <?php } ?>
    </div>
</div>

<?php
require_once "../includes/footer.php";
