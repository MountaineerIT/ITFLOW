<?php

/*
 * Mountaineer IT - more rows on Admin > Settings > Notifications: switches for the emails and app notices that scheduling,
 * assigning and our own features send, in ITFlow's own table (App / Tech email / Client email columns). `mit_settings` notify_<key>
 * ('0' = off; no row = on, so nothing changes until a box is unticked). Rows: one line in `admin/settings_notification.php` before
 * </tbody> (`mitNotificationRows`), saved by one line in `admin/post/settings_notification.php` (`mitNotificationsSave`, only when
 * the rows were on the page). Checked by `mitNotifyOn($key)` - guarded lines in functions/ticket.php ticketMailSchedule and
 * functions/ticket_fields.php (ticket / recurring ticket assigned), and inside our own senders.
 */

/** The switches: key => [section, label, help, column app|tech|client]. */
function mitNotificationSwitches() {
    return [
        'assign_app' => ['Assigning', 'Ticket or recurring ticket assigned to a tech', 'The bell notice to the tech, when someone else assigns it.', 'app'],
        'assign_email' => ['Assigning', 'Ticket or recurring ticket assigned to a tech', 'The email to the tech, when someone else assigns it.', 'tech'],
        'board_app' => ['Assigning', 'Ticket landed on or was moved to a service board', 'The bell notice to the board\'s members who have notices on (Admin > Settings > Boards).', 'app'],
        'board_email' => ['Assigning', 'Ticket landed on or was moved to a service board', 'The email to those members.', 'tech'],
        'helper_copy' => ['Ticket replies', 'Copy of the client\'s replies to additional techs', 'The lead tech gets ITFlow\'s own email; this is the copy to the other techs on the ticket.', 'tech'],
        'po_app' => ['Procurement', 'Purchase order waiting for approval', 'The bell notice to the approvers.', 'app'],
        'po_email' => ['Procurement', 'Purchase order waiting for approval', 'The email to the approvers.', 'tech'],
        'timesheet_app' => ['Timesheets', 'Timesheet notices (sent back, approved, changed by someone else, reminders)', 'The bell notice to the person. When reminders go out is set under Admin > Timesheets.', 'app'],
        'timesheet_email' => ['Timesheets', 'Timesheet notices (sent back, approved, changed by someone else, reminders)', 'The email to the person.', 'tech'],
    ];
}

/** Is this notice switched on? (no setting = on) */
function mitNotifyOn($key) {
    return function_exists('mitSetting') ? (string) mitSetting("notify_$key", '1') !== '0' : true;
}

/** The rows for ITFlow's Notifications table (5 columns: Notification / App / Tech email / Client email / Create Ticket). */
function mitNotificationRows() {
    $rows = [];
    foreach (mitNotificationSwitches() as $key => [$section, $label, $help, $column]) {
        $rows[$section][$label][$column] = [$key, $help];
    }
    $icons = ['Scheduling' => 'calendar-alt', 'Assigning' => 'user-check', 'Ticket replies' => 'reply', 'Procurement' => 'shopping-cart', 'Timesheets' => 'business-time'];
    $html = '<input type="hidden" name="mit_notify_present" value="1">' . mitScheduleNotifyRows();
    foreach ($rows as $section => $labels) {
        $html .= '<tr data-mit-notify-section><th colspan=5>' . escapeHtml($section) . '</th></tr>';
        foreach ($labels as $label => $columns) {
            $help = implode(' ', array_map(function ($c) { return $c[1]; }, $columns));
            $html .= '<tr><th><div><i class="fas fa-fw fa-' . ($icons[$section] ?? 'bell') . ' me-2"></i>' . escapeHtml($label) . '</div>'
                . '<small class="text-secondary">' . escapeHtml($help) . '</small></th>';
            foreach (['app', 'tech', 'client'] as $column) {
                $html .= '<td>';
                if (isset($columns[$column])) {
                    $key = $columns[$column][0];
                    $html .= '<div class="form-check text-center"><input type="checkbox" class="form-check-input" name="mit_notify[' . $key . ']" value="1" id="mitNotify_' . $key
                        . '" data-mit-notify="' . $key . '"' . (mitNotifyOn($key) ? ' checked' : '') . '><label class="form-check-label" for="mitNotify_' . $key . '"></label></div>';
                }
                $html .= '</td>';
            }
            $html .= '<td></td></tr>';
        }
    }
    $html .= '<tr><th colspan=5 class="small text-secondary fw-normal">Set elsewhere: new-ticket and SLA alert addresses (Settings > Ticket and SLA),'
        . ' Customer Thermometer surveys (Integrations), cron problem alerts (Maintenance > Cron), timesheet reminder days (Admin > Timesheets),'
        . ' and each board member\'s own notices (Settings > Boards).</th></tr>';
    return $html;
}

/** Save the switches (from the Notifications form). */
function mitNotificationsSave(array $post) {
    if (empty($post['mit_notify_present'])) {
        return;
    }
    $on = (array) ($post['mit_notify'] ?? []);
    foreach (array_keys(mitNotificationSwitches()) as $key) {
        mitSetSetting("notify_$key", empty($on[$key]) ? '0' : '1');
    }
    $schedule = (array) ($post['mit_schedule'] ?? []);
    foreach (['remote', 'onsite'] as $mode) {
        foreach (array_keys(MIT_SCHEDULE_WHO) as $who) {
            mitSetSetting("notify_schedule_{$mode}_$who", empty($schedule[$mode][$who]) ? '0' : '1');
        }
        mitSetSetting("notify_schedule_{$mode}_extra", implode(', ', mitScheduleEmails((string) ($schedule[$mode]['extra'] ?? ''))));
    }
}

/*
 * ---------- who is emailed when a ticket is scheduled ----------
 * Defaults per kind of visit (remote / onsite) on the Notifications page; the Schedule window and Dispatch's dialog show them as ticks to
 * change for one booking ($GLOBALS['mit_schedule_notify'], set by mitScheduleNotifyFromPost). Read in ITFlow's ticketMailSchedule through
 * mitScheduleNotify (tech, contact, watchers) and mitScheduleExtraMail (site contact, client's primary contact, extra addresses). Everyone
 * but the tech also needs ITFlow's "Send clients general notification emails". Cancellations follow the booking's kind.
 */

const MIT_SCHEDULE_WHO = ['tech' => 'Assigned tech', 'contact' => 'Ticket contact', 'watchers' => 'Watchers', 'site' => "The site's contact",
    'primary' => "Client's primary contact"];
const MIT_SCHEDULE_DEFAULT_ON = ['tech', 'contact', 'watchers'];

/** Valid addresses from a typed list (commas, semicolons, spaces or lines), at most 10. */
function mitScheduleEmails($text) {
    $out = [];
    foreach (preg_split('/[\s,;]+/', (string) $text) as $email) {
        $email = strtolower(trim($email));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && !in_array($email, $out, true)) {
            $out[] = $email;
        }
    }
    return array_slice($out, 0, 10);
}

/** The company's defaults for remote (false) or onsite (true) visits: who => bool, plus 'extra' => [emails]. */
function mitScheduleDefaults($onsite) {
    $mode = $onsite ? 'onsite' : 'remote';
    $out = [];
    foreach (array_keys(MIT_SCHEDULE_WHO) as $who) {
        $out[$who] = (string) mitSetting("notify_schedule_{$mode}_$who", in_array($who, MIT_SCHEDULE_DEFAULT_ON, true) ? '1' : '0') !== '0';
    }
    $out['extra'] = mitScheduleEmails((string) mitSetting("notify_schedule_{$mode}_extra", ''));
    return $out;
}

/** Who to email for this ticket's booking: the choice made for this booking, else the defaults for its kind of visit. */
function mitScheduleChoice($ticket_id) {
    global $mysqli;
    if (isset($GLOBALS['mit_schedule_notify']) && is_array($GLOBALS['mit_schedule_notify'])) {
        $choice = $GLOBALS['mit_schedule_notify'];
    } else {
        $onsite = intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT ticket_onsite FROM tickets WHERE ticket_id = " . intval($ticket_id)))[0] ?? 0);
        $choice = mitScheduleDefaults($onsite);
    }
    // Dispatch's / the helper invite's "tech only"
    if (!empty($GLOBALS['mit_schedule_skip_contact'])) {
        foreach (array_keys(MIT_SCHEDULE_WHO) as $who) {
            $choice[$who] = $who === 'tech' ? ($choice['tech'] ?? true) : false;
        }
        $choice['extra'] = [];
    }
    return $choice;
}

/** Is $who (tech | contact | watchers | site | primary) emailed for this ticket's booking? $row: a ticket row (ticket_id). */
function mitScheduleNotify($row, $who) {
    $ticket_id = is_array($row) ? intval($row['ticket_id'] ?? 0) : intval($row);
    return !empty(mitScheduleChoice($ticket_id)[$who]);
}

/** Read the ticks posted with a booking (the Schedule window, Dispatch). */
function mitScheduleNotifyFromPost(array $post) {
    if (empty($post['mit_schedule_notify_present'])) {
        return;
    }
    $on = (array) ($post['mit_schedule_notify'] ?? []);
    $choice = [];
    foreach (array_keys(MIT_SCHEDULE_WHO) as $who) {
        $choice[$who] = !empty($on[$who]);
    }
    $choice['extra'] = mitScheduleEmails((string) ($post['mit_schedule_notify_extra'] ?? ''));
    $GLOBALS['mit_schedule_notify'] = $choice;
}

/** The ticket's site contact and the client's primary contact: who => [email, name] (only those with an address). */
function mitScheduleOtherContacts($ticket_id) {
    global $mysqli;
    $ticket_id = intval($ticket_id);
    $out = [];
    $site = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT c.contact_email, c.contact_name FROM tickets t
        JOIN locations l ON l.location_id = IF(t.ticket_location_id > 0, t.ticket_location_id, (SELECT contact_location_id FROM contacts WHERE contact_id = t.ticket_contact_id))
        JOIN contacts c ON c.contact_id = l.location_contact_id AND c.contact_archived_at IS NULL WHERE t.ticket_id = $ticket_id"));
    if ($site && filter_var($site['contact_email'], FILTER_VALIDATE_EMAIL)) {
        $out['site'] = [$site['contact_email'], $site['contact_name']];
    }
    $primary = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT c.contact_email, c.contact_name FROM tickets t
        JOIN contacts c ON c.contact_client_id = t.ticket_client_id AND c.contact_primary = 1 AND c.contact_archived_at IS NULL WHERE t.ticket_id = $ticket_id LIMIT 1"));
    if ($primary && filter_var($primary['contact_email'], FILTER_VALIDATE_EMAIL)) {
        $out['primary'] = [$primary['contact_email'], $primary['contact_name']];
    }
    return $out;
}

/**
 * The extra schedule emails (site contact, primary contact, typed addresses) - one guarded line in ITFlow's ticketMailSchedule adds
 * them to its $data after the contact and watchers, with its own $from (subject + calendar file) and texts; anyone already in $data
 * is left out. Returns rows for addToMailQueue (SQL-escaped).
 */
function mitScheduleExtraMail(array $row, array $from, $what, $client_link, $footer, array $data) {
    global $mysqli;
    $ticket_id = intval($row['ticket_id']);
    $choice = mitScheduleChoice($ticket_id);
    $have = [];
    foreach ($data as $mail) {
        $have[strtolower(stripslashes((string) $mail['recipient']))] = true;
    }
    $people = [];
    foreach (mitScheduleOtherContacts($ticket_id) as $who => [$email, $name]) {
        if (!empty($choice[$who])) {
            $people[] = [$email, $name];
        }
    }
    foreach ((array) ($choice['extra'] ?? []) as $email) {
        $people[] = [$email, $email];
    }
    $reference = ticketReference($row);
    $out = [];
    foreach ($people as [$email, $name]) {
        if (isset($have[strtolower($email)])) {
            continue;
        }
        $have[strtolower($email)] = true;
        $body = "<div class='header'>Hello" . ($name !== $email ? ', ' . escapeHtml($name) : '') . ",</div>$what<br><br>Please do not reply to this email.<br><br><strong>Ticket:</strong> "
            . escapeHtml($reference) . "<br><strong>Subject:</strong> " . escapeHtml($row['ticket_subject']) . "<br><br>$footer";
        $out[] = $from + ['recipient' => mysqli_real_escape_string($mysqli, $email), 'recipient_name' => mysqli_real_escape_string($mysqli, $name),
            'body' => mysqli_real_escape_string($mysqli, $body)];
    }
    return $out;
}

/** Notifications page: the defaults table for scheduled visits. */
function mitScheduleNotifyRows() {
    $defaults = ['remote' => mitScheduleDefaults(false), 'onsite' => mitScheduleDefaults(true)];
    $html = '<tr data-mit-notify-section><th colspan=5>Scheduling</th></tr><tr><th colspan=5 class="fw-normal">'
        . '<div class="mb-2"><i class="fas fa-fw fa-calendar-alt me-2"></i><strong>Ticket scheduled, moved or cancelled</strong> - who is emailed, with a calendar invite.'
        . ' These are the defaults; the Schedule window and Dispatch show them as ticks to change for one booking. Everyone but the tech also needs'
        . ' "Send clients general notification emails" on. Techs whose Outlook calendar is synced get the booking there either way.</div>'
        . '<table class="table table-sm table-bordered mb-0 small" data-mit-schedule-defaults style="max-width: 40rem"><thead><tr><th>Who</th>'
        . '<th class="text-center"><i class="fas fa-headset me-1"></i>Remote</th><th class="text-center"><i class="fas fa-car me-1"></i>Onsite</th></tr></thead><tbody>';
    foreach (MIT_SCHEDULE_WHO as $who => $label) {
        $html .= '<tr><td>' . escapeHtml($label) . '</td>';
        foreach (['remote', 'onsite'] as $mode) {
            $html .= '<td class="text-center"><input type="checkbox" class="form-check-input" name="mit_schedule[' . $mode . '][' . $who . ']" value="1" data-mit-schedule="'
                . $mode . '-' . $who . '"' . ($defaults[$mode][$who] ? ' checked' : '') . '></td>';
        }
        $html .= '</tr>';
    }
    $html .= '<tr><td>Also email<div class="text-secondary">e.g. a front desk - commas between</div></td>';
    foreach (['remote', 'onsite'] as $mode) {
        $html .= '<td><input type="text" class="form-control form-control-sm" name="mit_schedule[' . $mode . '][extra]" maxlength="1000" data-mit-schedule="' . $mode
            . '-extra" value="' . escapeHtml(implode(', ', $defaults[$mode]['extra'])) . '" placeholder="none"></td>';
    }
    return $html . '</tr></tbody></table></th></tr>';
}

/** The Schedule window: the ticks for this booking, switching to the onsite / remote defaults as the visit changes (agent/js/mit_schedule_notify.js). */
function mitScheduleNotifyField(array $row) {
    global $config_ticket_client_general_notifications;
    $ticket_id = intval($row['ticket_id']);
    $names = ['tech' => (string) ($row['user_name'] ?? ''), 'contact' => (string) ($row['contact_name'] ?? '')];
    foreach (mitScheduleOtherContacts($ticket_id) as $who => [$email, $name]) {
        $names[$who] = $name;
    }
    $watchers = count(ticketWatcherEmails(array_column(mysqli_fetch_all(mysqli_query($GLOBALS['mysqli'],
        "SELECT watcher_email FROM ticket_watchers WHERE watcher_ticket_id = $ticket_id"), MYSQLI_ASSOC), 'watcher_email')));
    $defaults = ['0' => mitScheduleDefaults(false), '1' => mitScheduleDefaults(true)];
    $current = $defaults[intval($row['ticket_onsite']) ? '1' : '0'];
    $html = '<div class="mb-3" data-mit-schedule-notify data-defaults="' . escapeHtml(json_encode($defaults)) . '">'
        . '<input type="hidden" name="mit_schedule_notify_present" value="1"><label>Email</label><div class="d-flex flex-wrap gap-3 small">';
    foreach (MIT_SCHEDULE_WHO as $who => $label) {
        $detail = $who === 'watchers' ? ($watchers ? " ($watchers)" : ' (none)') : (($names[$who] ?? '') !== '' ? ' (' . $names[$who] . ')' : ' (none)');
        $html .= '<div class="form-check"><input class="form-check-input" type="checkbox" name="mit_schedule_notify[' . $who . ']" value="1" id="mitSchedNotify_' . $who
            . '" data-who="' . $who . '"' . ($current[$who] ? ' checked' : '') . '><label class="form-check-label" for="mitSchedNotify_' . $who . '">'
            . escapeHtml($label . $detail) . '</label></div>';
    }
    $html .= '</div><input type="text" class="form-control form-control-sm mt-2" name="mit_schedule_notify_extra" data-who="extra" maxlength="1000" placeholder="Also email (commas between)" value="'
        . escapeHtml(implode(', ', $current['extra'])) . '">';
    if (intval($config_ticket_client_general_notifications) !== 1) {
        $html .= '<div class="form-text">Client notifications are off (Admin > Settings > Notifications) - only the tech is emailed.</div>';
    }
    return $html . '</div><script src="' . mitAsset('/agent/js/mit_schedule_notify.js') . '"></script>';
}

