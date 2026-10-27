// Logs in once per run (admin, and the technician when given) and keeps the
// sessions in .auth/ for the tests.

const fs = require('fs');
const path = require('path');
const { chromium } = require('@playwright/test');

async function login(config, email, password, file) {
    const use = config.projects[0].use;
    const browser = await chromium.launch(use.launchOptions);
    const page = await browser.newPage({ ignoreHTTPSErrors: use.ignoreHTTPSErrors });
    await page.goto(use.baseURL + 'login.php');
    await page.fill('input[name=email]', email);
    await page.fill('input[name=password]', password);
    await Promise.all([page.waitForNavigation(), page.click('button[name=login]')]);
    if (/login\.php/.test(page.url())) {
        await browser.close();
        throw new Error(`Could not log in as ${email} - check the password, and that the login has no 2FA (use a test-only login)`);
    }
    await page.context().storageState({ path: file });
    await browser.close();
}

module.exports = async (config) => {
    const dir = path.join(__dirname, '.auth');
    fs.mkdirSync(dir, { recursive: true });

    // Where the PHP error log stands now - 99-php-log.spec.js reads what the run adds
    const log = process.env.ITFLOW_PHP_LOG;
    fs.writeFileSync(path.join(dir, 'php-log-offset'), log && fs.existsSync(log) ? String(fs.statSync(log).size) : '');

    await login(config, process.env.ITFLOW_EMAIL, process.env.ITFLOW_PASSWORD, path.join(dir, 'admin.json'));
    const tech = path.join(dir, 'tech.json');
    if (process.env.ITFLOW_TECH_EMAIL && process.env.ITFLOW_TECH_PASSWORD) {
        await login(config, process.env.ITFLOW_TECH_EMAIL, process.env.ITFLOW_TECH_PASSWORD, tech);
    } else if (fs.existsSync(tech)) {
        fs.unlinkSync(tech);
    }
};
