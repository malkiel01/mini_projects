/**
 * פרוסה V8 — מחלקות משותפות, מצבים, כלל היעד המלא, "מאפיינים נוספים", תכונות: בדיקות בדפדפן   node kol-maroko/tests/browser/v8.cjs
 *
 * על index.html המוסב (העמוד האמיתי), ושש כותרות שנוספות לו בתחילת הבדיקה (כל אחת בכלל משלה, עם הצהרה זהה):
 * Shift + לחיצה בעכבר על שש הכותרות ← "צור מחלקה משותפת" (עם "העבר את המשותף") ← צבע — כולן משתנות, קובץ אחד בשורה אחת;
 * ריחוף ← צבע: הקנבס מציג בלי עכבר, וכלל :hover מיד אחרי הבסיס; ריחוף אמיתי בעכבר לא נשבר; מצב × מכשיר; מצב של הורה;
 * "מאפיינים נוספים": clip-path, mix-blend-mode, writing-mode, scroll-snap-type — נכתבים, נראים ונמחקים; "צפה כ-CSS";
 * כלל היעד: תגית, בורר בהקלדה, ✓ / נדרס; זהות: id עם ההפניות, תגיות מחלקות, שינוי שם בכל הפרויקט (diff, JS לבחירה),
 * תכונות (on* ו-javascript: נדחים; " & < — ישויות); 200 מקרים אקראיים של המפל (@layer, @scope, :is/:where) מול
 * getComputedStyle; ביטול עד ההתחלה — קבצים זהים. ובטלפון (390) — מגע, כפתורים ≥44px. צילומים (1920, 390) — ל-tmpdir.
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
  const data = fs.mkdtempSync(path.join(os.tmpdir(), 'kol-v8-'));
  execFileSync('php', [path.join(__dirname, 'f3-seed.php'), data, path.join(data, 'old-render.html')]);
  const port = await freePort(), base = `http://127.0.0.1:${port}`;
  const php = spawn('php', ['-S', `127.0.0.1:${port}`, path.join(ROOT, 'tests', 'router.php')], { cwd: ROOT, env: { ...process.env, KOL_TEST_DATA: data, PHP_CLI_SERVER_WORKERS: '4' }, stdio: 'ignore' });
  let browser;
  try {
    for (let i = 0; i < 100; i++) { try { await fetch(base + '/api.php?action=embed_key'); break; } catch { await sleep(50); } }
    browser = await chromium.launch();
    const mk = (width, height, user = OWNER) => browser.newContext({ viewport: { width, height }, hasTouch: width < 600, extraHTTPHeaders: { 'X-Test-User': JSON.stringify(user) } });
    const call = (page, action, body, query = '') => page.evaluate(([a, b, q]) => api(a, b ?? undefined, q), [action, body ?? null, query]);
    const openPage = async (page, p) => { await page.goto(base + '/editor.php#/editor/' + encodeURIComponent(p)); await page.reload(); await page.waitForFunction(q => Editor._state.file && Editor._state.file.path === q && Editor._state.canvas && Editor._state.canvas.m && !Editor._state.busy && Editor._state.build && Editor._state.devs, p, { timeout: 30000 }); await sleep(200); };
    const files = page => page.evaluate(async () => { const S = Editor._state, o = { [S.file.path]: S.doc.text() }; for (const x of S.sheets.values()) o[x.path] = x.doc.text(); const kf = Project.docs.byPath('kol.json'); if (kf) { const d = await Project.docs.open(kf.id); o['kol.json'] = d.text(); } return o; });
    const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);
    const undoFiles = async (page, to) => { for (let i = 0; i < 80 && !same(await files(page), to); i++) { await page.evaluate(() => Editor._undo()); await sleep(40); } return same(await files(page), to); };
    const src = page => page.evaluate(() => Editor._state.doc.text());
    const css = async page => (await files(page))['css/page.css'];
    const select = (page, sel) => page.evaluate(s => { const c = Editor._state.canvas, e = c.doc.querySelector(s), n = e && c.nodeOf(e); c.select(n); c.reveal(n); return !!n; }, sel);
    const tab = async (page, id) => { await page.evaluate(id => { if (!document.querySelector('.ed').classList.contains('info-open')) document.querySelector('.ed-bar [data-panel="info"]').click(); Editor._tab(id); }, id); await sleep(150); };
    const body = id => '.ed-side-body.sg[aria-label="' + (id === 'adv' ? 'מתקדם' : 'עיצוב') + '"]';
    const field = (id, phys) => body(id) + ' [data-field="' + phys + '"]';
    const typeIn = async (page, id, phys, v) => { const q = field(id, phys) + ' .sg-ctl input'; await page.fill(q, v); await page.press(q, 'Enter'); await sleep(250); };
    const cs = (page, sel, prop) => page.evaluate(([s, p]) => { const c = Editor._state.canvas; return c.frame.contentWindow.getComputedStyle(c.doc.querySelector(s)).getPropertyValue(p); }, [sel, prop]);
    const lastTx = page => page.evaluate(() => { const t = Editor._state.tx; return t.length ? t[t.length - 1].label : ''; });
    const dev = page => page.evaluate(() => ({ id: Editor._state.device, vp: Editor._state.canvas.viewport, root: document.querySelector('.ed').dataset.devi, name: document.querySelector('[data-dev-name]')?.textContent || '', w: document.querySelector('[data-dev-width]')?.value, note: !document.querySelector('[data-dev-note]')?.hidden }));
    const clickDev = async (page, id) => { await page.click('[data-dev="' + id + '"]'); await sleep(250); };
    const devState = (page, id, phys) => page.evaluate(q => { const r = document.querySelector(q); return r ? { state: r.dataset.state, text: r.textContent, title: r.title } : null; }, field(id, phys) + ' [data-dev-state]');
    /** הבלוק @media (max-width: N) שמכיל את הבורר — או null. */
    const lineDiffT = (a, b) => { const x = a.split('\n'), y = b.split('\n'); return x.map((l, i) => l !== y[i] ? i + ': ' + l + ' → ' + y[i] : null).filter(Boolean).slice(0, 6); };
    const H2 = i => 'h2.v8-t' + i;
    const shotDir = path.join(os.tmpdir(), 'kol-v8-shots'); fs.mkdirSync(shotDir, { recursive: true });
    const ctx = await mk(1920, 1080), page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', e => { errors.push(e.message); console.log('   ⚠️ שגיאה בדף אחרי בדיקה ' + ran + ': ' + e.message + ' ' + String(e.stack || '').split('\n').slice(1, 4).join(' | ').slice(0, 400)); });
    page.on('dialog', d => (d.type() === 'beforeunload' ? d.accept() : d.dismiss()));
    await page.goto(base + '/#/project');
    await page.waitForSelector('.pj-tree .pj-row');
    await call(page, 'pf_migrate', {});
    await openPage(page, 'index.html');
    await clickDev(page, 'desktop');
    const oStart = await files(page);
    // הכנה: שש כותרות, כל אחת בכלל משלה (letter-spacing זהה, צבע שונה) — כמו "שש הכותרות" של העמוד האמיתי
    await page.evaluate(() => {
      const S = Editor._state, t = S.doc.text(), at = t.indexOf('</main>') >= 0 ? t.indexOf('</main>') : t.lastIndexOf('<script');
      S.doc.edit([{ from: at, to: at, text: '<section class="v8-cards">' + [1, 2, 3, 4, 5, 6].map(i => '<article class="v8-card"><h2 class="v8-t' + i + '">כותרת ' + i + '</h2><p>טקסט</p></article>').join('') + '</section>\n' }], 'הכנה');
      const pc = [...S.sheets.values()].find(x => x.path === 'css/page.css').doc;
      pc.edit([{ from: pc.text().length, to: pc.text().length, text: [1, 2, 3, 4, 5, 6].map(i => '.v8-t' + i + ' { letter-spacing: 1px; color: #' + i + '0' + i + '0' + i + '0; }').join('\n') + '\n' }], 'הכנה');
    });
    await sleep(600);
    const o0 = await files(page);
    ok((await page.evaluate(() => Editor._state.canvas.doc.querySelectorAll('.v8-cards h2').length)) === 6, 'הכנה: שש כותרות בקנבס');

    // ═══ 1. Shift + לחיצה בעכבר על שש הכותרות ═══
    await tab(page, 'design');
    const box = (page, sel) => page.evaluate(s => { const c = Editor._state.canvas, e = c.doc.querySelector(s); e.scrollIntoView({ block: 'center' }); const r = e.getBoundingClientRect(), f = c.frame.getBoundingClientRect(), z = c.zoom || 1; return { x: f.x + (r.x + Math.min(30, r.width / 2)) * z, y: f.y + (r.y + r.height / 2) * z }; }, sel);
    let b = await box(page, H2(1)); await page.mouse.click(b.x, b.y); await sleep(200);
    await page.keyboard.down('Shift');
    for (let i = 2; i <= 6; i++) { b = await box(page, H2(i)); await page.mouse.click(b.x, b.y); await sleep(150); }
    await page.keyboard.up('Shift'); await sleep(300);
    const picked = await page.evaluate(() => Editor._state.canvas.picked().map(n => n.attrs.find(a => a.name === 'class')?.value));
    ok(picked.length === 6, 'Shift + לחיצה (עכבר אמיתי): שש הכותרות נבחרות', picked);
    let mb = await page.evaluate(() => { const m = document.querySelector('.ed-side-body.sg[aria-label="עיצוב"] [data-multi]'); return m ? { t: m.textContent, opts: [...m.querySelectorAll('[data-multi-mode] option')].map(o => o.textContent), btn: !!m.querySelector('[data-shared-create]') } : null; });
    ok(mb && /כל אחד בכלל שלו \(6\)/.test(mb.opts[0]) && mb.btn, 'בראש "עיצוב": "משנה את: כל אחד בכלל שלו (6)" ולצידו "צור מחלקה משותפת"', mb);
    await page.click('[data-shared-create]'); await sleep(300);
    const dlg = await page.evaluate(() => ({ name: document.querySelector('[data-shared-name]')?.value, cnt: document.querySelector('[data-shared-count]')?.textContent, prev: document.querySelector('[data-shared-preview]')?.textContent, move: !document.querySelector('[data-shared-move]')?.disabled }));
    ok(dlg.name === 'v8-cards-title' && /משפיע על 6/.test(dlg.cnt) && /letter-spacing: 1px/.test(dlg.prev) && !/color/.test(dlg.prev) && dlg.move, 'הדיאלוג: שם נגזר מהמשותף (v8-cards-title — מהמכל המשותף עם מחלקה + התגית), "משפיע על 6", ובתצוגה המקדימה של "העבר את המשותף" — letter-spacing (זהה בכולם), לא הצבע (שונה)', dlg);
    await page.check('[data-shared-move]'); await page.click('[data-shared-ok]'); await sleep(600);
    let f1 = await files(page);
    const cls6 = await page.evaluate(() => [...Editor._state.canvas.doc.querySelectorAll('.v8-cards h2')].map(e => e.className));
    ok(cls6.every((c, i) => c === 'v8-t' + (i + 1) + ' v8-cards-title'), 'כל כותרת קיבלה את המחלקה בסוף ה-class, בלי לשנות את הקיימת', cls6);
    ok(/\n\.v8-cards-title \{ letter-spacing: 1px; \}\n?$/.test(f1['css/page.css']) && !/\.v8-t1 \{ letter-spacing/.test(f1['css/page.css']) && /\.v8-t1 \{ color: #101010; \}/.test(f1['css/page.css']), 'ההצהרה הזהה עברה לכלל החדש בסוף page.css, ונמחקה מששת המקורות', f1['css/page.css'].slice(-460));
    ok(await cs(page, H2(3), 'letter-spacing') === '1px' && await cs(page, H2(3), 'color') === 'rgb(48, 48, 48)', 'המראה לא השתנה (letter-spacing 1px, הצבע של כל אחת)');
    ok(/^מחלקה משותפת \.v8-cards-title · 6 אלמנטים · הועברו 1 הצהרות$/.test(await lastTx(page)), 'עסקה אחת: "' + await lastTx(page) + '"');
    mb = await page.evaluate(() => { const m = document.querySelector('[data-multi-mode]'); return m ? m.selectedOptions[0].textContent : ''; });
    ok(/\.v8-cards-title — מחלקה משותפת · משפיע על 6/.test(mb), 'מרגע שנוצרה — בורר היעד מציע אותה (ברירת המחדל)', mb);
    const before = await files(page);
    await typeIn(page, 'design', 'color', '#aa2200');
    f1 = await files(page);
    const changedFiles = Object.keys(f1).filter(k => f1[k] !== before[k]), dl = (a, b) => { const x = a.split('\n'), y = b.split('\n'); return x.filter((l, i) => l !== y[i]).length + Math.abs(x.length - y.length); };
    const cols = await page.evaluate(() => [...Editor._state.canvas.doc.querySelectorAll('.v8-cards h2')].map(e => getComputedStyle(e).color));
    ok(cols.every(c => c === 'rgb(170, 34, 0)') && changedFiles.join() === 'css/page.css' && dl(before['css/page.css'], f1['css/page.css']) === 1 && /\.v8-cards-title \{ letter-spacing: 1px; color: #aa2200; \}/.test(f1['css/page.css']), 'צבע אחד — כל שש משתנות; קובץ אחד (page.css) השתנה בשורה אחת', { changedFiles, cols, d: lineDiffT(before['css/page.css'], f1['css/page.css']) });
    await page.screenshot({ path: path.join(shotDir, 'v8-1920-shared.png') });
    ok(errors.length === 0, 'אין שגיאות JavaScript בדף', errors);
  } catch (e) { failed++; console.log('❌ הבדיקה נפלה: ' + (e.stack || e)); }
  finally { if (browser) await browser.close(); php.kill(); fs.rmSync(data, { recursive: true, force: true }); }
  console.log(`\n${ran - failed}/${ran} עברו`);
  process.exit(failed ? 1 : 0);
})();
