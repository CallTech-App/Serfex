// Kullanım: node check.js <sayfa>   (önce python3 build.py <sayfa>)
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const fs = require('fs'); const D = __dirname; const name = process.argv[2];
(async () => {
  fs.writeFileSync(`${D}/shots/${name}.config.js`, fs.readFileSync(`${D}/tw/tailwind.config.js`,'utf8').replace(/content:\[.*?\]/, `content:['${D}/out/${name}.html']`));
  execSync(`cd ${D}/tw && npx tailwindcss -c ${D}/shots/${name}.config.js -i in.css -o ${D}/shots/${name}.css`, { stdio: 'ignore' });
  let html = fs.readFileSync(`${D}/out/${name}.html`, 'utf8')
    .replace('<script src="https://cdn.tailwindcss.com/3.4.17"></script>', `<link rel="stylesheet" href="${name}.css"><script>window.tailwind={};</script>`)
    .replace('https://cdn.jsdelivr.net/npm/lucide@0.460.0/dist/umd/lucide.min.js', `${D}/tw/node_modules/lucide/dist/umd/lucide.min.js`);
  fs.writeFileSync(`${D}/shots/${name}.preview.html`, html);
  const b = await chromium.launch();
  for (const [scheme, w, suffix] of [['light', 1440, 'desktop'], ['dark', 400, 'mobile']]) {
    const p = await b.newPage({ viewport: { width: w, height: 900 }, colorScheme: scheme });
    const errs = []; p.on('pageerror', e => errs.push(e.message)); p.on('console', m => { if (m.type() === 'error' && !m.text().includes('ERR_')) errs.push(m.text()); });
    await p.goto(`file://${D}/shots/${name}.preview.html`); await p.waitForTimeout(700);
    const sw = await p.evaluate(() => document.documentElement.scrollWidth);
    await p.screenshot({ path: `${D}/shots/${name}-${suffix}.png`, fullPage: true });
    console.log(`${name}-${suffix}: scrollWidth=${sw} (viewport ${w}) errors=${JSON.stringify(errs)}`);
    await p.close();
  }
  await b.close();
})();
