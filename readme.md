# PHPue AI Knowledge ([ai.phpue.co.uk](https://ai.phpue.co.uk))

The canonical, LLM-ready knowledge base for **PHPue 0.0.3**, built so people and AI agents can write PHPue apps correctly the first time. The site itself is a PHPue 0.0.3 app.

| For | URL |
|---|---|
| People | [ai.phpue.co.uk](https://ai.phpue.co.uk), [/docs](https://ai.phpue.co.uk/docs), [/faq](https://ai.phpue.co.uk/faq), [/chat](https://ai.phpue.co.uk/chat) (Gemini, grounded on the knowledge base) |
| LLMs and agents | [/llms.txt](https://ai.phpue.co.uk/llms.txt) (index), [/llms-full.txt](https://ai.phpue.co.uk/llms-full.txt) (everything), `/knowledge/<topic>.md` (one topic) |
| Claude Code / Codex | [Agent Skill](https://ai.phpue.co.uk/skills): [/skills/phpue.zip](https://ai.phpue.co.uk/skills/phpue.zip), the author's own PHPue skill |
| Coding agents | [AGENTS.md / CLAUDE.md for PHPue projects](https://ai.phpue.co.uk/docs?topic=ai-rules#agents-md-claude-md-for-phpue-projects), [prompts/](prompts/) |
| Crawlers / GEO | `/robots.txt`, `/sitemap.xml`, JSON-LD (TechArticle, FAQPage, SoftwareSourceCode) on every page, markdown alternates and `Accept: text/markdown` negotiation |

**Editing the knowledge:** everything comes from `www/backend/knowledge/content/*.md` (one file per topic, registered in `www/backend/knowledge/PHPueKnowledge.php`). The HTML pages, agent endpoints, sitemap, JSON-LD and the chat's grounding are all generated from those files, so there is nothing to rebuild. Bump `PHPueKnowledge::UPDATED` when content changes.

**Updating the Agent Skill:** the downloadable skill is a copy of the author's skill. After changing it, run `php tools/sync-skill.php` (default source `~/.claude/skills/phpue`). It copies the skill into `www/backend/knowledge/skill/`, rewrites the few lines that point at the author's machine, and refuses to finish if any local path is left.

**Run locally:** `cd www && php -S localhost:3000` (PHP 8.0+), or `docker compose up` (port 3000). The chat uses the site's Gemini key from `www/backend/env.php` (git-ignored, `<?php putenv('GEMINI_API_KEY=...');`). Visitors can also use their own Gemini or OpenAI key: that mode runs entirely in the browser (`www/assets/js/phpue-byok.js`), the key is never sent to this server, and `/chat` sends a Content-Security-Policy that only allows connections to this site, Google and OpenAI.

---

The original PHPue framework readme follows.

---

**Official Website:** [PHPue](https://phpue.co.uk) | **Official Documentation:** [PHPue Documentation](https://phpue.co.uk/docs)
---
[PHPue Insights](https://phpue.co.uk/ue-insights) - [PHPue Releases](https://phpue.co.uk/ue-releases) - [PHPue Deployment](https://phpue.co.uk/deployment)
---

**Keep Building, Even When Things Break**

This framework works fully out of the box. While there may still be occasional bugs related to new features—such as custom p-directives or {{ }} variable echoing—you can always fall back to traditional PHP as a temporary workaround.

This flexibility is a game-changer. It allows development to continue smoothly without rushing features, giving developers room to explore a more modular approach to PHP coding.

**PHPue Framework 🚀**

A powerful PHP framework that brings Vue-inspired syntax to server-side rendering with hot reload, component system, and seamless PHP-JavaScript integration. 

*It’s like Node, but without the ```node_modules```!*

**Getting started is easy:**

Just clone the repo and grab two files — ```conversion.php``` and ```index.php```. That’s it. You’re ready to build.

Use it with XAMPP, PHP CLI, Docker Compose, or even upload directly via FTP. Watch your web application come to life with minimal setup and maximum flexibility.

**🌟 What is PHPue?**

PHPue combines the simplicity of PHP with Vue-like templating syntax to create fast, scalable web applications with server-side rendering. It offers the developer experience of modern frameworks with the performance and simplicity of traditional PHP.

**🎯 Features**

🏗️ Component System
```html
<!-- components/Navbar.pvue -->
<template>
    <nav class="navbar">
        <ul>
            <li p-for="$item in $navItems">
                <a href="{{ $item['url'] }}">{{ $item['title'] }}</a>
            </li>
        </ul>
    </nav>
</template>
```

**📦 Import System**

Component Importing

```html
<script>
    @require Navbar 'components/Navbar.pvue';
    @require Footer 'components/Footer.pvue';
</script>
```

**View Importing (with name)**

```html
<script>
    #require Home 'views/index.pvue';
    #require About 'views/about.pvue';
    #require Contact 'views/contact.pvue';
</script>
```

**View Importing (auto-named)**

```html
<script>
    #require 'views/index.pvue';
    #require 'views/about.pvue';
</script>
```

**Traditional PHP Includes**

```html
<script>
    require_once 'config/database.php';
    require 'helpers/functions.php';
</script>
```

**🎨 Directives**


**p-if** - Conditional Rendering

```html
<template>
    <div p-if="$user['isLoggedIn']">
        Welcome back, {{ $user['name'] }}!
    </div>
    
    <div p-if="count($products) > 0">
        <p>Showing {{ count($products) }} products</p>
    </div>
    
    <div p-if="!$user['isLoggedIn']">
        <a href="/login">Please log in</a>
    </div>
</template>
```

**p-for** - List Rendering

```html
<template>
    <ul>
        <li p-for="$user in $users" class="user-item">
            <strong>{{ $user['name'] }}</strong> - {{ $user['email'] }}
        </li>
    </ul>
    
    <div p-for="$product in $featuredProducts" class="product-card">
        <h3>{{ $product['title'] }}</h3>
        <p>{{ $product['description'] }}</p>
        <span class="price">${{ $product['price'] }}</span>
    </div>
</template>
```

IF any p-directive doesn't work, or you required PHP functions in template, for example p-if or p-for breaks in certain ways, due to the early stages of the framework, you can use PHP-style coding instead of p-if, p-for and {{ }}.

Our framework doesn't butcher PHP, JS, or HTML, we just enhance it. (PHP is allowed in all taglines, HTML and JS are not! This helps us understand what parts are SSR, and when we will require JS Client Side Rendering.

You can use this instead:-

```php
<script>
    $fruits = [Banana, Apple, Pears];
</script>

<template>
    <div>
        <?php
            foreach($fruits as $fruit) {
                echo "<p>".$fruit."</p>";
            }
        ?>
    </div>

    <!-- or if you needed to echo PHP variable because {{ $fruits }} doesn't handle array imploding, for {{}} ==> <?= htmlspecialchars($string) ?>
    - The framework detects {{ and $ for variable, or it won't generate as above! For example {{ $showColourRed ?? 'color: red;' : 'color: blue;' }} works! -->
    <div>
        <?= implode(', ', $fruits); ?>
    </div>
</template>
```

**📄 File Structure**
App.pvue (Root Component)
```html
<!-- Author: Your Name -->
<script>
    @require Navbar 'components/Navbar.pvue';
    #require 'views/index.pvue';
    #require 'views/about.pvue';
    #require 'views/contact.pvue';

    // Dynamic Meta Tags are easier to control with our custom extension:
    // Or get inspired and create your own control!
    // backend/ is auto-loaded before the framework headers are sent!
    https://github.com/PHPue/PHPue-Extensions/tree/PHPue-Extensions/phpue-metacontrol

    // Only set dynamic pages that don't contain a static HTML title!
    $pageTitle = "";
    if($currentRoute === 'index' || $currentRoute === '') {
        $pageTitle = "SSR Generated Index Page!";
    }
    
</script>

<header>
    <!-- Dynamic Headers - No PHP Allowed for Safety! -->
    {{ $pageTitle }}
    <!-- Global headers, styles, scripts -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <link rel="stylesheet" href="assets/css/main.css">
</header>

<template>
    <Navbar></Navbar>
    <View></View> <!-- Dynamic page content injection -->
</template>
```

**View File Structure**

```html
<script>
    $featuredProducts = [
        ['name' => 'Product 1', 'price' => 29.99],
        ['name' => 'Product 2', 'price' => 39.99]
    ];
    $user = ['name' => 'John Doe', 'isAdmin' => true];
</script>

<header>
    <meta name="description" content="Welcome to our amazing website">
    <meta name="keywords" content="php, vue, framework">

    <style>
        .product-card {
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 16px;
            transition: all 0.2s ease;
            cursor: pointer;
        }

        /* Hover effect */
        .product-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 16px rgba(0,0,0,0.15);
            background-color: #f9f9f9;
        }

        /* Selected effect (click) */
        .product-card.selected {
            border: 2px solid #00f;
            background-color: #e0f0ff;
        }
    </style>
</header>

<template>
    <div class="container">
        <h1>Hello, <span>{{ $user['name'] }}</span></h1>
        
        <div p-if="$user['isAdmin']" class="admin-panel">
            <button class="btn btn-warning">Admin Controls</button>
        </div>
        
        <div class="products">
            <div p-for="$product in $featuredProducts" class="product-card">
                <h3>{{ $product['name'] }}</h3>
                <p class="price">{{ $product['price'] }}</p>
            </div>
        </div>
    </div>
</template>

<cscript>
    // Client-side JavaScript
    console.log("Page loaded successfully!");
    
    // Access PHP variables in JavaScript
    let products = {{ $featuredProducts }};
    let user = {{ $user }};
    
    console.log("Products:", products);
    console.log("User:", user);
    
    // Add interactivity
    document.querySelectorAll('.product-card').forEach(card => {
        card.addEventListener('click', () => {
            card.classList.toggle('selected');
        });
    });
</cscript>
```

# Visit: http://localhost:3000/
**🔥 Hot Reload Development**
🚀 Production Runtime
```bash
# Start development/production-ready server with hot reload
php -S localhost:3000
# then add ?live to the page your checking!
# Docker injects Hot Reload automatically!
```

# Visit: http://localhost:3000/
# Converts .pvue files to .php files, and creates a dist/
🚀 Production Build
```bash
# Compile all .pvue files to .php for production
php index.php build
```

# Deploy the 'dist/' directory to your production server

🛣️ Routing System
Clean URLs: yoursite.com/about automatically loads views/about.pvue

**Automatic Routing:** All files in views/ become routes

**Navigation Helper:** phpue_navigation() returns all available routes

🔄 PHP-JavaScript Integration
Seamless Variable Passing

```html
<script>
    $userData = ['name' => 'John', 'age' => 30, 'premium' => true];
    $items = ['Apple', 'Banana', 'Cherry'];
    $counter = 42;
</script>

<template>
    <!-- PHP in HTML -->
    <p>Welcome, {{ $userData['name'] }}!</p>
    <p>Item count: {{ count($items) }}</p>
</template>

<cscript>
    // PHP variables in JavaScript
    let user = {{ $userData }};        // Object: {name: "John", age: 30, premium: true}
    let items = {{ $items }};          // Array: ["Apple", "Banana", "Cherry"]  
    let counter = {{ $counter }};      // Number: 42
    
    console.log(user.name);            // "John"
    console.log(items.length);         // 3
    console.log(counter + 10);         // 52
</cscript>
```

**📁 Project Structure**


```text
your-project/
├── App.pvue                 # Root application component
├── index.php                # Development server
├── conversion.php           # PHPue compiler
├── components/              # Reusable components
│   ├── Navbar.pvue
│   ├── Footer.pvue
│   └── UserCard.pvue
├── views/                   # Page views
│   ├── index.pvue
│   ├── about.pvue
│   └── contact.pvue
├── assets/                  # Static assets
│   ├── css/
│   ├── js/`
│   └── images/
└── dist/                    # Compiled PHP files (production)
```

**🚀 Quick Start**
Create App.pvue

```html
<script setup>
    @require Header 'components/Header.pvue';
    #require 'views/index.pvue';
</script>

<header>
    <title>My PHPue App</title>
</header>

<template>
    <Header></Header>
    <View></View>
</template>
```

Create a view:

```html
<!-- views/index.pvue -->
<script>
    $message = "Hello PHPue!";
    $items = ['Learn', 'Build', 'Deploy'];
</script>

<header>
    <title>Home Page</title>
</header>

<template>
    <div class="container">
        <h1>{{ $message }}</h1>
        <ul>
            <li p-for="$item in $items">{{ $item }}</li>
        </ul>
    </div>
</template>
```

Start development:

```bash
php -S localhost:3000
```

💡 Why PHPue?
✅ Server-Side Rendering - Better SEO and performance

✅ Hot Reload - Instant development feedback

✅ Vue-Inspired Syntax - Familiar and intuitive

✅ AJAX Decoration

✅ Unified File Structure - Accessible PHP, HTML, and JS!

✅ No Framework Lock In ! - Use PHP scripts where you need!

✅ Auto Loader Classes (backend/) - Instead of Middleware!

✅ PHP Power - Full access to PHP ecosystem

✅ Component-Based - Reusable and maintainable

✅ Auto Routing - File-based routing system

✅ PHP+JS Integration - Seamless variable passing

✅ Production Ready - Build system for deployment

🎉 Get Building!
PHPue gives you the best of both worlds: the simplicity and power of PHP with the modern developer experience of component-based frameworks. Start building your next amazing web application today! 🚀

PHPue - Server-side rendering with Vue-like syntax. Fast, simple, powerful.
