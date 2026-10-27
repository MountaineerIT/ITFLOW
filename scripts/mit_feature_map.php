<?php
/*
 * Mountaineer IT - writes MIT_FEATURES.md: each feature of ours (scripts/mit_features.php) with what it uses of ITFlow, worked out
 * from the code (scripts/mit_feature_lib.php). Run after adding a feature or a hook, and commit the file with the change.
 *
 *   php scripts/mit_feature_map.php           write MIT_FEATURES.md
 *   php scripts/mit_feature_map.php --check   fail when a file of ours is in no feature, a feature's file / test glob finds
 *                                             nothing, a "watch" string is gone from ITFlow, or MIT_FEATURES.md is out of date
 *                                             (run by scripts/mit_verify.sh)
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/mit_feature_lib.php';

$check = in_array('--check', $argv, true);
$features = mfFeatures();
$uses = mfUses($features);

$md = "# Mountaineer IT - feature map\n\n"
    . "Written by `php scripts/mit_feature_map.php` from `scripts/mit_features.php` and the code - don't edit by hand.\n"
    . "Each feature of ours, the tests that prove it, what it takes for granted about ITFlow, and what it uses of ITFlow's code\n"
    . "(functions called, tables and columns named, ITFlow files that carry its hooks, page ids / ITFlow classes its scripts look for).\n"
    . "`php scripts/mit_upstream_impact.php` reads this to say which features an ITFlow update touches.\n\n";
$md .= "| Feature | Our files | Tests | ITFlow functions | Tables | Columns | ITFlow files hooked |\n|---|---|---|---|---|---|---|\n";
foreach ($features as $key => $f) {
    $u = $uses[$key];
    $md .= "| [$f[name]](#$key) | " . count($f['files_found']) . ' | ' . count($f['tests_found']) . ' | ' . count($u['functions']) . ' | ' . count($u['tables'])
        . ' | ' . count($u['columns']) . ' | ' . count($u['hooks']) . " |\n";
}
foreach ($features as $key => $f) {
    $u = $uses[$key];
    $md .= "\n<a id=\"$key\"></a>\n## $f[name] (`$key`)\n\n";
    $md .= "**Takes for granted**\n\n" . implode("\n", array_map(function ($a) { return "- $a"; }, $f['assumes'])) . "\n\n";
    $md .= '**Tests** ' . ($f['tests_found'] ? implode(', ', array_map(function ($t) { return '`' . $t . '`'; }, $f['tests_found'])) : '_none_') . "\n\n";
    if ($u['hooks']) {
        $md .= "**Hooks in ITFlow's files**\n\n";
        foreach ($u['hooks'] as $file => $names) {
            $md .= "- `$file`: " . implode(', ', array_unique($names)) . "\n";
        }
        $md .= "\n";
    }
    $by_file = [];
    foreach ($u['functions'] as $fn => $file) {
        $by_file[$file][] = $fn;
    }
    ksort($by_file);
    if ($by_file) {
        $md .= "**ITFlow functions it calls**\n\n";
        foreach ($by_file as $file => $fns) {
            $md .= "- `$file`: " . implode(', ', $fns) . "\n";
        }
        $md .= "\n";
    }
    if ($u['tables']) {
        $md .= '**ITFlow tables** ' . implode(', ', array_keys($u['tables'])) . "\n\n";
    }
    if ($u['columns']) {
        $md .= '**ITFlow columns** ' . implode(', ', array_keys($u['columns'])) . "\n\n";
    }
    if ($u['selectors']) {
        $md .= '**ITFlow page ids / classes its scripts and styles use** ' . implode(' ', array_map(function ($s) { return '`' . $s . '`'; }, array_keys($u['selectors']))) . "\n\n";
    }
    if ($f['watch']) {
        $md .= '**Watched in ITFlow\'s code** ' . implode(' ', array_map(function ($s) { return '`' . $s . '`'; }, $f['watch'])) . "\n\n";
    }
    if ($f['overlap']) {
        $md .= '**"ITFlow does this too" words** ' . implode(', ', $f['overlap']) . "\n\n";
    }
    $md .= '**Our files** ' . implode(', ', array_map(function ($t) { return '`' . $t . '`'; }, array_values(array_filter($f['files_found'], function ($p) {
        return !preg_match('~^tests/|database_updates/mountaineerit/~', $p);
    })))) . "\n";
}

$problems = [];
foreach (mfUnplaced($features) as $file) {
    $problems[] = "not in any feature: $file (add it to scripts/mit_features.php)";
}
foreach ($features as $key => $f) {
    foreach ($f['missing'] ?? [] as $m) {
        $problems[] = "$key: nothing matches $m";
    }
    if (!$f['tests_found'] && $key !== 'checks') {
        $problems[] = "$key: no tests";
    }
    foreach ($uses[$key]['watch'] as $needle => $present) {
        if (!$present) {
            $problems[] = "$key: '$needle' (watched) is not in ITFlow's code any more - see what replaced it";
        }
    }
}

$path = MIT_FEATURE_ROOT . '/MIT_FEATURES.md';
if ($check) {
    if ((string) @file_get_contents($path) !== $md) {
        $problems[] = 'MIT_FEATURES.md is out of date - run: php scripts/mit_feature_map.php (and commit it)';
    }
    echo "Feature map - " . count($features) . " features\n";
    foreach ($problems as $p) {
        echo "FAIL  $p\n";
    }
    echo $problems ? '' : "OK    every file of ours is in a feature, every test glob finds tests, watched strings are all there, MIT_FEATURES.md is current\n";
    exit($problems ? 1 : 0);
}
file_put_contents($path, $md);
echo "MIT_FEATURES.md written - " . count($features) . " features\n";
foreach ($problems as $p) {
    echo "WARN  $p\n";
}
