<?php
    /*
        Small, safe Markdown renderer for the knowledge files.

        Supports what content/*.md uses: # headings (with ids), fenced code, tables, flat lists
        (with indented continuation lines), > quotes, --- rules, paragraphs, `code`, **bold**,
        *em* and [links](url). Every piece of text is HTML-escaped, so code samples containing
        PHPue syntax are shown, never executed, and the output is safe to print raw.
    */
    class PHPueMarkdown {

        public static function toHtml(string $markdown): string {
            $lines = preg_split('/\R/', str_replace("\t", '    ', $markdown));
            $html = [];
            $count = count($lines);
            $i = 0;

            while ($i < $count) {
                $line = $lines[$i];
                $trim = trim($line);

                if ($trim === '') { $i++; continue; }

                // Fenced code block
                if (preg_match('/^```\s*([\w+-]*)/', $trim, $m)) {
                    $lang = $m[1];
                    $code = [];
                    $i++;
                    while ($i < $count && !preg_match('/^\s*```\s*$/', $lines[$i])) {
                        $code[] = $lines[$i];
                        $i++;
                    }
                    $i++; // closing fence
                    $class = $lang !== '' ? ' class="language-' . self::e($lang) . '"' : '';
                    $label = $lang !== '' ? '<span class="code-lang">' . self::e($lang) . '</span>' : '';
                    $html[] = '<div class="code-wrap">' . $label . '<pre><code' . $class . '>' . self::e(implode("\n", $code)) . '</code></pre></div>';
                    continue;
                }

                // Heading
                if (preg_match('/^(#{1,4})\s+(.+?)\s*#*$/', $trim, $m)) {
                    $level = strlen($m[1]);
                    $html[] = '<h' . $level . ' id="' . self::slug($m[2]) . '">' . self::inline($m[2]) . '</h' . $level . '>';
                    $i++;
                    continue;
                }

                // Horizontal rule
                if (preg_match('/^(-{3,}|\*{3,})$/', $trim)) {
                    $html[] = '<hr>';
                    $i++;
                    continue;
                }

                // Table: header row followed by a |---| separator row
                if (str_starts_with($trim, '|') && $i + 1 < $count && preg_match('/^\|?\s*:?-{2,}/', trim($lines[$i + 1]))) {
                    $head = self::cells($trim);
                    $i += 2;
                    $rows = [];
                    while ($i < $count && str_starts_with(trim($lines[$i]), '|')) {
                        $rows[] = self::cells(trim($lines[$i]));
                        $i++;
                    }
                    $out = '<div class="table-wrap"><table><thead><tr>';
                    foreach ($head as $cell) $out .= '<th>' . self::inline($cell) . '</th>';
                    $out .= '</tr></thead><tbody>';
                    foreach ($rows as $row) {
                        $out .= '<tr>';
                        foreach ($row as $cell) $out .= '<td>' . self::inline($cell) . '</td>';
                        $out .= '</tr>';
                    }
                    $html[] = $out . '</tbody></table></div>';
                    continue;
                }

                // Blockquote
                if (str_starts_with($trim, '>')) {
                    $quote = [];
                    while ($i < $count && str_starts_with(trim($lines[$i]), '>')) {
                        $quote[] = ltrim(substr(trim($lines[$i]), 1));
                        $i++;
                    }
                    $html[] = '<blockquote>' . self::toHtml(implode("\n", $quote)) . '</blockquote>';
                    continue;
                }

                // List: indented markers become one nested level, other indented lines continue the item
                if (preg_match('/^([-*]|\d+\.)\s+/', $trim, $m)) {
                    $ordered = ctype_digit($m[1][0]);
                    $items = [];   // [text, [sub-item texts]]
                    while ($i < $count) {
                        $current = $lines[$i];
                        $currentTrim = trim($current);
                        $indent = strlen($current) - strlen(ltrim($current));
                        $isMarker = preg_match('/^([-*]|\d+\.)\s+(.*)$/', $currentTrim, $im);
                        if ($isMarker && $indent < 2) {
                            $items[] = [$im[2], []];
                        } elseif ($isMarker && $items) {
                            $items[count($items) - 1][1][] = $im[2];
                        } elseif ($currentTrim !== '' && $items && $indent >= 2 && !str_starts_with($currentTrim, '```')) {
                            $last = count($items) - 1;
                            if ($items[$last][1]) {
                                $items[$last][1][count($items[$last][1]) - 1] .= ' ' . $currentTrim;
                            } else {
                                $items[$last][0] .= ' ' . $currentTrim;
                            }
                        } else {
                            break;
                        }
                        $i++;
                    }
                    $tag = $ordered ? 'ol' : 'ul';
                    $out = '<' . $tag . '>';
                    foreach ($items as [$text, $children]) {
                        $out .= '<li>' . self::inline($text);
                        if ($children) {
                            $out .= '<ul>';
                            foreach ($children as $child) $out .= '<li>' . self::inline($child) . '</li>';
                            $out .= '</ul>';
                        }
                        $out .= '</li>';
                    }
                    $html[] = $out . '</' . $tag . '>';
                    continue;
                }

                // Paragraph
                $para = [];
                while ($i < $count) {
                    $t = trim($lines[$i]);
                    if ($t === '' || preg_match('/^(#{1,4}\s|```|>|\||([-*]|\d+\.)\s+|-{3,}$)/', $t)) break;
                    $para[] = $t;
                    $i++;
                }
                $html[] = '<p>' . self::inline(implode(' ', $para)) . '</p>';
            }

            return implode("\n", $html);
        }

        /** Level-2 headings as [id => text], for a page's table of contents. */
        public static function toc(string $markdown): array {
            $toc = [];
            $inCode = false;
            foreach (preg_split('/\R/', $markdown) as $line) {
                if (preg_match('/^\s*```/', $line)) { $inCode = !$inCode; continue; }
                if (!$inCode && preg_match('/^##\s+(.+?)\s*$/', $line, $m)) {
                    $toc[self::slug($m[1])] = self::plain($m[1]);
                }
            }
            return $toc;
        }

        /** Markdown inline syntax stripped to plain text (for meta descriptions and JSON-LD). */
        public static function plain(string $markdown): string {
            $text = preg_replace('/```[\s\S]*?```/', ' ', $markdown);
            $text = preg_replace('/\[([^\]]+)\]\(([^)\s]+)\)/', '$1 ($2)', $text);
            $text = preg_replace('/(\*\*|`)/', '', $text);
            $text = preg_replace('/^\s*([-*]|\d+\.|>|#{1,4})\s+/m', '', $text);
            return trim(preg_replace('/\s+/', ' ', $text));
        }

        public static function slug(string $text): string {
            $slug = strtolower(self::plain($text));
            $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
            return trim($slug, '-') ?: 'section';
        }

        private static function cells(string $row): array {
            $row = trim($row);
            if (str_starts_with($row, '|')) $row = substr($row, 1);
            if (str_ends_with($row, '|')) $row = substr($row, 0, -1);
            // Allow escaped pipes (\|) inside cells
            $cells = preg_split('/(?<!\\\\)\|/', $row);
            return array_map(fn($c) => str_replace('\|', '|', trim($c)), $cells);
        }

        private static function inline(string $text): string {
            // Swap `code` spans for placeholders so their content is escaped but never formatted,
            // while **bold**/links around them still pair up across the span.
            $spans = [];
            $text = preg_replace_callback('/(`+)(.+?)\1/', function ($m) use (&$spans) {
                $spans[] = '<code>' . self::e(trim($m[2])) . '</code>';
                return "\x1A" . (count($spans) - 1) . "\x1A";
            }, str_replace("\x1A", '', $text));
            $out = self::format(self::e($text));
            return preg_replace_callback('/\x1A(\d+)\x1A/', fn($m) => $spans[(int) $m[1]], $out);
        }

        private static function format(string $escaped): string {
            $escaped = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', function ($m) {
                $url = html_entity_decode($m[2], ENT_QUOTES);
                if (!preg_match('#^(https?://|/|\#|mailto:)#i', $url)) return $m[0];
                $external = preg_match('#^https?://#i', $url) && !str_contains($url, '://ai.phpue.co.uk');
                $attrs = $external ? ' rel="noopener" target="_blank"' : '';
                return '<a href="' . self::e($url) . '"' . $attrs . '>' . $m[1] . '</a>';
            }, $escaped);
            $escaped = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $escaped);
            $escaped = preg_replace('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/', '<em>$1</em>', $escaped);
            return $escaped;
        }

        private static function e(string $text): string {
            return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
    }
?>
