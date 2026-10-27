// ITFlow's Trips refuse to change mileage that belongs to a timesheet (agent/post/trip.php hooks, functions/mit_reimbursements.php):
//  - a trip made by mileage logged on a ticket (mit_reimbursements.trip_id) is changed and removed there, not in Trips;
//  - an own-vehicle trip on a day of the driver's submitted timesheet can't be logged, changed or deleted, nor moved onto one.
// Uses the technician's own timesheet for a past period (made submitted here, put back after) and trips of its own.

const { test, expect } = require('@playwright/test');
const { smokeName, visit, csrf } = require('./helpers');
const db = require('./mitdb');

test.describe.configure({ mode: 'serial' });

const DAY = '2026-08-05'; // inside the 2026-08-01 - 08-15 period (semi-monthly) - a past one, so never the current sheet
const OPEN_DAY = '2026-07-20';
const made = { tech: 0, sheet: 0, sheetBefore: null, lockedTrip: 0, reimbTrip: 0, reimb: 0, purpose: smokeName('trip lock') };

const trip = (id) => db.one(`SELECT trip_date, trip_miles, trip_user_id, trip_archived_at FROM trips WHERE trip_id = ${id}`);
const tripCount = () => Number(db.val(`SELECT COUNT(*) FROM trips WHERE trip_purpose = ${db.q(made.purpose)}`));

async function postTrip(page, fields) {
    const token = await csrf(page);
    return db.pagePost(page, '/agent/post.php', { csrf_token: token, ...fields });
}
async function deleteTrip(page, id) {
    const token = await csrf(page);
    return db.pageGet(page, `/agent/post.php?delete_trip=${id}&csrf_token=${encodeURIComponent(token)}`);
}
const tripFields = (overrides) => ({
    date: DAY, source: 'Office', destination: 'Client site', purpose: made.purpose, miles: '12', driver: String(made.tech), own_vehicle: '1', ...overrides,
});

test.afterAll(() => {
    db.exec(`DELETE FROM mit_reimbursements WHERE reimb_id = ${made.reimb || 0}`);
    db.exec(`DELETE FROM trips WHERE trip_purpose = ${db.q(made.purpose)}`);
    if (made.sheet) {
        if (made.sheetBefore === null) {
            db.exec(`DELETE FROM mit_timesheets WHERE sheet_id = ${made.sheet}`);
        } else {
            db.exec(`UPDATE mit_timesheets SET status = ${db.q(made.sheetBefore)} WHERE sheet_id = ${made.sheet}`);
        }
    }
});

test('setup: a submitted timesheet day and trips on it', async ({ page }) => {
    made.tech = Number(db.val("SELECT user_id FROM users WHERE user_email = 'tech@test.test'") || 0);
    test.skip(!made.tech, 'no technician on this install');
    test.skip(db.val("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mit_reimbursements'") !== '1', 'timesheets update not run');
    const existing = db.one(`SELECT sheet_id, status FROM mit_timesheets WHERE user_id = ${made.tech} AND period_start = '2026-08-01'`);
    if (existing) {
        made.sheet = Number(existing.sheet_id);
        made.sheetBefore = existing.status;
        db.exec(`UPDATE mit_timesheets SET status = 'submitted' WHERE sheet_id = ${made.sheet}`);
    } else {
        made.sheet = db.insert(`INSERT INTO mit_timesheets SET user_id = ${made.tech}, period_start = '2026-08-01', period_end = '2026-08-15', status = 'submitted', submitted_at = NOW()`);
    }
    made.lockedTrip = db.insert(`INSERT INTO trips SET trip_date = '${DAY}', trip_purpose = ${db.q(made.purpose)}, trip_source = 'Office', trip_destination = 'Client site',
        trip_miles = 12, round_trip = 0, trip_own_vehicle = 1, trip_user_id = ${made.tech}, trip_created_by = 1`);
    made.reimbTrip = db.insert(`INSERT INTO trips SET trip_date = '${OPEN_DAY}', trip_purpose = ${db.q(made.purpose)}, trip_source = 'Office', trip_destination = 'Client site',
        trip_miles = 8, round_trip = 0, trip_own_vehicle = 0, trip_user_id = ${made.tech}, trip_created_by = 1`);
    made.reimb = db.insert(`INSERT INTO mit_reimbursements SET user_id = ${made.tech}, entry_date = '${OPEN_DAY}', kind = 'mileage', description = ${db.q(made.purpose)},
        miles = 8, trip_id = ${made.reimbTrip}`);
    expect(made.lockedTrip && made.reimbTrip && made.reimb).toBeTruthy();
});

test('an own-vehicle trip on a submitted timesheet day: no logging, changing or deleting it in Trips', async ({ page }) => {
    test.skip(!made.lockedTrip, 'no setup');
    await visit(page, 'agent/trips.php');
    const before = tripCount();
    await postTrip(page, { add_trip: '1', ...tripFields() });
    expect(tripCount(), 'a new own-vehicle trip on the locked day is refused').toBe(before);

    await postTrip(page, { edit_trip: '1', trip_id: String(made.lockedTrip), ...tripFields({ miles: '99' }) });
    expect(Number(trip(made.lockedTrip).trip_miles), 'changing it is refused').toBe(12);

    await deleteTrip(page, made.lockedTrip);
    const after = trip(made.lockedTrip);
    expect(after && !after.trip_archived_at, 'deleting it is refused').toBeTruthy();

    // Nor can an open day's own-vehicle trip be moved onto the locked day
    await postTrip(page, { edit_trip: '1', trip_id: String(made.reimbTrip), ...tripFields({ date: DAY }) });
    expect(trip(made.reimbTrip).trip_date, 'not moved onto a submitted day').toBe(OPEN_DAY);
});

test('a trip made by mileage logged on a ticket: changed and removed there, not in Trips', async ({ page }) => {
    test.skip(!made.reimbTrip, 'no setup');
    await visit(page, 'agent/trips.php');
    await postTrip(page, { edit_trip: '1', trip_id: String(made.reimbTrip), ...tripFields({ date: OPEN_DAY, miles: '77', own_vehicle: '0' }) });
    expect(Number(trip(made.reimbTrip).trip_miles), 'its miles stay').toBe(8);
    await deleteTrip(page, made.reimbTrip);
    const after = trip(made.reimbTrip);
    expect(after && !after.trip_archived_at, 'it is still there').toBeTruthy();
});
