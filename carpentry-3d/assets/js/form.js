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
    for (const { p, row } of fields.values()) row.hidden = !visible(p, values);
  }
  /** מציג בשדות את הערכים כפי שהמודל הצמיד אותם (אחרי clamp). */
  function sync(clamped) {
    for (const { p, input } of fields.values()) {
      if (document.activeElement === input) continue;   // לא לדרוס שדה שמקלידים בו
      if (input.value !== String(clamped[p.key])) input.value = clamped[p.key];
    }
  }
  refresh();
  return { sync };
}
