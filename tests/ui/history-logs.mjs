import fs from 'node:fs';
import assert from 'node:assert/strict';

const output = 'docs/redesign/history-logs';
fs.mkdirSync(output, { recursive: true });
const target = await (await fetch('http://127.0.0.1:9227/json/new?about:blank', { method: 'PUT' })).json();
const socket = new WebSocket(target.webSocketDebuggerUrl);
await new Promise(resolve => socket.addEventListener('open', resolve, { once: true }));
let id = 0;
const pending = new Map(), errors = [], checks = [];
socket.addEventListener('message', ({ data }) => {
    const message = JSON.parse(data);
    if (message.method === 'Runtime.exceptionThrown') errors.push(message.params.exceptionDetails.exception?.description || message.params.exceptionDetails.text);
    if (!message.id) return;
    const request = pending.get(message.id);
    pending.delete(message.id);
    message.error ? request.reject(Error(message.error.message)) : request.resolve(message.result);
});
const send = (method, params = {}) => new Promise((resolve, reject) => {
    pending.set(++id, { resolve, reject });
    socket.send(JSON.stringify({ id, method, params }));
});
const pause = ms => new Promise(resolve => setTimeout(resolve, ms));
async function evaluate(expression) {
    const result = await send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
    if (result.exceptionDetails) throw Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text);
    return result.result.value;
}
async function navigate(path) {
    await send('Page.navigate', { url: 'http://127.0.0.1:8787' + path });
    await pause(600);
}
async function check(name, expression) {
    const passed = await evaluate(expression);
    checks.push({ name, passed });
    assert.equal(passed, true, name);
}
await send('Page.enable');
await send('Runtime.enable');
for (const width of [1440, 390, 360]) {
    await send('Emulation.setDeviceMetricsOverride', { width, height: 1000, deviceScaleFactor: 1, mobile: false });
    for (const [name, path] of [['analyst-log', '/analyst-log'], ['activity', '/history/activity']]) {
        await navigate(path);
        await evaluate(`window.applyTheme('dark')`);
        await check(`${name} fits ${width}px`, `document.documentElement.scrollWidth<=innerWidth && document.querySelector('.page-heading')!==null`);
        if (name === 'analyst-log') {
            await check(`combined analyst history renders notifications ACK and ignore at ${width}px`, `['notify','acknowledge','ignore'].every(action=>!!document.querySelector('[data-log-action="'+action+'"]')) && document.querySelector('[data-analyst-log]').innerText.includes('SENT')`);
            await check(`analyst menu is active at ${width}px`, `document.querySelector('.sidebar-nav a[href$="/analyst-log"]').classList.contains('active') && !document.querySelector('.sidebar-nav').innerText.includes('Log Notifikasi')`);
        } else {
            await check(`admin activity includes account configuration and failed actions at ${width}px`, `['login','logout','opensearch_updated','password_changed','user_created','user_deleted'].every(action=>!!document.querySelector('[data-activity-action="'+action+'"]')) && document.querySelector('[data-activity-log]').innerText.includes('GAGAL') && !document.querySelector('[data-log-action]')`);
            await check(`activity menu is in the admin section at ${width}px`, `(()=>{const nav=document.querySelector('.sidebar-nav');return nav.innerText.indexOf('Admin')<nav.innerText.indexOf('Log aktivitas') && nav.querySelector('a[href$="/history/activity"]').classList.contains('active')})()`);
        }
        await evaluate('window.scrollTo(0,0)');
        const screenshot = await send('Page.captureScreenshot', { format: 'png' });
        fs.writeFileSync(`${output}/${name}-${width}.png`, Buffer.from(screenshot.data, 'base64'));
    }
}
await navigate('/analyst-log?action=ignore');
await check('analyst action filter includes only ignore entries', `document.querySelectorAll('[data-log-action]').length>0 && [...document.querySelectorAll('[data-log-action]')].every(row=>row.dataset.logAction==='ignore')`);
await navigate('/analyst-log?action=notify&search=-100000000');
await check('notification filter keeps destination details and delivery failures', `document.querySelectorAll('[data-log-action]').length>0 && [...document.querySelectorAll('[data-log-action]')].every(row=>row.dataset.logAction==='notify') && document.querySelector('[data-analyst-log]').innerText.includes('FAILED (500)')`);
await navigate('/history/activity?action=password_changed');
await check('admin activity filter selects password changes', `document.querySelectorAll('[data-activity-action]').length===1 && document.querySelector('[data-activity-action]').dataset.activityAction==='password_changed'`);
await navigate('/history/notifications?search=QA');
await check('old notification URL redirects to analyst history preserving search', `location.pathname==='/analyst-log' && new URLSearchParams(location.search).get('search')==='QA'`);
await navigate('/analyst-log?_role=analyst');
await check('analysts can view analyst logs and have no activity menu', `!!document.querySelector('[data-analyst-log]') && !document.querySelector('.sidebar-nav a[href$="/history/activity"]') && !document.querySelector('.sidebar-nav a[href$="/settings"]')`);
await navigate('/history/activity?_role=analyst');
await check('direct admin activity access is denied to analysts', `!document.querySelector('[data-activity-log]') && document.body.innerText.includes('403')`);
checks.push({ name: 'No browser exceptions', passed: errors.length === 0 });
fs.writeFileSync(`${output}/checks.json`, JSON.stringify({ checks, errors }, null, 2));
console.log(JSON.stringify({ passed: checks.filter(check => check.passed).length, total: checks.length, errors }));
await send('Page.close');
socket.close();
assert.equal(errors.length, 0);
