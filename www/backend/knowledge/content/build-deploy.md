# Build, deploy, security and caching

> PHPue serves `.pvue` sources directly in development (compiling on each request) and a precompiled `.dist/` folder in production. Build with `php index.php build` from `www/`, then deploy `.dist/` together with `index.php`, `conversion.php`, `assets/`, `httpReqs/` and `.htaccess`. If `.dist/` exists, it is always used, so delete it while developing.

## Serving modes

`index.php` serves the first of these that exists:

| Priority | Present | Mode | Routes from |
|---|---|---|---|
| 1 | `.dist/App.php` | compiled (production) | `.dist/pages/*.php` |
| 2 | `App.php` in the web root | extracted compiled deploy | `pages/*.php` |
| 3 | `App.pvue` | source (development): compiles on every request | `views/*.pvue` |

**A stale `.dist/` (or stray `App.php`) in a dev checkout means your `.pvue` edits do nothing.** Delete it, or rebuild after every change.

## Development tools

- `cd www && php -S localhost:3000`, then open `http://localhost:3000`. Do not pass `index.php` as a router script.
- `?live`: hot reload (watches `App.pvue`, `views/` and `components/` recursively).
- `?compile=views/page.pvue` (add `&root=true` for `App.pvue`): shows the compiled PHP.
- In 0.0.3, `?compile=`, `?hot-reload` and the web `?build` only work in source mode. `define('PHPUE_DEV_TOOLS', true|false);` in `index.php` overrides this; never force it on in production.
- Docker: `php:8.2-fpm` + `nginx` with `./www` mounted at `/var/www/html` (the PHPue repository includes `docker-compose.yaml` and `nginx/default.conf`).

## Build

```bash
cd www
npm run build            # only if you use the Tailwind CLI: rebuild CSS first
php index.php build      # writes .dist/
```

```text
.dist/
├── App.php            compiled App.pvue (components inlined)
├── pages/<view>.php   every view
├── ajax/              @AJAX handlers: ajax-App.php, ajax-<view>.php, ajax-component-<Name>.php
├── components/        compiled copies (reference only; components are inlined)
├── backend/           copy of backend/
├── httpReqs/          copy of httpReqs/
└── assets/            copy of assets/
```

Rebuild after any change to `.pvue` files, `backend/` or `assets/` (they are copied).

## Deployment options

**1. Compiled `.dist/` (recommended).** Upload `.dist/`, `assets/`, `httpReqs/`, `index.php`, `conversion.php`, `.htaccess`. Block direct web access to `.dist/`. `.dist/` is always served through `index.php` + `conversion.php`; compiled files are never entry points on their own.

**2. Extracted.** Copy the **contents** of `.dist/` into the web root (`App.php`, `pages/`, `ajax/`, `components/`, `backend/`, `httpReqs/`, `assets/`) next to `index.php`, `conversion.php` and `.htaccess`. Block `App.php` and those folders from direct access.

**3. Source on the server.** Upload the `.pvue` sources and let the server compile per request. You must block `.pvue`, `views/`, `components/` and `backend/`, and remember that source mode enables the dev tools. Prefer options 1 or 2.

Any host with PHP 8.0+ works: shared hosting/cPanel, a VPS with nginx or Apache, Docker, FTP upload. No Node.js on the server.

## Apache `.htaccess`

```apache
RewriteEngine On

<FilesMatch "\.pvue$">
    Require all denied
</FilesMatch>
<FilesMatch "^App\.php$">
    Require all denied
</FilesMatch>

# Source + compiled internals
RewriteRule ^(\.dist|ajax|pages|backend|httpReqs|components|views)(/.*)?$ - [F,L]

# Legacy redirects go here, before the front controller
# RewriteRule ^old-page/?$ /new-page [R=301,L]

# Front controller
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ index.php?page=$1 [QSA,L]
```

## nginx

```nginx
server {
    listen 80;
    root /var/www/html;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.pvue$ { deny all; return 404; }
    location ~ ^/(\.dist|ajax|pages|backend|httpReqs|components|views)(/|$) { deny all; return 404; }
    location = /App.php { deny all; return 404; }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass php:9000;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    location ~ /\.ht { deny all; }
}
```

## Security notes

- 0.0.3 only dispatches functions marked `@AJAX`, with their declared method. Still validate and authorise inside every handler.
- `{{ }}` in the App header and `<?= ?>` are unescaped: escape when you build those strings.
- `backend/` runs on every request (including AJAX and 404s): keep it side-effect-light, never echo.
- Keep secrets out of git (`backend/env.php` in `.gitignore`).
- Deploy compiled output and keep `.pvue` sources and dev tools off production.

## Caching and crawlers

With `PHPUE_SESSION = 'auto'` (the default) every response starts a session, which sends `Set-Cookie` and `Cache-Control: no-store`, so nginx, CDNs and proxies never cache a page. For public sites:

1. In `index.php`: `define('PHPUE_SESSION', 'lazy');`
2. Call `phpue_session(true)` before writing `$_SESSION` while rendering (POST `@AJAX` handlers already have a session).
3. Send your own cache headers for anonymous visitors, for example in the App script:

```php
if (session_status() !== PHP_SESSION_ACTIVE) {
    header('Cache-Control: public, max-age=300');
}
```

nginx `fastcgi_cache`: bypass and skip caching when the visitor has a session cookie (`fastcgi_cache_bypass $cookie_PHPSESSID; fastcgi_no_cache $cookie_PHPSESSID;`), and add `$http_hx_request` to the cache key if you use HTMX.

## SEO and GEO checklist

- One `<title>` and meta description per page: static in each view header, or dynamic from the App header via the [MetaControl extension](/docs?topic=extensions).
- Real content in the server-rendered HTML (render lists with `p-for`, not only Alpine `x-for`).
- Canonical URLs, a `sitemap.xml`, a `robots.txt` that allows the crawlers you want, and JSON-LD structured data.
- For AI agents, an `/llms.txt` index and markdown versions of key pages. This site serves them from a `backend/` file that answers those URLs before routing (see its source on [GitHub](https://github.com/PHPue/PHPue-AI)).
- Cacheable anonymous pages (lazy sessions) so crawlers and CDNs get fast responses.
