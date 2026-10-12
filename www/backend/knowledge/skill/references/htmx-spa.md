# PHPue + HTMX (+ Alpine/x-ssr): "SSR SPA" patterns

Verified in a working app (built while testing this skill, with NGINX fastcgi_cache measurements). HTMX is optional and not part of PHPue. Use it when the user wants SPA-like navigation without leaving server rendering.

## Navigation: `hx-boost` + `hx-select`
```html
<!-- App.pvue template -->
<div class="app" hx-boost="true" hx-target="#view" hx-select="#view" hx-swap="outerHTML show:window:top">
    <nav> … plain <a href> links … </nav>
    <div id="view"><View/></div>
</div>
```
- Every boosted click still requests the **full SSR page** (App + view), and HTMX swaps only `#view`. It updates `<title>` from the response and pushes the URL. Back/forward work through HTMX's history cache.
- PHPue has **no "render the view without the layout" entry point**, so boosted responses always carry the whole layout (about 9 KB vs about 1 KB for a fragment in the experiment). Make that cheap with caching (below) rather than by hacking the core.
- Everything a page needs must load from the **App header**. View `<header>` CSS/JS is not applied on boosted navigation (only `#view` is swapped). Mark the active nav link in JS on `htmx:afterSettle`, because the nav sits outside `#view`.
- Attributes inherit: `hx-target`/`hx-select` on the layout are inherited by every `hx-*` element inside. Override them per element (`hx-target="#results" hx-select="#results"`) or use `hx-swap="none"` for fire-and-forget actions. Don't put `hx-push-url` on the layout, because mutations would push URLs.

## Mutations: HTMX → `@AJAX` needs JSON
HTMX posts form-encoded bodies, and PHPue's dispatcher only reads a **JSON** body for POST. Add a json-enc extension (same behaviour as htmx's official one, about 10 lines, defined after htmx loads):
```js
htmx.defineExtension('json-enc', {
    onEvent(name, evt) { if (name === 'htmx:configRequest') evt.detail.headers['Content-Type'] = 'application/json'; },
    encodeParameters(xhr, parameters) { xhr.overrideMimeType('text/json'); return JSON.stringify(parameters); }
});
```
```php
<?php $vals = json_encode(['action' => 'addToCart', 'sku' => $product['sku']]); ?>
<button hx-post="/" hx-ext="json-enc" hx-vals="{{ $vals }}" hx-include="#qty" hx-swap="none">Add</button>
```
Use `hx-vals="{{ $json }}"`: `{{ }}` HTML-escapes the JSON, which is correct inside an attribute. GET handlers need nothing extra: `hx-get="/?action=productCards"` (HTMX appends the input's `name=value`). In 0.0.3 the verb must match the declaration (`hx-post` → `@AJAX('POST')`, `hx-get` → `@AJAX('GET')`); a mismatch gets a 405.

**Server → client events:** an `@AJAX` handler can send `header('HX-Trigger: ' . json_encode(['cart-updated' => $summary]))`. The event bubbles to `window`, where a global listener updates an Alpine store (`Alpine.store('cart').set(e.detail)`).

## Partials: two ways
1. **Select from the full page:** `hx-get="/?q=tea" hx-select="#results"`. No extra server code and always identical markup, but full render plus transfer cost.
2. **Render one component standalone:** components are only inlined at compile time, so add a backend helper that reuses the build's compiled copy and falls back to compiling on the fly in source mode:
```php
class PHPueFragment {
    public static function render(string $__component, array $__props = []): string {
        $rel = substr($__component, strlen('components/'), -5) . '.php';
        $__compiled = is_file('.dist/App.php') && is_file('.dist/components/' . $rel) ? '.dist/components/' . $rel
                    : (is_file('App.php') && is_file('components/' . $rel) ? 'components/' . $rel : null);
        $__php = $__compiled === null ? convert_pvue_file($__component) : null;   // conversion.php is always loaded
        extract($__props, EXTR_SKIP);                                            // standalone render has no parent scope, so pass the variables an inline <Component/> would inherit
        ob_start();
        if ($__compiled !== null) include $__compiled; else eval('?>' . $__php);
        return ob_get_clean();
    }
}
// @AJAX('GET') function productCards($input) { echo '<section id="results">' . …PHPueFragment::render('components/shop/ProductCard.pvue', ['product' => $p])… . '</section>'; }
```
Same component, one source of truth for inline SSR and HTMX fragments. Verified in source and `.dist` modes (the extracted-deploy branch follows the same logic but wasn't run).

## Alpine components inside swapped views
After a swap, Alpine's MutationObserver initialises the new `x-data` **before** HTMX runs the swapped `<script>`/`<cscript>`, and `alpine:init` never fires again. A view-local `Alpine.data('cartPage', …)` therefore throws `cartPage is not defined` (verified). Do this instead:
- **Register every view component once, globally** (a JS file loaded in the App header, inside `alpine:init`).
- **Pass SSR data through the markup** as factory arguments: `x-data="cartPage({{ $linesJson }})"` with `Alpine.data('cartPage', (initialLines = []) => ({ lines: initialLines, … }))`. The view then needs no `<cscript>` at all.
- (Fallback, if a view must register its own component: register immediately when Alpine is running, then `Alpine.destroyTree(el); Alpine.initTree(el)` on matching roots. It works, but the first init still logs errors.)
- x-ssr lists inside swapped views work normally (SSR rows adopted, CSR rows created). `x-ssr-template` may also be an inline string expression: `x-ssr-template="'<tr><td>${item.name}</td></tr>'"`.

## Caching: why NGINX cache "is always bypassed", and the fix
**Root cause (verified with nginx:latest + php:8.2-fpm + fastcgi_cache):** PHPue's compiled App starts a session on **every** request (0.0.2 always; 0.0.3 with the default `PHPUE_SESSION = 'auto'`), unless the App script text contains `session_start()`. PHP's session cache limiter then sends `Set-Cookie` + `Cache-Control: no-store, no-cache` + `Pragma: no-cache`, even to anonymous visitors, so NGINX never stores a page: MISS, MISS, MISS.

**Fix on 0.0.3: built-in lazy sessions.** In `index.php` change the session line to `define('PHPUE_SESSION', 'lazy');`. PHPue then only resumes a session the visitor already has (their session cookie is present), so anonymous pages send no `Set-Cookie`/`no-store`.
- Write paths: POST `@AJAX` handlers start a session automatically. Anywhere else (page rendering, GET handlers, backend code) call `phpue_session(true)` before writing `$_SESSION`. Reads need nothing: `$_SESSION` is simply empty for anonymous visitors.
- Extensions and backend code should call `phpue_session()` (resume if present) or `phpue_session(true)` (start) instead of `session_start()`. It's defined in `conversion.php`, so it exists before `backend/` loads.
- Don't put the text `session_start()` in the App script (not even a comment): it switches PHPue's session handling off entirely.
- You still decide the caching headers: e.g. in the App script `if (session_status() !== PHP_SESSION_ACTIVE) header('Cache-Control: public, max-age=30'); header('Vary: HX-Request, Cookie');`.

**Fix on 0.0.2 (manual lazy sessions):**
```php
// App.pvue <script>: mentioning session_start() here switches PHPue's auto-start off
if (isset($_COOKIE[session_name()])) {
    session_start();                                   // returning visitor with a session (cart, login…)
} else {
    header('Cache-Control: public, max-age=30');       // anonymous: cacheable
}
header('Vary: HX-Request, Cookie');
```
- Code that **writes** session data starts the session itself (`if (session_status() === PHP_SESSION_NONE) session_start();`). Code that only **reads** must not start one. Note that MetaControl and other extensions call `session_start()` in their constructors, so with lazy sessions (either version), only instantiate them where a session is acceptable (or accept that those routes aren't cacheable).
- `@AJAX` handlers run **before** the App script, so with 0.0.2 manual lazy sessions they must start the session themselves when they need it.

Both versions:
- NGINX: put `$http_hx_request` in `fastcgi_cache_key` (boosted and normal responses differ for the same URL) and `fastcgi_cache_bypass $cookie_PHPSESSID; fastcgi_no_cache $cookie_PHPSESSID;`.
- `@AJAX('GET')` (`?action=…`) responses are cached too for anonymous visitors. Send `Cache-Control: no-store` from GET handlers that return per-user data.
- Measured on a tiny app: HIT 0.23 ms vs full PHPue render 0.96 ms per request (about 4×, and the gap grows with real DB work). Boosted (`HX-Request`) responses get their own HIT entries.

Remaining trade-off: session holders (cart, logged-in) always bypass and pay the full render, and boosted navigation always transfers the full layout HTML. For heavy personalised pages, prefer fragments (`PHPueFragment`) or Alpine + `@AJAX` JSON over boosted full-page swaps.
