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
    const files = page => page.evaluate(async () => { const S = Editor._state, o = { [S.file.path]: S.doc.text() }; for (const x of S.sheets.values()) o[x.path] = x.doc.text(); for (const p of ['kol.json', 'js/page.js', 'css/motion.css', 'js/kol-motion.js']) { const kf = Project.docs.byPath(p); if (kf) { const d = await Project.docs.open(kf.id); o[p] = d.text(); } } return o; });
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
    await page.evaluate(() => { const S = Editor._state, t = S.doc.text(), at = t.indexOf('</main>') >= 0 ? t.indexOf('</main>') : t.lastIndexOf('<script'); S.doc.edit([{ from: at, to: at, text: '<section class="v10-box"><h2 class="v10-t">כותרת</h2><p>טקסט</p></section>\n' }], 'הכנה');
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
    await page.evaluate(() => { const S = Editor._state, t = S.doc.text(), at = t.indexOf('<section class="v10-box">'); S.doc.edit([{ from: at, to: at, text: '<div class="v10-raw">x</div>' }], 'הכנה'); }); await sleep(500);
    await select(page, '.v10-raw'); await tab(page, 'design'); await openGrp(page, D_, 'shadow');
    st = await page.evaluate(r => ({ raw: !!document.querySelector(r + '[data-sh-raw="box-shadow"]'), extra: [...document.querySelectorAll(r + '[data-extra-value]')].map(i => i.dataset.extraValue) }), D_);
    ok(st.raw && st.extra.includes('box-shadow'), 'box-shadow עם var() בשכבה — "ערך מורכב", ומוצג ב"מאפיינים נוספים" לעריכה', st);
    ok(!errors.length, 'אין שגיאות JavaScript בדף', errors.slice(0, 3));
    console.log('\nצילומים: ' + shotDir);
  } catch (e) { failed++; console.log('❌ חריגה: ' + (e.stack || e.message)); }
  finally { await browser?.close(); php.kill(); }
  console.log('\n' + (failed ? '❌ נכשלו ' + failed + ' מתוך ' + ran : '✅ כל ' + ran + ' הבדיקות עברו'));
  process.exit(failed ? 1 : 0);
})();
