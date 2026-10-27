<?php
/*
 * Mountaineer IT - shared by scripts/mit_feature_map.php and scripts/mit_upstream_impact.php: reads the feature map
 * (scripts/mit_features.php) and works out, from the code itself, what each feature uses of ITFlow - functions it calls, tables
 * and columns it names, ITFlow files that carry its hooks (scripts/mit_hooks.txt), page ids / ITFlow classes its scripts and
 * styles look for. Read only. Works on any git revision of ITFlow's side (for the impact report), always on our files as checked out.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const MIT_FEATURE_ROOT = __DIR__ . '/..';

/** Run git in the repo, lines out. */
function mfGit(array $args) {
    $cmd = 'git -C ' . escapeshellarg(MIT_FEATURE_ROOT) . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>/dev/null';
    $out = shell_exec($cmd);
    return $out === null ? [] : array_values(array_filter(explode("\n", $out), 'strlen'));
}

/** A file's text at a revision (null = as checked out). '' when it isn't there. Revisions read through mfPrefetch are cached. */
function mfRead($path, $rev = null) {
    global $mf_cache;
    if ($rev === null) {
        $file = MIT_FEATURE_ROOT . '/' . $path;
        return is_file($file) ? (string) file_get_contents($file) : '';
    }
    if (isset($mf_cache[$rev][$path])) {
        return $mf_cache[$rev][$path];
    }
    $out = shell_exec('git -C ' . escapeshellarg(MIT_FEATURE_ROOT) . ' show ' . escapeshellarg("$rev:$path") . ' 2>/dev/null');
    return (string) $out;
}

/** Read many files of a revision at once (git cat-file --batch) into mfRead's cache. */
function mfPrefetch($rev, array $paths) {
    global $mf_cache;
    if ($rev === null || !$paths) {
        return;
    }
    $proc = proc_open(['git', '-C', MIT_FEATURE_ROOT, 'cat-file', '--batch'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    if (!is_resource($proc)) {
        return;
    }
    foreach ($paths as $path) {
        if (isset($mf_cache[$rev][$path])) {
            continue;
        }
        fwrite($pipes[0], "$rev:$path\n");
        fflush($pipes[0]);
        $head = fgets($pipes[1]);
        if ($head === false || substr(rtrim($head), -7) === 'missing') {
            $mf_cache[$rev][$path] = '';
            continue;
        }
        $size = intval(explode(' ', trim($head))[2] ?? 0);
        $data = '';
        while (strlen($data) < $size) {
            $chunk = fread($pipes[1], $size - strlen($data));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $data .= $chunk;
        }
        fgets($pipes[1]); // the newline after the content
        $mf_cache[$rev][$path] = $data;
    }
    fclose($pipes[0]);
    fclose($pipes[1]);
    proc_close($proc);
}

/** Is this file ours (not ITFlow's)? */
function mfIsOurs($path) {
    return (bool) preg_match('~(^|/)mit_|(^|/)MIT_|mountaineerit|^tests/|^css/fonts/inter/|^(CLAUDE|DEPLOYMENT)\.md$|^scripts/install(_local_ai)?\.sh$~', $path);
}

/** ITFlow's own code files at a revision (null = checked out) - PHP, JS and CSS, without libraries. */
function mfItflowFiles($rev = null) {
    $files = $rev === null ? mfGit(['ls-files', '--cached', '--others', '--exclude-standard']) : mfGit(['ls-tree', '-r', '--name-only', $rev]);
    $files = array_values(array_filter($files, function ($f) {
        return !mfIsOurs($f) && preg_match('/\.(php|js|css)$/', $f) && !preg_match('~^(libs|vendor|node_modules|plugins)/|\.min\.(js|css)$~', $f);
    }));
    mfPrefetch($rev, array_merge($files, ['db.sql']));
    return $files;
}

/** The feature map, with each feature's globs turned into our files and tests as checked out. */
function mfFeatures() {
    $map = require __DIR__ . '/mit_features.php';
    $all = mfGit(['ls-files', '--cached', '--others', '--exclude-standard']);
    foreach ($map as $key => &$feature) {
        foreach (['files', 'tests'] as $kind) {
            $matched = [];
            foreach ($feature[$kind] as $glob) {
                foreach ($all as $file) {
                    if (fnmatch($glob, $file, FNM_PATHNAME) || ($glob === $file)) {
                        $matched[$file] = true;
                    }
                }
                if (!array_filter(array_keys($matched), function ($f) use ($glob) { return fnmatch($glob, $f, FNM_PATHNAME); })) {
                    $feature['missing'][] = "$kind: $glob";
                }
            }
            $feature[$kind . '_found'] = array_keys($matched);
        }
        $feature['key'] = $key;
    }
    return $map;
}

/** Our files (as checked out) that the map doesn't place in any feature. */
function mfUnplaced(array $features) {
    $placed = [];
    foreach ($features as $feature) {
        foreach ($feature['files_found'] as $file) {
            $placed[$file] = true;
        }
    }
    return array_values(array_filter(mfGit(['ls-files', '--cached', '--others', '--exclude-standard']), function ($f) use ($placed) {
        return mfIsOurs($f) && !isset($placed[$f]) && !preg_match('~^(CLAUDE|DEPLOYMENT)\.md$|^scripts/install(_local_ai)?\.sh$|^css/fonts/inter/~', $f);
    }));
}

/**
 * PHP functions defined in $files, name => [file, signature, body hash]. Body hash = the function's tokens without whitespace
 * and comments, so a reformat doesn't count as a change.
 */
function mfFunctions(array $files, $rev = null) {
    $out = [];
    foreach ($files as $file) {
        if (substr($file, -4) !== '.php') {
            continue;
        }
        $tokens = @token_get_all(mfRead($file, $rev));
        $count = count($tokens);
        $depth = 0;
        $class_until = null; // brace depth at which the class body we are in ends
        $class_pending = false;
        for ($i = 0; $i < $count; $i++) {
            $t = $tokens[$i];
            if (is_array($t) && in_array($t[0], [T_CLASS, T_TRAIT, T_INTERFACE], true)) {
                $p = $i - 1;
                while ($p >= 0 && is_array($tokens[$p]) && $tokens[$p][0] === T_WHITESPACE) {
                    $p--;
                }
                if (!($p >= 0 && is_array($tokens[$p]) && $tokens[$p][0] === T_DOUBLE_COLON)) { // not Foo::class
                    $class_pending = true;
                }
            }
            if ($t === '{' || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                if ($class_pending && $class_until === null) {
                    $class_until = $depth;
                    $class_pending = false;
                }
                $depth++;
                continue;
            }
            if ($t === '}') {
                $depth--;
                if ($class_until !== null && $depth === $class_until) {
                    $class_until = null;
                }
                continue;
            }
            if (!is_array($t) || $t[0] !== T_FUNCTION || $class_until !== null) {
                continue;
            }
            $j = $i + 1;
            while ($j < $count && is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $j++;
            }
            if ($j < $count && $tokens[$j] === '&') {
                $j++;
                while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                    $j++;
                }
            }
            if ($j >= $count || !is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING) {
                continue; // a closure
            }
            $name = $tokens[$j][1];
            // Signature: up to the body's {
            $sig = '';
            $k = $j;
            while ($k < $count && $tokens[$k] !== '{' && $tokens[$k] !== ';') {
                $sig .= is_array($tokens[$k]) ? $tokens[$k][1] : $tokens[$k];
                $k++;
            }
            if ($k >= $count || $tokens[$k] === ';') {
                continue; // abstract / interface
            }
            $inner = 0;
            $body = '';
            for (; $k < $count; $k++) {
                $b = $tokens[$k];
                if ($b === '{' || (is_array($b) && in_array($b[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                    $inner++;
                } elseif ($b === '}') {
                    $inner--;
                }
                if (!is_array($b) || !in_array($b[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    $body .= is_array($b) ? $b[1] : $b;
                }
                if ($inner === 0) {
                    break;
                }
            }
            $out[strtolower($name)] = ['name' => $name, 'file' => $file, 'sig' => preg_replace('/\s+/', ' ', trim($sig)), 'hash' => md5($body)];
            $i = $k;
        }
    }
    return $out;
}

/** Function names a PHP file calls (lowercase => true) and the words in its strings / HTML (for tables, columns, keys). */
function mfCallsAndWords($text) {
    $calls = [];
    $words = [];
    $tokens = @token_get_all($text);
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        $t = $tokens[$i];
        if (!is_array($t)) {
            continue;
        }
        if ($t[0] === T_STRING) {
            $j = $i + 1;
            while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                $j++;
            }
            $p = $i - 1;
            while ($p >= 0 && is_array($tokens[$p]) && $tokens[$p][0] === T_WHITESPACE) {
                $p--;
            }
            $before = $p >= 0 ? (is_array($tokens[$p]) ? $tokens[$p][0] : $tokens[$p]) : null;
            if ($j < $count && $tokens[$j] === '(' && !in_array($before, [T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW], true)
                && !(defined('T_NULLSAFE_OBJECT_OPERATOR') && $before === T_NULLSAFE_OBJECT_OPERATOR)) {
                $calls[strtolower($t[1])] = true;
            }
            // function_exists('x') / is_callable - a guarded call
        }
        if (in_array($t[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true)) {
            if (preg_match_all('/[A-Za-z_][A-Za-z0-9_]{2,}/', $t[1], $m)) {
                foreach ($m[0] as $w) {
                    $words[$w] = true;
                }
            }
            if ($t[0] === T_CONSTANT_ENCAPSED_STRING && preg_match('/^[\'"]([A-Za-z_][A-Za-z0-9_]*)[\'"]$/', $t[1], $m)) {
                $calls[strtolower($m[1])] = true; // function_exists('name') / call_user_func('name')
            }
        }
    }
    return [$calls, $words];
}

/** ITFlow's tables and their columns from db.sql at a revision: table => [column => definition]. */
function mfSchema($rev = null) {
    $sql = mfRead('db.sql', $rev);
    $schema = [];
    if (preg_match_all('/CREATE TABLE `([^`]+)` \((.*?)\n\)[^;]*;/s', $sql, $m, PREG_SET_ORDER)) {
        foreach ($m as $table) {
            $columns = [];
            foreach (explode("\n", $table[2]) as $line) {
                if (preg_match('/^\s*`([^`]+)`\s+(.*?),?\s*$/', $line, $c)) {
                    $columns[$c[1]] = $c[2];
                }
            }
            $schema[$table[1]] = $columns;
        }
    }
    return $schema;
}

/** Our hook manifest: ITFlow file => [name => count]. */
function mfHooks() {
    $hooks = [];
    foreach (explode("\n", mfRead('scripts/mit_hooks.txt')) as $line) {
        $parts = explode("\t", $line);
        if (($parts[0] ?? '') === 'hook' && count($parts) >= 4) {
            $hooks[$parts[1]][$parts[2]] = intval($parts[3]);
        }
    }
    return $hooks;
}

/** Ids and ITFlow classes our scripts / styles look for: '#id' / '.itflow-x' / '.app-x' / getElementById('id'), as plain names. */
function mfSelectors($text) {
    $names = [];
    if (preg_match_all('/getElementById\(\s*[\'"]([A-Za-z][\w-]+)[\'"]/', $text, $m)) {
        foreach ($m[1] as $n) {
            $names['#' . $n] = true;
        }
    }
    if (preg_match_all('/#([A-Za-z][\w-]{2,})/', $text, $m)) {
        foreach ($m[1] as $n) {
            if (!preg_match('/^[0-9a-fA-F]{3,8}$/', $n)) { // a colour
                $names['#' . $n] = true;
            }
        }
    }
    if (preg_match_all('/\.((?:itflow|app)-[\w-]+)/', $text, $m)) {
        foreach ($m[1] as $n) {
            $names['.' . $n] = true;
        }
    }
    return $names;
}

/** Is selector / string $needle present in ITFlow's code text $corpus? (#id as id="..." or #id, .class as a class name) */
function mfInCorpus($needle, $corpus) {
    if ($needle[0] === '#') {
        $id = substr($needle, 1);
        return (bool) preg_match('/(id\s*=\s*[\'"]' . preg_quote($id, '/') . '[\'"]|#' . preg_quote($id, '/') . '\b|getElementById\(\s*[\'"]' . preg_quote($id, '/') . '[\'"])/', $corpus);
    }
    if ($needle[0] === '.') {
        return strpos($corpus, substr($needle, 1)) !== false;
    }
    return strpos($corpus, $needle) !== false;
}

/**
 * Everything each feature uses of ITFlow, from the code: [feature key => ['functions' => [name => ITFlow file], 'tables' => [...],
 * 'columns' => [...], 'hooks' => [ITFlow file => [names]], 'selectors' => [...], 'watch' => [...]]]. $rev = ITFlow's side to
 * read (null = checked out).
 */
function mfUses(array $features, $rev = null) {
    $itflow_files = mfItflowFiles($rev);
    $itflow_functions = mfFunctions($itflow_files, $rev);
    $schema = mfSchema($rev);
    $columns = [];
    foreach ($schema as $table => $cols) {
        foreach ($cols as $col => $def) {
            $columns[$col] = $table;
        }
    }
    $corpus = '';
    foreach ($itflow_files as $file) {
        $corpus .= mfRead($file, $rev) . "\n";
    }
    // Our functions -> feature, for placing hooks
    $our_functions = [];
    foreach ($features as $key => $feature) {
        foreach (mfFunctions($feature['files_found']) as $lower => $fn) {
            $our_functions[$lower][$key] = true;
        }
    }
    $hooks = mfHooks();
    $uses = [];
    foreach ($features as $key => $feature) {
        $u = ['functions' => [], 'tables' => [], 'columns' => [], 'hooks' => [], 'selectors' => [], 'watch' => []];
        foreach ($feature['files_found'] as $file) {
            if (preg_match('~^tests/|^scripts/mit_|\.md$|\.txt$~', $file)) {
                continue;
            }
            $text = mfRead($file);
            if (substr($file, -4) === '.php') {
                [$calls, $words] = mfCallsAndWords($text);
                foreach ($calls as $lower => $true) {
                    if (isset($itflow_functions[$lower])) {
                        $u['functions'][$itflow_functions[$lower]['name']] = $itflow_functions[$lower]['file'];
                    }
                }
            } else {
                $words = [];
                if (preg_match_all('/[A-Za-z_][A-Za-z0-9_]{2,}/', $text, $m)) {
                    $words = array_fill_keys($m[0], true);
                }
            }
            foreach ($words as $w => $true) {
                if (isset($schema[$w])) {
                    $u['tables'][$w] = true;
                }
                if (isset($columns[$w]) && strpos($w, '_') !== false) {
                    $u['columns'][$w] = $columns[$w];
                }
            }
            if (preg_match('/\.(js|css)$/', $file)) {
                foreach (mfSelectors($text) as $sel => $true) {
                    if (mfInCorpus($sel, $corpus)) {
                        $u['selectors'][$sel] = true;
                    }
                }
            }
        }
        foreach ($hooks as $itflow_file => $names) {
            foreach ($names as $name => $count) {
                $lower = strtolower($name);
                $base = preg_replace('/\.php$/', '', basename($name));
                $mine = isset($our_functions[$lower][$key]);
                if (!$mine) {
                    foreach ($feature['files_found'] as $file) {
                        if (pathinfo($file, PATHINFO_FILENAME) === $base) {
                            $mine = true;
                            break;
                        }
                    }
                }
                if (!$mine && !isset($our_functions[$lower]) && preg_match('/^[A-Za-z_][\w-]*$/', $name)) {
                    // A key / variable / attribute name of ours: the feature whose files use it
                    foreach ($feature['files_found'] as $file) {
                        if (!preg_match('~^tests/|^scripts/|\.md$|database_updates~', $file) && preg_match('/(?<![\w-])' . preg_quote($name, '/') . '(?![\w-])/', mfRead($file))) {
                            $mine = true;
                            break;
                        }
                    }
                }
                if (!$mine && (in_array($name, $feature['claims'] ?? [], true) || in_array("$itflow_file:$name", $feature['claims'] ?? [], true))) {
                    $mine = true;
                }
                if ($mine) {
                    $u['hooks'][$itflow_file][] = $name;
                }
            }
        }
        foreach ($feature['watch'] as $needle) {
            $u['watch'][$needle] = mfInCorpus($needle, $corpus);
        }
        ksort($u['functions']);
        ksort($u['tables']);
        ksort($u['columns']);
        ksort($u['hooks']);
        ksort($u['selectors']);
        $uses[$key] = $u;
    }
    // Names no feature's files use (a variable inside ITFlow's file, a class our CSS styles there): the features whose functions
    // are called on the lines around it in that ITFlow file
    $owners = [];
    foreach ($our_functions as $lower => $keys) {
        $owners[$lower] = array_keys($keys);
    }
    $claimed = [];
    foreach ($uses as $u) {
        foreach ($u['hooks'] as $file => $names) {
            foreach ($names as $n) {
                $claimed["$file\t$n"] = true;
            }
        }
    }
    foreach ($hooks as $file => $names) {
        $lines = null;
        foreach ($names as $name => $count) {
            if (isset($claimed["$file\t$name"])) {
                continue;
            }
            $lines = $lines ?? explode("\n", mfRead($file));
            $found = [];
            foreach ($lines as $i => $line) {
                if (strpos($line, $name) === false) {
                    continue;
                }
                $window = implode("\n", array_slice($lines, max(0, $i - 3), 7));
                if (preg_match_all('/\b(mit[A-Z]\w+)\s*\(/', $window, $m)) {
                    foreach ($m[1] as $fn) {
                        foreach ($owners[strtolower($fn)] ?? [] as $key) {
                            $found[$key] = true;
                        }
                    }
                }
            }
            foreach (array_keys($found) as $key) {
                $uses[$key]['hooks'][$file][] = $name;
                $claimed["$file\t$name"] = true;
            }
        }
    }
    // Hook names still unclaimed - listed under platform so nothing is lost
    foreach ($uses as $u) {
        foreach ($u['hooks'] as $file => $names) {
            foreach ($names as $n) {
                $claimed["$file\t$n"] = true;
            }
        }
    }
    foreach ($hooks as $file => $names) {
        foreach ($names as $name => $count) {
            if (!isset($claimed["$file\t$name"]) && isset($uses['platform'])) {
                $uses['platform']['hooks'][$file][] = $name;
            }
        }
    }
    return $uses;
}

/**
 * Names our database updates create or use (admin/database_updates/mountaineerit/*.php, as checked out): every `backticked`
 * name and every 'quoted' identifier. A NEW ITFlow column or table with one of these names is a clash - ITFlow's update would
 * fail ("Duplicate column", as 2.9.4's product_cost did) or mean something else than ours.
 */
function mfOurSchemaNames() {
    $names = [];
    foreach (mfGit(['ls-files', 'admin/database_updates/mountaineerit/']) as $file) {
        if (preg_match_all('/`([A-Za-z_][A-Za-z0-9_]*)`|\'([a-z][a-z0-9]*_[a-z0-9_]+)\'/', mfRead($file), $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $names[$hit[1] !== '' ? $hit[1] : $hit[2]] = basename($file);
            }
        }
    }
    return $names;
}

