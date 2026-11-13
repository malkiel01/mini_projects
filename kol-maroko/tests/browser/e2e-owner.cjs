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
 * 11. ⚙️ שורת חיבור ← 🚀 בנייה ← "לתור" ← "🔄 בדוק עכשיו" ← האתר מושך ← "✓ הוחל באתר" (tests/fake-wp.php, בלי אתר אמיתי).
 * 13. 🗂️ קבצים ← 🎙️ הקלטות: כל ההקלטות, ▶ השמעה, 🔁 החלפה מהמכשיר + 🕘 גרסאות וחזרה, מחיקה לסל ו-♻️ שחזור, ➕ הוספה.
 * 14. 📚 נתונים: "+ מילה" (לטבלה), 🧩 "+ שדה" (ובספרייה של העורך), "➕ רשימה חדשה" (קובץ ב-data/).
 * 15. 👥 אנשים: "+ הוסף עוזר" עם תפקיד.
 * 16. ↩️ שחזור: 📌 נקודה ← מחיקת מילה ← "↩️ שחזור לנקודה הזו".
 * 17. 🐞 בעורך: הצבעה על אלמנט ← "פתיחת מטלה" ← DevLoop.
 * 18. V13: לחיצה ימנית ← העתק / הדבק סגנון, 🕘 היסטוריה (חזרה לצעד), Ctrl+E, 📄 שמור כתבנית ← גרירה לקנבס, 🗜 ZIP.
 * 19. 🛠️ מפתח: 📋 מטלות (הדיווח), 📜 יומן, 👣 פעולות (של התרחיש), 🩺 תקינות (כל ❌ עם 🔧).
 * 20. 390: 📁 עץ הקבצים ← css/page.css ← הקלדה ← 💾 שמירה.
 * 21. 390 במגע (V14): גיליון תחתון וידית הגובה, האלמנט גלוי מעל הגיליון, ⬆⬇, לחיצה ארוכה ← תפריט, צביטה ← "⤢ התאם", 🔎.
 * 22. V15: ⚙️ כותרת / תיאור / תמונת שיתוף (העלאה), 📄 שמור את העמוד כתבנית ← ➕ עמוד "מתבנית עמוד", ⭐ ראשי וחזרה, 🚫 404.
 * 23. V16a: 🧩 רכיבים מוכנים — חיפוש, לחיצה ← אחרי הנבחר, הגדרה בפאנל, ➕ פריט, גרירה מהלוח הרחב, Ctrl+Z; 390 — הקשה בגיליון ← אחרי הנבחר.
 * 12. 390 במגע: הקשה בוחרת, ידית המרווח במגע, צבע מהפאנל.   (רץ אחרון)
 * E2E_ONLY=13,14 — רק הצעדים האלה (וצעד 0); E2E_TRACE=1 — מצב הרובריקה העליונה אחרי כל צעד.
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
  // WAV — צליל קצר (ההקלטה "מהמכשיר" של בעל העמוד), ושתי הקלטות שכבר בכלי (כמו ב-m1); השאר — "באתר"
  const wav = (n, f) => { const pcm = Buffer.alloc(n * 2); for (let i = 0; i < n; i++) pcm.writeInt16LE(Math.round(f ? 8000 * Math.sin(i / f) : 0), i * 2); const hd = Buffer.alloc(44); hd.write('RIFF', 0); hd.writeUInt32LE(36 + pcm.length, 4); hd.write('WAVEfmt ', 8); hd.writeUInt32LE(16, 16); hd.writeUInt16LE(1, 20); hd.writeUInt16LE(1, 22); hd.writeUInt32LE(16000, 24); hd.writeUInt32LE(32000, 28); hd.writeUInt16LE(2, 32); hd.writeUInt16LE(16, 34); hd.write('data', 36); hd.writeUInt32LE(pcm.length, 40); return Buffer.concat([hd, pcm]); };
  const TONE = path.join(data, 'my-recording.wav'), TONE2 = path.join(data, 'new-word.wav');
  fs.writeFileSync(TONE, wav(8000, 5)); fs.writeFileSync(TONE2, wav(6000, 9)); fs.writeFileSync(path.join(data, 'rec.wav'), wav(3200, 0));
  execFileSync('php', ['-r', `define('KOL_DATA_DIR', $argv[1]); foreach (['members', 'import', 'ai', 'agent', 'dev', 'preview', 'migrate', 'build'] as $l) require_once $argv[2] . "/lib/$l.php";
    foreach ([1, 2] as $w) { $t = KOL_DATA_DIR . "/rec$w.wav"; copy(KOL_DATA_DIR . '/rec.wav', $t);
      $id = file_store($t, "rec$w.wav", ['sub' => 'u-owner', 'name' => 'Owner Test'], 'audio/wav');
      db()->prepare('UPDATE words SET audio = ? WHERE id = ?')->execute([$id, $w]); }`, data, ROOT]);
  const [port, wpPort] = [await freePort(), await freePort()];
  const base = `http://127.0.0.1:${port}`, wp = `http://127.0.0.1:${wpPort}`;
  // בעל האתר מחבר את האתר (ערוץ משיכה, M7) — לא חלק מהתרחיש של בעל העמוד. רק לפני הפרסום: site_url משנה את מקור הייבוא
  // (התמונה של ה-hero נלקחת מהאתר), ולכן בייבוא — האתר של ברירת המחדל, כמו אצל הבעלים.
  const connectSite = () => execFileSync('php', ['-r', `define('KOL_DATA_DIR', $argv[1]); foreach (['members', 'import', 'ai', 'agent', 'dev', 'preview', 'migrate', 'build'] as $l) require_once $argv[2] . "/lib/$l.php";
    setting_set('site_url', $argv[3]); setting_set('pub_channel', 'pull'); setting_set('pull_url', $argv[4]);
    file_put_contents($argv[5] . '/options.json', json_encode(['kol_maroko_keys' => pub_key_line(pub_key())]));`, data, ROOT, wp, base + '/pull.php', wpDir]);
  const env = { ...process.env, PHP_CLI_SERVER_WORKERS: '6' };
  const php = spawn('php', ['-S', `127.0.0.1:${port}`, path.join(ROOT, 'tests', 'router.php')], { cwd: ROOT, env: { ...env, KOL_TEST_DATA: data }, stdio: 'ignore' });
  const wpPhp = spawn('php', ['-S', `127.0.0.1:${wpPort}`, path.join(ROOT, 'tests', 'fake-wp.php')], { cwd: ROOT, env: { ...env, KOL_TEST_WP: wpDir }, stdio: 'ignore' });
  // "בוורדפרס": הדבקת שורת החיבור בתוסף, ו"🔄 עדכן עכשיו" (wp-cron) — הצד של האתר, לא של הכלי (כמו ב-m7)
  const wpOpt = (k, v) => { const f = path.join(wpDir, 'options.json'); const o = JSON.parse(fs.readFileSync(f, 'utf8')); if (v === undefined) delete o[k]; else o[k] = v; fs.writeFileSync(f, JSON.stringify(o)); };
  const wpGet = k => JSON.parse(fs.readFileSync(path.join(wpDir, 'options.json'), 'utf8'))[k];
  const sitePull = async () => (await fetch(wp + '/__pull')).json();
  let browser;
  const errors = [];
  try {
    for (let i = 0; i < 100; i++) { try { await fetch(base + '/api.php?action=embed_key'); await fetch(wp + '/'); break; } catch { await sleep(50); } }
    browser = await chromium.launch();
    const mk = (width, height) => browser.newContext({ viewport: { width, height }, hasTouch: width < 600, isMobile: width < 600, extraHTTPHeaders: { 'X-Test-User': JSON.stringify(OWNER) } });
    // רק מתעד: כל קריאה ל-play() של רכיב שמע (גם new Audio() שאינו ב-DOM)
    const PLAYS = () => { const o = HTMLMediaElement.prototype.play; HTMLMediaElement.prototype.play = function () { (window.__plays = window.__plays || []).push(this.currentSrc || this.src); return o.apply(this, arguments); }; };
    const watch = (page, tag) => { page.addInitScript(PLAYS); page.on('pageerror', e => { errors.push(tag + ': ' + e.message); console.log('   ⚠️ שגיאה בדף (' + tag + '): ' + e.message); }); page.on('dialog', d => d.accept()); };

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
    // E2E_ONLY=13,14 — רק הצעדים האלה (וצעד 0, הייבוא), לפיתוח הבדיקה
    const ONLY = process.env.E2E_ONLY ? process.env.E2E_ONLY.split(',').map(x => x.trim()) : null;
    /** גיליון / חלון שנשאר פתוח מהצעד הקודם — סוגרים ב-✕, כמו אדם, לפני שעוברים מסך. */
    const closeAll = async pg => { for (let k = 0; k < 4; k++) { const x = await pg.$('#sheet[open] [data-close], dialog[open] [data-close]'); if (!x) break; await x.click().catch(() => {}); await sleep(300); } };
    const step = async (name, fn) => { if (ONLY && !name.startsWith('0.') && !ONLY.includes(name.split('.')[0])) return; await closeAll(page).catch(() => {}); if (ONLY && !name.startsWith('0.') && !ONLY.includes(name.split('.')[0])) return; console.log('\n── ' + name + ' ──'); try { await fn(); } catch (e) { failed++; ran++; console.log('❌ חריגה ב"' + name + '": ' + String(e.message || e).split('\n')[0].slice(0, 400)); }
      if (process.env.E2E_TRACE) console.log('   [trace] ' + JSON.stringify(await page.evaluate(() => { try { const S = Editor._state, e = S.canvas.doc.querySelector('.kadisha-hero'); return { cls: e && e.className, jpg: [...S.sheets.values()].some(x => /hero\.jpg/.test(x.doc.text())), html: (S.doc.text().match(/<section[^>]*kadisha-hero[^>]*>/) || [''])[0] }; } catch (x) { return String(x); } }))); };

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
      // אל השורה "LA VOIX DU MAROC / THE VOICE OF MOROCCO" — בעין, עוד כותרת על התמונה
      await drag(page, src, await at(page, '.kadisha-hero-eyebrow', 0, 0.5, 0.9)); await sleep(500);
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
      // התמונה שנוספה כבר נבחרת (לחיצה נוספת עליה = "בחר הורה" — כך בנוי העורך)
      ok(/^img/.test(await crumb(page) || ''), 'התמונה שנוספה — נבחרת', await crumb(page));
      ok(await page.isVisible('[data-swap-img]'), 'תמונה נבחרת — "🖼️ החלף" בסרגל', { sel: await crumb(page), imgs: await page.evaluate(() => [...Editor._state.canvas.doc.querySelectorAll('.kadisha-hero img')].map(e => e.outerHTML.slice(0, 100) + ' ' + JSON.stringify(e.getBoundingClientRect()))) });
      await shot(page, 'e2e-05-img-select-1920');
      await page.click('[data-swap-img]'); await sleep(500);
      const [fc2] = await Promise.all([page.waitForEvent('filechooser', { timeout: 8000 }), page.click('#sheet[open] [data-pick-upload]')]);
      await fc2.setFiles(GREEN);
      await until(() => page.evaluate(() => !document.getElementById('sheet').open), 15000); await sleep(800);
      H = await html(page);
      ok(/<img[^>]*src="media\/photo-green\.png"/.test(H) && !/<img[^>]*hero-red/.test(H), '"🖼️ החלף" ← העלאה — התמונה הוחלפה', H.match(/<img[^>]*>/g));
      await shot(page, 'e2e-05-img-1920');
      if (!/^img/.test(await crumb(page) || '')) { await click(page, 'h1'); await click(page, '.kadisha-hero img'); }
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
      await click(page, '.kadisha-lexicon-controls input[type="search"], .kadisha-lexicon-controls input', 0);
      let sel = await crumb(page);
      // הלחיצה נופלת על ילד — בעל העמוד עולה ב"↑ בחר הורה" שליד שביל הפירורים (השביל מקצר שמות ארוכים ל-…)
      for (let k = 0; k < 4 && !/kadisha-lexicon-controls|kadisha-lex-panel-search/.test(sel || ''); k++) { await page.click('.ed-seltools-host [aria-label="בחר הורה"], .ed-seltools-host [title="בחר הורה"]'); await sleep(300); sel = await crumb(page); }
      ok(/kadisha-lexicon-controls|kadisha-lex-panel-search/.test(sel || ''), 'שורת החיפוש והנושאים נבחרה', sel);
      await tabP(page, 'content');
      await page.click('[data-w="comp-save"]'); await sleep(400);
      await page.fill('[data-cs="label"]', 'שורת חיפוש'); await page.fill('[data-cs="name"]', 'search-bar');
      await page.click('[data-cs-go]');
      await until(() => page.evaluate(() => !document.getElementById('sheet').open), 15000); await sleep(1200);
      ok(/data-kol-component="search-bar"/.test(await html(page)), '"🧩 שמור כרכיב" — המופע הראשון בעמוד');
      await page.click('[data-ntab="comp"]'); await sleep(500);
      const src = await box(page, '.ed-lib-item[data-lib="comp-search-bar"]');
      ok(!!src, 'לשונית "🧩 רכיבים" — הרכיב ברשימה');
      await drag(page, src, await at(page, '.kadisha-hero-subtitle', 1, 0.5, 0.9)); await sleep(900);
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
      await page.click('.monaco-editor .view-lines'); await sleep(200);
      await page.keyboard.press('Control+f'); await sleep(300); await page.keyboard.type('אסף ותיעד'); await page.keyboard.press('Enter'); await page.keyboard.press('Escape'); await sleep(200);
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
    await step('11. בנייה ופרסום עד "הוחל באתר"', async () => {
      connectSite();
      await saved(page);
      // ⚙️ בעל האתר ← "📋 יצירת שורת חיבור" ← מודבקת בתוסף בוורדפרס
      await page.click('a:has-text("⚙️ בעל האתר")');
      await page.waitForSelector('.ob-channel', { timeout: 20000 });
      await page.click('.ob-channel button:has-text("שורת חיבור")');
      const line = (await (await page.waitForSelector('.ob-line')).textContent()).trim();
      const m = line.match(/^kol-maroko:2:([^:]+):([^:]+):(.+):([A-Za-z0-9_-]{43})$/);
      ok(!!m, '⚙️ בעל האתר ← "📋 שורת חיבור" — השורה מוצגת', line.slice(0, 40));
      if (m) { wpOpt('kol_maroko_pull_url', m[3]); wpOpt('kol_maroko_pull_token', m[4]); }
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
      await closeAll(page);
      // "🔄 בדוק עכשיו" ← האתר מושך ← "✓ הוחל באתר"
      await page.click('.ob-pulse button:has-text("בדוק עכשיו")'); await sleep(800);
      const run = await sitePull();
      ok(run.ok && run.applied >= 1, 'התוסף (fake-wp) משך והחיל', JSON.stringify(run).slice(0, 160));
      const applied = await until(() => page.$('.ob-row.ob-applied'), 20000);
      ok(applied && /הוחל באתר/.test(await applied.textContent()), '"✓ הוחל באתר" — בלי רענון');
      ok((await call(page, 'build_get', undefined, '&id=' + b1.id)).build.status === 'live', 'הגרסה "באתר"');
      // מה שבאוויר אצל האתר — העמוד עם השינויים של בעל העמוד
      let liveHtml = '';
      try { const man = JSON.parse(fs.readFileSync(path.join(wpDir, 'uploads', 'kol-maroko', 'v2', 'live.json'), 'utf8')); liveHtml = fs.readFileSync(path.join(wpDir, 'uploads', 'kol-maroko', 'v2', 'blobs', man.files[man.entry || 'index.html'].sha256), 'utf8'); } catch (e) { liveHtml = 'ERR ' + e.message; }
      ok(/קול מרוקו שלי/.test(liveHtml) && /ומשפחתו/.test(liveHtml), 'באתר: index.html עם הכותרת והטקסט שבעל העמוד שינה', liveHtml.slice(0, 120));
      await shot(page, 'e2e-11-applied-1920');
    });


    // ═══ 13. קבצי השמע ═══
    const libRow = (pg, n) => pg.locator('#main .lib-row').filter({ hasText: new RegExp('#' + n + '(?!\\d)') }).first();
    const rowTxt = async (pg, n) => (await libRow(pg, n).textContent()).replace(/\s+/g, ' ');
    await step('13. הקלטות: לראות, לשמוע, להחליף, גרסאות, להוסיף, למחוק ולשחזר', async () => {
      await page.click('a:has-text("🗂️ קבצים")');
      await page.waitForSelector('#main .lib-row', { timeout: 20000 });
      const head = (await page.textContent('#main')).replace(/\s+/g, ' ');
      ok(/1,494 הקלטות/.test(head) && /באתר/.test(head) && /בכלי/.test(head), '🗂️ קבצים ← 🎙️ הקלטות: כל ההקלטות (1,494) — "באתר" ו"בכלי"', head.slice(0, 160));
      // לשמוע
      await libRow(page, 1).locator('button:has-text("▶")').first().click(); await sleep(1200);
      const playing = await page.evaluate(() => (window.__plays || []).length > 0);
      ok(playing, '▶ — ההקלטה מתנגנת', await page.evaluate(() => window.__plays || []));
      await libRow(page, 1).locator('button:has-text("▶"), button:has-text("⏸")').first().click().catch(() => {}); await sleep(300);
      // להחליף הקלטה שבאתר
      ok(/באתר/.test(await rowTxt(page, 3)), '#3 — "באתר" לפני ההחלפה');
      await libRow(page, 3).locator('button:has-text("🔁")').click(); await sleep(500);
      const [fc] = await Promise.all([page.waitForEvent('filechooser', { timeout: 8000 }), page.click('dialog[open] :text("העלאה מהמכשיר")')]);
      await fc.setFiles(TONE); await sleep(2500);
      ok(/my-recording\.wav/.test(await rowTxt(page, 3)) && /גרסה קודמת/.test(await rowTxt(page, 3)), '🔁 ← "העלאה מהמכשיר" — ההקלטה הוחלפה, והקודמת נשמרה כגרסה', (await rowTxt(page, 3)).slice(0, 160));
      // גרסאות ← חזרה
      await libRow(page, 3).locator('button:has-text("⋯")').click(); await sleep(400);
      await page.click('dialog[open] button:has-text("🕘 גרסאות")'); await sleep(900);
      ok(/ההקלטה שבאתר/.test(await page.textContent('dialog[open]')), '🕘 גרסאות — ההקלטה שבאתר שמורה');
      await page.click('dialog[open] button:has-text("↩️ חזור לגרסה הזו")'); await sleep(1500);
      if (await page.isVisible('dialog[open]')) await page.click('dialog[open] [data-close]').catch(() => {});
      await sleep(500);
      ok(/באתר/.test(await rowTxt(page, 3)), '"↩️ חזור לגרסה הזו" — #3 שוב ההקלטה שבאתר', (await rowTxt(page, 3)).slice(0, 160));
      await shot(page, 'e2e-13-audio-1920');
      // למחוק לסל ← לשחזר
      await libRow(page, 1).locator('button:has-text("⋯")').click(); await sleep(400);
      await page.click('dialog[open] button:has-text("🗑️ מחיקה לסל")'); await sleep(500);
      await page.click('dialog[open] button:has-text("מחיקה לסל")'); await sleep(1500);
      const w1 = (await call(page, 'words')).words.find(w => w.id === 1);
      ok(w1 && !w1.audio, '🗑️ מחיקה לסל — המילה #1 "בלי הקלטה"', w1 && w1.audio);
      await page.locator('#main button', { hasText: 'בסל' }).first().click(); await sleep(1200);
      const rst = page.locator('#main button:has-text("♻️ שחזור")').first();
      ok(await rst.isVisible().catch(() => false), '"🗑️ בסל" — ההקלטה שנמחקה, עם "שחזור"');
      await rst.click(); await sleep(1500);
      const w1b = (await call(page, 'words')).words.find(w => w.id === 1);
      ok(w1b && w1b.audio, '↩️ שחזור — ההקלטה חזרה, מחוברת למילה', w1b && w1b.audio);
      // להוסיף
      await page.click('#main button:has-text("🎙️ הקלטות")').catch(() => {}); await sleep(600);
      const [fc2] = await Promise.all([page.waitForEvent('filechooser', { timeout: 8000 }), page.click('button:has-text("➕ הוספת הקלטות")')]);
      await fc2.setFiles(TONE2); await sleep(2500);
      const after = (await page.textContent('#main')).replace(/\s+/g, ' ');
      ok(/new-word/.test(after) || /לא מחוברת|לא בשימוש/.test(after), '➕ הוספת הקלטות — ההקלטה החדשה בספרייה', after.slice(0, 200));
      await shot(page, 'e2e-13-audio-add-1920');
    });

    // ═══ 14. נתונים: מילה חדשה, ובעמוד ═══
    await step('14. 📚 נתונים: מילה, שדה ורשימה חדשים', async () => {
      await page.click('a:has-text("📚 נתונים")');
      await page.waitForSelector('#main button:has-text("+ מילה")', { timeout: 20000 });
      await page.click('#main button:has-text("+ מילה")'); await sleep(600);
      const dlg = 'dialog[open]';
      const inputs = await page.$$(dlg + ' input[type="text"], ' + dlg + ' input:not([type])');
      ok(inputs.length > 0, '"+ מילה" — הטופס נפתח');
      await inputs[0].fill('מילה של בעל העמוד');
      const mean = await page.$(dlg + ' [contenteditable="true"], ' + dlg + ' textarea');
      if (mean) { await mean.click(); await page.keyboard.type('פירוש שכתבתי בעצמי'); }
      await page.click(dlg + ' button:has-text("✓ שמירה")'); await sleep(1500);
      const ws = (await call(page, 'words')).words;
      const mine = ws.find(w => w.word === 'מילה של בעל העמוד');
      ok(!!mine && /פירוש שכתבתי/.test(mine.meaning || ''), 'המילה נשמרה בטבלה, עם הפירוש', mine);
      ok(ws.length === 1497, '1,497 מילים', ws.length);
      const tabN = (await page.textContent('#main [data-list="words"]').catch(() => '')).replace(/\D/g, '');
      if (tabN && tabN !== '1497') finding('⚪', '📚 נתונים: אחרי "+ מילה" הסיכום אומר 1497, אבל הלשונית "מילים" נשארת על ' + tabN + ' עד רענון. צילום: e2e-14-words-1920.png');
      await shot(page, 'e2e-14-words-1920');
      // 🧩 מבנה המילה — שדה חדש
      await page.click('#main [data-structure]'); await sleep(800);
      const st = (await page.textContent('dialog[open], #sheet[open]').catch(() => '')).replace(/\s+/g, ' ');
      ok(/\+ שדה/.test(st), '🧩 מבנה המילה — רשימת השדות, עם "+ שדה"', st.slice(0, 200));
      await page.click('dialog[open] [data-add-field]'); await sleep(600);
      await page.locator('dialog[open] input').first().fill('דוגמה');
      await page.locator('dialog[open] select').first().selectOption('longtext');
      await page.click('dialog[open] [data-f-save]'); await sleep(1500);
      const st2 = (await page.textContent('dialog[open]').catch(() => '')).replace(/\s+/g, ' ');
      ok(/דוגמה/.test(st2), '"+ שדה" ← "דוגמה" (טקסט ארוך) — השדה במבנה', st2.slice(0, 300));
      await shot(page, 'e2e-14-structure-1920');
      await page.click('dialog[open] [data-close]').catch(() => {}); await sleep(400);
      // ➕ רשימה חדשה
      await page.click('#main [data-new-list]'); await sleep(600);
      await page.locator('dialog[open] input').first().fill('פתגמים');
      await page.locator('dialog[open] input').nth(1).fill('פתגם');
      await page.click('dialog[open] [data-l-create]'); await sleep(2000);
      if (await page.isVisible('dialog[open] [data-close]')) await page.click('dialog[open] [data-close]').catch(() => {});
      const tabs = (await page.textContent('#main')).replace(/\s+/g, ' ').slice(0, 120);
      ok(/פתגמים/.test(tabs), '"➕ רשימה חדשה" ← "פתגמים" — לשונית חדשה ב-📚', tabs);
      const files = (await call(page, 'pf_tree').catch(() => null)) || (await call(page, 'pf_files').catch(() => null));
      const paths = JSON.stringify(files || '');
      ok(/data\/[a-z0-9_]+\.json/.test(paths) && (paths.match(/data\/[a-z0-9_]+\.json/g) || []).length >= 2, 'קובץ הנתונים של הרשימה החדשה — בעץ הפרויקט (data/*.json)', (paths.match(/data\/[a-z0-9_]+\.json/g) || []));
      // השדה החדש — בספרייה של העורך
      await page.click('a:has-text("🎨 עורך")'); await ready(page);
      await page.click('[data-ntab="lib"]'); await sleep(500);
      const lib = (await page.textContent('[data-dp-fields], .ed-libbody').catch(() => '')).replace(/\s+/g, ' ');
      ok(/דוגמה/.test(lib), 'בעורך, "＋ ספרייה" ← השדות של words — "דוגמה" מוכן לגרירה לכרטיס', lib.slice(0, 200));
    });

    // ═══ 15. אנשים ותפקידים ═══
    await step('15. 👥 אנשים: עוזר ותפקיד', async () => {
      await page.click('a:has-text("👥 אנשים")');
      await page.waitForSelector('#main button:has-text("הוסף עוזר")', { timeout: 20000 });
      const form = page.locator('#main form').first();
      await form.locator('input[type="email"], input[name="email"]').fill('helper@example.test');
      const nm = form.locator('input[name="name"]'); if (await nm.count()) await nm.fill('עוזר בדיקה');
      await form.locator('select[name="role"]').selectOption({ label: 'עורך תוכן' }).catch(async () => form.locator('select[name="role"]').selectOption({ index: 0 }));
      await form.locator('button[type="submit"]').click(); await sleep(1500);
      const t = (await page.textContent('#main')).replace(/\s+/g, ' ');
      ok(/helper@example\.test|עוזר בדיקה/.test(t), '"+ הוסף עוזר" — העוזר ברשימה', t.slice(0, 300));
      await shot(page, 'e2e-15-people-1920');
    });

    // ═══ 16. נקודות שחזור ═══
    await step('16. ↩️ שחזור: נקודה, שינוי, חזרה', async () => {
      await page.click('a:has-text("↩️ שחזור")');
      await page.waitForSelector('#main button:has-text("📌 נקודה עכשיו")', { timeout: 20000 });
      await page.fill('#main .toolbar input', 'לפני ניסוי של בעל העמוד');
      await page.click('#main button:has-text("📌 נקודה עכשיו")'); await sleep(1500);
      ok(/לפני ניסוי של בעל העמוד/.test(await page.textContent('#main')), '📌 נקודה עכשיו — ברשימה, עם ההערה');
      const before = (await call(page, 'words')).words.length;
      // שינוי: מחיקת המילה שהוסיף (📚 ← ✏️ ← 🗑️ מחיקה)
      await page.click('a:has-text("📚 נתונים")'); await page.waitForSelector('#main [data-list="words"]', { timeout: 20000 });
      await page.click('#main [data-list="words"]'); await page.waitForSelector('#main .w-table-wrap, #main table', { timeout: 20000 });   // 📚 נפתח על הרשימה האחרונה ("פתגמים" מצעד 14)
      await page.fill('#main input[placeholder^="חיפוש"]', 'מילה של בעל העמוד'); await sleep(900);
      ok(await page.locator('#main tr[data-word]').count() === 1, 'חיפוש "מילה של בעל העמוד" — שורה אחת');
      await page.locator('#main tr[data-word] button:has-text("✏️")').first().click(); await sleep(600);
      await page.click('dialog[open] button:has-text("🗑️ מחיקה")'); await sleep(600);
      if (await page.isVisible('dialog[open] button:has-text("מחיקה")')) await page.click('dialog[open] button:has-text("מחיקה") >> nth=-1').catch(() => {});
      await sleep(1200);
      const mid = (await call(page, 'words')).words.length;
      ok(mid === before - 1, 'המילה נמחקה', { before, mid });
      await page.click('a:has-text("↩️ שחזור")'); await page.waitForSelector('#main li', { timeout: 20000 });
      const li = page.locator('#main li', { hasText: 'לפני ניסוי של בעל העמוד' }).first();
      await li.locator('button').first().click(); await sleep(700);
      await page.click('button:has-text("↩️ שחזור לנקודה הזו")'); await sleep(3000);
      const back = (await call(page, 'words')).words;
      ok(back.length === before && back.some(w => w.word === 'מילה של בעל העמוד'), '"↩️ שחזור לנקודה הזו" — המילה חזרה', back.length);
      await shot(page, 'e2e-16-restore-1920');
    });

    // ═══ 17. 🐞 "לא הצלחתי לשנות את זה" ═══
    await step('17. 🐞 דיווח מהעורך ← מטלה', async () => {
      await page.click('a:has-text("🎨 עורך")'); await ready(page);
      await page.click('[data-bug]'); await sleep(400);
      ok(await page.getAttribute('[data-bug]', 'aria-pressed') === 'true', '🐞 — מצב הצבעה');
      await click(page, '.kadisha-hero-subtitle-main');
      await page.waitForSelector('#sheet[open] [data-bug-send]', { timeout: 8000 });
      await page.fill('#sheet[open] textarea', 'ניסיתי לשנות את הגופן של שורת המשנה'); await sleep(300);
      await page.click('#sheet[open] [data-bug-send]');
      const done = await until(async () => /נפתחה מטלה/.test(await page.textContent('#sheet[open]')), 15000);
      ok(done, '"פתיחת מטלה" — "✅ נפתחה מטלה …"', await page.textContent('#sheet[open] [role=status]').catch(() => ''));
      if (await page.evaluate(() => [...document.querySelectorAll('#sheet[open] .ed-shot')].some(e => /\bnull\b/.test(e.textContent)))) finding('⚪', 'בגיליון "🐞 לא הצלחתי לשנות את זה" מתחת לצילום כתוב "null" — replaceChildren(img, null) כשהצילום מלא (screen.js:240). צילום: e2e-17-bug-1920.png');
      const code = ((await page.textContent('#sheet[open]')).match(/נפתחה מטלה ([A-Z]+-\d+)/) || [])[1];
      ok(!!code && await until(async () => (await page.textContent('#sheet[open] .ed-mine')).includes(code), 8000), '"הדיווחים שלי" בגיליון — ' + code);
      await shot(page, 'e2e-17-bug-1920');
      await page.click('#sheet[open] [data-close]').catch(() => {}); await sleep(300);
      // DevLoop — 🐞 בראש המסך ← "📋 … מטלות ממתינות לך" ← המטלה ברשימה
      await page.click('header button:has-text("🐞"), header a:has-text("🐞"), button[aria-label*="דיווח"]').catch(() => {}); await sleep(1000);
      await page.click('a:has-text("מטלות ממתינות"), button:has-text("מטלות ממתינות")').catch(e => console.log('   (רשימת המטלות: ' + e.message.split('\n')[0] + ')')); await sleep(1500);
      const dl = await page.evaluate(() => document.body.innerText);
      ok(!!code && dl.includes(code), 'DevLoop ← "מטלות ממתינות לך" — ' + code + ' ברשימה', dl.replace(/\s+/g, ' ').slice(0, 300));
      await shot(page, 'e2e-17-devloop-1920');
    });


    // ═══ 18. V13: תפריט הקשר, Ctrl+E, תבניות, היסטוריה ═══
    const ctxItem = async (pg, sel, txt, i = 0, fx, fy) => { const pt = await at(pg, sel, i, fx, fy); await pg.mouse.click(pt.x, pt.y); await sleep(300); await pg.mouse.click(pt.x, pt.y, { button: 'right' }); await sleep(500); const it = pg.locator('.ed-ctx [role=menuitem], .ed-ctx button').filter({ hasText: txt }).first(); const dis = await it.getAttribute('aria-disabled'); await it.click(); await sleep(700); return dis !== 'true'; };
    await step('18. נוחות (V13): לחיצה ימנית, Ctrl+E, תבניות, היסטוריה', async () => {
      if (!page.url().includes('editor')) { await page.click('a:has-text("🎨 עורך")'); }
      await ready(page);
      // העתק / הדבק סגנון: מהכותרת אל שורת המשנה
      const fs0 = await cs(page, '.kadisha-hero-subtitle-main', 'fontSize'), fsH = await cs(page, 'h1', 'fontSize');
      ok(await ctxItem(page, 'h1', 'העתק סגנון'), 'לחיצה ימנית על הכותרת ← "🎨 העתק סגנון"');
      await ctxItem(page, '.kadisha-hero-subtitle-main', 'הדבק סגנון');
      if (await page.isVisible('#sheet[open] button.primary')) { await page.click('#sheet[open] button.primary'); await sleep(900); }
      const fs1 = await cs(page, '.kadisha-hero-subtitle-main', 'fontSize');
      ok(fs1 === fsH && fs1 !== fs0, 'לחיצה ימנית על שורת המשנה ← "🖌 הדבק סגנון" — הגודל של הכותרת', { fs0, fsH, fs1 });
      // 🕘 היסטוריה — הצעד בשמו, ולחיצה על הצעד שלפניו מחזירה
      await page.click('[data-ntab="hist"]'); await sleep(600);
      const hist = await page.$$eval('[data-hist]', a => a.map(x => x.textContent.trim()));
      ok(hist.some(t => /הדבק סגנון/.test(t)), '🕘 היסטוריה — "הדבק סגנון" ברשימה, בשמו', hist.slice(-4));
      const prev = await page.$$eval('[data-hist]', a => { const i = a.findIndex(x => /הדבק סגנון/.test(x.textContent)); return i > 0 ? a[i - 1].dataset.hist : null; });
      if (prev) { await page.click(`[data-hist="${prev}"]`); await sleep(900); }
      ok(await cs(page, '.kadisha-hero-subtitle-main', 'fontSize') === fs0, 'לחיצה על הצעד שלפני — שורת המשנה חזרה לגודלה');
      await shot(page, 'e2e-18-history-1920');
      // Ctrl+E — "צבע טקסט" לאלמנט הנבחר
      await click(page, 'h1');
      await page.keyboard.press('Control+e'); await sleep(500);
      await page.keyboard.type('צבע טקסט'); await sleep(400); await page.keyboard.press('Enter'); await sleep(700);
      const focused = await page.evaluate(() => { const a = document.activeElement; return a && (a.closest('[data-field], .sg-row, .sg-line') || a).textContent.replace(/\s+/g, ' ').slice(0, 60); });
      ok(/צבע טקסט/.test(focused || '') || await page.isVisible('[data-color="color"]'), 'Ctrl+E ← "צבע טקסט" ← Enter — השדה בפאנל', focused);
      await page.keyboard.press('Escape'); await sleep(200);
      // 📄 שמור כתבנית ← הלשונית ← הוספה לעמוד ← Ctrl+Z
      await ctxItem(page, '.kadisha-hero', 'שמור כתבנית', 0, 0.03, 0.05);
      await page.fill('[data-ts="label"]', 'פס פתיחה'); await page.fill('[data-ts="name"]', 'hero-band');
      await page.click('[data-ts-go]'); await until(() => page.evaluate(() => !document.getElementById('sheet').open), 15000); await sleep(1000);
      await page.click('[data-ntab="tpl"]'); await sleep(600);
      ok(await page.isVisible('.ed-lib-item[data-lib="tpl-hero-band"]'), 'לחיצה ימנית ← "📄 שמור כתבנית" — "▤ פס פתיחה" בלשונית 📄 תבניות');
      const n0 = await page.evaluate(() => Editor._state.canvas.doc.querySelectorAll('.kadisha-hero').length);
      await drag(page, await box(page, '.ed-lib-item[data-lib="tpl-hero-band"]'), await at(page, '.kadisha-lexicon-count', 0, 0.5, 0.9)); await sleep(1200);
      const n1 = await page.evaluate(() => Editor._state.canvas.doc.querySelectorAll('[class*="kadisha-hero"]:is(section)').length);
      ok(n1 === n0 + 1, 'גרירת התבנית לקנבס — עוד פס פתיחה בעמוד', { n0, n1 });
      await shot(page, 'e2e-18-template-1920');
      await undo(page); await sleep(500);
      ok(await page.evaluate(() => Editor._state.canvas.doc.querySelectorAll('[class*="kadisha-hero"]:is(section)').length) === n0, 'Ctrl+Z — הפס שנוסף הוסר');
      // 🗜 ZIP של התבנית — הורדה
      const [dl] = await Promise.all([page.waitForEvent('download', { timeout: 15000 }), page.click('[data-tpl-export="hero-band"]')]);
      const zp = path.join(data, 'tpl.zip'); await dl.saveAs(zp);
      const zb = fs.readFileSync(zp);
      ok(zb.slice(0, 2).toString() === 'PK' && zb.includes('template.html'), '🗜 — ZIP של התבנית הורד (template.html בתוכו)', zb.length);
    });


    // ═══ 19. 🛠️ מסך המפתח ═══
    await step('19. 🛠️ מפתח: מטלות, יומן, פעולות, תקינות', async () => {
      await page.click('a:has-text("🛠️ מפתח")');
      await page.waitForSelector('.dev-subs', { timeout: 20000 }); await sleep(1500);
      const tasks = (await page.textContent('.dev-body')).replace(/\s+/g, ' ');
      ok(/לא הצלחתי לשנות/.test(tasks), '📋 מטלות — שורת "🐞 לא הצלחתי לשנות" (הדיווח מצעד 17)', tasks.slice(0, 160));
      const sub = async t => { await page.click('.dev-subs a:has-text("' + t + '")'); await sleep(2500); return (await page.textContent('.dev-body')).replace(/\s+/g, ' '); };
      const log = await sub('📜 יומן');
      ok(/שורות מתוך/.test(log) && /request/.test(log) && /העתקה לצ'אט/.test(log), '📜 יומן — כל בקשה, עם "📋 העתקה לצ\'אט"', log.slice(0, 160));
      const audit = await sub('👣 פעולות');
      ok(/ייבא מחדש/.test(audit) && /(עוזר|הוסיף|הזמין)/.test(audit) && /שחזר/.test(audit), '👣 פעולות — הייבוא, העוזר והשחזור של התרחיש', audit.slice(0, 300));
      const health = await sub('🩺 תקינות');
      const reds = await page.$$eval('.dev-body li, .dev-body .row, .dev-body > div > div', a => a.filter(x => /^❌/.test(x.textContent.trim())).map(x => x.textContent.replace(/\s+/g, ' ').slice(0, 140)));
      ok(/✅/.test(health) && /אדומות מתוך/.test(health), '🩺 תקינות — הבדיקות רצות, עם ✅ / ❌', health.slice(0, 160));
      const nX = (health.match(/❌/g) || []).length, nW = (health.match(/🔧/g) || []).length;
      ok(nX > 0 ? nW >= nX : true, 'כל ❌ — עם 🔧 "מה לעשות" (' + nX + ' אדומות בסביבת הבדיקה: build.json, data/.htaccess תחת php -S, וכו\')', { nX, nW, reds });
      await shot(page, 'e2e-19-dev-1920');
    });

    // ═══ 20. 📁 עץ הקבצים ועורך הקוד — בטלפון ═══
    await step('20. 390: 📁 עץ הקבצים ועורך הקוד', async () => {
      const c3 = await mk(390, 844), pq = await c3.newPage(); watch(pq, '390-code');
      await pq.goto(base + '/#/project'); await pq.waitForSelector('.pj-tree .pj-row', { timeout: 20000 }); await sleep(800);
      ok(await pq.evaluate(() => document.documentElement.scrollWidth - innerWidth) <= 1, '390: 📁 — בלי גלילה אופקית');
      const banner = (await pq.textContent('#main')).replace(/\s+/g, ' ');
      if (/הקבצים כאן עדיין אינם מתפרסמים/.test(banner)) finding('🟡', '📁 פרויקט אומר "הקבצים כאן עדיין אינם מתפרסמים. הפרסום מהקבצים ייפתח בשלב הבא" — אבל הפרסום מהקבצים כבר עובד (צעד 11: "✓ הוחל באתר" עם השינויים). בעל העמוד שעורך כאן יחשוב שהעבודה שלו לא תגיע לאתר. צילום: e2e-20-tree-390.png');
      await shot(pq, 'e2e-20-tree-390');
      await pq.locator('.pj-tree .pj-row', { hasText: 'page.css' }).first().tap(); await sleep(2500);
      ok(await pq.isVisible('.monaco-editor'), '390: הקשה על css/page.css — עורך הקוד נפתח');
      await pq.locator('.monaco-editor .view-line').first().tap(); await sleep(300);
      await pq.keyboard.press('Home'); await pq.keyboard.type('/* נערך בטלפון */\n'); await sleep(500);
      await pq.click('#main button:has-text("💾 שמירה")'); await sleep(1500);
      const t = await pq.evaluate(async () => { const f = Project.docs.byPath('css/page.css'); return (await Project.docs.open(f.id)).text(); });
      ok(/נערך בטלפון/.test(t), '390: הקלדה ← 💾 שמירה — הקובץ נשמר', t.slice(0, 80));
      ok(await pq.evaluate(() => [...Project._state.docs.values()].every(d => !d.dirty)), '390: "נשמר" — אין שינויים שלא נשמרו');
      await shot(pq, 'e2e-20-code-390');
      await c3.close();
    });


    // ═══ 21. 390 במגע — V14: גיליון, ⬆⬇, לחיצה ארוכה, צביטה, 🔎 ═══
    await step('21. 390 במגע (V14): גיליון, ⬆⬇, לחיצה ארוכה, צביטה, 🔎', async () => {
      const c4 = await mk(390, 844), pt = await c4.newPage(); watch(pt, '390-v14');
      await pt.goto(base + '/editor.php#/editor/index.html'); await ready(pt); await sleep(800);
      const cdp = await c4.newCDPSession(pt);
      const touch = (type, pts) => cdp.send('Input.dispatchTouchEvent', { type, touchPoints: pts.map((q, i) => ({ x: q.x, y: q.y, id: i + 1, radiusX: 4, radiusY: 4, force: 1 })) });
      // הקשה על כותרת המשנה ← הגיליון של הפאנל נפתח, והאלמנט נשאר גלוי מעליו
      await tap(pt, '.kadisha-hero-subtitle-main'); await sleep(700);
      if (!(await pt.isVisible('.ed-info'))) { await pt.click('.ed-bar [data-panel="info"]').catch(() => {}); await sleep(600); }
      const geo = async () => pt.evaluate(() => { const info = document.querySelector('.ed-info'), c = Editor._state.canvas, e = c.elOf(c.selected); const f = c.frame.getBoundingClientRect(), r = e.getBoundingClientRect(); return { sheetTop: Math.round(info.getBoundingClientRect().top), sheetH: Math.round(info.getBoundingClientRect().height), elBottom: Math.round(f.top + r.bottom * c.scale), elTop: Math.round(f.top + r.top * c.scale) }; });
      const g0 = await geo();
      ok(g0.elBottom <= g0.sheetTop + 2 && g0.elTop >= 0, 'הפאנל כגיליון תחתון — האלמנט הנבחר גלוי מעליו', g0);
      await shot(pt, 'e2e-21-sheet-390');
      // ידית הגיליון: גרירה למעלה ← גבוה יותר; למטה ← נמוך
      const grip = await box(pt, '[data-ts-grip="info"]');
      ok(!!grip, 'ידית אחיזה בראש הגיליון');
      if (grip) {
        await tdrag(pt, grip, { x: grip.x, y: grip.y - 300 }); await sleep(500);
        const g1 = await geo();
        ok(g1.sheetH > g0.sheetH + 100, 'גרירת הידית למעלה — הגיליון גבוה יותר', { before: g0.sheetH, after: g1.sheetH });
        const grip2 = await box(pt, '[data-ts-grip="info"]');
        await tdrag(pt, grip2, { x: grip2.x, y: grip2.y + 300 }); await sleep(500);
        const g2 = await geo();
        ok(g2.sheetH < g1.sheetH - 100, 'וגרירה למטה — נמוך שוב', { full: g1.sheetH, after: g2.sheetH });
      }
      // ⬆ ⬇ בסרגל הנבחר
      const o0 = await heroOrder(pt);
      await pt.click('[data-ts-mv="down"], [data-ts-mv="next"]').catch(async () => pt.click('[data-ts-mv] >> nth=1')); await sleep(800);
      const o1 = await heroOrder(pt);
      ok(JSON.stringify(o0) !== JSON.stringify(o1) && o1.indexOf('p.kadisha-hero-subtitle-main') === o0.indexOf('p.kadisha-hero-subtitle-main') + 1, '⬇ בסרגל הנבחר — שורת המשנה ירדה מקום אחד', { o0, o1 });
      await pt.click('.ed-bar button:has-text("↶")'); await sleep(800);
      ok(JSON.stringify(await heroOrder(pt)) === JSON.stringify(o0), '↶ — הסדר חזר');
      // לחיצה ארוכה ← תפריט ההקשר
      const lp = await at(pt, 'h1');
      await pt.evaluate(PD); await pt.evaluate(([x, y]) => window.__pd.down(x, y, true), [lp.x, lp.y]); await sleep(900);
      await pt.evaluate(([x, y]) => window.__pd.up(x, y), [lp.x, lp.y]); await sleep(700);
      const menu = await pt.$$eval('.ed-ctx [role=menuitem], .ed-ctx button, #sheet[open] .ed-menu button', a => a.map(x => x.textContent.trim().slice(0, 20)));
      ok(menu.length >= 5 && menu.some(t => /שכפל|העתק/.test(t)), 'לחיצה ארוכה על הכותרת — תפריט ההקשר (שכפל, העתק…)', menu.slice(0, 8));
      await shot(pt, 'e2e-21-longpress-390');
      await pt.keyboard.press('Escape'); await closeAll(pt); await sleep(300);
      if (await pt.isVisible('.ed-ctx')) await pt.mouse.click(5, 300);
      // צביטה בקנבס ← הגדלה; "⤢ התאם" ← 100%
      const fr = await pt.evaluate(() => { const r = Editor._state.canvas.frame.getBoundingClientRect(); return { x: r.left + r.width / 2, y: r.top + Math.min(r.height / 2, 200) }; });
      await touch('touchStart', [{ x: fr.x - 30, y: fr.y }, { x: fr.x + 30, y: fr.y }]);
      for (let i = 1; i <= 10; i++) { await touch('touchMove', [{ x: fr.x - 30 - i * 10, y: fr.y }, { x: fr.x + 30 + i * 10, y: fr.y }]); await sleep(30); }
      await touch('touchEnd', []); await sleep(700);
      const fitTxt = await pt.textContent('[data-ts-fit]').catch(() => '');
      ok(await pt.isVisible('[data-ts-fit]') && /\d{3}%/.test(fitTxt) && !/100%/.test(fitTxt), 'צביטה בשתי אצבעות — הקנבס מוגדל ("⤢ התאם · N%")', fitTxt);
      await shot(pt, 'e2e-21-pinch-390');
      if (await pt.isVisible('[data-ts-fit]')) { await pt.tap('[data-ts-fit]'); await sleep(500); }
      ok(!(await pt.isVisible('[data-ts-fit]')), '"⤢ התאם" — חזרה ל-100%');
      // 🔎 — חיפוש פעולה או שליטה (במקום Ctrl+E)
      await tap(pt, 'h1'); await sleep(400);
      await pt.tap('[data-ts-palette]'); await sleep(600);
      const pal = await pt.$('.pl-in');
      ok(!!pal, '🔎 — שדה "פעולה או שליטה"');
      if (pal) { await pal.type('צבע טקסט'); await sleep(400); await pt.keyboard.press('Enter'); await sleep(800); }
      ok(await pt.isVisible('[data-color="color"]'), '🔎 ← "צבע טקסט" — השדה בפאנל, גלוי');
      await shot(pt, 'e2e-21-palette-390');
      await cdp.detach(); await c4.close();
    });


    // ═══ 22. V15: הגדרות העמוד, עמוד חדש מתבנית, ⭐ ראשי, 🚫 404 ═══
    await step('22. עמודים (V15): ⚙️ הגדרות, תבנית עמוד ← עמוד חדש, ⭐ ראשי, 🚫 404', async () => {
      if (!page.url().includes('editor')) await page.click('a:has-text("🎨 עורך")');
      await ready(page); await closeAll(page);
      // ⚙️ — כותרת, תיאור, תמונת שיתוף (מהבורר האמיתי: העלאה מהמחשב)
      await page.click('[data-head-btn]'); await sleep(600);
      ok(await page.isVisible('[data-hd-form]'), '⚙️ בסרגל — הגדרות העמוד');
      const fill = async (k, v) => { const f = page.locator(`[data-hd-in="${k}"]`); await f.click({ clickCount: 3 }); await f.fill(v); await f.press(k === 'description' ? 'Tab' : 'Enter'); await sleep(600); };
      await fill('title', 'קול מרוקו — המילון');
      await fill('description', 'מילים, פתגמים וברכות בערבית־היהודית של מרוקו, עם ההגייה');
      let H = await html(page);
      ok(/<title>קול מרוקו — המילון<\/title>/.test(H), 'כותרת ← <title>', (H.match(/<title>[^<]*<\/title>/) || [])[0]);
      ok(/<meta name="description" content="מילים, פתגמים/.test(H), 'תיאור ← meta description');
      await page.click('[data-hd-pick="ogImage"]'); await sleep(600);
      const [fc] = await Promise.all([page.waitForEvent('filechooser', { timeout: 8000 }), page.click('#sheet[open] [data-pick-upload], dialog[open] [data-pick-upload]')]);
      await fc.setFiles(GREEN); await until(() => page.evaluate(() => !document.getElementById('sheet').open), 15000); await sleep(800);
      H = await html(page);
      ok(/<meta property="og:image" content="[^"]*photo-green(-\d+)?\.png">/.test(H), '"תמונת שיתוף" ← ⬆️ העלאה מהמחשב — og:image', (H.match(/<meta property="og:image"[^>]*>/) || [])[0]);
      ok(/photo-green/.test(await page.evaluate(() => document.querySelector('[data-hd-card] img')?.src || '')), 'כרטיס השיתוף (וואטסאפ / פייסבוק) עם התמונה');
      await shot(page, 'e2e-22-settings-1920');
      await page.click('[data-head-btn]'); await sleep(400);
      // 📄 שמור את העמוד כתבנית ← ➕ עמוד חדש "מתבנית עמוד"
      await page.click('[data-ntab="tpl"]'); await sleep(400);
      await page.click('[data-tpl-save-page]'); await sleep(600);
      await page.fill('[data-ts="label"]', 'עמוד המילון'); await page.fill('[data-ts="name"]', 'lexicon-page');
      await page.click('[data-ts-go]'); await until(() => page.evaluate(() => !document.getElementById('sheet').open), 15000); await sleep(1000);
      await page.click('[data-pages-btn]'); await sleep(400);
      await page.click('[data-pg-pop] [data-pg-new]'); await sleep(400);
      await page.fill('[data-pg-f="title"]', 'פתגמים');
      await page.selectOption('[data-pg-f="start"]', 'tpl'); await sleep(200);
      const tplOpt = await page.$$eval('[data-pg-f="template"] option', a => a.map(o => o.value));
      ok(tplOpt.includes('lexicon-page'), '"➕ עמוד" ← "מתחיל מ: תבנית עמוד" — "עמוד המילון" ברשימה', tplOpt);
      await page.selectOption('[data-pg-f="template"]', 'lexicon-page');
      const slug = await page.inputValue('[data-pg-f="slug"]');
      await page.click('[data-pg-create]');
      await ready(page, slug + '/index.html').catch(() => {}); await sleep(600);
      const P = await page.evaluate(() => Editor._state.file.path);
      const newH = await page.evaluate(() => Editor._state.doc.text());
      ok(P === slug + '/index.html' && /kadisha-hero/.test(newH), 'העמוד החדש (' + P + ') נוצר מהתבנית ונפתח בעורך', { P, html: newH.replace(/\s+/g, ' ').slice(0, 600), tpl: await page.evaluate(async () => { try { const f = Project.docs.byPath('templates/lexicon-page/template.html'); return f ? (await Project.docs.open(f.id)).text().slice(0, 300) : 'אין קובץ'; } catch (e) { return e.message; } }) });
      await shot(page, 'e2e-22-newpage-1920');
      // 📄 כל העמודים ← ⭐ קבע כראשי ← בחזרה; 🚫 צור דף 404
      await page.click('[data-pages-btn]'); await sleep(300); await page.click('[data-pg-pop] [data-pg-manage]'); await sleep(700);
      const rows = await page.$$eval('[data-pg-row]', a => a.map(x => x.dataset.pgRow));
      if (rows.some(r => /^templates\//.test(r))) finding('🟡', '📄 עמודים מציג את תבנית העמוד (' + rows.find(r => /^templates\//.test(r)) + ') כעמוד באתר, עם כתובת /lexicon/templates/… — "📄 שמור את העמוד כתבנית" (V13) יוצר "עמוד" ברשימה (V15). הבנייה מוציאה את templates/, אבל בעל העמוד רואה עמוד שאינו עמוד. צילום: e2e-22-pages-1920.png');
      await page.click(`[data-pg-row="${P}"]`); await sleep(300); await page.click('[data-pg-act="entry"]'); await sleep(900);
      ok(await page.evaluate(p => /⭐/.test(document.querySelector(`[data-pg-row="${p}"]`).textContent), P), '⭐ קבע כראשי — העמוד החדש מסומן ⭐');
      await page.click('[data-pg-row="index.html"]'); await sleep(300); await page.click('[data-pg-act="entry"]'); await sleep(900);
      ok(await page.evaluate(() => /⭐/.test(document.querySelector('[data-pg-row="index.html"]').textContent)), '⭐ בחזרה ל-index.html');
      await page.click('[data-pg-404new]'); await sleep(1200);
      ok(await page.evaluate(() => /🚫/.test(document.querySelector('[data-pg-row="404.html"]')?.textContent || '')), '🚫 צור דף 404 — 404.html ברשימה');
      await shot(page, 'e2e-22-pages-1920');
      await page.click('[data-pg-close]').catch(() => {}); await sleep(400);
      // חזרה ל-index.html מהבורר
      await page.click('[data-pages-btn]'); await sleep(300); await page.click('[data-pg-pop] [data-pg-open="index.html"]'); await ready(page, 'index.html');
      ok(true, 'חזרה ל-index.html מהבורר "📄"');
    });


    // ═══ 23. V16a: רכיבים מוכנים ═══
    const nComp = (pg, n) => pg.evaluate(n => Editor._state.canvas.doc.querySelectorAll(`[data-kol-component="${n}"]`).length, n);
    const compsTab = async pg => { if (!(await pg.isVisible('[data-ntab="comp"]'))) await pg.click('.ed-bar [data-panel="nav"]'); await pg.click('[data-ntab="comp"]'); await sleep(500); await pg.waitForFunction(() => Editor._state.kit && Editor._state.kit.items.length >= 20, null, { timeout: 15000 }); };
    await step('23. רכיבים מוכנים (V16a): חיפוש, הוספה, הגדרה, ➕ פריט, גרירה מהלוח, 390', async () => {
      if (!page.url().includes('editor')) await page.click('a:has-text("🎨 עורך")');
      await ready(page); await closeAll(page);
      await compsTab(page);
      ok(await page.locator('[data-kit-grid="lib"] .kit-card').count() >= 20, 'לשונית "🧩 רכיבים" — 20 רכיבים מוכנים, עם תמונה מקדימה');
      await page.fill('[data-kit-q="lib"]', 'אקורד'); await sleep(300);
      ok(await page.isVisible('[data-kit-item="accordion"]'), 'חיפוש "אקורד" ← אקורדיון');
      await page.fill('[data-kit-q="lib"]', ''); await sleep(300);
      // לחיצה על כרטיס ← נכנס אחרי הנבחר
      await click(page, '.kadisha-hero-subtitle', 1);
      const tx0 = await page.evaluate(() => Editor._state.tx.length);
      await page.click('[data-kit-item="accordion"]'); await sleep(2200);
      if (await page.isVisible('#sheet[open] [data-kit-as], #sheet[open] [data-kit-take]')) { await page.click('#sheet[open] [data-kit-take], #sheet[open] [data-kit-as]'); await sleep(2000); }
      ok(await nComp(page, 'accordion') === 1, 'לחיצה על "אקורדיון" — נכנס לעמוד (אחרי הנבחר)', await nComp(page, 'accordion'));
      ok(await page.evaluate(() => !!Project.docs.byPath('components/accordion/template.html')), 'components/accordion/ הועתק לפרויקט (שימוש ראשון)');
      await shot(page, 'e2e-23-kit-add-1920');
      // הגדרה מהפאנל
      await click(page, '[data-kol-component="accordion"]', 0, 0.02, 0.02);
      for (let k = 0; k < 4 && !(await page.isVisible('[data-comp-panel]')); k++) { await page.click('.ed-seltools-host [aria-label="בחר הורה"]').catch(() => {}); await sleep(300); }
      await tabP(page, 'content');
      const fields = await page.$$eval('[data-comp-panel] [data-cf]', a => a.map(x => x.tagName + ':' + x.dataset.cf + ':' + (x.type || '')));
      ok(fields.length > 0, 'הפאנל — ההגדרות של הרכיב', fields);
      const h0 = await html(page);
      const tf = await page.$('[data-comp-panel] input[data-cf][type="text"], [data-comp-panel] input[data-cf]:not([type]), [data-comp-panel] select[data-cf], [data-comp-panel] input[type="checkbox"][data-cf]');
      if (tf) { const tag = await tf.evaluate(e => e.tagName + ':' + e.type); if (/SELECT/.test(tag)) { const opts = await tf.$$eval('option', o => o.map(x => x.value)); await tf.selectOption(opts[opts.length - 1]); } else if (/checkbox/.test(tag)) await tf.click(); else { await tf.fill('הגדרה של בעל העמוד'); await tf.press('Enter'); } await sleep(900); }
      ok((await html(page)) !== h0, 'שינוי הגדרה בפאנל — העמוד השתנה');
      // ➕ פריט
      const items0 = await page.evaluate(() => { const e = Editor._state.canvas.doc.querySelector('[data-kol-component="accordion"]'); return e ? e.querySelectorAll('details, .kol-acc-item, [class*="item"]').length : -1; });
      await click(page, '[data-kol-component="accordion"] summary, [data-kol-component="accordion"] details', 0);
      if (await page.isVisible('[data-kit-add-item]')) { await page.click('[data-kit-add-item]'); await sleep(900); }
      const items1 = await page.evaluate(() => { const e = Editor._state.canvas.doc.querySelector('[data-kol-component="accordion"]'); return e ? e.querySelectorAll('details, .kol-acc-item, [class*="item"]').length : -1; });
      ok(items1 > items0, '"➕ פריט" — עוד פריט באקורדיון', { items0, items1 });
      // גרירה מהלוח הרחב
      await page.click('[data-kit-wide]'); await sleep(500);
      ok(await page.isVisible('[data-kit-board]'), '"לוח רחב" — כל הרכיבים בגדול');
      await drag(page, await box(page, '[data-kit-grid="board"] [data-kit-item="quote"]'), await at(page, '.kadisha-lexicon-count', 0, 0.5, 0.9)); await sleep(2200);
      if (await page.isVisible('#sheet[open] [data-kit-take], #sheet[open] [data-kit-as]')) { await page.click('#sheet[open] [data-kit-take], #sheet[open] [data-kit-as]'); await sleep(2000); }
      ok(await nComp(page, 'quote') >= 1, 'גרירת "ציטוט" מהלוח לקנבס — בעמוד', await nComp(page, 'quote'));
      await shot(page, 'e2e-23-kit-board-1920');
      await closeAll(page); if (await page.isVisible('[data-kit-board]')) await page.click('[data-kit-close]').catch(() => {});
      // Ctrl+Z
      await undo(page); await sleep(600);
      ok(await nComp(page, 'quote') === 0, 'Ctrl+Z — הציטוט הוסר');
      void tx0;
      // 390: גיליון ← הקשה = "הוסף אחרי הנבחר"
      await saved(page);
      const c5 = await mk(390, 844), pk = await c5.newPage(); watch(pk, '390-kit');
      await pk.goto(base + '/editor.php#/editor/index.html'); await ready(pk); await sleep(800);
      await tap(pk, 'h1'); await sleep(400);
      await pk.tap('.ed-bar [data-panel="nav"]').catch(() => {}); await sleep(400);
      await pk.tap('[data-ntab="comp"]'); await sleep(600);
      await pk.waitForFunction(() => Editor._state.kit && Editor._state.kit.items.length >= 20, null, { timeout: 15000 });
      await pk.tap('[data-kit-wide]'); await sleep(500);
      ok(await pk.isVisible('[data-kit-board]'), '390: "לוח רחב" — גיליון מלמטה');
      await shot(pk, 'e2e-23-kit-390');
      const n0 = await nComp(pk, 'counter');
      await pk.tap('[data-kit-grid="board"] [data-kit-item="counter"]'); await sleep(2500);
      if (await pk.isVisible('#sheet[open] [data-kit-take], #sheet[open] [data-kit-as]')) { await pk.tap('#sheet[open] [data-kit-take], #sheet[open] [data-kit-as]'); await sleep(2000); }
      const after = await pk.evaluate(() => { const c = Editor._state.canvas, h = c.doc.querySelector('h1'); let x = h.nextElementSibling; return x ? (x.getAttribute('data-kol-component') || x.localName) : null; });
      ok(await nComp(pk, 'counter') === n0 + 1 && after === 'counter', '390: הקשה על "מונה" — נכנס מיד אחרי הכותרת הנבחרת', { after });
      await shot(pk, 'e2e-23-kit-390-added');
      await c5.close();
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
