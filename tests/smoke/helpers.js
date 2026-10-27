// Shared steps for the smoke tests.

const { expect } = require('@playwright/test');

// What PHP prints when something is wrong (display_errors on a test install)
const PHP_ERROR = /Fatal error|Parse error|Uncaught|Warning:|Notice:|Deprecated:|mysqli_sql_exception|Stack trace:/;

// A name nobody else uses, so the tests find - and tidy up - only their own records
function smokeName(what) {
    return `Smoke test ${what} ${new Date().toISOString().replace(/\D/g, '').slice(0, 14)}`;
}

// Collect JavaScript errors on a page; check them with expectClean()
function watch(page) {
    const errors = [];
    page.on('pageerror', (e) => errors.push(e.message));
    return errors;
}

async function expectClean(page, errors = []) {
    const text = await page.locator('body').innerText().catch(() => '');
    const php = PHP_ERROR.exec(text);
    expect(php ? text.slice(Math.max(0, php.index - 100), php.index + 300) : '', `PHP error on ${page.url()}`).toBe('');
    expect(errors, `JavaScript errors on ${page.url()}`).toEqual([]);
}

// Open a page and check it loaded cleanly, logged in
async function visit(page, url, errors = []) {
    const response = await page.goto(url);
    expect(response.status(), `HTTP status of ${url}`).toBeLessThan(400);
    expect(page.url(), `${url} sent us to the login page`).not.toMatch(/\/login\.php/);
    await expectClean(page, errors);
    return response;
}

// The session's CSRF token, from any form or link on the page
async function csrf(page) {
    const token = await page.evaluate(() => {
        const input = document.querySelector('input[name=csrf_token]');
        if (input && input.value) {
            return input.value;
        }
        const link = document.querySelector('a[href*="csrf_token="]');
        return link ? new URL(link.href).searchParams.get('csrf_token') : '';
    });
    expect(token, 'CSRF token on the page').toBeTruthy();
    return token;
}

// Open one of ITFlow's ajax modals the way its buttons do, and wait for its form
async function openModal(page, url, size = '') {
    await page.evaluate(([url, size]) => {
        const button = document.createElement('button');
        button.className = 'ajax-modal d-none';
        button.dataset.modalUrl = url;
        if (size) {
            button.dataset.modalSize = size;
        }
        document.body.appendChild(button);
        button.click();
    }, [url, size]);
    const modal = page.locator('.modal.show').last();
    await modal.locator('form').first().waitFor({ state: 'attached' });
    await page.waitForTimeout(300); // select2 / TinyMCE settle
    await expectClean(page);
    return modal;
}

/*
 * Fill form fields by name, inside `scope` (a form or modal locator). Works
 * on selects hidden behind select2 and on TinyMCE editors. Values: string,
 * array (multi-select) or boolean (checkbox / radio by value).
 */
async function fillForm(scope, values) {
    await scope.evaluate((root, values) => {
        for (const [name, value] of Object.entries(values)) {
            const fields = root.querySelectorAll(`[name="${name}"]`);
            if (!fields.length) {
                throw new Error(`No field named ${name}`);
            }
            for (const field of fields) {
                if (field.type === 'radio') {
                    field.checked = field.value === String(value);
                } else if (field.type === 'checkbox') {
                    field.checked = !!value;
                } else if (field.multiple) {
                    for (const option of field.options) {
                        option.selected = value.map(String).includes(option.value);
                    }
                } else {
                    if (field.disabled) {
                        continue;
                    }
                    field.value = String(value);
                    if (field.tagName === 'TEXTAREA' && window.tinymce && field.id && window.tinymce.get(field.id)) {
                        window.tinymce.get(field.id).setContent(String(value));
                    }
                }
                field.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }
    }, values);
}

// Submit with the named button and wait for the redirect back
async function submit(scope, buttonName) {
    const page = scope.page();
    await Promise.all([
        page.waitForNavigation(),
        scope.evaluate((root, name) => {
            if (window.tinymce) {
                window.tinymce.triggerSave();
            }
            const button = root.querySelector(`button[name="${name}"], input[type=submit][name="${name}"]`);
            if (!button) {
                throw new Error(`No submit button named ${name}`);
            }
            // Say which field the browser would stop on, rather than wait for a page that never comes
            const invalid = [...button.form.elements].filter((field) => field.willValidate && !field.checkValidity());
            if (invalid.length) {
                throw new Error('The form would not send: ' + invalid.map((field) => `${field.name} (${field.validationMessage})`).join(', '));
            }
            button.form.requestSubmit(button);
        }, buttonName),
    ]);
    await expectClean(page);
}

// The message ITFlow showed after the last action
async function flash(page) {
    return (await page.locator('.toast-body, .alert').allInnerTexts()).join(' | ').replace(/\s+/g, ' ');
}

// A GET action link (archive, delete...) with the session's token, read from the page `from`
async function action(page, query, from) {
    await page.goto(from);
    const token = await csrf(page);
    const response = await page.goto(`agent/post.php?${query}&csrf_token=${encodeURIComponent(token)}`);
    expect(response.status()).toBeLessThan(400);
    await expectClean(page);
}

// Ask one of the JSON endpoints from inside the page, as its own scripts do
async function fetchText(page, url) {
    return page.evaluate(async (url) => {
        const response = await fetch(url, { credentials: 'same-origin' });
        return { status: response.status, text: await response.text() };
    }, new URL(url, page.url().replace(/[^/]*$/, '')).href);
}

async function getJson(page, url) {
    const { status, text } = await fetchText(page, url);
    expect(status, `HTTP status of ${url}`).toBe(200);
    expect(text, `PHP error in ${url}`).not.toMatch(PHP_ERROR);
    return JSON.parse(text);
}

// Empty a time field as a person deleting it would (fill('') focuses it after, and js/mit_time_inputs.js then starts it from now)
async function clearTime(locator) {
    await locator.evaluate((el) => {
        el.value = '';
        el.dispatchEvent(new Event('input', { bubbles: true }));
        el.dispatchEvent(new Event('change', { bubbles: true }));
    });
}

module.exports = { PHP_ERROR, clearTime, smokeName, watch, expectClean, visit, csrf, openModal, fillForm, submit, flash, action, fetchText, getJson };
