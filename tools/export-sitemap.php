<?php
// Run from the repository root: php tools/export-sitemap.php
// The static upload file and the PHP endpoint share one generator.
require_once __DIR__ . '/../www/backend/knowledge/PHPueKnowledge.php';

$path = __DIR__ . '/../www/sitemap.xml';
if (file_put_contents($path, PHPueKnowledge::sitemapXml()) === false) {
    fwrite(STDERR, "Could not write sitemap.xml\n");
    exit(1);
}
echo "Updated www/sitemap.xml\n";
