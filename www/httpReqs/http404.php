<div class="kb-wrap kb-404">
    <h1>404 - Page not found</h1>
    <p>Nothing lives at <code><?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '/') ?></code>.</p>
    <p>PHPue routes are flat (<code>views/name.pvue</code> is <code>/name</code>), so try <a href="/docs">all topics</a>, the <a href="/faq">FAQ</a> or <a href="/llms.txt">llms.txt</a>.</p>
</div>
