<?php

/*
 * Mountaineer IT - add a tech to help on a ticket, or change / remove one (functions/mit_ticket_techs.php).
 * ?ticket_id= to add, &user_id= to change that tech's booking.
 */

require_once '../../../includes/modal_header.php';

enforceUserPermission('module_support', 2);

$row = ticketForModal($_GET['ticket_id'] ?? 0, 2);
$ticket_id = intval($row['ticket_id']);
$lead_id = intval($row['ticket_assigned_to']);
$techs = mitTicketTechs($ticket_id);
$edit_user_id = intval($_GET['user_id'] ?? 0);
$tech = $techs[$edit_user_id] ?? null;
$is_open = ticketIsOpen($row);

// Booked: with the lead, at their own time, or not at all
$mode = $tech ? (intval($tech['follows_lead']) === 1 ? 'lead' : ($tech['start'] !== '' ? 'own' : 'none')) : 'lead';
$own_start = $mode === 'own' ? $tech['start'] : ($row['ticket_schedule'] ?: '');
$own_minutes = $mode === 'own' ? mitTicketScheduleMinutes($tech['start'], $tech['end'])
    : ($row['ticket_schedule'] ? mitTicketScheduleMinutes($row['ticket_schedule'], $row['ticket_schedule_end'] ?? null) : CALENDAR_TICKET_MINUTES);
$lengths = mitScheduleLengths();
if (!isset($lengths[$own_minutes])) {
    $lengths[$own_minutes] = $own_minutes . ' min';
    ksort($lengths);
}
$lead_when = $row['ticket_schedule'] ? mitScheduleWhenText($row['ticket_schedule'], $row['ticket_schedule_end'] ?? null) : '';

// Who can be added: active techs who are not the lead, not on it already, and can see the ticket's client
$agents = [];
if (!$tech) {
    $sql_agents = mysqli_query($mysqli, "SELECT user_id, user_name FROM users WHERE user_type = 1 AND user_status = 1 AND user_archived_at IS NULL AND user_id <> $lead_id ORDER BY user_name");
    while ($agent = mysqli_fetch_assoc($sql_agents)) {
        if (!isset($techs[intval($agent['user_id'])]) && mitAgentCanWorkTicket($agent['user_id'], $row['ticket_client_id'])) {
            $agents[] = $agent;
        }
    }
}
$day_user = $tech ? $edit_user_id : intval($agents[0]['user_id'] ?? 0);
$day_name = $tech ? $tech['user_name'] : ($agents[0]['user_name'] ?? '');

ob_start();

?>

<div class="modal-header bg-dark">
    <h5 class="modal-title">
        <i class="fa fa-fw fa-user-plus me-2"></i><?= $tech ? escapeHtml($tech['user_name']) . ' on' : 'Add an agent to' ?> <small class="ms-1 opacity-75"><?= escapeHtml(ticketReference($row)) ?></small>
    </h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<form action="post.php" method="post" autocomplete="off">
    <div class="modal-body">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        <input type="hidden" name="ticket_id" value="<?= $ticket_id ?>">

        <?php if ($tech) { ?>
            <input type="hidden" name="user_id" value="<?= $edit_user_id ?>">
        <?php } elseif (!$agents) { ?>
            <div class="alert alert-info mb-0">Every active agent is already on this ticket.</div>
        <?php } else { ?>
            <div class="mb-3">
                <label>Agent</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fa fa-fw fa-user-cog"></i></span>
                    <select class="form-select select2" name="user_id" id="scheduleTech" required>
                        <?php foreach ($agents as $agent) { ?>
                            <option value="<?= intval($agent['user_id']) ?>" data-name="<?= escapeHtml($agent['user_name']) ?>"<?= selectOptionColor(mitAgentColor($agent['user_id'])) ?>><?= escapeHtml($agent['user_name']) ?><?= intval($agent['user_id']) === intval($session_user_id) ? ' (me)' : '' ?></option>
                        <?php } ?>
                    </select>
                </div>
                <div class="form-text">The lead agent stays <?= $row['user_name'] ? '<strong>' . escapeHtml($row['user_name']) . '</strong>' : 'unassigned' ?>.</div>
            </div>
        <?php } ?>

        <?php if ($tech || $agents) { ?>
            <label class="d-block mb-1">When</label>
            <div class="mb-3" data-mit-tech-mode>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="tech_mode" value="lead" id="mitTechLead" <?= $mode === 'lead' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="mitTechLead">Same time as the lead
                        <span class="d-block small text-body-secondary"><?= $lead_when !== '' ? escapeHtml($lead_when) . ' - moves with it' : 'The lead is not scheduled yet - this follows once they are' ?></span>
                    </label>
                </div>
                <div class="form-check mt-1">
                    <input class="form-check-input" type="radio" name="tech_mode" value="own" id="mitTechOwn" <?= $mode === 'own' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="mitTechOwn">Their own time</label>
                </div>
                <div class="form-check mt-1">
                    <input class="form-check-input" type="radio" name="tech_mode" value="none" id="mitTechNone" <?= $mode === 'none' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="mitTechNone">Not scheduled <span class="small text-body-secondary">- on the ticket, nothing on their calendar</span></label>
                </div>
            </div>

            <div data-mit-tech-own <?= $mode === 'own' ? '' : 'hidden' ?>>
                <div class="row g-2 mb-3">
                    <div class="col-sm-7">
                        <label>Date and time</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fa fa-fw fa-calendar-day"></i></span>
                            <input type="datetime-local" class="form-control" name="scheduled_date_time" id="scheduleDateTime" value="<?= $own_start ? date('Y-m-d\TH:i', strtotime($own_start)) : '' ?>" min="<?= date('Y-m-d\T00:00', min(time(), $own_start ? strtotime($own_start) : time())) ?>">
                        </div>
                    </div>
                    <div class="col-sm-5">
                        <label>How long</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fa fa-fw fa-hourglass-half"></i></span>
                            <select class="form-select" name="schedule_minutes">
                                <?php foreach ($lengths as $minutes => $label) { ?>
                                    <option value="<?= intval($minutes) ?>" <?= intval($minutes) === $own_minutes ? 'selected' : '' ?>><?= escapeHtml($label) ?></option>
                                <?php } ?>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- What else they have that day (agent/js/ticket_schedule_modal.js) -->
                <div class="border rounded p-2 small mb-3" id="scheduleAgentDay" data-user="<?= $day_user ?>" data-ticket="<?= $ticket_id ?>" data-name="<?= escapeHtml($day_name) ?>" hidden>
                    <div class="d-flex align-items-center mb-1">
                        <span class="fw-semibold"><i class="far fa-calendar-alt fa-fw text-secondary me-1"></i><span class="js-day-title"></span></span>
                        <a class="ms-auto js-day-open" href="calendar.php">Calendar</a>
                    </div>
                    <div class="js-day-items"></div>
                </div>
            </div>

            <div class="form-text">They see the ticket in their lists, get its client replies by email, and are sent their schedule.</div>
        <?php } ?>
    </div>

    <div class="modal-footer">
        <?php if ($tech) { ?>
            <a href="post.php?mit_remove_ticket_tech=<?= $ticket_id ?>&user_id=<?= $edit_user_id ?>&csrf_token=<?= $_SESSION['csrf_token'] ?>" class="btn btn-outline-danger confirm-link me-auto"
                data-confirm-title="Take <?= escapeHtml($tech['user_name']) ?> off this ticket?" data-confirm-text="<?= $tech['start'] !== '' ? 'Their scheduled time is cancelled and they are emailed the cancellation.' : 'They stop seeing it in their lists.' ?>" data-confirm-button="Remove">
                <i class="fa fa-user-minus me-2"></i>Remove
            </a>
        <?php } ?>
        <?php if (($tech || $agents) && $is_open) { ?>
            <button type="submit" name="mit_save_ticket_tech" class="btn btn-primary text-bold"><i class="fa fa-check me-2"></i><?= $tech ? 'Save' : 'Add' ?></button>
        <?php } ?>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="fa fa-times me-2"></i>Cancel</button>
    </div>
</form>

<script>
    // Their own time shows its date fields - one listener for however many times the modal opens
    if (!window.mitTicketTechModeBound) {
        window.mitTicketTechModeBound = true;
        document.addEventListener('change', function (e) {
            if (e.target.name !== 'tech_mode' || !e.target.form) {
                return;
            }
            const own = e.target.form.querySelector('[data-mit-tech-own]');
            own.hidden = e.target.value !== 'own';
            const field = e.target.form.querySelector('#scheduleDateTime');
            if (!own.hidden && field) {
                field.dispatchEvent(new Event('change'));
            }
        });
    }
</script>
<script src="/agent/js/ticket_schedule_modal.js"></script>

<?php
require_once '../../../includes/modal_footer.php';
