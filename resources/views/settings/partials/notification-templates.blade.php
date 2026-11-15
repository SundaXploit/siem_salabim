<div class="card settings-notification-templates" x-data="notificationTemplatesManager(@js($notificationTemplates), @js(route('settings.templates.save')), @js(route('settings.templates.delete', '__ID__')))">
    <div class="card-header">
        <span class="card-title">Template notifikasi</span>
        <button type="button" class="btn btn-primary btn-sm ml-auto" @click="openEditor()" data-template-add>Tambah template</button>
    </div>
    <div class="card-body">
        <p class="settings-field-help">Template yang sama digunakan untuk notifikasi tunggal dan massal. Pesan tetap dapat diedit sebelum dikirim. Mengubah atau menghapus template tidak mengubah riwayat maupun pesan yang sudah diantrekan.</p>
        <p class="text-sm" x-show="feedback" x-text="feedback" role="status"></p>
        <p class="form-error" x-show="error && !$refs.templateEditor.open" x-text="error" role="alert"></p>
        <div class="notification-template-list">
            <template x-for="template in templates" :key="template.id">
                <article class="notification-template-item">
                    <div><strong x-text="template.name"></strong>
                        <p class="text-muted text-sm"><span x-text="template.category"></span> · <span x-text="template.is_active ? 'Aktif' : 'Nonaktif'"></span></p>
                    </div>
                    <div class="flex gap-2">
                        <button type="button" class="btn btn-secondary btn-sm" @click="openEditor(template)" :disabled="saving" data-template-edit>Edit</button>
                        <button type="button" class="btn btn-danger btn-sm" @click="remove(template)" :disabled="saving" data-template-delete>Hapus</button>
                    </div>
                </article>
            </template>
            <p x-show="!templates.length" class="text-muted">Belum ada template. Tambahkan template untuk tim SOC.</p>
        </div>
    </div>
    <dialog class="bulk-triage-dialog notification-dialog" x-ref="templateEditor" aria-labelledby="template-editor-title" @cancel.prevent="closeEditor()">
        <form @submit.prevent="save()" :aria-busy="saving.toString()">
            <h2 class="modal-title" id="template-editor-title" x-text="editor.id ? 'Edit template notifikasi' : 'Tambah template notifikasi'"></h2>
            <div class="form-group"><label class="form-label" for="template-editor-name">Nama template</label><input id="template-editor-name" class="form-input" x-model="editor.name" maxlength="100" required></div>
            <div class="form-group"><label class="form-label" for="template-editor-category">Kategori</label><input id="template-editor-category" class="form-input" x-model="editor.category" maxlength="50" placeholder="General"></div>
            <div class="form-group">
                <label class="form-label notification-message-label" for="template-editor-body"><span>Isi template</span><span class="text-muted text-xs" x-text="Array.from(editor.body).length + ' / 4096 karakter'"></span></label>
                <textarea id="template-editor-body" class="form-textarea" rows="16" x-model="editor.body" minlength="5" maxlength="4096" required></textarea>
                <p class="settings-field-help">Mendukung HTML Telegram. Variabel akan diisi dari alert, kemudian pengguna dapat mengedit pesan sebelum mengirim. Biarkan nilai kosong untuk isian manual.</p>
                <p class="settings-field-help">Variabel tunggal dan massal: <code>{alert_id}</code>, <code>{agent_id}</code>, <code>{agent_name}</code>, <code>{agent_ip}</code>, <code>{src_ip}</code>, <code>{dst_ip}</code>, <code>{rule_id}</code>, <code>{rule_level}</code>, <code>{rule_description}</code>, <code>{source_cluster}</code>, <code>{timestamp}</code>, <code>{wazuh_id}</code>.</p>
                <p class="settings-field-help">Khusus ringkasan massal: <code>{alert_count}</code>, <code>{alert_ids}</code>, <code>{agent_count}</code>, <code>{agents}</code>, <code>{agent_ids}</code>, <code>{rule_ids}</code>, <code>{max_level}</code>, <code>{alerts}</code>. Nilai daftar hanya mencakup agent yang dipetakan ke masing-masing tujuan.</p>
            </div>
            <label class="form-checkbox-label"><input type="checkbox" x-model="editor.is_active"> Aktif — tampil pada pemilih template</label>
            <p class="form-error" x-show="error" x-text="error" role="alert"></p>
            <div class="bulk-triage-actions"><button type="button" class="btn btn-ghost" @click="closeEditor()" :disabled="saving">Batal</button><button type="submit" class="btn btn-primary" :disabled="saving" x-text="saving ? 'Menyimpan…' : 'Simpan template'">Simpan template</button></div>
        </form>
    </dialog>
</div>
