# Views, routing and 404 pages

> PHPue routes by file name: `views/about.pvue` is served at `/about` and `views/index.pvue` at `/`. Only files directly inside `views/` are routes, so `/blog/my-post` is a 404. Use query strings (`/blog?post=my-post`) and branch inside one view. Register every view in `App.pvue` with `#require 'views/name.pvue';`.

## Routes

| File | URL |
|---|---|
| `views/index.pvue` | `/` |
| `views/about.pvue` | `/about` (also `/about/`) |
| `views/get-started.pvue` | `/get-started` |
| `views/blog/post.pvue` | not a route |

- The route slug is the URL path with slashes trimmed. It is stored in `$_GET['page']`, so **`page` is a reserved query parameter**: `?page=2` is overwritten. Paginate with `?p=2` or `?pagination=2`.
- Register views in `App.pvue` (one per line, no name before the path):

```php
#require 'views/index.pvue';
#require 'views/about.pvue';
```

`#require About 'views/about.pvue';` (with a name) is silently ignored: PHP treats it as a comment. Routing scans `views/*.pvue` regardless, but `#require` every view; it is the convention, and 0.0.2 builds depend on it.

- `<View/>` (or `<View />`, `<View></View>`) goes once in the App template and is replaced by the current page.

## Sub-pages with query parameters

```html
<!-- views/tutorials.pvue -->
<script>
    @require TutorialList 'components/tutorials/TutorialList.pvue';
    @require FirstApp 'components/tutorials/FirstApp.pvue';
    @require Styling 'components/tutorials/Styling.pvue';

    $tutorial = preg_replace('/[^a-z0-9-]/', '', $_GET['name'] ?? '');
</script>

<template>
    <?php if ($tutorial === 'first-app'): ?>
        <FirstApp/>
    <?php elseif ($tutorial === 'styling'): ?>
        <Styling/>
    <?php else: ?>
        <TutorialList/>
    <?php endif; ?>
</template>
```

Always sanitise or allow-list query values before using them in paths or queries.

For pretty URLs, the author's PrettyUrlRedirect extension 301-redirects `/page/key/value` to `/page?key=value`.

## Navigation helpers

Available in every `.pvue` file and in `backend/`:

- `phpue_navigation()` returns `[['name' => 'about', 'title' => 'About', 'url' => '/about'], ...]`. The title comes from each view's `<title>` (or `ucfirst(name)`). Order is file order, so sort or filter it yourself.
- `get_current_route()` returns the current slug (`index` for `/`).
- `phpue_current_meta()` returns the current view's parsed header meta.
- Active link: compare `parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)` with the link URL and set `aria-current="{{ $isCurrent ? 'page' : 'false' }}"`.

```html
<!-- components/Navbar.pvue -->
<script>
    $navRoutes = array_filter(phpue_navigation(), fn($r) => $r['name'] !== 'admin');
    $navCurrent = get_current_route();
</script>

<template>
    <nav aria-label="Main">
        <a p-for="$r in $navRoutes" href="{{ $r['url'] }}" aria-current="{{ $r['name'] === $navCurrent ? 'page' : 'false' }}">{{ $r['title'] }}</a>
    </nav>
</template>
```

## 404 pages

Unknown routes return HTTP 404 and render inside the App layout (navbar and footer stay):

- `httpReqs/http404.php`: plain PHP/HTML body that replaces `<View/>`.
- `httpReqs/http404Head.php`: head tags for the 404 page:

```php
<?php
global $phpue_header;
$phpue_header = <<<HTML
    <title>404 - Page Not Found</title>
    <meta name="robots" content="noindex">
HTML;
$GLOBALS['phpue_http404_header'] = $phpue_header;
?>
```

Without these files PHPue prints a default "404 - Page Not Found" heading. Only 404 is built in; for other errors, set `http_response_code()` in the view script.

## Where the view's code runs

A view's compiled code runs inside a framework method, in its **own scope**, after the App script and App header. So:

- Variables from `App.pvue` are not visible in views. Use `backend/` singletons, `$_SESSION` or `define()`.
- Output is buffered, so `header()`, `setcookie()`, redirects (`header('Location: /login'); exit;`) and `http_response_code()` all work from a view's `<script>`.
- `phpue_set_lang()` is too late in a view (`<html lang>` is already printed); call it in the App script or `backend/`, or put `<meta name="lang" content="cy">` in the view header.
