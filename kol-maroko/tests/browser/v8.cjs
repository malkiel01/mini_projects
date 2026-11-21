/**
 * פרוסה V8 — מחלקות משותפות, מצבים, כלל היעד המלא, "מאפיינים נוספים", תכונות: בדיקות בדפדפן   node kol-maroko/tests/browser/v8.cjs
 *
 * על index.html המוסב (העמוד האמיתי), ושש כותרות שנוספות לו בתחילת הבדיקה (כל אחת בכלל משלה, עם הצהרה זהה):
 * Shift + לחיצה בעכבר על שש הכותרות ← "צור מחלקה משותפת" (עם "העבר את המשותף") ← צבע — כולן משתנות, קובץ אחד בשורה אחת;
 * ריחוף ← צבע: הקנבס מציג בלי עכבר, וכלל :hover מיד אחרי הבסיס; ריחוף אמיתי בעכבר לא נשבר; מצב × מכשיר; מצב של הורה;
 * "מאפיינים נוספים": clip-path, cursor (mix-blend-mode — מ-V10 בשליטה ייעודית), writing-mode, scroll-snap-type — נכתבים, נראים ונמחקים; "צפה כ-CSS";
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
    const files = page => page.evaluate(async () => { const S = Editor._state, o = { [S.file.path]: S.doc.text() }; for (const x of S.sheets.values()) o[x.path] = x.doc.text(); for (const p of ['kol.json', 'js/page.js']) { const kf = Project.docs.byPath(p); if (kf) { const d = await Project.docs.open(kf.id); o[p] = d.text(); } } return o; });
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

    // ═══ 2. מצבים ═══
    const BTN = '.kadisha-lex-hero-btn';
    const stBtn = async pc => { await page.click(body('design') + ' [data-state="' + (pc || 'normal') + '"]'); await sleep(250); };
    await page.mouse.move(5, 5);
    await select(page, BTN); await sleep(150);
    const c0 = await cs(page, BTN, 'color');
    await stBtn(':hover');
    let sn = await page.evaluate(q => ({ note: document.querySelector(q + ' [data-state-note]')?.textContent || '', pressed: document.querySelector(q + ' [data-state=":hover"]')?.getAttribute('aria-pressed'), tinfo: document.querySelector(q + ' [data-tinfo]')?.textContent || '' }), body('design'));
    ok(sn.pressed === 'true' && /בלי עכבר/.test(sn.note) && /:hover/.test(sn.tinfo), 'בורר מצב: "ריחוף" — הקנבס מציג בלי עכבר, והיעד הוא כלל :hover', sn);
    await typeIn(page, 'design', 'color', '#00aa00');
    let lx = (await files(page))['css/lexicon.css'];
    ok(/\.kadisha-lex-hero-btn:hover,\s*\.kadisha-lex-hero-btn:focus-visible \{ filter: brightness\(1\.08\); outline: 2px solid #fff; outline-offset: 2px; color: #00aa00; \}/.test(lx), 'כלל קיים שמכסה את המצב (.btn:hover, .btn:focus-visible) — נערך במקומו');
    ok(await cs(page, BTN, 'color') === 'rgb(0, 170, 0)', 'הקנבס מציג את הריחוף בלי עכבר');
    let sr = await page.evaluate(q => { const r = document.querySelector(q + ' [data-field="color"] [data-st-state]'); return r ? { s: r.dataset.state, t: r.textContent } : null; }, body('design'));
    ok(sr && sr.s === 'own' && /נכתב במצב ריחוף/.test(sr.t) && /כלל משותף: \.kadisha-lex-hero-btn:hover, \.kadisha-lex-hero-btn:focus-visible/.test(sr.t), 'לצד הערך: "● נכתב במצב ריחוף · כלל משותף: …"', sr);
    sr = await page.evaluate(q => { const r = document.querySelector(q + ' [data-field="font-size"] [data-st-state]'); return r ? { s: r.dataset.state, t: r.textContent } : null; }, body('design'));
    ok(sr && sr.s === 'from' && /עובר מרגיל/.test(sr.t), 'ערך שלא נכתב במצב: "○ עובר מרגיל"', sr);
    ok(/ · מצב :hover$/.test(await lastTx(page)), 'שם העסקה כולל את המצב: "' + await lastTx(page) + '"');
    await stBtn('');
    ok(await cs(page, BTN, 'color') === c0, 'חזרה ל"רגיל": הקנבס בצבע הרגיל');
    b = await box(page, BTN); await page.mouse.move(b.x, b.y); await sleep(300);
    const realHover = await cs(page, BTN, 'color'); await page.mouse.move(5, 5); await sleep(200);
    ok(realHover === 'rgb(0, 170, 0)' && await cs(page, BTN, 'color') === c0, 'ריחוף אמיתי בעכבר בקנבס עובד (ירוק), ויציאה — חוזר', { realHover });
    // כלל מצב חדש — מיד אחרי הבסיס; איפוס במצב
    const pickTarget = async (id, re) => { const v = await page.evaluate(([q, src]) => { const s = document.querySelector(q + ' [data-target]'), o = [...s.options].find(x => new RegExp(src).test(x.textContent)); return o ? o.value : null; }, [body(id), re]); if (v != null) { await page.selectOption(body(id) + ' [data-target]', v); await sleep(250); } return v != null; };
    await select(page, H2(1)); await sleep(150); await stBtn(':hover');
    const tdef = await page.evaluate(q => document.querySelector(q + ' [data-target]').selectedOptions[0].textContent, body('design'));
    ok(/^★ \.v8-cards-title:hover/.test(tdef), 'יעד ברירת המחדל לכותרת — המחלקה המשותפת (מנצחת: אותה ייחודיות, כתובה אחרי), במצב: ' + tdef);
    ok(await pickTarget('design', '^\\.v8-t1:hover'), 'בחירת יעד: .v8-t1 (הכלל של הכותרת עצמה)');
    await typeIn(page, 'design', 'color', '#0000ff');
    let pc = (await files(page))['css/page.css'];
    ok(/\.v8-t1 \{ color: #101010; \}\n\.v8-t1:hover \{ color: #0000ff; \}\n\.v8-t2 \{/.test(pc) && await cs(page, H2(1), 'color') === 'rgb(0, 0, 255)', 'כלל :hover חדש — נכתב מיד אחרי כלל היעד (.v8-t1), והקנבס מציג', pc.slice(-420));
    await page.click(field('design', 'color') + ' [data-reset]'); await sleep(300);
    pc = (await files(page))['css/page.css'];
    ok(!/v8-t1:hover/.test(pc) && /\.v8-t1 \{ color: #101010; \}\n\.v8-t2/.test(pc), 'איפוס במצב — מוחק רק את ההצהרה של המצב (והכלל הריק)');
    // מצב × מכשיר
    await clickDev(page, 'mobile'); await select(page, H2(1)); await sleep(150); await pickTarget('design', '^\\.v8-t1:hover');
    await typeIn(page, 'design', 'color', '#ff00ff');
    pc = (await files(page))['css/page.css'];
    ok(/\.v8-t1 \{ color: #101010; \}\n@media \(max-width: 767px\) \{\n\s+\.v8-t1:hover \{ color: #ff00ff; \}\n\}/.test(pc) && await cs(page, H2(1), 'color') === 'rgb(255, 0, 255)', 'מצב × מכשיר: .v8-t1:hover בתוך @media (max-width: 767px) מיד אחרי הבסיס', pc.slice(-480));
    await clickDev(page, 'desktop'); await select(page, H2(1)); await sleep(150);
    ok(await cs(page, H2(1), 'color') !== 'rgb(255, 0, 255)', 'במחשב — הריחוף של הטלפון אינו חל');
    // מצב של הורה + מעבר
    await select(page, H2(2)); await sleep(200); await pickTarget('design', '^\\.v8-t2:hover');
    const ofv = await page.evaluate(q => { const s = document.querySelector(q + ' [data-state-of]'); const o = [...s.options].find(x => /v8-card —/.test(x.textContent)); s.value = o.value; s.dispatchEvent(new Event('change')); return o.textContent; }, body('design')); await sleep(300); await pickTarget('design', '^\\.v8-card:hover \\.v8-t2');
    await typeIn(page, 'design', 'color', '#123456');
    pc = (await files(page))['css/page.css'];
    ok(/\.v8-t2 \{ color: #202020; \}\n\.v8-card:hover \.v8-t2 \{ color: #123456; \}/.test(pc) && await cs(page, H2(2), 'color') === 'rgb(18, 52, 86)', '"מצב של: ' + ofv + '" — נכתב .v8-card:hover .v8-t2, והקנבס מציג (ההורה מסומן)');
    await typeIn(page, 'design', 'transition-duration', '0.3s');
    pc = (await files(page))['css/page.css'];
    ok(/\.v8-t2 \{ color: #202020; transition-duration: 0\.3s; \}/.test(pc), 'מעבר (transition-duration) — נכתב לכלל הרגיל, לא למצב');
    await stBtn('');
    await page.screenshot({ path: path.join(shotDir, 'v8-1920-states.png') });

    // ═══ 3. "מאפיינים נוספים" — כל מאפיין ═══
    await select(page, H2(3)); await sleep(150); await pickTarget('design', '^\\.v8-t3 ');
    const X = body('design') + ' [data-sec="extra-design"]';
    const addProp = async (name, value) => { await page.fill(X + ' [data-extra-new-name]', name); await page.fill(X + ' [data-extra-new-value]', value); await page.click(X + ' [data-extra-add]'); await sleep(300); };
    for (const [p, v, want] of [['clip-path', 'circle(40%)', 'circle(40%)'], ['cursor', 'crosshair', 'crosshair'], ['writing-mode', 'vertical-rl', 'vertical-rl'], ['scroll-snap-type', 'x mandatory', 'x mandatory']]) {
      await addProp(p, v);
      pc = (await files(page))['css/page.css'];
      const row = await page.evaluate(([q, pp]) => !!document.querySelector(q + ' [data-extra="' + pp + '"]'), [X, p]);
      ok(new RegExp('\\.v8-t3 \\{ color: #303030; ' + p + ': ' + v.replace(/[()]/g, '\\$&') + '; \\}').test(pc) && await cs(page, H2(3), p) === want && row, '"＋ מאפיין" ' + p + ': ' + v + ' — נכתב לכלל היעד, נראה בקנבס, ומופיע ברשימה');
      await page.click(X + ' [data-extra="' + p + '"] [data-extra-reset]'); await sleep(300);
      ok(!new RegExp(p).test((await files(page))['css/page.css'].split('.v8-t3 {')[1].split('}')[0]) && await cs(page, H2(3), p) !== want, '⟲ — ' + p + ' נמחק');
    }
    await addProp('--v8-x', '12px');
    ok(/\.v8-t3 \{ color: #303030; --v8-x: 12px; \}/.test((await files(page))['css/page.css']), 'מאפיין מותאם (--v8-x) — נכתב');
    await page.fill(X + ' [data-extra="--v8-x"] [data-extra-value]', '14px'); await page.press(X + ' [data-extra="--v8-x"] [data-extra-value]', 'Enter'); await sleep(300);
    ok(/--v8-x: 14px;/.test((await files(page))['css/page.css']), 'עריכה במקום — ערך');
    await page.click(X + ' [data-extra="--v8-x"] [data-extra-imp]'); await sleep(300);
    ok(/--v8-x: 14px !important;/.test((await files(page))['css/page.css']), '!important — נוסף בלחיצה');
    for (const [nm, val, why] of [['color', 'red</style><img src=x onerror=alert(1)>', '<'], ['clip-path', 'banana', 'לא חוקי'], ['bogus-prop', '1px', 'אינו מכיר'], ['a{b', '1', 'שם']]) {
      const was = await files(page); await addProp(nm, val);
      ok(same(was, await files(page)), '"＋ מאפיין" נדחה (' + why + '): ' + nm + ': ' + val);
    }
    // צפה כ-CSS
    await page.click(X + ' [data-cssview] summary'); await sleep(100);
    const tv = await page.inputValue(X + ' [data-cssview-text]');
    ok(/color: #303030;\n--v8-x: 14px !important;/.test(tv), '"צפה כ-CSS" — הצהרות כלל היעד כטקסט', tv);
    await page.fill(X + ' [data-cssview-text]', 'color: #303030;\noutline: 2px dashed red;'); await page.click(X + ' [data-cssview-apply]'); await sleep(300);
    ok(/\.v8-t3 \{ color: #303030; outline: 2px dashed red; \}/.test((await files(page))['css/page.css']) && await cs(page, H2(3), 'outline-style') === 'dashed', 'עריכה כגוש — הכלל נכתב מחדש בסגנון שלו (שורה אחת), והקנבס מציג');
    for (const bad of ['color: red; } body { display: none', 'color: </style>', 'color: banana;']) {
      const was = await files(page); await page.click(X + ' [data-cssview] summary').catch(() => {}); await page.evaluate(q => { const d = document.querySelector(q + ' [data-cssview]'); if (d) d.open = true; }, X);
      await page.fill(X + ' [data-cssview-text]', bad); await page.click(X + ' [data-cssview-apply]'); await sleep(200);
      ok(same(was, await files(page)) && await page.evaluate(q => !document.querySelector(q + ' [data-cssview-err]').hidden, X), 'גוש נדחה: ' + bad);
    }

    // ═══ 4. כלל היעד המלא: תגית, בורר בהקלדה, ✓ / נדרס ═══
    const opts = await page.evaluate(q => [...document.querySelector(q + ' [data-target]').options].map(o => ({ t: o.textContent, w: o.dataset.win || '' })), body('design'));
    ok(opts.some(o => /^h2 · css\/base\.css .*כל התגיות h2/.test(o.t)) && opts.some(o => /\.v8-cards-title/.test(o.t)) && opts.some(o => /בורר בהקלדה/.test(o.t)), 'בורר היעד: גם התגית (h2 ← styles.base), המחלקה המשותפת, ו"בורר בהקלדה"', opts.map(o => o.t));
    ok(opts.find(o => /^h2 /.test(o.t)).w === 'over' && /נדרס ע״י/.test(opts.find(o => /^h2 /.test(o.t)).t) && opts.find(o => /\.v8-cards-title ·/.test(o.t)).w === 'ok', 'ספציפיות גלויה: התגית — "⚠ נדרס ע״י …"; המחלקה המשותפת — ✓', opts.map(o => o.t));
    await page.selectOption(body('design') + ' [data-target]', 'typed'); await sleep(250);
    await page.fill(body('design') + ' [data-typed-sel]', '.v8-cards article:nth-child(odd) h2'); await sleep(100);
    let tm = await page.textContent(body('design') + ' [data-typed-msg]');
    ok(/חל על 3/.test(tm) && /אינו חל על האלמנט הנבחר/.test(tm) === false, 'בורר בהקלדה: "חל על 3: …" (הנבחר ביניהם)', tm);
    await page.fill(body('design') + ' [data-typed-sel]', '.v8-t2'); await sleep(100);
    tm = await page.textContent(body('design') + ' [data-typed-msg]');
    ok(/אינו חל על האלמנט הנבחר/.test(tm), 'בורר שאינו חל על הנבחר — אזהרה', tm);
    await page.fill(body('design') + ' [data-typed-sel]', '.v8-cards article:nth-child(odd) h2'); await page.press(body('design') + ' [data-typed-sel]', 'Enter'); await sleep(300);
    await typeIn(page, 'design', 'text-transform', 'uppercase').catch(() => {});
    await page.selectOption(field('design', 'text-transform') + ' select', 'uppercase'); await sleep(300);
    pc = (await files(page))['css/page.css'];
    ok(/\.v8-cards article:nth-child\(odd\) h2 \{ text-transform: uppercase; \}/.test(pc) && await cs(page, H2(5), 'text-transform') === 'uppercase' && await cs(page, H2(2), 'text-transform') !== 'uppercase', 'נכתב לבורר שהוקלד — כלל חדש, חל על 1/3/5');
    for (const bad of ['h2 { color: red }', '</style><script>', 'h2;x']) { await page.selectOption(body('design') + ' [data-target]', 'typed').catch(() => {}); await sleep(150); await page.fill(body('design') + ' [data-typed-sel]', bad); await page.press(body('design') + ' [data-typed-sel]', 'Enter'); await sleep(150); ok(/סוגרים|לא חוקי|הערה/.test(await page.textContent(body('design') + ' [data-typed-msg]')), 'בורר נדחה: ' + bad); }

    // ═══ 5. זהות: id, מחלקות (תגיות), שינוי שם בכל הפרויקט, תכונות ═══
    await tab(page, 'adv');
    const A = body('adv') + ' [data-sec="identity"]';
    await select(page, 'h1.kadisha-hero-title'); await sleep(200);
    ok(await page.inputValue(A + ' [data-id-input]') === 'lexicon-hero-title', 'זהות: שדה id');
    await page.fill(A + ' [data-id-input]', 'hero-main'); await page.press(A + ' [data-id-input]', 'Enter'); await sleep(300);
    let pv = await page.evaluate(() => [...document.querySelectorAll('#sheet [data-diff]')].map(e => ({ p: e.dataset.diff, t: e.textContent })));
    ok(pv.length === 1 && pv[0].p === 'index.html' && /aria-labelledby="hero-main"/.test(pv[0].t) && /id="hero-main"/.test(pv[0].t), 'שינוי id — תצוגה מקדימה: id וההפניה (aria-labelledby של ה-section)', pv);
    await page.click('#sheet [data-preview-ok]'); await sleep(300);
    let ih = (await files(page))['index.html'];
    ok(/aria-labelledby="hero-main"/.test(ih) && /<h1 id="hero-main"/.test(ih) && !/lexicon-hero-title/.test(ih), 'אחרי האישור: id וההפניות עודכנו (עסקה אחת: "' + await lastTx(page) + '")');
    await select(page, 'h1.kadisha-hero-title'); await sleep(150);
    for (const bad of ['a b', '1x', 'x"onmouseover="alert(1)']) { const was = await files(page); await page.fill(A + ' [data-id-input]', bad); await page.press(A + ' [data-id-input]', 'Enter'); await sleep(200); ok(same(was, await files(page)), 'id נדחה: ' + bad); }
    // id כפול
    await select(page, H2(4)); await sleep(150);
    await page.fill(A + ' [data-id-input]', 'hero-main'); await page.press(A + ' [data-id-input]', 'Enter'); await sleep(300);
    ok(/⚠️ id כפול בעמוד/.test(await page.textContent(A + ' [data-id-msg]')), 'id כפול בעמוד — מסומן');
    // תגיות מחלקות
    await select(page, H2(1)); await sleep(200);
    let tags = await page.evaluate(q => [...document.querySelectorAll(q + ' [data-tag]')].map(t => ({ c: t.dataset.tag, n: t.querySelector('[data-tag-count]').dataset.tagCount, u: t.classList.contains('unused') })), A);
    ok(tags.length === 2 && tags[0].c === 'v8-t1' && tags[1].c === 'v8-cards-title' && tags[1].n.startsWith('6/'), 'המחלקות כתגיות, לכל אחת: כמה אלמנטים · כמה כללים', tags);
    await page.fill(A + ' [data-tag-add]', 'v8-extra'); await page.press(A + ' [data-tag-add]', 'Enter'); await sleep(300);
    tags = await page.evaluate(q => [...document.querySelectorAll(q + ' [data-tag]')].map(t => ({ c: t.dataset.tag, u: t.classList.contains('unused') })), A);
    ok(tags.map(t => t.c).join() === 'v8-t1,v8-cards-title,v8-extra' && tags[2].u, 'הוספה (השלמה מהפרויקט); מחלקה בלי כלל ובלי JS — "לא משמשת לעיצוב"', tags);
    // גרירה לשינוי סדר — עכבר אמיתי
    const tb = async c => page.evaluate(([q, c]) => { const t = document.querySelector(q + ' [data-tag="' + c + '"]'), g = t.querySelector('.sg-tag-grip').getBoundingClientRect(), r = t.getBoundingClientRect(); return { x: g.x + g.width / 2, y: r.y + r.height / 2, r: r.right, l: r.left }; }, [A, c]);
    { const r0 = await tb('v8-t1'); await page.mouse.move(r0.x, 600); for (let k = 0; k < 8 && (await tb('v8-extra')).y > 900; k++) { await page.mouse.wheel(0, 200); await sleep(150); } }   // גלגלת על הפאנל — כמו משתמש
    let t1 = await tb('v8-extra'), t0 = await tb('v8-t1');
    await page.mouse.move(t1.x, t1.y); await page.mouse.down(); for (let i = 1; i <= 8; i++) { await page.mouse.move(t1.x + (t0.r - 4 - t1.x) * i / 8, t0.y); await sleep(20); } await page.mouse.up();
    await page.waitForFunction(() => /<h2 class="v8-extra v8-t1 v8-cards-title">/.test(Editor._state.doc.text()), null, { timeout: 3000 }).catch(() => {}); await sleep(200);
    ok(/<h2 class="v8-extra v8-t1 v8-cards-title">/.test((await files(page))['index.html']), 'גרירת תגית (עכבר) — סדר המחלקות ב-class השתנה', (await files(page))['index.html'].match(/<h2 class="[^"]*">כותרת 1/));
    await page.click(A + ' [data-tag-remove="v8-extra"]'); await page.waitForFunction(() => !/v8-extra/.test(Editor._state.doc.text()), null, { timeout: 3000 }).catch(() => {}); await sleep(200);
    ok(/<h2 class="v8-t1 v8-cards-title">/.test((await files(page))['index.html']), 'הסרת מחלקה (✕)', (await files(page))['index.html'].match(/<h2 class="[^"]*">כותרת 1/));
    for (const bad of ['a b', '.x', 'x"y', '<b>']) { const was = await files(page); await page.fill(A + ' [data-tag-add]', bad); await page.press(A + ' [data-tag-add]', 'Enter'); await sleep(150); ok(same(was, await files(page)), 'מחלקה נדחתה: ' + bad); }
    // שינוי שם בכל הפרויקט — HTML, CSS, ומחרוזות JS לבחירה
    await select(page, '.kadisha-lex-hero-btn'); await sleep(200);
    await page.click(A + ' [data-tag-rename="kadisha-lex-gate"]'); await page.waitForSelector('#sheet [data-rename-to]'); await sleep(400);
    await page.fill('#sheet [data-rename-to]', 'kadisha-lex-open'); await sleep(300);
    pv = await page.evaluate(() => ({ diffs: [...document.querySelectorAll('#sheet [data-diff]')].map(e => e.dataset.diff), js: [...document.querySelectorAll('#sheet [data-rename-js]')].map(e => e.closest('label').textContent) }));
    ok(pv.diffs.includes('index.html') && pv.js.length === 2 && pv.js.every(t => /js\/page\.js:(12|29)/.test(t)), 'שינוי שם: diff לכל קובץ, ומחרוזות ב-JS — לבחירה (2: שורות 12, 29; לא ההערה בשורה 5)', pv);
    await page.check('#sheet [data-rename-js$=":' + '"]').catch(() => {});
    await page.evaluate(() => document.querySelector('#sheet [data-rename-js]').click());
    await page.click('#sheet [data-rename-ok]'); await sleep(500);
    let fr = await files(page);
    const js12 = fr['js/page.js'].split('\n')[11], js29 = fr['js/page.js'].split('\n')[28];
    ok(!/kadisha-lex-gate/.test(fr['index.html']) && (fr['index.html'].match(/kadisha-lex-open/g) || []).length === 3 && /kadisha-lex-open/.test(js12) && /kadisha-lex-gate/.test(js29) && /kadisha-lex-gate/.test(fr['js/page.js'].split('\n')[4]), 'אחרי האישור: כל ה-class בעמוד; ב-JS — רק המחרוזת שנבחרה (שורה 12), לא 29 ולא ההערה', { js12, js29 });
    ok(/^שינוי שם \.kadisha-lex-gate ← \.kadisha-lex-open בכל הפרויקט$/.test(await lastTx(page)), 'עסקה אחת: "' + await lastTx(page) + '"');
    await page.evaluate(() => Editor._undo()); await sleep(300); fr = await files(page);
    ok(/kadisha-lex-gate/.test(fr['index.html']) && !/kadisha-lex-open/.test(fr['js/page.js']), 'ביטול אחד — כל הקבצים חוזרים');
    // תכונות
    await select(page, H2(1)); await sleep(200);
    const addAttr = async (n, v) => { await page.fill(A + ' [data-attr-new-name]', n); await page.fill(A + ' [data-attr-new-value]', v); await page.click(A + ' [data-attr-add]'); await sleep(300); };
    await addAttr('title', 'כותרת "ראשית" & <b>');
    ih = (await files(page))['index.html'];
    ok(/<h2 class="v8-t1 v8-cards-title" title="כותרת &quot;ראשית&quot; &amp; &lt;b&gt;">/.test(ih) && await page.evaluate(() => Editor._state.canvas.doc.querySelector('h2.v8-t1').title) === 'כותרת "ראשית" & <b>', 'תכונה חדשה עם " & < — כישויות; בקנבס — הטקסט המדויק');
    await page.fill(A + ' [data-attr="title"]', 'אחר'); await page.press(A + ' [data-attr="title"]', 'Enter'); await sleep(300);
    ok(/<h2 class="v8-t1 v8-cards-title" title="אחר">/.test((await files(page))['index.html']), 'שינוי תכונה');
    await addAttr('xml:lang', 'he');
    ok(/xml:lang="he"/.test((await files(page))['index.html']), 'שם עם : — נכתב');
    await page.click(A + ' [data-attr-del="title"]'); await sleep(300);
    ok(!/title="אחר"/.test((await files(page))['index.html']), 'מחיקת תכונה');
    for (const [n, v] of [['onclick', 'alert(1)'], ['onmouseover', 'x'], ['href', 'javascript:alert(1)'], ['href', ' java\tscript:x'], ['href', '&#x6A;avascript:x'], ['src', 'data:text/html,<script>x</script>'], ['srcdoc', '<script>x</script>'], ['x" onclick="a', '1']]) {
      const was = await files(page); await addAttr(n, v); const nt = await page.evaluate(() => document.querySelector('.ed-note')?.textContent || '');
      ok(same(was, await files(page)) && (n !== 'onclick' || /קוד נכתב בקובץ JS/.test(nt)), 'תכונה נדחתה: ' + n + '=' + v + (n === 'onclick' ? ' — ונאמר למה: "' + nt + '"' : ''));
    }
    await select(page, '.kadisha-lex-topic-btn.is-active'); await sleep(200);
    const kd = await page.evaluate(q => ({ n: document.querySelectorAll(q + ' [data-attr-data] [data-attr]').length, help: document.querySelector(q + ' [data-kol-help="data-kol-filter-value"]')?.textContent || '' }), A);
    ok(kd.n >= 1 && /ערך הסינון/.test(kd.help), 'תכונות data-kol-* — בקבוצה "נתונים", עם הסבר בעברית', kd);
    const over = await page.evaluate(q => { const b = document.querySelector(q); return b.scrollWidth - b.clientWidth; }, body('adv'));
    ok(over <= 1, '1920: הפאנל "מתקדם" (עם "זהות") אינו חורג לרוחב', over);
    await page.screenshot({ path: path.join(shotDir, 'v8-1920-identity.png') });

    // ═══ 6. המפל: 200 מקרים אקראיים — "מי מנצח" של הפאנל = getComputedStyle ═══
    {
      const cp = await ctx.newPage();
      await cp.goto(base + '/editor.php'); await cp.waitForFunction(() => window.KolDetails && window.KolStyle && window.KolSrc, null, { timeout: 30000 });
      const res = await cp.evaluate(() => {
        let seed = 8; const rnd = n => { seed = (seed * 1103515245 + 12345) & 0x7fffffff; return seed % n; }, pick = a => a[rnd(a.length)];
        const SELS = ['.d', 'p', '.c .d', ':is(.c, #s) .d', ':where(.a) .d', ':where(#s .d)', 'p:not(.zz)', '#s p', ':is(#nope, .d)', '.a :is(.x, .y) .d', 'article > p', ':is(p, .d):where(.d)', ':not(#q, .w) p', '.b .c p.d', 'body :where(p)', '.d.d', ':nth-child(1 of .d)', 'p:has(span)', ':is(.a .b) :is(p)', '[class~="d"]'];
        const SCOPED = ['.d', ':scope .d', 'p', ':scope p', '.c > .d', ':scope > p'];
        const host = document.createElement('div'); host.id = 'v8-cascade'; document.body.append(host);
        const bad = []; let total = 0; const why = { layer: 0, scope: 0, imp: 0, where: 0 };
        for (let k = 0; k < 200; k++) {
          host.innerHTML = '<div class="a ' + pick(['x', 'y', '']) + '"><section class="b ' + pick(['x', '']) + '" id="s"><article class="c ' + pick(['y', 'x', '']) + '"><p class="d ' + pick(['', 'w']) + '">t<span>s</span></p></article></section></div>';
          const el = host.querySelector('p'), css = [];
          if (rnd(3) === 0) css.push('@layer ' + pick(['L2, L1', 'L1, L2', 'L1.inner, L2']) + ';');
          const nr = 6 + rnd(9);
          for (let i = 0; i < nr; i++) {
            const col = 'rgb(' + (k % 200 + 20) + ', ' + (i * 13 + 10) + ', ' + (rnd(200) + 30) + ')', imp = k % 3 === 0 && rnd(6) === 0 ? ' !important' : '';
            const w = rnd(10);
            if (w < 2) css.push('@layer ' + pick(['L1', 'L2', 'L1.inner', 'L3']) + ' { ' + pick(SELS) + ' { color: ' + col + imp + '; } }');
            else if (w < 5) css.push('@scope (' + pick(['.b', '.c', '.a', '#s']) + ')' + pick(['', '', ' to (.c)', ' to (p)']) + ' { ' + pick(SCOPED) + ' { color: ' + col + imp + '; } }');
            else if (w === 5) css.push('@layer { ' + pick(SELS) + ' { color: ' + col + imp + '; } }');
            else css.push(pick(SELS) + ' { color: ' + col + imp + '; }');
          }
          const text = css.join('\n');
          for (const st of document.querySelectorAll('style[data-kol-canvas="v8.css"]')) st.remove();
          const st = document.createElement('style'); st.setAttribute('data-kol-canvas', 'v8.css'); st.textContent = text; document.head.append(st);
          const canvas = { elOf: n => n.el, frame: { contentWindow: window }, doc: document, d2m: new Map(), m: { src: '' } };
          const D = new KolDetails.Details(document.createElement('div'), { canvas, K: KolSrc, sheets: () => new Map([['v8.css', { path: 'v8.css', doc: { text: () => text } }]]), file: () => ({ path: 'index.html' }), openAt() {}, openEl() {} });
          const info = D.collect({ el, attrs: [] }), cs = KolStyle.Styler.prototype.cands.call({}, info, 'color', false), w0 = cs[0];
          const got = getComputedStyle(el).color, want = w0 ? w0.d.value.replace(/\s*!important/, '') : 'rgb(0, 0, 0)';
          const norm = v => { const x = document.createElement('i'); x.style.color = v; document.body.append(x); const c = getComputedStyle(x).color; x.remove(); return c; };
          total++; if (w0) { if (w0.r.layer < 999999999) why.layer++; if (w0.r.prox < 1e9) why.scope++; if (w0.d.important) why.imp++; if (/:where|:is|:not|:has/.test(w0.r.matched || '')) why.where++; }
          if (norm(want) !== got) bad.push({ k, text, panel: w0 ? w0.r.sel + ' ' + w0.d.value + ' ' + JSON.stringify(w0.k) : 'none', got });
        }
        host.remove(); for (const st of document.querySelectorAll('style[data-kol-canvas="v8.css"]')) st.remove();
        return { total, bad: bad.slice(0, 3), nbad: bad.length, why };
      });
      ok(res.total === 200 && res.nbad === 0 && res.why.layer >= 10 && res.why.scope >= 10 && res.why.imp >= 10 && res.why.where >= 10, 'המפל: 200 מקרים אקראיים (@layer מקונן / אנונימי / בסדר מוצהר, @scope עם קרבה ו-to, :is / :where / :not / :has / :nth-child(of), !important) — "מי מנצח" של הפאנל = getComputedStyle (' + (res.total - res.nbad) + '/200; מנצחים: ' + JSON.stringify(res.why) + ')', res);
      await cp.close();
    }

    // ═══ 7. ביטול עד ההתחלה ═══
    await tab(page, 'design'); await select(page, H2(1)); await sleep(150);
    await page.screenshot({ path: path.join(shotDir, 'v8-1920-design.png') });
    const overD = await page.evaluate(q => { const b = document.querySelector(q); return b.scrollWidth - b.clientWidth; }, body('design'));
    ok(overD <= 1, '1920: הפאנל "עיצוב" (מצבים, מעבר, מאפיינים נוספים) אינו חורג לרוחב', overD);
    ok(await undoFiles(page, o0), 'ביטול (Ctrl+Z) עד ההתחלה — כל הקבצים (index.html, כל ה-CSS, js/page.js, kol.json) זהים בייט בבייט');

    // ═══ 8. טלפון (390) — מגע ═══
    {
      // השמירה האוטומטית משותפת: ה-390 נפתח רק אחרי שהביטול של הדסקטופ נשמר
      await page.waitForFunction(() => { const S = Editor._state, ds = [S.doc, ...[...S.sheets.values()].map(x => x.doc)]; return ds.every(d => !d.dirty && !d.saving); }, null, { timeout: 20000 }); await sleep(1500);
      const lc = await mk(390, 844), lp = await lc.newPage();
      lp.on('pageerror', e => { errors.push(e.message); console.log('   ⚠️ 390: ' + e.message); });
      lp.on('dialog', d => (d.type() === 'beforeunload' ? d.accept() : d.dismiss()));
      await lp.goto(base + '/#/project'); await lp.waitForSelector('.pj-tree .pj-row');
      await openPage(lp, 'index.html');
      const l0 = await files(lp);
      await select(lp, '.kadisha-lex-hero-btn'); await sleep(200);
      await lp.evaluate(() => { document.querySelector('.ed-bar [data-panel="info"]').click(); Editor._tab('design'); }); await sleep(300);
      const tb2 = await lp.evaluate(() => [...document.querySelectorAll('.ed-side-body.sg[aria-label="עיצוב"] .sg-state-seg [data-state]')].map(b => { const r = b.getBoundingClientRect(); return [r.width, r.height]; }));
      ok(tb2.length === 8 && tb2.every(([w, hh]) => w >= 44 && hh >= 44), '390: כפתורי המצב ≥44px', tb2);
      await lp.tap('.ed-side-body.sg[aria-label="עיצוב"] [data-state=":hover"]'); await sleep(300);
      ok(await lp.evaluate(() => document.querySelector('.ed-side-body.sg[aria-label="עיצוב"] [data-state=":hover"]').getAttribute('aria-pressed')) === 'true', '390: הקשה על "ריחוף" — המצב נבחר');
      const q = '.ed-side-body.sg[aria-label="עיצוב"] [data-field="color"] .sg-ctl input';
      await lp.tap(q); await lp.fill(q, '#00aa00'); await lp.press(q, 'Enter'); await sleep(400);
      const lx3 = (await files(lp))['css/lexicon.css'], st3 = await lp.evaluate(() => document.querySelector('.ed-side-body.sg[aria-label="עיצוב"] [data-field="color"] [data-st-state]')?.textContent || '');
      ok(same(l0['css/lexicon.css'], o0['css/lexicon.css']) && /\.kadisha-lex-hero-btn:focus-visible \{[^}]*\}\n@media \(max-width: 767px\) \{\n\s+\.kadisha-lex-hero-btn:hover \{ color: #00aa00; \}\n\}/.test(lx3) && await cs(lp, '.kadisha-lex-hero-btn', 'color') === 'rgb(0, 170, 0)' && /נכתב במצב ריחוף/.test(st3),
        '390 (טלפון) + ריחוף: צבע — .btn:hover בתוך @media (max-width: 767px), אחרי כלל הריחוף של המחשב (ולכן מנצח); הקנבס מציג בלי עכבר; "● נכתב במצב ריחוף"', { st3, m: lx3.slice(lx3.indexOf('.kadisha-lex-hero-btn:hover'), lx3.indexOf('.kadisha-lex-hero-btn:hover') + 260) });
      const ow = await lp.evaluate(() => document.documentElement.scrollWidth - innerWidth);
      ok(ow <= 1, '390: אין גלילה לרוחב', ow);
      await lp.screenshot({ path: path.join(shotDir, 'v8-390-state.png') });
      await lp.evaluate(() => Editor._tab('adv')); await sleep(300);
      const tg = await lp.evaluate(() => [...document.querySelectorAll('.ed-side-body.sg[aria-label="מתקדם"] .sg-tag-btn')].map(b => { const r = b.getBoundingClientRect(); return [r.width, r.height]; }));
      ok(tg.length >= 2 && tg.every(([w, hh]) => w >= 44 && hh >= 44), '390: כפתורי התגיות (✎ ✕) ≥44px', tg);
      // גרירת תגית במגע
      await lp.evaluate(() => { const d = document.querySelector('.ed-side-body.sg[aria-label="מתקדם"] [data-sec="identity"]'); d.scrollIntoView({ block: 'start' }); }); await sleep(200);
      const g2 = await lp.evaluate(() => { const t = [...document.querySelectorAll('.ed-side-body.sg[aria-label="מתקדם"] [data-tag]')]; const a = t[1].querySelector('.sg-tag-grip').getBoundingClientRect(), b = t[0].getBoundingClientRect(); return { x: a.x + a.width / 2, y: a.y + a.height / 2, tx: b.right - 4, ty: b.y + b.height / 2 }; });
      const cdp = await lc.newCDPSession(lp);
      await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x: g2.x, y: g2.y }] });
      for (let i = 1; i <= 8; i++) { await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x: g2.x + (g2.tx - g2.x) * i / 8, y: g2.ty }] }); await sleep(20); }
      await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] }); await sleep(400);
      ok(/class="kadisha-lex-gate kadisha-lex-hero-btn"/.test((await files(lp))['index.html']), '390: גרירת תגית באצבע — סדר המחלקות השתנה');
      await lp.screenshot({ path: path.join(shotDir, 'v8-390-identity.png') });
      ok(await undoFiles(lp, l0), '390: ביטול — זהים');
      await lc.close();
    }
    ok(errors.length === 0, 'אין שגיאות JavaScript בדף', errors);
  } catch (e) { failed++; console.log('❌ הבדיקה נפלה: ' + (e.stack || e)); }
  finally { if (browser) await browser.close(); php.kill(); fs.rmSync(data, { recursive: true, force: true }); }
  console.log(`\n${ran - failed}/${ran} עברו`);
  process.exit(failed ? 1 : 0);
})();
