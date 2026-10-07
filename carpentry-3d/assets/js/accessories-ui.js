// מסך "אביזרים": הידיות, הכפתורים, הצירים והגלגלים של הנגר — אלמנטים
// תלת־ממדיים אמיתיים (model/accessories.js) עם תצוגה מקדימה חיה.
//
// כל אביזר הוא חומר מסוג 'hardware' בספרייה עם `accessory: { type, params,
// finish }`, ולכן המחיר וההצעה בטופס מגיעים משם. השמירה: accessories-save —
// רק שורות ה-hw:, כך שגם נגר (לא רק מנהל) מנהל את האביזרים שלו.

import * as M from './model/materials.js';
import { TYPES, KINDS, FINISHES, buildAccessory, paramsOf, typeOf } from './model/accessories.js';
import { accessoryGroup, accessoryExtent } from './accessory-mesh.js';
import { api } from './store.js';

/** מזהה לאביזר חדש: מהשם אם יש בו אותיות לטיניות, אחרת לפי הזמן — שם עברי לא הופך ל-"custom". */
function newAccessoryId(name) {
  const id = M.newId('hw', name);
  return /^hw:custom(-\d+)?$/.test(id) ? M.newId('hw', `acc-${Date.now().toString(36)}`) : id;
}
const esc = (s) => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');

export function createAccessoriesUI(root, { onChange, onError, getUser }) {
  let kind = 'handle';
  let selectedId = null;
  let preview = null;

  root.innerHTML = `
    <div class="drawer__bar"><strong>🔩 אביזרים</strong><div class="acc__kinds"></div><button type="button" class="btn" data-close="#accessories">סגירה</button></div>
    <div class="acc__body">
      <div class="acc__list"></div>
      <div class="acc__editor">
        <canvas class="acc__preview"></canvas>
        <div class="acc__form"></div>
      </div>
    </div>`;
  const $ = (s, el = root) => el.querySelector(s);
  const kindsEl = $('.acc__kinds');
  kindsEl.innerHTML = KINDS.map((k) => `<button type="button" data-kind="${k.id}">${k.name}</button>`).join('');
  kindsEl.addEventListener('click', (e) => { const b = e.target.closest('[data-kind]'); if (!b) return; kind = b.dataset.kind; selectedId = null; render(); });

  const canEdit = () => getUser() && getUser().role !== 'viewer';
  const kindOf = (m) => M.accessoryKind(m.accessory?.type);
  const list = () => M.all().filter((m) => m.kind === 'hardware' && m.accessory && kindOf(m) === kind && (canEdit() || m.active));

  async function persist() {
    try {
      const r = await api('accessories-save', { diff: M.diff().filter((m) => m.id.startsWith('hw:')) });
      M.load(r.diff);
      onChange();
    } catch (err) { onError(err); }
  }

  function render() {
    kindsEl.querySelectorAll('button').forEach((b) => b.classList.toggle('is-active', b.dataset.kind === kind));
    const items = list();
    if (!selectedId || !items.some((m) => m.id === selectedId)) selectedId = items[0]?.id || null;
    const listEl = $('.acc__list');
    listEl.innerHTML = `
      ${canEdit() ? `<button type="button" class="btn btn--accent acc__new" data-new="1">＋ ${KINDS.find((k) => k.id === kind).name.replace(/ים$/, '')} חדש</button>` : ''}
      ${items.map((m) => `<button type="button" class="acc__card ${m.id === selectedId ? 'is-active' : ''} ${m.active ? '' : 'is-off'}" data-id="${m.id}">
        <b>${esc(m.name)}</b>
        <span class="muted">${esc(typeOf(m.accessory.type)?.name || m.accessory.type)} · ${esc(FINISHES.find((f) => f.id === m.accessory.finish)?.name || '')}</span>
        <span class="muted">₪${m.price ?? 0}${m.active ? '' : ' · מושבת'}</span>
      </button>`).join('') || '<p class="muted">אין אביזרים מהסוג הזה.</p>'}`;
    listEl.onclick = (e) => {
      const b = e.target.closest('[data-id],[data-new]');
      if (!b) return;
      if (b.dataset.new) { addNew(); return; }
      selectedId = b.dataset.id; render();
    };
    renderEditor();
  }

  function addNew() {
    const types = TYPES.filter((t) => t.kind === kind);
    const t = types[0];
    const name = prompt('שם האביזר החדש:', t.name);
    if (!name) return;
    const id = newAccessoryId(name);
    M.upsert({ id, kind: 'hardware', name, price: 0, priceUnit: 'unit', active: true, accessory: { type: t.id, params: paramsOf(t, {}), finish: 'nickel' } });
    selectedId = id;
    persist().then(render);
  }

  function renderEditor() {
    const form = $('.acc__form');
    const m = selectedId && M.material(selectedId);
    if (!m || !m.accessory) { form.innerHTML = '<p class="muted">בוחרים אביזר מהרשימה, או יוצרים חדש.</p>'; showPreview(null); return; }
    const t = typeOf(m.accessory.type) || TYPES.find((x) => x.kind === kind);
    const params = paramsOf(t, m.accessory.params);
    const ro = !canEdit() ? 'disabled' : '';
    const types = TYPES.filter((x) => x.kind === kindOf(m));
    form.innerHTML = `
      <label class="acc__field"><span>שם</span><input name="name" value="${esc(m.name)}" ${ro}></label>
      <label class="acc__field"><span>סוג</span><select name="type" ${ro}>${types.map((x) => `<option value="${x.id}" ${x.id === t.id ? 'selected' : ''}>${esc(x.name)}</option>`).join('')}</select></label>
      <label class="acc__field"><span>גימור</span><select name="finish" ${ro}>${FINISHES.map((f) => `<option value="${f.id}" ${f.id === m.accessory.finish ? 'selected' : ''}>${esc(f.name)}</option>`).join('')}</select></label>
      ${t.params.map((p) => `<label class="acc__field"><span>${esc(p.label)}</span><input type="number" name="p:${p.key}" min="${p.min}" max="${p.max}" step="${p.step || 1}" data-step="${p.step || 1}" value="${params[p.key]}" ${ro}><i>${p.key === 'brake' ? '' : 'מ"מ'}</i></label>`).join('')}
      <label class="acc__field"><span>מחיר ליחידה</span><input type="number" name="price" step="0.5" value="${m.price ?? 0}" ${ro}><i>₪</i></label>
      <label class="acc__field acc__check"><input type="checkbox" name="active" ${m.active ? 'checked' : ''} ${ro}> <span>פעיל (מוצע בטופס)</span></label>
      ${canEdit() ? `<div class="acc__actions"><button type="button" class="btn btn--small" data-dup="1">שכפול</button><button type="button" class="btn btn--small" data-del="1">${m.seed ? 'השבתה' : 'מחיקה'}</button></div>` : ''}
      <p class="muted acc__hint">${esc(t.name)}: הראשית בנקודת ההרכבה על הלוח; האביזר נבנה מגלילים, תיבות וכדורים — אותו דבר שרואים בהדמיה.</p>`;
    showPreview(m);
    form.oninput = (e) => {
      if (!canEdit()) return;
      const inp = e.target;
      const patch = { id: m.id };
      const acc = { ...m.accessory, params: { ...m.accessory.params } };
      if (inp.name === 'name') patch.name = inp.value.trim() || m.name;
      else if (inp.name === 'type') { acc.type = inp.value; acc.params = paramsOf(typeOf(inp.value), {}); }
      else if (inp.name === 'finish') acc.finish = inp.value;
      else if (inp.name.startsWith('p:')) acc.params[inp.name.slice(2)] = Number(inp.value);
      else if (inp.name === 'price') patch.price = Number(inp.value) || 0;
      else if (inp.name === 'active') patch.active = inp.checked;
      patch.accessory = acc;
      M.upsert(patch);
      showPreview(M.material(m.id));
      if (inp.name === 'type') renderEditor();
      clearTimeout(form._t);
      form._t = setTimeout(() => persist().then(() => { if (inp.name === 'name' || inp.name === 'active' || inp.name === 'price') render(); }), 600);
    };
    form.onclick = (e) => {
      if (e.target.dataset.dup) {
        const id = newAccessoryId(m.name);
        M.upsert({ ...m, id, name: m.name + ' (עותק)', seed: false, accessory: { ...m.accessory, params: { ...m.accessory.params } } });
        selectedId = id; persist().then(render);
      } else if (e.target.dataset.del) {
        if (!confirm(m.seed ? `להשבית את "${m.name}"? (אביזר מהזריעה רק מושבת)` : `למחוק את "${m.name}"?`)) return;
        M.remove(m.id); selectedId = null; persist().then(render);
      }
    };
  }

  // ---- תצוגה מקדימה: סצנה קטנה, האביזר על פיסת לוח, מסתובב לאט ----
  function showPreview(m) {
    const canvas = $('.acc__preview');
    if (!window.THREE) return;
    if (!preview) preview = makePreview(canvas);
    preview.set(m ? buildAccessory(m, { side: 'left' }) : null, m?.accessory?.type);
  }
  function makePreview(canvas) {
    const THREE = window.THREE;
    const renderer = new THREE.WebGLRenderer({ canvas, antialias: true, alpha: true });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
    const scene = new THREE.Scene();
    const camera = new THREE.PerspectiveCamera(32, 1, 1, 5000);
    scene.add(new THREE.HemisphereLight(0xffffff, 0xcdbfa9, 1.0));
    const sun = new THREE.DirectionalLight(0xffffff, 0.7); sun.position.set(-200, 300, 400); scene.add(sun);
    const holder = new THREE.Group(); scene.add(holder);
    let radius = 200, angle = 0.6, dragging = false, lastX = 0, kindNow = 'handle';
    canvas.addEventListener('pointerdown', (e) => { dragging = true; lastX = e.clientX; canvas.setPointerCapture(e.pointerId); });
    canvas.addEventListener('pointermove', (e) => { if (dragging) { angle += (e.clientX - lastX) * 0.01; lastX = e.clientX; } });
    canvas.addEventListener('pointerup', () => { dragging = false; });
    function set(acc, type) {
      while (holder.children.length) holder.remove(holder.children[0]);
      if (!acc) return;
      kindNow = acc.type.kind;
      // פיסת לוח שהאביזר יושב עליה: דלת (חזית ב-+z), או תחתית לגלגל
      const ext = accessoryExtent(acc);
      const plate = new THREE.Mesh(new THREE.BoxGeometry(ext * 2.2, ext * 2.2, 18), new THREE.MeshStandardMaterial({ color: 0xe6d7bf, roughness: 0.8, transparent: kindNow === 'hinge', opacity: kindNow === 'hinge' ? 0.4 : 1 }));
      plate.position.z = -9;
      const g = accessoryGroup(THREE, acc);
      if (kindNow === 'hinge') { plate.position.z = 9; }   // הציר יושב על הפאה האחורית; הלוח "לפניו" — שקוף כדי לראות את הכוס
      holder.add(plate); holder.add(g);
      radius = ext * 3.2 + 60;
    }
    function frame() {
      const w = canvas.clientWidth, h = canvas.clientHeight;
      if (w && h && (canvas.width !== Math.round(w * renderer.getPixelRatio()) || canvas.height !== Math.round(h * renderer.getPixelRatio()))) { renderer.setSize(w, h, false); camera.aspect = w / h; camera.updateProjectionMatrix(); }
      if (!dragging) angle += 0.004;
      const el = kindNow === 'wheel' ? -0.35 : 0.35;
      camera.position.set(radius * Math.sin(angle) * Math.cos(el), radius * Math.sin(el), radius * Math.cos(angle) * Math.cos(el));
      camera.lookAt(0, 0, 0);
      renderer.render(scene, camera);
      requestAnimationFrame(frame);
    }
    frame();
    return { set };
  }

  return { open() { root.classList.add('is-open'); render(); }, render };
}
