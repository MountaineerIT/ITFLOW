<?php

/*
 * Mountaineer IT - recurring tickets and invoices made ahead (26.11.3.2, the user's request; M055).
 *
 * Recurring tickets: a time, length and visit (remote / onsite) for the work - each ticket raised is booked for its run day at that time
 * ("before the 1st of its month" - M056 lead_mode 'month', month-based repeats only - makes a month's runs on one day: site visits)
 * with ITFlow's ticketSetSchedule (the usual emails, note, Dispatch) - and "Create it N days before": the nightly job raises the ticket
 * that many days ahead of its Next run, which stays the work date (the user's choice). Table mit_recurring_ticket_plan.
 * Hooks: the condition of the nightly job's recurring-ticket query (mitRecurringTicketDueSql), one line in recurringTicketRunNow
 * (mitRecurringTicketBook), the Schedule tab of both recurring ticket windows (mitRecurringTicketPlanField) and the add / edit handlers
 * (mitRecurringTicketPlanSave).
 *
 * Recurring invoices: "Make it N days before" the Next invoice date. The invoice is dated the day it is made and is due on the Next
 * invoice date, or that date + the client's net terms (contractNetTerms) - the user's "hybrid". Table mit_recurring_invoice_plan.
 * Hooks: the condition of the nightly recurring-invoice query (mitRecurringInvoiceDueSql) and its generate call (early when ahead), one
 * line in recurringInvoiceGenerate (mitRecurringInvoiceDue), the billing form (mitRecurringInvoicePlanField) and the add / edit handlers
 * (mitRecurringInvoicePlanSave). With 0 days nothing changes from ITFlow's own behaviour.
 */

/** Has database update M055 run? */
function mitRecurringLeadReady() {
    global $mysqli;
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = mysqli_num_rows(mysqli_query($mysqli, "SHOW TABLES LIKE 'mit_recurring_ticket_plan'")) === 1;
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/** Days in one step of a frequency (a month = 28, the shortest) - "made ahead" stays under it, so one run is never made twice. */
function mitRecurringStepDays($frequency) {
    $step = RECURRING_TICKET_FREQUENCIES[$frequency] ?? null;
    if ($step) {
        return isset($step['days']) ? intval($step['days']) : 28 * intval($step['months']);
    }
    $map = ['day' => 1, 'week' => 7, 'month' => 28, 'quarter' => 84, 'year' => 365];
    $frequency = strtolower((string) $frequency);
    foreach ($map as $word => $days) {
        if (strpos($frequency, $word) !== false) {
            return $days;
        }
    }
    return 28;
}

/** Days ahead, kept between 0 and one less than a step (and 90 at most). */
function mitRecurringLeadClamp($days, $frequency) {
    return max(0, min(intval($days), 90, mitRecurringStepDays($frequency) - 1));
}

/*
 * ---------- Recurring tickets ----------
 */

/** Has M056 run (days ahead counted from the 1st of the run's month)? */
function mitRecurringLeadModeReady() {
    global $mysqli;
    static $ready = null;
    if ($ready === null) {
        $ready = mitRecurringLeadReady() && mysqli_num_rows(mysqli_query($mysqli, "SHOW COLUMNS FROM mit_recurring_ticket_plan LIKE 'lead_mode'")) === 1;
    }
    return $ready;
}

/** Month-based repeats - the only ones that may count days ahead from the 1st of the month (a weekly one would make several weeks at once). */
function mitRecurringMonthBased($frequency) {
    return isset(RECURRING_TICKET_FREQUENCIES[$frequency]['months']);
}

/** A recurring ticket's plan: time ('HH:MM' or ''), minutes, onsite, lead days, lead mode ('days' before the run | 'month' = before the 1st of its month). */
function mitRecurringTicketPlan($recurring_ticket_id) {
    global $mysqli;
    $plan = ['time' => '', 'minutes' => CALENDAR_TICKET_MINUTES, 'onsite' => 0, 'lead_days' => 0, 'lead_mode' => 'days'];
    if (!mitRecurringLeadReady() || !intval($recurring_ticket_id)) {
        return $plan;
    }
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT schedule_time, schedule_minutes, schedule_onsite, lead_days"
        . (mitRecurringLeadModeReady() ? ', lead_mode' : '') . " FROM mit_recurring_ticket_plan WHERE recurring_ticket_id = " . intval($recurring_ticket_id)));
    if ($row) {
        $plan = ['time' => $row['schedule_time'] ? substr($row['schedule_time'], 0, 5) : '', 'minutes' => intval($row['schedule_minutes']),
            'onsite' => intval($row['schedule_onsite']), 'lead_days' => intval($row['lead_days']), 'lead_mode' => ($row['lead_mode'] ?? '') === 'month' ? 'month' : 'days'];
    }
    return $plan;
}

/** The day the ticket for a run on $run is made under $plan ('Y-m-d'). */
function mitRecurringTicketCreateOn($run, array $plan) {
    $run = substr((string) $run, 0, 10);
    $from = $plan['lead_mode'] === 'month' ? substr($run, 0, 8) . '01' : $run;
    return date('Y-m-d', strtotime($from . ' -' . intval($plan['lead_days']) . ' days'));
}

/** The nightly job's condition: due today, missed, or its make-ahead day has come (days before the run, or before the 1st of its month). */
function mitRecurringTicketDueSql() {
    if (!mitRecurringLeadReady()) {
        return "recurring_ticket_next_run <= CURDATE()";
    }
    $from = mitRecurringLeadModeReady()
        ? "IF(p.lead_mode = 'month', DATE_FORMAT(recurring_tickets.recurring_ticket_next_run, '%Y-%m-01'), DATE(recurring_tickets.recurring_ticket_next_run))"
        : "DATE(recurring_tickets.recurring_ticket_next_run)";
    return "(recurring_ticket_next_run <= CURDATE() OR EXISTS (SELECT 1 FROM mit_recurring_ticket_plan p
        WHERE p.recurring_ticket_id = recurring_tickets.recurring_ticket_id AND p.lead_days > 0 AND DATE_SUB($from, INTERVAL p.lead_days DAY) <= CURDATE()))";
}

/** Time, how long, visit and "create it N days before" on the Schedule tab ($recurring_ticket_id 0 = a new one). */
function mitRecurringTicketPlanField($recurring_ticket_id = 0) {
    if (!mitRecurringLeadReady()) {
        return '';
    }
    $plan = mitRecurringTicketPlan($recurring_ticket_id);
    $lengths = mitScheduleLengths();
    if (!isset($lengths[$plan['minutes']])) {
        $lengths[$plan['minutes']] = $plan['minutes'] . ' min';
        ksort($lengths);
    }
    $length_options = '';
    foreach ($lengths as $minutes => $label) {
        $length_options .= '<option value="' . intval($minutes) . '"' . (intval($minutes) === $plan['minutes'] ? ' selected' : '') . '>' . escapeHtml($label) . '</option>';
    }
    $frequency = '';
    $next_run = '';
    if (intval($recurring_ticket_id)) {
        $rt = mysqli_fetch_row(mysqli_query($GLOBALS['mysqli'], "SELECT recurring_ticket_frequency, recurring_ticket_next_run FROM recurring_tickets WHERE recurring_ticket_id = " . intval($recurring_ticket_id)));
        [$frequency, $next_run] = [(string) ($rt[0] ?? ''), substr((string) ($rt[1] ?? ''), 0, 10)];
    }
    $mode_options = '<option value="days"' . ($plan['lead_mode'] === 'days' ? ' selected' : '') . '>days before the run day</option>';
    if (mitRecurringLeadModeReady()) {
        $mode_options .= '<option value="month"' . ($plan['lead_mode'] === 'month' ? ' selected' : '') . '>days before the 1st of its month</option>';
    }
    $made_on = '';
    if ($next_run !== '' && $plan['lead_days'] > 0) {
        $on = mitRecurringTicketCreateOn($next_run, $plan);
        $made_on = '<div class="small mt-1" data-mit-recurring-made-on><i class="far fa-calendar-plus me-1"></i>Next one is made '
            . escapeHtml(date('M j, Y', strtotime(max($on, date('Y-m-d'))))) . ' for ' . escapeHtml(date('M j, Y', strtotime($next_run))) . '.</div>';
    }
    $zone = function_exists('mitViewShifted') && mitViewShifted(mitViewZone())
        ? '<div class="form-text">Company time (' . escapeHtml($GLOBALS['config_timezone'] ?? date_default_timezone_get()) . ').</div>' : '';
    return '<div class="border rounded p-2 pt-1 mb-3" data-mit-recurring-plan><input type="hidden" name="mit_plan_present" value="1">'
        . '<div class="small fw-semibold text-body-secondary mb-1"><i class="fa fa-fw fa-calendar-check me-1"></i>Book the work</div>'
        . '<div class="row g-2">'
        . '<div class="col-sm-4"><label class="small">Time</label><input type="time" class="form-control" name="mit_plan_time" step="900" value="' . escapeHtml($plan['time']) . '">' . $zone . '</div>'
        . '<div class="col-sm-4"><label class="small">How long</label><select class="form-select" name="mit_plan_minutes">' . $length_options . '</select></div>'
        . '<div class="col-sm-4"><label class="small">Visit</label><select class="form-select" name="mit_plan_onsite"><option value="0"' . ($plan['onsite'] ? '' : ' selected') . '>Remote</option>'
        . '<option value="1"' . ($plan['onsite'] ? ' selected' : '') . '>Onsite</option></select></div>'
        . '<div class="col-12"><label class="small">Create it</label><div class="input-group"><input type="number" class="form-control" name="mit_plan_lead" min="0" max="90" style="max-width: 7rem" value="' . intval($plan['lead_days']) . '">'
        . '<select class="form-select" name="mit_plan_lead_mode">' . $mode_options . '</select></div>' . $made_on . '</div>'
        . '</div>'
        . '<div class="form-text">With a time, each ticket is booked for its run day at that time with its agent, and emailed as when it is scheduled on the ticket - '
        . 'leave the time empty to book nothing. 0 days makes it on the run day. "Before the 1st of its month" makes every run in a month on the same day - '
        . '3 makes all of December\'s on November 28, each booked for its own day (monthly, quarterly, twice-yearly and yearly repeats).</div>'
        . '</div>';
}

/** Save the plan from the add / edit form (only when the form had it). */
function mitRecurringTicketPlanSave($recurring_ticket_id, array $post) {
    global $mysqli;
    $recurring_ticket_id = intval($recurring_ticket_id);
    if (!$recurring_ticket_id || empty($post['mit_plan_present']) || !mitRecurringLeadReady()) {
        return;
    }
    $frequency = (string) (mysqli_fetch_row(mysqli_query($mysqli, "SELECT recurring_ticket_frequency FROM recurring_tickets WHERE recurring_ticket_id = $recurring_ticket_id"))[0] ?? '');
    $time = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', trim((string) ($post['mit_plan_time'] ?? ''))) ? "'" . trim($post['mit_plan_time']) . ":00'" : 'NULL';
    $minutes = max(15, min(24 * 60, intval($post['mit_plan_minutes'] ?? CALENDAR_TICKET_MINUTES)));
    $onsite = intval($post['mit_plan_onsite'] ?? 0) ? 1 : 0;
    // Before the 1st of its month: month-based repeats only, 0-27 days (made in the month before at the earliest, never a step early)
    $mode = ($post['mit_plan_lead_mode'] ?? '') === 'month' && mitRecurringMonthBased($frequency) && mitRecurringLeadModeReady() ? 'month' : 'days';
    $lead = $mode === 'month' ? max(0, min(27, intval($post['mit_plan_lead'] ?? 0))) : mitRecurringLeadClamp($post['mit_plan_lead'] ?? 0, $frequency);
    $mode_sql = mitRecurringLeadModeReady() ? ", lead_mode = '$mode'" : '';
    mysqli_query($mysqli, "INSERT INTO mit_recurring_ticket_plan SET recurring_ticket_id = $recurring_ticket_id, schedule_time = $time, schedule_minutes = $minutes,
        schedule_onsite = $onsite, lead_days = $lead $mode_sql
        ON DUPLICATE KEY UPDATE schedule_time = $time, schedule_minutes = $minutes, schedule_onsite = $onsite, lead_days = $lead $mode_sql");
}

/**
 * After recurringTicketRunNow raised $ticket_id for the run $row was due (recurring_ticket_next_run = the work date): book it for that day
 * at the plan's time (today when the run was missed). Nothing without a time.
 */
function mitRecurringTicketBook($ticket_id, array $row) {
    global $session_name, $session_user_id;
    $plan = mitRecurringTicketPlan($row['recurring_ticket_id'] ?? 0);
    if ($plan['time'] === '' || !intval($ticket_id)) {
        return;
    }
    $day = max(date('Y-m-d'), substr((string) ($row['recurring_ticket_next_run'] ?? ''), 0, 10));
    $start = $day . ' ' . $plan['time'] . ':00';
    $end = date('Y-m-d H:i:s', strtotime($start) + $plan['minutes'] * 60);
    $ticket = ticketRow(intval($ticket_id));
    if (!$ticket) {
        return;
    }
    // The cron has no one signed in - the booking's history and note say it was the recurring ticket
    $was = [$session_name ?? null, $session_user_id ?? null];
    if (empty($session_name)) {
        $GLOBALS['session_name'] = 'Recurring ticket';
        $GLOBALS['session_user_id'] = intval($GLOBALS['session_user_id'] ?? 0);
    }
    try {
        ticketSetSchedule($ticket, $start, $plan['onsite'], $end);
    } finally {
        [$GLOBALS['session_name'], $GLOBALS['session_user_id']] = $was;
    }
}

/** "Made 7 days ahead, booked 9:00 am" - for the recurring tickets list / window. */
function mitRecurringTicketPlanText($recurring_ticket_id) {
    $plan = mitRecurringTicketPlan($recurring_ticket_id);
    $bits = [];
    if ($plan['time'] !== '') {
        $bits[] = 'booked ' . date('g:i a', strtotime('2000-01-01 ' . $plan['time']));
    }
    if ($plan['lead_days']) {
        $bits[] = 'made ' . $plan['lead_days'] . ' day' . ($plan['lead_days'] === 1 ? '' : 's') . ($plan['lead_mode'] === 'month' ? ' before the 1st of its month' : ' ahead');
    }
    return $bits ? ucfirst(implode(', ', $bits)) . '.' : '';
}

/*
 * ---------- Recurring invoices ----------
 */

/** A recurring invoice's plan: lead days and due mode ('terms' | 'next'). */
function mitRecurringInvoicePlan($recurring_invoice_id) {
    global $mysqli;
    $plan = ['lead_days' => 0, 'due_mode' => 'terms'];
    if (!mitRecurringLeadReady() || !intval($recurring_invoice_id)) {
        return $plan;
    }
    $row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT lead_days, due_mode FROM mit_recurring_invoice_plan WHERE recurring_invoice_id = " . intval($recurring_invoice_id)));
    if ($row) {
        $plan = ['lead_days' => intval($row['lead_days']), 'due_mode' => $row['due_mode'] === 'next' ? 'next' : 'terms'];
    }
    return $plan;
}

/** The nightly job's condition: due today (or missed within ITFlow's grace) or within its lead days. */
function mitRecurringInvoiceDueSql() {
    if (!mitRecurringLeadReady()) {
        return "recurring_invoice_next_date <= CURDATE()";
    }
    return "recurring_invoice_next_date <= CURDATE() + INTERVAL COALESCE((SELECT p.lead_days FROM mit_recurring_invoice_plan p
        WHERE p.recurring_invoice_id = recurring_invoices.recurring_invoice_id), 0) DAY";
}

/** "Make it N days before" and "Due" on the recurring invoice form. */
function mitRecurringInvoicePlanField($recurring_invoice_id = 0, $client_id = 0) {
    if (!mitRecurringLeadReady()) {
        return '';
    }
    $plan = mitRecurringInvoicePlan($recurring_invoice_id);
    $terms = intval($client_id) && function_exists('contractNetTerms') ? intval(contractNetTerms(intval($client_id), date('Y-m-d'))) : null;
    $terms_label = $terms === null ? "Next invoice + client's terms" : ($terms > 0 ? "Next invoice + Net $terms" : 'Next invoice (Due on receipt)');
    return '<div class="col-sm-6" data-mit-recurring-invoice-plan><input type="hidden" name="mit_plan_present" value="1">'
        . '<label class="form-label">Make it</label><div class="input-group"><input type="number" class="form-control" name="mit_plan_lead" min="0" max="90" value="' . intval($plan['lead_days']) . '">'
        . '<span class="input-group-text">days before</span></div>'
        . '<div class="form-text">0 = on the Next invoice date. One made early is dated the day it is made.</div></div>'
        . '<div class="col-sm-6"><label class="form-label">Due when made early</label>'
        . '<select class="form-select" name="mit_plan_due"><option value="terms"' . ($plan['due_mode'] === 'terms' ? ' selected' : '') . '>' . escapeHtml($terms_label) . '</option>'
        . '<option value="next"' . ($plan['due_mode'] === 'next' ? ' selected' : '') . '>On the Next invoice date</option></select>'
        . '<div class="form-text">The client\'s terms count from the Next invoice date.</div></div>';
}

/** Save the plan from the add / edit form (only when the form had it). */
function mitRecurringInvoicePlanSave($recurring_invoice_id, array $post) {
    global $mysqli;
    $recurring_invoice_id = intval($recurring_invoice_id);
    if (!$recurring_invoice_id || empty($post['mit_plan_present']) || !mitRecurringLeadReady()) {
        return;
    }
    $frequency = (string) (mysqli_fetch_row(mysqli_query($mysqli, "SELECT recurring_invoice_frequency FROM recurring_invoices WHERE recurring_invoice_id = $recurring_invoice_id"))[0] ?? '');
    $lead = mitRecurringLeadClamp($post['mit_plan_lead'] ?? 0, $frequency);
    $mode = ($post['mit_plan_due'] ?? '') === 'next' ? 'next' : 'terms';
    mysqli_query($mysqli, "INSERT INTO mit_recurring_invoice_plan SET recurring_invoice_id = $recurring_invoice_id, lead_days = $lead, due_mode = '$mode'
        ON DUPLICATE KEY UPDATE lead_days = $lead, due_mode = '$mode'");
}

/**
 * In recurringInvoiceGenerate, after the invoice is made: one made before its run date ($date < $due) under a plan with lead days is due
 * on the run date, or the run date + the client's net terms. Nothing changes for one made on its date or without lead days.
 */
function mitRecurringInvoiceDue($invoice_id, $recurring_invoice_id, $due, $date) {
    global $mysqli;
    $invoice_id = intval($invoice_id);
    if (!$invoice_id || (string) $date >= (string) $due) {
        return;
    }
    $plan = mitRecurringInvoicePlan($recurring_invoice_id);
    if (!$plan['lead_days']) {
        return;
    }
    $days = 0;
    if ($plan['due_mode'] === 'terms') {
        $client_id = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT invoice_client_id FROM invoices WHERE invoice_id = $invoice_id"))[0] ?? 0);
        $days = function_exists('contractNetTerms') ? max(0, intval(contractNetTerms($client_id, $due))) : 0;
    }
    $due_sql = escapeSql(date('Y-m-d', strtotime($due . " +$days days")));
    mysqli_query($mysqli, "UPDATE invoices SET invoice_due = '$due_sql' WHERE invoice_id = $invoice_id");
}
