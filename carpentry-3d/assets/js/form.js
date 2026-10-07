// הטופס של הנגר — נבנה מרשימת הפרמטרים של התבנית, לא כתוב ביד.
//
// כל פרמטר הופך לשדה לפי סוגו: mm ו-int לשדה מספר עם טווח, enum ו-material
// לרשימת בחירה. השדות מקובצים לפי `group`, ושדה עם `showIf` מוצג רק כשהתנאי
// מתקיים. שינוי בכל שדה קורא ל-onChange עם הערכים המעודכנים.

import { allParams, visible, optionsFor } from './model/index.js';
import { material } from './model/materials.js';
import { resolveShares, editShare, normalizeLayout, layoutIsEmpty, MIN_SHARE } from './model/layout.js';

export function renderForm(root, tpl, values, onChange) {
  root.innerHTML = '';
  const groups = new Map();
  for (const p of allParams(tpl)) {
    if (!groups.has(p.group)) groups.set(p.group, []);
    groups.get(p.group).push(p);
  }
  const fields = new Map();

  for (const [name, params] of groups) {
    const sec = document.createElement('details');
    sec.className = 'group';
    sec.open = name !== 'חיבורים';
    sec.innerHTML = `<summary>${name}</summary>`;
    for (const p of params) {
      const row = document.createElement('label');
      row.className = 'field';
      row.dataset.key = p.key;
      const unit = p.type === 'mm' ? '<span class="unit">מ"מ</span>' : '';
      row.innerHTML = `<span class="field__label">${p.label}${unit}</span>`;
      let input;
      if (p.type === 'json' && p.editor === 'columns') {
        // עורך העמודות: לא שדה — ווידג'ט שמצייר את עצמו מחדש לפי שאר הערכים
        row.className = 'field field--wide';
        row.innerHTML = `<span class="field__label">${p.label}</span>`;
        const box = document.createElement('div');
        box.className = 'colsedit';
        row.appendChild(box);
        if (p.hint) row.insertAdjacentHTML('beforeend', `<small class="hint">${p.hint}</small>`);
        sec.appendChild(row);
        fields.set(p.key, { p, row, input: null, widget: () => renderColumnsEditor(box, tpl, values, p.key, () => { refresh(); onChange(values); }) });
        continue;
      }
      if (p.type === 'mm' || p.type === 'int') {
        input = document.createElement('input');
        input.type = 'number';
        input.inputMode = 'numeric';
        input.min = p.min; input.max = p.max; input.step = 1;
        input.dataset.step = p.type === 'mm' ? 10 : 1;   // החיצים קופצים ב-10 מ"מ; בהקלדה כל מספר שלם
        input.value = values[p.key];
      } else {
        input = document.createElement('select');
        const opts = optionsFor(p);
        // חומר שנבחר ואחר כך הושבת: נשאר ברשימה, מסומן, כדי שהפרויקט לא יקפוץ לחומר אחר בשקט.
        if (p.type === 'material' && !opts.some((o) => o.id === values[p.key])) {
          opts.push({ id: values[p.key], name: `${material(values[p.key]).name} (מושבת)` });
        }
        for (const o of opts) {
          const opt = document.createElement('option');
          opt.value = o.id; opt.textContent = o.name;
          opt.selected = values[p.key] === o.id;
          input.appendChild(opt);
        }
      }
      input.name = p.key;
      row.appendChild(input);
      if (p.hint) row.insertAdjacentHTML('beforeend', `<small class="hint">${p.hint}</small>`);
      sec.appendChild(row);
      fields.set(p.key, { p, row, input });

      // מספרים מתעדכנים תוך כדי הקלדה; הערך מוצמד לטווח במודל, לא כאן,
      // כדי שהמשתמש יוכל להקליד "12" בדרך ל-"1200" בלי שהשדה יקפוץ.
      input.addEventListener(input.tagName === 'SELECT' ? 'change' : 'input', () => {
        values[p.key] = input.tagName === 'SELECT' ? input.value : input.value === '' ? values[p.key] : Number(input.value);
        refresh();
        onChange(values);
      });
    }
    root.appendChild(sec);
  }

  function refresh() {
    for (const { p, row, widget } of fields.values()) { row.hidden = !visible(p, values); if (widget && !row.hidden) widget(); }
  }
  /** מציג בשדות את הערכים כפי שהמודל הצמיד אותם (אחרי clamp). */
  function sync(clamped) {
    for (const { p, input } of fields.values()) {
      if (!input) continue;                                // ווידג'ט — מצייר את עצמו ב-refresh
      if (document.activeElement === input) continue;   // לא לדרוס שדה שמקלידים בו
      if (input.value !== String(clamped[p.key])) input.value = clamped[p.key];
    }
  }
  refresh();
  return { sync };
}

/**
 * עורך העמודות: רוחב לכל עמודה, מספר מדפים לכל עמודה, וגובה כל תא.
 *
 * כל מספר הוא או "אוטומטי" (מתחלק בשווה במה שנשאר) או "נעוץ" (המשתמש קבע;
 * שינוי אוטומטי לא נוגע בו עד "איפוס"). שינוי של פריט אחד נלקח לפי המתג:
 * מכל השאר בשווה, מהשכן הבא (העמודה שמימין / התא שמעליו) או מהשכן הקודם;
 * בקצה, בלי שכן כזה, נופל לבד ל"בשווה". הלוגיקה עצמה ב-model/layout.js.
 */
function renderColumnsEditor(box, tpl, values, key, onChange) {
  if (!tpl.columnSpace) { box.textContent = ''; return; }
  const space = tpl.columnSpace(values);
  const layout = normalizeLayout(values[key], space.columns);
  const colMode = box.dataset.colMode || 'even', gapMode = box.dataset.gapMode || 'even';
  const openCols = new Set((box.dataset.open || '').split(',').filter(Boolean).map(Number));
  const widths = resolveShares(space.innerW, layout.widths);
  const radio = (name, cur, opts) => `<div class="colsedit__mode"><span class="muted">${opts.title}</span>${opts.items.map(([v, t]) => `<label><input type="radio" name="${name}-${key}" value="${v}" ${cur === v ? 'checked' : ''}> ${t}</label>`).join('')}</div>`;
  const auto = '<i class="colsedit__auto" title="אוטומטי — מתחלק בשווה במה שנשאר">אוטו</i>';
  const reset = (attrs) => `<button type="button" class="colsedit__reset" ${attrs} title="חזרה לאוטומטי">איפוס</button>`;
  const html = [];
  html.push(radio('colsedit-cmode', colMode, { title: 'שינוי רוחב עמודה נלקח:', items: [['even', 'מכל העמודות בשווה'], ['next', 'מהעמודה שמימין'], ['prev', 'מהעמודה שמשמאל']] }));
  html.push(radio('colsedit-gmode', gapMode, { title: 'שינוי גובה תא נלקח:', items: [['even', 'מכל התאים בשווה'], ['next', 'מהתא שמעליו'], ['prev', 'מהתא שמתחתיו']] }));
  for (let i = 0; i < space.columns; i++) {
    const c = layout.cols[i] || {};
    const n = Number.isInteger(c.shelves) ? c.shelves : space.defaultShelves;
    const free = space.innerH - n * space.shelfT;
    const pins = Array.isArray(c.gaps) && c.gaps.length === n + 1 ? c.gaps : Array.from({ length: n + 1 }, () => null);
    const gaps = resolveShares(free, pins);
    const wPinned = layout.widths[i] !== null;
    const custom = wPinned || Number.isInteger(c.shelves) || pins.some((g) => g !== null);
    html.push(`<details class="colsedit__col" ${openCols.has(i) ? 'open' : ''} data-col="${i}">
      <summary>עמודה ${i + 1} <span class="muted">· ${Math.round(widths[i])} מ"מ · ${n} מדפים${custom ? ' · מותאם' : ''}</span></summary>
      <div class="colsedit__row"><span>רוחב</span>
        <input type="number" step="10" min="${MIN_SHARE}" data-col="${i}" data-width="1" value="${Math.round(widths[i])}"><i>מ"מ</i>
        ${wPinned ? reset(`data-col="${i}" data-reset="width"`) : auto}</div>
      <div class="colsedit__row"><span>מדפים</span>
        <button type="button" data-col="${i}" data-shelves="${n - 1}" ${n <= 0 ? 'disabled' : ''}>−</button><b>${n}</b><button type="button" data-col="${i}" data-shelves="${n + 1}" ${n >= 15 ? 'disabled' : ''}>+</button>
        ${Number.isInteger(c.shelves) || pins.some((g) => g !== null) ? reset(`data-col="${i}" data-reset="shelves"`) : ''}</div>
      ${space.split ? '<small class="muted">העמודה מפוצלת — הגבהים מתחלקים לפי הפיצול</small>' : `<div class="colsedit__gaps">${gaps.map((g, gi) => `<label><span>תא ${gi + 1}${gi === gaps.length - 1 ? ' (עליון)' : gi === 0 ? ' (תחתון)' : ''}</span><input type="number" step="10" min="${MIN_SHARE}" data-col="${i}" data-gap="${gi}" value="${Math.round(g)}"><i>מ"מ</i>${pins[gi] !== null ? reset(`data-col="${i}" data-reset="gap" data-gap="${gi}"`) : auto}</label>`).reverse().join('')}</div>`}
    </details>`);
  }
  box.innerHTML = html.join('');
  box.querySelectorAll('details.colsedit__col').forEach((d) => d.addEventListener('toggle', () => {
    const set = new Set((box.dataset.open || '').split(',').filter(Boolean).map(Number));
    d.open ? set.add(Number(d.dataset.col)) : set.delete(Number(d.dataset.col));
    box.dataset.open = [...set].join(',');
  }));
  const commit = (next) => { values[key] = layoutIsEmpty(next) ? null : next; onChange(); };
  box.onchange = (e) => {
    const t = e.target;
    if (t.name === `colsedit-cmode-${key}`) { box.dataset.colMode = t.value; return; }
    if (t.name === `colsedit-gmode-${key}`) { box.dataset.gapMode = t.value; return; }
    const i = Number(t.dataset.col);
    if (t.dataset.width) {
      const next = { ...layout, widths: editShare(space.innerW, layout.widths, i, Number(t.value) || widths[i], box.dataset.colMode || 'even') };
      commit(next);
    } else if (t.dataset.gap !== undefined) {
      const gi = Number(t.dataset.gap);
      const c = layout.cols[i] || {};
      const n = Number.isInteger(c.shelves) ? c.shelves : space.defaultShelves;
      const free = space.innerH - n * space.shelfT;
      const pins = Array.isArray(c.gaps) && c.gaps.length === n + 1 ? c.gaps : Array.from({ length: n + 1 }, () => null);
      const cur = resolveShares(free, pins);
      const next = { ...layout, cols: { ...layout.cols, [i]: { shelves: n, gaps: editShare(free, pins, gi, Number(t.value) || cur[gi], box.dataset.gapMode || 'even') } } };
      commit(next);
    }
  };
  box.onclick = (e) => {
    const b = e.target.closest('button[data-col]');
    if (!b) return;
    const i = Number(b.dataset.col);
    const next = { ...layout, widths: layout.widths.slice(), cols: { ...layout.cols } };
    if (b.dataset.reset === 'width') next.widths[i] = null;
    else if (b.dataset.reset === 'shelves') delete next.cols[i];
    else if (b.dataset.reset === 'gap') {
      const c = { ...next.cols[i], gaps: next.cols[i].gaps.slice() };
      c.gaps[Number(b.dataset.gap)] = null;
      if (c.gaps.every((g) => g === null)) delete c.gaps;
      next.cols[i] = c;
    } else if (b.dataset.shelves !== undefined) next.cols[i] = { shelves: Number(b.dataset.shelves) };   // ספירה חדשה — התאים חוזרים לאוטומטי
    else return;
    commit(next);
  };
}
