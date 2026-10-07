// הטופס של הנגר — נבנה מרשימת הפרמטרים של התבנית, לא כתוב ביד.
//
// כל פרמטר הופך לשדה לפי סוגו: mm ו-int לשדה מספר עם טווח, enum ו-material
// לרשימת בחירה. השדות מקובצים לפי `group`, ושדה עם `showIf` מוצג רק כשהתנאי
// מתקיים. שינוי בכל שדה קורא ל-onChange עם הערכים המעודכנים.

import { allParams, visible, optionsFor } from './model/index.js';
import { material } from './model/materials.js';

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
 * עורך העמודות: לכל עמודה מספר מדפים, ומתחתיו גבהי התאים (מלמטה למעלה).
 * שינוי גובה של תא אחד נלקח או מכל שאר התאים בשווה, או מהתא השכן (מעליו)
 * — לפי המתג. הסכום תמיד שווה לגובה הפנוי של העמודה. עמודה שלא נגעו בה
 * (אין רשומה) עוקבת אחרי "מדפים בכל עמודה" ומחולקת שווה.
 */
function renderColumnsEditor(box, tpl, values, key, onChange) {
  if (!tpl.columnSpace) { box.textContent = ''; return; }
  const space = tpl.columnSpace(values);
  const layout = values[key] && typeof values[key] === 'object' ? values[key] : {};
  const mode = box.dataset.mode || 'even';
  const openCol = box.dataset.open != null ? Number(box.dataset.open) : null;
  const html = [];
  html.push(`<div class="colsedit__mode"><span class="muted">שינוי גובה של תא נלקח:</span>
    <label><input type="radio" name="colsedit-mode-${key}" value="even" ${mode === 'even' ? 'checked' : ''}> מכל התאים בשווה</label>
    <label><input type="radio" name="colsedit-mode-${key}" value="next" ${mode === 'next' ? 'checked' : ''}> מהתא שמעליו</label></div>`);
  for (let i = 0; i < space.columns; i++) {
    const c = layout[i] || {};
    const n = Number.isInteger(c.shelves) ? c.shelves : space.defaultShelves;
    const free = space.innerH - n * space.shelfT;
    const gaps = Array.isArray(c.gaps) && c.gaps.length === n + 1 ? normalize(c.gaps, free) : Array.from({ length: n + 1 }, () => free / (n + 1));
    const custom = Number.isInteger(c.shelves) || Array.isArray(c.gaps);
    html.push(`<details class="colsedit__col" ${openCol === i ? 'open' : ''} data-col="${i}">
      <summary>עמודה ${i + 1} <span class="muted">· ${n} מדפים${custom ? ' · מותאם' : ''}</span></summary>
      <div class="colsedit__row"><span>מדפים</span>
        <button type="button" data-col="${i}" data-shelves="${n - 1}" ${n <= 0 ? 'disabled' : ''}>−</button><b>${n}</b><button type="button" data-col="${i}" data-shelves="${n + 1}" ${n >= 15 ? 'disabled' : ''}>+</button>
        ${custom ? `<button type="button" class="colsedit__reset" data-col="${i}" data-reset="1">איפוס</button>` : ''}</div>
      ${space.split ? '<small class="muted">העמודה מפוצלת — הגבהים מתחלקים לפי הפיצול</small>' : `<div class="colsedit__gaps">${gaps.map((g, gi) => `<label><span>תא ${gi + 1}${gi === gaps.length - 1 ? ' (עליון)' : gi === 0 ? ' (תחתון)' : ''}</span><input type="number" step="10" min="50" data-col="${i}" data-gap="${gi}" value="${Math.round(g)}"><i>מ"מ</i></label>`).reverse().join('')}</div>`}
    </details>`);
  }
  box.innerHTML = html.join('');
  box.onchange = (e) => {
    const t = e.target;
    if (t.name === `colsedit-mode-${key}`) { box.dataset.mode = t.value; return; }
    if (t.dataset.gap !== undefined) {
      const i = Number(t.dataset.col), gi = Number(t.dataset.gap);
      const c = layout[i] || {};
      const n = Number.isInteger(c.shelves) ? c.shelves : space.defaultShelves;
      const free = space.innerH - n * space.shelfT;
      const gaps = Array.isArray(c.gaps) && c.gaps.length === n + 1 ? normalize(c.gaps, free) : Array.from({ length: n + 1 }, () => free / (n + 1));
      const want = Math.max(50, Math.min(free - 50 * n, Number(t.value) || gaps[gi]));
      const delta = want - gaps[gi];
      const next = gaps.slice();
      next[gi] = want;
      if ((box.dataset.mode || 'even') === 'next' && gi + 1 < next.length) {
        next[gi + 1] = Math.max(50, next[gi + 1] - delta);
      } else {
        const others = next.map((_, k) => k).filter((k) => k !== gi);
        const share = delta / others.length;
        for (const k of others) next[k] = Math.max(50, next[k] - share);
      }
      values[key] = { ...layout, [i]: { shelves: n, gaps: normalize(next, free).map((g) => Math.round(g)) } };
      box.dataset.open = i;
      onChange();
    }
  };
  box.onclick = (e) => {
    const b = e.target.closest('button[data-col]');
    if (!b) return;
    const i = Number(b.dataset.col);
    if (b.dataset.reset) { const l = { ...layout }; delete l[i]; values[key] = Object.keys(l).length ? l : null; }
    else values[key] = { ...layout, [i]: { shelves: Number(b.dataset.shelves) } };   // ספירה חדשה — חלוקה שווה
    box.dataset.open = i;
    onChange();
  };
}
function normalize(gaps, free) {
  const sum = gaps.reduce((a, b) => a + b, 0) || 1;
  return gaps.map((g) => (g * free) / sum);
}
