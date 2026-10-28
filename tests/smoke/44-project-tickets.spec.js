// Project tickets kept apart (functions/mit_projects.php), with the data underneath: a ticket opened in a project gets no SLA (though
// its client has one), is off the Open / Unassigned views and the Dispatch queue but in "Projects" and the Project filter; taken out
// of the project (ITFlow's own Edit project window) it gets the SLA back counted from that moment, not from when it was opened two
// days ago; put back it loses it again; booked, it is drawn in the project colour under the calendar's Projects source with the
// agent's colour kept as a dot. A client, project and SLA assignment of its own, removed at the end.

const { test, expect } = require('@playwright/test');
const { smokeName, watch, visit, openModal, fillForm, submit, flash, action, getJson, expectClean } = require('./helpers');
const db = require('./db');

test.describe.configure({ mode: 'serial' });

const made = { clientId: 0, projectId: 0, ticketId: 0, contractId: 0 };
const ticketPage = () => `agent/ticket.php?client_id=${made.clientId}&ticket_id=${made.ticketId}`;
const sla = () => db.rows(`SELECT ticket_sla_id, ticket_response_due_at, ticket_created_at, NOW() AS now FROM tickets WHERE ticket_id = ${made.ticketId}`)[0];
const listed = async (page, query) => {
    await visit(page, `agent/tickets.php?client_id=${made.clientId}&${query}`);
    return page.locator(`a[href*="ticket_id=${made.ticketId}"]`).count();
};
function tomorrow() {
    const d = new Date(Date.now() + 86400000);
    const pad = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

test('project tickets: opened in a project - no SLA, kept off the service views', async ({ page }) => {
    test.skip(db.value("SELECT setting_value FROM mit_settings WHERE setting_name = 'project_tickets_apart'") === '0', 'kept apart is switched off');
    const name = smokeName('t1 project client');
    db.run(`INSERT INTO clients SET client_name = ${db.q(name)}, client_currency_code = 'USD'`);
    made.clientId = Number(db.value(`SELECT client_id FROM clients WHERE client_name = ${db.q(name)}`));
    db.run(`INSERT INTO contacts SET contact_name = 'Pam Project', contact_primary = 1, contact_client_id = ${made.clientId}`);
    // The client's SLA for Low tickets - from its contract (ITFlow 3.5.0: clients have no SLAs of their own)
    db.run(`INSERT INTO contracts SET contract_name = 'Smoke 44 terms', contract_status = 'Active', contract_type = 'Fully Managed', contract_client_id = ${made.clientId},
        contract_start_date = CURDATE() - INTERVAL 30 DAY, contract_term_months = 12, contract_auto_renew = 1, contract_notice_days = 0, contract_sla_low_id = 1, contract_sla_medium_id = 0,
        contract_sla_high_id = 0, contract_sla_urgent_id = 0, contract_template_id = 0, contract_contact_id = 0, contract_owner_id = 0, contract_created_by = 1, contract_monthly_hours = 0,
        contract_hours_alert = 0, contract_created_at = NOW()`);
    made.contractId = Number(db.value(`SELECT MAX(contract_id) FROM contracts WHERE contract_client_id = ${made.clientId}`));
    db.run(`INSERT INTO projects SET project_prefix = 'PRJ-', project_number = 9${String(Date.now()).slice(-4)}, project_name = ${db.q(smokeName('t1 project'))}, project_client_id = ${made.clientId}`);
    made.projectId = Number(db.value(`SELECT MAX(project_id) FROM projects WHERE project_client_id = ${made.clientId}`));

    await visit(page, `agent/client_overview.php?client_id=${made.clientId}`);
    const modal = await openModal(page, `modals/ticket/ticket_add.php?client_id=${made.clientId}`, 'lg');
    await fillForm(modal, { subject: smokeName('t1 project ticket'), details: 'Project test', priority: 'Low', project_id: String(made.projectId) });
    await submit(modal, 'add_ticket');
    made.ticketId = Number(new URL(page.url()).searchParams.get('ticket_id'));
    expect(made.ticketId, await flash(page)).toBeGreaterThan(0);
    expect(db.value(`SELECT ticket_project_id FROM tickets WHERE ticket_id = ${made.ticketId}`)).toBe(String(made.projectId));
    expect(sla().ticket_sla_id, 'no SLA in a project (the client has Gold for Low)').toBe('0');
    expect(db.value(`SELECT in_project FROM mit_project_ticket_sla WHERE ticket_id = ${made.ticketId}`)).toBe('1');

    expect(await listed(page, 'show=open'), 'not on Open').toBe(0);
    expect(await listed(page, 'show=unassigned'), 'not on Unassigned').toBe(0);
    expect(await listed(page, 'show=projects'), 'on Projects').toBeGreaterThan(0);
    expect(await listed(page, `show=open&project=${made.projectId}`), 'and with the Project filter').toBeGreaterThan(0);
    expect(await listed(page, 'show=all'), 'and under All').toBeGreaterThan(0);
    const queue = await getJson(page, `/agent/mit_dispatch_ajax.php?queue=unassigned&client=${made.clientId}`).catch(() => null);
    if (queue) {
        expect((queue.tickets || queue).map((t) => Number(t.id)), 'not in the Dispatch queue').not.toContain(made.ticketId);
    }
});

test('project tickets: out of the project the SLA comes back from that moment, back in it goes', async ({ page }) => {
    test.skip(!made.ticketId, 'no ticket');
    // Opened two days ago
    db.run(`UPDATE tickets SET ticket_created_at = NOW() - INTERVAL 2 DAY WHERE ticket_id = ${made.ticketId}`);
    await visit(page, ticketPage());
    let modal = await openModal(page, `modals/ticket/ticket_edit_project.php?id=${made.ticketId}`);
    await fillForm(modal, { project: '0' });
    await submit(modal, 'edit_ticket_project');
    const s = sla();
    expect(s.ticket_sla_id, `the client's SLA back (${await flash(page)})`).toBe('1');
    expect(db.rows(`SELECT in_project, left_at IS NOT NULL AS left_set FROM mit_project_ticket_sla WHERE ticket_id = ${made.ticketId}`)[0]).toEqual({ in_project: '0', left_set: '1' });
    expect(new Date(s.ticket_response_due_at) >= new Date(new Date(s.now).getTime() - 60000), `response due counted from leaving, not from the opening two days ago (due ${s.ticket_response_due_at}, now ${s.now})`).toBe(true);
    expect(await listed(page, 'show=open'), 'on Open again').toBeGreaterThan(0);

    modal = await openModal(page, `modals/ticket/ticket_edit_project.php?id=${made.ticketId}`);
    await fillForm(modal, { project: String(made.projectId) });
    await submit(modal, 'edit_ticket_project');
    expect(sla().ticket_sla_id, 'back in: no SLA').toBe('0');
    expect(sla().ticket_response_due_at).toBeNull();
});

test('project tickets: the project page and the tickets list move them too, SLA following', async ({ page }) => {
    test.skip(!made.ticketId, 'no ticket');
    await visit(page, `agent/project.php?project_id=${made.projectId}`);
    const token = await page.locator('input[name=csrf_token]').first().inputValue();
    const post = (body) => page.evaluate(async (b) => {
        await fetch('/agent/post.php', { method: 'POST', credentials: 'same-origin', body: new URLSearchParams(b) });
    }, { csrf_token: token, ...body });
    // Off the project from its own page (projectRemoveTicket)
    await post({ remove_tickets_from_project: '1', project_id: String(made.projectId), 'ticket_ids[]': String(made.ticketId) });
    expect(db.value(`SELECT ticket_project_id FROM tickets WHERE ticket_id = ${made.ticketId}`)).toBe('0');
    expect(sla().ticket_sla_id, 'removed on the project page: the SLA back at once').toBe('1');
    // Back on it by its number (projectAddTicket)
    const number = db.value(`SELECT ticket_number FROM tickets WHERE ticket_id = ${made.ticketId}`);
    await post({ link_ticket_to_project: '1', project_id: String(made.projectId), ticket_numbers: number });
    expect(db.value(`SELECT ticket_project_id FROM tickets WHERE ticket_id = ${made.ticketId}`)).toBe(String(made.projectId));
    expect(sla().ticket_sla_id, 'added on the project page: no SLA at once').toBe('0');
    // Off and on again from the tickets list (bulk Project)
    await post({ bulk_add_ticket_project: '1', project_id: '0', 'ticket_ids[]': String(made.ticketId) });
    expect(sla().ticket_sla_id, 'taken out in bulk: the SLA back').toBe('1');
    await post({ bulk_add_ticket_project: '1', project_id: String(made.projectId), 'ticket_ids[]': String(made.ticketId) });
    expect(sla().ticket_sla_id, 'put in in bulk: no SLA').toBe('0');
});

test('project tickets: Admin > Settings > Projects carries the kept-apart card', async ({ page }) => {
    await visit(page, 'admin/settings_project.php');
    await expect(page.locator('[name=mit_project_tickets_apart]').first(), 'our card').toHaveCount(1);
});

test('project tickets: a booking is drawn in the project colour under Projects', async ({ page }) => {
    test.skip(!made.ticketId, 'no ticket');
    const day = tomorrow();
    await visit(page, ticketPage());
    const modal = await openModal(page, `modals/ticket/ticket_edit_schedule.php?ticket_id=${made.ticketId}`);
    await fillForm(modal, { schedule_tech: '1', scheduled_date_time: `${day}T11:00`, schedule_minutes: '60', onsite: '0' });
    await submit(modal, 'edit_ticket_schedule');
    const colour = db.value("SELECT setting_value FROM mit_settings WHERE setting_name = 'project_ticket_color'") || '#8e44ad';
    const tickets = await getJson(page, `/agent/ajax.php?calendar_items&from=${day}&to=${day}&sources=tickets`);
    expect(tickets.find((i) => i.id === `ticket-${made.ticketId}`), 'not under the Tickets source on the calendar page').toBeFalsy();
    const projects = await getJson(page, `/agent/ajax.php?calendar_items&from=${day}&to=${day}&sources=projects`);
    const item = projects.find((i) => i.id === `ticket-${made.ticketId}`);
    expect(item, 'under Projects').toBeTruthy();
    expect(item.color.toLowerCase(), 'in the project colour').toBe(colour.toLowerCase());
    expect(item.extendedProps.tech_color, 'the agent\'s colour kept for the dot').toBeTruthy();
    expect(item.extendedProps.detail).toContain('Project PRJ-');
});

test.afterAll(async ({ browser }) => {
    const use = test.info().project.use;
    const context = await browser.newContext({ storageState: use.storageState, baseURL: use.baseURL });
    const page = await context.newPage();
    if (made.ticketId) {
        await action(page, `delete_ticket=${made.ticketId}`, ticketPage()).catch(() => {});
    }
    if (made.contractId) {
        db.run(`DELETE FROM contracts WHERE contract_id = ${made.contractId}`);
    }
    if (made.projectId) {
        db.run(`DELETE FROM projects WHERE project_id = ${made.projectId}`);
    }
    if (made.clientId) {
        db.run(`DELETE FROM email_queue WHERE email_subject LIKE '%Smoke test t1 project ticket%'`);
        await action(page, `delete_client=${made.clientId}`, `agent/client_overview.php?client_id=${made.clientId}`).catch(() => {});
    }
    await context.close();
});
