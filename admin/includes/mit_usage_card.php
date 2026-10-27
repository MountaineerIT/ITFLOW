<?php
/*
 * Mountaineer IT - the Usage billing card under Admin > Settings > Invoices
 * (functions/mit_usage.php, handler in admin/post/mit_usage_settings.php).
 */

if (!function_exists('mitUsageReady') || !mitUsageReady()) {
    return;
}
$mit_usage_settings = mitUsageSettings();
?>

    <div class="card card-dark" data-mit-usage-settings>
        <div class="card-header py-3">
            <h3 class="card-title"><i class="fas fa-fw fa-tachometer-alt me-2"></i>Usage billing</h3>
        </div>
        <div class="card-body">
            <form action="post.php" method="post" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

                <p class="text-body-secondary">Recurring invoice lines marked as usage take their quantity from the client's count on the snapshot day when the
                    invoice is made, and wait as Drafts in Billing &gt; Usage Review.</p>

                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Snapshot day of the month</label>
                        <input type="number" class="form-control" name="mit_usage_snapshot_day" min="1" max="28" value="<?= $mit_usage_settings['snapshot_day'] ?>" required>
                        <div class="form-text">The count on or before this day is billed (1 - 28).</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Flag a change over (%)</label>
                        <input type="number" class="form-control" name="mit_usage_change_pct" min="0" max="1000" value="<?= $mit_usage_settings['change_pct'] ?>" required>
                        <div class="form-text">Against last month's quantity.</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Flag a count older than (days)</label>
                        <input type="number" class="form-control" name="mit_usage_stale_days" min="0" max="90" value="<?= $mit_usage_settings['stale_days'] ?>" required>
                        <div class="form-text">Before the snapshot day.</div>
                    </div>
                </div>

                <hr>
                <button type="submit" name="mit_edit_usage_settings" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i>Save</button>
            </form>
        </div>
    </div>
