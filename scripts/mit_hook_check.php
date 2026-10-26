<?php
/*
 * Mountaineer IT - are all our hooks in ITFlow's files still there? Run after every upstream merge (and before promoting).
 *
 *   php scripts/mit_hook_check.php            check against scripts/mit_hooks.txt - exit 1 when a hook is gone
 *   php scripts/mit_hook_check.php --update   write the manifest from the files as they are now (after a deliberate change)
 *
 * Our code hooks into ITFlow's files with one-line calls (CLAUDE.md, "Our deviations from upstream"). A merge conflict resolved
 * in favour of upstream drops such a line silently - the page still loads, the feature just stops. This finds them: in every
 * ITFlow file (not ours - no path part starting "mit_" or containing "mountaineerit", not libs/, tests/, uploads/) it counts our
 * names - functions and constants (mitXxx, MIT_XXX), our files, settings, globals and attributes (mit_xxx, mit-xxx, data-mit-xxx)
 * and "MountaineerIT" - and compares them with the manifest. A name used fewer times than recorded is reported with the file;
 * new ones are listed so the manifest can be updated. It also checks that our own files exist and that files we removed from
 * upstream stay removed.
 */

if (php_sapi_name() !== 'cli') {
    die("Run this from the command line.\n");
}

$root = dirname(__DIR__);
$manifest_file = __DIR__ . '/mit_hooks.txt';
$update = in_array('--update', $argv, true);

// Files we removed from upstream on purpose (CLAUDE.md) - a merge must not bring them back unchecked
const MIT_HOOK_REMOVED = ['.github/workflows/first-interaction.yml', 'agent/client_autopay.php'];

function mitHookOurs($path) {
    foreach (explode('/', $path) as $part) {
        if (strpos($part, 'mit_') === 0 || stripos($part, 'mountaineerit') !== false) {
            return true;
        }
    }
    return in_array($path, ['CLAUDE.md', 'DEPLOYMENT.md', 'MIT_CHANGELOG.md', 'scripts/install.sh'], true) || strpos($path, 'css/fonts/inter/') === 0;
}

function mitHookFiles($root) {
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        function ($file) use ($root) {
            $rel = ltrim(substr($file->getPathname(), strlen($root)), '/');
            if ($file->isDir()) {
                return !preg_match('#^(\.git|libs|vendor|uploads|tests|node_modules|setup/vendor)(/|$)|(^|/)(node_modules|[a-z]+-mock)$#', $rel);
            }
            return true;
        }));
    foreach ($it as $file) {
        $rel = ltrim(substr($file->getPathname(), strlen($root)), '/');
        // config.php is the install's own (a test install's stand-in addresses), not ITFlow's code
        if ($rel !== 'config.php' && (preg_match('/\.(php|js|css)$/', $rel) || basename($rel) === '.htaccess')) {
            $files[] = $rel;
        }
    }
    sort($files);
    return $files;
}

// Our names in a file: name => times used
function mitHookNames($text) {
    preg_match_all('/\b(mit[A-Z]\w*|MIT_[A-Z0-9_]+|mit_[a-z0-9_]+)\b|(?<![\w-])((?:data-)?mit-[a-z0-9-]+)|(MountaineerIT)/', $text, $m);
    $names = [];
    foreach ($m[0] as $name) {
        $names[$name] = ($names[$name] ?? 0) + 1;
    }
    ksort($names);
    return $names;
}

$now = [];
$ours = [];
foreach (mitHookFiles($root) as $rel) {
    if (mitHookOurs($rel)) {
        $ours[] = $rel;
        continue;
    }
    $names = mitHookNames((string) file_get_contents("$root/$rel"));
    if ($names) {
        $now[$rel] = $names;
    }
}

if ($update) {
    $out = "# Mountaineer IT hook manifest - written by: php scripts/mit_hook_check.php --update\n"
        . "# Our names in ITFlow's files (file, name, times used) and our own files. Update it after a deliberate change, never to\n"
        . "# make a failing check pass after a merge without finding out why the hook went.\n";
    foreach ($now as $rel => $names) {
        foreach ($names as $name => $count) {
            $out .= "hook\t$rel\t$name\t$count\n";
        }
    }
    foreach ($ours as $rel) {
        $out .= "ours\t$rel\n";
    }
    file_put_contents($manifest_file, $out);
    echo 'Manifest written: ' . count($now) . " ITFlow files with hooks, " . array_sum(array_map('count', $now)) . ' names, ' . count($ours) . " of our files.\n";
    exit(0);
}

if (!is_file($manifest_file)) {
    echo "No manifest yet - run: php scripts/mit_hook_check.php --update\n";
    exit(1);
}
$want = [];
$want_ours = [];
foreach (file($manifest_file, FILE_IGNORE_NEW_LINES) as $line) {
    $f = explode("\t", $line);
    if ($f[0] === 'hook' && count($f) === 4) {
        $want[$f[1]][$f[2]] = intval($f[3]);
    } elseif ($f[0] === 'ours' && count($f) === 2) {
        $want_ours[] = $f[1];
    }
}

$missing = [];
$new = [];
foreach ($want as $rel => $names) {
    if (!is_file("$root/$rel")) {
        $missing[] = "$rel: the file is gone (it had " . count($names) . ' of our hooks)';
        continue;
    }
    foreach ($names as $name => $count) {
        $have = $now[$rel][$name] ?? 0;
        if ($have < $count) {
            $missing[] = "$rel: $name used $have time" . ($have === 1 ? '' : 's') . ", was $count";
        }
    }
}
foreach ($now as $rel => $names) {
    foreach ($names as $name => $count) {
        if ($count > ($want[$rel][$name] ?? 0)) {
            $new[] = "$rel: $name ($count, was " . ($want[$rel][$name] ?? 0) . ')';
        }
    }
}
foreach ($want_ours as $rel) {
    if (!is_file("$root/$rel")) {
        $missing[] = "$rel: our file is gone";
    }
}
foreach (MIT_HOOK_REMOVED as $rel) {
    if (is_file("$root/$rel")) {
        $missing[] = "$rel: removed on purpose (CLAUDE.md) but back - check it, then delete it again";
    }
}

echo 'Hook check - ' . count($want) . ' ITFlow files with our hooks, ' . array_sum(array_map('count', $want)) . " names in the manifest.\n";
if ($new) {
    echo "\nNew since the manifest (fine if deliberate - then run --update):\n  " . implode("\n  ", array_slice($new, 0, 60)) . (count($new) > 60 ? "\n  ... and " . (count($new) - 60) . ' more' : '') . "\n";
}
if ($missing) {
    echo "\nMISSING - a hook (or our file) is gone; find out why before going on:\n  " . implode("\n  ", $missing) . "\n";
    exit(1);
}
echo "\nAll hooks present.\n";
exit(0);
