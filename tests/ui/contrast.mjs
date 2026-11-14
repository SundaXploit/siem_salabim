import fs from 'node:fs';
import assert from 'node:assert/strict';

// Both standalone pages and Tailwind are generated from this source.
const source=JSON.parse(fs.readFileSync('resources/design/ina-tokens.json','utf8'));
const palette=Object.fromEntries(Object.entries(source.palettes).flatMap(([family,shades])=>Object.entries(shades).map(([shade,hex])=>[family+'-'+shade,hex])));
const rgb=hex=>hex.match(/[a-f0-9]{2}/gi).map(channel=>parseInt(channel,16));
const blend=(a,b,opacity)=>rgb(a).map((channel,i)=>opacity*channel+(1-opacity)*rgb(b)[i]);
function luminance(value) {
    const channels=(Array.isArray(value)?value:rgb(value)).map(channel=>channel/255).map(channel=>channel<=.04045?channel/12.92:((channel+.055)/1.055)**2.4);
    return .2126*channels[0]+.7152*channels[1]+.0722*channels[2];
}
function ratio(a,b) {
    const values=[luminance(a),luminance(b)].sort((x,y)=>y-x);
    return (values[0]+.05)/(values[1]+.05);
}
const results=[];
function check(theme,foreground,background,a,b,minimum=4.5) { results.push({theme,foreground,background,ratio:ratio(a,b),minimum}); }
for(const [theme,roles] of Object.entries(source.semantic)) {
    const tokens=Object.fromEntries(Object.entries(roles).map(([role,reference])=>[role,palette[reference]]));
    for(const background of ['canvas','surface-1','surface-2','surface-3']) {
        for(const foreground of ['text-primary','text-secondary','text-muted','accent','success','warning','danger','info'])check(theme,foreground,background,tokens[foreground],tokens[background]);
        for(const foreground of ['border-strong','focus'])check(theme,foreground,background,tokens[foreground],tokens[background],3);
    }
    for(const role of ['accent','success','warning','danger','info'])check(theme,role,role+'-soft',tokens[role],tokens[role+'-soft']);
    for(const role of ['primary','accent','success'])check(theme,'on-'+role,role,tokens['on-'+role],tokens[role]);
    check(theme,'on-primary','primary-hover',tokens['on-primary'],tokens['primary-hover']);
    // Check existing glass surfaces against both image extrema.
    for(const image of ['#000000','#FFFFFF']) {
        const glass=blend(theme==='light'?'#FFFFFF':palette['neutral-900'],image,theme==='light'?.5:.72);
        for(const [label,ink] of Object.entries(theme==='light'?{text:palette['neutral-900'],secondary:palette['neutral-900'],error:palette['red-900']}:{text:tokens['text-primary'],secondary:tokens['text-secondary'],error:palette['red-200']}))check(theme,'login-'+label,'glass-over-'+image,ink,glass);
        check(theme,'login-control-border','glass-over-'+image,theme==='light'?palette['neutral-900']:palette['neutral-300'],glass,3);
    }
}
const failed=results.filter(result=>result.ratio<result.minimum);
fs.mkdirSync('docs/redesign/ina-digital',{recursive:true});
fs.writeFileSync('docs/redesign/ina-digital/contrast-results.json',JSON.stringify(results.map(result=>({...result,ratio:+result.ratio.toFixed(2)})),null,2));
console.log(JSON.stringify({checks:results.length,minimumText:+Math.min(...results.filter(r=>r.minimum===4.5).map(r=>r.ratio)).toFixed(2),minimumControl:+Math.min(...results.filter(r=>r.minimum===3).map(r=>r.ratio)).toFixed(2),failed},null,2));
assert.equal(failed.length,0,'INA semantic colors do not meet the documented text/control thresholds');
