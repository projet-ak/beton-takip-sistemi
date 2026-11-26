// Kullanım (up.sh içinden): node shot.mjs <sayfa> [sayfa...]   ör. node shot.mjs index.php fatura_eslestir.php
// Her sayfayı 3 genişlikte çeker: notebook 1366x768 · tablet 955x880 · telefon 390x844 (iPhone 13).
// Çıktı: $ERN_RUN_DIR/shots/<sayfa>-<genislik>.png + yatay taşma ve konsol hatası raporu.
// ERN_TAM=0 → tam sayfa yerine yalnız görünen ilk ekran (sabit alt menü/FAB gerçek yerinde görünür).
// ERN_EVAL='return …' verilirse her sayfada bu JS gövdesi çalıştırılıp sonucu yazdırılır (ölçüm için).
// CDN (jsdelivr) dosyaları `npm pack` ile önbelleğe indirilip yerelden verilir (tarayıcı proxy'den çıkamıyor).
import { createRequire } from 'node:module';
// Playwright genel kurulu: ESM `import` onu bulamaz (ERR_MODULE_NOT_FOUND), require bulur
const { chromium, devices } = createRequire(import.meta.url)('playwright');
import { execSync } from 'node:child_process';
import fs from 'node:fs'; import path from 'node:path';

const URL = process.env.ERN_URL || 'http://127.0.0.1:8099';
const R = process.env.ERN_RUN_DIR || '/tmp/ern-run';
const OUT = path.join(R, 'shots'); fs.mkdirSync(OUT, { recursive: true });
const NPM = path.join(R, 'npm'); fs.mkdirSync(NPM, { recursive: true });
const GEN = (process.env.ERN_GENISLIK || '1366,955,390').split(',').map(Number);
const sayfalar = process.argv.slice(2).length ? process.argv.slice(2) : ['index.php'];

function paket(spec) {                       // 'bootstrap@5.3.3' → yerel klasör
  const dir = path.join(NPM, spec.replace('/', '__'));
  if (!fs.existsSync(dir)) {
    const tgz = execSync(`npm pack ${spec} --silent`, { cwd: NPM }).toString().trim().split('\n').pop();
    fs.mkdirSync(dir); execSync(`tar xzf ${tgz} -C ${dir} --strip-components=1`, { cwd: NPM });
  }
  return dir;
}
const MIME = { '.css': 'text/css', '.js': 'application/javascript', '.woff2': 'font/woff2', '.woff': 'font/woff', '.map': 'application/json' };

const browser = await chromium.launch();
let sorun = 0;
for (const w of GEN) {
  const opt = w <= 480 ? { ...devices['iPhone 13'] } : { viewport: { width: w, height: w > 1000 ? 768 : 880 } };
  const ctx = await browser.newContext(opt);
  await ctx.route(/^https:\/\/cdn\.jsdelivr\.net\/npm\//, async r => {
    const m = r.request().url().match(/npm\/((?:@[^/]+\/)?[^@/]+@[^/]+)\/([^?#]+)/);
    try {
      let f = path.join(paket(m[1]), m[2]);
      // npm paketinde .min.js olmayabilir (chart.js@4.4.4: yalnız chart.umd.js) → küçültülmemiş hâli
      if (!fs.existsSync(f)) f = f.replace(/\.min\.(js|css)$/, '.$1');
      if (!fs.existsSync(f)) { console.log('   (CDN dosyası yok: ' + r.request().url() + ')'); return await r.abort(); }
      return await r.fulfill({ path: f, contentType: MIME[path.extname(f)] || 'application/octet-stream' });
    } catch { return r.abort().catch(() => {}); }
  });
  await ctx.route(/fonts\.(googleapis|gstatic)\.com|ern\.com\.tr|portal\./, r => r.abort());
  for (const s of sayfalar) {
    const p = await ctx.newPage(); const hata = [];
    p.on('pageerror', e => hata.push('JS: ' + e.message));
    p.on('console', m => m.type() === 'error' && !/ERR_FAILED|net::/.test(m.text()) && hata.push('console: ' + m.text()));
    const res = await p.goto(`${URL}/${s}`, { waitUntil: 'networkidle' });
    await p.waitForTimeout(400);
    const tasma = await p.evaluate(() => {
      const de = document.documentElement, bk = [];
      for (const el of document.querySelectorAll('body *')) {
        const r = el.getBoundingClientRect();
        if (r.width && r.right > de.clientWidth + 1 && getComputedStyle(el).position !== 'fixed' && !el.closest('.table-responsive,.dropdown-menu,.offcanvas,.modal'))
          bk.push((el.id ? '#' + el.id : el.tagName.toLowerCase() + '.' + [...el.classList].slice(0, 2).join('.')) + ' →' + Math.round(r.right));
      }
      return { sayfa: de.scrollWidth - de.clientWidth, ogeler: bk.slice(0, 6) };
    });
    if (process.env.ERN_EVAL) console.log(`${w}px ${s} eval →`, await p.evaluate(new Function(process.env.ERN_EVAL)).catch(e => 'HATA: ' + e.message.split('\n')[0]));
    if (process.env.ERN_EVAL) await p.waitForTimeout(600);   // eval kaydırdıysa (scroll-behavior:smooth) otursun
    const ad = s.replace(/[/?=&]/g, '_').replace(/\.php/, '');
    const dosya = path.join(OUT, `${ad}-${w}.png`);
    await p.screenshot({ path: dosya, fullPage: process.env.ERN_TAM !== '0' });   // ERN_TAM=0 → yalnız ilk ekran
    const not = [res.status() !== 200 && `HTTP ${res.status()}`, tasma.sayfa > 0 && `yatay taşma ${tasma.sayfa}px: ${tasma.ogeler.join(', ')}`, ...hata].filter(Boolean);
    if (not.length) sorun++;
    console.log(`${w}px ${s} → ${dosya}${not.length ? '\n   ⚠ ' + not.join('\n   ⚠ ') : '  ✓'}`);
    await p.close();
  }
  await ctx.close();
}
await browser.close();
process.exit(sorun ? 1 : 0);
