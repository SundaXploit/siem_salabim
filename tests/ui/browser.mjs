// Chrome DevTools Protocol, using Node's standard library. No browser dependency.
import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const phase = process.argv[2] || 'after';
const output = path.resolve(`docs/redesign/${phase}`);
fs.mkdirSync(output, { recursive: true });
const target = await (await fetch('http://127.0.0.1:9227/json/new?about:blank', { method: 'PUT' })).json();
const socket = new WebSocket(target.webSocketDebuggerUrl);
await new Promise(resolve => socket.addEventListener('open', resolve, { once: true }));
let sequence = 0;
const pending = new Map();
const exceptions = [];
socket.addEventListener('message', ({ data }) => {
    const message = JSON.parse(data);
    if (message.method === 'Runtime.exceptionThrown') exceptions.push(message.params.exceptionDetails.text + ': ' + (message.params.exceptionDetails.exception?.description || ''));
    if (!message.id) return;
    const action = pending.get(message.id);
    pending.delete(message.id);
    if (message.error) action.reject(new Error(JSON.stringify(message.error)));
    else action.resolve(message.result);
});
function send(method, params = {}) {
    return new Promise((resolve, reject) => {
        const id = ++sequence;
        pending.set(id, { resolve, reject });
        socket.send(JSON.stringify({ id, method, params }));
    });
}
const pause = ms => new Promise(resolve => setTimeout(resolve, ms));
async function evaluate(expression) {
    const result = await send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });
    if (result.exceptionDetails) throw new Error(result.exceptionDetails.exception?.description || result.exceptionDetails.text);
    return result.result.value;
}
await send('Page.enable');
await send('Runtime.enable');
await send('Page.addScriptToEvaluateOnNewDocument', { source: `window.__uiIntervals=[];const originalInterval=window.setInterval;window.setInterval=function(fn,delay,...args){window.__uiIntervals.push(delay);return originalInterval(fn,delay,...args)};` });
await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });
await send('Page.navigate', { url: 'http://127.0.0.1:8787/login' });
await pause(1200);
let pages = [['dashboard','/dashboard'], ['live-alerts','/live-alerts'], ['alert-detail','/alerts/1'], ['leaderboard','/leaderboard'], ['history-alerts','/history/alerts'], ['analyst-log','/analyst-log'], ['history-activity','/history/activity'], ['settings','/settings'], ['profile','/profile'], ['login','/login'], ['confirm-password','/confirm-password'], ['verify-email','/verify-email'], ['installer','/install'], ['installer-wizard','/install?wizard=1'], ['install-complete','/install/complete']];
if (phase === 'polish') pages = pages.filter(([name]) => ['dashboard','leaderboard','alert-detail','installer'].includes(name));
if (phase === 'gallery') pages = pages.filter(([name]) => name === 'dashboard');
if (phase === 'installer') pages = pages.filter(([name]) => name.startsWith('installer') || name === 'install-complete');
if (phase === 'integrations') pages = pages.filter(([name]) => ['settings', 'installer-wizard'].includes(name)).map(([name, url]) => [name, name === 'settings' ? '/settings?section=ai' : url]);
if (phase === 'telegram') pages = [['settings', '/settings?section=telegram']];
if (phase === 'branding') pages = [...pages.filter(([name]) => ['dashboard', 'login', 'installer', 'installer-wizard', 'install-complete'].includes(name)), ['error-404', '/__qa/error/404']];
if (phase === 'coverage') pages = pages.filter(([name]) => ['profile','confirm-password','verify-email'].includes(name));
if (phase === 'errors') pages = [403,404,419,429,500,503].map(status=>['error-'+status,'/__qa/error/'+status]);
const expectedViews = {profile:'profile/edit','confirm-password':'auth/confirm-password','verify-email':'auth/verify-email'};
const viewports = phase === 'interactions' ? [] : phase === 'telegram' ? [[1440,900],[390,844],[360,800]] : phase === 'branding' ? [[1440,900],[1024,768],[390,844],[360,800]] : ['before','routes'].includes(phase) ? [[1440,900]] : ['preview','gallery','integrations'].includes(phase) ? [[1440,900],[390,844]] : [[1920,1080],[1440,900],[1366,768],[1024,768],[768,1024],[390,844],[360,800]];
const results = [];
for (const [width,height] of viewports) {
    await send('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile: false });
    for (const theme of phase === 'before' ? ['dark'] : ['dark','light']) {
        await evaluate(`localStorage.setItem('siem-theme','${theme}');localStorage.removeItem('siem-sidebar-collapsed')`);
        for (const [name,url] of pages) {
            exceptions.length = 0;
            await send('Page.navigate', { url: `http://127.0.0.1:8787${url}` });
            await pause(name === 'leaderboard' ? 1700 : 450);
            await evaluate('document.fonts.ready.then(() => true)');
            if (phase === 'telegram') await evaluate(`(()=>{document.querySelector('.settings-telegram-card').scrollIntoView({block:'start'});window.scrollBy(0,-72)})()`);
            if (phase === 'integrations' && ['settings', 'installer-wizard'].includes(name)) {
                await evaluate(`(()=>{if(document.querySelector('[data-step-link="4"]')){document.querySelector('[data-step-link="4"]').click();const toggle=document.querySelector('#ai_enabled');toggle.checked=true;toggle.dispatchEvent(new Event('change'));}const root=document.querySelector('[data-ai-fields]'),provider=root.querySelector('[name="ai_provider"]');provider.value='ollama';provider.dispatchEvent(new Event('change'));root.querySelector('[name="ai_model"]').value='local-model';root.scrollIntoView({block:'start'});})()`);
                await pause(250);
            }
            const state = await evaluate(`({title:document.title,heading:document.querySelector('h1,h2')?.textContent.trim(),route:location.pathname,view:document.querySelector('[data-ui-view]')?.dataset.uiView,overflow:document.documentElement.scrollWidth>innerWidth+1,bodyWidth:document.body.scrollWidth,width:innerWidth,alpine:!!window.Alpine,theme:document.documentElement.dataset.theme,errors:!document.title.includes('SIEM Salabim')||document.body.innerText.includes('Internal Server Error')||document.title.includes('Exception')})`);
            const expectedRoute = new URL(url,'http://127.0.0.1:8787').pathname;
            state.expectedView = expectedViews[name] || (name.startsWith('error-') ? 'errors/'+name.slice(6) : null);
            state.routeMatched = state.route === expectedRoute;
            state.viewMatched = !state.expectedView || state.view === state.expectedView;
            if (phase === 'branding') {
                state.brandVerified = await evaluate(`(()=>{const logos=[...document.querySelectorAll('img.application-logo')],icons=[...document.querySelectorAll('link[rel="icon"]')];return logos.length>0&&logos.every(img=>img.complete&&img.naturalWidth===512&&img.src.endsWith('/images/brand/siem-salabim-logo.png')&&getComputedStyle(img).filter==='none')&&icons.some(icon=>icon.href.endsWith('/favicon.ico'))&&icons.some(icon=>icon.href.endsWith('/favicon-32.png'))&&!!document.querySelector('link[rel="apple-touch-icon"]')&&!!document.querySelector('link[rel="manifest"]')})()`);
                assert.ok(state.brandVerified, 'Official logo or browser icons missing: '+name+':'+width+':'+theme);
            }
            const prefix = `${name}-${theme}-${width}`;
            if (width === 1440 || width === 390 || name === 'dashboard') {
                const image = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
                fs.writeFileSync(path.join(output, `${prefix}.png`), Buffer.from(image.data, 'base64'));
                if (name === 'dashboard' && width === 1440) {
                    const { cssContentSize } = await send('Page.getLayoutMetrics');
                    const full = await send('Page.captureScreenshot', {format:'png',captureBeyondViewport:true,clip:{x:0,y:0,width:cssContentSize.width,height:cssContentSize.height,scale:1}});
                    fs.writeFileSync(path.join(output,`${prefix}-full.png`),Buffer.from(full.data,'base64'));
                }
            }
            results.push({ page:name, requestedTheme:theme,width,height,...state,exceptions:[...exceptions] });
        }
    }
}
fs.writeFileSync(path.join(output,'results.json'), JSON.stringify(results,null,2));
const interactions = [];
async function check(name, expression) {
    try {
        const passed = await evaluate(expression);
        interactions.push({ name, passed: passed === true });
        if (passed !== true) console.error('Interaction failed:', name, passed);
    } catch (error) {
        interactions.push({name,passed:false,error:error.message});
        console.error('Interaction failed:',name,error.message);
    }
}
async function navigate(url) {
    await send('Page.navigate', { url: 'http://127.0.0.1:8787' + url });
    await pause(750);
}
if (phase !== 'before') {
    await send('Emulation.setDeviceMetricsOverride', { width:1440, height:900, deviceScaleFactor:1, mobile:false });
    await navigate('/dashboard');
    await check('dashboard backend totals and no fake LIVE', `document.querySelector('.signal-lead-value').textContent.trim()==='42' && !document.querySelector('.live-badge')`);
    await check('font loaded locally', `document.fonts.check('16px Inter') && [...document.fonts].some(font=>font.family==='Inter'&&font.status==='loaded') && [...document.querySelectorAll('link,script')].every(x=>!(x.href||x.src||'').includes('fonts.googleapis'))`);
    await check('collapsed sidebar persists', `(document.querySelector('.sidebar-collapse-toggle').click(), localStorage.getItem('siem-sidebar-collapsed')==='true' && Math.round(document.querySelector('.sidebar').getBoundingClientRect().width)===208)`);
    await pause(250);
    await check('collapsed rail width', `Math.round(document.querySelector('.sidebar').getBoundingClientRect().width)===76`);
    await check('official logo and collapse control fit inside the rail', `(()=>{const sidebar=document.querySelector('.sidebar').getBoundingClientRect();return [...document.querySelectorAll('.sidebar-logo .application-logo,.sidebar-collapse-toggle')].every(el=>{const r=el.getBoundingClientRect();return r.left>=sidebar.left&&r.right<=sidebar.right&&r.width>=24})})()`);
    await evaluate(`document.querySelector('.sidebar-collapse-toggle').click()`);
    await check('theme toggle persists', `(()=>{const before=document.documentElement.dataset.theme;document.querySelector('[data-theme-toggle]').click();return document.documentElement.dataset.theme!==before && localStorage.getItem('siem-theme')===document.documentElement.dataset.theme})()`);
    await navigate('/live-alerts?status=all&per_page=25&page=2');
    await check('polling initialized once per component', `window.__uiIntervals.filter(x=>x===30000).length===2&&window.__uiIntervals.filter(x=>x===1000).length===2`);
    await check('refresh preserves page/filter and initializes replaced row components', `(async()=>{const el=document.querySelector('[x-data^="alertTable"]'), c=Alpine.$data(el), url=location.href;await c.refreshData();await new Promise(r=>setTimeout(r,50));return location.href===url&&!c.refreshError&&document.querySelector('select[name="status"]').value==='all'&&document.querySelector('select[name="per_page"]').value==='25'&&Boolean(document.querySelector('#alert-table-card-body [x-data]')?._x_dataStack)})()`);
    await check('bulk selection pauses refresh', `(async()=>{const c=Alpine.$data(document.querySelector('[x-data^="alertTable"]'));c.startBulkMode();c.toggleAll(true);const count=c.selectedIds.length;await c.refreshData();return count>0&&c.refreshPaused&&!c.isRefreshing&&c.selectedIds.length===count})()`);
    await evaluate(`Alpine.$data(document.querySelector('[x-data^="alertTable"]')).openBulk('ignore')`);
    await pause(100);
    await check('native triage modal focus', `document.querySelector('.bulk-triage-dialog').open && document.activeElement===document.querySelector('.bulk-triage-dialog textarea')`);
    await evaluate(`(()=>{const c=Alpine.$data(document.querySelector('[x-data^="alertTable"]'));c.closeBulk();c.cancelBulkMode()})()`);
    await check('polling failure retains rows and shows error', `(async()=>{const c=Alpine.$data(document.querySelector('[x-data^="alertTable"]')),original=window.fetch,old=document.querySelector('#alert-table-card-body').innerHTML;window.fetch=async()=>{throw Error('QA network failure')};try{await c.refreshData();return !!c.refreshError&&old===document.querySelector('#alert-table-card-body').innerHTML&&!c.isRefreshing}finally{window.fetch=original}})()`);
    await navigate('/alerts/1');
    await evaluate(`(()=>{const el=document.querySelector('[x-data^="detailNotifModal"]');Alpine.$data(el).showIgnoreModal=true})()`);
    await pause(120);
    await check('inline modal has focus inside', `(()=>{const modal=[...document.querySelectorAll('.modal-backdrop[role="dialog"]')].find(m=>m.getClientRects().length);return !!modal&&modal.contains(document.activeElement)})()`);
    await evaluate(`(()=>{const m=[...document.querySelectorAll('.modal-backdrop[role="dialog"]')].find(m=>m.getClientRects().length);[...m.querySelectorAll('button,input,textarea')].filter(x=>!x.disabled&&x.tabIndex>=0&&x.getClientRects().length).at(-1).focus()})()`);
    await send('Input.dispatchKeyEvent',{type:'keyDown',key:'Tab',code:'Tab',windowsVirtualKeyCode:9});
    await check('inline modal traps Tab focus', `(()=>{const m=[...document.querySelectorAll('.modal-backdrop[role="dialog"]')].find(m=>m.getClientRects().length);return m.contains(document.activeElement)&&document.activeElement===[...m.querySelectorAll('a[href],button,input,textarea,select,[tabindex]')].find(x=>!x.disabled&&x.tabIndex>=0&&x.getClientRects().length)})()`);
    await send('Input.dispatchKeyEvent',{type:'keyDown',key:'Escape',code:'Escape'});
    await pause(100);
    await check('Escape closes inline modal', `[...document.querySelectorAll('.modal-backdrop[role="dialog"]')].every(m=>!m.getClientRects().length)`);
    await navigate('/profile');
    await check('profile contains account and password forms without self deletion', `document.querySelectorAll('.profile-forms .account-section').length===2&&!!document.querySelector('form[action$="/profile"]')&&!!document.querySelector('form[action$="/password"]')&&!document.querySelector('button.btn-danger,[action][method="POST"] input[value="delete" i]')&&!document.body.innerText.includes('Penghapusan akun')`);
    await navigate('/leaderboard');
    await pause(500);
    await check('Chart.js and sparkline render with local library', `(()=>{const c=Alpine.$data(document.querySelector('.leaderboard-page'));return Chart.version==='4.4.4'&&Object.keys(c.charts).length>0&&Object.keys(c.charts).some(k=>k.startsWith('spark-'))})()`);
    await check('theme redraw does not leak chart instances', `(async()=>{const c=Alpine.$data(document.querySelector('.leaderboard-page'));const count=Object.keys(Chart.instances).length;toggleTheme();await new Promise(r=>setTimeout(r,150));return Object.keys(Chart.instances).length===count&&Object.values(c.charts).every(x=>x.options.animation===false)})()`);
    await check('period filter reloads leaderboard', `(async()=>{const c=Alpine.$data(document.querySelector('.leaderboard-page'));c.period='today';await c.fetchData();return c.period==='today'&&c.ready&&!c.error})()`);
    await navigate('/settings?section=ai');
    await check('AI provider presets allow keyless local servers and require cloud keys', `(()=>{const form=document.querySelector('[data-ai-fields]'),provider=form.querySelector('[name="ai_provider"]'),key=form.querySelector('[name="ai_api_key"]'),url=form.querySelector('[name="ai_base_url"]'),format=form.querySelector('[name="ai_api_format"]');provider.value='anthropic';provider.dispatchEvent(new Event('change'));if(url.value!=='https://api.anthropic.com/v1'||format.value!=='anthropic_messages'||!key.required)return false;provider.value='ollama';provider.dispatchEvent(new Event('change'));return url.value==='http://127.0.0.1:11434/v1'&&format.value==='chat_completions'&&!key.required&&!form.querySelector('[data-ai-local-help]').hidden})()`);
    await evaluate(`(()=>{const form=document.querySelector('[data-ai-fields]');form.querySelector('[name="ai_model"]').value='local-model';window.__aiRequests=[];window.fetch=async(url,options)=>{window.__aiRequests.push({url,body:Object.fromEntries(options.body.entries())});return new Response(JSON.stringify({success:true,model:'local-model',reply:'pong'}),{status:200,headers:{'Content-Type':'application/json'}})};document.querySelector('.settings-ai-card button').click()})()`);
    await pause(100);
    await check('AI test button sends the selected local provider without a key', `(()=>{const c=Alpine.$data(document.querySelector('.settings-ai-card')),request=window.__aiRequests[0];return request?.url==='/settings/test/ai'&&request.body.ai_provider==='ollama'&&request.body.ai_api_key===''&&request.body.ai_model==='local-model'&&c.testResult?.success===true&&!c.testing})()`);
    await check('AI unexpected response is not displayed as verified', `(async()=>{window.fetch=async()=>new Response('{}',{status:200});const c=Alpine.$data(document.querySelector('.settings-ai-card'));await c.testAi();return c.testResult.success===false&&!!c.testResult.error&&!c.testing})()`);
    await check('AI model discovery populates a safe picker without requiring a model', `(async()=>{const root=document.querySelector('[data-ai-fields]'),model=root.querySelector('[name="ai_model"]'),picker=root.querySelector('[data-ai-model-options]');model.value='';window.__modelRequests=[];window.fetch=async(url,options)=>{window.__modelRequests.push({url,body:JSON.parse(options.body)});return new Response(JSON.stringify({success:true,models:[{id:'local/model-a',name:'<img src=x>'},{id:'local/model-b',name:'Model B'}],truncated:false}),{status:200})};root.querySelector('[data-ai-load-models]').click();await new Promise(r=>setTimeout(r,60));picker.value='local/model-a';picker.dispatchEvent(new Event('change'));const request=window.__modelRequests[0];return request?.url==='/settings/ai/models'&&!Object.hasOwn(request.body,'ai_model')&&Object.keys(request.body).length===4&&picker.options.length===3&&!picker.hidden&&!picker.querySelector('img')&&model.value==='local/model-a'&&root.querySelector('[data-ai-model-status]').textContent.includes('2 model')})()`);
    await check('AI unavailable catalogue preserves manual model input', `(async()=>{const root=document.querySelector('[data-ai-fields]'),model=root.querySelector('[name="ai_model"]');model.value='manual-model';window.fetch=async()=>new Response(JSON.stringify({success:false,error:'Isi model secara manual.'}),{status:200});root.querySelector('[data-ai-load-models]').click();await new Promise(r=>setTimeout(r,60));return model.value==='manual-model'&&root.querySelector('[data-ai-model-options]').hidden&&root.querySelector('[data-ai-model-status]').textContent.includes('manual')&&!root.querySelector('[data-ai-load-models]').disabled})()`);
    await check('AI ignores catalogue replies from a previous endpoint', `(async()=>{const root=document.querySelector('[data-ai-fields]'),endpoint=root.querySelector('[name="ai_base_url"]');window.fetch=()=>new Promise(resolve=>window.__resolveModels=resolve);root.querySelector('[data-ai-load-models]').click();endpoint.value='http://llm.internal:8000/v1';endpoint.dispatchEvent(new Event('input'));window.__resolveModels(new Response(JSON.stringify({success:true,models:[{id:'stale-model'}]}),{status:200}));await new Promise(r=>setTimeout(r,60));return root.querySelector('[data-ai-model-options]').options.length===1&&root.querySelector('[data-ai-model-options]').hidden&&!root.querySelector('[data-ai-load-models]').disabled&&root.querySelector('[data-ai-model-status]').textContent===''})()`);
    await navigate('/settings?section=telegram');
    await evaluate(`(()=>{const form=document.querySelector('[x-ref="telegramForm"]');form.querySelector('[name="telegram_bot_token"]').value='unsaved-test-token';Alpine.$data(form).destinations=[{label:'QA',chat_id:'-100123',agents:['staging']}];window.__telegramRequests=[];window.fetch=async(url,options)=>{window.__telegramRequests.push({url,body:JSON.parse(options.body)});return new Response(JSON.stringify({success:false,error:'Bot token Telegram tidak valid.'}),{status:422,headers:{'Content-Type':'application/json'}})}})()`);
    await pause(50);
    await evaluate(`document.querySelector('[data-telegram-test-chat]').click()`);
    await pause(100);
    await check('Telegram test click reads the form token and displays API errors', `(()=>{const form=document.querySelector('[x-ref="telegramForm"]'),c=Alpine.$data(form),request=window.__telegramRequests[0];return request?.url==='/settings/test/telegram'&&request.body.telegram_bot_token==='unsaved-test-token'&&request.body.chat_id==='-100123'&&c.testingChatIndex===null&&form.innerText.includes('Bot token Telegram tidak valid.')})()`);
    await check('Telegram empty chat shows feedback without a network request', `(async()=>{const c=Alpine.$data(document.querySelector('[x-ref="telegramForm"]')),count=window.__telegramRequests.length;await c.testChat(' ',0);return window.__telegramRequests.length===count&&c.testResult.error.includes('Isi Chat ID')})()`);
    await check('Telegram unexpected server response is not shown as success', `(async()=>{window.fetch=async()=>new Response('<html>proxy error</html>',{status:502});const c=Alpine.$data(document.querySelector('[x-ref="telegramForm"]'));await c.testChat('-100123',0);return c.testResult.success===false&&c.testResult.error.includes('Periksa chat tujuan')&&c.testingChatIndex===null})()`);
    await check('Telegram bot connection button works without a Chat ID', `(async()=>{const card=document.querySelector('.settings-telegram-card'),c=Alpine.$data(card);c.destinations=[];window.__botChecks=[];window.fetch=async(url,options)=>{window.__botChecks.push(JSON.parse(options.body));return new Response(JSON.stringify({success:true,status:200,message:'Bot Telegram berhasil terhubung.'}),{status:200})};card.querySelector('[data-telegram-test-bot]').click();await new Promise(r=>setTimeout(r,60));return window.__botChecks.length===1&&window.__botChecks[0].test_type==='bot'&&!Object.hasOwn(window.__botChecks[0],'chat_id')&&card.innerText.includes('Bot Telegram berhasil terhubung.')&&!c.testing})()`);
    // Restore native fetch by navigating before checking other routes.
    await navigate('/leaderboard');
    await check('admin route rejects analyst', `(async()=>{const r=await fetch('/settings?_role=analyst');return r.status===403})()`);
    await navigate('/dashboard?_role=analyst');
    await check('admin navigation hidden for analyst', `![...document.querySelectorAll('.sidebar-nav a')].some(a=>new URL(a.href).pathname==='/settings')`);
    await navigate('/dashboard?empty=1');
    await check('confirmed zero has real zero and meaningful empty state', `document.querySelector('.signal-lead-value').textContent.trim()==='0'&&document.querySelector('.soc-empty-chart').textContent.includes('Belum ada alert')&&!document.querySelector('.signal-pixels')`);
    await send('Emulation.setDeviceMetricsOverride',{width:390,height:844,deviceScaleFactor:1,mobile:false});
    await navigate('/dashboard');
    await check('closed mobile sidebar is inert', `document.querySelector('.sidebar').inert===true`);
    await send('Page.bringToFront');
    await send('Emulation.setTouchEmulationEnabled',{enabled:true,maxTouchPoints:1});
    const button = await evaluate(`(()=>{const r=document.querySelector('.mobile-sidebar-toggle').getBoundingClientRect();return{x:r.x+r.width/2,y:r.y+r.height/2,width:r.width,height:r.height}})()`);
    await send('Input.dispatchTouchEvent',{type:'touchStart',touchPoints:[{x:button.x,y:button.y}]});
    await send('Input.dispatchTouchEvent',{type:'touchEnd',touchPoints:[]});
    await pause(250);
    await check('mobile drawer opens', `document.querySelector('.sidebar').classList.contains('is-mobile-open')&&document.querySelector('.mobile-sidebar-toggle').getAttribute('aria-expanded')==='true'`);
    interactions.push({name:'mobile menu touch target at least 44px',passed:button.width>=44&&button.height>=44});
    await send('Input.dispatchKeyEvent',{type:'keyDown',key:'Escape',code:'Escape'});
    await pause(250);
    await check('mobile drawer closes on Escape', `!document.querySelector('.sidebar').classList.contains('is-mobile-open')`);
    await send('Emulation.setEmulatedMedia',{features:[{name:'prefers-reduced-motion',value:'reduce'}]});
    await check('reduced motion disables animated feedback', `(()=>{const el=document.createElement('div');el.className='raw-ai-spinner';document.body.append(el);const duration=parseFloat(getComputedStyle(el).animationDuration);el.remove();return duration<.001&&matchMedia('(prefers-reduced-motion: reduce)').matches})()`);
    await navigate('/install?wizard=1');
    await check('installer wizard remains standalone and validates first step', `(()=>{const panel=document.querySelector('[data-step-panel]:not([hidden])');panel.querySelector('input[required]').value='';document.querySelector('[data-next]').click();return !!panel&&document.querySelector('[data-step-panel]:not([hidden])')===panel&&!document.querySelector('script[src]')&&!window.Alpine})()`);
    await check('installer advances all six steps and toggles optional integrations without submitting', `(()=>{for(let step=0;step<5;step++){const panel=document.querySelector('[data-step-panel]:not([hidden])');if(Number(panel.dataset.stepPanel)!==step)return false;for(const input of panel.querySelectorAll('input[required]')){if(input.type==='hidden'||input.type==='checkbox')continue;if(!input.value)input.value=input.name==='admin_email'?'qa@example.test':input.name.startsWith('telegram_chat_ids')?'-100000000':input.type==='password'?'PreviewOnly2026!':'qa-preview';}if(step===4){for(const toggle of panel.querySelectorAll('[data-toggle]')){toggle.checked=true;toggle.dispatchEvent(new Event('change',{bubbles:true}));if(document.getElementById(toggle.dataset.toggle).hidden)return false;toggle.checked=false;toggle.dispatchEvent(new Event('change',{bubbles:true}));if(!document.getElementById(toggle.dataset.toggle).hidden)return false;}}document.querySelector('[data-next]').click();}return document.querySelector('[data-step-panel]:not([hidden])').dataset.stepPanel==='5'&&!document.querySelector('[data-submit]').hidden})()`);
    await check('installer skips OpenSearch and Telegram without submitting credentials', `(()=>{document.querySelector('[data-step-link="2"]').click();if(!document.querySelector('#opensearch-fields').disabled)return false;document.querySelector('[data-skip-integration="opensearch_enabled"]').click();document.querySelector('[data-skip-integration="telegram_enabled"]').click();const data=new FormData(document.querySelector('[data-installer-form]'));return document.querySelector('[data-step-panel]:not([hidden])').dataset.stepPanel==='4'&&data.get('opensearch_enabled')==='0'&&data.get('telegram_enabled')==='0'&&!data.has('opensearch_host')&&!data.has('telegram_bot_token')})()`);
    await check('installer disabled AI ignores stale invalid endpoint', `(()=>{document.querySelector('#ai_base_url').value='not-a-url';document.querySelector('[data-next]').click();return document.querySelector('[data-step-panel]:not([hidden])').dataset.stepPanel==='5'&&!new FormData(document.querySelector('[data-installer-form]')).has('ai_base_url')})()`);
    await check('installer local AI can advance without an API key', `(()=>{document.querySelector('[data-step-link="4"]').click();const toggle=document.querySelector('#ai_enabled');toggle.checked=true;toggle.dispatchEvent(new Event('change'));const provider=document.querySelector('#ai_provider');provider.value='ollama';provider.dispatchEvent(new Event('change'));document.querySelector('#ai_model').value='local-model';document.querySelector('#ai_api_key').value='';document.querySelector('[data-next]').click();return document.querySelector('[data-step-panel]:not([hidden])').dataset.stepPanel==='5'&&!document.querySelector('#ai_api_key').required})()`);
    await check('installer model discovery sends only AI connection fields and allows selection', `(async()=>{document.querySelector('[data-step-link="4"]').click();const root=document.querySelector('[data-ai-fields]'),model=root.querySelector('[name="ai_model"]'),picker=root.querySelector('[data-ai-model-options]');model.value='';window.__installerModels=[];window.fetch=async(url,options)=>{window.__installerModels.push({url,body:JSON.parse(options.body)});return new Response(JSON.stringify({success:true,models:[{id:'discovered-local-model',name:'Local'}]}),{status:200})};root.querySelector('[data-ai-load-models]').click();await new Promise(r=>setTimeout(r,60));picker.value='discovered-local-model';picker.dispatchEvent(new Event('change'));const request=window.__installerModels[0];return request?.url==='/install/ai/models'&&Object.keys(request.body).length===4&&!Object.hasOwn(request.body,'db_password')&&!Object.hasOwn(request.body,'ai_model')&&model.value==='discovered-local-model'&&!picker.disabled})()`);
    await check('installer cloud AI still requires a key', `(()=>{document.querySelector('[data-step-link="4"]').click();const provider=document.querySelector('#ai_provider');provider.value='openai';provider.dispatchEvent(new Event('change'));document.querySelector('#ai_model').value='cloud-model';document.querySelector('[data-next]').click();return document.querySelector('[data-step-panel]:not([hidden])').dataset.stepPanel==='4'&&document.querySelector('#ai_api_key').required})()`);
    fs.writeFileSync(path.join(output,'interactions.json'),JSON.stringify(interactions,null,2));
}
console.log(JSON.stringify({phase,pages:results.length,overflow:results.filter(r=>r.overflow).map(r=>`${r.page}:${r.width}:${r.theme}`),errors:results.filter(r=>r.errors||r.exceptions.length||!r.routeMatched||!r.viewMatched)},null,2));
await send('Page.close');
socket.close();
if (phase !== 'before') assert.ok(results.every(r=>!r.overflow&&!r.errors&&!r.exceptions.length&&r.routeMatched&&r.viewMatched),'Visual QA found overflow, runtime errors, or a different page/redirect; see results.json');
assert.ok(interactions.every(i=>i.passed),'Interaction checks failed; see interactions.json');
