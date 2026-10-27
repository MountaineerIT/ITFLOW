<?php
/*
 * ITFlow's pager (includes/filter_footer.php) on a list of more than 10,000 pages - production's audit log (578,870 rows) at 10 or
 * 50 a page. Before our fix the pager had no step for that many pages and stopped the page with "Division by zero". Writes nothing.
 */
require __DIR__ . '/bootstrap.php';

foreach ([[578870, 10], [578870, 50], [120000, 100], [42, 10]] as [$rows, $per_page]) {
    $num_rows = [$rows];
    $user_config_records_per_page = $per_page;
    $_GET['page'] = 1;
    $error = '';
    ob_start();
    try {
        include getcwd() . '/includes/filter_footer.php';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
    $html = ob_get_clean();
    scenarioCheck("$rows rows at $per_page a page: the pager draws ($error)", $error === '' && strpos($html, 'of <strong>' . $rows . '</strong> records') !== false, true);
}
unset($_GET['page']);
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
