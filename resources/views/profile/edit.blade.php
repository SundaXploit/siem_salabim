@extends('layouts.app')

@section('title', 'Profil')
@section('page-context', 'Account')
@section('page-title', 'Profil')
@section('page-subtitle', 'Kelola informasi akun dan keamanan akses Anda')

@section('content')
    <div class="profile-stack">
        <section class="profile-section">
            <div class="profile-section-body">
                    @include('profile.partials.update-profile-information-form')
                </div>

        </section>

        <section class="profile-section">
            <div class="profile-section-body">
                    @include('profile.partials.update-password-form')
                </div>

        </section>

        <section class="profile-section profile-section-danger">
            <div class="profile-section-body">
                    @include('profile.partials.delete-user-form')
                </div>
        </section>
    </div>
@endsection
