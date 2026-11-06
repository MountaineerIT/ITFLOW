<?php

/*
 * Mountaineer IT - Admin > Settings > Labor costs (admin/mit_labor_costs.php, functions/mit_profit.php). The audit log names the agent,
 * never the amount (pay).
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['mit_labor_default_save'])) {

    validateCSRFToken();

    $value = trim(str_replace([',', '$'], '', (string) ($_POST['mit_labor_default'] ?? '')));
    if ($value !== '' && (!is_numeric($value) || (float) $value < 0 || (float) $value > 100000)) {
        flashAlert('Enter a default cost per hour of 0 or more, or leave it empty.', 'error');
        redirect();
    }
    mitSetSetting('profit_default_cost', $value === '' ? '' : (string) round((float) $value, 2));
    logAudit("Settings", "Edit", "$session_name changed the default labor cost per hour");
    flashAlert('Default labor cost saved');
    redirect();

}

if (isset($_POST['mit_labor_rate_add'])) {

    validateCSRFToken();

    $user_id = intval($_POST['mit_labor_rate_add']);
    $problem = mitProfitRateSave($user_id, $_POST['mit_labor_from'] ?? '', $_POST['mit_labor_cost'] ?? '');
    if ($problem !== '') {
        flashAlert($problem, 'error');
        redirect();
    }
    $name = mysqli_fetch_row(mysqli_query($mysqli, "SELECT user_name FROM users WHERE user_id = $user_id"))[0] ?? "agent $user_id";
    logAudit("Settings", "Edit", "$session_name set a labor cost for " . mysqli_real_escape_string($mysqli, $name), 0, $user_id);
    flashAlert('Labor cost saved for ' . escapeHtml($name));
    redirect();

}

if (isset($_POST['mit_labor_rate_delete'])) {

    validateCSRFToken();

    $user_id = mitProfitRateDelete(intval($_POST['mit_labor_rate_delete']));
    if ($user_id) {
        $name = mysqli_fetch_row(mysqli_query($mysqli, "SELECT user_name FROM users WHERE user_id = $user_id"))[0] ?? "agent $user_id";
        logAudit("Settings", "Edit", "$session_name removed a labor cost of " . mysqli_real_escape_string($mysqli, $name), 0, $user_id);
        flashAlert('Rate removed');
    }
    redirect();

}
