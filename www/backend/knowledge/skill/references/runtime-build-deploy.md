# Runtime, build and deployment

Current release: **0.0.3** (needs PHP 8.0+; the README badge's 7.4+ is out of date). 0.0.2 differences are marked **0.0.2:**. Check with `grep PHPUE_VERSION www/index.php`. Upgrading = replacing `index.php` **and** `conversion.php` together; nothing else changes.

Constants in `index.php`: `PHPUE_VERSION`, `PHPUE_LANG` (default `<html lang>`), and in 0.0.3 `PHPUE_SESSION` (`'auto'` | `'lazy'`, edit the `define` line) and optionally `PHPUE_DEV_TOOLS` (see below).

## Serving modes (`index.php` → `PHPueServer::serveApp`)

On each request `index.php` sets `$_GET['page']` from the URL path, builds routing, then serves the **first** of these that exists:

| Priority | File | Mode | Routes come from |
|---|---|---|---|
| 1 | `.dist/App.php` (and `.dist/` dir) | compiled (production) | `.dist/pages/*.php` |
| 2 | `App.php` in web root | "extracted" compiled deploy (v0.0.2) | `pages/*.php` |
| 3 | `App.pvue` | source (dev): compiles App and the current view **on every request**, then `eval` | `views/*.pvue` |

**Design rule:** `.dist/` is always served **through `index.php` + `conversion.php`**, which must be deployed with it. Compiled files in `.dist/pages`, `.dist/components` and `.dist/ajax` are never standalone entry points (requested directly they fail by design). Any new behaviour, such as partial rendering, belongs in that pipeline.

⚠️ **If a `.dist/` folder exists in your dev checkout, every request uses stale compiled files and your `.pvue` edits do nothing.** Delete `.dist/` while developing, or rebuild after each change. The same applies to a stray `App.php` or `pages/` in the web root.

Query flags handled by `index.php`:
- `?build` (or CLI `php index.php build`): compile everything to `.dist/`. Through the browser this only runs when neither `.dist/App.php` nor `App.php` exists (0.0.3: and dev tools are enabled), and otherwise redirects to the page. The CLI always rebuilds.
- `?live`: injects the hot-reload client (source mode only).
- `?hot-reload=1&live=1`: SSE endpoint that polls file mtimes.
- `?compile=<path>&root=true|false`: returns compiled PHP as text/plain (handy for debugging; see Security). 0.0.3 only accepts `.pvue` files inside the web root.
- **0.0.3 dev tools gate:** `?compile=`, `?hot-reload` and web `?build` only work in source mode (no `.dist/App.php` and no `App.php`). `define('PHPUE_DEV_TOOLS', true|false);` in `index.php` overrides that; never force it on in production. **0.0.2:** `?compile=` and `?hot-reload` work in every mode.
- `?dev`: forces dev detection in the author's `Environment` class (not the framework).

## Local development

```bash
cd www
php -S localhost:3000          # PHP built-in server; open http://localhost:3000/  (add ?live for hot reload)
```
Don't pass `index.php` as a router script. Static `/assets/...` must be served directly.

Docker (framework repo, FreeCAD): `php:8.2-fpm` + `nginx:latest` on port 3000, with `./www` mounted at `/var/www/html` and `nginx/default.conf` (see `templates/nginx.default.conf`). That nginx config appends `&live=1` to every query, so hot reload is always on in Docker. FreeCAD adds a `docker/php/custom.ini` for upload limits (`upload_max_filesize = 16M`, `post_max_size = 16M`, `memory_limit = 256M`) and a Python `freecad-api` service reached from PHP at `http://freecad-api:5000` (service name on the compose network).

Hot reload details: the SSE loop checks `App.pvue` plus every `.pvue`/`.php` under `components/` and `views/` (0.0.3: recursively; **0.0.2:** the glob `**` is one level deep, so `components/a/b/*.pvue` isn't watched) once a second for 30 s, then sends `timeout`. On a change it sends `reload` and the page refreshes. The author notes that AJAX can misbehave on the PHP built-in server while live mode is on, and that Docker/nginx is the smooth path.

## Build (`php index.php build`, run from `www/`)

Produces:
```
.dist/
├── App.php                 ← compiled App.pvue (all components inlined)
├── pages/<view>.php        ← every views/*.pvue (and views/*.php copied)
├── components/**.php       ← compiled copies (NOT used at runtime; components are inlined)
├── ajax/ajax-App.php, ajax-<view>.php, ajax-component-<Name>.php   ← @AJAX functions (0.0.2: App + #require'd views only)
├── backend/                ← copy of backend/
├── httpReqs/               ← copy of httpReqs/
└── assets/                 ← copy of assets/
```
Build-time facts:
- 0.0.3 builds every view's and every component's `@AJAX` handlers (nested component folders included), registers each with its method in `$GLOBALS['phpue_ajax']`, and works without a `components/` folder.
- `<html lang>` is resolved per request in 0.0.3 (`phpue_set_lang()` > view `<meta name="lang">` > `PHPUE_LANG`), in `.dist` too.
- **0.0.2:** the build **requires a `components/` directory** (without it, `compileAllFiles()` throws after writing `.dist/App.php`); `@AJAX` inside **components** is not written to any `ajax-*.php`, so it doesn't exist in production; views missing from `#require` in App get no `ajax-<view>.php`; and `<html lang>` is computed once while compiling App (for the route present during the build, usually `index`), so a per-view `<meta name="lang">` doesn't vary per page in `.dist`.
- Rebuild after every change to `.pvue`, `backend/` (copied) or `assets/` (copied).
- Tailwind CSS must be rebuilt (`npm run build`) **before** the PHPue build so the copied `assets/css/tailwind-styles.css` is current.

## Deployment layouts

**Option 1: compiled `.dist` (recommended).** Upload:
```
.dist/   assets/   httpReqs/   index.php   conversion.php   .htaccess   (+ backend/ is inside .dist)
```
`/assets/...` URLs are served from the root `assets/` directory, so upload it too. Block web access to `.dist/` (nginx `location ~ /\.dist/ { deny all; }`; Apache: `RewriteRule ^\.dist(/.*)?$ - [F,L]`).

**Option 2: extracted (since v0.0.2).** Copy the **contents** of `.dist/` into the web root:
```
App.php   pages/   ajax/   components/   backend/   httpReqs/   assets/   index.php   conversion.php   .htaccess
```
`index.php` serves `App.php`, and routing reads `pages/*.php`. Because `.dist/ajax` no longer exists, the AJAX functions compiled into `App.php` are used instead. Block `pages|ajax|components|backend|httpReqs` and `App.php` from direct access (TechDemo's `.htaccess` does exactly this).

**Option 3: source on server.** Upload `.pvue` sources plus core files and let the server compile per request, or visit `?build=1` once. You **must** block `.pvue`, `views/`, `components/` and `backend/` in `.htaccess`/nginx. Note that in source mode `?compile=<file>` prints the compiled PHP (including `@AJAX` business logic) of any `.pvue` in the web root (0.0.2: any readable file path, in every mode), which is another reason to prefer Options 1 and 2.

### Apache `.htaccess` (front controller + hardening, from TechDemo)
See `templates/.htaccess`. Key lines:
```apache
RewriteEngine On
<FilesMatch "\.pvue$">
    Require all denied
</FilesMatch>
<FilesMatch "^App\.php$">
    Require all denied
</FilesMatch>
RewriteRule ^(ajax|pages|backend|httpReqs|components|views)(/.*)?$ - [F,L]
RewriteCond %{REQUEST_URI} ^/([^/]+)/assets/(.*)$
RewriteRule ^ /assets/%2 [L,R=301]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ index.php?page=$1 [QSA,L]
```
Put legacy 301s (`RewriteRule ^old-slug/?$ /new-slug [R=301,L]`) **before** the front controller. Every asset directory and `httpReqs/` contains an `index.php` that renders a styled "Unauthorised Access (E:403)" page to stop directory listing.

## HTTP 404 pages (`httpReqs/`)

Only 404 is supported. Unknown routes return status 404 and render inside the App layout (Navbar/Footer stay):
- `httpReqs/http404Head.php`: head tags for the 404 page:
  ```php
  <?php
  global $phpue_header;
  $phpue_header = <<<HTML
      <title>404 - Page Not Found</title>
      <meta name="description" content="The page you're looking for doesn't exist">
  HTML;
  $GLOBALS['phpue_http404_header'] = $phpue_header;
  ?>
  ```
- `httpReqs/http404.php`: plain PHP/HTML body that replaces `<View/>` (may use `<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>`).
- Without these files the framework prints a default "404 - Page Not Found" heading and default meta.
- A view literally named `views/404.pvue` is also honoured as a fallback when `httpReqs/http404.php` is absent.

## Caching behind NGINX
With `PHPUE_SESSION = 'auto'` (the default, and always in 0.0.2) PHPue starts a session on every request, which makes every response uncacheable (`Set-Cookie` + `no-store`). 0.0.3: set `define('PHPUE_SESSION', 'lazy');` in `index.php`. See `htmx-spa.md` → Caching for the details, the 0.0.2 manual fix, the cache key (`$http_hx_request`) and bypass rules.

## Security notes worth knowing (framework behaviour)

- **AJAX dispatcher.** 0.0.3: only functions marked `@AJAX` can be called through `action=<name>` (GET query or POST JSON), and only with their declared method (405 otherwise); unknown names fall through to the page. **0.0.2:** any request with `action=<name>` calls `<name>()` if *any* function with that name exists, including PHP built-ins and backend helpers. Either way, validate and authorise inside handlers.
- Dev endpoints: 0.0.3 switches `?compile=`, `?hot-reload` and web `?build` off once `.dist/App.php` or `App.php` exists (unless `PHPUE_DEV_TOOLS` is defined). **0.0.2:** `?compile=` and `?build` are not restricted to dev mode. In both, deploy compiled output (Options 1 and 2) and keep `.pvue` sources off production servers.
- `backend/` code runs on every request, including AJAX and 404s. Keep it side-effect-light and never echo from it.
- `{{ $var }}` in the **App header** and `<?= ?>` are unescaped. Escape when you build those strings.

## Framework field notes
Longer-form notes from building the test apps, and the 0.0.3 discussion draft, were kept by the author in PHPue-TechDemo (`dev-notes/0.0.2-field-notes.md`, `dev-notes/0.0.3-proposals.md`). They predate the 0.0.3 release: the shipped behaviour is what the "VERSION 0.0.3" changelog comments at the top of `conversion.php` and `index.php` describe (and the 21-check test bench `www/views/fixes.pvue` in the PHPue repo exercises). Never treat a proposal as available unless the shipped core has it.
