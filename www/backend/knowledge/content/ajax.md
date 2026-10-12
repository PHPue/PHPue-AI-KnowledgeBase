# @AJAX: server functions for the browser

> Put `@AJAX('POST')` or `@AJAX('GET')` directly before a PHP function in a `<script>` block and the browser can call it: POST a JSON body with `"action": "functionName"`, or GET `?action=functionName`. The function receives the request data as `$input` and must **`echo`** its response (usually `json_encode`); return values are discarded. Handlers run before any page code and end the request.

## Server side

```php
<script>
    @AJAX('POST')
    function saveNote($input) {
        $text = trim((string) ($input['text'] ?? ''));
        header('Content-Type: application/json');

        if ($text === '' || mb_strlen($text) > 500) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Write 1-500 characters']);
            return;
        }

        $_SESSION['notes'][] = $text;
        echo json_encode(['ok' => true, 'count' => count($_SESSION['notes'])]);
    }

    @AJAX('GET')
    function listNotes($input) {
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        echo json_encode($_SESSION['notes'] ?? []);
    }
</script>
```

- Declaration (0.0.3): `@AJAX('POST')` or `@AJAX("GET")`, then `function name(...)`. Typed parameters, defaults and return types are fine: `@AJAX('POST') function save(array $input = []): void {`.
- `$input` is the decoded JSON body for POST, or `$_GET` for GET (which also contains `page`). A function with no parameters is called with none.
- The declared method is **enforced**: the other method gets `405` with an `Allow:` header and `{"error": "@AJAX 'name' only accepts POST"}`.
- Only `@AJAX` functions can be called. If `action` names anything else, the request falls through and returns the normal HTML page (status 200), so check `response.ok` and the content type on the client.
- **`echo`, don't `return`.** Set `Content-Type: application/json` and use `http_response_code()` for errors.
- Handlers run **before** the App and view scripts: they cannot see variables from those scripts. Load what you need inside the handler (a `backend/` singleton, `$_SESSION`).
- POST handlers always have a session (PHPue starts one, even with lazy sessions). GET handlers only have one if the visitor already has a session cookie; call `phpue_session(true)` before writing.
- Function names are global across the whole app: keep them unique.
- Handlers from App, every view and every component are available on every page, in source mode and in the built `.dist/`. Posting to the page that owns the handler (`fetch('')`) is the clearest convention.

## Client side

```js
// POST: JSON body with an "action"
async function saveNote(text) {
    const res = await fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'saveNote', text })
    });
    const data = await res.json();
    if (!res.ok || !data.ok) throw new Error(data.error || 'Save failed');
    return data;
}

// GET: query string
const notes = await fetch('?action=listNotes').then(r => r.json());
```

`fetch('')` posts to the current URL. Use `window.location.pathname` if the page has query parameters you do not want to resend.

## Submitting a form through @AJAX

```html
<template>
    <form onsubmit="submitForm(event, 'subscribe')">
        <input name="email" type="email" required>
        <button type="submit">Subscribe</button>
    </form>
    <p id="subscribeResult" role="status"></p>
</template>

<cscript>
    async function submitForm(event, action) {
        event.preventDefault();
        const fields = Object.fromEntries(new FormData(event.target));
        const res = await fetch('', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action, ...fields })
        });
        const data = await res.json();
        document.getElementById('subscribeResult').textContent = data.message;
    }
</cscript>
```

```php
@AJAX('POST')
function subscribe($input) {
    header('Content-Type: application/json');
    $email = filter_var($input['email'] ?? '', FILTER_VALIDATE_EMAIL);
    if (!$email) {
        http_response_code(422);
        echo json_encode(['message' => 'Please enter a valid email.']);
        return;
    }
    // Newsletter::getInstance()->add($email);
    echo json_encode(['message' => 'Thanks, you are subscribed.']);
}
```

## The common pattern: mutate, then reload

The simplest PHPue interaction loop needs almost no JavaScript: an `@AJAX` handler changes `$_SESSION` or the database, the client calls `location.reload()`, and the server renders the new state. For no-reload updates, echo JSON and patch the DOM in `<cscript>`, or use AlpineJS ([client JS](/docs?topic=client-js)).

## File uploads and other non-JSON requests

The dispatcher only reads JSON bodies for POST, so `FormData` / multipart uploads do not reach `@AJAX` (you get the HTML page back). Use a backend endpoint instead:

```php
<?php
// backend/endpoints/upload.php (runs on every request; only acts on its own URL)
if (isset($_GET['api'], $_GET['endpoint']) && $_GET['endpoint'] === 'avatarUpload') {
    header('Content-Type: application/json');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['avatar'])) {
        http_response_code(400);
        echo json_encode(['ok' => false]);
        exit;
    }
    // validate type and size, move_uploaded_file(...), store the result
    echo json_encode(['ok' => true]);
    exit;
}
```

Client: `fetch('?api&endpoint=avatarUpload', {method: 'POST', body: new FormData(form)})`.

## Security checklist

- Validate and authorise **inside every handler**; `$input` is untrusted user data.
- Add CSRF protection for state-changing handlers on logged-in sites (a token stored in `$_SESSION`, sent in the JSON body, compared with `hash_equals`).
- Escape anything you send back that the client will insert as HTML, or insert it with `textContent`.
- GET handlers can be cached by proxies for anonymous visitors: send `Cache-Control: no-store` for per-user data.
- On 0.0.2 the dispatcher calls **any** existing PHP function named in `action` (including built-ins) and ignores the declared method. Upgrade to 0.0.3.
