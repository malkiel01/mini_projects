/**
 * פרוסה V12 — רכיבים — בדיקות בדפדפן   node kol-maroko/tests/browser/v12.cjs
 *
 * על העמוד המוסב (F3) ועמוד שני (about.html): "שמור כרכיב" על שורת החיפוש והנושאים (template / component.json / style.css,
 * הכללים מועברים, האלמנט = מופע מקושר ראשון, Ctrl+Z אחד) · "➕ הוסף הגדרה" (כיתוב הכפתור) ← component.json נכון · לשונית
 * "🧩 רכיבים" — גרירה לקנבס של העמוד השני (HTML + <link> פעם אחת, Ctrl+Z אחד) · ההגדרות בפאנל (הערך מהמופע) · מקושר ← שונה
 * (עריכה ביד) ← "הצג את ההבדלים" ← "חזור לרכיב" · התגים בקנבס וב-Navigator · "✏️ ערוך את הרכיב" (template.html + style.css
 * בקנבס) ← "שמור ועדכן מופעים": 3 מופעים בשני קבצים, הכיתוב השונה נשמר, Ctrl+Z אחד · כל יעד בסכמה (text · attr · class ·
 * var · children · src) דרך הפאנל, וחלק חסר מושבת · repeater: גרירה בפאנל ובקנבס · component.json שגוי ← שגיאה עם שורה ·
 * הערת "ישנה את כל N המופעים" בעיצוב · מחיקה (נתק קודם, לסל, Ctrl+Z) · "הוסף לכל העמודים" · הזרקה · 1920 / 1366 / 390.
 * צילומים — ל-tmpdir (kol-v12-shots).
 */
'use strict';
const { chromium } = require('playwright');
const { spawn, execFileSync } = require('child_process');
const fs = require('fs'), os = require('os'), path = require('path'), net = require('net');

const ROOT = path.resolve(__dirname, '..', '..');
const OWNER = { sub: 'u-owner', name: 'Owner Test', email: 'owner@example.test', email_verified: true, platform_admin: true };
let failed = 0, ran = 0;
let T0 = Date.now(); const ok = (cond, what, got) => { ran++; const dt = ((Date.now() - T0) / 1000).toFixed(1); T0 = Date.now(); if (cond) console.log('✅ [' + dt + 's] ' + what); else { failed++; console.log('❌ ' + what + (got !== undefined ? '\n   התקבל: ' + JSON.stringify(got).slice(0, 1200) : '')); } };
const freePort = () => new Promise(r => { const s = net.createServer().listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => r(p)); }); });
const sleep = ms => new Promise(r => setTimeout(r, ms));

(async () => {
  const data = fs.mkdtempSync(path.join(os.tmpdir(), 'kol-v12-'));
  execFileSync('php', [path.join(__dirname, 'f3-seed.php'), data, path.join(data, 'old-render.html')]);
  const port = await freePort(), base = `http://127.0.0.1:${port}`;
  const php = spawn('php', ['-S', `127.0.0.1:${port}`, path.join(ROOT, 'tests', 'router.php')], { cwd: ROOT, env: { ...process.env, KOL_TEST_DATA: data, PHP_CLI_SERVER_WORKERS: '4' }, stdio: 'ignore' });
  let browser;
  try {
    for (let i = 0; i < 100; i++) { try { await fetch(base + '/api.php?action=embed_key'); break; } catch { await sleep(50); } }
    browser = await chromium.launch();
    const mk = (width, height, user = OWNER) => browser.newContext({ viewport: { width, height }, hasTouch: width < 600, isMobile: width < 600, extraHTTPHeaders: { 'X-Test-User': JSON.stringify(user) } });
    const call = (page, action, body, query = '') => page.evaluate(([a, b, q]) => api(a, b ?? undefined, q), [action, body ?? null, query]);
    const ready = (page, p) => page.waitForFunction(q => Editor._state.file && Editor._state.file.path === q && Editor._state.canvas && Editor._state.canvas.m && !Editor._state.busy && Editor._state.build && Editor._state.comp && Editor._state.lv, p, { timeout: 30000 });
    const flush = async page => { try { await page.evaluate(async () => { const S = Project._state; for (const [id, d] of S.docs) if (d.dirty) await Project._save(id); }); await page.waitForFunction(() => [...Project._state.docs.values()].every(d => !d.dirty && !d.saving), null, { timeout: 15000 }); } catch (e) { console.log('   ⚠️ flush: ' + e.message.slice(0, 200)); } };
    const openPage = async (page, p) => { if (page.url().includes('editor.php')) await flush(page); await page.goto(base + '/editor.php#/editor/' + encodeURIComponent(p)); await page.reload(); await ready(page, p); await page.waitForFunction(() => Editor._state.lv.lists.every(L => L.status !== 'loading'), null, { timeout: 30000 }); await sleep(600); };
    const text = (page, p) => page.evaluate(async p => { const f = Project.docs.byPath(p); if (!f) return null; const d = await Project.docs.open(f.id); return d.text(); }, p);
    const exists = (page, p) => page.evaluate(async p => { await Project.docs.reload(); return !!Project.docs.byPath(p); }, p);
    const lastTx = page => page.evaluate(() => { const t = Editor._state.tx; return t.length ? t[t.length - 1].label : ''; });
    const txN = page => page.evaluate(() => Editor._state.tx.length);
    const select = (page, sel, i = 0) => page.evaluate(([s, i]) => { const c = Editor._state.canvas, e = c.doc.querySelectorAll(s)[i]; if (!e) return false; const n = c.nodeOf(e); c.select(n); c.reveal(n); return !!n; }, [sel, i]);
    const tab = async (page, id) => { await page.evaluate(id => { if (!document.querySelector('.ed').classList.contains('info-open')) document.querySelector('.ed-bar [data-panel="info"]').click(); Editor._tab(id); }, id); await sleep(250); };
    const stateOf = (page, sel, i = 0) => page.evaluate(([s, i]) => { const S = Editor._state, c = S.canvas, e = c.doc.querySelectorAll(s)[i]; const n = e && c.nodeOf(e); const st = n && S.comp.stateOf(n); return st ? st.state : null; }, [sel, i]);
    const undo = async page => { await page.evaluate(() => Editor._undo()); await sleep(400); };
    const redo = async page => { await page.evaluate(() => Editor._redo()); await sleep(400); };
    const sheetOpen = page => page.evaluate(() => document.getElementById('sheet').open);
    const PD = () => {
      const ed = Editor._state, c = ed.canvas;
      const inFrame = (x, y) => { const f = c.frame.getBoundingClientRect(); return x >= f.left && x <= f.right && y >= f.top && y <= f.bottom; };
      const fpt = (x, y) => { const f = c.frame.getBoundingClientRect(); return [(x - f.left) / c.scale, (y - f.top) / c.scale]; };
      const fire = (t, type, x, y, cx, cy, touch) => t.dispatchEvent(new (t.ownerDocument.defaultView.PointerEvent)(type, { bubbles: true, cancelable: true, composed: true, clientX: cx, clientY: cy, pointerId: touch ? 2 : 1, pointerType: touch ? 'touch' : 'mouse', isPrimary: true, button: 0, buttons: type === 'pointerup' ? 0 : 1 }));
      window.__pd = {
        down(x, y, touch, shell) { this.touch = !!touch; this.frame = inFrame(x, y) && !shell; if (this.frame) { const [fx, fy] = fpt(x, y); this.t = c.doc.elementFromPoint(fx, fy); fire(this.t, 'pointerdown', x, y, fx, fy, touch); } else { this.t = document.elementFromPoint(x, y); fire(this.t, 'pointerdown', x, y, x, y, touch); } },
        move(x, y) { if (this.frame) { const [fx, fy] = fpt(x, y); fire(c.doc.elementFromPoint(fx, fy) || c.doc.documentElement, 'pointermove', x, y, fx, fy, this.touch); } else { const t = ed.build.drag.active ? (document.elementFromPoint(x, y) || document.body) : this.t; fire(t.localName === 'iframe' ? document.body : t, 'pointermove', x, y, x, y, this.touch); } },
        up(x, y) { if (this.frame) { const [fx, fy] = fpt(x, y); fire(c.doc.elementFromPoint(fx, fy) || c.doc.documentElement, 'pointerup', x, y, fx, fy, this.touch); } else { const t = ed.build.drag.active ? (document.elementFromPoint(x, y) || document.body) : this.t; fire(t.localName === 'iframe' ? document.body : t, 'pointerup', x, y, x, y, this.touch); } },
      };
    };
    const drag = async (page, from, to, opt = {}) => {
      await page.evaluate(PD);
      await page.evaluate(([x, y, t, sh]) => window.__pd.down(x, y, t, sh), [from.x, from.y, !!opt.touch, !!opt.shell]);
      const steps = opt.steps || 10;
      for (let i = 1; i <= steps; i++) { await page.evaluate(([x, y]) => window.__pd.move(x, y), [from.x + (to.x - from.x) * i / steps, from.y + (to.y - from.y) * i / steps]); await sleep(24); }
      await sleep(80);
      await page.evaluate(([x, y]) => window.__pd.up(x, y), [to.x, to.y]); await sleep(400);
    };
    const at = (page, sel, i = 0, fx = 0.5, fy = 0.5) => page.evaluate(([s, i, fx, fy]) => { const c = Editor._state.canvas, e = c.doc.querySelectorAll(s)[i]; if (!e) return null; e.scrollIntoView({ block: 'center' }); const r = e.getBoundingClientRect(), f = c.frame.getBoundingClientRect(); return { x: f.left + (r.left + r.width * fx) * c.scale, y: f.top + (r.top + r.height * fy) * c.scale }; }, [sel, i, fx, fy]);
    const shotDir = path.join(os.tmpdir(), 'kol-v12-shots'); fs.mkdirSync(shotDir, { recursive: true });
    const shot = (page, name) => page.screenshot({ path: path.join(shotDir, name + '.png') });
    const CS = '.kadisha-lexicon-controls';

    const ctx = await mk(1920, 1080), page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', e => { errors.push(e.message); console.log('   ⚠️ שגיאה בדף אחרי בדיקה ' + ran + ': ' + e.message + ' ' + String(e.stack || '').split('\n').slice(1, 4).join(' | ').slice(0, 400)); });
    page.on('dialog', d => (d.type() === 'beforeunload' ? d.accept() : d.dismiss()));
    await page.goto(base + '/#/project');
    await page.waitForSelector('.pj-tree .pj-row');
    await call(page, 'pf_migrate', {});
    // עמוד שני: אותם קובצי CSS
    const idx0 = await page.evaluate(async () => { await Project.docs.reload(); const f = Project.docs.byPath('index.html'); return (await Project.docs.open(f.id)).text(); });
    const links = (idx0.match(/<link\b[^>]*rel="stylesheet"[^>]*>/g) || []).join('\n  ');
    await call(page, 'pf_create', { path: 'about.html', content: '<!doctype html>\n<html lang="he" dir="rtl">\n<head>\n  <meta charset="utf-8">\n  <title>אודות</title>\n  ' + links + '\n</head>\n<body>\n  <main class="about">\n    <h1 class="about-title">אודות</h1>\n    <p class="about-text">עמוד שני.</p>\n  </main>\n</body>\n</html>\n' });
    await openPage(page, 'index.html');
    await page.click('[data-dev="desktop"]'); await sleep(300);
    const sheets0 = await page.evaluate(() => { const o = {}; for (const x of Editor._state.sheets.values()) o[x.path] = x.doc.text(); return o; });

    // ═══ 1. "שמור כרכיב" ═══
    ok(await select(page, CS), 'שורת החיפוש והנושאים נבחרה בקנבס');
    await tab(page, 'content');
    ok(await page.$('[data-w="comp-save"]') !== null, 'בלשונית "תוכן" — "🧩 שמור כרכיב" לאלמנט שאינו רכיב');
    await page.click('[data-w="comp-save"]'); await sleep(300);
    let st = await page.evaluate(() => ({ open: document.getElementById('sheet').open, name: document.querySelector('[data-cs="name"]').value, rules: [...document.querySelectorAll('[data-cs-rule]')].map(r => r.querySelector('code').textContent) }));
    ok(st.open && /^kadisha-lexicon-controls/.test(st.name) && st.rules.length >= 3 && st.rules.some(r => /kadisha-lexicon-search/.test(r)) && !st.rules.some(r => /^\.kadisha-page|^body|^:root/.test(r)), 'החלון: שם נגזר מהמחלקה, והצעת הכללים שחלים רק על האלמנט ועל צאצאיו', st);
    await page.fill('[data-cs="label"]', 'שורת חיפוש'); await page.fill('[data-cs="name"]', 'search-bar'); await page.fill('[data-cs="icon"]', '🔎'); await page.fill('[data-cs="category"]', 'ניווט');
    const keepRule = st.rules.findIndex(r => /kadisha-lex-topic-count/.test(r));
    if (keepRule >= 0) await page.selectOption('[data-cs-rule-act="' + keepRule + '"]', 'keep');
    const copyRule = st.rules.findIndex((r, i) => i !== keepRule && /kadisha-lexicon-count/.test(r));
    if (copyRule >= 0) await page.selectOption('[data-cs-rule-act="' + copyRule + '"]', 'copy');
    const n0 = await txN(page);
    await page.click('[data-cs-go]'); await page.waitForFunction(() => !document.getElementById('sheet').open, null, { timeout: 15000 }); await sleep(1200);
    const tplT = await text(page, 'components/search-bar/template.html'), jsonT = await text(page, 'components/search-bar/component.json'), cssT = await text(page, 'components/search-bar/style.css');
    ok(tplT && /^<div class="kadisha-lexicon-controls"/.test(tplT) && !/data-kol-component/.test(tplT) && /\n  <label for="kadisha-lex-search"/.test(tplT), 'template.html: ה-HTML של האלמנט, בלי ההזחה של העמוד ובלי הסימון', (tplT || '').slice(0, 300));
    let js = JSON.parse(jsonT || '{}');
    ok(js.name === 'search-bar' && js.label === 'שורת חיפוש' && js.icon === '🔎' && js.category === 'ניווט' && Array.isArray(js.fields) && !js.fields.length, 'component.json: שם, שם לתצוגה, סמל, קטגוריה, fields ריק', js);
    let idx = await text(page, 'index.html'), sh = await page.evaluate(() => { const o = {}; for (const x of Editor._state.sheets.values()) o[x.path] = x.doc.text(); return o; });
    const pageCss = Object.keys(sheets0).filter(p => !/^components\//.test(p));
    const movedSel = st.rules.find((r, i) => i !== keepRule && i !== copyRule && /kadisha-lexicon-search-wrap/.test(r)) || st.rules.find((r, i) => i !== keepRule && i !== copyRule);
    const inPage = sel => pageCss.some(p => (sh[p] || '').includes(sel.split(' · ')[0] + ' {') || (sh[p] || '').includes(sel.split(' · ')[0] + '{'));
    ok(/data-kol-component="search-bar"/.test(idx) && /<link rel="stylesheet" href="components\/search-bar\/style\.css">/.test(idx), 'העמוד: data-kol-component על האלמנט, ו-<link> ל-style.css ב-<head>', idx.match(/<div class="kadisha-lexicon-controls"[^>]*>/));
    ok(cssT.includes(movedSel.split(' · ')[0]) && !inPage(movedSel), '"העבר": הכלל ' + movedSel + ' יצא מקובץ העמוד ונכנס ל-style.css', { css: cssT.slice(0, 400) });
    if (copyRule >= 0) ok(cssT.includes(st.rules[copyRule].split(' · ')[0]) && inPage(st.rules[copyRule]), '"העתק": ' + st.rules[copyRule] + ' — גם בעמוד וגם ב-style.css');
    if (keepRule >= 0) ok(!cssT.includes(st.rules[keepRule].split(' · ')[0] + ' {') && inPage(st.rules[keepRule]), '"השאר בעמוד": ' + st.rules[keepRule] + ' — רק בעמוד');
    ok(await stateOf(page, CS) === 'linked', 'האלמנט בעמוד — המופע המקושר הראשון (◆)');
    ok(await txN(page) === n0 + 1 && /שמור כרכיב · search-bar/.test(await lastTx(page)), 'הכול — עסקה אחת בהיסטוריה', await lastTx(page));
    st = await page.evaluate(() => ({ tag: [...document.querySelectorAll('.ed-comp-tag')].map(b => b.textContent + '|' + b.dataset.compTag), nav: (() => { Editor._state.nav.refresh(); Editor._state.nav.sync(); return [...document.querySelectorAll('.nv-comp')].map(x => x.textContent.trim() + '|' + x.dataset.comp); })() }));
    ok(st.tag.includes('◆ search-bar|linked') && st.nav.includes('◆ search-bar|linked'), 'התג ◆ על שורש המופע — בקנבס וב-Navigator', st);
    await shot(page, 'v12-1920-created');
    // Ctrl+Z אחד: העמוד, ה-CSS והקבצים חוזרים
    await page.keyboard.press('Escape'); await page.evaluate(() => document.activeElement && document.activeElement.blur && document.activeElement.blur());
    await undo(page); await sleep(800);
    idx = await text(page, 'index.html'); sh = await page.evaluate(() => { const o = {}; for (const x of Editor._state.sheets.values()) if (!/^components\//.test(x.path)) o[x.path] = x.doc.text(); return o; });
    const sh0 = {}; for (const p of pageCss) sh0[p] = sheets0[p];
    ok(idx === idx0 && JSON.stringify(sh) === JSON.stringify(sh0) && !(await exists(page, 'components/search-bar/component.json')), 'Ctrl+Z אחד: העמוד וה-CSS בייט בבייט, וקובצי הרכיב לסל', { same: idx === idx0, css: JSON.stringify(sh) === JSON.stringify(sh0) });
    await redo(page); await sleep(1200);
    ok(/data-kol-component="search-bar"/.test(await text(page, 'index.html')) && await exists(page, 'components/search-bar/component.json'), 'Ctrl+Y: הרכיב חוזר (העמוד + הקבצים מהסל)');
    await page.evaluate(() => Editor._state.comp.load()); await sleep(600);

    // ═══ 2. "➕ הוסף הגדרה" — כיתוב הכפתור ═══
    ok(await select(page, CS + ' .kadisha-lex-topic-btn.is-active'), 'כפתור "הכל" בתוך המופע נבחר');
    await tab(page, 'content');
    st = await page.evaluate(() => ({ panel: !!document.querySelector('[data-comp-panel]'), root: !!document.querySelector('[data-w="comp-root"]'), add: !!document.querySelector('[data-w="comp-add-setting"]') }));
    ok(st.panel && st.root && st.add, 'חלק במופע: "🧩 רכיב · שורת חיפוש", "↑ בחר את שורש הרכיב", "➕ הוסף הגדרה"', st);
    await page.click('[data-w="comp-add-setting"]'); await sleep(300);
    await page.selectOption('[data-as="kind"]', 'text'); await page.fill('[data-as="label"]', 'כיתוב הכפתור');
    await page.click('[data-as-go]'); await sleep(900);
    js = JSON.parse(await text(page, 'components/search-bar/component.json'));
    ok(js.fields.length === 1 && js.fields[0].label === 'כיתוב הכפתור' && js.fields[0].control === 'text' && js.fields[0].target.text === '.kadisha-lex-topic-btn', 'component.json: שורה ב-fields — text ← .kadisha-lex-topic-btn (מחלקה קיימת)', js.fields);
    ok(await stateOf(page, CS) === 'linked', 'אחרי "הוסף הגדרה" — המופע עדיין מקושר');
    await select(page, CS); await tab(page, 'content');
    st = await page.evaluate(() => { const i = document.querySelector('[data-comp-field] [data-cf]'); return i ? { id: i.dataset.cf, v: i.value } : null; });
    ok(st && st.v === 'הכל', 'שורש המופע: השדה "כיתוב הכפתור" בפאנל, והערך נקרא מהמופע ("הכל")', st);


    // ═══ 3. לשונית "🧩 רכיבים" ← גרירה לקנבס של העמוד השני ═══
    await openPage(page, 'about.html');
    const ab0 = await text(page, 'about.html');
    await page.click('[data-ntab="comp"]'); await sleep(300);
    st = await page.evaluate(() => ({ items: [...document.querySelectorAll('[data-comp-body] [data-comp]')].map(x => x.dataset.comp + '|' + x.querySelector('.ed-lib-item').textContent.trim()), hidden: document.querySelector('[data-comp-body]').hidden }));
    ok(!st.hidden && st.items.length === 1 && /^search-bar\|🔎 שורת חיפוש/.test(st.items[0]), 'לשונית "🧩 רכיבים": הרכיב מהפרויקט (שם, סמל, מספר מופעים)', st);
    let b = await (await page.$('.ed-lib-item[data-lib="comp-search-bar"]')).boundingBox();
    let to = await at(page, '.about-title', 0, 0.5, 0.6);
    let nA = await txN(page);
    await drag(page, { x: b.x + b.width / 2, y: b.y + b.height / 2 }, to, { shell: true }); await sleep(800);
    let ab = await text(page, 'about.html');
    ok(/<h1 class="about-title">אודות<\/h1>\n    <div class="kadisha-lexicon-controls"[^>]*data-kol-component="search-bar">\n      <label/.test(ab), 'גרירה לקנבס: ה-HTML של template.html נכתב אחרי הכותרת (מוזח כמו שכניו), עם data-kol-component', ab.slice(ab.indexOf('<main'), ab.indexOf('<main') + 260));
    ok((ab.match(/components\/search-bar\/style\.css/g) || []).length === 1 && /<link rel="stylesheet" href="components\/search-bar\/style\.css">\s*<\/head>/.test(ab), '<link> ל-style.css — פעם אחת, בסוף ה-<head>');
    ok(await txN(page) === nA + 1 && /הוספת רכיב · שורת חיפוש/.test(await lastTx(page)), 'ה-HTML וה-<link> — פעולה אחת בהיסטוריה', await lastTx(page));
    ok(await stateOf(page, CS) === 'linked', 'המופע החדש — מקושר');
    await undo(page);
    ok(await text(page, 'about.html') === ab0, 'Ctrl+Z אחד מחזיר את העמוד בייט בבייט (ה-HTML וה-<link>)');
    await redo(page);
    // script.js: <script defer> פעם אחת; בקנבס אינו רץ
    await call(page, 'pf_create', { path: 'components/search-bar/script.js', content: 'window.__kolCompRan = 1;\n' });
    await page.evaluate(async () => { await Project.docs.reload(); await Editor._state.comp.load(); }); await sleep(500);
    await select(page, '.about-title');
    nA = await txN(page);
    await page.click('.ed-lib-item[data-lib="comp-search-bar"]'); await sleep(900);
    ab = await text(page, 'about.html');
    ok((ab.match(/components\/search-bar\/style\.css/g) || []).length === 1 && (ab.match(/<script defer src="components\/search-bar\/script\.js"><\/script>/g) || []).length === 1 && (ab.match(/data-kol-component="search-bar"/g) || []).length === 2 && await txN(page) === nA + 1, 'לחיצה ← "הוסף אחרי הנבחר": מופע שני; <link> לא כפול; <script defer> פעם אחת; פעולה אחת', { n: (ab.match(/data-kol-component/g) || []).length });
    st = await page.evaluate(() => ({ ran: !!Editor._state.canvas.frame.contentWindow.__kolCompRan, sel: Editor._state.canvas.label(Editor._state.canvas.selected) }));
    await tab(page, 'content');
    ok(!st.ran && await page.$('[data-comp-js]') !== null, 'script.js אינו רץ בקנבס, ובפאנל: "יש לרכיב קוד — ראו ▶️ תצוגה חיה"', st);

    // ═══ 4. ההגדרות בפאנל ═══
    await select(page, CS, 0); await tab(page, 'content');
    if (!await page.$('[data-comp-field] [data-cf]')) console.log('   דיבאג: ' + await page.evaluate(() => (document.querySelector('.ct') || document.body).innerText.slice(0, 600)));
    await page.fill('[data-comp-field] [data-cf]', 'כל הנושאים'); await page.press('[data-comp-field] [data-cf]', 'Tab'); await sleep(500);
    ab = await text(page, 'about.html');
    ok(/data-kol-filter-value="">כל הנושאים</.test(ab) && await stateOf(page, CS, 0) === 'linked' && /הגדרה · כיתוב הכפתור/.test(await lastTx(page)), 'שדה בפאנל ← הכתיבה ליעד (text), והמופע נשאר מקושר', await lastTx(page));

    // ═══ 5. מקושר ← שונה במקום (עריכה ביד) ← "הצג את ההבדלים" ← "חזור לרכיב" ═══
    const handEdit = (s1, s2) => page.evaluate(([a, b2]) => { const S = Editor._state, K = window.KolSrc, c = S.canvas, e = [...c.doc.querySelectorAll('label.screen-reader-text')][1]; const n = c.nodeOf(e); return Editor._act('עריכה ביד', m => K.html.setText(m, K.byPath(m, K.pathOf(n)), b2), n); }, [s1, s2]);
    await handEdit(null, 'חיפוש שונה'); await sleep(400);
    ok(await stateOf(page, CS, 1) === 'modified' && await stateOf(page, CS, 0) === 'linked', 'עריכה ביד של חלק שאינו הגדרה ← "שונה במקום" (◇); המופע השני — מקושר');
    st = await page.evaluate(() => [...document.querySelectorAll('.ed-comp-tag')].map(b => b.textContent + '|' + b.dataset.compTag));
    ok(st.includes('◇ search-bar|modified') && st.includes('◆ search-bar|linked'), 'התגים בקנבס: ◆ ו-◇', st);
    await select(page, CS, 1); await tab(page, 'content');
    await page.click('[data-w="comp-diff"]'); await sleep(300);
    st = await page.evaluate(() => { const d = document.querySelector('[data-comp-diffview]'); return d ? { del: [...d.querySelectorAll('.del')].map(x => x.textContent), add: [...d.querySelectorAll('.add')].map(x => x.textContent) } : null; });
    ok(st && st.del.some(x => /חיפוש שונה/.test(x)) && st.add.some(x => /חיפוש מילה/.test(x)), '"הצג את ההבדלים": − חיפוש שונה · + חיפוש מילה', st);
    await page.evaluate(() => document.getElementById('sheet').close()); await sleep(100);
    await page.click('[data-w="comp-revert"]'); await sleep(300);
    ok(await page.$('[data-comp-diffview]') !== null, '"חזור לרכיב" — ה-diff לפני ההחלפה');
    await page.click('[data-comp-revert-go]'); await sleep(500);
    ok(await stateOf(page, CS, 1) === 'linked' && (await text(page, 'about.html')).split('חיפוש מילה').length === 3, '"חזור לרכיב": המופע צויר מחדש — מקושר');
    await handEdit(null, 'חיפוש שונה'); await sleep(400);

    // ═══ 6. "✏️ ערוך את הרכיב" ← "שמור ועדכן מופעים" (3 מופעים בשני קבצים) ═══
    await select(page, CS, 0); await tab(page, 'content');
    // T2: מה שנראה בעמוד — אותו מראה בעריכת התבנית (CSS העמוד נטען גם שם; לפני T2 אייקון החיפוש עלה על ה-placeholder)
    const look = () => page.evaluate(() => { const c = Editor._state.canvas, w = c.frame.contentWindow, q = s => c.doc.querySelector(':not([data-kol-canvas]) > ' + s) || c.doc.querySelector(s);
      return ['.kadisha-lexicon-search-icon', '.kadisha-lexicon-search', '.kadisha-lexicon-search-wrap'].map(s => { const e = q(s); if (!e) return null; const cs = w.getComputedStyle(e); return [s, cs.position, cs.paddingInlineStart, cs.paddingInlineEnd, cs.fontSize, cs.display, cs.borderTopWidth].join('|'); }); });
    const lookPage = await look();
    await page.click('[data-w="comp-edit"]'); await ready(page, 'components/search-bar/template.html'); await sleep(800);
    { const lt = await look(), pg = await page.evaluate(() => { const S = Editor._state, ks = [...S.canvas.doc.querySelectorAll('style[data-kol-canvas]')].map(e => e.getAttribute('data-kol-canvas')).filter(Boolean); return { ks, own: [...S.sheets].filter(([k]) => !k.startsWith('page:')).map(([, x]) => x.path) }; });
      ok(lookPage.every(Boolean) && JSON.stringify(lt) === JSON.stringify(lookPage), 'עריכת תבנית: קובצי ה-CSS של העמוד שממנו נכנסו חלים גם בקנבס — האייקון, השדה והעטיפה מעוצבים כמו בעמוד', { page: lookPage, tpl: lt });
      ok(pg.ks.includes('page:dir:rtl') && pg.ks.some(k => k.startsWith('page:css/')) && pg.ks.indexOf('style.css') > Math.max(...pg.ks.map((k, i) => (k.startsWith('page:') ? i : -1))) && pg.own.length === 1 && pg.own[0] === 'components/search-bar/style.css', 'הסדר: CSS העמוד ואחריו style.css של הרכיב; כלל חדש — רק ל-style.css של הרכיב (pagePath)', pg); }
    st = await page.evaluate(() => { const S = Editor._state; return { css: !!S.canvas.doc.querySelector('style[data-kol-canvas="style.css"]'), strip: !document.querySelector('[data-comp-strip]').hidden && document.querySelector('[data-comp-strip]').textContent, fs: getComputedStyle(S.canvas.doc.querySelector('.kadisha-lexicon-search-wrap')).position }; });
    ok(st.css && /עורך את הרכיב שורת חיפוש/.test(st.strip) && /3 מופעים ב-2 עמודים/.test(st.strip), 'template.html נפתח בקנבס עם style.css שלו; הפס: "עורך את הרכיב" · 3 מופעים ב-2 עמודים', st);
    const idxB = await text(page, 'index.html'), abB = await text(page, 'about.html');
    // שינוי בתבנית: על הקנבס (טקסט של האייקון) + "להגדיל את הגופן" ב-style.css של הרכיב
    await page.evaluate(() => { const S = Editor._state, K = window.KolSrc, c = S.canvas, n = c.nodeOf(c.doc.querySelector('.kadisha-lexicon-search-icon')); Editor._act('אייקון', m => K.html.setText(m, K.byPath(m, K.pathOf(n)), '🔍'), n); });
    await page.evaluate(() => { const S = Editor._state, x = [...S.sheets.values()].find(v => v.path === 'components/search-bar/style.css'), t = x.doc.text(); x.doc.edit([{ from: t.length, to: t.length, text: '.kadisha-lex-topic-btn { font-size: 1.25rem; }\n' }], 'גופן'); });
    await sleep(400);
    await page.click('[data-comp-apply]'); await sleep(600);
    st = await page.evaluate(() => [...document.querySelectorAll('[data-upd]')].map(r => ({ id: r.dataset.upd, st: r.dataset.updState, ch: [...r.querySelectorAll('[data-upd-choice]')].map(i => i.value + (i.checked ? '*' : '')), diff: !!r.querySelector('[data-comp-diffview]') })));
    ok(st.length === 3 && st.filter(r => r.st === 'linked').length === 2 && st.find(r => r.st === 'modified' && r.id === 'about.html#1') && st.filter(r => r.st === 'linked').every(r => r.ch.join() === 'update*' && r.diff), 'הרשימה: 3 מופעים (index, about ×2) — המקושרים מסומנים לעדכון, עם ה-diff; השונה — עדכן / נתק / דלג', st);
    await page.click('[data-upd="about.html#1"] [data-upd-choice="update"]'); await sleep(200);
    ok(await page.$('[data-upd="about.html#1"] [data-comp-diffview]') !== null, 'השונה ← "עדכן": מוצג מה יאבד');
    nA = await txN(page);
    await page.click('[data-comp-apply-go]'); await sleep(900);
    const idxA = await text(page, 'index.html'); ab = await text(page, 'about.html');
    ok((idxA.match(/🔍/g) || []).length === 1 && (ab.match(/🔍/g) || []).length === 2 && !/🔎<\/span>/.test(ab), 'החל: כל 3 המופעים קיבלו את השינוי בתבנית, בשני הקבצים');
    ok(/data-kol-filter-value="">כל הנושאים</.test(ab) && !/חיפוש שונה/.test(ab), 'ערכי ההגדרות נשמרים ("כל הנושאים"); השינוי המקומי של המופע השונה — הוחלף (נבחר "עדכן")');
    ok(await txN(page) === nA + 1 && /עדכון מופעים · שורת חיפוש · 3 מופעים ב-2 קבצים/.test(await lastTx(page)), 'כל הקבצים — פעולה אחת בהיסטוריה', await lastTx(page));
    await undo(page);
    ok(await text(page, 'index.html') === idxB && await text(page, 'about.html') === abB, 'Ctrl+Z אחד מחזיר את שני הקבצים');
    await redo(page);
    ok(await text(page, 'about.html') === ab, 'Ctrl+Y — שוב');
    await shot(page, 'v12-1920-template');


    // ═══ 7. כל יעד בסכמה — דרך הפאנל ═══
    const ACC_T = '<section class="acc acc--plain">\n  <h3 class="acc-title">כותרת</h3>\n  <a class="acc-link" href="#">קישור</a>\n  <img class="acc-img" src="media/a.png" alt="">\n  <ul class="acc-list">\n    <li class="acc-item">פריט 1</li>\n    <li class="acc-item">פריט 2</li>\n    <li class="acc-item">פריט 3</li>\n  </ul>\n  <details class="acc-d"><summary>עוד</summary></details>\n</section>\n';
    const ACC_J = { name: 'accordion', label: 'אקורדיון', icon: '▤', category: 'תוכן', fields: [
      { id: 'title', label: 'כותרת', control: 'text', target: { text: '.acc-title' }, default: 'כותרת ברירת מחדל' },
      { id: 'open', label: 'פתוח בהתחלה', control: 'toggle', target: { attr: 'open', on: '.acc-d' } },
      { id: 'gap', label: 'רווח', control: 'size', target: { var: '--acc-gap' } },
      { id: 'style', label: 'סגנון', control: 'select', options: ['plain', 'boxed'], target: { class: 'acc--{value}' } },
      { id: 'items', label: 'פריטים', control: 'repeater', target: { children: '.acc-list > .acc-item' } },
      { id: 'img', label: 'תמונה', control: 'media', target: { src: '.acc-img' } },
      { id: 'href', label: 'קישור', control: 'link', target: { attr: 'href', on: '.acc-link' } },
      { id: 'color', label: 'צבע', control: 'color', target: { var: '--acc-color' } } ] };
    for (const [f, c] of [['template.html', ACC_T], ['component.json', JSON.stringify(ACC_J, null, 2) + '\n'], ['style.css', '.acc { gap: var(--acc-gap, 8px); color: var(--acc-color, #222); }\n.acc-title { font-size: 1.5rem; }\n']]) await call(page, 'pf_create', { path: 'components/accordion/' + f, content: c });
    await openPage(page, 'about.html');
    await select(page, '.about-text');
    await page.click('[data-ntab="comp"]'); await sleep(200);
    await page.click('.ed-lib-item[data-lib="comp-accordion"]'); await sleep(900);
    ab = await text(page, 'about.html');
    ok(/<p class="about-text">עמוד שני.<\/p>\n    <section class="acc acc--plain" data-kol-component="accordion">\n      <h3 class="acc-title">כותרת ברירת מחדל<\/h3>/.test(ab) && (ab.match(/components\/accordion\/style\.css/g) || []).length === 1, 'הוספה: ערך ברירת המחדל (default) של ההגדרה נכתב; <link> של הרכיב השני', ab.slice(ab.indexOf('<p class="about-text"'), ab.indexOf('<p class="about-text"') + 200));
    const AC = '[data-kol-component="accordion"]';
    await select(page, AC); await tab(page, 'content');
    st = await page.evaluate(() => [...document.querySelectorAll('[data-comp-field]')].map(r => r.dataset.compField));
    ok(st.join() === 'title,open,gap,style,items,img,href,color', 'הפאנל: כל השדות, בסדר של הסכמה', st);
    const setF = async (id, v) => { const sel = '[data-comp-field="' + id + '"] [data-cf="' + id + '"]'; const tag = await page.$eval(sel, e => e.localName + (e.type ? ':' + e.type : '')); if (tag.startsWith('select')) await page.selectOption(sel, v); else if (tag === 'input:checkbox') { await page.click(sel); } else { await page.fill(sel, v); await page.press(sel, 'Tab'); } await sleep(450); return text(page, 'about.html'); };
    const accOf = t => { const i = t.indexOf('<section class="acc'); return t.slice(i, t.indexOf('</section>', i) + 10); };
    let a1 = accOf(await setF('title', 'שאלות נפוצות'));
    ok(/<h3 class="acc-title">שאלות נפוצות<\/h3>/.test(a1), 'text: הכותרת');
    a1 = accOf(await setF('open', true));
    ok(/<details class="acc-d" open>/.test(a1), 'attr (toggle): open');
    a1 = accOf(await setF('gap', '24px'));
    ok(/<section class="acc acc--plain" data-kol-component="accordion" style="--acc-gap: 24px">/.test(a1), 'var: --acc-gap ב-style של השורש', a1.slice(0, 120));
    a1 = accOf(await setF('style', 'boxed'));
    ok(/class="acc acc--boxed"/.test(a1) && !/acc--plain/.test(a1), 'class: acc--plain ← acc--boxed (הקודמת מוסרת)');
    a1 = accOf(await setF('img', 'media/b.jpg'));
    ok(/<img class="acc-img" src="media\/b\.jpg"/.test(a1), 'src: התמונה');
    a1 = accOf(await setF('href', 'https://example.org/a?b=1&c=2'));
    ok(/href="https:\/\/example\.org\/a\?b=1&amp;c=2"/.test(a1), 'attr: href (מקודד)');
    await page.evaluate(() => { const i = document.querySelector('[data-cf-color="color"]'); i.value = '#aa3300'; i.dispatchEvent(new Event('change')); }); await sleep(450);
    a1 = accOf(await text(page, 'about.html'));
    ok(/--acc-gap: 24px; --acc-color: #aa3300/.test(a1), 'var (צבע): --acc-color', a1.slice(0, 160));
    ok(await stateOf(page, AC) === 'linked', 'אחרי כל ההגדרות — המופע מקושר');
    const before = await text(page, 'about.html');
    await setF('href', 'javascript:alert(1)');
    const n1 = await page.evaluate(() => document.querySelector('.ed-note.err')?.textContent || '');
    await setF('gap', '1px; } body { display:none');
    const n2 = await page.evaluate(() => document.querySelector('.ed-note.err')?.textContent || '');
    ok(await text(page, 'about.html') === before && /javascript/.test(n1) && /לא תקין/.test(n2), 'הזרקה מהפאנל (javascript:, ; } בערך משתנה) — נדחית עם הסבר, הקובץ לא השתנה', { same: await text(page, 'about.html') === before, n1, n2 });
    // repeater: הפאנל — כפתורים וגרירה
    await select(page, AC); await tab(page, 'content');
    await page.click('[data-rep-add="items"]'); await sleep(450);
    ok((accOf(await text(page, 'about.html')).match(/<li class="acc-item">/g) || []).length === 4, 'repeater: "＋ פריט" ← 4 פריטים');
    await page.click('[data-rep-del="3"]'); await sleep(450);
    await page.click('[data-rep-down="0"]'); await sleep(450);
    let items = () => text(page, 'about.html').then(t => [...accOf(t).matchAll(/<li class="acc-item">([^<]*)<\/li>/g)].map(x => x[1]));
    ok((await items()).join() === 'פריט 2,פריט 1,פריט 3', 'repeater: מחיקה, ↓', await items());
    let g = await (await page.$('[data-rep-grip="2"]')).boundingBox(), r0 = await (await page.$('[data-rep="0"]')).boundingBox();
    await page.mouse.move(g.x + g.width / 2, g.y + g.height / 2); await page.mouse.down(); for (let i = 1; i <= 8; i++) { await page.mouse.move(g.x + g.width / 2, g.y + g.height / 2 + (r0.y + 4 - g.y - g.height / 2) * i / 8); await sleep(30); } await page.mouse.up(); await sleep(500);
    ok((await items()).join() === 'פריט 3,פריט 2,פריט 1' && /סדר בגרירה · פריטים/.test(await lastTx(page)), 'repeater: גרירה בפאנל (⠿) — השלישי לראש הרשימה', await items());
    // גרירה בקנבס (V4): פריט 1 (האחרון) אל לפני הראשון
    await select(page, AC + ' .acc-item', 2);
    g = await (await page.$('[data-grip]')).boundingBox();
    to = await at(page, AC + ' .acc-item', 0, 0.5, 0.2);
    await drag(page, { x: g.x + g.width / 2, y: g.y + g.height / 2 }, to, { shell: true }); await sleep(500);
    ok((await items()).join() === 'פריט 1,פריט 3,פריט 2' && await stateOf(page, AC) === 'linked', 'repeater בקנבס: גרירת פריט (V4) — הסדר השתנה, והמופע מקושר', await items());
    // חלק חסר
    await page.evaluate(() => { const S = Editor._state, K = window.KolSrc, c = S.canvas, n = c.nodeOf(c.doc.querySelector('[data-kol-component="accordion"] .acc-img')); Editor._act('מחיקת תמונה', m => K.html.remove(m, K.byPath(m, K.pathOf(n))), n); }); await sleep(400);
    await select(page, AC); await tab(page, 'content');
    st = await page.evaluate(() => { const r = document.querySelector('[data-comp-field="img"]'); return r && { dis: !!r.querySelector('[data-comp-missing][disabled]'), txt: r.textContent }; });
    ok(st && st.dis && /החלק \.acc-img חסר במופע הזה/.test(st.txt) && await stateOf(page, AC) === 'modified', 'יעד שהבורר שלו חסר: "החלק .acc-img חסר במופע הזה" — מושבת; המופע "שונה"', st);
    await undo(page);
    // component.json שגוי ← שגיאה עם שורה; תיקון ← הפאנל מתעדכן
    const accJ = await text(page, 'components/accordion/component.json');
    await page.evaluate(async () => { const f = Project.docs.byPath('components/accordion/component.json'), d = await Project.docs.open(f.id), t = d.text(); d.edit([{ from: t.indexOf('"fields"'), to: t.indexOf('"fields"'), text: '"oops" ' }], 'שבירה'); }); await sleep(500);
    await select(page, AC); await tab(page, 'content');
    st = await page.evaluate(() => { const e = document.querySelector('[data-comp-json-error]'); return e && { line: e.dataset.compJsonError, t: e.textContent }; });
    ok(st && st.line === '6' && /שורה 6/.test(st.t), 'component.json לא תקין (עריכה בקוד / 📁) ← הפאנל מציג את השגיאה עם שורה', st);
    await page.evaluate(async t => { const f = Project.docs.byPath('components/accordion/component.json'), d = await Project.docs.open(f.id), was = d.text(); d.edit([{ from: 0, to: was.length, text: t.replace('"label": "כותרת"', '"label": "כותרת ראשית"') }], 'תיקון'); }, accJ); await sleep(500);
    await select(page, AC); await tab(page, 'content');
    ok(await page.evaluate(() => !document.querySelector('[data-comp-json-error]') && /כותרת ראשית/.test(document.querySelector('[data-comp-field="title"]').textContent)), 'תיקון ב-component.json ← הפאנל מתעדכן (שם השדה החדש)');

    // ═══ 8. העיצוב: כלל ב-style.css של רכיב ═══
    await select(page, AC + ' .acc-title'); await tab(page, 'design');
    st = await page.evaluate(() => { const e = document.querySelector('[data-comp-shared]'); return e && { n: e.dataset.compShared, t: e.textContent, local: !!e.querySelector('[data-comp-local]'), tgt: document.querySelector('[data-tinfo]').textContent }; });
    ok(st && /components\/accordion\/style\.css/.test(st.tgt) && /ישנה את כל 1 המופעים/.test(st.t) && st.local, 'עיצוב, יעד ב-style.css של הרכיב: "ישנה את כל N המופעים — או כתוב לעמוד הזה בלבד"', st);
    await page.click('[data-comp-local]'); await sleep(300);
    ok(await page.evaluate(() => !/^components\//.test(document.querySelector('[data-tinfo] span[dir="ltr"]').textContent)), '"כתוב לעמוד הזה בלבד" ← היעד עובר לקובץ של העמוד');
    await shot(page, 'v12-1920-design');

    // ═══ 9. "הוסף לכל העמודים" ═══
    await select(page, AC); await tab(page, 'content');
    nA = await txN(page);
    await page.click('[data-w="comp-all"]'); await sleep(900);
    const idxAll = await text(page, 'index.html');
    ok(/data-kol-component="accordion"/.test(idxAll) && (idxAll.match(/components\/accordion\/style\.css/g) || []).length === 1 && await txN(page) === nA + 1, '"הוסף לכל העמודים": index.html קיבל את הרכיב ואת ה-<link> — פעולה אחת', await lastTx(page));
    await undo(page);
    ok(!/data-kol-component="accordion"/.test(await text(page, 'index.html')), 'Ctrl+Z — העמוד האחר חוזר');

    // ═══ 10. מחיקת רכיב ═══
    await page.click('[data-ntab="comp"]'); await sleep(200);
    const abD = await text(page, 'about.html');
    await page.click('[data-comp-del="accordion"]'); await sleep(500);
    st = await page.evaluate(() => { const w = document.querySelector('[data-comp-del-warn]'); return w && w.textContent; });
    ok(st && /נתק את 1 המופעים קודם\?/.test(st), 'מחיקה עם מופע: "נתק את 1 המופעים קודם?"', st);
    await page.click('[data-comp-del-go]'); await sleep(1500);
    ab = await text(page, 'about.html');
    ok(!/data-kol-component="accordion"/.test(ab) && /<section class="acc acc--boxed"/.test(ab) && !(await exists(page, 'components/accordion/component.json')), 'המופע נותק (HTML רגיל נשאר), והקבצים — לסל');
    await undo(page); await sleep(1500);
    ok(await text(page, 'about.html') === abD && await exists(page, 'components/accordion/component.json'), 'Ctrl+Z: הסימון חוזר והקבצים משוחזרים מהסל');


    // ═══ 11. "הגדרות הרכיב" בעריכת התבנית — כל סוג של "הוסף הגדרה" ═══
    await openPage(page, 'components/search-bar/template.html');
    await tab(page, 'content');
    const addS = async (sel, i, kind, label, fill) => {
      await select(page, sel, i); await tab(page, 'content');
      await page.click('[data-w="comp-add-setting"]'); await sleep(250);
      await page.selectOption('[data-as="kind"]', kind); await sleep(100);
      if (fill) await fill();
      await page.fill('[data-as="label"]', label); await page.click('[data-as-go]'); await sleep(700);
      return JSON.parse(await text(page, 'components/search-bar/component.json')).fields.slice(-1)[0];
    };
    await select(page, CS); await tab(page, 'content');
    ok(await page.$('[data-comp-board="search-bar"]') !== null, 'בעריכת התבנית — לוח "⚙ הגדרות הרכיב" בלשונית "תוכן"');
    let fl = await addS('.kadisha-lexicon-search', 0, 'attr', 'טקסט החיפוש', () => page.selectOption('[data-as="attr"]', 'placeholder'));
    ok(fl.control === 'text' && fl.target.attr === 'placeholder' && fl.target.on === '.kadisha-lexicon-search', 'תכונה: placeholder של שדה החיפוש', fl);
    fl = await addS('.kadisha-lexicon-count', 0, 'class', 'גודל המונה', () => page.fill('[data-as="options"]', 'count-sm count-lg'));
    ok(fl.control === 'select' && fl.target.class === '{value}' && fl.target.on === '.kadisha-lexicon-count' && fl.options.join() === 'count-sm,count-lg', 'מחלקה מתוך קבוצה: האפשרויות שהוקלדו', fl);
    fl = await addS('.kadisha-lexicon-topic-filter', 0, 'children', 'הנושאים');
    ok(fl.control === 'repeater' && fl.target.children === '.kadisha-lexicon-topic-filter > .kadisha-lex-topic-btn', 'הילדים — רשימה: repeater', fl);
    fl = await addS('.kadisha-lexicon-controls', 0, 'var', 'צבע הרקע', () => page.selectOption('[data-as="prop"]', 'background-color'));
    const scss = await text(page, 'components/search-bar/style.css');
    ok(fl.control === 'color' && fl.target.var === '--search-bar-' + fl.id.replace(/_/g, '-') && new RegExp('background-color: var\\(' + fl.target.var + ', [^)]+\\)').test(scss), 'משתנה CSS: הערך הפך ל-var(--…, <הערך>) ב-style.css', { fl, css: scss.slice(-200) });
    fl = await addS('.kadisha-lexicon-search-icon', 0, 'text', 'סמל');
    ok(fl.target.text === '.kadisha-lexicon-search-icon', 'טקסט: מחלקה קיימת', fl);
    // חלק בלי מחלקה ← מחלקה חדשה בשם קריא, בתבנית ובמופעים המקושרים
    fl = await addS('.kadisha-lex-scope > span', 0, 'text', 'Label');
    const tplNow = await text(page, 'components/search-bar/template.html'), idxNow = await text(page, 'index.html');
    ok(/^\.search-bar__label$/.test(fl.target.text) && /class="search-bar__label"/.test(tplNow), 'חלק בלי מחלקה: מחלקה חדשה בשם קריא (search-bar__label) — בתבנית', { fl: fl.target, ok: /search-bar__label/.test(tplNow) });
    ok(/<span class="search-bar__label">הרחב את החיפוש/.test(idxNow) && (/<span class="search-bar__label">/.test(await text(page, 'about.html'))), '…והמחלקה החדשה נכתבה גם למופעים המקושרים בעמודים (כדי שיישארו מקושרים)');
    st = await page.evaluate(() => [...document.querySelectorAll('[data-board-field]')].map(x => x.dataset.boardField));
    ok(st.length === 7, 'הלוח: 7 הגדרות, עם יעד וסוג לכל אחת', st);
    await page.click('[data-board-del="' + st[st.length - 1] + '"]'); await sleep(500);
    ok(JSON.parse(await text(page, 'components/search-bar/component.json')).fields.length === 6, 'מחיקת הגדרה מהלוח ← component.json');
    await undo(page);
    await shot(page, 'v12-1920-board');
    await page.click('[data-comp-back]').catch(() => {}); await sleep(1500);

    // ═══ 12. על כל המסך — 1920, 1366; 390 במגע ═══
    await openPage(page, 'index.html');
    await page.click('[data-ntab="comp"]'); await sleep(200);
    await select(page, CS); await tab(page, 'content');
    const fill = pg => pg.evaluate(() => { const r = s => { const e = document.querySelector(s); return e ? e.getBoundingClientRect() : null; }, ed = r('.ed'), nav = r('.ed-navwrap'), lib = r('[data-comp-body]'), grid = r('[data-comp-body] .cm-grid'), panel = r('[data-comp-panel]'), info = r('.ed-info, .ed-side.info, [data-panel-body="info"]') || r('.ct'); return { edW: ed && ed.width, vw: innerWidth, libW: lib && Math.round(lib.width), navW: nav && Math.round(nav.width), gridW: grid && Math.round(grid.width), panelW: panel && Math.round(panel.width), ctW: info && Math.round(info.width), hs: document.documentElement.scrollWidth <= innerWidth }; });
    st = await fill(page);
    ok(st.edW >= st.vw - 20 && st.libW >= st.navW - 24 && st.gridW >= st.libW - 30 && st.panelW >= st.ctW - 40 && st.hs, '1920: העורך על כל המסך; לשונית הרכיבים והלוח ממלאים את הרוחב שלהם (בלי רוחב קבוע שמשאיר ריק)', st);
    await shot(page, 'v12-1920-panel');
    const ctx2 = await mk(1366, 768), p2 = await ctx2.newPage();
    p2.on('pageerror', e => { errors.push(e.message); console.log('   ⚠️ (1366) ' + e.message); });
    await p2.goto(base + '/#/project'); await p2.waitForSelector('.pj-tree .pj-row');
    await openPage(p2, 'index.html'); await p2.click('[data-ntab="comp"]'); await sleep(200); await select(p2, CS); await tab(p2, 'content');
    st = await fill(p2);
    ok(st.edW >= st.vw - 20 && st.libW >= st.navW - 24 && st.gridW >= st.libW - 30 && st.panelW >= st.ctW - 40 && st.hs, '1366: אותו דבר', st);
    await p2.screenshot({ path: path.join(shotDir, 'v12-1366-panel.png') }); await ctx2.close();
    const ctxP = await mk(390, 844), pp = await ctxP.newPage();
    pp.on('pageerror', e => { errors.push(e.message); console.log('   ⚠️ (390) ' + e.message); });
    await pp.goto(base + '/#/project'); await pp.waitForSelector('.pj-tree .pj-row');
    await openPage(pp, 'about.html');
    await pp.evaluate(() => document.querySelector('.ed-bar [data-panel="nav"]').click()); await sleep(300);
    await pp.tap('[data-ntab="comp"]'); await sleep(300);
    const abP = await text(pp, 'about.html'), nP = (abP.match(/data-kol-component="search-bar"/g) || []).length;
    const bt = await pp.$('.ed-lib-item[data-lib="comp-search-bar"]'); const bb = await bt.boundingBox();
    await pp.tap('.ed-lib-item[data-lib="comp-search-bar"]'); await sleep(900);
    const abP2 = await text(pp, 'about.html');
    st = await pp.evaluate(() => ({ hs: document.documentElement.scrollWidth <= innerWidth }));
    ok((abP2.match(/data-kol-component="search-bar"/g) || []).length === nP + 1 && bb.height >= 34 && st.hs, '390 (הקשה): לשונית הרכיבים, הקשה מוסיפה מופע; כפתור ≥34px; בלי גלילה אופקית', { n: nP, bb, st });
    await pp.screenshot({ path: path.join(shotDir, 'v12-390.png') }); await ctxP.close();

    // ═══ 13. הבנייה: template.html מחוץ לחבילה כברירת מחדל ═══
    const php2 = execFileSync('php', ['-r', 'define("KOL_DATA_DIR", $argv[1]); foreach (["db","build"] as $l) require_once $argv[2] . "/lib/$l.php"; echo json_encode([build_is_config("components/a/template.html"), build_is_config("components/a/component.json"), build_is_config("components/a/style.css"), build_is_config("components/a/script.js"), build_is_config("x/components/a/template.html")]);', data, ROOT]).toString();
    ok(php2.trim() === '[true,true,false,false,false]', 'build.php: template.html ו-component.json יוצאים מהחבילה כברירת מחדל; style.css / script.js — נכנסים', php2);

    console.log('\nצילומים: ' + shotDir);
    ok(!errors.length, 'אין שגיאות JavaScript בדף', errors.slice(0, 3));
  } catch (e) { failed++; console.log('❌ חריגה: ' + (e.stack || e.message)); }
  finally { await browser?.close(); php.kill(); }
  console.log('\n' + (failed ? '❌ נכשלו ' + failed + ' מתוך ' + ran : '✅ כל ' + ran + ' הבדיקות עברו'));
  process.exit(failed ? 1 : 0);
})();
