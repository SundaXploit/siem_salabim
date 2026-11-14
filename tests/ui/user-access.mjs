import assert from 'node:assert/strict';
import fs from 'node:fs';

const output = 'docs/redesign/user-access';
fs.mkdirSync(output, {recursive: true});
const target = await (await fetch('http://127.0.0.1:9227/json/new?about:blank', {method: 'PUT'})).json();
const socket = new WebSocket(target.webSocketDebuggerUrl);
await new Promise(resolve => socket.addEventListener('open', resolve, {once: true}));
let id = 0;
const pending = new Map(), errors = [], checks = [];
socket.addEventListener('message', ({data}) => {
    const message = JSON.parse(data);
    if (message.method === 'Runtime.exceptionThrown') errors.push(message.params.exceptionDetails.exception?.description || message.params.exceptionDetails.text);
    if (!message.id) return;
    const action = pending.get(message.id);
    pending.delete(message.id);
    message.error ? action.reject(Error(message.error.message)) : action.resolve(message.result);
});
const send = (method, params = {}) => new Promise((resolve, reject) => {
    pending.set(++id, {resolve, reject});
    socket.send(JSON.stringify({id, method, params}));
});
const pause = ms => new Promise(resolve => setTimeout(resolve, ms));
async function evaluate(expression) {
    const result = await send('Runtime.evaluate', {expression, awaitPromise: true, returnByValue: true});
    if (result.exceptionDetails) throw Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text);
    return result.result.value;
}
async function waitFor(expression) {
    const deadline = Date.now() + 5000;
    while (Date.now() < deadline) {
        if (await evaluate(expression)) return;
        await pause(80);
    }
    throw Error('Timed out: ' + expression);
}
async function navigate(path) {
    await send('Page.navigate', {url: 'http://127.0.0.1:8787' + path});
    await waitFor('!!window.Alpine && document.readyState === "complete"');
}
async function click(selector) {
    const point = await evaluate(`new Promise(resolve=>{const el=document.querySelector(${JSON.stringify(selector)});el.scrollIntoView({block:'center',behavior:'instant'});requestAnimationFrame(()=>{const r=el.getBoundingClientRect();resolve({x:r.x+r.width/2,y:r.y+r.height/2})})})`);
    await send('Input.dispatchMouseEvent', {type: 'mousePressed', ...point, button: 'left', clickCount: 1});
    await send('Input.dispatchMouseEvent', {type: 'mouseReleased', ...point, button: 'left', clickCount: 1});
    await pause(50);
}
async function check(name, expression) {
    const passed = await evaluate(expression);
    checks.push({name, passed: passed === true});
    assert.equal(passed, true, name);
}
await send('Page.enable');
await send('Runtime.enable');
await navigate('/settings?section=users');
for (const width of [1440, 1024, 390, 360]) {
    await send('Emulation.setDeviceMetricsOverride', {width, height: 960, deviceScaleFactor: 1, mobile: false});
    for (const theme of ['light', 'dark']) {
        await evaluate(`localStorage.setItem('siem-theme', '${theme}')`);
        await navigate('/settings?section=users');
        await waitFor('document.querySelectorAll(".settings-user-dialog [data-access-agent]").length===4');
        await check(`table uses full panel and create form stays closed ${width}/${theme}`, `(()=>{const table=document.querySelector('.settings-user-table'),list=document.querySelector('.settings-user-list');return !document.querySelector('.settings-user-dialog').open&&table.getBoundingClientRect().width>=list.clientWidth-2&&!document.querySelector('.settings-user-create')&&document.documentElement.scrollWidth<=innerWidth})()`);
        await evaluate(`document.querySelector('[data-settings-section="users"] > summary').scrollIntoView({block:'start'});window.scrollBy(0,-64)`);
        let screenshot = await send('Page.captureScreenshot', {format: 'png'});
        fs.writeFileSync(`${output}/table-${width}-${theme}.png`, Buffer.from(screenshot.data, 'base64'));
        await click('[data-user-menu="2"]');
        await check(`three-dot menu renders Edit/Delete without clipping ${width}/${theme}`, `(()=>{const menu=document.querySelector('#user-actions-menu'),r=menu.getBoundingClientRect(),edit=menu.querySelector('[data-user-edit]');return menu.matches(':popover-open')&&edit.innerText.trim()==='Edit'&&menu.querySelector('[data-user-delete]').getClientRects().length>0&&r.left>=0&&r.right<=innerWidth&&r.bottom<=innerHeight&&edit.contains(document.elementFromPoint(r.x+20,r.y+20))&&document.querySelector('[data-user-menu="2"] svg').getBoundingClientRect().width>=16&&edit.querySelector('svg').getBoundingClientRect().width>=16})()`);
        await click('[data-user-edit]');
        await check(`edit loads saved identity, grants and privilege checklist ${width}/${theme}`, `(()=>{const f=document.querySelector('.settings-user-dialog form'),d=Alpine.$data(f),data=new FormData(f);return document.querySelector('.settings-user-dialog').open&&document.activeElement.id==='user-editor-name'&&d.editingUserId===2&&d.formName==='Raka Pratama'&&d.selectedAgents.length===1&&f.querySelector('[data-access-agent="001"]').checked&&!d.selectedPrivileges.length&&data.get('_method')==='PATCH'&&f.action.endsWith('/settings/users/2')&&f.querySelector('[name="email"]').readOnly})()`);
        await check(`editor stays inside viewport and scrolls its content ${width}/${theme}`, `(()=>{const dialog=document.querySelector('.settings-user-dialog'),r=dialog.getBoundingClientRect(),footer=dialog.querySelector('footer').getBoundingClientRect();return r.left>=0&&r.right<=innerWidth&&r.top>=0&&r.bottom<=innerHeight&&footer.bottom<=r.bottom&&document.documentElement.scrollWidth<=innerWidth})()`);
        screenshot = await send('Page.captureScreenshot', {format: 'png'});
        fs.writeFileSync(`${output}/editor-${width}-${theme}.png`, Buffer.from(screenshot.data, 'base64'));
        await click('.settings-user-dialog [data-access-agent="004"]');
        await click('.settings-user-dialog [value="alerts.acknowledge"]');
        await evaluate(`(()=>{const f=document.querySelector('.settings-user-dialog form');f.querySelector('[type="search"]').value='web-prod';f.querySelector('[type="search"]').dispatchEvent(new Event('input',{bubbles:true}));f.querySelector('[name="password"]').value='temporary-draft-password'})()`);
        await check(`search keeps hidden grants and selected privileges ${width}/${theme}`, `(()=>{const f=document.querySelector('.settings-user-dialog form'),data=new FormData(f);return f.querySelectorAll('[data-access-agent]').length===1&&data.getAll('agent_keys[]').length===2&&data.getAll('privileges[]').join(',')==='alerts.acknowledge'})()`);
        await evaluate(`(()=>{const s=document.querySelector('.settings-user-dialog [name="role"]');s.value='admin';s.dispatchEvent(new Event('change',{bubbles:true}))})()`);
        await check(`admin role disables agent and privilege submissions ${width}/${theme}`, `(()=>{const data=new FormData(document.querySelector('.settings-user-dialog form'));return !data.has('agent_keys[]')&&!data.has('privileges[]')})()`);
        await send('Input.dispatchKeyEvent', {type:'keyDown', key:'Escape', code:'Escape', windowsVirtualKeyCode:27});
        await send('Input.dispatchKeyEvent', {type:'keyUp', key:'Escape', code:'Escape', windowsVirtualKeyCode:27});
        await waitFor('!document.querySelector(".settings-user-dialog").open && !document.querySelector(".settings-user-dialog [name=password]").value');
        await check(`Escape clears password drafts ${width}/${theme}`, `!document.querySelector('.settings-user-dialog [name="password"]').value`);
        await click('[data-user-menu="2"]');
        await click('[data-user-edit]');
        await check(`cancel discards draft privileges and restores saved access ${width}/${theme}`, `(()=>{const d=Alpine.$data(document.querySelector('.settings-user-dialog'));return d.accessRole==='analyst'&&d.selectedAgents.length===1&&!d.selectedPrivileges.length&&!d.agentSearch})()`);
        await evaluate(`document.querySelector('.settings-user-dialog').close()`);
        await click('[data-user-create]');
        await check(`create opens with clean read-only analyst defaults ${width}/${theme}`, `(()=>{const f=document.querySelector('.settings-user-dialog form'),d=Alpine.$data(f);return !d.editingUserId&&!d.formName&&!d.formEmail&&d.accessRole==='analyst'&&!d.selectedAgents.length&&!d.selectedPrivileges.length&&new FormData(f).get('_method')==='POST'&&f.action.endsWith('/settings/users')&&!f.querySelector('[name="email"]').readOnly&&f.querySelector('[name="password"]').required})()`);
        await evaluate(`document.querySelector('.settings-user-dialog').close()`);
    }
}
await navigate('/settings?section=users');
await waitFor('document.querySelectorAll(".settings-user-dialog [data-access-agent]").length===4');
await click('[data-user-menu="1"]');
await check('own account menu offers Edit without self deletion', `document.querySelector('#user-actions-menu [data-user-edit]').getClientRects().length>0&&!document.querySelector('#user-actions-menu [data-user-delete]').getClientRects().length`);
await send('Input.dispatchKeyEvent', {type:'keyDown',key:'Escape',code:'Escape',windowsVirtualKeyCode:27});
await send('Input.dispatchKeyEvent', {type:'keyUp',key:'Escape',code:'Escape',windowsVirtualKeyCode:27});
await waitFor('!document.querySelector("#user-actions-menu").matches(":popover-open")');
await click('[data-user-create]');
await click('.settings-user-dialog [data-access-agent="004"]');
await evaluate(`(()=>{window.__inventoryFetch=window.fetch;window.fetch=async(url,options)=>String(url).endsWith('/settings/users/agents')?new Response(JSON.stringify({success:false,error:'Monitoring tidak tersedia'}),{status:502}):window.__inventoryFetch(url,options)})()`);
await click('[data-access-refresh]');
await waitFor("!!Alpine.$data(document.querySelector('.settings-user-dialog')).inventoryError");
await check('inventory error is visible inside modal and keeps selected agents', `Alpine.$data(document.querySelector('.settings-user-dialog')).selectedAgents.length===1&&document.querySelector('.settings-user-dialog .form-error').innerText==='Monitoring tidak tersedia'`);
await evaluate('window.fetch=window.__inventoryFetch');
await click('[data-access-refresh]');
await waitFor("!Alpine.$data(document.querySelector('.settings-user-dialog')).inventoryLoading");
await check('inventory retry preserves the draft', `!Alpine.$data(document.querySelector('.settings-user-dialog')).inventoryError&&new FormData(document.querySelector('.settings-user-dialog form')).getAll('agent_keys[]').length===1`);
await navigate('/settings?section=users&_validation_user=1');
await waitFor('document.querySelector(".settings-user-dialog").open');
await check('validation errors reopen the right editor and retain selected privileges without passwords', `(()=>{const root=document.querySelector('.settings-user-dialog'),d=Alpine.$data(root);return d.editingUserId===2&&d.formName==='Draft analyst'&&d.selectedAgents.length===2&&d.selectedPrivileges.join(',')==='alerts.ignore'&&root.querySelector('[value="alerts.ignore"]').checked&&root.querySelector('[role="alert"]').innerText.includes('Konfirmasi password tidak cocok.')&&!root.querySelector('[name="password"]').value})()`);
await navigate('/alerts/1?_role=analyst');
await check('read-only detail omits action buttons and forms', `document.body.innerText.includes('hanya untuk membaca')&&!document.querySelector('form[action$="acknowledge"],form[action$="ignore"],form[action$="notify"],[aria-label="Kirim notifikasi"]')`);
await navigate('/live-alerts?_role=analyst');
await check('read-only live alerts display only assigned agent', `document.querySelector('.data-table').innerText.includes('web-prod-02')&&!document.querySelector('.data-table').innerText.includes('database-01')&&!document.querySelector('.data-table').innerText.includes('gateway-01')&&!document.querySelector('form[action$="acknowledge"],[aria-label="Kirim notifikasi"]')`);
assert.deepEqual(errors, [], 'JavaScript exceptions');
fs.writeFileSync(`${output}/checks.json`, JSON.stringify({checks, errors}, null, 2));
console.log(JSON.stringify({checks: checks.length, failures: checks.filter(check => !check.passed).length, errors}));
await send('Page.close');
socket.close();
