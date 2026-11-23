<?php
/*
 * A PO's lines put on a project after it was ordered (functions/mit_procurement_extras.php mitPoSetProject, 26.11.3.5 - the user found no
 * way to; on ITFlow 26.12's purchase orders since 26.12 - the line's project in mit_po_item_projects): an ordered PO's client line goes on the client's project (and its item with it), a line for nobody takes the project's client
 * while nothing of it is received, a received one for nobody is refused, another client's line is refused, the project card then counts
 * them, "No project" takes them off, and a cancelled PO is refused.
 */
require __DIR__ . '/bootstrap.php';
if (!function_exists('mitPoSetProject')) {
    echo "  skip - not in this build\n";
    exit(0);
}
$client_id = scenarioClient();
[$other_client] = scenarioMakeClient('ZZ Scenario other ' . date('His'));
$vendor = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT vendor_id FROM vendors WHERE vendor_client_id = 0 AND vendor_archived_at IS NULL ORDER BY vendor_id LIMIT 1"))[0] ?? 0);
$made = ['items' => [], 'pos' => [], 'projects' => []];
$old_approval = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT config_po_approval FROM settings WHERE company_id = 1"))[0] ?? 0);
// Before poSettings() first reads it (it keeps it for the request)
mysqli_query($mysqli, "UPDATE settings SET config_po_approval = 0 WHERE company_id = 1");
$item = function (array $fields) use (&$made) {
    return $made['items'][] = mitProcAddItem($fields + ['vendor_id' => 0, 'project_id' => 0, 'product_id' => 0]);
};
$project = function ($client) use ($mysqli, &$made) {
    mysqli_query($mysqli, "INSERT INTO projects SET project_prefix = 'PRJ-', project_number = " . (8800 + count($made['projects'])) . ", project_name = 'ZZ scen PO project',
        project_description = '', project_due = CURDATE(), project_client_id = " . intval($client));
    return $made['projects'][] = mysqli_insert_id($mysqli);
};
$line = function ($po_id, $name) {
    foreach (mitPoLines($po_id) as $row) {
        if ($row['po_item_name'] === $name) {
            return $row;
        }
    }
    return null;
};
try {
    $prj = $project($client_id);
    $ours = $project(0);

    // An ordered PO: a line for the client, one for nobody (stock), one for another client, one for nobody received already
    $ids = [$item(['name' => 'ZZ scen PO switch', 'quantity' => 2, 'unit_cost' => 100, 'client_id' => $client_id]),
        $item(['name' => 'ZZ scen PO cable', 'quantity' => 5, 'unit_cost' => 4, 'client_id' => 0]),
        $item(['name' => 'ZZ scen PO other', 'quantity' => 1, 'unit_cost' => 50, 'client_id' => $other_client]),
        $item(['name' => 'ZZ scen PO got', 'quantity' => 1, 'unit_cost' => 10, 'client_id' => 0])];
    $po_id = $made['pos'][] = poCreate($vendor, $ids, $error);
    poMarkOrdered(poRow($po_id), date('Y-m-d'), '', $error);
    poReceive([$line($po_id, 'ZZ scen PO got')['po_item_id'] => ['qty' => 1]]);
    scenarioCheck('the PO is ordered (partly received)', poRow($po_id)['po_status'], 'partial');

    $result = mitPoSetProject(poRow($po_id), [], $prj);
    scenarioCheck('two lines put on the project', $result['changed'], 2);
    scenarioCheck('another client\'s line and a received line for nobody are left, with why', array_keys($result['problems']), ['ZZ scen PO other', 'ZZ scen PO got']);
    scenarioCheck('the client\'s line is on it', intval($line($po_id, 'ZZ scen PO switch')['project_id']), $prj);
    $cable = $line($po_id, 'ZZ scen PO cable');
    scenarioCheck('the line for nobody is on it and takes the project\'s client', [intval($cable['project_id']), intval($cable['po_item_client_id'])], [$prj, $client_id]);
    scenarioCheck('its item follows', array_map('intval', mysqli_fetch_row(mysqli_query($mysqli, "SELECT links.project_id, order_item_client_id FROM order_items
        JOIN mit_order_item_links links ON links.order_item_id = order_items.order_item_id WHERE order_items.order_item_id = " . intval($cable['po_item_order_item_id'])))), [$prj, $client_id]);
    scenarioCheck('the other client\'s line is untouched', intval($line($po_id, 'ZZ scen PO other')['project_id']), 0);
    scenarioCheck('logged in the PO\'s history (ITFlow\'s audit log)', strpos(implode("\n", array_column(mysqli_fetch_all(mysqli_query($mysqli, "SELECT log_description FROM logs WHERE log_type = 'Purchase Order' AND log_entity_id = $po_id"), MYSQLI_ASSOC), 'log_description')), 'on project PRJ-') !== false, true);
    scenarioCheck('the PO page shows the line\'s project', strpos(mitPoItemFor($line($po_id, 'ZZ scen PO switch')), 'project.php?project_id=' . $prj) !== false, true);
    $card = preg_replace('/\s+/', ' ', strip_tags(str_replace('</div>', ' </div>', mitProcProjectCard($prj))));
    scenarioCheck('the project card counts them (2 x 100 + 5 x 4)', strpos($card, 'Hardware cost $220.00') !== false, true);
    if (strpos($card, 'Hardware cost $220.00') === false) {
        echo "       card: $card\n";
    }

    // One line only, onto our own project: the client's line is refused
    $result = mitPoSetProject(poRow($po_id), [intval($line($po_id, 'ZZ scen PO switch')['po_item_id'])], $ours);
    scenarioCheck('a client\'s line can\'t go on a project with no client', [$result['changed'], array_keys($result['problems'])], [0, ['ZZ scen PO switch']]);

    // Off again
    $result = mitPoSetProject(poRow($po_id), [intval($line($po_id, 'ZZ scen PO switch')['po_item_id'])], 0);
    scenarioCheck('"No project" takes a line off', [$result['changed'], intval($line($po_id, 'ZZ scen PO switch')['project_id'])], [1, 0]);

    // A project the agent can't reach / not open
    mysqli_query($mysqli, "UPDATE projects SET project_completed_at = NOW() WHERE project_id = $ours");
    $result = mitPoSetProject(poRow($po_id), [], $ours);
    scenarioCheck('a completed project is refused', [$result['changed'], array_keys($result['problems'])], [0, ['Project']]);

    // A cancelled PO
    $po2 = $made['pos'][] = poCreate($vendor, [$item(['name' => 'ZZ scen PO cancelled', 'quantity' => 1, 'unit_cost' => 5, 'client_id' => $client_id])], $error);
    poCancel(poRow($po2), $error);
    $result = mitPoSetProject(poRow($po2), [], $prj);
    scenarioCheck('a cancelled PO is refused', [$result['changed'], array_keys($result['problems'])], [0, ['PO']]);
} finally {
    mysqli_query($mysqli, "UPDATE settings SET config_po_approval = $old_approval WHERE company_id = 1");
    foreach (array_filter($made['pos']) as $id) {
        mysqli_query($mysqli, "DELETE FROM logs WHERE log_type = 'Purchase Order' AND log_entity_id = " . intval($id));
        mysqli_query($mysqli, "DELETE FROM purchase_orders WHERE po_id = " . intval($id));
    }
    foreach (array_filter($made['items']) as $id) {
        mysqli_query($mysqli, "DELETE FROM order_items WHERE order_item_id = " . intval($id));
    }
    foreach ($made['projects'] as $id) {
        mysqli_query($mysqli, "DELETE FROM projects WHERE project_id = " . intval($id));
    }
    mysqli_query($mysqli, "DELETE FROM product_stock WHERE stock_note LIKE '%ZZ scen PO%'");
    mysqli_query($mysqli, "DELETE FROM order_items WHERE order_item_name LIKE 'ZZ scen PO%'");
    scenarioCleanup();
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
