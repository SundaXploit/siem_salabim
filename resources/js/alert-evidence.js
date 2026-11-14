export function alertEvidence({ analysisUrl, storedAnalysis = null, analysisPayload = {} }) {
    let raw = {};

    return {
        mode:        'json',    // 'json' | 'table' | 'ai'
        copied:      false,
        copyTimer: null,
        highlighted: '',
        flatRows:    [],
        aiLoading:   false,
        aiError:     '',
        aiResult:    storedAnalysis,

        init() {
            raw = JSON.parse(this.$refs.rawEvidence.textContent);
            this.highlighted = this.syntaxHighlight(raw);
            this.flatRows    = this.flatten(raw, '');
        },

        destroy() { clearTimeout(this.copyTimer); },

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
                    clearTimeout(this.copyTimer);
                    this.copyTimer = setTimeout(() => { this.copied = false; }, 2200);
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
                        clearTimeout(this.copyTimer);
                    this.copyTimer = setTimeout(() => { this.copied = false; }, 2200);
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

            try {
                const response = await fetch(analysisUrl, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    },
                    body: JSON.stringify({ ...analysisPayload, force }),
                });
                const payload = await response.json().catch(() => ({}));

                if (!response.ok || !payload.success) {
                    throw new Error(payload.error ?? Object.values(payload.errors ?? {}).flat()[0] ?? payload.message ?? 'Analisis AI tidak dapat diselesaikan.');
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
