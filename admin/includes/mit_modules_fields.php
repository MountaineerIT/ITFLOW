<?php
/*
 * Mountaineer IT - our modules' switches on Admin > Settings > Modules, inside ITFlow's form
 * (required from admin/settings_module.php; saved by mitModulesSave() from admin/post/settings_module.php).
 * Off hides the module's menus and closes its pages; nothing is deleted. See mitModuleOn() in functions/mit_common.php.
 */

$mit_modules = [
    'procurement' => ['Show Procurement', 'To order, purchase orders and receiving, and parts on tickets', 'Needs Invoicing / Accounting on'],
    'timesheets' => ['Show Timesheets', 'Timesheets, leave balances and the payroll report', ''],
    'dispatch' => ['Show Dispatch', 'The dispatch board in Support', 'Needs Ticketing on'],
];
foreach ($mit_modules as $mit_key => [$mit_label, $mit_what, $mit_needs]) { ?>
    <div class="mb-3">
        <div class="form-check form-switch">
            <input type="hidden" name="mit_module_<?= $mit_key ?>" value="0">
            <input type="checkbox" class="form-check-input" name="mit_module_<?= $mit_key ?>" value="1" id="mitModule<?= ucfirst($mit_key) ?>" <?= mitSetting("module_$mit_key", '1') !== '0' ? 'checked' : '' ?>>
            <label class="form-check-label" for="mitModule<?= ucfirst($mit_key) ?>"><?= $mit_label ?> <small class="text-secondary">(<?= $mit_what ?><?= $mit_needs !== '' ? ' - ' . $mit_needs : '' ?>)</small></label>
        </div>
    </div>
<?php }
