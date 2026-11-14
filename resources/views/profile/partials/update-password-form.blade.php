<header class="account-section-heading">
    <h2 id="profile-password-heading">Kata sandi</h2>
    <p>Gunakan kata sandi yang kuat dan tidak digunakan pada layanan lain.</p>
</header>
<form method="POST" action="{{ route('password.update') }}" class="account-form">
    @csrf
    @method('PUT')
    <div class="access-field">
        <x-input-label for="update_password_current_password" value="Kata sandi saat ini" />
        <x-text-input id="update_password_current_password" name="current_password" type="password" autocomplete="current-password" aria-describedby="current-password-error" />
        <div id="current-password-error"><x-input-error :messages="$errors->updatePassword->get('current_password')" /></div>
    </div>
    <div class="account-field-grid">
        <div class="access-field">
            <x-input-label for="update_password_password" value="Kata sandi baru" />
            <x-text-input id="update_password_password" name="password" type="password" autocomplete="new-password" aria-describedby="new-password-error" />
            <div id="new-password-error"><x-input-error :messages="$errors->updatePassword->get('password')" /></div>
        </div>
        <div class="access-field">
            <x-input-label for="update_password_password_confirmation" value="Ulangi kata sandi baru" />
            <x-text-input id="update_password_password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" aria-describedby="password-confirmation-error" />
            <div id="password-confirmation-error"><x-input-error :messages="$errors->updatePassword->get('password_confirmation')" /></div>
        </div>
    </div>
    <div class="account-actions">
        <x-primary-button>Perbarui kata sandi</x-primary-button>
        @if (session('status') === 'password-updated')
            <p class="account-save-status" role="status">Kata sandi telah diperbarui.</p>
        @endif
    </div>
</form>
