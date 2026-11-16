# PHPue + AlpineJS + x-ssr

PHPue renders the page on the server. AlpineJS adds browser-side state. The **x-ssr** extension (`assets/js/phpue-ext-csr/alpine.ext.*.ssr.js`, by Edward Patch) is a keyed list directive. It **adopts the server-rendered list items as they are** and only creates or removes client-rendered items when Alpine data changes. That gives you CSR updates without throwing away or flickering the SSR markup, which `x-for` can't do.

> Rule of thumb from the author: "PHP owns the server. AlpineJS owns the browser. PHP handles data. AlpineJS handles clicks."

All PHPue Vanilla rules still apply (see `vanilla.md`), because the file is still compiled by the regex engine. The Alpine-specific rules below sit on top.

## Contents
1. Setup (script order)
2. What Alpine may and may not do inside .pvue
3. Registering components and seeding state from PHP
4. The x-ssr directive: API and exact semantics
5. CSR templates and `process()`
6. Patterns: SSR list + CSR filtering/pagination (TechDemo Browse); session-synced app state (FreeCAD); pure-CSR dashboards (Analytics)
7. AJAX from Alpine
8. Checklist

---

## 1. Setup

Load both scripts in a `<header>`: App-wide in `App.pvue` (FreeCAD, Analytics) or per route in a view header (TechDemo `ue-tutorials.pvue`):
```html
<header>
    <script defer src="/assets/js/fworks/alpine.min.js"></script>
    <!-- Load alpine.ext.min.ssr.js (Deferring / Async, will cause timing issues!) -->
    <script src="/assets/js/phpue-ext-csr/alpine.ext.min.ssr.js"></script>
</header>
```
- Alpine is **`defer`**. The x-ssr extension is a **plain synchronous script** (never `defer`/`async`). It registers `Alpine.directive('ssr', …)` in an `alpine:init` listener, and if Alpine is already on `window` it dispatches `alpine:init` itself.
- Extension builds: `alpine.ext.dev.ssr.js` (`DEVMODE = true` console tracing, good for debugging), `alpine.ext.prod.ssr.js` (readable, no logs), `alpine.ext.min.ssr.js` (production). Same behaviour.
- Cache-bust with `?v=YYYYMMDD-n` when the file changes.
- Any `<script>` that defines Alpine components (`Alpine.data(...)` in `alpine:init`) must also run **before** Alpine starts. A non-deferred `<script src>` in a view header (Analytics `forms.js`) or a `<cscript>` (inline in the body, which runs before deferred scripts) both work.
- If you use `x-cloak`, add `[x-cloak]{display:none!important}` to your CSS.

## 2. Alpine inside .pvue: allowed vs forbidden

✅ Works as-is (the compiler ignores these attributes): `x-data`, `x-init`, `x-show`, `x-text`, `x-html`, `x-model`, `x-model.number`, `x-ref`, `x-cloak`, `x-effect`, `:class`, `:style`, `:disabled`, `:value`, `@click`, `@click.prevent`, `@submit.prevent`, `@keydown.window`, `@change`, `@input`, `$refs`, `$el`, `$watch`, `$nextTick`.
`@click` in a template never conflicts with `@require`/`@AJAX`, which are only parsed inside `<script>`.

✅ 0.0.3 also allows:
- `<template x-for>` / `<template x-if>` (and any nested `<template>`), as long as every one is closed. Don't put `<template>` text inside a JS string in an inline `<script>` (it counts toward the nesting).
- `>`/`=>` and `{{ }}` in the other attributes of a `p-if`/`p-for` element (`<div p-if="$ok" x-show="n > 0">`).

Still prefer, for SSR-first pages: `p-for` (or `<?php foreach ?>`) with per-item `x-data` for a server list that doesn't change client-side, **`x-ssr`** for a list Alpine must add to, remove from, reorder or filter (an `x-for` list has no server-rendered rows, so crawlers and first paint see nothing), and `x-show` + `x-cloak` for conditional blocks that should be in the HTML.

❌ Never:
- Mustache-style Alpine output. `{{ count }}` isn't Alpine syntax (PHPue leaves it literal), and in 0.0.3 `{{ total() }}` is compiled as a **PHP** function call. Use `x-text`.
- **0.0.2 only:** `<template x-for>` / `<template x-if>` / any `<template>` tag (PHPue's `<template>` block stops at the first `</template>` and everything after it disappears; use `p-for`, `x-ssr` or `x-show`), and attributes containing `>` (`x-show="n > 0"`, arrow functions `@click="items.map(i => i)"`) on an element that **also has `p-if`** (that line gets truncated; move the logic into an Alpine method or getter such as `x-show="hasItems"`, or wrap with a separate element). On ordinary elements `>` in attributes is fine in both versions (Analytics uses multi-line arrow-function `x-ssr` expressions).

## 3. Registering components and seeding state

**Named component in `<cscript>`** (TechDemo Browse, FreeCAD):
```html
<template>
    <div x-data="tutorialBrowser"> … </div>
</template>

<cscript>
    document.addEventListener('alpine:init', () => {
        Alpine.data('tutorialBrowser', () => ({
            activeCategories: <?= json_encode($activeCategories) ?>,
            allTutorials: {{ $tutorialLibrary }},          // json_encode via cscript {{ }} (0.0.3: any $expr, e.g. {{ $data['items'] }})
            currentPage: <?= $page ?>,
            perPage: <?= $perPage ?>,
            init() { /* $watch, $nextTick … */ },
        }));
    });
</cscript>
```
**External file** (Analytics): `assets/js/handlers/forms.js` wraps everything in an IIFE and calls `Alpine.data('csvAnalytics', …)` in `alpine:init`. The view's `<cscript>` exposes server data on `window` first:
```html
<cscript>
    let csv = {{ $latestCSV }};
    window.latestCSV = csv;
</cscript>
```
and the component reads `window.latestCSV?.analytics_all ?? null`.

**Inline x-data with PHP values** (AlpineJS Foundations tutorial):
```html
<div x-data="{ count: {{ $initialCount }} }">
    <button @click="count++">
        Count: <span x-text="count">{{ $initialCount }}</span>
    </button>
</div>
```
`{{ }}` is HTML-escaped, which is correct inside an attribute because the browser decodes it. For arrays in an attribute use `x-data="{ items: <?= htmlspecialchars(json_encode($items), ENT_QUOTES) ?> }"`, or better, a named component.

**Always render the SSR value as fallback content** inside `x-text`/`x-show` elements. The page is correct before Alpine boots, and Alpine then takes over without a visible change. This is the core of "CSR that respects SSR".

## 4. The x-ssr directive

```html
<div x-ssr="visibleTutorials"           ← Alpine expression that evaluates to an ARRAY (reactive)
     x-ssr-key="slug"                   ← item property used as identity (default "id")
     x-ssr-template="tutorialCardTemplate"  ← template object/string on the component (default "csrTemplate")
     class="grid md:grid-cols-3 gap-6">
    <?php foreach ($initialTutorials as $tutorial): ?>
        <div data-slug="<?= htmlspecialchars($tutorial['slug']) ?>"> … server markup … </div>
    <?php endforeach; ?>
</div>
```
It must be inside an `x-data` scope.

Exact behaviour, from `alpine.ext.dev.ssr.js`:
1. **Adopt SSR items (once, at init).** It collects direct children that have `data-{key}`, plus any `[data-{key}]` elements **anywhere inside direct-child `<div>`s** (deep search). It maps them by attribute value, and the map is kept: if a key leaves the array and later returns, the original server node is **re-adopted** rather than re-created.
   - **Put `p-for` on the keyed element itself**: `<article p-for="$t in $tickets" data-id="{{ $t['id'] }}">` (in 0.0.2, children must not use the same tag), or `<tr p-for>` in a `<tbody x-ssr>`. This gives valid HTML before and after hydration.
   - **0.0.2:** the official examples' wrapper shape (`<div p-for>` around `<div data-id>`) closes the loop at the inner `</div>`, so N items emit N unclosed, nested wrapper `<div>`s. x-ssr then re-appends every adopted item as a **direct child** of its container, so the list itself looks right after hydration. The damage is to markup **after** the list: the unclosed wrappers swallow following siblings (in Experiment 03 a sibling list ended up inside the first container, and a 2-column grid rendered 1 child), and a second x-ssr placed after it gets its server nodes cross-adopted and rebuilt as CSR. Before Alpine runs (no-JS, crawlers) the HTML is malformed. It's safe only when the list is the last thing in its parent, as in the official examples. 0.0.3 closes each wrapper correctly, so the markup is valid there, but the keyed element as the direct child remains the clearest shape.
2. **Effect (re-runs whenever the expression's reactive dependencies change):**
   - Each data item's key is `String(item[key])` for objects, or `String(item)` for primitives.
   - DOM items whose key isn't in the array are **removed**.
   - For each array key in order:
     - If there's an existing SSR node with that key, it's **kept as-is** (never re-rendered with new data).
     - If there's an existing CSR node, it's **re-rendered** from the template (replaced) on every run.
     - Otherwise a new CSR node is created from the template.
     - The node is then `appendChild`-ed, which moves it into array order.
3. `data-{key}` is set automatically on CSR nodes.

Consequences:
- **The SSR markup's `data-{key}` value must equal `String(item[key])`** in the Alpine array. Otherwise the server node is removed and a duplicate CSR node is created (a visible flash). Use the same source data for both (same PHP array, `json_encode`d).
- SSR nodes are static. If their content must change client-side, bind those parts with Alpine (`x-text`, attribute bindings) or style them from a method (TechDemo `styleAllPageButtons()` after `$nextTick`).
- **State classes on SSR markup:** Alpine's `:class` never removes classes that were in the server-rendered `class` attribute, so a baked-in `class="btn primary"` stays highlighted forever. Bind an **attribute** that Alpine fully replaces, e.g. `aria-current="{{ $isCurrent }}" :aria-current="p === 2 ? 'page' : 'false'"`, style it with `.btn[aria-current=page]`, and use the same binding in the CSR template.
- **Updating an item's content (the rev-key pattern):** give each item a key that includes a revision, `key = sku@rev`. The server bumps `rev` on every write and returns the decorated item, and Alpine replaces it in the array. The old key disappears, so x-ssr removes the stale (possibly SSR) row and renders a fresh CSR row in the same sorted position. Plain `sku` keys would leave an SSR row showing old values.
- CSR nodes are rebuilt on every change, so don't keep state in CSR DOM. Keep it in Alpine data and re-apply it in `process()`.
- The expression can be a getter (`get visibleTutorials()`), a property, or an inline expression. Analytics uses multi-line expressions:
  ```html
  <div x-ssr="Object.entries(analytics?.top_landing_pages ?? {})
          .slice(0, 8)
          .map(([page, count]) => ({ id: page, page, count }))"
       x-ssr-key="id" x-ssr-template="landingPageTemplate"></div>
  ```
- Empty containers work: pure client rendering with keyed diffing (toasts, layers, workbench rows).
- **Tables:** on `<tbody>`, `<thead>`, `<tfoot>` (rows) or `<tr>` (cells), templates are parsed in a table context, so `<tr>`/`<td>` templates work:
  ```html
  <tbody x-ssr="analytics ? workbenchRows : []" x-ssr-key="key" x-ssr-template="workbenchTemplate"></tbody>
  ```
- The expression must return an array. Otherwise it logs `x-ssr: Expression must evaluate to an array` and does nothing. Guard with `?? []` or `analytics ? rows : []`.

## 5. CSR templates and `process()`

Define templates as properties of the Alpine component (the root `x-data` object):
```js
tutorialCardTemplate: {
    html: `
        <div data-slug="\${item.slug}">
            <a href="/ue-tutorials?tutorialName=\${item.slug}" class="group block rounded-2xl …">
                <h3 class="text-xl font-bold">\${item.name}</h3>
                <p class="text-sm">\${item.description}</p>
                <div class="flex gap-1 categories-container"></div>
            </a>
        </div>
    `,
    process(el, item, component) {
        const container = el.querySelector('.categories-container');
        if (container && item.categories) {
            container.innerHTML = item.categories.map(cat =>
                `<span class="text-xs …">${cat}</span>`).join('');
        }
    }
},
```
- `html` is a string with `${item.prop}` placeholders. **Inside a JS template literal, escape them as `\${item.prop}`** so JS doesn't interpolate them (which would throw `item is not defined`). In `'…'`/`"…"` strings no escaping is needed. A plain string is also accepted as the template.
- Only **top-level properties** are substituted (`${item.a.b}` is not). Values are HTML-escaped (`& < > " '`). `null`/`undefined` becomes an empty string. Placeholders for missing properties stay literal.
- The template must produce **one root element** (the first node after trim is used).
- `process(el, item, component)` runs after creation. Use it for nested arrays, event wiring (`btn.onclick = () => component.selectObject(item.id)`), conditional classes or styles, timers (toast auto-dismiss), or Three.js canvas setup. `component` is the outermost `x-data` object.
- Alpine directives inside template HTML (`@click="goToPage(\${item.page})"`) work, because Alpine initialises inserted nodes.
- To force CSR nodes to refresh when only an outside value changes (selection, version), include it in the mapped items. FreeCAD's `get objectsList()` adds `_version: this.objectsVersion`.
- Template lookup order: `component[templateName]`, then `component.$data[templateName]`, then `evaluate(templateName)`.

## 6. Patterns

### A0. Worked example built while testing this skill (inventory table with sort, filter, paging and CRUD via the rev-key pattern; kanban with three x-ssr columns over one array plus drag and drop; infinite-scroll feed using IntersectionObserver + `@AJAX('GET')`). Browser-verified: client rows equal the PHP-rendered rows for the same URL after every interaction.

### A. SSR list + client filtering/pagination with shareable URLs (TechDemo `components/ueTutorials/Browse.pvue`)
1. `<script>`: PHP reads `$_GET['category']` and `$_GET['pagination']`, filters and paginates `$tutorialLibrary` into `$initialTutorials` (what the server shows first).
2. `<template>`: `x-data="tutorialBrowser"`. Filter buttons are rendered by PHP `foreach` with `@click="toggleCategory('<?= $cat ?>')"` and `:class`. The grid is `x-ssr="visibleTutorials" x-ssr-key="slug"` containing the SSR cards with `data-slug`. Pagination uses a second `x-ssr` with `x-ssr-key="page"`.
3. `<cscript>`: Alpine gets the **full** dataset plus the current state (`json_encode`), getters `filteredTutorials`, `totalPages`, `visibleTutorials` and `pageNumbers` mirror the PHP logic exactly, and `updateURL()` uses `history.pushState` to keep `?category=…&pagination=…` in sync. A reload or shared link therefore SSRs the same state.

**Rule:** the PHP filter/pagination logic and the JS getters must produce the same items and keys for the same URL.

### B. Session-synced app state (FreeCAD `views/index.pvue`)
- PHP rebuilds `$scenes` from `$_SESSION['freecad_data']` and SSRs the canvases inside `x-ssr="scenes"` (`data-id` on `.scene-container`).
- `backendScenes: {{ $scenes }} || []` seeds Alpine. `init()` rehydrates (`appendBackendScenes()`) and renders Three.js in `sceneTemplate.process()`.
- Alpine methods call `@AJAX` handlers (`createCube`, `syncFreeCADData`, …) with `fetch(window.location.pathname, …)`. `syncFreeCADData` writes the scene back to `$_SESSION`, so the next SSR matches the client.
- Several `x-ssr` panels (layers, objects, entity browser, toasts) are pure CSR lists with `process()` wiring clicks back to component methods.

### C. Pure-CSR analytics dashboard (Analytics `views/index.pvue` + `assets/js/handlers/forms.js`)
- The view passes `$_SESSION['latest_csv']` to `window.latestCSV` through `<cscript>`.
- Uploads use `FormData` to a **backend endpoint** (`fetch('?api&endpoint=fileUpload', {method:'POST', body: formData})`, handled in `backend/forms/submit.php`, which stores results in the session), not `@AJAX`, because `@AJAX` only reads JSON and this is a multipart **file upload**. That is the narrow case for a backend endpoint (the other is a raw/non-JSON response: file download, image, stream). **Do not use a backend endpoint for ordinary JSON actions — use `@AJAX`** (§7). Everything else on this dashboard that talks to the server is an `@AJAX` handler.
- Getters (`workbenchRows`, …) feed `x-ssr` tables. `process()` makes cells clickable (`self.workbench.search = item.key`), which updates the search, which updates the rows, which re-renders.
- Detail views (traffic-depth, …) use plain `<cscript>` + Chart.js and server components (`<DetailedView/>`, `<CSVDetected/>`) that read variables the view sets first (`<?php $title = …; ?>`).

## 7. AJAX from Alpine

`@AJAX` is PHPue's built-in way for the browser to call the server. **Use it by default** for every Alpine action that needs the server (forms, buttons, basket/cart updates, toggles), on 0.0.3 and 0.0.2 alike. The standard PHPue + Alpine + x-ssr loop:

1. An Alpine action calls `fetch` on the page path with `{action: '<handler>', ...}`.
2. The `@AJAX` handler in the view updates session/DB and echoes JSON.
3. Alpine replaces its state with the response.
4. x-ssr swaps only the rows whose key changed.
5. A reload SSRs the same state from the session.

Keep the handler body thin and put logic and validation in a `backend/` class. **0.0.2** is not a reason to skip this: recommend upgrading to 0.0.3 (replace `index.php` + `conversion.php` together), and if the project must stay on 0.0.2, still use `@AJAX` with a method check inside the handler, no helper functions named after actions, and full input validation (`vanilla.md` §10). Don't invent a parallel endpoint in `backend/`.

### Worked example: session basket (run on 0.0.3 and 0.0.2 while writing this)

**Client fetch body** (Alpine method; the whole component is below):
```js
body: JSON.stringify({ action: 'menuBasket', token: this.token, id, op })   // op: 'add' | 'remove'
```

**View handler** (`views/menu.pvue`). One line of logic, in the view:
```html
<script>
    // 0.0.3 with PHPUE_SESSION = 'lazy': call phpue_session(true) first, because token() writes $_SESSION.
    $menu        = MenuBasket::menu();
    $basket      = MenuBasket::state();
    $basketToken = MenuBasket::token();

    @AJAX('POST')
    function menuBasket($input) {
        MenuBasket::respond($input);
    }
</script>
```

**Backend class** (`backend/Menu/MenuBasket.php`). `state()` feeds both the SSR script and the JSON response, so server rows and client rows always have the same keys:
```php
<?php
    class MenuBasket {
        private const MAX_QUANTITY = 20;
        private const MENU = [
            'margherita' => ['name' => 'Margherita', 'unitPence' => 950],
            'diavola'    => ['name' => 'Diavola',    'unitPence' => 1150],
        ];

        public static function menu(): array {
            $menu = [];
            foreach (self::MENU as $id => $item) $menu[] = ['id' => $id] + $item + ['priceLabel' => self::money($item['unitPence'])];
            return $menu;
        }

        // CSRF token, stored in the session and seeded into Alpine by the view.
        public static function token(): string {
            return $_SESSION['menu_basket_token'] ??= bin2hex(random_bytes(32));
        }

        public static function state(): array {
            $rows  = [];
            $total = 0;
            foreach ($_SESSION['menu_basket'] ?? [] as $id => $quantity) {
                if (!isset(self::MENU[$id])) continue;
                $unitPence = self::MENU[$id]['unitPence'];
                $total    += $quantity * $unitPence;
                $rows[]    = [
                    'key'       => "$id:$quantity:$unitPence",      // rev-key: changes whenever the row's content changes
                    'id'        => $id,
                    'name'      => self::MENU[$id]['name'],
                    'quantity'  => $quantity,
                    'lineLabel' => self::money($quantity * $unitPence),
                ];
            }
            return ['rows' => $rows, 'totalLabel' => self::money($total)];
        }

        public static function respond($input): void {
            header('Content-Type: application/json');
            // 0.0.3 already enforces the declared method (405). This check is what protects a 0.0.2 project.
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { self::fail(405, 'POST only'); return; }
            if (!is_array($input) || !hash_equals(self::token(), (string)($input['token'] ?? ''))) { self::fail(403, 'Bad token'); return; }

            $id = (string)($input['id'] ?? '');
            if (!isset(self::MENU[$id])) { self::fail(422, 'Unknown item'); return; }

            $quantity = (int)($_SESSION['menu_basket'][$id] ?? 0);
            switch ($input['op'] ?? '') {
                case 'add':    $quantity++; break;
                case 'remove': $quantity--; break;
                default:       self::fail(422, 'Unknown operation'); return;
            }
            $quantity = max(0, min(self::MAX_QUANTITY, $quantity));

            if ($quantity === 0) unset($_SESSION['menu_basket'][$id]);
            else $_SESSION['menu_basket'][$id] = $quantity;

            echo json_encode(['ok' => true, 'basket' => self::state()]);
        }

        private static function fail(int $status, string $error): void {
            http_response_code($status);
            echo json_encode(['ok' => false, 'error' => $error]);
        }

        private static function money(int $pence): string {
            return '£' . number_format($pence / 100, 2);
        }
    }
?>
```

**Template and Alpine state** (same view). Rows are keyed `id:quantity:unitPence`, so a quantity or price change gives a new key: x-ssr removes the stale row (server-rendered or not) and renders a fresh one, while untouched rows keep their original server node.
```html
<template>
    <main x-data="menuBasket">
        <ul>
            <?php foreach ($menu as $item): ?>
                <li>
                    <?= htmlspecialchars($item['name']) ?> (<?= htmlspecialchars($item['priceLabel']) ?>)
                    <button type="button" @click="change('<?= htmlspecialchars($item['id'], ENT_QUOTES) ?>', 'add')">Add</button>
                </li>
            <?php endforeach; ?>
        </ul>

        <ul x-ssr="basket.rows" x-ssr-key="key" x-ssr-template="rowTemplate">
            <?php foreach ($basket['rows'] as $row): ?>
                <li data-key="<?= htmlspecialchars($row['key'], ENT_QUOTES) ?>">
                    <span><?= htmlspecialchars($row['name']) ?></span> × <span><?= $row['quantity'] ?></span>
                    <span><?= htmlspecialchars($row['lineLabel']) ?></span>
                    <button type="button" @click="change('<?= htmlspecialchars($row['id'], ENT_QUOTES) ?>', 'remove')">Remove one</button>
                </li>
            <?php endforeach; ?>
        </ul>
        <p>Total: <span x-text="basket.totalLabel">{{ $basket['totalLabel'] }}</span></p>
    </main>
</template>

<cscript>
    document.addEventListener('alpine:init', () => {
        Alpine.data('menuBasket', () => ({
            basket: {{ $basket }},
            token: {{ $basketToken }},
            busy: false,

            rowTemplate: {
                html: `
                    <li>
                        <span>\${item.name}</span> × <span>\${item.quantity}</span>
                        <span>\${item.lineLabel}</span>
                        <button type="button" @click="change('\${item.id}', 'remove')">Remove one</button>
                    </li>
                `
            },

            async change(id, op) {
                if (this.busy) return;
                this.busy = true;
                try {
                    const res = await fetch(window.location.pathname, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'menuBasket', token: this.token, id, op })
                    });
                    const data = await res.json();
                    if (data.ok) this.basket = data.basket;      // x-ssr swaps only the rows whose key changed
                } finally {
                    this.busy = false;
                }
            }
        }));
    });
</cscript>
```
Checked behaviour: bad token → 403, unknown item → 422, GET → 405 (from the dispatcher on 0.0.3, from `respond()` on 0.0.2); adding another item keeps the existing server row's DOM node; changing a row's quantity replaces just that row; a reload renders the same rows and total from the session.

### Generic call shape

```js
async createCube() {
    this.isLoading = true;
    try {
        const res = await fetch(window.location.pathname, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'createCube', size: 10 })
        });
        const data = await res.json();          // handler must echo json_encode(...)
        if (data.status === 200) { /* update reactive state → x-ssr re-renders */ }
    } catch (e) {
        this.showToast('Network error: ' + e.message, 'error');
    } finally {
        this.isLoading = false;
    }
}
```
The handler goes in the **view** (`@AJAX('POST') function createCube($input) { …; echo json_encode([...]); }`). Post to the current path so production `.dist` and dev both resolve it.

## 8. Checklist for Alpine + x-ssr work

- [ ] Alpine `defer`, x-ssr extension not deferred, both in App or view `<header>`.
- [ ] `Alpine.data()` registered inside `alpine:init`, in `<cscript>` or a non-deferred script.
- [ ] 0.0.2: no `<template …>` tags anywhere in the `.pvue`. 0.0.3: every nested `<template>` is closed.
- [ ] Server data passed with `{{ $var }}` (cscript; 0.0.2 simple variables only, 0.0.3 any `$` expression) or `<?= json_encode(...) ?>`.
- [ ] SSR fallback text inside every `x-text` element that shows server data.
- [ ] Every SSR item inside `x-ssr` has `data-{key}` equal to the JS item's key (direct child, or inside one wrapper `<div>`).
- [ ] CSR template literals use `\${item.prop}`, one root element, nested data handled in `process()`.
- [ ] PHP and JS filtering/pagination logic match. URL state via `history.pushState`.
- [ ] 0.0.2: no `p-if` on elements carrying `>`-containing Alpine attributes, and no `p-for` + `p-if` on the same element (both fine in 0.0.3).
- [ ] `@AJAX` handlers live in the view/App (0.0.2 drops component handlers from the build), echo JSON, and are called with their declared method (0.0.3 enforces it; 0.0.2: check it in the handler). Logic and validation sit in a `backend/` class.
- [ ] Every ordinary JSON action is an `@AJAX` handler. A backend endpoint exists only for multipart file uploads or non-JSON responses.
