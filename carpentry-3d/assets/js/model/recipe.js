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

import bedRecipe from './recipes/bed.js';
import tableRecipe from './recipes/table.js';
import dresserRecipe from './recipes/dresser.js';
import { part, door, carcass, partitions, drawer, back, plinth } from './blocks.js';
import { addWheels, applyFinish, LIMITS, FINISHES, FINISHES_NO_GLASS, FINISHES_FLUSH } from './templates/common.js';
import { resolveShares, sectionLayout, effectivePins } from './layout.js';
import { material } from './materials.js';
import { evaluate, evalNum, evalBool, interpolate, compile, ExprError } from './expr.js';

export const RECIPE_PREFIX = 'r-';
const MAX_REPEAT = 200;

/** סוגי חומר לפרמטר — מה שהטופס יציע. */
export const MATERIAL_USES = {
  board: { name: 'לוח', p: { kind: 'board', back: false } },
  body: { name: 'לוח גוף (לא עץ מלא, לא משטח)', p: { kind: 'board', back: false, solid: false, top: false } },
  panel: { name: 'לוח (לא משטח)', p: { kind: 'board', back: false, top: false } },
  solid: { name: 'עץ מלא', p: { kind: 'board', solid: true } },
  back: { name: 'לוח גב', p: { kind: 'board', back: true } },
  edge: { name: 'קנט', p: { kind: 'edge' } },
  glass: { name: 'זכוכית', p: { kind: 'glass' } },
  handle: { name: 'ידית', p: { kind: 'hardware', role: 'handle', allowNone: true, noneLabel: 'ללא (לחיצה)' } },
  hinge: { name: 'ציר', p: { kind: 'hardware', role: 'hinge' } },
  wheel: { name: 'גלגל', p: { kind: 'hardware', role: 'wheel', allowNone: true, noneLabel: 'ללא' } },
  slide: { name: 'מסילת מגירה', p: { kind: 'hardware', role: 'slide' } },
};
/** רשימות בחירה חיות (מתעדכנות מהספרייה — למשל דוגמאות CNC). */
export const OPTION_SOURCES = {
  finishes: { name: 'גימורי חזית (כולל זכוכית)', list: FINISHES },
  finishesNoGlass: { name: 'גימורי חזית (בלי זכוכית)', list: FINISHES_NO_GLASS },
  finishFlush: { name: 'גימורים בלוח אחד (בלי עובי נוסף)', list: FINISHES_FLUSH },
};

function paramOf(rp) {
  const base = { key: rp.key, label: rp.label || rp.key, group: rp.group || 'כללי', hint: rp.hint || undefined, default: rp.default };
  if (rp.when && String(rp.when).trim()) {
    const src = String(rp.when);
    base.showIf = (values) => { try { return evalBool(src, values); } catch { return true; } };
    base.showIfText = src;
  }
  if (rp.type === 'json') return { ...base, type: 'json', editor: rp.editor || undefined, default: rp.default ?? null };
  if (rp.type === 'enum' && OPTION_SOURCES[rp.source]) return { ...base, type: 'enum', options: OPTION_SOURCES[rp.source].list, default: rp.default };
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
function axesOf(box, grain, forcedAxis) {
  const dims = { x: box.w, y: box.h, z: box.d };
  const axis = forcedAxis || ['x', 'y', 'z'].reduce((a, b) => (dims[b] < dims[a] ? b : a));
  const other = ['x', 'y', 'z'].filter((a) => a !== axis);
  const g = other.includes(grain) ? grain : (dims[other[0]] >= dims[other[1]] ? other[0] : other[1]);
  return { axis, grain: g };
}
const EDGES = {
  front: { front: true },
  all: { front: true, back: true, left: true, right: true, top: true, bottom: true },
  none: {},
};
export const EDGE_SIDES = { front: 'חזית', back: 'גב', top: 'עליון', bottom: 'תחתון', left: 'שמאל', right: 'ימין' };
/** קנטים: 'front' / 'all' / 'none', או רשימת צדדים 'top,front'. */
function edgesOf(e) {
  if (e === undefined || e === null || e === '') return { ...EDGES.front };
  if (EDGES[e]) return { ...EDGES[e] };
  const out = {};
  for (const k of String(e).split(',').map((x) => x.trim())) if (EDGE_SIDES[k]) out[k] = true;
  return out;
}

// ---- אבני בניין: רכיבים שקוראים לקוד הבדוק של blocks.js עם ארגומנטים מנוסחאות ----
// כל אבן: שדות (נוסחה 'fx', חומר/פרמטר 'mat', טקסט 'text') ב-c.a, והפעלה. אבן
// יכולה גם להחזיר תוצאות למשתנים (<מזהה>_x0…) שהרכיבים הבאים רואים.
const F = (k, label, kind = 'fx') => ({ k, label, kind });
export const BLOCKS = {
  carcass: {
    name: 'גוף (2 דפנות, גג, רצפה)', out: 'פנים הגוף: <מזהה>_x0 _x1 _y0 _y1 _z0 _z1',
    fields: [F('w', 'רוחב'), F('d', 'עומק'), F('z', 'z'), F('y0', 'תחתית הדפנות'), F('bottomY', 'פני הרצפה'), F('topY', 'פני הגג'), F('sideT', 'עובי דופן'), F('panelT', 'עובי גג/רצפה'), F('sidesOverTop', 'הדפנות עוברות (תנאי)'), F('material', 'חומר', 'mat'), F('dx', 'הזזה ב-x'), F('prefix', 'קידומת מזהים', 'text')],
    run(a, ctx) {
      const dx = a.num('dx', 0);
      const res = carcass({ w: a.num('w'), d: a.num('d'), z: a.num('z', 0), y0: a.num('y0', 0), bottomY: a.num('bottomY'), topY: a.num('topY'), sideT: a.num('sideT'), panelT: a.num('panelT'), sidesOverTop: a.bool('sidesOverTop'), material: a.mat('material'), prefix: a.text('prefix') });
      ctx.parts.push(...res.parts.map((p) => (dx ? { ...p, box: { ...p.box, x: p.box.x + dx } } : p)));
      const i = res.inner;
      ctx.out({ x0: i.x0 + dx, x1: i.x1 + dx, y0: i.y0, y1: i.y1, z0: i.z0, z1: i.z1 });
    },
  },
  partitions: {
    name: 'מחיצות (חלוקה לעמודות)', out: '<מזהה>_colW (הרחבה), _free, _x0s _x1s (רשימות)',
    fields: [F('x0', 'פנים x0'), F('x1', 'פנים x1'), F('y0', 'פנים y0'), F('y1', 'פנים y1'), F('z0', 'פנים z0'), F('z1', 'פנים z1'), F('columns', 'עמודות'), F('t', 'עובי'), F('widths', 'רוחבים (רשימה, רשות)'), F('material', 'חומר', 'mat'), F('prefix', 'קידומת מזהים', 'text')],
    run(a, ctx) {
      const widths = a.has('widths') ? a.val('widths') : null;
      const res = partitions({ inner: { x0: a.num('x0'), x1: a.num('x1'), y0: a.num('y0'), y1: a.num('y1'), z0: a.num('z0'), z1: a.num('z1') }, columns: Math.round(a.num('columns')), t: a.num('t'), material: a.mat('material'), prefix: a.text('prefix'), widths: Array.isArray(widths) ? widths : null });
      ctx.parts.push(...res.parts);
      ctx.out({ colW: res.colW, free: res.free, x0s: res.cols.map((c) => c.x0), x1s: res.cols.map((c) => c.x1) });
    },
  },
  drawer: {
    name: 'מגירה (חזית, ארגז, תחתית, מסילות)', out: '',
    fields: [F('x0', 'x0'), F('x1', 'x1'), F('y0', 'y0'), F('y1', 'y1'), F('zFront', 'z החזית'), F('depth', 'עומק הארגז'), F('frontT', 'עובי החזית'), F('boxT', 'עובי הארגז'), F('bottomT', 'עובי התחתית'),
      F('frontMaterial', 'חומר החזית', 'mat'), F('boxMaterial', 'חומר הארגז', 'mat'), F('bottomMaterial', 'חומר התחתית', 'mat'), F('slide', 'מסילות', 'mat'), F('handle', 'ידית', 'mat'), F('finish', 'גימור החזית', 'mat'),
      F('mountL', 'הדופן משמאל (מזהה)'), F('mountR', 'הדופן מימין (מזהה)'), F('mountBottom', 'תחתית הדופן')],
    run(a, ctx) {
      const handle = a.mat('handle');
      const d = drawer({
        id: ctx.id, name: ctx.name, x0: a.num('x0'), x1: a.num('x1'), y0: a.num('y0'), y1: a.num('y1'), zFront: a.num('zFront'), depth: a.num('depth'),
        frontT: a.num('frontT'), boxT: a.num('boxT', 18), bottomT: a.num('bottomT', 6),
        frontMaterial: a.mat('frontMaterial'), boxMaterial: a.mat('boxMaterial'), bottomMaterial: a.mat('bottomMaterial'), slide: a.mat('slide'), handle: handle && handle !== 'none' ? handle : null,
        mountIds: a.has('mountL') ? [String(a.val('mountL')), String(a.val('mountR'))] : null, mountBottom: a.has('mountBottom') ? a.num('mountBottom') : null,
      });
      if (a.has('finish')) applyFinish(d.parts[0], a.mat('finish'), { material: a.mat('frontMaterial'), normal: '+z' });
      ctx.parts.push(...d.parts); ctx.hardware.push(...d.hardware);
    },
  },
  back: {
    name: 'גב (בחריץ / מולבש / ללא)', out: '',
    fields: [F('mode', "שיטה ('groove' / 'overlay' / 'none')"), F('w', 'רוחב חיצוני'), F('y0', 'תחתית חיצונית'), F('y1', 'ראש חיצוני'), F('ix0', 'פנים x0'), F('ix1', 'פנים x1'), F('iy0', 'פנים y0'), F('iy1', 'פנים y1'), F('t', 'עובי'), F('grooveDepth', 'עומק החריץ'), F('inset', 'החריץ מהקצה'), F('material', 'חומר', 'mat'), F('dx', 'הזזה ב-x'), F('prefix', 'קידומת מזהים', 'text')],
    run(a, ctx) {
      const dx = a.num('dx', 0);
      const res = back({ mode: String(a.val('mode')), outer: { w: a.num('w'), y0: a.num('y0'), y1: a.num('y1') }, inner: { x0: a.num('ix0'), x1: a.num('ix1'), y0: a.num('iy0'), y1: a.num('iy1') }, t: a.num('t'), grooveDepth: a.num('grooveDepth', 8), inset: a.num('inset', 10), material: a.mat('material'), prefix: a.text('prefix') });
      ctx.parts.push(...res.parts.map((p) => (dx ? { ...p, box: { ...p.box, x: p.box.x + dx } } : p)));
    },
  },
  plinth: {
    name: 'סוקל', out: '',
    fields: [F('x0', 'x0'), F('x1', 'x1'), F('h', 'גובה'), F('setback', 'נסיגה'), F('t', 'עובי'), F('d', 'עומק הגוף'), F('material', 'חומר', 'mat'), F('prefix', 'קידומת מזהים', 'text')],
    run(a, ctx) {
      ctx.parts.push(...plinth({ inner: { x0: a.num('x0'), x1: a.num('x1') }, h: a.num('h'), setback: a.num('setback'), t: a.num('t'), d: a.num('d'), material: a.mat('material'), prefix: a.text('prefix') }).parts);
    },
  },
};
/** קורא ארגומנטים של אבן מתוך c.a מול המשתנים. */
function blockArgs(c, s, matOf) {
  const A = c.a || {};
  const has = (k) => A[k] !== undefined && A[k] !== null && String(A[k]).trim() !== '';
  return {
    has,
    val: (k) => evaluate(A[k], s),
    num: (k, def) => { if (!has(k)) { if (def === undefined) throw new ExprError(`חסר: ${k}`); return def; } return evalNum(A[k], s); },
    bool: (k) => (has(k) ? evalBool(A[k], s) : false),
    mat: (k) => (has(k) ? matOf(String(A[k]).trim()) : null),
    text: (k) => (has(k) ? interpolate(A[k], s) : ''),
  };
}
const outName = (id) => String(id).replace(/[^A-Za-z0-9_]/g, '_');

/** חלוקה (עמודות / שורות) מתוך פרמטר העריכה (json): רוחב לכל פריט, נעוץ או אוטומטי. */
function layoutList(L, s, v) {
  const n = Math.max(0, Math.round(evalNum(L.count, s)));
  const lay = sectionLayout(v[L.param], L.section || 'main', n);
  let pins = lay.widths;
  if (L.lastPin && String(L.lastPin).trim()) { const lp = evalNum(L.lastPin, s); pins = effectivePins(pins, Array.from({ length: n }, (_, i) => ({ defaultPin: i === n - 1 && n > 1 && lp > 0 ? lp : null }))); }
  return L.min !== undefined && L.min !== '' ? resolveShares(evalNum(L.total, s), pins, evalNum(L.min, s)) : resolveShares(evalNum(L.total, s), pins);
}

/** המשתנים (פרמטרים, עוביים, גבולות, משתנים, חלוקות) — לבנייה ולעורך החלוקה. */
function baseScope(r, v, fail) {
  const scope = { ...v };
  for (const p of r.params || []) if (p.type === 'material') scope[`${p.key}_t`] = Number(material(v[p.key]).t) || 18;
  for (const [k, val] of Object.entries(LIMITS)) scope[`lim_${k}`] = val;
  for (const [k, x] of (r.vars || []).entries()) {
    if (!x || !x.name) continue;
    try { scope[x.name] = evaluate(x.expr, scope); } catch (e) { fail(`משתנה ${x.name || k + 1}`, e); scope[x.name] = 0; }
  }
  for (const L of r.layouts || []) {
    if (!L || !L.name) continue;
    try { scope[L.name] = layoutList(L, scope, v); } catch (e) { fail(`חלוקה ${L.name}`, e); scope[L.name] = []; }
  }
  return scope;
}

/**
 * בונה מתכון מול ערכים. מחזיר כמו build של תבנית, ובנוסף `errors` —
 * [{ where, message }] לכל נוסחה שנכשלה (הרכיב מדולג, השאר נבנה).
 */
export function runRecipe(r, v) {
  const parts = [], hardware = [], warnings = [], errors = [];
  const fail = (where, e) => errors.push({ where, message: e instanceof ExprError ? e.message : String(e?.message || e) });
  const scope = baseScope(r, v, fail);
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
        // מזהה: תבנית {נוסחה} אם יש ("drawer-{c+1}-{r+1}"), אחרת <מזהה>-<מספר> בלולאה
        let id = c.idt && String(c.idt).trim() ? interpolate(c.idt, s) : (count > 1 || c.repeat ? `${baseId}-${i + 1}` : baseId);
        while (ids.has(id)) id += '_';
        ids.add(id);
        const name = interpolate(c.name || baseId, s);
        if (BLOCKS[c.kind]) {
          BLOCKS[c.kind].run(blockArgs(c, s, matOf), { parts, hardware, id, name, out: (o) => { for (const [k, val] of Object.entries(o)) scope[`${outName(baseId)}_${k}`] = val; } });
          continue;
        }
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
          const auto = axesOf(box, c.grain);
          const axis = ['x', 'y', 'z'].includes(c.axis) ? c.axis : auto.axis;
          const grain = ['x', 'y', 'z'].includes(c.grain) && c.grain !== axis ? c.grain : axesOf(box, null, axis).grain;
          // מפתח קיבוץ ברשימת החיתוך והערה — טקסט עם {נוסחה}; box_w/h/d = מידות הרכיב
          const bs = { ...s, box_w: box.w, box_h: box.h, box_d: box.d };
          const qtyKey = c.qtyKey && String(c.qtyKey).trim() ? interpolate(c.qtyKey, bs) : `${baseId}-${Math.round(box.w)}x${Math.round(box.h)}x${Math.round(box.d)}`;
          const note = c.note && String(c.note).trim() ? interpolate(c.note, bs) : undefined;
          parts.push(part(id, name, box, { axis, grain, material: matOf(c.material), qtyKey, edges: edgesOf(c.edges), note }));
        }
      } catch (e) { fail(count > 1 ? `${label} (${idx}=${i})` : label, e); }
    }
  }
  for (const [k, h] of (r.hardware || []).entries()) {
    if (!h) continue;
    try {
      if (h.when && String(h.when).trim() && !evalBool(h.when, scope)) continue;
      const qty = Math.round(evalNum(h.qty ?? 1, scope));
      if (qty > 0) hardware.push({ id: h.id && String(h.id).trim() ? String(h.id) : `rhw-${k + 1}`, kind: 'misc', material: matOf(h.material), qty, note: interpolate(h.name || '', scope) });
    } catch (e) { fail(`פרזול ${h.name || k + 1}`, e); }
  }
  // גלגלים: ארבעה בפינות התחתית, והמוצר מורם בגובהם (addWheels של הקוד)
  scope.lift = 0;
  if (r.wheels && r.wheels.param) {
    try {
      const wv = { wheels: v[r.wheels.param] };
      scope.lift = addWheels(parts, hardware, wv, { x0: evalNum(r.wheels.x0 || 0, scope), x1: evalNum(r.wheels.x1, scope), y0: evalNum(r.wheels.y0 || 0, scope), z0: evalNum(r.wheels.z0 || 0, scope), z1: evalNum(r.wheels.z1, scope), inset: evalNum(r.wheels.inset ?? 60, scope) });
    } catch (e) { fail('גלגלים', e); }
  }
  for (const [k, w] of (r.warnings || []).entries()) {
    if (!w) continue;
    try {
      // אזהרה בלולאה (למשל לכל עמודה): חזרות + מונה, כמו ברכיב
      const count = w.repeat && String(w.repeat).trim() ? Math.min(MAX_REPEAT, Math.floor(evalNum(w.repeat, scope))) : 1;
      const idx = w.index && /^[A-Za-z_][A-Za-z0-9_]*$/.test(w.index) ? w.index : 'i';
      for (let i = 0; i < count; i += 1) {
        const s = w.repeat ? { ...scope, [idx]: i, n: count } : scope;
        if (evalBool(w.when, s)) warnings.push(interpolate(w.text || 'אזהרה', s));
      }
    } catch (e) { fail(`אזהרה ${k + 1}`, e); }
  }
  let w = 0, h = 0, d = 0;
  for (const p of parts) { w = Math.max(w, p.box.x + p.box.w); h = Math.max(h, p.box.y + p.box.h); d = Math.max(d, p.box.z + p.box.d); }
  const bounds = { w: w || 1, h: h || 1, d: d || 1 };
  // גבולות מפורשים (רשות) — כשהמוצר "תופס" יותר מהחלקים (למשל מזרן)
  if (r.bounds) for (const k of ['w', 'h', 'd']) if (r.bounds[k] && String(r.bounds[k]).trim()) { try { bounds[k] = evalNum(r.bounds[k], scope); } catch (e) { fail(`גבולות ${k}`, e); } }
  for (const e of errors) warnings.push(`שגיאה במתכון — ${e.where}: ${e.message}`);
  return { parts, hardware, warnings, errors, bounds, scope };
}

/** מתכון → תבנית רגילה. */
export function recipeTemplate(r) {
  const params = (r.params || []).filter((p) => p && /^[A-Za-z_][A-Za-z0-9_]*$/.test(p.key || '')).map(paramOf);
  const t = {
    key: r.key, name: r.name || 'מוצר ללא שם', description: r.description || '', laborHours: Number(r.laborHours) || 0,
    recipe: r, params,
    build(v) { const { scope, ...out } = runRecipe(r, v); return out; },
  };
  // עורך החלוקה (form.js): לכל חלוקה עם כותרת — קבוצה עם הסכום והפריטים
  const eds = (r.layouts || []).filter((L) => L && L.title);
  if (eds.length) {
    t.columnSpace = (values) => {
      const v = { ...Object.fromEntries(params.map((p) => [p.key, p.default])), ...values };
      const s = baseScope({ ...r, layouts: [] }, v, () => {});
      return { sections: eds.map((L) => {
        let n = 0, lp = null;
        try { n = Math.max(0, Math.round(evalNum(L.count, s))); } catch { /* 0 */ }
        if (L.lastPin && String(L.lastPin).trim()) { try { lp = evalNum(L.lastPin, s); } catch { lp = 0; } }
        let total = 0;
        try { total = evalNum(L.editorTotal && String(L.editorTotal).trim() ? L.editorTotal : L.total, s); } catch { /* 0 */ }
        const sec = { key: L.section || 'main', title: L.title, total, sizeLabel: L.sizeLabel || 'רוחב', allLabel: L.allLabel || 'הפריטים', modes: { next: L.next || 'מהבא', prev: L.prev || 'מהקודם' } };
        if (L.reverse) sec.reverse = true;
        sec.items = Array.from({ length: n }, (_, i) => {
          const it = { label: interpolate(L.item || 'פריט {i+1}', { ...s, i, n }), editable: false, note: '' };
          if (lp !== null) it.defaultPin = i === n - 1 && n > 1 && lp > 0 ? lp : null;
          return it;
        });
        return sec;
      }) };
    };
  }
  return t;
}

// ---- מתכונים מובנים (שלב 3): מוצרים שעברו מקוד למתכון, באותו מפתח ----
// פרויקט קיים ('bed', 'table') נפתח כרגיל — הפרמטרים זהים. המנהל יכול לפתוח
// אותם במעבדה ולערוך: הגרסה הערוכה נשמרת בשרת באותו מפתח ודורסת את המובנה;
// מחיקתה = חזרה למתכון שבקוד.
export const BUILTIN_RECIPES = { bed: bedRecipe, table: tableRecipe, dresser: dresserRecipe };
export const isBuiltinKey = (k) => Object.prototype.hasOwnProperty.call(BUILTIN_RECIPES, k);
/** תבנית ממתכון מובנה (או מהגרסה הערוכה שלו). */
export function builtinTemplate(key, override = null) {
  const t = recipeTemplate(override ? { ...override, key } : BUILTIN_RECIPES[key]);
  t.builtin = true;
  if (override) t.overridden = true;
  return t;
}

// רישום התבניות (index.js) — מוזרק, כדי שהמודול הזה לא ייבא את index.js (מעגל)
let REG = null;
export function setRegistry(templates) { REG = templates; }

/**
 * מחליף את כל המתכונים הרשומים ברשימה חדשה: מתכוני 'r-…', וגרסאות ערוכות של
 * המובנים. מובנה שאין לו גרסה ברשימה — חוזר למקור. מתכון פגום — מדולג.
 */
export function registerRecipes(list) {
  for (const [k, t] of Object.entries(REG)) if (t.recipe && !t.builtin) delete REG[k];
  const over = {};
  for (const r of Array.isArray(list) ? list : []) {
    if (!r || typeof r.key !== 'string') continue;
    if (isBuiltinKey(r.key)) { over[r.key] = r; continue; }
    if (!r.key.startsWith(RECIPE_PREFIX)) continue;
    try { REG[r.key] = recipeTemplate(r); } catch { /* מתכון פגום */ }
  }
  for (const k of Object.keys(BUILTIN_RECIPES)) {
    try { REG[k] = builtinTemplate(k, over[k] || null); } catch { REG[k] = builtinTemplate(k); }
  }
}

/** בדיקת תחביר לכל הנוסחאות של מתכון — לעורך, לפני בנייה. */
export function recipeSyntax(r) {
  const out = [];
  const chk = (where, src) => { if (src === undefined || src === null || String(src).trim() === '') return; try { compile(String(src)); } catch (e) { out.push({ where, message: e.message }); } };
  (r.params || []).forEach((p) => chk(`פרמטר ${p.key} — מתי מוצג`, p.when));
  (r.vars || []).forEach((x) => chk(`משתנה ${x.name}`, x.expr));
  (r.layouts || []).forEach((L) => ['count', 'total', 'min', 'lastPin', 'editorTotal'].forEach((f) => chk(`חלוקה ${L.name} — ${f}`, L[f])));
  (r.components || []).forEach((c) => {
    ['x', 'y', 'z', 'w', 'h', 'd', 'repeat', 'when', 'side'].forEach((f) => chk(`רכיב ${c.name || c.id} — ${f}`, c[f]));
    if (BLOCKS[c.kind]) BLOCKS[c.kind].fields.filter((f) => f.kind === 'fx').forEach((f) => chk(`רכיב ${c.name || c.id} — ${f.label}`, c.a?.[f.k]));
  });
  (r.warnings || []).forEach((w, i) => { chk(`אזהרה ${i + 1}`, w.when); chk(`אזהרה ${i + 1} — חזרות`, w.repeat); });
  return out;
}

/** מזהה פנוי למתכון חדש. */
export function newRecipeKey() {
  let k;
  do k = RECIPE_PREFIX + Math.random().toString(36).slice(2, 8); while (REG[k]);
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
