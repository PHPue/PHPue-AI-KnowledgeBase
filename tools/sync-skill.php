<?php
/**
 * sync-skill.php: copy the author's PHPue Agent Skill into the site, ready to download.
 *
 * Usage (from the repository root):
 *   php tools/sync-skill.php [path/to/skills/phpue]
 *
 * Default source: ~/.claude/skills/phpue.
 * Destination:    www/backend/knowledge/skill/  (served as /skills/phpue.zip, never executed)
 *
 * The skill is copied unchanged except for lines that point at the author's own machine
 * (absolute local paths, /tmp experiments), which are rewritten to public equivalents.
 * The script fails if any machine-specific path is left, so nothing local gets published.
 */

$source = realpath($argv[1] ?? (getenv('HOME') . '/.claude/skills/phpue'));
$dest   = __DIR__ . '/../www/backend/knowledge/skill';

if ($source === false || !is_file($source . '/SKILL.md')) {
    fwrite(STDERR, "Skill not found. Pass the folder that contains SKILL.md.\n");
    exit(2);
}

// file => [regex => replacement]. Matched by folder name, so no machine-specific path lives in this repo.
$rewrites = [
    'SKILL.md' => [
        '# Both are symlinks to `PHPue-OpenSource/\.skills/phpue`\.#' => '',
        '#The repo is at `[^`]*/PHPue-Extensions-Official` \(one branch per category; read with `git show <branch>:<path>`\)\.#'
            => 'The repo is https://github.com/PHPue/PHPue-Extensions (one branch per category; clone it and read with `git show <branch>:<path>`).',
    ],
    'references/extensions.md' => [
        '#Source of truth \(local clone\): `[^`]*/PHPue-Extensions-Official`#'
            => 'Source of truth: https://github.com/PHPue/PHPue-Extensions (`git clone https://github.com/PHPue/PHPue-Extensions.git`)',
        '#cd \S*/PHPue-Extensions-Official#' => 'cd PHPue-Extensions',
    ],
    'references/alpine-xssr.md' => [
        '# \(`/tmp/PHPue-Skill-Learning/experiments/03-alpine-xssr`, may not exist on other machines\)#' => '',
    ],
    'references/htmx-spa.md' => [
        '#\(`/tmp/PHPue-Skill-Learning/experiments/04-htmx-ssr-spa`, built while testing this skill, with NGINX fastcgi_cache measurements\)#'
            => '(built while testing this skill, with NGINX fastcgi_cache measurements)',
    ],
    'references/runtime-build-deploy.md' => [
        '#are in `[^`]*/PHPue-TechDemo/dev-notes/` \(`0\.0\.2-field-notes\.md`, `0\.0\.3-proposals\.md`\)#'
            => 'were kept by the author in PHPue-TechDemo (`dev-notes/0.0.2-field-notes.md`, `dev-notes/0.0.3-proposals.md`)',
    ],
];

// 1. Fresh copy
removeTree($dest);
copyTree($source, $dest);

// 2. Rewrite machine-specific lines
$ok = true;
foreach ($rewrites as $file => $pairs) {
    $path = "$dest/$file";
    $text = (string) file_get_contents($path);
    foreach ($pairs as $pattern => $replace) {
        $text = preg_replace($pattern, $replace, $text, -1, $count);
        if ($count === 0) fwrite(STDERR, "WARN  $file: pattern not found (the skill changed?): $pattern\n");
    }
    file_put_contents($path, $text);
}

// 3. Refuse to publish anything that still points at this machine
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dest, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if (!preg_match('/\.(md|php|pvue|ya?ml|conf|json|js|css)$/', $file->getFilename()) && $file->getFilename() !== '.htaccess') continue;
    foreach (file($file->getPathname()) as $n => $line) {
        if (preg_match('#/mnt/|/home/|/tmp/PHPue-Skill|/Users/|C:\\\\Users#', $line)) {
            fwrite(STDERR, "ERROR " . substr($file->getPathname(), strlen($dest) + 1) . ':' . ($n + 1) . " still contains a local path\n");
            $ok = false;
        }
    }
}

$count = iterator_count(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dest, FilesystemIterator::SKIP_DOTS)));
echo ($ok ? "Synced" : "Synced WITH ERRORS") . ": $count files from $source\n";
exit($ok ? 0 : 1);

function copyTree(string $from, string $to): void {
    mkdir($to, 0775, true);
    foreach (new DirectoryIterator($from) as $item) {
        if ($item->isDot()) continue;
        $target = "$to/" . $item->getFilename();
        $item->isDir() ? copyTree($item->getPathname(), $target) : copy($item->getPathname(), $target);
    }
}

function removeTree(string $dir): void {
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $item) $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    rmdir($dir);
}
