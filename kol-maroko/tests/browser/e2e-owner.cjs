/**
 * E2E — תרחיש הבעלים מקצה לקצה, בדפדפן (Chromium, Playwright):   node kol-maroko/tests/browser/e2e-owner.cjs
 *
 * בעל העמוד עובר על כל מה שביקש ב-docs/owner-brief.md — **בלחיצות ובגרירה בלבד**, כמו שהוא היה עושה. הקוד של הבדיקה
 * קורא את המצב (קבצים, טבלה, בנייה) כדי לאמת, אבל לא משנה דבר בעצמו: כל שינוי עובר דרך כפתור, שדה, ידית או גרירה במסך.
 *
 *  0. ייבוא העמוד של היום (📁 פרויקט ← "🔄 ייבוא עכשיו").
 *  1. 🎨 עורך ← index.html נפתח; לחיצה על הכותרת בוחרת אותה.
 *  2. טקסט: לחיצה כפולה על הכותרת ← הקלדה ← נשמר ב-index.html.
 *  3. מרווחים בין הכותרות שעל התמונה: 📏 ← גרירת ידית הרווח בין הכותרת לשורת המשנה, ובין שתי שורות המשנה.
 *  4. גרירת כותרת למקום אחר ב-hero, Ctrl+Z; "＋ ספרייה" ← "H כותרת" נגררת אל ה-hero.
 *  5. תמונה: תמונת הרקע — 🗑 הסר ← ＋ תמונת רקע (העלאה מהמחשב); "＋🖼️ תמונה" ← "🖼️ החלף" ← 🗑️.
 *  6. מיקום חופשי: ✥ ← גרירה על הקנבס ← position/left/top בקובץ.
 *  7. צבע וגופן: לשונית 🎨 עיצוב ← "צבע טקסט", "גופן".
 *  8. כרטיס מילה: "התבנית — חל על כל המילים" (כל הכרטיסים) · "המילה הזו" ← ✏️ ← הטבלה.
 *  9. רכיב: "🧩 שמור כרכיב" ← לשונית 🧩 רכיבים ← גרירה לקנבס ← שני מופעים.
 * 10. קוד: "</> פתח בקוד" ← הקלדה בעורך הקוד ← חזרה לעורך — הקנבס מסונכרן; ◫ מבט מפוצל.
 * 11. 🚀 פרסום: בנייה ← "לתור" ← "⏳ ממתין למשיכה" — עד התור (האתר: tests/fake-wp.php, בלי אתר אמיתי).
 * 12. 390 במגע: הקשה בוחרת, ידית המרווח במגע, צבע מהפאנל.
 *
 * כל מקום שבו בעל העמוד **אינו יכול** לשנות משהו שהוא רואה — מודפס בסוף כ"ממצא" (🔴/🟡/⚪) ונרשם ב-team/log/code-E2E.md.
 * צילומים — ל-tmpdir (kol-e2e-shots), לבדיקה בעין.
 */
'use strict';
const { chromium } = require('playwright');
const { spawn, execFileSync } = require('child_process');
const fs = require('fs'), os = require('os'), path = require('path'), net = require('net'), zlib = require('zlib');

const ROOT = path.resolve(__dirname, '..', '..');
const OWNER = { sub: 'u-owner', name: 'Owner Test', email: 'owner@example.test', email_verified: true, platform_admin: true };
let failed = 0, ran = 0;
let T0 = Date.now();
const ok = (cond, what, got) => { ran++; const dt = ((Date.now() - T0) / 1000).toFixed(1); T0 = Date.now(); if (cond) console.log('✅ [' + dt + 's] ' + what); else { failed++; console.log('❌ ' + what + (got !== undefined ? '\n   התקבל: ' + JSON.stringify(got).slice(0, 900) : '')); } return !!cond; };
const findings = [];
const finding = (sev, what) => { findings.push(sev + ' ' + what); console.log('   ' + sev + ' ממצא: ' + what); };
const freePort = () => new Promise(r => { const s = net.createServer().listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => r(p)); }); });
const sleep = ms => new Promise(r => setTimeout(r, ms));
const until = async (fn, ms = 10000) => { const t = Date.now(); for (;;) { try { const v = await fn(); if (v) return v; } catch { /* עוד לא */ } if (Date.now() - t > ms) return null; await sleep(100); } };
// PNG בצבע אחיד — "התמונה מהמחשב" של בעל העמוד
const png = (w, h, rgb) => {
  const crc = b => { let c, t = []; for (let n = 0; n < 256; n++) { c = n; for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1; t[n] = c >>> 0; } let x = 0xffffffff; for (const v of b) x = t[(x ^ v) & 0xff] ^ (x >>> 8); return (x ^ 0xffffffff) >>> 0; };
  const ch = (type, d) => { const l = Buffer.alloc(4); l.writeUInt32BE(d.length); const td = Buffer.concat([Buffer.from(type), d]); const c = Buffer.alloc(4); c.writeUInt32BE(crc(td)); return Buffer.concat([l, td, c]); };
  const ih = Buffer.alloc(13); ih.writeUInt32BE(w, 0); ih.writeUInt32BE(h, 4); ih[8] = 8; ih[9] = 2;
  const row = Buffer.concat([Buffer.from([0]), Buffer.from(Array(w).fill(rgb).flat())]);
  return Buffer.concat([Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]), ch('IHDR', ih), ch('IDAT', zlib.deflateSync(Buffer.concat(Array(h).fill(row)))), ch('IEND', Buffer.alloc(0))]);
};

(async () => {
  const data = fs.mkdtempSync(path.join(os.tmpdir(), 'kol-e2e-'));
  const wpDir = fs.mkdtempSync(path.join(os.tmpdir(), 'kol-e2ewp-'));
  const shotDir = path.join(os.tmpdir(), 'kol-e2e-shots'); fs.mkdirSync(shotDir, { recursive: true });
  const RED = path.join(data, 'hero-red.png'), GREEN = path.join(data, 'photo-green.png');
  fs.writeFileSync(RED, png(320, 180, [200, 40, 40])); fs.writeFileSync(GREEN, png(200, 200, [30, 160, 60]));
  execFileSync('php', [path.join(__dirname, 'f3-seed.php'), data, path.join(data, 'old.html')]);
  const [port, wpPort] = [await freePort(), await freePort()];
  const base = `http://127.0.0.1:${port}`, wp = `http://127.0.0.1:${wpPort}`;
  // בעל האתר כבר חיבר את האתר (ערוץ משיכה, M7) — תנאי מוקדם, לא חלק מהתרחיש של בעל העמוד
  execFileSync('php', ['-r', `define('KOL_DATA_DIR', $argv[1]); foreach (['members', 'import', 'ai', 'agent', 'dev', 'preview', 'migrate', 'build'] as $l) require_once $argv[2] . "/lib/$l.php";
    setting_set('site_url', $argv[3]); setting_set('pub_channel', 'pull'); setting_set('pull_url', $argv[4]);
    file_put_contents($argv[5] . '/options.json', json_encode(['kol_maroko_keys' => pub_key_line(pub_key())]));`, data, ROOT, wp, base + '/pull.php', wpDir]);
  const env = { ...process.env, PHP_CLI_SERVER_WORKERS: '6' };
  const php = spawn('php', ['-S', `127.0.0.1:${port}`, path.join(ROOT, 'tests', 'router.php')], { cwd: ROOT, env: { ...env, KOL_TEST_DATA: data }, stdio: 'ignore' });
  const wpPhp = spawn('php', ['-S', `127.0.0.1:${wpPort}`, path.join(ROOT, 'tests', 'fake-wp.php')], { cwd: ROOT, env: { ...env, KOL_TEST_WP: wpDir }, stdio: 'ignore' });
  let browser;
  const errors = [];
  try {
    for (let i = 0; i < 100; i++) { try { await fetch(base + '/api.php?action=embed_key'); await fetch(wp + '/'); break; } catch { await sleep(50); } }
    browser = await chromium.launch();
    const mk = (width, height) => browser.newContext({ viewport: { width, height }, hasTouch: width < 600, isMobile: width < 600, extraHTTPHeaders: { 'X-Test-User': JSON.stringify(OWNER) } });
    const watch = (page, tag) => { page.on('pageerror', e => { errors.push(tag + ': ' + e.message); console.log('   ⚠️ שגיאה בדף (' + tag + '): ' + e.message); }); page.on('dialog', d => d.accept()); };

    // ── קריאה בלבד (לאימות) ──
    const call = (page, action, body, query = '') => page.evaluate(([a, b, q]) => api(a, b ?? undefined, q), [action, body ?? null, query]);
    const ready = (page, p = 'index.html') => page.waitForFunction(q => typeof Editor !== 'undefined' && Editor._state.file && Editor._state.file.path === q && Editor._state.canvas && Editor._state.canvas.m && !Editor._state.busy && Editor._state.lv && Editor._state.lv.lists.every(L => L.status !== 'loading'), p, { timeout: 30000 });
    const files = page => page.evaluate(() => { const S = Editor._state, o = { [S.file.path]: S.doc.text() }; for (const x of S.sheets.values()) o[x.path] = x.doc.text(); return o; });
    const allCss = async page => Object.entries(await files(page)).filter(([k]) => /\.css$/.test(k)).map(([, v]) => v).join('\n');
    const html = async page => (await files(page))['index.html'];
    const heroOrder = page => page.evaluate(() => [...Editor._state.canvas.doc.querySelector('.kadisha-hero-inner').children].map(e => e.localName + (e.className ? '.' + String(e.className).split(' ')[0] : '')));
    const crumb = page => page.evaluate(() => { const n = Editor._state.canvas.selected; return n ? Editor._state.canvas.label(n) : null; });
    const cs = (page, sel, prop, i = 0) => page.evaluate(([s, p, i]) => { const e = Editor._state.canvas.doc.querySelectorAll(s)[i]; return e ? getComputedStyle(e)[p] : null; }, [sel, prop, i]);
    const saved = page => page.waitForFunction(() => [...Project._state.docs.values()].every(d => !d.dirty && !d.saving), null, { timeout: 15000 }).catch(() => null);
    const shot = (page, name) => page.screenshot({ path: path.join(shotDir, name + '.png') });

    // ── מה שבעל העמוד עושה: נקודה על המסך, לחיצה, גרירה ──
    /** מרכז האלמנט בקנבס — בקואורדינטות של המסך (גולל אליו אם הוא מחוץ לתצוגה, כמו שאדם גולל). */
    const at = (page, sel, i = 0, fx = 0.5, fy = 0.5) => page.evaluate(([s, i, fx, fy]) => { const c = Editor._state.canvas, e = c.doc.querySelectorAll(s)[i]; if (!e) return null; const r0 = e.getBoundingClientRect(); if (r0.top < 0 || r0.bottom > c.doc.defaultView.innerHeight) e.scrollIntoView({ block: 'center' }); const r = e.getBoundingClientRect(), f = c.frame.getBoundingClientRect(); return { x: f.left + (r.left + r.width * fx) * c.scale, y: f.top + (r.top + r.height * fy) * c.scale }; }, [sel, i, fx, fy]);
    const box = async (page, sel) => { const e = await page.$(sel); if (!e) return null; await e.scrollIntoViewIfNeeded(); const r = await e.boundingBox(); return r && { x: r.x + r.width / 2, y: r.y + r.height / 2 }; };
    const click = async (page, sel, i = 0, fx, fy) => { const p = await at(page, sel, i, fx, fy); if (!p) return false; await page.mouse.click(p.x, p.y); await sleep(400); return true; };
    const tap = async (page, sel, i = 0) => { const p = await at(page, sel, i); if (!p) return false; await page.touchscreen.tap(p.x, p.y); await sleep(500); return true; };
    // גרירה: מעל הקנבס (iframe sandbox) page.mouse אינו מסתיים (V6b) — PointerEvent אל האלמנט שמתחת לאצבע, כמו שהדפדפן שולח
    const PD = () => {
      const ed = Editor._state, c = ed.canvas;
      const inFrame = (x, y) => { const f = c.frame.getBoundingClientRect(); return x >= f.left && x <= f.right && y >= f.top && y <= f.bottom; };
      const fpt = (x, y) => { const f = c.frame.getBoundingClientRect(); return [(x - f.left) / c.scale, (y - f.top) / c.scale]; };
      const fire = (t, type, x, y, cx, cy, touch) => t.dispatchEvent(new (t.ownerDocument.defaultView.PointerEvent)(type, { bubbles: true, cancelable: true, composed: true, clientX: cx, clientY: cy, pointerId: touch ? 2 : 1, pointerType: touch ? 'touch' : 'mouse', isPrimary: true, button: type === 'pointermove' ? -1 : 0, buttons: type === 'pointerup' ? 0 : 1 }));
      window.__pd = {
        // כמו מצביע אמיתי: מה שעליון בנקודה — שכבה במעטפת (ידית, תיבת הזזה) או, אם זה ה-iframe, האלמנט שבתוכו
        down(x, y, touch) { this.touch = !!touch; const top = document.elementFromPoint(x, y); this.frame = inFrame(x, y) && (!top || top.localName === 'iframe'); if (this.frame) { const [fx, fy] = fpt(x, y); this.t = c.doc.elementFromPoint(fx, fy); fire(this.t, 'pointerdown', x, y, fx, fy, touch); } else { this.t = document.elementFromPoint(x, y); fire(this.t, 'pointerdown', x, y, x, y, touch); } },
        move(x, y) { if (this.frame) { const [fx, fy] = fpt(x, y); fire(c.doc.elementFromPoint(fx, fy) || c.doc.documentElement, 'pointermove', x, y, fx, fy, this.touch); } else { const t = ed.build && ed.build.drag.active ? (document.elementFromPoint(x, y) || document.body) : this.t; fire(t.localName === 'iframe' ? document.body : t, 'pointermove', x, y, x, y, this.touch); } },
        up(x, y) { if (this.frame) { const [fx, fy] = fpt(x, y); fire(c.doc.elementFromPoint(fx, fy) || c.doc.documentElement, 'pointerup', x, y, fx, fy, this.touch); } else { const t = (ed.build && ed.build.drag.active ? document.elementFromPoint(x, y) : this.t) || document.body; fire(t.localName === 'iframe' ? document.body : t, 'pointerup', x, y, x, y, this.touch); } },
      };
    };
    const drag = async (page, a, b, opt = {}) => {
      await page.evaluate(PD);
      await page.evaluate(([x, y, t]) => window.__pd.down(x, y, t), [a.x, a.y, !!opt.touch]);
      if (opt.hold) await sleep(opt.hold);
      const steps = opt.steps || 12;
      for (let i = 1; i <= steps; i++) { await page.evaluate(([x, y]) => window.__pd.move(x, y), [a.x + (b.x - a.x) * i / steps, a.y + (b.y - a.y) * i / steps]); await sleep(24); }
      await sleep(80); await page.evaluate(([x, y]) => window.__pd.up(x, y), [b.x, b.y]); await sleep(600);
    };
    /** אצבע אמיתית (CDP Input.dispatchTouchEvent) — לידיות שבמעטפת, בטלפון. */
    const tdrag = async (page, a, b, steps = 12) => {
      const cdp = await page.context().newCDPSession(page);
      const T = (type, x, y) => cdp.send('Input.dispatchTouchEvent', { type, touchPoints: type === 'touchEnd' ? [] : [{ x, y, id: 1, radiusX: 4, radiusY: 4, force: 1 }] });
      await T('touchStart', a.x, a.y); await sleep(60);
      for (let i = 1; i <= steps; i++) { await T('touchMove', a.x + (b.x - a.x) * i / steps, a.y + (b.y - a.y) * i / steps); await sleep(24); }
      await sleep(80); await T('touchEnd'); await sleep(600); await cdp.detach();
    };
    /** ידית במעטפת (מעל הקנבס) — עכבר אמיתי. */
    const mdrag = async (page, a, b, steps = 12) => { await page.mouse.move(a.x, a.y); await page.mouse.down(); for (let i = 1; i <= steps; i++) { await page.mouse.move(a.x + (b.x - a.x) * i / steps, a.y + (b.y - a.y) * i / steps); await sleep(20); } await sleep(80); await page.mouse.up(); await sleep(600); };
    const tabP = async (page, id) => { if (!(await page.isVisible(`[data-ptab="${id}"]`))) await page.click('.ed-bar [data-panel="info"]'); await page.click(`[data-ptab="${id}"]`); await sleep(400); };
    const undo = async page => { await page.keyboard.press('Control+z'); await sleep(700); };
    const step = async (name, fn) => { console.log('\n── ' + name + ' ──'); try { await fn(); } catch (e) { failed++; ran++; console.log('❌ חריגה ב"' + name + '": ' + String(e.message || e).split('\n')[0].slice(0, 400)); } };

    const ctx = await mk(1920, 1080), page = await ctx.newPage();
    watch(page, '1920');

    // ═══ 0. ייבוא העמוד של היום ═══
    await step('0. ייבוא', async () => {
      await page.goto(base + '/#/project');
      await page.waitForSelector('.pj-tree .pj-row');
      await page.click('button:has-text("🔄 ייבוא עכשיו")');
      await page.waitForSelector('#sheet[open]', { timeout: 30000 });
      ok(/הפרויקט יובא מחדש/.test(await page.textContent('#sheet[open]')), '📁 פרויקט ← "🔄 ייבוא עכשיו": העמוד של היום יובא, עם "מה נלקח מאיפה"');
      await page.click('#sheet[open] [data-close]'); await sleep(500);
    });

    // ═══ 1. פתיחת העורך ═══
    await step('1. העורך על index.html', async () => {
      await page.click('nav a:has-text("🎨 עורך"), a:has-text("🎨 עורך")');
      await ready(page);
      ok(await page.inputValue('.ed-bar select').catch(() => null) === 'index.html' || (await page.textContent('.ed-bar')).includes('index.html'), 'הלשונית "🎨 עורך" נפתחת על index.html');
      ok(await page.evaluate(() => /קול מרוקו/.test(Editor._state.canvas.doc.querySelector('h1').textContent)), 'בקנבס — העמוד המיובא (הכותרת "קול מרוקו")');
      await click(page, 'h1');
      ok(/^h1/.test(await crumb(page) || ''), 'לחיצה על הכותרת בקנבס — בוחרת אותה', await crumb(page));
      await shot(page, 'e2e-01-editor-1920');
    });

    // ═══ 2. טקסט במקום ═══
    await step('2. עריכת טקסט בלחיצה כפולה', async () => {
      const p = await at(page, 'h1');
      await page.mouse.dblclick(p.x, p.y); await sleep(500);
      const editing = await page.evaluate(() => !!(Editor._state.text && Editor._state.text.active));
      ok(editing, 'לחיצה כפולה על הכותרת — עריכה במקום');
      await page.keyboard.press('Control+a'); await page.keyboard.type('קול מרוקו שלי');
      await page.keyboard.press('Escape'); await sleep(300);
      await click(page, '.kadisha-hero', 0, 0.03, 0.05); await sleep(500);
      ok(/<h1[^>]*>קול מרוקו שלי<\/h1>/.test(await html(page)), 'הכותרת החדשה — ב-index.html', (await html(page)).match(/<h1[^>]*>[^<]*<\/h1>/));
    });

    // ═══ 3. מרווחים בין הכותרות שעל התמונה ═══
    await step('3. ידיות מרווח (📏) בין הכותרות', async () => {
      await click(page, 'h1');
      await page.click('[data-space]'); await sleep(400);
      const g = await page.$$eval('.sp-gap', es => es.filter(e => e.getClientRects().length).map(e => { const r = e.getBoundingClientRect(); return { x: r.left + r.width / 2, y: r.top + r.height / 2, t: e.title }; }));
      ok(g.length >= 1 && /בין h1/.test(g[0].t), '📏 ← ידית "רווח" בין הכותרת לשורת המשנה', g.map(x => x.t.slice(0, 60)));
      const y0 = await page.evaluate(() => Editor._state.canvas.doc.querySelector('.kadisha-hero-subtitle-main').getBoundingClientRect().top);
      await mdrag(page, g[0], { x: g[0].x, y: g[0].y + 24 });
      const y1 = await page.evaluate(() => Editor._state.canvas.doc.querySelector('.kadisha-hero-subtitle-main').getBoundingClientRect().top);
      ok(y1 - y0 > 15, 'גרירת הידית 24px למטה — שורת המשנה זזה למטה בקנבס', { y0, y1 });
      ok(/kadisha-hero-subtitle-main[^}]*margin[^;]*:\s*2\d(\.\d+)?px/.test(await allCss(page)), 'הרווח נכתב ל-CSS (margin של שורת המשנה)');
      // הצמדה: רווח בין שתי שורות המשנה התחתונות — גרירה למעלה עד 0
      await click(page, '.kadisha-hero-subtitle', 0);
      const g2 = await page.$$eval('.sp-gap', es => es.filter(e => e.getClientRects().length).map(e => { const r = e.getBoundingClientRect(); return { x: r.left + r.width / 2, y: r.top + r.height / 2, t: e.title }; }));
      const gg = g2.find(x => /kadisha-hero-subtitle ל-p\.kadisha-hero-subtitle|p\.kadisha-hero-subtitle ל-p/.test(x.t)) || g2[g2.length - 1];
      ok(!!gg, 'ידית רווח בין שתי שורות המשנה', g2.map(x => x.t.slice(0, 80)));
      if (gg) {
        const a0 = await page.evaluate(() => { const e = Editor._state.canvas.doc.querySelectorAll('.kadisha-hero-subtitle'); return e[1].getBoundingClientRect().top - e[0].getBoundingClientRect().bottom; });
        await mdrag(page, gg, { x: gg.x, y: gg.y - 40 });
        const a1 = await page.evaluate(() => { const e = Editor._state.canvas.doc.querySelectorAll('.kadisha-hero-subtitle'); return e[1].getBoundingClientRect().top - e[0].getBoundingClientRect().bottom; });
        ok(a1 < a0 && a1 <= 1, 'גרירה למעלה — שתי שורות המשנה צמודות (אין רווח)', { before: a0, after: a1 });
      }
      await shot(page, 'e2e-03-spacing-1920');
      await page.click('[data-space]'); await sleep(300);
    });

    // ═══ 4. גרירת כותרות והוספת כותרת ═══
    await step('4. גרירת כותרת, הוספת כותרת', async () => {
      const o0 = await heroOrder(page);
      await click(page, 'h1');
      await drag(page, await at(page, 'h1'), await at(page, '.kadisha-hero-subtitle-main', 0, 0.5, 0.85));
      const o1 = await heroOrder(page);
      ok(o1[0] === 'p.kadisha-hero-subtitle-main' && o1[1] === 'h1.kadisha-hero-title', 'גרירת הכותרת אל מתחת לשורת המשנה — הסדר התחלף', o1);
      await undo(page);
      ok(JSON.stringify(await heroOrder(page)) === JSON.stringify(o0), 'Ctrl+Z — הסדר חזר');
      await page.click('[data-ntab="lib"]'); await sleep(300);
      const src = await box(page, '.ed-lib-item[data-lib="heading"]');
      const h0 = await html(page);
      await drag(page, src, await at(page, '.kadisha-hero-subtitle', 1, 0.5, 0.9), { shell: true }); await sleep(500);
      const h1 = await html(page), added = (h1.match(/<h2[^>]*>כותרת<\/h2>/g) || []).length - (h0.match(/<h2[^>]*>כותרת<\/h2>/g) || []).length;
      ok(added === 1, '"＋ ספרייה" ← "H כותרת" נגררה אל ה-hero — כותרת חדשה בעמוד');
      const parent = await page.evaluate(() => { const e = [...Editor._state.canvas.doc.querySelectorAll('.kadisha-hero h2')].pop(); return e ? e.parentElement.localName + '.' + e.parentElement.className : null; });
      if (parent && /^(p|span)\./.test(parent)) finding('🟡', 'כותרת שנגררת מהספרייה אל שורה בכותרת העמוד נכנסת **לתוך** ' + parent + ' (h2 בתוך ' + parent.split('.')[0] + ' — HTML לא תקין), ולא אחריה. צילום: e2e-04-heading-1920.png');
      ok(!!parent, 'הכותרת החדשה בתוך ה-hero', parent);
      await shot(page, 'e2e-04-heading-1920');
      // הכותרת החדשה נבחרת ונמחקת ב-🗑️
      await click(page, '.kadisha-hero h2');
      await page.click('.ed-bar button:has-text("🗑️")'); await sleep(600);
      ok(!/<h2[^>]*>כותרת<\/h2>/.test(await html(page)), '🗑️ — הכותרת שנוספה נמחקה');
    });

    // ═══ 5. תמונה ═══
    await step('5. תמונת הרקע ותמונה', async () => {
      await click(page, '.kadisha-hero', 0, 0.03, 0.05);
      ok(/section\.kadisha-hero/.test(await crumb(page) || ''), 'לחיצה על אזור ריק בכותרת העמוד — הרובריקה נבחרת', await crumb(page));
      ok(await page.isVisible('[data-bg-tools]'), 'בסרגל: "🖼 תמונת רקע: החלף · הסר · הזז"', await page.evaluate(() => ({ has: Editor._state.bg.hasImage(Editor._state.canvas.selected), bg: getComputedStyle(Editor._state.canvas.doc.querySelector('.kadisha-hero')).backgroundImage.slice(0, 120), cls: Editor._state.canvas.doc.querySelector('.kadisha-hero').className, css: [...Editor._state.sheets.values()].map(x => x.path + ':' + (x.doc.text().match(/[^\n]*hero\.jpg[^\n]*/) || [''])[0].slice(0, 160)).filter(x => /jpg/.test(x)) })));
      await tabP(page, 'content');
      const say = await page.textContent('.ct-bg').catch(() => '');
      if (/עדיין אינן על המסך/.test(say)) finding('🟡', 'לשונית "✏️ תוכן" של רובריקה עם תמונת רקע (ושל כל אלמנט שיושב עליה) אומרת "❌ החלפה והסרה של תמונת רקע עדיין אינן על המסך — הן מגיעות בשלב V5. עד אז: בקוד" — אבל הן כבר על המסך (הסרגל, לשונית עיצוב). בעל העמוד שמחפש שם יחשוב שאי אפשר. content.js:265');
      await page.click('[data-bg-tools] button:has-text("הסר")'); await sleep(700);
      ok(!/hero\.jpg/.test(await allCss(page)), '🗑 הסר — התמונה יצאה מה-CSS');
      ok(!(await page.isVisible('[data-bg-tools]')), 'אחרי ההסרה הכלים בסרגל נעלמים');
      await tabP(page, 'design');
      await page.click('[data-bg-add]'); await sleep(500);
      const [fc] = await Promise.all([page.waitForEvent('filechooser', { timeout: 8000 }), page.click('#sheet[open] [data-pick-upload]')]);
      await fc.setFiles(RED);
      await until(() => page.evaluate(() => !document.getElementById('sheet').open), 15000); await sleep(800);
      ok(/url\(['"]?\.\.\/media\/hero-red\.png/.test(await allCss(page)), '🎨 עיצוב ← "＋ תמונת רקע" ← ⬆️ העלאה מהמחשב — התמונה החדשה ברקע', (await allCss(page)).match(/background-image[^;]*/g));
      ok(await page.isVisible('[data-bg-tools]'), 'הכלים חזרו לסרגל');
      const stale = await page.isVisible('text=התמונה הוסרה. מה שנשאר');
      if (stale) finding('⚪', 'בלשונית עיצוב, אחרי הסרה והוספה של תמונת רקע, ההודעה "התמונה הוסרה. מה שנשאר:" עדיין מוצגת מעל התמונה החדשה. צילום: e2e-05-bg-1920.png');
      await shot(page, 'e2e-05-bg-1920');
      // אלמנט תמונה: ＋🖼️ תמונה אחרי הכותרת ← 🖼️ החלף ← 🗑️
      await click(page, 'h1');
      await page.click('[data-add-img]'); await sleep(500);
      await page.click('#sheet[open] [data-pick="media/hero-red.png"]'); await sleep(900);
      let H = await html(page);
      ok(/<img[^>]*src="media\/hero-red\.png"/.test(H), '"＋🖼️ תמונה" ← בחירה מהספרייה — <img> בעמוד', H.match(/<img[^>]*>/g));
      await click(page, '.kadisha-hero img');
      ok(await page.isVisible('[data-swap-img]'), 'תמונה נבחרת — "🖼️ החלף" בסרגל');
      await page.click('[data-swap-img]'); await sleep(500);
      const [fc2] = await Promise.all([page.waitForEvent('filechooser', { timeout: 8000 }), page.click('#sheet[open] [data-pick-upload]')]);
      await fc2.setFiles(GREEN);
      await until(() => page.evaluate(() => !document.getElementById('sheet').open), 15000); await sleep(800);
      H = await html(page);
      ok(/<img[^>]*src="media\/photo-green\.png"/.test(H) && !/<img[^>]*hero-red/.test(H), '"🖼️ החלף" ← העלאה — התמונה הוחלפה', H.match(/<img[^>]*>/g));
      await shot(page, 'e2e-05-img-1920');
      await click(page, '.kadisha-hero img');
      await page.click('.ed-bar button:has-text("🗑️")'); await sleep(600);
      ok(!/<img[^>]*photo-green/.test(await html(page)), '🗑️ — התמונה הוסרה מהעמוד');
    });

    // ═══ 6. מיקום חופשי ═══
    await step('6. מיקום חופשי', async () => {
      await click(page, '.kadisha-lex-hero-actions', 0, 0.02, 0.5);
      const sel = await crumb(page);
      ok(/kadisha-lex-hero-(actions|btn)/.test(sel || ''), 'כפתורי הפתיחה נבחרו', sel);
      await page.click('[data-free-btn]'); await sleep(800);
      ok(await page.getAttribute('[data-free-btn]', 'aria-pressed') === 'true', '"✥ מיקום חופשי" — פעיל');
      const r0 = await page.evaluate(() => { const e = Editor._state.canvas.selected; return Editor._state.canvas.elOf ? null : null; });
      const s0 = await page.evaluate(() => { const c = Editor._state.canvas, e = c.doc.querySelector('.kadisha-lex-hero-actions'); const r = e.getBoundingClientRect(); return { x: r.left, y: r.top }; });
      const from = await at(page, '.kadisha-lex-hero-actions', 0, 0.5, 0.5);
      await drag(page, from, { x: from.x - 200, y: from.y - 60 });
      const s1 = await page.evaluate(() => { const c = Editor._state.canvas, e = c.doc.querySelector('.kadisha-lex-hero-actions'); const r = e.getBoundingClientRect(); return { x: r.left, y: r.top }; });
      ok(Math.abs((s1.x - s0.x) + 200) < 30 && Math.abs((s1.y - s0.y) + 60) < 30, 'גרירה על הקנבס — הכפתורים זזו 200 שמאלה ו-60 למעלה', { s0, s1 });
      ok(/position:\s*absolute/.test(await allCss(page)), 'בקובץ: position: absolute + מיקום');
      await shot(page, 'e2e-06-free-1920');
      void r0;
    });

    // ═══ 7. צבע וגופן ═══
    await step('7. צבע וגופן', async () => {
      await click(page, 'h1');
      await tabP(page, 'design');
      const colorIn = await page.$('button[data-color="color"] ~ input[type="text"], [data-color="color"] + input');
      ok(!!colorIn, 'בלשונית "🎨 עיצוב" — "צבע טקסט"');
      await colorIn.click({ clickCount: 3 }); await colorIn.type('#c0392b'); await colorIn.press('Enter'); await sleep(700);
      ok(await cs(page, 'h1', 'color') === 'rgb(192, 57, 43)', 'הכותרת אדומה בקנבס', await cs(page, 'h1', 'color'));
      ok(/color:\s*#c0392b/i.test(await allCss(page)), 'הצבע נכתב ל-CSS');
      const fontIn = await page.$('[data-font-add-here]') ? await page.evaluateHandle(() => document.querySelector('[data-font-add-here]').closest('.sg-row, .sg-line, div').querySelector('input[type="text"]')) : null;
      ok(!!fontIn, 'שדה "גופן"');
      if (fontIn) {
        await fontIn.click({ clickCount: 3 }); await fontIn.type('Georgia, serif'); await fontIn.press('Enter'); await sleep(700);
        ok(/Georgia/.test(await cs(page, 'h1', 'fontFamily')), 'הגופן של הכותרת — Georgia', await cs(page, 'h1', 'fontFamily'));
      }
      await shot(page, 'e2e-07-design-1920');
    });

    // ═══ 8. כרטיס מילה ═══
    await step('8. כרטיס מילה: התבנית / המילה הזו', async () => {
      const W = '[data-kol-canvas="card"] .kadisha-word-title, [data-kol-canvas="card"] h3';
      await click(page, W, 0);
      ok(await page.isVisible('[data-lv-bar]'), 'לחיצה על מילה בכרטיס — הסרגל "התבנית / המילה הזו"');
      ok(await page.getAttribute('[data-lv-scope="tpl"]', 'aria-pressed') === 'true', 'ברירת המחדל — "התבנית — חל על כל המילים"');
      await tabP(page, 'design');
      const ci = await page.$('[data-color="color"] + input');
      await ci.click({ clickCount: 3 }); await ci.type('#1e6b3a'); await ci.press('Enter'); await sleep(900);
      const cols = await page.evaluate(s => [...Editor._state.canvas.doc.querySelectorAll(s)].slice(0, 6).map(e => getComputedStyle(e).color), W);
      ok(cols.length >= 3 && cols.every(c => c === 'rgb(30, 107, 58)'), 'צבע בתבנית — כל הכרטיסים ירוקים', cols);
      await click(page, W, 1);
      await page.click('[data-lv-scope="word"]'); await sleep(400);
      if (!(await page.isVisible('[data-lv-edit]'))) { await click(page, W, 1); await sleep(300); }
      const wordTxt = await page.evaluate(s => Editor._state.canvas.doc.querySelectorAll(s)[1].textContent.trim(), W);
      ok(await page.isVisible('[data-lv-edit]'), '"המילה הזו: ' + wordTxt + '" ← "✏️ ערוך את ה…"', await page.textContent('[data-lv-bar]').catch(() => null));
      await page.click('[data-lv-edit]'); await sleep(400);
      await page.keyboard.press('Control+a'); await page.keyboard.type('מילה חדשה'); await page.keyboard.press('Enter'); await sleep(1500);
      const ws = await call(page, 'words');
      ok(ws.words.some(w => w.word === 'מילה חדשה'), 'הערך נשמר בטבלת המילים (📚) — לא בעמוד');
      ok(!/מילה חדשה/.test(await html(page)), 'index.html לא השתנה');
      await shot(page, 'e2e-08-card-1920');
    });

    // ═══ 9. רכיב ═══
    await step('9. רכיב: שמור, גרור, שני מופעים', async () => {
      await click(page, '.kadisha-lexicon-controls', 0, 0.01, 0.98);
      let sel = await crumb(page);
      // הלחיצה נופלת על ילד — בעל העמוד עולה דרך שביל הפירורים שמעל הקנבס
      if (!/kadisha-lexicon-controls/.test(sel || '')) { await page.locator('.ed-crumb', { hasText: 'kadisha-lexicon-controls' }).first().click().catch(e => console.log('   (שביל: ' + e.message.split('\n')[0] + ')')); await sleep(400); sel = await crumb(page); }
      ok(/kadisha-lexicon-controls/.test(sel || ''), 'שורת החיפוש והנושאים נבחרה', sel);
      await tabP(page, 'content');
      await page.click('[data-w="comp-save"]'); await sleep(400);
      await page.fill('[data-cs="label"]', 'שורת חיפוש'); await page.fill('[data-cs="name"]', 'search-bar');
      await page.click('[data-cs-go]');
      await until(() => page.evaluate(() => !document.getElementById('sheet').open), 15000); await sleep(1200);
      ok(/data-kol-component="search-bar"/.test(await html(page)), '"🧩 שמור כרכיב" — המופע הראשון בעמוד');
      await page.click('[data-ntab="comp"]'); await sleep(500);
      const src = await box(page, '.ed-lib-item[data-lib="comp-search-bar"]');
      ok(!!src, 'לשונית "🧩 רכיבים" — הרכיב ברשימה');
      await drag(page, src, await at(page, '.kadisha-hero-subtitle', 1, 0.5, 0.9), { shell: true }); await sleep(900);
      const n = ((await html(page)).match(/data-kol-component="search-bar"/g) || []).length;
      ok(n === 2, 'גרירה לקנבס — שני מופעים בעמוד', n);
      await shot(page, 'e2e-09-component-1920');
      await undo(page);
      ok(((await html(page)).match(/data-kol-component="search-bar"/g) || []).length === 1, 'Ctrl+Z — המופע השני הוסר');
    });

    // ═══ 10. קוד ═══
    await step('10. עריכה בקוד וסנכרון', async () => {
      await saved(page);
      await click(page, '.kadisha-hero-subtitle', 1);
      await page.click('[data-split]'); await sleep(1500);
      const mon = await page.$('.monaco-editor');
      ok(!!mon, '◫ מבט מפוצל — עורך הקוד לצד הקנבס');
      // הקלדה בקוד: לחיצה על השורה של "אסף ותיעד", End, ולפני </p>
      await page.click('.monaco-editor .view-line:has-text("אסף ותיעד")'); await sleep(200);
      await page.keyboard.press('End'); await sleep(100);
      for (let i = 0; i < 4; i++) await page.keyboard.press('ArrowLeft');
      await page.keyboard.type(' ומשפחתו'); await sleep(1500);
      const t = await page.evaluate(() => Editor._state.canvas.doc.querySelectorAll('.kadisha-hero-subtitle')[1].textContent);
      ok(/העמוד ומשפחתו$/.test(t.trim()), 'הקלדה בקוד — הקנבס התעדכן בלי שמירה ידנית', t);
      await shot(page, 'e2e-10-split-1920');
      await page.click('[data-split]'); await sleep(600);
      // ובכיוון ההפוך: שינוי בגרירה ← הקוד
      await page.click('.ed-bar [data-code], .ed-bar button:has-text("פתח בקוד")'); await sleep(1500);
      ok(/#\/(code|project)|code/.test(page.url()) || await page.isVisible('.monaco-editor'), '"</> פתח בקוד" — הקובץ פתוח בעורך הקוד');
      const codeText = await page.evaluate(() => { const m = window.monaco && monaco.editor.getModels().find(m => /ומשפחתו/.test(m.getValue())); return m ? m.getValue() : null; });
      ok(!!codeText && /קול מרוקו שלי/.test(codeText), 'בעורך הקוד — גם הכותרת כפי שנערכה בקנבס');
      await page.click('a:has-text("🎨 עורך")'); await ready(page);
    });

    // ═══ 11. בנייה ופרסום — עד התור ═══
    await step('11. בנייה ופרסום עד התור', async () => {
      await saved(page);
      await page.click('a:has-text("🚀 פרסום")');
      await page.waitForSelector('.pk .btn.primary', { timeout: 20000 });
      await page.click('.pk .btn.primary');
      ok(await until(() => page.$('#sheet[open] .pk-sheet .chip.ok'), 40000), '"🏗️ בנה" — נבנתה גרסה');
      const b1 = (await call(page, 'builds')).builds[0];
      const g = await call(page, 'build_get', undefined, '&id=' + b1.id);
      ok(g.files.some(f => f.path === 'index.html'), 'בגרסה — index.html');
      const go = await until(() => page.$('#sheet .pk-send button:not([disabled])'), 15000);
      ok(go && /לתור/.test(await go.textContent()), 'הכפתור — "לתור"', go ? await go.textContent() : '');
      await shot(page, 'e2e-11-publish-1920');
      if (go) await go.click();
      ok(await until(async () => (await call(page, 'build_get', undefined, '&id=' + b1.id)).build.status === 'sending', 15000), 'הגרסה בתור ("⏳ ממתין למשיכה") — לא "באתר"');
    });

    // ═══ 12. 390 — מגע ═══
    await step('12. 390 במגע', async () => {
      const c2 = await mk(390, 844), pp = await c2.newPage();
      watch(pp, '390');
      await pp.goto(base + '/editor.php#/editor/index.html');
      await ready(pp);
      await sleep(800);
      const scrollX = await pp.evaluate(() => document.documentElement.scrollWidth - innerWidth);
      ok(scrollX <= 1, '390: בלי גלילה אופקית', scrollX);
      await tap(pp, 'h1');
      ok(/^h1/.test(await crumb(pp) || ''), '390: הקשה על הכותרת — בוחרת אותה', await crumb(pp));
      await shot(pp, 'e2e-12-select-390');
      // ידית מרווח במגע
      await pp.click('[data-space]').catch(() => null); await sleep(500);
      const g = await pp.$$eval('.sp-gap', es => es.filter(e => e.getClientRects().length).map(e => { const r = e.getBoundingClientRect(); return { x: r.left + r.width / 2, y: r.top + r.height / 2, w: r.width, h: r.height }; }));
      ok(g.length >= 1, '390: 📏 — ידית הרווח מופיעה', g);
      if (g.length) {
        if (Math.min(g[0].w, g[0].h) < 24) finding('🟡', '390: ידית הרווח בין הכותרות קטנה לאצבע (' + Math.round(g[0].w) + '×' + Math.round(g[0].h) + 'px). צילום: e2e-12-spacing-390.png');
        const y0 = await pp.evaluate(() => Editor._state.canvas.doc.querySelector('.kadisha-hero-subtitle-main').getBoundingClientRect().top);
        await tdrag(pp, g[0], { x: g[0].x, y: g[0].y + 20 });
        const y1 = await pp.evaluate(() => Editor._state.canvas.doc.querySelector('.kadisha-hero-subtitle-main').getBoundingClientRect().top);
        ok(y1 - y0 > 8, '390: גרירת הידית באצבע — שורת המשנה זזה', { y0, y1 });
      }
      await shot(pp, 'e2e-12-spacing-390');
      await pp.click('[data-space]').catch(() => null); await sleep(300);
      // צבע מהפאנל
      await tabP(pp, 'design').catch(() => null);
      const ci = await pp.$('[data-color="color"] + input');
      ok(!!ci && await ci.isVisible(), '390: "צבע טקסט" בפאנל');
      if (ci) { await ci.tap().catch(() => ci.click()); await ci.fill('#2244aa'); await ci.press('Enter'); await sleep(700); ok(await cs(pp, 'h1', 'color') === 'rgb(34, 68, 170)', '390: הכותרת כחולה', await cs(pp, 'h1', 'color')); }
      await shot(pp, 'e2e-12-design-390');
      await c2.close();
    });

    console.log('\nצילומים: ' + shotDir);
    ok(!errors.length, 'אין שגיאות JavaScript בדף', errors.slice(0, 5));
  } catch (e) { failed++; console.log('❌ חריגה: ' + (e.stack || e.message)); }
  finally { await browser?.close(); php.kill(); wpPhp.kill(); }
  if (findings.length) { console.log('\n══ ממצאים (בעל העמוד אינו יכול לשנות / קשה לו) ══'); for (const f of findings) console.log(' ' + f); }
  console.log('\n' + (failed ? '❌ נכשלו ' + failed + ' מתוך ' + ran : '✅ כל ' + ran + ' הבדיקות עברו'));
  process.exit(failed ? 1 : 0);
})();
