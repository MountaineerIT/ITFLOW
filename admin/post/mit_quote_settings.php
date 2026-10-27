<?php

/*
 * Mountaineer IT - Quote pricing defaults (functions/mit_quotes.php), from Admin > Settings > Quotes.
 */

defined('FROM_POST_HANDLER') || die("Direct file access is not allowed");

if (isset($_POST['mit_edit_quote_pricing'])) {

    validateCSRFToken();

    $method = ($_POST['mit_quote_price_method'] ?? '') === 'margin' ? 'margin' : 'markup';
    $percent = mitPostedNumber($_POST['mit_quote_price_percent'] ?? '');
    if ($percent === null || $percent < 0 || ($method === 'margin' && $percent >= 100) || $percent >= 1000) {
        flashAlert($method === 'margin' ? 'The default margin must be from 0 to under 100%' : 'The default markup must be a % from 0 to 999', 'error');
        redirect();
    }
    $presets = [];
    foreach (explode(',', (string) ($_POST['mit_quote_price_presets'] ?? '')) as $preset) {
        $preset = mitPostedNumber($preset);
        if ($preset !== null && $preset > 0 && $preset < 1000 && count($presets) < 8) {
            $presets[] = rtrim(rtrim(number_format($preset, 2, '.', ''), '0'), '.');
        }
    }

    mitSetSetting('quote_price_method', $method);
    mitSetSetting('quote_price_percent', $percent);
    mitSetSetting('quote_price_presets', implode(',', array_unique($presets)));
    mitSetSetting('quote_show_images', empty($_POST['mit_quote_show_images']) ? '0' : '1');

    logAudit("Settings", "Edit", "$session_name edited Quote pricing settings");

    flashAlert("Quote pricing updated");

    redirect();

}
