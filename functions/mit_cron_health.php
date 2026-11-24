<?php

/*
 * Mountaineer IT - cron health. Since 26.12 ITFlow has it itself (functions/cron_health.php: the Cron Watch job, the Alerts card on
 * Maintenance > Cron, /health.php for an uptime monitor - built from ours; M067 copied our alert settings and the health key). What
 * stays here:
 *  - mitCronExtraProblems(): the Microsoft 365 certificate our app signs in with (functions/mit_m365.php) - a renewal that keeps
 *    failing, or under 14 days left - added to ITFlow's problems (one guarded line at the end of cronHealthProblems), so Cron Watch
 *    emails it and /health.php reports it.
 *  - mitCronNavHtml(): the warning beside the bell for admins while there is a problem (includes/mit_cron_nav.php, required from the
 *    top bar) - ITFlow shows them only on Maintenance > Cron.
 *  - /mit_health.php: the address our monitors were set up with, answering as ITFlow's /health.php (the same key).
 */

/** Our problems in cronHealthProblems()'s shape. */
function mitCronExtraProblems() {
    $problems = [];
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

/** The top bar's warning for admins - nothing when all is well. */
function mitCronNavHtml() {
    global $session_is_admin, $user_config_theme_dark;
    if (empty($session_is_admin) || !function_exists('cronHealthProblems')) {
        return '';
    }
    try {
        $problems = cronHealthProblems();
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
            . (($problem['detail'] ?? '') !== '' ? "<div class='text-body-secondary text-truncate' title='" . escapeHtml($problem['detail']) . "'>" . escapeHtml($problem['detail']) . "</div>" : '')
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
