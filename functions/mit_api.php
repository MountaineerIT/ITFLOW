<?php

/*
 * Mountaineer IT - our features through ITFlow's REST API (Nov 23, 26.12.1.1 - the user's request). Endpoints in api/v1/mit_*:
 * service boards (mit_boards read / move), timesheets and payroll (mit_timesheets, mit_payroll, mit_charge_codes, mit_leave,
 * mit_reimbursements - read only), ticket parts (mit_ticket_parts read / create / delete) and the usage counts (mit_usage). Plus
 * our fields on ITFlow's own reads (mitApiExtras - one line in api/v1/read_output.php): a ticket's service board; an invoice's,
 * quote's or recurring invoice's percentage discount and minimum-commit lines; an invoice's QuickBooks state; a contract's
 * unlimited hours.
 *
 * Rules: each endpoint runs as the key's user (api/v1/enforce_api_rbac.php - module + client limits) and goes through the same
 * functions the pages use, so the same checks, history and audit lines apply. What things cost us never goes out
 * (mitApiHideCost, MIT_API_HIDDEN - parts' unit_cost too), and nothing here reaches the AI helper, the password vault or any
 * integration secret.
 */

/** Stop with an error: HTTP $code and {"success": "False", "message": ...}. */
function mitApiFail($code, $message) {
    global $return_arr;
    $texts = [400 => 'Bad Request', 403 => 'Forbidden', 404 => 'Not Found', 409 => 'Conflict', 503 => 'Service Unavailable'];
    header("HTTP/1.1 $code " . ($texts[$code] ?? 'Error'));
    $return_arr['success'] = "False";
    $return_arr['message'] = $message;
    echo json_encode($return_arr);
    exit();
}

/**
 * Answer a read with a list: ITFlow's shape ({"success", "count", "data"}), a page of it by the request's limit / offset. An
 * empty page answers success "True", count 0, data [] (ITFlow's reads say "False" there - ours say it plainly).
 */
function mitApiList(array $rows, $page = true) {
    global $return_arr, $limit, $offset;
    $rows = array_values($rows);
    $total = count($rows);
    if ($page) {
        $rows = array_slice($rows, max(0, intval($offset)), max(1, intval($limit ?: 50)));
    }
    $return_arr['success'] = "True";
    $return_arr['count'] = count($rows);
    $return_arr['total'] = $total;
    $return_arr['data'] = mitApiHideCost($rows);
    echo json_encode($return_arr);
    exit();
}

/** Answer a write that worked: {"success": "True", "message", "data": [$data]}. */
function mitApiDone($message, array $data = []) {
    global $return_arr;
    $return_arr['success'] = "True";
    $return_arr['message'] = $message;
    $return_arr['count'] = 1;
    $return_arr['data'] = [mitApiHideCost([$data])[0]];
    echo json_encode($return_arr);
    exit();
}

/** Minutes as hours, two places. */
function mitApiHours($minutes) {
    return round(floatval($minutes) / 60, 2);
}

/** A date parameter (YYYY-MM-DD), or $default when absent; a bad one stops the request. */
function mitApiDate($name, $default) {
    $value = trim((string) ($_GET[$name] ?? $_POST[$name] ?? ''));
    if ($value === '') {
        return $default;
    }
    $d = DateTime::createFromFormat('!Y-m-d', $value);
    if (!$d || $d->format('Y-m-d') !== $value) {
        mitApiFail(400, "$name must be a date written YYYY-MM-DD.");
    }
    return $value;
}

/** A ticket the key's user can reach (Support is checked by the enforcer; this adds the client limits), or a 404. */
function mitApiTicket($ticket_id) {
    global $mysqli;
    $ticket_id = intval($ticket_id);
    $ticket = $ticket_id ? mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT * FROM tickets WHERE ticket_id = $ticket_id")) : null;
    if (!$ticket || !apiUserCanAccessClient(intval($ticket['ticket_client_id']))) {
        mitApiFail(404, 'No ticket with that ticket_id (for a client this key can reach).');
    }
    return $ticket;
}

/*
 * ---------- our fields on ITFlow's own reads ----------
 */

/**
 * api/v1/read_output.php's hook: add our fields to the rows of an ITFlow read ($resource = the endpoint's folder, from the
 * enforcer). Each field is named mit_* so it can't collide with one of ITFlow's.
 */
function mitApiExtras(array $rows, $resource) {
    $ids = function ($key) use ($rows) {
        return array_values(array_filter(array_map(function ($r) use ($key) {
            return intval($r[$key] ?? 0);
        }, $rows)));
    };
    try {
        switch ((string) $resource) {
            case 'tickets':
                if (function_exists('mitBoardsReady') && mitBoardsReady() && mitBoards()) {
                    $of = mitApiBoardsOf($ids('ticket_id'));
                    foreach ($rows as &$row) {
                        $board = $of[intval($row['ticket_id'] ?? 0)] ?? null;
                        $row['mit_board_id'] = $board ? intval($board['board_id']) : null;
                        $row['mit_board_name'] = $board['board_name'] ?? null;
                    }
                    unset($row);
                }
                break;
            case 'invoices':
                $rows = mitApiBillingExtras($rows, 'invoice', 'invoice_id');
                if (function_exists('mitQboReady') && mitQboReady() && mitQboConnected()) {
                    $states = mitQboInvoiceStates($ids('invoice_id'));
                    foreach ($rows as &$row) {
                        $s = $states[intval($row['invoice_id'] ?? 0)] ?? null;
                        $row['mit_quickbooks'] = $s ? ['approval' => $s['approval'], 'batch_entry' => $s['entry'], 'entered_by_hand' => (bool) $s['manual']] : null;
                    }
                    unset($row);
                }
                break;
            case 'quotes':
                $rows = mitApiBillingExtras($rows, 'quote', 'quote_id');
                break;
            case 'recurring_invoices':
                $rows = mitApiBillingExtras($rows, 'recurring', 'recurring_invoice_id');
                break;
            case 'contracts':
                if (function_exists('mitContractUnlimited')) {
                    foreach ($rows as &$row) {
                        if (is_array($row) && array_key_exists('contract_monthly_hours', $row)) {
                            $row['mit_unlimited_hours'] = mitContractUnlimited($row);
                        }
                    }
                    unset($row);
                }
                break;
        }
    } catch (Throwable $e) {
        error_log('mitApiExtras: ' . $e->getMessage()); // never break ITFlow's read
    }
    return $rows;
}

/** Percentage discount and minimum-commit lines on billing reads. */
function mitApiBillingExtras(array $rows, $type, $id_key) {
    $discounts = function_exists('mitDiscountReady') && mitDiscountReady();
    $minimums = function_exists('mitMinimumReady') && mitMinimumReady();
    foreach ($rows as &$row) {
        $id = intval($row[$id_key] ?? 0);
        if (!$id) {
            continue;
        }
        if ($discounts) {
            $rule = mitDiscountRule($type, $id);
            $row['mit_discount_percent'] = $rule ? $rule['percent'] : null;
            $row['mit_discount_product_id'] = $rule && $rule['product_id'] ? $rule['product_id'] : null;
        }
        if ($minimums) {
            $row['mit_minimum_lines'] = [];
            foreach (mitMinimumLines($type, $id) as $item_id => $minimum) {
                $row['mit_minimum_lines'][] = ['item_id' => $item_id, 'minimum' => $minimum];
            }
        }
    }
    unset($row);
    return $rows;
}

/*
 * ---------- service boards ----------
 */

/** Each ticket's board row: ticket_id => board (no row of ours = the default board). Archived boards count as the default. */
function mitApiBoardsOf(array $ticket_ids) {
    global $mysqli;
    $ticket_ids = array_values(array_filter(array_map('intval', $ticket_ids)));
    $boards = mitBoards();
    $default = mitBoardDefaultId();
    $out = [];
    if (!$ticket_ids || !$boards) {
        return $out;
    }
    $of = array_fill_keys($ticket_ids, $default);
    $result = mysqli_query($mysqli, "SELECT ticket_id, board_id FROM mit_ticket_boards WHERE ticket_id IN (" . implode(',', $ticket_ids) . ")");
    while ($row = mysqli_fetch_row($result)) {
        $of[intval($row[0])] = isset($boards[intval($row[1])]) ? intval($row[1]) : $default;
    }
    foreach ($of as $ticket_id => $board_id) {
        if (isset($boards[$board_id])) {
            $out[$ticket_id] = $boards[$board_id];
        }
    }
    return $out;
}

/** A board as the API shows it: no styling, its members and open tickets (only those the key's clients allow). */
function mitApiBoard(array $board, array $open) {
    $members = [];
    foreach (mitBoardMembers($board['board_id']) as $user_id => $m) {
        $members[] = ['user_id' => $user_id, 'user_name' => $m['user_name'], 'notify' => (bool) $m['notify']];
    }
    return [
        'board_id' => intval($board['board_id']),
        'board_name' => $board['board_name'],
        'board_description' => $board['board_description'] ?? null,
        'board_is_default' => (bool) intval($board['board_is_default']),
        'board_auto_assign' => (bool) intval($board['board_assign'] ?? 0),
        'open_tickets' => intval($open[intval($board['board_id'])] ?? 0),
        'members' => $members,
    ];
}

/** Open tickets per board, among the clients the key's user can reach: board_id => count. */
function mitApiBoardOpenCounts() {
    global $mysqli;
    $counts = [];
    $result = mysqli_query($mysqli, "SELECT " . mitBoardSql('tickets') . " AS board_id, COUNT(*) FROM tickets
        WHERE ticket_closed_at IS NULL AND ticket_resolved_at IS NULL AND ticket_archived_at IS NULL" . clientScopeSql('tickets.ticket_client_id') . " GROUP BY 1");
    while ($row = mysqli_fetch_row($result)) {
        $counts[intval($row[0])] = intval($row[1]);
    }
    return $counts;
}

/*
 * ---------- timesheets ----------
 */

/** Stop unless timesheets are on and set up. */
function mitApiTsRequire() {
    if (!function_exists('mitTsOn') || !mitTsOn()) {
        mitApiFail(503, 'Timesheets are switched off here (Admin > Settings > Modules), or need the database update.');
    }
}

/**
 * The people the key's user may see timesheets of - their own, their reports, or everyone with Timesheets View (as
 * mitTsVisibleUsers) - with anyone who has hours in the dates. A user_id parameter narrows it (refused if not visible).
 */
function mitApiTsPeople($from, $to) {
    global $session_user_id;
    $users = mitTsUsers(mitTsUsersWithActivity($from, $to));
    if (mitTsLevel() < 1) {
        $users = array_intersect_key($users, array_flip(array_merge(mitTsMyReports(), [intval($session_user_id)])));
    }
    $one = intval($_GET['user_id'] ?? 0);
    if ($one) {
        if (!mitTsMaySee($one)) {
            mitApiFail(403, "The key's user may not see that person's timesheets (needs Timesheets view, or to be their approver).");
        }
        $users = array_intersect_key(mitTsUsers([$one]), [$one => true]);
    }
    return $users;
}

/** The dates asked for: from / to, or the pay period holding `period` (any date in it), else the last finished period. */
function mitApiTsRange() {
    if (isset($_GET['period']) && $_GET['period'] !== '') {
        $p = mitTsPeriodFor(mitApiDate('period', date('Y-m-d')));
        return [$p['start'], $p['end']];
    }
    $last = mitTsPeriodShift(mitTsPeriodFor(), -1);
    $from = mitApiDate('from', $last['start']);
    $to = mitApiDate('to', isset($_GET['from']) ? $from : $last['end']);
    if ($to < $from) {
        mitApiFail(400, 'to is before from.');
    }
    if ((strtotime($to) - strtotime($from)) / 86400 > 400) {
        mitApiFail(400, 'Ask for at most 400 days at a time.');
    }
    return [$from, $to];
}

/** One person's totals (mitTsTotals, minutes) as hours for the API. */
function mitApiTsHours(array $t, array $codes) {
    $by_code = [];
    foreach ($t['codes'] as $code_id => $minutes) {
        if ($minutes) {
            $by_code[] = ['code_id' => intval($code_id), 'code_name' => $codes[$code_id]['code_name'] ?? "Code $code_id", 'hours' => mitApiHours($minutes)];
        }
    }
    return [
        'billable_ticket_hours' => mitApiHours($t['billable']),
        'other_ticket_hours' => mitApiHours($t['other']),
        'code_hours' => $by_code,
        'unaccounted_hours' => mitApiHours($t['auto_lost']),
        'not_on_a_code_hours' => mitApiHours($t['unallocated']),
        'not_clocked_hours' => mitApiHours($t['not_clocked']),
        'paid_break_hours' => mitApiHours($t['break_paid']),
        'worked_hours' => mitApiHours($t['worked']),
        'leave_hours' => mitApiHours($t['leave']),
        'unpaid_hours' => mitApiHours($t['unpaid']),
        'paid_hours' => mitApiHours($t['paid']),
        'regular_hours' => mitApiHours($t['regular']),
        'overtime_hours' => mitApiHours($t['overtime']),
        'double_time_hours' => mitApiHours($t['double']),
        'meal_premium_hours' => mitApiHours($t['meal_premium']),
        'rest_premium_hours' => mitApiHours($t['rest_premium']),
        'total_hours' => mitApiHours($t['total']),
        'billable_percent' => $t['accounted'] ? round($t['billable'] / $t['accounted'] * 100, 1) : null,
        'california_rules' => (bool) $t['ca'],
    ];
}

/** A person's identity for payroll rows. */
function mitApiTsPerson($user_id, array $user) {
    $p = mitTsPerson($user_id);
    return ['user_id' => intval($user_id), 'user_name' => $user['user_name'], 'user_email' => $user['user_email'],
        'employee_id' => $p['employee_id'] !== '' ? $p['employee_id'] : null, 'pay_type' => $p['pay_type']];
}

/*
 * ---------- ticket parts ----------
 */

/** Stop unless ticket parts can be used: Purchasing on (ITFlow's module switch) and our tables there. */
function mitApiPartsRequire() {
    if (!function_exists('mitTicketPartsReady') || !mitProcReady() || !mitTicketPartsReady() || !mitModuleOn('procurement')) {
        mitApiFail(503, 'Ticket parts need Purchasing switched on (Admin > Settings > Modules) and the database update.');
    }
}

/** The ticket a part write acts on: reachable, and not closed (as the Parts box). */
function mitApiPartsTicket($ticket_id) {
    mitApiPartsRequire();
    $ticket = mitApiTicket($ticket_id);
    if (!empty($ticket['ticket_closed_at'])) {
        mitApiFail(409, 'The ticket is closed.');
    }
    return $ticket;
}

/** A part by id (its ticket's client reachable), or a 404. */
function mitApiPartRow($part_id) {
    mitApiPartsRequire();
    $part_id = intval($part_id);
    $part = $part_id ? (mitTicketPartsWhere("parts.part_id = $part_id")[$part_id] ?? null) : null;
    if (!$part || !apiUserCanAccessClient(intval($part['client_id']))) {
        mitApiFail(404, 'No part with that part_id (on a ticket of a client this key can reach).');
    }
    return $part;
}

/** A part as the API shows it - never our cost. */
function mitApiPart(array $p) {
    $status = ($p['source'] ?? '') === 'other' ? 'on hand' : 'from stock';
    if (($p['source'] ?? '') === 'order') {
        $status = $p['proc_status'] === 'cancelled' ? 'not ordered' : (!$p['po_id'] ? 'waiting to order'
            : ($p['proc_status'] === 'received' ? 'received' : ((float) $p['received_qty'] > 0 ? 'partly received' : 'ordered')));
    }
    return [
        'part_id' => intval($p['part_id']), 'ticket_id' => intval($p['ticket_id'] ?? 0), 'client_id' => intval($p['client_id'] ?? 0),
        'name' => $p['name'] ?? null, 'description' => $p['description'] ?? null, 'quantity' => isset($p['quantity']) ? (float) $p['quantity'] : null,
        'unit_price' => isset($p['unit_price']) ? (float) $p['unit_price'] : null, 'tax_id' => intval($p['tax_id'] ?? 0) ?: null,
        'product_id' => intval($p['product_id'] ?? 0) ?: null, 'asset_id' => intval($p['asset_id'] ?? 0) ?: null, 'source' => $p['source'] ?? null,
        'status' => $status, 'order_item_id' => intval($p['proc_item_id'] ?? 0) ?: null,
        'po_id' => intval($p['po_id'] ?? 0) ?: null, 'po_number' => !empty($p['po_id']) ? $p['po_prefix'] . $p['po_number'] : null,
        'received_quantity' => isset($p['received_qty']) ? (float) $p['received_qty'] : null,
        'invoice_id' => intval($p['invoice_id'] ?? 0) ?: null, 'invoice_number' => !empty($p['invoice_id']) && isset($p['invoice_number']) ? $p['invoice_prefix'] . $p['invoice_number'] : null,
        'billed_at' => $p['billed_at'] ?? null, 'created_by' => intval($p['created_by'] ?? 0) ?: null, 'created_at' => $p['created_at'] ?? null,
    ];
}
