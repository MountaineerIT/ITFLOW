<?php

/*
 * Mountaineer IT - our settings on ITFlow's Admin > Settings (26.10.189). ITFlow lists its settings pages in settingsPages() and every
 * setting in settingsFields() (functions/settings.php) - the Settings menu, the overview's tiles and Find a setting (and the top bar's
 * search) all come from those. Ours join them:
 *  - mitSettingsPages(): Procurement, Timesheets and Boards (our own pages) as tiles in "Work" and entries in the Settings menu - one
 *    `+` at the end of settingsPages(); a page whose module of ours is off is left out.
 *  - mitSettingsSummaries(): their "how it is set now" line on the overview - one line after $summaries in admin/settings.php.
 *  - mitSettingsFields(): the settings on our cards inside ITFlow's pages (Tickets, Quotes, Invoices, Projects, Company Details,
 *    Notifications, Modules) for Find a setting - one line before settingsFields()'s return. A result opens the page at the field
 *    (js/settings.js finds it by its name).
 */

/** Our settings pages, in settingsPages()'s shape (key => group, title, icon, url, module, about). */
function mitSettingsPages() {
    $pages = [];
    if (function_exists('mitModuleOn') && mitModuleOn('procurement')) {
        $pages['mit_procurement'] = ['group' => 'work', 'title' => 'Procurement', 'icon' => 'fa-file-signature', 'url' => 'mit_procurement_settings.php', 'module' => 'accounting',
            'about' => 'Purchase order numbers, who approves them and over what amount, and where orders ship to.'];
    }
    if (function_exists('mitModuleOn') && mitModuleOn('timesheets')) {
        $pages['mit_timesheets'] = ['group' => 'work', 'title' => 'Timesheets', 'icon' => 'fa-business-time', 'url' => 'mit_timesheets.php',
            'about' => 'Pay periods, approval, overtime, charge codes, leave, holidays, California rules, mileage and purchases paid back through payroll.'];
    }
    if (function_exists('mitBoardsReady')) {
        $pages['mit_boards'] = ['group' => 'work', 'title' => 'Boards', 'icon' => 'fa-columns', 'url' => 'mit_boards.php', 'module' => 'ticketing',
            'about' => 'Service boards (Service Desk, Tier 1, Tier 2 ...), who is on them, routing rules and round-robin auto-assign.'];
    }
    return $pages;
}

/** The overview's "how it is set now" line for each of our pages (HTML, escaped here). */
function mitSettingsSummaries() {
    $on_off = function ($on) {
        return $on ? 'on' : 'off';
    };
    $summaries = [];
    foreach (array_keys(mitSettingsPages()) as $key) {
        try {
            if ($key === 'mit_procurement') {
                $proc = mitProcSettings();
                $summaries[$key] = 'Next ' . escapeHtml($proc['prefix'] . $proc['next']) . ' · approval '
                    . ($proc['approval_on'] ? ($proc['approval_over'] > 0 ? 'over ' . escapeHtml(number_format($proc['approval_over'], 2)) : 'on every PO') : 'off');
            } elseif ($key === 'mit_timesheets') {
                $period = (string) mitSetting('timesheet_period', 'semi_monthly');
                $summaries[$key] = escapeHtml(strtok(MIT_TS_PERIODS[$period] ?? $period, ' (')) . ' periods · approval ' . $on_off(mitTsSettings()['approval']);
            } elseif ($key === 'mit_boards') {
                $boards = mitBoards();
                $summaries[$key] = count($boards) . ' board' . (count($boards) === 1 ? '' : 's');
            }
        } catch (Throwable $e) {
            $summaries[$key] = 'Database update needed';
        }
    }
    return $summaries;
}

/** The settings on our cards inside ITFlow's pages, in settingsFields()'s shape - for Find a setting. */
function mitSettingsFields() {
    $fields = [
        'mit_offices' => ['page' => 'company', 'section' => 'Offices', 'label' => 'Offices', 'words' => 'office branch location other offices address phone who works where'],
        'mit_app_logo' => ['page' => 'company', 'section' => 'Logo on ITFlow\'s screens', 'label' => 'Logo for the dark side menu and sign-in page', 'words' => 'logo dark menu sidebar sign in login white'],
        'mit_brand_logo_only' => ['page' => 'company', 'section' => 'In the side menu', 'label' => 'The logo shows the company name', 'words' => 'logo name side menu sidebar brand'],
        'mit_brand_logo_plain' => ['page' => 'company', 'section' => 'In the side menu', 'label' => 'No light tile behind the logo', 'words' => 'logo tile background side menu'],
        'mit_service_ticket_color' => ['page' => 'ticket', 'section' => 'Calendar', 'label' => 'Colour of booked service tickets', 'words' => 'calendar colour color booking scheduled'],
        'mit_no_response_message' => ['page' => 'ticket', 'section' => 'Messages when resolving', 'label' => 'Resolve - no response message', 'words' => 'no response resolve message client did not reply'],
        'mit_resolved_message' => ['page' => 'ticket', 'section' => 'Messages when resolving', 'label' => '"Ticket resolved" email wording', 'words' => 'resolved email wording message'],
        'mit_quote_price_method' => ['page' => 'quote', 'section' => 'Quote pricing', 'label' => 'Price from cost - markup, margin or set price', 'words' => 'markup margin cost price profit'],
        'mit_quote_show_images' => ['page' => 'quote', 'section' => 'Quote pricing', 'label' => 'Images on quote lines', 'words' => 'image picture photo quote line'],
        'mit_usage_snapshot_day' => ['page' => 'invoice', 'section' => 'Usage billing', 'label' => 'Usage counts - day of the month', 'words' => 'usage count seats devices users snapshot recurring'],
        'mit_project_tickets_apart' => ['page' => 'project', 'section' => 'Project tickets', 'label' => 'Keep project tickets off the service queue', 'words' => 'project tickets queue sla board'],
        'mit_project_ticket_color' => ['page' => 'project', 'section' => 'Project tickets', 'label' => 'Colour of project bookings', 'words' => 'project calendar colour color'],
        'mit_schedule' => ['page' => 'notification', 'section' => 'Ticket scheduled, moved or cancelled', 'label' => 'Who is emailed when a ticket is scheduled', 'words' => 'schedule booking calendar invite onsite remote email contact tech'],
        'mit_module_procurement' => ['page' => 'module', 'section' => 'Modules', 'label' => 'Procurement', 'words' => 'procurement purchase orders module'],
        'mit_module_timesheets' => ['page' => 'module', 'section' => 'Modules', 'label' => 'Timesheets', 'words' => 'timesheets payroll hours module'],
        'mit_module_dispatch' => ['page' => 'module', 'section' => 'Modules', 'label' => 'Dispatch', 'words' => 'dispatch board schedule techs module'],
    ];
    if (function_exists('mitNotificationSwitches')) {
        // One per notice (its bell and email switches share a field, named after its first key - as mitNotificationSections draws it)
        $seen = [];
        foreach (mitNotificationSwitches() as $key => [$section, $label, $help, $column]) {
            if (!isset($seen[$label])) {
                $seen[$label] = true;
                $fields["mit_notify_$key"] = ['page' => 'notification', 'section' => $section, 'label' => $label, 'words' => strtolower("$section notification email bell")];
            }
        }
    }
    return $fields;
}
