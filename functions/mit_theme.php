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
    return '<link rel="stylesheet" href="' . mitAsset('/css/mit_login.css') . '">';
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

/** The switch under the logo on Company Details (inside ITFlow's form, saved by mitBrandLogoSave). */
function mitBrandLogoField() {
    return '<div class="form-check form-switch text-start mb-3" data-mit-brand-logo-only>'
        . '<input type="hidden" name="mit_brand_logo_present" value="1">'
        . '<input class="form-check-input" type="checkbox" role="switch" name="mit_brand_logo_only" value="1" id="mitBrandLogoOnly"' . (mitBrandLogoOnly() ? ' checked' : '') . '>'
        . '<label class="form-check-label" for="mitBrandLogoOnly">The logo shows the company name</label>'
        . '<div class="form-text">The side menu then shows the logo on its own, without the name beside it.</div></div>';
}

/** Hook in edit_company (admin/post/settings_company.php): save the switch when the form had it. */
function mitBrandLogoSave(array $post) {
    if (!empty($post['mit_brand_logo_present'])) {
        mitSetSetting('brand_logo_only', empty($post['mit_brand_logo_only']) ? '0' : '1');
    }
}

require_once __DIR__ . '/mit_client_tiles.php';
