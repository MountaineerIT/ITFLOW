<?php
/*
 * Mountaineer IT - put the outside-service stand-ins into a TEST install, so the tests of QuickBooks, NinjaOne, the usage
 * connectors, Customer Thermometer, MSP Align, the AI helper and Microsoft 365 run instead of skipping.
 *
 *   php tests/mocks/install.php [--code-only] [/var/www/itflow]   (as root, from the repository; refuses an install that is
 *                                                                 not *.test; --code-only copies the files, leaves config.php)
 *
 * Each stand-in is a folder tests/mocks/<name>/ holding index.php (and anything it needs). It is copied to
 * <install>/<name>-mock/ with guard.php beside it, so it answers at http://127.0.0.1/<name>-mock/index.php (the main install
 * is Apache's default site). A folder may hold:
 *   constants.php  - returns [CONSTANT => value] written into the install's config.php ({MOCK} = the stand-in's address);
 *   start.sh       - run after copying (a stand-in that is its own server, e.g. the Microsoft Graph one), given the install's
 *                    root as $1.
 * config.php keeps the constants between "// MIT test stand-ins" markers, replaced on every run. Re-run it after a deploy
 * (rsync leaves the copies alone, but a new stand-in or constant needs it). Copies of the install (scripts/mit_test_copy.sh)
 * take the folders and constants with them; the stand-ins they reach are the main install's, at 127.0.0.1.
 */
if (PHP_SAPI !== 'cli') {
    exit;
}
$args = array_slice($argv, 1);
$code_only = in_array('--code-only', $args, true);   // copy the stand-ins' files only: no config.php, no start.sh
$args = array_values(array_diff($args, ['--code-only']));
$root = rtrim($args[0] ?? '/var/www/itflow', '/');
$config = "$root/config.php";
if (!is_file($config)) {
    fwrite(STDERR, "No config.php in $root\n");
    exit(1);
}
$base_url = (string) shell_exec('php -r ' . escapeshellarg('include $argv[1]; echo $config_base_url ?? "";') . ' ' . escapeshellarg($config));
if (!preg_match('/\.test$/', trim($base_url))) {
    fwrite(STDERR, "$root is not a .test install ($base_url) - stand-ins go on test installs only.\n");
    exit(1);
}
$here = __DIR__;
$constants = [];
$started = [];
foreach (glob("$here/*", GLOB_ONLYDIR) as $dir) {
    $name = basename($dir);
    if (!is_file("$dir/index.php") && !is_file("$dir/start.sh")) {
        continue;
    }
    $target = "$root/$name-mock";
    if (is_file("$dir/index.php")) {
        @mkdir($target, 0755, true);
        exec('rsync -a --delete --exclude constants.php --exclude start.sh ' . escapeshellarg("$dir/") . ' ' . escapeshellarg("$target/"));
        copy("$here/guard.php", "$target/guard.php");
        copy("$here/.htaccess", "$target/.htaccess");
        file_put_contents("$target/.mit-test-stand-in", "Put here by tests/mocks/install.php on a .test install - without it the stand-in answers nobody.\n");
        exec('chown -R www-data:www-data ' . escapeshellarg($target) . ' 2>/dev/null');
    }
    if (!$code_only && is_file("$dir/constants.php")) {
        $address = "http://127.0.0.1/$name-mock/index.php";
        foreach ((array) (include "$dir/constants.php") as $constant => $value) {
            $constants[$constant] = str_replace('{MOCK}', $address, (string) $value);
        }
    }
    if (!$code_only && is_file("$dir/start.sh")) {
        passthru('sh ' . escapeshellarg("$dir/start.sh") . ' ' . escapeshellarg($root), $code);
        $started[] = "$name (" . ($code === 0 ? 'started' : "start.sh exit $code") . ')';
    }
    echo "  $name-mock\n";
}

if ($code_only) {
    exit(0);
}
// The constants, between markers in config.php
$text = (string) file_get_contents($config);
$text = preg_replace('~\n?// MIT test stand-ins.*?// end MIT test stand-ins\n?~s', "\n", $text);
$block = "// MIT test stand-ins (tests/mocks/install.php - test installs only)\n";
ksort($constants);
foreach ($constants as $constant => $value) {
    $block .= "defined('$constant') || define('$constant', " . var_export($value, true) . ");\n";
}
$block .= "// end MIT test stand-ins\n";
$text = rtrim($text) . "\n\n" . $block;
file_put_contents($config, $text);
if (function_exists('opcache_reset')) {
    @opcache_reset();
}
echo count($constants) . " constants in config.php" . ($started ? '; ' . implode(', ', $started) : '') . "\n";
