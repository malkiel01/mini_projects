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
// המנוע שנבדק: RS_RENDERER=webgpu מפעיל את WebGPU ב-Chromium (בלי הדגל — אין מתאם, והעורך נופל ל-WebGL2)
const MODE = process.env.RS_RENDERER === 'webgpu' ? 'webgpu' : 'webgl2';
const ENGINE = MODE === 'webgpu' ? 'WebGPU' : 'WebGL2';
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
  env: { ...process.env, RASTER_STUDIO_DB: join(tmp, 'db.sqlite'), RASTER_STUDIO_FILES: join(tmp, 'files') }, stdio: 'ignore',
});

async function waitServer() {
  for (let i = 0; i < 50; i++) {
    try { if ((await fetch(BASE + '/index.html')).ok) return; } catch { /* עוד לא */ }
    await new Promise(r => setTimeout(r, 100));
  }
  throw new Error('php -S לא עלה');
}

// כל קטע מתחיל ממסמך נקי: הטיוטה במכשיר (IndexedDB) נמחקת, ואז נפתח העורך
let id;
async function openEd(pg) {
  await pg.goto(BASE + '/index.html');
  await pg.evaluate(() => new Promise(r => { const q = indexedDB.deleteDatabase('raster-studio'); q.onsuccess = q.onerror = q.onblocked = () => r(); }));
  await pg.goto(BASE + '/editor.html?doc=' + id);
}

async function main() {
  await waitServer();
  // WebGPU בלי כרטיס: SwiftShader דרך Vulkan; בלי --disable-gpu-compositing אין שכבת הצגה לקנבס של WebGPU,
  // והמכשיר נהרס בציור הראשון ("Could not find SharedImageBackingFactory")
  const browser = await chromium.launch(MODE === 'webgpu' ? {
    args: ['--enable-unsafe-webgpu', '--enable-unsafe-swiftshader', '--use-angle=swiftshader', '--enable-features=Vulkan', '--use-vulkan=swiftshader', '--disable-gpu-compositing'],
  } : {});
  const ctx = await browser.newContext({ viewport: { width: 1000, height: 760 }, hasTouch: true });
  const page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', e => errors.push(e.message));
  page.on('console', m => m.type() === 'error' && errors.push(m.text()));
  if (process.env.RS_DEBUG) page.on('console', m => console.log('[console]', m.type(), m.text()));

  // ---- מסמך מהספרייה ----
  await page.goto(BASE + '/index.html');
  await page.waitForSelector('#new-btn:not([disabled])');
  id = await page.evaluate(async () => {
    const r = await fetch('api.php', { method: 'POST', headers: { 'X-Raster-Studio': '1' },
      body: JSON.stringify({ action: 'doc_create', title: 'בדיקה', size: 'custom', px: ['600', '400'] }) });
    return (await r.json()).doc.id;
  });
  await page.reload();
  await page.waitForSelector('.doc-row a');
  check('בספרייה: שורה שמובילה לעורך', await page.$eval('.doc-row a', a => a.getAttribute('href')) === 'editor.html?doc=' + id);

  await openEd(page);
  await page.waitForFunction(() => window.__rs?.doc);
  const info = await page.evaluate(async () => ({
    name: __rs.renderer.name, w: __rs.doc.width, h: __rs.doc.height, title: document.querySelector('#title').textContent,
    px: Array.from(await __rs.renderer.readPixels(__rs.doc, 599, 399, 1, 1)), msg: document.querySelector('#msg').hidden,
  }));
  check(`העורך עולה עם ${ENGINE}`, info.name === ENGINE, info.name);
  await page.waitForFunction(() => __rs.perf?.score > 0, null, { timeout: 8000 }).catch(() => {});
  const perf = await page.evaluate(() => ({ p: __rs.perf, title: document.querySelector('#meta').title, f: __rs.budget.perf }));
  check('מדידת ביצועים — נמדדה, נשמרה, ומופיעה בכותרת', perf.p?.score > 0 && /ביצועים: (מהיר|בינוני|איטי)/.test(perf.title) && perf.f >= 0.5 && perf.f <= 1, JSON.stringify(perf));
  await page.reload();
  await page.waitForFunction(() => window.__rs?.doc);
  await page.waitForTimeout(300);
  check('בפתיחה הבאה — מהמטמון, בלי מדידה חוזרת', await page.evaluate(p => __rs.perf?.at === p.at, perf.p));
  check('גודל ושם מהשרת', info.w === 600 && info.h === 400 && info.title === 'בדיקה');
  check('רקע לבן אטום עד הפיקסל האחרון', info.px.join() === '255,255,255,255', info.px.join());
  check('אין הודעת שגיאה על הבמה', info.msg);

  // ---- הלוך-חזור: PNG → sRGB → לינארי float16 → sRGB, פיקסל-פיקסל ----
  const round = await page.evaluate(async ([b64, PAT, W, H]) => {
    const bytes = Uint8Array.from(atob(b64), c => c.charCodeAt(0));
    await __rs.openAsDocument(new File([bytes], 'בדיקה.png', { type: 'image/png' }));
    const f = eval(PAT);
    const got = await __rs.renderer.readPixels(__rs.doc, 0, 0, W, H);
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
        const got = Array.from(await r.readPixels(d, 290, 250, 1, 1));
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
    const hidden = Array.from(await r.readPixels(d, 5, 5, 1, 1)).join() === bg.join();
    // שינוי אטימות אחרי הרכבה — מתעדכן אחרי invalidate
    hid.visible = true; hid.opacity = 0; r.invalidate(d);
    const zero = Array.from(await r.readPixels(d, 5, 5, 1, 1)).join() === bg.join();
    hid.opacity = 1; r.invalidate(d);
    const black = Array.from(await r.readPixels(d, 5, 5, 1, 1)).join() === '0,0,0,255';
    return { out, hidden, zero, black };
  });
  for (const b of blends.out) check(`מיזוג ${b.mode}, ${b.fg} באטימות ${b.op}`, b.ok, `קיבלתי ${b.got}, ציפיתי ${b.exp}`);
  check('שכבה מוסתרת — בלי השפעה', blends.hidden);
  check('אטימות 0 ← 1 — ההרכבה מתעדכנת', blends.zero && blends.black);

  // ---- זום והזזה ----
  await openEd(page);
  await page.waitForFunction(() => window.__rs?.doc);
  await page.waitForTimeout(100);
  await page.click('.tool[data-tool="hand"]');   // גרירה = הזזה (ברירת המחדל — מכחול)
  const box = await page.$eval('#stage', el => { const r = el.getBoundingClientRect(); return { x: r.left, y: r.top, w: r.width, h: r.height }; });
  const v0 = await page.evaluate(() => __rs.view);
  // באמצע השטח הפנוי — בין פס הכלים לצד השני, ומתחת ללוח האפשרויות
  const free = await page.evaluate(() => {
    const st = document.querySelector('#stage').getBoundingClientRect(), t = document.querySelector('#tools').getBoundingClientRect();
    const o = document.querySelector('#tool-opts').getBoundingClientRect();
    return { r: t.left - st.left, top: o.bottom - st.top };
  });
  check('בהתחלה: התאמה למסך, לא מעל 100%, באמצע השטח הפנוי', v0.zoom === 1 && Math.abs(v0.x - (free.r - 600) / 2) < 0.5 && v0.y >= free.top, JSON.stringify([v0, free]));
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
  await page.click('.tool[data-tool="brush"]');   // צביטה גם כשכלי ציור פעיל
  const cdp = await ctx.newCDPSession(page);
  const cx = box.x + box.w / 2, cy = box.y + box.h / 2;
  const touch = (type, pts) => cdp.send('Input.dispatchTouchEvent', { type, touchPoints: pts.map(([x, y], i) => ({ x, y, id: i })) });
  await touch('touchStart', [[cx - 50, cy], [cx + 50, cy]]);
  for (let k = 1; k <= 5; k++) await touch('touchMove', [[cx - 50 - 10 * k, cy], [cx + 50 + 10 * k, cy]]);
  await touch('touchEnd', []);
  await page.waitForTimeout(100);
  const vp = await page.evaluate(() => __rs.view);
  check('צביטה בשתי אצבעות — זום ×2 (רק המסמך)', Math.abs(vp.zoom - 2) < 0.02, JSON.stringify(vp));
  check('צביטה — המשיכה שהתחילה באצבע הראשונה בוטלה, בלי עקבות', await page.evaluate(() => !__rs.doc._stroke && !__rs.history.canUndo));
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
  await page.waitForTimeout(500);
  check('הכתובת עוברת ל-?draft=local (טעינה מחדש תשחזר)', page.url().endsWith('editor.html?draft=local'), page.url());
  await page.evaluate(() => __rs.flushDraft());
  await page.reload();
  await page.waitForFunction(() => window.__rs?.doc, null, { timeout: 8000 }).catch(() => {});
  check('טעינה מחדש של קובץ מהמכשיר — משוחזר מהטיוטה', await page.evaluate(() => __rs.doc && __rs.doc.width) === IMG_W);

  // ---- לוח השכבות (מחשב: לוח בצד) ----
  page.on('dialog', d => d.accept());
  await openEd(page);
  await page.waitForFunction(() => window.__rs?.doc);
  const rows = () => page.$$eval('.layer-row', rs => rs.map(r => r.querySelector('.layer-name, .layer-name-input').textContent || r.querySelector('input')?.value));
  // קודם מחכים לכלים (דלי / טפטפת / הזזה קוראים מהכרטיס — ב-WebGPU זה לוקח זמן אמיתי)
  const px = (x = 300, y = 200) => page.evaluate(async ([x, y]) => { await __rs.tools.settle(); return Array.from(await __rs.renderer.readPixels(__rs.doc, x, y, 1, 1)).join(); }, [x, y]);
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
  check('🗑️ מחיקה — בלי שאלה (יש ביטול)', (await rows()).join() === 'שכבה 2,רקע');
  await page.keyboard.press('Control+z');
  check('Ctrl+Z — השכבה שנמחקה חוזרת, עם התוכן', (await rows()).join() === 'שכבה 2,אדום,רקע' && near(await px(), [255, 0, 0, 255]));
  await page.keyboard.press('Control+Shift+z');
  check('Ctrl+Shift+Z — נמחקת שוב', (await rows()).join() === 'שכבה 2,רקע');
  // גרירה: הרקע (למטה) אל מעל העליונה
  const hBox = await page.$eval('.layer-row:last-child .drag', el => { const r = el.getBoundingClientRect(); return [r.x + r.width / 2, r.y + r.height / 2]; });
  const topBox = await page.$eval('.layer-row:first-child', el => { const r = el.getBoundingClientRect(); return [r.x + r.width / 2, r.y + 4]; });
  await page.mouse.move(...hBox);
  await page.mouse.down();
  await page.mouse.move(topBox[0], topBox[1], { steps: 6 });
  await page.mouse.up();
  check('⋮⋮ גרירה — הרקע עבר למעלה', (await rows()).join() === 'רקע,שכבה 2', (await rows()).join());
  await page.keyboard.press('Control+z');
  check('ביטול גרירה — הסדר חוזר', (await rows()).join() === 'שכבה 2,רקע');
  await page.keyboard.press('Control+y');
  check('Ctrl+Y — ביצוע מחדש', (await rows()).join() === 'רקע,שכבה 2');
  check('אחרי הגרירה — הלבן מכסה את האדום', near(await px(), [255, 255, 255, 255]));
  await page.click('[data-act="add"]'); await page.click('[data-act="add"]');
  check('מונה השכבות', await page.$eval('#layers-btn', b => b.dataset.layerCount) === '4');
  const stageL = await page.$eval('#stage', el => el.getBoundingClientRect().left);
  await page.click('.layer-row:last-child .layer-name');
  await page.evaluate(() => { __rs.doc.active.name = 'שם ארוך מאוד מאוד מאוד שלא נכנס בשורה אחת בכלל'; __rs.panel.render(); });
  check('הבמה לא זזה כשתוכן הלוח משתנה', await page.$eval('#stage', el => el.getBoundingClientRect().left) === stageL);
  if (SHOTS) await page.screenshot({ path: join(SHOTS, 'layers-desktop.png') });
  // ---- ביטול / ביצוע ----
  await openEd(page);
  await page.waitForFunction(() => window.__rs?.doc);
  check('בהתחלה — אין מה לבטל', await page.$eval('#undo-btn', b => b.disabled) && await page.$eval('#redo-btn', b => b.disabled));
  await page.click('[data-act="add"]');
  check('אחרי פעולה — ↷ פעיל, עם שם הפעולה', !(await page.$eval('#undo-btn', b => b.disabled)) && (await page.$eval('#undo-btn', b => b.title)) === 'ביטול: שכבה חדשה');
  // פיקסלים דרך ההיסטוריה: צילום "לפני", מילוי, רשומה — ביטול מחליף מרקמים
  const swap = await page.evaluate(async () => {
    const { doc, renderer: r, history: h } = __rs;
    const l = doc.active;
    const before = r.snapshot(l, [...new Set([...l.tiles.keys(), '0,0', '1,0', '2,0', '0,1', '1,1', '2,1'])]);
    r.fill(doc, l, [0, 0, 255, 255]);
    h.pixels(doc, l, before, 'מילוי');
    const p = async () => Array.from(await r.readPixels(doc, 10, 10, 1, 1)).join();
    const after = await p();
    h.undo(); const undone = await p();
    h.redo(); const redone = await p();
    h.undo();
    return { after, undone, redone };
  });
  check('פיקסלים: מילוי ← ביטול ← ביצוע', swap.after === '0,0,255,255' && swap.undone === '255,255,255,255' && swap.redone === '0,0,255,255', JSON.stringify(swap));
  await page.selectOption('#layer-blend', 'screen');
  await page.$eval('#layer-opacity', el => { for (const v of ['80', '60', '40']) { el.value = v; el.dispatchEvent(new Event('input', { bubbles: true })); } el.dispatchEvent(new Event('change', { bubbles: true })); });
  check('גרירת אטימות — רשומה אחת', await page.evaluate(() => __rs.history.undoStack.map(e => e.label).join()) === 'שכבה חדשה,מילוי,מצב מיזוג,אטימות'.replace('מילוי,', ''), await page.evaluate(() => __rs.history.undoStack.map(e => e.label).join()));
  await page.click('#undo-btn');
  check('↷ — האטימות חוזרת ל-100%', await page.evaluate(() => __rs.doc.active.opacity) === 1 && (await page.textContent('#layer-opacity-out')) === '100%');
  await page.click('#undo-btn');
  check('↷ — המיזוג חוזר לרגיל', await page.evaluate(() => __rs.doc.active.blend) === 'normal' && (await page.$eval('#layer-blend', s => s.value)) === 'normal');
  await page.click('#undo-btn');
  check('↷ — השכבה החדשה בוטלה', (await rows()).join() === 'רקע' && await page.$eval('#undo-btn', b => b.disabled));
  await page.click('#redo-btn'); await page.click('#redo-btn');
  check('↶ ↶ — חוזרים קדימה', (await rows()).join() === 'שכבה 2,רקע' && await page.evaluate(() => __rs.doc.active.blend) === 'screen');
  await page.click('.layer-row[aria-selected="true"] .layer-name');
  await page.fill('.layer-name-input', 'ניסיון');
  await page.keyboard.press('Control+z');   // בתוך השדה — לא מבטל פעולות בקנבס
  check('Ctrl+Z בתוך שדה השם — לא נוגע בהיסטוריה', (await page.evaluate(() => __rs.history.undoStack.length)) === 2);
  await page.keyboard.press('Escape');


  // ---- כלי ציור ----
  await openEd(page);
  await page.waitForFunction(() => window.__rs?.doc);
  await page.evaluate(() => __rs.setView({ zoom: 1, x: 20, y: 20 }));
  const sb = await page.$eval('#stage', el => { const r = el.getBoundingClientRect(); return [r.left, r.top]; });
  const at = (x, y) => [sb[0] + 20 + x, sb[1] + 20 + y];   // נקודה במסמך → מסך (זום 100%)
  const setOpt = (id, v) => page.$eval('#opt-' + id, (el, v) => { el.value = String(v); el.dispatchEvent(new Event('input', { bubbles: true })); }, v);
  const stroke = async pts => {
    await page.mouse.move(...at(...pts[0]));
    await page.mouse.down();
    for (const p of pts.slice(1)) await page.mouse.move(...at(...p), { steps: 8 });
    await page.mouse.up();
  };
  const def = await page.evaluate(() => [document.querySelector('.tool[data-tool="brush"]').getAttribute('aria-pressed'), getComputedStyle(document.querySelector('#opt-size')).display, document.querySelector('#opt-size').closest('[data-for]').hidden].join());
  check('ברירת מחדל — מכחול, ולוח אפשרויות', /^true,[a-z-]+,false$/.test(def) && await page.isVisible('#opt-size'), def);
  await page.$eval('#color', el => { el.value = '#ff0000'; el.dispatchEvent(new Event('input', { bubbles: true })); });
  await setOpt('size', 48);   // ≈ 20px
  await setOpt('hardness', 100);
  await setOpt('opacity', 100);
  const sz = await page.evaluate(() => __rs.tools.state.size);
  check('מחוון גודל לוגריתמי', sz >= 18 && sz <= 22 && (await page.textContent('output[for="opt-size"]')) === sz + 'px', String(sz));
  await stroke([[100, 100], [300, 100]]);
  check('מכחול: קו אדום', near(await px(200, 100), [255, 0, 0, 255]) && near(await px(200, 140), [255, 255, 255, 255]), await px(200, 100));
  check('מכחול: רשומה אחת בהיסטוריה', await page.evaluate(() => __rs.history.undoStack.map(e => e.label).join()) === 'מכחול');
  await page.keyboard.press('Control+z');
  check('ביטול משיכה — חוזר ללבן', near(await px(200, 100), [255, 255, 255, 255]));
  await page.keyboard.press('Control+Shift+z');
  check('ביצוע — הקו חוזר', near(await px(200, 100), [255, 0, 0, 255]));
  // משיכה באטימות 50% שחוצה את עצמה — לא מתעבה (מסכה עם מקסימום)
  await setOpt('opacity', 50);
  await page.$eval('#color', el => { el.value = '#0000ff'; el.dispatchEvent(new Event('input', { bubbles: true })); });
  await stroke([[150, 250], [350, 250], [350, 300], [250, 300], [250, 200]]);
  const cross = await px(250, 250), single = await px(300, 250);
  check('אטימות 50%: חצי כחול על לבן', near(single, [188, 188, 255, 255]), single);
  check('קו שחוצה את עצמו — לא מתעבה', cross === single, cross + ' / ' + single);
  // מחק על שכבה
  await page.click('[data-act="add"]');
  await page.evaluate(() => { __rs.renderer.fill(__rs.doc, __rs.doc.active, [0, 160, 0, 255]); __rs.renderer.invalidate(__rs.doc); });
  await page.keyboard.press('e');
  check('E — מחק', await page.evaluate(() => __rs.tools.state.tool) === 'eraser');
  await setOpt('opacity', 100);
  await stroke([[400, 300], [500, 300]]);
  check('מחק: השכבה העליונה נמחקה — רואים את הלבן שמתחת', near(await px(450, 300), [255, 255, 255, 255]) && near(await px(450, 350), [0, 160, 0, 255]), await px(450, 300));
  // נעילה
  await page.click('.layer-row[aria-selected="true"] .lock');
  const n0 = await page.evaluate(() => __rs.history.undoStack.length);
  await stroke([[400, 50], [500, 50]]);
  check('שכבה נעולה — לא מציירים, והודעה', await page.evaluate(() => __rs.history.undoStack.length) === n0 && /נעולה/.test(await page.textContent('.toast')));
  await page.click('.layer-row[aria-selected="true"] .lock');
  // דלי: רקע עם קו אופקי לכל הרוחב — מילוי רק מעל הקו
  await page.click('[data-act="remove"]');
  await page.keyboard.press('b');
  await page.$eval('#color', el => { el.value = '#000000'; el.dispatchEvent(new Event('input', { bubbles: true })); });
  await setOpt('size', 40);
  await stroke([[2, 180], [598, 180]]);   // הרדיוס (20px) מכסה עד הקצוות
  check('הקו מגיע עד הנקודה האחרונה (גם כשהתנועה האחרונה מגיעה רק עם ההרמה)', near(await px(598, 180), [0, 0, 0, 255]), await px(598, 180));
  await page.keyboard.press('g');
  check('G — דלי, עם סבולת', await page.isVisible('#opt-tolerance') && !(await page.isVisible('#opt-size')));
  await page.$eval('#color', el => { el.value = '#00ff00'; el.dispatchEvent(new Event('input', { bubbles: true })); });
  await page.mouse.click(...at(30, 120));   // לא בראש הבמה — שם לוח האפשרויות
  check('דלי: מעל הקו — ירוק', near(await px(20, 20), [0, 255, 0, 255]) && near(await px(590, 120), [0, 255, 0, 255]), await px(20, 20));
  check('דלי: מתחת לקו — לא נגע', !near(await px(20, 380), [0, 255, 0, 255]), await px(20, 380));
  check('דלי: רשומה בהיסטוריה', await page.evaluate(() => __rs.history.undoStack.at(-1).label) === 'דלי');
  // טפטפת
  await page.keyboard.press('i');
  await page.mouse.click(...at(20, 380));
  const picked = await page.$eval('#color', el => el.value);
  check('I — טפטפת: הצבע מהתמונה (לבן מתחת לקו)', picked === '#ffffff', picked);
  await page.mouse.click(...at(30, 120));
  check('טפטפת על הירוק', await page.$eval('#color', el => el.value) === '#00ff00');
  // [ ] — גודל
  await page.keyboard.press('b');
  const s0 = await page.evaluate(() => __rs.tools.state.size);
  await page.keyboard.press(']');
  check('] — מגדיל את המכחול', (await page.evaluate(() => __rs.tools.state.size)) > s0);
  // מגע: אצבע אחת מציירת
  const cdp2 = await ctx.newCDPSession(page);
  const t2 = (type, pts) => cdp2.send('Input.dispatchTouchEvent', { type, touchPoints: pts.map(([x, y], i) => ({ x, y, id: i })) });
  const nT = await page.evaluate(() => __rs.history.undoStack.length);
  await t2('touchStart', [at(100, 330)]);
  for (let k = 1; k <= 6; k++) await t2('touchMove', [at(100 + 30 * k, 330)]);
  await t2('touchEnd', []);
  await page.waitForTimeout(100);
  check('מגע: אצבע אחת מציירת', (await page.evaluate(() => __rs.history.undoStack.length)) === nT + 1 && near(await px(200, 330), [0, 255, 0, 255]), await px(200, 330));
  if (SHOTS) await page.screenshot({ path: join(SHOTS, 'paint-desktop.png') });

  // ---- בחירות ----
  await openEd(page);
  await page.waitForFunction(() => window.__rs?.doc);
  await page.evaluate(() => __rs.setView({ zoom: 1, x: 20, y: 20 }));
  const drag = async (a, b, mods = []) => {
    for (const m of mods) await page.keyboard.down(m);
    await page.mouse.move(...at(...a)); await page.mouse.down();
    await page.mouse.move(...at(...b), { steps: 6 }); await page.mouse.up();
    for (const m of mods) await page.keyboard.up(m);
  };
  const color = c => page.$eval('#color', (el, c) => { el.value = c; el.dispatchEvent(new Event('input', { bubbles: true })); }, c);
  await page.keyboard.press('m');
  check('M — בחירה מלבנית, עם מצב ופעולות', await page.evaluate(() => __rs.tools.state.tool) === 'rect' && await page.isVisible('[data-sel="invert"]'));
  await drag([100, 120], [300, 220]);
  const sel1 = await page.evaluate(() => __rs.doc.selection?.shapes[0]);
  check('גרירה — מלבן בפיקסלים שלמים', sel1 && sel1.x === 100 && sel1.y === 120 && sel1.w === 200 && sel1.h === 100, JSON.stringify(sel1));
  check('בחירה — רשומה בהיסטוריה', await page.evaluate(() => __rs.history.undoStack.at(-1).label) === 'בחירה');
  // מכחול רק בתוך הבחירה
  await page.keyboard.press('b');
  await color('#ff0000');
  await setOpt('size', 60); await setOpt('opacity', 100); await setOpt('hardness', 100);
  await stroke([[50, 170], [400, 170]]);
  check('מכחול נחתך לבחירה: בפנים אדום, בחוץ לבן', near(await px(200, 170), [255, 0, 0, 255]) && near(await px(60, 170), [255, 255, 255, 255]) && near(await px(350, 170), [255, 255, 255, 255]), [await px(200, 170), await px(60, 170)].join(' | '));
  // מחיקה ומילוי
  await page.click('[data-act="add"]');
  await page.evaluate(() => { __rs.renderer.fill(__rs.doc, __rs.doc.active, [0, 0, 255, 255]); __rs.renderer.invalidate(__rs.doc); });
  await page.keyboard.press('Delete');
  check('Delete — הבחירה נמחקה מהשכבה', near(await px(200, 170), [255, 0, 0, 255]) && near(await px(60, 170), [0, 0, 255, 255]));
  await color('#00ff00');
  await page.keyboard.press('Alt+Backspace');
  check('Alt+Backspace — מילוי הבחירה בצבע', near(await px(200, 140), [0, 255, 0, 255]) && near(await px(60, 140), [0, 0, 255, 255]));
  // היפוך
  await page.keyboard.press('Control+Shift+i');
  check('Ctrl+Shift+I — בחירה הפוכה', await page.evaluate(() => __rs.doc.selection.inverted) === true);
  await page.keyboard.press('Delete');
  check('מחיקה בהפוכה — מה שבחוץ נמחק, מה שבפנים נשאר', near(await px(60, 140), [255, 255, 255, 255]) && near(await px(200, 140), [0, 255, 0, 255]));
  await page.keyboard.press('Control+z'); await page.keyboard.press('Control+z');
  check('ביטול ×2 — חוזרים לבחירה הרגילה, הפיקסלים חזרו', await page.evaluate(() => __rs.doc.selection.inverted) === false && near(await px(60, 140), [0, 0, 255, 255]));
  // ביטול בחירה: Ctrl+D, ולחיצה בלי גרירה
  await page.keyboard.press('Control+d');
  check('Ctrl+D — אין בחירה', await page.evaluate(() => __rs.doc.selection) === null);
  await page.keyboard.press('Control+z');
  check('ביטול — הבחירה חוזרת', await page.evaluate(() => !!__rs.doc.selection));
  await page.keyboard.press('m');
  await page.mouse.click(...at(500, 300));
  check('לחיצה בלי גרירה — ביטול הבחירה', await page.evaluate(() => __rs.doc.selection) === null);
  // אליפסה וחיסור
  await page.keyboard.press('m');
  check('M שוב — אליפסה', await page.evaluate(() => __rs.tools.state.tool) === 'ellipse');
  await drag([100, 100], [300, 300]);
  await drag([180, 180], [220, 220], ['Alt']);
  check('Alt+גרירה — חיסור', await page.evaluate(() => __rs.doc.selection.shapes.map(s => s.op).join()) === 'add,sub');
  await color('#ffff00');
  await page.keyboard.press('Alt+Backspace');
  check('אליפסה: המילוי בפנים, הפינה של המלבן החוסם בחוץ, והחור ריק',
    near(await px(150, 200), [255, 255, 0, 255]) && !near(await px(105, 105), [255, 255, 0, 255]) && !near(await px(200, 200), [255, 255, 0, 255]),
    [await px(150, 200), await px(105, 105), await px(200, 200)].join(' | '));
  // לאסו
  await page.keyboard.press('l');
  await page.mouse.move(...at(400, 100)); await page.mouse.down();
  for (const p of [[550, 100], [550, 250], [400, 100]]) await page.mouse.move(...at(...p), { steps: 10 });
  await page.mouse.up();
  const poly = await page.evaluate(() => __rs.doc.selection.shapes[0]);
  check('לאסו — מצולע', poly.type === 'poly' && poly.pts.length > 10, String(poly.pts?.length));
  await color('#ff00ff');
  await page.click('[data-sel="fill"]');
  check('לאסו: מילוי בתוך המשולש בלבד', near(await px(520, 140), [255, 0, 255, 255]) && !near(await px(420, 230), [255, 0, 255, 255]));
  // דלי נחתך לבחירה
  await page.keyboard.press('Control+a');
  check('Ctrl+A — הכול', await page.evaluate(() => { const s = __rs.doc.selection.shapes[0]; return [s.type, s.x, s.y, s.w, s.h, s.op].join(); }) === 'rect,0,0,600,400,add');
  await page.keyboard.press('m');
  await drag([0, 300], [600, 400]);
  await page.keyboard.press('g');
  await color('#008080');
  await setOpt('tolerance', 255);
  await page.mouse.click(...at(30, 350));
  check('דלי עם סבולת מלאה — רק בתוך הבחירה', near(await px(30, 350), [0, 128, 128, 255]) && !near(await px(30, 250), [0, 128, 128, 255]));
  if (SHOTS) {
    await page.keyboard.press('l');
    await page.screenshot({ path: join(SHOTS, 'select-desktop.png') });
  }

  // ---- מטה קסם ----
  await openEd(page);
  await page.waitForFunction(() => window.__rs?.doc);
  await page.evaluate(() => __rs.setView({ zoom: 1, x: 20, y: 20 }));
  await page.click('[data-act="add"]');
  await color('#ff0000');
  await page.keyboard.press('m');
  await drag([100, 100], [200, 200]);
  await drag([400, 100], [500, 200], ['Shift']);
  await page.keyboard.press('Alt+Backspace');
  await page.keyboard.press('Control+d');
  await page.keyboard.press('w');
  check('W — מטה קסם, עם סבולת, רציף וכל השכבות', await page.evaluate(() => __rs.tools.state.tool) === 'wand'
    && await page.isVisible('#opt-tolerance') && await page.isVisible('#opt-contiguous') && await page.isVisible('#opt-sample-all'));
  await setOpt('tolerance', 10);
  await page.mouse.click(...at(150, 150));
  await page.evaluate(() => __rs.tools.settle());
  const wand1 = await page.evaluate(() => { const s = __rs.doc.selection?.shapes[0]; return s && [s.type, s.x, s.y, s.w, s.h].join(); });
  check('לחיצה — מסכת פיקסלים של הריבוע הרציף בלבד', wand1 === 'mask,100,100,100,100', wand1);
  check('מטה קסם — רשומה בהיסטוריה', await page.evaluate(() => __rs.history.undoStack.at(-1).label) === 'מטה קסם');
  await color('#00ff00');
  await page.keyboard.press('Alt+Backspace');
  check('מילוי בבחירת המטה: הריבוע הנבחר ירוק, השני נשאר אדום, והלבן בחוץ',
    near(await px(150, 150), [0, 255, 0, 255]) && near(await px(450, 150), [255, 0, 0, 255]) && near(await px(250, 150), [255, 255, 255, 255]),
    [await px(150, 150), await px(450, 150), await px(250, 150)].join(' | '));
  await page.keyboard.press('Control+z');
  await page.click('#opt-contiguous');
  await page.mouse.click(...at(150, 150));
  await page.evaluate(() => __rs.tools.settle());
  const wand2 = await page.evaluate(() => { const s = __rs.doc.selection.shapes; return s.length + ':' + [s[0].type, s[0].x, s[0].w].join(); });
  check('בלי "רציף" — טווח צבעים: שני הריבועים, בצורה אחת', wand2 === '1:mask,100,400', wand2);
  await page.click('#opt-contiguous');
  await page.keyboard.down('Alt');
  await page.mouse.click(...at(450, 150));
  await page.keyboard.up('Alt');
  await page.evaluate(() => __rs.tools.settle());
  await page.keyboard.press('Delete');
  check('Alt+לחיצה — חיסור: מחיקה רק בריבוע שנשאר בבחירה', !near(await px(150, 150), [255, 0, 0, 255]) && near(await px(450, 150), [255, 0, 0, 255]),
    [await px(150, 150), await px(450, 150)].join(' | '));
  await page.keyboard.press('Control+z');
  // השכבה הפעילה שקופה מחוץ לריבועים; "כל השכבות" רואה את הרקע הלבן — אותה תוצאה כאן, ורציף מהלבן לא נכנס לאדום
  await page.click('#opt-sample-all');
  await page.mouse.click(...at(300, 300));
  await page.evaluate(() => __rs.tools.settle());
  const wand3 = await page.evaluate(() => { const s = __rs.doc.selection.shapes[0]; return [s.x, s.y, s.w, s.h, s.data[150 * s.w + 150], s.data[0]].join(); });
  check('כל השכבות: הלבן סביב — כל הקנבס, בלי הריבועים', wand3 === '0,0,600,400,0,255', wand3);
  // בחירת מסכה זזה יחד עם התוכן
  await page.keyboard.press('Control+d');
  await page.click('#opt-sample-all');
  await page.mouse.click(...at(150, 150));
  await page.evaluate(() => __rs.tools.settle());
  await page.keyboard.press('v');
  await drag([150, 150], [170, 160]);
  await page.keyboard.press('Enter');
  const wand4 = await page.evaluate(() => { const s = __rs.doc.selection.shapes[0]; return [s.type, s.x, s.y].join(); });
  check('הזזה — מסכת הבחירה זזה עם התוכן', wand4 === 'mask,120,110' && near(await px(210, 150), [255, 0, 0, 255]) && near(await px(110, 150), [255, 255, 255, 255]), wand4);
  if (SHOTS) {
    await page.keyboard.press('w');
    await page.screenshot({ path: join(SHOTS, 'wand-desktop.png') });
  }

  // ---- חותמת שכפול ----
  await openEd(page);
  await page.waitForFunction(() => window.__rs?.doc);
  await page.evaluate(() => __rs.setView({ zoom: 1, x: 20, y: 20 }));
  await page.click('[data-act="add"]');
  await color('#ff0000');
  await page.keyboard.press('m');
  await drag([100, 100], [150, 150]);
  await page.keyboard.press('Alt+Backspace');
  await page.keyboard.press('Control+d');
  await page.keyboard.press('s');
  check('S — חותמת שכפול, עם גודל, קשיות, אטימות ו-🎯', await page.evaluate(() => __rs.tools.state.tool) === 'clone'
    && await page.isVisible('#opt-size') && await page.isVisible('[data-clone="source"]'));
  await setOpt('size', 55); await setOpt('hardness', 100); await setOpt('opacity', 100);
  const undo0 = await page.evaluate(() => __rs.history.undoStack.length);
  await stroke([[325, 125], [330, 125]]);
  check('בלי נקודת מקור — הודעה, ושום שינוי', await page.evaluate(() => __rs.history.undoStack.length) === undo0
    && /נקודת המקור/.test(await page.textContent('.toast')));
  await page.keyboard.down('Alt');
  await page.mouse.click(...at(125, 125));
  await page.keyboard.up('Alt');
  await stroke([[325, 125], [330, 125]]);
  check('Alt+לחיצה ואז משיכה — מועתק מהמקור, באותו מרחק', near(await px(325, 125), [255, 0, 0, 255]) && near(await px(325, 160), [255, 255, 255, 255])
    && near(await px(125, 125), [255, 0, 0, 255]), [await px(325, 125), await px(325, 160)].join(' | '));
  check('חותמת שכפול — רשומה בהיסטוריה', await page.evaluate(() => __rs.history.undoStack.at(-1).label) === 'חותמת שכפול');
  // משיכה שנייה — אותו מרחק (המקור שמאלה ב-200): מעתיקה את מה שהמשיכה הקודמת ציירה
  await stroke([[520, 125], [522, 125]]);
  check('המרחק נשמר בין משיכות', near(await px(521, 125), [255, 0, 0, 255]) && near(await px(521, 160), [255, 255, 255, 255]), await px(521, 125));
  await page.keyboard.press('Control+z');
  // 🎯 (למסך מגע): הקשה קובעת מקור חדש, והמשיכה הבאה מחשבת מרחק חדש
  await page.click('[data-clone="source"]');
  check('🎯 — מחכה להקשה', await page.getAttribute('[data-clone="source"]', 'aria-pressed') === 'true');
  await page.mouse.click(...at(140, 140));
  check('הקשה — המקור נקבע, 🎯 כבה', await page.getAttribute('[data-clone="source"]', 'aria-pressed') === 'false');
  await stroke([[500, 250], [505, 250]]);
  check('מקור חדש — מרחק חדש', near(await px(502, 250), [255, 0, 0, 255]), await px(502, 250));
  // בחירה חותכת גם את החותמת
  await page.keyboard.press('m');
  await drag([400, 300], [200, 0]);   // מתחילים בשטח פנוי — לא על פס הכלים
  await page.keyboard.press('s');
  await page.keyboard.down('Alt');
  await page.mouse.click(...at(140, 125));
  await page.keyboard.up('Alt');
  await stroke([[425, 50], [380, 50]]);
  check('חותמת בתוך בחירה — רק בתוכה', near(await px(390, 50), [255, 0, 0, 255]) && near(await px(420, 50), [255, 255, 255, 255]),
    [await px(390, 50), await px(420, 50)].join(' | '));
  if (SHOTS) await page.screenshot({ path: join(SHOTS, 'clone-desktop.png') });

  // ---- מסכת שכבה ----
  await openEd(page);
  await page.waitForFunction(() => window.__rs?.doc);
  await page.evaluate(() => __rs.setView({ zoom: 1, x: 20, y: 20 }));
  await page.click('[data-act="add"]');
  await page.evaluate(() => { __rs.renderer.fill(__rs.doc, __rs.doc.active, [255, 0, 0, 255]); __rs.renderer.invalidate(__rs.doc); });
  await page.evaluate(() => __rs.tools.setTool('rect'));
  await drag([300, 150], [100, 100]);   // 100..300 × 100..150 — מתחילים בשטח פנוי
  await page.click('[data-act="mask"]');
  const mk1 = await page.evaluate(() => { const l = __rs.doc.active; return [!!l.mask, !!l.editMask, __rs.history.undoStack.at(-1).label].join(); });
  check('🎭 עם בחירה — מסכה מהבחירה, ועוברים לעריכתה', mk1 === 'true,true,מסכה מהבחירה', mk1);
  check('מסכה: בתוך הבחירה — השכבה נראית, מחוצה לה — מוסתרת', near(await px(200, 120), [255, 0, 0, 255]) && near(await px(200, 300), [255, 255, 255, 255]),
    [await px(200, 120), await px(200, 300)].join(' | '));
  check('🎭 — תמונה ממוזערת של המסכה בשורה, ושורת אפשרויות', await page.isVisible('.layer-row[aria-selected="true"] .mask-thumb.editing') && await page.isVisible('.mask-opts'));
  await page.keyboard.press('Control+d');
  // מכחול על המסכה: שחור מסתיר, אפור — חצי
  await page.keyboard.press('b');
  await color('#000000');
  await setOpt('size', 48); await setOpt('hardness', 100); await setOpt('opacity', 100);
  await stroke([[150, 125], [160, 125]]);
  check('מכחול שחור על המסכה — מסתיר; השכבה עצמה לא נגעה', near(await px(155, 125), [255, 255, 255, 255]) && near(await px(250, 125), [255, 0, 0, 255])
    && await page.evaluate(async () => Array.from(await __rs.renderer.readLayerPixels(__rs.doc.active, 155, 125, 1, 1)).join()) === '255,0,0,255');
  check('רשומה: "מכחול (מסכה)"', await page.evaluate(() => __rs.history.undoStack.at(-1).label) === 'מכחול (מסכה)');
  await page.keyboard.press('Control+z');
  check('ביטול — נראה שוב', near(await px(155, 125), [255, 0, 0, 255]));
  await color('#808080');
  await stroke([[250, 125], [255, 125]]);
  const half = (await px(252, 125)).split(',').map(Number);
  check('אפור 50% על המסכה — חצי שקוף (מעל לבן)', half[0] > 250 && half[1] > 150 && half[1] < 215, half.join());
  // מחק על המסכה — מגלה
  await page.keyboard.press('e');
  await stroke([[200, 300], [205, 300]]);
  check('מחק על המסכה — מחזיר את מה שהוסתר', near(await px(202, 300), [255, 0, 0, 255]) && near(await px(202, 330), [255, 255, 255, 255]));
  // השבתה
  await page.click('[data-mask="toggle"]');
  check('השבתה — כל השכבה נראית', near(await px(400, 350), [255, 0, 0, 255]));
  await page.keyboard.press('Control+z');
  check('ביטול ההשבתה — המסכה חוזרת', near(await px(400, 350), [255, 255, 255, 255]));
  // מעבר לעריכת הפיקסלים: המכחול צובע את השכבה
  await page.click('[data-act="mask"]');
  check('🎭 שוב — חזרה לפיקסלים', await page.evaluate(() => __rs.doc.active.editMask) === false && await page.isVisible('.layer-row[aria-selected="true"].editing-pixels'));
  // ‎.mbe: src — מה שרואים, ולצדו המתכון (source, mask, baked); פתיחה חזרה מחזירה את המסכה
  const mb = await page.evaluate(async () => {
    const { open } = await import('./assets/js/mbe/mbe.js');
    const bytes = window.__maskMbe = await __rs.buildMbe();
    const f = await open(bytes);
    const L = f.document.surfaces[0].layers[1];
    const hex = async b => [...new Uint8Array(await crypto.subtle.digest('SHA-256', b))].map(x => x.toString(16).padStart(2, '0')).join('');
    const out = { ok: f.ok, issues: f.issues.filter(i => i.level === 'error').map(i => i.message).join(), keys: ['source', 'mask', 'baked'].every(k => k in L),
      baked: L.baked === await hex(f.getMedia(L.src)), differ: L.src !== L.source, enabled: L.mask.enabled };
    await __rs.openAsDocument(new File([bytes], 'מסכה.mbe'));
    const l = __rs.doc.layers[1];
    out.back = !!l.mask && l.mask.enabled;
    return out;
  });
  check('‎.mbe עם מסכה: תקין, src אפוי, ולצדו source / mask / baked תואם', mb.ok && mb.keys && mb.baked && mb.differ && mb.enabled === true, JSON.stringify(mb));
  check('‎.mbe — פתיחה חזרה: המסכה במקומה, ואותה תמונה', mb.back && near(await px(200, 300), [255, 0, 0, 255]) && near(await px(200, 350), [255, 255, 255, 255])
    && near(await px(200, 120), [255, 0, 0, 255]), [mb.back, await px(200, 350)].join(' | '));
  // מתכון שמישהו ערך בלי להבין (baked לא תואם) — נפתח כרסטר רגיל: src כמו שהוא
  const stale = await page.evaluate(async () => {
    const { open, save } = await import('./assets/js/mbe/mbe.js');
    const f = await open(await __rs.buildMbe());
    f.document.surfaces[0].layers[1].baked = '0'.repeat(64);
    await __rs.openAsDocument(new File([await save(f, { app: 'test' })], 'ערוך.mbe'));
    return !!__rs.doc.layers[1].mask;
  });
  check('baked לא תואם — בלי מסכה, מציגים את src', !stale && near(await px(200, 350), [255, 255, 255, 255]) && near(await px(200, 120), [255, 0, 0, 255]));
  // טיוטה: המסכה נשמרת ומשוחזרת
  await page.evaluate(async () => { await __rs.openAsDocument(new File([window.__maskMbe], 'מסכה.mbe')); await __rs.flushDraft(); });
  await page.reload();
  await page.waitForFunction(() => window.__rs?.doc?.layers[1]?.mask, null, { timeout: 8000 }).catch(() => {});
  check('טיוטה — המסכה משוחזרת', await page.evaluate(() => !!__rs.doc.layers[1]?.mask) && near(await px(200, 350), [255, 255, 255, 255]) && near(await px(200, 300), [255, 0, 0, 255]));
  // ORA: המסכה אפויה בשכבה
  check('ORA — המסכה אפויה (פער מדווח)', await page.evaluate(async () => {
    const { gapsFor } = await import('./assets/js/engine/formats.js');
    return gapsFor('ora', __rs.doc.layers).includes('layer-mask → baked');
  }));
  // חיתוך — המסכה זזה יחד עם השכבה
  // אחרי הטעינה מחדש התצוגה משוחזרת מהטיוטה — חוזרים ל-100%, כדי שהגרירה תהיה בקואורדינטות הצפויות
  await page.evaluate(() => { __rs.setView({ zoom: 1, x: 20, y: 20 }); __rs.doc.activeId = __rs.doc.layers[1].id; __rs.panel.render(); });
  await page.keyboard.press('c');
  await drag([500, 380], [100, 50]);
  await page.keyboard.press('Enter');
  check('חיתוך — המסכה זזה עם השכבה', near(await px(100, 250), [255, 0, 0, 255]) && near(await px(100, 300), [255, 255, 255, 255]), [await px(100, 250), await px(100, 300)].join(' | '));
  await page.keyboard.press('Control+z');
  // החלה: הפיקסלים כפול המסכה, צעד אחד; מחיקה
  await page.click('[data-mask="apply"]');
  check('החלת המסכה — בלי מסכה, והמראה נשאר', await page.evaluate(() => __rs.doc.layers[1].mask) === null && near(await px(200, 350), [255, 255, 255, 255])
    && near(await px(200, 120), [255, 0, 0, 255]));
  await page.keyboard.press('Control+z');
  check('ביטול ההחלה — המסכה והפיקסלים חוזרים', await page.evaluate(() => !!__rs.doc.layers[1].mask) && await page.evaluate(async () => Array.from(await __rs.renderer.readLayerPixels(__rs.doc.layers[1], 200, 350, 1, 1)).join()) === '255,0,0,255');
  await page.click('[data-mask="remove"]');
  check('מחיקת המסכה — כל השכבה נראית', near(await px(200, 350), [255, 0, 0, 255]) && !(await page.isVisible('.mask-opts')));
  if (SHOTS) {
    await page.keyboard.press('Control+z');
    await page.screenshot({ path: join(SHOTS, 'mask-desktop.png') });
  }

  // ---- הזזה ושינוי צורה ----
  await openEd(page);
  await page.waitForFunction(() => window.__rs?.doc);
  await page.evaluate(() => __rs.setView({ zoom: 1, x: 20, y: 20 }));
  await page.click('[data-act="add"]');
  await page.keyboard.press('m');
  await drag([100, 100], [200, 200]);
  await color('#ff0000');
  await page.keyboard.press('Alt+Backspace');
  await page.keyboard.press('Control+d');
  await page.keyboard.press('v');
  check('V — כלי ההזזה, עם החלה וביטול', await page.evaluate(() => __rs.tools.state.tool) === 'move' && await page.isVisible('[data-xf="apply"]'));
  await drag([150, 150], [250, 180]);
  check('גרירה — התוכן צף וזז (לפני החלה)', await page.evaluate(() => __rs.tools.floating) && near(await px(250, 180), [255, 0, 0, 255]) && near(await px(120, 120), [255, 255, 255, 255]));
  await page.keyboard.press('Enter');
  check('Enter — הוחל, רשומת "הזזה"', !(await page.evaluate(() => __rs.tools.floating)) && await page.evaluate(() => __rs.history.undoStack.at(-1).label) === 'הזזה');
  check('הזזה מדויקת: קצוות חדים בפיקסל השלם', near(await px(200, 130), [255, 0, 0, 255]) && near(await px(199, 130), [255, 255, 255, 255]) && near(await px(299, 229), [255, 0, 0, 255]) && near(await px(300, 229), [255, 255, 255, 255]));
  await page.keyboard.press('Control+z');
  check('ביטול — חוזר למקום', near(await px(150, 150), [255, 0, 0, 255]) && near(await px(250, 180), [255, 255, 255, 255]));
  await page.keyboard.press('Control+Shift+z');
  // קנה מידה מהפינה: 100×100 → 200×200, הפינה השמאלית-עליונה נשארת
  await drag([250, 180], [250, 180]);   // מתחיל צף בלי לזוז
  await drag([300, 230], [400, 330]);
  await page.click('[data-xf="apply"]');
  check('ידית פינה — הגדלה ×2 ששומרת יחס', near(await px(390, 320), [255, 0, 0, 255]) && near(await px(410, 320), [255, 255, 255, 255]) && near(await px(205, 135), [255, 0, 0, 255]),
    [await px(390, 320), await px(410, 320)].join(' | '));
  check('רשומת "שינוי צורה"', await page.evaluate(() => __rs.history.undoStack.at(-1).label) === 'שינוי צורה');
  // סיבוב ← Esc מבטל בלי עקבות
  const beforeRot = await px(390, 140);
  const n1 = await page.evaluate(() => __rs.history.undoStack.length);
  await drag([300, 230], [300, 230]);
  await drag([412, 342], [300, 395]);
  const rot = await page.evaluate(() => __rs.renderer && __rs.doc._float?.t.angle);
  check('ליד פינה מבחוץ — סיבוב', Math.abs(rot - Math.PI / 4) < 0.05, String(rot));
  await page.keyboard.press('Escape');
  check('Esc — ביטול, בלי רשומה', (await page.evaluate(() => __rs.history.undoStack.length)) === n1 && (await px(390, 140)) === beforeRot);
  // חצים
  await page.keyboard.press('ArrowRight'); await page.keyboard.press('ArrowRight'); await page.keyboard.press('Shift+ArrowDown');
  await page.keyboard.press('Enter');
  check('חצים — 2 ימינה ו-10 למטה', near(await px(202, 140), [255, 0, 0, 255]) && near(await px(201, 140), [255, 255, 255, 255]) && near(await px(250, 139), [255, 255, 255, 255]));
  // בחירה זזה עם התוכן
  await page.keyboard.press('m');
  await drag([202, 140], [302, 240]);
  await page.keyboard.press('v');
  await drag([250, 190], [300, 190]);
  await page.keyboard.press('Enter');
  const movedSel = await page.evaluate(() => __rs.doc.selection.shapes[0]);
  check('הבחירה זזה יחד עם התוכן', movedSel.x === 252 && movedSel.y === 140, JSON.stringify(movedSel));
  check('רק מה שבבחירה זז', near(await px(270, 190), [255, 0, 0, 255]) && near(await px(320, 300), [255, 0, 0, 255]) && near(await px(220, 190), [255, 255, 255, 255]), [await px(270, 190), await px(320, 300), await px(220, 190)].join(' | '));
  await page.keyboard.press('Control+z');
  check('ביטול — גם הבחירה חוזרת', await page.evaluate(() => __rs.doc.selection.shapes[0].x) === 202);
  // שכבה ריקה
  await page.keyboard.press('Control+d');
  await page.click('[data-act="add"]');
  await drag([50, 50], [80, 80]);
  check('שכבה ריקה — הודעה', /ריקה/.test(await page.textContent('.toast')) && !(await page.evaluate(() => __rs.tools.floating)));
  if (SHOTS) {
    await page.click('.layer-row:nth-child(2)');
    await drag([300, 300], [300, 300]);
    await drag([402, 342], [330, 400]);
    await page.screenshot({ path: join(SHOTS, 'transform-desktop.png') });
    await page.keyboard.press('Escape');
  }
  // קריאה איטית מהכרטיס (כמו ב-WebGPU): הגרירה, הדלי והטפטפת מתחילים לפני שהקריאה חזרה — שום דבר לא הולך לאיבוד
  await openEd(page);
  await page.waitForFunction(() => window.__rs?.doc);
  await page.evaluate(() => __rs.setView({ zoom: 1, x: 20, y: 20 }));
  await page.click('[data-act="add"]');
  await page.keyboard.press('m');
  await drag([100, 100], [200, 200]);
  await color('#ff0000');
  await page.keyboard.press('Alt+Backspace');
  await page.keyboard.press('Control+d');
  await page.evaluate(() => {
    const r = __rs.renderer;
    const slow = f => async (...a) => { await new Promise(res => setTimeout(res, 400)); return f.apply(r, a); };
    r._fast = { layerBounds: r.layerBounds, readLayerPixels: r.readLayerPixels, readPixels: r.readPixels };
    for (const k of Object.keys(r._fast)) r[k] = slow(r._fast[k]);
  });
  await page.keyboard.press('v');
  await drag([150, 150], [250, 180]);
  const early = await page.evaluate(() => __rs.tools.floating);
  await page.waitForFunction(() => __rs.tools.floating && !__rs.tools.stroking);
  check('קריאה איטית: הגרירה לפני שהגבולות ידועים — התוכן זז עד הסוף', !early && near(await px(250, 180), [255, 0, 0, 255]) && near(await px(120, 120), [255, 255, 255, 255]));
  await page.keyboard.press('Enter');
  check('קריאה איטית: הוחל, הזזה מדויקת', await page.evaluate(() => __rs.history.undoStack.at(-1).label) === 'הזזה' && near(await px(200, 130), [255, 0, 0, 255]) && near(await px(199, 130), [255, 255, 255, 255]));
  await page.keyboard.press('g');
  await color('#0000ff');
  await page.mouse.click(...at(30, 350));
  await page.waitForFunction(() => __rs.history.undoStack.at(-1).label === 'דלי');
  check('קריאה איטית: הדלי ממלא אחרי שהקריאה חזרה', near(await px(30, 350), [0, 0, 255, 255]) && near(await px(250, 180), [255, 0, 0, 255]));
  await page.keyboard.press('i');
  await page.mouse.click(...at(250, 180));
  await page.waitForFunction(() => document.querySelector('#color').value === '#ff0000');
  check('קריאה איטית: הטפטפת דוגמת', true);
  await page.evaluate(() => { const r = __rs.renderer; Object.assign(r, r._fast); delete r._fast; });

  // ---- חיתוך ----
  await openEd(page);
  await page.waitForFunction(() => window.__rs?.doc);
  await page.evaluate(() => __rs.setView({ zoom: 1, x: 20, y: 20 }));
  await page.keyboard.press('m');
  await drag([300, 100], [400, 200]);
  await color('#ff0000');
  await page.keyboard.press('Alt+Backspace');
  await page.keyboard.press('Control+d');
  await page.keyboard.press('c');
  check('C — חיתוך', await page.evaluate(() => __rs.tools.state.tool) === 'crop' && await page.isVisible('[data-crop="apply"]'));
  await drag([250, 50], [450, 250]);
  check('גרירה — אזור עם גודל', (await page.textContent('#crop-info')) === '200 × 200');
  await drag([450, 250], [470, 260]);   // ידית פינה
  check('ידית — כיוונון', (await page.textContent('#crop-info')) === '220 × 210');
  await page.keyboard.press('Enter');
  await page.waitForFunction(() => document.querySelector('#meta').textContent.includes('220×210'), null, { timeout: 3000 }).catch(() => {});   // הכותרת מתעדכנת בפריים הבא
  const cropped = await page.evaluate(() => [__rs.doc.width, __rs.doc.height, document.querySelector('#meta').textContent]);
  check('Enter — הקנבס בגודל החדש, גם בכותרת', cropped[0] === 220 && cropped[1] === 210 && cropped[2].includes('220×210'), cropped.join());
  check('התוכן זז בדיוק: הריבוע האדום ב-(50..150, 50..150)', near(await px(50, 50), [255, 0, 0, 255]) && near(await px(149, 149), [255, 0, 0, 255]) && near(await px(49, 50), [255, 255, 255, 255]) && near(await px(150, 149), [255, 255, 255, 255]));
  await page.keyboard.press('Control+z');
  check('ביטול — הגודל והתוכן חוזרים', await page.evaluate(() => __rs.doc.width) === 600 && near(await px(300, 100), [255, 0, 0, 255]) && near(await px(299, 100), [255, 255, 255, 255]));
  await page.keyboard.press('Control+Shift+z');
  check('ביצוע — שוב 220×210', await page.evaluate(() => __rs.doc.width) === 220 && near(await px(50, 50), [255, 0, 0, 255]));
  // הגדלה: אזור מעבר לקנבס
  await page.keyboard.press('Control+z');
  await page.waitForTimeout(150);   // הביטול משנה גודל → התאמה למסך בפריים הבא; רק אחריה קובעים זום
  await page.evaluate(() => __rs.setView({ zoom: 0.5, x: 120, y: 120 }));
  await page.click('.tool[data-tool="crop"]');
  await page.mouse.move(sb[0] + 120 - 50, sb[1] + 120 - 25); await page.mouse.down();
  await page.mouse.move(sb[0] + 120 + 350, sb[1] + 120 + 225, { steps: 5 }); await page.mouse.up();
  await page.keyboard.press('Enter');
  const big = await page.evaluate(async () => [__rs.doc.width, __rs.doc.height, Array.from(await __rs.renderer.readPixels(__rs.doc, 5, 5, 1, 1)).join(), Array.from(await __rs.renderer.readPixels(__rs.doc, 450, 200, 1, 1)).join()]);   // הריבוע עבר ל-(400..500, 150..250)
  check('חיתוך מעבר לקנבס — הגדלה, השוליים שקופים, התוכן במקום', big[0] === 800 && big[1] === 500 && big[2] === '0,0,0,0' && near(big[3], [255, 0, 0, 255]), big.join(' | '));
  await page.keyboard.press('c');
  await drag([10, 10], [20, 20]);
  await page.keyboard.press('Escape');
  check('Esc — ביטול האזור', (await page.textContent('#crop-info')) === '' && await page.evaluate(() => __rs.doc.width) === 800);

  // ---- תיקונים ----
  await openEd(page);
  await page.waitForFunction(() => window.__rs?.doc);
  await page.evaluate(() => __rs.setView({ zoom: 1, x: 20, y: 20 }));
  const adj = async (tab, vals) => {
    if (!(await page.isVisible('#adjust'))) await page.click('#adjust-btn');
    await page.click(`[data-adj-tab="${tab}"]`);
    for (const [f, v] of Object.entries(vals)) {
      await page.$eval('#adj-' + f, (el, v) => { el.value = String(v); el.dispatchEvent(new Event('input', { bubbles: true })); }, v);
    }
  };
  const fillLayer = c => page.evaluate(c => { __rs.renderer.fill(__rs.doc, __rs.doc.active, c); __rs.renderer.invalidate(__rs.doc); }, c);
  await fillLayer([128, 128, 128, 255]);
  await adj('bc', { brightness: 100 });
  check('בהירות +100 — תצוגה חיה (128 → ~181), לפני החלה', near(await px(), [181, 181, 181, 255]), await px());
  await page.click('[data-adj-act="cancel"]');
  check('✕ — חוזר בלי שינוי ובלי רשומה', near(await px(), [128, 128, 128, 255]) && !(await page.evaluate(() => __rs.history.canUndo)));
  await adj('bc', { brightness: 100 });
  await page.click('[data-adj-act="apply"]');
  check('✓ — הוחל, רשומת "בהירות וניגודיות"', near(await px(), [181, 181, 181, 255]) && await page.evaluate(() => __rs.history.undoStack.at(-1).label) === 'בהירות וניגודיות' && !(await page.isVisible('#adjust')));
  await page.keyboard.press('Control+z');
  check('ביטול — 128 חוזר', near(await px(), [128, 128, 128, 255]));
  await fillLayer([64, 64, 64, 255]);
  await adj('bc', { contrast: 50 });
  check('ניגודיות +50 — כהה נעשה כהה יותר', near(await px(), [2, 2, 2, 255]), await px());
  await page.click('[data-adj-act="cancel"]');
  await fillLayer([255, 0, 0, 255]);
  await adj('hsl', { hue: 120 });
  check('גוון +120° — אדום → ירוק', near(await px(), [0, 255, 0, 255]), await px());
  await adj('hsl', { saturation: -100 });
  check('רוויה −100 — אפור', near(await px(), [128, 128, 128, 255]), await px());
  await adj('hsl', { lightness: 50 });
  check('בהירות +50 ב-HSL — אדום → ורוד', near(await px(), [255, 128, 128, 255]), await px());
  await page.click('[data-adj-act="cancel"]');
  // טשטוש: שחור ב-x 0..256 (בדיוק עד גבול האריח) על שכבה שקופה מעל לבן
  await page.click('[data-act="add"]');
  await page.keyboard.press('m');
  await drag([0, 60], [256, 400]);   // לא מראש הבמה — שם לוח האפשרויות
  await color('#000000');
  await page.keyboard.press('Alt+Backspace');
  await page.keyboard.press('Control+d');
  await adj('blur', { radius: 30 });
  await page.click('[data-adj-act="apply"]');
  const bl = await page.evaluate(() => Promise.all([[200, 100], [256, 100], [296, 100], [250, 255], [250, 256], [240, 300]].map(async ([x, y]) => Array.from(await __rs.renderer.readPixels(__rs.doc, x, y, 1, 1)))));
  check('טשטוש: הרחק מהקצה — שחור, בקצה — אפור, מעבר לגרעין — לבן', bl[0][0] < 3 && bl[1][0] > 60 && bl[1][0] < 200 && bl[2][0] > 252, JSON.stringify(bl.slice(0, 3)));
  check('טשטוש: בלי תפר בגבול אריחים (y 255/256)', Math.abs(bl[3][0] - bl[4][0]) <= 1, JSON.stringify(bl.slice(3, 5)));
  check('טשטוש "נשפך" לאריח שכן שהיה ריק', await page.evaluate(() => __rs.doc.active.tiles.has('1,0')) && bl[1][3] === 255);
  // תיקון רק בבחירה
  await page.keyboard.press('Control+z');
  await page.keyboard.press('m');
  await drag([0, 60], [100, 400]);
  await adj('hsl', { lightness: 100 });
  await page.click('[data-adj-act="apply"]');
  check('בחירה: רק בתוכה — לבן; מחוצה לה — עדיין שחור', near(await px(50, 100), [255, 255, 255, 255]) && near(await px(150, 100), [0, 0, 0, 255]));
  // פילטרים: אפור 100 עם ריבוע 160 ב-(300..400, 100..200)
  await page.keyboard.press('Control+d');
  await fillLayer([100, 100, 100, 255]);
  await page.evaluate(() => __rs.tools.setTool('rect'));   // M היה מחליף לאליפסה — הכלי כבר מלבן
  await drag([400, 200], [300, 100]);
  await color('#a0a0a0');
  await page.keyboard.press('Alt+Backspace');
  await page.keyboard.press('Control+d');
  const r0 = async (x, y) => Number((await px(x, y)).split(',')[0]);
  await adj('sharpen', { amount: 300, sradius: 4 });
  const sh = [await r0(301, 150), await r0(298, 150), await r0(350, 150), await r0(500, 300)];
  check('חידוד: ליד הקצה — בהיר מעל 160 מבפנים וכהה מתחת ל-100 מבחוץ; הרחק — בלי שינוי',
    sh[0] > 175 && sh[1] < 85 && Math.abs(sh[2] - 160) <= 1 && Math.abs(sh[3] - 100) <= 1, sh.join());
  await page.click('[data-adj-act="apply"]');
  check('חידוד — רשומת "חידוד"', await page.evaluate(() => __rs.history.undoStack.at(-1).label) === 'חידוד');
  await page.keyboard.press('Control+z');
  await adj('motion', { angle: 0, distance: 40 });
  const mo = [await r0(300, 150), await r0(350, 150), await r0(350, 96), await r0(500, 300)];
  check('טשטוש תנועה אופקי: הקצה האנכי מטושטש, הקצה העליון חד', mo[0] > 110 && mo[0] < 150 && Math.abs(mo[1] - 160) <= 1 && Math.abs(mo[2] - 100) <= 1 && Math.abs(mo[3] - 100) <= 1, mo.join());
  await adj('motion', { angle: 90, distance: 40 });
  const mv = [await r0(350, 100), await r0(296, 150)];
  check('טשטוש תנועה 90° — אנכי', mv[0] > 110 && mv[0] < 150 && Math.abs(mv[1] - 100) <= 1, mv.join());
  await adj('noise', { noise: 40 });
  const nz = await page.evaluate(async () => { await __rs.tools.settle(); return Array.from(await __rs.renderer.readPixels(__rs.doc, 450, 300, 32, 1)).filter((_, i) => i % 4 === 0); });
  const mean = nz.reduce((a, b) => a + b, 0) / nz.length;
  check('רעש: הפיקסלים שונים זה מזה, והממוצע קרוב למקור', new Set(nz).size > 10 && Math.abs(mean - 100) < 20, `${new Set(nz).size} · ${mean.toFixed(1)}`);
  await adj('sharpen', { amount: 0 });
  check('חידוד בכמות 0 — בלי שינוי (לא טשטוש)', Math.abs(await r0(300, 150) - 160) <= 1 && Math.abs(await r0(299, 150) - 100) <= 1);
  await page.click('[data-adj-act="cancel"]');
  if (SHOTS) {
    await adj('hsl', { hue: 90 });
    await page.screenshot({ path: join(SHOTS, 'adjust-desktop.png') });
    await page.click('[data-adj-act="cancel"]');
  }

  // ---- טקסט ----
  await openEd(page);
  await page.waitForFunction(() => window.__rs?.doc);
  await page.evaluate(() => __rs.setView({ zoom: 1, x: 20, y: 20 }));
  const ink = (x0, y0, x1, y1) => page.evaluate(async ([x0, y0, x1, y1]) => {
    const p = await __rs.renderer.readPixels(__rs.doc, x0, y0, x1 - x0, y1 - y0);
    let n = 0; for (let i = 0; i < p.length; i += 4) if (p[i] < 200) n++; return n;
  }, [x0, y0, x1, y1]);
  await page.keyboard.press('t');
  check('T — טקסט, עם גופן ויישור', await page.evaluate(() => __rs.tools.state.tool) === 'text' && await page.isVisible('#opt-font') && (await page.$$eval('#opt-font option', o => o.length)) === 3);
  await page.mouse.click(...at(300, 100));
  check('לחיצה — תיבת טקסט בפוקוס', await page.isVisible('#text-edit') && await page.evaluate(() => document.activeElement.id) === 'text-edit');
  await page.keyboard.type('שלום עולם');
  await page.keyboard.press('Control+Enter');
  await page.waitForFunction(() => __rs.history.undoStack.at(-1)?.label === 'טקסט', null, { timeout: 5000 }).catch(() => {});
  check('Ctrl+Enter — שכבה חדשה בשם הטקסט', await page.evaluate(() => __rs.doc.active.name) === 'שלום עולם' && !(await page.isVisible('#text-edit')));
  check('הגופן העברי נטען (Heebo)', await page.evaluate(() => document.fonts.check('48px "Heebo"', 'שלום')));
  const left = await ink(60, 100, 300, 165), right = await ink(305, 95, 420, 165);
  check('עברית: הטקסט נמשך שמאלה מנקודת הלחיצה (ימין = התחלה)', left > 300 && right === 0, `${left} / ${right}`);
  await page.keyboard.press('Control+z');
  check('ביטול — שכבת הטקסט נעלמת', await ink(60, 100, 300, 165) === 0);
  // אנגלית: התחלה = שמאל
  await page.mouse.click(...at(100, 250));
  await page.keyboard.type('Hello');
  await page.click('[data-text="apply"]');
  await page.waitForTimeout(300);
  const l2 = await ink(0, 250, 95, 310), r2 = await ink(100, 250, 300, 310);
  check('אנגלית: נמשך ימינה מהנקודה', r2 > 200 && l2 === 0, `${l2} / ${r2}`);
  // Esc — בלי שכבה
  const nL = await page.evaluate(() => __rs.doc.layers.length);
  await page.mouse.click(...at(100, 350));
  await page.keyboard.type('בדיקה');
  await page.keyboard.press('Escape');
  check('Esc — ביטול, בלי שכבה', await page.evaluate(() => __rs.doc.layers.length) === nL && !(await page.isVisible('#text-edit')));
  // מודגש וגודל משפיעים על הדיו
  await page.fill('#opt-font-size', '80');
  await page.mouse.click(...at(500, 300));
  await page.keyboard.type('אבג');
  if (SHOTS) await page.screenshot({ path: join(SHOTS, 'text-editing.png') });
  await page.keyboard.press('Control+Enter');
  await page.waitForTimeout(300);
  const thin = await ink(300, 300, 500, 400);
  await page.keyboard.press('Control+z');
  await page.click('#opt-bold');
  await page.mouse.click(...at(500, 300));
  await page.keyboard.type('אבג');
  await page.keyboard.press('Control+Enter');
  await page.waitForTimeout(300);
  const bold = await ink(300, 300, 500, 400);
  check('מודגש — יותר דיו', bold > thin * 1.2 && thin > 0, `${thin} / ${bold}`);
  // מעבר כלי באמצע עריכה — מאשר
  await page.mouse.click(...at(300, 50));
  await page.keyboard.type('ט');
  await page.keyboard.press('Escape');
  if (SHOTS) await page.screenshot({ path: join(SHOTS, 'text-desktop.png') });

  // ---- טיוטה במכשיר ----
  await openEd(page);
  await page.waitForFunction(() => window.__rs?.doc);
  await page.evaluate(() => __rs.setView({ zoom: 1, x: 20, y: 20 }));
  await page.click('[data-act="add"]');
  await page.keyboard.press('m');
  await drag([100, 100], [200, 200]);
  await color('#0000ff');
  await page.keyboard.press('Alt+Backspace');
  await page.evaluate(() => __rs.doc.active.name = 'כחול');
  await page.evaluate(() => __rs.flushDraft());
  await page.reload();
  await page.waitForFunction(() => window.__rs?.doc && __rs.doc.layers.length === 2, null, { timeout: 8000 }).catch(() => {});
  check('טעינה מחדש — הטיוטה משוחזרת (שכבות, שמות ופיקסלים)', await page.evaluate(() => __rs.doc.layers.map(l => l.name).join()) === 'רקע,כחול' && near(await px(150, 150), [0, 0, 255, 255]), await page.evaluate(() => __rs.doc.layers.map(l => l.name).join()));
  check('הודעה על השחזור', /שוחזרה הטיוטה/.test(await page.textContent('.toast')));
  // ייצוא
  await page.click('#export-btn');
  check('⬇️ — חלון הורדה עם PNG / JPG / ORA / ‎.mbe', await page.isVisible('#export-dlg') && !(await page.$eval('[data-export="mbe"]', b => b.disabled)));
  const [dl] = await Promise.all([page.waitForEvent('download'), page.click('[data-export="png"]')]);
  const pngBuf = await (await import('node:fs/promises')).readFile(await dl.path());
  // Chromium בלי ממשק מתעלם משם הורדה שאינו ASCII ("download") — שם הקובץ עצמו נבדק ישירות
  const okName = (d, ext) => ['בדיקה.' + ext, 'download'].includes(d.suggestedFilename());
  check('PNG — חתימה, וגודל הקנבס', pngBuf.subarray(1, 4).toString() === 'PNG' && pngBuf.readUInt32BE(16) === 600 && pngBuf.readUInt32BE(20) === 400 && okName(dl, 'png'), dl.suggestedFilename());
  check('שם הקובץ — מהכותרת, בלי תווים אסורים', await page.evaluate(async () => {
    const { fileName } = await import('./assets/js/export.js');
    return fileName('בדיקה', 'png') === 'בדיקה.png' && fileName('a/b:c?', 'ora') === 'a b c.ora' && fileName('', 'jpg') === 'תמונה.jpg';
  }));
  await page.click('#export-btn');
  const [dl2] = await Promise.all([page.waitForEvent('download'), page.click('[data-export="ora"]')]);
  const oraPath = await dl2.path();
  const { execFileSync } = await import('node:child_process');
  const list = execFileSync('unzip', ['-l', oraPath]).toString();
  const stack = execFileSync('unzip', ['-p', oraPath, 'stack.xml']).toString();
  const first = (await import('node:fs')).readFileSync(oraPath).subarray(30, 54).toString();
  check('ORA — mimetype ראשון, שתי שכבות, mergedimage ותמונה ממוזערת',
    first === 'mimetypeimage/openraster' && /data\/layer0\.png/.test(list) && /data\/layer1\.png/.test(list) && /mergedimage\.png/.test(list) && /Thumbnails\/thumbnail\.png/.test(list), list);
  check('ORA — stack.xml: העליונה ראשונה, בגודל הקנבס', stack.indexOf('layer1.png') < stack.indexOf('layer0.png') && /w="600" h="400"/.test(stack));
  await page.click('#export-btn');
  const [dl3] = await Promise.all([page.waitForEvent('download'), page.click('[data-export="jpg"]')]);
  const jpg = (await import('node:fs')).readFileSync(await dl3.path());
  check('JPG — חתימה', jpg[0] === 0xFF && jpg[1] === 0xD8 && okName(dl3, 'jpg'));

  // ---- ORA: הורדה ← פתיחה (שכבות, שמות, מיזוג, אטימות, הסתרה, נעילה) ----
  await openEd(page);
  await page.waitForFunction(() => window.__rs?.doc);
  await page.evaluate(() => {
    const { doc: d, renderer: r } = __rs;
    const a = d.addLayer({ name: 'כפל', blend: 'multiply', opacity: 0.5 }); r.fill(d, a, [200, 50, 50, 255]);
    const b = d.addLayer({ name: 'נסתר', visible: false, locked: true }); r.fill(d, b, [0, 0, 0, 255]);
    r.invalidate(d);
  });
  const want = await px(10, 10);
  await page.click('#export-btn');
  const [dlo] = await Promise.all([page.waitForEvent('download'), page.click('[data-export="ora"]')]);
  const oraBytes = (await import('node:fs')).readFileSync(await dlo.path());
  await page.goto(BASE + '/index.html');
  await page.waitForSelector('#new-btn:not([disabled])');
  await Promise.all([page.waitForURL(/editor\.html\?open=pending/), page.setInputFiles('#file', { name: 'שכבות.ora', mimeType: 'image/openraster', buffer: oraBytes })]);
  await page.waitForFunction(() => window.__rs?.doc?.layers.length === 3, null, { timeout: 8000 }).catch(() => {});
  const back = await page.evaluate(() => __rs.doc.layers.map(l => [l.name, l.blend, l.opacity, l.visible, l.locked].join(':')).join(' | '));
  check('ORA נפתח עם כל השכבות והמאפיינים', back === 'רקע:normal:1:true:false | כפל:multiply:0.5:true:false | נסתר:normal:1:false:true', back);
  check('ORA — אותה תמונה מורכבת', (await px(10, 10)) === want, (await px(10, 10)) + ' / ' + want);
  check('ORA — השם מהקובץ', await page.evaluate(() => __rs.doc.title) === 'שכבות');
  // ---- ‎.mbe: הורדה, אימות בשתי השפות, פתיחה, ושמירת מה שלא שלנו ----
  await openEd(page);
  await page.waitForFunction(() => window.__rs?.doc);
  await page.evaluate(() => {
    const { doc: d, renderer: r } = __rs;
    const a = d.addLayer({ name: 'כפל', blend: 'multiply', opacity: 0.5 }); r.fill(d, a, [200, 50, 50, 255]);
    const g = d.addGroup({ name: 'קבוצה', blend: 'screen', opacity: 0.8 });
    const c = d.addLayer({ name: 'בתוך', parent: g }); r.fill(d, c, [20, 90, 200, 160]);
    const h = d.addLayer({ name: 'נסתר', visible: false, locked: true }); r.fill(d, h, [0, 0, 0, 255]);
    d.activeId = a.id;
    r.invalidate(d);
  });
  const mbeWant = await px(10, 10);
  await page.click('#export-btn');
  const [dlm] = await Promise.all([page.waitForEvent('download'), page.click('[data-export="mbe"]')]);
  const mbePath = await dlm.path();
  const mbeBytes = (await import('node:fs')).readFileSync(mbePath);
  check('‎.mbe — שם, וחתימה: mimetype בהיסט 30', okName(dlm, 'mbe') && mbeBytes.subarray(30, 61).toString() === 'mimetypeapplication/vnd.mbe+zip', dlm.suggestedFilename());
  const mInfo = await page.evaluate(async b64 => {
    const { open } = await import('./assets/js/mbe/mbe.js');
    const f = await open(Uint8Array.from(atob(b64), c => c.charCodeAt(0)));
    const s = f.document.surfaces[0];
    const tree = l => l.type === 'group' ? `${l.name}[${l.children.map(tree).join(',')}]` : `${l.name}:${l.blend}:${l.opacity}:${l.visible}:${l.locked}`;
    return {
      ok: f.ok, errors: f.issues.filter(i => i.level === 'error').map(i => i.code), kind: f.kind, app: f.manifest.modified.app,
      type: s.type, size: s.size.join(), tree: s.layers.map(tree).join(' | '), media: f.media().length,
      rend: f.renditions().map(r => r.path + (r.stale ? '!' : '')).join(), view: new TextDecoder().decode(f.apps('raster-studio').get('view.json') ?? new Uint8Array()),
      units: f.document.units,
    };
  }, mbeBytes.toString('base64'));
  check('‎.mbe — נפתח בליבה בלי שגיאות, kind raster, נשמר ע"י raster-studio', mInfo.ok && mInfo.kind === 'raster' && mInfo.app === 'raster-studio', JSON.stringify(mInfo.errors));
  check('‎.mbe — משטח canvas בגודל הקנבס, units pt', mInfo.type === 'canvas' && mInfo.size === '600,400' && mInfo.units === 'pt', mInfo.size);
  check('‎.mbe — עץ השכבות עם המאפיינים (קבוצה עם children)', mInfo.tree === 'רקע:normal:1:true:false | כפל:multiply:0.5:true:false | קבוצה[בתוך:normal:1:true:false] | נסתר:normal:1:false:true', mInfo.tree);
  check('‎.mbe — PNG לכל שכבת רסטר, וייצוגים עדכניים: preview, image.png, image.ora', mInfo.media === 4 && mInfo.rend === 'renditions/image.ora,renditions/image.png,renditions/preview.png', `${mInfo.media} · ${mInfo.rend}`);
  check('‎.mbe — מצב התצוגה ב-apps/raster-studio', /"zoom":/.test(mInfo.view) && /"tool":/.test(mInfo.view), mInfo.view);
  // אותו קובץ — בבודק של PHP (עותק הליבה בשרת)
  const phpIssues = execFileSync('php', ['-r', 'require $argv[1]; echo json_encode(Mbe::validate(file_get_contents($argv[2])));', join(ROOT, 'lib/mbe/mbe.php'), mbePath]).toString();
  check('‎.mbe — עובר גם בליבת PHP, בלי שגיאות', !JSON.parse(phpIssues).some(i => i.level === 'error'), phpIssues);
  // פתיחה חזרה
  await page.goto(BASE + '/index.html');
  await page.waitForSelector('#new-btn:not([disabled])');
  await Promise.all([page.waitForURL(/editor\.html\?open=pending/), page.setInputFiles('#file', { name: 'חבילה.mbe', mimeType: 'application/vnd.mbe+zip', buffer: mbeBytes })]);
  await page.waitForFunction(() => window.__rs?.doc?.layers.length === 4, null, { timeout: 8000 }).catch(() => {});
  const mBack = await page.evaluate(() => [...__rs.doc.walk()].map(w => w.layer.name + (w.layer.type === 'group' ? '/' : '')).join(','));
  check('‎.mbe נפתח מהספרייה — העץ במלואו', mBack === 'רקע,כפל,קבוצה/,בתוך,נסתר', mBack);
  check('‎.mbe — אותה תמונה מורכבת, והשם מהקובץ', (await px(10, 10)) === mbeWant && await page.evaluate(() => __rs.doc.title) === 'חבילה', (await px(10, 10)) + ' / ' + mbeWant);
  // שמירה חוזרת בלי שינוי — שכבות שלא השתנו נשמרות באותו משאב, והקובץ נשאר תקין
  const again = await page.evaluate(async () => {
    const { open } = await import('./assets/js/mbe/mbe.js');
    const f = await open(await __rs.buildMbe());
    return { ok: f.ok, media: f.media().length };
  });
  check('‎.mbe — שמירה חוזרת: תקין, בלי משאבים כפולים', again.ok && again.media === 4, JSON.stringify(again));

  // קובץ של "אפליקציה אחרת": שכבה וקטורית, שדות לא מוכרים, משטח נוסף ו-apps/ של אחרים — הכול נשמר בית-לבית
  const keep = await page.evaluate(async () => {
    const { create, save, open, PREVIEW, canonicalJson } = await import('./assets/js/mbe/mbe.js');
    const { encodePNG } = await import('./assets/js/engine/png.js');
    const px = new Uint8ClampedArray(40 * 30 * 4).fill(255);
    const png = await encodePNG(px, 40, 30);
    const vec = { id: 'V1', name: 'לוגו', type: 'vector', paths: [{ id: 'p1', type: 'path', d: 'M0 0 L10 10 Z', future: [1, 2] }], visible: true, future: { x: 1 } };
    const f = create('raster', { units: 'pt', color: { space: 'sRGB' }, future: 'שדה עתידי', surfaces: [] });
    const src = await f.addMedia(png, 'image/png');
    f.document.surfaces = [
      { id: 'c1', type: 'canvas', size: [40, 30], note: 'לא שלנו', layers: [{ id: 'R1', name: 'רקע', type: 'raster', src, opacity: 1, blend: 'normal', visible: true, locked: false, tag: 7 }, vec] },
      { id: 'p9', type: 'page', size: [595, 842], layers: [] },
    ];
    f.setRendition(PREVIEW, png);
    f.setApp('vector-studio', 'state.json', new TextEncoder().encode('{"z":3}'));
    f.files.set('extra/unknown.bin', new Uint8Array([1, 2, 3]));
    const bytes = await save(f, { app: 'vector-studio' });
    await __rs.openAsDocument(new File([bytes], 'זר.mbe'));
    const d = __rs.doc;
    const foreign = d.rasters().filter(l => l.foreign);
    // מציירים על הרקע — שינוי שלנו; הווקטורית לא נערכת
    __rs.renderer.fill(d, d.layers[0], [0, 128, 0, 255]);
    d.activeId = foreign[0].id;
    const out = await open(await __rs.buildMbe());
    const s = out.document.surfaces.find(x => x.id === 'c1');
    return {
      opened: d.rasters().length, foreign: foreign.length, locked: foreign[0]?.locked, name: foreign[0]?.name,
      ok: out.ok, vec: canonicalJson(s.layers[1]) === canonicalJson(vec), future: out.document.future, note: s.note,
      tag: s.layers[0].tag, newSrc: s.layers[0].src !== src, oldGone: !out.files.has(src), page: out.document.surfaces.map(x => x.id).join(),
      app: new TextDecoder().decode(out.apps('vector-studio').get('state.json')), extra: Array.from(out.files.get('extra/unknown.bin') ?? []).join(),
      created: out.manifest.created.app, modified: out.manifest.modified.app,
      gaps: out.renditions().find(r => r.path === 'renditions/image.png')?.gaps?.join(),
    };
  });
  check('‎.mbe זר — שכבת vector נפתחת כנעולה, עם הסוג בשם', keep.opened === 2 && keep.foreign === 1 && keep.locked && /\(vector\)/.test(keep.name), JSON.stringify(keep));
  check('‎.mbe זר — שמירה: השכבה הווקטורית זהה בית-לבית', keep.ok && keep.vec, JSON.stringify(keep));
  check('‎.mbe זר — שדות לא מוכרים (במסמך, במשטח, בשכבה) ומשטח אחר נשמרים', keep.future === 'שדה עתידי' && keep.note === 'לא שלנו' && keep.tag === 7 && keep.page === 'c1,p9', JSON.stringify(keep));
  check('‎.mbe זר — apps/ של אחרים וחלק לא מוכר נשמרים', keep.app === '{"z":3}' && keep.extra === '1,2,3', JSON.stringify(keep));
  check('‎.mbe זר — הרקע שצויר מחדש: משאב חדש, הישן יצא מהקובץ', keep.newSrc && keep.oldGone, JSON.stringify(keep));
  check('‎.mbe זר — created נשאר של היוצר, modified שלנו; הפער נרשם בייצוג', keep.created === 'vector-studio' && keep.modified === 'raster-studio' && /layer:vector → omitted/.test(keep.gaps), JSON.stringify(keep));
  await page.evaluate(() => __rs.tools.setTool('brush'));
  await page.mouse.click(...(await page.evaluate(() => { const r = document.querySelector('#stage').getBoundingClientRect(); return [r.left + r.width / 2, r.top + r.height / 2]; })));
  check('‎.mbe זר — לחיצה במכחול על הווקטורית: הודעה, בלי שינוי', /אפליקציה אחרת/.test(await page.textContent('.toast')));
  // הטיוטה שומרת גם את מה שלא שלנו
  await page.evaluate(() => __rs.flushDraft());
  await page.reload();
  await page.waitForFunction(() => window.__rs?.doc?.rasters().some(l => l.foreign), null, { timeout: 8000 }).catch(() => {});
  const fromDraft = await page.evaluate(async () => {
    const { open } = await import('./assets/js/mbe/mbe.js');
    const out = await open(await __rs.buildMbe());
    const s = out.document.surfaces.find(x => x.id === 'c1');
    return { ok: out.ok, vec: s?.layers[1]?.type, app: new TextDecoder().decode(out.apps('vector-studio').get('state.json') ?? new Uint8Array()) };
  });
  check('‎.mbe זר — אחרי טעינה מהטיוטה: עדיין נשמר הכול', fromDraft.ok && fromDraft.vec === 'vector' && fromDraft.app === '{"z":3}', JSON.stringify(fromDraft));
  // עמוד של סטודיו העיצוב (kind design, ‏pt, רסטר עם px ותמונה ממוקמת ב-box) — נפתח בפיקסלים ונשמר כעמוד
  const gc = await page.evaluate(async () => {
    const { create, save, open, PREVIEW } = await import('./assets/js/mbe/mbe.js');
    const { encodePNG } = await import('./assets/js/engine/png.js');
    const solid = (w, h, c) => { const a = new Uint8ClampedArray(w * h * 4); for (let i = 0; i < a.length; i += 4) a.set(c, i); return encodePNG(a, w, h); };
    const f = create('design', { units: 'pt', color: { space: 'sRGB' }, surfaces: [] });
    const bg = await f.addMedia(await solid(300, 200, [255, 0, 0, 255]), 'image/png');
    const sq = await f.addMedia(await solid(100, 100, [0, 0, 255, 255]), 'image/png');
    const vec = { id: 'T1', type: 'vector', paths: [{ id: 't1', type: 'path', d: 'M0 0 L10 10 Z' }] };
    f.document.surfaces = [{ id: 'gc-page-1', type: 'page', size: [225, 150], layers: [
      { id: 'R1', name: 'רקע', type: 'raster', src: bg, px: [300, 200], opacity: 1, blend: 'normal', visible: true, locked: false },
      { id: 'R2', name: 'ריבוע', type: 'raster', src: sq, box: [75, 37.5, 75, 75], px: [100, 100], opacity: 1, blend: 'normal', visible: true, locked: false },
      vec,
    ] }];
    f.setRendition(PREVIEW, await solid(30, 20, [255, 0, 0, 255]));
    await __rs.openAsDocument(new File([await save(f, { app: 'greeting-cards' })], 'כרטיס.mbe'));
    const r = __rs.renderer, d = __rs.doc;
    const at = async (x, y) => Array.from(await r.readPixels(d, x, y, 1, 1)).join();
    const opened = { size: [d.width, d.height].join(), bg: await at(10, 10), sq: await at(150, 100), out: await at(95, 100) };
    const back = await open(await __rs.buildMbe());
    const s = back.document.surfaces[0];
    return { ...opened, ok: back.ok, kind: back.kind, type: s.type, ssize: s.size.join(), units: back.document.units,
      px: s.layers.filter(l => l.type === 'raster').map(l => (l.px || []).join('x') + (l.box ? '+box' : '')).join(), vec: s.layers.some(l => l.id === 'T1' && l.type === 'vector') };
  });
  check('עמוד של סטודיו העיצוב — נפתח בגודל הפיקסלים של הרסטר, והתמונה הממוקמת (box) במקומה', gc.size === '300,200' && gc.bg === '255,0,0,255' && gc.sq === '0,0,255,255' && gc.out === '255,0,0,255', JSON.stringify(gc));
  check('עמוד של סטודיו העיצוב — נשמר כעמוד ב-pt (kind design), הרסטר בגודל העמוד עם px, הווקטורי נשמר', gc.ok && gc.kind === 'design' && gc.type === 'page' && gc.ssize === '225,150' && gc.units === 'pt' && gc.px === '300x200,300x200' && gc.vec, JSON.stringify(gc));
  // קובץ פגום ומסמך שאינו רסטר — הודעה ברורה
  const bad = await page.evaluate(async () => {
    const { create, save, PREVIEW } = await import('./assets/js/mbe/mbe.js');
    const { encodePNG } = await import('./assets/js/engine/png.js');
    const f = create('text', { units: 'pt', surfaces: [{ id: 'p1', type: 'page', size: [595, 842], layers: [] }] });
    f.setRendition(PREVIEW, await encodePNG(new Uint8ClampedArray(4).fill(255), 1, 1));
    const text = await save(f, { app: 'word-processor' });
    const broken = text.slice(); broken[broken.length - 30] ^= 0xff;
    const out = [];
    for (const [b, n] of [[text, 'מסמך.mbe'], [broken.slice(0, 200), 'שבור.mbe']]) {
      try { await __rs.openAsDocument(new File([b], n)); out.push('נפתח'); } catch (e) { out.push(e.message + (e.detail ? ':' + e.detail : '')); }
    }
    return out;
  });
  check('‎.mbe של עורך התמלילים — "פותחים אותו בעורך התמלילים"', /^mbe-not-raster:.*עורך התמלילים/.test(bad[0]), bad[0]);
  check('‎.mbe שבור — נדחה', /^mbe-invalid/.test(bad[1]), bad[1]);

  // ---- פתיחה מאפליקציה אחרת וחזרה (mbe-handoff, greeting-cards/spec/13-open-in.md) ----
  // "המקור" — חלון באותו דומיין שפותח אותנו ב-?mbe-handoff=, כמו סטודיו העיצוב
  {
    const src = await ctx.newPage();
    await src.goto(BASE + '/index.html');
    await src.evaluate(async () => {
      const { create, save, PREVIEW } = await import('./assets/js/mbe/mbe.js');
      const { encodePNG } = await import('./assets/js/engine/png.js');
      const f = create('raster', { units: 'pt', color: { space: 'sRGB' }, surfaces: [] });
      const px = new Uint8ClampedArray(60 * 40 * 4);
      for (let i = 0; i < px.length; i += 4) px.set([0, 0, 255, 255], i);
      const png = await encodePNG(px, 60, 40);
      const s = await f.addMedia(png, 'image/png');
      f.setRendition(PREVIEW, png);
      f.document.surfaces = [{ id: 'c1', type: 'canvas', size: [60, 40], layers: [{ id: 'B', name: 'כחול', type: 'raster', src: s, opacity: 1, blend: 'normal', visible: true, locked: false }] }];
      const bytes = await save(f, { app: 'greeting-cards' });
      window.__log = [];
      const id = 'h' + Math.random().toString(36).slice(2);
      window.__w = window.open('index.html?mbe-handoff=' + id);
      window.addEventListener('message', e => {
        if (e.source !== window.__w || e.data?.proto !== 'mbe-handoff') return;
        window.__log.push(e.data.type);
        const reply = m => window.__w.postMessage({ proto: 'mbe-handoff', v: 1, id, ...m }, location.origin);
        if (e.data.type === 'ready') {
          // קודם הודעה עם id אחר — חייבת להידחות; ואז הנכונה
          window.__w.postMessage({ proto: 'mbe-handoff', v: 1, id: 'זר', type: 'open', name: 'זר.mbe', file: new ArrayBuffer(4), from: { app: 'x', name: 'זר' } }, location.origin);
          reply({ type: 'open', name: 'כרטיס.mbe', file: bytes.slice().buffer, from: { app: 'greeting-cards', name: 'סטודיו העיצוב' } });
        }
        if (e.data.type === 'return') { window.__back = new Uint8Array(e.data.file); reply({ type: 'returned' }); }
      });
    });
    const pop = await ctx.waitForEvent('page', { predicate: p => /mbe-handoff=/.test(p.url()) });
    await pop.waitForFunction(() => window.__rs?.doc && !document.querySelector('#handoff-bar').hidden, null, { timeout: 10000 }).catch(() => {});
    check('handoff: ready נשלח, הקובץ נפתח בעורך, ופס "↩ חזרה ל…"', (await src.evaluate(() => window.__log.join())).startsWith('ready')
      && await pop.evaluate(() => [__rs.doc.width, __rs.doc.height, __rs.doc.layers[0].name].join()) === '60,40,כחול'
      && (await pop.textContent('#handoff-btn')) === '↩ חזרה לסטודיו העיצוב', [await src.evaluate(() => window.__log.join()), pop.url(), await pop.evaluate(() => [!!window.__rs?.doc, document.querySelector('#handoff-bar')?.hidden, !!window.opener, sessionStorage.getItem('mbe-handoff'), document.querySelector('.msg, #msg')?.textContent].join(' ; ')).catch(e => e.message)].join(' | '));
    check('handoff: הודעה עם id אחר — נדחתה', await pop.evaluate(() => __rs.doc.title) !== 'זר');
    await pop.evaluate(() => { __rs.renderer.fill(__rs.doc, __rs.doc.layers[0], [255, 0, 0, 255]); __rs.renderer.invalidate(__rs.doc); });
    const closed = pop.waitForEvent('close', { timeout: 5000 }).then(() => true, () => false);
    await pop.click('#handoff-btn');
    await src.waitForFunction(() => window.__back, null, { timeout: 8000 }).catch(() => {});
    const back = await src.evaluate(async () => {
      if (!window.__back) return null;
      const { open } = await import('./assets/js/mbe/mbe.js');
      const f = await open(window.__back);
      const L = f.document.surfaces[0].layers[0];
      const bmp = await createImageBitmap(new Blob([f.getMedia(L.src)], { type: 'image/png' }));
      const c = new OffscreenCanvas(1, 1).getContext('2d');
      c.drawImage(bmp, 0, 0);
      return { ok: f.ok, name: L.name, px: Array.from(c.getImageData(0, 0, 1, 1).data).join(), log: window.__log.join() };
    });
    check('handoff: "↩ חזרה" — ה-‎.mbe חוזר למקור, תקין ועם העריכה', back?.ok && back.name === 'כחול' && back.px === '255,0,0,255' && back.log === 'ready,return', JSON.stringify(back));
    check('handoff: אחרי האישור (returned) — החלון נסגר', await closed);
    await src.close();
  }

  // ---- שמירה בשרת: גרסאות, התנגשות ממכשיר אחר, פתיחה מהשרת ----
  await page.goto(BASE + '/index.html');
  await page.waitForSelector('#new-btn:not([disabled])');
  const sid = await page.evaluate(async () => {
    const r = await fetch('api.php', { method: 'POST', headers: { 'X-Raster-Studio': '1' },
      body: JSON.stringify({ action: 'doc_create', title: 'בשרת', size: 'custom', px: ['300', '200'] }) });
    return (await r.json()).doc.id;
  });
  await page.evaluate(() => new Promise(r => { const q = indexedDB.deleteDatabase('raster-studio'); q.onsuccess = q.onerror = q.onblocked = () => r(); }));
  await page.goto(BASE + '/editor.html?doc=' + sid);
  await page.waitForFunction(() => window.__rs?.doc);
  check('מסמך שעוד לא נשמר — 💾 פעיל, 🕘 כבוי', !(await page.$eval('#save-btn', b => b.disabled)) && await page.$eval('#versions-btn', b => b.disabled));
  await page.evaluate(() => { __rs.renderer.fill(__rs.doc, __rs.doc.layers[0], [255, 0, 0, 255]); __rs.history.clear(); });
  await page.click('#save-btn');
  await page.waitForFunction(() => /גרסה 1/.test(document.querySelector('.toast').textContent), null, { timeout: 8000 }).catch(() => {});
  check('💾 — נשמר בשרת, גרסה 1', /נשמר בשרת — גרסה 1/.test(await page.textContent('.toast')) && await page.evaluate(() => __rs.doc.serverVersion === 1 && !__rs.unsaved));
  await page.reload();
  await page.waitForFunction(() => window.__rs?.doc?.serverVersion === 1, null, { timeout: 8000 }).catch(() => {});
  check('טעינה מחדש — נפתח מהשרת (בלי טיוטה), אותם פיקסלים', near(await px(10, 10), [255, 0, 0, 255]) && await page.evaluate(() => __rs.doc.title) === 'בשרת', await px(10, 10));
  // מכשיר אחר שומר בינתיים (גרסה 2) — השמירה מכאן שואלת לפני שהיא עולה מעליו
  const other = await page.evaluate(async id => {
    const bytes = await __rs.buildMbe();
    const r = await fetch(`api.php?action=doc_upload&id=${id}&base=1`, { method: 'POST', headers: { 'X-Raster-Studio': '1' }, body: bytes });
    return (await r.json()).version;
  }, sid);
  await page.evaluate(() => { __rs.renderer.fill(__rs.doc, __rs.doc.layers[0], [0, 0, 255, 255]); __rs.renderer.invalidate(__rs.doc); });
  let asked = '';
  // כל חלון שאלה מאושר (page.on מקטע השכבות) — כאן רק רושמים את הטקסט
  page.once('dialog', d => { asked = d.message(); });
  await page.keyboard.press('Control+s');
  await page.waitForFunction(() => /גרסה 3/.test(document.querySelector('.toast').textContent), null, { timeout: 8000 }).catch(() => {});
  check('גרסה ממכשיר אחר — השמירה שואלת, ואחרי אישור: גרסה חדשה מעליה (Ctrl+S)', other === 2 && /גרסה אחרת/.test(asked) && await page.evaluate(() => __rs.doc.serverVersion) === 3, asked);
  // גרסאות: רשימה, ופתיחת גרסה ישנה
  await page.click('#versions-btn');
  await page.waitForSelector('#versions-list [data-v]');
  const vRows = await page.$$eval('#versions-list li b', b => b.map(x => x.textContent));
  check('🕘 — שלוש גרסאות, מהחדשה', vRows.length === 3 && vRows[0] === 'גרסה 3 · האחרונה' && vRows[2] === 'גרסה 1', vRows.join(' | '));
  await page.click('#versions-list [data-v="1"]');
  await page.waitForFunction(() => /נפתחה גרסה 1/.test(document.querySelector('.toast').textContent), null, { timeout: 8000 }).catch(() => {});
  check('פתיחת גרסה 1 — הפיקסלים שלה, ושמירה ממנה תהיה גרסה 4 (לא התנגשות)', near(await px(10, 10), [255, 0, 0, 255]) && await page.evaluate(() => __rs.doc.serverVersion) === 3);
  await page.click('#save-btn');
  await page.waitForFunction(() => /גרסה 4/.test(document.querySelector('.toast').textContent), null, { timeout: 8000 }).catch(() => {});
  check('שמירה מגרסה ישנה — גרסה 4, בלי שאלה', /גרסה 4/.test(await page.textContent('.toast')));
  // הספרייה: מספר הגרסה ותמונה ממוזערת מתוך ה-‎.mbe
  await page.goto(BASE + '/index.html');
  await page.waitForSelector('.doc-row a');
  const libRow = await page.evaluate(id => {
    const a = [...document.querySelectorAll('.doc-row a')].find(x => x.getAttribute('href') === 'editor.html?doc=' + id);
    const li = a.closest('.doc-row');
    return { small: li.querySelector('small').textContent, bg: li.querySelector('.canvas-ic i').style.backgroundImage };
  }, sid);
  const prev = await page.evaluate(async id => { const r = await fetch(`api.php?action=doc_preview&id=${id}`); return [r.status, r.headers.get('content-type'), (await r.arrayBuffer()).byteLength]; }, sid);
  check('בספרייה — "גרסה 4" ותמונה ממוזערת', /גרסה 4/.test(libRow.small) && /doc_preview/.test(libRow.bg) && prev[0] === 200 && prev[1] === 'image/png' && prev[2] > 50, JSON.stringify([libRow, prev]));
  // השרת לא סומך על הלקוח: קובץ פגום — 422 עם הבעיות; בלי הכותרת — 403
  const srv = await page.evaluate(async id => {
    const up = (h, body) => fetch(`api.php?action=doc_upload&id=${id}`, { method: 'POST', headers: h, body })
      .then(async r => { const j = await r.json(); return [r.status, j.error, (j.issues || []).length]; });
    const junk = new Uint8Array(300); junk.set([80, 75, 3, 4]);
    return { bad: await up({ 'X-Raster-Studio': '1' }, junk), csrf: await up({}, junk) };
  }, sid);
  check('השרת דוחה קובץ פגום (422, mbe_invalid, עם הבעיות) ובקשה בלי הכותרת (403)', srv.bad[0] === 422 && srv.bad[1] === 'mbe_invalid' && srv.bad[2] > 0 && srv.csrf[0] === 403, JSON.stringify(srv));
  // מסמך מהמכשיר — 💾 מכניס אותו לספרייה בשרת
  await page.goto(BASE + '/index.html');
  await page.waitForSelector('#new-btn:not([disabled])');
  const greenPng = Buffer.from(await page.evaluate(async () => {
    const c = new OffscreenCanvas(50, 40); const g = c.getContext('2d'); g.fillStyle = '#00aa00'; g.fillRect(0, 0, 50, 40);
    return Array.from(new Uint8Array(await (await c.convertToBlob({ type: 'image/png' })).arrayBuffer()));
  }));
  await Promise.all([page.waitForURL(/editor\.html\?open=pending/), page.setInputFiles('#file', { name: 'ירוק.png', mimeType: 'image/png', buffer: greenPng })]);
  await page.waitForFunction(() => window.__rs?.doc?.width === 50, null, { timeout: 8000 }).catch(() => {});
  await page.click('#save-btn');
  await page.waitForFunction(() => /גרסה 1/.test(document.querySelector('.toast').textContent), null, { timeout: 8000 }).catch(() => {});
  const localSaved = await page.evaluate(() => ({ id: __rs.doc.serverId, url: location.search, v: __rs.doc.serverVersion }));
  // התשובות 409 / 422 / 403 שהבדיקות האלה ביקשו בכוונה — הדפדפן רושם אותן בקונסול
  for (let i = errors.length - 1; i >= 0; i--) if (/status of (409|422|403)\b/.test(errors[i])) errors.splice(i, 1);
  check('מסמך מהמכשיר — 💾 יוצר אותו בספרייה בשרת, והכתובת עוברת אליו', localSaved.id > 0 && localSaved.url === '?doc=' + localSaved.id && localSaved.v === 1, JSON.stringify(localSaved));

  // ---- התקנה ובלי רשת ----
  const man = await page.evaluate(async () => {
    const m = await (await fetch('manifest.webmanifest')).json();
    const icons = await Promise.all(m.icons.map(i => fetch(i.src).then(r => r.ok)));
    return { name: m.name, dir: m.dir, icons: icons.every(Boolean), handlers: m.file_handlers?.[0]?.accept?.['image/openraster']?.[0], mbe: m.file_handlers?.[0]?.accept?.['application/vnd.mbe+zip']?.[0] };
  });
  check('manifest: עברית מימין לשמאל, אייקונים קיימים, פתיחת ORA ו-‎.mbe', man.name === 'עיצוב רסטר' && man.dir === 'rtl' && man.icons && man.handlers === '.ora' && man.mbe === '.mbe', JSON.stringify(man));
  await page.goto(BASE + '/index.html');
  await page.evaluate(() => navigator.serviceWorker.ready);
  await page.reload();
  const cdpI = await ctx.newCDPSession(page);
  const inst = await cdpI.send('Page.getInstallabilityErrors');
  check('Chromium: ניתן להתקנה, בלי שגיאות', inst.installabilityErrors.length === 0, JSON.stringify(inst.installabilityErrors));
  await page.goto(BASE + '/editor.html?doc=' + id);   // נכנס למטמון
  await page.waitForFunction(() => window.__rs?.doc);
  await ctx.setOffline(true);
  await page.goto(BASE + '/index.html').catch(() => {});
  const offline = await page.evaluate(() => document.querySelector('h1')?.textContent || '').catch(() => '');
  check('בלי רשת — הדף נפתח מהמטמון', /עיצוב רסטר/.test(offline), offline);
  await ctx.setOffline(false);
  // השגיאות של בדיקת "בלי רשת" (בקשות לשרת שנכשלו) — צפויות
  for (let i = errors.length - 1; i >= 0; i--) if (/INTERNET_DISCONNECTED|Failed to fetch|network/i.test(errors[i])) errors.splice(i, 1);

  // ---- קבוצות ----
  await openEd(page);
  await page.waitForFunction(() => window.__rs?.doc);
  await page.evaluate(() => __rs.setView({ zoom: 1, x: 20, y: 20 }));
  const tree = () => page.$$eval('.layer-row', rs => rs.map(r => '·'.repeat(Number(r.style.getPropertyValue('--depth')) || 0) + (r.querySelector('.layer-name')?.textContent ?? '')).join(','));
  await page.click('[data-act="add"]');
  await page.keyboard.press('m');
  await drag([100, 100], [200, 200]);
  await color('#ff0000');
  await page.keyboard.press('Alt+Backspace');
  await page.keyboard.press('Control+d');
  await page.keyboard.press('Control+g');
  check('Ctrl+G — קבוצה, והשכבה בתוכה (מוזחת)', await tree() === 'קבוצה 1,·שכבה 2,רקע', await tree());
  check('רשומת "קיבוץ"', await page.evaluate(() => __rs.history.undoStack.at(-1).label) === 'קיבוץ');
  check('הקבוצה מורכבת כמו קודם — אדום', near(await px(150, 150), [255, 0, 0, 255]));
  await page.$eval('#layer-opacity', el => { el.value = '50'; el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true })); });
  check('אטימות הקבוצה 50% — חצי אדום על לבן', near(await px(150, 150), [255, 188, 188, 255]), await px(150, 150));
  await page.click('.group-row .eye');
  check('הסתרת הקבוצה — לבן', near(await px(150, 150), [255, 255, 255, 255]));
  await page.keyboard.press('b');
  await page.click('.layer-row:nth-child(2)');   // השכבה שבתוך הקבוצה
  const nG = await page.evaluate(() => __rs.history.undoStack.length);
  await stroke([[300, 300], [350, 300]]);
  check('ציור על שכבה בקבוצה מוסתרת — חסום, עם הודעה', await page.evaluate(() => __rs.history.undoStack.length) === nG && /מוסתרת/.test(await page.textContent('.toast')));
  await page.click('.group-row .eye');
  await page.click('.group-row .layer-name');
  await stroke([[300, 300], [350, 300]]);
  check('ציור על הקבוצה עצמה — חסום', /על קבוצה לא מציירים/.test(await page.textContent('.toast')));
  // שכבה חדשה ליד שכבה בקבוצה — נכנסת לקבוצה
  await page.click('.layer-row:nth-child(2)');
  await page.click('[data-act="add"]');
  check('+ שכבה ליד שכבה בקבוצה — בתוך הקבוצה', await tree() === 'קבוצה 1,·שכבה 3,·שכבה 2,רקע', await tree());
  // קיפול
  await page.click('.group-row .fold');
  check('קיפול — הילדים מוסתרים', await tree() === 'קבוצה 1,רקע');
  await page.click('.group-row .fold');
  // הזזת קבוצה
  await page.click('.group-row .layer-name');
  await page.keyboard.press('v');
  await drag([150, 150], [250, 170]);
  check('✥ על קבוצה — כל השכבות זזות יחד', near(await px(250, 170), [255, 188, 188, 255]) && near(await px(120, 120), [255, 255, 255, 255]), [await px(250, 170), await px(120, 120)].join(' | '));
  check('רשומת "הזזת קבוצה"', await page.evaluate(() => __rs.history.undoStack.at(-1).label) === 'הזזת קבוצה');
  await page.keyboard.press('Control+z');
  check('ביטול — חוזרת למקום', near(await px(150, 150), [255, 188, 188, 255]));
  // גרירה לתוך קבוצה: הרקע נכנס לקבוצה
  const bgH = await page.$eval('.layer-row:last-child .drag', el => { const r = el.getBoundingClientRect(); return [r.x + r.width / 2, r.y + r.height / 2]; });
  const gRow = await page.$eval('.group-row', el => { const r = el.getBoundingClientRect(); return [r.x + r.width / 2, r.y + r.height / 2]; });
  await page.mouse.move(...bgH); await page.mouse.down();
  await page.mouse.move(...gRow, { steps: 6 }); await page.mouse.up();
  check('גרירה לאמצע שורת קבוצה — לתוכה, למעלה', await tree() === 'קבוצה 1,·רקע,·שכבה 3,·שכבה 2', await tree());
  await page.keyboard.press('Control+z');
  check('ביטול — הרקע יצא', await tree() === 'קבוצה 1,·שכבה 3,·שכבה 2,רקע', await tree());
  // ORA וטיוטה שומרים את הקבוצה
  await page.evaluate(() => __rs.flushDraft());
  await page.reload();
  await page.waitForFunction(() => window.__rs?.doc && __rs.doc.layers.length === 2, null, { timeout: 8000 }).catch(() => {});
  check('טיוטה — הקבוצה משוחזרת עם האטימות', await tree() === 'קבוצה 1,·שכבה 3,·שכבה 2,רקע' && near(await px(150, 150), [255, 188, 188, 255]), await tree());
  const oraG = await page.evaluate(async () => { const { buildORA } = await import('./assets/js/export.js'); return Array.from(await buildORA(__rs.renderer, __rs.doc)); });
  const oraTmp = join(tmp, 'g.ora');
  (await import('node:fs')).writeFileSync(oraTmp, Buffer.from(oraG));
  const stackG = (await import('node:child_process')).execFileSync('unzip', ['-p', oraTmp, 'stack.xml']).toString();
  check('ORA — הקבוצה כ-stack מקונן', /<stack name="קבוצה 1" opacity="0.5"[^>]*isolation="isolate">/.test(stackG), stackG);
  // פירוק
  await page.click('.group-row .layer-name');
  await page.keyboard.press('Control+Shift+g');
  check('Ctrl+Shift+G — פירוק', await tree() === 'שכבה 3,שכבה 2,רקע', await tree());
  await page.keyboard.press('Control+z');
  check('ביטול — הקבוצה חוזרת', await tree() === 'קבוצה 1,·שכבה 3,·שכבה 2,רקע', await tree());
  if (SHOTS) await page.screenshot({ path: join(SHOTS, 'groups-desktop.png') });

  check('אין שגיאות בקונסול', errors.length === 0, errors.join(' | '));

  // ---- WebGPU מול WebGL2: אותה סצנה בשני המנועים, פיקסל-פיקסל ----
  if (MODE === 'webgpu') {
    const cmp = await page.evaluate(async () => {
      const { WebGPURenderer } = await import('./assets/js/engine/webgpu.js');
      const { WebGL2Renderer, decodeImage } = await import('./assets/js/engine/webgl2.js');
      const { RasterDocument } = await import('./assets/js/engine/doc.js');
      // תמונה: מעבר צבע + עיגולים חצי-שקופים (PNG, כדי ששני המנועים יקבלו בדיוק אותם בתים)
      const src = new OffscreenCanvas(420, 300);
      const g2 = src.getContext('2d');
      const grad = g2.createLinearGradient(0, 0, 420, 300);
      grad.addColorStop(0, '#ff2050'); grad.addColorStop(0.5, '#20c0ff'); grad.addColorStop(1, '#ffe040');
      g2.fillStyle = grad; g2.fillRect(0, 0, 420, 300);
      for (let i = 0; i < 6; i++) { g2.fillStyle = `hsla(${i * 60},80%,50%,0.5)`; g2.beginPath(); g2.arc(60 + i * 60, 150 + (i % 2) * 60, 50, 0, 7); g2.fill(); }
      g2.clearRect(380, 0, 40, 40);
      const png = await src.convertToBlob({ type: 'image/png' });
      const scene = async r => {
        const d = new RasterDocument(700, 500);
        r.fill(d, d.addLayer({ name: 'רקע' }), [240, 230, 210, 255]);
        const A = d.addLayer();
        const bmp = await decodeImage(png);
        await r.drawImage(d, A, bmp, 30, 20);
        bmp.close();
        const B = d.addLayer({ blend: 'multiply', opacity: 0.7 });
        r.beginStroke(d, B, { color: [20, 90, 200], opacity: 0.8 });
        for (let t = 0; t <= 1; t += 0.01) r.dab(d, 80 + t * 540, 250 + Math.sin(t * 9) * 120, 18 + 10 * t, 0.3);
        r.endStroke(d);
        r.beginStroke(d, B, { color: [200, 40, 20] });
        for (let t = 0; t <= 1; t += 0.02) r.dab(d, 600 - t * 500, 80 + t * 340, 6, 1);
        r.endStroke(d);
        const G = d.addGroup({ blend: 'screen', opacity: 0.8 });
        const C = d.addLayer({ parent: G, blend: 'overlay' });
        d.selection = { shapes: [{ op: 'add', type: 'ellipse', x: 200, y: 120, w: 330, h: 230 }], inverted: false };
        r.selectionChanged(d);
        r.fillSelection(d, C, 'paint', [120, 30, 160], 0.9);
        const D = d.addLayer({ parent: G });
        r.fill(d, D, [30, 160, 60, 200]);
        d.selection = null; r.selectionChanged(d);
        r.beginStroke(d, D, { mode: 'erase' });
        for (let t = 0; t <= 1; t += 0.02) r.dab(d, 50 + t * 600, 450 - t * 100, 30, 0.6);
        r.endStroke(d);
        // תיקונים: גוון / רוויה ל-A, בהירות / ניגודיות ל-C, טשטוש בבחירה ל-D
        r.setAdjust(d, A, { kind: 'hsl', hue: 40, saturation: -30, lightness: 10 }); r.commitAdjust(d);
        r.setAdjust(d, C, { kind: 'bc', brightness: 30, contrast: 40 }); r.commitAdjust(d);
        d.selection = { shapes: [{ op: 'add', type: 'rect', x: 240, y: 200, w: 300, h: 260 }], inverted: false };
        r.selectionChanged(d);
        r.setAdjust(d, D, { kind: 'blur', radius: 14 }); r.commitAdjust(d);
        d.selection = null; r.selectionChanged(d);
        // שינוי צורה: סיבוב וקנה מידה (דגימה לינארית ו-mipmaps)
        const rect = await r.layerBounds(B);
        r.beginFloat(d, B, rect, { cx: rect[0] + rect[2] / 2, cy: rect[1] + rect[3] / 2, sx: 0.8, sy: 0.8, angle: 0.3 });
        r.commitFloat(d);
        // הזזה בפיקסלים שלמים (חיתוך / הזזת קבוצה)
        const old = A.tiles;
        A.tiles = r.shiftTiles(old, -13, 7);
        r.freeTextures(old.values());
        r.resetComposite(d);
        const px = await r.readPixels(d, 0, 0, d.width, d.height);
        const th = await r.thumbnail(d, A, 64);
        return { px, th: th.data, layers: d.rasters().map(l => l.tiles.size).join() };
      };
      const mk = () => { const c = document.createElement('canvas'); c.width = 64; c.height = 64; return c; };
      const gpu = await WebGPURenderer.create(mk());
      const gl = new WebGL2Renderer(mk());
      const a = await scene(gpu), b = await scene(gl);
      const diff = (x, y) => {
        let max = 0, over1 = 0, at = '';
        for (let i = 0; i < x.length; i++) {
          const e = Math.abs(x[i] - y[i]);
          if (e > max) { max = e; at = `(${(i >> 2) % 700},${Math.floor((i >> 2) / 700)}) ${Array.from(x.slice(i & ~3, (i & ~3) + 4))} / ${Array.from(y.slice(i & ~3, (i & ~3) + 4))}`; }
          if (e > 1) over1++;
        }
        return { max, over1, share: over1 / x.length, at };
      };
      return { px: diff(a.px, b.px), th: diff(a.th, b.th), tiles: a.layers === b.layers, la: a.layers, lb: b.layers, n: a.px.length };
    });
    if (process.env.RS_DEBUG) console.log('[cmp]', JSON.stringify(cmp));
    check('WebGPU ↔ WebGL2: אותם אריחים בכל שכבה', cmp.tiles, `${cmp.la} / ${cmp.lb}`);
    check('WebGPU ↔ WebGL2: הסצנה המלאה (תמונה, משיכות, מיזוג, קבוצה, בחירה, תיקונים, סיבוב, הזזה) — ±1 בכל פיקסל',
      cmp.px.max <= 1, JSON.stringify(cmp.px));
    check('WebGPU ↔ WebGL2: תמונה ממוזערת', cmp.th.max <= 1, JSON.stringify(cmp.th));
    await page.goto(BASE + '/editor.html?doc=' + id + '&renderer=webgl2');
    await page.waitForFunction(() => window.__rs?.doc);
    check('‎?renderer=webgl2 — מכריח את הגיבוי גם כש-WebGPU זמין', await page.evaluate(() => __rs.renderer.name) === 'WebGL2');
  }

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
      // המסמך המותאם לא נכנס מתחת לפס הכלים ולוח האפשרויות
      clear: (() => {
        const st = document.querySelector('#stage').getBoundingClientRect(), t = document.querySelector('#tools').getBoundingClientRect();
        const o = document.querySelector('#tool-opts'), ob = o.hidden ? null : o.getBoundingClientRect();
        const { zoom, x, y } = __rs.view, R = st.left + x + __rs.doc.width * zoom, T = st.top + y;
        return R <= t.left + 0.5 && (!ob || T >= ob.bottom - 0.5);
      })(),
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
    check(`טלפון ${tag}: המסמך מותאם לרוחב`, r.zoom < 1 && r.zoom > 0.3, String(r.zoom));
    check(`טלפון ${tag}: המסמך לא מוסתר מתחת לפס הכלים ולוח האפשרויות`, r.clear);
    check(`טלפון ${tag}: רמז מגע — מוסתר כשאין לו מקום`, /צביטה/.test(r.hint) && !r.hintShown, r.hint);
    check(`טלפון ${tag}: בלי שגיאות`, errs.length === 0, errs.join(' | '));
    if (SHOTS) await ph.screenshot({ path: join(SHOTS, `editor-${w}${dark ? '-dark' : ''}.png`) });
    await ph.keyboard.press('m');
    const optsFit = await ph.evaluate(() => [...document.querySelectorAll('#tool-opts > :not([hidden])')].every(el => {
      const r = el.getBoundingClientRect(), o = document.querySelector('#tool-opts').getBoundingClientRect();
      return r.left >= o.left - 0.5 && r.right <= o.right + 0.5 && el.scrollWidth <= el.clientWidth + 1;
    }));
    if (SHOTS) await ph.screenshot({ path: join(SHOTS, `select-${w}${dark ? '-dark' : ''}.png`) });
    await ph.click('#adjust-btn');
    const adjFit = await ph.evaluate(() => { const r = document.querySelector('#adjust').getBoundingClientRect(); return r.left >= -0.5 && r.right <= innerWidth + 0.5 && r.height > 50; });
    if (SHOTS) await ph.screenshot({ path: join(SHOTS, `adjust-${w}${dark ? '-dark' : ''}.png`) });
    await ph.click('[data-adj-act="cancel"]');
    check(`טלפון ${w}px${dark ? ' כהה' : ''}: לוח התיקונים בתוך המסך`, adjFit);
    check(`טלפון ${w}px${dark ? ' כהה' : ''}: אפשרויות הבחירה נכנסות בלי חפיפה`, optsFit);
    await ph.keyboard.press('b');
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
