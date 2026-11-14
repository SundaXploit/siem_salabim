import fs from 'node:fs';
import path from 'node:path';

const reference = 'storage/framework/testing/ina-reference';
const output = 'docs/redesign/ina-digital';
const phase = process.argv[2] || 'after';
if (!['before', 'after'].includes(phase)) throw Error('Use before or after as the audit phase.');
fs.mkdirSync(output, { recursive: true });
function walk(dir) {
    return fs.readdirSync(dir, { withFileTypes: true }).flatMap(entry => entry.isDirectory() ? walk(path.join(dir, entry.name)) : [path.join(dir, entry.name)]);
}
const files = [...walk('resources'), 'public/branding.css'].filter(file => /\.(?:css|js|blade\.php)$/.test(file));
const properties = /\b(color|background(?:-color|-image)?|border(?:-(?:top|right|bottom|left))?-color|fill|stroke|font(?:-family|-size|-weight)?|line-height|letter-spacing|(?:margin|padding)(?:-(?:top|right|bottom|left|inline|block)(?:-start|-end)?)?|(?:row-|column-)?gap)\s*:\s*([^;{}\n]+)(?=[;}])/g;
const records = files.flatMap(file => {
    const content = fs.readFileSync(file, 'utf8');
    return [...content.matchAll(properties)].map(match => ({ file: file.replaceAll('\\', '/'), line: content.slice(0, match.index).split('\n').length, property: match[1], value: match[2].trim() }));
});
const counts = values => Object.fromEntries([...new Set(values)].sort().map(value => [value, values.filter(item => item === value).length]));
const report = {
    files: files.length,
    declarations: records.length,
    fonts: counts(records.filter(row => row.property === 'font-family').map(row => row.value)),
    fontSizes: counts(records.filter(row => row.property === 'font-size').map(row => row.value)),
    lineHeights: counts(records.filter(row => row.property === 'line-height').map(row => row.value)),
    spacings: counts(records.filter(row => /margin|padding|gap/.test(row.property)).map(row => row.value)),
    literalColors: counts(files.filter(file => !file.endsWith('welcome.blade.php')).flatMap(file => [...fs.readFileSync(file, 'utf8').matchAll(/(?<!&)#[a-f\d]{3,8}\b|rgba?\([^()]+\)/gi)].map(match => match[0].toLowerCase()))),
    records,
};
fs.writeFileSync(`${output}/audit-${phase}.json`, JSON.stringify(report, null, 2));
const palettes = {};
for (const name of ['brand-primary', 'green', 'blue', 'orange', 'red', 'neutral']) {
    if (!fs.existsSync(`${reference}/${name}.svg`)) continue;
    const source = fs.readFileSync(`${reference}/${name}.svg`, 'utf8');
    palettes[name] = { dimensions: source.match(/<svg[^>]+>/)[0], fills: [...new Set([...source.matchAll(/fill="(#[a-f\d]+)"/gi)].map(match => match[1]))], labels: [...source.matchAll(/<text[\s\S]*?<\/text>/g)].map(match => match[0]) };
}
if (Object.keys(palettes).length) fs.writeFileSync(`${output}/official-svg-fills.json`, JSON.stringify(palettes, null, 2));
console.log(JSON.stringify({ phase, files:report.files, declarations:report.declarations, fontFamilies:report.fonts, report:`${output}/audit-${phase}.json` }, null, 2));
