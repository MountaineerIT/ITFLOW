<?php
/*
 * Recurring tickets and invoices made ahead (functions/mit_recurring_lead.php, 26.11.3.2, M055), through ITFlow's real nightly job:
 * - a recurring ticket 5 days out with "create 5 days before" and a 9:30 time is raised tonight, booked for its run day at 9:30 for 90 min,
 *   onsite, and its next run moves on a step; one with 0 days ahead is not raised early;
 * - a recurring invoice 10 days out with "make it 10 days before" is made tonight, dated today, due on the Next invoice date + the client's
 *   net terms ("terms") or on the Next invoice date ("next"); one with 0 days is not made early.
 */
require __DIR__ . '/bootstrap.php';
if (!function_exists('mitRecurringLeadReady') || !mitRecurringLeadReady()) {
    echo "  skip - database update M055 not run\n";
    exit(0);
}
$today = date('Y-m-d');
$in5 = date('Y-m-d', strtotime('+5 days'));
$in10 = date('Y-m-d', strtotime('+10 days'));
[$client_id] = scenarioMakeClient('ZZ Scenario lead ' . date('His'));
$agent = intval($session_user_id);
$tag = 'ZZ Scen lead ' . date('His');

$recurring_ticket = function ($subject, $next) use ($mysqli, $client_id, $agent) {
    mysqli_query($mysqli, "INSERT INTO recurring_tickets SET recurring_ticket_subject = '" . escapeSql($subject) . "', recurring_ticket_details = 'x', recurring_ticket_priority = 'Low',
        recurring_ticket_frequency = 'Monthly', recurring_ticket_start_date = '$next', recurring_ticket_next_run = '$next', recurring_ticket_assigned_to = $agent,
        recurring_ticket_client_id = $client_id, recurring_ticket_created_by = $agent");
    return mysqli_insert_id($mysqli);
};
$recurring_invoice = function ($next) use ($mysqli, $client_id, $tag) {
    mysqli_query($mysqli, "INSERT INTO recurring_invoices SET recurring_invoice_prefix = 'ZZR-', recurring_invoice_number = " . random_int(900000, 999999) . ",
        recurring_invoice_scope = '" . escapeSql($tag) . "', recurring_invoice_frequency = 'month', recurring_invoice_next_date = '$next', recurring_invoice_anchor_day = DAY('$next'),
        recurring_invoice_status = 1, recurring_invoice_discount_amount = 0, recurring_invoice_amount = 10, recurring_invoice_currency_code = 'USD',
        recurring_invoice_auto_send = 0, recurring_invoice_client_id = $client_id, recurring_invoice_category_id = 0");
    $id = mysqli_insert_id($mysqli);
    mysqli_query($mysqli, "INSERT INTO recurring_invoice_items SET item_name = 'ZZ line', item_description = '', item_quantity = 1, item_price = 10, item_subtotal = 10,
        item_tax = 0, item_total = 10, item_order = 1, item_tax_id = 0, item_recurring_invoice_id = $id");
    return $id;
};

$t_ahead = $recurring_ticket("$tag ahead", $in5);
$t_plain = $recurring_ticket("$tag plain", $in5);
mitRecurringTicketPlanSave($t_ahead, ['mit_plan_present' => 1, 'mit_plan_time' => '09:30', 'mit_plan_minutes' => 90, 'mit_plan_onsite' => 1, 'mit_plan_lead' => 5]);
mitRecurringTicketPlanSave($t_plain, ['mit_plan_present' => 1, 'mit_plan_time' => '', 'mit_plan_minutes' => 60, 'mit_plan_onsite' => 0, 'mit_plan_lead' => 0]);
$i_terms = $recurring_invoice($in10);
$i_next = $recurring_invoice($in10);
$i_plain = $recurring_invoice($in10);
mitRecurringInvoicePlanSave($i_terms, ['mit_plan_present' => 1, 'mit_plan_lead' => 10, 'mit_plan_due' => 'terms']);
mitRecurringInvoicePlanSave($i_next, ['mit_plan_present' => 1, 'mit_plan_lead' => 10, 'mit_plan_due' => 'next']);
mitRecurringInvoicePlanSave($i_plain, ['mit_plan_present' => 1, 'mit_plan_lead' => 0, 'mit_plan_due' => 'next']);
$terms = intval(contractNetTerms($client_id, $in10));

try {
    scenarioCheck('days ahead kept under one repeat (weekly: at most 6)', mitRecurringLeadClamp(30, 'Weekly'), 6);

    exec('cd ' . escapeshellarg(getcwd() . '/cron') . ' && sudo -u www-data php nightly_tasks.php 2>&1', $out, $code_run);
    scenarioCheck('nightly job ran', $code_run, 0);

    // Tickets
    $made = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT ticket_id, ticket_schedule, ticket_schedule_end, ticket_onsite FROM tickets WHERE ticket_recurring_ticket_id = $t_ahead"));
    scenarioCheck('ticket made 5 days ahead', (bool) $made, true);
    if ($made) {
        $GLOBALS['scenario_tickets'][] = intval($made['ticket_id']);
        scenarioCheck('booked for the run day at 9:30', $made['ticket_schedule'], "$in5 09:30:00");
        scenarioCheck('for 90 minutes', $made['ticket_schedule_end'], "$in5 11:00:00");
        scenarioCheck('onsite', intval($made['ticket_onsite']), 1);
    }
    scenarioCheck('its next run moved on a month', scenarioValue("SELECT recurring_ticket_next_run FROM recurring_tickets WHERE recurring_ticket_id = $t_ahead"),
        date('Y-m-d', strtotime("$in5 +1 month")));
    scenarioCheck('0 days ahead: not made early', intval(scenarioValue("SELECT COUNT(*) FROM tickets WHERE ticket_recurring_ticket_id = $t_plain")), 0);

    // Invoices
    $inv = function ($rid) use ($mysqli) {
        return mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT invoice_id, invoice_date, invoice_due FROM invoices WHERE invoice_recurring_invoice_id = $rid"));
    };
    $a = $inv($i_terms);
    $b = $inv($i_next);
    scenarioCheck('invoice made 10 days ahead (terms)', (bool) $a, true);
    scenarioCheck('invoice made 10 days ahead (next)', (bool) $b, true);
    if ($a) {
        scenarioCheck('dated the day it is made', $a['invoice_date'], $today);
        scenarioCheck("due the Next invoice date + net $terms", $a['invoice_due'], date('Y-m-d', strtotime("$in10 +$terms days")));
    }
    if ($b) {
        scenarioCheck('due on the Next invoice date', $b['invoice_due'], $in10);
    }
    scenarioCheck('the recurring invoice moved on a month', scenarioValue("SELECT recurring_invoice_next_date FROM recurring_invoices WHERE recurring_invoice_id = $i_next"),
        date('Y-m-d', strtotime("$in10 +1 month")));
    scenarioCheck('0 days ahead: no invoice yet', $inv($i_plain), null);
} finally {
    foreach ([$i_terms, $i_next, $i_plain] as $rid) {
        foreach (scenarioColumn("SELECT invoice_id FROM invoices WHERE invoice_recurring_invoice_id = $rid") as $invoice_id) {
            mysqli_query($mysqli, "DELETE FROM invoice_items WHERE item_invoice_id = " . intval($invoice_id));
            mysqli_query($mysqli, "DELETE FROM history WHERE history_invoice_id = " . intval($invoice_id));
            mysqli_query($mysqli, "DELETE FROM invoices WHERE invoice_id = " . intval($invoice_id));
        }
        mysqli_query($mysqli, "DELETE FROM recurring_invoice_items WHERE item_recurring_invoice_id = $rid");
        mysqli_query($mysqli, "DELETE FROM history WHERE history_recurring_invoice_id = $rid");
        mysqli_query($mysqli, "DELETE FROM recurring_invoices WHERE recurring_invoice_id = $rid");
    }
    foreach (scenarioColumn("SELECT ticket_id FROM tickets WHERE ticket_recurring_ticket_id IN ($t_ahead, $t_plain)") as $id) {
        $GLOBALS['scenario_tickets'][] = intval($id);
    }
    scenarioCleanup();
    mysqli_query($mysqli, "DELETE FROM recurring_tickets WHERE recurring_ticket_id IN ($t_ahead, $t_plain)");
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
