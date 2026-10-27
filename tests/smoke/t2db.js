// Database helpers for the 5x scheduling tests: read and set up rows on the TEST install straight in MySQL (the mysql CLI as
// the user running the tests) and tidy up through ITFlow's own PHP (ticketDeleteForGood). ITFLOW_DB names the database,
// ITFLOW_ROOT the install (both in .env).

const { execFileSync } = require('child_process');

const DB = process.env.ITFLOW_DB || 'itflow';
const ROOT = process.env.ITFLOW_ROOT || '/var/www/itflow';

// Rows as objects (tab-separated output, NULL as null)
function sql(query) {
    const out = execFileSync('mysql', ['-B', DB, '-e', query], { encoding: 'utf8' });
    const lines = out.split('\n').filter((l) => l !== '');
    if (!lines.length) {
        return [];
    }
    const head = lines.shift().split('\t');
    return lines.map((line) => {
        const cells = line.split('\t');
        const row = {};
        head.forEach((h, i) => { row[h] = cells[i] === 'NULL' ? null : cells[i]; });
        return row;
    });
}

function one(query) {
    const rows = sql(query);
    return rows.length ? Object.values(rows[0])[0] : null;
}

// Run PHP with the install loaded ($mysqli, ITFlow's functions) - for tidying up
function php(code) {
    const boot = `chdir(${JSON.stringify(ROOT)}); require 'config.php'; require 'functions.php'; require 'includes/load_global_settings.php'; `
        + `$session_user_id = 1; $session_name = 'Smoke test'; `;
    return execFileSync('php', ['-r', boot + code], { encoding: 'utf8' });
}

function deleteTickets(ids) {
    ids = ids.filter(Boolean).map(Number);
    if (ids.length) {
        php(`foreach ([${ids.join(',')}] as $id) { mysqli_query($mysqli, "DELETE FROM mit_ticket_techs WHERE ticket_id = $id"); ticketDeleteForGood($id); }`);
    }
}

// An open ticket straight in the database: { subject, client, assigned, contact } -> ticket id
function makeTicket({ subject, client = 1, assigned = 0, contact = 0 }) {
    const n = 400000 + Math.floor(Math.random() * 90000);
    sql(`INSERT INTO tickets SET ticket_prefix = 'ZZ-', ticket_number = ${n}, ticket_subject = '${subject.replace(/'/g, "''")}', ticket_details = 'smoke', ticket_priority = 'Low',
        ticket_status = ${assigned ? 2 : 1}, ticket_created_by = 1, ticket_assigned_to = ${assigned}, ticket_client_id = ${client}, ticket_contact_id = ${contact}, ticket_url_key = 'zz${n}'`);
    return Number(one(`SELECT ticket_id FROM tickets WHERE ticket_number = ${n} AND ticket_prefix = 'ZZ-' ORDER BY ticket_id DESC LIMIT 1`));
}

module.exports = { sql, one, php, deleteTickets, makeTicket, DB, ROOT };
