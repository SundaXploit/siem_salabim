import fs from 'node:fs';
import assert from 'node:assert/strict';

const output = 'docs/redesign/automatic-notifications';
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
async function check(name, expression) {
    const passed = await evaluate(expression);
    checks.push({ name, passed });
    assert.equal(passed, true, name);
}
async function navigate(path) {
    await send('Page.navigate', { url: 'http://127.0.0.1:8787' + path });
    await pause(650);
}
await send('Page.enable');
await send('Runtime.enable');
for (const width of [1440, 390, 360]) {
    await send('Emulation.setDeviceMetricsOverride', { width, height: 1000, deviceScaleFactor: 1, mobile: false });
    await navigate('/settings?section=telegram');
    await check(`automatic settings are inside Telegram and disabled by default at ${width}px`, `!!document.querySelector('[data-automatic-notifications]') && document.querySelectorAll('[data-settings-section]').length===6 && !document.querySelector('#telegram-auto-enabled').checked`);
    await check(`the strict threshold is clear at ${width}px`, `document.querySelector('#telegram-auto-level').value==='13' && document.querySelector('#telegram-auto-level').selectedOptions[0].textContent.includes('14–15') && document.querySelector('[data-automatic-notifications]').innerText.includes('>13')`);
    await check(`daily quota defaults to two and rejects zero at ${width}px`, `(()=>{const input=document.querySelector('#telegram-auto-daily-limit');const valid=input.value==='2' && input.min==='1' && input.max==='100' && document.querySelector('[data-automatic-notifications]').innerText.includes('00.00 WIB');input.value='0';const rejectsZero=!input.checkValidity();input.value='2';return valid && rejectsZero})()`);
    await check(`the detail URL follows the current domain at ${width}px`, `document.querySelector('.settings-auto-preview code').textContent===location.origin+'/alerts/1564'`);
    await check(`automatic form reuses routing without recipient or token fields at ${width}px`, `(()=>{const form=document.querySelector('[data-automatic-notification-form]');return !form.querySelector('[name="chat_id"], [name="telegram_bot_token"], [name="telegram_auto_base_url"]') && !!document.querySelector('[data-telegram-add-destination]')})()`);
    await evaluate(`(()=>{const form=document.querySelector('[data-automatic-notification-form]');form.addEventListener('submit',event=>{event.preventDefault();window.__qaAuto=Object.fromEntries(new FormData(form))},{once:true});document.querySelector('#telegram-auto-enabled').click();document.querySelector('#telegram-auto-level').value='14';document.querySelector('#telegram-auto-daily-limit').value='3';form.requestSubmit()})()`);
    await check(`switch threshold and daily quota submit through the dedicated form at ${width}px`, `window.__qaAuto.telegram_auto_enabled==='1' && window.__qaAuto.telegram_auto_above_level==='14' && window.__qaAuto.telegram_auto_daily_limit==='3' && window.__qaAuto._section==='telegram' && document.querySelector('[data-automatic-notification-form]').action.endsWith('/settings/telegram/automatic-notifications')`);
    await check(`automatic settings fit ${width}px`, `document.documentElement.scrollWidth<=innerWidth && document.querySelector('[data-automatic-notifications]').getBoundingClientRect().right<=innerWidth`);
    await evaluate(`document.querySelector('[data-automatic-notifications]').scrollIntoView({block:'start',behavior:'instant'});window.scrollBy(0,-70)`);
    fs.writeFileSync(`${output}/settings-${width}.png`, Buffer.from((await send('Page.captureScreenshot', { format: 'png' })).data, 'base64'));

    await navigate('/analyst-log?automatic=1&search=otomatis');
    await check(`automatic history distinguishes system delivery states at ${width}px`, `(()=>{const table=document.querySelector('[data-analyst-log]');return table.innerText.includes('Sistem (otomatis)') && ['Menunggu pengiriman','SENT','Belum dikonfirmasi','FAILED (403)','Batas harian tercapai'].every(text=>table.innerText.includes(text)) && document.querySelectorAll('[data-log-action]').length===5})()`);
    await navigate('/alerts/1?automatic=1');
    await check(`single detail preserves raw metadata and shows system notifications at ${width}px`, `!!document.querySelector('[x-ref="rawEvidence"]') && document.body.innerText.includes('Sistem (otomatis)') && document.body.innerText.includes('Belum dikonfirmasi')`);
}
checks.push({ name: 'No browser exceptions', passed: errors.length === 0 });
fs.writeFileSync(`${output}/checks.json`, JSON.stringify({ checks, errors }, null, 2));
console.log(JSON.stringify({ passed: checks.filter(check => check.passed).length, total: checks.length, errors }));
await send('Page.close');
socket.close();
assert.equal(errors.length, 0);
