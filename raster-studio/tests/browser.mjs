// בדיקות דפדפן לעורך: node raster-studio/tests/browser.mjs [תיקייה-לצילומים]
// מרים php -S עם tests/serve.php (משתמש בדיקה, מסד זמני), ובודק ב-Chromium:
// פיקסלים מדויקים אחרי הלוך-חזור דרך המנוע, מצבי מיזוג מול חישוב ייחוס, זום והזזה, צביטה, תקציב, וטלפון.

import { createRequire } from 'node:module';
import { spawn } from 'node:child_process';
import { deflateSync, crc32 } from 'node:zlib';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);
const { chromium } = require('playwright');

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const SHOTS = process.argv[2] || null;
const PORT = 8700 + Math.floor(Math.random() * 200);
const BASE = `http://127.0.0.1:${PORT}`;
const tmp = mkdtempSync(join(tmpdir(), 'rs-browser-'));

let fails = 0, n = 0;
function check(name, ok, detail = '') {
  n++;
  if (!ok) fails++;
  console.log((ok ? '✓ ' : '✗ ') + name + (ok || !detail ? '' : ' — ' + detail));
}

// ---- PNG אמיתי, בלי דפדפן: כל פיקסל ידוע, כולל שקיפות חלקית ----
// (קנבס דו-ממדי היה מכפיל באלפא ומאבד דיוק — כאן הבתים מדויקים)
const IMG_W = 700, IMG_H = 520;
const PATTERN = `(x, y) => [x % 256, y % 256, (x * 7 + y * 3) % 256, x < 350 ? 255 : (y * 5) % 256]`;
const pattern = eval(PATTERN);
function png(w, h, px) {
  const raw = Buffer.alloc((w * 4 + 1) * h);
  for (let y = 0; y < h; y++) {
    raw[y * (w * 4 + 1)] = 0;
    for (let x = 0; x < w; x++) raw.set(px(x, y), y * (w * 4 + 1) + 1 + x * 4);
  }
  const chunk = (type, data) => {
    const len = Buffer.alloc(4); len.writeUInt32BE(data.length);
    const td = Buffer.concat([Buffer.from(type), data]);
    const crc = Buffer.alloc(4); crc.writeUInt32BE(crc32(td) >>> 0);
    return Buffer.concat([len, td, crc]);
  };
  const ihdr = Buffer.alloc(13);
  ihdr.writeUInt32BE(w, 0); ihdr.writeUInt32BE(h, 4);
  ihdr[8] = 8; ihdr[9] = 6; ihdr[10] = 0; ihdr[11] = 0; ihdr[12] = 0;
  return Buffer.concat([Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]), chunk('IHDR', ihdr),
    chunk('IDAT', deflateSync(raw)), chunk('IEND', Buffer.alloc(0))]);
}
const PNG = png(IMG_W, IMG_H, pattern);

const server = spawn('php', ['-S', `127.0.0.1:${PORT}`, '-t', ROOT, join(ROOT, 'tests/serve.php')], {
  env: { ...process.env, RASTER_STUDIO_DB: join(tmp, 'db.sqlite') }, stdio: 'ignore',
});

async function waitServer() {
  for (let i = 0; i < 50; i++) {
    try { if ((await fetch(BASE + '/index.html')).ok) return; } catch { /* עוד לא */ }
    await new Promise(r => setTimeout(r, 100));
  }
  throw new Error('php -S לא עלה');
}

async function main() {
  await waitServer();
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: 1000, height: 760 }, hasTouch: true });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', e => errors.push(e.message));
  page.on('console', m => m.type() === 'error' && errors.push(m.text()));

  // ---- מסמך מהספרייה ----
  await page.goto(BASE + '/index.html');
  await page.waitForSelector('#new-btn:not([disabled])');
  const id = await page.evaluate(async () => {
    const r = await fetch('api.php', { method: 'POST', headers: { 'X-Raster-Studio': '1' },
      body: JSON.stringify({ action: 'doc_create', title: 'בדיקה', size: 'custom', px: ['600', '400'] }) });
    return (await r.json()).doc.id;
  });
  await page.reload();
  await page.waitForSelector('.doc-row a');
  check('בספרייה: שורה שמובילה לעורך', await page.$eval('.doc-row a', a => a.getAttribute('href')) === 'editor.html?doc=' + id);

  await page.goto(BASE + '/editor.html?doc=' + id);
  await page.waitForFunction(() => window.__rs?.doc);
  const info = await page.evaluate(() => ({
    name: __rs.renderer.name, w: __rs.doc.width, h: __rs.doc.height, title: document.querySelector('#title').textContent,
    px: Array.from(__rs.renderer.readPixels(__rs.doc, 599, 399, 1, 1)), msg: document.querySelector('#msg').hidden,
  }));
  check('העורך עולה עם WebGL2', info.name === 'WebGL2', info.name);
  check('גודל ושם מהשרת', info.w === 600 && info.h === 400 && info.title === 'בדיקה');
  check('רקע לבן אטום עד הפיקסל האחרון', info.px.join() === '255,255,255,255', info.px.join());
  check('אין הודעת שגיאה על הבמה', info.msg);

  // ---- הלוך-חזור: PNG → sRGB → לינארי float16 → sRGB, פיקסל-פיקסל ----
  const round = await page.evaluate(async ([b64, PAT, W, H]) => {
    const bytes = Uint8Array.from(atob(b64), c => c.charCodeAt(0));
    await __rs.openAsDocument(new File([bytes], 'בדיקה.png', { type: 'image/png' }));
    const f = eval(PAT);
    const got = __rs.renderer.readPixels(__rs.doc, 0, 0, W, H);
    let bad = 0, maxErr = 0, first = '';
    for (let y = 0; y < H; y++) for (let x = 0; x < W; x++) {
      const e = f(x, y), i = (y * W + x) * 4;
      // אלפא 0 — הצבע לא קיים (מוכפל באפס); אחרת: צבע ±1, אלפא מדויק
      const err = e[3] === 0 ? Math.max(got[i], got[i + 1], got[i + 2], got[i + 3])
        : Math.max(Math.abs(got[i] - e[0]), Math.abs(got[i + 1] - e[1]), Math.abs(got[i + 2] - e[2]), Math.abs(got[i + 3] - e[3]) * 99);
      if (err > maxErr) maxErr = err;
      if (err > 1) { bad++; if (!first) first = `(${x},${y}) ${e} → ${Array.from(got.subarray(i, i + 4))}`; }
    }
    return { bad, maxErr, first, w: __rs.doc.width, title: document.querySelector('#title').textContent, stats: __rs.renderer.stats(__rs.doc) };
  }, [PNG.toString('base64'), PATTERN, IMG_W, IMG_H]);
  check(`הלוך-חזור מדויק: ${IMG_W}×${IMG_H}, כל הפיקסלים בטווח ±1 (גם על גבולות האריחים)`, round.bad === 0, `${round.bad} פיקסלים, ${round.first}`);
  check('פתיחה כמסמך — בגודל התמונה ובשמה', round.w === IMG_W && round.title === 'בדיקה');
  check('אריחים: 3×3 לשכבה', round.stats.layerTiles === 9, JSON.stringify(round.stats));

  // ---- מצבי מיזוג מול חישוב ייחוס (W3C, בלינארי) ----
  const blends = await page.evaluate(async () => {
    const lin = v => { const c = v / 255; return c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4; };
    const enc = l => { l = Math.min(1, Math.max(0, l)); return Math.round(255 * (l <= 0.0031308 ? l * 12.92 : 1.055 * l ** (1 / 2.4) - 0.055)); };
    const B = {
      normal: (b, s) => s, multiply: (b, s) => b * s, screen: (b, s) => b + s - b * s,
      overlay: (b, s) => b <= 0.5 ? s * 2 * b : s + (2 * b - 1) - s * (2 * b - 1),
    };
    // ייחוס: backdrop אטום, source עם אלפא ושקיפות שכבה
    function ref(bg, fg, op, mode) {
      const as = fg[3] / 255 * op;
      return [0, 1, 2].map(i => {
        const cb = lin(bg[i]), cs = lin(fg[i]);
        return enc(as * B[mode](cb, cs) + (1 - as) * cb);
      });
    }
    const { RasterDocument } = await import('./assets/js/engine/doc.js');
    const r = __rs.renderer;
    const out = [];
    const bg = [120, 200, 40, 255];
    for (const mode of ['normal', 'multiply', 'screen', 'overlay']) {
      for (const [fg, op] of [[[200, 60, 180, 255], 1], [[200, 60, 180, 128], 1], [[30, 240, 90, 255], 0.5]]) {
        const d = new RasterDocument(300, 260);
        r.fill(d, d.addLayer(), bg);
        const top = d.addLayer({ blend: mode, opacity: op });
        r.fill(d, top, fg);
        const got = Array.from(r.readPixels(d, 290, 250, 1, 1));
        const exp = ref(bg, fg, op, mode);
        const err = Math.max(...exp.map((v, i) => Math.abs(v - got[i])));
        out.push({ mode, fg: fg.join(), op, got: got.join(), exp: exp.join(), ok: err <= 1 && got[3] === 255 });
      }
    }
    // שכבה מוסתרת — לא משפיעה
    const d = new RasterDocument(10, 10);
    r.fill(d, d.addLayer(), bg);
    const hid = d.addLayer({ visible: false });
    r.fill(d, hid, [0, 0, 0, 255]);
    const hidden = Array.from(r.readPixels(d, 5, 5, 1, 1)).join() === bg.join();
    // שינוי אטימות אחרי הרכבה — מתעדכן אחרי invalidate
    hid.visible = true; hid.opacity = 0; r.invalidate(d);
    const zero = Array.from(r.readPixels(d, 5, 5, 1, 1)).join() === bg.join();
    hid.opacity = 1; r.invalidate(d);
    const black = Array.from(r.readPixels(d, 5, 5, 1, 1)).join() === '0,0,0,255';
    return { out, hidden, zero, black };
  });
  for (const b of blends.out) check(`מיזוג ${b.mode}, ${b.fg} באטימות ${b.op}`, b.ok, `קיבלתי ${b.got}, ציפיתי ${b.exp}`);
  check('שכבה מוסתרת — בלי השפעה', blends.hidden);
  check('אטימות 0 ← 1 — ההרכבה מתעדכנת', blends.zero && blends.black);

  // ---- זום והזזה ----
  await page.goto(BASE + '/editor.html?doc=' + id);
  await page.waitForFunction(() => window.__rs?.doc);
  await page.waitForTimeout(100);
  const box = await page.$eval('#stage', el => { const r = el.getBoundingClientRect(); return { x: r.left, y: r.top, w: r.width, h: r.height }; });
  const v0 = await page.evaluate(() => __rs.view);
  check('בהתחלה: התאמה למסך, לא מעל 100%', v0.zoom === 1 && Math.abs(v0.x - (box.w - 600) / 2) < 0.5, JSON.stringify(v0));
  check('תווית הזום', await page.textContent('#zoom-lbl') === '100%');

  // Ctrl+גלגלת: הנקודה שמתחת לסמן נשארת
  const mx = Math.round(box.x + box.w / 2 + 40), my = Math.round(box.y + box.h / 2 - 30);   // העכבר — בפיקסלים שלמים
  const docPt = await page.evaluate(([x, y]) => [(x - __rs.view.x) / __rs.view.zoom, (y - __rs.view.y) / __rs.view.zoom], [mx - box.x, my - box.y]);
  await page.mouse.move(mx, my);
  await page.keyboard.down('Control');
  await page.mouse.wheel(0, -100);
  await page.keyboard.up('Control');
  await page.waitForTimeout(100);
  const v1 = await page.evaluate(() => __rs.view);
  const after = [(mx - box.x - v1.x) / v1.zoom, (my - box.y - v1.y) / v1.zoom];
  check('Ctrl+גלגלת מגדילה סביב הסמן', v1.zoom > 2 && Math.hypot(after[0] - docPt[0], after[1] - docPt[1]) < 0.01, JSON.stringify({ v1, docPt, after }));

  // גלגלת בלי Ctrl — הזזה
  await page.mouse.wheel(0, 50);
  await page.waitForTimeout(100);
  const v2 = await page.evaluate(() => __rs.view);
  check('גלגלת — הזזה בלי זום', v2.zoom === v1.zoom && Math.abs(v2.y - (v1.y - 50)) < 0.01);

  // גרירה בעכבר — הזזה
  await page.mouse.move(mx, my);
  await page.mouse.down();
  await page.mouse.move(mx - 120, my + 70, { steps: 4 });
  await page.mouse.up();
  const v3 = await page.evaluate(() => __rs.view);
  check('גרירה — הזזה', Math.abs(v3.x - (v2.x - 120)) < 0.01 && Math.abs(v3.y - (v2.y + 70)) < 0.01);

  // כפתורים
  await page.click('#fit-btn');
  await page.waitForTimeout(50);
  check('⛶ — חזרה להתאמה', (await page.evaluate(() => __rs.view.zoom)) === 1);
  await page.click('#zoom-in');
  await page.waitForTimeout(50);
  check('+ — המדרגה הבאה (150%)', (await page.textContent('#zoom-lbl')) === '150%');
  await page.click('#zoom-out'); await page.click('#zoom-out');
  await page.waitForTimeout(50);
  check('− − — שתי מדרגות למטה (67%)', (await page.textContent('#zoom-lbl')) === '67%');
  await page.keyboard.press('Control+0');
  await page.waitForTimeout(50);
  check('Ctrl+0 — התאמה', (await page.evaluate(() => __rs.view.zoom)) === 1);

  // צביטה במגע (CDP: שתי אצבעות)
  const cdp = await ctx.newCDPSession(page);
  const cx = box.x + box.w / 2, cy = box.y + box.h / 2;
  const touch = (type, pts) => cdp.send('Input.dispatchTouchEvent', { type, touchPoints: pts.map(([x, y], i) => ({ x, y, id: i })) });
  await touch('touchStart', [[cx - 50, cy], [cx + 50, cy]]);
  for (let k = 1; k <= 5; k++) await touch('touchMove', [[cx - 50 - 10 * k, cy], [cx + 50 + 10 * k, cy]]);
  await touch('touchEnd', []);
  await page.waitForTimeout(100);
  const vp = await page.evaluate(() => __rs.view);
  check('צביטה בשתי אצבעות — זום ×2 (רק המסמך)', Math.abs(vp.zoom - 2) < 0.02, JSON.stringify(vp));
  check('צביטה — הדף עצמו לא הוגדל ולא נגלל', await page.evaluate(() => (visualViewport.scale === 1) && scrollX === 0 && scrollY === 0));

  // ---- תקציב: תמונה גדולה מהתקציב → הצעה להקטין ----
  await page.evaluate(() => { __rs.budget.maxPixels = () => 100000; });
  let dialogText = '';
  page.once('dialog', d => { dialogText = d.message(); d.accept(); });
  const small = await page.evaluate(async b64 => {
    const bytes = Uint8Array.from(atob(b64), c => c.charCodeAt(0));
    await __rs.openAsDocument(new File([bytes], 'גדול.png', { type: 'image/png' }));
    return [__rs.doc.width, __rs.doc.height];
  }, PNG.toString('base64'));
  check('תמונה מעל התקציב — מוצעת הקטנה', /להקטין/.test(dialogText), dialogText);
  check('אחרי הסכמה — הוקטנה לתקציב ובאותו יחס', small[0] * small[1] <= 100000 && Math.abs(small[0] / small[1] - IMG_W / IMG_H) < 0.02, small.join('×'));

  // ---- פתיחה מהספרייה: קובץ מהמכשיר → העורך ----
  await page.goto(BASE + '/index.html');
  await page.waitForSelector('#new-btn:not([disabled])');
  // נתיב בעברית — Playwright לא מעלה אותו; התוכן עצמו, עם השם בעברית — כן
  await Promise.all([page.waitForURL(/editor\.html\?open=pending/), page.setInputFiles('#file', { name: 'בדיקה.png', mimeType: 'image/png', buffer: PNG })]);
  await page.waitForFunction(() => window.__rs?.doc);
  const opened = await page.evaluate(() => [__rs.doc.width, __rs.doc.height, document.querySelector('#title').textContent]);
  check('📂 בספרייה — נפתח בעורך בגודל התמונה', opened[0] === IMG_W && opened[1] === IMG_H && opened[2] === 'בדיקה', opened.join());

  // ---- לוח השכבות (מחשב: לוח בצד) ----
  page.on('dialog', d => d.accept());
  await page.goto(BASE + '/editor.html?doc=' + id);
  await page.waitForFunction(() => window.__rs?.doc);
  const rows = () => page.$$eval('.layer-row', rs => rs.map(r => r.querySelector('.layer-name, .layer-name-input').textContent || r.querySelector('input')?.value));
  const px = (x = 300, y = 200) => page.evaluate(([x, y]) => Array.from(__rs.renderer.readPixels(__rs.doc, x, y, 1, 1)).join(), [x, y]);
  const near = (got, exp) => got.split(',').every((v, i) => Math.abs(v - exp[i]) <= 1);
  check('לוח השכבות גלוי בצד, עם הרקע', await page.isVisible('#layers') && (await rows()).join() === 'רקע' && !(await page.isVisible('#layers-btn')));
  await page.click('[data-act="add"]');
  check('+ שכבה — חדשה, למעלה ופעילה', (await rows()).join() === 'שכבה 2,רקע' &&
    await page.$eval('.layer-row', r => r.getAttribute('aria-selected')) === 'true');
  await page.evaluate(() => { __rs.renderer.fill(__rs.doc, __rs.doc.active, [255, 0, 0, 255]); __rs.panel.render(); });
  check('שכבה אדומה מעל לבן — אדום', near(await px(), [255, 0, 0, 255]));
  const thumbPx = await page.$eval('.layer-row .thumb', c => Array.from(c.getContext('2d').getImageData(c.width >> 1, c.height >> 1, 1, 1).data).join());
  check('התמונה הממוזערת מתעדכנת (אדום)', near(thumbPx, [255, 0, 0, 255]), thumbPx);
  await page.$eval('#layer-opacity', el => { el.value = '50'; el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true })); });
  check('אטימות 50% — חצי אדום על לבן (בלינארי)', near(await px(), [255, 188, 188, 255]), await px());
  check('תווית האטימות', (await page.textContent('#layer-opacity-out')) === '50%');
  check('מצבי מיזוג בעברית', (await page.$$eval('#layer-blend option', o => o.map(x => x.textContent))).join() === 'רגיל,הכפלה,מסך,שכבת-על');
  await page.selectOption('#layer-blend', 'multiply');
  check('בחירת מיזוג — נשמרת בשכבה', await page.evaluate(() => __rs.doc.active.blend) === 'multiply');
  await page.selectOption('#layer-blend', 'normal');
  await page.click('.layer-row .eye');
  check('👁️ — הסתרה: חוזר ללבן', near(await px(), [255, 255, 255, 255]));
  await page.click('.layer-row .eye');
  await page.$eval('#layer-opacity', el => { el.value = '100'; el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true })); });
  await page.click('[data-act="duplicate"]');
  const dup = await page.evaluate(() => ({ n: __rs.doc.layers.length, name: __rs.doc.active.name, tiles: __rs.doc.active.tiles.size, src: __rs.doc.layers[1].tiles.size }));
  check('⧉ שכפול — עותק מעל, עם כל האריחים', dup.n === 3 && dup.name === 'שכבה 2 עותק' && dup.tiles === dup.src && dup.tiles > 0, JSON.stringify(dup));
  await page.evaluate(() => { __rs.doc.layers[1].visible = false; __rs.renderer.invalidate(__rs.doc); });
  check('העותק לבדו — אותם פיקסלים', near(await px(), [255, 0, 0, 255]));
  await page.evaluate(() => { __rs.doc.layers[1].visible = true; __rs.renderer.invalidate(__rs.doc); __rs.panel.render(); });
  check('▲ כבוי בשכבה העליונה', await page.$eval('[data-act="up"]', b => b.disabled));
  await page.click('[data-act="down"]');
  check('▼ — יורדת אחת', (await rows()).join() === 'שכבה 2,שכבה 2 עותק,רקע');
  await page.click('.layer-row[aria-selected="true"] .layer-name');
  await page.fill('.layer-name-input', 'אדום');
  await page.keyboard.press('Enter');
  check('שינוי שם — לחיצה על שם הפעילה', (await rows()).join() === 'שכבה 2,אדום,רקע');
  await page.click('.layer-row[aria-selected="true"] .lock');
  check('🔒 נעילה — מחיקה ואטימות כבויות', await page.$eval('[data-act="remove"]', b => b.disabled) && await page.$eval('#layer-opacity', b => b.disabled));
  await page.click('.layer-row[aria-selected="true"] .lock');
  await page.click('[data-act="remove"]');
  check('🗑️ מחיקה (אחרי אישור) — האריחים שוחררו', (await rows()).join() === 'שכבה 2,רקע');
  // גרירה: הרקע (למטה) אל מעל העליונה
  const hBox = await page.$eval('.layer-row:last-child .drag', el => { const r = el.getBoundingClientRect(); return [r.x + r.width / 2, r.y + r.height / 2]; });
  const topBox = await page.$eval('.layer-row:first-child', el => { const r = el.getBoundingClientRect(); return [r.x + r.width / 2, r.y + 4]; });
  await page.mouse.move(...hBox);
  await page.mouse.down();
  await page.mouse.move(topBox[0], topBox[1], { steps: 6 });
  await page.mouse.up();
  check('⋮⋮ גרירה — הרקע עבר למעלה', (await rows()).join() === 'רקע,שכבה 2');
  check('אחרי הגרירה — הלבן מכסה את האדום', near(await px(), [255, 255, 255, 255]));
  await page.click('[data-act="add"]'); await page.click('[data-act="add"]');
  check('מונה השכבות', await page.$eval('#layers-btn', b => b.dataset.layerCount) === '4');
  if (SHOTS) await page.screenshot({ path: join(SHOTS, 'layers-desktop.png') });

  check('אין שגיאות בקונסול', errors.length === 0, errors.join(' | '));

  // ---- טלפון ----
  for (const [w, dark] of [[390, false], [320, false], [390, true]]) {
    const ph = await browser.newPage({ viewport: { width: w, height: 760 }, colorScheme: dark ? 'dark' : 'light', hasTouch: true, isMobile: true });
    const errs = [];
    ph.on('pageerror', e => errs.push(e.message));
    await ph.goto(BASE + '/editor.html?doc=' + id);
    await ph.waitForFunction(() => window.__rs?.doc);
    await ph.waitForTimeout(200);
    const r = await ph.evaluate(() => ({
      over: document.documentElement.scrollWidth > innerWidth,
      zoom: __rs.view.zoom,
      bar: document.querySelector('.bar').getBoundingClientRect().bottom <= innerHeight + 0.5,
      hint: document.querySelector('#hint').textContent,
      hintShown: !!document.querySelector('#hint').offsetWidth,
    }));
    await ph.click('#layers-btn');
    const sheet = await ph.evaluate(() => {
      const r = document.querySelector('#layers').getBoundingClientRect();
      return { open: r.height > 100, inside: r.bottom <= innerHeight + 0.5 && r.left >= -0.5 && r.right <= innerWidth + 0.5, rows: document.querySelectorAll('.layer-row').length };
    });
    if (SHOTS) await ph.screenshot({ path: join(SHOTS, `layers-${w}${dark ? '-dark' : ''}.png`) });
    await ph.click('.panel-close');
    const closed = !(await ph.isVisible('#layers'));
    const fits = await ph.evaluate(() => {
      const bar = document.querySelector('.bar');
      return bar.scrollWidth <= bar.clientWidth + 0.5 && document.scrollingElement.scrollLeft === 0 && document.body.scrollLeft === 0
        && [...bar.children].every(c => { const r = c.getBoundingClientRect(); return !r.width || (r.left >= -0.5 && r.right <= innerWidth + 0.5); });
    });
    check(`טלפון ${w}px${dark ? ' כהה' : ''}: הסרגל התחתון נכנס כולו, גם אחרי לחיצה`, fits);
    const tag = `${w}px${dark ? ' כהה' : ''}`;
    check(`טלפון ${tag}: "שכבות" פותח גיליון בתוך המסך, ✕ סוגר`, sheet.open && sheet.inside && sheet.rows === 1 && closed, JSON.stringify(sheet));
    check(`טלפון ${tag}: בלי גלילה אופקית, הסרגל בתוך המסך`, !r.over && r.bar);
    check(`טלפון ${tag}: המסמך מותאם לרוחב`, r.zoom < 1 && r.zoom > 0.4, String(r.zoom));
    check(`טלפון ${tag}: רמז מגע — מוסתר כשאין לו מקום`, /צביטה/.test(r.hint) && !r.hintShown, r.hint);
    check(`טלפון ${tag}: בלי שגיאות`, errs.length === 0, errs.join(' | '));
    if (SHOTS) await ph.screenshot({ path: join(SHOTS, `editor-${w}${dark ? '-dark' : ''}.png`) });
    await ph.close();
  }
  if (SHOTS) {
    const ph = await browser.newPage({ viewport: { width: 390, height: 760 }, hasTouch: true, isMobile: true });
    await ph.goto(BASE + '/editor.html?doc=' + id);
    await ph.waitForFunction(() => window.__rs?.doc);
    await ph.evaluate(async b64 => {
      const bytes = Uint8Array.from(atob(b64), c => c.charCodeAt(0));
      await __rs.addImageLayer(new File([bytes], 'שכבה.png', { type: 'image/png' }));
    }, PNG.toString('base64'));
    await ph.waitForTimeout(300);
    await ph.screenshot({ path: join(SHOTS, 'editor-390-layer.png') });
    await ph.goto(BASE + '/index.html');
    await ph.waitForSelector('.doc-row');
    await ph.screenshot({ path: join(SHOTS, 'library-390.png') });
  }
  await browser.close();
}

try {
  await main();
} catch (e) {
  fails++;
  console.log('✗ קריסה: ' + (e.stack || e));
} finally {
  server.kill();
  rmSync(tmp, { recursive: true, force: true });
}
console.log(`\n${n - fails} / ${n} עברו`);
process.exit(fails ? 1 : 0);
