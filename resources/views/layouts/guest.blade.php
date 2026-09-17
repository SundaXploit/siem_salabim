<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark" data-theme="dark">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>SIEM Salabim | @yield('title', 'Security Operations Center')</title>
        <link rel="icon" type="image/png" href="{{ asset('images/brand/siem-salabim-mark.png') }}">
        <link rel="apple-touch-icon" href="{{ asset('images/brand/siem-salabim-mark.png') }}">
        <script>
            (function () {
                try {
                var storedTheme = localStorage.getItem('siem-theme');
                var theme = storedTheme === 'light' ? 'light' : 'dark';
                document.documentElement.dataset.theme = theme;
                document.documentElement.classList.remove('dark', 'light');
                document.documentElement.classList.add(theme);
            } catch (_) {
                document.documentElement.dataset.theme = 'dark';
                document.documentElement.classList.remove('light');
                document.documentElement.classList.add('dark');
            }
            })();
        </script>

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="auth-shell">
        <button class="theme-toggle-login" type="button" data-theme-toggle title="Ubah tema" aria-label="Ubah tema gelap atau terang">
            <span class="theme-icon-dark" aria-hidden="true"><i data-lucide="moon"></i></span>
            <span class="theme-icon-light" aria-hidden="true"><i data-lucide="sun"></i></span>
        </button>
        <div class="login-container">
            <div class="login-header">
                <a href="/" class="login-logo" aria-label="SIEM Salabim"><img src="{{ asset('images/brand/siem-salabim-mark.png') }}" width="1254" height="1254" alt=""></a>
                <h1 class="login-title">SIEM Salabim</h1>
                <p class="login-subtitle">Security Operations Center</p>
            </div>

            <div class="login-card guest-panel">
                {{ $slot }}
            </div>
        </div>
    </body>
</html>
