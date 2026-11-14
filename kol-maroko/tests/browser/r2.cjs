/**
 * R2 — "באתר" בבורר התמונות, בלי 404 של <link> בקנבס, וההערה "בלשונית 🎨 עיצוב" ככפתור (Chromium, Playwright):
 *   node kol-maroko/tests/browser/r2.cjs
 * רץ מול tests/router.php ומסד זמני (f3-seed + r2-seed); "האתר" — tests/fake-audio.php (תמונה img/7.png), דרך KOL_TEST_REC_MAP.
 * 1280px (עכבר) ו-390px (מגע).
 */
'use strict';
const { chromium } = require('playwright');
const { spawn, execFileSync } = require('child_process');
const fs = require('fs'), os = require('os'), path = require('path'), net = require('net');
const ROOT = path.resolve(__dirname, '..', '..');
const SHOTS = process.env.KOL_SHOTS || '';
const OWNER = { sub: 'u-owner', name: 'Owner Test', email: 'owner@example.test', email_verified: true, platform_admin: true };
const HERO_URL = 'https://maghreb.co.il/wp-content/uploads/img/7.png';
let failed = 0, ran = 0;
const ok = (cond, what, got) => { ran++; if (cond) console.log('✅ ' + what); else { failed++; console.log('❌ ' + what + (got !== undefined ? '\n   התקבל: ' + JSON.stringify(got).slice(0, 700) : '')); } };
const freePort = () => new Promise(r => { const s = net.createServer().listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => r(p)); }); });
const sleep = ms => new Promise(r => setTimeout(r, ms));

(async () => {
  const data = fs.mkdtempSync(path.join(os.tmpdir(), 'kol-r2-')), aud = fs.mkdtempSync(path.join(os.tmpdir(), 'kol-r2-site-'));
  execFileSync('php', [path.join(__dirname, 'f3-seed.php'), data, path.join(data, 'old-render.html')]);
  execFileSync('php', [path.join(__dirname, 'r2-seed.php'), data]);
  const port = await freePort(), aport = await freePort(), base = `http://127.0.0.1:${port}`, site = `http://127.0.0.1:${aport}`;
  const fake = spawn('php', ['-S', `127.0.0.1:${aport}`, path.join(ROOT, 'tests', 'fake-audio.php')], { cwd: ROOT, env: { ...process.env, KOL_TEST_AUDIO: aud, PHP_CLI_SERVER_WORKERS: '2' }, stdio: 'ignore' });
  const php = spawn('php', ['-S', `127.0.0.1:${port}`, path.join(ROOT, 'tests', 'router.php')], { cwd: ROOT, stdio: 'ignore',
    env: { ...process.env, KOL_TEST_DATA: data, PHP_CLI_SERVER_WORKERS: '4', KOL_TEST_REC_MAP: JSON.stringify({ 'https://maghreb.co.il': site, 'https://www.maghreb.co.il': site }) } });
  const hits = () => { const f = path.join(aud, 'log.jsonl'); return fs.existsSync(f) ? fs.readFileSync(f, 'utf8').split('\n').filter(Boolean).map(l => JSON.parse(l)) : []; };
  let browser;
  try {
    for (let i = 0; i < 100; i++) { try { await fetch(base + '/api.php?action=embed_key'); break; } catch { await sleep(50); } }
    browser = await chromium.launch();
    const HERO = 'section.kadisha-hero';
    for (const [w, hgt, mob] of [[1280, 800, false], [390, 844, true]]) {
      const ctx = await browser.newContext({ viewport: { width: w, height: hgt }, hasTouch: mob, isMobile: mob, extraHTTPHeaders: { 'X-Test-User': JSON.stringify(OWNER) } });
      const page = await ctx.newPage(), errors = [], bad = [];
      page.on('pageerror', e => errors.push(e.message));
      page.on('dialog', d => d.accept());
      // בקשות שנכשלו מול העורך עצמו (לא "האתר" — maghreb.co.il אינו ברשת כאן)
      page.on('response', r => { if (r.status() >= 400 && r.url().startsWith(base) && !/api\.php/.test(r.url())) bad.push(r.status() + ' ' + r.url().slice(base.length)); });
      page.on('requestfailed', r => { if (r.url().startsWith(base)) bad.push('failed ' + r.url().slice(base.length)); });
      const press = async sel => { if (mob) await page.tap(sel); else await page.click(sel); };
      await page.goto(base + '/#/project'); await page.waitForSelector('.pj-tree .pj-row');
      if (w === 1280) await page.evaluate(() => api('pf_migrate', {}));
      bad.length = 0;
      await page.goto(base + '/editor.php#/editor/index.html');
      await page.waitForFunction(() => { const S = Editor._state; return S.file && S.file.path === 'index.html' && S.canvas && S.canvas.m && !S.busy && S.build; }, null, { timeout: 30000 });
      await sleep(800);
      const links = await page.evaluate(() => [...Editor._state.canvas.doc.querySelectorAll('link[rel~="stylesheet" i]')].map(l => ({ href: l.getAttribute('href'), dis: l.hasAttribute('disabled'), q: l.hasAttribute('data-kol-quiet') })));
      ok(links.length > 0 && !bad.length, w + ': פתיחת index.html — אין בקשה שנכשלה מול העורך (ה-<link> של העמוד אינם נטענים מול כתובת העורך)', { bad, links });
      ok(links.every(l => !l.q), w + ': בקנבס לא נשאר סימן זמני על ה-<link>', links);
      ok(await page.evaluate(() => Editor._state.canvas.src === Editor._state.doc.text() && !/data-kol-quiet/.test(Editor._state.doc.text())), w + ': המקור לא השתנה');

      // ── 1. הבורר של "＋ תמונת רקע": "באתר" ──
      await page.evaluate(s => { const c = Editor._state.canvas, n = c.nodeOf(c.doc.querySelector(s)); c.select(n); }, HERO); await sleep(200);
      if (await page.isVisible('[data-bgt="remove"]')) { await press('[data-bgt="remove"]'); await sleep(300); }
      const info = async () => { if (mob && !(await page.evaluate(() => { const e = document.querySelector('.ed-info'); return !!e && e.getClientRects().length > 0 && e.getBoundingClientRect().height > 80; }))) { await page.tap('.ed-bar [data-panel="info"]'); await sleep(300); await page.evaluate(() => Editor._state.touch?.set('half')); await sleep(400); } };
      await info();
      await page.evaluate(() => Editor._tab('design')); await sleep(300);
      await page.waitForSelector('.ed-info [data-bg-add]', { timeout: 5000 }).catch(() => {});
      await page.evaluate(() => document.querySelector('.ed-info [data-bg-add]')?.scrollIntoView({ block: 'center' }));
      await press('.ed-info [data-bg-add]');
      await page.waitForSelector('#sheet[open] .ed-pick-item', { timeout: 10000 }).catch(() => {});
      const pk = await page.evaluate(u => { const b = document.querySelector('#sheet[open] .ed-pick-item[data-pick-site]'); const im = b && b.querySelector('img'); return { has: !!b, url: b && b.dataset.pickSite, sub: b && b.querySelector('.hint')?.textContent, img: im && im.getAttribute('src'), ref: im && im.getAttribute('referrerpolicy'), msg: document.querySelector('#sheet[open] [role="status"]')?.textContent, head: document.querySelector('#sheet[open] [data-pick-src-h]')?.textContent || '', inSrc: !!b && !!b.closest('[data-pick-src]'), n: document.querySelectorAll('#sheet[open] .ed-pick-item').length, r: b && b.getBoundingClientRect().height }; }, HERO_URL);
      ok(pk.has && pk.url === HERO_URL && pk.sub === (w === 1280 ? 'מקור · באתר' : 'מקור · עותק בכלי') && pk.inSrc && /^מקור — מהאתר המקורי · 1$/.test(pk.head) && (w === 1280 ? pk.img === HERO_URL && pk.ref === 'no-referrer' : /media/.test(pk.img || '')), w + ': בבורר — אזור נפרד "מקור", ובו "תמונת הרקע של המילון" (' + (w === 1280 ? 'באתר' : 'עותק בכלי') + '), תצוגה מקדימה ' + (w === 1280 ? 'מהאתר בלי referrer' : 'מהעותק'), pk);
      if (SHOTS) await page.screenshot({ path: path.join(SHOTS, 'r2-' + w + '-pick.png') });
      const before = hits().filter(x => x.method === 'GET').length;
      await press('#sheet[open] .ed-pick-item[data-pick-site]');
      await page.waitForFunction(() => !document.querySelector('#sheet[open]'), null, { timeout: 15000 }).catch(() => {});
      await sleep(600);
      const got = await page.evaluate(() => { const S = Editor._state, c = S.canvas, e = c.doc.querySelector('section.kadisha-hero'); return { bi: e ? getComputedStyle(e).backgroundImage : '', open: !!document.querySelector('#sheet[open]'), tx: S.doc.lastTx ? S.doc.lastTx.label : '', note: document.querySelector('.ed-note:not([hidden])')?.textContent || '' }; });
      const tree = await page.evaluate(() => api('pf_tree'));
      const media = tree.files.filter(f => f.kind === 'media' && /^media\/7(-\d+)?\.(png|webp)$/.test(f.path));
      const gets = hits().filter(x => x.method === 'GET').length - before;
      ok(!got.open && /media\/7(-\d+)?\.(png|webp)/.test(got.bi), w + ': בחירה ב"באתר" — הרקע של ' + HERO + ' הוא הקובץ בפרויקט (media/)', got);
      ok(media.length === 1, w + ': בפרויקט קובץ אחד מקושר תחת media/ (גם בפעם השנייה — אותו עותק)', tree.files.filter(f => f.kind === 'media').map(f => f.path));
      ok(gets === (w === 1280 ? 1 : 0), w + (w === 1280 ? ': בקשה אחת לאתר (GET, בלי עוגייה)' : ': בפעם השנייה — בלי הורדה (העותק שבכלי)'), { gets, hits: hits() });
      ok(hits().every(x => !x.cookie && !x.auth), w + ': לאתר לא נשלחה עוגייה או הרשאה');
      const lib = await page.evaluate(() => api('files'));
      ok(lib.files.filter(f => f.kind === 'image').length >= 1, w + ': העותק בספרייה של הכלי');
      // ביטול — הרקע חוזר להיות "אין"
      await page.evaluate(() => Editor._undo()); await sleep(300);

      // ── 2. לשונית "תוכן": ההערה היא כפתור לעיצוב ← "רקע" ──
      await page.evaluate(() => Editor._undo()); await sleep(300);          // גם את "הסר"
      await page.evaluate(s => { const c = Editor._state.canvas, e = c.doc.querySelector(s + ' h1, ' + s + ' h2, ' + s + ' p') || c.doc.querySelector(s); c.select(c.nodeOf(e)); }, HERO); await sleep(200);
      await info(); await page.evaluate(() => Editor._tab('content')); await sleep(300);
      const note = await page.evaluate(() => { const b = document.querySelector('.ct [data-f="bg-note"]'); return b ? { tag: b.localName, txt: b.textContent, h: b.getBoundingClientRect().height } : null; });
      ok(note && note.tag === 'button' && /🎨 עיצוב/.test(note.txt) && /"רקע"/.test(note.txt) && note.h >= (mob ? 32 : 20), w + ': "החלפה, הסרה והוספה של תמונת רקע — בלשונית 🎨 עיצוב" הוא כפתור', note);
      await page.evaluate(() => document.querySelector('.ct [data-f="bg-note"]')?.scrollIntoView({ block: 'center' }));
      await press('.ct [data-f="bg-note"]'); await sleep(500);
      const g = await page.evaluate(() => { const S = Editor._state, sec = document.querySelector('.ed-info [data-sec="bg"]'), r = sec && sec.getBoundingClientRect(); return { tab: S.tab, sel: S.canvas.label(S.canvas.selected), sec: !!sec && sec.getClientRects().length > 0, top: r ? Math.round(r.top) : null, vh: innerHeight, focus: document.activeElement && sec && sec.contains(document.activeElement) }; });
      ok(g.tab === 'design' && /kadisha-hero/.test(g.sel) && g.sec && g.top < g.vh && g.focus, w + ': לחיצה ← לשונית 🎨 עיצוב, הרובריקה עם התמונה נבחרת, וקבוצת "רקע" במסך ובמיקוד', g);
      if (SHOTS) await page.screenshot({ path: path.join(SHOTS, 'r2-' + w + '-bg.png') });

      // ── 3. 📁 קבצים ← 🖼️ תמונות: בלי מונה חלקי ──
      await page.goto(base + '/#/files'); await page.waitForSelector('.seg-tabs [role="tab"]', { timeout: 15000 });
      const tabs = await page.evaluate(() => [...document.querySelectorAll('.seg-tabs [role="tab"]')].map(b => b.textContent.trim()));
      ok(tabs.some(t => t === '🖼️ תמונות'), w + ': הלשונית "🖼️ תמונות" — בלי מונה (קודם: 1, רק "באתר")', tabs);
      await page.evaluate(() => [...document.querySelectorAll('.seg-tabs [role="tab"]')].find(b => /תמונות/.test(b.textContent)).click());
      await page.waitForFunction(() => /עותק בכלי/.test(document.querySelector('[data-src-state]')?.textContent || ''), null, { timeout: 10000 }).catch(() => {});
      const src = await page.evaluate(() => ({ h: document.querySelector('[data-src-sec]')?.textContent || '', chip: document.querySelector('[data-src-state]')?.textContent || '', take: !!document.querySelector('[data-src-take]'),
        tool: [...document.querySelectorAll('.lib-images .use-row')].filter(r => /מקור/.test(r.textContent) && !r.querySelector('[data-src-state]')).length }));
      ok(src.h === 'מקור · 1' && /✓ עותק בכלי/.test(src.chip) && !src.take && src.tool >= 1, w + ': 📁 ← 🖼️ — אזור "מקור · 1" עם "✓ עותק בכלי", והעותק בכלי מסומן "מקור"', src);
      if (w === 1280) {   // בעל האתר: הודעת "קובץ מקור" בגיליון הקובץ, והמחיקה זמינה לו (לאחרים — מוסתרת, ובשרת 403 — 99-r2-site-images.php)
        await page.evaluate(() => [...document.querySelectorAll('.lib-images .use-row')].find(r => /מקור/.test(r.textContent) && !r.querySelector('[data-src-state]')).click()); await sleep(300);
        const fs1 = await page.evaluate(() => ({ hint: document.querySelector('#sheet[open] [data-file-source]')?.textContent || '', del: !!document.querySelector('#sheet[open] [data-file-delete]') }));
        ok(/קובץ מקור/.test(fs1.hint) && fs1.del, w + ': גיליון הקובץ — "📦 קובץ מקור", ולבעל האתר — 🗑️', fs1);
        if (SHOTS) await page.screenshot({ path: path.join(SHOTS, 'r2-' + w + '-src.png') });
        await page.keyboard.press('Escape');
      }
      ok(!errors.length, w + ': בלי שגיאות JS', errors);
      await ctx.close();
    }
  } catch (e) { failed++; console.log('❌ חריגה: ' + (e.stack || e)); }
  finally { if (browser) await browser.close(); php.kill(); fake.kill(); }
  console.log(`\n${ran - failed}/${ran} עברו`);
  process.exit(failed ? 1 : 0);
})();
