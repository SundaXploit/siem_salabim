{{-- The parent uses notificationComposer(). Both modes edit the exact outgoing message. --}}
<dialog class="bulk-triage-dialog notification-dialog" x-ref="notificationDialog" aria-label="Kirim notifikasi Telegram"
        @cancel.prevent="closeNotifModal()" @click="if ($event.target === $el) closeNotifModal()">
    <form method="POST" action="{{ $notificationAction }}" enctype="multipart/form-data" @submit="submitNotification($event)" :aria-busy="submitting.toString()">
        @csrf
        <input type="hidden" name="operation_id" :value="operationId">
        @if($bulkNotification ?? false)
        <template x-for="id in alertIds" :key="id"><input type="hidden" name="alert_ids[]" :value="id"></template>
        @endif
        <h2 class="modal-title"><i data-lucide="send" aria-hidden="true"></i> {{ $notificationTitle }}</h2>
        @if($bulkNotification ?? false)
        <p class="text-muted text-sm notification-help">Satu pesan gabungan per tujuan Telegram. Isi tiap tujuan hanya mencakup alert agent yang dipetakan ke tujuan tersebut. Semua alert harus ACK milik Anda.</p>
        @endif
        <p x-show="loading" role="status">Memuat template dan tujuan…</p>
        <div x-show="!loading" :inert="submitting">
            <div class="form-group">
                <span class="form-label">Tujuan otomatis</span>
                <p x-text="destinations.join(', ')"></p>
            </div>
            <div class="form-group">
                <span class="form-label">Pilih template</span>
                <div class="notification-template-grid" role="group" aria-label="Pilih template">
                    <template x-for="tpl in templates" :key="tpl.id">
                        <button type="button" class="btn btn-ghost notification-template" @click="selectTemplate(tpl)"
                                :class="{ 'active-template': selectedTplId === tpl.id }" :aria-pressed="selectedTplId === tpl.id" :disabled="submitting">
                            <span x-text="tpl.name"></span>
                        </button>
                    </template>
                </div>
                @if(auth()->user()->isAdmin())
                <a class="text-sm" href="{{ route('settings.index', ['section' => 'templates']) }}" target="_blank" rel="noopener">Kelola template di Settings</a>
                @endif
            </div>
            <div class="notification-destinations" x-show="messages.length > 1" role="group" aria-label="Pilih tujuan pesan">
                <template x-for="(item, index) in messages" :key="item.destination_key">
                    <button type="button" class="btn btn-secondary btn-sm" @click="activeDestination = index" :aria-pressed="activeDestination === index"
                            :class="{ 'active-template': activeDestination === index }" x-text="item.label + ' · ' + item.alert_count + ' alert'"></button>
                </template>
            </div>
            <template x-for="(item, index) in messages" :key="item.destination_key">
                <div class="form-group" x-show="activeDestination === index" x-id="['notification-message']">
                    @if($bulkNotification ?? false)
                    <input type="hidden" :name="`messages[${index}][destination_key]`" :value="item.destination_key">
                    @endif
                    <label class="form-label notification-message-label" :for="$id('notification-message') + '-' + index">
                        <span>Pesan (dapat diedit bebas)<span x-show="messages.length > 1" x-text="' — ' + item.label"></span></span>
                        <span class="text-muted text-xs" x-text="characterCount(item.message) + ' / 4096 karakter'"></span>
                    </label>
                    <textarea class="form-textarea" :id="$id('notification-message') + '-' + index" rows="16" minlength="5" maxlength="4096"
                              @if($bulkNotification ?? false) :name="`messages[${index}][message]`" @else name="message" @endif
                              x-model="item.message" :required="activeDestination === index" :disabled="loading" placeholder="Pilih template, lalu isi atau edit pesan."></textarea>
                    <p class="text-muted text-sm">Mendukung HTML Telegram: &lt;b&gt;, &lt;i&gt;, &lt;code&gt;, &lt;a href&gt;. Template Custom / Pesan Bebas dapat diisi sesuai kebutuhan.</p>
                </div>
            </template>
            <div class="form-group">
                <div class="notification-message-label">
                    <span class="form-label">Lampirkan evidence (opsional)</span>
                    <button type="button" class="btn btn-ghost btn-sm" @click="addEvidence()" :disabled="evidences.length >= 5 || submitting">+ Tambah</button>
                </div>
                <div class="notification-evidence-list">
                    <template x-for="(ev, index) in evidences" :key="ev.id">
                        <div class="notification-evidence-row">
                            <input type="file" name="evidence[]" class="form-input" accept="image/*" :aria-label="'Evidence ' + (index + 1)">
                            <button type="button" class="btn btn-danger btn-sm" @click="removeEvidence(index)" :disabled="submitting" aria-label="Hapus evidence">Hapus</button>
                        </div>
                    </template>
                </div>
                <p class="text-muted text-sm">Maksimal 5 gambar, masing-masing 10 MB. Gambar dikirim terpisah dari pesan teks; hasil tercatat per tujuan dan lampiran.</p>
            </div>
        </div>
        <p class="form-error" x-show="notificationError" x-text="notificationError" role="alert"></p>
        <div class="bulk-triage-actions">
            <button type="button" class="btn btn-ghost" @click="closeNotifModal()" :disabled="submitting">Batal</button>
            <button type="submit" class="btn btn-primary" :disabled="!canSend" x-text="submitting ? 'Memproses…' : 'Kirim ke Telegram'">Kirim ke Telegram</button>
        </div>
    </form>
</dialog>
