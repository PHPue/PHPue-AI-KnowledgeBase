# Quick start: your first PHPue app

> A PHPue app needs PHP 8.0+, two framework files (`index.php`, `conversion.php`) and your `.pvue` files. No Node.js, Composer or bundler. Run it with PHP's built-in server, Docker, XAMPP, or any host that routes requests to `index.php`.

## 1. Get the framework

```bash
git clone https://github.com/PHPue/PHPue.git
cd PHPue/www
grep -m1 PHPUE_VERSION index.php      # should print 0.0.3
```

Or start empty: create a `www/` folder and copy only `index.php` and `conversion.php` into it. They are one release; always copy (or upgrade) both together.

## 2. Create the layout: `www/App.pvue`

```html
<script>
    @require Navbar 'components/Navbar.pvue';

    #require 'views/index.pvue';
    #require 'views/about.pvue';
</script>

<header>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="/assets/css/styles.css">
</header>

<template>
    <Navbar/>
    <main>
        <View/>
    </main>
</template>
```

`#require` registers each view. `<View/>` is replaced by the page for the current URL.

## 3. Add a component: `www/components/Navbar.pvue`

```html
<script>
    $navLinks = [
        ['url' => '/', 'label' => 'Home'],
        ['url' => '/about', 'label' => 'About'],
    ];
    $currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
</script>

<template>
    <nav aria-label="Main">
        <a p-for="$link in $navLinks" href="{{ $link['url'] }}" aria-current="{{ $currentPath === $link['url'] ? 'page' : 'false' }}">{{ $link['label'] }}</a>
    </nav>
</template>
```

## 4. Add pages: `www/views/index.pvue` and `www/views/about.pvue`

```html
<script>
    $visits = (int) ($_GET['n'] ?? 0) + 1;
    $features = ['No build step', 'Server-side rendering', 'Plain PHP underneath'];
</script>

<header>
    <title>Home</title>
    <meta name="description" content="My first PHPue app">
</header>

<template>
    <h1>Hello PHPue</h1>
    <ul>
        <li p-for="$feature in $features">{{ $feature }}</li>
    </ul>
    <p p-if="$visits > 1">You have been here {{ $visits }} times.</p>
    <a href="/?n={{ $visits }}">Count again</a>
</template>

<cscript>
    console.log('Features from PHP:', {{ $features }});
</cscript>
```

```html
<header>
    <title>About</title>
</header>

<template>
    <h1>About</h1>
    <p>This page is views/about.pvue, served at /about.</p>
</template>
```

## 5. Run it

```bash
cd www
php -S localhost:3000
```

Open `http://localhost:3000` (use `localhost`, not `127.0.0.1`). Add `?live` to the URL for hot reload while you edit. Source mode compiles every `.pvue` on each request, so edits show up on refresh.

Docker alternative (nginx + php-fpm on port 3000): use the `docker-compose.yaml` and `nginx/default.conf` from the PHPue repository and run `docker compose up`.

## 6. Build and deploy

```bash
cd www
php index.php build        # compiles everything into .dist/
```

Upload `.dist/`, `assets/`, `httpReqs/`, `index.php`, `conversion.php` and `.htaccess` to any PHP 8 host. `index.php` serves the precompiled `.dist/` automatically. Delete `.dist/` locally when you go back to editing, otherwise the old build keeps being served. Details: [Build and deploy](/docs?topic=build-deploy).

## Next steps

- Add server actions without page reloads: [@AJAX](/docs?topic=ajax).
- Share data and classes between pages: [backend/ and scope](/docs?topic=backend).
- Style with Tailwind: add `tailwindcss` as a dev dependency, set `content` to `["./**/*.pvue", "./**/*.php"]`, build to `assets/css/tailwind-styles.css` and link it in the App header. Rebuild CSS after adding classes.
- Complete examples: [Recipes](/docs?topic=recipes).
