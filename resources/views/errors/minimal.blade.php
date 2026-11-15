<!DOCTYPE html>
<html lang="id" class="dark" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title') - SIEM Salabim</title>
    @include('partials.brand-head')
    <script>
        try { document.documentElement.dataset.theme = localStorage.getItem('siem-theme') === 'light' ? 'light' : 'dark'; } catch (_) {}
    </script>
    <style>
        :root { color-scheme: dark; --canvas: var(--color-canvas); --surface: var(--color-surface-1); --text: var(--color-text-primary); --secondary: var(--color-text-secondary); --muted: var(--color-text-muted); --border: var(--color-border-default); --accent: var(--color-accent); --on-accent: var(--color-canvas); --focus: var(--color-focus); }
        :root[data-theme='light'] { color-scheme: light; --canvas: var(--color-canvas); --surface: var(--color-surface-1); --text: var(--color-text-primary); --secondary: var(--color-text-secondary); --muted: var(--color-text-muted); --border: var(--color-border-default); --accent: var(--color-accent); --on-accent: var(--color-surface-3); --focus: var(--color-focus); }
        * { box-sizing: border-box; }
        body { display: grid; min-height: 100svh; place-items: center; margin: var(--spacing-0); padding: var(--spacing-24) var(--spacing-16); background: var(--canvas); color: var(--text); font: var(--ina-font-size-body)/var(--ina-line-height-body) var(--font-sans); }
        main { width: min(100%, 480px); }
        .brand { display: inline-block; margin-bottom: var(--spacing-24); color: var(--text); text-decoration: none; font-size: var(--ina-font-size-body-small); line-height: var(--ina-line-height-body-small); }
        .brand strong { font-weight: var(--font-weight-bold); }
        .error-panel { padding: var(--spacing-32); border: 1px solid var(--border); border-radius: 4px; background: var(--surface); }
        .status-code { margin: var(--spacing-0) var(--spacing-0) var(--spacing-16); color: var(--muted); font-size: var(--ina-font-size-caption); font-variant-numeric: tabular-nums; line-height: var(--ina-line-height-caption); }
        h1 { margin: var(--spacing-0); font-size: var(--text-page); line-height: var(--leading-page); font-weight: var(--font-weight-bold); letter-spacing: var(--ina-letter-spacing-body); }
        .description { margin: var(--spacing-16) var(--spacing-0) var(--spacing-24); color: var(--secondary); overflow-wrap: anywhere; }
        .home-link { display: inline-flex; min-height: 44px; align-items: center; justify-content: center; padding: var(--spacing-8) var(--spacing-16); background: var(--color-primary); color: var(--color-on-primary); border: 1px solid var(--color-accent); border-radius: 3px; text-decoration: none; font-weight: var(--font-weight-bold); }
        :focus-visible { outline: 2px solid var(--focus); outline-offset: 3px; }
        @media (max-width: 480px) { .error-panel { padding: var(--spacing-24) var(--spacing-24); } .home-link { width: 100%; } }
    </style>
</head>
<body data-ui-view="errors/@yield('code')">
    <main aria-labelledby="error-heading">
        <a class="brand error-brand" href="{{ url('/') }}"><x-application-logo alt="" /><span class="brand-name">SIEM <strong>Salabim</strong></span></a>
        <section class="error-panel">
            <p class="status-code">HTTP @yield('code')</p>
            <h1 id="error-heading">@yield('title')</h1>
            <p class="description">@yield('description')</p>
            <a class="home-link" href="{{ url('/') }}">Kembali ke halaman utama</a>
        </section>
    </main>
</body>
</html>
