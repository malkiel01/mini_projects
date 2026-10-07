// הטופס של הנגר — נבנה מרשימת הפרמטרים של התבנית, לא כתוב ביד.
//
// כל פרמטר הופך לשדה לפי סוגו: mm ו-int לשדה מספר עם טווח, enum ו-material
// לרשימת בחירה. השדות מקובצים לפי `group`, ושדה עם `showIf` מוצג רק כשהתנאי
// מתקיים. שינוי בכל שדה קורא ל-onChange עם הערכים המעודכנים.

import { allParams, visible, optionsFor } from './model/index.js';
import { material } from './model/materials.js';
import { resolveShares, editShare, sectionLayout, layoutIsEmpty, cellsOf, effectivePins, MIN_SHARE } from './model/layout.js';

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
 * עורך החלוקה: לכל "קבוצה" שהתבנית מתארת ב-columnSpace(v).sections (עמודות
 * ספרייה, ארונות של קיר במטבח, שורות של שידה…) — מידה לכל פריט (רוחב, או
 * גובה לשורות), ולפריט שניתן לערוך גם סוג (דלתות/מגירות), מספר מדפים או
 * מגירות, וגובה כל תא.
 *
 * כל מספר הוא או "אוטומטי" (מתחלק בשווה במה שנשאר) או "נעוץ" (המשתמש קבע;
 * שינוי אוטומטי לא נוגע בו עד "איפוס"). שינוי של פריט אחד נלקח לפי המתג:
 * מכל השאר בשווה, מהשכן הבא או מהשכן הקודם; בקצה, בלי שכן כזה, נופל לבד
 * ל"בשווה". הלוגיקה עצמה ב-model/layout.js; כאן רק הציור והאירועים.
 */
function renderColumnsEditor(box, tpl, values, key, onChange) {
  if (!tpl.columnSpace) { box.textContent = ''; return; }
  const spec = tpl.columnSpace(values);
  const sections = spec.sections || [];
  const openSet = new Set((box.dataset.open || '').split(',').filter(Boolean));
  const cellMode = box.dataset.gapMode || 'even';
  const auto = '<i class="colsedit__auto" title="אוטומטי — מתחלק בשווה במה שנשאר">אוטו</i>';
  const reset = (attrs) => `<button type="button" class="colsedit__reset" ${attrs} title="חזרה לאוטומטי">איפוס</button>`;
  const radio = (name, cur, title, items) => `<div class="colsedit__mode"><span class="muted">${title}</span>${items.map(([v, t]) => `<label><input type="radio" name="${name}-${key}" value="${v}" ${cur === v ? 'checked' : ''}> ${t}</label>`).join('')}</div>`;
  const anyCells = sections.some((sec) => sec.items.some((it) => it.editable));
  const html = [];
  if (anyCells) html.push(radio('colsedit-gmode', cellMode, 'שינוי גובה תא נלקח:', [['even', 'מכל התאים בשווה'], ['next', 'מהתא שמעליו'], ['prev', 'מהתא שמתחתיו']]));
  const state = {};   // לכל קבוצה: הפריסה המנורמלת והמידות הפתורות — לשימוש האירועים
  for (const sec of sections) {
    const n = sec.items.length;
    const lay = sectionLayout(values[key], sec.key, n);
    const pins = effectivePins(lay.widths, sec.items);
    const sizes = resolveShares(sec.total, pins);
    state[sec.key] = { lay, pins, sizes, sec };
    const secMode = box.dataset[`mode_${sec.key}`] || 'even';
    html.push(`<div class="colsedit__section" data-sec="${sec.key}">`);
    if (sec.title) html.push(`<div class="colsedit__title">${sec.title}</div>`);
    if (n > 1) html.push(radio(`colsedit-mode-${sec.key}`, secMode, `שינוי ${sec.sizeLabel || 'רוחב'} נלקח:`, [['even', `מכל ${sec.allLabel || 'השאר'} בשווה`], ['next', sec.modes?.next || 'מהשכן הבא'], ['prev', sec.modes?.prev || 'מהשכן הקודם']]));
    const order = sec.reverse ? [...sec.items.keys()].reverse() : [...sec.items.keys()];
    for (const i of order) {
      const it = sec.items[i], c = lay.cols[i] || {};
      const id = `${sec.key}:${i}`;
      const pinned = lay.widths[i] !== null;
      const kind = c.kind || it.kind || null;
      const kindObj = it.kinds?.find((k) => k.id === kind);
      const editable = it.editable && (kindObj ? kindObj.editable !== false : true);
      const cellsOfKind = kindObj?.cellsOf || it.cellsOf || 'shelves';
      const itemFor = { ...it, cellsOf: cellsOfKind, innerH: kindObj?.innerH ?? it.innerH };
      const count = Number.isInteger(c.shelves) ? c.shelves : (kindObj?.defaultCount ?? it.defaultCount ?? 0);
      const { cells, free } = cellsOf(itemFor, count);
      const cpins = Array.isArray(c.gaps) && c.gaps.length === cells ? c.gaps : Array.from({ length: cells }, () => null);
      const gaps = resolveShares(free, cpins);
      const custom = pinned || Number.isInteger(c.shelves) || cpins.some((g) => g !== null) || (c.kind && c.kind !== it.kind);
      const countLabel = cellsOfKind === 'drawers' ? 'מגירות' : 'מדפים';
      const cellLabel = cellsOfKind === 'drawers' ? 'מגירה' : 'תא';
      const countMin = cellsOfKind === 'drawers' ? 1 : 0, countMax = it.countMax ?? 15;
      html.push(`<details class="colsedit__col" ${openSet.has(id) ? 'open' : ''} data-id="${id}">
        <summary>${it.label} <span class="muted">· ${Math.round(sizes[i])} מ"מ${kindObj ? ` · ${kindObj.name}` : ''}${editable ? ` · ${count} ${countLabel}` : ''}${custom ? ' · מותאם' : ''}</span></summary>
        <div class="colsedit__row"><span>${sec.sizeLabel || 'רוחב'}</span>
          <input type="number" step="10" min="${MIN_SHARE}" data-sec="${sec.key}" data-col="${i}" data-width="1" value="${Math.round(sizes[i])}"><i>מ"מ</i>
          ${pinned ? reset(`data-sec="${sec.key}" data-col="${i}" data-reset="width"`) : auto}</div>
        ${it.kinds ? `<div class="colsedit__row"><span>סוג</span><select data-sec="${sec.key}" data-col="${i}" data-kind="1">${it.kinds.map((k) => `<option value="${k.id}" ${k.id === kind ? 'selected' : ''}>${k.name}</option>`).join('')}</select>${c.kind && c.kind !== it.kind ? reset(`data-sec="${sec.key}" data-col="${i}" data-reset="kind"`) : ''}</div>` : ''}
        ${!editable ? (it.note ? `<small class="muted">${it.note}</small>` : '') : `<div class="colsedit__row"><span>${countLabel}</span>
          <button type="button" data-sec="${sec.key}" data-col="${i}" data-shelves="${count - 1}" ${count <= countMin ? 'disabled' : ''}>−</button><b>${count}</b><button type="button" data-sec="${sec.key}" data-col="${i}" data-shelves="${count + 1}" ${count >= countMax ? 'disabled' : ''}>+</button>
          ${Number.isInteger(c.shelves) || cpins.some((g) => g !== null) ? reset(`data-sec="${sec.key}" data-col="${i}" data-reset="shelves"`) : ''}</div>
        <div class="colsedit__gaps">${gaps.map((g, gi) => `<label><span>${cellLabel} ${gi + 1}${gi === gaps.length - 1 ? ' (עליון)' : gi === 0 ? ' (תחתון)' : ''}</span><input type="number" step="10" min="${MIN_SHARE}" data-sec="${sec.key}" data-col="${i}" data-gap="${gi}" value="${Math.round(g)}"><i>מ"מ</i>${cpins[gi] !== null ? reset(`data-sec="${sec.key}" data-col="${i}" data-reset="gap" data-gap="${gi}"`) : auto}</label>`).reverse().join('')}</div>`}
      </details>`);
    }
    html.push('</div>');
  }
  // שדה שבפוקוס בתוך העורך: לשחרר לפני ההחלפה, אחרת ה-blur באמצע ההחלפה מבלבל את ה-DOM
  if (box.contains(document.activeElement)) document.activeElement.blur();
  box.innerHTML = html.join('');
  box.querySelectorAll('details.colsedit__col').forEach((d) => d.addEventListener('toggle', () => {
    const set = new Set((box.dataset.open || '').split(',').filter(Boolean));
    d.open ? set.add(d.dataset.id) : set.delete(d.dataset.id);
    box.dataset.open = [...set].join(',');
  }));

  // כתיבה חזרה: תמיד בפורמט הקבוצות; קבוצה ריקה נמחקת, ובלי קבוצות — null.
  const commit = (secKey, nextSec) => {
    const all = {};
    for (const k of Object.keys(state)) all[k] = state[k].lay;
    all[secKey] = nextSec;
    const out = {};
    for (const [k, l] of Object.entries(all)) if (!layoutIsEmpty(l)) out[k] = l;
    values[key] = Object.keys(out).length ? { sections: out } : null;
    onChange();
  };
  const cellCtx = (secKey, i) => {
    const { lay, sec } = state[secKey];
    const it = sec.items[i], c = lay.cols[i] || {};
    const kind = c.kind || it.kind || null;
    const kindObj = it.kinds?.find((k) => k.id === kind);
    const itemFor = { ...it, cellsOf: kindObj?.cellsOf || it.cellsOf || 'shelves', innerH: kindObj?.innerH ?? it.innerH };
    const count = Number.isInteger(c.shelves) ? c.shelves : (kindObj?.defaultCount ?? it.defaultCount ?? 0);
    const { cells, free } = cellsOf(itemFor, count);
    const cpins = Array.isArray(c.gaps) && c.gaps.length === cells ? c.gaps : Array.from({ length: cells }, () => null);
    return { lay, c, count, free, cpins };
  };
  box.onchange = (e) => {
    const t = e.target;
    if (t.name === `colsedit-gmode-${key}`) { box.dataset.gapMode = t.value; return; }
    const m = t.name && t.name.match(new RegExp(`^colsedit-mode-(.+)-${key}$`));
    if (m) { box.dataset[`mode_${m[1]}`] = t.value; return; }
    const secKey = t.dataset.sec, i = Number(t.dataset.col);
    if (!secKey) return;
    const { lay, pins, sizes, sec } = state[secKey];
    if (t.dataset.width) {
      const next = editShare(sec.total, pins, i, Number(t.value) || sizes[i], box.dataset[`mode_${secKey}`] || 'even');
      // נעיצה שנובעת רק מברירת מחדל של פריט אחר נשארת ברירת מחדל (לא נכתבת)
      const widths = next.map((w, k) => (w === pins[k] && lay.widths[k] === null ? null : w));
      commit(secKey, { ...lay, widths });
    } else if (t.dataset.kind) {
      commit(secKey, { ...lay, cols: { ...lay.cols, [i]: { kind: t.value } } });   // סוג חדש — מספר ותאים חוזרים לברירת המחדל
    } else if (t.dataset.gap !== undefined) {
      const gi = Number(t.dataset.gap);
      const { c, count, free, cpins } = cellCtx(secKey, i);
      const cur = resolveShares(free, cpins);
      commit(secKey, { ...lay, cols: { ...lay.cols, [i]: { ...c, shelves: count, gaps: editShare(free, cpins, gi, Number(t.value) || cur[gi], box.dataset.gapMode || 'even') } } });
    }
  };
  box.onclick = (e) => {
    const b = e.target.closest('button[data-sec]');
    if (!b) return;
    const secKey = b.dataset.sec, i = Number(b.dataset.col);
    const { lay } = state[secKey];
    const next = { ...lay, widths: lay.widths.slice(), cols: { ...lay.cols } };
    const c = { ...(next.cols[i] || {}) };
    if (b.dataset.reset === 'width') next.widths[i] = null;
    else if (b.dataset.reset === 'kind') { delete c.kind; delete c.shelves; delete c.gaps; }
    else if (b.dataset.reset === 'shelves') { delete c.shelves; delete c.gaps; }
    else if (b.dataset.reset === 'gap') {
      c.gaps = (c.gaps || []).slice();
      c.gaps[Number(b.dataset.gap)] = null;
      if (c.gaps.every((g) => g === null)) delete c.gaps;
    } else if (b.dataset.shelves !== undefined) { c.shelves = Number(b.dataset.shelves); delete c.gaps; }   // ספירה חדשה — התאים חוזרים לאוטומטי
    else return;
    if (b.dataset.reset !== 'width') { if (Object.keys(c).length) next.cols[i] = c; else delete next.cols[i]; }
    commit(secKey, next);
  };
}
