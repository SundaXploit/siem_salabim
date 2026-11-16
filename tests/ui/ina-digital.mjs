import fs from 'node:fs';
import assert from 'node:assert/strict';

const phase=process.argv[2]||'after';
const directory=`docs/redesign/ina-digital/${phase}`;
fs.mkdirSync(directory,{recursive:true});
const target=await(await fetch('http://127.0.0.1:9227/json/new?about:blank',{method:'PUT'})).json();
const socket=new WebSocket(target.webSocketDebuggerUrl);await new Promise(resolve=>socket.addEventListener('open',resolve,{once:true}));
let id=0;const pending=new Map(),exceptions=[];
socket.addEventListener('message',({data})=>{const message=JSON.parse(data);if(message.method==='Runtime.exceptionThrown')exceptions.push(message.params.exceptionDetails.exception?.description||message.params.exceptionDetails.text);if(!message.id)return;const action=pending.get(message.id);pending.delete(message.id);message.error?action.reject(Error(message.error.message)):action.resolve(message.result);});
const send=(method,params={})=>new Promise((resolve,reject)=>{pending.set(++id,{resolve,reject});socket.send(JSON.stringify({id,method,params}));});
async function evaluate(expression){const result=await send('Runtime.evaluate',{expression,awaitPromise:true,returnByValue:true});if(result.exceptionDetails)throw Error(result.exceptionDetails.exception?.description||result.exceptionDetails.text);return result.result.value;}
const pause=ms=>new Promise(resolve=>setTimeout(resolve,ms));
await send('Page.enable');await send('Runtime.enable');
let pages=[['dashboard','/dashboard'],['live-alerts','/live-alerts'],['detail','/alerts/1'],['bulk','/live-alerts/triage/detail?alert_ids[]=1&alert_ids[]=2'],['leaderboard','/leaderboard'],['history-alerts','/history/alerts'],['analyst-log','/analyst-log'],['activity','/history/activity'],['settings','/settings'],...['opensearch','permanent-ignore','ai','telegram','users','data'].map(section=>['settings-'+section,'/settings?section='+section]),['profile','/profile'],['login','/login'],['confirm','/confirm-password'],['verify','/verify-email'],['installer','/install'],['wizard','/install?wizard=1'],['complete','/install/complete'],...[403,404,419,429,500,503].map(code=>['error-'+code,'/__qa/error/'+code])];
if(phase!=='before')pages.push(
    ['detail-ignore','/alerts/9',`Alpine.$data(document.querySelector('[x-data^="notificationComposer"]')).showIgnoreModal=true`],
    ['detail-notify','/alerts/9',`Alpine.$data(document.querySelector('[x-data^="notificationComposer"]')).openNotifModal()`],
    ['bulk-ack','/live-alerts/triage/detail?alert_ids[]=1&alert_ids[]=2',`document.querySelector('[data-bulk-detail-ack]').click()`],
    ['bulk-ignore','/live-alerts/triage/detail?alert_ids[]=9&alert_ids[]=15',`document.querySelector('[data-bulk-detail-ignore]').click()`],
    ['settings-agent-picker','/settings?section=telegram',`document.querySelector('[data-telegram-agent-toggle]').click()`],
    ['export-verification','/settings?section=data',`window.dispatchEvent(new CustomEvent('download-export',{detail:{url:'/settings/data/exports/1/download',filename:'preview.zip'}}))`]
);
const profileOnly=process.argv.includes('--profile');
if(profileOnly)pages=pages.filter(([name])=>['profile','activity'].includes(name));
const results=[];
const viewports=profileOnly?[1920,1440,1280,1024,768,390,360,320]:process.argv.includes('--quick')?[1440]:phase==='before'?[1440,390]:[1440,768,390,360];
for(const width of viewports){
    await send('Emulation.setDeviceMetricsOverride',{width,height:1000,deviceScaleFactor:1,mobile:false});
    for(const theme of phase==='before'?['dark']:['light','dark']){
        for(const [name,url,setup] of pages){
            exceptions.length=0;
            await send('Page.navigate',{url:'http://127.0.0.1:8787'+url});
            await pause(name==='leaderboard'?700:250);
            await evaluate(`document.fonts.ready.then(()=>true)`);
            await evaluate(`(()=>{localStorage.setItem('siem-theme','${theme}');document.documentElement.dataset.theme='${theme}';document.documentElement.classList.toggle('dark','${theme}'==='dark');document.documentElement.classList.toggle('light','${theme}'==='light');window.applyTheme?.('${theme}',{persist:false})})()`);
            if(setup)await evaluate(setup);
            await pause(180); // Read the final palette after the existing color transitions.
            const state=await evaluate(`(()=>{
                const root=document.documentElement;
                const type={10:12,12:16,14:20,16:24,18:26,20:28,24:32,28:36,32:40,36:44,40:48,44:48,52:56};
                const spacing=[0,2,4,8,12,16,24,32,40,48,56,64,80,128];
                const invalidType=[],invalidSpacing=[],invalidContrast=[];
                const canvas=document.createElement('canvas');canvas.width=canvas.height=1;
                const context=canvas.getContext('2d',{willReadFrequently:true});
                const color=value=>{context.clearRect(0,0,1,1);context.fillStyle=value;context.fillRect(0,0,1,1);const rgba=[...context.getImageData(0,0,1,1).data];rgba[3]/=255;return rgba;};
                const over=(front,back)=>front.slice(0,3).map((n,i)=>n*front[3]+back[i]*(1-front[3])).concat(1);
                const luminance=rgba=>{const c=rgba.slice(0,3).map(n=>n/255).map(n=>n<=.04045?n/12.92:((n+.055)/1.055)**2.4);return .2126*c[0]+.7152*c[1]+.0722*c[2];};
                const ratio=(a,b)=>{const values=[luminance(a),luminance(b)].sort((a,b)=>b-a);return(values[0]+.05)/(values[1]+.05);};
                const background=el=>{const layers=[];for(let parent=el;parent;parent=parent.parentElement){const rgba=color(getComputedStyle(parent).backgroundColor);layers.unshift(rgba);if(rgba[3]===1)break;}return layers.reduce((back,front)=>over(front,back),[255,255,255,1]);};
                const visible=[...document.querySelectorAll('body *')].filter(el=>el instanceof HTMLElement && !['SCRIPT','STYLE','OPTION'].includes(el.tagName) && el.getClientRects().length && getComputedStyle(el).visibility!=='hidden');
                for(const el of visible){
                    const style=getComputedStyle(el),label=el.tagName.toLowerCase()+'.'+[...el.classList].slice(0,2).join('.');
                    const text=[...el.childNodes].some(node=>node.nodeType===Node.TEXT_NODE&&node.textContent.trim());
                    if(text && (type[parseFloat(style.fontSize)]!==parseFloat(style.lineHeight)||!['400','700'].includes(style.fontWeight)||(!style.fontFamily.includes('Inter')&&!style.fontFamily.includes('IBM Plex Mono'))))invalidType.push({element:label,size:style.fontSize,lineHeight:style.lineHeight,weight:style.fontWeight,font:style.fontFamily});
                    // The glass login is verified separately over both image extrema.
                    if(text&&!el.closest('.auth-login-shell,:disabled,[inert]')&&style.opacity==='1'){
                        const contrast=ratio(color(style.color),background(el));
                        if(contrast<4.5)invalidContrast.push({element:label,color:style.color,background:background(el),ratio:+contrast.toFixed(2)});
                    }
                    if(el.classList.contains('sr-only')||style.position==='absolute'||style.position==='fixed')continue;
                    for(const property of ['paddingTop','paddingRight','paddingBottom','paddingLeft','marginTop','marginRight','marginBottom','marginLeft','rowGap','columnGap']){
                        const cssProperty=property.replace(/[A-Z]/g,letter=>'-'+letter.toLowerCase());
                        if(el.computedStyleMap().get(cssProperty)?.toString()==='auto')continue;
                        // Reserve the original fixed sidebar width; this margin is not whitespace.
                        if(el.matches('.main-content')&&property==='marginLeft')continue;
                        const value=parseFloat(style[property]);
                        if(Number.isFinite(value) && !spacing.some(n=>Math.abs(n-Math.abs(value))<.05))invalidSpacing.push({element:label,property,value:style[property]});
                    }
                }
                return {title:document.title,route:location.pathname,width:innerWidth,theme:root.dataset.theme,overflow:root.scrollWidth>innerWidth+1,font:getComputedStyle(document.body).fontFamily,fontLoaded:document.fonts.check('16px Inter'),bodySize:getComputedStyle(document.body).fontSize,bodyLineHeight:getComputedStyle(document.body).lineHeight,invalidType:invalidType.slice(0,30),invalidSpacing:invalidSpacing.slice(0,40),invalidContrast:invalidContrast.slice(0,30),heading:document.querySelector('h1,h2')?.textContent.trim()};
            })()`);
            state.page=name;state.exceptions=[...exceptions];
            state.routeMatched=state.route===new URL(url,'http://127.0.0.1:8787').pathname;
            if(profileOnly&&name==='profile'){
                state.profileValid=await evaluate(`(()=>{const sections=[...document.querySelectorAll('.profile-forms .account-section')],boxes=sections.map(el=>el.getBoundingClientRect()),inputs=[...document.querySelectorAll('.account-field-grid input')].map(el=>el.getBoundingClientRect());return sections.length===2&&!document.querySelector('button.btn-danger,[action][method="POST"] input[value="delete" i]')&&!document.body.innerText.includes('Penghapusan akun')&&(innerWidth>1200?Math.abs(boxes[0].top-boxes[1].top)<1&&Math.abs(boxes[0].height-boxes[1].height)<1:boxes[1].top>=boxes[0].bottom+23)&&(innerWidth<=640||Math.abs(inputs[0].top-inputs[1].top)<1)})()`);
            }
            results.push(state);
            if((width===1440||width===390||(profileOnly&&width===1920))&&['dashboard','live-alerts','detail','settings-telegram','settings-ai','login','wizard','error-404','profile','activity'].includes(name)){
                if(name.startsWith('settings-'))await evaluate(`document.querySelector('details[open] .card')?.scrollIntoView({block:'start',behavior:'instant'});window.scrollBy(0,-72)`);
                const screenshot=await send('Page.captureScreenshot',{format:'png'});
                fs.writeFileSync(`${directory}/${name}-${theme}-${width}.png`,Buffer.from(screenshot.data,'base64'));
            }
            fs.writeFileSync(`${directory}/results.json`,JSON.stringify(results,null,2));
        }
        console.log(JSON.stringify({phase,width,theme,pages:results.length}));
    }
}
await send('Page.close');socket.close();
const failures=results.filter(row=>row.overflow||row.exceptions.length||!row.routeMatched||row.profileValid===false||(phase!=='before'&&(!row.font.includes('Inter')||!row.fontLoaded||row.invalidType.length||row.invalidSpacing.length||row.invalidContrast.length)));
console.log(JSON.stringify({phase,total:results.length,failed:failures.length,examples:failures.slice(0,3).map(row=>({page:row.page,width:row.width,theme:row.theme,overflow:row.overflow,invalidType:row.invalidType,invalidSpacing:row.invalidSpacing,invalidContrast:row.invalidContrast,exceptions:row.exceptions}))}));
if(phase!=='before')assert.equal(failures.length,0,'INA browser coverage failed; see results.json');
