<?php
/*
 * Mountaineer IT - Dispatch in the agent side menu (functions/mit_dispatch.php), required from
 * agent/includes/side_nav.php in SUPPORT after Recurring Tickets. For the Dispatch permission, View or more (with ticketing
 * and Dispatch switched on), with the count of open tickets with no agent - on the agent's own service boards when they are on any.
 */

if (function_exists('mitDispatchCan') && mitDispatchCan()) {
    $mit_dispatch_sql = "SELECT COUNT(*) FROM tickets WHERE ticket_assigned_to = 0
        AND ticket_resolved_at IS NULL AND ticket_closed_at IS NULL AND ticket_archived_at IS NULL" . clientScopeSql('ticket_client_id') . mitServiceTicketsAnd()
        . (function_exists('mitBoardWhere') ? mitBoardWhere('mine', $session_user_id) : '');
    $mit_dispatch_waiting = mitNavCachedCount("dispatch:$mit_dispatch_sql", fn() => intval(mysqli_fetch_row(mysqli_query($mysqli, $mit_dispatch_sql))[0]),
        basename($_SERVER['PHP_SELF']) === 'mit_dispatch.php'); ?>
    <li class="nav-item">
        <a href="/agent/mit_dispatch.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'mit_dispatch.php' ? 'active' : '' ?>">
            <i class="nav-icon fas fa-people-arrows"></i>
            <p>
                Dispatch
                <?php if ($mit_dispatch_waiting) { ?><span class="right badge text-light" data-bs-toggle="tooltip" title="Open tickets with no agent<?= function_exists('mitBoardsOfUser') && mitBoardsOfUser($session_user_id) ? ' on your boards' : '' ?>"><?= $mit_dispatch_waiting ?></span><?php } ?>
            </p>
        </a>
    </li>
<?php }
