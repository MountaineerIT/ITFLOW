<?php

/*
 * Mountaineer IT - Admin > Integrations > NinjaOne (functions/mit_ninjaone.php; handlers admin/post/mit_ninjaone.php).
 * Tabs: Settings (region, API keys, your NinjaOne address for the buttons, sync on / off, safety brake), Mapping (which
 * ITFlow client each NinjaOne organization goes to, and importing the n8n workflow's list), Sync runs (each run and its
 * report, Run now). ?tab=settings|mapping|runs, ?run= one run's report.
 */

require_once "includes/inc_all_admin.php";

if (!mitNinjaReady()) {
    echo '<div class="alert alert-warning">NinjaOne needs the database update - Admin > Update.</div>';
    require_once "../includes/footer.php";
    exit;
}

$settings = mitNinjaSettings();
$configured = mitNinjaConfigured();
$tab = in_array($_GET['tab'] ?? '', ['settings', 'mapping', 'runs'], true) ? $_GET['tab'] : ($configured ? 'mapping' : 'settings');
if (isset($_GET['run'])) {
    $tab = 'runs';
}
$csrf = $_SESSION['csrf_token'];
$outcomes = [
    'ok' => ['All good', 'text-bg-success'], 'warnings' => ['Done, see notes', 'text-bg-warning'], 'problems' => ['Needs attention', 'text-bg-danger'],
    'failed' => ['Failed', 'text-bg-danger'], 'running' => ['Running', 'text-bg-info'], 'busy' => ['Skipped', 'text-bg-secondary'],
];
$badge = function ($outcome) use ($outcomes) {
    [$label, $class] = $outcomes[$outcome] ?? [$outcome, 'text-bg-secondary'];
    return '<span class="badge ' . $class . '">' . escapeHtml($label) . '</span>';
};

?>

<div class="card">
    <div class="card-header bg-dark py-2 d-flex flex-wrap align-items-center gap-2">
        <h3 class="card-title mt-2 me-auto"><i class="fas fa-fw fa-satellite-dish me-2"></i>NinjaOne</h3>
        <?php if ($configured) { ?>
            <form action="post.php" method="post" class="d-inline">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <button type="submit" name="mit_ninja_run" class="btn btn-light btn-sm"><i class="fas fa-sync-alt me-2"></i>Run sync now</button>
            </form>
        <?php } ?>
    </div>
    <div class="card-body pb-0">
        <ul class="nav nav-tabs">
            <?php foreach (['settings' => 'Settings', 'mapping' => 'Mapping', 'runs' => 'Sync runs'] as $key => $label) { ?>
                <li class="nav-item"><a class="nav-link <?= $tab === $key ? 'active' : '' ?>" href="?tab=<?= $key ?>"><?= $label ?></a></li>
            <?php } ?>
        </ul>
    </div>
    <div class="card-body">

        <?php if ($tab === 'settings') { ?>
            <form action="post.php" method="post" autocomplete="off" class="row g-3" style="max-width: 52rem">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <div class="col-12"><h6 class="mb-0"><i class="fas fa-fw fa-key me-2"></i>API access</h6>
                    <div class="form-text">In NinjaOne: Administration > Apps > API > Client app IDs > Add - <strong>API Services (machine-to-machine)</strong>,
                        scope <strong>Monitoring</strong>, grant type <strong>Client credentials</strong>.</div></div>
                <div class="col-md-5">
                    <label class="form-label">Region</label>
                    <select class="form-select" name="ninja_instance">
                        <?php foreach (MIT_NINJA_INSTANCES as $key => [$label]) { ?>
                            <option value="<?= $key ?>" <?= $settings['instance'] === $key ? 'selected' : '' ?>><?= escapeHtml($label) ?></option>
                        <?php } ?>
                    </select>
                </div>
                <div class="col-md-7">
                    <label class="form-label">Client ID</label>
                    <input type="text" class="form-control" name="ninja_client_id" maxlength="200" value="<?= escapeHtml($settings['client_id']) ?>">
                </div>
                <div class="col-md-7 offset-md-5">
                    <label class="form-label">Client secret</label>
                    <input type="password" class="form-control" name="ninja_client_secret" maxlength="500" autocomplete="new-password" placeholder="<?= $settings['has_secret'] ? 'Saved - type a new one to replace it' : '' ?>">
                </div>

                <div class="col-12"><h6 class="mb-0 mt-2"><i class="fas fa-fw fa-external-link-alt me-2"></i>Your NinjaOne address</h6></div>
                <div class="col-md-7">
                    <input type="text" class="form-control" name="ninja_web_url" maxlength="200" value="<?= escapeHtml($settings['web_url_custom']) ?>" placeholder="https://<?= escapeHtml(MIT_NINJA_INSTANCES[$settings['instance']][1]) ?>">
                    <div class="form-text">Where the NinjaOne buttons on tickets and assets go - your own address (e.g. https://yourcompany.rmmservice.com). Empty: the region's address.</div>
                </div>

                <div class="col-12"><h6 class="mb-0 mt-2"><i class="fas fa-fw fa-sync-alt me-2"></i>Sync</h6></div>
                <div class="col-md-5">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" name="ninja_sync_on" value="1" id="mitNinjaSyncOn" <?= $settings['sync_on'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="mitNinjaSyncOn">Sync NinjaOne devices to assets every hour</label>
                    </div>
                    <div class="form-text">Turn the n8n workflow off when this is on - both would edit the same assets.</div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Safety brake</label>
                    <input type="number" min="1" max="5000" class="form-control" name="ninja_brake" value="<?= intval($settings['brake']) ?>">
                    <div class="form-text">Most new assets one run may make - more, and it writes nothing.</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Asset found under another client</label>
                    <select class="form-select" name="ninja_misplaced">
                        <option value="report" <?= $settings['misplaced'] === 'report' ? 'selected' : '' ?>>Report it, leave it (default)</option>
                        <option value="create" <?= $settings['misplaced'] === 'create' ? 'selected' : '' ?>>Make a new asset in the mapped client</option>
                    </select>
                </div>
                <div class="col-12 d-flex gap-2">
                    <button type="submit" name="mit_ninja_save_settings" class="btn btn-primary"><i class="fas fa-check me-2"></i>Save</button>
                    <?php if ($configured) { ?>
                        <button type="submit" name="mit_ninja_test" class="btn btn-outline-secondary"><i class="fas fa-plug me-2"></i>Save and test the connection</button>
                    <?php } ?>
                </div>
            </form>
            <div class="alert alert-light border small mt-4 mb-0" style="max-width: 52rem">
                <strong>How devices find their asset:</strong> by the link the sync keeps (or the NinjaOne device ID / UID in the asset's notes, as the
                n8n workflow wrote them), then by serial in the mapped client. <strong>Never by name</strong> - when an asset only shares a device's name,
                both are kept and the run lists it. Only the fields the sync owns are changed (name, type when generic, make, model, OS, serial, IP / MAC,
                purchase and warranty dates, and the [NinjaOne Sync] lines in the notes); status, contact, location and the rest of the notes stay yours.
            </div>

        <?php } elseif ($tab === 'mapping') {
            $orgs = mitNinjaOrgs();
            $clients = mysqli_fetch_all(mysqli_query($mysqli, "SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL ORDER BY client_name"), MYSQLI_ASSOC);
            $status_badge = [
                'mapped' => '<span class="badge text-bg-success">Mapped</span>', 'ignored' => '<span class="badge text-bg-secondary">Not synced</span>',
                'unmapped' => '<span class="badge text-bg-warning">Not mapped</span>',
            ]; ?>
            <div class="d-flex flex-wrap align-items-start gap-3 mb-3">
                <p class="text-muted mb-0 me-auto" style="max-width: 46rem">Which ITFlow client each NinjaOne organization's devices go to. A new organization starts as
                    <strong>Not mapped</strong>, so nothing syncs for it until you choose.</p>
                <?php if ($configured) { ?>
                    <form action="post.php" method="post">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <button type="submit" name="mit_ninja_refresh_orgs" class="btn btn-outline-secondary btn-sm"><i class="fas fa-download me-2"></i>Read organizations from NinjaOne</button>
                    </form>
                <?php } ?>
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="collapse" data-bs-target="#mitNinjaImport"><i class="fas fa-file-import me-2"></i>Import from the n8n workflow</button>
            </div>
            <div class="collapse mb-3" id="mitNinjaImport">
                <form action="post.php" method="post" enctype="multipart/form-data" class="border rounded p-3 bg-body-tertiary" style="max-width: 46rem">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <label class="form-label">The n8n workflow's JSON file</label>
                    <input type="file" class="form-control" name="workflow" accept=".json,application/json" required>
                    <div class="form-text">Its ORG_TO_CLIENT and IGNORED_ORGS lists are taken over (replacing those organizations' choices here). Clients that do not exist here are skipped.</div>
                    <button type="submit" name="mit_ninja_import" class="btn btn-primary btn-sm mt-2"><i class="fas fa-file-import me-2"></i>Import</button>
                </form>
            </div>
            <?php if (!$orgs) { ?>
                <div class="text-center text-muted py-5"><i class="fas fa-satellite-dish fa-2x mb-2 d-block"></i>No organizations yet - <?= $configured ? 'read them from NinjaOne' : 'add the API keys under Settings' ?>, or import the n8n workflow.</div>
            <?php } else { ?>
                <form action="post.php" method="post">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead class="small text-muted"><tr><th>NinjaOne organization</th><th class="text-end">Devices</th><th>Goes to</th><th></th></tr></thead>
                            <tbody>
                                <?php foreach ($orgs as $org_id => $org) {
                                    $current = $org['status'] === 'ignored' ? 'ignore' : (string) intval($org['client_id']); ?>
                                    <tr>
                                        <td><?= $org['name'] !== '' ? escapeHtml($org['name']) : '<span class="text-muted">' . ($org['seen_at'] ? '' : 'Not seen in NinjaOne') . '</span>' ?> <span class="text-muted small">#<?= intval($org_id) ?></span></td>
                                        <td class="text-end"><?= intval($org['device_count']) ?></td>
                                        <td style="min-width: 18rem">
                                            <select class="form-select form-select-sm" name="mapping[<?= intval($org_id) ?>]">
                                                <option value="">Not mapped</option>
                                                <option value="ignore" <?= $current === 'ignore' ? 'selected' : '' ?>>Don't sync this organization</option>
                                                <?php foreach ($clients as $client) { ?>
                                                    <option value="<?= intval($client['client_id']) ?>" <?= $current === (string) intval($client['client_id']) ? 'selected' : '' ?>><?= escapeHtml($client['client_name']) ?></option>
                                                <?php } ?>
                                                <?php if ($org['status'] === 'mapped' && $org['client_archived_at'] !== null) { ?>
                                                    <option value="<?= intval($org['client_id']) ?>" selected><?= escapeHtml($org['client_name']) ?> (archived)</option>
                                                <?php } ?>
                                            </select>
                                        </td>
                                        <td><?= $status_badge[$org['status']] ?? '' ?><?= $org['status'] === 'mapped' && $org['client_name'] === null ? ' <span class="badge text-bg-danger">Client gone</span>' : '' ?></td>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                    <button type="submit" name="mit_ninja_save_mapping" class="btn btn-primary mt-3"><i class="fas fa-check me-2"></i>Save mapping</button>
                </form>
            <?php } ?>

        <?php } else {
            $run = isset($_GET['run']) ? mitNinjaRunReport($_GET['run']) : null;
            if ($run) {
                $r = $run['report'];
                $t = $r['totals'] ?? []; ?>
                <a href="?tab=runs" class="small"><i class="fas fa-arrow-left me-1"></i>All runs</a>
                <h5 class="mt-2"><?= escapeHtml(date('M j, Y g:i A', strtotime($run['started_at']))) ?> <?= $badge($run['outcome']) ?></h5>
                <?php if ($t) { ?>
                    <p class="text-muted"><?= intval($t['devices'] ?? 0) ?> NinjaOne devices · <?= intval($t['created'] ?? 0) ?> new assets · <?= intval($t['updated'] ?? 0) ?> updated ·
                        <?= intval($t['unchanged'] ?? 0) ?> unchanged · <?= intval($t['ignored'] ?? 0) ?> in organizations not synced · <?= intval($t['unmapped'] ?? 0) ?> in organizations not mapped</p>
                <?php } ?>
                <?php foreach ((array) ($r['errors'] ?? []) as $line) { ?><div class="alert alert-danger py-2 mb-2"><i class="fas fa-exclamation-triangle me-2"></i><?= escapeHtml($line) ?></div><?php } ?>
                <?php foreach ((array) ($r['warnings'] ?? []) as $line) { ?><div class="alert alert-warning py-2 mb-2"><i class="fas fa-info-circle me-2"></i><?= escapeHtml($line) ?></div><?php } ?>
                <?php foreach ((array) ($r['notes'] ?? []) as $line) { ?>
                    <div class="alert alert-light border py-2 mb-2" data-mit-ninja-note><i class="fas fa-info-circle text-secondary me-2"></i><?= escapeHtml($line) ?>
                        <?php if (!empty($r['unmapped'])) { ?>
                            <details class="small mt-1"><summary class="text-secondary">Which organizations</summary>
                                <?= escapeHtml(implode('; ', array_map(function ($u) { return $u['org'] . ' - ' . intval($u['devices']) . ' device(s)'; }, (array) $r['unmapped']))) ?></details>
                        <?php } ?>
                    </div>
                <?php } ?>
                <?php
                $sections = [
                    'same_name' => ['Same name, kept apart', function ($row) {
                        return escapeHtml($row['name']) . ' in ' . escapeHtml($row['client']) . ' - a new asset was made beside: '
                            . implode(', ', array_map(function ($a) { return '<a href="/agent/asset.php?asset_id=' . intval($a['asset_id']) . '">' . escapeHtml($a['name']) . ' #' . intval($a['asset_id']) . '</a>'; }, $row['existing']));
                    }],
                    'misplaced' => ['Under another client', function ($row) {
                        return escapeHtml($row['name']) . ' should be in ' . escapeHtml($row['expected']) . ' - found in ' . escapeHtml(implode(', ', array_column($row['found'], 'client'))) . ' - ' . escapeHtml($row['action']);
                    }],
                    'deleted' => ['Deleted in ITFlow', function ($row) {
                        return escapeHtml($row['name']) . ' in ' . escapeHtml($row['client']) . ' - its asset was deleted, so it was not made again (add an asset with serial ' . escapeHtml($row['serial'] ?: '-') . ' to sync it again)';
                    }],
                    'archived' => ['Archived in ITFlow', function ($row) {
                        return escapeHtml($row['name']) . ' in ' . escapeHtml($row['client']) . ' - still in NinjaOne; unarchive the asset if it is in use';
                    }],
                    'duplicates' => ['More than one asset', function ($row) {
                        return escapeHtml($row['name']) . ' - kept #' . intval($row['keep']['asset_id']) . ', also ' . implode(', ', array_map(function ($a) { return '#' . intval($a['asset_id']); }, $row['extra']));
                    }],
                    'serial_conflicts' => ['Same serial in another client', function ($row) {
                        return escapeHtml($row['name']) . ' (' . escapeHtml($row['serial']) . ') - also in ' . escapeHtml(implode(', ', array_column($row['elsewhere'], 'client')));
                    }],
                    'orphans' => ['No longer in NinjaOne', function ($row) {
                        return '<a href="/agent/asset.php?asset_id=' . intval($row['asset_id']) . '">' . escapeHtml($row['name']) . '</a> in ' . escapeHtml($row['client']) . ' (device ' . intval($row['device_id']) . ') - nothing was deleted';
                    }],
                    'failed' => ['Could not be written', function ($row) {
                        return escapeHtml($row['name']) . ' - ' . escapeHtml($row['error']);
                    }],
                ];
                foreach ($sections as $key => [$title, $line]) {
                    if (empty($r[$key])) {
                        continue;
                    } ?>
                    <h6 class="mt-4"><?= $title ?> <span class="badge text-bg-light border"><?= count($r[$key]) ?></span></h6>
                    <ul class="small mb-0"><?php foreach ($r[$key] as $row) { ?><li><?= $line($row) ?></li><?php } ?></ul>
                <?php }
            } else {
                $runs = mitNinjaRuns(); ?>
                <?php if (!$runs) { ?>
                    <div class="text-center text-muted py-5">No sync has run yet.</div>
                <?php } else { ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead class="small text-muted"><tr><th>Started</th><th>By</th><th>Result</th><th class="text-end">New</th><th class="text-end">Updated</th><th class="text-end">Unchanged</th><th class="text-end">Notes</th><th></th></tr></thead>
                            <tbody>
                                <?php foreach ($runs as $row) { ?>
                                    <tr>
                                        <td class="text-nowrap"><?= escapeHtml(date('M j, g:i A', strtotime($row['started_at']))) ?></td>
                                        <td><?= $row['trigger_kind'] === 'manual' ? escapeHtml($row['user_name'] ?: 'Someone') : 'Schedule' ?></td>
                                        <td><?= $badge($row['outcome']) ?></td>
                                        <td class="text-end"><?= intval($row['created']) ?></td>
                                        <td class="text-end"><?= intval($row['updated']) ?></td>
                                        <td class="text-end"><?= intval($row['unchanged']) ?></td>
                                        <td class="text-end"><?= intval($row['problems']) ?></td>
                                        <td class="text-end"><a href="?run=<?= intval($row['run_id']) ?>">Report</a></td>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                <?php } ?>
            <?php } ?>
        <?php } ?>
    </div>
</div>

<?php require_once "../includes/footer.php";
