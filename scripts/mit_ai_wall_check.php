<?php
/*
 * Mountaineer IT - the AI helper's password wall, checked in the code (CLAUDE.md "AI helper"; run by scripts/mit_verify.sh).
 *
 *   php scripts/mit_ai_wall_check.php
 *
 * Every file of the AI helper (any file named mit_ai*) is read and the check fails when one:
 *   - mentions the password vault or any other secret store - the vault's tables and columns, logins' passwords / tokens / 2FA,
 *     API keys, the encryption helpers or keys (MIT_AI_WALL_FORBIDDEN). The helper has no reason to name any of them, so naming one
 *     is the failure - not just reading it;
 *   - takes every column of a table (SELECT *), so a secret column could come along unnamed;
 *   - reads or writes a table that is not on its list (MIT_AI_WALL_TABLES), found in the SQL in its PHP strings. A new table means a
 *     deliberate change here, reviewed against the wall.
 * The one key the helper handles is the AI provider's own (ai_provider_api_key), which ITFlow's aiProviderRow() hands back and is only
 * ever sent to that provider in a header.
 */

if (php_sapi_name() !== 'cli') {
    die("Run from the command line.\n");
}

const MIT_AI_WALL_FORBIDDEN = [
    '/credential/i' => 'the password vault',
    '/\b\w*_password\b|\bpassword_hash\b|\bpassword_verify\b/i' => 'a password column or check',
    '/\buser_token\b|\b\w*_mfa\w*\b|\btotp_secret\b|\buser_extension_key\b/i' => 'a login token or 2FA secret',
    '/\bapi_keys\b|\bapi_key_secret\b|\bapi_key_decrypt/i' => 'ITFlow\'s API keys',
    '/\bdecrypt\w*\s*\(|\bencryption_ciphertext|\buser_specific_encryption|\bconfig_mit_secret_key\b|\bmitDecryptSecret\b|\bmitEncryptSecret\b|\$database_password|\$installation_id/i' => 'decryption or an encryption key',
    '/\bshared_items\b|\bitem_encrypted/i' => 'shared secret links',
    '/\bSELECT\s+(?:\w+\.)?\*/i' => 'every column of a table (SELECT *) - name the columns, so no secret comes along',
    '/\bconfig_smtp_password|\bconfig_imap_password|\bconfig_mail_oauth|\bconfig_stripe|\bm365_cert|\bqbo_refresh|\bninja_secret|\bcontact_password\b/i' => 'a stored integration secret',
];

const MIT_AI_WALL_TABLES = ['settings', 'tickets', 'ticket_replies', 'documents', 'clients', 'users', 'ai_providers', 'mit_ai_sources', 'mit_ai_chunks',
    'mit_ai_conversations', 'mit_ai_messages'];

$root = dirname(__DIR__);
$files = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    $path = substr($file->getPathname(), strlen($root) + 1);
    if (preg_match('#(^|/)(libs|vendor|node_modules|tests|uploads|\.git)/#', $path) || !preg_match('#(^|/)mit_ai[^/]*\.(php|js)$|/mit_ai/[^/]+\.(php|js)$#', $path)) {
        continue;
    }
    if ($path === 'scripts/mit_ai_wall_check.php') {
        continue; // this file names what it looks for
    }
    $files[] = $path;
}
sort($files);

$problems = [];
foreach ($files as $path) {
    $code = file_get_contents("$root/$path");
    foreach (preg_split("/\n/", $code) as $no => $line) {
        foreach (MIT_AI_WALL_FORBIDDEN as $pattern => $what) {
            if (preg_match($pattern, $line, $m)) {
                $problems[] = "$path:" . ($no + 1) . " names $what (\"" . trim($m[0]) . "\")";
            }
        }
    }
    if (substr($path, -4) !== '.php') {
        continue;
    }
    foreach (token_get_all($code) as $token) {
        if (!is_array($token) || !in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
            continue;
        }
        if (!preg_match_all('/\b(?:FROM|JOIN|INTO|UPDATE|TABLE(?: IF (?:NOT )?EXISTS)?|TABLES LIKE)\s+[`\'"]?([a-z_][a-z0-9_]*)/i', $token[1], $found)) {
            continue;
        }
        foreach ($found[1] as $table) {
            if (!in_array(strtolower($table), MIT_AI_WALL_TABLES, true) && !preg_match('/^(select|the|a|an|this|that|it|them|nothing)$/i', $table)) {
                $problems[] = "$path:" . $token[2] . " uses table \"$table\", which is not on the AI helper's list";
            }
        }
    }
}

echo "AI helper wall check - " . count($files) . " file(s): " . implode(', ', $files) . "\n";
if ($problems) {
    echo "\nFAIL - the AI helper must never reach a password or another secret store:\n  " . implode("\n  ", $problems) . "\n";
    exit(1);
}
echo "\nThe wall holds: no file of the AI helper names the password vault or another secret, and every table it uses is on its list.\n";
