<?php
/*
 * Our columns on ITFlow's tables keep ITFlow's collation (26.11.7.1): ITFlow's databaseCollationRepair (3.6.5, Admin > Debug) fixes a
 * table's default and the columns db.sql has, and our runner's mitCollationRepair now only converts mit_ tables whole - so a column of
 * ours on an ITFlow table (products.product_image, recurring_tickets.recurring_ticket_by_day) left on another collation must be put
 * right by our runner, column by column, its type / NULL / default kept, and a second run must change nothing.
 */
require __DIR__ . '/bootstrap.php';
// $root is the install bootstrap.php picked (run.php [install] / ITFLOW_ROOT)
if (!is_file("$root/scripts/update_cli.php") || !function_exists('databaseCollationRepair')) {
    echo "  skip - no update_cli.php / ITFlow 26.11.7's collation repair here\n";
    exit(0);
}
$col = function ($t, $c) {
    global $mysqli;
    return mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT COLLATION_NAME AS co, COLUMN_TYPE AS ty, IS_NULLABLE AS nu, COLUMN_DEFAULT AS de
        FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$t' AND COLUMN_NAME = '$c'"));
};
$runner = function () use ($root) {
    $owner = posix_getpwuid(fileowner("$root/scripts/update_cli.php"))['name'] ?? 'root';
    $cmd = ($owner === 'root' ? '' : 'sudo -u ' . escapeshellarg($owner) . ' ') . 'php ' . escapeshellarg("$root/scripts/update_cli.php") . ' --update_db 2>&1';
    return (string) shell_exec($cmd);
};
$cases = [['products', 'product_image'], ['recurring_tickets', 'recurring_ticket_by_day']];
$before = [];
foreach ($cases as [$t, $c]) {
    $before["$t.$c"] = $col($t, $c);
}
if (!$before['products.product_image'] || !$before['recurring_tickets.recurring_ticket_by_day']) {
    echo "  skip - our columns are not here\n";
    exit(0);
}
try {
    echo "put on another collation\n";
    mysqli_query($mysqli, "ALTER TABLE products CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    mysqli_query($mysqli, "ALTER TABLE recurring_tickets MODIFY recurring_ticket_by_day " . $before['recurring_tickets.recurring_ticket_by_day']['ty'] . " CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL");
    databaseCollationRepair();
    scenarioCheck("ITFlow's repair leaves our column on products", $col('products', 'product_image')['co'], 'utf8mb4_unicode_ci');

    echo "our runner\n";
    $out = $runner();
    foreach ($cases as [$t, $c]) {
        $now = $col($t, $c);
        scenarioCheck("$t.$c back on utf8mb4_general_ci", $now['co'], 'utf8mb4_general_ci');
        scenarioCheck("... type, NULL and default kept", [$now['ty'], $now['nu'], $now['de']], [$before["$t.$c"]['ty'], $before["$t.$c"]['nu'], $before["$t.$c"]['de']]);
    }
    scenarioCheck('the runner said what it changed', strpos($out, 'collation products') !== false, true);
    scenarioCheck('a second run changes nothing', strpos($runner(), 'collation ') === false, true);
} finally {
    foreach (['products', 'recurring_tickets'] as $t) {
        mysqli_query($mysqli, "ALTER TABLE $t CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    }
    databaseCollationRepair();
    $runner();
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
