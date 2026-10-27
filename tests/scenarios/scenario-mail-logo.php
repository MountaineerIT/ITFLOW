<?php
/*
 * The company logo in emails and our PDFs: ITFlow's email look links the logo (https://<base url>/uploads/settings/<file>), which
 * shows broken wherever the reader's mail app can't reach this server - mitMailInlineImages (cron/mail_queue.php) puts it inside the
 * message instead (cid:). Only real images of uploads/settings; a calendar invite sent as its own part keeps the link (PHPMailer
 * leaves that part out of a message with inline images). The purchase order PDF finds the logo by its file path (made from cron too).
 */
require __DIR__ . '/bootstrap.php';
require_once 'libs/PHPMailer/src/Exception.php';
require_once 'libs/PHPMailer/src/PHPMailer.php';
if (!function_exists('mitMailInlineImages')) {
    echo "  skip - no mitMailInlineImages\n";
    exit(0);
}
$folder = 'uploads/settings';
$logo = 'zz-scenario-logo-' . date('His') . '.png';
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
file_put_contents("$folder/$logo", $png);
file_put_contents("$folder/zz-not-an-image.png", 'not an image');
$saved_logo = scenarioValue("SELECT company_logo FROM companies WHERE company_id = 1");
mysqli_query($mysqli, "UPDATE companies SET company_logo = '" . escapeSql($logo) . "' WHERE company_id = 1");
$made_po = 0;
try {
    $mail = function ($body, $ical = '') {
        $m = new PHPMailer\PHPMailer\PHPMailer(true);
        $m->isHTML(true);
        $m->setFrom('from@example.invalid');
        $m->addAddress('to@example.invalid');
        $m->Subject = 'Logo';
        $m->Body = $body;
        $m->Ical = $ical;
        return $m;
    };

    echo "ITFlow's email look\n";
    $html = emailHtml(['heading' => 'Ticket update', 'intro' => 'Hello']);
    scenarioCheck('ITFlow links the logo by its web address', strpos($html, "/uploads/settings/$logo") !== false, true);
    $m = $mail($html);
    scenarioCheck('one image put inside', mitMailInlineImages($m), 1);
    scenarioCheck('the body points at it (cid:), not at this server', [strpos($m->Body, 'cid:mit-img-0@itflow') !== false, strpos($m->Body, "/uploads/settings/$logo") !== false], [true, false]);
    $inline = array_values(array_filter($m->getAttachments(), fn($a) => $a[6] === 'inline'));
    scenarioCheck('carried as an inline part with that id', [count($inline), $inline[0][7] ?? ''], [1, 'mit-img-0@itflow']);
    $m->preSend();
    $mime = $m->getSentMIMEMessage();
    scenarioCheck('the message holds the image (Content-ID)', strpos($mime, 'Content-ID: <mit-img-0@itflow>') !== false, true);

    echo "left alone\n";
    $same = '<img src="https://other.example/uploads/settings/' . $logo . '"><img src="https://x.test/uploads/settings/' . $logo . '">';
    $m = $mail($same);
    scenarioCheck('the same logo twice = one part', mitMailInlineImages($m), 1);
    $m = $mail('<img src="https://x.test/uploads/settings/zz-not-an-image.png"><img src="https://x.test/uploads/settings/..%2F..%2Fconfig.php"><img src="https://x.test/uploads/settings/missing.png">');
    $before = $m->Body;
    scenarioCheck('not an image / outside the folder / missing: nothing put in', [mitMailInlineImages($m), $m->Body], [0, $before]);
    $m = $mail($html, "BEGIN:VCALENDAR\r\nMETHOD:REQUEST\r\nEND:VCALENDAR");
    scenarioCheck('a calendar invitation keeps the link', [mitMailInlineImages($m), strpos($m->Body, "/uploads/settings/$logo") !== false], [0, true]);

    // Through the mail queue job for real, when the Microsoft 365 stand-in is sending here (it keeps each message as sent)
    $state_file = '/srv/mockms/state.json';
    if (is_readable($state_file) && function_exists('mitM365GraphSendReady') && mitM365GraphSendReady()) {
        echo "through the mail queue\n";
        $tag = 'ZZ logo ' . bin2hex(random_bytes(4));
        mysqli_query($mysqli, "UPDATE email_queue SET email_status = 9 WHERE email_status = 0");
        try {
            emailQueue([['email' => 'logo.check@example.invalid', 'name' => 'Logo Check']], $tag, ['heading' => 'Ticket update', 'intro' => 'Hello']);
            shell_exec('cd ' . escapeshellarg(getcwd() . '/cron') . ' && sudo -u www-data php mail_queue.php --no-mx-validation 2>&1');
        } finally {
            mysqli_query($mysqli, "UPDATE email_queue SET email_status = 0 WHERE email_status = 9");
        }
        $raw = '';
        foreach ((json_decode((string) file_get_contents($state_file), true)['sent'] ?? []) as $message) {
            if (is_string($message) && strpos($message, $tag) !== false) {
                $raw = $message;
            }
        }
        scenarioCheck('sent', $raw !== '', true);
        scenarioCheck('the logo travels inside it, the body points at it', [strpos($raw, 'Content-ID: <mit-img-0@itflow>') !== false, strpos($raw, 'cid:mit-img-0@itflow') !== false
            || strpos(quoted_printable_decode($raw), 'cid:mit-img-0@itflow') !== false], [true, true]);
    }

    echo "purchase order PDF\n";
    if (function_exists('mitPoPdf') && mitProcReady()) {
        mysqli_query($mysqli, "INSERT INTO mit_purchase_orders SET po_prefix = 'ZZ-LOGO-', po_number = " . (900000 + intval(date('His'))) . ", vendor_id = 0, status = 'draft', created_by = 1");
        $made_po = intval(mysqli_insert_id($mysqli));
        $po = mitPoRow($made_po);
        $file = sys_get_temp_dir() . "/zz-po-$made_po.pdf";
        $saved_root = $_SERVER['DOCUMENT_ROOT'] ?? null;
        unset($_SERVER['DOCUMENT_ROOT']); // as from cron (emailing a PO): no web root to put in front of a web path
        mitPoPdf($po, $file);
        if ($saved_root !== null) {
            $_SERVER['DOCUMENT_ROOT'] = $saved_root;
        }
        scenarioCheck('the PDF has the logo in it, made without a web root', strpos((string) @file_get_contents($file), '/Subtype /Image') !== false, true);
        @unlink($file);
    }
} finally {
    mysqli_query($mysqli, "UPDATE companies SET company_logo = " . ($saved_logo === null ? 'NULL' : "'" . escapeSql($saved_logo) . "'") . " WHERE company_id = 1");
    @unlink("$folder/$logo");
    @unlink("$folder/zz-not-an-image.png");
    if ($made_po) {
        mysqli_query($mysqli, "DELETE FROM mit_purchase_orders WHERE po_id = $made_po");
    }
}
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
