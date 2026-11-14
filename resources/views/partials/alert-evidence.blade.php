@php($isBulk = $isBulk ?? false)
        <div class="card"
             x-data="alertEvidence(@js(['analysisUrl' => $analysisUrl, 'storedAnalysis' => $storedAnalysis, 'analysisPayload' => $analysisPayload ?? []]))"
            >
            <script type="application/json" x-ref="rawEvidence">@json($evidence)</script>

            {{-- Card header --}}
            <div class="card-header" style="flex-wrap: wrap; gap: var(--spacing-8);">
                <span class="card-title" x-show="mode !== 'ai'">{{ $isBulk ? 'Gabungan log alert' : 'Raw alert data' }}
                </span>
                <span class="card-title" x-show="mode === 'ai'" x-cloak>{{ $isBulk ? 'Analisis AI gabungan' : 'Analisis AI alert' }}
                </span>

                <div class="ml-auto flex items-center gap-2" style="flex-wrap: wrap;">

                    {{-- View toggle: JSON / Table / AI --}}
                    <div class="raw-view-toggle">
                        <button class="raw-view-btn" :class="{ active: mode === 'json' }"
                                @click="mode = 'json'" id="raw-mode-json">{ } JSON</button>
                        <button class="raw-view-btn" :class="{ active: mode === 'table' }"
                                @click="mode = 'table'" id="raw-mode-table">Table</button>
                        @can('alerts.analyze')
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
                        @endcan
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
                        <strong>{{ $isBulk ? 'Mengorelasikan seluruh alert terpilih…' : 'Menganalisis bukti alert…' }}</strong>
                        <p>{{ $isBulk ? 'Meninjau urutan kejadian, hubungan bukti, dan kemungkinan aktivitas normal. Keputusan triage tetap pada Anda.' : 'Analisis ringkas sedang berjalan. Halaman ini tidak melakukan triage otomatis.' }}</p>
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
                            <span>{{ $isBulk ? 'Penilaian keseluruhan' : 'Hint analisis AI' }}</span>
                            <strong x-text="verdictLabel(aiResult?.verdict)"></strong>
                        </div>
                        <div class="alert-ai-confidence" x-show="aiResult?.confidence !== null && aiResult?.confidence !== undefined">
                            <span>Confidence</span>
                            <strong x-text="`${aiResult?.confidence ?? 0}%`"></strong>
                        </div>
                    </header>

                    <p class="alert-ai-summary" x-text="aiResult?.summary"></p>

                    @if($isBulk)
                    <div class="bulk-ai-agent-refs" x-show="aiResult?.agent_refs?.length">
                        <span class="text-muted text-xs">Referensi agent:</span>
                        <template x-for="agent in aiResult?.agent_refs ?? []" :key="agent.ref"><span><strong x-text="agent.ref"></strong> = <span x-text="agent.name"></span></span></template>
                    </div>
                    <section class="bulk-ai-correlations" aria-label="Korelasi antaralert">
                        <h3>Korelasi antaralert</h3>
                        <p class="text-muted text-sm" x-show="!aiResult?.correlations?.length">Belum ada korelasi yang cukup didukung bukti pada pilihan ini.</p>
                        <template x-for="(correlation, index) in aiResult?.correlations ?? []" :key="index">
                            <article class="bulk-ai-correlation">
                                <header>
                                    <strong x-text="'Korelasi ' + (index + 1)"></strong>
                                    <span class="bulk-ai-strength" x-text="{strong: 'Bukti kuat', moderate: 'Bukti sedang', weak: 'Hipotesis / bukti lemah'}[correlation.strength]"></span>
                                </header>
                                <nav class="bulk-ai-references" aria-label="Alert pendukung korelasi"><template x-for="id in correlation.alert_ids" :key="id"><a :href="'#bulk-event-' + id" x-text="'#' + id"></a></template></nav>
                                <p><strong>Bukti:</strong> <span x-text="correlation.evidence"></span></p>
                                <p x-show="correlation.explanation" x-text="correlation.explanation"></p>
                                <p class="text-muted"><strong>Penjelasan alternatif:</strong> <span x-text="correlation.alternative"></span></p>
                            </article>
                        </template>
                    </section>
                    @endif

                    <div class="alert-ai-evidence-grid">
                        <div x-show="aiResult?.supporting_indicators?.length">
                            <h3>{{ $isBulk ? 'Indikator mencurigakan' : 'Indikator malicious' }}</h3>
                            <ul><template x-for="item in aiResult?.supporting_indicators ?? []" :key="item"><li x-text="item"></li></template></ul>
                        </div>
                        <div x-show="aiResult?.legitimate_indicators?.length">
                            <h3>{{ $isBulk ? 'Indikator aktivitas normal' : 'Indikator legitimate' }}</h3>
                            <ul><template x-for="item in aiResult?.legitimate_indicators ?? []" :key="item"><li x-text="item"></li></template></ul>
                        </div>
                        <div x-show="aiResult?.recommended_checks?.length">
                            <h3>{{ $isBulk ? 'Prioritas tindakan lanjutan' : 'Pemeriksaan lanjutan' }}</h3>
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
                            @if($isBulk)<span>Hasil gabungan tersedia selama 24 jam. Kesimpulan keseluruhan perlu ditinjau terhadap setiap alert sebelum triage.</span>@endif
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
