<?php

/*
 * Mountaineer IT - a timesheet (functions/mit_timesheets.php): one person's pay period, a week at a time,
 * a row a day. Clicking a day opens it on the side - start, meal and end times (California non-exempt,
 * functions/mit_ts_california.php), ticket time, and hours by charge code with notes - and it saves as
 * it is typed (agent/mit_timesheet_ajax.php). The rows are drawn by agent/js/mit_timesheet.js from the
 * data at the bottom (functions/mit_timesheet_view.php). ?user_id= (Timesheets View, or their approver,
 * to see someone else's) and ?start= (any date in the period).
 */

require_once "includes/inc_all.php";

mitModuleRequire('timesheets');

if (!mitTsReady()) {
    echo '<div class="alert alert-warning">Timesheets need the database update - Admin > Update.</div>';
    require_once "../includes/footer.php";
    exit;
}

$settings = mitTsSettings();
$user_id = intval($_GET['user_id'] ?? $session_user_id);
if (!mitTsMaySee($user_id)) {
    $user_id = intval($session_user_id);
}
$period = mitTsPeriodFromRequest($_GET['start'] ?? '', $user_id);
// The person picker: who this agent may look at (with anyone who has hours in this period), and themselves
$people = mitTsVisibleUsers($period) + array_intersect_key(mitTsUsers([$session_user_id, $user_id]), array_flip([intval($session_user_id), $user_id]));
uasort($people, function ($a, $b) {
    return strcasecmp($a['user_name'], $b['user_name']);
});
$person = $people[$user_id] ?? null;
if (!$person) {
    echo '<div class="alert alert-warning">That person does not keep a timesheet.</div>';
    require_once "../includes/footer.php";
    exit;
}
$own = $user_id === intval($session_user_id);
// Approving your own timesheet takes Full access; someone else's, Full access or being their approver
$may_approve = $own ? mitTsLevel() >= 3 : mitTsMayApprove($user_id);
$sheet = mitTsSheet($user_id, $period);
$locked = mitTsWhyLocked($sheet);
$today = mitTsToday($user_id);
$ca = mitTsCaOn($user_id, $period['end']);
$approval = $settings['approval'];
$attest = $ca ? mitTsCaAttestation($sheet['sheet_id']) : null;
$open = in_array($sheet['status'], ['open', 'returned'], true);
// Submitting: on California rules only the person signs and submits; otherwise they, or someone who corrects their hours
$can_submit = $approval && !$locked && $open && ($ca ? $own : ($own || mitTsMayCorrect($user_id)));
$can_sign = $ca && $own && !$locked && $open && !$approval && !$attest;
$csrf = $_SESSION['csrf_token'];
$prev = mitTsPeriodShift($period, -1);
$next = mitTsPeriodShift($period, 1);
$url = function (array $p, $uid = null) use ($user_id) {
    return 'mit_timesheet.php?user_id=' . intval($uid ?? $user_id) . '&start=' . $p['start'];
};

// The week to open on: this week when the period is the current one
$weeks = mitTsWeeks($period);
$open_week = 0;
foreach ($weeks as $i => $week) {
    if ($week[0] <= $today && end($week) >= $today) {
        $open_week = $i;
    }
}
$data = [
    'user_id' => $user_id, 'start' => $period['start'], 'csrf' => $csrf, 'own' => $own, 'locked' => $locked, 'ca' => $ca,
    'format' => mitTsFormat(), 'today' => $today, 'future' => $settings['future'], 'ticket_time' => (bool) $settings['ticket_time'], 'lost_paid' => (bool) $settings['auto_lost_paid'],
    'weeks' => $weeks, 'open_week' => $open_week, 'days' => mitTsViewDays($user_id, $period), 'codes' => mitTsViewCodes($user_id, $period),
    'answers' => MIT_TS_CA_ANSWERS, 'problems' => $ca ? mitTsCaProblems($user_id, $period) : [],
];
$status_text = [
    'open' => $approval ? 'Not submitted yet' : 'Saved as you go',
    'returned' => 'Sent back - see below',
    'submitted' => 'Submitted ' . ($sheet['submitted_at'] ? date('M j, g:i A', strtotime($sheet['submitted_at'])) : ''),
    'approved' => 'Approved by ' . ($sheet['decided_by_name'] ?: '-') . ($sheet['decided_at'] ? ' on ' . date('M j', strtotime($sheet['decided_at'])) : '') . ($sheet['decision_note'] ? ' - ' . $sheet['decision_note'] : ''),
][$sheet['status']] ?? '';

?>

<div class="mit-ts" id="mitTs">

    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <div class="me-auto">
            <h1 class="h4 mb-1"><?= $own ? 'My timesheet' : escapeHtml($person['user_name']) . "'s timesheet" ?></h1>
            <div class="small text-body-secondary d-flex flex-wrap align-items-center gap-2">
                <?php if ($approval || $sheet['status'] === 'approved') { ?><?= mitTsBadge($sheet['status']) ?><?php } ?>
                <span><?= escapeHtml($status_text) ?></span>
                <?php if ($ca) { ?><span class="badge text-bg-light border fw-normal" title="Start, meal and end times are recorded; California overtime and premium pay">California non-exempt</span><?php } ?>
                <?php if ($zone = mitTsOwnZone($user_id)) { ?><span class="badge text-bg-light border fw-normal" id="mitTsZone" title="Days, today and the day ticket time falls on follow this time zone (the company's is <?= escapeHtml(mitTsZoneLabel(date_default_timezone_get())) ?>)"><i class="fas fa-globe-americas me-1"></i><?= escapeHtml(mitTsZoneLabel($zone)) ?></span><?php } ?>
            </div>
        </div>
        <?php if (count($people) > 1) { ?>
            <select class="form-select w-auto" aria-label="Person" onchange="location.href = 'mit_timesheet.php?start=<?= $period['start'] ?>&user_id=' + this.value">
                <?php foreach ($people as $uid => $p) { ?>
                    <option value="<?= intval($uid) ?>" <?= $uid === $user_id ? 'selected' : '' ?>><?= escapeHtml($p['user_name']) ?></option>
                <?php } ?>
            </select>
        <?php } ?>
        <div class="btn-group" role="group" aria-label="Pay period">
            <a class="btn btn-outline-secondary" href="<?= $url($prev) ?>" title="Previous period"><i class="fas fa-chevron-left"></i></a>
            <a class="btn btn-outline-secondary fw-semibold" href="<?= $url(mitTsPeriodFor($today)) ?>" title="Go to this period"><?= escapeHtml($period['label']) ?></a>
            <a class="btn btn-outline-secondary" href="<?= $url($next) ?>" title="Next period"><i class="fas fa-chevron-right"></i></a>
        </div>
        <?php if (mitTsLevel() >= 1 || mitTsMyReports()) { ?>
            <a class="btn btn-outline-secondary" href="mit_timesheets.php?start=<?= $period['start'] ?>" title="Everyone's timesheets"><i class="fas fa-users"></i><span class="d-none d-lg-inline ms-2">Everyone</span></a>
        <?php } ?>
    </div>

    <?php if ($sheet['status'] === 'returned') { ?>
        <div class="alert alert-danger py-2"><i class="fas fa-undo me-2"></i>Sent back by <?= escapeHtml($sheet['decided_by_name'] ?: '-') ?>: <?= escapeHtml($sheet['decision_note']) ?></div>
    <?php } ?>
    <?php if ($locked) { ?>
        <div class="alert alert-light border py-2 small"><i class="fas fa-lock me-2"></i><?= escapeHtml($locked) ?></div>
    <?php } ?>

    <div class="row g-3">
        <div class="col-xl-8">
            <div id="mitTsSummary"><?= mitTsViewSummary($user_id, $period) ?></div>

            <div class="card mit-ts-card">
                <div class="card-header bg-transparent">
                    <ul class="nav nav-pills mit-ts-weeks" id="mitTsWeeks"></ul>
                </div>
                <div class="list-group list-group-flush mit-ts-days" id="mitTsDays"></div>
                <div class="card-footer bg-transparent small text-body-secondary">
                    <?= $locked ? 'Click a day to see it.' : 'Click a day to fill it in - it saves as you type.' ?>
                    <?= $ca ? 'Your hours worked are your blocks of work, start to end - add another block after a meal, or for a split shift.' : '' ?>
                    <?= $settings['ticket_time'] ? 'Ticket time comes in on its own from the time logged on ticket replies.' : '' ?>
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <div id="mitTsChanges"><?= mitTsCaChangesBox($user_id, $period, $own) ?></div>
            <div id="mitTsLeave"><?= mitLeaveTimesheetBox($user_id, $period) ?></div>
            <?= mitReimbTimesheetBox($user_id, $period) ?>
        </div>
    </div>

    <!-- Always in reach: whether it is saved, and what to do next -->
    <form action="post.php" method="post" autocomplete="off" id="mitTsForm" class="mit-ts-bar">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="user_id" value="<?= $user_id ?>">
        <input type="hidden" name="start" value="<?= $period['start'] ?>">
        <span class="small text-body-secondary me-auto" id="mitTsSaved"><?= $locked ? '<i class="fas fa-lock me-1"></i>Read only' : '<i class="fas fa-check-circle text-success me-1"></i>All changes saved' ?></span>
        <?php if ($attest) { ?>
            <span class="small text-success d-none d-md-inline"><i class="fas fa-file-signature me-1"></i>Signed by <?= escapeHtml($attest['signature_name']) ?>, <?= escapeHtml(date('M j, g:i A', strtotime($attest['attested_at']))) ?></span>
        <?php } ?>
        <?php if ($approval && $own && $sheet['status'] === 'submitted') { ?>
            <a class="btn btn-outline-secondary" href="post.php?mit_ts_withdraw=<?= intval($sheet['sheet_id']) ?>&csrf_token=<?= $csrf ?>"><i class="fas fa-undo me-2"></i>Take it back</a>
        <?php } ?>
        <?php if ($may_approve && $approval && $sheet['status'] === 'submitted') { ?>
            <a href="#" class="btn btn-outline-danger ajax-modal" data-modal-url="modals/mit_timesheet/decide.php?user_id=<?= $user_id ?>&start=<?= $period['start'] ?>&decision=return">Send back</a>
        <?php } ?>
        <?php if ($may_approve && $approval && $sheet['status'] !== 'approved') { ?>
            <a href="#" class="btn btn-success ajax-modal" data-modal-url="modals/mit_timesheet/decide.php?user_id=<?= $user_id ?>&start=<?= $period['start'] ?>&decision=approve"><i class="fas fa-check me-2"></i>Approve</a>
        <?php } ?>
        <?php if ($may_approve && $sheet['status'] === 'approved') { ?>
            <a class="btn btn-outline-secondary confirm-link" href="post.php?mit_ts_reopen=<?= intval($sheet['sheet_id']) ?>&csrf_token=<?= $csrf ?>"><i class="fas fa-lock-open me-2"></i>Reopen</a>
        <?php } ?>
        <?php if ($can_submit || $can_sign) { ?>
            <button type="submit" name="mit_ts_save" value="submit" class="btn btn-primary" data-mit-ts-submit>
                <i class="fas <?= $ca ? 'fa-file-signature' : 'fa-paper-plane' ?> me-2"></i><?= $ca ? ($approval ? 'Sign and submit' : 'Sign my hours') : 'Submit for approval' ?>
            </button>
        <?php } ?>
        <?= ($can_submit || $can_sign) && $ca ? mitTsCaSignModal($user_id, $period) : '' ?>
    </form>

    <!-- One day, on the side -->
    <div class="offcanvas offcanvas-end mit-ts-panel" tabindex="-1" id="mitTsDay" aria-labelledby="mitTsDayTitle">
        <div class="offcanvas-header border-bottom">
            <div class="me-auto">
                <h5 class="offcanvas-title mb-0" id="mitTsDayTitle"></h5>
                <div class="small text-body-secondary" data-mit-day-sub></div>
            </div>
            <div class="btn-group btn-group-sm me-2">
                <button type="button" class="btn btn-outline-secondary" data-mit-day-step="-1" title="Previous day"><i class="fas fa-chevron-up"></i></button>
                <button type="button" class="btn btn-outline-secondary" data-mit-day-step="1" title="Next day"><i class="fas fa-chevron-down"></i></button>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
        </div>
        <div class="offcanvas-body" data-mit-day-body></div>
        <div class="border-top px-3 py-2 d-flex align-items-center gap-2">
            <span class="small text-body-secondary me-auto" data-mit-day-status></span>
            <span class="fw-semibold" data-mit-day-total></span>
        </div>
    </div>

</div>


<style>
    /* Mountaineer IT - the timesheet page (agent/js/mit_timesheet.js draws the rows) */
    .mit-ts { padding-bottom: 4.5rem; }
    .mit-ts-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: .75rem; margin-bottom: 1rem; }
    .mit-ts-stat { background: var(--bs-body-bg); border: 1px solid var(--bs-border-color); border-radius: .75rem; padding: .75rem 1rem; }
    .mit-ts-stat-label { font-size: .75rem; text-transform: uppercase; letter-spacing: .04em; color: var(--bs-secondary-color); }
    .mit-ts-stat-value { font-size: 1.5rem; font-weight: 600; line-height: 1.3; }
    .mit-ts-stat-sub { font-size: .8rem; color: var(--bs-secondary-color); }
    .mit-ts-card { border-radius: .75rem; overflow: hidden; }
    .mit-ts-weeks { gap: .25rem; flex-wrap: nowrap; overflow-x: auto; }
    .mit-ts-weeks .nav-link { display: flex; flex-direction: column; align-items: flex-start; padding: .4rem .9rem; line-height: 1.2; white-space: nowrap; }
    .mit-ts-week-total { font-size: .75rem; opacity: .75; }
    .mit-ts-day { display: flex; align-items: center; gap: 1rem; padding: .8rem 1rem; border-left: 3px solid transparent; }
    .mit-ts-day.is-today { border-left-color: var(--bs-primary); }
    .mit-ts-day.is-off { background: var(--bs-tertiary-bg); }
    .mit-ts-day.active-day { background: var(--bs-primary-bg-subtle); }
    .mit-ts-day-date { width: 4.5rem; flex: none; }
    .mit-ts-dow { font-size: .75rem; text-transform: uppercase; color: var(--bs-secondary-color); letter-spacing: .04em; }
    .mit-ts-dnum { font-weight: 600; }
    .mit-ts-day-main { flex: 1; min-width: 0; }
    .mit-ts-times { font-weight: 500; }
    .mit-ts-day-total { font-size: 1.1rem; font-weight: 600; min-width: 3rem; text-align: right; font-variant-numeric: tabular-nums; }
    .mit-ts-chips { display: flex; flex-wrap: wrap; gap: .3rem; }
    .mit-ts-chip { display: inline-flex; align-items: center; gap: .35rem; font-size: .78rem; padding: .1rem .55rem; border-radius: 1rem; background: var(--bs-secondary-bg); color: var(--bs-body-color); }
    .mit-ts-chip i { width: .5rem; height: .5rem; border-radius: 50%; display: inline-block; }
    .mit-ts-chip-danger { background: var(--bs-danger-bg-subtle); color: var(--bs-danger-text-emphasis); }
    .mit-ts-chip-warning { background: var(--bs-warning-bg-subtle); color: var(--bs-warning-text-emphasis); }
    .mit-ts-chip-muted { background: transparent; border: 1px dashed var(--bs-border-color); color: var(--bs-secondary-color); }
    .mit-ts-week-foot { display: flex; justify-content: space-between; padding: .6rem 1rem; color: var(--bs-secondary-color); font-size: .9rem; }
    .mit-ts-week-foot b { color: var(--bs-body-color); padding-right: 1.6rem; }
    .mit-ts-bar { position: fixed; bottom: 0; right: 0; left: var(--mit-ts-left, 0); z-index: 1030; display: flex; flex-wrap: wrap; align-items: center; gap: .5rem;
        padding: .6rem 1.25rem; background: var(--bs-body-bg); border-top: 1px solid var(--bs-border-color); box-shadow: 0 -2px 8px rgba(0, 0, 0, .05); }
    @media (min-width: 992px) { .sidebar-expand-lg:not(.sidebar-collapse) .mit-ts-bar { left: var(--lte-sidebar-width, 250px); } }
    .mit-ts-panel { --bs-offcanvas-width: 480px; }
    .mit-ts-section { font-size: .75rem; text-transform: uppercase; letter-spacing: .05em; color: var(--bs-secondary-color); margin-bottom: .75rem; }
    .mit-ts-code { padding: .6rem 0; border-bottom: 1px solid var(--bs-border-color-translucent); }
    .mit-ts-code-auto .mit-ts-auto-hours { min-width: 4.5rem; text-align: end; padding-right: .75rem; }
    .mit-ts-hours { width: 5.5rem; }
    .mit-ts-dot { width: .6rem; height: .6rem; border-radius: 50%; flex: none; }
    .mit-ts-auto { display: flex; flex-wrap: wrap; align-items: center; gap: .4rem; padding: .5rem .75rem; margin-bottom: .5rem; border-radius: .5rem; background: var(--bs-tertiary-bg); text-decoration: none; }
    .mit-ts-issue { padding: .5rem .75rem; border-radius: .5rem; background: var(--bs-warning-bg-subtle); color: var(--bs-warning-text-emphasis); }
</style>
<script type="application/json" id="mitTsData"><?= json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="<?= mitAsset('/agent/js/mit_timesheet.js') ?>" defer></script><?php // after the footer's Bootstrap ?>

<?php
require_once "../includes/footer.php";
