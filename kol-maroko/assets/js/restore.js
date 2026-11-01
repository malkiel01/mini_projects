// קול מרוקו — שחזור (lib/restore.php): נקודות שחזור, סל המחזור וגיבוי מלא.
// "כל שינוי ניתן לשחזור, ושום דבר לא נמחק לצמיתות בלי אישור מפורש."
'use strict';

const Restore = (() => {
  const KIND = { manual: ['📌', 'ידנית'], auto: ['🛟', 'אוטומטית'], chat: ['🤖', 'לפני צ\'אט'], daily: ['📅', 'יומית'], pre_restore: ['↩️', 'לפני שחזור'] };
  let tab = sessionStorage.getItem('kol-restore-tab') || 'points';

  async function view(host, role) {
    const tabs = h('div', { class: 'seg-tabs', role: 'tablist' }, [['points', '🛟 נקודות שחזור'], ['trash', '🗑️ סל המחזור'], ...(role === 'owner' ? [['backup', '💾 גיבוי']] : [])]
      .map(([k, l]) => h('button', { type: 'button', role: 'tab', 'aria-selected': String(tab === k), onclick: () => { tab = k; sessionStorage.setItem('kol-restore-tab', k); view(host, role); } }, l)));
    const body = h('div', {}, h('p', { class: 'empty' }, 'טוען…'));
    host.replaceChildren(h('p', { class: 'hint' }, 'כל שינוי ניתן לשחזור. נקודות שחזור נוצרות לבד — לפני צ\'אט, לפני כל ייבוא ופעולה גורפת, ופעם ביום — ולא נמחקות. שחזור יוצר קודם נקודה של המצב הנוכחי, כך שגם אותו אפשר לבטל.'), tabs, body);
    try {
      if (tab === 'points') await points(body, 0);
      else if (tab === 'trash') await trash(body, role);
      else backup(body);
    } catch (e) { body.replaceChildren(h('p', { class: 'error' }, e.message)); }
  }

  // ── נקודות שחזור ────────────────────────────────────────
  async function points(body, offset, keep = []) {
    const d = await api('snapshots', undefined, `&offset=${offset}`);
    const rows = [...keep, ...d.snapshots];
    const note = h('input', { maxlength: 200, placeholder: 'למשל: לפני שינוי גדול' });
    body.replaceChildren(...[                                   // replaceChildren(null) כותב "null"
      h('div', { class: 'toolbar' }, note, h('button', { type: 'button', class: 'btn primary', onclick: async () => {
        try { await api('snapshot_create', { note: note.value }); toast('נשמרה נקודה'); points(body, 0); } catch (e) { toast(e.message, true); }
      } }, '📌 נקודה עכשיו')),
      rows.length ? h('ul', { class: 'list' }, rows.map(s => {
        const [ic, kl] = KIND[s.kind] || ['🛟', s.kind];
        return h('li', {}, h('button', { type: 'button', class: 'use-row', onclick: () => pointSheet(s, body) },
          h('span', { class: 'use-ic', 'aria-hidden': 'true' }, ic),
          h('span', { class: 'use-tx' }, h('b', { class: 'one' }, `#${s.id} · ${s.reason}`),
            h('small', {}, `${when(s.at)} · ${s.name} · ${kl} · ${s.meta.words ?? 0} מילים`)),
          h('span', { class: 'use-go', 'aria-hidden': 'true' }, '‹')));
      })) : h('p', { class: 'empty' }, 'עוד אין נקודות.'),
      rows.length < d.total ? h('button', { type: 'button', class: 'btn ghost w-more', onclick: () => points(body, offset + 50, rows) }, `עוד (${d.total - rows.length})`) : null,
    ].filter(Boolean));
  }

  function pointSheet(s, body) {
    const frame = h('iframe', { class: 'ver-frame', sandbox: 'allow-scripts', title: `נקודה ${s.id}` });
    const status = h('p', { class: 'hint', role: 'status' });
    const dlg = sheet(`🛟 נקודה #${s.id}`,
      h('dl', { class: 'cells' },
        h('div', {}, h('dt', {}, 'מתי'), h('dd', {}, when(s.at))),
        h('div', {}, h('dt', {}, 'למה'), h('dd', {}, s.reason)),
        h('div', {}, h('dt', {}, 'מי'), h('dd', {}, s.name)),
        h('div', {}, h('dt', {}, 'מה בה'), h('dd', {}, `${s.meta.words ?? 0} מילים · ${s.meta.code ? s.meta.code + ' קבצי קוד' : 'תבנית מובנית'} · ${s.meta.files ?? 0} קבצים`))),
      h('p', { class: 'hint' }, 'שחזור מחזיר את הטיוטה, המילים, הנושאים, קבצי הקוד ומצב הקבצים לנקודה הזו. לפני כן נשמרת נקודה של המצב הנוכחי — אפשר לחזור ממנו. האתר לא משתנה עד פרסום.'),
      h('div', { class: 'row-btns' }, h('button', { type: 'button', class: 'btn primary', onclick: async e => {
        if (!confirm(`לשחזר לנקודה #${s.id}?`)) return;
        e.target.disabled = true; status.textContent = 'משחזר…';
        try {
          const r = await api('snapshot_restore', { id: s.id });
          dlg.close();
          toast(`שוחזר · המצב הקודם נשמר כנקודה #${r.pre}`);
          if (r.missing.length) alert(`${r.missing.length} קבצים מהנקודה נמחקו סופית ולא חזרו.`);
          points(body, 0);
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
      ...sec('נושאים', d.topics.map(t => row('🏷️', t.op === 'rename' ? `${t.name} → ${t.to}` : `הוסר: ${t.name}`, `${t.words} מילים · ${t.by} · ${when(t.at)}`, 'topic', t.id, t.name))));
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
