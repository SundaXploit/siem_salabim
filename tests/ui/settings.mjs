import fs from 'node:fs';

const output = 'docs/redesign/settings';
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
    const action = pending.get(message.id);
    pending.delete(message.id);
    message.error ? action.reject(Error(message.error.message)) : action.resolve(message.result);
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
    await pause(700);
}
async function click(selector, offset = 0.5) {
    const point = await evaluate(`new Promise(resolve=>{const el=document.querySelector(${JSON.stringify(selector)});el.scrollIntoView({block:'center',behavior:'instant'});requestAnimationFrame(()=>requestAnimationFrame(()=>{const r=el.getBoundingClientRect();resolve({x:r.x+r.width*${offset},y:r.y+r.height/2})}))})`);
    await send('Input.dispatchMouseEvent', { type: 'mousePressed', ...point, button: 'left', clickCount: 1 });
    await send('Input.dispatchMouseEvent', { type: 'mouseReleased', ...point, button: 'left', clickCount: 1 });
}
async function fill(selector, value) {
    await evaluate(`(()=>{const input=document.querySelector(${JSON.stringify(selector)});input.value=${JSON.stringify(value)};input.dispatchEvent(new Event('input',{bubbles:true}))})()`);
}
async function waitFor(expression) {
    const deadline = Date.now() + 5000;
    while (Date.now() < deadline) {
        if (await evaluate(expression)) return;
        await pause(50);
    }
    throw new Error('Timed out waiting for: ' + expression);
}
async function check(name, expression) {
    const passed = await evaluate(expression);
    checks.push({ name, passed: passed === true });
    if (passed !== true) console.error(name, passed, await evaluate(`({url:location.href,title:document.title,cards:[...document.querySelectorAll('.settings-page > .card')].map(e=>e.className),directory:!!document.querySelector('.settings-directory')})`));
}
await send('Page.enable');
await send('Runtime.enable');
for (const width of [1440, 390, 360]) {
    await send('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: false });
    await navigate('/settings');
    await check(`six closed sections at ${width}px`, `document.querySelectorAll('details[data-settings-section]').length === 6 && !document.querySelector('details[data-settings-section][open]')`);
    await check(`no page overflow at ${width}px`, 'document.documentElement.scrollWidth <= innerWidth');
    await check(`no edit links and visible chevrons at ${width}px`, `!document.querySelector('.settings-directory-edit') && [...document.querySelectorAll('summary .settings-directory-chevron')].every(icon => icon.getBoundingClientRect().width > 0)`);
    for (const section of ['opensearch', 'permanent-ignore', 'ai', 'telegram', 'users', 'data']) {
        await click(`[data-settings-section="${section}"] summary`);
        await check(`${section} expands inline at ${width}px`, `location.pathname === '/settings' && location.search === '' && document.querySelector('[data-settings-section="${section}"]').open && [...document.querySelectorAll('[data-settings-section="${section}"] ${section === 'users' ? 'table' : 'form'}')].some(element => element.getBoundingClientRect().height > 0)`);
        await check(`${section} has no overflow at ${width}px`, 'document.documentElement.scrollWidth <= innerWidth');
        if (section === 'opensearch') {
            const screenshot = await send('Page.captureScreenshot', { format: 'png' });
            fs.writeFileSync(`${output}/inline-${width}.png`, Buffer.from(screenshot.data, 'base64'));
        }
        await click(`[data-settings-section="${section}"] summary`);
        await check(`${section} collapses at ${width}px`, `!document.querySelector('[data-settings-section="${section}"]').open`);
        await click(`[data-settings-section="${section}"] summary h2`);
        await check(`${section} opens by clicking its title at ${width}px`, `document.querySelector('[data-settings-section="${section}"]').open`);
        await click(`[data-settings-section="${section}"] summary p`);
        await check(`${section} closes by clicking its description at ${width}px`, `!document.querySelector('[data-settings-section="${section}"]').open`);
        await click(`[data-settings-section="${section}"] summary`, 0.01);
        await check(`${section} opens by clicking its padding at ${width}px`, `document.querySelector('[data-settings-section="${section}"]').open`);
        await click(`[data-settings-section="${section}"] summary .settings-directory-chevron`);
        await check(`${section} still closes by clicking its chevron at ${width}px`, `!document.querySelector('[data-settings-section="${section}"]').open`);
    }
    for (const theme of ['dark', 'light']) {
        await evaluate(`localStorage.setItem('siem-theme', '${theme}')`);
        await navigate('/settings');
        const screenshot = await send('Page.captureScreenshot', { format: 'png' });
        fs.writeFileSync(`${output}/list-${width}-${theme}.png`, Buffer.from(screenshot.data, 'base64'));
    }
}
await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });
for (const section of ['opensearch', 'permanent-ignore', 'ai', 'telegram', 'users', 'data']) {
    await navigate(`/settings?section=${section}`);
    await check(`section link expands only ${section}`, `document.querySelectorAll('details[data-settings-section][open]').length === 1 && document.querySelector('[data-settings-section="${section}"]').open && !!document.querySelector('.settings-directory')`);
}
await navigate('/settings');
await evaluate(`document.querySelector('[data-settings-section="opensearch"] summary').focus()`);
await send('Input.dispatchKeyEvent', {type:'keyDown', key:' ', code:'Space', windowsVirtualKeyCode:32});
await send('Input.dispatchKeyEvent', {type:'keyUp', key:' ', code:'Space', windowsVirtualKeyCode:32});
await check('section expands with keyboard', `document.querySelector('[data-settings-section="opensearch"]').open`);
await evaluate(`document.querySelector('[name="opensearch_username"]').value='unsaved-draft'; document.querySelector('[data-settings-section="opensearch"] summary').click(); document.querySelector('[data-settings-section="opensearch"] summary').click()`);
await check('unsaved values survive closing and reopening', `document.querySelector('[name="opensearch_username"]').value === 'unsaved-draft' && location.search === ''`);
await navigate('/settings?section=ai');
await check('AI has no import threshold', `!document.querySelector('[data-settings-section="ai"] [name="min_alert_level"]') && !!document.querySelector('[data-settings-section="ai"] [name="ai_model"]')`);
for (const width of [1440, 390]) {
    await send('Emulation.setDeviceMetricsOverride', {width,height:900,deviceScaleFactor:1,mobile:false});
    await navigate('/settings?section=telegram');
    await waitFor(`Alpine.$data(document.querySelector('.settings-telegram-card')).agentsLoaded`);
    await check(`Telegram destination table loads labeled routes at ${width}px`, `document.querySelectorAll('[data-telegram-destination]').length===3 && document.querySelector('[name="telegram_destinations[0][label]"]').value==='Developer' && new FormData(document.querySelector('[x-ref="telegramForm"]')).getAll('telegram_destinations[0][agents][]').includes('staging')`);
    await check(`Telegram inventory includes agents without alerts and has no manual entry at ${width}px`, `document.querySelector('[data-telegram-agent-count]').textContent==='4 agent tersedia' && !document.querySelector('#telegram-new-agent') && Alpine.$data(document.querySelector('.settings-telegram-card')).availableAgents.some(agent=>agent.name==='new-without-alerts') && !Alpine.$data(document.querySelector('.settings-telegram-card')).availableAgents.some(agent=>agent.name==='gateway-01')`);
    await check(`Telegram table has no page overflow at ${width}px`, 'document.documentElement.scrollWidth<=innerWidth');
    if (width === 1440) {
        await click('[data-telegram-add-destination]');
        await fill('[name="telegram_destinations[3][label]"]', 'Tenant baru');
        await fill('[name="telegram_destinations[3][chat_id]"]', '777888');
        await click('[data-telegram-destination="3"] [data-telegram-agent-toggle]');
        await fill('[data-telegram-destination="3"] [data-telegram-agent-search]', '004');
        await check('agent picker searches the monitoring ID', `document.querySelectorAll('[data-telegram-destination="3"] [data-agent-name]').length===1 && document.querySelector('[data-telegram-destination="3"] [data-agent-name]').value==='new-without-alerts'`);
        await click('[data-telegram-destination="3"] [data-agent-name="new-without-alerts"]');
        await waitFor(`new FormData(document.querySelector('[x-ref="telegramForm"]')).getAll('telegram_destinations[3][agents][]').includes('new-without-alerts')`);
        await click('[data-telegram-destination="3"] [data-telegram-agent-toggle]');
        await check('new destination supports a personal Chat ID and an automatically discovered agent', `(()=>{const data=new FormData(document.querySelector('[x-ref="telegramForm"]'));return data.get('telegram_destinations[3][chat_id]')==='777888' && data.getAll('telegram_destinations[3][agents][]').join(',')==='new-without-alerts'})()`);
        await click('[data-telegram-destination="1"] [aria-label="Hapus tujuan Telegram"]');
        await check('deleting a destination keeps form indexes and routing data correct', `(()=>{const data=new FormData(document.querySelector('[x-ref="telegramForm"]'));return document.querySelectorAll('[data-telegram-destination]').length===3 && data.get('telegram_destinations[2][label]')==='Tenant baru' && !data.has('telegram_destinations[3][chat_id]') && ![...data.values()].includes('123456')})()`);
        await fill('[name="telegram_bot_token"]', 'qa-only-token');
        await evaluate(`(()=>{const original=window.fetch;window.__telegramTestPayload=null;window.fetch=async(url,options)=>{if(String(url).endsWith('/settings/test/telegram')){window.__telegramTestPayload=JSON.parse(options.body);return new Response(JSON.stringify({success:true,message:'Pesan uji tersimulasi',status:200}),{status:200})}return original(url,options)}})()`);
        await click('[data-telegram-destination="0"] [data-telegram-test-chat]');
        await pause(100);
        await check('per-row test targets only that Chat ID using the unsaved bot token', `window.__telegramTestPayload.chat_id==='-100111' && window.__telegramTestPayload.telegram_bot_token==='qa-only-token' && document.querySelector('[data-settings-section="telegram"]').innerText.includes('Pesan uji tersimulasi')`);
        await evaluate(`(()=>{const original=window.fetch;window.__inventoryOriginalFetch=original;window.fetch=async(url,options)=>String(url).endsWith('/settings/telegram/agents')?new Response(JSON.stringify({success:false,error:'Monitoring tidak dapat diakses'}),{status:502}):original(url,options)})()`);
        await click('[data-telegram-refresh-agents]');
        await waitFor(`!Alpine.$data(document.querySelector('.settings-telegram-card')).agentsLoading`);
        await check('refresh failure preserves destination drafts and selected agents', `(()=>{const data=new FormData(document.querySelector('[x-ref="telegramForm"]'));return document.querySelector('.settings-agent-error').textContent==='Monitoring tidak dapat diakses' && data.get('telegram_destinations[2][label]')==='Tenant baru' && data.getAll('telegram_destinations[2][agents][]').includes('new-without-alerts')})()`);
        await evaluate(`window.fetch=window.__inventoryOriginalFetch`);
        await click('[data-telegram-refresh-agents]');
        await waitFor(`!Alpine.$data(document.querySelector('.settings-telegram-card')).agentsLoading`);
        await check('refresh retries successfully without changing routing selections', `!Alpine.$data(document.querySelector('.settings-telegram-card')).agentsError && new FormData(document.querySelector('[x-ref="telegramForm"]')).getAll('telegram_destinations[2][agents][]').includes('new-without-alerts')`);
    }
    await click('[data-telegram-destination="0"] [data-telegram-agent-toggle]');
    await check(`picker reflects existing selections and includes inactive agents at ${width}px`, `document.querySelector('[data-telegram-destination="0"] [data-agent-name="staging"]').checked && document.querySelector('[data-telegram-destination="0"] [data-agent-name="web-prod-02"]').checked && document.querySelector('[data-telegram-destination="0"] [data-agent-name="database-01"]').closest('label').innerText.includes('disconnected') && document.documentElement.scrollWidth<=innerWidth`);
    await check(`Telegram row controls do not overlap at ${width}px`, `(()=>{const cells=[...document.querySelector('[data-telegram-destination="0"]').cells];return innerWidth>760 || cells.every((cell,index)=>!index || cells[index-1].getBoundingClientRect().bottom<=cell.getBoundingClientRect().top+1)})()`);
    const telegramScreenshot=await send('Page.captureScreenshot',{format:'png'});
    fs.writeFileSync(`${output}/telegram-routing-${width}.png`,Buffer.from(telegramScreenshot.data,'base64'));
}
await send('Emulation.setDeviceMetricsOverride',{width:1440,height:900,deviceScaleFactor:1,mobile:false});
for (const [path,component] of [['/live-alerts','notifModal'],['/alerts/1','detailNotifModal']]) {
    await navigate(path);
    await click('[aria-label="Kirim notifikasi"]');
    await waitFor(`(()=>{const data=Alpine.$data(document.querySelector('[x-data^="${component}"]'));return data.showNotifModal && !data.loading})()`);
    await check(`${component} shows routed labels without any recipient selector`, `(()=>{const root=document.querySelector('[x-data^="${component}"]'),data=Alpine.$data(root);return data.showNotifModal && !data.loading && data.destinations.join(',')==='Developer,Developer personal' && !root.querySelector('[name="chat_id"]') && !root.querySelector('form[action$="notify"] button[type="submit"]').disabled && data.message.length>0})()`);
    await evaluate(`(()=>{const root=document.querySelector('[x-data^="${component}"]');Alpine.$data(root).showNotifModal=false;const original=window.fetch;window.fetch=async(url,options)=>String(url).endsWith('/template')?new Response(JSON.stringify({templates:[],destinations:[],default_message:'No mapping'}),{status:200}):original(url,options)})()`);
    await click('[aria-label="Kirim notifikasi"]');
    await waitFor(`(()=>{const data=Alpine.$data(document.querySelector('[x-data^="${component}"]'));return data.showNotifModal && !data.loading})()`);
    await check(`${component} refreshes routes and disables unmapped notifications`, `(()=>{const root=document.querySelector('[x-data^="${component}"]');return Alpine.$data(root).destinations.length===0 && root.querySelector('form[action$="notify"] button[type="submit"]').disabled})()`);
}
await navigate('/settings?section=alerts');
await check('legacy Alert link opens merged OpenSearch panel', `document.querySelector('[data-settings-section="opensearch"]').open && !document.querySelector('[data-settings-section="alerts"]')`);
await check('OpenSearch form saves connection and threshold together', `!!document.querySelector('[data-settings-section="opensearch"] form[action$="opensearch"] [name="opensearch_host"]') && !!document.querySelector('[data-settings-section="opensearch"] form[action$="opensearch"] [name="min_alert_level"]') && !!document.querySelector('[data-settings-section="opensearch"] form[action$="fetch-now"]')`);
await navigate('/settings?section=data');
await check('download stays hidden until export is ready', `!document.querySelector('[data-export-download]').getClientRects().length && !document.querySelector('[data-export-password]')`);
await evaluate(`(async()=>{const root=document.querySelector('[x-data^="exportStatus"]'),component=Alpine.$data(root);const original=window.fetch;window.__downloadAttempts=[];window.__downloads=[];
document.querySelector('.export-download-dialog a').click=function(){window.__downloads.push(this.href)};
window.fetch=async (url,options)=>{
if(String(url).endsWith('/authorize-download')){const body=JSON.parse(options.body);window.__downloadAttempts.push(body.current_password);return body.current_password==='correct-account-password'?new Response(JSON.stringify({download_url:'/settings/data/exports/1/download?verified-test=1'}),{status:200}):new Response(JSON.stringify({errors:{current_password:['Password akun tidak benar.']}}),{status:422});}
return String(url).endsWith('/status')?new Response(JSON.stringify({status:'verified',purge_status:null,download_available:true,file_size:1024,checksum:'a'.repeat(64)}),{status:200}):original(url,options);};await component.refreshStatus()})()`);
await pause(100);
await check('ready export offers download without exposing a download link or archive password', `(()=>{const root=document.querySelector('[x-data^="exportStatus"]');return root.querySelector('[data-export-download]').getClientRects().length>0 && !('download_url' in Alpine.$data(root).state) && !('archive_password' in Alpine.$data(root).state) && !document.querySelector('[data-export-password]') && !document.querySelector('.export-download-dialog a').hasAttribute('href')})()`);
await click('[data-export-download]');
await check('download opens account password verification', `document.querySelector('.export-download-dialog').open && document.activeElement.id==='export-current-password' && document.activeElement.type==='password' && !window.__downloads.length`);
const desktopDialog=await send('Page.captureScreenshot',{format:'png'});
fs.writeFileSync(`${output}/download-dialog-desktop.png`,Buffer.from(desktopDialog.data,'base64'));
await evaluate(`(()=>{const input=document.querySelector('#export-current-password');input.value='wrong-password';input.dispatchEvent(new Event('input',{bubbles:true}));input.form.requestSubmit()})()`);
await pause(100);
await check('incorrect account password cannot start download', `document.querySelector('#export-password-error').innerText==='Password akun tidak benar.' && !window.__downloads.length && document.querySelector('.export-download-dialog').open`);
await evaluate(`(()=>{const input=document.querySelector('#export-current-password');input.value='correct-account-password';input.dispatchEvent(new Event('input',{bubbles:true}));input.form.requestSubmit()})()`);
await pause(100);
await check('successful verification starts download and clears the password', `window.__downloads.length===1 && window.__downloads[0].endsWith('/settings/data/exports/1/download?verified-test=1') && !document.querySelector('.export-download-dialog').open && !document.querySelector('#export-current-password').value && !document.querySelector('.export-download-dialog a').hasAttribute('href')`);
await check('download dialog restores focus', `document.activeElement.matches('[data-export-download]')`);
await click('[data-export-download]');
await check('every download asks for account verification again', `document.querySelector('.export-download-dialog').open && !document.querySelector('#export-current-password').value && document.activeElement.id==='export-current-password'`);
await evaluate(`(()=>{const input=document.querySelector('#export-current-password');input.value='cancelled-input';input.dispatchEvent(new Event('input',{bubbles:true}))})()`);
await send('Input.dispatchKeyEvent',{type:'keyDown',key:'Escape',code:'Escape',windowsVirtualKeyCode:27});
await send('Input.dispatchKeyEvent',{type:'keyUp',key:'Escape',code:'Escape',windowsVirtualKeyCode:27});
await pause(100);
await check('cancel clears account password without another download', `!document.querySelector('.export-download-dialog').open && !document.querySelector('#export-current-password').value && window.__downloads.length===1 && window.__downloadAttempts.join(',')==='wrong-password,correct-account-password'`);
const screenshot=await send('Page.captureScreenshot',{format:'png'});
fs.writeFileSync(`${output}/export-ready.png`,Buffer.from(screenshot.data,'base64'));
await send('Emulation.setDeviceMetricsOverride',{width:390,height:900,deviceScaleFactor:1,mobile:false});
await navigate('/settings?section=data&ready=1');
await click('[data-export-download]');
await check('download verification popup fits mobile viewport', `(()=>{const d=document.querySelector('.export-download-dialog'),r=d.getBoundingClientRect();return d.open && r.left>=0 && r.right<=innerWidth && d.scrollWidth<=d.clientWidth})()`);
await check('mobile popup focuses masked account password', `document.activeElement.id==='export-current-password' && document.activeElement.type==='password'`);
const mobileDialog=await send('Page.captureScreenshot',{format:'png'});
fs.writeFileSync(`${output}/download-dialog-mobile.png`,Buffer.from(mobileDialog.data,'base64'));
await evaluate(`document.querySelector('.export-download-dialog').close()`);
checks.push({ name: 'no JavaScript errors', passed: errors.length === 0 });
fs.writeFileSync(`${output}/checks.json`, JSON.stringify({ checks, errors }, null, 2));
console.log(JSON.stringify({ passed: checks.filter(c => c.passed).length, total: checks.length, errors }));
await send('Page.close');
socket.close();
if (checks.some(c => !c.passed)) process.exitCode = 1;
