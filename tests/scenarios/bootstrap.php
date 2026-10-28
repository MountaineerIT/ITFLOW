<?php
/*
 * Mountaineer IT - scenario checks: our code worked through against ITFlow's own functions on the TEST install (never production -
 * they write rows, then remove them). Each scenario-*.php file is one story; run them all with: php tests/scenarios/run.php [install]
 */
if (php_sapi_name() !== 'cli') {
    die("Run this from the command line.\n");
}
$root = $argv[1] ?? (getenv('ITFLOW_ROOT') ?: '/var/www/itflow');
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'; // functions that log the caller
$_SERVER['HTTP_USER_AGENT'] = $_SERVER['HTTP_USER_AGENT'] ?? 'scenario';
chdir($root);
require_once 'config.php';
require_once 'functions.php';
require_once 'includes/load_global_settings.php';
if (function_exists('mitDevMode') && !mitDevMode() && empty(getenv('MIT_SCENARIOS_ANYWAY')) && !preg_match('/\.test$/', (string) ($config_base_url ?? ''))) {
    die("Not a test install (no development mode, base URL not *.test) - scenarios write rows. Set MIT_SCENARIOS_ANYWAY=1 to run anyway.\n");
}
// An admin signed in, for functions that read the session
$session_user_id = $GLOBALS['session_user_id'] = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT user_id FROM users WHERE user_type = 1 AND user_status = 1 AND user_archived_at IS NULL ORDER BY user_id LIMIT 1"))[0]);
$session_is_admin = $GLOBALS['session_is_admin'] = true;
$session_name = $GLOBALS['session_name'] = 'Scenario check';
$GLOBALS['mit_scenario_failed'] = 0;

/** One expectation: prints ok / FAIL. */
function scenarioCheck($label, $got, $want) {
    $ok = is_float($want) || is_float($got) ? abs((float) $got - (float) $want) < 0.005 : $got === $want;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok ? '' : ' - got ' . var_export($got, true) . ', want ' . var_export($want, true)) . "\n";
    if (!$ok) {
        $GLOBALS['mit_scenario_failed']++;
    }
}

/** The first active client. */
function scenarioClient() {
    global $mysqli;
    return intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT client_id FROM clients WHERE client_archived_at IS NULL ORDER BY client_id LIMIT 1"))[0] ?? 0);
}

/* ---------- helpers for the ticket scenarios ---------- */

/** A throw-away client with a contact (email) - removed by scenarioCleanup(). Returns [client_id, contact_id]. */
function scenarioMakeClient($name) {
    global $mysqli;
    $n = escapeSql($name);
    mysqli_query($mysqli, "INSERT INTO clients SET client_name = '$n', client_currency_code = 'USD'");
    $client_id = mysqli_insert_id($mysqli);
    $email = 'zz-contact-' . $client_id . '@example.invalid';
    mysqli_query($mysqli, "INSERT INTO contacts SET contact_name = 'Zed Contact', contact_email = '$email', contact_primary = 1, contact_client_id = $client_id");
    $GLOBALS['scenario_clients'][] = $client_id;
    return [$client_id, mysqli_insert_id($mysqli)];
}

/** A ticket made the way add_ticket does (no board routing - the caller does that). */
function scenarioMakeTicket($client_id, $contact_id, array $set = []) {
    global $mysqli;
    mysqli_query($mysqli, "UPDATE settings SET config_ticket_next_number = LAST_INSERT_ID(config_ticket_next_number), config_ticket_next_number = config_ticket_next_number + 1 WHERE company_id = 1");
    $number = mysqli_insert_id($mysqli);
    $fields = array_merge(['ticket_prefix' => 'TCK-', 'ticket_number' => $number, 'ticket_source' => 'Agent', 'ticket_category' => 0,
        'ticket_subject' => 'ZZ scenario ticket', 'ticket_details' => 'x', 'ticket_priority' => 'Low', 'ticket_billable' => 0, 'ticket_status' => 1,
        'ticket_created_by' => 1, 'ticket_assigned_to' => 0, 'ticket_contact_id' => $contact_id, 'ticket_url_key' => randomString(32),
        'ticket_client_id' => $client_id, 'ticket_invoice_id' => 0, 'ticket_project_id' => 0], $set);
    $parts = [];
    foreach ($fields as $k => $v) {
        $parts[] = "$k = " . (is_int($v) ? $v : "'" . escapeSql($v) . "'");
    }
    mysqli_query($mysqli, "INSERT INTO tickets SET " . implode(', ', $parts));
    $id = mysqli_insert_id($mysqli);
    $GLOBALS['scenario_tickets'][] = $id;
    return $id;
}

function scenarioValue($sql) {
    global $mysqli;
    $row = mysqli_fetch_row(mysqli_query($mysqli, $sql));
    return $row ? $row[0] : null;
}

function scenarioColumn($sql) {
    global $mysqli;
    return array_map(function ($r) { return $r[0]; }, mysqli_fetch_all(mysqli_query($mysqli, $sql)));
}

/** Remove the tickets and clients a scenario made (with what hangs off them). */
function scenarioCleanup() {
    global $mysqli;
    $tickets = implode(',', array_map('intval', $GLOBALS['scenario_tickets'] ?? [])) ?: '0';
    foreach (['ticket_replies' => 'ticket_reply_ticket_id', 'ticket_history' => 'ticket_history_ticket_id', 'ticket_watchers' => 'watcher_ticket_id',
        'mit_ticket_boards' => 'ticket_id', 'mit_board_moves' => 'ticket_id', 'mit_ticket_techs' => 'ticket_id', 'mit_ticket_mail_choices' => 'ticket_id',
        'mit_project_ticket_sla' => 'ticket_id', 'mit_ticket_no_response' => 'ticket_id', 'mit_ct_surveys' => 'ticket_id', 'notifications' => 'notification_entity_id'] as $table => $col) {
        try {
            mysqli_query($mysqli, "DELETE FROM $table WHERE $col IN ($tickets)");
        } catch (Throwable $e) {
        }
    }
    mysqli_query($mysqli, "DELETE FROM tickets WHERE ticket_id IN ($tickets)");
    $clients = implode(',', array_map('intval', $GLOBALS['scenario_clients'] ?? [])) ?: '0';
    mysqli_query($mysqli, "DELETE FROM notifications WHERE notification_client_id IN ($clients)");
    mysqli_query($mysqli, "DELETE FROM contacts WHERE contact_client_id IN ($clients)");
    mysqli_query($mysqli, "DELETE FROM logs WHERE log_client_id IN ($clients)");
    mysqli_query($mysqli, "DELETE FROM clients WHERE client_id IN ($clients)");
    mysqli_query($mysqli, "DELETE FROM email_queue WHERE email_subject LIKE '%ZZ scenario ticket%'");
}
