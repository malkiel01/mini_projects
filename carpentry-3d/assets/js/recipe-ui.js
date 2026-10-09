// עורך המתכונים — חלק ממסך "🧪 מעבדת מוצרים".
//
// מתכון נערך כטבלאות: פרמטרים, משתנים, רכיבים, פרזול ואזהרות. כל שדה של
// מידה/מיקום/כמות/תנאי הוא נוסחה, ונבדק תחבירית תוך כדי הקלדה (מסגרת אדומה +
// ההודעה). שינוי ערך לא מצייר מחדש (הפוקוס נשאר) — רק מודיע ל-onChange
// שבונה את התצוגה. הוספה, מחיקה, הזזה וסוג — מציירים מחדש.

import { MATERIAL_USES } from './model/recipe.js';
import { syntaxError } from './model/expr.js';

const esc = (s) => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
const TYPES = { mm: 'מ"מ', int: 'מספר שלם', deg: 'מעלות', enum: 'בחירה מרשימה', material: 'חומר' };
const BLANK = {
  params: () => ({ key: 'p' + Math.random().toString(36).slice(2, 5), label: 'פרמטר חדש', type: 'mm', default: 100, min: 0, max: 1000, group: 'כללי' }),
  vars: () => ({ name: 'v' + Math.random().toString(36).slice(2, 5), expr: '0' }),
  components: () => ({ id: 'part' + Math.random().toString(36).slice(2, 5), name: 'רכיב חדש', kind: 'board', material: '', x: '0', y: '0', z: '0', w: '500', h: '18', d: '300', edges: 'front', grain: 'auto' }),
  hardware: () => ({ name: 'פרזול', material: '', qty: '1' }),
  warnings: () => ({ when: '0', text: 'אזהרה' }),
};

export function createRecipeEditor(side, table, { onChange, onSave, onDelete, onDuplicate }) {
  let r = null;

  function show(recipe) { r = recipe; draw(); }

  // ---- שדות ----
  const fx = (path, val, ph = '', cls = '') => {
    const err = val !== undefined && val !== null && String(val).trim() !== '' ? syntaxError(String(val)) : null;
    return `<input class="rx ${cls} ${err ? 'is-bad' : ''}" data-path="${path}" data-fx="1" value="${esc(val)}" placeholder="${esc(ph)}" dir="ltr" title="${esc(err || 'נוסחה')}">`;
  };
  const tx = (path, val, ph = '', extra = '') => `<input data-path="${path}" value="${esc(val)}" placeholder="${esc(ph)}" ${extra}>`;
  const sel = (path, val, opts) => `<select data-path="${path}">${Object.entries(opts).map(([k, v]) => `<option value="${esc(k)}" ${String(val ?? '') === k ? 'selected' : ''}>${esc(v)}</option>`).join('')}</select>`;
  const lbl = (name, html, cls = '') => `<label class="rf ${cls}"><span>${name}</span>${html}</label>`;
  const tools = (list, i) => `<span class="rtools"><button type="button" data-up="${list}.${i}" title="למעלה">↑</button><button type="button" data-down="${list}.${i}" title="למטה">↓</button><button type="button" data-del="${list}.${i}" title="מחיקה">✕</button></span>`;
  const section = (list, title, help, body) => `<section class="rsec" data-sec="${list}"><h4 class="lab__group">${title} <small class="muted">${(r[list] || []).length}</small><button type="button" class="btn btn--small" data-add="${list}">＋ הוספה</button></h4><p class="muted rhelp">${help}</p>${body}</section>`;

  // ציור מחדש בלי לאבד את השדה שבפוקוס
  function draw() {
    const a = document.activeElement;
    const path = a && (side.contains(a) || table.contains(a)) ? a.dataset.path : null;
    drawAll();
    if (path) (side.querySelector(`[data-path="${path}"]`) || table.querySelector(`[data-path="${path}"]`))?.focus();
  }
  function drawAll() {
    const matParams = (r.params || []).filter((p) => p.type === 'material');
    const matOpts = (use) => ({ '': '— בחירה —', ...Object.fromEntries(matParams.filter((p) => !use || use.includes(p.use || 'board')).map((p) => [p.key, p.label || p.key])) });
    const names = [...(r.params || []).map((p) => p.key), ...matParams.map((p) => `${p.key}_t`), ...(r.vars || []).map((x) => x.name)].filter(Boolean);

    side.innerHTML = `
      <h3>🧪 ${esc(r.name)}</h3>
      ${lbl('שם המוצר', tx('name', r.name))}
      ${lbl('תיאור', tx('description', r.description || ''))}
      <div class="rrow">${lbl('שעות עבודה', `<input type="number" data-num="1" min="0" step="0.5" data-path="laborHours" value="${esc(r.laborHours ?? 0)}">`)}
      <label class="lab__vis"><input type="checkbox" data-path="active" ${r.active !== false ? 'checked' : ''}> פעיל (מופיע ביצירת פרויקט)</label></div>
      <div class="lab__warns"></div>
      <div class="rbtns">
        <button type="button" class="btn btn--accent" data-act="save">💾 שמירת המתכון</button>
        <button type="button" class="btn btn--small" data-act="dup">שכפול</button>
        <button type="button" class="btn btn--small" data-act="del">מחיקה</button>
      </div>
      <details class="rcheat"><summary>איך כותבים נוסחה</summary>
        <p>חשבון <code>+ - * / %</code> וסוגריים · השוואה <code>== != &lt; &gt; &lt;= &gt;=</code> · וגם <code>&amp;&amp;</code> · או <code>||</code> · לא <code>!</code><br>
        תנאי: <code>i == 0 ? 0 : T</code> · טקסט במרכאות: <code>doors == 'wood'</code><br>
        פונקציות: <code>min max round floor ceil abs sqrt clamp(x,מ,עד) if(תנאי,כן,לא)</code><br>
        בשם של רכיב: <code>מדף {i+1}</code>. בלולאה: המונה (<code>i</code>) מתחיל ב-0, ו-<code>n</code> = מספר החזרות.<br>
        צירים: x שמאל→ימין, y רצפה→מעלה, z אחור→חזית. x,y,z = הפינה; w,h,d = רוחב, גובה, עומק.</p>
        <p>משתנים זמינים: ${names.map((n) => `<code>${esc(n)}</code>`).join(' ') || '—'}</p>
      </details>`;

    table.innerHTML = [
      section('params', 'פרמטרים', 'מה שהנגר ממלא בטופס. המפתח (באנגלית) הוא שם המשתנה בנוסחאות.',
        `<div class="rlist">${(r.params || []).map((p, i) => `<div class="rcard">
          <div class="rhead">${tx(`params.${i}.key`, p.key, 'מפתח', 'dir="ltr" class="rkey"')}${tx(`params.${i}.label`, p.label, 'שם בטופס')}${tools('params', i)}</div>
          <div class="rrow">${lbl('סוג', sel(`params.${i}.type`, p.type, TYPES))}
          ${p.type === 'material' ? lbl('סוג חומר', sel(`params.${i}.use`, p.use || 'board', Object.fromEntries(Object.entries(MATERIAL_USES).map(([k, u]) => [k, u.name]))))
            + lbl('ברירת מחדל (מזהה)', tx(`params.${i}.default`, p.default, 'board:…', 'dir="ltr"'))
          : p.type === 'enum' ? lbl('ברירת מחדל', tx(`params.${i}.default`, p.default, 'id', 'dir="ltr"'))
          : lbl('ברירת מחדל', `<input type="number" data-num="1" data-path="params.${i}.default" value="${esc(p.default)}">`) + lbl('מינ׳', `<input type="number" data-num="1" data-path="params.${i}.min" value="${esc(p.min)}">`) + lbl('מקס׳', `<input type="number" data-num="1" data-path="params.${i}.max" value="${esc(p.max)}">`)}
          ${lbl('קבוצה', tx(`params.${i}.group`, p.group || ''))}</div>
          ${p.type === 'enum' ? lbl('אפשרויות (מזהה=שם, מופרדות בפסיק)', `<input data-path="params.${i}.options" data-opts="1" value="${esc((p.options || []).map((o) => `${o.id}=${o.name}`).join(', '))}" placeholder="none=ללא, wood=עץ">`, 'rwide') : ''}
          <div class="rrow">${lbl('מתי מוצג (נוסחה, ריק = תמיד)', fx(`params.${i}.when`, p.when || '', 'ריק = תמיד'), 'rwide')}${lbl('הסבר', tx(`params.${i}.hint`, p.hint || ''), 'rwide')}</div>
        </div>`).join('')}</div>`),
      section('vars', 'משתנים מחושבים', 'שם = נוסחה. לפי הסדר — כל משתנה רואה את הפרמטרים ואת המשתנים שמעליו. לכל פרמטר חומר יש גם <code>&lt;מפתח&gt;_t</code> — העובי שלו.',
        `<div class="rlist rlist--tight">${(r.vars || []).map((x, i) => `<div class="rcard rcard--line">${tx(`vars.${i}.name`, x.name, 'שם', 'dir="ltr" class="rkey"')}<b>=</b>${fx(`vars.${i}.expr`, x.expr, 'W - 2*T', 'rgrow')}${tools('vars', i)}</div>`).join('')}</div>`),
      section('components', 'רכיבים', 'כל לוח או דלת. "חזרות" — לולאה: הרכיב נבנה כמספר הזה, והמונה זמין בנוסחאות. "תנאי" — נבנה רק כשהנוסחה אמת.',
        `<div class="rlist">${(r.components || []).map((c, i) => `<div class="rcard ${c.kind === 'door' ? 'rcard--door' : ''}">
          <div class="rhead">${tx(`components.${i}.id`, c.id, 'מזהה', 'dir="ltr" class="rkey"')}${tx(`components.${i}.name`, c.name, 'שם (אפשר {i+1})')}${tools('components', i)}</div>
          <div class="rrow">${lbl('סוג', sel(`components.${i}.kind`, c.kind || 'board', { board: 'לוח', door: 'דלת (עם צירים)' }))}
          ${lbl('חומר', sel(`components.${i}.material`, c.material, { ...matOpts(c.kind === 'door' ? null : null), ...(c.material && !matParams.some((p) => p.key === c.material) ? { [c.material]: c.material } : {}) }))}
          ${lbl('חזרות', fx(`components.${i}.repeat`, c.repeat || '', 'ריק = 1', 'rshort'))}${lbl('מונה', tx(`components.${i}.index`, c.index || '', 'i', 'dir="ltr" class="rshort"'))}
          ${lbl('תנאי', fx(`components.${i}.when`, c.when || '', 'ריק = תמיד'), 'rwide')}</div>
          <div class="rgrid6">${['x', 'y', 'z', 'w', 'h', 'd'].map((k) => lbl({ x: 'x מיקום', y: 'y מיקום', z: 'z מיקום', w: 'w רוחב', h: 'h גובה', d: 'd עומק' }[k], fx(`components.${i}.${k}`, c[k] ?? '', '0'))).join('')}</div>
          <div class="rrow">${c.kind === 'door'
            ? lbl('צד הציר (נוסחה)', fx(`components.${i}.side`, c.side || '', "'left'"), 'rwide') + lbl('ידית', sel(`components.${i}.handle`, c.handle || '', { '': 'ללא', ...matOpts(['handle']) })) + lbl('ציר', sel(`components.${i}.hinge`, c.hinge || '', { '': 'ציר 110° (ברירת מחדל)', ...matOpts(['hinge']) }))
            : lbl('סיבים', sel(`components.${i}.grain`, c.grain || 'auto', { auto: 'אוטומטי (הצד הארוך)', x: 'לרוחב (x)', y: 'לגובה (y)', z: 'לעומק (z)' })) + lbl('קנט', sel(`components.${i}.edges`, c.edges || 'front', { front: 'חזית', all: 'מסביב', none: 'ללא' }))}</div>
        </div>`).join('')}</div>`),
      section('hardware', 'פרזול נוסף', 'מעבר לצירים ולידיות של הדלתות. חומר = מזהה מהספרייה (hw:…) או פרמטר חומר.',
        `<div class="rlist rlist--tight">${(r.hardware || []).map((h, i) => `<div class="rcard rcard--line">${tx(`hardware.${i}.name`, h.name, 'שם')}${tx(`hardware.${i}.material`, h.material, 'hw:…', 'dir="ltr"')}${lbl('כמות', fx(`hardware.${i}.qty`, h.qty ?? '1', '1', 'rshort'))}${lbl('תנאי', fx(`hardware.${i}.when`, h.when || '', 'ריק = תמיד'))}${tools('hardware', i)}</div>`).join('')}</div>`),
      section('warnings', 'אזהרות', 'כשהנוסחה אמת — האזהרה מופיעה לנגר. בטקסט אפשר {נוסחה}: "עמודה ברוחב {colW}".',
        `<div class="rlist rlist--tight">${(r.warnings || []).map((w, i) => `<div class="rcard rcard--line">${lbl('כאשר', fx(`warnings.${i}.when`, w.when || '', 'colW > 800'))}${tx(`warnings.${i}.text`, w.text, 'הטקסט', 'class="rgrow"')}${tools('warnings', i)}</div>`).join('')}</div>`),
    ].join('');
  }

  // ---- עריכה ----
  function setPath(path, value) {
    const keys = path.split('.');
    let o = r;
    for (const k of keys.slice(0, -1)) o = o[k];
    o[keys[keys.length - 1]] = value;
  }
  const NUMERIC = /^(params\.\d+\.(min|max)|laborHours)$/;
  function onInput(e) {
    const el = e.target;
    if (!el.dataset.path) return;
    const path = el.dataset.path;
    let v = el.type === 'checkbox' ? el.checked : el.value;
    if (el.dataset.opts) v = String(v).split(',').map((s) => s.trim()).filter(Boolean).map((s) => { const [id, ...n] = s.split('='); return { id: id.trim(), name: (n.join('=') || id).trim() }; });
    else if (NUMERIC.test(path) || (/^params\.\d+\.default$/.test(path) && el.type === 'number')) v = v === '' ? '' : Number(v);
    setPath(path, v);
    if (el.dataset.fx) {
      const err = String(v).trim() ? syntaxError(String(v)) : null;
      el.classList.toggle('is-bad', !!err); el.title = err || 'נוסחה';
    }
    // שינוי סוג משנה את השדות — ציור מחדש
    if (e.type === 'change' && /\.(type|kind|use)$/.test(path)) draw();
    if (e.type === 'change' && /^params\.\d+\.(key|label)$/.test(path)) draw();   // שמות החומרים ברשימות
    onChange(r);
  }
  for (const el of [side, table]) {
    el.addEventListener('input', (e) => { if (e.target.tagName !== 'SELECT' && e.target.type !== 'checkbox') onInput(e); });
    el.addEventListener('change', onInput);
    el.addEventListener('click', (e) => {
      const b = e.target.closest('button');
      if (!b) return;
      if (b.dataset.add) { (r[b.dataset.add] = r[b.dataset.add] || []).push(BLANK[b.dataset.add]()); draw(); onChange(r); return; }
      const ref = b.dataset.del || b.dataset.up || b.dataset.down;
      if (ref) {
        const [list, i] = [ref.split('.')[0], Number(ref.split('.')[1])];
        const arr = r[list];
        if (b.dataset.del) arr.splice(i, 1);
        else { const j = b.dataset.up ? i - 1 : i + 1; if (j < 0 || j >= arr.length) return; [arr[i], arr[j]] = [arr[j], arr[i]]; }
        draw(); onChange(r); return;
      }
      if (b.dataset.act === 'save') onSave(r);
      if (b.dataset.act === 'dup') onDuplicate(r);
      if (b.dataset.act === 'del') onDelete(r);
    });
  }

  return { show, redraw: () => r && draw() };
}
