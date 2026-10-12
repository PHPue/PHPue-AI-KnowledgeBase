<?php
    /*
        Machine-readable endpoints for AI agents, search and GEO crawlers.

        These URLs have no file on disk, so Apache (.htaccess), nginx (try_files) and `php -S`
        all hand them to index.php. PHPue loads backend/ before routing, so they are answered
        here and the request exits before any page renders.

            /llms.txt                 index of every topic (llmstxt.org format)
            /llms-full.txt            every topic in one file
            /knowledge/<slug>.md      one topic as markdown
            /skills/phpue.zip         Agent Skill for Claude Code / Codex (SKILL.md + references + scripts)
            /skills/phpue/<file>      one skill file (markdown/yaml only; .php URLs never reach PHPue on nginx)
            /robots.txt, /sitemap.xml
            /, /docs?topic=x, /faq    also return markdown when the client asks for text/markdown first
    */
    class PHPueAgentEndpoints {

        public static function handle(): void {
            if (php_sapi_name() === 'cli' && !isset($_SERVER['HTTP_HOST'])) return;   // `php index.php build`

            $path  = '/' . trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
            $topic = isset($_GET['topic']) ? (string) $_GET['topic'] : '';

            switch ($path) {
                case '/llms.txt':
                    self::send(PHPueKnowledge::llmsTxt(), 'text/plain');
                case '/llms-full.txt':
                    self::send(PHPueKnowledge::llmsFullTxt(), 'text/plain');
                case '/robots.txt':
                    self::send(self::robotsTxt(), 'text/plain');
                case '/sitemap.xml':
                    self::send(PHPueKnowledge::sitemapXml(), 'application/xml');
                case '/knowledge':
                    self::redirect('/llms.txt');
                case '/skills/phpue.zip':
                    [$zip, $isTemporary] = PHPueSkill::zip();
                    if ($isTemporary) register_shutdown_function([PHPueSkill::class, 'cleanup'], $zip);
                    self::sendFile($zip, 'application/zip', 'phpue-skill.zip');
            }

            if (preg_match('#^/skills/phpue/((?:[A-Za-z0-9_-]+/)*[A-Za-z0-9._-]+\.(md|yaml))$#', $path, $m)) {
                $body = PHPueSkill::file($m[1]);
                if ($body === null) self::send("Not found. Download the whole skill: " . PHPueKnowledge::SITE_URL . "/skills/phpue.zip\n", 'text/plain', 404);
                self::send($body, $m[2] === 'md' ? 'text/markdown' : 'text/plain');
            }

            if (preg_match('#^/knowledge/([a-z0-9-]+)\.md$#', $path, $m)) {
                if (!PHPueKnowledge::exists($m[1])) self::send("# Not found\n\nNo topic named `{$m[1]}`. See " . PHPueKnowledge::SITE_URL . "/llms.txt\n", 'text/markdown', 404);
                self::send(PHPueKnowledge::markdown($m[1]), 'text/markdown', 200, PHPueKnowledge::pageUrl($m[1], true));
            }

            // One canonical URL per topic
            if ($path === '/docs' && in_array($topic, ['overview', 'faq', 'agent-skill'], true)) {
                self::redirect(PHPueKnowledge::pageUrl($topic));
            }

            // Content negotiation: agents that prefer markdown get it from the HTML URLs too
            if (self::wantsMarkdown()) {
                $slug = match (true) {
                    $path === '/'                                          => 'overview',
                    $path === '/faq'                                       => 'faq',
                    $path === '/skills'                                    => 'agent-skill',
                    $path === '/docs' && PHPueKnowledge::exists($topic)    => $topic,
                    default                                                => null,
                };
                if ($slug !== null) {
                    header('Vary: Accept');
                    self::send(PHPueKnowledge::markdown($slug), 'text/markdown', 200, PHPueKnowledge::pageUrl($slug, true));
                }
                if ($path === '/docs') {
                    header('Vary: Accept');
                    self::send(PHPueKnowledge::llmsTxt(), 'text/markdown');
                }
            }
        }

        private static function robotsTxt(): string {
            $site = PHPueKnowledge::SITE_URL;
            return <<<TXT
            # PHPue AI Knowledge: written to be read by people, search engines and AI agents.
            # All crawlers (including GPTBot, ClaudeBot, PerplexityBot, Google-Extended) are welcome.
            # Start with {$site}/llms.txt or {$site}/llms-full.txt

            User-agent: *
            Allow: /
            Disallow: /chat?action=
            Disallow: /form

            Sitemap: {$site}/sitemap.xml

            TXT;
        }

        private static function wantsMarkdown(): bool {
            if (($_GET['format'] ?? '') === 'md') return true;
            $accept = strtolower($_SERVER['HTTP_ACCEPT'] ?? '');
            $md = strpos($accept, 'text/markdown');
            if ($md === false) return false;
            $html = strpos($accept, 'text/html');
            return $html === false || $md < $html;
        }

        private static function send(string $body, string $type, int $status = 200, ?string $canonical = null): void {
            while (ob_get_level() > 0) ob_end_clean();
            http_response_code($status);
            header('Content-Type: ' . $type . '; charset=utf-8');
            header('Cache-Control: public, max-age=3600');
            header('X-Content-Type-Options: nosniff');
            if ($canonical !== null) header('Link: <' . $canonical . '>; rel="canonical"');
            echo $body;
            exit;
        }

        private static function sendFile(string $file, string $type, string $downloadName): void {
            while (ob_get_level() > 0) ob_end_clean();
            http_response_code(200);
            header('Content-Type: ' . $type);
            header('Content-Disposition: attachment; filename="' . $downloadName . '"');
            header('Content-Length: ' . filesize($file));
            header('Cache-Control: public, max-age=3600');
            header('X-Robots-Tag: noindex');
            readfile($file);
            exit;
        }

        private static function redirect(string $to): void {
            while (ob_get_level() > 0) ob_end_clean();
            header('Location: ' . $to, true, 301);
            exit;
        }
    }
?>
