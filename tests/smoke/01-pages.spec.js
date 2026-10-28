// Every page in the menus opens without a PHP or JavaScript error - the agent
// side menu, a client's menu, Admin's menu, Account, and one of each record
// page (ticket, invoice, quote, project, purchase order...). The menus are read
// from the pages themselves, so pages an upstream release adds are covered too.

const { test, expect } = require('@playwright/test');
const { PHP_ERROR } = require('./helpers');

// Links that do something rather than show something
const SKIP = /logout|post\.php|csrf_token|\/login\.php|export|download|\.pdf|#$/i;

async function menuLinks(page, url) {
    await page.goto(url);
    const links = await page.evaluate(() => [...document.querySelectorAll('.app-sidebar a[href]')]
        .map((a) => a.href)
        .filter((href) => href.startsWith(location.origin) && /\.php/.test(href)));
    return [...new Set(links)];
}

// The first link on a list page that goes to a record page, e.g. ticket.php?ticket_id=
async function firstRecord(page, listUrl, pattern) {
    await page.goto(listUrl);
    const href = await page.evaluate((pattern) => {
        const re = new RegExp(pattern);
        const link = [...document.querySelectorAll('a[href]')].find((a) => re.test(a.getAttribute('href')));
        return link ? link.href : '';
    }, pattern.source);
    return href;
}

async function check(page, url, problems) {
    const errors = [];
    const listener = (e) => errors.push(e.message);
    page.on('pageerror', listener);
    let response;
    try {
        response = await page.goto(url);
        await page.waitForLoadState('load');
        await page.waitForTimeout(200);
    } catch (e) {
        problems.push(`${url}: ${e.message.split('\n')[0]}`);
        page.off('pageerror', listener);
        return;
    }
    page.off('pageerror', listener);
    const status = response ? response.status() : 0;
    const text = await page.locator('body').innerText().catch(() => '');
    const php = PHP_ERROR.exec(text);
    if (status >= 400) {
        problems.push(`${url}: HTTP ${status}`);
    } else if (/\/login\.php/.test(page.url())) {
        problems.push(`${url}: sent to the login page`);
    } else if (php) {
        problems.push(`${url}: ${text.slice(php.index, php.index + 250).replace(/\s+/g, ' ')}`);
    } else if (errors.length) {
        problems.push(`${url}: JavaScript error: ${errors[0]}`);
    }
}

async function crawl(page, urls) {
    const problems = [];
    let checked = 0;
    for (const url of urls) {
        if (!SKIP.test(url)) {
            await check(page, url, problems);
            checked++;
        }
    }
    test.info().annotations.push({ type: 'pages checked', description: String(checked) });
    return problems;
}

test('agent menu pages', async ({ page }) => {
    const urls = await menuLinks(page, 'agent/dashboard.php');
    expect(urls.length, 'links in the side menu').toBeGreaterThan(5);
    expect(await crawl(page, urls)).toEqual([]);
});

test('client menu pages', async ({ page }) => {
    const client = await firstRecord(page, 'agent/clients.php', /client_overview\.php\?client_id=\d+|client_id=\d+/);
    test.skip(!client, 'no clients on this install');
    const id = new URL(client).searchParams.get('client_id');
    const urls = await menuLinks(page, `agent/client_overview.php?client_id=${id}`);
    expect(urls.length, 'links in the client menu').toBeGreaterThan(5);
    expect(await crawl(page, urls)).toEqual([]);
});

test('admin menu pages', async ({ page }) => {
    test.setTimeout(180000); // the Settings menu lists every settings page (ITFlow's and ours) - 40 and more pages
    const urls = await menuLinks(page, 'admin/users.php');
    expect(urls.length, 'links in the admin menu').toBeGreaterThan(5);
    expect(await crawl(page, urls)).toEqual([]);
});

test('account pages', async ({ page }) => {
    const urls = await menuLinks(page, 'agent/user/user_details.php');
    expect(await crawl(page, ['agent/user/user_details.php', ...urls])).toEqual([]);
});

test('one of each record page', async ({ page }) => {
    const lists = [
        ['agent/tickets.php?show=all', /ticket\.php\?.*ticket_id=\d+/],
        ['agent/invoices.php', /invoice\.php\?.*invoice_id=\d+/],
        ['agent/quotes.php', /quote\.php\?.*quote_id=\d+/],
        ['agent/projects.php', /project\.php\?.*project_id=\d+/],
        ['agent/recurring_invoices.php', /recurring_invoice\.php\?.*recurring_invoice_id=\d+/],
        ['agent/vendors.php', /vendor\.php\?.*vendor_id=\d+/],
        ['agent/mit_purchase_orders.php', /mit_purchase_order\.php\?.*po_id=\d+/],
        ['agent/mit_timesheets.php', /mit_timesheet\.php\?.+/],
    ];
    const urls = [];
    for (const [list, pattern] of lists) {
        const url = await firstRecord(page, list, pattern);
        if (url) {
            urls.push(url);
        }
    }
    expect(urls.length, 'record pages found').toBeGreaterThan(0);
    expect(await crawl(page, urls)).toEqual([]);
});
