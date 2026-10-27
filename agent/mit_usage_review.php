<?php

/*
 * Mountaineer IT - Billing > Usage Review (functions/mit_usage.php): the invoices made from recurring invoices with usage lines
 * for one snapshot day - each line's count, last month's quantity, this month's, the change and its flags; change a quantity
 * (with a reason) and approve. ?snapshot=Y-m-d (default the newest), ?show=flagged|all.
 */

require_once "includes/inc_all.php";

enforceUserPermission('module_sales');

if (!mitUsageReady()) {
    echo '<div class="alert alert-warning">Usage billing needs the database update - Admin > Update.</div>';
    require_once "../includes/footer.php";
    exit;
}

$snapshots = mitUsageSnapshots();
$snapshot = in_array($_GET['snapshot'] ?? '', $snapshots, true) ? $_GET['snapshot'] : ($snapshots[0] ?? '');
$show = ($_GET['show'] ?? '') === 'all' ? 'all' : 'flagged';
$invoices = $snapshot !== '' ? mitUsageReviewRows($snapshot) : [];
$approvals = function_exists('mitInvoiceApprovalStates') && mitQboReady() ? mitInvoiceApprovalStates(array_keys($invoices)) : [];
$can_edit = mitUsageCan(2);
$can_approve = function_exists('mitQboCan') && mitQboCan(2);
$csrf = $_SESSION['csrf_token'];
$settings = mitUsageSettings();
$flagged_count = count(array_filter($invoices, fn($rows) => (bool) array_filter($rows, fn($r) => $r['flags'] !== '' && $r['override_at'] === null)));

?>

<div class="card">
    <div class="card-header bg-dark py-2 d-flex flex-wrap align-items-center gap-2">
        <h3 class="card-title mt-2 me-auto"><i class="fas fa-fw fa-tachometer-alt me-2"></i>Usage review</h3>
        <?php if (function_exists('mitQboReady') && mitQboReady()) { ?>
            <a href="/agent/mit_invoice_approvals.php" class="btn btn-light btn-sm"><i class="fas fa-clipboard-check me-2"></i>Invoice approvals</a>
        <?php } ?>
    </div>
    <div class="card-body">
        <?php if (!$snapshot) { ?>
            <p class="text-muted mb-0">No invoices with usage yet. Mark lines as usage on a recurring invoice (its Usage billing card); when the invoice is next
                made, the lines' quantities come from their counts on the snapshot day (the <?= $settings['snapshot_day'] ?><?= date('S', mktime(0, 0, 0, 1, $settings['snapshot_day'])) ?>) and show here.</p>
        <?php } else { ?>
            <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                <form method="get" class="d-flex gap-2 align-items-center">
                    <label class="small text-muted" for="mitUsageSnapshot">Snapshot</label>
                    <select class="form-select form-select-sm" name="snapshot" id="mitUsageSnapshot" onchange="this.form.submit()">
                        <?php foreach ($snapshots as $date) { ?>
                            <option value="<?= escapeHtml($date) ?>" <?= $date === $snapshot ? 'selected' : '' ?>><?= escapeHtml(date('M j, Y', strtotime($date))) ?></option>
                        <?php } ?>
                    </select>
                    <input type="hidden" name="show" value="<?= $show ?>">
                </form>
                <div class="btn-group ms-2">
                    <a class="btn btn-sm <?= $show === 'flagged' ? 'btn-primary' : 'btn-outline-secondary' ?>" href="?snapshot=<?= escapeHtml($snapshot) ?>&show=flagged">To review
                        <?php if ($flagged_count) { ?><span class="badge text-bg-light ms-1"><?= $flagged_count ?></span><?php } ?></a>
                    <a class="btn btn-sm <?= $show === 'all' ? 'btn-primary' : 'btn-outline-secondary' ?>" href="?snapshot=<?= escapeHtml($snapshot) ?>&show=all">All <span class="badge text-bg-light ms-1"><?= count($invoices) ?></span></a>
                </div>
                <span class="small text-muted ms-auto">Flags: a change over <?= $settings['change_pct'] ?>%, a count more than <?= $settings['stale_days'] ?> days before the snapshot, or no count.</span>
            </div>

            <?php $shown = 0;
            foreach ($invoices as $invoice_id => $rows) {
                $first = $rows[0];
                $needs_review = (bool) array_filter($rows, fn($r) => $r['flags'] !== '' && $r['override_at'] === null);
                if ($show === 'flagged' && !$needs_review) {
                    continue;
                }
                $shown++;
                $approval = $approvals[$invoice_id] ?? 'none';
                $editable = $first['invoice_status'] === 'Draft' && $can_edit; ?>
                <div class="border rounded mb-3" data-mit-usage-invoice="<?= intval($invoice_id) ?>">
                    <div class="d-flex flex-wrap align-items-center gap-2 px-3 py-2 bg-body-tertiary border-bottom">
                        <strong><?= escapeHtml($first['client_name']) ?></strong>
                        <a href="/agent/invoice.php?client_id=<?= intval($first['client_id']) ?>&invoice_id=<?= intval($invoice_id) ?>"><?= escapeHtml($first['invoice_prefix'] . $first['invoice_number']) ?></a>
                        <span class="badge text-bg-light border"><?= escapeHtml($first['invoice_status']) ?></span>
                        <span class="ms-auto fw-semibold"><?= numfmt_format_currency($currency_format, floatval($first['invoice_amount']), $first['invoice_currency_code']) ?></span>
                        <?php if ($approval === 'approved') { ?>
                            <span class="badge text-bg-primary">Approved</span>
                        <?php } elseif ($can_approve && in_array($first['invoice_status'], ['Draft', 'Sent', 'Viewed'], true)) { ?>
                            <a class="btn btn-primary btn-sm" href="/agent/post.php?mit_invoice_approve=<?= intval($invoice_id) ?>&csrf_token=<?= escapeHtml($csrf) ?>"><i class="fas fa-check me-1"></i><?= $approval === 'changed' ? 'Approve again' : 'Approve' ?></a>
                        <?php } ?>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead><tr><th>Line</th><th>Count</th><th class="text-end">Last month</th><th class="text-end">This month</th><th class="text-end">Change</th><th>Flags</th><th></th></tr></thead>
                            <tbody>
                            <?php foreach ($rows as $row) {
                                $qty = $row['override_qty'] !== null ? floatval($row['override_qty']) : floatval($row['applied_qty']);
                                $previous = $row['previous_qty'] !== null ? floatval($row['previous_qty']) : null;
                                $change = $previous === null ? null : $qty - $previous; ?>
                                <tr data-applied="<?= intval($row['applied_id']) ?>">
                                    <td><?= escapeHtml($row['item_name']) ?><div class="small text-muted"><?= escapeHtml($row['metric']) ?> · <?= numfmt_format_currency($currency_format, floatval($row['item_price']), $row['invoice_currency_code']) ?> each</div></td>
                                    <td class="small"><?php if ($row['count_id']) { ?>
                                        <?= escapeHtml($row['count_source']) ?>, <?= escapeHtml($row['count_as_of']) ?>
                                    <?php } else { ?><span class="text-muted">none - kept <?= mitUsageQty($row['base_qty']) ?></span><?php } ?></td>
                                    <td class="text-end"><?= $previous === null ? '<span class="text-muted">-</span>' : mitUsageQty($previous) ?></td>
                                    <td class="text-end fw-semibold" data-qty><?= mitUsageQty($qty) ?>
                                        <?php if ($row['override_qty'] !== null) { ?>
                                            <div class="small fw-normal text-muted" title="<?= escapeHtml((string) $row['override_reason']) ?>">changed from <?= mitUsageQty($row['applied_qty']) ?> by <?= escapeHtml((string) $row['override_name']) ?></div>
                                        <?php } ?></td>
                                    <td class="text-end"><?= $change === null ? '' : ($change > 0 ? '+' : '') . mitUsageQty($change)
                                        . ($previous > 0 ? ' <span class="small text-muted">(' . ($change > 0 ? '+' : '') . round($change / $previous * 100) . '%)</span>' : '') ?></td>
                                    <td><?= mitUsageFlagBadges($row['flags']) ?><?= $row['override_reason'] ? '<div class="small text-muted">' . escapeHtml($row['override_reason']) . '</div>' : '' ?></td>
                                    <td class="text-end">
                                        <?php if ($editable) { ?>
                                            <form action="post.php" method="post" class="d-flex gap-1 justify-content-end" style="min-width: 17rem">
                                                <input type="hidden" name="csrf_token" value="<?= escapeHtml($csrf) ?>">
                                                <input type="hidden" name="applied_id" value="<?= intval($row['applied_id']) ?>">
                                                <input type="number" min="0" step="0.01" class="form-control form-control-sm" name="quantity" value="<?= mitUsageQty($qty) ?>" style="max-width: 5.5rem" aria-label="Quantity">
                                                <input type="text" class="form-control form-control-sm" name="reason" maxlength="255" placeholder="Why" required aria-label="Reason">
                                                <button type="submit" name="mit_usage_override" class="btn btn-outline-secondary btn-sm">Set</button>
                                            </form>
                                        <?php } ?>
                                    </td>
                                </tr>
                            <?php } ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php }
            if (!$shown) { ?>
                <p class="text-muted text-center py-4 mb-0">Nothing to review for this snapshot<?= $show === 'flagged' ? ' - no flagged lines (see All)' : '' ?>.</p>
            <?php } ?>
        <?php } ?>
    </div>
</div>

<?php
require_once "../includes/footer.php";
