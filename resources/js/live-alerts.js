export function alertTable(bulkUrl) {
    return {
        countdown: 30,
        refreshText: 'Refresh dalam 30s',
        refreshTimer: null,
        isRefreshing: false,
        pageIds: [],
        selectedIds: [],
        bulkMode: false,
        bulkAction: null,
        bulkReason: '',
        bulkError: '',
        bulkMessage: '',
        refreshError: '',
        submitting: false,

        get allSelected() {
            return this.pageIds.length > 0 && this.pageIds.every(id => this.selectedIds.includes(id));
        },
        get refreshPaused() {
            return this.bulkMode || this.selectedIds.length > 0 || this.bulkAction !== null || this.submitting
                || [...this.$root.querySelectorAll('.modal-backdrop')]
                    .some(modal => modal.getClientRects().length > 0);
        },
        init() {
            this.readPageIds();
            this.refreshTimer = setInterval(() => {
                if (this.refreshPaused) {
                    this.refreshText = 'Refresh dijeda selama triage';
                    this.countdown = 30;
                    return;
                }
                if (this.isRefreshing) return;
                this.countdown--;
                this.refreshText = `Refresh dalam ${this.countdown}s`;
                if (this.countdown <= 0) this.refreshData();
            }, 1000);
        },
        destroy() {
            clearInterval(this.refreshTimer);
        },
        readPageIds() {
            this.pageIds = [...this.$root.querySelectorAll('[data-triage-id]')]
                .map(input => input.dataset.triageId);
        },
        startBulkMode() {
            if (this.submitting || this.isRefreshing) return;
            this.bulkMode = true;
            this.bulkMessage = '';
            this.refreshText = 'Refresh dijeda selama triage';
        },
        cancelBulkMode() {
            if (this.submitting) return;
            this.closeBulk();
            this.bulkMode = false;
            this.bulkReason = '';
            this.clearSelection();
        },
        toggleAll(checked) {
            if (!this.bulkMode || this.submitting || this.isRefreshing) return;
            this.selectedIds = checked ? [...this.pageIds] : [];
            this.bulkMessage = '';
        },
        clearSelection() {
            if (this.submitting) return;
            this.selectedIds = [];
            this.countdown = 30;
            this.refreshText = 'Refresh dalam 30s';
        },
        openBulk(action) {
            if (!this.bulkMode || !this.selectedIds.length || this.submitting || this.isRefreshing || this.bulkAction !== null) return;
            this.bulkAction = action;
            this.bulkReason = '';
            this.bulkError = '';
            this.bulkMessage = '';
            this.$nextTick(() => {
                if (this.bulkAction !== action) return;
                this.$refs.bulkDialog.showModal();
                (action === 'ignore' ? this.$refs.bulkReason : this.$refs.bulkConfirm).focus();
            });
        },
        closeBulk() {
            if (this.submitting) return;
            this.$refs.bulkDialog.close();
            this.bulkAction = null;
            this.bulkError = '';
        },
        async submitBulk() {
            if (this.submitting || !this.selectedIds.length || !this.bulkAction) return;
            const reason = this.bulkReason.trim();
            if (this.bulkAction === 'ignore' && (reason.length < 5 || reason.length > 1000)) {
                this.bulkError = 'Alasan harus berisi 5 sampai 1000 karakter.';
                return;
            }
            this.submitting = true;
            this.bulkError = '';
            try {
                const response = await fetch(bulkUrl, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({
                        alert_ids: [...this.selectedIds],
                        action: this.bulkAction,
                        reason: this.bulkAction === 'ignore' ? reason : null,
                    }),
                });
                const payload = await response.json().catch(() => ({}));
                if (!response.ok || !payload.success) {
                    const validation = Object.values(payload.errors || {}).flat()[0];
                    throw new Error(validation || (response.status === 419
                        ? 'Sesi kedaluwarsa. Muat ulang halaman sebelum mencoba lagi.'
                        : 'Triage belum berhasil. Coba kembali atau refresh daftar alert.'));
                }
                this.bulkMessage = payload.message;
                this.selectedIds = [];
                this.bulkMode = false;
                this.submitting = false;
                this.closeBulk();
                await this.refreshData();
            } catch (error) {
                this.bulkError = error.message || 'Triage belum berhasil. Coba kembali.';
            } finally {
                this.submitting = false;
            }
        },
        async refreshData() {
            if (this.isRefreshing || this.refreshPaused) return;
            this.isRefreshing = true;
            this.refreshText = 'Sedang update...';
            this.refreshError = '';
            try {
                const response = await fetch(window.location.href, { headers: { 'Accept': 'text/html' } });
                if (!response.ok || response.redirected) throw new Error('Refresh gagal');
                const doc = new DOMParser().parseFromString(await response.text(), 'text/html');
                const newBody = doc.getElementById('alert-table-card-body');
                if (!newBody) throw new Error('Daftar alert tidak tersedia');
                // A dialog or selection may have opened while the request was in flight.
                if (this.refreshPaused) return;
                const body = this.$root.querySelector('#alert-table-card-body');
                body.innerHTML = newBody.innerHTML;
                this.readPageIds();
                window.SIEMUI?.refreshIcons?.(body);
                const newTotal = doc.getElementById('alert-total-count');
                if (newTotal) this.$root.querySelector('#alert-total-count').textContent = newTotal.textContent;
                const stats = doc.querySelector('[data-alert-stats]')?.dataset.alertStats;
                if (stats) this.$dispatch('alerts-updated', JSON.parse(stats));
            } catch {
                this.refreshError = 'Daftar belum dapat diperbarui. Klik Refresh untuk mencoba lagi.';
            } finally {
                this.isRefreshing = false;
                this.countdown = 30;
                this.refreshText = this.refreshPaused ? 'Refresh dijeda selama triage' : 'Refresh dalam 30s';
            }
        },
        refreshNow() {
            this.countdown = 30;
            return this.refreshData();
        },
    };
}
