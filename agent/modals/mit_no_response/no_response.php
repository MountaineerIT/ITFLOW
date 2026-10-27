<?php

/*
 * Mountaineer IT - "Resolve - no response" (functions/mit_no_response.php, handler agent/post/mit_no_response.php). ?ticket_id=
 */

require_once '../../../includes/modal_header.php';

enforceUserPermission('module_support', 2);

$row = ticketForModal($_GET['ticket_id'] ?? 0, 2, true);
$ticket_id = intval($row['ticket_id']);
$message = mitNoResponseFill(mitNoResponseTemplate(), $row);
$can_mail = !empty($config_smtp_provider) && !empty($row['contact_email']);

ob_start();

?>

<div class="modal-header bg-dark">
    <h5 class="modal-title">
        <i class="fa fa-fw fa-comment-slash me-2"></i>Resolve - no response <small class="ms-1 opacity-75"><?= escapeHtml(ticketReference($row)) ?></small>
    </h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <div class="modal-body">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        <input type="hidden" name="ticket_id" value="<?= $ticket_id ?>">

        <p class="small text-body-secondary">For a client who has not answered after several tries. The message goes on the ticket as a public
            note<?= $can_mail ? ' and is emailed to ' . escapeHtml($row['contact_name']) . ' (and the watchers)' : ' - nobody is emailed (' . (empty($config_smtp_provider) ? 'mail is not set up' : 'the contact has no email address') . ')' ?>.
            The ticket is then resolved and closes on its own as usual; if they reply before that, it reopens. No satisfaction survey is sent.</p>

        <label class="form-label" for="mitNoResponseMessage">Message</label>
        <textarea class="form-control" name="message" id="mitNoResponseMessage" rows="9" maxlength="5000" required><?= escapeHtml($message) ?></textarea>
        <div class="form-text">Change it here for this ticket; the standard wording is under Admin > Settings > Ticket.</div>
    </div>
    <div class="modal-footer">
        <button type="submit" name="mit_no_response_resolve" class="btn btn-success text-bold"><i class="fas fa-check me-2"></i><?= $can_mail ? 'Send and resolve' : 'Resolve' ?></button>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>
</form>

<?php

require_once '../../../includes/modal_footer.php';
