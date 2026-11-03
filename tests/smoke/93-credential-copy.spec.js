// Credentials list: the Copy password button is twice ITFlow's size (functions/mit_theme.php mitThemeHead - every look), so it is
// easy to hit (ITFlow's copy buttons are .8 of the text around them, this one 1.6).
const { test, expect } = require('@playwright/test');
const { watch, visit } = require('./helpers');
const { sql, one } = require('./t2db');

// A login of its own (no username - nothing for the list to decrypt), removed afterwards
let credentialId = 0;
test.beforeAll(() => {
    sql(`INSERT INTO credentials SET credential_name = 'ZZ smoke 93 copy', credential_username = '', credential_password = '', credential_client_id = 1`);
    credentialId = Number(one(`SELECT MAX(credential_id) FROM credentials WHERE credential_name = 'ZZ smoke 93 copy'`));
});
test.afterAll(() => {
    sql(`DELETE FROM credentials WHERE credential_id = ${credentialId}`);
});

test('the Copy password button is twice the size of ITFlow copy buttons', async ({ page }) => {
    const errors = watch(page);
    await visit(page, 'agent/credentials.php?client_id=1', errors);
    const copy = page.locator(`.itflow-credential-pw[data-credential-id="${credentialId}"] .itflow-credential-copy`);
    await expect(copy).toBeVisible();
    // Against the text it sits in: ITFlow's copy buttons are .8 of it, this one 1.6
    const sizes = await page.evaluate((id) => {
        const pw = document.querySelector(`.itflow-credential-pw[data-credential-id="${id}"] .itflow-credential-copy`);
        const size = (el) => parseFloat(getComputedStyle(el).fontSize);
        return { ratio: size(pw) / size(pw.parentElement), height: pw.getBoundingClientRect().height };
    }, credentialId);
    expect(sizes.ratio).toBeCloseTo(1.6, 1);
    expect(sizes.height).toBeGreaterThanOrEqual(20);
    expect(errors).toEqual([]);
});
