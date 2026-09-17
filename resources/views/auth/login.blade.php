<!DOCTYPE html>
<html lang="id" class="dark" data-theme="dark">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="{{ asset('images/brand/siem-salabim-mark.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('images/brand/siem-salabim-mark.png') }}">
    <title>Masuk &mdash; SIEM Salabim</title>
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
    <button class="theme-toggle-login" type="button" data-theme-toggle title="Ubah tema" aria-label="Ubah tema gelap atau terang">
        <span class="theme-icon-dark" aria-hidden="true"><i data-lucide="moon"></i></span>
        <span class="theme-icon-light" aria-hidden="true"><i data-lucide="sun"></i></span>
    </button>

    <main class="auth-login-layout" aria-label="Masuk ke SIEM Salabim">
        <section class="auth-brand-panel" aria-labelledby="auth-brand-title">
            <div class="auth-brand-content">
                <a href="{{ url('/') }}" class="auth-brand-logo" aria-label="SIEM Salabim">
                    <img src="{{ asset('images/brand/siem-salabim-vertical.png') }}" width="1254" height="1254" alt="SIEM Salabim">
                </a>
                <h1 id="auth-brand-title" class="sr-only">SIEM Salabim</h1>
                <p class="auth-brand-description">
                    SIEM Salabim membantu tim SOC memantau, memprioritaskan, dan menindaklanjuti alarm Wazuh teragregasi dengan konteks yang jelas.
                </p>
            </div>
        </section>

        <section class="auth-signin-panel" aria-labelledby="login-form-title">
            <div class="login-container">
                <header class="auth-login-heading">
                    <p class="auth-login-eyebrow">Akses terautentikasi</p>
                    <h2 id="login-form-title">Masuk ke SOC</h2>
                    <p>Gunakan akun yang telah disetujui untuk mengakses operasi keamanan.</p>
                </header>

                <form method="POST" action="{{ route('login') }}" class="auth-login-form">
                    @csrf

                    @if($errors->any())
                        <div class="alert-msg error" role="alert">
                            {{ $errors->first() }}
                        </div>
                    @endif

                    <div class="form-group">
                        <label class="form-label" for="email">Alamat email</label>
                        <input id="email" type="email" name="email" class="form-input" value="{{ old('email') }}"
                            placeholder="analyst@siem.local" required autofocus autocomplete="email" autocapitalize="none" spellcheck="false">
                        @error('email')
                            <div class="error-msg">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="password">Kata sandi</label>
                        <input id="password" type="password" name="password" class="form-input"
                            placeholder="Masukkan kata sandi" required autocomplete="current-password">
                        @error('password')
                            <div class="error-msg">{{ $message }}</div>
                        @enderror
                    </div>

                    <button type="submit" class="login-btn">Masuk ke SOC Platform</button>
                </form>

                <p class="footer-note">SIEM Salabim v1.0 &middot; Akses terbatas untuk personel berwenang</p>
            </div>
        </section>
    </main>
</body>

</html>
