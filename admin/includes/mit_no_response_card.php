<?php
/*
 * Mountaineer IT - "Messages to clients" card under Admin > Settings > Ticket: the standard message for "Resolve - no response"
 * (functions/mit_no_response.php) and the "ticket assigned" email of a status that meets the response target
 * (functions/mit_status_response.php); handler admin/post/mit_no_response_settings.php. The "Ticket resolved" email is ITFlow's own.
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
            <h3 class="card-title"><i class="fas fa-fw fa-envelope-open-text me-2"></i>Messages to clients</h3>
        </div>
        <div class="card-body">
            <form action="post.php" method="post" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

                <h6 class="mb-1">Resolve - no response</h6>
                <p class="small text-body-secondary">The arrow beside Resolve has "Resolve - no response...": it sends this to a client who never
                    answered (instead of ITFlow's "Ticket resolved" email), resolves the ticket and sends no satisfaction survey. Agents can change it for each
                    ticket before it goes.</p>
                <textarea class="form-control" name="mit_no_response_message" id="mitNoResponseTemplate" rows="8" maxlength="5000" data-mit-no-response-template><?= escapeHtml(mitNoResponseTemplate()) ?></textarea>
                <div class="form-text">Filled in for the ticket: <?= $mit_placeholder_list(mitNoResponsePlaceholders()) ?> left empty, the built-in wording is used.</div>

                <?php if (function_exists('mitStatusResponseReady') && mitStatusResponseReady()) { ?>
                <hr>

                <h6 class="mb-1">Ticket assigned</h6>
                <p class="small text-body-secondary">Sent when a ticket moves into a status set to meet the SLA response target with an email (Admin >
                    Ticket Statuses - an "Assigned" status, say), before anyone has replied. It is kept as a public note and counts as the first response.
                    <?php $mit_sr_names = array_map(function ($id) { return (string) (ticketStatusRow($id)['ticket_status_name'] ?? ''); }, array_keys(array_filter(mitStatusResponseStatuses())));
                    echo $mit_sr_names ? 'Statuses that send it now: <strong>' . escapeHtml(implode(', ', $mit_sr_names)) . '</strong>.' : 'No status sends it yet.'; ?></p>
                <textarea class="form-control" name="mit_status_response_message" rows="8" maxlength="5000" data-mit-status-response-template><?= escapeHtml(mitStatusResponseTemplate()) ?></textarea>
                <div class="form-text">Filled in for the ticket: <?= $mit_placeholder_list(mitStatusResponsePlaceholders()) ?> left empty, the built-in wording is used.
                    A first line that is only a greeting is left out - ITFlow's email greets each person itself.</div>
                <?php } ?>

                <hr>

                <button type="submit" name="mit_edit_no_response" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i>Save</button>
            </form>
        </div>
    </div>
