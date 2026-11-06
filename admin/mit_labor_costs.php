<?php

/*
 * Mountaineer IT - Admin > Settings > Labor costs: each agent's cost per hour, with the date it counts from, and the company default
 * (functions/mit_profit.php; handlers admin/post/mit_labor_costs.php). Used by Reports > Client Profitability.
 */

require_once "includes/inc_all_admin.php";

if (!function_exists('mitProfitReady') || !mitProfitReady()) {
    echo '<div class="alert alert-warning">Labor costs need the database update - Admin > Update.</div>';
    require_once "../includes/footer.php";
    return;
}

$mit_rates = mitProfitRates();
$mit_default = mitProfitDefaultCost();
$mit_agents = mitProfitAgents();
$mit_today = date('Y-m-d');
?>

<div class="card card-dark" data-mit-labor-costs>
    <div class="card-header py-3">
        <h3 class="card-title"><i class="fas fa-fw fa-user-tag me-2"></i>Labor costs</h3>
    </div>
    <div class="card-body">
        <p class="text-body-secondary">
            What an hour of each agent costs the company - wage plus payroll taxes and benefits. Reports &gt; Client Profitability counts the
            hours on each client's tickets at these rates. A new rate counts from its date, so earlier periods keep the rate they had;
            an agent's first rate also covers time before its date. Only admins see this page.
        </p>

        <form action="post.php" method="post" autocomplete="off" class="row g-2 align-items-end mb-4" data-mit-labor-default>
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="mit_labor_default_save" value="1">
            <div class="col-sm-6 col-md-4">
                <label class="form-label">Company default per hour</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-fw fa-dollar-sign"></i></span>
                    <input type="text" inputmode="decimal" class="form-control" name="mit_labor_default" value="<?= $mit_default === null ? '' : escapeHtml(number_format($mit_default, 2, '.', '')) ?>" placeholder="None">
                </div>
                <div class="form-text">For agents with no rate of their own. Empty = their time is left uncosted.</div>
            </div>
            <div class="col-auto mb-4"><button type="submit" class="btn btn-primary"><i class="fas fa-check me-2"></i>Save</button></div>
        </form>

        <div class="table-responsive">
            <table class="table table-sm align-middle" data-mit-labor-table>
                <thead>
                    <tr><th>Agent</th><th>Per hour now</th><th>Rates</th><th style="min-width: 22rem">New rate</th></tr>
                </thead>
                <tbody>
                <?php foreach ($mit_agents as $mit_user_id => $mit_agent) {
                    $mit_list = $mit_rates[$mit_user_id] ?? [];
                    $mit_now = mitProfitRateOn($mit_rates, $mit_user_id, $mit_today, null); ?>
                    <tr data-mit-labor-agent="<?= intval($mit_user_id) ?>">
                        <td class="fw-semibold"><?= escapeHtml($mit_agent['name']) ?><?= $mit_agent['archived'] ? ' <span class="badge text-bg-secondary fw-normal">Archived</span>' : '' ?></td>
                        <td data-mit-labor-now>
                            <?php if ($mit_now !== null) { ?>
                                <?= escapeHtml(numfmt_format_currency($currency_format, $mit_now, $session_company_currency)) ?>
                            <?php } elseif ($mit_default !== null) { ?>
                                <span class="text-body-secondary">Default (<?= escapeHtml(numfmt_format_currency($currency_format, $mit_default, $session_company_currency)) ?>)</span>
                            <?php } else { ?>
                                <span class="text-warning-emphasis">Not set</span>
                            <?php } ?>
                        </td>
                        <td>
                            <?php foreach (array_reverse($mit_list) as [$mit_from, $mit_cost, $mit_rate_id]) { ?>
                                <form action="post.php" method="post" class="d-inline-flex align-items-center me-2 mb-1 small border rounded px-2 py-1" data-mit-labor-rate>
                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                    <input type="hidden" name="mit_labor_rate_delete" value="<?= intval($mit_rate_id) ?>">
                                    <span><?= escapeHtml(numfmt_format_currency($currency_format, $mit_cost, $session_company_currency)) ?> from <?= escapeHtml(date('M j, Y', strtotime($mit_from))) ?></span>
                                    <button type="submit" class="btn btn-link btn-sm text-danger p-0 ms-2" title="Remove this rate" aria-label="Remove this rate"><i class="fas fa-times"></i></button>
                                </form>
                            <?php } ?>
                            <?= $mit_list ? '' : '<span class="text-body-tertiary small">None yet</span>' ?>
                        </td>
                        <td>
                            <form action="post.php" method="post" autocomplete="off" class="d-flex gap-2" data-mit-labor-add>
                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                <input type="hidden" name="mit_labor_rate_add" value="<?= intval($mit_user_id) ?>">
                                <input type="date" class="form-control form-control-sm" name="mit_labor_from" value="<?= $mit_list ? $mit_today : escapeHtml(date('Y-01-01')) ?>" required aria-label="Counts from">
                                <input type="text" inputmode="decimal" class="form-control form-control-sm" name="mit_labor_cost" placeholder="Per hour" required aria-label="Cost per hour">
                                <button type="submit" class="btn btn-sm btn-outline-primary text-nowrap">Add</button>
                            </form>
                        </td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once "../includes/footer.php";
