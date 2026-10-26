<?php
/*
 * Mountaineer IT - the ticket timers running in this browser, next to the bell (js/mit_timers.js).
 * ITFlow keeps a ticket's timer in the browser (agent/js/ticket_time_tracking.js), so this lists
 * what that browser has: running and paused timers, with their time, to open, pause or throw away.
 * Hidden until the script finds a timer.
 */
?>
<?= function_exists('mitDevBadge') ? mitDevBadge() : '' // development mode badge (functions/mit_dev_mode.php) ?>
<?= function_exists('mitAiPanelNav') ? mitAiPanelNav() : '' // the Ask AI button and panel (functions/mit_ai_ask.php) ?>
<li class="nav-item dropdown" id="mitTimers" hidden>
    <a class="nav-link" href="#" role="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" title="Ticket timers">
        <i class="fas fa-stopwatch" aria-hidden="true"></i>
        <span class="badge rounded-pill navbar-badge" data-timers-badge></span>
    </a>
    <div class="dropdown-menu dropdown-menu-end p-0 itflow-notify-panel mit-timers-panel" data-bs-theme="<?= $user_config_theme_dark ? 'dark' : 'light' ?>">
        <div class="itflow-notify-head border-bottom pb-2">
            <strong class="me-auto"><i class="fas fa-stopwatch me-2"></i>Ticket timers</strong>
            <small class="text-body-secondary">In this browser</small>
        </div>
        <div data-timers-list></div>
    </div>
    <script src="<?= mitAsset('/js/mit_timers.js') ?>" defer></script><?php // inside the <li>: nothing else may sit in the navbar's <ul>; the script adds its own styles ?>
</li>
