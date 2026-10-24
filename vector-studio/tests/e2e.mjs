// בדיקת דפדפן לעורך: node vector-studio/tests/e2e.mjs <כתובת בסיס> [תיקיית צילומים]
// השרת צריך להגיש את vector-studio/ ולהחזיר משתמש ב-api.php?action=me (בפיתוח — שרת PHP עם זהות מדומה).

import { createRequire } from 'node:module';
import assert from 'node:assert/strict';

const require = createRequire(import.meta.url);
const { chromium } = require('playwright');

const base = (process.argv[2] || 'http://127.0.0.1:8765/vector-studio/').replace(/\/?$/, '/');
const shots = process.argv[3] || null;
const errors = [];
const step = (name) => console.log('✓ ' + name);

const browser = await chromium.launch();
try {
  // ---- מחשב ----
  const page = await browser.newPage({ viewport: { width: 1280, height: 800 } });
  page.on('pageerror', e => errors.push(e.message));
  page.on('console', m => m.type() === 'error' && errors.push(m.text()));

  await page.goto(base);
  await page.waitForSelector('#home:not([hidden])');
  await page.click('#new-btn');
  await page.click('[data-size="square"]');
  await page.waitForFunction(() => window.__vs?.S.doc);
  step('ציור חדש נפתח בעורך');

  const st = () => page.evaluate(() => {
    const S = window.__vs.S;
    return { sel: [...S.sel], paths: S.doc.surfaces[0].layers[0].paths, view: S.view, undo: S.undo.length };
  });
  const box = await page.locator('#canvas').boundingBox();
  const W = async (x, y) => { const { view } = await st(); return [box.x + view.x + x * view.z, box.y + view.y + y * view.z]; };

  // מלבן בגרירה
  await page.click('[data-tool="rect"]');
  let [a, b] = [await W(100, 100), await W(400, 300)];
  await page.mouse.move(...a); await page.mouse.down(); await page.mouse.move(...b, { steps: 5 }); await page.mouse.up();
  let s = await st();
  assert.equal(s.paths.length, 1);
  assert.equal(s.paths[0].shape.type, 'rect');
  s.paths[0].shape.box.forEach((v, i) => assert.ok(Math.abs(v - [100, 100, 300, 200][i]) < 2, 'box ' + s.paths[0].shape.box));
  assert.deepEqual(s.sel, [s.paths[0].id]);
  assert.equal(await page.getAttribute('[data-tool="select"]', 'aria-pressed'), 'true');
  step('מלבן בגרירה, נבחר, והכלי חוזר לבחירה');

  // הזזה
  [a, b] = [await W(250, 200), await W(350, 250)];
  await page.mouse.move(...a); await page.mouse.down(); await page.mouse.move(...b, { steps: 5 }); await page.mouse.up();
  s = await st();
  assert.ok(Math.abs(s.paths[0].shape.box[0] - 200) < 2 && Math.abs(s.paths[0].shape.box[1] - 150) < 2, 'move ' + s.paths[0].shape.box);
  step('הזזה בגרירה');

  // שינוי גודל מהידית הימנית-תחתונה (se)
  [a, b] = [await W(500, 350), await W(600, 450)];
  await page.mouse.move(...a); await page.mouse.down(); await page.mouse.move(...b, { steps: 5 }); await page.mouse.up();
  s = await st();
  const r = s.paths[0].shape.box;
  assert.ok(Math.abs(r[2] - 400) < 2 && Math.abs(r[3] - 300) < 2, 'scale ' + r);
  assert.equal(s.paths[0].stroke.width, 1, 'המתאר לא נמתח');
  step('שינוי גודל מהידית — הצורה נשארת מלבן, והמתאר לא נמתח');

  // ביטול וביצוע מחדש
  await page.keyboard.press('Control+z');
  s = await st();
  assert.ok(Math.abs(s.paths[0].shape.box[2] - 300) < 2);
  await page.keyboard.press('Control+Shift+z');
  s = await st();
  assert.ok(Math.abs(s.paths[0].shape.box[2] - 400) < 2);
  step('ביטול וביצוע מחדש');

  // אליפסה בהקשה (גודל ברירת מחדל), ואז בחירה במלבן של שתיהן
  await page.click('[data-tool="ellipse"]');
  await page.mouse.click(...await W(850, 850));
  s = await st();
  assert.equal(s.paths.length, 2);
  assert.deepEqual(s.paths[1].shape.box, [800, 800, 100, 100]);
  [a, b] = [await W(20, 20), await W(1000, 1000)];
  await page.mouse.move(...a); await page.mouse.down(); await page.mouse.move(...b, { steps: 5 }); await page.mouse.up();
  s = await st();
  assert.equal(s.sel.length, 2);
  step('אליפסה בהקשה; בחירה במלבן תופסת את שתיהן');

  // מאפיינים: צבע מילוי לשתיהן, עובי מתאר
  await page.locator('[data-c="fill"]').evaluate((el) => { el.value = '#e19a00'; el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true })); });
  await page.fill('[data-w]', '4'); await page.press('[data-w]', 'Enter');
  s = await st();
  assert.ok(s.paths.every(p => p.fill.color === '#e19a00' && p.stroke.width === 4));
  step('מילוי ומתאר משתנים לכל הנבחרים');

  // שכפול ומחיקה
  await page.keyboard.press('Control+d');
  s = await st();
  assert.equal(s.paths.length, 4);
  await page.keyboard.press('Delete');
  s = await st();
  assert.equal(s.paths.length, 2);
  step('שכפול ומחיקה');

  // זום בגלגלת עם Ctrl, וחזרה להתאמה
  const z0 = (await st()).view.z;
  await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
  await page.keyboard.down('Control'); await page.mouse.wheel(0, -200); await page.keyboard.up('Control');
  assert.ok((await st()).view.z > z0 * 1.5);
  await page.click('#zoom-pct');
  assert.ok(Math.abs((await st()).view.z - z0) < 1e-6);
  step('זום בגלגלת והתאמה למסך');

  // הטיוטה נשמרה ומופיעה ברשימה
  await page.waitForTimeout(600);
  if (shots) await page.screenshot({ path: `${shots}/editor-1280.png` });
  await page.click('a[href="./"]');
  await page.waitForSelector('#drafts .card');
  assert.match(await page.textContent('#drafts'), /2 אובייקטים/);
  step('הטיוטה נשמרה במכשיר ומופיעה ברשימה');
  await page.close();

  // ---- טלפון ----
  for (const width of [390, 320]) {
    const ctx = await browser.newContext({ viewport: { width, height: 780 }, hasTouch: true, isMobile: true, deviceScaleFactor: 2 });
    const p = await ctx.newPage();
    p.on('pageerror', e => errors.push(e.message));
    await p.goto(base + 'editor.html#new:a4');
    await p.waitForFunction(() => window.__vs?.S.doc);
    const sw = await p.evaluate(() => document.documentElement.scrollWidth);
    assert.equal(sw, width, 'אין גלילה אופקית');
    await p.tap('[data-tool="star"]');
    const cb = await p.locator('#canvas').boundingBox();
    await p.touchscreen.tap(cb.x + cb.width / 2, cb.y + cb.height / 2);
    const n = await p.evaluate(() => window.__vs.S.doc.surfaces[0].layers[0].paths.length);
    assert.equal(n, 1);
    await p.tap('#props-btn');
    await p.waitForSelector('.props-open #props-body .props-head');
    if (shots) await p.screenshot({ path: `${shots}/editor-${width}.png` });
    step(`טלפון ${width}px: כוכב בהקשה, לוח מאפיינים נפתח, בלי גלילה אופקית`);
    await ctx.close();
  }
} finally {
  await browser.close();
}

assert.deepEqual(errors, [], 'שגיאות בקונסול: ' + errors.join(' | '));
console.log('\nהכול עבר');
