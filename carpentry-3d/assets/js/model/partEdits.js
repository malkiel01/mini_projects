// עריכה של רכיב בודד מתוך התלת מימד.
//
// הנגר מקיש על רכיב ומשנה רק אותו: חומר, מידות (אורך/רוחב/עובי כמו ברשימת
// החיתוך), הזזה, סיבוב, קנטים, זווית פתיחה של דלת / מרחק שליפה של מגירה,
// או הסתרה. זה נשמר בערכי הפרויקט — values.partEdits = { partId: {...} } —
// ומופעל אחרי שהתבנית בנתה את המוצר, כך שכל שאר המוצר ממשיך לנבוע
// מהפרמטרים. ה-id של רכיב יציב כל עוד מבנה המוצר לא משתנה (אותו מספר
// עמודות/שדות); עריכה של id שכבר לא קיים פשוט לא מופעלת.
//
// בנוסף, relatedParams מחזיר את הגדרות התבנית שנוגעות לרכיב — כדי שאפשר
// יהיה לשנות מהכרטיס שלו גם את מה שמשותף (דוגמת החיפוי בשדה, גובה השדה,
// זווית הפינה, חומר הגוף…). תבנית יכולה להגדיר partParams(part, values)
// משלה; אחרת — ניחוש לפי שם הרכיב וחומרו.

import { allParams } from './index.js';
import { compose, aroundXf, applyXf } from './xform.js';

const AX = ['x', 'y', 'z'], SIZE = { x: 'w', y: 'h', z: 'd' };

/** הצירים של מידות החיתוך: l בכיוון הסיבים, w בציר השלישי, t בציר העובי (כמו cutSize). */
export function cutAxes(p) {
  const other = AX.filter((a) => a !== p.axis);
  const dims = { x: p.box.w, y: p.box.h, z: p.box.d };
  const l = other.includes(p.grain) ? p.grain : (dims[other[0]] >= dims[other[1]] ? other[0] : other[1]);
  return { l, w: other.find((a) => a !== l), t: p.axis };
}

/** הקנטים שרלוונטיים ללוח לפי ציר העובי — ארבע השפות שלו. */
export function edgeSides(p) {
  if (p.axis === 'z') return ['top', 'bottom', 'left', 'right'];
  if (p.axis === 'x') return ['top', 'bottom', 'front', 'back'];
  return ['front', 'back', 'left', 'right'];
}

/**
 * מפעיל את העריכות על תוצאת build (במקום). סדר: מידות → הזזה → סיבוב, ואחר כך
 * חומר, קנטים ותנועה. מה שמחובר לרכיב (צירים, ידיות, קידוחים, ציר הסיבוב של
 * הדלת) זז איתו.
 */
export function applyPartEdits(out, edits) {
  if (!edits || typeof edits !== 'object') return;
  for (const [id, e] of Object.entries(edits)) {
    if (!e || typeof e !== 'object') continue;
    // ידית: נשמרת לפי הדלת/המגירה (קבוצת התנועה), כי דלת מסגרת בנויה מכמה חלקים
    if (e.handle) placeHandle(out, id, e.handle);
    const i = out.parts.findIndex((p) => p.id === id);
    if (i < 0) continue;
    if (e.hidden) {
      out.parts.splice(i, 1);
      out.hardware = (out.hardware || []).filter((h) => h.for !== id).map((h) => (h.drill ? { ...h, drill: h.drill.filter((d) => d.part !== id) } : h));
      continue;
    }
    let p = { ...out.parts[i], box: { ...out.parts[i].box } };
    const own = (out.hardware || []).filter((h) => h.for === id && h.pos);
    let motion = p.motion ? { ...p.motion, ...(p.motion.pivot ? { pivot: [...p.motion.pivot] } : {}) } : null;
    const shiftAttached = (ax, from, delta) => {
      // ציר דלת / פרזול שבחצי הרחוק של הרכיב — זז עם הקצה שהתארך
      const k = AX.indexOf(ax);
      if (motion?.pivot && motion.pivot[k] > from) motion.pivot[k] += delta;
      for (const h of own) if (h.pos[k] > from) h.pos = h.pos.map((v, j) => (j === k ? v + delta : v));
    };
    // מידות: אורך / רוחב / עובי
    if (e.size) {
      const axes = cutAxes(p);
      for (const key of ['l', 'w', 't']) {
        const v = Number(e.size[key]);
        if (!Number.isFinite(v) || v <= 0) continue;
        const ax = axes[key], s = SIZE[ax], delta = v - p.box[s];
        if (Math.abs(delta) < 1e-9) continue;
        shiftAttached(ax, p.box[ax] + p.box[s] / 2, delta);
        p.box[s] = v;
      }
    }
    // הזזה
    // הזזה. בדלת/מגירה שבנויה מכמה חלקים (זקפים, קושרות, זכוכית) — כל הדלת זזה יחד,
    // עם הצירים, הידית וציר הסיבוב: הזזת זקף אחד לבד פירקה את הדלת והזיזה את ציר
    // הסיבוב של כולה
    if (e.move) {
      const g = p.motion?.group;
      const mates = g ? out.parts.map((q, j) => [q, j]).filter(([q, j]) => j !== i && q.motion?.group === g) : [];
      const groupHw = g ? (out.hardware || []).filter((h) => h.for === g && h.for !== id && h.pos) : [];
      AX.forEach((ax, k) => {
        const d = Number(e.move[ax]) || 0;
        if (!d) return;
        p.box[ax] += d;
        if (motion?.pivot) motion.pivot[k] += d;
        for (const h of [...own, ...groupHw]) h.pos = h.pos.map((v, j) => (j === k ? v + d : v));
        for (const [, j] of mates) {
          const q = out.parts[j];
          out.parts[j] = { ...q, box: { ...q.box, [ax]: q.box[ax] + d }, ...(q.motion?.pivot ? { motion: { ...q.motion, pivot: q.motion.pivot.map((v, n) => (n === k ? v + d : v)) } } : {}) };
        }
      });
    }
    // סיבוב סביב מרכז הרכיב (סביב הציר האנכי)
    const yaw = Number(e.yaw) || 0;
    if (yaw) {
      const c = applyXf(p.xf, [p.box.x + p.box.w / 2, 0, p.box.z + p.box.d / 2]);
      const before = p.xf || { yaw: 0, x: 0, z: 0 };
      p.xf = compose(aroundXf(yaw, c[0], c[2]), before);
      // הפרזול של הרכיב באותה מערכת מקומית: הצופה מציב אותו דרך ה-xf
      for (const h of own) h.xf = p.xf;
    }
    if (e.material) p.material = e.material;
    if (e.edges && typeof e.edges === 'object') p.edges = { ...(p.edges || {}), ...e.edges };
    if (motion && motion.kind === 'hinge' && Number.isFinite(Number(e.open)) && Number(e.open) > 0) {
      motion.angle = Math.sign(motion.angle || 1) * Math.min(180, Number(e.open));
    }
    if (motion && motion.kind === 'slide' && Number.isFinite(Number(e.travel)) && Number(e.travel) > 0) {
      const len = Math.hypot(...motion.vec) || 1;
      motion.vec = motion.vec.map((v) => (v / len) * Number(e.travel));
    }
    // תנועה: כל החלקים של אותה דלת/מגירה (אותה קבוצה) — זווית/שליפה משותפת
    if (motion) {
      const g = p.motion.group;
      p.motion = motion;
      out.parts = out.parts.map((q, j) => (j !== i && q.motion?.group === g ? { ...q, motion: { ...q.motion, angle: motion.angle ?? q.motion.angle, vec: motion.vec ?? q.motion.vec } } : q));
    }
    p.edited = true;
    out.parts[i] = p;
  }
}

/**
 * מיקום הידית של דלת/מגירה `group`: y — גובה מהרצפה (מוצמד לתוך הדלת), x — 'edge'
 * (ליד הקצה הפתוח, ברירת המחדל) או 'center', orient — 'v' / 'h'.
 */
export function placeHandle(out, group, hd) {
  const parts = out.parts.filter((p) => p.motion?.group === group || p.id === group);
  const handles = (out.hardware || []).filter((h) => h.kind === 'handle' && h.pos && h.for === group);
  if (!parts.length || !handles.length) return;
  let x0 = Infinity, y0 = Infinity, z0 = Infinity, x1 = -Infinity, y1 = -Infinity, z1 = -Infinity;
  for (const { box: b } of parts) { x0 = Math.min(x0, b.x); y0 = Math.min(y0, b.y); z0 = Math.min(z0, b.z); x1 = Math.max(x1, b.x + b.w); y1 = Math.max(y1, b.y + b.h); z1 = Math.max(z1, b.z + b.d); }
  const alongX = x1 - x0 >= z1 - z0;   // דלת בקיר השני של מטבח ר' — לאורך z
  for (const h of handles) {
    const pos = [...h.pos];
    const y = Number(hd.y);
    if (Number.isFinite(y) && y > 0) pos[1] = Math.min(y1 - 30, Math.max(y0 + 30, y));
    if (hd.x === 'center') { if (alongX) pos[0] = (x0 + x1) / 2; else pos[2] = (z0 + z1) / 2; }
    h.pos = pos;
    if (hd.orient === 'h') h.horizontal = true; else if (hd.orient === 'v') h.horizontal = false;
  }
}

/** תיאור קצר של מה שנערך ברכיב — לכרטיס ולהערה. */
export function editSummary(e) {
  if (!e) return '';
  const out = [];
  if (e.material) out.push('חומר');
  if (e.size) out.push('מידות');
  if (e.move && Object.values(e.move).some(Number)) out.push('מיקום');
  if (Number(e.yaw)) out.push(`סיבוב ${e.yaw}°`);
  if (e.edges) out.push('קנטים');
  if (e.open) out.push(`פתיחה ${e.open}°`);
  if (e.travel) out.push(`שליפה ${e.travel}`);
  if (e.handle) out.push('ידית');
  return out.join(', ');
}

// ---- ההגדרות של התבנית שנוגעות לרכיב ----

/** ערך מתוך עורך החלוקה (columnsLayout): sections[key].cols[col][prop]. */
export function getLayoutProp(raw, key, col, prop) {
  return raw?.sections?.[key]?.cols?.[col]?.[prop];
}
/** עותק של columnsLayout עם ערך אחד ששונה (null/'' — מוחק, וחוזרים לברירת המחדל). */
export function setLayoutProp(raw, key, col, prop, value) {
  const base = raw && typeof raw === 'object' && raw.sections ? raw : { sections: {} };
  const sec = { ...(base.sections[key] || {}) };
  const cols = { ...(sec.cols || {}) };
  const c = { ...(cols[col] || {}) };
  if (value === null || value === undefined || value === '') delete c[prop]; else c[prop] = value;
  if (Object.keys(c).length) cols[col] = c; else delete cols[col];
  sec.cols = cols;
  return { ...base, sections: { ...base.sections, [key]: sec } };
}

/** מילות מפתח בשם הרכיב → מילות מפתח בשם הפרמטר. */
const TOKENS = [
  [/side-L/, ['sideLeft', 'sideT']],
  [/side-R/, ['sideRight', 'sideT']],
  [/partition/, ['sideT', 'columns']],
  [/top-shelf|shelf/, ['shelf', 'shelves', 'topShelf']],
  [/top-plate|counter|^top$|-top$/, ['top', 'sidesOverTop']],
  [/bottom/, ['shelfT', 'plinth']],
  [/back/, ['back']],
  [/plinth/, ['plinth', 'wheels']],
  [/crown/, ['crown']],
  [/door/, ['door', 'hinge', 'handle', 'glass', 'sliding', 'lower']],
  [/drawer/, ['drawer', 'slide', 'handle', 'front']],
  [/leg/, ['leg', 'wheels']],
  [/apron/, ['apron', 'stretcher']],
  [/rail|ledger|beam|headboard/, ['frame', 'headboard']],
  [/slat/, ['slat']],
  [/rod/, ['rod', 'hanging']],
];
const DIMS = ['width', 'height', 'depth', 'length'];

/**
 * ההגדרות של התבנית שנוגעות לרכיב: מפתחות פרמטרים, ו/או קישורים לעורך
 * החלוקה ({ layout, col, prop, label, type, options }).
 */
export function relatedParams(t, p, values) {
  if (t.partParams) {
    const own = t.partParams(p, values);
    if (own) return own;
  }
  const params = allParams(t).filter((q) => q.type !== 'json');
  const keys = new Set();
  // הפרמטר שקובע את החומר של הרכיב (חומר הגוף, המדפים, החזיתות…)
  for (const q of params) if (q.type === 'material' && values[q.key] === p.material) keys.add(q.key);
  for (const [re, words] of TOKENS) {
    if (!re.test(p.id)) continue;
    for (const q of params) if (words.some((w) => q.key.toLowerCase().startsWith(w.toLowerCase()) || q.key.toLowerCase().includes(w.toLowerCase()))) keys.add(q.key);
  }
  for (const q of params) if (DIMS.includes(q.key)) keys.add(q.key);
  return [...keys];
}
