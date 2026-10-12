# PHPue FAQ

> Short, direct answers about PHPue 0.0.3. Each answer links to the full topic.

## What is PHPue?

PHPue is a server-side PHP framework that compiles Vue-like single-file components (`.pvue` files) into plain PHP. A `.pvue` file has a `<script>` block (PHP), a `<header>` block (head tags), a `<template>` block (HTML with `{{ }}`, `p-if` and `p-for`) and a `<cscript>` block (browser JavaScript). It needs no Node.js, bundler or build step. See [What PHPue is](/).

## Who created PHPue?

PHPue was created by Edward Patch. It is open source under the Apache 2.0 licence at [github.com/PHPue/PHPue](https://github.com/PHPue/PHPue), with the official site at [phpue.co.uk](https://phpue.co.uk).

## What is the latest version of PHPue?

The current release is PHPue 0.0.3, a bug-fix release that replaced 0.0.2's line-based regular expressions with a block parser and tag-aware directives, enforced `@AJAX` methods and added lazy sessions. See [Upgrading from 0.0.2](/docs?topic=upgrade).

## What do I need to run PHPue?

PHP 8.0 or newer and a web server that sends unknown URLs to `index.php` (PHP's built-in server, Apache with the included `.htaccess`, nginx with `try_files`, Docker, XAMPP or shared hosting). No Node.js, npm or Composer is required. See [Quick start](/docs?topic=quickstart).

## Is PHPue the same as Vue?

No. PHPue borrows Vue's single-file layout and directive style, but everything runs once on the server. There is no virtual DOM, reactivity, hydration, props, slots, emits or `v-model`. The `<script>` block is PHP. Client interactivity comes from plain JavaScript in `<cscript>`, `@AJAX` calls, or optionally AlpineJS. See [Migration](/docs?topic=migration).

## How do I print a variable in a PHPue template?

Use `{{ $variable }}`, which is HTML-escaped. Use array or object syntax for nested values, `{{ $user['name'] }}` or `{{ $user->name }}`, never `{{ $user.name }}` (the dot is PHP string concatenation). For trusted raw HTML use `<?= $html ?>`. See [Templates](/docs?topic=templates).

## Does PHPue have p-else or p-show?

No. PHPue's directives are `p-if` and `p-for`. For an else branch, add a second element with the negated condition (`p-if="!$loggedIn"`) or use raw PHP: `<?php if (...): ?> ... <?php else: ?> ... <?php endif; ?>`. There is no `p-model` either; forms use plain `name` and `value` attributes.

## How do I pass props to a PHPue component?

You don't need to: components are inlined at compile time and inherit every variable of the parent, like a PHP `include`. Set a variable before the tag (`<?php $cardTitle = 'Hello'; ?><Card/>`), or place the component inside a `p-for` loop and read the loop variable. Default inherited variables in the component (`$cardTitle = $cardTitle ?? '';`). See [Components](/docs?topic=components).

## How does routing work in PHPue?

Each file in `views/` is a route named after the file: `views/about.pvue` is `/about` and `views/index.pvue` is `/`. Routes are flat, so use query strings for detail pages (`/blog?post=my-post`). Register views in `App.pvue` with `#require 'views/about.pvue';`. See [Routing](/docs?topic=routing).

## How do I call PHP from JavaScript in PHPue?

Mark a PHP function with `@AJAX('POST')` (or `@AJAX('GET')`) in a `<script>` block, and call it with `fetch('', {method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({action: 'functionName', ...data})})`. The function receives `$input` and must `echo` its response, usually `json_encode(...)`. See [@AJAX](/docs?topic=ajax).

## How do I handle forms in PHPue?

Either submit a normal `<form method="post">` and handle `$_POST` in the view's `<script>`, or send the form as JSON to an `@AJAX` function. PHPue deliberately has no two-way binding: forms are submitted on purpose. File uploads go to a `backend/` endpoint because `@AJAX` only reads JSON. See [Recipes](/docs?topic=recipes).

## Can I use a database with PHPue?

Yes. Write a PDO (or any library) class in `backend/`, which PHPue loads on every request, and call it from views and `@AJAX` handlers, typically as a singleton: `Database::getInstance()->latestPosts()`. Always use prepared statements. See [backend/](/docs?topic=backend).

## Why can't my view see a variable from App.pvue?

App, each view and `@AJAX` handlers run in separate scopes. Share data through a `backend/` singleton, `define()`, `$_SESSION` or `$GLOBALS`. See [backend/, scope, sessions and language](/docs?topic=backend).

## Why don't my .pvue changes show up?

A `.dist/` folder exists, so PHPue serves the compiled copy. Delete `.dist/` while developing, or rebuild with `php index.php build`. Also check for a stray `App.php` in the web root.

## How do I deploy a PHPue app?

Run `php index.php build` in `www/`, then upload `.dist/`, `assets/`, `httpReqs/`, `index.php`, `conversion.php` and `.htaccess` to any PHP 8 host. `index.php` serves the precompiled pages. See [Build and deploy](/docs?topic=build-deploy).

## Can I use Tailwind CSS, AlpineJS or HTMX with PHPue?

Yes. Tailwind works through its CLI (scan `./**/*.pvue` and `./**/*.php`) or the CDN. AlpineJS works in templates as-is, and the official x-ssr extension lets Alpine update server-rendered lists. HTMX works for boosted navigation and partial updates; `@AJAX` POST needs a JSON-encoding extension. See [client JS](/docs?topic=client-js).

## Is PHPue good for SEO?

Yes. Pages are fully rendered HTML on the first response with no hydration. Use static `<title>` and meta in view headers, or dynamic meta from `App.pvue` (the MetaControl extension), and set `PHPUE_SESSION` to `'lazy'` so anonymous pages are cacheable. See [Official extensions](/docs?topic=extensions).

## Is PHPue production ready?

PHPue is young (0.0.x) and its community is small, but it is plain PHP underneath: when a directive does not fit, you write normal PHP, and compiled output is ordinary PHP files. It suits content sites, dashboards, admin panels and small apps. Pin the framework files per project and test after upgrading.

## How can AI assistants write correct PHPue code?

Point them at [https://ai.phpue.co.uk/llms-full.txt](/llms-full.txt) (everything in one file) or [/llms.txt](/llms.txt) (an index of markdown topics), and add the AGENTS.md from [Rules for AI coding agents](/docs?topic=ai-rules) to your project. Ask the agent to compile changed files with `convert_pvue_file()` and check them with `php -l`.
