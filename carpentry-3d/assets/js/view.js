// צפיית לקוח: מודל והדמיה מתוך קישור עם אסימון. אין כניסה, אין מחירים,
// אין רשימת חיתוך. אותו מודל ואותו צופה כמו בכלי — רק בלי הטופס.

import { build } from './model/index.js';
import { applyRules } from './model/rules.js';
import { cutSize } from './model/blocks.js';
import * as M from './model/materials.js';
import { createViewer } from './viewer.js';
import { viewByToken, viewAssemblyByToken } from './store.js';
import { combine } from './model/assembly.js';
import { wireFullscreen } from './fullscreen.js';

const fs = wireFullscreen(document.querySelector('.view-main'), document.querySelector('#btn-fullscreen'));
window.addEventListener('popstate', () => fs.handlePop());

const $ = (s) => document.querySelector(s);
const esc = (s) => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;');

async function main() {
  if (!window.THREE) { showError('לא הצלחתי לטעון את מנוע התלת מימד. בדקו את החיבור ורעננו.'); return; }
  const qs = new URLSearchParams(location.search);
  const asmToken = qs.get('a') || '';
  const token = qs.get('t') || '';
  let data;
  try { data = asmToken ? await viewAssemblyByToken(asmToken) : await viewByToken(token); } catch (err) { showError(err.message); return; }

  M.load(data.materials.diff);
  for (const [id, v] of Object.entries(data.materials.images || {})) M.setImage(id, v.url, v.imageMm);

  const p = data.project || data.assembly;
  document.title = `${p.name} — הדמיה`;
  $('#view-name').textContent = p.name;
  $('#view-sub').textContent = [p.client || p.client_name, p.owner_name ? `נגר: ${p.owner_name}` : '', data.assembly ? 'הרכבה' : ''].filter(Boolean).join(' · ');

  const viewer = createViewer($('#stage'), { onPick: (p) => showPart(p, viewer) });
  document.querySelectorAll('[data-view]').forEach((b) => b.addEventListener('click', () => viewer.view(b.dataset.view)));
  const sync = () => { $('#btn-open-all').classList.toggle('is-on', viewer.anyOpen()); $('#btn-ghost-fronts').classList.toggle('is-on', viewer.anyGhost()); };
  $('#btn-open-all').addEventListener('click', () => { viewer.openAll(!viewer.anyOpen()); sync(); if (shown) showPart(shown, viewer); });
  $('#btn-ghost-fronts').addEventListener('click', () => { viewer.ghostFronts(!viewer.anyGhost()); sync(); if (shown) showPart(shown, viewer); });
  $('#part').addEventListener('click', (e) => {
    const b = e.target.closest('[data-part-open],[data-part-ghost]');
    if (!b || !shown) return;
    if (b.dataset.partOpen) viewer.toggleOpen(b.dataset.partOpen);
    if (b.dataset.partGhost) viewer.toggleGhost(b.dataset.partGhost);
    showPart(shown, viewer); sync();
  });
  applyRules(data.rules);   // התאמות מעבדת המוצרים — אותו מודל כמו אצל הנגר
  let model;
  try {
    if (data.assembly) {
      const a = data.assembly;
      model = combine(a.items.map((it, i) => { const pr = a.projects.find((x) => x.id === it.project_id); return pr ? { model: build(pr.template_key, pr.values), pos: it.pos, rot: it.rot, visible: it.visible !== false, name: pr.name, key: i + 1 } : null; }).filter(Boolean), { joined: a.joined, name: a.name });
    } else model = build(p.template_key, p.values);
  } catch (err) { showError(`תבנית לא מוכרת: ${p.template_key || ''}`); return; }
  viewer.setModel(model);
  viewer.frame(model.bounds);
}

function showError(msg) {
  const el = $('#view-error');
  el.hidden = false;
  el.textContent = msg;
}

let shown = null;
function showPart(p, viewer) {
  const box = $('#part');
  shown = p;
  if (!p) { box.innerHTML = '<p class="muted">גרירה מסובבת; שתי אצבעות או גלגלת מזמנות. הקשה על לוח מציגה את מידותיו.</p>'; return; }
  const c = cutSize(p);
  const m = p.motion, open = m && viewer.isOpen(m.group), ghost = viewer.isGhost(p.id);
  const verb = m ? (m.kind === 'hinge' ? (open ? 'סגירת הדלת' : 'פתיחת הדלת') : (open ? 'סגירה' : 'פתיחה')) : '';
  box.innerHTML = `<h3>${esc(p.name)}</h3><dl><dt>מידות</dt><dd><b>${c.l} × ${c.w}</b> × ${c.t} מ"מ</dd><dt>חומר</dt><dd>${esc(M.material(p.material).name)}</dd></dl>
    <div class="part__actions">
      ${m ? `<button type="button" class="btn btn--small ${open ? 'is-on' : ''}" data-part-open="${esc(m.group)}">${m.kind === 'hinge' ? '🚪' : '↔️'} ${verb}</button>` : ''}
      <button type="button" class="btn btn--small ${ghost ? 'is-on' : ''}" data-part-ghost="${esc(p.id)}">👁 ${ghost ? 'אטום' : 'שקוף'}</button>
    </div>`;
}

main();
