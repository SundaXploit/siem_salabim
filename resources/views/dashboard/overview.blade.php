@extends('layouts.app')
@section('body-class', 'density-comfortable soc-dashboard')

@section('title', 'Dashboard SOC')
@section('page-context', 'Monitoring')
@section('page-title', 'Dashboard')
@section('page-subtitle', 'Sinyal keamanan dan tindak lanjut tim SOC. Periode ' . $dashboard['period']['label'] . '.')

@section('page-actions')
    <a href="{{ route('alerts.index') }}" class="btn btn-secondary">
        Buka Live Alerts
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
    <div class="signal-grid">
        <section class="panel soc-summary-panel" aria-labelledby="summary-heading">
            <header class="panel-header">
                <div><h2 id="summary-heading" class="panel-title">Ringkasan operasional</h2><p class="soc-panel-caption">{{ $dashboard['period']['label'] }} · data alert yang telah diimpor</p></div>
            </header>
            <div class="signal-summary">
                <div class="signal-lead">
                    <span class="signal-glyph" aria-hidden="true"><span></span><span></span><span></span></span>
                    <div class="signal-lead-value">{{ number_format($metrics['total']) }}</div>
                    <div class="signal-lead-label">Alert terimpor</div>
                    <p>{{ number_format($metrics['unique_agents']) }} agent/aset terpantau</p>
                </div>
                <dl class="signal-metrics">
                    <div><dt>Sudah ditindaklanjuti</dt><dd>{{ number_format($metrics['actioned']) }}</dd><small>ACK, ignore, atau notifikasi sukses</small></div>
                    <div><dt>Belum dianalisis</dt><dd>{{ number_format($metrics['pending']) }}</dd><small>Menunggu tindakan SOC</small></div>
                    <div><dt>Notifikasi terkirim</dt><dd>{{ number_format($metrics['notification_deliveries']) }}</dd><small>Pengiriman sukses pada periode ini</small></div>
                    <div><dt>Agent/aset terpantau</dt><dd>{{ number_format($metrics['unique_agents']) }}</dd><small>Memiliki alert pada periode ini</small></div>
                </dl>
            </div>
        </section>

        <section class="panel soc-priority-panel" aria-labelledby="operations-heading">
            <header class="panel-header"><div><h2 id="operations-heading" class="panel-title">Prioritas operasional</h2><p class="soc-panel-caption">Tentukan antrean investigasi.</p></div></header>
            <div class="panel-body soc-operation-list">
                <div class="soc-operation-row"><span>High + critical</span><strong class="text-danger">{{ number_format($metrics['high_critical']) }}</strong></div>
                <div class="soc-operation-row"><span>Alert di-ACK</span><strong>{{ number_format($metrics['acknowledged']) }}</strong></div>
                <div class="soc-operation-row"><span>Alert di-ignore</span><strong>{{ number_format($metrics['ignored']) }}</strong></div>
                <div class="soc-operation-row"><span>Source IP unik</span><strong>{{ number_format($metrics['unique_source_ips']) }}</strong></div>
                <a href="{{ route('alerts.index', ['status' => 'new', 'level' => 12]) }}" class="soc-inline-link">Tinjau alert baru level tinggi <span aria-hidden="true">↗</span></a>
            </div>
        </section>

        <section class="panel soc-trend-panel {{ $metrics['total'] > 0 ? 'signal-pixels signal-pixels-right' : '' }}" aria-labelledby="alert-trend-heading">
            <header class="panel-header"><div><h2 id="alert-trend-heading" class="panel-title">Tren alert harian</h2><p class="soc-panel-caption">Volume alert selama {{ $dashboard['period']['label'] }}.</p></div>
                <div class="soc-chart-key" aria-label="Legenda grafik"><span><i class="soc-chart-dot" aria-hidden="true"></i>Total</span><span><i class="soc-chart-dot is-high" aria-hidden="true"></i>Level ≥ 12</span></div>
            </header>
            <div class="panel-body soc-chart-body">
                @if($trendCount > 0 && $metrics['total'] > 0)
                    <div class="soc-chart-plot">
                        <div class="soc-value-axis" aria-hidden="true"><span>{{ number_format($trendMaximum) }}</span><span>{{ number_format($trendMaximum / 2, $trendMaximum % 2 ? 1 : 0) }}</span><span>0</span></div>
                        <div class="soc-chart-canvas">
                            <svg class="soc-trend-svg" viewBox="0 0 100 42" preserveAspectRatio="none" role="img" aria-labelledby="trend-svg-title trend-svg-description">
                                <title id="trend-svg-title">Tren alert {{ $dashboard['period']['label'] }}</title>
                                <desc id="trend-svg-description">Perbandingan total alert dan alert level 12 ke atas. Data setiap hari tersedia pada tabel di bawah grafik.</desc>
                                <line x1="3" x2="97" y1="37" y2="37"></line><line x1="3" x2="97" y1="22" y2="22"></line><line x1="3" x2="97" y1="7" y2="7"></line>
                                <polyline class="soc-trend-line" points="{{ $totalPoints }}"></polyline><polyline class="soc-trend-line is-high" points="{{ $highPoints }}"></polyline>
                                @foreach($trend as $index => $day)
                                    <rect class="soc-chart-hit" x="{{ 3 + (94 * $index / $trendDivider) - 1 }}" y="4" width="2" height="34" tabindex="0"><title>{{ $day['label'] }}: {{ $day['total'] }} total, {{ $day['high'] }} level ≥ 12</title></rect>
                                @endforeach
                            </svg>
                            <div class="soc-chart-labels" aria-hidden="true">@foreach(collect($trend)->filter(fn ($day, $index) => $index === 0 || $index === $trendCount - 1 || $index % max(1, (int) ceil($trendCount / 6)) === 0) as $day)<span>{{ $day['label'] }}</span>@endforeach</div>
                        </div>
                    </div>
                    <details class="soc-trend-data"><summary>Lihat data harian</summary><div class="table-shell"><table class="data-table"><caption class="sr-only">Data harian {{ $dashboard['period']['label'] }}</caption><thead><tr><th scope="col">Tanggal</th><th scope="col">Total alert</th><th scope="col">Level ≥ 12</th></tr></thead><tbody>@foreach($trend as $day)<tr><td>{{ $day['label'] }}</td><td class="technical">{{ number_format($day['total']) }}</td><td class="technical">{{ number_format($day['high']) }}</td></tr>@endforeach</tbody></table></div></details>
                @else
                    <div class="soc-empty-chart"><strong>Belum ada alert pada periode ini.</strong><span>Angka 0 menunjukkan data tersimpan untuk periode ini, bukan status koneksi indexer.</span><a href="{{ route('alerts.index') }}">Periksa Live Alerts</a></div>
                @endif
            </div>
        </section>

        <section class="panel soc-pattern-panel {{ count($dashboard['techniques']) || count($dashboard['top_rules']) ? 'signal-pixels signal-pixels-left' : '' }}" aria-labelledby="techniques-heading">
            <header class="panel-header"><div><h2 id="techniques-heading" class="panel-title">Teknik atau pola dominan</h2><p class="soc-panel-caption">{{ count($dashboard['techniques']) ? 'Pemetaan MITRE pada alert terimpor.' : 'Pola rule; pemetaan MITRE belum tersedia.' }}</p></div></header>
            <div class="panel-body soc-ranked-list">
                @if(count($dashboard['techniques']) > 0)
                    @foreach($dashboard['techniques'] as $technique)
                        <div class="soc-ranked-row"><div class="soc-ranked-copy"><span title="{{ $technique['label'] }}">{{ $technique['label'] }}</span><small>{{ number_format($technique['count']) }} alert terpetakan</small></div><div class="soc-meter" aria-label="{{ $technique['count'] }} alert"><span style="width: {{ min(100, ($technique['count'] / $topTechniqueCount) * 100) }}%"></span></div></div>
                    @endforeach
                @elseif(count($dashboard['top_rules']) > 0)
                    @foreach($dashboard['top_rules'] as $rule)
                        <div class="soc-ranked-row"><div class="soc-ranked-copy"><span title="{{ $rule['label'] }}">{{ $rule['label'] }}</span><small>{{ number_format($rule['count']) }} alert · level maksimum {{ $rule['max_level'] }}</small></div><div class="soc-meter" aria-label="{{ $rule['count'] }} alert"><span style="width: {{ min(100, ($rule['count'] / $topRuleCount) * 100) }}%"></span></div></div>
                    @endforeach
                @else
                    <div class="empty-state compact"><p>Belum ada pola yang dapat dirangkum.</p><span class="empty-state-copy">Pola muncul setelah alert pada periode ini tersedia.</span></div>
                @endif
            </div>
        </section>

        <section class="panel soc-assets-panel {{ count($dashboard['top_agents']) ? 'signal-corner' : '' }}" aria-labelledby="assets-heading">
            <header class="panel-header"><div><h2 id="assets-heading" class="panel-title">Agent/aset paling terdampak</h2><p class="soc-panel-caption">Berdasarkan jumlah alert terimpor.</p></div></header>
            <div class="panel-body soc-asset-list">
                @forelse($dashboard['top_agents'] as $agent)
                    <div class="soc-asset-row"><span class="soc-asset-name" title="{{ $agent['label'] }}">{{ $agent['label'] }}</span><strong>{{ number_format($agent['count']) }} <span class="soc-unit">alert</span></strong></div>
                @empty
                    <div class="empty-state compact"><p>Belum ada agent dengan alert pada periode ini.</p></div>
                @endforelse
            </div>
        </section>

    <section class="panel soc-insight-panel"
             aria-labelledby="ai-insight-heading"
             x-data="dashboardInsight()">
        <header class="panel-header soc-insight-header">
            <div>
                <h2 id="ai-insight-heading" class="panel-title">Kesimpulan analitis AI</h2>
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
                        <div>
                            <strong>Analisis AI belum diaktifkan.</strong>
                            <p>Administrator dapat memilih provider cloud atau LLM lokal, lalu mengisi endpoint dan model melalui Pengaturan. API key cloud disimpan terenkripsi; LLM lokal boleh tanpa API key.</p>
                        </div>
                    </div>
                @else
                    <div class="soc-insight-empty">
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
    </div>
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
