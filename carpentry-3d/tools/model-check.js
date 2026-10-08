#!/usr/bin/env node
// בדיקת המודל בלי דפדפן: בונה ספריות בכמה תצורות ומוודא שהגאומטריה
// עקבית — אין NaN, אין חלק מחוץ לגוף, הדפנות והגג נפגשים, המדפים בתוך
// העמודות, והאזהרות נדלקות במקום הנכון.
//
//   node carpentry-3d/tools/model-check.js

import { build, cutList, hardwareList, defaults, template, optionsFor, allParams, TEMPLATES } from '../assets/js/model/index.js';
import * as M from '../assets/js/model/materials.js';
import { cutSize } from '../assets/js/model/blocks.js';
import { estimate } from '../assets/js/model/pricing.js';
import { resolveShares, editShare, normalizeLayout, layoutIsEmpty } from '../assets/js/model/layout.js';
import { placeModel, combine, snapTo, dragSnap, dragSnapY, snapRot } from '../assets/js/model/assembly.js';
import { applyXf, partAabb, partCorners, xfPart, compose } from '../assets/js/model/xform.js';
import { relatedParams, setLayoutProp, getLayoutProp, cutAxes } from '../assets/js/model/partEdits.js';
import { TYPES, buildAccessory, paramsOf, faceOf, wheelHeight, FINISHES as ACC_FINISHES } from '../assets/js/model/accessories.js';
import { nest, sheetCount } from '../assets/js/model/sheets.js';
import { partWeight, totalWeight, boardWeight, hingeCount, hingeYs, hingeDrilling, slidingLeaf, SLIDING_SYSTEMS, slideLoad, drillingList, physicsWarnings, densityOf } from '../assets/js/model/physics.js';
import { toSTL, printSize } from '../assets/js/model/stl.js';
import { millSpec, fluteGrooves, fluteRib, millRects, millSolids, millRemoved, millText, millEdges, cncPatterns } from '../assets/js/model/milling.js';

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
  check(conc.prims.filter((p) => p.moving).length === 2 && conc.prims.filter((p) => !p.moving).length === 2 && Math.min(...conc.prims.filter((p) => !p.moving).map((p) => p.pos[0] - p.size[0] / 2)) >= -4.5 - 1e-9, 'ציר נסתר: כוס וזרוע נעים עם הדלת; גוף ופלטה לא חוצים את הפאה הפנימית של הדופן (שמאל: עד −4.5)');
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

// ---- עריכת רכיב בודד מהתלת מימד ----
{
  console.log('\nעריכת רכיב');
  const base = build('bookcase', { columns: 2, doorType: 'wood' });
  const shelf = base.parts.find((p) => p.id.startsWith('shelf'));
  const c0 = cutSize(shelf);
  const ed = build('bookcase', { columns: 2, doorType: 'wood', partEdits: { [shelf.id]: { material: 'board:solid-oak', size: { l: c0.l - 100, t: 25 }, move: { y: 50 }, edges: { back: true } } } });
  const s2 = ed.parts.find((p) => p.id === shelf.id), c1 = cutSize(s2);
  check(s2.material === 'board:solid-oak' && c1.l === c0.l - 100 && c1.t === 25 && c1.w === c0.w, `מדף: חומר, אורך ${c1.l} ועובי ${c1.t} — רק הוא`);
  check(s2.box.y === shelf.box.y + 50 && s2.edges.back === true, 'מדף: הזזה ב-Y וקנט אחורי');
  check(ed.parts.filter((p) => p.id.startsWith('shelf') && p.id !== shelf.id).every((p) => p.material === shelf.material), 'שאר המדפים בלי שינוי');
  check(cutList(ed).boards.some((r) => r.material === 'board:solid-oak' || /אלון/.test(r.material || '')) || cutList(ed).boards.length > cutList(base).boards.length, 'רשימת החיתוך מפרידה את המדף הערוך');
  // הסתרה: החלק והפרזול/הקידוחים שלו נעלמים
  const door = base.parts.find((p) => p.motion?.kind === 'hinge');
  const hid = build('bookcase', { columns: 2, doorType: 'wood', partEdits: { [door.id]: { hidden: true } } });
  check(!hid.parts.some((p) => p.id === door.id) && !hid.hardware.some((h) => h.for === door.id) && hid.parts.length === base.parts.length - 1, 'הסתרת דלת: היא והצירים/ידית שלה נעלמים');
  // זווית פתיחה: לכל חלקי הדלת, עם אותו כיוון
  const op = build('bookcase', { columns: 2, doorType: 'wood', partEdits: { [door.id]: { open: 165 } } });
  const d2 = op.parts.find((p) => p.id === door.id);
  check(Math.abs(d2.motion.angle) === 165 && Math.sign(d2.motion.angle) === Math.sign(door.motion.angle), `זווית פתיחה 165° (היה ${Math.abs(door.motion.angle)}°), אותו כיוון`);
  // הרחבת דלת עם ציר בצד הרחוק: הציר זז עם הקצה
  const wide = build('bookcase', { columns: 2, doorType: 'wood', doorOpen: { [door.motion.group]: 'right' }, partEdits: { [door.id]: { size: { w: cutSize(door).w + 40 } } } });
  const dw = wide.parts.find((p) => p.id === door.id);
  const axes = cutAxes(door);
  check(axes.w === 'x' ? Math.abs(dw.motion.pivot[0] - (dw.box.x + dw.box.w)) < 3 : true, 'דלת שהורחבה עם ציר מימין: הציר נשאר בקצה');
  // סיבוב רכיב: xf סביב מרכזו
  const rot = build('bookcase', { columns: 2, partEdits: { 'side-L': { yaw: 30 } } });
  const sl = rot.parts.find((p) => p.id === 'side-L');
  check(sl.xf && Math.abs(sl.xf.yaw - 30) < 1e-9 && cutSize(sl).l === cutSize(base.parts.find((p) => p.id === 'side-L')).l, 'סיבוב דופן ב-30°: xf, אותן מידות חיתוך');
  // id שכבר לא קיים — לא מפיל
  check(build('bookcase', { partEdits: { nope: { hidden: true } } }).parts.length === build('bookcase', {}).parts.length, 'עריכה לרכיב שלא קיים — נבלעת');
  // הגדרות קשורות: בחיפוי — סוג החיפוי וגובה השדה בעורך החלוקה, זוויות הפינות של הקיר
  const cl = build('cladding', { walls: 3, fields: 2 });
  const panel = cl.parts.find((p) => /^w2-f2-/.test(p.id));
  const rel = relatedParams(template('cladding'), panel, cl.values);
  check(rel.some((r) => r.layout === 'wall2' && r.col === 1 && r.prop === 'kind') && rel.includes('angle2') && rel.includes('angle3') && rel.includes('len2'), 'חיפוי: סוג החיפוי בשדה, אורך הקיר ושתי הפינות שלו');
  const lay = setLayoutProp(null, 'wall2', 1, 'kind', 'bricks');
  const cl2 = build('cladding', { walls: 3, fields: 2, columnsLayout: lay });
  check(getLayoutProp(lay, 'wall2', 1, 'kind') === 'bricks' && cl2.parts.some((p) => /^w2-f2-brick/.test(p.id)) && !cl2.parts.some((p) => /^w1-f2-brick/.test(p.id)), 'שינוי סוג החיפוי לשדה אחד מהכרטיס');
  check(getLayoutProp(setLayoutProp(lay, 'wall2', 1, 'kind', null), 'wall2', 1, 'kind') === undefined, 'ערך ריק מחזיר לברירת המחדל');
  const relB = relatedParams(template('bookcase'), door, base.values);
  check(relB.includes('doorFinish') && relB.includes('hinge') && relB.includes('width'), `ספרייה: לדלת — ${relB.slice(0, 6).join(', ')}…`);
  const relS = relatedParams(template('bookcase'), shelf, base.values);
  check(relS.includes('shelfMaterial') && relS.includes('shelvesPerColumn'), 'ספרייה: למדף — חומר המדפים ומספר המדפים');
}

// ---- זוויות חופשיות: הרכבה וקירות חיפוי ----
{
  console.log('\nזוויות חופשיות');
  check(snapRot(93) === 90 && snapRot(84) === 90 && snapRot(37.4) === 37 && snapRot(-90) === 270 && snapRot(357) === 0 && snapRot(185) === 180, 'הצמדה לרבעים בטווח 6°, אחרת מעלה שלמה');
  // xf: הלוך-חזור וקומפוזיציה
  const A = { yaw: 30, x: 100, z: -50 }, B = { yaw: 45, x: 10, z: 20 };
  const pt = [7, 3, 11], ab = applyXf(compose(A, B), pt), seq = applyXf(A, applyXf(B, pt));
  check(ab.every((v, i) => Math.abs(v - seq[i]) < 1e-6), 'compose(A,B) = A∘B');
  const q = xfPart({ id: 't', box: { x: 0, y: 0, z: 0, w: 600, h: 100, d: 18 }, axis: 'z', grain: 'x', edges: { front: true }, face: '+z' }, { yaw: 90, x: 0, z: 600 });
  check(!q.xf && q.box.w === 18 && q.box.d === 600 && q.axis === 'x' && q.grain === 'z' && q.face === '+x', 'רבע נאפה לתיבה מקבילה (צירים ופאה מסתובבים)');
  // הרכבה ב-30°: אותה רשימת חיתוך, חלקים עם xf, הגבולות עוטפים את כל הקודקודים
  const bc = build('bookcase', { columns: 2, doorType: 'wood' });
  const pl = placeModel(bc, { pos: [100, 0, 200], rot: 30 });
  check(pl.parts.every((p) => p.xf && Math.abs(p.xf.yaw - 30) < 1e-9), 'הרכבה 30°: כל החלקים עם xf');
  const corners = pl.parts.flatMap(partCorners);
  const minx = Math.min(...corners.map((c) => c[0])), maxx = Math.max(...corners.map((c) => c[0])), minz = Math.min(...corners.map((c) => c[2])), maxz = Math.max(...corners.map((c) => c[2]));
  // (החלקים עצמם יכולים להיות מעט בתוך גבולות המוצר — מרווחי דלתות)
  check(minx >= 100 - 0.01 && minz >= 200 - 0.01 && maxx <= 100 + pl.bounds.w + 0.01 && maxz <= 200 + pl.bounds.d + 0.01 && minx - 100 < 3 && minz - 200 < 3, `הגבולות עוטפים את כל הקודקודים (${Math.round(pl.bounds.w)}×${Math.round(pl.bounds.d)})`);
  const ex = (b) => (b.width ?? bc.bounds.w) * Math.cos(Math.PI / 6) + bc.bounds.d * Math.sin(Math.PI / 6);
  check(Math.abs(pl.bounds.w - ex({})) < 0.5, 'רוחב עוטף = w·cos30 + d·sin30');
  const cl = (m) => JSON.stringify(cutList(m).boards);
  check(cl({ ...bc, parts: pl.parts }) === cl(bc), 'רשימת החיתוך לא משתנה בסיבוב חופשי');
  const comb = combine([{ model: bc, pos: [0, 0, 0], rot: 0 }, { model: bc, pos: [2000, 0, 0], rot: 30 }]);
  check(comb.parts.filter((p) => p.xf).length === bc.parts.length && Math.abs(comb.bounds.w - (2000 + pl.bounds.w)) < 0.5, 'combine: חלקים מסובבים נשארים עם xf, הגבולות כוללים אותם');
  const doorM = pl.parts.find((p) => p.motion && p.motion.kind === 'hinge').motion;
  check(doorM.group.length > 0 && doorM.pivot && pl.parts.filter((p) => p.motion === doorM).length >= 1, 'דלת בהרכבה מסובבת: התנועה מקומית (הצופה מסובב את הצומת)');
  const stl = toSTL(pl.parts, { scale: 10, minT: 0 });
  const f = new Float32Array(stl.buffer.slice(84, 84 + 48));
  check(Math.abs(Math.hypot(f[0], f[1], f[2]) - 1) < 1e-5 && Math.abs(f[0]) > 0.1 && Math.abs(f[2]) > 0.1, 'STL: הנורמל של פאה מסובבת מסתובב');

  // חיפוי: זווית פינה חופשית — פרויקט ישן ("ימינה/שמאלה") עובר לזווית
  const tpl = template('cladding');
  const mig = tpl.migrate({ walls: 3, turn2: 'right', turn3: 'left' });
  check(mig.angle2 === 90 && mig.angle3 === 270 && mig.turn2 === undefined, 'פרויקט ישן: ימינה → 90°, שמאלה → 270°');
  check(allParams(tpl).find((p) => p.key === 'angle2').presets.join() === '90,180,270', 'קיצורים לזוויות 90/180/270');
  const legacy = build('cladding', { walls: 2, turn2: 'right', style: 'flat', len1: 1000, len2: 800 });
  const now = build('cladding', { walls: 2, angle2: 90, style: 'flat', len1: 1000, len2: 800 });
  check(JSON.stringify(legacy.parts.map((p) => p.box)) === JSON.stringify(now.parts.map((p) => p.box)), 'ימינה = 90° (אותן תיבות)');
  const straight = build('cladding', { walls: 2, angle2: 180, style: 'flat', len1: 1000, len2: 800 });
  check(straight.bounds.w === 1800 && !straight.parts.some((p) => p.miter || p.xf), '180°: קיר ישר באורך 1800, בלי גרונג');
  for (const ang of [60, 135, 225, 300]) {
    const m = build('cladding', { walls: 2, angle2: ang, style: 'flat', len1: 1000, len2: 800 });
    const a = m.parts.find((p) => p.id === 'w1-f1-panel-1'), b = m.parts.find((p) => p.id === 'w2-f1-panel-1');
    // קו הגרונג: הקודקודים המשופעים של שני הלוחות נפגשים
    const mit = (p) => {
      const bx = p.box, half = [bx.w / 2, bx.h / 2, bx.d / 2], c = [bx.x + half[0], bx.y + half[1], bx.z + half[2]];
      return [-1, 1].flatMap((sx) => [-1, 1].map((sz) => {
        const v = [sx * half[0], -half[1], sz * half[2]];
        for (const mm of p.miter || []) {
          const ai = 'xyz'.indexOf(mm.end[1]), s1 = mm.end[0] === '-' ? -1 : 1, bi = 'xyz'.indexOf(mm.short[1]), t1 = mm.short[0] === '-' ? -1 : 1;
          const lim = half[ai] - mm.cut * (t1 * v[bi] + half[bi]) / (2 * half[bi]);
          if (s1 * v[ai] > lim + 1e-6) v[ai] = s1 * lim;
        }
        return applyXf(p.xf, [c[0] + v[0], c[1] + v[1], c[2] + v[2]]).map((n) => Math.round(n * 10) / 10).join();
      }));
    };
    const shared = mit(a).filter((x) => mit(b).includes(x)).length;
    check(b.xf && a.miter && b.miter && shared === 2, `${ang}°: קיר 2 מסובב, שני הלוחות נפגשים בקו הגרונג (${shared} קודקודים)`);
    check(m.warnings.some((w) => new RegExp(`${ang}°.*גרונג ${Math.abs(180 - ang) / 2}°`).test(w)) && new RegExp(`גרונג ${Math.abs(180 - ang) / 2}°`).test(a.note), `${ang}°: גרונג ${Math.abs(180 - ang) / 2}° באזהרה ובהערה`);
    const bb = m.parts.reduce((acc, p) => { const x = partAabb(p); return [Math.min(acc[0], x.x), Math.min(acc[1], x.z)]; }, [Infinity, Infinity]);
    check(Math.abs(bb[0]) < 1e-6 && Math.abs(bb[1]) < 1e-6, `${ang}°: המודל מנורמל לראשית`);
  }
  const acute = build('cladding', { walls: 2, angle2: 60, style: 'flat', len1: 1000, len2: 800 });
  const bat = acute.parts.filter((p) => /^w1-batten/.test(p.id))[0];
  check(Math.abs(bat.box.w - (1000 - 18 / Math.tan(Math.PI / 3))) < 0.01, 'זווית חדה: הלטות של קיר 1 נעצרות לפני קיר 2');
}

// ---- חיפוי: צד החירוץ, התקנה בלי לטות, גרונג בפינה ----
{
  console.log('\nחיפוי: צד, ישר על הקיר, גרונג');
  const twoWalls = { walls: 2, turn2: 'right', style: 'flat', len1: 1000, len2: 800 };
  const fin = build('cladding', { ...twoWalls, partFinishes: { 'w1-f1-panel-1': 'cnc:milled-fine', 'w2-f1-panel-1': 'cnc:milled-fine' } });
  const n = Object.fromEntries(fin.parts.filter((p) => p.mill).map((p) => [p.id, p.mill.normal]));
  check(n['w1-f1-panel-1'] === '+z' && n['w2-f1-panel-1'] === '-x', `גימור מכרטיס הלוח בפינה פנימית — אל החדר בשני הקירות (${JSON.stringify(n)})`);
  const left = build('cladding', { ...twoWalls, turn2: 'left', partFinishes: { 'w1-f1-panel-1': 'cnc:milled-fine', 'w2-f1-panel-1': 'cnc:milled-fine' } });
  check(left.parts.filter((p) => p.mill).map((p) => p.mill.normal).join() === '+z,+x', 'בפינה חיצונית — גם אל החדר');
  const direct = build('cladding', { ...twoWalls, mount: 'direct' });
  check(!direct.parts.some((p) => /batten/.test(p.id)) && direct.parts.every((p) => p.name !== 'לטת רוחב'), 'ישר על הקיר: אין לטות');
  check(direct.parts.find((p) => p.id === 'w1-f1-panel-1').box.z === 0 && direct.bounds.d === 800, 'ישר על הקיר: הלוח צמוד לקיר');
  check(direct.hardware.some((h) => h.id === 'glue') && !direct.hardware.some((h) => h.id === 'screws'), 'ישר על הקיר: דבק במקום ברגים ללטות');
  check(allParams(template('cladding')).filter((p) => /^batten/.test(p.key)).every((p) => p.showIf?.mount?.[0] === 'battens'), 'שדות הלטות מוסתרים כשאין לטות');
  // גרונג: שני הלוחות נפגשים בקו אחד — פינה פנימית (ימינה) וחיצונית (שמאלה)
  const meet = (turn, mount) => {
    const m = build('cladding', { ...twoWalls, turn2: turn, mount, corner: 'miter' });
    const a = m.parts.find((p) => p.id === 'w1-f1-panel-1'), b = m.parts.find((p) => p.id === 'w2-f1-panel-1');
    return { m, a, b };
  };
  {
    const { a, b } = meet('right', 'battens');
    // פנימית: הפאה שאל החדר קצרה; בקיר 1 הקצה x, בקיר 2 התחלה ב-z
    check(a.miter?.[0]?.end === '+x' && a.miter[0].short === '+z' && a.miter[0].cut === 20 && a.box.x + a.box.w === 982, 'גרונג פנימי: קיר 1 עד הלטות של קיר 2, הפאה הקדמית קצרה ב-20');
    check(b.miter?.[0]?.end === '-z' && b.miter[0].short === '-x' && b.box.z === 18 && b.box.x === 962, 'גרונג פנימי: קיר 2 מתחיל אחרי הלטות של קיר 1');
    check(/גרונג/.test(a.note) && /גרונג/.test(b.note), 'גרונג מופיע בהערה (לרשימת החיתוך)');
  }
  {
    const { a, b } = meet('left', 'battens');
    check(a.miter?.[0]?.short === '-z' && a.box.x + a.box.w === 1038 && b.miter?.[0]?.end === '+z' && b.box.z + b.box.d === a.box.z + a.box.d, 'גרונג חיצוני: שניהם עד הפינה החיצונית, הפאה האחורית קצרה');
  }
  {
    const { a, b, m } = meet('right', 'direct');
    check(a.box.w === 1000 && b.box.z === 0 && !m.parts.some((p) => /batten/.test(p.id)), 'גרונג בלי לטות: מהקיר עד הקיר');
  }
  const butt = build('cladding', twoWalls);
  check(!butt.parts.some((p) => p.miter), 'חיבור ישר (ברירת מחדל): בלי גרונג');
  // הרכבה מסובבת: הפאה, החירוץ והגרונג מסתובבים עם החלק
  const placed = placeModel(build('cladding', { ...twoWalls, corner: 'miter', partFinishes: { 'w1-f1-panel-1': 'cnc:milled-fine' } }), { rot: 90 });
  const pp = placed.parts.find((p) => p.id === 'w1-f1-panel-1');
  check(pp.face === '+x' && pp.mill.normal === '+x' && pp.miter[0].end === '-z' && pp.miter[0].short === '+x', 'הרכבה 90°: פאה, חירוץ וגרונג מסתובבים');
}

// ---- כיוון פתיחה לדלת, וגימור לכל לוח ----
{
  console.log('\nכיוון פתיחה וגימור ללוח');
  const base = build('bookcase', { columns: 3, doorType: 'wood' });
  const auto = base.hardware.filter((h) => h.kind === 'hinge' && h.for === 'door-1');
  check(auto.every((h) => h.mount === 'side-L'), 'אוטומטי: דלת 1 על הדופן השמאלית');
  const flip = build('bookcase', { columns: 3, doorType: 'wood', doorOpen: { 'door-1': 'right' } });
  const fh = flip.hardware.filter((h) => h.kind === 'hinge' && h.for === 'door-1');
  const d1 = flip.parts.find((p) => p.id === 'door-1');
  check(fh.every((h) => h.mount === 'partition-1' && Math.abs(h.pos[0] - (d1.box.x + d1.box.w - 22.5)) < 0.01) && d1.motion.angle > 0, 'ציר מימין: הצירים בצד ימין, נקדחים במחיצה 1, הסיבוב הפוך');
  const kx = flip.hardware.find((h) => h.id === 'door-1-handle');
  check(!kx || kx.pos[0] < d1.box.x + d1.box.w / 2, 'הידית עוברת לצד הנגדי לציר');
  const up = build('bookcase', { columns: 3, doorType: 'wood', handle: 'hw:handle-bar-128', doorOpen: { 'door-2': 'top' } });
  const d2 = up.parts.find((p) => p.id === 'door-2');
  const uh = up.hardware.filter((h) => h.kind === 'hinge' && h.for === 'door-2');
  check(d2.motion.axis && d2.motion.axis[0] === 1 && d2.motion.angle < 0 && Math.abs(d2.motion.pivot[1] - (d2.box.y + d2.box.h)) < 0.01, 'קלפה: סיבוב סביב הקצה העליון (ציר X), כלפי מעלה');
  check(uh.length >= 2 && uh.every((h) => h.edge === 'top' && Math.abs(h.pos[1] - (d2.box.y + d2.box.h - 22.5)) < 0.01), `קלפה: ${uh.length} צירים לאורך הקצה העליון`);
  check(up.hardware.some((h) => h.material === 'hw:flap-lift' && h.for === 'door-2') && up.hardware.find((h) => h.id === 'door-2-handle').horizontal && up.hardware.find((h) => h.id === 'door-2-handle').pos[1] < d2.box.y + 60, 'קלפה: מנגנון הרמה, וידית אופקית בקצה התחתון');
  check(uh[0].drill.length === uh.length * 3 && uh[0].drill.every((d) => d.part === 'door-2'), 'קלפה: קידוחי כוס וברגים בקצה העליון');
  const down = build('bookcase', { columns: 3, doorType: 'wood', doorOpen: { 'door-3': 'bottom' } });
  const d3 = down.parts.find((p) => p.id === 'door-3');
  check(d3.motion.angle > 0 && Math.abs(d3.motion.pivot[1] - d3.box.y) < 0.01 && down.hardware.some((h) => h.material === 'hw:flap-stay'), 'נפתחת מטה: סביב הקצה התחתון, עם זרועות');
  const asm = placeModel(up, { pos: [0, 0, 0], rot: 90, prefix: 'e1:' });
  check(JSON.stringify(asm.parts.find((p) => p.id === 'e1:door-2').motion.axis) === JSON.stringify([0, 0, -1]), 'בהרכבה מסובבת 90° — ציר הקלפה מסתובב איתה');
  // גימור לכל לוח
  const pf = build('bookcase', { columns: 3, partFinishes: { 'side-R': 'cnc:milled-fine', 'partition-1': 'cnc:milled-frame' } });
  check(pf.parts.find((p) => p.id === 'side-R').mill?.normal === '+x' && pf.parts.find((p) => p.id === 'partition-1').mill?.normal === '-x', 'דופן ימין ומחיצה — חירוץ בפאה החיצונית');
  const dr = build('dresser', { partFinishes: { 'side-L': 'cnc:milled-wide' } });
  check(dr.parts.find((p) => p.id === 'side-L').mill?.normal === '-x' && /חירוץ CNC/.test(dr.parts.find((p) => p.id === 'side-L').note), 'שידה: דופן שמאל מחורצת');
  const fl = build('bookcase', { partFinishes: { 'side-L': 'fluted-fine' } });
  check(fl.parts.filter((p) => p.id.startsWith('side-L-strip')).length > 10 && fl.parts.filter((p) => p.id.startsWith('side-L-strip')).every((p) => p.box.x + p.box.w <= 0.01), 'סטריפים מודבקים על דופן שמאל — מחוץ לדופן');
  const clear = build('bookcase', { doorType: 'wood', doorFinish: 'cnc:milled-wide', partFinishes: { 'door-1': 'flat' } });
  check(!clear.parts.find((p) => p.id === 'door-1').mill && clear.parts.find((p) => p.id === 'door-2').mill, '"חלק" מבטל את הגימור של התבנית רק בלוח הזה');
  check(!build('bookcase', { partFinishes: { 'top': 'cnc:milled-fine' } }).parts.find((p) => p.id === 'top').mill, 'לוח אופקי (גג) — בלי גימור');
  const cl = build('cladding', { style: 'flat', panelFinish: 'cnc:milled-frame-double', walls: 2, turn2: 'left' });
  const pnls = cl.parts.filter((p) => p.name === 'לוח חיפוי');
  check(pnls.length && pnls.every((p) => p.mill) && new Set(pnls.map((p) => p.mill.normal)).size === 2, 'חיפוי: כל הלוחות מחורצים, בפאה שפונה לחדר בכל קיר');
  check(within(cl.parts, cl.bounds), 'חיפוי מחורץ בגבולות');
}

// ---- שיש ואבן ----
{
  console.log('\nשיש ואבן');
  const general = M.materialsOfKind('board', { back: false, solid: false, top: false }).map((m) => m.id);
  const tops = M.materialsOfKind('board', { top: true }).map((m) => m.id);
  check(general.includes('board:marble-carrara-20') && tops.includes('board:marble-carrara-20') && tops.includes('board:countertop-quartz') && !general.includes('board:countertop-quartz'), 'שיש מוצע גם כלוח רגיל וגם כמשטח; משטח קוורץ — רק כמשטח');
  check(M.all().filter((m) => m.finish === 'stone').length >= 6, 'שישה סוגי שיש ואבן');
  check(densityOf(M.material('board:marble-nero-20')) === 2700 && boardWeight(1000, 1000, 20, 'board:marble-nero-20') === 54, 'שיש: 2700 ק"ג/מ"ק — מ"ר ב-20 מ"מ = 54 ק"ג');
  const k = build('kitchen', { topMaterial: 'board:marble-calacatta-20' });
  check(k.values.topMaterial === 'board:marble-calacatta-20' && k.parts.some((p) => /countertop/.test(p.id) && p.material === 'board:marble-calacatta-20' && p.box.h === 20), 'מטבח עם משטח שיש קלקטה 20');
  const c = build('cladding', { slatMaterial: 'board:marble-carrara-20', style: 'flat' });
  check(c.parts.some((p) => p.material === 'board:marble-carrara-20'), 'חיפוי קיר בלוחות שיש');
  const w = build('bookcase', { doorType: 'wood', doorMaterial: 'board:marble-nero-20', doorFinish: 'cnc:milled-wide' });
  check(w.warnings.some((x) => /CNC לאבן/.test(x)) && !w.warnings.some((x) => /חושף את הליבה/.test(x)), 'חירוץ בשיש: אזהרת מכונת אבן (לא "חושף את הליבה")');
}

// ---- פיצול דלת לכל עמודה ----
{
  console.log('\nפיצול דלת לכל עמודה');
  const L = (cols) => ({ sections: { main: { widths: [null, null, null], cols } } });
  const doorsOf = (r, i) => r.parts.filter((p) => new RegExp(`^door-${i}(-|a|b|$)`).test(p.id) && !/strip|glass|frame/.test(p.id) && p.name.startsWith('דלת'));
  // רק עמודה 2 מפוצלת ב-700
  const a = build('bookcase', { columns: 3, doorType: 'wood', columnsLayout: L({ 1: { split: 700 } }) });
  check(doorsOf(a, 2).some((p) => /lo/.test(p.id)) && doorsOf(a, 2).some((p) => /up/.test(p.id)) && !doorsOf(a, 1).some((p) => /lo|up/.test(p.id)) && !doorsOf(a, 3).some((p) => /lo|up/.test(p.id)), 'רק עמודה 2 מפוצלת: דלת עליונה ותחתונה בה, דלת אחת בשאר');
  const lo2 = a.parts.find((p) => /^door-2-lo/.test(p.id));
  check(Math.abs(lo2.box.y + lo2.box.h - (a.values.plinthH + a.values.shelfT + 700)) < 2, `הדלת התחתונה עד גובה הפיצול (${Math.round(lo2.box.y + lo2.box.h)})`);
  check(a.parts.filter((p) => p.id.startsWith('split-')).length === 1, 'מדף קבוע אחד — רק בעמודה המפוצלת');
  // ברירת מחדל מפוצלת, עמודה 3 בלי (0), עמודה 1 בגובה אחר
  const b = build('bookcase', { columns: 3, doorType: 'wood', lowerH: 800, columnsLayout: L({ 0: { split: 500 }, 2: { split: 0 } }) });
  const y = (id) => { const p = b.parts.find((q) => q.id === id); return p && Math.round(p.box.y + p.box.h); };
  check(y('door-1-lo') < y('door-2-lo') && !b.parts.some((p) => /^door-3-(lo|up)/.test(p.id)) && b.parts.filter((p) => p.id.startsWith('split-')).length === 2, 'ברירת מחדל 800, עמודה 1 ב-500, עמודה 3 בלי פיצול');
  // פיצול שלא נכנס בעמודה נמוכה — בוטל עם אזהרה, לעמודה הזו בלבד
  const c = build('bookcase', { columns: 3, height: 2000, doorType: 'wood', lowerH: 1500, columnsLayout: L({ 1: { height: 1200 } }) });
  check(c.warnings.some((w) => /עמודה 2: גובה החלק התחתון 1500/.test(w)) && c.parts.some((p) => /^door-1-lo/.test(p.id)) && !c.parts.some((p) => /^door-2-(lo|up)/.test(p.id)), 'פיצול גבוה מעמודה נמוכה — בוטל רק בה, עם אזהרה');
  check(within(b.parts, b.bounds) && within(c.parts, c.bounds), 'בגבולות');
  check(normalizeLayout({ cols: { 0: { split: 0 } } }, 2).cols[0].split === 0, 'הפריסה שומרת פיצול 0 (בלי פיצול בעמודה)');
}

// ---- חירוץ CNC: לוח אחד מחורץ (דמוי סטריפים, מסגרות) ----
{
  console.log('\nחירוץ CNC');
  const fine = millSpec('cnc:milled-fine');
  const gs = fluteGrooves(400, fine);
  check(gs.length > 15 && gs[0][0] >= fine.margin + fine.rib - 0.01 && 400 - gs[gs.length - 1][1] >= fine.margin + fine.rib - 0.01 && gs.every(([a, b]) => Math.abs(b - a - 6) < 1e-9), `דמוי סטריפים: ${gs.length} חריצים 6, שוליים וצלע בקצוות`);
  const rib = fluteRib(400, fine);
  check(gs.every((g, i) => i === 0 || Math.abs(g[0] - gs[i - 1][1] - rib) < 1e-9) && Math.abs(gs[0][0] - fine.margin - rib) < 1e-9 && Math.abs(400 - gs[gs.length - 1][1] - fine.margin - rib) < 1e-9 && rib >= fine.rib && rib < fine.rib + fine.groove, `כל הצלעות שוות (${rib.toFixed(2)}), גם בקצוות — השארית לא מצטברת בשוליים`);
  const m30 = { ...fine, margin: 30 }, g30 = fluteGrooves(400, m30), r30 = fluteRib(400, m30);
  check(Math.abs(g30[0][0] - 30 - r30) < 1e-9, 'שוליים 30 — בדיוק 30 ואז צלע');
  check(cncPatterns().length === 5 && millSpec('milled-fine').id === 'cnc:milled-fine' && millSpec('flat') === null, 'הדוגמאות בספרייה; שם ישן מתורגם');
  check(build('bookcase', { doorType: 'wood', doorFinish: 'milled-wide' }).values.doorFinish === 'cnc:milled-wide', 'פרויקט שנשמר עם השם הישן — עובר לדוגמה בספרייה');
  M.upsert({ id: 'cnc:test-x', kind: 'cnc', name: 'בדיקה', price: 10, priceUnit: 'm2', mill: { kind: 'flutes', groove: 8, rib: 8, depth: 4, margin: 15 } });
  const tx = build('wardrobe', { doorType: 'wood', doorFinish: 'cnc:test-x' });
  check(tx.values.doorFinish === 'cnc:test-x' && tx.parts.find((p) => p.id === 'door-1a').mill.groove === 8, 'דוגמה חדשה מהספרייה מופיעה בטופס ונבנית');
  M.upsert({ id: 'cnc:test-x', active: false });
  check(build('wardrobe', { doorType: 'wood', doorFinish: 'cnc:test-x' }).values.doorFinish === 'cnc:test-x', 'דוגמה שהושבתה — פרויקט שכבר משתמש בה ממשיך לעבוד');
  M.remove('cnc:test-x');
  const dbl = millSpec('cnc:milled-frame-double');
  const rects = millRects(500, 2000, dbl);
  check(rects.length === 8, 'מסגרת כפולה: שתי טבעות × 4 צלעות');
  check(millRects(500, 2000, millSpec('cnc:milled-frame-2')).length === 16 && millRects(500, 700, millSpec('cnc:milled-frame-2')).length === 8, 'שני פנלים בדלת גבוהה; בחזית נמוכה — פנל אחד');
  check(millRects(150, 140, dbl).length <= 4, 'חזית מגירה קטנה: טבעת שלא נכנסת נשמטת');
  const area = (rs) => rs.reduce((s, r) => s + (r[1] - r[0]) * (r[3] - r[2]), 0);
  check(Math.abs(area(millSolids(500, 2000, rects)) + area(rects) - 500 * 2000) < 1, 'התאים המלאים + החריצים = כל הפאה (אין חפיפה בין מלבני הטבעות)');
  const b = build('bookcase', { doorType: 'wood', doorFinish: 'cnc:milled-frame-double', doorMaterial: 'board:mdf-paint-18' });
  const d1 = b.parts.find((p) => p.id === 'door-1');
  check(d1.mill && d1.mill.pattern === 'cnc:milled-frame-double' && !b.parts.some((p) => /strip/.test(p.id)) && /חירוץ CNC/.test(d1.note), 'דלת מחורצת: חלק אחד, עם הוראה בהערה, בלי סטריפים');
  check(partWeight(d1) < Math.round(d1.box.w * d1.box.h * d1.box.d / 1e9 * 740 * 100) / 100 && millRemoved(d1) > 0, 'המשקל מחסיר את החריצים');
  check(!b.warnings.some((w) => /חירוץ/.test(w)), 'MDF לצבע 18: בלי אזהרת חירוץ');
  const mel = build('bookcase', { doorType: 'wood', doorFinish: 'cnc:milled-fine', doorMaterial: 'board:melamine-oak-18' });
  check(mel.warnings.some((w) => /חושף את הליבה/.test(w)), 'חירוץ במלמין — אזהרה');
  const k = build('kitchen', { frontFinish: 'cnc:milled-frame' });
  check(k.parts.some((p) => /door/.test(p.id) && p.mill) && k.parts.some((p) => /drawer-\d+$/.test(p.id) && p.mill) && within(k.parts, k.bounds), 'מטבח: גימור חזיתות על דלתות ומגירות, בתוך הגבולות');
  check(build('dresser', { frontFinish: 'cnc:milled-wide' }).parts.filter((p) => p.mill).length > 0, 'שידה: חזיתות מחורצות');
  check(/מסגרות/.test(millText(d1)) && /חריץ 6×4/.test(millText(d1)), `הוראה: ${millText(d1)}`);
  // קווי מתאר רק על חומר: שום קו על פני הפאה לא חוצה פתח של חריץ (זה נראה כמו "מכסה שקוף")
  const E = millEdges(400, 2000, fine), G = fluteGrooves(400, fine);
  const crosses = E.filter(([p0, p1]) => p0[2] === 0 && p1[2] === 0 && p0[1] === p1[1] && G.some(([ga, gb]) => Math.min(p0[0], p1[0]) < ga + 0.01 && Math.max(p0[0], p1[0]) > gb - 0.01));
  check(crosses.length === 0, `דמוי סטריפים: אין קו שסוגר את פתח החריץ (${crosses.length})`);
  check(E.filter(([p0, p1]) => p0[1] === 2000 && p1[1] === 2000 && p0[2] !== p1[2]).length === G.length * 2, 'בקצה הפתוח של כל חריץ — צורת U (שתי דפנות ותחתית)');
  const FE = millEdges(500, 2000, dbl);
  check(FE.length === 4 + 2 * 2 * 2 * 4, 'מסגרת כפולה: שפת הפאה + לכל טבעת קו חיצוני ופנימי, בפני הפאה ובתחתית — בלי תפרים');
  const w = build('wardrobe', { doorType: 'wood', sideLeftFinish: 'cnc:milled-wide' });
  check(w.parts.find((p) => p.id === 'side-L').mill?.normal === '-x', 'דופן שמאל מחורצת בפאה החיצונית (-x)');
}

// ---- עמודות ושדות בגבהים שונים ----
{
  console.log('\nגובה לכל עמודה / שדה');
  const ov = (ps) => { const out = []; for (let i = 0; i < ps.length; i++) for (let j = i + 1; j < ps.length; j++) { const a = ps[i].box, b = ps[j].box, e = 0.5; if (a.x + e < b.x + b.w && b.x + e < a.x + a.w && a.y + e < b.y + b.h && b.y + e < a.y + a.h && a.z + e < b.z + b.d && b.z + e < a.z + a.d) out.push(`${ps[i].id}×${ps[j].id}`); } return out; };
  const lay = (hs) => ({ sections: { main: { widths: hs.map(() => null), cols: Object.fromEntries(hs.map((h, i) => [i, h ? { height: h } : null]).filter(([, c]) => c)) } } });
  // ספרייה 3 עמודות: אמצעית 1200 (גובה 2000)
  for (const extra of [{}, { sidesOverTop: 'top' }, { backMode: 'overlay' }, { crownH: 60 }, { doorType: 'wood' }]) {
    const b = build('bookcase', { columns: 3, height: 2000, columnsLayout: lay([null, 1200, null]), ...extra });
    const solid = b.parts.filter((p) => !/חריץ/.test(p.note || '') && p.material && M.material(p.material).kind !== 'glass');
    const o = ov(solid.filter((p) => !p.motion));
    check(o.length === 0, `ספרייה ${JSON.stringify(extra)}: אין חפיפות (${o.slice(0, 3).join('; ')})`);
    check(within(b.parts, b.bounds) && b.bounds.h === 2000, `ספרייה ${JSON.stringify(extra)}: בגבולות, גובה 2000`);
    const tops = b.parts.filter((p) => /^top(-\d+)*$/.test(p.id));
    check(tops.length === 3 && tops.some((p) => Math.abs(p.box.y + p.box.h - (1200 - (extra.crownH || 0))) < 0.01), `ספרייה ${JSON.stringify(extra)}: גג לכל ריצה, האמצעי ב-${1200 - (extra.crownH || 0)}`);
    const p1 = b.parts.find((p) => p.id === 'partition-1');
    check(Math.abs(p1.box.y + p1.box.h - (2000 - (extra.crownH || 0))) < 0.01, 'המחיצה בין עמודה גבוהה לנמוכה עולה עד הגג הגבוה');
    const mid = b.parts.filter((p) => /shelf/.test(p.id) && p.box.x > p1.box.x && p.box.x < b.parts.find((q) => q.id === 'partition-2').box.x);
    check(mid.every((p) => p.box.y + p.box.h <= 1200 - (extra.crownH || 0) - 18 + 0.01), 'מדפי העמודה הנמוכה מתחת לגג שלה');
    if (extra.doorType) { const d2 = b.parts.filter((p) => /^door-2/.test(p.id)); check(d2.length && d2.every((p) => p.box.y + p.box.h <= 1200 + 0.01), 'הדלת של העמודה הנמוכה בגובה שלה'); }
  }
  const eq = build('bookcase', { columns: 3, height: 2000, columnsLayout: lay([2000, 2000, null]) });
  check(eq.parts.filter((p) => /^top(-\d+)*$/.test(p.id)).length === 1, 'גובה שווה לגובה הספרייה — גג אחד, כמו בלי גובה');
  const clampLow = build('bookcase', { columns: 2, height: 2000, columnsLayout: lay([10, null]) });
  check(clampLow.parts.find((p) => p.id === 'side-L').box.h > 100, 'גובה קטן מדי מוצמד למינימום');
  // ארון: עמודת מדפים נמוכה ליד תלייה
  const w = build('wardrobe', { columns: 3, hangingColumns: 2, doorType: 'wood', columnsLayout: lay([null, null, 1600]) });
  check(ov(w.parts.filter((p) => !p.motion && !/חריץ/.test(p.note || ''))).length === 0 && within(w.parts, w.bounds), 'ארון: אין חפיפות, בגבולות');
  check(w.parts.find((p) => p.id === 'side-R').box.y + w.parts.find((p) => p.id === 'side-R').box.h <= 1600 + 0.01 && w.parts.filter((p) => /^door-3/.test(p.id)).every((p) => p.box.y + p.box.h <= 1600 + 0.01), 'ארון: הדופן והדלת של העמודה הנמוכה ב-1600');
  const ws = build('wardrobe', { columns: 3, doorType: 'sliding', columnsLayout: lay([null, null, 1600]) });
  check(ws.warnings.some((x) => /גובה אחיד/.test(x)) && ws.parts.filter((p) => /^top(-\d+)*$/.test(p.id)).length === 1, 'הזזה: גובה אחיד ואזהרה');
  // חיפוי: שדה שני נמוך
  const c = build('cladding', { fields: 3, crownH: 40, columnsLayout: { sections: { wall1: { widths: [null, null, null], cols: { 1: { height: 1200 } } } } } });
  const f2 = c.parts.filter((p) => /^w1-f2-/.test(p.id) && !/batten/.test(p.id));
  check(f2.length && f2.every((p) => p.box.y + p.box.h <= c.values.fromFloor + 1200 + 0.01), 'חיפוי: השדה הנמוך עד הגובה שלו, כולל הקרניז');
  check(c.parts.some((p) => p.id === 'w1-f2-crown') && !c.parts.some((p) => p.id === 'w1-crown') && c.parts.filter((p) => /^w1-f2-batten/.test(p.id)).every((p) => p.box.y + p.box.h <= c.values.fromFloor + 1200 + 0.01), 'חיפוי: לטות וקרניז לכל שדה, בגובה שלו');
  check(normalizeLayout({ cols: { 0: { height: 900 } } }, 2).cols[0].height === 900 && !layoutIsEmpty(normalizeLayout({ cols: { 0: { height: 900 } } }, 2)), 'הפריסה שומרת גובה');
}

// ---- ציר נסתר: הפלטה על הפאה הפנימית של הדופן, לא מחוץ לארון ----
{
  console.log('\nציר נסתר בתוך הארון');
  const conc = { accessory: { type: 'concealed' } };
  for (const [side, reach] of [['left', 4.5], ['right', 4.5], ['left', 13.5]]) {
    const s = side === 'left' ? -1 : 1;
    const a = buildAccessory(conc, { side, reach });
    const fixed = a.prims.filter((pr) => !pr.moving);
    // מקומית: +x לכיוון קצה הציר כש-s=1. הפאה הפנימית ב-s*reach; כל מה שקבוע חייב להיות בצד הפנימי שלה
    const outward = Math.max(...fixed.map((pr) => s * pr.pos[0] + pr.size[0] / 2));
    check(Math.abs(outward - reach) < 0.01, `${side}, reach ${reach}: הפלטה נוגעת בפאה הפנימית ולא חוצה אותה (${outward})`);
    const plate = fixed.find((pr) => pr.finish.id === 'nickel');
    check(plate && plate.pos[2] === -37, 'הפלטה 37 מ"מ מאחורי חזית הדופן — כמו בקידוח');
  }
  const wd = build('wardrobe', { doorType: 'wood', columns: 2 });
  const hg = wd.hardware.filter((h) => h.kind === 'hinge');
  check(hg.length && hg.every((h) => h.mount && wd.parts.some((p) => p.id === h.mount)), 'לכל ציר יש דופן הרכבה קיימת');
  const placed = placeModel(wd, { pos: [0, 0, 0], prefix: 'e1:' });
  check(placed.hardware.filter((h) => h.kind === 'hinge').every((h) => h.mount.startsWith('e1:')) && placed.hardware.flatMap((h) => h.drill || []).every((d) => d.part.startsWith('e1:') && placed.parts.some((p) => p.id === d.part)), 'בהרכבה: הדופן והקידוחים מקבלים את קידומת האלמנט');
}

// ---- הרכבה: גרירה על הרצפה עם הצמדה ----
{
  console.log('\nגרירת אלמנט בהרכבה');
  const bs = [{ x: 0, y: 0, z: 0, w: 600, h: 2000, d: 600 }, { x: 1000, y: 0, z: 0, w: 800, h: 900, d: 560 }];
  check(JSON.stringify(dragSnap(bs, 1, 123, 297)) === '[120,300]', 'בלי קצה קרוב: עיגול ל-10 מ"מ');
  check(dragSnap(bs, 1, -385, 0)[0] === -400, 'קצה שמאל נצמד לקצה ימין של השכן (צמוד)');
  check(dragSnap(bs, 1, -1010, 0)[0] === -1000, 'קצה שמאל מתיישר עם קצה שמאל של השכן');
  check(dragSnap(bs, 1, 0, 30)[1] === 40, 'Z: חזית מתיישרת עם חזית השכן (600−560)');
  check(dragSnap(bs, 1, -384, 0, { skip: [0] })[0] === -380, 'אלמנט מוסתר לא מושך');
  check(dragSnap(bs, 1, -300, 0)[0] === -300, 'רחוק מכל קצה — בלי הצמדה');
  // בגובה: bs[0] גבוה 2000, bs[1] גבוה 900
  check(dragSnapY(bs, 1, 1234) === 1230, 'גובה: עיגול ל-10 מ"מ');
  check(dragSnapY(bs, 1, 1985) === 2000, 'גובה: התחתית נצמדת לגג של השכן (עליו)');
  check(dragSnapY(bs, 1, 1080) === 1100, 'גובה: הגג מתיישר עם גג השכן (2000 − 900)');
  check(dragSnapY(bs, 1, -500) === 0 && dragSnapY([{ ...bs[0] }, { ...bs[1], y: 300 }], 1, -280) === -300, 'גובה: לא מתחת לרצפה, ונצמד אליה');
  check(dragSnapY(bs, 1, 1985, { skip: [0] }) === 1990, 'גובה: אלמנט מוסתר לא מושך');
}

// ---- פיזיקה: משקלים, צירים לפי גובה ומשקל, מערכות הזזה, קידוחים ----
{
  console.log('\nפיזיקה ופרזול');
  check(densityOf(M.material('glass:clear-4')) === 2500 && densityOf(M.material('board:melamine-white-18')) === 680 && densityOf(M.material('board:mdf-paint-18')) === 740, 'צפיפויות מהספרייה');
  // לוח 1000×1000×18 מלמין 680 → 12.24 ק"ג
  check(boardWeight(1000, 1000, 18, 'board:melamine-white-18') === 12.24, `משקל לוח מ"ר (${boardWeight(1000, 1000, 18, 'board:melamine-white-18')})`);
  M.upsert({ id: 'board:melamine-white-18', density: 1000 });
  check(boardWeight(1000, 1000, 18, 'board:melamine-white-18') === 18, 'צפיפות שהוגדרה בספרייה גוברת');
  M.upsert({ id: 'board:melamine-white-18', density: 0 });
  // צירים: 2 עד 900, 3 עד 1600, 4 עד 2100, 5 עד 2400, 6 מעל; ולפי משקל — הגבוה
  check(hingeCount(700, 3) === 2 && hingeCount(1500, 5) === 3 && hingeCount(2000, 10) === 4 && hingeCount(2300, 10) === 5 && hingeCount(2600, 10) === 6, 'צירים לפי גובה');
  check(hingeCount(700, 7) === 3 && hingeCount(700, 14) === 4 && hingeCount(700, 19) === 5 && hingeCount(700, 30) === 6, 'צירים לפי משקל גוברים על הגובה');
  check(JSON.stringify(hingeYs(1000, 3)) === '[100,500,900]' && hingeYs(300, 2)[0] === 75, 'גבהי צירים: 100 מהקצוות, שווה באמצע; דלת נמוכה — רבע');
  const hd = hingeDrilling({ doorId: 'd', mountId: 'm', ys: [100, 900], doorBottomOffset: 50 });
  const cups = hd.filter((h) => h.purpose === 'כוס ציר');
  check(cups.length === 2 && cups[0].dia === 35 && cups[0].depth === 13 && cups[0].x === 22.5 && cups[0].face === 'back', 'כוס Ø35 עומק 13, מרכז 22.5 מקצה הציר');
  check(hd.filter((h) => h.part === 'd' && h.dia === 2.5).length === 4 && hd.filter((h) => h.part === 'm').length === 4 && hd.find((h) => h.part === 'm').x === 37 && hd.filter((h) => h.part === 'm').map((h) => h.y).join() === '134,166,934,966', 'ברגי ציר ופלטות בדופן: 37 מהחזית, 32 ביניהם, מוזזות בגובה הדלת');
  // דלת ארון: צירים אמיתיים עם קידוח בדלת ובדופן
  const wd = build('wardrobe', { doorType: 'wood', columns: 2 });
  const door1 = wd.parts.find((p) => p.id === 'door-1a');
  const hinges1 = wd.hardware.filter((h) => h.kind === 'hinge' && h.for === 'door-1a');
  const info1 = wd.hardware.find((h) => h.kind === 'info' && h.door === 'door-1a');
  check(info1 && info1.kg > 5 && hinges1.length === info1.hinges && hinges1.length === hingeCount(door1.box.h, info1.kg), `דלת ארון: ${hinges1.length} צירים לפי ${door1.box.h} מ"מ ו-${info1 && info1.kg} ק"ג`);
  check(hinges1.every((h) => Math.abs(h.pos[0] - (door1.box.x + 22.5)) < 0.01) && hinges1[0].pos[1] === door1.box.y + 100 && hinges1[hinges1.length - 1].pos[1] === door1.box.y + door1.box.h - 100, 'הצירים במרכז הכוס, 100 מהקצוות');
  const dl = drillingList(wd);
  const doorHoles = dl.find((g) => g.id === 'door-1a'), sideHoles = dl.find((g) => g.id === 'side-L');
  check(doorHoles && doorHoles.holes.length === hinges1.length * 3 && sideHoles && sideHoles.holes.length === hinges1.length * 2, 'רשימת הקידוחים: 3 לכל ציר בדלת, 2 בדופן');
  check(sideHoles.holes.every((h) => h.y >= 0 && h.y <= wd.parts.find((p) => p.id === 'side-L').box.h), 'קידוחי הדופן בתוך גובה הדופן');
  check(hardwareList(wd).every((r) => r.kind !== 'info') && !estimate(wd, {}).lines.some((l) => /ק"ג/.test(l.name)), 'רשומות המידע אינן פרזול ברשימות ובמחיר');
  // דלת הזזה: יושבת על המסילה, לא מרחפת
  for (const key of Object.keys(SLIDING_SYSTEMS)) {
    const sys = key === 'top-hung' ? 'hw:track-sliding' : 'hw:track-bottom';
    const w = build('wardrobe', { doorType: 'sliding', slidingLeaves: 2, slidingSystem: sys });
    const leaves = w.parts.filter((p) => /^sliding-\d$/.test(p.id));
    const lf = slidingLeaf(key, w.values.plinthH, w.parts.find((p) => p.id === 'top').box.y + w.parts.find((p) => p.id === 'top').box.h);
    const s = SLIDING_SYSTEMS[key];
    check(leaves.length === 2 && leaves.every((p) => p.box.y === lf.bottom && p.box.h === lf.h) && lf.h < lf.openH, `${key}: גובה הכנף ${lf.h} מתוך פתח ${lf.openH}`);
    const expectBottom = key === 'top-hung' ? w.values.plinthH + s.guideH + s.bottomClear : w.values.plinthH + s.trackH + s.rollerLift;
    check(leaves[0].box.y === expectBottom, `${key}: תחתית הכנף ${leaves[0].box.y} = סוקל + פרופיל`);
    const tracks = w.hardware.filter((h) => h.kind === 'track'), carriers = w.hardware.filter((h) => h.kind === 'carrier');
    check(tracks.length === 2 && tracks.every((t) => t.len === w.values.width && t.pos) && carriers.length === 4 && carriers.every((c) => c.drill.length === 1 && c.for), `${key}: 2 פרופילים לרוחב, 4 גררות עם קידוח`);
    const track = tracks.find((t) => t.id === 'sliding-track');
    check(key === 'top-hung' ? track.pos[1] === leaves[0].box.y + leaves[0].box.h + s.topClear : track.pos[1] + s.trackH + s.rollerLift === leaves[0].box.y, `${key}: הכנף צמודה לפרופיל (מרווח ${s.topClear || s.rollerLift})`);
    check(Math.abs(leaves[1].box.z - leaves[0].box.z - (leaves[0].box.d + s.laneGap)) < 0.01 && leaves[0].box.z === w.parts.find((p) => p.id === 'side-L').box.z + w.parts.find((p) => p.id === 'side-L').box.d, `${key}: נתיב פנימי צמוד לגוף, חיצוני לפניו במרווח ${s.laneGap}`);
    check(within(w.parts, w.bounds) && w.bounds.d === w.values.depth + 2 * leaves[0].box.d + s.laneGap, `${key}: הגבולות כוללים את שני הנתיבים`);
    check(w.hardware.some((h) => h.kind === 'info' && Number.isFinite(h.maxKg) && h.maxKg === s.maxKgPerLeaf), `${key}: משקל הכנף מול ${s.maxKgPerLeaf} ק"ג`);
  }
  const heavy = build('wardrobe', { doorType: 'sliding', slidingLeaves: 2, width: 3000, height: 2400, doorMaterial: 'board:mdf-paint-22' });
  check(heavy.warnings.some((w) => /מעל 40 ק"ג/.test(w)), `כנף MDF 22 ברוחב 1500 — אזהרת עומס (${heavy.warnings.find((w) => /מעל 40/.test(w)) || 'אין'})`);
  // מגירות: עומס המסילה מול משקל + תכולה, וקידוחי סיסטם 32 בדפנות
  check(slideLoad('hw:slide-std') === 25 && slideLoad('hw:slide-tandem') === 40 && slideLoad('hw:slide-heavy') === 70, 'עומסי מסילות');
  const dr = build('dresser', {});
  const drHoles = drillingList(dr).find((g) => g.id === 'side-L');
  check(drHoles && drHoles.holes.every((h) => h.dia === 5 && h.purpose === 'מסילת מגירה') && drHoles.holes.some((h) => h.x === 37) && drHoles.holes.some((h) => h.x === 261), 'מסילות: קידוחים 37 ו-261 מהחזית בדופן');
  check(!dr.warnings.some((w) => /עומס המסילה/.test(w)), 'שידה רגילה בלי אזהרת עומס');
  check(physicsWarnings([{ kind: 'info', door: 'x', kg: 12, load: 25 }]).length === 1 && physicsWarnings([{ kind: 'info', door: 'x', kg: 9, load: 25 }]).length === 0 && physicsWarnings([{ kind: 'info', door: 'd', kg: 23, hinges: 6 }]).length === 1, 'אזהרות: מגירה מעל העומס, דלת מעל 22 ק"ג');
  check(totalWeight(dr.parts) > 20 && Math.abs(totalWeight(dr.parts) - dr.parts.reduce((s, p) => s + partWeight(p), 0)) < 0.1, `שידה: ${totalWeight(dr.parts)} ק"ג`);
  // STL: 12 משולשים לחלק, קנה מידה, עיבוי למינימום
  const bc = build('bookcase', {});
  const stl = toSTL(bc.parts, { scale: 20, minT: 1 });
  check(stl.triangles === bc.parts.length * 12 && stl.buffer.byteLength === 84 + stl.triangles * 50 && new DataView(stl.buffer).getUint32(80, true) === stl.triangles, 'STL בינארי: 12 משולשים לחלק, כותרת נכונה');
  check(stl.thickened > 0 && toSTL(bc.parts, { scale: 10, minT: 1 }).thickened < stl.thickened, 'לוח 18 ב-1:20 (0.9) מעובה ל-1; ב-1:10 פחות עיבויים');
  const ps = printSize(bc.bounds, 20);
  check(ps.w === Math.round(bc.bounds.w / 20 * 10) / 10 && ps.h === Math.round(bc.bounds.h / 20 * 10) / 10, 'מידות הדפסה');
  const first = new Float32Array(toSTL(bc.parts, { scale: 10, minT: 1 }).buffer.slice(84 + 12, 84 + 12 + 36));
  check(Math.abs(first[0] - bc.parts[0].box.x / 10) < 1e-4 && Math.abs(first[1] - bc.parts[0].box.y / 10) < 1e-4, 'קואורדינטות מחולקות בקנה המידה');
}

if (failed) { console.error(`\n${failed} בדיקות נכשלו`); process.exit(1); }
console.log('\nהכול עבר ✓');
