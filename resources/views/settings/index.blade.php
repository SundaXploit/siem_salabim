@extends('layouts.app')
@section('body-class', 'density-comfortable settings-workspace')

@section('title', 'Settings')
@section('page-context', 'Administration')
@section('page-title', 'Settings')
@section('page-subtitle', 'Konfigurasi sistem — Admin Only')

@section('content')

@php
    $currentLevel = (int) old('min_alert_level', $configs['min_alert_level'] ?? 12);

    $levelOptions = [
        3  => ['label' => '≥ 3',  'desc' => 'Low'],
        5  => ['label' => '≥ 5',  'desc' => 'Medium-Low'],
        7  => ['label' => '≥ 7',  'desc' => 'Medium'],
        10 => ['label' => '≥ 10', 'desc' => 'Medium-High'],
        12 => ['label' => '≥ 12', 'desc' => 'High (Default)'],
        13 => ['label' => '≥ 13', 'desc' => 'High+'],
        14 => ['label' => '≥ 14', 'desc' => 'Very High'],
        15 => ['label' => '≥ 15', 'desc' => 'Critical Only'],
    ];

@endphp


@if ($errors->any() && !old('_user_form'))
    <div class="alert-msg error" role="alert">
        <i data-lucide="circle-alert" aria-hidden="true"></i>
        <div>
            <strong>Konfigurasi belum disimpan.</strong>
            <ul class="form-error">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    </div>
@endif

    <div class="settings-directory" aria-label="Daftar pengaturan">
        <div class="settings-directory-heading">
            <span>Pengaturan sistem</span>
            <span class="text-muted text-sm">{{ count($settingsSections) }} bagian</span>
        </div>
        @foreach($settingsSections as $key => $section)
            <details class="settings-directory-section" data-settings-section="{{ $key }}" @if($activeSettingsSection === $key) open @endif>
            <summary class="settings-directory-row">
                <div class="settings-directory-icon"><i data-lucide="{{ $section['icon'] }}" aria-hidden="true"></i></div>
                <div class="settings-directory-copy">
                    <h2>{{ $section['title'] }}</h2>
                    <p>{{ $section['description'] }}</p>
                </div>
                <i data-lucide="chevron-right" class="settings-directory-chevron" aria-hidden="true"></i>
            </summary>

<div class="settings-grid settings-page" x-data="settingsPage()">

    {{-- ─── OpenSearch Config ─────────────────────────────── --}}
    @if($key === 'opensearch')
    <div class="card settings-opensearch-card">
        <div class="card-header">
            <span class="card-title">Konfigurasi OpenSearch &amp; Alert</span>
            <button type="button" @click="testOpenSearch()" class="btn btn-ghost btn-sm ml-auto" :disabled="testing">
                <span x-show="!testing">Test Koneksi</span>
                <span x-show="testing">Testing...</span>
            </button>
        </div>
        <div class="card-body">
            <div x-show="osResult" class="alert-msg" :class="osResult?.success ? 'success' : 'error'" style="margin-bottom:var(--spacing-16);" x-cloak>
                <span x-text="osResult?.success ? 'Akses indeks terverifikasi: ' + osResult.index + ' (' + osResult.count + ' dokumen dapat dibaca)' : osResult?.error"></span>
            </div>
            <form method="POST" action="{{ route('settings.opensearch') }}" x-ref="openSearchForm">
                @csrf
                <input type="hidden" name="_section" value="{{ $key }}">
                <div class="form-group">
                    <label class="form-label">Host URL</label>
                    <input aria-label="Host URL" type="url" name="opensearch_host" class="form-input" value="{{ old('opensearch_host', $configs['opensearch_host'] ?? 'https://localhost:9200') }}" placeholder="https://wazuh-indexer.example:9200" required>
                    <div class="text-muted text-sm" style="margin-top:var(--spacing-4);">Gunakan URL Wazuh Indexer (umumnya port <code>9200</code>), bukan Dashboard atau Wazuh API.</div>
                </div>
                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label">Username</label>
                        <input aria-label="Username" type="text" name="opensearch_username" class="form-input" value="{{ old('opensearch_username', $configs['opensearch_username'] ?? 'admin') }}" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Password</label>
                        <input aria-label="Password" type="password" name="opensearch_password" class="form-input" placeholder="Tersimpan" autocomplete="new-password">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Index Pattern</label>
                    <input aria-label="Index Pattern" type="text" name="opensearch_index" class="form-input" value="{{ old('opensearch_index', $configs['opensearch_index'] ?? 'wazuh-alerts-*') }}" placeholder="wazuh-alerts-*" required>
                </div>

                <div class="form-group">
                    <label class="form-label" for="alert-min-alert-level">Ambang impor rule level alert</label>
                    <select id="alert-min-alert-level" name="min_alert_level" class="form-select" required>
                        @foreach($levelOptions as $lvl => $opt)
                            <option value="{{ $lvl }}" @selected($currentLevel === $lvl)>{{ $opt['label'] }} — {{ $opt['desc'] }}</option>
                        @endforeach
                    </select>
                    <p class="settings-field-help">Hanya alert Wazuh pada level ini atau lebih tinggi yang diimpor.</p>
                </div>

                <button type="submit" class="btn settings-submit w-full">Simpan konfigurasi OpenSearch &amp; Alert</button>
            </form>
            <div class="settings-manual-actions">
                <h3 class="settings-subheading">Fetch alert manual</h3>
                <p class="text-muted text-sm" style="margin-bottom:var(--spacing-12);">Ambil alert hari ini mulai pukul 00:00 WIB (Asia/Jakarta), dengan level ≥ {{ $configs['min_alert_level'] ?? 12 }} sesuai konfigurasi yang sudah disimpan. Seluruh halaman hasil hari ini diperiksa dan alert yang sudah tersimpan dilewati.</p>
                <form method="POST" action="{{ route('settings.fetch-now') }}" x-data="{ fetching: false }" @submit="fetching = true">
                    @csrf
                    <input type="hidden" name="_section" value="{{ $key }}">
                    <button type="submit" class="btn settings-submit settings-submit-inline" :disabled="fetching"><i data-lucide="play" aria-hidden="true"></i> <span x-text="fetching ? 'Mengambil alert…' : 'Fetch alert hari ini'">Fetch alert hari ini</span></button>
                </form>
            </div>
        </div>
    </div>

    {{-- Seluruh endpoint pada panel ini juga memverifikasi role admin di controller. --}}
    @endif
    @if($key === 'permanent-ignore')
        @include('settings.partials.permanent-ignore')
    @endif
    @if($key === 'ai')
    <div class="card settings-ai-card" x-data="aiSettingsForm()">
        <div class="card-header">
            <div>
                <span class="card-title">Konfigurasi Analisis AI</span>
                <p class="settings-card-caption">Atur provider cloud atau LLM lokal untuk analisis dashboard dan detail alert.</p>
            </div>
            <button type="button" @click="testAi()" class="btn btn-ghost btn-sm ml-auto" :disabled="testing">
                <span x-show="!testing">Test Koneksi</span>
                <span x-show="testing">Testing...</span>
            </button>
        </div>
        <div class="card-body">
            <div x-show="testResult" class="alert-msg" :class="testResult?.success ? 'success' : 'error'" style="margin-bottom:var(--spacing-16);" x-cloak>
                <span x-text="testResult?.success ? 'Koneksi AI terverifikasi dengan model ' + testResult.model + '.' : testResult?.error"></span>
            </div>

            <form method="POST" action="{{ route('settings.ai') }}" x-ref="aiForm" data-ai-fields data-ai-models-url="{{ route('settings.ai.models', [], false) }}"
                data-ai-saved-provider="{{ $configs['ai_provider'] }}" data-ai-saved-url="{{ $configs['ai_base_url'] }}"
                data-ai-key-available="{{ $aiKeyAvailable ? '1' : '0' }}">
                @csrf
                <input type="hidden" name="_section" value="{{ $key }}">
                <div class="settings-ai-fields">
                    <div class="form-group">
                        <label class="form-label" for="ai-provider">Provider AI</label>
                        <select id="ai-provider" name="ai_provider" class="form-select" required>
                            @foreach($aiProviders as $value => $provider)
                                <option value="{{ $value }}" @selected(old('ai_provider', $configs['ai_provider']) === $value)>{{ $provider['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="ai-api-format">Format API</label>
                        <select id="ai-api-format" name="ai_api_format" class="form-select" required>
                            @foreach(['chat_completions' => 'Chat Completions', 'responses' => 'Responses', 'anthropic_messages' => 'Anthropic Messages'] as $value => $label)
                                <option value="{{ $value }}" @selected(old('ai_api_format', $configs['ai_api_format']) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group span-2">
                        <label class="form-label" for="ai-base-url">Base URL API</label>
                        <input id="ai-base-url" type="url" name="ai_base_url" class="form-input" value="{{ old('ai_base_url', $configs['ai_base_url']) }}" placeholder="Pilih provider atau isi base URL API" required>
                        <p class="settings-field-help">Gunakan base URL tanpa akhiran <code>/responses</code>, <code>/chat/completions</code>, atau <code>/messages</code>. Cloud memakai HTTPS; provider lokal boleh HTTP.</p>
                        <p class="settings-field-help" data-ai-local-help hidden>Alamat lokal diakses dari server aplikasi. Gunakan IP/hostname server LLM jika berada di komputer atau container lain, lalu pastikan model sudah dimuat.</p>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="ai-model">Model AI</label>
                        <input id="ai-model" type="text" name="ai_model" class="form-input" value="{{ old('ai_model', $configs['ai_model']) }}" placeholder="Nama model di provider atau server lokal" required>
                        @include('partials.ai-model-discovery', ['buttonClass' => 'btn btn-secondary btn-sm', 'helpClass' => 'settings-field-help', 'selectClass' => 'form-select'])
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="ai-api-key">API key</label>
                        <input id="ai-api-key" type="password" name="ai_api_key" class="form-input" placeholder="API key cloud; opsional untuk lokal" autocomplete="new-password">
                        <p class="settings-field-help">Token disimpan terenkripsi. Token lama dipertahankan hanya untuk provider dan endpoint yang sama. LLM lokal boleh tanpa token.</p>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="ai-snapshot-limit">Cakupan data</label>
                        <input id="ai-snapshot-limit" type="number" name="ai_snapshot_limit" class="form-input" min="1000" max="10000" step="500" value="{{ old('ai_snapshot_limit', $configs['ai_snapshot_limit'] ?? 10000) }}" required>
                        <p class="settings-field-help">Maksimum alert terbaru per bulan yang disampel untuk ekstraksi pola (1.000–10.000).</p>
                    </div>



                    <div class="form-group span-2">
                        <label class="form-label" for="ai-refresh-days">Frekuensi otomatis</label>
                        <select id="ai-refresh-days" name="ai_refresh_days" class="form-select" required>
                            @foreach([1 => 'Setiap hari', 3 => 'Setiap 3 hari', 7 => 'Setiap 7 hari', 10 => 'Setiap 10 hari', 14 => 'Setiap 14 hari', 30 => 'Setiap 30 hari'] as $days => $label)
                                <option value="{{ $days }}" @selected((int) old('ai_refresh_days', $configs['ai_refresh_days'] ?? 10) === $days)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <p class="settings-field-help">Scheduler memeriksa setiap hari dan membuat analisis baru setelah interval ini tercapai.</p>
                    </div>
                </div>

                <button type="submit" class="btn settings-submit w-full">Simpan konfigurasi Analisis AI</button>
            </form>
        </div>
    </div>

    {{-- ─── Telegram Config ───────────────────────────────── --}}
    @endif
    @if($key === 'telegram')
    <div class="card settings-telegram-card" x-data="telegramForm()">
        <div class="card-header">
            <div>
                <span class="card-title">Konfigurasi Telegram Bot</span>
                <p class="settings-card-caption">Periksa token bot dan uji pengiriman ke chat tujuan.</p>
            </div>
            <button type="button" class="btn btn-secondary btn-sm ml-auto" @click="testBot()" :disabled="testing"
                :aria-busy="testing && testingChatIndex === null" data-telegram-test-bot>
                <span x-text="testing && testingChatIndex === null ? 'Menguji bot...' : 'Test koneksi bot'"></span>
            </button>
        </div>
        <div class="card-body">
            <form method="POST" action="{{ route('settings.telegram') }}" x-ref="telegramForm">
                @csrf
                <input type="hidden" name="_section" value="{{ $key }}">
                <div class="form-group">
                    <label class="form-label">Bot Token</label>
                    <input aria-label="Bot Token" type="password" name="telegram_bot_token" class="form-input" placeholder="Tersimpan" autocomplete="new-password" :readonly="testing" @input="testResult = null">
                    <div class="text-muted text-sm" style="margin-top:var(--spacing-4);">Dapatkan dari @BotFather di Telegram. Test koneksi bot memakai token di formulir, atau token tersimpan jika kosong. Tes ini tidak mengirim pesan dan tidak menyimpan perubahan.</div>
                </div>

                <input type="hidden" name="update_telegram_destinations" value="1">
                <div class="form-group">
                    <div class="settings-telegram-heading">
                        <h3 class="settings-subheading">Manajemen tujuan Telegram</h3>
                        <button type="button" @click="addDestination()" :disabled="testing || destinations.length >= 100" class="settings-text-action" data-telegram-add-destination><i data-lucide="plus" aria-hidden="true"></i> Tambah tujuan</button>
                    </div>
                    <p class="settings-field-help">Setiap tujuan hanya menerima alert dari agent yang dipilih. Satu agent dapat dikirim ke beberapa tujuan.</p>
                    <div class="settings-agent-inventory" aria-live="polite">
                        <div>
                            <strong x-text="agentsLoading ? 'Memuat daftar agent...' : (agentsLoaded ? availableAgents.length + ' agent tersedia' : 'Daftar agent belum dimuat')" data-telegram-agent-count></strong>
                            <span x-show="agentsLoaded && updatedAt" x-text="'Monitoring terakhir: ' + snapshotTime()" class="settings-agent-updated"></span>
                            <p class="settings-field-help">Otomatis dari monitoring Wazuh melalui koneksi OpenSearch tersimpan.</p>
                        </div>
                        <button type="button" class="btn btn-secondary btn-sm" @click="loadAgents()" :disabled="agentsLoading" data-telegram-refresh-agents><i data-lucide="refresh-cw" aria-hidden="true"></i> Muat ulang</button>
                    </div>
                    <p x-show="agentsError" x-text="agentsError" class="settings-agent-error" role="alert" x-cloak></p>
                    <p x-show="agentsLoaded && !agentsError && availableAgents.length === 0" class="settings-field-help" x-cloak>Belum ada data agent di monitoring Wazuh. Periksa monitoring dan akses indeks wazuh-monitoring-* pada akun OpenSearch.</p>
                    <div class="settings-telegram-table-wrap">
                        <table class="data-table settings-telegram-table">
                            <thead><tr><th>Label tujuan</th><th>Chat ID</th><th>Agent yang diizinkan</th><th>Aksi</th></tr></thead>
                            <tbody>
                                <template x-for="(destination, idx) in destinations" :key="idx">
                                    <tr :data-telegram-destination="idx">
                                        <td data-label="Label tujuan"><input type="text" :name="`telegram_destinations[${idx}][label]`" x-model="destination.label" class="form-input" placeholder="Developer / Organisasi A" maxlength="100" required :disabled="testing" :aria-label="'Label tujuan ' + (idx + 1)"></td>
                                        <td data-label="Chat ID"><input type="text" :name="`telegram_destinations[${idx}][chat_id]`" x-model="destination.chat_id" class="form-input" placeholder="ID grup atau personal" pattern="-?[1-9][0-9]*" maxlength="100" required :disabled="testing" :aria-label="'Chat ID tujuan ' + (idx + 1)" @input="testResult = null"></td>
                                        <td data-label="Agent yang diizinkan">
                                            <template x-for="name in destination.agents" :key="name"><input type="hidden" :name="`telegram_destinations[${idx}][agents][]`" :value="name"></template>
                                            <div class="settings-agent-picker" x-data="{ open: false, search: '' }" @click.outside="open = false" @keydown.escape.stop.prevent="open = false; $refs.agentToggle.focus()">
                                                <button type="button" class="form-select settings-agent-toggle" @click="open = !open; if (open) $nextTick(() => $refs.agentSearch.focus())" :disabled="testing" :aria-expanded="open" :aria-controls="'telegram-agent-options-' + idx" :aria-label="'Pilih agent untuk ' + (destination.label || 'tujuan ' + (idx + 1))" x-ref="agentToggle" data-telegram-agent-toggle>
                                                    <span x-text="destination.agents.length ? destination.agents.length + ' agent dipilih' : 'Pilih agent'"></span>
                                                    <i data-lucide="chevron-right" aria-hidden="true"></i>
                                                </button>
                                                <div x-show="!open && destination.agents.length" class="settings-agent-selection" x-text="destination.agents.join(', ')"></div>
                                                <div x-show="open" :id="'telegram-agent-options-' + idx" class="settings-agent-options" x-cloak>
                                                    <input type="search" class="form-input" x-model="search" @keydown.enter.prevent placeholder="Cari nama atau ID agent" :aria-label="'Cari agent tujuan ' + (idx + 1)" x-ref="agentSearch" data-telegram-agent-search>
                                                    <div class="settings-agent-list">
                                                        <template x-for="agent in agentOptions(destination, search)" :key="agent.name">
                                                            <label class="settings-agent-option">
                                                                <input type="checkbox" :value="agent.name" x-model="destination.agents" :disabled="testing" :data-agent-name="agent.name">
                                                                <span class="settings-agent-option-copy"><span x-text="agent.name"></span><small x-text="agent.id ? 'ID ' + agent.id : (agentsLoaded ? 'Tidak ada di monitoring terbaru' : 'Pilihan tersimpan')"></small></span>
                                                                <span class="settings-agent-status" :class="{ 'is-active': agent.status === 'active' }" x-show="agent.id" x-text="agent.status"></span>
                                                            </label>
                                                        </template>
                                                        <p x-show="!agentOptions(destination, search).length" class="settings-field-help" x-text="agentsLoading ? 'Memuat agent...' : 'Tidak ada agent yang cocok.'"></p>
                                                    </div>
                                                </div>
                                            </div>
                                            <p class="settings-field-help" x-show="!destination.agents.length">Pilih minimal satu agent.</p>
                                        </td>
                                        <td data-label="Aksi"><div class="settings-telegram-actions">
                                            <button type="button" @click="testChat(destination.chat_id, idx)" class="btn btn-secondary btn-sm settings-chat-test" :disabled="testing" :aria-busy="testing && testingChatIndex === idx" data-telegram-test-chat><span x-text="testing && testingChatIndex === idx ? 'Mengirim...' : 'Kirim pesan tes'"></span></button>
                                            <button type="button" @click="removeDestination(idx)" :disabled="testing" class="settings-icon-action is-danger" title="Hapus tujuan" aria-label="Hapus tujuan Telegram"><i data-lucide="trash-2" aria-hidden="true"></i></button>
                                        </div></td>
                                    </tr>
                                </template>
                                <tr x-show="destinations.length === 0"><td colspan="4" class="text-muted">Belum ada tujuan. Tambahkan tujuan dan pilih agent yang boleh mengirim ke sana.</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="settings-field-help">Kirim pesan tes hanya mengirim pesan percobaan ke Chat ID pada baris tersebut.</p>
                </div>

                <div x-show="testResult" class="alert-msg" :class="testResult?.success ? 'success' : 'error'" role="status" aria-live="polite" x-cloak>
                    <span x-text="testResult?.success
                        ? (testResult.message || 'Pesan test berhasil dikirim' + (testResult.attempts > 1 ? ' setelah ' + testResult.attempts + ' percobaan.' : '.'))
                        : 'Gagal: ' + testResult?.error"></span>
                </div>

                <button type="submit" :disabled="testing || destinations.some(destination => !destination.agents.length)" class="btn settings-submit w-full" style="margin-top:var(--spacing-16);">Simpan konfigurasi Telegram</button>
            </form>
        </div>
    </div>


    {{-- ─── User Management ───────────────────────────────── --}}
    @include('settings.partials.automatic-notifications')
    @endif
    @if($key === 'users')
    @include('settings.partials.users')

    {{-- ─── Data Management ─────────────────────────────────── --}}
    @endif
    @if($key === 'data')
    <div class="card settings-data-card" x-data="dataManagement()">
        <div class="card-header">
            <span class="card-title">Manajemen data</span>
            <span class="text-muted text-sm ml-auto">Ekspor terverifikasi sebelum purge</span>
        </div>
        <div class="card-body">

            @if (!$dataExportsReady)
                <div class="alert-msg error" role="alert">
                    <i data-lucide="database" aria-hidden="true"></i>
                    <div>
                        <strong>Migrasi database belum lengkap.</strong>
                        Fitur ekspor belum tersedia. Administrator server perlu menjalankan
                        <code>php artisan migrate --force</code> dari folder instalasi aplikasi yang aktif.
                    </div>
                </div>
            @else
            {{-- Alert preview --}}
            <div x-show="previewCount !== null" class="alert-msg success" style="margin-bottom:var(--spacing-16);" x-cloak>
                Estimasi: <strong x-text="previewCount"></strong> alert yang akan terpengaruh
                <span x-show="previewAiAnalyses !== null">, <strong x-text="previewAiAnalyses"></strong> analisis AI</span>
                <span x-show="previewTriages !== null">, <strong x-text="previewTriages"></strong> triage</span>
                <span x-show="previewLogs !== null">, <strong x-text="previewLogs"></strong> log notifikasi</span>
            </div>
            <div x-show="previewError" class="alert-msg error" style="margin-bottom:var(--spacing-16);" x-cloak>
                <span x-text="previewError"></span>
            </div>

            <div class="settings-data-grid">

                {{-- ── Format & Scope ── --}}
                <div class="form-group">
                    <label class="form-label">Isi arsip ZIP</label>
                    <select aria-label="Isi arsip ZIP" x-model="format" class="form-select">
                        <option value="json">JSON (universal)</option>
                        <option value="sql">SQL (INSERT statements, reimportable ke MySQL)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Scope data</label>
                    <select aria-label="Scope data" x-model="scope" class="form-select" @change="resetPreview()">
                        <option value="alerts">Alert dan seluruh riwayat terkait</option>
                        <option value="triages">Riwayat triage (dengan konteks alert)</option>
                        <option value="notif_logs">Log notifikasi (dengan konteks alert)</option>
                        <option value="all">Semua data alert dan riwayat</option>
                    </select>
                </div>

                {{-- ── Period Mode Toggle ── --}}
                <div class="form-group" style="grid-column:1/-1;">
                    <label class="form-label"><i data-lucide="clock-3" aria-hidden="true"></i>Mode Rentang Waktu</label>
                    <div class="settings-segmented-control">
                        <button type="button" class="settings-segment" :class="{ 'is-active': periodMode === 'relative' }" @click="periodMode='relative'; resetPreview()">
                            Lebih lama dari N hari/minggu/bulan
                        </button>
                        <button type="button" class="settings-segment" :class="{ 'is-active': periodMode === 'range' }" @click="periodMode='range'; resetPreview()">
                            Rentang tanggal spesifik
                        </button>
                    </div>

                    {{-- Relative Mode --}}
                    <div x-show="periodMode === 'relative'" class="settings-period-row">
                        <div style="flex:1;">
                            <input type="number" x-model="amount" class="form-input" min="1" max="9999" placeholder="Contoh: 30" @input="resetPreview()" :required="periodMode === 'relative'">
                        </div>
                        <div style="flex:1;">
                            <select x-model="unit" class="form-select" @change="resetPreview()">
                                <option value="days">hari</option>
                                <option value="weeks">minggu</option>
                                <option value="months">bulan</option>
                                <option value="years">tahun</option>
                            </select>
                        </div>
                        <button type="button" class="btn btn-ghost btn-sm" @click="previewNow()" :disabled="!hasValidPeriod()"><i data-lucide="search" aria-hidden="true"></i> Preview</button>
                    </div>

                    {{-- Date Range Mode --}}
                    <div x-show="periodMode === 'range'" class="settings-period-row">
                        <div style="flex:1; min-width:140px;">
                            <label class="form-label">Dari</label>
                            <input aria-label="Dari" type="date" x-model="dateFrom" class="form-input" @change="resetPreview()" :required="periodMode === 'range'">
                        </div>
                        <div style="flex:1; min-width:140px;">
                            <label class="form-label">Sampai</label>
                            <input aria-label="Sampai" type="date" x-model="dateTo" class="form-input" @change="resetPreview()" :required="periodMode === 'range'">
                        </div>
                        <div style="align-self:flex-end;">
                            <button type="button" class="btn btn-ghost btn-sm" @click="previewNow()" :disabled="!hasValidPeriod()"><i data-lucide="search" aria-hidden="true"></i> Preview</button>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ── Safe export action ── --}}
            <div class="settings-data-actions">
                <form method="POST" action="{{ route('settings.data.backup') }}" x-ref="backupForm">
                    @csrf
                    <input type="hidden" name="_section" value="{{ $key }}">
                    <input type="hidden" name="format" :value="format">
                    <input type="hidden" name="scope" :value="scope">
                    <input type="hidden" name="period_mode" :value="periodMode">
                    <input type="hidden" name="amount" :value="periodMode==='relative' ? amount : ''">
                    <input type="hidden" name="unit" :value="periodMode==='relative' ? unit : ''">
                    <input type="hidden" name="date_from" :value="periodMode==='range' ? dateFrom : ''">
                    <input type="hidden" name="date_to" :value="periodMode==='range' ? dateTo : ''">
                    <button type="submit" class="btn settings-action-quiet" @click.prevent="submitAction('backup')" :disabled="!hasValidPeriod()">
                        <i data-lucide="archive" aria-hidden="true"></i> Buat ekspor ZIP
                    </button>
                </form>
            </div>

            <p class="text-muted text-sm" style="margin-top:var(--spacing-12);">
                ZIP standar tanpa password arsip. Verifikasi password akun diperlukan sebelum mengunduh.
            </p>

            <div style="margin-top:var(--spacing-24); overflow-x:auto;">
                <div style="font-weight:var(--font-weight-bold); margin-bottom:var(--spacing-8);">20 ekspor terbaru</div>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Dibuat</th>
                            <th>Scope / periode</th>
                            <th>Status</th>
                            <th>Integritas</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($dataExports as $export)
                            @php
                                $statusLabel = match($export->status) {
                                    'queued' => 'Antrean',
                                    'running' => 'Mengekspor',
                                    'verified' => 'Terverifikasi',
                                    'failed' => 'Gagal',
                                    default => $export->status,
                                };
                                $purgeLabel = match($export->purge_status) {
                                    'queued' => 'Purge antrean',
                                    'running' => 'Purge berjalan',
                                    'completed' => 'Sudah dipurge',
                                    'failed' => 'Purge dibatalkan',
                                    default => null,
                                };
                            @endphp
                            <tr x-data="exportStatus(@js([
                                'status' => $export->status,
                                'purge_status' => $export->purge_status,
                                'checksum' => $export->checksum,
                                'file_size' => $export->file_size,
                                'error_message' => $export->error_message,
                                'download_available' => $export->isVerified(),
                            ]), @js(route('settings.data.exports.status', $export)))">
                                <td>
                                    {{ $export->created_at?->format('d/m/Y H:i') }}
                                    <div class="text-muted text-sm">{{ $export->requested_by_name }}</div>
                                </td>
                                <td>
                                    <strong>{{ $export->scope }}</strong> · {{ strtoupper($export->format) }}
                                    <div class="text-muted text-sm">{{ $export->period_label }}</div>
                                </td>
                                <td>
                                    <span class="badge" :class="state.status === 'verified' ? 'badge-acknowledged' : (state.status === 'failed' ? 'badge-new' : 'badge-ignored')" x-text="statusLabel" role="status">{{ $statusLabel }}</span>
                                    <div x-show="state.purge_status" class="text-muted text-sm" x-text="purgeLabel" x-cloak></div>
                                    <div x-show="state.error_message" class="text-muted text-sm" x-text="state.error_message" x-cloak></div>
                                </td>
                                <td class="text-sm">
                                    <div x-show="state.checksum" x-cloak>SHA-256 <code :title="state.checksum" x-text="state.checksum?.slice(0, 12) + '…'"></code>
                                        <div class="text-muted" x-text="((state.file_size || 0) / 1024).toFixed(1) + ' KiB'"></div>
                                    </div>
                                    <span x-show="!state.checksum" class="text-muted">Belum tersedia</span>
                                </td>
                                <td>
                                    <div style="display:flex; gap:var(--spacing-8); flex-wrap:wrap;">
                                            <button type="button" class="btn btn-ghost btn-sm" x-show="state.download_available" x-cloak data-export-download
                                                @click="$dispatch('download-export', { url: @js(route('settings.data.exports.authorize-download', $export, false)), filename: @js($export->filename) })">
                                                <i data-lucide="download" aria-hidden="true"></i> Unduh ZIP
                                            </button>
                                            <form method="POST" action="{{ route('settings.data.exports.purge', $export) }}" x-show="state.status === 'verified' && [null, 'failed'].includes(state.purge_status)" x-cloak onsubmit="return confirm('Verifikasi ulang lalu purge data persis dari backup ini? Tindakan ini permanen.')">
                                                @csrf
                                                <input type="hidden" name="_section" value="{{ $key }}">
                                                <button class="btn btn-ghost btn-sm" type="submit" style="color:var(--danger);">
                                                    <i data-lucide="trash-2" aria-hidden="true"></i> Purge
                                                </button>
                                            </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-muted">Belum ada ekspor.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @endif
        </div>
    </div>

    @endif
</div>
            </details>
        @endforeach
    </div>

<div x-data="exportDownloadDialog()" @download-export.window="open($event.detail)">
    <dialog x-ref="dialog" class="export-download-dialog" aria-labelledby="export-download-title" @close="reset()">
        <h3 id="export-download-title" class="modal-title">Verifikasi unduhan</h3>
        <p class="settings-field-help export-password-filename" x-text="filename"></p>
        <form @submit.prevent="download()">
            <div class="form-group">
                <label class="form-label" for="export-current-password">Password akun Anda</label>
                <input id="export-current-password" x-ref="currentPassword" type="password" class="form-input" x-model="currentPassword" autocomplete="current-password" required :disabled="verifying" aria-describedby="export-password-error">
            </div>
            <p id="export-password-error" class="form-error" role="alert" x-show="error" x-text="error" x-cloak></p>
            <div class="export-password-actions">
                <button type="button" class="btn btn-ghost" @click="$refs.dialog.close()">Batal</button>
                <button type="submit" class="btn btn-primary" :disabled="verifying" x-text="verifying ? 'Memverifikasi…' : 'Verifikasi & unduh'"></button>
            </div>
        </form>
        <a x-ref="downloadLink" hidden download></a>
    </dialog>
</div>
@endsection

@push('scripts')
<script>
function settingsPage() {
    return {
        testing: false,
        osResult: null,
        async testOpenSearch() {
            this.testing = true;
            this.osResult = null;
            try {
                const form = this.$refs.openSearchForm;
                const res = await fetch('/settings/test/opensearch', {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    },
                    body: new FormData(form),
                });
                const data = await res.json().catch(() => ({}));
                this.osResult = res.ok
                    ? data
                    : { success: false, error: data.error ?? data.message ?? Object.values(data.errors ?? {}).flat().join(' ') ?? 'Koneksi tidak dapat diuji.' };
            } catch(e) {
                this.osResult = { success: false, error: e.message };
            }
            this.testing = false;
        }
    }
}

function exportDownloadDialog() {
    return {
        url: '', filename: '', currentPassword: '', error: '', verifying: false, request: null,
        open(detail) {
            this.reset();
            this.url = detail.url;
            this.filename = detail.filename;
            this.$refs.dialog.showModal();
            this.$nextTick(() => this.$refs.currentPassword.focus());
        },
        reset() {
            this.request?.abort();
            this.request = null;
            this.currentPassword = this.error = '';
            this.verifying = false;
            this.$refs.downloadLink?.removeAttribute('href');
        },
        async download() {
            if (this.verifying) return;
            const controller = new AbortController();
            this.request = controller;
            this.verifying = true;
            this.error = '';
            try {
                const response = await fetch(this.url, {
                    method: 'POST', signal: controller.signal, credentials: 'same-origin', cache: 'no-store',
                    headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                    body: JSON.stringify({ current_password: this.currentPassword }),
                });
                const data = await response.json();
                if (this.request !== controller) return;
                if (!response.ok) {
                    this.error = response.status === 429 ? 'Terlalu banyak percobaan. Tunggu satu menit.'
                        : response.status === 419 ? 'Sesi berakhir. Muat ulang halaman.'
                        : (data.errors?.current_password?.[0] || data.message || 'Verifikasi gagal. Coba kembali.');
                    return;
                }
                if (!data.download_url) throw new Error('URL unduhan tidak tersedia.');
                this.$refs.downloadLink.href = data.download_url;
                this.$refs.downloadLink.click();
                this.$refs.dialog.close();
            } catch (error) {
                if (this.request === controller && error.name !== 'AbortError') this.error = 'Verifikasi belum berhasil. Coba kembali.';
            } finally {
                if (this.request === controller) {
                    this.currentPassword = '';
                    this.verifying = false;
                    this.request = null;
                }
            }
        },
    };
}

function exportStatus(initial, statusUrl) {
    return {
        state: initial,
        timer: null,
        get statusLabel() {
            return ({queued: 'Antrean', running: 'Membuat ZIP', verified: 'Terverifikasi', failed: 'Gagal'})[this.state.status] || this.state.status;
        },
        get purgeLabel() {
            return ({queued: 'Purge antrean', running: 'Purge berjalan', completed: 'Sudah dipurge', failed: 'Purge dibatalkan'})[this.state.purge_status] || '';
        },
        init() {
            if (['queued', 'running'].includes(this.state.status) || ['queued', 'running'].includes(this.state.purge_status)) {
                this.timer = window.setInterval(() => this.refreshStatus(), 3000);
                this.refreshStatus();
            }
        },
        destroy() {
            if (this.timer) window.clearInterval(this.timer);
        },
        async refreshStatus() {
            try {
                const response = await fetch(statusUrl, {headers: {'Accept': 'application/json'}, cache: 'no-store', credentials: 'same-origin'});
                if (!response.ok) return;
                const next = await response.json();
                if (!['queued', 'running', 'verified', 'failed'].includes(next.status)) return;
                this.state = {...this.state, ...next};
                if (!['queued', 'running'].includes(next.status) && !['queued', 'running'].includes(next.purge_status)) this.destroy();
            } catch (error) {
                // Keep polling after a temporary network interruption.
            }
        },
    };
}

function dataManagement() {
    return {
        format: 'json',
        scope: 'alerts',
        periodMode: 'relative',
        amount: '7',
        unit: 'days',
        dateFrom: '',
        dateTo: '',
        previewCount: null,
        previewAiAnalyses: null,
        previewTriages: null,
        previewLogs: null,
        previewError: null,

        resetPreview() {
            this.previewCount = null;
            this.previewAiAnalyses = null;
            this.previewTriages = null;
            this.previewLogs = null;
            this.previewError = null;
        },

        hasValidPeriod() {
            if (this.periodMode === 'relative') {
                return Number.isInteger(Number(this.amount)) && Number(this.amount) >= 1 && Number(this.amount) <= 9999;
            }

            return Boolean(this.dateFrom && this.dateTo && this.dateFrom <= this.dateTo);
        },

        periodLabel() {
            if (this.periodMode === 'range') {
                return `${this.dateFrom} s/d ${this.dateTo}`;
            }

            return `lebih lama dari ${this.amount} ${this.unit}`;
        },

        async previewNow() {
            this.resetPreview();
            if (!this.hasValidPeriod()) {
                this.previewError = 'Lengkapi periode yang valid sebelum melakukan preview.';
                return;
            }

            const params = new URLSearchParams({ scope: this.scope, period_mode: this.periodMode });
            if (this.periodMode === 'relative') {
                params.append('amount', this.amount);
                params.append('unit', this.unit);
            } else {
                params.append('date_from', this.dateFrom);
                params.append('date_to', this.dateTo);
            }
            try {
                const res  = await fetch('/settings/data/preview?' + params.toString(), { headers: { 'Accept': 'application/json' } });
                const data = await res.json().catch(() => ({}));
                if (!res.ok) {
                    this.previewError = data.error ?? data.message ?? Object.values(data.errors ?? {}).flat().join(' ') ?? 'Preview tidak dapat dijalankan.';
                    return;
                }
                this.previewCount       = data.counts.alerts ?? 0;
                this.previewAiAnalyses = data.counts.alert_ai_analyses ?? null;
                this.previewTriages     = data.counts.triages ?? null;
                this.previewLogs        = data.counts.notif_logs ?? null;
            } catch(e) {
                this.previewError = e.message || 'Preview tidak dapat dijalankan.';
            }
        },

        submitAction(action) {
            if (!this.hasValidPeriod()) {
                this.previewError = 'Lengkapi periode yang valid sebelum menjalankan tindakan data.';
                return;
            }

            const confirmMsg = {
                backup: `Antrekan backup ${this.scope} untuk periode ${this.periodLabel()}?`,
            }[action];
            if (!confirm(confirmMsg)) return;
            const formRef = {
                backup: this.$refs.backupForm,
            }[action];
            if (formRef) formRef.submit();
        }
    }
}

function userManagement() {
    return {
        records: @js($users->map(fn ($user) => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $user->role, 'agents' => $user->allowed_agents ?? [], 'privileges' => $user->privileges ?? [], 'updateUrl' => route('settings.users.update', $user, false), 'deleteUrl' => route('settings.users.delete', $user, false)])->values()),
        menuUser: null, editingUserId: null, formName: '', formEmail: '',
        accessRole: 'analyst', selectedAgents: [], selectedPrivileges: [], savedAgents: [], agentSearch: '',
        inventoryAgents: [], inventoryLoading: false, inventoryLoaded: false, inventoryError: '',
        section: null, onToggle: null,
        init() {
            this.section = this.$el.closest('details');
            this.onToggle = () => { if (this.section.open && !this.inventoryLoaded) this.loadAgents(); };
            this.section.addEventListener('toggle', this.onToggle);
            if (this.section.open) this.loadAgents();
            @if($errors->any() && old('_user_form'))
            this.$nextTick(() => {
                this.openUserEditor(@js((int) old('_editing_user', 0) ?: null));
                this.formName = @js(old('name', '')); this.formEmail = @js(old('email', ''));
                this.accessRole = @js(old('role', 'analyst'));
                this.selectedAgents = @js(old('agent_keys', [])); this.selectedPrivileges = @js(old('privileges', []));
            });
            @endif
        },
        destroy() { this.section?.removeEventListener('toggle', this.onToggle); },
        get userFormAction() {
            return this.records.find(user => user.id === this.editingUserId)?.updateUrl || @js(route('settings.users.create', [], false));
        },
        openUserMenu(event, id) {
            const menu = this.$refs.userMenu;
            const same = menu.matches(':popover-open') && this.menuUser?.id === id;
            menu.hidePopover();
            if (same) return;
            this.menuUser = this.records.find(user => user.id === id);
            const anchor = event.currentTarget.getBoundingClientRect();
            menu.showPopover();
            this.$nextTick(() => {
                const box = menu.getBoundingClientRect();
                menu.style.left = Math.max(8, Math.min(anchor.right - box.width, innerWidth - box.width - 8)) + 'px';
                menu.style.top = (anchor.bottom + box.height + 8 <= innerHeight ? anchor.bottom + 4 : Math.max(8, anchor.top - box.height - 4)) + 'px';
                menu.querySelector('button').focus();
            });
        },
        openUserEditor(id = null) {
            const user = this.records.find(user => user.id === id);
            this.$refs.userMenu.hidePopover();
            this.editingUserId = user?.id ?? null; this.formName = user?.name ?? ''; this.formEmail = user?.email ?? '';
            this.accessRole = user?.role ?? 'analyst'; this.savedAgents = user?.agents ?? [];
            this.selectedAgents = this.savedAgents.map(agent => agent.key); this.selectedPrivileges = [...(user?.privileges ?? [])];
            this.agentSearch = ''; this.clearUserPasswords(); this.loadAgents();
            this.$refs.userEditor.showModal();
            this.$nextTick(() => this.$refs.userName.focus());
        },
        clearUserPasswords() {
            this.$refs.userEditorForm.querySelectorAll('input[type="password"]').forEach(input => input.value = '');
        },
        async loadAgents(force = false) {
            if (this.inventoryLoading || (this.inventoryLoaded && !force)) return;
            this.inventoryLoading = true; this.inventoryError = '';
            try {
                const response = await fetch(@js(route('settings.users.agents', [], false)), {headers: {'Accept': 'application/json'}, cache: 'no-store'});
                const data = await response.json();
                if (!response.ok || data.success !== true || !Array.isArray(data.agents)) throw Error(data.error || 'Daftar agent belum dapat dimuat.');
                this.inventoryAgents = data.agents; this.inventoryLoaded = true;
            } catch (error) { this.inventoryError = error.message || 'Daftar agent belum dapat dimuat.'; }
            finally { this.inventoryLoading = false; }
        },
        agentOptions(saved, search) {
            const choices = new Map(saved.map(agent => [agent.key, agent]));
            this.inventoryAgents.forEach(agent => choices.set(agent.key, agent));
            const term = String(search || '').toLocaleLowerCase();
            return [...choices.values()].filter(agent => (agent.name+' '+agent.id+' '+agent.source_cluster).toLocaleLowerCase().includes(term));
        }
    };
}
function telegramForm() {
    const saved = @js(old('telegram_destinations', $telegramDestinations));
    const destinations = Object.values(saved && typeof saved === 'object' ? saved : {}).map(destination => ({
        label: destination?.label || '', chat_id: destination?.chat_id || '',
        agents: Array.isArray(destination?.agents) ? destination.agents : [],
    }));
    return {
        destinations,
        availableAgents: [],
        agentsLoading: false,
        agentsLoaded: false,
        agentsError: '',
        updatedAt: null,
        testResult: null,
        testing: false,
        testingChatIndex: null,
        addDestination() { this.destinations.push({ label: '', chat_id: '', agents: [] }); },
        removeDestination(index) { this.destinations.splice(index, 1); this.testResult = null; },
        init() {
            const section = this.$el.closest('[data-settings-section]');
            this.onSectionToggle = () => { if (section.open && !this.agentsLoaded && !this.agentsLoading) this.loadAgents(); };
            section.addEventListener('toggle', this.onSectionToggle);
            if (section.open) this.loadAgents();
        },
        destroy() {
            this.$el.closest('[data-settings-section]').removeEventListener('toggle', this.onSectionToggle);
        },
        agentOptions(destination, search) {
            const known = new Set(this.availableAgents.map(agent => agent.name));
            const selected = destination.agents.filter(name => !known.has(name)).map(name => ({ name, id: '', status: 'unknown' }));
            const term = search.trim().toLocaleLowerCase();
            return [...this.availableAgents, ...selected].filter(agent => (agent.name + ' ' + agent.id).toLocaleLowerCase().includes(term));
        },
        snapshotTime() {
            return this.updatedAt ? new Date(this.updatedAt).toLocaleString('id-ID') : '';
        },
        async loadAgents() {
            if (this.agentsLoading) return;
            this.agentsLoading = true;
            this.agentsError = '';
            try {
                const res = await fetch(@json(route('settings.telegram.agents', [], false)), { headers: { 'Accept': 'application/json' }, cache: 'no-store' });
                const data = await res.json();
                if (!res.ok || data.success !== true || !Array.isArray(data.agents)) throw new Error(data.error || 'Daftar agent belum dapat dimuat. Coba muat ulang.');
                this.availableAgents = data.agents;
                this.updatedAt = data.updated_at;
                this.agentsLoaded = true;
            } catch (error) {
                this.agentsError = error instanceof SyntaxError || error instanceof TypeError ? 'Tidak dapat memuat agent. Periksa koneksi atau muat ulang halaman.' : error.message;
            } finally {
                this.agentsLoading = false;
            }
        },
        async testChat(chatId, index) {
            chatId = String(chatId ?? '').trim();
            if (this.testing) return;
            if (!chatId) {
                this.testResult = { success: false, error: 'Isi Chat ID tujuan terlebih dahulu.' };
                return;
            }

            return this.runTest({ test_type: 'message', chat_id: chatId }, index);
        },
        testBot() {
            return this.runTest({ test_type: 'bot' });
        },
        async runTest(payload, index = null) {
            if (this.testing) return;

            this.testing = true;
            this.testingChatIndex = index;
            this.testResult = null;
            try {
                const botToken = new FormData(this.$refs.telegramForm).get('telegram_bot_token')?.trim() ?? '';
                const res = await fetch(@json(route('settings.test.telegram', [], false)), {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    },
                    body: JSON.stringify({ ...payload, telegram_bot_token: botToken })
                });
                const data = await res.json().catch(() => ({}));
                this.testResult = res.ok && data.success === true
                    ? data
                    : { success: false, error: res.status === 419
                        ? 'Sesi berakhir. Muat ulang halaman, lalu coba kembali.'
                        : (data.error || Object.values(data.errors ?? {}).flat().join(' ') || data.message || 'Aplikasi belum mengonfirmasi pengiriman. Periksa chat tujuan sebelum mencoba kembali.') };
            } catch(e) {
                this.testResult = { success: false, error: 'Browser tidak menerima respons dari aplikasi.' };
            } finally {
                this.testing = false;
                this.testingChatIndex = null;
            }
        }
    }
}

function aiSettingsForm() {
    return {
        testing: false,
        testResult: null,
        async testAi() {
            if (this.testing || !this.$refs.aiForm.reportValidity()) return;
            this.testing = true;
            this.testResult = null;

            try {
                const res = await fetch('/settings/test/ai', {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    },
                    body: new FormData(this.$refs.aiForm),
                });
                const data = await res.json().catch(() => ({}));
                this.testResult = res.ok && data.success === true
                    ? data
                    : { success: false, error: data.error || Object.values(data.errors ?? {}).flat().join(' ') || data.message || 'Koneksi AI tidak dapat diuji.' };
            } catch (error) {
                this.testResult = { success: false, error: 'Koneksi AI tidak dapat diuji dari browser.' };
            } finally {
                this.testing = false;
            }
        }
    };
}
</script>
@include('partials.ai-provider-fields-script')
@endpush
