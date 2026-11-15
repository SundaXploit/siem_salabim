export function initializeSessionActivity(document = window.document, browser = window) {
    const meta = name => document.querySelector(`meta[name="${name}"]`)?.content;
    const activityUrl = meta('session-activity-url');
    if (!activityUrl) return;

    const idleLimit = 15 * 60 * 1000;
    const serverNow = Number(meta('session-server-now'));
    const clockOffset = serverNow ? serverNow - browser.Date.now() : 0;
    const clock = () => browser.Date.now() + clockOffset;
    const storageKey = `siem-activity:${meta('session-channel')}`;
    let lastActivity = Number(meta('session-activity-at'));
    let lastReport = 0;
    let pending = null;
    let reporting = false;
    let expired = false;
    let lastSharedWrite = 0;
    const csrf = () => meta('csrf-token');
    const storedActivity = () => {
        try { return Number(browser.localStorage.getItem(storageKey)) || 0; } catch { return 0; }
    };
    lastActivity = Math.max(lastActivity, storedActivity());

    const expire = async () => {
        if (expired) return;
        expired = true;
        browser.clearTimeout(pending);
        const redirect = browser.setTimeout(() => browser.location.replace(meta('session-login-url')), 3000);
        try {
            await browser.fetch(meta('session-logout-url'), {
                method: 'POST', credentials: 'same-origin', keepalive: true,
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf() },
            });
        } catch {
            // The login request also enforces expiry if the logout response is lost.
        } finally {
            browser.clearTimeout(redirect);
            browser.location.replace(meta('session-login-url'));
        }
    };
    const report = async () => {
        pending = null;
        if (expired || reporting) return;
        const idle = clock() - lastActivity;
        if (idle > idleLimit) { await expire(); return; }
        if (idle > 30000) return;
        reporting = true;
        lastReport = clock();
        try {
            const response = await browser.fetch(activityUrl, {
                method: 'POST', credentials: 'same-origin', cache: 'no-store', keepalive: true,
                headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
                body: JSON.stringify({ idle_ms: Math.max(0, Math.floor(idle)) }),
            });
            if ([401, 419].includes(response.status)) await expire();
        } catch {
            // An offline client still expires locally; polling never extends its session.
        } finally { reporting = false; }
    };
    const onActivity = event => {
        if (!event.isTrusted || expired) return;
        const now = clock();
        lastActivity = Math.max(lastActivity, storedActivity());
        if (now - lastActivity > idleLimit) { void expire(); return; }
        lastActivity = now;
        if (now - lastSharedWrite >= 1000) {
            try { browser.localStorage.setItem(storageKey, String(now)); } catch { /* Storage is optional. */ }
            lastSharedWrite = now;
        }
        if (pending === null) pending = browser.setTimeout(report, Math.max(0, 30000 - (now - lastReport)));
    };
    for (const name of ['pointermove', 'pointerdown', 'keydown', 'touchstart', 'wheel']) {
        document.addEventListener(name, onActivity, { passive: true });
    }
    browser.addEventListener('storage', event => {
        if (event.key === storageKey) lastActivity = Math.max(lastActivity, Number(event.newValue) || 0);
    });
    const check = () => {
        lastActivity = Math.max(lastActivity, storedActivity());
        if (clock() - lastActivity > idleLimit) void expire();
    };
    const flush = () => {
        check();
        if (pending !== null && !expired) {
            browser.clearTimeout(pending);
            pending = null;
            void report();
        }
    };
    document.addEventListener('visibilitychange', flush);
    browser.addEventListener('pagehide', flush);
    browser.addEventListener('pageshow', check);
    browser.setInterval(check, 1000);
    check();
}
