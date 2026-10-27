// The two checks that look across our code and ITFlow's (no browser needed):
//  - scripts/mit_hook_check.php: every hook we put in ITFlow's files is still there (scripts/mit_hooks.txt);
//  - scripts/mit_consistency_check.php: our data still agrees with ITFlow's on the test install (ITFLOW_ROOT, default
//    /var/www/itflow - skipped when that folder is not on this machine).

const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { test, expect } = require('@playwright/test');

function run(script) {
    try {
        return { code: 0, out: execFileSync('php', [script], { encoding: 'utf8', timeout: 120000 }) };
    } catch (e) {
        return { code: e.status, out: String(e.stdout || '') + String(e.stderr || '') };
    }
}

test('every hook in ITFlow\'s files is still there', async () => {
    const result = run(path.join(__dirname, '..', '..', 'scripts', 'mit_hook_check.php'));
    expect(result.code, result.out).toBe(0);
    expect(result.out).toContain('All hooks present');
});

test('our data agrees with ITFlow\'s on the test install', async () => {
    const root = process.env.ITFLOW_ROOT || '/var/www/itflow';
    test.skip(!fs.existsSync(path.join(root, 'scripts', 'mit_consistency_check.php')), `no install at ${root} (set ITFLOW_ROOT)`);
    const result = run(path.join(root, 'scripts', 'mit_consistency_check.php'));
    expect(result.code, result.out).toBe(0);
});
