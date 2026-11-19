<?php
/*
 * Mountaineer IT - unlimited support hours on contracts (Nov 19, 26.11.8.8 - the user's request: IT services as unlimited time under monthly
 * agreements; the user chose: after-hours work covered too, the client sees "Unlimited support hours" in the portal and the signed contract).
 *
 * Built on ITFlow's own prepaid hours (functions/contract_hours.php): "Unlimited" stores MIT_CONTRACT_UNLIMITED_HOURS in ITFlow's
 * contract_monthly_hours (contract_template_monthly_hours for templates), so each month's included-hours block is that big, every billable
 * ticket's time is drawn in full (ITFlow's nightly draw / Draw now, history line, out of Unbilled Time), nothing is ever over and the
 * "running low" alert never fires. Any hours at or over MIT_CONTRACT_UNLIMITED_FLOOR are shown as "Unlimited" (no month holds that many real
 * hours - 744 in the longest). No tables of our own; on stock ITFlow such a contract keeps covering everything, shown as 99,999 h.
 *
 * Hooks, one line each: contractHoursText / contractHoursWords (functions/contract_hours.php - every page that prints hours), contractBlocks
 * (a past month's unlimited block keeps nothing "left", so none "ran out unused"), contractFromInput (functions/contract.php - after ITFlow's
 * hours check), the Unlimited switch in agent/modals/contract/contract_form.php and admin/modals/contract_template/contract_template_form.php,
 * the hours card's figures on agent/contract.php and client/contract.php, and the "Included hours" line of the contract document
 * (functions/contract_signature.php).
 */

const MIT_CONTRACT_UNLIMITED_HOURS = 99999.0;
const MIT_CONTRACT_UNLIMITED_FLOOR = 50000.0;

/** Whether an amount of hours stands for unlimited. */
function mitContractHoursUnlimited($hours) {
    return is_numeric($hours) && floatval($hours) >= MIT_CONTRACT_UNLIMITED_FLOOR;
}

/** Whether a contract (or template) row has unlimited hours each month. */
function mitContractUnlimited(array $row) {
    return mitContractHoursUnlimited($row['contract_monthly_hours'] ?? ($row['contract_template_monthly_hours'] ?? 0));
}

/** contractHoursText / contractHoursWords' hook: "Unlimited" / "Unlimited hours" for unlimited, else null (ITFlow's text). */
function mitContractHoursLabel($hours, $words = false) {
    if (!mitContractHoursUnlimited($hours)) {
        return null;
    }
    return $words ? 'Unlimited hours' : 'Unlimited';
}

/** contractBlocks' hook: a month that has passed holds nothing left on an unlimited block (else it "ran out unused" by 99,000 hours). */
function mitContractBlocksUnlimited(array $blocks) {
    foreach ($blocks as $id => $block) {
        if (!empty($block['expired']) && mitContractHoursUnlimited($block['contract_block_hours'] ?? 0)) {
            $blocks[$id]['left'] = 0.0;
        }
    }
    return $blocks;
}

/*
 * contractFromInput's hook, after ITFlow has checked the hours: the Unlimited switch (posted with mit_unlimited_present) sets the hours each
 * month to MIT_CONTRACT_UNLIMITED_HOURS and the "tell the owner under" alert off. Without the switch on the form it changes nothing.
 */
function mitContractUnlimitedInput(array $f, array $in) {
    if (empty($in['mit_unlimited_present'])) {
        return $f;
    }
    if (!empty($in['mit_unlimited_hours'])) {
        $f['monthly_hours'] = MIT_CONTRACT_UNLIMITED_HOURS;
        $f['hours_alert'] = 0.0;
    } elseif (mitContractHoursUnlimited($f['monthly_hours'] ?? 0)) {
        $f['monthly_hours'] = 0.0;
    }
    return $f;
}

/*
 * The Unlimited switch under "Hours included each month" (contract and template forms). $hours = the stored hours each month. Ticked, the hours
 * and alert boxes are emptied and locked (the server sets them); a template picked on New Contract that is unlimited ticks it (ITFlow's
 * js/contract_form.js fills the box with the big number).
 */
function mitContractUnlimitedField($hours) {
    $on = mitContractHoursUnlimited($hours);
    $id = 'mitUnlimited' . bin2hex(random_bytes(4));
    $floor = json_encode(MIT_CONTRACT_UNLIMITED_FLOOR);
    return '<input type="hidden" name="mit_unlimited_present" value="1">'
        . '<div class="form-check form-switch mt-2" data-mit-unlimited-wrap>'
        . '<input class="form-check-input" type="checkbox" role="switch" name="mit_unlimited_hours" value="1" id="' . $id . '" data-mit-unlimited' . ($on ? ' checked' : '') . '>'
        . '<label class="form-check-label" for="' . $id . '">Unlimited - every billable ticket is covered, after hours too</label></div>'
        . '<script>(function () {'
        . 'var box = document.getElementById(' . json_encode($id) . '); if (!box) { return; }'
        . 'var form = box.closest("form"); if (!form) { return; }'
        . 'var hours = form.querySelector("[name=monthly_hours]"), alert = form.querySelector("[name=hours_alert]");'
        . 'function sync() { [hours, alert].forEach(function (input) { if (!input) { return; }'
        . ' if (box.checked) { if (!input.dataset.mitWas) { input.dataset.mitWas = Number(input.value) >= ' . $floor . ' ? "" : input.value; } input.value = ""; input.readOnly = true; input.placeholder = input === hours ? "Unlimited" : "Not needed"; }'
        . ' else if (input.readOnly) { input.readOnly = false; input.value = input.dataset.mitWas || ""; delete input.dataset.mitWas; input.placeholder = input === hours ? "None" : "Don\'t tell"; } }); }'
        . 'box.addEventListener("change", sync);'
        . 'var template = form.querySelector("[data-contract-template]");'
        . 'if (template) { template.addEventListener("change", function () { setTimeout(function () {'
        . ' if (hours && Number(hours.value) >= ' . $floor . ') { hours.dataset.mitWas = ""; box.checked = true; } else if (hours && !hours.readOnly) { box.checked = false; } sync(); }, 0); }); }'
        . 'if (hours && Number(hours.value) >= ' . $floor . ') { box.checked = true; }'
        . 'sync();'
        . '})();</script>';
}

/** Hours drawn from a contract's unlimited blocks - this month's and last month's - from contractBlocks(). */
function mitContractUnlimitedUse(array $blocks, $today = null) {
    $today = $today ?? date('Y-m-d');
    $this_month = substr($today, 0, 7);
    $last_month = date('Y-m', strtotime(substr($today, 0, 7) . '-01 -1 month'));
    $use = ['this' => 0.0, 'last' => 0.0, 'tickets' => 0];
    foreach ($blocks as $block) {
        if (!mitContractHoursUnlimited($block['contract_block_hours'] ?? 0)) {
            continue;
        }
        if ((string) $block['contract_block_month'] === $this_month) {
            $use['this'] += floatval($block['used']);
            $use['tickets'] += intval($block['uses'] ?? 0);
        } elseif ((string) $block['contract_block_month'] === $last_month) {
            $use['last'] += floatval($block['used']);
        }
    }
    $use['this'] = round($use['this'], 2);
    $use['last'] = round($use['last'], 2);
    return $use;
}

/*
 * agent/contract.php's hook: the figures at the top of the Prepaid hours card for an unlimited contract - what was used this month and last,
 * and that billable time is covered - in place of ITFlow's left / of / alert lines. null for other contracts (ITFlow's card as it is).
 */
function mitContractUnlimitedAgentFigures(array $contract, array $blocks) {
    if (!mitContractUnlimited($contract)) {
        return null;
    }
    $use = mitContractUnlimitedUse($blocks);
    $html = '<div class="row g-3 align-items-end mb-3" data-mit-contract-unlimited>';
    $html .= '<div class="col-sm-5"><div class="small text-body-secondary text-uppercase fw-semibold">Support hours</div>'
        . '<div class="itflow-contract-hours-left">Unlimited</div>'
        . '<div class="small text-body-secondary" data-mit-unlimited-used>' . escapeHtml(contractHoursText($use['this'])) . ' used this month'
        . ($use['tickets'] ? ' on ' . $use['tickets'] . ' ticket' . ($use['tickets'] === 1 ? '' : 's') : '') . '</div></div>';
    $html .= '<div class="col-sm-7"><ul class="list-unstyled small text-body-secondary mb-0 d-flex flex-column gap-1">'
        . '<li><i class="fas fa-fw fa-infinity me-1"></i>Every billable ticket\'s time is covered, after hours too - none goes to Unbilled Time</li>'
        . '<li><i class="fas fa-fw fa-history me-1"></i>' . escapeHtml(contractHoursText($use['last'])) . ' used last month</li>'
        . '<li><i class="fas fa-fw fa-bell-slash me-1"></i>Never runs low, so no one is told</li>'
        . '</ul></div></div>';
    return $html;
}

/** client/contract.php's hook: the same for the client in the portal - "Unlimited support hours". null for other contracts. */
function mitContractUnlimitedPortalFigures(array $contract, array $blocks) {
    if (!mitContractUnlimited($contract)) {
        return null;
    }
    $use = mitContractUnlimitedUse($blocks);
    return '<div class="d-flex align-items-baseline justify-content-between gap-2" data-mit-contract-unlimited>'
        . '<span class="fs-4 fw-semibold">Unlimited <span class="fs-6 fw-normal text-body-secondary">support hours</span></span>'
        . '<span class="small text-body-secondary">' . escapeHtml(contractHoursText($use['this'])) . ' used this month</span></div>'
        . '<ul class="list-unstyled small text-body-secondary mb-0 mt-2">'
        . '<li>The time on your tickets is covered by this agreement.</li></ul>';
}

/** functions/contract_signature.php's hook: the "Included hours" line of the contract document for an unlimited contract, else null. */
function mitContractUnlimitedTermsLine(array $row) {
    return mitContractUnlimited($row) ? 'Unlimited support hours each month' : null;
}
