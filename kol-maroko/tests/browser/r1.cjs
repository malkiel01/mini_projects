/**
 * R1 — עורך ← סטודיו ← עורך: הקנבס נטען בכל חזרה (Chromium, Playwright):   node kol-maroko/tests/browser/r1.cjs
 * רץ מול tests/router.php ומסד זמני. 1280px ו-390px (מגע); חזרה אחרי טעינה מלאה, חזרה מהירה באמצע טעינה, וחזרה ב"אחורה".
 */
'use strict';
const { chromium } = require('playwright');
const { spawn } = require('child_process');
const fs = require('fs'), os = require('os'), path = require('path'), net = require('net');
const ROOT = path.resolve(__dirname, '..', '..');
const SHOTS = process.env.KOL_SHOTS || '';
const OWNER = { sub: 'u-owner', name: 'Owner Test', email: 'owner@example.test', email_verified: true, platform_admin: true };
let failed = 0, ran = 0;
const ok = (cond, what, got) => { ran++; if (cond) console.log('✅ ' + what); else { failed++; console.log('❌ ' + what + (got !== undefined ? '\n   התקבל: ' + JSON.stringify(got).slice(0, 600) : '')); } };
const freePort = () => new Promise(r => { const s = net.createServer().listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => r(p)); }); });
const sleep = ms => new Promise(r => setTimeout(r, ms));

(async () => {
  const data = fs.mkdtempSync(path.join(os.tmpdir(), 'kol-r1-'));
  const port = await freePort(), base = `http://127.0.0.1:${port}`;
  const php = spawn('php', ['-S', `127.0.0.1:${port}`, path.join(ROOT, 'tests', 'router.php')], { cwd: ROOT, env: { ...process.env, KOL_TEST_DATA: data, PHP_CLI_SERVER_WORKERS: '4' }, stdio: 'ignore' });
  let browser;
  try {
    for (let i = 0; i < 100; i++) { try { await fetch(base + '/api.php?action=embed_key'); break; } catch { await sleep(50); } }
    browser = await chromium.launch();
    /** מצב העורך: נטען = יש קובץ, קנבס עם מודל, לא עסוק, ובקנבס יותר מ-html/head/body */
    const st = page => page.evaluate(() => { const S = typeof Editor !== 'undefined' && Editor._state; if (!S) return { none: true }; const c = S.canvas; return { file: S.file && S.file.path, busy: S.busy, m: !!(c && c.m), n: c && c.m ? window.KolSrc.all(c.m).length : 0, nav: document.querySelectorAll('.ed-nav .nv-row, .ed-navbody [data-nv]').length, status: (document.querySelector('.ed-status') || {}).textContent || '' }; });
    const loaded = (page, t = 20000) => page.waitForFunction(() => { const S = typeof Editor !== 'undefined' && Editor._state, c = S && S.canvas; return !!(S && S.file && c && c.m && !S.busy && window.KolSrc.all(c.m).length > 3); }, null, { timeout: t }).then(() => true, () => false);
    const tab = async (page, label) => { await page.click(`#tabs a:has-text("${label}")`); };
    for (const [w, hgt, mob] of process.env.R1_QUICK ? [[1280, 800, false]] : [[1280, 800, false], [390, 844, true]]) {
      const ctx = await browser.newContext({ viewport: { width: w, height: hgt }, hasTouch: mob, isMobile: mob, extraHTTPHeaders: { 'X-Test-User': JSON.stringify(OWNER) } });
      const page = await ctx.newPage(), errors = [];
      // רשת איטית כמו בשרת החי (Cloudflare, טלפון): כל בקשה ל-API מתעכבת — בלי זה המרוצים לא נראים מקומית
      const LAG = +(process.env.R1_LAG ?? 400);
      if (LAG) await page.route(/\/api\.php\?/, async r => { await sleep(LAG * (0.5 + Math.random())); r.continue().catch(() => {}); });
      page.on('pageerror', e => errors.push(e.message));
      await page.goto(base + '/#/project'); await page.waitForSelector('.pj-tree .pj-row');
      // כמו אצל הבעלים: index.html הוא העמוד היחיד (בזריעה של הבדיקות יש גם from-current/*.html, ואז הסדר מסתיר את המרוץ)
      if (w === 1280) await page.evaluate(async () => { const t = await api('pf_tree'); for (const f of t.files) if (f.kind === 'file' && /\.html?$/i.test(f.path) && f.path !== 'index.html') await api('pf_delete', { id: f.id }); });
      await page.goto(base + '/editor.php#/editor');
      ok(await loaded(page), w + ': כניסה ראשונה — הקנבס נטען', await st(page));
      const QUICK = !!process.env.R1_QUICK;   // רק 5 (הבדיקה הדטרמיניסטית של השורש) — לפיתוח
      if (!QUICK) {
      // 1. אחרי טעינה מלאה: עורך ← סטודיו ← עורך (בלשוניות)
      for (let k = 1; k <= 3; k++) {
        await tab(page, 'סטודיו'); await page.waitForFunction(() => /index\.php/.test(location.pathname) || location.hash.startsWith('#/studio'), null, { timeout: 10000 }); await sleep(800);
        await tab(page, 'עורך');
        const good = await loaded(page);
        if (SHOTS && k === 1) await page.screenshot({ path: path.join(SHOTS, 'r1-' + w + '-back.png') });
        ok(good, w + ': חזרה ' + k + ' לעורך אחרי טעינה מלאה — הקנבס נטען', await st(page));
      }
      // 2. מהר: באמצע הטעינה ← סטודיו ← עורך
      for (let k = 1; k <= 3; k++) {
        await tab(page, 'סטודיו'); await sleep(150 * k); await tab(page, 'עורך'); await sleep(100 * k);
        await tab(page, 'סטודיו'); await sleep(200); await tab(page, 'עורך');
        ok(await loaded(page), w + ': מעבר מהיר ' + k + ' (באמצע טעינה) — הקנבס נטען', await st(page));
      }
      // 3. "אחורה" בדפדפן (bfcache / hash באותו מסמך)
      await tab(page, 'סטודיו'); await sleep(1500); await page.goBack(); 
      ok(await loaded(page), w + ': "אחורה" מהסטודיו לעורך — הקנבס נטען', await st(page));
      // 4. באותו מסמך: hash יוצא מ-#/editor וחוזר מיד (unmount באמצע open)
      await page.goto(base + '/editor.php#/editor'); await loaded(page);
      for (let k = 1; k <= 3; k++) {
        await page.evaluate(() => { location.hash = '#/project'; });
        await sleep(30 * k);
        await page.evaluate(() => { location.hash = '#/editor'; });
        ok(await loaded(page), w + ': באותו מסמך — יציאה מהעורך באמצע טעינה וחזרה (' + k + ') — הקנבס נטען', await st(page));
      }
      }
      // 5. השורש (project.js): שני פותחים במקביל לאותו קובץ — שניהם מקבלים את הטקסט, לא '' של מסמך שעוד נטען
      if (w === 1280) {
        const name = 'r1-' + Date.now();
        // pf_read איטי מהשאר (עמוד גדול, Cloudflare) — מי שמקבל את המסמך לפני שנקרא, קורא אותו מיד
        const slow = async r => { await sleep(2500); r.continue().catch(() => {}); };
        await page.route(/action=pf_read/, slow);
        const r = await page.evaluate(async n => {
          const mk = async p => (await api('pf_create', { path: p, content: '<!doctype html><html lang="he" dir="rtl"><head><title>' + n + '</title></head><body><h1 id="r1h">כותרת ' + n + '</h1><p>פסקה</p></body></html>\n' })).file;
          const a = await mk(n + '-a.html'), b = await mk(n + '-b.html'); await Project.docs.reload();
          const p1 = Project.docs.open(a.id), p2 = Project.docs.open(a.id);
          const t2 = (await p2).text().length, t1 = (await p1).text().length;   // כל אחד קורא את הטקסט ברגע שקיבל את המסמך
          // כמו ספירת הרכיבים (pagesDocs): מישהו אחר פותח את העמוד ראשון, והעורך פותח אותו מיד אחריו
          const other = Project.docs.open(b.id);
          await Editor._openPage(n + '-b.html');
          const od = await other, S = Editor._state;
          return { t1, t2, other: od.text().length, file: S.file && S.file.path, n: window.KolSrc.all(S.canvas.m).length, h1: !!S.canvas.doc.querySelector('#r1h') };
        }, name);
        // 6. יציאה מהעורך באמצע open (pf_read איטי) ← busy משתחרר מיד, וה-open הישן לא כותב לכניסה הבאה; חזרה ← הקנבס מלא
        const u = await page.evaluate(async n => {
          const c = (await api('pf_create', { path: n + '-c.html', content: '<!doctype html><html><head><title>c</title></head><body><h2 id="r1c">שלישי</h2></body></html>\n' })).file; await Project.docs.reload();
          const S = Editor._state, g0 = S.gen; Editor._openPage(n + '-c.html');
          await new Promise(r => setTimeout(r, 400)); const midBusy = S.busy;
          location.hash = '#/project'; await new Promise(r => setTimeout(r, 50));
          return { midBusy, busy: S.busy, host: S.host, moved: S.gen > g0 + 1 };
        }, name);
        ok(u.midBusy === true && u.busy === false && u.host === null && u.moved, 'יציאה מהעורך באמצע טעינה — busy משתחרר והדור מתקדם (open הישן נעצר)', u);
        await page.evaluate(n => { location.hash = '#/editor/' + encodeURIComponent(n + '-c.html'); }, name);
        ok(await page.waitForFunction(n => { const S = Editor._state; return S.file && S.file.path === n + '-c.html' && S.canvas && S.canvas.m && !S.busy && S.canvas.doc.querySelector('#r1c'); }, name, { timeout: 20000 }).then(() => true, () => false), 'חזרה לעורך אחרי יציאה באמצע טעינה — הקנבס מלא', await st(page));
        await page.unroute(/action=pf_read/, slow);
        // 7. רשת הביטחון: גם אם מרוץ אחר יחזיר מסמך שהקריאה הראשונה שלו ריקה — הקנבס נטען מחדש מהטקסט, ונרשם ביומן
        const net = await page.evaluate(async n => {
          const logs = []; const was = window.KolLog; window.KolLog = r => { logs.push(r); };
          const orig = Project.docs.open, f = Project.docs.byPath(n + '-a.html');
          Project.docs.open = async id => { const d = await orig(id); if (id !== f.id) return d; let first = true; return new Proxy(d, { get: (t, k) => (k === 'text' ? () => (first ? (first = false, '') : t.text()) : t[k]) }); };
          try { await Editor._openPage(n + '-a.html'); } finally { Project.docs.open = orig; window.KolLog = was; }
          const S = Editor._state; return { n: window.KolSrc.all(S.canvas.m).length, h1: !!S.canvas.doc.querySelector('#r1h'), logs: logs.map(r => r.type) };
        }, name);
        ok(net.n > 3 && net.h1 && net.logs.includes('editor-canvas-mismatch'), 'רשת ביטחון: קנבס שלא תואם לקובץ נטען מחדש ונרשם ביומן (לא ריק בשקט)', net);
        if (SHOTS) await page.screenshot({ path: path.join(SHOTS, 'r1-race.png') });
        ok(r.t1 > 50 && r.t1 === r.t2, 'שני Docs.open במקביל לאותו קובץ — שניהם עם הטקסט המלא', r);
        ok(r.other > 50 && r.file === name + '-b.html' && r.n > 3 && r.h1, 'קובץ שמישהו אחר פתח רגע לפני העורך — הקנבס מלא (לא html/head/body ריקים)', r);
      }
      ok(!errors.length, w + ': בלי שגיאות בדף', errors);
      await ctx.close();
    }
  } catch (e) { failed++; console.log('❌ חריגה: ' + (e.stack || e)); }
  finally { await browser?.close(); php.kill(); fs.rmSync(data, { recursive: true, force: true }); }
  console.log(`\n${ran - failed}/${ran} עברו`);
  process.exit(failed ? 1 : 0);
})();
