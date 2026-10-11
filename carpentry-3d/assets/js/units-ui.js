// כרטיס העמודה והקומה (docs/elements-spec.md, שלב 3).
//
// בטופס: רשימת העמודות. כל עמודה נפתחת לכרטיס — רוחב, גובה וקומות; כל קומה —
// גובה, חזית (ללא / דלת עץ / ויטרינה, עם כל ההגדרות שלה) ותוכן (מדפים / מגירות /
// ריק). כל אלמנט עצמאי. מהתלת מימד, "⚙️ הגדרות הקומה" בכרטיס הרכיב פותח את אותו
// כרטיס כאן (openUnit).
//
// הנתונים: values.units. עד העריכה הראשונה הם נגזרים מההגדרות הישנות (המודל
// מחזיר אותם ב-model.units); העריכה הראשונה שומרת אותם בפרויקט, ומאז הם המקור
// היחיד. "לא בתוקף" — מ-model.inactive, ליד השדה (לפי path).

import * as M from './model/materials.js';
import { optionsFor } from './model/index.js';
import { FINISHES_NO_GLASS } from './model/templates/common.js';

const esc = (s) => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
const clone = (x) => JSON.parse(JSON.stringify(x));

let model = null;
const editors = new Set();
/** המודל האחרון שנבנה — לערכים המחושבים (רוחב, גובה) ול"לא בתוקף". */
export function setUnitsModel(m) { model = m; }

const FRONTS = { none: 'ללא', wood: 'דלת עץ', glass: 'ויטרינה' };
const INTERIORS = { shelves: 'מדפים', drawers: 'מגירות', empty: 'ריק' };
const FRAMES = { wood: 'מסגרת עץ / MDF', alu: 'פרופיל אלומיניום', none: 'זכוכית בלבד (בלי מסגרת)' };
const SIDES = { 4: '4 צדדים', '2v': '2 — ימין ושמאל', '2h': '2 — למעלה ולמטה' };
const PROFILES = { flat: 'ישר', shaker: 'שייקר', bevel: 'פאזה 45°', round: 'מעוגל' };
const MULLIONS = { none: 'ללא', v2: 'פס אנכי', h2: 'פס אופקי', h3: '2 פסים אופקיים', grid4: 'שתי וערב', grid6: '6 שמשות' };
const LEAVES = { auto: 'אוטומטי (לפי הרוחב)', 1: 'כנף אחת', 2: 'שתי כנפיים' };
const HINGE_SIDES = { auto: 'אוטומטי', left: 'ציר משמאל', right: 'ציר מימין', top: 'נפתחת למעלה', bottom: 'נפתחת למטה' };
const MODES = { adjustable: 'מתכווננים', fixed: 'קבועים' };

const list = (arr) => Object.fromEntries(arr.map((m) => [m.id, m.name]));
const boards = () => list(M.materialsOfKind('board', { back: false, solid: false, top: false }));
const glasses = () => list(M.materialsOfKind('glass'));
const role = (r, none) => ({ ...(none ? { none: none } : {}), ...list(M.materialsOfRole(r)) });
const finishes = () => Object.fromEntries(optionsFor({ type: 'enum', options: FINISHES_NO_GLASS }).map((o) => [o.id, o.name]));

/** סיכום עמודה בשורה אחת: "496 מ"מ · עץ 850 / ויטרינה". */
function summary(u, g) {
  const fl = u.floors.map((f, j) => {
    const fr = FRONTS[f.front?.type || 'none'], it = f.interior?.type === 'drawers' ? ` + ${f.interior.drawers?.count ?? 3} מגירות` : '';
    const h = u.floors.length > 1 && g?.floors?.[j] ? ` ${Math.round(g.floors[j])}` : '';
    return `${f.front?.type === 'none' ? (f.interior?.type === 'drawers' ? 'מגירות' : 'פתוח') : fr}${h}${f.front?.type !== 'none' ? it : ''}`;
  });
  return `${g ? `${Math.round(g.width)} מ"מ · ` : ''}${fl.join(' / ')}`;
}

/**
 * עורך העמודות בטופס. `values` — ערכי הפרויקט (משתנים במקום), `onChange` — אחרי כל
 * שינוי (הבנייה מחדש והשמירה).
 */
export function renderUnitsEditor(box, values, onChange) {
  editors.add(box);
  box._units = { values, onChange };
  const units = Array.isArray(values.units) && values.units.length ? values.units : model?.units;
  if (!units) { box.innerHTML = '<p class="muted">טוען…</p>'; return; }
  const geo = model?.unitGeo || [];
  const inactive = model?.inactive || [];
  const open = new Set((box.dataset.open || '').split(',').filter(Boolean));
  const off = (path) => inactive.filter((x) => x.path === path || x.path === `units.${path}`).map((x) => `<small class="unit__off">לא בתוקף — ${esc(x.reason)}</small>`).join('');

  const sel = (path, val, opts, label, cls = '') => `<label class="unit__f ${cls}"><span>${label}</span><select data-u="${path}">${Object.entries(opts).map(([k, n]) => `<option value="${esc(k)}" ${String(val ?? '') === String(k) ? 'selected' : ''}>${esc(n)}</option>`).join('')}</select>${off(path)}</label>`;
  const num = (path, val, label, ph) => `<label class="unit__f"><span>${label}</span><input type="number" data-num="1" step="10" data-u="${path}" value="${Number.isFinite(val) && val > 0 ? Math.round(val) : ''}" placeholder="${esc(ph)}"><i>מ"מ</i>${off(path)}</label>`;
  const cnt = (path, val, label, min, max) => `<label class="unit__f"><span>${label}</span><span class="unit__cnt"><button type="button" data-step="${path}" data-d="-1" ${val <= min ? 'disabled' : ''}>−</button><b>${val}</b><button type="button" data-step="${path}" data-d="1" ${val >= max ? 'disabled' : ''}>+</button></span>${off(path)}</label>`;

  function floorCard(u, i, f, j) {
    const at = `${i}.floors.${j}`, fr = f.front || {}, it = f.interior || {}, vt = fr.vitrine || {};
    const key = `${i}.${j}`;
    const n = u.floors.length;
    const name = n === 1 ? 'כל הגובה' : `קומה ${j + 1}${j === 0 ? ' (תחתונה)' : j === n - 1 ? ' (עליונה)' : ''}`;
    const g = geo[i]?.floors?.[j];
    const head = `${name} · ${FRONTS[fr.type || 'none']}${it.type && it.type !== 'empty' ? ` · ${INTERIORS[it.type]}` : ''}${g ? ` · ${Math.round(g)} מ"מ` : ''}`;
    const front = [sel(`${at}.front.type`, fr.type || 'none', FRONTS, 'חזית')];
    if (fr.type === 'wood' || fr.type === 'glass') {
      if (fr.type === 'wood') front.push(sel(`${at}.front.material`, fr.material, boards(), 'חומר הדלת'), sel(`${at}.front.finish`, fr.finish || 'flat', finishes(), 'גימור'));
      if (fr.type === 'glass') {
        front.push(sel(`${at}.front.glass`, fr.glass, glasses(), 'זכוכית'), sel(`${at}.front.vitrine.frame`, vt.frame || 'wood', FRAMES, 'מסגרת'));
        if (vt.frame !== 'none') front.push(sel(`${at}.front.vitrine.sides`, vt.sides || '4', SIDES, 'צדדים'), num(`${at}.front.vitrine.frameW`, vt.frameW ?? 60, 'רוחב הפס', '60'));
        if ((vt.frame || 'wood') === 'wood') front.push(sel(`${at}.front.vitrine.profile`, vt.profile || 'flat', PROFILES, 'פרופיל'), sel(`${at}.front.vitrine.frameMaterial`, vt.frameMaterial || 'same', { same: 'כמו חומר הדלתות', ...boards() }, 'צבע המסגרת'));
        if (vt.frame === 'alu') front.push(sel(`${at}.front.vitrine.alu`, vt.alu || 'profile:alu-black', list(M.materialsOfKind('profile')), 'צבע הפרופיל'));
        if (vt.frame !== 'none') front.push(sel(`${at}.front.vitrine.mullions`, vt.mullions || 'none', MULLIONS, 'שפרוסים'));
      }
      front.push(sel(`${at}.front.leaves`, fr.leaves || 'auto', LEAVES, 'כנפיים'), sel(`${at}.front.hingeSide`, fr.hingeSide || 'auto', HINGE_SIDES, 'פתיחה'),
        sel(`${at}.front.hinge`, fr.hinge, role('hinge'), 'צירים'), sel(`${at}.front.handle`, fr.handle || 'none', role('handle', 'ללא (לחיצה)'), 'ידית'));
    }
    const inner = [sel(`${at}.interior.type`, it.type || 'shelves', INTERIORS, 'תוכן')];
    if ((it.type || 'shelves') === 'shelves') {
      const sh = it.shelves || {};
      inner.push(cnt(`${at}.interior.shelves.count`, sh.count ?? 0, 'מדפים', 0, 15), sel(`${at}.interior.shelves.material`, sh.material || 'same', { same: 'כמו המדפים', ...boards(), ...glasses() }, 'חומר המדפים'), sel(`${at}.interior.shelves.mode`, sh.mode || 'adjustable', MODES, 'קבועים / מתכווננים'));
      if (Array.isArray(sh.gaps) && sh.gaps.some((x) => x !== null)) inner.push(off(`units.${at}.interior.shelves.gaps`) || '<small class="muted">גבהי התאים — לפי עריכה קודמת</small>');
    } else if (it.type === 'drawers') {
      const dr = it.drawers || {};
      inner.push(cnt(`${at}.interior.drawers.count`, dr.count ?? 3, 'מגירות', 1, 10), sel(`${at}.interior.drawers.frontMaterial`, dr.frontMaterial || fr.material, boards(), 'חומר החזיתות'), sel(`${at}.interior.drawers.slide`, dr.slide || 'hw:slide-std', role('slide'), 'מסילות'));
      if ((fr.type || 'none') === 'none') inner.push(sel(`${at}.interior.drawers.finish`, dr.finish || 'flat', finishes(), 'גימור החזיתות'), sel(`${at}.interior.drawers.handle`, dr.handle || 'hw:handle-bar-128', role('handle', 'ללא (לחיצה)'), 'ידיות'));
      else inner.push('<small class="muted">מאחורי הדלת — מגירות פנימיות, בלי ידית</small>');
    }
    return `<details class="unit__floor" data-key="f${key}" ${open.has(`f${key}`) ? 'open' : ''}>
      <summary>${esc(head)}</summary>
      <div class="unit__tools">
        ${n > 1 ? num(`${at}.height`, f.height, 'גובה הקומה', g ? `אוטומטי (${Math.round(g)})` : 'אוטומטי') : ''}
        <span class="unit__btns">
          <button type="button" data-act="up" data-i="${i}" data-j="${j}" ${j === n - 1 ? 'disabled' : ''} title="קומה למעלה">↑</button>
          <button type="button" data-act="down" data-i="${i}" data-j="${j}" ${j === 0 ? 'disabled' : ''} title="קומה למטה">↓</button>
          <button type="button" data-act="dup" data-i="${i}" data-j="${j}" title="שכפול הקומה">⧉</button>
          <button type="button" data-act="del" data-i="${i}" data-j="${j}" ${n === 1 ? 'disabled' : ''} title="מחיקת הקומה">✕</button>
        </span>
        ${off(`units.${at}.height`)}
      </div>
      <div class="unit__sec"><b>חזית</b>${front.join('')}</div>
      <div class="unit__sec"><b>תוכן</b>${inner.join('')}</div>
    </details>`;
  }

  box.innerHTML = units.map((u, i) => {
    const g = geo[i];
    const notes = inactive.filter((x) => x.column === i && !x.path.startsWith('units.')).map((x) => `<small class="unit__off">⚠ ${esc(x.reason)}</small>`).join('');
    // הקומות מוצגות מלמעלה למטה — כמו שרואים את הארון
    const floors = u.floors.map((f, j) => [f, j]).reverse().map(([f, j]) => floorCard(u, i, f, j)).join('');
    return `<details class="unit" data-key="u${i}" ${open.has(`u${i}`) ? 'open' : ''}>
      <summary><b>עמודה ${i + 1}</b> <span class="muted">· ${esc(summary(u, g))}</span>${notes ? ' <span class="unit__warn">⚠</span>' : ''}</summary>
      ${notes}
      <div class="unit__row">${num(`${i}.width`, u.width, 'רוחב', g ? `אוטומטי (${Math.round(g.width)})` : 'אוטומטי')}${num(`${i}.height`, u.height, 'גובה', g ? `כל הגובה (${Math.round(g.height)})` : 'כל הגובה')}</div>
      ${floors}
      <button type="button" class="btn btn--small unit__add" data-act="add" data-i="${i}">＋ קומה</button>
    </details>`;
  }).join('') + (values.units ? '' : '<p class="muted unit__hint">כל עמודה וכל קומה — עצמאית. השינוי הראשון שומר את המבנה בפרויקט.</p>');
}

/** פותח את כרטיס העמודה (ואם צריך — הקומה) וגולל אליו. */
export function openUnit(i, j) {
  for (const box of editors) {
    if (!box.isConnected) { editors.delete(box); continue; }
    const set = new Set((box.dataset.open || '').split(',').filter(Boolean));
    set.add(`u${i}`); if (j !== null && j !== undefined) set.add(`f${i}.${j}`);
    box.dataset.open = [...set].join(',');
    const { values, onChange } = box._units || {};
    if (values) renderUnitsEditor(box, values, onChange);
    const el = box.querySelector(j !== null && j !== undefined ? `[data-key="f${i}.${j}"]` : `[data-key="u${i}"]`);
    el?.closest('details.group')?.setAttribute('open', '');
    el?.scrollIntoView({ block: 'center', behavior: 'smooth' });
    el?.classList.add('is-flash'); setTimeout(() => el?.classList.remove('is-flash'), 1200);
  }
}

// ---- עריכה (האצלה אחת לכל המסמך — הכרטיס מצויר מחדש אחרי כל בנייה) ----
function editorOf(el) { const box = el.closest('.unitsedit'); return box && box._units ? box : null; }
function commit(box, mutate) {
  const { values, onChange } = box._units;
  const units = clone(Array.isArray(values.units) && values.units.length ? values.units : model.units);
  for (const u of units) { delete u.splitIgnored; delete u.splitCancelled; }   // סימונים של ההמרה — לא נשמרים
  mutate(units);
  values.units = units;
  onChange(values);
}
function setPath(units, path, value) {
  const keys = path.split('.');
  let o = units;
  for (const k of keys.slice(0, -1)) o = (o[k] = o[k] && typeof o[k] === 'object' ? o[k] : {});
  o[keys[keys.length - 1]] = value;
}
const getPath = (units, path) => path.split('.').reduce((o, k) => (o ? o[k] : undefined), units);

document.addEventListener('toggle', (e) => {
  const d = e.target;
  if (!(d instanceof HTMLDetailsElement) || !d.dataset.key) return;
  const box = editorOf(d); if (!box) return;
  const set = new Set((box.dataset.open || '').split(',').filter(Boolean));
  if (d.open) set.add(d.dataset.key); else set.delete(d.dataset.key);
  box.dataset.open = [...set].join(',');
}, true);

document.addEventListener('change', (e) => {
  const t = e.target;
  if (!t.dataset?.u) return;
  const box = editorOf(t); if (!box) return;
  const path = t.dataset.u;
  let v = t.value;
  if (t.type === 'number') v = v === '' ? null : Number(v);
  else if (/\.leaves$/.test(path)) v = v === 'auto' ? 'auto' : Number(v);
  commit(box, (units) => {
    setPath(units, path, v);
    // קומה שקיבלה מגירות בפעם הראשונה — ברירת מחדל סבירה
    if (/\.interior\.type$/.test(path) && v === 'drawers') { const it = getPath(units, path.replace(/\.type$/, '')); it.drawers = it.drawers || { count: 3, slide: 'hw:slide-std', handle: 'hw:handle-bar-128' }; }
    if (/\.interior\.type$/.test(path) && v === 'shelves') { const it = getPath(units, path.replace(/\.type$/, '')); it.shelves = it.shelves || { count: 2, material: 'same', mode: 'adjustable' }; }
  });
});

document.addEventListener('click', (e) => {
  const b = e.target.closest('button');
  if (!b) return;
  const box = editorOf(b); if (!box) return;
  if (b.dataset.step) {
    const path = b.dataset.step, d = Number(b.dataset.d);
    commit(box, (units) => setPath(units, path, Math.max(0, (Number(getPath(units, path)) || 0) + d)));
    return;
  }
  const act = b.dataset.act; if (!act) return;
  const i = Number(b.dataset.i), j = Number(b.dataset.j);
  commit(box, (units) => {
    const fl = units[i].floors;
    if (act === 'add') { const top = clone(fl[fl.length - 1]); top.height = null; fl.push(top); }
    if (act === 'dup') { const c = clone(fl[j]); fl.splice(j + 1, 0, c); }
    if (act === 'del' && fl.length > 1) fl.splice(j, 1);
    if (act === 'up' && j < fl.length - 1) [fl[j], fl[j + 1]] = [fl[j + 1], fl[j]];
    if (act === 'down' && j > 0) [fl[j], fl[j - 1]] = [fl[j - 1], fl[j]];
    // שתי קומות שאחת מהן נעוצה ושתיהן נעוצות — האחרונה חוזרת לאוטומטי (שתמיד יהיה מי שממלא)
    if (fl.length > 1 && fl.every((f) => Number.isFinite(f.height) && f.height > 0)) fl[fl.length - 1].height = null;
  });
});
