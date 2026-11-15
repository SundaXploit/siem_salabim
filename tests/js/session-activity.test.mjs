import { test } from 'node:test';
import assert from 'node:assert/strict';
import { initializeSessionActivity } from '../../resources/js/session-activity.js';

function client() {
    let now = 1_000_000;
    let nextId = 0;
    const timers = new Map();
    const listeners = new Map();
    const storage = new Map();
    const calls = [];
    const redirects = [];
    const metas = {
        'session-activity-url': '/session/activity', 'session-logout-url': '/logout',
        'session-login-url': '/login', 'session-activity-at': String(now), 'session-channel': 'test-session',
        'csrf-token': 'test-csrf',
    };
    const listen = (name, fn) => listeners.set(name, fn);
    const schedule = (fn, delay, interval = null) => {
        timers.set(++nextId, { fn, at: now + delay, interval });
        return nextId;
    };
    const document = {
        querySelector: selector => ({ content: metas[selector.match(/name="(.*?)"/)[1]] }),
        addEventListener: listen,
    };
    const browser = {
        Date: { now: () => now },
        localStorage: { getItem: key => storage.get(key), setItem: (key, value) => storage.set(key, value) },
        location: { replace: url => redirects.push(url) },
        fetch: async (url, options) => { calls.push({ url, options }); return { status: 200 }; },
        addEventListener: listen,
        setTimeout: (fn, delay) => schedule(fn, delay), clearTimeout: id => timers.delete(id),
        setInterval: (fn, delay) => schedule(fn, delay, delay),
    };
    const advance = async amount => {
        const end = now + amount;
        while (true) {
            const due = [...timers].filter(([, timer]) => timer.at <= end).sort((a, b) => a[1].at - b[1].at)[0];
            if (!due) break;
            const [id, timer] = due;
            now = timer.at;
            if (timer.interval) timer.at += timer.interval; else timers.delete(id);
            await timer.fn();
        }
        now = end;
        await Promise.resolve();
    };
    return { document, browser, calls, redirects, metas, advance,
        fire: (name, values = {}) => listeners.get(name)?.(values),
        start: () => initializeSessionActivity(document, browser),
    };
}

test('background events and synthetic mouse movement cannot renew idle activity', async () => {
    const c = client(); c.start();
    c.fire('pointermove', { isTrusted: false });
    c.fire('visibilitychange');
    await c.advance(15 * 60 * 1000 + 1000);
    assert.deepEqual(c.calls.map(call => call.url), ['/logout']);
    assert.deepEqual(c.redirects, ['/login']);
});

test('trusted pointer and keyboard input renew activity with bounded heartbeat frequency', async () => {
    const c = client(); c.start();
    for (let i = 0; i < 60; i++) {
        c.fire(i % 2 ? 'keydown' : 'pointermove', { isTrusted: true });
        await c.advance(1000);
    }
    assert.equal(c.calls.length, 3);
    assert.ok(c.calls.every(call => call.url === '/session/activity'));
    assert.ok(c.calls.every(call => JSON.parse(call.options.body).idle_ms <= 30000));
    assert.equal(c.redirects.length, 0);
});

test('an activity in another tab prevents idle logout in this tab', async () => {
    const c = client(); c.start();
    await c.advance(14 * 60 * 1000);
    c.fire('storage', { key: 'siem-activity:test-session', newValue: String(c.browser.Date.now()) });
    await c.advance(2 * 60 * 1000);
    assert.deepEqual(c.calls, []);
    assert.deepEqual(c.redirects, []);
});

test('first input after a suspended tab exceeds the deadline cannot revive the session', async () => {
    const c = client(); c.start();
    c.browser.Date.now = () => 1_000_000 + 16 * 60 * 1000;
    c.fire('pointerdown', { isTrusted: true });
    await Promise.resolve();
    await Promise.resolve();
    assert.deepEqual(c.calls.map(call => call.url), ['/logout']);
    assert.deepEqual(c.redirects, ['/login']);
});

test('a revoked backend session redirects to login on the next heartbeat', async () => {
    const c = client();
    c.browser.fetch = async (url, options) => { c.calls.push({ url, options }); return { status: 401 }; };
    c.start(); c.fire('pointerdown', { isTrusted: true });
    await c.advance(1);
    assert.deepEqual(c.calls.map(call => call.url), ['/session/activity', '/logout']);
    assert.deepEqual(c.redirects, ['/login']);
});

test('login pages do not start activity timers', async () => {
    const c = client(); delete c.metas['session-activity-url']; c.start();
    await c.advance(16 * 60 * 1000);
    assert.deepEqual(c.calls, []);
});

test('browser clock skew does not expire a newly authenticated session', async () => {
    const c = client();
    c.metas['session-server-now'] = '1000000';
    c.browser.Date.now = () => 4_600_000;
    c.start();
    await c.advance(1000);
    assert.deepEqual(c.redirects, []);
});

test('hiding a tab flushes pending real activity without creating a new interaction', async () => {
    const c = client(); c.start();
    c.fire('pointerdown', { isTrusted: true });
    await c.advance(1000);
    c.fire('keydown', { isTrusted: true });
    await c.advance(1000);
    c.fire('visibilitychange');
    await Promise.resolve();
    assert.equal(c.calls.length, 2);
    assert.equal(JSON.parse(c.calls[1].options.body).idle_ms, 1000);
    assert.equal(c.calls[1].options.keepalive, true);
});
