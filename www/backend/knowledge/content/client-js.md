# cscript, AlpineJS, x-ssr and HTMX

> PHPue renders everything on the server; the browser layer is optional. Use `<cscript>` for plain JavaScript (PHP values arrive through `{{ $var }}`, which is `json_encode`d). Add AlpineJS when a page needs client-side state, and the official **x-ssr** Alpine extension when Alpine must update a list that PHP already rendered. HTMX works for SPA-like navigation without leaving server rendering.

## PHP data in `<cscript>`

```html
<cscript>
    const count = {{ $count }};                 // 3
    const fruits = {{ $fruits }};               // ["Apple","Pear"]
    const user = {{ $user }};                   // {"name":"Ada"}
    const items = {{ $data['items'] }};         // any $ expression (0.0.3)
    const total = <?= json_encode(count($fruits)) ?>;   // function calls: json_encode yourself
</cscript>
```

- `{{ $expr }}` becomes `json_encode($expr, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP)`. **Never wrap it in quotes**: `"{{ $name }}"` gives `""Ada""`.
- Function calls are not converted inside cscript; use `<?= json_encode(...) ?>`.
- A cscript with a line starting `import`/`export` becomes `<script type="module">`; module functions are not global, so assign `window.fn = fn` for inline `onclick` handlers.
- It runs where it is printed (after its markup). Use `DOMContentLoaded` for elements further down.
- Vanilla style used by the author: global functions plus inline handlers (`<button onclick="addToCart(3)">`).

## AlpineJS

Load Alpine with `defer` from the App header (site-wide) or a view header (one route). Register components inside `alpine:init`, in a `<cscript>` or a non-deferred script, so they exist before Alpine starts.

```html
<!-- views/counter.pvue -->
<script>
    @AJAX('POST')
    function saveCount($input) {
        $_SESSION['count'] = max(0, (int) ($input['count'] ?? 0));
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
    }

    $start = (int) ($_SESSION['count'] ?? 0);
</script>

<header>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js"></script>
</header>

<template>
    <div x-data="counter">
        <button @click="increment()">Clicked <span x-text="count">{{ $start }}</span> times</button>
    </div>
</template>

<cscript>
    document.addEventListener('alpine:init', () => {
        Alpine.data('counter', () => ({
            count: {{ $start }},
            async increment() {
                this.count++;
                await fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'saveCount', count: this.count })
                });
            }
        }));
    });
</cscript>
```

Rules:

- All Alpine attributes pass through untouched: `x-data`, `x-show`, `x-text`, `x-model`, `:class`, `@click`, `<template x-for>`, `<template x-if>` (close every `<template>`).
- **Render the server value as fallback content** inside `x-text` elements (`<span x-text="count">{{ $start }}</span>`), so the page is correct before Alpine boots and for crawlers.
- Do not use mustache output for Alpine: `{{ count }}` stays literal and `{{ total() }}` is compiled as a PHP call. Use `x-text`.
- Alpine `@click` never conflicts with PHPue's `@require`/`@AJAX`, which only exist inside `<script>`.
- `x-model` on form fields is fine when the state lives in the browser. PHPue itself has no binding (`p-model` does not exist).
- An `x-for` list has no server-rendered rows, so crawlers and the first paint see nothing. For SEO-relevant lists, render with `p-for` and hand over to x-ssr.

## x-ssr: keep the server-rendered list, update it from Alpine

The official **x-ssr** extension (by Edward Patch, in [PHPue-Extensions](https://github.com/PHPue/PHPue-Extensions), branch `Client-Side-Extensions`) is a keyed list directive. It **adopts the server-rendered items as they are**, then adds, removes and reorders items when the Alpine array changes, without throwing away or flickering the SSR markup.

```html
<header>
    <script defer src="/assets/js/fworks/alpine.min.js"></script>
    <!-- not defer/async: it must register before Alpine starts -->
    <script src="/assets/js/phpue-ext-csr/alpine.ext.min.ssr.js"></script>
</header>

<template>
    <div x-data="taskList">
        <input x-model="filter" placeholder="Filter tasks">
        <ul x-ssr="visibleTasks" x-ssr-key="id" x-ssr-template="taskTemplate">
            <li p-for="$task in $tasks" data-id="{{ $task['id'] }}">{{ $task['title'] }}</li>
        </ul>
    </div>
</template>

<cscript>
    document.addEventListener('alpine:init', () => {
        Alpine.data('taskList', () => ({
            tasks: {{ $tasks }},
            filter: '',
            get visibleTasks() {
                return this.tasks.filter(t => t.title.toLowerCase().includes(this.filter.toLowerCase()));
            },
            taskTemplate: {
                html: `<li>\${item.title}</li>`,
                process(el, item, component) { /* wire events, nested data */ }
            }
        }));
    });
</cscript>
```

- `x-ssr="expr"` must evaluate to an array; `x-ssr-key` names the identity property (default `id`); `x-ssr-template` names the template on the component (default `csrTemplate`).
- Each SSR item needs `data-{key}` equal to `String(item[key])` in the Alpine array. Put `p-for` on the keyed element itself.
- SSR nodes are kept as they are (never re-rendered); client-rendered nodes are rebuilt on every change. To update an item's content, give it a key that includes a revision (`sku@3`).
- Template `html` uses `${item.prop}` placeholders (top-level properties only, HTML-escaped). Inside a JS template literal escape them as `\${item.prop}`. One root element. `process(el, item, component)` runs after each client-rendered node is created.
- Works on `<tbody>` / `<tr>` for tables, inside empty containers for pure client lists, and with `x-intersect` for infinite scroll (first page in PHP, more via `@AJAX('GET')`).
- Builds: `alpine.ext.dev.ssr.js` (console tracing), `alpine.ext.prod.ssr.js`, `alpine.ext.min.ssr.js`.
- Keep the PHP filter/pagination logic and the JS getters identical, and sync state to the URL (`history.pushState`) so a reload server-renders the same view.

## HTMX (optional)

PHPue works with HTMX for SPA-like navigation that stays server-rendered:

```html
<!-- App.pvue template -->
<div hx-boost="true" hx-target="#view" hx-select="#view" hx-swap="outerHTML show:window:top">
    <Navbar/>
    <div id="view"><View/></div>
</div>
```

- Boosted clicks still request the full page; HTMX swaps `#view` and updates the title and URL. Load all CSS/JS from the **App header** (view headers are not re-applied after a swap).
- HTMX posts form-encoded data, but `@AJAX` POST needs JSON: add a small `json-enc` extension (`htmx.defineExtension('json-enc', ...)` that sets `Content-Type: application/json` and returns `JSON.stringify(parameters)`), then `<button hx-post="/" hx-ext="json-enc" hx-vals="{{ $vals }}">` where `$vals = json_encode(['action' => 'addToCart', 'sku' => $sku])`. `hx-get="/?action=name"` needs nothing extra. The verb must match the `@AJAX` method.
- Handlers can send `header('HX-Trigger: ' . json_encode(['cart-updated' => $summary]))`.
- Register Alpine components globally (App-level JS) and pass server data as factory arguments (`x-data="cartPage({{ $linesJson }})"`); a view-local `Alpine.data()` is not defined yet when a swapped view initialises.

### Rendering one component as a partial

```php
<?php
// backend/PHPueFragment.php
class PHPueFragment {
    public static function render(string $__component, array $__vars = []): string {
        $rel = substr($__component, strlen('components/'), -5) . '.php';
        $__compiled = is_file('.dist/App.php') && is_file('.dist/components/' . $rel) ? '.dist/components/' . $rel
                    : (is_file('App.php') && is_file('components/' . $rel) ? 'components/' . $rel : null);
        $__php = $__compiled === null ? convert_pvue_file($__component) : null;
        extract($__vars, EXTR_SKIP);   // the variables an inline <Component/> would inherit
        ob_start();
        if ($__compiled !== null) include $__compiled; else eval('?>' . $__php);
        return ob_get_clean();
    }
}
```

`@AJAX('GET') function productCard($input) { echo PHPueFragment::render('components/ProductCard.pvue', ['product' => findProduct($input['sku'] ?? '')]); }`: one component, used inline and as a fragment.
