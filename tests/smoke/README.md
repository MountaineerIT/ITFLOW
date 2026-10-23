# Smoke tests (Mountaineer IT build)

A quick check, in a real browser, that the app still works after a change - above all after
merging an ITFlow release. About a minute. **Run it against the beta / test install, never
production**: it adds and deletes a client and a ticket, and changes the test logins' own
saved views.

## What it checks

| File | What |
| --- | --- |
| `01-pages.spec.js` | Every page in the agent, client, Admin and Account menus, plus one ticket / invoice / quote / project / PO / timesheet, opens with no PHP or JavaScript error. The menus are read from the pages, so pages a release adds are covered too. |
| `02-ticket-workflow.spec.js` | Through the real forms: add a client, open a ticket, add an internal note with time, book it for tomorrow 10:00-11:00, find it on the calendar (with its end time) and Dispatch. Deletes what it made, and any "Smoke test client ..." an earlier failed run left. No customer email is sent. |
| `03-our-features.spec.js` | Remembered Tickets view + Reset, board and filters, timesheet, payroll report, Dispatch, calendar, version / release notes, holidays, pop-up links to other sites refused, security headers and API keys only in a header, the Microsoft 365 calendar-app offer, the modern look (on by default, light and dark, a client's count tiles, quotes / invoices as a document, follows the company theme colour; Classic turns it off), the sign-in page's backdrop. The "Remind" step emails a real person, so it only runs with `ITFLOW_SMOKE_REMIND=1` (when everyone on the install is a test login). |
| `04-permissions.spec.js` | A Technician can work tickets but cannot open Admin pages or save an admin setting (tries the real Procurement settings form and checks, as admin, that nothing changed). Also: changing your password ends your other sign-ins (signs the technician in again afterwards). Skipped without a technician login. |
| `05-california.spec.js` | As the technician (on California rules): work times and the meal check, split shifts, refused blocks (same start and end, out of order), the signing window, a remote time zone's "today", and the default charge code for time not on a code. Clears what it types, also when a step fails. |
| `06-flows.spec.js` | Our features end to end through their real forms: procurement (item to order -> PO -> approve -> order -> receive), a ticket's parts, mileage and helper agents, quote pricing from our cost (never shown to the customer), a recurring ticket on "the third Wednesday", and a ticket put in a project and taken out. Deletes what it makes (a received PO can't be - its line is named "Smoke test ..."). |
| `07-boards.spec.js` | Service boards: Admin > Settings > Boards, a client routing rule placing a new ticket on its board, Escalate (reason required, kept as an internal note), the Tickets board switcher and Dispatch's queue by board; round-robin auto-assign handing out a ticket moved in unassigned, the dashboard's board picker (remembered) and the Board Performance report with its escalations export. Removes its client, ticket and rule and puts the board's settings back, also when a step fails. |
| `08-calendar.spec.js` | The calendar's To schedule and Left behind lists: both follow the Whose picker (Everyone = everyone's, with the agent named), a ticket booked three days ago and still open is left behind, marked on the calendar and in Dispatch's Left behind tab, and booking it again clears it; Dispatch shows an agent's meeting beside their bookings (not movable), counts it as a clash, and bookings say onsite / remote; the week view keeps the agent names at the left when the days scroll sideways. Makes and deletes its own client, ticket and meeting and puts the Whose choice back. |
| `22-ninjaone-sync.spec.js` | NinjaOne "Run sync now" against the stand-in, made slow: another page opens while it runs; an organization not mapped yet is a note on the report, not an error; no warranty lookup for its device. Sets the stand-in's organization 3 to not mapped and puts it back. Skipped without the stand-in. |
| `21-brand-logo.spec.js` | Company Details' "The logo shows the company name": on, the side menu shows the logo on its own (no name); off, the name is back. Uploads a small logo when there is none (removed again) and puts the switch back. |
| `20-event-agents.spec.js` | Calendar entries assigned to agents: New Event from another agent's calendar starts assigned to them ("Assigned to" beside the title, the Calendar picker only with several calendars, the rest under "Invite"; the quick look lists agents only under Assigned to); Dispatch has it on their column with their name and colour dot; the quick look hands it to the signed-in agent (and it leaves the other column); Dispatch's move takes it to another agent at another time. Needs a second agent; deletes its entry and puts the calendar's Whose back. |
| `09-note-times.spec.js` | Times on ticket notes: a note with time worked is not sent without its times, typed times set the length and show on the note, editing only the length moves the start; the timesheet lists the note with its times on the day it was worked and flags two that overlap, then puts one right from the day panel (a bad time refused, "after the one before", Enter to save) and checks the notes on the ticket followed; lost time shows with the codes; a Contractor is never under California rules (even switched On). Makes and deletes its own client and ticket and puts the person's pay type back. |
| `10-qbo.spec.js` | QuickBooks Online against the test install's stand-in for Intuit (never a real company): connect, match items, approve an invoice (a change after approving sends it back for approval), batch it and send it (it locks - an added line is refused, Add Payment is gone), void it in QuickBooks and take it out (back to Draft), send it again, and payments made in QuickBooks coming back (Partial, then Paid; the synced payment can't be deleted by hand). Makes and deletes its own client, invoice and batches and disconnects again. Skipped unless the stand-in is installed: `qbo-mock/index.php` in the test install's web root (not in the repo) and `MIT_QBO_AUTH_URL` / `MIT_QBO_TOKEN_URL` / `MIT_QBO_REVOKE_URL` / `MIT_QBO_API_BASE` pointing at it in its config.php - never on a real install. |
| `11-usage.spec.js` | Usage billing: a recurring invoice with a line counted from contacts with a tag and one reported through the API (a temporary API key made with Admin's own form; a negative count is refused and the read endpoint lists the line), Force -> the quantities follow the counts on the snapshot day (set to today for the run, put back after; skipped on the 29th-31st) and the invoice stays a Draft, Usage Review flags the big change, an override keeps its reason, approve, and the client's Usage card takes a typed count. Deletes its client, tag, API key, recurring invoice and invoice. |
| `13-board-roles.spec.js` | Service boards by role (needs the technician login): limits the Technician's role to one board in Admin > Roles > Edit ("Seen by" on Admin > Settings > Boards); as the technician a ticket on another board is not in the Tickets list, the board switcher, global search or the Dispatch queue, while one assigned to them and one they escalated there are; New Ticket doesn't offer the hidden board, Escalate does, and a direct link still opens the ticket; the admin still sees it. Puts the role's setting back and removes its client and tickets, also when a step fails. |
| `14-dispatch-role.spec.js` | The Dispatch permission (needs the technician login): with Dispatch View the technician's board is marked View only, its queue loads and assigning is refused (403); with no Dispatch the page, its menu entry and its requests are refused. Puts the role's Dispatch level back, also when a step fails. |
| `15-ticket-picks.spec.js` | The ticket page's Board, SLA and Category change in place (drop-down pickers, no window): picks another category, SLA plan and board on a new ticket and checks each is shown and saved. Removes its client and ticket, also when a step fails. |
| `16-backup-restore.spec.js` | Restore from Admin > Backup: a stored backup's Restore window shows what is in it, a wrong password changes nothing, a file that is not a backup is refused; a stored backup sent back in 1 MB pieces (the big-file upload) is put back together, listed and readable, then deleted. With `ITFLOW_SMOKE_RESTORE=1` only (it really replaces the test database): restores the newest backup, then the "Before restore" copy it made, so the install ends as it started, and signs in again for the later tests. |
| `17-dev-mode.spec.js` | Development mode (Admin > Backup): refused without a test inbox; on shows the DEVELOPMENT badge with the inbox and NinjaOne can still be read (integrations are read-only, not off); QuickBooks refuses a production company and works with a sandbox one connected there (QuickBooks stand-in); off removes the badge. Always switched off again at the end. |
| `18-customer-thermometer.spec.js` | Customer Thermometer against the test install's stand-in (`ct-mock/index.php`, `MIT_CT_API_BASE`): a wrong key refused, the right one reads thermometers and lists; a resolved ticket gets no survey before the start date or from an unticked board, then exactly one (to the contact, with tech / ticket number / subject); a Gold response with a comment shows on the client overview, dashboard, reviews list and ticket history. Switched off and cleaned up at the end; skipped without the stand-in. |
| `19-own-employment.spec.js` | An admin changes their own employment (pay type) in Account > Preferences and it is saved; put back at the end. (A technician not getting the field is checked in 04.) |
| `12-connectors.spec.js` | Usage connectors against the test install's stand-ins (never real accounts): NinjaOne device counts, Bitwarden (a wrong key refused, the right one linked - 3 users, 2 confirmed), Ingram Micro (login, read customers, match by name, subscriptions counted per part number) and a recurring line picking the Ingram product from the list and billing 12 + 3 = 15. Skipped unless the stand-in is installed: `usage-mock/index.php` in the test install's web root (not in the repo) with `MIT_BITWARDEN_IDENTITY_URL` / `MIT_BITWARDEN_API_URL` / `MIT_INGRAM_API_BASE` pointing at it in its config.php - never on a real install. Deletes its client (and its links and counts) and puts the settings back. |
| `99-php-log.spec.js` | No PHP warning or error was written to the site's error log during the run. Production-style servers don't show PHP warnings on the page, so this is the only way to see them. Skipped unless `ITFLOW_PHP_LOG` is set (i.e. when run on the server itself). |

## Set up (once)

Needs Node 18 or newer.

```bash
cd tests/smoke
npm ci
npx playwright install chromium     # the browser (skip where Chromium is already provided)
cp .env.example .env                # then fill it in
```

On the test install, make two logins just for this, **without 2FA**:
- an admin (e.g. `smoke-admin@...`),
- a Technician-role user (optional, for `04-permissions`), with access to all clients.

`tests/smoke/.htaccess` stops Apache serving these files if the
checkout is the web root. `.env`, `node_modules/`, `.auth/` and the reports are git-ignored.

## Run

```bash
cd tests/smoke
npx playwright test                 # everything
npx playwright test 02              # one file
npx playwright show-report report   # the HTML report: screenshots and a trace of anything that failed
```

## When to run

- After every upstream merge (with the database update done) - before pushing to `develop`.
- Before every "Promote to production".
- After any change to a page or hook in ITFlow's own files.

## Adding a test

Use the helpers in `helpers.js`: `visit()` (open + check for errors), `openModal()` (an ITFlow ajax
modal), `fillForm()` (by field name - copes with select2 and TinyMCE), `submit()` (says which field
blocked the form), `action()` (a GET action link with the CSRF token), `getJson()`. Name anything you
create with `smokeName()` and delete it in `afterAll`. Check a new test fails when the thing it
guards is broken.
