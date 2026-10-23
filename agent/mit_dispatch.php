<?php

/*
 * Mountaineer IT - Dispatch (functions/mit_dispatch.php): each tech's booked work beside the tickets still
 * to dispatch. Day: a column per tech; Week: a row per tech, a column per day. Drag a ticket from the
 * queue onto a tech and time (or a booking to another tech or time) and confirm; drag a booking back onto
 * the queue to take it off the schedule. Workload: each tech's open tickets, SLA and booked hours, flagged when they
 * are falling behind or well above the team (functions/mit_dispatch_workload.php). The script is agent/js/mit_dispatch.js; its requests go to
 * agent/mit_dispatch_ajax.php. ?date=Y-m-d opens on that day.
 */

require_once "includes/inc_all.php";

if (!mitDispatchCan()) {
    if (!mitModuleOn('dispatch')) {
        flashAlert('Dispatch is switched off (Admin > Settings > Modules).', 'error');
        redirect('/agent/calendar.php');
    }
    enforceUserPermission('module_dispatch');
}

$techs = mitDispatchTechs();
$state = mitDispatchState();
$service_boards = mitDispatchBoards(); // functions/mit_boards.php
$row = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT user_config_calendar_first_day FROM user_settings WHERE user_id = $session_user_id"));
$first_day = intval($row['user_config_calendar_first_day'] ?? 0);
$clients = mysqli_fetch_all(mysqli_query($mysqli, "SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL" . clientScopeSql('client_id') . " ORDER BY client_name"), MYSQLI_ASSOC);

?>

<style>
    /* The queue's three tabs side by side - a narrow pane wraps the words inside each tab */
    [data-md-tab] { padding: .25rem .3rem !important; line-height: 1.2; height: 100%; display: flex; align-items: center; justify-content: center; flex-wrap: wrap; gap: .15rem; }
    [data-md-tab] .badge { margin-left: 0 !important; padding: .2em .4em; }
    .mit-dispatch-queue { max-height: calc(100vh - 17rem); overflow-y: auto; }
    .mit-dq-item { cursor: grab; border-left: 4px solid var(--bs-secondary-border-subtle); }
    .mit-dq-item[data-priority="Urgent"] { border-left-color: var(--bs-danger); }
    .mit-dq-item[data-priority="High"] { border-left-color: var(--bs-warning); }
    .mit-dq-item[data-priority="Medium"] { border-left-color: var(--bs-info); }
    .mit-dq-item:hover { background: var(--bs-tertiary-bg); }
    .mit-dispatch-queue.is-drop-target { outline: 2px dashed var(--bs-danger); outline-offset: -4px; }
    .mit-dispatch-cols { display: flex; overflow-x: auto; gap: 0; }
    .mit-dispatch-col { flex: 1 0 210px; min-width: 210px; border-right: 1px solid var(--bs-border-color); }
    .mit-dispatch-col:last-child { border-right: 0; }
    .mit-dispatch-col-head { position: sticky; top: 0; padding: .4rem .5rem; border-bottom: 1px solid var(--bs-border-color); background: var(--bs-body-bg); z-index: 3; }
    .mit-dispatch [data-kind="outlook_busy"] { background-image: repeating-linear-gradient(135deg, rgba(255, 255, 255, .25) 0 4px, transparent 4px 10px); opacity: .7; cursor: default; }
    /* Service and project bookings (functions/mit_calendar.php): the tech's color as stripes over the booking's */
    .mit-dispatch [data-mit-agent] { background-image: repeating-linear-gradient(135deg, var(--mit-agent) 0 6px, transparent 6px 15px); text-shadow: 0 1px 2px rgba(0, 0, 0, .65); }
    .mit-dispatch [data-kind="helper"] { outline: 2px dashed rgba(255, 255, 255, .75); outline-offset: -3px; }
    .mit-dispatch-week th, .mit-dispatch-week td { vertical-align: top; }
    .mit-dispatch-week td.mit-dw-cell { min-width: 130px; width: 200px; max-width: 200px; height: 70px; padding: .25rem; }
    /* Agent names stay at the left while the week scrolls sideways */
    .mit-dispatch-week tbody th, .mit-dispatch-week thead th:first-child { position: sticky; left: 0; z-index: 1; box-shadow: inset -1px 0 0 var(--bs-border-color), 4px 0 6px -4px rgba(0, 0, 0, .15); }
    .mit-dispatch-week thead th:first-child { z-index: 3; }
    /* Solid, so the days scrolling under the names / heading don't show through (the modern look makes headings transparent) */
    .table.mit-dispatch-week > tbody > tr > th, .table.mit-dispatch-week > thead > tr > th { background: var(--mit-panel, var(--bs-body-bg)) !important; }
    .mit-dispatch-week td.mit-dw-cell.is-off { background: var(--bs-tertiary-bg); }
    .mit-dw-chip { font-size: .78rem; border-radius: .3rem; padding: .15rem .35rem; margin-bottom: .2rem; color: #fff; cursor: grab; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
    .mit-dw-chip[data-kind="outlook_busy"], .mit-dw-chip[data-kind="helper"] { cursor: default; }
    .mit-dw-cell .sortable-ghost, .mit-dispatch-queue .sortable-ghost { opacity: .4; }
    /* Workload */
    .mit-wl-row { cursor: pointer; }
    .mit-wl-bar { height: .55rem; }
    .mit-wl-tickets > td { max-width: 1px; } /* the ticket list follows the table's width instead of widening it */
    .mit-wl .mit-wl-late { border-left: 3px solid var(--bs-danger); }
    .mit-wl-drop { border-radius: .375rem; padding: .25rem; margin: -.25rem; }
    .mit-wl-drop .mit-dq-item { display: none; }
    .mit-wl-dragging .mit-wl-drop { outline: 2px dashed var(--bs-primary); outline-offset: 2px; background: var(--bs-primary-bg-subtle); }
    /* This week */
    .mit-stats { --ms-in: #2a78d6; --ms-out: #eb6834; }
    [data-bs-theme="dark"] .mit-stats { --ms-in: #3987e5; --ms-out: #d95926; }
    .mit-ms-tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: .75rem; }
    .mit-ms-tile { border: 1px solid var(--bs-border-color); border-radius: .5rem; padding: .75rem 1rem; }
    .mit-ms-tile .mit-ms-label { font-size: .75rem; text-transform: uppercase; letter-spacing: .06em; color: var(--bs-secondary-color); }
    .mit-ms-tile .mit-ms-value { font-size: 1.75rem; font-weight: 600; line-height: 1.2; font-variant-numeric: tabular-nums; }
    .mit-ms-tile .mit-ms-sub { font-size: .8rem; color: var(--bs-secondary-color); }
    .mit-ms-key { display: inline-block; width: .7rem; height: .7rem; border-radius: 2px; margin-right: .35rem; vertical-align: -1px; }
    .mit-ms-chart { position: relative; height: 240px; }
    .mit-ms-minibar { height: 6px; border-radius: 0 3px 3px 0; min-width: 2px; }
    .mit-ms-minibar + .mit-ms-minibar { margin-top: 2px; }
    .mit-ms-table td, .mit-ms-table th { font-variant-numeric: tabular-nums; }
    /* Side by side (lg and up) the queue and the board are one height, filling the window; each scrolls inside */
    @media (min-width: 992px) {
        .mit-dispatch-pane { height: max(28rem, calc(100vh - 13.5rem)); display: flex; flex-direction: column; }
        .mit-dispatch-pane .mit-dispatch-queue { flex: 1 1 auto; min-height: 0; max-height: none; }
        .mit-dispatch-pane [data-md-board] { flex: 1 1 auto; min-height: 0; overflow: auto; }
        .mit-dispatch-pane .mit-dispatch-cols { height: 100%; }
        .mit-dispatch-pane .mit-dispatch-col { display: flex; flex-direction: column; }
        .mit-dispatch-pane .mit-dispatch-col [data-md-cal] { flex: 1 1 auto; min-height: 0; }
        .mit-dispatch-pane .mit-dispatch-week thead th { position: sticky; top: 0; z-index: 2; background: var(--bs-body-bg); }
        .mit-dispatch-pane .mit-dispatch-week thead th:first-child { z-index: 3; }
        .mit-dispatch-pane .mit-dispatch-week-wrap { overflow: visible; }
    }
</style>

<div class="mit-dispatch" id="mitDispatch"
    data-csrf="<?= $_SESSION['csrf_token'] ?>"
    data-can-edit="<?= mitDispatchCanEdit() ? 1 : 0 ?>"
    data-techs="<?= escapeHtml(json_encode(array_values($techs))) ?>"
    data-state="<?= escapeHtml(json_encode($state)) ?>"
    data-boards="<?= escapeHtml(json_encode($service_boards)) ?>"
    data-me="<?= intval($session_user_id) ?>"
    data-first-day="<?= $first_day ?>"
    data-default-minutes="<?= CALENDAR_TICKET_MINUTES ?>"
    data-lengths="<?= escapeHtml(json_encode(MIT_DISPATCH_LENGTHS)) ?>"
    data-business-hours="<?= escapeHtml(json_encode(mitDispatchBusinessHours())) ?>"
    data-contact-default="<?= intval($config_ticket_client_general_notifications ?? 0) === 1 ? 1 : 0 ?>"
    data-initial-date="<?= calendarIsDate($_GET['date'] ?? '') ? escapeHtml($_GET['date']) : date('Y-m-d') ?>">

    <div class="card mb-3">
        <div class="card-header bg-dark py-2 d-flex flex-wrap align-items-center gap-2">
            <h3 class="card-title mt-2 me-auto"><i class="fas fa-fw fa-people-arrows me-2"></i>Dispatch<?php if (!mitDispatchCanEdit()) { ?> <span class="badge text-bg-secondary ms-2 align-middle" data-md-view-only title="Your role has Dispatch View - ask for Dispatch Edit (Admin > Roles) to book and move tickets">View only</span><?php } ?> <span class="fw-normal opacity-75 ms-2 small" data-md-title></span></h3>
            <div class="btn-group btn-group-sm" role="group" aria-label="View">
                <button type="button" class="btn btn-light" data-md-view="day">Day</button>
                <button type="button" class="btn btn-light" data-md-view="week">Week</button>
                <button type="button" class="btn btn-light" data-md-view="workload">Workload</button>
            </div>
            <div class="btn-group btn-group-sm">
                <button type="button" class="btn btn-light" data-md-nav="-1" title="Back"><i class="fas fa-chevron-left"></i></button>
                <button type="button" class="btn btn-light" data-md-nav="0">Today</button>
                <button type="button" class="btn btn-light" data-md-nav="1" title="Forward"><i class="fas fa-chevron-right"></i></button>
            </div>
            <input type="date" class="form-control form-control-sm w-auto" data-md-date aria-label="Go to date">
            <?php if ($service_boards) { ?>
                <select class="form-select form-select-sm w-auto" data-md-service-board aria-label="Board" title="Which board's tickets the queue, Workload and This week show">
                    <option value="all" <?= $state['board'] === 'all' ? 'selected' : '' ?>>All boards</option>
                    <?php if (array_intersect_key(mitBoardsOfUser($session_user_id), mitBoardsVisible())) { ?><option value="mine" <?= $state['board'] === 'mine' ? 'selected' : '' ?>>My boards</option><?php } ?>
                    <?php foreach ($service_boards as $service_board) { ?>
                        <option value="<?= intval($service_board['id']) ?>" <?= $state['board'] === (string) $service_board['id'] ? 'selected' : '' ?>><?= escapeHtml($service_board['name']) ?></option>
                    <?php } ?>
                </select>
            <?php } ?>
            <div class="dropdown">
                <button class="btn btn-light btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside"><i class="fas fa-users me-2"></i>Agents <span data-md-tech-count></span></button>
                <div class="dropdown-menu dropdown-menu-end p-2" style="max-height: 60vh; overflow-y: auto; min-width: 220px">
                    <?php foreach ($techs as $tech) { ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" value="<?= intval($tech['id']) ?>" id="mdTech<?= intval($tech['id']) ?>" data-md-tech <?= in_array($tech['id'], $state['hidden'], true) ? '' : 'checked' ?>>
                            <label class="form-check-label" for="mdTech<?= intval($tech['id']) ?>"><span class="d-inline-block rounded-circle me-1" style="width: 10px; height: 10px; background: <?= escapeHtml($tech['color']) ?>"></span><?= escapeHtml($tech['name']) ?></label>
                        </div>
                    <?php } ?>
                    <hr class="my-2">
                    <?php if ($service_boards) { ?>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="mdBoardTechs" data-md-board-techs <?= $state['board_techs'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="mdBoardTechs">Only the board's members</label>
                        </div>
                    <?php } ?>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="mdWeekends" data-md-weekends <?= $state['weekends'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="mdWeekends">Show weekends (week view)</label>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-3">
            <div class="card mb-0 mit-dispatch-pane">
                <div class="card-header p-2">
                    <ul class="nav nav-pills nav-fill small flex-nowrap" role="tablist">
                        <li class="nav-item"><a class="nav-link py-1" href="#" data-md-tab="unassigned">No agent <span class="badge text-bg-danger ms-1" data-md-count="unassigned"></span></a></li>
                        <li class="nav-item"><a class="nav-link py-1" href="#" data-md-tab="unbooked">Not scheduled <span class="badge text-bg-warning ms-1" data-md-count="unbooked"></span></a></li>
                        <li class="nav-item"><a class="nav-link py-1" href="#" data-md-tab="left_behind" title="Open tickets booked on a day that has gone - drag one onto an agent and time to book it again">Left behind <span class="badge text-bg-danger ms-1" data-md-count="left_behind"></span></a></li>
                    </ul>
                    <div class="mt-2 d-flex flex-column gap-1">
                        <input type="search" class="form-control form-control-sm" placeholder="Search tickets" data-md-filter="q" aria-label="Search">
                        <div class="d-flex gap-1">
                            <select class="form-select form-select-sm" data-md-filter="client_id" aria-label="Client">
                                <option value="">All clients</option>
                                <?php foreach ($clients as $client) { ?>
                                    <option value="<?= intval($client['client_id']) ?>"><?= escapeHtml($client['client_name']) ?></option>
                                <?php } ?>
                            </select>
                            <select class="form-select form-select-sm w-auto" data-md-filter="priority" aria-label="Priority">
                                <option value="">Any priority</option>
                                <?php foreach (['Urgent', 'High', 'Medium', 'Low'] as $priority) { ?>
                                    <option><?= $priority ?></option>
                                <?php } ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="card-body p-2 mit-dispatch-queue" data-md-queue></div>
                <div class="card-footer small text-muted py-2">Drag a ticket onto an agent and time. Drag scheduled work back here to take it off the schedule.</div>
            </div>
        </div>
        <div class="col-lg-9">
            <div class="card mb-0 mit-dispatch-pane">
                <div class="card-body p-0" data-md-board></div>
            </div>
        </div>
    </div>

    <!-- This week: in vs closed, SLA results, per agent (renderStats in the script) -->
    <div class="card mt-3 mit-stats">
        <div class="card-header py-2 d-flex flex-wrap align-items-center gap-2">
            <h3 class="card-title mb-0 me-auto"><i class="fas fa-fw fa-chart-bar me-2"></i>This week <span class="fw-normal text-muted small ms-1" data-ms-week></span></h3>
            <span class="small text-muted">Agents follow the Agents list above</span>
        </div>
        <div class="card-body" data-md-stats>
            <div class="text-muted small">Loading…</div>
        </div>
    </div>
</div>

<?php require_once "../includes/footer.php"; ?>
<link href="/libs/fullcalendar/skeleton.css" rel="stylesheet">
<link href="/libs/fullcalendar/themes/classic/theme.css" rel="stylesheet">
<link href="/libs/fullcalendar/themes/classic/palette.css" rel="stylesheet">
<script src="/libs/fullcalendar/fullcalendar.global.js"></script>
<script src="/libs/fullcalendar/themes/classic/global.js"></script>
<script src="/libs/SortableJS/Sortable.min.js"></script>
<script src="<?= mitAsset('/agent/js/mit_dispatch.js') ?>"></script>
