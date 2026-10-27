<?php
/*
 * The early-warning report (scripts/mit_upstream_impact.php) still sees what hurt us: run on the ITFlow commits of the Oct 25 merge
 * (DB 2.8.9 -> 3.4.7, merged in 9767bb1a), it must name the product_cost / product_vendor_id clash with our columns (2.9.4's
 * "Duplicate column" that stuck beta), ITFlow's own reimbursements against timesheets and its stock tracking against procurement.
 * Needs the repo's history (not the install); skipped without it.
 */
require __DIR__ . '/bootstrap.php';
$repo = dirname(__DIR__, 2);
if (!is_dir("$repo/.git") || trim((string) shell_exec('git -C ' . escapeshellarg($repo) . ' rev-parse --verify --quiet 9767bb1a^2 2>/dev/null')) === '') {
    echo "  skip - no repo history here\n";
    exit(0);
}
$report = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$repo/scripts/mit_upstream_impact.php") . ' --from=24135330^2 --to=9767bb1a^2 2>&1');
scenarioCheck('ran (98 commits, database 2.8.9 -> 3.4.7)', [strpos($report, '**98 commits**') !== false, strpos($report, '(database 2.8.9) -> 26.10 (database 3.4.7)') !== false], [true, true]);
scenarioCheck('the product_cost / product_vendor_id clash is named', [strpos($report, 'column products.product_cost - ours from') !== false, strpos($report, 'column products.product_vendor_id - ours from') !== false], [true, true]);
scenarioCheck('no clash for a name ITFlow already had (ticket_tags.ticket_id)', strpos($report, 'column ticket_tags.ticket_id - ours') === false, true);
$section = function ($key) use ($report) {
    return preg_match('/\(`' . $key . '`\)\n(.*?)(\n## |\z)/s', $report, $m) ? $m[1] : '';
};
scenarioCheck('timesheets: ITFlow\'s reimbursements flagged as an overlap', strpos($section('timesheets'), 'table reimbursements ("reimburse")') !== false, true);
scenarioCheck('procurement: ITFlow\'s stock tracking flagged', strpos($section('procurement'), 'product_stock.stock_type ("stock")') !== false, true);
scenarioCheck('quotes: ITFlow\'s markup / margin flagged', (bool) preg_match('/"(markup|margin)"/', $section('quotes')), true);
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
