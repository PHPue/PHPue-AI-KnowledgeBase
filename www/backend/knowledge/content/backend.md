# backend/, scope, sessions and language

> Every `*.php` file under `backend/` is `require_once`d on every request, before routing and `@AJAX` dispatch. Put classes, singletons, database access, API clients and helper functions there. App, views and `@AJAX` handlers each run in their own scope, so `backend/` singletons (or `$_SESSION`, `define()`) are how they share data. Sessions are controlled by `PHPUE_SESSION` (`'auto'` or `'lazy'`).

## backend/ autoloading

- Files load recursively in sorted path order (0.0.3). If a folder contains `autoload.php`, **only that file** is loaded for the folder and PHPue does not scan deeper. Use it to fix load order (`extends` across files) or to keep data files out of the scan.
- Files run on every request, including AJAX calls and 404s. Keep them to definitions and idempotent bootstraps. **Never echo** from them (except dedicated endpoint files that `exit`).
- Composer: put `vendor/` inside `backend/` (its `autoload.php` stops the scan there). Do not add Composer at the project root.
- A file that `return`s an array (config, language pack) must not be loaded twice with `require_once`, because the second call returns `true`. Keep such files in a folder with an `autoload.php`, or load them with `require`.

## The singleton pattern (author's convention)

```php
<?php
// backend/Database.php
class Database {
    private static ?self $instance = null;
    private ?PDO $pdo = null;

    public static function getInstance(): self {
        return self::$instance ??= new self();
    }

    public function pdo(): PDO {
        return $this->pdo ??= new PDO('sqlite:' . getcwd() . '/data/app.sqlite', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    public function latestPosts(int $limit = 10): array {
        $stmt = $this->pdo()->prepare('SELECT id, title, body FROM posts ORDER BY id DESC LIMIT :limit');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
```

Use it from any view, component, App or `@AJAX` handler: `$posts = Database::getInstance()->latestPosts(5);`. Keep secrets in a git-ignored file (for example `backend/env.php` with `putenv('DB_PASS=...')` or `define()`), and use prepared statements for every query.

## Scope: who sees what

| Code | Scope |
|---|---|
| `App.pvue` script + components placed in App | App scope |
| A view's script + components placed in that view | that view's scope |
| `@AJAX` functions | plain functions, called before the App/view scripts run |
| `backend/` files | their classes, functions and constants are global; loose variables are not (expose data through a class) |

Request order: backend files load, the session starts (per `PHPUE_SESSION`), `@AJAX` dispatch (exits if it matches), App script, `<head>`, App template, then the view's script and template at `<View/>`.

So an App variable is **undefined** in a view, and a handler cannot see page variables. Share data with:

- a backend singleton (best: `Config::getInstance()->get('siteName')`),
- `define('SITE_NAME', '...')` in the App script or backend,
- `$_SESSION` (per visitor),
- `$GLOBALS['x']` (request-wide, use sparingly).

## Sessions: `PHPUE_SESSION`

Set in `index.php`: `define('PHPUE_SESSION', 'lazy');`

| Mode | Behaviour | Use for |
|---|---|---|
| `'auto'` (default) | `session_start()` on every request, like 0.0.2 | apps where every visitor has state |
| `'lazy'` | only resumes a session the visitor already has; anonymous pages send no `Set-Cookie` and stay cacheable | public sites, docs, blogs, anything behind a cache or CDN |

With `'lazy'`:

- Reading `$_SESSION` needs nothing: it is simply empty for new visitors.
- Before **writing** during page rendering (or in a GET handler, or backend code), call `phpue_session(true)`.
- POST `@AJAX` handlers start a session automatically.
- Extensions and backend code should call `phpue_session()` / `phpue_session(true)` instead of `session_start()`.
- If the App script text contains `session_start()` (even in a comment), PHPue's session handling switches off entirely.

```php
// a view that remembers the last search (lazy sessions)
$query = trim($_GET['q'] ?? '');
if ($query !== '') {
    phpue_session(true);
    $_SESSION['lastQuery'] = $query;
}
$lastQuery = $_SESSION['lastQuery'] ?? '';
```

## Language: `<html lang>`

Priority per request: `phpue_set_lang('cy')` > the view header's `<meta name="lang" content="cy">` > `PHPUE_LANG` (`'en'` in `index.php`). `phpue_html_lang()` returns the resolved value.

Call `phpue_set_lang()` in the **App script** or a `backend/` bootstrap. A view runs after `<html lang>` is printed, so calling it there is too late.

## File paths

`__DIR__` inside `.pvue` code is `www/` in source mode but `www/.dist` or `www/.dist/pages` after a build, so paths built from it break in production. Use `getcwd()`, which is always the web root:

```php
$posts = json_decode(file_get_contents(getcwd() . '/data/posts.json'), true);
```

Inside `backend/*.php` files, `__DIR__` is that file's real folder and is safe to use for files next to it (the build copies `backend/` into `.dist/backend/`).

## Other runtime helpers (0.0.3)

- `phpue_session(bool $write = false)`: resume, or with `true` start, the session.
- `phpue_set_lang(string $lang)` / `phpue_html_lang()`.
- `phpue_iterable($x)`: returns `$x` if iterable, otherwise `[]` (what `p-for` uses).
- `phpue_navigation()`, `get_current_route()`, `phpue_current_meta()`: see [routing](/docs?topic=routing).
