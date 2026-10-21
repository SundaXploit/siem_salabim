<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>Instalasi Selesai &mdash; SIEM Salabim</title>
    <style>
        :root { color-scheme: dark; --bg: #07111f; --panel: #0d1b2d; --line: #263b55; --text: #e7eef8; --muted: #9eb0c6; --success: #34d399; }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px;
            color: var(--text); background: radial-gradient(circle at 50% 0%, rgba(52, 211, 153, .13), transparent 35rem), var(--bg);
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }
        main { width: min(600px, 100%); padding: clamp(28px, 6vw, 52px); text-align: center; border: 1px solid var(--line); border-radius: 22px; background: var(--panel); box-shadow: 0 28px 80px rgba(0, 0, 0, .32); }
        .icon { display: grid; place-items: center; width: 70px; height: 70px; margin: 0 auto 22px; border-radius: 50%; color: #052219; background: var(--success); font-size: 2rem; font-weight: 900; }
        h1 { margin: 0 0 10px; font-size: clamp(1.7rem, 5vw, 2.35rem); }
        p { margin: 0 auto 27px; max-width: 460px; color: var(--muted); line-height: 1.65; }
        a { display: inline-flex; min-height: 46px; align-items: center; justify-content: center; padding: 10px 20px; border-radius: 10px; color: #042034; background: #38bdf8; font-weight: 800; text-decoration: none; }
        .note { margin-top: 23px; color: #8094ab; font-size: .8rem; }
    </style>
</head>
<body>
    <main>
        <div class="icon" aria-hidden="true">&#10003;</div>
        <h1>Instalasi selesai</h1>
        <p>SIEM Salabim sudah dikonfigurasi dan installer telah ditutup. Masuk menggunakan akun administrator yang baru Anda buat.</p>
        <a href="{{ route('login') }}">Masuk ke SIEM Salabim</a>
        <div class="note">Token instalasi sebelumnya tidak dapat digunakan kembali.</div>
    </main>
</body>
</html>
