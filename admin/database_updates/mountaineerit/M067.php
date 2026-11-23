<?php

/*
 * Mountaineer IT - database update M067
 * Included by admin/database_updates_mountaineerit.php - do not access directly
 *
 * ITFlow 26.12 (database 3.6.7 - 4.1.1) took in many features of ours as its own (the user chose ITFlow's versions, Nov 22).
 * This copies what we kept for them into ITFlow's tables, columns and settings - only where ITFlow's is still empty, so
 * nothing ITFlow or an agent set since is overwritten. Our own tables are left as they were (dropped in a later update,
 * one release on), so a mistake here can be put right by running it again after a fix.
 *   mit_ticket_techs          -> ticket_helpers (same columns)
 *   mit_ticket_mail_choices   -> ticket_mail_choices
 *   mit_reply_times           -> ticket_replies.ticket_reply_work_start / _end - ours were the person's own clock, ITFlow's
 *                                are the company's: moved from the person's time zone (mit_timesheet_people) where it differs
 *   mit_ticket_no_response    -> tickets.ticket_no_response_at
 *   mit_ticket_status_response-> ticket_statuses.ticket_status_first_response (with an email 2, without 1)
 *   mit_ticket_status_reply   -> ticket_statuses.ticket_status_reply_status_id
 *   mit_service_users         -> users.user_service_account
 *   mit_offices (ids kept)    -> offices; mit_user_offices -> users.user_office_id; pending invites' office -> invite_office_id
 *   mit_agent_cost_rates      -> user_cost_rates
 *   mit_recurring_ticket_plan / mit_recurring_invoice_plan -> the recurring_tickets / recurring_invoices columns
 *   mit_project_ticket_sla    -> tickets.ticket_sla_from (tickets that left a project)
 *   mit_user_preferences calendar.color / calendar.all / calendar.client / dispatch.state, mit_timesheet_people.time_zone
 *                             -> user_settings.user_config_calendar_color / _calendar_state / _timezone
 *   mit_settings              -> settings: quick tickets, project tickets, the "no response" and "ticket assigned" messages,
 *                                cron alerts (and the health key, so an uptime monitor's address keeps working), labor cost
 *                                default, assignment / helper notification switches, Dispatch and purchasing switches, quote
 *                                pricing; once (marker m067_settings).
 *   settings.config_api_key_in_address = 1 - MSP Align and tools written for stock ITFlow send ?api_key= (the user's choice).
 *   cron_jobs rows of our retired jobs (mit_cron_watch, mit_project_tickets) removed - ITFlow's Cron Watch and project
 *   ticket code do it now.
 *   config.php: development mode switched on with our old names ($config_mit_dev_mode) is written in ITFlow's too.
 * Purchase orders are M068. Safe to run twice.
 */

defined('ITFLOW_MIT_DB_UPDATES') || die("Direct file access is not allowed");

$m067_table = function ($table) use ($mysqli) {
    $table = mysqli_real_escape_string($mysqli, $table);
    $result = mysqli_query($mysqli, "SHOW TABLES LIKE '$table'");
    return $result && mysqli_num_rows($result) > 0;
};
$m067_has = function ($table, $column) use ($mysqli, $m067_table) {
    return $m067_table($table) && mitColumnExists($mysqli, $table, $column);
};
$m067_mit = function ($name) use ($mysqli, $m067_table) {
    if (!$m067_table('mit_settings')) {
        return null;
    }
    $name = mysqli_real_escape_string($mysqli, $name);
    $row = mysqli_fetch_row(mysqli_query($mysqli, "SELECT setting_value FROM mit_settings WHERE setting_name = '$name'"));
    return $row ? (string) $row[0] : null;
};

// Helpers on tickets
if ($m067_table('mit_ticket_techs') && $m067_table('ticket_helpers')) {
    mysqli_query($mysqli, "INSERT IGNORE INTO ticket_helpers (ticket_id, user_id, follows_lead, schedule_start, schedule_end, added_by, added_at)
        SELECT ticket_id, user_id, follows_lead, schedule_start, schedule_end, added_by, added_at FROM mit_ticket_techs");
}

// Email reply To line, remembered per ticket
if ($m067_table('mit_ticket_mail_choices') && $m067_table('ticket_mail_choices')) {
    mysqli_query($mysqli, "INSERT IGNORE INTO ticket_mail_choices (mail_choice_ticket_id, mail_choice_email, mail_choice_send, mail_choice_by, mail_choice_updated_at)
        SELECT ticket_id, email, send, updated_by, updated_at FROM mit_ticket_mail_choices");
}

// When a note's work was done - on the company's clock in ITFlow
if ($m067_table('mit_reply_times') && $m067_has('ticket_replies', 'ticket_reply_work_start')) {
    $company_zone = (string) (mysqli_fetch_row(mysqli_query($mysqli, "SELECT config_timezone FROM settings WHERE company_id = 1"))[0] ?? '');
    $company_zone = in_array($company_zone, timezone_identifiers_list(), true) ? $company_zone : date_default_timezone_get();
    $zones = [];
    if ($m067_has('mit_timesheet_people', 'time_zone')) {
        $sql = mysqli_query($mysqli, "SELECT user_id, time_zone FROM mit_timesheet_people WHERE time_zone IS NOT NULL AND time_zone <> ''");
        while ($row = mysqli_fetch_assoc($sql)) {
            if (in_array($row['time_zone'], timezone_identifiers_list(), true) && $row['time_zone'] !== $company_zone) {
                $zones[intval($row['user_id'])] = $row['time_zone'];
            }
        }
    }
    $sql = mysqli_query($mysqli, "SELECT m.reply_id, m.work_start, m.work_end, r.ticket_reply_by FROM mit_reply_times m
        JOIN ticket_replies r ON r.ticket_reply_id = m.reply_id WHERE r.ticket_reply_work_start IS NULL");
    while ($row = mysqli_fetch_assoc($sql)) {
        [$start, $end] = [$row['work_start'], $row['work_end']];
        $zone = $zones[intval($row['ticket_reply_by'])] ?? '';
        if ($zone !== '') {
            $move = function ($when) use ($zone, $company_zone) {
                $date = new DateTime($when, new DateTimeZone($zone));
                return $date->setTimezone(new DateTimeZone($company_zone))->format('Y-m-d H:i:s');
            };
            [$start, $end] = [$move($start), $move($end)];
        }
        mysqli_query($mysqli, "UPDATE ticket_replies SET ticket_reply_work_start = '" . mysqli_real_escape_string($mysqli, $start) . "',
            ticket_reply_work_end = '" . mysqli_real_escape_string($mysqli, $end) . "' WHERE ticket_reply_id = " . intval($row['reply_id']) . " AND ticket_reply_work_start IS NULL");
    }
}

// Resolved - no response
if ($m067_table('mit_ticket_no_response') && $m067_has('tickets', 'ticket_no_response_at')) {
    mysqli_query($mysqli, "UPDATE tickets t JOIN mit_ticket_no_response n ON n.ticket_id = t.ticket_id
        SET t.ticket_no_response_at = n.resolved_at WHERE t.ticket_no_response_at IS NULL");
}

// Statuses that meet the response target, and where a client's reply moves a ticket
if ($m067_table('mit_ticket_status_response') && $m067_has('ticket_statuses', 'ticket_status_first_response')) {
    mysqli_query($mysqli, "UPDATE ticket_statuses s JOIN mit_ticket_status_response r ON r.ticket_status_id = s.ticket_status_id
        SET s.ticket_status_first_response = IF(r.send_email = 1, 2, 1) WHERE s.ticket_status_first_response = 0");
}
if ($m067_table('mit_ticket_status_reply') && $m067_has('ticket_statuses', 'ticket_status_reply_status_id')) {
    mysqli_query($mysqli, "UPDATE ticket_statuses s JOIN mit_ticket_status_reply r ON r.ticket_status_id = s.ticket_status_id
        SET s.ticket_status_reply_status_id = r.reply_status_id WHERE s.ticket_status_reply_status_id = 0");
}

// API / service accounts
if ($m067_table('mit_service_users') && $m067_has('users', 'user_service_account')) {
    mysqli_query($mysqli, "UPDATE users JOIN mit_service_users s ON s.user_id = users.user_id SET users.user_service_account = 1");
}

// Offices - same ids, so purchase orders' ship-to (M068) and anything else that named one still points right
if ($m067_table('mit_offices') && $m067_table('offices')) {
    mysqli_query($mysqli, "INSERT IGNORE INTO offices (office_id, office_name, office_address, office_city, office_state, office_zip, office_country,
            office_phone_country_code, office_phone, office_email, office_created_at, office_updated_at, office_archived_at)
        SELECT office_id, office_name, office_address, office_city, office_state, office_zip, office_country,
            office_phone_country_code, office_phone, office_email, office_created_at, office_updated_at, office_archived_at FROM mit_offices");
    if ($m067_table('mit_user_offices') && $m067_has('users', 'user_office_id')) {
        mysqli_query($mysqli, "UPDATE users JOIN mit_user_offices o ON o.user_id = users.user_id SET users.user_office_id = o.office_id WHERE users.user_office_id = 0");
    }
    if ($m067_table('mit_invite_extras') && $m067_has('user_invites', 'invite_office_id')) {
        $sql = mysqli_query($mysqli, "SELECT invite_id, extras FROM mit_invite_extras");
        while ($row = mysqli_fetch_assoc($sql)) {
            $office = intval((json_decode((string) $row['extras'], true) ?: [])['mit_office_id'] ?? 0);
            if ($office) {
                mysqli_query($mysqli, "UPDATE user_invites SET invite_office_id = $office WHERE invite_id = " . intval($row['invite_id']) . " AND invite_office_id = 0");
            }
        }
    }
}

// Labor costs
if ($m067_table('mit_agent_cost_rates') && $m067_table('user_cost_rates')) {
    mysqli_query($mysqli, "INSERT IGNORE INTO user_cost_rates (user_cost_rate_user_id, user_cost_rate_from, user_cost_rate_hourly, user_cost_rate_created_by, user_cost_rate_created_at)
        SELECT user_id, effective_from, hourly_cost, IFNULL(created_by, 0), created_at FROM mit_agent_cost_rates");
}

// Recurring tickets booked at a time and made ahead; recurring invoices made ahead
if ($m067_table('mit_recurring_ticket_plan') && $m067_has('recurring_tickets', 'recurring_ticket_lead_days')) {
    $minutes = $m067_has('recurring_tickets', 'recurring_ticket_schedule_minutes') ? ", t.recurring_ticket_schedule_minutes = IFNULL(p.schedule_minutes, t.recurring_ticket_schedule_minutes)" : '';
    mysqli_query($mysqli, "UPDATE recurring_tickets t JOIN mit_recurring_ticket_plan p ON p.recurring_ticket_id = t.recurring_ticket_id
        SET t.recurring_ticket_schedule_time = p.schedule_time, t.recurring_ticket_schedule_onsite = p.schedule_onsite,
            t.recurring_ticket_lead_days = p.lead_days, t.recurring_ticket_lead_mode = IF(p.lead_mode = 'month', 'month', 'days') $minutes
        WHERE t.recurring_ticket_schedule_time IS NULL AND t.recurring_ticket_lead_days = 0");
}
if ($m067_table('mit_recurring_invoice_plan') && $m067_has('recurring_invoices', 'recurring_invoice_lead_days')) {
    mysqli_query($mysqli, "UPDATE recurring_invoices i JOIN mit_recurring_invoice_plan p ON p.recurring_invoice_id = i.recurring_invoice_id
        SET i.recurring_invoice_lead_days = p.lead_days, i.recurring_invoice_due_mode = IF(p.due_mode = 'next', 'next', 'terms')
        WHERE i.recurring_invoice_lead_days = 0");
}

// Tickets that left a project count their SLA from then
if ($m067_table('mit_project_ticket_sla') && $m067_has('tickets', 'ticket_sla_from')) {
    mysqli_query($mysqli, "UPDATE tickets t JOIN mit_project_ticket_sla p ON p.ticket_id = t.ticket_id
        SET t.ticket_sla_from = p.left_at WHERE p.in_project = 0 AND p.left_at IS NOT NULL AND p.left_at > t.ticket_created_at AND t.ticket_sla_from IS NULL");
}

// Agents' calendar colour, what their calendar and Dispatch show, and their own time zone
if ($m067_has('user_settings', 'user_config_calendar_state') && $m067_table('mit_user_preferences')) {
    $prefs = [];
    $sql = mysqli_query($mysqli, "SELECT user_id, preference, value FROM mit_user_preferences WHERE preference IN ('calendar.color', 'calendar.all', 'calendar.client', 'dispatch.state')");
    while ($row = mysqli_fetch_assoc($sql)) {
        $prefs[intval($row['user_id'])][$row['preference']] = (string) $row['value'];
    }
    foreach ($prefs as $user_id => $pref) {
        $current = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT user_config_calendar_color, user_config_calendar_state FROM user_settings WHERE user_id = $user_id"));
        if (!$current) {
            mysqli_query($mysqli, "INSERT IGNORE INTO user_settings SET user_id = $user_id");
            $current = ['user_config_calendar_color' => null, 'user_config_calendar_state' => null];
        }
        $set = [];
        $color = strtolower(trim($pref['calendar.color'] ?? ''));
        if ($current['user_config_calendar_color'] === null && preg_match('/^#[0-9a-f]{6}$/', $color)) {
            $set[] = "user_config_calendar_color = '$color'";
        }
        if ($current['user_config_calendar_state'] === null || trim((string) $current['user_config_calendar_state']) === '') {
            $state = [];
            foreach (['all' => 'calendar.all', 'client' => 'calendar.client', 'dispatch' => 'dispatch.state'] as $key => $name) {
                $value = json_decode($pref[$name] ?? '', true);
                if (is_array($value) && $value) {
                    if (isset($value['view']) && in_array($value['view'], ['timeGridMit7', 'mitTimeGrid7'], true)) {
                        $value['view'] = 'timeGridSevenDays';
                    }
                    if ($key === 'dispatch') {
                        $value = array_intersect_key($value, array_flip(['view', 'hidden', 'weekends']));
                    }
                    $state[$key] = $value;
                }
            }
            if ($state) {
                $set[] = "user_config_calendar_state = '" . mysqli_real_escape_string($mysqli, json_encode($state)) . "'";
            }
        }
        if ($set) {
            mysqli_query($mysqli, "UPDATE user_settings SET " . implode(', ', $set) . " WHERE user_id = $user_id");
        }
    }
}
if ($m067_has('user_settings', 'user_config_timezone') && $m067_has('mit_timesheet_people', 'time_zone')) {
    mysqli_query($mysqli, "INSERT IGNORE INTO user_settings (user_id) SELECT user_id FROM mit_timesheet_people WHERE time_zone IS NOT NULL AND time_zone <> ''
        AND user_id IN (SELECT user_id FROM users)");
    mysqli_query($mysqli, "UPDATE user_settings s JOIN mit_timesheet_people p ON p.user_id = s.user_id
        SET s.user_config_timezone = p.time_zone WHERE p.time_zone IS NOT NULL AND p.time_zone <> '' AND s.user_config_timezone IS NULL");
}

// Settings - once (an admin may change ITFlow's afterwards)
if ($m067_mit('m067_settings') === null && $m067_has('settings', 'config_quick_ticket_on')) {
    $set = [];
    $put = function ($column, $sql_value) use (&$set, $m067_has) {
        if ($m067_has('settings', $column)) {
            $set[] = "$column = $sql_value";
        }
    };
    $text = function ($value) use ($mysqli) {
        return "'" . mysqli_real_escape_string($mysqli, (string) $value) . "'";
    };
    $flag = function ($name, $default) use ($m067_mit) {
        $value = $m067_mit($name);
        return $value === null ? $default : ($value === '0' ? 0 : 1);
    };

    // Quick tickets
    foreach (['on' => 'flag', 'assign' => 'text', 'status' => 'int', 'priority' => 'text', 'category' => 'int', 'timer' => 'text',
                 'primary' => 'flag', 'open_tickets' => 'flag', 'email_client' => 'flag'] as $key => $kind) {
        $value = $m067_mit("quick_ticket_$key");
        if ($value === null) {
            continue;
        }
        $put("config_quick_ticket_$key", $kind === 'int' ? intval($value) : ($kind === 'flag' ? ($value === '0' ? 0 : 1) : $text($value)));
    }
    $billable = $m067_mit('quick_ticket_billable');
    if ($billable !== null) {
        $put('config_quick_ticket_billable', $billable === '' ? -1 : ($billable === '0' ? 0 : 1));
    }

    // Project tickets kept apart - ours was on unless switched off
    $put('config_project_tickets_apart', $flag('project_tickets_apart', 1));
    if (($color = $m067_mit('project_ticket_color')) !== null && preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
        $put('config_project_ticket_color', $text($color));
    }

    // Messages to clients
    if (($message = $m067_mit('no_response_message')) !== null && trim($message) !== '') {
        $put('config_ticket_no_response_message', $text($message));
    }
    if (($message = $m067_mit('status_response_message')) !== null && trim($message) !== '') {
        $put('config_ticket_assigned_message', $text(str_replace('[Tech]', '[Agent]', $message)));
    }

    // Cron alerts - the health key too, so an uptime monitor's address keeps working (/mit_health.php is an alias of /health.php)
    if (($value = $m067_mit('cron_alert_email')) !== null) {
        $put('config_cron_alert_email', $value === '0' ? 0 : 1);
    }
    if (($value = $m067_mit('cron_alert_to')) !== null && trim($value) !== '') {
        $put('config_cron_alert_to', $text($value));
    }
    if (($value = $m067_mit('cron_health_key')) !== null && trim($value) !== '') {
        $put('config_cron_health_key', $text($value));
    }

    // Labor cost default ('' = none)
    if (($value = $m067_mit('profit_default_cost')) !== null && is_numeric($value)) {
        $put('config_labor_cost_default', round(floatval($value), 2));
    }

    // Notifications to our team
    $put('config_notify_assigned_app', $flag('notify_assign_app', 1));
    $put('config_notify_assigned_email', $flag('notify_assign_email', 1));
    $put('config_notify_helper_replies', $flag('notify_helper_copy', 1));

    // Module switches - Dispatch and purchasing were on unless switched off
    $put('config_module_enable_dispatch', $flag('module_dispatch', 1));
    $put('config_module_enable_purchasing', $flag('module_procurement', 1));

    // Quote pricing
    if (($value = $m067_mit('quote_price_method')) !== null && in_array($value, ['markup', 'margin'], true)) {
        $put('config_quote_price_method', $text($value));
    }
    if (($value = $m067_mit('quote_price_percent')) !== null && is_numeric($value)) {
        $put('config_quote_price_percent', round(floatval($value), 2));
    }
    if (($value = $m067_mit('quote_price_presets')) !== null && trim($value) !== '') {
        $put('config_quote_price_presets', $text(substr(preg_replace('/[^0-9.,]/', '', $value), 0, 100)));
    }
    if (($value = $m067_mit('quote_show_images')) !== null) {
        $put('config_quote_show_images', $value === '0' ? 0 : 1);
    }

    // Purchase orders: numbering and approval (ours: approval on unless switched off, over $500 unless set)
    if (($value = $m067_mit('procurement_po_prefix')) !== null && trim($value) !== '') {
        $put('config_po_prefix', $text($value));
    }
    $put('config_po_approval', $flag('procurement_approval_on', 1));
    $over = $m067_mit('procurement_approval_over');
    $put('config_po_approval_over', $over !== null && is_numeric($over) ? round(floatval($over), 2) : 500);

    // MSP Align and tools written for stock ITFlow send the key in the address
    $put('config_api_key_in_address', 1);

    if ($set) {
        mysqli_query($mysqli, "UPDATE settings SET " . implode(', ', $set) . " WHERE company_id = 1");
    }

    // The cron alert state: ours was {key: when sent}; ITFlow's {"sent": {...}, "waiting": {job: unix time}}
    if ($m067_has('settings', 'config_cron_alert_state') && ($state = json_decode((string) $m067_mit('cron_alert_state'), true)) && is_array($state)) {
        $waiting = [];
        $sql = mysqli_query($mysqli, "SELECT setting_name, setting_value FROM mit_settings WHERE setting_name LIKE 'cron\\_never\\_ran\\_%'");
        while ($row = mysqli_fetch_assoc($sql)) {
            $when = strtotime((string) $row['setting_value']);
            if ($when) {
                $waiting[substr($row['setting_name'], strlen('cron_never_ran_'))] = $when;
            }
        }
        mysqli_query($mysqli, "UPDATE settings SET config_cron_alert_state = '" . mysqli_real_escape_string($mysqli, json_encode(['sent' => $state, 'waiting' => (object) $waiting])) . "'
            WHERE company_id = 1 AND (config_cron_alert_state IS NULL OR config_cron_alert_state = '')");
    }

    mysqli_query($mysqli, "INSERT INTO mit_settings SET setting_name = 'm067_settings', setting_value = NOW()
        ON DUPLICATE KEY UPDATE setting_value = setting_value");
}

// Our retired cron jobs - ITFlow's Cron Watch and project ticket code do them now
if ($m067_table('cron_jobs')) {
    mysqli_query($mysqli, "DELETE FROM cron_jobs WHERE cron_job_name IN ('mit_cron_watch', 'mit_project_tickets')");
}

// Development mode switched on with our old names: written in ITFlow's too (functions/dev_mode.php)
if (!isset($GLOBALS['config_dev_mode']) && !empty($GLOBALS['config_mit_dev_mode']) && function_exists('devModeConfigSet')) {
    $m067_dev_error = devModeConfigSet(true, (string) ($GLOBALS['config_mit_dev_mail_to'] ?? ''));
    if ($m067_dev_error !== '') {
        error_log("Mountaineer IT M067: development mode could not be written to config.php - $m067_dev_error");
    }
}
