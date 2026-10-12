<?php
    /*
        PHPueKnowledge: the registry of knowledge topics and everything derived from it
        (page meta, JSON-LD, llms.txt, llms-full.txt, sitemap.xml).

        To add a topic: write content/<slug>.md (starting with "# Title"), then add it to TOPICS.
    */
    class PHPueKnowledge {
        const SITE_URL       = 'https://ai.phpue.co.uk';
        const SITE_NAME      = 'PHPue AI Knowledge';
        const PHPUE_VERSION  = '0.0.3';
        const UPDATED        = '2026-10-12';
        const AUTHOR         = 'Edward Patch';
        const SUMMARY        = 'The canonical, LLM-ready reference for PHPue 0.0.3, the server-side PHP framework with Vue-like .pvue single-file components. Written so AI assistants and coding agents can build PHPue apps correctly first time.';

        /** slug => [section, title, description] in reading order. */
        const TOPICS = [
            'overview'     => ['Start here', 'What PHPue is (and is not)', 'Definition, mental model, requirements, project layout and the request lifecycle of PHPue 0.0.3.'],
            'ai-rules'     => ['Start here', 'Rules for AI coding agents', 'The hard rules, the things never to bring in from Vue/React/Blade, the verification workflow, and an AGENTS.md / CLAUDE.md to drop into any PHPue project.'],
            'agent-skill'  => ['Start here', 'PHPue Agent Skill for Claude Code and Codex', 'Download the Agent Skill the PHPue author uses (SKILL.md, in-depth references, a starter app and compile/lint scripts) and install it for Claude Code, Codex or the Claude apps.'],
            'quickstart'   => ['Start here', 'Quick start: your first PHPue app', 'Get the two core files, write App.pvue and a view, run it with php -S, build .dist and deploy.'],
            'pvue-files'   => ['Core reference', '.pvue files: the four blocks', 'The script (PHP), header (head tags), template (HTML) and cscript (browser JS) blocks, and how the compiler finds them.'],
            'templates'    => ['Core reference', 'Templates: {{ }}, p-if, p-for and forms', 'Escaped output, conditional and repeated elements, raw PHP in templates, forms without p-model, and showing PHPue code as text.'],
            'components'   => ['Core reference', 'Components and inherited scope', '@require, <Component/> tags, why components need no props (they inherit the parent scope), loops and the rules for component code.'],
            'routing'      => ['Core reference', 'Views, routing and 404 pages', 'File-name routing from views/*.pvue, #require, query parameters instead of nested paths, navigation helpers and custom 404 pages.'],
            'ajax'         => ['Core reference', '@AJAX: server functions for the browser', 'Declaring @AJAX(\'POST\'|\'GET\') handlers, calling them with fetch, method enforcement, responses, uploads and security.'],
            'backend'      => ['Core reference', 'backend/, scope, sessions and language', 'Auto-loaded backend classes and singletons, variable scope between App, views and AJAX, PHPUE_SESSION lazy sessions, phpue_set_lang and file paths.'],
            'client-js'    => ['Core reference', 'cscript, AlpineJS, x-ssr and HTMX', 'Passing PHP data to JavaScript, adding AlpineJS, the x-ssr directive for server-rendered lists, and HTMX-style navigation.'],
            'build-deploy' => ['Ship it', 'Build, deploy, security and caching', 'Serving modes, php index.php build, the .dist layout, deployment options, Apache/nginx config, dev-tool safety and cacheable pages.'],
            'extensions'   => ['Ship it', 'Official extensions', 'MetaControl for per-page SEO, Matrix CMS for flat-file content, x-ssr for Alpine, and the multilingual pattern.'],
            'recipes'      => ['Examples', 'Copy-ready recipes', 'Complete, compiler-checked .pvue examples: forms, AJAX counter, lists, components in loops, database pages, dynamic meta.'],
            'gotchas'      => ['Avoid mistakes', 'Common mistakes and verified fixes', 'Input-to-output table of the mistakes that silently break PHPue pages, with the fix for each.'],
            'upgrade'      => ['Avoid mistakes', 'Upgrading from 0.0.2 to 0.0.3', 'What 0.0.3 changed, how to upgrade, and the 0.0.2-only rules to apply when you maintain an older project.'],
            'migration'    => ['Avoid mistakes', 'Coming from React, Next.js, Vue, Blade or plain PHP', 'Concept-by-concept mapping from other frameworks to PHPue, and when PHPue is (or is not) the right tool.'],
            'faq'          => ['Examples', 'PHPue FAQ', 'Short, direct answers to the questions people ask about PHPue most often.'],
        ];

        private static array $cache = [];

        public static function exists(string $slug): bool {
            return isset(self::TOPICS[$slug]);
        }

        public static function title(string $slug): string {
            return self::TOPICS[$slug][1] ?? $slug;
        }

        public static function description(string $slug): string {
            return self::TOPICS[$slug][2] ?? '';
        }

        public static function markdown(string $slug): string {
            if (!self::exists($slug)) return '';
            if (!isset(self::$cache[$slug])) {
                $file = __DIR__ . '/content/' . $slug . '.md';
                self::$cache[$slug] = is_file($file) ? file_get_contents($file) : '';
            }
            return self::$cache[$slug];
        }

        /** Rendered topic. $withTitle = false drops the leading "# Title" (when the page prints its own h1). */
        public static function html(string $slug, bool $withTitle = true): string {
            $markdown = self::markdown($slug);
            if (!$withTitle) $markdown = preg_replace('/\A\s*#\s+[^\n]*\n/', '', $markdown);
            return PHPueMarkdown::toHtml($markdown);
        }

        /** [previous slug|null, next slug|null] in reading order. */
        public static function neighbours(string $slug): array {
            $slugs = array_keys(self::TOPICS);
            $i = array_search($slug, $slugs, true);
            if ($i === false) return [null, null];
            return [$slugs[$i - 1] ?? null, $slugs[$i + 1] ?? null];
        }

        /** Canonical HTML URL of a topic (overview lives on the home page, faq on /faq). */
        public static function pageUrl(string $slug, bool $absolute = false): string {
            $path = match ($slug) {
                'overview'    => '/',
                'faq'         => '/faq',
                'agent-skill' => '/skills',
                default    => '/docs?topic=' . $slug,
            };
            return $absolute ? self::SITE_URL . $path : $path;
        }

        public static function markdownUrl(string $slug, bool $absolute = false): string {
            return ($absolute ? self::SITE_URL : '') . '/knowledge/' . $slug . '.md';
        }

        /** Topics grouped by section: section => [slug => title]. */
        public static function sections(): array {
            $sections = [];
            foreach (self::TOPICS as $slug => [$section, $title]) {
                $sections[$section][$slug] = $title;
            }
            return $sections;
        }

        /** Questions and plain-text answers parsed from faq.md ("## Question" + answer). */
        public static function faqItems(): array {
            $items = [];
            $parts = preg_split('/^##\s+/m', self::markdown('faq'));
            array_shift($parts);
            foreach ($parts as $part) {
                [$question, $answer] = array_pad(explode("\n", $part, 2), 2, '');
                $items[] = ['q' => trim($question), 'a' => PHPueMarkdown::plain($answer)];
            }
            return $items;
        }

        // ------------------------------------------------------------------ agent files

        public static function llmsTxt(): string {
            $out  = "# " . self::SITE_NAME . "\n\n";
            $out .= "> " . self::SUMMARY . "\n\n";
            $out .= "PHPue is NOT Vue: .pvue files compile to plain PHP on the server; there is no virtual DOM, reactivity, props, slots or hydration. ";
            $out .= "Current release: PHPue " . self::PHPUE_VERSION . " (needs PHP 8.0+). Official site: https://phpue.co.uk. Source: https://github.com/PHPue/PHPue.\n\n";
            $out .= "Read `ai-rules` before writing code. Every topic is self-contained markdown; `/llms-full.txt` is all of them in one file.\n\n";
            foreach (self::sections() as $section => $topics) {
                $out .= "## {$section}\n\n";
                foreach ($topics as $slug => $title) {
                    $out .= "- [{$title}](" . self::markdownUrl($slug, true) . "): " . self::description($slug) . "\n";
                }
                $out .= "\n";
            }
            $out .= "## Agent Skill\n\n";
            $out .= "- [PHPue skill for Claude Code and Codex (zip)](" . self::SITE_URL . "/skills/phpue.zip): the skill the PHPue author uses: SKILL.md, in-depth references/, a starter app in templates/, and compile/lint scripts. Unzip into ~/.claude/skills or ~/.codex/skills.\n";
            $out .= "- [Skill entry point](" . self::SITE_URL . "/skills/phpue/SKILL.md): the SKILL.md on its own.\n\n";
            $out .= "## Optional\n\n";
            $out .= "- [Full knowledge in one file](" . self::SITE_URL . "/llms-full.txt): every topic above, concatenated.\n";
            $out .= "- [Official documentation](https://phpue.co.uk/docs): the human docs on phpue.co.uk.\n";
            $out .= "- [PHPue on GitHub](https://github.com/PHPue/PHPue): index.php and conversion.php, the two framework files.\n";
            $out .= "- [Official extensions](https://github.com/PHPue/PHPue-Extensions): MetaControl, Matrix CMS, x-ssr.\n";
            $out .= "- [This knowledge base on GitHub](https://github.com/PHPue/PHPue-AI): source of this site; content lives in www/backend/knowledge/content/.\n";
            return $out;
        }

        public static function llmsFullTxt(): string {
            $out  = "# " . self::SITE_NAME . " (full text)\n\n";
            $out .= "> " . self::SUMMARY . "\n\n";
            $out .= "PHPue version: " . self::PHPUE_VERSION . " | Updated: " . self::UPDATED . " | Index: " . self::SITE_URL . "/llms.txt\n";
            foreach (self::TOPICS as $slug => $topic) {
                $out .= "\n\n---\n\n";
                $out .= "<!-- Source: " . self::pageUrl($slug, true) . " | Markdown: " . self::markdownUrl($slug, true) . " -->\n\n";
                $out .= trim(self::markdown($slug)) . "\n";
            }
            return $out;
        }

        public static function sitemapXml(): string {
            $urls = ['/' => '1.0', '/docs' => '0.8', '/faq' => '0.9'];
            foreach (array_keys(self::TOPICS) as $slug) {
                $urls[self::pageUrl($slug)] = $urls[self::pageUrl($slug)] ?? '0.8';
            }
            $urls['/chat'] = '0.4';
            $out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
            foreach ($urls as $path => $priority) {
                $out .= "  <url><loc>" . htmlspecialchars(self::SITE_URL . $path, ENT_XML1) . "</loc><lastmod>" . self::UPDATED . "</lastmod><priority>{$priority}</priority></url>\n";
            }
            return $out . "</urlset>\n";
        }

        // ------------------------------------------------------------------ page <head>

        /**
         * Everything route-specific in <head>: title, description, canonical, robots, Open Graph,
         * the markdown alternate and JSON-LD. Printed raw by App.pvue's header, so every value is escaped here.
         */
        public static function headTags(string $route, array $query = []): string {
            $meta = self::pageMeta($route, $query);
            $e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

            $tags   = [];
            $tags[] = '<title>' . $e($meta['title']) . '</title>';
            $tags[] = '<meta name="description" content="' . $e($meta['description']) . '">';
            $tags[] = '<meta name="robots" content="' . $e($meta['robots']) . '">';
            if ($meta['canonical']) {
                $tags[] = '<link rel="canonical" href="' . $e($meta['canonical']) . '">';
                $tags[] = '<meta property="og:url" content="' . $e($meta['canonical']) . '">';
            }
            $tags[] = '<meta property="og:type" content="' . ($route === 'index' ? 'website' : 'article') . '">';
            $tags[] = '<meta property="og:site_name" content="' . $e(self::SITE_NAME) . '">';
            $tags[] = '<meta property="og:title" content="' . $e($meta['title']) . '">';
            $tags[] = '<meta property="og:description" content="' . $e($meta['description']) . '">';
            $tags[] = '<meta property="og:image" content="' . $e(self::SITE_URL . '/assets/img/logo-large.png') . '">';
            $tags[] = '<meta name="twitter:card" content="summary">';
            if ($meta['markdown']) {
                $tags[] = '<link rel="alternate" type="text/markdown" title="This page as markdown" href="' . $e($meta['markdown']) . '">';
            }
            $tags[] = '<link rel="alternate" type="text/plain" title="llms.txt" href="/llms.txt">';
            $tags[] = '<link rel="sitemap" type="application/xml" href="/sitemap.xml">';
            $tags[] = '<script type="application/ld+json">' . json_encode($meta['jsonld'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . '</script>';

            return implode("\n    ", $tags);
        }

        public static function pageMeta(string $route, array $query = []): array {
            $topic = (string) ($query['topic'] ?? '');
            $base = [
                'title'       => self::SITE_NAME,
                'description' => self::SUMMARY,
                'robots'      => 'index, follow, max-snippet:-1, max-image-preview:large',
                'canonical'   => null,
                'markdown'    => null,
                'jsonld'      => ['@context' => 'https://schema.org', '@graph' => [self::websiteNode(), self::phpueNode()]],
            ];

            switch ($route) {
                case 'index':
                    $base['title']     = 'PHPue AI Knowledge: the LLM-ready PHPue ' . self::PHPUE_VERSION . ' reference';
                    $base['canonical'] = self::SITE_URL . '/';
                    $base['markdown']  = self::markdownUrl('overview', true);
                    $base['jsonld']['@graph'][] = self::articleNode('overview');
                    break;

                case 'docs':
                    if (self::exists($topic)) {
                        $base['title']       = self::title($topic) . ' | PHPue ' . self::PHPUE_VERSION . ' | ' . self::SITE_NAME;
                        $base['description'] = self::description($topic);
                        $base['canonical']   = self::pageUrl($topic, true);
                        $base['markdown']    = self::markdownUrl($topic, true);
                        $base['jsonld']['@graph'][] = self::articleNode($topic);
                        $base['jsonld']['@graph'][] = self::breadcrumbNode($topic);
                    } else {
                        $base['title']       = 'All PHPue topics | ' . self::SITE_NAME;
                        $base['description'] = 'Every PHPue ' . self::PHPUE_VERSION . ' knowledge topic for developers and AI agents: core reference, deployment, extensions, recipes and common mistakes.';
                        $base['canonical']   = self::SITE_URL . '/docs';
                        $base['markdown']    = self::SITE_URL . '/llms.txt';
                    }
                    break;

                case 'faq':
                    $base['title']       = 'PHPue FAQ: direct answers about PHPue ' . self::PHPUE_VERSION;
                    $base['description'] = self::description('faq');
                    $base['canonical']   = self::SITE_URL . '/faq';
                    $base['markdown']    = self::markdownUrl('faq', true);
                    $base['jsonld']['@graph'][] = self::faqNode();
                    break;

                case 'skills':
                    $base['title']       = 'PHPue Agent Skill for Claude Code and Codex | ' . self::SITE_NAME;
                    $base['description'] = self::description('agent-skill');
                    $base['canonical']   = self::SITE_URL . '/skills';
                    $base['markdown']    = self::markdownUrl('agent-skill', true);
                    $base['jsonld']['@graph'][] = self::articleNode('agent-skill');
                    break;

                case 'chat':
                    $base['title']       = 'Ask PHPue AI | ' . self::SITE_NAME;
                    $base['description'] = 'Chat with an AI assistant grounded on the PHPue ' . self::PHPUE_VERSION . ' knowledge base.';
                    $base['canonical']   = self::SITE_URL . '/chat';
                    break;

                default:
                    $base['title']  = 'PHPue AI Knowledge';
                    $base['robots'] = 'noindex, follow';
            }
            return $base;
        }

        private static function websiteNode(): array {
            return [
                '@type'       => 'WebSite',
                '@id'         => self::SITE_URL . '/#website',
                'name'        => self::SITE_NAME,
                'url'         => self::SITE_URL . '/',
                'description' => self::SUMMARY,
                'inLanguage'  => 'en',
                'publisher'   => ['@type' => 'Person', 'name' => self::AUTHOR],
                'about'       => ['@id' => self::SITE_URL . '/#phpue'],
            ];
        }

        private static function phpueNode(): array {
            return [
                '@type'               => 'SoftwareSourceCode',
                '@id'                 => self::SITE_URL . '/#phpue',
                'name'                => 'PHPue',
                'alternateName'       => 'PHPue Framework',
                'description'         => 'PHPue is a server-side PHP framework that compiles Vue-like .pvue single-file components (script, header, template and cscript blocks with {{ }}, p-if and p-for) into plain PHP. No Node.js, bundler or build step is required.',
                'url'                 => 'https://phpue.co.uk',
                'codeRepository'      => 'https://github.com/PHPue/PHPue',
                'programmingLanguage' => 'PHP',
                'runtimePlatform'     => 'PHP 8.0+',
                'version'             => self::PHPUE_VERSION,
                'license'             => 'https://www.apache.org/licenses/LICENSE-2.0',
                'author'              => ['@type' => 'Person', 'name' => self::AUTHOR],
            ];
        }

        private static function articleNode(string $slug): array {
            return [
                '@type'         => 'TechArticle',
                'headline'      => self::title($slug),
                'description'   => self::description($slug),
                'url'           => self::pageUrl($slug, true),
                'dateModified'  => self::UPDATED,
                'inLanguage'    => 'en',
                'author'        => ['@type' => 'Person', 'name' => self::AUTHOR],
                'isPartOf'      => ['@id' => self::SITE_URL . '/#website'],
                'about'         => ['@id' => self::SITE_URL . '/#phpue'],
                'encoding'      => ['@type' => 'MediaObject', 'encodingFormat' => 'text/markdown', 'contentUrl' => self::markdownUrl($slug, true)],
            ];
        }

        private static function breadcrumbNode(string $slug): array {
            return [
                '@type' => 'BreadcrumbList',
                'itemListElement' => [
                    ['@type' => 'ListItem', 'position' => 1, 'name' => 'PHPue AI Knowledge', 'item' => self::SITE_URL . '/'],
                    ['@type' => 'ListItem', 'position' => 2, 'name' => 'Topics', 'item' => self::SITE_URL . '/docs'],
                    ['@type' => 'ListItem', 'position' => 3, 'name' => self::title($slug), 'item' => self::pageUrl($slug, true)],
                ],
            ];
        }

        private static function faqNode(): array {
            return [
                '@type'      => 'FAQPage',
                'url'        => self::SITE_URL . '/faq',
                'mainEntity' => array_map(fn($item) => [
                    '@type' => 'Question',
                    'name'  => $item['q'],
                    'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item['a']],
                ], self::faqItems()),
            ];
        }
    }
?>
