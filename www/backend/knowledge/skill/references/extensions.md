# Official PHPue extensions (use these, don't reinvent them)

Source of truth: https://github.com/PHPue/PHPue-Extensions (`git clone https://github.com/PHPue/PHPue-Extensions.git`)
(GitHub: https://github.com/PHPue/PHPue-Extensions). **Each branch is a category**, so read files without switching the user's checkout:
```bash
cd PHPue-Extensions
git ls-tree -r --name-only PHPue-Extensions          # list a branch
git show PHPue-Extensions:phpue-metacontrol/backend/phpue-metacontrol.ext.php > /path/to/www/backend/phpue-extensions/phpue-metacontrol.ext.php
```

| Branch | Extension | Path on branch | Install into |
|---|---|---|---|
| `PHPue-Extensions` | **MetaControl** (dynamic SEO/meta) | `phpue-metacontrol/backend/phpue-metacontrol.ext.php` | `www/backend/phpue-extensions/` |
| `PHPue-Extensions` | **Matrix CMS** (flat-file headless CMS) | `phpue-matrix-cms/backend/phpue-extensions/matrix-cms/` (+ `example/views/*.pvue` admin/blog views) | `www/backend/phpue-extensions/matrix-cms/` |
| `Client-Side-Extensions` | **x-ssr** (AlpineJS SSR list hydration) | `phpue-alpinejs-ssr/assets/js/phpue-ext-csr/*.js` (+ `example-1`, `example-2`) | `www/assets/js/phpue-ext-csr/` (Alpine: `assets/js/fworks/`) |
| `Integrations-CMS-Bridges` | PHPue Elementor widget (WordPress) | `phpue-elementor-widget/` | WordPress plugin |
| `Framework-Extensions` | (empty so far) | | |

Other author extensions seen in projects (not in the repo yet): **PrettyUrlRedirect** (TechDemo `backend/phpue-extensions/phpue-prettyUrlRedirect.php`: `/page/k/v` → `/page?k=v`, strips trailing slashes) and **LanguageSelector** (PatchDesigns, bilingual English/Welsh; see §4).

Extension conventions: `namespace PHPueExt;`, a singleton `getInstance()`, a version guard `if (defined('PHPUE_VERSION') && version_compare(PHPUE_VERSION, '0.0.x', '>='))`, self-bootstrapping at include time (`\PHPueExt\MetaControl::getInstance();`), living in `backend/` so PHPue's autoloader picks them up. Extensions that need a session should prefer `function_exists('phpue_session') ? phpue_session(true) : session_start()` so they respect 0.0.3's `PHPUE_SESSION = 'lazy'`.

---

## 1. MetaControl: dynamic `<head>` for SEO

**Why it exists:** view `<header>` blocks are static, and the App script runs **before** the view. Per-request titles, descriptions, OG/Twitter tags and canonicals must therefore be decided early (backend/App) and printed by `App.pvue`'s header. MetaControl is the shared store for that.

API (`\PHPueExt\MetaControl`, session-backed under `$_SESSION['PHPueExt_MetaMap']`):

| Method | Purpose |
|---|---|
| `getInstance()` | Singleton (starts the session if needed) |
| `setMetaVariable(string $key, mixed $value)` | Store a value (plain text or a full tag string) |
| `getMetaVariable(string $key)` | Read (returns `''` if unset) |
| `getMetaArray()` | All stored values, in insertion order (**newer version**, in PatchDesigns. TechDemo's and the repo's copy don't have it yet) |
| `unsetMetaVariables()` | Clear the map |
| `setRendered(bool)` / `getRendered()` | Flag that meta was produced for a page, so the next request can clear it and stop meta "bleeding" across pages |

### Production pattern: meta router plus per-route meta singletons (TechDemo, PatchDesigns)
```
backend/phpue-extensions/phpue-metacontrol.ext.php
backend/meta-scripts/phpueMetaRouter.php          ← maps first path segment → *Meta class
backend/meta-scripts/metas/phpueBlogsMeta.php      ← one singleton per route
```
```php
class PHPueMetaRouter {
    private static ?self $instance = null;
    private static array $routes = [
        ''      => PHPueIndexMeta::class,
        'blogs' => PHPueBlogsMeta::class,
    ];
    private function __construct() {
        $metaControl = \PHPueExt\MetaControl::getInstance();
        $route = $this->getCurrentRoute();                 // first segment of REQUEST_URI path
        if (isset(self::$routes[$route])) { (self::$routes[$route])::getInstance(); return; }
        if ($metaControl->getRendered()) $metaControl->unsetMetaVariables();   // stop bleed to unmapped pages
    }
    // getCurrentRoute(), getInstance() …
}

class PHPueBlogsMeta {   // constructor: setMetaData() then $this->metaControl->setRendered(true)
    private function SetMetaData() {
        $slug  = $_GET['slug'] ?? null;
        $title = $slug ? \PHPueExt\PHPueMatrix::getPageTitle('blogs', $slug) : 'Blog | Site';
        $this->metaControl->setMetaVariable('page-title', '<title>' . htmlspecialchars($title) . '</title>');
        $this->metaControl->setMetaVariable('og:title', '<meta property="og:title" content="' . htmlspecialchars($title) . '">');
        $this->metaControl->setMetaVariable('linkCanonical', '<link rel="canonical" href="' . htmlspecialchars($canonical) . '">');
        // per-route side effects are fine here too: redirects (header()+exit), define('PHPue_Admin', true)
    }
}
```
`App.pvue`:
```php
<script>
    PHPueMetaRouter::getInstance();                       // runs the route's meta class
    $pageMeta  = \PHPueExt\MetaControl::getInstance();
    $metas     = array_values(array_pad($pageMeta->getMetaArray(), 16, ''));   // fixed slots
</script>
<header>
    <meta charset="UTF-8">
    {$metas[0]}
    {$metas[1]}
    {$metas[2]}
    …
</header>
```
Or, with only `getMetaVariable` available (TechDemo), build each variable and use `{{ $pageTitle }}`.
- **App header interpolation:** the header block is a PHP heredoc. `{{ $simpleVar }}` is PHPue's syntax (converted to `$simpleVar`), and **native heredoc syntax also works and is used by the author**: `{$metas[0]}`, `{$meta['title']}`, `{$obj->tag}`. Values are output **raw**, so escape inside the tag strings (`htmlspecialchars`). Some official examples skip this. Do escape anything derived from user input or CMS content.
- **Rule: App is the parent and views are children.** On routes the meta router manages, the view `<header>` has **no `<title>`** (and no description or OG tags), and only the meta class emits them. Static routes keep their static view `<title>`. Without that `<title>`, `phpue_navigation()` labels the route `ucfirst(viewName)`, so use explicit nav labels (or a hand-written Navbar) for those routes.
- Per-route flags from meta classes: PatchDesigns' `PHPueAdminMeta` does `define('PHPue_Admin', true)`. The App template then hides the Navbar with `<?php if (!defined('PHPue_Admin')): ?><Navbar/><?php endif; ?>`, which works because the meta router runs in the App script before the template.
- **AJAX + MetaControl** (repo example): an `@AJAX` handler calls `setMetaVariable('page-title', …)`, the client reloads, and the server renders the new head. The `setRendered`/`unsetMetaVariables` pair resets it on the following navigation.

## 2. Matrix CMS: flat-file headless CMS (`\PHPueExt\PHPueMatrix`, requires PHPue ≥ 0.0.2)

Content is PHP files returning arrays (`var_export`), rendered through theme closures. There's no database, and it's OpCache-friendly (`opcache_invalidate` on every read/write).
```
backend/phpue-extensions/matrix-cms/
├── phpue-matrix.ext.php          ← the class
├── themes/default.php            ← return ['meta'=>…, 'shortcodes'=>[name => fn], 'sections'=>[type => ['label','fields','render'=>fn(array $data): string]]]
└── pages/<route>/<slug>.php      ← return ['title','slug','theme','status'=>'live|draft','created','sections'=>[['type','data'=>[…]]],'seo'=>[…]]
    pages/templates/tpl-*.php     ← reusable page templates
```
API (all static):
- Render: `getPage($route, $slug): string` gives body HTML only (sections, then shortcodes). Use `getPagePreview` for the admin iframe.
- SEO: `getPageSeo`, `getPageTitle` (seo.meta_title → title), `getPageDescription` (seo, else first content/hero text, 160 chars), `getPageKeywords`, `getPageOgImage` (seo, else hero bg), `getPageTwitterImage`, `getPageFeaturedImage`, `getPageCanonical`, `getPageRobots` (`noindex` → `noindex, nofollow`).
- CRUD: `createPage($route, $slug, $theme, $sections, $title, $status, $seo): bool` (overwrites), `deletePage($route, $slug)`. PatchDesigns' fork also regenerates `sitemaps/sitemap-<route>.xml`.
- Listing: `listPages($category)` → `[slug,title,status,created]`, `listRoutes()`, `getTheme($name)` (labels and fields only, for admin forms).
- Shortcodes in content: `[[year]]`, `[[namespace.key]]` (for example `[[phpueLang.site_name]]`) resolved by theme `shortcodes` callables.

Usage in PHPue:
```php
// views/blogs.pvue
<script>
    $slug = isset($_GET['slug']) ? preg_replace('/[^a-z0-9\-]/', '', $_GET['slug']) : null;   // sanitise!
    if ($slug) $page = \PHPueExt\PHPueMatrix::getPage('blogs', $slug);
    else       $pages = array_filter(\PHPueExt\PHPueMatrix::listPages('blogs'), fn($p) => $p['status'] === 'live');
</script>
<template>
    <?php if (!empty($page)): ?>
        <?= $page ?>                       <!-- raw: theme closures already escape their fields -->
    <?php elseif (isset($pages)): ?>
        … listing: listPages + getPageFeaturedImage per card …
    <?php else: ?>
        … not found …
    <?php endif; ?>
</template>
```
SEO for CMS pages goes through App/MetaControl (see §1): `getPageTitle`, `getPageDescription` and so on, keyed on `get_current_route()` and `$_GET['slug']`. The admin UI is the example `views/phpue-matrix.pvue` (route selector, page CRUD, auto-save drafts, iframe preview via an `@AJAX getPreview` that echoes a full HTML document, templates, SEO panel). Its `@AJAX` handlers sanitise slugs with `preg_replace('/[^a-z0-9\-]/', '', …)` and `basename()`.

Matrix CMS caveats (verified against PHPue 0.0.2 behaviour; all still apply in 0.0.3):
- **Sanitise `$route`/`$slug` before every API call.** The class concatenates them straight into file paths (`pages/{$route}/{$slug}.php`), so `../` in a query string escapes the folder. Allow-list routes and use `[a-z0-9-]` slugs.
- **Protect the admin route.** The example admin view has no auth, so gate it (session login, IP allow-list, or don't deploy it) and add CSRF to its `@AJAX` writes.
- **`__DIR__` inside .pvue code depends on mode:** `www/` in source mode, but `www/.dist` (App) and `www/.dist/pages` (views) when compiled. The example admin view builds paths with `__DIR__ . '/backend/…'`, which breaks after `php index.php build`. Use `getcwd()` (always the web root) or the class's own paths.
- **Content lives inside `backend/`.** In compiled mode the live copy is `.dist/backend/phpue-extensions/matrix-cms/pages/` (the build copies `backend/`). Pages created on production are written there, and a fresh `.dist` deploy overwrites or drops them. Back up or sync that folder, or move `pagesDir` outside `backend/`.
- PHPue's backend autoloader `require_once`s **every** `.php` under `backend/`, including each CMS page and theme file, on every request (they just `return` arrays, so this is harmless but grows with content). An `autoload.php` inside `matrix-cms/` that requires only `phpue-matrix.ext.php` stops the scan.

## 3. x-ssr (Client-Side-Extensions branch)

Full behaviour is in `alpine-xssr.md`. Points from the official README and examples:
- It solves Alpine issues #196 (bind `x-for` to server-rendered items), #329 (pre-existing lists) and #450 (valid HTML for lists, tables and selects). x-ssr **works outside PHPue** too (Laravel, raw PHP, plain HTML).
- It composes with other Alpine directives: `x-show`, and `x-intersect` (Alpine Intersect plugin) for infinite scroll. Render the first N items in PHP for SEO and first paint, then append more on scroll through `@AJAX` + x-ssr.
- Official examples wrap SSR items as `<div p-for>` around `<div data-id>`. That works there because the list is the last element in its parent: x-ssr flattens the adopted items. On 0.0.2 the unclosed wrappers swallow any markup that follows, and the pre-hydration HTML is malformed (0.0.3 closes each wrapper correctly). Prefer `p-for` directly on the keyed element (see `alpine-xssr.md` §4).
- Official examples: `example-1` defines `Alpine.data` in `<cscript>` with `<?= json_encode($notifications) ?>`. `example-2` sets `window.notifications = {{ $notifications }};` in `<cscript>` and defines `Alpine.data` in `assets/js/handlers/*.js` loaded from the view header.
- SSR items can carry Alpine handlers directly (`@click="dismiss({{ $note['id'] }})"`). CSR items get theirs in `process()` (`button.onclick = () => component.dismiss(item.id)`).
- Optimistic updates: keep a copy (`const original = [...this.items]`), filter the array, POST to `@AJAX`, and restore `original` on failure.

## 4. LanguageSelector: bilingual sites (PatchDesigns, en/cy)

Layout:
```
backend/LangSelector/
├── autoload.php                     ← spl_autoload_register class map + LanguageSelector::getInstance() bootstrap
├── phpue-langSelector.ext.php       ← \PHPueExt\LanguageSelector
└── langs/English.php, Welsh.php     ← each: $phpueLang = []; $phpueLang['nav_home'] = '…'; … return $phpueLang;
```
Behaviour: `?lang=en|cy` (allow-listed) → stored in `$_SESSION['PHPue_Lang']`. On first visit it falls back to `Accept-Language` (`cy` → Welsh, else English). The pack is loaded once per request. API: `getInstance()`, `getLanguage()`, `setLanguage($lang)`, `getLanguagePack(): array`.

How the site uses it:
- **Every consumer fetches the pack itself**, because App variables aren't visible in views: `$phpueLang = \PHPueExt\LanguageSelector::getInstance()->getLanguagePack();` in App, in each view, and in each meta class.
- Strings are output **raw**: `<?= $phpueLang['contact_HeroBadge'] ?>`. Packs are trusted, developer-written content and may contain markup. Use `{{ $phpueLang['key'] }}` when a string must be escaped.
- Keys are prefixed by page/component (`nav_*`, `meta_indexTitle`, `contact_FAQ_Q1`, `compCookie_Accept`), so both packs keep the same key set (about 600 keys each).
- **Switcher** (Navbar): crawlable links that flip `?lang=` while keeping the other query params (`http_build_query`). The active link is disabled, and JS preserves scroll position across the reload.
- **Per-language content routes:** separate views and Matrix folders (`views/blogs.pvue` ↔ `views/blogiau.pvue`, `pages/blogs/` ↔ `pages/blogiau/`). The blog meta class redirects `/blogs?lang=cy` → `/blogiau` (302, keeps `?slug=`), and every page emits `hreflang="en"`, `"cy"` and `"x-default"` alternates plus a language-aware canonical through MetaControl.
- **Meta per language:** meta classes read `meta_*` keys from the pack (`meta_indexTitle`, `meta_blogsSocialTitle`, …).

Verified caveats (PatchDesigns, PHPue 0.0.2):
- **Keep `autoload.php` in `LangSelector/`. It isn't optional.** The packs are loaded with `require_once`. If PHPue's backend scanner reached `langs/*.php` first, the second `require_once` would return `true` instead of the array, and `getLanguagePack()` would break. Any "config file that `return`s an array" must live in a folder with an `autoload.php`, or be loaded with `require`/`include`, never `require_once`.
- **`<html lang>` (0.0.3 fix):** call `phpue_set_lang(\PHPueExt\LanguageSelector::getInstance()->getLanguage());` in the App script (or the extension's `autoload.php` bootstrap). It runs before `<html>` is printed and wins over view meta and `PHPUE_LANG`, in source and `.dist` mode. Calling it from a view is too late.
- **`<html lang>` on 0.0.2 doesn't follow the selected language.** It comes from `PHPUE_LANG` (fixed `'en'` in `index.php`), or a view's static `<meta name="lang">`, and in `.dist` it's frozen at build time. A Welsh page is served as `<html lang="en">` (verified). Until the framework computes lang at runtime, the only in-app mitigation is client-side: `document.documentElement.lang = {{ $langCode }};` in App's `<cscript>`. That helps screen readers, while crawlers rely on hreflang.
- **Matrix shortcode `[[phpueLang.key]]`:** the patchdesigns theme maps it to `\PHPueExt\PHPueMatrix::$phpueLang[$key]`, but nothing assigns that static, so a shortcode would print literally (no content uses it yet). Wire it in App after loading the pack: `\PHPueExt\PHPueMatrix::$phpueLang = $phpueLang;`.
- **Sessions:** the constructor always calls `session_start()`, so every page is uncacheable, even with lazy sessions (see `htmx-spa.md` → Caching). On 0.0.3 with `PHPUE_SESSION = 'lazy'`, the extension could call `phpue_session()` instead and only `phpue_session(true)` when a visitor actually switches language (`?lang=`), keeping the Accept-Language default sessionless.
- **Attribute fragments can't go through `{{ }}`:** the Navbar's `{{ $lang === 'en' ? 'aria-current="true"' : '' }}` outputs `aria-current=&quot;true&quot;`, because the quotes are escaped. Emit a value instead (`aria-current="{{ $lang === 'en' ? 'page' : 'false' }}"`) or use `<?= … ?>` for the whole fragment.
