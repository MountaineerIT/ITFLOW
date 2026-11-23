# Mountaineer IT - feature map

Written by `php scripts/mit_feature_map.php` from `scripts/mit_features.php` and the code - don't edit by hand.
Each feature of ours, the tests that prove it, what it takes for granted about ITFlow, and what it uses of ITFlow's code
(functions called, tables and columns named, ITFlow files that carry its hooks, page ids / ITFlow classes its scripts look for).
`php scripts/mit_upstream_impact.php` reads this to say which features an ITFlow update touches.

| Feature | Our files | Tests | ITFlow functions | Tables | Columns | ITFlow files hooked |
|---|---|---|---|---|---|---|
| [Shared base (helpers, database updates, version, menus, module switches)](#platform) | 79 | 14 | 12 | 45 | 233 | 40 |
| [Our checks (hooks, consistency, portability, AI wall, verify, feature map)](#checks) | 192 | 2 | 2 | 15 | 42 | 6 |
| [Development mode (a production copy on a test server)](#dev_mode) | 1 | 2 | 2 | 0 | 0 | 4 |
| [Restore from Admin > Backup](#backup_restore) | 1 | 1 | 2 | 0 | 0 | 3 |
| [Cron health (warning, emails, /mit_health.php)](#cron_health) | 3 | 2 | 2 | 0 | 0 | 7 |
| [Modern look, logo options, sign-in screen, client tiles, time fields, portal tables](#theme) | 13 | 7 | 12 | 13 | 23 | 15 |
| [Email look: logo inside the message, resolved wording, notification switches](#emails) | 2 | 2 | 8 | 7 | 18 | 6 |
| [Calendar (agent colours, booking ends, conflicts, Left behind, 7 days, weekday repeats, event agents, holidays, own time zone)](#calendar) | 4 | 11 | 12 | 2 | 3 | 11 |
| [Microsoft 365 app, Graph mail, Outlook calendar per tech](#outlook_m365) | 12 | 6 | 13 | 9 | 43 | 14 |
| [Additional techs (helpers) on tickets](#ticket_techs) | 1 | 3 | 7 | 9 | 38 | 9 |
| [Dispatch board, Workload, This week](#dispatch) | 4 | 5 | 30 | 12 | 46 | 10 |
| [Service boards (routing, escalation, round-robin, by role, pickers, report)](#boards) | 10 | 8 | 51 | 11 | 51 | 27 |
| [Project tickets kept apart](#projects) | 1 | 2 | 3 | 1 | 1 | 3 |
| [Ticket page: layout, Read tick, timers, list views, Resolve - no response](#ticket_page) | 5 | 5 | 6 | 7 | 5 | 8 |
| [Email reply "To" line (internal notes use ITFlow's @mentions)](#reply_recipients) | 0 | 1 | 0 | 0 | 0 | 0 |
| [Times on ticket notes (start / end)](#reply_times) | 1 | 3 | 9 | 4 | 20 | 5 |
| [Quote pricing (cost, markup / margin) and line images](#quotes) | 3 | 4 | 9 | 8 | 20 | 9 |
| [Procurement (to order, POs, receiving, ticket parts, parts on To Bill, vendor bills)](#procurement) | 12 | 11 | 37 | 22 | 131 | 23 |
| [Quick ticket (top-bar window: client, contact, issue; the contact's open tickets; timer started)](#quick_ticket) | 0 | 1 | 0 | 0 | 0 | 0 |
| [More bulk actions on the invoices list (mark sent / paid, send by email, cancel, delete)](#invoice_bulk) | 0 | 1 | 0 | 0 | 0 | 0 |
| [Timesheets, payroll, leave, California rules, holidays, reimbursements](#timesheets) | 28 | 24 | 57 | 21 | 92 | 20 |
| [QuickBooks Online (approvals, batches, locks, payments back)](#qbo) | 14 | 11 | 16 | 15 | 72 | 26 |
| [Usage billing (counts on recurring lines, connectors, API)](#usage) | 13 | 4 | 18 | 15 | 53 | 8 |
| [NinjaOne RMM sync and live status](#ninjaone) | 10 | 1 | 11 | 8 | 26 | 4 |
| [Customer Thermometer surveys](#customer_thermometer) | 7 | 2 | 16 | 7 | 25 | 4 |
| [MSP Align (read only)](#align) | 5 | 1 | 10 | 7 | 11 | 9 |
| [AI helper (library, Ask panel, write-up, local embeddings) - behind the password wall](#ai_helper) | 15 | 13 | 24 | 13 | 43 | 3 |
| [Bugs & ideas (bug reports and feature requests in the top bar)](#feedback) | 5 | 1 | 5 | 4 | 12 | 5 |
| [API / service accounts (logins API keys act as - out of every agent list, no browser sign-in)](#service_users) | 1 | 2 | 2 | 1 | 2 | 0 |
| [Unlimited support hours on contracts: a switch beside Hours each month, every billable ticket drawn in full by ITFlow's prepaid hours, read as "Unlimited"](#contract_unlimited) | 1 | 2 | 2 | 2 | 4 | 8 |
| [Percentage discounts: ITFlow's Discount as a % of discountable lines, products "Never discounted", kept up on recurring invoices, posted as a product in QuickBooks](#discount_percent) | 2 | 2 | 5 | 1 | 10 | 10 |
| [Diagrams with draw.io: network and site maps edited inside ITFlow - in Files (editable PNGs, earlier versions kept) and in documents; new ones from the client's assets or drawn by AI](#diagrams) | 61 | 3 | 16 | 14 | 79 | 6 |
| [Minimum commits: a line that tops an invoice / quote / recurring invoice up to a minimum, 0.00 once the other lines pass it, carried to invoices made from it](#minimum_commit) | 2 | 2 | 9 | 0 | 11 | 6 |
| [Client profitability: labor cost per agent (with history), parts cost, profit and margin, invoiced / paid basis in ITFlow's report](#client_profit) | 1 | 2 | 2 | 3 | 11 | 3 |

<a id="platform"></a>
## Shared base (helpers, database updates, version, menus, module switches) (`platform`)

**Takes for granted**

- ITFlow runs admin/database_updates.php in version order and our runner after it (update_cli, setup, restores).
- functions.php requires ours; mit_common.php first.
- Module rows (module_*) and roleModuleInfo() are how permissions work; Admin > Settings > Modules form posts to settings_module.php.
- Our menus hang off ITFlow's side_nav.php sections (INTEGRATIONS, BILLING, SUPPORT); our settings pages join settingsPages() / settingsFields() / the overview's $summaries (functions/settings.php, admin/settings.php), which feed the Settings menu, overview and Find a setting.

**Tests** `tests/scenarios/scenario-collation-columns.php`, `tests/smoke/01-pages.spec.js`, `tests/smoke/90-asset-stamps.spec.js`, `tests/smoke/72-module-switches.spec.js`, `tests/smoke/30-checks.spec.js`, `tests/smoke/77-sign-in-security.spec.js`, `tests/smoke/78-portal-sessions.spec.js`, `tests/smoke/79-platform-pages.spec.js`, `tests/smoke/88-settings-overview.spec.js`, `tests/scenarios/scenario-mail-logo.php`, `tests/scenarios/scenario-api-key-ways.php`, `tests/scenarios/scenario-api-paging-end.php`, `tests/smoke/83-contracts.spec.js`, `tests/smoke/99-php-log.spec.js`

**Hooks in ITFlow's files**

- `.htaccess`: MIT_CHANGELOG
- `admin/database_updates.php`: mitBeforeItflowUpdate, mit_db_upgrade
- `admin/includes/side_nav.php`: mit_integrations_nav
- `admin/post/settings_module.php`: mitModulesSave, mit_lines
- `admin/settings.php`: mitSettingsSummaries
- `admin/settings_module.php`: mit_modules_fields
- `admin/update.php`: MIT_CHANGELOG, mitVersion, mit_changelog_card, data-mit-itflow-version, data-mit-version, MountaineerIT
- `agent/includes/get_side_nav_counts.php`: mitNavCachedCount, mit_to_bill
- `agent/includes/side_nav.php`: mit_billing_nav, mit_to_bill
- `agent/unbilled_time.php`: mit_to_bill
- `agent/user/post/profile.php`: mitSessionRestamp
- `api/v1/enforce_api_rbac.php`: mit_usage
- `client/includes/check_login.php`: mitSessionCheck
- `client/login_microsoft.php`: mitPortalEntraTenant, mitPortalEntraTenantOk
- `client/post.php`: mitSessionRestamp
- `cron/mail_queue.php`: mitMailInlineImages, mit_common
- `functions.php`: mit_ai_helper, mit_align, mit_boards, mit_common, mit_ninjaone, mit_qbo, mit_timesheets, mit_usage
- `functions/backup.php`: mit_feedback
- `functions/backup_restore.php`: mit_backup_restore
- `functions/contract_hours.php`: mit_label
- `functions/invoice_actions.php`: mit_qbo, mit_qbo_billing
- `functions/reports.php`: mit_boards, mit_timesheets
- `functions/roles.php`: mitRoleModuleInfo
- `functions/settings.php`: mitSettingsFields, mitSettingsPages, mit_settings_index
- `functions/user.php`: mitSessionRestamp
- `functions/user_timezone.php`: mit_timesheets
- `includes/cron_jobs.php`: mit_ai_helper, mit_align, mit_boards, mit_customer_thermometer, mit_ninjaone, mit_qbo_billing, mit_timesheets, mit_usage
- `includes/footer.php`: mitVersion
- `includes/load_user_session.php`: mitSessionCheck
- `js/unbilled_time.js`: mit_to_bill, MountaineerIT
- `scripts/restore_cli.php`: mitRestoreItflowUpdates, mit_itflow_error, mit_updates_applied, mit_updates_error
- `setup/index.php`: mitRestoreItflowUpdates, mit_itflow_error, MountaineerIT, mit_message, mit_updates_error
- `admin/database_updates/3.8.7.php`: MountaineerIT
- `admin/database_updates/3.8.8.php`: MountaineerIT
- `agent/js/calendar.js`: mitAgent
- `functions/app.php`: MountaineerIT
- `functions/quote_images.php`: MountaineerIT
- `functions/quote_pricing.php`: MountaineerIT
- `scripts/setup_cli.php`: MountaineerIT, mit_updates_error
- `scripts/update_cli.php`: mit_updates_applied, mit_updates_error

**ITFlow functions it calls**

- `functions/app.php`: getFieldById
- `functions/auth.php`: clientScopeSql
- `functions/database_collation.php`: databaseCollationRepair
- `functions/dev_mode.php`: devModeConfigSet
- `functions/logging.php`: logApp
- `functions/offices.php`: offices
- `functions/products.php`: productStockMove
- `functions/request.php`: flashAlert, redirect
- `functions/sanitize.php`: escapeHtml, escapeSql
- `functions/settings.php`: moduleOn

**ITFlow tables** assets, calendar_events, categories, clients, companies, contacts, contract_templates, cron_jobs, documents, files, history, invoice_items, invoices, logs, modules, networks, offices, order_items, payments, po_items, po_receipts, po_tracking, product_stock, products, purchase_orders, quote_items, recurring_invoice_items, recurring_invoices, recurring_tickets, settings, software, taxes, ticket_attachments, ticket_helpers, ticket_history, ticket_mail_choices, ticket_replies, ticket_statuses, tickets, user_cost_rates, user_invites, user_role_permissions, user_roles, user_settings, users

**ITFlow columns** account_name, added_at, added_by, asset_id, category_id, client_id, company_currency, company_id, config_api_key_in_address, config_cron_alert_email, config_cron_alert_state, config_cron_alert_to, config_cron_health_key, config_current_database_version, config_labor_cost_default, config_mail_from_email, config_mail_from_name, config_module_enable_dispatch, config_module_enable_purchasing, config_notify_assigned_app, config_notify_assigned_email, config_notify_helper_replies, config_po_approval, config_po_approval_over, config_po_next_number, config_po_prefix, config_project_ticket_color, config_project_tickets_apart, config_quick_ticket_billable, config_quick_ticket_on, config_quote_price_method, config_quote_price_percent, config_quote_price_presets, config_quote_show_images, config_ticket_assigned_message, config_ticket_no_response_message, config_timezone, contact_id, cron_job_name, document_id, event_id, event_repeat_by_day, event_repeat_interval, expense_id, file_id, file_name, file_size, follows_lead, history_id, invite_id, invite_office_id, invoice_archived_at, invoice_client_id, invoice_id, invoice_status, item_cost, item_id, item_image, item_price, item_price_method, item_price_percent, item_seat_filter, item_seat_minimum, item_seat_source, log_action, log_client_id, log_created_at, log_description, log_entity_id, log_id, log_ip, log_type, log_user_agent, log_user_id, mail_choice_by, mail_choice_email, mail_choice_send, mail_choice_ticket_id, mail_choice_updated_at, module_description, module_id, module_name, office_address, office_archived_at, office_city, office_country, office_created_at, office_email, office_id, office_name, office_phone, office_phone_country_code, office_state, office_updated_at, office_zip, order_item_client_id, order_item_cost, order_item_created_at, order_item_created_by, order_item_description, order_item_id, order_item_name, order_item_note, order_item_product_id, order_item_quantity, order_item_source, order_item_status, order_item_updated_at, order_item_vendor_id, payment_id, po_approval_note, po_approved_at, po_approved_by, po_cancelled_at, po_created_at, po_created_by, po_currency_code, po_expense_id, po_id, po_item_client_id, po_item_cost, po_item_description, po_item_id, po_item_name, po_item_order, po_item_order_item_id, po_item_po_id, po_item_product_id, po_item_quantity, po_item_received, po_note, po_number, po_order_date, po_ordered_at, po_ordered_by, po_prefix, po_receipt_asset_ids, po_receipt_at, po_receipt_by, po_receipt_id, po_receipt_item_id, po_receipt_po_id, po_receipt_quantity, po_receipt_serials, po_receipt_stock_id, po_received_at, po_ship_to, po_ship_to_office_id, po_shipping, po_status, po_submitted_at, po_total, po_tracking_carrier, po_tracking_created_at, po_tracking_eta, po_tracking_id, po_tracking_number, po_tracking_po_id, po_updated_at, po_vendor_id, po_vendor_reference, product_cost, product_id, product_image, product_price, product_track_stock, product_type, product_vendor_id, project_id, recurring_invoice_due_mode, recurring_invoice_id, recurring_invoice_lead_days, recurring_ticket_by_day, recurring_ticket_frequency, recurring_ticket_id, recurring_ticket_lead_days, recurring_ticket_lead_mode, recurring_ticket_schedule_minutes, recurring_ticket_schedule_onsite, recurring_ticket_schedule_time, role_id, round_trip, schedule_end, schedule_start, software_id, stock_created_at, stock_id, stock_note, stock_product_id, stock_qty, stock_type, tax_id, ticket_archived_at, ticket_attachment_ticket_id, ticket_billable, ticket_category, ticket_closed_at, ticket_created_at, ticket_history_created_at, ticket_id, ticket_invoice_id, ticket_no_response_at, ticket_number, ticket_project_id, ticket_reply_by, ticket_reply_created_at, ticket_reply_id, ticket_reply_time_worked, ticket_reply_work_end, ticket_reply_work_start, ticket_resolved_at, ticket_schedule, ticket_schedule_end, ticket_sla_from, ticket_status_first_response, ticket_status_id, ticket_status_reply_status_id, trip_id, user_config_calendar_color, user_config_calendar_state, user_config_timezone, user_cost_rate_created_at, user_cost_rate_created_by, user_cost_rate_from, user_cost_rate_hourly, user_cost_rate_user_id, user_id, user_office_id, user_role_id, user_role_permission_level, user_service_account, user_token, vendor_id

**Watched in ITFlow's code** `FROM_DB_UPDATER` `CURRENT_DATABASE_VERSION` `roleModuleInfo` `settingsSave` `settingsPages` `settingsFields` `settingsSearchIndex` `data-settings-tile`

**"ITFlow does this too" words** module_

**Our files** `functions/mit_common.php`, `functions/mit_db_upgrade.php`, `admin/database_updates_mountaineerit.php`, `includes/mit_version.php`, `MIT_CHANGELOG.md`, `admin/includes/mit_changelog_card.php`, `functions/mit_settings_index.php`, `admin/includes/mit_integrations_nav.php`, `admin/includes/mit_modules_fields.php`, `agent/includes/mit_billing_nav.php`

<a id="checks"></a>
## Our checks (hooks, consistency, portability, AI wall, verify, feature map) (`checks`)

**Takes for granted**

- ITFlow's cron dispatcher (includes/cron_jobs.php) runs our jobs.

**Tests** `tests/smoke/30-checks.spec.js`, `tests/scenarios/scenario-cron-registry.php`

**Hooks in ITFlow's files**

- `.htaccess`: MIT_API, MIT_FEATURES
- `functions.php`: mit_boards, mit_timesheets
- `functions/contract_signature.php`: mit_line
- `functions/reports.php`: mit_boards, mit_timesheets
- `functions/user_timezone.php`: mit_timesheets
- `includes/cron_jobs.php`: mit_boards, mit_consistency, mit_timesheets

**ITFlow functions it calls**

- `cron/includes/cron_lock.php`: cronJobStop
- `functions/app.php`: getFieldById

**ITFlow tables** assets, clients, contacts, documents, expenses, invoice_items, invoices, payments, products, records, reimbursements, settings, tickets, trips, users

**ITFlow columns** account_id, added_at, asset_id, client_id, company_id, config_enable_cron, contact_id, document_archived_at, document_client_id, document_id, expense_amount, expense_reimbursement_id, invoice_id, invoice_number, invoice_prefix, invoice_status, item_id, item_invoice_id, item_name, item_quantity, payment_amount, payment_created_at, payment_date, payment_id, payment_invoice_id, payment_method, product_id, reimbursement_amount, reimbursement_id, reimbursement_reference, reimbursement_user_id, ticket_archived_at, ticket_client_id, ticket_closed_at, ticket_id, ticket_number, ticket_prefix, ticket_resolved_at, trip_id, user_archived_at, user_id, user_name

**Our files** `scripts/mit_ai_embed_worker.php`, `scripts/mit_ai_wall_check.php`, `scripts/mit_consistency_check.php`, `scripts/mit_feature_lib.php`, `scripts/mit_feature_map.php`, `scripts/mit_features.php`, `scripts/mit_hook_check.php`, `scripts/mit_hook_proof.php`, `scripts/mit_portability_check.php`, `scripts/mit_test_fixture.php`, `scripts/mit_upstream_impact.php`, `scripts/mit_drawio_update.sh`, `scripts/mit_test_copy.sh`, `scripts/mit_verify.sh`, `scripts/mit_hooks.txt`, `scripts/mit_hook_proof_accepted.txt`, `scripts/mit_hook_proof_switch.txt`, `functions/mit_consistency.php`, `cron/mit_consistency.php`, `MIT_FEATURES.md`, `MIT_API.md`

<a id="dev_mode"></a>
## Development mode (a production copy on a test server) (`dev_mode`)

**Takes for granted**

- All mail goes through cron/mail_queue.php (PHPMailer) - the redirect sits just before the send.
- Stripe is set up in includes/stripe_init.php; custom actions go through triggerCustomAction; log forwarding through functions/log_forward.php.
- cron/ticket_email_parser.php and cron/ai_triage.php can be stopped with cronJobStop.

**Tests** `tests/smoke/17-dev-mode.spec.js`, `tests/scenarios/scenario-dev-mode-mail.php`

**Hooks in ITFlow's files**

- `cron/mail_queue.php`: mit_common
- `functions.php`: mit_common, mit_ct
- `functions/logging.php`: mitDevMode
- `includes/cron_jobs.php`: mit_ct

**ITFlow functions it calls**

- `functions/dev_mode.php`: devModeMailTo, devModeOn

**Watched in ITFlow's code** `cronJobStop` `triggerCustomAction` `logForwardSend`

**"ITFlow does this too" words** dev_mode, sandbox, test_mode

**Our files** `functions/mit_dev_mode.php`

<a id="backup_restore"></a>
## Restore from Admin > Backup (`backup_restore`)

**Takes for granted**

- ITFlow's backupInspectArchive / backupRestoreArchive / backupAssertUploadsGuards and the backups table.
- load_global_settings.php fixes CURRENT_DATABASE_VERSION for the request (so a restore walks the updates itself).

**Tests** `tests/scenarios/scenario-restore-tempdir.php`

**Hooks in ITFlow's files**

- `cron/mail_queue.php`: mit_common
- `functions.php`: mit_common
- `functions/backup_restore.php`: mitRestoreUseTempDir, mit_backup_restore

**ITFlow functions it calls**

- `functions/backup.php`: backupDeleteDirectory, backupStorageDir

**Watched in ITFlow's code** `backupRestoreArchive` `backupInspectArchive` `backupAssertUploadsGuards`

**"ITFlow does this too" words** backupRestore, restore_archive, restoreArchive, steps/restore, restore_upload

**Our files** `functions/mit_backup_restore.php`

<a id="cron_health"></a>
## Cron health (warning, emails, /mit_health.php) (`cron_health`)

**Takes for granted**

- ITFlow's cron_jobs table (status, last run, interval) and settings.config_cron_last_dispatch_at.

**Tests** `tests/scenarios/scenario-cron-watch.php`, `tests/smoke/81-cron-health.spec.js`

**Hooks in ITFlow's files**

- `admin/oauth_microsoft_mail_callback.php`: mit_m365
- `admin/post/settings_mail.php`: mit_m365
- `functions.php`: mit_cron_health, mit_m365
- `functions/cron_health.php`: mitCronExtraProblems, mit_cron_health
- `functions/mail_graph.php`: mit_m365
- `functions/request.php`: mit_m365
- `includes/top_nav.php`: mit_cron_nav

**ITFlow functions it calls**

- `functions/cron_health.php`: cronHealthProblems
- `functions/sanitize.php`: escapeHtml

**Watched in ITFlow's code** `config_cron_last_dispatch_at`

**"ITFlow does this too" words** cron_alert, health, heartbeat, cron_fail, cronWatch

**Our files** `functions/mit_cron_health.php`, `includes/mit_cron_nav.php`, `mit_health.php`

<a id="theme"></a>
## Modern look, logo options, sign-in screen, client tiles, time fields, portal tables (`theme`)

**Takes for granted**

- A login's copy buttons (functions/credential.php): username .itflow-credential-user + .itflow-copy, password .itflow-credential-pw .itflow-credential-copy, link .itflow-copy[title="Copy link"] - set to 1em.
- The side menu: li.nav-header headings with the section's li items after them in the same list (folded by js/mit_nav_sections.js).
- ITFlow's markup classes: .app-header, .app-sidebar .nav-link.active, .card-header.bg-dark, .itflow-dash-rail*, .itflow-client-switcher.
- Company theme colours as body.theme-<colour> (admin/settings_theme.php); statuses coloured through --itflow-status-bg.
- login.php / client pages send CSP default-src 'self' - our files there must be files, not inline.

**Tests** `tests/smoke/21-brand-logo.spec.js`, `tests/smoke/26-portal-phone.spec.js`, `tests/smoke/46-client-tiles.spec.js`, `tests/smoke/80-theme-look.spec.js`, `tests/smoke/87-nav-sections.spec.js`, `tests/smoke/93-credential-copy.spec.js`, `tests/smoke/94-quick-look-fit.spec.js`

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
- `functions/settings.php`: settingsInput, settingsSectionEnd, settingsSectionStart, settingsSwitch
- `functions/software.php`: softwareSeatsUsedSql
- `functions/ticket.php`: ticketOverdueSql

**ITFlow tables** assets, categories, invoices, quotes, records, settings, software, software_assets, software_contacts, tags, ticket_replies, tickets, users

**ITFlow columns** asset_archived_at, asset_client_id, asset_id, asset_install_date, asset_purchase_date, asset_warranty_expire, client_id, contact_id, software_archived_at, software_client_id, software_expire, software_id, software_seats, ticket_assigned_to, ticket_client_id, ticket_closed_at, ticket_id, ticket_project_id, ticket_reply_archived_at, ticket_reply_created_at, ticket_reply_ticket_id, ticket_reply_time_worked, ticket_resolved_at

**ITFlow page ids / classes its scripts and styles use** `#items` `.app-header` `.app-main` `.app-sidebar` `.app-wrapper` `.itflow-brand-name` `.itflow-client-avatar` `.itflow-client-head` `.itflow-client-head-name` `.itflow-client-switcher` `.itflow-dash-money-value` `.itflow-dash-rail` `.itflow-dash-rail-clear` `.itflow-dash-rail-count` `.itflow-dash-rail-detail` `.itflow-dash-rail-item` `.itflow-dash-rail-label` `.itflow-theme-swatch`

**Watched in ITFlow's code** `app-header` `app-sidebar` `itflow-dash-rail` `itflow-client-switcher` `--itflow-accent` `--itflow-status-bg` `login-box` `itflow-credential-copy` `itflow-credential-user` `itflow-ticket-glance` `itflow-cal-tile` `title="Copy link"`

**"ITFlow does this too" words** theme, dark_mode, logo

**Our files** `functions/mit_theme.php`, `css/mit_login.css`, `css/mit_login_plain.css`, `css/mit_theme.css`, `css/mit_login_nodes.svg`, `css/fonts/inter/OFL.txt`, `css/fonts/inter/inter-latin-ext-wght-normal.woff2`, `css/fonts/inter/inter-latin-wght-normal.woff2`, `js/mit_theme.js`, `js/mit_time_inputs.js`, `js/mit_nav_sections.js`, `js/mit_portal_tables.js`, `functions/mit_client_tiles.php`

<a id="emails"></a>
## Email look: logo inside the message, resolved wording, notification switches (`emails`)

**Takes for granted**

- Every email is built by ITFlow's emailHtml / emailQueue / ticketMailSend and sent by cron/mail_queue.php.
- emailHtml links the logo as https://<base url>/uploads/settings/<file> (mitMailInlineImages embeds it).
- Each recipient is greeted by emailQueue ("Hello <name>,") - our wording must not greet again.
- ticketMailSchedule / ticketMailAssigned / recurringTicketMailAssigned are where schedule and assignment mail is built.

**Tests** `tests/scenarios/scenario-mail-logo.php`, `tests/smoke/25-notifications.spec.js`

**Hooks in ITFlow's files**

- `admin/post/settings_notification.php`: mitNotificationsSave, mit_changed
- `admin/settings_notification.php`: mitNotificationSections
- `agent/modals/ticket/ticket_edit_schedule.php`: mitScheduleNotifyField
- `agent/post/ticket.php`: mitScheduleNotifyFromPost
- `functions.php`: mit_notifications
- `functions/ticket.php`: mitScheduleExtraRecipients, mitScheduleNotify, mit_notifications, mit_contact, mit_extra, mit_watchers

**ITFlow functions it calls**

- `functions/billing.php`: billingCompany
- `functions/email.php`: emailCapturing
- `functions/sanitize.php`: escapeHtml, escapeSql
- `functions/settings.php`: settingsSectionEnd, settingsSectionStart
- `functions/ticket.php`: ticketColleagueRecipients, ticketWatcherEmails

**ITFlow tables** clients, contacts, locations, notifications, settings, ticket_watchers, tickets

**ITFlow columns** company_name, contact_archived_at, contact_client_id, contact_email, contact_id, contact_location_id, contact_name, contact_primary, location_contact_id, location_id, ticket_client_id, ticket_contact_id, ticket_id, ticket_location_id, ticket_onsite, user_name, watcher_email, watcher_ticket_id

**Watched in ITFlow's code** `/uploads/settings/` `addToMailQueue` `ticketMailSchedule` `ticketMailAssigned` `ticketEmailContent`

**"ITFlow does this too" words** notify_schedule, schedule_notify, inline_image, email_logo, resolved_message

**Our files** `functions/mit_notifications.php`, `agent/js/mit_schedule_notify.js`

<a id="calendar"></a>
## Calendar (agent colours, booking ends, conflicts, Left behind, 7 days, weekday repeats, event agents, holidays, own time zone) (`calendar`)

**Takes for granted**

- Stored booking / event times are clock times in the company zone (includes/inc_set_timezone.php); mit_zones.php moves them onto the viewer's clock at the edges.
- Bookings are tickets.ticket_schedule (+ our ticket_schedule_end); ticketSetSchedule / ticketCancelSchedule change them.
- functions/calendar.php builds the sources (calendarSources / calendarSourceItems / calendarEventItems); agent/js/calendar.js draws them with FullCalendar v7.
- Attendee agents (calendar_event_attendees.attendee_user_id) are an entry's assignment.
- Holidays are business_holidays (Admin > SLA closure days).

**Tests** `tests/smoke/08-calendar.spec.js`, `tests/smoke/50-sched-window.spec.js`, `tests/smoke/52-calendar-state-moves.spec.js`, `tests/smoke/53-event-repeat-weekday.spec.js`, `tests/smoke/54-recurring-ticket-weekday.spec.js`, `tests/scenarios/scenario-sched-booking.php`, `tests/scenarios/scenario-sched-gaps.php`, `tests/scenarios/scenario-sched-outlook.php`, `tests/scenarios/scenario-sched-sources.php`, `tests/scenarios/scenario-sched-workload.php`, `tests/smoke/95-time-zones.spec.js`

**Hooks in ITFlow's files**

- `admin/settings_ticket.php`: mitServiceTicketColor, mit_calendar_card
- `agent/calendar.php`: data-mit-agent, mit_calendar, mit_outlook
- `agent/js/calendar.js`: data-mit-agent, mitStriped
- `agent/user/includes/user_side_nav.php`: mit_outlook
- `cron/ticket_email_parser.php`: mit_ticket_techs
- `functions.php`: mit_calendar, mit_projects
- `functions/calendar.php`: mitCalendarTicketItems, mit_calendar, mit_outlook
- `functions/ticket.php`: mit_outlook
- `functions/ticket_helpers.php`: mit_outlook, mit_ticket_techs
- `functions/ticket_reply_recipients.php`: mit_ticket_techs
- `includes/cron_jobs.php`: mit_outlook

**ITFlow functions it calls**

- `functions/auth.php`: hasClientAccess
- `functions/calendar.php`: calendarAgentColor, calendarColor
- `functions/logging.php`: logAudit
- `functions/request.php`: flashAlert, redirect
- `functions/sanitize.php`: escapeHtml, escapeSql
- `functions/security.php`: validateCSRFToken
- `functions/ticket.php`: ticketReference, ticketScheduleConflicts, ticketScheduleEnd

**ITFlow tables** settings, tickets

**ITFlow columns** ticket_client_id, ticket_subject, user_id

**Watched in ITFlow's code** `ticket_schedule` `calendarSourceItems` `calendarEventItems` `eventContent` `eventDidMount` `headerToolbar` `business_holidays`

**"ITFlow does this too" words** schedule_end, calendar_color, agent_color, by_day, byday

**Our files** `functions/mit_calendar.php`, `js/mit_repeat_on.js`, `admin/includes/mit_calendar_card.php`, `admin/post/mit_calendar_settings.php`

<a id="outlook_m365"></a>
## Microsoft 365 app, Graph mail, Outlook calendar per tech (`outlook_m365`)

**Takes for granted**

- Mail OAuth settings (config_mail_oauth_*) and the token flow in functions/request.php httpFormPost / admin/post/settings_mail.php.
- cron/mail_queue.php sends one PHPMailer message per email; cron/ticket_email_parser.php reads mail through ImapEngine's Mailbox.
- Ticket invites are iCal with UID ticket-<id>@ (createiCalStr).

**Tests** `tests/scenarios/scenario-sched-outlook.php`, `tests/scenarios/scenario-dev-mode-mail.php`, `tests/scenarios/scenario-m365-hooks.php`, `tests/scenarios/scenario-parser-boards.php`, `tests/smoke/82-m365-mail-settings.spec.js`, `tests/smoke/79-platform-pages.spec.js`

**Hooks in ITFlow's files**

- `admin/oauth_microsoft_mail_callback.php`: mitMicrosoftTokenFields, mit_m365
- `admin/post/settings_mail.php`: mitM365ConnectPrompt, mitM365KeepCertificateSecret, mitMicrosoftTokenFields, mit_m365
- `admin/settings_mail.php`: mit_m365_card
- `agent/calendar.php`: mit_calendar, mit_outlook
- `agent/user/includes/user_side_nav.php`: mit_outlook
- `cron/ticket_email_parser.php`: mit_ticket_techs
- `functions.php`: mit_calendar, mit_m365
- `functions/calendar.php`: mitOutlookBusyItems, mitOutlookCalendarSources, mit_calendar, mit_outlook
- `functions/mail_graph.php`: mitMicrosoftTokenFields, mit_m365
- `functions/request.php`: mitMicrosoftTokenFields, mit_m365
- `functions/ticket.php`: mitOutlookBusyConflicts, mitOutlookSyncsEmail, mit_outlook
- `functions/ticket_helpers.php`: mitOutlookSyncsEmail, mit_outlook, mit_ticket_techs
- `functions/ticket_reply_recipients.php`: mit_ticket_techs
- `includes/cron_jobs.php`: mit_outlook, mit_outlook_sync

**ITFlow functions it calls**

- `cron/includes/cron_lock.php`: cronJobStop
- `functions/format.php`: timeAgo
- `functions/logging.php`: logApp, logAudit
- `functions/mail_graph.php`: mailGraphForget, mailGraphScopesOf
- `functions/request.php`: flashAlert, redirect
- `functions/sanitize.php`: escapeHtml, escapeSql
- `functions/security.php`: randomString, validateCSRFToken
- `functions/ticket.php`: ticketReference

**ITFlow tables** calendars, categories, clients, contacts, credentials, locations, settings, tickets, users

**ITFlow columns** account_name, client_id, client_name, company_id, config_enable_cron, config_mail_graph_scopes, config_mail_oauth_access_token, config_mail_oauth_access_token_expires_at, config_mail_oauth_client_id, config_mail_oauth_client_secret, config_mail_oauth_refresh_token, config_mail_oauth_tenant_id, contact_id, contact_location_id, contact_mobile, contact_name, contact_phone, event_id, location_address, location_city, location_id, location_name, location_state, location_zip, ticket_archived_at, ticket_assigned_to, ticket_client_id, ticket_closed_at, ticket_contact_id, ticket_id, ticket_location_id, ticket_number, ticket_onsite, ticket_prefix, ticket_resolved_at, ticket_schedule, ticket_schedule_end, ticket_subject, user_archived_at, user_email, user_id, user_name, user_status

**Watched in ITFlow's code** `httpFormPost` `XOAUTH2` `new Mailbox(` `createiCalStr` `config_mail_oauth_client_secret` `'oauth-app'` `ms_connect_group` `mailOauthRedirect` `tab-oauth` `oauth_connect_microsoft_mail` `settings:reset`

**"ITFlow does this too" words** graph, outlook, m365, microsoft

**Our files** `functions/mit_m365.php`, `functions/mit_outlook.php`, `admin/includes/mit_m365_card.php`, `admin/post/mit_m365.php`, `agent/user/mit_outlook.php`, `agent/user/mit_outlook_callback.php`, `agent/user/post/mit_outlook.php`, `cron/mit_outlook_sync.php`

<a id="ticket_techs"></a>
## Additional techs (helpers) on tickets (`ticket_techs`)

**Takes for granted**

- tickets.ticket_assigned_to is the one lead; ticketChangeAssignee changes it (four paths, none hooked - mitTicketTechsTidy on read).
- Assignee reply copies hang off ticketMailAssigneeReplied (parser, client/post.php).

**Tests** `tests/smoke/42-helpers.spec.js`, `tests/scenarios/scenario-helpers.php`, `tests/scenarios/scenario-parser-boards.php`

**Hooks in ITFlow's files**

- `agent/calendar.php`: mit_outlook
- `agent/post/ticket.php`: mitTicketClientMoved
- `agent/user/includes/user_side_nav.php`: mit_outlook
- `cron/ticket_email_parser.php`: mitAgentCanWorkTicket, mit_ticket_techs
- `functions/calendar.php`: mit_outlook
- `functions/ticket.php`: mit_outlook
- `functions/ticket_helpers.php`: mitAgentCanWorkTicket, mitTicketHelperUid, mit_outlook, mit_ticket_techs, mit_uid
- `functions/ticket_reply_recipients.php`: mitReplyRecipientsHelpers, mit_ticket_techs
- `includes/cron_jobs.php`: mit_outlook

**ITFlow functions it calls**

- `functions/format.php`: selectOptionUser
- `functions/ticket.php`: ticketRow
- `functions/ticket_helpers.php`: ticketAssignedSql, ticketHelperRemove, ticketHelpers, ticketHelpersReady
- `functions/user.php`: userClientAccess

**ITFlow tables** clients, contacts, locations, modules, ticket_helpers, tickets, user_role_permissions, user_roles, users

**ITFlow columns** client_id, client_name, contact_id, contact_location_id, contact_mobile, contact_name, contact_phone, location_address, location_city, location_id, location_name, location_state, location_zip, module_id, module_name, role_archived_at, role_id, role_is_admin, ticket_archived_at, ticket_assigned_to, ticket_client_id, ticket_closed_at, ticket_contact_id, ticket_id, ticket_location_id, ticket_number, ticket_onsite, ticket_prefix, ticket_resolved_at, ticket_schedule, ticket_schedule_end, ticket_subject, user_avatar, user_email, user_id, user_name, user_role_id, user_role_permission_level

**Watched in ITFlow's code** `ticket_assigned_to` `ticketMailAssigneeReplied` `ticketChangeAssignee`

**"ITFlow does this too" words** collaborator, additional_tech, helper, ticket_users, ticket_assignees

**Our files** `functions/mit_ticket_techs.php`

<a id="dispatch"></a>
## Dispatch board, Workload, This week (`dispatch`)

**Takes for granted**

- Booking = ticketChangeAssignee + ticketSetSchedule; the Schedule dialog repeats Edit ticket / Change client / SLA rules (change_client_ticket, ticketMergeInto in agent/post/ticket.php).
- ticketSlaState for SLA counts; Admin > SLA working hours.

**Tests** `tests/smoke/14-dispatch-role.spec.js`, `tests/smoke/51-dispatch-flow.spec.js`, `tests/smoke/92-dispatch-dialog.spec.js`, `tests/scenarios/scenario-sched-workload.php`, `tests/scenarios/scenario-dispatch-hours.php`

**Hooks in ITFlow's files**

- `agent/ajax.php`: mitDispatchBeforeBook, mit_dispatch, mit_dispatch_details_message, mit_why
- `agent/dispatch.php`: mitDispatchLevel, mitDispatchScheduleFields, mitDispatchViewerHours, mit_dispatch, mit_dispatch_extras
- `agent/includes/side_nav.php`: mitDispatchLevel, mitDispatchNavCount, mit_dispatch_count
- `agent/js/dispatch.js`: mit_dispatch
- `agent/post/ticket.php`: mit_board
- `functions.php`: mit_boards, mit_dispatch, mit_notifications, mit_projects
- `functions/dispatch.php`: mitDispatchBoardSql, mitDispatchLevel, mitDispatchOutlookItems, mitDispatchQueueBoards, mit_dispatch
- `functions/reports.php`: mit_boards
- `functions/ticket.php`: mit_notifications
- `includes/cron_jobs.php`: mit_boards

**ITFlow functions it calls**

- `functions/app.php`: getFieldById
- `functions/auth.php`: clientScopeSql, hasClientAccess, lookupUserPermission
- `functions/calendar.php`: calendarCanScheduleTickets, calendarSources
- `functions/dispatch.php`: dispatchAgents, dispatchCan
- `functions/logging.php`: logAudit, logTicketHistory, triggerCustomAction
- `functions/project_tickets.php`: projectTicketsServiceSql
- `functions/sanitize.php`: escapeHtml, escapeSql
- `functions/security.php`: validateCSRFToken
- `functions/settings.php`: moduleOn
- `functions/sla.php`: applyTicketSla
- `functions/ticket.php`: ticketCategoryId, ticketClientOwnedAssetIds, ticketClientOwnedId, ticketIsOpen, ticketReference, ticketRow, ticketScheduleValue, ticketSetOtherAssets
- `functions/ticket_fields.php`: ticketChangePriority, ticketChangeStatus, ticketPickableStatuses
- `functions/user_timezone.php`: timezoneShifted, timezoneView

**ITFlow tables** assets, categories, clients, contacts, credentials, modules, notifications, slas, ticket_assets, ticket_replies, tickets, trips

**ITFlow columns** asset_archived_at, asset_client_id, asset_contact_id, asset_id, asset_name, asset_type, category_archived_at, category_id, category_name, category_type, client_archived_at, client_id, client_name, contact_archived_at, contact_client_id, contact_id, contact_name, contact_primary, contact_title, module_name, sla_archived_at, sla_id, sla_name, ticket_archived_at, ticket_asset_id, ticket_assigned_to, ticket_category, ticket_client_id, ticket_closed_at, ticket_contact_id, ticket_id, ticket_location_id, ticket_priority, ticket_project_id, ticket_reply_ticket_id, ticket_reply_type, ticket_sla_id, ticket_status, ticket_status_id, ticket_status_name, ticket_subject, ticket_vendor_id, trip_client_id, trip_location_id, trip_ticket_id, user_id

**ITFlow page ids / classes its scripts and styles use** `#dispatchScheduleModal`

**Watched in ITFlow's code** `ticketMergeInto` `ticketSlaState` `change_client_ticket`

**"ITFlow does this too" words** dispatch, workload

**Our files** `agent/includes/mit_dispatch_extras.php`, `functions/mit_dispatch.php`, `agent/mit_dispatch_ajax.php`, `agent/js/mit_dispatch.js`

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
- `admin/post/roles.php`: mitBoardRoleSave, mit_boards_changed
- `admin/post/settings_ticket.php`: mitQuickTicketBoardSave, mit_changed
- `admin/settings_ticket.php`: mitQuickTicketBoardField
- `agent/ajax.php`: mitBoardVisibleSql, mitTicketPickOptions, mitTicketPickSet, mit_dispatch, mit_options, mit_set
- `agent/dashboard.php`: mitBoardDashCard, mitBoardDashLinks, mitBoardDashPicker
- `agent/dispatch.php`: mit_dispatch
- `agent/js/dispatch.js`: mit_dispatch
- `agent/modals/ticket/ticket_add.php`: mitBoardNewTicketField
- `agent/modals/ticket/ticket_quick.php`: mitQuickTicketBoardInput
- `agent/post/ticket.php`: mitBoardAfterCreate, mitBoardRoute, mit_board
- `agent/ticket.php`: mitBoardEscalateMenuItem, mitBoardTicketProp, mitTicketPickButton, mit_category, mit_sla
- `agent/ticket_kanban.php`: mitBoardListTag
- `agent/ticket_list.php`: mitBoardListTag
- `agent/tickets.php`: mitBoardHiddenInput, mitBoardSwitcher, mitBoardTicketsFilter
- `cron/ticket_email_parser.php`: mitBoardMessageAddresses, mitBoardRoute, mit_ticket_techs, mit_board_addresses
- `functions.php`: mit_boards, mit_dispatch, mit_projects
- `functions/client_overview.php`: mitBoardVisibleSql
- `functions/dashboard.php`: mitBoardDashAnd
- `functions/dispatch.php`: mit_dispatch
- `functions/reports.php`: mitBoardReportEntry, mit_boards
- `functions/search.php`: mitBoardVisibleSql
- `functions/ticket_helpers.php`: mit_ticket_techs
- `functions/ticket_reply_recipients.php`: mit_ticket_techs
- `includes/cron_jobs.php`: mit_boards
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
- `functions/settings.php`: settingsSelect
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

- `agent/ajax.php`: mit_split_projects
- `functions.php`: mit_projects
- `functions/calendar.php`: mitProjectBookingItems

**ITFlow functions it calls**

- `functions/calendar.php`: calendarSourceItems
- `functions/project_tickets.php`: projectTicketsApart, projectTicketsServiceSql

**ITFlow tables** tickets

**ITFlow columns** ticket_project_id

**Watched in ITFlow's code** `ticket_project_id` `projectAddTicket` `projectRemoveTicket`

**"ITFlow does this too" words** project_ticket

**Our files** `functions/mit_projects.php`

<a id="ticket_page"></a>
## Ticket page: layout, Read tick, timers, list views, Resolve - no response (`ticket_page`)

**Takes for granted**

- The ticket page's boxes are found by their title icons (fa-align-left, fa-sliders-h, fa-user, fa-layer-group, fa-map-marker-alt, fa-tasks, fa-link, fa-history).
- Reply form ids: #replyComposer, public_reply_type_opt1/2, #replyAudience, #replySubmitLabel, #ticket_add_reply.
- Timer keys in localStorage: <id>-startTime / <id>-pausedTime / ticket-timer-running-<id> (agent/js/ticket_time_tracking.js).
- ticketResolve sends no mail itself; ticketMailResolved builds the resolved email.

**Tests** `tests/smoke/43-layout-timers.spec.js`, `tests/smoke/23-bulk-toolbar.spec.js`, `tests/smoke/24-no-response.spec.js`, `tests/smoke/03-our-features.spec.js`, `tests/scenarios/scenario-no-response.php`

**Hooks in ITFlow's files**

- `agent/ajax.php`: mitTicketLayoutAjax, mit_ticket_layout
- `agent/ticket.php`: mitTicketLayoutHtml, mit_ticket_layout
- `agent/tickets.php`: mitListViewResetButton, mitListViewRestore
- `functions.php`: mit_diagrams, mit_list_views, mit_ninjaone, mit_ticket_layout
- `functions/backup.php`: mit_diagrams, mit_feedback
- `includes/cron_jobs.php`: mit_ninjaone, mit_procurement_parts
- `includes/top_nav.php`: mit_timers_nav
- `js/app.js`: mit_diagrams

**ITFlow functions it calls**

- `functions/auth.php`: hasClientAccess, lookupUserPermission
- `functions/logging.php`: logAudit
- `functions/request.php`: flashAlert, redirect
- `functions/security.php`: validateCSRFToken

**ITFlow tables** credentials, history, tags, tasks, tickets, trips, users

**ITFlow columns** client_id, ticket_client_id, ticket_details, ticket_id, user_id

**ITFlow page ids / classes its scripts and styles use** `#public_reply_type_opt1` `#replyAudience` `#replyComposer` `#replySubmitLabel` `.itflow-ticket-trips`

**Watched in ITFlow's code** `replyComposer` `public_reply_type_opt1` `replyAudience` `replySubmitLabel` `ticket_add_reply` `return ticketID + "-" + suffix` `getLocalStorageKey("startTime")` `getLocalStorageKey("pausedTime")` `ticket-timer-running-` `fa-sliders-h` `fa-layer-group` `fa-map-marker-alt` `ticket-actions` `bulkActionButton` `itflow-ticket-trips`

**"ITFlow does this too" words** ticket_layout, no_response, ticket_timer, time_tracking, list_view, saved_view

**Our files** `functions/mit_ticket_layout.php`, `agent/js/mit_ticket_layout.js`, `includes/mit_timers_nav.php`, `functions/mit_list_views.php`, `admin/post/mit_no_response_settings.php`

<a id="reply_recipients"></a>
## Email reply "To" line (internal notes use ITFlow's @mentions) (`reply_recipients`)

**Takes for granted**

- add_ticket_reply emails through ticketMailReply; ours replaces it when mit_send_to_present is posted and records ticketReplyEmailed.
- js/itflow_select.js is the picker (multi-select, data-tags); itflowDismissPopups closes pickers on an editor click.
- The parser files mail from an agent's own address in addReply.

**Tests** `tests/smoke/40-reply-recipients.spec.js`

**Watched in ITFlow's code** `ticketMailReply` `ticketReplyEmailed` `ticketColleagueRecipients` `itflowDismissPopups` `data-tags`

**"ITFlow does this too" words** reply_recipient, ticket_recipient, collaborator, ticket_cc, reply_to, internal_notify

**Our files** 

<a id="reply_times"></a>
## Times on ticket notes (start / end) (`reply_times`)

**Takes for granted**

- ticket_replies.ticket_reply_time_worked holds the length; the timer fields are #hours / #minutes / #seconds.
- flashAlert keeps one message (mitReplyTimesFlash adds the time warning back).

**Tests** `tests/smoke/09-note-times.spec.js`, `tests/smoke/45-note-time-locks.spec.js`, `tests/smoke/74-ts-ticket-moves.spec.js`

**Hooks in ITFlow's files**

- `agent/post/ticket.php`: mitReplyTimesFlash, mitReplyWorkGuardAdd, mitReplyWorkGuardEdit, mitTsKeepPublicNotes, mit_reply_times
- `functions.php`: mit_timesheets
- `functions/reports.php`: mit_timesheets
- `functions/user_timezone.php`: mit_timesheets
- `includes/cron_jobs.php`: mit_timesheets

**ITFlow functions it calls**

- `functions/auth.php`: hasClientAccess, lookupUserPermission
- `functions/logging.php`: logAudit
- `functions/request.php`: flashAlert, redirect
- `functions/sanitize.php`: escapeHtml, escapeSql
- `functions/ticket.php`: ticketReference, ticketWorkTimesReady

**ITFlow tables** clients, notifications, ticket_replies, tickets

**ITFlow columns** client_id, client_name, notification_timestamp, notification_type, notification_user_id, ticket_client_id, ticket_id, ticket_number, ticket_prefix, ticket_reply_archived_at, ticket_reply_by, ticket_reply_created_at, ticket_reply_id, ticket_reply_ticket_id, ticket_reply_time_worked, ticket_reply_type, ticket_reply_work_end, ticket_reply_work_start, ticket_subject, user_id

**Watched in ITFlow's code** `ticket_reply_time_worked` `#hours` `#minutes` `#seconds` `flashAlert`

**"ITFlow does this too" words** work_start, work_end, time_start, started_at

**Our files** `functions/mit_reply_times.php`

<a id="quotes"></a>
## Quote pricing (cost, markup / margin) and line images (`quotes`)

**Takes for granted**

- Quotes / invoices / recurring are one page: lines saved by agent/post/billing.php (billing_item_save ...), drawn by includes/billing_paper.php.
- products.product_cost / product_vendor_id are ITFlow's (0 = none); quote_items keep our item_cost / price method / image.
- PDFs by functions/billing_pdf.php.

**Tests** `tests/smoke/60-quote-pricing.spec.js`, `tests/smoke/65-quote-images.spec.js`, `tests/scenarios/scenario-quote-convert-same-name.php`, `tests/scenarios/scenario-quote-pricing.php`

**Hooks in ITFlow's files**

- `agent/invoice.php`: mitPaperLineExtras, mit_quotes, mit_staff
- `agent/quote.php`: mitPaperLineExtras, mitQuoteProfitBox, mit_quotes, mit_staff
- `api/v1/products/read.php`: mitProductApiColumns
- `api/v1/read_output.php`: mitApiHideCost, mit_quotes
- `functions.php`: mit_quotes
- `functions/backup.php`: mit_feedback
- `functions/quote_images.php`: mit_images
- `includes/billing_paper.php`: mitPaperAddRow, mitPaperLineExtras, mit_quotes, mit_add_row
- `js/billing_items.js`: mitBillingRefresh, mit_billing_add

**ITFlow functions it calls**

- `functions/billing.php`: billingMoney, billingType, quoteFrequencies, quoteFrequency
- `functions/quote_images.php`: quoteImagesShown
- `functions/quote_pricing.php`: quotePricingSettings
- `functions/sanitize.php`: escapeHtml
- `functions/tax.php`: taxDefaultFor, taxOptionsHtml

**ITFlow tables** assets, contacts, credentials, files, products, quote_items, quotes, taxes

**ITFlow columns** client_id, invoice_id, item_cost, item_frequency, item_id, item_image, item_price, item_price_method, item_price_percent, item_quantity, item_quote_id, product_archived_at, product_cost, product_id, product_image, product_name, quote_currency_code, quote_discount_amount, quote_id, tax_id

**ITFlow page ids / classes its scripts and styles use** `#add-line`

**Watched in ITFlow's code** `billing_item_save` `billing_item_add` `billing_paper` `product_cost`

**"ITFlow does this too" words** margin, markup, item_cost, product_cost, item_image, product_image, price_method

**Our files** `functions/mit_quotes.php`, `js/mit_quote_pricing.js`, `js/mit_billing_add.js`

<a id="procurement"></a>
## Procurement (to order, POs, receiving, ticket parts, parts on To Bill, vendor bills) (`procurement`)

**Takes for granted**

- Stock moves through ITFlow's productStockMove / product_stock (productStockShortfall, productStockReturnInvoice).
- Quotes become invoices by quoteConvert (quoteLineGroups); invoices by billingInvoiceCreate / billingRecalc / billingLineTax.
- Expenses by expenseSave; assets carry asset_invoice_id / _item_id / _product_id.
- ITFlow 3.5.0: rates and net terms come from the client's contract (contractTicketRate / contractClientRate / contractNetTerms) - clients.client_rate / client_net_terms are gone.
- To Bill: ITFlow's Unbilled Time page builds its clients with billingUnbilledByClient / expenseUnbilledMerge / tripUnbilledMerge and bills them with expenseBillWithTime (agent/post/billing.php); js/unbilled_time.js's update() runs on changes to [data-unbilled-expense-pick] / [data-unbilled-expense-price].

**Tests** `tests/smoke/62-procurement-roles.spec.js`, `tests/smoke/63-parts-and-invoice-orders.spec.js`, `tests/smoke/60-quote-pricing.spec.js`, `tests/scenarios/scenario-part-line-qty.php`, `tests/smoke/85-to-bill.spec.js`, `tests/smoke/37-po-project.spec.js`, `tests/scenarios/scenario-procurement-flow.php`, `tests/scenarios/scenario-procurement-project.php`, `tests/scenarios/scenario-procurement-stock.php`, `tests/scenarios/scenario-procurement-undo-receipt.php`, `tests/scenarios/scenario-contract-terms.php`

**Hooks in ITFlow's files**

- `agent/ajax.php`: mitPartLineQtyProblem, mit_why
- `agent/includes/client_side_nav.php`: mitToBillPartsCount, mit_client_parts
- `agent/includes/get_side_nav_counts.php`: mitToBillPartsCount, mit_to_bill
- `agent/includes/side_nav.php`: mit_to_bill
- `agent/invoice.php`: mitProcInvoiceMenuItem, mitProcInvoiceSummary, mit-invoice
- `agent/modals/purchasing/po_decide.php`: mitPoSeenTotalField
- `agent/modals/quote/quote_to_invoice.php`: mitProcQuoteConvertFields
- `agent/post/billing.php`: mitPartLineQtyProblem, mitToBillAfter, mitToBillFlash, mitToBillMerge, mit_why
- `agent/post/client.php`: mitTicketPartsDeleting
- `agent/post/purchase_order.php`: mitProcLock, mitProcReceiveReview
- `agent/post/quote.php`: mitProcAfterQuoteConvert
- `agent/project.php`: mitProcProjectCard
- `agent/purchase_order.php`: mitPoItemFor, mitPoProjectMenuItem
- `agent/receiving.php`: mitProcReceivingExtras
- `agent/ticket.php`: mitTicketPartsCard
- `agent/unbilled_time.php`: mitToBillCard, mitToBillCell, mitToBillHead, mitToBillMerge, mitToBillRowAttrs, mitToBillScript, mitToBillStatNote, mitToBillTotals, mit_part_value, mit_to_bill
- `api/v1/clients/delete.php`: mitTicketPartsDeleting
- `functions.php`: mit_procurement
- `functions/billing.php`: mitProcQuoteConvertGroups
- `functions/purchase_orders.php`: mitPoMayApproveOwn, mitPoSeenTotalProblem, mitPoTellSwitches, mitProcAfterReceive, mitProcReceiveNoShelf, mitProcUndoReceiptExtras, mit_po_receipt_extras, mit_bell, mit_own
- `functions/ticket.php`: mitTicketPartsDeleting
- `includes/cron_jobs.php`: mit_procurement_parts, mit_procurement_tidy
- `js/unbilled_time.js`: mitToBillParts, mit_to_bill

**ITFlow functions it calls**

- `cron/includes/cron_lock.php`: cronJobStop
- `functions/app.php`: getFieldById
- `functions/auth.php`: clientScopeSql, enforceUserPermission, hasClientAccess, lookupUserPermission
- `functions/billing.php`: billingInvoiceCreate, billingItems, billingLineTax, billingMoney, billingRecalc
- `functions/contract_billing.php`: contractClientRate, contractNetTerms
- `functions/logging.php`: logAudit, logTicketHistory
- `functions/purchase_orders.php`: poAccessible, poApprovers, poForRequest, poLevel, poNumber, poReady, poRequireLevel, poRow, poScopeSql, poStatusBadge, poTrackingFor, poTrackingHtml
- `functions/request.php`: flashAlert, redirect
- `functions/sanitize.php`: escapeHtml, escapeSql
- `functions/security.php`: randomString, validateCSRFToken
- `functions/settings.php`: moduleOn
- `functions/ticket.php`: ticketForModal, ticketForRequest, ticketReference

**ITFlow tables** assets, categories, clients, expenses, history, invoice_items, invoices, logs, order_items, po_items, product_stock, products, projects, purchase_orders, quote_items, settings, taxes, ticket_assets, ticket_replies, tickets, trips, vendors

**ITFlow columns** asset_archived_at, asset_client_id, asset_id, asset_invoice_id, asset_invoice_item_id, asset_name, asset_type, category_archived_at, category_id, category_name, category_type, client_abbreviation, client_id, client_name, company_id, config_enable_cron, config_invoice_next_number, history_description, history_invoice_id, history_status, invoice_amount, invoice_archived_at, invoice_category_id, invoice_client_id, invoice_currency_code, invoice_date, invoice_discount_amount, invoice_due, invoice_id, invoice_number, invoice_prefix, invoice_scope, invoice_status, invoice_url_key, item_cost, item_description, item_frequency, item_id, item_invoice_id, item_name, item_order, item_price, item_product_id, item_quantity, item_quote_id, item_subtotal, item_tax, item_tax_id, item_total, log_action, log_description, log_entity_id, log_type, log_user_id, order_item_client_id, order_item_cost, order_item_created_by, order_item_description, order_item_id, order_item_name, order_item_note, order_item_product_id, order_item_quantity, order_item_source, order_item_status, order_item_vendor_id, po_created_by, po_id, po_item_client_id, po_item_cost, po_item_id, po_item_name, po_item_order, po_item_order_item_id, po_item_po_id, po_item_product_id, po_item_quantity, po_item_received, po_number, po_prefix, po_receipt_asset_ids, po_receipt_id, po_receipt_item_id, po_receipt_quantity, po_status, po_total, po_vendor_id, po_vendor_reference, product_archived_at, product_cost, product_description, product_id, product_name, product_price, product_tax_id, product_track_stock, product_type, product_vendor_id, project_archived_at, project_client_id, project_completed_at, project_id, project_name, project_number, project_prefix, stock_invoice_id, stock_item_id, stock_note, stock_product_id, stock_qty, tax_archived_at, tax_id, tax_name, tax_percent, ticket_client_id, ticket_closed_at, ticket_id, ticket_number, ticket_prefix, ticket_project_id, ticket_reply, ticket_reply_by, ticket_reply_ticket_id, ticket_reply_time_worked, ticket_reply_type, ticket_resolved_at, ticket_subject, vendor_archived_at, vendor_client_id, vendor_id, vendor_name

**Watched in ITFlow's code** `productStockMove` `quoteConvert` `quoteLineGroups` `billingInvoiceCreate` `expenseSave` `productStockShortfall` `expenseUnbilledMerge` `tripUnbilledMerge` `expenseBillWithTime` `data-unbilled-expense-pick` `itflowUnbilledTime`

**"ITFlow does this too" words** purchase_order, purchase, procure, receiv, po_, stock, vendor, reorder, unbilled

**Our files** `functions/mit_procurement.php`, `functions/mit_procurement_extras.php`, `functions/mit_procurement_parts.php`, `agent/mit_unbilled_parts.php`, `agent/modals/mit_procurement/from_invoice.php`, `agent/modals/mit_procurement/part.php`, `agent/modals/mit_procurement/parts_bill.php`, `agent/modals/mit_procurement/po_project.php`, `agent/post/mit_procurement_parts.php`, `cron/mit_procurement_tidy.php`, `functions/mit_to_bill.php`, `js/mit_to_bill.js`

<a id="quick_ticket"></a>
## Quick ticket (top-bar window: client, contact, issue; the contact's open tickets; timer started) (`quick_ticket`)

**Takes for granted**

- Make ticket posts ITFlow's own New Ticket form: agent/post/ticket.php add_ticket (client_id, contact_id, subject, details as HTML, priority, category_id, assigned_to, billable, mit_board) - it redirects to ticket.php?client_id=&ticket_id=.
- ITFlow's ticket timer lives in the browser: agent/js/ticket_time_tracking.js reads <id>-startTime / <id>-pausedTime / ticket-timer-running-<id> on DOMContentLoaded; our start is written by an inline script printed with mitTicketLayoutHtml, before that script runs.
- Admin > Settings > Tickets is ITFlow's settings frame (settingsSectionStart, settingsSelect, settingsSwitch); edit_ticket_settings saves through settingsSave with $also_changed.
- ticketPickableStatuses / ticketChangeStatus set a status other than New / Resolved / Closed; ticketRows lists tickets with their status and assignee.

**Tests** `tests/smoke/89-quick-ticket.spec.js`

**Watched in ITFlow's code** `ticket_time_tracking.js` `getLocalStorageKey("startTime")` `ticket-timer-running-` `ticketPickableStatuses` `ticketChangeStatus` `edit_ticket_settings` `selectOptionContact`

**"ITFlow does this too" words** quick_ticket, quickticket, quick_add, fast_ticket

**Our files** 

<a id="invoice_bulk"></a>
## More bulk actions on the invoices list (mark sent / paid, send by email, cancel, delete) (`invoice_bulk`)

**Takes for granted**

- agent/invoices.php has ITFlow's Bulk Action menu (#bulkActionButton, items with data-bulk="true" and data-modal-url) and ticks named invoice_ids[]; js/bulk_actions.js adds the ticked ids to the modal URL.
- Each action copies ITFlow's own single-invoice handler (agent/post/invoice.php mark_invoice_sent / email_invoice quick send / cancel_invoice / delete_invoice, agent/post/payment.php add_payment) - re-check ours when those change (new rows to clear on delete, new history wording).
- Mark Sent is for Draft, payments for invoiceOpenStatuses() with a balance, Cancel not for Cancelled / Paid / Non-Billable - the same rules agent/invoice.php shows its buttons by.

**Tests** `tests/scenarios/scenario-invoice-bulk.php`

**Watched in ITFlow's code** `bulkActionButton` `data-bulk="true"` `invoice_ids[]` `mark_invoice_sent` `productStockReturnInvoice` `reconcileInvoiceLock` `updateInvoiceStatusFromPayments` `billingPaymentEmail`

**"ITFlow does this too" words** bulk_mark_paid, bulk_delete_invoice, bulk_cancel_invoice, bulk_mark_sent, bulk_email_invoice, invoice_bulk

**Our files** 

<a id="timesheets"></a>
## Timesheets, payroll, leave, California rules, holidays, reimbursements (`timesheets`)

**Takes for granted**

- Ticket time = ticket_replies.ticket_reply_time_worked by ticket_reply_by; billable by tickets.ticket_billable.
- Mileage is ITFlow trips (trip_own_vehicle, tripClaim, config_mileage_rate); purchases are ITFlow expenses (expense_paid_by, expenseReimburse, reimbursementDelete).
- Closure days are business_holidays (getBusinessHolidays, restampOpenSlaTickets).
- change_client_ticket turns public replies internal (mitTsKeepPublicNotes runs first).

**Tests** `tests/smoke/04-permissions.spec.js`, `tests/smoke/05-california.spec.js`, `tests/smoke/19-own-employment.spec.js`, `tests/smoke/29-payroll-payback.spec.js`, `tests/smoke/31-rest-breaks.spec.js`, `tests/smoke/70-ts-permissions.spec.js`, `tests/smoke/71-payroll-numbers.spec.js`, `tests/smoke/72-module-switches.spec.js`, `tests/smoke/74-ts-ticket-moves.spec.js`, `tests/smoke/75-ts-admin-screens.spec.js`, `tests/smoke/76-trip-locks.spec.js`, `tests/smoke/77-sign-in-security.spec.js`, `tests/smoke/78-portal-sessions.spec.js`, `tests/smoke/79-platform-pages.spec.js`, `tests/scenarios/scenario-ts-approval-off.php`, `tests/scenarios/scenario-ts-california.php`, `tests/scenarios/scenario-ts-holidays.php`, `tests/scenarios/scenario-ts-leave.php`, `tests/scenarios/scenario-ts-misc.php`, `tests/scenarios/scenario-ts-payroll-payback.php`, `tests/scenarios/scenario-ts-period-hourly.php`, `tests/scenarios/scenario-ts-rate-missing.php`, `tests/scenarios/scenario-ts-zone-switch-back.php`, `tests/scenarios/fixture-hourly.php`

**Hooks in ITFlow's files**

- `admin/modals/user/user_form.php`: mitTsPayTypeField
- `admin/post/users.php`: mitInviteExtrasSave, mitTsSavePayType
- `agent/ajax.php`: mit_ticket_layout
- `agent/includes/side_nav.php`: mit_timesheet_nav
- `agent/modals/trip/trip_form.php`: mitReimbTripRateNote
- `agent/post/client.php`: mitTsDeleteGuard
- `agent/post/ticket.php`: mitTsReplyTimeGuard, mit_reply_times
- `agent/post/trip.php`: mitReimbTripLocked, mitReimbTripWhyLocked, mit_why
- `agent/ticket.php`: mitReimbMenuItem, mitReimbTicketCard, mit_ticket_layout
- `agent/user/post/profile.php`: mitTsSavePreferences
- `agent/user/user_preferences.php`: mitTsPreferenceFields
- `api/v1/clients/delete.php`: mitTsDeleteBlocked
- `functions.php`: mit_invites, mit_ticket_layout, mit_timesheets
- `functions/backup.php`: mit_receipts
- `functions/expenses.php`: mitReimbExpenseWhyLocked, mitReimbReimbursementWhyLocked
- `functions/reports.php`: mitTsReportEntry, mit_payroll, mit_timesheets
- `functions/ticket.php`: mitTsDeleteGuard
- `functions/user_invite.php`: mitInviteExtrasApply
- `functions/user_timezone.php`: mitTsSaveZone, mit_timesheets
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
- `functions/user_timezone.php`: timezoneUsersReady

**ITFlow tables** accounts, business_holidays, categories, clients, companies, credentials, expenses, history, modules, notifications, reimbursements, settings, tags, ticket_replies, tickets, trips, user_role_permissions, user_roles, user_settings, users, vendors

**ITFlow columns** account_archived_at, account_id, account_name, category_archived_at, category_id, category_name, category_type, client_id, client_name, company_currency, company_id, config_enable_cron, config_mileage_rate, expense_account_id, expense_amount, expense_archived_at, expense_category_id, expense_client_id, expense_currency_code, expense_date, expense_description, expense_id, expense_paid_by, expense_receipt, expense_reference, expense_reimbursement_id, expense_vendor_id, holiday_date, holiday_id, holiday_name, invite_id, invoice_id, module_id, module_name, notification_action, notification_entity_id, notification_timestamp, notification_type, notification_user_id, reimbursement_date, reimbursement_expense_id, reimbursement_id, reimbursement_reference, reimbursement_user_id, role_archived_at, role_id, role_is_admin, role_name, round_trip, ticket_billable, ticket_client_id, ticket_closed_at, ticket_id, ticket_number, ticket_prefix, ticket_reply_archived_at, ticket_reply_by, ticket_reply_created_at, ticket_reply_id, ticket_reply_ticket_id, ticket_reply_time_worked, ticket_reply_type, ticket_reply_work_start, ticket_subject, trip_archived_at, trip_client_id, trip_date, trip_destination, trip_expense_id, trip_id, trip_miles, trip_own_vehicle, trip_purpose, trip_source, trip_ticket_id, trip_user_id, user_archived_at, user_avatar, user_config_timezone, user_created_at, user_email, user_id, user_name, user_role_id, user_role_permission_level, user_status, user_type, vendor_archived_at, vendor_client_id, vendor_description, vendor_id, vendor_name

**Watched in ITFlow's code** `ticket_reply_time_worked` `trip_own_vehicle` `tripClaim` `expenseReimburse` `reimbursementDelete` `expense_paid_by` `config_mileage_rate` `getBusinessHolidays`

**"ITFlow does this too" words** timesheet, payroll, leave, pto, overtime, time_entr, clock, reimburse, mileage, trip, holiday, pay_period

**Our files** `functions/mit_timesheet_view.php`, `functions/mit_timesheets.php`, `functions/mit_ts_california.php`, `functions/mit_ts_holidays.php`, `functions/mit_leave.php`, `functions/mit_invites.php`, `functions/mit_reimbursements.php`, `agent/mit_timesheet.php`, `agent/mit_timesheet_ajax.php`, `agent/mit_timesheets.php`, `agent/js/mit_timesheet.js`, `agent/modals/mit_timesheet/day.php`, `agent/modals/mit_timesheet/decide.php`, `agent/modals/mit_timesheet/leave.php`, `agent/modals/mit_reimburse/reimburse.php`, `agent/post/mit_timesheets.php`, `agent/post/mit_reimbursements.php`, `agent/mit_receipt.php`, `agent/reports/mit_payroll.php`, `agent/includes/mit_timesheet_nav.php`, `admin/mit_timesheets.php`, `admin/modals/mit_timesheets/charge_code.php`, `admin/modals/mit_timesheets/holiday.php`, `admin/modals/mit_timesheets/leave_bank.php`, `admin/modals/mit_timesheets/person.php`, `admin/modals/mit_timesheets/person_leave.php`, `admin/post/mit_timesheets.php`, `cron/mit_timesheets.php`

<a id="qbo"></a>
## QuickBooks Online (approvals, batches, locks, payments back) (`qbo`)

**Takes for granted**

- Every way an invoice or its payments change goes through a guarded handler (agent/post/invoice.php, billing.php, payment.php, ajax.php, API, nightly, portal, guest pay, bank import, reprice).
- Payments: payments table + updateInvoiceStatusFromPayments; Stripe booking in billingStripeBook.
- Recurring invoices by recurringInvoiceGenerate (auto-send condition).
- Income accounts: products / invoices carry an Income category (product_category_id, invoice_category_id); ticket time is billed by billingTimeInvoice, its lines named by its item_name; the Edit Line window is agent/modals/billing/billing_item.php, saved by billing_item_save.

**Tests** `tests/smoke/10-qbo.spec.js`, `tests/smoke/61-qbo-guards.spec.js`, `tests/smoke/66-qbo-more-doors.spec.js`, `tests/smoke/91-qbo-accounts.spec.js`, `tests/scenarios/scenario-qbo-accounts.php`, `tests/scenarios/scenario-qbo-billing-email.php`, `tests/scenarios/scenario-qbo-discount-account.php`, `tests/scenarios/scenario-qbo-flow.php`, `tests/scenarios/scenario-qbo-nightly.php`, `tests/scenarios/scenario-qbo-payments.php`, `tests/scenarios/scenario-qbo-terms.php`

**Hooks in ITFlow's files**

- `agent/ajax.php`: mitQboInvoiceLock, mit_why
- `agent/invoice.php`: mitQboInvoiceBar, mit-invoice
- `agent/invoices.php`: mitQboListBadge, mit_qbo_ids
- `agent/modals/billing/billing_item.php`: mitQboLineCategoryField
- `agent/post/billing.php`: mitQboGuardInvoice, mitQboInvoiceLock, mitQboLineCategorySave, mit_why
- `agent/post/client.php`: mitQboGuardClientDelete
- `agent/post/expense.php`: mitQboGuardInvoice
- `agent/post/invoice.php`: mitQboGuardEmail, mitQboGuardInvoice
- `agent/post/payment.php`: mitQboGuardInvoice, mitQboGuardPayment, mitQboGuardPaymentEdit, mitQboTakesPayments
- `agent/post/product.php`: mit_discount
- `agent/post/ticket.php`: mitQboGuardInvoice
- `agent/post/trip.php`: mitQboGuardInvoice
- `api/v1/clients/delete.php`: mitQboClientLock
- `api/v1/invoice_items/create.php`: mitQboInvoiceLock
- `client/pay.php`: mitQboTakesPayments
- `client/post.php`: mitQboGuardPayment, mit_invoice_id
- `cron/nightly_tasks.php`: mitQboSendsReminders, mitQboTakesPayments
- `functions.php`: mit_discount, mit_qbo
- `functions/billing.php`: mitQboHandlesInvoiceMail, mitQboTagTimeLines, MountaineerIT, mit_hold
- `functions/billing_pay.php`: mitQboCardPaymentBooked
- `functions/income_import.php`: mitQboTakesPayments
- `functions/invoice_actions.php`: mitQboConnected, mitQboInvoiceLock, mit_qbo, mit_qbo_billing
- `functions/products.php`: mitQboInvoiceLock
- `guest/guest_ajax.php`: mitQboTakesPayments
- `guest/guest_pay_invoice_stripe.php`: mitQboCardPaymentBooked
- `includes/cron_jobs.php`: mit_qbo_billing, mit_qbo_sync

**ITFlow functions it calls**

- `cron/includes/cron_lock.php`: cronJobStop
- `functions/accounts.php`: accountType
- `functions/app.php`: getFieldById
- `functions/auth.php`: clientScopeSql, enforceUserPermission, lookupUserPermission
- `functions/logging.php`: appNotify, logAudit
- `functions/payments.php`: updateInvoiceStatusFromPayments
- `functions/reconcile.php`: reconcileLock
- `functions/request.php`: flashAlert, redirect
- `functions/sanitize.php`: escapeHtml, escapeSql
- `functions/security.php`: validateCSRFToken
- `functions/tax.php`: taxSettings

**ITFlow tables** accounts, categories, clients, companies, contacts, expenses, history, invoice_items, invoices, payments, products, settings, taxes, tickets, users

**ITFlow columns** account_archived_at, account_id, account_name, added_by, category_archived_at, category_id, category_name, category_type, client_archived_at, client_id, client_name, company_currency, company_id, company_name, config_enable_cron, contact_archived_at, contact_billing, contact_client_id, contact_email, contact_name, contact_primary, history_description, history_invoice_id, history_status, invoice_amount, invoice_archived_at, invoice_category_id, invoice_client_id, invoice_credit_amount, invoice_currency_code, invoice_date, invoice_discount_amount, invoice_due, invoice_id, invoice_note, invoice_number, invoice_prefix, invoice_scope, invoice_status, item_archived_at, item_description, item_id, item_invoice_id, item_name, item_order, item_price, item_product_id, item_quantity, item_tax_id, item_total, payment_account_id, payment_amount, payment_currency_code, payment_date, payment_deposit_transfer_id, payment_id, payment_invoice_id, payment_method, payment_reconciliation_id, payment_reference, product_archived_at, product_category_id, product_id, product_name, product_price, product_type, tax_archived_at, tax_id, tax_name, tax_percent, user_id, user_name

**Watched in ITFlow's code** `updateInvoiceStatusFromPayments` `billingStripeBook` `recurringInvoiceGenerate` `productRepriceApply` `income_import` `billingTimeInvoice` `product_category_id` `invoice_category_id`

**"ITFlow does this too" words** quickbooks, qbo, xero, accounting_sync, intuit

**Our files** `functions/mit_qbo.php`, `functions/mit_qbo_accounts.php`, `functions/mit_qbo_billing.php`, `functions/mit_qbo_terms.php`, `admin/mit_qbo.php`, `admin/mit_qbo_callback.php`, `admin/post/mit_qbo.php`, `agent/mit_invoice_approvals.php`, `agent/mit_qbo_batch.php`, `agent/mit_qbo_batches.php`, `agent/post/mit_qbo.php`, `cron/mit_qbo_sync.php`

<a id="usage"></a>
## Usage billing (counts on recurring lines, connectors, API) (`usage`)

**Takes for granted**

- recurringInvoiceGenerate copies recurring lines in item_order; usage sets their quantity before auto-send.
- api/v1/enforce_api_rbac.php maps endpoints to modules.
- Seat lines (26.11.3.7): ITFlow's recurringSeatSources / recurringSeatFilter / recurringSeatCount / recurringSeatDescribe take our "usage" source (filter {"metric": name}); billingItemSave posts seat_metric; the Edit Line window shows [data-seat-for=<source>] sections and live-counts through ajax.php recurring_seat_count; a null count leaves the quantity.

**Tests** `tests/smoke/11-usage.spec.js`, `tests/smoke/12-connectors.spec.js`, `tests/scenarios/scenario-usage.php`, `tests/scenarios/scenario-usage-seats.php`

**Hooks in ITFlow's files**

- `admin/settings_invoice.php`: mit_usage_card
- `agent/client_overview.php`: mitUsageClientCard
- `agent/modals/billing/billing_item.php`: mitSeatNotTagOptions, mitUsageSeatField, mitUsageSeatOption
- `api/v1/enforce_api_rbac.php`: mit_usage
- `functions.php`: mit_usage
- `functions/billing.php`: mitSeatNotTagDescribe, mitSeatNotTagFilter, mitSeatNotTagSql, mitUsageApply, mitUsageSeatCount, mitUsageSeatDescribe, mitUsageSeatFilter, mitUsageSeatSources, MountaineerIT, mit_hold, mit_not
- `functions/invoice_actions.php`: mit_qbo_billing
- `includes/cron_jobs.php`: mit_qbo_billing, mit_usage

**ITFlow functions it calls**

- `api/v1/enforce_api_rbac.php`: apiClientScopeSql
- `cron/includes/cron_lock.php`: cronJobStop
- `functions/app.php`: getFieldById
- `functions/auth.php`: clientScopeSql, enforceClientAccess, enforceUserPermission, lookupUserPermission
- `functions/billing.php`: billingLineTax, billingRecalc, recurringSeatCount, recurringSeatDescribe
- `functions/logging.php`: logAudit
- `functions/request.php`: flashAlert, redirect
- `functions/sanitize.php`: escapeHtml, escapeSql
- `functions/security.php`: validateCSRFToken
- `functions/tags.php`: tagRow

**ITFlow tables** asset_tags, assets, clients, contact_tags, contacts, history, invoice_items, invoices, products, recurring_invoice_items, recurring_invoices, settings, tags, taxes, users

**ITFlow columns** asset_id, asset_tag_asset_id, asset_tag_tag_id, client_archived_at, client_id, client_name, company_id, config_enable_cron, contact_archived_at, contact_client_id, contact_id, history_description, history_invoice_id, history_status, invoice_amount, invoice_archived_at, invoice_client_id, invoice_currency_code, invoice_date, invoice_discount_amount, invoice_id, invoice_number, invoice_prefix, invoice_status, item_archived_at, item_id, item_invoice_id, item_name, item_order, item_price, item_quantity, item_recurring_invoice_id, item_seat_filter, item_seat_minimum, item_seat_source, item_subtotal, item_tax, item_tax_id, item_total, recurring_invoice_archived_at, recurring_invoice_client_id, recurring_invoice_id, recurring_invoice_number, recurring_invoice_prefix, recurring_invoice_status, tag_archived_at, tag_id, tag_name, tag_type, tax_id, tax_percent, user_id, user_name

**Watched in ITFlow's code** `item_order` `item_seat_source` `enforce_api_rbac` `recurringSeatSources` `recurringSeatFilter` `recurringSeatCount` `recurringSeatDescribe` `recurringInvoiceRefresh` `data-seat-for` `recurring_seat_count`

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

**Tests** `tests/smoke/18-customer-thermometer.spec.js`, `tests/scenarios/scenario-ct-responses.php`

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
- `functions/ticket.php`: ticketNoResponseReady, ticketReference

**ITFlow tables** clients, contacts, credits, settings, ticket_history, tickets, users

**ITFlow columns** client_abbreviation, client_archived_at, client_id, client_name, company_id, config_enable_cron, contact_archived_at, contact_email, contact_id, contact_name, ticket_archived_at, ticket_assigned_to, ticket_client_id, ticket_closed_at, ticket_contact_id, ticket_history_description, ticket_history_ticket_id, ticket_id, ticket_no_response_at, ticket_number, ticket_prefix, ticket_resolved_at, ticket_subject, user_id, user_name

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
- callAiApi posts messages as given (OpenAI chat format) - a user message made of parts (text + image_url, pictures with an Ask AI question) passes through; our one line in its redaction loop redacts the text parts.
- Documents: add_document handler and modals/document/document_form.php; archived = *_archived_at.

**Tests** `tests/smoke/32-ai-helper.spec.js`, `tests/smoke/33-ai-panel.spec.js`, `tests/smoke/34-ai-document.spec.js`, `tests/smoke/35-ai-local.spec.js`, `tests/smoke/69-ai-pictures.spec.js`, `tests/scenarios/scenario-ai-ask.php`, `tests/scenarios/scenario-ai-document.php`, `tests/scenarios/scenario-ai-documents.php`, `tests/scenarios/scenario-ai-library.php`, `tests/scenarios/scenario-ai-local.php`, `tests/scenarios/scenario-ai-pictures.php`, `tests/scenarios/scenario-ai-scrub.php`, `tests/scenarios/scenario-ai-wall.php`

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

**ITFlow tables** ai_models, ai_providers, ai_usage, clients, documents, files, folders, history, networks, settings, tags, ticket_replies, tickets

**ITFlow columns** ai_model_name, ai_provider_api_key, ai_provider_api_url, ai_provider_id, ai_provider_kind, ai_provider_name, ai_provider_redact, ai_usage_created_at, ai_usage_feature, ai_usage_tokens_in, ai_usage_tokens_out, client_archived_at, client_id, client_name, company_id, config_enable_cron, document_archived_at, document_client_id, document_content, document_created_at, document_description, document_id, document_name, document_updated_at, ticket_archived_at, ticket_client_id, ticket_closed_at, ticket_created_at, ticket_details, ticket_id, ticket_number, ticket_prefix, ticket_reply, ticket_reply_archived_at, ticket_reply_created_at, ticket_reply_id, ticket_reply_ticket_id, ticket_reply_type, ticket_reply_updated_at, ticket_resolved_at, ticket_subject, ticket_updated_at, user_id

**Watched in ITFlow's code** `callAiApi` `aiRedact` `getAiModel` `aiCleanHtml` `aiDocumentSplit` `document_form.php`

**"ITFlow does this too" words** embedding, knowledge, assistant, copilot, ai_search, ai_chat, ai_ask, ai_answer

**Our files** `functions/mit_ai_ask.php`, `functions/mit_ai_diagram.php`, `functions/mit_ai_docs.php`, `functions/mit_ai_helper.php`, `functions/mit_ai_local.php`, `admin/mit_ai_helper.php`, `admin/post/mit_ai_helper.php`, `agent/mit_ai_ajax.php`, `agent/js/mit_ai_helper.js`, `agent/modals/mit_ai/document.php`, `cron/mit_ai_index.php`

<a id="feedback"></a>
## Bugs & ideas (bug reports and feature requests in the top bar) (`feedback`)

**Takes for granted**

- The top bar is includes/top_nav.php, which requires our includes/mit_timers_nav.php (it echoes mitFeedbackNav).
- Admin > Debug is admin/debug.php (one require of our switch card before its script).
- ITFlow's notifications table (notification_type / notification / notification_action / notification_user_id / notification_entity_id) is the bell.
- Admins are users whose role has role_is_admin = 1 ($session_is_admin).

**Tests** `tests/smoke/84-feedback.spec.js`

**Hooks in ITFlow's files**

- `admin/debug.php`: mit_feedback_card
- `cron/mail_queue.php`: mit_common
- `functions.php`: mit_common
- `functions/backup.php`: mit_feedback
- `includes/top_nav.php`: mit_timers_nav

**ITFlow functions it calls**

- `functions/logging.php`: logAudit
- `functions/request.php`: flashAlert, redirect
- `functions/sanitize.php`: escapeHtml
- `functions/security.php`: validateCSRFToken

**ITFlow tables** files, notifications, user_roles, users

**ITFlow columns** notification_action, notification_entity_id, notification_type, notification_user_id, role_id, role_is_admin, user_archived_at, user_id, user_name, user_role_id, user_status, user_type

**Watched in ITFlow's code** `notification_action` `role_is_admin` `session_is_admin`

**"ITFlow does this too" words** feedback, feature_request, bug_report, suggestion

**Our files** `functions/mit_feedback.php`, `agent/mit_feedback_ajax.php`, `agent/js/mit_feedback.js`, `admin/includes/mit_feedback_card.php`, `admin/post/mit_feedback_settings.php`

<a id="service_users"></a>
## API / service accounts (logins API keys act as - out of every agent list, no browser sign-in) (`service_users`)

**Takes for granted**

- ITFlow's API keys act as an agent (api_keys.api_key_user_id) who must be an active agent with a role (apiKeyAgentProblem).
- Agent lists are queries with user_type = 1 on users - each carries one guarded mitServiceUserSql(...) after that condition.
- Agent sign-in is login.php (the user loop) and includes/load_user_session.php on every agent / admin page.

**Tests** `tests/smoke/96-service-users.spec.js`, `tests/scenarios/scenario-service-users.php`

**ITFlow functions it calls**

- `functions/service_account.php`: serviceAccountIs, serviceAccountsReady

**ITFlow tables** users

**ITFlow columns** user_id, user_service_account

**Watched in ITFlow's code** `api_key_user_id` `apiKeyAgentProblem` `quickPickAgents` `ticketMentionAgents` `signInAdmins`

**"ITFlow does this too" words** service_account, api_user, user_is_api, bot_user

**Our files** `functions/mit_service_users.php`

<a id="contract_unlimited"></a>
## Unlimited support hours on contracts: a switch beside Hours each month, every billable ticket drawn in full by ITFlow's prepaid hours, read as "Unlimited" (`contract_unlimited`)

**Takes for granted**

- ITFlow's prepaid hours (functions/contract_hours.php, database 3.4.4): contract_monthly_hours makes a block each month (contractHoursEnsureMonthly / contractHoursSync) and contractHoursDraw takes billable done tickets' time from blocks with hours left, the rest left to bill.
- Every page prints hours through contractHoursText / contractHoursWords; contractBlocks gives each block's used / left / expired; contractFromInput checks the hours (under 1,000) for contracts and templates.
- The contract and template windows have a monthly_hours and an hours_alert box (Cover tab); js/contract_form.js fills them from a template picked on New Contract.

**Tests** `tests/smoke/55-contract-unlimited.spec.js`, `tests/scenarios/scenario-contract-unlimited.php`

**Hooks in ITFlow's files**

- `admin/modals/contract_template/contract_template_form.php`: mitContractUnlimitedField
- `agent/contract.php`: mitContractUnlimitedAgentFigures, MountaineerIT, mit_figures
- `agent/modals/contract/contract_form.php`: mitContractUnlimitedField
- `client/contract.php`: mitContractUnlimitedPortalFigures, MountaineerIT, mit_figures
- `functions.php`: mit_contract_unlimited
- `functions/contract.php`: mitContractUnlimitedInput, MountaineerIT
- `functions/contract_hours.php`: mitContractBlocksUnlimited, mitContractHoursLabel, MountaineerIT
- `functions/contract_signature.php`: mitContractUnlimitedTermsLine, MountaineerIT

**ITFlow functions it calls**

- `functions/contract_hours.php`: contractHoursText
- `functions/sanitize.php`: escapeHtml

**ITFlow tables** history, tickets

**ITFlow columns** contract_block_hours, contract_block_month, contract_monthly_hours, contract_template_monthly_hours

**Watched in ITFlow's code** `contract_monthly_hours` `contract_template_monthly_hours` `contractHoursDraw` `contractHoursEnsureMonthly` `Hours each month are a number of hours under 1,000` `of support each month, used for that month only`

**"ITFlow does this too" words** unlimited, contract_unlimited, hours_unlimited

**Our files** `functions/mit_contract_unlimited.php`

<a id="discount_percent"></a>
## Percentage discounts: ITFlow's Discount as a % of discountable lines, products "Never discounted", kept up on recurring invoices, posted as a product in QuickBooks (`discount_percent`)

**Takes for granted**

- ITFlow's billingRecalc (functions/billing.php) reads *_discount_amount and works every total out from it - invoices, quotes, recurring invoices; billingLinesOpen says when lines may still change.
- recurringInvoiceGenerate and quoteConvert copy the discount dollars and call billingRecalc on the new document; billingItems gives item_subtotal / item_product_id / item_name.
- agent/modals/billing/billing_form.php has the Discount box named invoice_discount / quote_discount / recurring_invoice_discount, posted to the six add / edit handlers.

**Tests** `tests/smoke/56-discount-percent.spec.js`, `tests/scenarios/scenario-discount-percent.php`

**Hooks in ITFlow's files**

- `agent/modals/billing/billing_form.php`: mitDiscountField
- `agent/modals/product/product_form.php`: mitDiscountProductField, MountaineerIT
- `agent/post/invoice.php`: mitDiscountCopy, mitDiscountSave, MountaineerIT
- `agent/post/product.php`: mitDiscountProductSave, mit_discount
- `agent/post/quote.php`: mitDiscountCopy, mitDiscountSave, MountaineerIT
- `agent/post/recurring_invoice.php`: mitDiscountCopy, mitDiscountSave, MountaineerIT
- `functions.php`: mit_discount, mit_minimum
- `functions/billing.php`: mitDiscountBeforeRecalc, mitDiscountCopy, MountaineerIT, mit_hold
- `functions/billing_pdf.php`: mitDiscountLabel
- `includes/billing_paper.php`: mitDiscountLabel

**ITFlow functions it calls**

- `functions/billing.php`: billingItems, billingLinesOpen, billingRecalc, billingType
- `functions/sanitize.php`: escapeHtml

**ITFlow tables** products

**ITFlow columns** invoice_id, item_id, item_name, item_product_id, item_subtotal, product_archived_at, product_category_id, product_id, product_name, product_price

**Watched in ITFlow's code** `function billingRecalc(` `billingLinesOpen` `_discount_amount` `Taken off the total, after the lines.`

**"ITFlow does this too" words** discount_percent, discount_type, no_discount, discountable

**Our files** `functions/mit_discount.php`

<a id="diagrams"></a>
## Diagrams with draw.io: network and site maps edited inside ITFlow - in Files (editable PNGs, earlier versions kept) and in documents; new ones from the client's assets or drawn by AI (`diagrams`)

**Takes for granted**

- ITFlow's client files live in uploads/clients/<client>/<file_reference_name> (files table: file_name, file_ext, file_size, file_client_id, file_folder_id, file_archived_at); agent/file.php serves them; folderOwnedId checks a folder.
- ITFlow's document save (agent/post/document.php add / edit) turns data: images into files with saveBase64Images(html, <root>/uploads/documents/, "uploads/documents/", id) and keeps the content before in document_versions; documentContentRaw / documentHtmlPurify (classes and the uploads address kept).
- The document editor is TinyMCE ".tinymce" (js/app.js init, toolbar as groups) inside a form with an add_document / edit_document submit; the document page shows the content in .itflow-document-body.
- agent/files.php builds each item's ⋮ menu in filesItemMenu($item) (kind, id, name, archived, row) and has an Upload split button with "Document from template" in its menu.
- A map from assets reads ITFlow's assets (asset_type as in functions/asset_type.php ASSET_TYPES), asset_interfaces (interface_ip / _network_id / _primary), asset_interface_links (interface_a_id / _b_id), networks (network_vlan / network / network_gateway), locations and racks / rack_units (unit_start_number / _end_number / unit_face, rack_units_descending).
- Draw with AI uses ITFlow's callAiApi with getAiModel('Documentation') and OpenAI-style picture parts (image_url), like Ask AI.

**Tests** `tests/smoke/58-diagrams.spec.js`, `tests/scenarios/scenario-diagrams.php`, `tests/scenarios/scenario-diagram-build.php`

**Hooks in ITFlow's files**

- `agent/files.php`: mitDiagramFileMenu, mitDiagramNewMenuItem
- `functions.php`: mit_ai_helper, mit_diagrams
- `functions/backup.php`: mit_diagrams
- `includes/cron_jobs.php`: mit_ai_helper
- `includes/top_nav.php`: mit_timers_nav
- `js/app.js`: mitDiagrams, mit_diagrams

**ITFlow functions it calls**

- `functions/ai.php`: aiReady, callAiApi, getAiModel
- `functions/asset_type.php`: assetTypeName
- `functions/auth.php`: clientScopeSql, enforceClientAccess, enforceUserPermission, lookupUserPermission
- `functions/files.php`: documentContentRaw, folderOwnedId, saveBase64Images
- `functions/logging.php`: logAudit
- `functions/sanitize.php`: escapeHtml, escapeSql
- `functions/security.php`: randomString, validateCSRFToken

**ITFlow tables** asset_interface_links, asset_interfaces, assets, clients, credentials, document_versions, documents, files, history, locations, networks, rack_units, racks, users

**ITFlow columns** ai_provider_redact, asset_archived_at, asset_client_id, asset_id, asset_location_id, asset_make, asset_model, asset_name, asset_type, client_id, document_archived_at, document_client_id, document_content, document_content_raw, document_created_at, document_created_by, document_description, document_id, document_name, document_updated_at, document_updated_by, document_version_content, document_version_created_at, document_version_created_by, document_version_description, document_version_document_id, document_version_name, file_archived_at, file_client_id, file_created_at, file_created_by, file_description, file_ext, file_folder_id, file_id, file_mime_type, file_name, file_reference_name, file_size, file_updated_at, folder_id, interface_a_id, interface_archived_at, interface_asset_id, interface_b_id, interface_id, interface_ip, interface_link_id, interface_name, interface_network_id, interface_primary, location_archived_at, location_client_id, location_id, location_name, network_archived_at, network_client_id, network_gateway, network_id, network_name, network_subnet, network_vlan, rack_archived_at, rack_client_id, rack_id, rack_location_id, rack_name, rack_units, rack_units_descending, unit_archived_at, unit_asset_id, unit_device, unit_end_number, unit_face, unit_rack_id, unit_start_number, unit_type, user_id, user_name

**ITFlow page ids / classes its scripts and styles use** `#mitDiagrams` `.itflow-document-body`

**Watched in ITFlow's code** `function saveBase64Images(` `function documentContentRaw(` `function documentHtmlPurify(` `function filesItemMenu(` `itflow-document-body` `Document from template` `itflowDocumentEditor` `asset_interface_links` `rack_units_descending` `function assetTypeName(`

**"ITFlow does this too" words** drawio, diagram, network_map, networkMap, topology, mxgraph, diagrams.net

**Our files** `functions/mit_diagrams.php`, `functions/mit_diagram_assets.php`, `functions/mit_ai_diagram.php`, `agent/mit_diagrams_ajax.php`, `agent/js/mit_diagrams.js`, `agent/modals/mit_diagram/versions.php`, `scripts/mit_drawio_update.sh`, `mit_drawio/LICENSE`, `mit_drawio/favicon.ico`, `mit_drawio/index.html`, `mit_drawio/open.html`, `mit_drawio/shortcuts.svg`, `mit_drawio/images/apple-touch-icon.png`, `mit_drawio/images/aui-wait.gif`, `mit_drawio/images/delete.png`, `mit_drawio/images/drawlogo-gray.svg`, `mit_drawio/images/drawlogo-text-bottom.svg`, `mit_drawio/images/drawlogo.svg`, `mit_drawio/images/drawlogo48.png`, `mit_drawio/images/droptarget.png`, `mit_drawio/images/github-logo-white.svg`, `mit_drawio/images/github-logo.svg`, `mit_drawio/images/gitlab-logo-white.svg`, `mit_drawio/images/gitlab-logo.svg`, `mit_drawio/images/google-drive-logo-white.svg`, `mit_drawio/images/google-drive-logo.svg`, `mit_drawio/images/icon-192-maskable.png`, `mit_drawio/images/icon-192.png`, `mit_drawio/images/icon-512-maskable.png`, `mit_drawio/images/icon-512.png`, `mit_drawio/images/logo-confluence.png`, `mit_drawio/images/logo-flat-small.png`, `mit_drawio/images/logo-flat.png`, `mit_drawio/images/logo-jira.png`, `mit_drawio/images/logo.png`, `mit_drawio/images/manifest.json`, `mit_drawio/images/onedrive-logo-white.svg`, `mit_drawio/images/onedrive-logo.svg`, `mit_drawio/images/osa_database.png`, `mit_drawio/images/osa_drive-harddisk.png`, `mit_drawio/images/spin.gif`, `mit_drawio/images/trello-logo-white-orange.svg`, `mit_drawio/images/trello-logo.svg`, `mit_drawio/js/PostConfig.js`, `mit_drawio/js/PreConfig.js`, `mit_drawio/js/app.min.js`, `mit_drawio/js/bootstrap.js`, `mit_drawio/js/extensions.min.js`, `mit_drawio/js/main.js`, `mit_drawio/js/math-print.js`, `mit_drawio/js/orgchart.min.js`, `mit_drawio/js/shapes-14-6-5.min.js`, `mit_drawio/js/stencils.min.js`, `mit_drawio/js/viewer-static.min.js`, `mit_drawio/resources/dia.txt`, `mit_drawio/styles/grapheditor.css`, `mit_drawio/styles/high-contrast.css`, `mit_drawio/math4/es5/drawio-mathjax.min.js`, `mit_drawio/mxgraph/css/common.css`, `mit_drawio/styles/fonts/ArchitectsDaughter-Regular.ttf`

<a id="minimum_commit"></a>
## Minimum commits: a line that tops an invoice / quote / recurring invoice up to a minimum, 0.00 once the other lines pass it, carried to invoices made from it (`minimum_commit`)

**Takes for granted**

- ITFlow's billingRecalc (functions/billing.php) works every total out from the lines' item_subtotal / item_tax / item_total; our discount hook at its start runs mitMinimumApply first.
- recurringInvoiceGenerate, quoteConvert, Copy Invoice / Quote and Make Recurring copy the lines (names with {period} tokens replaced, item_order kept on recurring runs); our mitDiscountCopy line sits after the copy at each.
- agent/modals/billing/billing_item.php posts qty / price / seat_source to billing_item_save in agent/post/billing.php, which calls billingItemSave; quoteFrequency groups a quote's lines.

**Tests** `tests/smoke/57-minimum-commit.spec.js`, `tests/scenarios/scenario-minimum-commit.php`

**Hooks in ITFlow's files**

- `agent/invoice.php`: mit_staff
- `agent/modals/billing/billing_item.php`: mitMinimumField
- `agent/post/billing.php`: mitMinimumSave, mit_why
- `agent/quote.php`: mit_staff
- `functions.php`: mit_minimum
- `functions/billing.php`: mitMinimumCopy, MountaineerIT

**ITFlow functions it calls**

- `functions/billing.php`: billingItems, billingLineAmounts, billingLineTax, billingLinesOpen, billingRecalc, billingType, quoteFrequency
- `functions/sanitize.php`: escapeHtml
- `functions/tax.php`: taxRow

**ITFlow columns** item_frequency, item_id, item_name, item_order, item_price, item_product_id, item_quantity, item_subtotal, item_tax, item_tax_id, item_total

**Watched in ITFlow's code** `function billingRecalc(` `function billingCopyLines(` `billingLineTax` `item_order` `billing_item_save`

**"ITFlow does this too" words** minimum_commit, item_minimum, commit_amount, minimum_charge

**Our files** `functions/mit_minimum.php`

<a id="client_profit"></a>
## Client profitability: labor cost per agent (with history), parts cost, profit and margin, invoiced / paid basis in ITFlow's report (`client_profit`)

**Takes for granted**

- ITFlow's agent/reports/client_profitability.php builds $clients rows through the $client closure (client_id, client_name, client_abbreviation), works out net = income - costs, then $columns (with a 'net' key), $totals and $target_control, and draws reportStats / reportTable.
- Hours there are ticket_replies.ticket_reply_time_worked dated by ticket_reply_created_at; reportRangeSql / reportSegmentControl / reportShare are ITFlow's report helpers.
- Invoices count as sold in Sent / Viewed / Partial / Paid; products.product_cost is ITFlow's product cost.

**Tests** `tests/smoke/36-client-profit.spec.js`, `tests/scenarios/scenario-client-profit.php`

**Hooks in ITFlow's files**

- `functions.php`: mit_profit
- `functions/labor_cost.php`: mitProfitDefaultBasis, mitProfitPartsAdd, mit_profit
- `includes/cron_jobs.php`: mit_procurement_parts

**ITFlow functions it calls**

- `functions/auth.php`: clientScopeSql
- `functions/reports.php`: reportRangeSql

**ITFlow tables** clients, invoice_items, invoices

**ITFlow columns** client_abbreviation, client_id, client_name, invoice_client_id, invoice_date, invoice_id, invoice_status, item_archived_at, item_id, item_invoice_id, item_product_id

**Watched in ITFlow's code** `client_profitability` `reportRangeSql` `reportSegmentControl` `dashboardRevenueSql` `product_cost`

**"ITFlow does this too" words** labor_cost, cost_rate, hourly_cost, profit_margin, user_cost

**Our files** `functions/mit_profit.php`
