import fs from 'node:fs';
import assert from 'node:assert/strict';

const output = 'docs/redesign/bulk-analysis';
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
async function waitFor(expression) {
    for (let attempt = 0; attempt < 100; attempt++) {
        if (await evaluate(expression)) return;
        await pause(50);
    }
    throw Error('Timed out: ' + expression);
}
async function check(name, expression) {
    const passed = await evaluate(expression);
    checks.push({ name, passed });
    assert.equal(passed, true, name);
}
async function click(selector) {
    const point = await evaluate(`new Promise(resolve=>{const el=document.querySelector(${JSON.stringify(selector)});el.scrollIntoView({block:'center',behavior:'instant'});requestAnimationFrame(()=>requestAnimationFrame(()=>{const r=el.getBoundingClientRect();resolve({x:r.x+r.width/2,y:r.y+r.height/2})}))})`);
    await send('Input.dispatchMouseEvent', { type: 'mousePressed', ...point, button: 'left', clickCount: 1 });
    await send('Input.dispatchMouseEvent', { type: 'mouseReleased', ...point, button: 'left', clickCount: 1 });
}
async function navigate(path) {
    await send('Page.navigate', { url: 'http://127.0.0.1:8787' + path });
    await pause(650);
}
await send('Page.enable');
await send('Runtime.enable');
await send('Page.addScriptToEvaluateOnNewDocument', { source: `
window.__qaAiCalls=[];window.__qaAiFail=false;
const originalFetch=window.fetch;
window.fetch=async(url,options)=>{
 if(String(url).endsWith('/ai-analysis')){
  const body=JSON.parse(options.body);window.__qaAiCalls.push({url:String(url),body});
  await new Promise(resolve=>setTimeout(resolve,80));
  if(window.__qaAiFail)return new Response(JSON.stringify({success:false,error:'Provider QA unavailable'}),{status:503});
  return new Response(JSON.stringify({success:true,analysis:{verdict:'suspicious',confidence:72,summary:'Login dan proses lanjutan perlu dikorelasikan.',
   correlations:body.alert_ids?[{alert_ids:body.alert_ids,strength:'moderate',evidence:'IP sumber sama dan waktu berurutan.',explanation:'Validasi sesi dan parent process.',alternative:'Pemeliharaan terjadwal mungkin menjelaskan rangkaian ini.'}]:[],
   agent_refs:[{ref:'agent-1',name:'web-prod-02'}],supporting_indicators:['Aktivitas berurutan dari sumber yang sama.'],legitimate_indicators:['Konfirmasi jadwal pemeliharaan.'],recommended_checks:['P1: Periksa parent process dan sesi login.'],limitations:['Belum ada validasi pemilik host.'],model:'qa-fixture',generated_at:'2026-11-12T04:00:00Z',duration_ms:1234}}),{status:200});
 }
 return originalFetch(url,options);
};` });
await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false });
await navigate('/live-alerts');
await check('bulk detail is hidden before selecting bulk mode', `!document.querySelector('[data-bulk-detail]').getClientRects().length`);
await click('[aria-controls="bulk-triage-controls"]');
await check('bulk detail requires a selection', `document.querySelector('[data-bulk-detail]').disabled`);
await click('[data-triage-id="1"]');
await click('[data-triage-id="2"]');
await check('detail form contains only the selected IDs', `new FormData(document.querySelector('[data-bulk-detail]').form).getAll('alert_ids[]').join(',')==='1,2'`);
await click('[data-bulk-detail]');
await waitFor(`!!document.querySelector('.bulk-alert-detail') && !!window.Alpine`);
await check('selected alerts load as a chronological combined detail without starting AI', `[...document.querySelectorAll('[data-bulk-event]')].map(el=>el.dataset.bulkEvent).join(',')==='2,1' && window.__qaAiCalls.length===0 && document.querySelectorAll('[x-ref="rawEvidence"]').length===1`);
await click('#raw-mode-table');
await check('combined table preserves the original Wazuh paths for both selected logs', `document.querySelector('.raw-table').innerText.includes('alert_1.event.action') && document.querySelector('.raw-table').innerText.includes('alert_2.event.action') && !document.querySelector('.raw-table').innerText.includes('.raw_data.')`);
await click('#raw-mode-ai');
await waitFor(`!Alpine.$data(document.querySelector('[x-data^="alertEvidence"]')).aiLoading`);
await check('AI analyses the complete selection in one request', `window.__qaAiCalls.length===1 && window.__qaAiCalls[0].body.alert_ids.join(',')==='2,1' && document.querySelector('.bulk-ai-correlation').innerText.includes('Penjelasan alternatif') && document.querySelector('.alert-ai-result').innerText.includes('P1:')`);
await check('AI does not trigger triage', `document.querySelectorAll('[data-bulk-event] .badge-new').length===2 && !document.querySelector('[x-ref="triageDialog"]').open`);
await click('.bulk-ai-references a');
await check('correlation links point to selected chronology evidence', `location.hash==='#bulk-event-2' && !!document.querySelector(':target[data-bulk-event="2"]')`);
await click('[data-bulk-detail-ack]');
await check('bulk ACK opens a confirmation dialog', `document.querySelector('[x-ref="triageDialog"]').open && document.querySelector('[x-ref="triageDialog"] [name="reason"]').disabled && new FormData(document.querySelector('[x-ref="triageDialog"] form')).getAll('alert_ids[]').join(',')==='2,1'`);
await send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
await send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
await click('[data-bulk-detail-ignore]');
await waitFor(`document.activeElement.id==='bulk-detail-ignore-reason'`);
await check('ignore requires a reason and keeps focus in the dialog', `document.activeElement.id==='bulk-detail-ignore-reason' && !document.querySelector('[x-ref="triageDialog"] form').checkValidity()`);
await evaluate(`(()=>{const form=document.querySelector('[x-ref="triageDialog"] form');form.addEventListener('submit',event=>{event.preventDefault();window.__qaTriage=Object.fromEntries(new FormData(form));window.__qaTriage.ids=new FormData(form).getAll('alert_ids[]')},{once:true});const reason=form.querySelector('[name="reason"]');reason.value='Pemeliharaan telah divalidasi.';reason.dispatchEvent(new Event('input',{bubbles:true}))})()`);
await click('[x-ref="triageDialog"] button[type="submit"]');
await check('confirmed mass ignore submits only the selection with the analyst reason', `window.__qaTriage.action==='ignore' && window.__qaTriage.ids.join(',')==='2,1' && window.__qaTriage.reason==='Pemeliharaan telah divalidasi.'`);
await evaluate(`document.querySelector('[x-ref="triageDialog"]').close()`);

for (const width of [1440, 390, 360]) {
    await send('Emulation.setDeviceMetricsOverride', { width, height: 1000, deviceScaleFactor: 1, mobile: false });
    await navigate('/live-alerts/triage/detail?alert_ids[]=1&alert_ids[]=2');
    await evaluate(`window.applyTheme('dark')`);
    await click('#raw-mode-ai');
    await waitFor(`!Alpine.$data(document.querySelector('[x-data^="alertEvidence"]')).aiLoading`);
    await check(`combined AI detail fits ${width}px`, `document.documentElement.scrollWidth<=innerWidth && document.querySelector('.bulk-ai-correlation').getBoundingClientRect().right<=innerWidth && !!document.querySelector('[data-bulk-detail-ack]')`);
    await evaluate(`document.querySelector('.alert-ai-result').scrollIntoView({block:'start',behavior:'instant'});window.scrollBy(0,-70)`);
    const screenshot = await send('Page.captureScreenshot', { format: 'png' });
    fs.writeFileSync(`${output}/ai-${width}.png`, Buffer.from(screenshot.data, 'base64'));
}
await evaluate(`window.__qaAiFail=true`);
await click('.alert-ai-result-footer button');
await waitFor(`!Alpine.$data(document.querySelector('[x-data^="alertEvidence"]')).aiLoading`);
await check('failed reanalysis keeps the previous assessment for review', `document.querySelector('.alert-ai-error').innerText.includes('Provider QA unavailable') && document.querySelector('.bulk-ai-correlation').getClientRects().length>0`);
await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false });
await navigate('/alerts/1');
await check('single detail retains JSON table and lazy AI', `document.querySelector('[x-data^="alertEvidence"]')!==null && window.__qaAiCalls.length===0 && document.querySelector('#raw-mode-json')!==null`);
await click('#raw-mode-table');
await check('single log table still renders evidence', `document.querySelector('.raw-table').innerText.includes('event.action')`);
await click('#raw-mode-ai');
await waitFor(`!Alpine.$data(document.querySelector('[x-data^="alertEvidence"]')).aiLoading`);
await check('single AI still uses the single-alert endpoint without bulk IDs', `window.__qaAiCalls.length===1 && window.__qaAiCalls[0].url==='/alerts/1/ai-analysis' && !('alert_ids' in window.__qaAiCalls[0].body) && !document.querySelector('.bulk-ai-correlations')`);
checks.push({ name: 'No browser exceptions', passed: errors.length === 0 });
fs.writeFileSync(`${output}/checks.json`, JSON.stringify({ checks, errors }, null, 2));
console.log(JSON.stringify({ passed: checks.filter(check => check.passed).length, total: checks.length, errors }));
await send('Page.close');
socket.close();
assert.equal(errors.length, 0);
