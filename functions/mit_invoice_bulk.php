<?php
/*
 * Mountaineer IT - more bulk actions on the invoices list (Nov 17 2026, 26.11.8.5 - the user's request: ITFlow's Bulk Action menu only sets a
 * category). Mark sent, record each invoice paid in full, send by email, cancel, delete - each one done the way ITFlow's own button on the
 * invoice does it (agent/post/invoice.php, agent/post/payment.php), and only on invoices that button would be offered for. Invoices that
 * can't take the action are left alone and named with why (QuickBooks batch, client out of reach, status, a reconciled payment ...).
 *
 * Hook: one line in agent/invoices.php's Bulk Action menu (mitInvoiceBulkMenu). Window agent/modals/mit_invoice/bulk.php, handler
 * agent/post/mit_invoice_bulk.php. No tables.
 */

/** The actions: key => [label, icon, what the window says, button class, done text]. */
function mitInvoiceBulkActions() {
    return [
        'sent' => ['Mark Sent', 'fa-check', 'Drafts are marked sent (sent outside ITFlow - nothing is emailed).', 'btn-primary', 'marked sent'],
        'email' => ['Send by Email', 'fa-paper-plane', 'Each invoice is emailed to its client\'s primary and billing contacts, as Quick Send does. Drafts become Sent.', 'btn-primary', 'emailed'],
        'pay' => ['Mark Paid', 'fa-credit-card', 'Each invoice gets one payment for what is still owed on it, so it is paid in full.', 'btn-success', 'marked paid'],
        'cancel' => ['Cancel', 'fa-times', 'Cancelled invoices stay on record - nothing more is owed on them. What they took off the shelf goes back.', 'btn-danger', 'cancelled'],
        'delete' => ['Delete', 'fa-trash', 'Deleted for good, with their lines, payments and history. Their tickets become unbilled again, and their expenses and trips are to bill again.', 'btn-danger', 'deleted'],
    ];
}

/** May the signed-in agent use this bulk action? Same permissions as the invoice page's own buttons. */
function mitInvoiceBulkCan($action) {
    $sales = intval(lookupUserPermission('module_sales'));
    switch ($action) {
        case 'sent':
        case 'cancel':
            return $sales >= 2;
        case 'email':
            return $sales >= 2 && !empty($GLOBALS['config_smtp_provider']) && !(function_exists('mitQboConnected') && mitQboConnected());
        case 'pay':
            return $sales >= 2 && billingMoneyCan('take');
        case 'delete':
            return $sales >= 3;
    }
    return false;
}

/**
 * The ticked invoices kept in the session under a short token (agent/mit_invoice_bulk_ajax.php), so the window's address and its form carry the
 * token, not every id - 500 ids in the address were refused by Apache (414 "Request-URI Too Long" - "Error loading modal content", Nov 17), and a
 * form of more than PHP's max_input_vars (1000) loses fields. The newest 5 sets are kept.
 */
const MIT_INVOICE_BULK_MAX = 5000;

function mitInvoiceBulkRemember(array $invoice_ids) {
    $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $invoice_ids)))), 0, MIT_INVOICE_BULK_MAX);
    $token = bin2hex(random_bytes(8));
    $sets = (array) ($_SESSION['mit_invoice_bulk'] ?? []);
    $sets[$token] = $ids;
    $_SESSION['mit_invoice_bulk'] = array_slice($sets, -5, null, true);
    return $token;
}

/** The invoice ids kept under a token ([] when it is not this session's). */
function mitInvoiceBulkRecall($token) {
    $token = (string) $token;
    return preg_match('/^[a-f0-9]{16}$/', $token) ? array_map('intval', (array) ($_SESSION['mit_invoice_bulk'][$token] ?? [])) : [];
}

/** The extra items for ITFlow's Bulk Action menu (agent/invoices.php), and the script that hands the ticked invoices over before the window opens. */
function mitInvoiceBulkMenu() {
    $html = '';
    foreach (mitInvoiceBulkActions() as $key => [$label, $icon]) {
        if (!mitInvoiceBulkCan($key)) {
            continue;
        }
        $danger = in_array($key, ['cancel', 'delete'], true);
        if ($danger && strpos($html, 'dropdown-divider') === false) {
            $html .= '<div class="dropdown-divider"></div>';
        }
        $html .= '<a class="dropdown-item' . ($danger ? ' text-danger' : '') . '" href="#" data-mit-invoice-bulk="' . $key . '">'
            . '<i class="fas fa-fw ' . $icon . ($danger ? '' : ' text-secondary') . ' me-2"></i>' . escapeHtml($label) . '...</a>';
    }
    if ($html === '') {
        return '';
    }
    return $html . '<script>
document.addEventListener("click", function (event) {
    var item = event.target.closest("[data-mit-invoice-bulk]");
    if (!item) { return; }
    event.preventDefault();
    var ids = Array.from(document.querySelectorAll("input.bulk-select[name=\'invoice_ids[]\']:checked")).map(function (box) { return box.value; });
    if (!ids.length) { return; }
    var body = new FormData();
    body.append("csrf_token", ' . json_encode($_SESSION['csrf_token'] ?? '') . ');
    body.append("ids", ids.join(","));
    fetch("mit_invoice_bulk_ajax.php", { method: "POST", body: body, credentials: "same-origin" }).then(function (r) { return r.json(); }).then(function (json) {
        if (!json.token) { throw new Error(json.error || "no token"); }
        var open = document.createElement("button");
        open.className = "ajax-modal d-none";
        open.dataset.modalUrl = "modals/mit_invoice/bulk.php?action=" + encodeURIComponent(item.dataset.mitInvoiceBulk) + "&set=" + json.token;
        document.body.appendChild(open);
        open.click();
        open.remove();
    }).catch(function () { alert("The invoices picked could not be handed over - reload the page and try again."); });
});
</script>';
}

/** The invoices picked, with what is owed on each, in number order (ids the agent can't reach are kept - their check says so). */
function mitInvoiceBulkRows(array $invoice_ids) {
    global $mysqli;
    $ids = array_values(array_unique(array_filter(array_map('intval', $invoice_ids))));
    if (!$ids) {
        return [];
    }
    $rows = [];
    $sql = mysqli_query($mysqli, "SELECT invoice_id, invoice_prefix, invoice_number, invoice_status, invoice_amount, invoice_currency_code, invoice_client_id, invoice_scope,
            client_name, COALESCE((SELECT SUM(payment_amount) FROM payments WHERE payment_invoice_id = invoice_id), 0) AS amount_paid
        FROM invoices LEFT JOIN clients ON client_id = invoice_client_id WHERE invoice_id IN (" . implode(',', $ids) . ") ORDER BY invoice_number ASC");
    while ($row = mysqli_fetch_assoc($sql)) {
        $row['balance_cents'] = intval(round(floatval($row['invoice_amount']) * 100)) - intval(round(floatval($row['amount_paid']) * 100));
        $rows[] = $row;
    }
    return $rows;
}

/** Why this invoice can't take the action ('' = it can). */
function mitInvoiceBulkWhyNot($action, array $row) {
    $status = (string) $row['invoice_status'];
    $invoice_id = intval($row['invoice_id']);
    if (!hasClientAccess($row['invoice_client_id'])) {
        return 'you can\'t reach its client';
    }
    if (function_exists('mitQboInvoiceLock') && mitQboInvoiceLock($invoice_id)) {
        return 'in a QuickBooks batch';
    }
    switch ($action) {
        case 'sent':
            return $status === 'Draft' ? '' : "already $status";
        case 'email':
            if (!in_array($status, array_merge(['Draft'], invoiceOpenStatuses()), true)) {
                return $status;
            }
            return billingEmailRecipients(intval($row['invoice_client_id'])) ? '' : 'no primary or billing contact with an email address';
        case 'pay':
            if ($status === 'Draft') {
                return 'a draft - mark it sent first';
            }
            if (!in_array($status, invoiceOpenStatuses(), true)) {
                return $status;
            }
            return $row['balance_cents'] > 0 ? '' : 'nothing owed';
        case 'cancel':
            return in_array($status, ['Cancelled', 'Paid', 'Non-Billable'], true) ? $status : '';
        case 'delete':
            return reconcileInvoiceLock($invoice_id) ? 'a payment on it is on a reconciled statement - cancel it instead' : '';
    }
    return 'unknown action';
}

/** Split the picked invoices into those the action is done to and those left alone: ['do' => rows, 'skip' => [[row, why], ...]]. */
function mitInvoiceBulkPlan($action, array $invoice_ids) {
    $plan = ['do' => [], 'skip' => []];
    foreach (mitInvoiceBulkRows($invoice_ids) as $row) {
        $why = mitInvoiceBulkWhyNot($action, $row);
        if ($why === '') {
            $plan['do'][] = $row;
        } else {
            $plan['skip'][] = [$row, $why];
        }
    }
    return $plan;
}

/**
 * Do it. $opts for 'sent': sent_method (one of getSentMethods()), note; for 'pay': date, account, method, reference, email_receipt.
 * Returns ['done' => [number, ...], 'skipped' => [number => why], 'total' => paid in cents by currency, 'emailed' => n receipts / emails].
 * Every invoice is checked again just before it is changed (another agent, the QuickBooks sync).
 */
function mitInvoiceBulkRun($action, array $invoice_ids, array $opts = []) {
    global $mysqli, $session_name;
    $result = ['done' => [], 'skipped' => [], 'total' => [], 'emailed' => 0];
    $paid_by_client = [];
    foreach (mitInvoiceBulkRows($invoice_ids) as $row) {
        $invoice_id = intval($row['invoice_id']);
        $client_id = intval($row['invoice_client_id']);
        $number = $row['invoice_prefix'] . $row['invoice_number'];
        $locked = $action === 'pay' && !mitLock("invoice_pay_$invoice_id", 5);
        if ($locked) {
            $result['skipped'][$number] = 'someone else is paying it right now';
            continue;
        }
        // Read again under the lock - what is owed may have changed since the list was drawn
        $fresh = mitInvoiceBulkRows([$invoice_id])[0] ?? null;
        $why = $fresh ? mitInvoiceBulkWhyNot($action, $fresh) : 'no longer exists';
        if ($why !== '') {
            $result['skipped'][$number] = $why;
            if ($action === 'pay') {
                mitUnlock("invoice_pay_$invoice_id");
            }
            continue;
        }
        $row = $fresh;
        $number_sql = escapeSql($number);
        switch ($action) {
            case 'sent':
                mysqli_query($mysqli, "UPDATE invoices SET invoice_status = 'Sent' WHERE invoice_id = $invoice_id");
                $history = 'Invoice marked sent by ' . stripslashes($session_name) . ' - ' . $opts['sent_method'] . ' (bulk)' . ($opts['note'] !== '' ? "\nNote: " . $opts['note'] : '');
                logHistory('Sent', escapeSql($history), $invoice_id);
                logAudit('Invoice', 'Edit', "$session_name marked invoice $number_sql sent - " . escapeSql($opts['sent_method']) . ' (bulk)', $client_id, $invoice_id);
                break;

            case 'email':
                $invoice = billingRow('invoice', $invoice_id);
                $recipients = billingEmailRecipients($client_id);
                [$subject, $email] = billingInvoiceEmail($invoice, ['kind' => 'invoice', 'note' => '']);
                $attach = [];
                if (billingReminderRules()['attach_pdf'] && ($file = billingEmailPdf(billingPdf('invoice', $invoice)))) {
                    $attach[] = $file;
                }
                $sent = billingEmailQueue($recipients, $subject, $email, ['attach' => $attach]);
                $texted = function_exists('smsInvoiceNotify') ? smsInvoiceNotify($invoice, 'invoice', $recipients) : [];
                billingLog('invoice', $invoice_id, $invoice['invoice_status'] === 'Draft' ? 'Sent' : $invoice['invoice_status'],
                    'Invoice emailed by ' . stripslashes($session_name) . ' to ' . implode(', ', $sent) . ($attach ? ' with the PDF' : '') . ($texted ? ' - and texted to ' . implode(', ', $texted) : '') . ' (bulk)');
                foreach ($recipients as $recipient) {
                    logAudit('Invoice', 'Email', "$session_name emailed " . escapeSql($recipient['email']) . " invoice $number_sql (bulk)", $client_id, $invoice_id);
                }
                if ($invoice['invoice_status'] === 'Draft') {
                    mysqli_query($mysqli, "UPDATE invoices SET invoice_status = 'Sent' WHERE invoice_id = $invoice_id");
                }
                $result['emailed']++;
                break;

            case 'pay':
                $amount = $row['balance_cents'] / 100;
                $currency = escapeSql($row['invoice_currency_code']);
                mysqli_query($mysqli, "INSERT INTO payments SET payment_date = '" . escapeSql($opts['date']) . "', payment_amount = $amount, payment_currency_code = '$currency',
                    payment_account_id = " . intval($opts['account']) . ", payment_method = '" . escapeSql($opts['method']) . "', payment_reference = '" . escapeSql($opts['reference']) . "',
                    payment_invoice_id = $invoice_id");
                $status = updateInvoiceStatusFromPayments($invoice_id);
                mysqli_query($mysqli, "INSERT INTO history SET history_status = '" . escapeSql($status) . "', history_description = 'Payment added (bulk)', history_invoice_id = $invoice_id");
                logAudit('Invoice', 'Payment', 'Payment amount of ' . escapeSql(billingMoney($amount, $row['invoice_currency_code'])) . " added to invoice $number_sql (bulk)", $client_id, $invoice_id);
                mitUnlock("invoice_pay_$invoice_id");
                triggerCustomAction('invoice_pay', $invoice_id);
                $result['total'][$row['invoice_currency_code']] = ($result['total'][$row['invoice_currency_code']] ?? 0) + $row['balance_cents'];
                $paid_by_client[$client_id][] = ['invoice_id' => $invoice_id, 'amount' => $amount, 'currency' => $row['invoice_currency_code']];
                break;

            case 'cancel':
                mysqli_query($mysqli, "UPDATE invoices SET invoice_status = 'Cancelled' WHERE invoice_id = $invoice_id");
                mysqli_query($mysqli, "INSERT INTO history SET history_status = 'Cancelled', history_description = 'Invoice cancelled by $session_name (bulk)', history_invoice_id = $invoice_id");
                productStockReturnInvoice($invoice_id, "$number cancelled");
                logAudit('Invoice', 'Edit', "$session_name cancelled invoice $number_sql (bulk)", $client_id, $invoice_id);
                break;

            case 'delete':
                // As ITFlow's delete_invoice: stock back first, then the invoice, its lines, history and payments; what was billed on it is to bill again
                productStockReturnInvoice($invoice_id, "$number deleted");
                mysqli_query($mysqli, "DELETE FROM invoices WHERE invoice_id = $invoice_id");
                mysqli_query($mysqli, "DELETE FROM invoice_items WHERE item_invoice_id = $invoice_id");
                mysqli_query($mysqli, "DELETE FROM history WHERE history_invoice_id = $invoice_id");
                mysqli_query($mysqli, "DELETE FROM payments WHERE payment_invoice_id = $invoice_id");
                mysqli_query($mysqli, "UPDATE tickets SET ticket_invoice_id = 0 WHERE ticket_invoice_id = $invoice_id");
                mysqli_query($mysqli, "UPDATE assets SET asset_invoice_id = 0, asset_invoice_item_id = 0 WHERE asset_invoice_id = $invoice_id");
                mysqli_query($mysqli, "UPDATE expenses SET expense_invoice_id = NULL, expense_invoice_item_id = NULL WHERE expense_invoice_id = $invoice_id");
                mysqli_query($mysqli, "UPDATE trips SET trip_invoice_id = 0, trip_invoice_item_id = 0 WHERE trip_invoice_id = $invoice_id");
                logAudit('Invoice', 'Delete', "$session_name deleted invoice $number_sql (bulk)", $client_id);
                break;
        }
        $result['done'][] = $number;
    }

    // One receipt per client, listing each invoice paid (as ITFlow's Batch Payment sends it)
    if ($action === 'pay' && !empty($opts['email_receipt']) && !empty($GLOBALS['config_smtp_provider'])) {
        foreach ($paid_by_client as $client_id => $entries) {
            foreach (array_unique(array_column($entries, 'currency')) as $currency) {
                $applied = [];
                foreach ($entries as $entry) {
                    if ($entry['currency'] === $currency) {
                        $applied[] = ['invoice' => billingRow('invoice', $entry['invoice_id'], false), 'amount' => $entry['amount']];
                    }
                }
                [$subject, $email] = billingPaymentEmail($applied, ['date' => $opts['date'], 'method' => $opts['method'], 'reference' => $opts['reference'], 'currency' => $currency]);
                $receipt_to = billingEmailQueue(billingEmailRecipients($client_id), $subject, $email);
                if ($receipt_to) {
                    foreach ($applied as $entry) {
                        billingLog('invoice', $entry['invoice']['invoice_id'], 'Sent', 'Payment receipt emailed to ' . implode(', ', $receipt_to));
                    }
                    $result['emailed']++;
                }
            }
        }
    }
    if ($result['done']) {
        logAudit('Invoice', 'Bulk Edit', "$session_name: " . escapeSql(mitInvoiceBulkActions()[$action][4]) . ' ' . count($result['done']) . ' invoice(s) - ' . escapeSql(implode(', ', $result['done'])));
    }
    return $result;
}

/** The flash after a run: what was done, what was left and why. HTML. */
function mitInvoiceBulkMessage($action, array $result) {
    $done = count($result['done']);
    $text = '<strong>' . $done . '</strong> invoice' . ($done === 1 ? '' : 's') . ' ' . escapeHtml(mitInvoiceBulkActions()[$action][4]);
    if ($action === 'pay' && $result['total']) {
        $text .= ' (' . escapeHtml(implode(', ', array_map(function ($currency, $cents) {
            return billingMoney($cents / 100, $currency);
        }, array_keys($result['total']), $result['total']))) . ')';
        if ($result['emailed']) {
            $text .= ' - ' . $result['emailed'] . ' receipt' . ($result['emailed'] === 1 ? '' : 's') . ' queued';
        }
    }
    if ($result['skipped']) {
        $list = [];
        foreach ($result['skipped'] as $number => $why) {
            $list[] = "$number ($why)";
        }
        $text .= '. Left alone: ' . escapeHtml(implode(', ', array_slice($list, 0, 15)) . (count($list) > 15 ? ' and ' . (count($list) - 15) . ' more' : ''));
    }
    return $text;
}
