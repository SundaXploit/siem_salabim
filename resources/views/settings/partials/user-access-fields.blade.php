<input type="hidden" name="_access_settings" value="1">
<p class="settings-field-help" x-show="accessRole === 'admin'">Admin dapat mengakses seluruh agent dan pengaturan sistem.</p>
<fieldset class="settings-access-fieldset" x-show="accessRole === 'analyst'" :disabled="accessRole !== 'analyst'">
    <legend>Agent yang diizinkan</legend>
    <p class="settings-field-help">Pilih agent yang boleh diakses oleh akun ini.</p>
    <div class="settings-access-search">
        <input type="search" class="form-input" placeholder="Cari nama atau ID" x-model="agentSearch" @keydown.enter.prevent aria-label="Cari agent untuk hak akses">
        <button type="button" class="icon-button" @click="loadAgents(true)" :disabled="inventoryLoading" aria-label="Muat ulang daftar agent" title="Muat ulang daftar agent" data-access-refresh><i data-lucide="refresh-cw" aria-hidden="true"></i></button>
    </div>
    <p x-show="inventoryError" x-text="inventoryError" class="form-error" role="alert" x-cloak></p>
    <template x-for="key in selectedAgents" :key="key"><input type="hidden" name="agent_keys[]" :value="key"></template>
    <div class="settings-access-agents">
        <template x-for="agent in agentOptions(savedAgents, agentSearch)" :key="agent.key">
            <label class="settings-access-option">
                <input type="checkbox" :value="agent.key" x-model="selectedAgents" :data-access-agent="agent.id">
                <span><span x-text="agent.name"></span><small class="settings-field-help" x-text="'ID '+agent.id+' · '+agent.source_cluster"></small></span>
            </label>
        </template>
        <p class="settings-field-help" x-show="!agentOptions(savedAgents, agentSearch).length" x-text="inventoryLoading ? 'Memuat agent...' : 'Tidak ada agent yang cocok. Muat ulang daftar atau ubah pencarian.'"></p>
    </div>
    <p class="settings-field-help" x-text="selectedAgents.length+' agent dipilih'"></p>
    <p class="settings-field-help" x-show="!selectedAgents.length">Tanpa agent yang dipilih, akun analis tidak dapat melihat alert.</p>
</fieldset>
<fieldset class="settings-access-fieldset" x-show="accessRole === 'analyst'" :disabled="accessRole !== 'analyst'">
    <legend>Checklist privilege</legend>
    <label class="settings-access-option"><input type="checkbox" checked disabled><span>Lihat alert dan riwayat <small class="settings-field-help">Hanya untuk agent yang dipilih.</small></span></label>
    @foreach(\App\Models\User::PRIVILEGES as $privilege => $label)
        @if($privilege !== 'alerts.view')
        <label class="settings-access-option"><input type="checkbox" name="privileges[]" value="{{ $privilege }}" x-model="selectedPrivileges"><span>{{ $label }}</span></label>
        @endif
    @endforeach
    <p class="settings-field-help">Hak tambahan tidak dicentang secara default. Akses Settings dan Log aktivitas tetap khusus admin.</p>
</fieldset>
