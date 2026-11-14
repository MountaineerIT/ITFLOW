// Quote pricing through the real pages (functions/mit_quotes.php, js/mit_quote_pricing.js, js/mit_billing_add.js): ITFlow 26.11.7's add row
// brought back (item + description, qty, billed, our cost, price by, %, price, tax) - a product picked fills it and takes the product's cost, a typed line keeps cost / markup; cost and markup are saved; the Edit Line window switches to margin and takes an image upload; the profit box adds
// up; the client's copy (guest link) and the PDF show the picture but never our cost; and the quote -> invoice window's
// "Send to procurement" for a stock-tracked product with none on the shelf (ITFlow alone refuses the conversion).
// Makes its own quote / product and removes them (and what the conversion made).

const { test, expect } = require('@playwright/test');
const { watch, visit, openModal, fillForm, submit, expectClean, csrf } = require('./helpers');
const db = require('./mitdb');

test.describe.configure({ mode: 'serial' });

const made = { quoteId: 0, productId: 0, invoiceIds: [], itemIds: [], lineId: 0, urlKey: 'smoke' + Date.now() };
const CLIENT = Number(db.val('SELECT client_id FROM clients WHERE client_archived_at IS NULL ORDER BY client_id LIMIT 1'));
const quotePage = () => `agent/quote.php?quote_id=${made.quoteId}`;
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAABgAAAAQCAIAAACDRijCAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAHElEQVQ4jWOUqzjBQA3ARBVTRg0aNWjUoBFsEACHZAF+6AIbvgAAAABJRU5ErkJggg==', 'base64');

test.beforeAll(() => {
    const n = Number(db.val('SELECT COALESCE(MAX(quote_number), 0) + 1 FROM quotes'));
    made.quoteId = db.insert(`INSERT INTO quotes SET quote_prefix = 'Q-', quote_number = ${n}, quote_scope = 'Smoke 60 pricing', quote_date = CURDATE(),
        quote_expire = DATE_ADD(CURDATE(), INTERVAL 30 DAY), quote_amount = 0, quote_currency_code = 'USD', quote_status = 'Draft', quote_url_key = ${db.q(made.urlKey)},
        quote_client_id = ${CLIENT}, quote_category_id = 0`);
    made.productId = db.insert(`INSERT INTO products SET product_name = 'Smoke 60 tracked laptop', product_price = 1200, product_cost = 950, product_type = 'product',
        product_track_stock = 1, product_currency_code = 'USD', product_tax_id = 0,
        product_category_id = ${Number(db.val("SELECT category_id FROM categories WHERE category_type = 'Income' AND category_archived_at IS NULL LIMIT 1"))}`);
});

test.afterAll(() => {
    for (const id of made.invoiceIds) {
        db.exec(`DELETE FROM mit_procurement_items WHERE invoice_id = ${id}; DELETE FROM invoice_items WHERE item_invoice_id = ${id}; DELETE FROM history WHERE history_invoice_id = ${id}; DELETE FROM invoices WHERE invoice_id = ${id}`);
    }
    if (made.quoteId) {
        db.php(`mitQuoteImagesDelete(${made.quoteId});`);
        db.exec(`DELETE FROM quote_items WHERE item_quote_id = ${made.quoteId}; DELETE FROM history WHERE history_quote_id = ${made.quoteId}; DELETE FROM quotes WHERE quote_id = ${made.quoteId}`);
    }
    if (made.productId) {
        db.exec(`DELETE FROM product_stock WHERE stock_product_id = ${made.productId}; DELETE FROM products WHERE product_id = ${made.productId}`);
    }
});

test('a line added on the page from a product takes its cost; cost + markup work the price out', async ({ page }) => {
    const errors = watch(page);
    await visit(page, quotePage(), errors);
    // ITFlow 26.11.7's add row brought back (mitPaperAddRow, js/mit_billing_add.js) in place of 26.11.8's one field
    const row = page.locator('form[data-mit-add-row]');
    await expect(row, 'the add row').toBeVisible();
    await expect(page.locator('[data-billing-add]'), "ITFlow 26.11.8's one field is replaced").toHaveCount(0);
    for (const field of ['name', 'description', 'qty', 'frequency', 'item_cost', 'item_price_method', 'item_price_percent', 'price', 'tax_id']) {
        await expect(row.locator(`[name=${field}]`), `${field} on the row`).toHaveCount(1);
    }
    const fieldBox = async (f) => row.locator(`[name=${f}]`).boundingBox();
    expect((await fieldBox('description')).y, 'the description under the item').toBeGreaterThan((await fieldBox('name')).y);

    // A product picked fills the row (price, our cost) and adds nothing until Add
    await row.locator('[name=name]').fill('Smoke 60 tracked');
    await expect(page.locator('.itflow-ac-panel').first(), 'the product list').toBeVisible();
    await page.locator('.itflow-ac-panel .itflow-select-option, .itflow-ac-panel [role=option]').first().click();
    await expect(row.locator('[name=price]')).toHaveValue('1200.00');
    await expect(row.locator('[name=item_cost]'), "the product's cost").toHaveValue('950.00');
    expect(Number(db.val(`SELECT COUNT(*) FROM quote_items WHERE item_quote_id = ${made.quoteId}`)), 'nothing added on the pick').toBe(0);
    await row.locator('[name=qty]').fill('2');
    await row.locator('[name=description]').fill('Picked, then the quantity typed');
    await row.locator('[data-mit-add-go]').click();
    await expect.poll(() => Number(db.val(`SELECT COUNT(*) FROM quote_items WHERE item_quote_id = ${made.quoteId} AND item_product_id = ${made.productId}`)), { message: 'line added from the product' }).toBe(1);
    const picked = db.one(`SELECT item_id, item_price, item_quantity, item_description, item_cost, item_price_method FROM quote_items WHERE item_quote_id = ${made.quoteId} AND item_product_id = ${made.productId}`);
    expect([Number(picked.item_price), Number(picked.item_quantity), picked.item_description, Number(picked.item_cost), picked.item_price_method], "the product's price and cost, our quantity and description").toEqual([1200, 2, 'Picked, then the quantity typed', 950, 'price']);
    await expect(page.locator('#items tr', { hasText: 'Picked, then the quantity typed' }), 'drawn without a reload').toHaveCount(1);
    await expect(row.locator('[name=name]'), 'the row is empty again').toHaveValue('');
    db.exec(`DELETE FROM quote_items WHERE item_id = ${picked.item_id}`); // the later tests add their own product lines

    // A line of its own: name, billed monthly, cost, markup works the price out - Enter adds it
    await row.locator('[name=name]').fill('Smoke 60 typed cable');
    await row.locator('[name=name]').press('Escape');
    await row.locator('[name=frequency]').selectOption('month');
    await row.locator('[name=item_price_method]').selectOption('markup');
    await row.locator('[name=item_price_percent]').fill('50');
    await row.locator('[name=item_cost]').fill('10');
    await expect(row.locator('[name=price]'), 'cost + 50% markup').toHaveValue('15.00');
    await row.locator('[name=price]').press('Enter');
    await expect.poll(() => Number(db.val(`SELECT COUNT(*) FROM quote_items WHERE item_quote_id = ${made.quoteId} AND item_name = 'Smoke 60 typed cable'`)), { message: 'typed line added' }).toBe(1);
    const typed = db.one(`SELECT item_id, item_price, item_cost, item_price_method, item_price_percent, item_product_id, item_frequency FROM quote_items WHERE item_quote_id = ${made.quoteId} AND item_name = 'Smoke 60 typed cable'`);
    expect([Number(typed.item_price), Number(typed.item_cost), typed.item_price_method, Number(typed.item_price_percent), Number(typed.item_product_id), typed.item_frequency]).toEqual([15, 10, 'markup', 50, 0, 'month']);
    db.exec(`DELETE FROM quote_items WHERE item_id = ${typed.item_id}`);
    await expectClean(page, errors);

    // Cost + markup set in the Edit Line window's save (agent/post.php billing_item_save with our fields)
    const token = await csrf(page);
    await db.pagePost(page, 'post.php', { csrf_token: token, billing_item_save: '1', type: 'quote', doc_id: String(made.quoteId), name: 'Smoke 60 firewall', description: '',
        qty: '2', price: '125', tax_id: '0', product_id: '0', frequency: '', mit_pricing_present: '1', item_cost: '100', item_price_method: 'markup', item_price_percent: '25' });
    const line = db.one(`SELECT item_id, item_price, item_cost, item_price_method, item_price_percent, item_quantity FROM quote_items WHERE item_quote_id = ${made.quoteId} AND item_name = 'Smoke 60 firewall'`);
    expect(line, 'the line was saved').toBeTruthy();
    made.lineId = Number(line.item_id);
    expect([Number(line.item_price), Number(line.item_cost), line.item_price_method, Number(line.item_price_percent)]).toEqual([125, 100, 'markup', 25]);
    await visit(page, quotePage(), errors);
    // Staff see the cost under the line and the profit box: cost 200, price 250, profit 50, margin 20%
    await expect(page.locator('[data-mit-line-cost]').first()).toContainText('100.00');
    const box = page.locator('[data-mit-profit-box]');
    await expect(box).toContainText('$200.00');
    await expect(box).toContainText('$250.00');
    await expect(box).toContainText('$50.00');
    await expect(box).toContainText('20.0%');
    // In the side column, between the Quote card and History (26.11.1.8 - was under the quote)
    const place = await page.evaluate(() => {
        const profit = document.querySelector('[data-mit-profit-box]');
        const cards = [...profit.parentElement.querySelectorAll(':scope > .card')];
        const title = (c) => (c.querySelector('.card-title') || {}).textContent || '';
        return {
            side: !profit.closest('.itflow-paper-col'),
            before: title(cards[cards.indexOf(profit) - 1] || {}).trim(),
            history: cards.findIndex((c) => title(c).trim() === 'History') > cards.indexOf(profit),
        };
    });
    expect(place).toEqual({ side: true, before: 'Quote', history: true });
});

test('Edit Line window: margin and an image upload', async ({ page }) => {
    const errors = watch(page);
    await visit(page, quotePage(), errors);
    const modal = await openModal(page, `modals/billing/billing_item.php?type=quote&doc_id=${made.quoteId}&item_id=${made.lineId}`, 'lg');
    await expect(modal.locator('[data-mit-cost]')).toHaveValue('100.00');
    await modal.locator('select[name=item_price_method]').selectOption('margin');
    await modal.locator('input[name=item_price_percent]').fill('20');
    await modal.locator('[data-mit-cost]').fill('80');
    await modal.locator('[data-mit-cost]').dispatchEvent('input');
    await expect(modal.locator('input[name=price]'), 'price from cost 80 at 20% margin').toHaveValue('100.00');
    await modal.locator('input[name=item_image]').setInputFiles({ name: 'smoke.png', mimeType: 'image/png', buffer: PNG });
    await Promise.all([page.waitForNavigation(), modal.locator('button[type=submit]').click()]);
    await expectClean(page, errors);
    const line = db.one(`SELECT item_price, item_cost, item_price_method, item_price_percent, item_image FROM quote_items WHERE item_id = ${made.lineId}`);
    expect([Number(line.item_price), Number(line.item_cost), line.item_price_method, Number(line.item_price_percent)]).toEqual([100, 80, 'margin', 20]);
    expect(line.item_image, 'image stored as our own copy').toMatch(/^uploads\/mit_images\/[a-f0-9]{32}\.(png|jpg)$/);
    const img = await db.pageGet(page, '/' + line.item_image);
    expect(img.status, 'image served').toBe(200);
});

test("the client's copy and the PDF: picture yes, our cost never", async ({ page }) => {
    const errors = watch(page);
    const guest = `guest/guest_view_quote.php?quote_id=${made.quoteId}&url_key=${made.urlKey}`;
    await visit(page, guest, errors);
    const html = await page.content();
    expect(html, 'line picture on the guest page').toMatch(/uploads\/mit_images\/[a-f0-9]{32}/);
    for (const staffOnly of ['data-mit-line-cost', 'data-mit-profit-box', 'Our cost', 'ONLY STAFF', 'data-mit-cost']) {
        expect(html, `guest page shows ${staffOnly}`).not.toContain(staffOnly);
    }
    await visit(page, quotePage(), errors);
    const token = await csrf(page);
    const pdf = await db.pageGet(page, `post.php?export_quote_pdf=${made.quoteId}&csrf_token=${encodeURIComponent(token)}`, true);
    expect(pdf.status).toBe(200);
    const body = Buffer.from(pdf.b64, 'base64');
    expect(body.slice(0, 5).toString(), 'a PDF').toBe('%PDF-');
    expect(body.toString('latin1'), 'the PDF carries the line picture').toContain('/Subtype /Image');
});

test('quote -> invoice: "Send to procurement" orders a tracked product with none on the shelf', async ({ page }) => {
    const errors = watch(page);
    // A line for the tracked product (nothing on the shelf) and a labour line
    await visit(page, quotePage(), errors);
    const token = await csrf(page);
    for (const fields of [
        { name: 'Smoke 60 tracked laptop', qty: '2', price: '1200', tax_id: '0', product_id: String(made.productId), frequency: '' },
        { name: 'Smoke 60 setup labour', qty: '3', price: '95', tax_id: '0', product_id: '0', frequency: '' },
    ]) {
        await db.pagePost(page, 'post.php', { csrf_token: token, billing_item_save: '1', type: 'quote', doc_id: String(made.quoteId), description: '', ...fields });
    }
    db.exec(`UPDATE quotes SET quote_status = 'Accepted' WHERE quote_id = ${made.quoteId}`);
    const laptop = db.one(`SELECT item_id, item_product_id, item_cost FROM quote_items WHERE item_quote_id = ${made.quoteId} AND item_name = 'Smoke 60 tracked laptop'`);
    expect(Number(laptop.item_product_id), 'quote line keeps its product').toBe(made.productId);
    expect(Number(laptop.item_cost), 'quote line takes the product cost').toBe(950);

    await visit(page, quotePage(), errors);
    const modal = await openModal(page, `modals/quote/quote_to_invoice.php?quote_id=${made.quoteId}`, 'lg');
    const tick = modal.locator(`input[name="mit_proc_lines[${laptop.item_id}][send]"]`);
    await expect(tick, 'hardware line ticked to order').toBeChecked();
    await Promise.all([page.waitForNavigation(), modal.locator('button[name=add_quote_to_invoice]').click()]);
    await expectClean(page, errors);
    const invoiceId = Number(new URL(page.url()).searchParams.get('invoice_id'));
    expect(invoiceId, `converted (${await page.locator('.toast-body, .alert').allInnerTexts()})`).toBeGreaterThan(0);
    made.invoiceIds.push(invoiceId);
    const line = db.one(`SELECT item_id, item_product_id, item_quantity FROM invoice_items WHERE item_invoice_id = ${invoiceId} AND item_name = 'Smoke 60 tracked laptop'`);
    expect(Number(line.item_product_id), 'invoice line ordered for the client: no product, so nothing comes off the shelf').toBe(0);
    expect(Number(db.val(`SELECT COALESCE(SUM(stock_qty), 0) FROM product_stock WHERE stock_product_id = ${made.productId}`)), 'shelf untouched').toBe(0);
    const proc = db.one(`SELECT source, quantity, unit_cost, product_id, invoice_item_id, status, client_id FROM mit_procurement_items WHERE invoice_id = ${invoiceId} AND name = 'Smoke 60 tracked laptop'`);
    expect(proc, 'on the list to order').toBeTruthy();
    expect([proc.source, Number(proc.quantity), Number(proc.unit_cost), Number(proc.product_id), Number(proc.invoice_item_id), proc.status, Number(proc.client_id)])
        .toEqual(['quote', 2, 950, made.productId, Number(line.item_id), 'waiting', CLIENT]);
    expect(Number(db.val(`SELECT COUNT(*) FROM mit_procurement_items WHERE invoice_id = ${invoiceId} AND name = 'Smoke 60 setup labour'`)), 'labour (no cost) not ordered').toBe(0);
    // The invoice page shows the line as waiting to order
    await visit(page, `agent/invoice.php?invoice_id=${invoiceId}`, errors);
    await expect(page.locator(`#line-${line.item_id}`), 'on the line itself').toContainText('Waiting to order');
    // Once neither can be edited (the quote accepted, the invoice non-billable), staff still see our notes on the lines
    db.exec(`UPDATE invoices SET invoice_status = 'Non-Billable' WHERE invoice_id = ${invoiceId}`); // lines locked (ITFlow's billingCanEditItems - Sent still edits)
    await visit(page, `agent/invoice.php?invoice_id=${invoiceId}`, errors);
    await expect(page.locator(`#line-${line.item_id}`), 'a locked invoice still shows the line waiting to order').toContainText('Waiting to order');
    await visit(page, quotePage(), errors);
    await expect(page.locator('[data-mit-line-cost]').first(), 'an accepted quote still shows our cost to staff').toBeVisible();
});

test('product picture: uploaded on the product, copied onto a new quote line from it, removed with it', async ({ page }) => {
    const errors = watch(page);
    db.exec(`UPDATE quotes SET quote_status = 'Draft' WHERE quote_id = ${made.quoteId}`);
    await visit(page, 'agent/products.php', errors);
    const modal = await openModal(page, `modals/product/product_edit.php?id=${made.productId}`, 'lg');
    await modal.locator('input[name=product_image]').setInputFiles({ name: 'p.png', mimeType: 'image/png', buffer: PNG });
    await submit(modal, 'edit_product');
    await expectClean(page, errors);
    const image = db.val(`SELECT product_image FROM products WHERE product_id = ${made.productId}`);
    expect(image, 'product image stored').toMatch(/^uploads\/mit_images\/[a-f0-9]{32}\.(png|jpg)$/);
    expect(Number(db.val(`SELECT product_cost FROM products WHERE product_id = ${made.productId}`)), 'ITFlow\'s product cost untouched by our save').toBe(950);
    // A new quote line from the product gets its own copy of the picture
    await visit(page, quotePage(), errors);
    const token = await csrf(page);
    await db.pagePost(page, 'post.php', { csrf_token: token, billing_item_save: '1', type: 'quote', doc_id: String(made.quoteId), name: 'Smoke 60 tracked laptop again', description: '',
        qty: '1', price: '1200', tax_id: '0', product_id: String(made.productId), frequency: '' });
    const lineImage = db.val(`SELECT item_image FROM quote_items WHERE item_quote_id = ${made.quoteId} AND item_name = 'Smoke 60 tracked laptop again'`);
    expect(lineImage, 'line has its own copy').toMatch(/^uploads\/mit_images\//);
    expect(lineImage).not.toBe(image);
    // Remove the product's picture: its file goes, the line's copy stays
    const m2 = await openModal(page, `modals/product/product_edit.php?id=${made.productId}`, 'lg');
    await m2.locator('input[name=product_image_remove]').check();
    await submit(m2, 'edit_product');
    expect(db.val(`SELECT product_image FROM products WHERE product_id = ${made.productId}`), 'product image removed').toBeNull();
    expect((await db.pageGet(page, '/' + image)).status, 'its file is gone').toBe(404);
    expect((await db.pageGet(page, '/' + lineImage)).status, "the line's copy stays").toBe(200);
});

test("an invoice's add row: item, description, qty, price and tax - no cost (customer price only)", async ({ page }) => {
    const errors = watch(page);
    const n = Number(db.val('SELECT COALESCE(MAX(invoice_number), 0) + 1 FROM invoices'));
    const invoiceId = db.insert(`INSERT INTO invoices SET invoice_prefix = 'INV-', invoice_number = ${n}, invoice_scope = 'Smoke 60 add row', invoice_date = CURDATE(),
        invoice_due = CURDATE(), invoice_amount = 0, invoice_currency_code = 'USD', invoice_status = 'Draft', invoice_url_key = 'smoke60', invoice_client_id = ${CLIENT}, invoice_category_id = 0`);
    made.invoiceIds.push(invoiceId);
    await visit(page, `agent/invoice.php?client_id=${CLIENT}&invoice_id=${invoiceId}`, errors);
    const row = page.locator('form[data-mit-add-row]');
    await expect(row).toBeVisible();
    await expect(row.locator('[name=item_cost]'), 'no cost on invoices').toHaveCount(0);
    await expect(row.locator('[name=frequency]'), 'no Billed on invoices').toHaveCount(0);
    await row.locator('[name=name]').fill('Smoke 60 invoice labour');
    await row.locator('[name=name]').press('Escape');
    await row.locator('[name=description]').fill('Two hours on site');
    await row.locator('[name=qty]').fill('2');
    await row.locator('[name=price]').fill('95');
    await row.locator('[data-mit-add-go]').click();
    await expect(page.locator('#items tr', { hasText: 'Smoke 60 invoice labour' }), 'drawn without a reload').toHaveCount(1);
    const line = db.one(`SELECT item_quantity, item_price, item_description FROM invoice_items WHERE item_invoice_id = ${invoiceId}`);
    expect([Number(line.item_quantity), Number(line.item_price), line.item_description]).toEqual([2, 95, 'Two hours on site']);
    expect(Number(db.val(`SELECT invoice_amount FROM invoices WHERE invoice_id = ${invoiceId}`)), 'the invoice total follows').toBe(190);
    await expectClean(page, errors);
});
