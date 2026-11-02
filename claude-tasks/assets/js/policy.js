/*
 * שלב 7b (docs/spec.md §6.1) — אישורים לכל פרויקט, וכלליים לכל הלוח.
 *
 * שורה לכל סוג בקטלוג: מאיפה הכלל ("כאן" / "יורש מ" / "כללי" / "לא נקבע"), ובורר
 * שואל · תמיד מותר · אף פעם. כלל שנקבע כאן — מלא; ירושה — רק מתאר, כדי שיהיה
 * ברור ששום דבר לא נקבע כאן. מתחת — "מה הוחלט": כללים, וגם החלטות של פעם אחת.
 */
import { $, el, api, toast } from './core.js';

const VERDICT = { ask: 'שואל', allow: 'תמיד מותר', deny: 'אף פעם' };
const ICON = { allow: '✅', once: '✅', deny: '⛔', never: '⛔', ask: '↩︎' };
let cur = { projectId: null, name: '', data: null };

/** פותח את הגיליון. ‏projectId = null — האישורים הכלליים לכל הלוח. */
export async function openPolicySheet(projectId, name = '') {
  cur = { projectId, name, data: null };
  $('#pvsPolicyT').textContent = projectId === null ? 'אישורים כלליים' : `אישורים · ${name}`;
  $('#polHint').textContent = projectId === null
    ? 'מה Claude רשאי לעשות בכל הלוח בלי לשאול. פרויקט שקבע כלל לעצמו — הכלל שלו גובר.'
    : 'מה Claude רשאי לעשות בפרויקט הזה בלי לשאול. כלל כאן גובר על מה שבפרויקט האב.';
  $('#polRows').replaceChildren(el('li', { class: 'hint', text: 'טוען…' }));
  $('#polHistory').replaceChildren();
  if (!$('#pvsPolicy').open) $('#pvsPolicy').showModal();
  await reload();
}

/** "3 מותרים · 1 חסום" — לשורה בגיליון ⚙. */
export async function policySubline(projectId) {
  const { counts } = await api('policy-list', { project_id: projectId });
  const parts = [];
  if (counts.allow) parts.push(`${counts.allow} מותרים`);
  if (counts.deny) parts.push(`${counts.deny} חסומים`);
  return parts.join(' · ') || 'אין כללים';
}

async function reload() {
  try {
    cur.data = await api('policy-list', cur.projectId === null ? {} : { project_id: cur.projectId });
    render();
  } catch (ex) { $('#polRows').replaceChildren(el('li', { class: 'hint', text: ex.message })); }
}

function sourceText(r) {
  if (r.local) return 'כאן';
  if (r.source === 'board') return 'כללי לכל הלוח';
  if (r.source && r.source.name) return `יורש מ: ${r.source.name}`;
  return 'לא נקבע';
}

function render() {
  const d = cur.data;
  // כללים שנקבעו כאן — קודם
  const rows = [...d.types].sort((a, b) => (b.local ? 1 : 0) - (a.local ? 1 : 0));
  $('#polRows').replaceChildren(...rows.map((r) => {
    const seg = el('div', { class: 'seg pol-seg', role: 'radiogroup', 'aria-label': r.label },
      ['ask', 'allow', 'deny'].map((v) => {
        const blockedAllow = v === 'allow' && r.always === 'no';
        const b = el('button', {
          // מלא — נקבע כאן; מתאר — מה שחל בפועל בלי כלל כאן (ירושה, כללי, או ברירת המחדל "שואל")
          type: 'button', role: 'radio', 'aria-checked': String(r.local === v),
          class: !r.local && r.verdict === v ? 'is-inherited' : '',
          disabled: !d.can_edit || blockedAllow,
          title: blockedAllow ? 'לסוג הזה אין "תמיד מותר"' : (!r.local && r.verdict === v ? `${sourceText(r)} — לא נקבע כאן` : ''),
          onclick: () => set(r, v),
        }, [VERDICT[v]]);
        return b;
      }));
    return el('li', { class: 'pol-row' }, [
      el('div', { class: 'pol-head' }, [
        el('div', { class: 'body' }, [
          el('b', { text: r.label }),
          el('small', { class: r.local ? 'is-here' : '', text: `${sourceText(r)}${r.local || !r.source ? '' : ` · ${VERDICT[r.verdict]}`}` }),
        ]),
        r.always === 'warn' ? el('span', { class: 'badge badge--blocked', text: '⚠️ רגיש' }) : null,
      ]),
      seg,
      r.always === 'no' ? el('small', { class: 'pol-why', text: 'פעולה שאין לה "תמיד מותר" — רק פעם אחת, או "אף פעם".' }) : null,
    ]);
  }));
  if (!d.can_edit) {
    $('#polHint').textContent += cur.projectId === null ? ' רק בעל הלוח משנה.' : ' לשינוי צריך הרשאה להחליט בפרויקט.';
  }
  renderHistory(d.history);
}

async function set(r, verdict) {
  if (r.local === verdict || (!r.local && verdict === 'ask')) return;
  let confirmType = '';
  if (verdict === 'allow' && r.always === 'warn') {
    confirmType = prompt(`"תמיד מותר" לפעולה רגישה. כדי לאשר — הקלד את שם הסוג: ${r.type}`) ?? '';
    if (confirmType.trim() !== r.type) { if (confirmType) toast('השם לא תאם — לא נשמר', 'error'); return; }
  }
  try {
    await api('policy-set', { project_id: cur.projectId, type: r.type, verdict, confirm_type: confirmType });
    toast(verdict === 'ask' ? 'הכלל נמחק — חוזר לירושה' : `נשמר: ${VERDICT[verdict]}`, 'ok');
    await reload();
    document.dispatchEvent(new CustomEvent('ct:policy-changed', { detail: cur.projectId }));
  } catch (ex) { toast(ex.message, 'error'); }
}

function dayLabel(ts) {
  const d = new Date(ts * 1000), today = new Date();
  const y = new Date(); y.setDate(today.getDate() - 1);
  if (d.toDateString() === today.toDateString()) return 'היום';
  if (d.toDateString() === y.toDateString()) return 'אתמול';
  return d.toLocaleDateString('he-IL', { day: 'numeric', month: 'long' });
}

function renderHistory(items) {
  if (!items.length) {
    $('#polHistory').replaceChildren(el('p', { class: 'hint', text: 'עוד לא הוחלט כאן כלום.' }));
    return;
  }
  const out = [];
  let day = '';
  for (const h of items) {
    const dl = dayLabel(h.at);
    if (dl !== day) { day = dl; out.push(el('div', { class: 'pol-day', text: dl })); }
    const what = h.kind === 'once'
      ? `${h.decision === 'once' ? 'אושר' : 'נדחה'} פעם אחת`
      : `${VERDICT[h.prev] || h.prev} ← ${VERDICT[h.decision] || h.decision}`;
    const time = new Date(h.at * 1000).toLocaleTimeString('he-IL', { hour: '2-digit', minute: '2-digit' });
    out.push(el('div', { class: 'pol-ev' }, [
      el('span', { class: 'ic', 'aria-hidden': 'true', text: ICON[h.decision] || '•' }),
      el('div', { class: 'body' }, [
        el('b', { text: h.label }),
        el('small', { text: `${what}${h.note ? ` — ${h.note}` : ''} · ${h.by_name || 'מערכת'} · ${time}` }),
        h.task_id ? el('button', { type: 'button', class: 'b-task',
          onclick: () => document.dispatchEvent(new CustomEvent('ct:open-task', { detail: h.task_id })) },
          [el('span', { text: `📋 #${h.task_id}${h.task_title ? ' · ' + h.task_title : ''}` }), el('span', { 'aria-hidden': 'true', text: '‹' })]) : null,
      ]),
    ]));
  }
  $('#polHistory').replaceChildren(...out);
}
