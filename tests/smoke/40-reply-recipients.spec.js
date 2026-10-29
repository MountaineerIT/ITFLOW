// Email reply recipients, checked in the data underneath (functions/mit_reply_recipients.php): exactly who is queued an email,
// what is remembered per ticket (mit_ticket_mail_choices), the next reply starting from it, a ticket moved to another client
// forgetting it (and the old client's watcher). Internal notes tell agents with ITFlow's own @mentions (26.10.196 - our Notify
// line is gone): the mention, its bell and email, beside our reply box. Two clients of its own, removed at the end.

const { test, expect } = require('@playwright/test');
const { smokeName, watch, visit, openModal, fillForm, submit, flash, action, expectClean } = require('./helpers');
const db = require('./db');

test.describe.configure({ mode: 'serial' });

const stamp = Date.now();
const made = { clientId: 0, client2Id: 0, ticketId: 0, number: '', adminName: null };
const cara = `cara-${stamp}@example.invalid`;
const otto = `otto-${stamp}@example.invalid`;
const watcher = `watch-${stamp}@example.invalid`;
const typed = `typed-${stamp}@example.invalid`;
const ticketPage = () => `agent/ticket.php?client_id=${made.clientId}&ticket_id=${made.ticketId}`;
const queued = () => db.column(`SELECT email_recipient FROM email_queue WHERE email_subject LIKE ${db.q(`%[${made.number}]%`)} AND email_subject LIKE 'Ticket update%' ORDER BY email_recipient`);
const clearQueue = () => db.run(`DELETE FROM email_queue WHERE email_subject LIKE ${db.q(`%[${made.number}]%`)}`);

async function emailReply(page, text, pick) {
    await visit(page, ticketPage());
    await page.click('label[for=public_reply_type_opt2]');
    const select = page.locator('#mitSendTo select');
    await expect(page.locator('#mitSendTo')).toBeVisible();
    const before = await select.evaluate((el) => [...el.selectedOptions].map((o) => o.value).sort());
    if (pick) {
        await select.evaluate((el, pick) => {
            for (const email of pick) {
                if (!el.querySelector(`option[value="${email}"]`)) {
                    el.add(new Option(email, email)); // a typed address, as the picker's tags add it
                }
            }
            for (const o of el.options) {
                o.selected = pick.includes(o.value);
            }
            el.dispatchEvent(new Event('change', { bubbles: true }));
        }, pick);
    }
    const form = page.locator('form:has(#replyComposer)');
    await fillForm(form, { ticket_reply: text });
    await submit(form, 'add_ticket_reply');
    return before;
}

test('reply recipients: setup - two clients, contacts, a ticket with a watcher', async ({ page }) => {
    for (const [key, name] of [['clientId', smokeName('t1 reply client')], ['client2Id', smokeName('t1 reply other')]]) {
        db.run(`INSERT INTO clients SET client_name = ${db.q(name)}, client_currency_code = 'USD'`);
        made[key] = Number(db.value(`SELECT client_id FROM clients WHERE client_name = ${db.q(name)}`));
    }
    db.run(`INSERT INTO contacts SET contact_name = 'Cara Contact', contact_email = '${cara}', contact_primary = 1, contact_client_id = ${made.clientId}`);
    db.run(`INSERT INTO contacts SET contact_name = 'Otto Other', contact_email = '${otto}', contact_primary = 0, contact_client_id = ${made.clientId}`);
    db.run(`INSERT INTO contacts SET contact_name = 'New Nina', contact_email = 'nina-${stamp}@example.invalid', contact_primary = 1, contact_client_id = ${made.client2Id}`);
    const caraId = db.value(`SELECT contact_id FROM contacts WHERE contact_email = '${cara}'`);

    await visit(page, `agent/client_overview.php?client_id=${made.clientId}`);
    const modal = await openModal(page, `modals/ticket/ticket_add.php?client_id=${made.clientId}`, 'lg');
    await fillForm(modal, { subject: smokeName('t1 reply ticket'), details: 'Reply recipients test', priority: 'Low', contact_id: caraId, assigned_to: '2' });
    await submit(modal, 'add_ticket');
    made.ticketId = Number(new URL(page.url()).searchParams.get('ticket_id'));
    expect(made.ticketId, await flash(page)).toBeGreaterThan(0);
    const t = db.rows(`SELECT CONCAT(ticket_prefix, ticket_number) AS ref, ticket_contact_id, ticket_assigned_to FROM tickets WHERE ticket_id = ${made.ticketId}`)[0];
    made.number = t.ref;
    expect(t.ticket_contact_id).toBe(caraId);
    expect(t.ticket_assigned_to).toBe('2');
    db.run(`INSERT INTO ticket_watchers SET watcher_email = '${watcher}', watcher_ticket_id = ${made.ticketId}`);
    clearQueue();
});

test('reply recipients: only the people in To are emailed, and the choice is remembered', async ({ page }) => {
    test.skip(!made.ticketId, 'no ticket');
    const errors = watch(page);
    // The watcher taken out, the other contact and a typed address added
    const before = await emailReply(page, `Smoke reply one ${stamp}`, [cara, otto, typed]);
    expect(before, 'To starts with the contact and the watcher').toEqual([cara, watcher].sort());
    expect(queued(), 'queued to exactly those picked').toEqual([cara, otto, typed].sort());
    // ITFlow 3.4.8: the reply keeps who it was emailed to - our To line records it as ITFlow's own send does
    expect(await flash(page)).toContain('emailed to 3 people');
    const reply = db.rows(`SELECT ticket_reply_id, ticket_reply_emailed, ticket_reply_emailed_to FROM ticket_replies WHERE ticket_reply_ticket_id = ${made.ticketId} AND ticket_reply LIKE '%Smoke reply one ${stamp}%'`)[0];
    expect(reply.ticket_reply_emailed, 'marked emailed').toBe('1');
    expect(JSON.parse(reply.ticket_reply_emailed_to).map((r) => r.email).sort(), 'who it went to').toEqual([cara, otto, typed].sort());
    expect(db.value(`SELECT COUNT(*) FROM email_queue WHERE email_ticket_reply_id = ${reply.ticket_reply_id}`), 'each email carries the reply').toBe('3');
    await expect(page.locator('.badge', { hasText: 'Email reply' }).first()).toBeVisible();
    // ITFlow's "emailed to" line opens its list of recipients inside our ticket layout
    const emailedLine = page.locator(`a.ticket-reply-emailed[href="#replyEmailed${reply.ticket_reply_id}"]`);
    await expect(emailedLine).toBeVisible();
    await emailedLine.click();
    const panel = page.locator(`#replyEmailed${reply.ticket_reply_id}`);
    await expect(panel).toBeVisible();
    for (const email of [cara, otto, typed]) {
        await expect(panel).toContainText(email);
    }
    const choices = Object.fromEntries(db.rows(`SELECT email, send FROM mit_ticket_mail_choices WHERE ticket_id = ${made.ticketId}`).map((r) => [r.email, r.send]));
    expect(choices, 'remembered where it differs from the default').toEqual({ [watcher]: '0', [otto]: '1', [typed]: '1' });
    const body = db.value(`SELECT email_content FROM email_queue WHERE email_recipient = '${otto}' AND email_subject LIKE ${db.q(`%[${made.number}]%`)}`);
    expect(body, 'the reply text is in the email').toContain(`Smoke reply one ${stamp}`);
    const history = db.column(`SELECT ticket_history_description FROM ticket_history WHERE ticket_history_ticket_id = ${made.ticketId}`).join(' | ');
    expect(history).toContain('Reply emailed to');
    expect(history).not.toContain(watcher);
    clearQueue();

    // The next reply starts from the remembered choice and, unchanged, goes to the same people
    const again = await emailReply(page, `Smoke reply two ${stamp}`, null);
    expect(again, 'starts from the remembered choice').toEqual([cara, otto, typed].sort());
    expect(queued()).toEqual([cara, otto, typed].sort());
    clearQueue();

    // Nobody ticked: the reply box refuses to send it (an alert), nothing is posted
    await visit(page, ticketPage());
    await page.click('label[for=public_reply_type_opt2]');
    await page.locator('#mitSendTo select').evaluate((el) => {
        for (const o of el.options) {
            o.selected = false;
        }
        el.dispatchEvent(new Event('change', { bubbles: true }));
    });
    await fillForm(page.locator('form:has(#replyComposer)'), { ticket_reply: `Smoke reply three ${stamp}` });
    let alerted = '';
    page.once('dialog', async (d) => { alerted = d.message(); await d.dismiss(); });
    await page.click('#ticket_add_reply');
    await page.waitForTimeout(800);
    expect(alerted, 'refused with a message').toContain('Add at least one person to To');
    expect(db.value(`SELECT COUNT(*) FROM ticket_replies WHERE ticket_reply_ticket_id = ${made.ticketId} AND ticket_reply LIKE '%Smoke reply three ${stamp}%'`)).toBe('0');
    await expectClean(page, errors);
});

test('reply recipients: the To list closes when you click into the reply box (ITFlow\'s and our closing together)', async ({ page }) => {
    test.skip(!made.ticketId, 'no ticket');
    const errors = watch(page);
    await visit(page, ticketPage());
    await page.click('label[for=public_reply_type_opt2]');
    const wrap = page.locator('#mitSendTo .is-open');
    await page.locator('#mitSendTo [aria-expanded]').first().click();
    await expect(wrap, 'the To list opens').toHaveCount(1);
    await page.frameLocator('#replyComposer iframe').first().locator('body').click();
    await expect(wrap, 'and closes on a click into the editor').toHaveCount(0);
    await expectClean(page, errors);
});

test('reply recipients: a ticket moved to another client forgets the choices and the old client\'s watcher', async ({ page }) => {
    test.skip(!made.ticketId, 'no ticket');
    db.run(`INSERT INTO ticket_watchers SET watcher_email = 'tech@test.test', watcher_ticket_id = ${made.ticketId}`); // an agent stays
    await visit(page, ticketPage());
    const modal = await openModal(page, `modals/ticket/ticket_change_client.php?ticket_id=${made.ticketId}`);
    await fillForm(modal, { new_client_id: String(made.client2Id) });
    await submit(modal, 'change_client_ticket');
    expect(db.value(`SELECT ticket_client_id FROM tickets WHERE ticket_id = ${made.ticketId}`), await flash(page)).toBe(String(made.client2Id));
    expect(db.value(`SELECT COUNT(*) FROM mit_ticket_mail_choices WHERE ticket_id = ${made.ticketId}`), 'remembered choices gone').toBe('0');
    expect(db.column(`SELECT watcher_email FROM ticket_watchers WHERE watcher_ticket_id = ${made.ticketId}`), 'old client\'s watcher gone, the agent kept').toEqual(['tech@test.test']);
    // Back to the first client for the rest
    const back = await openModal(page, `modals/ticket/ticket_change_client.php?ticket_id=${made.ticketId}`);
    await fillForm(back, { new_client_id: String(made.clientId), new_contact_id: db.value(`SELECT contact_id FROM contacts WHERE contact_email = '${cara}'`) });
    await submit(back, 'change_client_ticket');
});

test('internal note: ITFlow\'s @mention tells the technician (bell, email) - our Notify line is gone', async ({ page }) => {
    test.skip(!made.ticketId, 'no ticket');
    await visit(page, ticketPage());
    await page.click('label[for=public_reply_type_opt0]');
    await expect(page.locator('#mitNotify'), 'no Notify line of ours').toHaveCount(0);
    const offered = JSON.parse((await page.locator('#ticket_reply').getAttribute('data-mention-agents')) || '[]').map((a) => String(a.id));
    expect(offered, 'the assigned technician can be mentioned').toContain('2');
    const tech = db.value('SELECT user_name FROM users WHERE user_id = 2');
    const body = page.frameLocator('#replyComposer iframe').first().locator('body');
    await body.click();
    await page.keyboard.type(`Smoke note for tech ${stamp} `);
    await page.keyboard.type('@' + tech.slice(0, 4), { delay: 80 });
    await expect(page.locator('.tox-collection__item', { hasText: tech }).first(), 'the @ list').toBeVisible();
    await page.keyboard.press('Enter');
    await expect(body.locator('.itflow-mention[data-mention="2"]')).toHaveCount(1);
    await submit(page.locator('form:has(#replyComposer)'), 'add_ticket_reply');
    const reply = db.value(`SELECT ticket_reply_id FROM ticket_replies WHERE ticket_reply_ticket_id = ${made.ticketId} AND ticket_reply LIKE ${db.q(`%Smoke note for tech ${stamp}%`)}`);
    expect(reply, 'the note saved').toBeTruthy();
    expect(db.column(`SELECT mention_user_id FROM ticket_reply_mentions WHERE mention_reply_id = ${reply}`), 'the mention kept with it').toEqual(['2']);
    expect(db.value(`SELECT COUNT(*) FROM notifications WHERE notification_user_id = 2 AND notification LIKE '%mentioned you%${made.number}%'`), 'a bell').toBe('1');
    expect(db.value(`SELECT COUNT(*) FROM email_queue WHERE email_recipient = 'tech@test.test' AND email_subject LIKE ${db.q(`%Mentioned on ticket [${made.number}]%`)}`), 'and an email').toBe('1');
    expect(db.value(`SELECT COUNT(*) FROM email_queue WHERE email_subject LIKE ${db.q(`Internal note for you - [${made.number}]%`)}`), 'nothing from our old Notify').toBe('0');
});

test.afterAll(async ({ browser }) => {
    if (made.adminName) {
        db.run(`UPDATE users SET user_name = ${db.q(made.adminName)} WHERE user_id = 1`);
    }
    const use = test.info().project.use;
    const context = await browser.newContext({ storageState: use.storageState, baseURL: use.baseURL });
    const page = await context.newPage();
    if (made.ticketId) {
        await action(page, `delete_ticket=${made.ticketId}`, ticketPage()).catch(() => {});
        db.run(`DELETE FROM email_queue WHERE email_subject LIKE ${db.q(`%[${made.number}]%`)}`);
        db.run(`DELETE FROM notifications WHERE notification LIKE ${db.q(`%${made.number} %`)}`);
    }
    for (const id of [made.clientId, made.client2Id].filter(Boolean)) {
        await action(page, `delete_client=${id}`, `agent/client_overview.php?client_id=${id}`).catch(() => {});
    }
    await context.close();
});
