/** V10 */
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
  const data = fs.mkdtempSync(path.join(os.tmpdir(), 'kol-v10-'));
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
    const files = page => page.evaluate(async () => { const S = Editor._state, o = { [S.file.path]: S.doc.text() }; for (const x of S.sheets.values()) o[x.path] = x.doc.text(); for (const p of ['kol.json', 'js/page.js', 'css/motion.css', 'js/kol-motion.js']) { const kf = Project.docs.byPath(p); if (kf) { const d = await Project.docs.open(kf.id); o[p] = d.text(); } } if (!Project.docs.byPath('css/motion.css')) delete o['css/motion.css']; return o; });
    const tree = async page => (await call(page, 'pf_tree')).files.map(f => f.path).sort();
    const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);
    const undoFiles = async (page, to) => { for (let i = 0; i < 120 && !same(await files(page), to); i++) { await page.evaluate(() => Editor._undo()); await sleep(40); } return same(await files(page), to); };
    const cs = (page, sel, prop) => page.evaluate(([s, p]) => { const c = Editor._state.canvas, e = c.doc.querySelector(s); return e ? c.frame.contentWindow.getComputedStyle(e).getPropertyValue(p) : null; }, [sel, prop]);
    const lastTx = page => page.evaluate(() => { const t = Editor._state.tx; return t.length ? t[t.length - 1].label : ''; });
    const file = async (page, p) => (await files(page))[p];
    const select = (page, sel) => page.evaluate(s => { const c = Editor._state.canvas, e = c.doc.querySelector(s), n = e && c.nodeOf(e); c.select(n); c.reveal(n); return !!n; }, sel);
    const tab = async (page, id) => { await page.evaluate(id => { if (!document.querySelector('.ed').classList.contains('info-open')) document.querySelector('.ed-bar [data-panel="info"]').click(); Editor._tab(id); }, id); await sleep(200); };
    const flush = page => page.evaluate(async () => { for (const [id, d] of Project._state.docs) if (d.dirty) await Project._save(id); });
    const shotDir = path.join(os.tmpdir(), 'kol-v10-shots'); fs.mkdirSync(shotDir, { recursive: true });
    const ctx = await mk(1920, 1080), page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', e => { errors.push(e.message); console.log('   ⚠️ שגיאה בדף אחרי בדיקה ' + ran + ': ' + e.message + ' ' + String(e.stack || '').split('\n').slice(1, 4).join(' | ').slice(0, 400)); });
    page.on('dialog', d => (d.type() === 'beforeunload' ? d.accept() : d.dismiss()));
    await page.goto(base + '/#/project');
    await page.waitForSelector('.pj-tree .pj-row');
    await call(page, 'pf_migrate', {});
    await openPage(page, 'index.html');
    await page.click('[data-dev="desktop"]'); await sleep(250);
    await page.evaluate(() => { const S = Editor._state, t = S.doc.text(), at = t.indexOf('</main>') >= 0 ? t.indexOf('</main>') : t.lastIndexOf('<script'); S.doc.edit([{ from: at, to: at, text: '<div class="v10-raw">x</div><section class="v10-box"><h2 class="v10-t">כותרת</h2><p>טקסט</p></section>\n' }], 'הכנה');
      const pc = [...S.sheets.values()].find(x => x.path === 'css/page.css').doc; pc.edit([{ from: pc.text().length, to: pc.text().length, text: '.v10-box { padding: 40px; background: #f4efe6; }\n.v10-t { color: #14213d; }\n.v10-raw { box-shadow: 0 0 var(--blur) red; }\n' }], 'הכנה'); });
    await sleep(500);
    const o0 = await files(page);
    ok(/v10-box/.test(o0['index.html']), 'הכנה: העמוד המוסב + רובריקה לבדיקה (v10-box)');

    // ═══ עזרים ═══
    const D_ = '.ed-side-body.sg[aria-label="עיצוב"] ', A_ = '.ed-side-body.sg[aria-label="מתקדם"] ';
    const openGrp = async (page, root, id) => { await page.evaluate(([r, id]) => { const d = document.querySelector(r + '[data-sec="' + id + '"]'); if (d && !d.open) d.querySelector('summary').click(); }, [root, id]); await sleep(250); };
    const setNum = async (page, sel, v) => { await page.fill(sel, v); await page.press(sel, 'Enter'); await sleep(450); };
    const center = (page, sel) => page.evaluate(s => { const e = document.querySelector(s); if (!e || e.hidden) return null; const r = e.getBoundingClientRect(); return { x: r.left + r.width / 2, y: r.top + r.height / 2 }; }, sel);
    const dragMouse = async (page, p, dx, dy, steps = 8, mod) => { await page.mouse.move(p.x, p.y); if (mod) await page.keyboard.down(mod); await page.mouse.down(); for (let i = 1; i <= steps; i++) { await page.mouse.move(p.x + dx * i / steps, p.y + dy * i / steps); await sleep(15); } await page.mouse.up(); if (mod) await page.keyboard.up(mod); await sleep(350); };
    const pageCss = page => file(page, 'css/page.css');
    const shot = (page, name) => page.screenshot({ path: path.join(shotDir, name + '.png') });

    // ═══ 1. מסגרת ופינות (עיצוב) ═══
    await select(page, '.v10-box'); await tab(page, 'design'); await openGrp(page, D_, 'border');
    let st = await page.evaluate(r => ({ grp: [...document.querySelectorAll(r + 'details.sg-fx')].map(d => d.dataset.sec), lock: document.querySelector(r + '[data-border-lock="all"]')?.getAttribute('aria-pressed') }), D_);
    ok(st.grp.join() === 'border,shadow,effects,mask,shapes' && st.lock === 'true', '"עיצוב": קבוצות מסגרת ופינות · צל · אפקטים · מסיכה · מפריד צורה (מתקפלות); "כל הצדדים" כברירת מחדל', st);
    await page.selectOption(D_ + '[data-border-all="style"]', 'dashed'); await sleep(350);
    await page.fill(D_ + '[data-border-all="width"]', '3px'); await page.press(D_ + '[data-border-all="width"]', 'Enter'); await sleep(350);
    await page.fill(D_ + '[data-border-all="color"]', '#a3322a'); await page.press(D_ + '[data-border-all="color"]', 'Enter'); await sleep(450);
    let pc = await pageCss(page);
    ok(/\.v10-box \{[^}]*border: 3px dashed #a3322a;/.test(pc) && (await cs(page, '.v10-box', 'border-left-style')) === 'dashed' && (await cs(page, '.v10-box', 'border-top-width')) === '3px', 'מסגרת לכל הצדדים: border: 3px dashed #a3322a — קיצור אחד בכלל .v10-box; הקנבס מתעדכן', pc.slice(-260));
    await page.click(D_ + '[data-border-lock="sides"]'); await sleep(300); await page.click(D_ + '[data-border-side="right"]'); await sleep(300);
    await setNum(page, D_ + '[data-field="border-right-width"] .sg-ctl input', '8px');
    pc = await pageCss(page);
    ok(/border-inline-start-width: 8px;/.test(pc) && (await cs(page, '.v10-box', 'border-right-width')) === '8px' && (await cs(page, '.v10-box', 'border-left-width')) === '3px', 'צד אחד (ימין, RTL): border-inline-start-width — מאפיין לוגי; הצד השני לא משתנה', pc.slice(-200));
    await setNum(page, D_ + '[data-field="border-radius"] .sg-ctl input', '40px / 20px');
    ok(/border-radius: 40px \/ 20px;/.test(await pageCss(page)) && /40px/.test(await cs(page, '.v10-box', 'border-top-left-radius')), 'רדיוס אליפטי בהקלדה: 40px / 20px');
    await page.click(D_ + '[data-radius-lock="corners"]'); await sleep(300);
    await setNum(page, D_ + '[data-field="border-top-right-radius"] .sg-ctl input', '6px');
    ok(/border-start-start-radius: 6px;/.test(await pageCss(page)) && (await cs(page, '.v10-box', 'border-top-right-radius')) === '6px', 'פינה אחת (ימין למעלה, RTL): border-start-start-radius');
    // ידית רדיוס — עכבר אמיתי; Alt ← פינה אחת
    let p = await center(page, '[data-radius-h="bottom-left"]');
    ok(!!p, 'ידיות רדיוס בכל פינה כשהקבוצה פתוחה', p);
    if (p) await dragMouse(page, p, 30, -30);
    pc = await pageCss(page); const rad1 = await cs(page, '.v10-box', 'border-bottom-right-radius');
    ok(/border-radius: \d+px;/.test(pc) && /^\d+px$/.test(rad1) && parseFloat(rad1) > 20 && /^פינות · .*כל הפינות/.test(await lastTx(page)), 'גרירת ידית רדיוס (עכבר) ← border-radius לכל הפינות', { rad1, tx: await lastTx(page) });
    p = await center(page, '[data-radius-h="top-left"]');
    if (p) await dragMouse(page, p, 25, 25, 8, 'Alt');
    pc = await pageCss(page);
    ok(/border-start-end-radius: \d+px;/.test(pc) && parseFloat(await cs(page, '.v10-box', 'border-top-left-radius')) > parseFloat(await cs(page, '.v10-box', 'border-bottom-left-radius')), 'Alt + גרירה ← פינה אחת (שמאל למעלה: border-start-end-radius)', pc.slice(-300));
    await shot(page, 'v10-1920-border');

    // ═══ 2. צל ═══
    await openGrp(page, D_, 'shadow');
    await page.click(D_ + '[data-sh-add="box-shadow"]'); await sleep(450);
    pc = await pageCss(page);
    ok(/box-shadow: 0 8px 24px rgba\(0, 0, 0, 0\.18\);/.test(pc) && /rgba\(0, 0, 0, 0\.18\) 0px 8px 24px/.test(await cs(page, '.v10-box', 'box-shadow')), '＋ שכבת צל ← box-shadow בכלל; הקנבס מציג', pc.slice(-150));
    await page.click(D_ + '[data-sh-add="box-shadow"]'); await sleep(450);
    await page.fill(D_ + '[data-sh="box-shadow:1:color"]', '#a3322a'); await page.press(D_ + '[data-sh="box-shadow:1:color"]', 'Enter'); await sleep(400);
    await page.click(D_ + '[data-sh-inset="box-shadow:1"]'); await sleep(400);
    ok(/box-shadow: 0 8px 24px rgba\(0, 0, 0, 0\.18\), inset 0 8px 24px #a3322a;/.test(await pageCss(page)), 'שתי שכבות; צבע ופנימי לשכבה השנייה', (await pageCss(page)).match(/box-shadow:[^;]*/)?.[0]);
    // גרירת ⠿ — סדר
    p = await center(page, D_ + '[data-sh-layer="box-shadow:1"] .gt-grip'); const p0 = await center(page, D_ + '[data-sh-layer="box-shadow:0"] .gt-grip');
    if (p && p0) await dragMouse(page, p, 0, p0.y - p.y - 6);
    ok(/box-shadow: inset 0 8px 24px #a3322a, 0 8px 24px rgba\(0, 0, 0, 0\.18\);/.test(await pageCss(page)), 'גרירה ⠿ (עכבר) — סדר השכבות', (await pageCss(page)).match(/box-shadow:[^;]*/)?.[0]);
    // השמש — השכבה הנבחרת (הראשונה אחרי הסידור: בוחרים אותה)
    await page.click(D_ + '[data-sh-layer="box-shadow:1"]', { position: { x: 4, y: 4 } }); await sleep(200);
    p = await center(page, '[data-shadow-h]');
    ok(!!p, '☀ על הקנבס כשקבוצת הצל פתוחה', p);
    if (p) await dragMouse(page, p, 30, 12);
    ok(/box-shadow: inset 0 8px 24px #a3322a, 30px 20px 24px rgba\(0, 0, 0, 0\.18\);/.test(await pageCss(page)), 'גרירת ☀ (עכבר) ← X/Y של השכבה הנבחרת', (await pageCss(page)).match(/box-shadow:[^;]*/)?.[0]);
    p = await center(page, '[data-shadow-h]'); await page.mouse.move(p.x, p.y); await page.mouse.wheel(0, -100); await sleep(80); await page.mouse.wheel(0, -100); await sleep(700);
    ok(/30px 20px 28px rgba/.test(await pageCss(page)), 'גלגלת על ☀ ← טשטוש', (await pageCss(page)).match(/box-shadow:[^;]*/)?.[0]);
    await select(page, '.v10-t'); await tab(page, 'design'); await openGrp(page, D_, 'shadow');
    await page.click(D_ + '[data-sh-add="text-shadow"]'); await sleep(450);
    ok(/\.v10-t \{[^}]*text-shadow: 1px 2px 4px rgba\(0, 0, 0, 0\.35\);/.test(await pageCss(page)), 'צל טקסט — שכבה (בלי פריסה ופנימי)');
    await page.selectOption(D_ + '[data-field="outline-style"] select', 'dotted'); await sleep(400);
    await setNum(page, D_ + '[data-field="outline-offset"] .sg-ctl input', '4px');
    ok(/outline-style: dotted;[\s\S]*outline-offset: 4px;/.test(await pageCss(page)), 'קו מתאר: סוג והיסט');
    // ערך שהפאנל אינו מפרק ← "מאפיינים נוספים", לא נדרס
    await select(page, '.v10-raw'); await tab(page, 'design'); await openGrp(page, D_, 'shadow');
    st = await page.evaluate(r => ({ raw: !!document.querySelector(r + '[data-sh-raw="box-shadow"]'), extra: [...document.querySelectorAll(r + '[data-extra-value]')].map(i => i.dataset.extraValue) }), D_);
    ok(st.raw && st.extra.includes('box-shadow'), 'box-shadow עם var() בשכבה — "ערך מורכב", ומוצג ב"מאפיינים נוספים" לעריכה', st);

    // ═══ 3. שקיפות, מסננים, מיזוג ═══
    await select(page, '.v10-box'); await tab(page, 'design'); await openGrp(page, D_, 'effects');
    await setNum(page, D_ + '[data-field="opacity"] .sg-ctl input', '0.9');
    // מחוון — עכבר אמיתי: לחיצה ב-30% של מחוון הטשטוש
    let rb = await page.evaluate(r => { const e = document.querySelector(r + '[data-filter="filter:blur"]'); e.scrollIntoView({ block: 'center' }); const b = e.getBoundingClientRect(); return { x: b.left, y: b.top + b.height / 2, w: b.width }; }, D_);
    await page.mouse.click(rb.x + rb.w * 0.7, rb.y); await sleep(450);   // RTL: המחוון הפוך — 70% משמאל = 30% מהערך
    pc = await pageCss(page); const blur = /filter: blur\(([\d.]+)px\);/.exec(pc);
    ok(/opacity: 0\.9;/.test(pc) && blur && +blur[1] > 0 && /blur/.test(await cs(page, '.v10-box', 'filter')), 'שקיפות 0.9; מחוון טשטוש (לחיצת עכבר) ← filter: blur(Npx)', blur && blur[0]);
    await page.evaluate(r => { const e = document.querySelector(r + '[data-filter="filter:grayscale"]'); e.value = '60'; e.dispatchEvent(new Event('input')); e.dispatchEvent(new Event('change')); }, D_); await sleep(450);
    pc = await pageCss(page);
    ok(/filter: blur\([\d.]+px\) grayscale\(60%\);/.test(pc), 'מסנן שני נוסף אחרי הראשון (הסדר נשמר)', pc.match(/filter:[^;]*/)?.[0]);
    await page.click(D_ + '[data-filter-reset="filter:blur"]'); await sleep(450);
    ok(/filter: grayscale\(60%\);/.test(await pageCss(page)), '⟲ על מסנן אחד — רק הוא יוצא', (await pageCss(page)).match(/filter:[^;]*/)?.[0]);
    await page.evaluate(r => { const e = document.querySelector(r + '[data-filter="backdrop-filter:blur"]'); e.value = '6'; e.dispatchEvent(new Event('change')); }, D_); await sleep(450);
    await page.selectOption(D_ + '[data-field="mix-blend-mode"] select', 'multiply'); await sleep(450);
    pc = await pageCss(page);
    ok(/backdrop-filter: blur\(6px\);/.test(pc) && /mix-blend-mode: multiply;/.test(pc) && (await cs(page, '.v10-box', 'mix-blend-mode')) === 'multiply', 'פילטר רקע ומצב מיזוג');
    await shot(page, 'v10-1920-effects');

    // ═══ 4. מסיכה ═══
    await openGrp(page, D_, 'mask');
    await page.selectOption(D_ + '[data-mask]', 'circle'); await sleep(450);
    pc = await pageCss(page);
    ok(/mask-image: radial-gradient\(circle closest-side, #000 98%, transparent\);\s*-webkit-mask-image: radial-gradient\(circle closest-side, #000 98%, transparent\);/.test(pc) && /radial/.test(await cs(page, '.v10-box', 'mask-image')), 'מסיכה מוכנה (עיגול) ← mask-image + -webkit-mask-image בזוג', pc.slice(-300));
    await page.selectOption(D_ + '[data-mask-size]', 'contain'); await sleep(450);
    ok(/mask-size: contain;\s*-webkit-mask-size: contain;/.test(await pageCss(page)), 'גודל המסיכה — בזוג');
    st = await page.evaluate(r => [...document.querySelectorAll(r + '[data-extra-value]')].map(i => i.dataset.extraValue).filter(x => /mask|filter|opacity|border|shadow|blend/.test(x)), D_);
    ok(!st.length, 'מסיכה / מסננים / מסגרת — לא כפולים ב"מאפיינים נוספים" (יש להם שליטה)', st);
    await page.selectOption(D_ + '[data-mask]', '-'); await sleep(450);
    ok(!/mask/.test(await pageCss(page)), 'מסיכה ← "בלי": כל הצהרות mask-* נמחקות');

    // ═══ 5. מפריד צורה ═══
    await openGrp(page, D_, 'shapes');
    st = await page.evaluate(r => document.querySelectorAll(r + '[data-shape-pick] option').length, D_);
    ok(st >= 10, '10 צורות לפחות', st);
    await page.selectOption(D_ + '[data-shape-pick]', 'wave'); await page.click(D_ + '[data-shape-add="bottom"]'); await sleep(700);
    let all = await files(page);
    ok(/<section class="v10-box"><h2 class="v10-t">כותרת<\/h2><p>טקסט<\/p><div class="shape shape-bottom" data-shape="wave" aria-hidden="true"><svg [^>]*><path fill="currentColor" d="[^"]+"\/><\/svg><\/div><\/section>/.test(all['index.html']) && /\.shape \{[^}]*position: absolute/.test(all['css/page.css']) && /\.v10-box \{[^}]*position: relative;[^}]*isolation: isolate;/.test(all['css/page.css']), 'מפריד גל בתחתית: <div class="shape"> עם SVG גלוי, .shape ב-page.css, position: relative לרובריקה — עסקה אחת', (await lastTx(page)));
    st = await page.evaluate(() => { const S = Editor._state, n = S.canvas.selected; return n && n.tag === 'div' && /shape/.test(n.attrs.map(a => a.value).join(' ')); });
    ok(st, 'המפריד נבחר מיד (אלמנט רגיל)');
    await openGrp(page, D_, 'shape');
    await page.fill(D_ + '[data-field="color"] .sg-ctl input', '#14213d'); await page.press(D_ + '[data-field="color"] .sg-ctl input', 'Enter'); await sleep(450);
    await setNum(page, D_ + '[data-field="height"] .sg-ctl input', '90px');
    const fill = await page.evaluate(() => { const c = Editor._state.canvas, p = c.doc.querySelector('.v10-box .shape path'); return c.frame.contentWindow.getComputedStyle(p).fill; });
    ok(fill === 'rgb(20, 33, 61)' && (await cs(page, '.v10-box .shape', 'height')) === '90px', 'צבע (fill: currentColor) וגובה למפריד', { fill });
    await page.click(D_ + '[data-shape-flip]'); await sleep(450);
    ok(/scaleX\(-1\)/.test(await cs(page, '.v10-box .shape', 'transform')) || /matrix\(-1/.test(await cs(page, '.v10-box .shape', 'transform')), 'היפוך אופקי', await cs(page, '.v10-box .shape', 'transform'));
    await shot(page, 'v10-1920-shape');
    await page.click('.ed-bar [aria-label^="מחיקת האלמנט"]'); await sleep(500);
    ok(!/class="shape/.test(await file(page, 'index.html')), 'מחיקה (🗑) — כמו כל אלמנט');
    await page.evaluate(() => Editor._undo()); await sleep(400);
    ok(/class="shape shape-bottom"/.test(await file(page, 'index.html')), 'Ctrl+Z מחזיר את המפריד');

    // ═══ 6. Transform (מתקדם): פאנל, ידית סיבוב, נקודת ציר, Ctrl + פינה, ריחוף ═══
    await select(page, '.v10-t'); await tab(page, 'adv'); await openGrp(page, A_, 'transform');
    await page.fill(A_ + '[data-tf="tx"]', '10'); await page.press(A_ + '[data-tf="tx"]', 'Enter'); await sleep(450);
    pc = await pageCss(page);
    ok(/\.v10-t \{[^}]*transform: translate\(10px, 0\);/.test(pc) && /matrix\(1, 0, 0, 1, 10, 0\)/.test(await cs(page, '.v10-t', 'transform')), 'הזזה X בפאנל ← transform: translate(10px, 0)', pc.match(/\.v10-t \{[^}]*\}/)?.[0]);
    p = await center(page, '[data-rotate-h]');
    ok(!!p, '⟳ ידית סיבוב מעל האלמנט', p);
    const oc = await page.evaluate(() => { const c = Editor._state.canvas, r = c.rectOf(c.selected), L = c.layer.getBoundingClientRect(); return { x: L.left + r.left + r.width / 2, y: L.top + r.top + r.height / 2 }; });
    // סיבוב של ~30° סביב המרכז, עם Shift ← 30 בדיוק (קפיצות של 15°)
    { const R = Math.hypot(p.x - oc.x, p.y - oc.y), a0 = Math.atan2(p.y - oc.y, p.x - oc.x), a1 = a0 + 32 * Math.PI / 180;
      await page.mouse.move(p.x, p.y); await page.keyboard.down('Shift'); await page.mouse.down(); for (let i = 1; i <= 10; i++) { const a = a0 + (a1 - a0) * i / 10; await page.mouse.move(oc.x + R * Math.cos(a), oc.y + R * Math.sin(a)); await sleep(15); } await page.mouse.up(); await page.keyboard.up('Shift'); await sleep(400); }
    pc = await pageCss(page);
    ok(/transform: translate\(10px, 0\) rotate\(30deg\);/.test(pc) && /^סיבוב · 30deg/.test(await lastTx(page)), 'גרירת ⟳ עם Shift (עכבר) ← rotate(30deg) בקפיצות של 15°; ההזזה נשמרת', pc.match(/\.v10-t \{[^}]*\}/)?.[0]);
    p = await center(page, '[data-origin-h]');
    { const r = await page.evaluate(() => { const c = Editor._state.canvas, r = c.rectOf(c.selected), L = c.layer.getBoundingClientRect(); return { l: L.left + r.left, t: L.top + r.top, w: r.width, h: r.height }; }); await dragMouse(page, p, r.l - p.x + 1, r.t - p.y + 1); }
    ok(/transform-origin: 0% 0%;/.test(await pageCss(page)), 'גרירת נקודת הציר לפינה ← transform-origin: 0% 0% (נצמדת לקצוות)', (await pageCss(page)).match(/transform-origin:[^;]*/)?.[0]);
    await page.evaluate(() => { const c = Editor._state.canvas, e = c.elOf(c.selected); e.scrollIntoView({ block: 'center' }); }); await sleep(300);
    p = await center(page, '[data-size-h="ne"]');
    if (p) await dragMouse(page, p, 30, -30, 8, 'Control');
    pc = await pageCss(page);
    ok(p && /rotate\(30deg\) scale\([\d.]+\);/.test(pc) && !/\.v10-t \{[^}]*width:/.test(pc), 'Ctrl + ידית פינה (V6) ← scale, לא רוחב', pc.match(/\.v10-t \{[^}]*\}/)?.[0]);
    // ריחוף (V8): אותה שליטה נכתבת לכלל :hover
    await page.click(A_ + '[data-state=":hover"]'); await sleep(300);
    await page.fill(A_ + '[data-tf="sx"]', '1.2'); await page.press(A_ + '[data-tf="sx"]', 'Enter'); await sleep(450);
    pc = await pageCss(page);
    ok(/\.v10-t:hover \{[^}]*transform: [^;]*scale\(1\.2, [\d.]+\);/.test(pc) && !/\.v10-t \{[^}]*scale\(1\.2/.test(pc), 'במצב ריחוף — transform נכתב ל-.v10-t:hover ("גם בריחוף"; מהערך הרגיל + קנה מידה)', pc.match(/\.v10-t:hover \{[^}]*\}/)?.[0]);
    await page.click(A_ + '[data-state="normal"]'); await sleep(300);
    await shot(page, 'v10-1920-transform');

    // ═══ 7. מעבר עם עורך עקומה (עיצוב) ═══
    await tab(page, 'design');
    await setNum(page, D_ + '[data-field="transition-duration"] .sg-ctl input', '0.4s');
    await page.evaluate(r => document.querySelector(r + '[data-bezier="transition-timing-function"]')?.scrollIntoView({ block: 'center' }), D_); await sleep(150);
    p = await center(page, D_ + '[data-bezier="transition-timing-function"] [data-bz="1"]'); if (p) await dragMouse(page, p, 30, -40);
    pc = await pageCss(page);
    ok(/transition-duration: 0\.4s;/.test(pc) && /transition-timing-function: cubic-bezier\([\d.]+, [\d.-]+, 0\.25, 1\);/.test(pc), 'גרירת ידית בעורך העקומה (עכבר) ← cubic-bezier(…)', pc.match(/transition-timing-function:[^;]*/)?.[0]);

    // ═══ 8. אנימציית כניסה ← css/motion.css ═══
    await select(page, '.v10-t'); await tab(page, 'adv'); await openGrp(page, A_, 'entrance');
    await page.selectOption(A_ + '[data-in]', 'fade-up'); await sleep(1500);
    all = await files(page);
    ok(/<h2 class="v10-t kol-in kol-in-fade-up">/.test(all['index.html']) && /<link rel="stylesheet" href="css\/motion\.css">/.test(all['index.html']) && /@keyframes kol-in-fade-up \{\n  from \{ opacity: 0; transform: translateY\(40px\); \}/.test(all['css/motion.css'] || '') && /prefers-reduced-motion: no-preference[\s\S]*\.kol-in \{/.test(all['css/motion.css'] || ''), '"החלקה מלמטה": מחלקה kol-in kol-in-fade-up; css/motion.css נוצר (keyframes בתוך reduced-motion) ומקושר', { tx: await lastTx(page), m: (all['css/motion.css'] || '').slice(0, 120) });
    ok(!all['js/kol-motion.js'], '"בטעינה" — בלי js/kol-motion.js (CSS בלבד)');
    await setNum(page, A_ + '[data-field="--kol-in-dur"] .sg-ctl input', '1.2s');
    ok(/\.v10-t \{[^}]*--kol-in-dur: 1\.2s;/.test(await pageCss(page)), 'משך ← --kol-in-dur בכלל היעד');
    st = await page.evaluate(() => { const c = Editor._state.canvas, e = c.doc.querySelector('.v10-t'), w = c.frame.contentWindow; return { an: w.getComputedStyle(e).animationName, op: w.getComputedStyle(e).opacity }; });
    ok(st.an === 'none' && st.op === '1', 'בקנבס האנימציות מושהות כברירת מחדל — המצב הסופי (לא שקוף)', st);
    await page.click('[data-play]'); await sleep(150);
    st = await page.evaluate(() => { const c = Editor._state.canvas, e = c.doc.querySelector('.v10-t'), w = c.frame.contentWindow; return { an: w.getComputedStyle(e).animationName, pressed: document.querySelector('[data-play]').getAttribute('aria-pressed') }; });
    ok(st.an === 'kol-in-fade-up' && st.pressed === 'true', '"▶ הפעל אנימציות" (עכבר) — האנימציה רצה בקנבס', st);
    await page.click('[data-play]'); await sleep(150);
    await page.click(A_ + '[data-in-replay]'); await sleep(100);
    st = await page.evaluate(() => { const c = Editor._state.canvas, e = c.doc.querySelector('.v10-t'); return { an: c.frame.contentWindow.getComputedStyle(e).animationName, attr: e.hasAttribute('data-kol-replay') }; });
    ok(st.an === 'kol-in-fade-up' && st.attr, '"↻ הצג שוב" — האלמנט הנבחר בלבד', st);
    // "כשנראה" ← js/kol-motion.js
    await page.click(A_ + '[data-in-mode="view"]'); await sleep(1800);
    all = await files(page); const kj = JSON.parse(all['kol.json']);
    ok(/<h2 class="v10-t kol-in kol-in-fade-up" data-kol-in="view">/.test(all['index.html']) && /<script src="js\/kol-motion\.js" defer><\/script>/.test(all['index.html']) && /IntersectionObserver/.test(all['js/kol-motion.js'] || '') && kj.system && kj.system['js/kol-motion.js'], '"כשנראה במסך": data-kol-in="view"; js/kol-motion.js נוצר (קובץ מערכת ב-kol.json) ו-<script defer> בעמוד', { sys: kj.system, tx: await lastTx(page) });
    ok(!/localStorage|cookie/.test((all['js/kol-motion.js'] || '').replace(/\/\*[\s\S]*?\*\//, '')), 'kol-motion.js — בלי אחסון');
    // התצוגה החיה: עם JS — is-in; prefers-reduced-motion — בלי תנועה
    await flush(page);
    const tok = (await call(page, 'pv_token')).token, pv = await ctx.newPage();
    await pv.goto(`${base}/preview/${tok}/index.html`); await sleep(400);
    await pv.evaluate(() => document.querySelector('.v10-t').scrollIntoView()); await sleep(1800);
    st = await pv.evaluate(() => { const e = document.querySelector('.v10-t'); return { on: document.documentElement.classList.contains('kol-motion-on'), isIn: e.classList.contains('is-in'), op: getComputedStyle(e).opacity, an: getComputedStyle(e).animationName }; });
    ok(st.on && st.isIn && st.op === '1' && st.an === 'kol-in-fade-up', 'התצוגה החיה: kol-motion-on, is-in בכניסה למסך, והאלמנט מגיע לשקיפות 1', st);
    await pv.emulateMedia({ reducedMotion: 'reduce' }); await pv.reload(); await sleep(500);
    st = await pv.evaluate(() => { const e = document.querySelector('.v10-t'); return { an: getComputedStyle(e).animationName, op: getComputedStyle(e).opacity }; });
    ok(st.an === 'none' && st.op === '1', 'prefers-reduced-motion: reduce (אמולציה) ← אין תנועה, המצב הסופי', st);
    await pv.emulateMedia({ reducedMotion: 'no-preference' }); await pv.route('**/kol-motion.js', r => r.abort()); await pv.reload(); await sleep(1600);
    st = await pv.evaluate(() => { const e = document.querySelector('.v10-t'); return { on: document.documentElement.classList.contains('kol-motion-on'), op: getComputedStyle(e).opacity }; });
    ok(!st.on && st.op === '1', 'בלי kol-motion.js (נחסם) — האנימציה רצה בטעינה ומסתיימת גלויה; אף פעם לא תוכן שקוף', st);
    await pv.close();

    // ═══ 9. אפקט גלילה ═══
    await select(page, '.v10-box'); await tab(page, 'adv'); await openGrp(page, A_, 'scroll');
    await page.click(A_ + '[data-sc-on]'); await sleep(1200);
    all = await files(page);
    ok(/<section class="v10-box kol-sc">/.test(all['index.html']) && /@supports \(animation-timeline: view\(\)\)/.test(all['css/motion.css']) && /@keyframes kol-sc/.test(all['css/motion.css']) && /--kol-sc-o0: 0;/.test(all['css/page.css']), 'אפקט גלילה: kol-sc + keyframes בתוך @supports (CSS בלבד); ברירת מחדל: הופעה ועלייה', (await lastTx(page)));
    await page.fill(A_ + '[data-sc-parallax]', '0.5'); await page.press(A_ + '[data-sc-parallax]', 'Enter'); await sleep(450);
    await page.selectOption(A_ + '[data-sc-range="--kol-sc-to"]', 'cover 50%'); await sleep(450);
    pc = await pageCss(page);
    ok(/--kol-sc-y0: 50px;[\s\S]*--kol-sc-y1: -50px;/.test(pc) && /--kol-sc-to: cover 50%;/.test(pc), 'parallax 0.5 ← ±50px; טווח (סוף) ← cover 50%');
    await page.evaluate(r => { const s = document.querySelector(r + '[data-sc-sim]'); s.value = '0'; s.dispatchEvent(new Event('input')); }, A_); await sleep(150);
    const o_0 = await cs(page, '.v10-box', 'opacity');
    await page.evaluate(r => { const s = document.querySelector(r + '[data-sc-sim]'); s.value = '1'; s.dispatchEvent(new Event('input')); }, A_); await sleep(150);
    const o_1 = await cs(page, '.v10-box', 'opacity');
    ok(+o_0 < 0.2 && +o_1 > 0.8, '"מיקום גלילה מדומה" — הקנבס מראה כל נקודה בטווח (שקיפות 0 ← 1)', { o_0, o_1 });

    // ═══ 10. נדבק ═══
    await select(page, '.v10-t'); await tab(page, 'adv'); await openGrp(page, A_, 'sticky');
    await page.click(A_ + '[data-sticky="top"]'); await sleep(450);
    await setNum(page, A_ + '[data-field="top"] .sg-ctl input', '20px');
    pc = await pageCss(page); ok(/\.v10-t \{[^}]*position: sticky;[^}]*inset-block-start: 20px;/.test(pc) && (await cs(page, '.v10-t', 'position')) === 'sticky', 'נדבק למעלה, היסט 20px ← position: sticky; inset-block-start', pc.match(/\.v10-t \{[^}]*\}/)?.[0]);

    // ═══ 11. וידאו ברקע ומצגת ═══
    for (const [pth, body] of [['media/v10/clip.mp4', Buffer.from('fakemp4').toString('base64')], ['media/v10/a.png', 'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAFklEQVR4nGP8z8DAwMDAxMDAwMDAAAANHQEDasKb6QAAAABJRU5ErkJggg=='], ['media/v10/b.png', 'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAFklEQVR4nGP8z8DAwMDAxMDAwMDAAAANHQEDasKb6QAAAABJRU5ErkJggg==']]) await call(page, 'pf_create', { path: pth, content_b64: body });
    await page.evaluate(() => Project.docs.reload()); await sleep(300);
    const pickItem = async name => { await page.waitForSelector(`#sheet[open] [data-pick="${name}"]`); await page.click(`#sheet[open] [data-pick="${name}"]`); await sleep(300); };
    await select(page, '.v10-box'); await tab(page, 'adv'); await openGrp(page, A_, 'bgvideo');
    await page.click(A_ + '[data-bgv-add]'); await pickItem('media/v10/clip.mp4'); await pickItem('media/v10/a.png'); await sleep(900);
    all = await files(page);
    ok(/<section class="v10-box kol-sc"><video class="bg-video" src="media\/v10\/clip\.mp4" poster="media\/v10\/a\.png" autoplay muted loop playsinline aria-hidden="true"><\/video><h2/.test(all['index.html']) && /\.bg-video \{[^}]*object-fit: cover;/.test(all['css/page.css']) && /@media \(max-width: 767px\) \{[\s\S]*\.bg-video \{ display: none; \}/.test(all['css/page.css']), 'וידאו ברקע: <video class="bg-video"> ראשון ברובריקה (src, poster, autoplay muted loop playsinline), .bg-video ב-CSS, "רק במחשב" ← display:none ב-@media של הטלפון', all['css/page.css'].slice(-260));
    st = await page.evaluate(() => { const n = Editor._state.canvas.selected; return n ? n.tag : null; });
    const navHas = await page.evaluate(() => [...document.querySelectorAll('.nv *')].some(e => /video\.bg-video/.test(e.textContent)));
    ok(st === 'video' && navHas, 'הווידאו נבחר, ומופיע ב-Navigator (video.bg-video)', { st, navHas });
    await select(page, '.v10-box'); await tab(page, 'adv'); await openGrp(page, A_, 'bgvideo');
    await page.click(A_ + '[data-sl-new]'); await pickItem('media/v10/a.png'); await pickItem('media/v10/b.png'); await sleep(1200);
    all = await files(page);
    ok(/<div class="bg-slides bg-slides-2 kol-slides-fade-2" style="--kol-slide-d: 5s" aria-hidden="true"><div class="bg-slide" style="background-image: url\(&#39;media\/v10\/a\.png&#39;\)"><\/div><div class="bg-slide"/.test(all['index.html']) || /<div class="bg-slides bg-slides-2 kol-slides-fade-2"[^>]*><div class="bg-slide" style="background-image: url\(/.test(all['index.html']), 'מצגת רקע: שכבות div.bg-slide (כל תמונה — אלמנט), בתוך .bg-slides', all['index.html'].match(/<div class="bg-slides[^]*?<\/div><\/div>/)?.[0]);
    ok(/@keyframes kol-slides-fade-2/.test(all['css/motion.css']) && /animation-delay: calc\(var\(--kol-slide-d, 5s\) \* 1\)/.test(all['css/motion.css']), 'keyframes עם השהיות מחושבות — ב-motion.css (CSS בלבד)');
    await page.click(A_ + '[data-sl-kb]'); await sleep(1200);
    ok(/kol-slides-fade-kb-2/.test(await file(page, 'index.html')) && /@keyframes kol-slides-fade-kb-2/.test(await file(page, 'css/motion.css')), '"קן ברנס" ← keyframes משלו');
    await shot(page, 'v10-1920-motion');

    // ═══ 12. הזרקה ═══
    st = await page.evaluate(() => { const F = window.KolFx, out = []; for (const v of ['red;}body{display:none', '0 0 1px red</style><script>alert(1)</script>', 'url(javascript:alert(1))', 'blur(1px) /* x */', 'expression(alert(1))', '@import url(x)', "0 0 1px 'a", 'rotate(1deg))']) { try { F.checkFx(v); out.push('עבר: ' + v); } catch { /* נדחה */ } } try { F.checkFx("url('javascript:alert(1)')", { url: true }); out.push('url js'); } catch { /* */ } try { F.planMotion('', ['in:x;}a{']); out.push('in:x'); } catch { /* */ } try { F.slidesCss(11, 'fade'); out.push('11'); } catch { /* */ } try { F.checkSvg('<svg onload="x()"></svg>'); out.push('svg'); } catch { /* */ } return out; });
    ok(!st.length, 'הזרקה: } ; < url(javascript: הערות expression @import, שם keyframes מוזרק, SVG עם on… — כולם נדחים', st);
    await select(page, '.v10-t'); await tab(page, 'adv'); await openGrp(page, A_, 'transform');
    const before = await pageCss(page);
    await page.fill(A_ + '[data-tf="rot"]', '1deg) } body { display: none'); await page.press(A_ + '[data-tf="rot"]', 'Enter'); await sleep(450);
    ok((await pageCss(page)) === before, 'הקלדת } בשדה סיבוב — לא נכתב דבר');

    // ═══ 13. ביטול עד ההתחלה ═══
    const back = await undoFiles(page, o0); const tr = await tree(page);
    if (!back) { const now = await files(page); for (const k of new Set([...Object.keys(now), ...Object.keys(o0)])) if (now[k] !== o0[k]) console.log('   diff', k, JSON.stringify(String(now[k]).slice(-300)), '|||', JSON.stringify(String(o0[k]).slice(-200))); console.log('   tx', await page.evaluate(() => Editor._state.tx.map(t => t.label))); }
    ok(back && !tr.includes('css/motion.css') && !tr.includes('js/kol-motion.js'), 'Ctrl+Z עד ההתחלה: כל הקבצים זהים בייט בבייט; css/motion.css ו-js/kol-motion.js — בסל', { back, tr: tr.filter(x => /motion/.test(x)) });

    // ═══ 14. על כל המסך: 1920×1080 ו-1366×768 — הקנבס והפאנל ממלאים את הרוחב; הפאנל אינו חורג ═══
    for (const [w, hgt] of [[1920, 1080], [1366, 768]]) {
      await page.setViewportSize({ width: w, height: hgt }); await sleep(400);
      await select(page, '.v10-box'); await tab(page, 'design'); for (const id of ['border', 'shadow', 'effects']) await openGrp(page, D_, id);
      st = await page.evaluate(() => { const b = document.querySelector('.ed-body').getBoundingClientRect(), side = document.querySelector('.ed-info').getBoundingClientRect(), mid = document.querySelector('.ed-mid').getBoundingClientRect(), over = []; for (const e of document.querySelectorAll('.ed-info .sg *')) { const r = e.getBoundingClientRect(); if (r.width && (r.right > side.right + 1 || r.left < side.left - 1)) over.push((e.className && e.className.baseVal == null ? e.className : e.tagName) + ''); } return { left: Math.round(b.left), right: Math.round(innerWidth - b.right), mid: Math.round(mid.width), side: Math.round(side.width), over: over.slice(0, 4) }; });
      ok(st.left <= 16 && st.right <= 16 && st.mid > w * 0.45 && !st.over.length, w + '×' + hgt + ': העורך ברוחב המסך (בלי פס ריק), הקבוצות החדשות בתוך הפאנל — שום רכיב לא חורג', st);
      await shot(page, 'v10-' + w + '-panel');
    }
    await page.setViewportSize({ width: 1920, height: 1080 }); await sleep(300);

    // ═══ 15. טלפון (390) — מגע ═══
    const ctxP = await mk(390, 844), pp = await ctxP.newPage();
    pp.on('pageerror', e => { errors.push(e.message); console.log('   ⚠️ (390) ' + e.message); });
    await openPage(pp, 'index.html');
    await pp.evaluate(() => { const c = Editor._state.canvas, e = c.doc.querySelector('.v10-box'); c.select(c.nodeOf(e)); c.reveal(c.nodeOf(e)); });
    if (!(await pp.evaluate(() => document.querySelector('.ed').classList.contains('info-open')))) await pp.tap('.ed-bar [data-panel="info"]');
    await sleep(300); await pp.tap('.ed-ptabs [data-ptab="design"]'); await sleep(300);
    await pp.evaluate(r => { for (const id of ['border', 'shadow']) { const d = document.querySelector(r + '[data-sec="' + id + '"]'); if (d && !d.open) d.querySelector('summary').click(); } }, D_); await sleep(300);
    await pp.tap(D_ + '[data-radius-lock="all"]').catch(() => {}); await sleep(200);
    const small = await pp.evaluate(r => [...document.querySelectorAll(r + '.sg-fx button, ' + r + '.sg-fx select')].filter(e => e.offsetParent && e.getBoundingClientRect().height < 43.5).map(e => ((e.dataset && Object.keys(e.dataset)[0]) || e.className || e.tagName) + ':' + Math.round(e.getBoundingClientRect().height)).slice(0, 6), D_);
    ok(!small.length, '390: כפתורי הקבוצות החדשות ≥44px', small);
    await pp.tap(D_ + '[data-sh-add="box-shadow"]'); await sleep(500);
    ok(/\.v10-box \{[^}]*box-shadow: 0 8px 24px/.test(await pp.evaluate(() => [...Editor._state.sheets.values()].find(x => x.path === 'css/page.css').doc.text())), '390 (הקשה): ＋ שכבת צל — נכתב');
    // ידית הרדיוס במגע: 36px, וגרירה באירועי מגע אל הידית
    st = await pp.evaluate(() => { const b = document.querySelector('[data-radius-h="top-right"]'); if (!b || b.hidden) return null; const r = b.getBoundingClientRect(); return { w: r.width, x: r.left + r.width / 2, y: r.top + r.height / 2 }; });
    ok(st && st.w >= 36, '390: ידית רדיוס גדולה למגע', st);
    if (st) await pp.evaluate(p => { const b = document.querySelector('[data-radius-h="top-right"]'), f = (t, x, y) => b.dispatchEvent(new PointerEvent(t, { bubbles: true, clientX: x, clientY: y, pointerId: 3, pointerType: 'touch', isPrimary: true, button: 0, buttons: t === 'pointerup' ? 0 : 1 })); f('pointerdown', p.x, p.y); for (let i = 1; i <= 6; i++) f('pointermove', p.x - i * 4, p.y + i * 4); f('pointerup', p.x - 24, p.y + 24); }, st);
    await sleep(500);
    ok(/\.v10-box \{[^}]*border-radius: \d+px;/.test(await pp.evaluate(() => [...Editor._state.sheets.values()].find(x => x.path === 'css/page.css').doc.text())), '390 (מגע): גרירת ידית רדיוס ← border-radius');
    ok(await pp.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), '390: בלי גלילה אופקית');
    await pp.screenshot({ path: path.join(shotDir, 'v10-390-design.png') });
    await pp.tap('.ed-ptabs [data-ptab="adv"]'); await sleep(300);
    await pp.evaluate(r => { const d = document.querySelector(r + '[data-sec="transform"]'); d.scrollIntoView(); if (!d.open) d.querySelector('summary').click(); }, A_); await sleep(300);
    await pp.screenshot({ path: path.join(shotDir, 'v10-390-adv.png') });
    for (let i = 0; i < 3; i++) { await pp.evaluate(() => Editor._undo()); await sleep(200); }
    await ctxP.close();
    ok(!errors.length, 'אין שגיאות JavaScript בדף', errors.slice(0, 3));
    console.log('\nצילומים: ' + shotDir);
  } catch (e) { failed++; console.log('❌ חריגה: ' + (e.stack || e.message)); }
  finally { await browser?.close(); php.kill(); }
  console.log('\n' + (failed ? '❌ נכשלו ' + failed + ' מתוך ' + ran : '✅ כל ' + ran + ' הבדיקות עברו'));
  process.exit(failed ? 1 : 0);
})();
