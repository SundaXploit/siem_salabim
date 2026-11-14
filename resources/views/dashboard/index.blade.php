@extends('layouts.app')
@section('body-class', 'density-dense live-alerts-page')

@section('title', 'Live Alerts')
@section('page-context', 'Incident Queue')
@section('page-title', 'Live Alerts')
@section('page-subtitle', 'Real-time monitoring · Auto-refresh tiap 30 detik')

@php
    $canTriage = auth()->user()?->isAdmin() || auth()->user()?->isAnalyst();
@endphp

@section('content')

{{-- Stats Cards --}}
<div class="stats-grid" x-data="alertStats()" data-alert-stats="{{ json_encode($stats) }}"
     @alerts-updated.window="stats = $event.detail">
    <div class="stat-card red">
        <div class="stat-label">New</div>
        <div class="stat-value" x-text="stats.new">{{ $stats['new'] }}</div>
        <div class="stat-sub">Alert belum ditangani</div>
    </div>
    <div class="stat-card yellow">
        <div class="stat-label">Acknowledged</div>
        <div class="stat-value" x-text="stats.ack">{{ $stats['ack'] }}</div>
        <div class="stat-sub">Sedang diinvestigasi</div>
    </div>
    <div class="stat-card gray">
        <div class="stat-label">Ignored</div>
        <div class="stat-value" x-text="stats.ignored">{{ $stats['ignored'] }}</div>
        <div class="stat-sub">Alert diabaikan</div>
    </div>
    <div class="stat-card blue">
        <div class="stat-label">Total events</div>
        <div class="stat-value" x-text="stats.total">{{ $stats['total'] }}</div>
        <div class="stat-sub">Semua alert</div>
    </div>
</div>

{{-- Filter Bar --}}
<div class="card mb-3" style="margin-bottom: var(--spacing-16);">
    <div class="card-body">
        <form method="GET" action="{{ route('alerts.index') }}" id="filterForm"
            x-data="liveAlertFilters()" @submit.prevent="submitFilters($el)">
            <div class="filter-bar">
                <div style="flex:1; min-width:200px;">
                    <label class="form-label" for="dashboard-index-search">Cari event</label>
                    <input id="dashboard-index-search" type="text" name="search" class="form-input search-input"
                        placeholder="Cari ID, rule, IP, agent, atau isi event..."
                        autocomplete="off"
                        @input.debounce.700ms="submitSearch($el)"
                        value="{{ request('search') }}">
                </div>
                <div>
                    <label class="form-label" for="dashboard-index-status">Status</label>
                    <select id="dashboard-index-status" name="status" class="form-select" onchange="this.form.requestSubmit()">
                        <option value="">Active (Non-Ignored)</option>
                        <option value="new"          {{ request('status') === 'new'          ? 'selected' : '' }}>NEW</option>
                        <option value="acknowledged" {{ request('status') === 'acknowledged' ? 'selected' : '' }}>ACKNOWLEDGED</option>
                        <option value="ignored"      {{ request('status') === 'ignored'      ? 'selected' : '' }}>IGNORED</option>
                        <option value="all"          {{ request('status') === 'all'          ? 'selected' : '' }}>SEMUA</option>
                    </select>
                </div>
                <div>
                    <label class="form-label" for="dashboard-index-level">Min Level</label>
                    <select id="dashboard-index-level" name="level" class="form-select" onchange="this.form.requestSubmit()">
                        <option value="">Semua Level</option>
                        <option value="12" {{ request('level') == 12 ? 'selected' : '' }}>≥ 12</option>
                        <option value="13" {{ request('level') == 13 ? 'selected' : '' }}>≥ 13</option>
                        <option value="14" {{ request('level') == 14 ? 'selected' : '' }}>≥ 14</option>
                        <option value="15" {{ request('level') == 15 ? 'selected' : '' }}>≥ 15 (Critical)</option>
                    </select>
                </div>
                <div>
                    <label class="form-label" for="dashboard-index-date_from">Dari Tanggal</label>
                    <input id="dashboard-index-date_from" type="date" name="date_from" class="form-input" value="{{ request('date_from') }}" onchange="this.form.requestSubmit()">
                </div>
                <div>
                    <label class="form-label" for="dashboard-index-date_to">Sampai</label>
                    <input id="dashboard-index-date_to" type="date" name="date_to" class="form-input" value="{{ request('date_to') }}" onchange="this.form.requestSubmit()">
                </div>
                <div style="display:flex; gap:var(--spacing-8); align-items:flex-end;">
                    <button type="submit" class="btn btn-primary">Filter</button>
                    <a href="{{ route('alerts.index') }}" class="btn btn-ghost">Reset</a>
                </div>
                <div>
                    <label class="form-label" for="alerts-per-page">Per halaman</label>
                    <select id="alerts-per-page" name="per_page" class="form-select" onchange="this.form.requestSubmit()">
                        @foreach([25, 50, 100] as $size)
                            <option value="{{ $size }}" @selected($alerts->perPage() === $size)>{{ $size }} alert</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </form>
    </div>
</div>

{{-- Alert Table --}}
<div class="card" x-data="alertTable(@js(route('alerts.bulk-triage', [], false)))">
    <div class="card-header">
        <span class="card-title">Daftar alert</span>
        <span class="text-muted text-sm" style="margin-left:var(--spacing-8);" id="alert-total-count">
            {{ $alerts->total() }} alert ditemukan
        </span>
        <div class="ml-auto flex gap-2 items-center">
            @if($canTriage)
            <button type="button" class="btn btn-secondary btn-sm" @click="startBulkMode()"
                    x-show="!bulkMode" :disabled="isRefreshing" aria-controls="bulk-triage-controls"
                    :aria-expanded="bulkMode.toString()">Triage massal</button>
            @endif
            <span class="text-muted text-sm" x-text="refreshText">Refresh dalam 30s</span>
            <button class="btn btn-ghost btn-sm" @click="refreshNow()" :disabled="isRefreshing || refreshPaused"><i data-lucide="refresh-cw" aria-hidden="true"></i> Refresh</button>
        </div>
    </div>

    @if($canTriage)
    <div class="bulk-triage-bar" id="bulk-triage-controls" aria-label="Triage massal" x-show="bulkMode" x-cloak>
        <div class="bulk-triage-summary">
            <strong>Triage massal</strong>
            <span class="text-muted text-sm" aria-live="polite"
                  x-text="selectedIds.length ? `${selectedIds.length} alert dipilih pada halaman ini` : 'Pilih alert melalui checkbox. Maksimal 100 per halaman.'"></span>
        </div>
        <div class="bulk-triage-actions">
            <button type="button" class="btn btn-ghost btn-sm" @click="cancelBulkMode()"
                    :disabled="submitting">Batal triage</button>
            <form method="GET" action="{{ route('alerts.bulk-detail') }}">
                <template x-for="id in selectedIds" :key="id"><input type="hidden" name="alert_ids[]" :value="id"></template>
                <button type="submit" class="btn btn-secondary btn-sm" :disabled="!selectedIds.length || submitting || isRefreshing" data-bulk-detail>
                    <i data-lucide="eye" aria-hidden="true"></i> Detail & analisis
                </button>
            </form>
            @can('alerts.acknowledge')
            <button type="button" class="btn btn-primary btn-sm" @click="openBulk('acknowledge')"
                    :disabled="!selectedIds.length || submitting || isRefreshing">ACK massal
            </button>
            @endcan
            @can('alerts.ignore')
            <button type="button" class="btn btn-danger btn-sm" @click="openBulk('ignore')"
                    :disabled="!selectedIds.length || submitting || isRefreshing">
                <i data-lucide="x" aria-hidden="true"></i> Abaikan massal
            </button>
            @endcan
        </div>
    </div>
    <p class="bulk-triage-feedback" x-show="bulkMessage" x-text="bulkMessage" role="status" x-cloak></p>

    <dialog class="bulk-triage-dialog" x-ref="bulkDialog" aria-labelledby="bulk-triage-title"
            @cancel.prevent="closeBulk()">
        <form @submit.prevent="submitBulk()" :aria-busy="submitting.toString()">
            <h2 class="modal-title" id="bulk-triage-title"
                x-text="bulkAction === 'ignore' ? 'Abaikan alert terpilih' : 'ACK alert terpilih'"></h2>
            <p class="text-sm" x-text="`${selectedIds.length} alert dipilih pada halaman ini.`"></p>
            <p class="text-muted text-sm bulk-triage-help"
               x-text="bulkAction === 'ignore' ? 'Alasan yang sama akan dicatat untuk setiap alert. Alert yang sudah diabaikan akan dilewati.' : 'Hanya alert berstatus NEW yang di-ACK. Alert yang sudah ditangani akan dilewati.'"></p>
            <div class="form-group" :hidden="bulkAction !== 'ignore'">
                <label class="form-label" for="bulk-triage-reason">Alasan mengabaikan *</label>
                <textarea id="bulk-triage-reason" class="form-textarea" rows="4" x-ref="bulkReason"
                          x-model="bulkReason" :required="bulkAction === 'ignore'" minlength="5" maxlength="1000"
                          :disabled="submitting" placeholder="Contoh: Aktivitas pemeliharaan yang sudah dikonfirmasi."></textarea>
            </div>
            <p class="bulk-triage-error" x-show="bulkError" x-text="bulkError" role="alert"></p>
            <div class="bulk-triage-actions">
                <button type="button" class="btn btn-ghost" @click="closeBulk()" :disabled="submitting">Batal</button>
                <button type="submit" class="btn" x-ref="bulkConfirm"
                        :class="bulkAction === 'ignore' ? 'btn-danger' : 'btn-primary'" :disabled="submitting"
                        x-text="submitting ? 'Memproses…' : (bulkAction === 'ignore' ? 'Abaikan' : 'ACK') + ` ${selectedIds.length} alert`"></button>
            </div>
        </form>
    </dialog>
    @endif
    <p class="bulk-triage-feedback bulk-triage-error" x-show="refreshError" x-text="refreshError" role="alert" x-cloak></p>

    <div id="alert-table-card-body">
        <div style="overflow-x:auto;">
            <table class="data-table">
            <thead>
                <tr>
                    @if($canTriage)
                    <th scope="col" class="triage-checkbox-cell" x-show="bulkMode" x-cloak>
                        <label class="triage-check-target">
                            <input type="checkbox" class="triage-checkbox" aria-label="Pilih semua alert yang dapat ditangani pada halaman ini"
                                   :checked="allSelected" x-effect="$el.indeterminate = selectedIds.length > 0 && !allSelected"
                                   @change="toggleAll($event.target.checked)" :disabled="!pageIds.length || submitting || isRefreshing">
                        </label>
                    </th>
                    @endif
                    <th scope="col">Rule Description</th>
                    <th scope="col">Source IP</th>
                    <th scope="col">Dest IP</th>
                    <th scope="col">Agent</th>
                    <th scope="col">Rule ID</th>
                    <th scope="col">Level</th>
                    <th scope="col">Waktu</th>
                    <th scope="col">Status</th>
                    <th scope="col">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse($alerts as $alert)
                <tr class="row-{{ $alert->status }}" id="alert-row-{{ $alert->id }}"
                    :class="{ 'triage-selected': selectedIds.includes('{{ $alert->id }}') }">
                    @if($canTriage)
                    <td class="triage-checkbox-cell" x-show="bulkMode" x-cloak>
                        <label class="triage-check-target"><input type="checkbox" class="triage-checkbox" value="{{ $alert->id }}" aria-label="Pilih alert #{{ $alert->id }}"
                               @if($alert->status === 'ignored')
                                   disabled title="Alert sudah diabaikan"
                               @else
                                   data-triage-id="{{ $alert->id }}" x-model="selectedIds"
                                   :disabled="submitting || isRefreshing"
                               @endif></label>
                    </td>
                    @endif
                    <td>
                        <a href="{{ route('alerts.show', $alert) }}" style="color: var(--text-primary); text-decoration:none; font-weight:var(--font-weight-bold);" title="{{ $alert->rule_description }}">
                            <div class="truncate-cell">{{ $alert->rule_description ?? '-' }}</div>
                        </a>
                    </td>
                    <td style="font-family:var(--font-mono); font-size:var(--text-xs); color:var(--info); line-height:var(--leading-xs);">{{ $alert->src_ip ?? '-' }}</td>
                    <td style="font-family:var(--font-mono); font-size:var(--text-xs); color:var(--warning); line-height:var(--leading-xs);">{{ $alert->dst_ip ?? '-' }}</td>
                    <td>{{ $alert->agent_name ?? '-' }}</td>
                    <td style="font-family:var(--font-mono); font-size:var(--text-xs); line-height:var(--leading-xs);">{{ $alert->rule_id ?? '-' }}</td>
                    <td>
                        <span class="table-level {{ $alert->level_badge_class }}" aria-label="Rule level {{ $alert->rule_level }}">
                            {{ $alert->rule_level }}
                        </span>
                    </td>
                    <td style="font-size:var(--text-xs); white-space:nowrap; line-height:var(--leading-xs);">
                        {{ $alert->first_seen_at ? $alert->first_seen_at->setTimezone('Asia/Jakarta')->format('d/m/Y H:i:s') : '-' }}
                    </td>
                    <td>
                        <span class="table-status {{ $alert->status_badge_class }}">{{ $alert->status_label }}</span>
                    </td>
                    <td>
                        {{-- ══════ Per-row Alpine component ══════ --}}
                        <div class="table-actions"
                             x-data="notifModal({{ $alert->id }})">

                            {{-- ACK --}}
                            @if($alert->status === 'new' && auth()->user()->can('acknowledge', $alert))
                            <form method="POST" action="{{ route('alerts.acknowledge', $alert) }}" style="display:inline;">
                                @csrf
                                <button type="submit" class="table-action" title="Acknowledge alert" aria-label="Acknowledge alert"><i data-lucide="check" aria-hidden="true"></i></button>
                            </form>
                            @endif

                            {{-- IGNORE --}}
                            @if($alert->status !== 'ignored' && auth()->user()->can('ignore', $alert))
                            <button type="button" class="table-action" @click="showIgnoreModal=true" title="Abaikan alert" aria-label="Abaikan alert"><i data-lucide="x" aria-hidden="true"></i></button>

                            <div class="modal-backdrop" x-show="showIgnoreModal" x-cloak @click.self="showIgnoreModal=false" @keydown.escape.window="showIgnoreModal=false" role="dialog" aria-modal="true" aria-label="Abaikan alert">
                                <div class="modal-box" @click.stop>
                                    <div class="modal-title"><i data-lucide="circle-alert" aria-hidden="true"></i> Abaikan alert #{{ $alert->id }}</div>
                                    <form method="POST" action="{{ route('alerts.ignore', $alert) }}">
                                        @csrf
                                        <div class="form-group">
                                            <label class="form-label">Alasan Mengabaikan *</label>
                                            <textarea name="reason" class="form-textarea" rows="4"
                                                placeholder="Contoh: False positive, IP internal..."
                                                required minlength="5"></textarea>
                                        </div>
                                        <div class="flex gap-2" style="justify-content:flex-end;">
                                            <button type="button" class="btn btn-ghost" @click="showIgnoreModal=false">Batal</button>
                                            <button type="submit" class="btn btn-danger">Abaikan Alert</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                            @endif

                            {{-- NOTIF Button --}}
                            @can('notify', $alert)
                            <button type="button" class="table-action"
                                    @click="openNotifModal()"
                                    title="Kirim notifikasi" aria-label="Kirim notifikasi">
                                <i data-lucide="send" aria-hidden="true"></i>
                            </button>

                            {{-- NOTIF MODAL --}}
                            <div class="modal-backdrop" x-show="showNotifModal" x-cloak @click.self="showNotifModal=false" @keydown.escape.window="showNotifModal=false" role="dialog" aria-modal="true" aria-label="Kirim notifikasi">
                                <div class="modal-box" style="max-width:700px;" @click.stop>
                                    <div class="modal-title"><i data-lucide="send" aria-hidden="true"></i> Kirim notifikasi — alert #{{ $alert->id }}</div>

                                    {{-- Loading --}}
                                    <div x-show="loading" style="text-align:center; padding:var(--spacing-32); color:var(--text-muted);">
                                        <i class="loading-state-icon" data-lucide="clock-3" aria-hidden="true"></i>
                                        Memuat template...
                                    </div>

                                    <form method="POST" action="{{ route('alerts.notify', $alert) }}" x-show="!loading" enctype="multipart/form-data">
                                        @csrf
                    <div class="form-group">
                        <span class="form-label">Tujuan otomatis</span>
                        <p class="text-sm" x-show="destinations.length" x-text="destinations.join(', ')"></p>
                        <p class="form-error" x-show="!destinations.length" x-text="notificationError || 'Belum ada tujuan untuk agent ini. Hubungi admin untuk mengatur Settings → Telegram.'"></p>
                    </div>


                                        {{-- ── Template Selector ── --}}
                                        <div class="form-group">
                                            <label class="form-label">Pilih template</label>
                                            <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(150px, 1fr)); gap:var(--spacing-8);">
                                                <template x-for="tpl in templates" :key="tpl.id">
                                                    <button type="button"
                                                        class="btn btn-ghost btn-sm"
                                                        :class="selectedTplId === tpl.id ? 'active-template' : ''"
                                                        :style="selectedTplId === tpl.id
                                                            ? 'border-color:var(--accent); background:var(--color-accent-soft); color:var(--accent);'
                                                            : ''"
                                                        @click="selectTemplate(tpl)"
                                                        style="justify-content:flex-start; text-align:left; white-space:normal; height:auto; padding:var(--spacing-8) var(--spacing-12); line-height:var(--ina-line-height-body-small);">
                                                        <span x-text="tpl.name"
                                                              style="font-size:var(--text-sm);line-height:var(--leading-sm);"></span>
                                                    </button>
                                                </template>
                                            </div>
                                        </div>

                                        {{-- ── Message Editor ── --}}
                                        <div class="form-group">
                                            <label class="form-label" style="display:flex; justify-content:space-between;">
                                                <span>Pesan (dapat diedit bebas)</span>
                                                <span style="font-size:var(--text-xs); color:var(--text-muted); line-height:var(--leading-xs);" x-text="message.length + ' karakter'"></span>
                                            </label>
                                            <textarea name="message" class="form-textarea"
                                                      rows="16"
                                                      x-model="message"
                                                      placeholder="Pilih template di atas, lalu edit sesuai kebutuhan..."
                                                      required style="font-size:var(--text-sm); line-height:var(--leading-sm);"></textarea>
                                            <div class="text-muted text-sm" style="margin-top:var(--spacing-4);">
                                                Mendukung HTML tag Telegram: &lt;b&gt;, &lt;i&gt;, &lt;code&gt;, &lt;a href&gt;
                                            </div>
                                        </div>

                                        {{-- ── Evidence Area ── --}}
                                        <div class="form-group" style="margin-top:calc(-1 * var(--spacing-8)); margin-bottom:var(--spacing-24);">
                                            <label class="form-label" style="display:flex; justify-content:space-between; align-items:center;">
                                                <span>Lampirkan evidence (opsional)</span>
                                                <button type="button" class="btn btn-ghost btn-sm" @click="addEvidence()" style="color:var(--accent);">+ Tambah</button>
                                            </label>
                                            <div style="display:flex; flex-direction:column; gap:var(--spacing-8);">
                                                <template x-for="(ev, index) in evidences" :key="ev.id">
                                                    <div style="display:flex; gap:var(--spacing-8); align-items:center;">
                                                        <input type="file" name="evidence[]" class="form-input" style="padding:var(--spacing-8); background:var(--bg-body); border-style:dashed; flex:1;" accept="image/*">
                                                        <button type="button" class="btn btn-danger btn-sm" x-show="evidences.length > 1" @click="removeEvidence(index)" title="Hapus" aria-label="Hapus evidence"><i data-lucide="x" aria-hidden="true"></i></button>
                                                    </div>
                                                </template>
                                            </div>
                                            <div class="text-muted text-sm" style="margin-top:var(--spacing-4);">
                                                Gambar akan dikirim secara berurutan setelah pesan notifikasi.
                                            </div>
                                        </div>

                                        <div class="flex gap-2" style="justify-content:flex-end;">
                                            <button type="button" class="btn btn-ghost" @click="showNotifModal=false">Batal</button>
                                            <button type="submit" class="btn btn-primary"
                                                    :disabled="destinations.length === 0 || loading">Kirim ke Telegram
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>

                            {{-- Detail --}}
                            @endcan
                            <a href="{{ route('alerts.show', $alert) }}" class="table-action" title="Lihat detail alert" aria-label="Lihat detail alert"><i data-lucide="eye" aria-hidden="true"></i></a>
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="9" :colspan="{{ $canTriage ? 'bulkMode ? 10 : 9' : '9' }}" style="text-align:center; padding:var(--spacing-48); color:var(--text-muted);">
                        <div class="empty-state"><i data-lucide="circle-check" aria-hidden="true"></i></div>
                        Tidak ada alert yang cocok dengan filter saat ini.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
        </div>

        @if($alerts->hasPages())
        <div style="padding:var(--spacing-16) var(--spacing-24); border-top:1px solid var(--border);">
            {{ $alerts->links('pagination::bootstrap-5') }}
        </div>
        @endif
    </div>
</div>

@endsection

@push('scripts')
<script>
// ── Alert stats polling ─────────────────────────────────────────────
function alertStats() {
    return {
        stats: {
            new:     {{ $stats['new'] }},
            ack:     {{ $stats['ack'] }},
            ignored: {{ $stats['ignored'] }},
            total:   {{ $stats['total'] }},
        },
        timer: null,
        init() { this.timer = setInterval(() => this.fetchStats(), 30000); },
        destroy() { clearInterval(this.timer); },
        async fetchStats() {
            try {
                const res  = await fetch('/api/alerts/new-count');
                const data = await res.json();
                this.stats.new = data.count;
            } catch(e) {}
        }
    }
}

function liveAlertFilters() {
    return {
        submitSearch(input) {
            const currentSearch = new URLSearchParams(window.location.search).get('search') ?? '';
            if (input.value.trim() === currentSearch.trim()) return;

            input.form.requestSubmit();
        },
        submitFilters(form) {
            const params = new URLSearchParams();

            for (const [name, value] of new FormData(form).entries()) {
                const normalized = typeof value === 'string' ? value.trim() : value;

                if (normalized !== '') {
                    params.append(name, normalized);
                }
            }

            const query = params.toString();
            window.location.assign(query ? `${form.action}?${query}` : form.action);
        },
    };
}

// ── Auto-refresh countdown ──────────────────────────────────────────
// ── Notification modal per row ──────────────────────────────────────
function notifModal(alertId) {
    return {
        showIgnoreModal: false,
        showNotifModal:  false,
        loading:         false,
        destinations: [],
        notificationError: '',

        templates:    [],
        selectedTplId: null,
        message:      '',
        evidences:    [{id: Date.now()}],

        async openNotifModal() {
            this.showNotifModal = true;

            this.destinations = [];
            this.notificationError = '';
            this.loading = true;
            try {
                const res  = await fetch(`/alerts/${alertId}/template`);
                if (!res.ok || res.redirected) throw new Error('Tidak dapat memuat tujuan notifikasi.');
                const data = await res.json();

                this.templates = data.templates || [];
                this.destinations = data.destinations || [];
                if (!this.message) {
                    if (this.templates.length) this.selectTemplate(this.templates[0]);
                    else this.message = data.default_message || '';
                }
            } catch(e) {
                this.notificationError = 'Tujuan notifikasi belum dapat dimuat. Coba buka kembali.';
            }
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
</script>
@endpush
