// המלצות (docs/elements-spec.md, שלב 4): המערכת ממליצה, לא כופה.
//
// שלושה סוגים:
//   copy    — אחרי שינוי בעמודה: "להחיל גם על…" (כולן / עמודה מסוימת / הקיצוניות /
//             לסירוגין). ההחלה היא העתקה חד-פעמית — אין קישור.
//   uniform — אחידות: עמודות בגבהים שונים → להשוות.
//   fix     — מקצועית: שילוב בעייתי, עם תיקון בלחיצה (זכוכית דקה בלי מסגרת וכו').
// "לא, תודה" נשמר ב-values.dismissed לפי המפתח, וההמלצה לא חוזרת.
//
// הכול על values.units (מערך העמודות). JS טהור — נבדק ב-model-check.

import { material } from './materials.js';
import { LIMITS } from './templates/common.js';

const clone = (x) => (x === undefined ? undefined : JSON.parse(JSON.stringify(x)));
const get = (o, path) => path.split('.').reduce((a, k) => (a == null ? undefined : a[k]), o);
function set(o, path, v) {
  const ks = path.split('.');
  let a = o;
  for (const k of ks.slice(0, -1)) a = (a[k] = a[k] && typeof a[k] === 'object' ? a[k] : {});
  a[ks[ks.length - 1]] = v;
}
const same = (a, b) => JSON.stringify(a ?? null) === JSON.stringify(b ?? null);

/**
 * מה מעתיקים אחרי שינוי: לפי הנתיב שהשתנה בתוך העמודה. שינוי בוויטרינה → כל
 * הוויטרינה של הקומה; בחזית → כל החזית; בתוכן → כל התוכן; במבנה הקומות (הוספה,
 * מחיקה, הזזה, גובה קומה) → כל הקומות; גובה העמודה → הגובה.
 */
function scopeOf(path) {
  let m = /^floors\.(\d+)\.front\.vitrine\./.exec(path);
  if (m) return { path: `floors.${m[1]}.front.vitrine`, floor: +m[1], label: 'הוויטרינה' };
  m = /^floors\.(\d+)\.front\./.exec(path);
  if (m) return { path: `floors.${m[1]}.front`, floor: +m[1], label: 'החזית' };
  m = /^floors\.(\d+)\.interior\./.exec(path);
  if (m) return { path: `floors.${m[1]}.interior`, floor: +m[1], label: 'התוכן' };
  if (/^floors(\.\d+\.height)?$/.test(path)) return { path: 'floors', floor: null, label: 'מבנה הקומות (קומות, חזיתות ותוכן)' };
  if (path === 'height') return { path: 'height', floor: null, label: 'הגובה' };
  m = /^floors\.(\d+)\.led$/.exec(path);
  if (m) return { path: `floors.${m[1]}.led`, floor: +m[1], label: 'התאורה' };
  if (/^back(\.|$)/.test(path)) return { path: 'back', floor: null, label: 'הגב' };
  if (path === 'crownH') return { path: 'crownH', floor: null, label: 'הכרכוב' };
  if (/^partitionR(\.|$)/.test(path)) return { path: 'partitionR', floor: null, label: 'המחיצה (מימין)' };
  return null;
}

/** קבוצות יעד מוכנות: כולן, הקיצוניות, לסירוגין (כמו העמודה), וכל עמודה בנפרד. */
function targetGroups(from, members, differs, short) {
  // members — העמודות שבאותה קבוצה (במטבח: אותה שורת ארונות), לפי הסדר
  const n = members.length, pos = (i) => members.indexOf(i);
  const all = differs;
  const ends = [members[0], members[n - 1]].filter((i) => i !== from && differs.includes(i));
  const alt = differs.filter((i) => (pos(i) - pos(from)) % 2 === 0);
  const out = [];
  if (all.length > 1) out.push({ id: 'all', label: 'כולן', units: all });
  if (n > 3 && ends.length && ends.length < all.length) out.push({ id: 'ends', label: 'הקיצוניות', units: ends });
  if (n > 3 && alt.length && alt.length < all.length) out.push({ id: 'alt', label: 'לסירוגין', units: alt });
  for (const i of differs) out.push({ id: String(i), label: short(i), units: [i] });
  return out;
}

/**
 * ההמלצות למצב הנוכחי. `change` — { unit, path } (נתיב בתוך העמודה) של השינוי
 * האחרון, אם יש; `geo` — model.unitGeo (רוחבים); `dismissed` — מפתחות שנדחו.
 */
export function suggest(units, { change = null, geo = [], dismissed = [], label = (i) => `עמודה ${i + 1}`, group = null } = {}) {
  if (!Array.isArray(units) || !units.length) return [];
  const out = [];
  const no = new Set(dismissed || []);
  const n = units.length;
  const short = (i) => label(i).replace(/^(עמודה|ארון) /, '');
  // copy — אחרי שינוי
  if (change && n > 1 && units[change.unit]) {
    const sc = scopeOf(change.path);
    if (sc) {
      const src = get(units[change.unit], sc.path);
      const members = units.map((u, i) => i).filter((i) => !group || group(units[i]) === group(units[change.unit]));
      const differs = members.filter((i) => i !== change.unit
        && (sc.floor === null || units[i].floors.length > sc.floor)
        && !same(get(units[i], sc.path), src));
      const key = `copy:${change.unit}:${sc.path}`;
      if (differs.length && !no.has(key)) {
        const where = sc.floor === null || units[change.unit].floors.length === 1 ? '' : ` (קומה ${sc.floor + 1})`;
        out.push({ kind: 'copy', key, from: change.unit, path: sc.path, text: `להחיל את ${sc.label}${where} של ${label(change.unit)} גם על:`, targets: targetGroups(change.unit, members, differs, short) });
      }
    }
  }
  // uniform — עמודות בגבהים שונים
  const hs = units.map((u) => (Number.isFinite(u.height) && u.height > 0 ? u.height : null));
  if (new Set(hs.map(String)).size > 1 && !no.has('uniform:height')) {
    out.push({ kind: 'uniform', key: 'uniform:height', text: `העמודות בגבהים שונים (${hs.map((h) => h ?? 'מלא').join(' · ')}) — להשוות לגובה המלא?`, apply: hs.map((h, i) => (h === null ? null : { unit: i, path: 'height', value: null })).filter(Boolean) });
  }
  // fix — מקצועיות
  units.forEach((u, i) => u.floors.forEach((f, j) => {
    const fr = f.front || {}, at = `floors.${j}.front`, where = `${label(i)}${u.floors.length > 1 ? `, קומה ${j + 1}` : ''}`;
    const add = (id, text, path, value) => { const key = `fix:${i}:${j}:${id}`; if (!no.has(key)) out.push({ kind: 'fix', key, unit: i, floor: j, path, value, text: `${where}: ${text}` }); };
    if (fr.type === 'glass') {
      const vt = fr.vitrine || {};
      if (vt.frame === 'none' && (material(fr.glass).t || 4) < 6) add('tempered', `זכוכית ${material(fr.glass).t || 4} מ"מ בלי מסגרת — כדאי זכוכית מחוסמת 6`, `${at}.glass`, 'glass:tempered-6');
      if (vt.frame === 'alu' && Number(vt.frameW) > 40) add('alu-w', `פרופיל אלומיניום ברוחב ${vt.frameW} — בדרך כלל 20 מ"מ`, `${at}.vitrine.frameW`, 20);
    }
    if ((fr.type === 'wood' || fr.type === 'glass') && Number(fr.leaves) === 1 && geo[i] && geo[i].width > LIMITS.doorWidth) add('leaves', `דלת אחת ברוחב ${Math.round(geo[i].width)} — כדאי שתי כנפיים`, `${at}.leaves`, 'auto');
    const dr = f.interior?.type === 'drawers' ? f.interior.drawers || {} : null;
    if (dr && geo[i]?.floors?.[j] && geo[i].floors[j] / (dr.count || 3) < 90) add('drawers', `${dr.count} מגירות בגובה ${Math.round(geo[i].floors[j])} — נמוכות מ-90 כל אחת`, `floors.${j}.interior.drawers.count`, Math.max(1, Math.floor(geo[i].floors[j] / 120)));
  }));
  return out;
}

/** מחיל המלצה. copy — על קבוצת יעד (target.units); uniform/fix — כמו שהיא. מחזיר units חדשים. */
export function applySuggestion(units, s, target = null) {
  const next = clone(units);
  if (s.kind === 'copy') {
    const src = get(units[s.from], s.path);
    for (const i of target?.units || []) if (next[i]) set(next[i], s.path, clone(src));
  } else if (s.kind === 'uniform') {
    for (const a of s.apply) set(next[a.unit], a.path, a.value);
  } else if (s.kind === 'fix') {
    set(next[s.unit], s.path, s.value);
  }
  return next;
}
