<?php

/*
 * Mountaineer IT - QuickBooks Online invoice terms (M052; required from functions/mit_qbo.php).
 *
 * Every invoice sent to QuickBooks carries a Terms (SalesTermRef):
 *   a client with its own QuickBooks term (mit_qbo_client_terms, picked on the Customers tab - any term there, "Due on the 1st"
 *     included): that term, and no DueDate - QuickBooks works the due date out, and ITFlow's invoice_due follows it after the send
 *     (mitQboTermsAfterSend - the approval and batch fingerprints are put right with it, the change goes in the invoice's history);
 *   else the QuickBooks term with the invoice's days (due date - invoice date; 0 = "Due on receipt"), made there when it has none
 *     ("Net 45"), with ITFlow's due date as it is.
 * Invoices already in QuickBooks are left as they are (the user's choice).
 */

/** Has M052 run? (one query per request) */
function mitQboTermsReady() {
    global $mysqli;
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = mysqli_num_rows(mysqli_query($mysqli, "SHOW TABLES LIKE 'mit_qbo_client_terms'")) === 1;
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/** QuickBooks' terms, active ones: id => [id, name, type (STANDARD | DATE_DRIVEN), days, day_of_month]. Read once a request. */
function mitQboTerms($fresh = false) {
    static $list = null;
    if ($list === null || $fresh) {
        $list = [];
        foreach (mitQboQueryAll('Term', 'Active = true') as $row) {
            $list[(string) $row['Id']] = ['id' => (string) $row['Id'], 'name' => (string) ($row['Name'] ?? ''), 'type' => (string) ($row['Type'] ?? 'STANDARD'),
                'days' => isset($row['DueDays']) ? intval($row['DueDays']) : null, 'day_of_month' => isset($row['DayOfMonthDue']) ? intval($row['DayOfMonthDue']) : null];
        }
        uasort($list, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
    }
    return $list;
}

/** A client's own QuickBooks term: client id => [qbo_term_id, qbo_term_name]. */
function mitQboClientTerms() {
    global $mysqli;
    $rows = [];
    if (mitQboTermsReady()) {
        $result = mysqli_query($mysqli, "SELECT client_id, qbo_term_id, qbo_term_name FROM mit_qbo_client_terms");
        while ($row = mysqli_fetch_assoc($result)) {
            $rows[intval($row['client_id'])] = $row;
        }
    }
    return $rows;
}

/** Set (or clear, with '') a client's own QuickBooks term. */
function mitQboSetClientTerm($client_id, $term_id, $term_name = '') {
    global $mysqli, $session_user_id;
    $client_id = intval($client_id);
    $term_id = preg_replace('/[^0-9A-Za-z_-]/', '', (string) $term_id);
    if (!mitQboTermsReady()) {
        return;
    }
    if ($term_id === '') {
        mysqli_query($mysqli, "DELETE FROM mit_qbo_client_terms WHERE client_id = $client_id");
        return;
    }
    $name = escapeSql(mb_substr((string) $term_name, 0, 255));
    $by = intval($session_user_id ?? 0);
    mysqli_query($mysqli, "INSERT INTO mit_qbo_client_terms SET client_id = $client_id, qbo_term_id = '$term_id', qbo_term_name = '$name', updated_by = $by, updated_at = NOW()
        ON DUPLICATE KEY UPDATE qbo_term_id = '$term_id', qbo_term_name = '$name', updated_by = $by, updated_at = NOW()");
}

/** The QuickBooks term for a number of days - one there with those days, else one made ("Due on receipt" / "Net N"). Returns its id. */
function mitQboTermForDays($days, $create = true) {
    $days = max(0, intval($days));
    $want = $days === 0 ? 'Due on receipt' : "Net $days";
    $found = null;
    foreach (mitQboTerms() as $term) {
        if ($term['type'] === 'STANDARD' && $term['days'] === $days) {
            if (strcasecmp($term['name'], $want) === 0) {
                return $term['id'];
            }
            $found = $found ?? $term['id'];
        }
    }
    if ($found !== null || !$create) {
        return (string) $found;
    }
    // A name already taken (an inactive term) is made active again rather than made twice
    $taken = mitQboQueryAll('Term', 'Name = ' . mitQboQuote($want) . ' AND Active IN (true, false)');
    if ($taken) {
        $term = $taken[0];
        mitQboApi('POST', '/term', ['Id' => (string) $term['Id'], 'SyncToken' => (string) ($term['SyncToken'] ?? '0'), 'sparse' => true, 'Active' => true]);
        mitQboTerms(true);
        return (string) $term['Id'];
    }
    $answer = mitQboApi('POST', '/term', ['Name' => $want, 'Type' => 'STANDARD', 'DueDays' => $days]);
    mitQboTerms(true);
    return (string) ($answer['Term']['Id'] ?? '');
}

/**
 * Put the Terms on a QuickBooks invoice payload (from mitQboInvoicePayload): the client's own term (and no DueDate - QuickBooks
 * works it out), else the term with the invoice's days. A problem (QuickBooks refused to make the term) goes into $problems.
 */
function mitQboApplyTerms(array &$payload, array $invoice, array &$problems, $create = true) {
    if (!mitQboTermsReady()) {
        return;
    }
    $client_id = intval($invoice['invoice_client_id']);
    $own = mitQboClientTerms()[$client_id] ?? null;
    try {
        if ($own) {
            if (!isset(mitQboTerms()[$own['qbo_term_id']])) {
                $problems[] = 'The client\'s QuickBooks terms "' . $own['qbo_term_name'] . '" are no longer active in QuickBooks - pick others on the Customers tab.';
                return;
            }
            $payload['SalesTermRef'] = ['value' => (string) $own['qbo_term_id']];
            unset($payload['DueDate']);
            return;
        }
        $days = intval(round((strtotime((string) $invoice['invoice_due']) - strtotime((string) $invoice['invoice_date'])) / 86400));
        $term_id = mitQboTermForDays($days, $create);
        if ($term_id !== '') {
            $payload['SalesTermRef'] = ['value' => $term_id];
        }
    } catch (Throwable $e) {
        $problems[] = 'Could not set the QuickBooks terms: ' . $e->getMessage();
    }
}

/**
 * After QuickBooks made the invoice (mitQboSendEntry): when it worked the due date out (a client's own term), ITFlow's invoice
 * takes it - with a line in its history - and the approval / batch fingerprints follow, so it doesn't read "changed since approval".
 */
function mitQboTermsAfterSend($invoice_id, array $qbo, $entry_id) {
    global $mysqli;
    $invoice_id = intval($invoice_id);
    $due = (string) ($qbo['DueDate'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) {
        return;
    }
    $now = (string) (mysqli_fetch_row(mysqli_query($mysqli, "SELECT invoice_due FROM invoices WHERE invoice_id = $invoice_id"))[0] ?? '');
    if ($now === '' || substr($now, 0, 10) === $due) {
        return;
    }
    mysqli_query($mysqli, "UPDATE invoices SET invoice_due = '$due' WHERE invoice_id = $invoice_id");
    $fingerprint = escapeSql(mitInvoiceFingerprint($invoice_id));
    mysqli_query($mysqli, "UPDATE mit_invoice_approvals SET fingerprint = '$fingerprint' WHERE invoice_id = $invoice_id");
    mysqli_query($mysqli, "UPDATE mit_qbo_batch_invoices SET fingerprint = '$fingerprint' WHERE entry_id = " . intval($entry_id));
    $term = (string) ($qbo['SalesTermRef']['name'] ?? '');
    mysqli_query($mysqli, "INSERT INTO history SET history_status = 'Sent', history_description = '" . escapeSql('Due date set to ' . $due
        . ' by QuickBooks Online' . ($term !== '' ? " (terms: $term)" : '') . ', was ' . substr($now, 0, 10)) . "', history_invoice_id = $invoice_id");
}
