const requestError = (response, payload) => Object.values(payload.errors || {}).flat()[0]
    || (response.status === 419 ? 'Sesi kedaluwarsa. Muat ulang halaman.' : payload.message)
    || 'Permintaan belum berhasil. Coba kembali.';
const headers = () => ({ Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content });

export function notificationComposer(options) {
    return {
        showIgnoreModal: false,
        showNotifModal: false,
        loading: false,
        submitting: false,
        notificationError: '',
        templates: [],
        selectedTplId: null,
        destinations: [],
        messages: [],
        activeDestination: 0,
        alertIds: [],
        operationId: options.operationId,
        evidences: [{ id: 0 }],
        nextEvidenceId: 1,
        loadedSelection: null,
        loadSequence: 0,
        get message() { return this.messages[0]?.message || ''; },
        set message(value) { if (this.messages[0]) this.messages[0].message = value; },
        get canSend() { return !this.loading && !this.submitting && this.messages.length > 0 && this.destinations.length > 0; },
        characterCount(value) { return Array.from(value).length; },
        async openNotifModal(alertIds = []) {
            if (this.submitting) return;
            this.alertIds = [...alertIds];
            this.showNotifModal = true;
            if (!this.$refs.notificationDialog.open) this.$refs.notificationDialog.showModal();
            this.loading = true;
            this.notificationError = '';
            const sequence = ++this.loadSequence;
            try {
                const response = await fetch(options.templateUrl, options.bulk ? {
                    method: 'POST', headers: { ...headers(), 'Content-Type': 'application/json' },
                    body: JSON.stringify({ alert_ids: this.alertIds }),
                } : { headers: headers() });
                const payload = await response.json().catch(() => ({}));
                if (!response.ok || response.redirected) throw Error(requestError(response, payload));
                if (sequence !== this.loadSequence || !this.showNotifModal) return;
                this.templates = payload.templates || [];
                const defaults = options.bulk ? payload.default_messages : [{
                    destination_key: 'single', label: (payload.destinations || []).join(', '),
                    alert_count: 1, message: payload.default_message || '',
                }];
                this.destinations = options.bulk ? defaults.map(item => item.label) : (payload.destinations || []);
                const selection = JSON.stringify([this.alertIds.map(String).sort(), defaults.map(item => [item.destination_key, item.label])]);
                if (selection !== this.loadedSelection || !this.messages.length) {
                    this.messages = defaults.map(item => ({ ...item }));
                    this.selectedTplId = this.templates[0]?.id ?? null;
                    this.activeDestination = 0;
                    this.loadedSelection = selection;
                    this.evidences = [{ id: this.nextEvidenceId++ }];
                }
                if (!this.destinations.length) this.notificationError = 'Belum ada tujuan untuk agent ini. Hubungi admin untuk mengatur Settings → Telegram.';
            } catch (error) {
                if (sequence === this.loadSequence) {
                    this.destinations = [];
                    this.notificationError = error.message || 'Template belum dapat dimuat. Coba buka kembali.';
                }
            } finally {
                if (sequence === this.loadSequence) this.loading = false;
            }
        },
        closeNotifModal() {
            if (this.submitting) return;
            this.$refs.notificationDialog.close();
            this.showNotifModal = false;
            ++this.loadSequence;
            if (options.bulk) this.$dispatch('bulk-notification-closed');
        },
        selectTemplate(template) {
            this.selectedTplId = template.id;
            this.messages = options.bulk ? template.messages.map(item => ({ ...item }))
                : [{ ...this.messages[0], message: template.body }];
            this.notificationError = '';
        },
        addEvidence() { if (this.evidences.length < 5 && !this.submitting) this.evidences.push({ id: this.nextEvidenceId++ }); },
        removeEvidence(index) { if (!this.submitting) this.evidences.splice(index, 1); },
        async submitNotification(event) {
            if (!this.canSend) { event.preventDefault(); return; }
            const invalid = this.messages.findIndex(item => this.characterCount(item.message.trim()) < 5 || this.characterCount(item.message) > 4096);
            if (invalid !== -1) {
                event.preventDefault();
                this.activeDestination = invalid;
                this.notificationError = 'Setiap pesan harus berisi 5 sampai 4096 karakter.';
                return;
            }
            if (!options.asyncSubmit) { this.submitting = true; return; }
            event.preventDefault();
            const form = event.target;
            const body = new FormData(form);
            // Empty optional file inputs must not become invalid evidence entries.
            const evidence = body.getAll('evidence[]').filter(file => file instanceof File && file.size > 0);
            body.delete('evidence[]');
            evidence.forEach(file => body.append('evidence[]', file));
            this.submitting = true;
            this.notificationError = '';
            try {
                const response = await fetch(options.sendUrl, { method: 'POST', headers: headers(), body });
                const payload = await response.json().catch(() => ({}));
                if (!response.ok || !payload.success || response.redirected) throw Error(requestError(response, payload));
                this.operationId = payload.next_operation_id || crypto.randomUUID();
                this.submitting = false;
                this.closeNotifModal();
                this.$dispatch('bulk-notification-sent', { message: payload.message });
                this.loadedSelection = null;
                this.messages = [];
                form.reset();
            } catch (error) {
                // Keep the operation UUID, edits, and attachments for an identical retry.
                this.notificationError = error.message || 'Hasil pengiriman belum dapat dipastikan. Coba permintaan yang sama.';
            } finally { this.submitting = false; }
        },
    };
}

export function notificationTemplatesManager(initialTemplates, saveUrl, deleteUrl) {
    return {
        templates: initialTemplates, saving: false, error: '', feedback: '',
        editor: { id: null, name: '', category: 'General', body: '', is_active: true },
        openEditor(template = null) {
            this.editor = template ? { id: template.id, name: template.name, category: template.category, body: template.body, is_active: template.is_active }
                : { id: null, name: '', category: 'General', body: '', is_active: true };
            this.error = '';
            this.$refs.templateEditor.showModal();
        },
        closeEditor() { if (!this.saving) this.$refs.templateEditor.close(); },
        async save() {
            if (this.saving) return;
            this.saving = true;
            this.error = '';
            try {
                const response = await fetch(saveUrl, { method: 'POST', headers: { ...headers(), 'Content-Type': 'application/json' }, body: JSON.stringify(this.editor) });
                const payload = await response.json().catch(() => ({}));
                if (!response.ok || !payload.success) throw Error(requestError(response, payload));
                this.templates = [...this.templates.filter(item => item.id !== payload.template.id), payload.template]
                    .sort((a, b) => `${a.category} ${a.name}`.localeCompare(`${b.category} ${b.name}`, 'id'));
                this.feedback = payload.message;
                this.saving = false;
                this.closeEditor();
            } catch (error) { this.error = error.message; }
            finally { this.saving = false; }
        },
        async remove(template) {
            if (this.saving || !confirm(`Hapus template "${template.name}"? Riwayat dan pesan yang sudah diantrekan tetap tersimpan.`)) return;
            this.saving = true;
            this.error = '';
            try {
                const response = await fetch(deleteUrl.replace('__ID__', template.id), { method: 'DELETE', headers: headers() });
                const payload = await response.json().catch(() => ({}));
                if (!response.ok || !payload.success) throw Error(requestError(response, payload));
                this.templates = this.templates.filter(item => item.id !== template.id);
                this.feedback = payload.message;
            } catch (error) { this.error = error.message; }
            finally { this.saving = false; }
        },
    };
}
