import fs from 'node:fs';
import assert from 'node:assert/strict';

const output='docs/redesign/permanent-ignore-rule-id';
fs.mkdirSync(output,{recursive:true});
const target=await(await fetch('http://127.0.0.1:9227/json/new?about:blank',{method:'PUT'})).json();
const socket=new WebSocket(target.webSocketDebuggerUrl);
await new Promise(resolve=>socket.addEventListener('open',resolve,{once:true}));
let id=0;const pending=new Map(),errors=[],checks=[];
socket.addEventListener('message',({data})=>{const m=JSON.parse(data);if(m.method==='Runtime.exceptionThrown')errors.push(m.params.exceptionDetails.text);if(!m.id)return;const p=pending.get(m.id);pending.delete(m.id);m.error?p.reject(Error(m.error.message)):p.resolve(m.result);});
const send=(method,params={})=>new Promise((resolve,reject)=>{pending.set(++id,{resolve,reject});socket.send(JSON.stringify({id,method,params}));});
const pause=ms=>new Promise(resolve=>setTimeout(resolve,ms));
async function evaluate(expression){const r=await send('Runtime.evaluate',{expression,awaitPromise:true,returnByValue:true});if(r.exceptionDetails)throw Error(r.exceptionDetails.text);return r.result.value;}
async function check(name,expression){const passed=await evaluate(expression);checks.push({name,passed});assert.equal(passed,true,name);}
await send('Page.enable');await send('Runtime.enable');await send('Page.bringToFront');
for(const width of [1440,390,360,320]){
    await send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:false});
    for(const theme of ['light','dark']){
        await send('Page.navigate',{url:'http://127.0.0.1:8787/settings?section=permanent-ignore'});
        await pause(600);
        await evaluate(`window.applyTheme('${theme}')`);await pause(180);
        const label=width+'px '+theme;
        await check('management table uses Rule ID '+label,`document.querySelector('[data-settings-section="permanent-ignore"]').open&&document.querySelectorAll('[data-permanent-ignore-rule]').length===1&&document.querySelector('.permanent-ignore-table th').textContent.trim()==='Rule ID'&&document.querySelector('[data-permanent-ignore-rule] td').textContent.trim()==='10001'`);
        await check('one direct input replaces description search '+label,`(()=>{const form=document.querySelector('.permanent-ignore-form');return form.querySelectorAll('input:not([type="hidden"])').length===1&&!!form.querySelector('[name="rule_id"]')&&!form.querySelector('[name="description"],[name="alert_id"],input[type="search"]')})()`);
        await check('native validation rejects empty and wildcard IDs '+label,`(()=>{const input=document.querySelector('[name="rule_id"]');input.value='';const empty=!input.checkValidity();input.value='40*';return empty&&!input.checkValidity()})()`);
        await check('direct valid ID submits without selecting an existing alert '+label,`(()=>{const form=document.querySelector('.permanent-ignore-form');form.querySelector('[name="rule_id"]').value='40101';form.addEventListener('submit',event=>{event.preventDefault();window.__ignorePayload=Object.fromEntries(new FormData(form))},{once:true});form.requestSubmit();return window.__ignorePayload?.rule_id==='40101'&&window.__ignorePayload._section==='permanent-ignore'&&!('description' in window.__ignorePayload)&&!('alert_id' in window.__ignorePayload)})()`);
        await check('layout fits viewport '+label,`document.documentElement.scrollWidth<=innerWidth+1&&document.querySelector('[name="rule_id"]').getBoundingClientRect().right<=innerWidth&&document.querySelector('[data-add-permanent-ignore]').getBoundingClientRect().right<=innerWidth`);
        await evaluate(`document.querySelector('[data-permanent-ignore]').scrollIntoView({block:'start',behavior:'instant'});window.scrollBy(0,-72)`);
        const screenshot=await send('Page.captureScreenshot',{format:'png'});
        fs.writeFileSync(output+'/settings-'+theme+'-'+width+'.png',Buffer.from(screenshot.data,'base64'));
    }
}
await check('removal retains DELETE and section metadata',`(()=>{const form=document.querySelector('[data-permanent-ignore-rule] form');window.confirm=()=>true;form.addEventListener('submit',event=>{event.preventDefault();window.__ignoreDelete=Object.fromEntries(new FormData(form))},{once:true});form.requestSubmit();return window.__ignoreDelete._method==='DELETE'&&window.__ignoreDelete._section==='permanent-ignore'})()`);
checks.push({name:'no browser exceptions',passed:errors.length===0});
fs.writeFileSync(output+'/checks.json',JSON.stringify({checks,errors},null,2));
await send('Page.close');socket.close();
console.log(JSON.stringify({passed:checks.filter(c=>c.passed).length,total:checks.length,errors}));
assert.equal(errors.length,0);
