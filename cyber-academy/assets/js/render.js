/* עזרי תצוגה משותפים. כל טקסט מהנתונים עובר esc — הנתונים מגיעים ממאגר חיצוני. */
'use strict';
window.CA = window.CA || {};
(function () {
  const esc = (v) => String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  const STATUS = {
    not_assessed:          { label: 'לא הוערך',          cls: '' },
    gap_identified:        { label: 'זוהה פער',          cls: 'bad' },
    learning:              { label: 'בלמידה',            cls: 'learn' },
    theory_demonstrated:   { label: 'נלמד · תאוריה',      cls: 'learn' },
    practice_demonstrated: { label: 'הוכח במעשה',        cls: 'ok' },
    reassessment_required: { label: 'נדרשת הערכה מחדש',  cls: 'warn' },
    mastered:              { label: 'שליטה · הוכחה',      cls: 'ok' },
  };
  const STATUS_ORDER = ['mastered', 'practice_demonstrated', 'theory_demonstrated', 'learning', 'reassessment_required', 'gap_identified', 'not_assessed'];
  const TASK = {
    planned:   { label: 'פתוחות',  one: 'פתוחה',  icon: '📋', cls: '' },
    active:    { label: 'פעילות',  one: 'פעילה',  icon: '▶️', cls: 'learn' },
    blocked:   { label: 'חסומות',  one: 'חסומה',  icon: '⛔', cls: 'bad' },
    completed: { label: 'הושלמו',  one: 'הושלמה', icon: '✅', cls: 'ok' },
  };
  const MODE = { computer: { icon: '💻', label: 'מחשב' }, mobile_or_voice: { icon: '📱', label: 'טלפון / קול' }, either: { icon: '🔀', label: 'כל אחד' } };

  const fmtDate = (iso, withTime) => {
    if (!iso) return '';
    const d = new Date(iso);
    if (isNaN(d)) return esc(iso);
    const o = withTime ? { day: 'numeric', month: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' } : { day: 'numeric', month: 'numeric', year: 'numeric' };
    return d.toLocaleString('he-IL', o);
  };

  const chip = (status) => {
    const s = STATUS[status] || { label: status, cls: '' };
    return `<span class="chip ${s.cls}">${esc(s.label)}</span>`;
  };
  const taskChip = (status) => {
    const s = TASK[status];
    return `<span class="chip ${s.cls}">${esc(s.one)}</span>`;
  };

  /** שני המדדים — נלמד / הוכח — זה לצד זה, ספירה ולא אחוז. */
  const measures = (m, mini) => {
    const bar = (n) => `<span class="track" aria-hidden="true"><span style="inline-size:${m.total ? (n / m.total) * 100 : 0}%"></span></span>`;
    return `<div class="measures${mini ? ' mini' : ' big'}">
      <div class="measure learn" title="בלמידה + נלמד בתאוריה">
        <span class="k">📘 נלמד</span>
        <span class="v"><b>${m.learned}</b> מתוך ${m.total}</span>${bar(m.learned)}
      </div>
      <div class="measure proof" title="הוכח במבחן מעשי + שליטה">
        <span class="k">🧪 הוכח במעשה</span>
        <span class="v"><b>${m.proven}</b> מתוך ${m.total}</span>${bar(m.proven)}
      </div>
    </div>`;
  };

  /** התפלגות כל 7 הסטטוסים — פס + מקרא. */
  const dist = (m) => {
    if (!m.total) return '';
    const parts = STATUS_ORDER.filter((k) => m.byStatus[k]);
    return `<div class="dist" role="img" aria-label="${esc(parts.map((k) => STATUS[k].label + ' ' + m.byStatus[k]).join(', '))}">
      ${parts.map((k) => `<span class="s-${k}" style="inline-size:${(m.byStatus[k] / m.total) * 100}%"></span>`).join('')}
    </div>
    <div class="legend">${parts.map((k) => `<span><i class="s-${k}"></i>${esc(STATUS[k].label)} ${m.byStatus[k]}</span>`).join('')}</div>`;
  };

  /** מסך שקיים בדרישה ואין לו נתונים בחוזה — אומרים את זה, לא ממציאים. */
  const noContract = (what, note) => `<div class="nocontract">📭 <b>${esc(what)}</b> — אין עדיין נתונים בחוזה v1.${note ? ' ' + esc(note) : ''}</div>`;

  const emptyLine = (text) => `<p class="empty">${esc(text)}</p>`;

  // השם — העיקר: עובר שורה ולא נחתך. שורת המשנה (sub) נחתכת ב-…; meta — תגיות בשורה משלהן מתחת לשם.
  const row = ({ href, icon, title, sub, end, meta }) => {
    const inner = `<span class="use-ic" aria-hidden="true">${icon}</span>
      <span class="use-tx"><b>${title}</b>${sub ? `<small>${sub}</small>` : ''}${meta ? `<span class="row-meta">${meta}</span>` : ''}</span>
      ${end ? `<span class="row-end">${end}</span>` : ''}${href ? '<span class="use-go" aria-hidden="true">‹</span>' : ''}`;
    return `<li>${href ? `<a class="use-row" href="${esc(href)}">${inner}</a>` : `<div class="use-row">${inner}</div>`}</li>`;
  };

  CA.ui = { esc, STATUS, STATUS_ORDER, TASK, MODE, fmtDate, chip, taskChip, measures, dist, noContract, emptyLine, row };
})();
