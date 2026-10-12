# Upgrading from 0.0.2 to 0.0.3

> PHPue 0.0.3 is a bug-fix release. It replaces 0.0.2's line-based regular expressions with a block parser and tag-aware `p-if`/`p-for` conversion, so most 0.0.2 markup traps are gone. To upgrade, replace **both** `index.php` and `conversion.php` (they are one release), make sure the server runs PHP 8.0+, then check the behaviour changes below. Your `.pvue` files usually need no edits.

## How to upgrade

1. Check the current version: `grep -m1 PHPUE_VERSION www/index.php`.
2. Copy `index.php` and `conversion.php` from the 0.0.3 release of [PHPue](https://github.com/PHPue/PHPue) into `www/`. Re-apply any constants you changed (`PHPUE_LANG`).
3. Choose sessions: keep `PHPUE_SESSION = 'auto'` (0.0.2 behaviour) or switch to `'lazy'` for cacheable public pages, then add `phpue_session(true)` before session writes during page rendering.
4. Delete the old `.dist/` and rebuild: `php index.php build`.
5. Test every `@AJAX` call (see "Behaviour changes").

## Behaviour changes to check

| Area | 0.0.2 | 0.0.3 |
|---|---|---|
| AJAX dispatch | any existing PHP function named in `action` was callable (built-ins too); method not enforced | only `@AJAX` functions; wrong method gets 405 |
| AJAX in components | dropped from the build | built into `.dist/ajax/ajax-component-<Name>.php` |
| AJAX for views not `#require`d | missing in production | every view's handlers are built |
| Sessions | always started | `PHPUE_SESSION`: `'auto'` (default) or `'lazy'`, plus `phpue_session()` |
| `<html lang>` in `.dist` | frozen at build time | resolved per request; `phpue_set_lang()` added |
| Dev tools | `?compile=` and `?build` worked in every mode | only in source mode unless `PHPUE_DEV_TOOLS` is defined |
| Hot reload | watched one folder level | recursive |
| Build without `components/` | crashed | works |
| backend/ load order | filesystem order | sorted path order |
| PHP version | 7.4+ | 8.0+ |

If a client calls a plain function through `action`, it worked on 0.0.2 and returns the HTML page on 0.0.3: mark it `@AJAX('POST')` (or `'GET'`).

## What now works (no workaround needed on 0.0.3)

- `<p p-if="$x" class="a">Text {{ $y }}</p>` on one line, multi-line opening tags, `{{ }}` and `>` in other attributes of a `p-if` element, nested same-tag children, `p-if` on void elements.
- `p-for` over function calls (`range(1, 5)`), `PDOStatement`s and generators; nested same-tag children; `p-for` + `p-if` on one element.
- `{{ count($x) }}` and other function calls in `{{ }}`.
- `{{ $data['items'] }}` in `<cscript>`; `{{ $meta['title'] }}` and `{{ $obj->prop }}` in the App header.
- Nested `<template>` elements (Alpine `<template x-for>`).
- `<Card />` with a space, `@require` with double quotes, `p-if`/`p-for` on component tags.
- `@AJAX("POST")`, typed parameters, defaults with parentheses, return types.
- Block tag names inside comments and strings no longer hijack blocks; a bare `<header>` element in a template is kept; a bare `<script>` in a template is fine.

## Maintaining a 0.0.2 project (rules that still apply there)

If you cannot upgrade yet, follow these on 0.0.2 only:

- `p-if`: opening tag on **one line**, nothing after its `>` on that line, children on the next lines, closing tag on its own line. No `>` and no `{{ }}`/`<?= ?>` in that element's attributes. No one-line child of the same tag (`<div>x</div>` inside `<div p-if>`). No `p-if` on void elements.
- `p-for`: the source must be a variable or index (it is wrapped in `isset()`; a call is fatal and objects are skipped); the loop ends at the **first** closing tag of the same name, so never nest that tag; never combine `p-for` and `p-if` on one element.
- `{{ }}` only converts `$...` expressions: use `<?= count($x) ?>` for calls. In `<cscript>`, only simple variables convert: `<?= json_encode($a['b']) ?>`.
- Never use a nested `<template>` anywhere (the template block ends at the first `</template>`).
- Components: `@require` with single quotes only; `<Card/>` or `<Card></Card>` (no space).
- `@AJAX('POST')` with single quotes, `function name($input) {` with no return type and no `)` in defaults. Put handlers in views or App (not components), and `#require` every view.
- Never write a literal `<header>`, `<template>`, `<cscript>`, closing script tag or `<title>` in any comment or string. Give template `<header>` elements an attribute (`<header class="top">`).
- Keep a `components/` folder (the build crashes without it).
- Lazy sessions by hand: mention `session_start()` in the App script (which disables auto-start) and start the session only when the visitor already has a cookie.
