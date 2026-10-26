// Quote pricing through the real pages (functions/mit_quotes.php, js/mit_quote_pricing.js): the add-a-line row works the price out
// from our cost and markup and saves them; the Edit Line window switches to margin and takes an image upload; the profit box adds
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

test('add-a-line row: price worked out from cost + markup, cost saved', async ({ page }) => {
    const errors = watch(page);
    await visit(page, quotePage(), errors);
    const row = page.locator('#add-line');
    await expect(row.locator('[data-mit-cost]'), 'our cost box on the add row').toBeVisible();
    await row.locator('input[name=name]').fill('Smoke 60 firewall');
    await row.locator('input[name=qty]').fill('2');
    await row.locator('select[name=item_price_method]').selectOption('markup');
    await row.locator('[data-mit-pct]').fill('25');
    await row.locator('[data-mit-cost]').fill('100');
    await row.locator('[data-mit-cost]').dispatchEvent('input');
    await expect(row.locator('input[name=price]'), 'price from cost 100 + 25%').toHaveValue('125.00');
    await Promise.all([page.waitForNavigation(), page.evaluate(() => document.getElementById('billingAddLine').requestSubmit())]);
    await expectClean(page, errors);
    const line = db.one(`SELECT item_id, item_price, item_cost, item_price_method, item_price_percent, item_quantity FROM quote_items WHERE item_quote_id = ${made.quoteId} AND item_name = 'Smoke 60 firewall'`);
    expect(line, 'the line was saved').toBeTruthy();
    made.lineId = Number(line.item_id);
    expect([Number(line.item_price), Number(line.item_cost), line.item_price_method, Number(line.item_price_percent)]).toEqual([125, 100, 'markup', 25]);
    // Staff see the cost under the line and the profit box: cost 200, price 250, profit 50, margin 20%
    await expect(page.locator('[data-mit-line-cost]').first()).toContainText('100.00');
    const box = page.locator('[data-mit-profit-box]');
    await expect(box).toContainText('$200.00');
    await expect(box).toContainText('$250.00');
    await expect(box).toContainText('$50.00');
    await expect(box).toContainText('20.0%');
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
    await expect(page.locator('body')).toContainText('Waiting to order');
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
