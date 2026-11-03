/*
 * 🔐 בקשת הרשאה מצ'אט — docs/spec.md §5.12.3 (שלב 13).
 *
 * אותו כרטיס בבועה בשיחה ובפס שבראש המסך (בכל מסך, כי זה דחוף): הכלי, הפקודה המלאה בבלוק קוד,
 * התיאור של הצ'אט, ושלושה כפתורים — "✅ פעם אחת" · "✅ תמיד בצ'אט הזה" (מוסתר כשאסור, והסיבה
 * מתחת) · "⛔ לא" (שדה סיבה רשות שחוזר לצ'אט). ספירה לאחור עד שה-hook מפסיק לחכות — החלון
 * באפליקציה פתוח כל הזמן, ומי שעונה ראשון קובע (§5.12.2a). רק בעל הלוח עונה.
 * אחרי מענה — שורה אחת: "✓ אושר פעם אחת · 21:40 · מלכיאל".
 */
import { $, el, api, toast, state } from './core.js';

const isOwner = () => state.user?.role === 'admin';
const hhmm = (ts) => (ts ? new Date(ts * 1000).toLocaleTimeString('he-IL', { hour: '2-digit', minute: '2-digit' }) : '');
const mmss = (s) => `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;

/** מהודעה בשיחה או משורה של permissions-open — לאותה צורה. */
export function permFromMessage(m) {
  const x = m.meta || {};
  return {
    message_id: m.id, status: m.status, tool_name: x.tool_name || '', command: x.command || '', description: x.description || '',
    deadline: x.deadline || 0, always_ok: !!x.always?.ok, always_label: x.always?.label || '', always_reason: x.always?.reason || '',
    alias: !!x.alias, decision: x.decision || null, reason: x.reason || '', decided_by_name: x.decided_by_name || '',
    decided_at: x.decided_at || 0, closed_how: x.closed_how || '', closed_at: x.closed_at || 0,
  };
}

/** השורה המקופלת אחרי שהבקשה נסגרה. */
function closedLine(p) {
  const who = [hhmm(p.decided_at || p.closed_at), p.decided_by_name].filter(Boolean).join(' · ');
  let text;
  if (p.status === 'answered') {
    text = p.decision === 'once' ? '✓ אושר פעם אחת'
      : p.decision === 'always' ? `✓ אושר תמיד בצ'אט הזה${p.always_label ? ` (${p.always_label})` : ''}`
      : `⛔ נדחה${p.reason ? ` — ${p.reason}` : ''}`;
  } else if (p.status === 'moved_to_app') {
    text = '⌛ עבר לאפליקציה — לא נענה בלוח תוך 10 דקות';
  } else {
    text = p.closed_how === 'stop' ? '✓ הוכרע באפליקציה' : '✓ אושר באפליקציה / אוטומטית';
  }
  return el('div', { class: `perm perm--done perm--${p.status === 'answered' && p.decision === 'deny' ? 'deny' : 'ok'}` }, [
    el('p', { class: 'perm-done-head' }, [el('span', { text }), who ? el('small', { text: ` · ${who}` }) : null].filter(Boolean)),
    el('details', {}, [el('summary', { text: `🔐 ${p.tool_name}` }), el('pre', { class: 'perm-cmd', dir: 'ltr' }, [el('code', { text: p.command })])]),
  ]);
}

/**
 * הכרטיס. ‏opts.onDone — אחרי מענה (רענון). ‏opts.channel — שם הצ'אט (בפס הראשי).
 */
export function permCard(p, { onDone, channel } = {}) {
  if (p.status && p.status !== 'open') return closedLine(p);
  const owner = isOwner();
  const countdown = el('p', { class: 'perm-timer', role: 'timer', 'aria-live': 'off' });
  const tick = () => {
    const left = Math.max(0, (p.deadline || 0) - Math.floor(Date.now() / 1000));
    countdown.textContent = left > 0 ? `עוד ${mmss(left)} · אפשר לענות גם בחלון באפליקציה` : 'עבר הזמן — עונים באפליקציה';
    if (!countdown.isConnected && countdown.dataset.started) clearInterval(timer);
    countdown.dataset.started = '1';
  };
  const timer = setInterval(tick, 1000); tick();

  const reasonBox = el('textarea', { class: 'perm-reason', rows: '2', hidden: true, maxlength: '1000', dir: 'auto',
    'aria-label': 'סיבה (רשות) — תגיע לצ\'אט', placeholder: 'למה? (רשות — תגיע לצ\'אט)' });
  const box = el('div', { class: 'perm', role: 'group', 'aria-label': `בקשת הרשאה: ${p.tool_name}` });
  const answer = async (decision) => {
    box.querySelectorAll('button, textarea').forEach((b) => { b.disabled = true; });
    try {
      await api('permission-answer', { message_id: p.message_id, decision, reason: decision === 'deny' ? reasonBox.value : '' });
      toast(decision === 'deny' ? 'נדחה — הצ\'אט יקבל את הסיבה' : 'אושר — הצ\'אט ממשיך', 'ok');
    } catch (ex) { toast(ex.message, 'error'); }
    clearInterval(timer);
    onDone?.();
  };
  const denyBtn = el('button', { type: 'button', class: 'btn ghost perm-deny', text: '⛔ לא', disabled: !owner,
    onclick: () => {
      if (reasonBox.hidden) { reasonBox.hidden = false; denyBtn.textContent = '⛔ לדחות'; reasonBox.focus(); return; }
      answer('deny');
    } });
  box.replaceChildren(...[
    el('p', { class: 'perm-head' }, [el('b', { text: '🔐 בקשת הרשאה' }), channel ? el('span', { text: ` · ${channel}` }) : null,
      el('code', { class: 'perm-tool', dir: 'ltr', text: p.tool_name })].filter(Boolean)),
    p.description ? el('p', { class: 'perm-desc', dir: 'auto', text: p.description }) : null,
    el('pre', { class: 'perm-cmd', dir: 'ltr', tabindex: '0', 'aria-label': 'הפקודה המלאה' }, [el('code', { text: p.command })]),
    owner ? el('div', { class: 'perm-acts' }, [
      el('button', { type: 'button', class: 'btn primary', text: '✅ פעם אחת', onclick: () => answer('once') }),
      p.always_ok ? el('button', { type: 'button', class: 'btn ghost', title: p.always_label ? `מעכשיו בצ'אט הזה: ${p.always_label}` : '',
        text: '✅ תמיד בצ\'אט הזה', onclick: () => answer('always') }) : null,
      denyBtn,
    ].filter(Boolean)) : el('p', { class: 'hint', text: 'רק בעל הלוח מאשר הרשאות.' }),
    owner ? reasonBox : null,
    owner && p.always_ok && p.always_label ? el('p', { class: 'hint', dir: 'auto', text: `"תמיד" יאשר בצ'אט הזה כל פקודה שמתחילה ב: ${p.always_label}` }) : null,
    owner && !p.always_ok && p.always_reason ? el('p', { class: 'hint', text: `אין "תמיד": ${p.always_reason}` }) : null,
    p.alias ? el('p', { class: 'hint', text: 'כלי בשם מחולל — ייתכן שאפשר לאשר אותו רק באפליקציה.' }) : null,
    countdown,
  ].filter(Boolean));
  return box;
}

/* ── הפס בראש המסך: כל הבקשות הפתוחות, בכל מסך ─────────────── */

let stripTimer = null;
let stripBusy = false;
let shown = null;   // החתימה של מה שמוצג; null — לבנות מחדש בפעם הבאה

async function refreshStrip() {
  const strip = $('#permStrip');
  if (!strip || stripBusy || !isOwner()) return;
  stripBusy = true;
  try {
    const { requests } = await api('permissions-open');
    const sig = requests.map((r) => r.message_id).join(',');
    if (sig === shown) return;          // בלי לבנות מחדש — אחרת שדה הסיבה מתאפס באמצע הקלדה
    shown = sig;
    strip.replaceChildren(...requests.slice(0, 3).map((r) => el('div', { class: 'perm-strip-item' }, [
      permCard({ ...r, status: 'open' }, { channel: `${r.channel_icon || '💬'} ${r.channel_name}`, onDone: () => { shown = null; refreshStrip(); } }),
      el('button', { type: 'button', class: 'btn ghost sm', text: 'לשיחה ‹',
        onclick: () => document.dispatchEvent(new CustomEvent('ct:open-channel', { detail: r.channel_id })) }),
    ])), ...(requests.length > 3 ? [el('p', { class: 'hint', text: `ועוד ${requests.length - 3} בקשות` })] : []));
    strip.hidden = !requests.length;
  } catch { /* רשת — בפעם הבאה */ } finally { stripBusy = false; }
}

/** בעל הלוח בלבד: בדיקה כל 10 ש' כשהלשונית גלויה. */
export function startPermStrip() {
  if (stripTimer || !isOwner()) return;
  refreshStrip();
  stripTimer = setInterval(() => { if (!document.hidden) refreshStrip(); }, 10000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) refreshStrip(); });
}
export const refreshPermStrip = () => { shown = null; return refreshStrip(); };
