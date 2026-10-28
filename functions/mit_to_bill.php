<?php

/*
 * Mountaineer IT - To Bill (26.10.182): ITFlow's Unbilled Time page (agent/unbilled_time.php, in the menu as "To Bill") also lists
 * the parts put on tickets and not billed yet (functions/mit_procurement_parts.php) - per client beside its tickets, expenses and
 * trips, in the totals and the menu badge - and Make Invoice(s) puts them on the same invoice (mitPartsBill). Our own Unbilled
 * Parts page (agent/mit_unbilled_parts.php) now sends people here.
 *
 * Hooks, each guarded: agent/unbilled_time.php (mitToBillMerge, mitToBillTotals, the To bill stat, the Parts column and cell,
 * mitToBillCard, the script), js/unbilled_time.js (mitToBillParts - amount, summary, button), agent/post/billing.php
 * (mitToBillMerge, mitToBillAfter, mitToBillFlash in unbilled_invoice / unbilled_invoice_clients), and the menu's label and
 * badge (agent/includes/get_side_nav_counts.php, side_nav.php, client_side_nav.php - mitToBillPartsCount).
 *
 * Who: the page needs Sales view (ITFlow's rule); parts show with Sales view, as on the old page, and go on an invoice only
 * with Sales edit and Support edit (mitToBillCanBill) - the rule of the ticket's Bill parts window. Parts need the
 * Procurement module (it keeps them).
 */

/** Parts are kept here, and this agent may see them on To Bill. */
function mitToBillOn() {
    return function_exists('mitModuleOn') && mitModuleOn('procurement') && mitProcReady() && mitTicketPartsReady()
        && lookupUserPermission('module_sales') >= 1;
}

/** This agent may put parts on an invoice. */
function mitToBillCanBill() {
    return mitToBillOn() && lookupUserPermission('module_sales') >= 2 && lookupUserPermission('module_support') >= 2;
}

/** Parts not billed yet, for the menu badge - every client's, or $client_id's. */
function mitToBillPartsCount($client_id = 0) {
    return mitToBillOn() ? mitUnbilledPartsCount($client_id) : 0;
}

/**
 * ITFlow's clients to bill (billingUnbilledByClient + expenseUnbilledMerge + tripUnbilledMerge) with each one's unbilled parts:
 * 'mit_parts' (part_id => the part, with 'line' = quantity x price and 'waiting' = days since it was put on) and
 * 'mit_part_value'. A client with only parts is added, built like ITFlow's own (its rate, its drafts). Sorted again by
 * everything it comes to.
 */
function mitToBillMerge(array $clients, $client_id = 0) {
    global $mysqli;
    foreach ($clients as $id => $c) {
        $clients[$id] += ['mit_parts' => [], 'mit_part_value' => 0.0];
    }
    if (!mitToBillOn()) {
        return $clients;
    }
    $today = strtotime(date('Y-m-d'));
    $added = [];
    foreach (mitUnbilledParts(intval($client_id)) as $part_id => $part) {
        $id = intval($part['client_id']);
        if (!$id) {
            continue;
        }
        if (!isset($clients[$id])) {
            $rate = function_exists('contractClientRate') ? contractClientRate($id) : ['rate' => 0, 'source' => 'default', 'note' => ''];
            $client = mysqli_fetch_assoc(mysqli_query($mysqli, "SELECT client_name, client_abbreviation FROM clients WHERE client_id = $id"));
            $clients[$id] = ['client_id' => $id, 'client_name' => $client['client_name'] ?? $part['client_name'], 'client_abbreviation' => $client['client_abbreviation'] ?? '',
                'rate' => $rate['rate'], 'default_rate' => $rate['source'] === 'default', 'rate_note' => $rate['note'], 'rate_source' => $rate['source'], 'rates' => [], 'tickets' => [],
                'seconds' => 0, 'hours' => 0.0, 'value' => 0.0, 'oldest' => 0, 'no_time' => 0, 'drafts' => [],
                'expenses' => [], 'expense_cost' => 0.0, 'expense_value' => 0.0, 'trips' => [], 'trip_value' => 0.0, 'mit_parts' => [], 'mit_part_value' => 0.0];
            $added[] = $id;
        }
        $part['line'] = round((float) $part['quantity'] * (float) $part['unit_price'], 2);
        $part['waiting'] = max(0, intval(floor(($today - strtotime(date('Y-m-d', strtotime((string) $part['created_at'])))) / 86400)));
        $clients[$id]['mit_parts'][$part_id] = $part;
        $clients[$id]['mit_part_value'] = round($clients[$id]['mit_part_value'] + $part['line'], 2);
        $clients[$id]['oldest'] = max($clients[$id]['oldest'], $part['waiting']);
    }
    if ($added) {
        $sql = mysqli_query($mysqli, "SELECT invoice_id, invoice_prefix, invoice_number, invoice_scope, invoice_date, invoice_amount, invoice_client_id FROM invoices
            WHERE invoice_status = 'Draft' AND invoice_archived_at IS NULL AND invoice_client_id IN (" . implode(',', $added) . ")
            ORDER BY invoice_date DESC, invoice_id DESC");
        while ($row = mysqli_fetch_assoc($sql)) {
            $clients[intval($row['invoice_client_id'])]['drafts'][intval($row['invoice_id'])] = $row;
        }
    }
    $worth = function ($c) {
        return $c['value'] + ($c['expense_value'] ?? 0) + ($c['trip_value'] ?? 0) + $c['mit_part_value'];
    };
    uasort($clients, function ($a, $b) use ($worth) {
        return $worth($b) <=> $worth($a);
    });
    return $clients;
}

/** What the page's clients have in parts: ['parts' => how many, 'value' => what they come to]. */
function mitToBillTotals(array $clients) {
    $total = ['parts' => 0, 'value' => 0.0];
    foreach ($clients as $c) {
        $total['parts'] += count($c['mit_parts'] ?? []);
        $total['value'] += $c['mit_part_value'] ?? 0;
    }
    $total['value'] = round($total['value'], 2);
    return $total;
}

/** The "To bill" stat's note: " · $X of parts" (HTML), or '' with none. */
function mitToBillStatNote(array $mit, callable $money) {
    return $mit['parts'] ? ' &middot; ' . $money($mit['value']) . ' of parts' : '';
}

/** The every-client table's Parts heading (HTML), when any client has parts. */
function mitToBillHead(array $mit) {
    return $mit['parts'] ? '<th class="text-end d-none d-md-table-cell">Parts</th>' : '';
}

/** A client row's Parts cell (HTML), when any client has parts. */
function mitToBillCell(array $c, array $mit, callable $money) {
    if (!$mit['parts']) {
        return '';
    }
    $n = count($c['mit_parts'] ?? []);
    return '<td class="text-end text-nowrap d-none d-md-table-cell" data-mit-to-bill-parts>' . ($n
        ? $money($c['mit_part_value']) . '<div class="small text-body-secondary">' . $n . ' part' . ($n === 1 ? '' : 's') . '</div>'
        : '<span class="text-body-secondary">-</span>') . '</td>';
}

/** A client row's attributes for the page's total (js/mit_to_bill.js) - its parts are billed with it only when this agent can. */
function mitToBillRowAttrs(array $c) {
    $billed = mitToBillCanBill();
    return ' data-mit-parts="' . ($billed ? count($c['mit_parts'] ?? []) : 0) . '" data-mit-part-value="' . ($billed ? round($c['mit_part_value'] ?? 0, 2) : 0) . '"';
}

/** One client's "Parts to bill" card (HTML) - beside its expenses and travel, ticked and priced like them. */
function mitToBillCard($this_client, callable $money, callable $waiting_pill) {
    global $session_company_currency, $session_company_locale;
    $parts = $this_client['mit_parts'] ?? [];
    if (!$parts) {
        return '';
    }
    $can_bill = mitToBillCanBill();
    $see_tickets = lookupUserPermission('module_support') >= 1;
    $client_id = intval($this_client['client_id']);
    ob_start(); ?>
    <!-- Mountaineer IT: parts put on their tickets, to bill (functions/mit_to_bill.php) -->
    <div class="card mb-3" data-mit-to-bill-card data-currency="<?= escapeHtml((string) $session_company_currency) ?>" data-locale="<?= escapeHtml(str_replace('_', '-', (string) $session_company_locale)) ?>">
        <div class="card-header d-flex align-items-center">
            <h3 class="card-title mb-0 me-auto"><i class="fas fa-fw fa-microchip text-secondary me-2"></i>Parts to bill <span class="badge rounded-pill bg-secondary-subtle text-secondary-emphasis fw-normal ms-1"><?= count($parts) ?></span></h3>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 itflow-billing-table itflow-unbilled-table">
                <thead class="text-nowrap table-light">
                <tr>
                    <?php if ($can_bill) { ?>
                        <th class="checkbox-column border-end">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" checked data-mit-part-all title="Tick or untick them all" aria-label="All parts">
                            </div>
                        </th>
                    <?php } ?>
                    <th>Part</th>
                    <th class="d-none d-md-table-cell">Put on</th>
                    <th class="text-end">Qty</th>
                    <th class="text-end">Price</th>
                    <th class="text-end d-none d-sm-table-cell">Amount</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($parts as $part_id => $part) {
                    $reference = $part['ticket_number'] !== null ? ticketReference($part) : ''; ?>
                    <tr data-mit-part-row data-qty="<?= (float) $part['quantity'] ?>">
                        <?php if ($can_bill) { ?>
                            <td class="checkbox-column border-end">
                                <div class="form-check">
                                    <!-- data-unbilled-expense-pick / -price: ITFlow's page script works the total out again when they change -->
                                    <input class="form-check-input" type="checkbox" name="mit_part_ids[]" value="<?= intval($part_id) ?>" checked data-mit-part-pick data-unbilled-expense-pick aria-label="Bill <?= escapeHtml($part['name']) ?>">
                                </div>
                            </td>
                        <?php } ?>
                        <td class="min-w-0">
                            <span class="fw-semibold"><?= escapeHtml($part['name']) ?></span>
                            <div class="small text-body-secondary">
                                <?php if ($reference !== '' && $see_tickets) { ?>
                                    <a href="ticket.php?client_id=<?= $client_id ?>&amp;ticket_id=<?= intval($part['ticket_id']) ?>"><span class="font-monospace me-1"><?= escapeHtml($reference) ?></span><?= escapeHtml((string) $part['ticket_subject']) ?></a>
                                <?php } elseif ($reference !== '') { ?>
                                    <span class="font-monospace"><?= escapeHtml($reference) ?></span>
                                <?php } ?>
                                <span class="ms-1"><?= mitTicketPartStatus($part) ?></span>
                            </div>
                        </td>
                        <td class="text-nowrap d-none d-md-table-cell">
                            <div class="small"><?= escapeHtml(date('Y-m-d', strtotime((string) $part['created_at']))) ?></div>
                            <?= $waiting_pill($part['waiting']) ?>
                        </td>
                        <td class="text-end text-nowrap"><?= escapeHtml(mitQty($part['quantity'])) ?></td>
                        <td class="text-end itflow-unbilled-price">
                            <?php if ($can_bill) { ?>
                                <input type="text" class="form-control form-control-sm text-end" name="mit_part_price[<?= intval($part_id) ?>]" value="<?= number_format((float) $part['unit_price'], 2, '.', '') ?>" inputmode="decimal" aria-label="Price each" data-mit-part-price data-unbilled-expense-price>
                            <?php } else { ?>
                                <?= $money($part['unit_price']) ?>
                            <?php } ?>
                        </td>
                        <td class="text-end text-nowrap fw-semibold d-none d-sm-table-cell" data-mit-part-line><?= $money($part['line']) ?></td>
                    </tr>
                <?php } ?>
                </tbody>
            </table>
        </div>
        <div class="card-footer small text-body-secondary">
            At the price set on the ticket, each. Change a price before billing; each goes on as a line of its own, with its ticket in the description.
            <?= $can_bill ? '' : ' Billing parts takes Sales and Support edit.' ?>
        </div>
    </div>
    <?php return ob_get_clean();
}

/** The script line (HTML) for the page's parts total - before ITFlow's own page script starts on DOMContentLoaded. */
function mitToBillScript() {
    return mitToBillOn() ? '<script src="' . mitAsset('/js/mit_to_bill.js') . '"></script>' : '';
}

/**
 * Hook after ITFlow's expenseBillWithTime() in agent/post/billing.php: the client's ticked parts (one client's page) or all of
 * them ($c - a client of the every-client page) go on the invoice ITFlow made or added to, or - when only parts were ticked -
 * on the draft picked or a new one. $itflow_had: ITFlow was given tickets, expenses or trips (so a null $result is its
 * refusal, and the parts wait). Returns the result for ITFlow's message ($error set when nothing went on); what the parts came
 * to is added to the message by mitToBillFlash().
 */
function mitToBillAfter($client_id, $result, &$error, $itflow_had, $c = null) {
    global $session_name;
    $client_id = intval($client_id);
    if (!mitToBillCanBill() || !hasClientAccess($client_id)) {
        return $result;
    }
    if ($c === null) {
        // One client: the ticked ones at the prices typed
        $ids = array_unique(array_filter(array_map('intval', (array) ($_POST['mit_part_ids'] ?? []))));
        $prices = array_intersect_key((array) ($_POST['mit_part_price'] ?? []), array_flip($ids));
        $parts = $ids ? array_intersect_key(mitUnbilledParts($client_id), array_flip($ids)) : [];
    } else {
        $parts = $c['mit_parts'] ?? [];
        $prices = [];
    }
    if (!$parts) {
        return $result;
    }
    if (!$result && $itflow_had) {
        // ITFlow refused what it was given - the parts wait with it
        return $result;
    }
    if ($result) {
        $invoice_id = intval($result['invoice_id']);
    } elseif ($c === null) {
        $invoice_id = intval($_POST['invoice_id'] ?? 0);
    } else {
        // Every client, "each one's newest draft": the newest that isn't in a QuickBooks batch, else a new one
        $invoice_id = ($_POST['target'] ?? '') === 'draft' && !empty($c['drafts']) ? intval(array_key_first($c['drafts'])) : 0;
        if ($invoice_id && function_exists('mitQboInvoiceLock') && mitQboInvoiceLock($invoice_id) !== '') {
            $invoice_id = 0;
        }
    }
    $billed = mitPartsBill($client_id, $parts, $prices, $invoice_id, [
        'date' => (string) ($_POST['date'] ?? ''),
        'category_id' => intval($_POST['category'] ?? 0),
        'scope' => (string) ($_POST['scope'] ?? ''),
        'tax_id' => isset($_POST['tax_id']) ? intval($_POST['tax_id']) : null,
        'with_ticket' => true,
        'by' => stripslashes((string) $session_name),
    ]);
    if (!$billed['invoice_id']) {
        if ($result) {
            $result['error'] = trim($result['error'] . ' Parts: ' . $billed['error']);
        } else {
            $error = $billed['error'];
        }
        return $result;
    }
    $GLOBALS['mit_to_bill_billed'][] = $billed;
    if (!$result) {
        $result = ['invoice_id' => $billed['invoice_id'], 'number' => $billed['reference'], 'new' => $billed['new'], 'time' => null, 'expenses' => null, 'trips' => null, 'error' => ''];
    }
    return $result;
}

/** Hook after ITFlow's flashAlert in those handlers: the parts that went on, added to its message (it keeps one). */
function mitToBillFlash() {
    global $session_company_currency;
    $billed = $GLOBALS['mit_to_bill_billed'] ?? [];
    if (!$billed) {
        return;
    }
    $n = array_sum(array_column($billed, 'count'));
    $amount = array_sum(array_column($billed, 'amount'));
    $text = '<strong>' . $n . ' part' . ($n === 1 ? '' : 's') . '</strong> billed - ' . escapeHtml(function_exists('billingMoney') ? billingMoney($amount, $session_company_currency) : number_format($amount, 2));
    // Every client with only parts ticked: ITFlow's "made or added to - : INV-1" has nothing before the colon
    $message = str_replace(' - : ', ': ', (string) ($_SESSION['alert_message'] ?? ''));
    flashAlert($message !== '' ? $message . '. ' . $text : $text . ' on ' . escapeHtml(implode(', ', array_column($billed, 'reference'))), $_SESSION['alert_type'] ?? 'success');
    unset($GLOBALS['mit_to_bill_billed']);
}
