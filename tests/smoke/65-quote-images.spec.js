// Quote and product pictures through ITFlow's own handlers (functions/mit_quotes.php hooks in agent/post/product.php, quote.php,
// billing.php, client.php and api/v1/clients/delete.php): a product added with a picture keeps it; a picture that can't be read
// says so after the save; copying a quote copies each line's cost, pricing and its own copy of the picture; and every way a line,
// quote, product or client goes takes its picture files with it. Also that Admin > Settings > Quotes carries our pricing card.
// Makes its own clients, quotes, products and picture files, and removes them.

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { test, expect } = require('@playwright/test');
const { watch, visit, openModal, fillForm, submit, flash, expectClean, csrf, smokeName } = require('./helpers');
const db = require('./mitdb');

test.describe.configure({ mode: 'serial' });

const ROOT = process.env.ITFLOW_ROOT || '/var/www/itflow';
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAABgAAAAQCAIAAACDRijCAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAHElEQVQ4jWOUqzjBQA3ARBVTRg0aNWjUoBFsEACHZAF+6AIbvgAAAABJRU5ErkJggg==', 'base64');
const CLIENT = Number(db.val('SELECT client_id FROM clients WHERE client_archived_at IS NULL ORDER BY client_id LIMIT 1'));
const made = { quotes: [], products: [], clients: [], apiKey: 0, files: [], name: smokeName('quote pictures') };

/** A picture file of ours (uploads/mit_images/<md5>.png), as an upload would leave it - returns its stored path. */
function picture() {
    const rel = `uploads/mit_images/${crypto.randomBytes(16).toString('hex')}.png`;
    fs.mkdirSync(path.join(ROOT, 'uploads/mit_images'), { recursive: true });
    fs.writeFileSync(path.join(ROOT, rel), PNG);
    made.files.push(rel);
    return rel;
}
const exists = (rel) => !!rel && fs.existsSync(path.join(ROOT, rel));

function quote(clientId, lines) {
    const n = Number(db.val('SELECT COALESCE(MAX(quote_number), 0) + 1 FROM quotes'));
    const id = db.insert(`INSERT INTO quotes SET quote_prefix = 'Q-', quote_number = ${n}, quote_scope = ${db.q(made.name)}, quote_date = CURDATE(),
        quote_expire = DATE_ADD(CURDATE(), INTERVAL 30 DAY), quote_amount = 0, quote_currency_code = 'USD', quote_status = 'Draft',
        quote_url_key = ${db.q('smoke' + Date.now() + n)}, quote_client_id = ${clientId}, quote_category_id = 0`);
    made.quotes.push(id);
    const items = lines.map((line, i) => db.insert(`INSERT INTO quote_items SET item_quote_id = ${id}, item_name = ${db.q(line.name)}, item_description = '',
        item_quantity = 1, item_price = 100, item_subtotal = 100, item_tax = 0, item_total = 100, item_order = ${i + 1}, item_tax_id = 0,
        item_cost = ${line.cost ?? 'NULL'}, item_price_method = ${line.method ? db.q(line.method) : 'NULL'}, item_price_percent = ${line.pct ?? 'NULL'},
        item_image = ${line.image ? db.q(line.image) : 'NULL'}`));
    return { id, items };
}
function product(image) {
    const id = db.insert(`INSERT INTO products SET product_name = ${db.q(made.name + ' ' + (made.products.length + 1))}, product_price = 10, product_type = 'product',
        product_currency_code = 'USD', product_tax_id = 0, product_image = ${image ? db.q(image) : 'NULL'},
        product_category_id = ${Number(db.val("SELECT category_id FROM categories WHERE category_type = 'Income' AND category_archived_at IS NULL LIMIT 1"))}`);
    made.products.push(id);
    return id;
}
function archivedClient() {
    const id = db.insert(`INSERT INTO clients SET client_name = ${db.q(made.name + ' client ' + (made.clients.length + 1))}, client_currency_code = 'USD', client_net_terms = 30, client_archived_at = NOW()`);
    made.clients.push(id);
    return id;
}

test.afterAll(() => {
    for (const id of made.quotes) {
        db.exec(`DELETE FROM quote_items WHERE item_quote_id = ${id}; DELETE FROM history WHERE history_quote_id = ${id}; DELETE FROM quotes WHERE quote_id = ${id}`);
    }
    db.exec(`DELETE FROM quote_items WHERE item_quote_id IN (SELECT quote_id FROM quotes WHERE quote_scope = ${db.q(made.name)});
        DELETE FROM quotes WHERE quote_scope = ${db.q(made.name)}`);
    db.exec(`DELETE FROM products WHERE product_name LIKE ${db.q(made.name + '%')}`);
    for (const id of made.clients) {
        db.exec(`DELETE FROM clients WHERE client_id = ${id}`);
    }
    if (made.apiKey) {
        db.exec(`DELETE FROM api_keys WHERE api_key_id = ${made.apiKey}`);
    }
    for (const rel of made.files) {
        try { fs.unlinkSync(path.join(ROOT, rel)); } catch (e) { /* already gone */ }
    }
    for (const rel of db.rows(`SELECT product_image AS f FROM products WHERE product_name LIKE ${db.q(made.name + '%')}`)) {
        try { fs.unlinkSync(path.join(ROOT, rel.f)); } catch (e) { /* already gone */ }
    }
});

test('Admin > Settings > Quotes carries the pricing and pictures card', async ({ page }) => {
    const errors = watch(page);
    await visit(page, 'admin/settings_quote.php', errors);
    await expect(page.locator('button[name=mit_edit_quote_pricing]'), 'our card').toBeVisible();
    await expect(page.locator('input[name=mit_quote_show_images]')).toHaveCount(1);
    await expectClean(page, errors);
});

test('a product added with a picture keeps it; one that cannot be read says so after the save', async ({ page }) => {
    test.skip(db.val("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'product_image'") !== '1', 'needs M007');
    const errors = watch(page);
    const add = async (name, file) => {
        await visit(page, 'agent/products.php', errors);
        const modal = await openModal(page, 'modals/product/product_add.php', 'lg');
        await modal.locator('select[name=category]').evaluate((s) => { s.value = [...s.options].find((o) => o.value)?.value || ''; });
        await fillForm(modal, { name, price: '10' });
        await modal.locator('input[name=product_image]').setInputFiles(file);
        await submit(modal, 'add_product');
        return db.one(`SELECT product_id, product_image FROM products WHERE product_name = ${db.q(name)}`);
    };
    const good = await add(made.name + ' with picture', { name: 'p.png', mimeType: 'image/png', buffer: PNG });
    expect(good, 'the product was added').toBeTruthy();
    expect(good.product_image, 'its picture stored on add').toMatch(/^uploads\/mit_images\/[a-f0-9]{32}\.(png|jpg)$/);
    expect(exists(good.product_image)).toBe(true);
    expect(await flash(page)).not.toContain('could not be added');

    const bad = await add(made.name + ' bad picture', { name: 'p.png', mimeType: 'image/png', buffer: Buffer.from('not a picture at all') });
    expect(bad, 'still added').toBeTruthy();
    expect(bad.product_image, 'no picture').toBeNull();
    expect(await flash(page), 'told on add').toContain('Saved, but the image could not be added');

    await visit(page, 'agent/products.php', errors);
    const modal = await openModal(page, `modals/product/product_edit.php?id=${bad.product_id}`, 'lg');
    await modal.locator('input[name=product_image]').setInputFiles({ name: 'q.png', mimeType: 'image/png', buffer: Buffer.from('still not a picture') });
    await submit(modal, 'edit_product');
    expect(await flash(page), 'told on edit').toContain('Saved, but the image could not be added');
    await expectClean(page, errors);
});

test('copying a quote: each line keeps its cost and pricing and gets its own copy of the picture', async ({ page }) => {
    const image = picture();
    const from = quote(CLIENT, [{ name: 'Smoke 65 switch', cost: 70, method: 'markup', pct: 30, image }]);
    await visit(page, `agent/quote.php?quote_id=${from.id}`);
    const token = await csrf(page);
    const today = new Date().toISOString().slice(0, 10);
    const expire = new Date(Date.now() + 30 * 86400000).toISOString().slice(0, 10);
    await db.pagePost(page, '/agent/post.php', { csrf_token: token, add_quote_copy: '1', quote_id: String(from.id), client_id: String(CLIENT), date: today, expire });
    const copy = db.one(`SELECT i.item_cost, i.item_price_method, i.item_price_percent, i.item_image, q.quote_id FROM quote_items i JOIN quotes q ON q.quote_id = i.item_quote_id
        WHERE q.quote_scope = ${db.q(made.name)} AND q.quote_id <> ${from.id} AND i.item_name = 'Smoke 65 switch' ORDER BY q.quote_id DESC LIMIT 1`);
    expect(copy, 'the quote was copied').toBeTruthy();
    made.quotes.push(Number(copy.quote_id));
    expect([Number(copy.item_cost), copy.item_price_method, Number(copy.item_price_percent)], 'cost and pricing copied').toEqual([70, 'markup', 30]);
    expect(copy.item_image, 'a picture of its own').toMatch(/^uploads\/mit_images\//);
    expect(copy.item_image).not.toBe(image);
    expect(exists(copy.item_image), 'its file is there').toBe(true);
    made.files.push(copy.item_image);
});

test('a quote line, a quote, a product (one or several) going takes its picture file along', async ({ page }) => {
    const lineImage = picture();
    const keptImage = picture();
    const q1 = quote(CLIENT, [{ name: 'Smoke 65 line to go', image: lineImage }, { name: 'Smoke 65 line to keep', image: keptImage }]);
    await visit(page, `agent/quote.php?quote_id=${q1.id}`);
    let token = await csrf(page);
    await db.pageGet(page, `/agent/post.php?billing_item_delete=${q1.items[0]}&type=quote&doc_id=${q1.id}&csrf_token=${encodeURIComponent(token)}`);
    expect(Number(db.val(`SELECT COUNT(*) FROM quote_items WHERE item_id = ${q1.items[0]}`)), 'line deleted').toBe(0);
    expect(exists(lineImage), "the deleted line's picture went").toBe(false);
    expect(exists(keptImage), "the other line's stays").toBe(true);

    await db.pageGet(page, `/agent/post.php?delete_quote=${q1.id}&csrf_token=${encodeURIComponent(token)}`);
    expect(Number(db.val(`SELECT COUNT(*) FROM quotes WHERE quote_id = ${q1.id}`)), 'quote deleted').toBe(0);
    expect(exists(keptImage), "the deleted quote's pictures went").toBe(false);

    const images = [picture(), picture(), picture()];
    const [one, two, three] = images.map((image) => product(image));
    await visit(page, 'agent/products.php');
    token = await csrf(page);
    await db.pageGet(page, `/agent/post.php?delete_product=${one}&csrf_token=${encodeURIComponent(token)}`);
    expect(Number(db.val(`SELECT COUNT(*) FROM products WHERE product_id = ${one}`)), 'product deleted').toBe(0);
    expect(exists(images[0]), "a deleted product's picture went").toBe(false);
    await db.pagePost(page, '/agent/post.php', { csrf_token: token, bulk_delete_products: '1', 'product_ids[]': [String(two), String(three)] });
    expect(Number(db.val(`SELECT COUNT(*) FROM products WHERE product_id IN (${two}, ${three})`)), 'products deleted').toBe(0);
    expect(exists(images[1]) || exists(images[2]), 'bulk-deleted products\' pictures went').toBe(false);
});

test('a client deleted (from ITFlow or through the API) takes its quotes\' pictures along', async ({ page }) => {
    const viaPage = archivedClient();
    const pageImage = picture();
    quote(viaPage, [{ name: 'Smoke 65 client line', image: pageImage }]);
    await visit(page, 'agent/clients.php');
    const token = await csrf(page);
    await db.pageGet(page, `/agent/post.php?delete_client=${viaPage}&csrf_token=${encodeURIComponent(token)}`);
    expect(Number(db.val(`SELECT COUNT(*) FROM clients WHERE client_id = ${viaPage}`)), 'client deleted').toBe(0);
    expect(exists(pageImage), "its quote's picture went").toBe(false);

    const viaApi = archivedClient();
    const apiImage = picture();
    quote(viaApi, [{ name: 'Smoke 65 API client line', image: apiImage }]);
    const secret = crypto.randomBytes(16).toString('hex');
    made.apiKey = db.insert(`INSERT INTO api_keys SET api_key_name = ${db.q(made.name)}, api_key_secret = ${db.q(secret)}, api_key_decrypt_hash = '',
        api_key_expire = DATE_ADD(CURDATE(), INTERVAL 1 DAY), api_key_user_id = 1`);
    const answer = await page.evaluate(async ({ secret, id }) => {
        const r = await fetch('/api/v1/clients/delete.php', { method: 'POST', headers: { 'X-API-Key': secret, 'Content-Type': 'application/json' }, body: JSON.stringify({ client_id: id }) });
        return { status: r.status, text: await r.text() };
    }, { secret, id: viaApi });
    expect(Number(db.val(`SELECT COUNT(*) FROM clients WHERE client_id = ${viaApi}`)), `client deleted through the API (${answer.status} ${answer.text.slice(0, 200)})`).toBe(0);
    expect(exists(apiImage), "its quote's picture went").toBe(false);
});
