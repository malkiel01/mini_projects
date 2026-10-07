#!/usr/bin/env node
// בדיקת המודל בלי דפדפן: בונה ספריות בכמה תצורות ומוודא שהגאומטריה
// עקבית — אין NaN, אין חלק מחוץ לגוף, הדפנות והגג נפגשים, המדפים בתוך
// העמודות, והאזהרות נדלקות במקום הנכון.
//
//   node carpentry-3d/tools/model-check.js

import { build, cutList, hardwareList, defaults, template } from '../assets/js/model/index.js';

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

if (failed) { console.error(`\n${failed} בדיקות נכשלו`); process.exit(1); }
console.log('\nהכול עבר ✓');
