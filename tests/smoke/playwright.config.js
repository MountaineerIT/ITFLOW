// Smoke tests for the Mountaineer IT build of ITFlow - see README.md.
// Settings come from the environment (or a .env file next to this one):
//   ITFLOW_URL       the test install, e.g. https://itflow-beta.example.com
//   ITFLOW_EMAIL     an admin login        ITFLOW_PASSWORD
//   ITFLOW_TECH_EMAIL / ITFLOW_TECH_PASSWORD   optional: a Technician login for the permission checks
//   ITFLOW_HOST_IP   optional: send ITFLOW_URL's host name to this address (a test install with no DNS)

const fs = require('fs');
const path = require('path');
const { defineConfig } = require('@playwright/test');

const envFile = path.join(__dirname, '.env');
if (fs.existsSync(envFile)) {
    for (const line of fs.readFileSync(envFile, 'utf8').split(/\r?\n/)) {
        const m = line.match(/^\s*([A-Z_]+)\s*=\s*(.*?)\s*$/);
        if (m && process.env[m[1]] === undefined) {
            process.env[m[1]] = m[2].replace(/^(['"])(.*)\1$/, '$2');
        }
    }
}

for (const name of ['ITFLOW_URL', 'ITFLOW_EMAIL', 'ITFLOW_PASSWORD']) {
    if (!process.env[name]) {
        throw new Error(`Set ${name} (see tests/smoke/README.md)`);
    }
}

const baseURL = process.env.ITFLOW_URL.replace(/\/+$/, '') + '/';
const args = ['--no-proxy-server'];
if (process.env.ITFLOW_HOST_IP) {
    args.push(`--host-resolver-rules=MAP ${new URL(baseURL).hostname} ${process.env.ITFLOW_HOST_IP}`);
}

module.exports = defineConfig({
    testDir: __dirname,
    testMatch: /\d\d-.*\.spec\.js$/,
    globalSetup: require.resolve('./global-setup.js'),
    // One at a time: the tests share one install and one login
    workers: 1,
    fullyParallel: false,
    retries: 0,
    timeout: 60000,
    reporter: [['list'], ['html', { outputFolder: 'report', open: 'never' }]],
    use: {
        baseURL,
        storageState: path.join(__dirname, '.auth', 'admin.json'),
        ignoreHTTPSErrors: !!process.env.ITFLOW_IGNORE_HTTPS_ERRORS,
        viewport: { width: 1400, height: 1000 },
        launchOptions: { args, executablePath: process.env.ITFLOW_CHROMIUM || undefined },
        screenshot: 'only-on-failure',
        trace: 'retain-on-failure',
    },
});
