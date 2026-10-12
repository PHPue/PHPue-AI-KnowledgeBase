# Engine gotchas: verified compiler behaviour

`conversion.php` is not an HTML parser. 0.0.2 is a set of regular expressions. 0.0.3 finds blocks at the top level of the file and converts `p-if`/`p-for` tag by tag (quote-, comment- and PHP-aware), but everything else (`{{ }}`, headers, `@AJAX`, components) is still pattern matching. Every case below was compiled with the named version's `convert_pvue_file()`. "→" shows the actual output. When in doubt, compile the file (`scripts/phpue-compile.php`) and read the PHP, or run `scripts/phpue-lint.php` (it applies the rules for the project's `PHPUE_VERSION`).

Check the version first: `grep PHPUE_VERSION www/index.php`.

## A. Still true in 0.0.3 (and in 0.0.2)

| # | You write | What happens | Fix |
|---|---|---|---|
| 1 | `$s = "</script>";` or `// see the </script> tag` in the PHP block | The PHP block **ends at the first closing script tag**, wherever it is. The rest of your PHP is dropped (it sits outside every block), or the open string becomes a parse error. | `'</scr' . 'ipt>'`; reword comments. Same for `</cscript>` in cscript and `</header>` in the header. |
| 2 | A `<template>`/`</template>` inside a JS string in an inline `<script>` in the template (`const s = '<template>';`) | 0.0.3 counts it as a nested template (only PHP blocks and comments are skipped), so the PHPue template never closes and swallows the cscript. 0.0.2 ends the template at a literal `</template>`. | Build the string (`'<tem' + 'plate>'`) or move the JS to `<cscript>`/a file. |
| 3 | `{{ $user.name }}` | → `htmlspecialchars($user.name ?? "")`, which is PHP concatenation: wrong output and warnings. | `{{ $user['name'] }}` / `{{ $user->name }}` |
| 4 | `{{ $tags }}` where `$tags` is an array (template) | `htmlspecialchars(array)` TypeError. | `{{ implode(', ', $tags) }}` (0.0.3) / `<?= htmlspecialchars(implode(', ', $tags)) ?>` |
| 5 | `{{ !$x }}`, `{{ (int)$x }}`, `{{ 'text' }}` | Left literally in the HTML: only expressions starting with `$` (and, in 0.0.3, a function/static call) convert. | `{{ $x ? '' : 'no' }}`, `{{ intval($x) }}` (0.0.3), or `<?= … ?>` |
| 6 | Alpine-style `{{ total() }}` in a 0.0.3 template | Compiled as a **PHP** call → `Call to undefined function total()`. `{{ total }}` / `{{ item.name }}` (no call, no `$`) stay literal. | Use `x-text="total()"` for Alpine output. |
| 7 | `<Card title="x"/>`, `<Card>slot</Card>` | Not replaced. The raw tag is output and nothing renders. | `<Card/>` (0.0.3: also `<Card />`, and `p-if`/`p-for` on the tag). No props by design: the component inherits the parent's variables, so set them beforehand. |
| 8 | Component script declares `function helper() {}` and the component is used twice (or inside `p-for`) | `Cannot redeclare helper()`. | Put helpers in `backend/`, or use closures. |
| 9 | Component `<cscript>` has `const x = …` and the component renders more than once | JS `SyntaxError: Identifier 'x' has already been declared`. | Wrap in an IIFE, use `var`/function declarations, or move shared JS to App/view cscript. |
| 10 | AJAX handler does `return ['ok' => true];` | Response body is empty (return value discarded). | `echo json_encode(['ok' => true]);` |
| 11 | `fetch('', {method:'POST', body: formData})` to an `@AJAX` function | The dispatcher reads only the JSON body, so no action is found and the **full HTML page comes back with status 200**. | Send JSON, or use a backend endpoint (`?api&endpoint=…`) for multipart. |
| 12 | `@AJAX` handler uses `$repo` set in the view/App script | Undefined: handlers are plain functions and run **before** the App and view scripts. | Recompute it inside the handler, or use a backend singleton. |
| 13 | View `<header>` with `<title>{{ $postTitle }}</title>` | Outputs the literal text `$postTitle`. | Static view headers. Do dynamic meta via App header vars (MetaControl pattern). |
| 14 | App `<header>` with `{{ count($x) }}` or `{{ $a['k'] ?? '' }}` | Not converted. 0.0.3 converts `{{ $var }}`, `{{ $arr['k'] }}`, `{{ $arr[0] }}`, `{{ $arr[$k] }}`, `{{ $obj->prop }}` (and chains of those) only; 0.0.2 only `{{ $var }}`. | Build `$titleTag` in the App script and write `{{ $titleTag }}`, or native heredoc `{$meta['title']}`. |
| 15 | App `<header>` with inline `<script>$(function(){…})</script>` | The header is a PHP heredoc, so `$(` / `$var` get interpolated or error. | Move JS to `/assets/js/…` or `<cscript>`. |
| 16 | `<?= $x ?>` inside App `<header>` | Printed literally (the header is a heredoc string, not a template). | `{{ $x }}` / `{$x}` |
| 17 | `<cscript>` with `const name = "{{ $name }}";` | → `""Ada""`: JS syntax error. | `const name = {{ $name }};` (json_encode adds the quotes). |
| 18 | `<cscript>` with `const n = {{ count($items) }};` | Not converted (cscript only converts `{{ $… }}`): broken JS. | `const n = <?= json_encode(count($items)) ?>;` |
| 19 | `#require Home 'views/index.pvue';` | Ignored. PHP treats it as a `#` comment. | `#require 'views/index.pvue';` |
| 20 | `views/blog/post.pvue` or URL `/blog/post` | Not a route → 404. | Flat `views/*.pvue` + query params (or PrettyUrlRedirect). |
| 21 | Variable set in `App.pvue` used in a view | Undefined (separate method scopes). | `$_SESSION`, `define()`, `$GLOBALS`, or a backend singleton. |
| 22 | Editing `.pvue` while `.dist/` exists | No effect: compiled files are served. | `rm -rf .dist` in dev, or `php index.php build`. |
| 23 | Literal `{{ $x }}` in documentation text | Gets compiled. | `&#123;&#123; … &#125;&#125;`. (Escaped tags such as `&lt;li p-for="…"&gt;` are safe in 0.0.3; in 0.0.2 write `<?= "p-for" ?>="…"`.) |
| 24 | Variables named `$routing`, `$current_route`, `$b404` | Reset by the preamble every compiled component re-runs. (0.0.2 also clobbers `$file`, `$iterator`, `$backendDir`; 0.0.3 renamed its loader variables to `$phpueBackend…`.) | Use other names. |
| 25 | A `p-if`/`p-for` element without its closing tag (`<div p-if="$x">` … no `</div>`) | 0.0.3 logs `has no closing </div>; wrapping the opening tag only`: the content always renders. 0.0.2 leaves the `if` open (parse error). | Close the element. The linter reports the compiler's message with the line. |
| 26 | `__DIR__ . '/backend/…'` in a view/App script | Works in source mode (`www/`) but is `www/.dist` / `www/.dist/pages` after a build, so the paths break. | `getcwd()` (always the web root). |
| 27 | Query string `?page=2` (pagination) | `index.php` overwrites `$_GET['page']` with the route slug, so your value is lost (`$_GET['page'] === 'products'`). | Never use `page` as a parameter name; use `?p=2` / `?pagination=2` (TechDemo uses `pagination`). |
| 28 | Expecting NGINX/proxy caching of PHPue pages | With `PHPUE_SESSION = 'auto'` (default, and always in 0.0.2) the App starts a session on every request, so every response has `Set-Cookie` + `no-store` and is never cached. The check is textual: if the App script contains `session_start()` anywhere (even in a comment), PHPue's session handling is skipped. | 0.0.3: `define('PHPUE_SESSION', 'lazy')` in `index.php` + `phpue_session(true)` before session writes. 0.0.2: manual lazy sessions plus `Cache-Control: public` (`htmx-spa.md`). |
| 29 | Alpine `Alpine.data()` registered in a view's script block, reached via HTMX/boosted swap | The swapped `x-data` initialises before the swapped script runs: `X is not defined`. | Register components globally (App-level JS) and pass SSR data as factory args `x-data="comp({{ $json }})"`. |
| 30 | `<a {{ $on ? 'aria-current="true"' : '' }}>` (a whole attribute through `{{ }}`) | `htmlspecialchars` escapes the quotes, so the output is `aria-current=&quot;true&quot;` (verified in PatchDesigns' Navbar). | Output a value: `aria-current="{{ $on ? 'page' : 'false' }}"`, or `<?= $on ? 'aria-current="page"' : '' ?>`. |
| 31 | A backend file that `return`s an array (language pack, config) loaded with `require_once` | If the backend scanner already `require_once`d it, the second call returns `true`, not the array. | Put such files in a folder with an `autoload.php` (LanguageSelector), or load them with `require`/`include`. |
| 32 | `phpue_set_lang('cy')` called in a **view** script (0.0.3) | Too late: `<html lang>` is printed after the App script, before the view renders. | Call it in the App script or a `backend/` bootstrap, or give the view `<meta name="lang" content="cy">`. |

## B. 0.0.2 only (fixed in 0.0.3): the upgrade checklist

Everything here breaks on 0.0.2 and **works on 0.0.3**. On a 0.0.2 project, follow the "0.0.2 fix" column. On a 0.0.3 project, don't add these workarounds (they still work, but they're no longer needed).

| # | You write | What 0.0.2 does | 0.0.2 fix |
|---|---|---|---|
| B1 | `<p p-if="$x" class="a">Text {{ $y }}</p>` (one line) | → `<?php if($x): ?>` `<p class="a">`. **Rest of line deleted, `if` never closed**, giving `syntax error, unexpected end of file`. | Opening tag alone on its line; content and `</p>` on the next lines. |
| B2 | `<div p-if="$a">` with a child line `<div>nested</div>` | → `<?php endif; ?>` inserted right after the one-line child. The remaining children render unconditionally. | Use a wrapper tag the children don't use on one line (`<section p-if>`), split children across lines, or `<?php if: ?>`. |
| B3 | `<div` ⏎ `p-if="$x">` (multi-line opening tag) | Not converted. `p-if` stays as a dead attribute and the element always renders. | Keep the `p-if` opening tag on one line. |
| B4 | `<div p-if="$ok" @click="list.map(x => x)">` | Line cut at the first `>`: → `<div @click="list.map(x =>` plus broken markup. | No `>` in attributes of `p-if` elements; move the logic into an Alpine method or getter. |
| B5 | `<span p-if="$x" class="badge {{ $tone }}">` (mustache or `<?= ?>` in the p-if tag's attributes) | `{{ }}` is converted before p-if; the `?>` stops the p-if regex, so the output is `<span class="badge <?= … ?>` with the closing `">` dropped. The open attribute swallows the following HTML and nests the layout; `php -l` still passes. | Wrap: `<div p-if="$x"><span class="badge {{ $tone }}">…</span></div>`. |
| B6 | `<li p-for="$i in $items" p-if="$i !== 'x'">` | `foreach` dropped, unclosed `if` → parse error. | Filter in `<script>`, or nest: `<li p-for>` containing a `<span p-if>` written on separate lines. |
| B7 | `<div p-for="$r in $rows">` containing `<div>{{ $r['v'] }}</div>` | The loop body ends at the **first** `</div>`. Later children render once, outside the loop, with the last `$r`. | Different outer tag (`article`, `li`, `section`, `tr`) or `<?php foreach ?>`. (FreeCAD's `x-ssr="scenes"` wrapper hits this; it only looks right with exactly one scene.) |
| B8 | `p-for="$x in getRows()"` | → `isset(getRows())` → fatal "Cannot use isset() on the result of an expression". | Assign to a variable in `<script>` first. |
| B9 | `p-for="$row in $stmt"` (PDOStatement / Traversable) | Skipped silently by `is_array()`. | `$rows = $stmt->fetchAll();` |
| B10 | `<input p-if="$x">` (void element) | No closing tag to find, so the `if` never closes. | Wrap it: `<span p-if="$x">` ⏎ `<input>` ⏎ `</span>`. |
| B11 | `{{ count($items) }}`, `{{ strtoupper($s) }}` | Left literally in the HTML (only `{{ $…` converts). | `<?= count($items) ?>`, `<?= htmlspecialchars(strtoupper($s)) ?>` |
| B12 | `<template x-if="open"><span>…</span></template>` anywhere inside `<template>` | The PHPue template **ends at that `</template>`**. Everything after it is gone. | Never nest `<template>`. Use `x-show`, `p-if`, `p-for`, or `x-ssr`. |
| B13 | `<Card />` (space) | Not replaced; the raw tag is output. | `<Card/>` or `<Card></Card>`. |
| B14 | `@require Card "components/Card.pvue";` (double quotes) | Not recognised: left in PHP → parse error. | Single quotes. |
| B15 | Component without a PHP `<script>` whose template contains `<script>console.log(1)</script>` | That JS becomes the component's **PHP** code → fatal error. | Use `<cscript>`, give the `<script>` an attribute (`type="module"`), or add an empty PHP `<script></script>` block first. |
| B16 | `@AJAX("POST")`, `function f($in): array {`, `function f($o = array()) {` | Not recognised. `@AJAX(...)` is left in PHP → parse error. | `@AJAX('POST')` + `function f($input) {` |
| B17 | `@AJAX` in a component | Dev: only callable on pages that render it. Build: **dropped**. | Put the handler in the hosting view or `App.pvue`. |
| B18 | A view with `@AJAX` handlers that isn't `#require`d in App | Works in dev; the build generates no `ajax-<view>.php`, so production loses the handlers. | `#require` every view. |
| B19 | Any `action` name (POST JSON or `?action=`) | The dispatcher calls **any** existing function with that name, including PHP built-ins (`?action=phpinfo`) and backend helpers. The declared method isn't enforced. | Validate in every handler; never name helpers after actions. (0.0.3: only `@AJAX` functions, method enforced with 405.) |
| B20 | `<cscript>` with `const v = {{ $data['items'] }};` | Not converted: broken JS. | `const v = <?= json_encode($data['items']) ?>;` |
| B21 | App `<header>` with `{{ $meta['title'] }}` | Not converted (only simple variable names). | Native heredoc `{$meta['title']}` / `{$metas[0]}` (the author's MetaControl pattern), or build `$titleTag` in the App script. |
| B22 | `<header>` (bare) used as an HTML element inside `<template>` | Stripped from output (and if it's the first bare `<header>` in the file, treated as the head block). | `<header class="…">` (any attribute makes it safe). |
| B23 | A literal tag name in a comment or string, e.g. `// inject into <header>` in `<script>`, or `<!-- <title> names the route -->` in a header | The first `<header>`/`<template>`/`<cscript>`/`</script>` or `<title>` **anywhere** is used. Blocks get swallowed or the page title becomes comment text. | Never write bare block or tag names in comments or strings. Say "header block" or "title tag", or escape as `&lt;header&gt;`. |
| B24 | Project without a `components/` folder | `php index.php build` throws `RecursiveDirectoryIterator::__construct(components)` after compiling App, leaving a half-built `.dist`. | Keep a `components/` folder (even empty). |
| B25 | Per-view `<meta name="lang">` in a built `.dist` | `<html lang>` is baked in at build time, so every page shares one lang. | Set it yourself at runtime. (0.0.3 resolves it per request.) |
| B26 | Hot reload of `components/a/b/c.pvue` | Not watched (the glob `**` is one level deep). | Save a file higher up. (0.0.3 watches recursively.) |

## C. New in 0.0.3 (worth knowing)

- **Directives on component tags:** `<Card p-if="$x"/>` and `<Card p-for="$item in $items" />` work, because directives are stripped before components are injected. The component's code runs once per item, so keep it free of named functions.
- **`p-for` source normaliser:** `phpue_iterable($x)` returns `$x` if it's iterable, else `[]`, so `p-for` over `null`, an unset variable or a scalar renders nothing (no warning).
- **Invalid `p-for`** (`p-for="row of $rows"`) → the attribute is renamed `data-phpue-invalid-p-for` and the compiler logs `invalid p-for`.
- **Mustache function calls** compile to `htmlspecialchars((string) (expr))`: arrays give "Array to string conversion".
- **`<View />`** (with a space) and `<View></View>` work in App.
- **Header title** ignores HTML comments, and `$phpue_header` extraction from compiled pages matches the exact heredoc terminator.
- **AJAX dispatch:** an `action` that names no `@AJAX` function falls through to normal page rendering (so plain form POSTs with an `action` field still render the page). A wrong method gets `405` with `Allow:` and `{"error": "@AJAX 'name' only accepts POST"}`. POST handlers start a session (`phpue_session(true)`).
- **Backend files** load in sorted path order on every OS.

## What the compiler adds to every compiled file

```php
<?php
// Auto-load backend classes            ← recursive require_once of backend/ (or .dist/backend/), sorted in 0.0.3
// Pre-determine 404 status             ← $current_route, $routing = get_phpue_routing(), $b404
//   (root only) session (0.0.3: phpue_session(); 0.0.2: session_start()), AJAX handler loading
//   (0.0.3: function + $GLOBALS['phpue_ajax'][name] = METHOD allow-list), AJAX dispatch + exit
<your <script> PHP, with @require/#require/@AJAX lines replaced by comments>
$phpue_header = <<<HTML … HTML;        ← if the file has a <header>
?>
<template HTML with directives converted, components inlined>
<script> …cscript… </script>
```
Root (App) output adds `<!DOCTYPE html><html lang="…">` (0.0.3: `<?= htmlspecialchars(phpue_html_lang()) ?>`, resolved per request), the head (App header + view header + 404 header) and `<body>`.

Reading this output is the fastest way to debug any rendering issue:
```bash
cd www && php -r 'define("PHPUE_VERSION","0.0.3"); define("PHPUE_LANG","en"); require "conversion.php"; echo convert_pvue_file("views/page.pvue");'
cd www && php -r 'define("PHPUE_VERSION","0.0.3"); define("PHPUE_LANG","en"); require "conversion.php"; echo convert_pvue_file("App.pvue", true);'
```
(or `scripts/phpue-compile.php`, which sets these up from the project's `index.php`).
