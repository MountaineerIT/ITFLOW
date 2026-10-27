<?php
/*
 * Mountaineer IT - "Messages when resolving" card under Admin > Settings > Ticket: the "Ticket resolved" email's wording (ITFlow's
 * own until changed) and the standard message for "Resolve - no response" (functions/mit_no_response.php; handler
 * admin/post/mit_no_response_settings.php).
 */

$mit_placeholder_list = function (array $tags) {
    $html = '';
    foreach ($tags as $tag => $what) {
        $html .= '<code>' . escapeHtml($tag) . '</code> ' . escapeHtml($what) . '; ';
    }
    return $html;
};
?>

    <div class="card card-dark" data-mit-no-response-card>
        <div class="card-header py-3">
            <h3 class="card-title"><i class="fas fa-fw fa-check-circle me-2"></i>Messages when resolving</h3>
        </div>
        <div class="card-body">
            <form action="post.php" method="post" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

                <h6 class="mb-1">Ticket resolved email</h6>
                <p class="small text-body-secondary">Sent to the contact and watchers when a ticket is resolved (with client notifications on).
                    <?= mitResolvedTemplateSaved() === '' ? 'This is ITFlow\'s standard wording - change it to use your own.' : 'Your own wording is in use - empty the box to go back to ITFlow\'s.' ?>
                    It goes out in ITFlow's email layout: your logo, a "Hello &lt;name&gt;," for each person (so a greeting line at the top of
                    yours is left out), the ticket's details and a "View or reopen it" button. Where [Note] is, the agent's note is quoted.</p>
                <textarea class="form-control" name="mit_resolved_message" id="mitResolvedTemplate" rows="10" maxlength="5000" data-mit-resolved-template><?= escapeHtml(mitResolvedTemplate()) ?></textarea>
                <div class="form-text mb-4">Filled in for the ticket: <?= $mit_placeholder_list(mitResolvedPlaceholders()) ?></div>

                <h6 class="mb-1">Resolve - no response</h6>
                <p class="small text-body-secondary">The arrow beside Resolve has "Resolve - no response...": it sends this to a client who never
                    answered (instead of the email above), resolves the ticket and sends no satisfaction survey. Agents can change it for each
                    ticket before it goes.</p>
                <textarea class="form-control" name="mit_no_response_message" id="mitNoResponseTemplate" rows="8" maxlength="5000" data-mit-no-response-template><?= escapeHtml(mitNoResponseTemplate()) ?></textarea>
                <div class="form-text">Filled in for the ticket: <?= $mit_placeholder_list(mitNoResponsePlaceholders()) ?> left empty, the built-in wording is used.</div>

                <hr>

                <button type="submit" name="mit_edit_no_response" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i>Save</button>
            </form>
        </div>
    </div>
