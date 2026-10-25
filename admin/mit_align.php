<?php

/*
 * Mountaineer IT - Admin > Integrations > MSP Align (functions/mit_align.php; handlers admin/post/mit_align.php). Read only:
 * the address and API key, Test connection (shows the key's scopes), on / off, Sync now, and what the last copy matched.
 */

require_once "includes/inc_all_admin.php";

if (!mitAlignReady()) {
    echo '<div class="alert alert-warning">MSP Align needs the database update - Admin > Update.</div>';
    require_once "../includes/footer.php";
    exit;
}

$settings = mitAlignSettings();
$csrf = $_SESSION['csrf_token'];
$counts = mysqli_fetch_row(mysqli_query($mysqli, "SELECT (SELECT COUNT(*) FROM mit_align_clients), (SELECT COUNT(*) FROM mit_align_devices),
    (SELECT COUNT(*) FROM mit_align_licenses)"));
$scopes_wanted = ['clients:read' => 'Clients', 'devices:read' => 'Devices', 'licenses:read' => 'Licenses', 'health:read' => 'Health score',
    'alignment:read' => 'Alignment', 'meetings:read' => 'Meetings'];
$writes = array_values(array_filter($settings['scopes'], fn($scope) => str_ends_with((string) $scope, ':write')));

?>

<div class="card">
    <div class="card-header bg-dark py-2 d-flex flex-wrap align-items-center gap-2">
        <h3 class="card-title mt-2 me-auto"><i class="fas fa-fw fa-compass me-2"></i>MSP Align</h3>
        <?php if ($settings['url'] !== '' && $settings['has_key']) { ?>
            <form action="post.php" method="post">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <button type="submit" name="mit_align_sync" class="btn btn-light btn-sm"><i class="fas fa-sync-alt me-2"></i>Sync now</button>
            </form>
        <?php } ?>
    </div>
    <div class="card-body">
        <p class="text-muted small" style="max-width: 52rem">Read only: every hour ITFlow copies what MSP Align knows about the clients, assets and licenses it brought
            in from ITFlow - health and alignment scores, the next meeting, each device's lifecycle status and each license's renewal - and shows it on the
            client overview, the asset pages and the license list, with links to the matching MSP Align pages. Records are matched by the ITFlow ids MSP Align
            already keeps, so there is nothing to map. ITFlow never changes anything in MSP Align.</p>
        <?php if ($settings['last_run']) { ?>
            <div class="small mb-3 <?= strpos($settings['last_result'], 'Failed') === 0 ? 'text-danger' : 'text-muted' ?>" data-mit-align-last>
                Last sync <?= escapeHtml($settings['last_run']) ?>: <?= escapeHtml($settings['last_result']) ?>.
                Now linked: <?= intval($counts[0]) ?> client<?= intval($counts[0]) === 1 ? '' : 's' ?>, <?= intval($counts[1]) ?> asset<?= intval($counts[1]) === 1 ? '' : 's' ?>,
                <?= intval($counts[2]) ?> license<?= intval($counts[2]) === 1 ? '' : 's' ?>.</div>
        <?php } ?>
        <?php if (!empty($settings['report']['mismatched'])) { ?>
            <div class="alert alert-warning small" data-mit-align-mismatch>
                <strong>Names differ</strong> for <?= count($settings['report']['mismatched']) ?> linked client<?= count($settings['report']['mismatched']) === 1 ? '' : 's' ?>
                - fine after a rename, but if MSP Align was filled from a different ITFlow these links point at the wrong clients:
                <?= escapeHtml(implode('; ', array_map(fn($m) => $m['itflow'] . ' / ' . $m['align'], array_slice($settings['report']['mismatched'], 0, 8)))) ?>
            </div>
        <?php } ?>

        <form action="post.php" method="post" class="row g-3" style="max-width: 52rem" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <div class="col-md-6">
                <label class="form-label">MSP Align address</label>
                <input type="url" class="form-control" name="align_url" maxlength="300" value="<?= escapeHtml($settings['url']) ?>" placeholder="https://align.example.com" required>
                <div class="form-text">The address you open MSP Align at.</div>
            </div>
            <div class="col-md-6">
                <label class="form-label">API key</label>
                <input type="password" class="form-control" name="align_key" maxlength="300" placeholder="<?= $settings['has_key'] ? 'Saved - leave blank to keep it' : 'msa_...' ?>" autocomplete="new-password">
                <div class="form-text">In MSP Align: Settings > API > Turn on, then a new key with the <em>read</em> scopes below only.</div>
            </div>
            <div class="col-12">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" name="align_enabled" value="1" id="alignEnabled" <?= $settings['enabled'] ? 'checked' : '' ?>>
                    <label class="form-check-label" for="alignEnabled">Copy from MSP Align every hour and show it in ITFlow</label>
                </div>
            </div>
            <div class="col-12">
                <button type="submit" name="mit_align_save" class="btn btn-primary"><i class="fas fa-plug me-2"></i>Save and test</button>
            </div>
        </form>

        <?php if ($settings['has_key'] && $settings['key_name'] !== '') { ?>
            <hr>
            <h6>The key <span class="text-muted fw-normal">"<?= escapeHtml($settings['key_name']) ?>"</span></h6>
            <div class="d-flex flex-wrap gap-2 mb-2" data-mit-align-scopes>
                <?php foreach ($scopes_wanted as $scope => $label) {
                    $has = in_array($scope, $settings['scopes'], true); ?>
                    <span class="badge rounded-pill <?= $has ? 'text-bg-success' : 'text-bg-light border' ?> fw-normal">
                        <i class="fas fa-<?= $has ? 'check' : 'minus' ?> me-1"></i><?= $label ?></span>
                <?php } ?>
            </div>
            <div class="small text-muted">Without one of these, that part is simply left out.</div>
            <?php if ($writes) { ?>
                <div class="small text-warning-emphasis mt-1"><i class="fas fa-exclamation-triangle me-1"></i>This key can also change things in MSP Align
                    (<?= escapeHtml(implode(', ', $writes)) ?>). ITFlow only reads, but a read-only key is safer.</div>
            <?php } ?>
        <?php } ?>
    </div>
</div>

<?php
require_once "../includes/footer.php";
