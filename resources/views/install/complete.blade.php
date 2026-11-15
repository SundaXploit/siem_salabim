<!DOCTYPE html>
<html lang="id" class="dark" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>Instalasi Selesai - SIEM Salabim</title>
    @include('partials.brand-head')
    <style>
        :root { --bg: var(--color-canvas); --panel: var(--color-surface-1); --line: var(--color-border-default); --text: var(--color-text-primary); --muted: var(--color-text-secondary); --success: var(--color-success); }
        * { box-sizing: border-box; }
        body {
            margin: var(--spacing-0); min-height: 100vh; display: grid; place-items: center; padding: var(--spacing-24);
            color: var(--text); background: var(--bg);
            font-family: var(--font-sans);
            font-size: var(--ina-font-size-body);
            line-height: var(--ina-line-height-body);
        }
        main { width: min(600px, 100%); padding: var(--spacing-56); text-align: center; border: 1px solid var(--line); border-radius: 4px; background: var(--panel); box-shadow: none; }
        .icon { display: grid; place-items: center; width: 70px; height: 70px; margin: var(--spacing-0) auto var(--spacing-24); border-radius: 50%; color: var(--color-on-success); background: var(--success); font-size: var(--ina-font-size-heading-3); font-weight: var(--font-weight-bold); line-height: var(--ina-line-height-heading-3); }
        h1 { margin: var(--spacing-0) var(--spacing-0) var(--spacing-12); font-size: var(--text-page); line-height: var(--leading-page); }
        p { margin: var(--spacing-0) auto var(--spacing-24); max-width: 460px; color: var(--muted); line-height: var(--ina-line-height-body); }
        a { display: inline-flex; min-height: 46px; align-items: center; justify-content: center; padding: var(--spacing-12) var(--spacing-24); border-radius: 3px; color: var(--color-on-primary); background: var(--color-primary); font-weight: var(--font-weight-bold); text-decoration: none; }
        .note { margin-top: var(--spacing-24); color: var(--color-text-muted); font-size: var(--ina-font-size-caption-small); line-height: var(--ina-line-height-caption-small); }
            :focus-visible { outline: 2px solid var(--color-focus); outline-offset: 3px; }
        @media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation: none !important; transition: none !important; } }
        @media (max-width: 640px) { main { padding: var(--spacing-24); } }
    </style>
</head>
<body>
    <main>
        <div class="completion-brand"><x-application-logo alt="" /><span class="brand-name">SIEM <strong>Salabim</strong></span></div>
        <div class="icon" aria-hidden="true">&#10003;</div>
        <h1>Instalasi selesai</h1>
        <p>SIEM Salabim sudah dikonfigurasi dan installer telah ditutup. Masuk menggunakan akun administrator yang baru Anda buat.</p>
        <a href="{{ route('login') }}">Masuk ke SIEM Salabim</a>
        <div class="note">Token instalasi sebelumnya tidak dapat digunakan kembali.</div>
    </main>
</body>
</html>
