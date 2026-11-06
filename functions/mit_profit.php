<?php

/*
 * Mountaineer IT - what each client costs us, in ITFlow's Client Profitability report (26.11.3.4, the user's request).
 *
 * ITFlow's report (agent/reports/client_profitability.php) sets money in against the hours logged on a client's tickets, less the
 * expenses filed against them. It does not know what an hour or a part costs us. This adds:
 *  - Labor: each agent's cost per hour (wage, taxes, benefits) with a date it counts from (mit_agent_cost_rates, M057) - a new rate
 *    is a new row, so past periods keep the rate they had; an agent's first rate also covers time before its date. Agents with none
 *    use the company default (mit_settings profit_default_cost); with no default either their hours are left uncosted and the report
 *    says so. Set on Admin > Settings > Labor costs (admin/mit_labor_costs.php, admins only). Hours are ITFlow's: time on ticket
 *    replies, dated when the reply was posted (as ITFlow's report counts them).
 *  - Parts: on invoices dated in the period (Sent / Viewed / Partial / Paid), each line's cost - a billed ticket part's own cost
 *    (mit_ticket_parts.unit_cost x quantity), else its product's cost now (products.product_cost x quantity).
 *  - Income invoiced or paid (?basis=, invoiced by default - the user's choice): invoiced = invoice lines before tax, less the invoice
 *    discount, on invoices dated in the period, plus ITFlow's client revenues; paid = ITFlow's own (payments + revenues, tax included).
 *  - Profit = ITFlow's Net less labor and parts; Margin = profit / income.
 *  - Break-even hours (26.11.3.4, the user bills flat monthly agreements with unlimited hours): hours a month the client's recurring fee
 *    pays for at the cost of the agents who work for them, against the hours a month they use (mitProfitBreakEven).
 * Hooks in ITFlow's report, one line each: mitProfitAdd (before net is worked out), mitProfitColumns, mitProfitTotals, the basis switch
 * (mitProfitControls) in its controls and mitProfitSummary under its figures. The report stays Financial-only, as ITFlow has it.
 */

const MIT_PROFIT_BASES = ['invoiced' => 'Invoiced', 'paid' => 'Paid'];
/** Invoices that count as sold. */
const MIT_PROFIT_INVOICE_STATUSES = "'Sent', 'Viewed', 'Partial', 'Paid'";

/** Database update M057 has run. */
function mitProfitReady() {
    global $mysqli;
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = mysqli_num_rows(mysqli_query($mysqli, "SHOW TABLES LIKE 'mit_agent_cost_rates'")) === 1;
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/** The company's default cost per hour, or null when none is set. */
function mitProfitDefaultCost() {
    $value = trim((string) mitSetting('profit_default_cost', ''));
    return $value === '' || !is_numeric($value) ? null : round((float) $value, 2);
}

/** Every agent's rates: user id => [[effective_from, hourly_cost, rate_id], ...] oldest first. */
function mitProfitRates() {
    global $mysqli;
    $rates = [];
    if (!mitProfitReady()) {
        return $rates;
    }
    $result = mysqli_query($mysqli, "SELECT rate_id, user_id, effective_from, hourly_cost FROM mit_agent_cost_rates ORDER BY user_id, effective_from");
    while ($row = mysqli_fetch_assoc($result)) {
        $rates[intval($row['user_id'])][] = [$row['effective_from'], (float) $row['hourly_cost'], intval($row['rate_id'])];
    }
    return $rates;
}

/** An agent's cost per hour on a day: the newest rate from on or before it (the first rate also covers earlier days), else the default. */
function mitProfitRateOn(array $rates, $user_id, $date, $default) {
    $list = $rates[intval($user_id)] ?? [];
    if (!$list) {
        return $default;
    }
    $rate = $list[0][1];
    foreach ($list as [$from, $cost]) {
        if ($from <= $date) {
            $rate = $cost;
        }
    }
    return $rate;
}

/** Save a rate for an agent from a date (one rate a day - the same date replaces it). Returns a problem, or ''. */
function mitProfitRateSave($user_id, $from, $cost) {
    global $mysqli, $session_user_id;
    $user_id = intval($user_id);
    $from = trim((string) $from);
    $cost = trim(str_replace([',', '$'], '', (string) $cost));
    if (!mitProfitReady()) {
        return 'Labor costs need the database update - Admin > Update.';
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !strtotime($from)) {
        return 'Pick the date the rate counts from.';
    }
    if (!is_numeric($cost) || (float) $cost < 0 || (float) $cost > 100000) {
        return 'Enter a cost per hour of 0 or more.';
    }
    if (!mysqli_num_rows(mysqli_query($mysqli, "SELECT 1 FROM users WHERE user_id = $user_id AND user_type = 1"))) {
        return 'That agent was not found.';
    }
    $cost = round((float) $cost, 2);
    mysqli_query($mysqli, "INSERT INTO mit_agent_cost_rates SET user_id = $user_id, effective_from = '" . escapeSql($from) . "', hourly_cost = $cost, created_by = " . intval($session_user_id) . "
        ON DUPLICATE KEY UPDATE hourly_cost = VALUES(hourly_cost), created_by = VALUES(created_by), created_at = NOW()");
    return '';
}

/** Remove one rate row. Returns the agent's id, or 0 when there was none. */
function mitProfitRateDelete($rate_id) {
    global $mysqli;
    $rate_id = intval($rate_id);
    if (!mitProfitReady()) {
        return 0;
    }
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT user_id FROM mit_agent_cost_rates WHERE rate_id = $rate_id"));
    if (!$row) {
        return 0;
    }
    mysqli_query($mysqli, "DELETE FROM mit_agent_cost_rates WHERE rate_id = $rate_id");
    return intval($row['user_id']);
}

/** The agents to set costs for: active agents (no service accounts) and anyone who already has a rate. id => [name, archived]. */
function mitProfitAgents() {
    global $mysqli;
    $service = function_exists('mitServiceUserSql') ? mitServiceUserSql('user_id') : '';
    $with_rates = mitProfitReady() ? " OR user_id IN (SELECT user_id FROM mit_agent_cost_rates)" : '';
    $agents = [];
    $result = mysqli_query($mysqli, "SELECT user_id, user_name, user_archived_at FROM users
        WHERE user_type = 1 AND ((user_archived_at IS NULL $service) $with_rates) ORDER BY user_archived_at IS NOT NULL, user_name");
    while ($row = mysqli_fetch_assoc($result)) {
        $agents[intval($row['user_id'])] = ['name' => $row['user_name'], 'archived' => $row['user_archived_at'] !== null];
    }
    return $agents;
}

/** The basis picked on the report (?basis=), invoiced by default. */
function mitProfitBasis() {
    $basis = (string) ($_GET['basis'] ?? 'invoiced');
    return isset(MIT_PROFIT_BASES[$basis]) ? $basis : 'invoiced';
}

/**
 * The money side of every client in the period, in ITFlow's report's rows (by reference). $client is the report's own closure that
 * adds a client row from [client_id, client_name, client_abbreviation]. Sets income (invoiced basis), labor, parts, uncosted_seconds.
 */
function mitProfitAdd(array &$clients, callable $client, array $period, $with_tickets = true) {
    global $mysqli;
    if (!mitProfitReady()) {
        return;
    }
    $GLOBALS['mit_profit_period'] = $period;
    $scope = clientScopeSql('client_id');
    $basis = mitProfitBasis();
    $in_period = function ($column) use ($period) {
        return reportRangeSql($column, $period);
    };

    // Invoiced: the lines before tax, less the invoice's discount, on invoices dated in the period - plus ITFlow's client revenues
    if ($basis === 'invoiced') {
        foreach ($clients as &$row) {
            $row['income'] = 0.0;
        }
        unset($row);
        $sql = mysqli_query($mysqli, "SELECT client_id, client_name, client_abbreviation, SUM(amount) AS v FROM (
                SELECT invoice_client_id AS client_id,
                    (SELECT COALESCE(SUM(item_subtotal), 0) FROM invoice_items WHERE item_invoice_id = invoice_id AND item_archived_at IS NULL) - invoice_discount_amount AS amount
                    FROM invoices WHERE invoice_status IN (" . MIT_PROFIT_INVOICE_STATUSES . ") AND " . $in_period('invoice_date') . "
                UNION ALL
                SELECT revenue_client_id, revenue_amount FROM revenues
                    WHERE revenue_client_id > 0 AND " . dashboardRevenueSql() . " AND " . $in_period('revenue_date') . "
            ) AS income
            JOIN clients USING (client_id)
            WHERE 1 = 1 $scope
            GROUP BY client_id, client_name, client_abbreviation");
        while ($row = mysqli_fetch_assoc($sql)) {
            $clients[$client($row)]['income'] = (float) $row['v'];
        }
    }

    // Labor: each agent's hours at their rate on the day
    $rates = mitProfitRates();
    $default = mitProfitDefaultCost();
    if ($with_tickets) {
        $sql = mysqli_query($mysqli, "SELECT client_id, client_name, client_abbreviation, ticket_reply_by AS user_id, DATE(ticket_reply_created_at) AS d,
                SUM(TIME_TO_SEC(ticket_reply_time_worked)) AS s
            FROM ticket_replies
            JOIN tickets ON ticket_reply_ticket_id = ticket_id
            JOIN clients ON ticket_client_id = client_id
            WHERE ticket_reply_archived_at IS NULL AND ticket_reply_time_worked IS NOT NULL
            AND " . $in_period('ticket_reply_created_at') . " $scope
            GROUP BY client_id, client_name, client_abbreviation, ticket_reply_by, DATE(ticket_reply_created_at)");
        while ($row = mysqli_fetch_assoc($sql)) {
            $id = $client($row);
            $seconds = intval($row['s']);
            $rate = mitProfitRateOn($rates, $row['user_id'], $row['d'], $default);
            if ($rate === null) {
                $clients[$id]['uncosted_seconds'] = ($clients[$id]['uncosted_seconds'] ?? 0) + $seconds;
            } else {
                $clients[$id]['labor'] = ($clients[$id]['labor'] ?? 0.0) + $seconds / 3600 * $rate;
            }
        }
    }

    // Parts: what the lines sold on invoices dated in the period cost us
    $sql = mysqli_query($mysqli, "SELECT client_id, client_name, client_abbreviation, SUM(cost) AS v FROM (
            SELECT invoice_client_id AS client_id,
                COALESCE((SELECT SUM(part.unit_cost * part.quantity) FROM mit_ticket_parts part WHERE part.invoice_item_id = item_id),
                    IF(product_cost > 0, product_cost * item_quantity, 0)) AS cost
            FROM invoice_items
            JOIN invoices ON item_invoice_id = invoice_id
            LEFT JOIN products ON product_id = item_product_id AND item_product_id > 0
            WHERE item_archived_at IS NULL AND invoice_status IN (" . MIT_PROFIT_INVOICE_STATUSES . ") AND " . $in_period('invoice_date') . "
        ) AS sold
        JOIN clients USING (client_id)
        WHERE cost > 0 $scope
        GROUP BY client_id, client_name, client_abbreviation");
    while ($row = mysqli_fetch_assoc($sql)) {
        $clients[$client($row)]['parts'] = (float) $row['v'];
    }

    foreach ($clients as &$row) {
        $row['labor'] = round($row['labor'] ?? 0.0, 2);
        $row['parts'] = round($row['parts'] ?? 0.0, 2);
        $row['uncosted_seconds'] = $row['uncosted_seconds'] ?? 0;
    }
    unset($row);
}

/** Profit and margin on each row (after ITFlow worked out net), and our columns after Net. */
function mitProfitColumns(array $columns, array &$clients) {
    if (!mitProfitReady()) {
        return $columns;
    }
    foreach ($clients as &$row) {
        $row['labor'] = $row['labor'] ?? 0.0;
        $row['parts'] = $row['parts'] ?? 0.0;
        $row['profit'] = round(($row['net'] ?? 0) - $row['labor'] - $row['parts'], 2);
        $row['margin'] = reportShare($row['profit'], $row['income'], 1);
    }
    unset($row);
    mitProfitBreakEven($clients, $GLOBALS['mit_profit_period'] ?? null);

    $zero = '<span class="itflow-report-zero">–</span>';
    $ours = [
        'labor' => ['label' => 'Labor cost', 'type' => 'money', 'title' => 'Hours on their tickets at each agent\'s cost per hour (Admin > Settings > Labor costs)',
            'html' => function ($row) use ($zero) {
                $html = $row['labor'] > 0 ? reportMoney($row['labor']) : $zero;
                if (($row['uncosted_seconds'] ?? 0) > 0) {
                    $html .= ' <i class="fas fa-exclamation-triangle text-warning" data-mit-profit-uncosted title="' . escapeHtml(reportHours($row['uncosted_seconds']) . ' h by agents with no cost per hour') . '"></i>';
                }
                return $html;
            }],
        'parts' => ['label' => 'Parts cost', 'type' => 'money', 'title' => 'What the parts and products on their invoices in the period cost us',
            'html' => function ($row) use ($zero) {
                return $row['parts'] > 0 ? reportMoney($row['parts']) : $zero;
            }],
        'profit' => ['label' => 'Profit', 'type' => 'money', 'class' => 'fw-semibold', 'title' => 'Net less labor and parts',
            'html' => function ($row) {
                return '<span class="fw-semibold ' . ($row['profit'] < 0 ? 'text-danger' : 'text-success-emphasis') . '" data-mit-profit="' . escapeHtml(number_format($row['profit'], 2, '.', '')) . '">' . reportMoney($row['profit']) . '</span>';
            }],
        'margin' => ['label' => 'Margin', 'type' => 'percent', 'title' => 'Profit as a share of income',
            'html' => function ($row) {
                if ($row['margin'] === null) {
                    return '<span class="itflow-report-zero">–</span>';
                }
                return '<span class="' . ($row['margin'] < 0 ? 'text-danger fw-semibold' : '') . '">' . reportPercent($row['margin']) . '</span>';
            }],
    ];
    $agreement = [
        'hours_month' => ['label' => 'Hours / month', 'type' => 'decimal', 'decimals' => 1, 'title' => 'Hours on their tickets, a month on average over the period'],
        'break_even' => ['label' => 'Break-even h / month', 'type' => 'decimal', 'decimals' => 1,
            'title' => 'Hours a month the agreement pays for: the monthly fee, less parts and direct costs a month, divided by the cost per hour of the agents who work for them',
            'csv' => function ($row) {
                return $row['break_even'] === null ? '' : number_format($row['break_even'], 1, '.', '');
            },
            'html' => function ($row) {
                if ($row['break_even'] === null) {
                    return '<span class="itflow-report-zero" title="No recurring fee">–</span>';
                }
                $text = number_format($row['break_even'], 1);
                if ($row['over_break_even']) {
                    return '<span class="text-danger fw-semibold" data-mit-break-even="over">' . $text . '</span>'
                        . ' <span class="badge rounded-pill bg-danger-subtle text-danger-emphasis border border-danger-subtle fw-normal" title="More hours a month than the agreement pays for">Over</span>';
                }
                return '<span class="text-success-emphasis" data-mit-break-even="under">' . $text . '</span>';
            }],
    ];
    $out = [];
    foreach ($columns as $key => $column) {
        $out[$key] = $column;
        if ($key === 'net') {
            $out += $ours;
        }
        if ($key === 'mrr') {
            $out += $agreement;
        }
    }
    return $out + $ours + $agreement;
}

/**
 * Break-even hours for flat monthly agreements (the user's way of billing: a set monthly fee, unlimited hours). Per client: hours a month
 * on average over the period, and the hours a month their recurring fee (ITFlow's Recurring / month) pays for - the fee less the parts
 * and direct costs a month, divided by the cost per hour of the agents who worked for them (their labor / costed hours; with none, the
 * blended rate of everyone in the report, else the company default). over_break_even when they used more. No fee = no break-even.
 */
function mitProfitBreakEven(array &$clients, $period) {
    $days = $period ? (strtotime($period['end']) - strtotime($period['start'])) / 86400 + 1 : 30.4375;
    $months = max($days, 1) / 30.4375;
    $labor = 0.0;
    $costed = 0;
    foreach ($clients as $row) {
        $labor += $row['labor'] ?? 0;
        $costed += max(0, ($row['seconds'] ?? 0) - ($row['uncosted_seconds'] ?? 0));
    }
    $blended = $costed > 0 ? $labor / ($costed / 3600) : mitProfitDefaultCost();
    foreach ($clients as &$row) {
        $row['hours_month'] = round(($row['seconds'] ?? 0) / 3600 / $months, 2);
        $row['break_even'] = null;
        $row['over_break_even'] = false;
        $fee = (float) ($row['mrr'] ?? 0);
        $own = max(0, ($row['seconds'] ?? 0) - ($row['uncosted_seconds'] ?? 0));
        $rate = $own > 0 && ($row['labor'] ?? 0) > 0 ? $row['labor'] / ($own / 3600) : $blended;
        if ($fee > 0 && $rate) {
            $other = (($row['costs'] ?? 0) + ($row['parts'] ?? 0)) / $months;
            $row['break_even'] = round(max(0, $fee - $other) / $rate, 2);
            $row['over_break_even'] = $row['hours_month'] > $row['break_even'];
        }
    }
    unset($row);
}

/** The totals row's labor, parts, profit and margin. */
function mitProfitTotals(array $totals, array $clients) {
    if (!mitProfitReady()) {
        return $totals;
    }
    $totals['labor'] = array_sum(array_column($clients, 'labor'));
    $totals['parts'] = array_sum(array_column($clients, 'parts'));
    $totals['profit'] = array_sum(array_column($clients, 'profit'));
    $totals['uncosted_seconds'] = array_sum(array_column($clients, 'uncosted_seconds'));
    $totals['margin'] = reportShare($totals['profit'], $totals['income'] ?? 0, 1);
    $totals['hours_month'] = array_sum(array_column($clients, 'hours_month'));
    $totals['break_even'] = null;
    return $totals;
}

/** The Invoiced / Paid switch, for the report's controls. */
function mitProfitControls() {
    if (!mitProfitReady()) {
        return '';
    }
    return '<div class="col-auto" data-mit-profit-basis title="Invoiced: sales before tax on invoices dated in the period. Paid: money received in the period (ITFlow\'s way, tax included).">'
        . reportSegmentControl('basis', mitProfitBasis(), MIT_PROFIT_BASES, 'd-inline-block') . '</div>';
}

/** Our figures under ITFlow's: labor, parts, profit and margin, and what is not costed yet. */
function mitProfitSummary(array $clients, array $totals) {
    global $session_is_admin;
    if (!mitProfitReady()) {
        return '';
    }
    $losing = array_filter($clients, function ($row) {
        return ($row['profit'] ?? 0) < 0;
    });
    $over = array_filter($clients, function ($row) {
        return !empty($row['over_break_even']);
    });
    $uncosted = intval($totals['uncosted_seconds'] ?? 0);
    $basis = mitProfitBasis();
    $html = '<div data-mit-profit-summary>' . reportStats([
        ['label' => 'Labor cost', 'value' => reportMoney($totals['labor'] ?? 0),
            'sub' => $uncosted > 0 ? '<span class="text-warning-emphasis">' . escapeHtml(reportHours($uncosted)) . ' h with no cost per hour</span>' : '<span class="text-body-tertiary">Hours at each agent\'s cost</span>'],
        ['label' => 'Parts cost', 'value' => reportMoney($totals['parts'] ?? 0), 'sub' => '<span class="text-body-tertiary">On invoices dated in the period</span>'],
        ['label' => 'Profit', 'value' => reportMoney($totals['profit'] ?? 0), 'tone' => ($totals['profit'] ?? 0) < 0 ? 'danger' : 'success',
            'sub' => ($totals['margin'] ?? null) === null ? '' : escapeHtml(reportPercent($totals['margin'])) . ' <span class="text-body-tertiary">margin · ' . ($basis === 'invoiced' ? 'invoiced' : 'paid') . '</span>'],
        ['label' => 'Losing money', 'value' => reportCount(count($losing)), 'tone' => $losing ? 'danger' : 'success',
            'sub' => '<span class="text-body-tertiary">Clients with profit below 0</span>'],
        ['label' => 'Over break-even', 'value' => reportCount(count($over)), 'tone' => $over ? 'danger' : 'success',
            'sub' => '<span class="text-body-tertiary">More hours a month than their agreement pays for</span>'],
    ]) . '<p class="small text-body-secondary mb-3 px-1" data-mit-profit-note>'
        . ($basis === 'invoiced'
            ? 'Income here is invoiced: sales before tax, less discounts, on invoices dated in the period, plus revenue entered for the client. Switch to Paid for money received.'
            : 'Income here is money received in the period (payments, tax included, and revenue), as ITFlow counts it.')
        . ' Profit is net less labor (hours at each agent\'s cost per hour) and the cost of parts and products on invoices dated in the period.'
        . ' Break-even h / month is how many hours a month the client\'s recurring fee pays for, after parts and direct costs, at the cost per hour of the agents who work for them.</p></div>';
    if (!mitProfitRates() && mitProfitDefaultCost() === null) {
        $html .= '<div class="alert alert-warning small" data-mit-profit-no-rates><i class="fas fa-exclamation-triangle me-2"></i>No labor costs are set, so time costs nothing here. '
            . (!empty($session_is_admin) ? '<a href="/admin/mit_labor_costs.php" class="alert-link">Set each agent\'s cost per hour</a>.' : 'An admin sets them under Admin > Settings > Labor costs.') . '</div>';
    } elseif ($uncosted > 0) {
        $html .= '<div class="alert alert-warning small" data-mit-profit-uncosted-note><i class="fas fa-exclamation-triangle me-2"></i>' . escapeHtml(reportHours($uncosted)) . ' h were logged by agents with no cost per hour and no company default - their time costs nothing here. '
            . (!empty($session_is_admin) ? '<a href="/admin/mit_labor_costs.php" class="alert-link">Labor costs</a>' : '') . '</div>';
    }
    return $html;
}

/*
 * ---------- Admin > Settings > Labor costs ----------
 */

/** The settings page's "set now" line. */
function mitProfitSettingsSummary() {
    $count = count(mitProfitRates());
    $default = mitProfitDefaultCost();
    return $count . ' agent' . ($count === 1 ? '' : 's') . ' costed · default ' . ($default === null ? 'none' : escapeHtml(number_format($default, 2)) . ' / h');
}
