<?php
/*
 * The AI helper's password filter (functions/mit_ai_helper.php mitAiScrub) sentence by sentence: every way techs write a password
 * down is taken out (the value never survives), and ordinary troubleshooting that only talks about passwords, or names products,
 * versions, error codes, hosts and files, is kept word for word. Beta showed ~30% of tickets losing text to talk like "the user
 * needs a password" - the "kept" list guards against that coming back; the "taken" list guards the wall.
 */
require __DIR__ . '/bootstrap.php';
if (!function_exists('mitAiScrub')) {
    echo "  skip - no AI helper\n";
    exit(0);
}

// sentence => the secret that must not survive
$taken = [
    'Router admin password: Xq7-ruby-falcon' => 'Xq7-ruby-falcon',
    'Reset password to Welcome1 and gave it to him.' => 'Welcome1',
    'The NAS password purplemonkey' => 'purplemonkey',
    'pw is lowercaseonly' => 'lowercaseonly',
    'pwd=Tr0ub4dor&3' => 'Tr0ub4dor&3',
    'Admin creds are admin / Tr0ub4dor&3' => 'Tr0ub4dor&3',
    'The admin password is purplemonkey' => 'purplemonkey',
    'Password for the copier is hunter22' => 'hunter22',
    'Set her PIN to 4821 at the desk.' => '4821',
    'Wifi key: sunshine-day' => 'sunshine-day',
    'the login for the router is Summer2024!' => 'Summer2024',
    'Summer2024! is the new password' => 'Summer2024',
    'Changed the password to "correct horse battery staple".' => 'correct horse battery',
    'Password reset, new one is Spring#2025' => 'Spring#2025',
    'API key = sk-proj-ABCdefGHIjklMNOpqrSTUvwx' => 'sk-proj-ABCdefGHIjklMNOpqrSTUvwx',
    'Temp password Bluefish7 given to the user over the phone.' => 'Bluefish7',
    'Use P@ssw0rd123 for the local admin.' => 'P@ssw0rd123',
    'The switch password Xq7-ruby-falcon works again.' => 'Xq7-ruby-falcon',
    'What is the router admin password CANARY-NOTE-1a' => 'CANARY-NOTE-1a',
    'Gave her the wifi key ThinkPad-77-blue earlier.' => 'ThinkPad-77-blue',
];
// sentences that must come through unchanged
$kept = [
    'The user needs a password for the copier.',
    'Bob forgot his password, reset it in ADUC and told him.',
    'Password reset for Bob',
    "User's password expired. Ran the self-service reset.",
    'Outlook keeps prompting for a password after the M365 migration.',
    'Cleared the credential manager and recreated the Outlook profile.',
    'She was asking about her password but it is not in this ticket.',
    'Customer requested a password reset on their Office365 account.',
    'Installed KB5034441 on Windows11 and rebooted the server at 10.0.0.5.',
    'Error AADSTS50126 when signing in - wrong password, account fine.',
    'Replaced the toner on the HP LaserJet M404n and printed a test page.',
    'Updated the ThinkPad-T14s BIOS to 1.42 and ran Windows Update.',
    'Downloaded Win11_23H2_English_x64v2.iso to the USB stick.',
    'Moved the user to the new OptiPlex7090 and copied their profile.',
    'Emailed jsmith@acme.com the steps from https://support.microsoft.com/kb/123.',
    'Their password manager (Bitwarden) needed the browser extension reinstalled.',
    'The password policy requires 14 characters now.',
    'MFA prompt loop fixed by re-registering the Authenticator app.',
    'PIN reset in Windows Hello after the TPM was cleared.',
    'The password was wrong, so we reset it.',
    'Copied 16GB of files from \\\\fileserver\\share to OneDrive.',
];

echo "taken out\n";
foreach ($taken as $sentence => $secret) {
    [$clean] = mitAiScrub($sentence);
    scenarioCheck('gone: ' . $sentence, stripos($clean, $secret) === false, true);
}
echo "kept\n";
foreach ($kept as $sentence) {
    [$clean, $count] = mitAiScrub($sentence);
    scenarioCheck('kept: ' . $sentence, [$clean, $count], [$sentence, 0]);
}
echo "the log says what and why\n";
$log = [];
mitAiScrub("Password: Xq7-ruby-falcon\nThe NAS password purplemonkey. Try Summer2024!x on the switch.", $log);
scenarioCheck('each removal is logged with its rule', array_column($log, 'rule'), ['labelled', 'sentence', 'word']);
exit($GLOBALS['mit_scenario_failed'] ? 1 : 0);
