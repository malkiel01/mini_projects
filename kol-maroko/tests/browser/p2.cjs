/**
 * פרוסה P2 — ליטוש (V16c ⚪, E2E 🟡3 / ⚪14, V14c ⚪) — בדיקות בדפדפן   node kol-maroko/tests/browser/p2.cjs
 *
 * 1. רכיבים מוכנים ב-390 במגע אמיתי (CDP Input.dispatchTouchEvent): אייקוני רשתות, כפתורי הטופס, "פתח במפות", כותרת האקורדיון —
 *    ≥44, והקשה במרכז נוחתת על המטרה. נקודות המצגת — 12px בעין, אזור הקשה 44 (::after), ונקודה שכנה אינה "גונבת" הקשה.
 *    ובמחשב (עכבר) — הגדלים לא השתנו.
 * 2. עורך 1366: ביטול אישור המחיקה ← רמז "המחיקה בוטלה"; מיקום חופשי על אלמנט שמכסה אחר ← "⚠️ … מכסה את …" בהודעה ובפאנל;
 *    תוויות המופעים בקנבס אינן חופפות; "שמור" של ה-webhook — כפתור עם מסגרת.
 * 3. עורך 390 (מגע CDP): הרמז "נבחר ההורה" — הטקסט בשורה מלאה והכפתור בתוך המסך; 📄 ← "▶️ תצוגה חיה" בהקשה אחת.
 * צילומים — ל-tmpdir (kol-p2-shots).
 */
'use strict';
const { chromium } = require('playwright');
const { spawn, execFileSync } = require('child_process');
const fs = require('fs'), os = require('os'), path = require('path'), net = require('net');

const ROOT = path.resolve(__dirname, '..', '..');
const KIT = path.join(ROOT, 'assets', 'kit', 'components');
const OWNER = { sub: 'u-owner', name: 'Owner Test', email: 'owner@example.test', email_verified: true, platform_admin: true };
let failed = 0, ran = 0;
const ok = (cond, what, got) => { ran++; if (cond) console.log('✅ ' + what); else { failed++; console.log('❌ ' + what + (got !== undefined ? '\n   ' + JSON.stringify(got).slice(0, 700) : '')); } };
const freePort = () => new Promise(r => { const s = net.createServer().listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => r(p)); }); });
const sleep = ms => new Promise(r => setTimeout(r, ms));

(async () => {
  const data = fs.mkdtempSync(path.join(os.tmpdir(), 'kol-p2-'));
  execFileSync('php', [path.join(__dirname, 'f3-seed.php'), data, path.join(data, 'old-render.html')]);
  const port = await freePort(), base = `http://127.0.0.1:${port}`;
  const php = spawn('php', ['-S', `127.0.0.1:${port}`, path.join(ROOT, 'tests', 'router.php')], { cwd: ROOT, env: { ...process.env, KOL_TEST_DATA: data, PHP_CLI_SERVER_WORKERS: '4' }, stdio: 'ignore' });
  let browser;
  const errors = [];
  try {
    for (let i = 0; i < 100; i++) { try { await fetch(base + '/api.php?action=embed_key'); break; } catch { await sleep(50); } }
    browser = await chromium.launch();
    const mk = (w, h, touch) => browser.newContext({ viewport: { width: w, height: h }, hasTouch: touch, isMobile: touch, extraHTTPHeaders: { 'X-Test-User': JSON.stringify(OWNER) } });
    const shotDir = path.join(os.tmpdir(), 'kol-p2-shots'); fs.mkdirSync(shotDir, { recursive: true });
    const shot = (page, name) => page.screenshot({ path: path.join(shotDir, name + '.png') });
    const watch = (page, tag) => { page.on('pageerror', e => { errors.push(tag + ': ' + e.message); console.log('   ⚠️ שגיאה בדף (' + tag + '): ' + e.message); }); page.on('dialog', d => d.dismiss()); return page; };
    const cdpOf = async page => page.__cdp || (page.__cdp = await page.context().newCDPSession(page));
    const tp = (x, y) => [{ x: Math.round(x), y: Math.round(y), id: 1, radiusX: 4, radiusY: 4, force: 1 }];
    const touchTap = async (page, p, hold = 60) => { const c = await cdpOf(page); await c.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: tp(p.x, p.y) }); await sleep(hold); await c.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] }); await sleep(250); };
    const ready = page => page.waitForFunction(() => Editor._state.file && Editor._state.canvas && Editor._state.canvas.m && !Editor._state.busy && Editor._state.build && Editor._state.touch, null, { timeout: 30000 });
    const openEd = async page => { await page.goto(base + '/editor.php#/editor/index.html'); await page.reload(); await ready(page); await sleep(800); };
    const center = (page, sel) => page.evaluate(q => { const e = document.querySelector(q); if (!e) return null; e.scrollIntoView({ block: 'center' }); const r = e.getBoundingClientRect(); return { x: r.left + r.width / 2, y: r.top + r.height / 2, w: r.width, h: r.height }; }, sel);

    // ═══ 1. רכיבים מוכנים — 390 במגע ובמחשב ═══
    const tpl = n => fs.readFileSync(path.join(KIT, n, 'template.html'), 'utf8'), css = n => fs.readFileSync(path.join(KIT, n, 'style.css'), 'utf8');
    const KITS = ['social-icons', 'form', 'map', 'accordion', 'slides'];
    const html = '<!doctype html><html lang="he" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><style>body{margin:8px;font:16px/1.5 system-ui}' + KITS.map(css).join('\n') + '</style></head><body>' + KITS.map(n => '<section data-k="' + n + '">' + tpl(n) + '</section>').join('\n') + '</body></html>';
    const kitPage = async (w, h, touch) => {
      const ctx = await mk(w, h, touch), page = watch(await ctx.newPage(), 'kit-' + w);
      await page.setContent(html); await page.evaluate(() => { document.querySelectorAll('.kol-slides').forEach(s => { s.classList.add('is-ready'); const ds = s.querySelector('.kol-slides__dots'), d = ds && ds.querySelector('.kol-slides__dot'); for (let i = 2; d && i <= 3; i++) { const k = d.cloneNode(true); k.removeAttribute('aria-current'); ds.append(k); } });   /* הנקודות נבנות ב-JS — כאן שלוש, כמו שלוש שקופיות */ document.querySelectorAll('.kol-map').forEach(s => s.classList.add('is-ready')); });
      return { ctx, page };
    };
    {
      const { ctx, page } = await kitPage(390, 844, true);
      ok(await page.evaluate(() => matchMedia('(pointer: coarse)').matches), '390 מגע: (pointer: coarse) חל — הכללים של P2 נבדקים באמת');
      const T = [['.kol-social__link--facebook', 'אייקון רשת'], ['.kol-form__submit', 'כפתור "שליחה" בטופס'], ['.kol-map__open', '"פתח במפות ↗"'], ['.kol-acc__title', 'כותרת פריט באקורדיון']];
      for (const [sel, name] of T) {
        const p = await center(page, sel);
        if (!p) { ok(false, name + ' — לא נמצא ' + sel); continue; }
        await page.evaluate(q => { window.__hit = null; const e = document.querySelector(q); e.addEventListener('click', ev => { window.__hit = q; ev.preventDefault(); }, { once: true, capture: true }); }, sel);
        await touchTap(page, p);
        const hit = await page.evaluate(() => window.__hit);
        ok(p.h >= 44 && (sel === '.kol-map__open' || sel === '.kol-acc__title' || p.w >= 44) && hit === sel, '390 מגע: ' + name + ' — ' + Math.round(p.w) + '×' + Math.round(p.h) + ' (≥44), והקשה במרכז מפעילה אותו', { p, hit });
      }
      // נקודות המצגת: 12 בעין, אזור הקשה 44 — הקשה 18px מהמרכז עדיין על הנקודה, ולא על השכנה
      const dots = await page.evaluate(() => { document.querySelector('.kol-slides__dots').scrollIntoView({ block: 'center' }); return 0; }) || await page.evaluate(() => [...document.querySelectorAll('.kol-slides__dot')].map(d => { const r = d.getBoundingClientRect(); return { x: r.left + r.width / 2, y: r.top + r.height / 2, w: r.width }; }));
      if (dots.length >= 2) {
        await page.evaluate(() => { window.__dot = -1; document.querySelectorAll('.kol-slides__dot').forEach((d, i) => d.addEventListener('click', ev => { window.__dot = i; ev.preventDefault(); ev.stopPropagation(); }, true)); });
        const d = dots[1], gap = Math.abs(dots[1].x - dots[0].x);
        await touchTap(page, { x: d.x + (dots[0].x > d.x ? 18 : -18), y: d.y + 18 });
        const got = await page.evaluate(() => window.__dot);
        ok(Math.round(d.w) === 12 && gap >= 44 && got === 1, 'מצגת: נקודה 12px בעין, מרווח בין מרכזים ≥44, והקשה 18px מהמרכז (לכיוון השכנה) — על הנקודה הנכונה', { w: d.w, gap, got });
      } else ok(false, 'מצגת: אין נקודות', dots);
      ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), '390: בלי גלילה אופקית');
      await shot(page, 'p2-kit-390');
      await ctx.close();
    }
    {
      const { ctx, page } = await kitPage(1366, 900, false);
      const s = await page.evaluate(() => { const r = q => document.querySelector(q).getBoundingClientRect(), mb = q => getComputedStyle(document.querySelector(q)).minBlockSize; return { coarse: matchMedia('(pointer: coarse)').matches, social: Math.round(r('.kol-social__link').height), submit: mb('.kol-form__submit'), map: getComputedStyle(document.querySelector('.kol-map__open')).display, acc: mb('.kol-acc__title') }; });
      ok(!s.coarse && s.social === 40 && s.submit === 'auto' && s.map === 'inline' && /^(auto|0px)$/.test(s.acc), 'מחשב (עכבר): העיצוב לא השתנה — אייקון 40, כפתור הטופס והקישור בגודלם', s);
      await ctx.close();
    }

    // ═══ 2. עורך 1366 ═══
    {
      const ctx = await mk(1366, 900, false), page = watch(await ctx.newPage(), '1366');
      await openEd(page);
      // מחיקה עם אזהרה ← "בטל" ← רמז
      const del = await page.evaluate(async () => {
        const S = Editor._state, c = S.canvas, n = c.nodeOf(c.doc.querySelector('h1')), X0 = S.build.X;
        S.build.X = Object.assign({}, X0, { deps: () => [{ kind: 'id', text: 'section מפנה אל #t (aria-labelledby)' }] });
        c.select(n); const src = c.m.src; S.build.remove(); await new Promise(r => setTimeout(r, 400));
        const dlg = document.getElementById('sheet'), open = dlg.open, cancel = [...dlg.querySelectorAll('button')].find(b => b.textContent.trim() === 'בטל');
        if (cancel) cancel.click(); await new Promise(r => setTimeout(r, 400));
        S.build.X = X0;
        const nt = document.querySelector('.ed-note:not([hidden])');
        return { open, same: c.m.src === src, note: nt && nt.textContent };
      });
      ok(del.open && del.same && /המחיקה בוטלה/.test(del.note || ''), 'מחיקה עם אזהרה ← "בטל": לא נמחק, ונאמר "המחיקה בוטלה — לא השתנה דבר"', del);

      // מיקום חופשי על אלמנט שמכסה אחר
      await page.evaluate(() => { const S = Editor._state, c = S.canvas, i = c.m.src.indexOf('</h1>') + 5;
        Editor._act('P2 · זרע', () => ({ patches: [{ from: i, to: i, text: '\n    <p class="p2-under" style="margin:0">שורה שתכוסה</p>\n    <p class="p2-top" style="margin:-20px 0 0;padding:10px 0;background:#fde68a">כפתור שמכסה</p>' }] })); });
      await sleep(900);
      const pos = await page.evaluate(async () => {
        const S = Editor._state, c = S.canvas, n = c.nodeOf(c.doc.querySelector('.p2-top'));
        c.select(n); const okOn = S.pos.on(n); await new Promise(r => setTimeout(r, 500));
        const nt = document.querySelector('.ed-note:not([hidden])');
        Editor._tab('adv'); await new Promise(r => setTimeout(r, 400));
        const cov = document.querySelector('[data-covers]');
        return { okOn, note: nt && nt.textContent, panel: cov && cov.textContent };
      });
      ok(pos.okOn && /מכסה את p\.p2-under/.test(pos.note || '') && /📱/.test(pos.note || ''), 'מיקום חופשי: ההודעה אומרת "מכסה את p.p2-under" (ואיך להגיע אליו) ושהמיקום חל בכל המכשירים', pos);
      ok(/מכסה את p\.p2-under/.test(pos.panel || ''), 'מיקום חופשי: גם בפאנל המיקום ("מתקדם") — ⚠️ מכסה את …', pos.panel);
      await shot(page, 'p2-free-cover-1366');
      // אחרי "חזרה לזרימה" — אין אזהרה
      const off = await page.evaluate(async () => { const S = Editor._state, c = S.canvas; S.pos.off(c.selected); await new Promise(r => setTimeout(r, 500)); Editor._tab('adv'); await new Promise(r => setTimeout(r, 300)); return !document.querySelector('[data-covers]'); });
      ok(off, 'אחרי "חזרה לזרימה" — אין אזהרת כיסוי');

      // תוויות המופעים — שלושה רכיבים צמודים
      for (const k of ['social-icons', 'share-buttons', 'slides']) { await page.evaluate(k => { const c = Editor._state.canvas; c.select(c.nodeOf(c.doc.querySelector('h1'))); return Editor._state.kit.use(k); }, k); await sleep(1500); }
      await page.evaluate(() => Editor._state.canvas.frame.contentWindow.scrollTo(0, 0)); await sleep(600);
      const tags = await page.evaluate(() => [...document.querySelectorAll('.ed-comp-tag')].filter(b => b.offsetParent && b.textContent.trim()).map(b => { const r = b.getBoundingClientRect(); return { t: b.textContent.trim(), l: r.left, r: r.right, y: r.top, b: r.bottom }; }));
      const clash = []; for (let i = 0; i < tags.length; i++) for (let j = i + 1; j < tags.length; j++) { const a = tags[i], b = tags[j]; if (a.l < b.r - 1 && b.l < a.r - 1 && a.y < b.b - 1 && b.y < a.b - 1) clash.push([a.t, b.t]); }
      ok(tags.length >= 3 && !clash.length, 'תוויות המופעים (' + tags.length + ') — אף שתיים אינן חופפות', { tags: tags.map(t => t.t + '@' + Math.round(t.y)), clash });
      await shot(page, 'p2-tags-1366');

      // "שמור" של ה-webhook — כפתור, לא טקסט
      await page.evaluate(() => { const c = Editor._state.canvas; c.select(c.nodeOf(c.doc.querySelector('h1'))); return Editor._state.kit.use('form'); }); await sleep(1800);
      await page.evaluate(() => { const c = Editor._state.canvas, f = c.doc.querySelector('.kol-form'); c.select(c.nodeOf(f)); Editor._tab('content'); }); await sleep(800);
      const hb = await page.evaluate(() => { const b = document.querySelector('[data-kf="hook-save"]'); if (!b) return null; b.scrollIntoView({ block: 'center' }); const s = getComputedStyle(b); return { border: s.borderTopStyle, bw: s.borderTopWidth, bg: s.backgroundColor, h: Math.round(b.getBoundingClientRect().height) }; });
      ok(hb && hb.border === 'solid' && parseFloat(hb.bw) >= 1 && hb.bg !== 'rgba(0, 0, 0, 0)', '"שמור" של ה-webhook — עם מסגרת ורקע (נראה ככפתור)', hb);
      await shot(page, 'p2-hook-1366');
      await ctx.close();
    }

    // ═══ 3. עורך 390 — מגע CDP ═══
    {
      const ctx = await mk(390, 844, true), page = watch(await ctx.newPage(), '390');
      await openEd(page);
      const h1 = () => page.evaluate(() => { const c = Editor._state.canvas, e = c.doc.querySelector('h1'), r = e.getBoundingClientRect(), f = c.frame.getBoundingClientRect(); return { x: f.left + (r.left + r.width / 2) * c.scale, y: f.top + (r.top + r.height / 2) * c.scale }; });
      await page.evaluate(() => Editor._state.canvas.select(null)); await sleep(200);
      await touchTap(page, await h1()); await sleep(500);
      await touchTap(page, await h1()); await sleep(500);
      const up = await page.evaluate(() => { const n = document.querySelector('.ed-note:not([hidden])'); if (!n) return null; const s = n.querySelector('span'), b = n.querySelector('.btn'), R = x => { const r = x.getBoundingClientRect(); return [Math.round(r.left), Math.round(r.right), Math.round(r.width), Math.round(r.top)]; }; return { text: s.textContent, span: R(s), btn: b && R(b), note: R(n), sel: Editor._state.canvas.selected && Editor._state.canvas.label(Editor._state.canvas.selected), w: innerWidth }; });
      ok(up && /נבחר ההורה/.test(up.text) && up.span[2] >= 260 && up.btn && up.btn[0] >= 0 && up.btn[1] <= up.w && up.btn[3] > up.span[3], '390: הרמז "נבחר ההורה" — הטקסט ברוחב ' + (up && up.span[2]) + 'px, והכפתור "↩ חזרה" מתחתיו ובתוך המסך', up);
      await shot(page, 'p2-uplevel-390');
      await page.evaluate(() => { document.querySelector('.ed-note').hidden = true; });
      // E2E ⚪15: כפתורי "🖼 תמונת רקע" בסרגל — 44 (הסגנון בלבד: בעמוד הזה אין רובריקה עם תמונת רקע)
      const bgt = await page.evaluate(() => { const g = document.querySelector('[data-bg-tools]'); if (!g) return null; const was = g.hidden, par = g.parentElement; g.hidden = false; const bar = document.querySelector('.ed-bar'); if (!bar.contains(g)) bar.append(g); const hs = [...g.querySelectorAll('.btn')].map(b => Math.round(b.getBoundingClientRect().height)); g.hidden = was; if (par !== g.parentElement) par.append(g); return hs; });
      ok(bgt && bgt.length === 3 && bgt.every(x => x >= 44), '390: כפתורי "🖼 תמונת רקע" (החלף / הסר / הזז) בסרגל — ≥44', bgt);
      // 📄 ← ▶️
      const pg = await center(page, '[data-pages-btn]'); await touchTap(page, pg); await sleep(600);
      const lv = await page.evaluate(() => { const b = document.querySelector('[data-pg-pop] [data-pg-live]'); if (!b) return null; b.scrollIntoView({ block: 'nearest' }); const r = b.getBoundingClientRect(), x = r.left + r.width / 2, y = r.top + r.height / 2, at = document.elementFromPoint(x, y); return { h: Math.round(r.height), x, y, vis: r.width > 0, hit: !!at && (at === b || b.contains(at)), at: at && at.outerHTML.slice(0, 80), vh: innerHeight }; });
      ok(lv && lv.vis && lv.hit && lv.h >= 44, '390: 📄 ← "▶️ תצוגה חיה" בגיליון, ≥44', lv);
      await shot(page, 'p2-pages-390');
      if (lv) { await touchTap(page, lv); await sleep(1500); }
      ok(await page.isVisible('.pj-live iframe'), '390: 📄 ← ▶️ — התצוגה החיה נפתחת בשתי הקשות (במקום ארבע)');
      await ctx.close();
    }
    ok(!errors.length, 'אין שגיאות JavaScript בדף', errors);
  } catch (e) { failed++; console.log('❌ חריגה: ' + (e.stack || e.message)); }
  finally { if (browser) await browser.close(); php.kill(); }
  console.log('\n' + (failed ? '❌ נכשלו ' + failed + ' מתוך ' + ran : '✅ כל ' + ran + ' הבדיקות עברו'));
  process.exit(failed ? 1 : 0);
})();
