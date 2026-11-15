@extends('layouts.app')
@section('body-class', 'density-default')

@section('title', 'Detail Alert #' . $alert->id)
@section('page-context', 'Incident Queue')
@section('page-title', 'Detail Alert')
@section('page-subtitle', 'Alert #' . $alert->id)

@section('content')

<div class="alert-grid-main">

    {{-- ─── Left: Alert Detail ─────────────────────────────────────────── --}}
    <div style="min-width: 0;">
        <div class="card" style="margin-bottom:var(--spacing-24);">
            <div class="card-header alert-header-actions">
                <div class="alert-header-badges">
                    <span class="badge {{ $alert->status_badge_class }}" style="font-size:var(--text-sm);line-height:var(--leading-sm);">{{ $alert->status_label }}</span>
                    <span class="badge {{ $alert->level_badge_class }}" style="font-size:var(--text-sm);line-height:var(--leading-sm);">Level {{ $alert->rule_level }}</span>
                    <span class="text-muted text-sm" style="margin-left:var(--spacing-8);">
                        #{{ $alert->id }} · {{ $alert->first_seen_at?->setTimezone('Asia/Jakarta')->format('d M Y, H:i:s') ?? '-' }} WIB
                    </span>
                </div>
                <a href="{{ route('alerts.index') }}" class="btn btn-ghost btn-sm ml-auto"><i data-lucide="arrow-left" aria-hidden="true"></i> Kembali</a>
            </div>
            <div class="card-body">
                <h2 style="font-size:var(--text-xl); font-weight:var(--font-weight-bold); margin-bottom:var(--spacing-24); color:var(--text-primary); line-height:var(--leading-xl);">
                    {{ $alert->rule_description ?? 'No Description' }}
                </h2>
                <div class="alert-grid-info">
                    <div>
                        <div class="form-label">Rule ID</div>
                        <div style="font-family:var(--font-mono); color:var(--accent); font-size:var(--text-lg); font-weight:var(--font-weight-bold); line-height:var(--leading-lg);">{{ $alert->rule_id ?? '-' }}</div>
                    </div>
                    <div>
                        <div class="form-label">Rule Level</div>
                        <div style="font-size:var(--text-section); font-weight:var(--font-weight-bold); color:{{ $alert->rule_level >= 15 ? 'var(--danger)' : ($alert->rule_level >= 12 ? 'var(--warning)' : 'var(--success)') }}; line-height:var(--leading-section)">
                            {{ $alert->rule_level }}
                        </div>
                    </div>
                    <div>
                        <div class="form-label">Agent</div>
                        <div style="font-weight:var(--font-weight-bold);">{{ $alert->agent_name ?? '-' }}</div>
                    </div>
                    <div>
                        <div class="form-label">Wazuh Alert ID</div>
                        <div style="font-family:var(--font-mono); font-size:var(--text-xs); color:var(--text-muted); word-break:break-all; line-height:var(--leading-xs);">{{ $alert->wazuh_alert_id }}</div>
                    </div>
                    <div>
                        <div class="form-label">Source IP</div>
                        <div style="font-family:var(--font-mono); color:var(--info); font-size:var(--text-lg); font-weight:var(--font-weight-bold); line-height:var(--leading-lg);">{{ $alert->src_ip ?? '-' }}</div>
                    </div>
                    <div>
                        <div class="form-label">Destination IP</div>
                        <div style="font-family:var(--font-mono); color:var(--warning); font-size:var(--text-lg); font-weight:var(--font-weight-bold); line-height:var(--leading-lg);">{{ $alert->dst_ip ?? '-' }}</div>
                    </div>
                </div>

                @php $raw = $alert->raw_data ?? []; $syscheck = $raw['syscheck'] ?? []; $data = $raw['data'] ?? []; @endphp
                @if(!empty($syscheck['path']) || !empty($data['srcuser']) || !empty($data['dstuser']))
                <hr class="divider">
                <div class="alert-grid-extra">
                    @if(!empty($syscheck['path']))<div><div class="form-label">Path</div><div style="font-family:var(--font-mono); font-size:var(--text-sm); word-break:break-all; line-height:var(--leading-sm);">{{ $syscheck['path'] }}</div></div>@endif
                    @if(!empty($data['dstuser']))<div><div class="form-label">Dest User</div><div>{{ $data['dstuser'] }}</div></div>@endif
                    @if(!empty($data['srcuser']))<div><div class="form-label">Src User</div><div>{{ $data['srcuser'] }}</div></div>@endif
                </div>
                @endif
            </div>
        </div>

        {{-- ─── Raw Alert Data Card ─── --}}
        @include('partials.alert-evidence', [
            'evidence' => $alert->raw_data ?? [],
            'analysisUrl' => route('alerts.ai-analysis', $alert, false),
            'storedAnalysis' => $alert->latestAiAnalysis?->toResult(),
        ])
    </div>

    {{-- ─── Right: Actions + History ───────────────────────────────────── --}}
    <div x-data="notificationComposer(@js(['templateUrl' => route('alerts.template', $alert), 'sendUrl' => route('alerts.notify', $alert), 'operationId' => (string) Illuminate\Support\Str::uuid()]))">

        {{-- Actions --}}
        <div class="card" style="margin-bottom:var(--spacing-24);">
            <div class="card-header"><span class="card-title">Triage actions</span></div>
            <div class="card-body" style="display:flex; flex-direction:column; gap:var(--spacing-12);">
                <p class="text-muted text-sm">{{ $alert->acknowledgedBy ? 'Pemilik ACK: '.$alert->acknowledgedBy->name : 'ACK terlebih dahulu untuk ignore/notifikasi alert' }}.</p>
                @if(($alert->status === 'new' || ($alert->status === 'acknowledged' && !$alert->acknowledged_by_user_id)) && auth()->user()->can('acknowledge', $alert))
                <form method="POST" action="{{ route('alerts.acknowledge', $alert) }}">
                    @csrf
                    <button type="submit" class="btn btn-warning w-full" style="width:100%; justify-content:center;">Acknowledge alert</button>
                </form>
                @endif

                @if($alert->status !== 'ignored' && auth()->user()->can('ignore', $alert))
                <button class="btn btn-danger w-full" style="width:100%; justify-content:center;" @click="showIgnoreModal=true"><i data-lucide="x" aria-hidden="true"></i> Abaikan alert</button>
                @endif

                @can('notify', $alert)
                <button type="button" class="btn btn-primary w-full" style="width:100%; justify-content:center;" @click="openNotifModal()" aria-label="Kirim notifikasi">Kirim notifikasi Telegram
                </button>
                @endcan
                @if(!auth()->user()->hasPrivilege('alerts.acknowledge') && !auth()->user()->hasPrivilege('alerts.ignore') && !auth()->user()->hasPrivilege('alerts.notify'))
                <p class="text-muted text-sm">Akses akun Anda hanya untuk membaca alert ini.</p>
                @endif
            </div>
        </div>

        {{-- Triage History --}}
        <div class="card" style="margin-bottom:var(--spacing-24);">
            <div class="card-header"><span class="card-title">Riwayat triage</span></div>
            <div class="card-body" style="padding:var(--spacing-0);">
                @forelse($alert->triages as $triage)
                <div style="padding:var(--spacing-16) var(--spacing-24); border-bottom:1px solid var(--border);">
                    <div class="flex items-center gap-2 mb-1" style="flex-wrap: wrap;">
                        <span class="badge {{ $triage->action === 'ignore' ? 'badge-ignored' : ($triage->action === 'acknowledge' ? 'badge-acknowledged' : 'badge-new') }}" style="font-size:var(--text-xs);line-height:var(--leading-xs);">{{ strtoupper($triage->action) }}</span>
                        <span style="font-size:var(--text-xs); font-weight:var(--font-weight-bold); line-height:var(--leading-xs);">{{ $triage->user->name ?? 'Unknown' }}</span>
                        <span class="text-muted text-sm ml-auto">{{ $triage->created_at?->setTimezone('Asia/Jakarta')->diffForHumans() }}</span>
                    </div>
                    @if($triage->reason)<div style="font-size:var(--text-xs); color:var(--text-muted); line-height:var(--leading-xs);">{{ $triage->reason }}</div>@endif
                </div>
                @empty
                <div style="padding:var(--spacing-24); text-align:center; color:var(--text-muted); font-size:var(--text-sm); line-height:var(--leading-sm);">Belum ada triage</div>
                @endforelse
            </div>
        </div>

        {{-- Notification History --}}
        <div class="card">
            <div class="card-header"><span class="card-title">Riwayat notifikasi</span></div>
            <div class="card-body" style="padding:var(--spacing-0);">
                @forelse($alert->notificationLogs as $log)
                <div style="padding:var(--spacing-16) var(--spacing-24); border-bottom:1px solid var(--border);">
                    <div class="flex items-center gap-2 mb-1" style="flex-wrap: wrap;">
                        <span style="font-size:var(--text-xs); color:var(--info); line-height:var(--leading-xs); overflow-wrap:anywhere;">{{ $telegramLabels[$log->chat_id] ?? 'Tujuan Telegram tidak lagi terdaftar' }}</span>
                        @include('partials.notification-result', ['state' => $log->delivery_state, 'status' => $log->response_status])
                        <span class="text-muted text-sm ml-auto">{{ $log->sent_at?->setTimezone('Asia/Jakarta')->diffForHumans() }}</span>
                    </div>
                    <div style="font-size:var(--text-xs); color:var(--text-muted); line-height:var(--leading-xs);">oleh {{ $log->automatic_key ? 'Sistem (otomatis)' : ($log->user->name ?? 'Unknown') }}</div>
                    <details class="notification-sent-message"><summary class="text-sm">Lihat pesan yang dikirim</summary><pre class="text-sm">{{ $log->visibleMessage(auth()->user()) }}</pre></details>
                    @if($log->manual_operation_id)
                        @if(isset($log->delivery_details['bulk_group']))
                            <div class="text-muted text-sm">Bagian dari satu notifikasi gabungan ke tujuan ini.</div>
                        @endif
                        <div class="text-muted text-sm" style="overflow-wrap:anywhere;">Operasi {{ $log->manual_operation_id }}</div>
                        @foreach($log->delivery_details['evidence'] ?? [] as $evidence)
                            <div class="text-sm" style="margin-top:var(--spacing-8); overflow-wrap:anywhere;">
                                {{ $evidence['name'] }}
                                @include('partials.notification-result', ['state' => $evidence['state'], 'status' => $evidence['status'] ?? null])
                            </div>
                        @endforeach
                    @endif
                </div>
                @empty
                <div style="padding:var(--spacing-24); text-align:center; color:var(--text-muted); font-size:var(--text-sm); line-height:var(--leading-sm);">Belum ada notifikasi dikirim</div>
                @endforelse
            </div>
        </div>

        {{-- ── IGNORE MODAL ── --}}
        @can('ignore', $alert)
        <div class="modal-backdrop" x-show="showIgnoreModal" x-cloak @click.self="showIgnoreModal=false" @keydown.escape.window="showIgnoreModal=false" role="dialog" aria-modal="true" aria-label="Abaikan alert">
            <div class="modal-box" @click.stop>
                <div class="modal-title"><i data-lucide="circle-alert" aria-hidden="true"></i> Abaikan alert #{{ $alert->id }}</div>
                <form method="POST" action="{{ route('alerts.ignore', $alert) }}">
                    @csrf
                    <div class="form-group">
                        <label class="form-label">Alasan Mengabaikan *</label>
                        <textarea aria-label="Alasan Mengabaikan *" name="reason" class="form-textarea" rows="4" placeholder="Contoh: False positive, IP internal..." required minlength="5"></textarea>
                    </div>
                    <div class="flex gap-2" style="justify-content:flex-end;">
                        <button type="button" class="btn btn-ghost" @click="showIgnoreModal=false">Batal</button>
                        <button type="submit" class="btn btn-danger">Abaikan</button>
                    </div>
                </form>
            </div>
        </div>

        {{-- ── NOTIFY MODAL ── --}}
        @endcan
        @can('notify', $alert)
        @include('partials.notification-dialog', ['notificationAction' => route('alerts.notify', $alert), 'notificationTitle' => 'Kirim notifikasi Telegram - alert #'.$alert->id])
        @endcan
    </div>

</div>

@endsection
