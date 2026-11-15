<!DOCTYPE html>
<html lang="id" class="dark" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>Instalasi - SIEM Salabim</title>
    @include('partials.brand-head')
    <style>
        :root {
            --bg: var(--color-canvas);
            --panel: var(--color-surface-1);
            --panel-2: var(--color-surface-3);
            --line: var(--color-border-default);
            --text: var(--color-text-primary);
            --muted: var(--color-text-secondary);
            --primary: var(--color-accent);
            --primary-strong: var(--color-accent-hover);
            --success: var(--color-success);
            --danger: var(--color-danger);
            --warning: var(--color-warning);
        }
        * { box-sizing: border-box; }
        body {
            margin: var(--spacing-0);
            min-height: 100vh;
            background: var(--bg);
            color: var(--text);
            font-family: var(--font-sans);
            font-size: var(--ina-font-size-body);
            line-height: var(--ina-line-height-body);
        }
        button, input, select { font: inherit; }
        button { cursor: pointer; }
        .shell { width: min(1120px, calc(100% - 2 * var(--spacing-16))); margin: var(--spacing-0) auto; padding: var(--spacing-40) var(--spacing-0) var(--spacing-64); }
        .masthead { display: flex; align-items: center; gap: var(--spacing-16); margin-bottom: var(--spacing-24); }
        .mark {
            display: grid; place-items: center; width: 46px; height: 46px; border-radius: 3px;
            background: var(--primary); color: var(--color-canvas); font-weight: var(--font-weight-bold);
            box-shadow: none;
        }
        .masthead h1 { margin: var(--spacing-0); font-size: var(--ina-font-size-body); letter-spacing: var(--ina-letter-spacing-body); line-height: var(--ina-line-height-body); }
        .masthead p { margin: var(--spacing-2) var(--spacing-0) var(--spacing-0); color: var(--muted); font-size: var(--ina-font-size-caption); line-height: var(--ina-line-height-caption); }
        .card {
            border: 1px solid var(--line); border-radius: 4px; background: var(--panel);
            box-shadow: none; overflow: hidden;
        }
        .gate { max-width: 600px; margin: var(--spacing-64) auto var(--spacing-0); padding: var(--spacing-48); }
        .gate-icon {
            display: grid; place-items: center; width: 54px; height: 54px; border-radius: 3px;
            color: var(--primary); background: var(--color-info-soft); border: 1px solid var(--color-info-border);
            font-size: var(--ina-font-size-heading-5);
            line-height: var(--ina-line-height-heading-5);
        }
        h2 { margin: var(--spacing-16) var(--spacing-0) var(--spacing-8); font-size: var(--ina-font-size-heading-3); line-height: var(--ina-line-height-heading-3); }
        .lead { margin: var(--spacing-0) var(--spacing-0) var(--spacing-24); color: var(--muted); }
        .security-note {
            margin-top: var(--spacing-24); padding: var(--spacing-12) var(--spacing-16); border-radius: 3px; color: var(--color-info);
            border: 1px solid var(--color-info-border); background: var(--color-surface-2); font-size: var(--ina-font-size-caption); line-height: var(--ina-line-height-caption);
        }
        .wizard { display: grid; grid-template-columns: 245px minmax(0, 1fr); min-height: 670px; }
        .sidebar { padding: var(--spacing-32) var(--spacing-24); background: var(--color-surface-1); border-right: 1px solid var(--line); }
        .sidebar h2 { margin: var(--spacing-0) var(--spacing-8) var(--spacing-8); font-size: var(--ina-font-size-body); line-height: var(--ina-line-height-body); }
        .sidebar > p { margin: var(--spacing-0) var(--spacing-8) var(--spacing-24); color: var(--muted); font-size: var(--ina-font-size-caption); line-height: var(--ina-line-height-caption); }
        .progress { height: 5px; margin: var(--spacing-0) var(--spacing-8) var(--spacing-24); border-radius: 4px; background: var(--color-info-soft); overflow: hidden; }
        .progress span { display: block; height: 100%; width: 16.666%; border-radius: inherit; background: var(--primary); transition: width .2s ease; }
        .steps { display: grid; gap: var(--spacing-8); }
        .step-link {
            display: flex; gap: var(--spacing-12); align-items: center; width: 100%; padding: var(--spacing-12) var(--spacing-12); border: 0;
            border-radius: 3px; text-align: left; color: var(--muted); background: transparent;
        }
        .step-link[aria-current="step"] { color: var(--text); background: var(--color-surface-3); }
        .step-number {
            flex: 0 0 auto; display: grid; place-items: center; width: 28px; height: 28px; border-radius: 50%;
            border: 1px solid var(--color-border-strong); font-size: var(--ina-font-size-caption); font-weight: var(--font-weight-bold); line-height: var(--ina-line-height-caption);
        }
        .step-link[aria-current="step"] .step-number { color: var(--color-canvas); background: var(--primary); border-color: var(--primary); }
        .content { min-width: 0; padding: var(--spacing-48); }
        .eyebrow { margin: var(--spacing-0) var(--spacing-0) var(--spacing-4); color: var(--primary); font-size: var(--ina-font-size-caption-small); font-weight: var(--font-weight-bold); letter-spacing: var(--ina-letter-spacing-body); text-transform: uppercase; line-height: var(--ina-line-height-caption-small); }
        .step-panel > h2 { margin: var(--spacing-0) var(--spacing-0) var(--spacing-8); }
        .step-description { margin: var(--spacing-0) var(--spacing-0) var(--spacing-24); color: var(--muted); }
        .grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--spacing-16); }
        .span-2 { grid-column: span 2; }
        .field { min-width: 0; }
        label { display: block; margin-bottom: var(--spacing-8); font-size: var(--ina-font-size-caption); font-weight: var(--font-weight-bold); line-height: var(--ina-line-height-caption); }
        .hint { display: block; margin-top: var(--spacing-8); color: var(--muted); font-size: var(--ina-font-size-caption-small); line-height: var(--ina-line-height-caption-small); }
        input, select {
            width: 100%; min-height: 45px; padding: var(--spacing-12) var(--spacing-12); color: var(--text); background: var(--color-surface-2);
            border: 1px solid var(--color-border-strong); border-radius: 3px; outline: none;
        }
        input:focus, select:focus { border-color: var(--primary); box-shadow: 0 0 0 2px var(--color-focus); }
        input::placeholder { color: var(--color-text-muted); }
        .field-error { margin-top: var(--spacing-8); color: var(--color-danger); font-size: var(--ina-font-size-caption); line-height: var(--ina-line-height-caption); }
        .alert {
            margin-bottom: var(--spacing-24); padding: var(--spacing-12) var(--spacing-16); border-radius: 3px; border: 1px solid var(--color-danger-border);
            background: var(--color-danger-soft); color: var(--color-danger); font-size: var(--ina-font-size-caption); line-height: var(--ina-line-height-caption);
        }
        .toggle-box {
            display: flex; gap: var(--spacing-12); align-items: flex-start; padding: var(--spacing-16); border: 1px solid var(--line);
            background: var(--color-surface-2); border-radius: 3px;
        }
        .toggle-box input { flex: 0 0 auto; width: 19px; min-height: 19px; height: 19px; margin-top: var(--spacing-2); accent-color: var(--primary); }
        .toggle-box label { margin: var(--spacing-0); }
        .toggle-box p { margin: var(--spacing-4) var(--spacing-0) var(--spacing-0); color: var(--muted); font-size: var(--ina-font-size-caption); line-height: var(--ina-line-height-caption); }
        .optional-fields { margin: var(--spacing-16) var(--spacing-0) var(--spacing-0); padding: var(--spacing-0); border: 0; min-width: 0; }
        .chat-list { display: grid; gap: var(--spacing-12); }
        .chat-row { display: grid; grid-template-columns: 1fr auto; gap: var(--spacing-8); }
        .icon-button {
            min-width: 44px; border-radius: 3px; border: 1px solid var(--color-danger-border); color: var(--color-danger); background: var(--color-danger-soft);
        }
        .add-button { margin-top: var(--spacing-12); padding: var(--spacing-8) var(--spacing-12); color: var(--primary); border: 1px solid var(--color-info-border); border-radius: 3px; background: var(--color-surface-3); }
        .review {
            padding: var(--spacing-16); border: 1px solid var(--color-info-border); border-radius: 3px; background: var(--color-surface-2);
        }
        .review h3 { margin: var(--spacing-0) var(--spacing-0) var(--spacing-8); font-size: var(--ina-font-size-body-small); line-height: var(--ina-line-height-body-small); }
        .review ul { margin: var(--spacing-0); padding-left: var(--spacing-24); color: var(--muted); font-size: var(--ina-font-size-caption); line-height: var(--ina-line-height-caption); }
        .actions { display: flex; justify-content: space-between; gap: var(--spacing-12); margin-top: var(--spacing-32); padding-top: var(--spacing-24); border-top: 1px solid var(--line); }
        .button { min-height: 44px; padding: var(--spacing-12) var(--spacing-16); border-radius: 3px; border: 1px solid transparent; font-weight: var(--font-weight-bold); }
        .button-primary { color: var(--color-on-primary); background: var(--color-primary); border-color: var(--color-accent); }
        .button-primary:hover { background: var(--color-primary-hover); }
        .button-secondary { color: var(--text); border-color: var(--color-border-strong); background: var(--color-surface-3); }
        .button:disabled { cursor: not-allowed; opacity: .6; }
        [hidden] { display: none !important; }
        .noscript { margin-bottom: var(--spacing-24); padding: var(--spacing-12); border-radius: 3px; color: var(--color-warning); background: var(--color-warning-soft); }
        @media (max-width: 800px) {
            .content, .gate { padding: var(--spacing-24); }
            .shell { width: min(100% - 2 * var(--spacing-12), 680px); padding-top: var(--spacing-24); }
            .wizard { grid-template-columns: 1fr; }
            .sidebar { border-right: 0; border-bottom: 1px solid var(--line); }
            .sidebar h2, .sidebar > p { display: none; }
            .steps { grid-template-columns: repeat(6, 1fr); }
            .step-link { justify-content: center; padding: var(--spacing-8); }
            .step-label { display: none; }
            .grid { grid-template-columns: 1fr; }
            .span-2 { grid-column: span 1; }
        }
        @media (max-width: 480px) {
            .content { padding: var(--spacing-24) var(--spacing-16); }
            .gate { padding: var(--spacing-24) var(--spacing-24); }
            .actions { flex-wrap: wrap; }
            .actions .button { flex: 1; }
        }
            :focus-visible { outline: 2px solid var(--color-focus); outline-offset: 3px; }
        @media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation: none !important; transition: none !important; } }
    </style>
</head>
<body>
    <main class="shell">
        <header class="masthead">
            <x-application-logo alt="" />
            <div>
                <h1>SIEM Salabim</h1>
                <p>Installer aman untuk konfigurasi awal</p>
            </div>
        </header>

        @unless($unlocked)
            <section class="card gate" aria-labelledby="gate-title">
                <p class="eyebrow">Konfigurasi awal</p>
                <h2 id="gate-title">Buka sesi instalasi</h2>
                <p class="lead">Masukkan token instalasi sekali pakai yang diberikan oleh administrator server. Token tidak akan disimpan di browser maupun ditampilkan kembali.</p>

                @if($errors->any())
                    <div class="alert" role="alert">{{ $errors->first() }}</div>
                @endif

                <form method="POST" action="{{ route('install.unlock') }}" autocomplete="off">
                    @csrf
                    <div class="field">
                        <label for="installation_token">Token instalasi</label>
                        <input id="installation_token" name="installation_token" type="password" required autofocus
                            autocomplete="new-password" maxlength="4096" spellcheck="false" aria-describedby="token-help">
                        @error('installation_token')
                            <div class="field-error">{{ $message }}</div>
                        @enderror
                        <span id="token-help" class="hint">Demi keamanan, kegagalan tidak akan mengisi ulang token ini.</span>
                    </div>
                    <div class="actions">
                        <span></span>
                        <button class="button button-primary" type="submit">Verifikasi token</button>
                    </div>
                </form>
                <div class="security-note">
                    Administrator server dapat membuat token melalui
                    <code>php artisan siem:prepare-installation</code>. Token tidak pernah ditampilkan oleh halaman web ini.
                    Akses diberi rate limit dan sesi konfigurasi otomatis berakhir setelah 30 menit.
                </div>
            </section>
        @else
            <section class="card wizard" aria-labelledby="wizard-title">
                <aside class="sidebar">
                    <h2 id="wizard-title">Konfigurasi awal</h2>
                    <p>Isi aplikasi, database, dan admin. Integrasi boleh dilewati.</p>
                    <div class="progress" aria-hidden="true"><span data-progress></span></div>
                    <nav class="steps" aria-label="Tahap instalasi">
                        @foreach(['Aplikasi', 'Database', 'OpenSearch', 'Telegram', 'Integrasi', 'Admin'] as $index => $label)
                            <button class="step-link" type="button" data-step-link="{{ $index }}" @if($index === 0) aria-current="step" @endif>
                                <span class="step-number">{{ $index + 1 }}</span>
                                <span class="step-label">{{ $label }}</span>
                            </button>
                        @endforeach
                    </nav>
                </aside>

                <form class="content" method="POST" action="{{ route('install.store') }}" autocomplete="off" novalidate data-installer-form>
                    @csrf
                    <noscript><div class="noscript">JavaScript diperlukan untuk navigasi wizard. Semua validasi keamanan tetap dilakukan di server.</div></noscript>

                    @if($errors->has('installation'))
                        <div class="alert" role="alert">{{ $errors->first('installation') }}</div>
                    @elseif($errors->any())
                        <div class="alert" role="alert">Beberapa konfigurasi perlu diperbaiki. Wizard telah membuka tahap pertama yang bermasalah.</div>
                    @endif

                    <section class="step-panel" data-step-panel="0">
                        <p class="eyebrow">Tahap 1 dari 6</p>
                        <h2>Identitas aplikasi</h2>
                        <p class="step-description">Alamat ini dipakai untuk tautan dashboard, callback, dan notifikasi.</p>
                        <div class="grid">
                            <div class="field span-2">
                                <label for="app_name">Nama aplikasi</label>
                                <input id="app_name" name="app_name" value="{{ old('app_name', $defaults['app_name']) }}" required maxlength="100">
                                @error('app_name')<div class="field-error">{{ $message }}</div>@enderror
                            </div>
                            <div class="field">
                                <label for="app_url">URL aplikasi</label>
                                <input id="app_url" name="app_url" type="url" value="{{ old('app_url', $defaults['app_url']) }}" required maxlength="2048" placeholder="https://siem.example.go.id">
                                @error('app_url')<div class="field-error">{{ $message }}</div>@enderror
                            </div>
                            <div class="field">
                                <label for="app_timezone">Zona waktu</label>
                                <select id="app_timezone" name="app_timezone" required>
                                    @foreach(['Asia/Jakarta', 'Asia/Makassar', 'Asia/Jayapura', 'UTC'] as $timezone)
                                        <option value="{{ $timezone }}" @selected(old('app_timezone', $defaults['app_timezone']) === $timezone)>{{ $timezone }}</option>
                                    @endforeach
                                </select>
                                @error('app_timezone')<div class="field-error">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    </section>

                    <section class="step-panel" data-step-panel="1" hidden>
                        <p class="eyebrow">Tahap 2 dari 6</p>
                        <h2>Koneksi database</h2>
                        <p class="step-description">Gunakan database khusus. PostgreSQL direkomendasikan untuk instalasi dengan volume log besar.</p>
                        <div class="grid">
                            <div class="field">
                                <label for="db_connection">Mesin database</label>
                                <select id="db_connection" name="db_connection" required data-db-driver>
                                    <option value="pgsql" @selected(old('db_connection', $defaults['db_connection']) === 'pgsql')>PostgreSQL (direkomendasikan)</option>
                                    <option value="mysql" @selected(old('db_connection', $defaults['db_connection']) === 'mysql')>MySQL</option>
                                    <option value="mariadb" @selected(old('db_connection', $defaults['db_connection']) === 'mariadb')>MariaDB</option>
                                </select>
                                @error('db_connection')<div class="field-error">{{ $message }}</div>@enderror
                            </div>
                            <div class="field">
                                <label for="db_host">Host</label>
                                <input id="db_host" name="db_host" value="{{ old('db_host', $defaults['db_host']) }}" required maxlength="255">
                                @error('db_host')<div class="field-error">{{ $message }}</div>@enderror
                            </div>
                            <div class="field">
                                <label for="db_port">Port</label>
                                <input id="db_port" name="db_port" type="number" min="1" max="65535" value="{{ old('db_port', $defaults['db_port']) }}" required data-db-port>
                                @error('db_port')<div class="field-error">{{ $message }}</div>@enderror
                            </div>
                            <div class="field">
                                <label for="db_database">Nama database</label>
                                <input id="db_database" name="db_database" value="{{ old('db_database') }}" required maxlength="255" placeholder="siem_salabim">
                                @error('db_database')<div class="field-error">{{ $message }}</div>@enderror
                            </div>
                            <div class="field">
                                <label for="db_username">Pengguna</label>
                                <input id="db_username" name="db_username" value="{{ old('db_username') }}" required maxlength="255" autocomplete="username">
                                @error('db_username')<div class="field-error">{{ $message }}</div>@enderror
                            </div>
                            <div class="field">
                                <label for="db_password">Kata sandi</label>
                                <input id="db_password" name="db_password" type="password" maxlength="4096" autocomplete="new-password">
                                @error('db_password')<div class="field-error">{{ $message }}</div>@enderror
                                <span class="hint">Boleh kosong hanya jika server memang mengizinkannya.</span>
                            </div>
                            <div class="field">
                                <label for="db_sslmode">TLS PostgreSQL</label>
                                <select id="db_sslmode" name="db_sslmode">
                                    @foreach(['prefer' => 'Prefer', 'require' => 'Require', 'verify-ca' => 'Verifikasi CA', 'verify-full' => 'Verifikasi penuh', 'disable' => 'Nonaktif'] as $value => $label)
                                        <option value="{{ $value }}" @selected(old('db_sslmode', 'prefer') === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                                @error('db_sslmode')<div class="field-error">{{ $message }}</div>@enderror
                                <span class="hint">Dipakai hanya saat PostgreSQL dipilih; produksi sebaiknya require atau lebih ketat.</span>
                            </div>
                        </div>
                    </section>

                    <section class="step-panel" data-step-panel="2" hidden>
                        <p class="eyebrow">Tahap 3 dari 6</p>
                        <h2>Wazuh / OpenSearch</h2>
                        <p class="step-description">Opsional. Tanpa OpenSearch, dashboard tetap dapat dibuka dengan data lokal yang tersedia.</p>
                        <div class="toggle-box">
                            <input type="hidden" name="opensearch_enabled" value="0">
                            <input id="opensearch_enabled" name="opensearch_enabled" type="checkbox" value="1" @checked((bool) old('opensearch_enabled', false)) data-toggle="opensearch-fields">
                            <div><label for="opensearch_enabled">Konfigurasi OpenSearch sekarang</label><p>Boleh dilewati dan diatur kembali melalui Pengaturan.</p></div>
                        </div>
                        <fieldset class="optional-fields grid" id="opensearch-fields" @if(! old('opensearch_enabled', false)) hidden disabled @endif>
                            <legend class="hint">Konfigurasi OpenSearch</legend>
                            <div class="field span-2">
                                <label for="opensearch_host">Endpoint OpenSearch</label>
                                <input id="opensearch_host" name="opensearch_host" type="url" value="{{ old('opensearch_host', $defaults['opensearch_host']) }}" required maxlength="2048">
                                @error('opensearch_host')<div class="field-error">{{ $message }}</div>@enderror
                            </div>
                            <div class="field">
                                <label for="opensearch_username">Pengguna</label>
                                <input id="opensearch_username" name="opensearch_username" value="{{ old('opensearch_username') }}" required maxlength="255" autocomplete="off">
                                @error('opensearch_username')<div class="field-error">{{ $message }}</div>@enderror
                            </div>
                            <div class="field">
                                <label for="opensearch_password">Kata sandi</label>
                                <input id="opensearch_password" name="opensearch_password" type="password" required maxlength="4096" autocomplete="new-password">
                                @error('opensearch_password')<div class="field-error">{{ $message }}</div>@enderror
                            </div>
                            <div class="field">
                                <label for="opensearch_index">Pola indeks alert</label>
                                <input id="opensearch_index" name="opensearch_index" value="{{ old('opensearch_index', $defaults['opensearch_index']) }}" required maxlength="255">
                                @error('opensearch_index')<div class="field-error">{{ $message }}</div>@enderror
                            </div>
                            <div class="field">
                                <label for="opensearch_source_cluster">Identitas sumber</label>
                                <input id="opensearch_source_cluster" name="opensearch_source_cluster" value="{{ old('opensearch_source_cluster', $defaults['opensearch_source_cluster']) }}" required maxlength="64" pattern="[A-Za-z0-9_.-]+">
                                @error('opensearch_source_cluster')<div class="field-error">{{ $message }}</div>@enderror
                                <span class="hint">Nama stabil untuk deduplikasi; jangan mengikuti hostname.</span>
                            </div>
                            <div class="field span-2">
                                <div class="toggle-box">
                                    <input type="hidden" name="opensearch_verify_ssl" value="0">
                                    <input id="opensearch_verify_ssl" name="opensearch_verify_ssl" type="checkbox" value="1" @checked((bool) old('opensearch_verify_ssl', true))>
                                    <div>
                                        <label for="opensearch_verify_ssl">Verifikasi SSL Wazuh/OpenSearch (aktif = true; nonaktif = false)</label>
                                        <p>Direkomendasikan true. Jika uji koneksi gagal karena sertifikat internal, false dapat dipakai sementara pada jaringan tepercaya. Perbaiki CA/nama host lalu aktifkan true kembali.</p>
                                    </div>
                                </div>
                                @error('opensearch_verify_ssl')<div class="field-error">{{ $message }}</div>@enderror
                            </div>
                        </fieldset>
                        <button class="add-button" type="button" data-skip-integration="opensearch_enabled">Lewati OpenSearch</button>
                    </section>

                    <section class="step-panel" data-step-panel="3" hidden>
                        <p class="eyebrow">Tahap 4 dari 6</p>
                        <h2>Notifikasi Telegram</h2>
                        <p class="step-description">Opsional. Aktifkan sekarang atau atur nanti di Pengaturan untuk mengirim notifikasi.</p>
                        <div class="toggle-box">
                            <input type="hidden" name="telegram_enabled" value="0">
                            <input id="telegram_enabled" name="telegram_enabled" type="checkbox" value="1" @checked((bool) old('telegram_enabled', false)) data-toggle="telegram-fields">
                            <div><label for="telegram_enabled">Konfigurasi Telegram sekarang</label><p>Boleh dilewati dan diatur kembali melalui Pengaturan.</p></div>
                        </div>
                        <fieldset class="optional-fields grid" id="telegram-fields" @if(! old('telegram_enabled', false)) hidden disabled @endif>
                            <legend class="hint">Konfigurasi Telegram</legend>
                            <div class="field span-2">
                                <label for="telegram_bot_token">Token bot</label>
                                <input id="telegram_bot_token" name="telegram_bot_token" type="password" required maxlength="4096" autocomplete="new-password">
                                @error('telegram_bot_token')<div class="field-error">{{ $message }}</div>@enderror
                            </div>
                            <div class="field span-2">
                                <label>Tujuan chat</label>
                                <div class="chat-list" data-chat-list>
                                    @foreach((array) old('telegram_chat_ids', ['']) as $index => $chatId)
                                        <div class="chat-row">
                                            <div>
                                                <input name="telegram_chat_ids[]" value="{{ $chatId }}" required maxlength="100" placeholder="-1001234567890 atau @nama_channel" aria-label="ID chat Telegram {{ $index + 1 }}">
                                                @error('telegram_chat_ids.'.$index)<div class="field-error">{{ $message }}</div>@enderror
                                            </div>
                                            <button class="icon-button" type="button" data-remove-chat aria-label="Hapus tujuan chat">&times;</button>
                                        </div>
                                    @endforeach
                                </div>
                                @error('telegram_chat_ids')<div class="field-error">{{ $message }}</div>@enderror
                                <button class="add-button" type="button" data-add-chat>+ Tambah tujuan</button>
                            </div>
                        </fieldset>
                        <button class="add-button" type="button" data-skip-integration="telegram_enabled">Lewati Telegram</button>
                    </section>

                    <section class="step-panel" data-step-panel="4" hidden>
                        <p class="eyebrow">Tahap 5 dari 6</p>
                        <h2>Integrasi opsional</h2>
                        <p class="step-description">AI dan SMTP dapat dilewati sekarang lalu dikonfigurasi kembali oleh admin.</p>
                        <div class="grid">
                            <div class="field span-2">
                                <div class="toggle-box">
                                    <input type="hidden" name="ai_enabled" value="0">
                                    <input id="ai_enabled" name="ai_enabled" type="checkbox" value="1" @checked((bool) old('ai_enabled', false)) data-toggle="ai-fields">
                                    <div>
                                        <label for="ai_enabled">Aktifkan analisis AI</label>
                                        <p>AI akan menerima data alert yang telah dinormalisasi, bukan seluruh raw log.</p>
                                    </div>
                                </div>
                                <div class="optional-fields grid" id="ai-fields" data-ai-fields data-ai-models-url="{{ route('install.ai.models', [], false) }}" @if(! old('ai_enabled', false)) hidden @endif>
                                    <div class="field">
                                        <label for="ai_provider">Provider AI</label>
                                        <select id="ai_provider" name="ai_provider" data-conditional-required>
                                            @foreach(config('ai_providers') as $value => $provider)
                                                <option value="{{ $value }}" @selected(old('ai_provider', 'custom') === $value)>{{ $provider['label'] }}</option>
                                            @endforeach
                                        </select>
                                        @error('ai_provider')<div class="field-error">{{ $message }}</div>@enderror
                                    </div>
                                    <div class="field">
                                        <label for="ai_api_format">Format API</label>
                                        <select id="ai_api_format" name="ai_api_format" data-conditional-required>
                                            @foreach(['chat_completions' => 'Chat Completions', 'responses' => 'Responses', 'anthropic_messages' => 'Anthropic Messages'] as $value => $label)
                                                <option value="{{ $value }}" @selected(old('ai_api_format', 'chat_completions') === $value)>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                        @error('ai_api_format')<div class="field-error">{{ $message }}</div>@enderror
                                    </div>
                                    <div class="field span-2">
                                        <label for="ai_api_key">API key AI</label>
                                        <input id="ai_api_key" name="ai_api_key" type="password" maxlength="4096" autocomplete="new-password">
                                        @error('ai_api_key')<div class="field-error">{{ $message }}</div>@enderror
                                        <span class="hint">LLM lokal boleh tanpa API key. Token disimpan terenkripsi.</span>
                                    </div>
                                    <div class="field">
                                        <label for="ai_base_url">Base URL API</label>
                                        <input id="ai_base_url" name="ai_base_url" type="url" value="{{ old('ai_base_url', $defaults['ai_base_url']) }}" maxlength="2048" data-conditional-required>
                                        @error('ai_base_url')<div class="field-error">{{ $message }}</div>@enderror
                                        <span class="hint">Base URL tanpa akhiran /chat/completions, /responses, atau /messages. Cloud memakai HTTPS; lokal boleh HTTP.</span>
                                    </div>
                                    <div class="field">
                                        <label for="ai_model">Model</label>
                                        <input id="ai_model" name="ai_model" value="{{ old('ai_model', $defaults['ai_model']) }}" maxlength="255" data-conditional-required>
                                        @include('partials.ai-model-discovery', ['buttonClass' => 'add-button', 'helpClass' => 'hint', 'selectClass' => ''])
                                        @error('ai_model')<div class="field-error">{{ $message }}</div>@enderror
                                    </div>
                                    <p class="hint span-2" data-ai-local-help hidden>Alamat diakses dari server aplikasi. Pastikan server LLM berjalan dan model sudah dimuat; gunakan IP/hostname jika berada di komputer lain.</p>
                                </div>
                            </div>

                            <div class="field span-2">
                                <div class="toggle-box">
                                    <input type="hidden" name="smtp_enabled" value="0">
                                    <input id="smtp_enabled" name="smtp_enabled" type="checkbox" value="1" @checked((bool) old('smtp_enabled', false)) data-toggle="smtp-fields">
                                    <div>
                                        <label for="smtp_enabled">Aktifkan SMTP</label>
                                        <p>Digunakan untuk email aplikasi. SMTP tanpa autentikasi tetap didukung.</p>
                                    </div>
                                </div>
                                <div class="optional-fields grid" id="smtp-fields" @if(! old('smtp_enabled', false)) hidden @endif>
                                    <div class="field">
                                        <label for="mail_host">Host SMTP</label>
                                        <input id="mail_host" name="mail_host" value="{{ old('mail_host') }}" maxlength="255" data-conditional-required>
                                        @error('mail_host')<div class="field-error">{{ $message }}</div>@enderror
                                    </div>
                                    <div class="field">
                                        <label for="mail_port">Port</label>
                                        <input id="mail_port" name="mail_port" type="number" min="1" max="65535" value="{{ old('mail_port', $defaults['mail_port']) }}" data-conditional-required>
                                        @error('mail_port')<div class="field-error">{{ $message }}</div>@enderror
                                    </div>
                                    <div class="field">
                                        <label for="mail_username">Pengguna SMTP</label>
                                        <input id="mail_username" name="mail_username" value="{{ old('mail_username') }}" maxlength="255" autocomplete="off">
                                        @error('mail_username')<div class="field-error">{{ $message }}</div>@enderror
                                    </div>
                                    <div class="field">
                                        <label for="mail_password">Kata sandi SMTP</label>
                                        <input id="mail_password" name="mail_password" type="password" maxlength="4096" autocomplete="new-password">
                                        @error('mail_password')<div class="field-error">{{ $message }}</div>@enderror
                                    </div>
                                    <div class="field">
                                        <label for="mail_encryption">Enkripsi transport</label>
                                        <select id="mail_encryption" name="mail_encryption">
                                            <option value="tls" @selected(old('mail_encryption', 'tls') === 'tls')>STARTTLS / TLS</option>
                                            <option value="ssl" @selected(old('mail_encryption') === 'ssl')>SSL</option>
                                            <option value="none" @selected(old('mail_encryption') === 'none')>Tanpa enkripsi</option>
                                        </select>
                                        @error('mail_encryption')<div class="field-error">{{ $message }}</div>@enderror
                                    </div>
                                    <div class="field">
                                        <label for="mail_from_address">Email pengirim</label>
                                        <input id="mail_from_address" name="mail_from_address" type="email" value="{{ old('mail_from_address') }}" maxlength="255" data-conditional-required>
                                        @error('mail_from_address')<div class="field-error">{{ $message }}</div>@enderror
                                    </div>
                                    <div class="field span-2">
                                        <label for="mail_from_name">Nama pengirim</label>
                                        <input id="mail_from_name" name="mail_from_name" value="{{ old('mail_from_name', old('app_name', $defaults['app_name'])) }}" maxlength="255" data-conditional-required>
                                        @error('mail_from_name')<div class="field-error">{{ $message }}</div>@enderror
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="step-panel" data-step-panel="5" hidden>
                        <p class="eyebrow">Tahap 6 dari 6</p>
                        <h2>Akun administrator</h2>
                        <p class="step-description">Akun pertama mendapat akses penuh ke pengaturan, pengguna, dan pembaruan aplikasi.</p>
                        <div class="grid">
                            <div class="field">
                                <label for="admin_name">Nama</label>
                                <input id="admin_name" name="admin_name" value="{{ old('admin_name') }}" required maxlength="255" autocomplete="name">
                                @error('admin_name')<div class="field-error">{{ $message }}</div>@enderror
                            </div>
                            <div class="field">
                                <label for="admin_email">Email</label>
                                <input id="admin_email" name="admin_email" type="email" value="{{ old('admin_email') }}" required maxlength="255" autocomplete="email">
                                @error('admin_email')<div class="field-error">{{ $message }}</div>@enderror
                            </div>
                            <div class="field">
                                <label for="admin_password">Kata sandi</label>
                                <input id="admin_password" name="admin_password" type="password" required minlength="12" maxlength="72" autocomplete="new-password">
                                @error('admin_password')<div class="field-error">{{ $message }}</div>@enderror
                                <span class="hint">Minimal 12 karakter, huruf besar/kecil, angka, dan simbol.</span>
                            </div>
                            <div class="field">
                                <label for="admin_password_confirmation">Konfirmasi kata sandi</label>
                                <input id="admin_password_confirmation" name="admin_password_confirmation" type="password" required minlength="12" maxlength="72" autocomplete="new-password">
                            </div>
                            <div class="review span-2">
                                <h3>Saat Anda menekan Instal Aplikasi</h3>
                                <ul>
                                    <li>Koneksi database diverifikasi dan migrasi dijalankan.</li>
                                    <li>Konfigurasi ditulis secara atomik tanpa menampilkan credential.</li>
                                    <li>Akun admin dibuat dan token instalasi dinonaktifkan permanen.</li>
                                </ul>
                            </div>
                        </div>
                    </section>

                    <div class="actions">
                        <button class="button button-secondary" type="button" data-previous hidden>Kembali</button>
                        <span data-action-spacer></span>
                        <button class="button button-primary" type="button" data-next>Berikutnya</button>
                        <button class="button button-primary" type="submit" data-submit hidden>Instal aplikasi</button>
                    </div>
                </form>
            </section>
        @endunless
    </main>

    @if($unlocked)
        <script>
            (() => {
                'use strict';

                const form = document.querySelector('[data-installer-form]');
                const panels = [...document.querySelectorAll('[data-step-panel]')];
                const links = [...document.querySelectorAll('[data-step-link]')];
                const previous = document.querySelector('[data-previous]');
                const next = document.querySelector('[data-next]');
                const submit = document.querySelector('[data-submit]');
                const spacer = document.querySelector('[data-action-spacer]');
                const progress = document.querySelector('[data-progress]');
                let current = Math.max(0, panels.findIndex(panel => panel.querySelector('.field-error')));

                function showStep(index) {
                    current = Math.max(0, Math.min(index, panels.length - 1));
                    panels.forEach((panel, panelIndex) => panel.hidden = panelIndex !== current);
                    links.forEach((link, linkIndex) => {
                        if (linkIndex === current) link.setAttribute('aria-current', 'step');
                        else link.removeAttribute('aria-current');
                    });
                    previous.hidden = current === 0;
                    spacer.hidden = current !== 0;
                    next.hidden = current === panels.length - 1;
                    submit.hidden = current !== panels.length - 1;
                    progress.style.width = `${((current + 1) / panels.length) * 100}%`;
                    panels[current].querySelector('input:not([type="hidden"]), select')?.focus({ preventScroll: true });
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                }

                function validateCurrentStep() {
                    const controls = [...panels[current].querySelectorAll('input, select')];
                    for (const control of controls) {
                        if (!control.checkValidity()) {
                            control.reportValidity();
                            control.focus();
                            return false;
                        }
                    }
                    return true;
                }

                links.forEach(link => link.addEventListener('click', () => {
                    const target = Number(link.dataset.stepLink);
                    if (target <= current || validateCurrentStep()) showStep(target);
                }));
                previous.addEventListener('click', () => showStep(current - 1));
                next.addEventListener('click', () => {
                    if (validateCurrentStep()) showStep(current + 1);
                });

                document.querySelectorAll('[data-toggle]').forEach(toggle => {
                    const target = document.getElementById(toggle.dataset.toggle);
                    const sync = () => {
                        target.hidden = !toggle.checked;
                        if (target.tagName === 'FIELDSET') target.disabled = !toggle.checked;
                        else target.querySelectorAll('input, select, button').forEach(control => control.disabled = !toggle.checked);
                        target.querySelectorAll('[data-conditional-required]').forEach(input => input.required = toggle.checked);
                    };
                    toggle.addEventListener('change', sync);
                    sync();
                });

                document.querySelectorAll('[data-skip-integration]').forEach(button => button.addEventListener('click', () => {
                    const toggle = document.getElementById(button.dataset.skipIntegration);
                    toggle.checked = false;
                    toggle.dispatchEvent(new Event('change'));
                    showStep(current + 1);
                }));

                const driver = document.querySelector('[data-db-driver]');
                const port = document.querySelector('[data-db-port]');
                let previousDefaultPort = driver.value === 'pgsql' ? '5432' : '3306';
                driver.addEventListener('change', () => {
                    const nextDefaultPort = driver.value === 'pgsql' ? '5432' : '3306';
                    if (!port.value || port.value === previousDefaultPort) port.value = nextDefaultPort;
                    previousDefaultPort = nextDefaultPort;
                });

                const chatList = document.querySelector('[data-chat-list]');
                document.querySelector('[data-add-chat]').addEventListener('click', () => {
                    if (chatList.children.length >= 20) return;
                    const row = document.createElement('div');
                    row.className = 'chat-row';
                    row.innerHTML = '<div><input name="telegram_chat_ids[]" required maxlength="100" placeholder="-1001234567890 atau @nama_channel" aria-label="ID chat Telegram"></div><button class="icon-button" type="button" data-remove-chat aria-label="Hapus tujuan chat">&times;</button>';
                    chatList.appendChild(row);
                    row.querySelector('input').focus();
                });
                chatList.addEventListener('click', event => {
                    const button = event.target.closest('[data-remove-chat]');
                    if (!button || chatList.children.length === 1) return;
                    button.closest('.chat-row').remove();
                });

                form.addEventListener('submit', event => {
                    if (!validateCurrentStep()) {
                        event.preventDefault();
                        return;
                    }
                    submit.disabled = true;
                    submit.textContent = 'Memasang...';
                });

                showStep(current);
            })();
        </script>
        @include('partials.ai-provider-fields-script')
    @endif
</body>
</html>
