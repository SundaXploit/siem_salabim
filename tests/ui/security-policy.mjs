import assert from 'node:assert/strict';
import fs from 'node:fs';

const output = 'storage/framework/testing/security-ui';
const debuggerUrl = process.env.SIEM_QA_DEBUG_URL || 'http://127.0.0.1:9227';
fs.mkdirSync(output, { recursive: true });
const target = await (await fetch(debuggerUrl + '/json/new?about:blank', { method: 'PUT', signal: AbortSignal.timeout(3000) })).json();
const socket = new WebSocket(target.webSocketDebuggerUrl);
await new Promise(resolve => socket.addEventListener('open', resolve, { once: true }));
const pending = new Map(), errors = [], checks = [];
let sequence = 0;
socket.addEventListener('message', ({ data }) => {
    const message = JSON.parse(data);
    if (message.method === 'Runtime.exceptionThrown') errors.push(message.params.exceptionDetails.exception?.description || message.params.exceptionDetails.text);
    if (!message.id) return;
    const callback = pending.get(message.id);
    pending.delete(message.id);
    message.error ? callback.reject(Error(message.error.message)) : callback.resolve(message.result);
});
const send = (method, params = {}) => new Promise((resolve, reject) => {
    const timeout = setTimeout(() => reject(Error('CDP timeout: ' + method)), 10000);
    pending.set(++sequence, { resolve: result => {clearTimeout(timeout);resolve(result);}, reject: error => {clearTimeout(timeout);reject(error);} });
    socket.send(JSON.stringify({ id: sequence, method, params }));
});
const pause = ms => new Promise(resolve => setTimeout(resolve, ms));
const evaluate = async expression => {
    const result = await send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
    if (result.exceptionDetails) throw Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text);
    return result.result.value;
};
const waitFor = async expression => {
    const end = Date.now() + 8000;
    while (Date.now() < end) {
        if (await evaluate(expression)) return;
        await pause(80);
    }
    throw Error(`Timed out: ${expression}`);
};
const navigate = async path => {
    await send('Page.navigate', { url: 'http://127.0.0.1:8787' + path });
    await waitFor('document.readyState === "complete" && !!window.Alpine && !!document.querySelector("meta[name=session-activity-url]")');
};
const click = async selector => {
    await evaluate(`document.querySelector(${JSON.stringify(selector)}).click()`);
    await pause(120);
};
const check = async (name, expression) => {
    const actual = await evaluate(expression);
    checks.push({ name, passed: actual === true });
    assert.equal(actual, true, name);
};

try {
    await send('Page.enable'); await send('Runtime.enable');
    await send('Page.bringToFront');
    await send('Page.addScriptToEvaluateOnNewDocument', { source: `Date.now = () => new Date('2026-11-11T10:24:00+07:00').getTime();` });
    await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false });
    await navigate('/settings?section=users');
    await waitFor('document.querySelectorAll("[data-access-agent]").length === 4');
    await check('active and inactive accounts are visible', `document.querySelector('.settings-user-table').innerText.includes('Nonaktif')`);
    await click('[data-user-menu="2"]');
    await check('active analyst has a deactivate action with PATCH status', `(()=>{const b=document.querySelector('[data-user-status]');return b.innerText.includes('Nonaktifkan')&&b.closest('form').querySelector('[name=_method]').value==='PATCH'&&b.closest('form').querySelector('[name=is_active]').value==='0';})()`);
    await click('[data-user-edit]');
    await check('analyst can be edited', `document.querySelector('.settings-user-dialog').open && document.querySelector('#user-editor-name').value==='Raka Pratama'`);
    await evaluate(`document.querySelector('.settings-user-dialog').close()`);
    await click('[data-user-menu="3"]');
    await check('other administrator profile has no edit action', `getComputedStyle(document.querySelector('[data-user-edit]')).display==='none'`);
    await click('[data-user-menu="4"]');
    await check('inactive account offers reactivation', `document.querySelector('[data-user-status]').innerText.includes('Aktifkan')&&document.querySelector('[data-user-status]').closest('form').querySelector('[name=is_active]').value==='1'`);
    await evaluate(`document.querySelector('#user-actions-menu').hidePopover()`);
    const screenshot = await send('Page.captureScreenshot', { format: 'png' });
    fs.writeFileSync(`${output}/users-desktop.png`, Buffer.from(screenshot.data, 'base64'));

    await navigate('/settings?section=telegram');
    await waitFor(`Alpine.$data(document.querySelector('.settings-telegram-card')).availableAgents.length===4`);
    await click('[data-telegram-agent-toggle]');
    await check('agent selector submits identity keys and displays Wazuh IDs', `(()=>{const input=document.querySelector('[data-agent-name="web-prod-02"]');return /^[a-f0-9]{64}$/.test(input.value)&&input.closest('label').innerText.includes('001')&&document.querySelector('[name="telegram_destinations[0][agent_keys][]"]')!==null;})()`);

    await navigate('/settings?section=opensearch');
    await check('OpenSearch exposes explicit true and false SSL verification choices', `(()=>{const s=document.querySelector('[name=opensearch_verify_ssl]');return !!s&&s.querySelector('[value="1"]').textContent.includes('true')&&s.querySelector('[value="0"]').textContent.includes('false');})()`);
    await evaluate(`{const s=document.querySelector('[name=opensearch_verify_ssl]');s.value='0';s.dispatchEvent(new Event('change',{bubbles:true}));}`);
    await check('OpenSearch test/save form submits the currently selected SSL mode', `new FormData(document.querySelector('[x-ref=openSearchForm]')).get('opensearch_verify_ssl')==='0'`);
    await check('source timezone offers UTC and WIB with offset precedence explained', `(()=>{const s=document.querySelector('[name=opensearch_timezone]');return !!s.querySelector('[value="UTC"]')&&s.querySelector('[value="Asia/Jakarta"]').textContent.includes('WIB')&&s.closest('.form-group').innerText.includes('offset aslinya');})()`);

    await navigate('/settings?section=data');
    await check('SQL export names the active database engine', `document.querySelector('.settings-data-card option[value=sql]').textContent.includes('SQL INSERT (SQLite)')`);
    await evaluate(`{const s=Alpine.$data(document.querySelector('.settings-data-card'));s.periodMode='range';s.dateFrom='2026-11-01';s.dateTo='2026-11-11';s.previewNow();}`);
    await waitFor(`Alpine.$data(document.querySelector('.settings-data-card')).previewOperations===0`);
    await check('backup preview includes notification operation markers', `document.querySelector('.settings-data-card').innerText.includes('penanda pengiriman')&&Alpine.$data(document.querySelector('.settings-data-card')).previewCount>0`);

    await navigate('/settings?section=templates');
    await check('settings lists shared notification templates and their management actions', `document.querySelectorAll('.notification-template-item').length===7&&!!document.querySelector('[data-template-add]')&&document.querySelector('.settings-notification-templates').innerText.includes('riwayat')`);
    await check('template section has a rendered directory icon and plain text template names', `!!document.querySelector('[data-settings-section="templates"] summary svg.lucide-clipboard-list')&&Array.from(document.querySelectorAll('.notification-template-item strong')).every(label=>label.children.length===0)&&!document.querySelector('#template-editor-icon')`);
    await click('[data-template-add]');
    await check('admin can open an empty template editor', `document.querySelector('[x-ref=templateEditor]').open&&document.querySelector('#template-editor-name').value===''&&document.querySelector('#template-editor-body').maxLength===4096`);
    await evaluate(`{window.__qaFetch=window.fetch;window.confirm=()=>true;window.fetch=async(url,options={})=>{if(String(url).includes('/settings/notification-templates')){const item=options.body?JSON.parse(options.body):null;return {ok:true,status:200,json:async()=>({success:true,message:options.method==='DELETE'?'Template dihapus. Riwayat notifikasi tetap tersimpan.':'Template disimpan.',template:item?{...item,id:item.id||99}:undefined})};}return window.__qaFetch(url,options);};const manager=Alpine.$data(document.querySelector('.settings-notification-templates'));manager.editor.name='QA custom template';manager.editor.category='SOC';manager.editor.body='<b>Pesan manual SOC</b>';}`);
    await click('[x-ref=templateEditor] button[type=submit]');
    await waitFor(`!document.querySelector('[x-ref=templateEditor]').open&&Array.from(document.querySelectorAll('.notification-template-item')).some(item=>item.innerText.includes('QA custom template'))`);
    await check('created template appears in the management list', `document.querySelectorAll('.notification-template-item').length===8`);
    await evaluate(`Array.from(document.querySelectorAll('.notification-template-item')).find(item=>item.innerText.includes('QA custom template')).querySelector('[data-template-edit]').click()`);
    await check('edit opens the existing template body and active flag', `document.querySelector('#template-editor-body').value==='<b>Pesan manual SOC</b>'&&Alpine.$data(document.querySelector('.settings-notification-templates')).editor.is_active===true`);
    await evaluate(`{const manager=Alpine.$data(document.querySelector('.settings-notification-templates'));manager.editor.body='Pesan baru';manager.editor.is_active=false;}`);
    await click('[x-ref=templateEditor] button[type=submit]');
    await waitFor(`!document.querySelector('[x-ref=templateEditor]').open`);
    await check('deactivated template stays manageable with its status', `Array.from(document.querySelectorAll('.notification-template-item')).find(item=>item.innerText.includes('QA custom template')).innerText.includes('Nonaktif')`);
    await evaluate(`Array.from(document.querySelectorAll('.notification-template-item')).find(item=>item.innerText.includes('QA custom template')).querySelector('[data-template-delete]').click()`);
    await waitFor(`document.querySelectorAll('.notification-template-item').length===7`);
    await check('template deletion leaves a history preservation notice', `document.querySelector('.settings-notification-templates').innerText.includes('Riwayat notifikasi tetap tersimpan.')`);
    const templateSettings = await send('Page.captureScreenshot', { format: 'png' });
    fs.writeFileSync(`${output}/notification-templates-settings.png`, Buffer.from(templateSettings.data, 'base64'));

    await send('Network.enable');
    await send('Network.setBlockedURLs', { urls: ['*/api/alerts/new-count'] });
    await navigate('/live-alerts');
    await evaluate(`window.dispatchEvent(new CustomEvent('siem-ingestion',{detail:{tls_error:'Verifikasi sertifikat SSL/TLS gagal. Pilih Verifikasi SSL = false sementara.'}}))`);
    await waitFor(`(()=>{const e=document.querySelector('[x-data^="{ tlsError:"]');return e.textContent.includes('Verifikasi SSL = false')&&getComputedStyle(e).display!=='none';})()`);
    await check('Live Alerts displays safe TLS diagnostics received from ingestion polling', `(()=>{const e=document.querySelector('[x-data^="{ tlsError:"]');return getComputedStyle(e).display!=='none'&&e.textContent.includes('Verifikasi SSL = false');})()`);
    await evaluate(`window.dispatchEvent(new CustomEvent('siem-ingestion',{detail:{tls_error:null}}))`);
    await send('Network.setBlockedURLs', { urls: [] });
    await click('[aria-controls="bulk-triage-controls"]');
    await click('[data-triage-id="1"]');
    await click('[data-triage-id="3"]');
    await check('mixed NEW and owned ACK selection blocks ignore and notification', `document.querySelector('[data-bulk-ignore]').disabled&&document.querySelector('[data-bulk-notify]').disabled&&!document.querySelector('[data-bulk-ack]').disabled`);
    await evaluate(`Alpine.$data(document.querySelector('[x-data^="alertTable("]')).selectedIds=['3','9']`);
    await waitFor(`!document.querySelector('[data-bulk-notify]').disabled`);
    await check('owned ACK selection enables follow-up actions', `!document.querySelector('[data-bulk-ignore]').disabled&&document.querySelector('[data-bulk-ack]').disabled`);
    await click('[data-bulk-notify]');
    await waitFor(`!!document.querySelector('.notification-dialog[open]')&&document.querySelector('.notification-dialog[open] .notification-template')&&!document.querySelector('.notification-dialog[open] button[type=submit]').disabled`);
    await check('live bulk notification explains one combined message per destination', `document.querySelector('.notification-dialog[open]').innerText.includes('Satu pesan gabungan per tujuan Telegram')`);
    await evaluate(`Array.from(document.querySelectorAll('.notification-dialog[open] .notification-template')).find(button=>button.innerText.includes('Custom / Pesan Bebas')).click()`);
    await waitFor(`document.querySelector('.notification-dialog[open] textarea').value.includes('Judul notifikasi:')`);
    await check('mass composer shares template buttons and editable messages with the single form', `(()=>{const dialog=document.querySelector('.notification-dialog[open]');return dialog.querySelectorAll('.notification-template').length===7&&dialog.querySelector('textarea').maxLength===4096&&dialog.innerText.includes('Pesan (dapat diedit bebas)')&&!!dialog.querySelector('[name="evidence[]"]');})()`);
    await check('notification template buttons show names without decorative icons', `Array.from(document.querySelectorAll('.notification-dialog[open] .notification-template')).every(button=>button.children.length===1&&button.firstElementChild.getAttribute('x-text')==='tpl.name')`);
    await check('custom values stay empty and mass messages are separate for each destination', `(()=>{const dialog=document.querySelector('.notification-dialog[open]');const fields=Array.from(dialog.querySelectorAll('textarea'));return fields.length===3&&fields.every(field=>!field.value.includes('web-prod')&&!field.value.includes('{agent_name}')&&field.value.includes('<b>Prioritas:</b>'));})()`);
    await evaluate(`{const component=Alpine.$data(document.querySelector('.notification-dialog[open]'));component.messages[0].message='Manual hanya untuk tujuan pertama';}`);
    await check('editing one destination leaves other destination messages unchanged', `(()=>{const fields=document.querySelectorAll('.notification-dialog[open] textarea');return fields[0].value==='Manual hanya untuk tujuan pertama'&&fields[1].value.includes('Judul notifikasi:');})()`);
    await check('mass form keeps every destination key selected alert and operation UUID', `(()=>{const form=document.querySelector('.notification-dialog[open] form');const data=new FormData(form);return data.getAll('alert_ids[]').join(',')==='3,9'&&/^[a-f0-9-]{36}$/.test(data.get('operation_id'))&&/^[a-f0-9]{64}$/.test(data.get('messages[0][destination_key]'));})()`);
    await evaluate(`window.applyTheme('dark')`);
    const bulkComposer = await send('Page.captureScreenshot', { format: 'png' });
    fs.writeFileSync(`${output}/bulk-notification-composer.png`, Buffer.from(bulkComposer.data, 'base64'));

    await navigate('/live-alerts/triage/detail?alert_ids[]=1&alert_ids[]=3');
    await check('combined detail blocks follow-up on mixed ownership and offers the owned subset', `document.querySelector('[data-bulk-detail-ignore]').disabled&&document.querySelector('[data-bulk-detail-notify]').disabled&&!!document.querySelector('[data-bulk-own-selection]')`);
    await click('[data-bulk-own-selection]');
    await waitFor(`document.querySelectorAll('[data-bulk-event]').length===1`);
    await check('owned subset allows follow-up and identifies the ACK owner', `!document.querySelector('[data-bulk-detail-ignore]').disabled&&!document.querySelector('[data-bulk-detail-notify]').disabled&&document.body.innerText.includes('Pemilik ACK: Nadia Putri')`);
    await click('[data-bulk-detail-notify]');
    await waitFor(`!!document.querySelector('.notification-dialog[open] textarea')`);
    await check('combined detail uses the same full message and evidence composer', `(()=>{const dialog=document.querySelector('.notification-dialog[open]');return dialog.innerText.includes('Satu pesan gabungan per tujuan Telegram')&&dialog.querySelectorAll('.notification-template').length===7&&!!dialog.querySelector('[name="evidence[]"]');})()`);
    await check('combined notification keeps the selected IDs and operation UUID', `(()=>{const form=document.querySelector('.notification-dialog[open] form');return new FormData(form).getAll('alert_ids[]').join(',')==='3'&&/^[a-f0-9-]{36}$/.test(form.querySelector('[name=operation_id]').value)&&form.querySelector('textarea').required;})()`);

    await navigate('/alerts/9');
    await check('ACK owner has ignore and Telegram actions', `document.body.innerText.includes('Pemilik ACK: Nadia Putri')&&document.body.innerText.includes('Kirim notifikasi Telegram')&&document.body.innerText.includes('Abaikan alert')`);
    await click('button[aria-label="Kirim notifikasi"]');
    await waitFor(`Array.from(document.querySelectorAll('form')).some(f=>f.action.endsWith('/notify')&&getComputedStyle(f).display!=='none'&&!f.querySelector('button[type=submit]').disabled)`);
    await check('manual notification form includes a UUID operation and bounded message', `(()=>{const f=document.querySelector('form[action$="/notify"]');return /^[a-f0-9-]{36}$/.test(f.querySelector('[name=operation_id]').value)&&f.querySelector('[name=message]').maxLength===4096;})()`);
    await evaluate(`Array.from(document.querySelectorAll('.notification-dialog[open] .notification-template')).find(button=>button.innerText.includes('Custom / Pesan Bebas')).click()`);
    await check('single custom has the same empty manual fields as mass custom', `document.querySelector('.notification-dialog[open] textarea').value.includes('Judul notifikasi:')&&!document.querySelector('.notification-dialog[open] textarea').value.includes('{agent_name}')`);
    await check('only the chosen template is selected', `document.querySelectorAll('.notification-dialog[open] .notification-template.active-template').length===1&&document.querySelector('.notification-dialog[open] .notification-template.active-template').innerText.includes('Custom / Pesan Bebas')`);
    const singleComposer = await send('Page.captureScreenshot', { format: 'png' });
    fs.writeFileSync(`${output}/single-notification-composer.png`, Buffer.from(singleComposer.data, 'base64'));
    await evaluate(`{const f=document.querySelector('form[action$="/notify"]');window.__qaOperationId=f.querySelector('[name=operation_id]').value;window.__qaFirstSubmit=f.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));window.__qaSecondSubmit=f.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));}`);
    await waitFor(`document.querySelector('form[action$="/notify"] button[type=submit]').disabled`);
    await check('double submit is prevented and keeps the same operation ID', `window.__qaFirstSubmit===true&&window.__qaSecondSubmit===false&&document.querySelector('form[action$="/notify"] [name=operation_id]').value===window.__qaOperationId`);
    await navigate('/alerts/3?_role=other-admin');
    await check('another admin cannot ignore or notify the claimed alert', `!document.querySelector('button[aria-label="Kirim notifikasi"]')&&!Array.from(document.querySelectorAll('button')).some(b=>b.innerText.includes('Abaikan alert'))`);
    await navigate('/alerts/1');
    await check('new alert requires ACK before manual notification', `document.body.innerText.includes('Acknowledge alert')&&!document.querySelector('button[aria-label="Kirim notifikasi"]')`);

    for (const width of [1582, 1280, 1024, 768, 390]) {
        await send('Emulation.setDeviceMetricsOverride', { width, height: 1000, deviceScaleFactor: 1, mobile: width < 640 });
        await navigate('/history/activity');
        await check(`activity filter fields have space and fit the page at ${width}px`, `(()=>{const grid=document.querySelector('.activity-filter-grid');const fields=Array.from(grid.children).map(element=>element.getBoundingClientRect());const inputs=Array.from(grid.querySelectorAll('input,select'));return document.documentElement.scrollWidth<=innerWidth&&inputs.every(input=>input.getBoundingClientRect().width<=input.parentElement.getBoundingClientRect().width+1)&&fields.every((a,i)=>fields.every((b,j)=>i===j||a.right+11<=b.left||b.right+11<=a.left||a.bottom+11<=b.top||b.bottom+11<=a.top));})()`);
        if (width === 1582 || width === 390) {
            const activity = await send('Page.captureScreenshot', { format: 'png' });
            fs.writeFileSync(`${output}/activity-${width}.png`, Buffer.from(activity.data, 'base64'));
        }
    }
    await send('Emulation.setDeviceMetricsOverride', { width: 390, height: 900, deviceScaleFactor: 1, mobile: true });
    await navigate('/settings?section=users');
    await check('account table stays inside the mobile page', 'document.documentElement.scrollWidth<=innerWidth');
    const mobile = await send('Page.captureScreenshot', { format: 'png' });
    fs.writeFileSync(`${output}/users-mobile.png`, Buffer.from(mobile.data, 'base64'));
    await navigate('/alerts/9');
    await click('button[aria-label="Kirim notifikasi"]');
    await waitFor(`!!document.querySelector('.notification-dialog[open] textarea')`);
    await check('notification composer and message editor fit a mobile screen', `(()=>{const dialog=document.querySelector('.notification-dialog[open]');return dialog.getBoundingClientRect().width<=innerWidth&&dialog.scrollWidth<=dialog.clientWidth&&dialog.querySelector('textarea').getBoundingClientRect().width<=dialog.clientWidth;})()`);
    const mobileComposer = await send('Page.captureScreenshot', { format: 'png' });
    fs.writeFileSync(`${output}/notification-composer-mobile.png`, Buffer.from(mobileComposer.data, 'base64'));
    assert.deepEqual(errors, [], 'browser runtime exceptions');
    fs.writeFileSync(`${output}/checks.json`, JSON.stringify({ checks, errors }, null, 2));
    console.log(JSON.stringify({ passed: checks.length, errors, output }));
} catch (error) {
    const diagnostic = await evaluate(`({url:location.pathname,dialog:document.querySelector('.notification-dialog[open]')?.innerText,body:document.querySelector('main')?.innerText.slice(0,700)})`).catch(() => null);
    console.error(JSON.stringify({ errors, diagnostic }));
    const failed = await send('Page.captureScreenshot', { format: 'png' }).catch(() => null);
    if (failed) fs.writeFileSync(`${output}/failure.png`, Buffer.from(failed.data, 'base64'));
    throw error;
} finally {
    socket.close();
    await fetch(debuggerUrl + '/json/close/' + target.id, { signal: AbortSignal.timeout(2000) }).catch(() => {});
}
