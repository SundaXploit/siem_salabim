@extends('layouts.app')

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


@if ($errors->any())
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

<div class="settings-grid settings-page" x-data="settingsPage()">

    {{-- ─── OpenSearch Config ─────────────────────────────── --}}
    <div class="card settings-opensearch-card">
        <div class="card-header">
            <span class="card-title"><i data-lucide="database" aria-hidden="true"></i> Konfigurasi OpenSearch</span>
            <button type="button" @click="testOpenSearch()" class="btn btn-ghost btn-sm ml-auto" :disabled="testing">
                <span x-show="!testing">Test Koneksi</span>
                <span x-show="testing">Testing...</span>
            </button>
        </div>
        <div class="card-body">
            <div x-show="osResult" class="alert-msg" :class="osResult?.success ? 'success' : 'error'" style="margin-bottom:1rem;" x-cloak>
                <span x-text="osResult?.success ? 'Akses indeks terverifikasi: ' + osResult.index + ' (' + osResult.count + ' dokumen dapat dibaca)' : osResult?.error"></span>
            </div>
            <form method="POST" action="{{ route('settings.opensearch') }}" x-ref="openSearchForm">
                @csrf
                <div class="form-group">
                    <label class="form-label">Host URL</label>
                    <input type="url" name="opensearch_host" class="form-input" value="{{ old('opensearch_host', $configs['opensearch_host'] ?? 'https://localhost:9200') }}" placeholder="https://wazuh-indexer.example:9200" required>
                    <div class="text-muted text-sm" style="margin-top:0.3rem;">Gunakan URL Wazuh Indexer (umumnya port <code>9200</code>), bukan Dashboard atau Wazuh API.</div>
                </div>
                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label">Username</label>
                        <input type="text" name="opensearch_username" class="form-input" value="{{ old('opensearch_username', $configs['opensearch_username'] ?? 'admin') }}" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Password</label>
                        <input type="password" name="opensearch_password" class="form-input" placeholder="Tersimpan — kosongkan untuk mempertahankan" autocomplete="new-password">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Index Pattern</label>
                    <input type="text" name="opensearch_index" class="form-input" value="{{ old('opensearch_index', $configs['opensearch_index'] ?? 'wazuh-alerts-*') }}" placeholder="wazuh-alerts-*" required>
                </div>

                <button type="submit" class="btn settings-submit w-full"><i data-lucide="check" aria-hidden="true"></i> Simpan konfigurasi OpenSearch</button>
            </form>
        </div>
    </div>

    {{-- Seluruh endpoint pada panel ini juga memverifikasi role admin di controller. --}}
    <div class="card settings-ai-card" x-data="aiSettingsForm()">
        <div class="card-header">
            <div>
                <span class="card-title"><i data-lucide="sparkles" aria-hidden="true"></i> Konfigurasi Analisis AI</span>
                <p class="settings-card-caption">Atur data analisis dashboard, jadwal otomatis, dan koneksi AmanAI.</p>
            </div>
            <button type="button" @click="testAi()" class="btn btn-ghost btn-sm ml-auto" :disabled="testing">
                <span x-show="!testing">Test Koneksi</span>
                <span x-show="testing">Testing...</span>
            </button>
        </div>
        <div class="card-body">
            <div x-show="testResult" class="alert-msg" :class="testResult?.success ? 'success' : 'error'" style="margin-bottom:1rem;" x-cloak>
                <span x-text="testResult?.success ? 'Koneksi AI terverifikasi dengan model ' + testResult.model + '.' : testResult?.error"></span>
            </div>

            <form method="POST" action="{{ route('settings.ai') }}" x-ref="aiForm">
                @csrf
                <div class="settings-ai-fields">
                    <div class="form-group span-2">
                        <label class="form-label" for="ai-base-url">Endpoint</label>
                        <input id="ai-base-url" type="url" name="ai_base_url" class="form-input" value="{{ old('ai_base_url', $configs['ai_base_url'] ?? 'https://api.amanai.dev/v1') }}" placeholder="https://api.amanai.dev/v1" required>
                        <p class="settings-field-help">Wajib HTTPS. Gunakan base URL API tanpa akhiran <code>/responses</code>.</p>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="ai-model">Model AI</label>
                        <input id="ai-model" type="text" name="ai_model" class="form-input" value="{{ old('ai_model', $configs['ai_model'] ?? 'amanai/glm-5.3') }}" placeholder="amanai/glm-5.3" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="ai-api-key">Key token</label>
                        <input id="ai-api-key" type="password" name="ai_api_key" class="form-input" placeholder="{{ $aiKeyStoredInSettings ? 'Tersimpan terenkripsi — kosongkan untuk mempertahankan' : ($aiConfigured ? 'Konfigurasi server aktif — isi untuk memindahkan' : 'Masukkan API key') }}" autocomplete="new-password">
                        <p class="settings-field-help">Token baru disimpan terenkripsi dan tidak pernah ditampilkan kembali.</p>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="ai-snapshot-limit">Cakupan data</label>
                        <input id="ai-snapshot-limit" type="number" name="ai_snapshot_limit" class="form-input" min="1000" max="10000" step="500" value="{{ old('ai_snapshot_limit', $configs['ai_snapshot_limit'] ?? 10000) }}" required>
                        <p class="settings-field-help">Maksimum alert terbaru per bulan yang disampel untuk ekstraksi pola (1.000–10.000).</p>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="ai-min-alert-level">Ambang impor</label>
                        <select id="ai-min-alert-level" name="min_alert_level" class="form-select" required>
                            @foreach($levelOptions as $lvl => $opt)
                                <option value="{{ $lvl }}" @selected($currentLevel === $lvl)>{{ $opt['label'] }} — {{ $opt['desc'] }}</option>
                            @endforeach
                        </select>
                        <p class="settings-field-help">Hanya alert Wazuh pada level ini atau lebih tinggi yang diimpor.</p>
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

                <button type="submit" class="btn settings-submit w-full"><i data-lucide="check" aria-hidden="true"></i> Simpan konfigurasi Analisis AI</button>
            </form>
        </div>
    </div>

    {{-- ─── Telegram Config ───────────────────────────────── --}}
    <div class="card settings-telegram-card">
        <div class="card-header">
            <span class="card-title"><i data-lucide="send" aria-hidden="true"></i> Konfigurasi Telegram Bot</span>
        </div>
        <div class="card-body">
            <form method="POST" action="{{ route('settings.telegram') }}" x-data="telegramForm()">
                @csrf
                <div class="form-group">
                    <label class="form-label">Bot Token</label>
                    <input type="password" name="telegram_bot_token" class="form-input" placeholder="Tersimpan — kosongkan untuk mempertahankan" autocomplete="new-password">
                    <div class="text-muted text-sm" style="margin-top:0.3rem;">Dapatkan dari @BotFather di Telegram. Nilai yang kosong memakai token tersimpan.</div>
                </div>

                <div class="form-group">
                    <label class="form-label">Chat ID Tujuan</label>
                    <div style="display:flex; flex-direction:column; gap:0.5rem; margin-bottom:0.75rem;">
                        <template x-for="(cid, idx) in chatIds" :key="idx">
                            <div class="settings-chat-id-row">
                                <input type="text" :name="`telegram_chat_ids[]`" x-model="chatIds[idx]" class="form-input" placeholder="-100xxxxxxxx">
                                <button type="button" @click="removeChatId(idx)" class="settings-icon-action is-danger" title="Hapus Chat ID" aria-label="Hapus Chat ID"><i data-lucide="x" aria-hidden="true"></i></button>
                                <button type="button" @click="testChat(chatIds[idx], idx)" class="settings-icon-action"
                                    :disabled="testingChatIndex !== null"
                                    :title="testingChatIndex === idx ? 'Menguji koneksi...' : 'Test Chat ID'"
                                    aria-label="Test Chat ID"><i data-lucide="send" aria-hidden="true"></i></button>
                            </div>
                        </template>
                    </div>
                    <button type="button" @click="addChatId()" class="settings-text-action"><i data-lucide="plus" aria-hidden="true"></i> Tambah Chat ID</button>
                </div>

                <div x-show="testResult" class="alert-msg" :class="testResult?.success ? 'success' : 'error'" x-cloak>
                    <span x-text="testResult?.success
                        ? 'Pesan test berhasil dikirim' + (testResult.attempts > 1 ? ' setelah ' + testResult.attempts + ' percobaan.' : '.')
                        : 'Gagal: ' + testResult?.error"></span>
                </div>

                <button type="submit" class="btn settings-submit w-full" style="margin-top:1rem;"><i data-lucide="check" aria-hidden="true"></i> Simpan konfigurasi Telegram</button>
            </form>
        </div>
    </div>


    {{-- ─── User Management ───────────────────────────────── --}}
    <div class="card settings-user-management">
        <div class="card-header">
            <span class="card-title"><i data-lucide="users" aria-hidden="true"></i> Manajemen user</span>
        </div>
        <div class="settings-user-grid">
            {{-- User list --}}
            <div class="settings-user-list">
                <table class="data-table settings-user-table">
                    <thead>
                        <tr>
                            <th>Nama</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($users as $user)
                        <tr>
                            <td class="settings-user-name">{{ $user->name }}</td>
                            <td class="settings-user-email">{{ $user->email }}</td>
                            <td class="settings-user-role-cell">
                                <span class="settings-role">{{ strtoupper($user->role) }}</span>
                            </td>
                            <td class="settings-user-actions">
                                <div>
                                    @if($user->id !== auth()->id())
                                    <form method="POST" action="{{ route('settings.users.delete', $user) }}" onsubmit="return confirm('Hapus pengguna ini?')">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="table-action table-action-danger" title="Hapus user {{ $user->name }}" aria-label="Hapus user {{ $user->name }}"><i data-lucide="trash-2" aria-hidden="true"></i></button>
                                    </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Create user form --}}
            <div class="settings-user-create">
                <div class="settings-user-create-heading">
                    <h3>Tambah pengguna</h3>
                    <p>Akun baru dapat diberi peran admin atau analyst.</p>
                </div>
                <form method="POST" action="{{ route('settings.users.create') }}" class="settings-user-form">
                    @csrf
                    <div class="form-group">
                        <label class="form-label">Nama</label>
                        <input type="text" name="name" class="form-input" value="{{ old('name') }}" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-input" value="{{ old('email') }}" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Role</label>
                        <select name="role" class="form-select" required>
                            <option value="analyst" @selected(old('role', 'analyst') === 'analyst')>Analyst</option>
                            <option value="admin" @selected(old('role') === 'admin')>Admin</option>
                        </select>
                    </div>
                    <div class="grid-2">
                        <div class="form-group">
                            <label class="form-label">Password</label>
                            <input type="password" name="password" class="form-input" required minlength="8">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Konfirmasi Password</label>
                            <input type="password" name="password_confirmation" class="form-input" required>
                        </div>
                    </div>
                    <button type="submit" class="btn settings-submit w-full"><i data-lucide="user-cog" aria-hidden="true"></i> Buat pengguna</button>
                </form>
            </div>
        </div>
    </div>

    {{-- ─── Manual Actions ──────────────────────────────────── --}}
    <div class="card settings-manual-card">
        <div class="card-header">
            <span class="card-title"><i data-lucide="play" aria-hidden="true"></i> Manual actions</span>
        </div>
        <div class="card-body" style="display:flex; flex-direction:column; gap:0.75rem;">
            <div>
                <div style="font-weight:600; margin-bottom:0.3rem;">Fetch Alert Manual</div>
                <div class="text-muted text-sm" style="margin-bottom:0.75rem;">Ambil alert hari ini mulai pukul 00:00 WIB (Asia/Jakarta), dengan level ≥ {{ $configs['min_alert_level'] ?? 12 }}. Seluruh halaman hasil hari ini diperiksa dan alert yang sudah tersimpan dilewati.</div>
                <form method="POST" action="{{ route('settings.fetch-now') }}" x-data="{ fetching: false }" @submit="fetching = true">
                    @csrf
                    <button type="submit" class="btn settings-submit settings-submit-inline" :disabled="fetching"><i data-lucide="play" aria-hidden="true"></i> <span x-text="fetching ? 'Mengambil alert…' : 'Fetch alert hari ini'">Fetch alert hari ini</span></button>
                </form>
            </div>
        </div>
    </div>

    {{-- ─── Data Management ─────────────────────────────────── --}}
    <div class="card settings-data-card" x-data="dataManagement()">
        <div class="card-header">
            <span class="card-title"><i data-lucide="archive" aria-hidden="true"></i> Manajemen data</span>
            <span class="text-muted text-sm ml-auto">Backup & hapus data sesuai kebutuhan</span>
        </div>
        <div class="card-body">

            {{-- Alert preview --}}
            <div x-show="previewCount !== null" class="alert-msg success" style="margin-bottom:1rem;" x-cloak>
                Estimasi: <strong x-text="previewCount"></strong> alert yang akan terpengaruh
                <span x-show="previewTriages !== null">, <strong x-text="previewTriages"></strong> triage</span>
                <span x-show="previewLogs !== null">, <strong x-text="previewLogs"></strong> log notifikasi</span>
            </div>
            <div x-show="previewError" class="alert-msg error" style="margin-bottom:1rem;" x-cloak>
                <span x-text="previewError"></span>
            </div>

            <div class="settings-data-grid">

                {{-- ── Format & Scope ── --}}
                <div class="form-group">
                    <label class="form-label">Format output</label>
                    <select x-model="format" class="form-select">
                        <option value="json">JSON (universal)</option>
                        <option value="sql">SQL (INSERT statements, reimportable ke MySQL)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Scope data</label>
                    <select x-model="scope" class="form-select" @change="resetPreview()">
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
                    <div x-show="periodMode === 'relative'" style="display:flex; gap:0.75rem; align-items:center;">
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
                    <div x-show="periodMode === 'range'" style="display:flex; gap:0.75rem; align-items:center; flex-wrap:wrap;">
                        <div style="flex:1; min-width:140px;">
                            <label class="form-label">Dari</label>
                            <input type="date" x-model="dateFrom" class="form-input" @change="resetPreview()" :required="periodMode === 'range'">
                        </div>
                        <div style="flex:1; min-width:140px;">
                            <label class="form-label">Sampai</label>
                            <input type="date" x-model="dateTo" class="form-input" @change="resetPreview()" :required="periodMode === 'range'">
                        </div>
                        <div style="align-self:flex-end;">
                            <button type="button" class="btn btn-ghost btn-sm" @click="previewNow()" :disabled="!hasValidPeriod()"><i data-lucide="search" aria-hidden="true"></i> Preview</button>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ── Action Buttons ── --}}
            <div class="settings-data-actions">
                {{-- Backup --}}
                <form method="POST" action="{{ route('settings.data.backup') }}" x-ref="backupForm">
                    @csrf
                    <input type="hidden" name="format" :value="format">
                    <input type="hidden" name="scope" :value="scope">
                    <input type="hidden" name="period_mode" :value="periodMode">
                    <input type="hidden" name="amount" :value="periodMode==='relative' ? amount : ''">
                    <input type="hidden" name="unit" :value="periodMode==='relative' ? unit : ''">
                    <input type="hidden" name="date_from" :value="periodMode==='range' ? dateFrom : ''">
                    <input type="hidden" name="date_to" :value="periodMode==='range' ? dateTo : ''">
                    <button type="submit" class="btn settings-action-quiet" @click.prevent="submitAction('backup')" :disabled="!hasValidPeriod()">
                        <i data-lucide="download" aria-hidden="true"></i> Backup saja
                    </button>
                </form>

                {{-- Delete --}}
                <form method="POST" action="{{ route('settings.data.delete') }}" x-ref="deleteForm">
                    @csrf
                    <input type="hidden" name="scope" :value="scope">
                    <input type="hidden" name="period_mode" :value="periodMode">
                    <input type="hidden" name="amount" :value="periodMode==='relative' ? amount : ''">
                    <input type="hidden" name="unit" :value="periodMode==='relative' ? unit : ''">
                    <input type="hidden" name="date_from" :value="periodMode==='range' ? dateFrom : ''">
                    <input type="hidden" name="date_to" :value="periodMode==='range' ? dateTo : ''">
                    <button type="submit" class="btn settings-action-quiet is-danger" @click.prevent="submitAction('delete')" :disabled="!hasValidPeriod()">
                        <i data-lucide="trash-2" aria-hidden="true"></i> Hapus saja
                    </button>
                </form>

                {{-- Backup + Delete --}}
                <form method="POST" action="{{ route('settings.data.backup-delete') }}" x-ref="backupDeleteForm">
                    @csrf
                    <input type="hidden" name="format" :value="format">
                    <input type="hidden" name="scope" :value="scope">
                    <input type="hidden" name="period_mode" :value="periodMode">
                    <input type="hidden" name="amount" :value="periodMode==='relative' ? amount : ''">
                    <input type="hidden" name="unit" :value="periodMode==='relative' ? unit : ''">
                    <input type="hidden" name="date_from" :value="periodMode==='range' ? dateFrom : ''">
                    <input type="hidden" name="date_to" :value="periodMode==='range' ? dateTo : ''">
                    <button type="submit" class="btn settings-action-quiet" @click.prevent="submitAction('backup-delete')" :disabled="!hasValidPeriod()">
                        <i data-lucide="archive" aria-hidden="true"></i> Backup + hapus
                    </button>
                </form>
            </div>

            <p class="text-muted text-sm" style="margin-top:0.75rem;">
                "Hapus" bersifat <strong style="color:var(--danger);">permanen</strong>. Gunakan "Backup + Hapus" untuk arsip sebelum menghapus.
            </p>
        </div>
    </div>

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
        previewTriages: null,
        previewLogs: null,
        previewError: null,

        resetPreview() {
            this.previewCount = null;
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
                this.previewCount   = data.counts.alerts   ?? 0;
                this.previewTriages = data.counts.triages   ?? null;
                this.previewLogs    = data.counts.notif_logs ?? null;
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
                delete:          `Hapus data ${this.scope} untuk periode ${this.periodLabel()}? Tindakan ini tidak bisa dibatalkan.`,
                backup:          `Unduh backup data ${this.scope} untuk periode ${this.periodLabel()}?`,
                'backup-delete': `Backup lalu hapus data ${this.scope} untuk periode ${this.periodLabel()}? Data akan hilang permanen.`,
            }[action];
            if (!confirm(confirmMsg)) return;
            const formRef = {
                backup:          this.$refs.backupForm,
                delete:          this.$refs.deleteForm,
                'backup-delete': this.$refs.backupDeleteForm,
            }[action];
            if (formRef) formRef.submit();
        }
    }
}

function telegramForm() {
    const raw = @json(\App\Models\Configuration::getTelegramChatIds());
    return {
        chatIds: raw.length ? raw : [''],
        testResult: null,
        testingChatIndex: null,
        addChatId() { this.chatIds.push(''); },
        removeChatId(idx) { this.chatIds.splice(idx, 1); if (!this.chatIds.length) this.chatIds.push(''); },
        async testChat(chatId, index) {
            chatId = String(chatId ?? '').trim();
            if (!chatId || this.testingChatIndex !== null) return;

            this.testingChatIndex = index;
            this.testResult = null;
            try {
                const botToken = new FormData(this.$el).get('telegram_bot_token')?.trim() ?? '';
                const res = await fetch('/settings/test/telegram', {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    },
                    body: JSON.stringify({ chat_id: chatId, telegram_bot_token: botToken })
                });
                const data = await res.json().catch(() => ({}));
                this.testResult = res.ok
                    ? data
                    : { success: false, error: data.error ?? data.message ?? Object.values(data.errors ?? {}).flat().join(' ') ?? 'Pesan test tidak dapat dikirim.' };
            } catch(e) {
                this.testResult = { success: false, error: 'Browser tidak menerima respons dari aplikasi.' };
            } finally {
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
                this.testResult = res.ok
                    ? data
                    : { success: false, error: data.error ?? data.message ?? Object.values(data.errors ?? {}).flat().join(' ') ?? 'Koneksi AI tidak dapat diuji.' };
            } catch (error) {
                this.testResult = { success: false, error: 'Koneksi AI tidak dapat diuji dari browser.' };
            } finally {
                this.testing = false;
            }
        }
    };
}
</script>
@endpush
