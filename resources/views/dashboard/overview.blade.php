@extends('layouts.app')

@section('title', 'Dashboard SOC')
@section('page-context', 'Security Overview')
@section('page-title', 'Dashboard')
@section('page-subtitle', 'Ringkasan operasional dan kesimpulan analitis untuk periode ' . $dashboard['period']['label'])

@section('page-actions')
    <a href="{{ route('alerts.index') }}" class="btn btn-secondary">
        <i data-lucide="bell" aria-hidden="true"></i> Buka Live Alerts
    </a>
@endsection

@php
    $metrics = $dashboard['metrics'];
    $trend = $dashboard['daily_trend'];
    $trendCount = count($trend);
    $trendDivider = max(1, $trendCount - 1);
    $trendMaximum = max(1, (int) collect($trend)->max('total'), (int) collect($trend)->max('high'));

    $totalPoints = collect($trend)->values()->map(function (array $row, int $index) use ($trendDivider, $trendMaximum): string {
        $x = 3 + (94 * $index / $trendDivider);
        $y = 37 - (30 * ((int) $row['total'] / $trendMaximum));
        return number_format($x, 2, '.', '') . ',' . number_format($y, 2, '.', '');
    })->implode(' ');

    $highPoints = collect($trend)->values()->map(function (array $row, int $index) use ($trendDivider, $trendMaximum): string {
        $x = 3 + (94 * $index / $trendDivider);
        $y = 37 - (30 * ((int) $row['high'] / $trendMaximum));
        return number_format($x, 2, '.', '') . ',' . number_format($y, 2, '.', '');
    })->implode(' ');

    $recentTrend = collect($trend)->slice(max(0, $trendCount - 7))->values();
    $topTechniqueCount = max(1, (int) collect($dashboard['techniques'])->max('count'));
    $topRuleCount = max(1, (int) collect($dashboard['top_rules'])->max('count'));
    $generatedAt = $insight?->generated_at
        ? $insight->generated_at->copy()->setTimezone('Asia/Jakarta')->format('d M Y, H:i') . ' WIB'
        : null;
@endphp

@section('content')
    <section class="stats-grid soc-stats-grid" aria-label="Ringkasan alert periode {{ $dashboard['period']['label'] }}">
        <article class="stat-card">
            <div class="stat-label">Alert terimpor</div>
            <div class="stat-value">{{ number_format($metrics['total']) }}</div>
            <div class="stat-sub">{{ number_format($metrics['unique_agents']) }} agent/aset terpantau</div>
        </article>
        <article class="stat-card">
            <div class="stat-label">Sudah ditindaklanjuti</div>
            <div class="stat-value">{{ number_format($metrics['actioned']) }}</div>
            <div class="stat-sub">ACK, ignore, atau notifikasi sukses</div>
        </article>
        <article class="stat-card">
            <div class="stat-label">Belum dianalisis</div>
            <div class="stat-value">{{ number_format($metrics['pending']) }}</div>
            <div class="stat-sub">Masih menunggu tindakan SOC</div>
        </article>
        <article class="stat-card">
            <div class="stat-label">Notifikasi terkirim</div>
            <div class="stat-value">{{ number_format($metrics['notification_deliveries']) }}</div>
            <div class="stat-sub">Pengiriman sukses tercatat pada periode ini</div>
        </article>
    </section>

    <div class="soc-overview-grid">
        <section class="panel soc-trend-panel" aria-labelledby="alert-trend-heading">
            <header class="panel-header">
                <div>
                    <h2 id="alert-trend-heading" class="panel-title">Tren alert harian</h2>
                    <p class="soc-panel-caption">Total alert dibandingkan dengan alert level 12 ke atas.</p>
                </div>
                <div class="soc-chart-key" aria-label="Legenda grafik">
                    <span><i class="soc-chart-dot" aria-hidden="true"></i>Total</span>
                    <span><i class="soc-chart-dot is-high" aria-hidden="true"></i>Level ≥ 12</span>
                </div>
            </header>
            <div class="panel-body soc-chart-body">
                @if($trendCount > 0 && $metrics['total'] > 0)
                    <svg class="soc-trend-svg" viewBox="0 0 100 42" preserveAspectRatio="none" role="img" aria-label="Grafik tren alert harian">
                        <line x1="3" x2="97" y1="37" y2="37"></line>
                        <line x1="3" x2="97" y1="22" y2="22"></line>
                        <line x1="3" x2="97" y1="7" y2="7"></line>
                        <polyline class="soc-trend-line" points="{{ $totalPoints }}"></polyline>
                        <polyline class="soc-trend-line is-high" points="{{ $highPoints }}"></polyline>
                    </svg>
                    <div class="soc-chart-labels" aria-hidden="true">
                        @foreach($recentTrend as $day)
                            <span>{{ $day['label'] }}</span>
                        @endforeach
                    </div>
                @else
                    <div class="soc-empty-chart">
                        <i data-lucide="activity" aria-hidden="true"></i>
                        <span>Belum ada alert pada periode ini.</span>
                    </div>
                @endif
            </div>
        </section>

        <section class="panel" aria-labelledby="operations-heading">
            <header class="panel-header">
                <div>
                    <h2 id="operations-heading" class="panel-title">Prioritas operasional</h2>
                    <p class="soc-panel-caption">Indikator untuk menentukan antrean investigasi.</p>
                </div>
            </header>
            <div class="panel-body soc-operation-list">
                <div class="soc-operation-row">
                    <span>High + critical</span><strong>{{ number_format($metrics['high_critical']) }}</strong>
                </div>
                <div class="soc-operation-row">
                    <span>Alert di-ACK</span><strong>{{ number_format($metrics['acknowledged']) }}</strong>
                </div>
                <div class="soc-operation-row">
                    <span>Alert di-ignore</span><strong>{{ number_format($metrics['ignored']) }}</strong>
                </div>
                <div class="soc-operation-row">
                    <span>Source IP unik</span><strong>{{ number_format($metrics['unique_source_ips']) }}</strong>
                </div>
                <a href="{{ route('alerts.index', ['status' => 'new', 'level' => 12]) }}" class="soc-inline-link">
                    Tinjau alert baru level tinggi <i data-lucide="arrow-up-right" aria-hidden="true"></i>
                </a>
            </div>
        </section>
    </div>

    <div class="soc-summary-grid">
        <section class="panel" aria-labelledby="techniques-heading">
            <header class="panel-header">
                <div>
                    <h2 id="techniques-heading" class="panel-title">Teknik atau pola dominan</h2>
                    <p class="soc-panel-caption">Berdasarkan pemetaan MITRE pada log; pola rule dipakai bila pemetaan tidak tersedia.</p>
                </div>
            </header>
            <div class="panel-body soc-ranked-list">
                @if(count($dashboard['techniques']) > 0)
                    @foreach($dashboard['techniques'] as $technique)
                        <div class="soc-ranked-row">
                            <div class="soc-ranked-copy">
                                <span title="{{ $technique['label'] }}">{{ $technique['label'] }}</span>
                                <small>{{ number_format($technique['count']) }} alert terpetakan</small>
                            </div>
                            <div class="soc-meter" aria-label="{{ $technique['count'] }} alert">
                                <span style="width: {{ min(100, max(4, ($technique['count'] / $topTechniqueCount) * 100)) }}%"></span>
                            </div>
                        </div>
                    @endforeach
                @elseif(count($dashboard['top_rules']) > 0)
                    @foreach($dashboard['top_rules'] as $rule)
                        <div class="soc-ranked-row">
                            <div class="soc-ranked-copy">
                                <span title="{{ $rule['label'] }}">{{ $rule['label'] }}</span>
                                <small>{{ number_format($rule['count']) }} alert · level maksimum {{ $rule['max_level'] }}</small>
                            </div>
                            <div class="soc-meter" aria-label="{{ $rule['count'] }} alert">
                                <span style="width: {{ min(100, max(4, ($rule['count'] / $topRuleCount) * 100)) }}%"></span>
                            </div>
                        </div>
                    @endforeach
                @else
                    <div class="empty-state compact">
                        <i data-lucide="shield-question" aria-hidden="true"></i>
                        <p>Belum ada pola yang dapat dirangkum.</p>
                    </div>
                @endif
            </div>
        </section>

        <section class="panel" aria-labelledby="assets-heading">
            <header class="panel-header">
                <div>
                    <h2 id="assets-heading" class="panel-title">Agent/aset paling terdampak</h2>
                    <p class="soc-panel-caption">Urutan berdasarkan jumlah alert terimpor pada periode berjalan.</p>
                </div>
            </header>
            <div class="panel-body soc-asset-list">
                @forelse($dashboard['top_agents'] as $agent)
                    <div class="soc-asset-row">
                        <span class="soc-asset-mark" aria-hidden="true"><i data-lucide="server"></i></span>
                        <span class="soc-asset-name" title="{{ $agent['label'] }}">{{ $agent['label'] }}</span>
                        <strong>{{ number_format($agent['count']) }}</strong>
                    </div>
                @empty
                    <div class="empty-state compact">
                        <i data-lucide="server-off" aria-hidden="true"></i>
                        <p>Belum ada agent dengan alert pada periode ini.</p>
                    </div>
                @endforelse
            </div>
        </section>
    </div>

    <section class="panel soc-insight-panel"
             aria-labelledby="ai-insight-heading"
             x-data="dashboardInsight()">
        <header class="panel-header soc-insight-header">
            <div>
                <h2 id="ai-insight-heading" class="panel-title"><i data-lucide="sparkles" aria-hidden="true"></i> Kesimpulan analitis AI</h2>
                <p class="soc-panel-caption">Ringkasan {{ $dashboard['period']['label'] }} berbasis agregat alert yang telah diimpor.</p>
            </div>
            <div class="soc-insight-actions">
                <span class="soc-insight-time"
                      x-show="generatedAt"
                      x-text="generatedAt ? `Diperbarui ${generatedAt}` : ''"></span>
                @if($canRefreshInsight && $aiConfigured)
                    <button type="button"
                            class="btn btn-secondary btn-sm"
                            @click="refreshInsight()"
                            :disabled="loading"
                            :aria-busy="loading.toString()">
                        <i data-lucide="refresh-cw" x-show="!loading" aria-hidden="true"></i>
                        <span class="raw-ai-spinner" x-show="loading" x-cloak aria-hidden="true"></span>
                        <span x-text="loading ? 'Sedang menganalisis…' : 'Analisis ulang'">Analisis ulang</span>
                    </button>
                @endif
            </div>
        </header>
        <div class="panel-body soc-insight-layout">
            <div class="soc-insight-copy">
                <div class="soc-insight-empty" x-show="loading" x-cloak>
                    <span class="raw-ai-spinner" aria-hidden="true"></span>
                    <div>
                        <strong>AI sedang menganalisis data periode ini.</strong>
                        <p>Respons akan langsung ditampilkan di sini setelah layanan AI selesai. Halaman tidak perlu dimuat ulang.</p>
                    </div>
                </div>

                <div class="soc-insight-empty" x-show="!loading && error" x-cloak>
                    <i data-lucide="circle-alert" aria-hidden="true"></i>
                    <div>
                        <strong>Analisis belum berhasil.</strong>
                        <p x-text="error"></p>
                    </div>
                </div>

                <div x-show="!loading && summary" x-cloak>
                    <template x-for="(paragraph, index) in paragraphs" :key="index">
                        <p x-text="paragraph"></p>
                    </template>
                </div>

                <div x-show="!loading && !summary && !error">
                @if(!$aiConfigured)
                    <div class="soc-insight-empty">
                        <i data-lucide="key-round" aria-hidden="true"></i>
                        <div>
                            <strong>Analisis AI belum diaktifkan.</strong>
                            <p>Administrator perlu melengkapi endpoint, model, dan key token pada menu Settings. Key token disimpan terenkripsi dan tidak ditampilkan kembali.</p>
                        </div>
                    </div>
                @else
                    <div class="soc-insight-empty">
                        <i data-lucide="sparkles" aria-hidden="true"></i>
                        <div>
                            <strong>Belum ada kesimpulan untuk periode ini.</strong>
                            <p>Klik Analisis ulang untuk memproses data sekarang dan menampilkan respons AI langsung pada panel ini.</p>
                        </div>
                    </div>
                @endif
                </div>
            </div>
            <aside class="soc-insight-facts" aria-label="Batasan analisis AI">
                <div>
                    <span>Cakupan data</span>
                    <strong>{{ number_format($dashboard['scope']['sampled_alerts']) }} / {{ number_format($dashboard['scope']['maximum_alert_sample']) }} alert</strong>
                </div>
                <div>
                    <span>Ambang impor</span>
                    <strong>Level ≥ {{ $dashboard['scope']['minimum_rule_level'] }}</strong>
                </div>
                <div>
                    <span>Frekuensi otomatis</span>
                    <strong>Setiap {{ $dashboard['scope']['refresh_days'] ?? 10 }} hari</strong>
                </div>
                <p>AI menerima metrik dan pola yang telah diizinkan, bukan raw log, IP, payload, atau kredensial. Validasi hasil tetap dilakukan oleh analyst SOC.</p>
            </aside>
        </div>
    </section>
@endsection

@push('scripts')
<script>
function dashboardInsight() {
    return {
        summary: @js($insightSummary),
        generatedAt: @js($generatedAt),
        loading: false,
        error: @js(!$insightSummary && $latestAttempt?->status === 'failed'
            ? ($latestAttempt->error_message ?: 'Analisis terakhir belum berhasil. Silakan coba kembali.')
            : ''),

        get paragraphs() {
            return String(this.summary || '')
                .split(/\n\s*\n/)
                .map(paragraph => paragraph.trim())
                .filter(Boolean);
        },

        async refreshInsight() {
            if (this.loading) return;

            this.loading = true;
            this.error = '';

            try {
                const response = await fetch(@js(route('dashboard.insight.refresh', [], false)), {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                });
                const payload = await response.json().catch(() => ({}));

                if (!response.ok || !payload.success) {
                    throw new Error(payload.error || payload.message || 'Analisis AI tidak dapat diselesaikan.');
                }

                this.summary = payload.insight?.summary || '';
                this.generatedAt = payload.insight?.generated_at || '';

                if (!this.summary) {
                    throw new Error('Layanan AI tidak mengembalikan kesimpulan yang dapat ditampilkan.');
                }
            } catch (error) {
                this.error = error?.message || 'Analisis AI tidak dapat diselesaikan.';
            } finally {
                this.loading = false;
            }
        },
    };
}
</script>
@endpush
