// קול מרוקו — שחזור (lib/restore.php): נקודות שחזור, סל המחזור וגיבוי מלא.
// "כל שינוי ניתן לשחזור, ושום דבר לא נמחק לצמיתות בלי אישור מפורש."
'use strict';

const Restore = (() => {
  const KIND = { manual: ['📌', 'ידנית'], auto: ['🛟', 'אוטומטית'], chat: ['🤖', 'לפני צ\'אט'], daily: ['📅', 'יומית'], pre_restore: ['↩️', 'לפני שחזור'], origin: ['📌', 'מקור'] };
  // R5: נקודה מלפני קובצי הפרויקט (F2a) — שחזור אליה לא משנה את העמוד, ולכן זה כתוב ליד הנקודה ובאישור
  const NO_PROJECT = '⚠️ בלי קובצי העמוד — שחזור לא ישנה את העמוד';
  const has = s => (s.project ? 'כולל את קובצי העמוד' : NO_PROJECT);
  let tab = sessionStorage.getItem('kol-restore-tab') || 'points';

  async function view(host, role, site = false) {
    const tabs = h('div', { class: 'seg-tabs', role: 'tablist' }, [['points', '🛟 נקודות שחזור'], ['trash', '🗑️ סל המחזור'], ...(role === 'owner' ? [['backup', '💾 גיבוי']] : [])]
      .map(([k, l]) => h('button', { type: 'button', role: 'tab', 'aria-selected': String(tab === k), onclick: () => { tab = k; sessionStorage.setItem('kol-restore-tab', k); view(host, role, site); } }, l)));
    const body = h('div', {}, h('p', { class: 'empty' }, 'טוען…'));
    host.replaceChildren(h('p', { class: 'hint' }, 'כל שינוי ניתן לשחזור. נקודות שחזור נוצרות לבד — לפני צ\'אט, לפני כל ייבוא ופעולה גורפת, ופעם ביום — ולא נמחקות. שחזור יוצר קודם נקודה של המצב הנוכחי, כך שגם אותו אפשר לבטל.'), tabs, body);
    try {
      if (tab === 'points') await points(body, 0, [], site);
      else if (tab === 'trash') await trash(body, role);
      else backup(body);
    } catch (e) { body.replaceChildren(h('p', { class: 'error' }, e.message)); }
  }

  // ── נקודות שחזור ────────────────────────────────────────
  async function points(body, offset, keep = [], site = false) {
    const d = await api('snapshots', undefined, `&offset=${offset}`);
    const rows = [...keep, ...d.snapshots];
    const note = h('input', { maxlength: 200, placeholder: 'למשל: לפני שינוי גדול' });
    body.replaceChildren(...[                                   // replaceChildren(null) כותב "null"
      h('div', { class: 'toolbar' }, note, h('button', { type: 'button', class: 'btn primary', onclick: async () => {
        try { await api('snapshot_create', { note: note.value }); toast('נשמרה נקודה'); points(body, 0, [], site); } catch (e) { toast(e.message, true); }
      } }, '📌 נקודה עכשיו')),
      site ? h('div', { class: 'row-btns snap-reset-row' }, h('button', { type: 'button', class: 'btn ghost danger', 'data-snap-reset': true, onclick: () => resetSheet(d.total, body) }, '🧹 איפוס נקודות השחזור')) : null,
      rows.length ? h('ul', { class: 'list' }, rows.map(s => {
        const [ic, kl] = KIND[s.kind] || ['🛟', s.kind];
        return h('li', { 'data-snap': s.id, class: s.kind === 'origin' ? 'snap-origin' : null }, h('button', { type: 'button', class: 'use-row', onclick: () => pointSheet(s, body, site) },
          h('span', { class: 'use-ic', 'aria-hidden': 'true' }, ic),
          h('span', { class: 'use-tx' }, h('b', { class: 'one' }, s.kind === 'origin' ? `📌 מקור · #${s.id}` : `#${s.id} · ${s.reason}`),
            h('small', {}, `${when(s.at)} · ${s.name} · ${kl} · ${s.meta.words ?? 0} מילים`),
            h('small', { class: s.project ? 'snap-has' : 'snap-has warn', 'data-snap-project': String(!!s.project) }, has(s))),
          h('span', { class: 'use-go', 'aria-hidden': 'true' }, '‹')));
      })) : h('p', { class: 'empty' }, 'עוד אין נקודות.'),
      rows.length < d.total ? h('button', { type: 'button', class: 'btn ghost w-more', onclick: () => points(body, offset + 50, rows, site) }, `עוד (${d.total - rows.length})`) : null,
    ].filter(Boolean));
  }

  /**
   * R5/R6: איפוס — רק בעל האתר (נאכף גם בשרת). R6 (הבעלים, 15.11: "לחצתי ולא קרה כלום"):
   * הכפתור תמיד לחיץ, ולחיצה בלי אישור אומרת בשורה אדומה מה חסר; ההקלדה מנורמלת (תווי כיוון, ניקוד, רווחים);
   * Enter בשדה = לחיצה (ולא שליחת הטופס של החלון, שסוגרת אותו); כל תוצאה — בתוך החלון, וכל לחיצה ותוצאה — ליומן.
   */
  const WORD = 'איפוס';
  const norm = v => String(v || '').normalize('NFC')
    .replace(/[֑-ׇ​-‏‪-‮⁠-⁩﻿­\s]/g, '');   // טעמים וניקוד, כיוון, רוחב-אפס, רווחים מכל סוג
  const log = (step, msg, status) => { try { window.KolUi && window.KolUi('snapshots_reset.' + step, msg, status); } catch { /* היומן לא עוצר את המסך */ } };
  const WHY = { 0: 'אין חיבור לשרת', 403: 'אין הרשאה — רק בעל האתר', 423: 'הטיוטה נעולה' };

  function resetSheet(total, body) {
    const typed = h('input', { 'data-snap-reset-type': true, autocomplete: 'off', autocapitalize: 'off', spellcheck: 'false', enterkeyhint: 'done', 'aria-label': `לאישור — כתבו: ${WORD}` });
    const status = h('p', { class: 'hint', role: 'status', 'aria-live': 'polite', 'data-snap-reset-status': '' });
    const say = (cls, text, state) => { status.dataset.snapResetStatus = state; status.replaceChildren(h('span', { class: cls }, text)); };
    let busy = false, done = false;
    const run = async () => {
      if (busy) return;
      if (done) { dlg.close(); return; }
      const v = norm(typed.value);
      if (v !== WORD) {
        const msg = v ? `כתבתם "${typed.value.trim()}". כדי לאפס — כתבו בדיוק: ${WORD}` : `כדי לאפס — כתבו בשדה את המילה: ${WORD}`;
        say('error', '⚠️ ' + msg, 'need');
        log('blocked', v ? `הוקלד: ${JSON.stringify(typed.value).slice(0, 80)}` : 'בלי הקלדה');
        typed.focus();
        return;
      }
      busy = true; go.disabled = true; say('hint', '⏳ מאפס…', 'busy');
      log('click', `אישור · ${total} נקודות`);
      try {
        const r = await api('snapshots_reset', { confirm: true });
        done = true;
        const msg = `נמחקו ${r.deleted} נקודות · נקודת מקור #${r.origin}`;
        say('ok', '✅ ' + msg, 'ok');
        log('ok', msg);
        go.textContent = '✓ סגירה'; go.classList.remove('danger'); go.classList.add('primary');
        typed.disabled = true;
        toast(msg);
        points(body, 0, [], true).catch(e => say('error', `האיפוס בוצע, אבל הרשימה לא נטענה: ${e.message}`, 'ok'));
      } catch (err) {
        const st = err.status ?? 0;
        const why = WHY[st] ? `${WHY[st]} (${st || 'רשת'})` : `שגיאה ${st || ''}`.trim();
        say('error', `❌ האיפוס לא בוצע — ${why}: ${err.message}`, 'error');
        log('error', err.message, st);
      } finally { busy = false; go.disabled = false; }
    };
    const go = h('button', { type: 'button', class: 'btn danger snap-reset-go', 'data-snap-reset-go': true, onclick: run }, '🧹 איפוס');
    // Enter (גם "סיום" במקלדת של הטלפון): בלי preventDefault — הטופס של החלון (method="dialog") נשלח, והחלון נסגר בלי כלום
    typed.addEventListener('keydown', e => { if (e.key === 'Enter' && !e.isComposing) { e.preventDefault(); run(); } });
    typed.addEventListener('input', () => { if (status.dataset.snapResetStatus === 'need' && norm(typed.value) === WORD) say('hint', 'אפשר ללחוץ "🧹 איפוס".', 'ready'); });
    log('open', `${total} נקודות`);
    const dlg = sheet('🧹 איפוס נקודות השחזור',
      h('p', { class: 'error', 'data-snap-reset-text': true }, `כל ${total} הנקודות יימחקו. תיווצר נקודה אחת "מקור" של המצב הנוכחי. אי אפשר לבטל.`),
      h('p', { class: 'hint' }, 'נקודת המקור מוצגת בראש הרשימה, לא נמחקת בשום ניקוי, ומוחלפת רק באיפוס הבא. העמוד, המילים והקבצים עצמם לא משתנים.'),
      h('p', {}, h('a', { class: 'btn ghost', href: 'api.php?action=backup', download: '' }, '💾 הורד גיבוי קודם')),
      h('label', { class: 'field' }, h('span', {}, `לאישור — כתבו: ${WORD}`), typed),
      h('div', { class: 'row-btns' }, go), status);
  }

  function pointSheet(s, body, site) {
    const frame = h('iframe', { class: 'ver-frame', sandbox: 'allow-scripts', title: `נקודה ${s.id}` });
    const status = h('p', { class: 'hint', role: 'status' });
    const dlg = sheet(`🛟 נקודה #${s.id}`,
      h('dl', { class: 'cells' },
        h('div', {}, h('dt', {}, 'מתי'), h('dd', {}, when(s.at))),
        h('div', {}, h('dt', {}, 'למה'), h('dd', {}, s.reason)),
        h('div', {}, h('dt', {}, 'מי'), h('dd', {}, s.name)),
        h('div', {}, h('dt', {}, 'מה בה'), h('dd', {}, `${s.meta.words ?? 0} מילים · ${s.meta.code ? s.meta.code + ' קבצי קוד' : 'תבנית מובנית'} · ${s.meta.files ?? 0} קבצים`)),
        h('div', {}, h('dt', {}, 'קובצי העמוד'), h('dd', { class: s.project ? null : 'error', 'data-snap-sheet-project': String(!!s.project) }, has(s)))),
      h('p', { class: 'hint' }, 'שחזור מחזיר את הטיוטה, המילים, הנושאים, קבצי הקוד ומצב הקבצים לנקודה הזו. לפני כן נשמרת נקודה של המצב הנוכחי — אפשר לחזור ממנו. האתר לא משתנה עד פרסום.'),
      h('div', { class: 'row-btns' }, h('button', { type: 'button', class: 'btn primary', onclick: async e => {
        if (!confirm(`לשחזר לנקודה #${s.id}?${s.project ? '' : `\n\n${NO_PROJECT}: הנקודה נוצרה לפני שקובצי העמוד נשמרו בנקודות. יחזרו רק המילים, הנושאים, קבצי הקוד ומצב הקבצים.`}`)) return;
        e.target.disabled = true; status.textContent = 'משחזר…';
        try {
          const r = await api('snapshot_restore', { id: s.id });
          dlg.close();
          toast(`שוחזר · המצב הקודם נשמר כנקודה #${r.pre}`);
          if (r.missing.length) alert(`${r.missing.length} קבצים מהנקודה נמחקו סופית ולא חזרו.`);
          points(body, 0, [], site);
        } catch (err) { status.replaceChildren(h('span', { class: 'error' }, err.message)); e.target.disabled = false; }
      } }, '↩️ שחזור לנקודה הזו')),
      status, frame);
    api('snapshot_preview', undefined, `&id=${s.id}`).then(r => {
      frame.srcdoc = `<!doctype html><html lang="he" dir="rtl"><head><meta charset="utf-8">${r.fonts ? `<link rel="stylesheet" href="${r.fonts}">` : ''}<style>body{margin:0;font-family:system-ui,Arial,sans-serif}${r.css.replace(/<\/style/gi, '')}</style></head><body>${r.html}</body></html>`;
    }).catch(e => frame.replaceWith(h('p', { class: 'error' }, e.message)));
  }

  // ── סל המחזור ──────────────────────────────────────────
  async function trash(body, role) {
    const d = await api('trash');
    const owner = role === 'owner';
    const act = async (path, b, ok) => {
      try { await api(path, b); toast(ok); trash(body, role); } catch (e) { toast(e.message, true); }
    };
    const purge = (kind, id, label) => {
      if (!confirm(`למחוק סופית את "${label}"? אי אפשר לבטל את זה.`)) return;
      if (prompt('לאישור — כתבו: מחיקה סופית') !== 'מחיקה סופית') return;
      act('trash_purge', { kind, id, confirm: true }, 'נמחק סופית');
    };
    const row = (ic, title, sub, kind, id, label) => h('li', { class: 'w-topic' },
      h('span', { class: 'use-ic', 'aria-hidden': 'true' }, ic),
      h('span', { class: 'use-tx' }, h('b', { class: 'one' }, title), h('small', {}, sub)),
      h('button', { type: 'button', class: 'btn sm primary', onclick: () => act('trash_restore', { kind, id }, 'שוחזר') }, '↩️ שחזור'),
      owner ? h('button', { type: 'button', class: 'btn sm ghost danger', title: 'מחיקה סופית', 'aria-label': 'מחיקה סופית', onclick: () => purge(kind, id, label) }, '✕') : null);
    const sec = (title, items) => [h('h3', { class: 'sec-title' }, `${title} · ${items.length}`),
      items.length ? h('ul', { class: 'list' }, items) : h('p', { class: 'hint' }, 'ריק.')];
    body.replaceChildren(
      h('p', { class: 'hint' }, owner ? 'מחיקה סופית — רק מכאן, ורק בעלים. קובץ שגרסה שמורה מפנה אליו לא נמחק סופית.' : 'מחיקה סופית — רק לבעלים.'),
      ...sec('קבצים', d.files.map(f => row('📄', f.name, `${f.deleted_by || ''} · ${when(f.deleted_at)}`, 'file', f.id, f.name))),
      ...sec('מילים', d.words.map(w => row('📚', w.word, `${w.by} · ${when(w.at)}`, 'word', w.id, w.word))),
      ...sec('נושאים', d.topics.map(t => row('🏷️', t.op === 'rename' ? `${t.name} → ${t.to}` : t.op === 'merge' ? `מוזג: ${t.name} → ${t.to}` : `הוסר: ${t.name}`, `${t.words} מילים · ${t.by} · ${when(t.at)}`, 'topic', t.id, t.name))),
      ...sec('שדות', (d.fields || []).map(f => row('🧩', `${f.label} (${f.key})`, `${f.values} ערכים · ${f.by} · ${when(f.at)}`, 'field', f.id, f.label))),
      // N3: רשימות — רשימה שלמה, שדה ברשימה, פריט (lib/lists.php, list_trash)
      ...sec('רשימות', (d.lists || []).map(x => row(x.kind === 'list' ? '📚' : x.kind === 'field' ? '🧩' : '▫️', x.name, `${x.list} · ${x.by} · ${when(x.at)}`, 'list', x.id, x.name))),
      // M5: פניות — אצווה (פנייה שנמחקה, או "מחק ישנות" כולה) משוחזרת יחד
      ...sec('פניות', (d.forms || []).map(f => row('📬', f.old ? `"מחק ישנות" · ${f.n} פניות` : `פנייה בטופס ${f.form}${f.n > 1 ? ` · ${f.n}` : ''}`, `${f.by} · ${when(f.at)}`, 'form', f.id, `${f.n} פניות`))));
  }

  // ── גיבוי ──────────────────────────────────────────────
  function backup(body) {
    body.replaceChildren(h('section', { class: 'summary' },
      h('p', {}, 'קובץ ZIP אחד עם הכול: כל הגרסאות, נקודות השחזור, המילים, הקוד, ההיסטוריה וכל הקבצים וההקלטות — למקרה שהכלי עצמו ייפול. בתוכו הוראות שחזור.'),
      h('p', { class: 'hint' }, 'עם הקלטות — הקובץ גדול וההכנה לוקחת כמה דקות.'),
      h('a', { class: 'btn primary', href: 'api.php?action=backup', download: '' }, '💾 הורדת גיבוי מלא')));
  }

  return { view };
})();
