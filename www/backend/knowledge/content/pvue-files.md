# .pvue files: the four blocks

> A `.pvue` file is a single-file component with up to four top-level blocks: `<script>` (server PHP), `<header>` (tags for `<head>`), `<template>` (HTML) and `<cscript>` (browser JavaScript). `conversion.php` compiles it to plain PHP. Every block is optional and appears at most once; keep them in this order.

```html
<!-- Comments outside the blocks are ignored -->
<script>
    // PHP without <?php tags. Runs on the server before the template.
    $items = ['One', 'Two'];
</script>

<header>
    <!-- Tags for <head>. App.pvue: dynamic. Views: static. Components: ignored. -->
    <title>Example</title>
</header>

<template>
    <!-- HTML + {{ $expr }} + p-if / p-for + raw <?php ?> + <Component/> -->
    <ul>
        <li p-for="$item in $items">{{ $item }}</li>
    </ul>
</template>

<cscript>
    // Browser JavaScript, printed as a <script> tag after this file's markup
    console.log({{ $items }});
</cscript>
```

## `<script>`: server PHP

- Plain PHP without `<?php` / `?>`. Anything PHP 8 can do works: `$_GET`, `$_POST`, `$_SESSION`, `$_SERVER`, `curl`, PDO through backend classes, closures.
- `<script setup>` is accepted as an alias.
- Three PHPue statements are only recognised here:
  - `@require Name 'components/Name.pvue';` imports a component (single or double quotes, path from the web root).
  - `#require 'views/name.pvue';` registers a view (only in `App.pvue`).
  - `@AJAX('POST')` / `@AJAX('GET')` before a function makes it callable from the browser.
- It is **PHP, not JavaScript**. A Vue habit of writing JS here produces a PHP parse error.

## `<header>`: tags for `<head>`

| File | Behaviour |
|---|---|
| `App.pvue` | Becomes a PHP heredoc. `{{ $var }}`, `{{ $arr['k'] }}` and `{{ $obj->prop }}` are injected **raw** (escape them yourself when building the string). Function calls and `??` are not converted; `<?php ?>` is printed literally. Avoid inline JS that uses `$` here. |
| `views/*.pvue` | Static HTML, added after the App header **only on that route**. `{{ $x }}` prints a literal `$x`; PHP is not run. `<title>` also names the route for `phpue_navigation()`. `<meta name="lang" content="fr">` sets `<html lang>` for that view. |
| components | Ignored. Put component CSS in a `<style>` inside its template. |

Never write `<html>`, `<head>` or `<body>`: PHPue prints them. The page is `<!DOCTYPE html><html lang="…"><head>` + App header + view header + `</head><body>` + App template + App cscript + `</body></html>`.

If both App and the view print a `<title>`, the App one comes first and wins. Pick one strategy: static titles in each view's header, or one dynamic title from the App header for every page.

## `<template>`: the HTML

The markup for this file, with `{{ }}`, `p-if`, `p-for`, raw PHP and component tags. See [Templates](/docs?topic=templates) and [Components](/docs?topic=components). In `App.pvue` the template contains `<View/>`, which is replaced by the current page.

Nested `<template>` elements (Alpine `<template x-for>`, `<template id>` for cloning) are fine as long as each one is closed.

## `<cscript>`: browser JavaScript

- Printed as `<script>…</script>` (or `<script type="module">` when a line starts with `import`/`export`) right after this file's markup. App cscript is printed before `</body>`.
- `{{ $expr }}` inside it becomes `json_encode($expr)`: numbers, strings (already quoted), arrays and objects arrive as JS values. Never wrap it in quotes.
- Raw PHP works inside it: `const n = <?= json_encode(count($items)) ?>;`.
- A component's cscript is repeated each time the component renders: avoid top-level `let`/`const` there.

Details: [cscript, AlpineJS and x-ssr](/docs?topic=client-js).

## How the compiler finds blocks (0.0.3)

- Only **top-level** tags count. Tag names inside comments, PHP strings or the template do not start or end blocks.
- `<script>`, `<header>` and `<cscript>` run to their **first** closing tag; `<template>` runs to its **matching** `</template>`.
- Consequence: a block cannot contain its own closing tag, even in a string or a comment. A literal closing script tag in a PHP string ends the PHP block and the rest of your code is silently dropped. Split it: `'</scr' . 'ipt>'`. Same for `</cscript>` and `</header>`.
- Only the first block of each kind is used.

## Naming conventions

- Views: kebab-case slugs, because the file name is the URL (`get-started.pvue` is `/get-started`).
- Components: PascalCase files in topic folders (`components/cards/ProductCard.pvue`).
- Booleans: `$b` prefix (`$bShowDetails`) in the author's code.
- Paths: always from the web root (`www/`), assets as absolute `/assets/...` URLs.
