// בדיקת דפדפן לעורך: node vector-studio/tests/e2e.mjs <כתובת בסיס> [תיקיית צילומים]
// השרת צריך להגיש את vector-studio/ ולהחזיר משתמש ב-api.php?action=me (בפיתוח — שרת PHP עם זהות מדומה).

import { createRequire } from 'node:module';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import { execFileSync } from 'node:child_process';

const require = createRequire(import.meta.url);
const { chromium } = require('playwright');

const base = (process.argv[2] || 'http://127.0.0.1:8765/vector-studio/').replace(/\/?$/, '/');
const shots = process.argv[3] || null;
const errors = [];
const step = (name) => console.log('✓ ' + name);

/** בחירת צורה מכפתור הצורות: לחיצה ראשונה — הצורה האחרונה; אחרת פותחים את הבחירה. */
async function pickShape(page, shape, tap = false) {
  const click = (sel) => tap ? page.tap(sel) : page.click(sel);
  const current = await page.evaluate(() => window.__vs.S.tool);
  if (current === shape) return;
  if (!['rect', 'ellipse', 'polygon', 'star', 'line'].includes(current)) {
    await click('[data-tool="shape"]');
    if (await page.evaluate(() => window.__vs.S.tool) === shape) return;
  }
  await click('[data-tool="shape"]');
  await click(`#shape-pick [data-shape="${shape}"]`);
}

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
  // מספרים מדויקים בבדיקות — בלי הצמדה; היא נבדקת בנפרד בסוף
  if (await page.getAttribute('#snap-btn', 'aria-pressed') === 'true') await page.click('#snap-btn');
  step('ציור חדש נפתח בעורך');

  const st = () => page.evaluate(() => {
    const S = window.__vs.S;
    return { sel: [...S.sel], paths: S.doc.surfaces[0].layers[0].paths, view: S.view, undo: S.hist.canUndo };
  });
  const box = await page.locator('#canvas').boundingBox();
  const W = async (x, y) => { const { view } = await st(); return [box.x + view.x + x * view.zoom, box.y + view.y + y * view.zoom]; };

  // מלבן בגרירה
  await pickShape(page, 'rect');
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
  assert.equal(await page.getAttribute('#undo', 'title'), 'ביטול: הזזה', 'ui-core/history — תווית הצעד בכפתור');
  step('הזזה בגרירה');

  // שינוי גודל מהידית הימנית-תחתונה (se)
  // (ידיות ui-core: פינה שומרת יחס, Shift משחרר)
  [a, b] = [await W(500, 350), await W(600, 450)];
  await page.keyboard.down('Shift');
  await page.mouse.move(...a); await page.mouse.down(); await page.mouse.move(...b, { steps: 5 }); await page.mouse.up();
  await page.keyboard.up('Shift');
  s = await st();
  const r = s.paths[0].shape.box;
  assert.ok(Math.abs(r[2] - 400) < 2 && Math.abs(r[3] - 300) < 2, 'scale ' + r);
  assert.equal(s.paths[0].stroke.width, 1, 'המתאר לא נמתח');
  assert.ok(await page.locator('.uic-handles .uic-corner').count() === 4, 'ידיות ui-core על המסך');
  // בלי Shift — הפינה שומרת יחס (4:3)
  [a, b] = [await W(600, 450), await W(700, 460)];
  await page.mouse.move(...a); await page.mouse.down(); await page.mouse.move(...b, { steps: 5 }); await page.mouse.up();
  const r2 = (await st()).paths[0].shape.box;
  assert.ok(r2[2] > 410 && Math.abs(r2[2] / r2[3] - 4 / 3) < 0.01, 'יחס נשמר ' + r2);
  await page.keyboard.press('Control+z');
  assert.ok(Math.abs((await st()).paths[0].shape.box[2] - 400) < 2, 'ביטול — חזרה ל-400');
  step('שינוי גודל מהידית — Shift חופשי, בלי Shift ביחס; הצורה נשארת מלבן, והמתאר לא נמתח');

  // ביטול וביצוע מחדש
  await page.keyboard.press('Control+z');
  s = await st();
  assert.ok(Math.abs(s.paths[0].shape.box[2] - 300) < 2);
  await page.keyboard.press('Control+Shift+z');
  s = await st();
  assert.ok(Math.abs(s.paths[0].shape.box[2] - 400) < 2);
  // מקלדת בעברית: e.key הוא האות העברית, e.code — המקש הפיזי
  const heb = (key, code, ctrl = false, shift = false) => page.evaluate(([key, code, ctrl, shift]) =>
    document.body.dispatchEvent(new KeyboardEvent('keydown', { key, code, ctrlKey: ctrl, shiftKey: shift, bubbles: true })), [key, code, ctrl, shift]);
  const nHeb = (await st()).paths.length;
  await page.evaluate(() => window.__vs.select([window.__vs.S.doc.surfaces[0].layers[0].paths[0].id]));
  await heb('ג', 'KeyD', true);
  assert.equal((await st()).paths.length, nHeb + 1, 'Ctrl+D בעברית — שכפול');
  await heb('ז', 'KeyZ', true);
  assert.equal((await st()).paths.length, nHeb, 'Ctrl+Z בעברית — ביטול');
  await heb('ז', 'KeyZ', true, true);
  assert.equal((await st()).paths.length, nHeb + 1, 'Ctrl+Shift+Z בעברית — ביצוע מחדש');
  await heb('ז', 'KeyZ', true);
  await heb('פ', 'KeyP');
  assert.equal(await page.evaluate(() => window.__vs.S.tool), 'pen', 'P בעברית — עט');
  await heb('ה', 'KeyV');
  assert.equal(await page.evaluate(() => window.__vs.S.tool), 'select', 'V בעברית — בחירה');
  step('ביטול וביצוע מחדש (גם במקלדת עברית: Ctrl+Z / Ctrl+Shift+Z / Ctrl+D ואותיות הכלים)');

  // אליפסה בהקשה (גודל ברירת מחדל), ואז בחירה במלבן של שתיהן
  await pickShape(page, 'ellipse');
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
  await page.fill('[data-w]', '4');
  await page.mouse.click(box.x + 5, box.y + box.height - 5); // לחיצה על הקנבס שומרת את השדה (blur → change)
  await page.evaluate(() => { const p = window.__vs.S.doc.surfaces[0].layers[0].paths; window.__vs.select(p.map(x => x.id)); });
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
  const z0 = (await st()).view.zoom;
  await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
  await page.keyboard.down('Control'); await page.mouse.wheel(0, -200); await page.keyboard.up('Control');
  assert.ok((await st()).view.zoom > z0 * 1.5);
  await page.click('#zoom-pct');
  assert.ok(Math.abs((await st()).view.zoom - z0) < 1e-6);
  step('זום בגלגלת והתאמה למסך');

  // עט: שתי נקודות חדות, אחת בגרירה (עקומה), סגירה בהקשה על הראשונה
  await page.click('[data-tool="pen"]');
  await page.mouse.click(...await W(100, 600));
  await page.mouse.click(...await W(300, 600));
  [a, b] = [await W(300, 900), await W(400, 900)];
  await page.mouse.move(...a); await page.mouse.down(); await page.mouse.move(...b, { steps: 5 }); await page.mouse.up();
  await page.mouse.click(...await W(100, 600));
  s = await st();
  const pen = s.paths[s.paths.length - 1];
  assert.match(pen.d, /^M100 600 L300 600 C/);
  assert.match(pen.d, /Z$/);
  assert.equal(pen.fill, null);
  assert.equal(await page.isHidden('#pen-done'), true, 'אחרי סגירה — לא בציור');
  step('עט: נקודות חדות, עקומה בגרירה, סגירה על הנקודה הראשונה');

  // עריכת נקודות: גרירת נקודה, נקודה חדשה על הקו, מחיקה
  await page.click('[data-tool="node"]');
  [a, b] = [await W(300, 600), await W(350, 550)];
  await page.mouse.move(...a); await page.mouse.down(); await page.mouse.move(...b, { steps: 5 }); await page.mouse.up();
  s = await st();
  let ed = s.paths[s.paths.length - 1];
  assert.match(ed.d, /^M100 600 L350 550 /);
  await page.mouse.click(...await W(225, 575)); // אמצע הקו הראשון
  s = await st();
  ed = s.paths[s.paths.length - 1];
  assert.match(ed.d, /^M100 600 L22[45](\.\d+)? 57[45](\.\d+)? L350 550 /);
  assert.equal(await page.isVisible('#node-del'), true);
  await page.click('#node-del');
  s = await st();
  assert.match(s.paths[s.paths.length - 1].d, /^M100 600 L350 550 /);
  await page.keyboard.press('Control+z');
  s = await st();
  assert.match(s.paths[s.paths.length - 1].d, /^M100 600 L22[45](\.\d+)? 57[45](\.\d+)? /);
  step('עריכת נקודות: גרירה, נקודה חדשה על הקו, מחיקה וביטול');

  // מלבן שנערך בנקודות — הופך לנתיב רגיל
  await page.click('[data-tool="node"]');
  await page.mouse.click(...await W(300, 250)); // בתוך המלבן — נבחר לעריכה
  [a, b] = [await W(200, 150), await W(180, 130)];
  await page.mouse.move(...a); await page.mouse.down(); await page.mouse.move(...b, { steps: 3 }); await page.mouse.up();
  s = await st();
  assert.equal(s.paths[0].shape, undefined);
  await page.keyboard.press('Control+z');
  s = await st();
  assert.equal(s.paths[0].shape.type, 'rect');
  await page.click('[data-tool="select"]');
  step('צורה חיה שנערכת בנקודות הופכת לנתיב; ביטול מחזיר אותה');

  // קבוצה: הכול לקבוצה אחת, הקשה על חלק בוחרת את הקבוצה, הזזה מזיזה את כולם, פירוק
  await page.keyboard.press('Control+a');
  await page.keyboard.press('Control+g');
  s = await st();
  assert.equal(s.paths.length, 1);
  assert.equal(s.paths[0].type, 'group');
  assert.equal(s.paths[0].children.length, 3);
  await page.mouse.click(...await W(800, 800)); // רקע
  await page.mouse.click(...await W(300, 250)); // בתוך המלבן
  s = await st();
  assert.deepEqual(s.sel, [s.paths[0].id]);
  [a, b] = [await W(300, 250), await W(310, 260)];
  await page.mouse.move(...a); await page.mouse.down(); await page.mouse.move(...b, { steps: 3 }); await page.mouse.up();
  s = await st();
  assert.deepEqual(s.paths[0].children[0].shape.box.slice(0, 2), [210, 160]);
  assert.deepEqual(s.paths[0].children[1].shape.box.slice(0, 2), [810, 810]);
  await page.keyboard.press('Control+Shift+g');
  s = await st();
  assert.equal(s.paths.length, 3);
  assert.equal(s.sel.length, 3);
  step('קבוצה: קיבוץ, בחירה בהקשה, הזזה של כולם ופירוק');

  // יישור לשמאל של מלבן ואליפסה
  await page.evaluate(() => { const p = window.__vs.S.doc.surfaces[0].layers[0].paths; window.__vs.select([p[0].id, p[1].id]); });
  await page.click('[data-arr="align"][data-arg="left"]');
  s = await st();
  assert.equal(s.paths[1].shape.box[0], s.paths[0].shape.box[0]);
  await page.click('[data-arr="order"][data-arg="back"]'); // כבר בתחתית — בלי שינוי
  step('יישור לשמאל ולחצני סדר הצגה');

  // שכבות: שכבה חדשה מקבלת את הצורה הבאה; שכבה נעולה — לא נבחרת
  await page.mouse.click(...await W(1000, 1000)); // רקע — בלי בחירה
  await page.click('[data-tab="layers"]');
  await page.click('[data-l="add"]');
  let layers = await page.evaluate(() => window.__vs.S.doc.surfaces[0].layers.map(l => l.id));
  assert.deepEqual(layers, ['L1', 'L2']);
  await pickShape(page, 'rect');
  [a, b] = [await W(600, 100), await W(700, 200)];
  await page.mouse.move(...a); await page.mouse.down(); await page.mouse.move(...b, { steps: 3 }); await page.mouse.up();
  const l2 = await page.evaluate(() => window.__vs.S.doc.surfaces[0].layers[1].paths.length);
  assert.equal(l2, 1);
  await page.click('.layer-row[data-id="L2"] [data-l="locked"]');
  await page.mouse.click(...await W(1000, 1000));
  await page.mouse.click(...await W(650, 150));
  s = await st();
  assert.deepEqual(s.sel, []);
  await page.click('[data-tab="props"]');
  step('שכבות: שכבה חדשה פעילה, ושכבה נעולה לא נבחרת');

  // טקסט: הקשה עם כלי הטקסט, הקלדה בעברית, Esc; הקשה כפולה — עריכה; גודל ומודגש מהלוח; ריק — נמחק
  await page.evaluate(() => window.__vs.fontsReady);
  await page.click('[data-tool="text"]');
  await page.mouse.click(...await W(900, 100));
  await page.keyboard.type('שלום עולם');
  await page.keyboard.press('Escape');
  s = await st();
  let tx = s.paths.find(p => p.type === 'text');
  assert.equal(tx.text, 'שלום עולם');
  assert.ok(Math.abs(tx.box[0] + tx.box[2] - 900) < 1, 'הקצה הימני בנקודת ההקשה');
  assert.ok(tx.box[2] > 100 && tx.box[2] < 250, 'רוחב לפי הגופן: ' + tx.box[2]);
  const w36 = tx.box[2];
  await page.mouse.dblclick(...await W(tx.box[0] + tx.box[2] / 2, tx.box[1] + tx.box[3] / 2));
  assert.equal(await page.isVisible('#text-edit'), true);
  await page.keyboard.press('End');
  await page.keyboard.press('Enter');
  await page.keyboard.type('שורה שנייה');
  await page.keyboard.press('Escape');
  s = await st();
  tx = s.paths.find(p => p.type === 'text');
  assert.equal(tx.text, 'שלום עולם\nשורה שנייה');
  assert.ok(Math.abs(tx.box[3] - 2 * 36 * 1.25) < 0.01);
  await page.fill('[data-t="size"]', '72'); await page.press('[data-t="size"]', 'Enter');
  await page.click('fieldset[data-k="weight"] label:nth-child(2)');
  s = await st();
  tx = s.paths.find(p => p.type === 'text');
  assert.equal(tx.font.size, 72);
  assert.equal(tx.font.weight, 700);
  assert.ok(tx.box[2] > w36 * 1.8, 'גדל עם הגופן');
  await page.click('[data-tool="text"]');
  await page.mouse.click(...await W(900, 1000));
  await page.keyboard.press('Escape');
  s = await st();
  assert.equal(s.paths.filter(p => p.type === 'text').length, 1, 'טקסט ריק לא נשאר');
  assert.deepEqual(s.sel, []);
  assert.equal(await page.isVisible('[data-a="w"]'), true, 'הלוח מציג את הלוח, לא את הטקסט שנמחק');
  if (shots) await page.screenshot({ path: `${shots}/text-1280.png` });
  step('טקסט: הקלדה בעברית, עריכה בהקשה כפולה, גודל ומודגש, ריק נמחק');

  // הצמדה: מלבן שנגרר קרוב לשפת הלוח נצמד אליה, ומוצג קו עזר; רשת — לכפולות של 10
  await page.click('#snap-btn');
  await page.click('[data-tool="select"]');
  await page.evaluate(() => { const p = window.__vs.S.doc.surfaces[0].layers[0].paths; window.__vs.select([p[0].id]); });
  s = await st();
  let r0 = s.paths[0].shape.box;
  [a, b] = [await W(r0[0] + 20, r0[1] + 20), await W(17, r0[1] + 20)]; // השמאל יגיע ל-x=-3
  await page.mouse.move(...a); await page.mouse.down(); await page.mouse.move(...b, { steps: 4 });
  const guides = await page.evaluate(() => window.__vs.S.guides);
  await page.mouse.up();
  s = await st();
  assert.equal(s.paths[0].shape.box[0], 0, 'נצמד לשפת הלוח');
  assert.ok(guides?.some(g => g.axis === 'x' && g.v === 0), 'קו עזר');
  await page.click('#snap-btn');
  await page.click('#grid-btn');
  [a, b] = [await W(20, r0[1] + 20), await W(20 + 33, r0[1] + 20)];
  await page.mouse.move(...a); await page.mouse.down(); await page.mouse.move(...b, { steps: 4 }); await page.mouse.up();
  s = await st();
  assert.equal(s.paths[0].shape.box[0] % 10, 0, 'רשת: ' + s.paths[0].shape.box[0]);
  await page.click('#grid-btn');
  step('הצמדה ללוח עם קו עזר, והצמדה לרשת');

  // מעבר צבע מהלוח: מלבן → מעבר, צבע סוף, זווית 90
  await page.evaluate(() => { const p = window.__vs.S.doc.surfaces[0].layers[0].paths; window.__vs.select([p[0].id]); });
  await page.click('fieldset[data-k="fillmode"] label:nth-child(2)');
  await page.locator('[data-g="1"]').evaluate((el) => { el.value = '#1e7b3a'; el.dispatchEvent(new Event('change', { bubbles: true })); });
  await page.fill('[data-ga]', '90'); await page.press('[data-ga]', 'Enter');
  s = await st();
  const g0 = s.paths[0].fill.gradient;
  const rb = s.paths[0].shape.box;
  assert.equal(g0.stops[1].color, '#1e7b3a');
  assert.deepEqual([g0.from[0], g0.to[0]], [rb[0] + rb[2] / 2, rb[0] + rb[2] / 2], 'אנכי: אותו x');
  assert.deepEqual([g0.from[1], g0.to[1]], [rb[1], rb[1] + rb[3]]);
  [a, b] = [await W(rb[0] + 20, rb[1] + 20), await W(rb[0] + 70, rb[1] + 20)];
  await page.mouse.move(...a); await page.mouse.down(); await page.mouse.move(...b, { steps: 3 }); await page.mouse.up();
  s = await st();
  assert.equal(s.paths[0].fill.gradient.from[0], g0.from[0] + 50, 'המעבר זז עם הצורה');
  // מעבר עגול: מהמרכז של הצורה, אותם צבעים; וחזרה לישר
  await page.click('fieldset[data-k="fillmode"] label:nth-child(3)');
  s = await st();
  const rg = s.paths[0].fill.gradient, rb2 = s.paths[0].shape.box;
  assert.equal(rg.type, 'radial');
  assert.deepEqual(rg.center, [rb2[0] + rb2[2] / 2, rb2[1] + rb2[3] / 2]);
  assert.equal(rg.stops[1].color, '#1e7b3a');
  assert.equal(await page.locator('[data-ga]').count(), 0, 'לעגול אין זווית');
  await page.click('fieldset[data-k="fillmode"] label:nth-child(2)');
  assert.equal((await st()).paths[0].fill.gradient.type, 'linear');
  step('מעבר צבע: ישר ועגול, צבעים וזווית מהלוח, וזז עם הצורה');

  // צבעים וסגנונות שמורים: פלטה ראשונה, הקשה — למילוי / למתאר, הוספה נשמרת בשרת, סגנון בשם מוחל על צורה אחרת
  await page.evaluate(async () => {
    const { shapeToD } = await import('./assets/js/geometry/shapes.js');
    const L = window.__vs.S.doc.surfaces[0].layers[0];
    for (const [id, x] of [['sw1', 700], ['sw2', 850]]) { const shape = { type: 'rect', box: [x, 900, 100, 60], radius: 0 }; L.paths.push({ id, d: shapeToD(shape), shape, fill: { color: '#cccccc' }, stroke: null }); }
    window.__vs.select(['sw1']);
  });
  await page.waitForSelector('.swatches .swatch[data-sw="2"]');
  const swOf = (id) => page.evaluate((id) => window.__vs.S.doc.surfaces[0].layers[0].paths.find(p => p.id === id), id);
  await page.click('.swatch[data-sw="2"]');
  assert.equal((await swOf('sw1')).fill.color, '#2d4a8a');
  await page.click('fieldset[data-k="svtarget"] label:nth-child(2)');
  await page.click('.swatch[data-sw="5"]');
  assert.equal((await swOf('sw1')).stroke.color, '#c0392b');
  await page.click('fieldset[data-k="svtarget"] label:nth-child(1)');
  await page.evaluate(() => { const p = window.__vs.S.doc.surfaces[0].layers[0].paths.find(x => x.id === 'sw1'); p.fill.color = '#123456'; window.__vs.select(['sw1']); });
  await page.click('[data-sv="add-color"]');
  await page.waitForFunction(() => fetch('api.php?action=prefs').then(r => r.json()).then(d => d.swatches.includes('#123456')));
  await page.click('[data-sv="add-style"]');
  await page.fill('dialog[open] input[name="v"]', 'כפתור כחול');
  await page.click('dialog[open] button[type="submit"]');
  await page.waitForSelector('.style-chip');
  await page.evaluate(() => window.__vs.select(['sw2']));
  await page.click('.style-chip');
  const sw2 = await swOf('sw2');
  assert.deepEqual([sw2.fill.color, sw2.stroke.color], ['#123456', '#c0392b'], 'הסגנון הוחל');
  // עריכה: הסרת צבע
  const nSw = await page.locator('.swatches [data-sw]').count();
  await page.click('[data-sv="manage"]');
  await page.click('.swatch[data-sw="0"]');
  await page.waitForFunction((n) => document.querySelectorAll('.swatches [data-sw]').length === n - 1, nSw);
  await page.click('[data-sv="manage"]');
  await page.evaluate(() => { const L = window.__vs.S.doc.surfaces[0].layers[0]; L.paths = L.paths.filter(p => !['sw1', 'sw2'].includes(p.id)); window.__vs.select([]); });
  step('צבעים וסגנונות שמורים: פלטה, למילוי ולמתאר, נשמרים בשרת, סגנון בשם, הסרה');

  // מתאר משוכלל: קצה חץ בסוף ומעבר צבע במתאר — מלוח המאפיינים
  await page.evaluate(() => { const L = window.__vs.S.doc.surfaces[0].layers[0]; L.paths.push({ id: 'ar1', d: 'M600 900 L900 900', fill: null, stroke: { color: '#16192b', width: 4, cap: 'butt', join: 'miter', dash: [] } }); window.__vs.select(['ar1']); });
  await page.click('fieldset[data-k="end"] label:nth-child(2)');   // ➤ חץ
  await page.click('fieldset[data-k="strokemode"] label:nth-child(2)');   // מעבר צבע
  let ar = await page.evaluate(() => window.__vs.S.doc.surfaces[0].layers[0].paths.find(p => p.id === 'ar1').stroke);
  assert.equal(ar.end, 'arrow');
  assert.ok(ar.gradient?.stops?.length === 2 && !ar.color, 'מתאר במעבר צבע: ' + JSON.stringify(ar));
  await page.click('fieldset[data-k="end"] label:nth-child(1)');   // בלי
  ar = await page.evaluate(() => window.__vs.S.doc.surfaces[0].layers[0].paths.find(p => p.id === 'ar1').stroke);
  assert.equal(ar.end, undefined, 'בלי קצה — השדה נמחק');
  await page.evaluate(() => { const L = window.__vs.S.doc.surfaces[0].layers[0]; L.paths = L.paths.filter(p => p.id !== 'ar1'); window.__vs.select([]); });
  step('מתאר משוכלל: חץ בקצה ומעבר צבע במתאר, מלוח המאפיינים');

  // אפקטים: צל מלוח המאפיינים — בקנבס (פיקסל כהה ליד הצורה), ב-SVG (filter) וב-PDF (תמונה מתחת)
  await page.evaluate(() => { const L = window.__vs.S.doc.surfaces[0].layers[0]; L.paths.push({ id: 'fx1', d: 'M600 900 L760 900 L760 1000 L600 1000 Z', fill: { color: '#ffffff' }, stroke: null }); window.__vs.select(['fx1']); });
  await page.check('[data-fxon="shadow"]');
  await page.fill('[data-fx="shadow"][data-fk="dx"]', '20'); await page.press('[data-fx="shadow"][data-fk="dx"]', 'Enter');
  await page.fill('[data-fx="shadow"][data-fk="opacity"]', '100'); await page.press('[data-fx="shadow"][data-fk="opacity"]', 'Enter');
  const fx = await page.evaluate(() => window.__vs.S.doc.surfaces[0].layers[0].paths.find(p => p.id === 'fx1').effects);
  assert.deepEqual(fx.map(e => [e.type, e.dx, e.opacity]), [['shadow', 20, 1]]);
  await page.waitForTimeout(150);
  const shade = await page.evaluate(() => {
    const S = window.__vs.S, c = document.getElementById('canvas');
    const at = (x, y) => [...c.getContext('2d').getImageData(Math.round((x * S.view.zoom + S.view.x) * S.dpr), Math.round((y * S.view.zoom + S.view.y) * S.dpr), 1, 1).data];
    return { shadow: at(770, 960), clear: at(800, 960) };
  });
  assert.ok(shade.shadow[0] < 120, 'צל ליד הצורה: ' + shade.shadow);
  assert.ok(shade.clear[0] > 200, 'רחוק מהצל — בהיר: ' + shade.clear);
  await page.evaluate(() => { const L = window.__vs.S.doc.surfaces[0].layers[0]; L.paths = L.paths.filter(p => p.id !== 'fx1'); window.__vs.select([]); });
  step('אפקטים: צל מלוח המאפיינים, מצויר בקנבס');

  // סיבוב: ידית הסיבוב (Shift — 15°), כפתור 90°, היפוך; הצורה נשארת חיה
  await page.evaluate(() => { const p = window.__vs.S.doc.surfaces[0].layers[0].paths; window.__vs.select([p[1].id]); }); // האליפסה
  s = await st();
  const eb = s.paths[1].shape.box;
  const rc = [eb[0] + eb[2] / 2, eb[1] + eb[3] / 2];
  const hTop = await W(rc[0], eb[1]);
  const hRot = [hTop[0], hTop[1] - 28];
  const cS = await W(...rc);
  await page.keyboard.down('Shift');
  await page.mouse.move(...hRot); await page.mouse.down();
  await page.mouse.move(cS[0] + 80, cS[1], { steps: 6 }); // 90° עם השעון
  await page.mouse.up();
  await page.keyboard.up('Shift');
  s = await st();
  const T = s.paths[1].transform;
  assert.ok(T && Math.abs(T[0]) < 1e-6 && Math.abs(T[1] - 1) < 1e-6, 'סיבוב 90°: ' + JSON.stringify(T));
  assert.equal(s.paths[1].shape.type, 'ellipse');
  await page.click('[data-arr="rotate"][data-arg="-90"]');
  s = await st();
  assert.equal(s.paths[1].transform, undefined, 'סיבוב חזרה — בלי transform');
  step('סיבוב בידית (Shift — 90°) ובכפתור; הצורה נשארת חיה');

  // לוחות: לוח שני, ואובייקט שנגרר אליו עובר אליו
  await page.mouse.click(...await W(1000, 1060)); // רקע בלוח — בלי בחירה
  await page.click('[data-board="add"]');
  let surf = await page.evaluate(() => window.__vs.S.doc.surfaces.map(x => x.id));
  assert.equal(surf.length, 2);
  await page.click('#zoom-pct'); // התאמה — שני הלוחות במסך
  s = await st();
  const eb2 = s.paths[1].shape.box;
  [a, b] = [await W(eb2[0] + eb2[2] / 2, eb2[1] + eb2[3] / 2), await W(1080 + 48 + 300, 300)];
  await page.mouse.move(...a); await page.mouse.down(); await page.mouse.move(...b, { steps: 6 }); await page.mouse.up();
  const moved = await page.evaluate(() => window.__vs.S.doc.surfaces[1].layers[0].paths.map(p => p.shape?.box));
  assert.equal(moved.length, 1, 'האליפסה עברה ללוח השני');
  assert.ok(Math.abs(moved[0][0] + moved[0][2] / 2 - 300) < 3, 'באותו מקום על המסך: ' + moved[0]);
  await page.keyboard.press('Control+z');
  surf = await page.evaluate(() => window.__vs.S.doc.surfaces[1].layers[0].paths.length);
  assert.equal(surf, 0, 'ביטול מחזיר ללוח הראשון');
  await page.mouse.click(...await W(1080 + 48 + 500, 500));
  await page.click('.boards [data-id="s2"] [data-board="remove"]');
  assert.equal(await page.evaluate(() => window.__vs.S.doc.surfaces.length), 1);
  step('לוחות: הוספה, מעבר אובייקט בין לוחות בגרירה, מחיקה');

  // העתקה והדבקה: Ctrl+C / Ctrl+V באותו ציור (מוזז 10pt)
  await page.evaluate(() => { const p = window.__vs.S.doc.surfaces[0].layers[0].paths; window.__vs.select([p[0].id]); });
  const before2 = (await st()).paths.length;
  await page.keyboard.press('Control+c');
  await page.keyboard.press('Control+v');
  s = await st();
  assert.equal(s.paths.length, before2 + 1);
  assert.equal(s.paths[s.paths.length - 1].shape.box[0], s.paths[0].shape.box[0] + 10);
  await page.keyboard.press('Delete');
  step('העתקה והדבקה (Ctrl+C / Ctrl+V)');

  // פעולות צורה: שני ריבועים חופפים → איחוד, ביטול → חיסור; טקסט → קווי מתאר
  await page.evaluate(() => {
    const { S, select } = window.__vs;
    const L = S.doc.surfaces[0].layers[0];
    L.paths.push({ id: 'b1', d: 'M600 600 L700 600 L700 700 L600 700 Z', fill: { color: '#2d4a8a' }, stroke: null },
                 { id: 'b2', d: 'M650 650 L750 650 L750 750 L650 750 Z', fill: { color: '#e19a00' }, stroke: null });
    select(['b1', 'b2']);
  });
  const nBool = (await st()).paths.length;
  await page.click('[data-arr="bool"][data-arg="union"]');
  await page.waitForFunction((n) => window.__vs.S.doc.surfaces[0].layers[0].paths.length === n - 1, nBool);
  s = await st();
  const un = s.paths.find(p => s.sel.includes(p.id));
  assert.equal(un.fill.color, '#e19a00', 'איחוד — הסגנון של העליון');
  assert.equal((un.d.match(/L/g) || []).length, 7, 'מצולע של 8 קודקודים: ' + un.d);
  await page.keyboard.press('Control+z');
  await page.evaluate(() => window.__vs.select(['b1', 'b2']));
  await page.click('[data-arr="bool"][data-arg="subtract"]');
  await page.waitForFunction((n) => window.__vs.S.doc.surfaces[0].layers[0].paths.length === n - 1, nBool);
  s = await st();
  const sub = s.paths.find(p => s.sel.includes(p.id));
  assert.equal(sub.fill.color, '#2d4a8a', 'חיסור — הסגנון של התחתון');
  await page.keyboard.press('Control+z');
  // שני עיגולים: האיחוד נשאר עקומות (לא מצולע)
  await page.evaluate(async () => {
    const { S, select } = window.__vs;
    const { shapeToD } = await import('./assets/js/geometry/shapes.js');
    const c = (id, x) => { const shape = { type: 'ellipse', box: [x, 600, 100, 100] }; return { id, d: shapeToD(shape), shape, fill: { color: '#2d4a8a' }, stroke: null }; };
    S.doc.surfaces[0].layers[0].paths.push(c('c1', 600), c('c2', 650));
    select(['c1', 'c2']);
  });
  await page.click('[data-arr="bool"][data-arg="union"]');
  await page.waitForFunction(() => !window.__vs.S.doc.surfaces[0].layers[0].paths.some(p => p.id === 'c1'));
  s = await st();
  const cu = s.paths.find(p => s.sel.includes(p.id));
  assert.equal((cu.d.match(/L/g) || []).length, 0, 'בלי קווים: ' + cu.d);
  assert.ok((cu.d.match(/C/g) || []).length <= 10, 'מעט עקומות: ' + cu.d);
  await page.keyboard.press('Control+z');
  // חיתוך בלי חפיפה עם טקסט: "לא נשאר שטח", והטקסט נשאר טקסט
  const tid0 = (await st()).paths.find(p => p.type === 'text').id;
  await page.evaluate((t) => window.__vs.select(['c1', t]), tid0);
  await page.click('[data-arr="bool"][data-arg="intersect"]');
  await page.waitForFunction(() => /לא נשאר/.test(document.querySelector('.toast').textContent));
  assert.equal((await st()).paths.find(p => p.id === tid0).type, 'text');
  await page.evaluate(() => { const L = window.__vs.S.doc.surfaces[0].layers[0]; L.paths = L.paths.filter(p => !['c1', 'c2'].includes(p.id)); });
  await page.evaluate(() => window.__vs.select(['b1', 'b2']));
  await page.keyboard.press('Delete');
  const tid = (await st()).paths.find(p => p.type === 'text').id;
  await page.evaluate((id) => window.__vs.select([id]), tid);
  await page.click('[data-arr="outline"]');
  await page.waitForFunction((id) => window.__vs.S.doc.surfaces[0].layers[0].paths.find(p => p.id === id)?.type !== 'text', tid);
  s = await st();
  const ol = s.paths.find(p => p.id === tid);
  assert.ok(ol.d.length > 200 && ol.name.startsWith('שלום'), 'טקסט הפך לנתיב');
  await page.keyboard.press('Control+z');
  assert.equal((await st()).paths.find(p => p.id === tid).type, 'text');
  step('פעולות צורה (איחוד, חיסור, עיגולים נשארים עקומות) וטקסט לקווי מתאר, עם ביטול');

  // עיפרון ומברשת: גרירה בעכבר → נתיב חלק (עיפרון — מתאר עגול; מברשת — צורה מלאה), וביטול מוחק
  const nFree = (await st()).paths.length;
  await page.click('[data-tool="draw"]');
  assert.equal(await page.evaluate(() => window.__vs.S.tool), 'pencil');
  const wave = async () => {
    const [x0, y0] = await W(150, 700);
    await page.mouse.move(x0, y0); await page.mouse.down();
    for (let i = 1; i <= 30; i++) await page.mouse.move(x0 + i * 8, y0 + Math.sin(i / 5) * 30);
    await page.mouse.up();
  };
  await wave();
  s = await st();
  const pc = s.paths[s.paths.length - 1];
  assert.equal(s.paths.length, nFree + 1);
  assert.equal(pc.fill, null);
  assert.deepEqual([pc.stroke.cap, pc.stroke.join, pc.stroke.width], ['round', 'round', 2]);
  assert.match(pc.d, /^M[\d. ]+ C/);
  assert.ok((pc.d.match(/C/g) || []).length < 15, 'מוחלק: ' + pc.d);
  await page.click('[data-tool="draw"]');
  await page.click('[data-free="brush"]');
  await page.fill('#free-width', '20'); await page.press('#free-width', 'Enter');
  await wave();
  s = await st();
  const br = s.paths[s.paths.length - 1];
  assert.equal(s.paths.length, nFree + 2);
  assert.equal(br.stroke, null);
  assert.ok(br.fill.color && /Z$/.test(br.d), 'מברשת — צורה סגורה');
  assert.equal(await page.evaluate(() => JSON.parse(localStorage.getItem('vector-studio:free')).brush.width), 20);
  await page.keyboard.press('Control+z');
  await page.keyboard.press('Control+z');
  assert.equal((await st()).paths.length, nFree);
  await page.keyboard.press('v');
  step('עיפרון ומברשת: ציור בגרירה, מוחלק לעקומות, עובי וצבע נשמרים, ביטול');

  // ייצוא: SVG, PDF ו-PNG יורדים, עם טקסט כקווי מתאר
  const grab = async (kind) => {
    await page.click('#file-btn');
    const [dl] = await Promise.all([page.waitForEvent('download'), page.click(`[data-export="${kind}"]`)]);
    const buf = fs.readFileSync(await dl.path());
    return { name: dl.suggestedFilename(), buf };
  };
  const svgOut = await grab('svg');
  // Chromium בלי לוקאל UTF-8 (כמו בקונטיינר) מחליף שם בעברית ב-"download"; בטלפון ובמחשב השם נשמר
  assert.match(svgOut.name, /\.svg$|^download$/);
  const svgText = svgOut.buf.toString('utf8');
  assert.match(svgText, /viewBox="0 0 1080 1080"/);
  assert.match(svgText, /aria-label="שלום עולם/);
  assert.doesNotMatch(svgText, /<text/);
  assert.match(svgText, /<linearGradient id="grad1" gradientUnits="userSpaceOnUse"/);
  assert.match(svgText, /fill="url\(#grad1\)"/);
  assert.match(await page.textContent('.toast'), /קווי מתאר/);
  const pdfOut = await grab('pdf');
  assert.equal(pdfOut.buf.subarray(0, 5).toString(), '%PDF-');
  const pngOut = await grab('png');
  assert.equal(pngOut.buf.subarray(1, 4).toString(), 'PNG');
  assert.equal(pngOut.buf.readUInt32BE(16), 2160, 'רוחב ×2');
  // ‎.mbe: עובר את הליבה בשתי השפות, עם הגופן והייצוגים
  const mbeOut = await grab('mbe');
  const { open: mbeOpen } = await import('../assets/js/mbe/mbe.js');
  const mf = await mbeOpen(new Uint8Array(mbeOut.buf));
  assert.equal(mf.ok, true, JSON.stringify(mf.issues));
  assert.equal(mf.kind, 'vector');
  assert.ok(mf.document.fonts?.some(x => x.family === 'Heebo'));
  assert.deepEqual(mf.renditions().map(r => r.path).sort(), ['renditions/document.pdf', 'renditions/drawing.svg', 'renditions/preview.png']);
  assert.ok(mf.renditions().every(r => !r.stale));
  const tmpMbe = `${os.tmpdir()}/vs-e2e-${process.pid}.mbe`;
  fs.writeFileSync(tmpMbe, mbeOut.buf);
  const phpIssues = JSON.parse(execFileSync('php', ['-r', `require '${new URL('../lib/mbe/mbe.php', import.meta.url).pathname}'; echo json_encode(Mbe::validate(file_get_contents($argv[1])));`, tmpMbe], { encoding: 'utf8' }));
  assert.deepEqual(phpIssues.filter(i => i.level === 'error'), []);
  const docPaths = (await st()).paths.length;
  step('הורדה כ-‎.mbe: עובר את הליבה ב-JS וב-PHP, עם גופן מוטמע ושלושה ייצוגים עדכניים');
  if (shots) { fs.writeFileSync(`${shots}/export.svg`, svgOut.buf); fs.writeFileSync(`${shots}/export.pdf`, pdfOut.buf); fs.writeFileSync(`${shots}/export.png`, pngOut.buf); }
  step('ייצוא SVG, PDF ו-PNG (×2), טקסט כקווי מתאר');

  // תמונה בתוך הציור: מהמכשיר → עולה לשרת, אובייקט בלוח (בתוך 80%), אטימות ויחס מקורי, וביטול
  const imgPng = `${os.tmpdir()}/vs-img-${process.pid}.png`;
  fs.writeFileSync(imgPng, Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAIAAAABCAIAAAB7QOjdAAAADUlEQVR4nGP4z8AARAAI/gH/xp559wAAAABJRU5ErkJggg==', 'base64'));
  const nImg = (await st()).paths.length;
  await page.click('#file-btn');
  const [ich] = await Promise.all([page.waitForEvent('filechooser'), page.click('#imp-image')]);
  await ich.setFiles(imgPng);
  await page.waitForFunction((n) => window.__vs.S.doc.surfaces[0].layers[0].paths.length === n + 1, nImg);
  s = await st();
  const im = s.paths[s.paths.length - 1];
  assert.equal(im.type, 'image');
  assert.match(im.src, /^media\/[0-9a-f]{64}\.png$/);
  assert.deepEqual(s.sel, [im.id]);
  assert.ok(Math.abs(im.box[2] / im.box[3] - 2) < 0.01 && im.box[2] <= 1080 * 0.8 + 0.01, 'יחס 2:1, עד 80% מהלוח: ' + im.box);
  assert.equal(await page.textContent('#props-body h2'), 'תמונה');
  await page.fill('[data-io]', '50'); await page.press('[data-io]', 'Enter');
  assert.equal((await st()).paths.find(p => p.id === im.id).opacity, 0.5);
  await page.evaluate((id) => { const p = window.__vs.S.doc.surfaces[0].layers[0].paths.find(x => x.id === id); p.box[3] = 10; }, im.id);
  await page.click('[data-act="image-ratio"]');
  const ib = (await st()).paths.find(p => p.id === im.id).box;
  assert.ok(Math.abs(ib[2] / ib[3] - 2) < 0.01, 'יחס מקורי: ' + ib);
  const isvg = (await grab('svg')).buf.toString('utf8');
  assert.match(isvg, new RegExp(`<image id="${im.id}" [^>]*href="data:image/png;base64,`));
  const imbe = await (await import('../assets/js/mbe/mbe.js')).open(new Uint8Array((await grab('mbe')).buf));
  assert.equal(imbe.ok, true, JSON.stringify(imbe.issues));
  assert.ok(imbe.media().some(m => m.path === im.src), 'התמונה ב-media/ של הקובץ');
  fs.unlinkSync(imgPng);
  step('תמונה בתוך הציור: מהמכשיר, אובייקט בלוח, אטימות ויחס מקורי, ב-SVG וב-‎.mbe');

  // ייבוא SVG: צורות, תורשת סגנון, טרנספורמציה, viewBox; מה שלא נתמך — בדוח
  const before = (await st()).paths.length;
  await page.click('#file-btn');
  const [chooser] = await Promise.all([page.waitForEvent('filechooser'), page.click('#imp-svg')]);
  await chooser.setFiles(new URL('./fixtures/sample.svg', import.meta.url).pathname);
  await page.waitForFunction((n) => window.__vs.S.doc.surfaces[0].layers[0].paths.length > n, before);
  s = await st();
  const imp = s.paths[s.paths.length - 1];
  assert.equal(imp.type, 'group');
  assert.deepEqual(s.sel, [imp.id]);
  const flat = (it) => it.type === 'group' ? it.children.flatMap(flat) : [it];
  const parts = flat(imp);
  assert.equal(parts.length, 7); // מלבן, עיגול, נתיב, קו, מלבן עם מעבר ליניארי, עיגול עם מעבר עגול, מלבן עם מעבר שלא קיים (שחור)
  const rgi = parts.find(p => p.fill?.gradient?.type === 'radial');
  assert.deepEqual([rgi.fill.gradient.center, rgi.fill.gradient.radius], [[170, 20], 10]); // objectBoundingBox, חצי בגלל viewBox
  const lg = parts.find(p => p.fill?.gradient);
  assert.deepEqual(lg.fill.gradient.from, [100, 60]); // objectBoundingBox של המלבן (200,120 → חצי בגלל viewBox)
  assert.deepEqual(lg.fill.gradient.to, [140, 60]);
  assert.equal(lg.fill.gradient.stops[1].opacity, 0.5);
  const rect = parts.find(p => p.shape?.type === 'rect' && p.shape.radius > 0);
  assert.deepEqual(rect.shape.box, [5, 5, 50, 30]); // viewBox 400 → width 200: חצי
  assert.equal(rect.shape.radius, 4);
  assert.equal(rect.fill.color, '#2d4a8a');
  assert.equal(rect.stroke.width, 1); // 2 × חצי
  const circ = parts.find(p => p.shape?.type === 'ellipse');
  assert.equal(circ.fill.color, '#e19a00');
  assert.equal(circ.stroke, null);
  const dashed = parts.find(p => !p.shape && p.stroke?.dash?.length);
  assert.match(dashed.d, /^M155 15 /);
  assert.equal(dashed.fill, null);
  const toastText = await page.textContent('.toast');
  assert.match(toastText, /<text>/);
  assert.match(toastText, /מעבר צבע/);
  assert.match(toastText, /סקריפט/);
  // גודל לפי viewBox כשהרוחב באחוזים / חסר; שקיפות מצטברת; מתאר של מסובב לא מוכפל פעמיים
  const imp2 = await page.evaluate(async () => {
    const { importSVG } = await import('./assets/js/import.js');
    let n = 0; const next = () => 'q' + (++n);
    const a = importSVG('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 600" width="100%" height="100%"><rect width="800" height="600"/></svg>', next).size;
    const b = importSVG('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 50" width="200"><rect width="100" height="50"/></svg>', next).size;
    const c = importSVG('<svg xmlns="http://www.w3.org/2000/svg"><g opacity=".5"><rect width="10" height="10" opacity=".5"/></g></svg>', next).items[0].fill.opacity;
    const d = importSVG('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10" width="100" height="100"><rect width="4" height="4" transform="rotate(45 5 5)" stroke="#000" stroke-width="1"/></svg>', next).items[0];
    return { a, b, c, w: d.stroke.width, k: Math.hypot(d.transform[0], d.transform[1]) };
  });
  assert.deepEqual([imp2.a, imp2.b, imp2.c], [[800, 600], [200, 100], 0.25]);
  assert.ok(Math.abs(imp2.w * imp2.k - 10) < 1e-3, 'מתאר 10pt בפועל: ' + JSON.stringify(imp2));
  step('ייבוא SVG: צורות חיות, סגנון ותורשה, טרנספורמציה ו-viewBox (גם באחוזים), ודוח על מה שלא עבר');

  // שמירה בשרת: אחרי שקט קצר — "נשמר"; טעינה מחדש מחזירה את אותו מסמך; ברשימה — עם תמונה ממוזערת
  await page.waitForFunction(() => document.getElementById('doc-status').textContent === 'נשמר', null, { timeout: 10000 });
  if (shots) await page.screenshot({ path: `${shots}/editor-1280.png` });
  const savedId = await page.evaluate(() => window.__vs.S.id);
  const docBefore = await page.evaluate(() => JSON.stringify(window.__vs.S.doc));
  await page.reload();
  await page.waitForFunction(() => window.__vs?.S.doc);
  // התמונה שבציור — נטענת מהשרת
  await page.waitForFunction(() => [...window.__vs.S.media.keys()].some(k => /^media\/[0-9a-f]{64}\.png$/.test(k)), null, { timeout: 10000 });
  assert.equal(await page.evaluate(() => JSON.stringify(window.__vs.S.doc)), docBefore, 'המסמך חזר מהשרת זהה');
  assert.equal(await page.evaluate(() => location.hash), '#d' + savedId);
  // שינוי שם מהכותרת
  await page.click('#doc-name');
  // חלון השאלה של ui-core, עם השם הנוכחי בתוכו
  assert.equal(await page.inputValue('dialog[open] input[name="v"]'), await page.evaluate(() => window.__vs.S.name));
  await page.fill('dialog[open] input[name="v"]', 'לוגו לבדיקה');
  await page.click('dialog[open] button[type="submit"]');
  await page.waitForFunction(() => document.getElementById('doc-status').textContent === 'נשמר', null, { timeout: 10000 });
  await page.click('a[href="./"]');
  await page.waitForSelector(`#drafts .card[data-id="${savedId}"] img.thumb`);
  assert.match(await page.textContent(`#drafts .card[data-id="${savedId}"]`), /לוגו לבדיקה/);
  const thumbOk = await page.evaluate((id) => { const i = document.querySelector(`.card[data-id="${id}"] img`); return i.complete && i.naturalWidth > 0; }, savedId);
  assert.ok(thumbOk, 'התמונה הממוזערת נטענת');
  step('ספרייה בשרת: שמירה אוטומטית, טעינה מחדש זהה, שינוי שם, תמונה ממוזערת ברשימה');

  // שני חלונות על אותו ציור: השני ששומר מקבל "נשמר מחלון אחר" ולא דורס
  const p2 = await browser.newPage();
  await p2.goto(base + 'editor.html#d' + savedId);
  await p2.waitForFunction(() => window.__vs?.S.doc);
  await page.goto(base + 'editor.html#d' + savedId);
  await page.waitForFunction(() => window.__vs?.S.doc);
  await page.keyboard.press('Control+a'); await page.keyboard.press('ArrowRight');
  await page.waitForFunction(() => document.getElementById('doc-status').textContent === 'נשמר', null, { timeout: 10000 });
  await p2.keyboard.press('Control+a'); await p2.keyboard.press('ArrowDown');
  await p2.waitForFunction(() => /מחלון אחר/.test(document.getElementById('doc-status').textContent), null, { timeout: 10000 });
  await p2.close();
  step('התנגשות בין שני חלונות — השני לא דורס, ואומר לפתוח מחדש');

  // פתיחת ה-‎.mbe מהמכשיר (דף הפתיחה): ציור חדש בספרייה עם אותו תוכן
  await page.goto(base);
  await page.waitForSelector('#home:not([hidden])');
  const [mbeChooser] = await Promise.all([page.waitForEvent('filechooser'), page.click('#open-btn')]);
  await mbeChooser.setFiles(tmpMbe);
  await page.waitForFunction(() => /^#d\d+$/.test(location.hash) && window.__vs?.S.doc, null, { timeout: 15000 });
  assert.equal(await page.evaluate(() => window.__vs.S.doc.surfaces[0].layers[0].paths.length), docPaths);
  assert.ok(await page.evaluate(() => window.__vs.S.doc.surfaces[0].layers[0].paths.some(p => p.type === 'text')), 'הטקסט חזר כטקסט');
  fs.unlinkSync(tmpMbe);
  step('פתיחת ‎.mbe מהמכשיר — ציור חדש בספרייה, עם אותו תוכן (טקסט נשאר טקסט)');

  // ‎.mbe של אפליקציה אחרת: שכבת רסטר ומסגרת טקסט מוצגות (לא נערכות), ונשמרות כמו שהן
  const { create: mbeCreate, save: mbeSave } = await import('../assets/js/mbe/mbe.js');
  const red = Uint8Array.from(Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAAEElEQVR4nGP4z8AARAwQCgAf7gP9i18U1AAAAABJRU5ErkJggg==', 'base64'));
  const of = mbeCreate('raster', { units: 'pt', color: { space: 'sRGB' }, surfaces: [], flows: { f1: { engine: 'prosemirror', doc: { type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'פסקה מהעימוד' }] }] } } } });
  const imgPath = await of.addMedia(red, 'image/png');
  of.document.surfaces.push({ id: 's1', type: 'canvas', size: [200, 200], layers: [
    { id: 'R1', type: 'raster', name: 'תמונה', src: imgPath, box: [0, 0, 100, 100] },
    { id: 'F1', type: 'frame', name: 'מסגרת', box: [100, 120, 90, 60], flow: 'f1' },
  ] });
  of.setRendition('renditions/preview.png', red);
  const foreignFile = `${os.tmpdir()}/vs-foreign-${process.pid}.mbe`;
  fs.writeFileSync(foreignFile, await mbeSave(of, { app: 'raster-studio' }));
  await page.goto(base);
  await page.waitForSelector('#home:not([hidden])');
  const [fch] = await Promise.all([page.waitForEvent('filechooser'), page.click('#open-btn')]);
  await fch.setFiles(foreignFile);
  await page.waitForFunction(() => /^#d\d+$/.test(location.hash) && window.__vs?.S.doc && window.__vs.S.media.size === 1, null, { timeout: 15000 });
  await page.waitForTimeout(200);
  const px = await page.evaluate(() => {
    const { S } = window.__vs, c = document.getElementById('canvas');
    const x = (50 * S.view.zoom + S.view.x) * S.dpr, y = (50 * S.view.zoom + S.view.y) * S.dpr;
    return [...c.getContext('2d').getImageData(Math.round(x), Math.round(y), 1, 1).data];
  });
  assert.ok(px[0] > 200 && px[1] < 60 && px[2] < 60, 'שכבת הרסטר מצוירת: ' + px);
  await page.click('[data-tab="layers"]');
  assert.match(await page.textContent('#layers-body'), /רסטר/);
  assert.match(await page.textContent('#layers-body'), /מסגרת/);
  await page.click('[data-tab="props"]');
  fs.unlinkSync(foreignFile);
  const fsvg = (await grab('svg')).buf.toString('utf8');
  assert.match(fsvg, /<g id="R1"[^>]*>\s*<image x="0" y="0" width="100" height="100" preserveAspectRatio="none" href="data:image\/png;base64,/);
  assert.match(fsvg, /<g id="F1"[^>]*>\s*<path id="F1" d="M/);
  const fpdf = (await grab('pdf')).buf.toString('latin1');
  // pdf-core: התמונה של שכבת הרסטר כ-XObject בגודל שלה (2×2)
  assert.match(fpdf, /\/Subtype \/Image/);
  assert.match(fpdf, /\/Width 2 /);
  if (shots) fs.writeFileSync(`${shots}/foreign.pdf`, Buffer.from(fpdf, 'latin1'));
  step('‎.mbe של אפליקציה אחרת: רסטר ומסגרת טקסט מוצגים, בשכבות לקריאה בלבד, ונכללים בייצוא SVG ו-PDF');

  // מסמך של מעבד תמלילים (שני עמודים ב-PDF שלו, לוח אחד במסמך): העמודים מוצגים כמו שהוא צייר — האדום של ה-PDF,
  // לא הטקסט הפשוט של המסגרת; בייצוא — שני עמודים עם תמונה; ב-‎.mbe — עותק של ה-PDF ב-apps/vector-studio/
  const { toPDF: pdfOf } = await import('../assets/js/export.js');
  const redPage = (n) => ({ surface: { id: 'p' + n, size: [200, 200] }, layers: [{ layer: { id: 'L' }, opacity: 1, items: [{ id: 'r', d: 'M0 0 L200 0 L200 200 L0 200 Z', fill: { color: n === 1 ? '#ff0000' : '#0000ff' } }] }], gaps: [] });
  const wp = mbeCreate('text', { units: 'pt', color: { space: 'sRGB' }, flows: { f1: { engine: 'prosemirror', doc: { type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'מכתב' }] }] } } },
    surfaces: [{ id: 'p1', type: 'page', size: [200, 200], layers: [{ id: 'fr1', type: 'frame', box: [10, 10, 180, 180], flow: 'f1' }] }] });
  wp.setRendition('renditions/preview.png', red);
  wp.setRendition('renditions/document.pdf', await pdfOf([redPage(1), redPage(2)]), { by: 'word-processor' });
  const wpFile = `${os.tmpdir()}/vs-wp-${process.pid}.mbe`;
  fs.writeFileSync(wpFile, await mbeSave(wp, { app: 'word-processor' }));
  await page.goto(base);
  await page.waitForSelector('#home:not([hidden])');
  const [wch] = await Promise.all([page.waitForEvent('filechooser'), page.click('#open-btn')]);
  await wch.setFiles(wpFile);
  await page.waitForFunction(() => window.__vs?.S.pageImgs?.length === 2, null, { timeout: 30000 });
  await page.waitForTimeout(300);
  const pix = (x, y) => page.evaluate(([x, y]) => {
    const { S } = window.__vs, c = document.getElementById('canvas');
    return [...c.getContext('2d').getImageData(Math.round((x * S.view.zoom + S.view.x) * S.dpr), Math.round((y * S.view.zoom + S.view.y) * S.dpr), 1, 1).data];
  }, [x, y]);
  const pg1 = await pix(100, 100), pg2 = await pix(200 + 48 + 100, 100);
  assert.ok(pg1[0] > 200 && pg1[2] < 60, 'עמוד 1 מה-PDF: ' + pg1);
  assert.ok(pg2[2] > 200 && pg2[0] < 60, 'עמוד 2 — אחרי הלוח, לתצוגה: ' + pg2);
  const wpdf = (await grab('pdf')).buf.toString('latin1');
  assert.match(wpdf, /\/Count 2/);
  assert.equal((wpdf.match(/\/Subtype \/Image/g) || []).length, 2);
  const { open: mbeOpen2 } = await import('../assets/js/mbe/mbe.js');
  const wmbe = await mbeOpen2(new Uint8Array((await grab('mbe')).buf));
  assert.equal(wmbe.ok, true, JSON.stringify(wmbe.issues));
  assert.equal(wmbe.kind, 'text', 'סוג המסמך נשמר');
  assert.ok(wmbe.apps('vector-studio').get('background.pdf')?.length > 100, 'עותק של ה-PDF של המקור');
  assert.deepEqual(wmbe.document.flows, wp.document.flows, 'הטקסט של מעבד התמלילים לא השתנה');
  fs.unlinkSync(wpFile);
  step('מסמך של מעבד תמלילים: העמודים כמו שהוא צייר (גם עמוד שגלש), בייצוא PDF, וה-PDF נשמר ב-‎.mbe');

  // טיוטה ישנה מהמכשיר: מופיעה ברשימה, ובפתיחה עוברת לספרייה
  await page.goto(base);
  await page.evaluate(() => {
    const doc = { units: 'pt', color: { space: 'sRGB' }, surfaces: [{ id: 's1', type: 'artboard', name: 'לוח 1', size: [300, 200], layers: [{ id: 'L1', name: 'שכבה 1', type: 'vector', paths: [{ id: 'p1', d: 'M10 10 L100 10 L100 80 Z', fill: { color: '#2d4a8a' } }] }] }] };
    localStorage.setItem('vector-studio:draft:old1', JSON.stringify({ name: 'טיוטה ישנה', doc }));
    localStorage.setItem('vector-studio:drafts', JSON.stringify([{ id: 'old1', name: 'טיוטה ישנה', updated: new Date().toISOString() }]));
  });
  await page.reload();
  await page.waitForSelector('#drafts .card[data-local="old1"]');
  await page.click('#drafts .card[data-local="old1"] a');
  await page.waitForFunction(() => /^#d\d+$/.test(location.hash) && window.__vs?.S.doc);
  assert.equal(await page.evaluate(() => window.__vs.S.doc.surfaces[0].layers[0].paths.length), 1);
  assert.equal(await page.evaluate(() => localStorage.getItem('vector-studio:draft:old1')), null, 'נמחקה מהמכשיר אחרי שנשמרה בשרת');
  step('טיוטה ישנה מהמכשיר עוברת לספרייה בשרת');

  // נפתחים מאפליקציה אחרת (mbe-handoff): ready מיד, הקובץ נפתח, "↩ חזרה" מחזיר ‎.mbe תקין, והחלון נסגר
  const hf = mbeCreate('vector', { units: 'pt', color: { space: 'sRGB' }, surfaces: [{ id: 's1', type: 'artboard', name: 'כרטיס', size: [300, 200],
    layers: [{ id: 'L1', name: 'שכבה', type: 'vector', paths: [{ id: 'h1', d: 'M10 10 L100 10 L100 80 Z', fill: { color: '#e19a00' } }] }] }] });
  hf.setRendition('renditions/preview.png', red);
  const hBytes = [...await mbeSave(hf, { app: 'greeting-cards' })];
  await page.goto(base);
  const [popup] = await Promise.all([page.waitForEvent('popup'), page.evaluate((bytes) => {
    window.__ho = { ready: false, returned: null };
    const id = 'h' + Math.random().toString(36).slice(2);
    const w = window.open('index.html?mbe-handoff=' + id);
    addEventListener('message', (e) => {
      if (e.source !== w || e.origin !== location.origin || e.data?.proto !== 'mbe-handoff' || e.data.id !== id) return;
      if (e.data.type === 'ready') {
        window.__ho.ready = true;
        const file = new Uint8Array(bytes).buffer;
        w.postMessage({ proto: 'mbe-handoff', v: 1, type: 'open', id, name: 'כרטיס.mbe', file, from: { app: 'greeting-cards', name: 'הסטודיו' } }, location.origin, [file]);
      }
      if (e.data.type === 'return') {
        window.__ho.returned = [...new Uint8Array(e.data.file)];
        w.postMessage({ proto: 'mbe-handoff', v: 1, type: 'returned', id }, location.origin);
      }
    });
  }, hBytes)]);
  await popup.waitForSelector('#handoff-back:not([hidden])', { timeout: 15000 });
  assert.equal(await popup.textContent('#handoff-back'), '↩ חזרה להסטודיו');
  assert.ok(await page.evaluate(() => window.__ho.ready), 'ready נשלח');
  await popup.waitForFunction(() => window.__vs?.S.doc?.surfaces[0].layers[0].paths.some(p => p.id === 'h1'));
  await popup.evaluate(() => { window.__vs.S.doc.surfaces[0].layers[0].paths[0].fill.color = '#1e7b3a'; });
  const closed = popup.waitForEvent('close', { timeout: 15000 });
  await popup.click('#handoff-back');
  await closed;
  const back = await page.evaluate(() => window.__ho.returned);
  const { open: mbeOpen3 } = await import('../assets/js/mbe/mbe.js');
  const bf = await mbeOpen3(new Uint8Array(back));
  assert.equal(bf.ok, true, JSON.stringify(bf.issues));
  assert.equal(bf.document.surfaces[0].layers[0].paths.find(p => p.id === 'h1').fill.color, '#1e7b3a', 'השינוי חזר');
  step('פתיחה מאפליקציה אחרת (mbe-handoff): ready, פתיחה, "↩ חזרה" עם ‎.mbe תקין, והחלון נסגר');
  await page.close();

  // התקנה: manifest תקין ו-service worker — Chromium לא מוצא שגיאות התקנה; בלי רשת הדף נפתח מהמטמון
  const pi = await browser.newPage();
  await pi.goto(base);
  await pi.waitForSelector('#home:not([hidden])');
  await pi.evaluate(() => navigator.serviceWorker.ready);
  await pi.reload();
  await pi.waitForSelector('#home:not([hidden])');
  const cdp = await pi.context().newCDPSession(pi);
  const inst = await cdp.send('Page.getInstallabilityErrors');
  assert.deepEqual(inst.installabilityErrors, [], JSON.stringify(inst.installabilityErrors));
  const man = await cdp.send('Page.getAppManifest');
  assert.deepEqual(man.errors, []);
  assert.match(man.data, /"file_handlers"/);
  await pi.context().setOffline(true);
  await pi.reload();
  assert.match(await pi.title(), /וקטורי/);
  await pi.context().setOffline(false);
  await pi.close();
  step('התקנה: בלי שגיאות התקנה ב-Chromium, file_handlers ל-‎.mbe, והדף נפתח בלי רשת');

  // ---- טלפון ----
  // 👤 תפריט המשתמש (ui-core): העיגול בסרגל, ערכה כהה שנשמרת בשרת ומגיעה גם לעורך, וייצוא כל הנתונים
  const pa = await browser.newPage();
  await pa.goto(base);
  await pa.waitForSelector('.account-btn:not([hidden])');
  await pa.click('.account-btn');
  await pa.getByText('הגדרות אישיות').click();
  await pa.check('input[name=theme][value=dark]');
  await pa.waitForFunction(() => document.documentElement.dataset.theme === 'dark');
  await pa.keyboard.press('Escape');
  await pa.goto(base + 'editor.html#d' + savedId);
  await pa.waitForFunction(() => window.__vs?.S.doc && document.documentElement.dataset.theme === 'dark', null, { timeout: 10000 });
  const exp = await pa.evaluate(() => fetch('api.php?action=account_export', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{}' }).then(r => r.json()));
  assert.equal(exp.app, 'vector-studio');
  assert.ok(exp.data.drawings.length >= 1 && exp.data.drawings.every(d => d.doc?.surfaces), 'הציורים בייצוא');
  assert.equal(exp.prefs.general.theme, 'dark');
  await pa.evaluate(() => fetch('api.php?action=account_prefs_set', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ ns: 'general', value: { theme: 'system' } }) }));
  await pa.close();
  step('תפריט המשתמש: הגדרות אישיות בשרת (ערכה כהה גם בעורך), ייצוא כל הנתונים');

  for (const width of [390, 320]) {
    const ctx = await browser.newContext({ viewport: { width, height: 780 }, hasTouch: true, isMobile: true, deviceScaleFactor: 2 });
    const p = await ctx.newPage();
    p.on('pageerror', e => errors.push(e.message));
    await p.goto(base + 'editor.html#new:a4');
    await p.waitForFunction(() => window.__vs?.S.doc);
    const sw = await p.evaluate(() => document.documentElement.scrollWidth);
    assert.equal(sw, width, 'אין גלילה אופקית');
    await pickShape(p, 'star', true);
    const cb = await p.locator('#canvas').boundingBox();
    await p.touchscreen.tap(cb.x + cb.width / 2, cb.y + cb.height / 2);
    let n = await p.evaluate(() => window.__vs.S.doc.surfaces[0].layers[0].paths.length);
    assert.equal(n, 1);
    // עט במגע: שלוש הקשות ו"✓ סיום"
    await p.tap('[data-tool="pen"]');
    for (const [dx, dy] of [[-60, 80], [0, 120], [60, 80]]) await p.touchscreen.tap(cb.x + cb.width / 2 + dx, cb.y + cb.height / 2 + dy);
    await p.tap('#pen-done');
    n = await p.evaluate(() => window.__vs.S.doc.surfaces[0].layers[0].paths.length);
    assert.equal(n, 2);
    // צביטה באמצע עט לא מוסיפה נקודה; הרמת אצבע אחת אחרי צביטה — בלי שגיאות; ↶ באמצע נתיב מוחק נקודה
    const cdp = await ctx.newCDPSession(p);
    const cx0 = cb.x + cb.width / 2, cy0 = cb.y + cb.height / 2;
    const touch = (type, pts) => cdp.send('Input.dispatchTouchEvent', { type, touchPoints: pts.map(([x, y], id) => ({ x, y, id })) });
    await p.tap('[data-tool="pen"]');
    for (const [dx, dy] of [[-80, -100], [-20, -100]]) await p.touchscreen.tap(cx0 + dx, cy0 + dy);
    const nodesOf = () => p.evaluate(() => { const L = window.__vs.S.doc.surfaces[0].layers[0].paths; return (L[L.length - 1].d.match(/[ML]/g) || []).length; });
    assert.equal(await nodesOf(), 2);
    await touch('touchStart', [[cx0 + 40, cy0 - 40]]);
    await touch('touchStart', [[cx0 + 40, cy0 - 40], [cx0 + 100, cy0 + 20]]);
    await touch('touchMove', [[cx0 + 30, cy0 - 50], [cx0 + 120, cy0 + 40]]);
    await touch('touchEnd', [[cx0 + 30, cy0 - 50]]);
    await touch('touchMove', [[cx0 + 20, cy0 - 60]]);
    await touch('touchEnd', []);
    assert.equal(await nodesOf(), 2, 'הצביטה לא הוסיפה נקודה');
    await p.tap('#undo');
    assert.equal(await nodesOf(), 1, '↶ מחק את הנקודה האחרונה');
    await p.touchscreen.tap(cx0 + 40, cy0 - 100);
    await p.tap('#pen-done');
    assert.equal(await p.evaluate(() => window.__vs.S.doc.surfaces[0].layers[0].paths.length), 3);
    await p.tap('#undo');
    assert.equal(await p.evaluate(() => window.__vs.S.doc.surfaces[0].layers[0].paths.length), 2, 'ואחרי הסיום — ↶ רגיל');
    // מברשת באצבע: גרירה מציירת; אצבע שנייה באמצע — זום, והקו שהתחיל נמחק
    await p.tap('[data-tool="draw"]');
    await p.tap('[data-tool="draw"]');
    await p.tap('[data-free="brush"]');
    const before = await p.evaluate(() => window.__vs.S.doc.surfaces[0].layers[0].paths.length);
    await touch('touchStart', [[cx0 - 60, cy0 + 40]]);
    for (let i = 1; i <= 12; i++) await touch('touchMove', [[cx0 - 60 + i * 10, cy0 + 40 + Math.sin(i / 3) * 15]]);
    await touch('touchEnd', []);
    assert.equal(await p.evaluate(() => window.__vs.S.doc.surfaces[0].layers[0].paths.length), before + 1, 'מברשת באצבע');
    await touch('touchStart', [[cx0 - 60, cy0 + 90]]);
    await touch('touchMove', [[cx0 - 40, cy0 + 95]]);
    await touch('touchStart', [[cx0 - 40, cy0 + 95], [cx0 + 40, cy0 + 140]]);
    await touch('touchMove', [[cx0 - 50, cy0 + 90], [cx0 + 60, cy0 + 160]]);
    await touch('touchEnd', []);
    assert.equal(await p.evaluate(() => window.__vs.S.doc.surfaces[0].layers[0].paths.length), before + 1, 'צביטה ביטלה את הקו');
    if (shots) await p.screenshot({ path: `${shots}/brush-${width}.png` });
    await p.evaluate(() => { const L = window.__vs.S.doc.surfaces[0].layers[0]; L.paths.pop(); });
    // טקסט במגע
    await p.tap('[data-tool="text"]');
    await p.touchscreen.tap(cb.x + cb.width / 2 + 80, cb.y + 60);
    await p.keyboard.type('שלום');
    const fs = await p.evaluate(() => parseFloat(getComputedStyle(document.getElementById('text-edit')).fontSize));
    assert.ok(fs >= 16, 'שדה העריכה לפחות 16px (אחרת iOS מגדיל): ' + fs);
    if (shots) await p.screenshot({ path: `${shots}/text-${width}.png` });
    await p.evaluate(() => document.getElementById('text-edit').blur());
    n = await p.evaluate(() => window.__vs.S.doc.surfaces[0].layers[0].paths.filter(x => x.type === 'text').length);
    assert.equal(n, 1);
    await p.tap('[data-tool="select"]');
    // אצבע אחת על רקע ריק מזיזה את התצוגה (ui-core bindViewport, panOnEmpty) ולא מבטלת בחירה; הקשה שם — מבטלת
    await p.evaluate(() => window.__vs.select([window.__vs.S.doc.surfaces[0].layers[0].paths[0].id]));
    const v0 = await p.evaluate(() => ({ ...window.__vs.S.view }));
    await touch('touchStart', [[cb.x + 12, cb.y + 12]]);
    for (let i = 1; i <= 5; i++) await touch('touchMove', [[cb.x + 12 + i * 8, cb.y + 12 + i * 6]]);
    await touch('touchEnd', []);
    const v1 = await p.evaluate(() => ({ ...window.__vs.S.view }));
    assert.ok(Math.abs(v1.x - v0.x - 40) < 2 && Math.abs(v1.y - v0.y - 30) < 2 && v1.zoom === v0.zoom, 'הזזה באצבע: ' + JSON.stringify([v0, v1]));
    assert.equal(await p.evaluate(() => window.__vs.S.sel.size), 1, 'הזזה לא מבטלת בחירה');
    await p.touchscreen.tap(cb.x + 12, cb.y + 12);
    assert.equal(await p.evaluate(() => window.__vs.S.sel.size), 0, 'הקשה על רקע ריק מבטלת בחירה');
    await p.evaluate(() => window.__vs.select([window.__vs.S.doc.surfaces[0].layers[0].paths[0].id]));
    await p.tap('#props-btn');
    await p.waitForSelector('.props-open #props-body .props-head');
    if (shots) await p.screenshot({ path: `${shots}/editor-${width}.png` });
    // סוג המילוי — שלוש אפשרויות שנכנסות ברוחב, בלי לגלוש
    await p.locator('fieldset[data-k="fillmode"]').scrollIntoViewIfNeeded();
    await p.tap('fieldset[data-k="fillmode"] label:nth-child(3)');
    await p.waitForFunction(() => window.__vs.S.doc.surfaces[0].layers[0].paths[0].fill?.gradient?.type === 'radial');
    const over = await p.evaluate(() => [...document.querySelectorAll('#props-body fieldset.seg, #props-body .g-row')].filter(e => e.scrollWidth > e.clientWidth + 1).map(e => e.outerHTML.slice(0, 80)));
    assert.deepEqual(over, [], 'גולש ברוחב');
    assert.ok(await p.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'גלילה אופקית');
    if (shots) { await p.locator('fieldset[data-k="fillmode"]').scrollIntoViewIfNeeded(); await p.screenshot({ path: `${shots}/fill-${width}.png` }); }
    step(`טלפון ${width}px: כוכב בהקשה, עט ב-3 הקשות ו"סיום", צביטה ו-↶ באמצע עט, הזזה באצבע והקשה שמבטלת בחירה, טקסט, לוח מאפיינים, בלי גלילה אופקית`);
    await ctx.close();
  }
} finally {
  await browser.close();
}

assert.deepEqual(errors, [], 'שגיאות בקונסול: ' + errors.join(' | '));
console.log('\nהכול עבר');
