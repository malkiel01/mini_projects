// מסך ספריית החומרים: רשימה לפי סוג, עריכה במקום, הוספה, השבתה, ותמונה
// אמיתית לכל חומר. כל שינוי עובר ל-materials.js ונשמר דרך store.js;
// המודל והתלת מימד מתעדכנים דרך onChange.

import * as M from './model/materials.js';
import { imageToDataUrl, swatchDataUrl } from './textures.js';
import { saveMaterialsDiff, saveImage, deleteImage } from './store.js';

const $ = (s, r = document) => r.querySelector(s);
const hex = (c) => '#' + (c ?? 0xcccccc).toString(16).padStart(6, '0');
const unitName = { m2: 'למ"ר', m: 'למטר', unit: 'ליחידה' };

export function createMaterialsUI(root, { onChange }) {
  let kind = 'board';

  root.innerHTML = `
    <div class="mlib__bar">
      <strong>ספריית חומרים</strong>
      <div class="mlib__kinds"></div>
      <div class="mlib__actions">
        <button type="button" class="btn btn--accent" data-act="add">+ חומר חדש</button>
        <button type="button" class="btn" data-act="reset">חזרה לזריעה</button>
        <button type="button" class="btn" data-act="close">סגירה</button>
      </div>
    </div>
    <div class="mlib__body"></div>`;

  const kindsEl = $('.mlib__kinds', root);
  for (const k of M.KINDS) {
    const b = document.createElement('button');
    b.type = 'button'; b.dataset.kind = k.id; b.textContent = k.name;
    b.addEventListener('click', () => { kind = k.id; render(); });
    kindsEl.appendChild(b);
  }
  root.addEventListener('click', (e) => {
    const act = e.target.closest('[data-act]')?.dataset.act;
    if (act === 'close') root.classList.remove('is-open');
    if (act === 'add') addNew();
    if (act === 'reset' && confirm('להחזיר את הספרייה לזריעה מהקוד? התוספות והשינויים יימחקו (התמונות יישארו).')) {
      M.reset(); persist(); render();
    }
  });

  function persist() {
    saveMaterialsDiff(M.diff());
    onChange();
  }

  function addNew() {
    const name = prompt('שם החומר החדש:');
    if (!name) return;
    const base = { board: { t: 18, color: 0xd8cbb6, finish: 'wood', grainMm: 600, sheet: [2800, 2070], price: 0, priceUnit: 'm2' },
      edge: { t: 1, price: 0, priceUnit: 'm' },
      glass: { t: 4, color: 0xcfe3ea, finish: 'glass', opacity: 0.35, price: 0, priceUnit: 'm2' },
      hardware: { price: 0, priceUnit: 'unit' } }[kind];
    M.upsert({ id: M.newId(kind, name), kind, name, active: true, ...base });
    persist(); render();
  }

  function render() {
    kindsEl.querySelectorAll('button').forEach((b) => b.classList.toggle('is-active', b.dataset.kind === kind));
    const body = $('.mlib__body', root);
    body.innerHTML = '';
    const list = M.all().filter((m) => m.kind === kind);
    if (!list.length) { body.innerHTML = '<p class="muted">אין חומרים מהסוג הזה. אפשר להוסיף.</p>'; return; }
    for (const m of list) body.appendChild(card(m));
  }

  function card(m) {
    const el = document.createElement('div');
    el.className = 'mcard' + (m.active ? '' : ' is-off');
    const hasVisual = m.kind === 'board' || m.kind === 'glass';
    const swatch = m.kind === 'glass'
      ? `<div class="mcard__swatch" style="background:${hex(m.color)}; opacity:${0.4 + (m.opacity ?? 0.4) * 0.6}"></div>`
      : `<div class="mcard__swatch" style="background-image:url(${swatchDataUrl(m)})"></div>`;
    el.innerHTML = `
      ${hasVisual ? swatch : '<div class="mcard__swatch mcard__swatch--none">🔩</div>'}
      <div class="mcard__fields">
        <label><span>שם</span><input name="name" value="${esc(m.name)}"></label>
        ${m.kind !== 'hardware' ? `<label><span>עובי</span><input name="t" type="number" step="0.5" value="${m.t ?? ''}"><i>מ"מ</i></label>` : ''}
        ${hasVisual ? `<label><span>צבע</span><input name="color" type="color" value="${hex(m.color)}"></label>` : ''}
        ${m.kind === 'board' ? `<label><span>גימור</span><select name="finish">${M.FINISHES.filter((f) => f.id !== 'glass').map((f) => `<option value="${f.id}" ${m.finish === f.id ? 'selected' : ''}>${f.name}</option>`).join('')}</select></label>` : ''}
        ${m.kind === 'board' ? `<label><span>קנה מידה סיבים</span><input name="grainMm" type="number" step="50" value="${m.grainMm ?? 600}"><i>מ"מ</i></label>` : ''}
        ${m.kind === 'board' ? `<label><span>לוח</span><input name="sheet0" type="number" value="${m.sheet?.[0] ?? ''}"> × <input name="sheet1" type="number" value="${m.sheet?.[1] ?? ''}"></label>` : ''}
        ${m.kind === 'board' ? `<label class="mcard__check"><input name="back" type="checkbox" ${m.back ? 'checked' : ''}><span>לוח גב</span></label>` : ''}
        ${m.kind === 'glass' ? `<label><span>שקיפות</span><input name="opacity" type="range" min="0.1" max="0.95" step="0.05" value="${m.opacity ?? 0.4}"></label>` : ''}
        <label><span>מחיר</span><input name="price" type="number" step="0.5" value="${m.price ?? 0}"><i>₪ ${unitName[m.priceUnit] || ''}</i></label>
        ${hasVisual ? `<label class="mcard__image"><span>תמונה אמיתית</span>
          <input name="imageFile" type="file" accept="image/*">
          ${m.image ? `<input name="imageMm" type="number" step="50" value="${m.imageMm ?? 600}" title="כמה מ&quot;מ מכסה התמונה לרוחבה"><i>מ"מ לרוחב</i><button type="button" class="btn btn--small" data-img="remove">הסרת תמונה</button>` : '<i class="muted">תמונה של הלוח האמיתי — תחליף את הטקסטורה</i>'}
        </label>` : ''}
      </div>
      <div class="mcard__side">
        <label class="mcard__check"><input name="active" type="checkbox" ${m.active ? 'checked' : ''}><span>פעיל</span></label>
        ${m.seed ? '<small class="muted">מהזריעה</small>' : '<button type="button" class="btn btn--small" data-del="1">מחיקה</button>'}
      </div>`;

    el.addEventListener('change', async (e) => {
      const inp = e.target;
      if (inp.name === 'imageFile') {
        if (!inp.files[0]) return;
        try {
          const url = await imageToDataUrl(inp.files[0]);
          M.setImage(m.id, url, m.imageMm || 600);
          await saveImage(m.id, url, m.imageMm || 600);
          onChange(); render();
        } catch (err) { alert(err.message); }
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
      if (inp.name === 'active') patch.active = inp.checked;
      if (inp.name === 'imageMm') { M.setImage(m.id, m.image, Number(inp.value) || 600); await saveImage(m.id, m.image, Number(inp.value) || 600); onChange(); return; }
      M.upsert(patch);
      persist();
      if (inp.name === 'active' || inp.name === 'back') render(); else if (inp.name === 'color' || inp.name === 'finish') refreshSwatch(el, M.material(m.id));
    });
    el.addEventListener('click', async (e) => {
      if (e.target.dataset.img === 'remove') {
        M.setImage(m.id, null); await deleteImage(m.id); onChange(); render();
      }
      if (e.target.dataset.del && confirm(`למחוק את "${m.name}"?`)) {
        M.remove(m.id); await deleteImage(m.id); persist(); render();
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

  render();
  return { open() { root.classList.add('is-open'); render(); }, render };
}

function esc(s) { return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;'); }
