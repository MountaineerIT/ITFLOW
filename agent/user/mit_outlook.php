<?php

/*
 * Mountaineer IT - Your account > Outlook calendar: connect your own Microsoft 365 account and
 * your booked tickets show in your Outlook calendar (functions/mit_outlook.php).
 */

require_once "includes/inc_all_user.php";

$mit_link = mitOutlookLink($session_user_id);
$mit_available = mitOutlookAvailable();
$mit_booked = $mit_link ? intval(mysqli_fetch_row(mysqli_query($mysqli, "SELECT COUNT(*) FROM mit_outlook_events WHERE user_id = " . intval($session_user_id)))[0]) : 0;

?>

<div class="card">
    <div class="card-header bg-dark">
        <h3 class="card-title"><i class="fas fa-fw fa-calendar-alt me-2"></i>Outlook calendar</h3>
    </div>
    <div class="card-body">

        <p class="text-body-secondary">
            Tickets booked for you show up in your Outlook calendar - on your phone too - and follow along when a booking is
            moved, changed, handed to someone else or closed. ITFlow is in charge of them: change a booking in ITFlow, not in
            Outlook, or Outlook will be put back. Your own appointments are never touched.
        </p>
        <p class="small text-body-secondary">
            While connected, booking emails to you come without the calendar invite (the booking is already in your calendar). If you
            subscribed to ITFlow's calendar feed in Outlook (Calendar &gt; <em>Your schedule on your phone</em>), remove that subscription,
            or your bookings show twice.
        </p>

        <?php if (!$mit_link) { ?>

            <?php if ($mit_available) { ?>
                <form action="post.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <button type="submit" name="mit_outlook_connect" class="btn btn-primary"><i class="fab fa-microsoft me-2"></i>Connect Outlook</button>
                </form>
                <div class="form-text mt-2">Sign in with your own Microsoft 365 account.</div>
            <?php } else { ?>
                <div class="alert alert-secondary mb-0">
                    <i class="fas fa-info-circle me-2"></i>Not available yet - an administrator needs to set up Microsoft 365 under
                    <?php if ($session_is_admin) { ?><a href="/admin/settings_mail.php">Admin &gt; Settings &gt; Mail</a><?php } else { ?>Admin &gt; Settings &gt; Mail<?php } ?> first.
                </div>
            <?php } ?>

        <?php } else { ?>

            <div class="border rounded p-3 d-flex flex-wrap gap-3 align-items-center">
                <i class="fab fa-microsoft fa-2x text-primary"></i>
                <div class="flex-grow-1" style="min-width: 14rem;">
                    <div>Connected as <strong><?= escapeHtml($mit_link['account_email']) ?></strong></div>
                    <div class="small text-body-secondary">
                        <?= $mit_link['last_sync_at'] ? 'Synced ' . escapeHtml(timeAgo($mit_link['last_sync_at'])) : 'Not synced yet' ?>
                        &middot; <?= $mit_booked ?> booking<?= $mit_booked === 1 ? '' : 's' ?> in Outlook
                    </div>
                </div>
                <form action="post.php" method="post" class="d-flex gap-2 m-0">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <button type="submit" name="mit_outlook_sync" class="btn btn-sm btn-outline-secondary"><i class="fas fa-sync-alt me-1"></i>Sync now</button>
                    <?php if (!empty($mit_link['last_error'])) { ?>
                        <button type="submit" name="mit_outlook_connect" class="btn btn-sm btn-outline-primary">Reconnect</button>
                    <?php } ?>
                    <button type="submit" name="mit_outlook_disconnect" class="btn btn-sm btn-outline-danger confirm-link"
                        data-confirm-title="Disconnect Outlook?" data-confirm-button="Disconnect"
                        data-confirm-text="ITFlow takes its scheduled tickets out of your Outlook calendar and stops syncing.">Disconnect</button>
                </form>
            </div>

            <?php if (!empty($mit_link['last_error'])) { ?>
                <div class="alert alert-danger mt-3 mb-0"><i class="fas fa-exclamation-triangle me-2"></i><?= escapeHtml($mit_link['last_error']) ?></div>
            <?php } ?>

        <?php } ?>

    </div>
</div>

<?php
require_once "../../includes/footer.php";
