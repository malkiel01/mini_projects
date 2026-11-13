/**
 * פרוסה V14 — הכול גם בטלפון (ובטאבלט) — בדיקות בדפדפן   node kol-maroko/tests/browser/v14.cjs
 *
 * Playwright עם hasTouch + isMobile (390×844, 360×740, 768×1024, רוחב 844×390), ואירועי מגע (tap אמיתי; גרירה — PointerEvent
 * מסוג touch, כמו v4/v6: ה-iframe בארגז החול אינו מסיים גרירה של page.mouse). מכסה את §2.1–2.6 ואת הטבלה של §2.4 (פעולה במגע
 * לכל פרוסה V1–V12; V13 — בסבב השני), ו-§2.7: במחשב (1920 / 1366) אין שום סימן של V14, וכל כלל CSS של V14 בתוך
 * max-width: 999px או pointer: coarse. צילומים — ל-tmpdir (kol-v14-shots).
 */
'use strict';
const { chromium } = require('playwright');
const { spawn, execFileSync } = require('child_process');
const fs = require('fs'), os = require('os'), path = require('path'), net = require('net');

const ROOT = path.resolve(__dirname, '..', '..');
const OWNER = { sub: 'u-owner', name: 'Owner Test', email: 'owner@example.test', email_verified: true, platform_admin: true };
let failed = 0, ran = 0;
let T0 = Date.now(); const ok = (cond, what, got) => { if (process.env.V14_DEBUG && got !== undefined) console.log('   · ' + JSON.stringify(got).slice(0, 400)); ran++; const dt = ((Date.now() - T0) / 1000).toFixed(1); T0 = Date.now(); if (cond) console.log('✅ [' + dt + 's] ' + what); else { failed++; console.log('❌ ' + what + (got !== undefined ? '\n   התקבל: ' + JSON.stringify(got).slice(0, 1200) : '')); } };
const freePort = () => new Promise(r => { const s = net.createServer().listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => r(p)); }); });
const sleep = ms => new Promise(r => setTimeout(r, ms));

(async () => {
  const data = fs.mkdtempSync(path.join(os.tmpdir(), 'kol-v14-'));
  execFileSync('php', [path.join(__dirname, 'f3-seed.php'), data, path.join(data, 'old-render.html')]);
  const port = await freePort(), base = `http://127.0.0.1:${port}`;
  const php = spawn('php', ['-S', `127.0.0.1:${port}`, path.join(ROOT, 'tests', 'router.php')], { cwd: ROOT, env: { ...process.env, KOL_TEST_DATA: data, PHP_CLI_SERVER_WORKERS: '4' }, stdio: 'ignore' });
  let browser;
  try {
    for (let i = 0; i < 100; i++) { try { await fetch(base + '/api.php?action=embed_key'); break; } catch { await sleep(50); } }
    browser = await chromium.launch();
    const mk = (width, height, touch = true) => browser.newContext({ viewport: { width, height }, hasTouch: touch, isMobile: touch, extraHTTPHeaders: { 'X-Test-User': JSON.stringify(OWNER) } });
    const shotDir = path.join(os.tmpdir(), 'kol-v14-shots'); fs.mkdirSync(shotDir, { recursive: true });
    const shot = (page, name) => page.screenshot({ path: path.join(shotDir, name + '.png') });
    const ready = page => page.waitForFunction(() => Editor._state.file && Editor._state.canvas && Editor._state.canvas.m && !Editor._state.busy && Editor._state.build && Editor._state.touch, null, { timeout: 30000 });
    const flush = async page => { try { await page.evaluate(async () => { const S = Project._state; for (const [id, d] of S.docs) if (d.dirty) await Project._save(id); }); await page.waitForFunction(() => [...Project._state.docs.values()].every(d => !d.dirty && !d.saving), null, { timeout: 15000 }); } catch (e) { console.log('   ⚠️ flush: ' + e.message.slice(0, 200)); } };
    const errors = [];
    const newPage = async (ctx, tag) => {
      const page = await ctx.newPage();
      page.on('pageerror', e => { errors.push(tag + ': ' + e.message); console.log('   ⚠️ שגיאה בדף (' + tag + ') אחרי בדיקה ' + ran + ': ' + e.message + ' ' + String(e.stack || '').split('\n').slice(1, 3).join(' | ').slice(0, 300)); });
      page.on('dialog', d => (d.type() === 'beforeunload' ? d.accept() : d.dismiss()));
      return page;
    };
    const openEd = async page => { await page.goto(base + '/editor.php#/editor/index.html'); await page.reload(); await ready(page); await page.waitForFunction(() => !Editor._state.lv || Editor._state.lv.lists.every(L => L.status !== 'loading'), null, { timeout: 30000 }); await sleep(600); };
    // נקודה על הקנבס (קואורדינטות המעטפת) לאלמנט שבקנבס
    const at = (page, sel, i = 0, fx = 0.5, fy = 0.5, scroll = true) => page.evaluate(([s, i, fx, fy, sc]) => { const c = Editor._state.canvas, e = c.doc.querySelectorAll(s)[i]; if (!e) return null; if (sc) e.scrollIntoView({ block: 'center' }); const r = e.getBoundingClientRect(), f = c.frame.getBoundingClientRect(); return { x: f.left + (r.left + r.width * fx) * c.scale, y: f.top + (r.top + r.height * fy) * c.scale }; }, [sel, i, fx, fy, scroll]);
    const tap = async (page, p) => { await page.touchscreen.tap(p.x, p.y); await sleep(250); };
    const selName = page => page.evaluate(() => { const c = Editor._state.canvas; return c.selected ? c.label(c.selected) : null; });
    const select = (page, sel, i = 0) => page.evaluate(([s, i]) => { const c = Editor._state.canvas, e = c.doc.querySelectorAll(s)[i]; if (!e) return false; const n = c.nodeOf(e); c.select(n); return !!n; }, [sel, i]);
    const lastTx = page => page.evaluate(() => { const t = Editor._state.tx; return t.length ? t[t.length - 1].label : ''; });
    const undo = async page => { await page.evaluate(() => Editor._undo()); await sleep(400); };
    // מגע סינתטי (PointerEvent מסוג touch) — על הקנבס או על המעטפת; hold = לחיצה ארוכה לפני התזוזה
    const PT = () => {
      const ed = Editor._state, c = ed.canvas;
      const inFrame = (x, y) => { const f = c.frame.getBoundingClientRect(), hr = c.host.getBoundingClientRect(); return x >= Math.max(f.left, hr.left) && x <= Math.min(f.right, hr.right) && y >= Math.max(f.top, hr.top) && y <= Math.min(f.bottom, hr.bottom); };
      const fpt = (x, y) => { const f = c.frame.getBoundingClientRect(); return [(x - f.left) / c.scale, (y - f.top) / c.scale]; };
      const fire = (t, type, cx, cy, id) => t.dispatchEvent(new (t.ownerDocument.defaultView.PointerEvent)(type, { bubbles: true, cancelable: true, composed: true, clientX: cx, clientY: cy, pointerId: id || 7, pointerType: 'touch', isPrimary: (id || 7) === 7, button: type === 'pointermove' ? -1 : 0, buttons: type === 'pointerup' || type === 'pointercancel' ? 0 : 1 }));
      const target = (x, y, sticky) => { if (inFrame(x, y) && !sticky.shell) { const [fx, fy] = fpt(x, y); return [c.doc.elementFromPoint(fx, fy) || c.doc.documentElement, fx, fy]; } let t = ed.build.drag.active ? document.elementFromPoint(x, y) : (sticky.t || document.elementFromPoint(x, y)); if (!t || t.localName === 'iframe') t = document.body; return [t, x, y]; };
      window.__pt = {
        down(x, y, shell, id) { this.s = { shell: !!shell }; this.frame = inFrame(x, y) && !shell; const [t, cx, cy] = target(x, y, this.s); this.s.t = this.frame ? null : t; this.ft = this.frame ? t : null; fire(t, 'pointerdown', cx, cy, id); return t.className || t.localName; },
        move(x, y, id) { if (this.frame && !ed.build.drag.active) { const [fx, fy] = fpt(x, y); fire(c.doc.elementFromPoint(fx, fy) || c.doc.documentElement, 'pointermove', fx, fy, id); return; } const [t, cx, cy] = target(x, y, this.s); fire(t, 'pointermove', cx, cy, id); },
        up(x, y, id) { if (this.frame && !ed.build.drag.active) { const [fx, fy] = fpt(x, y); fire(c.doc.elementFromPoint(fx, fy) || c.doc.documentElement, 'pointerup', fx, fy, id); return; } const [t, cx, cy] = target(x, y, this.s); fire(t, 'pointerup', cx, cy, id); },
      };
    };
    const touchDrag = async (page, from, to, opt = {}) => {
      await page.evaluate(PT);
      await page.evaluate(([x, y, sh]) => window.__pt.down(x, y, sh), [from.x, from.y, !!opt.shell]);
      if (opt.hold) await sleep(opt.hold);
      const steps = opt.steps || 10; let mid = null;
      for (let i = 1; i <= steps; i++) { await page.evaluate(([x, y]) => window.__pt.move(x, y), [from.x + (to.x - from.x) * i / steps, from.y + (to.y - from.y) * i / steps]); await sleep(opt.stepMs || 24); if (i === steps - 1 && opt.during) mid = await opt.during(); }
      await sleep(80);
      await page.evaluate(([x, y]) => window.__pt.up(x, y), [to.x, to.y]); await sleep(400);
      return mid;
    };
    // מגע אמיתי (CDP) — למעטפת: ידיות, הגיליון, הפאנל. מצביע אמיתי, כך ש-setPointerCapture עובד
    const cdpOf = async page => page.__cdp || (page.__cdp = await page.context().newCDPSession(page));
    const realDrag = async (page, from, to, opt = {}) => {
      const s = await cdpOf(page), pt = (x, y) => [{ x: Math.round(x), y: Math.round(y), id: 1, radiusX: 4, radiusY: 4, force: 1 }];
      await s.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: pt(from.x, from.y) });
      if (opt.hold) await sleep(opt.hold);
      const steps = opt.steps ?? 10; let mid = null;
      for (let i = 1; i <= steps; i++) { await s.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: pt(from.x + (to.x - from.x) * i / steps, from.y + (to.y - from.y) * i / steps) }); await sleep(opt.stepMs || 24); if (i === steps - 1 && opt.during) mid = await opt.during(); }
      await sleep(60);
      await s.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] }); await sleep(400);
      return mid;
    };
    const rectOf = (page, sel) => page.evaluate(s => { const e = document.querySelector(s); if (!e) return null; const r = e.getBoundingClientRect(); return { x: r.x, y: r.y, w: r.width, h: r.height, top: r.top, bottom: r.bottom, left: r.left, right: r.right, cx: r.x + r.width / 2, cy: r.y + r.height / 2 }; }, sel);
    const sheetOpen = page => page.evaluate(() => document.getElementById('sheet').open);
    /** T2: גרירה אל צד ה-<span> של השורה (ולא לתוכו — E2E 🟡1) נשארת באותה רובריקה, ובטלפון שואלת את שאלת V7 ("בכל המכשירים / רק כאן") — עונים "בכל המכשירים". */
    const answerAll = page => page.evaluate(() => { const d = document.getElementById('sheet'), b = d.open && /סדר ב/.test(d.textContent) && d.querySelector('[data-choice="all"]'); if (b) b.click(); return !!b; }).then(x => (x ? sleep(400) : null));
    const closeSheet = page => page.evaluate(() => { const d = document.getElementById('sheet'); if (d.open) d.close(); });

    // ═══ הכנה ═══
    {
      const ctx = await mk(1280, 900, false), page = await newPage(ctx, 'prep');
      await page.goto(base + '/#/project'); await page.waitForSelector('.pj-tree .pj-row');
      await page.evaluate(() => api('pf_migrate', {}));
      await ctx.close();
    }

    // ═══ 1. 390×844 — המסך ═══
    const ctx = await mk(390, 844), page = await newPage(ctx, '390');
    await openEd(page);
    let st = await page.evaluate(() => {
      const bar = document.querySelector('.ed-bar'), vis = [...bar.children].filter(e => e.offsetParent && e.getBoundingClientRect().width);
      const tops = vis.map(e => Math.round(e.getBoundingClientRect().top + e.getBoundingClientRect().height / 2));
      const inView = s => { const e = bar.querySelector(s), r = e.getBoundingClientRect(); return r.left >= 0 && r.right <= innerWidth; };
      return { sw: document.documentElement.scrollWidth, w: innerWidth, oneRow: Math.max(...tops) - Math.min(...tops) <= 4, rows: [...new Set(tops)], nav: inView('[data-panel="nav"]'), info: inView('[data-panel="info"]'), barR: bar.getBoundingClientRect().right };
    });
    ok(st.sw <= st.w, '390: אין גלילה אופקית לעמוד', st);
    ok(st.oneRow && st.barR <= st.w + 1, '390: הסרגל העליון בשורה אחת (מה שלא נכנס — גולל בתוך הסרגל)', st);
    ok(st.nav && st.info, '390: 🌳 ו-ℹ️ גלויים בסרגל בלי לגלול', st);
    await shot(page, 'v14-390-start');

    // ═══ 2. בחירה (V1) — הקשה, הקשה חוזרת = הורה, פירורי לחם ═══
    const H1 = 'h1';
    const cvTop = () => page.evaluate(() => Math.round(document.querySelector('.ed-canvas').getBoundingClientRect().top));
    const top0 = await cvTop();
    let p = await at(page, H1); await tap(page, p);
    const s1 = await selName(page);
    ok(await cvTop() === top0, 'בחירה אינה מזיזה את הקנבס (אחרת ההקשה השנייה של הקשה כפולה נוחתת על אלמנט אחר)', [top0, await cvTop()]);
    ok(/^h1/.test(s1 || ''), 'V1 · הקשה בוחרת (h1)', s1);
    await sleep(500);                                                       // אחרי חלון ההקשה הכפולה
    p = await at(page, H1, 0, 0.5, 0.5, false); await tap(page, p);
    const s2 = await selName(page);
    ok(s2 && !/^h1/.test(s2), 'V1 · הקשה חוזרת על הנבחר — עולה להורה', s2);
    st = await page.evaluate(() => { const cr = [...document.querySelectorAll('.ed-crumb')]; return cr.map(b => ({ t: b.textContent, h: b.getBoundingClientRect().height })); });
    ok(st.length >= 3 && st.every(x => x.h >= 44), 'פירורי לחם — כל פירור 44px לפחות במגע', st);
    await page.evaluate(() => { const cr = [...document.querySelectorAll('.ed-crumb')]; cr[1].scrollIntoView({ inline: 'center' }); });
    { const r = await page.evaluate(() => { const b = [...document.querySelectorAll('.ed-crumb')][1], r = b.getBoundingClientRect(); return { x: r.x + r.width / 2, y: r.y + r.height / 2, t: b.textContent }; }); await tap(page, r); ok((await selName(page) || '').startsWith(r.t.split(/[.#]/)[0]) && await selName(page) !== s2, 'הקשה על פירור בוחרת אותו (' + r.t + ')', await selName(page)); }

    // ═══ 3. הגיליון — שלושה גבהים, החלקה סוגרת, נזכר ═══
    await select(page, H1);
    await tap(page, await rectOf(page, '.ed-bar [data-panel="info"]').then(r => ({ x: r.cx, y: r.cy })));
    st = await page.evaluate(() => ({ cls: document.querySelector('.ed').className, h: document.querySelector('.ed-info').getBoundingClientRect().height, grip: !!document.querySelector('.ed-info [data-ts-grip]') }));
    ok(/info-open/.test(st.cls) && /ts-half/.test(st.cls) && Math.abs(st.h - 422) < 8 && st.grip, 'הפאנל נפתח כגיליון בחצי גובה, עם ידית אחיזה', st);
    const grip = async () => { const r = await rectOf(page, '.ed-info [data-ts-grip]'); return { x: r.cx, y: r.cy }; };
    let g = await grip(); await realDrag(page, g, { x: g.x, y: 60 });
    st = await page.evaluate(() => ({ cls: document.querySelector('.ed').className, h: Math.round(document.querySelector('.ed-info').getBoundingClientRect().height), ls: localStorage.getItem('kol-editor-sheet') }));
    ok(/ts-full/.test(st.cls) && st.h > 760 && st.ls === 'full', 'גרירת הידית למעלה — מלא (ונזכר)', st);
    g = await grip(); await realDrag(page, g, { x: g.x, y: 760 }, { steps: 12, stepMs: 40 });
    st = await page.evaluate(() => ({ cls: document.querySelector('.ed').className, h: Math.round(document.querySelector('.ed-info').getBoundingClientRect().height), ls: localStorage.getItem('kol-editor-sheet') }));
    ok(/ts-row/.test(st.cls) && st.h <= 140 && st.ls === 'row', 'גרירה למטה — שורה (רק הכותרת והלשוניות)', st);
    st = await page.evaluate(() => { const s = document.querySelector('.ed-info'), t = s.querySelector('.ed-ptabs').getBoundingClientRect(); return { tabsIn: t.bottom <= s.getBoundingClientRect().bottom + 1, tabs: t.height }; });
    ok(st.tabsIn, 'בגובה "שורה" — הלשוניות גלויות', st);
    g = await grip(); await realDrag(page, g, { x: g.x, y: 470 }, { steps: 8, stepMs: 40 });
    st = await page.evaluate(() => ({ cls: document.querySelector('.ed').className, h: Math.round(document.querySelector('.ed-info').getBoundingClientRect().height) }));
    ok(/ts-half/.test(st.cls), 'גרירה למעלה משורה — חצי', { st, g });
    g = await grip(); await tap(page, g);
    ok(/ts-full/.test(await page.evaluate(() => document.querySelector('.ed').className)), 'הקשה על הידית — הגובה הבא (חצי → מלא)');
    await page.evaluate(() => Editor._state.touch.set('row')); await sleep(350);
    g = await grip(); await realDrag(page, g, { x: g.x, y: 840 }, { steps: 6 });
    ok(!/info-open/.test(await page.evaluate(() => document.querySelector('.ed').className)), 'החלקה למטה מ"שורה" — סוגרת את הגיליון');
    await page.evaluate(() => Editor._state.touch.set('half'));
    await page.reload(); await ready(page); await sleep(500);
    await page.evaluate(() => document.querySelector('.ed-bar [data-panel="nav"]').click()); await sleep(300);
    st = await page.evaluate(() => ({ cls: document.querySelector('.ed').className, h: Math.round(document.querySelector('.ed-nav').getBoundingClientRect().height), grip: !!document.querySelector('.ed-nav [data-ts-grip]') }));
    ok(/ts-half/.test(st.cls) && Math.abs(st.h - 422) < 8 && st.grip, 'אחרי טעינה מחדש — הגובה האחרון (חצי), גם ל-Navigator', st);
    await page.evaluate(() => { localStorage.setItem('kol-editor-sheet', 'bogus'); });

    // ═══ 4. האלמנט הנבחר — גלוי מעל הגיליון ═══
    const visible = (sel, i = 0) => page.evaluate(([s, i]) => {
      const c = Editor._state.canvas, e = c.doc.querySelectorAll(s)[i], r = e.getBoundingClientRect(), f = c.frame.getBoundingClientRect(), side = document.querySelector('.ed.nav-open .ed-nav, .ed.info-open .ed-info');
      const top = f.top + r.top * c.scale, bot = f.top + r.bottom * c.scale, sTop = side ? side.getBoundingClientRect().top : innerHeight, cTop = c.host.getBoundingClientRect().top;
      return { top: Math.round(top), bot: Math.round(bot), sTop: Math.round(sTop), cTop: Math.round(cTop), ok: top >= cTop - 1 && top < sTop - 8 };
    }, [sel, i]);
    // אלמנט בתחתית העמוד — אחרי בחירה הוא מעל ראש הגיליון
    const lastSel = await page.evaluate(() => { const c = Editor._state.canvas, real = e => !e.closest('[data-kol-canvas]') && c.nodeOf(e) && c.elOf(c.nodeOf(e)) === e, all = [...c.doc.body.querySelectorAll('p, h2, h3, a, button, footer, li')].filter(e => e.getClientRects().length && real(e)); const e = all[all.length - 1]; e.setAttribute('data-v14-last', ''); return e.localName; });
    await page.evaluate(() => { const c = Editor._state.canvas; c.frame.contentWindow.scrollTo(0, 0); const n = c.nodeOf(c.doc.querySelector('[data-v14-last]')); c.select(n); }); await sleep(700);
    st = await visible('[data-v14-last]');
    ok(st.ok, 'האלמנט הנבחר (האחרון בעמוד, ' + lastSel + ') נגלל אל מעל ראש הגיליון', st);
    // אלמנט גבוה מהשטח שנשאר — ראשו גלוי
    await page.evaluate(() => { const c = Editor._state.canvas; c.frame.contentWindow.scrollTo(0, 99999); const n = c.nodeOf(c.doc.querySelector('.kadisha-page') || c.doc.body.firstElementChild); c.select(n); }); await sleep(700);
    st = await visible('.kadisha-page, body > *');
    ok(st.ok, 'אלמנט גבוה מהשטח שנשאר — ראשו גלוי', st);
    await shot(page, 'v14-390-keep-visible');

    // ═══ 5. Navigator בגיליון — ↑ ↓ ⤵ ⤴ לשורה הנבחרת ═══
    const sib = await page.evaluate(() => { const c = Editor._state.canvas, real = e => !e.closest('[data-kol-canvas]') && c.nodeOf(e) && c.elOf(c.nodeOf(e)) === e; for (const e of c.doc.body.querySelectorAll('*')) { if (!real(e)) continue; const sibs = [...(e.parentElement?.children || [])].filter(x => x.getClientRects().length && real(x) && !/^(script|style|template)$/.test(x.localName)); const uq = e.id ? '#' + CSS.escape(e.id) : [...e.classList].map(k => '.' + CSS.escape(k)).find(k => c.doc.querySelectorAll(k).length === 1); if (uq && sibs.length >= 3 && sibs.indexOf(e) === 1 && !/^(script|style|template)$/.test(e.localName)) { window.__mv = uq; return { sel: uq, tag: e.localName, cls: e.className, i: [...e.parentElement.children].indexOf(e) }; } } return null; });
    ok(!!sib, 'נמצא אלמנט עם אחים להזזה', sib);
    const MV = sib && sib.sel;
    await page.evaluate(() => { const c = Editor._state.canvas, n = c.nodeOf(c.doc.querySelector(window.__mv)); c.select(n); }); await sleep(300);
    st = await page.evaluate(() => { const b = document.querySelector('[data-ts-navtools]'); return { shown: b && !b.hidden, btns: b ? [...b.querySelectorAll('[data-ts-nv]')].map(x => x.dataset.tsNv + (x.disabled ? '-off' : '') + ':' + Math.round(x.getBoundingClientRect().height)) : [] }; });
    ok(st.shown && st.btns.length === 5 && st.btns.every(x => +x.split(':')[1] >= 44), 'Navigator: סרגל ↑ ↓ ⤵ ⤴ "העבר אל…" לשורה הנבחרת, 44px', st);
    let idx = () => page.evaluate(() => { const e = Editor._state.canvas.doc.querySelector(window.__mv); return [...e.parentElement.children].indexOf(e); });
    let i0 = await idx();
    await tap(page, await rectOf(page, '[data-ts-nv="down"]').then(r => ({ x: r.cx, y: r.cy })));
    ok(await idx() === i0 + 1 && /למטה/.test(await lastTx(page)), 'Navigator ↓ — השורה ירדה (עסקה "למטה")', [i0, await idx(), await lastTx(page)]);
    await tap(page, await rectOf(page, '[data-ts-nv="up"]').then(r => ({ x: r.cx, y: r.cy })));
    ok(await idx() === i0, 'Navigator ↑ — חזרה למקום');
    await tap(page, await rectOf(page, '[data-ts-nv="to"]').then(r => ({ x: r.cx, y: r.cy })));
    st = await page.evaluate(() => ({ open: document.getElementById('sheet').open, rows: document.querySelectorAll('.ed-moveto-row').length, mv: [...document.querySelectorAll('[data-mv]')].map(b => b.dataset.mv) }));
    ok(st.open && st.rows > 3 && st.mv.join() === 'up,down,in,out', '"↕ העבר אל…" — העץ בגיליון: לפני / בסוף כל יעד, ולמעלה / למטה / פנימה / החוצה', st);
    await closeSheet(page);
    // גרירה ב-Navigator במגע: לחיצה ארוכה על שורה
    await page.evaluate(() => { Editor._state.nav.refresh(); Editor._state.nav.sync(); }); await sleep(200);
    st = await page.evaluate(() => { const r = document.querySelector('.nv-row.sel'); if (!r) return null; const b = r.getBoundingClientRect(); return { x: b.x + b.width / 2, y: b.y + b.height / 2, h: b.height }; });
    if (st) {
      const from = st, to = { x: st.x, y: st.y + 70 }, tN = await page.evaluate(() => Editor._state.doc.text());
      const mid = await realDrag(page, from, to, { hold: 650, steps: 10, during: () => page.evaluate(() => !!Editor._state.build.drag.active) });
      await answerAll(page);
      const tN1 = await page.evaluate(() => Editor._state.doc.text());
      ok(mid === true && tN1 !== tN, 'Navigator: לחיצה ארוכה על שורה ← גרירה במגע (' + await lastTx(page) + ')', { mid });
      if (tN1 !== tN) await undo(page);
      ok(await page.evaluate(() => Editor._state.doc.text()) === tN, 'Navigator: Ctrl+Z מחזיר');
    } else ok(false, 'Navigator: השורה הנבחרת לא נמצאה');
    await page.evaluate(() => document.querySelector('.ed-nav .ed-side-head .icon-btn').click()); await sleep(250);

    // ═══ 6. בסרגל הנבחר: ⬆ ⬇ (V4 — ולצד הגרירה) ═══
    await page.evaluate(() => { const c = Editor._state.canvas, n = c.nodeOf(c.doc.querySelector(window.__mv)); c.select(n); }); await sleep(300);
    st = await page.evaluate(() => [...document.querySelectorAll('.ed-seltools [data-ts-mv]')].map(b => b.dataset.tsMv + ':' + Math.round(b.getBoundingClientRect().width)));
    ok(st.length === 2 && st.every(x => +x.split(':')[1] >= 40), 'סרגל הנבחר במגע: ⬆ ⬇ (החלפה עם האח)', st);
    i0 = await idx();
    await page.evaluate(() => document.querySelector('.ed-seltools [data-ts-mv="down"]').scrollIntoView({ inline: 'center', block: 'nearest' }));
    st = await page.evaluate(() => { const b = document.querySelector('.ed-seltools [data-ts-mv="down"]'), r = b.getBoundingClientRect(); return { x: r.x + r.width / 2, y: r.y + r.height / 2, top: document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2) === b }; });
    ok(st.top, '⬇ נגיש בהקשה (סרגל הנבחר גולל בתוך שורת השביל)', st);
    await tap(page, st);
    ok(await idx() === i0 + 1, '⬇ — האלמנט אחרי האח', [i0, await idx()]);
    await undo(page);
    ok(await idx() === i0, 'Ctrl+Z מחזיר');

    // ═══ 7. גרירת סדר במגע (V4): לחיצה ארוכה על הנבחר ← גרירה, קו יעד ותווית, גלילה בקצה ═══
    {
      const tgt = await page.evaluate(() => { const e = Editor._state.canvas.doc.querySelector(window.__mv), p = e.parentElement; const k = [...p.children].filter(x => x.getClientRects().length); return k.length; });
      await page.evaluate(() => { const c = Editor._state.canvas, n = c.nodeOf(c.doc.querySelector(window.__mv)); c.select(n); }); await sleep(200);
      const from = await at(page, MV), txt0 = await page.evaluate(() => Editor._state.doc.text());
      const sibAfter = await page.evaluate(() => { const e = Editor._state.canvas.doc.querySelector(window.__mv); let s = e.nextElementSibling; while (s && !s.getClientRects().length) s = s.nextElementSibling; if (s) s.setAttribute('data-v14-after', ''); return !!s; });
      const to = sibAfter ? await at(page, '[data-v14-after]', 0, 0.5, 0.85, false) : { x: from.x, y: from.y + 80 };
      const mid = await touchDrag(page, from, to, { hold: 500, steps: 10, during: () => page.evaluate(() => { const d = Editor._state.build.drag; const tag = document.querySelector('.dg-tag:not([hidden])'); return { active: !!d.active, tag: tag ? tag.textContent : '' }; }) });
      await answerAll(page);
      const after = await idx(), txt1 = await page.evaluate(() => Editor._state.doc.text());
      ok(mid && mid.active && /מעל|מתחת|אחרי|לפני|בתוך|בסוף/.test(mid.tag), 'V4 · לחיצה ארוכה על הנבחר ← גרירה במגע, עם תווית היעד', { mid, tgt });
      const moved4 = txt1 !== txt0 && /^גרירה/.test(await lastTx(page));
      ok(moved4, 'V4 · השחרור הזיז את האלמנט (' + await lastTx(page) + ')', [i0, after]);
      if (moved4) await undo(page);
      ok(await page.evaluate(() => Editor._state.doc.text()) === txt0, 'V4 · Ctrl+Z מחזיר את הגרירה');
      // גלילה אוטומטית בקצה התחתון של הקנבס
      await page.evaluate(() => Editor._state.canvas.frame.contentWindow.scrollTo(0, 0));
      const f0 = await at(page, MV);
      const hb = await rectOf(page, '.ed-canvas');
      await page.evaluate(PT);
      await page.evaluate(([x, y]) => window.__pt.down(x, y), [f0.x, f0.y]); await sleep(500);
      for (let i = 1; i <= 6; i++) { await page.evaluate(([x, y]) => window.__pt.move(x, y), [f0.x, f0.y + (hb.bottom - 12 - f0.y) * i / 6]); await sleep(30); }
      const y0 = await page.evaluate(() => Editor._state.canvas.frame.contentWindow.scrollY);
      for (let i = 0; i < 30; i++) { await page.evaluate(([x, y]) => window.__pt.move(x, y), [f0.x, hb.bottom - 12]); await sleep(30); }
      const y1 = await page.evaluate(() => Editor._state.canvas.frame.contentWindow.scrollY);
      await page.evaluate(() => Editor._state.build.drag.cancel()); await page.evaluate(([x, y]) => window.__pt.up(x, y), [f0.x, hb.bottom - 12]); await sleep(300);
      ok(y1 > y0 + 20, 'V4 · גלילה אוטומטית כשהאצבע בקצה התחתון של הקנבס', { y0, y1 });
      ok(await idx() === i0, 'V4 · ביטול הגרירה — אין שינוי');
    }

    const btn = async sel => { const r = await rectOf(page, sel); return r && r.w ? { x: r.cx, y: r.cy } : null; };
    const tapSel = async sel => { await page.evaluate(s => { const e = document.querySelector(s); if (e) e.scrollIntoView({ block: 'nearest', inline: 'center' }); }, sel); const b = await btn(sel); if (!b) return false; await tap(page, b); return true; };
    // ═══ 8. לחיצה ארוכה על אלמנט לא נבחר — תפריט הקשר כגיליון ═══
    {
      await page.evaluate(() => Editor._state.canvas.select(null)); await sleep(200);
      const p2 = await at(page, H1);
      await page.evaluate(PT); await page.evaluate(([x, y]) => window.__pt.down(x, y), [p2.x, p2.y]); await sleep(700); await page.evaluate(([x, y]) => window.__pt.up(x, y), [p2.x, p2.y]); await sleep(400);
      st = await page.evaluate(() => { const m = document.querySelector('.ed-ctx'); if (!m) return null; const r = m.getBoundingClientRect(), it = [...m.querySelectorAll('.ed-ctx-item')].filter(b => b.getClientRects().length); return { w: Math.round(r.width), bottom: Math.round(r.bottom), iw: innerWidth, ih: innerHeight, n: it.length, minH: Math.min(...it.map(b => b.getBoundingClientRect().height)), close: Math.round(m.querySelector('[data-ctx-close]').getBoundingClientRect().width) }; });
      ok(st && st.w === st.iw && Math.abs(st.bottom - st.ih) <= 1 && st.n >= 10 && st.minH >= 44 && st.close >= 44, 'V13 · לחיצה ארוכה — תפריט ההקשר כגיליון מלמטה, ברוחב המסך, פריטים ו-✕ של 44px', st);
      // פעולה מהתפריט בהקשה: "שכפל" ← Ctrl+Z
      const tq = await page.evaluate(() => Editor._state.doc.text());
      await page.evaluate(() => document.querySelector('.ed-ctx [data-ctx="duplicate"]').scrollIntoView({ block: 'nearest' }));
      const dupOk = await tapSel('.ed-ctx [data-ctx="duplicate"]'); await sleep(500);
            ok(await page.evaluate(() => !document.querySelector('.ed-ctx')) && (await page.evaluate(() => Editor._state.doc.text())) !== tq && /שכפ/.test(await lastTx(page)), 'V13 · "שכפל" מהתפריט במגע — בוצע, והתפריט נסגר (' + await lastTx(page) + ')');
      await undo(page);
      ok(await page.evaluate(() => Editor._state.doc.text()) === tq, 'V13 · Ctrl+Z מחזיר');
      await page.evaluate(() => Editor._state.menu?.close());
    }

    // ═══ 9. הקלדה (V2) + מקלדת וירטואלית ═══
    {
      const p3 = await at(page, H1);
      await page.touchscreen.tap(p3.x, p3.y); await sleep(120); await page.touchscreen.tap(p3.x, p3.y); await sleep(500);
      await page.evaluate(() => Editor._state.touch.fakeKeyboard(320)); await sleep(300);
      const act = await page.evaluate(() => !!Editor._state.text?.active);
      ok(act, 'V2 · הקשה כפולה — עריכת טקסט במקום');
      st = await page.evaluate(() => { const b = document.querySelector('.ed-tbar:not([hidden])'); const r = b && b.getBoundingClientRect(); return r ? { bottom: Math.round(r.bottom), top: Math.round(r.top), ih: innerHeight, pos: getComputedStyle(b).position } : null; });
      ok(st && st.pos === 'fixed' && Math.abs(st.bottom - (st.ih - 320)) <= 2, 'מקלדת: שורת העיצוב (B I קישור…) צמודה מעל המקלדת', st);
      await page.keyboard.press('Control+End'); await page.keyboard.type(' ב'); await sleep(100);
      await page.evaluate(() => Editor._state.touch.keep(Editor._state.canvas.selected)); await sleep(200);
      const v = await page.evaluate(() => { const c = Editor._state.canvas, e = c.elOf(c.selected), r = e.getBoundingClientRect(), f = c.frame.getBoundingClientRect(), bar = document.querySelector('.ed-tbar:not([hidden])').getBoundingClientRect(); return { top: Math.round(f.top + r.top * c.scale), barTop: Math.round(bar.top), cTop: Math.round(c.host.getBoundingClientRect().top) }; });
      ok(v.top >= v.cTop - 1 && v.top < v.barTop, 'מקלדת: השורה הנערכת גלויה מעל שורת העיצוב', v);
      ok(await page.evaluate(() => !!Editor._state.text?.active), 'V2 · העריכה עדיין פעילה אחרי הגלילה אל מעל המקלדת', await page.evaluate(() => { const c = Editor._state.canvas; return { h1: c.doc.querySelector('h1').outerHTML.slice(0, 200), act: document.activeElement && document.activeElement.localName, fa: c.doc.activeElement && c.doc.activeElement.localName }; }));
      await page.keyboard.press('Escape'); await sleep(500);
      const h1t = await page.evaluate(() => Editor._state.doc.text().match(/<h1[^>]*>[\s\S]*?<\/h1>/)[0]);
      ok(/ ב/.test(h1t), 'V2 · ההקלדה נשמרה ב-index.html', h1t);
      await undo(page);
      // שדה בפאנל: הגיליון עולה, השדה גלוי
      await page.evaluate(() => { Editor._state.touch.set('row'); const c = Editor._state.canvas; c.select(c.nodeOf(c.doc.querySelector('h1'))); }); await sleep(200);
      await page.evaluate(() => { document.querySelector('.ed-bar [data-panel="info"]').click(); }); await sleep(200);
      await page.evaluate(() => Editor._tab('design')); await sleep(400);
      const inp = await page.evaluate(() => { const i = [...document.querySelectorAll('.ed-info input[type="text"], .ed-info input:not([type])')].find(x => x.getClientRects().length); if (!i) return null; i.setAttribute('data-v14-inp', ''); return true; });
      if (inp) {
        await page.evaluate(() => document.querySelector('[data-v14-inp]').focus()); await sleep(400);
        st = await page.evaluate(() => { const s = document.querySelector('.ed-info').getBoundingClientRect(), i = document.querySelector('[data-v14-inp]').getBoundingClientRect(); return { cls: document.querySelector('.ed').className, sBot: Math.round(s.bottom), ih: innerHeight, iTop: Math.round(i.top), iBot: Math.round(i.bottom), sTop: Math.round(s.top) }; });
        ok(/ts-half|ts-full/.test(st.cls) && Math.abs(st.sBot - (st.ih - 320)) <= 2 && st.iTop >= st.sTop && st.iBot <= st.sBot, 'מקלדת: שדה בפאנל — הגיליון עולה מעל המקלדת, והשדה גלוי', st);
      } else ok(false, 'שדה טקסט בפאנל העיצוב לא נמצא');
      await page.evaluate(() => { document.activeElement.blur(); Editor._state.touch.fakeKeyboard(0); }); await sleep(200);
    }

    // ═══ 10. −/+ : לחיצה ארוכה חוזרת ═══
    {
      await page.evaluate(() => { Editor._state.touch.set('half'); Editor._tab('design'); }); await sleep(400);
      const pl = await page.evaluate(() => { const b = [...document.querySelectorAll('.ed-info .sg-step')].find(x => x.textContent === '+' && !x.disabled && x.getClientRects().length && x.closest('.sg-line').querySelector('input')); if (!b) return null; b.scrollIntoView({ block: 'center' }); b.setAttribute('data-v14-plus', ''); window.__plus = b.getAttribute('aria-label'); const inp = b.parentElement.querySelector('input'); const r = b.getBoundingClientRect(); return { x: r.x + r.width / 2, y: r.y + r.height / 2, v: inp.value, w: Math.round(r.width), h: Math.round(r.height) }; });
      if (pl) {
        ok(pl.w >= 44 && pl.h >= 44, '−/+ בפאנל — 44px במגע', pl);
        ok(await page.evaluate(p => document.elementFromPoint(p.x, p.y) === document.querySelector('[data-v14-plus]'), pl), '+ אינו מכוסה (הגיליון ברוחב המסך)');
        await realDrag(page, pl, pl, { hold: 1500, steps: 0 }); await sleep(300);
        const v1 = await page.evaluate(() => { const b = [...document.querySelectorAll('.ed-info .sg-step')].find(x => x.getAttribute('aria-label') === window.__plus); return b ? b.parentElement.querySelector('input').value : null; });
        const num = s => parseFloat(String(s).replace(/[^\d.-]/g, '')) || 0;
        ok(v1 != null && num(v1) - num(pl.v) >= 4, '+ בלחיצה ארוכה — חוזר ומאיץ (' + pl.v + ' ← ' + v1 + ')', { from: pl.v, to: v1 });
        for (let i = 0; i < 3; i++) { const t = await lastTx(page); if (!/.+/.test(t)) break; }
        await undo(page);
      } else ok(false, '+ בפאנל העיצוב לא נמצא');
    }

    // ═══ 11. ידית מרווח במגע (V3): 44px, ערך צף מעל האצבע ═══
    {
      await page.evaluate(() => { document.querySelector('.ed-info .ed-side-head .icon-btn').click(); }); await sleep(200);
      await page.evaluate(() => { const c = Editor._state.canvas; c.select(c.nodeOf(c.doc.querySelector('h1'))); }); await sleep(200);
      await page.evaluate(() => document.querySelector('[data-space]').click()); await sleep(400);
      const hd = await page.evaluate(() => { const b = [...document.querySelectorAll('.sp-h')].find(x => !x.hidden && x.getClientRects().length && /^padding-(top|bottom)/.test(x.dataset.sp)) || [...document.querySelectorAll('.sp-h')].find(x => !x.hidden && x.getClientRects().length); if (!b) return null; const r = b.getBoundingClientRect(), x = r.x + r.width / 2, y = r.y + r.height / 2; const hit = document.elementFromPoint(x + 18, y + 18), hit0 = document.elementFromPoint(x, y); return { x, y, sp: b.dataset.sp, w: r.width, hh: r.height, hit18: hit === b, at18: hit && hit.className, at0: hit0 === b ? 'self' : hit0 && hit0.className }; });
      if (hd) {
        ok(hd.hit18, 'V3 · ידית המרווח — אזור מגע 44×44 (נקודה 18px מהמרכז עדיין הידית)', hd);
        const vert = /top|bottom/.test(hd.sp);
        const mid = await realDrag(page, hd, vert ? { x: hd.x, y: hd.y + 40 } : { x: hd.x - 40, y: hd.y }, { steps: 8, during: () => page.evaluate(() => { const n = document.querySelector('.sp-num:not([hidden])'); return n ? Math.round(n.getBoundingClientRect().bottom) : null; }) });
        const fy = hd.y + (vert ? 40 * 7 / 8 : 0);
        ok(mid != null && mid < fy - 20, 'V3 · בזמן הגרירה — הערך הצף מעל האצבע', { numBottom: mid, finger: Math.round(fy) });
        ok(/מרווח|ריפוד|שוליים|padding|margin/.test(await lastTx(page)), 'V3 · גרירת ידית במגע נכתבה (' + await lastTx(page) + ')');
        await undo(page);
      } else ok(false, 'V3 · ידית מרווח לא הופיעה במגע');
      await page.evaluate(() => document.querySelector('[data-space]').click()); await sleep(200);
    }

    // ═══ 12. צביטה — הגדלה עד 300%, "⤢ התאם" ═══
    {
      const cv = await rectOf(page, '.ed-canvas');
      const cx = cv.cx, cy = cv.cy;
      await page.evaluate(PT);
      await page.evaluate(([x, y]) => { window.__pt.down(x - 20, y, false, 11); window.__pt.down(x + 20, y, false, 12); }, [cx, cy]);
      for (let i = 1; i <= 8; i++) { await page.evaluate(([x, y, d]) => { window.__pt.move(x - d, y, 11); window.__pt.move(x + d, y, 12); }, [cx, cy, 20 + i * 10]); await sleep(20); }
      await page.evaluate(([x, y]) => { window.__pt.up(x - 100, y, 11); window.__pt.up(x + 100, y, 12); }, [cx, cy]); await sleep(300);
      st = await page.evaluate(() => ({ z: Editor._state.touch.zoom, scale: Editor._state.canvas.scale, fit: !document.querySelector('[data-ts-fit]').hidden, fitT: document.querySelector('[data-ts-fit]').textContent }));
      ok(st.z > 2 && st.z <= 3 && st.fit, 'צביטה — הקנבס מוגדל (' + Math.round(st.z * 100) + '%), ו"⤢ התאם" מופיע', st);
      await page.evaluate(() => Editor._state.touch.setZoom(9));
      ok(await page.evaluate(() => Editor._state.touch.zoom) === 3, 'הגדלה — עד 300% לכל היותר');
      // במצב מוגדל: הקשה בוחרת את האלמנט הנכון
      let p4 = await at(page, H1);
      p4 = await page.evaluate(p => { const host = Editor._state.canvas.host, r = host.getBoundingClientRect(); host.scrollLeft += p.x - (r.left + r.width / 2); return p; }, p4);
      p4 = await at(page, H1, 0, 0.5, 0.5, false);
      await tap(page, p4);
      ok(/^h1/.test(await selName(page) || ''), 'במצב מוגדל — הקשה בוחרת את האלמנט שמתחת לאצבע');
      await shot(page, 'v14-390-zoom');
      await tap(page, await rectOf(page, '[data-ts-fit]').then(r => ({ x: r.cx, y: r.cy })));
      st = await page.evaluate(() => ({ z: Editor._state.touch.zoom, fit: document.querySelector('[data-ts-fit]').hidden }));
      ok(st.z === 1 && st.fit, '"⤢ התאם" — חזרה ל-100%', st);
    }

    // ═══ 13. הטבלה של §2.4 — פעולה במגע לכל פרוסה ═══
    {
      // V5 · החלפת תמונת רקע — הכפתור בסרגל
      const sec = await page.evaluate(() => { const c = Editor._state.canvas; for (const e of c.doc.querySelectorAll('body *')) { const bg = c.frame.contentWindow.getComputedStyle(e).backgroundImage; if (/url\(/.test(bg) && e.getClientRects().length) { const n = c.nodeOf(e); if (n) { c.select(n); return e.localName + '.' + e.className; } } } return null; });
      await sleep(300);
      if (sec) { const okT = await tapSel('[data-bgt="replace"]'); await sleep(400); ok(okT && await sheetOpen(page), 'V5 · "🖼 החלף" תמונת רקע — הקשה פותחת את הבחירה (' + sec + ')'); await closeSheet(page); }
      else ok(true, 'V5 · אין בעמוד רקע-תמונה — מדלג (נבדק ב-v5)');
      // V6 · מיקום חופשי
      await select(page, MV); await sleep(300);
      const t6 = await tapSel('[data-free-btn]'); await sleep(500);
      ok(t6 && /מיקום|חופשי|position/.test(await lastTx(page) + (await page.evaluate(() => document.querySelector('[data-free-btn]').getAttribute('aria-pressed')))), 'V6 · "✥ מיקום חופשי" — הקשה (' + await lastTx(page) + ')');
      if (await page.evaluate(() => document.querySelector('[data-free-btn]').getAttribute('aria-pressed') === 'true')) await undo(page);
      // V7 · עריכה במצב "טלפון" — נכתבת ל-@media
      st = await page.evaluate(() => ({ dev: Editor._state.device, w: Editor._state.canvas.viewport, host: Editor._state.canvas.host.clientWidth, frameW: Math.round(Editor._state.canvas.frame.getBoundingClientRect().width), scale: Editor._state.canvas.scale }));
      ok(st.scale === 1 && st.dev === 'mobile' && st.frameW === st.host, 'V7 · בטלפון, מצב "טלפון" נערך ברוחב האמיתי (קנה מידה 1, המסגרת = רוחב הקנבס)', st);
      // V8 · מצב :hover — כפתור המצב בפאנל
      await select(page, H1); await page.evaluate(() => { document.querySelector('.ed-bar [data-panel="info"]').click(); Editor._tab('design'); }); await sleep(500);
      const hv = await page.evaluate(() => { const b = document.querySelector('.ed-info .sg-state-seg [data-state=":hover"]'); if (!b) return null; b.scrollIntoView({ block: 'center' }); const r = b.getBoundingClientRect(); return { x: r.x + r.width / 2, y: r.y + r.height / 2, h: r.height }; });
      if (hv) { await tap(page, hv); ok(await page.evaluate(() => document.querySelector('.ed-info .sg-state-seg [data-state=":hover"]').getAttribute('aria-pressed') === 'true' || /hover/.test(document.querySelector('.sg-state-seg [aria-pressed="true"]')?.dataset.state || '')) && hv.h >= 44, 'V8 · מצב :hover — הקשה בוחרת את המצב (44px)', hv); await page.evaluate(() => document.querySelector('.ed-info .sg-state-seg [data-state="normal"]')?.click()); }
      else ok(false, 'V8 · כפתורי המצב לא נמצאו');
      // V9 · "🌐 כללי" — משתנים
      const t9 = await tapSel('.ed-ptabs [data-ptab="glob"]'); await sleep(500);
      ok(t9 && await page.evaluate(() => !document.querySelector('.ed-info .gl').hidden && document.querySelector('.ed-info .gl').textContent.length > 20), 'V9 · לשונית "🌐 כללי" נפתחת בהקשה');
      // V10 · צל / רדיוס — הקבוצה בפאנל, וידית הרדיוס 44px
      await page.evaluate(() => Editor._tab('design')); await sleep(300);
      const fx = await page.evaluate(() => { const s = document.querySelector('.ed-info .sg-fx, .ed-info [data-sec="effects"], .ed-info [data-sec="border"]'); return !!s; });
      ok(fx, 'V10 · קבוצות האפקטים (צל / רדיוס / מסגרת) בפאנל העיצוב בטלפון');
      // V11 · "המילה הזו" — סרגל הרשימות
      ok(await page.evaluate(() => !document.querySelector('[data-lv-strips]').hidden), 'V11 · סרגל רשימות הנתונים גלוי בטלפון');
      // V12 · רכיבים — לשונית ב-Navigator
      await page.evaluate(() => document.querySelector('.ed-bar [data-panel="nav"]').click()); await sleep(300);
      const t12 = await tapSel('[data-ntab="comp"]'); await sleep(400);
      ok(t12 && await page.evaluate(() => !document.querySelector('[data-comp-body]').hidden), 'V12 · לשונית "🧩 רכיבים" נפתחת בהקשה בגיליון');
      await tapSel('[data-ntab="lib"]'); await sleep(300);
      const libItem = await page.evaluate(() => { const b = document.querySelector('.ed-libbody [data-lib]'); if (!b) return null; const r = b.getBoundingClientRect(); return { h: r.height }; });
      ok(libItem && libItem.h >= 36, 'V4 · הספרייה בגיליון — פריטים להקשה', libItem);
      await page.evaluate(() => document.querySelector('.ed-nav .ed-side-head .icon-btn').click()); await sleep(200);
    }

    // ═══ 13a. V13 במגע: חיפוש פעולה מכפתור, היסטוריה, תבניות ═══
    {
      await select(page, H1); await sleep(300);
      st = await page.evaluate(() => { document.querySelector('.ed-bar').scrollLeft = 0; const b = document.querySelector('[data-ts-palette]'), r = b && b.getBoundingClientRect(); return b ? { hidden: b.hidden, inView: r.left >= 0 && r.right <= innerWidth, w: Math.round(r.width) } : null; });
      ok(st && !st.hidden && st.inView, 'V13 · 🔎 חיפוש פעולה (Ctrl+E) — כפתור בסרגל, גלוי במגע', st);
      await tapSel('[data-ts-palette]'); await sleep(400);
      st = await page.evaluate(() => { const p = document.querySelector('[data-palette]'); if (!p) return null; const rows = [...p.querySelectorAll('.pl-row')].filter(b => b.getClientRects().length); return { n: rows.length, minH: Math.min(...rows.map(b => b.getBoundingClientRect().height)), sw: document.documentElement.scrollWidth, iw: innerWidth }; });
      ok(st && st.n > 3 && st.minH >= 44 && st.sw <= st.iw, 'V13 · החיפוש נפתח בהקשה; שורות 44px, בלי גלילה אופקית', st);
      await page.fill('[data-palette] .pl-in', 'שכפל'); await sleep(300);
      const tq2 = await page.evaluate(() => Editor._state.doc.text());
      const row = await page.evaluate(() => { const b = [...document.querySelectorAll('[data-palette] .pl-row')].find(x => /שכפל/.test(x.textContent) && x.getAttribute('aria-disabled') !== 'true'); if (!b) return null; const r = b.getBoundingClientRect(); return { x: r.x + r.width / 2, y: r.y + r.height / 2 }; });
      if (row) { await tap(page, row); await sleep(500); }
      ok(row && (await page.evaluate(() => Editor._state.doc.text())) !== tq2, 'V13 · פעולה מהחיפוש בהקשה ("שכפל") — בוצעה');
      if (row) await undo(page);
      await page.evaluate(() => Editor._state.palette?.close());
      // היסטוריה ותבניות — לשוניות בגיליון ה-Navigator
      await page.evaluate(() => { if (!document.querySelector('.ed').classList.contains('nav-open')) document.querySelector('.ed-bar [data-panel="nav"]').click(); }); await sleep(300);
      const th = await tapSel('[data-ntab="hist"]'); await sleep(400);
      st = await page.evaluate(() => { const rows = [...document.querySelectorAll('[data-hist-list] .hs-row')].filter(b => b.getClientRects().length); const tabs = [...document.querySelectorAll('.ed-ntabs button')].map(b => Math.round(b.getBoundingClientRect().height)); return { n: rows.length, minH: rows.length ? Math.min(...rows.map(b => b.getBoundingClientRect().height)) : 0, tabs, sw: document.documentElement.scrollWidth, iw: innerWidth }; });
      ok(th && st.n >= 2 && st.minH >= 44 && st.tabs.every(x => x >= 44) && st.sw <= st.iw, 'V13 · 🕘 היסטוריה בגיליון — שורות ולשוניות 44px', st);
      // קפיצה בהיסטוריה בהקשה: לשורת "התחלה" ובחזרה
      const tH = await page.evaluate(() => Editor._state.doc.text());
      const nTx = await page.evaluate(() => Editor._state.tx.length);
      if (nTx) {
        await tapSel('[data-hist="start"]'); await sleep(700);
        const back = await page.evaluate(() => Editor._state.tx.length);
        ok(back === 0, 'V13 · הקשה על "התחלה" בהיסטוריה — חזרה לתחילת העריכה', { nTx, back });
        await tapSel('[data-hist="r' + (nTx - 1) + '"]'); await sleep(700);
        ok(await page.evaluate(() => Editor._state.doc.text()) === tH, 'V13 · הקשה על השורה האחרונה — חזרה למצב העדכני');
      } else ok(true, 'V13 · אין עסקאות — דילוג על הקפיצה בהיסטוריה');
      const tt = await tapSel('[data-ntab="tpl"]'); await sleep(800);
      st = await page.evaluate(() => { const b = [...document.querySelectorAll('[data-tpl-save-page], [data-tpl-import]')].filter(x => x.getClientRects().length); return { n: b.length, minH: b.length ? Math.min(...b.map(x => x.getBoundingClientRect().height)) : 0, sw: document.documentElement.scrollWidth, iw: innerWidth }; });
      ok(tt && st.n >= 2 && st.minH >= 34 && st.sw <= st.iw, 'V13 · 📄 תבניות בגיליון — נפתחות בהקשה, בלי גלילה אופקית', st);
      await shot(page, 'v14-390-v13-templates');
      await tapSel('[data-ntab="tree"]'); await sleep(200);
      await page.evaluate(() => document.querySelector('.ed-nav .ed-side-head .icon-btn').click()); await sleep(200);
    }

    // ═══ 13b. E2E 🟡3: מיקום חופשי מהמחשב — אזהרה בטלפון, ו"בזרימה בטלפון בלבד" ═══
    {
      const t0 = await page.evaluate(() => [...Editor._state.sheets.values()].map(x => x.doc.text()).join('\n'));
      await page.evaluate(() => document.querySelector('.ed-dev [data-dev="desktop"]').click()); await sleep(600);
      await select(page, MV); await sleep(300);
      await page.evaluate(() => { const S = Editor._state; S.pos.on(S.canvas.selected, S.pos.pickRef); }); await sleep(600);
      const freeD = await page.evaluate(() => Editor._state.pos.isFree(Editor._state.canvas.selected));
      await page.evaluate(() => document.querySelector('.ed-dev [data-dev="mobile"]').click()); await sleep(600);
      await select(page, MV); await sleep(600);
      st = await page.evaluate(() => { const w = document.querySelector('[data-ts-freewarn]'); return { free: Editor._state.pos.isFree(Editor._state.canvas.selected), shown: !!w && !w.hidden, text: w ? w.textContent : '' }; });
      ok(freeD && st.free && st.shown && /מחשב/.test(st.text), 'E2E · מיקום חופשי שנקבע במחשב — בטלפון מופיעה אזהרה', st);
      await tapSel('[data-ts-flow]'); await sleep(700);
      st = await page.evaluate(() => { const S = Editor._state; return { free: S.pos.isFree(S.canvas.selected), shown: !document.querySelector('[data-ts-freewarn]').hidden, css: [...S.sheets.values()].map(x => x.doc.text()).join('\n') }; });
      ok(!st.free && !st.shown && /@media[^{]*max-width[^{]*\{[^@]*position:\s*static/.test(st.css), '"⇩ בזרימה בטלפון בלבד" — position: static ב-@media, והאזהרה נעלמת', { free: st.free, shown: st.shown });
      await page.evaluate(() => document.querySelector('.ed-dev [data-dev="desktop"]').click()); await sleep(600);
      await select(page, MV); await sleep(300);
      ok(await page.evaluate(() => Editor._state.pos.isFree(Editor._state.canvas.selected)), 'במחשב — האלמנט נשאר במיקום החופשי');
      for (let i = 0; i < 6 && await page.evaluate(() => [...Editor._state.sheets.values()].map(x => x.doc.text()).join('\n')) !== t0; i++) await undo(page);
      ok(await page.evaluate(() => [...Editor._state.sheets.values()].map(x => x.doc.text()).join('\n')) === t0, 'Ctrl+Z מחזיר את ה-CSS');
      await page.evaluate(() => document.querySelector('.ed-dev [data-dev="mobile"]').click()); await sleep(600);
    }
    ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), '390: בסוף — עדיין אין גלילה אופקית');
    await flush(page);
    await ctx.close();

    // ═══ 14. 360×740 ═══
    {
      const c2 = await mk(360, 740), pg = await newPage(c2, '360'); await openEd(pg);
      st = await pg.evaluate(() => ({ sw: document.documentElement.scrollWidth, w: innerWidth, cv: Math.round(document.querySelector('.ed-canvas').getBoundingClientRect().height) }));
      ok(st.sw <= st.w && st.cv > 360, '360×740: אין גלילה אופקית, והקנבס ממלא את המסך', st);
      await shot(pg, 'v14-360'); await c2.close();
    }
    // ═══ 15. מצב רוחב — הגיליון בצד, 40% ═══
    {
      const c3 = await mk(844, 390), pg = await newPage(c3, 'land'); await openEd(pg);
      await pg.evaluate(() => document.querySelector('.ed-bar [data-panel="info"]').click()); await sleep(400);
      st = await pg.evaluate(() => { const r = document.querySelector('.ed-info').getBoundingClientRect(); return { w: Math.round(r.width), h: Math.round(r.height), top: Math.round(r.top), iw: innerWidth, ih: innerHeight }; });
      ok(Math.abs(st.w - st.iw * 0.4) < 4 && st.h >= st.ih - 2 && st.top <= 1, 'מצב רוחב (844×390): הגיליון בצד, ברוחב 40%', st);
      await shot(pg, 'v14-landscape'); await c3.close();
    }
    // ═══ 16. טאבלט 768×1024 — הפאנל בצד (320px), Navigator גיליון ═══
    {
      const c4 = await mk(768, 1024), pg = await newPage(c4, '768'); await openEd(pg);
      await pg.evaluate(() => document.querySelector('.ed-bar [data-panel="info"]').click()); await sleep(400);
      st = await pg.evaluate(() => { const r = document.querySelector('.ed-info').getBoundingClientRect(), cv = document.querySelector('.ed-canvas').getBoundingClientRect(); return { w: Math.round(r.width), pos: getComputedStyle(document.querySelector('.ed-info')).position, cvW: Math.round(cv.width), overlap: cv.right > r.left && cv.left < r.right, sw: document.documentElement.scrollWidth }; });
      ok(st.w === 320 && st.pos !== 'fixed' && !st.overlap && st.sw <= 768, '768: הפאנל בצד (320px), לא מעל הקנבס', st);
      await pg.evaluate(() => document.querySelector('.ed-bar [data-panel="nav"]').click()); await sleep(400);
      st = await pg.evaluate(() => ({ pos: getComputedStyle(document.querySelector('.ed-nav')).position, grip: document.querySelector('.ed-nav [data-ts-grip]').getBoundingClientRect().height }));
      ok(st.pos === 'fixed' && st.grip > 0, '768: Navigator כגיליון עם ידית', st);
      // במגע בטאבלט: הקשה בוחרת, ⬆ ⬇ בסרגל
      const pt = await pg.evaluate(() => { const c = Editor._state.canvas, e = c.doc.querySelector('h1'); e.scrollIntoView({ block: 'center' }); const r = e.getBoundingClientRect(), f = c.frame.getBoundingClientRect(); return { x: f.left + (r.left + r.width / 2) * c.scale, y: f.top + (r.top + r.height / 2) * c.scale }; });
      await pg.touchscreen.tap(pt.x, pt.y); await sleep(300);
      ok(await pg.evaluate(() => /^h1/.test(Editor._state.canvas.label(Editor._state.canvas.selected)) && document.querySelectorAll('.ed-seltools [data-ts-mv]').length === 2), '768: הקשה בוחרת, ו-⬆ ⬇ בסרגל הנבחר');
      // V13 ב-768: לחיצה ארוכה ← תפריט ההקשר (גיליון, 44px); 🔎 בסרגל
      await pg.evaluate(() => Editor._state.canvas.select(null)); await sleep(500);
      await pg.evaluate(PT); await pg.evaluate(([x, y]) => window.__pt.down(x, y), [pt.x, pt.y]); await sleep(700); await pg.evaluate(([x, y]) => window.__pt.up(x, y), [pt.x, pt.y]); await sleep(400);
      st = await pg.evaluate(() => { const m = document.querySelector('.ed-ctx'); if (!m) return null; const r = m.getBoundingClientRect(), it = [...m.querySelectorAll('.ed-ctx-item')].filter(b => b.getClientRects().length); return { w: Math.round(r.width), iw: innerWidth, minH: Math.min(...it.map(b => b.getBoundingClientRect().height)), pal: !document.querySelector('[data-ts-palette]').hidden }; });
      ok(st && st.w === st.iw && st.minH >= 44 && st.pal, '768 · V13: לחיצה ארוכה — תפריט ההקשר כגיליון, פריטים 44px; 🔎 בסרגל', st);
      await pg.evaluate(() => Editor._state.menu?.close());
      await shot(pg, 'v14-768'); await c4.close();
    }

    // ═══ 17. המחשב לא משתנה (§2.7) ═══
    {
      const css = fs.readFileSync(path.join(ROOT, 'assets/css/editor.css'), 'utf8'), blk = css.slice(css.indexOf('/* V14 —'));
      let depth = 0, bad = [], i = 0, ctxs = [];
      while (i < blk.length) {
        if (blk.startsWith('/*', i)) { i = blk.indexOf('*/', i) + 2; continue; }
        const ch = blk[i];
        if (ch === '{') { const head = blk.slice(blk.lastIndexOf('}', i - 1) + 1, i).replace(/\/\*[\s\S]*?\*\//g, '').trim(); ctxs.push(head); if (depth === 0 && !/^@media[^{]*(max-width:\s*999px|pointer:\s*coarse)/.test(head)) bad.push(head); depth++; }
        else if (ch === '}') { depth--; ctxs.pop(); }
        i++;
      }
      ok(!bad.length && depth === 0, '§2.7 · כל כלל CSS של V14 בתוך max-width: 999px או pointer: coarse', bad);
      for (const [w, hh] of [[1920, 1080], [1366, 768]]) {
        const c5 = await mk(w, hh, false), pg = await newPage(c5, String(w)); await openEd(pg);
        await pg.evaluate(() => { const c = Editor._state.canvas; c.select(c.nodeOf(c.doc.querySelector('h1'))); }); await sleep(400);
        st = await pg.evaluate(() => {
          const ed = document.querySelector('.ed'), r = ed.getBoundingClientRect();
          return { cls: [...ed.classList].filter(c => /^ts-/.test(c)), grips: [...document.querySelectorAll('[data-ts-grip]')].map(g => g.getBoundingClientRect().height), mv: document.querySelectorAll('[data-ts-mv]').length, navtools: !document.querySelector('[data-ts-navtools]').hidden, cover: getComputedStyle(document.querySelector('.ed-panes')).marginBottom, bar: getComputedStyle(document.querySelector('.ed-bar')).flexWrap, l: Math.round(r.left), r: Math.round(innerWidth - r.right), fit: !document.querySelector('[data-ts-fit]').hidden, pal: !!document.querySelector('[data-ts-palette]') && !document.querySelector('[data-ts-palette]').hidden };
        });
        ok(!st.cls.length && st.grips.every(x => x === 0) && !st.mv && !st.navtools && st.cover === '0px' && !st.fit && !st.pal, w + ': אין שום סימן של V14 (ידיות גיליון, ⬆ ⬇, סרגל Navigator, קיצור הקנבס)', st);
        ok(st.l <= 16 && st.r <= 16, w + ': העורך ממלא את המסך, בלי ריק בצדדים', st);
        await shot(pg, 'v14-' + w); await c5.close();
      }
    }
    ok(errors.length === 0, 'אין שגיאות JavaScript בדפים' + (errors.length ? ': ' + errors.slice(0, 3).join(' | ') : ''));
  } catch (e) { failed++; console.log('❌ חריגה: ' + (e.stack || e)); }
  finally { if (browser) await browser.close(); php.kill(); try { fs.rmSync(data, { recursive: true, force: true }); } catch { /* */ } }
  console.log('\n' + (failed ? '❌ ' + failed + ' נכשלו מתוך ' + ran : '✅ כל ' + ran + ' הבדיקות עברו'));
  process.exit(failed ? 1 : 0);
})();
