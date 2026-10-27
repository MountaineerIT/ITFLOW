<?php
/*
 * Mountaineer IT - the TIME section of the agent side menu (functions/mit_timesheets.php), required from
 * agent/includes/side_nav.php before TOOLS. "My timesheet" for everyone who keeps one; "Timesheets"
 * (everyone's) for Timesheets View and for people's own approvers, with a count of those waiting for them.
 */

$mit_ts_mine = function_exists('mitTsOn') && mitTsOn() && mitTsKeepsTimesheet($session_user_id);
$mit_ts_everyone = function_exists('mitTsOn') && mitTsOn() && (mitTsLevel() >= 1 || mitTsMyReports());
if ($mit_ts_mine || $mit_ts_everyone) {
    $mit_nav_page = basename($_SERVER['PHP_SELF']);
    $mit_ts_waiting = $mit_ts_everyone ? mitTsWaitingCount() : 0; ?>
    <li class="nav-header mt-3">TIME</li>
    <?php if ($mit_ts_mine) { ?>
    <li class="nav-item">
        <a href="/agent/mit_timesheet.php" class="nav-link <?= $mit_nav_page === 'mit_timesheet.php' ? 'active' : '' ?>">
            <i class="nav-icon fas fa-business-time"></i>
            <p>My Timesheet</p>
        </a>
    </li>
    <?php } ?>
    <?php if ($mit_ts_everyone) { ?>
        <li class="nav-item">
            <a href="/agent/mit_timesheets.php" class="nav-link <?= $mit_nav_page === 'mit_timesheets.php' ? 'active' : '' ?>">
                <i class="nav-icon fas fa-user-clock"></i>
                <p>
                    Timesheets
                    <?php if ($mit_ts_waiting) { ?><span class="right badge text-light" data-bs-toggle="tooltip" title="Waiting for approval"><?= $mit_ts_waiting ?></span><?php } ?>
                </p>
            </a>
        </li>
    <?php }
}
