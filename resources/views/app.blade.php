<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0f172a">
    <meta name="description" content="NGELAYAR - Smart Hazard & Fishing Zone Map untuk Nelayan Tradisional. Peta zona ikan (ZPPI) & peringatan bahaya laut, offline-first.">

    <title inertia>{{ config('app.name', 'NGELAYAR') }}</title>

    <!-- Fonts: Figtree + Inter via Bunny (GDPR-friendly) -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700|plus-jakarta-sans:600,700&display=swap" rel="stylesheet" />

    <!-- Dark mode flicker guard: default dark unless user toggled light -->
    <script>
        (function() {
            const stored = localStorage.getItem('ngelayar-theme');
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            // Default = dark (sesuai spec estetika dark)
            if (stored === 'light') document.documentElement.classList.remove('dark');
            else document.documentElement.classList.add('dark');
            if (!stored && !prefersDark) {
                // tetap dark sebagai default NGELAYAR; user bisa toggle manual ke light
                document.documentElement.classList.add('dark');
            }
        })();
    </script>

    <!-- PWA: manifest akan di-inject oleh vite-plugin-pwa -->
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.jsx'])
    @inertiaHead
</head>
<body class="font-sans antialiased bg-[#020617] text-slate-100 min-h-screen selection:bg-sky-500/30">
    @inertia
    <noscript>
        <div class="flex min-h-screen items-center justify-center p-6 text-center">
            <div class="ngelayar-card p-8 max-w-md">
                <h1 class="text-xl font-bold text-white">JavaScript diperlukan</h1>
                <p class="mt-2 text-sm text-slate-400">NGELAYAR membutuhkan JavaScript untuk menampilkan peta ZPPI & Hazard. Aktifkan JavaScript di browser Anda.</p>
            </div>
        </div>
    </noscript>
</body>
</html>
