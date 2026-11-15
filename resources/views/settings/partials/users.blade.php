<div class="card settings-user-management" x-data="userManagement()" @resize.window="$refs.userMenu.hidePopover()" @scroll.window="$refs.userMenu.hidePopover()">
    <div class="card-header settings-user-heading">
        <div>
            <h3 class="card-title">Manajemen user</h3>
            <p class="settings-field-help">{{ $users->count() }} pengguna · {{ $users->where('is_active', true)->count() }} aktif · Kelola role, agent, dan privilege akun.</p>
        </div>
        <button type="button" class="btn btn-primary btn-sm" @click="openUserEditor()" data-user-create><i data-lucide="plus" aria-hidden="true"></i> Tambah pengguna</button>
    </div>
    <div class="settings-access-inventory">
        <p class="settings-field-help">Admin memiliki akses penuh. Analis hanya dapat mengakses agent yang diizinkan.</p>
        <button type="button" class="btn btn-ghost btn-sm" @click="loadAgents(true)" :disabled="inventoryLoading" x-text="inventoryLoading ? 'Memuat agent...' : 'Muat ulang agent'"></button>
        <p x-show="inventoryError" x-text="inventoryError" class="form-error" role="alert" x-cloak></p>
    </div>
    <div class="settings-user-list">
        <table class="data-table settings-user-table">
            <caption class="sr-only">Daftar pengguna dan akses agent</caption>
            <thead><tr><th scope="col">Nama</th><th scope="col">Email</th><th scope="col">Role / status</th><th scope="col">Akses</th><th scope="col" class="settings-user-actions">Aksi</th></tr></thead>
            <tbody>
                @foreach($users as $user)
                <tr data-user-row="{{ $user->id }}">
                    <td class="settings-user-name">{{ $user->name }} @if($user->id === auth()->id())<span class="settings-field-help">(Anda)</span>@endif</td>
                    <td class="settings-user-email">{{ $user->email }}</td>
                    <td class="settings-user-role-cell"><span class="settings-role">{{ $user->isAdmin() ? 'Admin' : 'Analyst' }}</span><div class="settings-field-help">{{ $user->is_active ? 'Aktif' : 'Nonaktif' }}</div></td>
                    <td class="settings-user-access-cell">
                        <span>{{ $user->isAdmin() ? 'Semua agent' : count($user->allowed_agents ?? []).' agent' }}</span>
                        <small class="settings-field-help">{{ $user->isAdmin() ? 'Akses penuh' : (count($user->privileges ?? []) ? count($user->privileges).' privilege tambahan' : 'Hanya baca') }}</small>
                    </td>
                    <td class="settings-user-actions">
                        <button type="button" class="table-action" aria-label="Aksi pengguna {{ $user->name }}" aria-controls="user-actions-menu" :aria-expanded="menuUser?.id === {{ $user->id }}" @click="openUserMenu($event, {{ $user->id }})" data-user-menu="{{ $user->id }}"><i data-lucide="ellipsis-vertical" aria-hidden="true"></i></button>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div id="user-actions-menu" x-ref="userMenu" class="settings-user-menu" popover @toggle="if ($event.newState === 'closed' && !$el.matches(':popover-open')) menuUser = null" role="group" aria-label="Aksi pengguna">
        <button type="button" class="settings-user-menu-action" x-show="menuUser?.canEdit" @click="openUserEditor(menuUser.id)" data-user-edit><i data-lucide="pencil" aria-hidden="true"></i> Edit</button>
        <p class="settings-field-help" x-show="menuUser && !menuUser.canEdit">Profil admin lain dilindungi.</p>
        <form method="POST" :action="menuUser?.statusUrl" x-show="menuUser && menuUser.id !== {{ auth()->id() }}" @submit="if (!confirm((menuUser.isActive ? 'Nonaktifkan akun dan cabut seluruh sesinya? Alert ACK akan kembali ke NEW. ' : 'Aktifkan kembali akun? ') + menuUser.name)) $event.preventDefault(); $refs.userMenu.hidePopover()">
            @csrf @method('PATCH')
            <input type="hidden" name="is_active" :value="menuUser?.isActive ? '0' : '1'">
            <input type="hidden" name="_section" value="users">
            <button type="submit" class="settings-user-menu-action" :class="menuUser?.isActive ? 'is-danger' : ''" data-user-status><i data-lucide="power" aria-hidden="true"></i><span x-text="menuUser?.isActive ? 'Nonaktifkan akun' : 'Aktifkan akun'"></span></button>
        </form>
    </div>

    <dialog class="settings-user-dialog" x-ref="userEditor" aria-labelledby="user-editor-title" aria-describedby="user-editor-description" @close="clearUserPasswords()" @click="if ($event.target === $el && ($event.clientX < $el.getBoundingClientRect().left || $event.clientX > $el.getBoundingClientRect().right || $event.clientY < $el.getBoundingClientRect().top || $event.clientY > $el.getBoundingClientRect().bottom)) $el.close()">
        <header class="settings-user-dialog-header">
            <div><h3 id="user-editor-title" x-text="editingUserId ? 'Edit pengguna' : 'Tambah pengguna'">Pengaturan pengguna</h3><p id="user-editor-description" class="settings-field-help" x-text="editingUserId ? formEmail : 'Tentukan informasi akun dan hak akses pengguna baru.'"></p></div>
            <button type="button" class="icon-button" @click="$refs.userEditor.close()" aria-label="Tutup pengaturan pengguna"><i data-lucide="x" aria-hidden="true"></i></button>
        </header>
        <form method="POST" :action="userFormAction" action="{{ route('settings.users.create') }}" class="settings-user-form" x-ref="userEditorForm">
            @csrf
            <input type="hidden" name="_method" :value="editingUserId ? 'PATCH' : 'POST'">
            <input type="hidden" name="_section" value="users">
            <input type="hidden" name="_user_form" value="1">
            <input type="hidden" name="_editing_user" :value="editingUserId || ''">
            <div class="settings-user-dialog-body">
                @if($errors->any() && old('_user_form'))
                <div class="alert-msg error" role="alert"><div><strong>Perubahan belum disimpan.</strong><ul class="form-error">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div></div>
                @endif
                <div class="settings-user-account-fields">
                    <div class="form-group"><label class="form-label" for="user-editor-name">Nama</label><input id="user-editor-name" x-ref="userName" type="text" name="name" class="form-input" x-model="formName" required maxlength="255" autocomplete="name"></div>
                    <div class="form-group"><label class="form-label" for="user-editor-email">Email</label><input id="user-editor-email" type="email" name="email" class="form-input" x-model="formEmail" :readonly="!!editingUserId" required autocomplete="email"></div>
                    <div class="form-group"><label class="form-label" for="user-editor-role">Role</label><select id="user-editor-role" name="role" class="form-select" x-model="accessRole" required><option value="analyst">Analyst</option><option value="admin">Admin</option></select></div>
                </div>
                <div class="settings-user-permissions">@include('settings.partials.user-access-fields')</div>
                <div class="settings-user-account-fields settings-user-password-fields">
                    <div class="form-group"><label class="form-label" for="user-editor-password" x-text="editingUserId ? 'Password baru (opsional)' : 'Password'">Password</label><input id="user-editor-password" type="password" name="password" class="form-input" :required="!editingUserId" minlength="8" autocomplete="new-password"></div>
                    <div class="form-group"><label class="form-label" for="user-editor-password-confirmation">Konfirmasi password</label><input id="user-editor-password-confirmation" type="password" name="password_confirmation" class="form-input" :required="!editingUserId" autocomplete="new-password"></div>
                </div>
            </div>
            <footer class="settings-user-dialog-footer"><button type="button" class="btn btn-ghost" @click="$refs.userEditor.close()">Batal</button><button type="submit" class="btn btn-primary" x-text="editingUserId ? 'Simpan perubahan' : 'Buat pengguna'">Simpan</button></footer>
        </form>
    </dialog>
</div>
