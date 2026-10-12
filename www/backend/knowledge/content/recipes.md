# Copy-ready recipes

> Complete PHPue 0.0.3 examples. Each was compiled with `convert_pvue_file()`, syntax-checked with `php -l`, and exercised with `php -S` and curl. File paths are relative to `www/`. Remember to add each view to `App.pvue` with `#require 'views/<name>.pvue';`.

## 1. Contact form with validation (no JavaScript)

Post/Redirect/Get: the form posts to the same page, the script validates, and on success redirects so a refresh does not resend.

```html
<!-- views/contact.pvue -->
<script>
    $errors = [];
    $name = trim((string) ($_POST['name'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $message = trim((string) ($_POST['message'] ?? ''));
    $bSent = isset($_GET['sent']);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if ($name === '') $errors['name'] = 'Please enter your name.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Please enter a valid email.';
        if (mb_strlen($message) < 10) $errors['message'] = 'Please write at least 10 characters.';

        if (!$errors) {
            // Mailer::getInstance()->send($email, $name, $message);   (a backend/ class)
            header('Location: /contact?sent=1', true, 303);
            exit;
        }
    }
</script>

<header>
    <title>Contact</title>
    <meta name="description" content="Send us a message.">
</header>

<template>
    <h1>Contact</h1>
    <p p-if="$bSent" role="status">Thanks, your message was sent.</p>

    <form method="post" action="/contact" novalidate>
        <label for="name">Name</label>
        <input id="name" name="name" value="{{ $name }}" aria-invalid="{{ isset($errors['name']) ? 'true' : 'false' }}">
        <p p-if="isset($errors['name'])" class="error">{{ $errors['name'] ?? '' }}</p>

        <label for="email">Email</label>
        <input id="email" name="email" type="email" value="{{ $email }}">
        <p p-if="isset($errors['email'])" class="error">{{ $errors['email'] ?? '' }}</p>

        <label for="message">Message</label>
        <textarea id="message" name="message">{{ $message }}</textarea>
        <p p-if="isset($errors['message'])" class="error">{{ $errors['message'] ?? '' }}</p>

        <button type="submit">Send</button>
    </form>
</template>
```

## 2. Like button with @AJAX (no reload)

```html
<!-- views/post.pvue -->
<script>
    @AJAX('POST')
    function likePost($input) {
        header('Content-Type: application/json');
        $id = (int) ($input['id'] ?? 0);
        if ($id < 1) {
            http_response_code(422);
            echo json_encode(['ok' => false]);
            return;
        }
        $_SESSION['likes'][$id] = ($_SESSION['likes'][$id] ?? 0) + 1;
        echo json_encode(['ok' => true, 'likes' => $_SESSION['likes'][$id]]);
    }

    $postId = 1;
    $likes = $_SESSION['likes'][$postId] ?? 0;
</script>

<template>
    <article>
        <h1>Hello PHPue</h1>
        <button type="button" onclick="likePost({{ $postId }}, this)">
            Like (<span class="like-count">{{ $likes }}</span>)
        </button>
    </article>
</template>

<cscript>
    async function likePost(id, button) {
        button.disabled = true;
        try {
            const res = await fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'likePost', id })
            });
            const data = await res.json();
            if (data.ok) button.querySelector('.like-count').textContent = data.likes;
        } finally {
            button.disabled = false;
        }
    }
</cscript>
```

## 3. Search and filter a list with GET parameters

Shareable URLs, works without JavaScript, and crawlers see the results.

```html
<!-- views/tools.pvue -->
<script>
    $allTools = [
        ['name' => 'PHPue', 'type' => 'framework'],
        ['name' => 'AlpineJS', 'type' => 'library'],
        ['name' => 'HTMX', 'type' => 'library'],
        ['name' => 'Tailwind CSS', 'type' => 'css'],
    ];
    $types = array_values(array_unique(array_column($allTools, 'type')));
    $q = trim((string) ($_GET['q'] ?? ''));
    $type = in_array($_GET['type'] ?? '', $types, true) ? $_GET['type'] : '';

    $tools = array_filter($allTools, fn($t) =>
        ($q === '' || stripos($t['name'], $q) !== false) && ($type === '' || $t['type'] === $type));
</script>

<template>
    <form method="get" action="/tools" role="search">
        <input name="q" value="{{ $q }}" placeholder="Search tools">
        <select name="type">
            <option value="">All types</option>
            <option p-for="$t in $types" value="{{ $t }}" <?= $t === $type ? 'selected' : '' ?>>{{ ucfirst($t) }}</option>
        </select>
        <button type="submit">Filter</button>
    </form>

    <p>{{ count($tools) }} result(s)</p>
    <ul>
        <li p-for="$tool in $tools">{{ $tool['name'] }} <small>({{ $tool['type'] }})</small></li>
    </ul>
    <p p-if="count($tools) === 0">Nothing matches. <a href="/tools">Clear filters</a></p>
</template>
```

Boolean attributes (`selected`, `checked`, `disabled`) count as set whenever they are present, even as `selected=""`, so add or omit the whole attribute with `<?= ... ?>` instead of filling its value with `{{ }}`.

## 4. Card grid: a component in a loop with defaults

```html
<!-- views/team.pvue -->
<script>
    @require PersonCard 'components/PersonCard.pvue';
    $people = [
        ['name' => 'Ada', 'role' => 'Engineer'],
        ['name' => 'Grace', 'role' => 'Admiral'],
    ];
</script>

<template>
    <section class="grid">
        <PersonCard p-for="$person in $people" />
    </section>
</template>
```

```html
<!-- components/PersonCard.pvue -->
<script>
    $person = $person ?? ['name' => 'Unknown', 'role' => ''];
    $personInitial = mb_substr($person['name'], 0, 1);
</script>

<template>
    <article class="card">
        <span class="avatar" aria-hidden="true">{{ $personInitial }}</span>
        <h2>{{ $person['name'] }}</h2>
        <p p-if="$person['role'] !== ''">{{ $person['role'] }}</p>
    </article>
</template>
```

## 5. Database page with a backend singleton (SQLite via PDO)

Needs PHP's `pdo_sqlite` extension (included in the official `php` Docker images). Swap the DSN for MySQL or PostgreSQL in production.

```php
<?php
// backend/Notes.php
class Notes {
    private static ?self $instance = null;
    private PDO $pdo;

    public static function getInstance(): self {
        return self::$instance ??= new self();
    }

    private function __construct() {
        $dir = getcwd() . '/data';
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        $this->pdo = new PDO('sqlite:' . $dir . '/notes.sqlite', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS notes (id INTEGER PRIMARY KEY, body TEXT NOT NULL, created TEXT NOT NULL)');
    }

    public function latest(int $limit = 20): array {
        $stmt = $this->pdo->prepare('SELECT * FROM notes ORDER BY id DESC LIMIT :limit');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function add(string $body): void {
        $stmt = $this->pdo->prepare('INSERT INTO notes (body, created) VALUES (:body, :created)');
        $stmt->execute([':body' => $body, ':created' => date('c')]);
    }
}
```

```html
<!-- views/notes.pvue -->
<script>
    @AJAX('POST')
    function addNote($input) {
        header('Content-Type: application/json');
        $body = trim((string) ($input['body'] ?? ''));
        if ($body === '' || mb_strlen($body) > 280) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Write 1-280 characters.']);
            return;
        }
        Notes::getInstance()->add($body);
        echo json_encode(['ok' => true]);
    }

    $notes = Notes::getInstance()->latest();
</script>

<template>
    <form onsubmit="addNote(event)">
        <input name="body" maxlength="280" required>
        <button type="submit">Add note</button>
    </form>
    <ul>
        <li p-for="$note in $notes"><time>{{ substr($note['created'], 0, 10) }}</time> {{ $note['body'] }}</li>
    </ul>
</template>

<cscript>
    async function addNote(event) {
        event.preventDefault();
        const body = new FormData(event.target).get('body');
        const res = await fetch('', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'addNote', body })
        });
        if (res.ok) location.reload();          // the server renders the new list
        else alert((await res.json()).error);
    }
</cscript>
```

Keep the `data/` folder outside the web root, or block it in `.htaccess`/nginx.

## 6. Dynamic page titles from App.pvue (sessionless)

Views have static headers, so compute per-request meta in a backend class and print it from the App header.

```php
<?php
// backend/PageMeta.php
class PageMeta {
    public static function tags(): string {
        $route = get_current_route();
        $title = match ($route) {
            'index' => 'Home | My Site',
            'blog'  => isset($_GET['post'])
                ? ucwords(str_replace('-', ' ', preg_replace('/[^a-z0-9-]/', '', $_GET['post']))) . ' | Blog'
                : 'Blog | My Site',
            default => ucfirst($route) . ' | My Site',
        };
        $e = fn($v) => htmlspecialchars($v, ENT_QUOTES);
        return '<title>' . $e($title) . '</title>'
             . '<link rel="canonical" href="https://example.com' . $e(strtok($_SERVER['REQUEST_URI'], '#')) . '">';
    }
}
```

```html
<!-- App.pvue -->
<script>
    #require 'views/index.pvue';
    #require 'views/blog.pvue';
    $headTags = PageMeta::tags();
</script>

<header>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    {{ $headTags }}
</header>

<template>
    <View/>
</template>
```

Leave `<title>` out of the view headers when the App prints it.

## 7. Login gate with sessions

```html
<!-- views/admin.pvue -->
<script>
    @AJAX('POST')
    function adminLogin($input) {
        header('Content-Type: application/json');
        $hash = getenv('ADMIN_PASSWORD_HASH') ?: '';   // password_hash() output, kept out of git
        if ($hash !== '' && password_verify((string) ($input['password'] ?? ''), $hash)) {
            session_regenerate_id(true);
            $_SESSION['isAdmin'] = true;
            echo json_encode(['ok' => true]);
            return;
        }
        http_response_code(401);
        echo json_encode(['ok' => false]);
    }

    @AJAX('POST')
    function adminLogout($input) {
        $_SESSION = [];
        echo json_encode(['ok' => true]);
    }

    $bAdmin = !empty($_SESSION['isAdmin']);
</script>

<header>
    <meta name="robots" content="noindex">
</header>

<template>
    <form p-if="!$bAdmin" onsubmit="adminAction(event, 'adminLogin')">
        <input name="password" type="password" autocomplete="current-password" required>
        <button type="submit">Sign in</button>
    </form>

    <section p-if="$bAdmin">
        <h1>Admin</h1>
        <button type="button" onclick="adminAction(event, 'adminLogout')">Sign out</button>
    </section>
</template>

<cscript>
    async function adminAction(event, action) {
        event.preventDefault();
        const fields = event.target.tagName === 'FORM' ? Object.fromEntries(new FormData(event.target)) : {};
        const res = await fetch('', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action, ...fields })
        });
        if (res.ok) location.reload(); else alert('Wrong password');
    }
</cscript>
```
