<?php
/**
 * phpue-compile.php: print the PHP that PHPue's conversion.php generates for a .pvue file.
 *
 * Usage (from anywhere):
 *   php phpue-compile.php path/to/www/views/page.pvue
 *   php phpue-compile.php path/to/www/App.pvue --root        # compile as the root App
 *   php phpue-compile.php path/to/www/views/page.pvue --lint # also run `php -l` on the result
 *
 * The script locates the PHPue web root (the directory holding conversion.php) by walking up
 * from the file, chdir()s into it (component paths are relative to it) and calls
 * convert_pvue_file(). It never writes into the project.
 */

$args  = array_slice($argv, 1);
$bRoot = in_array('--root', $args, true);
$bLint = in_array('--lint', $args, true);
$files = array_values(array_filter($args, fn($a) => substr($a, 0, 2) !== '--'));

if (count($files) !== 1) {
    fwrite(STDERR, "Usage: php phpue-compile.php <file.pvue> [--root] [--lint]\n");
    exit(2);
}

$target = realpath($files[0]);
if ($target === false || !is_file($target)) {
    fwrite(STDERR, "File not found: {$files[0]}\n");
    exit(2);
}

$dir = dirname($target);
$www = null;
while ($dir !== dirname($dir)) {
    if (is_file($dir . '/conversion.php')) { $www = $dir; break; }
    $dir = dirname($dir);
}
if ($www === null) {
    fwrite(STDERR, "Could not find conversion.php above $target\n");
    exit(2);
}

chdir($www);
$relative = ltrim(substr($target, strlen($www)), '/');
if (basename($relative) === 'App.pvue') $bRoot = true;

$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';
$_SERVER['SERVER_NAME'] = $_SERVER['SERVER_NAME'] ?? 'localhost';
$_SERVER['SERVER_ADDR'] = $_SERVER['SERVER_ADDR'] ?? '127.0.0.1';
// Same constants index.php defines (PHPUE_VERSION is read from the project's own index.php)
$indexSrc = is_file($www . '/index.php') ? file_get_contents($www . '/index.php') : '';
if (!defined('PHPUE_VERSION')) define('PHPUE_VERSION', preg_match('/define\(\s*[\'"]PHPUE_VERSION[\'"]\s*,\s*[\'"]([0-9][0-9.]*)[\'"]/', $indexSrc, $vm) ? $vm[1] : '0.0.2');
if (!defined('PHPUE_LANG')) define('PHPUE_LANG', 'en');

require_once $www . '/conversion.php';

try {
    $php = convert_pvue_file($relative, $bRoot);
} catch (Throwable $e) {
    fwrite(STDERR, "Compilation error: " . $e->getMessage() . "\n");
    exit(1);
}

echo $php;

if ($bLint) {
    $tmp = sys_get_temp_dir() . '/phpue-' . bin2hex(random_bytes(6)) . '.php';
    file_put_contents($tmp, $php);
    $out = [];
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($tmp) . ' 2>&1', $out, $code);
    unlink($tmp);
    fwrite(STDERR, "\n--- php -l: " . ($code === 0 ? "OK" : implode("\n", $out)) . "\n");
    exit($code === 0 ? 0 : 1);
}
