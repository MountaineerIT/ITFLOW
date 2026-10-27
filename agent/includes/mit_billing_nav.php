<?php
/*
 * Mountaineer IT - our entries in the agent side menu's BILLING section, required from agent/includes/side_nav.php after
 * Products (the section already needs Sales View):
 * - Unbilled Parts (agent/mit_unbilled_parts.php, functions/mit_procurement_parts.php) - needs the Procurement module, which keeps parts.
 * - Usage Review (functions/mit_usage.php) - once a recurring invoice has a usage line.
 * - Invoice Approvals and QuickBooks Batches (functions/mit_qbo_billing.php) - once QuickBooks Online is set up (Admin > Integrations).
 */

if (function_exists('mitUnbilledPartsCount') && mitProcOn() && mitTicketPartsReady()) {
    $mit_unbilled = mitNavCachedCount('unbilled_parts:' . clientScopeSql(defined('MIT_PART_CLIENT_SQL') ? MIT_PART_CLIENT_SQL : 'client_id'), 'mitUnbilledPartsCount',
        basename($_SERVER['PHP_SELF']) === 'mit_unbilled_parts.php'); ?>
    <li class="nav-item">
        <a href="/agent/mit_unbilled_parts.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'mit_unbilled_parts.php' ? 'active' : '' ?>">
            <i class="nav-icon fas fa-file-invoice-dollar"></i>
            <p>
                Unbilled Parts
                <?php if ($mit_unbilled) { ?><span class="right badge text-light" data-bs-toggle="tooltip" title="Parts on tickets not billed yet"><?= $mit_unbilled ?></span><?php } ?>
            </p>
        </a>
    </li>
<?php }

if (function_exists('mitUsageReady') && mitUsageReady() && mysqli_num_rows(mysqli_query($mysqli, "SELECT 1 FROM mit_usage_lines LIMIT 1"))) {
    $mit_usage_count = mitNavCachedCount('usage:' . clientScopeSql('invoices.invoice_client_id'), 'mitUsageNavCount', basename($_SERVER['PHP_SELF']) === 'mit_usage_review.php'); ?>
    <li class="nav-item">
        <a href="/agent/mit_usage_review.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'mit_usage_review.php' ? 'active' : '' ?>">
            <i class="nav-icon fas fa-tachometer-alt"></i>
            <p>
                Usage Review
                <?php if ($mit_usage_count) { ?><span class="right badge text-light" data-bs-toggle="tooltip" title="Invoices with usage to review"><?= $mit_usage_count ?></span><?php } ?>
            </p>
        </a>
    </li>
<?php }

if (function_exists('mitQboReady') && mitQboReady() && (mitQboConnected() || mitQboSettings()['client_id'] !== '')) {
    $mit_page = basename($_SERVER['PHP_SELF']);
    $mit_qbo_counts = mitNavCachedCount('qbo:' . clientScopeSql('invoices.invoice_client_id'), 'mitQboNavCounts',
        in_array($mit_page, ['mit_invoice_approvals.php', 'mit_qbo_batches.php'], true)); ?>
    <li class="nav-item">
        <a href="/agent/mit_invoice_approvals.php" class="nav-link <?= $mit_page === 'mit_invoice_approvals.php' ? 'active' : '' ?>">
            <i class="nav-icon fas fa-clipboard-check"></i>
            <p>
                Invoice Approvals
                <?php if ($mit_qbo_counts['awaiting']) { ?><span class="right badge text-light" data-bs-toggle="tooltip" title="Draft invoices awaiting approval"><?= $mit_qbo_counts['awaiting'] ?></span><?php } ?>
            </p>
        </a>
    </li>
    <li class="nav-item">
        <a href="/agent/mit_qbo_batches.php" class="nav-link <?= in_array($mit_page, ['mit_qbo_batches.php', 'mit_qbo_batch.php'], true) ? 'active' : '' ?>">
            <i class="nav-icon fas fa-layer-group"></i>
            <p>
                QuickBooks Batches
                <?php if ($mit_qbo_counts['ready']) { ?><span class="right badge text-light" data-bs-toggle="tooltip" title="Approved invoices waiting for a batch"><?= $mit_qbo_counts['ready'] ?></span><?php } ?>
            </p>
        </a>
    </li>
<?php }
