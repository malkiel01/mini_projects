#!/usr/bin/env node
// בדיקת המודל בלי דפדפן: בונה ספריות בכמה תצורות ומוודא שהגאומטריה
// עקבית — אין NaN, אין חלק מחוץ לגוף, הדפנות והגג נפגשים, המדפים בתוך
// העמודות, והאזהרות נדלקות במקום הנכון.
//
//   node carpentry-3d/tools/model-check.js

import { build, cutList, hardwareList, defaults, template, optionsFor, allParams, TEMPLATES } from '../assets/js/model/index.js';
import * as M from '../assets/js/model/materials.js';
import { estimate } from '../assets/js/model/pricing.js';
import { resolveShares, editShare, normalizeLayout, layoutIsEmpty } from '../assets/js/model/layout.js';
import { placeModel, combine, snapTo } from '../assets/js/model/assembly.js';
import { TYPES, buildAccessory, paramsOf, faceOf, wheelHeight, FINISHES as ACC_FINISHES } from '../assets/js/model/accessories.js';
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
    bookcase: [{}, { doorType: 'wood', crownH: 60 }, { lowerH: 900, lowerDoors: 'wood', doorType: 'glass', doorFinish: 'fluted-fine', glassColumns: 'first', sideLeftFinish: 'glass', led: 'sides' }, { glassColumns: 'all', sideLeftFinish: 'glass', sideRightFinish: 'glass', led: 'shelves', shelvesMode: 'fixed' }, { sideLeftFinish: 'fluted-wide', sideRightFinish: 'grooved', innerMaterial: 'board:melamine-white-18', columnsLayout: { 0: { shelves: 2 }, 2: { shelves: 3, gaps: [600, 200, 200, 200] } } }],
    wardrobe: [{}, { doorType: 'wood', drawersPerColumn: 2, doorFinish: 'fluted-fine', sideLeftFinish: 'fluted-wide', innerMaterial: 'board:melamine-white-18' }, { doorType: 'sliding', slidingLeaves: 3, hangingColumns: 3, columns: 3 }, { columns: 1, hangingColumns: 0, shelvesPerColumn: 6 }],
    dresser: [{}, { drawerColumns: 2, drawerRows: 3, topRowH: 150 }, { backMode: 'overlay', plinthH: 0 }],
    table: [{}, { apronH: 0 }, { stretcher: 'h', length: 2400 }],
    bed: [{}, { headboardH: 0, legs: '4', mattressW: 900 }, { mattressW: 2000, mattressL: 2200 }],
    cladding: [{}, { walls: 2, turn2: 'left', baseH: 100, crownH: 60 }, { walls: 3, turn2: 'right', turn3: 'right', style: 'panels', slatW: 300, slatGap: 8 }, { style: 'flat', len1: 5000, height: 1000, fromFloor: 0 }, { walls: 2, turn2: 'right', style: 'slats' }, { style: 'squares' }, { style: 'bricks' }, { style: 'checker' }, { style: 'relief' }, { style: 'frames' }, { style: 'hslats' }, { walls: 2, turn2: 'left', fields: 3, columnsLayout: { sections: { wall1: { widths: [800, null, null], cols: { 0: { kind: 'squares' }, 2: { kind: 'frames' } } }, wall2: { cols: { 1: { kind: 'bricks' } } } } } }],
    kitchen: [{}, { uppers: 'no', drawerCabinets: 0 }, { cabinets: 8, length: 4800, drawerCabinets: 3, backMode: 'overlay' },
      { shape: 'L' }, { shape: 'L', cornerType: 'l-carousel', cornerSide: 'right', drawerCabinetsB: 1 }, { shape: 'L', cornerType: 'blind', uppers: 'no' }, { shape: 'L', cornerSize: 1200, lengthB: 4000, cabinetsB: 4, backMode: 'overlay' }],
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
  check(k.parts.find((p) => p.id === 'countertop-A') && k.parts.filter((p) => p.id.startsWith('ע')).length > 0, 'מטבח: משטח ועליונים');
  check(k.warnings.length === 0, `מטבח ברירת מחדל בלי אזהרות (${k.warnings.join('; ')})`);
}

console.log("מטבח בצורת ר'");
{
  // חפיפות: בגב מולבש (לא בחריץ) אף שני לוחות לא צריכים לחדור זה לזה —
  // חוץ ממה שיושב בחריץ בכוונה (תחתית מגירה), שמסומן כך בהערה.
  const overlaps = (all) => {
    const parts = all.filter((p) => !/חריץ/.test(p.note || ''));
    const out = [];
    for (let i = 0; i < parts.length; i++) for (let j = i + 1; j < parts.length; j++) {
      const a = parts[i].box, b = parts[j].box, e = 0.5;
      if (a.x + e < b.x + b.w && b.x + e < a.x + a.w && a.y + e < b.y + b.h && b.y + e < a.y + a.h && a.z + e < b.z + b.d && b.z + e < a.z + a.d) out.push(`${parts[i].id} × ${parts[j].id}`);
    }
    return out;
  };
  for (const vals of [{ shape: 'L', backMode: 'overlay' }, { shape: 'L', cornerType: 'blind', backMode: 'overlay' }, { shape: 'L', cornerSide: 'right', backMode: 'overlay', drawerCabinetsB: 1 }, { shape: 'line', backMode: 'overlay' }]) {
    const r = build('kitchen', vals);
    const o = overlaps(r.parts);
    check(o.length === 0, `${JSON.stringify(vals)}: אין חפיפות (${o.slice(0, 4).join('; ')})`);
    check(within(r.parts, r.bounds), `${JSON.stringify(vals)}: בתוך הגבולות`);
  }
  const L = build('kitchen', { shape: 'L' });
  check(L.bounds.d === 2400 && L.bounds.w === 3000, "ר': הגבולות הם שני הקירות");
  check(L.warnings.length === 0, `ר' ברירת מחדל בלי אזהרות (${L.warnings.join('; ')})`);
  check(L.parts.some((p) => p.id === 'פינה-door-A') && L.parts.some((p) => p.id === 'פינה-door-B'), 'ארון פינתי: שתי דלתות');
  const dB = L.parts.find((p) => p.id === 'פינה-door-B');
  check(dB.axis === 'x' && dB.box.x === 560 && dB.box.z > 560, 'דלת ב על הפאה x=D, לאורך z');
  check(L.parts.filter((p) => p.id.startsWith('ב1-')).every((p) => p.box.x < 560 + 1) && L.parts.some((p) => p.id === 'ב1-door' || p.id === 'ב1-doora'), 'ארונות הקיר השני בתוך עומק הקיר, עם דלת');
  check(L.parts.some((p) => p.id === 'countertop-B') && L.parts.some((p) => p.id === 'plinth-B'), 'משטח וסוקל לקיר השני');
  const R = build('kitchen', { shape: 'L', cornerSide: 'right' });
  const dAr = R.parts.find((p) => p.id === 'פינה-door-A');
  check(Math.abs(dAr.box.x - (3000 - 1000)) < 3 && dAr.box.x + dAr.box.w < 3000 - 560, 'פינה מימין: דלת א בין הפינה הימנית לעומק הקיר השני');
  const car = build('kitchen', { shape: 'L', cornerType: 'l-carousel' });
  check(car.hardware.some((h) => h.material === 'hw:carousel') && car.hardware.some((h) => h.material === 'hw:hinge-bifold'), 'קרוסלה וצירי קיפול בפרזול');
  const narrow = build('kitchen', { shape: 'L', cornerSize: 800, baseD: 650 });
  check(narrow.warnings.some((w) => w.includes('פינה')), 'אזהרה על פינה קטנה מדי');
}

console.log('רכיבי תנועה');
{
  const bc = build('bookcase', { doorType: 'glass', columns: 2, width: 1000 });
  const doorParts = bc.parts.filter((p) => p.id.startsWith('door-1'));
  check(doorParts.length === 5 && doorParts.every((p) => p.motion && p.motion.kind === 'hinge' && p.motion.group === 'door-1'), 'ויטרינה: כל 5 חלקי הדלת באותה קבוצת סיבוב');
  check(doorParts[0].motion.pivot[0] === doorParts[0].box.x && doorParts[0].motion.angle < 0, 'ציר משמאל: הציר בקצה השמאלי, סיבוב שלילי');
  const d2 = bc.parts.find((p) => p.id === 'door-2-stile-L').motion;
  check(d2.angle > 0 && Math.abs(d2.pivot[0] - (bc.parts.find((p) => p.id === 'door-2-stile-R').box.x + 60)) < 0.01, 'ציר מימין: הציר בקצה הימני, סיבוב חיובי');
  check(bc.parts.filter((p) => !p.id.startsWith('door')).every((p) => !p.motion), 'לגוף אין תנועה');
  const wd = build('wardrobe', { doorType: 'sliding', slidingLeaves: 2, drawersPerColumn: 1 });
  const s1 = wd.parts.find((p) => p.id === 'sliding-1').motion, s2 = wd.parts.find((p) => p.id === 'sliding-2').motion;
  check(s1.kind === 'slide' && s1.vec[0] > 0 && s2.vec[0] < 0, 'הזזה: כנף 1 ימינה, כנף 2 שמאלה');
  const dr = wd.parts.filter((p) => p.id.startsWith('drawer-1-1'));
  check(dr.length === 6 && dr.every((p) => p.motion && p.motion.kind === 'slide' && p.motion.group === 'drawer-1-1' && p.motion.vec[2] > 0), 'מגירה: 6 חלקים נשלפים יחד קדימה');
  const kl = build('kitchen', { shape: 'L' });
  const dA = kl.parts.find((p) => p.id === 'פינה-door-A').motion, dB = kl.parts.find((p) => p.id === 'פינה-door-B').motion;
  check(dA.angle > 0 && dB.angle < 0 && dB.pivot[2] > dB.pivot[0], 'פינה: דלת ב מסובבת (X↔Z) — הציר בקצה הרחוק וכיוון הפוך');
  const b1 = kl.parts.find((p) => p.id.startsWith('ב1-drawer') || p.id === 'ב1-doora' || p.id === 'ב1-door');
  const kr = build('kitchen', { shape: 'L', cornerSide: 'right' });
  const dAr = kr.parts.find((p) => p.id === 'פינה-door-A').motion;
  check(dAr.angle === -dA.angle && Math.abs(dAr.pivot[0] - (3000 - dA.pivot[0])) < 0.01, 'שיקוף: הציר משתקף וכיוון הסיבוב מתהפך');
  const drB = build('kitchen', { shape: 'L', drawerCabinetsB: 1 }).parts.find((p) => p.id === 'ב1-drawer-1').motion;
  check(drB.vec[0] > 0 && drB.vec[2] === 0, 'מגירה בקיר השני נשלפת לכיוון +X');
}

console.log('חיפוי קיר');
{
  const overlaps = (parts) => {
    const out = [];
    for (let i = 0; i < parts.length; i++) for (let j = i + 1; j < parts.length; j++) {
      const a = parts[i].box, b = parts[j].box, e = 0.5;
      if (a.x + e < b.x + b.w && b.x + e < a.x + a.w && a.y + e < b.y + b.h && b.y + e < a.y + a.h && a.z + e < b.z + b.d && b.z + e < a.z + a.d) out.push(`${parts[i].id} × ${parts[j].id}`);
    }
    return out;
  };
  const c1 = build('cladding', {});
  check(c1.parts.filter((p) => p.name === 'סטריפ').length === 50, `3000 מ"מ ב-40+20 → 50 סטריפים (${c1.parts.filter((p) => p.name === 'סטריפ').length})`);
  check(c1.parts.filter((p) => p.name === 'לטת רוחב').length === 3 && c1.bounds.w === 3000 && c1.bounds.d === 38, 'קיר אחד: 3 לטות, עומק = לטה + סטריפ');
  for (const vals of [{ walls: 2, turn2: 'left' }, { walls: 2, turn2: 'right' }, { walls: 3, turn2: 'left', turn3: 'left' }, { walls: 3, turn2: 'right', turn3: 'left', style: 'flat' }]) {
    const r = build('cladding', vals);
    const o = overlaps(r.parts);
    check(o.length === 0, `${JSON.stringify(vals)}: אין חפיפות בפינה (${o.slice(0, 3).join('; ')})`);
    check(within(r.parts, r.bounds) && allFinite(r.parts), `${JSON.stringify(vals)}: בתוך הגבולות`);
  }
  const R = build('cladding', { walls: 2, turn2: 'right', len1: 3000, len2: 2000 });
  check(R.bounds.w === 3000 && R.bounds.d === 2000, 'פנייה ימינה = פינה פנימית (פינת חדר): 3000 × 2000');
  const w2 = R.parts.find((p) => p.id === 'w2-batten-1');
  check(w2 && w2.box.d > w2.box.w && w2.box.x + w2.box.w <= 3000, 'לטות הקיר השני ניצבות, בתוך הגבולות');
}

console.log('חיפוי: דוגמאות ושדות');
{
  const overlaps = (parts) => {
    let n = 0;
    for (let i = 0; i < parts.length; i++) for (let j = i + 1; j < parts.length; j++) {
      const a = parts[i].box, b = parts[j].box, e = 0.5;
      if (a.x + e < b.x + b.w && b.x + e < a.x + a.w && a.y + e < b.y + b.h && b.y + e < a.y + a.h && a.z + e < b.z + b.d && b.z + e < a.z + a.d) n++;
    }
    return n;
  };
  const base = { len1: 2000, height: 1200, tileS: 200, slatW: 40, slatGap: 20 };
  const names = {};
  for (const style of ['slats', 'hslats', 'panels', 'flat', 'squares', 'bricks', 'checker', 'relief', 'frames']) {
    const r = build('cladding', { ...base, style });
    const clad = r.parts.filter((p) => !p.id.includes('batten'));
    names[style] = clad.length;
    check(clad.length > 0 && within(r.parts, r.bounds) && allFinite(r.parts) && overlaps(r.parts) === 0, `${style}: ${clad.length} חלקים, בתוך הגבולות, בלי חפיפות`);
  }
  check(names.hslats === Math.floor((1200 + 20) / 60) && names.squares === 9 * 5 && names.checker > names.squares, `אופקיים ${names.hslats}, ריבועים ${names.squares} (9×5), שחמט ${names.checker}`);
  const rel = build('cladding', { ...base, style: 'relief' });
  const depths = new Set(rel.parts.filter((p) => p.name === 'אריח תבליט').map((p) => p.box.d));
  check(depths.size === 3, `תבליט: שלושה עומקים (${[...depths].join(',')})`);
  const br = build('cladding', { ...base, style: 'bricks' });
  const row1 = br.parts.filter((p) => p.id.startsWith('w1-f1-brick-1-')), row2 = br.parts.filter((p) => p.id.startsWith('w1-f1-brick-2-'));
  check(row1.length > 0 && row2.length === row1.length + 1 && Math.abs(row2[0].box.w - 90) < 0.01 && Math.abs(row2[0].box.x) < 0.01, 'לבנים: השורה השנייה מוזזת בחצי, עם חצי לבנה בקצה');
  const fr = build('cladding', { ...base, style: 'frames', tileS: 600 });
  check(fr.parts.filter((p) => p.name === 'לוח רקע').length === 2 && fr.parts.filter((p) => p.name === 'פס מסגרת').length === 3 * 2 * 4 && !fr.warnings.some((w) => w.includes('מסגרות')), 'מסגרות: לוח רקע + 3×2 מסגרות × 4 פסים');
  // שדות: רוחב נעוץ ודוגמה לכל שדה, בשני קירות
  const f = build('cladding', { ...base, walls: 2, turn2: 'left', len2: 1500, fields: 3, columnsLayout: { sections: { wall1: { widths: [800, null, null], cols: { 0: { kind: 'squares' }, 2: { kind: 'hslats' } } }, wall2: { cols: { 1: { kind: 'bricks' } } } } } });
  const f1 = f.parts.filter((p) => p.id.startsWith('w1-f1-')), f2 = f.parts.filter((p) => p.id.startsWith('w1-f2-')), f3 = f.parts.filter((p) => p.id.startsWith('w1-f3-'));
  check(f1.every((p) => p.name === 'אריח עץ') && f2.every((p) => p.name === 'סטריפ') && f3.every((p) => p.name === 'סטריפ אופקי'), 'קיר 1: שדה 1 ריבועים, שדה 2 ברירת המחדל (סטריפים), שדה 3 אופקיים');
  const xs1 = f1.map((p) => p.box.x + p.box.w), xs2 = f2.map((p) => p.box.x);
  check(Math.max(...xs1) <= 800 + 0.01 && Math.min(...xs2) >= 800 - 0.01 && Math.abs(f3[0].box.w - 600) < 0.01, 'שדה 1 ברוחב 800, השאר 600 כל אחד');
  check(f.parts.some((p) => p.id.startsWith('w2-f2-brick')) && overlaps(f.parts) === 0 && within(f.parts, f.bounds), 'קיר 2: שדה 2 לבנים; בלי חפיפות ובתוך הגבולות');
  const sp = template('cladding').columnSpace({ ...defaults(template('cladding')), ...base, walls: 2, turn2: 'right', len2: 1500, fields: 3 });
  check(sp.sections.length === 2 && sp.sections[0].total === 2000 && sp.sections[1].total === 1500 - 38 && sp.sections[0].items[0].kinds.length === 9, 'columnSpace: קבוצה לקיר, הקיר השני פחות עובי החיפוי בפינה פנימית, 9 דוגמאות');
  const L = build('cladding', { walls: 2, turn2: 'left', len1: 3000, len2: 2000 });
  check(L.bounds.w === 3038 && L.bounds.d === 2038, 'פנייה שמאלה = פינה חיצונית (עוטף בליטה): החיפוי יוצא מעבר לקירות');
  const corner = L.parts.some((p) => p.box.x <= 3019 && p.box.x + p.box.w >= 3019 && p.box.z <= 19 && p.box.z + p.box.d >= 19 && p.box.y <= 1200 && p.box.y + p.box.h >= 1200);
  check(corner, 'פינה חיצונית: הקצה מכוסה (אין חור בפינה)');
  const flat = build('cladding', { style: 'flat', len1: 5000 });
  check(flat.parts.filter((p) => p.name === 'לוח חיפוי').length === 5, 'לוחות רצופים: 5000 → 5 לוחות עד 1200');
}

console.log('ספרייה: פיצול, ויטרינות, דפנות זכוכית, לד');
{
  const r = build('bookcase', { width: 1500, columns: 3, lowerH: 900, lowerDoors: 'wood', doorType: 'glass', doorFinish: 'fluted', glassColumns: 'first', glassSides: 'left', led: 'sides' });   // מפתחות ישנים — בודק גם את ההסבה
  const ids = r.parts.map((p) => p.id);
  check(ids.includes('door-1-stile-L') && ids.includes('door-1-glass') && !ids.some((x) => x.startsWith('door-1-lo')), 'עמודה 1: ויטרינה מלאה (בלי פיצול)');
  check(ids.includes('door-2-lo') && ids.includes('door-2-up-stile-L'), 'עמודה 2: דלת עץ למטה, ויטרינה למעלה');
  check(ids.includes('split-shelf-2-1') && !ids.includes('split-shelf-1-1'), 'מדף קבוע בפיצול — רק בעמודות המפוצלות');
  const flutes = r.parts.filter((p) => p.id.startsWith('door-2-lo-strip'));
  check(flutes.length > 10 && flutes.every((p) => p.motion === r.parts.find((q) => q.id === 'door-2-lo').motion), `סטריפים על דלת העץ, נעים איתה (${flutes.length})`);
  check(ids.includes('side-L-glass') && ids.includes('side-L-stile-F') && !ids.includes('side-L') && ids.includes('side-R'), 'דופן שמאל: זכוכית בין זקפים; ימין לוח');
  check(r.hardware.filter((h) => h.kind === 'led').length === 6 && r.hardware.find((h) => h.kind === 'led').qty > 1, '6 פסי לד אנכיים, במטרים');
  const lo = r.parts.find((p) => p.id === 'door-2-lo'), up = r.parts.find((p) => p.id === 'door-2-up-stile-L');
  check(Math.abs(lo.box.y + lo.box.h + 2 - up.box.y) < 0.01, 'הדלת העליונה מתחילה איפה שהתחתונה נגמרת');
  check(r.values.sideLeftFinish === 'glass' && r.values.doorFinish === 'fluted-fine' && r.values.glassSides === undefined, 'הסבה: glassSides/fluted ישנים → המפתחות החדשים');
}

console.log('ספרייה: גוף פנימי/חיצוני, דפנות לפי צד, פריסת עמודות');
{
  const base = { width: 1800, height: 2000, columns: 3, shelvesPerColumn: 4, bodyMaterial: 'board:melamine-oak-18', shelfMaterial: 'board:melamine-oak-18' };
  const r0 = build('bookcase', base);
  check(r0.parts.every((p) => ['side-L', 'side-R', 'partition-1', 'shelf-1-1'].includes(p.id) ? p.material === 'board:melamine-oak-18' : true), 'ברירת מחדל: פנימי וחיצוני אותו חומר');
  const r1 = build('bookcase', { ...base, innerMaterial: 'board:melamine-white-18', sideRightMaterial: 'board:veneer-walnut-18' });
  const m = (id) => r1.parts.find((p) => p.id === id).material;
  check(m('side-L') === 'board:melamine-oak-18' && m('side-R') === 'board:veneer-walnut-18' && m('partition-1') === 'board:melamine-white-18' && m('shelf-1-1') === 'board:melamine-white-18', 'פנימי לבן, דופן ימין אגוז, שמאל כמו הגוף');
  const r2 = build('bookcase', { ...base, sideLeftFinish: 'fluted-wide', sideRightFinish: 'grooved' });
  const stripsL = r2.parts.filter((p) => p.id.startsWith('side-L-strip'));
  const sideL = r2.parts.find((p) => p.id === 'side-L');
  check(stripsL.length > 5 && stripsL.every((p) => Math.abs(p.box.x + p.box.w - sideL.box.x) < 0.01) && sideL.box.x === 10, `סטריפים רחבים על דופן שמאל, כלפי חוץ (${stripsL.length}) והמודל מוזז פנימה`);
  check(r2.parts.find((p) => p.id === 'side-R').surface === 'grooved' && r2.bounds.w === 1810, 'דופן ימין: חריצים (סימון), הגבולות כוללים את הסטריפים');
  check(r2.parts.every((p) => p.box.x >= -0.01), 'אחרי ההזזה אין חלק ב-x שלילי');
  const r3 = build('bookcase', { ...base, columnsLayout: { 0: { shelves: 2 }, 2: { shelves: 3, gaps: [700, 100, 100, 100] } } });
  const n = (c) => r3.parts.filter((p) => p.id.startsWith(`shelf-${c}-`)).length;
  check(n(1) === 2 && n(2) === 4 && n(3) === 3, `מדפים לפי עמודה: ${n(1)}/${n(2)}/${n(3)}`);
  const ys = [1, 2, 3].map((i) => r3.parts.find((p) => p.id === `shelf-3-${i}`).box.y);
  const inner = r3.parts.find((p) => p.id === 'bottom');
  const g0 = ys[0] - (inner.box.y + inner.box.h), g1 = ys[1] - ys[0] - 18;
  check(Math.abs(g0 / g1 - 7) < 0.05 && Math.abs(ys[2] - ys[1] - 18 - g1) < 0.01, `תא תחתון פי 7 מהאחרים (${g0.toFixed(0)} / ${g1.toFixed(0)})`);
  const space = template('bookcase').columnSpace(r3.values);
  check(space.sections[0].items.length === 3 && Math.abs(space.sections[0].items[0].innerH - (r3.parts.find((p) => p.id === 'top').box.y - inner.box.y - inner.box.h)) < 0.01, 'columnSpace: הגובה הפנוי תואם את הגוף');
  const w = build('wardrobe', { innerMaterial: 'board:melamine-white-18', sideLeftFinish: 'fluted-fine', doorType: 'wood', doorFinish: 'fluted-fine' });
  check(w.parts.find((p) => p.id === 'partition-1').material === 'board:melamine-white-18' && w.parts.some((p) => p.id.startsWith('side-L-strip')) && w.parts.some((p) => p.id.includes('door') && p.id.includes('-strip-')) && w.parts.every((p) => p.box.x >= -0.01), 'ארון: פנימי נפרד, סטריפים בדופן ובדלתות');
  const sh = build('bookcase', { led: 'shelves' });
  check(sh.hardware.filter((h) => h.kind === 'led' && h.horizontal).length === 12, 'לד מתחת לכל מדף: 12');
  check(build('bookcase', { lowerH: 5000 }).warnings.some((w) => w.includes('הפיצול בוטל')), 'פיצול גבוה מדי — אזהרה וביטול');
}

console.log('פריסה: נעוץ ואוטומטי, עריכה בשלושה מצבים, רוחבי עמודות');
{
  const eq = (a, b) => a.length === b.length && a.every((x, i) => Math.abs(x - b[i]) < 0.01);
  check(eq(resolveShares(900, [null, null, null]), [300, 300, 300]), 'הכול אוטומטי — בשווה');
  check(eq(resolveShares(900, [500, null, null]), [500, 200, 200]), 'נעוץ אחד — השאר מתחלקים במה שנשאר');
  check(eq(resolveShares(900, [500, 300, null]), [500, 300, 100]), 'שני נעוצים — האוטומטי מקבל את השארית');
  const squeezed = resolveShares(900, [800, 600, null]);
  check(Math.abs(squeezed[0] + squeezed[1] + squeezed[2] - 900) < 0.01 && squeezed[2] === 50 && squeezed[0] > squeezed[1], 'נעוצים שחורגים — מוקטנים ביחס, האוטומטי שומר מינימום');
  const allPinned = resolveShares(1000, [300, 300, 300]);
  check(eq(allPinned, [1000 / 3, 1000 / 3, 1000 / 3]), 'כולם נעוצים בסכום שגוי — מתוקנים ביחס');
  // עריכה
  check(eq(editShare(900, [null, null, null], 0, 500, 'even'), [500, null, null]), 'בשווה: רק הנערך ננעץ, האוטומטיים סופגים');
  check(eq(resolveShares(900, editShare(900, [null, null, null], 0, 500, 'even')), [500, 200, 200]), '…ואחרי פתירה 500/200/200');
  check(eq(editShare(900, [null, null, null], 0, 500, 'next'), [500, 100, null]), 'מהשכן הבא: השכן ננעץ ב-300-200, השלישי נשאר אוטומטי (300)');
  check(eq(editShare(900, [null, null, null], 1, 400, 'prev'), [200, 400, null]), 'מהשכן הקודם');
  check(eq(editShare(900, [null, null, null], 2, 500, 'next'), [null, null, 500]), 'בקצה העליון "מעליו" נופל ל"בשווה"');
  check(eq(editShare(900, [null, null, null], 0, 500, 'prev'), [500, null, null]), 'בקצה התחתון "מתחתיו" נופל ל"בשווה"');
  check(eq(editShare(900, [300, 300, 300], 0, 500, 'even'), [500, 200, 200]), 'כולם נעוצים, בשווה: ההפרש מתחלק בין האחרים');
  check(eq(editShare(900, [null, null, null], 0, 5000, 'even'), [800, null, null]), 'ערך מוגזם נחסם כך שלאחרים נשאר מינימום');
  // הסבה
  const old = normalizeLayout({ 0: { shelves: 2 }, 2: { shelves: 3, gaps: [600, 200, 200, 200] } }, 3);
  check(old.widths.every((w) => w === null) && old.cols[0].shelves === 2 && old.cols[2].gaps.length === 4 && old.cols[2].gaps[0] === 600 && !old.cols[1], 'פורמט ישן → חדש: רוחבים אוטומטיים, תאים נעוצים');
  const arr = normalizeLayout({ widths: [400, null], cols: [{ shelves: 1 }, null] }, 3);
  check(arr.widths.length === 3 && arr.widths[0] === 400 && arr.widths[2] === null && arr.cols[0].shelves === 1 && !arr.cols[1], 'מערכים אחרי JSON, ומספר עמודות שגדל');
  check(layoutIsEmpty(normalizeLayout(null, 3)) && !layoutIsEmpty(arr), 'layoutIsEmpty');
  // במודל
  const base = { width: 1800, height: 2000, columns: 3, shelvesPerColumn: 4 };
  const r = build('bookcase', { ...base, columnsLayout: { widths: [800, null, null] } });
  const cw = (i) => { const c = r.parts.find((p) => p.id === `shelf-${i}-1`); return c.box.w; };
  const freeW = 1800 - 4 * 18;
  check(Math.abs(cw(1) - 800) < 0.01 && Math.abs(cw(2) - (freeW - 800) / 2) < 0.01 && Math.abs(cw(2) - cw(3)) < 0.01, `עמודה 1 ברוחב 800, השאר בשווה (${cw(2).toFixed(0)})`);
  const p1 = r.parts.find((p) => p.id === 'partition-1'), p2 = r.parts.find((p) => p.id === 'partition-2');
  check(Math.abs(p1.box.x - (18 + 800)) < 0.01 && Math.abs(p2.box.x + 18 + cw(3) - (1800 - 18)) < 0.01, 'המחיצות במקום הנכון, העמודה האחרונה נגמרת בדופן');
  const rg = build('bookcase', { ...base, columnsLayout: { widths: [null, null, null], cols: { 1: { shelves: 4, gaps: [600, null, null, null, null] } } } });
  const ys = [1, 2, 3, 4].map((i) => rg.parts.find((p) => p.id === `shelf-2-${i}`).box.y);
  const bottom = rg.parts.find((p) => p.id === 'bottom');
  const g0 = ys[0] - (bottom.box.y + bottom.box.h);
  const gs = [ys[1] - ys[0] - 18, ys[2] - ys[1] - 18, ys[3] - ys[2] - 18];
  check(Math.abs(g0 - 600) < 0.01 && gs.every((g) => Math.abs(g - gs[0]) < 0.01), `תא תחתון נעוץ 600, השאר אוטומטיים ושווים (${gs[0].toFixed(0)})`);
  const r2 = build('bookcase', { ...base, width: 2400, columnsLayout: { widths: [800, null, null] } });
  check(Math.abs(r2.parts.find((p) => p.id === 'shelf-1-1').box.w - 800) < 0.01, 'שינוי רוחב הספרייה לא נוגע בעמודה הנעוצה');
  // ארון בגדים: אותו עורך — רוחב לכל עמודה, תאים בעמודת מדפים (מעל המגירות), תלייה רק רוחב
  const wb = { width: 2400, height: 2400, columns: 3, hangingColumns: 1, shelvesPerColumn: 4, drawersPerColumn: 2, drawerH: 200 };
  const w = build('wardrobe', { ...wb, columnsLayout: { widths: [1000, null, null], cols: { 2: { shelves: 3, gaps: [500, null, null, null] } } } });
  const rod = w.hardware.find((h) => h.id === 'rod-1');
  const over2 = w.parts.find((p) => p.id === 'over-drawers-shelf-3-1');
  const s3 = [1, 2, 3].map((i) => w.parts.find((p) => p.id === `shelf-3-${i}`));
  check(Math.abs(w.parts.find((p) => p.id === 'partition-1').box.x - (18 + 1000)) < 0.01 && s3.every(Boolean) && !w.parts.some((p) => p.id === 'shelf-3-4'), 'ארון: עמודת תלייה ברוחב 1000, עמודה 3 עם 3 מדפים');
  const y0 = 80 + 18 + 2 * 200 + 18;   // סוקל + רצפה + מגירות + המדף שמעליהן
  check(Math.abs(s3[0].box.y - y0 - 500) < 0.01 && Math.abs((s3[2].box.y - s3[1].box.y) - (s3[1].box.y - s3[0].box.y)) < 0.01, 'ארון: התא התחתון מעל המגירות 500, השאר שווים');
  const wsp = template('wardrobe').columnSpace(w.values);
  check(wsp.sections[0].items[0].editable === false && wsp.sections[0].items[2].editable === true && Math.abs(wsp.sections[0].items[2].innerH - (w.parts.find((p) => p.id === 'top').box.y - y0)) < 0.01, 'ארון columnSpace: תלייה לא נערכת, גובה פנוי מעל המגירות תואם');
  check(Boolean(rod) && Math.abs(rod.pos ? rod.pos[0] : 0) >= 0, 'ארון: המוט קיים');
  // פורמט הקבוצות: אותה ספרייה דרך { sections: { main } }
  const rs = build('bookcase', { ...base, columnsLayout: { sections: { main: { widths: [800, null, null] } } } });
  check(Math.abs(rs.parts.find((p) => p.id === 'shelf-1-1').box.w - 800) < 0.01, 'ספרייה: פורמט הקבוצות (sections.main) נקרא');
}

console.log('מטבח ושידה: אותו עיקרון — רוחב לכל ארון, סוג, מגירות ותאים; שורות ועמודות בשידה');
{
  const kv = { shape: 'line', length: 3000, cabinets: 4, drawerCabinets: 1, drawersPerCabinet: 3, shelvesPerCabinet: 1, uppers: 'yes' };
  const sp = template('kitchen').columnSpace({ ...defaults(template('kitchen')), ...kv });
  check(sp.sections.map((x) => x.key).join(',') === 'baseA,upperA' && sp.sections[0].items[0].kind === 'drawers' && sp.sections[0].items[1].kind === 'doors' && sp.sections[0].total === 3000, 'מטבח קו ישר: שתי קבוצות, הראשון מגירות');
  const k = build('kitchen', { ...kv, columnsLayout: { sections: { baseA: { widths: [1000, null, null, null], cols: { 1: { kind: 'drawers', shelves: 4, gaps: [300, null, null, null] }, 3: { shelves: 2 } } }, upperA: { widths: [null, 400, null, null] } } } });
  const span = (r, pre, ax = 'x', sz = 'w') => { const L = r.parts.find((p) => p.id === `${pre}-side-L`).box, R = r.parts.find((p) => p.id === `${pre}-side-R`).box; return { x: L[ax], w: R[ax] + R[sz] - L[ax] }; };
  check(Math.abs(span(k, 'ת1').w - 1000) < 0.01 && Math.abs(span(k, 'ת2').w - 2000 / 3) < 0.01 && Math.abs(span(k, 'ת2').x - 1000) < 0.01, `מטבח: ארון 1 ברוחב 1000, השאר בשווה (${span(k, 'ת2').w.toFixed(0)}), צמודים`);
  const d2 = [1, 2, 3, 4].map((r) => k.parts.find((p) => p.id === `ת2-drawer-${r}`));
  check(d2.every(Boolean) && !k.parts.some((p) => p.id === 'ת2-drawer-5') && !k.parts.some((p) => p.id.startsWith('ת2-shelf')), 'מטבח: ארון 2 הפך למגירות (4), בלי מדפים');
  const boxes = [1, 2, 3, 4].map((r) => k.parts.find((p) => p.id === `ת2-drawer-${r}-side-L`));
  const hs = boxes.map((b, i) => (i < 3 ? boxes[i + 1].box.y - b.box.y : null)).filter((x) => x !== null);
  check(Math.abs(hs[0] - 300) < 0.01 && Math.abs(hs[1] - hs[2]) < 0.01, `מטבח: המגירה התחתונה 300, השאר שוות (${hs[1].toFixed(0)})`);
  check(k.parts.filter((p) => p.id.startsWith('ת4-shelf-')).length === 2 && k.parts.filter((p) => p.id.startsWith('ת3-shelf-')).length === 1, 'מטבח: ארון 4 עם 2 מדפים, ארון 3 ברירת מחדל');
  check(Math.abs(span(k, 'ע2').w - 400) < 0.01 && Math.abs(span(k, 'ע1').w - 2600 / 3) < 0.01, 'מטבח: עליון 2 ברוחב 400, השאר בשווה');
  const kl = build('kitchen', { shape: 'L', length: 3000, lengthB: 2400, cabinets: 2, cabinetsB: 2, cornerSize: 1000, columnsLayout: { sections: { baseB: { widths: [500, null] } } } });
  const b1 = span(kl, 'ב1', 'z', 'd'), b2 = span(kl, 'ב2', 'z', 'd');
  check(Math.abs(b1.w - 500) < 0.01 && Math.abs(b2.w - 900) < 0.01 && Math.abs(b2.x - 1500) < 0.01, "מטבח ר': הקיר השני — ארון 1 ברוחב 500 (לאורך Z), השני משלים");
  // שידה
  const dv = { width: 1000, height: 850, drawerRows: 4, drawerColumns: 2, topRowH: 150 };
  const d0 = build('dresser', dv);
  const rowY = (c, r) => d0.parts.find((p) => p.id === `drawer-${c}-${r}`).box.y;
  check(Math.abs((rowY(1, 2) - rowY(1, 1)) - (rowY(1, 3) - rowY(1, 2))) < 0.01, 'שידה: בלי פריסה — השורות התחתונות שוות');
  const dsp = template('dresser').columnSpace({ ...defaults(template('dresser')), ...dv });
  check(dsp.sections[0].key === 'cols' && dsp.sections[1].key === 'rows' && dsp.sections[1].items[3].defaultPin === 150 && dsp.sections[1].items[0].defaultPin === null, 'שידה columnSpace: עמודות ושורות, השורה העליונה נעוצה כברירת מחדל ל-150');
  const d1 = build('dresser', { ...dv, columnsLayout: { sections: { cols: { widths: [300, null] }, rows: { widths: [null, 250, null, null] } } } });
  const c1 = d1.parts.find((p) => p.id === 'drawer-1-1').box, c2 = d1.parts.find((p) => p.id === 'drawer-2-1').box;
  const ry = (r) => d1.parts.find((p) => p.id === `drawer-1-${r}`).box.y;
  const part1 = d1.parts.find((p) => p.id === 'partition-1').box, sideL = d1.parts.find((p) => p.id === 'side-L').box;
  check(Math.abs(part1.x - (sideL.x + sideL.w) - 300) < 0.01 && c2.w > c1.w + 200, `שידה: עמודה 1 ברוחב 300 (המחיצה במקום), עמודה 2 מקבלת את השאר (${c2.w.toFixed(0)})`);
  check(Math.abs((ry(3) - ry(2)) - 250) < 0.01 && Math.abs(ry(2) - ry(1) - (ry(4) - ry(3))) < 0.01, 'שידה: שורה 2 נעוצה 250, שורות 1 ו-3 שוות; העליונה 150 מברירת המחדל');
  const top = d1.parts.find((p) => p.id === 'drawer-1-4').box;
  check(Math.abs(top.h - 150) < 20, `שידה: חזית השורה העליונה ${top.h.toFixed(0)} ≈ 150`);
}


console.log('הרכבה: הנחה, סיבוב, איחוד, הצמדה');
{
  const low = build('bookcase', { width: 1800, height: 900, depth: 400, columns: 3, doorType: 'wood' });
  const up = build('bookcase', { width: 1200, height: 1100, depth: 300, columns: 2 });
  const pl = placeModel(up, { pos: [300, 900, 100], rot: 0, prefix: 'e1:' });
  check(pl.parts.every((p) => p.id.startsWith('e1:')) && pl.bounds.x === 300 && pl.bounds.y === 900 && pl.bounds.w === 1200, 'הנחה: קידומת ו-גבולות במקום החדש');
  const sideL = pl.parts.find((p) => p.id === 'e1:side-L');
  check(sideL.box.x === 300 && sideL.box.y === 900 && sideL.box.z === 100, 'הדופן השמאלית זזה עם המודל');
  check(up.parts.find((p) => p.id === 'side-L').box.x === 0, 'המקור לא השתנה');
  const r90 = placeModel(low, { pos: [0, 0, 0], rot: 90, prefix: 'r:' });
  check(r90.bounds.w === low.bounds.d && r90.bounds.d === 1800 && within(r90.parts, r90.bounds) && allFinite(r90.parts), 'סיבוב 90: הרוחב והעומק מתחלפים, הכול בתוך הגבולות');
  const sL = r90.parts.find((p) => p.id === 'r:side-L');
  check(sL.axis === 'z' && sL.grain === 'y' && sL.box.d === 18 && sL.box.w === 400, 'דופן מסובבת: ציר העובי z, הסיבים נשארים לגובה');
  const door = low.parts.find((p) => p.motion && p.motion.kind === 'hinge');
  const doorR = r90.parts.find((p) => p.id === 'r:' + door.id);
  check(doorR.motion.group === 'r:' + door.motion.group && doorR.motion.angle === door.motion.angle && Math.abs(doorR.motion.pivot[1] - door.motion.pivot[1]) < 0.01, 'תנועה: קבוצה עם קידומת, הזווית נשמרת (סיבוב, לא שיקוף)');
  const pv = door.motion.pivot, pr = doorR.motion.pivot;
  check(Math.abs(pr[0] - pv[2]) < 0.01 && Math.abs(pr[2] - (1800 - pv[0])) < 0.01, 'הציר מסתובב כנקודה');
  const r180 = placeModel(low, { rot: 180 }), r270 = placeModel(low, { rot: 270 });
  check(r180.bounds.w === 1800 && r270.bounds.w === low.bounds.d && within(r180.parts, r180.bounds) && within(r270.parts, r270.bounds), '180 ו-270 בתוך הגבולות');
  const hw = r90.hardware.filter((h) => h.pos);
  check(hw.length > 0 && hw.every((h) => h.pos[0] >= -30 && h.pos[0] <= r90.bounds.w + 30 && h.pos[2] >= -1 && h.pos[2] <= 1801), 'פרזול מסתובב יחד');
  // איחוד: ארונית מעל מזווה, ממורכזת
  const a = { model: low, pos: [0, 0, 0], rot: 0, name: 'מזווה', key: 1 };
  const b = { model: up, pos: snapTo({ ...placeModel(up).bounds }, { x: 0, y: 0, z: 0, w: 1800, h: 900, d: 400 }, 'above'), rot: 0, name: 'ארונית', key: 2 };
  b.pos = snapTo({ x: b.pos[0], y: b.pos[1], z: b.pos[2], w: 1200, h: 1100, d: 300 }, { x: 0, y: 0, z: 0, w: 1800, h: 900, d: 400 }, 'center');
  check(b.pos[1] === 900 && b.pos[0] === 300 && b.pos[2] === 50, `הצמדה: מעל ואז מרכוז → ${b.pos.join(',')}`);
  const c = combine([a, b], { joined: true, name: 'מזווה וארונית' });
  check(c.parts.length === low.parts.length + up.parts.length && c.bounds.w === 1800 && c.bounds.h === 2000 && c.bounds.d === low.bounds.d, 'איחוד: כל החלקים, גבולות עוטפים');
  check(c.items.length === 2 && c.items[1].bounds.y === 900 && c.template.laborHours === low.template.laborHours + up.template.laborHours && c.joined, 'items עם גבולות, שעות עבודה מצטברות');
  check(c.warnings.every((w) => !w.includes('חופפים')), 'בלי חפיפה כשמונח מעל');
  const cl = cutList(c);
  const sameShelf = cl.boards.find((r) => r.ids.some((id) => id.startsWith('e1:shelf')) && r.ids.some((id) => id.startsWith('e2:shelf')));
  check(cl.boards.length > 0 && (sameShelf ? sameShelf.qty > 1 : true), 'רשימת חיתוך של ההרכבה עובדת (חלקים זהים משני האלמנטים מתקבצים)');
  const overlap = combine([a, { ...b, pos: [0, 500, 0] }]);
  check(overlap.warnings.some((w) => w.includes('חופפים')), 'אזהרת חפיפה כשארונית נכנסת למזווה');
  const neg = combine([a, { ...b, pos: [-500, 0, 0] }]);
  check(neg.bounds.w === 2300 && neg.parts.every((p) => p.box.x >= -0.01) && neg.items[0].bounds.x === 500, 'מיקום שלילי: הכול מוזז כך שהמינימום 0');
  const hidden = combine([a, { ...b, visible: false }]);
  check(hidden.parts.length === low.parts.length && hidden.items.length === 2 && !hidden.items[1].visible, 'אלמנט מוסתר: לא בחלקים, כן ברשימת הפריטים');
  const e = estimate(c, {});
  check(e.total > estimate(low, {}).total && e.labor.hours === c.template.laborHours, 'מחיר ההרכבה גדול ממחיר המזווה לבדו');
}

console.log('אביזרים: ידיות, כפתורים, צירים, גלגלים');
{
  const finite = (prims) => prims.every((pr) => pr.pos.every(Number.isFinite) && pr.size.every((x) => Number.isFinite(x) && x > 0) && pr.finish && pr.finish.color !== undefined);
  for (const t of TYPES) {
    const a = buildAccessory({ accessory: { type: t.id, params: {}, finish: 'chrome' } }, { side: 'right' });
    const lo = buildAccessory({ accessory: { type: t.id, params: Object.fromEntries(t.params.map((p) => [p.key, p.min])), finish: 'black' } });
    const hi = buildAccessory({ accessory: { type: t.id, params: Object.fromEntries(t.params.map((p) => [p.key, p.max])), finish: 'brass' } });
    check(a && a.prims.length > 0 && finite(a.prims) && finite(lo.prims) && finite(hi.prims) && a.type.kind === t.kind, `${t.id} (${t.name}): ${a ? a.prims.length : 0} גופים, גם בקצוות הטווח`);
  }
  check(paramsOf(TYPES[0], { length: 5000, diameter: 'x' }).length === 1200 && paramsOf(TYPES[0], {}).diameter === 10, 'paramsOf: הצמדה לטווח וברירות מחדל');
  check(buildAccessory({ accessory: { type: 'no-such' } }) === null && buildAccessory({}) === null, 'חומר בלי מתכון → null');
  const conc = buildAccessory(M.material('hw:hinge-110'), { side: 'left' });
  check(conc.prims.filter((p) => p.moving).length === 2 && conc.prims.filter((p) => !p.moving).length === 2 && conc.prims.find((p) => !p.moving).pos[0] < 0, 'ציר נסתר: כוס וזרוע נעים עם הדלת, גוף ופלטה בצד הציר (שמאל = -x)');
  const b = { x: 100, y: 0, z: 50, w: 400, h: 700, d: 18 };
  check(faceOf(b, [300, 350, 68]) === '+z' && faceOf(b, [300, 350, 50]) === '-z' && faceOf({ x: 0, y: 0, z: 0, w: 18, h: 700, d: 400 }, [18, 300, 200]) === '+x' && faceOf(b, [300, 0, 60]) === '-y', 'faceOf: חזית, אחור, צד ימין, תחתית');
  check(M.materialsOfRole('handle').length >= 12 && M.materialsOfRole('hinge').length >= 4 && M.materialsOfRole('wheel').length >= 4 && M.materialsOfRole('handle').every((m) => m.accessory), 'הספרייה: תפקידים לפי סוג האביזר');
  const opts = optionsFor({ type: 'material', kind: 'hardware', role: 'handle', allowNone: true });
  check(opts[0].id === 'none' && opts.some((o) => o.id === 'hw:handle-bar-320-brass'), 'אפשרויות ידית: "ללא" + האביזרים');
  check(wheelHeight(M.material('hw:caster-swivel-50')) === 62 && wheelHeight(M.material('hw:caster-brake-75')) > wheelHeight(M.material('hw:caster-swivel-50')) && wheelHeight(M.material('hw:handle-knob')) === 0, `גובה גלגל 50: ${wheelHeight(M.material('hw:caster-swivel-50'))}; לא-גלגל = 0`);
  // גלגלים על שידה: הכול מורם, 4 גלגלים בפינות, הגבולות גדלים
  const d0 = build('dresser', { width: 1000, height: 850 }), d1 = build('dresser', { width: 1000, height: 850, wheels: 'hw:caster-swivel-50' });
  const lift = wheelHeight(M.material('hw:caster-swivel-50'));
  const wheels = d1.hardware.filter((h) => h.kind === 'wheel');
  check(wheels.length === 4 && wheels.every((h) => h.pos[1] === lift) && d1.bounds.h === d0.bounds.h + lift, 'שידה על גלגלים: 4 גלגלים בגובה ההרמה, הגבולות גדלים');
  check(d1.parts.find((p) => p.id === 'side-L').box.y === d0.parts.find((p) => p.id === 'side-L').box.y + lift && d1.parts.every((p) => p.box.y >= lift - 0.01), 'כל החלקים מורמים');
  const h0 = d0.hardware.find((h) => h.kind === 'handle'), h1 = d1.hardware.find((h) => h.kind === 'handle');
  check(h1.pos[1] === h0.pos[1] + lift, 'הידיות מורמות עם המגירות');
  const bk = build('bookcase', { doorType: 'wood', handle: 'hw:handle-bar-320-brass', hinge: 'hw:hinge-butt', wheels: 'hw:caster-brake-75' });
  check(bk.values.handle === 'hw:handle-bar-320-brass' && bk.values.hinge === 'hw:hinge-butt' && bk.hardware.some((h) => h.material === 'hw:handle-bar-320-brass') && bk.hardware.filter((h) => h.kind === 'wheel').length === 4, 'ספרייה: ידית, ציר וגלגלים מהאביזרים');
  const none = build('bookcase', { doorType: 'wood', handle: 'none', wheels: 'none' });
  check(none.values.handle === 'none' && !none.hardware.some((h) => h.kind === 'handle') && none.values.wheels === 'none', '"ללא" לידיות ולגלגלים');
  const tb = build('table', { wheels: 'hw:caster-fixed-50' });
  check(tb.hardware.filter((h) => h.kind === 'wheel').length === 4 && tb.bounds.h === 750 + wheelHeight(M.material('hw:caster-fixed-50')), 'שולחן על גלגלים');
  check(ACC_FINISHES.length >= 6, 'גימורים');
}

if (failed) { console.error(`\n${failed} בדיקות נכשלו`); process.exit(1); }
console.log('\nהכול עבר ✓');
