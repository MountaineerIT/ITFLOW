<?php
/*
 * Mountaineer IT - the Bugs & ideas switch, on Admin > Debug (functions/mit_feedback.php,
 * handler admin/post/mit_feedback_settings.php). Off until an admin turns it on.
 */

if (function_exists('mitFeedbackReady') && mitFeedbackReady()) {
    $mit_feedback_on = mitFeedbackOn();
    $mit_feedback_open = mitFeedbackOpenCount(); ?>

    <div class="card card-dark" data-mit-feedback-card>
        <div class="card-header py-3">
            <h3 class="card-title"><i class="fas fa-fw fa-bug me-2"></i>Bugs &amp; ideas</h3>
        </div>
        <div class="card-body">
            <p class="text-muted">
                A <i class="fas fa-bug"></i> button in the top bar for every agent: send a bug report or a feature request from the page
                it is about, with a screenshot. Everyone sees the list; admins mark reports done. Turned off, the button is gone and
                nothing can be sent - reports already sent are kept for when it is turned on again<?= $mit_feedback_open ? ' (' . number_format($mit_feedback_open) . ' open now)' : '' ?>.
            </p>
            <form action="post.php" method="post" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" role="switch" id="mitFeedbackEnabled" name="mit_feedback_enabled" value="1" <?= $mit_feedback_on ? 'checked' : '' ?> data-mit-feedback-enabled>
                    <label class="form-check-label" for="mitFeedbackEnabled">Show the Bugs &amp; ideas button</label>
                </div>
                <button type="submit" name="mit_feedback_settings" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i>Save</button>
            </form>
        </div>
    </div>

<?php }
