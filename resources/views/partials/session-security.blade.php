@auth
<meta name="session-activity-url" content="{{ route('session.activity', [], false) }}">
<meta name="session-logout-url" content="{{ route('logout', [], false) }}">
<meta name="session-login-url" content="{{ route('login', [], false) }}">
<meta name="session-activity-at" content="{{ \App\Http\Middleware\EnforceSessionSecurity::lastActivity(request()) * 1000 }}">
<meta name="session-server-now" content="{{ now()->timestamp * 1000 }}">
<meta name="session-channel" content="{{ hash('sha256', session()->getId()) }}">
@endauth
