<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>Instalasi &mdash; SIEM Salabim</title>
    <style>
        :root {
            color-scheme: dark;
            --bg: #07111f;
            --panel: #0d1b2d;
            --panel-2: #11233a;
            --line: #263b55;
            --text: #e7eef8;
            --muted: #9eb0c6;
            --primary: #38bdf8;
            --primary-strong: #0ea5e9;
            --success: #34d399;
            --danger: #fb7185;
            --warning: #fbbf24;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            background:
                radial-gradient(circle at 10% 0%, rgba(14, 165, 233, .16), transparent 34rem),
                radial-gradient(circle at 100% 100%, rgba(52, 211, 153, .08), transparent 32rem),
                var(--bg);
            color: var(--text);
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            line-height: 1.55;
        }
        button, input, select { font: inherit; }
        button { cursor: pointer; }
        .shell { width: min(1120px, calc(100% - 32px)); margin: 0 auto; padding: 40px 0 64px; }
        .masthead { display: flex; align-items: center; gap: 14px; margin-bottom: 26px; }
        .mark {
            display: grid; place-items: center; width: 46px; height: 46px; border-radius: 14px;
            background: linear-gradient(145deg, var(--primary), #2563eb); color: #03111e; font-weight: 900;
            box-shadow: 0 12px 30px rgba(14, 165, 233, .25);
        }
        .masthead h1 { margin: 0; font-size: 1.08rem; letter-spacing: .01em; }
        .masthead p { margin: 2px 0 0; color: var(--muted); font-size: .88rem; }
        .card {
            border: 1px solid var(--line); border-radius: 22px; background: rgba(13, 27, 45, .96);
            box-shadow: 0 28px 80px rgba(0, 0, 0, .32); overflow: hidden;
        }
        .gate { max-width: 600px; margin: 8vh auto 0; padding: clamp(24px, 5vw, 48px); }
        .gate-icon {
            display: grid; place-items: center; width: 54px; height: 54px; border-radius: 16px;
            color: var(--primary); background: rgba(56, 189, 248, .1); border: 1px solid rgba(56, 189, 248, .24);
            font-size: 1.5rem;
        }
        h2 { margin: 18px 0 7px; font-size: clamp(1.45rem, 4vw, 2rem); }
        .lead { margin: 0 0 24px; color: var(--muted); }
        .security-note {
            margin-top: 20px; padding: 13px 15px; border-radius: 12px; color: #bfd0e3;
            border: 1px solid #24415e; background: #0a1828; font-size: .86rem;
        }
        .wizard { display: grid; grid-template-columns: 245px minmax(0, 1fr); min-height: 670px; }
        .sidebar { padding: 28px 20px; background: #091726; border-right: 1px solid var(--line); }
        .sidebar h2 { margin: 0 8px 8px; font-size: 1.14rem; }
        .sidebar > p { margin: 0 8px 24px; color: var(--muted); font-size: .84rem; }
        .progress { height: 5px; margin: 0 8px 22px; border-radius: 99px; background: #1c3047; overflow: hidden; }
        .progress span { display: block; height: 100%; width: 16.666%; border-radius: inherit; background: var(--primary); transition: width .2s ease; }
        .steps { display: grid; gap: 7px; }
        .step-link {
            display: flex; gap: 11px; align-items: center; width: 100%; padding: 10px 11px; border: 0;
            border-radius: 11px; text-align: left; color: var(--muted); background: transparent;
        }
        .step-link[aria-current="step"] { color: var(--text); background: #14283f; }
        .step-number {
            flex: 0 0 auto; display: grid; place-items: center; width: 28px; height: 28px; border-radius: 50%;
            border: 1px solid #38506b; font-size: .78rem; font-weight: 800;
        }
        .step-link[aria-current="step"] .step-number { color: #052033; background: var(--primary); border-color: var(--primary); }
        .content { min-width: 0; padding: clamp(24px, 5vw, 48px); }
        .eyebrow { margin: 0 0 5px; color: var(--primary); font-size: .75rem; font-weight: 800; letter-spacing: .13em; text-transform: uppercase; }
        .step-panel > h2 { margin: 0 0 6px; }
        .step-description { margin: 0 0 27px; color: var(--muted); }
        .grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px; }
        .span-2 { grid-column: span 2; }
        .field { min-width: 0; }
        label { display: block; margin-bottom: 7px; font-size: .86rem; font-weight: 720; }
        .hint { display: block; margin-top: 6px; color: var(--muted); font-size: .75rem; }
        input, select {
            width: 100%; min-height: 45px; padding: 10px 12px; color: var(--text); background: #091725;
            border: 1px solid #304762; border-radius: 10px; outline: none;
        }
        input:focus, select:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(56, 189, 248, .13); }
        input::placeholder { color: #627892; }
        .field-error { margin-top: 6px; color: #fda4af; font-size: .78rem; }
        .alert {
            margin-bottom: 22px; padding: 13px 15px; border-radius: 12px; border: 1px solid rgba(251, 113, 133, .45);
            background: rgba(159, 18, 57, .16); color: #fecdd3; font-size: .88rem;
        }
        .toggle-box {
            display: flex; gap: 13px; align-items: flex-start; padding: 15px; border: 1px solid var(--line);
            background: #0a1929; border-radius: 12px;
        }
        .toggle-box input { flex: 0 0 auto; width: 19px; min-height: 19px; height: 19px; margin-top: 2px; accent-color: var(--primary); }
        .toggle-box label { margin: 0; }
        .toggle-box p { margin: 3px 0 0; color: var(--muted); font-size: .78rem; }
        .optional-fields { margin-top: 18px; }
        .chat-list { display: grid; gap: 10px; }
        .chat-row { display: grid; grid-template-columns: 1fr auto; gap: 8px; }
        .icon-button {
            min-width: 44px; border-radius: 10px; border: 1px solid #4d3547; color: #fda4af; background: #221725;
        }
        .add-button { margin-top: 10px; padding: 8px 12px; color: var(--primary); border: 1px solid #275172; border-radius: 9px; background: #0a1d2f; }
        .review {
            padding: 16px; border: 1px solid #2a4560; border-radius: 13px; background: #0a1929;
        }
        .review h3 { margin: 0 0 8px; font-size: .94rem; }
        .review ul { margin: 0; padding-left: 20px; color: var(--muted); font-size: .83rem; }
        .actions { display: flex; justify-content: space-between; gap: 12px; margin-top: 34px; padding-top: 20px; border-top: 1px solid var(--line); }
        .button { min-height: 44px; padding: 10px 18px; border-radius: 10px; border: 1px solid transparent; font-weight: 800; }
        .button-primary { color: #032035; background: var(--primary); }
        .button-primary:hover { background: #7dd3fc; }
        .button-secondary { color: var(--text); border-color: #38506b; background: #14253a; }
        .button:disabled { cursor: not-allowed; opacity: .6; }
        [hidden] { display: none !important; }
        .noscript { margin-bottom: 20px; padding: 12px; border-radius: 10px; color: #fde68a; background: #3a2b0d; }
        @media (max-width: 800px) {
            .shell { width: min(100% - 20px, 680px); padding-top: 22px; }
            .wizard { grid-template-columns: 1fr; }
            .sidebar { border-right: 0; border-bottom: 1px solid var(--line); }
            .sidebar h2, .sidebar > p { display: none; }
            .steps { grid-template-columns: repeat(6, 1fr); }
            .step-link { justify-content: center; padding: 7px; }
            .step-label { display: none; }
            .grid { grid-template-columns: 1fr; }
            .span-2 { grid-column: span 1; }
        }
        @media (max-width: 480px) {
            .content { padding: 22px 17px; }
            .gate { padding: 25px 20px; }
            .actions { flex-wrap: wrap; }
            .actions .button { flex: 1; }
        }
    </style>
</head>
<body>
    <main class="shell">
        <header class="masthead">
            <div class="mark" aria-hidden="true">S</div>
            <div>
                <h1>SIEM Salabim</h1>
                <p>Installer aman untuk konfigurasi awal</p>
            </div>
        </header>

        @unless($unlocked)
            <section class="card gate" aria-labelledby="gate-title">
                <div class="gate-icon" aria-hidden="true">&#128274;</div>
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
                    <p>Lengkapi enam tahap berikut.</p>
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
                        <p class="step-description">Sumber alert utama. Opsional saat instalasi dan dapat dikonfigurasi nanti melalui Settings.</p>
                        <div class="toggle-box">
                            <input type="hidden" name="opensearch_enabled" value="0">
                            <input id="opensearch_enabled" name="opensearch_enabled" type="checkbox" value="1" @checked((bool) old('opensearch_enabled', false)) data-toggle="opensearch-fields">
                            <div>
                                <label for="opensearch_enabled">Aktifkan OpenSearch</label>
                                <p>Aktifkan untuk mengisi konfigurasi dan menguji koneksi saat instalasi.</p>
                            </div>
                        </div>
                        @error('opensearch_enabled')<div class="field-error">{{ $message }}</div>@enderror
                        <div class="optional-fields grid" id="opensearch-fields" @if(! old('opensearch_enabled', false)) hidden @endif>
                            <div class="field span-2">
                                <label for="opensearch_host">Endpoint OpenSearch</label>
                                <input id="opensearch_host" name="opensearch_host" type="url" value="{{ old('opensearch_host', $defaults['opensearch_host']) }}" data-conditional-required maxlength="2048">
                                @error('opensearch_host')<div class="field-error">{{ $message }}</div>@enderror
                            </div>
                            <div class="field">
                                <label for="opensearch_username">Pengguna</label>
                                <input id="opensearch_username" name="opensearch_username" value="{{ old('opensearch_username') }}" data-conditional-required maxlength="255" autocomplete="off">
                                @error('opensearch_username')<div class="field-error">{{ $message }}</div>@enderror
                            </div>
                            <div class="field">
                                <label for="opensearch_password">Kata sandi</label>
                                <input id="opensearch_password" name="opensearch_password" type="password" data-conditional-required maxlength="4096" autocomplete="new-password">
                                @error('opensearch_password')<div class="field-error">{{ $message }}</div>@enderror
                            </div>
                            <div class="field">
                                <label for="opensearch_index">Pola indeks alert</label>
                                <input id="opensearch_index" name="opensearch_index" value="{{ old('opensearch_index', $defaults['opensearch_index']) }}" data-conditional-required maxlength="255">
                                @error('opensearch_index')<div class="field-error">{{ $message }}</div>@enderror
                            </div>
                            <div class="field">
                                <label for="opensearch_source_cluster">Identitas sumber</label>
                                <input id="opensearch_source_cluster" name="opensearch_source_cluster" value="{{ old('opensearch_source_cluster', $defaults['opensearch_source_cluster']) }}" data-conditional-required maxlength="64" pattern="[A-Za-z0-9_.-]+">
                                @error('opensearch_source_cluster')<div class="field-error">{{ $message }}</div>@enderror
                                <span class="hint">Nama stabil untuk deduplikasi; jangan mengikuti hostname.</span>
                            </div>
                            <div class="field span-2">
                                <div class="toggle-box">
                                    <input type="hidden" name="opensearch_verify_ssl" value="0">
                                    <input id="opensearch_verify_ssl" name="opensearch_verify_ssl" type="checkbox" value="1" @checked((bool) old('opensearch_verify_ssl', true))>
                                    <div>
                                        <label for="opensearch_verify_ssl">Verifikasi sertifikat TLS</label>
                                        <p>Direkomendasikan. Nonaktifkan hanya sementara pada lingkungan terisolasi dengan sertifikat internal.</p>
                                    </div>
                                </div>
                                @error('opensearch_verify_ssl')<div class="field-error">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    </section>

                    <section class="step-panel" data-step-panel="3" hidden>
                        <p class="eyebrow">Tahap 4 dari 6</p>
                        <h2>Notifikasi Telegram</h2>
                        <p class="step-description">Notifikasi Telegram bersifat opsional dan dapat dikonfigurasi nanti melalui Settings.</p>
                        <div class="toggle-box">
                            <input type="hidden" name="telegram_enabled" value="0">
                            <input id="telegram_enabled" name="telegram_enabled" type="checkbox" value="1" @checked((bool) old('telegram_enabled', false)) data-toggle="telegram-fields">
                            <div>
                                <label for="telegram_enabled">Aktifkan Telegram</label>
                                <p>Aktifkan untuk mengisi konfigurasi dan menguji koneksi saat instalasi.</p>
                            </div>
                        </div>
                        @error('telegram_enabled')<div class="field-error">{{ $message }}</div>@enderror
                        <div class="optional-fields grid" id="telegram-fields" @if(! old('telegram_enabled', false)) hidden @endif>
                            <div class="field span-2">
                                <label for="telegram_bot_token">Token bot</label>
                                <input id="telegram_bot_token" name="telegram_bot_token" type="password" data-conditional-required maxlength="4096" autocomplete="new-password">
                                @error('telegram_bot_token')<div class="field-error">{{ $message }}</div>@enderror
                            </div>
                            <div class="field span-2">
                                <label>Tujuan chat</label>
                                <div class="chat-list" data-chat-list>
                                    @foreach(((array) old('telegram_chat_ids') ?: ['']) as $index => $chatId)
                                        <div class="chat-row">
                                            <div>
                                                <input name="telegram_chat_ids[]" value="{{ $chatId }}" data-conditional-required maxlength="100" placeholder="-1001234567890 atau @nama_channel" aria-label="ID chat Telegram {{ $index + 1 }}">
                                                @error('telegram_chat_ids.'.$index)<div class="field-error">{{ $message }}</div>@enderror
                                            </div>
                                            <button class="icon-button" type="button" data-remove-chat aria-label="Hapus tujuan chat">&times;</button>
                                        </div>
                                    @endforeach
                                </div>
                                @error('telegram_chat_ids')<div class="field-error">{{ $message }}</div>@enderror
                                <button class="add-button" type="button" data-add-chat>+ Tambah tujuan</button>
                            </div>
                        </div>
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
                                <div class="optional-fields grid" id="ai-fields" @if(! old('ai_enabled', false)) hidden @endif>
                                    <div class="field span-2">
                                        <label for="ai_api_key">API key AI</label>
                                        <input id="ai_api_key" name="ai_api_key" type="password" maxlength="4096" autocomplete="new-password" data-conditional-required>
                                        @error('ai_api_key')<div class="field-error">{{ $message }}</div>@enderror
                                    </div>
                                    <div class="field">
                                        <label for="ai_base_url">Endpoint AI</label>
                                        <input id="ai_base_url" name="ai_base_url" type="url" value="{{ old('ai_base_url', $defaults['ai_base_url']) }}" maxlength="2048" data-conditional-required>
                                        @error('ai_base_url')<div class="field-error">{{ $message }}</div>@enderror
                                    </div>
                                    <div class="field">
                                        <label for="ai_model">Model</label>
                                        <input id="ai_model" name="ai_model" value="{{ old('ai_model', $defaults['ai_model']) }}" maxlength="255" data-conditional-required>
                                        @error('ai_model')<div class="field-error">{{ $message }}</div>@enderror
                                    </div>
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
                        target.querySelectorAll('input, select, button').forEach(control => control.disabled = !toggle.checked);
                        target.querySelectorAll('[data-conditional-required]').forEach(input => input.required = toggle.checked);
                    };
                    toggle.addEventListener('change', sync);
                    sync();
                });

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
                    row.innerHTML = '<div><input name="telegram_chat_ids[]" required data-conditional-required maxlength="100" placeholder="-1001234567890 atau @nama_channel" aria-label="ID chat Telegram"></div><button class="icon-button" type="button" data-remove-chat aria-label="Hapus tujuan chat">&times;</button>';
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
    @endif
</body>
</html>
