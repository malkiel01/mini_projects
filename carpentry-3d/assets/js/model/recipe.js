// מתכונים: מוצר שמוגדר בנתונים (במעבדת המוצרים), לא בקוד.
//
// מתכון = פרמטרים (מה שהנגר ממלא) + משתנים מחושבים + רכיבים + פרזול + אזהרות.
// כל מידה ומיקום הם נוסחה (expr.js). רכיב יכול לחזור בלולאה ("לכל i מ-0 עד
// cols-1") ולהופיע רק בתנאי. דלת היא רכיב מיוחד — נבנית ב-door() של הקוד, עם
// צירים, ידית ותנועת פתיחה, בדיוק כמו בתבניות הכתובות.
//
//   { key: 'r-…', name, description, laborHours,
//     params:     [{ key, label, type: 'mm'|'int'|'enum'|'material', default, min, max, group, hint,
//                    options: [{ id, name }] (enum), use: 'board'|'solid'|'back'|'edge'|'glass'|'handle'|'hinge' (material),
//                    when: נוסחה — מתי השדה מוצג }],
//     vars:       [{ name, expr }],                         — לפי הסדר; כל אחד רואה את הקודמים
//     components: [{ id, name, kind: 'board'|'door', material: מפתח פרמטר או מזהה חומר,
//                    x, y, z, w, h, d: נוסחאות, grain: 'auto'|'x'|'y'|'z', edges: 'front'|'all'|'none',
//                    repeat: נוסחת כמות, index: שם המונה (i), when: נוסחת תנאי,
//                    side: נוסחה ('left'/'right'/'top'/'bottom') , handle, hinge: מפתחות פרמטר — לדלת }],
//     hardware:   [{ name, material, qty, when }],
//     warnings:   [{ when, text }] }                        — text עם {נוסחה}
//
// recipeTemplate הופך מתכון לתבנית רגילה ({ key, params, build }) — כך הטופס,
// התלת מימד, רשימת החיתוך, המחיר והשיתוף עובדים עליו בלי לדעת שהוא מתכון.
// משתנים אוטומטיים: לכל פרמטר חומר — <מפתח>_t, עובי החומר שנבחר.

import { TEMPLATES } from './index.js';
import { part, door } from './blocks.js';
import { material } from './materials.js';
import { evaluate, evalNum, evalBool, interpolate, compile, ExprError } from './expr.js';

export const RECIPE_PREFIX = 'r-';
const MAX_REPEAT = 200;

/** סוגי חומר לפרמטר — מה שהטופס יציע. */
export const MATERIAL_USES = {
  board: { name: 'לוח', p: { kind: 'board', back: false } },
  solid: { name: 'עץ מלא', p: { kind: 'board', solid: true } },
  back: { name: 'לוח גב', p: { kind: 'board', back: true } },
  edge: { name: 'קנט', p: { kind: 'edge' } },
  glass: { name: 'זכוכית', p: { kind: 'glass' } },
  handle: { name: 'ידית', p: { kind: 'hardware', role: 'handle', allowNone: true, noneLabel: 'ללא (לחיצה)' } },
  hinge: { name: 'ציר', p: { kind: 'hardware', role: 'hinge' } },
};

function paramOf(rp) {
  const base = { key: rp.key, label: rp.label || rp.key, group: rp.group || 'כללי', hint: rp.hint || undefined, default: rp.default };
  if (rp.when && String(rp.when).trim()) {
    const src = String(rp.when);
    base.showIf = (values) => { try { return evalBool(src, values); } catch { return true; } };
    base.showIfText = src;
  }
  if (rp.type === 'enum') {
    const options = (rp.options || []).filter((o) => o && o.id !== undefined && o.id !== '').map((o) => ({ id: String(o.id), name: o.name || String(o.id) }));
    return { ...base, type: 'enum', options, default: options.some((o) => o.id === String(rp.default)) ? String(rp.default) : options[0]?.id };
  }
  if (rp.type === 'material') {
    const use = MATERIAL_USES[rp.use] || MATERIAL_USES.board;
    return { ...base, type: 'material', ...use.p };
  }
  const type = rp.type === 'int' ? 'int' : rp.type === 'deg' ? 'deg' : 'mm';
  const min = Number.isFinite(Number(rp.min)) ? Number(rp.min) : 0;
  const max = Number.isFinite(Number(rp.max)) && Number(rp.max) >= min ? Number(rp.max) : Math.max(min, 10000);
  const def = Number.isFinite(Number(rp.default)) ? Math.min(max, Math.max(min, Number(rp.default))) : min;
  return { ...base, type, min, max, default: def };
}

/** סדר עדיפויות לכיוון סיבים: הארוך מבין שני צירי המישור. */
function axesOf(box, grain) {
  const dims = { x: box.w, y: box.h, z: box.d };
  const axis = ['x', 'y', 'z'].reduce((a, b) => (dims[b] < dims[a] ? b : a));
  const other = ['x', 'y', 'z'].filter((a) => a !== axis);
  const g = other.includes(grain) ? grain : (dims[other[0]] >= dims[other[1]] ? other[0] : other[1]);
  return { axis, grain: g };
}
const EDGES = {
  front: { front: true },
  all: { front: true, back: true, left: true, right: true, top: true, bottom: true },
  none: {},
};

/**
 * בונה מתכון מול ערכים. מחזיר כמו build של תבנית, ובנוסף `errors` —
 * [{ where, message }] לכל נוסחה שנכשלה (הרכיב מדולג, השאר נבנה).
 */
export function runRecipe(r, v) {
  const parts = [], hardware = [], warnings = [], errors = [];
  const fail = (where, e) => errors.push({ where, message: e instanceof ExprError ? e.message : String(e?.message || e) });
  const scope = { ...v };
  for (const p of r.params || []) if (p.type === 'material') scope[`${p.key}_t`] = Number(material(v[p.key]).t) || 0;
  for (const [k, x] of (r.vars || []).entries()) {
    if (!x || !x.name) continue;
    try { scope[x.name] = evaluate(x.expr, scope); } catch (e) { fail(`משתנה ${x.name || k + 1}`, e); scope[x.name] = 0; }
  }
  const matOf = (m) => (m && Object.prototype.hasOwnProperty.call(v, m) ? v[m] : m);
  const ids = new Set();
  for (const [ci, c] of (r.components || []).entries()) {
    if (!c) continue;
    const label = `רכיב ${c.name || c.id || ci + 1}`;
    let count = 1;
    if (c.repeat && String(c.repeat).trim()) {
      try { count = Math.floor(evalNum(c.repeat, scope)); } catch (e) { fail(`${label} — כמות`, e); continue; }
      if (count > MAX_REPEAT) { fail(`${label} — כמות`, new ExprError(`${count} חזרות — יותר מ-${MAX_REPEAT}`)); count = MAX_REPEAT; }
    }
    const idx = c.index && /^[A-Za-z_][A-Za-z0-9_]*$/.test(c.index) ? c.index : 'i';
    const baseId = String(c.id || `c${ci + 1}`).replace(/[^A-Za-z0-9_-]/g, '') || `c${ci + 1}`;
    for (let i = 0; i < count; i += 1) {
      const s = { ...scope, [idx]: i, n: count };
      try {
        if (c.when && String(c.when).trim() && !evalBool(c.when, s)) continue;
        let id = count > 1 || c.repeat ? `${baseId}-${i + 1}` : baseId;
        while (ids.has(id)) id += '_';
        ids.add(id);
        const name = interpolate(c.name || baseId, s);
        const box = { x: evalNum(c.x || 0, s), y: evalNum(c.y || 0, s), z: evalNum(c.z || 0, s), w: evalNum(c.w || 0, s), h: evalNum(c.h || 0, s), d: evalNum(c.d || 0, s) };
        if (!(box.w > 0 && box.h > 0 && box.d > 0)) throw new ExprError(`מידה לא חיובית (${Math.round(box.w)}×${Math.round(box.h)}×${Math.round(box.d)})`);
        if (c.kind === 'door') {
          const side = c.side && String(c.side).trim() ? String(evaluate(c.side, s)) : 'left';
          const handle = c.handle ? matOf(c.handle) : null;
          const dd = door({
            id, name, x0: box.x, x1: box.x + box.w, y0: box.y, y1: box.y + box.h, zFront: box.z, t: box.d, type: 'wood',
            material: matOf(c.material), handle: handle && handle !== 'none' ? handle : null, hinge: (c.hinge && matOf(c.hinge)) || 'hw:hinge-110',
            hingeSide: ['left', 'right', 'top', 'bottom'].includes(side) ? side : 'left', gap: 2,
          });
          parts.push(...dd.parts); hardware.push(...dd.hardware);
        } else {
          const { axis, grain } = axesOf(box, c.grain);
          parts.push(part(id, name, box, { axis, grain, material: matOf(c.material), qtyKey: `${baseId}-${Math.round(box.w)}x${Math.round(box.h)}x${Math.round(box.d)}`, edges: { ...(EDGES[c.edges] || EDGES.front) } }));
        }
      } catch (e) { fail(count > 1 ? `${label} (${idx}=${i})` : label, e); }
    }
  }
  for (const [k, h] of (r.hardware || []).entries()) {
    if (!h) continue;
    try {
      if (h.when && String(h.when).trim() && !evalBool(h.when, scope)) continue;
      const qty = Math.round(evalNum(h.qty ?? 1, scope));
      if (qty > 0) hardware.push({ id: `rhw-${k + 1}`, kind: 'misc', material: matOf(h.material), qty, note: interpolate(h.name || '', scope) });
    } catch (e) { fail(`פרזול ${h.name || k + 1}`, e); }
  }
  for (const [k, w] of (r.warnings || []).entries()) {
    if (!w) continue;
    try { if (evalBool(w.when, scope)) warnings.push(interpolate(w.text || 'אזהרה', scope)); } catch (e) { fail(`אזהרה ${k + 1}`, e); }
  }
  for (const e of errors) warnings.push(`שגיאה במתכון — ${e.where}: ${e.message}`);
  let w = 0, h = 0, d = 0;
  for (const p of parts) { w = Math.max(w, p.box.x + p.box.w); h = Math.max(h, p.box.y + p.box.h); d = Math.max(d, p.box.z + p.box.d); }
  return { parts, hardware, warnings, errors, bounds: { w: w || 1, h: h || 1, d: d || 1 }, scope };
}

/** מתכון → תבנית רגילה. */
export function recipeTemplate(r) {
  const params = (r.params || []).filter((p) => p && /^[A-Za-z_][A-Za-z0-9_]*$/.test(p.key || '')).map(paramOf);
  return {
    key: r.key, name: r.name || 'מוצר ללא שם', description: r.description || '', laborHours: Number(r.laborHours) || 0,
    recipe: r, params,
    build(v) { const { scope, ...out } = runRecipe(r, v); return out; },
  };
}

/** מחליף את כל המתכונים הרשומים ברשימה חדשה. מתכון פגום — מדולג (ולא מפיל את השאר). */
export function registerRecipes(list) {
  for (const [k, t] of Object.entries(TEMPLATES)) if (t.recipe) delete TEMPLATES[k];
  for (const r of Array.isArray(list) ? list : []) {
    if (!r || typeof r.key !== 'string' || !r.key.startsWith(RECIPE_PREFIX)) continue;
    try { TEMPLATES[r.key] = recipeTemplate(r); } catch { /* מתכון פגום */ }
  }
}

/** בדיקת תחביר לכל הנוסחאות של מתכון — לעורך, לפני בנייה. */
export function recipeSyntax(r) {
  const out = [];
  const chk = (where, src) => { if (src === undefined || src === null || String(src).trim() === '') return; try { compile(String(src)); } catch (e) { out.push({ where, message: e.message }); } };
  (r.params || []).forEach((p) => chk(`פרמטר ${p.key} — מתי מוצג`, p.when));
  (r.vars || []).forEach((x) => chk(`משתנה ${x.name}`, x.expr));
  (r.components || []).forEach((c) => ['x', 'y', 'z', 'w', 'h', 'd', 'repeat', 'when', 'side'].forEach((f) => chk(`רכיב ${c.name || c.id} — ${f}`, c[f])));
  (r.warnings || []).forEach((w, i) => chk(`אזהרה ${i + 1}`, w.when));
  return out;
}

/** מזהה פנוי למתכון חדש. */
export function newRecipeKey() {
  let k;
  do k = RECIPE_PREFIX + Math.random().toString(36).slice(2, 8); while (TEMPLATES[k]);
  return k;
}

/**
 * מתכון לדוגמה — ארון פתוח עם עמודות, מדפים בלולאה ודלתות בתנאי. נקודת
 * התחלה שמראה את כל הכלים: משתנים, לולאה כפולה (דרך משתנה), תנאי, צד ציר מחושב.
 */
export function starterRecipe(key = newRecipeKey()) {
  return {
    key, name: 'ארון פתוח (דוגמה)', description: 'עמודות, מדפים ודלתות — נבנה מנוסחאות', laborHours: 5,
    params: [
      { key: 'W', label: 'רוחב', type: 'mm', default: 1200, min: 400, max: 3000, group: 'מידות' },
      { key: 'H', label: 'גובה', type: 'mm', default: 1800, min: 400, max: 2600, group: 'מידות' },
      { key: 'D', label: 'עומק', type: 'mm', default: 400, min: 200, max: 700, group: 'מידות' },
      { key: 'cols', label: 'עמודות', type: 'int', default: 2, min: 1, max: 6, group: 'חלוקה' },
      { key: 'shelves', label: 'מדפים בעמודה', type: 'int', default: 3, min: 0, max: 10, group: 'חלוקה' },
      { key: 'doors', label: 'דלתות', type: 'enum', default: 'none', group: 'דלתות', options: [{ id: 'none', name: 'ללא' }, { id: 'wood', name: 'דלת לכל עמודה' }] },
      { key: 'body', label: 'חומר הגוף', type: 'material', use: 'board', default: 'board:melamine-white-18', group: 'חומרים' },
      { key: 'handle', label: 'ידיות', type: 'material', use: 'handle', default: 'hw:handle-bar-128', group: 'דלתות', when: "doors == 'wood'" },
    ],
    vars: [
      { name: 'T', expr: 'body_t' },
      { name: 'inner', expr: 'W - 2*T' },
      { name: 'colW', expr: '(inner - (cols-1)*T) / cols' },
    ],
    components: [
      { id: 'side-L', name: 'דופן שמאל', kind: 'board', material: 'body', x: '0', y: '0', z: '0', w: 'T', h: 'H', d: 'D', grain: 'y', edges: 'front' },
      { id: 'side-R', name: 'דופן ימין', kind: 'board', material: 'body', x: 'W - T', y: '0', z: '0', w: 'T', h: 'H', d: 'D', grain: 'y', edges: 'front' },
      { id: 'top', name: 'גג', kind: 'board', material: 'body', x: 'T', y: 'H - T', z: '0', w: 'inner', h: 'T', d: 'D', edges: 'front' },
      { id: 'bottom', name: 'רצפה', kind: 'board', material: 'body', x: 'T', y: '0', z: '0', w: 'inner', h: 'T', d: 'D', edges: 'front' },
      { id: 'partition', name: 'מחיצה {i+1}', kind: 'board', material: 'body', repeat: 'cols - 1', index: 'i', x: 'T + (i+1)*colW + i*T', y: 'T', z: '0', w: 'T', h: 'H - 2*T', d: 'D - 20', grain: 'y', edges: 'front' },
      { id: 'shelf', name: 'מדף', kind: 'board', material: 'body', repeat: 'cols * shelves', index: 'k', when: 'shelves > 0',
        x: 'T + floor(k / shelves) * (colW + T)', y: 'T + ((k % shelves) + 1) * (H - 2*T) / (shelves + 1) - T/2', z: '0', w: 'colW', h: 'T', d: 'D - 25', edges: 'front' },
      { id: 'door', name: 'דלת {i+1}', kind: 'door', material: 'body', repeat: 'cols', index: 'i', when: "doors == 'wood'",
        x: 'i == 0 ? 0 : T/2 + i*(colW + T)', y: '0', z: 'D', w: '(i == cols-1 ? W : T + i*(colW + T) + colW + T/2) - (i == 0 ? 0 : T/2 + i*(colW + T))', h: 'H', d: 'T',
        side: "i < cols / 2 ? 'left' : 'right'", handle: 'handle', hinge: '' },
    ],
    hardware: [
      { name: 'פיני מדף', material: 'hw:shelf-pin', qty: 'cols * shelves * 4', when: 'shelves > 0' },
    ],
    warnings: [
      { when: 'colW > 800', text: 'עמודה ברוחב {colW} — מדף עלול לשקוע; כדאי עוד עמודה' },
      { when: 'H > 2200', text: 'גובה {H} — מומלץ עיגון לקיר' },
    ],
  };
}
