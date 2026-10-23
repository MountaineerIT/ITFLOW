<?php

/*
 * Mountaineer IT: remembered list views - the filters, pill, sort and List /
 * Board an agent last picked on a list page, kept per agent in
 * mit_user_preferences (like the calendar's settings) and put back when the
 * page is opened without any (from the side menu, say). A "Reset view"
 * button forgets them.
 *
 * Only choices made on the page itself are kept: the request must come from
 * the same page (Referer), so links from the dashboard, emails and notices
 * still show what they point to without replacing the saved view. Searches,
 * the page number and one-off keys (open=) are never kept.
 */

// The query keys each page remembers
function mitListViewKeys($page) {
    $keys = [
        'tickets' => ['board', 'view', 'show', 'assigned', 'client', 'status', 'priority', 'category', 'project', 'sla', 'billing', 'canned_date', 'dtf', 'dtt', 'sort', 'order', 'state', 'billable', 'unbilled'],
    ];
    return $keys[$page] ?? [];
}

// The view keys this request carries - canned_date=custom with no range is filter_header.php's own filler, not a choice
function mitListViewFromRequest($page) {
    $view = [];
    foreach (mitListViewKeys($page) as $key) {
        if (!isset($_GET[$key])) {
            continue;
        }
        if ($key === 'canned_date' && $_GET[$key] === 'custom' && empty($_GET['dtf'])) {
            continue;
        }
        $value = $_GET[$key];
        if (is_array($value)) {
            $value = array_values(array_filter(array_map('strval', $value), 'is_numeric'));
        } else {
            $value = substr((string) $value, 0, 100);
        }
        $view[$key] = $value;
    }
    return $view;
}

// Did the agent pick this on the page itself (not a link from elsewhere)?
function mitListViewFromSamePage($page) {
    $referer = parse_url($_SERVER['HTTP_REFERER'] ?? '');
    if (empty($referer['path']) || ($referer['host'] ?? '') !== parse_url('//' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)) {
        return false;
    }
    parse_str($referer['query'] ?? '', $referer_query);
    return basename($referer['path']) === "$page.php" && !isset($referer_query['client_id']);
}

/*
 * Run right after inc_all: remembers or restores the view. Returns true when
 * it put a saved view into $_GET - the caller then runs filter_header.php
 * again so sort / order / dates follow it.
 */
function mitListViewRestore($page) {
    global $session_user_id, $mit_list_view_restored;

    $mit_list_view_restored = false;
    if (isset($_GET['client_id']) || !mitListViewKeys($page)) {
        return false;  // one client's list - always the plain view
    }
    $preference = "listview.$page";

    // Reset carries its own token (mitListViewResetToken), so another site cannot wipe someone's saved view with a link or image
    if (isset($_GET['mit_reset_view']) && hash_equals(mitListViewResetToken(), (string) $_GET['mit_reset_view'])) {
        unset($_GET['mit_reset_view']);
        $mit_list_view_restored = 'reset';
        try {
            mitSetUserPreference($session_user_id, $preference, '');
        } catch (Throwable $e) {
            // database update pending - nothing was saved anyway
        }
        return false;
    }

    $view = mitListViewFromRequest($page);
    if ($view) {
        if (mitListViewFromSamePage($page)) {
            try {
                mitSetUserPreference($session_user_id, $preference, json_encode(array_filter($view, function ($v) { return $v !== '' && $v !== []; })));
            } catch (Throwable $e) {
                // database update pending - the page works, it just is not remembered
            }
        }
        return false;
    }

    $saved = json_decode((string) mitUserPreference($session_user_id, $preference, ''), true);
    if (!is_array($saved) || !$saved) {
        return false;
    }
    foreach (mitListViewKeys($page) as $key) {
        if (isset($saved[$key]) && (is_string($saved[$key]) || is_array($saved[$key]))) {
            $_GET[$key] = $saved[$key];
        }
    }
    $mit_list_view_restored = true;
    return true;
}

// The Reset view button; after a restore also puts the saved view in the address bar, so reload and Back keep it
// (and after a reset takes mit_reset_view off it)
function mitListViewResetButton($page) {
    global $mit_list_view_restored;

    if (isset($_GET['client_id'])) {
        return '';
    }
    $html = "<a href='?mit_reset_view=" . rawurlencode(mitListViewResetToken()) . "' class='btn btn-outline-secondary text-nowrap' title='Reset view - back to the default filters and sort'>"
        . "<i class='fas fa-fw fa-undo'></i><span class='d-none d-xl-inline ms-1'>Reset</span></a>"
        // While tickets are ticked, ITFlow's Bulk Action button joins this row: keep every button on one line (they used to squash
        // into two-line buttons and push over the filters), with Reset / List / Kanban as icons only (their titles still say what)
        . "<style>.d-flex:has(> #bulkActionButton:not([hidden])) { flex-wrap: nowrap; align-items: center; }"
        . ".d-flex:has(> #bulkActionButton:not([hidden])) > * { flex-shrink: 0; white-space: nowrap; }"
        . ".d-flex:has(> #bulkActionButton:not([hidden])) > :not(#bulkActionButton) .ms-1 { display: none !important; }"
        // ... and its column takes the width that needs, the filter beside it giving way
        . "@media (min-width: 992px) { .row > :has(> .d-flex > #bulkActionButton:not([hidden])) { flex: 0 0 auto; width: auto; margin-left: auto; }"
        . ".row > :has(+ * > .d-flex > #bulkActionButton:not([hidden])) { flex: 1 1 12rem; width: auto; min-width: 0; } }</style>";
    if ($mit_list_view_restored === 'reset') {
        $html .= "<script>history.replaceState(null, '', location.pathname);</script>";
    } elseif ($mit_list_view_restored) {
        $query = http_build_query(array_intersect_key($_GET, array_flip(array_merge(mitListViewKeys($page), ['open']))));
        $html .= "<script>history.replaceState(null, '', " . json_encode("?$query") . ");</script>";
    }
    return $html;
}

/**
 * The Reset link's own token: it goes in an address (logs, browser history), so it is not the session's CSRF token -
 * one made for this alone, kept in the session.
 */
function mitListViewResetToken() {
    if (empty($_SESSION['mit_reset_view_token'])) {
        $_SESSION['mit_reset_view_token'] = bin2hex(random_bytes(16));
    }
    return (string) $_SESSION['mit_reset_view_token'];
}
