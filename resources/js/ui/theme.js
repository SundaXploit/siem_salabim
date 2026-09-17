const THEME_STORAGE_KEY = 'siem-theme';

export function preferredTheme() {
    try {
        const stored = window.localStorage.getItem(THEME_STORAGE_KEY);
        if (stored === 'dark' || stored === 'light') return stored;
    } catch (_) {
        // Storage access can be disabled by the browser. Dark remains the product default.
    }

    return 'dark';
}

export function applyTheme(theme, { persist = true } = {}) {
    const resolved = theme === 'light' ? 'light' : 'dark';
    const root = document.documentElement;

    root.dataset.theme = resolved;
    root.classList.toggle('dark', resolved === 'dark');
    root.classList.toggle('light', resolved === 'light');

    if (persist) {
        try { window.localStorage.setItem(THEME_STORAGE_KEY, resolved); } catch (_) {}
    }

    document.dispatchEvent(new CustomEvent('siem:themechange', { detail: { theme: resolved } }));
    return resolved;
}

export function toggleTheme() {
    return applyTheme(document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark');
}

export function initializeTheme() {
    applyTheme(preferredTheme(), { persist: false });

    document.addEventListener('click', (event) => {
        const control = event.target.closest('[data-theme-toggle]');
        if (!control) return;
        event.preventDefault();
        toggleTheme();
    });
}
