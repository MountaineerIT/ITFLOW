// An agent's own time zone is ITFlow's since 26.12 (functions/user_timezone.php - "My time zone" in Account > Preferences,
// user_settings.user_config_timezone; the calendar, Dispatch and booking emails on their clock - built from ours). Ours on top
// (functions/mit_timesheets.php mitTsSaveZone, one line in ITFlow's timezoneSave): the timesheet follows it - the person's timesheet zone
// (mit_timesheet_people.time_zone) and a dated change (mit_ts_zone_changes), so ticket time can't move in or out of a period handed in;
// back on the company's clock clears it. The admin's zone is put back.
const { test, expect } = require('@playwright/test');
const { watch, visit, fillForm, submit, expectClean } = require('./helpers');
const { sql, one, php } = require('./t2db');

const ZONE = 'Asia/Tokyo';

test('"My time zone" in Preferences: the timesheet follows it', async ({ page }) => {
    const errors = watch(page);
    const company = php("echo date_default_timezone_get();").trim();
    test.skip(company === ZONE, 'the company is in Tokyo already');
    const before = one('SELECT user_config_timezone FROM user_settings WHERE user_id = 1');
    const changes = Number(one('SELECT COUNT(*) FROM mit_ts_zone_changes WHERE user_id = 1'));
    const save = async (zone) => {
        await visit(page, 'agent/user/user_preferences.php', errors);
        const form = page.locator('form:has([name="edit_your_user_preferences"])');
        await fillForm(form, { timezone: zone });
        await submit(form, 'edit_your_user_preferences');
    };
    try {
        await save(ZONE);
        expect(one('SELECT user_config_timezone FROM user_settings WHERE user_id = 1'), 'ITFlow\'s own field saved').toBe(ZONE);
        expect(one('SELECT time_zone FROM mit_timesheet_people WHERE user_id = 1'), 'the timesheet zone follows').toBe(ZONE);
        expect(Number(one('SELECT COUNT(*) FROM mit_ts_zone_changes WHERE user_id = 1')), 'the change recorded').toBeGreaterThan(changes);
        await visit(page, 'agent/mit_timesheet.php', errors);
        await expect(page.locator('#mitTsZone'), 'the timesheet says which clock').toContainText('Tokyo');

        await save('');
        expect(one('SELECT user_config_timezone FROM user_settings WHERE user_id = 1'), 'the company\'s again').toBeNull();
        expect(one('SELECT time_zone FROM mit_timesheet_people WHERE user_id = 1'), 'and the timesheet\'s').toBeNull();
        await expectClean(page, errors);
    } finally {
        if (before) {
            await save(before).catch(() => {});
        } else {
            sql('UPDATE user_settings SET user_config_timezone = NULL WHERE user_id = 1');
            sql('UPDATE mit_timesheet_people SET time_zone = NULL WHERE user_id = 1');
        }
    }
});
