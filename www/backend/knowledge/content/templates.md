# Templates: {{ }}, p-if, p-for and forms

> The `<template>` block is HTML plus four tools: `{{ $expr }}` for escaped output, `p-if="cond"` to render an element conditionally, `p-for="$item in $list"` to repeat an element, and raw `<?php ?>` / `<?= ?>` for anything else. All of it runs once, on the server, per request. There is no `p-else`, `p-show` or `p-model`.

## `{{ }}`: escaped output

`{{ $expr }}` compiles to `<?= htmlspecialchars($expr ?? "") ?>`. An expression that starts with a function or static call compiles to `<?= htmlspecialchars((string) (expr)) ?>`.

| Write | Result |
|---|---|
| `{{ $title }}` | escaped value |
| `{{ $row['label'] ?? '' }}`, `{{ $post->title }}` | array key / object property |
| `{{ $isActive ? 'active' : '' }}` | ternary; works inside attributes: `class="nav {{ $isActive ? 'active' : '' }}"` |
| `{{ $price * $qty }}` | arithmetic |
| `{{ count($items) }}`, `{{ number_format($price, 2) }}`, `{{ \App\Fmt::money($p) }}` | function and static calls (0.0.3) |
| `{{ $user.name }}` | **wrong**: `.` is PHP concatenation. Use `$user['name']` or `$user->name` |
| `{{ $tags }}` where `$tags` is an array | **TypeError**. Use `{{ implode(', ', $tags) }}` |
| `{{ !$x }}`, `{{ (int)$x }}`, `{{ item.name }}` | left as literal text (does not start with `$` or a call) |

- Raw, unescaped HTML (trusted content only): `<?= $html ?>`.
- A whole attribute cannot come from `{{ }}` because quotes are escaped: `{{ $on ? 'aria-current="page"' : '' }}` prints `aria-current=&quot;page&quot;`. Output the value instead: `aria-current="{{ $on ? 'page' : 'false' }}"`, or use `<?= ... ?>`.
- Alpine-looking `{{ total() }}` is compiled as a **PHP** call. Use `x-text="total()"` for Alpine output.

## `p-if`: conditional element

```html
<div p-if="$bShowDetails" class="card">
    <h2>Details</h2>
    <p>{{ $name }}</p>
</div>
<div p-if="!$bShowDetails" class="card">Nothing yet.</div>

<p p-if="count($errors) > 0" class="error">Please fix {{ count($errors) }} fields.</p>
<span p-if="$isNew" class="badge {{ $tone }}">New</span>
<input p-if="$editable" name="title" value="{{ $title }}">
```

Compiles to `<?php if(cond): ?>` + the element without the attribute + `<?php endif; ?>`.

- Works on one line or many, with nested same-tag children, with `{{ }}`, `>` or `=>` in other attributes, and on void elements.
- The element **must be closed** (or be void/self-closing). Otherwise only the opening tag is wrapped and the content always renders (the compiler logs `has no closing </tag>`).
- The condition is any PHP expression. Use single quotes inside a double-quoted attribute (`p-if="$user['role'] === 'admin'"`).
- `p-if` inside `<?php ?>`, HTML comments, `{{ }}` and `<script>`/`<style>` bodies is not converted.
- **There is no `p-else`.** Use a negated `p-if`, or raw PHP for if/elseif/else:

```html
<?php if ($user === null): ?>
    <a href="/login">Sign in</a>
<?php elseif ($user['admin']): ?>
    <AdminMenu/>
<?php else: ?>
    <p>Welcome back, {{ $user['name'] }}</p>
<?php endif; ?>
```

## `p-for`: repeated element

```html
<li p-for="$fruit in $fruits">{{ $fruit }}</li>

<a p-for="$route in $routes" href="{{ $route['url'] }}">{{ $route['title'] }}</a>

<tr p-for="$id => $product in $products">
    <td>{{ $id }}</td>
    <td>{{ $product['name'] }}</td>
</tr>

<option p-for="$n in range(1, 10)" value="{{ $n }}">{{ $n }}</option>

<li p-for="$n in $numbers" p-if="$n % 2 === 0">{{ $n }} is even</li>
```

Compiles to `<?php foreach (phpue_iterable((expr) ?? null) as $item): ?>…<?php endforeach; ?>`.

- Syntax: `$item in <expr>` or `$key => $value in <expr>`. `<expr>` can be a variable, an array index, a function call, a `PDOStatement`, an `ArrayIterator` or a generator.
- `null`, unset variables and scalars render nothing (no warning).
- Nested same-tag children are fine: `<div p-for="$r in $rows"><div>{{ $r['v'] }}</div></div>`.
- `p-for` + `p-if` on one element: loop first, then filter each item.
- A component tag can loop: `<ProductCard p-for="$product in $products" />` renders the component once per item, and the component reads `$product`.
- Invalid syntax (`p-for="item of $items"`) is not converted; the attribute becomes `data-phpue-invalid-p-for`.
- The loop variable stays defined after the loop (plain PHP `foreach`), so use specific names.

## Forms: no `p-model`, by design

PHPue is privacy-first: no two-way binding, no keystroke tracking. A `p-model` regex still exists in `conversion.php` only as an abandoned placeholder; **never use it**. Write the attributes yourself and submit on purpose:

```html
<form method="post">
    <input name="email" type="email" value="{{ $email }}" required>
    <textarea name="message">{{ $message }}</textarea>
    <select name="topic">
        <option p-for="$t in $topics" value="{{ $t }}">{{ $t }}</option>
    </select>
    <button type="submit" name="send" value="1">Send</button>
</form>
```

Handle it in the same view's `<script>` (`if (($_POST['send'] ?? '') === '1') { ... }`), or post JSON to an `@AJAX` function without a reload ([@AJAX](/docs?topic=ajax)). Live client-side state, if you need it, is the browser's job (plain JS or Alpine `x-model`), not a PHPue directive.

## Raw PHP in templates

`<?php ... ?>` and `<?= ... ?>` pass straight through. Use them for if/elseif/else, loops over complex data, setting variables that a following component will read, and trusted raw HTML.

```html
<?php foreach ($techStack as $tech => $data): ?>
    <div class="card {{ $data['color'] }}">
        <h4>{{ $tech }}</h4>
    </div>
<?php endforeach; ?>
```

## Inline `<style>` and `<script>`

- `<style>` inside a template is fine (component-local CSS, printed wherever the component renders).
- `<script src>`, `<script type="module">` and bare `<script>` tags inside a template are fine in 0.0.3. Prefer `<cscript>` for page JS. Do not put the text `<template>` inside a JS string in an inline script (it counts toward template nesting).

## Showing PHPue code as text (docs, tutorials)

The compiler would run code samples, so escape them:

- `{{ ... }}` as `&#123;&#123; $title &#125;&#125;`.
- Tags as `&lt;template&gt;`, `&lt;script&gt;`, `&lt;li p-for="$item in $items"&gt;` (escaped tags are never converted in 0.0.3).
- In PHP strings, split closing script tags: `'</scr' . 'ipt>'`.
- Or keep samples in a data file (markdown, JSON) and print them escaped with `<?= htmlspecialchars($code) ?>`, which is how this site shows its examples.
