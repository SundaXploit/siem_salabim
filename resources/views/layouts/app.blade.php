<!DOCTYPE html>
<html lang="id" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="{{ asset('images/brand/siem-salabim-mark.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('images/brand/siem-salabim-mark.png') }}">
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
    <style media="not all" data-legacy-shell="true">
        /* ─── Dark Mode Variables ─── */
        :root, html.dark {
            --bg-primary:    #0a0a0f;
            --bg-secondary:  #12121a;
            --bg-card:       #1a1a25;
            --border:        #2a2a3a;
            --text-primary:  #f5f5f7;
            --text-secondary:#9ca3af;
            --accent:        #6366f1;
            --accent-v:      #8b5cf6;
            --accent-glow:   rgba(99,102,241,0.15);
            --accent-hover:  #4f46e5;
            --success:       #10b981;
            --warning:       #f59e0b;
            --danger:        #ef4444;
            --info:          #06b6d4;
            --sidebar-w:     260px;
        }

        /* ─── Light Mode Variables ─── */
        html.light {
            --bg-primary:    #fafafa;
            --bg-secondary:  #ffffff;
            --bg-card:       #f4f4f5;
            --border:        #e4e4e7;
            --text-primary:  #18181b;
            --text-secondary:#71717a;
            --accent:        #4f46e5;
            --accent-v:      #7c3aed;
            --accent-glow:   rgba(79,70,229,0.1);
            --accent-hover:  #4338ca;
            --success:       #10b981;
            --warning:       #f59e0b;
            --danger:        #ef4444;
            --info:          #06b6d4;
            --sidebar-w:     260px;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            display: flex;
            min-height: 100vh;
            transition: background 0.3s ease, color 0.3s ease;
        }

        /* ─── Sidebar ─── */
        .sidebar {
            width: var(--sidebar-w);
            background: var(--bg-secondary);
            border-right: 1px solid var(--border);
            position: fixed;
            top: 0; left: 0; bottom: 0;
            display: flex;
            flex-direction: column;
            z-index: 100;
            overflow-y: auto;
            transition: background 0.3s ease, border-color 0.3s ease;
        }

        .sidebar-logo {
            padding: 1.5rem 1.25rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .logo-icon {
            width: 40px; height: 40px;
            background: linear-gradient(135deg, var(--accent), var(--accent-v));
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.2rem;
            box-shadow: 0 0 20px var(--accent-glow);
        }

        .logo-text { font-size: 1rem; font-weight: 700; }
        .logo-sub  { font-size: 0.65rem; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 1px; }

        .nav-section {
            padding: 1rem 0.75rem 0.25rem;
            font-size: 0.65rem; font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase; letter-spacing: 1px;
        }

        .nav-item {
            display: flex; align-items: center; gap: 0.75rem;
            padding: 0.625rem 1rem;
            margin: 0.125rem 0.5rem;
            border-radius: 8px;
            color: var(--text-secondary);
            font-size: 0.875rem; font-weight: 500;
            text-decoration: none;
            transition: all 0.2s ease;
            position: relative;
        }

        .nav-item:hover {
            background: var(--accent-glow);
            color: var(--text-primary);
        }

        .nav-item.active {
            background: var(--accent-glow);
            color: var(--accent);
            border: 1px solid rgba(99,102,241,0.2);
        }

        html.light .nav-item.active {
            border-color: rgba(79,70,229,0.2);
        }

        .nav-item.active::before {
            content: '';
            position: absolute;
            left: 0; top: 20%; bottom: 20%;
            width: 3px;
            border-radius: 2px;
            background: var(--accent);
        }

        .nav-icon { font-size: 1rem; width: 20px; text-align: center; }

        .nav-badge {
            margin-left: auto;
            background: var(--danger); color: #fff;
            font-size: 0.65rem; font-weight: 700;
            padding: 0.15rem 0.5rem;
            border-radius: 20px;
            min-width: 20px; text-align: center;
        }

        .sidebar-footer {
            margin-top: auto;
            padding: 1rem;
            border-top: 1px solid var(--border);
        }

        .user-card {
            display: flex; align-items: center; gap: 0.75rem;
            padding: 0.75rem;
            border-radius: 10px;
            background: var(--bg-card);
            border: 1px solid var(--border);
        }

        .user-avatar {
            width: 36px; height: 36px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--accent), var(--accent-v));
            display: flex; align-items: center; justify-content: center;
            font-size: 0.875rem; font-weight: 700;
            color: #fff;
            flex-shrink: 0;
        }

        .user-name { font-size: 0.8rem; font-weight: 600; }
        .user-role { font-size: 0.65rem; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.5px; }

        .logout-btn {
            margin-left: auto;
            padding: 0.35rem 0.6rem;
            border-radius: 6px;
            background: transparent;
            border: 1px solid var(--border);
            color: var(--text-secondary);
            font-size: 0.75rem;
            cursor: pointer;
            transition: all 0.2s;
        }
        .logout-btn:hover { background: var(--danger); border-color: var(--danger); color: #fff; }

        /* ─── Main Content ─── */
        .main-content {
            margin-left: var(--sidebar-w);
            flex: 1;
            display: flex; flex-direction: column;
            min-height: 100vh;
        }

        .topbar {
            background: var(--bg-secondary);
            border-bottom: 1px solid var(--border);
            padding: 0.875rem 1.5rem;
            display: flex; align-items: center; gap: 1rem;
            position: sticky; top: 0; z-index: 50;
            transition: background 0.3s ease, border-color 0.3s ease;
        }

        .topbar-title { font-size: 1rem; font-weight: 700; }
        .topbar-sub { font-size: 0.75rem; color: var(--text-secondary); margin-left: 0.5rem; }

        .topbar-right { margin-left: auto; display: flex; align-items: center; gap: 1rem; }

        .live-badge {
            display: flex; align-items: center; gap: 0.4rem;
            padding: 0.3rem 0.75rem;
            border-radius: 20px;
            background: rgba(16,185,129,0.1);
            border: 1px solid rgba(16,185,129,0.3);
            font-size: 0.75rem;
            color: var(--success);
            font-weight: 600;
        }

        .live-dot {
            width: 8px; height: 8px;
            border-radius: 50%;
            background: var(--success);
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50%       { opacity: 0.5; transform: scale(0.8); }
        }

        .time-display { font-size: 0.75rem; color: var(--text-secondary); font-family: monospace; }

        /* ─── Theme Toggle Button ─── */
        .theme-toggle {
            width: 36px; height: 36px;
            border-radius: 8px;
            background: var(--bg-card);
            border: 1px solid var(--border);
            color: var(--text-secondary);
            display: flex; align-items: center; justify-content: center;
            cursor: pointer;
            font-size: 1rem;
            transition: all 0.2s ease;
            flex-shrink: 0;
        }
        .theme-toggle:hover {
            background: var(--accent-glow);
            color: var(--accent);
            border-color: var(--accent);
        }

        /* ─── Page Content ─── */
        .page-content { padding: 1.5rem; flex: 1; }

        /* ─── Cards ─── */
        .card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
            transition: background 0.3s ease, border-color 0.3s ease;
        }

        .card-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--border);
            display: flex; align-items: center; gap: 0.75rem;
        }

        .card-title { font-size: 0.9rem; font-weight: 700; }
        .card-body { padding: 1.25rem; }

        /* ─── Badges ─── */
        .badge {
            padding: 0.2rem 0.6rem;
            border-radius: 20px;
            font-size: 0.68rem; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.5px;
        }
        .badge-new          { background: rgba(239,68,68,0.15); color: #ef4444; border: 1px solid rgba(239,68,68,0.3); }
        .badge-acknowledged { background: rgba(245,158,11,0.15); color: #f59e0b; border: 1px solid rgba(245,158,11,0.3); }
        .badge-ignored      { background: rgba(156,163,175,0.15); color: var(--text-secondary); border: 1px solid rgba(156,163,175,0.3); }
        .level-critical { background: rgba(220,38,38,0.2); color: #ef4444; border: 1px solid rgba(220,38,38,0.4); }
        .level-high     { background: rgba(249,115,22,0.2); color: #f97316; border: 1px solid rgba(249,115,22,0.4); }
        .level-medium   { background: rgba(245,158,11,0.2); color: #f59e0b; border: 1px solid rgba(245,158,11,0.4); }
        .level-low      { background: rgba(16,185,129,0.2); color: #10b981; border: 1px solid rgba(16,185,129,0.4); }

        /* ─── Data Table ─── */
        .data-table { width: 100%; border-collapse: collapse; }
        .data-table th {
            padding: 0.75rem 1rem;
            text-align: left;
            font-size: 0.7rem; font-weight: 700;
            color: var(--text-secondary);
            text-transform: uppercase; letter-spacing: 0.5px;
            border-bottom: 1px solid var(--border);
            background: var(--bg-secondary);
        }
        .data-table td {
            padding: 0.75rem 1rem;
            font-size: 0.8rem;
            color: var(--text-secondary);
            border-bottom: 1px solid var(--border);
        }
        .data-table tr:hover td { background: var(--accent-glow); }
        .data-table tr.row-ignored td      { opacity: 0.6; }

        /* ─── Buttons ─── */
        .btn {
            display: inline-flex; align-items: center; gap: 0.4rem;
            padding: 0.4rem 0.875rem;
            border-radius: 7px;
            font-size: 0.78rem; font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            border: none;
            text-decoration: none;
            font-family: 'Plus Jakarta Sans', sans-serif;
        }
        .btn-primary { background: var(--accent); color: #fff; }
        .btn-primary:hover { background: var(--accent-hover); box-shadow: 0 0 20px var(--accent-glow); }
        .btn-success { background: rgba(16,185,129,0.15); color: var(--success); border: 1px solid rgba(16,185,129,0.3); }
        .btn-success:hover { background: var(--success); color: #fff; }
        .btn-warning { background: rgba(245,158,11,0.15); color: var(--warning); border: 1px solid rgba(245,158,11,0.3); }
        .btn-warning:hover { background: var(--warning); color: #fff; }
        .btn-danger { background: rgba(239,68,68,0.15); color: var(--danger); border: 1px solid rgba(239,68,68,0.3); }
        .btn-danger:hover { background: var(--danger); color: #fff; }
        .btn-ghost { background: transparent; color: var(--text-secondary); border: 1px solid var(--border); }
        .btn-ghost:hover { background: var(--bg-card); color: var(--text-primary); }
        .btn-sm { padding: 0.3rem 0.65rem; font-size: 0.72rem; }

        /* ─── Forms ─── */
        .form-input, .form-select, .form-textarea {
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            color: var(--text-primary);
            border-radius: 8px;
            padding: 0.5rem 0.875rem;
            font-size: 0.85rem;
            font-family: 'Plus Jakarta Sans', sans-serif;
            width: 100%;
            transition: border-color 0.2s, box-shadow 0.2s;
            outline: none;
        }
        .form-input:focus, .form-select:focus, .form-textarea:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px var(--accent-glow);
        }
        .form-label { font-size: 0.78rem; font-weight: 600; color: var(--text-secondary); margin-bottom: 0.35rem; display: block; }
        .form-group { margin-bottom: 1rem; }

        /* ─── Alert Messages ─── */
        .alert-msg {
            padding: 0.875rem 1rem;
            border-radius: 8px;
            font-size: 0.82rem;
            margin-bottom: 1rem;
            display: flex; align-items: center; gap: 0.5rem;
        }
        .alert-msg.success { background: rgba(16,185,129,0.1); border: 1px solid rgba(16,185,129,0.3); color: var(--success); }
        .alert-msg.error   { background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.3); color: var(--danger); }

        /* ─── Modals ─── */
        .modal-backdrop {
            position: fixed; inset: 0;
            background: rgba(0,0,0,0.6);
            backdrop-filter: blur(4px);
            z-index: 200;
            display: flex; align-items: center; justify-content: center;
            padding: 1rem;
        }
        html.light .modal-backdrop { background: rgba(0,0,0,0.35); }

        .modal-box {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 14px;
            width: 100%; max-width: 560px;
            max-height: 90vh; overflow-y: auto;
            padding: 1.5rem;
            box-shadow: 0 25px 60px rgba(0,0,0,0.4);
            animation: modalIn 0.2s ease;
        }
        @keyframes modalIn {
            from { opacity: 0; transform: scale(0.95) translateY(10px); }
            to   { opacity: 1; transform: scale(1) translateY(0); }
        }
        .modal-title { font-size: 1rem; font-weight: 700; margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem; }

        /* ─── Stat Cards ─── */
        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 1.5rem; }
        .stat-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 1.25rem;
            position: relative; overflow: hidden;
        }
        .stat-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px; }
        .stat-card.red::before    { background: var(--danger); }
        .stat-card.yellow::before { background: var(--warning); }
        .stat-card.gray::before   { background: var(--text-secondary); }
        .stat-card.blue::before   { background: var(--accent); }
        .stat-label { font-size: 0.72rem; color: var(--text-secondary); font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
        .stat-value { font-size: 2rem; font-weight: 800; margin: 0.25rem 0; }
        .stat-card.red .stat-value    { color: var(--danger); }
        .stat-card.yellow .stat-value { color: var(--warning); }
        .stat-card.gray .stat-value   { color: var(--text-secondary); }
        .stat-card.blue .stat-value   { color: var(--accent); }
        .stat-sub { font-size: 0.72rem; color: var(--text-secondary); }

        /* ─── Filter Bar ─── */
        .filter-bar { display: flex; gap: 0.75rem; align-items: flex-end; flex-wrap: wrap; margin-bottom: 1.25rem; }
        .filter-bar .form-input, .filter-bar .form-select { width: auto; }
        .filter-bar .search-input { flex: 1; min-width: 200px; }

        /* ─── Utility Classes ─── */
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        .flex { display: flex; } .items-center { align-items: center; }
        .gap-2 { gap: 0.5rem; } .gap-3 { gap: 0.75rem; }
        .mb-1{margin-bottom:0.25rem} .mb-2{margin-bottom:0.5rem} .mb-3{margin-bottom:0.75rem} .mb-4{margin-bottom:1rem}
        .ml-auto{margin-left:auto} .mt-4{margin-top:1rem} .w-full{width:100%}
        .text-muted{color:var(--text-secondary)} .text-danger{color:var(--danger)} .text-success{color:var(--success)}
        .text-warning{color:var(--warning)} .text-accent{color:var(--accent)} .text-sm{font-size:0.8rem}
        .truncate-cell { max-width: 200px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .divider { border: none; border-top: 1px solid var(--border); margin: 1rem 0; }

        /* ─── Scrollbar ─── */
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: var(--bg-primary); }
        ::-webkit-scrollbar-thumb { background: var(--border); border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: var(--text-secondary); }

        /* ─── Pagination ─── */
        nav .pagination {
            display: flex; flex-wrap: wrap; justify-content: center;
            gap: 0.35rem; list-style: none; padding: 0; margin: 1rem 0 0 0;
        }
        nav .pagination .page-item .page-link {
            border: 1px solid var(--border);
            background: var(--bg-secondary);
            color: var(--text-primary);
            border-radius: 6px;
            min-width: 32px; height: 32px;
            display: flex; align-items: center; justify-content: center;
            padding: 0 0.5rem;
            font-size: 0.8rem; font-weight: 500;
            text-decoration: none;
            transition: all 0.2s ease;
        }
        nav .pagination .page-item.active .page-link {
            background: var(--accent);
            border-color: var(--accent);
            color: #fff; font-weight: 700;
        }
        nav .pagination .page-item:not(.active):not(.disabled) .page-link:hover {
            background: var(--accent-glow);
            border-color: var(--accent);
            color: var(--accent);
        }
        nav .pagination .page-item.disabled .page-link {
            opacity: 0.5; cursor: not-allowed;
        }

        /* ─── Responsive ─── */
        @media (max-width: 1024px) {
            body { flex-direction: column; }
            .sidebar { width: 100%; position: static; height: auto; border-right: none; border-bottom: 1px solid var(--border); display: block; }
            .sidebar-logo { justify-content: space-between; }
            .sidebar > div[style*="flex:1"] { display: none; }
            .main-content { margin-left: 0; }
            .topbar { flex-direction: column; align-items: flex-start; gap: 0.75rem; }
            .topbar-right { margin-left: 0; width: 100%; justify-content: space-between; }
            .stats-grid { grid-template-columns: 1fr 1fr; }
        }

        @media (max-width: 640px) {
            .stats-grid { grid-template-columns: 1fr; }
            .filter-bar { flex-direction: column; align-items: stretch; }
            .filter-bar > div, .filter-bar input, .filter-bar select { width: 100%; }
        }
    </style>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('styles')
</head>
<body class="app-shell" x-data="siemApp()" x-init="init()" @keydown.escape.window="closeMobileSidebar()">

    <aside id="primary-sidebar" class="sidebar" :class="{ 'is-mobile-open': sidebarOpen }">
        <div class="sidebar-logo">
            <a href="{{ route('dashboard.index') }}" class="sidebar-brand-link" aria-label="SIEM Salabim — Dashboard">
                <img class="sidebar-brand-horizontal" src="{{ asset('images/brand/siem-salabim-horizontal.png') }}" width="2172" height="724" alt="SIEM Salabim">
                <img class="sidebar-brand-mark-image" src="{{ asset('images/brand/siem-salabim-mark.png') }}" width="1254" height="1254" alt="">
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
            <a href="{{ route('history.notifications') }}" class="nav-item {{ request()->routeIs('history.notifications') ? 'active' : '' }}" title="Log Notifikasi">
                <span class="nav-icon" aria-hidden="true"><i data-lucide="mail"></i></span><span class="nav-label">Log Notifikasi</span>
            </a>
            <a href="{{ route('history.activity') }}" class="nav-item {{ request()->routeIs('history.activity') ? 'active' : '' }}" title="Log Aktivitas">
                <span class="nav-icon" aria-hidden="true"><i data-lucide="history"></i></span><span class="nav-label">Log Aktivitas</span>
            </a>

            @if(auth()->user()->isAdmin())
            <div class="nav-section">Admin</div>
            <a href="{{ route('settings.index') }}" class="nav-item {{ request()->routeIs('settings.*') ? 'active' : '' }}" title="Settings">
                <span class="nav-icon" aria-hidden="true"><i data-lucide="settings"></i></span><span class="nav-label">Settings</span>
            </a>
            @endif
        </nav>

        <div class="sidebar-footer">
            <div class="user-card">
                <div class="user-avatar" title="{{ auth()->user()->name }}">{{ substr(auth()->user()->name, 0, 1) }}</div>
                <div class="user-meta">
                    <div class="user-name">{{ auth()->user()->name }}</div>
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
            <a href="{{ route('dashboard.index') }}" class="topbar-brand" aria-label="SIEM Salabim — Dashboard">
                <img src="{{ asset('images/brand/siem-salabim-horizontal.png') }}" width="2172" height="724" alt="SIEM Salabim">
            </a>
            <div class="topbar-context">
                <div class="topbar-breadcrumb">
                    <span>Security Operations</span>
                    <i data-lucide="chevron-right" aria-hidden="true"></i>
                    <span>@yield('page-context', 'Monitoring')</span>
                </div>
                <span class="topbar-title">@yield('page-title', 'Dashboard')</span>
            </div>
            <div class="topbar-right">
                <div class="live-badge" aria-label="Sistem aktif">
                    <div class="live-dot"></div>
                    <span class="live-label">LIVE</span>
                </div>
                <div class="time-display" x-text="currentTime"></div>
                <!-- Dark / Light Mode Toggle -->
                <button id="theme-toggle-btn" class="theme-toggle" type="button" data-theme-toggle title="Ubah tema" aria-label="Ubah tema gelap atau terang">
                    <span class="theme-icon-dark" aria-hidden="true"><i data-lucide="moon"></i></span>
                    <span class="theme-icon-light" aria-hidden="true"><i data-lucide="sun"></i></span>
                </button>
            </div>
        </header>

        <main class="page-content" data-ui-page>
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
                <div class="alert-msg success"><i data-lucide="circle-check" aria-hidden="true"></i><span>{{ session('success') }}</span></div>
            @endif
            @if(session('error'))
                <div class="alert-msg error"><i data-lucide="circle-alert" aria-hidden="true"></i><span>{{ session('error') }}</span></div>
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
            init() {
                window.addEventListener('resize', () => {
                    if (!window.matchMedia('(max-width: 960px)').matches) {
                        this.closeMobileSidebar();
                    }
                });
                this.updateTime();
                setInterval(() => this.updateTime(), 1000);
                setInterval(() => this.pollNewCount(), 30000);
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
            },
            closeMobileSidebar() {
                this.sidebarOpen = false;
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
