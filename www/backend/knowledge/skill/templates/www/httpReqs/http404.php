<!-- Rendered inside App.pvue's layout in place of <View/> (Navbar etc. stay). Plain PHP/HTML. -->
<main class="flex min-h-[60vh] items-center justify-center p-4">
    <div class="max-w-md text-center">
        <h1 class="text-7xl font-bold">404</h1>
        <p class="mt-4 text-slate-600">We couldn't find <code><?= htmlspecialchars($_SERVER['REQUEST_URI'] ?? '') ?></code>.</p>
        <div class="mt-6 flex justify-center gap-3">
            <a href="/" class="rounded-lg bg-blue-600 px-5 py-2 text-white">Go home</a>
            <button onclick="history.back()" class="rounded-lg border px-5 py-2">Go back</button>
        </div>
    </div>
</main>
