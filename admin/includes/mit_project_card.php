<?php
/*
 * Mountaineer IT - "Project tickets" card under Admin > Settings > Project (functions/mit_projects.php,
 * handler in admin/post/mit_project_settings.php).
 */
?>

    <div class="card card-dark">
        <div class="card-header py-3">
            <h3 class="card-title"><i class="fas fa-fw fa-project-diagram me-2"></i>Project tickets</h3>
        </div>
        <div class="card-body">
            <form action="post.php" method="post" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

                <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" name="mit_project_tickets_apart" value="1" id="mitProjectTicketsApart" <?= mitProjectTicketsApart() ? 'checked' : '' ?>>
                    <label class="form-check-label" for="mitProjectTicketsApart">Keep project tickets apart from service tickets</label>
                </div>
                <div class="form-text mb-3">
                    A ticket in a project is left off the Tickets board (it has its own <strong>Projects</strong> view there), the client's Tickets tab
                    and the Dispatch queue, and has no SLA - the project's due dates count instead. Techs still see their own in My tickets,
                    the Dispatch workload and on the calendar. When a ticket leaves its project, its SLA starts again from that moment.
                </div>

                <label class="form-label" for="mitProjectTicketColor">Calendar color for project bookings</label>
                <div class="d-flex align-items-center gap-2">
                    <input type="color" class="form-control form-control-color" name="mit_project_ticket_color" id="mitProjectTicketColor" value="<?= escapeHtml(mitProjectTicketColor()) ?>">
                    <span class="form-text m-0">On the calendar and Dispatch, with a small dot in the tech's own color.</span>
                </div>

                <hr>

                <button type="submit" name="mit_edit_project_tickets" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i>Save</button>
            </form>
        </div>
    </div>
