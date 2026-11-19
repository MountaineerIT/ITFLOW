<?php
/*
 * Mountaineer IT - test stand-in for Customer Thermometer's API (functions/mit_ct.php; MIT_CT_API_BASE in config.php).
 *   GET index.php?getMethod=...&apiKey=ct-key (any other key: "Invalid API Key", as plain text the way the real API answers):
 *     getThermometers (101 "Ticket closed", 102 "Project done"), getRecipientLists (7 "ITFlow tickets"),
 *     sendEmail (records the fields, answers a new blast id - or 0 while out of credits),
 *     getBlastResults / getComments (XML; responses whose date is within fromDate..toDate, the newest 200 at most - as the real API).
 *   Control (JSON): /_reset, /_state (sent surveys, responses, credits), /_credits?on=0|1,
 *     /_respond?blast=&temperature=1-4&comment=&date=Y-m-d H:i:s (a response to a sent - or any - blast; replaces an earlier one).
 * Tests: smoke 18-customer-thermometer, 24-no-response; tests/scenarios/scenario-ct-responses.php.
 */
require __DIR__ . '/guard.php';

const CT_KEY = 'ct-key';
const CT_LIMIT = 200;
$state = mockLoad('ct', ['sent' => [], 'responses' => [], 'credits' => true, 'next_blast' => 5000]);
$path = mockPath();

if ($path === '/_reset') {
    mockSave('ct', ['sent' => [], 'responses' => [], 'credits' => true, 'next_blast' => 5000]);
    mockJson(['ok' => true]);
}
if ($path === '/_state') {
    mockJson($state);
}
if ($path === '/_credits') {
    $state['credits'] = ($_GET['on'] ?? '1') !== '0';
    mockSave('ct', $state);
    mockJson(['ok' => true, 'credits' => $state['credits']]);
}
if ($path === '/_respond') {
    $blast = (string) ($_GET['blast'] ?? '');
    if ($blast === '') {
        mockJson(['error' => 'blast needed'], 400);
    }
    $date = (string) ($_GET['date'] ?? '');
    $date = $date !== '' && strtotime($date) ? date('Y-m-d H:i:s', strtotime($date)) : date('Y-m-d H:i:s');
    $custom2 = '';
    foreach ($state['sent'] as $sent) {
        if ((string) $sent['blast'] === $blast) {
            $custom2 = (string) ($sent['custom2'] ?? '');
        }
    }
    $state['responses'] = array_values(array_filter($state['responses'], fn($r) => (string) $r['blast'] !== $blast));
    $state['responses'][] = ['blast' => $blast, 'temperature' => intval($_GET['temperature'] ?? 1), 'comment' => (string) ($_GET['comment'] ?? ''),
        'date' => $date, 'custom2' => $custom2];
    mockSave('ct', $state);
    mockJson(['ok' => true]);
}
if ($path !== '/') {
    mockJson(['error' => 'not found'], 404);
}

// ---------- the API ----------
header('Content-Type: text/plain; charset=utf-8');
if ((string) ($_GET['apiKey'] ?? '') !== CT_KEY) {
    echo 'Invalid API Key';
    exit;
}
function ctXml($root, $item, array $rows) {
    $xml = new SimpleXMLElement("<?xml version=\"1.0\" encoding=\"UTF-8\"?><$root/>");
    foreach ($rows as $row) {
        $node = $xml->addChild($item);
        foreach ($row as $name => $value) {
            $node->addChild($name, htmlspecialchars((string) $value, ENT_XML1));
        }
    }
    header('Content-Type: application/xml; charset=utf-8');
    echo $xml->asXML();
    exit;
}
/** Responses in fromDate..toDate (whole days), newest first, at most CT_LIMIT. */
function ctInRange(array $responses) {
    $from = (string) ($_GET['fromDate'] ?? '');
    $to = (string) ($_GET['toDate'] ?? '');
    $rows = array_filter($responses, function ($r) use ($from, $to) {
        $day = substr($r['date'], 0, 10);
        return ($from === '' || $day >= $from) && ($to === '' || $day <= $to);
    });
    usort($rows, fn($a, $b) => strcmp($b['date'], $a['date']) ?: strcmp((string) $b['blast'], (string) $a['blast']));
    return array_slice($rows, 0, CT_LIMIT);
}

switch ((string) ($_GET['getMethod'] ?? '')) {
    case 'getThermometers':
        ctXml('thermometers', 'thermometer', [['thermometer_id' => 101, 'name' => 'Ticket closed'], ['thermometer_id' => 102, 'name' => 'Project done']]);
    case 'getRecipientLists':
        ctXml('recipient_lists', 'recipient_list', [['list_id' => 7, 'name' => 'ITFlow tickets']]);
    case 'sendEmail':
        if (empty($state['credits'])) {
            echo '0';
            exit;
        }
        $blast = $state['next_blast']++;
        $fields = ['thermometerID', 'listID', 'emailAddress', 'firstName', 'lastName', 'companyName', 'custom1', 'custom2', 'custom3'];
        $sent = ['blast' => $blast];
        foreach ($fields as $field) {
            $sent[$field] = (string) ($_GET[$field] ?? '');
        }
        $state['sent'][] = $sent;
        mockSave('ct', $state);
        echo $blast;
        exit;
    case 'getBlastResults':
        ctXml('blast_results', 'response', array_map(fn($r) => ['blast_id' => $r['blast'], 'temperature_id' => $r['temperature'],
            'response_date' => $r['date'], 'custom2' => $r['custom2']], ctInRange($state['responses'])));
    case 'getComments':
        ctXml('comments', 'comment_record', array_map(fn($r) => ['blast_id' => $r['blast'], 'comment' => $r['comment'], 'response_date' => $r['date']],
            ctInRange(array_filter($state['responses'], fn($r) => $r['comment'] !== ''))));
    default:
        echo 'Unknown getMethod';
        exit;
}
