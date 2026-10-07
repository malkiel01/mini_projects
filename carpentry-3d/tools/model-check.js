#!/usr/bin/env node
// בדיקת המודל בלי דפדפן: בונה ספריות בכמה תצורות ומוודא שהגאומטריה
// עקבית — אין NaN, אין חלק מחוץ לגוף, הדפנות והגג נפגשים, המדפים בתוך
// העמודות, והאזהרות נדלקות במקום הנכון.
//
//   node carpentry-3d/tools/model-check.js

import { build, cutList, hardwareList, defaults, template, optionsFor, allParams, TEMPLATES } from '../assets/js/model/index.js';
import * as M from '../assets/js/model/materials.js';
import { estimate } from '../assets/js/model/pricing.js';
import { nest, sheetCount } from '../assets/js/model/sheets.js';

let failed = 0;
function check(cond, msg) {
  if (!cond) { failed += 1; console.error('  ✗', msg); }
}

function allFinite(parts) {
  return parts.every((p) => ['x', 'y', 'z', 'w', 'h', 'd'].every((k) => Number.isFinite(p.box[k])) && p.box.w > 0 && p.box.h > 0 && p.box.d > 0);
}
function within(parts, b) {
  return parts.every((p) => p.box.x >= -0.01 && p.box.y >= -0.01 && p.box.z >= -0.01
    && p.box.x + p.box.w <= b.w + 0.01 && p.box.y + p.box.h <= b.h + 0.01 && p.box.z + p.box.d <= b.d + 0.01);
}
const find = (parts, id) => parts.find((p) => p.id === id);

console.log('ברירת מחדל');
{
  const r = build('bookcase', {});
  check(allFinite(r.parts), 'יש חלק עם מידה לא סופית או אפס');
  check(within(r.parts, r.bounds), 'יש חלק מחוץ לגבולות הגוף');
  check(r.parts.filter((p) => p.id.startsWith('partition')).length === 2, '3 עמודות = 2 מחיצות');
  check(r.parts.filter((p) => p.id.startsWith('shelf')).length === 12, '3 × 4 = 12 מדפים');
  const side = find(r.parts, 'side-L'), top = find(r.parts, 'top');
  check(side.box.h === 2000 && top.box.x === 18, 'דפנות עוברות: הדופן בגובה מלא והגג בין הדפנות');
  check(find(r.parts, 'plinth').box.z === 350 - 30 - 18, 'הסוקל נסוג 30 מהחזית');
  check(find(r.parts, 'back').box.z === 10, 'הגב בחריץ יושב 10 מהקצה האחורי');
  check(r.warnings.length === 0, `לא צפויות אזהרות, יש: ${r.warnings.join('; ')}`);
  const cl = cutList(r);
  const shelfRow = cl.boards.find((row) => row.ids.includes('shelf-1-1'));
  check(shelfRow && shelfRow.qty === 12, 'כל 12 המדפים מתקבצים לשורה אחת');
  check(shelfRow && shelfRow.t === 18 && shelfRow.w === 350 - 10 - 6 - 5 - 1, `עומק המדף: 350 − חריץ 10 − גב 6 − נסיגה 5 − קנט 1 (קיבלתי ${shelfRow && shelfRow.w})`);
  const sideRow = cl.boards.find((row) => row.ids.includes('side-L'));
  check(sideRow && sideRow.qty === 2 && sideRow.l === 2000 - 1, 'דופן: 2 יח׳, הקנט יורד מהאורך רק בקצה העליון (החזית היא לרוחב)');
  check(hardwareList(r).find((h) => h.kind === 'shelf-pin').qty === 48, '12 מדפים × 4 פינים');
}

console.log('הגג עובר, גב מולבש, בלי סוקל');
{
  const r = build('bookcase', { sidesOverTop: 'top', backMode: 'overlay', plinthH: 0 });
  check(allFinite(r.parts) && within(r.parts, r.bounds), 'גאומטריה תקינה');
  const side = find(r.parts, 'side-L'), top = find(r.parts, 'top'), back = find(r.parts, 'back');
  check(top.box.x === 0 && top.box.w === 1200, 'הגג ברוחב מלא');
  check(side.box.y === 18 && side.box.y + side.box.h === 2000 - 18, 'הדופן בין הרצפה לגג');
  check(back.box.z === 0 && back.box.d === 6 && side.box.z === 6, 'הגב מאחור, הפנלים מתחילים אחריו');
}

console.log('דלתות ויטרינה, עמודה רחבה');
{
  const r = build('bookcase', { width: 1500, columns: 2, doorType: 'glass', depth: 200 });
  check(allFinite(r.parts) && within(r.parts, r.bounds), 'גאומטריה תקינה');
  const doors = r.parts.filter((p) => /^door-\d+[ab]?-stile-L$/.test(p.id));
  check(doors.length === 4, `עמודה ברוחב ~740 → שתי דלתות לכל עמודה (יש ${doors.length})`);
  check(r.parts.some((p) => p.material === 'glass:clear-4'), 'יש שמשות');
  check(r.warnings.some((w) => w.includes('ויטרינה')), 'אזהרת עומק לוויטרינה');
  const cl = cutList(r);
  check(cl.glass.length > 0 && cl.glass[0].qty === 4, 'הזכוכית ברשימה נפרדת, 4 שמשות');
  check(r.hardware.filter((h) => h.kind === 'hinge').length >= 8, 'צירים לכל דלת');
  check(r.bounds.d === 200 + 18, 'העומק הכולל כולל את הדלת');
}

console.log('אזהרות: מפתח מדף וגובה');
{
  const r = build('bookcase', { width: 1000, columns: 1, height: 2500 });
  check(r.warnings.some((w) => w.includes('מדף')), 'אזהרת מפתח מדף');
  check(r.warnings.some((w) => w.includes('עיגון')), 'אזהרת גובה');
}

console.log('הצמדה לטווח');
{
  const r = build('bookcase', { width: 99999, columns: -3, doorType: 'bogus' });
  check(r.values.width === 4000 && r.values.columns === 1 && r.values.doorType === 'none', 'ערכים מחוץ לטווח מוצמדים');
  const t = template('bookcase');
  check(Object.keys(defaults(t)).length === t.params.length + t.joinery.length, 'ברירות מחדל לכל פרמטר');
}

console.log('ספריית חומרים דינמית');
{
  const t = template('bookcase');
  const p = allParams(t).find((x) => x.key === 'bodyMaterial');
  const before = optionsFor(p).length;
  check(before > 0 && optionsFor(p).every((o) => !o.id.startsWith('board:back')), 'חומר הגוף: לוחות שאינם גב');
  M.upsert({ id: M.newId('board', 'Test Oak'), kind: 'board', name: 'אלון בדיקה', t: 25, color: 0xaa8855, finish: 'wood', active: true });
  check(optionsFor(p).length === before + 1, 'חומר חדש מופיע ברשימה');
  const r = build('bookcase', { bodyMaterial: 'board:test-oak' });
  check(r.values.bodyMaterial === 'board:test-oak' && r.parts[0].material === 'board:test-oak', 'המודל משתמש בחומר החדש');
  M.remove('board:melamine-oak-18');
  check(M.material('board:melamine-oak-18').active === false, 'חומר מהזריעה מושבת, לא נמחק');
  check(build('bookcase', { bodyMaterial: 'board:melamine-oak-18' }).values.bodyMaterial === 'board:melamine-oak-18', 'פרויקט עם חומר מושבת ממשיך לעבוד');
  check(build('bookcase', { bodyMaterial: 'board:nope' }).values.bodyMaterial === p.default, 'מזהה לא קיים חוזר לברירת המחדל');
  const d = M.diff();
  check(d.length === 2 && d.some((x) => x.id === 'board:test-oak') && d.some((x) => x.id === 'board:melamine-oak-18' && x.active === false), `נשמר רק מה ששונה מהזריעה (${d.length})`);
  M.load(d);
  check(M.material('board:test-oak').t === 25 && M.material('board:melamine-oak-18').active === false, 'טעינה מחזירה את אותו מצב');
  M.reset();
  check(optionsFor(p).length === before, 'איפוס מחזיר לזריעה');
}

console.log('מחיר');
{
  const r = build('bookcase', { doorType: 'glass' });
  const e = estimate(r, {});
  check(e.total > 0 && e.materials > 0 && e.hardware > 0 && e.labor.total === 6 * 150, `הערכה עם ברירות מחדל (סה"כ ${e.total})`);
  check(e.lines.some((l) => l.group === 'קנט') && e.edgeMeters > 0, 'קנט נספר במטרים');
  check(e.lines.some((l) => l.group === 'זכוכית'), 'זכוכית בשורה משלה');
  const mine = estimate(r, { laborHour: 200, markup: 0, materials: { 'board:melamine-oak-18': 1000 } });
  check(mine.labor.rate === 200 && mine.labor.mine && mine.markup.total === 0, 'תעריפי הנגר דורסים');
  check(mine.lines.find((l) => l.name === 'מלמין אלון 18').mine && mine.materials > e.materials, '"המחיר שלי" לחומר');
  check(Math.abs(e.total - (e.subtotal * 1.2)) < 0.05, 'רווח 20% על הכול');
}

console.log('לוחות וסידור');
{
  const r = build('bookcase', { width: 2400, columns: 4, doorType: 'wood' });
  const cl = cutList(r);
  const sc = sheetCount(cl, r.parts);
  check(sc.length >= 2 && sc.every((g) => g.count >= 1), `ספירה לכל חומר (${sc.map((g) => `${g.name}: ${g.count}`).join(', ')})`);
  const n = nest(cl, r.parts);
  const placed = n.reduce((s, g) => s + g.sheets.reduce((t, sh) => t + sh.places.length, 0), 0);
  const total = cl.boards.filter((row) => M.material(r.parts.find((p) => p.id === row.ids[0]).material).sheet).reduce((s, row) => s + row.qty, 0);
  check(placed === total && n.every((g) => g.tooBig.length === 0), `כל החלקים הונחו (${placed}/${total})`);
  let overlap = false, outside = false;
  for (const g of n) for (const sh of g.sheets) {
    for (const a of sh.places) {
      if (a.x < 0 || a.y < 0 || a.x + a.w > sh.L + 0.01 || a.y + a.h > sh.W + 0.01) outside = true;
      for (const b of sh.places) if (a !== b && a.x < b.x + b.w && b.x < a.x + a.w && a.y < b.y + b.h && b.y < a.y + a.h) overlap = true;
    }
  }
  check(!overlap && !outside, 'אין חפיפות ואין חריגה מהלוח');
  const oak = n.find((g) => g.id === 'board:melamine-oak-18');
  check(oak && oak.sheets.every((sh) => sh.places.every((pl) => !pl.rotated)), 'מלמין: לא מסובבים (סיבים)');
  check(n.every((g) => g.waste >= 0 && g.waste < 1), 'פחת בין 0 ל-1');
  const huge = build('bookcase', { width: 4000, columns: 1, shelvesPerColumn: 0, sidesOverTop: 'top' });
  const hn = nest(cutList(huge), huge.parts);
  check(hn.some((g) => g.tooBig.length > 0), 'גג 4000 ארוך מלוח 2800 — מדווח, לא נעלם');
}

console.log('כל התבניות: ברירת מחדל ותצורות');
{
  const variants = {
    bookcase: [{}, { doorType: 'wood', crownH: 60 }],
    wardrobe: [{}, { doorType: 'wood', drawersPerColumn: 2 }, { doorType: 'sliding', slidingLeaves: 3, hangingColumns: 3, columns: 3 }, { columns: 1, hangingColumns: 0, shelvesPerColumn: 6 }],
    dresser: [{}, { drawerColumns: 2, drawerRows: 3, topRowH: 150 }, { backMode: 'overlay', plinthH: 0 }],
    table: [{}, { apronH: 0 }, { stretcher: 'h', length: 2400 }],
    bed: [{}, { headboardH: 0, legs: '4', mattressW: 900 }, { mattressW: 2000, mattressL: 2200 }],
    kitchen: [{}, { uppers: 'no', drawerCabinets: 0 }, { cabinets: 8, length: 4800, drawerCabinets: 3, backMode: 'overlay' }],
  };
  for (const key of Object.keys(TEMPLATES)) {
    const t = template(key);
    check(Object.keys(defaults(t)).length === allParams(t).length, `${t.name}: ברירות מחדל לכל פרמטר`);
    for (const p of allParams(t)) if (p.type === 'material' || p.type === 'enum') {
      check(optionsFor(p).some((o) => o.id === p.default), `${t.name}: ברירת המחדל של ${p.key} קיימת ברשימה`);
    }
    for (const vals of variants[key] || [{}]) {
      const r = build(key, vals);
      const label = `${t.name} ${JSON.stringify(vals)}`;
      check(allFinite(r.parts), `${label}: מידות סופיות וחיוביות`);
      check(within(r.parts, r.bounds), `${label}: בתוך הגבולות`);
      check(r.parts.length > 3, `${label}: יש חלקים (${r.parts.length})`);
      const ids = new Set(r.parts.map((p) => p.id));
      check(ids.size === r.parts.length, `${label}: מזהי חלקים ייחודיים`);
      const cl = cutList(r);
      check(cl.boards.every((row) => row.l > 0 && row.w > 0 && row.t > 0), `${label}: רשימת חיתוך תקינה`);
      check(Array.isArray(r.warnings), `${label}: אזהרות`);
    }
  }
  const w = build('wardrobe', { drawersPerColumn: 2, doorType: 'wood' });
  check(w.hardware.filter((h) => h.kind === 'rod').length === 2 && w.hardware.filter((h) => h.kind === 'slide').length === 6, 'ארון: 2 מוטות, 6 זוגות מסילות');
  check(w.parts.filter((p) => p.id.startsWith('drawer-')).length === 6 * 6, 'כל מגירה = 6 חלקים');
  const d = build('dresser', { drawerRows: 4, drawerColumns: 2 });
  check(d.parts.filter((p) => p.name === 'חזית מגירה').length === 8, 'שידה: 8 חזיתות');
  check(d.parts.find((p) => p.id === 'top-plate').box.w === 1000 && d.parts.find((p) => p.id === 'side-L').box.x === 20, 'שידה: הגג בולט 20 מכל צד');
  const tb = build('table', {});
  check(tb.parts.filter((p) => p.name === 'רגל').length === 4 && tb.parts.filter((p) => p.id.startsWith('apron')).length === 4, 'שולחן: 4 רגליים, 4 מסגרות');
  const bd = build('bed', {});
  check(bd.parts.filter((p) => p.name === 'לטה').length >= 14 && bd.parts.find((p) => p.id === 'headboard'), `מיטה: לטות (${bd.parts.filter((p) => p.name === 'לטה').length}) וראש מיטה`);
  check(bd.bounds.w === 1600 + 20 + 50, 'מיטה: רוחב = מזרן + מרווח + דפנות');
  const k = build('kitchen', {});
  check(k.parts.find((p) => p.id === 'countertop') && k.parts.filter((p) => p.id.startsWith('ע')).length > 0, 'מטבח: משטח ועליונים');
  check(k.warnings.length === 0, `מטבח ברירת מחדל בלי אזהרות (${k.warnings.join('; ')})`);
}

if (failed) { console.error(`\n${failed} בדיקות נכשלו`); process.exit(1); }
console.log('\nהכול עבר ✓');
