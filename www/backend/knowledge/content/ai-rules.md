# Rules for AI coding agents

> Read this before generating any PHPue code. PHPue is new and small, so general training data about it is thin and often wrong. When this page disagrees with older blog posts or docs (for example `{{ $user.name }}`, `p-model` or "PHP 7.1+"), **this page reflects what the PHPue 0.0.3 compiler actually does**. Every rule here was verified by compiling real `.pvue` files.

## Step 1: check the version

```bash
grep -m1 PHPUE_VERSION www/index.php     # define('PHPUE_VERSION', '0.0.3');
```

These rules are for **0.0.3**. 0.0.2 is much stricter about markup layout; if the project is on 0.0.2, read [Upgrading from 0.0.2](/docs?topic=upgrade) first and either upgrade (replace `index.php` **and** `conversion.php` together) or follow the 0.0.2 rules there. Never write 0.0.3-only markup into a 0.0.2 project.

## The hard rules

1. **A `.pvue` file has up to four top-level blocks, in this order:** `<script>` (PHP, no `<?php` tags), `<header>` (head tags), `<template>` (HTML), `<cscript>` (browser JS). Each appears at most once. Anything outside them is ignored. Never write `<html>`, `<head>` or `<body>`.
2. **`{{ }}` converts expressions that start with `$` or a function/static call:** `{{ $user['name'] }}`, `{{ $a ? 'x' : 'y' }}`, `{{ count($items) }}`. Output is `htmlspecialchars`-escaped. Anything else (`{{ !$x }}`, `{{ (int)$x }}`, `{{ item.name }}`) stays literal text. **Never `{{ $user.name }}`**: `.` is PHP string concatenation. Use `$user['name']` or `$user->name`. Arrays inside `{{ }}` throw. Raw HTML: `<?= $trustedHtml ?>`.
3. **`p-if="cond"`** works on any closed element (or void element): one line, multi-line, nested same-tag children, `{{ }}` in other attributes. There is **no `p-else`, `p-else-if` or `p-show`**: write a negated second `p-if` or raw `<?php if: ?> ... <?php else: ?> ... <?php endif; ?>`.
4. **`p-for="$item in <expr>"`** or `p-for="$key => $value in <expr>"`; `<expr>` can be any PHP expression (`$rows`, `$data['items']`, `range(1, 5)`, a `PDOStatement`, a generator). Non-iterables render nothing. `p-for` and `p-if` on the same element loop first, then filter each item.
5. **Components:** declare with `@require Card 'components/Card.pvue';` in `<script>` (path from the web root), use as `<Card/>`, `<Card />` or `<Card></Card>`. **No attributes, no props, no slots, no children** (only `p-if`/`p-for` on the tag). A component is inlined and **inherits every variable of the parent** at that point. Set variables before the tag (`<?php $cardTitle = 'X'; ?><Card/>`) or reuse a `p-for` loop variable, and default them in the component (`$cardTitle = $cardTitle ?? '';`). Do not invent a props mechanism.
6. **Views are routes:** `views/<name>.pvue` is `/<name>`, `views/index.pvue` is `/`. Only top-level files; no nested paths (`/blog/post` is a 404), use query strings (`/blog?post=slug`). Register every view in `App.pvue` with `#require 'views/name.pvue';` (no name before the path). `<View/>` appears only in `App.pvue`.
7. **The view `<header>` is static HTML** (`{{ $x }}` prints a literal `$x`, PHP is not run). Only `App.pvue`'s header is dynamic, and only for `{{ $var }}`, `{{ $arr['k'] }}` and `{{ $obj->prop }}`, injected **raw**. Build dynamic meta strings in the App script (or use the MetaControl extension).
8. **Scope:** App, each view and `@AJAX` handlers run in separate scopes. App variables are **not** visible in views. Share data through `backend/` singletons, `$_SESSION`, `define()` or `$GLOBALS`.
9. **`@AJAX('POST')` / `@AJAX('GET')`** goes directly before `function name($input) { ... }` in a `<script>` block. Handlers must **`echo`** (usually `json_encode`), because return values are discarded. They run **before** the page script, so they see no page variables. The declared method is enforced (wrong method: 405). Function names are global, so keep them unique. Client: `fetch('', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({action: 'name', ...})})` or `fetch('?action=name&x=1')`. Only JSON bodies dispatch; `FormData` does not.
10. **`<cscript>` becomes a `<script>` tag.** Inside it `{{ $expr }}` becomes `json_encode($expr)`, so never wrap it in quotes (`"{{ $name }}"` produces `""Ada""`). Function calls are not converted there: `<?= json_encode(count($x)) ?>`.
11. **A block cannot contain its own closing tag**, even in a string or comment: a literal closing script tag inside the PHP block ends it. Split it: `'</scr' . 'ipt>'`. Same for `</cscript>` and `</header>`.
12. **No named functions or classes in components** (redeclared when the component is used twice or in a loop). Put them in `backend/`, or use closures. Avoid top-level `let`/`const` in a reused component's `<cscript>`.
13. **Use `getcwd()` for file paths**, not `__DIR__` (in `.pvue` code `__DIR__` changes once the app is built into `.dist/`).
14. **Sessions:** with `PHPUE_SESSION = 'lazy'` (recommended for public sites), call `phpue_session(true)` before writing `$_SESSION` while rendering a page. POST `@AJAX` handlers get a session automatically. `$_GET['page']` is reserved for the route: paginate with `?p=`.
15. **If `.dist/` exists, edits to `.pvue` files do nothing** (the compiled copy is served). Delete `.dist/` while developing, or rebuild with `php index.php build`.
16. **Never edit `index.php` or `conversion.php`** to make a feature work. They are the framework core, shared by every PHPue project. If a framework change seems necessary, say so.

## Never bring in

| From | Do not write | PHPue way |
|---|---|---|
| Vue | `v-if`, `v-for`, `:prop`, `@event` on components, `v-model`, `<style scoped>`, `<script setup>` with JS | `p-if`, `p-for`, inherited variables, explicit form submits, `<style>` in the template, JS in `<cscript>` |
| Vue/PHPue myths | `p-else`, `p-show`, `p-model`, `p-bind` | negated `p-if`, raw PHP, `value="{{ $x }}"` |
| React/Next.js | JSX, `useState`, `useEffect`, client routers, hydration | PHP variables, server-side data fetching, file routes |
| Laravel Blade | `@if`, `@foreach`, `@include`, `{!! !!}` | `p-if`, `p-for`, `<Component/>`, `<?= ?>` |
| Tooling | npm bundlers, Vite, webpack, Composer at the project root | nothing (optional Tailwind CLI); vendor code goes in `backend/` |

`p-model` still exists in `conversion.php` only as an abandoned placeholder. Never use it: write `name="email" value="{{ $email }}"` and submit on purpose.

## Where code goes

| Need | Put it in |
|---|---|
| Data loading, form handling, page logic | the view's `<script>` |
| Markup | `<template>` |
| Page title and meta for one static page | the view's `<header>` |
| Dynamic title/meta, global CSS/JS, analytics | `App.pvue` `<header>` + App `<script>` |
| Browser behaviour | `<cscript>` (or `/assets/js/*.js`) |
| Classes, DB access, helpers, API clients | `backend/*.php` (singletons with `getInstance()`) |
| Browser-callable server actions | `@AJAX` functions in the view (or App for site-wide actions) |
| File uploads, non-JSON endpoints | a `backend/` endpoint file (`?api&endpoint=...`) |

## Verify before you answer

1. Compile the file you changed and syntax-check the result (run from `www/`):

```bash
php -r 'define("PHPUE_VERSION","0.0.3"); define("PHPUE_LANG","en"); require "conversion.php"; echo convert_pvue_file("views/page.pvue");' | php -l
php -r 'define("PHPUE_VERSION","0.0.3"); define("PHPUE_LANG","en"); require "conversion.php"; echo convert_pvue_file("App.pvue", true);' | php -l
```

Drop the `| php -l` to read the compiled PHP. In source mode the dev server also shows it: `http://localhost:3000/?compile=views/page.pvue`.

2. Run the app: `cd www && php -S localhost:3000`, open `http://localhost:3000` (add `?live` for hot reload). Make sure no stale `.dist/` exists.
3. Test `@AJAX` handlers with curl: `curl -X POST -H 'Content-Type: application/json' -d '{"action":"save","name":"Ada"}' http://localhost:3000/page`. A full HTML page in the response means the action name did not match an `@AJAX` function; a 405 means the method is wrong.

## Agent Skill (Claude Code, Codex)

The fastest way to teach a coding agent PHPue is the downloadable skill: [phpue.zip](/skills/phpue.zip) is the PHPue author's own skill: these rules, in-depth references, a starter app and compile/lint scripts. Install instructions: [/skills](/skills).

## AGENTS.md / CLAUDE.md for PHPue projects

Copy this into the root of any PHPue project so coding agents (Claude Code, Codex, Cursor, Copilot, Gemini CLI) follow the rules:

```markdown
# PHPue project rules

This is a PHPue app (https://phpue.co.uk). PHPue compiles .pvue files to PHP on the server. It is NOT Vue.
Full reference for agents: https://ai.phpue.co.uk/llms-full.txt (index: https://ai.phpue.co.uk/llms.txt)

- Check the version first: grep -m1 PHPUE_VERSION www/index.php. These rules are for 0.0.3.
- .pvue blocks, in order: <script> (PHP, no <?php tags), <header> (head tags), <template> (HTML), <cscript> (browser JS).
- {{ $expr }} = escaped echo. Use $arr['k'] or $obj->p, never $obj.p. Raw HTML: <?= $html ?>.
- p-if="cond" and p-for="$item in $list" on closed elements. No p-else / p-show / p-model: use a negated p-if or raw PHP.
- Components: @require Name 'components/Name.pvue'; then <Name/>. No props/attributes/slots: components inherit the parent's variables. Default them with $x = $x ?? ''.
- Routes: views/<name>.pvue -> /<name>. Flat only; use query strings. #require 'views/<name>.pvue'; in App.pvue.
- View <header> is static. Dynamic meta goes through App.pvue's header (or the MetaControl extension).
- App variables are not visible in views. Share via backend/ singletons, $_SESSION, define().
- @AJAX('POST') function name($input) { echo json_encode(...); } - echo, don't return. Call with fetch('', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({action:'name', ...})}).
- <cscript>: {{ $var }} is json_encode'd - never wrap it in quotes.
- No named functions/classes in components; put them in backend/. Use getcwd() for paths, not __DIR__.
- Never edit index.php or conversion.php. Delete .dist/ while developing.
- Verify: compile with convert_pvue_file() and pipe to php -l; run with cd www && php -S localhost:3000.
```

## Prompt for chat assistants

When using a chat assistant (ChatGPT, Claude, Gemini, Perplexity) that can browse, start with:

```text
Before answering, read https://ai.phpue.co.uk/llms-full.txt. It is the canonical reference for PHPue 0.0.3,
a server-side PHP framework with Vue-like .pvue files. PHPue is new: do not rely on general knowledge or on Vue
behaviour. Use only the syntax documented there, say when something is not covered, and link the relevant topic
from https://ai.phpue.co.uk/llms.txt.
```
