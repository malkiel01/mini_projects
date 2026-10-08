// מסך "🛠 CNC": דוגמאות החירוץ של הנגר — דמוי סטריפים ומסגרות — עם תצוגה
// מקדימה חיה: מבט חזית על לוח לדוגמה (דלת, חזית מגירה, דופן) וחתך מוגדל של
// הקצה, שמראה את השוליים, הצלעות והעומק בדיוק כפי שהמכונה תכרסם.
//
// כל דוגמה היא חומר מסוג 'cnc' בספרייה (`cnc:…`) עם `mill` ומחיר למ"ר,
// ומופיעה בטופס ברשימות הגימור ("חירוץ CNC — <שם>"). השמירה: cnc-save —
// רק שורות ה-cnc:, כך שגם נגר (לא רק מנהל) מנהל אותן.

import * as M from './model/materials.js';
import { MILL_DEFAULTS, millSpec, millRects, fluteGrooves, fluteRib } from './model/milling.js';
import { api } from './store.js';

const esc = (s) => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
const SAMPLES = [
  { id: 'door', name: 'דלת 450 × 2000', U: 450, V: 2000 },
  { id: 'door-low', name: 'דלת 600 × 720', U: 600, V: 720 },
  { id: 'drawer', name: 'חזית מגירה 600 × 200', U: 600, V: 200 },
  { id: 'side', name: 'דופן 350 × 2000', U: 350, V: 2000 },
];
// [מפתח, תווית, מינימום, מקסימום, יחידה, הסבר בשורה נפרדת]
const FIELDS = {
  flutes: [
    ['groove', 'רוחב חריץ', 2, 40, 'מ"מ', ''], ['rib', 'רוחב צלע', 3, 80, 'מ"מ', 'מינימום — השארית מתחלקת שווה בין הצלעות'],
    ['depth', 'עומק', 1, 15, 'מ"מ', ''], ['margin', 'שוליים בקצה', 0, 150, 'מ"מ', '0 = צלע בקצה הלוח'],
  ],
  frames: [
    ['groove', 'רוחב חריץ', 2, 30, 'מ"מ', ''], ['depth', 'עומק', 1, 12, 'מ"מ', ''], ['inset', 'מרחק מהקצה', 20, 250, 'מ"מ', ''],
    ['rings', 'מספר קווים', 1, 4, '', ''], ['ringGap', 'מרווח בין קווים', 4, 120, 'מ"מ', ''], ['split', 'גובה הפנל העליון', 40, 85, '%', 'בשני פנלים — מגובה הלוח'],
  ],
};

/** מזהה לדוגמה חדשה: מהשם אם יש בו אותיות לטיניות, אחרת לפי הזמן. */
function newCncId(name) {
  const id = M.newId('cnc', name);
  return /^cnc:custom(-\d+)?$/.test(id) ? M.newId('cnc', `p-${Date.now().toString(36)}`) : id;
}

export function createCncUI(root, { onChange, onError, getUser }) {
  let selectedId = null;
  let sample = 'door';
  let saveTimer = null;

  root.innerHTML = `
    <div class="drawer__bar"><strong>🛠 דוגמאות CNC</strong><span class="muted cnc__hint">חירוץ בלוח אחד — בלי להדביק חתיכות</span><button type="button" class="btn" data-close="#cnc">סגירה</button></div>
    <div class="acc__body">
      <div class="acc__list"></div>
      <div class="acc__editor cnc__editor">
        <div class="cnc__preview"></div>
        <div class="acc__form"></div>
      </div>
    </div>`;
  const $ = (s, el = root) => el.querySelector(s);
  const canEdit = () => getUser() && getUser().role !== 'viewer';
  const list = () => M.all().filter((m) => m.kind === 'cnc' && m.mill && (canEdit() || m.active));

  async function persist() {
    try {
      const r = await api('cnc-save', { diff: M.diff().filter((m) => m.id.startsWith('cnc:')) });
      M.load(r.diff);
      onChange();
    } catch (err) { onError(err); }
  }
  // שינוי מספר — שומרים אחרי רגע של שקט, לא בכל לחיצה על החץ
  const persistSoon = () => { clearTimeout(saveTimer); saveTimer = setTimeout(persist, 600); };

  function render() {
    const items = list();
    if (!selectedId || !items.some((m) => m.id === selectedId)) selectedId = items[0]?.id || null;
    $('.acc__list').innerHTML = `
      ${canEdit() ? `<div class="cnc__new"><button type="button" class="btn btn--accent" data-new="flutes">＋ דמוי סטריפים</button><button type="button" class="btn btn--accent" data-new="frames">＋ מסגרות</button></div>` : ''}
      ${items.map((m) => `<button type="button" class="acc__card ${m.id === selectedId ? 'is-active' : ''} ${m.active ? '' : 'is-off'}" data-id="${m.id}">
        <b>${esc(m.name)}</b>
        <span class="muted">${m.mill.kind === 'frames' ? 'מסגרות' : 'דמוי סטריפים'} · ${m.price ?? 0} ₪ למ"ר${m.active ? '' : ' · מושבת'}</span>
      </button>`).join('') || '<p class="muted">אין דוגמאות.</p>'}`;
    renderEditor();
  }

  function renderEditor() {
    const m = selectedId && M.material(selectedId);
    const form = $('.acc__form');
    if (!m || m.kind !== 'cnc') { form.innerHTML = ''; $('.cnc__preview').innerHTML = ''; return; }
    const ro = canEdit() ? '' : 'disabled';
    const kind = m.mill.kind === 'frames' ? 'frames' : 'flutes';
    const mill = { ...MILL_DEFAULTS[kind], ...m.mill };
    form.innerHTML = `
      <label class="acc__field"><span>שם</span><input name="name" value="${esc(m.name)}" ${ro}><i></i></label>
      <label class="acc__field"><span>מחיר עיבוד</span><input name="price" type="number" step="5" min="0" value="${m.price ?? 0}" ${ro}><i>₪ למ"ר</i></label>
      ${FIELDS[kind].map(([k, label, min, max, unit, hint]) => `<label class="acc__field"><span>${label}</span><input name="m:${k}" type="number" step="1" min="${min}" max="${max}" value="${mill[k]}" ${ro}><i>${unit}</i>${hint ? `<small class="acc__hint">${hint}</small>` : ''}</label>`).join('')}
      ${kind === 'frames' ? `<label class="acc__field"><span>פנלים</span><select name="m:panels" ${ro}><option value="1" ${mill.panels !== 2 ? 'selected' : ''}>פנל אחד</option><option value="2" ${mill.panels === 2 ? 'selected' : ''}>שניים — עליון ותחתון (בלוח גבוה מ-900)</option></select><i></i></label>` : ''}
      <label class="acc__field acc__check"><input name="active" type="checkbox" ${m.active ? 'checked' : ''} ${ro}><span>פעיל — מוצע בטופס</span></label>
      <label class="acc__field"><span>תצוגה על</span><select name="sample">${SAMPLES.map((s) => `<option value="${s.id}" ${s.id === sample ? 'selected' : ''}>${s.name}</option>`).join('')}</select><i></i></label>
      <p class="muted cnc__stats"></p>
      ${canEdit() ? `<div class="acc__actions"><button type="button" class="btn btn--small" data-dup="1">שכפול</button>${m.seed ? '' : '<button type="button" class="btn btn--small" data-del="1">מחיקה</button>'}</div>` : ''}`;
    drawPreview();
  }

  /** מבט חזית על הלוח לדוגמה, וחתך מוגדל של 140 המ"מ הראשונים מהקצה. */
  function drawPreview() {
    const spec = millSpec(selectedId);
    const box = $('.cnc__preview');
    if (!spec) { box.innerHTML = ''; return; }
    const s = SAMPLES.find((x) => x.id === sample) || SAMPLES[0];
    const { U, V } = s;
    const T = 18;
    const rects = millRects(U, V, spec);
    // חזית: בקנה מידה שנכנס ל-260×300
    const k = Math.min(260 / U, 300 / V), fw = U * k, fh = V * k;
    const front = `<svg class="cnc__front" viewBox="-6 -6 ${fw + 12} ${fh + 12}" role="img" aria-label="מבט חזית">
      <rect x="0" y="0" width="${fw}" height="${fh}" class="cnc__board"/>
      ${rects.map(([u0, u1, v0, v1]) => `<rect x="${u0 * k}" y="${(V - v1) * k}" width="${(u1 - u0) * k}" height="${(v1 - v0) * k}" class="cnc__groove"/>`).join('')}
    </svg>`;
    // חתך בגובה האמצע (במסגרות — בגובה שבו הקווים האנכיים עוברים): 140 מ"מ מהקצה, ×2.2
    const L = Math.min(140, U), z = 2.2, vMid = spec.kind === 'frames' ? V * 0.5 : V / 2;
    const cuts = rects.filter(([u0, , v0, v1]) => u0 < L && v0 <= vMid && v1 >= vMid).map(([u0, u1]) => [u0, Math.min(u1, L)]);
    // הפרופיל: מהקצה, פני הלוח בגובה 0, החריצים יורדים בעומק
    let path = `M0 ${T * z} L0 0`;
    for (const [a, b] of cuts.sort((p, q) => p[0] - q[0])) path += ` L${a * z} 0 L${a * z} ${spec.depth * z} L${b * z} ${spec.depth * z} L${b * z} 0`;
    path += ` L${L * z} 0 L${L * z} ${T * z} Z`;
    const dims = [];
    if (spec.kind === 'flutes') {
      const r = fluteRib(U, spec), first = fluteGrooves(U, spec)[0];
      if (first) {
        if (spec.margin > 0) dims.push([0, spec.margin, `שוליים ${spec.margin}`]);
        dims.push([spec.margin, first[0], `צלע ${Math.round(r * 10) / 10}`], [first[0], first[1], `חריץ ${spec.groove}`]);
      }
    } else if (cuts[0]) dims.push([0, cuts[0][0], `${Math.round(cuts[0][0])} מהקצה`], [cuts[0][0], cuts[0][1], `חריץ ${spec.groove}`]);
    const sec = `<svg class="cnc__section" viewBox="-10 -48 ${L * z + 60} ${T * z + 72}" role="img" aria-label="חתך בקצה">
      <path d="${path}" class="cnc__profile"/>
      ${dims.map(([a, b, t], i) => { const y = -8 - i * 14; return `<g class="cnc__dim"><line x1="${a * z}" y1="${y}" x2="${b * z}" y2="${y}"/><line x1="${a * z}" y1="${y - 3}" x2="${a * z}" y2="0"/><line x1="${b * z}" y1="${y - 3}" x2="${b * z}" y2="0"/><text x="${(a + b) / 2 * z}" y="${y - 3}">${t}</text></g>`; }).join('')}
      <text x="${L * z + 4}" y="${spec.depth * z}" class="cnc__depth">עומק ${spec.depth}</text>
      <text x="${L * z}" y="${T * z + 18}" class="cnc__cap">חתך — ${L} המ"מ הראשונים מהקצה, בעובי 18</text>
    </svg>`;
    box.innerHTML = `<div class="cnc__views"><figure>${front}<figcaption>מבט חזית — ${s.name}</figcaption></figure><figure>${sec}</figure></div>`;
    const n = spec.kind === 'flutes' ? fluteGrooves(U, spec).length : rects.length / 4;
    const st = $('.cnc__stats');
    if (st) st.textContent = spec.kind === 'flutes'
      ? `על ${s.name}: ${n} חריצים, צלע בפועל ${Math.round(fluteRib(U, spec) * 10) / 10} מ"מ`
      : `על ${s.name}: ${n} קווים${n < (spec.rings || 1) * (spec.panels === 2 && V > 900 ? 2 : 1) ? ' (קו שלא נכנס נשמט)' : ''}`;
  }

  // ---- אירועים ----
  root.addEventListener('click', (e) => {
    const card = e.target.closest('[data-id]');
    if (card) { selectedId = card.dataset.id; render(); return; }
    const nb = e.target.closest('[data-new]');
    if (nb && canEdit()) {
      const kind = nb.dataset.new;
      const id = newCncId('');
      M.upsert({ id, kind: 'cnc', name: kind === 'frames' ? 'מסגרת חדשה' : 'סטריפים חדש', price: 80, priceUnit: 'm2', active: true, mill: { kind, ...MILL_DEFAULTS[kind] } });
      selectedId = id; render(); persist(); return;
    }
    if (e.target.closest('[data-dup]') && canEdit()) {
      const m = M.material(selectedId);
      const id = newCncId('');
      M.upsert({ id, kind: 'cnc', name: `${m.name} (עותק)`, price: m.price, priceUnit: 'm2', active: true, mill: { ...m.mill } });
      selectedId = id; render(); persist(); return;
    }
    if (e.target.closest('[data-del]') && canEdit()) {
      if (!confirm('למחוק את הדוגמה? פרויקט שמשתמש בה יחזור לגימור חלק.')) return;
      M.remove(selectedId); selectedId = null; render(); persist();
    }
  });
  root.addEventListener('input', (e) => {
    const t = e.target;
    if (!t.name || t.name === 'sample' || !canEdit()) return;
    const m = M.material(selectedId);
    if (t.name.startsWith('m:')) {
      const key = t.name.slice(2);
      let v = Number(t.value);
      if (!Number.isFinite(v)) return;
      if (t.min !== '') v = Math.max(Number(t.min), v);
      if (t.max !== '') v = Math.min(Number(t.max), v);
      M.upsert({ id: selectedId, mill: { ...m.mill, [key]: v } });
    } else if (t.name === 'name') M.upsert({ id: selectedId, name: t.value.trim() || m.name });
    else if (t.name === 'price') M.upsert({ id: selectedId, price: Math.max(0, Number(t.value) || 0) });
    else return;
    drawPreview();
    persistSoon();
  });
  root.addEventListener('change', (e) => {
    const t = e.target;
    if (t.name === 'sample') { sample = t.value; drawPreview(); return; }
    if (!canEdit()) return;
    if (t.name === 'active') { M.upsert({ id: selectedId, active: t.checked }); render(); persist(); return; }
    if (t.name === 'm:panels') { const m = M.material(selectedId); M.upsert({ id: selectedId, mill: { ...m.mill, panels: Number(t.value) } }); drawPreview(); persist(); return; }
    if (t.name === 'name' || t.name === 'price') { clearTimeout(saveTimer); render(); persist(); }   // שם/מחיר — מעדכנים גם את הכרטיס ברשימה
  });

  return { open() { root.classList.add('is-open'); render(); }, render };
}
