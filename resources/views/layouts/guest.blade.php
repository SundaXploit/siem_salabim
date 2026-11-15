<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark" data-theme="dark">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        @include('partials.session-security')

        <title>SIEM Salabim | @yield('title', 'Security Operations Center')</title>
    @include('partials.brand-head')
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
    <body class="auth-shell access-shell" data-ui-view="@yield('auth-view', 'auth/guest')">
        <button class="theme-toggle-login" type="button" data-theme-toggle title="Ubah tema" aria-label="Ubah tema gelap atau terang">
            <span class="theme-icon-dark" aria-hidden="true"><i data-lucide="moon"></i></span>
            <span class="theme-icon-light" aria-hidden="true"><i data-lucide="sun"></i></span>
        </button>
        <main class="access-workspace">
            <a href="{{ url('/') }}" class="access-brand" aria-label="SIEM Salabim - halaman utama"><x-application-logo alt="" /><span class="brand-name">SIEM <strong>Salabim</strong></span></a>
            <section class="access-panel" aria-labelledby="access-heading">
                <header class="access-heading">
                    <p class="access-eyebrow">@yield('auth-context', 'Akses akun SOC')</p>
                    <h1 id="access-heading">@yield('auth-title', 'Akses SOC')</h1>
                    @hasSection('auth-description')
                        <p class="access-description">@yield('auth-description')</p>
                    @endif
                </header>
                <div class="access-content">
                {{ $slot }}
                </div>
            </section>
            <p class="access-workspace-note">Security Operations Center · Akses akun SIEM Salabim</p>
        </main>
    </body>
</html>
