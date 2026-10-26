// Read / set rows on this test install's database (mysql CLI, root) - the checks underneath the pages.
const { execFileSync } = require('child_process');

const DB = process.env.ITFLOW_DB || 'itflow';

// mysql --batch writes tab, newline, backslash and NUL as \\t \\n \\\\ \\0
function unescape(cell) {
    return cell.replace(/\\(.)/g, (m, c) => ({ n: '\n', t: '\t', 0: '\0', '\\': '\\' }[c] ?? c));
}

/** Rows of a query as objects (all values strings, NULL as null). */
function rows(sql) {
    const out = execFileSync('mysql', ['--batch', DB, '-e', sql], { encoding: 'utf8' });
    const lines = out.split('\n').filter((l) => l !== '');
    if (!lines.length) {
        return [];
    }
    const head = lines[0].split('\t');
    return lines.slice(1).map((l) => {
        const cells = l.split('\t');
        return Object.fromEntries(head.map((h, i) => [h, cells[i] === 'NULL' ? null : unescape(cells[i])]));
    });
}

/** The first column of the first row (or null). */
function value(sql) {
    const r = rows(sql);
    return r.length ? Object.values(r[0])[0] : null;
}

/** One column of every row. */
function column(sql) {
    return rows(sql).map((r) => Object.values(r)[0]);
}

function run(sql) {
    execFileSync('mysql', [DB, '-e', sql]);
}

function q(s) {
    return "'" + String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";
}

module.exports = { rows, value, column, run, q };
