@section('title', 'Konfirmasi akses')
@section('auth-title', 'Konfirmasi akses')
@section('auth-context', 'Keamanan akun')
@section('auth-view', 'auth/confirm-password')
@section('auth-description', 'Masukkan kata sandi akun Anda untuk melanjutkan ke tindakan yang memerlukan konfirmasi.')
<x-guest-layout>
    <div class="access-context">
        <span class="access-context-label">Akun yang digunakan</span>
        <p class="technical-value">{{ auth()->user()->email }}</p>
    </div>
    <form method="POST" action="{{ route('password.confirm') }}" class="access-form">
        @csrf
        <div class="access-field">
            <x-input-label for="password" value="Kata sandi saat ini" />
            <x-text-input id="password" name="password" type="password" required autocomplete="current-password" autofocus aria-describedby="password-error" />
            <div id="password-error"><x-input-error :messages="$errors->get('password')" /></div>
        </div>
        <x-primary-button class="access-submit">Konfirmasi dan lanjutkan</x-primary-button>
    </form>
    <div class="access-footer"><a href="{{ route('profile.edit') }}">Kembali ke profil</a></div>
</x-guest-layout>
