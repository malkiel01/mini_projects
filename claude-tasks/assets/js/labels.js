/*
 * 📌 הצמדה ו-🏷 תוויות במסך השיחה — docs/spec.md §5.11, שלב 12c.
 *
 * התווית קובעת: נראות (משותפת — כולם רואים את הסימון / פרטית — רק אני) והיקף (כל הלוח /
 * הפרויקט הזה). ההצמדה — "לכולם" או "לי". 8 צבעים קבועים — אותם גוונים של ריבוע הצ'אט.
 *
 * המודול לא מכיר את מסך השיחה: chats.js מעביר לו את הערוץ ואת הפעולות (רענון, קפיצה להודעה).
 */
import { $, el, api, toast, state } from './core.js';

export const LABEL_HUES = { blue: 215, teal: 180, green: 140, olive: 85, amber: 45, orange: 22, red: 355, violet: 270 };
const COLOR_NAME = { blue: 'כחול', teal: 'טורקיז', green: 'ירוק', olive: 'זית', amber: 'ענבר', orange: 'כתום', red: 'אדום', violet: 'סגול' };
const isOwner = () => state.user?.role === 'admin';

/** שבב תווית קטן — בשורת המטא של הבועה. */
export function labelChip(l) {
  return el('span', { class: 'lbl', style: `--hue:${LABEL_HUES[l.color] ?? 215}`, title: l.visibility === 'private' ? 'תווית פרטית — רק אתה רואה' : 'תווית משותפת' },
    [l.visibility === 'private' ? '🔒 ' : '', l.name]);
}

/* ── 🏷 גיליון התוויות: להודעה (סימון), או לניהול ───────────────────── */

let ctx = null;   // { channel, message?, onChange }

async function loadLabels() {
  const { labels } = await api('labels', { channel_id: ctx.channel.id });
  return labels;
}

function swatches(name, selected) {
  return el('div', { class: 'cs-colors', role: 'radiogroup', 'aria-label': 'צבע' }, Object.keys(LABEL_HUES).map((k) => el('button', {
    type: 'button', role: 'radio', class: 'cs-color', name, 'aria-checked': String(k === selected), dataset: { color: k },
    'aria-label': COLOR_NAME[k], title: COLOR_NAME[k], style: `--hue:${LABEL_HUES[k]}`,
    onclick: (e) => e.currentTarget.parentElement.querySelectorAll('.cs-color').forEach((b) => b.setAttribute('aria-checked', String(b === e.currentTarget))),
  })));
}

function seg(label, options, selected) {
  return el('div', { class: 'seg', role: 'radiogroup', 'aria-label': label }, options.map(([v, text, disabled]) => el('button', {
    type: 'button', role: 'radio', dataset: { v }, 'aria-checked': String(v === selected), disabled: !!disabled,
    onclick: (e) => e.currentTarget.parentElement.querySelectorAll('button').forEach((b) => b.setAttribute('aria-checked', String(b === e.currentTarget))),
  }, [text])));
}
const picked = (box) => box.querySelector('[aria-checked="true"]')?.dataset;

/** טופס תווית חדשה / עריכה. בעריכה — רק שם וצבע (הסוג נקבע ביצירה). */
function labelForm(existing, done) {
  const owner = isOwner();
  const name = el('input', { type: 'text', maxlength: '30', dir: 'auto', value: existing?.name || '', placeholder: 'למשל: ✅ החלטה', 'aria-label': 'שם התווית' });
  const colors = swatches('lblColor', existing?.color || 'blue');
  // משותפת / של כל הלוח — בעל הלוח בלבד (§5.11.2)
  const vis = existing ? null : seg('נראות', [['shared', '👥 משותפת', !owner], ['private', '🔒 רק לי']], owner ? 'shared' : 'private');
  const scope = existing ? null : seg('היקף', [['project', `📁 ${ctx.channel.project_name || 'הפרויקט הזה'}`], ['board', '🌐 כל הלוח', !owner]], 'project');
  const save = el('button', { type: 'button', class: 'btn primary', text: existing ? 'שמירה' : 'יצירה' });
  save.addEventListener('click', async () => {
    try {
      const body = { name: name.value, color: picked(colors)?.color };
      if (existing) body.id = existing.id;
      else Object.assign(body, { visibility: picked(vis).v, project_id: picked(scope).v === 'board' ? null : ctx.channel.project_id });
      const { label } = await api('label-save', body);
      done(label);
    } catch (ex) { toast(ex.message, 'error'); }
  });
  return el('div', { class: 'lbl-form' }, [
    el('label', { class: 'field' }, [el('span', { text: 'שם' }), name]),
    el('p', { class: 'hint', text: 'צבע' }), colors,
    vis ? el('p', { class: 'hint', text: owner ? 'מי רואה את הסימון' : 'מי רואה את הסימון — תווית משותפת יוצר בעל הלוח' }) : null, vis,
    scope ? el('p', { class: 'hint', text: 'איפה התווית זמינה' }) : null, scope,
    el('div', { class: 'row-btns' }, [save]),
  ].filter(Boolean));
}

async function renderForMessage() {
  const m = ctx.message;
  const labels = await loadLabels();
  const on = new Set((m.labels || []).map((l) => l.id));
  const canShared = ctx.channel.can_send && ctx.channel.status === 'active';
  const rows = labels.map((l) => {
    const disabled = l.visibility === 'shared' && !canShared;
    return el('button', {
      type: 'button', class: 'lbl-toggle', 'aria-pressed': String(on.has(l.id)), disabled,
      title: disabled ? 'תווית משותפת — מי שרשאי לכתוב לצ\'אט' : '',
      onclick: async (e) => {
        const now = e.currentTarget.getAttribute('aria-pressed') !== 'true';
        try {
          await api('message-label', { message_id: m.id, label_id: l.id, on: now });
          e.currentTarget.setAttribute('aria-pressed', String(now));
          if (now) on.add(l.id); else on.delete(l.id);
          m.labels = labels.filter((x) => on.has(x.id));
          ctx.onChange?.();
        } catch (ex) { toast(ex.message, 'error'); }
      },
    }, [el('span', { class: 'lbl-check', 'aria-hidden': 'true' }), labelChip(l),
        el('small', { text: l.project_id === null ? 'כל הלוח' : '' })]);
  });
  $('#lsT').textContent = 'תוויות להודעה';
  $('#lsBody').replaceChildren(...[
    rows.length ? el('div', { class: 'lbl-list' }, rows) : el('p', { class: 'hint', text: 'עוד אין תוויות. צרו את הראשונה:' }),
    el('details', { class: 'lbl-new', open: rows.length ? null : true }, [el('summary', { text: '+ תווית חדשה' }),
      labelForm(null, async (label) => {
        try { await api('message-label', { message_id: m.id, label_id: label.id, on: true }); } catch (ex) { toast(ex.message, 'error'); }
        m.labels = [...(m.labels || []), label];
        ctx.onChange?.();
        renderForMessage();
      })]),
  ].filter(Boolean));
}

export async function openLabelsForMessage(channel, message, onChange) {
  ctx = { channel, message, onChange };
  $('#lsBody').replaceChildren(el('p', { class: 'hint', text: '…' }));
  $('#labelSheet').showModal();
  try { await renderForMessage(); } catch (ex) { toast(ex.message, 'error'); }
}

async function renderManage() {
  const labels = await loadLabels();
  const row = (l) => el('li', { class: 'card lbl-row' }, [
    el('div', { class: 'body' }, [
      el('div', { class: 'l1' }, [labelChip(l)]),
      el('div', { class: 'l2', text: `${l.visibility === 'private' ? '🔒 רק לך' : '👥 משותפת'} · ${l.project_id === null ? 'כל הלוח' : 'הפרויקט'} · ${l.uses === 1 ? 'הודעה אחת' : `${l.uses} הודעות`}` }),
    ]),
    l.can_edit ? el('button', { type: 'button', class: 'icon-btn', 'aria-label': `עריכת ${l.name}`, text: '✏️',
      onclick: () => { $('#lsBody').replaceChildren(labelForm(l, () => { toast('נשמר', 'ok'); ctx.onChange?.(); renderManage(); })); } }) : null,
    l.can_edit ? el('button', { type: 'button', class: 'icon-btn danger-text', 'aria-label': `מחיקת ${l.name}`, text: '🗑',
      onclick: async () => {
        if (!confirm(`למחוק את התווית "${l.name}"? ${l.uses ? `היא מסומנת על ${l.uses} הודעות — הסימונים יימחקו.` : ''}`)) return;
        try { const { cleared } = await api('label-delete', { id: l.id }); toast(`נמחקה${cleared ? ` · ${cleared} סימונים הוסרו` : ''}`, 'ok'); ctx.onChange?.(); renderManage(); }
        catch (ex) { toast(ex.message, 'error'); }
      } }) : null,
  ].filter(Boolean));
  $('#lsT').textContent = 'ניהול תוויות';
  $('#lsBody').replaceChildren(...[
    labels.length ? el('ul', { class: 'list' }, labels.map(row)) : el('p', { class: 'hint', text: 'עוד אין תוויות.' }),
    el('details', { class: 'lbl-new' }, [el('summary', { text: '+ תווית חדשה' }), labelForm(null, () => { toast('נוצרה', 'ok'); renderManage(); })]),
  ]);
}

export async function openManageLabels(channel, onChange) {
  ctx = { channel, onChange };
  $('#lsBody').replaceChildren(el('p', { class: 'hint', text: '…' }));
  $('#labelSheet').showModal();
  try { await renderManage(); } catch (ex) { toast(ex.message, 'error'); }
}

/* ── 📌 מוצמדות: פס בראש השיחה, ורשימה ───────────────────────────── */

const plainText = (s, n) => {
  const t = String(s || '').replace(/```[\s\S]*?```/g, ' [קוד] ').replace(/[`*_>#~|]/g, '').replace(/\s+/g, ' ').trim();
  return t.length > n ? `${t.slice(0, n - 1)}…` : t;
};

/** הפס: "📌 N מוצמדות · <האחרונה>" — נגיעה קופצת אליה; ▾ פותח את כולן. */
/* ── 📌 פס המוצמדות: פס / בועה צפה / מוסתר — לכל מכשיר ──────────────
 * ✕ בפס הופך אותו לבועה צפה. את הבועה גוררים לכל מקום (המיקום נשמר); גרירה אל ה-✕ שמופיע למטה בזמן
 * הגרירה מסירה אותה. ⚙ ← "פס המוצמדות" מחזיר את הפס. נגיעה בבועה — רשימת המוצמדות. */
const PINS_MODE_KEY = 'ct-pins-mode', PINS_POS_KEY = 'ct-pins-pos';
const store = {
  get: (k) => { try { return localStorage.getItem(k); } catch { return null; } },
  set: (k, v) => { try { if (v === null) localStorage.removeItem(k); else localStorage.setItem(k, v); } catch { /* פרטי / חסום */ } },
};
export const pinsMode = () => (['strip', 'bubble', 'hidden'].includes(store.get(PINS_MODE_KEY)) ? store.get(PINS_MODE_KEY) : 'strip');
let lastPins = [], lastJump = () => {};
export function setPinsMode(mode) {
  store.set(PINS_MODE_KEY, mode === 'strip' ? null : mode);
  renderPinStrip(lastPins, lastJump);
}

export function renderPinStrip(pins, jump) {
  lastPins = pins; lastJump = jump;
  const box = $('#cvPins');
  const mode = pinsMode();
  box.hidden = !pins.length || mode !== 'strip';
  renderPinBubble(pins, jump, mode === 'bubble' && pins.length > 0);
  if (box.hidden) { box.replaceChildren(); return; }
  const last = pins[0];
  // replaceChildren הופך null לטקסט "null" — עם מוצמדת אחת אין ▾, ולכן מסננים (כך הופיע "null" בפס)
  box.replaceChildren(...[
    el('button', { type: 'button', class: 'pins-last', onclick: () => jump(last.message_id) }, [
      el('b', { text: `📌 ${pins.length === 1 ? 'מוצמדת' : `${pins.length} מוצמדות`}` }),
      el('span', { dir: 'auto', text: `${last.visibility === 'private' ? '🔒 ' : ''}${plainText(last.body, 70)}` }),
    ]),
    pins.length > 1 ? el('button', { type: 'button', class: 'icon-btn', 'aria-label': 'כל המוצמדות', text: '▾', onclick: () => openPinList(pins, jump) }) : null,
    el('button', { type: 'button', class: 'icon-btn pins-close', 'aria-label': 'הסתרת הפס — יהפוך לבועה צפה', title: 'הסתרה — לבועה צפה',
      text: '✕', onclick: () => setPinsMode('bubble') }),
  ].filter(Boolean));
}

function renderPinBubble(pins, jump, show) {
  const b = $('#pinBubble');
  if (!b) return;
  b.hidden = !show;
  if (!show) return;
  b.textContent = `📌${pins.length > 1 ? ` ${pins.length}` : ''}`;
  b.setAttribute('aria-label', `${pins.length === 1 ? 'מוצמדת אחת' : `${pins.length} מוצמדות`} — נגיעה פותחת, גרירה מזיזה`);
  const pos = (() => { try { return JSON.parse(store.get(PINS_POS_KEY) || 'null'); } catch { return null; } })();
  const place = (x, y) => {
    const r = b.getBoundingClientRect();
    b.style.left = `${Math.max(4, Math.min(innerWidth - r.width - 4, x))}px`;
    b.style.top = `${Math.max(4, Math.min(innerHeight - r.height - 4, y))}px`;
  };
  if (pos) place(pos.x * innerWidth, pos.y * innerHeight); else { b.style.left = ''; b.style.top = ''; }
  if (b.dataset.wired) return;
  b.dataset.wired = '1';
  const drop = $('#pinDrop');
  let start = null, moved = false;
  const overDrop = (e) => { const r = drop.getBoundingClientRect(); return e.clientX >= r.left - 12 && e.clientX <= r.right + 12 && e.clientY >= r.top - 12 && e.clientY <= r.bottom + 12; };
  b.addEventListener('pointerdown', (e) => {
    const r = b.getBoundingClientRect();
    start = { x: e.clientX, y: e.clientY, dx: e.clientX - r.left, dy: e.clientY - r.top };
    moved = false; b.setPointerCapture(e.pointerId);
  });
  b.addEventListener('pointermove', (e) => {
    if (!start) return;
    if (!moved && Math.hypot(e.clientX - start.x, e.clientY - start.y) < 8) return;
    moved = true; drop.hidden = false; b.classList.add('is-dragging');
    place(e.clientX - start.dx, e.clientY - start.dy);
    drop.classList.toggle('is-over', overDrop(e));
  });
  const end = (e) => {
    if (!start) return;
    const wasMove = moved; start = null; b.classList.remove('is-dragging');
    if (wasMove) {
      const remove = overDrop(e);
      drop.hidden = true; drop.classList.remove('is-over');
      if (remove) { setPinsMode('hidden'); return; }
      const r = b.getBoundingClientRect();
      store.set(PINS_POS_KEY, JSON.stringify({ x: r.left / innerWidth, y: r.top / innerHeight }));
    }
  };
  b.addEventListener('pointerup', end);
  b.addEventListener('pointercancel', end);
  b.addEventListener('click', () => { if (!moved) openPinList(lastPins, lastJump); });
}

export function openPinList(pins, jump) {
  $('#psList').replaceChildren(...(pins.length ? pins.map((p) => el('li', {
    class: 'card', tabindex: '0', role: 'button',
    onclick: () => { $('#pinSheet').close(); jump(p.message_id); },
    onkeydown: (e) => { if (e.key === 'Enter') { $('#pinSheet').close(); jump(p.message_id); } },
  }, [
    el('span', { class: 'ic', 'aria-hidden': 'true', text: p.visibility === 'private' ? '🔒' : '📌' }),
    el('div', { class: 'body' }, [el('div', { class: 'l1', dir: 'auto', text: plainText(p.body, 120) }),
      el('div', { class: 'l2', text: p.visibility === 'private' ? 'הצמדה שלך בלבד' : 'מוצמדת לכולם' })]),
    el('span', { class: 'go', 'aria-hidden': 'true', text: '‹' }),
  ])) : [el('li', { class: 'hint', text: 'אין הודעות מוצמדות. נגיעה ארוכה על הודעה ← 📌.' })]));
  $('#pinSheet').showModal();
}
