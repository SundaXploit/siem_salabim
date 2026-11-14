<header class="account-section-heading">
    <h2 id="profile-information-heading">Informasi akun</h2>
    <p>Perbarui nama dan alamat email yang digunakan untuk akun ini.</p>
</header>
<form id="send-verification" method="POST" action="{{ route('verification.send') }}">@csrf</form>
<form method="POST" action="{{ route('profile.update') }}" class="account-form">
    @csrf
    @method('PATCH')
    <div class="account-information-fields">
        <div class="access-field">
            <x-input-label for="name" value="Nama lengkap" />
            <x-text-input id="name" name="name" type="text" :value="old('name', $user->name)" required autocomplete="name" aria-describedby="profile-name-error" />
            <div id="profile-name-error"><x-input-error :messages="$errors->get('name')" /></div>
        </div>
        <div class="access-field">
            <x-input-label for="email" value="Alamat email" />
            <x-text-input id="email" name="email" type="email" :value="old('email', $user->email)" required autocomplete="email" aria-describedby="profile-email-error" />
            <div id="profile-email-error"><x-input-error :messages="$errors->get('email')" /></div>
        </div>
    </div>
    @if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $user->hasVerifiedEmail())
        <div class="account-notice">
            <p>Alamat email Anda belum diverifikasi.</p>
            <button type="submit" form="send-verification" class="access-text-button">Kirim ulang email verifikasi</button>
            @if (session('status') === 'verification-link-sent')
                <p class="account-save-status" role="status">Tautan verifikasi baru telah dikirim.</p>
            @endif
        </div>
    @endif
    <div class="account-actions">
        <x-primary-button>Simpan informasi akun</x-primary-button>
        @if (session('status') === 'profile-updated')
            <p class="account-save-status" role="status">Informasi akun telah disimpan.</p>
        @endif
    </div>
</form>
