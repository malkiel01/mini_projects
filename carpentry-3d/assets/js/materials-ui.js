// מסך ספריית החומרים: רשימה לפי סוג, עריכה במקום, הוספה, השבתה, ותמונה
// אמיתית לכל חומר.
//
// מנהל עורך את הספרייה המשותפת (נשמרת בשרת כ-diff מהזריעה). נגר רואה
// אותה לקריאה, ובכל חומר יש לו שדה "המחיר שלי" — תעריף אישי שדורס את
// המחיר המשותף אצלו בלבד. גם שעת עבודה ורווח הם תעריפים אישיים.

import * as M from './model/materials.js';
import { imageToDataUrl, swatchDataUrl } from './textures.js';
import { api } from './store.js';

const $ = (s, r = document) => r.querySelector(s);
const hex = (c) => '#' + (c ?? 0xcccccc).toString(16).padStart(6, '0');
const unitName = { m2: 'למ"ר', m: 'למטר', unit: 'ליחידה' };

export function createMaterialsUI(root, { onChange, onError, getUser, rates }) {
  let kind = 'board';
  const isAdmin = () => getUser()?.role === 'admin';

  root.innerHTML = `
    <div class="mlib__bar">
      <strong>ספריית חומרים</strong>
      <div class="mlib__kinds"></div>
      <div class="mlib__rates" id="mlib-rates"></div>
      <div class="mlib__actions">
        <button type="button" class="btn btn--accent" data-act="add">+ חומר חדש</button>
        <button type="button" class="btn" data-act="reset">חזרה לזריעה</button>
        <button type="button" class="btn" data-act="close">סגירה</button>
      </div>
    </div>
    <p class="mlib__note muted" id="mlib-note"></p>
    <div class="mlib__body"></div>`;

  const kindsEl = $('.mlib__kinds', root);
  for (const k of M.KINDS) {
    const b = document.createElement('button');
    b.type = 'button'; b.dataset.kind = k.id; b.textContent = k.name;
    b.addEventListener('click', () => { kind = k.id; render(); });
    kindsEl.appendChild(b);
  }
  root.addEventListener('click', async (e) => {
    const act = e.target.closest('[data-act]')?.dataset.act;
    if (act === 'close') { if (window.__carpentry?.closeDrawer) window.__carpentry.closeDrawer('#mlib'); else root.classList.remove('is-open'); }
    if (act === 'add') addNew();
    if (act === 'reset' && confirm('להחזיר את הספרייה לזריעה מהקוד? התוספות והשינויים יימחקו (התמונות יישארו).')) {
      M.load([]); await persist(); render();
    }
  });

  async function persist() {
    try {
      const r = await api('materials-save', { diff: M.diff() });
      applyServer(r);
      onChange();
    } catch (err) { onError(err); }
  }
  /** מיישם את מה שהשרת החזיר: diff + תמונות (כתובות). */
  function applyServer({ diff, images }) {
    M.load(diff);
    for (const [id, v] of Object.entries(images || {})) M.setImage(id, v.url, v.imageMm);
  }

  async function saveRates() {
    try { await api('rates-save', { rates: rates.get() }); onChange(); } catch (err) { onError(err); }
  }

  function addNew() {
    const name = prompt('שם החומר החדש:');
    if (!name) return;
    const base = { board: { t: 18, color: 0xd8cbb6, finish: 'wood', grainMm: 600, sheet: [2800, 2070], price: 0, priceUnit: 'm2' },
      edge: { t: 1, price: 0, priceUnit: 'm' },
      glass: { t: 4, color: 0xcfe3ea, finish: 'glass', opacity: 0.35, price: 0, priceUnit: 'm2' },
      hardware: { price: 0, priceUnit: 'unit' } }[kind];
    M.upsert({ id: M.newId(kind, name), kind, name, active: true, ...base });
    persist().then(render);
  }

  function renderRates() {
    const el = $('#mlib-rates', root);
    if (!getUser() || getUser().role === 'viewer') { el.innerHTML = ''; return; }
    const r = rates.get();
    el.innerHTML = `
      <label>שעת עבודה <input name="laborHour" type="number" step="5" value="${r.laborHour ?? ''}" placeholder="₪"></label>
      <label>רווח <input name="markup" type="number" step="1" min="0" max="300" value="${r.markup != null ? Math.round(r.markup * 100) : ''}" placeholder="%"></label>`;
    el.addEventListener('change', (e) => {
      const v = e.target.value === '' ? null : Number(e.target.value);
      if (e.target.name === 'laborHour') rates.set({ laborHour: v });
      if (e.target.name === 'markup') rates.set({ markup: v == null ? null : v / 100 });
      saveRates();
    });
  }

  function render() {
    const admin = isAdmin();
    root.classList.toggle('is-readonly', !admin);
    kindsEl.querySelectorAll('button').forEach((b) => b.classList.toggle('is-active', b.dataset.kind === kind));
    $('[data-act=add]', root).hidden = !admin;
    $('[data-act=reset]', root).hidden = !admin;
    $('#mlib-note', root).textContent = admin
      ? 'הספרייה משותפת לכל הנגרים. "המחיר" הוא המחיר המומלץ; כל נגר יכול לדרוס אותו אצלו ב"המחיר שלי".'
      : 'הספרייה נערכת בידי המנהל. "המחיר שלי" דורס את המחיר המומלץ — אצלך בלבד.';
    renderRates();
    const body = $('.mlib__body', root);
    body.innerHTML = '';
    const list = M.all().filter((m) => m.kind === kind && (admin || m.active) && !m.accessory);
    if (kind === 'hardware') body.insertAdjacentHTML('beforeend', '<p class="muted">ידיות, כפתורים, צירים וגלגלים — עם הצורה התלת־ממדית והמחיר שלהם — מנוהלים במסך "🔩 אביזרים".</p>');
    if (!list.length) { body.insertAdjacentHTML('beforeend', '<p class="muted">אין חומרים מהסוג הזה.</p>'); return; }
    for (const m of list) body.appendChild(card(m, admin));
  }

  function card(m, admin) {
    const el = document.createElement('div');
    el.className = 'mcard' + (m.active ? '' : ' is-off');
    const hasVisual = m.kind === 'board' || m.kind === 'glass';
    const swatch = m.kind === 'glass'
      ? `<div class="mcard__swatch" style="background:${hex(m.color)}; opacity:${0.4 + (m.opacity ?? 0.4) * 0.6}"></div>`
      : `<div class="mcard__swatch" style="background-image:url(${swatchDataUrl(m)})"></div>`;
    const ro = admin ? '' : 'disabled';
    const myPrice = rates.get().materials?.[m.id];
    el.innerHTML = `
      ${hasVisual ? swatch : '<div class="mcard__swatch mcard__swatch--none">🔩</div>'}
      <div class="mcard__fields">
        <label><span>שם</span><input name="name" value="${esc(m.name)}" ${ro}></label>
        ${m.kind !== 'hardware' ? `<label><span>עובי</span><input name="t" type="number" step="0.5" value="${m.t ?? ''}" ${ro}><i>מ"מ</i></label>` : ''}
        ${hasVisual && admin ? `<label><span>צבע</span><input name="color" type="color" value="${hex(m.color)}"></label>` : ''}
        ${m.kind === 'board' && admin ? `<label><span>גימור</span><select name="finish">${M.FINISHES.filter((f) => f.id !== 'glass').map((f) => `<option value="${f.id}" ${m.finish === f.id ? 'selected' : ''}>${f.name}</option>`).join('')}</select></label>` : ''}
        ${m.kind === 'board' && admin ? `<label><span>קנה מידה סיבים</span><input name="grainMm" type="number" step="50" value="${m.grainMm ?? 600}"><i>מ"מ</i></label>` : ''}
        ${m.kind === 'board' ? `<label><span>לוח</span><input name="sheet0" type="number" value="${m.sheet?.[0] ?? ''}" ${ro}> × <input name="sheet1" type="number" value="${m.sheet?.[1] ?? ''}" ${ro}></label>` : ''}
        ${m.kind === 'board' && admin ? `<label class="mcard__check"><input name="back" type="checkbox" ${m.back ? 'checked' : ''}><span>לוח גב</span></label>` : ''}
        ${hasVisual ? `<label title="למשקל החלקים, הצירים והמסילות"><span>צפיפות</span><input name="density" type="number" step="10" min="50" value="${m.density ?? ''}" placeholder="לפי הסוג" ${ro}><i>ק"ג/מ"ק</i></label>` : ''}
        ${m.kind === 'hardware' && Number.isFinite(m.load) ? `<label title="עומס המסילה — נבדק מול משקל המגירה + תכולה"><span>עומס</span><input name="load" type="number" step="5" min="5" value="${m.load}" ${ro}><i>ק"ג</i></label>` : ''}
        ${m.kind === 'glass' && admin ? `<label><span>שקיפות</span><input name="opacity" type="range" min="0.1" max="0.95" step="0.05" value="${m.opacity ?? 0.4}"></label>` : ''}
        <label><span>${admin ? 'מחיר מומלץ' : 'מחיר מומלץ'}</span><input name="price" type="number" step="0.5" value="${m.price ?? 0}" ${ro}><i>₪ ${unitName[m.priceUnit] || ''}</i></label>
        ${getUser()?.role !== 'viewer' ? `<label class="mcard__mine"><span>המחיר שלי</span><input name="myPrice" type="number" step="0.5" value="${myPrice ?? ''}" placeholder="כמו המומלץ"><i>₪ ${unitName[m.priceUnit] || ''}</i></label>` : ''}
        ${hasVisual && admin ? `<label class="mcard__image"><span>תמונה אמיתית</span>
          <input name="imageFile" type="file" accept="image/*">
          ${m.image ? `<input name="imageMm" type="number" step="50" value="${m.imageMm ?? 600}" title="כמה מ&quot;מ מכסה התמונה לרוחבה"><i>מ"מ לרוחב</i><button type="button" class="btn btn--small" data-img="remove">הסרת תמונה</button>` : '<i class="muted">תמונה של הלוח האמיתי — תחליף את הטקסטורה</i>'}
        </label>` : ''}
      </div>
      <div class="mcard__side">
        ${admin ? `<label class="mcard__check"><input name="active" type="checkbox" ${m.active ? 'checked' : ''}><span>פעיל</span></label>` : ''}
        ${m.seed ? '<small class="muted">מהזריעה</small>' : (admin ? '<button type="button" class="btn btn--small" data-del="1">מחיקה</button>' : '')}
      </div>`;

    el.addEventListener('change', async (e) => {
      const inp = e.target;
      if (inp.name === 'myPrice') {
        const mats = { ...(rates.get().materials || {}) };
        if (inp.value === '') delete mats[m.id]; else mats[m.id] = Number(inp.value);
        rates.set({ materials: mats });
        saveRates();
        return;
      }
      if (!admin) return;
      if (inp.name === 'imageFile') {
        if (!inp.files[0]) return;
        try {
          const url = await imageToDataUrl(inp.files[0]);
          const r = await api('material-image', { id: m.id, image: url, imageMm: m.imageMm || 600 });
          M.setImage(m.id, r.image.url, r.image.imageMm);
          onChange(); render();
        } catch (err) { onError(err); }
        return;
      }
      if (inp.name === 'imageMm') {
        try {
          // שינוי קנה המידה בלבד — אותה פעולה, בלי תמונה.
          const r = await api('material-image', { id: m.id, image: '', imageMm: Number(inp.value) || 600 });
          M.setImage(m.id, r.image.url, r.image.imageMm);
          onChange();
        } catch (err) { onError(err); }
        return;
      }
      const patch = { id: m.id };
      if (inp.name === 'name') patch.name = inp.value.trim() || m.name;
      if (inp.name === 't') patch.t = Number(inp.value) || m.t;
      if (inp.name === 'color') patch.color = parseInt(inp.value.slice(1), 16);
      if (inp.name === 'finish') patch.finish = inp.value;
      if (inp.name === 'grainMm') patch.grainMm = Number(inp.value) || 600;
      if (inp.name === 'sheet0' || inp.name === 'sheet1') {
        patch.sheet = [Number($('[name=sheet0]', el).value) || 2800, Number($('[name=sheet1]', el).value) || 2070];
      }
      if (inp.name === 'back') patch.back = inp.checked;
      if (inp.name === 'opacity') patch.opacity = Number(inp.value);
      if (inp.name === 'price') patch.price = Number(inp.value) || 0;
      if (inp.name === 'density') patch.density = Number(inp.value) || 0;
      if (inp.name === 'load') patch.load = Number(inp.value) || m.load;
      if (inp.name === 'active') patch.active = inp.checked;
      M.upsert(patch);
      await persist();
      if (inp.name === 'active' || inp.name === 'back') render(); else if (inp.name === 'color' || inp.name === 'finish') refreshSwatch(el, M.material(m.id));
    });
    el.addEventListener('click', async (e) => {
      if (!admin) return;
      if (e.target.dataset.img === 'remove') {
        try { await api('material-image-delete', { id: m.id }); M.setImage(m.id, null); onChange(); render(); } catch (err) { onError(err); }
      }
      if (e.target.dataset.del && confirm(`למחוק את "${m.name}"?`)) {
        M.remove(m.id);
        try { await api('material-image-delete', { id: m.id }); } catch { /* לא הייתה תמונה */ }
        await persist(); render();
      }
    });
    return el;
  }

  function refreshSwatch(el, m) {
    const sw = $('.mcard__swatch', el);
    if (!sw) return;
    if (m.kind === 'glass') sw.style.background = hex(m.color);
    else sw.style.backgroundImage = `url(${swatchDataUrl(m)})`;
  }

  return { open() { root.classList.add('is-open'); render(); }, render, applyServer };
}

function esc(s) { return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;'); }
