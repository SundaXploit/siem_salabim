// Uses the isolated tests/ui/router.php server on :8787 and Chrome CDP on :9227.
import fs from 'node:fs';
import assert from 'node:assert/strict';

const directory = 'docs/redesign/responsive-layout';
fs.mkdirSync(directory, {recursive: true});
const target = await (await fetch('http://127.0.0.1:9227/json/new?about:blank', {method: 'PUT'})).json();
const socket = new WebSocket(target.webSocketDebuggerUrl);
await new Promise(resolve => socket.addEventListener('open', resolve, {once: true}));
let id = 0;
const pending = new Map(), exceptions = [];
socket.addEventListener('message', ({data}) => {
    const message = JSON.parse(data);
    if (message.method === 'Runtime.exceptionThrown') exceptions.push(message.params.exceptionDetails.text);
    if (!message.id) return;
    const action = pending.get(message.id);
    pending.delete(message.id);
    message.error ? action.reject(Error(message.error.message)) : action.resolve(message.result);
});
const send = (method, params = {}) => new Promise((resolve, reject) => {
    pending.set(++id, {resolve, reject});
    socket.send(JSON.stringify({id, method, params}));
});
async function evaluate(expression) {
    const result = await send('Runtime.evaluate', {expression, awaitPromise: true, returnByValue: true});
    if (result.exceptionDetails) throw Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text);
    return result.result.value;
}
const pause = ms => new Promise(resolve => setTimeout(resolve, ms));
const results = [];
await send('Page.enable');
await send('Runtime.enable');
try {
    for (const width of [1920, 1536, 1366, 1280, 1024, 320]) {
        const dpr = width === 1536 ? 1.25 : 1;
        await send('Emulation.setDeviceMetricsOverride', {width, height: 776, deviceScaleFactor: dpr, mobile: false});
        for (const theme of ['dark', 'light']) {
            for (const path of ['/dashboard', '/live-alerts']) {
                exceptions.length = 0;
                await send('Page.navigate', {url: 'http://127.0.0.1:8787' + path});
                let ready = false;
                for (let attempt = 0; attempt < 100; attempt++) {
                    try {
                        ready = await evaluate(`location.pathname === '${path}' && document.readyState === 'complete' && !!window.Alpine && !!document.querySelector('[data-ui-page]')`);
                    } catch (_) { /* Navigation can replace the execution context. */ }
                    if (ready) break;
                    await pause(100);
                }
                assert.ok(ready, 'Preview did not finish loading: ' + path);
                await evaluate(`document.fonts.ready.then(() => { localStorage.setItem('siem-theme', '${theme}'); window.applyTheme('${theme}', {persist: false}); })`);
                await pause(200);
                const state = await evaluate(`(() => {
                    const root = document.documentElement;
                    const bounds = element => { const r = element.getBoundingClientRect(); return {left: r.left, right: r.right, top: r.top, bottom: r.bottom, width: r.width, height: r.height}; };
                    const grid = document.querySelector('#filterForm .live-alert-filter-grid');
                    const fields = grid ? [...grid.querySelectorAll('input,select,button,a')].map(element => ({name: element.name || element.textContent.trim(), fontSize: parseFloat(getComputedStyle(element).fontSize), ...bounds(element)})) : [];
                    const visibleRows = [...document.querySelectorAll('.data-table tbody tr')].filter(row => row.querySelector('td') && bounds(row).bottom <= innerHeight).length;
                    return {path: location.pathname, width: innerWidth, dpr: devicePixelRatio, theme: root.dataset.theme, zoom: getComputedStyle(root).zoom, viewportScale: visualViewport.scale, rootSize: parseFloat(getComputedStyle(root).fontSize), bodySize: parseFloat(getComputedStyle(document.body).fontSize), overflow: root.scrollWidth > innerWidth + 1, sidebar: bounds(document.querySelector('.sidebar')), grid: grid && bounds(grid), fields, visibleRows};
                })()`);
                results.push({...state, exceptions: [...exceptions]});
                fs.writeFileSync(`${directory}/results.json`, JSON.stringify(results, null, 2));
                const label = `${path}-${width}-${theme}`;
                assert.equal(state.path, path, label);
                assert.equal(state.overflow, false, 'Page overflow: ' + label);
                assert.equal(state.viewportScale, 1, 'Browser zoom changed: ' + label);
                assert.equal(state.zoom, '1', 'CSS zoom changed: ' + label);
                assert.equal(state.rootSize, 16, 'Root font changed: ' + label);
                assert.equal(state.bodySize, 16, 'Workspace text is too large: ' + label);
                assert.deepEqual(exceptions, [], 'Browser exception: ' + label);
                if (width > 960) assert.equal(state.sidebar.width, 208, label);
                if (state.grid) {
                    for (const field of state.fields) {
                        assert.ok(field.left >= state.grid.left - 1 && field.right <= state.grid.right + 1, 'Filter escapes container: ' + label + ':' + field.name);
                        if (width <= 640) {
                            assert.ok(field.height >= 44, 'Mobile target too small: ' + label + ':' + field.name);
                            if (!['Filter', 'Reset'].includes(field.name)) assert.ok(field.fontSize >= 16, 'Mobile input text too small: ' + label);
                        }
                    }
                    if (width >= 1536) {
                        const positions = state.fields.map(field => field.top);
                        assert.ok(Math.max(...positions) - Math.min(...positions) < 1, 'Wide filters wrapped: ' + label);
                        assert.ok(state.visibleRows >= 3, 'Too few alert rows visible: ' + label);
                    }
                }
                if ([1536, 320].includes(width)) {
                    const screenshot = await send('Page.captureScreenshot', {format: 'png'});
                    fs.writeFileSync(`${directory}/${path.slice(1)}-${theme}-${width}.png`, Buffer.from(screenshot.data, 'base64'));
                }
            }
        }
        console.log(JSON.stringify({width, cases: results.length}));
    }
    console.log(JSON.stringify({cases: results.length, failures: 0}));
} finally {
    await send('Page.close');
    socket.close();
}
