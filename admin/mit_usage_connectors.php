<?php

/*
 * Mountaineer IT - Admin > Integrations > Usage connectors (functions/mit_usage_connectors.php; handlers
 * admin/post/mit_usage_connectors.php). Tabs: NinjaOne (on / off), Bitwarden (each client's organization API key), Ingram Micro
 * (the API login and which Ingram customer each client is). Counts go to usage billing daily (cron job mit_usage) or Run now.
 */

require_once "includes/inc_all_admin.php";

if (!mitUsageReady() || !mitUsageLinksReady()) {
    echo '<div class="alert alert-warning">Usage connectors need the database update - Admin > Update.</div>';
    require_once "../includes/footer.php";
    exit;
}

$tabs = MIT_USAGE_CONNECTORS;
$tab = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'ninjaone';
$settings = mitUsageConnectorSettings();
$csrf = $_SESSION['csrf_token'];
$last = (json_decode((string) mitSetting('usage_connectors_last', '{}'), true) ?: [])[$tab] ?? null;
$clients = mysqli_fetch_all(mysqli_query($mysqli, "SELECT client_id, client_name FROM clients WHERE client_archived_at IS NULL ORDER BY client_name"), MYSQLI_ASSOC);
$status_badge = function ($link) {
    if (!$link['last_status']) {
        return '<span class="badge text-bg-light border">Not run yet</span>';
    }
    return '<span class="badge ' . ($link['last_status'] === 'ok' ? 'text-bg-success' : 'text-bg-danger') . '">' . ($link['last_status'] === 'ok' ? 'OK' : 'Failed') . '</span>'
        . '<div class="small text-muted">' . escapeHtml((string) $link['last_message']) . ' · ' . escapeHtml((string) $link['last_run_at']) . '</div>';
};

?>

<div class="card">
    <div class="card-header bg-dark py-2 d-flex flex-wrap align-items-center gap-2">
        <h3 class="card-title mt-2 me-auto"><i class="fas fa-fw fa-plug me-2"></i>Usage connectors</h3>
        <form action="post.php" method="post">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="connector" value="<?= $tab ?>">
            <button type="submit" name="mit_usage_connector_run" class="btn btn-light btn-sm"><i class="fas fa-sync-alt me-2"></i>Run <?= escapeHtml($tabs[$tab]) ?> now</button>
        </form>
    </div>
    <div class="card-body pb-0">
        <p class="text-muted small mb-2">Counts other systems hold, recorded daily as usage counts. On a recurring invoice's Usage billing card, set a line to
            "Reported through the API" with the usage name below - the snapshot day's count is billed.</p>
        <ul class="nav nav-tabs">
            <?php foreach ($tabs as $key => $label) { ?>
                <li class="nav-item"><a class="nav-link <?= $tab === $key ? 'active' : '' ?>" href="?tab=<?= $key ?>"><?= escapeHtml($label) ?></a></li>
            <?php } ?>
        </ul>
    </div>
    <div class="card-body">
        <?php if ($last) { ?>
            <div class="small mb-3 <?= $last['problems'] ? 'text-danger' : 'text-muted' ?>" data-mit-connector-last>Last run <?= escapeHtml($last['at']) ?>: <?= intval($last['counted']) ?> counted
                <?= $last['problems'] ? ' - ' . escapeHtml(implode(' · ', $last['problems'])) : '' ?></div>
        <?php } ?>

        <?php if ($tab === 'ninjaone') { ?>
            <form action="post.php" method="post" style="max-width: 46rem">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" role="switch" name="usage_ninjaone_on" value="1" id="mitUsageNinjaOn" <?= $settings['ninjaone_on'] ? 'checked' : '' ?>>
                    <label class="form-check-label" for="mitUsageNinjaOn">Count NinjaOne devices every day</label>
                </div>
                <p class="small text-muted">From the devices the NinjaOne sync saw in the last 7 days, by the client each NinjaOne organization is mapped to
                    (Admin &gt; Integrations &gt; NinjaOne &gt; Mapping), and only while the sync has run in the last 2 days. Usage names:
                    <code>ninjaone:workstations</code> (Windows, Mac, Linux workstations), <code>ninjaone:servers</code> (Windows, Linux, Mac servers and
                    VMware / Hyper-V hosts), <code>ninjaone:devices</code> (workstations + servers) and <code>ninjaone:all</code> (everything NinjaOne lists,
                    network devices, VMs and phones included).</p>
                <button type="submit" name="mit_usage_ninjaone_save" class="btn btn-primary btn-sm"><i class="fas fa-check me-2"></i>Save</button>
            </form>
            <?php $counts = mitUsageNinjaCounts();
            if ($counts) {
                $names = array_column($clients, 'client_name', 'client_id'); ?>
                <table class="table table-sm mt-3" style="max-width: 46rem">
                    <thead><tr><th>Client</th><th class="text-end">Workstations</th><th class="text-end">Servers</th><th class="text-end">Devices</th><th class="text-end">All</th></tr></thead>
                    <tbody>
                    <?php foreach ($counts as $client_id => $c) { ?>
                        <tr><td><?= escapeHtml($names[$client_id] ?? "Client $client_id") ?></td><td class="text-end"><?= $c['workstations'] ?></td><td class="text-end"><?= $c['servers'] ?></td><td class="text-end"><?= $c['devices'] ?></td><td class="text-end"><?= $c['all'] ?></td></tr>
                    <?php } ?>
                    </tbody>
                </table>
            <?php } ?>

        <?php } elseif ($tab === 'bitwarden') {
            $links = mitUsageLinks('bitwarden'); ?>
            <p class="small text-muted" style="max-width: 52rem">Bitwarden's Provider portal has no API, so each client organization is read with its own API key: in the
                Provider portal open the client organization, then <strong>Settings &gt; Organization info &gt; API key</strong> (needs the organization's owner rights).
                Usage names: <code>bitwarden:users</code> (members not revoked - what Bitwarden bills) and <code>bitwarden:confirmed</code>.</p>
            <form action="post.php" method="post" class="mb-3" style="max-width: 52rem">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <div class="row g-2 align-items-end">
                    <div class="col-md-3"><label class="form-label small mb-1">Region</label>
                        <select class="form-select form-select-sm" name="usage_bitwarden_region">
                            <option value="us" <?= $settings['bitwarden_region'] === 'us' ? 'selected' : '' ?>>bitwarden.com (US)</option>
                            <option value="eu" <?= $settings['bitwarden_region'] === 'eu' ? 'selected' : '' ?>>bitwarden.eu (EU)</option>
                        </select></div>
                    <div class="col-md-2"><button type="submit" name="mit_usage_bitwarden_region" class="btn btn-outline-secondary btn-sm">Save region</button></div>
                </div>
            </form>
            <form action="post.php" method="post" autocomplete="off" class="border rounded p-3 bg-body-tertiary mb-3" style="max-width: 52rem" data-mit-bitwarden-add>
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <h6>Link a client organization</h6>
                <div class="row g-2">
                    <div class="col-md-4"><label class="form-label small mb-1">ITFlow client</label>
                        <select class="form-select form-select-sm" name="client_id" required>
                            <option value="">-</option>
                            <?php foreach ($clients as $client) { ?><option value="<?= intval($client['client_id']) ?>"><?= escapeHtml($client['client_name']) ?></option><?php } ?>
                        </select></div>
                    <div class="col-md-4"><label class="form-label small mb-1">Organization name (for you)</label>
                        <input type="text" class="form-control form-control-sm" name="external_name" maxlength="255"></div>
                    <div class="col-md-6"><label class="form-label small mb-1">client_id</label>
                        <input type="text" class="form-control form-control-sm" name="api_client_id" placeholder="organization.xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" required></div>
                    <div class="col-md-6"><label class="form-label small mb-1">client_secret</label>
                        <input type="password" class="form-control form-control-sm" name="api_secret" autocomplete="new-password" required></div>
                </div>
                <button type="submit" name="mit_usage_bitwarden_link" class="btn btn-primary btn-sm mt-2"><i class="fas fa-link me-1"></i>Test and link</button>
            </form>
            <div class="table-responsive">
                <table class="table table-sm align-middle" data-mit-bitwarden-links>
                    <thead><tr><th>Client</th><th>Organization</th><th>Last run</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($links as $link) { ?>
                        <tr>
                            <td><?= escapeHtml($link['client_name']) ?></td>
                            <td><?= escapeHtml($link['external_name'] ?: $link['external_id']) ?><div class="small text-muted"><?= escapeHtml($link['external_id']) ?></div></td>
                            <td><?= $status_badge($link) ?></td>
                            <td class="text-end"><a class="btn btn-outline-danger btn-sm confirm-link" href="post.php?mit_usage_unlink=<?= intval($link['link_id']) ?>&csrf_token=<?= $csrf ?>">Unlink</a></td>
                        </tr>
                    <?php } ?>
                    <?php if (!$links) { ?><tr><td colspan="4" class="text-muted">No organizations linked yet.</td></tr><?php } ?>
                    </tbody>
                </table>
            </div>

        <?php } else {
            $links = array_column(mitUsageLinks('ingram'), null, 'client_id');
            $customers = mitUsageIngramCustomers(); ?>
            <div class="row g-4">
                <div class="col-lg-5">
                    <form action="post.php" method="post" autocomplete="off" class="row g-2">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <div class="col-12"><h6 class="mb-0">Cloud Marketplace API (Xvantage)</h6>
                            <div class="form-text">The API user, its password and the subscription key Ingram Micro gave you for the Cloud Marketplace API.</div></div>
                        <div class="col-md-4"><label class="form-label small mb-1">Marketplace</label>
                            <input type="text" class="form-control form-control-sm" name="usage_ingram_marketplace" maxlength="10" value="<?= escapeHtml($settings['ingram_marketplace']) ?>" placeholder="us"></div>
                        <div class="col-md-8"><label class="form-label small mb-1">API user (login)</label>
                            <input type="text" class="form-control form-control-sm" name="usage_ingram_login" maxlength="200" value="<?= escapeHtml($settings['ingram_login']) ?>"></div>
                        <div class="col-md-6"><label class="form-label small mb-1">Password</label>
                            <input type="password" class="form-control form-control-sm" name="usage_ingram_password" autocomplete="new-password" placeholder="<?= $settings['ingram_has_password'] ? 'Saved' : '' ?>"></div>
                        <div class="col-md-6"><label class="form-label small mb-1">Subscription key</label>
                            <input type="password" class="form-control form-control-sm" name="usage_ingram_key" autocomplete="new-password" placeholder="<?= $settings['ingram_has_key'] ? 'Saved' : '' ?>"></div>
                        <div class="col-12"><label class="form-label small mb-1">API address (only if Ingram gave you another)</label>
                            <input type="text" class="form-control form-control-sm" name="usage_ingram_base" maxlength="200" value="<?= escapeHtml($settings['ingram_base_custom']) ?>" placeholder="https://api.cloud.im/marketplaces/<?= escapeHtml($settings['ingram_marketplace'] ?: 'us') ?>"></div>
                        <div class="col-12 d-flex gap-2">
                            <button type="submit" name="mit_usage_ingram_save" class="btn btn-primary btn-sm"><i class="fas fa-check me-1"></i>Save</button>
                            <?php if (mitUsageIngramConfigured()) { ?>
                                <button type="submit" name="mit_usage_ingram_customers" class="btn btn-outline-secondary btn-sm"><i class="fas fa-download me-1"></i>Save and read customers</button>
                            <?php } ?>
                        </div>
                    </form>
                    <p class="small text-muted mt-3">Usage names: <code>ingram:&lt;part number&gt;</code> for each product on the customer's active subscriptions
                        (e.g. a Microsoft 365 licence), with the product's name shown when you pick it on a recurring invoice. A product that ends is counted 0.</p>
                </div>
                <div class="col-lg-7">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <h6 class="mb-0 me-auto">Clients and their Ingram customer</h6>
                        <?php if ($customers) { ?>
                            <form action="post.php" method="post">
                                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                <button type="submit" name="mit_usage_ingram_match" class="btn btn-outline-secondary btn-sm"><i class="fas fa-magic me-1"></i>Match by name</button>
                            </form>
                        <?php } ?>
                    </div>
                    <?php if (!$customers && !$links) { ?>
                        <p class="text-muted small">Save the API login and read the customers first.</p>
                    <?php } else { ?>
                        <form action="post.php" method="post">
                            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                            <div class="table-responsive" style="max-height: 32rem">
                                <table class="table table-sm align-middle" data-mit-ingram-map>
                                    <thead><tr><th>Client</th><th>Ingram customer</th><th>Last run</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($clients as $client) {
                                        $link = $links[intval($client['client_id'])] ?? null; ?>
                                        <tr>
                                            <td><?= escapeHtml($client['client_name']) ?></td>
                                            <td><select class="form-select form-select-sm" name="map[<?= intval($client['client_id']) ?>]">
                                                <option value="">-</option>
                                                <?php if ($link && !isset($customers[$link['external_id']])) { ?>
                                                    <option value="<?= escapeHtml($link['external_id']) ?>" selected><?= escapeHtml($link['external_name'] ?: $link['external_id']) ?></option>
                                                <?php }
                                                foreach ($customers as $id => $name) { ?>
                                                    <option value="<?= escapeHtml((string) $id) ?>" <?= $link && (string) $link['external_id'] === (string) $id ? 'selected' : '' ?>><?= escapeHtml($name) ?></option>
                                                <?php } ?>
                                            </select></td>
                                            <td><?= $link ? $status_badge($link) : '' ?></td>
                                        </tr>
                                    <?php } ?>
                                    </tbody>
                                </table>
                            </div>
                            <button type="submit" name="mit_usage_ingram_map" class="btn btn-primary btn-sm"><i class="fas fa-check me-1"></i>Save</button>
                        </form>
                    <?php } ?>
                </div>
            </div>
        <?php } ?>
    </div>
</div>

<?php require_once "../includes/footer.php";
