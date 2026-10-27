<?php
/*
 * Mountaineer IT - "Calendar" card under Admin > Settings > Ticket: the color of booked service tickets
 * (functions/mit_calendar.php mitServiceCalendarItems; handler admin/post/mit_calendar_settings.php).
 */
?>

    <div class="card card-dark" data-mit-calendar-card>
        <div class="card-header py-3">
            <h3 class="card-title"><i class="fas fa-fw fa-calendar-alt me-2"></i>Calendar</h3>
        </div>
        <div class="card-body">
            <form action="post.php" method="post" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

                <label class="form-label" for="mitServiceTicketColor">Calendar color for service ticket bookings</label>
                <div class="d-flex align-items-center gap-2">
                    <input type="color" class="form-control form-control-color" name="mit_service_ticket_color" id="mitServiceTicketColor" value="<?= escapeHtml(mitServiceTicketColor()) ?>">
                    <span class="form-text m-0">On the calendar and Dispatch, with a small dot in the assigned agent's own color. Project bookings
                        have their own color (Settings > Project); calendar entries take their assigned agent's color.</span>
                </div>

                <hr>

                <button type="submit" name="mit_edit_calendar_colors" class="btn btn-primary text-bold"><i class="fas fa-check me-2"></i>Save</button>
            </form>
        </div>
    </div>
