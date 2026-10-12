# Official extensions

> PHPue extensions are plain PHP classes in `backend/` (picked up by the autoloader) or JS files in `assets/`. The official ones live in [github.com/PHPue/PHPue-Extensions](https://github.com/PHPue/PHPue-Extensions), one branch per category. Use them instead of hand-rolling dynamic meta, a flat-file CMS or Alpine list hydration.

| Branch | Extension | Install into |
|---|---|---|
| `PHPue-Extensions` | **MetaControl**: dynamic titles, descriptions, Open Graph, canonicals | `www/backend/phpue-extensions/phpue-metacontrol.ext.php` |
| `PHPue-Extensions` | **Matrix CMS**: flat-file headless CMS with themes, sections and SEO fields | `www/backend/phpue-extensions/matrix-cms/` |
| `Client-Side-Extensions` | **x-ssr**: AlpineJS directive that keeps server-rendered lists | `www/assets/js/phpue-ext-csr/` |
| `Integrations-CMS-Bridges` | PHPue Elementor widget for WordPress | WordPress plugin |

Extension conventions: `namespace PHPueExt;`, a singleton `getInstance()`, a version guard such as `if (defined('PHPUE_VERSION') && version_compare(PHPUE_VERSION, '0.0.2', '>='))`, and session access through `function_exists('phpue_session') ? phpue_session(true) : session_start()` so they respect lazy sessions.

## MetaControl: per-page SEO from App.pvue

**Why:** view `<header>` blocks are static and the App script runs before the view, so per-request titles and meta must be decided early (in `backend/` or the App script) and printed by `App.pvue`'s header.

API (`\PHPueExt\MetaControl`, stored in the session):

| Method | Purpose |
|---|---|
| `getInstance()` | singleton (starts the session) |
| `setMetaVariable(string $key, mixed $value)` | store plain text or a full tag string |
| `getMetaVariable(string $key)` | read (`''` if unset) |
| `unsetMetaVariables()` | clear everything |
| `setRendered(bool)` / `getRendered()` | mark meta as produced, so the next page can clear it and avoid meta "bleeding" between pages |

Pattern: a backend **meta router** maps the first path segment to a small `*Meta` class that sets the variables, and App prints them:

```php
<?php
// backend/meta/BlogMeta.php
class BlogMeta {
    public static function apply(): void {
        $meta  = \PHPueExt\MetaControl::getInstance();
        $slug  = preg_replace('/[^a-z0-9-]/', '', $_GET['post'] ?? '');
        $title = $slug ? ucwords(str_replace('-', ' ', $slug)) . ' | Blog' : 'Blog';
        $meta->setMetaVariable('page-title', '<title>' . htmlspecialchars($title) . '</title>');
        $meta->setMetaVariable('page-description', '<meta name="description" content="' . htmlspecialchars($title) . '">');
        $meta->setRendered(true);
    }
}
```

```html
<!-- App.pvue -->
<script>
    if (get_current_route() === 'blog') BlogMeta::apply();
    $pageMeta  = \PHPueExt\MetaControl::getInstance();
    $pageTitle = $pageMeta->getMetaVariable('page-title');
    $pageDescription = $pageMeta->getMetaVariable('page-description');
</script>

<header>
    <meta charset="UTF-8">
    {{ $pageTitle }}
    {{ $pageDescription }}
</header>
```

- App header values are injected **raw**: escape inside the tag strings.
- Routes whose meta comes from MetaControl should have **no `<title>`** in their view header (the App one would win anyway).
- MetaControl uses the session, so pages using it are not cacheable anonymously. For a fully cacheable public site, compute the head tags from a sessionless backend class instead (this site does: see `PHPueKnowledge::headTags()` in its [source](https://github.com/PHPue/PHPue-AI)).

## Matrix CMS: flat-file content

`\PHPueExt\PHPueMatrix` stores pages as PHP files that return arrays (`pages/<route>/<slug>.php`) and renders them through theme closures (`themes/default.php`). No database; OpCache-friendly.

- Render: `PHPueMatrix::getPage($route, $slug)` returns body HTML.
- SEO: `getPageTitle`, `getPageDescription`, `getPageKeywords`, `getPageOgImage`, `getPageCanonical`, `getPageRobots`.
- CRUD: `createPage($route, $slug, $theme, $sections, $title, $status, $seo)`, `deletePage($route, $slug)`.
- Listing: `listPages($route)` returns `[slug, title, status, created]`; `listRoutes()`, `getTheme($name)`.
- Shortcodes in content: `[[year]]`, `[[namespace.key]]`, resolved by the theme.

```html
<!-- views/blog.pvue -->
<script>
    $slug = preg_replace('/[^a-z0-9-]/', '', $_GET['post'] ?? '');
    $pageHtml = $slug ? \PHPueExt\PHPueMatrix::getPage('blog', $slug) : '';
    $posts = array_filter(\PHPueExt\PHPueMatrix::listPages('blog'), fn($p) => $p['status'] === 'live');
</script>

<template>
    <?php if ($pageHtml): ?>
        <?= $pageHtml ?>
    <?php else: ?>
        <ul>
            <li p-for="$post in $posts"><a href="/blog?post={{ $post['slug'] }}">{{ $post['title'] }}</a></li>
        </ul>
    <?php endif; ?>
</template>
```

Caveats: **sanitise `$route` and `$slug`** before every call (they become file paths); **protect the admin view** (the example has no auth; add a login and CSRF); content lives inside `backend/`, so a fresh `.dist` deploy can overwrite pages created on production (back them up or move the pages folder); add an `autoload.php` in `matrix-cms/` so PHPue does not `require_once` every content file on each request.

## x-ssr (AlpineJS)

Server-rendered lists that Alpine can filter, sort, add to and remove from without re-rendering them. Works outside PHPue too. Full usage: [cscript, AlpineJS, x-ssr and HTMX](/docs?topic=client-js).

## Multilingual sites (pattern)

The author's LanguageSelector pattern for bilingual sites:

- A backend singleton reads an allow-listed `?lang=` (falling back to `Accept-Language`), stores it, and loads a language pack (`langs/English.php` returning `['nav_home' => 'Home', ...]`). Keep the packs in a folder with an `autoload.php` (they `return` arrays).
- Every consumer fetches the pack itself (App variables are not visible in views): `$t = LanguageSelector::getInstance()->getLanguagePack();`, then `{{ $t['nav_home'] }}`.
- Call `phpue_set_lang($lang)` in the App script so `<html lang>` follows the selection.
- Language switcher as crawlable links that change `?lang=` and keep other query parameters; emit `hreflang` alternates and a language-aware canonical for each page.
- Separate views per language for translated slugs (`views/blog.pvue` and `views/blogiau.pvue`).
