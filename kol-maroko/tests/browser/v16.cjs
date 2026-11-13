/**
 * פרוסה V16 — ספריית הרכיבים המוכנים — בדיקות בדפדפן   node kol-maroko/tests/browser/v16.cjs
 *
 * לכל רכיב מוכן (assets/kit/components/*): הוספה מהספרייה (העתקה בשימוש ראשון — components/<שם>/ ו-kol.json system, Ctrl+Z
 * אחד), עריכת טקסט במקום, הגדרה אחת מהפאנל, מעבר מצב בקנבס (הקובץ זהה), ▶️ תצוגה חיה (ההתנהגות), ובלי JS (התוכן גלוי);
 * שימוש שני (אין העתקה); שם תפוס; עדכון (לא שונה / שונה — שלוש הפעולות); ➕ פריט (1 / 3 / 12); הלוח הרחב ב-1920 / 1366,
 * גרירה ממנו לקנבס; 390 — גיליון, לחיצה ארוכה, "הוסף אחרי הנבחר". צילומים — ל-tmpdir (kol-v16-shots).
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
  const data = fs.mkdtempSync(path.join(os.tmpdir(), 'kol-v16-'));
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
    const shotDir = path.join(os.tmpdir(), 'kol-v16-shots'); fs.mkdirSync(shotDir, { recursive: true });
    const shot = (page, name) => page.screenshot({ path: path.join(shotDir, name + '.png') });
    const CS = '.kadisha-lexicon-controls';

    const ctx = await mk(1920, 1080), page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', e => { errors.push(e.message); console.log('   ⚠️ שגיאה בדף אחרי בדיקה ' + ran + ': ' + e.message + ' ' + String(e.stack || '').split('\n').slice(1, 4).join(' | ').slice(0, 400)); });
    page.on('dialog', d => (d.type() === 'beforeunload' ? d.accept() : d.dismiss()));
    await page.goto(base + '/#/project');
    await page.waitForSelector('.pj-tree .pj-row');
    await call(page, 'pf_migrate', {});
    const PAGE = '<!doctype html>\n<html lang="he" dir="rtl">\n<head>\n  <meta charset="utf-8">\n  <meta name="viewport" content="width=device-width, initial-scale=1">\n  <title>רכיבים</title>\n  <link rel="stylesheet" href="css/tokens.css">\n</head>\n<body>\n  <main class="kit-page">\n    <h1 class="kit-h">רכיבים מוכנים</h1>\n  </main>\n</body>\n</html>\n';
    await call(page, 'pf_create', { path: 'kit.html', content: PAGE });
    await openPage(page, 'kit.html');
    await page.click('[data-dev="desktop"]'); await sleep(300);
    const compTab = async pg => { await pg.evaluate(() => document.querySelector('[data-ntab="comp"]').click()); await sleep(300); };
    await compTab(page);
    await page.waitForFunction(() => Editor._state.kit && Editor._state.kit.items.length >= 20, null, { timeout: 15000 });
    const items = await page.evaluate(() => Editor._state.kit.items.map(x => ({ name: x.name, label: x.label, category: x.category, version: x.version, js: x.js, external: x.external })));
    ok(items.length >= 20, 'הספרייה: ' + items.length + ' רכיבים מוכנים מ-kit_list', items.map(x => x.name));
    const kol = async pg => JSON.parse(await text(pg, 'kol.json'));
    const instText = (pg, name, i = 0) => pg.evaluate(([n, i]) => { const S = Editor._state, c = S.canvas, C = window.KolComp; const inst = C.instances(c.m, n)[i]; return inst ? C.outerOf(c.m, inst.node) : null; }, [name, i]);
    const selInst = (pg, name, i = 0, part) => pg.evaluate(([n, i, part]) => { const c = Editor._state.canvas, C = window.KolComp, inst = C.instances(c.m, n)[i]; if (!inst) return false; let t = inst.node; if (part) t = C.findIn(inst.node, part) || t; c.select(t); c.reveal(t); return true; }, [name, i, part || null]);
    const selMain = pg => select(pg, 'main.kit-page');

    // ═══ 1. הספרייה: חיפוש, קטגוריות, תמונה מקדימה, תגים ═══
    let st = await page.evaluate(() => { const b = document.querySelector('[data-kit]'); return { cards: b.querySelectorAll('.kit-card').length, prev: b.querySelectorAll('.kit-card img.kit-prev').length, cats: [...b.querySelectorAll('[data-kit-cat]')].map(x => x.dataset.kitCat), tags: [...new Set([...b.querySelectorAll('.kit-tag')].map(x => x.dataset.kitState))] }; });
    ok(st.cards === items.length && st.prev === items.length && st.cats.length === 10 && st.tags.join() === 'kit' && items.length >= 32, 'בלשונית "🧩 רכיבים": כרטיס לכל רכיב עם תמונה מקדימה, "הכול" + 9 קטגוריות, תג "יועתק לפרויקט"', st);
    await page.fill('[data-kit-q="lib"]', 'אקורד'); await sleep(200);
    st = await page.evaluate(() => [...document.querySelectorAll('[data-kit-grid="lib"] .kit-card')].map(x => x.dataset.kitItem));
    ok(st[0] === 'accordion' && st.length <= 2 && !st.includes('counter'), 'חיפוש "אקורד" ← אקורדיון (ולשוניות, שבטלפון הן אקורדיון)', st);
    await page.fill('[data-kit-q="lib"]', ''); await page.click('[data-kit-cat="מדיה"]'); await sleep(200);
    st = await page.evaluate(() => [...document.querySelectorAll('[data-kit-grid="lib"] .kit-card')].map(x => x.dataset.kitItem));
    ok(st.length === 5 && st.includes('lightbox') && !st.includes('tabs'), 'קטגוריה "מדיה" ← 5 רכיבים', st);
    await page.click('[data-kit-cat="all"]'); await sleep(200);

    // ═══ 2. שימוש ראשון: העתקה + הוספה, פעולה אחת; Ctrl+Z / Ctrl+Y ═══
    await selMain(page);
    let n0 = await txN(page);
    await page.click('[data-kit-item="accordion"]'); await sleep(2200);
    let pg = await text(page, 'kit.html'), kj = await kol(page);
    ok(/<section class="kol-acc kol-acc--one" data-kol-component="accordion">/.test(pg) && /<link rel="stylesheet" href="components\/accordion\/style\.css">/.test(pg) && /<script defer src="components\/accordion\/script\.js"><\/script>/.test(pg), 'העמוד: המופע (data-kol-component), <link> ו-<script defer> פעם אחת', pg.slice(0, 900));
    ok(await exists(page, 'components/accordion/template.html') && await exists(page, 'components/accordion/script.js') && !await exists(page, 'components/accordion/kit.json'), 'components/accordion/ הועתק (בלי kit.json)');
    ok(kj.system['components/accordion/'] && kj.system['components/accordion/'].version === items.find(x => x.name === 'accordion').version && kj.system['components/accordion/'].modified === false && kj.system['js/kol-data.js'], 'kol.json system: "components/accordion/": {version, modified: false} — וקובצי המערכת האחרים נשמרו', kj.system);
    ok(await txN(page) === n0 + 1 && /רכיב מוכן · אקורדיון · הועתק לפרויקט/.test(await lastTx(page)), 'פעולה אחת: "רכיב מוכן · אקורדיון · הועתק לפרויקט"', await lastTx(page));
    st = await page.evaluate(() => document.querySelector('[data-kit-item="accordion"]').dataset.kitTag);
    ok(st === 'in', 'התג בספרייה ← "בפרויקט"', st);
    await undo(page); await sleep(1500);
    pg = await text(page, 'kit.html'); kj = await kol(page);
    ok(!/kol-acc/.test(pg) && !/components\/accordion/.test(pg) && !await exists(page, 'components/accordion/template.html') && !kj.system['components/accordion/'], 'Ctrl+Z אחד: המופע, ה-link / script, הקבצים והרשומה — כולם חזרו', { pg: pg.slice(0, 300), sys: kj.system });
    await redo(page); await sleep(2000);
    pg = await text(page, 'kit.html'); kj = await kol(page);
    ok(/data-kol-component="accordion"/.test(pg) && await exists(page, 'components/accordion/template.html') && kj.system['components/accordion/'], 'Ctrl+Y: חזר הכול');
    // שימוש שני: אין העתקה
    const files0 = await page.evaluate(async () => (await Project.docs.reload()).length);
    await selMain(page); n0 = await txN(page);
    await page.click('[data-kit-item="accordion"]'); await sleep(1500);
    pg = await text(page, 'kit.html');
    ok((pg.match(/data-kol-component="accordion"/g) || []).length === 2 && (pg.match(/components\/accordion\/style\.css/g) || []).length === 1 && await page.evaluate(async () => (await Project.docs.reload()).length) === files0 && !/הועתק/.test(await lastTx(page)), 'שימוש שני: מופע שני — בלי העתקה, בלי <link> נוסף', await lastTx(page));
    await undo(page); await sleep(800);

    // ═══ 3. שם תפוס ═══
    await call(page, 'pf_create', { path: 'components/quote/component.json', content: '{"name":"quote","label":"הציטוט שלי","fields":[]}' });
    await call(page, 'pf_create', { path: 'components/quote/template.html', content: '<blockquote class="my-quote">שלי</blockquote>\n' });
    await page.evaluate(async () => { await Project.docs.reload(); await Editor._state.comp.load(); await Editor._state.kit.load(); }); await sleep(500);
    st = await page.evaluate(() => document.querySelector('[data-kit-item="quote"]').dataset.kitTag);
    ok(st === 'taken', 'components/quote/ של בעל העמוד ← התג "השם תפוס"', st);
    await selMain(page);
    await page.click('[data-kit-item="quote"]'); await sleep(500);
    st = await page.evaluate(() => ({ open: document.getElementById('sheet').open, mine: !!document.querySelector('[data-kit-mine]'), as: document.querySelector('[data-kit-as]')?.dataset.kitAs }));
    ok(st.open && st.mine && st.as === 'quote-2', '"השתמש בשלך" / "הוסף את המוכן בשם quote-2"', st);
    await page.click('[data-kit-as]'); await sleep(2200);
    pg = await text(page, 'kit.html'); kj = await kol(page);
    ok(/data-kol-component="quote-2"/.test(pg) && /components\/quote-2\/style\.css/.test(pg) && kj.system['components/quote-2/'] && kj.system['components/quote-2/'].kit === 'quote' && (await text(page, 'components/quote/template.html')).includes('my-quote'), 'הוסף בשם quote-2: components/quote-2/, הרשומה עם kit: "quote" — והרכיב של בעל העמוד לא נגע', kj.system['components/quote-2/']);
    const jq = JSON.parse(await text(page, 'components/quote-2/component.json'));
    ok(jq.name === 'quote-2', 'component.json של העותק — name: "quote-2" (שם התיקייה), כדי שיהיה רכיב תקין', jq.name);
    ok(await stateOf(page, '.kol-quote') === 'linked', 'המופע של quote-2 — "מקושר"');

    // ═══ 4. לכל רכיב: הוספה · טקסט במקום · הגדרה מהפאנל · מצב בקנבס (הקובץ זהה) ═══
    const SKIP_ADDED = new Set(['accordion', 'quote']);
    for (const it of items) {
      const name = it.name === 'quote' ? 'quote-2' : it.name;
      if (!SKIP_ADDED.has(it.name)) {
        await selMain(page);
        await page.click('[data-kit-item="' + it.name + '"]'); await sleep(1600);
      }
      await tab(page, 'content');
      const html = await instText(page, name);
      ok(html && (await stateOf(page, '[data-kol-component="' + name + '"]')) === 'linked', it.name + ': נוסף מהספרייה — מופע "מקושר"', html && html.slice(0, 200));
      if (!html) continue;
      // טקסט במקום: האלמנט הראשון במופע עם טקסט משלו
      st = await page.evaluate(n => {
        const S = Editor._state, c = S.canvas, K = window.KolSrc, C = window.KolComp, T = window.KolText, inst = C.instances(c.m, n)[0]; let hit = null;
        K.walk(inst.node, x => { if (!hit && !x.implied && x.tag && T.editable(x) && T.ownText(x) && !T.blocker(c.m, x)) hit = x; });
        if (!hit) return null; c.select(hit); c.reveal(hit); return { ok: S.text.begin(hit), before: K.textOf(c.m, hit), path: K.pathOf(hit) };
      }, name);
      if (st && st.ok) {
        await page.keyboard.press('Control+End'); await page.keyboard.type('✓'); await page.keyboard.press('Escape'); await sleep(250);
        const after = await page.evaluate(p => { const m = Editor._state.canvas.m, n = window.KolSrc.byPath(m, p); return window.KolSrc.textOf(m, n) + (/✓/.test(window.KolComp.outerOf(m, n)) && !/✓/.test(window.KolSrc.textOf(m, n)) ? '✓' : ''); }, st.path);
        ok(after === st.before + '✓', it.name + ': טקסט נערך במקום ("' + st.before.slice(0, 24) + '")', after);
        await undo(page);
      } else ok(it.name === 'anchor', it.name + ': אין טקסט לעריכה במקום' + (it.name === 'anchor' ? ' — עוגן הוא נקודה בלי טקסט (תקין)' : ''), st);
      // הגדרה אחת מהפאנל (השדה הראשון שאינו repeater)
      await selInst(page, name);
      await tab(page, 'content');
      const f = await page.evaluate(n => { const cp = Editor._state.comp.comp(n); const f = cp.schema.fields.find(x => window.KolComp.kindOf(x) !== 'children' && !x.bad); return f && { id: f.id, control: f.control, options: f.options }; }, name);
      const before = await text(page, 'kit.html');
      if (f.control === 'select') await page.selectOption('[data-comp-panel] [data-cf="' + f.id + '"]', f.options[f.options.length - 1]);
      else if (f.control === 'toggle') await page.click('[data-comp-panel] [data-cf="' + f.id + '"]');
      else { const v = { size: '20px', number: '3', color: '#225588', media: 'media/x.png', link: 'https://example.com/v16' }[f.control] || 'ערך V16'; await page.fill('[data-comp-panel] [data-cf="' + f.id + '"]', f.target === 'until' ? '2030-01-01T10:00:00+02:00' : v); await page.press('[data-comp-panel] [data-cf="' + f.id + '"]', 'Enter'); await page.evaluate(id => document.querySelector('[data-comp-panel] [data-cf="' + id + '"]').dispatchEvent(new Event('change')), f.id); }
      await sleep(400);
      const after = await text(page, 'kit.html');
      ok(after !== before && /^הגדרה · /.test(await lastTx(page)) && (await stateOf(page, '[data-kol-component="' + name + '"]')) === 'linked', it.name + ': הגדרה מהפאנל (' + f.id + ' · ' + f.control + ') נכתבה — והמופע עדיין "מקושר"', await lastTx(page));
      await undo(page);
      // מצב בקנבס: DOM בלבד, הקובץ זהה
      const states = await page.evaluate(n => Editor._state.kit.raw(n).states.map(s => s.id), name);
      if (states.length) {
        await selInst(page, name); await tab(page, 'content');
        const f0 = await text(page, 'kit.html'), dom0 = await page.evaluate(n => Editor._state.canvas.elOf(window.KolComp.instances(Editor._state.canvas.m, n)[0].node).outerHTML, name);
        const opts = await page.evaluate(id => [...document.querySelector('[data-kit-state="' + id + '"]').options].map(o => o.value), states[0]);
        const pick = opts.find(v => v === '1') || opts[opts.length - 1];
        await page.selectOption('[data-kit-state="' + states[0] + '"]', pick); await sleep(300);
        const dom1 = await page.evaluate(n => Editor._state.canvas.elOf(window.KolComp.instances(Editor._state.canvas.m, n)[0].node).outerHTML, name);
        ok(dom1 !== dom0 && await text(page, 'kit.html') === f0, it.name + ': מצב בקנבס "' + states[0] + '" ← ' + pick + ' — הקנבס השתנה, הקובץ זהה', { len: [dom0.length, dom1.length] });
        // ומעבר מצבים מהשבב שעל הקנבס (גם במגע)
        const chip = await page.$('[data-kit-cycle="' + states[0] + '"]');
        if (chip) { await page.evaluate(id => document.querySelector('[data-kit-cycle="' + id + '"]').click(), states[0]); await sleep(200); }
        ok(chip && await text(page, 'kit.html') === f0, it.name + ': השבב "👁 מצב" על הקנבס מחליף מצב — הקובץ זהה');
        await page.selectOption('[data-kit-state="' + states[0] + '"]', ''); await sleep(200);
        const dom2 = await page.evaluate(n => Editor._state.canvas.elOf(window.KolComp.instances(Editor._state.canvas.m, n)[0].node).outerHTML, name);
        ok(dom2 === dom0, it.name + ': "כמו בקובץ" ← הקנבס חזר בדיוק');
      }
    }
    await shot(page, 'v16-1920-all');

    // ═══ 5. ➕ פריט (בשבב על הקנבס ובפאנל) — לשוניות ל-12, אקורדיון ל-1 ═══
    await selInst(page, 'tabs', 0, '.kol-tabs__item'); await sleep(200);
    ok(await page.$('[data-kit-chip-add]') !== null && await page.$('[data-kit-add-item]') !== null, '"➕ פריט" — בשבב שעל הקנבס ובפאנל, כשנבחר חלק ברכיב');
    for (let k = 0; k < 9; k++) { await page.evaluate(() => document.querySelector('[data-kit-chip-add]').click()); await sleep(250); }
    let cnt = await page.evaluate(() => { const c = Editor._state.canvas, C = window.KolComp, r = C.instances(c.m, 'tabs')[0].node; return C.findAllIn(r, '.kol-tabs__item').length; });
    ok(cnt === 12 && await stateOf(page, '.kol-tabs') === 'linked', '"➕ פריט" ×9 ← 12 לשוניות, המופע "מקושר"', cnt);
    ok(/^הוספת פריט · /.test(await lastTx(page)), 'כל הוספה — פעולה אחת עם שם', await lastTx(page));
    await selInst(page, 'accordion'); await tab(page, 'content');
    for (let k = 0; k < 2; k++) { await page.click('[data-comp-panel] [data-rep-del="0"]'); await sleep(250); }
    cnt = await page.evaluate(() => { const c = Editor._state.canvas, C = window.KolComp, r = C.instances(c.m, 'accordion')[0].node; return C.findAllIn(r, '.kol-acc__item').length; });
    ok(cnt === 1, 'אקורדיון — פריט אחד (🗑 בפאנל)', cnt);
    await page.click('[data-kit-add-item]'); await page.click('[data-kit-add-item]'); await sleep(300);
    cnt = await page.evaluate(() => { const c = Editor._state.canvas, C = window.KolComp, r = C.instances(c.m, 'accordion')[0].node; return C.findAllIn(r, '.kol-acc__item').length; });
    ok(cnt === 3, '"➕ פריט" בפאנל ← 3 פריטים', cnt);
    await flush(page);

    st = await page.evaluate(() => { const k = Editor._state.kit; return [k.okName('attr', 'open'), k.okName('class', 'is-active'), k.okName('attr', 'onclick'), k.okName('attr', 'style'), k.okName('attr', 'src'), k.okName('attr', 'srcdoc'), k.okName('class', 'a b'), k.okName('class', '"><x')]; });
    ok(st.join() === 'true,true,false,false,false,false,false,false', 'מצבים: שם תכונה / מחלקה מ-component.json נבדק — on*, style, src, srcdoc ושמות לא תקינים אינם נכתבים לקנבס', st);

    // ═══ 6. ▶️ תצוגה חיה: ההתנהגות של כל רכיב (JS של הרכיב רץ) ═══
    const tok = (await call(page, 'pv_token')).token, pvUrl = `${base}/preview/${tok}/kit.html`;
    const pv = await ctx.newPage(); const pvErr = []; pv.on('pageerror', e => pvErr.push(e.message));
    const pvExt = [];   // כל בקשה החוצה — נרשמת ונחסמת
    await pv.route(u => !u.href.startsWith(base) && !/^(data|blob|about):/.test(u.protocol), r => { pvExt.push(r.request().url()); return r.abort(); });   // שום רשת חיצונית — הנגן של YouTube אינו נטען (הבדיקה: רק שה-iframe נוצר כמו שצריך)
    await pv.goto(pvUrl); await sleep(800);
    const q = (s, fn, arg) => pv.evaluate(([s, fn, arg]) => { const e = document.querySelector(s); return e ? new Function('e', 'arg', 'return (' + fn + ')(e, arg)')(e, arg) : '∅'; }, [s, fn.toString(), arg]);
    const vis = (s) => pv.evaluate(s => { const e = document.querySelector(s); if (!e) return false; const r = e.getBoundingClientRect(); return r.width > 0 && r.height > 0 && getComputedStyle(e).visibility !== 'hidden'; }, s);
    const into = s => pv.evaluate(s => document.querySelector(s)?.scrollIntoView({ block: 'center' }), s);
    // לשוניות (12): הלשונית ה-12
    await into('.kol-tabs'); await pv.click('.kol-tabs > .kol-tabs__item:nth-child(12) > .kol-tabs__tab'); await sleep(200);
    st = await pv.evaluate(() => { const its = [...document.querySelectorAll('.kol-tabs > .kol-tabs__item')]; return { ready: document.querySelector('.kol-tabs').classList.contains('is-ready'), act: its.map(x => x.classList.contains('is-active')).indexOf(true), vis: its.map(x => getComputedStyle(x.querySelector('.kol-tabs__panel')).display !== 'none').filter(Boolean).length, exp: its[11].querySelector('.kol-tabs__tab').getAttribute('aria-expanded'), hash: location.hash }; });
    ok(st.ready && st.act === 11 && st.vis === 1 && st.exp === 'true' && !st.hash, 'לשוניות (12 פריטים): לחיצה על ה-12 ← רק הלוח שלה גלוי, aria-expanded, בלי קפיצה', st);
    await pv.keyboard.press('ArrowLeft'); await sleep(100);
    st = await pv.evaluate(() => [...document.querySelectorAll('.kol-tabs > .kol-tabs__item')].findIndex(x => x.classList.contains('is-active')));
    ok(st === 0 || st === 10, 'לשוניות: חץ במקלדת עובר ללשונית הסמוכה (RTL)', st);
    // אקורדיון (3, "one"): פתיחת השני סוגרת את הראשון
    await into('.kol-acc'); await pv.click('.kol-acc > .kol-acc__item:nth-of-type(2) > summary'); await sleep(200);
    st = await pv.evaluate(() => [...document.querySelectorAll('.kol-acc > .kol-acc__item')].map(d => d.open));
    ok(st.join() === 'false,true,false', 'אקורדיון (one): פתיחת פריט סוגרת את האחרים', st);
    // מתג
    await into('.kol-switch'); await pv.click('.kol-switch__ctl'); await sleep(150);
    st = await pv.evaluate(() => ({ a: getComputedStyle(document.querySelector('.kol-switch__a')).display, b: getComputedStyle(document.querySelector('.kol-switch__b')).display }));
    ok(st.a === 'none' && st.b === 'block', 'מתג: לחיצה ← מצב ב\' גלוי, א\' מוסתר', st);
    // קרא עוד
    await into('.kol-more');
    st = await pv.evaluate(() => { const r = document.querySelector('.kol-more'), b = r.querySelector('.kol-more__body'); return { ready: r.classList.contains('is-ready'), short: r.classList.contains('is-short'), clipped: b.scrollHeight > b.clientHeight + 2, btn: getComputedStyle(r.querySelector('.kol-more__btn')).display }; });
    ok(st.ready && st.clipped && st.btn !== 'none', 'קרא עוד: מקופל לגובה, והכפתור גלוי', st);
    await pv.click('.kol-more__btn'); await sleep(150);
    st = await pv.evaluate(() => { const r = document.querySelector('.kol-more'), b = r.querySelector('.kol-more__body'); return { open: r.classList.contains('is-open'), full: b.scrollHeight <= b.clientHeight + 2, exp: r.querySelector('.kol-more__btn').getAttribute('aria-expanded') }; });
    ok(st.open && st.full && st.exp === 'true', 'קרא עוד: לחיצה ← פתוח במלואו, aria-expanded', st);
    // התראה
    await into('.kol-alert'); await pv.click('.kol-alert__close'); await sleep(100);
    ok(!(await vis('.kol-alert')), 'התראה: ✕ סוגר');
    // flip
    await into('.kol-flip'); await pv.focus('.kol-flip'); await sleep(700);
    st = await q('.kol-flip__inner', e => getComputedStyle(e).transform);
    ok(st !== 'none' && st !== '∅', 'קופסה מתהפכת: פוקוס / ריחוף ← מתהפכת (transform)', st);
    // מונה
    await into('.kol-counter'); await sleep(2200);
    st = await q('.kol-counter__num', e => e.textContent);
    ok(st === '1,496', 'מונה: סופר כשנכנס למסך, ומסיים בדיוק בטקסט שבקובץ', st);
    // פס התקדמות
    await into('.kol-progress'); await sleep(1600);
    st = await q('.kol-progress', e => ({ in: e.classList.contains('is-in'), w: e.querySelector('.kol-progress__bar').getBoundingClientRect().width / e.querySelector('.kol-progress__track').getBoundingClientRect().width }));
    ok(st.in && Math.abs(st.w - 0.72) < 0.03, 'פס התקדמות: מתמלא ל-72% בכניסה למסך', st);
    // ספירה לאחור
    st = await q('.kol-countdown', e => ({ ready: e.classList.contains('is-ready'), d: +e.querySelector('[data-kol-unit="d"]').textContent, s: e.querySelector('[data-kol-unit="s"]').textContent, done: e.classList.contains('is-done') }));
    ok(st.ready && st.d > 0 && /^\d\d$/.test(st.s) && !st.done, 'ספירה לאחור: ימים / שניות מתעדכנים, לא הסתיים', st);
    await pv.evaluate(() => { const e = document.querySelector('.kol-countdown'); e.setAttribute('data-kol-until', '2001-01-01T00:00:00Z'); e.kolCd = false; }); await pv.addScriptTag({ path: path.join(ROOT, 'assets/kit/components/countdown/script.js') }).catch(() => null); await sleep(300);
    // רכיב עם רקע משלו — גם צבע טקסט משלו: כל העמוד בתוך "מקטע כהה" (טקסט לבן), וכל טקסט על רקע בהיר של רכיב — כהה
    st = await pv.evaluate(() => {
      const b = document.body, was = b.getAttribute('style'); b.style.color = '#fff'; b.style.background = '#1b1b1f';
      const lum = c => { const m = c.match(/[\d.]+/g); if (!m) return null; const [r, g, bl, a = 1] = m.map(Number); return a < .5 ? null : (0.2126 * r + 0.7152 * g + 0.0722 * bl) / 255; };
      const bad = [];
      for (const root of document.querySelectorAll('[data-kol-component]')) for (const e of [root, ...root.querySelectorAll('*')]) {
        if (![...e.childNodes].some(n => n.nodeType === 3 && n.textContent.trim())) continue;
        const r = e.getBoundingClientRect(); if (!r.width || getComputedStyle(e).visibility === 'hidden') continue;
        let bg = null; for (let x = e; x && x !== root.parentElement; x = x.parentElement) { const l = lum(getComputedStyle(x).backgroundColor); if (l != null) { bg = l; break; } }
        if (bg != null && bg > .6 && lum(getComputedStyle(e).color) > .5) bad.push(root.getAttribute('data-kol-component') + ' · ' + e.className);
      }
      if (was == null) b.removeAttribute('style'); else b.setAttribute('style', was);
      return [...new Set(bad)];
    });
    ok(!st.length, 'בתוך מקטע כהה: אין טקסט בהיר על רקע בהיר של רכיב (רקע משלו ← צבע טקסט משלו)', st);
    // דירוג
    st = await q('.kol-stars__bar', e => Math.round(e.getBoundingClientRect().width));
    ok(st === 110, 'דירוג כוכבים: 5 כוכבים של 22px', st);
    // וידאו מוטמע
    await into('.kol-embed'); await sleep(400);
    st = await pv.evaluate(() => ({ frames: document.querySelectorAll('iframe').length, ext: performance.getEntriesByType('resource').map(e => e.name).filter(u => !u.startsWith(location.origin) && !/^(data|blob):/.test(u)) }));
    ok(st.frames === 0 && !st.ext.length && !pvExt.length, 'וידאו מוטמע: לפני לחיצה — אין iframe בעמוד, ואין שום בקשה החוצה (גם לא תמונה מ-YouTube)', { ...st, pvExt });
    await pv.click('.kol-embed__link'); await sleep(300);
    st = await pv.evaluate(() => { const f = document.querySelector('.kol-embed__frame'); return f && { src: f.getAttribute('src'), sb: f.getAttribute('sandbox'), title: f.title }; });
    ok(st && /^https:\/\/www\.youtube-nocookie\.com\/embed\/dQw4w9WgXcQ\?/.test(st.src) && /allow-scripts/.test(st.sb) && !/allow-top-navigation/.test(st.sb) && st.title === 'צפייה בסרטון', 'וידאו מוטמע: רק אחרי לחיצה — iframe של youtube-nocookie בארגז חול, שם מהתבנית', st);
    // שמע
    st = await q('.kol-audio', e => ({ ready: e.classList.contains('is-ready'), native: getComputedStyle(e.querySelector('.kol-audio__el')).display, btn: getComputedStyle(e.querySelector('.kol-audio__play')).display }));
    ok(st.ready && st.native === 'none' && st.btn !== 'none', 'נגן שמע: הנגן המעוצב, וה-<audio> הרגיל מוסתר', st);
    // גלריה
    st = await q('.kol-gallery', e => getComputedStyle(e).gridTemplateColumns.split(' ').length);
    ok(st === 3, 'גלריה: רשת של 3 עמודות', st);
    // lightbox
    await into('.kol-lightbox'); await pv.click('.kol-lightbox__item:nth-child(2)'); await sleep(200);
    st = await pv.evaluate(() => ({ open: document.querySelector('.kol-lightbox__dlg').open, cap: document.querySelector('.kol-lightbox__cap').textContent }));
    ok(st.open && st.cap === 'תמונה 2', 'lightbox: לחיצה ← החלון נפתח על התמונה, הכיתוב מה-alt', st);
    await pv.keyboard.press('ArrowLeft'); await sleep(100);
    const cap2 = await q('.kol-lightbox__cap', e => e.textContent);
    await pv.keyboard.press('Escape'); await sleep(150);
    ok(cap2 === 'תמונה 3' && !(await q('.kol-lightbox__dlg', e => e.open)), 'lightbox: חץ ← התמונה הבאה (RTL); Esc ← נסגר', cap2);
    for (const s of ['.kol-iconbox', '.kol-imgbox', '.kol-iconlist', '.kol-quote', '.kol-cta', '.kol-video']) ok(await vis(s), 'תצוגה חיה: ' + s + ' מוצג');
    ok(!pvErr.filter(m => !/network error/i.test(m)).length, 'אין שגיאות JS בתצוגה החיה (הרשת החיצונית חסומה בבדיקה — רק "network error" של המסגרת)', pvErr);
    await pv.close();

    // ═══ 7. בלי JS: התוכן גלוי ═══
    const ctxNo = await browser.newContext({ viewport: { width: 1280, height: 900 }, javaScriptEnabled: false, extraHTTPHeaders: { 'X-Test-User': JSON.stringify(OWNER) } });
    const nj = await ctxNo.newPage(); await nj.goto(pvUrl); await sleep(500);
    st = await nj.evaluate(() => [...document.querySelectorAll('[data-kol-component]')].map(e => ({ n: e.getAttribute('data-kol-component'), h: Math.round(e.getBoundingClientRect().height), t: e.innerText.trim().length, img: [...e.querySelectorAll('img')].some(i => i.getBoundingClientRect().height > 20) })).filter(x => x.n !== 'anchor' && !(x.h > 10 && (x.t > 0 || x.img))));
    ok(!st.length, 'בלי JS: כל מופע גלוי — עם הטקסט / התמונות שלו', st);
    st = await nj.evaluate(() => ({
      tab1: getComputedStyle(document.querySelector('.kol-tabs__panel')).display, tab2: getComputedStyle(document.querySelectorAll('.kol-tabs__panel')[1]).display,
      acc: [...document.querySelectorAll('.kol-acc__item > summary')].every(x => x.getBoundingClientRect().height > 0), title: document.querySelector('.kol-audio__title').getBoundingClientRect().height > 0, counter: document.querySelector('.kol-counter__num').textContent,
      more: (() => { const b = document.querySelector('.kol-more__body'); return b.scrollHeight <= b.clientHeight + 2; })(),
      date: document.querySelector('.kol-countdown__date').getBoundingClientRect().height > 0, units: getComputedStyle(document.querySelector('.kol-countdown__units')).display,
      audio: document.querySelector('.kol-audio__el').getBoundingClientRect().height > 0, lb: document.querySelector('.kol-lightbox__item').getAttribute('href').length > 10,
      embed: document.querySelector('.kol-embed__link').getAttribute('href'), alertX: getComputedStyle(document.querySelector('.kol-alert__close')).display, progress: document.querySelector('.kol-progress__bar').getBoundingClientRect().width > 0,
    }));
    ok(st.tab1 === 'block' && st.tab2 === 'none' && st.acc === true && st.title && st.counter === '1,496' && st.more && st.date && st.units === 'none' && st.audio && st.lb && /youtube\.com/.test(st.embed) && st.alertX === 'none' && st.progress,
      'בלי JS: לשונית ראשונה פתוחה · <details> — הכותרות · שם ההקלטה · המונה — המספר הסופי · "קרא עוד" — הכול גלוי · התאריך · <audio> רגיל · קישורים לתמונות ולסרטון · בלי ✕ · הפס מלא', st);
    await nj.goto(pvUrl + '#kol-tab-2'); await sleep(300);
    st = await nj.evaluate(() => { const ps = [...document.querySelectorAll('.kol-tabs__panel')]; return { on: ps.filter(p => getComputedStyle(p).display !== 'none').map(p => p.id), ids: new Set(ps.map(p => p.id)).size, n: ps.length, href: [...document.querySelectorAll('.kol-tabs__tab')].every((a, i) => a.getAttribute('href') === '#' + ps[i].id) }; });
    ok(st.on.join() === 'kol-tab-2' && st.ids === st.n && st.href, 'בלי JS: קישור ללוח (#kol-tab-2) פותח אותו (:target); "➕ פריט" נתן לכל לוח id משלו, והקישור שלו מתעדכן', st);
    await ctxNo.close();

    // ═══ 8. הלוח הרחב: 1920 / 1366 — רשת על כל רוחב הקנבס; גרירה ממנו לנקודה בקנבס עם קו היעד ═══
    for (const [W, H] of [[1920, 1080], [1366, 768]]) {
      await page.setViewportSize({ width: W, height: H }); await sleep(500);
      await page.click('[data-kit-wide]'); await sleep(400);
      st = await page.evaluate(() => { const b = document.querySelector('[data-kit-board]'), m = document.querySelector('.ed-mid'), g = b.querySelector('.kit-grid'), cards = [...g.querySelectorAll('.kit-card')], br = b.getBoundingClientRect(), mr = m.getBoundingClientRect(), gr = g.getBoundingClientRect();
        const xs = cards.map(c => c.getBoundingClientRect()); return { vis: !b.hidden, bw: Math.round(br.width), mw: Math.round(mr.width), cols: getComputedStyle(g).gridTemplateColumns.split(' ').length, left: Math.round(Math.min(...xs.map(r => r.left)) - gr.left), right: Math.round(gr.right - Math.max(...xs.map(r => r.right))), h: Math.round(br.height) }; });
      ok(st.vis && Math.abs(st.bw - st.mw) < 2 && st.cols >= (W > 1500 ? 6 : 4) && st.left < 24 && st.right < 24, W + ': "⤢ לוח רחב" — על כל רוחב אזור הקנבס, ' + st.cols + ' עמודות, בלי ריק בצדדים', st);
      await shot(page, 'v16-' + W + '-board');
      // גרירה: הלוח מתקפל, קו היעד, השחרור מוסיף (עם העתקה — הרכיב כבר בפרויקט: מופע שני)
      const before = (await text(page, 'kit.html')).match(/data-kol-component="counter"/g).length;
      const from = await page.evaluate(() => { const e = document.querySelector('[data-kit-grid="board"] [data-kit-item="counter"]'); e.scrollIntoView({ block: 'nearest' }); const r = e.getBoundingClientRect(); return { x: r.left + r.width / 2, y: r.top + r.height / 2 }; });
      const to = await at(page, '.kit-h', 0, 0.5, 0.9);
      await page.evaluate(PD);
      await page.evaluate(([x, y]) => window.__pd.down(x, y, false, true), [from.x, from.y]);
      for (let k = 1; k <= 8; k++) { await page.evaluate(([x, y]) => window.__pd.move(x, y), [from.x + (to.x - from.x) * k / 8, from.y + (to.y - from.y) * k / 8]); await sleep(30); }
      await sleep(150);
      st = await page.evaluate(() => ({ drag: !!Editor._state.build.drag.active, line: !document.querySelector('.dg-line').hidden, folded: document.querySelector('[data-kit-board]').classList.contains('kit-dragging') }));
      await page.evaluate(([x, y]) => window.__pd.up(x, y), [to.x, to.y]); await sleep(1500);
      const after = (await text(page, 'kit.html')).match(/data-kol-component="counter"/g).length;
      const back = await page.evaluate(() => !document.querySelector('[data-kit-board]').classList.contains('kit-dragging'));
      ok(st.drag && st.line && st.folded && back && after === before + 1, W + ': גרירה מהלוח — הלוח מתקפל, קו היעד של V4, השחרור מוסיף מופע, והלוח חוזר', { ...st, back, before, after });
      ok(await page.evaluate(() => { const c = Editor._state.canvas, C = window.KolComp, i = C.instances(c.m, 'counter'); const h1 = c.m.root && window.KolSrc.byPath(c.m, window.KolSrc.pathOf(C.instances(c.m, 'counter')[0].node)); void h1; const kids = i.map(x => x.node.parent.kids.indexOf(x.node)); return kids[0] <= 2; }), W + ': המופע נכנס במקום שאליו נגרר (אחרי הכותרת)');
      await undo(page);
      await page.evaluate(() => { if (!document.querySelector('[data-kit-board]').hidden) document.querySelector('[data-kit-close]').click(); }); await sleep(200);
    }
    await page.setViewportSize({ width: 1920, height: 1080 }); await sleep(400);

    // ═══ 9. עדכון: לא שונה ← "עדכן", והמופעים במסלול של V12; שונה ← "יש גרסה חדשה — השוואה" ═══
    const putFile = (pg, p, content) => pg.evaluate(async ([p, content]) => { await Project.docs.reload(); const f = Project.docs.byPath(p); return api('pf_write', { id: f.id, rev: f.rev, content }); }, [p, content]);
    const sha1 = (pg, files) => pg.evaluate(async files => { const enc = new TextEncoder(), parts = []; for (const k of Object.keys(files).sort()) parts.push(enc.encode(k + '\0' + files[k] + '\0')); const all = new Uint8Array(parts.reduce((a, b) => a + b.length, 0)); let o = 0; for (const x of parts) { all.set(x, o); o += x.length; } return [...new Uint8Array(await crypto.subtle.digest('SHA-1', all))].map(b => b.toString(16).padStart(2, '0')).join(''); }, files);
    const OLD = (await text(page, 'components/counter/template.html')).replace('מילים במילון', 'מילים (גרסה ישנה)');
    await putFile(page, 'components/counter/template.html', OLD);
    const srv = (pg, p) => pg.evaluate(async p => { await Project.docs.reload(); const f = Project.docs.byPath(p); return (await api('pf_read', undefined, '&id=' + f.id)).content; }, p);
    const cf = {}; for (const f of ['component.json', 'template.html', 'style.css', 'script.js']) cf[f] = await srv(page, 'components/counter/' + f);
    kj = await kol(page); kj.system['components/counter/'].version = '0.9.0'; kj.system['components/counter/'].hash = await sha1(page, cf);
    await putFile(page, 'kol.json', JSON.stringify(kj, null, 4) + '\n');
    await page.evaluate(async () => { await Project.docs.reload(); await Editor._state.comp.load(); await Editor._state.kit.load(); }); await sleep(600);
    await selMain(page); await page.click('[data-kit-item="counter"]'); await sleep(1500);   // מופע מהתבנית הישנה — מקושר
    st = await page.evaluate(() => ({ tag: document.querySelector('[data-kit-item="counter"]').dataset.kitTag, btn: document.querySelector('[data-kit-update="counter"]')?.textContent }));
    ok(st.tag === 'upd' && /עדכן ל-1\.0\.0/.test(st.btn || ''), 'גרסה חדשה בכלי, הרכיב לא שונה ← "⬆ עדכן ל-1.0.0"', st);
    await page.click('[data-kit-update="counter"]'); await sleep(1800);
    const tplNow = await text(page, 'components/counter/template.html');
    st = await page.evaluate(() => ({ open: document.getElementById('sheet').open, rows: [...document.querySelectorAll('[data-upd]')].map(r => r.dataset.updState) }));
    ok(tplNow.includes('מילים במילון') && st.open && st.rows.includes('linked'), 'העדכון: template.html נכתב (שינוי קובץ רגיל), ו"שמור ועדכן מופעים" (V12) מציג את המופע המקושר', st);
    await page.click('[data-comp-apply-go]'); await sleep(800);
    pg = await text(page, 'kit.html');
    ok(!/גרסה ישנה/.test(pg) && (await kol(page)).system['components/counter/'].version === '1.0.0', 'המופע המקושר עודכן, והרשומה ב-kol.json — 1.0.0');
    await undo(page); await sleep(400);
    ok(/גרסה ישנה/.test(await text(page, 'kit.html')), 'Ctrl+Z — עדכון המופעים חזר');
    await undo(page); await sleep(1500);
    ok((await text(page, 'components/counter/template.html')).includes('גרסה ישנה') && (await kol(page)).system['components/counter/'].version === '0.9.0', 'Ctrl+Z נוסף — הקבצים והרשומה חזרו לגרסה הקודמת (kit_revert)');
    // שונה: alert
    await putFile(page, 'components/alert/style.css', (await text(page, 'components/alert/style.css')) + '\n.kol-alert { border-width: 3px; }\n');
    kj = await kol(page); kj.system['components/alert/'].version = '0.5.0'; await putFile(page, 'kol.json', JSON.stringify(kj, null, 4) + '\n');
    await page.evaluate(async () => { await Project.docs.reload(); await Editor._state.kit.load(); }); await sleep(500);
    st = await page.evaluate(() => document.querySelector('[data-kit-update="alert"]')?.textContent);
    ok(/יש גרסה חדשה — השוואה/.test(st || ''), 'שונה + גרסה חדשה ← "⬆ יש גרסה חדשה — השוואה"', st);
    await page.click('[data-kit-update="alert"]'); await sleep(800);
    st = await page.evaluate(() => ({ take: !!document.querySelector('[data-kit-take]'), keep: !!document.querySelector('[data-kit-keep]'), both: !!document.querySelector('[data-kit-both]') }));
    ok(st.take && st.keep && st.both, 'שלוש הפעולות: קח את החדשה · השאר את שלי · שתיהן זו לצד זו', st);
    await page.click('[data-kit-both]'); await sleep(200);
    st = await page.evaluate(() => { const c = document.querySelector('[data-kit-cmp]'); return { vis: !c.hidden, files: [...c.querySelectorAll('h4')].map(h => h.textContent), pres: c.querySelectorAll('pre').length }; });
    ok(st.vis && st.files.includes('style.css') && st.pres >= 3, '"שתיהן זו לצד זו": שלי / החדשה לכל קובץ שונה, וההבדלים', st);
    await page.click('[data-kit-keep]'); await sleep(1200);
    kj = await kol(page);
    ok(kj.system['components/alert/'].modified === true && kj.system['components/alert/'].version === items.find(x => x.name === 'alert').version && /border-width: 3px/.test(await text(page, 'components/alert/style.css')), '"השאר את שלי": modified: true, הגרסה סומנה — והקובץ שלי לא נגע', kj.system['components/alert/']);
    st = await page.evaluate(() => ({ tag: document.querySelector('[data-kit-item="alert"]').dataset.kitTag, upd: !!document.querySelector('[data-kit-update="alert"]') }));
    ok(st.tag === 'mod' && !st.upd, 'התג ← "בפרויקט · שונה", בלי הצעת עדכון', st);
    await shot(page, 'v16-1920-panel');


    // ═══ 11. V16b — ניווט, חלונות, תנועה: הפעולות בעורך (מהעמודים / מהכותרות / גרירת נקודה) והתצוגה החיה ═══
    await page.setViewportSize({ width: 1920, height: 1080 }); await sleep(300);
    // תוויות עם סוגריים ולטינית ב-RTL — <bdi>: "(" משמאל ל-")" בתוך הסוגריים הלטיניים, והם מימין למילים בעברית
    await selInst(page, 'video-embed'); await tab(page, 'content'); await sleep(300);
    st = await page.evaluate(() => { const k = document.querySelector('[data-comp-field="url"] .ct-k'), b = k && k.querySelector('bdi'); if (!b) return { bdi: false, txt: k && k.textContent };
      const t = b.firstChild, r = new Range(), at = i => { r.setStart(t, i); r.setEnd(t, i + 1); return r.getBoundingClientRect(); }, L = t.length;
      const he = new Range(); he.selectNodeContents(k.firstChild); const hr = he.getBoundingClientRect();
      return { bdi: true, txt: b.textContent, dir: getComputedStyle(b).direction, open: at(0).left, close: at(L - 1).left, y: at(1).left, heLeft: hr.left, sameLine: Math.abs(at(0).top - hr.bottom + hr.height / 2) < hr.height / 2 && hr.height < 30 }; });
    ok(st.bdi && st.txt === '(YouTube / Vimeo)' && st.open < st.y && st.y < st.close && (!st.sameLine || st.close < st.heLeft), 'תווית "כתובת הסרטון (YouTube / Vimeo)": הסוגריים ב-<bdi> — "(" לפני YouTube ו-")" אחרי Vimeo (ובשורה אחת עם העברית — משמאל לה)', st);
    // "↻ מהעמודים" (תפריט)
    await selInst(page, 'nav-menu'); await tab(page, 'content');
    await page.click('[data-kit-action="pages"]'); await sleep(500);
    const pagesList = await page.evaluate(async () => (await Project.docs.files()).filter(f => f.kind === 'file' && /\.html?$/i.test(f.path) && !/^components\//.test(f.path) && !Project.oldTemplate(f.path)).map(f => f.path).sort());
    st = await page.evaluate(() => { const c = Editor._state.canvas, C = window.KolComp, r = C.instances(c.m, 'nav-menu')[0].node; return C.findAllIn(r, '.kol-nav__link').map(a => [window.KolSrc.attrOf(a, 'href').value, window.KolSrc.textOf(c.m, a)]); });
    ok(st.length === pagesList.length && st.some(x => x[0] === 'kit.html' && x[1] === 'רכיבים') && /^פריטים מהעמודים/.test(await lastTx(page)) && await stateOf(page, '.kol-nav') === 'linked', 'תפריט: "↻ מהעמודים" ← קישור לכל עמוד (הכותרת שלו), פעולה אחת, המופע מקושר', { st, pagesList });
    await undo(page);
    // "↻ מהכותרות" (תוכן עניינים)
    await selInst(page, 'toc'); await tab(page, 'content');
    await page.click('[data-kit-action="toc"]'); await sleep(500);
    pg = await text(page, 'kit.html');
    st = await page.evaluate(() => { const c = Editor._state.canvas, C = window.KolComp, K = window.KolSrc, r = C.instances(c.m, 'toc')[0].node; return C.findAllIn(r, '.kol-toc__item').map(li => ({ href: K.attrOf(C.findIn(li, '.kol-toc__link'), 'href').value, sub: K.classesOf(li).includes('kol-toc__item--sub'), t: K.textOf(c.m, C.findIn(li, '.kol-toc__link')) })); });
    const h2s = await page.evaluate(() => { const c = Editor._state.canvas; return [...c.doc.querySelectorAll('h2, h3')].filter(e => !e.closest('.kol-toc')).map(e => ({ id: e.id, t: e.textContent.replace(/\s+/g, ' ').trim(), tag: e.localName })); });
    ok(st.length === h2s.length && st.length > 3 && st.every((x, i) => x.href === '#' + h2s[i].id && x.t === h2s[i].t && x.sub === (h2s[i].tag === 'h3')) && h2s.every(x => x.id), 'תוכן עניינים: "↻ מהכותרות" ← שורה לכל h2 / h3 בעמוד (h3 — משנה), וכל כותרת קיבלה id — בקובץ, פעולה אחת', { st: st.slice(0, 4), h2s: h2s.slice(0, 4) });
    ok(/<h2 class="kol-slides__title" id="[^"]+">/.test(pg), 'ה-id נכתב לכותרת עצמה (גלוי, נערך)');
    // hotspot: הידיות רק ברכיב הנבחר; גרירה ← --x / --y בנקודה, פעולה אחת
    await selMain(page); await sleep(200);
    ok(await page.evaluate(() => document.querySelectorAll('[data-kit-handles] .kit-handle').length) === 0, 'ידיות הנקודות — רק כשהרכיב נבחר');
    await selInst(page, 'hotspot'); await sleep(300);
    st = await page.evaluate(() => [...document.querySelectorAll('[data-kit-handles] .kit-handle')].map(b => { const r = b.getBoundingClientRect(); return { x: r.left + r.width / 2, y: r.top + r.height / 2 }; }));
    ok(st.length === 3, 'hotspot נבחר ← 3 ידיות על הנקודות', st.length);
    const hp0 = await page.evaluate(() => { const c = Editor._state.canvas, img = c.doc.querySelector('.kol-hotspot__img'), r = img.getBoundingClientRect(), f = c.frame.getBoundingClientRect(); return { l: f.left + r.left * c.scale, t: f.top + r.top * c.scale, w: r.width * c.scale, h: r.height * c.scale }; });
    n0 = await txN(page);
    await page.mouse.move(st[0].x, st[0].y); await page.mouse.down(); await page.mouse.move(hp0.l + hp0.w * 0.5, hp0.t + hp0.h * 0.25, { steps: 8 }); await page.mouse.up(); await sleep(500);
    pg = await text(page, 'kit.html');
    const pin = /<button class="kol-hotspot__pin" type="button" style="--x: ([\d.]+)%; --y: ([\d.]+)%">/.exec(pg);
    ok(pin && Math.abs(+pin[1] - 50) < 3 && Math.abs(+pin[2] - 25) < 3 && await txN(page) === n0 + 1 && /^גרירת נקודה/.test(await lastTx(page)) && await stateOf(page, '.kol-hotspot') === 'linked', 'גרירת הנקודה בקנבס ← style="--x: 50%; --y: 25%" על הנקודה, פעולה אחת, המופע מקושר', pin && pin.slice(1));
    await shot(page, 'v16-1920-hotspot');
    await undo(page);
    // הגדרות שמשפיעות על ההתנהגות בתצוגה החיה: מצגת — אוטומטית כל 1 שנייה; חלון קופץ — אחרי 1 שנייה, פעם לגולש
    await selInst(page, 'slides'); await tab(page, 'content');
    await page.fill('[data-comp-panel] [data-cf="auto"]', '1'); await page.evaluate(() => document.querySelector('[data-comp-panel] [data-cf="auto"]').dispatchEvent(new Event('change'))); await sleep(300);
    await selInst(page, 'popup'); await tab(page, 'content');
    await page.selectOption('[data-comp-panel] [data-cf="trigger"]', 'delay'); await sleep(300);
    await page.fill('[data-comp-panel] [data-cf="delay"]', '1'); await page.evaluate(() => document.querySelector('[data-comp-panel] [data-cf="delay"]').dispatchEvent(new Event('change'))); await sleep(300);
    await page.selectOption('[data-comp-panel] [data-cf="once"]', 'visitor'); await sleep(300);
    pg = await text(page, 'kit.html');
    ok(/data-kol-autoplay="1"/.test(pg) && /data-kol-trigger="delay" data-kol-delay="1"[^>]*data-kol-once="visitor"/.test(pg), 'בפאנל: "מתי נפתח" = אחרי 1 שנייה, פעם לגולש — תכונות גלויות על <dialog>', pg.match(/<dialog class="kol-popup__dlg"[^>]*>/));
    await flush(page);
    const tok3 = (await call(page, 'pv_token')).token, pb = await ctx.newPage(); const pbErr = []; pb.on('pageerror', e => pbErr.push(e.message));
    await pb.goto(`${base}/preview/${tok3}/kit.html`); await sleep(1700);
    st = await pb.evaluate(() => ({ open: document.querySelector('.kol-popup__dlg').open, modal: document.querySelector('.kol-popup__dlg').matches(':modal') }));
    ok(st.open && st.modal, 'חלון קופץ: נפתח לבד אחרי שנייה (modal)', st);
    await pb.keyboard.press('Escape'); await sleep(200);
    ok(!(await pb.evaluate(() => document.querySelector('.kol-popup__dlg').open)), 'Esc סוגר');
    // "פעם לגולש" — localStorage; בתצוגה החיה (מקור אטום) אין זיכרון, ולכן נבדק בדף רגיל על אותו שרת
    { const pz = await ctx.newPage(), K16 = path.join(ROOT, 'assets/kit/components/popup/');
      const tpl = fs.readFileSync(K16 + 'template.html', 'utf8').replace('data-kol-trigger="click" data-kol-delay="5"', 'data-kol-trigger="delay" data-kol-delay="1"').replace('data-kol-once="session"', 'data-kol-once="visitor"');
      await pz.route(base + '/__v16/popup.html', r => r.fulfill({ contentType: 'text/html', body: '<!doctype html><html lang="he" dir="rtl"><head><meta charset="utf-8"><style>' + fs.readFileSync(K16 + 'style.css', 'utf8') + '</style></head><body>' + tpl + '<script>' + fs.readFileSync(K16 + 'script.js', 'utf8') + '</script></body></html>' }));
      await pz.goto(base + '/__v16/popup.html'); await sleep(1600);
      const o1 = await pz.evaluate(() => document.querySelector('.kol-popup__dlg').open);
      await pz.keyboard.press('Escape'); await sleep(150); await pz.reload(); await sleep(1600);
      const o2 = await pz.evaluate(() => document.querySelector('.kol-popup__dlg').open);
      ok(o1 && !o2, '"פעם לגולש": נפתח אחרי שנייה, ואחרי טעינה מחדש — לא שוב (localStorage)', [o1, o2]); await pz.close(); }
    await pb.click('.kol-popup__open'); await sleep(200);
    ok(await pb.evaluate(() => document.querySelector('.kol-popup__dlg').open), 'כפתור עם data-kol-open פותח בכל זאת');
    await pb.click('.kol-popup__close'); await sleep(150);
    ok(!(await pb.evaluate(() => document.querySelector('.kol-popup__dlg').open)), 'data-kol-close סוגר');
    // חלונית מהצד
    await pb.click('.kol-offcanvas__open'); await sleep(400);
    st = await pb.evaluate(() => { const d = document.querySelector('.kol-offcanvas__dlg'), r = d.getBoundingClientRect(); return { open: d.open, right: Math.round(innerWidth - r.right), h: Math.round(r.height), vh: innerHeight }; });
    ok(st.open && st.right === 0 && st.h === st.vh, 'חלונית מהצד: נפתחת בגובה המסך, מתחילת השורה (ימין ב-RTL)', st);
    await pb.keyboard.press('Escape'); await sleep(150);
    // מצגת: נקודות כמספר השקופיות, חץ, הפעלה אוטומטית
    await pb.evaluate(() => document.querySelector('.kol-slides').scrollIntoView({ block: 'center' })); await sleep(200);
    st = await pb.evaluate(() => ({ ready: document.querySelector('.kol-slides').classList.contains('is-ready'), dots: document.querySelectorAll('.kol-slides__dot').length, n: document.querySelectorAll('.kol-slides__slide').length, labels: [...document.querySelectorAll('.kol-slides__dot .kol-slides__vh')].map(x => x.textContent).join() }));
    ok(st.ready && st.dots === st.n && st.labels === '1,2,3', 'מצגת: נקודה לכל שקופית (משוכפלת מהראשונה, המספר כטקסט)', st);
    const act = () => pb.evaluate(() => [...document.querySelectorAll('.kol-slides__slide')].findIndex(x => x.classList.contains('is-active')));
    await pb.hover('.kol-slides__next'); await pb.click('.kol-slides__next'); await sleep(900);
    const a1 = await act();
    await pb.mouse.move(5, 5); await sleep(2600);
    const a2 = await act();
    ok(a1 === 1 && a2 !== 1, 'מצגת: "הבאה" ← שקופית 2; ואוטומטית (כל שנייה, כשהעכבר לא עליה) — ממשיכה', [a1, a2]);
    // קרוסלה
    await pb.evaluate(() => document.querySelector('.kol-carousel').scrollIntoView({ block: 'center' })); await sleep(200);
    st = await pb.evaluate(() => { const t = document.querySelector('.kol-carousel__track'), s = t.children[0].getBoundingClientRect().width; return Math.round(t.clientWidth / s * 10) / 10; });
    await pb.click('.kol-carousel__next'); await sleep(900);
    const ca = await pb.evaluate(() => [...document.querySelectorAll('.kol-carousel__slide')].findIndex(x => x.classList.contains('is-active')));
    ok(st >= 2.8 && st <= 3.3 && ca === 2, 'קרוסלה: 3 בכל מבט; "הבאה" ← מבט שלם קדימה (5 כרטיסים: עד הסוף — כרטיסים 3–5)', { per: st, ca });
    // כותרת מונפשת
    st = await pb.evaluate(() => [...document.querySelectorAll('.kol-headline__word')].map(w => getComputedStyle(w).animationName));
    ok(st.join() === 'kol-hl-3,kol-hl-3,kol-hl-3', 'כותרת מונפשת: CSS בלבד (kol-hl-3 לכל מילה)', st);
    // hotspot
    await pb.evaluate(() => document.querySelector('.kol-hotspot').scrollIntoView({ block: 'center' })); await pb.hover('.kol-hotspot__pin'); await sleep(250);
    ok(await pb.evaluate(() => getComputedStyle(document.querySelector('.kol-hotspot__tip')).visibility === 'visible'), 'נקודות על תמונה: ריחוף ← ההסבר');
    // תפריט נפתח
    await pb.evaluate(() => document.querySelector('.kol-drop').scrollIntoView({ block: 'center' })); await pb.click('.kol-drop__btn'); await sleep(150);
    const d1 = await pb.evaluate(() => document.querySelector('.kol-drop').open);
    await pb.mouse.click(5, 5); await sleep(150);
    ok(d1 && !(await pb.evaluate(() => document.querySelector('.kol-drop').open)), 'תפריט נפתח: נפתח בלחיצה, נסגר בלחיצה בחוץ');
    // חזרה לראש
    await pb.evaluate(() => scrollTo(0, 2000)); await sleep(300);
    ok(await pb.evaluate(() => document.querySelector('.kol-top').classList.contains('is-shown')), 'חזרה לראש: מופיע אחרי גלילה');
    await pb.click('.kol-top'); await sleep(1500);
    ok(await pb.evaluate(() => scrollY) < 5, 'חזרה לראש: לחיצה ← לראש העמוד');
    // תוכן עניינים: is-here
    await pb.evaluate(() => { const a = document.querySelectorAll('.kol-toc__link')[2]; document.getElementById(decodeURIComponent(a.getAttribute('href').slice(1))).scrollIntoView({ block: 'start' }); }); await sleep(500);
    st = await pb.evaluate(() => [...document.querySelectorAll('.kol-toc__item')].map(x => x.classList.contains('is-here')).indexOf(true));
    ok(st === 2, 'תוכן עניינים: השורה של הכותרת שעל המסך — is-here', st);
    // Lottie: הנגן מהפרויקט (components/lottie/), לא מ-CDN
    await pb.evaluate(() => document.querySelector('.kol-lottie').scrollIntoView({ block: 'center' })); await sleep(2500);
    st = await pb.evaluate(() => ({ ready: document.querySelector('.kol-lottie').classList.contains('is-ready'), svg: !!document.querySelector('.kol-lottie__stage svg'), src: [...document.scripts].map(s => s.getAttribute('src')).filter(x => /lottie/.test(x || '')) }));
    ok(st.ready && st.svg && st.src.length && st.src.every(x => /components\/lottie\/(script|lottie_light\.min)\.js$/.test(x)), 'Lottie: הנגן נטען מ-components/lottie/ (קובץ בפרויקט), והאנימציה מצוירת', st);
    ok(!pbErr.filter(m => !/network error|'caches' property/i.test(m)).length, 'V16b — אין שגיאות JS בתצוגה החיה', pbErr);
    // בלי JS: התפריט — רשימת קישורים; המצגת — גלילה עם scroll-snap
    const ctxNo2 = await browser.newContext({ viewport: { width: 390, height: 844 }, javaScriptEnabled: false, extraHTTPHeaders: { 'X-Test-User': JSON.stringify(OWNER) } });
    const nj2 = await ctxNo2.newPage(); await nj2.goto(`${base}/preview/${tok3}/kit.html`); await sleep(400);
    st = await nj2.evaluate(() => ({ links: [...document.querySelectorAll('.kol-nav__link')].filter(a => a.getBoundingClientRect().width > 0).length, toggle: getComputedStyle(document.querySelector('.kol-nav__toggle')).display, snap: getComputedStyle(document.querySelector('.kol-slides__track')).scrollSnapType, arrows: getComputedStyle(document.querySelector('.kol-slides__next')).display, lottie: document.querySelector('.kol-lottie__poster').getBoundingClientRect().height > 0 }));
    ok(st.links >= 4 && st.toggle === 'none' && /x mandatory/.test(st.snap) && st.arrows === 'none' && st.lottie, 'בלי JS (390): התפריט — כל הקישורים גלויים (בלי המבורגר); המצגת — scroll-snap; Lottie — התמונה', st);
    await ctxNo2.close(); await pb.close();

    // ═══ 10. טלפון (390): גיליון; הקשה = "הוסף אחרי הנבחר"; לחיצה ארוכה = גרירה; כל רכיב עובד ב-390 ═══
    await flush(page);
    // מכל ממוקם (absolute) עם כפתור — כמו כפתור על תמונת hero; ל"אחרי הנבחר" כשהכפתור נבחר
    const kitSrc = await text(page, 'kit.html');
    await putFile(page, 'kit.html', kitSrc.replace(/<\/body>/i, '  <div class="t-pin" style="position: absolute; inset-inline-start: 12px; inset-block-start: 12px; inline-size: 120px"><a class="t-pin__btn" href="#">כפתור</a></div>\n</body>'));
    const ph = await mk(390, 844), pp = await ph.newPage();
    pp.on('pageerror', e => errors.push('390: ' + e.message));
    await pp.goto(base + '/editor.php#/editor/' + encodeURIComponent('kit.html')); await ready(pp, 'kit.html'); await sleep(800);
    await pp.evaluate(() => { const b = document.querySelector('.ed-bar [data-panel="nav"]'); if (b.getAttribute('aria-pressed') !== 'true') b.click(); }); await sleep(300);
    await compTab(pp);
    await pp.waitForFunction(() => Editor._state.kit && Editor._state.kit.items.length >= 20, null, { timeout: 15000 });
    await pp.evaluate(() => document.querySelector('[data-kit-wide]').click()); await sleep(300);
    await pp.evaluate(() => { const b = document.querySelector('.ed-bar [data-panel="nav"]'); if (b.getAttribute('aria-pressed') === 'true') b.click(); }); await sleep(300);
    st = await pp.evaluate(() => { const b = document.querySelector('[data-kit-board]'), r = b.getBoundingClientRect(), g = b.querySelector('.kit-grid'); return { vis: !b.hidden, pos: getComputedStyle(b).position, w: Math.round(r.width), bottom: Math.round(innerHeight - r.bottom), top: Math.round(r.top), cols: getComputedStyle(g).gridTemplateColumns.split(' ').length, hint: b.querySelector('.kit-bhead .hint').textContent }; });
    ok(st.vis && st.pos === 'fixed' && st.w === 390 && st.bottom === 0 && st.top > 100 && st.cols === 2 && /הוסף אחרי הנבחר/.test(st.hint) && /לחיצה ארוכה/.test(st.hint), '390: הספרייה — גיליון מלמטה, 2 עמודות, "הקשה — הוסף אחרי הנבחר · לחיצה ארוכה — גרירה"', st);
    await shot(pp, 'v16-390-sheet');
    await select(pp, '.kit-h');
    let c0 = (await text(pp, 'kit.html')).match(/data-kol-component="icon-box"/g).length;
    await pp.evaluate(() => document.querySelector('[data-kit-grid="board"] [data-kit-item="icon-box"]').click()); await sleep(1500);
    st = await pp.evaluate(() => { const c = Editor._state.canvas, C = window.KolComp, h = c.doc.querySelector('.kit-h'), n = c.nodeOf(h); const i = n.parent.kids.indexOf(n); return { next: (window.KolSrc.attrOf(n.parent.kids[i + 1], 'data-kol-component') || {}).value, sheet: !document.querySelector('[data-kit-board]').hidden }; });
    ok((await text(pp, 'kit.html')).match(/data-kol-component="icon-box"/g).length === c0 + 1 && st.next === 'icon-box' && !st.sheet, '390: הקשה על כרטיס ← נוסף אחרי הנבחר (הכותרת), והגיליון נסגר', st);
    await undo(pp);
    // "אחרי הנבחר" כשהנבחר — כפתור בתוך מכל absolute: לא לתוך המכל (שם הוא נפרש מחוץ למסך) — אחרי המכל, בזרימה
    await select(pp, '.t-pin__btn'); await sleep(200);
    await pp.evaluate(() => Editor._state.kit.use('icon-box')); await sleep(1500);
    st = await pp.evaluate(() => { const c = Editor._state.canvas, pin = c.doc.querySelector('.t-pin'), ins = [...c.doc.querySelectorAll('[data-kol-component="icon-box"]')].find(e => !e.closest('.kit-h') && e.previousElementSibling === pin) || null, r = ins && ins.getBoundingClientRect();
      return { inside: !!pin.querySelector('[data-kol-component]'), after: !!ins, x: r && Math.round(r.left), right: r && Math.round(r.right), vw: c.doc.documentElement.clientWidth }; });
    ok(!st.inside && st.after && st.x >= 0 && st.right <= st.vw, '390: "אחרי הנבחר" על כפתור במכל ממוקם (absolute) ← הרכיב אחרי המכל, בתוך המסך — לא בתוכו', st);
    await undo(pp); await sleep(300);
    // לחיצה ארוכה ← גרירה לקנבס
    await pp.evaluate(() => document.querySelector('[data-kit-wide]') && (document.querySelector('.ed-bar [data-panel="nav"]').click(), 0)); await sleep(200);
    await pp.evaluate(() => { document.querySelector('[data-kit-wide]').click(); const b = document.querySelector('.ed-bar [data-panel="nav"]'); if (b.getAttribute('aria-pressed') === 'true') b.click(); }); await sleep(300);
    c0 = (await text(pp, 'kit.html')).match(/data-kol-component="quote-2"/g).length;
    const card = await pp.evaluate(() => { const e = document.querySelector('[data-kit-grid="board"] [data-kit-item="quote"]'); e.scrollIntoView({ block: 'center' }); const r = e.getBoundingClientRect(); return { x: r.left + r.width / 2, y: r.top + r.height / 2 }; });
    await pp.evaluate(PD);
    await pp.evaluate(([x, y]) => window.__pd.down(x, y, true, true), [card.x, card.y]); await sleep(650);
    const held = await pp.evaluate(() => !!Editor._state.build.drag.active);
    const dest = await at(pp, '.kit-h', 0, 0.5, 0.9);
    for (let k = 1; k <= 8; k++) { await pp.evaluate(([x, y]) => window.__pd.move(x, y), [card.x + (dest.x - card.x) * k / 8, card.y + (dest.y - card.y) * k / 8]); await sleep(40); }
    await sleep(120); await pp.evaluate(([x, y]) => window.__pd.up(x, y), [dest.x, dest.y]); await sleep(1500);
    ok(held && (await text(pp, 'kit.html')).match(/data-kol-component="quote-2"/g).length === c0 + 1, '390: לחיצה ארוכה ← גרירה, והשחרור בקנבס מוסיף', { held });
    await undo(pp); await flush(pp);
    // כל רכיב ב-390, בתצוגה החיה: בלי גלילה לרוחב, כל מופע ברוחב המסך
    const tok2 = (await call(pp, 'pv_token')).token, pm = await ph.newPage(); await pm.goto(`${base}/preview/${tok2}/kit.html`); await sleep(1500); await pm.evaluate(() => document.querySelectorAll("dialog[open]").forEach(d => d.close()));
    st = await pm.evaluate(() => ({ sw: document.documentElement.scrollWidth, wide: [...document.querySelectorAll('[data-kol-component]')].filter(e => e.getAttribute('data-kol-component') !== 'anchor' && (e.getBoundingClientRect().width > 391 || e.getBoundingClientRect().height < 10)).map(e => e.getAttribute('data-kol-component')),
      tabs: getComputedStyle(document.querySelector('.kol-tabs')).display, tabOrder: (() => { const t = document.querySelector('.kol-tabs__tab').getBoundingClientRect(), p = document.querySelector('.kol-tabs__panel').getBoundingClientRect(); return p.top >= t.bottom - 1; })() }));
    ok(st.sw <= 391 && !st.wide.length, '390: כל ' + items.length + ' הרכיבים — בתוך רוחב המסך, בלי גלילה לרוחב', st);
    ok(st.tabs === 'block' && st.tabOrder, '390: לשוניות ← אקורדיון (הלוח מתחת ללשונית שלו)', st);
    await pm.click('.kol-tabs > .kol-tabs__item:nth-child(2) > .kol-tabs__tab'); await sleep(150);
    st = await pm.evaluate(() => { const its = [...document.querySelectorAll('.kol-tabs > .kol-tabs__item')]; return its.map(x => getComputedStyle(x.querySelector('.kol-tabs__panel')).display !== 'none'); });
    ok(st[1] && st.filter(Boolean).length === 1, '390: הקשה על לשונית אחרת ← היא נפתחת, האחרות נסגרות', st);
    await pm.evaluate(() => document.querySelector('.kol-flip').scrollIntoView({ block: 'center' })); await pm.tap('.kol-flip__front'); await sleep(700);
    st = await pm.evaluate(() => ({ f: document.querySelector('.kol-flip').classList.contains('is-flipped'), t: getComputedStyle(document.querySelector('.kol-flip__inner')).transform }));
    ok(st.f && st.t !== 'none', '390 (מגע): הקשה הופכת את הקופסה', st);
    await pm.evaluate(() => document.querySelector('.kol-lightbox').scrollIntoView({ block: 'center' })); await pm.tap('.kol-lightbox__item'); await sleep(200);
    const sw0 = await pm.evaluate(() => document.querySelector('.kol-lightbox__cap').textContent);
    await pm.evaluate(() => { const d = document.querySelector('.kol-lightbox__dlg'), ev = (t, x) => d.dispatchEvent(new PointerEvent(t, { clientX: x, bubbles: true, pointerType: 'touch' })); ev('pointerdown', 300); ev('pointerup', 150); }); await sleep(150);
    const sw1 = await pm.evaluate(() => document.querySelector('.kol-lightbox__cap').textContent);
    ok(sw0 === 'תמונה 1' && sw1 !== sw0, '390: lightbox — החלקה מחליפה תמונה', [sw0, sw1]);
    await pm.keyboard.press('Escape'); await sleep(150);
    // תפריט ב-390: המבורגר ← חלונית מהצד; Esc סוגר
    await pm.evaluate(() => document.querySelector('.kol-nav').scrollIntoView({ block: 'center' })); await sleep(150);
    st = await pm.evaluate(() => ({ btn: getComputedStyle(document.querySelector('.kol-nav__toggle')).display, off: document.querySelector('.kol-nav__list').getBoundingClientRect().left >= 390 - 1 }));
    await pm.tap('.kol-nav__toggle'); await sleep(450);
    const navOpen = await pm.evaluate(() => { const r = document.querySelector('.kol-nav__list').getBoundingClientRect(); return { open: document.querySelector('.kol-nav').classList.contains('is-open'), right: Math.round(390 - r.right), h: Math.round(r.height), exp: document.querySelector('.kol-nav__toggle').getAttribute('aria-expanded') }; });
    ok(st.btn === 'block' && st.off && navOpen.open && navOpen.right === 0 && navOpen.h > 800 && navOpen.exp === 'true', '390: תפריט — המבורגר; הקשה ← החלונית מהצד (ימין, בגובה המסך)', { st, navOpen });
    await pm.keyboard.press('Escape'); await sleep(200);
    ok(!(await pm.evaluate(() => document.querySelector('.kol-nav').classList.contains('is-open'))), '390: Esc סוגר את החלונית');
    await shot(pm, 'v16-390-live');
    await ph.close();
    // ═══ END ═══
    ok(!errors.length, 'אין שגיאות JS בעורך', errors.slice(0, 5));
  } finally {
    if (browser) await browser.close();
    php.kill();
  }
  console.log(failed ? `\n❌ ${failed} מתוך ${ran} נכשלו` : `\n✅ כל ${ran} הבדיקות עברו`);
  process.exit(failed ? 1 : 0);
})();
