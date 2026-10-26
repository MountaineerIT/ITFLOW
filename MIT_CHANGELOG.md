# Mountaineer IT build of ITFlow - release notes

Version = ITFlow's release + our iteration (26.10.1, 26.10.2 ...). It goes up with every push to `develop` and starts
again at .1 when an ITFlow release is merged. Newest first. Shown under Admin > Update. (ITFlow's own release notes are
CHANGELOG.md.) Versions up to 26.10.62 were numbered afterwards, one per push to develop since 15 October 2026.

## 26.10.152 - 2026-10-26
- Full functional test of every feature after the ITFlow merge (each area used end to end on its own copy of the test install, with the data underneath checked). Fixes from it:
- Tickets: a warning about a note's time (for example "today is on an approved timesheet") now shows beside "Reply added" - it was overwritten. The "Assigned to you" bell notification follows the Admin > Notifications switch on New ticket, Edit ticket and bulk assign too. Names with an apostrophe show without a backslash in no-response emails, internal-note notices and our admin logs. "Resolve - no response" also emails the watchers when the contact has no address. Customer Thermometer no longer surveys a ticket that was merged into another. The SLA picker refuses an SLA on a project ticket with a reason instead of seeming to set it. Moving a ticket to another client keeps its notes' time on timesheets (ITFlow turns the public notes internal).
- Scheduling: who is emailed for a booking is remembered, so moving or cancelling it later emails the same people. Dispatch checks a booking (agent, access to the client, open ticket) before saving the dialog's changes, so a refused booking changes nothing. Bookings that started the day before the calendar's range (overnight or long ones) now show and count as clashes, helpers' too. A helper made lead gets the ticket's own invite even right after a helper invite. The booking address falls back to the contact's location.
- Timesheets: changing back to an earlier time zone no longer pushes the rest of that day's ticket time onto the next day. With approval off, a trip or expense paid personally can't be dated into a pay period payroll already paid. Turned-off module pages no longer warn in the error log.
- Procurement: converting a quote that has two lines with the same name now sends the right line to procurement. Taking a stock part off a ticket (or changing / deleting the ticket) puts back only what was really taken - a part added before the product kept a stock count no longer adds stock.
- Checks: 23 new smoke test files and 26 scenario checks from the test (tickets, boards, scheduling, Dispatch, timesheets / payroll, quotes, procurement, QuickBooks, usage billing).

## 26.10.151 - 2026-10-26
- Timesheets: short paid rest breaks are now part of the hours people log - nothing is added on top, and they no longer make 8 logged hours count as overtime. Days already approved keep what they were approved with. The "Paid breaks" minutes setting in Admin > Timesheets (and per person) is gone.
- Timesheets: every day now asks "I could not take a rest break this day", not only California days. On California rules it still owes an hour of rest premium; otherwise it is a record, shown on the day ("Rest break missed") and in the payroll detail export.

## 26.10.150 - 2026-10-26
- Deep dive of timesheets / payroll, projects / procurement and invoices / QuickBooks against ITFlow 26.11.
- Procurement: a quote line ticked "Send to procurement" for a stock-tracked product now converts even with none on the shelf, and takes no stock (ITFlow refused the whole quote before). Receiving puts back only stock the invoice still holds, so a cancelled invoice is no longer counted back twice. Partly received lines keep their product; quantities keep decimals.
- Procurement: a "to order" part whose order was cancelled is no longer billable, and a received part can be taken off a ticket. Items waiting for a cancelled or deleted invoice are cancelled by the daily tidy. An item can't be raised past its invoice line, and PO edits update the item. A ticket moved to another client drops the old client's project and asset from its parts. Changing only a part's price writes no stock moves. "Record vendor bill" goes through ITFlow's own expense rules (an account is required) and links the PO's received stock to the bill.
- Projects: finished project tickets show under Closed and All, and project tickets under Mine, on the Tickets page.
- QuickBooks: taking an invoice out with "leave it in QuickBooks" keeps it sent and marks it "Already in QuickBooks", so it is not sent there a second time. Each send first looks for an invoice an earlier try already made. Payments coming back are added as new payments, and a reconciled or deposited payment is never changed or deleted - a warning shows instead. Taking an invoice out waits for a running payment sync. A card payment taken in ITFlow on a batched invoice now warns to record it in QuickBooks too, and the portal's pay page refuses batched invoices. Invoices marked "Already in QuickBooks" get no ITFlow reminders or late fees. Emailing a batched invoice stays blocked after a Disconnect. Invoices in another currency are refused with a reason. Tax before or after the discount follows ITFlow's setting. Old unpaid invoices keep syncing after a year.
- Usage billing: a line already billed by ITFlow's seat count can't also be a usage line.
- Timesheets: time logged while today is on an approved timesheet is no longer silently lost - the note is saved without it and the agent is told. People who left still show with their final ticket time, trips and expenses. Their unaccounted time stops after they leave. Their last period is paid and accrued when approval is off.
- Timesheets: a charge code with hours on approved timesheets can't change kind or paid. The pay period can't change while timesheets wait for approval. Expenses paid personally follow the same day locks as trips. A failed payroll close is retried. Closure days changed in ITFlow's Admin > SLA now update open timesheets. Day saves wait for a running submit or approval. The API can't delete a client whose ticket time is on a submitted timesheet. Turning a note internal is checked like a time change. Ticket subjects of clients you can't see are hidden in the mileage and purchases lists. Paid break codes show in the day total and are not paid on top on California days. The payroll detail CSV has a "Not clocked" row, so it adds up.
- Checks: new scenario checks (`tests/scenarios`, run by `scripts/mit_verify.sh`). The hook check ignores the install's own config.php.

## 26.10.149 - 2026-10-26
- Timesheets team list: the columns now add up to Total. For people on California rules, Total is the start / end times they typed, so ticket time and code hours outside those times (or on a day with no times typed) now show in a "Not clocked" column and are taken off. Before, they were left in Billable / Lost but not in Total, so the row didn't add up and Billable % could go over 100%.
- Admin & work also counts paid breaks and clocked time that is on no code, which were in Total without a column.
- Billable % is now out of every hour on the sheet, so it never goes over 100%. The same applies to the summary line, the person's timesheet and the payroll report.
- Payroll report: "Not on a code" and "Not clocked" columns for California people. Someone with ticket time but no clocked times is no longer left out.

## 26.10.148 - 2026-10-26
- Calendar: bookings in the "7 days" view show their full detail again (time, who, ticket, client), as in Week - they were cut to one line.
- Calendar: the calendar is taller - it fills the screen down to the Left behind list, which shows one row of tickets (scroll it for more) with its hint in the header line.

## 26.10.147 - 2026-10-26
- Calendar: new "7 days" view beside Week - seven days in a row starting on any day, and the arrows move it one day at a time. Step it so Sunday and Monday sit side by side and a booking can be dragged across the weekend without changing weeks. It is remembered like the other views.
- Calendar: the Left behind list now sits under the calendar, as wide as it, with its tickets side by side - the calendar gets a little shorter while there are any, so you can drag one up onto a day without scrolling. With nothing left behind it is a single line.

## 26.10.146 - 2026-10-26
- Our cron jobs now wait quietly (shown as Stopped) in the minutes after an install or a restore, before our database updates have run, instead of failing with "Table 'mit_settings' doesn't exist". The daily Consistency Check fails if our updates still haven't run.

## 26.10.145 - 2026-10-26
- The stock-ITFlow portability check now tells our own columns apart from ones neither ITFlow nor our updates made (added by hand or by another tool) - such a required column is reported as "Unknown column" and also breaks ITFlow's own forms today.

## 26.10.144 - 2026-10-26
- New daily Consistency Check (Admin > Maintenance > Cron): checks that our data still agrees with ITFlow's own records - payroll payments against their timesheets and expenses, QuickBooks batches against ITFlow payments, billed ticket parts, usage-billed invoice lines, boards, Outlook links, database updates. When something disagrees the job fails, so Cron Watch emails the admins with what it found. Read only - it changes nothing.
- For updates: a hook check that fails when an ITFlow update drops one of our hooks into its files, and one command (scripts/mit_verify.sh) that runs every check - syntax, hooks, consistency, stock-ITFlow portability and the smoke tests - before anything is promoted.

## 26.10.143 - 2026-10-26
- Mileage and purchases, checked end to end against ITFlow's own trips, expenses and pay-back screens. The timesheet, the payroll report and approval now all work from one list, so what the report says is owed is exactly what approval marks paid.
- Fix: something already paid back in ITFlow (Trips > Pay Mileage, Expenses > To reimburse) was still counted in the payroll total and could be paid twice. It now shows as "Paid back in ITFlow" (struck through on the timesheet, in its own "Paid in ITFlow" column on the report) and is left out of Reimburse.
- Expenses an accountant enters in Expenses as paid personally by a tech now go on that tech's timesheet and payroll too (before, approval marked them paid without them ever being in the report).
- Fix: an approver who can't see every client (or the hourly job, with approval off) paid only part of a period. Approval now pays all of it.
- Approving is refused, with a clear message, when there is something to pay but no "Payroll pays from account" or mileage rate - instead of half-paying it. Reopening is refused when the payroll payment is on a reconciled statement, and now removes only what approval made (a mileage expense an accountant made stays).
- ITFlow now refuses to change, delete or undo a trip, expense or reimbursement that payroll paid (reopen the timesheet instead), and to add, move or change own-vehicle trips on a day of a submitted or approved timesheet.
- One mileage rate: saving it in Admin > Timesheets also sets ITFlow's "Paid back a mile" - without it, Log a trip has no "In their own vehicle" switch, so trips were never paid. The page warns while it or the payroll account is missing.
- With approval off, each ended period is paid through payroll once (it re-ran every hour).
- Labels match ITFlow's: "Trips > To pay back", "Expenses > To reimburse"; the timesheet box is "Mileage and purchases"; a purchase's expense reads "Paid by <name>" (not "Reimbursed to") until it is paid; the Trips & purchases box counts every line; mileage logged on a ticket is listed once there and can be changed from it.
- Purchases always become ITFlow expenses when the timesheet is approved (the category is optional now - "Don't record them" left them unpaid).

## 26.10.142 - 2026-10-25
- Tickets: trips and purchases share one box, "Trips & purchases" (ITFlow's Trips box) - with "Add a purchase" beside "Log a trip". The separate Reimbursements box is gone when nothing has been bought, and shows on its own only on a ticket without a Trips box.

## 26.10.141 - 2026-10-25
- Mileage and purchases a tech paid for themselves now go on payroll (Admin > Timesheets > Reimbursements, "Mileage and purchases are paid back: With payroll" - the default; "Through ITFlow" keeps the old way). The payroll report has the dollar amounts (Mileage, Purchases, Reimburse) to type into your payroll.
- Approving a timesheet marks that period's mileage (own-vehicle trips) and purchases paid through payroll, so they no longer wait in Trips > To pay back or Expenses > Reimburse; reopening the timesheet puts them back. Each entry says "Goes to payroll" or "Paid through payroll <date>".
- New: "Add a purchase" in the ticket's ⋮ menu, beside ITFlow's "Log a trip".
- Fix: Admin > Timesheets didn't save the reimbursement category and account while ITFlow's mileage rate was set.

## 26.10.140 - 2026-10-25
- Full check after the ITFlow 26.11 merge: every addition reviewed against the new code, and every page and window opened as nine different users (admin, technician, accountant, view-only, client-limited and more) plus the client portal on a computer and a phone - about 3,900 pages with no errors. Fixes:
- Fix: ITFlow's new "View portal as them" on a contact signed you out of ITFlow.
- Fix: invoices in a QuickBooks batch could still get payments from ITFlow's new bank-statement import, lines from Unbilled Time, or new prices from Products > Update prices.
- Fix: procurement now follows ITFlow's own stock tracking - only products set to track stock move stock, through ITFlow's stock history; a quote line ordered for the client no longer leaves the shelf count short; received assets show on the invoice's Hardware box.
- Fix: usage-billed and ticket-parts invoices work tax out with ITFlow's new tax rules (multi-part rates, discount before tax).
- Fix: the ticket page layout knew nothing of ITFlow's new Fill-ins and Trips boxes and the AI suggestions banner, which could push the conversation above the reply box.
- Fix: email replies now also go to client contacts who asked ITFlow for every update, and each person is greeted by their own name.
- Fix: changing a calendar entry's agents, or moving it on Dispatch, now needs the same rights as ITFlow's Edit event; moving a ticket to another client from Dispatch takes its trips along.
- Fix: no double "Ticket Scheduled" email to a contact who asked for every update; the guest page's "Add to calendar" keeps the booking's real length.
- Paying people back: through ITFlow only (your choice). Mileage waits in Trips > To pay back and purchases in Expenses > Reimburse; the payroll report shows them as "Paid back in ITFlow" (not payroll money), each entry says where it is (Waiting in Trips, Waiting in Expenses > Reimburse, Paid back on a date), and driving is logged with ITFlow's own "Log a trip" on the ticket - those trips still count on the timesheet and in the report.
- Development mode now also stops ITFlow's new log forwarding (so a test copy can't send to production's log server) and its AI triage of new tickets (which would use production's AI key).
- The quote profit box shows one-time and monthly / quarterly / yearly lines separately; "send to procurement" lists one-time lines only.
- Admin > Roles explains what each level of Procurement, Timesheets and Dispatch allows; Admin > Modules and Company say what changed when only our settings changed; the mileage rate field follows ITFlow's rate.
- Faster: database update M041 adds indexes for a count ITFlow 26.11 runs on every page and for the audit log's filters (measured on a 50,000-ticket copy: Unbilled Time 241 to 118 ms, Audit logs 1.5 to 1.2 s, most pages 10-40% quicker).

## 26.10.139 - 2026-10-25
- Fix: on MariaDB 11.8 (Debian 13) the dashboard, Products and quote pages were blank after the ITFlow 26.11 merge ("Illegal mix of collations"). Some of ITFlow's tables (quote_items, recurring_invoice_items, task_approvals, asset_tags) had been created with the server's own collation instead of ITFlow's utf8mb4_general_ci, and ITFlow's new "lines at an old price" count joins them with the others. The database updater now converts any such table to ITFlow's collation each time it runs (tables with a deliberate binary column are left and logged). Run `php scripts/update_cli.php --update_db` once to fix an install now.

## 26.10.138 - 2026-10-25
- Ticket page: a long subject no longer squeezes the buttons beside it - Resolve, its "no response" arrow and the ⋮ menu stay on one line and the subject wraps instead (they used to stack, with Resolve on two lines).
- Quotes: our cost, Price by (markup / margin / set price) and % are back on the quote's add-a-line row (ITFlow 26.11's new quote page had them only in the Edit Line window). The price is worked out as you type, the profit shows under the row, and picking a product fills in its cost. Staff only - customers never see them.

## 26.10.137 - 2026-10-25
- Fix: updating with scripts/update_cli.php (or the Update page) from before 26.10.135 stopped at ITFlow's database update 2.9.4 ("Duplicate column name 'product_cost'"), leaving pages blank - the updater ran with the code from before the download, which did not have our step yet. The step now lives in its own file that the updater loads itself. An install stuck at 2.9.3 is put right by running `php scripts/update_cli.php --update_db`.

## 26.10.136 - 2026-10-25
- Fix: Admin > Update's check stopped with "Data too long for column 'config_update_pending_commits'" when many commits with long descriptions were waiting (the 26.11 merge brought 99). It now keeps the newest 100, each description cut to 200 characters.

## 26.10.135 - 2026-10-25
- ITFlow's latest work (its 26.11 public beta, database 3.4.7) merged in. New from ITFlow: contracts (SLA, rates and terms for a client, prepaid hours), one shared page for invoices, quotes and recurring invoices with lines edited in place and a new PDF look, products with cost, vendor and stock tracking, trips paid back for mileage and expenses paid personally, bank statement import and account reconciling, rebuilt settings pages, ticket statuses, payment methods and providers, custom links and AI providers, portal invites and self sign-up, ticket emails that carry the conversation, a "Get started" guide for new installs, and a security sweep.
- Products: cost and vendor are now ITFlow's own fields - your values were copied over by the update. The product window keeps our picture; its own markup % setting is gone (quote lines start with the company default).
- Quotes: our cost, markup / margin and picture for a line are in its Edit window (⋮ > Edit) on the new page; staff see the cost under the line. Quote PDFs keep the pictures.
- Reimbursements are paid back through ITFlow: mileage logged on a ticket waits under Trips > To pay back (at ITFlow's mileage rate), and approved purchases wait under Expenses > Reimburse as paid personally. An entry already paid back can no longer be changed.
- QuickBooks locks, usage billing, procurement, ticket emails (resolved wording, no response, helpers' copies, booking emails) and notification switches work on ITFlow's rebuilt pages.

## 26.10.134 - 2026-10-25
- Company Details has a second logo, "Logo for ITFlow's screens": the side menu and the sign-in page show it, while the company logo stays on quotes, invoices, purchase orders and their PDFs. Leave it empty to use the company logo everywhere, as before.
- New switch "No light tile behind the logo" (Company Details): the side menu and sign-in page show the logo without the white box behind it, for a logo drawn for a dark background.

## 26.10.133 - 2026-10-25
- MSP Align takes the place of "Needs attention": on a client's overview (for clients MSP Align has) and on the dashboard. Switch it off on Admin > Integrations > MSP Align to get ITFlow's Needs attention back.
- The client overview's MSP Align section has a Past SLA tile: the client's open tickets past SLA, opening ITFlow's ticket list.
- The dashboard shows MSP Align across your clients: open tickets past SLA (following the dashboard's board), clients at risk with the average health score, devices to act on, and meetings in the next 7 days.

## 26.10.132 - 2026-10-25
- MSP Align: the client overview's quick links include Backups, opening the client's backup review in MSP Align (after Compliance).

## 26.10.131 - 2026-10-25
- New: MSP Align, read only (Admin > Integrations > MSP Align). Type its address and an API key made in MSP Align under Settings > API (read scopes only). ITFlow then copies from MSP Align every hour, or on "Sync now". It only ever reads, so it can't change anything in MSP Align.
- Clients, assets and licenses are matched by the ITFlow ids MSP Align already keeps, so there is nothing to map. If linked clients have different names in the two programs, the admin page lists them, in case MSP Align was filled from another ITFlow.
- Client overview: a new MSP Align section with the health score and band, alignment score, devices to act on, and the next meeting. It has quick links to the client's MSP Align Overview, Roadmap, Budget, Devices, Licenses, Compliance, Alignment, Meetings and Reports pages.
- On the client's other pages, the client header has a small "MSP Align" link.
- Assets: the asset list shows MSP Align's lifecycle status (Replace now, OS unsupported, Out of warranty, Healthy …), with warranty and replacement dates on hover. The asset page and window have an "MSP Align" button showing the status.
- Licenses list: each license MSP Align has shows its renewal or contract-end date and its yearly cost, linking to the client's licenses in MSP Align.
- Database update M039 (three new tables).
- Smoke tests: the portal-on-a-phone test now signs in from its own empty browser. It had signed in on top of the admin's shared test session, which signed the admin out for any test after it.

## 26.10.130 - 2026-10-25
- QuickBooks Online: invoices you entered in QuickBooks by hand can be marked "Already in QuickBooks", so they stop waiting for approval or a batch. You can mark them in three places:
  - the QuickBooks strip on the invoice;
  - Billing > Invoice Approvals: tick them, then "Already in QuickBooks";
  - Billing > QuickBooks Batches, under "Add to batch".
- A marked invoice leaves the approval and batch lists and side-menu counts, can't be approved or batched, and shows "QBO (by hand)" in the invoice list.
- A marked invoice isn't linked to the QuickBooks invoice, so ITFlow doesn't bring back its payments. Record them in ITFlow as usual.
- Invoice Approvals has a new "Already in QuickBooks" tab. From there, or with Undo on the invoice, you can put an invoice back in the queue.
- Database update M038 (a new table, `mit_qbo_manual`).

## 26.10.129 - 2026-10-25
- Customer happiness (the responses page opened from the dashboard and client cards) has a client picker beside its heading to switch clients, or go back to All Clients. It lists the clients with survey responses. The heading now says "last 12 months" to match its button.
- A client's overview still shows its 3 newest survey comments, and when there are more it adds an "All N comments" link to that client's responses for the last 12 months. The comments shown are from those 12 months too, like the score above them.

## 26.10.128 - 2026-10-24
- Full check of the whole build: every agent, admin, account, report, guest, client portal and sign-in page opened as an administrator and as a technician, on a desktop and on a phone. No PHP errors or browser errors, and no failed requests apart from the ones fixed below. The full smoke suite and the portability check pass.
- The customer's quote and invoice pages (the links in emails) no longer ask for the logo folder when no company logo is set. That request was refused and logged as an error on every view.
- The client portal's lists (assets, invoices, statement, activity, all tickets ...) scroll sideways inside their box on a phone. Before, a wide table made the whole page wider than the screen.
- The customer's invoice page and the portal's invoice pages no longer log PHP warnings when Stripe is not set up.
- The smoke tests' last check also fails when the server refused a file a page asked for, which is how the logo problem above had gone unnoticed.
- Smoke tests: Dispatch workload no longer skips when the admin is on a service board, the note-times timesheet test no longer skips on a Sunday, and the ticket SLA pick waits for its page reload (it failed now and then). New test for the portal on a phone.
- Admin > Integrations > Customer Thermometer's survey list and the Customer reviews list scroll sideways on a phone instead of making the whole page wider than the screen.

## 26.10.127 - 2026-10-24
- Admin > Settings > Notifications has new rows, with app, tech email and client email switches. All are on to start, so nothing changes until you untick one:
  - Ticket scheduled, moved or cancelled: who is emailed, set separately for remote and onsite visits. Choices are the assigned tech, the ticket contact, the watchers, the site's contact (the location's contact), the client's primary contact, and extra addresses such as a front desk. These are only defaults: the Schedule window and Dispatch's schedule dialog show them as ticks you can change for one booking. Switching a booking between Remote and Onsite switches the ticks to that visit's defaults. Cancellations go to the same people as the booking's type of visit.
  - Ticket or recurring ticket assigned to a tech: the bell notice and the email.
  - Ticket landed on or moved to a service board: the bell notice and the email to board members.
  - Copies of the client's replies to additional techs.
  - Purchase order waiting for approval: the bell notice and the email.
  - Timesheet notices: the bell notice and the email.
- A line at the bottom says where the other notification settings live.

## 26.10.126 - 2026-10-24
- Tickets: Resolve now has a small arrow beside it with "Resolve - no response...", for a client who never answered after several tries. It opens your standard message filled in for the ticket (first name, ticket number, subject...), which you can change before it goes. Sending puts it on the ticket as a public note, emails it to the contact and watchers in place of the usual "Ticket resolved" email, and resolves the ticket. It then closes on its own as usual, and a reply from the client before that reopens it.
- No Customer Thermometer survey is sent for a ticket resolved this way. If it is reopened and later resolved normally, it is surveyed as usual.
- The standard message is set under Admin > Settings > Ticket ("Messages when resolving"), with placeholders [First Name], [Contact], [Client], [Ticket], [Subject], [Agent] and [Date].
- The usual "Ticket resolved" email can now be worded your own way on the same card. It starts as ITFlow's wording and adds [Status], [Ticket Link] (view or re-open) and [Note] (what the agent wrote when resolving, left out when nothing was). Empty the box to go back to ITFlow's email.

## 26.10.125 - 2026-10-23
- Tickets list: with tickets ticked, the Bulk Action, Reset, List and Kanban buttons stay one line high. Reset, List and Kanban show as icons only (hover for the name), and on a narrower screen the buttons move to their own line on the right instead of covering the filters.

## 26.10.124 - 2026-10-23
- Modern look: tick boxes and round option buttons have a darker border, so they are easy to see on white and on shaded rows (light and dark mode). They turn the accent colour when the mouse is over them.

## 26.10.123 - 2026-10-23
- Modern look: lists are easier to read row by row. There is a thin line between rows and the alternate-row shading is a little stronger, in light and dark mode. This covers the Tickets list and every other table.

## 26.10.122 - 2026-10-23
- NinjaOne: when the safety brake stops a sync, the run's report is kept. It lists each asset the sync wanted to make and why it matched none you already have: no asset in the client has the device's NinjaOne ID or serial number. Assets with the same name are shown with the serial and NinjaOne ID they carry instead. The report also has a table by organization.
- NinjaOne: new "Preview" button. It works out what a sync would make, update or leave alone, and writes nothing.
- NinjaOne: each device is counted once, even if NinjaOne returns it on two pages.

## 26.10.121 - 2026-10-23
- NinjaOne: "Run sync now" no longer locks you out of ITFlow while it runs. Your other pages and tabs keep working, because the sync stops holding your session. The QuickBooks "Sync now", usage connector "Run now" and Customer Thermometer "Run now" buttons got the same fix.
- NinjaOne: warranties are looked up only for devices in mapped organizations, so a sync with only a few organizations mapped is much quicker. Before, it looked up up to 150 devices a run, mapped or not.
- NinjaOne: organizations not mapped yet are a note on the sync report (with the list folded away), not a "Needs attention" error. Mapping a few to start with is normal.

## 26.10.120 - 2026-10-23
- Admin > Settings > Company Details has "The logo shows the company name". When it's on, the side menu shows the logo on its own, across the room the name took, instead of the logo with the company name beside it. Off (the default) keeps both.
- Fixed: uploading a company logo when there wasn't one before tried to delete the logo folder (a warning in the log, nothing lost).

## 26.10.119 - 2026-10-23
- Calendar: in month view the agent's first name no longer pushes a booking's title off its one-line item; the agent's colour dot and the hover text say who. In the list view the agent's dot now comes before their name.
- Checked the whole calendar with one item from every source (scheduled tickets, helpers, project bookings and dates, service reviews, recurring tickets, renewals, invoices due, Outlook busy times, holidays, entries on three calendars, including a repeating one) in every view, for Everyone, one agent, a client's calendar and a technician. Also checked each item's window, moving and resizing, and the subscription feeds. Everything else was working.

## 26.10.118 - 2026-10-23
- Calendar entries, clearer: in New / Edit Event, "Assigned to" sits beside the title where "Calendar" was. The Calendar picker shows only when there is more than one calendar; with one, entries go on it without asking.
- An entry's quick look lists its agents only under "Assigned to". Contacts and email addresses are under "Invited", which is left out when there are none.

## 26.10.117 - 2026-10-23
- Calendar and Dispatch: the agent's colour on service and project bookings is a solid bar down the left edge instead of slanted stripes.
- Calendar: the assigned agent's first name shows beside the time on bookings and entries ("Chris +1" when an entry has more agents; full names show when you hover). In a narrow column it moves under the time.

## 26.10.116 - 2026-10-22
- Calendar and Dispatch: booked service tickets have their own colour with a bar down the left in the assigned agent's colour; project bookings get the same bar instead of a dot. They still follow Whose (one agent, or Everyone). Set the colour under Admin > Settings > Ticket > Calendar.
- In month view and the list, where an item is a line rather than a block, the agent still shows as a dot.
- Calendar entries are drawn in their assigned agent's colour; the calendar's colour is kept for entries with nobody on them.

## 26.10.115 - 2026-10-22
- Calendar entries are assigned to agents. New Event has "Assigned to" under the title; contacts and email addresses are under "Invite".
- An entry made while looking at an agent's calendar (Whose) starts assigned to that agent.
- Entries show their agent's colour dot and name on the calendar and on Dispatch. Once an entry is assigned to others, it no longer sits on the calendar of the person who made it.
- An entry's quick look has "Assigned to", to hand it to someone else without opening the full form.
- On Dispatch's day view, a one-off entry can be dragged to another time or to another agent's column, which hands it over. Repeating entries still move from Edit.
- An agent taken off an entry who had been sent the invitation gets the cancellation. A new agent gets the invitation when others on the entry already have it. People who already have the invitation get the new time.

## 26.10.114 - 2026-10-22
- Timesheets: ticket entries in a day's panel can be fixed in place - click an entry's times to change its start and end (Enter saves, Esc cancels). An overlapping entry offers "Start it at ..., after the one before" (same length). The note on the ticket changes too, and its time worked follows the new times. Days on a submitted, approved or closed timesheet stay locked, as on the ticket. When someone else fixes your entries, you get a notification.

## 26.10.113 - 2026-10-22
- Admins can set their own employment (hourly / salary / contractor) in Account > Preferences, under Timesheet - Admin > Users doesn't edit your own account. The change is written to the audit log.

## 26.10.112 - 2026-10-22
- Customer Thermometer: a satisfaction survey goes out for each ticket resolved on the service boards you pick, from the start date you pick (older tickets are never sent). Each carries the tech's name, the ticket number and its subject. Set up in Admin > Integrations > Customer Thermometer.
- Responses come back every 15 minutes: "Customer happiness" (Gold + Green share, with the four counts) on the dashboard and each client's overview, a list of every response with its comment, and a line in the ticket's history.
- In development mode surveys go to the test inbox.

## 26.10.111 - 2026-10-22
- Admin > Backup: big backups restore again - they were unpacked in the server's temporary folder, which on newer Debian is a small memory disk ("Could not extract the archive: No error"). They now unpack next to the backups.
- A restore that fails part way still leaves the backup list right and the database up to date.
- New servers keep PHP's temporary files on disk (the installer); DEPLOYMENT.md has the two commands for a server installed before.

## 26.10.110 - 2026-10-22
- Admin > Backup: backups up to 5 GB can be uploaded to restore - the page sends the file in pieces with a progress bar (keep the page open), so the server's upload limit no longer matters.
- Before an upload or a restore, free disk space is checked (a restore needs about three times the backup's size).
- Each restore's result is kept and shown on Admin > Backup - a big restore carries on even if the page stops waiting, and you see afterwards how it went.

## 26.10.109 - 2026-10-22
- Development mode: integrations sync into the dev server but nothing done there goes back - NinjaOne, Bitwarden and Ingram Micro are still read, Outlook busy times still come in, and nothing is created, changed or deleted in them, even from a button pressed by hand. Mail sent to the test inbox still goes out.
- Development mode: QuickBooks Online and invoice batching work with an Intuit sandbox company connected on the dev server (production's connection from the backup is never used or revoked); QuickBooks' invoice emails go to the test inbox.

## 26.10.108 - 2026-10-22
- Admin > Backup: restore a backup on a running system - the Restore button on a stored backup, or upload a backup .zip (also one from another server, with its key). You see what is in it first, and confirm with your password and by typing RESTORE.
- Before anything changes, a Full backup of the system as it is now is made and kept as "Before restore" - restore that to undo. Afterwards the database updates run and everyone signs in again. Do it when nobody is working; it takes a minute or more.
- A backup from stock ITFlow (v26.08 or newer) can be restored the same way - type that server's backup key in the Restore window. ITFlow's own database updates now run after a restore from Admin > Backup too (an older backup was left on its old database version).
- Admin > Backup > Development mode, for a copy of production on a test server: every email goes to a test inbox (the subject says who it was for), the mailbox is not read, Outlook sync, QuickBooks Online, Stripe, custom actions and the Microsoft 365 certificate renewal are off, and a red DEVELOPMENT badge shows in the top bar. NinjaOne, Bitwarden and Ingram Micro are still read. Kept in config.php, so restoring production's backup doesn't switch it off.

## 26.10.107 - 2026-10-22
- Procurement: the Receiving number in the side menu counts the items still to arrive (it counted purchase orders, so a PO with two items showed 1).

## 26.10.106 - 2026-10-21
- Ticket page: Board, SLA and Category in the Details box are drop-downs now, like Status and Priority - pick and it is saved, no window. Changing the SLA reloads the page so the clock shows the new targets. Escalating to another board (with a reason) is still in the ⋮ menu.

## 26.10.105 - 2026-10-21
- New "Dispatch" permission in Admin > Roles (database update M035). View sees the Dispatch board, queue, Workload and This week, marked "View only" - nothing can be dragged, booked or assigned there. Edit books, moves, assigns and merges (with Support edit, as before).
- On the update every role that had Support edit gets Dispatch Edit, so nobody loses the board. Then set Technician to View, and make a Dispatcher role (Client View, Support Edit, Dispatch Edit).

## 26.10.104 - 2026-10-21
- Notes only: approving your own timesheet with Full Timesheets access stays allowed (by choice). No program changes.

## 26.10.103 - 2026-10-21
Roles check - every feature tried against ITFlow's roles (admin, Technician, Accountant, and test roles with no access, view only, full but not admin, and limited to one client):
- Agents are picked, handed or told about tickets only when their role can work tickets: round-robin skips them ("their role cannot work tickets"), board notices, the internal note Notify list, Additional agents and Dispatch's agent columns leave them out, and their emailed replies are treated like a stranger's.
- Service boards by role now also apply to the calendar's To schedule and Left behind lists, the client's ticket tiles and overview counts, and Dispatch's "other tickets of this contact". Dispatch's Schedule window no longer moves a ticket off a board the agent can't see.
- Procurement: receiving makes assets and ticket notes only for someone with Support edit, and adds stock only with Sales edit. A ticket moved to another client takes its parts still to order or receive with it. PO approval requests go only to approvers who can open the PO. Send to procurement from an invoice needs Sales access; invoice numbers link only for Sales.
- QuickBooks batch totals count only clients you can see. The portal's saved-card payment checks the invoice is yours before anything else.
- Timesheets: the person picker lists only people you may open (it showed everyone). An approver can be any agent, even one who keeps no timesheet. Whoever may remove a reimbursement may also change it. When someone else changes your hours you get a notification.

## 26.10.102 - 2026-10-21
- Service boards by role: Admin > Roles > Edit has a "Service boards" section - leave "All boards" on, or pick the boards that role's agents see (e.g. technicians don't see Sales). Admin roles always see every board.
- A board a role can't see is left out of its Tickets list and kanban, the board switcher and counts, the dashboard, Dispatch, the calendar, global search and the client overview. Tickets assigned to them, ones they help on and ones they escalated still show; a direct link still opens the ticket.
- New Ticket offers only the role's boards; Escalate still offers every board. Admin > Settings > Boards shows which roles see each board.

## 26.10.101 - 2026-10-21
Audit of the QuickBooks, usage billing and connector releases (four reviewers; every finding checked against the code):
- QuickBooks: an invoice in a batch (sent or still waiting) takes no payments in ITFlow - agent, bulk, nightly autopay, client portal and guest pay links (before, autopay could charge a card for an invoice QuickBooks was also collecting). Sending refuses an invoice paid, cancelled or archived since it was batched.
- QuickBooks: an invoice voided in QuickBooks is no longer read as "paid in full" (it is flagged to take out). "Leave in QuickBooks" keeps payments already brought back. A send that timed out after QuickBooks saved the invoice is not made twice. Payment checks and batch send / delete / take-out can't run over each other.
- QuickBooks: connecting a different company (e.g. sandbox, then production) is refused while invoices are in batches, and clears the old mappings otherwise. The sign-in is renewed before it lapses even with nothing to check. Items inside a QuickBooks category and inactive customers are matched. The email call uses the content type Intuit asks for.
- QuickBooks: overdue reminders and late fees stop only for invoices in batches (others carry on); Mark Sent and deleting a client are refused while invoices are in batches; batch delete / rename need access to all its clients; receiving a PO no longer makes an approved invoice "changed"; the invoice strip also locks the notes, line order and Mark Sent.
- Usage billing: a deleted or archived tag is no longer counted as 0 (the line shows "No count"); "tag:" usage names are kept for tag counts; last month's quantity is what the last invoice still standing billed (hand edits count, cancelled ones don't); the Usage Review count includes invoices changed since approval; an error setting quantities still leaves the invoice a Draft.
- Connectors: NinjaOne counts only while its sync has run in the last 2 days (a stopped sync no longer bills 0); servers include VMware / Hyper-V hosts, `ninjaone:devices` = workstations + servers, new `ninjaone:all` for everything. Ingram part numbers with unusual characters are counted and kept consistently; paging and customer filtering are checked; the API address must be public https and a new address needs the password and key typed again. One connector failing no longer stops the others; runs can't overlap.


Usage connectors (database update M033) - Admin > Integrations > Usage connectors, run daily with the Usage Counts job or Run now:
- NinjaOne: workstations, servers and all devices per client (from what the NinjaOne sync saw in the last 7 days) - usage names `ninjaone:workstations`, `ninjaone:servers`, `ninjaone:devices`.
- Bitwarden: each client organization linked with its own API key (the Provider portal has no API; the key is tested before it is kept, and stored encrypted) - `bitwarden:users` (members not revoked, as Bitwarden bills) and `bitwarden:confirmed`. US or EU region.
- Ingram Micro Cloud Marketplace (Xvantage): your API login, read customers, link them to clients (or match by name) - each product on a customer's active subscriptions is counted as `ingram:<part number>` with its name; a product that ends counts 0.
- A recurring invoice's usage line now offers the usage names already reported for the client (e.g. "Microsoft 365 Business Basic") to pick from.
- Parallels RAS stays a contact tag (e.g. "RAS user").


Usage billing (database update M032):
- A recurring invoice's lines can be billed by a count instead of a fixed quantity (its new Usage billing card): typed each month, reported through the API, or the client's contacts carrying a tag (e.g. "Billable user"). A minimum can be set per line.
- Counts are kept with their date and source. Other systems report them with `POST /api/v1/mit_usage/create.php` (ITFlow API key, JSON: client_id, metric, quantity, as_of); `GET /api/v1/mit_usage/read.php` lists the usage names to report. Tag counts are recorded daily (cron job "Usage Counts"). The client's overview has a Usage card with the latest counts and a box to type one.
- Snapshot day (Admin > Settings > Invoices > Usage billing, default the 1st): when a recurring invoice is made, each usage line's quantity becomes the last count on or before the snapshot day, totals are worked out again, and the invoice stays a Draft whatever auto-send says.
- Billing > Usage Review: per snapshot, last month's and this month's quantity, the change and flags (over 20% by default, a count older than 7 days before the snapshot, no count, the minimum used). Change a quantity with a reason (kept in the invoice history) and approve - approved invoices go into QuickBooks batches as before.

## 26.10.98 - 2026-10-21
QuickBooks Online, first release (database update M031):
- Admin > Integrations > QuickBooks Online: connect a company through Intuit's sign-in (an Intuit developer app's client ID and secret), choose how tax is sent (US: taxable / non-taxable lines and one tax code per invoice), the item for lines with no match, the ITFlow account payments from QuickBooks go to, and whether QuickBooks emails new batches. Customers, Items and Tax codes tabs map ITFlow clients, products and taxes to QuickBooks (or match by name; missing customers can be made in QuickBooks).
- Billing > Invoice Approvals: every invoice is approved before it can go to QuickBooks. A change after approval (lines, amounts, dates, client) shows "Changed since approval" and needs approving again. Approve from the list (many at once) or from the strip on the invoice.
- Billing > QuickBooks Batches: tick approved invoices into a new or open batch, then Send - each becomes a QuickBooks invoice with ITFlow's number, a Draft becomes Sent, and QuickBooks emails it to the client's billing contacts (else the primary). Failed ones keep the reason and are sent again with the batch.
- An invoice in a batch is locked in ITFlow (edits, lines, cancel, delete, adding it to from tickets / parts / the API are refused). Taking it out unlocks it; one already in QuickBooks is voided there (refused while it has payments) or left there, and goes back to Draft for approval.
- Payments made in QuickBooks come back every hour (cron job "QuickBooks Online Payments", or "Check payments now"): one ITFlow payment per invoice follows what was paid there, so Partial / Paid, balances and the portal stay right. ITFlow won't take payments for an invoice in QuickBooks, and the synced payment can't be changed by hand.
- While connected, QuickBooks sends the invoices: ITFlow's invoice emails, overdue reminders, late fees and recurring invoices' auto-send stop (recurring invoices are made as Drafts to approve).
- Fix: ITFlow's Create invoice form logged a PHP warning (recurring discount not sent).

## 26.10.97 - 2026-10-21
Performance review (measured on a copy grown to 50,000 tickets, 300,000 replies and 300,000 log entries):
- New database indexes (update M030) for the open-ticket counts, date ranges, ticket numbers, attachments, client activity, last sign-ins and invoice counts. Admin > Users went from 0.8 s to 0.01 s, the dashboard from 0.5 s to 0.3 s, Dispatch's queues from 0.13 s to 0.02 s; most other pages are 30-60% quicker on a busy system.
- Dispatch's "This week" card reads a quarter of what it did.

## 26.10.96 - 2026-10-21
Full test pass (every page and pop-up window as admin, a restricted tech and an accountant; every background job; restore into stock ITFlow) - small fixes:
- Invoices, quotes and recurring invoices no longer ask for a missing company logo when none is set.
- Two PHP warnings removed (portal password reset with an unknown link, account changes opened without a referring page).

## 26.10.95 - 2026-10-21
Code review of ITFlow's own code - fixes:
- Security: credential 2FA codes can no longer be reached through a share link by agents without credential access, and shared 2FA codes stop once a one-time link is used; "Require MFA" is now enforced on every page; an existing MFA can't be replaced without turning it off first; the portal password reset no longer emails anyone for an empty or unknown link and is rate-limited; saving a card in the portal only accepts the checkout the person started; bulk email needs edit rights and a configured From address; read-only and wrong-client gaps closed (revenues, transfers, contact links, export list).
- Money: the agent "Pay with saved card" can no longer charge twice and charges the balance; Paid / Partial invoices follow changes to their total; converting a quote twice no longer makes two invoices; bulk payments round correctly and skip Non-Billable invoices; recurring invoices set to send automatically become Sent even when no email goes out, so autopay and reminders run; autopay never charges an invoice twice in a run; online payment respects the Stripe on/off switch and threshold, and small balances can be paid; portal saved cards work on part-paid invoices.
- Tickets: replying to a Resolved ticket with Open really reopens it (and its SLA); editing a ticket keeps a hand-picked SLA and no longer re-sends breach alerts; "Mark all complete" leaves tasks waiting for approval; merged replies keep their attachments; very long email subjects become tickets; accented text can't break the ticket history; portal close / resolve follow the same rules as the agent side.
- Quotes: expired quotes can't be accepted.
- Smaller: guest ticket avatars, shared-file download names, API key log names, date filters, expense refunds and receipts, portal document authors.

## 26.10.94 - 2026-10-21
Code review of all our additions - fixes:
- Timesheets: an overnight California shift on a clock-change night (Nov 1 / Mar 14) no longer gains or loses an hour each time it is saved; overnight note times the same.
- Timesheets: approving a period now stores its overtime, double time, meal / rest premiums and California status, so later changes to settings or someone's pay type no longer change periods already paid.
- Timesheets: editing an old ticket note no longer moves its time to another day; archiving a note, or deleting a ticket or client, with time on a submitted timesheet is refused; note times follow the auto-lock and can't be in the future.
- Timesheets: after a pay-period change, the older period's days stay locked and its stored figures are left alone; changes after a California signature ask for a new signature; holidays no longer fill submitted sheets; submit / take back can't race an approval; the timesheet page is much quicker.
- Boards: a board name with an apostrophe no longer breaks moving, escalating or New Ticket; names like O'Brien show without a backslash.
- Projects: closed project tickets keep their SLA results (only open ones are changed).
- Additional agents: helpers who can't see a ticket's client stop getting its emails and are taken off when the ticket moves client; a helper made lead gets the booking invite.
- Dispatch: booking cancels the old lead's invite only after the reassign works; the Schedule dialog checks everything before saving; "This week" and the auto-assign sweep are much lighter.
- Procurement: long part names no longer leave a half-made invoice (billing is all or nothing); received stock can't be counted twice when its invoice line is later deleted; quote lines keep their product, so "Send to procurement" picks hardware and the vendor; Create PO reports a failure; Bill parts shows only to people who can use it; better asset-type guesses.
- Microsoft 365: the calendar app gets the sign-in permissions Outlook connections ask for (an "Update permissions" button for existing apps); secrets are moved to the new key daily.
- NinjaOne: a device whose asset could not be made is tried again next run; unknown status shows as unknown; the "Offline in NinjaOne" client tile works.
- Mail: an agent replying to a ticket email is filed as their internal note without reopening the ticket.
- Signing in as another account in the same browser no longer bounces back to sign-in.
- Speed: side-menu counts are kept for a minute; fewer queries on the ticket page and Tickets list; new indexes for timesheets (database update M029).

## 26.10.93 - 2026-10-21
- Time fields: clicking into one no longer fills it - only the clock icon puts in the time now. Typing the hour and minutes fills in AM / PM to match the time now (type A or P to pick the other); date-and-time fields the same once the date and time are typed.
- Ticket notes: the Start / End boxes are wider, so times like 09:59 PM are no longer cut off.

## 26.10.92 - 2026-10-21
- Time fields everywhere (ticket notes, timesheets, Dispatch, calendar events, scheduling): the clock icon now puts in the time now instead of opening the scroll list. An empty time field starts from now when you click into it, so typing just the hour and minutes keeps the right AM / PM (and today's date on date-and-time fields); click in and leave without typing and it stays empty.

## 26.10.91 - 2026-10-21
- Pay type (Hourly / Salaried / Contractor) is now set in Admin > Users > Edit user, as "Employment" (also on New user). Admin > Timesheets > People shows it with a link there.

## 26.10.90 - 2026-10-20
- Ticket note box tidied: the timer and Date worked / Start - End sit together in one strip at the top ("From the timer" or "Typed by you" under it), then Status and the Add note button on one line below it - the button no longer wraps.

## 26.10.89 - 2026-10-20
- Ticket notes now record when the work was done: Date worked, Start and End sit under the time worked in the reply box. They follow the timer (ending now) until you type them; typing them sets the length. A note with time can't be sent without them, and they show on the note ("Tue Oct 20, 9:10 - 9:40 AM"). Edit reply has them too - change only the length and the start moves.
- Timesheets count ticket time on the day it was worked, not the day the note was posted. The day panel lists each ticket entry with its times, and warns when two overlap or (on California / time-clock days) when one falls outside your clocked times.
- Timesheets: a new Contractor pay type - no overtime and never under the California rules (even if switched on for them); they still keep a timesheet. The People list shows who is on California rules.
- Timesheets: automatic lost time now shows as a row with the charge codes in the day panel, going down as you add time, so it is clear what the day is charged with.

## 26.10.88 - 2026-10-20
- Dispatch Week view: the agent names stay on the left while the days scroll sideways, and each day is kept to a set width - long titles are cut short (hover for the whole title) instead of stretching one day across the screen.

## 26.10.87 - 2026-10-20
- Dispatch: each agent's column (Day and Week) now also shows the rest of their day - calendar meetings and events they organise or are invited to, closure days (holidays) and their Outlook busy times - so tickets can be booked around them. Only tickets can be moved there; click a meeting to open it.
- Dispatch: bookings show the onsite (car) or remote (headset) icon, as on the calendar page, with where / who underneath in the Day view.
- Booking a ticket over an agent's meeting now warns about the clash, like overlapping tickets and Outlook appointments already did.

## 26.10.86 - 2026-10-20
- Dispatch: a third queue tab, "Left behind" - every agent's open tickets booked on a day that has gone, oldest first, with the day they were booked. Drag one onto an agent and time to book it again.

## 26.10.85 - 2026-10-20
- Calendar: a "Left behind" list under To schedule - open tickets booked on a day that has gone without being done or moved on. Drag one onto the calendar to book it again (it keeps its length), or click it for a quick look; its old booking is outlined in red on the calendar.
- Calendar: To schedule and Left behind both follow whose calendar you are viewing - on "Everyone", To schedule now lists everyone's unbooked tickets (with each agent's name) instead of only yours.

## 26.10.84 - 2026-10-20
- Tickets: the List / Board buttons are now List / Kanban, and the kanban view's first tab is "Active" (was "On the board") - so "board" on the Tickets page only means a service board (Service Desk, Tier 1 ...).

## 26.10.83 - 2026-10-20
- Service boards, part 2 - round-robin: a board can hand its new tickets to its members in turn (Admin > Settings > Boards > Edit > Auto-assign). It skips anyone left out of the turn, on leave today (timesheets), Out of office in Outlook right now, who can't see the client, or who already holds the board's limit of open tickets. Tickets that arrive after working hours wait for the day to start. Only tickets placed on the board after it is turned on are handed out - never the ones already waiting.
- Dashboard: a Board picker beside the greeting (remembered) - Needs attention, Open tickets by tech and the Opened and resolved chart follow it - and a "Your boards" strip with each of your boards' open, unassigned and past-SLA tickets.
- New report, Board Performance (Reports > Service Desk): for each board, new tickets, moved in, escalated on, resolved, the typical first reply and time to resolve, SLAs met and what is open now; where escalations go; and each agent on one board. Exports to CSV.
- Reports: the Export button is visible again with the modern look.

## 26.10.82 - 2026-10-20
- Service boards: tickets now sit on a board - Service Desk (where new tickets land), Tier 1, Tier 2, Sysadmin and Sales to start with - separate from their category. Admin > Settings > Boards adds, edits, orders and archives boards and picks each board's members (and who of them gets told about new and moved tickets).
- Routing: new tickets go on a board by the address the email was sent to, then by client, then by category, else the default board - set under Admin > Settings > Boards > Routing. "Place open tickets by category" moves today's open tickets to their category's board once.
- Tickets: a board switcher above the list (with open counts, remembered like your other list settings) and the board on each row. New Ticket has a Board choice (Automatic follows the routing rules).
- Ticket page: a Board line (click to move it) and "Escalate to another board" in the ⋮ menu - a reason is required and kept as an internal note, the ticket is unassigned by default, and the board's members are told.
- Dispatch: pick All boards, My boards (the default when you are on one) or a single board - the queue, Workload and This week follow it; queue cards show their board, the Schedule dialog can move a ticket to another board, and "Only the board's members" shows just that board's agents. The Dispatch count in the menu counts your own boards.

## 26.10.81 - 2026-10-20
- Admin > Settings > Theme: the colour names now show properly even where the browser kept an old copy of ITFlow's stylesheet (no refresh needed with the modern look).

## 26.10.80 - 2026-10-20
- Modern look follows the company theme colour again (Admin > Settings > Theme): the menu's current page, buttons, tabs, links and a line along the top bar take the colour - with dark text on yellow, and very dark colours lifted in dark mode so they still show.
- Admin > Settings > Theme: the colour names under each preview were squeezed to a letter or two per line - fixed.

## 26.10.79 - 2026-10-20
- Modern look: the Financial boxes on a client's overview (Balance, Paid this year, Recurring, Not yet invoiced, Terms) are readable again - label, amount on one line and the note under it.

## 26.10.78 - 2026-10-20
- Quotes, invoices and recurring invoices look like a document: a white sheet with the buttons in a strip above it, a bold blue QUOTE / INVOICE title, the number and dates in a neat box, the customer in a card with a blue edge, line items with a tinted heading, the add-a-line row set apart, a softer "Our cost" column and the Total in a blue band.
- The broken "Company logo" picture on quotes and invoices is hidden when no logo is uploaded.

## 26.10.77 - 2026-10-20
- New sign-in screen: a dark navy background with soft blue glows, a faint grid and network lines, and a white rounded sign-in box (also the client portal's password reset). An uploaded company logo sits on a white plate so it still shows.

## 26.10.76 - 2026-10-20
- Modern look: the "Needs attention" boxes on the dashboard and client overview, and a client's count tiles, are now compact one-line tiles (icon, label, number on the right) in even columns - about a third of the height.

## 26.10.75 - 2026-10-20
- Modern look, part 2: it now works in Dark mode too (dark navy panels, same blue).
- A client's Assets, Licenses and Tickets pages open with count tiles - warranty expired or ending, devices over 5 years old, offline in NinjaOne, licenses expired or renewing, open / overdue / unassigned tickets, closed in the last 30 days and time logged this month. Click a tile to see the list it counts.
- The client header shows the primary contact, phone, email and website.
- Charts use the same softer colours.

## 26.10.74 - 2026-10-20
- New modern look: a slate side menu with a blue pill on the page you're on, a white top bar, soft grey rounded panels, count tiles with pastel icons, tinted status pills and the Inter font. On for everyone; go back to ITFlow's look under Account > Preferences > Look > Classic. It's light only - the Dark theme keeps ITFlow's dark look.

## 26.10.73 - 2026-10-20
- Security: changing a password (yourself, an admin, or the "reset all passwords" button) or turning MFA on or off now signs that person out everywhere else on their next click - also client portal logins.
- API: send the key in an `X-API-Key` header (or `Authorization: Bearer`); keys in the address (`?api_key=`) are refused, since they end up in server logs. Update n8n and any scripts that used the address.
- Security headers on every page (no MIME sniffing, a same-site referrer, no framing by other sites, HTTPS remembered), and files opened straight from uploads can't run scripts.
- Outlook calendars can get their own calendar-only Microsoft app (Admin > Settings > Mail, one Global Admin sign-in): staff Outlook sign-ins then can't reach their mail. Everyone who connected Outlook reconnects once. You're warned when the Outlook account isn't your ITFlow email.
- Client portal: sign-ins end when the client or contact is archived or the portal is switched off; Microsoft sign-in only from the client's own Microsoft 365 organization; paying with a saved card can't charge twice.
- Emails from someone who isn't a contact, watcher or agent but quote a ticket number are kept as an internal note without reopening the ticket or adding their attachments; bounces no longer get an automatic "ticket closed" reply.
- Moving a ticket to another client takes off the old client's watchers and remembered email recipients.
- Schedule clashes for clients you can't see show as "Another booking"; the Schedule window only offers agents who can see the client.
- Trips need Financial edit access, and trips made by reimbursements are changed on the ticket; moving expenses to another client checks their current client too.
- The last admin role can't lose admin; the AI summary is cleaned before it's shown; the API keeps an asset's or contact's links within its own client.
- Fixes: the API reply endpoint (closed tickets, bad statuses), API limits and the software lookup; lists no longer error on page 0 or an unknown sort; a code of 000000 now works for MFA; guest feedback; quote accept / decline email links; opening the site address while signed in to the portal; tickets with your client's asset only from the portal.
- Reimbursements: amounts are checked properly and receipts are limited to 20 MB; the old Microsoft certificate is taken off the apps after a renewal.

## 26.10.72 - 2026-10-20
- Security: a link in an emailed ticket could load another website's page into ITFlow and run it as the agent who clicked it - pop-ups now only open ITFlow's own pages, and ticket text can't carry the pop-up marker.
- With Microsoft 365 (or Google) mail, portal "Forgot password?", payment receipts, quote accept / decline emails, new-user emails, share-link emails and the "your account was changed" security emails were silently skipped - they are sent again.
- Microsoft 365 mail reading no longer stops when about 200 unprocessed messages pile up in the inbox, and a failed mailbox read (or a mail server refusing the connection) now shows as a failed job, so cron alerts report it.
- Client portal: only the primary contact can give someone a portal login or change a login's email or sign-in method (another contact could take over a billing contact's login); files on internal notes can no longer be downloaded from the portal; attachments on closed tickets download again.
- Installer: setup runs before the site goes live (the setup page was briefly open to the internet); "behind a reverse proxy" mode keeps secure cookies and sees each visitor's real address (one proxy address could lock everyone out).
- Autopay now charges invoices that are partly paid.
- Reimbursed purchases: changing or removing one after a reopen updates or removes its expense; with timesheet approval off they are recorded as expenses once the period settles.
- Two people (or a double click) approving a timesheet, recording a vendor bill or putting the same items on a PO at once can no longer double up.
- NinjaOne: an asset you delete in ITFlow is no longer made again by the next sync (it is reported instead); a device whose asset is under another client keeps its link; an unknown online status no longer shows as Online.
- Outlook: events of bookings older than 30 days are no longer removed from techs' calendars.
- Cron alerts also report a job that has never run, and a Microsoft 365 certificate that failed to renew or runs out within 14 days.
- Purchase order numbers are now unique on every install (duplicates are renumbered, noted in the PO's history).
- API client delete puts unbilled parts back in stock and removes the client's files and quote images.
- Restoring an older backup runs ITFlow's database updates before ours.
- Scheduling: picking a new tech who can't see the client is refused, and the old tech's invite is only withdrawn once the new one has the ticket.
- Emails from our nightly jobs no longer show a backslash in names with an apostrophe.

## 26.10.71 - 2026-10-20
- Timesheets: changing someone's time zone now counts from that moment - or, if they have a submitted or approved timesheet that ends later, from the day after it - so ticket time already on a timesheet stays where it was (it could be counted twice or dropped before). Every change is logged with who made it.
- Timesheets (California rules): a block that starts and ends at the same time, or blocks typed out of order, are refused with a message instead of being saved as 24 hours or as the next morning. At most 12 blocks a day.
- Timesheets: time put on the default code for "Not on a code" stops at that code's daily limit.
- On a split-shift day the day row no longer says "no meal" when the time off between shifts counts as the meal break.

## 26.10.70 - 2026-10-20
- Timesheets (California rules): Admin > Timesheets > Settings has "Time not on a code goes on" - pick a charge code (e.g. Admin) and the hours worked that are not on ticket time or a code count on it, instead of showing as "Not on a code". The day shows it as "<code> (not on a code)"; the payroll report counts it under that code. Approved timesheets keep the code they were approved with.

## 26.10.69 - 2026-10-19
- Timesheets (California rules): work times are now blocks - Start and End - with "Add another block" for as many as a day needs. Between blocks, pick a meal break or "off between shifts" for a split shift; the day shows the meal and the time off separately. Weekends and holidays take times like any other day.
- Timesheets: time zones for remote people - set one on Admin > Timesheets > People, or yourself under Account > Preferences. Their timesheet's "today", days still to come and the day their ticket time falls on follow their own clock; the timesheet shows the zone.

## 26.10.68 - 2026-10-19
- Timesheets: a Holidays tab under Admin > Timesheets - add, edit (date and name) and remove holidays a year at a time, or add the US holidays for a year. They are the same closure days as Admin > SLA.
- Changing a holiday puts open timesheets right straight away (hours move with it, renamed, or come off); submitted and approved timesheets are left as they are.
- Holidays show on the calendar too, all day in red, under a new Holidays tick box.

## 26.10.67 - 2026-10-19
- Fix: the Notify and To lists on the reply box close when you click into the note editor (it is its own frame, so the click was not seen and the list stayed open).

## 26.10.66 - 2026-10-19
- Fix: a running ticket timer now stops when the note is saved. ITFlow reset it a moment after the button was clicked, which was missed when the page moved on quickly, so the timer kept running.
- After a note the timer stays at 0, even with timer auto-start on; it starts again the next time the ticket is opened.
- An email reply that is not sent (nobody in To) keeps its timer.

## 26.10.65 - 2026-10-19
- Internal notes: a "Notify" line to ping techs about the note - they get a bell notification and an email with the note and a link to the ticket. A reply to that email from their own address is added to the ticket as an internal note.

## 26.10.64 - 2026-10-19
- Email reply: who it goes to is now one "To" line - names as chips (no email addresses for people in ITFlow), type to find a contact, watcher or someone on our team, or type a new address.

## 26.10.63 - 2026-10-19
- Version numbers: our build is ITFlow's release + an iteration, shown in the admin footer and on Admin > Update.
- Release notes for every update so far (this list), shown under Admin > Update.

## 26.10.62 - 2026-10-19
- Tickets: pick who an email reply goes to (M021)

## 26.10.61 - 2026-10-19
- Dispatch: edit the ticket title in the Schedule dialog

## 26.10.60 - 2026-10-19
- Ticket page: Read tick on the description keeps it folded away

## 26.10.59 - 2026-10-19
- Calendar: project ticket bookings follow the Projects tick box

## 26.10.58 - 2026-10-19
- Project tickets kept apart: off the Tickets board, no SLA, own calendar color

## 26.10.57 - 2026-10-18
- NinjaOne page: header and tabs like the other admin pages

## 26.10.56 - 2026-10-18
- NinjaOne moves to Admin > Integrations

## 26.10.55 - 2026-10-18
- CLAUDE.md: notes for merging ITFlow 26.11 (product column clash, overlaps)
- NinjaOne RMM: device sync to assets (replaces the n8n workflow), org mapping with n8n import, NinjaOne buttons with live status on tickets and assets (M019)

## 26.10.54 - 2026-10-18
- Timesheets: who is behind with Remind buttons, Send back on the team list, send-back emails

## 26.10.53 - 2026-10-18
- Security review fixes; end-to-end smoke tests for our features

## 26.10.52 - 2026-10-18
- Dispatch: This week dashboard - inbound vs closed, SLA results, assigned vs closed by agent

## 26.10.51 - 2026-10-18
- Dispatch: Workload view of each tech's open tickets, SLA and booked hours

## 26.10.50 - 2026-10-18
- Our scripts load with their file time on the address, so browsers pick up updates

## 26.10.49 - 2026-10-18
- Ticket reply box starts open on Public note

## 26.10.48 - 2026-10-18
- Procurement settings: switch approval on or off, and choose who approves
- Procurement settings: drop 'Who can approve' - approvers come from roles
- Procurement only for roles with the Procurement permission
- Ticket Parts box for everyone on the ticket; the rest of Procurement stays with the role
- Unbilled Parts moves to Billing and follows the Sales permission

## 26.10.47 - 2026-10-18
- Procurement > Unbilled Parts: every ticket part not billed yet, in one place

## 26.10.46 - 2026-10-18
- Timesheet: the signing window could not be clicked

## 26.10.45 - 2026-10-18
- My timesheet: all time on tickets shows as one 'Ticket time'
- Timesheets: 'All ticket time' counts as billable (the new default)

## 26.10.44 - 2026-10-18
- Receiving: open the standard asset window for each new client asset
- Smoke test: look for a PO among all of them, not only open ones
- Receiving: 'Make client items assets' and 'Add stock items to stock' start unticked

## 26.10.43 - 2026-10-18
- Purchase orders: admins can approve their own; action links are buttons again

## 26.10.42 - 2026-10-17
- Timesheet page redesign: a week at a time, a row a day, a side panel that saves as you type

## 26.10.41 - 2026-10-17
- Timesheets: California non-exempt time records, meal premiums, CA overtime and signed attestation
- California timesheet rules apply from the day the update runs

## 26.10.40 - 2026-10-17
- Procurement: project picker follows the client and says why it is empty

## 26.10.39 - 2026-10-17
- Cron health: top-bar warning for admins, alert emails, health address for an uptime monitor

## 26.10.38 - 2026-10-17
- Add Playwright smoke tests (tests/smoke) for the test install

## 26.10.37 - 2026-10-17
- Tickets list remembers each agent's filters, with a Reset view button

## 26.10.36 - 2026-10-17
- Dispatch: set the ticket's status in the Schedule dialog
- Dispatch: show the contact's other open tickets, and merge them

## 26.10.35 - 2026-10-17
- Reimbursements: mileage and purchases logged on tickets, paid with payroll

## 26.10.34 - 2026-10-17
- Timesheets: lunch and paid breaks

## 26.10.33 - 2026-10-17
- Dispatch: check the ticket's details while scheduling it

## 26.10.32 - 2026-10-17
- Dispatch: keep every agent column's hours full height
- Dispatch: queue and board are the same height in day and week

## 26.10.31 - 2026-10-17
- Review of our additions: fixes, permissions, module switches, menus, docs

## 26.10.30 - 2026-10-17
- Dispatch board: tech columns by day, techs by week, and the queue

## 26.10.29 - 2026-10-17
- Timesheets: charge codes, pay-period timesheets with approval, payroll report
- Timesheet options: settings, people, code rules, overtime, reminders
- PTO and sick leave balances with configurable accrual

## 26.10.28 - 2026-10-17
- Procurement part 3: receiving extras, vendor bill as expense, order status

## 26.10.27 - 2026-10-17
- Procurement part 2: quote, invoice and ticket parts into procurement

## 26.10.26 - 2026-10-17
- Procurement part 1: items to order, purchase orders with approval, receiving

## 26.10.25 - 2026-10-17
- Ticket layout: column widths, a third column, half-width boxes

## 26.10.24 - 2026-10-17
- Tickets: customisable page layout - box order, column, start collapsed

## 26.10.23 - 2026-10-17
- Tickets: additional techs, each with the lead's time or their own
- Additional techs: a helper who becomes the lead stops being a helper

## 26.10.22 - 2026-10-17
- Calendar colour moves to Account > Preferences; dots in the Whose list

## 26.10.21 - 2026-10-17
- Quotes: add an image from a link on the add-item row too
- Quotes: line the quote page up - profit box under the totals

## 26.10.20 - 2026-10-17
- Quotes: our cost with markup / margin pricing, and an image per line

## 26.10.19 - 2026-10-16
- Repeat by weekday: "the third Wednesday", "the last Friday"

## 26.10.18 - 2026-10-16
- Cron and code interplay: fixes from a review of everything around our changes

## 26.10.17 - 2026-10-16
- Keep this build portable to stock ITFlow, and check it

## 26.10.16 - 2026-10-16
- Ticket timers in the top bar, next to the bell

## 26.10.15 - 2026-10-16
- Microsoft 365 mail and Outlook: fixes from a full review

## 26.10.14 - 2026-10-16
- Outlook busy times on the ITFlow calendar and in booking overlaps

## 26.10.13 - 2026-10-16
- Outlook calendar per tech: booked tickets show in each tech's Outlook

## 26.10.12 - 2026-10-16
- Microsoft 365: let a non-admin mailbox connect to ITFlow's own app

## 26.10.11 - 2026-10-16
- Microsoft 365: send and receive mail through Microsoft Graph

## 26.10.10 - 2026-10-16
- Microsoft 365: ITFlow creates its own app in the tenant

## 26.10.9 - 2026-10-16
- Calendar: show the Techs list (and your colour dot) even when there is only one tech

## 26.10.8 - 2026-10-16
- Calendar: each tech can pick their own calendar colour

## 26.10.7 - 2026-10-16
- Calendar: remember the view, whose calendar and ticked calendars on the agent's account (M002)
- Calendar settings: keep the ticked sources an object when empty; CLAUDE.md note

## 26.10.6 - 2026-10-16
- Add Mountaineer IT database update track (M001: ticket schedule end)
- Calendar: each tech's colour and calendar, book a block of time from the ticket
- Calendar: spacing above the Techs legend; CLAUDE.md records the DB update scheme and calendar hooks

## 26.10.5 - 2026-10-16
- Merged unreleased ITFlow 26.11 work (upstream develop) early, for the new ticket page.
- CLAUDE.md: note the early merge of upstream develop and hold production promotion until 26.11

## 26.10.4 - 2026-10-16
- Portal: stop contacts editing the primary contact or granting themselves billing access
- Security: saved-card client scope, date-filter XSS, API client checks
- API: stop ticket updates wiping the vendor ticket number
- Billing: recurring force/discount, recurring expense dates, payments, autopay
- Billing: contract rates, time rounding, time reports, quote due date, stock
- Fix broken features: guest payment errors, task rows, modules, setup, restore
- Installer: /etc/cron.d, upstream-style short options, --ssl none; drop welcome workflow

## 26.10.3 - 2026-10-15
- install.sh: fall back to su when runuser is not available

## 26.10.2 - 2026-10-15
- install.sh: set a full PATH so runuser/a2enmod are found after plain 'su'

## 26.10.1 - 2026-10-15
- Set up Mountaineer IT deployment: MountaineerIT as release branch
- CLAUDE.md: confirm with the user before every push or merge
- DEPLOYMENT.md: fix install steps and beta auto-update from review
- Add one-line Debian installer (scripts/install.sh)
- Docs: commit directly to develop; PRs only for develop -> MountaineerIT
