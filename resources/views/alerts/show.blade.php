@extends('layouts.app')

@section('title', 'Detail Alert #' . $alert->id)
@section('page-context', 'Incident Queue')
@section('page-title', 'Detail Alert')
@section('page-subtitle', 'Alert #' . $alert->id)

@php
    $chatIds = \App\Models\Configuration::getTelegramChatIds();
@endphp

@push('scripts')
<script>window.__siemChatIds = {!! \Illuminate\Support\Js::from($chatIds) !!};</script>
@endpush

@section('content')

<style media="not all" data-legacy-alert-detail="true">
    .alert-grid-main { display: grid; grid-template-columns: 1fr 380px; gap: 1.5rem; align-items: start; }
    .alert-grid-info { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-top: 1rem; }
    .alert-grid-extra { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem; margin-top: 1rem; }
    .alert-header-actions { display: flex; align-items: center; justify-content: space-between; gap: 1rem; width: 100%; flex-wrap: wrap; }
    .alert-header-badges { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
    
    @media (max-width: 1024px) {
        .alert-grid-main { grid-template-columns: 1fr; }
    }
    @media (max-width: 640px) {
        .alert-grid-info { grid-template-columns: 1fr; gap: 1rem; }
        .alert-grid-extra { grid-template-columns: 1fr; }
        .alert-header-actions { flex-direction: column; align-items: flex-start; }
        .btn-ghost.ml-auto { margin-left: 0; }
    }

    /* ─── Raw Data Viewer ─── */
    .raw-view-toggle {
        display: flex;
        gap: 0.25rem;
        background: var(--bg-secondary);
        border: 1px solid var(--border);
        border-radius: 8px;
        padding: 0.2rem;
    }
    .raw-view-btn {
        padding: 0.25rem 0.75rem;
        border-radius: 6px;
        font-size: 0.72rem;
        font-weight: 600;
        cursor: pointer;
        border: none;
        background: transparent;
        color: var(--text-secondary);
        font-family: 'Plus Jakarta Sans', sans-serif;
        transition: all 0.15s;
        white-space: nowrap;
    }
    .raw-view-btn.active {
        background: var(--accent);
        color: #fff;
        box-shadow: 0 1px 6px var(--accent-glow);
    }
    .raw-json-pre {
        padding: 1rem 1.25rem;
        font-family: 'Fira Mono', 'Cascadia Code', 'Courier New', monospace;
        font-size: 0.71rem;
        line-height: 1.7;
        overflow: auto;
        background: var(--bg-secondary);
        border-radius: 0 0 12px 12px;
        border-top: 1px solid var(--border);
        transition: max-height 0.3s ease;
        color: var(--text-secondary);
    }
    .raw-json-pre .j-key    { color: #7dd3fc; }  /* key names */
    .raw-json-pre .j-str    { color: #86efac; }  /* string values */
    .raw-json-pre .j-num    { color: #fca5a5; }  /* number values */
    .raw-json-pre .j-bool   { color: #f9a8d4; }  /* bool values */
    .raw-json-pre .j-null   { color: #9ca3af; }  /* null */
    html.light .raw-json-pre .j-key  { color: #1d4ed8; }
    html.light .raw-json-pre .j-str  { color: #15803d; }
    html.light .raw-json-pre .j-num  { color: #b91c1c; }
    html.light .raw-json-pre .j-bool { color: #9333ea; }
    html.light .raw-json-pre .j-null { color: #71717a; }

    .raw-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.75rem;
    }
    .raw-table tr:hover td { background: var(--accent-glow); }
    .raw-table td {
        padding: 0.45rem 1rem;
        border-bottom: 1px solid var(--border);
        vertical-align: top;
    }
    .raw-table td:first-child {
        font-weight: 600;
        color: var(--text-secondary);
        width: 38%;
        white-space: nowrap;
        font-family: monospace;
        font-size: 0.68rem;
    }
    .raw-table td:last-child {
        color: var(--text-primary);
        word-break: break-all;
        font-family: monospace;
        font-size: 0.7rem;
    }
    .raw-copy-btn {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        padding: 0.25rem 0.7rem;
        border-radius: 7px;
        font-size: 0.72rem;
        font-weight: 600;
        cursor: pointer;
        border: 1px solid var(--border);
        background: transparent;
        color: var(--text-secondary);
        font-family: 'Plus Jakarta Sans', sans-serif;
        transition: all 0.2s;
    }
    .raw-copy-btn:hover { border-color: var(--accent); color: var(--accent); background: var(--accent-glow); }
    .raw-copy-btn.copied { border-color: var(--success); color: var(--success); background: rgba(16,185,129,0.1); }
</style>

<div class="alert-grid-main">

    {{-- ─── Left: Alert Detail ─────────────────────────────────────────── --}}
    <div style="min-width: 0;">
        <div class="card" style="margin-bottom:1.25rem;">
            <div class="card-header alert-header-actions">
                <div class="alert-header-badges">
                    <span class="badge {{ $alert->status_badge_class }}" style="font-size:0.78rem;">{{ $alert->status_label }}</span>
                    <span class="badge {{ $alert->level_badge_class }}" style="font-size:0.78rem;">Level {{ $alert->rule_level }}</span>
                    <span class="text-muted text-sm" style="margin-left:0.5rem;">
                        #{{ $alert->id }} · {{ $alert->first_seen_at?->setTimezone('Asia/Jakarta')->format('d M Y, H:i:s') ?? '-' }} WIB
                    </span>
                </div>
                <a href="{{ route('alerts.index') }}" class="btn btn-ghost btn-sm ml-auto"><i data-lucide="arrow-left" aria-hidden="true"></i> Kembali</a>
            </div>
            <div class="card-body">
                <h2 style="font-size:1.1rem; font-weight:700; margin-bottom:1.5rem; color:var(--danger);">
                    {{ $alert->rule_description ?? 'No Description' }}
                </h2>
                <div class="alert-grid-info">
                    <div>
                        <div class="form-label">Rule ID</div>
                        <div style="font-family:monospace; color:var(--accent); font-size:1rem; font-weight:700;">{{ $alert->rule_id ?? '-' }}</div>
                    </div>
                    <div>
                        <div class="form-label">Rule Level</div>
                        <div style="font-size:1.5rem; font-weight:800; color:{{ $alert->rule_level >= 15 ? 'var(--danger)' : ($alert->rule_level >= 12 ? 'var(--warning)' : 'var(--success)') }}">
                            {{ $alert->rule_level }}
                        </div>
                    </div>
                    <div>
                        <div class="form-label">Agent</div>
                        <div style="font-weight:600;">{{ $alert->agent_name ?? '-' }}</div>
                    </div>
                    <div>
                        <div class="form-label">Wazuh Alert ID</div>
                        <div style="font-family:monospace; font-size:0.72rem; color:var(--text-muted); word-break:break-all;">{{ $alert->wazuh_alert_id }}</div>
                    </div>
                    <div>
                        <div class="form-label">Source IP</div>
                        <div style="font-family:monospace; color:var(--info); font-size:1rem; font-weight:700;">{{ $alert->src_ip ?? '-' }}</div>
                    </div>
                    <div>
                        <div class="form-label">Destination IP</div>
                        <div style="font-family:monospace; color:var(--warning); font-size:1rem; font-weight:700;">{{ $alert->dst_ip ?? '-' }}</div>
                    </div>
                </div>

                @php $raw = $alert->raw_data ?? []; $syscheck = $raw['syscheck'] ?? []; $data = $raw['data'] ?? []; @endphp
                @if(!empty($syscheck['path']) || !empty($data['srcuser']) || !empty($data['dstuser']))
                <hr class="divider">
                <div class="alert-grid-extra">
                    @if(!empty($syscheck['path']))<div><div class="form-label">Path</div><div style="font-family:monospace; font-size:0.78rem; word-break:break-all;">{{ $syscheck['path'] }}</div></div>@endif
                    @if(!empty($data['dstuser']))<div><div class="form-label">Dest User</div><div>{{ $data['dstuser'] }}</div></div>@endif
                    @if(!empty($data['srcuser']))<div><div class="form-label">Src User</div><div>{{ $data['srcuser'] }}</div></div>@endif
                </div>
                @endif
            </div>
        </div>

        {{-- ─── Raw Alert Data Card ─── --}}
        <div class="card"
             x-data="rawDataViewer()"
             x-init="init()">

            {{-- Card header --}}
            <div class="card-header" style="flex-wrap: wrap; gap: 0.5rem;">
                <span class="card-title" x-show="mode !== 'ai'">
                    <i data-lucide="file-code-2" aria-hidden="true"></i> Raw alert data
                </span>
                <span class="card-title" x-show="mode === 'ai'" x-cloak>
                    <i data-lucide="lightbulb" aria-hidden="true"></i> Analisis AI alert
                </span>

                <div class="ml-auto flex items-center gap-2" style="flex-wrap: wrap;">

                    {{-- View toggle: JSON / Table / AI --}}
                    <div class="raw-view-toggle">
                        <button class="raw-view-btn" :class="{ active: mode === 'json' }"
                                @click="mode = 'json'" id="raw-mode-json">{ } JSON</button>
                        <button class="raw-view-btn" :class="{ active: mode === 'table' }"
                                @click="mode = 'table'" id="raw-mode-table">Table</button>
                        <button type="button"
                                class="raw-view-btn"
                                :class="{ active: mode === 'ai' }"
                                @click="openAi()"
                                id="raw-mode-ai"
                                :aria-busy="(mode === 'ai' && aiLoading).toString()">
                            <i data-lucide="lightbulb" x-show="!aiLoading" aria-hidden="true"></i>
                            <span class="raw-ai-spinner" x-show="aiLoading" x-cloak aria-hidden="true"></span>
                            <span>AI</span>
                        </button>
                    </div>


                    {{-- Copy button --}}
                    <button class="raw-copy-btn" :class="{ copied: copied }"
                            x-show="mode !== 'ai'"
                            @click="copyRaw()" id="raw-copy-btn">
                        <i data-lucide="copy" aria-hidden="true"></i><span x-text="copied ? 'Tersalin' : 'Salin'"></span>
                    </button>

                </div>
            </div>

            {{-- AI view replaces JSON/Table; the request starts only when this tab is opened. --}}
            <section class="alert-ai-analysis" x-show="mode === 'ai'" x-cloak aria-live="polite">
                <div class="alert-ai-loading" x-show="aiLoading">
                    <span class="raw-ai-spinner" aria-hidden="true"></span>
                    <div>
                        <strong>Menganalisis bukti alert…</strong>
                        <p>Analisis ringkas sedang berjalan. Halaman ini tidak melakukan triage otomatis.</p>
                    </div>
                </div>

                <div class="alert-ai-error" x-show="!aiLoading && aiError">
                    <div>
                        <strong>Analisis belum berhasil.</strong>
                        <p x-text="aiError"></p>
                    </div>
                    <button type="button" class="settings-text-action" @click="analyseWithAi()">Coba lagi</button>
                </div>

                <div class="alert-ai-result" x-show="!aiLoading && aiResult">
                    <header class="alert-ai-result-header">
                        <div>
                            <span>Hint analisis AI</span>
                            <strong x-text="verdictLabel(aiResult?.verdict)"></strong>
                        </div>
                        <div class="alert-ai-confidence" x-show="aiResult?.confidence !== null && aiResult?.confidence !== undefined">
                            <span>Confidence</span>
                            <strong x-text="`${aiResult?.confidence ?? 0}%`"></strong>
                        </div>
                    </header>

                    <p class="alert-ai-summary" x-text="aiResult?.summary"></p>

                    <div class="alert-ai-evidence-grid">
                        <div x-show="aiResult?.supporting_indicators?.length">
                            <h3>Indikator malicious</h3>
                            <ul><template x-for="item in aiResult?.supporting_indicators ?? []" :key="item"><li x-text="item"></li></template></ul>
                        </div>
                        <div x-show="aiResult?.legitimate_indicators?.length">
                            <h3>Indikator legitimate</h3>
                            <ul><template x-for="item in aiResult?.legitimate_indicators ?? []" :key="item"><li x-text="item"></li></template></ul>
                        </div>
                        <div x-show="aiResult?.recommended_checks?.length">
                            <h3>Pemeriksaan lanjutan</h3>
                            <ul><template x-for="item in aiResult?.recommended_checks ?? []" :key="item"><li x-text="item"></li></template></ul>
                        </div>
                        <div x-show="aiResult?.limitations?.length">
                            <h3>Keterbatasan</h3>
                            <ul><template x-for="item in aiResult?.limitations ?? []" :key="item"><li x-text="item"></li></template></ul>
                        </div>
                    </div>

                    <footer class="alert-ai-result-footer">
                        <div>
                            <span x-text="aiResult?.model"></span>
                            <span x-show="aiResult?.generated_at" x-text="analysisMeta(aiResult)"></span>
                            <span>Bukti teknis tersanitasi dapat mencakup IOC seperti IP atau path. Second opinion saja — keputusan akhir tetap pada analyst SOC.</span>
                        </div>
                        <button type="button" class="settings-text-action" @click="analyseWithAi(true)" :disabled="aiLoading">
                            <i data-lucide="refresh-cw" aria-hidden="true"></i> Analisis ulang
                        </button>
                    </footer>
                </div>
            </section>

            {{-- JSON view --}}
            <div x-show="mode === 'json'">
                <pre class="raw-json-pre" style="max-height: 500px;" x-html="highlighted"></pre>
            </div>

            {{-- Table view --}}
            <div x-show="mode === 'table'"
                 style="max-height: 500px; overflow: auto; border-top: 1px solid var(--border); border-radius: 0 0 12px 12px;">
                <table class="raw-table">
                    <tbody>
                        <template x-for="[k, v] in flatRows" :key="k">
                            <tr>
                                <td x-text="k"></td>
                                <td x-text="v"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- ─── Right: Actions + History ───────────────────────────────────── --}}
    <div x-data="detailNotifModal({{ $alert->id }})">

        {{-- Actions --}}
        <div class="card" style="margin-bottom:1.25rem;">
            <div class="card-header"><span class="card-title"><i data-lucide="shield-alert" aria-hidden="true"></i> Triage actions</span></div>
            <div class="card-body" style="display:flex; flex-direction:column; gap:0.75rem;">
                @if($alert->status === 'new')
                <form method="POST" action="{{ route('alerts.acknowledge', $alert) }}">
                    @csrf
                    <button type="submit" class="btn btn-warning w-full" style="width:100%; justify-content:center;"><i data-lucide="check" aria-hidden="true"></i> Acknowledge alert</button>
                </form>
                @endif

                @if($alert->status !== 'ignored')
                <button class="btn btn-danger w-full" style="width:100%; justify-content:center;" @click="showIgnoreModal=true"><i data-lucide="x" aria-hidden="true"></i> Abaikan alert</button>
                @endif

                <button class="btn btn-primary w-full" style="width:100%; justify-content:center;" @click="openNotifModal()">
                    <i data-lucide="send" aria-hidden="true"></i> Kirim notifikasi Telegram
                </button>
            </div>
        </div>

        {{-- Triage History --}}
        <div class="card" style="margin-bottom:1.25rem;">
            <div class="card-header"><span class="card-title"><i data-lucide="history" aria-hidden="true"></i> Riwayat triage</span></div>
            <div class="card-body" style="padding:0;">
                @forelse($alert->triages as $triage)
                <div style="padding:0.875rem 1.25rem; border-bottom:1px solid var(--border);">
                    <div class="flex items-center gap-2 mb-1" style="flex-wrap: wrap;">
                        <span class="badge {{ $triage->action === 'ignore' ? 'badge-ignored' : ($triage->action === 'acknowledge' ? 'badge-acknowledged' : 'badge-new') }}" style="font-size:0.65rem;">{{ strtoupper($triage->action) }}</span>
                        <span style="font-size:0.75rem; font-weight:600;">{{ $triage->user->name ?? 'Unknown' }}</span>
                        <span class="text-muted text-sm ml-auto">{{ $triage->created_at?->setTimezone('Asia/Jakarta')->diffForHumans() }}</span>
                    </div>
                    @if($triage->reason)<div style="font-size:0.75rem; color:var(--text-muted);">{{ $triage->reason }}</div>@endif
                </div>
                @empty
                <div style="padding:1.5rem; text-align:center; color:var(--text-muted); font-size:0.82rem;">Belum ada triage</div>
                @endforelse
            </div>
        </div>

        {{-- Notification History --}}
        <div class="card">
            <div class="card-header"><span class="card-title"><i data-lucide="mail" aria-hidden="true"></i> Riwayat notifikasi</span></div>
            <div class="card-body" style="padding:0;">
                @forelse($alert->notificationLogs as $log)
                <div style="padding:0.875rem 1.25rem; border-bottom:1px solid var(--border);">
                    <div class="flex items-center gap-2 mb-1" style="flex-wrap: wrap;">
                        <span style="font-size:0.72rem; font-family:monospace; color:var(--info);">{{ $log->chat_id }}</span>
                        <span class="badge {{ $log->response_status === 200 ? 'badge-acknowledged' : 'badge-ignored' }}" style="font-size:0.65rem;">{{ $log->response_status === 200 ? 'OK' : 'FAILED' }}</span>
                        <span class="text-muted text-sm ml-auto">{{ $log->sent_at?->setTimezone('Asia/Jakarta')->diffForHumans() }}</span>
                    </div>
                    <div style="font-size:0.72rem; color:var(--text-muted);">oleh {{ $log->user->name ?? 'Unknown' }}</div>
                </div>
                @empty
                <div style="padding:1.5rem; text-align:center; color:var(--text-muted); font-size:0.82rem;">Belum ada notifikasi dikirim</div>
                @endforelse
            </div>
        </div>

        {{-- ── IGNORE MODAL ── --}}
        <div class="modal-backdrop" x-show="showIgnoreModal" x-cloak @click.self="showIgnoreModal=false" @keydown.escape.window="showIgnoreModal=false" role="dialog" aria-modal="true" aria-label="Abaikan alert">
            <div class="modal-box" @click.stop>
                <div class="modal-title"><i data-lucide="circle-alert" aria-hidden="true"></i> Abaikan alert #{{ $alert->id }}</div>
                <form method="POST" action="{{ route('alerts.ignore', $alert) }}">
                    @csrf
                    <div class="form-group">
                        <label class="form-label">Alasan Mengabaikan *</label>
                        <textarea name="reason" class="form-textarea" rows="4" placeholder="Contoh: False positive, IP internal..." required minlength="5"></textarea>
                    </div>
                    <div class="flex gap-2" style="justify-content:flex-end;">
                        <button type="button" class="btn btn-ghost" @click="showIgnoreModal=false">Batal</button>
                        <button type="submit" class="btn btn-danger">Abaikan</button>
                    </div>
                </form>
            </div>
        </div>

        {{-- ── NOTIFY MODAL ── --}}
        <div class="modal-backdrop" x-show="showNotifModal" x-cloak @click.self="showNotifModal=false" @keydown.escape.window="showNotifModal=false" role="dialog" aria-modal="true" aria-label="Kirim notifikasi">
            <div class="modal-box" style="max-width:700px;" @click.stop>
                <div class="modal-title"><i data-lucide="send" aria-hidden="true"></i> Kirim notifikasi Telegram — alert #{{ $alert->id }}</div>

                <div x-show="loading" style="text-align:center; padding:2rem; color:var(--text-muted);">
                    <i class="loading-state-icon" data-lucide="clock-3" aria-hidden="true"></i>Memuat template...
                </div>

                <form method="POST" action="{{ route('alerts.notify', $alert) }}" x-show="!loading" enctype="multipart/form-data">
                    @csrf

                    {{-- Chat ID --}}
                    <div class="form-group">
                        <label class="form-label">Kirim ke (Chat ID)</label>
                        <select name="chat_id" class="form-select" required>
                            <option value="">-- Pilih Chat ID --</option>
                            <template x-for="cid in chatIds" :key="cid">
                                <option :value="cid" x-text="cid"></option>
                            </template>
                        </select>
                        <div x-show="chatIds.length === 0" style="color:var(--danger); font-size:0.75rem; margin-top:0.3rem;">
                            Belum ada Chat ID. Tambah di <a href="{{ route('settings.index') }}" style="color:var(--accent);">Settings → Telegram</a>
                        </div>
                    </div>

                    {{-- Template selector --}}
                    <div class="form-group">
                        <label class="form-label">Pilih template</label>
                        <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(150px,1fr)); gap:0.5rem;">
                            <template x-for="tpl in templates" :key="tpl.id">
                                <button type="button"
                                    class="btn btn-ghost btn-sm"
                                    :style="selectedTplId === tpl.id ? 'border-color:var(--accent); background:rgba(59,130,246,0.15); color:var(--accent);' : ''"
                                    @click="selectTemplate(tpl)"
                                    style="justify-content:flex-start; text-align:left; white-space:normal; height:auto; padding:0.5rem 0.75rem; line-height:1.3;">
                                    <span x-text="tpl.name" style="font-size:0.78rem;"></span>
                                </button>
                            </template>
                        </div>
                    </div>

                    {{-- Message --}}
                    <div class="form-group">
                        <label class="form-label" style="display:flex; justify-content:space-between;">
                            <span>Pesan (dapat diedit bebas)</span>
                            <span style="font-size:0.7rem; color:var(--text-muted);" x-text="message.length + ' karakter'"></span>
                        </label>
                        <textarea name="message" class="form-textarea" rows="16"
                            x-model="message"
                            placeholder="Pilih template di atas..."
                            required style="font-size:0.78rem; line-height:1.5;"></textarea>
                        <div class="text-muted text-sm" style="margin-top:0.3rem;">
                            Mendukung HTML Telegram: &lt;b&gt;, &lt;i&gt;, &lt;code&gt;, &lt;a href&gt;
                        </div>
                    </div>

                    {{-- Evidence --}}
                    <div class="form-group" style="margin-top:-0.5rem; margin-bottom:1.5rem;">
                        <label class="form-label" style="display:flex; justify-content:space-between; align-items:center;">
                            <span>Lampirkan evidence (opsional)</span>
                            <button type="button" class="btn btn-ghost btn-sm" @click="addEvidence()" style="color:var(--accent);">+ Tambah</button>
                        </label>
                        <div style="display:flex; flex-direction:column; gap:0.5rem;">
                            <template x-for="(ev, index) in evidences" :key="ev.id">
                                <div style="display:flex; gap:0.5rem; align-items:center;">
                                    <input type="file" name="evidence[]" class="form-input" style="padding:0.4rem; background:var(--bg-body); border-style:dashed; flex:1;" accept="image/*">
                                    <button type="button" class="btn btn-danger btn-sm" x-show="evidences.length > 1" @click="removeEvidence(index)" title="Hapus" aria-label="Hapus evidence"><i data-lucide="x" aria-hidden="true"></i></button>
                                </div>
                            </template>
                        </div>
                        <div class="text-muted text-sm" style="margin-top:0.3rem;">
                            Gambar akan dikirim secara berurutan setelah pesan notifikasi.
                        </div>
                    </div>

                    <div class="flex gap-2" style="justify-content:flex-end;">
                        <button type="button" class="btn btn-ghost" @click="showNotifModal=false">Batal</button>
                        <button type="submit" class="btn btn-primary" :disabled="chatIds.length === 0"><i data-lucide="send" aria-hidden="true"></i> Kirim ke Telegram</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

</div>

@endsection

@push('scripts')
<script>
function detailNotifModal(alertId) {
    return {
        showIgnoreModal: false,
        showNotifModal:  false,
        loading:         false,
        // Read from window variable (HTML-safe, no broken double-quotes)
        chatIds:         window.__siemChatIds || [],
        templates:       [],
        selectedTplId:   null,
        message:         '',
        evidences:       [{id: Date.now()}],

        async openNotifModal() {
            this.showNotifModal = true;
            if (this.templates.length > 0) return;
            this.loading = true;
            try {
                const res  = await fetch(`/alerts/${alertId}/template`);
                const data = await res.json();
                this.templates = data.templates || [];
                if (data.chat_ids && data.chat_ids.length > 0) {
                    this.chatIds = data.chat_ids;
                    window.__siemChatIds = data.chat_ids;
                }
                if (this.templates.length > 0) this.selectTemplate(this.templates[0]);
            } catch(e) { console.error('detailNotifModal error:', e); }
            this.loading = false;
        },

        selectTemplate(tpl) {
            this.selectedTplId = tpl.id;
            this.message       = tpl.body;
        },

        addEvidence() {
            this.evidences.push({id: Date.now()});
        },

        removeEvidence(index) {
            this.evidences.splice(index, 1);
        }
    }
}

// ── Raw Alert Data Viewer ──────────────────────────────────────────────────
function rawDataViewer() {
    // Raw data injected server-side via JSON script tag
    const raw = @json($alert->raw_data ?? []);
    const analysisUrl = @js(route('alerts.ai-analysis', $alert, false));
    const storedAnalysis = @js($alert->latestAiAnalysis?->toResult());

    return {
        mode:        'json',    // 'json' | 'table' | 'ai'
        copied:      false,
        highlighted: '',
        flatRows:    [],
        aiLoading:   false,
        aiError:     '',
        aiResult:    storedAnalysis,

        init() {
            this.highlighted = this.syntaxHighlight(raw);
            this.flatRows    = this.flatten(raw, '');
        },

        openAi() {
            this.mode = 'ai';

            if (!this.aiLoading && !this.aiResult && !this.aiError) {
                this.analyseWithAi();
            }
        },

        /* Copy raw JSON to clipboard */
        copyRaw() {
            const text = JSON.stringify(raw, null, 2);

            const fallbackCopy = () => {
                const ta = document.createElement('textarea');
                ta.value = text;
                ta.style.position = 'fixed';
                ta.style.left = '-9999px'; // Move out of view safely
                document.body.appendChild(ta);
                ta.select();
                try {
                    document.execCommand('copy');
                    this.copied = true;
                    setTimeout(() => { this.copied = false; }, 2200);
                } catch (err) {
                    console.error('Fallback copy failed', err);
                }
                document.body.removeChild(ta);
            };

            // navigator.clipboard is undefined on non-HTTPS origins (like .test)
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text)
                    .then(() => {
                        this.copied = true;
                        setTimeout(() => { this.copied = false; }, 2200);
                    })
                    .catch(() => fallbackCopy());
            } else {
                fallbackCopy();
            }
        },

        async analyseWithAi(force = false) {
            if (this.aiLoading) return;

            this.aiLoading = true;
            this.aiError = '';
            this.aiResult = null;

            try {
                const response = await fetch(analysisUrl, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    },
                    body: JSON.stringify({ force }),
                });
                const payload = await response.json().catch(() => ({}));

                if (!response.ok || !payload.success) {
                    throw new Error(payload.error ?? payload.message ?? 'Analisis AI tidak dapat diselesaikan.');
                }

                this.aiResult = payload.analysis;
            } catch (error) {
                this.aiError = error?.message || 'Analisis AI tidak dapat diselesaikan.';
            } finally {
                this.aiLoading = false;
            }
        },

        analysisMeta(result) {
            if (!result?.generated_at) return '';

            const generatedAt = new Date(result.generated_at).toLocaleString('id-ID', {
                dateStyle: 'medium',
                timeStyle: 'short',
            });
            const duration = Number.isFinite(result.duration_ms)
                ? ` · ${(result.duration_ms / 1000).toFixed(1)} detik`
                : '';

            return `Disimpan ${generatedAt}${duration}`;
        },

        verdictLabel(verdict) {
            return {
                malicious: 'Malicious',
                likely_malicious: 'Likely malicious',
                suspicious: 'Suspicious',
                likely_legitimate: 'Likely legitimate',
                legitimate: 'Legitimate',
                inconclusive: 'Inconclusive',
            }[verdict] ?? 'Inconclusive';
        },

        /* Syntax-highlight JSON string with <span> classes */
        syntaxHighlight(obj) {
            const json = JSON.stringify(obj, null, 2)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;');
            return json.replace(
                /("(\\u[a-zA-Z0-9]{4}|\\[^u]|[^\\"])*"(\s*:)?|\b(true|false|null)\b|-?\d+(?:\.\d*)?(?:[eE][+\-]?\d+)?)/g,
                (match) => {
                    let cls = 'j-num';
                    if (/^"/.test(match)) {
                        cls = /:$/.test(match) ? 'j-key' : 'j-str';
                    } else if (/true|false/.test(match)) {
                        cls = 'j-bool';
                    } else if (/null/.test(match)) {
                        cls = 'j-null';
                    }
                    return `<span class="${cls}">${match}</span>`;
                }
            );
        },

        /* Flatten nested JSON into [path, value] rows for Table view */
        flatten(obj, prefix) {
            const rows = [];
            for (const [k, v] of Object.entries(obj || {})) {
                const path = prefix ? `${prefix}.${k}` : k;
                if (v !== null && typeof v === 'object' && !Array.isArray(v)) {
                    rows.push(...this.flatten(v, path));
                } else if (Array.isArray(v)) {
                    rows.push([path, JSON.stringify(v)]);
                } else {
                    rows.push([path, v === null ? 'null' : String(v)]);
                }
            }
            return rows;
        }
    };
}
</script>
@endpush
