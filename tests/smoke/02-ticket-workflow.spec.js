// The everyday path through the forms a person uses: add a client, open a
// ticket for it, add an internal note with time, book it on the calendar, and
// see the booking on the calendar and Dispatch. Everything it makes is named
// "Smoke test ..." and deleted again at the end, even when a step fails.
// Nothing is emailed to a customer: the contact has no email address and the
// note is internal (the booking email goes to the agent it is booked for) - until the
// email-reply test gives it a reserved @example.invalid address, which never delivers.

const { test, expect } = require('@playwright/test');
const { smokeName, watch, visit, openModal, fillForm, submit, flash, action, fetchText, getJson, expectClean } = require('./helpers');

test.describe.configure({ mode: 'serial' });

const made = { clientName: smokeName('client'), clientId: 0, ticketId: 0 };

function tomorrow(hour) {
    const d = new Date(Date.now() + 86400000);
    const pad = (n) => String(n).padStart(2, '0');
    return { date: `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`, time: `${pad(hour)}:00` };
}

test('add a client', async ({ page }) => {
    await visit(page, 'agent/clients.php');
    const modal = await openModal(page, 'modals/client/client_add.php', 'lg');
    await fillForm(modal, { name: made.clientName, contact: 'Smoke Contact' });
    await submit(modal, 'add_client');

    await visit(page, `agent/clients.php?q=${encodeURIComponent(made.clientName)}`);
    const href = await page.locator('a[href*="client_id="]', { hasText: made.clientName }).first().getAttribute('href');
    made.clientId = Number(new URL(href, page.url()).searchParams.get('client_id'));
    expect(made.clientId, 'new client found in the list').toBeGreaterThan(0);
});

test('open a ticket for it', async ({ page }) => {
    test.skip(!made.clientId, 'no client');
    await visit(page, `agent/client_overview.php?client_id=${made.clientId}`);
    const modal = await openModal(page, `modals/ticket/ticket_add.php?client_id=${made.clientId}`, 'lg');
    await fillForm(modal, { subject: smokeName('ticket'), details: 'Opened by the smoke tests.', priority: 'Low' });
    await submit(modal, 'add_ticket');

    made.ticketId = Number(new URL(page.url()).searchParams.get('ticket_id'));
    expect(made.ticketId, `ticket page after saving (${page.url()} - ${await flash(page)})`).toBeGreaterThan(0);
    await expect(page.locator('body')).toContainText('Smoke test ticket');
});

test('add an internal note with time', async ({ page }) => {
    test.skip(!made.ticketId, 'no ticket');
    const errors = watch(page);
    await visit(page, `agent/ticket.php?client_id=${made.clientId}&ticket_id=${made.ticketId}`, errors);
    // As a person does: pick the internal note button, which opens the composer
    await page.click('label[for=public_reply_type_opt0]');
    await page.locator('#replyComposer.show').waitFor();
    const form = page.locator('form:has(#replyComposer)');
    await fillForm(form, { ticket_reply: 'Smoke test internal note', minutes: '15' });
    await submit(form, 'add_ticket_reply');
    await expect(page.locator('body')).toContainText('Smoke test internal note');
    await expectClean(page, errors);
});

test('a running ticket timer stops when the note is sent', async ({ page }) => {
    test.skip(!made.ticketId, 'no ticket');
    const errors = watch(page);
    const ticketPage = `agent/ticket.php?client_id=${made.clientId}&ticket_id=${made.ticketId}`;
    const id = String(made.ticketId);
    await visit(page, ticketPage, errors);
    // A timer that has been running five minutes
    await page.evaluate((id) => {
        localStorage.setItem(`${id}-startTime`, String(Date.now() - 5 * 60 * 1000));
        localStorage.removeItem(`${id}-pausedTime`);
    }, id);
    await visit(page, ticketPage, errors);
    await expect(page.locator('#minutes')).toHaveValue('05');
    await page.click('label[for=public_reply_type_opt0]');
    await page.locator('#replyComposer.show').waitFor();
    // Sent without ITFlow's click-then-100ms reset getting its turn (a quick server, Enter, a script)
    const form = page.locator('form:has(#replyComposer)');
    await fillForm(form, { ticket_reply: 'Smoke test timed note' });
    await Promise.all([page.waitForNavigation(), form.evaluate((f) => {
        window.tinymce && window.tinymce.triggerSave();
        f.requestSubmit(f.querySelector('#ticket_add_reply'));
    })]);
    await expectClean(page);
    await expect(page.locator('body')).toContainText('Smoke test timed note');
    expect(await page.evaluate((id) => localStorage.getItem(`${id}-startTime`), id), 'the timer is not running any more').toBeNull();
    await expect(page.locator('#minutes'), 'and is back at nothing').not.toHaveValue('05');
    await expectClean(page, errors);
});

test('internal note notifies the tech picked', async ({ page }) => {
    test.skip(!made.ticketId, 'no ticket');
    const errors = watch(page);
    const ticketPage = `agent/ticket.php?client_id=${made.clientId}&ticket_id=${made.ticketId}`;
    await visit(page, ticketPage, errors);
    await page.click('label[for=public_reply_type_opt0]');
    const line = page.locator('#mitNotify');
    await expect(line, 'the Notify line on internal notes').toBeVisible();
    await expect(page.locator('#mitSendTo'), 'not the email To line').toBeHidden();
    const agent = await line.locator('select option').first().evaluate((o) => ({ id: o.value, name: o.textContent.trim() })).catch(() => null);
    if (agent) {
        // Its list closes on a click in the note editor (TinyMCE's frame, which the page never sees)
        await line.locator('.itflow-select').click();
        await expect(page.locator('.itflow-select-panel')).toHaveCount(1);
        const editor = await page.locator('#replyComposer iframe').first().boundingBox();
        const panel = await page.locator('.itflow-select-panel').boundingBox();
        if (panel.y + panel.height + 10 < editor.y + editor.height) {
            await page.mouse.click(editor.x + editor.width - 20, editor.y + editor.height - 10); // clear of the open list
        } else {
            await page.frameLocator('#replyComposer iframe').first().locator('body').focus(); // a long list covers the editor
        }
        await expect(page.locator('.itflow-select-panel'), 'Notify list closed by a click in the editor').toHaveCount(0);
    }
    test.skip(!agent, 'no other agent who can see this client');
    const form = page.locator('form:has(#replyComposer)');
    await fillForm(form, { ticket_reply: 'Smoke test note for a tech', 'mit_notify[]': [agent.id] });
    await submit(form, 'add_ticket_reply');
    await visit(page, ticketPage, errors);
    const history = page.locator('.card', { has: page.locator('.card-title i.fa-history') });
    await expect(history).toContainText(`notified ${agent.name} about an internal note`);
    await expectClean(page, errors);
});

test('book it and see it on the calendar and Dispatch', async ({ page }) => {
    test.skip(!made.ticketId, 'no ticket');
    await visit(page, `agent/ticket.php?client_id=${made.clientId}&ticket_id=${made.ticketId}`);
    const when = tomorrow(10);
    const modal = await openModal(page, `modals/ticket/ticket_edit_schedule.php?ticket_id=${made.ticketId}`);
    await fillForm(modal, { scheduled_date_time: `${when.date}T${when.time}`, schedule_minutes: '60', onsite: '0' });
    await submit(modal, 'edit_ticket_schedule');
    expect(await flash(page)).not.toMatch(/error|could not|can't/i);

    // URLs below are relative to the page we are on (agent/)
    const items = await getJson(page, `ajax.php?calendar_items&from=${when.date}&to=${when.date}&sources=tickets`);
    const booking = items.find((item) => item.id === `ticket-${made.ticketId}`);
    expect(booking, 'the booking on the calendar').toBeTruthy();
    expect(booking.start).toContain(`${when.date} 10:00`);
    expect(booking.end, 'booking end time (Mountaineer IT calendar)').toContain(`${when.date} 11:00`);

    await visit(page, 'agent/calendar.php');
    const dispatch = await fetchText(page, `mit_dispatch_ajax.php?items&from=${when.date}&to=${when.date}&techs=`);
    if (dispatch.status === 200) { // 403 when Dispatch is off or not allowed
        expect(dispatch.text).not.toMatch(/Fatal error|Warning:/);
        expect(() => JSON.parse(dispatch.text)).not.toThrow();
    }
});

test('Dispatch Schedule dialog: the title is edited with the booking', async ({ page }) => {
    test.skip(!made.ticketId, 'no ticket');
    const ticketPage = `agent/ticket.php?client_id=${made.clientId}&ticket_id=${made.ticketId}`;
    await visit(page, ticketPage);
    const details = await fetchText(page, `mit_dispatch_ajax.php?details=${made.ticketId}`);
    test.skip(details.status !== 200, 'Dispatch is off or not allowed');
    const d = JSON.parse(details.text);
    expect(d.ticket.subject, 'the dialog gets the title').toBeTruthy();
    // The booking as it stands (tech and time), from ITFlow's own Schedule window
    const modal = await openModal(page, `modals/ticket/ticket_edit_schedule.php?ticket_id=${made.ticketId}`);
    const techId = await modal.locator('select[name=schedule_tech]').inputValue();
    const start = (await modal.locator('input[name=scheduled_date_time]').inputValue()).replace('T', ' ') + ':00';
    const csrf = await page.locator('input[name=csrf_token]').first().inputValue();
    const book = (subject) => page.evaluate(async (body) => {
        const response = await fetch('mit_dispatch_ajax.php', { method: 'POST', credentials: 'same-origin', body: new URLSearchParams(body) });
        return response.json();
    }, {
        book: 1, csrf_token: csrf, ticket_id: made.ticketId, tech_id: techId, start, minutes: 60, onsite: 0, contact: 0,
        detail_subject: subject, detail_client_id: d.ticket.client_id, detail_contact_id: d.ticket.contact_id, detail_priority: d.ticket.priority,
        detail_status: d.ticket.status_id, detail_category_id: d.ticket.category_id, detail_sla: d.ticket.sla_id,
    });
    const renamed = smokeName('renamed "ticket"');
    const saved = await book(renamed);
    expect(saved.error, 'booking with a new title').toBeFalsy();
    await visit(page, ticketPage);
    await expect(page.locator('body')).toContainText(renamed);
    // An empty title is refused, and nothing changes
    const refused = await book('   ');
    expect(refused.error).toContain('subject');
    await visit(page, ticketPage);
    await expect(page.locator('body')).toContainText(renamed);
});

test('email reply goes only to the people ticked, and the choice is remembered', async ({ page }) => {
    test.skip(!made.ticketId, 'no ticket');
    const errors = watch(page);
    const ticketPage = `agent/ticket.php?client_id=${made.clientId}&ticket_id=${made.ticketId}`;
    await visit(page, ticketPage, errors);
    // The contact needs an address for "Email reply" - a reserved one that never delivers
    const contactUrl = await page.locator('[data-modal-url*="modals/contact/contact.php?id="]').first().getAttribute('data-modal-url');
    const contactId = new URL(contactUrl, page.url()).searchParams.get('id');
    const modal = await openModal(page, `modals/contact/contact_edit.php?id=${contactId}`, 'lg');
    await fillForm(modal, { email: 'smoke-contact@example.invalid' });
    await submit(modal, 'edit_contact');
    await visit(page, ticketPage, errors);
    test.skip(await page.locator('label[for=public_reply_type_opt2]').count() === 0, 'mail is not set up on this install');

    await page.click('label[for=public_reply_type_opt2]');
    const box = page.locator('#mitSendTo');
    await expect(box, 'the To line').toBeVisible();
    const selected = () => box.locator('select').evaluate((el) => [...el.selectedOptions].map((o) => o.value));
    expect(await selected(), 'the contact starts in To').toEqual(['smoke-contact@example.invalid']);
    // As a person does: take the contact's chip out, type another address and add it
    await box.locator('.itflow-select').click();
    await page.keyboard.type('smoke-copy@example.invalid');
    await page.keyboard.press('Enter');
    await page.keyboard.press('Escape');
    await box.locator('select').evaluate((el) => {
        el.querySelector('option[value="smoke-contact@example.invalid"]').selected = false;
        el.dispatchEvent(new Event('change', { bubbles: true }));
    });
    expect(await selected()).toEqual(['smoke-copy@example.invalid']);
    const form = page.locator('form:has(#replyComposer)');
    await fillForm(form, { ticket_reply: 'Smoke test emailed reply' });
    await submit(form, 'add_ticket_reply');

    // Who it went to is in the ticket's history - only the address ticked
    await visit(page, ticketPage, errors);
    const history = page.locator('.card', { has: page.locator('.card-title i.fa-history') });
    await expect(history).toContainText('Reply emailed to smoke-copy@example.invalid');
    await expect(history).not.toContainText('smoke-contact@example.invalid');
    // Next time it starts from the same choice
    await page.click('label[for=public_reply_type_opt2]');
    expect(await selected(), 'To starts from the last choice').toEqual(['smoke-copy@example.invalid']);
    await expectClean(page, errors);
});

test('our boxes on the ticket page', async ({ page }) => {
    test.skip(!made.ticketId, 'no ticket');
    const errors = watch(page);
    await visit(page, `agent/ticket.php?client_id=${made.clientId}&ticket_id=${made.ticketId}`, errors);
    await page.waitForTimeout(500); // the layout script moves the boxes
    for (const icon of ['fa-microchip', 'fa-receipt']) {
        if (await page.locator(`.card-title i.${icon}, .card-header i.${icon}`).count() === 0) {
            test.info().annotations.push({ type: 'note', description: `${icon} box not shown (module off?)` });
        }
    }
    await expectClean(page, errors);
});

test.afterAll(async ({ browser }) => {
    const use = test.info().project.use;
    const context = await browser.newContext({ storageState: use.storageState, baseURL: use.baseURL, ignoreHTTPSErrors: use.ignoreHTTPSErrors });
    const page = await context.newPage();
    if (made.ticketId) {
        await action(page, `delete_ticket=${made.ticketId}`, `agent/ticket.php?client_id=${made.clientId}&ticket_id=${made.ticketId}`);
    }
    // This run's client, and any a failed earlier run left behind (deleting a client deletes its tickets)
    await page.goto('agent/clients.php?q=Smoke+test+client');
    const ids = new Set(made.clientId ? [made.clientId] : []);
    for (const href of await page.locator('a[href*="client_id="]', { hasText: /^Smoke test client \d{14}$/ }).evaluateAll((links) => links.map((a) => a.href))) {
        ids.add(Number(new URL(href).searchParams.get('client_id')));
    }
    for (const id of ids) {
        await action(page, `delete_client=${id}`, `agent/client_overview.php?client_id=${id}`);
    }
    await context.close();
});
