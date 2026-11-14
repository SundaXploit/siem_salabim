@section('title', 'Verifikasi email')
@section('auth-title', 'Verifikasi alamat email')
@section('auth-context', 'Keamanan akun')
@section('auth-view', 'auth/verify-email')
@section('auth-description', 'Buka tautan verifikasi yang dikirim ke email Anda untuk mengonfirmasi alamat yang digunakan pada akun SOC.')
<x-guest-layout>
    <div class="access-context">
        <span class="access-context-label">Email akun</span>
        <p class="technical-value">{{ auth()->user()->email }}</p>
    </div>
    @if (session('status') == 'verification-link-sent')
        <div class="access-notice" role="status">Tautan verifikasi baru telah dikirim. Periksa kotak masuk dan folder spam.</div>
    @endif
    @if($errors->any())
        <div class="alert-msg error" role="alert"><x-input-error :messages="$errors->all()" /></div>
    @endif
    <form method="POST" action="{{ route('verification.send') }}" class="access-form">
        @csrf
        <p class="field-guidance">Belum menerima email? Anda dapat meminta tautan baru.</p>
        <x-primary-button class="access-submit">Kirim ulang email verifikasi</x-primary-button>
    </form>
    <div class="access-footer access-footer-actions">
        <a href="{{ route('profile.edit') }}">Periksa alamat email</a>
        <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" class="access-text-button">Keluar dari akun</button></form>
    </div>
</x-guest-layout>
