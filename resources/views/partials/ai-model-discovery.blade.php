<div style="margin-top:var(--spacing-8);">
    <button type="button" class="{{ $buttonClass }}" data-ai-load-models>Ambil daftar model</button>
    <p class="{{ $helpClass }}" data-ai-model-status role="status" aria-live="polite"></p>
    <select class="{{ $selectClass }}" data-ai-model-options aria-label="Model yang tersedia di endpoint" hidden disabled>
        <option value="">Pilih model dari endpoint</option>
    </select>
</div>
