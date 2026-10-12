<?php
/**
 * phpue-lint.php: static checks for PHPue (.pvue) projects, based on how conversion.php actually
 * compiles files. Also compiles every .pvue with the project's own conversion.php and runs `php -l`
 * on the result.
 *
 * Version-aware: the rule set follows PHPUE_VERSION in the project's index.php (falling back to
 * sniffing conversion.php). 0.0.2 projects keep the 0.0.2 rules; 0.0.3+ projects get the 0.0.3 rules
 * (single-line p-if, nested <template>, {{ count($x) }} etc. are fine there).
 *
 * Usage:
 *   php phpue-lint.php [path/to/www] [--no-compile] [--errors-only] [--version=0.0.3]
 *
 * Exit code: 1 if any ERROR was found, else 0.
 * Levels: ERROR (output is broken), WARN (likely wrong / fragile), INFO (worth knowing).
 */

$args      = array_slice($argv, 1);
$noCompile = in_array('--no-compile', $args, true);
$errOnly   = in_array('--errors-only', $args, true);
$forcedVer = null;
foreach ($args as $a) if (preg_match('/^--version=([0-9][0-9.]*)$/', $a, $vm)) $forcedVer = $vm[1];
$paths     = array_values(array_filter($args, fn($a) => substr($a, 0, 2) !== '--'));
$start     = realpath($paths[0] ?? getcwd());

if ($start === false) { fwrite(STDERR, "Path not found\n"); exit(2); }
$www = null;
foreach ([$start, $start . '/www'] as $cand) {
    if (is_file("$cand/conversion.php") || is_file("$cand/App.pvue")) { $www = $cand; break; }
}
if ($www === null) { fwrite(STDERR, "No PHPue web root (conversion.php / App.pvue) at $start\n"); exit(2); }
chdir($www);

$issues = [];
function issue(string $level, string $file, int $line, string $msg): void {
    global $issues;
    $issues[] = [$level, $file, $line, $msg];
}
function lineAt(string $content, int $offset): int {
    return substr_count(substr($content, 0, max(0, $offset)), "\n") + 1;
}

// ---------------------------------------------------------------- version
$indexSrc = is_file('index.php') ? file_get_contents('index.php') : '';
$convSrc  = is_file('conversion.php') ? file_get_contents('conversion.php') : '';
$declared = preg_match('/define\(\s*[\'"]PHPUE_VERSION[\'"]\s*,\s*[\'"]([0-9][0-9.]*)[\'"]/', $indexSrc, $vm) ? $vm[1] : null;
$compiler = $convSrc === '' ? null : (str_contains($convSrc, 'function extractBlocks') ? '0.0.3' : '0.0.2');
if ($forcedVer !== null)    { $version = $forcedVer; $verFrom = '--version'; }
elseif ($declared !== null) { $version = $declared;  $verFrom = 'PHPUE_VERSION in index.php'; }
elseif ($compiler !== null) { $version = $compiler;  $verFrom = 'sniffed from conversion.php'; }
else                        { $version = '0.0.2';    $verFrom = 'assumed: no index.php/conversion.php'; }
$v3 = version_compare($version, '0.0.3', '>=');

if ($declared !== null && $compiler !== null && version_compare($declared, '0.0.3', '>=') !== ($compiler === '0.0.3')) {
    issue('ERROR', 'index.php', 1, "index.php says PHPUE_VERSION $declared but conversion.php is the $compiler compiler. Upgrade by replacing BOTH files (they are one release).");
}
$sessionPolicy = preg_match('/define\(\s*[\'"]PHPUE_SESSION[\'"]\s*,\s*[\'"](\w+)[\'"]/', $indexSrc, $sm) ? $sm[1] : 'auto';
if ($v3 && preg_match('/define\(\s*[\'"]PHPUE_DEV_TOOLS[\'"]\s*,\s*(true|1|[\'"]1[\'"])\s*\)/i', $indexSrc, $dm, PREG_OFFSET_CAPTURE)) {
    issue('WARN', 'index.php', lineAt($indexSrc, $dm[0][1]), 'PHPUE_DEV_TOOLS is forced on: ?compile=, ?build and ?hot-reload stay reachable even when .dist/ is deployed. Only do this on a dev machine.');
}
if ($v3) {
    // 0.0.3's conversion.php uses str_contains(): PHP 8.0+ (the README badge still says 7.4+)
    $pinFiles = array_merge(
        glob(dirname($www) . '/{docker-compose,compose}*.{yml,yaml}', GLOB_BRACE) ?: [],
        glob(dirname($www) . '/{Dockerfile,Dockerfile.*,docker/*/Dockerfile*}', GLOB_BRACE) ?: [],
        glob('{composer.json,../composer.json}', GLOB_BRACE) ?: []
    );
    foreach ($pinFiles as $pf) {
        $src = file_get_contents($pf);
        if (preg_match('/\bphp:7\.\d|\bphp7\.\d|"php"\s*:\s*"[^"]*\b(?:5|7)\.\d/i', $src, $pm, PREG_OFFSET_CAPTURE)) {
            issue('ERROR', str_starts_with($pf, dirname($www) . '/') ? '../' . substr($pf, strlen(dirname($www)) + 1) : $pf, lineAt($src, $pm[0][1]), "PHPue $version needs PHP 8.0+ (conversion.php uses str_contains()), but this file pins PHP 7: '{$pm[0][0]}'.");
        }
    }
}

// ---------------------------------------------------------------- helpers
/** 0.0.2 PHPueConverter::extractBetween: first $start to first $end anywhere; also returns the offset. */
function block(string $content, string $start, string $end): array {
    $p = '/' . preg_quote($start, '/') . '(.*?)' . preg_quote($end, '/') . '/s';
    if (preg_match($p, $content, $m, PREG_OFFSET_CAPTURE)) return [$m[1][0], $m[1][1]];
    return ['', -1];
}

/** 0.0.3 PHPueConverter::findTemplateEnd (depth-aware, skips comments and PHP blocks). */
function templateEnd(string $c, int $from): int {
    $depth = 1; $offset = $from;
    while (preg_match('/<!--.*?-->|<\?(?:php|=).*?\?>|<template(?=[\s>\/])|<\/template\s*>/is', $c, $m, PREG_OFFSET_CAPTURE, $offset)) {
        $tok = $m[0][0]; $at = $m[0][1]; $offset = $at + strlen($tok);
        if ($tok[1] === '!' || $tok[1] === '?') continue;
        if ($tok[1] === '/') { if (--$depth === 0) return $at; } else { $depth++; }
    }
    return strlen($c);
}

/**
 * 0.0.3 PHPueConverter::extractBlocks (top-level blocks only), with offsets. Also returns the text
 * that sits outside every block ('stray', which the compiler ignores, in 0.0.2 too) and whether the
 * template never closes. Stray text right after the PHP block almost always means a literal closing
 * script tag inside a PHP string/comment ended the block early.
 */
function topLevelBlocks(string $c): array {
    $out = ['script' => ['', -1], 'header' => ['', -1], 'template' => ['', -1], 'cscript' => ['', -1], 'stray' => [], 'templateOpen' => false];
    $simple = [['<script setup>', '</script>', 'script'], ['<script>', '</script>', 'script'], ['<header>', '</header>', 'header'], ['<cscript>', '</cscript>', 'cscript']];
    $len = strlen($c); $pos = 0; $cursor = 0; $prev = null;
    $gap = function (int $to) use (&$out, &$cursor, &$prev, $c) {
        $txt = substr($c, $cursor, max(0, $to - $cursor));
        if (trim($txt) !== '') $out['stray'][] = [$cursor + strlen($txt) - strlen(ltrim($txt)), trim($txt), $prev];
    };
    while ($pos < $len) {
        $lt = strpos($c, '<', $pos);
        if ($lt === false) break;
        if (substr($c, $lt, 4) === '<!--') {
            $gap($lt);
            $e = strpos($c, '-->', $lt + 4);
            $pos = $cursor = $e === false ? $len : $e + 3;
            continue;
        }
        if (substr($c, $lt, 10) === '<template>') {
            $gap($lt);
            $s = $lt + 10; $e = templateEnd($c, $s);
            if ($out['template'][1] < 0) {
                $out['template'] = [substr($c, $s, $e - $s), $s];
                if ($e >= $len) $out['templateOpen'] = true;
            }
            $pos = $cursor = min($len, $e + strlen('</template>')); $prev = 'template';
            continue;
        }
        $matched = false;
        foreach ($simple as [$open, $close, $name]) {
            if (substr($c, $lt, strlen($open)) !== $open) continue;
            $gap($lt);
            $s = $lt + strlen($open);
            $e = strpos($c, $close, $s);
            if ($e === false) $e = $len;
            if ($out[$name][1] < 0) $out[$name] = [substr($c, $s, $e - $s), $s];
            $pos = $cursor = min($len, $e + strlen($close)); $prev = $name;
            $matched = true;
            break;
        }
        if (!$matched) $pos = $lt + 1;
    }
    $gap($len);
    return $out;
}

/** PHP code with comments and string contents blanked (same length), so prose can't trigger checks. */
function maskPhp(string $code): string {
    if (!function_exists('token_get_all')) return $code;
    $toks = @token_get_all('<?php ' . $code);
    $out = '';
    foreach ($toks as $i => $t) {
        $txt = is_array($t) ? $t[1] : $t;
        if ($i === 0 && is_array($t) && $t[0] === T_OPEN_TAG) {
            $txt = substr($txt, 6);
        } elseif (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
            $txt = preg_replace('/[^\n]/', ' ', $txt);
        }
        $out .= $txt;
    }
    return strlen($out) === strlen($code) ? $out : $code;
}

/** 0.0.3 maskOpaqueRegions: PHP blocks, comments, {{ }} and script/style bodies blanked (same length). */
function maskTemplate(string $t): string {
    return preg_replace_callback('/<\?(?:php|=).*?\?>|<!--.*?-->|\{\{.*?\}\}|(<(script|style)\b[^>]*>)(.*?)(<\/\2\s*>)/is', function ($m) {
        if (!empty($m[1])) return $m[1] . preg_replace('/[^\n]/', ' ', $m[3]) . $m[4];
        return preg_replace('/[^\n]/', ' ', $m[0]);
    }, $t);
}

/** 0.0.3 tag walk: [offset, raw opening tag] for every real opening tag (quote-aware end, attributes skipped). */
function openTags(string $masked, string $raw): array {
    $tags = []; $offset = 0; $len = strlen($masked);
    while (preg_match('/<([a-zA-Z][a-zA-Z0-9:-]*)(?=[\s\/>])/', $masked, $m, PREG_OFFSET_CAPTURE, $offset)) {
        $lt = $m[0][1]; $quote = null; $end = null;
        for ($i = $lt + 1; $i < $len; $i++) {
            $ch = $masked[$i];
            if ($quote !== null) { if ($ch === $quote) $quote = null; continue; }
            if ($ch === '"' || $ch === "'") { $quote = $ch; continue; }
            if ($ch === '>') { $end = $i + 1; break; }
        }
        if ($end === null) break;
        $tags[] = [$lt, substr($raw, $lt, $end - $lt)];
        $offset = $end;
    }
    return $tags;
}

/** End offset of a function body that starts after $from (brace matching on masked code). */
function bodyEnd(string $masked, int $from): int {
    $depth = 1; $i = $from; $len = strlen($masked);
    while ($i < $len && $depth > 0) { $ch = $masked[$i]; if ($ch === '{') $depth++; elseif ($ch === '}') $depth--; $i++; }
    return $i;
}

const SUPERGLOBALS = ['this', 'GLOBALS', '_GET', '_POST', '_SESSION', '_SERVER', '_COOKIE', '_FILES', '_REQUEST', '_ENV', 'http_response_header', 'argv', 'argc'];

/** Variable names a (masked) PHP fragment defines: assignments, foreach targets, params, global/static, catch, destructuring, by-ref outputs. */
function definedVars(string $code): array {
    $names = [];
    $grab = function (string $s) use (&$names) { if (preg_match_all('/\$([A-Za-z_]\w*)/', $s, $m)) foreach ($m[1] as $n) $names[$n] = true; };
    if (preg_match_all('/\$([A-Za-z_]\w*)\s*(?:\[[^\]\n]*\]\s*)*(?:=(?![=>])|\.=|\+=|-=|\*=|\/=|\?\?=|\|=|&=)/', $code, $m)) foreach ($m[1] as $n) $names[$n] = true;
    foreach (['/\bas\b([^)]*)\)/', '/\b(?:global|static)\s+(\$[^;]+);/', '/\bcatch\s*\(([^)]*)\)/', '/\buse\s*\(([^)]*)\)/',
              '/\b(?:function|fn)\b[^(]*\(((?:[^()]|\([^()]*\))*)\)/', '/(?:\blist\s*\(|\[)([^\]\)]*)[\])]\s*=(?![=>])/',
              '/\b(?:preg_match_all|preg_match|preg_replace_callback|parse_str|mb_parse_str|exec|similar_text|getimagesize|openssl_\w+|stream_select|sscanf)\s*\(([^;]*)\)/'] as $re) {
        if (preg_match_all($re, $code, $m)) foreach ($m[1] as $s) $grab($s);
    }
    return $names;
}

/** First offset of every $var used in a fragment. */
function usedVars(string $code): array {
    $out = [];
    if (preg_match_all('/\$([A-Za-z_]\w*)/', $code, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[1] as [$n, $o]) if (!in_array($n, SUPERGLOBALS, true) && !isset($out[$n])) $out[$n] = $o - 1;
    }
    return $out;
}

/** PHP that runs in a template: <?php ?> / <?= ?> blocks, {{ }} expressions and p-if/p-for values, as [code, offset] pieces. */
function templatePhp(string $template): array {
    $pieces = [];
    if (preg_match_all('/<\?(?:php|=)(.*?)\?>|\{\{\s*(\$.*?|\\\\?[A-Za-z_][\w\\\\]*\s*(?:\(|::).*?)\s*\}\}|\sp-(?:if|for)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/s', $template, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
        foreach ($m as $g) {
            for ($k = 1; $k <= 4; $k++) if (isset($g[$k]) && $g[$k][1] >= 0 && $g[$k][0] !== '') { $pieces[] = [$g[$k][0], $g[$k][1]]; break; }
        }
    }
    return $pieces;
}

// ---------------------------------------------------------------- collect files
$files = [];
if (is_file('App.pvue')) $files[] = 'App.pvue';
foreach (glob('views/*.pvue') ?: [] as $f) $files[] = $f;
$all = [];
if (is_dir('views'))      $all = array_merge($all, iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator('views', FilesystemIterator::SKIP_DOTS))));
if (is_dir('components')) $all = array_merge($all, iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator('components', FilesystemIterator::SKIP_DOTS))));
foreach ($all as $f) {
    $f = str_replace('\\', '/', (string)$f);
    if (substr($f, -5) !== '.pvue') continue;
    if (str_starts_with($f, 'views/') && substr_count($f, '/') > 1) {
        issue('WARN', $f, 1, 'Views must be top-level views/*.pvue; nested views are never routed (use query params).');
    }
    if (!in_array($f, $files, true)) $files[] = $f;
}
sort($files);

if (!is_dir('components') && !$v3) issue('ERROR', 'components/', 1, 'No components/ directory: `php index.php build` throws (RecursiveDirectoryIterator on components) in 0.0.2. Create it, even empty (fixed in 0.0.3).');
if (is_dir('.dist')) issue('WARN', '.dist/', 1, '.dist/ exists: the server serves compiled files and ignores .pvue edits until you rebuild (php index.php build) or delete .dist/.');
if (is_file('App.php') || is_dir('pages')) issue('WARN', 'App.php|pages/', 1, 'Extracted compiled deploy present in web root: it takes priority over App.pvue.');

// project-wide data
$ajaxNames = [];        // name => [file, line, method]
$usage     = [];        // component name => count of placeholders across templates
$xssrUsed  = false;
$extLoaded = false;
$jsSources = '';
$assetJs   = [];
foreach (glob('assets/js/{*,*/*,*/*/*}.js', GLOB_BRACE) ?: [] as $js) { $assetJs[$js] = file_get_contents($js); $jsSources .= $assetJs[$js]; }

$appRequiredViews = [];
if (is_file('App.pvue')) {
    preg_match_all('/^\s*#require\s+[\'"]([^\'"]+\.pvue)[\'"]\s*;?\s*$/m', file_get_contents('App.pvue'), $m);
    $appRequiredViews = $m[1];
}

$requireRe = $v3 ? '/@require\s+(\w+)\s+[\'"]([^\'"]+)[\'"]\s*;?/' : '/@require\s+(\w+)\s+\'([^\']+)\'\s*;?/';
$ajaxRe    = $v3 ? '/@AJAX\(\s*[\'"]([A-Za-z]+)[\'"]\s*\)\s*(function\s+(\w+)\s*\((?:[^()]|\((?:[^()]|\([^()]*\))*\))*\)\s*(?::\s*\??[\w\\\\|]+)?)\s*\{/As'
                 : '/@AJAX\(\'([^\']+)\'\)\s*(function\s+(\w+)\([^)]*\))\s*\{/As';
$compTagRe = $v3 ? '/<([A-Z]\w*)\s*\/>|<([A-Z]\w*)\s*>\s*<\/\2\s*>/' : '/<([A-Z]\w*)(?:\/>|><\/\1>)/';

$parsed = [];
foreach ($files as $file) {
    $c = file_get_contents($file);
    $top = topLevelBlocks($c);
    if ($v3) {
        [$script, $sOff]   = $top['script'];
        [$header, $hOff]   = $top['header'];
        [$template, $tOff] = $top['template'];
        [$cscript, $cOff]  = $top['cscript'];
    } else {
        [$script, $sOff] = block($c, '<script setup>', '</script>');
        if (trim($script) === '') [$script, $sOff] = block($c, '<script>', '</script>');
        [$header, $hOff]   = block($c, '<header>', '</header>');
        [$template, $tOff] = block($c, '<template>', '</template>');
        [$cscript, $cOff]  = block($c, '<cscript>', '</cscript>');
    }
    $mScript = maskPhp($script);
    // @AJAX handlers: [name, method, offset in script, header end, body end]
    $handlers = [];
    if (preg_match_all('/@AJAX\s*\(/', $mScript, $aj, PREG_OFFSET_CAPTURE)) {
        foreach ($aj[0] as [, $off]) {
            if (preg_match($ajaxRe, $script, $am, 0, $off)) {
                $handlers[] = ['name' => $am[3], 'method' => strtoupper($am[1]), 'off' => $off, 'hdr' => $am[2], 'bodyStart' => $off + strlen($am[0]), 'bodyEnd' => bodyEnd($mScript, $off + strlen($am[0])), 'ok' => true];
            } else {
                $handlers[] = ['off' => $off, 'ok' => false];
            }
        }
    }
    // top-level script without @AJAX handler bodies
    $mTop = $mScript;
    foreach (array_reverse(array_filter($handlers, fn($h) => $h['ok'])) as $h) $mTop = substr_replace($mTop, str_repeat(' ', $h['bodyEnd'] - $h['off']), $h['off'], $h['bodyEnd'] - $h['off']);
    preg_match_all($requireRe, $script, $rq, PREG_SET_ORDER);
    $required = [];
    foreach ($rq as $r) $required[$r[1]] = $r[2];

    $parsed[$file] = compact('c', 'top', 'script', 'sOff', 'header', 'hOff', 'template', 'tOff', 'cscript', 'cOff', 'mScript', 'mTop', 'handlers', 'required');
    $jsSources .= $cscript;
    if (str_contains($c, 'alpine.ext.')) $extLoaded = true;
    if (preg_match_all($compTagRe, $template, $mm, PREG_SET_ORDER)) {
        foreach ($mm as $g) { $n = $g[1] !== '' ? $g[1] : $g[2]; if (isset($required[$n])) $usage[$n] = ($usage[$n] ?? 0) + 1; }
    }
    if ($v3 && preg_match_all('/<([A-Z]\w*)\s[^>]*\bp-for\s*=/', $template, $mm)) {
        foreach ($mm[1] as $n) if (isset($required[$n])) $usage[$n] = ($usage[$n] ?? 0) + 2;   // a looped component renders many times
    }
}

// App variables (top-level App script + App template PHP): not visible in views or @AJAX handlers
$appVars = [];
if (isset($parsed['App.pvue'])) {
    $appVars = definedVars($parsed['App.pvue']['mTop']);
    foreach (templatePhp($parsed['App.pvue']['template']) as [$code]) $appVars += definedVars(maskPhp($code));
}
$fnDecls = [];   // plain (non-@AJAX) named functions: name => [file, line]
foreach ($parsed as $file => $p) {
    if (preg_match_all('/^\s*function\s+(\w+)\s*\(/m', $p['mTop'], $fm, PREG_OFFSET_CAPTURE)) {
        foreach ($fm[1] as [$n, $o]) $fnDecls[$n] = [$file, lineAt($p['c'], $p['sOff'] + $o)];
    }
}
if (is_dir('backend')) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator('backend', FilesystemIterator::SKIP_DOTS)) as $bf) {
        if (substr((string)$bf, -4) !== '.php') continue;
        $src = file_get_contents((string)$bf);
        if (preg_match_all('/^\s*function\s+(\w+)\s*\(/m', $src, $fm, PREG_OFFSET_CAPTURE)) foreach ($fm[1] as [$n, $o]) $fnDecls[$n] ??= [str_replace('\\', '/', (string)$bf), lineAt($src, $o)];
    }
}
$jsActions = [];   // [file, line, name, kind: 'json'|'query']

// ---------------------------------------------------------------- per-file checks
foreach ($parsed as $file => $p) {
    extract($p);
    $isApp       = $file === 'App.pvue';
    $isView      = str_starts_with($file, 'views/');
    $isComponent = str_starts_with($file, 'components/');
    $tLine0      = $tOff >= 0 ? lineAt($c, $tOff) : 0;
    $tl          = fn(int $off) => $tLine0 + substr_count(substr($template, 0, $off), "\n");

    // ---- block structure
    foreach ($top['stray'] as [$off, $txt, $after]) {
        $snip = mb_strimwidth(preg_replace('/\s+/', ' ', $txt), 0, 60, '…');
        if ($after === 'script') {
            issue('ERROR', $file, lineAt($c, $off), "Text after the PHP block's closing tag is ignored: '$snip'. A literal closing script tag inside a PHP string or comment ends the <script> block early, so the rest of your PHP is lost. Split it in strings ('</scr' . 'ipt>') and reword comments.");
        } else {
            issue('WARN', $file, lineAt($c, $off), "Text outside the <script>/<header>/<template>/<cscript> blocks is ignored: '$snip'.");
        }
    }
    if ($v3 && $top['templateOpen']) {
        issue('ERROR', $file, lineAt($c, $tOff), 'The <template> block never closes: a nested <template> (e.g. <template x-if>) without its </template> makes the PHPue template swallow the rest of the file (cscript included).');
    }
    if (!$v3 && $sOff >= 0) {
        $sEnd = $sOff + strlen($script);
        foreach (['<header>' => $hOff, '<template>' => $tOff, '<cscript>' => $cOff] as $tagName => $bo) {
            if ($bo >= $sOff && $bo < $sEnd) {
                issue('ERROR', $file, lineAt($c, $bo), "A literal $tagName inside the PHP <script> block (comment or string) is taken as the real $tagName block in 0.0.2. Reword it (e.g. 'header block').");
            }
        }
    }
    if ($tOff < 0 && trim($template) === '') {
        issue('INFO', $file, 1, 'No <template> block.');
    }
    if (!$v3) {
        if (preg_match_all('/<\/template>/', $c, $mm, PREG_OFFSET_CAPTURE) && count($mm[0]) > 1) {
            issue('ERROR', $file, lineAt($c, $mm[0][0][1]), 'More than one </template>: in 0.0.2 the PHPue template ends at the FIRST one, so everything after it is lost. Never use <template x-for>/<template x-if> or nested <template> in 0.0.2 (use p-for, x-show, x-ssr).');
        }
        if (preg_match('/<script(?:\s+setup)?>/', $c, $mm, PREG_OFFSET_CAPTURE) && $tOff >= 0 && $mm[0][1] > $tOff && $sOff > $tOff) {
            issue('ERROR', $file, lineAt($c, $mm[0][1]), 'The first bare <script> is inside the template/cscript, so PHPue 0.0.2 compiles that JavaScript as PHP. Add a PHP <script></script> block first, use <cscript>, or give the tag an attribute (type="module").');
        }
    }

    // ---- <script> (PHP)
    $sLine0 = $sOff >= 0 ? lineAt($c, $sOff) : 1;
    $sl = fn(int $off) => $sLine0 + substr_count(substr($script, 0, $off), "\n");
    if (preg_match('/<\?(php|=)/', $mScript, $mm, PREG_OFFSET_CAPTURE)) {
        issue('ERROR', $file, $sl($mm[0][1]), '<?php tags inside the <script> block: the block is already PHP (nested tags cause a parse error).');
    }
    if (preg_match_all('/@require\b/', $mScript, $reqAt, PREG_OFFSET_CAPTURE)) {
        foreach ($reqAt[0] as [, $off]) {
            $line = strtok(substr($script, $off), "\n");
            if (!preg_match($requireRe, $line, $rm)) {
                issue('ERROR', $file, $sl($off), ($v3 ? "Malformed @require (use: @require Name 'components/Name.pvue';): "
                                                       : "Malformed @require (use: @require Name 'components/Name.pvue'; with SINGLE quotes in 0.0.2): ") . trim($line));
                continue;
            }
            if (!is_file($rm[2])) issue('ERROR', $file, $sl($off), "@require path not found (paths are relative to www/): {$rm[2]}");
        }
    }
    preg_match_all('/^[ \t]*#require\b[^\n]*/m', $script, $hr, PREG_OFFSET_CAPTURE);
    foreach ($hr[0] as [$line, $off]) {
        if (!preg_match('/^\s*#require\s+[\'"]([^\'"]+\.pvue)[\'"]\s*;?\s*$/', $line, $vm)) {
            issue('WARN', $file, $sl($off), "#require not recognised (named form is ignored as a PHP comment). Use: #require 'views/name.pvue'; : " . trim($line));
        } elseif (!is_file($vm[1])) {
            issue('ERROR', $file, $sl($off), "#require view not found: {$vm[1]}");
        }
    }
    // @AJAX
    $pageVars = definedVars($mTop);
    foreach (templatePhp($template) as [$code]) $pageVars += definedVars(maskPhp($code));
    foreach ($handlers as $h) {
        $off = $h['off'];
        if (!$h['ok']) {
            issue('ERROR', $file, $sl($off), $v3
                ? "@AJAX not recognised by the compiler (needs @AJAX('POST') or @AJAX(\"GET\") directly followed by function name(...) {). It will be left in PHP as a parse error."
                : "@AJAX not recognised by the 0.0.2 compiler (needs @AJAX('POST') then function name(\$input) { with SINGLE quotes, no return type and no ')' in defaults). It will be left in PHP as a parse error.");
            continue;
        }
        $name = $h['name'];
        if (isset($ajaxNames[$name])) {
            issue('ERROR', $file, $sl($off), "Duplicate @AJAX function '$name' (also in {$ajaxNames[$name][0]}:{$ajaxNames[$name][1]}). AJAX functions are global (Cannot redeclare).");
        } else {
            $ajaxNames[$name] = [$file, $sl($off), $h['method']];
        }
        if ($isComponent && !$v3) {
            issue('WARN', $file, $sl($off), "@AJAX '$name' in a component: in 0.0.2 dev it only works on pages rendering this component, and the build DROPS it. Move it to the hosting view or App.pvue (fixed in 0.0.3).");
        }
        if (!in_array($h['method'], ['POST', 'GET'], true)) {
            if ($v3) issue('WARN', $file, $sl($off), "@AJAX('{$h['method']}') can never be called: only POST (JSON body) and GET (?action=) are dispatched, and 0.0.3 answers any other method with 405.");
            else     issue('INFO', $file, $sl($off), "@AJAX('{$h['method']}'): only POST (JSON body) and GET (?action=) requests are dispatched.");
        }
        $body = substr($mScript, $h['bodyStart'], $h['bodyEnd'] - $h['bodyStart']);
        if (preg_match('/\breturn\s+[^;\s]/', $body) && !preg_match('/\b(echo|print|printf|json_encode|exit|die|header|readfile|var_dump|http_response_code)\b/', $body)) {
            issue('WARN', $file, $sl($off), "@AJAX '$name' returns a value but never echoes: return values are discarded, so the response body is empty.");
        }
        // page/App variables used inside the handler
        $hDefs = definedVars(maskPhp($h['hdr']) . "\n" . $body);
        foreach (usedVars($body) as $var => $o) {
            if (isset($hDefs[$var])) continue;
            $src = isset($pageVars[$var]) ? $file : (isset($appVars[$var]) ? 'App.pvue' : null);
            if ($src === null) continue;
            issue('WARN', $file, $sl($h['bodyStart'] + $o), "@AJAX '$name' uses \$$var, which is set by the $src script: handlers are plain functions that run BEFORE the App/view script, so it is undefined here. Recompute it in the handler or use a backend singleton.");
        }
        if ($v3 && $sessionPolicy === 'lazy' && $h['method'] === 'GET' && preg_match('/\$_SESSION\s*\[[^\]]*\]\s*(?:\[[^\]]*\]\s*)*=(?!=)/', $body) && !preg_match('/phpue_session\s*\(\s*true|session_start\s*\(/', $body)) {
            issue('WARN', $file, $sl($off), "@AJAX('GET') '$name' writes \$_SESSION, but PHPUE_SESSION is 'lazy': GET handlers only get a session if the visitor already has one. Call phpue_session(true) first (POST handlers start one automatically).");
        }
    }
    if ($isComponent) {
        if (preg_match('/^\s*(?:final\s+|abstract\s+)?(function\s+\w+\s*\(|class\s+\w+)/m', $mTop, $fm, PREG_OFFSET_CAPTURE)) {
            issue('WARN', $file, $sl($fm[1][1]), "Named function/class declared in a component: 'Cannot redeclare' if the component renders twice (or inside a p-for). Move it to backend/ or use a closure.");
        }
    }
    if (preg_match('/[?&]page=|\$_GET\[[\'"]page[\'"]\]\s*\)?\s*\?\?\s*[0-9]|\(int\)\s*\(?\$_GET\[[\'"]page/', $c, $pm2, PREG_OFFSET_CAPTURE)) {
        issue('WARN', $file, lineAt($c, $pm2[0][1]), "'page' is reserved: index.php overwrites \$_GET['page'] with the route slug, so ?page=N pagination never arrives. Use ?p= or ?pagination=.");
    }
    if (preg_match('/__DIR__/', $mScript, $dm, PREG_OFFSET_CAPTURE)) {
        issue('WARN', $file, $sl($dm[0][1]), "__DIR__ in .pvue code is www/ in source mode but www/.dist(/pages) once built; use getcwd() for paths.");
    }
    $reserved = $v3 ? 'routing|current_route|b404' : 'file|iterator|backendDir|routing|current_route|b404';
    if (preg_match('/\$(' . $reserved . ')\s*=[^=]/', $mScript, $vm2, PREG_OFFSET_CAPTURE)) {
        issue('WARN', $file, $sl($vm2[0][1]), "Variable \${$vm2[1][0]} is reset by the compiled preamble of every component; pick another name.");
    }
    if ($isApp && is_dir('views') && !$v3) {
        foreach (glob('views/*.pvue') ?: [] as $v) {
            if (!in_array($v, $appRequiredViews, true)) {
                [$vScript] = block(file_get_contents($v), '<script>', '</script>');
                $hasAjax = (bool)preg_match('/@AJAX\(/', maskPhp($vScript));
                issue($hasAjax ? 'ERROR' : 'INFO', $file, 1, "View $v is not #require'd in App.pvue" . ($hasAjax
                    ? ": its @AJAX handlers work in dev but are NOT generated into .dist/ajax/ by the 0.0.2 build."
                    : " (routes anyway; add it so future @AJAX handlers get built)."));
            }
        }
    }
    if ($v3 && $sessionPolicy === 'lazy') {
        $topCode = $mTop;
        foreach (templatePhp($template) as [$code]) $topCode .= "\n" . maskPhp($code);
        if (preg_match('/\$_SESSION\s*\[[^\]]*\]\s*(?:\[[^\]]*\]\s*)*=(?!=)/', $topCode, $sw, PREG_OFFSET_CAPTURE) && !preg_match('/phpue_session\s*\(\s*true|session_start\s*\(/', $topCode)) {
            issue('WARN', $file, $sl(min($sw[0][1], strlen($script))), "Writes \$_SESSION during page rendering while PHPUE_SESSION is 'lazy': first-time visitors have no session, so the write is lost. Call phpue_session(true) before writing (or write from an @AJAX POST handler).");
        }
        if ($isApp && str_contains($script, 'session_start()')) {
            issue('INFO', $file, $sLine0, "PHPUE_SESSION is 'lazy', but the App script contains the text session_start(), which switches PHPue's session policy off entirely (textual check, comments included).");
        }
    }

    // ---- <header>
    if (!$v3 && trim($header) !== '' && $tOff >= 0 && $hOff > $tOff) {
        $header = ''; // the "header block" is really a bare <header> element inside the template (reported below)
    }
    if (!$v3 && preg_match_all('/<!--(.*?)-->/s', $header, $hc, PREG_OFFSET_CAPTURE)) {
        foreach ($hc[1] as [$txt, $o]) if (preg_match('/<\/?(title|header|template|script|cscript)>/', $txt, $tn)) {
            issue('ERROR', $file, lineAt($c, $hOff + $o), "Comment in <header> contains a literal {$tn[0]}: the 0.0.2 regex compiler still matches it (e.g. becomes the page title). Reword the comment.");
        }
    }
    $header = preg_replace('/<!--.*?-->/s', '', $header);
    if (trim($header) !== '') {
        $hLine = lineAt($c, $hOff);
        if ($isComponent) {
            issue('INFO', $file, $hLine, 'Component <header> is ignored. Put CSS in a template <style> or the view/App header.');
        } elseif ($isView) {
            if (preg_match('/\{\{\s*\$|<\?(php|=)/', $header)) {
                issue('WARN', $file, $hLine, 'View <header> is static: {{ $var }} prints the literal "$var" and PHP is not executed. Use App-level dynamic meta (MetaControl pattern).');
            }
        } elseif ($isApp) {
            preg_match_all('/\{\{\s*(.*?)\s*\}\}/', $header, $hm);
            $okHeaderExpr = $v3 ? '/^\$[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*(?:\[(?:\'[^\']*\'|"[^"]*"|\d+|\$\w+)\]|->\w+)*$/'
                                : '/^\$[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*$/';
            foreach ($hm[1] as $expr) {
                if (!preg_match($okHeaderExpr, $expr)) {
                    issue('ERROR', $file, $hLine, $v3
                        ? "App <header> converts {{ \$var }}, {{ \$arr['k'] }} and {{ \$obj->prop }} only; '{{ $expr }}' is left as-is. Build the string in <script> and print {{ \$thatVar }}."
                        : "App <header> only converts {{ \$simpleVariable }} in 0.0.2; '{{ $expr }}' is left as-is. Use native heredoc syntax ({\$meta['title']}, {\$metas[0]}) or build the string in <script>.");
                }
            }
            $noMustache = preg_replace(['/\{\{.*?\}\}/', '/\{\$[^}]*\}/'], '', $header);   // {{ $x }} and heredoc {$x[..]} are intended
            if (preg_match('/\$[A-Za-z_(]/', $noMustache)) {
                issue('WARN', $file, $hLine, 'App <header> is a PHP heredoc: bare $… (e.g. jQuery $( or JS variables) will be interpolated. Move inline JS to a file or <cscript>.');
            }
            if (preg_match('/<\?(php|=)/', $header)) {
                issue('ERROR', $file, $hLine, 'PHP tags in the App <header> are printed literally (it is a heredoc string). Use {{ $var }} or {$var[...]}.');
            }
        }
        if (preg_match_all('/<script\b[^>]*>/', $header, $sm)) {
            foreach ($sm[0] as $tag) {
                if (str_contains($tag, 'alpine.ext.') && preg_match('/\b(defer|async)\b/', $tag)) {
                    issue('ERROR', $file, $hLine, 'x-ssr extension must NOT be defer/async (it has to register before Alpine starts): ' . $tag);
                }
                if (preg_match('/alpine(js)?[^"\']*\.min\.js|cdn\.min\.js/i', $tag) && !str_contains($tag, 'alpine.ext.') && !preg_match('/\bdefer\b/', $tag)) {
                    issue('WARN', $file, $hLine, 'Load Alpine with defer so the x-ssr extension and Alpine.data() registrations run first: ' . $tag);
                }
            }
        }
    }

    if ($tOff < 0) goto cscript_checks;

    // ---- <template>
    if (!$v3 && preg_match_all('/<header>/', $template, $hm2, PREG_OFFSET_CAPTURE)) {
        foreach ($hm2[0] as [, $off]) issue('ERROR', $file, $tl($off), 'Bare <header> element inside <template>: the 0.0.2 compiler strips it (with all its content). Add an attribute: <header class="…">.');
    }
    if (preg_match_all('/<View\s*\/?>/', $template, $vm3, PREG_OFFSET_CAPTURE) && !$isApp) {
        issue('ERROR', $file, $tl($vm3[0][0][1]), '<View/> only works in App.pvue.');
    }
    // {{ }}
    if (preg_match_all('/\{\{\s*(.*?)\s*\}\}/', $template, $mu, PREG_OFFSET_CAPTURE)) {
        foreach ($mu[1] as $k => [$expr, $off]) {
            if ($expr === '' || str_contains($expr, '<') || str_contains($expr, '?>')) continue; // intentional display of PHPue syntax
            $isVar  = $expr[0] === '$';
            $isCall = (bool)preg_match('/^\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*\s*(\(|::)/', $expr);
            if ($v3 && !$isVar && !$isCall) {
                if (str_contains($expr, '$')) issue('WARN', $file, $tl($off), "{{ $expr }} is not converted (0.0.3 converts expressions that start with \$ or a function/static call) and will show literally. Start it with \$ or use <?= … ?>.");
                continue;   // {{ item.name }} etc. is left for Alpine/docs on purpose
            }
            if (!$v3 && !$isVar) {
                issue('WARN', $file, $tl($off), "{{ $expr }} is not converted in 0.0.2 (must start with \$) and will show literally. Use <?= … ?> for function calls.");
            } elseif (preg_match('/=["\']/', $expr) && preg_match('/[\'"][\w:-]+=/', $expr)) {
                issue('WARN', $file, $tl($off), "{{ $expr }} looks like it outputs a whole attribute; {{ }} escapes the quotes (aria-current=&quot;true&quot;). Output a value inside a quoted attribute, or use <?= ?>.");
            } elseif (preg_match('/^\$\w+\.\w/', $expr)) {
                issue('ERROR', $file, $tl($off), "{{ $expr }}: dot notation is PHP string concatenation. Use \$x['key'] or \$x->prop.");
            }
        }
    }
    if (preg_match_all('/\sp-model="/', $template, $pmm, PREG_OFFSET_CAPTURE)) {
        foreach ($pmm[0] as [, $off]) issue('WARN', $file, $tl($off), 'p-model is an abandoned placeholder (privacy-first forms): write name="field" value="{{ $field }}" and submit explicitly.');
    }
    // components usage
    if (preg_match_all('/<([A-Z]\w*)\b([^>]*)>/', $template, $cu, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
        foreach ($cu as $m) {
            $name = $m[1][0]; $rest = $m[2][0]; $off = $m[0][1];
            $after = substr($template, $off + strlen($m[0][0]));
            if ($v3) {
                // 0.0.3 strips p-if / p-for first, then replaces <Name/>, <Name />, <Name></Name>
                $rest  = preg_replace('/\s+p-(?:if|for)\s*=\s*(?:"[^"]*"|\'[^\']*\')/', '', $rest);
                $exact = (bool)preg_match('/^\s*\/$/', $rest) || (trim($rest) === '' && preg_match('/^\s*<\/' . $name . '\s*>/', $after));
                $forms = "<$name/>, <$name /> or <$name></$name> (p-if/p-for allowed; no other attributes or children)";
            } else {
                $exact = ($rest === '/') || ($rest === '' && str_starts_with($after, "</$name>"));
                $forms = "exactly <$name/> or <$name></$name> (no attributes/spaces/children)";
            }
            if ($name === 'View') { if (!$exact) issue('ERROR', $file, $tl($off), 'Write <View/> or <View></View> exactly.'); continue; }
            if (isset($required[$name])) {
                if (!$exact) issue('ERROR', $file, $tl($off), "Component tag {$m[0][0]} will NOT be replaced: use $forms. Pass data as variables set before the tag.");
            } elseif ($exact) {
                issue('WARN', $file, $tl($off), "<$name/> is used but not @require'd in this file. Nothing will render.");
            }
        }
    }
    if ($v3) {
        // p-for syntax (unclosed p-if/p-for elements are reported from the compiler's own log below)
        // walk real opening tags like the compiler (escaped &lt;li p-for=…&gt; documentation text is not a tag)
        foreach (openTags(maskTemplate($template), $template) as [$tagOff, $openTag]) {
            if (!preg_match_all('/\sp-for\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/', $openTag, $pa, PREG_SET_ORDER)) continue;
            foreach ($pa as $m) {
                $val = ($m[1] ?? '') !== '' ? $m[1] : ($m[2] ?? '');
                if (!preg_match('/^\s*(.+?)\s+in\s+(.+?)\s*$/s', $val, $lp)) {
                    issue('ERROR', $file, $tl($tagOff), "p-for=\"$val\" is not converted (needs \"\$item in <expression>\" or \"\$k => \$v in <expression>\").");
                } elseif (!preg_match('/^(?:&?\$\w+|\$\w+\s*=>\s*&?\$\w+|\[.*\]|list\s*\(.*\))$/s', trim($lp[1]))) {
                    issue('ERROR', $file, $tl($tagOff), "p-for=\"$val\": '{$lp[1]}' is not a loop variable (foreach ... as {$lp[1]} is a parse error). Use \$item or \$key => \$item.");
                }
            }
        }
    } else {
        // p-for (0.0.2 regex)
        $pforRe = '/<(\w+)([^>]*)p-for="(\$[^"]+) in (\$[^"]+)"([^>]*)>([\s\S]*?)<\/\1>/';
        preg_match_all($pforRe, $template, $pf, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        $covered = [];
        foreach ($pf as $m) {
            $tag = $m[1][0]; $off = $m[0][1]; $covered[] = [$off, $off + strlen($m[0][0])];
            $openTag = substr($m[0][0], 0, strpos($m[0][0], '>', strpos($m[0][0], 'p-for=')) + 1);
            if (preg_match('/<' . $tag . '\b/', $m[6][0])) {
                issue('ERROR', $file, $tl($off), "p-for on <$tag> contains another <$tag>: in 0.0.2 the loop body ends at the first </$tag>, so each iteration leaves an unclosed <$tag> that swallows following markup. Put p-for on the keyed element itself, use a different outer tag, or <?php foreach ?>.");
            }
            if (str_contains($m[4][0], '(')) {
                issue('ERROR', $file, $tl($off), "p-for array '{$m[4][0]}' is a call/expression; 0.0.2 wraps it in isset() (fatal). Assign it to a variable first.");
            }
            if (str_contains($openTag, 'p-if=')) {
                issue('ERROR', $file, $tl($off), 'p-for and p-if on the same element break each other in 0.0.2. Filter in <script> or nest elements.');
            }
        }
        if (preg_match_all('/<\w+[^>]*\sp-for="([^"]*)"/', $template, $pa, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($pa as $m) {
                $off = $m[0][1]; $ok = false;
                foreach ($covered as [$a, $b]) if ($off >= $a && $off < $b) { $ok = true; break; }
                if (!$ok) issue('ERROR', $file, $tl($off), "p-for=\"{$m[1][0]}\" is not converted by 0.0.2 (needs \"\$item in \$array\" with a matching closing tag).");
            }
        }
        // p-if: replicate 0.0.2 convertPIfWithStack to find where endif lands, and compare to the real close
        $lines = explode("\n", $template);
        $stack = [];
        foreach ($lines as $idx => $line) {
            $abs = $tLine0 + $idx;
            if (preg_match('/<(\w+)([^>]*)\s+p-if="([^"]*)"([^>]*)>/', $line, $m, PREG_OFFSET_CAPTURE)) {
                $tag = $m[1][0];
                $matchEnd = $m[0][1] + strlen($m[0][0]);
                $after = trim(substr($line, $matchEnd));
                $before = trim(substr($line, 0, $m[0][1]));
                if ($after !== '') issue('ERROR', $file, $abs, "Text after the p-if opening tag on the same line is DELETED by 0.0.2 and the if never closes: '" . mb_strimwidth($after, 0, 60, '…') . "'. Put the content on the next lines.");
                if ($before !== '') issue('ERROR', $file, $abs, "Text before the p-if tag on the same line is DELETED by 0.0.2: '" . mb_strimwidth($before, 0, 60, '…') . "'.");
                if (preg_match('/\{\{|<\?/', $line)) issue('ERROR', $file, $abs, "{{ }} or <?= ?> on the p-if line: 0.0.2 converts p-if AFTER {{ }}, so the generated ?> cuts the tag and the closing quote/> are dropped (the attribute swallows following markup). Move dynamic attributes to a child element.");
                if (substr_count($m[0][0], '"') % 2 === 1) issue('ERROR', $file, $abs, "An attribute on this p-if element contains '>' (e.g. x-show=\"a > b\" or =>); 0.0.2 cuts the tag there. Move that logic into an Alpine method/getter.");
                // real close: tag balance from the opening tag onwards
                $real = null; $bal = 0;
                $scan = substr($line, $m[0][1]);
                for ($j = $idx; $j < count($lines); $j++) {
                    $chunk = $j === $idx ? $scan : $lines[$j];
                    $bal += preg_match_all('/<' . $tag . '\b(?![^>]*\/>)/', $chunk) - preg_match_all('/<\/' . $tag . '>/', $chunk);
                    if ($bal <= 0) { $real = $tLine0 + $j; break; }
                }
                $stack[] = ['tag' => $tag, 'depth' => 1, 'start' => $abs, 'real' => $real];
                continue;
            }
            if (preg_match('/^(.*?)p-if="/', $line, $pm) && !str_contains($pm[1], '&lt;') && !str_contains($pm[1], '<?')) {
                $prev = implode("\n", array_slice($lines, 0, $idx)) . "\n" . $pm[1];
                if (strrpos($prev, '<') !== false && strrpos($prev, '<') > (int)strrpos($prev, '>')) {
                    issue('ERROR', $file, $abs, 'p-if is on a continuation line of a multi-line opening tag: 0.0.2 does NOT convert it (element always renders). Keep the p-if opening tag on one line.');
                }
            }
            if ($stack) {
                $cur = &$stack[count($stack) - 1];
                $t = $cur['tag'];
                if (preg_match("/<" . $t . "[^>]*>/", $line) && !preg_match("/<\/" . $t . ">/", $line)) $cur['depth']++;
                if (preg_match("/<\/" . $t . ">/", $line)) {
                    $cur['depth']--;
                    if ($cur['depth'] === 0) {
                        if ($cur['real'] !== null && $cur['real'] !== $abs) {
                            issue('ERROR', $file, $cur['start'], "p-if on <{$t}> is closed by the 0.0.2 compiler at line $abs but the element really ends at line {$cur['real']} (a same-tag child opened and closed on one line, or several tags per line). Use a different wrapper tag, one tag per line, or <?php if: ?>.");
                        }
                        array_pop($stack);
                    }
                }
                unset($cur);
            }
        }
        foreach ($stack as $open) {
            issue('ERROR', $file, $open['start'], "p-if on <{$open['tag']}> never gets its <?php endif; ?> in 0.0.2 (PHP parse error: unexpected end of file).");
        }
    }
    // x-ssr
    if (preg_match_all('/<(\w+)\b[^>]*\sx-ssr="/', $template, $xs, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
        $xssrUsed = true;
        foreach ($xs as $m) {
            $tag = $m[1][0]; $off = $m[0][1];
            $tagEnd = strpos($template, '>', $off);
            // the x-ssr expression may contain '>' (arrow functions): find the real end of the opening tag
            $q = false;
            for ($k = $off; $k < strlen($template); $k++) { if ($template[$k] === '"') $q = !$q; elseif ($template[$k] === '>' && !$q) { $tagEnd = $k; break; } }
            $open = substr($template, $off, $tagEnd - $off + 1);
            $key  = preg_match('/x-ssr-key="([^"]+)"/', $open, $km) ? $km[1] : 'id';
            $tpl  = preg_match('/x-ssr-template="([^"]+)"/', $open, $tm) ? $tm[1] : 'csrTemplate';
            $bal = 0; $inner = '';
            if (preg_match_all('/<\/?' . $tag . '\b[^>]*>/', $template, $tt, PREG_OFFSET_CAPTURE, $off)) {
                foreach ($tt[0] as [$t, $o]) {
                    if (str_ends_with($t, '/>')) continue;
                    $bal += ($t[1] === '/') ? -1 : 1;
                    if ($bal === 0) { $inner = substr($template, $tagEnd + 1, $o - $tagEnd - 1); break; }
                }
            }
            $innerFull = $inner;
            if (preg_match_all('/<([A-Z]\w*)[\s\/>]/', $inner, $ic)) {
                foreach ($ic[1] as $cn) if (isset($required[$cn]) && is_file($required[$cn])) $innerFull .= file_get_contents($required[$cn]);
            }
            if (preg_match('/p-for=|foreach|<\w+/', $inner) && !preg_match('/data-' . preg_quote($key, '/') . '=/', $innerFull)) {
                issue('WARN', $file, $tl($off), "x-ssr (key '$key') has server-rendered children but none carry data-$key=\"…\": they will be removed and re-created client-side.");
            }
            if (!preg_match('/^[\'"`]/', $tpl) && !preg_match('/\b' . preg_quote($tpl, '/') . '\s*:/', $jsSources)) {   // inline string templates are valid
                issue('WARN', $file, $tl($off), "x-ssr template '$tpl' not found as a property in any <cscript> or assets/js file.");
            }
        }
    }
    if (!$v3 && preg_match('/<template\s+x-(for|if)/', $c, $mm, PREG_OFFSET_CAPTURE)) {
        issue('ERROR', $file, lineAt($c, $mm[0][1]), 'Alpine <template x-' . $mm[1][0] . '> cannot be used in a 0.0.2 .pvue (it terminates the PHPue template). Use p-for / x-show / x-ssr, or upgrade to 0.0.3.');
    }

    cscript_checks:
    if (trim($cscript) !== '') {
        $cLine0 = lineAt($c, $cOff);
        $cl = fn(int $off) => $cLine0 + substr_count(substr($cscript, 0, $off), "\n");
        if (preg_match_all('/\{\{\s*(.*?)\s*\}\}/', $cscript, $cm, PREG_OFFSET_CAPTURE)) {
            foreach ($cm[1] as [$expr, $off]) {
                if ($v3) {
                    if ($expr !== '' && $expr[0] !== '$' && preg_match('/^\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*\s*(\(|::)/', $expr)) {
                        issue('WARN', $file, $cl($off), "<cscript> {{ $expr }} is not converted (cscript converts {{ \$expression }} only). Use <?= json_encode($expr) ?>.");
                    }
                } elseif ($expr !== '' && $expr[0] === '$' && !preg_match('/^\$[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*$/', $expr)) {
                    issue('ERROR', $file, $cl($off), "<cscript> {{ $expr }} is not converted by 0.0.2 (only {{ \$simpleVar }}). Use <?= json_encode(…) ?>.");
                }
            }
        }
        $quotedRe = $v3 ? '/([\'"`])\s*\{\{\s*\$[^}]*?\s*\}\}\s*\1/' : '/([\'"])\s*\{\{\s*\$\w+\s*\}\}\s*\1/';
        if (preg_match_all($quotedRe, $cscript, $qm, PREG_OFFSET_CAPTURE)) {
            foreach ($qm[0] as [, $off]) issue('ERROR', $file, $cl($off), '<cscript> {{ $… }} is already json_encoded (strings include their quotes); remove the surrounding quotes.');
        }
        if (preg_match_all('/html\s*:\s*`([\s\S]*?)`/', $cscript, $hx, PREG_OFFSET_CAPTURE)) {
            foreach ($hx[1] as [$tplStr, $off]) {
                if (preg_match('/(?<!\\\\)\$\{item\./', $tplStr)) {
                    issue('ERROR', $file, $cl($off), 'x-ssr html template literal uses ${item.…} without a backslash: JS evaluates it immediately. Write \${item.prop}.');
                }
            }
        }
        if ($isComponent && preg_match('/^\s{0,4}(let|const)\s+\w+/m', $cscript, $lm, PREG_OFFSET_CAPTURE)) {
            $name = null;
            foreach ($parsed as $pp) foreach ($pp['required'] as $rn => $rpath) if ($rpath === $file) $name = $rn;
            if ($name && ($usage[$name] ?? 0) > 1) {
                issue('WARN', $file, $cl($lm[1][1]), "Top-level let/const in a component that renders more than once (several placeholders or a p-for): if two copies land on one page the JS throws 'already declared'. Wrap in an IIFE.");
            }
        }
    }

    // ---- browser-side action calls (checked against @AJAX handlers after the loop)
    if ($v3) {
        foreach (['template' => [$template, $tOff], 'cscript' => [$cscript, $cOff], 'script' => [$script, $sOff]] as $blk => [$src, $bo]) {
            if ($bo < 0) continue;
            $collect = function (string $re, string $kind) use ($src, $bo, $c, $file, &$jsActions) {
                if (preg_match_all($re, $src, $am, PREG_OFFSET_CAPTURE)) foreach ($am[1] as [$n, $o]) $jsActions[] = [$file, lineAt($c, $bo + $o), $n, $kind, $src, $o];
            };
            $collect('/(?<![\w$.])action\s*:\s*[\'"`](\w+)[\'"`]/', 'json');
            $collect('/[\'"]action[\'"]\s*(?::|=>)\s*[\'"](\w+)[\'"]/', 'json');
            $collect('/[?&]action=(\w+)/', 'query');
        }
    }
}
if ($v3) {
    foreach ($assetJs as $js => $src) {
        foreach (['/(?<![\w$.])action\s*:\s*[\'"`](\w+)[\'"`]/' => 'json', '/[\'"]action[\'"]\s*:\s*[\'"](\w+)[\'"]/' => 'json', '/[?&]action=(\w+)/' => 'query'] as $re => $kind) {
            if (preg_match_all($re, $src, $am, PREG_OFFSET_CAPTURE)) foreach ($am[1] as [$n, $o]) $jsActions[] = [$js, lineAt($src, $o), $n, $kind, $src, $o];
        }
    }
    $seen = [];
    foreach ($jsActions as [$f, $ln, $name, $kind, $src, $o]) {
        if (isset($seen["$f:$ln:$name"])) continue;
        $seen["$f:$ln:$name"] = true;
        if (!isset($ajaxNames[$name])) {
            if (isset($fnDecls[$name])) {
                issue('WARN', $f, $ln, "action '$name' names a plain function ({$fnDecls[$name][0]}:{$fnDecls[$name][1]}), not an @AJAX handler. 0.0.3 only dispatches @AJAX functions, so this request falls through and gets the full page HTML (200). Add @AJAX('POST') above it.");
            }
            continue;
        }
        $declared = $ajaxNames[$name][2];
        $isPostBody = $kind === 'json' && preg_match('/JSON\.stringify|json-enc|hx-post|method\s*:\s*[\'"]POST/i', substr($src, max(0, $o - 300), 300));
        if ($kind === 'query' && $declared === 'POST') {
            issue('WARN', $f, $ln, "?action=$name sends a GET, but '$name' is declared @AJAX('POST') ({$ajaxNames[$name][0]}:{$ajaxNames[$name][1]}): 0.0.3 answers 405. POST a JSON body or declare @AJAX('GET').");
        } elseif ($isPostBody && $declared === 'GET') {
            issue('WARN', $f, $ln, "action '$name' is POSTed as JSON, but it is declared @AJAX('GET') ({$ajaxNames[$name][0]}:{$ajaxNames[$name][1]}): 0.0.3 answers 405. Declare @AJAX('POST') or call ?action=$name with GET.");
        }
    }
}

// ---- App variables used in views (App and views run in separate scopes)
if ($appVars) {
    $requiredBy = fn(string $f) => array_values(array_filter($parsed[$f]['required'] ?? [], fn($p) => isset($parsed[$p])));
    foreach ($parsed as $view => $p) {
        if (!str_starts_with($view, 'views/')) continue;
        $scope = [$view]; $queue = [$view];
        while ($queue) foreach ($requiredBy(array_shift($queue)) as $cmp) if (!in_array($cmp, $scope, true)) { $scope[] = $cmp; $queue[] = $cmp; }
        $defs = []; $uses = [];
        foreach ($scope as $f) {
            $q = $parsed[$f];
            if (str_contains($q['mScript'], 'extract(') || str_contains($q['mScript'], '$$')) continue 2;
            $defs += definedVars($q['mScript']);
            foreach (usedVars($q['mTop']) as $n => $o) $uses[] = [$f, lineAt($q['c'], $q['sOff'] + $o), $n];
            foreach (templatePhp($q['template']) as [$code, $o]) {
                $mc = maskPhp($code);
                $defs += definedVars($mc);
                if (preg_match('/^\s*(.+?)\s+in\s/s', $code, $lv)) $defs += definedVars($lv[1] . ' = 1;');   // p-for loop variables
                foreach (usedVars($mc) as $n => $o2) $uses[] = [$f, lineAt($q['c'], $q['tOff'] + $o + $o2), $n];
            }
            if (preg_match_all('/\{\{\s*(\$.+?)\s*\}\}/', $q['cscript'], $cm, PREG_OFFSET_CAPTURE)) {
                foreach ($cm[1] as [$code, $o]) foreach (usedVars($code) as $n => $o2) $uses[] = [$f, lineAt($q['c'], $q['cOff'] + $o + $o2), $n];
            }
        }
        $reported = [];
        foreach ($uses as [$f, $ln, $n]) {
            if (!isset($appVars[$n]) || isset($defs[$n]) || isset($reported["$f:$n"])) continue;
            $reported["$f:$n"] = true;
            issue('WARN', $f, $ln, "\$$n is set in App.pvue, but App variables are not visible in views" . ($f === $view ? '' : " (this component renders inside $view)") . ": views run in a separate scope. Share it with define(), \$GLOBALS, \$_SESSION or a backend singleton.");
        }
    }
}

if ($xssrUsed && !$extLoaded) {
    issue('ERROR', 'App.pvue', 1, 'x-ssr is used but no alpine.ext.*.ssr.js script is loaded in any header.');
}

// ---------------------------------------------------------------- compile + php -l
if (!$noCompile && is_file('conversion.php')) {
    $_SERVER['REQUEST_URI'] = '/'; $_SERVER['SERVER_NAME'] = 'localhost'; $_SERVER['SERVER_ADDR'] = '127.0.0.1';
    if (!defined('PHPUE_VERSION')) define('PHPUE_VERSION', $version);
    if (!defined('PHPUE_LANG')) define('PHPUE_LANG', 'en');
    $tmpDir = sys_get_temp_dir() . '/phpue-lint-' . bin2hex(random_bytes(4));
    mkdir($tmpDir);
    $logFile = "$tmpDir/compiler.log";
    $oldLog = ini_set('error_log', $logFile);
    ob_start();
    require_once 'conversion.php';
    ob_end_clean();
    // components first, so a component's compiler messages are attributed to it (not to every page that inlines it)
    $order = $files;
    usort($order, fn($a, $b) => [!str_starts_with($a, 'components/'), $a] <=> [!str_starts_with($b, 'components/'), $b]);
    $seenLog = [];
    foreach ($order as $file) {
        @file_put_contents($logFile, '');
        try {
            ob_start();
            $php = convert_pvue_file($file, $file === 'App.pvue');
            ob_end_clean();
        } catch (Throwable $e) {
            ob_end_clean();
            issue('ERROR', $file, 1, 'Compilation threw: ' . $e->getMessage());
            continue;
        }
        // 0.0.3 logs unclosed p-if/p-for elements and invalid p-for values
        foreach (is_file($logFile) ? file($logFile, FILE_IGNORE_NEW_LINES) : [] as $logLine) {
            if (!preg_match('/PHPue: (.*)$/', $logLine, $lm)) continue;
            $msg = $lm[1];
            if (str_starts_with($msg, 'invalid p-for')) continue;   // reported by the static p-for check
            if (isset($seenLog[$msg])) continue;
            $seenLog[$msg] = true;
            $line = 1;
            if (preg_match('/(p-(?:if|for))="(.*?)"/', $msg, $dm)) {
                $src = file_get_contents($file);
                foreach (["{$dm[1]}=\"{$dm[2]}\"", "{$dm[1]}='{$dm[2]}'"] as $needle) if (($at = strpos($src, $needle)) !== false) { $line = lineAt($src, $at); break; }
            }
            $hint = str_contains($msg, 'no closing') ? ' The element content then always renders: close the element.' : '';
            issue('ERROR', $file, $line, "Compiler: $msg$hint");
        }
        $tmp = $tmpDir . '/' . str_replace('/', '__', $file) . '.php';
        file_put_contents($tmp, $php);
        $out = [];
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($tmp) . ' 2>&1', $out, $code);
        if ($code !== 0) {
            $msg = trim(preg_replace('/ in \S+ on line (\d+)/', ' (compiled line $1)', implode(' ', array_filter($out, fn($l) => !str_starts_with($l, 'Errors parsing')))));
            if (preg_match('/compiled line (\d+)/', $msg, $ln)) {
                $src = explode("\n", $php)[(int)$ln[1] - 1] ?? '';
                if (trim($src) !== '') $msg .= ' → `' . mb_strimwidth(trim($src), 0, 90, '…') . '`';
            }
            issue('ERROR', $file, 1, "Compiled PHP does not parse: $msg");
        }
        unlink($tmp);
    }
    ini_set('error_log', $oldLog === false ? '' : $oldLog);
    @unlink($logFile);
    @rmdir($tmpDir);
}

// ---------------------------------------------------------------- report
echo "PHPue $version project ($verFrom): " . ($v3 ? '0.0.3' : '0.0.2') . " rules.\n";
$order = ['ERROR' => 0, 'WARN' => 1, 'INFO' => 2];
usort($issues, fn($a, $b) => [$order[$a[0]], $a[1], $a[2]] <=> [$order[$b[0]], $b[1], $b[2]]);
$counts = ['ERROR' => 0, 'WARN' => 0, 'INFO' => 0];
foreach ($issues as [$lvl, $f, $l, $msg]) {
    $counts[$lvl]++;
    if ($errOnly && $lvl !== 'ERROR') continue;
    printf("%-5s %s:%d  %s\n", $lvl, $f, $l, $msg);
}
printf("\nPHPue lint: %d file(s), %d error(s), %d warning(s), %d info.\n", count($files), $counts['ERROR'], $counts['WARN'], $counts['INFO']);
exit($counts['ERROR'] > 0 ? 1 : 0);
