<!DOCTYPE html>
<html lang="id" class="dark" data-theme="dark">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, interactive-widget=resizes-content">
    @include('partials.brand-head')
    <title>Masuk - SIEM Salabim</title>
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
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="auth-shell auth-login-shell">
    <div class="auth-login-backdrop" aria-hidden="true"
        style="--login-background-day: url('{{ asset('images/auth/login-manggarai.png') }}'); --login-background-night: url('{{ asset('images/auth/login-manggarai-night.png') }}');"></div>
    <button class="theme-toggle-login" type="button" data-theme-toggle title="Ubah tema" aria-label="Ubah tema gelap atau terang">
        <span class="theme-icon-dark" aria-hidden="true"><i data-lucide="moon"></i></span>
        <span class="theme-icon-light" aria-hidden="true"><i data-lucide="sun"></i></span>
    </button>

    <main class="auth-login-layout" aria-label="Masuk ke SIEM Salabim">
        <section class="auth-signin-panel" aria-labelledby="login-form-title">
            <div class="login-container">
                <a href="{{ url('/') }}" class="auth-login-brand" aria-label="SIEM Salabim - halaman utama">
                    <x-application-logo alt="" />
                    <span class="auth-login-wordmark">SIEM <strong>Salabim</strong><small>Security Operations Center</small></span>
                </a>
                <header class="auth-login-heading">
                    <h1 id="login-form-title">Selamat datang</h1>
                    <p>Masuk untuk melanjutkan pemantauan keamanan.</p>
                </header>

                @if(session('status'))
                    <div class="auth-login-status" role="status">{{ session('status') }}</div>
                @endif

                <form method="POST" action="{{ route('login') }}" class="auth-login-form">
                    @csrf

                    @if($errors->any() && !$errors->hasAny(['email', 'password']))
                        <div class="alert-msg error" role="alert">
                            {{ $errors->first() }}
                        </div>
                    @endif

                    <div class="form-group">
                        <label class="form-label" for="email">Alamat email</label>
                        <input id="email" type="email" name="email" class="form-input" value="{{ old('email') }}"
                            placeholder="nama@organisasi.id" required autocomplete="email" inputmode="email" autocapitalize="none" spellcheck="false" maxlength="255"
                            @error('email') aria-invalid="true" aria-describedby="email-error" @enderror>
                        @error('email')
                            <div id="email-error" class="error-msg" role="alert">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="password">Kata sandi</label>
                        <input id="password" type="password" name="password" class="form-input"
                            placeholder="Masukkan kata sandi" required autocomplete="current-password"
                            @error('password') aria-invalid="true" aria-describedby="password-error" @enderror>
                        @error('password')
                            <div id="password-error" class="error-msg" role="alert">{{ $message }}</div>
                        @enderror
                    </div>

                    <button type="submit" class="login-btn">Masuk ke SOC</button>
                </form>

                <p class="footer-note">Akses terbatas untuk personel berwenang.</p>
            </div>
        </section>
    </main>
</body>

</html>
