# What PHPue is (and is not)

> **PHPue** is a server-side PHP framework by Edward Patch that compiles Vue-like single-file components (`.pvue` files) into plain PHP. A `.pvue` file has a `<script>` block (PHP), a `<header>` block (head tags), a `<template>` block (HTML with `{{ }}`, `p-if` and `p-for`) and a `<cscript>` block (browser JavaScript). There is no Node.js, bundler or required build step: two PHP files (`index.php` and `conversion.php`) route, compile and serve the app. The current release is **PHPue 0.0.3**, which needs **PHP 8.0 or newer**.

Tagline: *Giving U Back Control.* Official site: [phpue.co.uk](https://phpue.co.uk). Source: [github.com/PHPue/PHPue](https://github.com/PHPue/PHPue).

## Key facts

| Fact | Value |
|---|---|
| Current version | 0.0.3 (bug-fix release; 0.0.2 projects still exist) |
| Requirements | PHP 8.0+ (0.0.3 uses `str_contains()`); any web server that can route to `index.php` |
| Framework files | `index.php` (server, router, builder) and `conversion.php` (`.pvue` to PHP compiler) |
| Component format | `.pvue` single-file components with `script`, `header`, `template`, `cscript` blocks |
| Template syntax | `{{ $expr }}` (escaped echo), `p-if="cond"`, `p-for="$item in $list"`, raw `<?php ?>` |
| Routing | File-based: `views/about.pvue` is `/about`, `views/index.pvue` is `/` |
| Browser calls | `@AJAX('POST')` / `@AJAX('GET')` PHP functions called with `fetch` |
| Rendering | Server-side only; no virtual DOM, no hydration, no reactivity |
| Optional client layer | Plain JS in `<cscript>`, AlpineJS, the x-ssr Alpine extension, HTMX |
| Build | Optional: `php index.php build` writes a precompiled `.dist/` for production |
| Licence | Apache 2.0 |

## PHPue is NOT Vue

The syntax looks like Vue, but the model is classic server-rendered PHP:

- Everything inside `{{ }}`, `p-if` and `p-for` runs **once, on the server**, per request.
- There is no virtual DOM, no reactivity, no hydration, no props, no slots, no emits and no `v-model`.
- The `<script>` block is **PHP**, not JavaScript. Browser code goes in `<cscript>`.
- Components have **no prop API by design**: a component is inlined into its parent at compile time, so it sees every variable the parent has (like a PHP `include`).
- **Raw PHP is always allowed and idiomatic.** When a directive cannot express something, write `<?php if (...): ?>`, `<?php foreach (...): ?>` or `<?= ... ?>` in the template.
- **Privacy-first forms**: there is no input binding or keystroke tracking. Forms are submitted on purpose (a normal POST, or an explicit `fetch` to an `@AJAX` function).

Rule of thumb from the author: *PHP owns the server. The browser layer is optional.*

## Project layout

```text
www/                     web root and working directory (all paths are relative to it)
├── index.php            framework: server, router, builder
├── conversion.php       framework: .pvue -> PHP compiler
├── App.pvue             root layout: global <header>, navbar/footer, <View/>
├── views/               one .pvue per route (top level only): index.pvue -> "/", about.pvue -> "/about"
├── components/          reusable .pvue files (any depth), pulled in with @require
├── backend/             *.php auto-required on every request (classes, singletons, endpoints)
├── httpReqs/            http404.php + http404Head.php (custom 404 page)
├── assets/              css/ js/ img/, served statically at /assets/...
├── .htaccess            front controller + security rules (Apache)
└── .dist/               build output (when present, the server uses it instead of the .pvue sources)
```

## How a request is served

1. `index.php` turns the URL path into a route slug (`/about` is `about`, `/` is `index`) and finds `views/<slug>.pvue`. Unknown slugs get the 404 page with HTTP status 404.
2. `conversion.php` compiles `App.pvue` and the view into PHP (or the precompiled `.dist/` files are used).
3. The compiled App runs: every `backend/*.php` is loaded, the session is handled, and if the request names an `@AJAX` function (`action`), that function runs and the request ends.
4. Otherwise the App `<script>` runs, the `<head>` is printed (App header + view header), and the App template renders, replacing `<View/>` with the current view.
5. Each block's `<cscript>` is printed as a `<script>` tag after its markup.

## The smallest complete app

```html
<!-- www/App.pvue -->
<script>
    #require 'views/index.pvue';
</script>

<header>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</header>

<template>
    <View/>
</template>
```

```html
<!-- www/views/index.pvue -->
<script>
    $title = 'Hello PHPue';
    $features = ['No build step', 'Server-side rendering', 'Plain PHP underneath'];
</script>

<header>
    <title>Home</title>
</header>

<template>
    <h1>{{ $title }}</h1>
    <ul>
        <li p-for="$feature in $features">{{ $feature }}</li>
    </ul>
</template>
```

Run it with `cd www && php -S localhost:3000` and open `http://localhost:3000`.

## Where to go next

- Writing PHPue with an AI assistant or coding agent: [Rules for AI coding agents](/docs?topic=ai-rules).
- First app step by step: [Quick start](/docs?topic=quickstart).
- Syntax in depth: [.pvue files](/docs?topic=pvue-files), [Templates](/docs?topic=templates), [Components](/docs?topic=components), [Routing](/docs?topic=routing), [@AJAX](/docs?topic=ajax).
- Something renders wrong: [Common mistakes](/docs?topic=gotchas).
- Everything in one file for an LLM: [/llms-full.txt](/llms-full.txt).
