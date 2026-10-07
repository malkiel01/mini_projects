// צפיית לקוח: מודל והדמיה מתוך קישור עם אסימון. אין כניסה, אין מחירים,
// אין רשימת חיתוך. אותו מודל ואותו צופה כמו בכלי — רק בלי הטופס.

import { build } from './model/index.js';
import { cutSize } from './model/blocks.js';
import * as M from './model/materials.js';
import { createViewer } from './viewer.js';
import { viewByToken } from './store.js';

const $ = (s) => document.querySelector(s);
const esc = (s) => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;');

async function main() {
  if (!window.THREE) { showError('לא הצלחתי לטעון את מנוע התלת מימד. בדקו את החיבור ורעננו.'); return; }
  const token = new URLSearchParams(location.search).get('t') || '';
  let data;
  try { data = await viewByToken(token); } catch (err) { showError(err.message); return; }

  M.load(data.materials.diff);
  for (const [id, v] of Object.entries(data.materials.images || {})) M.setImage(id, v.url, v.imageMm);

  const p = data.project;
  document.title = `${p.name} — הדמיה`;
  $('#view-name').textContent = p.name;
  $('#view-sub').textContent = [p.client, p.owner_name ? `נגר: ${p.owner_name}` : ''].filter(Boolean).join(' · ');

  const viewer = createViewer($('#stage'), { onPick: showPart });
  document.querySelectorAll('[data-view]').forEach((b) => b.addEventListener('click', () => viewer.view(b.dataset.view)));
  let model;
  try { model = build(p.template_key, p.values); } catch (err) { showError(`תבנית לא מוכרת: ${p.template_key}`); return; }
  viewer.setModel(model);
  viewer.frame(model.bounds);
}

function showError(msg) {
  const el = $('#view-error');
  el.hidden = false;
  el.textContent = msg;
}

function showPart(p) {
  const box = $('#part');
  if (!p) { box.innerHTML = '<p class="muted">גרירה מסובבת; שתי אצבעות או גלגלת מזמנות. הקשה על לוח מציגה את מידותיו.</p>'; return; }
  const c = cutSize(p);
  box.innerHTML = `<h3>${esc(p.name)}</h3><dl><dt>מידות</dt><dd><b>${c.l} × ${c.w}</b> × ${c.t} מ"מ</dd><dt>חומר</dt><dd>${esc(M.material(p.material).name)}</dd></dl>`;
}

main();
