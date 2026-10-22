<?php

/*
 * ITFlow - The cron job registry
 *
 * The list of jobs cron/cron.php knows how to run, and the schedule each one ships with.
 * Adding a job is a script in cron/ and an entry here - the crontab never changes.
 *
 * Schedules here are DEFAULTS. They seed the job's row in the cron_jobs table the first
 * time the dispatcher sees it, and from then on the row is what runs: Maintenance > Cron
 * writes to it. Changing a default in this file therefore only affects installs that have
 * not met the job yet.
 *
 * This file is the only thing that decides which scripts can be run. The database holds
 * when and whether, never what - a row naming a script that is not listed here is ignored,
 * so nothing that reaches the database can point the dispatcher at an arbitrary file.
 *
 * Loaded from both sides: cron/cron.php requires it on the command line under system cron,
 * and Maintenance > Cron requires it in a web request. Nothing in here may touch $_SERVER,
 * $_SESSION or any other superglobal - there is no DOCUMENT_ROOT, no session and no request
 * when cron runs it.
 *
 * That shared use is why this sits here rather than in cron/includes/ with the lock, which
 * only cron loads: the admin pages would otherwise be reaching into the cron directory.
 *
 * 'enabled' => 0 ships a job switched off - the row is seeded disabled and stays that way
 * until somebody turns it on in Maintenance > Cron. Used for work an install should opt into
 * rather than inherit from an upgrade, like the backup job filling a disk overnight.
 *
 * 'interval_safe' => false marks a job whose work repeats if the day repeats - nightly's
 * late fees and overdue reminders fire again on a second run of the same day. Maintenance >
 * Cron only offers the daily schedule for such a job, and the dispatcher refuses to run
 * one on an interval whatever its row says.
 *
 * Every job here checks config_enable_cron in its own header and stops itself when that
 * switch is off. It is not a dispatcher-level gate - a new job has to make the check
 * itself, and a job that skips it will keep running on an install that thinks cron is off.
 *
 * Order matters: the dispatcher works down this list. app_update replaces the files every
 * job is loaded from, so it stays at the end and ends the cycle behind itself.
 */

function cronJobRegistry(): array
{
    return [
        [
            'name' => 'mail_queue',
            'label' => 'Mail Queue',
            'script' => 'mail_queue.php',
            'description' => 'Sends everything ITFlow has queued - invoices, quotes, ticket replies, notifications.',
            'schedule' => 'Interval',
            'interval_minutes' => 1,
        ],
        [
            'name' => 'ticket_email_parser',
            'label' => 'Ticket Email Parser',
            'script' => 'ticket_email_parser.php',
            'description' => 'Reads the support mailbox and turns incoming mail into tickets and replies.',
            'schedule' => 'Interval',
            'interval_minutes' => 1,
        ],
        [
            'name' => 'ticket_sla',
            'label' => 'Ticket SLA Monitor',
            'script' => 'ticket_sla.php',
            'description' => 'Moves tickets through their SLA warning and breach stages and sends the alerts.',
            'schedule' => 'Interval',
            'interval_minutes' => 1,
        ],
        [
            'name' => 'domain_refresher',
            'label' => 'Domain Refresher',
            'script' => 'domain_refresher.php',
            'description' => 'Looks up DNS, registration and expiry for domains that are due - never looked up, not in a week, or expiring within 60 days. Up to 50 a run.',
            'schedule' => 'Daily',
            'daily_at' => '04:00',
        ],
        [
            'name' => 'nightly_tasks',
            'label' => 'Nightly Tasks',
            'script' => 'nightly_tasks.php',
            'description' => 'The daily run: recurring invoices and tickets, overdue reminders, autopay, late fees, clean-up, update check.',
            'schedule' => 'Daily',
            'daily_at' => '03:00',
            'interval_safe' => false,
        ],
        [
            'name' => 'backup',
            'label' => 'Backup',
            'script' => 'backup.php',
            'description' => 'Builds the scheduled backup and anything queued from Maintenance > Backup. Off by default.',
            'schedule' => 'Daily',
            'daily_at' => '02:00',
            'enabled' => 0,
            'interval_safe' => false,
        ],
        [
            'name' => 'certificate_refresher',
            'label' => 'Certificate Refresher',
            'script' => 'certificate_refresher.php',
            'description' => 'Checks the host of every certificate once a day - what it serves now, its expiry and issuer, and whether it is OK, for another name, untrusted, expired or unreachable.',
            'schedule' => 'Daily',
            'daily_at' => '03:30',
        ],
        [
            'name' => 'update_check',
            'label' => 'Update Check',
            'script' => 'update_check.php',
            'description' => 'Asks the git remote whether a newer release exists and stores the answer for Maintenance > Update.',
            'schedule' => 'Daily',
            'daily_at' => '02:30',
        ],
        [
            // Mountaineer IT (functions/mit_outlook.php)
            'name' => 'mit_outlook_sync',
            'label' => 'Outlook Calendar Sync',
            'script' => 'mit_outlook_sync.php',
            'description' => 'Puts each agent\'s scheduled tickets in the Outlook calendar they connected under Account > Outlook calendar, and puts back events changed there.',
            'schedule' => 'Interval',
            'interval_minutes' => 1,
        ],
        [
            // Mountaineer IT (functions/mit_timesheets.php)
            'name' => 'mit_timesheets',
            'label' => 'Timesheets',
            'script' => 'mit_timesheets.php',
            'description' => 'Timesheet reminders, and PTO / sick leave earned and carried over, as set in Admin > Timesheets.',
            'schedule' => 'Interval',
            'interval_minutes' => 60,
        ],
        [
            // Mountaineer IT (functions/mit_procurement_parts.php)
            'name' => 'mit_procurement_tidy',
            'label' => 'Procurement Tidy-up',
            'script' => 'mit_procurement_tidy.php',
            'description' => 'Cancels items to order for deleted tickets and clients, and frees ticket parts whose invoice line was deleted.',
            'schedule' => 'Daily',
            'daily_at' => '04:15',
        ],
        [
            // Mountaineer IT (functions/mit_ninjaone.php)
            'name' => 'mit_ninjaone_sync',
            'label' => 'NinjaOne Sync',
            'script' => 'mit_ninjaone_sync.php',
            'description' => 'Brings NinjaOne devices in as assets of the ITFlow clients they are mapped to (Admin > Integrations > NinjaOne). Does nothing until it is set up and switched on there.',
            'schedule' => 'Interval',
            'interval_minutes' => 60,
        ],
        [
            // Mountaineer IT (functions/mit_usage.php)
            'name' => 'mit_usage',
            'label' => 'Usage Counts',
            'script' => 'mit_usage.php',
            'description' => 'Counts each client\'s contacts carrying the tag of a usage-billed recurring invoice line, and reads the usage connectors (NinjaOne, Bitwarden, Ingram Micro - Admin > Integrations > Usage connectors), so the snapshot day has a count to bill.',
            'schedule' => 'Daily',
            'daily_at' => '00:30',
        ],
        [
            // Mountaineer IT (functions/mit_ct.php)
            'name' => 'mit_customer_thermometer',
            'label' => 'Customer Thermometer',
            'script' => 'mit_customer_thermometer.php',
            'description' => 'Sends a Customer Thermometer survey for each newly resolved ticket on the chosen service boards and reads the responses back. Does nothing until it is set up and switched on (Admin > Integrations > Customer Thermometer).',
            'schedule' => 'Interval',
            'interval_minutes' => 5,
        ],
        [
            // Mountaineer IT (functions/mit_qbo_billing.php)
            'name' => 'mit_qbo_sync',
            'label' => 'QuickBooks Online Payments',
            'script' => 'mit_qbo_sync.php',
            'description' => 'Brings payments made in QuickBooks Online back to the ITFlow invoices sent there, so balances and statuses follow. Does nothing until QuickBooks Online is connected (Admin > Integrations).',
            'schedule' => 'Interval',
            'interval_minutes' => 60,
        ],
        [
            // Mountaineer IT (functions/mit_projects.php)
            'name' => 'mit_project_tickets',
            'label' => 'Project Ticket SLAs',
            'script' => 'mit_project_tickets.php',
            'description' => 'Takes the SLA off tickets that joined a project and gives it back to ones that left, for the ways in and out that do not do it at once (the API, deleting a project).',
            'schedule' => 'Interval',
            'interval_minutes' => 1,
        ],
        [
            // Mountaineer IT (functions/mit_boards.php)
            'name' => 'mit_boards',
            'label' => 'Service Boards',
            'script' => 'mit_boards.php',
            'description' => 'Puts tickets made by the portal, the API, recurring tickets and projects on their board by the routing rules (tickets from email and New Ticket get theirs at once).',
            'schedule' => 'Interval',
            'interval_minutes' => 1,
        ],
        [
            // Mountaineer IT (functions/mit_cron_health.php)
            'name' => 'mit_cron_watch',
            'label' => 'Cron Watch',
            'script' => 'mit_cron_watch.php',
            'description' => 'Emails the admins when a job here fails, gets stuck or falls behind, and when it is fixed.',
            'schedule' => 'Interval',
            'interval_minutes' => 5,
        ],
        [
            'name' => 'app_update',
            'label' => 'Application Update',
            'script' => 'app_update.php',
            'description' => 'Runs an update queued from Maintenance > Update. Does nothing unless one is queued.',
            'schedule' => 'Daily',
            'daily_at' => '05:00',
            'enabled' => 0,
            'interval_safe' => false,
        ],
    ];
}

/*
 * The registry keyed by job name, for looking up what a cron_jobs row belongs to.
 */
function cronJobRegistryByName(): array
{
    $jobs = [];

    foreach (cronJobRegistry() as $job) {
        $jobs[$job['name']] = $job;
    }

    return $jobs;
}

/*
 * How a schedule reads in the admin UI: "Every minute", "Every 5 minutes", "Daily at 03:00".
 */
function cronJobScheduleDescription(string $schedule, int $interval_minutes, ?string $daily_at): string
{
    if ($schedule === 'Daily') {
        return 'Daily at ' . substr((string)$daily_at, 0, 5);
    }

    if ($interval_minutes === 1) {
        return 'Every minute';
    }

    if ($interval_minutes === 60) {
        return 'Hourly';
    }

    return "Every $interval_minutes minutes";
}

/*
 * When a job is next expected to run, or null when it is disabled or nothing is scheduled.
 * Interval jobs that are already overdue read as due now rather than as a time in the past.
 */
function cronJobNextRun(array $row): ?string
{
    if (empty($row['cron_job_enabled'])) {
        return null;
    }

    $last_run = $row['cron_job_last_run_at'];

    if ($row['cron_job_schedule'] === 'Daily') {
        // The most recent scheduled occurrence: today's, or yesterday's if today's has not
        // arrived yet - matching how the dispatcher decides due-ness
        $occurrence = date('Y-m-d') . ' ' . substr((string)$row['cron_job_daily_at'], 0, 5) . ':00';
        if ($occurrence > date('Y-m-d H:i:s')) {
            $occurrence = date('Y-m-d', strtotime('-1 day')) . ' ' . substr((string)$row['cron_job_daily_at'], 0, 5) . ':00';
        }

        if ($last_run === null || $last_run < $occurrence) {
            return date('Y-m-d H:i:s');
        }

        return date('Y-m-d H:i:s', strtotime($occurrence) + 86400);
    }

    if ($last_run === null) {
        return date('Y-m-d H:i:s');
    }

    $next = strtotime($last_run) + (max(1, intval($row['cron_job_interval_minutes'])) * 60);

    return date('Y-m-d H:i:s', max($next, time()));
}

/*
 * "4 minutes ago" / "in 2 minutes" for the admin page. Anything older than a day reads as a
 * date, because "27,000 minutes ago" tells nobody anything.
 */
function cronJobTimeAgo(?string $datetime): string
{
    if (empty($datetime)) {
        return 'Never';
    }

    $seconds = time() - strtotime($datetime);
    $ahead = $seconds < 0;
    $seconds = abs($seconds);

    if ($seconds < 60) {
        $text = 'less than a minute';
    } elseif ($seconds < 3600) {
        $minutes = floor($seconds / 60);
        $text = $minutes . ' minute' . ($minutes == 1 ? '' : 's');
    } elseif ($seconds < 86400) {
        $hours = floor($seconds / 3600);
        $text = $hours . ' hour' . ($hours == 1 ? '' : 's');
    } else {
        return date('M j, g:i A', strtotime($datetime));
    }

    return $ahead ? "in $text" : "$text ago";
}
