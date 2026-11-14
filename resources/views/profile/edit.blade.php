@extends('layouts.app')
@section('body-class', 'density-comfortable profile-page')
@section('title', 'Profil')
@section('page-context', 'Akun')
@section('page-title', 'Profil')
@section('page-subtitle', 'Informasi pribadi dan pengaturan keamanan akses Anda.')
@section('content')
    <div class="profile-workspace" data-ui-view="profile/edit">
        <aside class="profile-context" aria-label="Ringkasan akun">
            <div class="profile-account-name">
                <p class="profile-context-label">Akun SOC</p>
                <h2>{{ $user->name }}</h2>
            </div>
            <dl class="profile-identity">
                <div><dt>Email</dt><dd>{{ $user->email }}</dd></div>
                <div><dt>Peran</dt><dd>{{ $user->role }}</dd></div>
            </dl>
        </aside>
        <div class="profile-forms">
            <section id="informasi-akun" class="account-section" aria-labelledby="profile-information-heading">
                @include('profile.partials.update-profile-information-form')
            </section>
            <section id="kata-sandi" class="account-section" aria-labelledby="profile-password-heading">
                @include('profile.partials.update-password-form')
            </section>
        </div>
    </div>
@endsection
