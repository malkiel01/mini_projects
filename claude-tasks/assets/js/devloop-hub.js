import { $, el, toast, api } from './core.js';

/**
 * DevLoop — הלוח המרכזי: מה מחכה לך בכל האפליקציות (🧪 לאימות, ❓ שאלה, 💬 Claude ענה, ⚠️ לבירור),
 * עם קישור ישר למטלה באפליקציה. החיבורים — "+ אפליקציה": כתובת ה-API ואסימון DevLoop שלה. השרת: lib/devloop_hub.php.
 */

const WHY = {
  verify:   ['🧪', 'ממתינה לאימות שלך'],
  question: ['❓', 'שאלה אליך'],
  answered: ['💬', 'Claude ענה'],
  triage:   ['⚠️', 'לבירור'],
};
const ago = (iso) => (iso ? new Date(iso.length > 10 ? iso : iso + 'T12:00:00').toLocaleString('he-IL', { day: 'numeric', month: 'numeric', hour: '2-digit', minute: '2-digit' }) : '');

let board = null;

export function initHub() {
  $('#loopBtn').addEventListener('click', () => { $('#devloop').showModal(); load(); });
  $('#loopReload').addEventListener('click', load);
  badge();
  // רענון המונה בכל חזרה ללשונית — בלי לרוץ ברקע כל הזמן
  document.addEventListener('visibilitychange', () => { if (!document.hidden) badge(); });
}

async function badge() {
  try {
    board = await api('devloop-board');
    const n = board.waiting;
    $('#loopN').textContent = n || '';
    $('#loopN').hidden = !n;
  } catch { /* בלי מקורות או בלי רשת — בלי מונה */ }
}

async function load() {
  const body = $('#loopBody');
  body.replaceChildren(el('p', { class: 'hint', text: 'טוען מכל האפליקציות…' }));
  try { board = await api('devloop-board'); } catch (e) { body.replaceChildren(el('p', { class: 'hub-err', text: e.message })); return; }
  $('#loopN').textContent = board.waiting || '';
  $('#loopN').hidden = !board.waiting;
  draw();
}

function draw() {
  const body = $('#loopBody');
  const parts = [];
  if (!board.apps.length) {
    parts.push(el('p', { class: 'hint', text: 'עוד לא חוברה אף אפליקציה. באפליקציה: 📋 מטלות ← 🔑 גישה ל-Claude ← אסימון חדש; ואז "+ אפליקציה" כאן, עם כתובת ה-API והאסימון.' }));
  } else {
    parts.push(el('div', { class: 'hub-apps' }, board.apps.map((a) => {
      const c = a.counts || {};
      const chips = a.ok ? [
        ['badge badge--blocked', `🧪 ${c.verify} לאימות`, c.verify], ['badge badge--answered', `❓ ${c.question} שאלות`, c.question],
        ['badge badge--topic', `💬 ${c.answered} תשובות`, c.answered], ['badge badge--progress', `⚠️ ${c.triage} לבירור`, c.triage],
        ['badge', `🔵 ${c.claude} אצל Claude`, true], ['badge badge--done', `✅ ${c.closed}`, true],
      ].filter((x) => x[2]).map(([cls, t]) => el('span', { class: cls, text: t })) : [];
      return el('div', { class: 'hub-app' }, [
        el('div', { class: 'hub-app__head' }, [el('b', { text: a.name }), el('a', { href: a.url, target: '_blank', rel: 'noopener', text: 'למטלות ‹' })]),
        a.ok ? el('div', { class: 'card__meta' }, chips) : el('p', { class: 'hub-err', text: '✗ ' + a.error }),
      ]);
    })));
    parts.push(el('h3', { class: 'hub-h', text: board.items.length ? `מחכה לך (${board.waiting})` : 'שום דבר לא מחכה לך 🎉' }));
    if (board.items.length) {
      parts.push(el('ul', { class: 'hub-items' }, board.items.map((x) => {
        const [ic, label] = WHY[x.why];
        const sub = [x.app, label, x.count ? `×${x.count}` : '', ago(x.at)].filter(Boolean).join(' · ');
        return el('li', { class: 'hub-item', dataset: { why: x.why } }, [
          el('a', { href: x.url, target: '_blank', rel: 'noopener' }, [
            el('b', {}, [el('span', { text: ic + ' ' }), el('code', { dir: 'ltr', text: x.code }), el('span', { text: ' ' + x.title })]),
            el('small', { text: sub }),
            x.last ? el('small', { text: '„' + x.last + '“' }) : null,
          ].filter(Boolean)),
        ]);
      })));
    }
  }
  parts.push(el('h3', { class: 'hub-h', text: 'אפליקציות מחוברות' }));
  parts.push(el('div', {}, board.apps.map((a) => el('div', { class: 'hub-src' }, [
    el('span', { text: a.name }),
    el('button', { type: 'button', class: 'btn btn--ghost', text: 'ניתוק', onclick: () => remove(a) }),
  ]))));
  parts.push(addForm());
  body.replaceChildren(...parts);
}

function addForm() {
  const f = el('form', { class: 'stack' }, [
    el('label', { class: 'field' }, [el('span', { text: 'שם' }), el('input', { name: 'name', maxlength: 60, placeholder: 'מנהל הפרויקטים' })]),
    el('label', { class: 'field' }, [el('span', { text: 'כתובת ה-API' }), el('input', { name: 'url', dir: 'ltr', maxlength: 300, placeholder: 'https://…/project-manager/api.php' })]),
    el('label', { class: 'field' }, [el('span', { text: 'אסימון DevLoop' }), el('input', { name: 'token', type: 'password', dir: 'ltr', autocomplete: 'off', placeholder: 'pmd_…' })]),
    el('p', { class: 'hint', text: 'האסימון נבדק מול האפליקציה, נשמר מוצפן, ופותח רק את המטלות שלה — קריאה בלבד מכאן.' }),
    el('button', { type: 'submit', class: 'btn btn--primary', text: '+ אפליקציה' }),
  ]);
  f.addEventListener('submit', async (e) => {
    e.preventDefault();
    const b = f.querySelector('[type=submit]');
    b.disabled = true;
    try {
      await api('devloop-source-add', { name: f.elements.name.value, url: f.elements.url.value, token: f.elements.token.value });
      toast('חובר ✓');
      await load();
    } catch (x) { toast(x.message, 'error'); b.disabled = false; }
  });
  return f;
}

async function remove(a) {
  if (!confirm(`לנתק את "${a.name}"? האסימון נמחק מכאן (באפליקציה הוא נשאר — אפשר לבטל אותו שם).`)) return;
  try { await api('devloop-source-remove', { id: a.id }); await load(); } catch (x) { toast(x.message, 'error'); }
}
