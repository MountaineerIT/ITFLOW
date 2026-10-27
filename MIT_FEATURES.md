# Mountaineer IT - feature map

Written by `php scripts/mit_feature_map.php` from `scripts/mit_features.php` and the code - don't edit by hand.
Each feature of ours, the tests that prove it, what it takes for granted about ITFlow, and what it uses of ITFlow's code
(functions called, tables and columns named, ITFlow files that carry its hooks, page ids / ITFlow classes its scripts look for).
`php scripts/mit_upstream_impact.php` reads this to say which features an ITFlow update touches.

| Feature | Our files | Tests | ITFlow functions | Tables | Columns | ITFlow files hooked |
|---|---|---|---|---|---|---|
| [Shared base (helpers, database updates, version, menus, module switches)](#platform) | 56 | 10 | 7 | 27 | 67 | 27 |
| [Our checks (hooks, consistency, portability, AI wall, verify, feature map)](#checks) | 152 | 2 | 2 | 15 | 40 | 4 |
| [Development mode (a production copy on a test server)](#dev_mode) | 1 | 2 | 1 | 1 | 0 | 11 |
| [Restore from Admin > Backup](#backup_restore) | 4 | 2 | 21 | 7 | 13 | 7 |
| [Cron health (warning, emails, /mit_health.php)](#cron_health) | 6 | 2 | 9 | 4 | 20 | 7 |
| [Modern look, logo options, sign-in screen, client tiles, time fields, portal tables](#theme) | 12 | 4 | 8 | 12 | 23 | 15 |
| [Email look: logo inside the message, resolved wording, notification switches](#emails) | 2 | 4 | 6 | 6 | 18 | 7 |
| [Calendar (agent colours, booking ends, conflicts, Left behind, 7 days, weekday repeats, event agents, holidays)](#calendar) | 9 | 16 | 28 | 10 | 40 | 26 |
| [Microsoft 365 app, Graph mail, Outlook calendar per tech](#outlook_m365) | 9 | 6 | 11 | 9 | 41 | 15 |
| [Additional techs (helpers) on tickets](#ticket_techs) | 3 | 4 | 31 | 13 | 63 | 16 |
| [Dispatch board, Workload, This week](#dispatch) | 6 | 3 | 40 | 16 | 65 | 10 |
| [Service boards (routing, escalation, round-robin, by role, pickers, report)](#boards) | 10 | 8 | 50 | 11 | 51 | 23 |
| [Project tickets kept apart](#projects) | 4 | 2 | 10 | 3 | 9 | 10 |
| [Ticket page: layout, Read tick, timers, list views, Resolve - no response](#ticket_page) | 10 | 5 | 21 | 10 | 18 | 9 |
| [Email reply "To" line, internal-note Notify](#reply_recipients) | 2 | 1 | 12 | 8 | 29 | 7 |
| [Times on ticket notes (start / end)](#reply_times) | 2 | 3 | 8 | 4 | 18 | 6 |
| [Quote pricing (cost, markup / margin) and line images](#quotes) | 4 | 4 | 8 | 5 | 13 | 14 |
| [Procurement (to order, POs, receiving, ticket parts, unbilled parts, vendor bills)](#procurement) | 20 | 5 | 27 | 25 | 147 | 13 |
| [Timesheets, payroll, leave, California rules, holidays, reimbursements](#timesheets) | 27 | 24 | 56 | 20 | 89 | 17 |
| [QuickBooks Online (approvals, batches, locks, payments back)](#qbo) | 10 | 6 | 15 | 12 | 64 | 24 |
| [Usage billing (counts on recurring lines, connectors, API)](#usage) | 11 | 3 | 15 | 13 | 48 | 7 |
| [NinjaOne RMM sync and live status](#ninjaone) | 6 | 1 | 11 | 8 | 26 | 4 |
| [Customer Thermometer surveys](#customer_thermometer) | 5 | 1 | 15 | 7 | 24 | 4 |
| [MSP Align (read only)](#align) | 4 | 1 | 10 | 7 | 11 | 9 |
| [AI helper (library, Ask panel, write-up, local embeddings) - behind the password wall](#ai_helper) | 10 | 11 | 24 | 11 | 43 | 3 |

<a id="platform"></a>
## Shared base (helpers, database updates, version, menus, module switches) (`platform`)

**Takes for granted**

- ITFlow runs admin/database_updates.php in version order and our runner after it (update_cli, setup, restores).
- functions.php requires ours; mit_common.php first.
- Module rows (module_*) and roleModuleInfo() are how permissions work; Admin > Settings > Modules form posts to settings_module.php.
- Our menus hang off ITFlow's side_nav.php sections (SETTINGS, INTEGRATIONS, BILLING, SUPPORT).

**Tests** `tests/smoke/01-pages.spec.js`, `tests/smoke/72-module-switches.spec.js`, `tests/smoke/30-checks.spec.js`, `tests/smoke/77-sign-in-security.spec.js`, `tests/smoke/78-portal-sessions.spec.js`, `tests/smoke/79-platform-pages.spec.js`, `tests/scenarios/scenario-mail-logo.php`, `tests/scenarios/scenario-pager-many-pages.php`, `tests/smoke/83-contracts.spec.js`, `tests/smoke/99-php-log.spec.js`

**Hooks in ITFlow's files**

- `.htaccess`: MIT_CHANGELOG
- `admin/database_updates.php`: mitBeforeItflowUpdate, mit_db_upgrade
- `admin/includes/side_nav.php`: mitAdminSettingsPages, mit_admin_nav, mit_integrations_nav
- `admin/post/settings_module.php`: mitModulesSave, mit_lines
- `admin/settings_module.php`: mit_modules_fields
- `admin/update.php`: MIT_CHANGELOG, mitVersion, mit_changelog_card, MountaineerIT
- `agent/calendar.php`: mitAsset
- `agent/includes/side_nav.php`: mit_billing_nav
- `agent/post/payment.php`: mitLock, mitUnlock
- `agent/user/post/profile.php`: mitSessionRestamp
- `api/v1/enforce_api_rbac.php`: mit_usage
- `client/includes/check_login.php`: mitSessionCheck
- `client/login_microsoft.php`: mitPortalEntraTenant, mitPortalEntraTenantOk
- `client/post.php`: mitSessionRestamp
- `cron/mail_queue.php`: mitMailInlineImages, mit_common, mit_dev_mode
- `functions.php`: mit_ai_helper, mit_align, mit_boards, mit_common, mit_ninjaone, mit_qbo, mit_timesheets, mit_usage
- `functions/reports.php`: mit_boards, mit_timesheets
- `functions/roles.php`: mitRoleModuleInfo
- `functions/user.php`: mitSessionRestamp
- `includes/cron_jobs.php`: mit_ai_helper, mit_align, mit_boards, mit_customer_thermometer, mit_ninjaone, mit_procurement_parts, mit_qbo_billing, mit_timesheets, mit_usage
- `includes/footer.php`: mitVersion
- `includes/load_user_session.php`: mitSessionCheck
- `scripts/restore_cli.php`: mitRestoreItflowUpdates, mit_updates_applied
- `setup/index.php`: mitRestoreItflowUpdates, MountaineerIT, mit_message
- `functions/app.php`: MountaineerIT
- `scripts/setup_cli.php`: MountaineerIT
- `scripts/update_cli.php`: mit_updates_applied

**ITFlow functions it calls**

- `functions/app.php`: getFieldById
- `functions/auth.php`: clientScopeSql
- `functions/products.php`: productStockMove
- `functions/request.php`: flashAlert, redirect
- `functions/sanitize.php`: escapeHtml, escapeSql

**ITFlow tables** assets, calendar_events, clients, contract_templates, documents, invoice_items, invoices, logs, modules, networks, payments, product_stock, products, quote_items, recurring_invoice_items, recurring_invoices, recurring_tickets, settings, software, taxes, ticket_attachments, ticket_history, ticket_replies, tickets, user_role_permissions, user_roles, users

**ITFlow columns** account_name, asset_id, client_id, company_id, config_current_database_version, config_mail_from_email, config_mail_from_name, contact_id, document_id, event_id, event_repeat_interval, expense_id, history_id, invoice_archived_at, invoice_client_id, invoice_id, invoice_status, item_id, item_price, log_action, log_client_id, log_id, log_type, log_user_id, module_description, module_id, module_name, payment_id, product_cost, product_id, product_price, product_track_stock, product_type, product_vendor_id, project_id, recurring_invoice_id, recurring_ticket_frequency, role_id, round_trip, software_id, stock_note, stock_product_id, stock_qty, tax_id, ticket_archived_at, ticket_attachment_ticket_id, ticket_billable, ticket_category, ticket_closed_at, ticket_created_at, ticket_history_created_at, ticket_id, ticket_invoice_id, ticket_number, ticket_project_id, ticket_reply_by, ticket_reply_created_at, ticket_reply_id, ticket_resolved_at, ticket_schedule, trip_id, user_id, user_password, user_role_id, user_role_permission_level, user_token, vendor_id

**Watched in ITFlow's code** `FROM_DB_UPDATER` `CURRENT_DATABASE_VERSION` `roleModuleInfo` `settingsSave`

**"ITFlow does this too" words** module_

**Our files** `functions/mit_common.php`, `functions/mit_db_upgrade.php`, `admin/database_updates_mountaineerit.php`, `includes/mit_version.php`, `MIT_CHANGELOG.md`, `admin/includes/mit_changelog_card.php`, `admin/includes/mit_admin_nav.php`, `admin/includes/mit_integrations_nav.php`, `admin/includes/mit_modules_fields.php`, `agent/includes/mit_billing_nav.php`

<a id="checks"></a>
## Our checks (hooks, consistency, portability, AI wall, verify, feature map) (`checks`)

**Takes for granted**

- ITFlow's cron dispatcher (includes/cron_jobs.php) runs our jobs.

**Tests** `tests/smoke/30-checks.spec.js`, `tests/scenarios/scenario-cron-registry.php`

**Hooks in ITFlow's files**

- `.htaccess`: MIT_FEATURES
- `functions.php`: mit_boards, mit_timesheets
- `functions/reports.php`: mit_boards, mit_timesheets
- `includes/cron_jobs.php`: mit_boards, mit_consistency, mit_timesheets

**ITFlow functions it calls**

- `cron/includes/cron_lock.php`: cronJobStop
- `functions/app.php`: getFieldById

**ITFlow tables** assets, clients, contacts, documents, expenses, invoice_items, invoices, payments, products, records, reimbursements, settings, tickets, trips, users

**ITFlow columns** account_id, asset_id, client_id, company_id, config_enable_cron, contact_id, document_archived_at, document_client_id, document_id, expense_amount, expense_reimbursement_id, invoice_id, invoice_number, invoice_prefix, invoice_status, item_id, item_name, item_quantity, payment_amount, payment_created_at, payment_date, payment_id, payment_invoice_id, payment_method, product_id, reimbursement_amount, reimbursement_id, reimbursement_reference, reimbursement_user_id, ticket_archived_at, ticket_client_id, ticket_closed_at, ticket_id, ticket_number, ticket_prefix, ticket_resolved_at, trip_id, user_archived_at, user_id, user_name

**Our files** `scripts/mit_ai_embed_worker.php`, `scripts/mit_ai_wall_check.php`, `scripts/mit_consistency_check.php`, `scripts/mit_feature_lib.php`, `scripts/mit_feature_map.php`, `scripts/mit_features.php`, `scripts/mit_hook_check.php`, `scripts/mit_hook_proof.php`, `scripts/mit_portability_check.php`, `scripts/mit_upstream_impact.php`, `scripts/mit_test_copy.sh`, `scripts/mit_verify.sh`, `scripts/mit_hooks.txt`, `scripts/mit_hook_proof_accepted.txt`, `scripts/mit_hook_proof_switch.txt`, `functions/mit_consistency.php`, `cron/mit_consistency.php`, `MIT_FEATURES.md`

<a id="dev_mode"></a>
## Development mode (a production copy on a test server) (`dev_mode`)

**Takes for granted**

- All mail goes through cron/mail_queue.php (PHPMailer) - the redirect sits just before the send.
- Stripe is set up in includes/stripe_init.php; custom actions go through triggerCustomAction; log forwarding through functions/log_forward.php.
- cron/ticket_email_parser.php and cron/ai_triage.php can be stopped with cronJobStop.

**Tests** `tests/smoke/17-dev-mode.spec.js`, `tests/scenarios/scenario-dev-mode-mail.php`

**Hooks in ITFlow's files**

- `admin/oauth_microsoft_mail_callback.php`: mit_m365
- `admin/post/settings_mail.php`: mit_m365
- `cron/ai_triage.php`: mitDevMode
- `cron/mail_queue.php`: mitDevMailRedirect, mit_common, mit_dev_mode, mit_graph
- `cron/ticket_email_parser.php`: mitDevMode
- `functions.php`: mit_common, mit_m365
- `functions/log_forward.php`: mitDevMode
- `functions/logging.php`: mitDevMode
- `functions/request.php`: mit_m365
- `includes/stripe_init.php`: mitDevBlockStripe
- `includes/top_nav.php`: mit_timers_nav

**ITFlow functions it calls**

- `functions/sanitize.php`: escapeHtml

**ITFlow tables** payments

**Watched in ITFlow's code** `cronJobStop` `triggerCustomAction` `logForwardSend`

**"ITFlow does this too" words** dev_mode, sandbox, test_mode

**Our files** `functions/mit_dev_mode.php`

<a id="backup_restore"></a>
## Restore from Admin > Backup (`backup_restore`)

**Takes for granted**

- ITFlow's backupInspectArchive / backupRestoreArchive / backupAssertUploadsGuards and the backups table.
- load_global_settings.php fixes CURRENT_DATABASE_VERSION for the request (so a restore walks the updates itself).

**Tests** `tests/smoke/16-backup-restore.spec.js`, `tests/smoke/73-restore-roundtrip.spec.js`

**Hooks in ITFlow's files**

- `admin/backup.php`: mitRestoreRowButton, mit_restore_card
- `cron/mail_queue.php`: mit_common
- `functions.php`: mit_common
- `scripts/restore_cli.php`: mit_itflow_error, mit_updates_error
- `scripts/setup_cli.php`: mit_updates_error
- `scripts/update_cli.php`: mit_updates_error
- `setup/index.php`: mit_itflow_error, mit_updates_error

**ITFlow functions it calls**

- `functions/backup.php`: backupAllTypes, backupBuildFileName, backupCreate, backupCurrentDatabaseVersion, backupDbEnsure, backupDeleteDirectory, backupEncryptionKey, backupFormatBytes, backupInspectArchive, backupMaxUploadBytes, backupResolvePath, backupRestoreArchive, backupStorageDir, backupTypeLabel
- `functions/logging.php`: logAudit
- `functions/request.php`: flashAlert, redirect
- `functions/sanitize.php`: escapeHtml, escapeSql
- `functions/security.php`: randomString, validateCSRFToken

**ITFlow tables** accounts, backups, clients, credentials, files, payments, users

**ITFlow columns** backup_completed_at, backup_created_at, backup_created_by, backup_error, backup_file_name, backup_id, backup_sha256, backup_size, backup_source, backup_status, backup_type, user_id, user_password

**Watched in ITFlow's code** `backupRestoreArchive` `backupInspectArchive` `backupAssertUploadsGuards`

**"ITFlow does this too" words** backupRestore, restore_archive, restoreArchive, steps/restore, restore_upload

**Our files** `functions/mit_backup_restore.php`, `admin/includes/mit_restore_card.php`, `admin/modals/mit_backup/restore.php`, `admin/post/mit_backup_restore.php`

<a id="cron_health"></a>
## Cron health (warning, emails, /mit_health.php) (`cron_health`)

**Takes for granted**

- ITFlow's cron_jobs table (status, last run, interval) and settings.config_cron_last_dispatch_at.

**Tests** `tests/scenarios/scenario-cron-watch.php`, `tests/smoke/81-cron-health.spec.js`

**Hooks in ITFlow's files**

- `admin/cron.php`: mit_cron_card
- `admin/oauth_microsoft_mail_callback.php`: mit_m365
- `admin/post/settings_mail.php`: mit_m365
- `functions.php`: mit_cron_health, mit_m365
- `functions/request.php`: mit_m365
- `includes/cron_jobs.php`: mit_cron_health, mit_cron_watch
- `includes/top_nav.php`: mit_cron_nav

**ITFlow functions it calls**

- `cron/includes/cron_lock.php`: cronJobStop
- `functions/app.php`: addToMailQueue
- `functions/logging.php`: logAudit
- `functions/request.php`: flashAlert, redirect
- `functions/sanitize.php`: escapeHtml, escapeSql
- `functions/security.php`: validateCSRFToken
- `includes/cron_jobs.php`: cronJobRegistry

**ITFlow tables** cron_jobs, settings, user_roles, users

**ITFlow columns** company_id, config_cron_last_dispatch_at, config_enable_cron, cron_job_daily_at, cron_job_enabled, cron_job_interval_minutes, cron_job_last_error, cron_job_last_error_at, cron_job_last_run_at, cron_job_last_status, cron_job_name, cron_job_schedule, role_id, role_is_admin, user_archived_at, user_email, user_name, user_role_id, user_status, user_type

**Watched in ITFlow's code** `config_cron_last_dispatch_at`

**"ITFlow does this too" words** cron_alert, health, heartbeat, cron_fail, cronWatch

**Our files** `functions/mit_cron_health.php`, `includes/mit_cron_nav.php`, `admin/includes/mit_cron_card.php`, `admin/post/mit_cron_health.php`, `cron/mit_cron_watch.php`, `mit_health.php`

<a id="theme"></a>
## Modern look, logo options, sign-in screen, client tiles, time fields, portal tables (`theme`)

**Takes for granted**

- ITFlow's markup classes: .app-header, .app-sidebar .nav-link.active, .card-header.bg-dark, .itflow-dash-rail*, .itflow-client-switcher.
- Company theme colours as body.theme-<colour> (admin/settings_theme.php); statuses coloured through --itflow-status-bg.
- login.php / client pages send CSP default-src 'self' - our files there must be files, not inline.

**Tests** `tests/smoke/21-brand-logo.spec.js`, `tests/smoke/26-portal-phone.spec.js`, `tests/smoke/46-client-tiles.spec.js`, `tests/smoke/80-theme-look.spec.js`

**Hooks in ITFlow's files**

- `admin/post/settings_company.php`: mitBrandLogoSave, mit_lines
- `admin/settings_company.php`: mitBrandLogoField
- `agent/assets.php`: mitClientTiles
- `agent/includes/inc_client_top_head.php`: mitClientHeadMeta
- `agent/includes/side_nav.php`: mitBrandLogoAttrs, mitBrandLogoOnly, mitBrandScreenLogo, mit_logo_only
- `agent/software.php`: mitClientTiles
- `agent/tickets.php`: mitClientTiles
- `agent/user/post/profile.php`: mitThemeSavePreference
- `agent/user/user_preferences.php`: mitThemePreferenceField
- `client/includes/footer.php`: mit_portal_tables
- `client/login_reset.php`: mitLoginHead
- `functions.php`: mit_ninjaone, mit_theme
- `includes/cron_jobs.php`: mit_ninjaone
- `includes/header.php`: mitThemeHead
- `login.php`: mitBrandScreenLogo, mitLoginHead

**ITFlow functions it calls**

- `functions/dashboard.php`: dashboardDuration
- `functions/logging.php`: logAudit
- `functions/request.php`: flashAlert
- `functions/sanitize.php`: checkFileUpload, escapeHtml, escapeSql
- `functions/software.php`: softwareSeatsUsedSql
- `functions/ticket.php`: ticketOverdueSql

**ITFlow tables** assets, categories, invoices, quotes, settings, software, software_assets, software_contacts, tags, ticket_replies, tickets, users

**ITFlow columns** asset_archived_at, asset_client_id, asset_id, asset_install_date, asset_purchase_date, asset_warranty_expire, client_id, contact_id, software_archived_at, software_client_id, software_expire, software_id, software_seats, ticket_assigned_to, ticket_client_id, ticket_closed_at, ticket_id, ticket_project_id, ticket_reply_archived_at, ticket_reply_created_at, ticket_reply_ticket_id, ticket_reply_time_worked, ticket_resolved_at

**ITFlow page ids / classes its scripts and styles use** `#items` `.app-header` `.app-main` `.app-sidebar` `.app-wrapper` `.itflow-brand-name` `.itflow-client-avatar` `.itflow-client-head` `.itflow-client-head-name` `.itflow-client-switcher` `.itflow-dash-money-value` `.itflow-dash-rail` `.itflow-dash-rail-clear` `.itflow-dash-rail-count` `.itflow-dash-rail-detail` `.itflow-dash-rail-item` `.itflow-dash-rail-label` `.itflow-theme-swatch`

**Watched in ITFlow's code** `app-header` `app-sidebar` `itflow-dash-rail` `itflow-client-switcher` `--itflow-accent` `--itflow-status-bg` `login-box`

**"ITFlow does this too" words** theme, dark_mode, logo

**Our files** `functions/mit_theme.php`, `css/mit_login.css`, `css/mit_login_plain.css`, `css/mit_theme.css`, `css/mit_login_nodes.svg`, `css/fonts/inter/OFL.txt`, `css/fonts/inter/inter-latin-ext-wght-normal.woff2`, `css/fonts/inter/inter-latin-wght-normal.woff2`, `js/mit_theme.js`, `js/mit_time_inputs.js`, `js/mit_portal_tables.js`, `functions/mit_client_tiles.php`

<a id="emails"></a>
## Email look: logo inside the message, resolved wording, notification switches (`emails`)

**Takes for granted**

- Every email is built by ITFlow's emailHtml / emailQueue / ticketMailSend and sent by cron/mail_queue.php.
- emailHtml links the logo as https://<base url>/uploads/settings/<file> (mitMailInlineImages embeds it).
- Each recipient is greeted by emailQueue ("Hello <name>,") - our wording must not greet again.
- ticketMailSchedule / ticketMailAssigned / recurringTicketMailAssigned are where schedule and assignment mail is built.

**Tests** `tests/scenarios/scenario-mail-logo.php`, `tests/smoke/25-notifications.spec.js`, `tests/smoke/47-assign-switches.spec.js`, `tests/scenarios/scenario-assign-switches.php`

**Hooks in ITFlow's files**

- `admin/post/settings_notification.php`: mitNotificationsSave, mit_changed
- `admin/settings_notification.php`: mitNotificationRows, data-mit-notifications
- `agent/modals/ticket/ticket_edit_schedule.php`: mitScheduleNotifyField
- `agent/post/ticket.php`: mitNotifyOn, mitScheduleNotifyFromPost
- `functions.php`: mit_notifications
- `functions/ticket.php`: mitNotifyOn, mitScheduleExtraRecipients, mitScheduleNotify, mit_schedule_skip_contact, mit_contact, mit_extra, mit_watchers
- `functions/ticket_fields.php`: mitNotifyOn

**ITFlow functions it calls**

- `functions/billing.php`: billingCompany
- `functions/email.php`: emailCapturing
- `functions/sanitize.php`: escapeHtml, escapeSql
- `functions/ticket.php`: ticketColleagueRecipients, ticketWatcherEmails

**ITFlow tables** clients, contacts, locations, notifications, ticket_watchers, tickets

**ITFlow columns** company_name, contact_archived_at, contact_client_id, contact_email, contact_id, contact_location_id, contact_name, contact_primary, location_contact_id, location_id, ticket_client_id, ticket_contact_id, ticket_id, ticket_location_id, ticket_onsite, user_name, watcher_email, watcher_ticket_id

**Watched in ITFlow's code** `/uploads/settings/` `addToMailQueue` `ticketMailSchedule` `ticketMailAssigned` `ticketEmailContent`

**"ITFlow does this too" words** notify_schedule, schedule_notify, inline_image, email_logo, resolved_message

**Our files** `functions/mit_notifications.php`, `agent/js/mit_schedule_notify.js`

<a id="calendar"></a>
## Calendar (agent colours, booking ends, conflicts, Left behind, 7 days, weekday repeats, event agents, holidays) (`calendar`)

**Takes for granted**

- Bookings are tickets.ticket_schedule (+ our ticket_schedule_end); ticketSetSchedule / ticketCancelSchedule change them.
- functions/calendar.php builds the sources (calendarSources / calendarSourceItems / calendarEventItems); agent/js/calendar.js draws them with FullCalendar v7.
- Attendee agents (calendar_event_attendees.attendee_user_id) are an entry's assignment.
- Holidays are business_holidays (Admin > SLA closure days).

**Tests** `tests/smoke/08-calendar.spec.js`, `tests/smoke/20-event-agents.spec.js`, `tests/smoke/50-sched-window.spec.js`, `tests/smoke/52-calendar-state-moves.spec.js`, `tests/smoke/53-event-repeat-weekday.spec.js`, `tests/smoke/54-recurring-ticket-weekday.spec.js`, `tests/scenarios/scenario-sched-booking.php`, `tests/scenarios/scenario-sched-byday-pages.php`, `tests/scenarios/scenario-sched-byday.php`, `tests/scenarios/scenario-sched-end.php`, `tests/scenarios/scenario-sched-event-agents.php`, `tests/scenarios/scenario-sched-feeds.php`, `tests/scenarios/scenario-sched-gaps.php`, `tests/scenarios/scenario-sched-outlook.php`, `tests/scenarios/scenario-sched-sources.php`, `tests/scenarios/scenario-sched-workload.php`

**Hooks in ITFlow's files**

- `admin/modals/user/user_form.php`: mitCalendarColorField
- `admin/post/users.php`: mitSaveAgentColor
- `admin/settings_ticket.php`: mitServiceTicketColor, mit_calendar_card
- `agent/ajax.php`: mitCleanCalendarState, mitLeftBehindAjax, mitSetUserPreference, mitTicketScheduleMinutes, mit_calendar, mit_calendar_state, mit_left_behind, mit_ticket_techs
- `agent/calendar.php`: mit-cal-agent, mit-cal-when, mit-tech-dot, mitAgentColor, mitCalendarState, mitLeftBehindCard, mit_calendar, mit_outlook, mit_ticket_techs
- `agent/js/calendar.js`: MountaineerIT, mit-cal-agent, mit-cal-when, mit-tech-dot, mitStriped, mit_calendar, mit_calendar_state, mit_event_agents
- `agent/js/ticket_schedule_modal.js`: mit_calendar
- `agent/modals/calendar/calendar_event.php`: mitEventQuickAssign, mit_assign, mit_who
- `agent/modals/calendar/calendar_event_add.php`: mitEventDefaultAgents
- `agent/modals/calendar/calendar_event_form.php`: mitEventAssignField, mitRepeatOnField, mit_assign
- `agent/modals/recurring_ticket/recurring_ticket_add.php`: mitRecurringTicketRepeatOnField
- `agent/modals/recurring_ticket/recurring_ticket_edit.php`: mitRecurringTicketRepeatOnField
- `agent/modals/ticket/ticket_edit_schedule.php`: mitScheduleLengths, mitTicketScheduleMinutes
- `agent/post/recurring_ticket.php`: mitByDayFollow, mitRecurringTicketSaveByDay
- `agent/ticket.php`: mitScheduleShortText
- `agent/user/includes/user_side_nav.php`: mit_outlook
- `agent/user/post/profile.php`: mitSaveAgentColor
- `agent/user/user_preferences.php`: mitCalendarColorField
- `cron/nightly_tasks.php`: mitRecurringTicketByDayColumn
- `functions.php`: mit_calendar
- `functions/app.php`: mitTicketScheduleEnd
- `functions/calendar.php`: mitAgentColor, mitByDayDate, mitByDayRule, mitByDayText, mitCalendarTicketItems, mitEventByDayColumn, mitEventByDaySql, mitEventItemsAgents, mitHolidayCalendarItems, mitHolidayCalendarSources, mitScheduleOverlapSql, mitTicketScheduleEnd, mit_recurring, mit_ticket_techs
- `functions/client_overview.php`: mitEventByDayColumn
- `functions/dashboard.php`: mitEventByDayColumn
- `functions/ticket.php`: mitByDayDate, mitByDayRule, mitByDayText, mitRecurringTicketByDay, mitScheduleConflicts, mitScheduleWhenText, mitTicketScheduleEnd, mit_recurring, mit_ticket_techs
- `includes/cron_jobs.php`: mit_outlook

**ITFlow functions it calls**

- `agent/post/event.php`: calendarSentText
- `functions/auth.php`: clientScopeSql, hasClientAccess, lookupUserPermission, permissionRefusedText
- `functions/calendar.php`: calendarCanScheduleTickets, calendarColor, calendarEventAttendees, calendarEventCan, calendarEventForRequest, calendarEventItems, calendarEventRepeats, calendarEventRow, calendarInvitedAttendees, calendarList, calendarSendInvites, calendarSetAttendees, calendarWhenText
- `functions/format.php`: selectOptionColor, selectOptionUser
- `functions/logging.php`: logAudit
- `functions/request.php`: flashAlert, redirect
- `functions/sanitize.php`: escapeHtml, escapeSql
- `functions/security.php`: validateCSRFToken
- `functions/ticket.php`: ticketReference, ticketScheduleValue

**ITFlow tables** business_holidays, calendar_event_attendees, calendar_events, clients, credentials, history, recurring_tickets, settings, tickets, users

**ITFlow columns** attendee_archived_at, attendee_contact_id, attendee_event_id, attendee_id, attendee_invited_at, attendee_user_id, calendar_color, calendar_name, client_id, client_name, event_all_day, event_archived_at, event_client_id, event_end, event_id, event_sequence, event_start, event_title, holiday_date, holiday_id, holiday_name, recurring_ticket_id, ticket_archived_at, ticket_assigned_to, ticket_client_id, ticket_closed_at, ticket_id, ticket_number, ticket_onsite, ticket_prefix, ticket_priority, ticket_resolved_at, ticket_schedule, ticket_subject, user_archived_at, user_avatar, user_id, user_name, user_status, user_type

**ITFlow page ids / classes its scripts and styles use** `#calendar` `#calendarAgent` `#itflowCalendar`

**Watched in ITFlow's code** `ticket_schedule` `calendarSourceItems` `calendarEventItems` `eventContent` `eventDidMount` `headerToolbar` `business_holidays`

**"ITFlow does this too" words** schedule_end, calendar_color, agent_color, by_day, byday

**Our files** `functions/mit_calendar.php`, `functions/mit_left_behind.php`, `functions/mit_recurring.php`, `functions/mit_event_agents.php`, `agent/js/mit_left_behind.js`, `js/mit_repeat_on.js`, `admin/includes/mit_calendar_card.php`, `admin/post/mit_calendar_settings.php`, `agent/post/mit_event_agents.php`

<a id="outlook_m365"></a>
## Microsoft 365 app, Graph mail, Outlook calendar per tech (`outlook_m365`)

**Takes for granted**

- Mail OAuth settings (config_mail_oauth_*) and the token flow in functions/request.php httpFormPost / admin/post/settings_mail.php.
- cron/mail_queue.php sends one PHPMailer message per email; cron/ticket_email_parser.php reads mail through ImapEngine's Mailbox.
- Ticket invites are iCal with UID ticket-<id>@ (createiCalStr).

**Tests** `tests/scenarios/scenario-sched-outlook.php`, `tests/scenarios/scenario-dev-mode-mail.php`, `tests/scenarios/scenario-m365-hooks.php`, `tests/scenarios/scenario-parser-boards.php`, `tests/smoke/82-m365-mail-settings.spec.js`, `tests/smoke/79-platform-pages.spec.js`

**Hooks in ITFlow's files**

- `admin/oauth_microsoft_mail_callback.php`: mitMicrosoftTokenFields, mit_m365
- `admin/post/settings_mail.php`: mitM365ConnectPrompt, mitM365GraphMailboxTest, mitM365KeepCertificateSecret, mitMicrosoftTokenFields, mit_m365, mit_test
- `admin/settings_mail.php`: mit_m365_card
- `agent/ajax.php`: mit_calendar, mit_ticket_techs
- `agent/calendar.php`: mit_calendar, mit_outlook, mit_ticket_techs
- `agent/js/calendar.js`: mit_calendar
- `agent/js/ticket_schedule_modal.js`: mit_calendar
- `agent/user/includes/user_side_nav.php`: mit_outlook
- `cron/mail_queue.php`: mitM365GraphSendMail, mitM365GraphSendReady, mit_graph
- `cron/ticket_email_parser.php`: mitM365GraphMailbox, mitM365GraphMailboxReady
- `functions.php`: mit_calendar, mit_m365
- `functions/calendar.php`: mitOutlookBusyItems, mitOutlookCalendarSources, mit_ticket_techs
- `functions/request.php`: mitMicrosoftTokenFields, mit_m365
- `functions/ticket.php`: mitOutlookSyncsEmail, mit_ticket_techs
- `includes/cron_jobs.php`: mit_outlook, mit_outlook_sync

**ITFlow functions it calls**

- `cron/includes/cron_lock.php`: cronJobStop
- `functions/format.php`: timeAgo
- `functions/logging.php`: logApp, logAudit
- `functions/request.php`: flashAlert, redirect
- `functions/sanitize.php`: escapeHtml, escapeSql
- `functions/security.php`: randomString, validateCSRFToken
- `functions/ticket.php`: ticketReference

**ITFlow tables** calendars, categories, clients, contacts, credentials, locations, settings, tickets, users

**ITFlow columns** account_name, client_id, client_name, company_id, config_enable_cron, config_mail_oauth_access_token, config_mail_oauth_access_token_expires_at, config_mail_oauth_client_id, config_mail_oauth_client_secret, config_mail_oauth_refresh_token, config_mail_oauth_tenant_id, contact_id, contact_location_id, contact_mobile, contact_name, contact_phone, event_id, location_address, location_city, location_id, location_name, location_state, location_zip, ticket_archived_at, ticket_assigned_to, ticket_client_id, ticket_closed_at, ticket_contact_id, ticket_id, ticket_location_id, ticket_number, ticket_onsite, ticket_prefix, ticket_resolved_at, ticket_schedule, ticket_subject, user_archived_at, user_email, user_id, user_name, user_status

**Watched in ITFlow's code** `httpFormPost` `XOAUTH2` `new Mailbox(` `createiCalStr` `config_mail_oauth_client_secret`

**"ITFlow does this too" words** graph, outlook, m365, microsoft

**Our files** `functions/mit_m365.php`, `functions/mit_m365_mailbox.php`, `functions/mit_outlook.php`, `admin/includes/mit_m365_card.php`, `admin/post/mit_m365.php`, `agent/user/mit_outlook.php`, `agent/user/mit_outlook_callback.php`, `agent/user/post/mit_outlook.php`, `cron/mit_outlook_sync.php`

<a id="ticket_techs"></a>
## Additional techs (helpers) on tickets (`ticket_techs`)

**Takes for granted**

- tickets.ticket_assigned_to is the one lead; ticketChangeAssignee changes it (four paths, none hooked - mitTicketTechsTidy on read).
- Assignee reply copies hang off ticketMailAssigneeReplied (parser, client/post.php).

**Tests** `tests/smoke/42-helpers.spec.js`, `tests/scenarios/scenario-helpers.php`, `tests/scenarios/scenario-parser-boards.php`, `tests/scenarios/scenario-helper-views.php`

**Hooks in ITFlow's files**

- `agent/ajax.php`: mitTicketTechCalendarMove, mit_ticket_techs
- `agent/calendar.php`: mit_outlook, mit_ticket_techs
- `agent/js/ticket_schedule_modal.js`: mit_ticket_tech
- `agent/modals/ticket/ticket_edit_schedule.php`: mitAgentCanSeeClient
- `agent/post/ticket.php`: mitAgentCanSeeClient, mitScheduleLeadChanging
- `agent/ticket.php`: mitTicketTechsProp
- `agent/ticket_kanban.php`: mitTicketTechsListBadge
- `agent/ticket_list.php`: mitTicketTechsListBadge
- `agent/tickets.php`: mitAssignedSql
- `agent/user/includes/user_side_nav.php`: mit_outlook
- `client/post.php`: mitTicketTechsCopyReply
- `cron/ticket_email_parser.php`: mitAgentCanWorkTicket, mitTicketTechsCopyReply
- `functions/calendar.php`: mitTicketTechCalendarItems, mitTicketTechFeedItems, mit_ticket_techs
- `functions/dashboard.php`: mitAssignedSql, mitTicketTechDashboardItems
- `functions/ticket.php`: mitTicketTechsLeadCancelled, mitTicketTechsLeadMoved, mit_schedule_skip_contact, mit_ticket_techs
- `includes/cron_jobs.php`: mit_outlook

**ITFlow functions it calls**

- `functions/app.php`: addToMailQueue, createiCalStr, createiCalStrCancel, getTicketCalendarUid
- `functions/auth.php`: clientScopeSql, enforceUserPermission, hasClientAccess, lookupUserPermission
- `functions/calendar.php`: calendarCanScheduleTickets, calendarHost, calendarTicketTitle
- `functions/format.php`: selectOptionColor
- `functions/logging.php`: logAudit, logTicketHistory
- `functions/request.php`: flashAlert, redirect
- `functions/sanitize.php`: escapeHtml, escapeSql
- `functions/security.php`: validateCSRFToken
- `functions/ticket.php`: ticketAgentEmailContent, ticketForModal, ticketForRequest, ticketIsOpen, ticketMailAgent, ticketMailAgents, ticketMailSchedule, ticketReference, ticketRow, ticketScheduleValue, ticketUrl
- `functions/user.php`: userClientAccess

**ITFlow tables** categories, clients, contacts, email_queue, locations, modules, notifications, ticket_replies, ticket_statuses, tickets, user_role_permissions, user_roles, users

**ITFlow columns** client_id, client_name, contact_id, contact_location_id, contact_mobile, contact_name, contact_phone, email_cal_str, email_queued_at, email_recipient, email_subject, feed_busy_only, feed_scope, feed_tickets, feed_user_id, location_address, location_city, location_id, location_name, location_state, location_zip, module_id, module_name, notification_action, notification_client_id, notification_type, notification_user_id, role_archived_at, role_id, role_is_admin, ticket_archived_at, ticket_assigned_to, ticket_client_id, ticket_closed_at, ticket_contact_id, ticket_created_at, ticket_details, ticket_id, ticket_location_id, ticket_number, ticket_onsite, ticket_prefix, ticket_reply, ticket_reply_by, ticket_reply_ticket_id, ticket_reply_time_worked, ticket_reply_type, ticket_resolved_at, ticket_schedule, ticket_status, ticket_status_id, ticket_status_name, ticket_subject, ticket_updated_at, user_archived_at, user_avatar, user_email, user_id, user_name, user_role_id, user_role_permission_level, user_status, user_type

**Watched in ITFlow's code** `ticket_assigned_to` `ticketMailAssigneeReplied` `ticketChangeAssignee`

**"ITFlow does this too" words** collaborator, additional_tech, helper, ticket_users, ticket_assignees

**Our files** `functions/mit_ticket_techs.php`, `agent/modals/ticket/mit_ticket_tech.php`, `agent/post/mit_ticket_techs.php`

<a id="dispatch"></a>
## Dispatch board, Workload, This week (`dispatch`)

**Takes for granted**

- Booking = ticketChangeAssignee + ticketSetSchedule; the Schedule dialog repeats Edit ticket / Change client / SLA rules (change_client_ticket, ticketMergeInto in agent/post/ticket.php).
- ticketSlaState for SLA counts; Admin > SLA working hours.

**Tests** `tests/smoke/14-dispatch-role.spec.js`, `tests/smoke/51-dispatch-flow.spec.js`, `tests/scenarios/scenario-sched-workload.php`

**Hooks in ITFlow's files**

- `agent/ajax.php`: mit_calendar, mit_left_behind, mit_ticket_techs
- `agent/calendar.php`: data-mit-agent, mit_calendar, mit_ticket_techs
- `agent/includes/side_nav.php`: mit_dispatch_nav
- `agent/js/calendar.js`: data-mit-agent, mitAgent, mit_calendar, mit_event_agents
- `agent/js/ticket_schedule_modal.js`: mit_calendar
- `functions.php`: mit_boards, mit_calendar, mit_dispatch, mit_notifications
- `functions/calendar.php`: mit_ticket_techs
- `functions/reports.php`: mit_boards
- `functions/ticket.php`: mit_schedule_skip_contact, mit_ticket_techs
- `includes/cron_jobs.php`: mit_boards

**ITFlow functions it calls**

- `agent/post/ticket.php`: ticketMergeInto
- `functions/app.php`: getFieldById
- `functions/auth.php`: clientScopeSql, enforceUserPermission, hasClientAccess, lookupUserPermission
- `functions/calendar.php`: calendarCanScheduleTickets, calendarEventItems, calendarIsDate, calendarList, calendarSourceItems, calendarSources
- `functions/format.php`: timeAgo
- `functions/logging.php`: logAudit, logTicketHistory, triggerCustomAction
- `functions/request.php`: flashAlert, redirect
- `functions/sanitize.php`: escapeHtml, escapeSql
- `functions/security.php`: validateCSRFToken
- `functions/sla.php`: applyTicketSla, getSlaSettings
- `functions/ticket.php`: ticketCancelSchedule, ticketCategoryId, ticketClientOwnedId, ticketDuePill, ticketDueState, ticketIsOpen, ticketReference, ticketRow, ticketScheduleValue, ticketSetSchedule, ticketSlaPill, ticketSlaState, ticketUrl
- `functions/ticket_fields.php`: ticketChangeAssignee, ticketChangePriority, ticketChangeStatus, ticketPickableStatuses

**ITFlow tables** calendars, categories, clients, contacts, credentials, modules, notifications, slas, ticket_assets, ticket_history, ticket_replies, ticket_statuses, tickets, trips, user_settings, users

**ITFlow columns** category_archived_at, category_id, category_name, category_type, client_archived_at, client_id, client_name, contact_archived_at, contact_client_id, contact_id, contact_name, contact_primary, contact_title, event_id, module_name, sla_archived_at, sla_id, sla_name, ticket_archived_at, ticket_asset_id, ticket_assigned_to, ticket_category, ticket_client_id, ticket_closed_at, ticket_contact_id, ticket_created_at, ticket_due_at, ticket_first_response_at, ticket_history_created_at, ticket_history_description, ticket_history_ticket_id, ticket_id, ticket_location_id, ticket_number, ticket_onsite, ticket_prefix, ticket_priority, ticket_project_id, ticket_reply_created_at, ticket_reply_ticket_id, ticket_reply_type, ticket_resolution_due_at, ticket_resolution_sla_alert_stage, ticket_resolution_sla_met, ticket_resolved_at, ticket_response_due_at, ticket_response_sla_alert_stage, ticket_response_sla_met, ticket_schedule, ticket_sla_id, ticket_status, ticket_status_id, ticket_status_name, ticket_status_pauses_sla, ticket_subject, ticket_vendor_id, trip_client_id, trip_location_id, trip_ticket_id, user_archived_at, user_config_calendar_first_day, user_id, user_name, user_status, user_type

**Watched in ITFlow's code** `ticketMergeInto` `ticketSlaState` `change_client_ticket`

**"ITFlow does this too" words** dispatch, workload

**Our files** `functions/mit_dispatch.php`, `functions/mit_dispatch_workload.php`, `agent/mit_dispatch.php`, `agent/mit_dispatch_ajax.php`, `agent/js/mit_dispatch.js`, `agent/includes/mit_dispatch_nav.php`

<a id="boards"></a>
## Service boards (routing, escalation, round-robin, by role, pickers, report) (`boards`)

**Takes for granted**

- New tickets come from add_ticket, the parser's addTicket, the portal, the API, recurring and projects (a sweep catches the rest).
- quickPick() / js/quick_pick.js markup and quick_pick_options / quick_pick_set in agent/ajax.php.
- Ticket lists are agent/tickets.php + ticket_list.php / ticket_kanban.php; dashboard counts in functions/dashboard.php.

**Tests** `tests/smoke/07-boards.spec.js`, `tests/smoke/13-board-roles.spec.js`, `tests/smoke/15-ticket-picks.spec.js`, `tests/smoke/48-boards-ui.spec.js`, `tests/scenarios/scenario-boards.php`, `tests/scenarios/scenario-parser-boards.php`, `tests/scenarios/scenario-picks-project.php`, `tests/scenarios/scenario-board-scope.php`

**Hooks in ITFlow's files**

- `admin/modals/role/role_add.php`: mitBoardRoleField
- `admin/modals/role/role_edit.php`: mitBoardRoleField
- `admin/post/roles.php`: mitBoardRoleSave
- `agent/ajax.php`: mitBoardVisibleSql, mitTicketPickOptions, mitTicketPickSet, mit_ticket_techs, mit_options, mit_set
- `agent/calendar.php`: mit_ticket_techs
- `agent/dashboard.php`: mitBoardDashCard, mitBoardDashLinks, mitBoardDashPicker
- `agent/modals/ticket/ticket_add.php`: mitBoardNewTicketField
- `agent/post/ticket.php`: mitBoardAfterCreate, mitBoardRoute, mit_board
- `agent/ticket.php`: mitBoardEscalateMenuItem, mitBoardTicketProp, mitTicketPickButton, mit_category, mit_sla
- `agent/ticket_kanban.php`: mitBoardListTag
- `agent/ticket_list.php`: mitBoardListTag
- `agent/tickets.php`: mitBoardHiddenInput, mitBoardSwitcher, mitBoardTicketsFilter
- `cron/ticket_email_parser.php`: mitBoardMessageAddresses, mitBoardRoute, mit_board_addresses
- `functions.php`: mit_boards, mit_dispatch, mit_projects
- `functions/calendar.php`: mit_ticket_techs
- `functions/client_overview.php`: mitBoardVisibleSql
- `functions/dashboard.php`: mitBoardDashAnd
- `functions/reports.php`: mitBoardReportEntry, mit_boards
- `functions/search.php`: mitBoardVisibleSql
- `functions/sla.php`: mit_projects
- `functions/ticket.php`: mit_ticket_techs
- `includes/cron_jobs.php`: mit_boards, mit_projects
- `js/quick_pick.js`: mit_ticket_picks

**ITFlow functions it calls**

- `cron/includes/cron_lock.php`: cronJobStop
- `functions/app.php`: addToMailQueue, getFieldById
- `functions/auth.php`: clientScopeSql, enforceUserPermission, hasClientAccess, lookupUserPermission
- `functions/categories.php`: categoryMarkHtml
- `functions/dashboard.php`: dashboardSlaBreachedSql
- `functions/format.php`: formatAssignee
- `functions/logging.php`: logAudit, logTicketHistory, triggerCustomAction
- `functions/reports.php`: reportChart, reportClientId, reportCount, reportCsv, reportEmpty, reportEnforce, reportExporting, reportHeader, reportLateHtml, reportMedian, reportPastYears, reportPeriod, reportRangeSql, reportRateHtml, reportSectionOpen, reportSelectControl, reportShare, reportSlaFigures, reportStats, reportTable, reportTicketSql, reportUrl
- `functions/request.php`: flashAlert, redirect
- `functions/sanitize.php`: escapeHtml, escapeSql
- `functions/security.php`: validateCSRFToken
- `functions/sla.php`: applyTicketSla, getBusinessHolidays, getSlaSettings
- `functions/ticket.php`: ticketCategoryId, ticketForModal, ticketForRequest, ticketIsOpen, ticketReference, ticketUrl
- `functions/ticket_fields.php`: ticketChangeAssignee

**ITFlow tables** categories, clients, notifications, projects, records, settings, slas, ticket_replies, tickets, user_roles, users

**ITFlow columns** category_archived_at, category_color, category_icon, category_id, category_name, category_type, client_archived_at, client_id, client_name, company_id, config_enable_cron, config_smtp_host, config_smtp_provider, notification_action, notification_client_id, notification_entity_id, notification_type, notification_user_id, role_archived_at, role_id, role_is_admin, role_name, sla_archived_at, sla_id, sla_name, ticket_assigned_to, ticket_category, ticket_client_id, ticket_closed_at, ticket_created_at, ticket_first_response_at, ticket_id, ticket_number, ticket_prefix, ticket_project_id, ticket_reply, ticket_reply_by, ticket_reply_ticket_id, ticket_reply_time_worked, ticket_reply_type, ticket_resolution_sla_met, ticket_resolved_at, ticket_sla_id, ticket_subject, user_archived_at, user_avatar, user_email, user_id, user_name, user_status, user_type

**Watched in ITFlow's code** `quick_pick_options` `quick_pick_set` `applyTicketSla` `dashboardAttention`

**"ITFlow does this too" words** board, tier, escalat, round_robin, auto_assign

**Our files** `functions/mit_boards.php`, `functions/mit_board_roles.php`, `functions/mit_ticket_picks.php`, `admin/mit_boards.php`, `admin/modals/mit_boards/board.php`, `admin/post/mit_boards.php`, `agent/modals/mit_board/board_move.php`, `agent/post/mit_boards.php`, `agent/reports/mit_boards.php`, `cron/mit_boards.php`

<a id="projects"></a>
## Project tickets kept apart (`projects`)

**Takes for granted**

- tickets.ticket_project_id; projectAddTicket / projectRemoveTicket; applyTicketSla.

**Tests** `tests/smoke/44-project-tickets.spec.js`, `tests/scenarios/scenario-picks-project.php`

**Hooks in ITFlow's files**

- `admin/settings_project.php`: mit_project_card
- `agent/ajax.php`: mit_split_projects
- `agent/post/ticket.php`: mitProjectTicketMoved
- `agent/tickets.php`: mitProjectTicketsShows
- `functions.php`: mit_projects
- `functions/calendar.php`: mitProjectBookingItems
- `functions/dashboard.php`: mitServiceTicketsAnd
- `functions/project.php`: mitProjectTicketMoved
- `functions/sla.php`: mitProjectSlaOff, mitProjectSlaStart, mit_projects
- `includes/cron_jobs.php`: mit_project_tickets, mit_projects

**ITFlow functions it calls**

- `cron/includes/cron_lock.php`: cronJobStop
- `functions/calendar.php`: calendarColor, calendarSourceItems
- `functions/logging.php`: logAudit
- `functions/project.php`: projectReference
- `functions/request.php`: flashAlert, redirect
- `functions/sanitize.php`: escapeHtml
- `functions/security.php`: validateCSRFToken
- `functions/sla.php`: applyTicketSla

**ITFlow tables** projects, settings, tickets

**ITFlow columns** company_id, config_enable_cron, project_id, project_name, project_number, project_prefix, ticket_id, ticket_project_id, ticket_sla_id

**Watched in ITFlow's code** `ticket_project_id` `projectAddTicket` `projectRemoveTicket`

**"ITFlow does this too" words** project_ticket

**Our files** `functions/mit_projects.php`, `admin/includes/mit_project_card.php`, `admin/post/mit_project_settings.php`, `cron/mit_project_tickets.php`

<a id="ticket_page"></a>
## Ticket page: layout, Read tick, timers, list views, Resolve - no response (`ticket_page`)

**Takes for granted**

- The ticket page's boxes are found by their title icons (fa-align-left, fa-sliders-h, fa-user, fa-layer-group, fa-map-marker-alt, fa-tasks, fa-link, fa-history).
- Reply form ids: #replyComposer, public_reply_type_opt1/2, #replyAudience, #replySubmitLabel, #ticket_add_reply.
- Timer keys in localStorage: <id>-startTime / <id>-pausedTime / ticket-timer-running-<id> (agent/js/ticket_time_tracking.js).
- ticketResolve sends no mail itself; ticketMailResolved builds the resolved email.

**Tests** `tests/smoke/43-layout-timers.spec.js`, `tests/smoke/23-bulk-toolbar.spec.js`, `tests/smoke/24-no-response.spec.js`, `tests/smoke/03-our-features.spec.js`, `tests/scenarios/scenario-no-response.php`

**Hooks in ITFlow's files**

- `admin/settings_ticket.php`: mitNoResponseReady, mit_no_response_card
- `agent/ajax.php`: mitTicketLayoutAjax, mit_ticket_layout, mit_timer_tickets, mit_timers
- `agent/ticket.php`: mitNoResponseButton, mitTicketLayoutHtml, mit_ticket_layout
- `agent/tickets.php`: mitListViewResetButton, mitListViewRestore
- `cron/mail_queue.php`: mit_dev_mode
- `functions.php`: mit_list_views, mit_ninjaone, mit_no_response, mit_ticket_layout
- `functions/ticket.php`: mitResolvedMailContent
- `includes/cron_jobs.php`: mit_ninjaone, mit_procurement_parts
- `includes/top_nav.php`: mit_timers_nav

**ITFlow functions it calls**

- `functions/auth.php`: enforceUserPermission, hasClientAccess, lookupUserPermission
- `functions/email.php`: emailPlainText
- `functions/logging.php`: logAudit
- `functions/request.php`: flashAlert, redirect
- `functions/sanitize.php`: escapeHtml, escapeSql
- `functions/security.php`: validateCSRFToken
- `functions/ticket.php`: ticketEmailContent, ticketEmailPreview, ticketForModal, ticketForRequest, ticketIsOpen, ticketMailSend, ticketOpenTaskCount, ticketReference, ticketReplyEmailed, ticketResolve, ticketRow

**ITFlow tables** credentials, history, notifications, tags, tasks, ticket_replies, ticket_watchers, tickets, trips, users

**ITFlow columns** client_id, client_name, contact_email, contact_name, ticket_client_id, ticket_details, ticket_id, ticket_reply, ticket_reply_by, ticket_reply_id, ticket_reply_ticket_id, ticket_reply_time_worked, ticket_reply_type, ticket_resolved_at, ticket_status_name, ticket_subject, user_id, watcher_ticket_id

**ITFlow page ids / classes its scripts and styles use** `#public_reply_type_opt1` `#replyAudience` `#replyComposer` `#replySubmitLabel` `#startStopTimer` `#ticket_add_reply` `.itflow-ticket-trips`

**Watched in ITFlow's code** `replyComposer` `public_reply_type_opt1` `replyAudience` `replySubmitLabel` `ticket_add_reply` `return ticketID + "-" + suffix` `getLocalStorageKey("startTime")` `getLocalStorageKey("pausedTime")` `ticket-timer-running-` `fa-sliders-h` `fa-layer-group` `fa-map-marker-alt` `ticket-actions` `bulkActionButton` `itflow-ticket-trips`

**"ITFlow does this too" words** ticket_layout, no_response, ticket_timer, time_tracking, list_view, saved_view

**Our files** `functions/mit_ticket_layout.php`, `agent/js/mit_ticket_layout.js`, `includes/mit_timers_nav.php`, `js/mit_timers.js`, `functions/mit_list_views.php`, `functions/mit_no_response.php`, `agent/modals/mit_no_response/no_response.php`, `agent/post/mit_no_response.php`, `admin/includes/mit_no_response_card.php`, `admin/post/mit_no_response_settings.php`

<a id="reply_recipients"></a>
## Email reply "To" line, internal-note Notify (`reply_recipients`)

**Takes for granted**

- add_ticket_reply emails through ticketMailReply; ours replaces it when mit_send_to_present is posted and records ticketReplyEmailed.
- js/itflow_select.js is the picker (multi-select, data-tags); itflowDismissPopups closes pickers on an editor click.
- The parser files mail from an agent's own address in addReply.

**Tests** `tests/smoke/40-reply-recipients.spec.js`

**Hooks in ITFlow's files**

- `agent/ajax.php`: mit_ticket_techs
- `agent/calendar.php`: mit_ticket_techs
- `agent/post/ticket.php`: mitInternalNoteNotify, mitReplyRecipientsMail, mitReplyRecipientsReady, mitTicketClientMoved, mit_reply_recipients, mit_send_to_present, mit_content, mit_resolved, mit_row, mit_subject
- `agent/ticket.php`: mitReplyRecipientsField
- `functions.php`: mit_reply_recipients
- `functions/calendar.php`: mit_ticket_techs
- `functions/ticket.php`: mit_ticket_techs

**ITFlow functions it calls**

- `functions/app.php`: addToMailQueue
- `functions/billing.php`: billingCompany
- `functions/email.php`: emailQueue
- `functions/logging.php`: logTicketHistory
- `functions/sanitize.php`: escapeHtml, escapeSql
- `functions/ticket.php`: ticketColleagueRecipients, ticketEmailFrom, ticketReference, ticketReplyEmailed, ticketUrl
- `functions/user.php`: userClientAccess

**ITFlow tables** contacts, notifications, tags, ticket_replies, ticket_watchers, tickets, user_roles, users

**ITFlow columns** company_name, contact_archived_at, contact_client_id, contact_email, contact_id, contact_name, contact_primary, notification_action, notification_client_id, notification_type, notification_user_id, role_id, role_is_admin, ticket_assigned_to, ticket_client_id, ticket_contact_id, ticket_id, ticket_reply, ticket_reply_id, ticket_subject, user_archived_at, user_email, user_id, user_name, user_role_id, user_status, user_type, watcher_email, watcher_ticket_id

**ITFlow page ids / classes its scripts and styles use** `#replyAudience` `#ticket_add_reply`

**Watched in ITFlow's code** `ticketMailReply` `ticketReplyEmailed` `ticketColleagueRecipients` `itflowDismissPopups` `data-tags`

**"ITFlow does this too" words** reply_recipient, ticket_recipient, collaborator, ticket_cc, reply_to, internal_notify

**Our files** `functions/mit_reply_recipients.php`, `agent/js/mit_reply_recipients.js`

<a id="reply_times"></a>
## Times on ticket notes (start / end) (`reply_times`)

**Takes for granted**

- ticket_replies.ticket_reply_time_worked holds the length; the timer fields are #hours / #minutes / #seconds.
- flashAlert keeps one message (mitReplyTimesFlash adds the time warning back).

**Tests** `tests/smoke/09-note-times.spec.js`, `tests/smoke/45-note-time-locks.spec.js`, `tests/smoke/74-ts-ticket-moves.spec.js`

**Hooks in ITFlow's files**

- `agent/modals/ticket/ticket_reply_edit.php`: mitReplyTimesEditField
- `agent/post/ticket.php`: mitReplyTimesFlash, mitReplyTimesFromEdit, mitReplyTimesFromPost, mitReplyTimesSavePending, mitTsKeepPublicNotes
- `agent/ticket.php`: mitReplyTimesField, mitReplyTimesText
- `functions.php`: mit_timesheets
- `functions/reports.php`: mit_timesheets
- `includes/cron_jobs.php`: mit_timesheets

**ITFlow functions it calls**

- `functions/auth.php`: hasClientAccess, lookupUserPermission
- `functions/logging.php`: logAudit
- `functions/request.php`: flashAlert, redirect
- `functions/sanitize.php`: escapeHtml, escapeSql
- `functions/ticket.php`: ticketReference

**ITFlow tables** clients, notifications, ticket_replies, tickets

**ITFlow columns** client_id, client_name, notification_timestamp, notification_type, notification_user_id, ticket_client_id, ticket_id, ticket_number, ticket_prefix, ticket_reply_archived_at, ticket_reply_by, ticket_reply_created_at, ticket_reply_id, ticket_reply_ticket_id, ticket_reply_time_worked, ticket_reply_type, ticket_subject, user_id

**ITFlow page ids / classes its scripts and styles use** `#hours` `#minutes` `#seconds` `#startStopTimer` `#ticket_add_reply` `#ticket_reply_status`

**Watched in ITFlow's code** `ticket_reply_time_worked` `#hours` `#minutes` `#seconds` `flashAlert`

**"ITFlow does this too" words** work_start, work_end, time_start, started_at

**Our files** `functions/mit_reply_times.php`, `agent/js/mit_reply_times.js`

<a id="quotes"></a>
## Quote pricing (cost, markup / margin) and line images (`quotes`)

**Takes for granted**

- Quotes / invoices / recurring are one page: lines saved by agent/post/billing.php (billing_item_save ...), drawn by includes/billing_paper.php.
- products.product_cost / product_vendor_id are ITFlow's (0 = none); quote_items keep our item_cost / price method / image.
- PDFs by functions/billing_pdf.php.

**Tests** `tests/smoke/60-quote-pricing.spec.js`, `tests/smoke/65-quote-images.spec.js`, `tests/scenarios/scenario-quote-convert-same-name.php`, `tests/scenarios/scenario-quote-pricing.php`

**Hooks in ITFlow's files**

- `admin/settings_quote.php`: mit_quote_card
- `agent/invoice.php`: mitPaperLineExtras, mit_quotes, mit_staff
- `agent/modals/billing/billing_item.php`: mitQuoteLineFields
- `agent/modals/product/product_form.php`: mitProductImageField
- `agent/post/billing.php`: mitImageErrorFlash, mitQuoteItemDelete, mitQuoteItemSave
- `agent/post/client.php`: mitQuoteImagesDelete
- `agent/post/product.php`: mitImageErrorFlash, mitProductDelete, mitProductSave
- `agent/post/quote.php`: mitQuoteItemCopy, mitQuoteItemDelete
- `agent/quote.php`: mitPaperLineExtras, mitQuoteProfitBox, mit_quotes, mit_staff
- `api/v1/clients/delete.php`: mitQuoteImagesDelete
- `api/v1/products/read.php`: mitProductApiColumns
- `functions.php`: mit_quotes
- `functions/billing_pdf.php`: mitQuotePdfImage
- `includes/billing_paper.php`: mitPaperAddFields, mitPaperLineExtras, mitPaperLineImage

**ITFlow functions it calls**

- `functions/app.php`: getFieldById
- `functions/billing.php`: billingMoney, quoteFrequency
- `functions/logging.php`: logAudit
- `functions/request.php`: flashAlert, redirect
- `functions/sanitize.php`: escapeHtml
- `functions/security.php`: validateCSRFToken

**ITFlow tables** files, products, quote_items, quotes, settings

**ITFlow columns** invoice_id, item_frequency, item_id, item_price, item_product_id, item_quantity, item_quote_id, product_archived_at, product_cost, product_id, quote_currency_code, quote_discount_amount, quote_id

**Watched in ITFlow's code** `billing_item_save` `billingAddLine` `billing_paper` `product_cost`

**"ITFlow does this too" words** margin, markup, item_cost, product_cost, item_image, product_image, price_method

**Our files** `functions/mit_quotes.php`, `js/mit_quote_pricing.js`, `admin/includes/mit_quote_card.php`, `admin/post/mit_quote_settings.php`

<a id="procurement"></a>
## Procurement (to order, POs, receiving, ticket parts, unbilled parts, vendor bills) (`procurement`)

**Takes for granted**

- Stock moves through ITFlow's productStockMove / product_stock (productStockShortfall, productStockReturnInvoice).
- Quotes become invoices by quoteConvert (quoteLineGroups); invoices by billingInvoiceCreate / billingRecalc / billingLineTax.
- Expenses by expenseSave; assets carry asset_invoice_id / _item_id / _product_id.

**Tests** `tests/smoke/62-procurement-roles.spec.js`, `tests/smoke/63-parts-and-invoice-orders.spec.js`, `tests/smoke/60-quote-pricing.spec.js`, `tests/scenarios/scenario-procurement-flow.php`, `tests/scenarios/scenario-procurement-stock.php`

**Hooks in ITFlow's files**

- `agent/includes/side_nav.php`: mit_procurement_nav
- `agent/invoice.php`: mitProcInvoiceMenuItem, mitProcInvoiceSummary, mit_quotes
- `agent/modals/quote/quote_to_invoice.php`: mitProcQuoteConvertFields
- `agent/post/client.php`: mitTicketPartsDeleting
- `agent/post/quote.php`: mitProcAfterQuoteConvert
- `agent/project.php`: mitProcProjectCard
- `agent/quote.php`: mit_quotes
- `agent/ticket.php`: mitTicketPartsCard
- `api/v1/clients/delete.php`: mitTicketPartsDeleting
- `functions.php`: mit_procurement, mit_quotes
- `functions/billing.php`: mitProcQuoteConvertGroups
- `functions/ticket.php`: mitTicketPartsDeleting
- `includes/cron_jobs.php`: mit_procurement_parts, mit_procurement_tidy

**ITFlow functions it calls**

- `cron/includes/cron_lock.php`: cronJobStop
- `functions/app.php`: addToMailQueue, getFieldById
- `functions/auth.php`: clientScopeSql, enforceUserPermission, hasClientAccess, lookupUserPermission
- `functions/billing.php`: billingInvoiceCreate, billingItems, billingLineTax, billingMoney, billingRecalc
- `functions/expenses.php`: expenseSave
- `functions/format.php`: formatPhoneNumber, timeAgo
- `functions/logging.php`: logAudit, logTicketHistory
- `functions/products.php`: productStockMove
- `functions/request.php`: flashAlert, redirect
- `functions/sanitize.php`: escapeHtml, escapeSql
- `functions/security.php`: randomString, validateCSRFToken
- `functions/ticket.php`: ticketForModal, ticketForRequest, ticketReference

**ITFlow tables** accounts, asset_notes, assets, categories, clients, companies, expenses, history, invoice_items, invoices, modules, notifications, product_stock, products, projects, quote_items, settings, taxes, ticket_assets, ticket_replies, tickets, user_role_permissions, user_roles, users, vendors

**ITFlow columns** account_archived_at, account_id, account_name, asset_archived_at, asset_client_id, asset_id, asset_invoice_id, asset_invoice_item_id, asset_make, asset_model, asset_name, asset_notes, asset_product_id, asset_purchase_date, asset_purchase_reference, asset_serial, asset_status, asset_type, asset_vendor_id, category_archived_at, category_id, category_name, category_type, client_archived_at, client_id, client_name, client_net_terms, company_address, company_city, company_email, company_id, company_logo, company_name, company_phone, company_phone_country_code, company_state, company_zip, config_enable_cron, config_invoice_next_number, expense_account_id, expense_amount, expense_archived_at, expense_category_id, expense_client_id, expense_currency_code, expense_date, expense_description, expense_id, expense_reference, expense_vendor_id, history_description, history_id, history_invoice_id, history_status, invoice_amount, invoice_category_id, invoice_client_id, invoice_currency_code, invoice_date, invoice_discount_amount, invoice_due, invoice_id, invoice_number, invoice_prefix, invoice_scope, invoice_status, invoice_url_key, item_description, item_frequency, item_id, item_invoice_id, item_name, item_order, item_price, item_product_id, item_quantity, item_quote_id, item_subtotal, item_tax, item_tax_id, item_total, module_id, module_name, notification_action, notification_entity_id, notification_type, notification_user_id, product_archived_at, product_cost, product_description, product_id, product_name, product_price, product_tax_id, product_track_stock, product_type, product_vendor_id, project_archived_at, project_client_id, project_completed_at, project_id, project_name, project_number, project_prefix, role_id, role_is_admin, stock_expense_id, stock_invoice_id, stock_item_id, stock_note, stock_product_id, stock_qty, stock_type, tax_archived_at, tax_id, tax_name, tax_percent, ticket_client_id, ticket_closed_at, ticket_id, ticket_number, ticket_prefix, ticket_project_id, ticket_reply, ticket_reply_by, ticket_reply_ticket_id, ticket_reply_time_worked, ticket_reply_type, ticket_resolved_at, ticket_subject, user_archived_at, user_email, user_id, user_name, user_role_id, user_role_permission_level, user_status, user_type, vendor_account_number, vendor_archived_at, vendor_client_id, vendor_contact_email, vendor_contact_name, vendor_email, vendor_id, vendor_name, vendor_phone

**Watched in ITFlow's code** `productStockMove` `quoteConvert` `quoteLineGroups` `billingInvoiceCreate` `expenseSave` `productStockShortfall`

**"ITFlow does this too" words** purchase_order, purchase, procure, receiv, po_, stock, vendor, reorder, unbilled

**Our files** `functions/mit_procurement.php`, `functions/mit_procurement_extras.php`, `functions/mit_procurement_parts.php`, `agent/mit_procurement.php`, `agent/mit_purchase_order.php`, `agent/mit_purchase_orders.php`, `agent/mit_receiving.php`, `agent/mit_unbilled_parts.php`, `agent/modals/mit_procurement/from_invoice.php`, `agent/modals/mit_procurement/item.php`, `agent/modals/mit_procurement/part.php`, `agent/modals/mit_procurement/parts_bill.php`, `agent/modals/mit_procurement/po_action.php`, `agent/post/mit_procurement.php`, `agent/post/mit_procurement_parts.php`, `agent/includes/mit_procurement_nav.php`, `admin/mit_procurement_settings.php`, `admin/includes/mit_procurement_card.php`, `admin/post/mit_procurement_settings.php`, `cron/mit_procurement_tidy.php`

<a id="timesheets"></a>
## Timesheets, payroll, leave, California rules, holidays, reimbursements (`timesheets`)

**Takes for granted**

- Ticket time = ticket_replies.ticket_reply_time_worked by ticket_reply_by; billable by tickets.ticket_billable.
- Mileage is ITFlow trips (trip_own_vehicle, tripClaim, config_mileage_rate); purchases are ITFlow expenses (expense_paid_by, expenseReimburse, reimbursementDelete).
- Closure days are business_holidays (getBusinessHolidays, restampOpenSlaTickets).
- change_client_ticket turns public replies internal (mitTsKeepPublicNotes runs first).

**Tests** `tests/smoke/04-permissions.spec.js`, `tests/smoke/05-california.spec.js`, `tests/smoke/19-own-employment.spec.js`, `tests/smoke/29-payroll-payback.spec.js`, `tests/smoke/31-rest-breaks.spec.js`, `tests/smoke/70-ts-permissions.spec.js`, `tests/smoke/71-payroll-numbers.spec.js`, `tests/smoke/72-module-switches.spec.js`, `tests/smoke/73-restore-roundtrip.spec.js`, `tests/smoke/74-ts-ticket-moves.spec.js`, `tests/smoke/75-ts-admin-screens.spec.js`, `tests/smoke/76-trip-locks.spec.js`, `tests/smoke/77-sign-in-security.spec.js`, `tests/smoke/78-portal-sessions.spec.js`, `tests/smoke/79-platform-pages.spec.js`, `tests/scenarios/scenario-ts-approval-off.php`, `tests/scenarios/scenario-ts-california.php`, `tests/scenarios/scenario-ts-holidays.php`, `tests/scenarios/scenario-ts-leave.php`, `tests/scenarios/scenario-ts-misc.php`, `tests/scenarios/scenario-ts-payroll-payback.php`, `tests/scenarios/scenario-ts-period-hourly.php`, `tests/scenarios/scenario-ts-zone-switch-back.php`, `tests/scenarios/fixture-hourly.php`

**Hooks in ITFlow's files**

- `admin/modals/user/user_form.php`: mitTsPayTypeField
- `admin/post/users.php`: mitTsSavePayType
- `agent/ajax.php`: mit_ticket_layout
- `agent/includes/side_nav.php`: mit_timesheet_nav
- `agent/post/client.php`: mitTsDeleteGuard
- `agent/post/ticket.php`: mitTsReplyTimeGuard
- `agent/post/trip.php`: mitReimbTripLocked, mitReimbTripWhyLocked, mit_why
- `agent/ticket.php`: mitReimbMenuItem, mitReimbTicketCard, mit_ticket_layout
- `agent/user/post/profile.php`: mitTsSavePreferences
- `agent/user/user_preferences.php`: mitTsPreferenceFields
- `api/v1/clients/delete.php`: mitTsDeleteBlocked
- `functions.php`: mit_ticket_layout, mit_timesheets
- `functions/backup.php`: mit_receipts
- `functions/expenses.php`: mitReimbExpenseWhyLocked, mitReimbReimbursementWhyLocked
- `functions/reports.php`: mitTsReportEntry, mit_payroll, mit_timesheets
- `functions/ticket.php`: mitTsDeleteGuard
- `includes/cron_jobs.php`: mit_timesheets

**ITFlow functions it calls**

- `cron/includes/cron_lock.php`: cronJobStop
- `functions/app.php`: addToMailQueue, getFieldById
- `functions/auth.php`: enforceUserPermission, hasClientAccess, lookupUserPermission
- `functions/billing.php`: billingMoney, paymentAccounts
- `functions/calendar.php`: calendarIsDate
- `functions/expenses.php`: expenseDelete, expenseLabel, expenseReimburse, reimbursementDelete, reimbursementExpenses
- `functions/format.php`: timeAgo
- `functions/logging.php`: logAudit, logTicketHistory
- `functions/reconcile.php`: reconcileCheckDelete
- `functions/reports.php`: reportAgentCell, reportAgentId, reportCan, reportChart, reportColumnIsNumber, reportCount, reportCsv, reportEnforce, reportExporting, reportHeader, reportHours, reportMoney, reportPercent, reportPeriod, reportSectionOpen, reportStats, reportTable, reportUrl, reportWorkdayHours
- `functions/request.php`: flashAlert, getIP, redirect
- `functions/sanitize.php`: checkFileUpload, escapeHtml, escapeSql
- `functions/security.php`: randomString, validateCSRFToken
- `functions/sla.php`: getBusinessHolidays, getSlaSettings, restampOpenSlaTickets, usFederalHolidays
- `functions/ticket.php`: ticketForModal, ticketForRequest, ticketLink, ticketReference, ticketRow
- `functions/trips.php`: tripClaim, tripRules

**ITFlow tables** accounts, business_holidays, categories, clients, companies, credentials, expenses, history, modules, notifications, reimbursements, settings, tags, ticket_replies, tickets, trips, user_role_permissions, user_roles, users, vendors

**ITFlow columns** account_archived_at, account_id, account_name, category_archived_at, category_id, category_name, category_type, client_id, client_name, company_currency, company_id, config_enable_cron, config_mileage_rate, expense_account_id, expense_amount, expense_archived_at, expense_category_id, expense_client_id, expense_currency_code, expense_date, expense_description, expense_id, expense_paid_by, expense_receipt, expense_reference, expense_reimbursement_id, expense_vendor_id, holiday_date, holiday_id, holiday_name, invoice_id, module_id, module_name, notification_action, notification_entity_id, notification_timestamp, notification_type, notification_user_id, reimbursement_date, reimbursement_expense_id, reimbursement_id, reimbursement_reference, reimbursement_user_id, role_archived_at, role_id, role_is_admin, role_name, round_trip, ticket_billable, ticket_client_id, ticket_closed_at, ticket_id, ticket_number, ticket_prefix, ticket_reply_archived_at, ticket_reply_by, ticket_reply_created_at, ticket_reply_id, ticket_reply_ticket_id, ticket_reply_time_worked, ticket_reply_type, ticket_subject, trip_archived_at, trip_client_id, trip_date, trip_destination, trip_expense_id, trip_id, trip_miles, trip_own_vehicle, trip_purpose, trip_source, trip_ticket_id, trip_user_id, user_archived_at, user_avatar, user_created_at, user_email, user_id, user_name, user_role_id, user_role_permission_level, user_status, user_type, vendor_archived_at, vendor_client_id, vendor_description, vendor_id, vendor_name

**Watched in ITFlow's code** `ticket_reply_time_worked` `trip_own_vehicle` `tripClaim` `expenseReimburse` `reimbursementDelete` `expense_paid_by` `config_mileage_rate` `getBusinessHolidays`

**"ITFlow does this too" words** timesheet, payroll, leave, pto, overtime, time_entr, clock, reimburse, mileage, trip, holiday, pay_period

**Our files** `functions/mit_timesheet_view.php`, `functions/mit_timesheets.php`, `functions/mit_ts_california.php`, `functions/mit_ts_holidays.php`, `functions/mit_leave.php`, `functions/mit_reimbursements.php`, `agent/mit_timesheet.php`, `agent/mit_timesheet_ajax.php`, `agent/mit_timesheets.php`, `agent/js/mit_timesheet.js`, `agent/modals/mit_timesheet/day.php`, `agent/modals/mit_timesheet/decide.php`, `agent/modals/mit_timesheet/leave.php`, `agent/modals/mit_reimburse/reimburse.php`, `agent/post/mit_timesheets.php`, `agent/post/mit_reimbursements.php`, `agent/mit_receipt.php`, `agent/reports/mit_payroll.php`, `agent/includes/mit_timesheet_nav.php`, `admin/mit_timesheets.php`, `admin/modals/mit_timesheets/charge_code.php`, `admin/modals/mit_timesheets/holiday.php`, `admin/modals/mit_timesheets/leave_bank.php`, `admin/modals/mit_timesheets/person.php`, `admin/modals/mit_timesheets/person_leave.php`, `admin/post/mit_timesheets.php`, `cron/mit_timesheets.php`

<a id="qbo"></a>
## QuickBooks Online (approvals, batches, locks, payments back) (`qbo`)

**Takes for granted**

- Every way an invoice or its payments change goes through a guarded handler (agent/post/invoice.php, billing.php, payment.php, ajax.php, API, nightly, portal, guest pay, bank import, reprice).
- Payments: payments table + updateInvoiceStatusFromPayments; Stripe booking in billingStripeBook.
- Recurring invoices by recurringInvoiceGenerate (auto-send condition).

**Tests** `tests/smoke/10-qbo.spec.js`, `tests/smoke/61-qbo-guards.spec.js`, `tests/smoke/66-qbo-more-doors.spec.js`, `tests/scenarios/scenario-qbo-flow.php`, `tests/scenarios/scenario-qbo-nightly.php`, `tests/scenarios/scenario-qbo-payments.php`

**Hooks in ITFlow's files**

- `agent/ajax.php`: mitQboInvoiceLock
- `agent/invoice.php`: mitQboInvoiceBar
- `agent/invoices.php`: mitQboListBadge, mit_qbo_ids
- `agent/post/billing.php`: mitQboGuardInvoice, mitQboInvoiceLock
- `agent/post/client.php`: mitQboGuardClientDelete
- `agent/post/expense.php`: mitQboGuardInvoice
- `agent/post/invoice.php`: mitQboGuardEmail, mitQboGuardInvoice
- `agent/post/payment.php`: mitQboGuardInvoice, mitQboGuardPayment, mitQboGuardPaymentEdit, mitQboTakesPayments
- `agent/post/ticket.php`: mitQboGuardInvoice
- `agent/post/trip.php`: mitQboGuardInvoice
- `api/v1/clients/delete.php`: mitQboClientLock
- `api/v1/invoice_items/create.php`: mitQboInvoiceLock
- `client/pay.php`: mitQboTakesPayments
- `client/post.php`: mitQboGuardPayment, mit_invoice_id
- `cron/mail_queue.php`: mit_dev_mode
- `cron/nightly_tasks.php`: mitQboSendsReminders, mitQboTakesPayments
- `functions.php`: mit_qbo
- `functions/billing.php`: mitQboHandlesInvoiceMail, mit_hold
- `functions/billing_pay.php`: mitQboCardPaymentBooked
- `functions/income_import.php`: mitQboTakesPayments
- `functions/products.php`: mitQboInvoiceLock
- `guest/guest_ajax.php`: mitQboTakesPayments
- `guest/guest_pay_invoice_stripe.php`: mitQboCardPaymentBooked
- `includes/cron_jobs.php`: mit_qbo_billing, mit_qbo_sync

**ITFlow functions it calls**

- `cron/includes/cron_lock.php`: cronJobStop
- `functions/app.php`: getFieldById
- `functions/auth.php`: clientScopeSql, enforceUserPermission, lookupUserPermission
- `functions/logging.php`: appNotify, logAudit
- `functions/payments.php`: updateInvoiceStatusFromPayments
- `functions/reconcile.php`: reconcileLock
- `functions/request.php`: flashAlert, redirect
- `functions/sanitize.php`: escapeHtml, escapeSql
- `functions/security.php`: validateCSRFToken
- `functions/tax.php`: taxSettings

**ITFlow tables** accounts, clients, companies, contacts, history, invoice_items, invoices, payments, products, settings, taxes, users

**ITFlow columns** account_archived_at, account_id, account_name, client_archived_at, client_id, client_name, company_currency, company_id, company_name, config_enable_cron, contact_archived_at, contact_billing, contact_client_id, contact_email, contact_name, contact_primary, history_description, history_invoice_id, history_status, invoice_amount, invoice_archived_at, invoice_client_id, invoice_credit_amount, invoice_currency_code, invoice_date, invoice_discount_amount, invoice_due, invoice_id, invoice_note, invoice_number, invoice_prefix, invoice_scope, invoice_status, item_archived_at, item_description, item_id, item_invoice_id, item_name, item_order, item_price, item_product_id, item_quantity, item_tax_id, item_total, payment_account_id, payment_amount, payment_currency_code, payment_date, payment_deposit_transfer_id, payment_id, payment_invoice_id, payment_method, payment_reconciliation_id, payment_reference, product_archived_at, product_id, product_name, product_price, tax_archived_at, tax_id, tax_name, tax_percent, user_id, user_name

**Watched in ITFlow's code** `updateInvoiceStatusFromPayments` `billingStripeBook` `recurringInvoiceGenerate` `productRepriceApply` `income_import`

**"ITFlow does this too" words** quickbooks, qbo, xero, accounting_sync, intuit

**Our files** `functions/mit_qbo.php`, `functions/mit_qbo_billing.php`, `admin/mit_qbo.php`, `admin/mit_qbo_callback.php`, `admin/post/mit_qbo.php`, `agent/mit_invoice_approvals.php`, `agent/mit_qbo_batch.php`, `agent/mit_qbo_batches.php`, `agent/post/mit_qbo.php`, `cron/mit_qbo_sync.php`

<a id="usage"></a>
## Usage billing (counts on recurring lines, connectors, API) (`usage`)

**Takes for granted**

- recurringInvoiceGenerate copies recurring lines in item_order; usage sets their quantity before auto-send.
- api/v1/enforce_api_rbac.php maps endpoints to modules.

**Tests** `tests/smoke/11-usage.spec.js`, `tests/smoke/12-connectors.spec.js`, `tests/scenarios/scenario-usage.php`

**Hooks in ITFlow's files**

- `admin/settings_invoice.php`: mit_usage_card
- `agent/client_overview.php`: mitUsageClientCard
- `agent/recurring_invoice.php`: mitUsageRecurringCard
- `api/v1/enforce_api_rbac.php`: mit_usage
- `functions.php`: mit_usage
- `functions/billing.php`: mitUsageApply, mit_hold
- `includes/cron_jobs.php`: mit_qbo_billing, mit_usage

**ITFlow functions it calls**

- `api/v1/enforce_api_rbac.php`: apiClientScopeSql
- `cron/includes/cron_lock.php`: cronJobStop
- `functions/app.php`: getFieldById
- `functions/auth.php`: clientScopeSql, enforceClientAccess, enforceUserPermission, lookupUserPermission
- `functions/billing.php`: billingLineTax, billingRecalc
- `functions/logging.php`: logAudit
- `functions/request.php`: flashAlert, redirect
- `functions/sanitize.php`: escapeHtml, escapeSql
- `functions/security.php`: validateCSRFToken

**ITFlow tables** clients, contact_tags, contacts, history, invoice_items, invoices, products, recurring_invoice_items, recurring_invoices, settings, tags, taxes, users

**ITFlow columns** client_archived_at, client_id, client_name, company_id, config_enable_cron, contact_archived_at, contact_client_id, contact_id, history_description, history_invoice_id, history_status, invoice_amount, invoice_archived_at, invoice_client_id, invoice_currency_code, invoice_date, invoice_discount_amount, invoice_id, invoice_number, invoice_prefix, invoice_status, item_archived_at, item_id, item_invoice_id, item_name, item_order, item_price, item_quantity, item_recurring_invoice_id, item_seat_source, item_subtotal, item_tax, item_tax_id, item_total, recurring_invoice_archived_at, recurring_invoice_client_id, recurring_invoice_id, recurring_invoice_number, recurring_invoice_prefix, recurring_invoice_status, tag_archived_at, tag_id, tag_name, tag_type, tax_id, tax_percent, user_id, user_name

**Watched in ITFlow's code** `item_order` `item_seat_source` `enforce_api_rbac`

**"ITFlow does this too" words** usage_line, usage_count, seat, metered, quantity_source

**Our files** `functions/mit_usage.php`, `functions/mit_usage_connectors.php`, `agent/mit_usage_review.php`, `agent/post/mit_usage.php`, `admin/includes/mit_usage_card.php`, `admin/post/mit_usage_connectors.php`, `admin/post/mit_usage_settings.php`, `admin/mit_usage_connectors.php`, `api/v1/mit_usage/create.php`, `api/v1/mit_usage/read.php`, `cron/mit_usage.php`

<a id="ninjaone"></a>
## NinjaOne RMM sync and live status (`ninjaone`)

**Takes for granted**

- Assets table and its columns (serial, make, model, os, ip ...); asset links look like modals/asset/asset.php?...id=

**Tests** `tests/smoke/22-ninjaone-sync.spec.js`

**Hooks in ITFlow's files**

- `agent/asset.php`: mitNinjaAssetButton
- `agent/modals/asset/asset.php`: mitNinjaAssetButton
- `functions.php`: mit_ninjaone
- `includes/cron_jobs.php`: mit_ninjaone, mit_ninjaone_sync

**ITFlow functions it calls**

- `cron/includes/cron_lock.php`: cronJobStop
- `functions/app.php`: getFieldById
- `functions/auth.php`: clientScopeSql, lookupUserPermission
- `functions/format.php`: timeAgo
- `functions/logging.php`: logAudit
- `functions/request.php`: flashAlert, redirect
- `functions/sanitize.php`: escapeHtml, escapeSql
- `functions/security.php`: validateCSRFToken

**ITFlow tables** asset_interfaces, asset_notes, assets, clients, credentials, settings, tickets, users

**ITFlow columns** asset_archived_at, asset_client_id, asset_id, asset_make, asset_model, asset_name, asset_notes, asset_os, asset_purchase_date, asset_serial, asset_status, asset_type, asset_warranty_expire, client_archived_at, client_id, client_name, company_id, config_enable_cron, interface_asset_id, interface_id, interface_ip, interface_mac, interface_name, interface_primary, user_id, user_name

**Watched in ITFlow's code** `modals/asset/asset.php`

**"ITFlow does this too" words** ninja, rmm, device_sync

**Our files** `functions/mit_ninjaone.php`, `admin/mit_ninjaone.php`, `admin/post/mit_ninjaone.php`, `agent/js/mit_ninjaone.js`, `agent/mit_ninjaone_ajax.php`, `cron/mit_ninjaone_sync.php`

<a id="customer_thermometer"></a>
## Customer Thermometer surveys (`customer_thermometer`)

**Takes for granted**

- Resolved / closed tickets by ticket_resolved_at / ticket_closed_at; ticketMergeInto writes "merged this ticket into" history.

**Tests** `tests/smoke/18-customer-thermometer.spec.js`

**Hooks in ITFlow's files**

- `agent/client_overview.php`: mitCtClientCard, mit_align_card
- `agent/dashboard.php`: mitCtDashCard
- `functions.php`: mit_ct
- `includes/cron_jobs.php`: mit_ct, mit_customer_thermometer

**ITFlow functions it calls**

- `cron/includes/cron_lock.php`: cronJobStop
- `functions/app.php`: getFieldById
- `functions/auth.php`: clientScopeSql, enforceUserPermission, hasClientAccess, lookupUserPermission
- `functions/format.php`: selectOptionClient
- `functions/logging.php`: logAudit, logTicketHistory
- `functions/request.php`: flashAlert, redirect
- `functions/sanitize.php`: escapeHtml, escapeSql
- `functions/security.php`: validateCSRFToken
- `functions/ticket.php`: ticketReference

**ITFlow tables** clients, contacts, credits, settings, ticket_history, tickets, users

**ITFlow columns** client_abbreviation, client_archived_at, client_id, client_name, company_id, config_enable_cron, contact_archived_at, contact_email, contact_id, contact_name, ticket_archived_at, ticket_assigned_to, ticket_client_id, ticket_closed_at, ticket_contact_id, ticket_history_description, ticket_history_ticket_id, ticket_id, ticket_number, ticket_prefix, ticket_resolved_at, ticket_subject, user_id, user_name

**Watched in ITFlow's code** `merged this ticket into`

**"ITFlow does this too" words** survey, feedback, csat, satisfaction, rating

**Our files** `functions/mit_ct.php`, `admin/mit_customer_thermometer.php`, `admin/post/mit_customer_thermometer.php`, `agent/mit_ct_reviews.php`, `cron/mit_customer_thermometer.php`

<a id="align"></a>
## MSP Align (read only) (`align`)

**Takes for granted**

- Client overview's Needs attention block and the dashboard's $attention items.

**Tests** `tests/smoke/27-align.spec.js`

**Hooks in ITFlow's files**

- `agent/asset.php`: mitAlignAssetPanel
- `agent/assets.php`: mitAlignAssetBadge
- `agent/client_overview.php`: mitAlignClientCard, mitAlignReplacesAttention, mit_align_card
- `agent/dashboard.php`: mitAlignDashCard, mit_align_dash
- `agent/includes/inc_client_top_head.php`: mitAlignClientButton
- `agent/modals/asset/asset.php`: mitAlignAssetPanel
- `agent/software.php`: mitAlignLicenseLine
- `functions.php`: mit_align
- `includes/cron_jobs.php`: mit_align, mit_align_sync

**ITFlow functions it calls**

- `cron/includes/cron_lock.php`: cronJobStop
- `functions/auth.php`: clientScopeSql
- `functions/client_overview.php`: clientOverviewSlaBreachedSql
- `functions/dashboard.php`: dashboardCount
- `functions/logging.php`: logAudit
- `functions/request.php`: flashAlert, redirect
- `functions/sanitize.php`: escapeHtml, escapeSql
- `functions/security.php`: validateCSRFToken

**ITFlow tables** assets, backups, budget, clients, settings, software, tickets

**ITFlow columns** asset_id, client_archived_at, client_id, client_name, company_id, config_enable_cron, software_id, ticket_client_id, ticket_closed_at, ticket_id, ticket_resolved_at

**Watched in ITFlow's code** `Needs attention`

**"ITFlow does this too" words** align, vcio, lifecycle

**Our files** `functions/mit_align.php`, `admin/mit_align.php`, `admin/post/mit_align.php`, `cron/mit_align_sync.php`

<a id="ai_helper"></a>
## AI helper (library, Ask panel, write-up, local embeddings) - behind the password wall (`ai_helper`)

**Takes for granted**

- ITFlow's AI providers (ai_providers, aiProviderRow, aiProviderHeaders, callAiApi, getAiModel, aiCan, aiReady, aiRedact, aiCleanHtml, aiDocumentSplit).
- Documents: add_document handler and modals/document/document_form.php; archived = *_archived_at.

**Tests** `tests/smoke/32-ai-helper.spec.js`, `tests/smoke/33-ai-panel.spec.js`, `tests/smoke/34-ai-document.spec.js`, `tests/smoke/35-ai-local.spec.js`, `tests/scenarios/scenario-ai-ask.php`, `tests/scenarios/scenario-ai-document.php`, `tests/scenarios/scenario-ai-documents.php`, `tests/scenarios/scenario-ai-library.php`, `tests/scenarios/scenario-ai-local.php`, `tests/scenarios/scenario-ai-scrub.php`, `tests/scenarios/scenario-ai-wall.php`

**Hooks in ITFlow's files**

- `functions.php`: mit_ai_helper
- `includes/cron_jobs.php`: mit_ai_helper, mit_ai_index
- `includes/top_nav.php`: mit_timers_nav

**ITFlow functions it calls**

- `cron/includes/cron_lock.php`: cronJobStop
- `functions/ai.php`: aiCan, aiCleanHtml, aiProviderHeaders, aiProviderRow, aiProviderUrl, aiReady, aiRedact, aiUsageRecord, callAiApi, getAiModel
- `functions/ai_document.php`: aiDocumentSplit
- `functions/ai_ticket.php`: aiHtmlToText
- `functions/auth.php`: clientScopeSql, hasClientAccess, lookupUserPermission, permissionRefuse
- `functions/logging.php`: logAudit
- `functions/request.php`: flashAlert, redirect
- `functions/sanitize.php`: escapeHtml, escapeSql
- `functions/security.php`: validateCSRFToken
- `functions/ticket.php`: ticketUrl

**ITFlow tables** ai_models, ai_providers, ai_usage, clients, documents, folders, history, settings, tags, ticket_replies, tickets

**ITFlow columns** ai_model_name, ai_provider_api_key, ai_provider_api_url, ai_provider_id, ai_provider_kind, ai_provider_name, ai_provider_redact, ai_usage_created_at, ai_usage_feature, ai_usage_tokens_in, ai_usage_tokens_out, client_archived_at, client_id, client_name, company_id, config_enable_cron, document_archived_at, document_client_id, document_content, document_created_at, document_description, document_id, document_name, document_updated_at, ticket_archived_at, ticket_client_id, ticket_closed_at, ticket_created_at, ticket_details, ticket_id, ticket_number, ticket_prefix, ticket_reply, ticket_reply_archived_at, ticket_reply_created_at, ticket_reply_id, ticket_reply_ticket_id, ticket_reply_type, ticket_reply_updated_at, ticket_resolved_at, ticket_subject, ticket_updated_at, user_id

**Watched in ITFlow's code** `callAiApi` `aiRedact` `getAiModel` `aiCleanHtml` `aiDocumentSplit` `document_form.php`

**"ITFlow does this too" words** embedding, knowledge, assistant, copilot, ai_search, ai_chat, ai_ask, ai_answer

**Our files** `functions/mit_ai_ask.php`, `functions/mit_ai_docs.php`, `functions/mit_ai_helper.php`, `functions/mit_ai_local.php`, `admin/mit_ai_helper.php`, `admin/post/mit_ai_helper.php`, `agent/mit_ai_ajax.php`, `agent/js/mit_ai_helper.js`, `agent/modals/mit_ai/document.php`, `cron/mit_ai_index.php`
