<?php

/*
 * Mountaineer IT - Customer Thermometer responses (functions/mit_ct.php): the score and every response with its comment, the
 * ticket and the tech, newest first. Opened from the Customer happiness cards on the dashboard and client overview.
 * ?client_id= one client, ?temperature= 1-4, ?days= 30 / 90 / 365 / 0 (all). Support View; clients the agent may see.
 */

require_once "includes/inc_all.php";

enforceUserPermission('module_support');

if (!mitCtReady()) {
    echo '<div class="alert alert-warning">Customer Thermometer needs the database update - Admin > Update.</div>';
    require_once "../includes/footer.php";
    exit;
}

$client_filter = intval($_GET['client_id'] ?? 0);
if ($client_filter && !hasClientAccess($client_filter)) {
    $client_filter = 0;
}
$temperature = intval($_GET['temperature'] ?? 0);
$days = in_array(intval($_GET['days'] ?? 90), [30, 90, 365, 0], true) ? intval($_GET['days'] ?? 90) : 90;
$client_name = $client_filter ? (string) getFieldById('clients', $client_filter, 'client_name') : '';

$where = "s.temperature IS NOT NULL" . ($days ? " AND s.responded_at >= NOW() - INTERVAL $days DAY" : '')
    . ($client_filter ? " AND s.client_id = $client_filter" : clientScopeSql('s.client_id'))
    . (isset(MIT_CT_TEMPERATURES[$temperature]) ? " AND s.temperature = $temperature" : '');
$rows = mysqli_query($mysqli, "SELECT s.*, cl.client_name FROM mit_ct_surveys s LEFT JOIN clients cl ON cl.client_id = s.client_id
    WHERE $where ORDER BY s.responded_at DESC LIMIT 500");
$score = mitCtScore($client_filter, $days);
// The client picker: clients with a survey response (and the one shown), as the agent may see them
$picker_clients = mysqli_query($mysqli, "SELECT client_id, client_name, client_abbreviation FROM clients
    WHERE (client_id IN (SELECT client_id FROM mit_ct_surveys WHERE temperature IS NOT NULL) OR client_id = $client_filter)" . clientScopeSql('clients.client_id') . "
    ORDER BY client_name ASC");
$query = function (array $change) use ($client_filter, $temperature, $days) {
    $params = array_merge(['client_id' => $client_filter, 'temperature' => $temperature, 'days' => $days], $change);
    return '?' . http_build_query(array_filter($params, function ($value, $name) {
        return $name === 'days' || $value;
    }, ARRAY_FILTER_USE_BOTH));
};

?>

<section class="mb-3" data-mit-ct-reviews>
    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
        <h2 class="itflow-dash-section mb-0 me-auto">Customer happiness<?= $client_name !== '' ? ' - ' . escapeHtml($client_name) : '' ?>
            <span class="small text-body-secondary fw-normal"><?= $days === 365 ? 'last 12 months' : ($days ? "last $days days" : 'all time') ?></span></h2>
        <form method="get" autocomplete="off" style="min-width: 16rem;" data-mit-ct-client-pick>
            <input type="hidden" name="days" value="<?= $days ?>">
            <?php if ($temperature) { ?><input type="hidden" name="temperature" value="<?= $temperature ?>"><?php } ?>
            <select class="form-select form-select-sm select2" name="client_id" onchange="this.form.submit()" aria-label="Client">
                <option value="">- All Clients -</option>
                <?php while ($row = mysqli_fetch_assoc($picker_clients)) { ?>
                    <option value="<?= intval($row['client_id']) ?>" <?= intval($row['client_id']) === $client_filter ? 'selected' : '' ?><?= selectOptionClient($row['client_id'], $row['client_name'], $row['client_abbreviation'] ?? '') ?>><?= escapeHtml($row['client_name']) ?></option>
                <?php } ?>
            </select>
        </form>
    </div>
    <?= mitCtRail($score, $query(['temperature' => 0])) ?>
</section>

<div class="card">
    <div class="card-header py-2 d-flex flex-wrap align-items-center gap-2">
        <h3 class="card-title mt-1 me-auto">Responses</h3>
        <div class="btn-group btn-group-sm">
            <?php foreach ([30 => '30 days', 90 => '90 days', 365 => '12 months', 0 => 'All time'] as $value => $label) { ?>
                <a class="btn btn-outline-secondary <?= $days === $value ? 'active' : '' ?>" href="<?= escapeHtml($query(['days' => $value])) ?>"><?= $label ?></a>
            <?php } ?>
        </div>
        <?php if ($client_filter || $temperature) { ?>
            <a class="btn btn-sm btn-light" href="<?= escapeHtml($query(['client_id' => 0, 'temperature' => 0])) ?>"><i class="fas fa-times me-1"></i>Everyone, every rating</a>
        <?php } ?>
    </div>
    <div class="card-body p-0 table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead><tr><th>Responded</th><th>Rating</th><th>Comment</th><th>Client</th><th>Ticket</th><th>Tech</th></tr></thead>
            <tbody>
            <?php while ($row = mysqli_fetch_assoc($rows)) { ?>
                <tr>
                    <td class="text-nowrap small"><?= escapeHtml(date('Y-m-d H:i', strtotime($row['responded_at']))) ?></td>
                    <td><?= mitCtBadge($row['temperature']) ?></td>
                    <td class="small"><?= $row['comment'] !== null ? nl2br(escapeHtml($row['comment'])) : '<span class="text-muted">-</span>' ?></td>
                    <td><?= $row['client_name'] !== null ? '<a href="?client_id=' . intval($row['client_id']) . '&days=' . $days . '">' . escapeHtml($row['client_name']) . '</a>' : '' ?></td>
                    <td><?= $row['ticket_id'] ? '<a href="/agent/ticket.php?client_id=' . intval($row['client_id']) . '&ticket_id=' . intval($row['ticket_id']) . '">' . escapeHtml($row['ticket_ref']) . '</a>' : escapeHtml($row['ticket_ref']) ?></td>
                    <td><?= escapeHtml($row['tech_name']) ?></td>
                </tr>
            <?php } ?>
            <?php if (!mysqli_num_rows($rows)) { ?>
                <tr><td colspan="6" class="text-center text-muted py-3">No responses in this period.</td></tr>
            <?php } ?>
            </tbody>
        </table>
    </div>
</div>

<?php
require_once "../includes/footer.php";
