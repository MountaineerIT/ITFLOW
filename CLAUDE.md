# Mountaineer IT build of ITFlow

PHP/MySQL MSP PSA, forked from https://github.com/itflow-org/itflow. Deployment and branch
model: see DEPLOYMENT.md.

## Branches
- `MountaineerIT` – production, default branch. **Never push to or merge into it without
  asking the user first.** Changes reach it only via a PR from `develop`.
- `develop` – staging; beta server tracks it. All work goes here first, committed directly.
- `master` – untouched copy of upstream master, comparison only.
- Other branches are leftover upstream copies; ignore them.

## Rules
- Commit directly to `develop` (no feature branches or PRs into `develop`) – after the user
  confirms the push. PRs are only for `develop` → `MountaineerIT`.
- **Ask the user to confirm before every `git push`** (any branch) and before merging any PR.
- **Version (Oct 2026):** our build is ITFlow's release + an iteration - `MIT_VERSION` in `includes/mit_version.php`
  (26.10.1, 26.10.2 ...). Every push to `develop` goes up by one: bump it in the last commit before the push and add a
  "## <version> - <date>" entry with "- " lines at the top of `MIT_CHANGELOG.md` (ours - ITFlow's own is `CHANGELOG.md`,
  never edit that). After merging an ITFlow release (APP_VERSION changes), start again at <new>.1. Tell the user the new
  version when asking to push. Shown in the admin footer and on Admin > Update with the release notes.
- Explain the plan before big changes or anything touching the schema.
- **Our database changes go in `admin/database_updates/mountaineerit/` as M001.php, M002.php …**
  (agreed scheme). Never add files to ITFlow's numbered `admin/database_updates/*.php` and
  never edit `db.sql`. Our updates are tracked by name in `mit_database_updates`, run by
  `admin/database_updates_mountaineerit.php` after ITFlow's (update_cli) and at the end of
  both installers and restores. Each file must be safe to run twice (use `mitColumnExists()`,
  `mitIndexExists()` etc.).
- **Stay portable to stock ITFlow** (the user may want to move back to itflow-org's `master`): DB
  changes only add – `mit_` tables, or columns on ITFlow tables that are NULL-able/defaulted; never
  rename/drop/alter ITFlow columns, change ITFlow data's meaning, add unique keys or triggers to
  ITFlow tables, or FK ITFlow tables into ours. Our tables may FK ITFlow's (tickets, users) only with
  ON DELETE CASCADE / SET NULL, so ITFlow's deletes carry on. Our columns on ITFlow tables are named
  like theirs (`ticket_schedule_end`, `item_cost`, `product_vendor_id`) rather than with a `mit_`
  prefix - the portability check and this file are how they are told apart.
  After every feature that touches the DB or settings, and after every upstream merge, run
  `php scripts/mit_portability_check.php` on the test install and report the result to the user.
  Before every "Promote to production", also do the full test: backup on the test install ->
  restore into a stock checkout of the latest release (`git archive vYY.MM`; install schema first,
  then `scripts/restore_cli.php`) -> click through the main pages and save a client/ticket/reply.
  Keep DEPLOYMENT.md "Moving to stock ITFlow" (what stops working after a move) up to date.
- Smoke tests: `cd tests/smoke && ITFLOW_PHP_LOG=/var/log/apache2/itflow-error.log npx playwright test` against the test install
  (never production; the log variable switches on 99-php-log, which otherwise skips) - after
  every upstream merge, before every "Promote to production", and after changing a page or hook in ITFlow's files.
  Report the result. New features get a test there (it must fail when the feature is broken).
- Don't modify `libs/` unless asked.
- Keep diffs against upstream minimal so release merges stay clean.

## Our deviations from upstream (re-check after every upstream merge)
- Default/release branch is `MountaineerIT` instead of `master`: `functions/app.php`
  (`getRepoBranch`), `admin/update.php` (release-branch badge), `setup/index.php` and
  `scripts/setup_cli.php` (default `$repo_branch`).
- `DEPLOYMENT.md`, `CLAUDE.md`, `scripts/install.sh` (Debian one-line installer), `tests/smoke/` (Playwright smoke
  tests, see its README) – ours.
- `.htaccess`: one extra line (404 for `CLAUDE.md` / `DEPLOYMENT.md` / `MIT_CHANGELOG.md`, which map our code). Keep it if upstream changes the file.
- Version and release notes: `includes/mit_version.php` + `MIT_CHANGELOG.md` (ours; `mitVersion()` / `mitChangelog()` in
  `functions/mit_common.php`). Hooks: `includes/footer.php` (admin footer shows `mitVersion()`), `admin/update.php` (Release
  shows it with "ITFlow <APP_VERSION>" under it, and requires `admin/includes/mit_changelog_card.php` under the version row).
  `includes/app_version.php` stays ITFlow's.
- Removed `.github/workflows/first-interaction.yml` (upstream's welcome bot – it points people
  to ITFlow's forum). If an upstream merge brings it back, delete it again.
- Bug and security fixes made here ahead of upstream (Oct 2026, "Phase 1" – see `git log`):
  portal contacts, saved-card charging, income/transactions XSS, API client checks, recurring
  invoices/expenses, payments, autopay, contract templates, ticket time rounding, time
  reports, quote due date (`agent/post/quote.php` add_quote_to_invoice due from the invoice date),
  invoice stock, task rows, Admin > Modules, setup_cli/restore_cli. Oct 18: `js/itflow_select.js` ties a moved select to its form
  (quote / invoice add-item rows lost the Tax choice - upstream develop), `agent/post/quote_model.php` quote_discount default. Also removed
  `agent/client_autopay.php` (unlinked, broken queries) and the `agent/custom/post.php` require of a
  missing `post/ai.php` (commit 2985b652) - if an upstream merge brings them back, check they work first.
  Oct 20 (full-program audit, 26.10.72): `js/ajax_modal.js` loads same-origin URLs only, and the ticket / mail-queue purifiers
  (`agent/ticket.php`, `agent/modals/ticket/ticket.php`, `admin/modals/mail_queue/mail_queue_message_view.php`) forbid the
  `ajax-modal` class (an emailed link could load and run another site's page); "mail is set up" also accepts
  `config_smtp_provider` (OAuth leaves the host empty: `client/login_reset.php`, `client/post.php`, `functions/user.php`
  userNotifyChange, `admin/post/users.php`, `admin/modals/user/user_form.php`, `agent/ajax.php`, `guest/guest_post.php`,
  `guest/guest_pay_invoice_stripe.php`); portal: only the primary contact changes logins (`client/post.php` edit_contact),
  `client/ticket_attachment.php` refuses internal / archived replies' files, `verifyContactTicketAccess` "Any";
  `cron/cron.php` records a job stopped with a non-zero code as Failed (parser connect error passed on);
  autopay includes Partial invoices (`cron/nightly_tasks.php`); `api/v1/clients/delete.php` uploads path.
  Audit B (Oct 20, 26.10.73): `.htaccess` security headers (nosniff, Referrer-Policy same-origin, CSP frame-ancestors 'self',
  HSTS on HTTPS); `api/v1/validate_api_key.php` takes the key from `X-API-Key` / `Authorization: Bearer` (getallheaders
  fallback) or the POST body and refuses `?api_key=` (var_dump gone); trips need Financial edit and an active agent
  (`agent/post/trip.php`, `trip_model.php`); `bulk_edit_expense_client` checks each expense's current client; portal
  `client/includes/check_login.php` ends sessions of archived clients / contacts and when the portal is off;
  `client/login_microsoft.php` (portal on, contact not archived, curl timeouts, tenant hook); saved-card payment
  (`client/post.php`) under `mitLock("invoice_pay_<id>")` with the status re-read and a Stripe idempotency key; AI summary /
  document template output through `mitPurifyHtml` (`agent/ajax.php`); API asset / contact models keep only the client's own
  location / contact / network / vendor ids; `admin/post/roles.php` refuses removing admin from the last admin role;
  `cron/ticket_email_parser.php` addReply: strangers (not contact / watcher / agent) give an internal note only - no reopen,
  no attachments, text-only tech mail - and the tech mail is escaped once; `functions/security.php`
  `user_encryption_session_ciphertext ?? ''`.
  Audit C/D (26.10.73): no "ticket closed" auto-reply to a bounce (parser); `api/v1/ticket_replies/create.php` refuses closed
  tickets and statuses outside `ticketReplyStatuses`, and Resolved -> working clears ticket_resolved_at; `index.php` sends a
  portal session to /client first; `includes/filter_header.php` page >= 1 and `sort` only when the column exists
  (information_schema); API limit 1-1000 / offset >= 0, `software/read.php` ORDER BY software_id; `agent/ajax.php`
  client_duplicate_check scoped + LIKE-escaped, item reorders check the record's client; guest feedback Good/Bad only and its
  custom action before the redirect, quote notice links `/agent/quote.php`; `login.php` `$code_given` (000000 is a code);
  `uploads/.htaccess` nosniff + `script-src 'none'` (same in `backupAssertUploadsGuards`, which also removes sub-folder
  .htaccess / .user.ini after a restore and rewrites backups / tmp / mit_receipts guards); portal add_ticket keeps only the
  client's asset and a Ticket category, portal document upload takes the MIME type from finfo; root `.htaccess` 404s top-level
  `*.pdf`. `css/itflow_custom.css`: the Preferences Light / Dark swatch rules are scoped to `.itflow-theme-pick` (they also
  shrank Admin > Settings > Theme's colour cards, `label.itflow-theme-swatch`; `css/mit_theme.css` repeats the correction
  because browsers keep itflow_custom.css - it has no version in its address). Not changed (noted): logout by GET, the unlinked `admin/settings_custom_fields.php`, setup's telemetry step,
  tag / category creation rights, `Auto-Submitted` on queued mail, installer passwords in `ps`.
  Code review stage 2 (Oct 21, 26.10.95): tickets - a reply / bulk reply taking a Resolved ticket back to work clears
  ticket_resolved_at and resets the resolution SLA, edit_ticket re-applies the SLA only when the priority changed,
  `ticketMergeInto` moves the moved replies' attachments (rows + files) (all `agent/post/ticket.php`); `agent/post/task.php`
  leaves tasks with a pending / declined approval open; `ticket_export.php` client list scoped; `api/v1/tickets/update.php`
  re-applies the SLA on a priority change; `functions/logging.php` cuts text with mb_strcut; parser addTicket cuts the subject
  to 500 and attachment names to 255 (escaped once). Billing - agent saved-card payment (`agent/post/payment.php`) under
  `mitLock("invoice_pay_<id>")`, re-read, charges the balance, Stripe idempotency key; bulk payment in cents, skips
  Non-Billable / nothing owed; Paid / Partial follow total changes (`agent/post/invoice.php`, dead link_invoice_to_ticket
  removed); add_quote_to_invoice claims the quote first; force_recurring and the nightly job mark an auto-send invoice Sent
  even without email (email only with global auto-send + email_notify); nightly recurring query without the recurring_payments
  join, autopay re-reads before charging; revenue needs Financial; edit_transfer takes ids from the transfer; expense refund
  keeps the client, no unlink of an empty receipt. Clients - `agent/ajax.php` share_generate_link: types Document / File /
  Credential exactly, expiries 1/24/168/730 h, item must be the client's; `guest/guest_ajax.php` TOTP needs BINARY
  'Credential' and stops 10 min after the last logged view once the view limit is used, Stripe intent needs Stripe active and
  under its threshold, balance in cents, JSON errors (same check in `guest_pay_invoice_stripe.php`); `functions/security.php`
  apiEncryptCredentialEntry refuses an empty key (API credential create / update say so); `agent/post/contact.php`
  link_contact_to_asset same-client check, bulk mail needs client edit and a configured From address (also
  `agent/post/client.php`), bulk archive leaves a primary contact's login, anonymize redacts the raw values;
  `agent/post/asset.php` transfer log name; client overview hides credential share names without credential access. Sign-in -
  `includes/load_user_session.php` enforces "Require MFA" on every agent / admin page (only `agent/user/mfa_enforcement.php`,
  enable_mfa and logout get through); enable_mfa won't replace an existing secret; `cron/mail_queue.php` refreshes an OAuth
  token once per run; `admin/post/api_keys.php` log names; `includes/filter_header.php` custom range without dtt = open end.
  Portal / guest - expired quotes can't be accepted (`guest_view_quote.php` precedence, `guest_post.php` claim); guest
  reopen / close sync the SLA clock; `client/login_reset.php` refuses empty / unknown tokens (hash_equals), USER_PASSWORD_MIN,
  per-IP limit 10 per 10 min (logs type 'Portal Reset', GET_LOCK like login.php); `client/post.php` new-ticket mail details via
  mitPurifyHtml, close needs resolved / resolve needs unresolved with tasks done / a comment on a resolved ticket reopens it
  properly, saved card allows Partial and charges the balance, stripe_save_card only takes this login's checkout session for the
  client's own customer, portal documents / files record the portal user id; `guest_view_ticket.php` avatar path;
  `guest_download_file.php` checks the item first, quoted filename. Stage 3 (26.10.96): `agent/invoice.php` / `quote.php` /
  `recurring_invoice.php` draw the logo only when one is set (an empty name asked for the uploads/settings/ folder - 403);
  `client/login_reset.php` reads the user row with `?? 0`; `agent/user/post.php` HTTP_REFERER `?? ''`. 26.10.98: `agent/post/invoice_model.php` discounts `?? 0` (add_invoice posts no recurring_discount). 26.10.120: `admin/post/settings_company.php` deletes the old logo only when there is one (the first upload unlinked the folder).
  These fixes carry no "Mountaineer IT" marker (they are plain bug fixes, meant to match upstream's
  eventual fix) - find them with `git log`.
  When an upstream release fixes the same thing, resolve conflicts in favour of upstream.
- Calendar (Oct 2026): `functions/mit_calendar.php` (ours) - agent colors, booking end
  times (`tickets.ticket_schedule_end`, M001), overlap conflicts. Hooks in
  `functions/ticket.php` (ticketSetSchedule/ticketCancelSchedule), `functions/app.php`
  (createiCalStr `$end`), `functions/calendar.php` (ticket items + feeds), `agent/ajax.php`
  (calendar_move), `agent/calendar.php` + `agent/js/calendar.js` (color dots in Whose, opens on me),
  `agent/modals/ticket/ticket_edit_schedule.php` + `agent/js/ticket_schedule_modal.js`
  (Tech + How long), `agent/post/ticket.php` (edit_ticket_schedule), `agent/ticket.php`
  (start - end wording), `functions.php` (require), `scripts/update_cli.php`, `setup/index.php`,
  `scripts/setup_cli.php` (run our DB updates). Re-apply them if an upstream merge drops them.
  Calendar settings (view, whose, ticked) are also saved per user in `mit_user_preferences`
  (M002) via `ajax.php?mit_calendar_state` and loaded by `agent/calendar.php` (data-saved-state).
  Each tech picks their calendar color under Account > Preferences (`agent/user/user_preferences.php`
  + `agent/user/post/profile.php`); admins can set anyone's in Admin > Users > Edit
  (`admin/modals/user/user_form.php` + `admin/post/users.php` edit_user) - one-line hooks,
  `mitCalendarColorField()` / `mitSaveAgentColor()`, saved as the `calendar.color` preference;
  `mitAgentColor()` falls back to the automatic color.
  Left behind (Oct 2026): `functions/mit_left_behind.php` + `agent/js/mit_left_behind.js` (ours, required from mit_calendar.php) -
  a sidebar list under To schedule of open tickets whose booking ended before today (`mitLeftBehindSql`), following the Whose
  picker (Everyone = everyone's) and the client; drag a row onto the calendar to book it again (calendar.js's own `drop` /
  `booked()` - rows carry the To schedule data attributes; the move keeps the booking's length), click for the quick look. Their
  old bookings get class `mit-left-behind` (dashed red) and "Left behind" in the detail (`mitLeftBehindMark`). Hooks: one line in
  `agent/calendar.php` after the To schedule card (`mitLeftBehindCard`), `agent/ajax.php?mit_left_behind`, and
  `functions/calendar.php`'s tickets-source hook now calls `mitCalendarTicketItems` (left behind, then `mitProjectCalendarItems`).
  Dispatch has it as a third queue tab, "Left behind" (`mitDispatchQueue` tab `left_behind` = `mitLeftBehindSql()`, oldest booking
  first, the card shows the booked day; every agent's, following the board / client / priority filters).
  To schedule on Everyone lists every agent's unscheduled tickets with the agent named (`agent/ajax.php` calendar_unscheduled:
  user_id 0 = all assigned, `user_name`; `agent/js/calendar.js` loadUnscheduled sends `agentId()`), and `agent/calendar.php` loads
  calendar.js through `mitAsset` so browsers fetch our changes.
  Entries assigned to agents (26.10.115): `functions/mit_event_agents.php` (ours, required from mit_calendar.php) - ITFlow's own
  attendee agents (`calendar_event_attendees.attendee_user_id`) are the assignment; no tables. New Event from an agent's calendar
  starts with them (`mitEventDefaultAgents` - one line in `agent/modals/calendar/calendar_event_add.php`, `agent_id` sent by
  `newEvent` in `agent/js/calendar.js`); "Assigned to" beside the title in place of ITFlow's Calendar column (26.10.118 -
  `mitEventAssignField($form_attendees, $form_calendars, $form_calendar_id)`, one `if / else` around ITFlow's Calendar column in
  `agent/modals/calendar/calendar_event_form.php`; one calendar = hidden `calendar` input, several = our Calendar picker
  `[data-mit-event-calendar]`; the form then skips its own Agents box (`$mit_assign`) and labels the rest "Invite"); the quick look
  shows agents only under Assigned to and the rest as "Invited" (one block around ITFlow's Who list in
  `agent/modals/calendar/calendar_event.php`, `$mit_who`); `mitEventItemsAgents($items, $opts)` at the end of `calendarEventItems` in `functions/calendar.php` (agents, tech_color
  dot, names in the detail; on one agent's calendar an entry assigned to others is left out even for its maker - also in
  conflicts); quick look "Assigned to" (`mitEventQuickAssign` - one line in `agent/modals/calendar/calendar_event.php`, handler
  `agent/post/mit_event_agents.php` `mit_event_assign`, `mitEventSetAgents` keeps contacts / emails, cancels taken-off invited
  agents, invites newcomers when others were invited); Dispatch day view moves one-off entries in time and between agents
  (`mitDispatchItems` makes kind `event` editable, `moveEntry` in `agent/js/mit_dispatch.js`, POST `event_move` ->
  `mitDispatchEventMove`). Smoke `20-event-agents.spec.js`. Entries with agents are filled with the shown (or first) agent's color
  (26.10.116); the calendar's color only for entries with nobody on them.
  Service ticket color (26.10.116): `mitServiceCalendarItems` (functions/mit_calendar.php, last step of `mitCalendarTicketItems`) fills
  booked service tickets / helper bookings (not project ones) with `mit_settings` service_ticket_color (`mitServiceTicketColor`, default
  #0d6efd = ITFlow's Scheduled tickets source color) and puts the agent's color in `extendedProps.tech_color` (none when unassigned).
  tech_color (service and project bookings) is drawn as a bar down the left (inset box-shadow, 26.10.117 - stripes before): `data-mit-agent` + `--mit-agent` set in eventDidMount
  (`agent/js/calendar.js` - only on blocks, `mitStriped`: time grids and all-day; month view's timed items and the list keep the dot -
  and `agent/js/mit_dispatch.js`, also its week chips), CSS `[data-mit-agent]` in `agent/calendar.php` / `agent/mit_dispatch.php`. Card `admin/includes/mit_calendar_card.php` - one require at the end of `admin/settings_ticket.php`, handler
  `admin/post/mit_calendar_settings.php`. Smoke: `08-calendar.spec.js` dispatch test.
  Agent beside the time (26.10.117): `extendedProps.agent_name` (first name - `mitFirstName`; events "Bob +1") / `agent_full` (tooltip)
  from `mitServiceCalendarItems` (lead or helper; the lead's name is taken out of ITFlow's ticket detail) and `mitEventItemsAgents`
  (no longer in the event detail); `agent/js/calendar.js` eventContent puts it with the time in `.mit-cal-when` (flex, wraps under the
  time in a narrow column - CSS in `agent/calendar.php`; not on month view's one-line dayGrid items - 26.10.119), or at the front of the detail when no time is shown; in the list the dot comes before it. Dispatch shows the
  agent by column, not on the booking.
- Microsoft 365 app (Oct 2026): `functions/mit_m365.php` (ours) creates ITFlow's own app in the
  tenant (device-code sign-in as Global Admin, Graph CLI client) or takes a hand-made one, with a
  certificate instead of a secret, renewed 30 days before expiry via Graph `addKey`. Settings in
  `mit_settings` (M003). The mail OAuth secret holds the marker `mit-m365-certificate`; one-line
  hooks swap it for a signed client assertion: `functions/request.php` (httpFormPost),
  `admin/post/settings_mail.php` (token test; Connect `prompt` via `mitM365ConnectPrompt` so a
  non-admin mailbox is not sent to the consent screen), `admin/oauth_microsoft_mail_callback.php`. UI:
  `admin/includes/mit_m365_card.php` required at the top of `admin/settings_mail.php`;
  handlers in `admin/post/mit_m365.php`. Re-apply the hooks if an upstream merge drops them.
  Mail goes through Microsoft Graph (not SMTP/IMAP) once the app has the Mail permissions
  (`MIT_M365_PERMISSIONS_VERSION` 2; older apps get "Update permissions" on the card). Hooks:
  `cron/mail_queue.php` (`$mit_graph = ...mitM365GraphSendReady()` skips the XOAUTH2 block,
  `mitM365GraphSendMail($mail)` before `$mail->send()`; >3 MB goes as draft + upload session),
  `cron/ticket_email_parser.php` (microsoft_oauth `$pass` skips the IMAP token when
  `mitM365GraphMailboxReady()`; `mitM365GraphMailbox(...) ?? new Mailbox(...)`), and
  `admin/post/settings_mail.php` (Test IMAP via `mitM365GraphMailboxTest`; both OAuth saves keep
  the marker via `mitM365KeepCertificateSecret`). Graph stand-ins for ImapEngine are in
  `functions/mit_m365_mailbox.php`. Also `(string)$config_smtp_host` in mail_queue's two
  sendQueueEmail calls (crashed when the SMTP host was never set). Secrets at rest (cert key,
  Outlook tokens) use `mitEncryptSecret()` (v2 key `$config_mit_secret_key` - see Review fixes).
- Outlook calendar per tech (Oct 2026, Phase 4 step 1): `functions/mit_outlook.php` (ours, required
  from mit_m365.php) - Account > Outlook calendar (`agent/user/mit_outlook.php`, handlers
  `agent/user/post/mit_outlook.php`, Microsoft returns to `agent/user/mit_outlook_callback.php`).
  Tables `mit_outlook_links` / `mit_outlook_events` (M004); refresh tokens AES-GCM encrypted with
  a key from config.php. Cron job `mit_outlook_sync` (`cron/mit_outlook_sync.php`, one entry
  added to `includes/cron_jobs.php`) pushes changed bookings every minute and checks Outlook
  for edits every 15 min (ITFlow wins). Side-nav entry in `agent/user/includes/user_side_nav.php`.
  Step 2: Outlook busy times copied every 5 min into `mit_outlook_busy` (M005; also
  `mit_outlook_links.busy_refreshed_at`) by the same job. Shown as the `outlook_busy` calendar
  source - hooks in `functions/calendar.php` (calendarSources `+=`, calendarSourceItems early
  return), striped via `[data-kind="outlook_busy"]` CSS in `agent/calendar.php`; counted in
  `mitScheduleConflicts()`. Titles only for the owner; others see Busy/Tentative/Out of office.
  Techs with Outlook synced get booking emails without the .ics (`functions/ticket.php`
  ticketMailSchedule hook, `mitOutlookSyncsEmail`); busy copy skips ITFlow invites
  (iCalUId `ticket-<id>@`). Disabled users (user_status 0) are paused, archived ones removed.
  Reads of our tables fall back quietly when the database update has not run yet.
  Calendar-only app (26.10.73): Outlook sign-ins go through a second app with only User.Read + Calendars.ReadWrite
  (`mitM365ProvisionCalendar`, settings m365_cal_client_id / m365_cal_object_id, `mitM365CalendarApp`, `mitOutlookClientId`), so
  a stored tech refresh token cannot reach mail. Same tenant, same certificate (`mitMicrosoftTokenFields` signs for both client
  ids; `mitM365RenewCertificate` addKeys the calendar app first). Made from the Microsoft 365 card (purpose `calendar` in
  `admin/post/mit_m365.php` - which also refuses `create` while an app exists); making it empties every link's tokens with a
  Reconnect message. The callback warns when the Outlook account is not the agent's ITFlow email. Its grant also covers
  offline_access / openid / profile / email (`MIT_M365_CAL_SCOPES`); older calendar apps (`m365_cal_permissions_version` below
  `MIT_M365_CAL_PERMISSIONS_VERSION`) get "Update permissions" on the card (purpose `calendar_update`,
  `mitM365UpdateCalendarPermissions`). `mitUpgradeSecrets` (also ninja_*) runs daily from `cron/mit_cron_watch.php`
  (`secrets_upgraded_on`). NinjaOne live status: `agent/mit_ninjaone_ajax.php` closes the session and calls with a 5 s timeout
  (`$GLOBALS['mit_ninja_timeout']`); a failed asset create is not recorded, so the next run tries again.
- Ticket timers in the top bar (Oct 2026): `includes/mit_timers_nav.php` + `js/mit_timers.js` (ours)
  list the browser's ticket timers (ITFlow's localStorage keys `<id>-startTime`/`-pausedTime`
  from `agent/js/ticket_time_tracking.js` - re-check if upstream changes them) with pause /
  resume / discard. Hooks: one require in `includes/top_nav.php` before the bell, and
  `agent/ajax.php?mit_timer_tickets` (ticket names, client-scoped).
- Repeats by weekday (Oct 2026): `functions/mit_recurring.php` (ours, required from mit_calendar.php)
  + `js/mit_repeat_on.js` - "the third Wednesday" / "the last Friday" (RFC 5545 BYDAY, e.g. `3WE`,
  `-1FR`) in `recurring_tickets.recurring_ticket_by_day` and `calendar_events.event_repeat_by_day`
  (M006). Hooks: `functions/ticket.php` (recurringTicketNextRun `$by_day` param + its 4 callers,
  CadenceText), `cron/nightly_tasks.php` (recurring SELECT *), `agent/post/recurring_ticket.php`
  (save / bulk next run), both recurring ticket modals and `calendar_event_form.php` (one-line
  `mitRepeatOnField`), `functions/calendar.php` (event SET, expandRecurringEvent, RepeatText,
  icsRepeatToRrule BYDAY/BYMONTH), event SELECTs with explicit columns in calendar.php,
  dashboard.php, client_overview.php (`mitEventByDayColumn()`).
- Quote pricing and images (Oct 2026): `functions/mit_quotes.php` + `js/mit_quote_pricing.js` (ours) -
  our cost, Markup / Margin % or Set price, and one image per quote line and per product (upload or
  a link - an image or a product page's og:image, fetched public-only; GD re-encodes into
  `uploads/mit_images/`). Columns `quote_items.item_cost/_price_method/_price_percent/_image` and
  `products.product_cost/...` (M007); defaults in `mit_settings` `quote_price_*` / `quote_show_images`
  (card `admin/includes/mit_quote_card.php` required at the end of `admin/settings_quote.php`,
  handler `admin/post/mit_quote_settings.php`). Hooks: `agent/quote.php` (Image / Our cost cells, add
  row multipart, profit box under the totals), `agent/modals/quote/quote_item_edit.php`, `agent/post/quote.php`
  (add/edit/copy/delete items, PDF image), `guest/guest_view_quote.php` + `guest/guest_post.php` (PDF),
  `agent/modals/product/product_add.php` / `product_edit.php`, `agent/post/product.php`,
  `agent/post/client.php` (client delete). Invoices are untouched (customer price only).
- Additional techs on tickets (Oct 2026): `functions/mit_ticket_techs.php` (ours, required from
  mit_calendar.php), table `mit_ticket_techs` (M008) - helpers beside the lead (`ticket_assigned_to`
  unchanged), each booked with the lead (follows_lead), at their own time, or not booked. UI: modal
  `agent/modals/ticket/mit_ticket_tech.php`, handlers `agent/post/mit_ticket_techs.php`, one-line
  `mitTicketTechsProp` in `agent/ticket.php` (under Assigned to). Hooks: `functions/ticket.php`
  (ticketSetSchedule `mitTicketTechsLeadMoved`, ticketCancelSchedule `mitTicketTechsLeadCancelled`),
  `functions/calendar.php` (tickets source + user feeds), `agent/ajax.php` (calendar_move kind
  `helper`, calendar_day skips `helper-<ticket>-`), `agent/js/calendar.js` (moved: helper branch),
  `agent/calendar.php` (dashed `[data-kind="helper"]` CSS), `agent/tickets.php` (Mine + assigned
  filter via `mitAssignedSql`), `functions/dashboard.php` (dashboardMyTickets), `agent/ticket_list.php`
  + `agent/ticket_kanban.php` (`mitTicketTechsListBadge`), `cron/ticket_email_parser.php` +
  `client/post.php` (`mitTicketTechsCopyMail` after the lead's reply email). Ours: helper clashes
  in `mitScheduleConflicts`, helper bookings in `mitOutlookBookings`, busy-copy skips
  `ticket-<id>-tech-<user>@` invites. A helper who becomes the lead is cleared by `mitTicketTechsTidy()`
  on read (no hooks in ITFlow's four reassign paths) and, on a booked ticket, sent the ticket's own invite (`mitTicketLeadInvite`,
  not twice within 10 min). Helpers who can't see the client get no copies or booking mails, and a client change removes them
  (`mitTicketClientMoved` -> `mitTicketTechRemove`). Customer emails stay about the lead's booking only.
- Ticket page layout (Oct 2026): `functions/mit_ticket_layout.php` + `agent/js/mit_ticket_layout.js` (ours) -
  columns (main wide / even / side wider / one / three - an extra `.mit-layout-left` column the
  script adds), box order / column / half width / start collapsed, company default (`mit_settings` ticket_layout, admins) and
  per tech (`mit_user_preferences` ticket.layout). The script moves ITFlow's own boxes in the browser,
  found by their title icon (fa-align-left, fa-sliders-h, fa-user, fa-layer-group, fa-map-marker-alt,
  fa-tasks, fa-link, fa-history), the reply form by `#replyComposer`, the rest of the main column
  wrapped as "conversation" - re-check those if upstream reworks `agent/ticket.php`. Hooks: one line
  after the layout in `agent/ticket.php` (`mitTicketLayoutHtml`), `agent/ajax.php?mit_ticket_layout`,
  `functions.php` (require). "Customize layout" is added to the ticket's ⋮ menu by the script.
  The same script opens the reply box on "Public note" at page load (ITFlow starts it shut with nothing
  picked) - it relies on ITFlow's ids `#replyComposer`, `public_reply_type_opt1`, `#replyAudience`, `#replySubmitLabel`.
  It also adds a "Read" tick to the Description box's header: ticked, the description starts folded away for that tech
  on that ticket (`mit_user_preferences` ticket.read - ticket id -> hash of the description, newest 500; an edited
  description shows as unread again). Saved through `ajax.php?mit_ticket_layout` scope read / unread (`mitTicketReadSet`,
  client-scoped); `mitTicketLayoutHtml` passes `read` from ITFlow's `$ticket_id` / `$ticket`. A box folded by Read is not
  saved as "Start collapsed" in a layout.
  The same script stops the ticket timer when the reply form is really sent (a `submit` listener, not cancelled): it clears
  ITFlow's `<id>-startTime` / `-pausedTime` / `ticket-timer-running-<id>` keys at once - ITFlow's own reset runs 100 ms after the
  Add button's click (`agent/js/ticket_time_tracking.js`) and was lost when the page moved on sooner. A sessionStorage marker
  keeps the reloaded ticket at 0 with auto-start on (removed after ITFlow's timer script has run). The email-reply checks in
  `agent/js/mit_reply_recipients.js` run on the Add button's click in the capture phase, before ITFlow's reset, so a reply
  that is not sent keeps its time. Re-check if upstream changes the timer script or its keys.
- Procurement (Oct 2026, part 1 of 3): `functions/mit_procurement.php` (ours) - items to order
  (`mit_procurement_items`), purchase orders (`mit_purchase_orders`, `mit_po_lines`, `mit_po_tracking`,
  `mit_po_receipts`, `mit_po_history`; M009), approval when over `procurement_approval_over`
  (`mit_settings`, 0 = always), tracking links, receiving (partial, serials), PO PDF (TCPDF) and email
  (attachment in `uploads/mit_po/`). Permission: ITFlow module row `module_procurement` (M009) -
  View 1 / Edit 2 / Full 3 approves + cancels; admins Full. Pages `agent/mit_procurement.php`,
  `mit_purchase_orders.php`, `mit_purchase_order.php`, `mit_receiving.php`; modals
  `agent/modals/mit_procurement/`; handlers `agent/post/mit_procurement.php`. Hooks: one require of
  `agent/includes/mit_procurement_nav.php` in `agent/includes/side_nav.php` (before FINANCE),
  Admin > Settings > Procurement page `admin/mit_procurement_settings.php` (card
  `admin/includes/mit_procurement_card.php`, handler `admin/post/mit_procurement_settings.php`; menu via
  `admin/includes/mit_admin_nav.php`), `functions.php` (require). Side-menu counts (`agent/includes/mit_procurement_nav.php`): To Order
  = waiting items, Purchase Orders = waiting for approval, Receiving = PO lines still to arrive (not POs). PO numbers are unique (M015); a PO
  needs approval when over the limit or when a line has no cost - unless approval is switched off (`procurement_approval_on`) -
  approvers are Full Procurement access + admins (roles - `mitPoApprovers`); nobody approves their own PO unless
  they are an admin (logged as self-approved) or the only approver (`mitPoMayApprove`). "Their own" = made or shaped it
  (`mitPoShapers`: created, added items, changed quantities / costs, submitted - from `mit_po_history`), and Approve carries the
  total the approver saw (refused if the PO changed since). Receiving and billing parts run under a DB lock (`mitProcLock`).
  A part's client is its ticket's client now (`MIT_PART_CLIENT_SQL`), so a ticket moved to another client takes its parts along. Draft POs can be deleted at Edit (level 2) on purpose -
  nothing was ordered yet. Daily cron job `mit_procurement_tidy` (`cron/mit_procurement_tidy.php`,
  `mitProcTidy`) cancels waiting items of deleted tickets/clients and frees parts whose invoice line went;
  a deleted ticket's unbilled stock parts go back on the shelf through `mitTicketPartsDeleting` (one line in
  `functions/ticket.php` ticketDeleteForGood and in `agent/post/client.php` client delete) before the
  `mit_ticket_parts` FK cascade removes them.
  Part 2: `functions/mit_procurement_parts.php` (ours, required from mit_procurement.php), M010
  (`mit_ticket_parts`, `products.product_vendor_id`). Quote -> invoice ticks: `mitProcQuoteConvertFields`
  in `agent/modals/quote/quote_to_invoice.php` (+ data-modal-size="lg" on the Invoice button in
  `agent/quote.php`), `mitProcFromQuoteLine` in `agent/post/quote.php` add_quote_to_invoice loop.
  Invoice: `mitProcInvoiceMenuItem` in the `agent/invoice.php` ⋮ menu -> modal
  `agent/modals/mit_procurement/from_invoice.php`. Ticket Parts box: `mitTicketPartsCard` in
  `agent/ticket.php` (side column, above Linked; icon fa-microchip = layout box `parts`), modals
  `part.php` / `parts_bill.php`, handlers `agent/post/mit_procurement_parts.php`. From-stock parts take
  product_stock at once (billed lines have no item_product_id, so ITFlow does not take it again);
  to-order parts become procurement items (source ticket). Procurement's pages, POs and receiving follow the role's
  Procurement permission; the ticket Parts box does not - everyone on the ticket sees it, ticket edit adds parts (to order too),
  billing needs Sales edit, and PO numbers in it are links only with Procurement View. The product "Order from" field needs
  Procurement Edit. Billing > Unbilled Parts (`agent/mit_unbilled_parts.php`, Sales permission;
  side-menu link with a count in `agent/includes/mit_billing_nav.php`, one require in `agent/includes/side_nav.php` BILLING after
  Products): every part not on an invoice, by client and ticket, with Bill parts (`mitUnbilledParts` / `mitUnbilledPartsCount`, sharing `mitTicketPartsWhere` with `mitTicketParts`). Project pickers (item modal, from-invoice, quote convert) use `mitProcProjectField` - the client's open
  projects (client 0 = projects without a client), with a note when there are none; the item modal's list follows its client.
  Product vendor: `mitProductVendorField`
  appended to the product modals' pricing hook line, saved from `mitProductSave`.
  Part 3: `functions/mit_procurement_extras.php` (ours, required from mit_procurement.php), M011
  (`mit_po_receipts.asset_ids`). Receiving (`mitPoReceive` options -> `mitProcAfterReceive`): client
  lines become assets (one per unit, serial, type picked per line on `agent/mit_receiving.php`, linked
  to the ticket for ticket parts; several units named "<line> #n"), stock lines add product_stock, ticket parts get an internal note
  (never stock). An invoice line's ordered product put back on the shelf has its `item_product_id` cleared, so ITFlow's line
  delete / quantity edit can't return the stock a second time (`mitProcInvoiceLineProducts` keeps a partly received line's
  product for the rest). Quote lines keep their product (`quote_items.item_product_id`, from `mitQuoteItemSave` / copy). Billing
  parts checks every line first and writes in one transaction; part names are at most 200 characters (invoice_items.item_name).
  Bill parts needs Sales edit and Support edit.
  "Fill in each asset's details" (options[review]) sends Receiving back with `?mit_review_assets=ids&mit_review_total=n`, and
  `agent/mit_receiving.php` opens ITFlow's own `modals/asset/asset_edit.php` for each in turn (the address drops each id as its
  window opens, so ITFlow's save-and-redirect carries on with the rest; Cancel skips one).
  "Record vendor bill" on the PO (`po_action.php?action=bill`, needs Financial edit) inserts an ITFlow
  expense (`mitPoRecordBill`, PO `expense_id`). Hooks: `mitProcInvoiceSummary` + `mitProcInvoiceLineStatus`
  in `agent/invoice.php` (above the items / under each line name), `mitProcProjectCard` in
  `agent/project.php` (left column, under the tabs card).
  Products API: `api/v1/products/read.php` selects `mitProductApiColumns()` (every column but our cost /
  markup) instead of `*` - two lines.
- Timesheets (Oct 2026): `functions/mit_timesheets.php` (ours) - per pay period (semi-monthly default;
  `mit_settings` timesheet_period / timesheet_anchor; weekly follows the week start), ticket time auto from ticket replies (billable by
  `ticket_billable`), hours on charge codes (`mit_charge_codes`: work / lost / leave, paid or not),
  `mit_time_entries`, `mit_timesheets` (open / submitted / returned / approved; approving copies ticket
  time and unaccounted time in as source ticket_billable/ticket_other/auto_lost, code 0 - a 0 auto_lost row on
  the first day marks a period stored this way) - M012, plus module row `module_timesheets`
  (everyone keeps their own; View 1 sees all + report, Edit 2 corrects, Full 3 approves - their own sheet too: the user chose
  (Oct 21) to keep self-approval for Full, unlike POs, so don't flag it in reviews). Pages
  `agent/mit_timesheet.php`, `agent/mit_timesheets.php`, modals `agent/modals/mit_timesheet/`, handlers
  `agent/post/mit_timesheets.php`, report `agent/reports/mit_payroll.php` (CSV summary + detail).
  Admin > Timesheets `admin/mit_timesheets.php` (tabs Settings / Charge codes / People), modals
  `admin/modals/mit_timesheets/`, handlers `admin/post/mit_timesheets.php`. Options (M013): company settings
  `mit_settings` ts_* (`mitTsSettings` - week start, approval on/off, future days, auto-lock, reminders, h:mm,
  rounding, ticket time / internal notes / billable rule (`ts_billable` all - the default - | flag | client, timesheets only, `mitTsBillableSql`), normal week, overtime day/week (weekly overtime
  falls on the day it is worked), auto lost time - unpaid unless ts_auto_lost_paid, never before the hire
  date or the login's creation);
  codes `code_roles` / `code_note_required` / `code_daily_max` / `code_closure_fill`; `mit_timesheet_people`
  (schedule, approver - who can then see, correct and approve them -, employee_id, pay_type: salaried = no
  overtime, keeps_timesheet - M015); personal `mit_user_preferences` timesheet.format / .view / .hidden_codes / .reminders.
  Closure days fill in the closure code on save / submit / approve / cron; a filled day cleared by hand keeps a
  0 row so it is not filled again. The page (Oct 2026 redesign): a week at a time, a row a day, drawn by `agent/js/mit_timesheet.js`
  from JSON built by `functions/mit_timesheet_view.php` (`mitTsViewDays`, `mitTsViewCodes`, `mitTsViewSummary`); clicking a day
  opens a side panel (times, meal answers, hours by code, notes) that saves as it is typed through `agent/mit_timesheet_ajax.php`
  (`save_day` - `mitTsCaSave` + `mitTsSaveCells` for that date, returns the day, summary, leave box, sign problems); a fixed bar
  holds the save state and Submit / Approve. The old form handler (`mit_ts_save`) is kept for submitting and signing.
  Lunch and breaks (M016): charge-code kind `break` (Lunch seeded, unpaid) - logged by people, accounted for (not
  lost) but not worked; paid short breaks are automatic, `mitTsBreakFor` from ts_break_minutes / ts_break_per (or a
  person's `mit_timesheet_people.break_minutes` / `break_per`), counted in paid hours, the total and overtime, not in
  worked / billable %; stored on approval as source `break_paid` (approved periods without them = none).
  Reimbursements (M017): `functions/mit_reimbursements.php` (ours, required from mit_timesheets.php) - mileage
  (makes an ITFlow trip, rate `ts_mileage_rate` kept on the entry) and purchases (receipt in `uploads/mit_receipts/`,
  served by `agent/mit_receipt.php`; optional ticket part via `mitTicketPartSave`, which now also returns the part id)
  in `mit_reimbursements`; by date in the pay period, locked with the timesheet; approving records purchases as ITFlow
  expenses (`mitReimbRecordExpenses`, `ts_reimb_category` / `ts_reimb_account`). Box `mitReimbTicketCard` - one line in
  `agent/ticket.php` under Parts (layout box `reimburse`, fa-receipt) - modal `agent/modals/mit_reimburse/reimburse.php`,
  handlers `agent/post/mit_reimbursements.php`; payroll report columns Miles / Mileage / Purchases / Reimburse.
  Receipts open for the person, their timesheet viewers, or Financial access to the client (`mitReimbMaySeeReceipt`).
  Reply time is locked once its timesheet is submitted / approved: one guarded line in `agent/post/ticket.php` edit_ticket_reply
  (`mitTsReplyTimeGuard`), one in archive_ticket_reply, and `mitTsDeleteGuard` (one line each in `functions/ticket.php`
  ticketDeleteForGood and `agent/post/client.php` delete_client - refuses while a timed note is on a submitted sheet). Note times
  also follow `mitTsDayLock` (submitted / approved / closed = auto-locked for someone who can't correct them) and no future days.
  Days held by another submitted / approved sheet (after a pay-period change) stay locked everywhere - saves, closure fills,
  reimbursements, holidays, approve / reopen copies (`mitTsLockedDates`, `mitTsWhyDayLocked`); approving waits while an
  overlapping older sheet is submitted. A California sheet is not approved while the person has changes to acknowledge, and any
  change after signing sets the attestation aside (`mitTsCaChangedAfterSigning` - a submitted sheet goes back to the person).
  Approval stores each day's pay figures (`MIT_TS_STORED_SOURCES`: pay_rules - 1 California day / 0 not, marks a stored day -,
  overtime, double, meal_premium, rest_premium, code_id 0) and `mitTsStoredRules` reads them back in `mitTsTotals` /
  `mitTsCaDays`, so later setting / pay-type changes don't alter paid periods (days approved before 26.10.94 work out live).
  Per request `mitTsMemo` / `mitTsForget` (forgotten after every write) keep reply rows, days, ticket minutes; M029 indexes
  ticket_replies (`mit_ticket_replies_by_created`, `mit_ticket_replies_created`) for `mitTsReplyWorkRows`. Times typed past
  midnight roll by calendar day (DST-safe) in `mitTsCaParseBlocks` / `mitReplyTimesParse`.
  Pay types (`MIT_TS_PAY_TYPES` / `MIT_TS_PAY_TYPE_SHORT`): hourly, salary (no overtime, California off unless set On), contractor
  (no overtime, never California - `mitTsCaOn` returns false whatever `ca_rules` says; still keeps a timesheet, leave and auto lost
  time as set). The pay type is set in Admin > Users > New / Edit ("Employment": `mitTsPayTypeField` - one line in
  `admin/modals/user/user_form.php` after Role; `mitTsSavePayType` - one line each in `admin/post/users.php` add_user / edit_user);
  the People modal only shows it and its save leaves it alone. ITFlow's Admin > Users won't edit your own account, so an admin sets
  their own in Account > Preferences (Timesheet section, admins only - `mitTsPreferenceFields` / `mitTsSavePreferences`, audit-logged;
  smoke `19-own-employment.spec.js`, the technician side in 04). The People list shows a California badge (`mitTsCaOn`). The day panel lists auto lost time as a row with the codes
  (`[data-auto-lost]`, goes down live as codes are typed; "Lost time (auto)" chip on the day row).
  Times on ticket notes (M028, `functions/mit_reply_times.php` + `agent/js/mit_reply_times.js`, required from mit_timesheets.php):
  `mit_reply_times` (reply_id FK ticket_replies CASCADE, work_start / work_end as the person's clock read them). The reply box
  (`mitReplyTimesField`, one line in `agent/ticket.php` after the send buttons) asks for Date worked / Start / End whenever time is
  logged - they follow ITFlow's timer (#hours / #minutes / #seconds; end = now) until typed, typed times pause the timer and set
  the length, a missing / bad time stops the send (capture-phase click on `#ticket_add_reply`). The script also lays the footer out:
  ITFlow's timer input-group (`#hours`'s `.input-group`) moves into our time strip beside the times, its old column is removed, and
  the row gets `mit-reply-foot` (strip first, then Status `mit-status-col` and the send buttons `mit-send-col`, right, one line). Hooks in `agent/post/ticket.php`:
  add_ticket_reply `mitReplyTimesFromPost` (after the time is built; typed times win unless within a minute; a submitted /
  approved day drops the times with a warning) + `mitReplyTimesSavePending` after the INSERT; edit_ticket_reply
  `mitReplyTimesFromEdit` (only the length changed -> the start moves; moving onto / off a locked day refused) +
  `mitReplyTimesSavePending` after the UPDATE. Edit modal `mitReplyTimesEditField` (one line in
  `agent/modals/ticket/ticket_reply_edit.php`), note header `mitReplyTimesText` (one line in `agent/ticket.php`). Notes without a
  row (older, API, bulk resolve, email) end when posted. Timesheets: `mitTsReplyWorkRows` (the day worked - work_start's date,
  else mitTsLocalDate of the posting) feeds `mitTsTicketMinutesLive`, `mitTsDayTickets` and the day panel's entry list
  (`mitTsViewDays` 'entries', `mitTsDayEntryFlags`: overlap between timed entries, outside = not within a California day's clocked
  work periods); `mitTsReplyTimeGuard` uses the work date.
  Quick fix from the day panel (26.10.114): an entry's times are clickable (`entryForm` / `saveEntry` in `agent/js/mit_timesheet.js`,
  "after the one before" fills start = the previous entry's end, same length) -> `mit_timesheet_ajax.php` save_entry ->
  `mitReplyTimesQuickSet` (Support edit, the ticket's client, stays on its day, `mitReplyTimesEntryLock` = `mitTsWhyDayLocked` +
  `mitReplyTimesLocked`; writes ticket_reply_time_worked + mit_reply_times, audit log, notifies the person when someone else did it).
  Entries carry `reply_id` / `editable`. No hook in ITFlow's files.
  California non-exempt (M018): `functions/mit_ts_california.php` (ours, required from mit_timesheets.php) - per person
  `mit_timesheet_people.ca_rules` (NULL = on for hourly; People modal), from `mit_settings` ts_ca_start on (set to the day M018
  ran - earlier days and periods keep their old hours; `mitTsCaOn($user_id, $date)`). Typed start / meal / end times per day
  (`mit_ts_work_periods`, gaps = meal periods, never rounded) are the hours worked - codes and ticket time only allocate them
  (`unallocated` = paid work on no code); no auto lost time, no unpaid work codes, no auto paid breaks, break-kind codes hidden.
  Meal rules (`mitTsCaAnalyze`: 30 min by end of hour 5 / 10), the person's answer per missed/late/short meal and
  "could not take a rest break" (`mit_ts_days`) -> meal / rest premium hours (unanswered = owed); CA overtime
  (`mitTsCaOvertime`: >8/day, >12 double, >40 regular/week, 7th consecutive day). Changes to times (`mit_ts_changes`) need a
  reason from anyone but the person, who acknowledges them (`?mit_ca_acknowledge=`). Submit = signed attestation
  (`mit_ts_attestations`: statement text + version, typed name, IP, UA, breaks answer / exceptions, JSON snapshot) via the
  window `mitTsCaSignModal`; `mitTsSubmit` / approve refuse without one; withdraw / send back / reopen set it aside
  (`mitTsCaSupersede`). Hooks in mit_timesheets.php: mitTsDays, mitTsDayWorked, mitTsTotals (double, premiums), mitTsSubmit,
  mitTsWithdraw, mitTsDecide, mitTsReopen. UI: the day panel of `agent/js/mit_timesheet.js` (Work times section, same meal rules
  in JS), `agent/mit_timesheet.php` (sign window, changes box), decide modal (`mitTsCaApproverNote`), payroll report (Double time, Meal / Rest premium
  columns; detail "Work times" rows). No FKs on the M018 tables: records outlive deleted logins (keep 3 years).
  Split shifts (M022): `mit_ts_work_periods.gap_before` (NULL = meal break, 'off' = off between shifts). The day panel enters
  blocks (Start / End, any number - `blockRow` / `blocks()` in the script, posted as `times[blocks][i][start|end|gap]`, read by
  `mitTsCaParseBlocks` - same rules in the script's `read()`: in time order, a block ending before it starts runs past midnight,
  a later block before the previous end only as after midnight within 12 h, start = end refused, 24 h max, MIT_TS_CA_MAX_BLOCKS 12;
  a save without `blocks` is refused, not read as empty). An off gap of 30 min+ still counts as a meal
  period in `mitTsCaAnalyze` (off duty); `mitTsCaPeriodsText` writes " / off / " between split shifts (change history, snapshot).
  Default code for time not on a code: `mit_settings` ts_unallocated_code (`mitTsUnallocatedCode` - paid work / lost codes,
  `mitTsUnallocatedCodes`); mitTsDays moves a California day's `unallocated` onto it (`filled` code_id => minutes, also in
  `codes`, work / lost); stored on approval as source `unallocated` (code_id = the code) by `mitTsSnapshotTickets`, read back
  for approved days in `mitTsTicketMinutes`, removed with the other copies on reopen. Day row chip and payroll detail row.
  Live fills stop at the code's daily max; its note / role settings don't apply.
  Time zones (M023): `mit_timesheet_people.time_zone` (NULL = the company's `config_timezone`, which ITFlow sets for the whole
  request). `mitTsOwnZone` / `mitTsZone` / `mitTsToday` / `mitTsLocalDate` in mit_timesheets.php: a person's
  today (mitTsDays auto lost, future block, closure fill, auto-lock, reminders, CA save, view, page, default period) and the day
  their ticket replies fall on (`mitTsReplyWorkRows` reads a range 2 days wider and dates each reply, `mitTsDayTickets`,
  `mitTsReplyTimeGuard`). Set in the People modal and Account > Preferences (`mitTsZoneSelect`, `mitTsSaveZone`). Typed times are
  stored as typed (parsed in the company zone - an overnight shift on a night only one zone changes clocks is an hour off).
  Changes (M024 `mit_ts_zone_changes`, also the record of who changed it + logAudit): `mitTsSaveZone` makes a change count from now,
  or from the day after their last submitted / approved period (in the old zone); `mitTsZoneAt` gives the zone in force when a reply
  was logged, and `mitTsLocalDate` never dates a reply before the day its zone started counting (in the zone before it), so a change
  can't move ticket time into or out of a period already handed in. M024 seeds existing zones from 1970.
  Behind on timesheets (team page `agent/mit_timesheets.php`): `mitTsBehind` (ended periods not submitted in the last
  MIT_TS_BEHIND_PERIODS, from the start date, approval on) with Remind / Remind all (`mit_ts_remind` -> `mitTsRemind`:
  notification + email, sets reminded_late_at, not again within an hour); Late badge and Send back on the rows. Send back emails too.
  Cron job `mit_timesheets` (`cron/mit_timesheets.php`, entry in `includes/cron_jobs.php`, hourly: closure days
  of the period just ended, reminders - `mit_timesheets.reminded_due_at` / `reminded_late_at` -, leave earned
  when approval is off (once the period has settled: auto-lock days, else 7), leave year ends - carry-over
  worked out again for the first 60 days). A charge code belongs to one leave bank at most.
  Holidays (Oct 2026): `functions/mit_ts_holidays.php` (ours, required from mit_timesheets.php) - Admin > Timesheets > Holidays tab
  (year at a time; modal `admin/modals/mit_timesheets/holiday.php`, handlers `mit_ts_holiday_save` / `_delete` / `mit_ts_holidays_us`
  in `admin/post/mit_timesheets.php`) edits ITFlow's own `business_holidays` (Admin > SLA closure days - ITFlow there has add /
  delete / US year only). After a change: `getBusinessHolidays(true)` + ITFlow's `restampOpenSlaTickets()`, and `mitTsHolidayChanged`
  puts open timesheets right - entries on the old date with a closure-fill code and the holiday's name as the note are deleted
  (moved / removed) or renamed, and `mitTsFillClosures` fills the new date for days already gone; submitted / approved sheets
  (`mitTsHolidayLocked`) are left and counted in the message. No tables of its own.
  On the calendar as the `holidays` source (all-day, red): `mitHolidayCalendarSources` / `mitHolidayCalendarItems` in
  `functions/mit_calendar.php`, hooks in `functions/calendar.php` calendarSources (`+=`) and calendarSourceItems (early return).
  Leave balances (M014): `functions/mit_leave.php` (ours, required from mit_timesheets.php) - banks
  (`mit_leave_banks`: codes that use it up, earned per period / per hour worked / yearly / not, max, carry-over
  keep all / cap / lose, year from 1 Jan or hire anniversary, waiting days, below 0 allowed), ledger
  (`mit_leave_ledger`, unique person+bank+kind+ref for automatic rows), per-person rate / left out
  (`mit_leave_rates`), `mit_timesheet_people.hire_date`. Balance = ledger - hours on its codes. Period earnings
  added in `mitTsDecide` approve (`mitLeaveAccruePeriod`), removed in `mitTsReopen`; save checks
  `mitLeaveCheckSave`. UI: balances box on the timesheet (`mitLeaveTimesheetBox`, history modal
  `agent/modals/mit_timesheet/leave.php`), Admin > Timesheets > Leave tab (`admin/modals/mit_timesheets/leave_bank.php`)
  and People tab (`person_leave.php`: rates, adjustments, history); "<bank> left" columns in the payroll report. Hooks: one line in `functions/reports.php`
  reportCatalog (`'mit_payroll' => mitTsReportEntry()` after time_by_tech), require of
  `agent/includes/mit_timesheet_nav.php` in `agent/includes/side_nav.php` (its own TIME section, before TOOLS),
  require of `admin/includes/mit_admin_nav.php` in `admin/includes/side_nav.php` (inside SETTINGS after Ticket,
  plus `array_merge(..., mitAdminSettingsPages())` on the SETTINGS menu-open line), Account > Preferences
  (`mitTsPreferenceFields` in `agent/user/user_preferences.php`, `mitTsSavePreferences` in
  `agent/user/post/profile.php`), `functions.php` (require).
- Dispatch (Oct 2026): `functions/mit_dispatch.php` (ours) - page `agent/mit_dispatch.php`, script
  `agent/js/mit_dispatch.js` (Day: one FullCalendar v7 timeGridDay per tech - v7 options, e.g. `slotHeaderContent`,
  and hashed class names, so style by data- attributes; Week: techs x days table with SortableJS), requests
  `agent/mit_dispatch_ajax.php` (queue, items, book, unbook, state). Queue: open tickets with no tech / with a tech
  and nothing booked. Book = `ticketChangeAssignee` + `ticketSetSchedule`; unbook = `ticketCancelSchedule`.
  The Schedule dialog also shows the ticket's title (editable), client, contact, priority, status, category and SLA (`?details=` /
  `?contacts=`); changes are saved first by `mitDispatchSaveDetails` - the same rules as ITFlow's Edit ticket,
  Change client (`change_client_ticket`, public replies become internal) and SLA forms; re-check it if those change.
  Under Contact it lists the contact's other open tickets (`?contact_tickets=`) with "Merge into this" / "Merge this into it"
  (`mitDispatchMerge`: ITFlow's Merge form checks, a scheduled ticket being merged has its schedule cancelled, then ITFlow's
  own `ticketMergeInto()`, which lives in `agent/post/ticket.php` and is loaded from there - re-check if upstream moves it).
  Permission (M035, 26.10.105): ITFlow module row `module_dispatch` (Admin > Roles "Dispatch") - `mitDispatchLevel`: View 1 sees the
  board / queue / Workload / This week (`mitDispatchCan`; menu, page, GETs), Edit 2 books, moves, assigns, merges (`mitDispatchCanEdit`
  = Dispatch Edit + Support edit; every POST but `state`, item `editable`, `data-can-edit` -> the script's `canEdit` turns off dragging,
  Sortable and Move to; "View only" badge). M035 gave Dispatch Edit to every role that had Support edit; before it runs the old rule
  (Support edit) applies. Suggested: Technician View, a Dispatcher role (Client View, Support Edit, Dispatch Edit). Smoke
  `14-dispatch-role.spec.js`. Settings per agent in `mit_user_preferences` dispatch.state.
  Hooks: require of `agent/includes/mit_dispatch_nav.php` in `agent/includes/side_nav.php` (SUPPORT, after
  Recurring Tickets), `functions.php` (require), and one condition in `functions/ticket.php` ticketMailSchedule
  (`empty($GLOBALS['mit_schedule_skip_contact'])` - the "Email the contact" tick; a global rather than a new
  parameter so ITFlow's function signature stays as it is). Working hours on the board come from Admin > SLA
  (`mitDispatchBusinessHours`). Needs ticketing on and the Dispatch switch on.
  Workload view (Oct 2026): `functions/mit_dispatch_workload.php` (ours, required from mit_dispatch.php) - third view button;
  `?workload=` / POST `assign=` in `agent/mit_dispatch_ajax.php`, `renderWorkload` in the script. Per tech their open tickets as
  lead or helper, ITFlow's `ticketSlaState` counts, overdue, not scheduled, quiet (no reply of ours in 3 days, not paused), booked
  hours today / this week vs Admin > SLA working hours; flags Falling behind (breached / past due), Watch, Overloaded / Has room
  vs the team average (`mitDispatchWorkloadFlags`). Move to: unbooked = `mitDispatchAssign`, booked = the Schedule dialog.
  No tables or hooks of its own (who assigned a ticket is in ITFlow's ticket history).
  The board also shows what else is on each agent's day (`mitDispatchItems`): calendar events they organise or are invited to
  (ITFlow's `calendarEventItems` with their user_id - not movable there, click opens the event), closure days (holidays source)
  and Outlook busy times; items are drawn like the calendar page (`eventContent` with the itflow-cal-* classes: time, project
  tech dot, `extendedProps.icon` - fa-car onsite / fa-headset remote / meeting icons -, title, detail; the week chips carry the
  icon too). Clashes (`mitScheduleConflicts`) count those events as well (`mitEventConflicts`, all-day ones left out).
  Week view: the Agent column is sticky (left: 0) with solid header / name cells (`.table.mit-dispatch-week > thead|tbody > tr > th`
  beats the modern look's transparent table headings), and a day is at most 200px wide (chips cut short, full title on hover).
  "This week" card under the board (all views; `mitDispatchWeekStats`, `?week_stats=`, `renderStats` + Chart.js from ITFlow's footer):
  inbound vs closed per day (closed = COALESCE(resolved, closed)), vs last week / 4-week average, SLA met / missed for targets due
  this week (resolution also when closed this week), breached / close now, and per shown agent: assigned this week (created for
  them, or ITFlow's ticket_history "assigned the ticket to <name>") vs closed, open now, SLA missed. Week from the agent's calendar first day.
- NinjaOne RMM (Oct 2026): `functions/mit_ninjaone.php` (ours, required from `functions.php`) - replaces the user's n8n
  "NinjaOne -> ITFlow asset sync" with its rules: device -> asset by link (`mit_ninja_devices.asset_id`) or the n8n notes block
  (`[NinjaOne Sync]` / `NinjaOne Device ID:` / `UID:`), then serial in the mapped client - never by name (a same-name asset is
  kept and reported); misplaced (report | create), archived left, placeholder serials -> UID, safety brake, only sync-owned fields
  changed (`mitNinjaChanges`), orphans reported. API: client credentials, scope monitoring, `/v2/organizations`,
  `/v2/devices-detailed`, `/v2/device/{id}?expand=warranty` (warranty at most 150 a run, weekly per device). Tables M019:
  `mit_ninja_orgs` (mapping), `mit_ninja_devices` (link, status, warranty; FK assets SET NULL), `mit_ninja_runs` (report JSON).
  Settings `mit_settings` ninja_* (secret + token via `mitEncryptSecret`; `ninja_web_url` = the user's own NinjaOne address for
  buttons, `mitNinjaCleanWebUrl`). Admin > Integrations > NinjaOne `admin/mit_ninjaone.php` (tabs Settings / Mapping with n8n import
  `mitNinjaImportN8n` / Sync runs), handlers `admin/post/mit_ninjaone.php`, menu `admin/includes/mit_integrations_nav.php` (one require in
  `admin/includes/side_nav.php` INTEGRATIONS after AI Providers). Cron job
  `mit_ninjaone_sync` hourly (`cron/mit_ninjaone_sync.php`, entry in `includes/cron_jobs.php`). Buttons + live status:
  `agent/js/mit_ninjaone.js` finds asset links (`modals/asset/asset.php?...id=`) and `[data-mit-ninja-asset]`, asks
  `agent/mit_ninjaone_ajax.php?status=` (`mitNinjaAssetDevices`: Support view + client scope, status re-asked after 5 min). Hooks:
  the ticket page gets the script through `mitTicketLayoutHtml` (no extra line in agent/ticket.php); one line each in
  `agent/asset.php` and `agent/modals/asset/asset.php` (`mitNinjaAssetButton`). A test install may set `MIT_NINJA_API_BASE` in
  config.php to point at a stand-in API (never on a real one).
  26.10.121: "Run sync now" (`admin/post/mit_ninjaone.php`) does `session_write_close()` before `mitNinjaSync` and `session_start()` after
  (a long run used to hold the session lock - every other page of that person waited); the same around QuickBooks Sync now, usage
  connector Run now and Customer Thermometer Run now. Warranty lookups only for devices of mapped organizations. Unmapped
  organizations go in the report's `notes` (shown as `[data-mit-ninja-note]` with the list in a <details>), not `errors`. The
  test install's stand-in (`ninja-mock/index.php`) has `/_slow?seconds=`, `/_calls`, `/_clear`; smoke `22-ninjaone-sync.spec.js`.
- QuickBooks Online (Oct 2026, first release - steps 1-3 of the billing plan; later: usage billing on agreement lines with an
  inbound API, monthly snapshot and review, then source connectors NinjaOne / Bitwarden / Ingram Micro / Parallels / M365):
  Audit fixes (26.10.101): payments refused for any live batch entry (`mitQboTakesPayments`; hooks also in `cron/nightly_tasks.php` autopay +
  reminders loop, `client/post.php` add_payment_by_provider, `guest/guest_ajax.php` intent), `mark_invoice_sent` guarded, client delete refused
  (`mitQboGuardClientDelete` in `agent/post/client.php`, `mitQboClientLock` in `api/v1/clients/delete.php`), send re-checks status / payments and
  adopts a QuickBooks invoice a timed-out try made (DocNumber + customer + marker), voids seen by the sync (TotalAmt 0), unlink keeps payments,
  locks `qbo_sync` / `qbo_batch_<id>` on sync, remove, delete; `qbo_mapped_realm` guards a change of company; fingerprint without item_product_id.
  `functions/mit_qbo.php` (connection - Intuit OAuth 2.0 code flow, tokens via `mitEncryptSecret`, refresh under
  `mitLock('qbo_token')`; API v3 JSON, minorversion 75, `mitQboApi` / `mitQboQueryAll`; mappings; `mitQboInvoicePayload` - number
  = prefix + number (QuickBooks needs "Custom transaction numbers" on), US tax = TAX / NON lines + one TxnTaxCodeRef, discount +
  credit as one discount line) and `functions/mit_qbo_billing.php` (required from it: approvals = sha256 fingerprint of the
  invoice + lines in `mit_invoice_approvals`, "changed" when it differs - no hooks in ITFlow's edit paths for that; batches;
  locks; send; take out = void (refused while paid there) or unlink -> Draft + unapproved, the sync's payment deleted; payments
  back = one ITFlow payment per sent entry, method 'QuickBooks Online', = paid there (all of ITFlow's amount when the balance
  there is 0) less other ITFlow payments, then `updateInvoiceStatusFromPayments`). Tables M031 `mit_qbo_customers` / `_items` /
  `_tax_codes` (CASCADE), `mit_invoice_approvals`, `mit_qbo_batches`, `mit_qbo_batch_invoices` (payment_id SET NULL); settings
  `mit_settings` qbo_*. Permission: pages Sales view, approve / batch / send / take out Financial edit (`mitQboCan`). Pages
  Admin > Integrations > QuickBooks Online `admin/mit_qbo.php` (handlers `admin/post/mit_qbo.php`, Intuit returns to
  `admin/mit_qbo_callback.php` - the redirect URI), Billing > Invoice Approvals `agent/mit_invoice_approvals.php`, Billing >
  QuickBooks Batches `agent/mit_qbo_batches.php` + `agent/mit_qbo_batch.php` (handlers `agent/post/mit_qbo.php`; GET
  `mit_invoice_approve` / `mit_invoice_unapprove` with the token from the invoice strip). Menus: `admin/includes/mit_integrations_nav.php`,
  `agent/includes/mit_billing_nav.php` (shown once a client ID is saved; counts awaiting / ready). Hooks (one guarded line each):
  `agent/post/invoice.php` `mitQboGuardInvoice` in edit_invoice, mark non-billable, cancel, delete, add / edit / delete item,
  add / remove ticket, `mitQboGuardEmail` in email_invoice (quick send too); `agent/ajax.php` invoice_set_notes and item
  reorder (409 when locked); `agent/post/payment.php` `mitQboGuardPayment` add_payment / add_payment_stripe, `mitQboGuardInvoice`
  apply_credit, `mitQboGuardPaymentEdit` edit / delete / refund, bulk payment skips invoices in QuickBooks; `agent/post/ticket.php`
  add_invoice_from_ticket onto an existing invoice; `api/v1/invoice_items/create.php` (refused as not found); our
  `mitTicketPartsBill`; `cron/nightly_tasks.php` reminders + late fees skipped and recurring auto-send off while connected
  (`mitQboHandlesInvoiceMail`), same auto-send line in `agent/post/recurring_invoice.php` force; `agent/invoice.php`
  `mitQboInvoiceBar` (strip above the items; its `<style>` hides email / edit / payment buttons - the handlers refuse anyway);
  `agent/invoices.php` `mitQboResultIds($sql)` before the loop + `mitQboListBadge` by the number. Cron job `mit_qbo_sync` hourly
  (`cron/mit_qbo_sync.php`, entry in `includes/cron_jobs.php`; fails when no payment account is set). The test install has a
  stand-in at `qbo-mock/index.php` (not in the repo; `MIT_QBO_AUTH_URL` / `_TOKEN_URL` / `_REVOKE_URL` / `_API_BASE` in its
  config.php - never on a real one); smoke `10-qbo.spec.js`. Not yet: portal / guest Stripe pay is not blocked for invoices in
  QuickBooks (turn ITFlow's Stripe off), statements still come from ITFlow.
- Usage billing (Oct 2026, step 4 of the billing plan; step 5 = source connectors reporting through its API): `functions/mit_usage.php`
  (ours, required from `functions.php`). Tables M032: `mit_usage_lines` (a recurring invoice line billed by a count - source
  manual / api / tag, usage name `line_metric` - tag lines "tag:<id>" -, optional minimum; FK recurring_invoice_items /
  recurring_invoices CASCADE), `mit_usage_counts` (client + metric + as_of, source, note; FK clients CASCADE), `mit_usage_applied`
  (per generated invoice line: snapshot, count used, base / previous / applied qty, flags, override + reason; FK invoices,
  invoice_items CASCADE, line SET NULL). Settings `mit_settings` usage_snapshot_day (1-28) / usage_change_pct / usage_stale_days
  (card `admin/includes/mit_usage_card.php` - one require at the end of `admin/settings_invoice.php`, handler
  `admin/post/mit_usage_settings.php`). `mitUsageApply($new_invoice_id, $recurring_invoice_id)` - one guarded line each in
  `cron/nightly_tasks.php` (after "Invoice Generated from Recurring!") and `agent/post/recurring_invoice.php` force_recurring (after
  the amount update): matches the new invoice's lines to the recurring lines by item_id order (how ITFlow copies them), sets
  qty from the last count on or before `mitUsageSnapshotDate()`, recalculates, writes history, and returning true turns
  auto-send off (stays a Draft). UI: `mitUsageRecurringCard` (one line in `agent/recurring_invoice.php` before History),
  `mitUsageClientCard` (one line at the end of the billing section in `agent/client_overview.php`), Billing > Usage Review
  `agent/mit_usage_review.php` (override = `mitUsageOverride`; Approve = the QuickBooks approval link), nav entry in
  `agent/includes/mit_billing_nav.php`, handlers `agent/post/mit_usage.php`. API `api/v1/mit_usage/create.php` / `read.php` - one
  line in `api/v1/enforce_api_rbac.php` (`'mit_usage' => 'module_sales'`). Cron job `mit_usage` daily 00:30 (`cron/mit_usage.php`,
  `mitUsageRecordTagCounts`; apply also counts tags when the snapshot is today). Smoke `11-usage.spec.js`.
  Connectors (step 5, 26.10.100): `functions/mit_usage_connectors.php` (required from mit_usage.php), table M033 `mit_usage_links`
  (connector bitwarden | ingram, client FK CASCADE, external id unique per connector, encrypted secret, last run). `mitUsageRunConnectors`
  runs from cron `mit_usage` (throws when any connector had a problem) and "Run now"; counts via `mitUsageRecordDaily` (one per day
  per client + metric + source; count_source ninjaone | bitwarden | ingram - `MIT_USAGE_COUNT_SOURCES`). NinjaOne: from
  `mit_ninja_devices` seen in 7 days by `mit_ninja_orgs` mapped client, `usage_ninjaone_on`. Bitwarden: per client org API key
  (`organization.<uuid>` + secret, scope api.organization, `/public/members`, status >= 0 = users, 2 = confirmed), region
  `usage_bitwarden_region`. Ingram Micro Cloud Marketplace: `usage_ingram_*` (marketplace, login, password + subscription key
  encrypted, optional base; token POST {base}/token with X-Subscription-Key, cached `usage_ingram_token`), GET /customers and
  /subscriptions?customerId=&status=active (products[].mpn / name / quantity, summed per mpn) - the real API was not reachable
  when built: confirm on the first live run. Page Admin > Integrations > Usage connectors `admin/mit_usage_connectors.php`
  (handlers `admin/post/mit_usage_connectors.php`, menu `admin/includes/mit_integrations_nav.php`). The usage card's metric box has
  a datalist from `mitUsageKnownMetrics` (Ingram names from the count note). Test install stand-in `usage-mock/index.php` (not in
  the repo; `MIT_BITWARDEN_IDENTITY_URL` / `MIT_BITWARDEN_API_URL` / `MIT_INGRAM_API_BASE` in its config.php); smoke `12-connectors.spec.js`.
  Audit fixes (26.10.101): NinjaOne counts need a sync run in 2 days (`mitUsageNinjaFreshSync`), classes `MIT_NINJA_WORKSTATION_CLASSES` /
  `MIT_NINJA_SERVER_CLASSES`, metrics workstations / servers / devices (= both) / all; `mitUsageRecordDaily` cleans the name and returns a
  problem; Ingram products keyed by the cleaned metric, rows de-duplicated by id, other customers' subscriptions skipped,
  `mitUsageIngramBaseOk` (public https, re-checked before the token call), new address needs the secrets again; `mitUsageRunConnectors`
  under `mitLock('usage_connectors')`, each connector in its own try. Usage: tag lines skip deleted / archived tags, `tag:` names refused for
  other sources, previous_qty from the live line of a still-billable invoice, `mitUsageApply` never lets a failure send the invoice.
- Project tickets kept apart (Oct 2026): `functions/mit_projects.php` (ours) - a ticket in a project (ITFlow's
  `ticket_project_id`) is off the Tickets board / client tab (`mitProjectTicketsShows` adds `ticket_project_id = 0` to each
  view of `agent/tickets.php` plus a "Projects" view, unless the Project filter is set), the Dispatch queue + its menu count
  and the dashboard's attention counts (`mitServiceTicketsAnd`); still in My tickets, Workload and the calendar. No SLA (open tickets only - resolved / closed tickets' SLA data is never changed):
  `mitProjectSlaOff` forces SLA 0 in `functions/sla.php` applyTicketSla, and `mitProjectSlaStart` starts a ticket's SLA from
  when it left a project (`mit_project_ticket_sla`, M020). Moves call `mitProjectTicketMoved` (one line each in
  `agent/post/ticket.php` edit_ticket_project + bulk project, `functions/project.php` projectAddTicket / projectRemoveTicket);
  cron job `mit_project_tickets` (`cron/mit_project_tickets.php`, every minute, `mitProjectTicketsSweep`) catches the API and
  project delete. Calendar: `mitProjectCalendarItems($items, $opts)` (one line in `functions/calendar.php` tickets source) - project
  color, `extendedProps.tech_color` drawn as a dot by `agent/js/calendar.js` (eventContent, 4 lines) and `agent/js/mit_dispatch.js`.
  On the calendar page project bookings belong to the Projects tick box: `agent/ajax.php` calendar_items passes
  `mit_split_projects` in `$opts` (one line), the tickets source then leaves them out and the projects source adds them
  (`mitProjectBookingItems`, one line in `functions/calendar.php` projects branch); Dispatch and calendar_day still get all.
  Settings card `admin/includes/mit_project_card.php` (one require at the end of `admin/settings_project.php`), handler
  `admin/post/mit_project_settings.php`; `mit_settings` project_tickets_apart / project_ticket_color.
- Email reply recipients (Oct 2026): `functions/mit_reply_recipients.php` + `agent/js/mit_reply_recipients.js` (ours) - the reply
  box's "Email reply" has one "To" line (ITFlow's own picker, `js/itflow_select.js`: a multi-select with data-tags, names as chips,
  group + email as search words): the contact and watchers start in it; the lead agent and helpers, the client's other
  contacts and typed addresses can be added; only those in it are emailed (contact: ITFlow's email, the rest with the
  collaborator line), logged in the ticket history. The choice is remembered per ticket where it differs from that default
  (`mit_ticket_mail_choices`, M021). Hooks: one line in `agent/ticket.php` after `#replyAudience` (`mitReplyRecipientsField`),
  and in `agent/post/ticket.php` add_ticket_reply `mitReplyRecipientsMail` replaces `ticketMailContactAndWatchers` only when
  the form posted `mit_send_to_present` (API and other senders unchanged). "Email reply" still needs a contact with an address
  (ITFlow's `$can_email_reply`). `functions.php` (require).
  Internal notes have a "Notify" line in the same file (`mitInternalNotifyField` / `mitInternalNoteNotify`): empty to start, any active
  agent who can see the client (`mitAgentCanSeeClient`); each picked gets a bell notification and an email (ticket From address,
  [TCK-n] subject - a reply from an active agent's own address is filed by the parser as an internal note by them, without the
  mismatch banner, reopening or "ticket updated" mails: one branch in `cron/ticket_email_parser.php` addReply). The `agent/ticket.php`
  hook now always calls `mitReplyRecipientsField($ticket_id, $can_email_reply)` (Notify needs no contact email); one guarded block in
  `agent/post/ticket.php` add_ticket_reply after the agent notifications. The script closes both pickers when focus moves into a frame (TinyMCE) -
  ITFlow's picker closes on outside mousedown, which a click inside the editor's iframe never fires.
- Remembered list views (Oct 2026): `functions/mit_list_views.php` (ours) - the Tickets list keeps each agent's
  pill, filters, sort and List / Board in `mit_user_preferences` `listview.tickets` (like the calendar) and puts
  them back when Tickets is opened with none; saved only when picked on the page itself (Referer), never q / page /
  open, not in a client's ticket list. Hooks in `agent/tickets.php`: one line after `enforceUserPermission`
  (`mitListViewRestore('tickets')`, then filter_header.php runs again so sort / order / dates follow) and
  `mitListViewResetButton('tickets')` before the List / Board group (`?mit_reset_view=<csrf token>`). `functions.php` (require).
- Service boards (Oct 2026, part 1): `functions/mit_boards.php` (ours) - who works a ticket (Service Desk, Tier 1, Tier 2, Sysadmin,
  Sales), apart from its category. Tables M026: `mit_boards` (one `board_is_default`, archived kept), `mit_board_members` (notify
  per member), `mit_ticket_boards` (no row = the default board - `mitBoardSql($alias)`), `mit_board_rules` (mailbox / client /
  category -> board; first match in that order, else the default). Organise only - no visibility limits. New tickets: New Ticket's
  Board picker (`mitBoardNewTicketField` beside Category in `agent/modals/ticket/ticket_add.php`; `mitBoardAfterCreate` in
  `agent/post/ticket.php` add_ticket, `mitBoardRoute` for per-asset tickets), the parser by the To / Cc / Delivered-To /
  X-Original-To addresses (`$GLOBALS['mit_board_addresses'] = mitBoardMessageAddresses($message)` in the message loop,
  `mitBoardRoute` in addTicket after applyTicketSla), and cron job `mit_boards` (`cron/mit_boards.php`, every minute,
  `mitBoardSweep`: tickets since `mit_settings` boards_since with no row - portal, API, recurring, projects). Members with notify
  on (and access to the client, never the one who moved it) get a bell + email (`mitBoardNotify`). Ticket page: Board line
  (`mitBoardTicketProp` after `mitTicketTechsProp` in `agent/ticket.php`) and "Escalate to another board" in the ⋮ menu
  (`mitBoardEscalateMenuItem`, after Move to another client) - modal `agent/modals/mit_board/board_move.php` (escalate: reason
  required -> internal note, unassign ticked), handler `agent/post/mit_boards.php` (`mitBoardMove`). Tickets list: board switcher
  with open counts (`mitBoardSwitcher` after the h3, `mitBoardTicketsFilter` after the Project filter, `mitBoardHiddenInput` in the
  form of `agent/tickets.php`; `board` remembered by `mit_list_views.php`), a board tag per row (`mitBoardListTag($id, $page_ids)` - one query per page - in
  `agent/ticket_list.php` / `ticket_kanban.php`). Admin > Settings > Boards `admin/mit_boards.php` (tabs Boards / Routing, "Place
  open tickets by category"), modal `admin/modals/mit_boards/board.php`, handlers `admin/post/mit_boards.php` (archiving moves
  its tickets to the default and drops its rules); menu in `admin/includes/mit_admin_nav.php`. Dispatch: board picker (All / My
  boards - the default when the agent is on one - / each; `mitDispatchBoardChoice`, saved in dispatch.state with `board_techs`),
  queue / Workload / This week filtered (`mitDispatchBoardWhere`), board tag on queue cards, Board field in the Schedule dialog
  (`detail_board` -> `mitBoardMove`), "Only the board's members" in the Agents list (a board with nobody on it shows everyone),
  side-menu count on the agent's own boards.
  Part 2 (M027): `mit_board_moves` (every landing / move - routed, created, moved, escalated, placed, archived - by
  `mitBoardLogMove`; seeded from mit_ticket_boards). Round-robin per board (`mit_boards.board_assign` / `_max` / `_hours` /
  `_last` / `_since`, `mit_board_members.member_rotation`; Auto-assign section of the board modal): `mitBoardAutoAssign` after
  `mitBoardRoute` / `mitBoardMove` when the ticket is unassigned, and `mitBoardAssignSweep` in cron `mit_boards` (tickets placed
  since it was turned on - never the backlog; ones that waited for Admin > SLA working hours). Next member in turn after
  `board_assign_last`, skipping: out of the turn, can't see the client, holding the max open on the board, leave today
  (`mit_time_entries` code_kind leave), Outlook `oof` now (`mit_outlook_busy`); board read fresh under `mitLock`. Uses ITFlow's
  `ticketChangeAssignee` with `$session_name` = "Auto-assign (<board>)" and `$session_user_id` 0 for the call (cron loads
  `load_global_settings.php` for its email). Dashboard: `mitBoardDashChoice` (?board=, saved as `mit_user_preferences`
  dashboard.board), hooks - one line each - in `agent/dashboard.php` (`mitBoardDashLinks` after $attention, `mitBoardDashPicker`
  before Period, `mitBoardDashCard` "Your boards" before Your day) and `functions/dashboard.php` (`mitBoardDashAnd()` on
  dashboardAttention's $ticket_scope, dashboardTeamWorkload and dashboardTicketTrend's $scope). Report Board Performance
  `agent/reports/mit_boards.php` (catalog line `'mit_boards' => mitBoardReportEntry()` in `functions/reports.php` reportCatalog
  before sla_by_client): per board new / moved in / escalated on / resolved / median first reply and resolve / SLA / open now;
  escalation flows; agents for one board; CSV.
  Details box pickers (26.10.106): `functions/mit_ticket_picks.php` (ours, required from mit_boards.php) - Board, SLA and Category on
  the ticket page change in place with ITFlow's own quick picker (`js/quick_pick.js`) instead of a window: fields `mit_board`
  (`mitBoardMove`), `mit_sla` (as edit_ticket_sla: applyTicketSla pinned, history, audit, custom action), `mit_category` (as
  bulk_edit_ticket_category for one ticket). `mitTicketPickButton` copies quickPick()'s 'detail' markup. Hooks: one line each in
  `agent/ajax.php` quick_pick_options (`mitTicketPickOptions`, after the Support edit check) and quick_pick_set (`mitTicketPickSet`,
  after `$ticket_ids`), `agent/ticket.php` SLA and Category (`function_exists` branch before ITFlow's ajax-modal links, kept as the
  fallback), `mitBoardTicketProp`, and `js/quick_pick.js` reloads the page on `result.reload` (SLA change, board move that
  auto-assigned). Escalate (with a reason) stays in the ⋮ menu. Smoke `15-ticket-picks.spec.js`.
  Wording: ITFlow's kanban toggle on `agent/tickets.php` says "Kanban" (was "Board") and its first pill there "Active" (was "On the
  board"), so "board" on that page only means a service board - two strings; keep them if an upstream merge brings the old ones back.
  Boards by role (M034, 26.10.102): `functions/mit_board_roles.php` (ours, required from mit_boards.php) - each ITFlow role sees every
  board (no row / `limited` 0) or only the ones ticked (`mit_role_board_access`, `mit_role_boards`; FK user_roles / mit_boards
  CASCADE); admin roles and sessions without a role (cron, API) see all. Hidden from lists only, not blocked (a link still opens
  the ticket); still shown: assigned to them, helping on (mit_ticket_techs), escalated by them (mit_board_moves). `mitBoardVisibleSql`
  is appended by `mitBoardWhere` (Tickets list / kanban / client tab, Dispatch queue / Workload / week stats, menu counts), the
  switcher, dashboard (`mitBoardDashAnd`, picker, Your boards card), and one guarded line each in `functions/search.php` (tickets
  and ticket_replies groups) and `functions/client_overview.php` clientOverviewOpenTickets; calendar via `mitBoardVisibleCalendarItems`
  in `mitCalendarTicketItems`. New Ticket and the board pickers offer `mitBoardsVisible()`; Escalate / Move offer every board.
  UI: "Service boards" section (`mitBoardRoleField`) - one line in `admin/modals/role/role_add.php` / `role_edit.php` before the footer,
  saved by `mitBoardRoleSave` (one line each in `admin/post/roles.php` add_role / edit_role, only when `mit_boards_present` is
  posted); Admin > Settings > Boards shows "Seen by" (`mitBoardSeenBy`). Smoke `13-board-roles.spec.js`.
- Modern look (Oct 2026): `functions/mit_theme.php`, `css/mit_theme.css`, `js/mit_theme.js`, `css/fonts/inter/` (Inter,
  OFL - from @fontsource-variable/inter, latin + latin-ext) - ours. Colours, type and spacing over ITFlow's own styles, no markup
  changes: slate sidebar with a blue active pill, white top bar (the script switches ITFlow's `.app-header` `data-bs-theme="dark"`
  to light), grey rounded `.card` panels with white boxes inside, `.itflow-dash-rail` counts as pastel icon tiles, small-caps
  table headings, tinted badges (inline-coloured statuses / tags get `--mit-tone` + `.mit-tone` from the script), charts redrawn
  in Inter. Agent / admin / account / report pages only (not the portal, guest pages or login), light and dark (every colour
  is a `--mit-*` variable, set again under `[data-bs-theme="dark"]`; ITFlow's `--itflow-accent` / `--bs-success` ... are set
  from them, which is also how its charts - js/dashboard.js, client_overview.js, reports.js - get the palette). The accent
  follows the company theme (`config_theme` -> ITFlow's `body.theme-<colour>`): one generated line per colour (and per colour in
  dark mode) sets `--mit-accent`, `--mit-on-accent` (dark on yellow), `--mit-link` + `--bs-link-color-rgb` / `--bs-primary-rgb`
  (Bootstrap colours links from the -rgb triple); blue keeps #2f6fde. Keep that list in step with ITFlow's theme colours
  (`admin/settings_theme.php`). Per agent:
  `mit_user_preferences` ui.look (modern - the default - | classic). Hooks: one line in `includes/header.php` after
  itflow_custom.css (`mitThemeHead()` - which also loads `js/mit_time_inputs.js` for every look: a time field's clock icon puts in
  the time now - the icon gets `pointer-events: none`, a click near the right edge is ours; rounded to the field's step -, and typing
  a time into an empty time / datetime-local field fills AM / PM from now once the minutes are in (keys followed per segment the way
  Chrome takes them, date segments in the Intl order, nothing on a 24-hour clock); a click alone fills nothing; smoke tests empty
  time fields with helpers.js `clearTime`), `mitThemePreferenceField` in `agent/user/user_preferences.php`, `mitThemeSavePreference`
  in `agent/user/post/profile.php`, `functions.php` (require). Selectors to re-check after an upstream restyle: `.app-header`,
  `.app-sidebar .nav-link.active`, `.card-header.bg-dark`, `.itflow-dash-rail*` (items with `.itflow-dash-rail-label` get the
  one-line tile; others, like the client overview's Financial strip `.itflow-ov-stats`, stay stacked), `.itflow-client-switcher`, `btn-outline-dark`
  / `-info` reply-type toggles.
  Logo on its own (26.10.120, every look): `mitBrandLogoOnly` / `mitBrandLogoField` / `mitBrandLogoSave` in `functions/mit_theme.php`,
  `mit_settings` brand_logo_only - one line under the upload field in `admin/settings_company.php`, one in `admin/post/settings_company.php`
  edit_company, and in `agent/includes/side_nav.php` `$mit_logo_only` (the logo tile gets `data-mit-logo-only` + an inline style to take
  the width, the name span is left out). Smoke `21-brand-logo.spec.js`.
  Sign-in screens (for everyone, not per agent): `css/mit_login.css` + `css/mit_login_nodes.svg` (a file - login.php sends
  `Content-Security-Policy: default-src 'self'`, which refuses data: images), loaded by `mitLoginHead()` - one line after
  itflow_custom.css in `login.php` and `client/login_reset.php`. Only `.login-box` / the "Powered by" line are lifted over the
  backdrop - AdminLTE's `.skip-links` must stay as they are (hidden until tabbed to).
  Quotes / invoices / recurring invoices as a document: CSS only, keyed on the card around ITFlow's `table#items`
  (`.card:has(> .card-body table#items)`, `#items`, the `.row.bg-light` To / Bill To block, the totals `.table:has(tr.h5)`) -
  re-check if upstream reworks those pages (upstream develop rebuilds them as one shared page). `js/mit_theme.js` hides an
  `img[alt="Company logo"]` that failed to load (ITFlow's `file_exists` there is true for the folder when no logo is set).
  Client tiles and header line (`functions/mit_client_tiles.php`, modern look only): `mitClientTiles('assets'|'licenses'|'tickets',
  $client_id)` - one line each before the main card in `agent/assets.php`, `agent/software.php`, `agent/tickets.php` (only with
  `$client_url`); tiles link to ITFlow's own filters (expire_days, show=overdue / unassigned / closed). `mitClientHeadMeta()` -
  one line after `tagPills` in `agent/includes/inc_client_top_head.php` (primary contact, phone, email, website from
  inc_all_client.php's variables).
- Cron health (Oct 2026): `functions/mit_cron_health.php` (ours) reads ITFlow's own `cron_jobs` / `config_cron_last_dispatch_at`
  (`mitCronProblems`: not running > 5 min, switched off, a job Failed, stuck Running > 2 h, or late - interval jobs past two
  intervals (min +10 min), daily ones past 26 h). Shown as a warning next to the bell for admins (`includes/mit_cron_nav.php`,
  one require in `includes/top_nav.php` after the timers); emailed by cron job `mit_cron_watch` (`cron/mit_cron_watch.php`,
  entry in `includes/cron_jobs.php`, every 5 min: new / daily reminder / fixed, state in `mit_settings` cron_alert_state;
  cron_alert_email, cron_alert_to - empty = active admins); `/mit_health.php?key=` (ours, web root, `cron_health_key`) answers
  200 OK / 503 PROBLEM for an outside uptime monitor - the only alert when cron itself stops. Settings card
  `admin/includes/mit_cron_card.php` (one require at the end of `admin/cron.php`), handlers `admin/post/mit_cron_health.php`.
- Audit fixes (Oct 20, 26.10.72) in our code: Graph mail listing pages on past flagged mail and fails the job on errors
  other than throttling (`functions/mit_m365_mailbox.php`); `api/v1/clients/delete.php` calls `mitQuoteImagesDelete` /
  `mitTicketPartsDeleting` (hooks, like agent/post/client.php); reimbursed purchases keep their expense in step
  (`mitReimbSyncExpense`, delete removes it) and become expenses from cron when approval is off (`mitReimbRecordEnded`);
  `mitLock` / `mitUnlock` (mit_common) around timesheet decide / reopen, PO create / add items, vendor bill, receiving and
  billing parts (`mitProcLock` now refuses when busy); NinjaOne: an asset deleted in ITFlow is reported, not re-created
  (`deleted`), misplaced devices keep their link, unknown offline = NULL; Outlook keeps events of bookings older than the
  window; cron health reports never-run jobs (`cron_never_ran_<job>` in mit_settings) and the M365 certificate (renew error /
  < 14 days); M025 renumbers duplicate PO numbers and adds the unique key M015 skipped; restores run ITFlow's database
  updates before ours (`mitRestoreItflowUpdates`); the schedule modal checks the new lead first and cancels the old lead's
  invite only after the reassign; our cron mails use `mitMailFrom()` (globals are escaped by nightly_tasks);
  `scripts/install.sh` runs setup before enabling the site, keeps `https_only` with `--ssl none` (mod_remoteip for private
  proxies, `SetEnvIf X-Forwarded-Proto`).
  Audit B (26.10.73), ours: sign-ins end when the password or MFA changes - `mitSessionStamp` / `mitSessionCheck` /
  `mitSessionRestamp` (mit_common; a fingerprint of `user_password` + `user_token` in `$_SESSION['mit_session_stamp']`, owner
  user id + agent / portal in `mit_session_stamp_owner` - another account signing in re-stamps, no table), hooks in `includes/load_user_session.php`, `client/includes/check_login.php`, `functions/user.php` userSetPassword /
  userResetMfa, `agent/user/post/profile.php` (MFA on), `client/post.php` (own password). Portal Entra sign-in is tied to the
  client's Microsoft 365 organization (`mitPortalEntraTenant` / `mitPortalEntraTenantOk`, `mit_settings`
  portal_entra_tenant_<client>, first sign-in records it - delete the row to change it). Moving a ticket to another client
  (`change_client_ticket`, `mitDispatchSaveDetails`) calls `mitTicketClientMoved` (remembered recipients go, watchers who are
  not agents or the new client's contacts go). Schedule conflicts show "Another booking (a client you can't see)"
  (`mitConflictWhat`); the schedule modal lists only agents who can see the client. `mitReimbTripLocked` stops trips made by
  reimbursements being changed in Trips.
  Audit D (26.10.73), ours: reimbursement amounts are plain money up to 1,000,000 and receipts up to 20 MB, the receipts
  folder's deny file is written whenever missing; Receiving lists only POs whose every client the agent can see
  (`mitPoScopeSql`); the list-view Reset link has its own token (`mitListViewResetToken`); Outlook busy copy keeps the old
  copy when there are more than 20 pages; certificate renewal removes the old key from both apps (`m365_cert_key_id` /
  `m365_cal_cert_key_id`, best effort). Smoke: the Remind step needs `ITFLOW_SMOKE_REMIND=1`.
- Roles audit (Oct 21, 26.10.103): every feature checked against ITFlow roles (module levels, client limits, menus, API) by
  review and a sign-in crawl as admin / Technician / Accountant / no-access / view-only / full non-admin / client-limited users.
  Other agents are picked, handed or told about tickets only when their role can: `mitAgentSupportLevel` / `mitAgentCanWorkTicket`
  (functions/mit_ticket_techs.php - Support edit to work, view to be told) in round-robin and board notices, the Notify list,
  helpers, Dispatch's agents, and the parser (an agent who can't work the client's tickets mails in as a stranger). Boards by role
  also on the calendar's To schedule / Left behind lists, client tiles, client overview counts (two lines in
  `functions/client_overview.php`, one in `agent/ajax.php` calendar_unscheduled) and Dispatch's contact list; New Ticket refuses a
  hidden board; Dispatch's dialog keeps a ticket's hidden board as its choice (it moved them before). Receiving's assets / notes
  need Support edit and its stock Sales edit (`mitProcReceiveOptions`); a ticket moved to another client takes its waiting parts
  and unreceived PO lines (`mitProcTicketClientMoved`, from `mitTicketClientMoved`); PO approval notices / names only to approvers
  who can see every client on the PO (`mitPoApproversFor`); Send to procurement needs Sales; invoice links need Sales;
  QuickBooks batch totals count visible clients only; portal pay checks QuickBooks after the invoice's owner. Timesheets: the
  person picker lists only people who may be opened; a per-person approver can be any active agent; changing a reimbursement no
  longer needs Support (same rule as removing); hours changed by someone else notify the person (once an hour). Reports and the
  API are not filtered by board (documented in mit_board_roles.php). Smoke: picker test in `04-permissions.spec.js`.
- Customer Thermometer (Oct 22, 26.10.112): `functions/mit_ct.php` (ours, required from `functions.php`) - a survey per resolved ticket.
  Table M036 `mit_ct_surveys` (one per ticket - UNIQUE ticket_id, FK tickets SET NULL so a deleted ticket keeps its review -,
  blast id, temperature 1 Gold / 2 Green / 3 Yellow / 4 Red, comment, responded_at, raw response); settings `mit_settings` ct_* (API
  key via mitEncryptSecret, thermometer, recipient list, boards, start, enabled, ct_choices cache, ct_last_sample). API
  `https://app.customerthermometer.com/api.php?getMethod=...&apiKey=...` (GET): getThermometers / getRecipientLists (pickers),
  sendEmail (thermometerID, listID, emailAddress, names, companyName, custom1 tech, custom2 ticket number, custom3 subject; no
  blastID = a one-person blast whose id is returned and kept), getBlastResults / getComments (XML whose element names are not
  published - read generically by `mitCtXmlRecords` / `mitCtField`; responses matched by blast id, else custom2). Cron job
  `mit_customer_thermometer` every 5 min (`cron/mit_customer_thermometer.php`, `mitCtRun`: up to 25 sends, responses every 15 min):
  tickets resolved (or closed unresolved) on / after ct_start, on a ticked board (`mitBoardSql`), contact with email, no survey yet
  - every ticket (no per-contact limit, user's choice). Score = Happiness (Gold + Green share). UI: Admin > Integrations > Customer
  Thermometer `admin/mit_customer_thermometer.php` (handlers `admin/post/mit_customer_thermometer.php`, menu
  `admin/includes/mit_integrations_nav.php`), reviews list `agent/mit_ct_reviews.php` (Support view, client-scoped), hooks - one line
  each - `mitCtDashCard` in `agent/dashboard.php` (after mitBoardDashCard, last 90 days) and `mitCtClientCard` in
  `agent/client_overview.php` (before Technical, last 12 months + latest comments). Ticket history gets "survey sent" / "rated".
  Development mode: surveys go to the test inbox (sendEmail is a GET - the read-only gate would let it by). Test install stand-in
  `ct-mock/index.php` (not in the repo; `MIT_CT_API_BASE` in its config.php); smoke `18-customer-thermometer.spec.js`.
- Restore on a running install (Oct 22, 26.10.108): `functions/mit_backup_restore.php` (ours, required from mit_common.php) -
  Admin > Backup: Restore on a stored backup (`mitRestoreRowButton`, one line in the row actions of `admin/backup.php`) or upload a
  .zip (`admin/includes/mit_restore_card.php`, one require in ITFlow's "Restoring" card, whose "cannot be done from here" sentence
  went; the upload is stored with the backups, source "Uploaded", and `?mit_restore=<id>` opens its window). Window
  `admin/modals/mit_backup/restore.php` (contents via ITFlow's backupInspectArchive; newer database version refused; key field for
  another install's backups), handlers `admin/post/mit_backup_restore.php` (own password + typing RESTORE): `mitRestoreRun` = a Full
  backup source "Before restore" first, ITFlow's `backupRestoreArchive` (dumps the database and puts it back if the import fails),
  then the restored-from and safety rows written back into the restored `backups` table; the handler then runs
  `mitRestoreItflowUpdates` + our runner (`MIT_FROM_RESTORE` lets `admin/database_updates_mountaineerit.php` run outside the CLI),
  logs it, destroys the session and shows a result page. Under `mitLock('backup_restore')`, `ignore_user_abort`. The action name is a
  hidden input (the button is disabled while it runs - a disabled button's name is not sent). Smoke `16-backup-restore.spec.js`
  (the real round trip only with `ITFLOW_SMOKE_RESTORE=1`).
  26.10.108: in a web request ITFlow's `load_global_settings.php` has already fixed `CURRENT_DATABASE_VERSION` at the pre-restore
  version, so `mitRestoreItflowUpdates` then walks `admin/database_updates/*.php` itself (same order and version bump as ITFlow's
  runner; the CLI paths still use ITFlow's file) - a stock v26.10 backup (DB 2.7.9) restored from Admin > Backup was left at 2.7.9.
  Rows that came with the backup still Running / Pending with no file here are marked Failed. Checked with a stock v26.10 install's
  backup (its key typed in the window): updated to 2.8.9, all our updates ran, main pages clean.
  Big backups (26.10.110): up to `MIT_RESTORE_MAX_BYTES` (5 GB), sent by the card's script in pieces of `mitRestoreChunkBytes()`
  (32 MB, less under a smaller PHP limit - under Cloudflare's 100 MB request cap) - handlers `mit_backup_chunk_start` / `mit_backup_chunk` /
  `mit_backup_chunk_finish` (JSON) -> `mitRestoreChunkStart` / `Append` / `Finish` (session `mit_restore_upload`, file
  `<backups>/mit_upload_<id>.part`, pieces in order only, leftovers older than a day removed) -> `mitRestoreAdopt` (also used by the
  one-go `mitRestoreUpload`, the no-JavaScript fallback). `mitRestoreDiskProblem`: the upload needs its size + 512 MB free, a restore
  3 x the archive. Each restore writes `<backups>/mit_last_restore.json` (running / done / failed - `mitRestoreSetStatus`), shown on
  Admin > Backup (`[data-mit-last-restore]`) - a restore carries on when the browser or a proxy stops waiting (ignore_user_abort).
  Tested with a 1.2 GB backup: upload 29 s, restore about 3 min (2.5 of it the "Before restore" copy).
  Temporary folder (26.10.111): ITFlow unpacks into sys_get_temp_dir() (/tmp) - on Debian 13 a RAM disk of half the memory, so a
  1.1 GB backup failed with "Could not extract the archive: No error" (or, a little smaller, "database restored but uploads.zip could
  not be opened"). `mitRestoreUseTempDir` (start of `mitRestoreRun`) sets TMPDIR to `<backups>/mit_tmp` before PHP picks its folder
  (a php.ini `sys_temp_dir` still wins; that folder's free space is then checked too); leftovers there older than a day go. The backup
  list is put right after a failed restore too, and the handler runs the database updates after a failure (ITFlow may have restored
  the database before the uploads failed). `scripts/install.sh` sets `sys_temp_dir = /var/lib/itflow-tmp` (apache2 + cli) so ITFlow's
  own backups and restore_cli don't use /tmp either. Reproduced with Apache's TMPDIR on a 300 MB tmpfs (old code failed, new restored).
- Development mode (Oct 22, 26.10.108): `functions/mit_dev_mode.php` (ours, required from mit_common.php) - for a copy of production
  restored onto a test server. `config.php` `$config_mit_dev_mode` / `$config_mit_dev_mail_to` (not the database, so restoring
  production's backup can't switch it off; written by `mitConfigSet`, which also clears opcache). Switched in Admin > Backup's
  Development mode card (`admin/includes/mit_restore_card.php`, handler `mit_dev_mode_save` in `admin/post/mit_backup_restore.php`;
  a test inbox is required). While on: every queued mail goes to the test inbox, subject "[DEV to <real recipients>]"
  (`mitDevMailRedirect` - one guarded line in `cron/mail_queue.php` before the send; no inbox = marked sent, not sent); the mail
  parser stops (`cronJobStop` in `cron/ticket_email_parser.php`); Stripe calls throw (`mitDevBlockStripe` sets the library's HTTP
  client - one line in `includes/stripe_init.php`); custom actions do nothing (`functions/logging.php` triggerCustomAction);
  QuickBooks works only with an Intuit sandbox company connected on this server (`mitQboDevProblem`: `qbo_env` sandbox and
  `mit_settings` qbo_dev_realm = the realm, set by `mitQboConnect` in development mode - production's restored connection is refused,
  Disconnect forgets it without revoking; connecting there clears the copied batches / mappings; `mitQboHttp` passes the gate; invoice
  BillEmail and `/send?sendTo=` = the test inbox; Connect handler and `cron/mit_qbo_sync.php` check it; notice `[data-mit-qbo-dev]` on
  `admin/mit_qbo.php`); Outlook reads busy times only (dev branch in `mitOutlookSyncLocked`, archived users' events not removed in
  `cron/mit_outlook_sync.php`); the Microsoft 365 certificate is not renewed (`mitM365RenewCertificate` throws,
  `mitM365MaybeRenewCertificate` returns - a renewal would add a key to production's app). NinjaOne / Bitwarden / Ingram keep
  reading (user's choice). 26.10.109: every integration is read-only - `mitDevAllows($method, $url)` at the top of `mitHttp`
  (Microsoft 365 / Outlook / QuickBooks / Bitwarden / Ingram) and `mitNinjaHttp` refuses all but GET / HEAD and POSTs to a sign-in
  token address (`MIT_DEV_TOKEN_URL`), answering `MIT_DEV_READ_ONLY` (so hand-pressed buttons - M365 card, Outlook disconnect,
  QuickBooks disconnect's revoke - change nothing there); the one write let through is Graph sending a message already redirected
  to the test inbox (`mitDevSendingRedirected`, set around `mitM365GraphSendMail`), plus QuickBooks once `mitQboDevProblem` allows it.
  NinjaOne / Bitwarden / Ingram still sync into this server (reads); nothing done here goes back. A new integration must use one of those two
  helpers. ITFlow's own AI providers and domain checks are not ours and not gated. A red DEVELOPMENT badge in the top bar (`mitDevBadge` from `includes/mit_timers_nav.php`). Smoke
  `17-dev-mode.spec.js`.
- Review fixes (Oct 2026): `functions/mit_common.php` (ours, required first in `functions.php`) - shared
  helpers: `mitMailBody` / `mitSqlHtml` (HTML for SQL without escapeSql's strip_tags), `mitProductIsTangible`,
  module switches (`mitModuleOn`, `mitModuleRequire` for pages/modals, `mitModuleGuard` for handler files,
  `mitModulesSave`), `mitAdminSettingsPages`, `mitNavCachedCount` (side-menu counts kept a minute in the session, fresh on
  their own page - `agent/includes/mit_dispatch_nav.php`, `mit_billing_nav.php`, `mit_procurement_nav.php`), `mitAsset` (our script tags get `?v=<file time>` so browsers
  fetch them again after an update). Module switches on Admin > Settings > Modules: require of
  `admin/includes/mit_modules_fields.php` in `admin/settings_module.php` (inside ITFlow's form) and
  `mitModulesSave($_POST)` in `admin/post/settings_module.php`; saved as `mit_settings` module_procurement /
  module_timesheets / module_dispatch (default on). Procurement also needs invoicing on, Dispatch ticketing.
  Secrets: `$config_mit_secret_key` (appended to config.php on first use, like the backup key) - `mitEncryptSecret`
  writes v2 (AES-256-GCM), v1 values (key from `$installation_id`) still read and upgraded by `mitUpgradeSecrets`.
  Calendar: when the schedule modal or Dispatch gives a scheduled ticket a new lead, `mitScheduleLeadChanging`
  (one line in `agent/post/ticket.php` edit_ticket_schedule) cancels the old lead's invite; `dashboardSchedule`
  in `functions/dashboard.php` adds `mitTicketTechDashboardItems` (tickets you help on); helpers must have access
  to the ticket's client (`mitAgentCanSeeClient`). `cron/nightly_tasks.php` keeps upstream's column list plus
  `mitRecurringTicketByDayColumn()`. Restores (`setup/index.php` restore, `scripts/restore_cli.php`) run our
  database updates afterwards, so a stock ITFlow backup gets `ticket_schedule_end` etc.; setup shows a failed
  update. Our runner (`admin/database_updates_mountaineerit.php`) has `mitColumnExists` / `mitIndexExists`.
  Our features check their own update with a `...Ready()` function (one cached query per request) and fall back
  quietly; `function_exists()` guards in ITFlow files are kept on purpose so a half-merged checkout still loads.
- Performance (Oct 21, 26.10.97): measured on a copy grown to 50,000 tickets / 300,000 replies / 300,000 log rows (the
  test install keeps it as database `itflow_perf` - point config.php's `$database` at it to time pages; grant the itflow user).
  M030 adds plain `mit_*` indexes on ITFlow's tables: tickets (closed+resolved+archived for the open counts, created,
  resolved, schedule, number), ticket_attachments (ticket), ticket_history (created), logs (client+id, user+type+action+id
  for Admin > Users' last sign-in), invoices (status+archived). Admin > Users 807 -> 10 ms, dashboard 535 -> 275 ms, Dispatch
  queues 125 -> 20 ms. Our "closed in range" is `mitDispatchDoneIn()` (functions/mit_dispatch_workload.php) - written so those
  indexes are used, not COALESCE(resolved, closed). Left as ITFlow has them: the Tickets list's tab counts and
  SQL_CALC_FOUND_ROWS paging (about 0.4 s on the closed list at 50,000), Ticket Summary's reply-time sum over its range,
  and global search's LIKE over every reply (about 1 s at 300,000 replies).
- Phase 2 (after the next upstream release is merged): recheck the review findings in files
  upstream rewrote – ticket merge/move/link client checks, AI summary XSS, certificate-check
  SSRF, broken tag links, Clients/Tickets/dashboard/search performance.

## "Pull in ITFlow's updates"
1. `git remote add upstream https://github.com/itflow-org/itflow.git` (if missing);
   `git fetch upstream master --tags`.
2. Merge only official releases: `upstream/master` / the newest `vYY.MM` tag – not
   upstream `develop`. Exception (Oct 2026): upstream `develop` (unreleased 26.11 work, DB
   2.8.9) was merged into our `develop` at the user's request to get the new ticket page
   early. Until upstream releases 26.11, `develop` is ahead of any release - **do not promote
   `develop` to `MountaineerIT` before 26.11 is out** without the user explicitly accepting
   unreleased upstream code in production. When 26.11 is released, merge its tag as usual.
   Checked Oct 18 (user chose to wait for the release): upstream develop then had 47 more commits, DB 2.9.0 -> 3.2.2.
   **Upstream 2.9.4 adds `products.product_cost` and `product_vendor_id` - the same names as our M007 / M010 columns
   (same meaning).** Its single ALTER would fail with "Duplicate column" on our databases, so the merge needs a step
   that renames ours aside before 2.9.4 runs and copies the values into theirs after (then our code uses theirs).
   Also expect to redo, not just merge: quote pricing / image and invoice procurement hooks (upstream rebuilt invoices,
   quotes and recurring invoices as one shared page), and to decide ours-vs-theirs for product cost / stock / vendors
   (procurement), mileage pay-back (reimbursements) and Unbilled Time (next to Unbilled Parts). A trial merge then
   had 24 conflicting files. Upstream also rebuilt parts of its API (roles, API keys): re-apply `mitProductApiColumns()` in
   `api/v1/products/read.php` and recheck the API against our features (our cost never in it).
3. Summarise for the user: version, notable features/fixes, new files in
   `admin/database_updates/` (DB version change), anything touching our deviations above.
4. On `develop`, `git merge <tag>`, resolve conflicts keeping our deviations, `php -l`
   changed PHP files, run `scripts/mit_portability_check.php` and the smoke tests (`tests/smoke`)
   on the test install after the database update, then ask the user before pushing to `develop`.

## "Promote to production"
Open a PR `develop` → `MountaineerIT` listing what's included and any DB updates, plus the
result of the stock-ITFlow portability test (see Rules) and of the smoke tests. Ask the user before merging.
