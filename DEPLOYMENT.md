# Mountaineer IT – ITFlow deployment

This repository is Mountaineer IT's build of [ITFlow](https://github.com/itflow-org/itflow).
Servers install and update from **this** repository, never from itflow-org directly.

The commands below assume Debian/Ubuntu with Apache, ITFlow at `/var/www/itflow`, and the
web server user `www-data` (home directory `/var/www` – check with `getent passwd www-data`).
Adjust paths if yours differ.

## Branches

| Branch          | Purpose                                                        | Who pulls from it   |
|-----------------|----------------------------------------------------------------|---------------------|
| `MountaineerIT` | Production. Default branch. Changes arrive only by PR from `develop`. | Production servers  |
| `develop`       | Staging. Our changes and ITFlow's releases land here first.    | Beta / test server  |
| `master`        | Untouched copy of upstream `master`, for comparison only. **Never deploy it** – see "Moving an existing install over". | Nobody |

Flow:

```
itflow-org/itflow (official releases)
        │  merged on request ("Claude, pull in ITFlow's updates")
        ▼
develop  ──►  beta server         ← our changes are committed straight to develop
        │  PR once tested on beta ("Claude, promote develop to production")
        ▼
MountaineerIT  ──►  production servers
```

## How a server knows which branch to follow

ITFlow's updater does a `git fetch` from the clone's `origin` remote, hard-resets the files
to `origin/$repo_branch`, then applies any pending database updates. `$repo_branch` lives
in the server's `config.php`:

| Server     | `origin`                                         | `$repo_branch`              |
|------------|--------------------------------------------------|-----------------------------|
| Production | `https://github.com/MountaineerIT/ITFLOW.git`    | `'MountaineerIT'` (default) |
| Beta       | `https://github.com/MountaineerIT/ITFLOW.git`    | `'develop'`                 |

Admin > Update shows "Not the release branch" on anything other than `MountaineerIT` –
expected on the beta server.

Clicking **Update** in Admin > Update does not update immediately: it *queues* the update and
the cron dispatcher (`cron/cron.php`) runs it within a minute. So **cron must be installed and
switched on** (Maintenance > Cron) for updates to happen.

## New install – one line (Debian 12 / 13)

On a fresh Debian server, as root:

**Beta / testing server** (tracks `develop`, updates itself nightly):

```bash
curl -fsSL https://raw.githubusercontent.com/MountaineerIT/ITFLOW/develop/scripts/install.sh | bash -s -- --branch develop
```

(Minimal Debian may not have `curl`: use `wget -qO- <same URL> | bash -s -- --branch develop`.)

It asks for the server name, HTTPS type (Let's Encrypt for a public name, self-signed for an
internal one, or `none` for a server behind a reverse proxy that handles HTTPS - the proxy must send `X-Forwarded-Proto: https`
and `X-Forwarded-For`; proxies on a private network are trusted for the visitor's address, add `RemoteIPTrustedProxy <ip>` to
`/etc/apache2/sites-available/itflow.conf` for a public one), timezone, company name and the first admin's name, email and password,
(API: from 26.10.73 the key goes in an `X-API-Key: <key>` header - or `Authorization: Bearer <key>` - not in the address;
`?api_key=` is refused. Update n8n / scripts that used it.)
shows a summary and asks to continue. It then:

- installs Apache, MariaDB, PHP and git, and sets PHP upload limits to 500 MB
- creates the `itflow` database (utf8mb4_general_ci) and a database user with a random password
- clones this repository into `/var/www/itflow` as `www-data`, on the chosen branch
- sets up the Apache site with HTTP→HTTPS redirect and the certificate (or plain HTTP with `-s none`)
- runs ITFlow's setup (company, first admin, `config.php` with `$repo_branch` set)
- writes `/etc/cron.d/itflow` (jobs run as `www-data`; errors go to the system log -
  `journalctl -t itflow-cron`), switches cron on, sets Domain Refresher to every 30 minutes
- on non-production branches, schedules the nightly self-update (see "Updating")
- saves the database password to `/root/itflow-install-credentials.txt` (root only)

Every question can also be given as an option for an unattended install – run it with
`--help` to list them. The short options match ITFlow's own installer: `-b` branch, `-d`
domain, `-s` HTTPS type, `-t` timezone, `-u` unattended.

It is for fresh servers only: it stops if `/var/www/itflow` or an `itflow` database already
exists. If it stops part-way, fix the problem it reports and rerun on a fresh server (or drop
the `itflow` database and user and delete `/var/www/itflow` first).

## New install – manual

1. Create the database with the collation ITFlow's schema uses, so tables added by future
   database updates match:

   ```sql
   CREATE DATABASE itflow CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
   ```

2. Create the install folder as root (`/var/www` is root-owned), then clone into it as
   `www-data`:

   ```bash
   sudo install -d -o www-data -g www-data /var/www/itflow
   sudo -u www-data git clone -b MountaineerIT https://github.com/MountaineerIT/ITFLOW.git /var/www/itflow
   # beta server: use -b develop
   ```

3. Run the web setup (`https://<host>/setup`) or `scripts/setup_cli.php` (as `www-data`).
   Setup writes `$repo_branch = 'MountaineerIT'` into `config.php`; **on the beta server
   change it to `'develop'`**.

4. Add the single cron entry to `www-data`'s crontab (`sudo crontab -u www-data -e`) and turn
   cron on in Maintenance > Cron (it ships switched off):

   ```
   * * * * * php /var/www/itflow/cron/cron.php >/dev/null
   ```

5. Recommended cron setting: in Maintenance > Cron set **Domain Refresher** to run every
   15–60 minutes. It refreshes only one domain per run, so on the default daily schedule a
   list of N domains takes N days to refresh and expiry dates go stale.

6. If you use **nginx** instead of Apache, the `.htaccess` files are ignored – deny web access
   to `/.git`, `/config.php`, `/cron/`, `/scripts/`, `/setup/` (once installed), `/tests/`, `/uploads/backups/`,
   `/uploads/tmp/`, `/uploads/mit_receipts/`, the top-level `*.md` and `*.pdf` files, and PHP anywhere under `/uploads/`;
   and send the same headers the `.htaccess` files set: `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin`,
   `Content-Security-Policy: frame-ancestors 'self'` (plus `script-src 'none'` under `/uploads/`) and, over HTTPS,
   `Strict-Transport-Security: max-age=31536000`.

## Moving an existing install over from itflow-org

> ⚠️ **Change `$repo_branch` before updating.** An install set up from itflow-org has
> `$repo_branch = 'master'` in `config.php`. In this repository `master` is the untouched
> upstream copy, so updating without changing it would deploy upstream code and run
> database updates that cannot be rolled back. (Recommended: rename this repository's
> `master` branch, e.g. to `upstream-master`, so a forgotten setting fails instead.)

1. Check the server is not ahead of the branch it is moving to: it must be on the same ITFlow
   release as that branch or older (Admin > Update shows the version). Never move a server
   that has run upstream's unreleased `develop`.
2. **Take a backup** (Admin > Backup) – see "Updating".
3. Point the clone at this repository:

   ```bash
   cd /var/www/itflow
   sudo -u www-data git remote set-url origin https://github.com/MountaineerIT/ITFLOW.git
   sudo -u www-data git fetch origin
   ```

4. Edit `config.php`: `$repo_branch = 'MountaineerIT';` (or `'develop'` on beta).
5. Check `www-data`'s crontab has only the single `cron.php` line. Older installs may still
   have separate lines for `nightly_tasks.php`, `ticket_email_parser.php` etc. – remove them,
   or those jobs run twice and outside the schedules set in Maintenance > Cron.
6. Make sure cron is switched on (Maintenance > Cron), then update from Admin > Update.

## Restoring this build onto a new server

Backups hold the database and uploads, not `config.php`. Our Microsoft 365 certificate, the mail
mailbox's Graph token and each person's Outlook sign-in are encrypted (AES-256-GCM) with
`$config_mit_secret_key`, a random key ITFlow appends to `config.php` the first time it needs one
(older values made with a key from `$installation_id` are still read, and re-encrypted with the new
key by the Outlook cron job). So after restoring onto a **new** server, copy `$config_mit_secret_key`
(plus `$installation_id` and `$config_backup_key`) from the old server's `config.php` into the new
one - **keep it with the backup key**. Without it the Microsoft 365 card says the certificate
cannot be read (Remove and set it up again, then reconnect the mailbox), and everyone's Outlook
calendar page asks them to Reconnect. Restoring onto the same server needs nothing.

A backup restored with `scripts/restore_cli.php` or the setup wizard gets our database updates
straight after (a stock ITFlow backup, or one from an older build of ours, needs them).

**Restoring on a running install (26.10.108):** Admin > Backup > Restore on a stored backup, or upload a .zip - up to 5 GB
(26.10.110: the page sends it in 32 MB pieces, so PHP's upload limit and a proxy's request limit don't matter; keep the page open
until it finishes). Free disk: about 3 x the backup's size (the "Before restore" copy, the unpacked archive, the import). A big
restore takes minutes - if the page stops waiting (a proxy timeout), the restore carries on; sign in again and Admin > Backup shows
how it went. Bigger than 5 GB: copy the file to the server and use `scripts/restore_cli.php`.

**Temporary files (26.10.111).** Backups and restores unpack in PHP's temporary folder. On Debian 13 `/tmp` is a RAM disk of half
the memory (`df -h /tmp` shows `tmpfs`), too small for a big backup - a restore failed with "Could not extract the archive: No
error". Restores from Admin > Backup now unpack next to the backups (`uploads/backups/mit_tmp`). New servers from
`scripts/install.sh` also send ITFlow's own backups and the command line restore to `/var/lib/itflow-tmp`; on a server installed
before 26.10.111 do the same once:

    sudo install -d -m 0700 -o www-data -g www-data /var/lib/itflow-tmp
    for s in apache2 cli; do echo 'sys_temp_dir = /var/lib/itflow-tmp' | sudo tee -a /etc/php/*/$s/conf.d/99-itflow.ini; done
    sudo systemctl restart apache2 It shows what is in the archive, asks for your password and
`RESTORE`, makes a Full "Before restore" backup of the system as it is (restore that to undo), restores, runs ITFlow's and
our database updates and signs everyone out. Do it when nobody is working - it takes a minute or more and the system is
being replaced meanwhile. A backup from another server needs that server's backup key, and its connection secrets
(Microsoft 365, NinjaOne, QuickBooks, Bitwarden, Ingram) need entering again unless `$config_mit_secret_key` is copied too.

**A backup from stock ITFlow** (the original itflow-org install) restores the same way: ITFlow v26.08 or newer makes the
same encrypted archive (older: update that server first). Upload it in Admin > Backup, type **that server's** backup key
(its Admin > Backup page shows it) in the Restore window, and sign in afterwards with that server's accounts. ITFlow's and our
database updates run straight after (tested with a stock v26.10 backup, database 2.7.9).

**Development mode (26.10.108) - for a copy of production on a test server.** Admin > Backup > Development mode: switch it on
and give a test inbox **before** restoring production's backup there. It is kept in `config.php` (`$config_mit_dev_mode`,
`$config_mit_dev_mail_to`), so the restore can't switch it off. While it is on:
- every email (ticket replies, invoices, reminders, alerts) goes to the test inbox instead, its subject saying who it was for;
- the mailbox is not read (the copy would take production's new mail), the Microsoft 365 certificate is not renewed, Stripe is
  switched off, custom actions do nothing;
- every integration is **read-only**: NinjaOne, Bitwarden and Ingram Micro still sync into this server and Outlook busy times
  are still read in, but nothing done here is created, changed or deleted in another system - also when a button is pressed by
  hand (Microsoft 365 card, Outlook disconnect); the only other way out is the redirected mail to the test inbox;
- **QuickBooks Online works with an Intuit sandbox company only.** Production's connection comes with the backup but is not used
  (QuickBooks pages say so). To test batching: Admin > Integrations > QuickBooks Online > Disconnect (only forgets it here - your
  real connection keeps working), set Environment to Sandbox, Connect and pick your sandbox company (add the dev server's
  `/admin/mit_qbo_callback.php` address to the Intuit app's Development redirect URIs, and use its Development keys). This clears
  the batches and mappings copied from production on the dev server; map again and batch away. QuickBooks' invoice emails go to
  the test inbox;
- a red DEVELOPMENT badge shows in the top bar.
Never switch it on on production. Webhooks and anything set up outside ITFlow (n8n, uptime monitors) are not covered.

**What the stored Microsoft tokens can do.** The mailbox token (Settings > Mail) can read and send
mail as that mailbox; each person's Outlook token can read their calendar's free/busy and write the
events ITFlow puts there. Anyone with the database *and* `$config_mit_secret_key` could use them, so
treat a backup plus `config.php` like a password. To cut access, remove the app in Settings > Mail
(Microsoft 365 card > Remove) or the person's connection in Account > Outlook calendar - or delete
the app registration in Entra.

## Moving to stock ITFlow (backup portability)

We keep this build so that a backup made here restores into ITFlow's own release and keeps
working – so going back to itflow-org's `master` is always possible. Tested Oct 2026 (again on Oct 21, 26.10.96): a
full backup of `develop` restored into stock v26.10 and into upstream's newer code (database 2.8.9 updated to 2.9.3);
every main page loaded and new clients, tickets and replies saved without errors.

**Check it at any time:** `sudo -u www-data php scripts/mit_portability_check.php` – compares the
live database with ITFlow's schema (`db.sql`), exits 1 and prints `FAIL` lines if a backup would
break stock ITFlow, and lists what stops working after a move.

**To move:**

1. Take a full backup (Admin > Backup) and note the backup key shown there.
2. Install stock ITFlow (its own installer) on the target server – **install first, then
   restore**: stock v26.10's command-line restore fails on an empty database.
3. Copy the archive over and restore:
   `sudo -u www-data php scripts/restore_cli.php --file=/path/to/backup.zip --key=THE_KEY`
4. If the stock code is newer than the backup: `sudo -u www-data php scripts/update_cli.php --update_db`.
   If the backup is from a database newer than the stock release (e.g. beta while it ran
   upstream's unreleased work), prefer the stock release that has caught up.

**What stops working on stock ITFlow** (and comes back if you move back to this build):

- **Microsoft 365 email** – ours signs in with a certificate and sends/receives through Microsoft
  Graph. On stock ITFlow add a client secret to the app in Entra, paste it under Settings > Mail >
  OAuth, and switch SMTP AUTH / IMAP back on for the mailbox.
- **Outlook calendars** stop syncing; events ITFlow already put in people's Outlook stay until
  deleted (they carry the *ITFlow* category).
- **California time records** – start / meal / end times, meal answers, change history and signed attestations stay in
  the `mit_ts_*` tables but are not shown; stock ITFlow has no California overtime or premiums. Export the payroll detail
  report first, and keep the tables (California asks for at least 3 years of time records).
- **NinjaOne** – the sync, the mapping page and the NinjaOne buttons go (the mapping and device links stay in the
  `mit_ninja_*` tables). The assets it made are ordinary ITFlow assets and stay, with the `[NinjaOne Sync]` lines in
  their notes - so the n8n workflow picks them up again if you go back to it.
- **QuickBooks Online** – disconnect it first (Admin > Integrations > QuickBooks Online). Stock ITFlow has no invoice
  approvals, batches or QuickBooks link: invoices in batches unlock, ITFlow's own invoice emails, reminders, late fees and
  recurring auto-send come back, and payments made in QuickBooks stop coming back. Payments already brought back are
  ordinary ITFlow payments (method "QuickBooks Online") and stay; the approvals, batches and mappings stay unused in `mit_qbo_*` /
  `mit_invoice_approvals`.
- **Usage billing** – recurring invoices bill the fixed quantity on their lines again (update it by hand each month); the
  counts, `api/v1/mit_usage/` and Usage Review go (the data stays in `mit_usage_*`). Invoices already made keep their
  quantities. Systems reporting counts through the API get "resource not mapped" errors - switch them off. The usage
  connectors (NinjaOne, Bitwarden, Ingram Micro) stop too; their Bitwarden keys and Ingram login stay encrypted in
  `mit_usage_links` / `mit_settings` - delete those rows if you leave for good.
- **Email reply recipients** – an email reply goes to the contact and every watcher again, as stock ITFlow does; the
  per-ticket choices stay unused in `mit_ticket_mail_choices`.
- **Modern look** – pages (and the sign-in screen) go back to ITFlow's own look, without the client count tiles and header contact line; the Look
  choice stays unused in `mit_user_preferences`.
- **Version number** – the footer and Admin > Update show ITFlow's own release (26.10) again; `MIT_CHANGELOG.md` is ours.
- **Logo on its own** (Company Details > "The logo shows the company name") – the side menu shows the company name beside the
  logo again; the setting stays in `mit_settings`.
- **Project tickets** – they show on the Tickets board, the client's Tickets tab and the Dispatch queue again, and
  calendar bookings go back to the tech's color. They keep no SLA (ours took it off) - set one by hand on a ticket that
  needs it.
- **Cron alerts** – the top-bar warning, the alert emails and `/mit_health.php` go; Maintenance > Cron still shows
  ITFlow's own status. Point your uptime monitor elsewhere (it will report the address as gone).
- **Remembered Tickets filters** – stock ITFlow opens Tickets with its defaults every time (the saved
  views sit unused in `mit_user_preferences`).
- **Repeats by weekday** ("the third Wednesday", "the last Friday") – stock ITFlow repeats those
  recurring tickets and calendar events on the same date each month instead (the rule sits unused
  in `recurring_ticket_by_day` / `event_repeat_by_day`). Check and adjust their dates after a move.
- **Procurement** (items to order, purchase orders, approval, tracking, receiving) – not in stock
  ITFlow; its data stays in the `mit_procurement_items` / `mit_purchase_orders` / `mit_po_*` tables
  and PO PDFs in `uploads/mit_po/`. Admin > Roles keeps an unused "Procurement" permission (a row
  in ITFlow's `modules` table, `module_procurement`) – harmless; delete the row if wanted.
  Assets, product stock, ticket notes and vendor-bill expenses made when receiving are ordinary
  ITFlow records and stay; only the PO's link to them (and the order status shown on invoice lines
  and projects) goes.
- **Timesheets** – not in stock ITFlow. Timesheets, hours on charge codes and the codes stay in
  `mit_timesheets` / `mit_time_entries` / `mit_charge_codes` / `mit_timesheet_people` (schedules,
  approvers, employee IDs, hire dates), PTO / sick balances in `mit_leave_banks` / `mit_leave_ledger` /
  `mit_leave_rates`, settings in `mit_settings` (ts_*, including the paid-break rule; lunch is a charge code); export the Timesheets & Payroll
  report (both CSVs) before moving. Ticket time is ITFlow's own (ticket replies) and stays. Admin >
  Roles keeps an unused "Timesheets" permission (`module_timesheets` row) – harmless.
- **Customer Thermometer** – surveys stop going out and the Customer happiness cards and reviews list go away; the surveys and
  responses stay in `mit_ct_surveys` (and in Customer Thermometer itself). The ticket history lines it wrote stay.
- **Restore from Admin > Backup** – stock ITFlow restores only on a fresh install (setup wizard) or from the command line
  (`scripts/restore_cli.php`); nothing of ours is stored for it.
- **Development mode** – stock ITFlow ignores `$config_mit_dev_mode` / `$config_mit_dev_mail_to` in `config.php`: a test copy
  moved to stock code sends real mail and runs everything again. Delete the two lines.
- **Dispatch** – the page and its Workload view go away; it keeps nothing of its own (schedules and
  assignments it made are ordinary ITFlow ticket fields and stay). Use Calendar and each ticket's Schedule instead.
  Admin > Roles keeps an unused "Dispatch" permission (`module_dispatch` row, M035) – harmless; delete the row if wanted.
- **Module switches** for Procurement, Timesheets and Dispatch (Settings > Modules) – stored in
  `mit_settings` (`module_*`), unused by stock ITFlow.
- **Reimbursements** (mileage and purchases logged on tickets) – the ticket's Reimbursements box, the
  timesheet list and the payroll columns go; the entries stay in `mit_reimbursements` and receipts in
  `uploads/mit_receipts/`. Export the payroll report first. The ITFlow trips (mileage) and expenses
  (approved purchases) they made are ordinary ITFlow records and stay.
- **Ticket parts** – the Parts box is ours (`mit_ticket_parts`); parts already billed are ordinary
  invoice lines and stay. Products' "Order from" vendor (`products.product_vendor_id`) is ignored.
- **Additional agents on tickets** – stock ITFlow keeps only the assigned agent. The others, their
  own scheduled times and their calendar / Outlook entries are not shown (the rows sit unused
  in `mit_ticket_techs`); reassign or note them on the tickets that need it.
- **Times on ticket notes** – stock ITFlow keeps each note's length (time worked) but not when the work was done; the
  dates and start / end times sit unused in `mit_reply_times`. Ticket time stays on the ticket.
- **Service boards** – stock ITFlow has no boards: every ticket is in the one Tickets list again
  (filter by category instead), the Board line, Escalate, routing rules and board notifications stop
  (the rows sit unused in `mit_boards` / `mit_ticket_boards` / `mit_board_rules` / `mit_board_members`).
  Categories are ITFlow's and stay; escalation reasons stay as internal notes. Round-robin auto-assign stops (new
  tickets stay unassigned), and the Board Performance report, the board move log (`mit_board_moves`) and the
  dashboard's board filter go; assignments it made are ordinary ITFlow assignments and stay. Boards by role go too
  (`mit_role_board_access` / `mit_role_boards`): every role sees every ticket again (ITFlow's own role permissions and
  client access stay as they are).
- **Quote pricing and item images** – stock ITFlow keeps every quote's customer prices, but our
  cost, the markup / margin and the line and product images are not shown (they sit unused in
  the `item_cost` / `item_price_*` / `item_image` and `product_cost` / `product_price_*` /
  `product_image` columns, the files in `uploads/mit_images/`). Our API leaves product cost and
  markup out of `api/v1/products/read.php`; stock ITFlow's API returns every column, so after a move
  an API key with Sales access can read them – clear them first if that matters.
- **Ticket page layout** (column widths, three columns, box order, half-width and collapsed boxes, reply box open on Public note) – stock ITFlow shows its own layout;
  the saved layouts sit unused in `mit_settings` / `mit_user_preferences`.
- **Schedule lengths, agent colors, per-person calendar settings, Outlook busy times, the ticket
  timers dropdown, the description's Read tick** – not in stock ITFlow; their data sits unused in `mit_` tables and the
  `tickets.ticket_schedule_end` column.

## Updating

**Always take a backup first** (Admin > Backup, and keep a copy off the server). If a
database update fails part-way, re-running it usually stops on the part that already ran,
and recovery means restoring the backup or repairing the database by hand.

**Production** – manual, when you choose: Admin > Update > Update. To run it from the shell
instead (useful if the web update reports a problem):

```bash
cd /var/www/itflow/scripts && sudo -u www-data php update_cli.php
```

Don't run the shell update while an update queued from the web page is still in progress.

**Beta** – may update itself nightly. Don't call `update_cli.php` straight from crontab: it
swaps the files while the every-minute cron jobs may be running. Instead queue the update
the same way the Update button does, and let the cron dispatcher apply it safely. Create
`/var/www/itflow/scripts/custom/queue_update.php` (the `*/custom/` folders other than
`agent/custom` are not tracked by git, so updates leave it alone; `scripts/` is not reachable
from the web):

```php
<?php
// Queue an ITFlow update for the cron dispatcher - same as Admin > Update > Update.
if (php_sapi_name() !== 'cli') { exit(1); }
require __DIR__ . '/../../config.php';
mysqli_query($mysqli, "UPDATE settings SET config_update_queued_at = NOW() WHERE company_id = 1");
mysqli_query($mysqli, "UPDATE cron_jobs SET cron_job_run_now = 1 WHERE cron_job_name = 'app_update'");
```

```bash
sudo chown www-data:www-data /var/www/itflow/scripts/custom/queue_update.php
```

Then add to `www-data`'s crontab (03:15 nightly):

```
15 3 * * * php /var/www/itflow/scripts/custom/queue_update.php
```

The result appears in Maintenance > Cron (Application Update) and Admin > Update.

**What an update keeps and replaces:** the update hard-resets every file tracked by git.
Untracked files are kept: `config.php`, `uploads/`, and the `custom/` folders – **except
`agent/custom/`**, whose `index.php`, `post.php` and `includes/` are tracked and will be
overwritten. Don't edit tracked files on a server; make changes in this repository instead.

**Browser caching:** CSS and JavaScript files are not versioned, so after an update ask users
to hard-refresh (Ctrl+F5) if a page looks or behaves oddly.

## If the repository is ever made private again (deploy keys)

The repository is public, so servers clone and update over HTTPS with no credentials.
If it is made private, each server needs its own read-only **deploy key**, and its `origin`
must be switched to `git@github.com:MountaineerIT/ITFLOW.git`
(`sudo -u www-data git -C /var/www/itflow remote set-url origin git@github.com:MountaineerIT/ITFLOW.git`).

`/var/www` is owned by root, so create the `.ssh` folder as root first, then generate the
key as `www-data`:

```bash
sudo install -d -o www-data -g www-data -m 700 /var/www/.ssh
sudo -u www-data ssh-keygen -t ed25519 -N "" -C "itflow-$(hostname)" -f /var/www/.ssh/id_ed25519
sudo -u www-data cat /var/www/.ssh/id_ed25519.pub
sudo -u www-data ssh -o StrictHostKeyChecking=accept-new -T git@github.com   # accepts GitHub's host key
```

The last command should say "successfully authenticated" once the key has been added.

On GitHub: **Repository > Settings > Deploy keys > Add deploy key**, paste the public key,
name it after the server, and leave **Allow write access unchecked**.
To retire a server, delete its key there.

## Pulling in ITFlow's updates

Ask Claude Code (with this repository attached): *"Check ITFlow for updates and bring them in."*
It will fetch the latest **official release** from itflow-org, summarise what changed
(including any database updates), merge it into `develop` and, once you confirm, push it.
The beta server picks it up; test there (including the smoke tests below), then ask Claude to open the
`develop` → `MountaineerIT` PR.

## California timesheets

Hourly people are on California non-exempt rules unless switched off in Admin > Timesheets > People, from the day the
database update (M018) runs - earlier periods keep their hours as they were. They type start,
meal and end times each day, answer for a missed, late or short meal period, and sign a statement when they submit.
The payroll report adds double time and meal / rest premium hours. Have an employment lawyer check the statement
wording (`mitTsCaStatement` in `functions/mit_ts_california.php`) and the rules before relying on them.

## Remote people's time zones

ITFlow has one company time zone (Admin > Settings > Localization). Someone working elsewhere can have their own for
their timesheet - Admin > Timesheets > People, or Account > Preferences: their "today" and the day their ticket time
falls on follow their clock. Everything else (tickets, the calendar, emails) stays in company time. A change counts from
then on (after any timesheet already submitted or approved) and is logged, so hours already handed in don't move.

## Cron alerts

Admin > Maintenance > Cron has a **Cron alerts** card. Admins get a warning next to the bell, and an email,
when a cron job fails, gets stuck or falls behind. An email can't report that cron itself has stopped, so
copy the card's **health address** into an uptime monitor (Uptime Kuma, UptimeRobot...) and have it check
every 5 minutes. It answers `OK`, or HTTP 503 when something is wrong. Do this on production and beta.
If the monitor can send a header, send the key as `X-Health-Key: <key>` and leave `?key=` off the address, so the
key does not end up in the web server's access log.

## NinjaOne

Admin > Integrations > NinjaOne. In NinjaOne make an API client app (Administration > Apps > API > Client app IDs: API
Services (machine-to-machine), scope Monitoring, client credentials) and put its ID and secret, your region and your own
NinjaOne address (for the buttons) on the Settings tab. Then on Mapping either **Import from the n8n workflow** (its JSON
file - takes over its organization -> client list) or pick a client per organization, and **Run sync now**. Read the run's
report; when it looks right, switch the hourly sync on and **turn the n8n workflow off** (both would edit the same
assets). It finds assets n8n made by the NinjaOne device ID in their notes, so the switch makes no duplicates.

## Project tickets

Admin > Settings > Project > Project tickets (on by default). A ticket in a project is kept apart from service
tickets: off the Tickets board (its own **Projects** view there; the Project filter still finds them), the client's
Tickets tab and the Dispatch queue, and with no SLA - the project's due dates count. Techs still see their own in
My tickets on the dashboard, the Dispatch workload, and on the calendar and Dispatch, where project bookings are in
the project color (set on the same card) with a dot in the tech's color. On the calendar they come and go with the **Projects**
tick box (with the project start / due dates), not Scheduled tickets. A ticket taken out of its project gets its
SLA back counted from that moment. The cron job "Project Ticket SLAs" (every minute) catches tickets that join or
leave a project through the API or a deleted project.

## Version numbers

Our build is numbered ITFlow's release + an iteration: **26.10.1**, 26.10.2 ... (`includes/mit_version.php`). It goes up by
one with every push to `develop`, so beta always shows exactly which build it runs, and production shows the build it was
promoted from. When an ITFlow release is merged it starts again at .1 (26.11.1). Each version has release notes in
`MIT_CHANGELOG.md`, shown under Admin > Update; the admin footer shows the version too. ITFlow's own `CHANGELOG.md` and
`includes/app_version.php` are left as ITFlow ships them.

## Smoke tests

`tests/smoke/` holds browser tests that click through the app on the **beta** install: every menu
page, a client → ticket → note → booking round trip, our own features, and a Technician's
permissions - about a minute. Setup and use are in `tests/smoke/README.md` (Node, two test-only
logins without 2FA, a `.env`). Run them after every ITFlow update and before promoting to
production. Never point them at production: they add and delete a test client.

## Rules for our own changes

- Changes are committed straight to `develop` and tested on the beta server – no PRs needed.
- Nothing is pushed or merged into `MountaineerIT` except a reviewed PR from `develop`.
- Don't modify `libs/` (third-party code).
- Keep edits to upstream files small – every changed line can conflict when ITFlow releases
  are merged in. Prefer the `*/custom/` directories where they fit.
- **Our database changes** go in `admin/database_updates/mountaineerit/` (M001, M002 …), never in
  ITFlow's numbered `admin/database_updates/` – those would collide with their next release.
- **Stay portable to stock ITFlow** (see "Moving to stock ITFlow"): our database changes only
  *add* – new `mit_` tables, or columns on ITFlow's tables that are NULL-able or have a default.
  Never rename, drop or change ITFlow's columns, change what ITFlow's data means, or point ITFlow's
  tables at ours. Run `scripts/mit_portability_check.php` after every change that touches the
  database and after every ITFlow update; before promoting to production, also restore a backup
  into stock ITFlow and click through it. When a feature adds something that would stop working
  after a move, add it to the list in that section.
