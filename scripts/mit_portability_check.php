<?php

/*
 * Mountaineer IT - can this database still go back to stock ITFlow?
 *
 * A backup made here should restore into ITFlow's own release and keep working (DEPLOYMENT.md,
 * "Moving to stock ITFlow"). That holds as long as our changes to the database only ever add:
 * our own mit_ tables, and columns on ITFlow's tables that stock ITFlow can ignore. This compares
 * the live database with ITFlow's schema for this code (db.sql - which we never edit) and says
 * what would break, and lists the things that stop working after a move.
 *
 * Usage: php scripts/mit_portability_check.php     (exit code 0 = portable, 1 = not)
 *
 * Run it after every feature change that touches the database, after every ITFlow update
 * merge, and before promoting develop to production (CLAUDE.md).
 */

chdir(dirname(__FILE__));

if (php_sapi_name() !== 'cli') {
    die("This script must be run from the command line.\n");
}

require_once "../config.php";
require_once "../includes/database_version.php";

$problems = [];
$warnings = [];
$notes = [];

/**
 * ITFlow's tables and columns from db.sql: table => [column => definition]. Its unique keys go in
 * $unique_keys: table => [key name => true].
 */
function mitStockSchema($file, &$unique_keys = []) {
    $tables = [];
    $unique_keys = [];
    $table = null;
    foreach (file($file) as $line) {
        if (preg_match('/^CREATE TABLE `([^`]+)`/', $line, $m)) {
            $table = $m[1];
            $tables[$table] = [];
        } elseif ($table && preg_match('/^\s+UNIQUE KEY `([^`]+)`/', $line, $m)) {
            $unique_keys[$table][$m[1]] = true;
        } elseif ($table && preg_match('/^\s+`([^`]+)`\s+(.+?),?\s*$/', $line, $m)) {
            $tables[$table][$m[1]] = $m[2];
        } elseif ($table && preg_match('/^\)/', $line)) {
            $table = null;
        }
    }
    return $tables;
}

$stock_unique = [];
$stock = mitStockSchema(dirname(__DIR__) . '/db.sql', $stock_unique);
if (count($stock) < 50) {
    fwrite(STDERR, "Could not read ITFlow's schema from db.sql.\n");
    exit(1);
}

$db = mysqli_real_escape_string($mysqli, $database);
$live = [];
$result = mysqli_query($mysqli, "SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, GENERATION_EXPRESSION
    FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '$db' ORDER BY TABLE_NAME, ORDINAL_POSITION");
while ($row = mysqli_fetch_assoc($result)) {
    $live[$row['TABLE_NAME']][$row['COLUMN_NAME']] = $row;
}

// The database should be at the version this code expects, or the comparison is with the wrong schema
$version_row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT config_current_database_version FROM settings WHERE company_id = 1"));
$version = $version_row['config_current_database_version'] ?? 'unknown';
if ($version !== LATEST_DATABASE_VERSION) {
    $warnings[] = "The database is at $version but this code expects " . LATEST_DATABASE_VERSION . " - run the database update first, then check again.";
}

// 1. Everything ITFlow has must still be there
foreach ($stock as $table => $columns) {
    if (!isset($live[$table])) {
        $problems[] = "ITFlow's table `$table` is missing.";
        continue;
    }
    foreach ($columns as $column => $definition) {
        if (!isset($live[$table][$column])) {
            $problems[] = "ITFlow's column `$table`.`$column` is missing.";
            continue;
        }
        // Same type as db.sql ("int(11)", "varchar(200)" ...). A WARN, not a FAIL: ITFlow's own updates have not always
        // matched db.sql exactly, but we never change ITFlow's columns, so a difference needs a look
        $stock_type = preg_match('/^([a-z]+(\([^)]*\))?( unsigned)?( zerofill)?)/i', $definition, $m) ? strtolower($m[1]) : '';
        $live_type = strtolower((string) $live[$table][$column]['COLUMN_TYPE']);
        if ($stock_type !== $live_type && preg_replace('/\(\d+\)/', '', $stock_type) !== preg_replace('/\(\d+\)/', '', $live_type)) {
            $warnings[] = "ITFlow's column `$table`.`$column` is $live_type here, $stock_type in db.sql.";
        }
    }
}

// 2. Columns we added to ITFlow's tables: stock ITFlow never fills them, so they must accept being left out. A column neither
// db.sql nor our updates (admin/database_updates/mountaineerit/) mention came from somewhere else - added by hand, another tool
$our_updates = '';
foreach (glob(dirname(__DIR__) . '/admin/database_updates/mountaineerit/M*.php') as $file) {
    $our_updates .= file_get_contents($file);
}
foreach ($stock as $table => $columns) {
    foreach ($live[$table] ?? [] as $column => $info) {
        if (isset($columns[$column])) {
            continue;
        }
        $optional = $info['IS_NULLABLE'] === 'YES' || $info['COLUMN_DEFAULT'] !== null
            || stripos($info['EXTRA'], 'auto_increment') !== false || !empty($info['GENERATION_EXPRESSION']);
        if (!$optional) {
            $whose = strpos($our_updates, $column) !== false ? 'Our column' : 'Unknown column (not ITFlow\'s, not added by our updates)';
            $problems[] = "$whose `$table`.`$column` is NOT NULL with no default - stock ITFlow's inserts into `$table` would fail" . ($whose === 'Our column' ? '.' : ' (and ITFlow\'s own do now).');
        } else {
            $notes[] = "Extra column `$table`.`$column` (" . (strpos($our_updates, $column) !== false ? 'ours' : 'not ITFlow\'s, not added by our updates') . ' - stock ITFlow ignores it).';
        }
    }
}

// 3. Tables that are not ITFlow's must be ours (mit_), so it is clear what can be ignored or dropped
foreach (array_keys($live) as $table) {
    if (!isset($stock[$table]) && strpos($table, 'mit_') !== 0) {
        $warnings[] = "Table `$table` is neither ITFlow's (db.sql) nor ours (mit_) - check where it came from.";
    }
}

// 3b. No unique keys or triggers of ours on ITFlow's tables - either could refuse rows stock ITFlow writes
$result = mysqli_query($mysqli, "SELECT DISTINCT TABLE_NAME, INDEX_NAME FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = '$db' AND NON_UNIQUE = 0 AND INDEX_NAME <> 'PRIMARY'");
while ($row = mysqli_fetch_assoc($result)) {
    if (isset($stock[$row['TABLE_NAME']]) && !isset($stock_unique[$row['TABLE_NAME']][$row['INDEX_NAME']])) {
        $problems[] = "Unique key `{$row['INDEX_NAME']}` on ITFlow's table `{$row['TABLE_NAME']}` is not ITFlow's - stock ITFlow could hit it.";
    }
}
$result = mysqli_query($mysqli, "SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = '$db'");
while ($row = mysqli_fetch_assoc($result)) {
    if (isset($stock[$row['EVENT_OBJECT_TABLE']])) {
        $problems[] = "Trigger `{$row['TRIGGER_NAME']}` on ITFlow's table `{$row['EVENT_OBJECT_TABLE']}` - ITFlow uses none.";
    }
}

// 4. ITFlow's tables must not depend on ours (a foreign key into mit_ would block stock ITFlow's deletes/inserts)
$result = mysqli_query($mysqli, "SELECT TABLE_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = '$db' AND REFERENCED_TABLE_NAME LIKE 'mit\\_%'");
while ($row = mysqli_fetch_assoc($result)) {
    if (strpos($row['TABLE_NAME'], 'mit_') !== 0) {
        $problems[] = "ITFlow's table `{$row['TABLE_NAME']}` has a foreign key ({$row['CONSTRAINT_NAME']}) into our `{$row['REFERENCED_TABLE_NAME']}`.";
    }
}

// 4b. Our tables may point at ITFlow's, but only so that ITFlow's deletes carry on (CASCADE / SET NULL) - a
// RESTRICT key would stop stock ITFlow deleting a ticket or user that one of our rows mentions
$result = mysqli_query($mysqli, "SELECT TABLE_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME, DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = '$db' AND TABLE_NAME LIKE 'mit\\_%'");
while ($row = mysqli_fetch_assoc($result)) {
    if (!isset($stock[$row['REFERENCED_TABLE_NAME']])) {
        continue;
    }
    if (in_array($row['DELETE_RULE'], ['CASCADE', 'SET NULL'], true)) {
        $notes[] = "Our `{$row['TABLE_NAME']}` follows ITFlow's `{$row['REFERENCED_TABLE_NAME']}` ({$row['DELETE_RULE']} on delete).";
    } else {
        $problems[] = "Our `{$row['TABLE_NAME']}` has a {$row['DELETE_RULE']} foreign key ({$row['CONSTRAINT_NAME']}) into ITFlow's `{$row['REFERENCED_TABLE_NAME']}` - stock ITFlow's deletes would fail.";
    }
}

// 5. Our database updates all applied (a pending one means the comparison is with a half-updated database)
$mit_files = array_map(function ($file) {
    return basename($file, '.php');
}, glob(dirname(__DIR__) . '/admin/database_updates/mountaineerit/M[0-9][0-9][0-9].php') ?: []);
$mit_done = [];
try {
    $result = mysqli_query($mysqli, "SELECT mit_update_name FROM mit_database_updates");
    while ($row = mysqli_fetch_row($result)) {
        $mit_done[] = $row[0];
    }
} catch (Throwable $e) {
    // the table comes with the first of our updates
}
if ($pending = array_diff($mit_files, $mit_done)) {
    $warnings[] = "Our database update" . (count($pending) === 1 ? ' ' : 's ') . implode(', ', $pending) . " not applied yet - run php scripts/update_cli.php --update_db, then check again.";
}

// What stops working after a move (works again on moving back) - see DEPLOYMENT.md
$settings = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT config_mail_oauth_client_secret, config_smtp_provider, config_imap_provider FROM settings WHERE company_id = 1"));
$after = [];
if (($settings['config_mail_oauth_client_secret'] ?? '') === 'mit-m365-certificate') {
    $warnings[] = "Mail signs in to Microsoft 365 with our certificate: on stock ITFlow mail stops until a client secret is set (see the list below).";
    $after[] = "Microsoft 365 email: signs in with our certificate and sends/receives through Graph. On stock ITFlow add a client secret to the app in Entra and paste it under Settings > Mail > OAuth (and SMTP AUTH / IMAP must be enabled for the mailbox).";
}
// A table or column that is not there yet (an update not run) counts as none - mysqli throws on it in PHP 8.1+
$count = function ($sql) use ($mysqli) {
    try {
        $r = mysqli_query($mysqli, $sql);
        return $r ? intval(mysqli_fetch_row($r)[0]) : 0;
    } catch (Throwable $e) {
        return 0;
    }
};
if ($n = $count("SELECT COUNT(*) FROM mit_outlook_links")) {
    $after[] = "Outlook calendars: $n connected - sync stops; ITFlow's events already in Outlook stay until deleted (they have the ITFlow category).";
}
if ($n = $count("SELECT COUNT(*) FROM tickets WHERE ticket_schedule_end IS NOT NULL")) {
    $after[] = "Schedule lengths: $n scheduled tickets have an end time - stock ITFlow shows its default length instead.";
}
$n = $count("SELECT COUNT(*) FROM recurring_tickets WHERE recurring_ticket_by_day IS NOT NULL") + $count("SELECT COUNT(*) FROM calendar_events WHERE event_repeat_by_day IS NOT NULL");
if ($n) {
    $after[] = "Repeats by weekday: $n recurring tickets / events repeat on e.g. the third Wednesday - stock ITFlow repeats them on the same date each month instead.";
}
$n = $count("SELECT COUNT(*) FROM mit_purchase_orders");
if ($n || $count("SELECT COUNT(*) FROM modules WHERE module_name = 'module_procurement'")) {
    $after[] = "Procurement: $n purchase order" . ($n === 1 ? '' : 's') . " and the items to order are not shown by stock ITFlow (they stay in the mit_ tables; PO PDFs in uploads/mit_po). Admin > Roles keeps an unused \"Procurement\" permission - delete its row from the modules table if it bothers you. Assets, stock and vendor-bill expenses made when receiving are ordinary ITFlow records and stay.";
}
$n = $count("SELECT COUNT(*) FROM mit_timesheets");
if ($n || $count("SELECT COUNT(*) FROM modules WHERE module_name = 'module_timesheets'")) {
    $after[] = "Timesheets: $n timesheet" . ($n === 1 ? '' : 's') . " and the hours on charge codes are not shown by stock ITFlow (they stay in mit_timesheets / mit_time_entries / mit_charge_codes / mit_timesheet_people), and nor are PTO / sick balances (mit_leave_*) - export the Timesheets & Payroll report first. Ticket time itself is ITFlow's and stays. Admin > Roles keeps an unused \"Timesheets\" permission.";
}
if ($count("SELECT COUNT(*) FROM modules WHERE module_name = 'module_dispatch'")) {
    $after[] = "Dispatch: the board goes away (its bookings and assignments are ordinary ITFlow ticket fields and stay). Admin > Roles keeps an unused \"Dispatch\" permission - delete its row from the modules table if it bothers you.";
}
if ($n = $count("SELECT COUNT(*) FROM mit_reimbursements")) {
    $after[] = "Reimbursements: $n mileage / purchase entries on tickets are not shown by stock ITFlow (they stay in mit_reimbursements, receipts in uploads/mit_receipts) - export the payroll report first. The trips and expenses they made are ordinary ITFlow records and stay.";
}
if ($n = $count("SELECT COUNT(*) FROM mit_ts_work_periods") + $count("SELECT COUNT(*) FROM mit_ts_attestations")) {
    $after[] = "California time records: start / meal / end times, meal-period answers, change history and signed attestations ($n rows) are not shown by stock ITFlow - they stay in mit_ts_* (keep them at least 3 years); export the payroll detail report first.";
}
if ($n = $count("SELECT COUNT(*) FROM mit_ninja_devices WHERE asset_id IS NOT NULL")) {
    $after[] = "NinjaOne: $n assets linked to NinjaOne devices - the sync, mapping and NinjaOne buttons stop; the assets stay (with the [NinjaOne Sync] lines in their notes, which the n8n workflow reads).";
}
if ($n = $count("SELECT COUNT(*) FROM mit_ai_sources")) {
    $after[] = "AI helper: $n tickets / documents in its library - stock ITFlow has no helper that searches them (its own AI features keep working). The library stays unused in mit_ai_*.";
}
if (($n = $count("SELECT COUNT(*) FROM mit_ai_conversations")) > 0) {
    $after[] = "AI helper: $n Ask AI conversation(s) - stock ITFlow has no Ask AI panel. They stay unused in mit_ai_conversations / mit_ai_messages.";
}
if ($n = $count("SELECT COUNT(*) FROM mit_align_clients") + $count("SELECT COUNT(*) FROM mit_align_devices") + $count("SELECT COUNT(*) FROM mit_align_licenses")) {
    $after[] = "MSP Align: $n clients / assets / licenses carry MSP Align's scores and status - stock ITFlow shows none of it and has no links to MSP Align (MSP Align itself keeps working - it reads ITFlow's own API). The copies stay unused in mit_align_*.";
}
if ($n = $count("SELECT COUNT(*) FROM tickets WHERE ticket_project_id > 0 AND ticket_resolved_at IS NULL AND ticket_closed_at IS NULL")) {
    $after[] = "Project tickets: $n open ticket" . ($n === 1 ? '' : 's') . " in projects show on the Tickets board again in stock ITFlow, and keep no SLA (we took it off) - give one an SLA by hand on the ticket if it needs it. Calendar bookings go back to the tech's color.";
}
if ($n = $count("SELECT COUNT(DISTINCT ticket_id) FROM mit_ticket_mail_choices")) {
    $after[] = "Email reply recipients: $n ticket" . ($n === 1 ? ' has' : 's have') . " a saved choice of who gets email replies - stock ITFlow emails the contact and every watcher.";
}
if ($n = $count("SELECT COUNT(*) FROM mit_ticket_parts")) {
    $after[] = "Ticket parts: $n part" . ($n === 1 ? '' : 's') . " on tickets are not shown by stock ITFlow (billed ones are ordinary invoice lines and stay). A product's vendor and cost are ITFlow's own fields (26.11) and stay.";
}
if ($n = $count("SELECT COUNT(*) FROM mit_ticket_techs tt JOIN tickets t ON t.ticket_id = tt.ticket_id WHERE t.ticket_resolved_at IS NULL AND t.ticket_closed_at IS NULL")) {
    $after[] = "Additional agents: $n on open tickets - stock ITFlow keeps only the assigned agent; the others and their own scheduled times are not shown.";
}
if ($n = $count("SELECT COUNT(*) FROM mit_ticket_boards b JOIN tickets t ON t.ticket_id = b.ticket_id WHERE t.ticket_resolved_at IS NULL AND t.ticket_closed_at IS NULL")) {
    $after[] = "Service boards: $n open ticket" . ($n === 1 ? ' is' : 's are') . " on a board - stock ITFlow has no boards, so they are all in one Tickets list again (filter by category instead); routing rules and board notifications stop. Categories are ITFlow's and stay.";
}
if ($n = $count("SELECT COUNT(*) FROM mit_reply_times")) {
    $after[] = "Times on ticket notes: $n note" . ($n === 1 ? ' has' : 's have') . " its date and start / end times - stock ITFlow keeps each note's length only, and timesheets are gone anyway.";
}
if ($n = $count("SELECT COUNT(*) FROM mit_timesheet_people WHERE pay_type = 'contractor'")) {
    $after[] = "Contractors: $n person / people set as Contractor on timesheets - only meaningful to our timesheets.";
}
if ($n = $count("SELECT COUNT(*) FROM mit_boards WHERE board_assign = 'round_robin' AND board_archived_at IS NULL")) {
    $after[] = "Board auto-assign: $n board" . ($n === 1 ? ' hands' : 's hand') . " new tickets out round-robin - in stock ITFlow new tickets stay unassigned until someone takes them. The Board Performance report and the dashboard's board filter go too.";
}
if ($n = $count("SELECT COUNT(*) FROM mit_role_board_access WHERE limited = 1")) {
    $after[] = "Boards by role: $n role" . ($n === 1 ? ' is' : 's are') . " limited to chosen service boards - in stock ITFlow every role sees every ticket again (ITFlow's own permissions and client access stay).";
}
if ($n = $count("SELECT COUNT(*) FROM mit_qbo_batch_invoices WHERE entry_status != 'removed'") + $count("SELECT COUNT(*) FROM mit_invoice_approvals") + $count("SELECT COUNT(*) FROM mit_qbo_manual")) {
    $after[] = "QuickBooks Online: $n invoice approvals / batch entries / invoices marked \"Already in QuickBooks\" - stock ITFlow has no approvals, batches or QuickBooks link, so invoices unlock, ITFlow emails invoices and reminders again, and payments made in QuickBooks stop coming back (the ones already brought back are ordinary ITFlow payments and stay). Disconnect QuickBooks first (Admin > Integrations).";
}
if ($n = $count("SELECT COUNT(*) FROM mit_usage_links")) {
    $after[] = "Usage connectors: $n Bitwarden / Ingram Micro link" . ($n === 1 ? '' : 's') . " - the daily counts from NinjaOne, Bitwarden and Ingram Micro stop (the keys stay encrypted in mit_usage_links / mit_settings; delete them if you move).";
}
if ($n = $count("SELECT COUNT(*) FROM mit_usage_lines")) {
    $after[] = "Usage billing: $n recurring invoice line" . ($n === 1 ? ' is' : 's are') . " billed by a count - stock ITFlow bills the fixed quantity on the recurring invoice again (set it by hand each month), and the counts, the API endpoint and Usage Review stop. Invoices already made keep their quantities.";
}
$n = $count("SELECT COUNT(*) FROM quote_items WHERE item_cost IS NOT NULL OR item_image IS NOT NULL") + $count("SELECT COUNT(*) FROM products WHERE product_image IS NOT NULL");
if ($n) {
    $after[] = "Quote pricing and images: $n quote lines / products have our cost or an image - stock ITFlow shows the customer prices only, without the images (the files stay in uploads/mit_images).";
}
if ($n = $count("SELECT COUNT(*) FROM mit_offices WHERE office_archived_at IS NULL")) {
    $after[] = "Offices: $n other office" . ($n === 1 ? '' : 's') . " - stock ITFlow has the one company address: trips start from the agent's last start or the company again, ticket emails show the company's address and phone (the offices and who works where stay in mit_offices / mit_user_offices).";
}
if ($n = $count("SELECT COUNT(*) FROM mit_user_preferences")) {
    $after[] = "Per-person settings - look, calendar view, agent colors, ticket page layout, descriptions ticked Read, timesheet choices ($n saved) - are not used by stock ITFlow.";
}

echo "Stock ITFlow portability check - database $version, ITFlow schema " . LATEST_DATABASE_VERSION . " (db.sql)\n\n";
foreach ($problems as $line) {
    echo "  FAIL  $line\n";
}
foreach ($warnings as $line) {
    echo "  WARN  $line\n";
}
foreach ($notes as $line) {
    echo "  ok    $line\n";
}
echo $problems ? "\nNOT portable - a backup of this database would break stock ITFlow. Fix the FAIL lines.\n"
    : "\nPortable - a backup of this database restores into stock ITFlow " . LATEST_DATABASE_VERSION . " (or newer) and keeps working.\n";
if ($after) {
    echo "\nAfter moving to stock ITFlow, redo or expect:\n";
    foreach ($after as $line) {
        echo "  - $line\n";
    }
}
exit($problems ? 1 : 0);
