<div class="card settings-auto-notifications" data-automatic-notifications>
    <div class="card-header">
        <div>
            <span class="card-title">Notifikasi otomatis</span>
            <p class="settings-card-caption">Beritahu SOC tentang alert NEW yang perlu segera ditinjau.</p>
        </div>
    </div>
    <div class="card-body">
        <form method="POST" action="{{ route('settings.telegram.automatic') }}" data-automatic-notification-form>
            @csrf
            <input type="hidden" name="_section" value="telegram">
            <div class="settings-ai-fields">
                <div class="form-group span-2">
                    <input type="hidden" name="telegram_auto_enabled" value="0">
                    <label class="settings-auto-toggle" for="telegram-auto-enabled">
                        <input type="checkbox" id="telegram-auto-enabled" name="telegram_auto_enabled" value="1" @checked(old('telegram_auto_enabled', $automaticNotifications['enabled']))>
                        <span>Aktifkan notifikasi otomatis</span>
                    </label>
                    <p class="settings-field-help">Tujuan mengikuti pemetaan agent di Manajemen tujuan Telegram.</p>
                </div>
                <div class="form-group">
                    <label class="form-label" for="telegram-auto-level">Kirim jika rule.level lebih dari</label>
                    <select id="telegram-auto-level" name="telegram_auto_above_level" class="form-select" required>
                        @for($level = 0; $level <= 14; $level++)
                        <option value="{{ $level }}" @selected((int) old('telegram_auto_above_level', $automaticNotifications['above_level']) === $level)>&gt; {{ $level }} · level {{ $level + 1 }}{{ $level < 14 ? '–15' : '' }}</option>
                        @endfor
                    </select>
                    <p class="settings-field-help">Contoh: &gt;13 hanya mengirim level 14 dan 15. Berlaku untuk alert yang lolos aturan impor.</p>
                </div>
                <div class="form-group">
                    <label class="form-label" for="telegram-auto-daily-limit">Maksimal notifikasi yang sama per hari</label>
                    <input id="telegram-auto-daily-limit" type="number" name="telegram_auto_daily_limit" class="form-input" min="1" max="100" step="1" value="{{ (int) old('telegram_auto_daily_limit', $automaticNotifications['daily_limit']) }}" required>
                    <p class="settings-field-help">Rule ID + nama agent yang sama. Reset pukul 00.00 WIB. Satu alert dihitung sekali meskipun dikirim ke beberapa tujuan.</p>
                </div>
            </div>
            <div class="settings-auto-preview">
                <strong>Isi notifikasi</strong>
                <p>Deskripsi · Rule ID · Rule level · Nama agent · Waktu (WIB) · Link detail alert</p>
                <code>{{ request()->root().route('alerts.show', 1564, false) }}</code>
                <p class="settings-field-help">Alamat aplikasi terisi otomatis saat pengaturan disimpan.</p>
            </div>
            <p class="settings-field-help">Hanya alert baru yang diimpor sejak fitur aktif yang diproses. Mengaktifkan atau menyimpan ulang pengaturan tidak mengirim alert NEW lama. Alert tetap NEW sampai SOC melakukan triage. Hasil pengiriman tersedia di Log analis sebagai Sistem (otomatis).</p>
            <button type="submit" class="btn settings-submit" style="margin-top:var(--spacing-16);">Simpan notifikasi otomatis</button>
        </form>
    </div>
</div>
