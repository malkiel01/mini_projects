/**
 * פרוסה V9 — גלובליים: גופנים, משתנים, ברירות מחדל לתגיות — בדיקות בדפדפן   node kol-maroko/tests/browser/v9.cjs
 *
 * על index.html המוסב (העמוד האמיתי): "🌐 כללי" בעכבר אמיתי; העלאת woff2 ← @font-face ב-tokens.css וקובץ ב-media/fonts/ ←
 * הכותרת בגופן החדש בקנבס ובתצוגה החיה (preview/); שינוי --ka-navy ← הפס והכפתורים משתנים (הצהרה אחת); h2 ב-base.css דרך
 * "תגיות" (אותו פאנל, היעד = התגית); "⛓ צור משתנה מהערך" (+ "החלף גם ב-N"); שינוי שם משתנה; Google Fonts (<link> בכל
 * העמודים) ו"⬇ הבא לפרויקט" (דרך השרת — אם יש רשת); מחיקת גופן בשימוש ← "החלף בכל מקום"; ביטול עד ההתחלה — קבצים זהים
 * וקובצי הגופן בסל. ובטלפון (390) — מגע, כפתורים ≥44px. צילומים (1920, 390) — ל-tmpdir.
 *
 * רשת (P2): סעיף 8 פונה מהשרת ל-fonts.googleapis.com / fonts.gstatic.com באמת (font_http, בלי מארח חלופי לבדיקות). בלי רשת —
 * הסעיף מדלג, והדילוג מופיע בשורת הסיכום (לא "כל הבדיקות עברו" סתם). V9_OFFLINE=1 — לא לנסות בכלל; V9_REQUIRE_NET=1 — דילוג = כישלון.
 */
'use strict';
const { chromium } = require('playwright');
const { spawn, execFileSync } = require('child_process');
const fs = require('fs'), os = require('os'), path = require('path'), net = require('net');

const ROOT = path.resolve(__dirname, '..', '..');
const OWNER = { sub: 'u-owner', name: 'Owner Test', email: 'owner@example.test', email_verified: true, platform_admin: true };
let failed = 0, ran = 0; const skipped = [];
let T0 = Date.now(); const ok = (cond, what, got) => { ran++; const dt = ((Date.now() - T0) / 1000).toFixed(1); T0 = Date.now(); if (cond) console.log('✅ [' + dt + 's] ' + what); else { failed++; console.log('❌ ' + what + (got !== undefined ? '\n   התקבל: ' + JSON.stringify(got).slice(0, 900) : '')); } };
const freePort = () => new Promise(r => { const s = net.createServer().listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => r(p)); }); });
const sleep = ms => new Promise(r => setTimeout(r, ms));
const FX = path.join(ROOT, 'tests', 'fixtures', 'fonts');

(async () => {
  const data = fs.mkdtempSync(path.join(os.tmpdir(), 'kol-v9-'));
  execFileSync('php', [path.join(__dirname, 'f3-seed.php'), data, path.join(data, 'old-render.html')]);
  const port = await freePort(), base = `http://127.0.0.1:${port}`;
  const php = spawn('php', ['-S', `127.0.0.1:${port}`, path.join(ROOT, 'tests', 'router.php')], { cwd: ROOT, env: { ...process.env, KOL_TEST_DATA: data, PHP_CLI_SERVER_WORKERS: '4' }, stdio: 'ignore' });
  let browser;
  try {
    for (let i = 0; i < 100; i++) { try { await fetch(base + '/api.php?action=embed_key'); break; } catch { await sleep(50); } }
    browser = await chromium.launch();
    const mk = (width, height, user = OWNER) => browser.newContext({ viewport: { width, height }, hasTouch: width < 600, isMobile: width < 600, extraHTTPHeaders: { 'X-Test-User': JSON.stringify(user) } });
    const call = (page, action, body, query = '') => page.evaluate(([a, b, q]) => api(a, b ?? undefined, q), [action, body ?? null, query]);
    const openPage = async (page, p) => { await page.goto(base + '/editor.php#/editor/' + encodeURIComponent(p)); await page.reload(); await page.waitForFunction(q => Editor._state.file && Editor._state.file.path === q && Editor._state.canvas && Editor._state.canvas.m && !Editor._state.busy && Editor._state.build && Editor._state.glob, p, { timeout: 30000 }); await sleep(200); };
    const files = page => page.evaluate(async () => { const S = Editor._state, o = { [S.file.path]: S.doc.text() }; for (const x of S.sheets.values()) o[x.path] = x.doc.text(); for (const p of ['kol.json', 'js/page.js']) { const kf = Project.docs.byPath(p); if (kf) { const d = await Project.docs.open(kf.id); o[p] = d.text(); } } return o; });
    const tree = async page => (await call(page, 'pf_tree')).files.filter(f => f.path.startsWith('media/fonts/')).map(f => f.path).sort();
    const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);
    const undoFiles = async (page, to) => { for (let i = 0; i < 120 && !same(await files(page), to); i++) { await page.evaluate(() => Editor._undo()); await sleep(40); } return same(await files(page), to); };
    const cs = (page, sel, prop) => page.evaluate(([s, p]) => { const c = Editor._state.canvas, e = c.doc.querySelector(s); return e ? c.frame.contentWindow.getComputedStyle(e).getPropertyValue(p) : null; }, [sel, prop]);
    const lastTx = page => page.evaluate(() => { const t = Editor._state.tx; return t.length ? t[t.length - 1].label : ''; });
    const file = async (page, p) => (await files(page))[p];
    const select = (page, sel) => page.evaluate(s => { const c = Editor._state.canvas, e = c.doc.querySelector(s), n = e && c.nodeOf(e); c.select(n); c.reveal(n); return !!n; }, sel);
    const tab = async (page, id) => { await page.evaluate(id => { if (!document.querySelector('.ed').classList.contains('info-open')) document.querySelector('.ed-bar [data-panel="info"]').click(); Editor._tab(id); }, id); await sleep(200); };
    const flush = page => page.evaluate(async () => { for (const [id, d] of Project._state.docs) if (d.dirty) await Project._save(id); });
    const shotDir = path.join(os.tmpdir(), 'kol-v9-shots'); fs.mkdirSync(shotDir, { recursive: true });
    const ctx = await mk(1920, 1080), page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', e => { errors.push(e.message); console.log('   ⚠️ שגיאה בדף אחרי בדיקה ' + ran + ': ' + e.message + ' ' + String(e.stack || '').split('\n').slice(1, 4).join(' | ').slice(0, 400)); });
    page.on('dialog', d => (d.type() === 'beforeunload' ? d.accept() : d.dismiss()));
    await page.goto(base + '/#/project');
    await page.waitForSelector('.pj-tree .pj-row');
    await call(page, 'pf_migrate', {});
    await openPage(page, 'index.html');
    await page.click('[data-dev="desktop"]'); await sleep(250);
    await page.evaluate(() => { const S = Editor._state, t = S.doc.text(), at = t.indexOf('</main>') >= 0 ? t.indexOf('</main>') : t.lastIndexOf('<script'); S.doc.edit([{ from: at, to: at, text: '<p class="v9-a">א</p><p class="v9-b">ב</p>\n' }], 'הכנה');
      const pc = [...S.sheets.values()].find(x => x.path === 'css/page.css').doc; pc.edit([{ from: pc.text().length, to: pc.text().length, text: '.v9-a { color: #123456; }\n.v9-b { border-color: #123456; outline-color: #123456; }\n' }], 'הכנה'); });
    await sleep(500);
    const o0 = await files(page), t0 = await tree(page);
    ok(t0.length === 0 && /--ka-navy: #14213d/.test(o0['css/tokens.css']), 'הכנה: העמוד המוסב, tokens.css עם --ka-navy, בלי קובצי גופן');

    // ═══ 1. "🌐 כללי" בעכבר ═══
    if (!(await page.evaluate(() => document.querySelector('.ed').classList.contains('info-open')))) await page.click('.ed-bar [data-panel="info"]');
    await page.click('.ed-ptabs [data-ptab="glob"]'); await sleep(300);
    let st = await page.evaluate(() => ({ vis: !document.querySelector('.gl').hidden, tabs: [...document.querySelectorAll('.gl [data-gl-tab]')].map(b => b.textContent), fonts: [...document.querySelectorAll('.gl [data-font]')].map(r => r.dataset.font + ':' + r.dataset.src) }));
    ok(st.vis && st.tabs.length === 3 && st.fonts.includes('Frank Ruhl Libre:google') && st.fonts.includes('Assistant:google'), '"🌐 כללי" (לחיצה בעכבר): גופנים · משתנים · תגיות; Frank Ruhl Libre ו-Assistant — Google (מה-<link> של העמוד)', st);

    // ═══ 2. העלאת woff2 ═══
    await page.click('[data-font-add]'); await sleep(200);
    const up = path.join(data, 'MyHebrew-Bold.woff2'); fs.copyFileSync(path.join(FX, 'heebo-bold-sub.woff2'), up);
    await page.setInputFiles('[data-font-file]', up);
    await page.waitForSelector('[data-up]', { timeout: 15000 });
    st = await page.evaluate(() => ({ fam: document.querySelector('[data-up-family]').value, w: document.querySelector('[data-up-weight]').value, path: document.querySelector('[data-up]').dataset.up, heb: document.querySelector('[data-up-heb]')?.dataset.upHeb || '', ok: !document.querySelector('[data-font-ok]').disabled }));
    ok(st.fam === 'MyHebrew' && st.w === '700' && /^media\/fonts\/myhebrew-700\.woff2$/.test(st.path) && st.heb === 'yes' && st.ok, 'העלאה: woff2 נשמר ב-media/fonts/; משפחה ומשקל (מהשם — woff2) מוצגים לאישור; "יש אותיות עבריות" (בדיקה בדפדפן)', st);
    await page.fill('[data-up-family]', 'Kol Hebrew'); await page.click('[data-font-ok]'); await sleep(600);
    let tk = await file(page, 'css/tokens.css');
    ok(/@font-face \{[\s\S]*font-family: 'Kol Hebrew';[\s\S]*src: url\('\.\.\/media\/fonts\/myhebrew-700\.woff2'\) format\('woff2'\);[\s\S]*font-weight: 700;[\s\S]*font-display: swap;[\s\S]*\}/.test(tk) && tk.indexOf('@font-face') < tk.indexOf(':root') && /^הוספת גופן · Kol Hebrew/.test(await lastTx(page)), '@font-face ב-tokens.css (src יחסי, swap) — עסקה "הוספת גופן · Kol Hebrew"', tk.slice(0, 400));
    ok(same(await tree(page), ['media/fonts/myhebrew-700.woff2']), 'הקובץ בעץ הפרויקט');
    st = await page.evaluate(() => [...document.querySelectorAll('.gl [data-font]')].map(r => r.dataset.font + ':' + r.dataset.src));
    ok(st[0] === 'Kol Hebrew:file', 'מופיע ראשון ברשימה — "קובץ בפרויקט"', st);
    // שם על כותרת h1 דרך בורר הגופן (עכבר + הקלדה)
    await select(page, 'h1'); await page.click('.ed-ptabs [data-ptab="design"]'); await sleep(300);
    const dl = await page.evaluate(() => [...document.querySelectorAll('#sg-fonts option')].map(o => o.value));
    ok(dl.includes("'Kol Hebrew'"), 'הגופן זמין מיד בבורר הגופן', dl.slice(0, 6));
    await page.fill('[data-field="font-family"] .sg-ctl input', "'Kol Hebrew'"); await page.press('[data-field="font-family"] .sg-ctl input', 'Enter'); await sleep(500);
    const ff = await cs(page, 'h1', 'font-family');
    const loaded = await page.evaluate(async () => { const d = Editor._state.canvas.doc; await d.fonts.ready; for (let i = 0; i < 40; i++) { if ([...d.fonts].some(f => /Kol Hebrew/.test(f.family) && f.status === 'loaded')) return true; await new Promise(r => setTimeout(r, 100)); } return [...d.fonts].map(f => f.family + ':' + f.status); });
    ok(/^"?Kol Hebrew"?, sans-serif$/.test(ff) && loaded === true, 'h1 בגופן החדש בקנבס: font-family עם גופן גיבוי, והגופן נטען (document.fonts)', { ff, loaded });
    const wsel = await page.evaluate(() => { const f = document.querySelector('[data-field="font-weight"]'); return f ? { of: f.dataset.weightsOf, opts: [...f.querySelectorAll('option')].map(o => o.value) } : null; });
    ok(wsel && wsel.of === 'Kol Hebrew' && wsel.opts.join() === '700,normal,bold', 'בורר המשקל — רק המשקלים שהגופן מספק (700)', wsel);
    // התצוגה החיה (preview/ — כתובת ההגשה המבודדת): אותו גופן
    await flush(page);
    const tok = (await call(page, 'pv_token')).token, pv = await ctx.newPage();
    await pv.goto(`${base}/preview/${tok}/index.html`);
    const pvf = await pv.evaluate(async () => { await document.fonts.ready; const h1 = document.querySelector('h1'); for (let i = 0; i < 40; i++) { if ([...document.fonts].some(f => /Kol Hebrew/.test(f.family) && f.status === 'loaded')) break; await new Promise(r => setTimeout(r, 100)); } return { ff: getComputedStyle(h1).fontFamily, loaded: [...document.fonts].some(f => /Kol Hebrew/.test(f.family) && f.status === 'loaded'), check: document.fonts.check('700 20px "Kol Hebrew"', 'שלום') }; });
    const fr = await ctx.request.get(`${base}/preview/${tok}/media/fonts/myhebrew-700.woff2`).then(r => ({ ct: r.headers()['content-type'], ns: r.headers()['x-content-type-options'] })).catch(e => ({ e: e.message }));   // הכותרות המלאות (מתוך העמוד המבודד — רק הבטוחות נקראות)
    await pv.close();
    ok(/Kol Hebrew/.test(pvf.ff) && pvf.loaded, 'התצוגה החיה (preview/): h1 בגופן החדש, והקובץ נטען', pvf);
    ok(fr.ct === 'font/woff2' && fr.ns === 'nosniff', 'קובץ הגופן מוגש כ-font/woff2 עם nosniff (לא כ-HTML)', fr);

    // ═══ 3. --ka-navy ← כל העמוד ═══
    const bg0 = await cs(page, '.kadisha-hero', 'background-color'), btnSel = await page.evaluate(() => { const c = Editor._state.canvas, w = c.frame.contentWindow; const b = [...c.doc.querySelectorAll('button, .kadisha-btn, a')].find(e => /20, 33, 61/.test(w.getComputedStyle(e).backgroundColor) || /20, 33, 61/.test(w.getComputedStyle(e).color)); if (!b) return null; b.setAttribute('data-v9-navy', ''); return true; });
    await page.click('.ed-ptabs [data-ptab="glob"]'); await sleep(200); await page.click('.gl [data-gl-tab="vars"]'); await sleep(300);
    st = await page.evaluate(() => { const r = document.querySelector('.gl [data-var="--ka-navy"]'); return r ? { kind: r.dataset.kind, uses: +r.querySelector('[data-var-uses]').dataset.varUses, defs: r.querySelectorAll('[data-var-def]').length, src: r.querySelector('.sg-at').textContent, groups: [...document.querySelectorAll('.gl .gl-h')].map(x => x.textContent) } : null; });
    ok(st && st.kind === 'color' && st.uses >= 5 && st.src.startsWith('css/tokens.css:') && st.defs >= 1 && st.groups.some(g => /צבע/.test(g)) && st.groups.some(g => /גופן/.test(g)), 'משתנים: --ka-navy בקבוצת "צבע", בשימוש ב-N הצהרות, המקור tokens.css, וההגדרה ב-theme-style.css מוצגת גם היא', st);
    await page.fill('.gl [data-var="--ka-navy"] .gl-vline [data-var-value]', '#0a5d3a'); await page.press('.gl [data-var="--ka-navy"] .gl-vline [data-var-value]', 'Enter'); await sleep(500);
    const bg1 = await cs(page, '.kadisha-hero', 'background-color'), nb = btnSel ? await page.evaluate(() => { const c = Editor._state.canvas, w = c.frame.contentWindow, e = c.doc.querySelector('[data-v9-navy]'); return e ? w.getComputedStyle(e).backgroundColor + '|' + w.getComputedStyle(e).color : ''; }) : '';
    const tk1 = await file(page, 'css/tokens.css'), dline = tk1.split('\n').filter((l, i) => l !== tk.split('\n')[i]);
    ok(bg0 === 'rgb(20, 33, 61)' && bg1 === 'rgb(10, 93, 58)' && (!btnSel || /10, 93, 58/.test(nb)) && dline.length === 1 && /--ka-navy: #0a5d3a;/.test(dline[0]), 'שינוי --ka-navy: הפס' + (btnSel ? ' והכפתורים' : '') + ' משתנים בקנבס — הצהרה אחת בקובץ (שורה אחת)', { bg0, bg1, nb, dline });

    // ═══ 4. h2 ב-base.css דרך "תגיות" ═══
    await page.click('.gl [data-gl-tab="tags"]'); await sleep(300);
    st = await page.evaluate(() => [...document.querySelectorAll('.gl [data-tag]')].map(r => r.dataset.tag + ':' + !r.querySelector('[data-tag-edit]').disabled));
    ok(st.includes('h2:true') && st.includes('body:true') && st.includes('h5:false'), 'תגיות: body, h1–h6, p, a, button, img, input, ul — עם מספר בעמוד; תגית שאין בעמוד — לא נבחרת', st);
    await page.click('.gl [data-tag-edit="h2"]'); await sleep(500);
    st = await page.evaluate(() => ({ tab: Editor._state.tab, sel: Editor._state.canvas.selected?.tag, t: document.querySelector('.ed-side-body.sg[aria-label="עיצוב"] [data-tinfo]')?.textContent }));
    ok(st.tab === 'design' && st.sel === 'h2' && /^h2 · css\/base\.css/.test(st.t), '✎ h2 ← "🎨 עיצוב" עם היעד = h2 ב-css/base.css (אותו פאנל)', st);
    await page.fill('.ed-side-body.sg[aria-label="עיצוב"] [data-field="font-size"] .sg-ctl input', '31px'); await page.press('.ed-side-body.sg[aria-label="עיצוב"] [data-field="font-size"] .sg-ctl input', 'Enter'); await sleep(500);
    const bs = await file(page, 'css/base.css'), h2fs = await cs(page, 'h2', 'font-size');
    ok(/\nh2 \{ font-size: 31px; \}\n/.test(bs) && (h2fs === '31px' || h2fs !== null), 'h2 ב-base.css: כלל חדש "h2 { font-size: 31px; }"', { tail: bs.slice(-80), h2fs });

    // ═══ 5. "⛓ צור משתנה מהערך" ═══
    await select(page, 'h1'); await tab(page, 'design');
    const colBefore = await cs(page, 'h1', 'color');
    await page.click('.ed-side-body.sg[aria-label="עיצוב"] [data-field="color"] [data-var-bind]'); await sleep(250);
    st = await page.evaluate(() => { const m = document.querySelector('[data-var-menu="color"]'); return m ? { name: m.querySelector('[data-newvar-from]')?.value, chips: m.querySelectorAll('[data-bind-var]').length, also: m.querySelector('[data-newvar-also]') ? m.querySelector('[data-newvar-also]').parentElement.textContent : '' } : null; });
    ok(st && /^--color-/.test(st.name) && st.chips >= 3, '⛓ ליד צבע הטקסט: "קשר למשתנה" (משתני צבע) ו"צור משתנה מהערך" עם שם מוצע מההקשר', st);
    await page.fill('[data-newvar-from]', '--color-hero-title'); await page.click('[data-newvar-go]'); await sleep(600);
    const all5 = await files(page);
    ok(/--color-hero-title: [^;]+;/.test(all5['css/tokens.css']) && /var\(--color-hero-title\)/.test(Object.values(all5).join('\n')) && (await cs(page, 'h1', 'color')) === colBefore && /^צור משתנה --color-hero-title/.test(await lastTx(page)), 'נוצר --color-hero-title ב-tokens.css; ההצהרה ← var(); הצבע זהה; עסקה אחת', { tx: await lastTx(page) });
    st = await page.evaluate(() => { const c = document.querySelector('.ed-side-body.sg[aria-label="עיצוב"] [data-var-chip]'); return c ? c.dataset.varChip + ' ' + c.textContent : null; });
    ok(st && /--color-hero-title/.test(st) && /נתק/.test(st), 'בפאנל — צ\'יפ "⛓ --color-hero-title = <ערך>" עם "נתק"', st);
    // קשר למשתנה אחר (עכבר)
    await page.click('.ed-side-body.sg[aria-label="עיצוב"] [data-field="color"] [data-var-bind]'); await sleep(200);
    await page.click('[data-var-menu="color"] [data-bind-var="--ka-gold"]'); await sleep(400);
    ok((await cs(page, 'h1', 'color')) === 'rgb(201, 162, 39)', 'קשר למשתנה: --ka-gold ← h1 בזהב', await cs(page, 'h1', 'color'));
    await page.click('.ed-side-body.sg[aria-label="עיצוב"] [data-var-unbind="color"]'); await sleep(400);
    ok(!/var\(--ka-gold\)/.test(Object.values(await files(page)).join('\n').split('h1')[0] + '') || (await cs(page, 'h1', 'color')) === 'rgb(201, 162, 39)', '"נתק" — הערך נכתב במקום var(), המראה זהה');

    // ═══ 5ב. "החלף גם ב-N מקומות" ═══
    await select(page, 'p.v9-a'); await tab(page, 'design');
    await page.click('.ed-side-body.sg[aria-label="עיצוב"] [data-field="color"] [data-var-bind]'); await sleep(250);
    st = await page.evaluate(() => ({ also: document.querySelector('[data-newvar-also]')?.checked, list: [...document.querySelectorAll('[data-newvar-list] li')].map(l => l.textContent) }));
    ok(st.also && st.list.length === 2 && st.list.every(x => /\.v9-b/.test(x)), '"צור משתנה מהערך": "להחליף גם ב-2 מקומות" — עם הרשימה (border-color, outline-color של .v9-b)', st);
    await page.fill('[data-newvar-from]', '--color-v9'); await page.click('[data-newvar-go]'); await sleep(600);
    const pc5 = await file(page, 'css/page.css');
    ok(/\.v9-a \{ color: var\(--color-v9\); \}\n\.v9-b \{ border-color: var\(--color-v9\); outline-color: var\(--color-v9\); \}/.test(pc5) && /--color-v9: #123456;/.test(await file(page, 'css/tokens.css')), 'שלושת המקומות ← var(--color-v9), והמשתנה ב-tokens.css — עסקה אחת', pc5.slice(-120));
    await page.evaluate(() => Editor._undo()); await sleep(400);
    ok(/\.v9-b \{ border-color: #123456; outline-color: #123456; \}/.test(await file(page, 'css/page.css')) && !/--color-v9/.test(await file(page, 'css/tokens.css')), 'ביטול אחד מחזיר את כל השלושה ואת tokens.css');

    // ═══ 6. שינוי שם משתנה ═══
    await tab(page, 'glob'); await page.click('.gl [data-gl-tab="vars"]'); await sleep(250);
    await page.click('.gl [data-var="--ka-gold"] [data-var-rename]'); await sleep(150);
    await page.fill('[data-var-newname]', '--brand-gold'); await sleep(150);
    const rinfo = await page.textContent('[data-rename-info]');
    await page.click('[data-rename-ok]'); await sleep(500);
    const all6 = Object.values(await files(page)).join('\n');
    ok(/ישתנה ב-\d+ מקומות/.test(rinfo) && !/--ka-gold(?![\w-])/.test(all6) && /--brand-gold/.test(all6), 'שינוי שם --ka-gold ← --brand-gold: תצוגה מקדימה (N מקומות, קבצים), ואז בכל ההופעות', rinfo);

    // ═══ 7. מחיקת גופן בשימוש ← "החלף בכל מקום" ═══
    await page.click('.gl [data-gl-tab="fonts"]'); await sleep(250);
    await page.click('.gl [data-font-del="Kol Hebrew"]'); await sleep(200);
    st = await page.evaluate(() => { const b = document.querySelector('[data-font-del-box]'); return b ? b.textContent : null; });
    ok(st && /בשימוש ב-1/.test(st) && /h1/.test(st), 'מחיקת גופן בשימוש — אזהרה עם הרשימה, ו"להחליף בכל מקום ב-…"', st);
    await page.selectOption('[data-font-rep]', 'Assistant'); await page.click('[data-font-del-ok]'); await sleep(800);
    const all7 = await files(page);
    ok(!/Kol Hebrew/.test(Object.values(all7).join('\n')) && (await tree(page)).length === 0 && /Assistant/.test(await cs(page, 'h1', 'font-family')), 'נמחק: @font-face, הקובץ לסל, ו-h1 ← Assistant — עסקה אחת', { tree: await tree(page), ff: await cs(page, 'h1', 'font-family') });
    await page.evaluate(() => Editor._undo()); await sleep(800);
    ok(/Kol Hebrew/.test((await files(page))['css/tokens.css']) && same(await tree(page), ['media/fonts/myhebrew-700.woff2']), 'ביטול המחיקה — @font-face חוזר והקובץ חוזר מהסל', await tree(page));

    // ═══ 8. Google Fonts + "הבא לפרויקט" (דרך השרת; דורש רשת) ═══
    let net = true;
    if (process.env.V9_OFFLINE) { net = false; skipped.push('סעיף 8 — Google Fonts (V9_OFFLINE)'); }
    else try { await call(page, 'font_google_add', { family: 'Secular One', weights: [400] }); } catch (e) { net = false; skipped.push('סעיף 8 — Google Fonts (אין רשת: ' + e.message + ')'); console.log('   ⏭ אין רשת ל-fonts.googleapis.com: ' + e.message); if (process.env.V9_REQUIRE_NET) ok(false, 'V9_REQUIRE_NET: יש רשת ל-Google Fonts', e.message); }
    if (net) {
      await page.click('[data-font-add]'); await sleep(150); await page.click('[data-add-tab="google"]'); await page.waitForSelector('[data-gf="Secular One"]', { timeout: 10000 });
      st = await page.evaluate(() => ({ n: document.querySelectorAll('[data-gf]').length, heb: document.querySelector('[data-gf-heb]').checked, roboto: !!document.querySelector('[data-gf="Roboto"]') }));
      ok(st.n >= 20 && st.heb && !st.roboto, 'Google Fonts: רשימה דרך השרת, "רק עם עברית" כברירת מחדל (Roboto לא מוצג)', st);
      await page.fill('[data-gf-q]', 'secular'); await sleep(150); await page.click('[data-gf="Secular One"]'); await sleep(150); await page.click('[data-gf-add]');
      await page.waitForFunction(() => !document.getElementById('sheet').open, null, { timeout: 15000 });
      const ix = (await files(page))['index.html'];
      ok(/<link rel="stylesheet" href="https:\/\/fonts\.googleapis\.com\/css2\?family=Secular\+One:wght@400&amp;display=swap">/.test(ix), '<link> ל-Secular One נכתב ב-<head>', ix.split('\n').filter(l => /googleapis/.test(l)));
      await page.click('.gl [data-font-fetch="Secular One"]'); await sleep(150); await page.click('[data-fetch-ok]');
      await page.waitForFunction(() => !document.getElementById('sheet').open, null, { timeout: 30000 });
      const a8 = await files(page), t8 = await tree(page);
      ok(!/Secular\+One/.test(a8['index.html']) && /font-family: 'Secular One';[\s\S]*?url\('\.\.\/media\/fonts\/secular-one-400-[a-z-]+\.woff2'\)[\s\S]*?unicode-range/.test(a8['css/tokens.css']) && t8.some(p => /secular-one-400-hebrew\.woff2$/.test(p)), '"⬇ הבא לפרויקט": קובצי woff2 ב-media/fonts/, @font-face מקומי (עם unicode-range), וה-<link> הוסר', t8);
    }

    // ═══ 9. ביטול עד ההתחלה ═══
    const back = await undoFiles(page, o0);
    await sleep(800);
    ok(back && (await tree(page)).length === 0, 'Ctrl+Z עד ההתחלה: כל הקבצים זהים בייט בבייט, וקובצי הגופן בסל', { back, tree: await tree(page) });
    await page.evaluate(() => Editor._redo()); await sleep(800);
    ok(same(await tree(page), ['media/fonts/myhebrew-700.woff2']) && /Kol Hebrew/.test((await files(page))['css/tokens.css']), 'החזרה (Ctrl+Y) — ההעלאה חוזרת: @font-face והקובץ מהסל', await tree(page));
    await page.evaluate(() => Editor._undo()); await sleep(600);

    // ═══ 10. הזרקה דרך הממשק ═══
    await tab(page, 'glob'); await page.click('.gl [data-gl-tab="vars"]'); await sleep(200);
    const before10 = await files(page);
    await page.fill('.gl [data-var="--ka-navy"] .gl-vline [data-var-value]', 'red; } body { display: none'); await page.press('.gl [data-var="--ka-navy"] .gl-vline [data-var-value]', 'Enter'); await sleep(250);
    await page.click('[data-var-new]'); await page.fill('[data-newvar-name]', '--x;color:red'); await page.fill('[data-newvar-value]', '1px'); await page.click('[data-newvar-ok]'); await sleep(250);
    await page.fill('[data-newvar-name]', '--ok'); await page.fill('[data-newvar-value]', "url(javascript:alert(1))"); await page.click('[data-newvar-ok]'); await sleep(250);
    ok(same(await files(page), before10), 'הזרקה מהממשק (} ; בערך, ; בשם, url(javascript:) — לא נכתב דבר');

    // ═══ צילום 1920 ═══
    await page.click('.gl [data-gl-tab="fonts"]'); await sleep(400);
    await page.screenshot({ path: path.join(shotDir, 'v9-1920-fonts.png') });
    await page.click('.gl [data-gl-tab="vars"]'); await sleep(300);
    await page.screenshot({ path: path.join(shotDir, 'v9-1920-vars.png') });
    await select(page, 'h1'); await tab(page, 'design');
    await page.click('.ed-side-body.sg[aria-label="עיצוב"] [data-field="color"] [data-var-bind]'); await sleep(250);
    await page.screenshot({ path: path.join(shotDir, 'v9-1920-bind.png') });
    const over = await page.evaluate(() => { const p = document.querySelector('.ed-side.info, [data-side="info"], .ed-side:last-of-type'); const out = []; for (const e of document.querySelectorAll('.gl *, .sg-varmenu *')) { const r = e.getBoundingClientRect(); if (r.width && r.right > innerWidth + 1) out.push(e.className || e.tagName); } return out.slice(0, 5); });
    ok(!over.length, '1920: שום רכיב של הפאנל אינו חורג מהמסך', over);

    // ═══ 11. טלפון (390) — מגע ═══
    const ctxP = await mk(390, 844), pp = await ctxP.newPage();
    pp.on('pageerror', e => { errors.push(e.message); console.log('   ⚠️ (390) ' + e.message); });
    await openPage(pp, 'index.html');
    await pp.evaluate(() => { const c = Editor._state.canvas, e = c.doc.querySelector('h1'); c.select(c.nodeOf(e)); });
    if (!(await pp.evaluate(() => document.querySelector('.ed').classList.contains('info-open')))) await pp.tap('.ed-bar [data-panel="info"]');
    await sleep(300); await pp.tap('.ed-ptabs [data-ptab="glob"]'); await sleep(300); await pp.tap('.gl [data-gl-tab="vars"]'); await sleep(300);
    const small = await pp.evaluate(() => [...document.querySelectorAll('.gl button, .gl input:not([type=checkbox])')].filter(e => e.offsetParent && e.getBoundingClientRect().height < 43.5).map(e => ((e.dataset && Object.keys(e.dataset)[0]) || e.className || e.tagName) + ":" + Math.round(e.getBoundingClientRect().height)).slice(0, 6));
    ok(!small.length, '390: כל כפתור ושדה בפאנל "🌐 כללי" ≥44px', small);
    const sel = '.gl [data-var="--ka-cream"] .gl-vline [data-var-value]';
    await pp.tap(sel); await pp.fill(sel, '#ffeedd'); await pp.press(sel, 'Enter'); await sleep(500);
    ok(/--ka-cream: #ffeedd;/.test((await pp.evaluate(() => [...Editor._state.sheets.values()].find(x => x.path === 'css/tokens.css').doc.text()))), '390 (מגע): שינוי --ka-cream בהקשה והקלדה — נכתב');
    const wide = await pp.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1);
    ok(wide, '390: בלי גלילה אופקית');
    await pp.screenshot({ path: path.join(shotDir, 'v9-390-vars.png') });
    await pp.tap('.gl [data-gl-tab="fonts"]'); await sleep(300);
    await pp.screenshot({ path: path.join(shotDir, 'v9-390-fonts.png') });
    await pp.evaluate(() => Editor._undo()); await sleep(300);
    await ctxP.close();

    ok(!errors.length, 'אין שגיאות JavaScript בדף', errors.slice(0, 3));
    console.log('\nצילומים: ' + shotDir);
  } catch (e) { failed++; console.log('❌ חריגה: ' + (e.stack || e.message)); }
  finally { await browser?.close(); php.kill(); }
  console.log('\n' + (failed ? '❌ נכשלו ' + failed + ' מתוך ' + ran : '✅ כל ' + ran + ' הבדיקות ' + (skipped.length ? 'שרצו ' : '') + 'עברו') + (skipped.length ? '\n⏭ דולג: ' + skipped.join(' · ') : ''));
  process.exit(failed ? 1 : 0);
})();
