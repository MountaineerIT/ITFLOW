<?php

/*
 * Mountaineer IT: cron health - is cron running, and is every switched-on job
 * keeping up? ITFlow already records each job's last run, status and error
 * (cron_jobs, Maintenance > Cron); this reads them and says so where people
 * look:
 *   - a warning next to the bell for admins (includes/mit_cron_nav.php),
 *   - an email to admins when a job fails or falls behind, again once a day
 *     while it lasts, and when it is fixed (cron job mit_cron_watch),
 *   - a health address for an outside uptime monitor (/mit_health.php), the only
 *     thing that can tell anyone cron itself has stopped.
 * Settings (mit_settings): cron_alert_email (1/0), cron_alert_to (addresses;
 * empty = every active admin), cron_health_key, cron_alert_state (what was last
 * emailed). Settings card: admin/includes/mit_cron_card.php on Maintenance > Cron.
 */

// How long a running job may take before it counts as stuck, in minutes
const MIT_CRON_STUCK_MINUTES = 120;

/*
 * What is wrong, as a list of problems, each:
 *   key (stays the same while the problem lasts), job (name or ''), label,
 *   kind (not_running / off / failed / overdue / stuck), text, detail (error text, admins only).
 * Empty when all is well.
 */
function mitCronProblems() {
    global $mysqli;
    static $problems = null;
    if ($problems !== null) {
        return $problems;
    }
    $problems = [];

    $settings = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT config_enable_cron, config_cron_last_dispatch_at FROM settings WHERE company_id = 1"));
    $heartbeat = $settings['config_cron_last_dispatch_at'] ?? null;
    // Same rule as Maintenance > Cron: the dispatcher writes this every minute
    if (!$heartbeat || time() - strtotime($heartbeat) >= 300) {
        $problems[] = ['key' => 'not_running', 'job' => '', 'label' => 'Cron', 'kind' => 'not_running',
            'text' => 'Cron is not running - last heard from ' . ($heartbeat ? mitCronAgo($heartbeat) : 'never') . '. No mail is sent, no email becomes a ticket and nothing is invoiced.',
            'detail' => ''];
    }
    if (intval($settings['config_enable_cron'] ?? 0) === 0) {
        $problems[] = ['key' => 'off', 'job' => '', 'label' => 'Cron', 'kind' => 'off',
            'text' => 'Cron is switched off in Maintenance > Cron - every job stops itself.', 'detail' => ''];
        return $problems; // the jobs are not expected to run, so not late either
    }

    require_once __DIR__ . '/../includes/cron_jobs.php';
    $jobs = [];
    foreach (cronJobRegistry() as $job) {
        $jobs[$job['name']] = $job;
    }
    $sql = mysqli_query($mysqli, "SELECT cron_job_name, cron_job_enabled, cron_job_schedule, cron_job_interval_minutes, cron_job_daily_at,
        cron_job_last_run_at, cron_job_last_status, cron_job_last_error, cron_job_last_error_at FROM cron_jobs WHERE cron_job_enabled = 1");
    while ($row = mysqli_fetch_assoc($sql)) {
        $name = $row['cron_job_name'];
        if (!isset($jobs[$name])) {
            continue; // not a job this release knows - the dispatcher ignores it too
        }
        $label = $jobs[$name]['label'];
        $last_run = $row['cron_job_last_run_at'] ? strtotime($row['cron_job_last_run_at']) : 0;
        $status = (string) $row['cron_job_last_status'];
        // A job that has never run: late once it has been waiting longer than it may (counted from when it was first
        // seen waiting - the table keeps no "enabled since")
        if (!$last_run) {
            $waiting = (string) mitSetting("cron_never_ran_$name", '');
            if ($waiting === '') {
                mitSetSetting("cron_never_ran_$name", date('Y-m-d H:i:s'));
            } elseif (time() - strtotime($waiting) > mitCronAllowedGap($row) && !in_array('not_running', array_column($problems, 'kind'), true)) {
                $problems[] = ['key' => "never:$name", 'job' => $name, 'label' => $label, 'kind' => 'overdue',
                    'text' => "$label has never run - it has been waiting since " . mitCronAgo($waiting) . '.', 'detail' => ''];
            }
            continue;
        }
        if (mitSetting("cron_never_ran_$name", '') !== '') {
            mitDeleteSetting("cron_never_ran_$name");
        }

        if ($status === 'Failed') {
            $problems[] = ['key' => "failed:$name", 'job' => $name, 'label' => $label, 'kind' => 'failed',
                'text' => "$label failed " . mitCronAgo($row['cron_job_last_error_at'] ?: $row['cron_job_last_run_at']) . '.',
                'detail' => mb_substr((string) $row['cron_job_last_error'], 0, 500)];
        } elseif ($status === 'Running' && $last_run && time() - $last_run > MIT_CRON_STUCK_MINUTES * 60) {
            $problems[] = ['key' => "stuck:$name", 'job' => $name, 'label' => $label, 'kind' => 'stuck',
                'text' => "$label started " . mitCronAgo($row['cron_job_last_run_at']) . ' and is still running - it may have died part way.', 'detail' => ''];
        } elseif ($last_run && time() - $last_run > mitCronAllowedGap($row) && !in_array('not_running', array_column($problems, 'kind'), true)) {
            // Late only means something while cron itself is running; otherwise "not running" says it all
            $problems[] = ['key' => "overdue:$name", 'job' => $name, 'label' => $label, 'kind' => 'overdue',
                'text' => "$label is behind - it last ran " . mitCronAgo($row['cron_job_last_run_at']) . '.', 'detail' => ''];
        }
    }

    // The Microsoft 365 certificate (functions/mit_m365.php): renewed by the mail jobs 30 days before it runs out - a
    // renewal that keeps failing is reported here, so it is fixed before mail stops
    if (function_exists('mitM365App') && ($app = mitM365App())) {
        $expires = $app['cert_expires'] !== '' ? strtotime($app['cert_expires']) : 0;
        if ($app['renew_error'] !== '' || ($expires && $expires - time() < 14 * 86400)) {
            $problems[] = ['key' => 'm365_certificate', 'job' => '', 'label' => 'Microsoft 365', 'kind' => 'failed',
                'text' => 'The Microsoft 365 certificate ' . ($expires && $expires < time() ? 'has run out' : ($expires ? 'runs out ' . date('M j', $expires) : 'could not be renewed'))
                    . ' - mail stops when it does. See Admin > Settings > Mail.',
                'detail' => mb_substr($app['renew_error'], 0, 500)];
        }
    }
    return $problems;
}

// How long a job may go without a run before it is late, in seconds
function mitCronAllowedGap(array $row) {
    if ($row['cron_job_schedule'] === 'Daily') {
        return 26 * 3600; // a day, plus slack for a long run before it
    }
    $interval = max(1, intval($row['cron_job_interval_minutes']));
    return ($interval + max(10, $interval)) * 60; // two intervals, and never less than ten minutes over
}

function mitCronAgo($datetime) {
    if (empty($datetime)) {
        return 'never';
    }
    $seconds = max(0, time() - strtotime($datetime));
    if ($seconds < 120) {
        return 'just now';
    }
    if ($seconds < 7200) {
        return round($seconds / 60) . ' minutes ago';
    }
    if ($seconds < 172800) {
        return round($seconds / 3600) . ' hours ago';
    }
    return round($seconds / 86400) . ' days ago (' . date('M j, g:i A', strtotime($datetime)) . ')';
}

// The warning next to the bell - admins only
function mitCronNavHtml() {
    global $session_is_admin, $user_config_theme_dark;
    if (empty($session_is_admin)) {
        return '';
    }
    try {
        $problems = mitCronProblems();
    } catch (Throwable $e) {
        return '';
    }
    if (!$problems) {
        return '';
    }
    $items = '';
    foreach ($problems as $problem) {
        $icon = in_array($problem['kind'], ['not_running', 'failed'], true) ? 'fa-times-circle text-danger' : 'fa-exclamation-circle text-warning';
        $items .= "<div class='px-3 py-2 border-bottom small'><i class='fas fa-fw $icon me-1'></i>" . escapeHtml($problem['text'])
            . ($problem['detail'] !== '' ? "<div class='text-body-secondary text-truncate' title='" . escapeHtml($problem['detail']) . "'>" . escapeHtml($problem['detail']) . "</div>" : '')
            . "</div>";
    }
    $count = count($problems);
    return "<li class='nav-item dropdown' id='mitCronHealth'>
        <a class='nav-link' href='#' role='button' data-bs-toggle='dropdown' aria-expanded='false' title='Cron needs attention'>
            <i class='fas fa-heartbeat text-warning' aria-hidden='true'></i>
            <span class='badge rounded-pill text-bg-danger navbar-badge'>$count</span>
        </a>
        <div class='dropdown-menu dropdown-menu-end p-0 itflow-notify-panel' data-bs-theme='" . (!empty($user_config_theme_dark) ? 'dark' : 'light') . "' style='min-width: 22rem'>
            <div class='itflow-notify-head border-bottom pb-2'><strong class='me-auto'><i class='fas fa-heartbeat me-2'></i>Cron needs attention</strong></div>
            $items
            <a class='dropdown-item text-center small py-2' href='/admin/cron.php'>Open Maintenance &gt; Cron</a>
        </div>
    </li>";
}

// Who the emails go to: the addresses set, or every active admin
function mitCronAlertRecipients() {
    global $mysqli;
    $set = array_filter(array_map('trim', preg_split('/[\s,;]+/', (string) mitSetting('cron_alert_to', ''))), function ($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL);
    });
    if ($set) {
        return array_fill_keys($set, '');
    }
    $recipients = [];
    $sql = mysqli_query($mysqli, "SELECT user_name, user_email FROM users JOIN user_roles ON role_id = user_role_id
        WHERE role_is_admin = 1 AND user_type = 1 AND user_status = 1 AND user_archived_at IS NULL");
    while ($row = mysqli_fetch_assoc($sql)) {
        if (filter_var($row['user_email'], FILTER_VALIDATE_EMAIL)) {
            $recipients[$row['user_email']] = $row['user_name'];
        }
    }
    return $recipients;
}

/*
 * The cron job: email the admins about problems that are new, still there a
 * day later, or gone. Cron is running when this runs, so "not running" never
 * comes up here - that is what the health address is for. Returns lines for the log.
 */
function mitCronWatch() {
    global $config_smtp_provider, $config_mail_from_email, $config_mail_from_name, $config_base_url;

    $problems = array_values(array_filter(mitCronProblems(), function ($problem) {
        return $problem['kind'] !== 'not_running' && $problem['job'] !== 'mit_cron_watch';
    }));
    $state = json_decode((string) mitSetting('cron_alert_state', ''), true);
    $state = is_array($state) ? $state : [];
    $now = time();

    $new = $reminders = [];
    $next_state = [];
    foreach ($problems as $problem) {
        $sent = intval($state[$problem['key']] ?? 0);
        if (!$sent) {
            $new[] = $problem;
            $next_state[$problem['key']] = $now;
        } elseif ($now - $sent >= 86400) {
            $reminders[] = $problem;
            $next_state[$problem['key']] = $now;
        } else {
            $next_state[$problem['key']] = $sent;
        }
    }
    $fixed = array_diff_key($state, $next_state);
    mitSetSetting('cron_alert_state', json_encode($next_state));

    if (!$new && !$reminders && !$fixed) {
        return ['Nothing new'];
    }
    if (mitSetting('cron_alert_email', '1') !== '1' || empty($config_smtp_provider)) {
        return ['Email alerts are off - ' . count($problems) . ' problem(s)'];
    }

    $list = function (array $items) {
        $html = '<ul>';
        foreach ($items as $problem) {
            $html .= '<li>' . escapeHtml($problem['text']) . ($problem['detail'] !== '' ? '<br><small>' . escapeHtml($problem['detail']) . '</small>' : '') . '</li>';
        }
        return $html . '</ul>';
    };
    $body = '';
    if ($new) {
        $body .= '<p><strong>New:</strong></p>' . $list($new);
    }
    if ($reminders) {
        $body .= '<p><strong>Still not fixed:</strong></p>' . $list($reminders);
    }
    if ($fixed) {
        $body .= $problems
            ? '<p><strong>Fixed since the last email:</strong> ' . count($fixed) . ' problem' . (count($fixed) === 1 ? '' : 's') . '.</p>'
            : '<p><strong>All clear</strong> - every cron job is running again.</p>';
    }
    $body .= '<p><a href="https://' . escapeHtml($config_base_url) . '/admin/cron.php">Open Maintenance &gt; Cron</a></p>';
    $subject = $problems ? 'ITFlow cron needs attention (' . count($problems) . ')' : 'ITFlow cron: all clear';

    $mail = [];
    foreach (mitCronAlertRecipients() as $email => $name) {
        $mail[] = ['from' => escapeSql(mitMailFrom()['email']), 'from_name' => escapeSql(mitMailFrom()['name']),
            'recipient' => escapeSql($email), 'recipient_name' => escapeSql($name), 'subject' => escapeSql($subject),
            'body' => mitMailBody($body)]; // not escapeSql: it would strip the list and the link
    }
    if ($mail) {
        addToMailQueue($mail);
    }
    return ["Emailed " . count($mail) . " - new " . count($new) . ", reminders " . count($reminders) . ", fixed " . count($fixed)];
}

// The secret in the health address - made the first time it is asked for
function mitCronHealthKey($renew = false) {
    $key = (string) mitSetting('cron_health_key', '');
    if ($key === '' || $renew) {
        $key = bin2hex(random_bytes(20));
        mitSetSetting('cron_health_key', $key);
    }
    return $key;
}

function mitCronHealthUrl() {
    global $config_base_url;
    return 'https://' . $config_base_url . '/mit_health.php?key=' . mitCronHealthKey();
}
