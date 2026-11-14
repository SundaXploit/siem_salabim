import fs from 'node:fs';

const output = 'docs/redesign/login';
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
async function check(name, expression) {
    const passed = await evaluate(expression);
    checks.push({ name, passed: passed === true });
    if (passed !== true) console.error(name, passed, await evaluate(`({url:location.href,title:document.title,cards:[...document.querySelectorAll('.settings-page > .card')].map(e=>e.className),directory:!!document.querySelector('.settings-directory')})`));
}
await send('Page.enable');
await send('Runtime.enable');
const viewports = [[1920,1080],[1536,864],[1440,900],[1366,768],[1280,720],[1024,768],[768,1024],[430,932],[390,844],[360,800],[320,568],[844,390],[390,430],[360,320]];
for (const [width,height] of viewports) {
    await send('Emulation.setDeviceMetricsOverride', {width,height,deviceScaleFactor:1,mobile:false});
    await navigate('/login');
    for (const theme of ['dark','light']) {
        await evaluate(`window.applyTheme('${theme}')`);
        await evaluate('document.fonts.ready.then(() => true)');
        const label = `${width}x${height}-${theme}`;
        await check(`no horizontal overflow ${label}`, 'document.documentElement.scrollWidth <= innerWidth + 1');
        await check(`correct background loaded ${label}`, `(async()=>{const style=getComputedStyle(document.querySelector('.auth-login-backdrop')),url=style.backgroundImage.slice(5,-2),img=new Image();img.src=url;await img.decode();return img.naturalWidth>0 && style.backgroundSize==='cover' && url.endsWith('${theme === 'dark' ? 'login-manggarai-night.png' : 'login-manggarai.png'}')})()`);
        await check(`accessible glass with opaque content ${label}`, `(()=>{const card=document.querySelector('.auth-signin-panel'),style=getComputedStyle(card);return parseFloat(style.backgroundColor.match(/[0-9.]+/g).at(-1)) === ${theme==='dark'?0.72:0.5} && style.opacity === '1' && getComputedStyle(document.querySelector('#email')).opacity === '1'})()`);
        await check(`form fits width and has touch targets ${label}`, `['#email','#password','.login-btn'].every(selector=>{const el=document.querySelector(selector),r=el.getBoundingClientRect();return r.left>=0 && r.right<=innerWidth && r.height>=44 && parseFloat(getComputedStyle(document.querySelector('#email')).fontSize)>=16})`);
        await check(`login fields and CSRF retained ${label}`, `(()=>{const form=document.querySelector('.auth-login-form');return form.method === 'post' && new URL(form.action).pathname === '/login' && !!form.querySelector('[name="_token"]').value && form.querySelector('#email').type === 'email' && form.querySelector('#password').type === 'password' && !form.querySelector('[autofocus]')})()`);
        if ([1440,390,320].includes(width) || height<=430) {
            await evaluate('window.scrollTo(0,0)');
            const screenshot = await send('Page.captureScreenshot', {format:'png'});
            fs.writeFileSync(`${output}/${label}.png`,Buffer.from(screenshot.data,'base64'));
        }
        if (height<=430) {
            await evaluate(`document.querySelector('#password').focus({preventScroll:true});document.querySelector('.login-btn').scrollIntoView({block:'center'})`);
            await pause(120);
            await check(`submit reachable with keyboard-sized viewport ${label}`, `(()=>{const r=document.querySelector('.login-btn').getBoundingClientRect();return r.top>=0 && r.bottom<=innerHeight})()`);
            await evaluate('document.activeElement.blur();window.scrollTo(0,0)');
        }
    }
}
await navigate('/login');
await evaluate(`window.applyTheme('light');document.querySelector('[data-theme-toggle]').click()`);
await check('toggle immediately selects night artwork', `document.documentElement.dataset.theme==='dark' && getComputedStyle(document.querySelector('.auth-login-backdrop')).backgroundImage.includes('login-manggarai-night.png')`);
await navigate('/login');
await check('saved dark theme keeps night artwork after reload', `document.documentElement.dataset.theme==='dark' && getComputedStyle(document.querySelector('.auth-login-backdrop')).backgroundImage.includes('login-manggarai-night.png')`);
await evaluate(`document.querySelector('[data-theme-toggle]').click()`);
await check('toggle immediately selects sunset artwork', `document.documentElement.dataset.theme==='light' && !getComputedStyle(document.querySelector('.auth-login-backdrop')).backgroundImage.includes('login-manggarai-night.png')`);
await navigate('/login');
await check('saved light theme keeps sunset artwork after reload', `document.documentElement.dataset.theme==='light' && getComputedStyle(document.querySelector('.auth-login-backdrop')).backgroundImage.includes('login-manggarai.png')`);
checks.push({name:'no JavaScript errors',passed:errors.length===0});
fs.writeFileSync(`${output}/checks.json`,JSON.stringify({checks,errors},null,2));
console.log(JSON.stringify({passed:checks.filter(c=>c.passed).length,total:checks.length,errors}));
await send('Page.close');
socket.close();
if(checks.some(c=>!c.passed))process.exitCode=1;
