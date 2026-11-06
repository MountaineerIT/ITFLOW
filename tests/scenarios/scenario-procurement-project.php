<?php
/*
 * A PO's lines put on a project after it was ordered (functions/mit_procurement_extras.php mitPoSetProject, 26.11.3.5 - the user found no
 * way to): an ordered PO's client line goes on the client's project (and its item with it), a line for nobody takes the project's client
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
$old_approval = (string) mitSetting('procurement_approval_on', '1');
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
        if ($row['name'] === $name) {
            return $row;
        }
    }
    return null;
};
try {
    mitSetSetting('procurement_approval_on', '0');
    $prj = $project($client_id);
    $ours = $project(0);

    // An ordered PO: a line for the client, one for nobody (stock), one for another client, one for nobody received already
    $ids = [$item(['name' => 'ZZ scen PO switch', 'quantity' => 2, 'unit_cost' => 100, 'client_id' => $client_id]),
        $item(['name' => 'ZZ scen PO cable', 'quantity' => 5, 'unit_cost' => 4, 'client_id' => 0]),
        $item(['name' => 'ZZ scen PO other', 'quantity' => 1, 'unit_cost' => 50, 'client_id' => $other_client]),
        $item(['name' => 'ZZ scen PO got', 'quantity' => 1, 'unit_cost' => 10, 'client_id' => 0])];
    $po_id = $made['pos'][] = mitPoCreate($ids, $vendor);
    mitPoSubmit(mitPoRow($po_id));
    mitPoMarkOrdered(mitPoRow($po_id), date('Y-m-d'), '');
    mitPoReceive([$line($po_id, 'ZZ scen PO got')['line_id'] => ['qty' => 1]], []);
    scenarioCheck('the PO is ordered (partly received)', mitPoRow($po_id)['status'], 'partial');

    $result = mitPoSetProject(mitPoRow($po_id), [], $prj);
    scenarioCheck('two lines put on the project', $result['changed'], 2);
    scenarioCheck('another client\'s line and a received line for nobody are left, with why', array_keys($result['problems']), ['ZZ scen PO other', 'ZZ scen PO got']);
    scenarioCheck('the client\'s line is on it', intval($line($po_id, 'ZZ scen PO switch')['project_id']), $prj);
    $cable = $line($po_id, 'ZZ scen PO cable');
    scenarioCheck('the line for nobody is on it and takes the project\'s client', [intval($cable['project_id']), intval($cable['client_id'])], [$prj, $client_id]);
    scenarioCheck('its item follows', array_map('intval', mysqli_fetch_row(mysqli_query($mysqli, "SELECT project_id, client_id FROM mit_procurement_items WHERE item_id = " . intval($cable['proc_item_id'])))), [$prj, $client_id]);
    scenarioCheck('the other client\'s line is untouched', intval($line($po_id, 'ZZ scen PO other')['project_id']), 0);
    scenarioCheck('logged on the PO', strpos(implode("\n", array_column(mysqli_fetch_all(mysqli_query($mysqli, "SELECT description FROM mit_po_history WHERE po_id = $po_id"), MYSQLI_ASSOC), 'description')), 'on project PRJ-') !== false, true);
    $card = preg_replace('/\s+/', ' ', strip_tags(str_replace('</div>', ' </div>', mitProcProjectCard($prj))));
    scenarioCheck('the project card counts them (2 x 100 + 5 x 4)', strpos($card, 'Hardware cost $220.00') !== false, true);
    if (strpos($card, 'Hardware cost $220.00') === false) {
        echo "       card: $card\n";
    }

    // One line only, onto our own project: the client's line is refused
    $result = mitPoSetProject(mitPoRow($po_id), [intval($line($po_id, 'ZZ scen PO switch')['line_id'])], $ours);
    scenarioCheck('a client\'s line can\'t go on a project with no client', [$result['changed'], array_keys($result['problems'])], [0, ['ZZ scen PO switch']]);

    // Off again
    $result = mitPoSetProject(mitPoRow($po_id), [intval($line($po_id, 'ZZ scen PO switch')['line_id'])], 0);
    scenarioCheck('"No project" takes a line off', [$result['changed'], intval($line($po_id, 'ZZ scen PO switch')['project_id'])], [1, 0]);

    // A project the agent can't reach / not open
    mysqli_query($mysqli, "UPDATE projects SET project_completed_at = NOW() WHERE project_id = $ours");
    $result = mitPoSetProject(mitPoRow($po_id), [], $ours);
    scenarioCheck('a completed project is refused', [$result['changed'], array_keys($result['problems'])], [0, ['Project']]);

    // A cancelled PO
    $po2 = $made['pos'][] = mitPoCreate([$item(['name' => 'ZZ scen PO cancelled', 'quantity' => 1, 'unit_cost' => 5, 'client_id' => $client_id])], $vendor);
    mitPoCancel(mitPoRow($po2));
    $result = mitPoSetProject(mitPoRow($po2), [], $prj);
    scenarioCheck('a cancelled PO is refused', [$result['changed'], array_keys($result['problems'])], [0, ['PO']]);
} finally {
    mitSetSetting('procurement_approval_on', $old_approval);
    foreach ($made['pos'] as $id) {
        mysqli_query($mysqli, "DELETE FROM mit_purchase_orders WHERE po_id = " . intval($id));
    }
    foreach ($made['items'] as $id) {
        mysqli_query($mysqli, "DELETE FROM mit_procurement_items WHERE item_id = " . intval($id));
    }
    foreach ($made['projects'] as $id) {
        mysqli_query($mysqli, "DELETE FROM projects WHERE project_id = " . intval($id));
    }
    mysqli_query($mysqli, "DELETE FROM product_stock WHERE stock_note LIKE '%ZZ scen PO%'");
    scenarioCleanup();
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
