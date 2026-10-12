# Components and inherited scope

> A PHPue component is any `.pvue` file pulled in with `@require Name 'path.pvue';` and placed with `<Name/>`. At compile time its PHP, markup and cscript are **inlined** where the tag is, so it runs in the parent's scope and sees every variable the parent has, exactly like a PHP `include`. That is why PHPue has **no props, slots or emits**: set a variable before the tag, and the component reads it.

## Declare and place

```html
<!-- views/products.pvue -->
<script>
    @require ProductCard 'components/cards/ProductCard.pvue';
    @require EmptyState 'components/EmptyState.pvue';

    $products = [
        ['name' => 'Tea', 'price' => 3.5],
        ['name' => 'Coffee', 'price' => 4.0],
    ];
</script>

<template>
    <section class="grid">
        <ProductCard p-for="$product in $products" />
    </section>
    <EmptyState p-if="count($products) === 0"/>
</template>
```

```html
<!-- components/cards/ProductCard.pvue -->
<script>
    // $product comes from the parent's p-for. Default it so the card also works on its own.
    $product = $product ?? ['name' => 'Unknown', 'price' => 0];
</script>

<template>
    <article class="card">
        <h3>{{ $product['name'] }}</h3>
        <p>£{{ number_format($product['price'], 2) }}</p>
    </article>
</template>
```

- `@require` takes single or double quotes; the path is relative to the web root (`www/`).
- Tags: `<Name/>`, `<Name />` or `<Name></Name>`. **No other attributes and no children.** The only exceptions are `p-if` and `p-for` on the tag (0.0.3).
- Components can `@require` other components. Paths are always from the web root.
- A missing file compiles to a comment and renders nothing. Using `<Name/>` without `@require` renders nothing useful either.

## Giving a component data (instead of props)

Set variables right before the tag, in raw PHP:

```html
<?php
    $panelTitle = 'Traffic';
    $panelRows  = $trafficRows;
?>
<StatsPanel/>

<?php
    $panelTitle = 'Revenue';
    $panelRows  = $revenueRows;
?>
<StatsPanel/>
```

```php
// components/StatsPanel.pvue <script>: normalise what you inherit
$panelTitle = $panelTitle ?? '';
$panelRows  = (isset($panelRows) && is_array($panelRows)) ? $panelRows : [];
```

- Use specific names (`$panelTitle`, not `$title`): a component's writes go straight into the parent scope and stay visible after it.
- Inside a loop no setup is needed: the component reads the loop variable.
- Do **not** invent a props mechanism (attributes, a `$props` array, `extract()`). It does not exist and is not needed.

## Rules for component code

- **No named functions or classes** in a component's `<script>`. The code runs once per placement, so a second use (or a `p-for`) fails with `Cannot redeclare`. Put helpers in `backend/` or use closures (`$fmt = fn($n) => number_format($n, 2);`).
- **`<header>` is ignored** in components. Put CSS in a `<style>` inside the component's template, or in the App/view header.
- **`<cscript>` repeats** every time the component renders. Top-level `let`/`const` then throws `Identifier has already been declared`. Use `function` declarations, `var`, or wrap the code in an IIFE with a guard:

```js
(function () {
    if (window.__cartWidgetReady) return;
    window.__cartWidgetReady = true;
    document.addEventListener('click', function (e) { /* ... */ });
})();
```

- **`@AJAX` in components** works in 0.0.3 (handlers are global and built into `.dist/ajax/ajax-component-<Name>.php`). Keeping handlers in the view that hosts the component is still the clearest layout.
- Avoid these variable names, which the compiled preamble resets: `$routing`, `$current_route`, `$b404`, `$input`, `$action`.

## Components in App.pvue vs views

Components placed in `App.pvue` (navbar, footer, cookie banner) run in the **App scope**; components placed in a view run in that **view's scope**. App variables are not visible in views and vice versa. Share data through a `backend/` singleton, `$_SESSION` or `define()` ([backend and scope](/docs?topic=backend)).

## Rendering one component on its own (partials)

Components only exist inlined at compile time. To return one component's HTML from an `@AJAX` handler (for HTMX or `fetch` + `innerHTML`), compile it with `convert_pvue_file()` in a backend helper and pass the variables it would normally inherit. See the `PHPueFragment` helper in [cscript, AlpineJS, x-ssr and HTMX](/docs?topic=client-js).
