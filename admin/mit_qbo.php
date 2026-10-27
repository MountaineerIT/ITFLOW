<?php

/*
 * Mountaineer IT - Admin > Integrations > QuickBooks Online (functions/mit_qbo.php; handlers admin/post/mit_qbo.php).
 * Tabs: Connection (the Intuit app, Connect / Disconnect, tax style, fallback item, payment account, emails), Customers /
 * Items / Tax codes (which QuickBooks customer, item or tax code each ITFlow client, product or tax becomes).
 */

require_once "includes/inc_all_admin.php";

if (!mitQboReady()) {
    echo '<div class="alert alert-warning">QuickBooks Online needs the database update - Admin > Update.</div>';
    require_once "../includes/footer.php";
    exit;
}

$settings = mitQboSettings();
$connected = mitQboConnected();
$tabs = ['connection' => 'Connection', 'customers' => 'Customers', 'items' => 'Items', 'tax_codes' => 'Tax codes'];
$tab = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'connection';
$csrf = $_SESSION['csrf_token'];

// QuickBooks' lists for the pickers (read live; a failure is shown, not fatal)
$qbo_lists = ['customers' => [], 'items' => [], 'tax_codes' => []];
$qbo_error = '';
if ($connected) {
    try {
        foreach ($tab === 'connection' ? ['items', 'tax_codes'] : [$tab] as $kind) {
            $qbo_lists[$kind] = mitQboList($kind);
        }
    } catch (Throwable $e) {
        $qbo_error = $e->getMessage();
    }
}

?>

<?php if (mitDevMode()) { ?>
    <div class="alert alert-danger" data-mit-qbo-dev>
        <i class="fas fa-flask me-2"></i><strong>Development mode:</strong> QuickBooks Online works here only with an Intuit <strong>sandbox</strong>
        company connected on this server - the connection that came with production's backup is not used (Disconnect only forgets it
        here). Connecting the sandbox company clears the batches and mappings copied from production on this server. Invoice emails
        from QuickBooks go to the test inbox.
        <?php if (mitQboDevProblem() !== '' && $connected) { ?><div class="mt-1"><?= escapeHtml(mitQboDevProblem()) ?></div><?php } ?>
    </div>
<?php } ?>

<div class="card">
    <div class="card-header bg-dark py-2 d-flex flex-wrap align-items-center gap-2">
        <h3 class="card-title mt-2 me-auto"><i class="fas fa-fw fa-file-invoice-dollar me-2"></i>QuickBooks Online</h3>
        <?php if ($connected) { ?>
            <span class="badge text-bg-success"><i class="fas fa-plug me-1"></i><?= escapeHtml($settings['company_name'] ?: 'Connected') ?></span>
        <?php } ?>
    </div>
    <div class="card-body pb-0">
        <ul class="nav nav-tabs">
            <?php foreach ($tabs as $key => $label) { ?>
                <li class="nav-item"><a class="nav-link <?= $tab === $key ? 'active' : '' ?>" href="?tab=<?= $key ?>"><?= $label ?></a></li>
            <?php } ?>
        </ul>
    </div>
    <div class="card-body">
        <?php if ($qbo_error !== '') { ?>
            <div class="alert alert-danger"><?= escapeHtml($qbo_error) ?></div>
        <?php } ?>

        <?php if ($tab === 'connection') { ?>
            <div class="row g-4">
                <div class="col-lg-7">
                    <form action="post.php" method="post" autocomplete="off" class="row g-3">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <div class="col-12"><h6 class="mb-0"><i class="fas fa-fw fa-key me-2"></i>Intuit app</h6>
                            <div class="form-text">At developer.intuit.com make an app with the <strong>Accounting</strong> scope, add the redirect URI below to it,
                                and copy its keys here (Development keys for a sandbox company, Production keys for your real one).</div></div>
                        <div class="col-md-6">
                            <label class="form-label">Client ID</label>
                            <input type="text" class="form-control" name="qbo_client_id" maxlength="200" value="<?= escapeHtml($settings['client_id']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Client secret</label>
                            <input type="password" class="form-control" name="qbo_client_secret" maxlength="500" autocomplete="new-password" placeholder="<?= $settings['has_secret'] ? 'Saved - type a new one to replace it' : '' ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Company</label>
                            <select class="form-select" name="qbo_env" <?= $connected ? 'disabled' : '' ?>>
                                <option value="production" <?= $settings['env'] === 'production' ? 'selected' : '' ?>>Production (real company)</option>
                                <option value="sandbox" <?= $settings['env'] === 'sandbox' ? 'selected' : '' ?>>Sandbox (for trying it out)</option>
                            </select>
                            <?php if ($connected) { ?><div class="form-text">Disconnect to change it.</div><?php } ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Redirect URI</label>
                            <input type="text" class="form-control" readonly value="<?= escapeHtml(mitQboRedirectUri()) ?>" onclick="this.select()">
                        </div>

                        <div class="col-12"><h6 class="mb-0 mt-2"><i class="fas fa-fw fa-percent me-2"></i>Tax</h6></div>
                        <div class="col-md-6">
                            <label class="form-label">How QuickBooks takes tax</label>
                            <select class="form-select" name="qbo_tax_style">
                                <option value="us" <?= $settings['tax_style'] === 'us' ? 'selected' : '' ?>>US company - lines Taxable / Non, one tax code per invoice</option>
                                <option value="line" <?= $settings['tax_style'] === 'line' ? 'selected' : '' ?>>Outside the US - a tax code on every line</option>
                            </select>
                            <div class="form-text">Map each ITFlow tax to a QuickBooks tax code (or rate) under Tax codes.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Tax code for untaxed lines</label>
                            <?php if ($qbo_lists['tax_codes']) { ?>
                                <select class="form-select" name="qbo_no_tax_code">
                                    <option value="">-</option>
                                    <?php foreach ($qbo_lists['tax_codes'] as $code) { ?>
                                        <option value="<?= escapeHtml($code['id']) ?>" <?= $settings['no_tax_code'] === $code['id'] ? 'selected' : '' ?>><?= escapeHtml($code['name']) ?></option>
                                    <?php } ?>
                                </select>
                            <?php } else { ?>
                                <input type="text" class="form-control" name="qbo_no_tax_code" maxlength="32" value="<?= escapeHtml($settings['no_tax_code']) ?>" placeholder="Connect to pick one">
                            <?php } ?>
                            <div class="form-text">Only for "outside the US" (e.g. Exempt / Zero-rated).</div>
                        </div>

                        <div class="col-12"><h6 class="mb-0 mt-2"><i class="fas fa-fw fa-file-invoice me-2"></i>Invoices</h6></div>
                        <div class="col-md-6">
                            <label class="form-label">Item for lines with no match</label>
                            <?php if ($qbo_lists['items']) { ?>
                                <select class="form-select" name="qbo_fallback_item">
                                    <option value="">- none: such lines stop the send -</option>
                                    <?php foreach ($qbo_lists['items'] as $item) { ?>
                                        <option value="<?= escapeHtml($item['id']) ?>" <?= $settings['fallback_item'] === $item['id'] ? 'selected' : '' ?>><?= escapeHtml($item['name']) ?></option>
                                    <?php } ?>
                                </select>
                            <?php } else { ?>
                                <input type="text" class="form-control" name="qbo_fallback_item" maxlength="32" value="<?= escapeHtml($settings['fallback_item']) ?>" placeholder="Connect to pick one">
                            <?php } ?>
                            <div class="form-text">A line uses its product's item, else the QuickBooks item with the line's name, else this one.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Account for payments from QuickBooks</label>
                            <select class="form-select" name="qbo_payment_account">
                                <option value="0">-</option>
                                <?php $accounts = mysqli_query($mysqli, "SELECT account_id, account_name FROM accounts WHERE account_archived_at IS NULL ORDER BY account_name");
                                while ($account = mysqli_fetch_assoc($accounts)) { ?>
                                    <option value="<?= intval($account['account_id']) ?>" <?= $settings['payment_account'] === intval($account['account_id']) ? 'selected' : '' ?>><?= escapeHtml($account['account_name']) ?></option>
                                <?php } ?>
                            </select>
                            <div class="form-text">The ITFlow account payments made in QuickBooks are recorded in (e.g. "QuickBooks clearing").</div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" name="qbo_email_default" value="1" id="mitQboEmail" <?= $settings['email_default'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="mitQboEmail">New batches: QuickBooks emails the invoices</label>
                            </div>
                            <div class="form-text">To the client's billing contacts (else the primary contact). Each batch can change it.</div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" name="qbo_create_customers" value="1" id="mitQboCreate" <?= $settings['create_customers'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="mitQboCreate">Make missing customers in QuickBooks</label>
                            </div>
                            <div class="form-text">When a client has no customer yet: link the one with the same name, or make it.</div>
                        </div>
                        <div class="col-12">
                            <button type="submit" name="mit_qbo_save_settings" class="btn btn-primary"><i class="fas fa-check me-2"></i>Save</button>
                        </div>
                    </form>
                </div>

                <div class="col-lg-5">
                    <div class="border rounded p-3 mb-3">
                        <h6><i class="fas fa-fw fa-plug me-2"></i>Connection</h6>
                        <?php if ($connected) { ?>
                            <p class="mb-1">Connected to <strong><?= escapeHtml($settings['company_name'] ?: 'company ' . $settings['realm_id']) ?></strong>
                                (<?= $settings['env'] === 'sandbox' ? 'sandbox' : 'production' ?>, company ID <?= escapeHtml($settings['realm_id']) ?>).</p>
                            <p class="small text-muted mb-2">Since <?= escapeHtml($settings['connected_at']) ?>.
                                <?php if ($settings['refresh_expires']) { ?>Sign-in good until <?= date('M j, Y', $settings['refresh_expires']) ?> (renewed as it is used).<?php } ?></p>
                            <form action="post.php" method="post" class="d-flex flex-wrap gap-2">
                                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                <button type="submit" name="mit_qbo_test" class="btn btn-outline-secondary btn-sm"><i class="fas fa-vial me-2"></i>Test</button>
                                <button type="submit" name="mit_qbo_disconnect" class="btn btn-outline-danger btn-sm confirm-link"><i class="fas fa-unlink me-2"></i>Disconnect</button>
                            </form>
                        <?php } elseif ($settings['client_id'] !== '' && $settings['has_secret']) { ?>
                            <p class="small text-muted">Sign in to Intuit as a QuickBooks admin and pick the company.</p>
                            <form action="post.php" method="post">
                                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                <button type="submit" name="mit_qbo_connect" class="btn btn-success"><i class="fas fa-plug me-2"></i>Connect to QuickBooks</button>
                            </form>
                        <?php } else { ?>
                            <p class="small text-muted mb-0">Save the Intuit app's client ID and secret first.</p>
                        <?php } ?>
                    </div>

                    <?php if ($connected) { ?>
                        <div class="border rounded p-3 mb-3">
                            <h6><i class="fas fa-fw fa-hand-holding-usd me-2"></i>Payments back</h6>
                            <p class="small mb-1">Checked every hour (cron job mit_qbo_sync).
                                Last check: <?= $settings['last_sync'] !== '' ? escapeHtml($settings['last_sync']) : 'never' ?>.</p>
                            <?php if ($settings['last_error'] !== '') { ?>
                                <p class="small text-danger mb-1"><?= escapeHtml($settings['last_error']) ?></p>
                            <?php } ?>
                            <form action="post.php" method="post">
                                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                                <button type="submit" name="mit_qbo_sync_now" class="btn btn-outline-secondary btn-sm"><i class="fas fa-sync-alt me-2"></i>Check payments now</button>
                            </form>
                        </div>
                    <?php } ?>

                    <div class="alert alert-light border small mb-0">
                        <strong>How it works:</strong> invoices are approved (Billing > Invoice Approvals), gathered into a batch and sent (Billing > QuickBooks
                        Batches). An invoice in a batch is locked in ITFlow until it is taken out. While connected, QuickBooks emails the invoices and takes the
                        payments: ITFlow's own invoice emails, reminders and recurring auto-send stop, and payments on invoices in QuickBooks come back on their own.
                        <ul class="mb-0 mt-2 ps-3">
                            <li>Turn on <strong>Custom transaction numbers</strong> in QuickBooks (Account and settings > Sales) so it keeps ITFlow's invoice numbers.</li>
                            <li>Turn off ITFlow's Stripe payments for clients billed through QuickBooks.</li>
                        </ul>
                    </div>
                </div>
            </div>

        <?php } else {
            $kind = $tab;
            $map = mitQboMap($kind);
            $labels = ['customers' => ['Client', 'QuickBooks customer'], 'items' => ['Product', 'QuickBooks item'], 'tax_codes' => ['Tax', 'QuickBooks tax code']];
            $sources = [
                'customers' => "SELECT client_id AS id, client_name AS name, '' AS detail FROM clients WHERE client_archived_at IS NULL ORDER BY client_name",
                'items' => "SELECT product_id AS id, product_name AS name, product_price AS detail FROM products WHERE product_archived_at IS NULL ORDER BY product_name",
                'tax_codes' => "SELECT tax_id AS id, tax_name AS name, tax_percent AS detail FROM taxes WHERE tax_archived_at IS NULL ORDER BY tax_name",
            ];
            $rows = mysqli_fetch_all(mysqli_query($mysqli, $sources[$kind]), MYSQLI_ASSOC);
            $options = $qbo_lists[$kind]; ?>

            <?php if (!$connected) { ?>
                <div class="alert alert-info">Connect to QuickBooks first (Connection tab).</div>
            <?php } else { ?>
                <div class="d-flex flex-wrap align-items-start gap-3 mb-3">
                    <p class="text-muted mb-0 me-auto" style="max-width: 46rem">
                        <?php if ($kind === 'customers') { ?>
                            The QuickBooks customer each client's invoices go to. Unmapped clients get one when their first invoice is sent (if "Make missing
                            customers" is on).
                        <?php } elseif ($kind === 'items') { ?>
                            The QuickBooks item (product / service) each ITFlow product's invoice lines use - it decides the income account in QuickBooks. Lines
                            without a product use the item with the same name, else the fallback item.
                        <?php } else { ?>
                            The QuickBooks tax code (US: the tax group or rate for the whole invoice) each ITFlow tax becomes. QuickBooks works the tax amount out itself.
                        <?php } ?>
                    </p>
                    <form action="post.php" method="post">
                        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                        <input type="hidden" name="kind" value="<?= $kind ?>">
                        <button type="submit" name="mit_qbo_auto_match" class="btn btn-outline-secondary btn-sm"><i class="fas fa-magic me-2"></i>Match unmapped by name</button>
                    </form>
                </div>
                <form action="post.php" method="post" id="mitQboMapForm">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="kind" value="<?= $kind ?>">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle" style="max-width: 60rem">
                            <thead><tr><th><?= $labels[$kind][0] ?></th><th><?= $labels[$kind][1] ?></th><?php if ($kind === 'customers') { ?><th></th><?php } ?></tr></thead>
                            <tbody>
                            <?php foreach ($rows as $row) {
                                $id = intval($row['id']);
                                $current = $map[$id]['qbo_id'] ?? ''; ?>
                                <tr>
                                    <td><?= escapeHtml($row['name']) ?>
                                        <?php if ($kind === 'items') { ?><span class="text-muted small ms-1"><?= numfmt_format_currency($currency_format, floatval($row['detail']), $session_company_currency) ?></span><?php } ?>
                                        <?php if ($kind === 'tax_codes') { ?><span class="text-muted small ms-1"><?= floatval($row['detail']) ?>%</span><?php } ?>
                                    </td>
                                    <td style="min-width: 18rem">
                                        <select class="form-select form-select-sm" name="map[<?= $id ?>]">
                                            <option value="">- not mapped -</option>
                                            <?php if ($current !== '' && !isset($options[$current])) { ?>
                                                <option value="<?= escapeHtml($current) ?>" selected><?= escapeHtml($map[$id]['qbo_name']) ?> (inactive or gone)</option>
                                            <?php }
                                            foreach ($options as $option) { ?>
                                                <option value="<?= escapeHtml($option['id']) ?>" <?= $current === $option['id'] ? 'selected' : '' ?>><?= escapeHtml($option['name']) ?></option>
                                            <?php } ?>
                                        </select>
                                    </td>
                                    <?php if ($kind === 'customers') { ?>
                                        <td><?php if ($current === '') { ?>
                                            <button type="submit" form="mitQboMapForm" name="mit_qbo_create_customer" value="<?= $id ?>" class="btn btn-link btn-sm p-0">Make in QuickBooks</button>
                                        <?php } ?></td>
                                    <?php } ?>
                                </tr>
                            <?php } ?>
                            <?php if (!$rows) { ?><tr><td colspan="3" class="text-muted">Nothing here yet.</td></tr><?php } ?>
                            </tbody>
                        </table>
                    </div>
                    <button type="submit" name="mit_qbo_save_map" class="btn btn-primary"><i class="fas fa-check me-2"></i>Save</button>
                </form>
            <?php } ?>
        <?php } ?>
    </div>
</div>

<?php require_once "../includes/footer.php";
