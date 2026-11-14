import fs from 'node:fs';

const tokens = JSON.parse(fs.readFileSync('resources/design/ina-tokens.json', 'utf8'));
const rules = [];
for (const [family, shades] of Object.entries(tokens.palettes)) {
    for (const [shade, value] of Object.entries(shades)) rules.push(`    --ina-${family}-${shade}: ${value};`);
}
for (const [name, style] of Object.entries(tokens.typography)) {
    rules.push(`    --ina-font-size-${name}: ${style.size / 16}rem;`, `    --ina-line-height-${name}: ${style.lineHeight / 16}rem;`, `    --ina-font-weight-${name}: ${style.weight};`, `    --ina-letter-spacing-${name}: ${style.letterSpacing};`);
}
for (const space of tokens.spacing) rules.push(`    --spacing-${space}: ${space / 16}rem;`);
const aliases = { xs:'caption', sm:'body-small', base:'body', lg:'body-large', xl:'heading-5', '2xl':'heading-3', '3xl':'heading-3', '4xl':'heading-2', '5xl':'display-small', '6xl':'display-large', '7xl':'display-large', '8xl':'display-large', '9xl':'display-large', page:'heading-1', section:'heading-5', panel:'heading-5', kpi:'heading-3' };
for (const [name, style] of Object.entries(aliases)) rules.push(`    --text-${name}: var(--ina-font-size-${style});`, `    --leading-${name}: var(--ina-line-height-${style});`);
for (const [name, space] of Object.entries({0:0,1:4,2:8,3:12,4:16,5:24,6:24,8:32,10:40,12:48,14:56,16:64,20:80,32:128})) rules.push(`    --space-${name}: var(--spacing-${space});`);
rules.push("    --ina-font-family: 'Inter', ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif;", '    --font-sans: var(--ina-font-family);', "    --font-mono: 'IBM Plex Mono', ui-monospace, SFMono-Regular, Consolas, monospace;", '    --font-weight-regular: 400;', '    --font-weight-bold: 700;');
const fonts = ['normal','italic'].map(style=>`@font-face { font-family: 'Inter'; font-style: ${style}; font-weight: 100 900; font-display: swap; src: url('./InterVariable${style === 'italic' ? '-Italic' : ''}.woff2') format('woff2'); }`).join('\n')+'\n';
const theme = (name, selector) => `${selector} {\n    color-scheme: ${name};\n${Object.entries(tokens.semantic[name]).map(([key, value])=>`    --color-${key}: var(--ina-${value});`).join('\n')}\n    --color-overlay: color-mix(in srgb, var(--ina-neutral-900) ${name === 'light' ? 58 : 72}%, transparent);\n}\n`;
const css = `/* Generated from resources/design/ina-tokens.json. Sources: ${Object.values(tokens.sources).filter(value=>value.startsWith('https://')).join(' ')} */\n:root {\n${rules.join('\n')}\n}\n${theme('light', ':root, :root[data-theme="light"]')}${theme('dark', ':root[data-theme="dark"], html.dark:not([data-theme="light"])')}@media (max-width: 640px) { :root { --text-page: var(--ina-font-size-heading-3); --leading-page: var(--ina-line-height-heading-3); } }\n`;
const file = 'public/ina-foundation.css';
const tw = ['/* Generated INA mapping for Tailwind CSS v4. Geometry utilities retain their original scale. */', '@theme inline {', '    --color-*: initial;', '    --font-sans: var(--ina-font-family);', "    --font-mono: 'IBM Plex Mono', ui-monospace, Consolas, monospace;", '    --color-white: var(--ina-neutral-25);', '    --color-black: var(--ina-neutral-900);', '    --color-primary: var(--ina-brand-inagov-500);'];
for(const [prefix,family] of Object.entries({gray:'neutral',slate:'neutral',zinc:'neutral',stone:'neutral',neutral:'neutral',green:'green',emerald:'green',teal:'green',blue:'blue',sky:'blue',cyan:'blue',indigo:'blue',violet:'blue',purple:'blue',pink:'red',rose:'red',red:'red',orange:'orange',amber:'orange',yellow:'orange',lime:'green'})) {
    for(const shade of [50,100,200,300,400,500,600,700,800,900,950]) {
        const target=family==='neutral'?(shade===50?50:Math.min(900,shade)):Math.max(100,Math.min(900,shade));
        tw.push(`    --color-${prefix}-${shade}: var(--ina-${family}-${target});`);
    }
}
for(const [alias,style] of Object.entries(aliases)) tw.push(`    --text-${alias}: var(--ina-font-size-${style});`, `    --text-${alias}--line-height: var(--ina-line-height-${style});`, `    --text-${alias}--letter-spacing: var(--ina-letter-spacing-${style});`);
tw.push('    --font-weight-normal: 400;', '    --font-weight-medium: 700;', '    --font-weight-semibold: 700;', '    --font-weight-bold: 700;', '    --font-weight-extrabold: 700;', '    --font-weight-black: 700;', '}');
const themeFile='resources/css/ui/tailwind-theme.css',themeCss=tw.join('\n')+'\n';
if (process.argv.includes('--check')) {
    if (fs.readFileSync(file, 'utf8') !== css) throw Error('INA token CSS is stale. Run node scripts/build-ina-tokens.mjs.');
    if (fs.readFileSync(themeFile, 'utf8') !== themeCss) throw Error('INA Tailwind theme is stale.');
    if (fs.readFileSync('public/fonts/inter.css', 'utf8') !== fonts) throw Error('INA font CSS is stale.');
} else { fs.writeFileSync(file, css); fs.writeFileSync(themeFile,themeCss); fs.writeFileSync('public/fonts/inter.css', fonts); }
