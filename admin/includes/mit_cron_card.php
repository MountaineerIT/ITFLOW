<?php
/*
 * Mountaineer IT - cron alerts, on Admin > Maintenance > Cron (functions/mit_cron_health.php,
 * handler admin/post/mit_cron_health.php).
 */

if (function_exists('mitCronHealthUrl')) {
    try {
        $mit_health_url = mitCronHealthUrl();
    } catch (Throwable $e) {
        $mit_health_url = ''; // database update pending
    }
    $mit_alert_recipients = mitCronAlertRecipients();
    if ($mit_health_url) { ?>

    <div class="card card-dark">
        <div class="card-header py-3">
            <h3 class="card-title"><i class="fas fa-fw fa-heartbeat me-2"></i>Cron alerts</h3>
        </div>
        <div class="card-body">
            <p class="text-muted">
                When cron stops, a job fails, gets stuck or falls behind, admins see a <i class="fas fa-heartbeat text-warning"></i>
                warning next to the bell. The Cron Watch job above also emails about it - once when it starts, once a day while
                it lasts, and when it is fixed.
            </p>
            <form action="post.php" method="post" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="form-check form-switch mt-md-4">
                            <input class="form-check-input" type="checkbox" role="switch" id="mitCronAlertEmail" name="mit_cron_alert_email" value="1" <?= mitSetting('cron_alert_email', '1') === '1' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="mitCronAlertEmail">Email alerts</label>
                        </div>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label">Send to</label>
                        <input type="text" class="form-control" name="mit_cron_alert_to" value="<?= escapeHtml(mitSetting('cron_alert_to', '')) ?>" placeholder="Every active admin">
                        <div class="form-text">
                            Addresses, separated by commas. Empty = every active admin<?= !mitSetting('cron_alert_to', '') && $mit_alert_recipients ? ' (' . escapeHtml(implode(', ', array_keys($mit_alert_recipients))) . ')' : '' ?>.
                        </div>
                    </div>
                </div>
                <div class="mt-3">
                    <button type="submit" name="mit_edit_cron_alerts" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i>Save</button>
                    <button type="submit" name="mit_test_cron_alert" class="btn btn-outline-secondary ms-2" formnovalidate><i class="fas fa-paper-plane me-2"></i>Send a test email</button>
                </div>
            </form>

            <hr>

            <h6><i class="fas fa-fw fa-satellite-dish me-2"></i>Outside monitor</h6>
            <p class="text-muted mb-2">
                An email cannot tell you cron has stopped - it is cron that sends it. Point an uptime monitor (Uptime Kuma,
                UptimeRobot, Better Stack...) at this address every 5 minutes: it answers <code>OK</code> when all is well and
                an error (HTTP 503) when cron is not running or a job is in trouble. Keep it private - anyone with it can see
                which jobs are failing.
            </p>
            <div class="input-group">
                <input type="text" class="form-control font-monospace" id="mitHealthUrl" value="<?= escapeHtml($mit_health_url) ?>" readonly>
                <button type="button" class="btn btn-outline-secondary clipboardjs" data-clipboard-text="<?= escapeHtml($mit_health_url) ?>" title="Copy"><i class="fas fa-fw fa-copy"></i></button>
                <a class="btn btn-outline-danger confirm-link" href="post.php?mit_renew_cron_key&amp;csrf_token=<?= $_SESSION['csrf_token'] ?>"
                    data-confirm-text="The old address stops working - update your monitor with the new one." title="New address"><i class="fas fa-fw fa-sync-alt"></i></a>
            </div>
        </div>
    </div>

<?php }
}
