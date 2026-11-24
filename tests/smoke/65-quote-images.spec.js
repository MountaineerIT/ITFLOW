// Quote line cost / pricing and pictures, and product pictures, are ITFlow's own since 26.12 (the Edit Line window, uploads/quote_images -
// built from ours). Ours on top: the API never gives out our cost - api/v1/products/read.php selects mitProductApiColumns() (every column
// but the cost / markup ones), and a quote's lines read through api/v1/quote_items/read.php carry no cost either. A product, a quote and
// an API key of its own, removed at the end.

const crypto = require('crypto');
const { test, expect } = require('@playwright/test');
const { visit, smokeName } = require('./helpers');
const db = require('./mitdb');

const CLIENT = Number(db.val('SELECT client_id FROM clients WHERE client_archived_at IS NULL ORDER BY client_id LIMIT 1'));
const made = { product: 0, quote: 0, key: 0, name: smokeName('api cost') };
const secret = crypto.randomBytes(16).toString('hex');

test.beforeAll(() => {
    made.product = db.insert(`INSERT INTO products SET product_name = ${db.q(made.name)}, product_price = 10, product_cost = 123.45, product_type = 'product',
        product_currency_code = 'USD', product_tax_id = 0, product_category_id = 0`);
    const n = Number(db.val('SELECT COALESCE(MAX(quote_number), 0) + 1 FROM quotes'));
    made.quote = db.insert(`INSERT INTO quotes SET quote_prefix = 'Q-', quote_number = ${n}, quote_scope = ${db.q(made.name)}, quote_date = CURDATE(),
        quote_expire = DATE_ADD(CURDATE(), INTERVAL 30 DAY), quote_amount = 100, quote_currency_code = 'USD', quote_status = 'Draft',
        quote_url_key = ${db.q('smoke' + Date.now())}, quote_client_id = ${CLIENT}, quote_category_id = 0`);
    db.exec(`INSERT INTO quote_items SET item_quote_id = ${made.quote}, item_name = ${db.q(made.name)}, item_description = '', item_quantity = 1, item_price = 100,
        item_subtotal = 100, item_tax = 0, item_total = 100, item_order = 1, item_tax_id = 0, item_cost = 67.89`);
    made.key = db.insert(`INSERT INTO api_keys SET api_key_name = ${db.q(made.name)}, api_key_secret = ${db.q(secret)}, api_key_decrypt_hash = '',
        api_key_expire = DATE_ADD(CURDATE(), INTERVAL 1 DAY), api_key_user_id = 1`);
});

test.afterAll(() => {
    db.exec(`DELETE FROM api_keys WHERE api_key_id = ${made.key || 0}; DELETE FROM quote_items WHERE item_quote_id = ${made.quote || 0};
        DELETE FROM quotes WHERE quote_id = ${made.quote || 0}; DELETE FROM products WHERE product_id = ${made.product || 0}`);
});

const read = (page, url) => page.evaluate(async ({ url, secret }) => (await fetch(url, { headers: { 'X-API-Key': secret } })).text(), { url, secret });

test('the products API never gives out our cost', async ({ page }) => {
    await visit(page, 'agent/products.php');
    for (const text of [await read(page, `/api/v1/products/read.php?product_id=${made.product}`), await read(page, '/api/v1/products/read.php?limit=1000')]) {
        expect(text, 'the product is in the answer').toContain(made.name);
        expect(text, 'no cost field').not.toContain('product_cost');
        expect(text, 'no cost value').not.toContain('123.45');
    }
});

test('a quote\'s lines through the API carry no cost', async ({ page }) => {
    await visit(page, 'agent/quotes.php');
    const text = await read(page, `/api/v1/quote_items/read.php?quote_id=${made.quote}`);
    expect(text, 'the line is in the answer').toContain(made.name);
    expect(text, 'no cost field').not.toContain('item_cost');
    expect(text, 'no cost value').not.toContain('67.89');
});
