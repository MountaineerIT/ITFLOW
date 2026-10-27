<?php
/*
 * Mountaineer IT - the feature map: every feature of ours, the files it is made of, the tests that prove it, and what it takes
 * for granted about ITFlow. scripts/mit_feature_map.php works out the rest from the code (the ITFlow functions, tables,
 * columns, hooks and page elements each feature uses) and writes MIT_FEATURES.md; scripts/mit_upstream_impact.php reads both
 * to say which features an ITFlow update touches. Every file of ours must belong to at least one feature (the map check fails
 * otherwise) - a new file is added here in the same change.
 *
 *   files     our files (globs, from the repo root)
 *   tests     smoke specs / scenarios that prove it (globs)
 *   assumes   what it relies on ITFlow doing - read these when the impact report names the feature
 *   watch     strings in ITFlow's code it depends on that the code scan can't see (ids, keys, wording) - the impact report
 *             flags any that an update removes
 *   claims    hook names in ITFlow's files that belong to it but no file of ours mentions ('name', or 'itflow/file.php:name' for
 *             one file only) - classes our CSS styles there, the MountaineerIT marker comments in an ITFlow script; most hooks are
 *             placed from the code without this
 *   overlap   words for "ITFlow now does this too" - new ITFlow tables, columns, functions or files with these in the name
 *             are flagged for an ours-or-theirs decision
 */
if (PHP_SAPI !== 'cli' && basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(404);
    exit;
}

return [
    'platform' => [
        'name' => 'Shared base (helpers, database updates, version, menus, module switches)',
        'files' => ['functions/mit_common.php', 'functions/mit_db_upgrade.php', 'admin/database_updates_mountaineerit.php',
            'admin/database_updates/mountaineerit/*.php', 'includes/mit_version.php', 'MIT_CHANGELOG.md', 'admin/includes/mit_changelog_card.php',
            'admin/includes/mit_admin_nav.php', 'admin/includes/mit_integrations_nav.php', 'admin/includes/mit_modules_fields.php',
            'agent/includes/mit_billing_nav.php'],
        'tests' => ['tests/smoke/01-pages.spec.js', 'tests/smoke/72-module-switches.spec.js', 'tests/smoke/30-checks.spec.js', 'tests/smoke/99-php-log.spec.js'],
        'assumes' => [
            'ITFlow runs admin/database_updates.php in version order and our runner after it (update_cli, setup, restores).',
            'functions.php requires ours; mit_common.php first.',
            'Module rows (module_*) and roleModuleInfo() are how permissions work; Admin > Settings > Modules form posts to settings_module.php.',
            'Our menus hang off ITFlow\'s side_nav.php sections (SETTINGS, INTEGRATIONS, BILLING, SUPPORT).',
        ],
        'watch' => ['FROM_DB_UPDATER', 'CURRENT_DATABASE_VERSION', 'roleModuleInfo', 'settingsSave'],
        'overlap' => ['module_'],
    ],
    'checks' => [
        'name' => 'Our checks (hooks, consistency, portability, AI wall, verify, feature map)',
        'files' => ['scripts/mit_*.php', 'scripts/mit_*.sh', 'scripts/mit_hooks.txt', 'functions/mit_consistency.php', 'cron/mit_consistency.php',
            'MIT_FEATURES.md', 'tests/smoke/*', 'tests/scenarios/*', 'tests/.gitignore'],
        'tests' => ['tests/smoke/30-checks.spec.js'],
        'assumes' => ['ITFlow\'s cron dispatcher (includes/cron_jobs.php) runs our jobs.'],
        'watch' => [],
        'overlap' => [],
    ],
    'dev_mode' => [
        'name' => 'Development mode (a production copy on a test server)',
        'files' => ['functions/mit_dev_mode.php'],
        'tests' => ['tests/smoke/17-dev-mode.spec.js', 'tests/scenarios/scenario-dev-mode-mail.php'],
        'assumes' => [
            'All mail goes through cron/mail_queue.php (PHPMailer) - the redirect sits just before the send.',
            'Stripe is set up in includes/stripe_init.php; custom actions go through triggerCustomAction; log forwarding through functions/log_forward.php.',
            'cron/ticket_email_parser.php and cron/ai_triage.php can be stopped with cronJobStop.',
        ],
        'watch' => ['cronJobStop', 'triggerCustomAction', 'logForwardSend'],
        'overlap' => ['dev_mode', 'sandbox', 'test_mode'],
    ],
    'backup_restore' => [
        'name' => 'Restore from Admin > Backup',
        'files' => ['functions/mit_backup_restore.php', 'admin/includes/mit_restore_card.php', 'admin/modals/mit_backup/*.php', 'admin/post/mit_backup_restore.php'],
        'tests' => ['tests/smoke/16-backup-restore.spec.js', 'tests/smoke/73-restore-roundtrip.spec.js'],
        'assumes' => [
            'ITFlow\'s backupInspectArchive / backupRestoreArchive / backupAssertUploadsGuards and the backups table.',
            'load_global_settings.php fixes CURRENT_DATABASE_VERSION for the request (so a restore walks the updates itself).',
        ],
        'watch' => ['backupRestoreArchive', 'backupInspectArchive', 'backupAssertUploadsGuards'],
        'overlap' => ['backupRestore', 'restore_archive', 'restoreArchive', 'steps/restore', 'restore_upload'],
    ],
    'cron_health' => [
        'name' => 'Cron health (warning, emails, /mit_health.php)',
        'files' => ['functions/mit_cron_health.php', 'includes/mit_cron_nav.php', 'admin/includes/mit_cron_card.php', 'admin/post/mit_cron_health.php',
            'cron/mit_cron_watch.php', 'mit_health.php'],
        'tests' => ['tests/scenarios/scenario-cron-watch.php'],
        'assumes' => ['ITFlow\'s cron_jobs table (status, last run, interval) and settings.config_cron_last_dispatch_at.'],
        'watch' => ['config_cron_last_dispatch_at'],
        'overlap' => ['cron_alert', 'health', 'heartbeat', 'cron_fail', 'cronWatch'],
    ],
    'theme' => [
        'name' => 'Modern look, logo options, sign-in screen, client tiles, time fields, portal tables',
        'files' => ['functions/mit_theme.php', 'css/mit_*.css', 'css/mit_login_nodes.svg', 'css/fonts/inter/*', 'js/mit_theme.js', 'js/mit_time_inputs.js',
            'js/mit_portal_tables.js', 'functions/mit_client_tiles.php'],
        'tests' => ['tests/smoke/21-brand-logo.spec.js', 'tests/smoke/26-portal-phone.spec.js', 'tests/smoke/46-client-tiles.spec.js'],
        'assumes' => [
            'ITFlow\'s markup classes: .app-header, .app-sidebar .nav-link.active, .card-header.bg-dark, .itflow-dash-rail*, .itflow-client-switcher.',
            'Company theme colours as body.theme-<colour> (admin/settings_theme.php); statuses coloured through --itflow-status-bg.',
            'login.php / client pages send CSP default-src \'self\' - our files there must be files, not inline.',
        ],
        'watch' => ['app-header', 'app-sidebar', 'itflow-dash-rail', 'itflow-client-switcher', '--itflow-accent', '--itflow-status-bg', 'login-box'],
        'overlap' => ['theme', 'dark_mode', 'logo'],
    ],
    'emails' => [
        'name' => 'Email look: logo inside the message, resolved wording, notification switches',
        'files' => ['functions/mit_notifications.php', 'agent/js/mit_schedule_notify.js'],
        'tests' => ['tests/scenarios/scenario-mail-logo.php', 'tests/smoke/25-notifications.spec.js', 'tests/smoke/47-assign-switches.spec.js'],
        'assumes' => [
            'Every email is built by ITFlow\'s emailHtml / emailQueue / ticketMailSend and sent by cron/mail_queue.php.',
            'emailHtml links the logo as https://<base url>/uploads/settings/<file> (mitMailInlineImages embeds it).',
            'Each recipient is greeted by emailQueue ("Hello <name>,") - our wording must not greet again.',
            'ticketMailSchedule / ticketMailAssigned / recurringTicketMailAssigned are where schedule and assignment mail is built.',
        ],
        'watch' => ['/uploads/settings/', 'addToMailQueue', 'ticketMailSchedule', 'ticketMailAssigned', 'ticketEmailContent'],
        'overlap' => ['notify_schedule', 'schedule_notify', 'inline_image', 'email_logo', 'resolved_message'],
    ],
    'calendar' => [
        'name' => 'Calendar (agent colours, booking ends, conflicts, Left behind, 7 days, weekday repeats, event agents, holidays)',
        'files' => ['functions/mit_calendar.php', 'functions/mit_left_behind.php', 'functions/mit_recurring.php', 'functions/mit_event_agents.php',
            'agent/js/mit_left_behind.js', 'js/mit_repeat_on.js', 'admin/includes/mit_calendar_card.php', 'admin/post/mit_calendar_settings.php',
            'agent/post/mit_event_agents.php'],
        'tests' => ['tests/smoke/08-calendar.spec.js', 'tests/smoke/20-event-agents.spec.js', 'tests/smoke/50-sched-window.spec.js',
            'tests/smoke/52-calendar-state-moves.spec.js', 'tests/smoke/53-event-repeat-weekday.spec.js', 'tests/scenarios/scenario-sched-*.php'],
        'assumes' => [
            'Bookings are tickets.ticket_schedule (+ our ticket_schedule_end); ticketSetSchedule / ticketCancelSchedule change them.',
            'functions/calendar.php builds the sources (calendarSources / calendarSourceItems / calendarEventItems); agent/js/calendar.js draws them with FullCalendar v7.',
            'Attendee agents (calendar_event_attendees.attendee_user_id) are an entry\'s assignment.',
            'Holidays are business_holidays (Admin > SLA closure days).',
        ],
        'watch' => ['ticket_schedule', 'calendarSourceItems', 'calendarEventItems', 'eventContent', 'eventDidMount', 'headerToolbar', 'business_holidays'],
        'claims' => ['mit_calendar_state', 'mit-cal-agent', 'mit-cal-when', 'mit-tech-dot', 'mitStriped', 'agent/js/calendar.js:MountaineerIT'],
        'overlap' => ['schedule_end', 'calendar_color', 'agent_color', 'by_day', 'byday'],
    ],
    'outlook_m365' => [
        'name' => 'Microsoft 365 app, Graph mail, Outlook calendar per tech',
        'files' => ['functions/mit_m365.php', 'functions/mit_m365_mailbox.php', 'functions/mit_outlook.php', 'admin/includes/mit_m365_card.php',
            'admin/post/mit_m365.php', 'agent/user/mit_outlook*.php', 'agent/user/post/mit_outlook.php', 'cron/mit_outlook_sync.php'],
        'tests' => ['tests/scenarios/scenario-sched-outlook.php', 'tests/scenarios/scenario-dev-mode-mail.php'],
        'assumes' => [
            'Mail OAuth settings (config_mail_oauth_*) and the token flow in functions/request.php httpFormPost / admin/post/settings_mail.php.',
            'cron/mail_queue.php sends one PHPMailer message per email; cron/ticket_email_parser.php reads mail through ImapEngine\'s Mailbox.',
            'Ticket invites are iCal with UID ticket-<id>@ (createiCalStr).',
        ],
        'watch' => ['httpFormPost', 'XOAUTH2', 'new Mailbox(', 'createiCalStr', 'config_mail_oauth_client_secret'],
        'overlap' => ['graph', 'outlook', 'm365', 'microsoft'],
    ],
    'ticket_techs' => [
        'name' => 'Additional techs (helpers) on tickets',
        'files' => ['functions/mit_ticket_techs.php', 'agent/modals/ticket/mit_ticket_tech.php', 'agent/post/mit_ticket_techs.php'],
        'tests' => ['tests/smoke/42-helpers.spec.js', 'tests/scenarios/scenario-helpers.php'],
        'assumes' => [
            'tickets.ticket_assigned_to is the one lead; ticketChangeAssignee changes it (four paths, none hooked - mitTicketTechsTidy on read).',
            'Assignee reply copies hang off ticketMailAssigneeReplied (parser, client/post.php).',
        ],
        'watch' => ['ticket_assigned_to', 'ticketMailAssigneeReplied', 'ticketChangeAssignee'],
        'overlap' => ['collaborator', 'additional_tech', 'helper', 'ticket_users', 'ticket_assignees'],
    ],
    'dispatch' => [
        'name' => 'Dispatch board, Workload, This week',
        'files' => ['functions/mit_dispatch*.php', 'agent/mit_dispatch*.php', 'agent/js/mit_dispatch.js', 'agent/includes/mit_dispatch_nav.php'],
        'tests' => ['tests/smoke/14-dispatch-role.spec.js', 'tests/smoke/51-dispatch-flow.spec.js', 'tests/scenarios/scenario-sched-workload.php'],
        'assumes' => [
            'Booking = ticketChangeAssignee + ticketSetSchedule; the Schedule dialog repeats Edit ticket / Change client / SLA rules (change_client_ticket, ticketMergeInto in agent/post/ticket.php).',
            'ticketSlaState for SLA counts; Admin > SLA working hours.',
        ],
        'watch' => ['ticketMergeInto', 'ticketSlaState', 'change_client_ticket'],
        'overlap' => ['dispatch', 'workload'],
    ],
    'boards' => [
        'name' => 'Service boards (routing, escalation, round-robin, by role, pickers, report)',
        'files' => ['functions/mit_boards.php', 'functions/mit_board_roles.php', 'functions/mit_ticket_picks.php', 'admin/mit_boards.php',
            'admin/modals/mit_boards/*.php', 'admin/post/mit_boards.php', 'agent/modals/mit_board/*.php', 'agent/post/mit_boards.php',
            'agent/reports/mit_boards.php', 'cron/mit_boards.php'],
        'tests' => ['tests/smoke/07-boards.spec.js', 'tests/smoke/13-board-roles.spec.js', 'tests/smoke/15-ticket-picks.spec.js', 'tests/smoke/48-boards-ui.spec.js',
            'tests/scenarios/scenario-boards.php', 'tests/scenarios/scenario-parser-boards.php', 'tests/scenarios/scenario-picks-project.php'],
        'assumes' => [
            'New tickets come from add_ticket, the parser\'s addTicket, the portal, the API, recurring and projects (a sweep catches the rest).',
            'quickPick() / js/quick_pick.js markup and quick_pick_options / quick_pick_set in agent/ajax.php.',
            'Ticket lists are agent/tickets.php + ticket_list.php / ticket_kanban.php; dashboard counts in functions/dashboard.php.',
        ],
        'watch' => ['quick_pick_options', 'quick_pick_set', 'applyTicketSla', 'dashboardAttention'],
        'overlap' => ['board', 'tier', 'escalat', 'round_robin', 'auto_assign'],
    ],
    'projects' => [
        'name' => 'Project tickets kept apart',
        'files' => ['functions/mit_projects.php', 'admin/includes/mit_project_card.php', 'admin/post/mit_project_settings.php', 'cron/mit_project_tickets.php'],
        'tests' => ['tests/smoke/44-project-tickets.spec.js', 'tests/scenarios/scenario-picks-project.php'],
        'assumes' => ['tickets.ticket_project_id; projectAddTicket / projectRemoveTicket; applyTicketSla.'],
        'watch' => ['ticket_project_id', 'projectAddTicket', 'projectRemoveTicket'],
        'overlap' => ['project_ticket'],
    ],
    'ticket_page' => [
        'name' => 'Ticket page: layout, Read tick, timers, list views, Resolve - no response',
        'files' => ['functions/mit_ticket_layout.php', 'agent/js/mit_ticket_layout.js', 'includes/mit_timers_nav.php', 'js/mit_timers.js',
            'functions/mit_list_views.php', 'functions/mit_no_response.php', 'agent/modals/mit_no_response/*.php', 'agent/post/mit_no_response.php',
            'admin/includes/mit_no_response_card.php', 'admin/post/mit_no_response_settings.php'],
        'tests' => ['tests/smoke/43-layout-timers.spec.js', 'tests/smoke/23-bulk-toolbar.spec.js', 'tests/smoke/24-no-response.spec.js',
            'tests/scenarios/scenario-no-response.php'],
        'assumes' => [
            'The ticket page\'s boxes are found by their title icons (fa-align-left, fa-sliders-h, fa-user, fa-layer-group, fa-map-marker-alt, fa-tasks, fa-link, fa-history).',
            'Reply form ids: #replyComposer, public_reply_type_opt1/2, #replyAudience, #replySubmitLabel, #ticket_add_reply.',
            'Timer keys in localStorage: <id>-startTime / <id>-pausedTime / ticket-timer-running-<id> (agent/js/ticket_time_tracking.js).',
            'ticketResolve sends no mail itself; ticketMailResolved builds the resolved email.',
        ],
        'watch' => ['replyComposer', 'public_reply_type_opt1', 'replyAudience', 'replySubmitLabel', 'ticket_add_reply', 'return ticketID + "-" + suffix', 'getLocalStorageKey("startTime")', 'getLocalStorageKey("pausedTime")',
            'ticket-timer-running-', 'fa-sliders-h', 'fa-layer-group', 'fa-map-marker-alt', 'ticket-actions', 'bulkActionButton', 'itflow-ticket-trips'],
        'overlap' => ['ticket_layout', 'no_response', 'ticket_timer', 'time_tracking', 'list_view', 'saved_view'],
    ],
    'reply_recipients' => [
        'name' => 'Email reply "To" line, internal-note Notify',
        'files' => ['functions/mit_reply_recipients.php', 'agent/js/mit_reply_recipients.js'],
        'tests' => ['tests/smoke/40-reply-recipients.spec.js'],
        'assumes' => [
            'add_ticket_reply emails through ticketMailReply; ours replaces it when mit_send_to_present is posted and records ticketReplyEmailed.',
            'js/itflow_select.js is the picker (multi-select, data-tags); itflowDismissPopups closes pickers on an editor click.',
            'The parser files mail from an agent\'s own address in addReply.',
        ],
        'watch' => ['ticketMailReply', 'ticketReplyEmailed', 'ticketColleagueRecipients', 'itflowDismissPopups', 'data-tags'],
        'overlap' => ['reply_recipient', 'ticket_recipient', 'collaborator', 'ticket_cc', 'reply_to', 'internal_notify'],
    ],
    'reply_times' => [
        'name' => 'Times on ticket notes (start / end)',
        'files' => ['functions/mit_reply_times.php', 'agent/js/mit_reply_times.js'],
        'tests' => ['tests/smoke/09-note-times.spec.js', 'tests/smoke/45-note-time-locks.spec.js'],
        'assumes' => [
            'ticket_replies.ticket_reply_time_worked holds the length; the timer fields are #hours / #minutes / #seconds.',
            'flashAlert keeps one message (mitReplyTimesFlash adds the time warning back).',
        ],
        'watch' => ['ticket_reply_time_worked', '#hours', '#minutes', '#seconds', 'flashAlert'],
        'overlap' => ['work_start', 'work_end', 'time_start', 'started_at'],
    ],
    'quotes' => [
        'name' => 'Quote pricing (cost, markup / margin) and line images',
        'files' => ['functions/mit_quotes.php', 'js/mit_quote_pricing.js', 'admin/includes/mit_quote_card.php', 'admin/post/mit_quote_settings.php'],
        'tests' => ['tests/smoke/60-quote-pricing.spec.js', 'tests/scenarios/scenario-quote-*.php'],
        'assumes' => [
            'Quotes / invoices / recurring are one page: lines saved by agent/post/billing.php (billing_item_save ...), drawn by includes/billing_paper.php.',
            'products.product_cost / product_vendor_id are ITFlow\'s (0 = none); quote_items keep our item_cost / price method / image.',
            'PDFs by functions/billing_pdf.php.',
        ],
        'watch' => ['billing_item_save', 'billingAddLine', 'billing_paper', 'product_cost'],
        'overlap' => ['margin', 'markup', 'item_cost', 'product_cost', 'item_image', 'product_image', 'price_method'],
    ],
    'procurement' => [
        'name' => 'Procurement (to order, POs, receiving, ticket parts, unbilled parts, vendor bills)',
        'files' => ['functions/mit_procurement*.php', 'agent/mit_procurement.php', 'agent/mit_purchase_order*.php', 'agent/mit_receiving.php',
            'agent/mit_unbilled_parts.php', 'agent/modals/mit_procurement/*.php', 'agent/post/mit_procurement*.php', 'agent/includes/mit_procurement_nav.php',
            'admin/mit_procurement_settings.php', 'admin/includes/mit_procurement_card.php', 'admin/post/mit_procurement_settings.php',
            'cron/mit_procurement_tidy.php'],
        'tests' => ['tests/smoke/62-procurement-roles.spec.js', 'tests/smoke/63-parts-and-invoice-orders.spec.js', 'tests/scenarios/scenario-procurement-*.php'],
        'assumes' => [
            'Stock moves through ITFlow\'s productStockMove / product_stock (productStockShortfall, productStockReturnInvoice).',
            'Quotes become invoices by quoteConvert (quoteLineGroups); invoices by billingInvoiceCreate / billingRecalc / billingLineTax.',
            'Expenses by expenseSave; assets carry asset_invoice_id / _item_id / _product_id.',
        ],
        'watch' => ['productStockMove', 'quoteConvert', 'quoteLineGroups', 'billingInvoiceCreate', 'expenseSave', 'productStockShortfall'],
        'overlap' => ['purchase_order', 'purchase', 'procure', 'receiv', 'po_', 'stock', 'vendor', 'reorder', 'unbilled'],
    ],
    'timesheets' => [
        'name' => 'Timesheets, payroll, leave, California rules, holidays, reimbursements',
        'files' => ['functions/mit_timesheet*.php', 'functions/mit_ts_*.php', 'functions/mit_leave.php', 'functions/mit_reimbursements.php',
            'agent/mit_timesheet*.php', 'agent/js/mit_timesheet.js', 'agent/modals/mit_timesheet/*.php', 'agent/modals/mit_reimburse/*.php',
            'agent/post/mit_timesheets.php', 'agent/post/mit_reimbursements.php', 'agent/mit_receipt.php', 'agent/reports/mit_payroll.php',
            'agent/includes/mit_timesheet_nav.php', 'admin/mit_timesheets.php', 'admin/modals/mit_timesheets/*.php', 'admin/post/mit_timesheets.php',
            'cron/mit_timesheets.php'],
        'tests' => ['tests/smoke/04-permissions.spec.js', 'tests/smoke/05-california.spec.js', 'tests/smoke/19-own-employment.spec.js',
            'tests/smoke/29-payroll-payback.spec.js', 'tests/smoke/31-rest-breaks.spec.js', 'tests/smoke/7[0-9]-*.spec.js', 'tests/scenarios/scenario-ts-*.php',
            'tests/scenarios/fixture-hourly.php'],
        'assumes' => [
            'Ticket time = ticket_replies.ticket_reply_time_worked by ticket_reply_by; billable by tickets.ticket_billable.',
            'Mileage is ITFlow trips (trip_own_vehicle, tripClaim, config_mileage_rate); purchases are ITFlow expenses (expense_paid_by, expenseReimburse, reimbursementDelete).',
            'Closure days are business_holidays (getBusinessHolidays, restampOpenSlaTickets).',
            'change_client_ticket turns public replies internal (mitTsKeepPublicNotes runs first).',
        ],
        'watch' => ['ticket_reply_time_worked', 'trip_own_vehicle', 'tripClaim', 'expenseReimburse', 'reimbursementDelete', 'expense_paid_by',
            'config_mileage_rate', 'getBusinessHolidays'],
        'overlap' => ['timesheet', 'payroll', 'leave', 'pto', 'overtime', 'time_entr', 'clock', 'reimburse', 'mileage', 'trip', 'holiday', 'pay_period'],
    ],
    'qbo' => [
        'name' => 'QuickBooks Online (approvals, batches, locks, payments back)',
        'files' => ['functions/mit_qbo*.php', 'admin/mit_qbo*.php', 'admin/post/mit_qbo.php', 'agent/mit_invoice_approvals.php', 'agent/mit_qbo_batch*.php',
            'agent/post/mit_qbo.php', 'cron/mit_qbo_sync.php'],
        'tests' => ['tests/smoke/10-qbo.spec.js', 'tests/smoke/61-qbo-guards.spec.js', 'tests/scenarios/scenario-qbo-*.php'],
        'assumes' => [
            'Every way an invoice or its payments change goes through a guarded handler (agent/post/invoice.php, billing.php, payment.php, ajax.php, API, nightly, portal, guest pay, bank import, reprice).',
            'Payments: payments table + updateInvoiceStatusFromPayments; Stripe booking in billingStripeBook.',
            'Recurring invoices by recurringInvoiceGenerate (auto-send condition).',
        ],
        'watch' => ['updateInvoiceStatusFromPayments', 'billingStripeBook', 'recurringInvoiceGenerate', 'productRepriceApply', 'income_import'],
        'overlap' => ['quickbooks', 'qbo', 'xero', 'accounting_sync', 'intuit'],
    ],
    'usage' => [
        'name' => 'Usage billing (counts on recurring lines, connectors, API)',
        'files' => ['functions/mit_usage*.php', 'agent/mit_usage_review.php', 'agent/post/mit_usage.php', 'admin/includes/mit_usage_card.php',
            'admin/post/mit_usage_*.php', 'admin/mit_usage_connectors.php', 'api/v1/mit_usage/*.php', 'cron/mit_usage.php'],
        'tests' => ['tests/smoke/11-usage.spec.js', 'tests/smoke/12-connectors.spec.js', 'tests/scenarios/scenario-usage.php'],
        'assumes' => [
            'recurringInvoiceGenerate copies recurring lines in item_order; usage sets their quantity before auto-send.',
            'api/v1/enforce_api_rbac.php maps endpoints to modules.',
        ],
        'watch' => ['item_order', 'item_seat_source', 'enforce_api_rbac'],
        'overlap' => ['usage_line', 'usage_count', 'seat', 'metered', 'quantity_source'],
    ],
    'ninjaone' => [
        'name' => 'NinjaOne RMM sync and live status',
        'files' => ['functions/mit_ninjaone.php', 'admin/mit_ninjaone.php', 'admin/post/mit_ninjaone.php', 'agent/js/mit_ninjaone.js',
            'agent/mit_ninjaone_ajax.php', 'cron/mit_ninjaone_sync.php'],
        'tests' => ['tests/smoke/22-ninjaone-sync.spec.js'],
        'assumes' => ['Assets table and its columns (serial, make, model, os, ip ...); asset links look like modals/asset/asset.php?...id='],
        'watch' => ['modals/asset/asset.php'],
        'overlap' => ['ninja', 'rmm', 'device_sync'],
    ],
    'customer_thermometer' => [
        'name' => 'Customer Thermometer surveys',
        'files' => ['functions/mit_ct.php', 'admin/mit_customer_thermometer.php', 'admin/post/mit_customer_thermometer.php', 'agent/mit_ct_reviews.php',
            'cron/mit_customer_thermometer.php'],
        'tests' => ['tests/smoke/18-customer-thermometer.spec.js'],
        'assumes' => ['Resolved / closed tickets by ticket_resolved_at / ticket_closed_at; ticketMergeInto writes "merged this ticket into" history.'],
        'watch' => ['merged this ticket into'],
        'overlap' => ['survey', 'feedback', 'csat', 'satisfaction', 'rating'],
    ],
    'align' => [
        'name' => 'MSP Align (read only)',
        'files' => ['functions/mit_align.php', 'admin/mit_align.php', 'admin/post/mit_align.php', 'cron/mit_align_sync.php'],
        'tests' => ['tests/smoke/27-align.spec.js'],
        'assumes' => ['Client overview\'s Needs attention block and the dashboard\'s $attention items.'],
        'watch' => ['Needs attention'],
        'overlap' => ['align', 'vcio', 'lifecycle'],
    ],
    'ai_helper' => [
        'name' => 'AI helper (library, Ask panel, write-up, local embeddings) - behind the password wall',
        'files' => ['functions/mit_ai_*.php', 'admin/mit_ai_helper.php', 'admin/post/mit_ai_helper.php', 'agent/mit_ai_ajax.php', 'agent/js/mit_ai_helper.js',
            'agent/modals/mit_ai/*.php', 'cron/mit_ai_index.php'],
        'tests' => ['tests/smoke/3[2-5]-ai-*.spec.js', 'tests/scenarios/scenario-ai-*.php'],
        'assumes' => [
            'ITFlow\'s AI providers (ai_providers, aiProviderRow, aiProviderHeaders, callAiApi, getAiModel, aiCan, aiReady, aiRedact, aiCleanHtml, aiDocumentSplit).',
            'Documents: add_document handler and modals/document/document_form.php; archived = *_archived_at.',
        ],
        'watch' => ['callAiApi', 'aiRedact', 'getAiModel', 'aiCleanHtml', 'aiDocumentSplit', 'document_form.php'],
        'overlap' => ['embedding', 'knowledge', 'assistant', 'copilot', 'ai_search', 'ai_chat', 'ai_ask', 'ai_answer'],
    ],
];
