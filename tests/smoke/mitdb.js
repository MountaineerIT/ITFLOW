// Test-only helper for the 6x specs: read / write the test install's database straight through the mysql client
// (ITFLOW_DB, default itflow), to check what the pages wrote and to make fixtures. Never point it at production.
const { execFileSync } = require('child_process');

const DB = process.env.ITFLOW_DB || 'itflow';

function rows(sql) {
    const out = execFileSync('mysql', [DB, '-B', '--raw', '-e', sql], { encoding: 'utf8' });
    const lines = out.split('\n').filter((l) => l !== '');
    if (!lines.length) {
        return [];
    }
    const head = lines[0].split('\t');
    return lines.slice(1).map((line) => {
        const cells = line.split('\t');
        const row = {};
        head.forEach((h, i) => { row[h] = cells[i] === 'NULL' ? null : cells[i]; });
        return row;
    });
}

function one(sql) {
    const r = rows(sql);
    return r.length ? r[0] : null;
}

function val(sql) {
    const r = one(sql);
    return r ? Object.values(r)[0] : null;
}

function exec(sql) {
    execFileSync('mysql', [DB, '-e', sql], { encoding: 'utf8' });
}

// INSERT ... and return its id
function insert(sql) {
    return Number(val(`${sql}; SELECT LAST_INSERT_ID() AS id`));
}

function q(s) {
    return "'" + String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";
}

// Run PHP inside the test install as the web server user would (functions loaded, an admin session)
function php(code) {
    const root = process.env.ITFLOW_ROOT || '/var/www/itflow';
    const script = `chdir(${JSON.stringify(root)}); require 'config.php'; require 'functions.php'; require 'includes/load_global_settings.php';
        $session_user_id = 1; $session_is_admin = true; $session_name = 'Smoke test'; $session_company_currency = mysqli_fetch_row(mysqli_query($mysqli, 'SELECT company_currency FROM companies WHERE company_id = 1'))[0]; ${code}`;
    return execFileSync('php', ['-r', script], { encoding: 'utf8' });
}

module.exports = { rows, one, val, exec, insert, q, php };

// In-page requests (the test host name only resolves inside the browser): POST a form, GET as text or as bytes
async function pagePost(page, url, fields) {
    return page.evaluate(async ({ url, fields }) => {
        const body = new URLSearchParams();
        for (const [k, v] of Object.entries(fields)) {
            if (Array.isArray(v)) { v.forEach((x) => body.append(k, x)); } else { body.append(k, v); }
        }
        const r = await fetch(url, { method: 'POST', body, credentials: 'same-origin' });
        return { status: r.status, url: r.url, text: await r.text() };
    }, { url: new URL(url, page.url()).href, fields });
}

async function pageGet(page, url, binary = false) {
    return page.evaluate(async ({ url, binary }) => {
        const r = await fetch(url, { credentials: 'same-origin' });
        if (!binary) {
            return { status: r.status, url: r.url, type: r.headers.get('content-type'), text: await r.text() };
        }
        const buf = new Uint8Array(await r.arrayBuffer());
        let s = '';
        for (let i = 0; i < buf.length; i += 0x8000) { s += String.fromCharCode.apply(null, buf.subarray(i, i + 0x8000)); }
        return { status: r.status, url: r.url, type: r.headers.get('content-type'), b64: btoa(s) };
    }, { url: new URL(url, page.url()).href, binary });
}

module.exports.pagePost = pagePost;
module.exports.pageGet = pageGet;
