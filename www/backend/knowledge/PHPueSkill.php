<?php
    /*
        PHPueSkill: the downloadable Agent Skill for Claude Code and Codex.

        skill/ is the author's own PHPue skill (SKILL.md, references/, scripts/, templates/, agents/, assets/),
        copied in by `php tools/sync-skill.php`, which only rewrites lines that point at the author's machine.
        It is served exactly as it is: /skills/phpue.zip (whole skill) and /skills/phpue/<file>.md|.yaml.
    */
    class PHPueSkill {
        const NAME = 'phpue';

        /** Bundle path (relative to the skill folder) => ['path' => file on disk]. */
        public static function files(): array {
            $dir = __DIR__ . '/skill';
            $files = [];
            if (!is_dir($dir)) return $files;
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                $files[str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1))] = ['path' => $file->getPathname()];
            }
            ksort($files);
            return $files;
        }

        /** One bundle file's contents, or null. */
        public static function file(string $relative): ?string {
            $files = self::files();
            if (!isset($files[$relative])) return null;
            return (string) file_get_contents($files[$relative]['path']);
        }

        /**
         * Build the zip of the bundle (phpue/...). Returns [path, isTemporary].
         *
         * Cached in the private folder (PHPuePrivate, outside the web root) until any source file changes.
         * Without a private folder it is built in a fresh random temp folder (created 0700, so nothing can be
         * planted there) and the caller deletes it after sending: a predictable name in the shared /tmp could
         * be pre-created by another hosting account and served to visitors.
         */
        public static function zip(): array {
            $files = self::files();
            $fingerprint = '';
            foreach ($files as $name => $file) {
                $fingerprint .= $name . ':' . filemtime($file['path']) . ':' . filesize($file['path']) . ';';
            }

            $cacheDir = PHPuePrivate::dir('cache');
            if ($cacheDir !== null) {
                $cached = $cacheDir . '/phpue-skill-' . md5($fingerprint) . '.zip';
                if (is_file($cached)) return [$cached, false];
                $tmp = $cacheDir . '/phpue-skill-' . bin2hex(random_bytes(8)) . '.zip';
                self::build($files, $tmp);
                rename($tmp, $cached);
                return [$cached, false];
            }

            $tmpDir = sys_get_temp_dir() . '/phpue-skill-' . bin2hex(random_bytes(16));
            if (!mkdir($tmpDir, 0700)) throw new RuntimeException('Could not create a temporary folder for the skill zip.');
            $zip = $tmpDir . '/phpue-skill.zip';
            self::build($files, $zip);
            return [$zip, true];
        }

        /** Remove a temporary zip returned by zip() (and its folder). */
        public static function cleanup(string $zip): void {
            @unlink($zip);
            @rmdir(dirname($zip));
        }

        /** ZipArchive when available, otherwise PharData (bundled with PHP; the official php Docker images lack ext-zip). */
        private static function build(array $files, string $target): void {
            if (class_exists('ZipArchive')) {
                $archive = new ZipArchive();
                $archive->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE);
                foreach ($files as $name => $file) $archive->addFile($file['path'], self::NAME . '/' . $name);
                $archive->close();
                return;
            }
            $archive = new PharData($target, 0, null, Phar::ZIP);
            foreach ($files as $name => $file) $archive->addFile($file['path'], self::NAME . '/' . $name);
            unset($archive);
        }
    }
?>
