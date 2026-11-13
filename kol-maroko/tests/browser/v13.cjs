/**
 * פרוסה V13 — נוחות — בדיקות בדפדפן   node kol-maroko/tests/browser/v13.cjs
 *
 * על עמוד בדיקה (v13.html + css/v13.css) ועמוד שני (v13b.html + css/v13b.css):
 * תפריט ההקשר (לחיצה ימנית בקנבס וב-Navigator; כל פעולה, ושם הפעולה בהיסטוריה; מושבתת — עם הסיבה בריחוף) · Ctrl+E ("צל" ←
 * הקבוצה נפתחת ומודגשת; "letter-spacing" ← ריווח אותיות; "הוסף כותרת" ← הפעולה; שימוש אחרון בראש) · קיצורים (וגם — לא בתוך
 * שדה, חוץ מ-Ctrl+S) · העתק / הדבק סגנון (בסיס, מצב, מכשיר; מחלקה משותפת בשאלה; כמה אלמנטים; Alt + גרירה) ← השורות ←
 * Ctrl+Z אחד · אפס סגנון · היסטוריה (שמות, איחוד, קפיצה 3 אחורה, קוד + חזותי ב-Ctrl+Z אחד, ביטול בעורך הקוד, גרסאות, אחרי רענון) ·
 * תבנית: שמירה (4 קבצים) ← הוספה לעמוד אחר בגרירה (שינוי שם מחלקה מתנגשת, ה-CSS בבלוק עם הערה) ← ייצוא ZIP ← ייבוא (תצוגה ←
 * הוספה; התנגשות ← "יוחלף"; ZIP זדוני נדחה) · build_is_config · 1920 / 1366 (הלוחות מתקפלים, בלי גלילה) · 390 (לחיצה ארוכה).
 * צילומים — ל-tmpdir (kol-v13-shots).
 */
'use strict';
const { chromium } = require('playwright');
const { spawn, execFileSync } = require('child_process');
const fs = require('fs'), os = require('os'), path = require('path'), net = require('net');

const ROOT = path.resolve(__dirname, '..', '..');
const OWNER = { sub: 'u-owner', name: 'Owner Test', email: 'owner@example.test', email_verified: true, platform_admin: true };
let failed = 0, ran = 0;
let T0 = Date.now(); const ok = (cond, what, got) => { ran++; const dt = ((Date.now() - T0) / 1000).toFixed(1); T0 = Date.now(); if (cond) console.log('✅ [' + dt + 's] ' + what); else { failed++; console.log('❌ ' + what + (got !== undefined ? '\n   התקבל: ' + JSON.stringify(got).slice(0, 1400) : '')); } };
const freePort = () => new Promise(r => { const s = net.createServer().listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => r(p)); }); });
const sleep = ms => new Promise(r => setTimeout(r, ms));

(async () => {
  const data = fs.mkdtempSync(path.join(os.tmpdir(), 'kol-v13-'));
  execFileSync('php', [path.join(__dirname, 'f3-seed.php'), data, path.join(data, 'old-render.html')]);
  const port = await freePort(), base = `http://127.0.0.1:${port}`;
  const php = spawn('php', ['-S', `127.0.0.1:${port}`, path.join(ROOT, 'tests', 'router.php')], { cwd: ROOT, env: { ...process.env, KOL_TEST_DATA: data, PHP_CLI_SERVER_WORKERS: '4' }, stdio: 'ignore' });
  let browser;
  try {
    for (let i = 0; i < 100; i++) { try { await fetch(base + '/api.php?action=embed_key'); break; } catch { await sleep(50); } }
    browser = await chromium.launch();
    const mk = (width, height, user = OWNER) => browser.newContext({ viewport: { width, height }, hasTouch: width < 600, isMobile: width < 600, extraHTTPHeaders: { 'X-Test-User': JSON.stringify(user) } });
    const call = (page, action, body, query = '') => page.evaluate(([a, b, q]) => api(a, b ?? undefined, q), [action, body ?? null, query]);
    const ready = (page, p) => page.waitForFunction(q => Editor._state.file && Editor._state.file.path === q && Editor._state.canvas && Editor._state.canvas.m && !Editor._state.busy && Editor._state.build && Editor._state.comp && Editor._state.menu && Editor._state.hist, p, { timeout: 30000 });
    const flush = async page => { try { await page.evaluate(async () => { const S = Project._state; for (const [id, d] of S.docs) if (d.dirty) await Project._save(id); }); await page.waitForFunction(() => [...Project._state.docs.values()].every(d => !d.dirty && !d.saving), null, { timeout: 15000 }); } catch (e) { console.log('   ⚠️ flush: ' + e.message.slice(0, 200)); } };
    const openPage = async (page, p) => { if (page.url().includes('editor.php')) await flush(page); await page.goto(base + '/editor.php#/editor/' + encodeURIComponent(p)); await page.reload(); await ready(page, p); await sleep(500); };
    const text = (page, p) => page.evaluate(async p => { await Project.docs.reload(); const f = Project.docs.byPath(p); if (!f) return null; const d = await Project.docs.open(f.id); return d.text(); }, p);
    const exists = (page, p) => page.evaluate(async p => { await Project.docs.reload(); return !!Project.docs.byPath(p); }, p);
    const lastTx = page => page.evaluate(() => { const t = Editor._state.tx; return t.length ? t[t.length - 1].label : ''; });
    const txN = page => page.evaluate(() => Editor._state.tx.length);
    const select = (page, sel, i = 0) => page.evaluate(([s, i]) => { const c = Editor._state.canvas, e = c.doc.querySelectorAll(s)[i]; if (!e) return false; const n = c.nodeOf(e); c.select(n); c.reveal(n); return !!n; }, [sel, i]);
    const selTag = page => page.evaluate(() => { const c = Editor._state.canvas, n = c.selected; return n ? n.tag + (Editor._state.canvas.elOf(n)?.className ? '.' + Editor._state.canvas.elOf(n).className : '') : null; });
    const tab = async (page, id) => { await page.evaluate(id => { if (!document.querySelector('.ed').classList.contains('info-open')) document.querySelector('.ed-bar [data-panel="info"]').click(); Editor._tab(id); }, id); await sleep(250); };
    const undo = async page => { await page.evaluate(() => Editor._undo()); await sleep(300); };
    const redo = async page => { await page.evaluate(() => Editor._redo()); await sleep(300); };
    const css = page => text(page, 'css/v13.css');
    const at = (page, sel, i = 0, fx = 0.5, fy = 0.5) => page.evaluate(([s, i, fx, fy]) => { const c = Editor._state.canvas, e = c.doc.querySelectorAll(s)[i]; if (!e) return null; e.scrollIntoView({ block: 'center' }); const r = e.getBoundingClientRect(), f = c.frame.getBoundingClientRect(); return { x: f.left + (r.left + r.width * fx) * c.scale, y: f.top + (r.top + r.height * fy) * c.scale }; }, [sel, i, fx, fy]);
    const rclick = async (page, sel, i = 0) => { const p = await at(page, sel, i); await page.mouse.click(p.x, p.y, { button: 'right' }); try { await page.waitForSelector('.ed-ctx', { timeout: 5000 }); } catch (e) { console.log('   ⚠️ אין תפריט: ' + JSON.stringify(await page.evaluate(([x, y]) => ({ dlg: document.getElementById('sheet').open, el: document.elementFromPoint(x, y)?.className, alt: !!Editor._state.canvas.alt, brush: !!Editor._state.sc.brush, pal: !!Editor._state.palette.el }), [p.x, p.y]))); throw e; } await sleep(100); };
    const ctx = async (page, sel, id, i = 0) => { await rclick(page, sel, i); await page.click('.ed-ctx [data-ctx="' + id + '"]'); await sleep(450); };
    const closeSheet = async page => { await page.evaluate(() => { const d = document.getElementById('sheet'); if (d.open) d.close(); }); await sleep(100); };
    const PD = () => {
      const ed = Editor._state, c = ed.canvas;
      const inFrame = (x, y) => { const f = c.frame.getBoundingClientRect(); return x >= f.left && x <= f.right && y >= f.top && y <= f.bottom; };
      const fpt = (x, y) => { const f = c.frame.getBoundingClientRect(); return [(x - f.left) / c.scale, (y - f.top) / c.scale]; };
      const fire = (t, type, x, y, cx, cy, touch, alt) => t.dispatchEvent(new (t.ownerDocument.defaultView.PointerEvent)(type, { bubbles: true, cancelable: true, composed: true, clientX: cx, clientY: cy, pointerId: touch ? 2 : 1, pointerType: touch ? 'touch' : 'mouse', isPrimary: true, button: 0, buttons: type === 'pointerup' ? 0 : 1, altKey: !!alt }));
      window.__pd = {
        down(x, y, touch, shell, alt) { this.touch = !!touch; this.alt = !!alt; this.frame = inFrame(x, y) && !shell; if (this.frame) { const [fx, fy] = fpt(x, y); this.t = c.doc.elementFromPoint(fx, fy); fire(this.t, 'pointerdown', x, y, fx, fy, touch, alt); } else { this.t = document.elementFromPoint(x, y); fire(this.t, 'pointerdown', x, y, x, y, touch, alt); } },
        move(x, y) { if (this.frame && inFrame(x, y)) { const [fx, fy] = fpt(x, y); fire(c.doc.elementFromPoint(fx, fy) || c.doc.documentElement, 'pointermove', x, y, fx, fy, this.touch, this.alt); } else { const t = ed.build.drag.active ? (document.elementFromPoint(x, y) || document.body) : this.t; fire(t.localName === 'iframe' ? document.body : t, 'pointermove', x, y, x, y, this.touch, this.alt); } },
        up(x, y) { if (this.frame && inFrame(x, y)) { const [fx, fy] = fpt(x, y); fire(c.doc.elementFromPoint(fx, fy) || c.doc.documentElement, 'pointerup', x, y, fx, fy, this.touch, this.alt); } else { const t = ed.build.drag.active ? (document.elementFromPoint(x, y) || document.body) : this.t; fire(t.localName === 'iframe' ? document.body : t, 'pointerup', x, y, x, y, this.touch, this.alt); } },
      };
    };
    const drag = async (page, from, to, opt = {}) => {
      await page.evaluate(PD);
      await page.evaluate(([x, y, t, sh, alt]) => window.__pd.down(x, y, t, sh, alt), [from.x, from.y, !!opt.touch, !!opt.shell, !!opt.alt]);
      const steps = opt.steps || 10;
      for (let i = 1; i <= steps; i++) { await page.evaluate(([x, y]) => window.__pd.move(x, y), [from.x + (to.x - from.x) * i / steps, from.y + (to.y - from.y) * i / steps]); await sleep(24); }
      await sleep(80);
      await page.evaluate(([x, y]) => window.__pd.up(x, y), [to.x, to.y]); await sleep(400);
    };
    const shotDir = path.join(os.tmpdir(), 'kol-v13-shots'); fs.mkdirSync(shotDir, { recursive: true });
    const shot = (page, name) => page.screenshot({ path: path.join(shotDir, name + '.png') });

    const c1 = await mk(1920, 1080), page = await c1.newPage();
    page.on('pageerror', e => console.log('   ⚠️ שגיאה בדף אחרי בדיקה ' + ran + ': ' + e.message + ' ' + String(e.stack || '').split('\n').slice(1, 4).join(' | ').slice(0, 400)));
    page.on('dialog', d => (d.type() === 'beforeunload' ? d.accept() : d.dismiss()));
    await page.goto(base + '/#/project');
    await page.waitForSelector('.pj-tree .pj-row');
    await call(page, 'pf_migrate', {});
    await openPage(page, 'index.html');
    const MOB = await page.evaluate(() => Editor._state.styler.devs().filter(d => d.max).sort((a, b) => a.max - b.max)[0].max);
    const CSS0 = '.wrap { padding: 10px; }\n.t1 { color: red; font-size: 48px; }\n.t1:hover { color: blue; }\n@media (max-width: ' + MOB + 'px) {\n  .t1 { font-size: 30px; }\n}\n.t2 { color: green; }\n.btn { border: 1px solid #000; }\n.strip { background: #eee; padding: 8px; }\n.strip-title { color: purple; }\n.strip-text { font-size: 14px; }\n';
    const HTML0 = '<!doctype html>\n<html lang="he" dir="rtl">\n<head>\n  <meta charset="utf-8">\n  <title>V13</title>\n  <link rel="stylesheet" href="css/v13.css">\n</head>\n<body>\n  <main class="wrap">\n    <h1 class="t1">כותרת ראשית</h1>\n    <h2 class="t2">כותרת שנייה</h2>\n    <h2 class="t3" style="letter-spacing: 1px">כותרת שלישית</h2>\n    <section class="strip">\n      <h3 class="strip-title">פס</h3>\n      <p class="strip-text btn">טקסט</p>\n      <img src="media/dot.svg" alt="">\n    </section>\n    <p class="btn">כפתור א</p>\n    <p class="btn">כפתור ב</p>\n  </main>\n</body>\n</html>\n';
    await call(page, 'pf_create', { path: 'css/v13.css', content: CSS0 });
    await call(page, 'pf_create', { path: 'media/dot.svg', content: '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><circle cx="5" cy="5" r="5"/></svg>\n' });
    await call(page, 'pf_create', { path: 'v13.html', content: HTML0 });
    await call(page, 'pf_create', { path: 'css/v13b.css', content: '.box { padding: 4px; }\n.strip-title { color: orange; }\n' });
    await call(page, 'pf_create', { path: 'v13b.html', content: '<!doctype html>\n<html lang="he" dir="rtl">\n<head>\n  <meta charset="utf-8">\n  <title>B</title>\n  <link rel="stylesheet" href="css/v13b.css">\n</head>\n<body>\n  <div class="box">\n    <h1 class="strip-title">כותרת בצבע אחר</h1>\n    <p>טקסט</p>\n  </div>\n</body>\n</html>\n' });
    await openPage(page, 'v13.html');
    await page.click('[data-dev="desktop"]'); await sleep(300);

    // ═══ 1. תפריט ההקשר ═══
    await rclick(page, 'h1.t1');
    let st = await page.evaluate(() => { const m = document.querySelector('.ed-ctx'), r = m.getBoundingClientRect(); return { ids: [...m.querySelectorAll('[data-ctx]')].map(b => b.dataset.ctx), off: [...m.querySelectorAll('[data-ctx][aria-disabled="true"]')].map(b => b.dataset.ctx + ':' + (b.querySelector('.ed-ctx-why')?.textContent || '')), from: m.dataset.ctxFrom, fixed: getComputedStyle(m).position, r: [r.left, r.top, r.width] }; });
    const NEED = ['copy', 'paste-before', 'paste-after', 'paste-in', 'duplicate', 'delete', 'wrap-col', 'wrap-row', 'move-out', 'parent', 'similar', 'copy-style', 'paste-style', 'reset-style', 'save-component', 'save-template', 'open-code', 'bug'];
    ok(NEED.every(x => st.ids.includes(x)) && st.from === 'canvas' && st.fixed === 'fixed', 'לחיצה ימנית על כותרת בקנבס: תפריט צף עם כל הפעולות', st);
    ok(await selTag(page) === 'h1.t1', 'לחיצה ימנית בוחרת את האלמנט');
    ok(st.off.some(x => /^paste-after:.*הלוח של העורך ריק/.test(x)) && st.off.some(x => /^paste-in:/.test(x)) && st.off.some(x => /^paste-style:.*אין סגנון בלוח/.test(x)) && st.off.some(x => /^similar:.*אין עוד אלמנט/.test(x)), 'פעולה שאינה אפשרית — מושבתת, והסיבה כתובה', st.off);
    await page.hover('.ed-ctx [data-ctx="paste-after"]'); await sleep(100);
    ok(await page.evaluate(() => { const w = document.querySelector('.ed-ctx [data-why="paste-after"]'); return getComputedStyle(w).display !== 'none' && w.getBoundingClientRect().height > 0 && document.querySelector('.ed-ctx [data-ctx="paste-after"]').title.includes('ריק'); }), 'הסיבה מוצגת בריחוף (וגם ב-title)');
    await page.click('.ed-ctx [data-ctx="paste-after"]', { force: true }); await sleep(200);
    ok(await page.evaluate(() => !!document.querySelector('.ed-ctx') && /הלוח של העורך ריק/.test(document.querySelector('.ed-note').textContent)), 'לחיצה על מושבתת — ההודעה עם הסיבה, התפריט נשאר');
    await page.keyboard.press('Escape'); await sleep(100);
    ok(await page.evaluate(() => !document.querySelector('.ed-ctx')), 'Esc סוגר את התפריט');
    // Navigator
    await page.evaluate(() => { if (!document.querySelector('.ed').classList.contains('nav-open')) document.querySelector('.ed-bar [data-panel="nav"]').click(); }); await sleep(200);
    await page.evaluate(() => { const S = Editor._state; S.nav.refresh(); S.nav.sync(); });
    const row = await page.evaluate(() => { const r = [...document.querySelectorAll('.nv-row')].find(x => x.textContent.includes('t2')); if (!r) return null; r.scrollIntoView({ block: 'center' }); const b = r.getBoundingClientRect(); return { x: b.left + b.width / 2, y: b.top + b.height / 2 }; });
    await page.mouse.click(row.x, row.y, { button: 'right' }); await page.waitForSelector('.ed-ctx');
    ok(await page.evaluate(() => document.querySelector('.ed-ctx').dataset.ctxFrom === 'nav') && await selTag(page) === 'h2.t2', 'לחיצה ימנית על שורה ב-Navigator: אותו תפריט, על האלמנט שלה');
    await page.keyboard.press('Escape');

    // כל פעולה — והשם בהיסטוריה
    const n0 = await txN(page);
    await ctx(page, 'h1.t1', 'copy');
    ok(await page.evaluate(() => /class="t1"/.test(Editor._state.build.clip?.html || '')), 'העתק — האלמנט בלוח של העורך');
    await ctx(page, 'h2.t2', 'paste-before');
    let h = await text(page, 'v13.html');
    ok((h.match(/class="t1"/g) || []).length === 2 && h.indexOf('class="t1"', h.indexOf('class="t1"') + 1) < h.indexOf('class="t2"') && /הדבקה · h1 · לפני/.test(await lastTx(page)), 'הדבק לפני — לפני t2, ושם בהיסטוריה', await lastTx(page));
    await undo(page);
    await ctx(page, 'h2.t2', 'paste-after');
    h = await text(page, 'v13.html');
    ok(h.indexOf('class="t1"', h.indexOf('class="t1"') + 1) > h.indexOf('class="t2"') && /^הדבקה/.test(await lastTx(page)), 'הדבק אחרי', await lastTx(page));
    await undo(page);
    await ctx(page, 'section.strip', 'paste-in'); await sleep(200);
    h = await text(page, 'v13.html');
    ok(/<section class="strip">[\s\S]*class="t1"[\s\S]*<\/section>/.test(h) && /^הדבקה/.test(await lastTx(page)), 'הדבק בתוך — בסוף הרובריקה', h.slice(h.indexOf('<section'), h.indexOf('</section>') + 10));
    await undo(page);
    await ctx(page, 'h2.t2', 'duplicate');
    ok(((await text(page, 'v13.html')).match(/class="t2"/g) || []).length === 2 && /^שכפול · /.test(await lastTx(page)), 'שכפל', await lastTx(page));
    await undo(page);
    await ctx(page, 'p.btn', 'delete', 2);
    ok(((await text(page, 'v13.html')).match(/כפתור ב/g) || []).length === 0 && /^מחיקת /.test(await lastTx(page)), 'מחק', await lastTx(page));
    await undo(page);
    await ctx(page, 'h2.t2', 'wrap-col');
    ok(/<div>\s*<h2 class="t2">/.test(await text(page, 'v13.html')) && /^עטיפה ברובריקה · /.test(await lastTx(page)), 'עטוף ברובריקה ↕', await lastTx(page));
    await undo(page);
    const nW = await txN(page);
    await ctx(page, 'h2.t2', 'wrap-row'); await sleep(600);
    st = { h: await text(page, 'v13.html'), css: await css(page), label: await lastTx(page), n: await txN(page) };
    ok(/<div class="[\w-]+">\s*<h2 class="t2">/.test(st.h) && /display: flex/.test(st.css) && /flex-direction: row|display: flex/.test(st.css) && /^עטיפה ברובריקה ↔/.test(st.label) && st.n === nW + 1, 'עטוף ברובריקה ↔ — HTML + כיוון שורה, עסקה אחת', st);
    await undo(page);
    ok((await text(page, 'v13.html')) === HTML0 && (await css(page)) === CSS0, 'Ctrl+Z אחד — העטיפה ↔ בוטלה כולה (HTML ו-CSS)');
    await ctx(page, 'h3.strip-title', 'move-out');
    h = await text(page, 'v13.html');
    ok(h.indexOf('class="strip-title"') < h.indexOf('<section class="strip">') && /^הזזה · .* · הוצא/.test(await lastTx(page)), 'הוצא מהרובריקה — לפני הרובריקה', await lastTx(page));
    await undo(page);
    await ctx(page, 'h3.strip-title', 'parent');
    ok(await selTag(page) === 'section.strip', 'בחר הורה');
    await ctx(page, 'p.btn', 'similar');
    ok(await page.evaluate(() => Editor._state.canvas.picked().length) === 3, 'בחר את כל הדומים — 3 אלמנטים עם .btn');
    await select(page, 'h2.t2');
    ok(await page.evaluate(() => Editor._state.canvas.picked().length) === 1, 'בחירה אחרת — הבחירה המרובה מתבטלת');
    // שמור כרכיב / כתבנית / פתח בקוד / 🐞 — פותחים את החלון שלהם
    await ctx(page, 'section.strip', 'save-component');
    ok(await page.evaluate(() => document.getElementById('sheet').open && !!document.querySelector('[data-cs="name"]')), 'שמור כרכיב — חלון "שמור כרכיב" (V12)');
    await closeSheet(page);
    await ctx(page, 'section.strip', 'save-template');
    ok(await page.evaluate(() => document.getElementById('sheet').open && !!document.querySelector('[data-ts="name"]')), 'שמור כתבנית — החלון שלה');
    await closeSheet(page);
    await page.evaluate(() => { window.__oa = null; window.__oaWas = Project.openAt; Project.openAt = (...a) => { window.__oa = a; }; });
    await ctx(page, 'h2.t2', 'open-code');
    ok(await page.evaluate(() => window.__oa && window.__oa[0] === 'v13.html' && window.__oa[2] && window.__oa[2].from > 0), 'פתח בקוד — הקובץ, על הטווח של האלמנט', await page.evaluate(() => window.__oa));
    await page.evaluate(() => { Project.openAt = window.__oaWas; });
    await ctx(page, 'h2.t2', 'bug');
    ok(await page.evaluate(() => document.getElementById('sheet').open && !!document.querySelector('[data-bug-send]')), '🐞 לא הצלחתי לשנות את זה — חלון הדיווח');
    await closeSheet(page);
    // html — מושבתות עם סיבה
    await page.evaluate(() => { const S = Editor._state, c = S.canvas; S.menu.open(c.m.root.kids.find(k => k.tag === 'html') || c.m.root, 300, 300); }); await sleep(100);
    st = await page.evaluate(() => [...document.querySelectorAll('.ed-ctx [aria-disabled="true"]')].map(b => b.dataset.ctx));
    ok(['copy', 'duplicate', 'delete', 'wrap-col', 'save-component'].every(x => st.includes(x)), 'על <html>: העתק / שכפל / מחק / עטוף / רכיב — מושבתות', st);
    await page.keyboard.press('Escape');

    // ═══ 2. קיצורים ═══
    await select(page, 'h2.t2'); await page.evaluate(() => document.activeElement?.blur?.());
    await page.keyboard.press('Control+d'); await sleep(400);
    ok(/^שכפול/.test(await lastTx(page)), 'Ctrl+D — שכפול');
    await page.keyboard.press('Control+z'); await sleep(300);
    ok((await text(page, 'v13.html')) === HTML0, 'Ctrl+Z — ביטול');
    await page.keyboard.press('Control+Shift+z'); await sleep(300);
    ok(/^שכפול/.test(await lastTx(page)), 'Ctrl+Shift+Z — חזור');
    await page.keyboard.press('Control+z'); await sleep(300);
    await select(page, 'h2.t2'); await page.keyboard.press('ArrowLeft'); await sleep(150);
    const sib = await selTag(page); await page.keyboard.press('ArrowRight'); await sleep(150);
    ok(/^h[12]\.t[13]$/.test(sib) && await selTag(page) === 'h2.t2', 'חיצים — האח הקודם / הבא', sib);
    await page.keyboard.press('Escape'); await sleep(100);
    ok(await selTag(page) === 'main.wrap', 'Esc — ההורה');
    await page.keyboard.press('Escape'); await sleep(100); await page.keyboard.press('Escape'); await sleep(100);
    ok(await selTag(page) === null, 'Esc בראש העץ — ביטול הבחירה');
    await page.evaluate(() => { window.__wo = null; window.open = (...a) => { window.__wo = a; return null; }; });
    await page.keyboard.press('Control+p'); await sleep(600);
    ok(await page.evaluate(() => window.__wo && /live\.php\?path=v13\.html/.test(window.__wo[0])), 'Ctrl+P — ▶️ תצוגה חיה של העמוד', await page.evaluate(() => window.__wo));
    await page.keyboard.press('Shift+?'); await sleep(200);
    ok(await page.evaluate(() => document.getElementById('sheet').open && /Ctrl\+E/.test(document.querySelector('.ed-keys').textContent) && /Ctrl\+Shift\+C/.test(document.querySelector('.ed-keys').textContent)), '? — רשימת הקיצורים (כולל Ctrl+E ו-Ctrl+Shift+C)');
    await closeSheet(page);
    // לא בתוך שדה — חוץ מ-Ctrl+S
    await select(page, 'h2.t2'); await tab(page, 'design');
    await page.focus('[data-field="font-size"] input'); await page.keyboard.press('End'); await page.keyboard.press('Delete'); await page.keyboard.press('Control+e'); await sleep(200);
    ok(!(await page.evaluate(() => !!document.querySelector('[data-palette]'))) && (await text(page, 'v13.html')) === HTML0, 'בתוך שדה — Delete ו-Ctrl+E שייכים לשדה (לא נמחק, אין חיפוש)');
    await page.evaluate(async () => { const f = Project.docs.byPath('v13.html'), d = await Project.docs.open(f.id); d.edit([{ from: d.text().length, to: d.text().length, text: '\n' }], 'סימון'); });
    await page.keyboard.press('Control+s'); await page.waitForFunction(() => [...Project._state.docs.values()].every(d => !d.dirty && !d.saving), null, { timeout: 10000 }).catch(() => {});
    ok(await page.evaluate(() => /נשמר/.test(document.querySelector('.ed-note').textContent) && [...Project._state.docs.values()].every(d => !d.dirty)), 'Ctrl+S — שומר גם מתוך שדה');
    await undo(page); await flush(page);

    // ═══ 3. Ctrl+E ═══
    await select(page, 'h1.t1'); await page.evaluate(() => document.activeElement?.blur?.());
    await page.keyboard.press('Control+e'); await page.waitForSelector('[data-palette-q]');
    await page.keyboard.type('צל'); await sleep(150);
    st = await page.evaluate(() => [...document.querySelectorAll('[data-pl]')].slice(0, 3).map(b => b.dataset.pl + '|' + b.textContent));
    ok(/^c:design:shadow/.test(st[0]), '"צל" — הראשון: קבוצת הצל של הפאנל', st);
    await page.keyboard.press('Enter'); await sleep(700);
    st = await page.evaluate(() => { const d = document.querySelector('.ed-side-body.sg:not([hidden]) details[data-sec="shadow"]'); return { open: d && d.open, flash: d && (d.classList.contains('ed-flash') || !!d.querySelector('.ed-flash')), tab: Editor._state.tab, vis: d ? d.getBoundingClientRect().top < innerHeight : false, kids: d ? d.children.length : 0 }; });
    ok(st.open && st.flash && st.tab === 'design' && st.vis && st.kids > 1, 'קבוצת הצל של האלמנט הנבחר נפתחה, מודגשת וגלויה', st);
    await page.evaluate(() => { const d = document.querySelector('.ed-side-body.sg:not([hidden]) details[data-sec="shadow"]'); if (d) d.open = false; }); await sleep(150);   // ידיות הצל על הקנבס — סוגרים
    await page.evaluate(() => document.activeElement?.blur?.());
    await page.keyboard.press('Control+e'); await page.waitForSelector('[data-palette-q]'); await page.keyboard.type('letter-spacing'); await sleep(150);
    st = await page.evaluate(() => document.querySelector('[data-pl]').textContent);
    ok(/ריווח אותיות/.test(st), '"letter-spacing" ← ריווח אותיות', st);
    await page.keyboard.press('Enter'); await sleep(600);
    ok(await page.evaluate(() => { const f = document.querySelector('.ed-side-body.sg:not([hidden]) [data-field="letter-spacing"]'); return !!f && f.classList.contains('ed-flash') && f.contains(document.activeElement); }), 'השדה "ריווח אותיות" מודגש, והמיקוד בו');
    await page.evaluate(() => document.activeElement?.blur?.());
    const nH = await txN(page);
    await page.keyboard.press('Control+e'); await page.waitForSelector('[data-palette-q]'); await page.keyboard.type('הוסף כותרת'); await sleep(150);
    ok(/g:add-heading/.test(await page.evaluate(() => document.querySelector('[data-pl]').dataset.pl)), '"הוסף כותרת" ← הפעולה');
    await page.keyboard.press('Enter'); await sleep(700); await page.keyboard.press('Escape'); await sleep(300);
    ok(await txN(page) === nH + 1 && /^הוספה · כותרת/.test(await lastTx(page)), 'הפעולה רצה — נוספה כותרת, ושמה בהיסטוריה', await lastTx(page));
    await undo(page); await page.evaluate(() => document.activeElement?.blur?.());
    await page.keyboard.press('Control+e'); await page.waitForSelector('[data-palette-q]'); await sleep(100);
    st = await page.evaluate(() => [...document.querySelectorAll('[data-pl]')].slice(0, 3).map(b => b.dataset.pl));
    ok(st[0] === 'g:add-heading' && st[1] === 'c:design::letter-spacing' || (st[0] === 'g:add-heading' && st.includes('c:design:type:letter-spacing')), 'בלי חיפוש — השימוש האחרון בראש', st);
    await page.keyboard.press('Escape'); await sleep(100);
    ok(!(await page.evaluate(() => !!document.querySelector('[data-palette]'))), 'Esc סוגר את החיפוש');

    // ═══ 4. העתק / הדבק / אפס סגנון ═══
    await select(page, 'h1.t1'); await page.evaluate(() => document.activeElement?.blur?.());
    await page.keyboard.press('Control+Shift+c'); await sleep(200);
    ok(await page.evaluate(() => { const x = Editor._state.sc.clip; return x && x.own && x.own.sel === '.t1' && x.own.items.length === 3; }), 'Ctrl+Shift+C: הכלל של .t1 — בסיס, :hover ו-@media של הטלפון');
    await rclick(page, 'h2.t2');
    ok(await page.evaluate(() => !document.querySelector('.ed-ctx [data-ctx="paste-style"]').hasAttribute('aria-disabled')), '"הדבק סגנון" — פעיל כשיש סגנון בלוח');
    await page.click('.ed-ctx [data-ctx="paste-style"]'); await page.waitForSelector('#sheet[open] [data-scp-go]');
    st = await page.evaluate(() => ({ sum: document.querySelector('[data-scp-sum]').textContent, lines: document.querySelector('[data-scp-lines]').textContent }));
    ok(/ייכתבו 4 הצהרות ל-1 אלמנט/.test(st.sum) && /color: red;/.test(st.lines) && /font-size: 48px/.test(st.lines) && /:hover/.test(st.lines) && new RegExp('max-width: ' + MOB).test(st.lines) && /→ \.t2/.test(st.lines), 'תצוגה מקדימה: "ייכתבו 4 הצהרות ל-1 אלמנט", השורות לכל מכשיר ומצב, ולאן (.t2)', st);
    const nP = await txN(page);
    await page.click('[data-scp-go]'); await sleep(700);
    let c2 = await css(page);
    ok(/\.t2 \{ color: red; font-size: 48px; \}/.test(c2) && /\.t2:hover \{\s*color: blue;?\s*\}/.test(c2) && new RegExp('@media \\(max-width: ' + MOB + 'px\\) \\{[\\s\\S]*\\.t2 \\{\\s*font-size: 30px;?\\s*\\}').test(c2) && await txN(page) === nP + 1 && /^הדבק סגנון · h2\.t2 ← h1\.t1 · 4 הצהרות/.test(await lastTx(page)), 'הודבק: .t2 (בסיס), .t2:hover, ו-.t2 ב-@media — עסקה אחת עם שם', { css: c2, label: await lastTx(page) });
    const dbg = await page.evaluate(() => Editor._state.tx.slice(-3).map(t => t.label + ' / ' + t.docs.length + ' / ' + (t.code ? 'code' : '')));
    await undo(page);
    ok((await css(page)) === CSS0, 'Ctrl+Z אחד — הקובץ חוזר בייט בבייט', { dbg, css: await css(page) });
    // מחלקה משותפת + כמה אלמנטים
    await select(page, 'p.strip-text'); await page.evaluate(() => Editor._state.sc.copy());
    await page.evaluate(() => { const c = Editor._state.canvas; c.select(c.nodeOf(c.doc.querySelector('h2.t2'))); c.toggleMulti(c.nodeOf(c.doc.querySelector('h2.t3'))); Editor._state.sc.paste(); }); await page.waitForSelector('#sheet[open] [data-scp-go]');
    st = await page.evaluate(() => ({ sum: document.querySelector('[data-scp-sum]').textContent, sh: [...document.querySelectorAll('[data-scp-shared]')].map(x => x.dataset.scpShared) }));
    ok(/ל-2 אלמנטים/.test(st.sum) && /ייכתבו 2 הצהרות/.test(st.sum) && st.sh.includes('.btn'), 'כמה אלמנטים: "ייכתבו 2 הצהרות ל-2 אלמנטים"; המחלקה .btn משותפת — בשאלה', st);
    await page.check('[data-scp-shared=".btn"]'); await sleep(100);
    ok(/ייכתבו 4 הצהרות ל-2 אלמנטים/.test(await page.evaluate(() => document.querySelector('[data-scp-sum]').textContent)) && /border: 1px solid #000/.test(await page.evaluate(() => document.querySelector('[data-scp-lines]').textContent)), '"להעתיק גם אותה?" — כן: גם border, והספירה מתעדכנת');
    await page.click('[data-scp-go]'); await sleep(800);
    c2 = await css(page);
    ok(/\.t2 \{ color: green; font-size: 14px; border: 1px solid #000;? ?\}/.test(c2) && /\.t3 \{[^}]*font-size: 14px[^}]*border: 1px solid #000/.test(c2) && /הדבק סגנון · 2 אלמנטים/.test(await lastTx(page)), 'נכתב לכלל של כל אחד (.t2 קיים, .t3 — חדש)', { css: c2, l: await lastTx(page) });
    await undo(page);
    ok((await css(page)) === CSS0 && (await text(page, 'v13.html')) === HTML0, 'Ctrl+Z אחד — שני האלמנטים');
    // Alt + גרירה
    const pa = await at(page, 'h1.t1'), pb = await at(page, 'h2.t3');
    await drag(page, pa, pb, { alt: true, steps: 12 });
    st = await page.evaluate(() => ({ open: document.getElementById('sheet').open, sum: document.querySelector('[data-scp-sum]')?.textContent || '', title: document.getElementById('sheet-title').textContent }));
    ok(st.open && /מ-h1\.t1/.test(st.title) && /ל-1 אלמנט/.test(st.sum) && await selTag(page) === 'h2.t3', 'Alt + גרירה מכותרת לכותרת — אותה תצוגה מקדימה', st);
    await page.click('[data-scp-go]'); await sleep(700);
    ok(/\.t3 \{[^}]*color: red[^}]*font-size: 48px/.test(await css(page)), 'שחרור ← אישור ← הסגנון של .t1 על .t3');
    // אפס סגנון
    await ctx(page, 'h2.t3', 'reset-style'); await page.waitForSelector('#sheet[open] [data-reset-go]');
    st = await page.evaluate(() => ({ sum: document.querySelector('[data-reset-sum]').dataset.resetSum, lines: document.querySelector('[data-reset-lines]').textContent }));
    ok(+st.sum >= 5 && /\.t3 \{/.test(st.lines) && /\.t3:hover/.test(st.lines) && /style="letter-spacing: 1px"/.test(st.lines), 'אפס סגנון — רשימה של מה יימחק (הכלל, המצב, המכשיר, style="")', st);
    await page.click('[data-reset-go]'); await sleep(600);
    st = { css: await css(page), h: await text(page, 'v13.html') };
    ok(!/\.t3/.test(st.css) && !/style="letter-spacing/.test(st.h) && /\.btn \{/.test(st.css) && /^אפס סגנון · h2\.t3/.test(await lastTx(page)), 'נמחק — רק מה שנכתב לאלמנט (.btn המשותפת נשארת)', st.css);
    await undo(page);
    ok(/style="letter-spacing: 1px"/.test(await text(page, 'v13.html')) && /\.t3 \{/.test(await css(page)), 'Ctrl+Z — האיפוס בוטל');
    await undo(page);
    ok((await css(page)) === CSS0, 'ועוד Ctrl+Z — ההדבקה בגרירה בוטלה');

    // ═══ 5. היסטוריה ═══
    await page.evaluate(() => { document.querySelector('[data-ntab="hist"]').click(); }); await sleep(200);
    await select(page, 'h1.t1'); await tab(page, 'design');
    const setNum = async (prop, v) => { await page.fill('.ed-side-body.sg:not([hidden]) [data-field="' + prop + '"] input', v); await page.press('.ed-side-body.sg:not([hidden]) [data-field="' + prop + '"] input', 'Enter'); await sleep(450); };
    const nS = await txN(page);
    await setNum('font-size', '50px'); await setNum('font-size', '52px'); await setNum('font-size', '56px');
    st = await page.evaluate(() => ({ n: Editor._state.tx.length, l: Editor._state.tx[Editor._state.tx.length - 1].label }));
    ok(st.n === nS + 1 && /גודל.* · h1\.t1 · 48px ← 56px$/.test(st.l), 'שלושה שינויים רצופים באותו ערך — שורה אחת "48px ← 56px"', st);
    await undo(page);
    ok(/\.t1 \{ color: red; font-size: 48px; \}/.test(await css(page)), 'Ctrl+Z על השורה המאוחדת — חזרה ל-48px');
    await redo(page);
    // גרירה
    await select(page, 'h2.t3');
    const g3 = await at(page, 'h2.t3'), g2 = await at(page, 'h2.t2', 0, 0.5, 0.1);
    await drag(page, g3, g2, { steps: 14 });
    st = await lastTx(page);
    ok(/^גרירה · h2\.t3 · אל לפני h2\.t2/.test(st), 'גרירה — "גרירה · h2.t3 · אל לפני h2.t2"', st);
    // קוד: עורך הקוד של אותו קובץ (מבט מפוצל) — נכנס לאותה רשימה
    await page.evaluate(() => document.querySelector('[data-split]').click()); await page.waitForFunction(() => Editor._state.code && Editor._state.code.ed, null, { timeout: 20000 }); await sleep(400);
    await page.evaluate(() => { const ed = Editor._state.code.ed, m = ed.getModel(), p = m.getPositionAt(m.getValue().indexOf('</main>')); ed.setPosition(p); ed.focus(); ed.trigger('keyboard', 'type', { text: '<p class="from-code">מהקוד</p>\n  ' }); }); await sleep(300);
    st = await page.evaluate(() => Editor._state.tx[Editor._state.tx.length - 1]);
    ok(st && st.code && /^קוד · v13\.html · (שורה אחת|2 שורות)$/.test(st.label), 'הקלדה בעורך הקוד — "קוד · v13.html · …" באותה רשימה', st && st.label);
    await page.evaluate(() => { const ed = Editor._state.code.ed; ed.trigger('keyboard', 'type', { text: 'x' }); }); await sleep(200);
    ok(await page.evaluate(() => Editor._state.tx.filter(t => t.code).length) === 1, 'הקלדה רצופה — שורה אחת');
    // CSS בעורך הקוד (המסמך המשותף של css/v13.css)
    await page.evaluate(async () => { const f = Project.docs.byPath('css/v13.css'); const m = Project._state.docs.get(f.id).model; m.pushEditOperations(null, [{ range: new monaco.Range(1, 1, 1, 1), text: '/* מהקוד */\n' }], () => null); }); await sleep(300);
    ok(/^קוד · css\/v13\.css · /.test(await lastTx(page)), 'עריכה בקובץ ה-CSS של העמוד בעורך הקוד — "קוד · css/v13.css"', await lastTx(page));
    await page.evaluate(() => document.activeElement?.blur?.()); await page.mouse.click(5, 5);
    // Ctrl+Z בקנבס: הקוד, ואז הקוד של העמוד, ואז הגרירה
    await page.evaluate(() => Editor._state.canvas.frame.focus());
    await undo(page);
    ok(!/מהקוד \*\//.test(await css(page)), 'Ctrl+Z בקנבס — מבטל את עריכת ה-CSS שבקוד');
    await undo(page);
    ok(!/from-code/.test(await text(page, 'v13.html')) && await page.evaluate(() => !Editor._state.canvas.doc.querySelector('.from-code')), 'ועוד Ctrl+Z — מבטל את ההקלדה ב-HTML (גם בקנבס)');
    await undo(page);
    h = await text(page, 'v13.html');
    ok(h.indexOf('class="t2"') < h.indexOf('class="t3"'), 'ועוד Ctrl+Z — הגרירה בוטלה (חזותי וקוד באותה היסטוריה)');
    await redo(page); await redo(page);
    ok(/from-code/.test(await text(page, 'v13.html')), 'Ctrl+Y — הגרירה וההקלדה חזרו');
    await redo(page);
    ok(/מהקוד \*\//.test(await css(page)), 'Ctrl+Y — ועריכת ה-CSS');
    // ביטול בעורך הקוד של פעולה חזותית
    await select(page, 'h2.t2'); await page.evaluate(() => Editor._state.build.duplicate()); await sleep(400);
    const nD = await txN(page);
    await page.evaluate(() => { const ed = Editor._state.code.ed; ed.focus(); ed.trigger('keyboard', 'undo', null); }); await sleep(400);
    st = await page.evaluate(() => ({ n: Editor._state.tx.length, r: Editor._state.rtx.length && Editor._state.rtx[Editor._state.rtx.length - 1].label }));
    ok(st.n === nD - 1 && /^שכפול/.test(st.r) && ((await text(page, 'v13.html')).match(/class="t2"/g) || []).length === 1, 'Ctrl+Z בעורך הקוד — מבטל את השכפול, והצעד עובר ל"חזור" ברשימה', st);
    await page.evaluate(() => document.querySelector('[data-split]').click()); await sleep(300);
    // הלוח: שמות וקפיצה
    await page.evaluate(() => { document.querySelector('[data-ntab="hist"]').click(); }); await sleep(200);
    st = await page.evaluate(() => [...document.querySelectorAll('[data-hist-list] .hs-row')].map(r => r.className.replace('hs-row ', '') + '|' + r.textContent));
    ok(st.some(x => /גודל.*48px ← 56px/.test(x)) && st.some(x => /גרירה · h2\.t3/.test(x)) && st.some(x => /קוד · v13\.html/.test(x)) && st.some(x => /קוד · css\/v13\.css/.test(x)) && st.some(x => /^undone\|.*שכפול/.test(x)) && st.some(x => /^cur\|/.test(x)), 'הלוח: כל צעד בשמו; הנוכחי מסומן; מה שבוטל — מעומעם', st);
    const before3 = await page.evaluate(() => Editor._state.tx.length);
    await page.evaluate(() => { const rows = [...document.querySelectorAll('[data-hist-list] .hs-row.done, [data-hist-list] .hs-row.cur')]; rows[rows.length - 4].click(); }); await sleep(800);
    st = await page.evaluate(() => ({ n: Editor._state.tx.length, r: Editor._state.rtx.length, und: document.querySelectorAll('[data-hist-list] .hs-row.undone').length }));
    ok(st.n === before3 - 3 && st.r === 4 && st.und === 4, 'לחיצה על צעד — שלושה צעדים אחורה; הם (והשכפול) נשארו ב"חזור"', st);
    ok(!/from-code/.test(await text(page, 'v13.html')) && !/מהקוד/.test(await css(page)), 'הקבצים חזרו לנקודה (בלי ההקלדה ובלי ה-CSS)');
    await page.evaluate(() => { const rows = [...document.querySelectorAll('[data-hist-list] .hs-row.undone')]; rows[2].click(); }); await sleep(800);
    ok(await txN(page) === before3 && /מהקוד/.test(await css(page)), 'לחיצה על צעד שבוטל — חזרה קדימה עד אליו');
    await shot(page, 'v13-1920-history');
    // גרסאות (F2) — בתחתית הלוח
    await flush(page);
    await page.click('[data-hist-vers-load]'); await page.waitForSelector('[data-vers-file="v13.html"] [data-rev]', { timeout: 10000 });
    st = await page.evaluate(() => [...document.querySelectorAll('[data-vers-file="v13.html"] [data-rev]')].map(x => x.dataset.rev));
    ok(st.length >= 2, 'גרסאות: היסטוריית הקבצים של F2 בשרת, לעמוד ולקובצי ה-CSS', st);
    await page.locator('[data-vers-file="v13.html"] [data-ver-open]').last().click(); await page.waitForSelector('#sheet[open] .pj-diffv', { timeout: 10000 });
    ok(await page.evaluate(() => /גרסה \d+ · v13\.html/.test(document.getElementById('sheet-title').textContent)), '"👁 פתח" — השוואה של גרסה ישנה מול מה שבעורך');
    const nV = await txN(page);
    await page.click('#sheet [data-ver-back-go]'); await sleep(700);
    ok(await txN(page) === nV + 1 && /^חזרה לגרסה \d+ · v13\.html/.test(await lastTx(page)), '"↩️ חזור לגרסה" — עסקה אחת בעורך (Ctrl+Z מבטל)', await lastTx(page));
    await undo(page);
    // אחרי רענון
    await flush(page);
    await openPage(page, 'v13.html');
    await page.evaluate(() => { if (!document.querySelector('.ed').classList.contains('nav-open')) document.querySelector('.ed-bar [data-panel="nav"]').click(); document.querySelector('[data-ntab="hist"]').click(); }); await sleep(300);
    st = await page.evaluate(() => ({ n: Editor._state.tx.length, rows: document.querySelectorAll('[data-hist-list] .hs-row').length, hint: document.querySelector('.ed-histbody .hint').textContent }));
    ok(st.n === 0 && st.rows === 1 && /הגרסאות השמורות למטה/.test(st.hint), 'אחרי רענון — ההיסטוריה בזיכרון ריקה, ונאמר "הגרסאות השמורות — למטה"', st);
    await page.click('[data-hist-vers-load]'); await page.waitForSelector('[data-vers-file="v13.html"] [data-rev]');
    ok(await page.evaluate(() => document.querySelectorAll('[data-vers-file="v13.html"] [data-rev]').length >= 2), 'והגרסאות של F2 — נשארו');
    // מחזירים את העמוד למקור לחלק הבא
    await page.evaluate(async ([h, c]) => { for (const [p, t] of [['v13.html', h], ['css/v13.css', c]]) { const f = Project.docs.byPath(p), d = await Project.docs.open(f.id); if (d.text() !== t) d.edit([{ from: 0, to: d.text().length, text: t }], 'איפוס לבדיקה'); } }, [HTML0, CSS0]); await flush(page);
    await openPage(page, 'v13.html');

    // ═══ 6. תבניות ═══
    await ctx(page, 'section.strip', 'save-template');
    st = await page.evaluate(() => ({ name: document.querySelector('[data-ts="name"]').value, rules: [...document.querySelectorAll('[data-ts-rule]')].map(r => r.querySelector('code').textContent + '|' + r.querySelector('select').value) }));
    ok(st.name === 'strip' && st.rules.some(r => /^\.strip\|copy/.test(r)) && st.rules.some(r => /^\.strip-title\|copy/.test(r)) && !st.rules.some(r => /^\.btn/.test(r)), 'החלון: שם מהמחלקה, הכללים שחלים רק עליו — "העתק" כברירת מחדל (.btn המשותף — לא)', st);
    await page.fill('[data-ts="label"]', 'פס'); await page.fill('[data-ts="name"]', 'strip');
    await page.click('[data-ts-go]'); await page.waitForFunction(() => !document.getElementById('sheet').open, null, { timeout: 20000 }); await sleep(800);
    st = { html: await text(page, 'templates/strip/template.html'), css: await text(page, 'templates/strip/style.css'), json: await text(page, 'templates/strip/template.json'), thumb: await exists(page, 'templates/strip/thumb.png') };
    let tj = {}; try { tj = JSON.parse(st.json); } catch { tj = {}; }
    ok(/^<section class="strip">\n  <h3 class="strip-title">/.test(st.html || '') && /\.strip \{ background: #eee/.test(st.css) && /\.strip-title \{ color: purple/.test(st.css) && !/\.btn/.test(st.css) && tj.name === 'strip' && tj.label === 'פס' && tj.kind === 'section' && /^\d{4}-/.test(tj.created) && st.thumb, 'templates/strip/: template.html (בלי ההזחה), style.css (הכללים), template.json (שם, סוג, תאריך) ו-thumb.png', st);
    ok((await text(page, 'v13.html')) === HTML0 && (await css(page)) === CSS0, 'העמוד עצמו לא השתנה ("העתק")');
    ok(/^שמור כתבנית · strip/.test(await lastTx(page)), 'השמירה בהיסטוריה');
    // הוספה מתבנית: לחיצה ← מתחת לפס (אותו עמוד, כללים זהים — לא כפולים)
    await page.evaluate(() => { document.querySelector('[data-ntab="tpl"]').click(); }); await page.waitForSelector('[data-tpl="strip"]');
    ok(await page.evaluate(() => { const b = document.querySelector('[data-tpl="strip"]'); return /פס/.test(b.textContent) && !!b.querySelector('img.tp-thumb'); }), 'לשונית "📄 תבניות": התבנית עם הצילום');
    await select(page, 'section.strip');
    await page.click('[data-tpl="strip"]'); await sleep(900);
    h = await text(page, 'v13.html'); c2 = await css(page);
    ok((h.match(/<section class="strip">/g) || []).length === 2 && c2 === CSS0 && /^הוספה מתבנית · פס$/.test(await lastTx(page)), 'לחיצה — הפס נוסף שוב מתחתיו; הכללים כבר בעמוד וזהים — לא נכפלו', { l: await lastTx(page), css: c2 });
    await undo(page);
    // עמוד אחר: גרירה לקנבס, .strip-title קיימת עם תוכן אחר ← strip-title-2
    await openPage(page, 'v13b.html');
    await page.evaluate(() => { if (!document.querySelector('.ed').classList.contains('nav-open')) document.querySelector('.ed-bar [data-panel="nav"]').click(); document.querySelector('[data-ntab="tpl"]').click(); }); await page.waitForSelector('[data-tpl="strip"]');
    const nT = await txN(page);
    const src = await page.evaluate(() => { const b = document.querySelector('[data-tpl="strip"]').getBoundingClientRect(); return { x: b.left + b.width / 2, y: b.top + b.height / 2 }; });
    const dst = await at(page, 'div.box p', 0, 0.5, 0.9);
    await drag(page, src, dst, { shell: true, steps: 12 }); await sleep(1000);
    st = { h: await text(page, 'v13b.html'), css: await text(page, 'css/v13b.css'), l: await lastTx(page), n: await txN(page), note: await page.evaluate(() => document.querySelector('.ed-note').textContent) };
    ok(/<h3 class="strip-title-2">פס<\/h3>/.test(st.h) && /<h1 class="strip-title">/.test(st.h) && !/data-kol-component/.test(st.h), 'גרירה לקנבס של עמוד אחר: ה-HTML מועתק; המחלקה .strip-title קיימת עם תוכן אחר ← strip-title-2', st.h);
    ok(/\/\* תבנית: פס \*\/\n\.strip \{ background: #eee; padding: 8px; \}[\s\S]*\.strip-title-2 \{ color: purple; \}/.test(st.css) && /\.strip-title \{ color: orange; \}/.test(st.css), 'ה-CSS נוסף לסוף css/v13b.css בבלוק עם הערה, בשם החדש — והכלל הקיים לא נגעו בו', st.css);
    ok(st.n === nT + 1 && /^הוספה מתבנית · פס · strip-title ← strip-title-2/.test(st.l) && /strip-title ← \.strip-title-2/.test(st.note), 'עסקה אחת, עם הודעה על שינוי השם', st);
    await undo(page);
    ok(!/strip-title-2|class="strip"/.test(await text(page, 'v13b.html')) && (await text(page, 'css/v13b.css')) === '.box { padding: 4px; }\n.strip-title { color: orange; }\n', 'Ctrl+Z אחד — ה-HTML וה-CSS');
    // ייצוא ← ייבוא
    const ex = await page.evaluate(async () => { const r = await Editor._state.tpl.exportZip('strip', { noDownload: true }); return r && { files: r.files, miss: r.miss, b64: btoa(String.fromCharCode(...r.zip)) }; });
    ok(ex && ['templates/strip/style.css', 'templates/strip/template.html', 'templates/strip/template.json', 'templates/strip/thumb.png', 'media/dot.svg'].every(p => ex.files.includes(p)) && !ex.miss.length, 'ייצוא: templates/strip/ (4 קבצים) + המדיה שהתבנית מפנה אליה (media/dot.svg)', ex && ex.files);
    const dl = page.waitForEvent('download', { timeout: 8000 }).catch(() => null);
    await page.click('[data-tpl-export="strip"]');
    const d1 = await dl; ok(!!d1 && d1.suggestedFilename() === 'template-strip.zip', 'כפתור 🗜 — הורדה template-strip.zip');
    const zpath = path.join(data, 'tpl.zip'); fs.writeFileSync(zpath, Buffer.from(ex.b64, 'base64'));
    // מוחקים את התבנית ומייבאים
    await page.evaluate(async () => { await api('pf_delete', { path: 'templates/strip' }); await Project.docs.reload(); await Editor._state.tpl.load(); });
    ok(!(await exists(page, 'templates/strip/template.html')), 'התבנית נמחקה (לפני הייבוא)');
    await page.setInputFiles('[data-tpl-import-file]', zpath); await page.waitForSelector('#sheet[open] [data-tpl-import-go]', { timeout: 10000 });
    st = await page.evaluate(() => ({ add: [...document.querySelectorAll('[data-tpl-imp="add"] li')].map(x => x.textContent), same: [...document.querySelectorAll('[data-tpl-imp="same"] li')].map(x => x.textContent) }));
    ok(st.add.includes('templates/strip/template.html') && st.add.includes('templates/strip/thumb.png') && st.same.includes('media/dot.svg'), 'ייבוא — תצוגה מקדימה: מה יתווסף, ומה כבר זהה בפרויקט', st);
    await page.click('[data-tpl-import-go]'); await sleep(1200);
    ok((await text(page, 'templates/strip/template.html')) === `<section class="strip">\n  <h3 class="strip-title">פס</h3>\n  <p class="strip-text btn">טקסט</p>\n  <img src="media/dot.svg" alt="">\n</section>\n` && await page.evaluate(() => !!document.querySelector('[data-tpl="strip"]')), 'נוסף — והתבנית בלשונית שוב');
    // התנגשות
    await page.evaluate(async () => { const f = Project.docs.byPath('templates/strip/template.json'), d = await Project.docs.open(f.id); d.edit([{ from: 0, to: d.text().length, text: '{"name":"strip","label":"שונה","kind":"section"}\n' }], 'שינוי'); await Project._save(f.id); });
    await page.setInputFiles('[data-tpl-import-file]', zpath); await page.waitForSelector('#sheet[open] [data-tpl-import-go]');
    st = await page.evaluate(() => [...document.querySelectorAll('[data-tpl-imp="replace"] li')].map(x => x.textContent));
    ok(st.length === 1 && st[0] === 'templates/strip/template.json', 'התנגשות שמות — הקובץ ששונה מופיע ב"יוחלף"', st);
    await closeSheet(page);
    // ZIP זדוני — נדחה לפני השרת
    const evil = await page.evaluate(() => { const T = window.KolTemplates, e = new TextEncoder(); return btoa(String.fromCharCode(...T.zipBuild([['templates/x/template.html', e.encode('<p>')], ['templates/x/template.json', e.encode('{}')], ['index.html', e.encode('<script>alert(1)</script>')]]))); });
    const epath = path.join(data, 'evil.zip'); fs.writeFileSync(epath, Buffer.from(evil, 'base64'));
    const idxBefore = await text(page, 'index.html');
    await page.setInputFiles('[data-tpl-import-file]', epath); await page.waitForSelector('#sheet[open] [data-tpl-import-err]');
    ok(/מותר רק templates/.test(await page.evaluate(() => document.querySelector('[data-tpl-import-err]').textContent)) && (await text(page, 'index.html')) === idxBefore, 'ZIP עם קובץ מחוץ לתבנית (index.html) — נדחה, ושום דבר לא נכתב');
    await closeSheet(page);
    ok(await page.evaluate(() => !!document.querySelector('[data-tpl-project-zip]')), 'הפרויקט כולו — קישור ל-ZIP של F2b');
    // תבנית עמוד
    await page.evaluate(() => Editor._state.tpl.save(null)); await page.waitForSelector('[data-ts="name"]');
    await page.fill('[data-ts="label"]', 'עמוד B'); await page.click('[data-ts-go]'); await page.waitForFunction(() => !document.getElementById('sheet').open, null, { timeout: 20000 }); await sleep(600);
    st = await page.evaluate(async () => { await Project.docs.reload(); const f = Project.docs.byPath('templates/v13b-page/template.json'); return f ? JSON.parse((await Project.docs.open(f.id)).text()) : null; });
    ok(st && st.kind === 'page' && /^<!doctype html>/.test(await text(page, 'templates/v13b-page/template.html')) && /\.box/.test(await text(page, 'templates/v13b-page/style.css')), 'שמור את העמוד כתבנית — סוג "עמוד", הקובץ כולו וה-CSS שלו', st);
    await page.click('[data-tpl="v13b-page"]', { force: true }); await sleep(200);
    ok(/V15/.test(await page.evaluate(() => document.querySelector('.ed-note').textContent)), 'תבנית עמוד — "עמוד חדש מתבנית" יגיע עם V15 (נאמר)');
    // build_is_config
    const bic = execFileSync('php', ['-r', 'define("KOL_DATA_DIR", $argv[1]); foreach (["db","build"] as $l) require_once $argv[2] . "/lib/$l.php"; echo json_encode([build_is_config("templates/strip/template.html"), build_is_config("templates/strip/thumb.png"), build_is_config("media/dot.svg"), build_is_config("x/templates/a.html"), build_is_config("components/a/template.html")]);', data, ROOT]).toString();
    ok(bic === '[true,true,false,false,true]', 'build_is_config: templates/ מחוץ לחבילה (ורק בשורש)', bic);

    // ═══ 7. על כל המסך ═══
    await openPage(page, 'v13.html');
    for (const [w, hh] of [[1920, 1080], [1366, 768]]) {
      await page.setViewportSize({ width: w, height: hh }); await sleep(400);
      for (const id of ['hist', 'tpl']) {
        await page.evaluate(id => { if (!document.querySelector('.ed').classList.contains('nav-open')) document.querySelector('.ed-bar [data-panel="nav"]').click(); document.querySelector('[data-ntab="' + id + '"]').click(); }, id); await sleep(300);
        st = await page.evaluate(() => { const cv = document.querySelector('.ed-canvas').getBoundingClientRect(), nv = document.querySelector('.ed-nav').getBoundingClientRect(); return { cv: Math.round(cv.width), nv: Math.round(nv.width), sx: document.documentElement.scrollWidth - innerWidth, ed: Math.round(document.querySelector('.ed').getBoundingClientRect().width) }; });
        ok(st.sx <= 0 && st.cv >= w - 2 * 600 && st.ed >= w - 80, w + ': לשונית ' + id + ' בצד — הקנבס ממלא את השאר (' + st.cv + 'px), בלי גלילה לרוחב', st);
        await page.evaluate(() => document.querySelector('.ed-bar [data-panel="nav"]').click()); await sleep(250);
        const cv2 = await page.evaluate(() => Math.round(document.querySelector('.ed-canvas').getBoundingClientRect().width));
        ok(cv2 > st.cv, w + ': הלוח מתקפל — הקנבס גדל (' + st.cv + ' ← ' + cv2 + ')');
        await page.evaluate(() => document.querySelector('.ed-bar [data-panel="nav"]').click()); await sleep(250);
      }
      await rclick(page, 'h1.t1');
      st = await page.evaluate(() => { const r = document.querySelector('.ed-ctx').getBoundingClientRect(); return { in: r.left >= 0 && r.right <= innerWidth && r.top >= 0 && r.bottom <= innerHeight, w: r.width }; });
      ok(st.in, w + ': התפריט בתוך המסך', st);
      await shot(page, 'v13-' + w + '-menu');
      await page.keyboard.press('Escape');
    }
    await page.setViewportSize({ width: 1920, height: 1080 }); await sleep(300);
    await page.evaluate(() => { document.querySelector('[data-ntab="tpl"]').click(); }); await select(page, 'h1.t1'); await page.evaluate(() => Editor._state.palette.open()); await page.keyboard.type('צל'); await sleep(200);
    await shot(page, 'v13-1920-palette'); await page.keyboard.press('Escape');
    await shot(page, 'v13-1920-templates');
    await c1.close();

    // ═══ 8. טלפון (390): לחיצה ארוכה ═══
    const c3 = await mk(390, 844), p3 = await c3.newPage();
    p3.on('pageerror', e => console.log('   ⚠️ שגיאה בטלפון: ' + e.message));
    await p3.goto(base + '/editor.php#/editor/v13.html'); await ready(p3, 'v13.html'); await sleep(600);
    const q = await at(p3, 'h2.t2');
    await p3.evaluate(PD); await p3.evaluate(([x, y]) => window.__pd.down(x, y, true), [q.x, q.y]); await sleep(700); await p3.evaluate(([x, y]) => window.__pd.up(x, y), [q.x, q.y]); await sleep(300);   // לחיצה ארוכה (V4: מחמשת גרירה; שחרור בלי תזוזה — התפריט)
    let st3 = await p3.evaluate(() => { const m = document.querySelector('.ed-ctx'); if (!m) return null; const r = m.getBoundingClientRect(); return { bottom: Math.round(r.bottom), H: innerHeight, w: Math.round(r.width), W: innerWidth, why: getComputedStyle(m.querySelector('.ed-ctx-why') || m).display, h: Math.round(m.querySelector('.ed-ctx-item').getBoundingClientRect().height) }; });
    ok(st3 && Math.abs(st3.bottom - st3.H) <= 1 && st3.w === st3.W && st3.h >= 44 && st3.why !== 'none', '390: לחיצה ארוכה ← התפריט מלמטה, ברוחב המסך, שורות ≥44px, הסיבות גלויות', st3);
    await shot(p3, 'v13-390-menu');
    await p3.tap('.ed-ctx [data-ctx="duplicate"]'); await sleep(500);
    ok(/^שכפול · h2\.t2/.test(await lastTx(p3)), '390: הקשה על פעולה — מבוצעת');
    await shot(p3, 'v13-390');
    await c3.close();
  } catch (e) { failed++; console.log('❌ חריגה: ' + e.stack); }
  finally { await browser?.close(); php.kill(); }
  console.log((failed ? '❌ ' : '✅ ') + (ran - failed) + '/' + ran + ' · צילומים: ' + path.join(os.tmpdir(), 'kol-v13-shots'));
  process.exit(failed ? 1 : 0);
})();
