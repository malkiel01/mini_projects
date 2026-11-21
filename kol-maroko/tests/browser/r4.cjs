/**
 * R4 — תמונת רקע שנבחרה מ"מקור" נעלמה בלי שאיש מחק אותה (הבעלים, 15.11). שחזור הציר כולו (Chromium, Playwright):
 *   node kol-maroko/tests/browser/r4.cjs
 * בחירה מ"מקור" ← שמירה ← F5 ← field_delete ← list_delete ← נקודת שחזור ← פתיחה מחדש. בכל צעד: הכלל בקובץ ה-CSS בשרת, הקישור
 * media/ בפרויקט, הקובץ בתצוגה (pv) ומה שהקנבס מציג.
 */
'use strict';
const { chromium } = require('playwright');
const { spawn, execFileSync } = require('child_process');
const fs = require('fs'), os = require('os'), path = require('path'), net = require('net');
const ROOT = path.resolve(__dirname, '..', '..');
const OWNER = { sub: 'u-owner', name: 'Owner Test', email: 'owner@example.test', email_verified: true, platform_admin: true };
let failed = 0, ran = 0;
const ok = (cond, what, got) => { ran++; if (cond) console.log('✅ ' + what); else { failed++; console.log('❌ ' + what + (got !== undefined ? '\n   התקבל: ' + JSON.stringify(got).slice(0, 900) : '')); } };
const freePort = () => new Promise(r => { const s = net.createServer().listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => r(p)); }); });
const sleep = ms => new Promise(r => setTimeout(r, ms));
const HERO = 'section.kadisha-hero';

(async () => {
  const data = fs.mkdtempSync(path.join(os.tmpdir(), 'kol-r4-')), aud = fs.mkdtempSync(path.join(os.tmpdir(), 'kol-r4-site-'));
  execFileSync('php', [path.join(__dirname, 'f3-seed.php'), data, path.join(data, 'old-render.html')]);
  execFileSync('php', [path.join(__dirname, 'r2-seed.php'), data]);
  const port = await freePort(), aport = await freePort(), base = `http://127.0.0.1:${port}`, site = `http://127.0.0.1:${aport}`;
  const fake = spawn('php', ['-S', `127.0.0.1:${aport}`, path.join(ROOT, 'tests', 'fake-audio.php')], { cwd: ROOT, env: { ...process.env, KOL_TEST_AUDIO: aud, PHP_CLI_SERVER_WORKERS: '2' }, stdio: 'ignore' });
  const php = spawn('php', ['-S', `127.0.0.1:${port}`, path.join(ROOT, 'tests', 'router.php')], { cwd: ROOT, stdio: ['ignore', 'ignore', process.env.R4_LOG ? 'inherit' : 'ignore'],
    env: { ...process.env, KOL_TEST_DATA: data, PHP_CLI_SERVER_WORKERS: '4', KOL_TEST_REC_MAP: JSON.stringify({ 'https://maghreb.co.il': site, 'https://www.maghreb.co.il': site }) } });
  let browser;
  try {
    for (let i = 0; i < 100; i++) { try { await fetch(base + '/api.php?action=embed_key'); break; } catch { await sleep(50); } }
    browser = await chromium.launch();
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 800 }, extraHTTPHeaders: { 'X-Test-User': JSON.stringify(OWNER) } });
    const page = await ctx.newPage(), errors = [];
    page.on('pageerror', e => errors.push(e.message));
    page.on('dialog', d => d.accept());
    const call = (a, b) => page.evaluate(([a, b]) => api(a, b).catch(e => ({ error: e.message })), [a, b]);
    await page.goto(base + '/#/project'); await page.waitForSelector('.pj-tree .pj-row');
    await page.evaluate(() => api('pf_migrate', {}));
    const openEd = async () => {
      await page.goto(base + '/editor.php#/editor/index.html');
      await page.waitForFunction(() => { const S = Editor._state; return S.file && S.file.path === 'index.html' && S.canvas && S.canvas.m && !S.busy && S.build; }, null, { timeout: 30000 });
      await sleep(900);
    };
    /** מה שהשרת מחזיק: כל קובץ CSS/HTML שבו מופיעה media/7, הקישור media/, והקובץ בתצוגה */
    const server = async () => page.evaluate(async () => {
      const t = await api('pf_tree'), out = { media: t.files.filter(f => f.kind === 'media').map(f => f.path), refs: {}, hero: {} };
      for (const f of t.files.filter(f => f.kind === 'file' && /\.(css|html)$/.test(f.path))) {
        const d = await api('pf_read', undefined, '&id=' + f.id), m = (d.content || '').match(/[^\n]*media\/7[^\n]*/g);
        if (m) out.refs[f.path] = m;
        const h = (d.content || '').match(/[^\n}]*kadisha-hero[^{]*\{[^}]*background[^}]*\}/g); if (h) out.hero[f.path] = h.map(s => s.replace(/\s+/g, ' ').slice(0, 220));
      }
      return out;
    });
    const canvasBg = () => page.evaluate(s => { const c = Editor._state.canvas, e = c.doc.querySelector(s); return e ? getComputedStyle(e).backgroundImage : 'אין אלמנט'; }, HERO);
    const bgLoads = () => page.evaluate(async s => { const c = Editor._state.canvas, e = c.doc.querySelector(s), u = (getComputedStyle(e).backgroundImage.match(/url\("?(.*?)"?\)/) || [])[1]; if (!u) return { u: null }; const r = await fetch(u).catch(x => ({ status: 'err ' + x.message })); return { u, status: r.status, type: r.headers?.get('content-type') }; }, HERO);
    const check = async step => {
      const s = await server(), bi = await canvasBg(), ld = await bgLoads();
      const inCss = Object.values(s.refs).some(a => a.length);
      ok(inCss, step + ': בשרת — הכלל עם media/7 בקובץ', s);
      ok(s.media.some(p => /^media\/7/.test(p)), step + ': בשרת — הקישור media/7 בפרויקט', s.media);
      ok(/media\/7/.test(bi), step + ': בקנבס — הרקע של ' + HERO + ' הוא media/7', bi);
      ok(ld.status === 200 && /^image\//.test(ld.type || ''), step + ': הקובץ נטען מהתצוגה (200, image/*)', ld);
      return s;
    };

    // ── 1. בחירה מ"מקור" ──
    await openEd();
    console.log('לפני: ' + JSON.stringify(await server()).slice(0, 600) + '\n   קנבס: ' + await canvasBg());
    await page.evaluate(s => { const c = Editor._state.canvas; c.select(c.nodeOf(c.doc.querySelector(s))); }, HERO); await sleep(200);
    await page.evaluate(() => Editor._tab('design')); await sleep(300);
    const sel = (await page.isVisible('.ed-info [data-bg-add]')) ? '.ed-info [data-bg-add]' : '.ed-info [data-bg-replace]';
    console.log('כפתור: ' + sel);
    await page.evaluate(q => document.querySelector(q)?.scrollIntoView({ block: 'center' }), sel);
    await page.click(sel);
    await page.waitForSelector('#sheet[open] .ed-pick-item[data-pick-site]', { timeout: 10000 });
    await page.click('#sheet[open] .ed-pick-item[data-pick-site]');
    await page.waitForFunction(() => !document.querySelector('#sheet[open]'), null, { timeout: 15000 }).catch(() => {});
    await sleep(500);
    ok(/media\/7/.test(await canvasBg()), 'אחרי הבחירה — בקנבס media/7', await canvasBg());
    await sleep(2500);                                  // שמירה אוטומטית (1.5ש')
    await check('אחרי שמירה');

    // ── 2. F5 ──
    await openEd(); await check('אחרי F5');

    // ── 3. field_delete + list_delete (כמו הבעלים: "הערה שלי", "פתגמים") ──
    ok(!(await call('field_save', { key: 'my_note', label: 'הערה שלי', type: 'text' })).error, 'שדה "הערה שלי"');
    ok(!(await call('list_create', { label: 'פתגמים', item_label: 'פתגם', key: 'proverbs' })).error, 'רשימה "פתגמים"');
    await openEd();
    const fd = await call('field_delete', { key: 'my_note' }); ok(!fd.error, 'field_delete', fd.error);
    await check('אחרי field_delete');
    const ld = await call('list_delete', { key: 'proverbs' }); ok(!ld.error, 'list_delete', ld.error);
    await sleep(2500);
    await check('אחרי list_delete (העורך פתוח)');
    // ── 4. נקודה ──
    const sn = await call('snapshot_create', { note: 'יומית' }); ok(!sn.error, 'snapshot_create', sn.error);
    // ── 5. פתיחה מחדש ──
    await openEd(); await check('פתיחה מחדש');

    // ── 6. שמירה של קובץ ה-CSS שנכשלת פעם אחת (רשת / 5xx של השרת) — לא נעלמת בשקט ──
    // ההחלפה הבאה: חזרה לכתובת באתר (בורר → "מקור" נותן אותו קובץ; כאן — שינוי ישיר של הכלל דרך setUrl ל-media/7 → ל-url אחר אינו נחוץ):
    // מבטלים (Ctrl+Z) ו"מחזירים" (Ctrl+Y) — שני שינויים בקובץ ה-CSS, והראשון ששומר נכשל.
    for (const [kind, how] of [['503', r => r.fulfill({ status: 503, contentType: 'text/html', body: '<h1>Service Unavailable</h1>' })], ['רשת', r => r.abort('connectionreset')]]) {
      await openEd();
      let fails = 1, seen = 0;
      await page.route(/api\.php\?action=pf_write/, async r => { const b = r.request().postDataJSON() || {}; if (/media\/7/.test(b.content || '') || /maghreb/.test(b.content || '')) { seen++; if (fails-- > 0) return how(r); } return r.continue(); });
      await page.evaluate(() => Editor._undo()); await sleep(2600);        // השמירה הראשונה (אוטומטית) נכשלת
      const mid = await page.evaluate(() => { const S = Editor._state, docs = [...Project._state.docs.values()].filter(d => d.dirty); return { status: document.querySelector('.ed-status, [data-ed-status]')?.textContent || '', st: S.status?.textContent, dirty: docs.map(d => Project._state.byId.get(d.id)?.path + ':' + (d.error || '')), note: document.querySelector('.ed-note:not([hidden])')?.textContent || '' }; });
      const stTxt = await page.evaluate(() => document.querySelector('.ed-status')?.textContent || '');
      ok(!/נשמר · גרסה/.test(stTxt) || mid.dirty.length === 0, kind + ': שמירה שנכשלה של קובץ ה-CSS — העורך אינו מראה "נשמר"', { stTxt, mid });
      await sleep(8000);                                                    // ניסיון חוזר
      const left = await page.evaluate(() => [...Project._state.docs.values()].filter(d => d.dirty).map(d => Project._state.byId.get(d.id)?.path + ':' + (d.error || '')));
      ok(!left.length && seen >= 2, kind + ': אחרי כשל רגעי — השמירה ננסית שוב לבד, ושום קובץ לא נשאר "לא נשמר"', { left, seen });
      await page.unroute(/api\.php\?action=pf_write/);
      const s = await server();
      ok(!Object.keys(s.refs).length && Object.values(s.hero).flat().some(x => /maghreb/.test(x)), kind + ': בשרת — הביטול נשמר (חזרה לכתובת באתר)', s);
      await page.evaluate(() => Editor._redo()); await sleep(2600);
      await check(kind + ': אחרי "החזר"');
    }
    // ── 7. שינוי ומיד יציאה (בטלפון: מעבר לאפליקציה אחרת — אין beforeunload): השמירה יוצאת כשהלשונית מוסתרת, לא אחרי 1.5ש' ──
    await openEd();
    await page.evaluate(() => Editor._undo()); await sleep(150);
    await page.evaluate(() => { Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => 'hidden' }); document.dispatchEvent(new Event('visibilitychange')); });
    await sleep(700);
    const s7 = await server();
    ok(!Object.keys(s7.refs).length, 'שינוי ומיד הסתרת הלשונית — השינוי כבר בשרת (לפני 1.5ש\')', s7.refs);
    await page.evaluate(() => { delete document.visibilityState; Editor._redo(); }); await sleep(2600);
    await check('אחרי "החזר" (7)');
    ok(!errors.length, 'בלי שגיאות JS', errors);
  } catch (e) { failed++; console.log('❌ חריגה: ' + (e.stack || e)); }
  finally { if (browser) await browser.close(); php.kill(); fake.kill(); }
  console.log(`\n${ran - failed}/${ran} עברו`);
  process.exit(failed ? 1 : 0);
})();
