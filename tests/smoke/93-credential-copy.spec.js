// Credentials list: a login's three copy buttons - username, password, link - are the size of the text beside them
// (functions/mit_theme.php mitThemeHead - every look). ITFlow's copy buttons are .8 of it; the user chose 1.0 for all three
// (26.11.1.8).
const { test, expect } = require('@playwright/test');
const { watch, visit } = require('./helpers');
const { sql, one, php } = require('./t2db');

// A login of its own with a link (no username - the list can't decrypt one written here), removed afterwards
let credentialId = 0;
test.beforeAll(() => {
    sql(`INSERT INTO credentials SET credential_name = 'ZZ smoke 93 copy', credential_username = '', credential_password = '', credential_uri = 'https://zz93.example.com', credential_client_id = 1`);
    credentialId = Number(one(`SELECT MAX(credential_id) FROM credentials WHERE credential_name = 'ZZ smoke 93 copy'`));
});
test.afterAll(() => {
    sql(`DELETE FROM credentials WHERE credential_id = ${credentialId}`);
});

test('username, password and link copy buttons are the size of their text', async ({ page }) => {
    const errors = watch(page);
    await visit(page, 'agent/credentials.php?client_id=1', errors);
    const pw = page.locator(`.itflow-credential-pw[data-credential-id="${credentialId}"]`);
    await expect(pw).toBeVisible();
    // The username as ITFlow draws it, put where the list draws it (its "-" for no username)
    const userHtml = php(`echo credentialUsernameHtml('zz93user');`);
    const ratios = await page.evaluate(({ id, userHtml }) => {
        const cell = document.querySelector(`.itflow-credential-pw[data-credential-id="${id}"]`).closest('td');
        const userLine = cell.querySelector('.fa-user').parentElement;
        userLine.querySelector('.text-body-secondary:not(i)').outerHTML = userHtml;
        const size = (el) => parseFloat(getComputedStyle(el).fontSize);
        const ratio = (button) => size(button) / size(button.parentElement);
        return {
            user: ratio(cell.querySelector('.itflow-credential-user + .itflow-copy')),
            password: ratio(cell.querySelector('.itflow-credential-copy')),
            link: ratio(cell.querySelector('.itflow-copy[title="Copy link"]')),
        };
    }, { id: credentialId, userHtml });
    expect(ratios.user).toBeCloseTo(1, 1);
    expect(ratios.password).toBeCloseTo(1, 1);
    expect(ratios.link).toBeCloseTo(1, 1);
    expect(errors).toEqual([]);
});
