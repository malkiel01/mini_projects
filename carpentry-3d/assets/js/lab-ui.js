// מסך "🧪 מעבדת מוצרים" — למנהל בלבד.
//
// כל מוצר (תבנית מהקוד) נפרש לטבלה: כל פרמטר עם שמו, ברירת המחדל, הטווח,
// הקבוצה, ההסבר, האם הוא מוצג לנגר ומתי (התנאי, בעברית). בנוסף — שעות העבודה
// של המוצר, והגבולות הכלליים שמהם נדלקות האזהרות (מוטת מדף, רוחב דלת…).
//
// מה שנערך כאן הוא טיוטה: התצוגה בתלת מימד והאזהרות מתעדכנות מיד, אבל רק
// "שמירה" כותבת לשרת (rules-save) ומחילה על כולם. סגירה בלי שמירה — חזרה
// למה שנשמר. כל שדה ששונה מסומן, ו-↺ מחזיר אותו לערך שבקוד.

import { TEMPLATES, allParams, optionsFor, build } from './model/index.js';
import { applyRules, activeRules, originalOf, originalLimits, describeShowIf, LIMIT_INFO } from './model/rules.js';
import { LIMITS } from './model/templates/common.js';
import { createViewer } from './viewer.js';
import { api } from './store.js';

const esc = (s) => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
const clone = (x) => JSON.parse(JSON.stringify(x || {}));
const NUM = ['mm', 'int', 'deg'];
const UNIT = { mm: 'מ"מ', deg: '°', int: '' };

export function createLabUI(root, { onChange, onError, toast, requestClose }) {
  let draft = {};        // ההתאמות כפי שהן במסך (טרם נשמרו)
  let saved = {};        // מה שבשרת — לחזרה בסגירה בלי שמירה
  let sel = 'bookcase';  // תבנית, או '_limits'
  let viewer = null;
  let dirty = false;

  root.innerHTML = `
    <div class="drawer__bar"><strong>🧪 מעבדת מוצרים</strong><span class="muted lab__hint">מה שהמערכת יודעת על כל מוצר — גלוי וניתן לעריכה</span>
      <span class="lab__state muted"></span>
      <button type="button" class="btn btn--accent lab__save" disabled>שמירה</button>
      <button type="button" class="btn lab__close">סגירה</button></div>
    <div class="acc__body lab__body">
      <div class="acc__list lab__list"></div>
      <div class="lab__editor">
        <div class="lab__top">
          <div class="lab__stage"><canvas class="acc__preview lab__preview"></canvas></div>
          <div class="lab__side"></div>
        </div>
        <div class="lab__table"></div>
      </div>
    </div>`;
  const $ = (s) => root.querySelector(s);

  /** נקרא אחרי שהמגירה נפתחה. */
  function open() {
    saved = clone(activeRules());
    draft = clone(saved);
    dirty = false;
    render();
  }
  $('.lab__close').addEventListener('click', () => {
    if (dirty && !confirm('יש שינויים שלא נשמרו. לסגור בלי לשמור?')) return;
    requestClose();
  });
  // המגירה נסגרה (כפתור, "אחורה" בטלפון) — טיוטה שלא נשמרה חוזרת למה שנשמר
  new MutationObserver(() => {
    if (root.classList.contains('is-open') || !dirty) return;
    dirty = false;
    applyRules(saved);
    onChange();
  }).observe(root, { attributes: true, attributeFilter: ['class'] });

  // ---- טיוטה: רק מה ששונה מהקוד נשמר ----
  const tplRules = (key) => (draft[key] = draft[key] && typeof draft[key] === 'object' && !Array.isArray(draft[key]) ? draft[key] : {});
  function setParam(key, pkey, field, value) {
    const o = originalOf(key).params[pkey];
    const r = tplRules(key);
    r.params = r.params && !Array.isArray(r.params) ? r.params : {};
    const e = (r.params[pkey] = r.params[pkey] || {});
    const same = value === '' || value === null || value === undefined || String(value) === String(o[field] ?? (field === 'hidden' ? false : ''));
    if (same) delete e[field]; else e[field] = value;
    if (!Object.keys(e).length) delete r.params[pkey];
    tidy(key);
  }
  function setHours(key, value) {
    const r = tplRules(key);
    if (value === '' || Number(value) === originalOf(key).laborHours) delete r.laborHours; else r.laborHours = Number(value);
    tidy(key);
  }
  function setLimit(k, value) {
    const lim = (draft._limits = draft._limits && !Array.isArray(draft._limits) ? draft._limits : {});
    if (value === '' || Number(value) === originalLimits()[k]) delete lim[k]; else lim[k] = Number(value);
    if (!Object.keys(lim).length) delete draft._limits;
  }
  function tidy(key) {
    const r = draft[key];
    if (r?.params && !Object.keys(r.params).length) delete r.params;
    if (r && !Object.keys(r).length) delete draft[key];
  }
  const changes = (key) => {
    if (key === '_limits') return Object.keys(draft._limits || {}).length;
    const r = draft[key] || {};
    return Object.values(r.params || {}).reduce((s, e) => s + Object.keys(e).length, 0) + (r.laborHours !== undefined ? 1 : 0);
  };
  function touch() {
    dirty = true;
    applyRules(draft);
    $('.lab__save').disabled = false;
    $('.lab__state').textContent = '● שינויים שטרם נשמרו';
    renderList();
    renderEditor();
    renderPreview();
  }

  $('.lab__save').addEventListener('click', async () => {
    try {
      const r = await api('rules-save', { rules: draft });
      applyRules(r.rules);
      saved = clone(activeRules());
      draft = clone(saved);
      dirty = false;
      onChange();
      toast('נשמר — חל על כל הפרויקטים החדשים ועל הטופס');
      render();
    } catch (err) { onError(err); }
  });

  // ---- ציור ----
  function render() {
    applyRules(draft);
    $('.lab__save').disabled = !dirty;
    $('.lab__state').textContent = dirty ? '● שינויים שטרם נשמרו' : '';
    renderList();
    renderEditor();
    renderPreview();
  }
  function renderList() {
    const card = (key, title, sub) => {
      const n = changes(key);
      return `<button type="button" class="acc__card ${sel === key ? 'is-active' : ''}" data-sel="${key}"><strong>${esc(title)}</strong><span class="muted">${esc(sub)}${n ? ` · <b class="lab__n">${n} שינויים</b>` : ''}</span></button>`;
    };
    $('.lab__list').innerHTML = Object.entries(TEMPLATES).map(([k, t]) => card(k, t.name, `${allParams(t).length} פרמטרים · ${t.laborHours} ש׳ עבודה`)).join('')
      + card('_limits', '⚖️ גבולות ואזהרות', `${Object.keys(LIMITS).length} גבולות לכל המוצרים`)
      + '<p class="muted lab__note">שינוי חל על פרויקטים חדשים ועל ברירות המחדל. פרויקט קיים שומר את הערכים שלו. "מוסתר" — הנגר לא רואה את השדה, והמוצר תמיד נבנה עם ברירת המחדל.</p>';
  }
  $('.lab__list').addEventListener('click', (e) => {
    const b = e.target.closest('[data-sel]');
    if (!b) return;
    sel = b.dataset.sel;
    renderList(); renderEditor(); renderPreview();
  });

  // ציור מחדש אחרי כל שינוי — בלי לאבד את השדה שהמנהל עבר אליו (Tab)
  function renderEditor() {
    const a = document.activeElement, row = a && root.contains(a) ? a.closest('[data-p],[data-lim]') : null;
    const sig = row ? [row.dataset.p || row.dataset.lim, a.dataset.f || a.dataset.l || ''] : a && a.dataset?.hours !== undefined ? ['', 'hours'] : null;
    drawEditor();
    if (!sig) return;
    const r = sig[0] ? root.querySelector(`[data-p="${CSS.escape(sig[0])}"],[data-lim="${CSS.escape(sig[0])}"]`) : root;
    const el = sig[1] === 'hours' ? root.querySelector('[data-hours]') : r?.querySelector(`[data-f="${sig[1]}"],[data-l="${sig[1]}"]`);
    el?.focus();
  }
  function drawEditor() {
    if (sel === '_limits') return renderLimits();
    const t = TEMPLATES[sel], o = originalOf(sel), r = draft[sel] || {};
    $('.lab__side').innerHTML = `
      <h3>${esc(t.name)}</h3>
      <label class="acc__field"><span>שעות עבודה</span><input type="number" min="0" step="0.5" data-hours value="${t.laborHours}" class="${r.laborHours !== undefined ? 'is-changed' : ''}"><i>בקוד: ${o.laborHours}</i></label>
      <p class="muted">שעות העבודה נכנסות להצעת המחיר (כפול תעריף השעה) ולסיכום ההרכבה.</p>
      <div class="lab__warns"></div>
      <button type="button" class="btn btn--small" data-reset-tpl ${changes(sel) ? '' : 'disabled'}>↺ כל ${esc(t.name)} — חזרה לקוד</button>`;
    // פרמטרים לפי קבוצה, בסדר הופעתם בטופס
    const groups = [];
    for (const p of allParams(t)) {
      const g = p.group || 'כללי';
      let row = groups.find((x) => x.name === g);
      if (!row) groups.push((row = { name: g, list: [] }));
      row.list.push(p);
    }
    $('.lab__table').innerHTML = groups.map((g) => `
      <h4 class="lab__group">${esc(g.name)}</h4>
      <div class="lab__rows">${g.list.map((p) => paramRow(t, p, o.params[p.key], r.params?.[p.key] || {})).join('')}</div>`).join('');
  }
  function paramRow(t, p, o, e) {
    const ch = (f) => (e[f] !== undefined ? 'is-changed' : '');
    const num = NUM.includes(p.type);
    let def;
    if (num) def = `<input type="number" data-num="1" data-f="default" value="${esc(p.default)}" step="${p.type === 'int' ? 1 : 'any'}" class="${ch('default')}">`;
    else if (p.type === 'json') def = '<span class="muted">מבנה — נערך בטופס</span>';
    else {
      let opts = [];
      try { opts = optionsFor(p); } catch { /* ספרייה טרם נטענה */ }
      if (!opts.some((x) => String(x.id) === String(p.default))) opts = [{ id: p.default, name: String(p.default) }, ...opts];
      def = `<select data-f="default" class="${ch('default')}">${opts.map((x) => `<option value="${esc(x.id)}" ${String(x.id) === String(p.default) ? 'selected' : ''}>${esc(x.name)}</option>`).join('')}</select>`;
    }
    const changed = Object.keys(e).length;
    return `<div class="lab__row ${p.hidden ? 'is-hidden' : ''} ${changed ? 'is-changed-row' : ''}" data-p="${esc(p.key)}">
      <div class="lab__name">
        <input data-f="label" value="${esc(p.label)}" class="${ch('label')}" title="השם שהנגר רואה בטופס">
        <code>${esc(p.key)}</code>
        <label class="lab__vis" title="האם הנגר רואה את השדה"><input type="checkbox" data-f="hidden" ${p.hidden ? '' : 'checked'}> מוצג</label>
        ${changed ? '<button type="button" class="lab__reset" data-reset-p title="חזרה לקוד">↺</button>' : ''}
      </div>
      <div class="lab__vals">
        <label><span>ברירת מחדל</span>${def}</label>
        ${num ? `<label><span>מינימום</span><input type="number" data-num="1" data-f="min" value="${esc(p.min)}" step="any" class="${ch('min')}"></label>
        <label><span>מקסימום</span><input type="number" data-num="1" data-f="max" value="${esc(p.max)}" step="any" class="${ch('max')}"></label>
        <i class="lab__unit">${UNIT[p.type] || ''}</i>` : ''}
        <label><span>קבוצה</span><input data-f="group" value="${esc(p.group || '')}" class="${ch('group')}"></label>
      </div>
      <label class="lab__hintf"><span>הסבר לנגר</span><input data-f="hint" value="${esc(p.hint || '')}" class="${ch('hint')}" placeholder="—"></label>
      <div class="lab__cond"><span>מתי מוצג:</span> ${esc(describeShowIf(p, t))}${num ? ` · <span>בקוד: ${esc(o.default)} (${esc(o.min)}–${esc(o.max)})</span>` : ''}</div>
    </div>`;
  }
  function renderLimits() {
    const o = originalLimits(), lim = draft._limits || {};
    $('.lab__side').innerHTML = `<h3>⚖️ גבולות ואזהרות</h3>
      <p class="muted">הכללים שמהם המערכת מחליטה מתי להזהיר ומתי לפצל — משותפים לכל המוצרים. התצוגה משמאל: ${esc(TEMPLATES[lastTpl()].name)}, עם האזהרות שנדלקות.</p>
      <div class="lab__warns"></div>
      <button type="button" class="btn btn--small" data-reset-lim ${Object.keys(lim).length ? '' : 'disabled'}>↺ כל הגבולות — חזרה לקוד</button>`;
    $('.lab__table').innerHTML = `<div class="lab__rows">${Object.entries(LIMIT_INFO).map(([k, info]) => `
      <div class="lab__row ${lim[k] !== undefined ? 'is-changed-row' : ''}" data-lim="${k}">
        <div class="lab__name"><strong>${esc(info.label)}</strong><code>${k}</code>${lim[k] !== undefined ? '<button type="button" class="lab__reset" data-reset-l title="חזרה לקוד">↺</button>' : ''}</div>
        <div class="lab__vals"><label><span>ערך</span><input type="number" min="1" data-num="1" data-l="${k}" value="${LIMITS[k]}" class="${lim[k] !== undefined ? 'is-changed' : ''}"></label><i class="lab__unit">${esc(info.unit)}</i><span class="muted">בקוד: ${o[k]}</span></div>
        <div class="lab__cond">${esc(info.hint)}</div>
      </div>`).join('')}</div>`;
  }
  let prevTpl = 'bookcase';
  const lastTpl = () => (sel === '_limits' ? prevTpl : (prevTpl = sel));

  // ---- עריכה ----
  root.addEventListener('change', (e) => {
    const el = e.target;
    if (el.dataset.hours !== undefined) { setHours(sel, el.value); touch(); return; }
    if (el.dataset.l) { setLimit(el.dataset.l, el.value); touch(); return; }
    const row = el.closest('[data-p]');
    if (!row || !el.dataset.f) return;
    const pk = row.dataset.p, f = el.dataset.f;
    const p = allParams(TEMPLATES[sel]).find((q) => q.key === pk);
    let v = el.type === 'checkbox' ? !el.checked : el.value;
    if (f !== 'hidden' && ['default', 'min', 'max'].includes(f) && NUM.includes(p.type)) v = v === '' ? '' : Number(v);
    if (f === 'default' && !NUM.includes(p.type)) { const o = originalOf(sel).params[pk].default; if (typeof o === 'boolean') v = v === 'true'; else if (typeof o === 'number') v = Number(v); }
    setParam(sel, pk, f, v);
    touch();
  });
  root.addEventListener('click', (e) => {
    const b = e.target;
    if (b.dataset.resetP !== undefined) {
      const pk = b.closest('[data-p]').dataset.p;
      if (draft[sel]?.params) delete draft[sel].params[pk];
      tidy(sel); touch();
    } else if (b.dataset.resetL !== undefined) {
      setLimit(b.closest('[data-lim]').dataset.lim, ''); touch();
    } else if (b.dataset.resetTpl !== undefined) {
      if (!confirm(`להחזיר את כל ההגדרות של "${TEMPLATES[sel].name}" לערכים שבקוד?`)) return;
      delete draft[sel]; touch();
    } else if (b.dataset.resetLim !== undefined) {
      delete draft._limits; touch();
    }
  });

  // ---- תצוגה חיה ----
  function renderPreview() {
    const key = lastTpl();
    let model;
    try { model = build(key, {}); } catch (err) { $('.lab__warns').innerHTML = `<p class="lab__err">שגיאה בבנייה: ${esc(err.message)}</p>`; return; }
    const w = model.warnings || [];
    const box = $('.lab__warns');
    if (box) box.innerHTML = w.length ? `<details open><summary>⚠ ${w.length} אזהרות בברירת המחדל</summary><ul>${w.map((x) => `<li>${esc(x)}</li>`).join('')}</ul></details>` : '<p class="lab__ok">✓ ברירת המחדל בונה מוצר בלי אזהרות</p>';
    if (!window.THREE) return;
    if (!viewer) viewer = createViewer($('.lab__preview'), {});
    const first = !viewer._shown || viewer._shown !== key;
    viewer.setModel(model);
    if (first) { viewer.frame(model.bounds); viewer._shown = key; }
  }

  return { open };
}
