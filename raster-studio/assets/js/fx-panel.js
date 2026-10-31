// לוח סגנונות שכבה: צל, זוהר, קו מתאר, צבע, זוהר פנימי, צל פנימי — על השכבה הפעילה (פיקסלים או מילוי).
// עריכה חיה; ✓ — צעד ביטול אחד ("סגנונות שכבה"); ✕ — חוזרים למה שהיה. כל אפקט — לשונית, ובה "פעיל" והשדות שלו;
// שינוי שדה מפעיל את האפקט. הפורמט: mbe-format.md סעיף 3 (effects) — הצל נשמר ב-dx / dy; כיוון ומרחק — רק בלוח.
// אפקט שאנחנו לא מכירים (או עוד אחד מאותו סוג, מאפליקציה אחרת) — נשאר ברשימה כמו שהוא.

import { EFFECT_TYPES } from './engine/formats.js';

const $ = (s, el = document) => el.querySelector(s);

export const FX_LABEL = { shadow: 'צל', glow: 'זוהר', stroke: 'קו מתאר', overlay: 'צבע', 'inner-glow': 'זוהר פנימי', 'inner-shadow': 'צל פנימי' };
// ערכי ברירת המחדל — ביחידות הלוח (אחוזים, מעלות, פיקסלים)
const DEFAULTS = {
  shadow: { color: '#000000', opacity: 75, angle: 45, distance: 6, blur: 8, spread: 0 },
  'inner-shadow': { color: '#000000', opacity: 75, angle: 45, distance: 4, blur: 6, spread: 0 },
  glow: { color: '#ffffbe', opacity: 75, blur: 10, spread: 0 },
  'inner-glow': { color: '#ffffbe', opacity: 75, blur: 10, spread: 0 },
  stroke: { color: '#000000', opacity: 100, width: 3, position: 'outside' },
  overlay: { color: '#ff0000', opacity: 100 },
};
const UNIT = { opacity: '%', angle: '°', distance: 'px', blur: 'px', spread: '%', width: 'px' };
const RANGES = ['opacity', 'angle', 'distance', 'blur', 'spread', 'width'];
const round2 = v => Math.round(v * 100) / 100;

/** אפקט מהפורמט → ערכי הלוח */
function toUi(e) {
  const u = { ...DEFAULTS[e.type] };
  if (typeof e.color === 'string') u.color = e.color;
  if (Number.isFinite(e.opacity)) u.opacity = Math.round(e.opacity * 100);
  if (Number.isFinite(e.dx) || Number.isFinite(e.dy)) {
    const dx = e.dx ?? 0, dy = e.dy ?? 0;
    u.distance = Math.round(Math.hypot(dx, dy));
    if (u.distance) u.angle = Math.round(Math.atan2(dy, dx) * 180 / Math.PI);
  }
  if (Number.isFinite(e.blur)) u.blur = Math.round(e.blur);
  if (Number.isFinite(e.spread)) u.spread = Math.round(e.spread * 100);
  if (Number.isFinite(e.width)) u.width = Math.round(e.width);
  if (e.position) u.position = e.position;
  return u;
}

/** ערכי הלוח → אפקט בפורמט; rest — האפקט המקורי (שדות שאנחנו לא מכירים נשמרים) */
function toEffect(type, u, rest = {}) {
  const e = { ...rest, type, color: u.color, opacity: u.opacity / 100 };
  if (type === 'shadow' || type === 'inner-shadow') {
    const a = u.angle * Math.PI / 180;
    e.dx = round2(u.distance * Math.cos(a));
    e.dy = round2(u.distance * Math.sin(a));
  }
  if (type !== 'stroke' && type !== 'overlay') {
    e.blur = u.blur;
    if (u.spread) e.spread = u.spread / 100; else delete e.spread;
  }
  if (type === 'stroke') { e.width = u.width; e.position = u.position; }
  return e;
}

export function createFxPanel({ root, doc, renderer, history, onChange, draw, toast, beforeOpen }) {
  let open = false;
  let tab = 'shadow';
  let layer = null, before = null;
  let ui = {}, on = new Set(), rest = {}, extra = [];

  /** הרשימה מהלוח: הפעילים לפי סדר הציור, ואחריהם מה שלא שלנו */
  const current = () => {
    const list = [...EFFECT_TYPES.filter(t => on.has(t)).map(t => toEffect(t, ui[t], rest[t])), ...extra];
    return list.length ? list : undefined;
  };

  function preview() {
    layer.effects = current();
    renderer.invalidate(doc());
    draw();
  }

  /** הלשונית הפעילה: השדות שלה, "פעיל", וסימון ● על לשוניות שהאפקט שלהן פעיל */
  function sync() {
    const u = ui[tab];
    for (const b of root.querySelectorAll('[data-fx-tab]')) {
      const t = b.dataset.fxTab;
      b.setAttribute('aria-selected', String(t === tab));
      b.classList.toggle('on', on.has(t));
    }
    $('#fx-on', root).checked = on.has(tab);
    $('#fx-color', root).value = u.color;
    $('#fx-position', root).value = u.position ?? 'outside';
    for (const f of RANGES) {
      const el = $('#fx-' + f, root);
      if (u[f] !== undefined) el.value = u[f];
      $(`output[for="fx-${f}"]`, root).textContent = (u[f] ?? '') + UNIT[f];
    }
    for (const el of root.querySelectorAll('[data-fx-for]')) el.hidden = !el.dataset.fxFor.split(' ').includes(tab);
  }

  root.addEventListener('input', e => {
    if (!open) return;
    const el = e.target;
    if (el.id === 'fx-on') { if (el.checked) on.add(tab); else on.delete(tab); sync(); preview(); return; }
    const f = el.id?.slice(3);
    if (!f || !(f in DEFAULTS[tab]) && f !== 'position') return;
    ui[tab][f] = el.type === 'range' ? Number(el.value) : el.value;
    on.add(tab);   // שינוי שדה — האפקט פעיל
    sync();
    preview();
  });
  root.addEventListener('click', e => {
    const t = e.target.closest('[data-fx-tab]');
    if (t) { tab = t.dataset.fxTab; sync(); return; }
    const b = e.target.closest('[data-fx-act]');
    if (b) ({ apply, cancel: close })[b.dataset.fxAct]();
  });

  function show() {
    const d = doc(), l = d?.active;
    if (!l) return;
    if (l.type === 'group') { toast('סגנונות שכבה — על שכבה, לא על קבוצה', true); return; }
    if (l.foreign) { toast(`שכבה של אפליקציה אחרת (${l.foreign.type}) — לא נערכת כאן`, true); return; }
    if (l.adjust) { toast('לשכבת התאמה אין סגנונות — בחרו שכבת פיקסלים או מילוי', true); return; }
    if (l.locked) { toast('השכבה נעולה — 🔓 בלוח השכבות', true); return; }
    beforeOpen?.();
    layer = l;
    before = l.effects ? structuredClone(l.effects) : undefined;
    ui = Object.fromEntries(EFFECT_TYPES.map(t => [t, { ...DEFAULTS[t] }]));
    on = new Set(); rest = {}; extra = [];
    for (const e of l.effects ?? []) {
      if (EFFECT_TYPES.includes(e?.type) && !on.has(e.type)) { ui[e.type] = toUi(e); rest[e.type] = e; on.add(e.type); }
      else extra.push(e);
    }
    // הלשונית הראשונה שפעילה (או צל)
    tab = EFFECT_TYPES.find(t => on.has(t)) ?? 'shadow';
    open = true;
    root.hidden = false;
    $('#fx-layer', root).textContent = l.name;
    sync();
  }

  function close() {
    if (!open) return;
    open = false;
    root.hidden = true;
    layer.effects = before;
    renderer.invalidate(doc());
    layer = null;
    draw();
  }

  function apply() {
    if (!open) return;
    const d = doc(), after = current();
    open = false;
    root.hidden = true;
    layer.effects = after;
    if (JSON.stringify(after ?? null) !== JSON.stringify(before ?? null)) {
      history.props(d, layer, { effects: before }, { effects: after }, 'סגנונות שכבה');
    }
    renderer.invalidate(d);
    layer = null;
    onChange('effects');
  }

  return { show, close, apply, get open() { return open; } };
}
