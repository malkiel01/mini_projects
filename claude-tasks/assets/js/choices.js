/*
 * בועת שאלת בחירה (AskUserQuestion) בלוח — docs/spec.md §5.10.
 *
 * לכל שאלה: הכותרת כשבב קטן, השאלה מודגשת, וכפתור ברוחב מלא לכל אפשרות (התווית
 * מודגשת, התיאור באפור מתחתיה). multiSelect — כפתורי מתג עם ✓. "אחר…" פותח שדה.
 * שאלה אחת עם בחירה אחת — נגיעה שולחת מיד; אחרת "שליחה" כשלכל שאלה יש תשובה.
 * אחרי מענה הבועה מתקפלת ל-"✓ נענתה (בלוח/בצ'אט): …".
 */
import { el, api, toast } from './core.js';

const VIA = { board: 'בלוח', chat: 'בצ\'אט', app: 'באפליקציה' };

function answerText(a) {
  if (a && typeof a === 'object' && !Array.isArray(a)) return a.other ?? '';
  return Array.isArray(a) ? a.join(', ') : String(a ?? '');
}

/** הבועה המקופלת: מה נענה, ואיפה. */
function answered(meta) {
  const qs = meta.questions || [];
  const ans = meta.answers || {};
  const rows = Object.entries(ans).map(([q, a]) => {
    const head = qs.find((x) => x.question === q);
    return el('li', {}, [el('b', { text: head ? (head.header || head.question) : 'תשובה' }), ': ', answerText(a)]);
  });
  return el('div', { class: 'choice choice--done' }, [
    el('p', { class: 'choice-done-head', text: `✓ נענתה ${VIA[meta.answered_via] || ''}` }),
    el('details', {}, [
      el('summary', { text: qs.map((q) => q.header || q.question).join(' · ') }),
      el('ul', { class: 'choice-done-list' }, rows),
    ]),
  ]);
}

/**
 * ‏m — הודעת kind choice. ‏canAnswer — מותר לכתוב לצ'אט. ‏onDone — אחרי שליחה (רענון).
 */
export function choiceCard(m, { canAnswer, onDone }) {
  const meta = m.meta || {};
  if (m.status === 'answered') return answered(meta);
  const qs = meta.questions || [];
  const inApp = meta.mode === 'app';
  const picks = new Map();          // שאלה ← תווית | [תוויות] | {other}
  const single = qs.length === 1 && !qs[0].multiSelect;
  const disabled = !canAnswer || inApp;
  const send = el('button', { type: 'button', class: 'btn primary choice-send', disabled: true, text: 'שליחה' });

  const ready = () => qs.every((q) => {
    const a = picks.get(q.question);
    return a !== undefined && (Array.isArray(a) ? a.length > 0 : typeof a === 'object' ? a.other.trim() !== '' : true);
  });
  const submit = async () => {
    if (!ready()) return;
    const answers = Object.fromEntries(picks);
    box.querySelectorAll('button, input, textarea').forEach((b) => { b.disabled = true; });
    try {
      await api('choice-answer', { message_id: m.id, answers });
      toast('התשובה נשלחה לצ\'אט', 'ok');
    } catch (ex) { toast(ex.message, 'error'); }
    onDone?.();
  };

  const blocks = qs.map((q) => {
    const opts = [];
    const otherField = el('textarea', { class: 'choice-other-input', rows: '2', hidden: true,
      'aria-label': `תשובה אחרת: ${q.question}`, placeholder: 'כתוב תשובה משלך' });
    const refresh = () => {
      const a = picks.get(q.question);
      opts.forEach((b) => {
        const on = Array.isArray(a) ? a.includes(b.dataset.label) : a === b.dataset.label;
        b.setAttribute('aria-pressed', on ? 'true' : 'false');
      });
      otherBtn.setAttribute('aria-pressed', a && typeof a === 'object' && !Array.isArray(a) ? 'true' : 'false');
      send.disabled = disabled || !ready();
    };
    q.options.forEach((o) => {
      const b = el('button', {
        type: 'button', class: 'choice-opt', disabled, 'aria-pressed': 'false', dataset: { label: o.label },
        onclick: () => {
          otherField.hidden = true;
          if (q.multiSelect) {
            const cur = Array.isArray(picks.get(q.question)) ? picks.get(q.question) : [];
            picks.set(q.question, cur.includes(o.label) ? cur.filter((x) => x !== o.label) : [...cur, o.label]);
          } else {
            picks.set(q.question, o.label);
          }
          refresh();
          if (single) submit();
        },
      }, [
        q.multiSelect ? el('span', { class: 'choice-check', 'aria-hidden': 'true' }) : null,
        el('span', { class: 'choice-opt-text' }, [
          el('b', { text: o.label }),
          o.description ? el('small', { text: o.description }) : null,
        ]),
      ]);
      opts.push(b);
    });
    const otherBtn = el('button', {
      type: 'button', class: 'choice-opt choice-opt--other', disabled, 'aria-pressed': 'false',
      onclick: () => {
        otherField.hidden = false;
        picks.set(q.question, { other: otherField.value });
        refresh();
        otherField.focus();
      },
    }, [el('span', { class: 'choice-opt-text' }, [el('b', { text: 'אחר…' })])]);
    otherField.addEventListener('input', () => { picks.set(q.question, { other: otherField.value }); refresh(); });
    return el('fieldset', { class: 'choice-q' }, [
      el('legend', { class: 'sr-only', text: q.question }),
      q.header ? el('span', { class: 'chip-sm', text: q.header }) : null,
      el('p', { class: 'choice-question', dir: 'auto' }, [el('b', { text: q.question }),
        q.multiSelect ? el('small', { text: ' (אפשר כמה)' }) : null]),
      el('div', { class: 'choice-opts' }, [...opts, otherBtn]),
      otherField,
    ]);
  });

  // "שליחה" — כשיש יותר משאלה אחת, בחירה מרובה, או "אחר"
  send.addEventListener('click', submit);
  const note = inApp ? 'השאלה פתוחה בחלון באפליקציה — עונים שם.'
    : !canAnswer ? 'אין לך הרשאה לענות לצ\'אט הזה.'
    : 'הצ\'אט עצר ומחכה לתשובה. אפשר לענות כאן, או להקליד בצ\'אט.';
  const box = el('div', { class: 'choice' }, [
    ...blocks,
    el('p', { class: 'hint choice-note', text: note }),
    disabled ? null : el('div', { class: 'choice-foot', hidden: single ? true : null }, [send]),
  ]);
  // שאלה אחת עם בחירה אחת — "אחר" צריך שליחה
  if (single) box.addEventListener('click', (e) => {
    if (e.target.closest('.choice-opt--other')) box.querySelector('.choice-foot')?.removeAttribute('hidden');
  });
  return box;
}
