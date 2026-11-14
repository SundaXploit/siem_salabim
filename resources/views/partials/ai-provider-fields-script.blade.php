<script>
(() => {
    const providers = @json(config('ai_providers'));
    document.querySelectorAll('[data-ai-fields]').forEach(root => {
        const provider = root.querySelector('[name="ai_provider"]');
        const endpoint = root.querySelector('[name="ai_base_url"]');
        const format = root.querySelector('[name="ai_api_format"]');
        const model = root.querySelector('[name="ai_model"]');
        const key = root.querySelector('[name="ai_api_key"]');
        const loadModels = root.querySelector('[data-ai-load-models]');
        const modelOptions = root.querySelector('[data-ai-model-options]');
        const modelStatus = root.querySelector('[data-ai-model-status]');
        let modelRequest = null;
        const savedProvider = root.dataset.aiSavedProvider;
        const savedUrl = root.dataset.aiSavedUrl;
        const localProviders = ['ollama', 'lmstudio', 'local'];
        const sync = () => {
            const local = localProviders.includes(provider.value);
            const stored = root.dataset.aiKeyAvailable === '1' && provider.value === savedProvider
                && endpoint.value.trim().replace(/\/+$/, '') === savedUrl;
            key.required = !key.disabled && !local && !stored;
            key.placeholder = local ? 'Opsional jika server lokal memakai autentikasi'
                : (stored ? 'token tersimpan' : 'Masukkan API key provider ini');
            root.querySelector('[data-ai-local-help]').hidden = !local;
            loadModels.disabled = endpoint.disabled || modelRequest !== null;
            modelOptions.disabled = endpoint.disabled || modelOptions.options.length <= 1;
        };
        const resetModels = () => {
            modelRequest?.abort();
            modelRequest = null;
            modelOptions.replaceChildren(new Option('Pilih model dari endpoint', ''));
            modelOptions.hidden = true;
            modelStatus.textContent = '';
            loadModels.textContent = 'Ambil daftar model';
            sync();
        };
        provider.addEventListener('change', () => {
            const preset = providers[provider.value];
            endpoint.value = preset.base_url;
            format.value = preset.api_format;
            model.value = '';
            key.value = '';
            resetModels();
        });
        endpoint.addEventListener('input', resetModels);
        key.addEventListener('input', resetModels);
        format.addEventListener('change', resetModels);
        document.querySelector('#ai_enabled')?.addEventListener('change', resetModels);
        modelOptions.addEventListener('change', () => {
            if (!modelOptions.value) return;
            model.value = modelOptions.value;
            model.dispatchEvent(new Event('input', { bubbles: true }));
        });
        loadModels.addEventListener('click', async () => {
            if (modelRequest || endpoint.disabled) return;
            for (const control of [provider, format, endpoint, key]) {
                if (!control.reportValidity()) return;
            }
            resetModels();
            const controller = new AbortController();
            modelRequest = controller;
            loadModels.textContent = 'Mengambil model...';
            modelStatus.textContent = 'Menghubungi endpoint untuk membaca daftar model.';
            sync();
            const timeout = setTimeout(() => controller.abort(), 35000);
            try {
                const form = root.closest('form');
                const response = await fetch(root.dataset.aiModelsUrl, {
                    method: 'POST', signal: controller.signal,
                    headers: {
                        'Accept': 'application/json', 'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': form.querySelector('[name="_token"]').value,
                    },
                    body: JSON.stringify({
                        ai_provider: provider.value, ai_api_format: format.value,
                        ai_base_url: endpoint.value.trim(), ai_api_key: key.value.trim(),
                    }),
                });
                const data = await response.json().catch(() => ({}));
                if (modelRequest !== controller) return;
                if (!response.ok || data.success !== true || !Array.isArray(data.models)) {
                    modelStatus.textContent = response.status === 419
                        ? 'Sesi berakhir. Muat ulang halaman untuk mencoba lagi.'
                        : (data.error || Object.values(data.errors ?? {}).flat().join(' ') || 'Daftar model tidak dapat diambil. Model tetap bisa diisi manual.');
                    return;
                }
                for (const item of data.models) {
                    if (typeof item.id !== 'string' || !item.id) continue;
                    modelOptions.add(new Option(item.name && item.name !== item.id ? `${item.name} (${item.id})` : item.id, item.id));
                }
                const count = modelOptions.options.length - 1;
                modelOptions.hidden = count === 0;
                modelStatus.textContent = count
                    ? `${count} model ditemukan. Pilih model, lalu gunakan Test Koneksi.` + (data.truncated ? ' Daftar dibatasi; model lain tetap bisa diisi manual.' : '')
                    : 'Endpoint belum menampilkan model. Nama model tetap bisa diisi manual.';
            } catch (error) {
                if (modelRequest === controller) {
                    modelStatus.textContent = 'Daftar model tidak dapat diambil atau melewati batas waktu. Model tetap bisa diisi manual.';
                }
            } finally {
                clearTimeout(timeout);
                if (modelRequest === controller) {
                    modelRequest = null;
                    loadModels.textContent = 'Ambil daftar model';
                    sync();
                }
            }
        });
        sync();
    });
})();
</script>
