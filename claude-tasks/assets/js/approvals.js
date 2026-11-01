/*
 * כרטיס 🔐 — בקשת אישור (docs/spec.md §6). אותו כרטיס בחלון המטלה ובבועה
 * בערוץ: סיכום, פירוט ב-<details>, וארבעה כפתורים 2×2.
 * "תמיד" לא מוצג לסוג שאין לו (no / other); בסוג warn — מבקש להקליד את שמו.
 */
import { el, api, toast } from './core.js';

export const DECISION_LABEL = { once: 'אושר פעם אחת', always: 'אושר תמיד', deny: 'נדחה', never: 'נדחה תמיד' };

/**
 * ‏a — הבקשה (lastApproval בשרת). ‏canDecide — האם להציג כפתורים.
 * ‏onDone — נקרא אחרי החלטה שנשמרה.
 */
export function approvalCard(a, taskId, { canDecide = false, pending = true, onDone = () => {} } = {}) {
  const forever = a.type !== 'other';
  const note = el('input', { type: 'text', class: 'ap-note', placeholder: 'הערה לצ\'אט (לא חובה)', 'aria-label': 'הערה לצ\'אט', maxlength: '5000' });
  const send = async (decision, btn) => {
    let confirmType = '';
    if (decision === 'always' && a.always === 'warn') {
      confirmType = prompt(`"אשר תמיד" לפעולה רגישה. כדי לאשר — הקלד את שם הסוג: ${a.type}`) ?? '';
      if (confirmType.trim() !== a.type) { if (confirmType) toast('השם לא תאם — לא נשמר', 'error'); return; }
    }
    btn.disabled = true;
    try {
      await api('decide', { id: taskId, decision, note: note.value, confirm_type: confirmType });
      toast(`${DECISION_LABEL[decision]} — המטלה חזרה לתור של Claude`, 'ok');
      onDone(decision);
    } catch (ex) { toast(ex.message, 'error'); btn.disabled = false; }
  };
  const btn = (decision, text, cls, title) => el('button', {
    type: 'button', class: `btn ${cls}`, title, onclick: (e) => send(decision, e.currentTarget),
  }, [text]);

  const buttons = canDecide && pending ? [
    btn('once', '✅ פעם אחת', 'primary', 'מאשר רק את הבקשה הזאת'),
    a.always !== 'no' && forever ? btn('always', a.always === 'warn' ? '⚠️ תמיד' : '♾️ תמיד', 'ghost',
      'מעכשיו בלי לשאול — נכתב למדיניות') : null,
    btn('deny', '✋ לא', 'ghost', 'דוחה את הבקשה הזאת — הצ\'אט יבחר דרך אחרת'),
    forever ? btn('never', '⛔ אף פעם', 'ghost danger-text', 'מעכשיו לא לבקש — נכתב למדיניות') : null,
  ].filter(Boolean) : [];

  return el('div', { class: 'approval' }, [
    el('div', { class: 'ap-head' }, [
      el('span', { class: 'badge', text: `🔐 ${a.label || a.type}` }),
      a.always === 'warn' ? el('span', { class: 'badge badge--blocked', text: '⚠️ רגיש' }) : null,
      !pending ? el('span', { class: 'badge', text: 'הוחלט' }) : null,
    ]),
    el('p', { class: 'ap-summary', dir: 'auto', text: a.summary }),
    a.detail || a.target ? el('details', {}, [
      el('summary', { text: 'פירוט' }),
      a.target ? el('p', { class: 'ap-target', dir: 'ltr', text: a.target }) : null,
      a.detail ? el('p', { class: 'ap-detail', dir: 'auto', text: a.detail }) : null,
    ]) : null,
    buttons.length ? note : null,
    buttons.length ? el('div', { class: 'ap-buttons' }, buttons) : null,
    canDecide || !pending ? null : el('p', { class: 'hint', text: 'ממתין להחלטה של מי שרשאי להחליט כאן.' }),
  ]);
}

/** הערת approval / decision בשרשור המטלה — JSON בשרת, טקסט קריא כאן. */
export function noteText(n) {
  if (n.kind !== 'approval' && n.kind !== 'decision') return n.body;
  let d;
  try { d = JSON.parse(n.body); } catch { return n.body; }
  if (n.kind === 'approval') return `🔐 ${d.summary || ''}${d.target ? `\n${d.target}` : ''}`;
  return `${DECISION_LABEL[d.decision] || d.decision}${d.note ? ` — ${d.note}` : ''}`;
}
