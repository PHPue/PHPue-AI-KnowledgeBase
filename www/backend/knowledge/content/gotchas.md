# Common mistakes and verified fixes

> Each row was compiled with PHPue 0.0.3's `convert_pvue_file()`. Many of these do not throw an error: the page silently renders the wrong thing. When something looks wrong, read the compiled PHP (`?compile=views/page.pvue` in dev, or `convert_pvue_file()` from the CLI).

## Template output

| You write | What happens | Fix |
|---|---|---|
| `{{ $user.name }}` | PHP concatenation: wrong output and warnings | `{{ $user['name'] }}` or `{{ $user->name }}` |
| `{{ $tags }}` (array) | `htmlspecialchars()` TypeError | `{{ implode(', ', $tags) }}` |
| `{{ !$x }}`, `{{ (int)$x }}`, `{{ 'text' }}` | printed literally | `{{ $x ? '' : 'no' }}`, `{{ intval($x) }}`, `<?= ... ?>` |
| `{{ total() }}` meant for Alpine | compiled as a PHP call: `Call to undefined function total()` | `x-text="total()"` |
| `class="x" {{ $on ? 'aria-current="page"' : '' }}` | quotes escaped: `aria-current=&quot;page&quot;` | `aria-current="{{ $on ? 'page' : 'false' }}"` or `<?= ... ?>` |
| `p-else`, `p-show`, `p-model` | not directives; left as dead attributes | negated `p-if`, raw PHP, `value="{{ $x }}"` |
| `p-if` element without its closing tag | only the opening tag is wrapped; content always renders | close the element |
| `p-for="item of $items"` | not converted (`data-phpue-invalid-p-for`) | `p-for="$item in $items"` |
| Literal `{{ $x }}` in documentation text | gets compiled | `&#123;&#123; $x &#125;&#125;` |

## Blocks and files

| You write | What happens | Fix |
|---|---|---|
| A closing script tag inside a PHP string or comment in `<script>` | the PHP block ends there; the rest is dropped or a parse error | `'</scr' . 'ipt>'` |
| `const s = '<template>'` in an inline `<script>` inside the template | counted as a nested template; the template swallows the rest of the file | `'<tem' + 'plate>'`, or move the JS to `<cscript>` |
| JavaScript in `<script>` (Vue habit) | PHP parse error: `<script>` is PHP | browser JS goes in `<cscript>` |
| `<html>`, `<head>`, `<body>` in a `.pvue` | duplicated document structure | PHPue prints them; use `<header>` for head tags |
| Editing `.pvue` while `.dist/` exists | no effect; the compiled copy is served | delete `.dist/` or rebuild |

## Components

| You write | What happens | Fix |
|---|---|---|
| `<Card title="x"/>`, `<Card>slot</Card>` | not replaced: the raw tag is printed, nothing renders | set `$cardTitle` before `<Card/>`; components inherit scope |
| `function helper() {}` in a component used twice or in a loop | `Cannot redeclare helper()` | move to `backend/` or use a closure |
| `const x = ...` at the top level of a reused component's `<cscript>` | JS `Identifier 'x' has already been declared` | IIFE, `var`, or move to the view/App cscript |
| `<Card/>` without `@require` | renders nothing useful | `@require Card 'components/Card.pvue';` |
| Component uses `$title`, parent also uses `$title` | the component overwrites the parent's variable | specific names (`$cardTitle`) |

## Routing, scope and headers

| You write | What happens | Fix |
|---|---|---|
| `#require Home 'views/index.pvue';` | ignored (PHP `#` comment) | `#require 'views/index.pvue';` |
| `views/blog/post.pvue`, URL `/blog/post` | 404 | flat views + query strings |
| `?page=2` for pagination | overwritten by the route slug | `?p=2` |
| App variable used in a view | undefined | backend singleton, `$_SESSION`, `define()` |
| `<title>{{ $postTitle }}</title>` in a view header | prints the literal text `$postTitle` | dynamic meta from the App header (MetaControl) |
| `{{ count($x) }}` or `{{ $a ?? '' }}` in the App header | not converted | build the string in the App script, print `{{ $titleTag }}` |
| `<?= $x ?>` in the App header | printed literally (it is a heredoc) | `{{ $x }}` |
| Inline `<script>$(function(){...})</script>` in the App header | `$` interpolated by the heredoc | move JS to a file or `<cscript>` |
| `phpue_set_lang('cy')` in a view | too late, `<html lang>` already printed | App script or `backend/` |
| `__DIR__ . '/data/x.json'` in a view | breaks after `php index.php build` | `getcwd() . '/data/x.json'` |
| Variables named `$routing`, `$current_route`, `$b404`, `$input`, `$action` | reset by the compiled preamble | other names |

## AJAX

| You write | What happens | Fix |
|---|---|---|
| `return ['ok' => true];` in a handler | empty response | `echo json_encode(['ok' => true]);` |
| `fetch('', {method: 'POST', body: formData})` | no action found: the full HTML page comes back (200) | send JSON, or a backend endpoint for uploads |
| Handler uses a variable set in the view script | undefined (handlers run first) | recompute in the handler or use a singleton |
| `@AJAX('POST')` called with GET (or the reverse) | 405 | match the method |
| Client calls a plain (non-`@AJAX`) function by `action` | falls through to the HTML page | mark it `@AJAX('POST')` |
| Two handlers with the same name in different views | fatal redeclare | unique names app-wide |

## cscript

| You write | What happens | Fix |
|---|---|---|
| `const name = "{{ $name }}";` | `""Ada""`: JS syntax error | `const name = {{ $name }};` |
| `const n = {{ count($items) }};` | not converted: broken JS | `const n = <?= json_encode(count($items)) ?>;` |

## Sessions and caching

| You write | What happens | Fix |
|---|---|---|
| Writing `$_SESSION` in a view with `PHPUE_SESSION = 'lazy'` | lost for first-time visitors (no session yet) | `phpue_session(true)` before the write |
| `session_start()` anywhere in the App script text (even a comment) | PHPue's session handling switches off | remove it; use `phpue_session()` |
| Expecting nginx/CDN caching with default settings | every response sets a cookie and `no-store` | `PHPUE_SESSION = 'lazy'` |
| A backend file that `return`s an array, loaded with `require_once` | second load returns `true`, not the array | folder with `autoload.php`, or `require` |
