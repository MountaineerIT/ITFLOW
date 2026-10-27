<?php
/*
 * Mountaineer IT - our pages in the admin side menu's SETTINGS, required from admin/includes/side_nav.php
 * after Ticket. Procurement only with invoicing on (as Quote and Invoice), Boards with ticketing on; the SETTINGS menu opens on them
 * through mitAdminSettingsPages() (functions/mit_common.php).
 */
?>
<?php if ($config_module_enable_accounting) { ?>
    <li class="nav-item">
        <a href="/admin/mit_procurement_settings.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'mit_procurement_settings.php' ? 'active' : '' ?>">
            <i class="nav-icon fas fa-file-signature"></i>
            <p>Procurement</p>
        </a>
    </li>
<?php } ?>
<li class="nav-item">
    <a href="/admin/mit_timesheets.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'mit_timesheets.php' ? 'active' : '' ?>">
        <i class="nav-icon fas fa-business-time"></i>
        <p>Timesheets</p>
    </a>
</li>
<?php if ($config_module_enable_ticketing && function_exists('mitBoardsReady')) { ?>
    <li class="nav-item">
        <a href="/admin/mit_boards.php" class="nav-link <?= basename($_SERVER['PHP_SELF']) === 'mit_boards.php' ? 'active' : '' ?>">
            <i class="nav-icon fas fa-columns"></i>
            <p>Boards</p>
        </a>
    </li>
<?php } ?>
