<?php

/*
 * Mountaineer IT - the modern look: css/mit_theme.css + js/mit_theme.js on top of ITFlow's own styles, for agent,
 * admin, account and report pages (includes/header.php, one line). Each agent picks Modern (the default) or Classic
 * under Account > Preferences, saved as the ui.look preference (mit_user_preferences); it follows ITFlow's Light / Dark.
 * With it on, a client's pages also get count tiles and a contact line in the header (functions/mit_client_tiles.php).
 */

const MIT_LOOKS = ['modern' => 'Modern', 'classic' => 'Classic ITFlow'];

/** The look an agent picked: 'modern' (default) or 'classic'. */
function mitLook($user_id) {
    $look = (string) mitUserPreference($user_id, 'ui.look', 'modern');
    return isset(MIT_LOOKS[$look]) ? $look : 'modern';
}

/** True when the signed-in agent sees the modern look on this page. */
function mitLookOn() {
    global $session_user_id;
    static $on = null;
    if ($on === null) {
        $on = !empty($session_user_id) && mitLook($session_user_id) === 'modern';
    }
    return $on;
}

/** For includes/header.php: the time-field script (everyone), and the modern look's stylesheet and script (not for Classic or nobody signed in). */
function mitThemeHead() {
    // Time fields (clock icon = now, AM / PM from now) - for every look
    $time = '<script src="' . mitAsset('/js/mit_time_inputs.js') . '" defer></script>';
    if (!mitLookOn()) {
        return $time;
    }
    return $time . '<link rel="stylesheet" href="' . mitAsset('/css/mit_theme.css') . '">'
        . '<script src="' . mitAsset('/js/mit_theme.js') . '" defer></script>';
}

/** The sign-in screens' backdrop and card (login.php, client/login_reset.php) - for everyone, before anyone is signed in. */
function mitLoginHead() {
    return '<link rel="stylesheet" href="' . mitAsset('/css/mit_login.css') . '">'
        . (mitBrandLogoPlain() ? '<link rel="stylesheet" href="' . mitAsset('/css/mit_login_plain.css') . '">' : '');
}

/** The Look choice on Account > Preferences. */
function mitThemePreferenceField($user_id) {
    $look = mitLook($user_id);
    $html = '<div class="col-md-6"><label class="form-label" for="mitLook">Look</label><div class="input-group">'
        . '<span class="input-group-text"><i class="fas fa-fw fa-palette"></i></span><select class="form-select" name="mit_look" id="mitLook">';
    foreach (MIT_LOOKS as $value => $label) {
        $html .= '<option value="' . $value . '"' . ($look === $value ? ' selected' : '') . '>' . $label . '</option>';
    }
    return $html . '</select></div><div class="form-text">Modern works with both Light and Dark.</div></div>';
}

/** Save it (a no-op when the form did not have it). */
function mitThemeSavePreference($user_id, array $post) {
    if (isset($post['mit_look'])) {
        mitSetUserPreference($user_id, 'ui.look', isset(MIT_LOOKS[$post['mit_look']]) ? $post['mit_look'] : 'modern');
    }
}

/*
 * The company's mark in the side menu (agent/includes/side_nav.php): ITFlow puts the company name beside the logo. When the
 * logo already has the name in it, Admin > Settings > Company Details > "The logo shows the company name" (mit_settings
 * brand_logo_only) shows the logo on its own, across the width the name took. For every look.
 */
function mitBrandLogoOnly() {
    return (string) mitSetting('brand_logo_only', '0') === '1';
}

/*
 * A second logo for ITFlow's own screens - the side menu and the sign-in page (both dark) - when the
 * company logo (ITFlow's, kept for quotes, invoices, PDFs, the guest pages and the light portal / MFA set-up) is drawn for paper. mit_settings brand_app_logo =
 * a file in uploads/settings/ (a random name, like ITFlow's), uploaded / removed under Company Details (mitBrandLogoField / Save).
 */
function mitBrandAppLogo() {
    $file = basename((string) mitSetting('brand_app_logo', ''));
    return $file !== '' && is_file(__DIR__ . "/../uploads/settings/$file") ? $file : '';
}

/** The logo for a screen: the app logo when there is one, else the company logo given. Hooks in side_nav and login. */
function mitBrandScreenLogo($company_logo) {
    return mitBrandAppLogo() ?: $company_logo;
}

/** No light tile behind the logo in the side menu (Company Details switch, mit_settings brand_logo_plain) - for a logo drawn for a dark background. */
function mitBrandLogoPlain() {
    return (string) mitSetting('brand_logo_plain', '0') === '1';
}

/** agent/includes/side_nav.php: attributes for the logo tile - wider for a logo on its own, no tile for a plain one. Inline, so every look gets them. */
function mitBrandLogoAttrs() {
    $style = [];
    $attrs = '';
    if (mitBrandLogoOnly()) {
        $attrs .= ' data-mit-logo-only';
        $style[] = 'max-width: none; flex: 1 1 auto; justify-content: flex-start';
    }
    if (mitBrandLogoPlain()) {
        $attrs .= ' data-mit-logo-plain';
        $style[] = 'background: transparent; box-shadow: none; padding: 0; border-radius: 0';
    }
    return $attrs . ($style ? ' style="' . implode('; ', $style) . '"' : '');
}

/** The switches under the logo on Company Details (inside ITFlow's form, saved by mitBrandLogoSave). */
function mitBrandLogoField() {
    $app = mitBrandAppLogo();
    return '<div class="mb-3 text-start" data-mit-brand-app-logo><hr>'
        . '<label>Logo for ITFlow\'s screens</label>'
        . ($app !== '' ? '<img class="img-thumbnail d-block mx-auto my-2" style="background: #1e293b" src="../uploads/settings/' . escapeHtml(rawurlencode($app)) . '" alt="App logo">'
            . '<div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="mit_app_logo_remove" value="1" id="mitAppLogoRemove">'
            . '<label class="form-check-label" for="mitAppLogoRemove">Remove it (use the company logo)</label></div>' : '')
        . '<input type="file" class="form-control" name="mit_app_logo" accept=".jpg, .jpeg, .png">'
        . '<div class="form-text">The side menu and the sign-in page show this one. The company logo above stays on quotes, invoices, '
        . 'purchase orders and their PDFs. Leave it empty to use the company logo everywhere.</div></div>'
        . '<div class="form-check form-switch text-start mb-3" data-mit-brand-logo-only>'
        . '<input type="hidden" name="mit_brand_logo_present" value="1">'
        . '<input class="form-check-input" type="checkbox" role="switch" name="mit_brand_logo_only" value="1" id="mitBrandLogoOnly"' . (mitBrandLogoOnly() ? ' checked' : '') . '>'
        . '<label class="form-check-label" for="mitBrandLogoOnly">The logo shows the company name</label>'
        . '<div class="form-text">The side menu then shows the logo on its own, without the name beside it.</div></div>'
        . '<div class="form-check form-switch text-start mb-3" data-mit-brand-logo-plain>'
        . '<input class="form-check-input" type="checkbox" role="switch" name="mit_brand_logo_plain" value="1" id="mitBrandLogoPlain"' . (mitBrandLogoPlain() ? ' checked' : '') . '>'
        . '<label class="form-check-label" for="mitBrandLogoPlain">No light tile behind the logo</label>'
        . '<div class="form-text">In the side menu and on the sign-in page - for a logo drawn for a dark background.</div></div>';
}

/** Hook in edit_company (admin/post/settings_company.php): save the switch when the form had it. */
function mitBrandLogoSave(array $post) {
    $lines = []; // what changed, in words - for ITFlow's settingsSaid
    if (!empty($post['mit_brand_logo_present'])) {
        foreach (['brand_logo_only' => ['mit_brand_logo_only', 'Logo on its own in the side menu'], 'brand_logo_plain' => ['mit_brand_logo_plain', 'No light tile behind the logo']] as $key => [$field, $label]) {
            $on = empty($post[$field]) ? '0' : '1';
            if ((string) mitSetting($key, '0') !== $on) {
                $lines[] = "$label " . ($on === '1' ? 'on' : 'off');
            }
            mitSetSetting($key, $on);
        }
        $before = mitBrandAppLogo();
        mitBrandAppLogoSave(!empty($post['mit_app_logo_remove']), $_FILES['mit_app_logo'] ?? null);
        if (mitBrandAppLogo() !== $before) {
            $lines[] = mitBrandAppLogo() !== '' ? 'Logo for ITFlow\'s screens uploaded' : 'Logo for ITFlow\'s screens removed';
        }
    }
    return $lines;
}

/** Store a new app logo (JPG / PNG that really is an image) or remove it; the old file goes. Returns a problem or ''. */
function mitBrandAppLogoSave($remove, $file) {
    $dir = __DIR__ . '/../uploads/settings/';
    $old = mitBrandAppLogo();
    $new = '';
    if (is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $name = checkFileUpload($file, ['jpg', 'jpeg', 'png']);
        $info = $name ? @getimagesize($file['tmp_name']) : false;
        if (!$name || !$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true) || !move_uploaded_file($file['tmp_name'], $dir . $name)) {
            flashAlert('The logo for ITFlow\'s screens was not saved - pick a JPG or PNG image.', 'error');
            return 'not an image';
        }
        $new = $name;
    } elseif (!$remove) {
        return '';
    }
    mitSetSetting('brand_app_logo', $new);
    if ($old !== '' && $old !== $new) {
        @unlink($dir . $old);
    }
    global $session_name;
    logAudit('Settings', 'Edit', escapeSql(($session_name ?? 'Someone') . ($new !== '' ? ' uploaded' : ' removed') . ' the logo for ITFlow\'s screens'));
    return '';
}

require_once __DIR__ . '/mit_client_tiles.php';
