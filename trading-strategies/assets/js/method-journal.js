// יומן השיטה (spec-stage-a 18.6, S6, ✅27): מה כל שיטה אמרה, מתי, ומה קרה אחר כך. בראש — סיכום לכל (שיטה, גרסה):
// כמה תרחישים נכנסו לאזור, כמה הגיעו לביטול, כמה אף אחד, וכמה עוד לא ידוע. מתחת — הרשומות, העדכניות קודם.
// התוצאה נקבעת בשרת ב-cron (מנרות סגורים) — כאן רק קריאה. עורך רואה הכול; משתמש — רק נכסים שהוא רואה (השרת מסנן).
// כל טקסט — textContent (h()).
import { TF_LABEL } from './card.js';

const CHIP = { zone: 'ok', invalidated: 'bad', neither: '', none: '' };
const FILTERS = [['', 'כל התוצאות'], ['pending', 'עוד לא ידוע'], ['zone', 'נכנס לאזור'], ['invalidated', 'הגיע לביטול'], ['neither', 'אף אחד מהם']];

export function mountMethodJournal(ctx) {
  const { h, api, pairMain } = ctx;
  /** מחרוזת עשרונית מהשרת → עם מפריד אלפים, בלי לעבור דרך float */
  const price = v => { const [i, f] = String(v).split('.'); return i.replace(/\B(?=(\d{3})+(?!\d))/g, ',') + (f ? '.' + f : ''); };
  const tz = () => ctx.me().user.timezone;
  const when = t => { try { return new Date(t * 1000).toLocaleString('he-IL', { timeZone: tz(), day: 'numeric', month: 'numeric', year: '2-digit', hour: '2-digit', minute: '2-digit' }); } catch { return ''; } };
  const day = t => { try { return new Date(t * 1000).toLocaleDateString('he-IL', { timeZone: tz(), day: 'numeric', month: 'numeric' }); } catch { return ''; } };
  const load = () => { try { return JSON.parse(sessionStorage.getItem('ts-mjf') || '{}'); } catch { return {}; } };
  const save = f => { try { sessionStorage.setItem('ts-mjf', JSON.stringify(f)); } catch { /* מצב פרטי */ } };

  function summary(rows) {
    if (!rows.length) return h('p', { class: 'empty' }, 'עוד אין רשומות. רשומה נכתבת כששיטה רצה על נר סגור חדש בגרף.');
    return h('div', { class: 'tbl-wrap' }, h('table', { class: 'tbl mj-sum' },
      h('thead', {}, h('tr', {}, h('th', {}, 'שיטה'), h('th', { class: 'n' }, 'לאזור'),
        h('th', { class: 'n' }, 'ביטול'), h('th', { class: 'n' }, 'אף אחד'), h('th', { class: 'n' }, 'לא ידוע'))),
      h('tbody', {}, rows.map(r => h('tr', { 'data-method': r.method.id, 'data-version': String(r.method.version) },
        h('td', {}, h('div', { class: 'mj-m' }, r.method.name, h('span', { class: 'muted' }, ' · v' + r.method.version)),
          h('small', { class: 'muted' }, h('span', { class: 'num total' }, String(r.total)), ' רשומות')),
        h('td', { class: 'n num zone' }, String(r.zone), r.zone_pct != null ? h('small', { class: 'muted pct' }, `${r.zone_pct}%`) : null),
        h('td', { class: 'n num invalidated' }, String(r.invalidated)),
        h('td', { class: 'n num neither' }, String(r.neither)),
        h('td', { class: 'n num pending' }, String(r.pending)))))));
  }

  function said(r) {
    if (r.kind === 'backtest') {
      const b = r.said.backtest || {};
      return [h('div', { class: 'l2' }, `בדיקה לאחור: ${b.n ?? 0} איתותים, ${b.wins ?? 0} הצליחו`)];
    }
    const s = r.said.scenario;
    const out = [h('div', { class: 'l2 mj-finding' }, r.said.finding || 'אין ממצא')];
    if (s) {
      const parts = [];
      // הטווח משמאל לימין (נמוך–גבוה), עם מפריד אלפים — בלי שהכיוון של העברית יהפוך אותו
      if (s.zone) parts.push(h('span', {}, 'אזור ', h('span', { class: 'num', dir: 'ltr' }, price(s.zone.lo) + '–' + price(s.zone.hi))));
      if (s.invalidation) parts.push(h('span', {}, 'ביטול ', h('span', { class: 'num', dir: 'ltr' }, price(s.invalidation))));
      parts.push(h('span', {}, 'עד ' + day(s.until)));
      out.push(h('div', { class: 'mj-scen' }, parts));
    }
    return out;
  }

  function row(r) {
    const chip = r.kind === 'backtest' ? h('span', { class: 'chip' }, 'בדיקה לאחור')
      : h('span', { class: 'chip mj-out ' + (r.outcome == null ? 'warn' : CHIP[r.outcome]), 'data-outcome': r.outcome ?? 'pending' }, r.outcome_text);
    return h('li', { class: 'card mj-row', style: 'cursor:default', 'data-id': String(r.id), 'data-asset': r.asset },
      h('div', { class: 'body' },
        h('div', { class: 'l1' }, pairMain(r), h('span', { class: 't' }, TF_LABEL[r.tf] || r.tf)),
        h('div', { class: 'l2' }, r.method.name, ' · v' + r.method.version, ' · ', h('span', { class: 'num' }, when(r.as_of)),
          r.scope_kind && r.scope_kind !== 'shared' ? h('span', { class: 'muted' }, r.scope_kind === 'personal' ? ' · 🔑 מפתח אישי' : ' · מפתח מהעורך') : null),
        ...said(r)),
      chip);
  }

  /** היומן לתוך host. מחזיר את ה-host (מתמלא אחרי הבקשה) */
  function view(host) {
    let f = load();
    async function draw() {
      host.replaceChildren(h('p', { class: 'loading' }, 'טוען…'));
      let d;
      try {
        d = await api('method_journal', null, Object.fromEntries(Object.entries(f).filter(([, v]) => v !== '' && v != null)));
      } catch (e) {
        host.replaceChildren(h('p', { class: 'error', role: 'alert' }, e.message));
        return;
      }
      const methods = [...new Map(d.summary.map(s => [s.method.id, s.method.name])).entries()];
      const pick = h('select', { 'aria-label': 'שיטה', on: { change: () => { f = { ...f, method: pick.value }; save(f); draw(); } } },
        h('option', { value: '' }, 'כל השיטות'), methods.map(([id, name]) => h('option', { value: id }, name)));
      pick.value = f.method || '';
      const outs = h('select', { class: 'mj-outcome', 'aria-label': 'תוצאה', on: { change: () => { f = { ...f, outcome: outs.value }; save(f); draw(); } } },
        FILTERS.map(([v, l]) => h('option', { value: v }, l)));
      outs.value = f.outcome || '';
      const list = h('ul', { class: 'list mj-list' }, d.rows.length ? d.rows.map(row) : h('li', { class: 'empty' }, 'אין רשומות בסינון הזה.'));
      let last = d.rows.at(-1)?.id;
      const more = d.more ? h('button', { class: 'btn ghost', type: 'button', on: { click: async () => {
        more.disabled = true;
        try {
          const n = await api('method_journal', null, Object.fromEntries(Object.entries({ ...f, before: last }).filter(([, v]) => v !== '' && v != null)));
          list.append(...n.rows.map(row));
          last = n.rows.at(-1)?.id ?? last;
          if (n.more) more.disabled = false; else more.remove();
        } catch (e) { ctx.toast(e.message, true); more.disabled = false; }
      } } }, 'עוד רשומות') : null;
      host.replaceChildren(
        h('p', { class: 'hint' }, 'רשומה לכל שיטה, נכס, מסגרת ונר סגור. התוצאה נקבעת אחרי שעבר טווח הזמן של התרחיש, מנרות סגורים בלבד — עד אז "עוד לא ידוע". '
          + (d.is_admin ? 'כעורך — כל היומן.' : 'רק נכסים שאתם רואים.')),
        h('h2', { class: 'sec-title' }, 'סיכום לפי גרסה'),
        summary(d.summary),
        h('h2', { class: 'sec-title' }, 'רשומות'),
        h('div', { class: 'toolbar mj-tools' }, pick, outs),
        list,
        ...(more ? [more] : []));
    }
    draw();
    return host;
  }

  async function viewMethodJournal() {
    ctx.setHead('יומן השיטה', 'מה השיטה אמרה ומה קרה', { back: true });
    ctx.render(view(h('div', { class: 'stack method-journal' })));
  }

  return { view, viewMethodJournal };
}
