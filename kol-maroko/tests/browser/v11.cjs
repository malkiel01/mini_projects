/**
 * פרוסה V11 — רשימת הנתונים וכרטיס המילה בקנבס — בדיקות בדפדפן   node kol-maroko/tests/browser/v11.cjs
 *
 * על העמוד המוסב (F3: 1,496 מילים, שישה נושאים): הכרטיסים מצוירים מהתבנית ומ-data/words.json (12, "הצג עוד"); כל חלק בכרטיס
 * ממופה לצומת בתבנית; בחירה ← אותו חלק בכל הכרטיסים; שינוי בתבנית (עיצוב, גרירה בתוך הכרטיס) ← כולם משתנים, ציור מחדש < 150ms;
 * "המילה הזו" — עריכה במקום ← הטבלה (📚), ו-Ctrl+Z מחזיר; גרירת שדה ← אלמנט קשור; גרירה על אלמנט — קושרת; פאנל הקשירה (טקסט,
 * טקסט מעורב, תכונה, תנאי, מחלקה, חזרה); המכל (מיון, טעינה, פריסה); רכיבי רשימה מהספרייה; מילה לתצוגה; מצבים ("אין תוצאות" נבחר,
 * מסונן, מתנגן); החלון בקנבס; kol-data.js שונה ← פס; N3 — רשימה בקנבס; הזרקה; הקובץ — רק <template> ו-CSS; Ctrl+Z עד ההתחלה;
 * 1920×1080 ו-1366×768 בלי פס ריק; 390 — מגע. צילומים — ל-tmpdir (kol-v11-shots).
 */
'use strict';
const { chromium } = require('playwright');
const { spawn, execFileSync } = require('child_process');
const fs = require('fs'), os = require('os'), path = require('path'), net = require('net');

const ROOT = path.resolve(__dirname, '..', '..');
const OWNER = { sub: 'u-owner', name: 'Owner Test', email: 'owner@example.test', email_verified: true, platform_admin: true };
let failed = 0, ran = 0;
let T0 = Date.now(); const ok = (cond, what, got) => { ran++; const dt = ((Date.now() - T0) / 1000).toFixed(1); T0 = Date.now(); if (cond) console.log('✅ [' + dt + 's] ' + what); else { failed++; console.log('❌ ' + what + (got !== undefined ? '\n   התקבל: ' + JSON.stringify(got).slice(0, 900) : '')); } };
const freePort = () => new Promise(r => { const s = net.createServer().listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => r(p)); }); });
const sleep = ms => new Promise(r => setTimeout(r, ms));

(async () => {
  const data = fs.mkdtempSync(path.join(os.tmpdir(), 'kol-v11-'));
  execFileSync('php', [path.join(__dirname, 'f3-seed.php'), data, path.join(data, 'old-render.html')]);
  const port = await freePort(), base = `http://127.0.0.1:${port}`;
  const php = spawn('php', ['-S', `127.0.0.1:${port}`, path.join(ROOT, 'tests', 'router.php')], { cwd: ROOT, env: { ...process.env, KOL_TEST_DATA: data, PHP_CLI_SERVER_WORKERS: '4' }, stdio: 'ignore' });
  let browser;
  try {
    for (let i = 0; i < 100; i++) { try { await fetch(base + '/api.php?action=embed_key'); break; } catch { await sleep(50); } }
    browser = await chromium.launch();
    const mk = (width, height, user = OWNER) => browser.newContext({ viewport: { width, height }, hasTouch: width < 600, isMobile: width < 600, extraHTTPHeaders: { 'X-Test-User': JSON.stringify(user) } });
    const call = (page, action, body, query = '') => page.evaluate(([a, b, q]) => api(a, b ?? undefined, q), [action, body ?? null, query]);
    const openPage = async (page, p) => { await page.goto(base + '/editor.php#/editor/' + encodeURIComponent(p)); await page.reload(); await page.waitForFunction(q => Editor._state.file && Editor._state.file.path === q && Editor._state.canvas && Editor._state.canvas.m && !Editor._state.busy && Editor._state.build && Editor._state.glob && Editor._state.lv, p, { timeout: 30000 }); await page.waitForFunction(() => Editor._state.lv.lists.length && Editor._state.lv.lists.every(L => L.status !== 'loading'), null, { timeout: 30000 }); await sleep(300); };
    const files = page => page.evaluate(async () => { const S = Editor._state, o = { [S.file.path]: S.doc.text() }; for (const x of S.sheets.values()) o[x.path] = x.doc.text(); return o; });
    const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);
    const word = async (page, id) => (await call(page, 'words')).words.find(w => w.id === id);
    const lastTx = page => page.evaluate(() => { const t = Editor._state.tx; return t.length ? t[t.length - 1].label : ''; });
    const select = (page, sel, i = 0) => page.evaluate(([s, i]) => { const c = Editor._state.canvas, e = c.doc.querySelectorAll(s)[i]; if (!e) return false; Editor._state.lv.focusAt(e); const n = c.nodeOf(e); c.select(n); c.reveal(n); return !!n; }, [sel, i]);
    const at = (page, sel, i = 0, fx = 0.5, fy = 0.5, scroll = true) => page.evaluate(([s, i, fx, fy, scroll]) => { const c = Editor._state.canvas, e = c.doc.querySelectorAll(s)[i]; if (!e) return null; if (scroll) e.scrollIntoView({ block: 'center' }); const r = e.getBoundingClientRect(), f = c.frame.getBoundingClientRect(); return { x: f.left + (r.left + r.width * fx) * c.scale, y: f.top + (r.top + r.height * fy) * c.scale }; }, [sel, i, fx, fy, scroll]);
    const tab = async (page, id) => { await page.evaluate(id => { if (!document.querySelector('.ed').classList.contains('info-open')) document.querySelector('.ed-bar [data-panel="info"]').click(); Editor._tab(id); }, id); await sleep(200); };
    const CARD = '[data-kol-canvas="card"]';
    const PD = () => {
      const ed = Editor._state, c = ed.canvas;
      const inFrame = (x, y) => { const f = c.frame.getBoundingClientRect(); return x >= f.left && x <= f.right && y >= f.top && y <= f.bottom; };
      const fpt = (x, y) => { const f = c.frame.getBoundingClientRect(); return [(x - f.left) / c.scale, (y - f.top) / c.scale]; };
      const fire = (t, type, x, y, cx, cy, touch) => t.dispatchEvent(new (t.ownerDocument.defaultView.PointerEvent)(type, { bubbles: true, cancelable: true, composed: true, clientX: cx, clientY: cy, pointerId: touch ? 2 : 1, pointerType: touch ? 'touch' : 'mouse', isPrimary: true, button: type === 'pointermove' ? -1 : 0, buttons: type === 'pointerup' ? 0 : 1 }));
      window.__pd = {
        down(x, y, touch, shell) { this.touch = !!touch; this.frame = inFrame(x, y) && !shell; if (this.frame) { const [fx, fy] = fpt(x, y); this.t = c.doc.elementFromPoint(fx, fy); fire(this.t, 'pointerdown', x, y, fx, fy, touch); } else { this.t = document.elementFromPoint(x, y); fire(this.t, 'pointerdown', x, y, x, y, touch); } },
        move(x, y) { if (this.frame) { const [fx, fy] = fpt(x, y); fire(c.doc.elementFromPoint(fx, fy) || c.doc.documentElement, 'pointermove', x, y, fx, fy, this.touch); } else { const t = ed.build.drag.active ? (document.elementFromPoint(x, y) || document.body) : this.t; fire(t.localName === 'iframe' ? document.body : t, 'pointermove', x, y, x, y, this.touch); } },
        up(x, y) { if (this.frame) { const [fx, fy] = fpt(x, y); fire(c.doc.elementFromPoint(fx, fy) || c.doc.documentElement, 'pointerup', x, y, fx, fy, this.touch); } else { const t = document.elementFromPoint(x, y) || document.body; fire(t.localName === 'iframe' ? document.body : t, 'pointerup', x, y, x, y, this.touch); } },
      };
    };
    const drag = async (page, from, to, opt = {}) => {
      await page.evaluate(PD);
      await page.evaluate(([x, y, t, sh]) => window.__pd.down(x, y, t, sh), [from.x, from.y, !!opt.touch, !!opt.shell]);
      if (opt.hold) await sleep(opt.hold);
      const steps = opt.steps || 10;
      for (let i = 1; i <= steps; i++) { await page.evaluate(([x, y]) => window.__pd.move(x, y), [from.x + (to.x - from.x) * i / steps, from.y + (to.y - from.y) * i / steps]); await sleep(24); }
      await sleep(80);
      await page.evaluate(([x, y]) => window.__pd.up(x, y), [to.x, to.y]); await sleep(300);
    };
    const libAt = async (page, id) => { await page.click('[data-ntab="lib"]'); await sleep(80); const b = await page.$(`.ed-lib-item[data-lib="${id}"]`); if (!b) return null; await b.scrollIntoViewIfNeeded(); const r = await b.boundingBox(); return { x: r.x + r.width / 2, y: r.y + r.height / 2 }; };
    const tpl = page => page.evaluate(() => { const t = Editor._state.doc.text(), m = /<div data-kol-list="words"[^>]*>\s*<template>([\s\S]*?)<\/template>/.exec(t); return m ? m[1] : ''; });
    const shotDir = path.join(os.tmpdir(), 'kol-v11-shots'); fs.mkdirSync(shotDir, { recursive: true });
    const shot = (page, name) => page.screenshot({ path: path.join(shotDir, name + '.png') });

    const ctx = await mk(1920, 1080), page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', e => { errors.push(e.message); console.log('   ⚠️ שגיאה בדף אחרי בדיקה ' + ran + ': ' + e.message + ' ' + String(e.stack || '').split('\n').slice(1, 4).join(' | ').slice(0, 400)); });
    let dialogs = 0; page.on('dialog', d => { if (d.type() !== 'beforeunload') dialogs++; return d.type() === 'beforeunload' ? d.accept() : d.dismiss(); });
    await page.goto(base + '/#/project');
    await page.waitForSelector('.pj-tree .pj-row');
    await call(page, 'pf_migrate', {});
    await openPage(page, 'index.html');
    await page.click('[data-dev="desktop"]'); await sleep(300);
    const o0 = await files(page), w0 = await call(page, 'words');

    // ═══ 1. הכרטיסים בקנבס ═══
    let st = await page.evaluate(CARD => { const S = Editor._state, c = S.canvas, lv = S.lv, L = lv.lists[0], cards = [...c.doc.querySelectorAll('article' + CARD)];
      let all = 0, mapped = 0; for (const a of cards) for (const e of [a, ...a.querySelectorAll('*')]) { all++; const n = c.nodeOf(e); let t = n; while (t && t.tag !== 'template') t = t.parent; if (t === L.tpl) mapped++; }
      return { n: cards.length, words: cards.map(a => a.querySelector('.kadisha-word-title').textContent), drawn: L.drawn, matched: L.matched, all, mapped, file: /data-kol-canvas/.test(S.doc.text()), title0: cards[0] && cards[0].querySelector('.kadisha-word-title').textContent }; }, CARD);
    const firstWords = w0.words.slice(0, 3).map(w => w.word);
    ok(st.n === 12 && st.drawn === 12 && st.matched === 1496 && st.words.every(Boolean) && !st.file, 'הכרטיסים מצוירים: 12 מתוך 1,496 מילים אמיתיות מ-data/words.json; הם אינם בקובץ', { ...st, words: st.words.slice(0, 3), firstWords });
    ok(st.all > 100 && st.mapped === st.all, 'כל אלמנט בכרטיס מצויר ממופה לצומת בתבנית (' + st.all + ')', st);
    st = await page.evaluate(() => { const S = Editor._state, c = S.canvas; return { edited: !!c.doc.querySelector('[data-kol-canvas="shown"]'), timing: S.lv.timing }; });
    ok(!st.edited && st.timing < 150, 'במקום "מוצג לעריכה" (V1b) — הכרטיסים עצמם; ציור ראשון ' + Math.round(st.timing) + 'ms', st);
    await shot(page, 'v11-1920-cards');

    // ═══ 2. בחירה בכרטיס ← כל הכרטיסים מסומנים; הסרגל "התבנית / המילה הזו" ═══
    let p = await at(page, '.kadisha-word-meaning', 2, 0.3, 0.5);
    await page.mouse.click(p.x, p.y); await sleep(400);
    st = await page.evaluate(() => { const S = Editor._state, c = S.canvas, n = c.selected, lv = S.lv, e = c.elOf(n), k = lv.cardOf(e); return { sel: n && c.label(n), inTpl: !!lv.inList(n), card: k && k.i, els: lv.els(n).length, twins: lv.twins.filter(b => !b.hidden).length, bar: lv.bar.hidden ? '' : lv.bar.textContent, panel: [...document.querySelectorAll('.dp-group h3')].map(x => x.textContent) }; });
    ok(st.sel === 'div.kadisha-word-meaning' && st.inTpl && st.card === 2 && st.els === 12 && st.twins >= 3, 'לחיצה על הפירוש בכרטיס 3 ← הצומת בתבנית נבחר; אותו חלק ב-12 הכרטיסים (מסגרת חלשה בנראים), הכרטיס הזה — חזקה', st);
    ok(/התבנית — חל על כל המילים/.test(st.bar) && /המילה הזו: /.test(st.bar) && st.panel.some(t => /קשירה/.test(t)), 'הסרגל מעל הבחירה: "התבנית — חל על כל המילים" · "המילה הזו: <המילה>"; בלשונית "תוכן" — "📊 נתונים · קשירה"', st);

    // ═══ 3. התבנית: עיצוב ← כל הכרטיסים; ציור מחדש < 150ms ═══
    const fs0 = await page.evaluate(() => { const c = Editor._state.canvas; return [...c.doc.querySelectorAll('.kadisha-word-meaning')].map(e => c.frame.contentWindow.getComputedStyle(e).fontSize); });
    await page.evaluate(() => { const S = Editor._state, n = S.canvas.selected, t = S.styler.targets(n)[0]; S.lv.timing = 0; S.__t = t.sel; S.design.write(new Map([[n, [['font-size', '22px', t]]]]), 'גודל · הפירוש'); });
    st = await page.evaluate(() => { const S = Editor._state, c = S.canvas; return { fs: [...c.doc.querySelectorAll('.kadisha-word-meaning')].map(e => c.frame.contentWindow.getComputedStyle(e).fontSize), t: S.lv.timing, sel: S.__t, css: [...S.sheets.values()].map(x => x.doc.text()).join('\n').includes('font-size: 22px') }; });
    ok(st.fs.length === 12 && st.fs.every(x => x === '22px') && fs0[0] !== '22px' && st.css, '"התבנית": גודל הגופן של הפירוש ← כל 12 הכרטיסים 22px; נכתב ל-CSS (' + st.sel + ')', { fs0: fs0[0], ...st });

    // ═══ 4. גרירה בתוך הכרטיס (V4): הנושאים אל מעל הכותרת — בכל הכרטיסים ═══
    const to = await at(page, '.kadisha-word-head', 0, 0.5, 0.1), from = await at(page, '.kadisha-word-topics', 0, 0.03, 0.5, false);
    await page.evaluate(() => { Editor._state.lv.timing = 0; });
    await drag(page, from, to);
    st = await page.evaluate(() => { const S = Editor._state, c = S.canvas, cards = [...c.doc.querySelectorAll('article[data-kol-canvas="card"]')]; return { order: cards.slice(0, 4).map(a => [...a.children].map(x => (x.getAttribute('class') || x.localName).split(' ')[0]).join(',')), tx: S.tx.length ? S.tx[S.tx.length - 1].label : '', tpl: (/<template>([\s\S]*?)<\/template>/.exec(S.doc.text().slice(S.doc.text().indexOf('data-kol-list="words"'))) || [])[1] || '' }; });
    const topicsFirst = s => /^kadisha-word-topics/.test(s);
    const tm = await page.evaluate(() => Editor._state.lv.timing);
    ok(tm > 0 && tm < 150, 'ציור מחדש אחרי שינוי בתבנית: ' + tm.toFixed(1) + 'ms ל-12 כרטיסים (< 150ms), מתוך 1,496 מילים', tm);
    ok(st.order.length === 4 && st.order.filter(o => /topics/.test(o)).length >= 2 && st.order.every(o => topicsFirst(o) || !/topics/.test(o)) && /^\s*<article[^>]*>\s*<div data-kol-if="topics"/.test(st.tpl), 'גרירה בתוך הכרטיס: "נושאים" אל לפני הכותרת ← ה-<template> בקובץ, וכל הכרטיסים מצוירים מחדש', st);

    // ═══ 5. "המילה הזו": עריכה במקום ← הטבלה; Ctrl+Z ← הטבלה חוזרת ═══
    await select(page, '.kadisha-word-meaning', 1);
    const target = await page.evaluate(() => { const S = Editor._state, k = S.lv.cardOf(S.canvas.elOf(S.canvas.selected)); return k.item.id; });
    const before = await word(page, target);
    await page.click('[data-lv-scope="word"]'); await sleep(200);
    st = await page.evaluate(() => Editor._state.lv.bar.textContent);
    ok(/✏️ ערוך את ה/.test(st) && /פתח את המילה/.test(st), '"המילה הזו" נבחרה: "✏️ ערוך את הפירוש" · "✏️ פתח את המילה"', st);
    p = await at(page, '.kadisha-word-meaning', 1, 0.5, 0.5);
    await page.mouse.dblclick(p.x, p.y); await sleep(300);
    st = await page.evaluate(() => { const e = Editor._state.lv.valueEdit; return e ? { tag: e.e.localName, ce: e.e.getAttribute('contenteditable') } : null; });
    ok(st && st.ce === 'true', 'לחיצה כפולה על הפירוש — עריכה במקום (contenteditable בקנבס בלבד)', st);
    await page.evaluate(() => { const e = Editor._state.lv.valueEdit.e; e.innerHTML = '<p>פירוש מתוקן <b>מהקנבס</b></p>'; });
    await page.keyboard.press('Tab').catch(() => {}); await page.evaluate(() => Editor._state.lv.valueEdit && Editor._state.lv.valueEdit.end()); await sleep(1500);
    await page.waitForFunction(() => !Editor._state.lv.busy, null, { timeout: 15000 });
    let after = await word(page, target);
    st = await page.evaluate(() => ({ tx: Editor._state.tx[Editor._state.tx.length - 1].label, card: [...Editor._state.canvas.doc.querySelectorAll('.kadisha-word-meaning')].map(e => e.textContent).find(x => /מתוקן/.test(x)) }));
    ok(/פירוש מתוקן <b>מהקנבס<\/b>/.test(after.meaning) && st.card && /המילה הזו/.test(st.tx), '"המילה הזו": הפירוש נשמר בטבלה (📚 — api words) דרך מנוע הקובץ, והכרטיס מצויר מחדש; עסקה "' + st.tx + '"', { meaning: after.meaning, st });
    ok(same(await files(page), await files(page)) && !(await page.evaluate(() => /פירוש מתוקן/.test(Editor._state.doc.text()))), '"המילה הזו" אינה נוגעת ב-index.html');
    await page.evaluate(() => Editor._undo()); await sleep(2000); await page.waitForFunction(() => !Editor._state.lv.busy, null, { timeout: 15000 });
    after = await word(page, target);
    const noLang = t => String(t).replace(/ lang="[^"]*"/g, '');
    ok(noLang(after.meaning) === noLang(before.meaning), 'Ctrl+Z של העורך מחזיר גם את המילה (פעולה הפוכה דרך אותו מנוע)', { before: before.meaning.slice(0, 60), after: after.meaning.slice(0, 60) });
    if (after.meaning !== before.meaning) console.log('   ⚠️ ידוע (לא של V11): sanitize_rich של השרת מסיר lang= מ-<span> בכל שמירה של פירוש — הביטול מחזיר את הטקסט, בלי lang (team/log/code-V11.md)');
    await page.evaluate(() => Editor._redo()); await sleep(1500); await page.waitForFunction(() => !Editor._state.lv.busy, null, { timeout: 15000 });
    ok(/מתוקן/.test((await word(page, target)).meaning), 'Ctrl+Y — שוב הערך החדש');
    await page.evaluate(() => Editor._undo()); await sleep(1500); await page.waitForFunction(() => !Editor._state.lv.busy, null, { timeout: 15000 });
    // אלמנט שאינו קשור
    await select(page, '.kadisha-word-feedback-label', 0); await sleep(200);
    st = await page.evaluate(() => Editor._state.lv.bar.textContent);
    ok(/זה חלק מהתבנית; אין לו ערך משלו במילה/.test(st), 'אלמנט שאינו קשור, ב"המילה הזו": "זה חלק מהתבנית; אין לו ערך משלו במילה"', st);
    // נושאים
    await select(page, '.kadisha-word-topic', 0); await sleep(200);
    ok(await page.$('[data-lv-each]'), 'נושא (data-kol-each) ב"המילה הזו": "🏷 נושאים…" — בורר נושאים');
    {
      const wid = await page.evaluate(() => { const S = Editor._state; return S.lv.cardOf(S.canvas.elOf(S.canvas.selected)).item.id; }), tb = await word(page, wid);
      await page.click('[data-lv-each]'); await sleep(300);
      const want = await page.evaluate(() => { const box = document.querySelector('[data-lv-values="topics"]'), off = [...box.querySelectorAll('input')].find(x => !x.checked); off.checked = true; return off.value; });
      await page.click('[data-lv-values-save]'); await sleep(1500); await page.waitForFunction(() => !Editor._state.lv.busy, null, { timeout: 15000 });
      const ta = await word(page, wid), slugs = (await call(page, 'topics')).topics.reduce((o, t) => (o[t.slug] = t.name, o), {});
      ok(ta.topics.length === tb.topics.length + 1 && ta.topics.includes(slugs[want]), 'בורר הנושאים ← נושא נוסף למילה בטבלה (' + slugs[want] + ')', { before: tb.topics, after: ta.topics, want });
      await page.evaluate(() => Editor._undo()); await sleep(1500); await page.waitForFunction(() => !Editor._state.lv.busy, null, { timeout: 15000 });
      ok(same((await word(page, wid)).topics, tb.topics), 'Ctrl+Z — הנושאים חוזרים');
    }
    await page.click('[data-lv-open]'); await sleep(400);
    st = await page.evaluate(() => { const d = document.querySelector('dialog[open]'); return d ? [...d.querySelectorAll('[data-dp-word]')].map(x => x.dataset.dpWord) : null; });
    ok(st && st.includes('word') && st.includes('meaning'), '"✏️ פתח את המילה" — חלון עם כל השדות של המילה', st);
    await page.keyboard.press('Escape'); await sleep(200);
    await page.click('[data-lv-scope="tpl"]'); await sleep(200);

    // ═══ 6. גרירת שדה מרשימת השדות אל הכרטיס ← אלמנט קשור; על אלמנט קיים — קושר ═══
    await page.waitForFunction(() => document.querySelector('[data-dp-fields] [data-lib="field-topics"]'), null, { timeout: 10000 });
    st = await page.evaluate(() => [...document.querySelectorAll('[data-dp-fields] .ed-lib-item')].map(b => b.textContent));
    ok(st.length >= 4 && st.some(x => /נושאים/.test(x)) && st.some(x => /פירוש/.test(x)), 'רשימת השדות (N2) בספרייה: ' + st.join(' · '), st);
    let src = await libAt(page, 'field-word'); p = await at(page, '.kadisha-word-meaning', 0, 0.5, 0.95);
    await drag(page, src, p);
    let t1 = await tpl(page);
    ok(/<span data-kol-text="word"><\/span>/.test(t1) && await page.evaluate(() => [...Editor._state.canvas.doc.querySelectorAll('article[data-kol-canvas="card"]')].slice(0, 3).every(a => [...a.querySelectorAll('span')].some(s => s.textContent && s.textContent === a.querySelector('.kadisha-word-title').textContent))), 'גרירת השדה "מילה" אל הכרטיס ← <span data-kol-text="word"> בתבנית; בכל כרטיס — המילה שלו', t1.slice(-400));
    src = await libAt(page, 'field-meaning'); p = await at(page, '.kadisha-word-feedback-label', 0, 0.5, 0.5);
    await drag(page, src, p);
    t1 = await tpl(page);
    ok(/<span class="kadisha-word-feedback-label" data-kol-html="meaning">/.test(t1), 'גרירת "פירוש" על אלמנט קיים ("שלח משוב") ← קושרת אותו (data-kol-html)', (/feedback-label[^>]*>/.exec(t1) || [])[0]);
    await page.evaluate(() => Editor._undo()); await sleep(300);

    // ═══ 7. פאנל "נתונים": הטקסט = השדה, טקסט מעורב, תכונה, תנאי, מחלקה, חזרה ═══
    await select(page, '.kadisha-word-feedback-label', 0); await tab(page, 'content');
    await page.selectOption('[data-dp="text"]', 'word'); await sleep(300);
    t1 = await tpl(page);
    ok(/feedback-label" data-kol-text="word"/.test(t1) && await page.evaluate(() => { const c = Editor._state.canvas, a = c.doc.querySelectorAll('article[data-kol-canvas="card"]')[1]; return a.querySelector('.kadisha-word-feedback-label').textContent === a.querySelector('.kadisha-word-title').textContent; }), '"הטקסט = השדה מילה" ← data-kol-text="word"; כל כרטיס — המילה שלו', (/feedback-label[^>]*>/.exec(t1) || [])[0]);
    await page.selectOption('[data-dp="text"]', '__mixed'); await sleep(300);
    await page.fill('[data-dp="mixed"]', 'משוב על '); await page.focus('[data-dp="mixed"]'); await page.keyboard.press('End');
    await page.selectOption('[data-dp="mixed-add"]', 'word'); await page.click('[data-dp="mixed-apply"]'); await sleep(300);
    ok(/data-kol-text="משוב על \{word\}"/.test(await tpl(page)) && await page.evaluate(() => /^משוב על \S/.test(Editor._state.canvas.doc.querySelector('article[data-kol-canvas="card"] .kadisha-word-feedback-label').textContent)), 'טקסט מעורב עם "הוסף שדה" ← "משוב על {word}"; בכרטיס — "משוב על <המילה>"');
    await select(page, '.kadisha-word-title', 0); await tab(page, 'content');
    await page.selectOption('[data-dp="attr-target"]', 'title'); await page.selectOption('[data-dp="attr-field"]', 'meaning'); await page.click('[data-dp="attr-add"]'); await sleep(300);
    ok(/<h3 class="kadisha-word-title" data-kol-text="word" data-kol-attr-title="meaning">/.test(await tpl(page)) && await page.evaluate(() => /פירוש/.test(Editor._state.canvas.doc.querySelector('article[data-kol-canvas="card"] .kadisha-word-title').getAttribute('title') || '')), '"התכונה title = השדה פירוש" ← data-kol-attr-title; בכרטיס — title מהנתונים');
    await page.selectOption('[data-dp="if-op"]', 'has'); await page.selectOption('[data-dp="if-field"]', 'audio'); await sleep(300);
    ok(/kadisha-word-title"[^>]*data-kol-if="audio"/.test(await tpl(page)), '"מוצג רק אם יש הקלטה" ← data-kol-if="audio"');
    await page.fill('[data-dp="class-name"]', 'isLong'); await page.fill('[data-dp="class-cond"]', 'topics.count=2'); await page.click('[data-dp="class-add"]'); await sleep(300);
    ok(/data-kol-class="isLong: topics\.count=2"/.test(await tpl(page)) && await page.evaluate(() => [...Editor._state.canvas.doc.querySelectorAll('article[data-kol-canvas="card"]')].some(a => a.querySelector('.kadisha-word-title')?.classList.contains('isLong'))), 'מחלקה עם אות גדולה ← הצורה הכללית data-kol-class="isLong: topics.count=2", ומצוירת בכרטיסים המתאימים');
    await page.fill('[data-dp="class-name"]', 'has-audio'); await page.fill('[data-dp="class-cond"]', 'audio'); await page.click('[data-dp="class-add"]'); await sleep(300);
    ok(/data-kol-class-has-audio="audio"/.test(await tpl(page)), 'מחלקה רגילה ← data-kol-class-has-audio="audio"');
    // הזרקה
    await page.fill('[data-dp="class-name"]', 'x"><script>'); await page.fill('[data-dp="class-cond"]', 'audio'); await page.click('[data-dp="class-add"]'); await sleep(200);
    await page.selectOption('[data-dp="attr-target"]', '__other'); await page.fill('[data-dp="attr-other"]', 'onclick'); await page.selectOption('[data-dp="attr-field"]', 'word'); await page.click('[data-dp="attr-add"]'); await sleep(200);
    await page.evaluate(() => { const S = Editor._state; S.data.setAttrs(S.canvas.selected, [['data-kol-attr-title', 'x"><img src=x onerror=alert(1)>{word}']], 'הזרקה'); }); await sleep(300);
    t1 = await tpl(page);
    ok(!/<script>/.test(t1) && !/data-kol-attr-onclick/.test(t1) && /data-kol-attr-title="x&quot;&gt;&lt;img src=x onerror=alert\(1\)&gt;\{word\}"/.test(t1) && !dialogs, 'הזרקה: שם מחלקה עם "> — נדחה; onclick — נדחה; ערך עם מרכאות ותגית — מקודד בקובץ; בקנבס שום סקריפט אינו רץ', (/kadisha-word-title"[^>]*>/.exec(t1) || [])[0]);

    // ═══ 8. המכל: מיון, כמה בכל טעינה, פריסה ═══
    await select(page, '#kadisha-lex-grid'); await tab(page, 'content');
    await page.selectOption('[data-dp="sort"]', '-word'); await sleep(500);
    st = await page.evaluate(() => [...Editor._state.canvas.doc.querySelectorAll('article[data-kol-canvas="card"] .kadisha-word-title')].slice(0, 3).map(e => e.textContent));
    const sortedDesc = w0.words.map(w => w.word).sort(new Intl.Collator('he', { numeric: true, sensitivity: 'base' }).compare).reverse().slice(0, 3);
    ok(/data-kol-sort="-word"/.test(await page.evaluate(() => Editor._state.doc.text())) && same(st, sortedDesc), 'פאנל המכל: מיון "מילה ↓" ← data-kol-sort="-word", והכרטיסים בסדר הזה', { st, sortedDesc });
    await page.fill('[data-dp="page"]', '30'); await page.press('[data-dp="page"]', 'Enter'); await sleep(300);
    await page.click('[data-dp-layout="רשימה"]'); await sleep(400);
    st = await page.evaluate(() => { const c = Editor._state.canvas, g = c.doc.querySelector('#kadisha-lex-grid'); return { page: g.getAttribute('data-kol-page'), d: c.frame.contentWindow.getComputedStyle(g).flexDirection + ' ' + c.frame.contentWindow.getComputedStyle(g).display }; });
    ok(st.page === '30' && st.d === 'column flex', 'כמה בכל טעינה = 30; "פריסת כרטיסים: רשימה" ← flex בעמודה', st);
    await page.click('[data-dp-layout="רשת"]'); await sleep(400);

    // ═══ 9. "הצג עוד", מילה לתצוגה ═══
    await page.click('[data-lv-more]'); await sleep(400);
    ok(await page.evaluate(() => Editor._state.canvas.doc.querySelectorAll('article[data-kol-canvas="card"]').length) === 24, '"＋ הצג עוד 12" בסרגל ← 24 כרטיסים בקנבס');
    await page.selectOption('[data-lv-pick="words"]', 'noaudio'); await sleep(400);
    st = await page.evaluate(() => { const a = Editor._state.canvas.doc.querySelector('article[data-kol-canvas="card"]'); return { audio: !!a.querySelector('.kadisha-word-audio-btn'), id: a.getAttribute('data-id') }; });
    ok(!st.audio && ['500', '1000'].includes(st.id), 'מילה לתצוגה "בלי הקלטה" ← הכרטיס הראשון: מילה בלי כפתור השמעה (#' + st.id + ')', st);
    await page.click('.ed-bar [data-panel="info"]').catch(() => {}); await sleep(100); await page.click('.ed-bar [data-panel="info"]').catch(() => {}); await sleep(100);
    for (const [k, test] of [['longest', a => a.meaningLen > 0], ['mosttopics', a => a.topics >= 2], ['notopics', a => a.topics === 0], ['arabic', a => /[؀-ۿ]/.test(a.word) || a.none], ['latin', a => /[A-Za-z]/.test(a.word) || a.none]]) {
      await page.selectOption('[data-lv-pick="words"]', k); await sleep(250);
      st = await page.evaluate(() => { const a = Editor._state.canvas.doc.querySelector('article[data-kol-canvas="card"]'), L = Editor._state.lv.lists[0]; return { word: a.querySelector('.kadisha-word-title')?.textContent || '', topics: a.querySelectorAll('.kadisha-word-topic').length, meaningLen: (a.querySelector('.kadisha-word-meaning')?.textContent || '').length, none: !!L.pickMiss }; });
      ok(test(st), 'מילה לתצוגה "' + k + '"', st);
    }
    await page.selectOption('[data-lv-pick="words"]', 'search'); await sleep(200); await page.fill('[data-lv-pick-arg]', '777'); await sleep(500);
    ok(/777/.test(await page.evaluate(() => Editor._state.canvas.doc.querySelector('article[data-kol-canvas="card"] .kadisha-word-title').textContent)), 'מילה לתצוגה לפי חיפוש ("777")');
    await page.selectOption('[data-lv-pick="words"]', 'empty'); await sleep(300);
    await page.selectOption('[data-lv-pick="words"]', ''); await sleep(300);

    // ═══ 10. מצבים: אין תוצאות (נבחר), מסונן, אחרי טען עוד, מתנגן ═══
    await page.selectOption('[data-lv-state="words"]', 'empty'); await sleep(400);
    st = await page.evaluate(() => { const c = Editor._state.canvas, e = c.doc.querySelector('#kadisha-lex-no-matches'); return { cards: c.doc.querySelectorAll('article[data-kol-canvas="card"]').length, disp: c.frame.contentWindow.getComputedStyle(e).display, h: e.getBoundingClientRect().height }; });
    ok(st.cards === 0 && st.disp !== 'none' && st.h > 0, 'מצב "אין תוצאות": אין כרטיסים, והודעת הריק מוצגת', st);
    p = await at(page, '#kadisha-lex-no-matches');
    await page.mouse.click(p.x, p.y); await sleep(300);
    ok(await page.evaluate(() => Editor._state.canvas.selected && Editor._state.canvas.selected.attrs.some(a => a.name === 'data-kol-empty')), 'הודעת "אין תוצאות" נבחרת בלחיצה');
    await page.mouse.dblclick(p.x, p.y); await sleep(300);
    await page.keyboard.press('Control+A'); await page.keyboard.type('לא מצאנו את המילה'); await page.keyboard.press('Escape'); await sleep(400);
    ok(/data-kol-empty="words"[^>]*>לא מצאנו את המילה</.test(await page.evaluate(() => Editor._state.doc.text())), '"אין תוצאות" — הנוסח שונה במקום (טקסט באלמנט, בקובץ)');
    await page.selectOption('[data-lv-state="words"]', 'filter'); await sleep(300);
    const topic = await page.evaluate(() => document.querySelector('[data-lv-state-value]').value);
    st = await page.evaluate(t => { const c = Editor._state.canvas, cards = [...c.doc.querySelectorAll('article[data-kol-canvas="card"]')], btn = c.doc.querySelector('[data-kol-canvas="card"][data-kol-filter-value="' + t + '"]'); return { n: cards.length, all: cards.every(a => (a.getAttribute('data-topics') || '').split(' ').includes(t)), active: btn && btn.classList.contains('is-active') && btn.getAttribute('aria-pressed') === 'true' }; }, topic);
    ok(st.n > 0 && st.all && st.active, 'מצב "מסונן לפי נושא" (' + topic + '): רק מילים בנושא, והכפתור שלו פעיל (is-active)', st);
    await page.selectOption('[data-lv-state="words"]', 'more'); await sleep(300);
    ok(await page.evaluate(() => Editor._state.lv.lists[0].drawn) === 24, 'מצב "אחרי טען עוד" (30 בכל טעינה ← עד 60; בקנבס — עד 24 עם "הצג עוד")');
    await page.selectOption('[data-lv-state="words"]', 'playing'); await sleep(300);
    st = await page.evaluate(() => { const b = Editor._state.canvas.doc.querySelector('article[data-kol-canvas="card"] [data-kol-play]'); return b ? { cls: b.className, pressed: b.getAttribute('aria-pressed') } : null; });
    ok(st && /is-playing/.test(st.cls) && st.pressed === 'true', 'מצב "מתנגן": כפתור ההשמעה בכרטיס הראשון עם is-playing — נבחר ומעוצב', st);
    await select(page, 'article[data-kol-canvas="card"] [data-kol-play]'); await tab(page, 'design'); await sleep(200);
    ok(await page.evaluate(() => Editor._state.canvas.selected && Editor._state.canvas.selected.attrs.some(a => a.name === 'data-kol-play')), 'הנגן (data-kol-play) נבחר בכרטיס');
    await page.selectOption('[data-lv-state="words"]', ''); await sleep(300);

    // ═══ 11. החלון ═══
    await page.selectOption('[data-lv-dialog="feedback"] select', 'open'); await sleep(400);
    st = await page.evaluate(() => { const c = Editor._state.canvas, d = c.doc.querySelector('[data-kol-dialog="feedback"]'), w = c.doc.querySelector('#kadisha-lex-feedback-word-name'), first = c.doc.querySelector('article[data-kol-canvas="card"] .kadisha-word-title'); return { disp: c.frame.contentWindow.getComputedStyle(d).display, word: w.textContent, first: first && first.textContent }; });
    ok(st.disp !== 'none' && st.word && st.word === st.first, 'החלון (data-kol-dialog="feedback") — "פתח בקנבס" עם המילה הראשונה', st);
    p = await page.evaluate(() => { const c = Editor._state.canvas, e = c.doc.querySelector('.kadisha-lex-feedback-form'), r = e.getBoundingClientRect(), f = c.frame.getBoundingClientRect(); return { x: f.left + (r.left + 10) * c.scale, y: f.top + (r.top + 10) * c.scale }; });
    await page.mouse.click(p.x, p.y); await sleep(300);
    ok(await page.evaluate(() => { const n = Editor._state.canvas.selected; for (let x = n; x; x = x.parent) if (x.attrs.some(a => a.name === 'data-kol-dialog')) return true; return false; }), 'חלק בחלון (הטופס) נבחר בקנבס');
    await shot(page, 'v11-1920-dialog');
    await page.selectOption('[data-lv-dialog="feedback"] select', ''); await sleep(300);

    // ═══ 12. רכיבי רשימה מהספרייה ═══
    await select(page, '#kadisha-lex-no-matches');
    await page.click('[data-ntab="lib"]'); await sleep(100); await page.click('.ed-lib-item[data-lib="kl-more"]'); await sleep(400);
    st = await page.evaluate(() => { const t = Editor._state.doc.text(); return (t.match(/data-kol-more="words">טען עוד</g) || []).length; });
    ok(st >= 1, '"טען עוד" מהספרייה ← <button data-kol-more="words">טען עוד</button> (הכיתוב — טקסט באלמנט)', st);
    await page.click('.ed-lib-item[data-lib="kl-count"]'); await sleep(400);
    await page.evaluate(() => { const c = Editor._state.canvas, all = c.doc.querySelectorAll('[data-kol-count]'), e = all[all.length - 1]; c.select(c.nodeOf(e)); });
    ok(/data-kol-count="words" data-kol-count-text="\{shown\} מתוך \{total\}"/.test(await page.evaluate(() => Editor._state.doc.text())), 'מונה מהספרייה ← data-kol-count + "{shown} מתוך {total}"');
    await tab(page, 'content');
    ok(await page.$('[data-dp="of-list"]') && await page.$('[data-dp="count-text"]'), 'בפאנל של המונה — "של הרשימה ___" והנוסח');
    await select(page, '#kadisha-lex-search'); await tab(page, 'content');
    st = await page.evaluate(() => [...document.querySelectorAll('[data-dp="search-fields"] input')].map(x => x.value + ':' + x.checked));
    ok(st.includes('word:true') && st.some(x => /^meaning/.test(x)), 'בפאנל של החיפוש — "באילו שדות"', st);

    // ═══ 13. js/kol-data.js שונה ← פס ═══
    ok(!(await page.$('[data-lv-modified]')), 'kol-data.js כמו של הכלי — בלי פס');
    await page.evaluate(() => { const S = Editor._state, k = S.js.find(x => x.path === 'js/kol-data.js'); k.doc.edit([{ from: 0, to: 0, text: '// שלי\n' }], 'שינוי'); S.lv.drawStrip(); });
    st = await page.evaluate(() => document.querySelector('[data-lv-modified]')?.textContent || '');
    ok(/js\/kol-data\.js שלך שונה/.test(st), 'kol-data.js ששונה ← פס מעל הרשימה: "' + st.slice(0, 80) + '…"', st);
    await page.evaluate(() => { const S = Editor._state, k = S.js.find(x => x.path === 'js/kol-data.js'); k.doc.undo(); S.lv.drawStrip(); });

    // ═══ 14. הקובץ נשאר HTML רגיל: מחוץ ל-<template> — רק מה שנבחר במפורש ═══
    const cur = await files(page), strip = t => t.replace(/<template>[\s\S]*?<\/template>/g, '');
    st = { cards: /data-kol-canvas|lv-editing|contenteditable/.test(cur['index.html']) };
    ok(!st.cards, 'שום כרטיס מצויר ושום סימון של העורך אינם בקובץ', st);

    // ═══ 15. Ctrl+Z עד ההתחלה ═══
    for (let i = 0; i < 80 && !same(await files(page), o0); i++) { await page.evaluate(() => Editor._undo()); await sleep(120); }
    await page.waitForFunction(() => !Editor._state.lv.busy, null, { timeout: 15000 });
    ok(same(await files(page), o0), 'Ctrl+Z עד ההתחלה: index.html ו-CSS — זהים בייט בבייט');
    const w1 = await call(page, 'words');
    ok(same(w1.words.map(w => [w.id, w.word, noLang(w.meaning), w.topics, w.audio]), w0.words.map(w => [w.id, w.word, noLang(w.meaning), w.topics, w.audio])), 'והטבלה — כמו בהתחלה (פרט ל-lang= שהשרת מסיר — ראו למעלה)');

    // ═══ 16. בדיקת "10 שינויים בתבנית" — ה-diff רק בתוך <template> ═══
    await select(page, '.kadisha-word-title', 0); await tab(page, 'content');
    const base0 = await page.evaluate(() => Editor._state.doc.text());
    for (const [op, f] of [['has', 'audio'], ['no', 'audio'], ['has', 'topics'], ['', '']]) { await page.selectOption('[data-dp="if-op"]', op); if (f) await page.selectOption('[data-dp="if-field"]', f); await sleep(250); }
    for (const t of ['title', 'aria-label', 'alt']) { await page.selectOption('[data-dp="attr-target"]', t); await page.selectOption('[data-dp="attr-field"]', 'word'); await page.click('[data-dp="attr-add"]'); await sleep(250); }
    for (const [c, k] of [['a-b', 'audio'], ['c-d', '!audio'], ['Ee', 'topics']]) { await page.fill('[data-dp="class-name"]', c); await page.fill('[data-dp="class-cond"]', k); await page.click('[data-dp="class-add"]'); await sleep(250); }
    const after10 = await page.evaluate(() => Editor._state.doc.text());
    ok(after10 !== base0 && strip(after10) === strip(base0), '10 שינויים בתבנית ← ה-diff של index.html רק בתוך <template>');
    for (let i = 0; i < 30 && (await page.evaluate(() => Editor._state.doc.text())) !== base0; i++) { await page.evaluate(() => Editor._undo()); await sleep(100); }

    // ═══ 17. N3: רשימה בקנבס ═══
    let made = null; try { made = await call(page, 'list_create', { key: 'proverbs', label: 'פתגמים', item_label: 'פתגם' }); } catch (e) { made = { error: e.message }; }
    if (made && !made.error) {
      await call(page, 'list_item_save', { list: 'proverbs', data: { title: 'מי שמחכה — מקבל' } }).catch(() => {});
      await page.evaluate(() => { Editor._state.data.listsMeta = null; Editor._state.data._lists = false; });
      await select(page, '#kadisha-lex-no-matches');
      await page.click('[data-ntab="lib"]'); await sleep(100); await page.click('.ed-lib-item[data-lib="kl-list"]'); await sleep(800);
      await page.selectOption('[data-dp="add-list"]', 'proverbs'); await page.click('[data-dp="add-list-go"]'); await sleep(1500);
      await page.waitForFunction(() => Editor._state.lv.lists.every(L => L.status !== 'loading'), null, { timeout: 15000 });
      st = await page.evaluate(() => ({ html: /data-kol-list="proverbs"/.test(Editor._state.doc.text()), css: [...Editor._state.sheets.values()].some(x => x.doc.text().includes('.kol-list-proverbs {')), L: Editor._state.lv.lists.map(L => [L.name, L.status, L.drawn, L.why || '']) }));
      ok(st.html && st.css && st.L.some(x => x[0] === 'proverbs' && x[1] === 'ok' && x[2] >= 1), 'N3: "רשימה" בספרייה ← קטע N3 §2.5 במקום שנבחר, ה-CSS שלו, והפריטים מצוירים בקנבס', st);
      for (let i = 0; i < 6 && (await page.evaluate(() => /proverbs/.test(Editor._state.doc.text()))); i++) { await page.evaluate(() => Editor._undo()); await sleep(200); }
    } else ok(false, 'N3: יצירת רשימה לבדיקה', made);

    // ═══ 18. על כל המסך: 1920×1080 ו-1366×768 ═══
    for (const [w, hgt] of [[1920, 1080], [1366, 768]]) {
      await page.setViewportSize({ width: w, height: hgt }); await sleep(400);
      await select(page, '.kadisha-word-meaning', 0); await tab(page, 'content');
      st = await page.evaluate(() => { const b = document.querySelector('.ed-body').getBoundingClientRect(), side = document.querySelector('.ed-info').getBoundingClientRect(), mid = document.querySelector('.ed-mid').getBoundingClientRect(), strip = document.querySelector('[data-lv-strips]').getBoundingClientRect(), over = []; for (const e of document.querySelectorAll('.ed-info .dp-group *, [data-lv-strips] *')) { const r = e.getBoundingClientRect(); if (r.width && r.right > innerWidth + 1) over.push(e.tagName); } return { left: Math.round(b.left), right: Math.round(innerWidth - b.right), mid: Math.round(mid.width), stripW: Math.round(strip.width), bottom: Math.round(innerHeight - b.bottom), over: over.slice(0, 4), scroll: document.documentElement.scrollWidth <= innerWidth + 1 }; });
      ok(st.left <= 16 && st.right <= 16 && st.mid > w * 0.45 && st.stripW > w * 0.9 && st.bottom <= 16 && !st.over.length && st.scroll, w + '×' + hgt + ': הקנבס, הפאנל וסרגל הרשימה — ברוחב המסך, בלי פס ריק ובלי חריגה', st);
      await shot(page, 'v11-' + w + '-panel');
      await page.click('[data-ntab="lib"]'); await sleep(200); await shot(page, 'v11-' + w + '-fields'); await page.click('[data-ntab="tree"]'); await sleep(100);
    }
    await page.setViewportSize({ width: 1920, height: 1080 }); await sleep(300);
    // לוח השדות מתקפל: הסגירה של ה-Navigator (הספרייה ולוח השדות בתוכו)
    await page.click('.ed-bar [data-panel="nav"]'); await sleep(200);
    ok(await page.evaluate(() => !document.querySelector('.ed').classList.contains('nav-open')), 'לוח השדות (בצד, עם הספרייה) מתקפל — 🌳');
    await page.click('.ed-bar [data-panel="nav"]'); await sleep(200);

    // ═══ 19. טלפון (390) — מגע ═══
    const ctxP = await mk(390, 844), pp = await ctxP.newPage();
    pp.on('pageerror', e => { errors.push(e.message); console.log('   ⚠️ (390) ' + e.message); });
    await pp.goto(base + '/#/project'); await pp.waitForSelector('.pj-tree .pj-row');
    await openPage(pp, 'index.html');
    p = await at(pp, '.kadisha-word-title', 1);
    await pp.touchscreen.tap(p.x, p.y); await sleep(500);
    st = await pp.evaluate(() => { const S = Editor._state; return { sel: S.canvas.selected && S.canvas.label(S.canvas.selected), bar: !S.lv.bar.hidden, card: S.lv.focus && S.lv.focus.i }; });
    ok(st.sel === 'h3.kadisha-word-title' && st.bar && st.card === 1, '390 (הקשה): כותרת בכרטיס 2 נבחרת, עם "התבנית / המילה הזו"', st);
    st = await pp.evaluate(() => { const small = []; for (const b of document.querySelectorAll('.lv-bar button')) { const r = b.getBoundingClientRect(); if (r.height < 36) small.push(b.textContent); } return { small, scroll: document.documentElement.scrollWidth <= innerWidth + 1 }; });
    ok(!st.small.length && st.scroll, '390: כפתורי הסרגל גדולים למגע; בלי גלילה אופקית', st);
    await pp.screenshot({ path: path.join(shotDir, 'v11-390.png') });
    await ctxP.close();

    ok(!errors.length, 'אין שגיאות JavaScript בדף', errors.slice(0, 3));
    console.log('\nצילומים: ' + shotDir);
  } catch (e) { failed++; console.log('❌ חריגה: ' + (e.stack || e.message)); }
  finally { await browser?.close(); php.kill(); }
  console.log('\n' + (failed ? '❌ נכשלו ' + failed + ' מתוך ' + ran : '✅ כל ' + ran + ' הבדיקות עברו'));
  process.exit(failed ? 1 : 0);
})();
