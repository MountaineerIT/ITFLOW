<?php
/*
 * Mountaineer IT - what an ITFlow update would do to our features, before it is merged. Compares ITFlow's code at two revisions
 * and checks each feature of the map (scripts/mit_features.php, worked out by scripts/mit_feature_lib.php) against the changes:
 *
 *   - ITFlow functions it calls that were removed, got a new signature or a changed body
 *   - ITFlow tables / columns it names that were removed or changed; new database updates
 *   - ITFlow files carrying its hooks that changed, and those a trial merge can't merge
 *   - watched strings and page ids / ITFlow classes its scripts use that are gone
 *   - "ITFlow does this too": new ITFlow tables, columns, functions or files named like the feature (an ours-or-theirs decision)
 *
 *   php scripts/mit_upstream_impact.php [--to=upstream/develop] [--from=<rev>] [--fetch] [--out=report.md]
 *
 * --to defaults to upstream/develop, --from to where our branch and --to last met (the last ITFlow commit merged). --fetch runs
 * git fetch upstream first. Read only: nothing is merged (the trial merge is git merge-tree, which writes no branch). Run it weekly
 * on upstream/develop to see a release coming, and before every "Pull in ITFlow's updates" on the release tag.
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
if (!empty($opts['fetch'])) {
    shell_exec('git -C ' . escapeshellarg(MIT_FEATURE_ROOT) . ' fetch upstream master develop --tags 2>&1');
}
$to = (string) ($opts['to'] ?? 'upstream/develop');
$from = (string) ($opts['from'] ?? (mfGit(['merge-base', 'HEAD', $to])[0] ?? ''));
if ($from === '' || !mfGit(['rev-parse', '--verify', "$to^{commit}"])) {
    fwrite(STDERR, "Can't find the revisions (to: $to, from: $from) - is the upstream remote fetched? (--fetch)\n");
    exit(2);
}
$short = function ($rev) {
    return mfGit(['rev-parse', '--short', $rev])[0] ?? $rev;
};

// ---------- What changed in ITFlow ----------
$commits = mfGit(['log', '--format=%h %ad %s', '--date=short', "$from..$to"]);
$changed = [];
foreach (mfGit(['diff', '--name-status', '-M', $from, $to]) as $line) {
    $parts = explode("\t", $line);
    $status = $parts[0][0];
    $path = $parts[count($parts) - 1];
    if (!mfIsOurs($path)) {
        $changed[$path] = $status;
        if ($status === 'R') {
            $changed[$parts[1]] = 'D'; // renamed away = gone from the old path
        }
    }
}
if (!$commits) {
    echo "Nothing new in ITFlow between " . $short($from) . " and $to.\n";
    exit(0);
}
$files_from = mfItflowFiles($from);
$files_to = mfItflowFiles($to);
$fn_from = mfFunctions($files_from, $from);
$fn_to = mfFunctions($files_to, $to);
$fn_change = []; // lower => [kind, file, detail]
foreach ($fn_from as $lower => $f) {
    if (!isset($fn_to[$lower])) {
        $fn_change[$lower] = ['removed', $f['file'], 'gone from ITFlow'];
    } elseif ($fn_to[$lower]['sig'] !== $f['sig']) {
        $fn_change[$lower] = ['signature', $fn_to[$lower]['file'], $f['sig'] . '  ->  ' . $fn_to[$lower]['sig']];
    } elseif ($fn_to[$lower]['hash'] !== $f['hash']) {
        $fn_change[$lower] = ['body', $fn_to[$lower]['file'], $fn_to[$lower]['file'] !== $f['file'] ? "moved from $f[file]" : ''];
    }
}
$fn_new = array_diff_key($fn_to, $fn_from);
$schema_from = mfSchema($from);
$schema_to = mfSchema($to);
$col_change = []; // "table.column" => detail
$new_names = []; // new table / column / function / file names, for overlaps
foreach ($schema_from as $table => $cols) {
    if (!isset($schema_to[$table])) {
        $col_change[$table] = 'table removed';
        continue;
    }
    foreach ($cols as $col => $def) {
        if (!isset($schema_to[$table][$col])) {
            $col_change["$table.$col"] = 'column removed';
        } elseif ($schema_to[$table][$col] !== $def) {
            $col_change["$table.$col"] = "$def  ->  " . $schema_to[$table][$col];
        }
    }
}
foreach ($schema_to as $table => $cols) {
    if (!isset($schema_from[$table])) {
        $new_names["table $table"] = $table;
    }
    foreach ($cols as $col => $def) {
        if (!isset($schema_from[$table][$col])) {
            $new_names["column $table.$col"] = $col;
        }
    }
}
foreach ($fn_new as $lower => $f) {
    $new_names["function $f[name]() in $f[file]"] = $f['name'];
}
foreach ($changed as $path => $status) {
    if ($status === 'A') {
        $new_names["file $path"] = $path;
    }
}
$db_updates = array_values(array_filter(array_keys($changed), function ($p) use ($changed) {
    return $changed[$p] === 'A' && preg_match('~^admin/database_updates/[^/]+\.php$~', $p);
}));
$version = function ($rev) {
    $app = preg_match("/APP_VERSION['\"]?\s*,\s*['\"]([^'\"]+)/", mfRead('includes/app_version.php', $rev), $m) ? $m[1] : '?';
    $db = '';
    foreach (mfGit(['ls-tree', '--name-only', "$rev:admin/database_updates"]) as $name) {
        if (preg_match('/^(\d+\.\d+\.\d+)\.php$/', $name, $m) && ($db === '' || version_compare($m[1], $db, '>'))) {
            $db = $m[1];
        }
    }
    return [$app, $db];
};
[$app_from, $db_from] = $version($from);
[$app_to, $db_to] = $version($to);

// Trial merge (writes no branch): files that would conflict
$conflicts = [];
$tree = mfGit(['merge-tree', '--write-tree', '--name-only', 'HEAD', $to]);
foreach (array_slice($tree, 1) as $line) {
    if ($line === '' || strpos($line, ' ') !== false) {
        break; // the informational messages after the file list
    }
    $conflicts[$line] = true;
}

// Page ids / ITFlow classes and watched strings, in ITFlow's code before and after
$corpus = function ($files, $rev) {
    $text = '';
    foreach ($files as $file) {
        $text .= mfRead($file, $rev) . "\n";
    }
    return $text;
};
$corpus_from = $corpus($files_from, $from);
$corpus_to = $corpus($files_to, $to);

// New ITFlow columns / tables with a name our database updates already use
$ours = mfOurSchemaNames();
$known = []; // names ITFlow already had (a join table's ticket_id is not ours, whatever our tables call their columns)
foreach ($schema_from as $table => $cols) {
    $known[$table] = true;
    foreach ($cols as $col => $def) {
        $known[$col] = true;
    }
}
$clashes = [];
foreach ($new_names as $label => $name) {
    if ((strpos($label, 'column ') === 0 || strpos($label, 'table ') === 0) && isset($ours[$name]) && !isset($known[$name])) {
        $clashes[$name] = "$label - ours from " . $ours[$name];
    }
}

// ---------- Each feature against it ----------
$features = mfFeatures();
$uses = mfUses($features); // what our code uses, read against ITFlow as merged now
$report = [];
foreach ($features as $key => $f) {
    $u = $uses[$key];
    $high = [];
    $check = [];
    foreach ($u['functions'] as $fn => $file) {
        $c = $fn_change[strtolower($fn)] ?? null;
        if (!$c) {
            continue;
        }
        if ($c[0] === 'removed') {
            $high[] = "ITFlow function `$fn()` it calls is gone ($c[1])";
        } elseif ($c[0] === 'signature') {
            $high[] = "ITFlow function `$fn()` it calls takes different arguments: `$c[2]`";
        } else {
            $check[] = "ITFlow function `$fn()` it calls was changed ($c[1]" . ($c[2] ? ", $c[2]" : '') . ')';
        }
    }
    foreach ($col_change as $what => $detail) {
        $parts = explode('.', $what);
        $col = $parts[1] ?? null;
        if (($col === null && isset($u['tables'][$parts[0]])) || ($col !== null && isset($u['columns'][$col]))) {
            ($detail === 'column removed' || $detail === 'table removed' ? $high[] = "`$what` it uses: $detail" : $check[] = "`$what` it uses changed: $detail");
        }
    }
    foreach ($u['hooks'] as $file => $names) {
        if (isset($conflicts[$file])) {
            $high[] = "`$file` (its hooks: " . implode(', ', array_unique($names)) . ') does not merge cleanly - resolve keeping the hooks';
        } elseif (isset($changed[$file])) {
            ($changed[$file] === 'D' ? $high[] = "`$file` (its hooks: " . implode(', ', array_unique($names)) . ') is gone or moved'
                : $check[] = "`$file` changed - its hooks there: " . implode(', ', array_unique($names)));
        }
    }
    foreach ($f['watch'] as $needle) {
        if (mfInCorpus($needle, $corpus_from) && !mfInCorpus($needle, $corpus_to)) {
            $high[] = "watched `$needle` is no longer in ITFlow's code";
        }
    }
    foreach (array_keys($u['selectors']) as $sel) {
        if (mfInCorpus($sel, $corpus_from) && !mfInCorpus($sel, $corpus_to)) {
            $high[] = "page id / class `$sel` its scripts or styles use is no longer in ITFlow's code";
        }
    }
    foreach ($clashes as $name => $detail) {
        foreach ($f['files_found'] as $file) {
            if (!preg_match('~^tests/|^scripts/|\.md$|database_updates~', $file) && preg_match('/\b' . preg_quote($name, '/') . '\b/', mfRead($file))) {
                $high[] = "NAME CLASH: ITFlow adds $detail - its database update will fail or collide with ours; plan the move (as M040 did for product_cost)";
                break;
            }
        }
    }
    $overlaps = [];
    foreach ($new_names as $label => $name) {
        foreach ($f['overlap'] as $word) {
            if (stripos($name, $word) !== false) {
                $overlaps[] = "$label (\"$word\")";
                break;
            }
        }
    }
    if ($overlaps) {
        $high[] = 'ITFlow may now do some of this itself - decide ours or theirs: ' . implode('; ', array_slice($overlaps, 0, 12)) . (count($overlaps) > 12 ? ' ... (' . count($overlaps) . ')' : '');
    }
    if ($high || $check) {
        $report[$key] = ['high' => $high, 'check' => $check];
    }
}

// ---------- The report ----------
$out = "# What ITFlow's update would touch\n\n"
    . 'ITFlow `' . $short($from) . '` -> `' . $to . '` (' . $short($to) . '): **' . count($commits) . ' commits**, ' . count($changed) . " files changed.\n"
    . "Version $app_from" . ($db_from ? " (database $db_from)" : '') . " -> $app_to" . ($db_to ? " (database $db_to)" : '')
    . ($db_updates ? '; new database updates: ' . implode(', ', array_map('basename', $db_updates)) : '; no database updates') . ".\n"
    . 'Trial merge with our branch: ' . ($conflicts ? count($conflicts) . ' files would conflict (' . implode(', ', array_keys($conflicts)) . ')' : 'merges cleanly') . ".\n\n";
if ($clashes) {
    $out .= "## Database name clashes\n\nITFlow's update adds names our database updates already use - its update will fail on our databases "
        . "(\"Duplicate column\") unless ours are moved aside first (mitBeforeItflowUpdate, then a copy in our next M file):\n\n"
        . implode("\n", array_map(function ($d) { return "- $d"; }, $clashes)) . "\n\n";
}
$highs = array_sum(array_map(function ($r) { return count($r['high']); }, $report));
$out .= "**" . count($report) . " of " . count($features) . " features touched, $highs things to act on.** Features not listed use nothing ITFlow changed.\n\n";
$out .= "| Feature | Act on | Check |\n|---|---|---|\n";
uasort($report, function ($a, $b) { return count($b['high']) <=> count($a['high']) ?: count($b['check']) <=> count($a['check']); });
foreach ($report as $key => $r) {
    $out .= "| " . $features[$key]['name'] . " (`$key`) | " . count($r['high']) . ' | ' . count($r['check']) . " |\n";
}
foreach ($report as $key => $r) {
    $f = $features[$key];
    $out .= "\n## " . $f['name'] . " (`$key`)\n\n";
    if ($r['high']) {
        $out .= "**Act on**\n\n" . implode("\n", array_map(function ($l) { return "- $l"; }, $r['high'])) . "\n\n";
    }
    if ($r['check']) {
        $out .= "**Check** (it may be fine - read the change against what the feature takes for granted)\n\n"
            . implode("\n", array_map(function ($l) { return "- $l"; }, array_slice($r['check'], 0, 40)))
            . (count($r['check']) > 40 ? "\n- ... and " . (count($r['check']) - 40) . ' more' : '') . "\n\n";
    }
    $out .= "**Takes for granted**\n\n" . implode("\n", array_map(function ($l) { return "- $l"; }, $f['assumes'])) . "\n\n";
    $out .= '**Prove it with** ' . ($f['tests_found'] ? implode(', ', array_map(function ($t) { return '`' . $t . '`'; }, $f['tests_found'])) : '_no tests - write one_') . "\n";
}
$out .= "\n## ITFlow's commits\n\n" . implode("\n", array_map(function ($c) { return "- $c"; }, array_slice($commits, 0, 200)))
    . (count($commits) > 200 ? "\n- ... and " . (count($commits) - 200) . ' more' : '') . "\n";

if (!empty($opts['out']) && $opts['out'] !== true) {
    file_put_contents($opts['out'], $out);
    echo "Written to $opts[out] - " . count($report) . " features touched, $highs to act on.\n";
} else {
    echo $out;
}
exit(0);
