@extends('layouts.app')
@section('body-class', 'density-default')
@section('title', 'Detail & Analisis Massal')
@section('page-context', 'Incident Queue')
@section('page-title', 'Detail & analisis massal')
@section('page-subtitle', $alerts->count().' alert terpilih · Tinjau keterkaitan log sebelum mengambil keputusan triage.')

@section('content')
@php
    $ids = $alerts->modelKeys();
    $newCount = $alerts->filter(fn ($alert) => $alert->status === 'new' || ($alert->status === 'acknowledged' && !$alert->acknowledged_by_user_id))->count();
    $ignoreCount = $alerts->filter(fn ($alert) => $alert->isAcknowledgedBy(auth()->user()))->count();
    $agents = $alerts->groupBy(fn ($alert) => $alert->agent_name ?: 'Tidak diketahui');
    $ips = $alerts->pluck('src_ip')->merge($alerts->pluck('dst_ip'))->filter()->unique()->values();
    $evidence = $alerts->mapWithKeys(fn ($alert) => ['alert_'.$alert->id => $alert->raw_data ?? []])->all();
@endphp
<div class="alert-grid-main bulk-alert-detail">
    <div class="bulk-alert-main">
        <div class="card">
            <div class="card-header alert-header-actions">
                <span class="card-title">Ringkasan pilihan</span>
                <a href="{{ route('alerts.index') }}" class="btn btn-ghost btn-sm ml-auto"><i data-lucide="arrow-left" aria-hidden="true"></i> Live Alerts</a>
            </div>
            <div class="card-body">
                <div class="bulk-alert-stats">
                    <div><span>Alert dipilih</span><strong>{{ $alerts->count() }}</strong></div>
                    <div><span>Agent</span><strong>{{ $agents->count() }}</strong></div>
                    <div><span>Rule berbeda</span><strong>{{ $alerts->pluck('rule_id')->unique()->count() }}</strong></div>
                    <div><span>Level tertinggi</span><strong>{{ $alerts->max('rule_level') }}</strong></div>
                </div>
                <p class="bulk-alert-period">{{ $alerts->first()->first_seen_at?->copy()->setTimezone(config('app.timezone'))->format('d M Y H:i:s') ?? 'Waktu tidak diketahui' }} - {{ $alerts->last()->first_seen_at?->copy()->setTimezone(config('app.timezone'))->format('d M Y H:i:s') ?? 'Waktu tidak diketahui' }} {{ \App\Support\LocalTime::label() }}</p>
                <p class="text-muted text-sm">Buka tab <strong>AI</strong> untuk menganalisis seluruh pilihan dalam satu konteks. Periksa juga kronologi dan log lengkap di bawah.</p>
            </div>
        </div>

        @include('partials.alert-evidence', [
            'evidence' => $evidence,
            'analysisUrl' => route('alerts.bulk-ai-analysis', [], false),
            'analysisPayload' => ['alert_ids' => $ids],
            'storedAnalysis' => $storedAnalysis,
            'isBulk' => true,
        ])

        <section class="card" aria-label="Kronologi alert terpilih">
            <div class="card-header"><span class="card-title">Kronologi alert terpilih</span><span class="text-muted text-xs">Urutan terlama → terbaru</span></div>
            <ol class="bulk-alert-timeline">
                @foreach($alerts as $alert)
                <li id="bulk-event-{{ $alert->id }}" data-bulk-event="{{ $alert->id }}">
                    <div class="bulk-alert-event-heading">
                        <a href="{{ route('alerts.show', $alert) }}" class="bulk-alert-event-id">#{{ $alert->id }} <i data-lucide="eye" aria-hidden="true"></i></a>
                        <time>{{ $alert->first_seen_at?->copy()->setTimezone(config('app.timezone'))->format('d M Y H:i:s') ?? '-' }} {{ \App\Support\LocalTime::label() }}</time>
                        <span class="badge {{ $alert->status_badge_class }}">{{ $alert->status_label }}</span>
                        <span class="badge {{ $alert->level_badge_class }}">Level {{ $alert->rule_level }}</span>
                    </div>
                    <h3>{{ $alert->rule_description ?? 'Tanpa deskripsi' }}</h3>
                    <p><strong>{{ $alert->agent_name ?? '-' }}</strong><span>Rule {{ $alert->rule_id ?? '-' }}</span></p>
                    @if($alert->acknowledgedBy)<p class="text-muted text-sm">Pemilik ACK: {{ $alert->acknowledgedBy->name }}</p>@endif
                    <p class="bulk-alert-event-network"><span>Source: {{ $alert->src_ip ?? '-' }}</span><span>Destination: {{ $alert->dst_ip ?? '-' }}</span></p>
                </li>
                @endforeach
            </ol>
        </section>
    </div>

    <aside class="bulk-alert-sidebar" x-data="{ action: null, submitting: false }">
        <div class="card">
            <div class="card-header"><span class="card-title">Triage massal</span></div>
            <div class="card-body bulk-alert-actions">
                <p class="text-muted text-sm">{{ $newCount }} NEW · {{ $alerts->where('status', 'acknowledged')->count() }} ACK · {{ $alerts->where('status', 'ignored')->count() }} diabaikan</p>
                @can('alerts.acknowledge')<button type="button" class="btn btn-primary w-full" @click="action = 'acknowledge'; $refs.triageDialog.showModal()" @disabled($newCount === 0) data-bulk-detail-ack>ACK {{ $newCount }} alert baru</button>@endcan
                @can('alerts.ignore')<button type="button" class="btn btn-danger w-full" @click="action = 'ignore'; $refs.triageDialog.showModal(); $nextTick(() => $refs.ignoreReason.focus())" @disabled($ignoreCount !== $alerts->count()) data-bulk-detail-ignore>Abaikan massal</button>@endcan
                @can('alerts.notify')<button type="button" class="btn btn-secondary w-full" @click="$dispatch('open-bulk-detail-notification')" @disabled($ignoreCount !== $alerts->count()) data-bulk-detail-notify>Notifikasi massal</button>@endcan
                @if($ignoreCount > 0 && $ignoreCount !== $alerts->count())
                    <a href="{{ route('alerts.bulk-detail', ['alert_ids' => $alerts->filter(fn ($alert) => $alert->isAcknowledgedBy(auth()->user()))->modelKeys()]) }}" class="btn btn-secondary w-full" data-bulk-own-selection>Tampilkan {{ $ignoreCount }} alert ACK milik saya</a>
                @endif
            </div>
        </div>
        <div class="card">
            <div class="card-header"><span class="card-title">Agent dalam pilihan</span></div>
            <ul class="bulk-alert-agent-list">@foreach($agents as $name => $items)<li><span>{{ $name }}</span><span class="text-muted">{{ $items->count() }} alert</span></li>@endforeach</ul>
        </div>
        @if($ips->isNotEmpty())
        <div class="card"><div class="card-header"><span class="card-title">IP dalam pilihan</span></div><div class="card-body bulk-alert-ips">@foreach($ips as $ip)<span>{{ $ip }}</span>@endforeach</div></div>
        @endif
        <dialog class="bulk-triage-dialog" x-ref="triageDialog" aria-labelledby="bulk-detail-triage-title" @cancel.prevent="if (!submitting) $refs.triageDialog.close()">
            <form method="POST" action="{{ route('alerts.bulk-triage') }}" @submit="if (submitting) $event.preventDefault(); else submitting = true" :aria-busy="submitting.toString()">
                @csrf
                @foreach($ids as $id)<input type="hidden" name="alert_ids[]" value="{{ $id }}">@endforeach
                <input type="hidden" name="action" :value="action">
                <h2 class="modal-title" id="bulk-detail-triage-title" x-text="action === 'ignore' ? 'Abaikan alert terpilih' : 'ACK alert terpilih'"></h2>
                <p class="text-muted text-sm bulk-triage-help" x-text="action === 'ignore' ? 'Semua alert yang diabaikan harus sudah Anda ACK. Alasan yang sama dicatat untuk setiap alert.' : 'ACK pertama menentukan pemilik. Alert milik pengguna lain akan dilewati.'"></p>
                <div class="form-group" :hidden="action !== 'ignore'">
                    <label class="form-label" for="bulk-detail-ignore-reason">Alasan mengabaikan *</label>
                    <textarea id="bulk-detail-ignore-reason" name="reason" x-ref="ignoreReason" class="form-textarea" rows="4" minlength="5" maxlength="1000" :required="action === 'ignore'" :disabled="action !== 'ignore'" placeholder="Tuliskan hasil validasi aktivitas normal."></textarea>
                </div>
                <div class="bulk-triage-actions">
                    <button type="button" class="btn btn-ghost" @click="$refs.triageDialog.close()" :disabled="submitting">Batal</button>
                    <button type="submit" class="btn" :class="action === 'ignore' ? 'btn-danger' : 'btn-primary'" :disabled="submitting" x-text="submitting ? 'Memproses…' : 'Konfirmasi'"></button>
                </div>
            </form>
        </dialog>
        @can('alerts.notify')
        <div x-data="notificationComposer(@js(['bulk' => true, 'templateUrl' => route('alerts.bulk-notify-preview'), 'sendUrl' => route('alerts.bulk-notify'), 'operationId' => (string) Illuminate\Support\Str::uuid()]))"
             @open-bulk-detail-notification.window="openNotifModal(@js($ids))">
            @include('partials.notification-dialog', ['notificationAction' => route('alerts.bulk-notify'), 'notificationTitle' => 'Kirim notifikasi Telegram - '.count($ids).' alert terpilih', 'bulkNotification' => true])
        </div>
        @endcan
    </aside>
</div>
@endsection
