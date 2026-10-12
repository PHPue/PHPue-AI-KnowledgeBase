<?php
    /*
        PHPuePrivate: logs and cache files that must never be public, safe on shared hosting.

        Files go to a private folder OUTSIDE the web root:
            PHPUE_PRIVATE_DIR (env), or "phpue-private/" next to www/ (e.g. /home/<account>/phpue-private)
        Folders are created 0700 and files 0600 (owner only). A folder inside DOCUMENT_ROOT is refused.
        Nothing is ever written to the shared /tmp, where other hosting accounts could read or plant files.
        If no private folder is writable (e.g. Docker), log() falls back to PHP's error_log (stderr / the
        account's own error log) and cache users must build fresh files instead.

        Never log prompts, answers, keys or other personal data. Set PHPUE_DEBUG=1 (development only) to
        allow short debug snippets.
    */
    if (!class_exists('PHPuePrivate')) {
        class PHPuePrivate {

            /** Absolute path of a private sub-folder (created on demand), or null if none is usable. */
            public static function dir(string $sub): ?string {
                $base = getenv('PHPUE_PRIVATE_DIR') ?: dirname((string) getcwd()) . '/phpue-private';
                $dir = rtrim($base, '/') . '/' . trim($sub, '/');
                if (!is_dir($dir) && !@mkdir($dir, 0700, true)) return null;
                $real = realpath($dir);
                if ($real === false || !is_writable($real)) return null;

                $docRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: null;
                if ($docRoot !== null && str_starts_with($real . '/', rtrim($docRoot, '/') . '/')) return null;   // never public
                return $real;
            }

            public static function debug(): bool {
                return getenv('PHPUE_DEBUG') === '1';
            }

            /** One line per event: "<time> [channel] message {context}". Context is JSON (newlines escaped). */
            public static function log(string $channel, string $message, array $context = []): void {
                $line = date('c') . ' [' . $channel . '] ' . str_replace(["\r", "\n"], ' ', mb_substr($message, 0, 500))
                      . ($context ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) : '');

                $dir = self::dir('logs');
                if ($dir === null) {
                    error_log('[PHPue] ' . $line);
                    return;
                }
                $file = $dir . '/' . preg_replace('/[^a-z0-9_-]/i', '', $channel) . '-' . date('Y-m') . '.log';
                $isNew = !is_file($file);
                if (@file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX) !== false && $isNew) {
                    @chmod($file, 0600);
                }
            }
        }
    }
?>
