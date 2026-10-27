// Cron health on ITFlow's pages (functions/mit_cron_health.php): a job that failed shows the warning next to the bell for admins
// (includes/mit_cron_nav.php in ITFlow's top bar), and Maintenance > Cron carries our alerts card. One job is marked Failed for the
// test and put back as it was.

const { test, expect } = require('@playwright/test');
const { watch, visit, expectClean } = require('./helpers');
const db = require('./mitdb');

const made = { job: null };

test.afterAll(() => {
    if (made.job) {
        db.exec(`UPDATE cron_jobs SET cron_job_last_status = ${made.job.cron_job_last_status === null ? 'NULL' : db.q(made.job.cron_job_last_status)},
            cron_job_last_error = ${made.job.cron_job_last_error === null ? 'NULL' : db.q(made.job.cron_job_last_error)},
            cron_job_last_error_at = ${made.job.cron_job_last_error_at === null ? 'NULL' : db.q(made.job.cron_job_last_error_at)}
            WHERE cron_job_id = ${made.job.cron_job_id}`);
    }
});

test('a failed cron job: the warning beside the bell, and the alerts card on Maintenance > Cron', async ({ page }) => {
    const errors = watch(page);
    made.job = db.one("SELECT cron_job_id, cron_job_last_status, cron_job_last_error, cron_job_last_error_at FROM cron_jobs WHERE cron_job_enabled = 1 ORDER BY cron_job_id LIMIT 1");
    test.skip(!made.job, 'no cron jobs here');
    db.exec(`UPDATE cron_jobs SET cron_job_last_status = 'Failed', cron_job_last_error = 'Smoke test failure', cron_job_last_error_at = NOW() WHERE cron_job_id = ${made.job.cron_job_id}`);
    await visit(page, 'agent/dashboard.php', errors);
    const warning = page.locator('#mitCronHealth');
    await expect(warning, 'the warning beside the bell').toHaveCount(1);
    await expect(warning.locator('.dropdown-menu')).toContainText('Smoke test failure');

    await visit(page, 'admin/cron.php', errors);
    await expect(page.locator('button[name=mit_edit_cron_alerts]'), 'our alerts card').toHaveCount(1);
    await expectClean(page, errors);
});
