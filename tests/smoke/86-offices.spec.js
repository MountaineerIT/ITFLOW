// Offices (functions/mit_offices.php): Admin > Settings > Company Details lists the main office (the company above) and an added
// one with who works there; the office window saves its address, phone and people. Admin > Users > Edit moves an agent there.
// A new trip starts From the driver's office and follows the driver; a ticket email's foot shows the assigned agent's office
// (the main one keeps the company's); a new PO ships to its maker's office and its Ship to picks any office. Everything it makes
// is removed and people put back in their offices afterwards.

const { test, expect } = require('@playwright/test');
const { smokeName, watch, visit, expectClean, openModal, fillForm, submit } = require('./helpers');
const db = require('./mitdb');

test.describe.configure({ mode: 'serial' });

const N = { office: smokeName('Medford office') };
const made = { office: 0, offices: {}, ticket: 0, po: 0, item: 0 };
const ADMIN = 1;
const TECH = 2;

test.beforeAll(() => {
    test.skip(!db.val("SHOW TABLES LIKE 'mit_user_offices'"), 'offices are not set up here (M048)');
    for (const row of db.rows('SELECT user_id, office_id FROM mit_user_offices')) {
        made.offices[row.user_id] = row.office_id;
    }
    // The main office needs an address to start trips from (the test install's company may have none) - put back afterwards
    made.company = db.one('SELECT company_address, company_city, company_state, company_zip FROM companies WHERE company_id = 1');
    if (!made.company.company_address) {
        db.exec("UPDATE companies SET company_address = '1 Main Smoke Street', company_city = 'Yreka', company_state = 'CA', company_zip = '96097' WHERE company_id = 1");
    }
});

test.afterAll(() => {
    for (const user of [made.invitedUser].filter(Boolean)) {
        db.exec(`DELETE FROM mit_timesheet_people WHERE user_id = ${user}; DELETE FROM user_settings WHERE user_id = ${user}; DELETE FROM logs WHERE log_user_id = ${user};
            DELETE FROM notifications WHERE notification_entity_id = ${user} AND notification_type = 'User'; UPDATE user_invites SET invite_user_id = 0 WHERE invite_user_id = ${user}; DELETE FROM users WHERE user_id = ${user}`);
    }
    if (made.inviteEmail) {
        db.exec(`DELETE FROM user_invites WHERE invite_email = ${db.q(made.inviteEmail)}`);
    }
    if (made.newUser) {
        db.exec(`DELETE FROM user_settings WHERE user_id = ${made.newUser}; DELETE FROM logs WHERE log_user_id = ${made.newUser}; DELETE FROM users WHERE user_id = ${made.newUser}`);
    }
    if (made.company && !made.company.company_address) {
        db.exec(`UPDATE companies SET company_address = ${db.q(made.company.company_address || '')}, company_city = ${db.q(made.company.company_city || '')},
            company_state = ${db.q(made.company.company_state || '')}, company_zip = ${db.q(made.company.company_zip || '')} WHERE company_id = 1`);
    }
    if (made.task) {
        db.exec(`DELETE FROM task_approvals WHERE approval_task_id = ${made.task}; DELETE FROM tasks WHERE task_id = ${made.task}`);
    }
    if (made.contact) {
        db.exec(`DELETE FROM contacts WHERE contact_id = ${made.contact}`);
    }
    if (made.ticket) {
        db.exec(`DELETE FROM ticket_history WHERE ticket_history_ticket_id = ${made.ticket}; DELETE FROM tickets WHERE ticket_id = ${made.ticket}`);
    }
    if (made.po) {
        db.exec(`DELETE FROM mit_po_history WHERE po_id = ${made.po}; DELETE FROM mit_po_lines WHERE po_id = ${made.po}; DELETE FROM mit_purchase_orders WHERE po_id = ${made.po}`);
    }
    if (made.item) {
        db.exec(`DELETE FROM mit_procurement_items WHERE item_id = ${made.item}`);
    }
    db.exec(`DELETE FROM mit_offices WHERE office_name = ${db.q(N.office)}`);
    db.exec('DELETE FROM mit_user_offices');
    for (const [user, office] of Object.entries(made.offices)) {
        db.exec(`INSERT IGNORE INTO mit_user_offices SET user_id = ${user}, office_id = ${office}`);
    }
});

test('Company Details: add an office with its people; Admin > Users moves an agent there', async ({ page }) => {
    const errors = watch(page);
    await visit(page, 'admin/settings_company.php', errors);
    const section = page.locator('#section-mit-offices');
    await expect(section, 'an Offices section').toBeVisible();
    await expect(section.locator('[data-mit-office="0"]'), 'the main office - the company above').toContainText('Main office');

    await section.locator('[data-mit-office-add]').click();
    const modal = page.locator('.modal.show').last();
    await modal.locator('[data-mit-office-form]').waitFor();
    await page.waitForTimeout(300);
    await fillForm(modal, {
        office_name: N.office, office_address: '200 Smoke Street', office_city: 'Medford', office_state: 'OR', office_zip: '97501',
        office_phone: '541-555-0100', office_email: 'medford@example.com', 'office_people[]': [String(ADMIN)],
    });
    await submit(modal, 'mit_office_save');
    const office = db.one(`SELECT office_id, office_phone, office_email, office_city FROM mit_offices WHERE office_name = ${db.q(N.office)}`);
    made.office = Number(office.office_id);
    expect([office.office_phone, office.office_email, office.office_city], 'saved - the phone as digits').toEqual(['5415550100', 'medford@example.com', 'Medford']);
    expect(Number(db.val(`SELECT office_id FROM mit_user_offices WHERE user_id = ${ADMIN}`)), 'the admin works there now').toBe(made.office);
    const row = page.locator(`#section-mit-offices [data-mit-office="${made.office}"]`);
    await expect(row, 'listed with its people').toContainText('Test Admin');
    await expect(row).toContainText('200 Smoke Street');

    // Admin > Users > Edit: the Office picker
    const user = await openModal(page, `modals/user/user_edit.php?id=${TECH}`, 'lg');
    const pick = user.locator('[name="mit_office_id"]');
    await expect(pick, 'an Office picker in the user window').toHaveCount(1);
    await expect(pick).toHaveValue('0');
    await fillForm(user, { mit_office_id: String(made.office) });
    await submit(user, 'edit_user');
    expect(Number(db.val(`SELECT office_id FROM mit_user_offices WHERE user_id = ${TECH}`)), 'the technician moved').toBe(made.office);

    // New User: the office is saved with the new login
    made.newEmail = `smoke86-${Date.now()}@example.com`;
    const add = await openModal(page, 'modals/user/user_add.php', 'lg');
    const role = await add.locator('[name="role"] option').evaluateAll((options) => (options.find((o) => o.value && o.value !== '0') || {}).value);
    await fillForm(add, { name: 'Smoke 86 new agent', email: made.newEmail, role, sign_in: 'password', password: 'Smoke86-pass-2026!', mit_office_id: String(made.office) });
    await submit(add, 'add_user');
    made.newUser = Number(db.val(`SELECT user_id FROM users WHERE user_email = ${db.q(made.newEmail)}`));
    expect(made.newUser, 'the new login was made').toBeGreaterThan(0);
    expect(Number(db.val(`SELECT office_id FROM mit_user_offices WHERE user_id = ${made.newUser}`)), 'in the office picked').toBe(made.office);
    await expectClean(page, errors);
});

test('New User by invite: the office and employment picked go on the login when they join (ITFlow 3.5.1 invites)', async ({ page, browser }) => {
    test.skip(!db.val("SHOW TABLES LIKE 'mit_invite_extras'"), 'no agent invites here (ITFlow 3.5.1 / M049)');
    const errors = watch(page);
    await visit(page, 'admin/users.php', errors);
    made.inviteEmail = `smoke86-invite-${Date.now()}@example.com`;
    const add = await openModal(page, 'modals/user/user_add.php', 'lg');
    const role = await add.locator('[name="role"] option').evaluateAll((options) => (options.find((o) => o.value && o.value !== '0') || {}).value);
    const payType = await add.locator('[name="mit_pay_type"]').count() ? { mit_pay_type: 'contractor' } : {};
    await fillForm(add, { name: 'Smoke 86 invited agent', email: made.inviteEmail, role, sign_in: 'invite', mit_office_id: String(made.office), ...payType });
    await submit(add, 'add_user');
    const invite = Number(db.val(`SELECT invite_id FROM user_invites WHERE invite_email = ${db.q(made.inviteEmail)} ORDER BY invite_id DESC LIMIT 1`));
    expect(invite, 'an invite, not a login yet').toBeGreaterThan(0);
    expect(Number(db.val(`SELECT COUNT(*) FROM users WHERE user_email = ${db.q(made.inviteEmail)}`))).toBe(0);
    expect(db.val(`SELECT extras FROM mit_invite_extras WHERE invite_id = ${invite}`), 'our fields kept with it').toContain(`"mit_office_id":"${made.office}"`);

    // The link from the invite email (or the one shown to pass on), opened signed out
    const html = db.val(`SELECT REPLACE(email_content, '\\n', ' ') FROM email_queue WHERE email_recipient = ${db.q(made.inviteEmail)} ORDER BY email_id DESC LIMIT 1`) || '';
    const link = (html.match(/join\.php\?invite=\d+&(?:amp;)?token=[A-Za-z0-9_%-]+/) || [''])[0].replace('&amp;', '&');
    test.skip(!link, 'no invite email queued (mail not set up here)');
    const use = test.info().project.use;
    const context = await browser.newContext({ baseURL: use.baseURL, ignoreHTTPSErrors: use.ignoreHTTPSErrors, storageState: { cookies: [], origins: [] } });
    const join = await context.newPage();
    await join.goto(link);
    await join.locator('[name="new_password"]').fill('Smoke86-join-2026!x');
    await join.locator('[name="confirm_password"]').fill('Smoke86-join-2026!x');
    await Promise.all([join.waitForLoadState('load'), join.getByRole('button', { name: 'Join' }).click()]);
    await context.close();
    made.invitedUser = Number(db.val(`SELECT user_id FROM users WHERE user_email = ${db.q(made.inviteEmail)}`));
    expect(made.invitedUser, 'they joined').toBeGreaterThan(0);
    expect(Number(db.val(`SELECT office_id FROM mit_user_offices WHERE user_id = ${made.invitedUser}`)), 'in the office picked on New User').toBe(made.office);
    if (payType.mit_pay_type) {
        expect(db.val(`SELECT pay_type FROM mit_timesheet_people WHERE user_id = ${made.invitedUser}`), 'with the employment picked').toBe('contractor');
    }
    expect(Number(db.val(`SELECT COUNT(*) FROM mit_invite_extras WHERE invite_id = ${invite}`)), 'and what was kept is done with').toBe(0);
    db.exec(`DELETE FROM email_queue WHERE email_recipient = ${db.q(made.inviteEmail)}`);
    await expectClean(page, errors);
});

test('a new trip starts from the driver\'s office and follows the driver', async ({ page }) => {
    const errors = watch(page);
    const officeLine = db.php(`echo mitOfficeAddressLine(mitOffice(${made.office}));`).trim();
    const mainLine = db.php('echo mitOfficeAddressLine(mitOfficeMain());').trim();
    // The technician goes back to the main office, so there is a driver there to switch to
    db.exec(`DELETE FROM mit_user_offices WHERE user_id = ${TECH}`);
    await visit(page, 'agent/trips.php', errors);
    const modal = await openModal(page, 'modals/trip/trip_add.php', 'lg');
    const from = modal.locator('[data-trip-from]');
    await expect(from, 'From is the admin\'s office').toHaveValue(officeLine);
    const listed = await modal.locator('datalist option').evaluateAll((options) => options.map((o) => o.value));
    expect(listed, 'every office is offered').toEqual(expect.arrayContaining([officeLine, mainLine].filter(Boolean)));
    const drivers = await modal.locator('[data-trip-driver] option').evaluateAll((options) => options.map((o) => o.value));
    test.skip(!drivers.includes(String(TECH)), 'the technician is not a driver here');
    await modal.locator('[data-trip-driver]').evaluate((select, value) => {
        select.value = value;
        select.dispatchEvent(new Event('change', { bubbles: true }));
    }, String(TECH));
    await expect(from, 'a main-office driver starts from the main office').toHaveValue(mainLine);
    await expectClean(page, errors);
});

test('ticket emails: the foot shows the assigned agent\'s office', () => {
    const n = Number(db.val('SELECT COALESCE(MAX(ticket_number), 0) + 1 FROM tickets'));
    made.ticket = db.insert(`INSERT INTO tickets SET ticket_prefix = 'TCK-', ticket_number = ${n}, ticket_subject = 'Smoke 86', ticket_details = 'x', ticket_priority = 'Low',
        ticket_status = 1, ticket_client_id = 1, ticket_created_by = 1, ticket_assigned_to = ${ADMIN}, ticket_url_key = MD5(RAND())`);
    const mail = (assigned) => {
        db.exec(`UPDATE tickets SET ticket_assigned_to = ${assigned} WHERE ticket_id = ${made.ticket}`);
        return db.php(`$session_user_id = 0; echo emailHtml(ticketEmailContent(ticketRow(${made.ticket}), 'created', ['how' => 'agent']));`);
    };
    const office = mail(ADMIN);
    expect(office, "the office's street").toContain('200 Smoke Street');
    expect(office, "its phone").toMatch(/541\D*555\D*0100/);
    expect(office, 'and email').toContain('medford@example.com');
    // The queued "that ticket is closed" reply (ticketMailClosedReply) - through the mail queue
    const to = `smoke86-${Date.now()}@example.com`;
    const queued = db.php(`$session_user_id = 0; echo ticketMailClosedReply(${made.ticket}, ${JSON.stringify(to)});`).trim();
    if (queued === '1') {
        const html = db.val(`SELECT REPLACE(email_content, '\\n', ' ') FROM email_queue WHERE email_recipient = ${db.q(to)} ORDER BY email_id DESC LIMIT 1`);
        db.exec(`DELETE FROM email_queue WHERE email_recipient = ${db.q(to)}`);
        expect(html, 'the closed-ticket reply too').toContain('200 Smoke Street');
    }
    // A colleague who asked to hear of new tickets (ticketMailColleaguesNew), and a task waiting for their approval (ticketMailTaskApproval)
    const colleague = `smoke86c-${Date.now()}@example.com`;
    made.contact = db.insert(`INSERT INTO contacts SET contact_name = 'Smoke 86 colleague', contact_email = ${db.q(colleague)}, contact_client_id = 1, contact_technical = 1,
        contact_company_ticket_emails = 'new'`);
    made.task = db.insert(`INSERT INTO tasks SET task_name = 'Smoke 86 task', task_ticket_id = ${made.ticket}`);
    const approval = db.insert(`INSERT INTO task_approvals SET approval_scope = 'client', approval_type = 'technical', approval_status = 'pending', approval_created_by = 1,
        approval_url_key = MD5(RAND()), approval_task_id = ${made.task}`);
    db.php(`$session_user_id = 0; $config_ticket_client_general_notifications = 1; ticketMailColleaguesNew(${made.ticket}); ticketMailTaskApproval(${approval}, 'Smoke');`);
    const queuedFor = db.rows(`SELECT email_subject, IF(email_content LIKE '%200 Smoke Street%', 'office', 'company') AS foot FROM email_queue WHERE email_recipient = ${db.q(colleague)}`);
    db.exec(`DELETE FROM email_queue WHERE email_recipient = ${db.q(colleague)}`);
    expect(queuedFor.map((r) => r.foot), 'the new-ticket note to a colleague and the task approval carry the office foot').toEqual(['office', 'office']);
    const company = db.one('SELECT company_address FROM companies WHERE company_id = 1');
    const main = mail(TECH); // the technician is in the main office now
    expect(main, 'a main-office agent keeps the company foot').not.toContain('200 Smoke Street');
    if (company.company_address) {
        expect(main).toContain(company.company_address);
    }
});

test('purchase orders: a new one ships to its maker\'s office, Ship to picks another', async ({ page }) => {
    test.skip(!db.val("SHOW TABLES LIKE 'mit_purchase_orders'"), 'no procurement');
    const vendor = Number(db.val('SELECT vendor_id FROM vendors WHERE vendor_client_id = 0 AND vendor_archived_at IS NULL ORDER BY vendor_id LIMIT 1'));
    test.skip(!vendor, 'no vendor');
    made.item = db.insert(`INSERT INTO mit_procurement_items SET source = 'manual', client_id = 0, name = 'Smoke 86 part', quantity = 1, unit_cost = 5, vendor_id = ${vendor}, created_by = ${ADMIN}`);
    made.po = Number(db.php(`echo mitPoCreate([${made.item}], ${vendor});`).trim());
    const po = db.one(`SELECT ship_to, REPLACE(ship_to_address, '\\n', ' | ') AS ship_to_address FROM mit_purchase_orders WHERE po_id = ${made.po}`);
    expect(po.ship_to, "the admin's office").toBe(`office:${made.office}`);
    expect(po.ship_to_address).toContain('200 Smoke Street');

    const errors = watch(page);
    await visit(page, `agent/mit_purchase_order.php?po_id=${made.po}`, errors);
    await expect(page.locator('[data-mit-po-ship-office-name]'), 'the PO names the office').toHaveText(N.office);
    const modal = await openModal(page, `modals/mit_procurement/po_action.php?po_id=${made.po}&action=details`);
    const pick = modal.locator('[data-mit-po-ship-office]');
    await expect(pick).toHaveValue(String(made.office));
    await pick.selectOption('0');
    const mainShipTo = db.php('echo mitOfficeShipTo(0);').trim();
    await expect(modal.locator('[data-mit-po-ship-address]'), 'picking fills the address').toHaveValue(mainShipTo);
    await submit(modal, 'mit_po_details');
    const saved = db.one(`SELECT ship_to, REPLACE(ship_to_address, '\\n', ' | ') AS ship_to_address FROM mit_purchase_orders WHERE po_id = ${made.po}`);
    expect(saved.ship_to, 'the main office').toBe('office');
    expect(saved.ship_to_address.trim()).toBe(mainShipTo.replace(/\n/g, ' | '));
    await expectClean(page, errors);
});
