# Coming from React, Next.js, Vue, Blade or plain PHP

> PHPue keeps the parts of modern frameworks people like (single-file components, file-based routing, a tidy template syntax) and drops the parts that cost the most (Node.js toolchains, hydration, client state management). State lives on the server in PHP; the browser gets finished HTML. Use this page to translate concepts, not code line by line.

## Concept map

| Concept | React / Next.js | Vue / Nuxt | Laravel Blade | PHPue |
|---|---|---|---|---|
| Component file | `.jsx` / `.tsx` | `.vue` | `.blade.php` | `.pvue` (PHP + HTML + optional JS) |
| Server logic | `getServerSideProps`, server components | `asyncData`, server routes | controller | the `.pvue` `<script>` block (PHP) |
| Output | `{user.name}` | `{{ user.name }}` | `{{ $user->name }}` | `{{ $user['name'] }}` / `{{ $user->name }}` |
| Condition | `{cond && <p/>}` | `v-if` / `v-else` | `@if` / `@else` | `p-if` (+ negated `p-if` or `<?php else: ?>`) |
| Loop | `items.map(...)` | `v-for` | `@foreach` | `p-for="$item in $items"` |
| Child component | `<Card title="x"/>` | `<Card :title="x"/>` | `<x-card :title="$x"/>` | `<?php $cardTitle = 'x'; ?><Card/>` (inherited scope) |
| Slots / children | `props.children` | `<slot>` | `{{ $slot }}` | none: set variables, or split into components |
| Routing | `pages/` or `app/` folders, nested | `pages/`, nested | `routes/web.php` | `views/*.pvue`, flat; query strings for detail pages |
| Layout | `_app.tsx` / `layout.tsx` | `layouts/` | `@extends` | `App.pvue` with `<View/>` |
| Head / meta | `next/head`, `metadata` | `useHead` | `@section('title')` | view `<header>` (static) or App header (dynamic) |
| Client state | `useState`, Redux | `ref`, Pinia | Livewire, Alpine | PHP + `$_SESSION`; Alpine when you need it |
| Effects / fetching | `useEffect`, SWR | `onMounted`, `useFetch` | controller | fetch in PHP before rendering |
| API routes | `pages/api/*` | `server/api/*` | `routes/api.php` | `@AJAX('POST')` functions, or a `backend/` endpoint |
| Forms | controlled inputs | `v-model` | `old()`, `@csrf` | plain `name`/`value`, explicit POST or `@AJAX` |
| Build | webpack / Vite / Turbopack | Vite | Vite (assets) | none; optional `php index.php build` |
| Hosting | Node server, Vercel | Node server | PHP host | any PHP 8 host, shared hosting included |

## From React / Next.js

- Convert each page to `views/<route>.pvue`. `getServerSideProps` and `useEffect` data fetching become plain PHP in `<script>`.
- `useState` for UI-only state: keep it in the browser with a few lines of `<cscript>` or AlpineJS. For state that must survive reloads, store it in `$_SESSION` or the database through `@AJAX`.
- Props become variables set before `<Component/>`. Children/slots become separate components.
- Nested routes (`/blog/[slug]`) become `/blog?post=slug`.

```jsx
// React
export default function UserList({ users }) {
    return <ul>{users.map(u => <li key={u.id}>{u.name}</li>)}</ul>;
}
```

```html
<!-- PHPue: views/users.pvue -->
<script>
    $users = Database::getInstance()->users();
</script>

<template>
    <ul>
        <li p-for="$user in $users">{{ $user['name'] }}</li>
    </ul>
</template>
```

## From Vue / Nuxt

The syntax is deliberately familiar, but: `{{ }}` takes **PHP** expressions (`$user['name']`, not `user.name`); there is no reactivity (the template runs once on the server); no `v-else`, `v-show`, `v-model`, `:props` or `@emit`; `<script>` is PHP. Put interactive islands in AlpineJS, which feels close to Vue's Options API.

## From Laravel Blade

`@if`/`@foreach` become `p-if`/`p-for` (or the raw PHP equivalents you already know); `{!! $html !!}` becomes `<?= $html ?>`; `@include` becomes `<Component/>` with inherited variables; controllers collapse into the view's `<script>`; Eloquent can be replaced by PDO singletons in `backend/` (or a Composer package vendored inside `backend/`). Add your own CSRF token check for `@AJAX` writes.

## From plain PHP

You already know PHPue: it is your PHP with a component system. Move `include 'header.php'` into `App.pvue`, turn each page into a view, wrap repeated markup into components, and keep using `<?php ?>` wherever it is clearer.

## When PHPue fits (and when it does not)

Good fit:

- Content sites, docs, marketing sites, blogs, dashboards, admin panels, small SaaS and internal tools.
- Teams that know PHP, or anyone tired of JavaScript build tooling.
- Cheap or shared hosting; projects that must keep running for years without dependency churn.
- SEO- and GEO-sensitive sites: complete HTML on first response, no hydration.

Less suitable:

- Heavily real-time apps (use WebSockets alongside), offline-first apps, or UIs that are almost entirely client-side state (an editor or a game).
- Projects that need a large ecosystem of ready-made framework plugins: PHPue is young and its community is small.
