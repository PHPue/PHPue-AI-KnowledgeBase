# PHPue Vanilla: complete reference

Everything here was checked against `conversion.php` and `index.php` (PHPue **0.0.3**, with 0.0.2 differences marked **0.0.2:**) and the PHPue-TechDemo, PHPue-FreeCAD and PHPue-Analytics source. Check a project's version with `grep PHPUE_VERSION www/index.php`. When this file and older PHPue docs disagree (for example `{{ $user.name }}` or `#require Home '…'`), **this file reflects what the engine actually does**. 0.0.3 needs PHP 8.0+.

## Contents
1. Project layout
2. The .pvue file and its four blocks
3. `<script>`: PHP logic, `@require`, `#require`, `@AJAX`
4. `<header>`: head tags (App vs view)
5. `<template>`: `{{ }}`, `p-if`, `p-for`, forms (no `p-model`), raw PHP, components, `<View/>`
6. `<cscript>`: client JS and PHP-to-JS data
7. Components in depth
8. Views and routing
9. App.pvue
10. AJAX in depth
11. backend/ autoloading and singletons
12. Variable scope and lifecycle
13. Styling
14. Escaping PHPue syntax inside content (docs and code samples)
15. Idiomatic patterns from TechDemo

---

## 1. Project layout

```
www/                         ← web root and working directory for everything (paths are relative to it)
├── index.php                ← server, router, builder (framework core; don't edit casually)
├── conversion.php           ← .pvue → PHP regex compiler (framework core)
├── App.pvue                 ← root layout: global <header>, Navbar/Footer, <View/>
├── views/                   ← one .pvue per route (top level only): index.pvue → "/", about.pvue → "/about"
├── components/              ← reusable .pvue (any depth): components/navigation/Navbar.pvue
├── backend/                 ← *.php auto-required on every request (classes, singletons, extensions, API endpoints)
├── httpReqs/                ← http404.php, http404Head.php (custom 404), index.php (403 guard)
├── assets/                  ← css/ js/ img/ (served statically at /assets/...), each dir has an index.php 403 guard
├── .htaccess                ← front controller + security blocks (Apache)
├── tailwind.config.js, package.json (optional Tailwind CLI build)
└── .dist/                   ← build output (auto-generated; its presence switches the server to compiled mode)
```

Naming: views use kebab-case slugs (`get-started.pvue`, `ue-insights.pvue`). Components use PascalCase files in topic folders (`components/modals/ShareModal.pvue`, `components/index/template/Hero.pvue`). Code-sample components live in `components/codeBlocks/` and end in `CB`.

## 2. The .pvue file

```html
<!-- Author: Edward Patch -->          ← comments outside blocks are ignored
<script>
    // PHP (no <?php ?> tags). Runs on the server before the template.
</script>

<header>
    <!-- tags for <head>. App: dynamic via {{ $var }}. Views: static only. Components: ignored. -->
</header>

<template>
    <!-- HTML + {{ $expr }} + p-if / p-for + raw <?php ?> + <Component/> -->
</template>

<cscript>
    // Browser JavaScript, emitted as <script> after this file's markup
</cscript>
```

How blocks are found (0.0.3, `PHPueConverter::extractBlocks`): the file is scanned at the **top level** only. HTML comments between blocks are skipped, an opening `<script>`/`<script setup>`/`<header>`/`<cscript>` runs to its **first** closing tag, and `<template>` runs to its **matching** `</template>` (nested `<template>` elements are counted; PHP blocks and comments inside are skipped).
- So tag names inside PHP comments/strings, inside the template, or in HTML comments don't hijack blocks, a bare `<header>` element inside the template is kept, and Alpine `<template x-for>` works.
- But a block's own closing tag can't appear inside it: a literal closing script tag in a PHP string or comment ends the PHP block (split it: `'</scr' . 'ipt>'`).
- All blocks are optional. Each one appears at most once (only the first of each is used). Keep the order script → header → template → cscript.

**0.0.2:** `extractBetween` takes the **first** `<tag>` to the **first** `</tag>` anywhere in the file (non-greedy, exact bare tag names). The first bare `<script>` in the file is the PHP block, so keep the PHP block first; a literal block tag in any comment or string is matched as the real block; the template ends at the first `</template>`; and a bare `<header>` (no attributes) inside the template is stripped (write `<header class="topbar">`).

## 3. `<script>`: server logic

Plain PHP without `<?php` tags. Anything PHP 8 can do works: `$_GET`, `$_POST`, `$_SESSION` (auto-started unless `PHPUE_SESSION` is `'lazy'`, see §9), `$_SERVER`, PDO through backend singletons, `curl`, and so on.

### `@require`: import a component
```php
@require Navbar 'components/navigation/Navbar.pvue';
```
- Regex (0.0.3): `@require\s+(\w+)\s+['"]([^'"]+)['"]\s*;?`, so single or double quotes. **0.0.2: single quotes only.** The path is relative to `www/`.
- The component is compiled recursively and its compiled PHP and HTML replace `<Navbar/>` / `<Navbar />` (0.0.3) / `<Navbar></Navbar>` in this file's template.
- A missing file compiles to `// ERROR: Component file not found: …` (silent, nothing rendered).

### `#require`: register a view
```php
#require 'views/about.pvue';
```
- Only in `App.pvue`. Must be alone on its line. Single or double quotes.
- **The named form `#require About 'views/about.pvue'` does nothing.** It doesn't match, and PHP treats `#…` as a comment.
- Routing scans `views/*.pvue` (or `.dist/pages/*.php`) anyway. **0.0.2:** `#require` matters for the **build**, because only `#require`d views get `.dist/ajax/ajax-<view>.php` generated. 0.0.3 builds every view's handlers regardless. Always `#require` every view (it's the convention and keeps 0.0.2 builds correct).

### `@AJAX`: server functions callable from the browser
```php
@AJAX('POST')
function saveUser($input) {
    $name = trim($input['name'] ?? '');
    header('Content-Type: application/json');
    echo json_encode(['ok' => $name !== '']);
}
```
0.0.3:
- `@AJAX('POST')` / `@AJAX("GET")`, then `function name(...)` with any parameters (typed, defaults with parentheses such as `array $input = array()`) and an optional return type (`): void {`).
- The method is **enforced**: a request with the other method gets `405` (`Allow:` header + JSON error). Only `POST` and `GET` can ever be dispatched.
- Only functions marked `@AJAX` can be called. A plain helper function named in `action` is never called.
- Takes `$input` or no parameters. See §10.

**0.0.2:** the regex is `@AJAX\('([^']+)'\)\s*(function\s+\w+\([^)]*\))\s*\{`, so single quotes only, no return type and no `)` inside the parameter list (when the pattern doesn't match, `@AJAX(...)` is left in the PHP as a parse error). The method is documentation only, and **any** function (PHP built-ins included) can be called by name.

Everything else in `<script>` is normal PHP: assignments, `define()`, `use` of backend classes, closures, and `usort` with closures.

## 4. `<header>`

The compiled root page is always:
`<!DOCTYPE html><html lang="…"><head>` + App header + current view header + 404 header (if any) + `</head><body>` + App template + App cscript + `</body></html>`.
**Never write `<html>`, `<head>` or `<body>` yourself.**

| File | `<header>` behaviour |
|---|---|
| `App.pvue` | Becomes a PHP heredoc. `{{ $name }}` becomes `$name`, and (0.0.3) `{{ $meta['title'] }}` / `{{ $metas[0] }}` / `{{ $obj->tag }}` become `{$meta['title']}` etc., all interpolated **raw/unescaped**. Function calls and `??` are not converted (build the string in the script). **0.0.2:** simple variables only; write native heredoc syntax for arrays and objects (`{$metas[0]}`, `{$meta['title']}`, `{$obj->tag}`), as the author does. `<?php ?>` is **not** executed (printed literally). Any other bare `$word` is interpolated as well, so keep inline JS that uses `$` out of it (use a file or `<cscript>`). |
| `views/*.pvue` | Static HTML, inserted after the App header **only on that route**. `{{ $x }}` outputs the literal text `$x`, and `<?php ?>` is not executed. `src="assets/…"` / `href="assets/…"` get a leading `/` added. jQuery/Bootstrap `<script>` tags are moved to the end. |
| components | Ignored (not output anywhere). |

View header extras:
- `<title>` is also the page name returned by `phpue_navigation()` (falls back to `ucfirst(viewName)`).
- `<meta name="lang" content="fr">` or `<!-- lang: fr -->` sets `<html lang>` for that view (default is `PHPUE_LANG`, `'en'`, defined in `index.php`). 0.0.3 resolves it **per request**, also in `.dist`: `phpue_set_lang($lang)` (call it in the App script or a backend bootstrap, e.g. from a language selector) > view meta > `PHPUE_LANG`. **0.0.2:** in built `.dist` output, lang is baked once at build time (see runtime doc), and there's no `phpue_set_lang()`.
- An HTML comment in the header can't supply the `<title>` (0.0.3 strips comments before reading it; in 0.0.2 a `<title>` in a comment wins).
- Per-view `<style>` and `<script src>` (CDN libraries, Alpine, Chart.js, Three.js) belong here so they load only on that route.

**Two `<title>`s:** if App's header has a `<title>` and the view does too, the App one comes first and wins in browsers. For real projects use the official **MetaControl** extension plus a backend meta router (full pattern in `extensions.md`). The minimal TechDemo form only emits an App title when a dynamic one exists:
```php
// App.pvue <script>
$pageMeta  = \PHPueExt\MetaControl::getInstance();
$pageTitle = $pageMeta->getMetaVariable('page-title');
if (!empty($pageTitle)) $pageTitle = "<title>" . htmlspecialchars($pageTitle) . "</title>";
$pageDescription = $pageMeta->getMetaVariable('page-description');
if (!empty($pageDescription)) $pageDescription = '<meta name="description" content="'.htmlspecialchars($pageDescription).'">';
```
```html
<!-- App.pvue <header> -->
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
{{$pageTitle}}
{{$pageDescription}}
<link rel="stylesheet" href="/assets/css/tailwind-styles.css?v30JUN2026-1">
```
Views keep their static `<title>` and SEO/OG tags. Data-driven pages (blog post, tutorial, landing) set MetaControl variables from a backend "meta router" singleton keyed on the route.

## 5. `<template>`

### `{{ $expr }}`: escaped echo
- `{{ $… }}` becomes `<?= htmlspecialchars($expr ?? "") ?>`. In 0.0.3, an expression that starts with a function or static call becomes `<?= htmlspecialchars((string) (expr)) ?>`.
- ✅ `{{ $title }}`, `{{ $row['label'] ?? '' }}`, `{{ $post->title }}`, `{{ $currentYear - $data['birth'] }}`, `{{ $isActive ? 'active text-green-600' : 'text-slate-600' }}` (works inside attributes such as `class="nav {{ … }}"` and `href="{{ $r['url'] }}"`, including on `p-if`/`p-for` elements in 0.0.3).
- ✅ (0.0.3) `{{ count($x) }}`, `{{ strtoupper($s) }}`, `{{ number_format($price, 2) }}`, `{{ \App\Fmt::money($p) }}`. **0.0.2:** these are not converted and show literally, so use `<?= count($x) ?>` or `<?= htmlspecialchars(strtoupper($s)) ?>`.
- Anything else is left literal: `{{ !$x }}`, `{{ (int)$x }}`, `{{ item.name }}`. That's useful for docs and Alpine-style text, but Alpine-looking `{{ total() }}` **is** compiled as a PHP call in 0.0.3 (use `x-text`).
- ❌ `{{ $user.name }}`: PHP string concatenation (warning plus wrong output). Use `$user['name']` or `$user->name`.
- ❌ Arrays/objects inside `{{ }}`: `htmlspecialchars()` TypeError. Use `implode`, or pass the data to JS through `<cscript>`.
- Raw (unescaped) HTML: `<?= $trustedHtml ?>`.

### `p-if="condition"`: conditional element
```html
<div p-if="$bShowUserDetails" class="card">
    <h2>Details</h2>
    <p>{{ $name }}</p>
</div>
<div p-if="!$bShowUserDetails" class="card">
    Nothing yet.
</div>
```
**0.0.3** compiles `p-if` tag by tag: it finds the element's real closing tag by counting same-name tags (quotes, `<?php ?>`, comments, `{{ }}` and `<script>`/`<style>` bodies are skipped), then writes `<?php if(cond): ?>` + the element without `p-if` + `<?php endif; ?>`. So all of these work:
```html
<p p-if="$x" class="hint">Text {{ $y }}</p>                       <!-- one line -->
<span p-if="$x" class="badge {{ $tone }}">New</span>              <!-- {{ }} in its attributes -->
<div p-if='$ok' x-show="n > 0" @click="items.map(i => i)">…</div> <!-- '>' / '=>', single quotes -->
<div p-if="$a"><div>one</div><div>two</div></div>                 <!-- same-tag children -->
<input p-if="$editable" name="title" value="{{ $title }}">         <!-- void element -->
<div
    p-if="$x"
    class="card">…</div>                                           <!-- multi-line opening tag -->
```
- The element must be **closed** (or void/self-closing). Otherwise the compiler logs `has no closing </tag>` and wraps only the opening tag, so the content always renders.
- `p-if` inside `<?php ?>`, HTML comments, `{{ }}` and `<script>`/`<style>` bodies isn't converted.
- The condition is any PHP expression (`p-if="count($listRows) === 0"`, `p-if="$user['role'] === 'admin'"`). Use the other quote style if it needs quotes of its own.
- There is **no `p-else`, `p-else-if` or `p-show`.** Use a negated second `p-if` (author's pattern) or raw PHP (below).

**0.0.2** works **line by line**, so:
- The opening tag must be on **one line**, and **nothing may follow its `>` on that line** (the rest of the line is deleted and the `if` never closes, which gives `unexpected end of file`).
  - ❌ `<p p-if="$x" class="hint">Text</p>`
  - ✅ `<p p-if="$x" class="hint">` / `Text` / `</p>` on three lines.
- No `>` inside any attribute of that element (`x-show="n > 0"`, `@click="a => b"`).
- **No `{{ }}` / `<?= ?>` inside that element's attributes** (`<span p-if="$x" class="badge {{ $tone }}">`). `{{ }}` is converted first, its `?>` ends the p-if match, and `">` is lost, so the attribute stays open and eats the following markup (cards nested inside each other in Experiment 01). Put dynamic attributes on a child element. (`p-for` is converted before `{{ }}`, so `<a p-for="…" href="{{ $r['url'] }}">` is fine.)
- Depth tracking counts lines, not tags: a later line containing `<tag …>` without `</tag>` is +1, and any line containing `</tag>` is −1. A one-line child of the **same tag** (`<div>x</div>` inside `<div p-if>`) therefore ends the `if` early. Pick a wrapper tag the children don't use on one line (`section`, `article`, `aside`), or use raw PHP.
- The condition is any PHP expression without double quotes, and void elements can't carry `p-if` (the `if` never closes).

Raw-PHP if/else (both versions):
```html
<?php if ($showTechDemo): ?>
    <Playground/>
<?php else: ?>
    <Main/>
<?php endif; ?>
```

### `p-for="$item in $array"`: repeated element
```html
<a p-for="$route in $routes" href="{{ $route['url'] }}" class="nav-link">{{ $route['title'] }}</a>

<option p-for="$fruit in $fruits" value="{{ $fruit }}">{{ $fruit }}</option>

<li p-for="$slug => $post in $posts">
    <a href="/blog?post={{ $slug }}">{{ $post['title'] }}</a>
</li>
```
**0.0.3** compiles to `<?php foreach (phpue_iterable((<expr>) ?? null) as $item): ?><tag …>content</tag><?php endforeach; ?>`, using the same tag-aware matching as `p-if`.
- `$k => $v in <expr>` works. `<expr>` is any PHP expression: `$rows`, `$data['items']`, `range(1, 9)`, `array_slice($cats, 0, 2)`, `$stmt` (PDOStatement), `new ArrayIterator(…)`, generators. Non-iterables and unset variables render nothing.
- Same-tag children are fine: `<div p-for="$r in $rows"><div>{{ $r['v'] }}</div></div>`.
- `p-for` + `p-if` on one element loops and then filters each item: `<li p-for="$n in $numbers" p-if="$n % 2 === 0">{{ $n }}</li>`.
- An invalid value (`p-for="row of $rows"`) isn't converted (the attribute becomes `data-phpue-invalid-p-for`).
- Components inside a p-for body work: `<li p-for="$r in $rows"><Row/></li>`, where `$r` is visible inside `Row`. In 0.0.3 the component tag itself can loop: `<Row p-for="$r in $rows" />`.

**0.0.2** compiles to `<?php if(isset($array) && is_array($array)): foreach($array as $item): ?>…<?php endforeach; endif; ?>`:
- The element ends at the **first** `</tag>` of the same name, so **don't nest the same tag**. ❌ `<div p-for>` containing `<div>`s. ✅ `<article p-for>` / `<li p-for>` / `<tr p-for>` with `<div>`/`<td>` children, or `<?php foreach (…): ?> … <?php endforeach; ?>`.
- The opening tag may span lines.
- `$array` must work inside `isset()`: a variable, `$a['k']` or `$o->p`. ❌ `p-for="$x in getItems()"` (fatal). Objects/Traversables are skipped silently by `is_array`, so call `fetchAll()` or `iterator_to_array()` first.
- Don't combine `p-for` and `p-if` on one element. Nest them, or filter the array in `<script>`.

### Forms: no `p-model` (privacy-first, by design)
`conversion.php` still contains a `p-model` regex, but it's a **leftover placeholder**. The official release deliberately abandoned it (TechDemo docs, "Form Handling Philosophy"): PHPue avoids automatic form tracking and two-way binding that watches every keystroke, and expects explicit, intentional submissions. **Don't use `p-model`.** Write the attributes yourself:
```html
<input name="email" type="email" value="{{ $email }}">
<textarea name="message">{{ $message }}</textarea>
<option p-for="$t in $topics" value="{{ $t }}">{{ $t }}</option>
```
Submit explicitly: a `<form onsubmit>` that `fetch`es JSON to an `@AJAX` handler, or a normal `method="post"` form handled in the view's `<script>` (with no JSON `action`, the request falls through to normal page rendering, so `$_POST` is available there). If live client-side state is needed, that's the browser's job (vanilla JS or Alpine `x-model`), not a PHPue directive.

### Raw PHP in templates
`<?php … ?>` and `<?= … ?>` pass straight through. Use them for if/else, foreach over objects, function calls, setting variables a component will inherit, and raw HTML:
```html
<?php foreach ($techStack as $tech => $data): ?>
<div class="bg-gradient-to-br {{$data['color']}} rounded-2xl p-6">
    <h4>{{$tech}}</h4>
</div>
<?php endforeach; ?>
```

### Components and `<View/>`
- `<Navbar/>`, `<Navbar />` (0.0.3) and `<Navbar></Navbar>` are equivalent. No attributes and no children, except `p-if`/`p-for` in 0.0.3 (`<Navbar p-if="$bShowNav"/>`). In 0.0.2, `<Navbar />` (with a space) is **not** replaced and is left as an unknown tag.
- `<View/>`, `<View />` (0.0.3) or `<View></View>` (App only) is replaced by the current route's rendered page (or the 404 page).

### Inline JS/CSS in templates
- `<style>…</style>` in a template is fine (component-local CSS).
- `<script type="module">…</script>` or `<script defer src>` (with attributes) in a template is fine.
- A bare `<script>` in a template is fine in 0.0.3 (blocks are found at the top level only), but don't put `<template>`/`</template>` text inside it (it still counts toward template nesting). **0.0.2:** a bare `<script>` in a template is safe only if the file's PHP `<script>` block appears earlier; with no PHP block, that JS is compiled **as PHP**. Prefer `<cscript>`.

## 6. `<cscript>`: client JavaScript

```html
<cscript>
    let btnCounter = {{$btnCount}};            // number → 3
    let fruit = {{$fruits}};                   // array → ["🍌 Banana","🍎 Apple"]
    const user = {{ $user }};                  // assoc array → {"name":"Ada"}
    const second = <?= json_encode($fruits[1]) ?>;  // expressions → use json_encode yourself
    document.getElementById('fruit').innerText = fruit.join(' • ');
</cscript>
```
- Output: `<script>…</script>`, or `<script type="module">` if any line starts with `import` / `export`. In module mode, functions aren't global, so assign `window.fn = fn` for inline `onclick`.
- `{{ $name }}` becomes `<?= json_encode($name, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>`. In 0.0.3 any expression that starts with `$` converts (`{{ $data['items'] }}`, `{{ $user->profile }}`); function calls don't (`<?= json_encode(count($x)) ?>`). **0.0.2:** only **simple variable names** convert (`{{ $a['b'] }}` is left as broken JS). Don't put quotes around it, because strings already come out quoted.
- Raw PHP works inside it (`<?= $page ?>`, `<?= json_encode(...) ?>`).
- Placement: App cscript before `</body>`. A view's or component's cscript sits right after its own markup (inline in the page), and is repeated **every time** the component is used. Top-level `let`/`const` in a reused component throws `Identifier already declared`, so use `function` declarations, `var`, or an IIFE/guard.
- It runs as the parser reaches it. Elements above it exist; wrap in `DOMContentLoaded` for elements below or for cross-component wiring. For Alpine, register inside `alpine:init`.
- The vanilla interactivity style: global functions plus inline handlers, for example `<button onclick="incrementCounter('saveBtnCounter')">`.

## 7. Components in depth

A component is any `.pvue` that is `@require`d. Compilation **inlines** its compiled output (preamble, script, template, cscript) at each placeholder. Consequences:

- **Inherited scope instead of props (by design).** A component is inlined, so it runs in the scope of the file that includes it, exactly like a PHP `include`. It already sees every variable the parent has at that point (including the current `p-for` item), so PHPue needs no prop API. To give it data, set or reuse a variable before the tag. Variables the component sets also stay visible to the parent afterwards:
  ```php
  // components/DetailedView.pvue
  <template>
      <section class="rounded-2xl border p-6">
          <h1 class="text-3xl font-bold">{{ $title ?? '' }}</h1>
          <p>{{ $desc ?? '' }}</p>
      </section>
  </template>
  ```
  ```html
  <!-- in a view template -->
  <?php
      $title = "Traffic Depth";
      $desc  = "GA4-style breakdown of volume and delivery health.";
  ?>
  <DetailedView/>
  ```
  Default and normalise inherited variables at the top of the component script, so it also works where the parent didn't set them:
  ```php
  $listTitle = $listTitle ?? '';
  $listRows  = (isset($listRows) && is_array($listRows)) ? $listRows : [];
  $specialBTNOnly = isset($specialBTNOnly) && $specialBTNOnly === true;
  ```
  Use specific names (`$listTitle`, `$cardUser`) for variables a component reads or sets, because writes go straight into the parent's scope.
  Inside a loop no setup is needed: `<div p-for="$product in $products"><ProductCard/></div>` and the card reads `$product`.
- **Re-run per use.** The script runs on each placement, so components can sit inside loops and receive the loop variable.
- **No named functions/classes** in component scripts. A second use (or a `p-for`) means `Cannot redeclare`. Put helpers in `backend/` or use closures (`$fmt = fn($n) => number_format($n);`).
- **`@AJAX` in components:** 0.0.3 registers component handlers on every page in dev and builds them into `.dist/ajax/ajax-component-<Name>.php` (`@AJAX` functions are global, so the handler works from any page). **0.0.2:** in dev a component handler is only available on pages rendering that component, and the build drops it, so put it in the hosting view or App. Keeping handlers in the view is still the clearest layout: TechDemo's `indexForm.pvue` posts `action: 'ajaxFormSubmit'`, which lives in `views/index.pvue`.
- **`<header>` is ignored.** Put CSS in a template `<style>` or the view/App header.
- **Nesting** works (components can `@require` components). Paths are always from `www/`.
- Unrequired `<Tag/>` usage adds a `// WARNING: … used but not required` comment to the compiled PHP and renders nothing useful.
- Each compiled file also re-runs the backend autoload preamble and resets `$current_route`, `$routing` and `$b404`. Avoid naming your own variables `$routing`, `$current_route`, `$b404`, `$input` or `$action` (0.0.2 also clobbers `$file`, `$iterator` and `$backendDir`; 0.0.3 prefixes its loader variables with `$phpueBackend`).

## 8. Views and routing

- Route = file name of `views/<name>.pvue`; `index` → `/`. The current route slug is `$_GET['page']`, derived from the URL path with slashes trimmed, so `/about/` resolves to `about`. **`page` is therefore reserved:** a `?page=2` query value is overwritten, so paginate with `?p=` or `?pagination=`.
- No nested routes: `/blog/my-post` → slug `blog/my-post` → 404. Use query parameters (`/ue-insights?postId=8`, `/ue-tutorials?tutorialName=first-app`) and branch inside one view, or use the PrettyUrlRedirect extension (`backend/phpue-extensions/phpue-prettyUrlRedirect.php`), which 301-redirects `/page/key/value` → `/page?key=value` and strips trailing slashes.
- Sub-pages inside one view (TechDemo `ue-tutorials.pvue`): `@require` every sub-component and choose with PHP on query params:
  ```html
  <?php if ($tutorialNameParam === 'first-app'): ?>
      <FirstAppTutorial/>
  <?php elseif ($browseTutorials): ?>
      <Browse/>
  <?php else: ?>
      <MainBody/>
  <?php endif; ?>
  ```
- Helpers available everywhere:
  - `phpue_navigation()` → `[['name'=>'about','title'=>'About','url'=>'/about'], …]`. The title comes from the view `<title>`. Order is file order, so sort it yourself (TechDemo Navbar sorts by a `$urlOrder` list and moves some routes into a "More" submenu).
  - `phpue_current_meta()` → the current view's parsed header meta.
  - `get_current_route()` (from `index.php`) → the current slug (`index` for `/`), usable anywhere, including the App script and backend.
  - Active link: compare `$_SERVER['REQUEST_URI']` with `$route['url']`.
- 404: an unknown slug renders `httpReqs/http404.php` inside the App layout (or a built-in message) with HTTP 404, plus `httpReqs/http404Head.php` head tags. See the runtime doc.

## 9. App.pvue

```html
<!-- Author: … -->
<script>
    @require Navbar 'components/navigation/Navbar.pvue';
    @require Footer 'components/footer/Footer.pvue';

    #require 'views/index.pvue';
    #require 'views/about.pvue';

    // global, request-wide logic: env, meta, consent…
    $env  = Environment::getInstance();
    $gTag = $env->getConfig()['g-tag'];          // prebuilt <script> HTML, injected raw below

    @AJAX('POST')
    function cookies($input) {                    // app-wide AJAX (works from every page)
        Environment::getInstance()->setCookies((bool)($input['cookies'] ?? false));
    }
</script>

<header>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" href="/assets/css/tailwind-styles.css">
    <link rel="stylesheet" href="/assets/css/styles.css">
    {{$gTag}}
</header>

<template>
    <Navbar/>
    <View/>
    <Footer/>
</template>
```
- Sessions (0.0.3): `PHPUE_SESSION` in `index.php` is `'auto'` (default: `session_start()` on every request, like 0.0.2) or `'lazy'` (only resume a session the visitor already has, so anonymous pages are cacheable). Under `'lazy'`, call `phpue_session(true)` before writing `$_SESSION` during page rendering; POST `@AJAX` handlers start one automatically. Extensions should call `phpue_session()` instead of `session_start()`. If the App script text contains `session_start()` (even in a comment), PHPue's session handling is skipped. **0.0.2:** auto-started on every request unless the App script contains `session_start()`.
- App-level `@AJAX` is the place for cross-page actions (cookie consent, global toggles).

## 10. AJAX in depth

**Server.** Every request first runs the compiled App preamble. If the request is `POST` with a JSON body containing `"action"`, or `GET` with `?action=`, and that name is a registered `@AJAX` function (0.0.3; **0.0.2:** any existing PHP function), it's called and the script **exits** before any HTML:
- 0.0.3 enforces the declared method: `@AJAX('POST')` called with GET (or the reverse) gets `405`, an `Allow:` header and `{"error": "@AJAX 'name' only accepts POST"}`. POST handlers get a session started (`phpue_session(true)`).
- `$input` = `json_decode(php://input, true)` for POST, or `$_GET` for GET (which includes `page`).
- Functions with ≥1 parameter receive `$input`; zero-parameter functions are called with no arguments.
- Output is whatever the function echoes. Return values are ignored. Set `header('Content-Type: application/json')` when returning JSON. Use `http_response_code(4xx)` for errors.
- If `action` names no `@AJAX` function, the request falls through and returns the **full HTML page with status 200**. Clients should check `response.ok` and the content type, or `try { await res.json() }`. (Upgrading from 0.0.2: if the client calls a plain function by `action`, mark it `@AJAX('POST')`; the linter flags these.)
- `$_SESSION` is available. Backend singletons are loaded.
- Available handlers. 0.0.3: App + every view + every component (recursively), in dev and in `.dist` (`.dist/ajax/ajax-*.php`), so any page URL works, though posting to the page that owns the handler (`fetch('')` or `window.location.pathname`) stays the clearest. **0.0.2:** dev has `App.pvue` + all `views/*.pvue` + components required by the requested page; production has App + every `#require`d view only, so **post to the page URL that owns the handler**.
- Only JSON bodies are read for POST. `FormData`/multipart uploads don't dispatch. For uploads use a **backend endpoint** (see §11) and `fetch('?api&endpoint=fileUpload', {method:'POST', body: formData})`.
- Security: validate and authorise inside every handler and never trust `$input`. 0.0.3 only dispatches `@AJAX` functions with their declared method. **0.0.2:** the dispatcher calls **any** existing function by name, including PHP built-ins and backend helpers, so don't name helper functions after actions.

**Client (vanilla).**
```js
async function submitForm(event, methodName) {
    event.preventDefault();
    const formData = new FormData(event.target);
    const response = await fetch('', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ action: methodName, formData: Object.fromEntries(formData) })
    });
    if (response.ok) window.location.reload();   // SSR re-renders from $_SESSION
}
```
```html
<form onsubmit="submitForm(event, 'ajaxFormSubmit')"> … </form>
```
Server side:
```php
@AJAX('POST')
function ajaxFormSubmit($input) {
    $formData = $input['formData'] ?? [];
    $_SESSION['subForm_name']  = $formData['name'] ?? '';
    $_SESSION['bShowUserDetails'] = true;
}
```
The common vanilla loop is: **AJAX mutates `$_SESSION`/DB → `location.reload()` (or `location.href = location.pathname`) → SSR renders the new state.** For no-reload updates, echo JSON and patch the DOM in `<cscript>` (TechDemo counter: optimistic `innerText` update plus fire-and-forget POST). GET handlers: `fetch('?action=indexGet&token=abc')`.

## 11. backend/ autoloading and singletons

- Every compiled file starts by recursively `require_once`-ing all `*.php` under `backend/` (or `.dist/backend/` if present): in **sorted path order** in 0.0.3, in **filesystem order** in 0.0.2 (it differs per OS and filesystem). If a directory contains `autoload.php`, only that file is required and the directory isn't scanned deeper. Composer `vendor/autoload.php` works, and a custom `autoload.php` controls load order.
- Don't rely on load order either way. If a file `extends` or uses another backend class at include time, put them in a sub-folder with an `autoload.php`: either an ordered `require_once` list or a lazy `spl_autoload_register` class map (the author's LanguageSelector pattern). PHPue then includes only that `autoload.php` for the folder.
- Files may run code at include time. They run on **every request, before routing and AJAX dispatch**, including AJAX calls and 404s. Keep them to class/function definitions plus idempotent bootstraps (`Environment::getInstance();`). Never echo from them.
- Use `extends`/`implements` across files only with an `autoload.php` that fixes the order.
- Author's conventions:
  ```php
  <?php
      class DBLoader {
          private static ?self $instance = null;
          private ?PDO $connection = null;
          public static function getInstance(): self {
              if (self::$instance === null) self::$instance = new self();
              return self::$instance;
          }
          public function getConnection(): PDO { /* lazy PDO with ERRMODE_EXCEPTION, FETCH_ASSOC, real prepares */ }
          public function fetchPosts(?string $category = null, int $limit = 10): array { /* prepared statements, bindValue */ }
      }
  ?>
  ```
  - `Environment` singleton: dev detection (`SERVER_NAME === 'localhost' || SERVER_ADDR === '127.0.0.1' || isset($_GET['dev'])`), DB config, cookie consent, analytics snippet.
  - Extensions in `backend/phpue-extensions/` with a `PHPueExt` namespace, guarded by `if (defined('PHPUE_VERSION') && version_compare(PHPUE_VERSION, '0.0.1', '>='))`. Examples: `\PHPueExt\MetaControl` (session-backed meta map: `setMetaVariable`, `getMetaVariable`, `unsetMetaVariables`, `setRendered`) and `PrettyUrlRedirect::init()`.
  - Meta router: `backend/meta-scripts/phpueMetaRouter.php` maps the first path segment to a `*Meta` singleton that sets MetaControl variables from query params or DB.
- **Backend API endpoint pattern** (Analytics `backend/forms/submit.php`). Use it when `@AJAX` doesn't fit (multipart uploads, raw responses):
  ```php
  <?php
  if (isset($_GET['api'], $_GET['endpoint']) && in_array($_GET['endpoint'], ['fileUpload', 'loadExample'], true)) {
      header('Content-Type: application/json');
      if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['success' => false, 'error' => 'Invalid method']); exit; }
      // … handle $_FILES / $_POST, store results in $_SESSION …
      echo json_encode(['success' => true, /* … */]);
      exit;
  }
  ```

## 12. Variable scope and lifecycle

Request order (source mode). In `.dist` mode the same order applies, using precompiled files:
1. `index.php`: `ob_start()`, `$_GET['page']` = path slug, routing built from `views/*.pvue` (or `.dist/pages/*.php`). Unknown route → `$_GET['page']='404'`, status 404.
2. Current view is pre-compiled. App compiled and `eval`'d **inside a method**, giving it local scope.
3. App preamble: backend autoload → 404 header prep → session (`phpue_session()` per `PHPUE_SESSION` in 0.0.3; `session_start` in 0.0.2) → AJAX handler definitions → **AJAX dispatch (exit)**.
4. App `<script>` → `$phpue_header` → `<html lang>` (0.0.3: `phpue_html_lang()`, so `phpue_set_lang()` must run before this) → `<head>` (App header + view header + 404 header).
5. App template: components inline (App scope) → `<View/>` → the view's compiled code runs inside `PHPRouting::getCurrentPageContent()` (**separate local scope**) → its components (view scope) → its cscript.
6. App cscript → `</body></html>` → `ob_end_flush()`.

So: **App variables ≠ view variables.** Views and their components share one scope, and App and its components share another. Share across them with `$_SESSION`, constants (`define('APP_ROOT', __DIR__)` in App), `$GLOBALS['x']`, or singletons. Because output is buffered, `header()`/`setcookie()` work from any script block.

## 13. Styling

- **Tailwind (default in all three projects).** `package.json` → `"build": "npx tailwindcss@3.4.0 -i assets/css/tailwind-src.css -o assets/css/tailwind-styles.css --minify"`. `tailwind.config.js` `content` must include `"./**/*.pvue"` and `"./**/*.php"` (and `"./assets/**/*.js"` if JS builds class strings). Link `/assets/css/tailwind-styles.css?v=…` in the App header and **rerun the build after adding new classes**. Arbitrary values (`text-[#47a783]`, `hover:shadow-[0_0_25px_rgba(…)]`) are used freely. Quick prototypes may use the Tailwind CDN script in the App header instead.
- Global CSS: `/assets/css/styles.css` in the App header.
- Per-route CSS: `<style>` in the view `<header>`.
- Component CSS: `<style>` inside the component `<template>` (emitted inline wherever it's used).
- Asset URLs: prefer absolute `/assets/...`. Relative `assets/...` works in templates because all routes are one segment deep, but absolute is safer. Cache-bust with `?v=<date>`.

## 14. Escaping PHPue syntax inside content

When a page **shows** PHPue code (docs, tutorials, code blocks), stop the compiler from executing it:
- `{{ … }}` → write `&#123;&#123; $title &#125;&#125;` (or `<?= "{{" ?> … <?= "}}" ?>`).
- Directive names: 0.0.3 only converts directives on real opening tags, so escaped markup such as `&lt;li p-for="$item in $items"&gt;` is safe. **0.0.2:** write `&lt;li <?= "p-for" ?>="$item in $items"&gt;` (the engine looks for `p-for="` literally).
- Tags → `&lt;template&gt;`, `&lt;script&gt;`, `&lt;header&gt;` (a real `<template>`/`</template>` changes the template nesting).
- In PHP strings that hold sample code, split a closing script tag (`'</scr' . 'ipt>'`), because it ends the PHP block in both versions.
- Code samples go in their own component (`components/codeBlocks/XyzCB.pvue`) with a single `<pre><code>` template.

## 15. Idiomatic patterns from TechDemo

- **Session-driven UI state.** Read flags in the view script (`$bFillFormBtnHidden = $_SESSION['bFillFormBtnHidden'] ?? false;`), toggle them with `@AJAX` handlers, then reload.
- **Preference switch.** `setViewPreference` AJAX stores `$_SESSION['viewPreference']`, and the index view chooses `<Playground/>` vs `<Main/>` with raw PHP if/else.
- **Login gate.** `<div p-if="!$admin">` wraps `<Login></Login>`, and `<div p-if="$admin">` wraps the admin UI. `$admin` comes from `$_SESSION['AUTH_TOKEN']`.
- **Cookie consent.** A component with a fixed banner, `<cscript>` reading `document.cookie`, and posting `action: 'cookies'` to an App-level `@AJAX`, which sets the cookie server-side. `Environment` reads it to decide whether to emit the gtag snippet.
- **DB pages.** The view script calls `DBLoader::getInstance()->fetchPosts(...)`, data goes to sidebar/post components through shared variables, and like/share/newsletter actions are view-level `@AJAX` that call singleton methods with validated input (`filter_var($email, FILTER_VALIDATE_EMAIL)`).
- **Accessibility.** The author consistently uses `aria-*`, `sr-only` skip links and `role` attributes. Keep that up.
