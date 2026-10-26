<?php

/*
 * Mountaineer IT - does our data still agree with ITFlow's? Read only; safe on production.
 *
 * Feature tests run on test data, one feature at a time. These checks run on the real database and look across the seams
 * between our features and ITFlow's own records - the places where two systems keep the same money or the same thing, and
 * either one can change it. Run by scripts/mit_consistency_check.php (by hand, after a merge, before promoting) and daily by
 * the cron job mit_consistency (cron/mit_consistency.php - a 'fail' makes the job fail, so Cron Watch emails the admins).
 *
 * mitConsistencyChecks() returns findings: ['area', 'level' => 'fail' | 'warn', 'text', 'items' => [examples]]. 'fail' = the
 * two sides disagree about money or a record that must match; 'warn' = left over or worth a look. Each check skips itself
 * when its feature's database update has not run.
 */

/** Does this table exist? */
function mitConsistencyTable($table) {
    global $mysqli;
    static $tables = null;
    if ($tables === null) {
        $tables = array_flip(array_column(mysqli_fetch_all(mysqli_query($mysqli, "SHOW TABLES")), 0));
    }
    return isset($tables[$table]);
}

/** Rows of a query, at most $limit. */
function mitConsistencyRows($sql, $limit = 500) {
    global $mysqli;
    return mysqli_fetch_all(mysqli_query($mysqli, "$sql LIMIT " . intval($limit)), MYSQLI_ASSOC);
}

/** All checks - findings, worst first. */
function mitConsistencyChecks() {
    $findings = [];
    $add = function ($area, $level, $text, array $items = []) use (&$findings) {
        $findings[] = ['area' => $area, 'level' => $level, 'text' => $text, 'items' => array_slice($items, 0, 10), 'count' => count($items)];
    };
    foreach (['mitConsistencyDatabase', 'mitConsistencyPayroll', 'mitConsistencyQbo', 'mitConsistencyParts', 'mitConsistencyUsage',
                 'mitConsistencyBoards', 'mitConsistencyOutlook', 'mitConsistencySettings', 'mitConsistencyOrphans'] as $check) {
        try {
            $check($add);
        } catch (Throwable $e) {
            $add($check, 'fail', 'The check itself failed: ' . $e->getMessage());
        }
    }
    usort($findings, function ($a, $b) {
        return [$a['level'] !== 'fail', $a['area']] <=> [$b['level'] !== 'fail', $b['area']];
    });
    return $findings;
}

/** ITFlow's and our database updates have all run; tables use ITFlow's collation. */
function mitConsistencyDatabase(callable $add) {
    global $mysqli;
    $root = dirname(__DIR__);
    if (defined('LATEST_DATABASE_VERSION') && defined('CURRENT_DATABASE_VERSION') && version_compare(CURRENT_DATABASE_VERSION, LATEST_DATABASE_VERSION, '<')) {
        $add('Database', 'fail', 'ITFlow\'s database is ' . CURRENT_DATABASE_VERSION . ' but the code expects ' . LATEST_DATABASE_VERSION . ' - run php scripts/update_cli.php --update_db.');
    }
    if (!mitConsistencyTable('mit_settings')) {
        $add('Database', 'fail', 'Our settings table (mit_settings) is missing - our database updates have not run (our cron jobs wait for them). Run php scripts/update_cli.php --update_db.');
    }
    if (mitConsistencyTable('mit_database_updates')) {
        $done = array_flip(array_column(mysqli_fetch_all(mysqli_query($mysqli, "SELECT mit_update_name FROM mit_database_updates")), 0));
        $missing = [];
        foreach (glob("$root/admin/database_updates/mountaineerit/M*.php") as $file) {
            if (!isset($done[basename($file, '.php')]) && !isset($done[basename($file)])) {
                $missing[] = basename($file, '.php');
            }
        }
        if ($missing) {
            $add('Database', 'fail', 'Our database updates have not all run - run php scripts/update_cli.php --update_db.', $missing);
        }
    } else {
        $add('Database', 'fail', 'Our database update table (mit_database_updates) is missing - our updates never ran.');
    }
    $mixed = array_column(mitConsistencyRows("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_COLLATION <> 'utf8mb4_general_ci'
        AND TABLE_NAME NOT IN (SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLLATION_NAME LIKE '%\\_bin')"), 'TABLE_NAME');
    if ($mixed) {
        $add('Database', 'warn', 'Tables not on ITFlow\'s collation (joins with them can fail - our database runner converts them; run it).', $mixed);
    }
}

/** Mileage and purchases paid with payroll (functions/mit_reimbursements.php) against ITFlow's reimbursements and expenses. */
function mitConsistencyPayroll(callable $add) {
    if (!function_exists('mitReimbItems') || !mitReimbReady() || !mitReimbItflowPaysBack() || !mitConsistencyTable('reimbursements')) {
        return;
    }
    // Every reimbursement is the sum of its expenses (ITFlow's own rule - an expense changed after it was paid breaks it)
    $off = mitConsistencyRows("SELECT r.reimbursement_id, r.reimbursement_reference, r.reimbursement_amount, COALESCE(SUM(e.expense_amount), 0) AS paid_for
        FROM reimbursements r LEFT JOIN expenses e ON e.expense_reimbursement_id = r.reimbursement_id GROUP BY r.reimbursement_id
        HAVING ABS(r.reimbursement_amount - paid_for) > 0.004");
    if ($off) {
        $add('Payroll', 'fail', 'Reimbursements whose amount is not the sum of the expenses they paid back (an expense was changed or deleted afterwards).',
            array_map(function ($r) {
                return "#{$r['reimbursement_id']} {$r['reimbursement_reference']}: paid {$r['reimbursement_amount']}, expenses now {$r['paid_for']}";
            }, $off));
    }
    // Payroll payments belong to an approved timesheet
    $bad = [];
    foreach (mitConsistencyRows("SELECT r.reimbursement_id, r.reimbursement_reference, r.reimbursement_user_id, s.status FROM reimbursements r
            LEFT JOIN mit_timesheets s ON s.sheet_id = CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(r.reimbursement_reference, 'timesheet ', -1), ' ', 1) AS UNSIGNED)
            WHERE r.reimbursement_reference LIKE 'Payroll - timesheet %'", 5000) as $r) {
        if (strpos($r['reimbursement_reference'], 'Payroll - timesheet 0 ') !== 0 && $r['status'] !== 'approved') {
            $bad[] = "#{$r['reimbursement_id']} {$r['reimbursement_reference']}: the timesheet is " . ($r['status'] ?: 'gone');
        }
    }
    if ($bad) {
        $add('Payroll', 'fail', 'Payroll payments for timesheets that are not approved (reopened without undoing it?) - they may be paid again.', $bad);
    }
    // Approved periods with something still owed and not marked paid
    if (mitReimbViaPayroll()) {
        $left = [];
        foreach (mitConsistencyRows("SELECT user_id, period_start, period_end FROM mit_timesheets WHERE status = 'approved' AND period_end >= CURDATE() - INTERVAL 120 DAY", 5000) as $sheet) {
            foreach (mitReimbItems([$sheet['user_id']], $sheet['period_start'], $sheet['period_end'])[intval($sheet['user_id'])] ?? [] as $item) {
                if ($item['owed'] && $item['paid'] === '' && ($item['source'] !== 'entry' || $item['kind'] === 'purchase')) {
                    $left[] = (getFieldById('users', intval($sheet['user_id']), 'user_name') ?: 'user ' . $sheet['user_id']) . ", {$item['date']}: {$item['what']} ({$item['amount']})";
                }
            }
        }
        if ($left) {
            $add('Payroll', 'warn', 'Mileage or purchases in approved periods not marked paid (added after approval, or approval could not pay them) - on the next payroll by hand, or reopen and approve again.', $left);
        }
    }
    // Our entries whose ITFlow trip or expense went
    $lost = mitConsistencyRows("SELECT r.reimb_id, r.kind, r.description, r.entry_date FROM mit_reimbursements r
        WHERE (r.trip_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM trips WHERE trip_id = r.trip_id))");
    if ($lost) {
        $add('Payroll', 'warn', 'Mileage logged on tickets whose ITFlow trip was deleted (it is still counted from the entry).', array_map(function ($r) {
            return "#{$r['reimb_id']} {$r['entry_date']} {$r['description']}";
        }, $lost));
    }
}

/** QuickBooks: invoices in a batch take their payments in QuickBooks - ITFlow payments made by hand on them disagree. */
function mitConsistencyQbo(callable $add) {
    if (!function_exists('mitQboReady') || !mitQboReady()) {
        return;
    }
    $rows = mitConsistencyRows("SELECT i.invoice_id, i.invoice_prefix, i.invoice_number, p.payment_id, p.payment_amount, p.payment_method, p.payment_date
        FROM mit_qbo_batch_invoices e JOIN invoices i ON i.invoice_id = e.invoice_id JOIN payments p ON p.payment_invoice_id = e.invoice_id
        WHERE e.entry_status != 'removed' AND e.entry_id = (SELECT MAX(e2.entry_id) FROM mit_qbo_batch_invoices e2 WHERE e2.invoice_id = e.invoice_id)
            AND p.payment_method != 'QuickBooks Online' AND p.payment_created_at > e.added_at");
    if ($rows) {
        $add('QuickBooks', 'fail', 'ITFlow payments made by hand on invoices that are in a QuickBooks batch (their payments come from QuickBooks) - they may be counted twice.',
            array_map(function ($r) {
                return "{$r['invoice_prefix']}{$r['invoice_number']}: {$r['payment_method']} {$r['payment_amount']} on {$r['payment_date']}";
            }, $rows));
    }
    // A sent entry must still have its invoice and a QuickBooks id
    $broken = mitConsistencyRows("SELECT e.entry_id, e.invoice_id FROM mit_qbo_batch_invoices e WHERE e.entry_status = 'sent' AND (e.qbo_invoice_id IS NULL OR e.qbo_invoice_id = '')");
    if ($broken) {
        $add('QuickBooks', 'warn', 'Batch entries marked sent without a QuickBooks invoice id.', array_map(function ($r) {
            return "entry {$r['entry_id']}, invoice {$r['invoice_id']}";
        }, $broken));
    }
}

/** Ticket parts billed on invoice lines that are gone (the nightly tidy-up frees them - still here = it is not running). */
function mitConsistencyParts(callable $add) {
    if (!mitConsistencyTable('mit_ticket_parts')) {
        return;
    }
    $rows = mitConsistencyRows("SELECT p.part_id, p.name, p.invoice_id, p.invoice_item_id FROM mit_ticket_parts p
        WHERE p.invoice_id > 0 AND (NOT EXISTS (SELECT 1 FROM invoices WHERE invoice_id = p.invoice_id)
            OR (p.invoice_item_id > 0 AND NOT EXISTS (SELECT 1 FROM invoice_items WHERE item_id = p.invoice_item_id)))");
    if ($rows) {
        $add('Parts', 'warn', 'Ticket parts marked billed on an invoice or line that no longer exists (Procurement Tidy-up frees them daily).', array_map(function ($r) {
            return "part {$r['part_id']} {$r['name']}: invoice {$r['invoice_id']}, line {$r['invoice_item_id']}";
        }, $rows));
    }
}

/** Usage billing: the quantity on a still-draft invoice line is what usage set (or its override). */
function mitConsistencyUsage(callable $add) {
    if (!function_exists('mitUsageReady') || !mitUsageReady()) {
        return;
    }
    $rows = mitConsistencyRows("SELECT a.applied_id, i.invoice_prefix, i.invoice_number, ii.item_name, ii.item_quantity, COALESCE(a.override_qty, a.applied_qty) AS qty
        FROM mit_usage_applied a JOIN invoice_items ii ON ii.item_id = a.invoice_item_id JOIN invoices i ON i.invoice_id = a.invoice_id
        WHERE i.invoice_status = 'Draft' AND ABS(ii.item_quantity - COALESCE(a.override_qty, a.applied_qty)) > 0.0001");
    if ($rows) {
        $add('Usage billing', 'warn', 'Draft invoices whose usage-billed quantity was changed by hand after usage set it (use Override on Usage Review so it is recorded).',
            array_map(function ($r) {
                return "{$r['invoice_prefix']}{$r['invoice_number']} {$r['item_name']}: {$r['item_quantity']}, usage said {$r['qty']}";
            }, $rows));
    }
}

/** Service boards: open tickets left on an archived board. */
function mitConsistencyBoards(callable $add) {
    if (!function_exists('mitBoardsReady') || !mitBoardsReady()) {
        return;
    }
    $rows = mitConsistencyRows("SELECT t.ticket_id, t.ticket_prefix, t.ticket_number, b.board_name FROM mit_ticket_boards tb JOIN mit_boards b ON b.board_id = tb.board_id
        JOIN tickets t ON t.ticket_id = tb.ticket_id WHERE b.board_archived_at IS NOT NULL AND t.ticket_closed_at IS NULL AND t.ticket_archived_at IS NULL");
    if ($rows) {
        $add('Boards', 'warn', 'Open tickets on an archived board (no one sees them in that board\'s list).', array_map(function ($r) {
            return "{$r['ticket_prefix']}{$r['ticket_number']} on {$r['board_name']}";
        }, $rows));
    }
}

/** Outlook links of people who left. */
function mitConsistencyOutlook(callable $add) {
    if (!mitConsistencyTable('mit_outlook_links')) {
        return;
    }
    $rows = mitConsistencyRows("SELECT l.user_id, l.account_email FROM mit_outlook_links l LEFT JOIN users u ON u.user_id = l.user_id
        WHERE u.user_id IS NULL OR u.user_archived_at IS NOT NULL");
    if ($rows) {
        $add('Outlook', 'warn', 'Outlook calendar links of archived or deleted users (the sync removes them - still here = it is not running).', array_map(function ($r) {
            return "user {$r['user_id']} ({$r['account_email']})";
        }, $rows));
    }
}

/** Settings our features need from ITFlow's. */
function mitConsistencySettings(callable $add) {
    if (function_exists('mitReimbViaPayroll') && mitReimbReady() && mitTsOn() && mitReimbItflowPaysBack()) {
        if (mitReimbItflowRate() <= 0) {
            $add('Settings', 'warn', 'ITFlow\'s mileage rate (Paid back a mile) is not set - Log a trip has no "In their own vehicle" switch, so no trip is paid back. Set it in Admin > Timesheets.');
        }
        if (mitReimbViaPayroll() && !mitReimbSettings()['account_id']) {
            $add('Settings', 'warn', 'Mileage and purchases go on payroll but no "Payroll pays from account" is picked - timesheets with any can\'t be approved (Admin > Timesheets).');
        }
    }
}

/**
 * Our rows pointing at ITFlow records that are gone, where there is no foreign key to clear them (with one, the database does).
 * Records kept on purpose are left out: timesheet history after a login is deleted (M018), survey reviews (M036).
 */
function mitConsistencyOrphans(callable $add) {
    $parents = ['ticket_id' => ['tickets', 'ticket_id'], 'client_id' => ['clients', 'client_id'], 'asset_id' => ['assets', 'asset_id'],
        'invoice_id' => ['invoices', 'invoice_id'], 'invoice_item_id' => ['invoice_items', 'item_id'], 'product_id' => ['products', 'product_id'],
        'contact_id' => ['contacts', 'contact_id'], 'user_id' => ['users', 'user_id']];
    $columns = mitConsistencyRows("SELECT c.TABLE_NAME, c.COLUMN_NAME FROM information_schema.COLUMNS c
        WHERE c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME LIKE 'mit\\_%' AND c.COLUMN_NAME IN ('" . implode("', '", array_keys($parents)) . "')
            AND NOT EXISTS (SELECT 1 FROM information_schema.KEY_COLUMN_USAGE k WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = c.TABLE_NAME
                AND k.COLUMN_NAME = c.COLUMN_NAME AND k.REFERENCED_TABLE_NAME IS NOT NULL)", 1000);
    $found = [];
    foreach ($columns as $c) {
        [$table, $column] = [$c['TABLE_NAME'], $c['COLUMN_NAME']];
        if ($table === 'mit_ct_surveys') {
            continue; // a review is kept when its ticket, client or contact goes (M036)
        }
        if ($column === 'user_id' && (strpos($table, 'mit_ts_') === 0 || in_array($table, ['mit_timesheets', 'mit_time_entries', 'mit_leave_ledger', 'mit_timesheet_people', 'mit_leave_rates'], true))) {
            continue; // timesheet history outlives the login on purpose
        }
        [$parent, $key] = $parents[$column];
        $n = intval(mitConsistencyRows("SELECT COUNT(*) AS n FROM `$table` x WHERE x.`$column` > 0 AND NOT EXISTS (SELECT 1 FROM `$parent` p WHERE p.`$key` = x.`$column`)")[0]['n'] ?? 0);
        if ($n) {
            $found[] = "$table.$column: $n row" . ($n === 1 ? '' : 's') . " point at a missing $parent";
        }
    }
    if ($found) {
        $add('Leftovers', 'warn', 'Our rows pointing at ITFlow records that were deleted (no foreign key clears them) - harmless unless a feature counts them.', $found);
    }
}
