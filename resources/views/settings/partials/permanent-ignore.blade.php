<div class="card" data-permanent-ignore>
    <div class="card-header"><div><span class="card-title">Manajemen ignore permanen</span><p class="settings-card-caption">Abaikan alert baru berdasarkan nilai rule.id.</p></div></div>
    <div class="card-body">
        <p class="settings-field-help">Alert baru dengan Rule ID yang terdaftar tidak diambil atau disimpan, untuk seluruh agent. Alert lama tetap tersedia. Alert yang tersaring tidak membuat catatan di Log analis.</p>
        <form method="POST" action="{{ route('settings.permanent-ignores.store') }}" class="permanent-ignore-form">
            @csrf
            <input type="hidden" name="_section" value="permanent-ignore">
            <label class="form-label" for="permanent-ignore-rule-id">Rule ID</label>
            <input id="permanent-ignore-rule-id" type="text" name="rule_id" class="form-input" inputmode="numeric" pattern="[0-9]+" maxlength="64" value="{{ old('rule_id') }}" placeholder="Contoh: 40101" required aria-describedby="permanent-ignore-rule-id-help">
            <p id="permanent-ignore-rule-id-help" class="settings-field-help">Masukkan satu Rule ID lengkap. Tidak perlu memilih deskripsi atau menunggu alert tersimpan.</p>
            <button type="submit" class="btn btn-primary" data-add-permanent-ignore><i data-lucide="plus" aria-hidden="true"></i> Tambah ignore permanen</button>
        </form>

        <div class="settings-telegram-heading" style="margin-top:var(--spacing-24);"><h3 class="settings-subheading">{{ $permanentIgnores->total() }} aturan aktif</h3><span class="text-muted text-xs">Berlaku sampai aturan dihapus</span></div>
        <div class="permanent-ignore-table-wrap">
            <table class="data-table permanent-ignore-table">
                <thead><tr><th scope="col">Rule ID</th><th scope="col">Ditambahkan oleh</th><th scope="col">Waktu ({{ \App\Support\LocalTime::label() }})</th><th scope="col">Aksi</th></tr></thead>
                <tbody>
                    @forelse($permanentIgnores as $rule)
                    <tr data-permanent-ignore-rule="{{ $rule->id }}">
                        <td class="font-technical">{{ $rule->rule_id }}</td>
                        <td>{{ $rule->created_by_name }}</td>
                        <td class="text-xs" style="white-space:nowrap;">{{ $rule->created_at?->copy()->setTimezone(config('app.timezone'))->format('d/m/Y H:i:s') ?? '-' }}</td>
                        <td><form method="POST" action="{{ route('settings.permanent-ignores.destroy', $rule) }}" onsubmit="return confirm('Hapus aturan ini? Rule ID tersebut dapat diimpor kembali pada fetch berikutnya.')">
                            @csrf @method('DELETE')
                            <input type="hidden" name="_section" value="permanent-ignore">
                            <button type="submit" class="settings-icon-action is-danger" aria-label="Hapus aturan ignore permanen" title="Hapus aturan"><i data-lucide="trash-2" aria-hidden="true"></i></button>
                        </form></td>
                    </tr>
                    @empty
                    <tr><td colspan="4" class="text-muted" style="padding:var(--spacing-24); text-align:center;">Belum ada aturan ignore permanen.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($permanentIgnores->hasPages())<div style="margin-top:var(--spacing-16);">{{ $permanentIgnores->links('pagination::bootstrap-5') }}</div>@endif
        @if($legacyPermanentIgnores->total())
        <details class="permanent-ignore-legacy">
            <summary>{{ $legacyPermanentIgnores->total() }} catatan aturan lama untuk ditinjau</summary>
            <p class="settings-field-help">Catatan deskripsi lama tetap disimpan, tetapi tidak digunakan untuk memfilter alert. Periksa daftar Rule ID aktif di atas; tambahkan ID yang belum tercakup sebelum menghapus catatan lama.</p>
            <div class="permanent-ignore-table-wrap">
                <table class="data-table permanent-ignore-table">
                    <thead><tr><th scope="col">Deskripsi lama</th><th scope="col">Ditambahkan oleh</th><th scope="col">Aksi</th></tr></thead>
                    <tbody>@foreach($legacyPermanentIgnores as $rule)<tr>
                        <td><span class="permanent-ignore-description">{{ $rule->description }}</span></td><td>{{ $rule->created_by_name }}</td>
                        <td><form method="POST" action="{{ route('settings.permanent-ignores.destroy', $rule) }}" onsubmit="return confirm('Hapus catatan aturan lama ini?')">
                            @csrf @method('DELETE')<input type="hidden" name="_section" value="permanent-ignore">
                            <button type="submit" class="settings-icon-action is-danger" aria-label="Hapus catatan aturan lama"><i data-lucide="trash-2" aria-hidden="true"></i></button>
                        </form></td>
                    </tr>@endforeach</tbody>
                </table>
            </div>
            @if($legacyPermanentIgnores->hasPages()){{ $legacyPermanentIgnores->links('pagination::bootstrap-5') }}@endif
        </details>
        @endif
    </div>
</div>

