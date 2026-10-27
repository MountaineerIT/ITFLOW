<?php
/*
 * Mountaineer IT - the hook proof: is every hook of ours in ITFlow's files covered by a test that would notice it going?
 * For each hook site (a line in an ITFlow file that calls our code), on a COPY of the test install (scripts/mit_test_copy.sh):
 * switch that one hook off, run the tests of every feature that owns it (scripts/mit_features.php) - its scenarios first, then its
 * smoke specs - and put the line back. A hook whose loss no test notices is a gap: write a test that fails without it.
 *
 *   php scripts/mit_hook_proof.php --list [--feature=key,key]                    the hook sites and how each would be switched off
 *   php scripts/mit_hook_proof.php --copy=N [--feature=key,key] [--site=file:line] [--out=results.jsonl]
 *
 * Switching off, so that ITFlow carries on as if our code were not there (never a broken page - each change must pass php -l):
 *   guard     function_exists('mitX') on the line -> false (the hook's fallback runs)
 *   call      a statement that is only our call (mitX(...);) -> commented out
 *   output    <?= mitX(...) ... ?> -> <?= '' ?>
 *   include   a card / menu section / form fields of ours required into ITFlow's page -> not required
 * Not switched off automatically (listed as "manual" for review): lines loading our files, values used further on, our hooks in
 * ITFlow's JavaScript / CSS. Results go to --out (one JSON line a site: noticed by which test, not noticed, manual, broken).
 * Never on production: refuses an install whose base URL is not *.test.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/mit_feature_lib.php';

$opts = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z]+)(?:=(.*))?$/', $arg, $m)) {
        $opts[$m[1]] = $m[2] ?? true;
    }
}
$only = !empty($opts['feature']) && $opts['feature'] !== true ? array_flip(explode(',', $opts['feature'])) : null;

// ---------- Hook sites ----------
$features = mfFeatures();
$uses = mfUses($features);
$sites = []; // "file:line" => ['file', 'line', 'text', 'features' => [], 'how', 'names']
foreach ($uses as $key => $u) {
    foreach ($u['hooks'] as $file => $names) {
        $lines = explode("\n", mfRead($file));
        foreach ($lines as $i => $line) {
            $hit = false;
            foreach (array_unique($names) as $name) {
                if (strpos($line, $name) !== false) {
                    $hit = true;
                    break;
                }
            }
            // Only lines that call our code: a guard, a call or output of ours (variables of ours inside a guarded block go with it)
            if (!$hit || !preg_match('/\bmit[A-Z]\w*\s*\(|function_exists\(\s*[\'"]mit|require(_once)?[^;]*mit_|mit_\w+\.(php|js)/', $line)
                || preg_match('~^\s*(//|/\*|\*|#)~', $line)) { // a comment beside a hook is not one
                continue;
            }
            $id = "$file:" . ($i + 1);
            $sites[$id] = $sites[$id] ?? ['file' => $file, 'line' => $i + 1, 'text' => $line, 'features' => []];
            $sites[$id]['features'][$key] = true;
        }
    }
}

/** How a line is switched off: [kind, new line] - kind guard / call / output / manual (with why). */
function mfSwitchOff($file, $line) {
    if (preg_match('/\.(js|css)$/', $file)) {
        return ['manual', 'in ITFlow\'s ' . pathinfo($file, PATHINFO_EXTENSION) . ' - review by hand'];
    }
    // A card / menu section / form fields of ours drawn into ITFlow's page: left out
    if (preg_match('/^(\s*(?:<\?php\s+)?)(?:if \(function_exists\([^)]*\)\) \{ )?(require|include)(_once)?\b[^;]*mit_\w*(card|nav|fields)\.php[\'"];(?: \})?(.*)$/', $line, $m)) {
        $rest = preg_replace('~^\s*//[^?]*~', '', $m[5]);
        return ['include', $m[1] . '/* mit_hook_proof: switched off */' . ($rest !== '' ? ' ' . ltrim($rest) : '')];
    }
    if (preg_match('/\b(require|include)(_once)?\b[^;]*mit_/', $line)) {
        return ['manual', 'loads our code - everything of the feature goes with it'];
    }
    if (preg_match('/function_exists\(\s*[\'"]mit\w+[\'"]\s*\)/', $line)) {
        return ['guard', preg_replace('/function_exists\(\s*[\'"]mit\w+[\'"]\s*\)/', 'false', $line)];
    }
    if (preg_match('/<\?=\s*mit[A-Z]\w*\(.*?\?>/', $line)) {
        return ['output', preg_replace('/<\?=\s*mit[A-Z]\w*\(.*?\?>/', "<?= '' ?>", $line)];
    }
    if (preg_match('/^(\s*)mit[A-Z]\w*\(.*\);\s*(\/\/.*)?$/', $line, $m)) {
        return ['call', $m[1] . '/* mit_hook_proof: switched off */'];
    }
    return ['manual', 'its value is used further on (no guard) - review by hand'];
}

foreach ($sites as $id => &$site) {
    [$site['how'], $site['new']] = mfSwitchOff($site['file'], $site['text']);
}
unset($site);
if ($only !== null) {
    $sites = array_filter($sites, function ($s) use ($only) {
        return (bool) array_intersect_key($s['features'], $only);
    });
}
if (!empty($opts['site']) && $opts['site'] !== true) {
    $sites = array_intersect_key($sites, [$opts['site'] => true]);
}
ksort($sites);

if (!empty($opts['list'])) {
    $by = [];
    foreach ($sites as $id => $s) {
        $by[$s['how']] = ($by[$s['how']] ?? 0) + 1;
        printf("%-7s %-55s %s\n", $s['how'], $id, implode(',', array_keys($s['features'])));
    }
    echo "\n" . count($sites) . ' hook sites: ' . implode(', ', array_map(function ($k, $v) { return "$v $k"; }, array_keys($by), $by)) . "\n";
    exit(0);
}

// ---------- Run on a copy ----------
$n = intval($opts['copy'] ?? 0);
$root = "/var/www/itflow_t$n";
$db = "itflow_t$n";
$url = "http://itflow-t$n.test";
if (!$n || !is_file("$root/config.php")) {
    fwrite(STDERR, "Run it on a copy of the test install: --copy=N (make one with: sh scripts/mit_test_copy.sh N)\n");
    exit(2);
}
$base = trim((string) shell_exec('php -r ' . escapeshellarg('include $argv[1]; echo $config_base_url;') . ' ' . escapeshellarg("$root/config.php")));
if (!preg_match('/\.test$/', $base)) {
    fwrite(STDERR, "$root is not a .test install ($base) - refusing.\n");
    exit(2);
}
$out = !empty($opts['out']) && $opts['out'] !== true ? $opts['out'] : '';
$repo = MIT_FEATURE_ROOT;
// Each copy runs the smoke tests from a folder of its own - Playwright keeps its sign-in (.auth) and results in the folder, and
// two runs against different copies from one folder sign each other out
$smoke = "$repo/tests/.smoke_t$n"; // beside tests/smoke, so its ../scenarios and ../../scripts paths still work (git ignores it)
shell_exec('rsync -a --delete --exclude node_modules --exclude .auth --exclude report --exclude test-results '
    . escapeshellarg("$repo/tests/smoke/") . ' ' . escapeshellarg("$smoke/") . ' && ln -sfn ' . escapeshellarg("$repo/tests/smoke/node_modules") . ' '
    . escapeshellarg("$smoke/node_modules"));
$env = 'ITFLOW_URL=' . escapeshellarg($url) . ' ITFLOW_DB=' . escapeshellarg($db) . ' ITFLOW_ROOT=' . escapeshellarg($root)
    . ' ITFLOW_PHP_LOG=' . escapeshellarg("/var/log/apache2/itflow-t$n-error.log");

/** Run the tests of these features against the copy: the first that fails, or '' when all pass (and the seconds it took). */
function mfRunTests(array $feature_keys, array $features, $repo, $root, $env, $smoke) {
    $started = microtime(true);
    $scenarios = [];
    $specs = [];
    foreach ($feature_keys as $key) {
        foreach ($features[$key]['tests_found'] as $test) {
            if (preg_match('~^tests/scenarios/scenario-[^/]+\.php$~', $test)) {
                $scenarios[$test] = true;
            } elseif (preg_match('~^tests/smoke/([^/]+\.spec\.js)$~', $test, $m)) {
                $specs[$m[1]] = true;
            }
        }
    }
    foreach (array_keys($scenarios) as $test) {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$repo/$test") . ' ' . escapeshellarg($root) . ' 2>&1', $output, $code);
        if ($code !== 0) {
            $fail = array_values(array_filter($output, function ($l) { return strpos($l, 'FAIL') !== false || stripos($l, 'fatal') !== false; }));
            return [$test . ($fail ? ' - ' . trim($fail[0]) : ''), microtime(true) - $started];
        }
        $output = [];
    }
    if ($specs) {
        exec('cd ' . escapeshellarg($smoke) . " && $env npx playwright test --max-failures=1 --reporter=line "
            . implode(' ', array_map('escapeshellarg', array_keys($specs))) . ' 2>&1', $output, $code);
        if ($code !== 0) {
            $fail = array_values(array_filter($output, function ($l) { return preg_match('/✘|\d+\) .*spec\.js/', $l); }));
            return ['smoke ' . ($fail ? trim(preg_replace('/\e\[[0-9;]*m/', '', $fail[0])) : implode(' ', array_keys($specs))), microtime(true) - $started];
        }
    }
    return ['', microtime(true) - $started];
}

$record = function (array $row) use ($out) {
    $line = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    echo $line . "\n";
    if ($out !== '') {
        file_put_contents($out, $line . "\n", FILE_APPEND);
    }
};

// Baseline: each feature's tests must pass untouched on this copy, or its sites can't be judged
$baseline = [];
foreach ($sites as $s) {
    foreach (array_keys($s['features']) as $key) {
        if (!isset($baseline[$key])) {
            [$fail, $secs] = mfRunTests([$key], $features, $repo, $root, $env, $smoke);
            $baseline[$key] = $fail;
            $record(['baseline' => $key, 'ok' => $fail === '', 'fail' => $fail, 'seconds' => round($secs)]);
        }
    }
}

// Stopped part way (Ctrl-C, kill): put back the line switched off at that moment
$GLOBALS['mf_restore'] = null;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    foreach ([SIGINT, SIGTERM, SIGHUP] as $signal) {
        pcntl_signal($signal, function () {
            if ($GLOBALS['mf_restore']) {
                file_put_contents($GLOBALS['mf_restore'][0], $GLOBALS['mf_restore'][1]);
            }
            fwrite(STDERR, "Stopped - the copy's file is put back.\n");
            exit(130);
        });
    }
}

foreach ($sites as $id => $s) {
    $keys = array_values(array_filter(array_keys($s['features']), function ($k) use ($baseline) { return $baseline[$k] === ''; }));
    $row = ['site' => $id, 'how' => $s['how'], 'features' => array_keys($s['features']), 'text' => trim($s['text'])];
    if ($s['how'] === 'manual') {
        $record($row + ['result' => 'manual', 'why' => $s['new']]);
        continue;
    }
    if (!$keys) {
        $record($row + ['result' => 'skipped', 'why' => 'the owning features\' tests fail even with the hook in place']);
        continue;
    }
    $path = "$root/$s[file]";
    $original = (string) file_get_contents($path);
    $lines = explode("\n", $original);
    if (($lines[$s['line'] - 1] ?? null) !== $s['text']) {
        $record($row + ['result' => 'skipped', 'why' => 'the copy\'s file differs from the repo - refresh it (scripts/mit_test_copy.sh)']);
        continue;
    }
    $lines[$s['line'] - 1] = $s['new'];
    $GLOBALS['mf_restore'] = [$path, $original];
    file_put_contents($path, implode("\n", $lines));
    try {
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1', $lint, $lint_code);
        if ($lint_code !== 0) {
            $record($row + ['result' => 'broken', 'why' => 'switching it off this way is not valid PHP - review by hand']);
            continue;
        }
        sleep(3); // Apache's opcache looks at a changed file again after up to 2 seconds
        [$fail, $secs] = mfRunTests($keys, $features, $repo, $root, $env, $smoke);
        $record($row + ['result' => $fail === '' ? 'NOT NOTICED' : 'noticed', 'by' => $fail, 'seconds' => round($secs)]);
    } finally {
        file_put_contents($path, $original);
        $GLOBALS['mf_restore'] = null;
        sleep(3);
    }
}
