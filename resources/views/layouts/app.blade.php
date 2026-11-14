<!DOCTYPE html>
<html lang="id" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @include('partials.brand-head')
    <title>@yield('title', 'Dashboard') — SIEM Salabim</title>
    <meta name="description" content="@yield('description', 'Security Information and Event Management — SOC Subang')">
    <script>
        (function () {
            try {
                var storedTheme = localStorage.getItem('siem-theme');
                var theme = storedTheme === 'light' ? 'light' : 'dark';
                document.documentElement.dataset.theme = theme;
                document.documentElement.classList.remove('dark', 'light');
                document.documentElement.classList.add(theme);
            } catch (_) {
                document.documentElement.dataset.theme = 'dark';
                document.documentElement.classList.add('dark');
            }
        })();
        (function () {
            try {
                if (localStorage.getItem('siem-sidebar-collapsed') === 'true') {
                    document.documentElement.classList.add('sidebar-collapsed');
                }
            } catch (_) {
                // The expanded sidebar remains the safe default when storage is unavailable.
            }
        })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('styles')
</head>
<body class="app-shell @yield('body-class')" x-data="siemApp()" @keydown.escape.window="closeMobileSidebar()" @keydown.tab.window="trapSidebarFocus($event)">

    <a class="skip-link" href="#main-workspace">Langsung ke konten</a>
    <aside id="primary-sidebar" class="sidebar" :class="{ 'is-mobile-open': sidebarOpen }" :inert="isMobile && !sidebarOpen">
        <div class="sidebar-logo">
            <a href="{{ route('dashboard.index') }}" class="sidebar-brand-link" aria-label="SIEM Salabim — Dashboard">
                <x-application-logo alt="" />
                <span class="sidebar-wordmark">SIEM <strong>Salabim</strong></span>
            </a>
            <button type="button" class="sidebar-collapse-toggle" @click="toggleSidebar()" :aria-expanded="(!sidebarCollapsed).toString()" :title="sidebarCollapsed ? 'Perluas sidebar' : 'Persempit sidebar'" :aria-label="sidebarCollapsed ? 'Perluas sidebar' : 'Persempit sidebar'" aria-controls="primary-sidebar">
                <i class="sidebar-collapse-icon-close" data-lucide="panel-left-close" aria-hidden="true"></i>
                <i class="sidebar-collapse-icon-open" data-lucide="panel-left-open" aria-hidden="true"></i>
            </button>
        </div>

        <nav class="sidebar-nav" aria-label="Navigasi utama">
            <div class="nav-section">Monitoring</div>
            <a href="{{ route('dashboard.index') }}" class="nav-item {{ request()->routeIs('dashboard.*') ? 'active' : '' }}" title="Dashboard">
                <span class="nav-icon" aria-hidden="true"><i data-lucide="layout-dashboard"></i></span>
                <span class="nav-label">Dashboard</span>
            </a>
            <a href="{{ route('alerts.index') }}" class="nav-item {{ request()->routeIs('alerts.index') || request()->routeIs('alerts.show') ? 'active' : '' }}" title="Live Alerts">
                <span class="nav-icon" aria-hidden="true"><i data-lucide="bell"></i></span>
                <span class="nav-label">Live Alerts</span>
                <span class="nav-badge" x-show="newAlertCount > 0" x-text="newAlertCount > 99 ? '99+' : newAlertCount" style="display:none;"></span>
            </a>
            <a href="{{ route('leaderboard.index') }}" class="nav-item {{ request()->routeIs('leaderboard.*') ? 'active' : '' }}" title="Leaderboard">
                <span class="nav-icon" aria-hidden="true"><i data-lucide="trophy"></i></span><span class="nav-label">Leaderboard</span>
            </a>

            <div class="nav-section">History</div>
            <a href="{{ route('history.alerts') }}" class="nav-item {{ request()->routeIs('history.alerts') ? 'active' : '' }}" title="Riwayat Alert">
                <span class="nav-icon" aria-hidden="true"><i data-lucide="clipboard-list"></i></span><span class="nav-label">Riwayat Alert</span>
            </a>
            <a href="{{ route('analyst-log.index') }}" class="nav-item {{ request()->routeIs('analyst-log.*') ? 'active' : '' }}" title="Log analis">
                <span class="nav-icon" aria-hidden="true"><i data-lucide="clipboard-list"></i></span><span class="nav-label">Log analis</span>
            </a>

            @if(auth()->user()->isAdmin())
            <div class="nav-section">Admin</div>
            <a href="{{ route('settings.index') }}" class="nav-item {{ request()->routeIs('settings.*') ? 'active' : '' }}" title="Settings">
                <span class="nav-icon" aria-hidden="true"><i data-lucide="settings"></i></span><span class="nav-label">Settings</span>
            </a>
            <a href="{{ route('history.activity') }}" class="nav-item {{ request()->routeIs('history.activity') ? 'active' : '' }}" title="Log aktivitas">
                <span class="nav-icon" aria-hidden="true"><i data-lucide="history"></i></span><span class="nav-label">Log aktivitas</span>
            </a>
            @endif
        </nav>

        <div class="sidebar-footer">
            <div class="user-card">
                <div class="user-avatar" title="{{ auth()->user()->name }}">{{ substr(auth()->user()->name, 0, 1) }}</div>
                <div class="user-meta">
                    <a class="user-name" href="{{ route('profile.edit') }}" title="Buka profil">{{ auth()->user()->name }}</a>
                    <div class="user-role">{{ auth()->user()->role }}</div>
                </div>
                <form method="POST" action="{{ route('logout') }}" class="ml-auto">
                    @csrf
                    <button type="submit" class="logout-btn" title="Logout" aria-label="Logout"><i data-lucide="log-out"></i></button>
                </form>
            </div>
        </div>
    </aside>
    <div class="sidebar-backdrop" x-cloak x-show="sidebarOpen" @click="closeMobileSidebar()" aria-hidden="true"></div>

    <div class="main-content">
        <header class="topbar">
            <button type="button" class="mobile-sidebar-toggle" @click="toggleMobileSidebar()" :aria-expanded="sidebarOpen.toString()" :title="sidebarOpen ? 'Tutup menu' : 'Buka menu'" :aria-label="sidebarOpen ? 'Tutup menu' : 'Buka menu'" aria-controls="primary-sidebar">
                <i data-lucide="menu" aria-hidden="true"></i>
            </button>

            <a href="{{ route('dashboard.index') }}" class="topbar-identity" aria-label="SIEM Salabim — Dashboard">
                <x-application-logo alt="" />
                <span class="brand-name">SIEM <strong>Salabim</strong></span>
            </a>
            <div class="topbar-context">
                <div class="topbar-breadcrumb">
                    <span>Security Operations</span>
                    <i data-lucide="chevron-right" aria-hidden="true"></i>
                    <span>@yield('page-context', 'Monitoring')</span>
                </div>
            </div>
            <div class="topbar-right">
                <span class="workspace-label">Ruang kerja SOC</span>
                <div class="time-display" x-text="currentTime"></div>
                <!-- Dark / Light Mode Toggle -->
                <button id="theme-toggle-btn" class="theme-toggle" type="button" data-theme-toggle title="Ubah tema" aria-label="Ubah tema gelap atau terang">
                    <span class="theme-icon-dark" aria-hidden="true"><i data-lucide="moon"></i></span>
                    <span class="theme-icon-light" aria-hidden="true"><i data-lucide="sun"></i></span>
                </button>
            </div>
        </header>

        <main id="main-workspace" class="page-content" data-ui-page tabindex="-1">
            <header class="page-header">
                <div class="page-header-copy">
                    <div class="page-eyebrow">@yield('page-context', 'Monitoring')</div>
                    <h1 class="page-heading">@yield('page-title', 'Dashboard')</h1>
                    @hasSection('page-subtitle')
                        <p class="page-description">@yield('page-subtitle')</p>
                    @endif
                </div>
                @hasSection('page-actions')
                    <div class="page-actions">@yield('page-actions')</div>
                @endif
            </header>
            @if(session('success'))
                <div class="alert-msg success" role="status"><i data-lucide="circle-check" aria-hidden="true"></i><span>{{ session('success') }}</span></div>
            @endif
            @if(session('error'))
                <div class="alert-msg error" role="alert"><i data-lucide="circle-alert" aria-hidden="true"></i><span>{{ session('error') }}</span></div>
            @endif

            @yield('content')
            @isset($slot)
                {{ $slot }}
            @endisset
        </main>
    </div>

    <script>
    /* ─── Theme Toggle ─── */
    /* ─── Alpine App ─── */
    function siemApp() {
        return {
            newAlertCount: {{ $layoutNewAlertCount ?? 0 }},
            currentTime: '',
            sidebarCollapsed: document.documentElement.classList.contains('sidebar-collapsed'),
            sidebarOpen: false,
            isMobile: window.matchMedia('(max-width: 960px)').matches,
            clockTimer: null,
            countTimer: null,
            resizeHandler: null,
            init() {
                this.resizeHandler = () => {
                    this.isMobile = window.matchMedia('(max-width: 960px)').matches;
                    if (!window.matchMedia('(max-width: 960px)').matches) {
                        this.closeMobileSidebar();
                    }
                };
                window.addEventListener('resize', this.resizeHandler);
                this.updateTime();
                this.clockTimer = setInterval(() => this.updateTime(), 1000);
                this.countTimer = setInterval(() => this.pollNewCount(), 30000);
            },
            destroy() {
                clearInterval(this.clockTimer);
                clearInterval(this.countTimer);
                window.removeEventListener('resize', this.resizeHandler);
            },
            toggleSidebar() {
                if (window.matchMedia('(max-width: 960px)').matches) return;

                this.sidebarCollapsed = !this.sidebarCollapsed;
                document.documentElement.classList.toggle('sidebar-collapsed', this.sidebarCollapsed);

                try {
                    localStorage.setItem('siem-sidebar-collapsed', this.sidebarCollapsed ? 'true' : 'false');
                } catch (_) {}
            },
            toggleMobileSidebar() {
                if (!window.matchMedia('(max-width: 960px)').matches) return;
                this.sidebarOpen = !this.sidebarOpen;
                if (this.sidebarOpen) {
                    this.$nextTick(() => document.querySelector('.sidebar-nav a')?.focus());
                }
            },
            closeMobileSidebar() {
                const wasOpen = this.sidebarOpen;
                this.sidebarOpen = false;
                if (wasOpen && this.isMobile) document.querySelector('.mobile-sidebar-toggle')?.focus();
            },
            trapSidebarFocus(event) {
                if (!this.sidebarOpen || !this.isMobile) return;
                const controls = [...document.querySelectorAll('#primary-sidebar a, #primary-sidebar button')]
                    .filter(control => control.getClientRects().length && !control.disabled);
                const first = controls[0], last = controls.at(-1);
                if (event.shiftKey && document.activeElement === first) {
                    event.preventDefault(); last?.focus();
                } else if (!event.shiftKey && document.activeElement === last) {
                    event.preventDefault(); first?.focus();
                }
            },
            updateTime() {
                const now = new Date();
                this.currentTime = now.toLocaleString('id-ID', {
                    timeZone: 'Asia/Jakarta',
                    hour: '2-digit', minute: '2-digit', second: '2-digit',
                    day: '2-digit', month: 'short', year: 'numeric'
                }) + ' WIB';
            },
            async pollNewCount() {
                try {
                    const res = await fetch('/api/alerts/new-count');
                    if (!res.ok) return;
                    const data = await res.json();
                    this.newAlertCount = data.count;
                } catch(e) {}
            }
        }
    }
    </script>
    @stack('scripts')
</body>
</html>
