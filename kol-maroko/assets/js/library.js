// קול מרוקו — הספרייה (פרוסה A1): כל ההקלטות של המילון — גם אלה שעדיין רק כתובת באתר — כשורות שאפשר להשמיע.
// המפתח של הקלטה הוא מזהה המילה. ההשמעה היא של הדפדפן, ישירות מהכתובת; שום דבר לא יורד ולא נשמר.
// "קבצים" ו"תמונות" — המסך הקיים (media.js), בתוך לשונית.
// A2: המצב נקרא ממודל ההקלטות; לבעלים — "הבאה מהאתר" (library-fetch.js); המשך נשמר בשרת כשהוא נמדד.
// A3: הפעולות על הקלטה (library-actions.js): תפריט שורה, בחירה מרובה, הוספה עם התאמה, סל, גרסאות, הורדה, כפולות.
//     הקלטה "לא מחוברת" (D.loose) — שורה עם מזהה שלילי (-מזהה הרשומה), כדי לא להתנגש במזהה מילה.
// N2b: שורה לכל מקום — מילה × שדה שמע (D.fields). השדה הראשי — המזהה של השורה הוא מזהה המילה (כמו לפני); שדה מותאם —
//     "<מזהה>:<מפתח>". r.wid — המילה, r.field — השדה. עמודה + סינון "שדה" — רק כשיש יותר משדה שמע אחד. גרירה: קובץ על שורה ←
//     החלפה / חיבור למקום; שורה על שורה "חסרה" ← חיבור / העברה (library-actions.js). במחשב — על כל הרוחב (body.lib-wide).
'use strict';

const Library = (() => {
  const PAGE = 60;                         // שורות בכל פעם
  const TIMEOUT = 15000;                   // כמה מחכים להקלטה לפני "תם הזמן"
  const STATE = { site: ['באתר', ''], tool: ['בכלי', 'ok'], none: ['בלי הקלטה', 'warn'] };
  const DURS = { '': 'כל המשכים', short: 'עד 2 שניות', mid: '2–10 שניות', long: 'מעל 10 שניות', unknown: 'משך לא נמדד' };
  const SIZES = { '': 'כל הגדלים', s: 'עד 50KB', m: '50KB–1MB', l: 'מעל 1MB' };
  const SORTS = { id: 'לפי מזהה', ab: 'לפי א"ב', state: 'לפי מצב' };
  const ORDER = { tool: 0, site: 1, none: 2 };
  let D = null, host = null, isOwner = false, pageOwner = false;   // isOwner — בעל האתר; pageOwner — בעל העמוד ומעלה (T1)
  let tab = '', q = '', st = '', topic = '', sort = 'id', shown = PAGE, durF = '', sizeF = '', fieldF = '';
  const chosen = new Set();                   // A3: הבחירה המרובה (מפתחות שורה)
  let selMode = false;                     // בטלפון — אחרי לחיצה ארוכה
  let pending = 0;                         // מילה להציג כשהמסך נטען ("הצג בספרייה")
  let listEl = null, moreEl = null, items = new Map();
  const failed = new Map();                // id → למה לא נטען
  const dur = new Map();                   // id → משך בשניות (נודע רק אחרי שהושמע)

  const norm = s => String(s).toLowerCase().replace(/[֑-ׇ]/g, '');
  const num = n => n.toLocaleString('he-IL');
  const clock = s => isFinite(s) ? `${Math.floor(s / 60)}:${String(Math.floor(s % 60)).padStart(2, '0')}` : '';
  const date = iso => new Date(iso).toLocaleDateString('he-IL', { day: 'numeric', month: 'short', year: '2-digit', timeZone: 'Asia/Jerusalem' });
  const byId = id => D.all.find(r => r.id === id);
  /** N2b: המילה של השורה (null — לא מחוברת), השדה, ושדות השמע. */
  const widOf = r => r.loose ? null : (r.wid ?? r.id);
  const multi = () => (D?.fields?.length || 0) > 1;
  const fieldOf = k => D.fields?.find(f => f.key === k);
  const primaryKey = () => D.fields?.find(f => f.primary)?.key || 'audio';
  const fIdx = r => Math.max(0, D.fields?.findIndex(f => f.key === r.field) ?? 0);
  /** השורה של מקום (מילה + שדה). */
  const slotRow = (wid, field) => D.all.find(r => !r.loose && widOf(r) === wid && (r.field ?? primaryKey()) === (field ?? primaryKey()));
  /** הנתונים מהשרת: שורות המילים ואחריהן ההקלטות הלא מחוברות. */
  const setD = d => { d.all = [...d.rows, ...(d.loose || [])]; D = d; for (const k of [...chosen]) if (!byId(k)) chosen.delete(k); return d; };
  const durOf = r => dur.get(r.id) ?? r.file?.duration ?? null;
  const dupCount = () => { const m = new Map(); for (const r of D.all) if (r.file?.sha && !r.missing) m.set(r.file.sha, (m.get(r.file.sha) || 0) + 1); return m; };

  /** כתובת באתר מותרת להשמעה / להצגה רק אם היא https והמארח הוא מארח האתר. קובץ שלנו — כתובת יחסית של api.php. */
  function safeSrc(src) {
    if (!src) return '';
    if (src.startsWith('api.php?action=media&')) return src;
    try {
      const u = new URL(src);
      return u.protocol === 'https:' && u.hostname.toLowerCase().replace(/^www\./, '') === D.host ? u.href : '';
    } catch { return ''; }
  }

  /**
   * רישום שגיאת השמעה ביומן המפתח. הנקודה היחידה לחיבור ליומן של F1:
   * כש-window.KolLog קיים (פונקציה) — הרשומה עוברת אליו; עד אז — למסוף, ונשמרות 50 האחרונות ב-Library.errors.
   */
  const errors = [];
  function logPlaybackError(entry) {
    const rec = { type: 'audio_playback', at: new Date().toISOString(), ...entry };
    errors.push(rec); if (errors.length > 50) errors.shift();
    try {
      if (typeof window.KolLog === 'function') window.KolLog(rec);
      else console.warn('kol-maroko: ההקלטה לא נטענה', rec);
    } catch { /* יומן שנכשל לא עוצר את ההשמעה */ }
  }

  // ── הנגן: אלמנט אחד, נטען רק בלחיצה ───────────────────────
  const P = { audio: null, id: 0, bar: null, timer: 0 };
  function player() {
    if (P.audio) return P;
    const a = P.audio = new Audio();
    a.preload = 'none';
    const title = h('b', { class: 'one' }), sub = h('small', { class: 'one' });
    const seek = h('input', { type: 'range', min: 0, max: 1000, value: 0, 'aria-label': 'התקדמות', class: 'lib-seek',
      oninput: () => { if (isFinite(a.duration)) a.currentTime = seek.value / 1000 * a.duration; } });
    const toggle = h('button', { type: 'button', class: 'icon-btn lib-toggle', 'aria-label': 'נגן / עצור', onclick: () => togglePlay() }, '▶');
    P.bar = h('div', { class: 'lib-player', role: 'region', 'aria-label': 'נגן', hidden: true },
      h('div', { class: 'lib-p-row' },
        h('button', { type: 'button', class: 'icon-btn', 'aria-label': 'ההקלטה הקודמת', onclick: () => step(-1) }, '⏮'),
        toggle,
        h('button', { type: 'button', class: 'icon-btn', 'aria-label': 'ההקלטה הבאה', onclick: () => step(1) }, '⏭'),
        h('div', { class: 'use-tx' }, title, sub),
        h('button', { type: 'button', class: 'icon-btn', 'aria-label': 'סגירת הנגן', onclick: stop }, '✕')),
      seek);
    document.body.append(P.bar);
    Object.assign(P, { title, sub, seek, toggle });
    const sync = () => {
      toggle.textContent = a.paused ? '▶' : '⏸';
      mark();
    };
    a.addEventListener('play', sync); a.addEventListener('pause', sync); a.addEventListener('ended', sync);
    a.addEventListener('playing', () => { clearTimeout(P.timer); if (failed.delete(P.id)) redraw(P.id); sub.textContent = time(); });
    a.addEventListener('loadedmetadata', () => {
      if (!isFinite(a.duration)) return;
      dur.set(P.id, a.duration); redraw(P.id);
      const r = byId(P.id);                  // A2: המשך נשמר — רק לקובץ שבכלי, ורק פעם אחת
      if (r?.file && r.file.duration == null && r.state === 'tool') {
        r.file.duration = a.duration;
        api('rec_duration', r.loose ? { rec_id: r.rec, file_id: r.file.id, duration: a.duration } : { word_id: widOf(r), field: r.field, file_id: r.file.id, duration: a.duration }).catch(() => {});
      }
    });
    a.addEventListener('timeupdate', () => {
      if (isFinite(a.duration) && a.duration) seek.value = Math.round(a.currentTime / a.duration * 1000);
      if (!failed.has(P.id)) sub.textContent = time();
    });
    a.addEventListener('error', () => {
      if (!P.id || !a.getAttribute('src')) return;
      const c = a.error?.code;
      fail(c === 2 ? 'תקלת רשת בזמן הטעינה' : c === 3 ? 'הקובץ פגום ואי אפשר לנגן אותו'
        : 'הכתובת לא החזירה קובץ שמע (לא נמצא, נחסם, או סוג שהדפדפן לא מנגן)', c || 0);
    });
    return P;
  }
  const time = () => `${clock(P.audio.currentTime)}${isFinite(P.audio.duration) ? ' / ' + clock(P.audio.duration) : ''}`;

  function fail(reason, code) {
    clearTimeout(P.timer);
    const r = byId(P.id);
    failed.set(P.id, reason);
    logPlaybackError({ word_id: r ? widOf(r) : P.id, field: r?.field || '', word: r?.word || '', url: r?.src || '', state: r?.state || '', reason, code });
    P.audio.pause();
    P.sub.textContent = 'לא נטען — ' + reason;
    P.toggle.textContent = '▶';
    redraw(P.id);
  }

  function play(id) {
    const r = byId(id);
    if (!r || r.state === 'none') return;
    const p = player(), a = p.audio;
    if (p.id === id && !failed.has(id) && a.getAttribute('src')) { togglePlay(); return; }
    a.pause(); clearTimeout(p.timer);
    const prev = p.id;
    p.id = id;
    p.bar.hidden = false; document.body.classList.add('lib-playing');
    p.title.textContent = (r.loose ? r.word : `#${widOf(r)} · ${r.word}`) + (multi() && r.field ? ` · ${fieldOf(r.field)?.short || r.field_label}` : '');
    p.sub.textContent = 'טוען…';
    p.seek.value = 0;
    failed.delete(id);
    if (prev) redraw(prev);
    const src = r.missing ? '' : safeSrc(r.src);
    if (!src) {
      a.removeAttribute('src');
      fail(r.missing ? 'הקובץ חסר בכלי' : 'הכתובת אינה כתובת https באתר', -1);
      return;
    }
    a.src = src;
    p.timer = setTimeout(() => { if (p.id === id && a.readyState < 3) { a.removeAttribute('src'); a.load(); fail('תם הזמן — האתר לא ענה תוך 15 שניות', -2); } }, TIMEOUT);
    a.play().catch(() => {});                 // כישלון טעינה מגיע באירוע error; חסימת ניגון אוטומטי — נשארים על ▶
    mark();
  }
  function togglePlay() {
    const a = P.audio;
    if (!a || !P.id) return;
    if (failed.has(P.id) || !a.getAttribute('src')) { const id = P.id; P.id = 0; play(id); return; }
    a.paused ? a.play().catch(() => {}) : a.pause();
  }
  /** הקודם / הבא — לפי הרשימה המסוננת, כולל שורות שעוד לא נגללו. הקלטה שנכשלה אינה עוצרת. */
  function step(d) {
    const list = filtered().filter(r => r.state !== 'none');
    if (!list.length) return;
    const i = list.findIndex(r => r.id === P.id);
    const next = list[i < 0 ? 0 : (i + d + list.length) % list.length];
    reveal(next.id);
    play(next.id);
  }
  function stop() {
    if (!P.audio) return;
    clearTimeout(P.timer);
    P.audio.pause(); P.audio.removeAttribute('src'); P.audio.load();
    const id = P.id; P.id = 0;
    P.bar.hidden = true; document.body.classList.remove('lib-playing');
    if (id && D) redraw(id);
  }
  /** סימון השורה שמתנגנת. */
  function mark() {
    for (const [id, li] of items) {
      const on = id === P.id;
      li.classList.toggle('on', on);
      const b = li.querySelector('.lib-play');
      if (b) b.textContent = on && P.audio && !P.audio.paused ? '⏸' : '▶';
    }
  }

  // מקלדת: רווח — נגן / עצור; חיצים — הקודם / הבא. רק כשהנגן פתוח, במסך הזה, ולא בתוך שדה או חלון.
  document.addEventListener('keydown', e => {
    if (!P.id || location.hash !== '#/files' || e.ctrlKey || e.metaKey || e.altKey || document.querySelector('dialog[open]')) return;
    const t = e.target;
    if (t.closest?.('input, select, textarea, [contenteditable]')) return;
    if (e.key === ' ' || e.code === 'Space') { if (t.closest?.('button, a')) return; e.preventDefault(); togglePlay(); }
    else if (e.key === 'ArrowDown' || e.key === 'ArrowLeft') { e.preventDefault(); step(1); }
    else if (e.key === 'ArrowUp' || e.key === 'ArrowRight') { e.preventDefault(); step(-1); }
  });
  window.addEventListener('hashchange', () => { if (location.hash !== '#/files') stop(); });

  // ── הנתונים והרשימה ───────────────────────────────────────
  function filtered() {
    const s = norm(q.trim()), idq = s.replace(/^#/, '');
    const dups = st === 'dups' ? dupCount() : null;
    const sizeOk = r => !sizeF || (r.file && (sizeF === 's' ? r.file.size <= 51200 : sizeF === 'm' ? r.file.size > 51200 && r.file.size <= 1048576 : r.file.size > 1048576));
    const durOk = r => { if (!durF) return true; const d = durOf(r); if (durF === 'unknown') return r.state === 'tool' && d == null; return d != null && (durF === 'short' ? d <= 2 : durF === 'mid' ? d > 2 && d <= 10 : d > 10); };
    const stOk = r => st === 'all' ? true : st === 'none' ? r.state === 'none' : st === 'loose' ? !!r.loose : st === 'failed' ? !!r.fetch
      : st === 'dups' ? !!(r.file?.sha && !r.missing && dups.get(r.file.sha) > 1) : r.state !== 'none' && (!st || (r.state === st && !r.loose));
    const rows = D.all.filter(r => stOk(r) && sizeOk(r) && durOk(r) &&
      (!fieldF || r.field === fieldF || (!r.field && fieldF === primaryKey() && !r.loose)) &&
      (!topic || r.topics.includes(topic)) &&
      (!s || norm(r.word).includes(s) || norm(r.meaning).includes(s) || norm(r.name || '').includes(s) || String(widOf(r)) === idq));
    const key = r => r.loose ? 1e9 - r.id : widOf(r);          // הלא מחוברות — אחרי המילים
    // N3b: קודם המילים, אחריהן כל רשימה (לפי סדר השדות שלה), ובסוף הלא מחוברות
    const grp = r => r.loose ? 2 : r.list ? 1 : 0;
    const cmp = (a, b) => grp(a) - grp(b) || (grp(a) === 1 && a.list !== b.list ? fIdx(a) - fIdx(b) : 0) || key(a) - key(b) || fIdx(a) - fIdx(b);  // N2b: אותה מילה — לפי סדר השדות
    if (st === 'dups') rows.sort((a, b) => a.file.sha < b.file.sha ? -1 : a.file.sha > b.file.sha ? 1 : cmp(a, b));
    else if (sort === 'id') rows.sort(cmp);
    else if (sort === 'state') rows.sort((a, b) => ORDER[a.state] - ORDER[b.state] || cmp(a, b));
    return rows;                             // 'ab' — הסדר שהשרת החזיר (א"ב עברי)
  }

  function row(r) {
    const bad = failed.get(r.id) || (r.missing ? 'הקובץ חסר בכלי' : '');
    const [label, cls] = bad ? ['לא נטען', 'bad'] : STATE[r.state];
    const f = r.file;
    const wid = widOf(r);
    const many = multi();
    const fl = r.field_label ? (fieldOf(r.field)?.short || r.field_label) : '';
    // N2b: "חסרה" לכל שדה — "בסטילה — אין הקלטה בצרפתית"
    const l2 = r.state === 'none' && many && r.field ? `אין ${r.field_label}` : [r.meaning, r.topics.join(', ')].filter(Boolean).join(' · ');
    const named = r.name && r.name !== r.word && !r.loose ? '«' + r.name + '» · ' : '';
    const d = durOf(r);
    const l3 = bad || (r.fetch ? 'ההבאה לכלי נכשלה — ' + r.fetch.reason : '') || (f ? named + [d != null ? '⏱ ' + clock(d) : 'משך לא נמדד', Media.mb(f.size), f.name, date(f.at),
      r.versions ? `${r.versions} ${r.versions === 1 ? 'גרסה קודמת' : 'גרסאות קודמות'}` : ''].filter(Boolean).join(' · ') : '')
      || r.gone || (r.dropped ? `משדה שנמחק: ${r.dropped}` : '');
    const can = r.state !== 'none' && r.rec;
    const word = r.loose ? r.word : `${r.word}${many && fl ? ' · ' + fl : ''}`;
    const li = h('li', { class: 'lib-row' + (P.id === r.id ? ' on' : '') + (chosen.has(r.id) ? ' picked' : '') + (r.state === 'none' ? ' lib-empty' : ''), 'data-id': r.id },
      can ? h('input', { type: 'checkbox', class: 'lib-check', checked: chosen.has(r.id), 'aria-label': `בחירה: ${word}`, onchange: e => choose(r.id, e.target.checked) }) : h('span', { class: 'lib-check-sp' }),
      r.state === 'none'
        ? h('span', { class: 'lib-noplay', role: 'img', 'aria-label': 'בלי הקלטה' }, '—')
        : h('button', { type: 'button', class: 'icon-btn lib-play', 'aria-label': `השמעה: ${word}`, onclick: () => play(r.id) },
          P.id === r.id && P.audio && !P.audio.paused ? '⏸' : '▶'),
      h('button', { type: 'button', class: 'lib-main', 'aria-label': `פרטים: ${word}`, onclick: () => details(r) },
        h('span', { class: 'lib-l1' }, r.loose ? null : h('span', { class: 'lib-id', dir: 'ltr' }, '#' + wid), h('b', { class: 'one lib-title' }, r.word),
          r.internal ? h('span', { class: 'chip lib-internal', title: 'השדה אינו מתפרסם — ההקלטה אינה בחבילה' }, 'פנימי') : null),
        // השדה — שורה שנייה בכרטיס (בטלפון ובמסך צר); במחשב — עמודה
        l2 || (many && fl) ? h('small', { class: 'one' }, many && fl && r.state !== 'none' ? h('b', { class: 'lib-field-m' }, fl + (l2 ? ' · ' : '')) : null, l2) : null,
        l3 ? h('small', { class: 'one' + (bad || r.fetch ? ' lib-bad' : '') }, l3) : null),
      // N2b: העמודות במחשב (שדה · משך · גודל) — רק במסך רחב (app.css, body.lib-wide)
      many ? h('span', { class: 'lib-c lib-c-field one', title: r.field_label || '' }, fl || (r.loose ? 'לא מחוברת' : '')) : null,
      h('span', { class: 'lib-c lib-c-dur', dir: 'ltr', title: 'משך' }, d != null ? clock(d) : '—'),
      h('span', { class: 'lib-c lib-c-size', dir: 'ltr', title: 'גודל' }, f ? Media.mb(f.size) : '—'),
      h('span', { class: `chip ${cls}` }, r.loose && !bad ? 'לא מחוברת' : label),
      r.state === 'none'
        ? (r.list ? h('button', { type: 'button', class: 'icon-btn', 'aria-label': `פתיחת הרשימה: ${r.list_label || r.list}`, title: 'פתיחת הרשימה', onclick: () => Words.openList(r.list) }, '✏️')
          : h('button', { type: 'button', class: 'icon-btn', 'aria-label': `עריכת המילה: ${r.word}`, title: 'עריכת המילה', onclick: () => Words.open(wid) }, '✏️'))
        : null,
      h('button', { type: 'button', class: 'icon-btn lib-rep', title: r.state === 'none' ? 'הקלט / העלה קובץ' : 'החלף / הקלט',
        'aria-label': `${r.state === 'none' ? 'הקלט' : 'החלף / הקלט'}: ${word}`, onclick: () => replace(r.id) }, r.state === 'none' ? '🎙️' : '🔁'),
      r.state === 'none' && !r.loose && D.loose?.length ? h('button', { type: 'button', class: 'icon-btn lib-link', title: 'חבר הקלטה מהספרייה', 'aria-label': `חבר הקלטה מהספרייה: ${word}`,
        onclick: () => LibActions.pickFor(r) }, '🔗') : null,
      can ? h('button', { type: 'button', class: 'icon-btn lib-more', title: 'כל הפעולות', 'aria-label': `כל הפעולות: ${word}`, onclick: () => LibActions.menu(r) }, '⋯') : null);
    // טלפון: לחיצה ארוכה — בחירה מרובה
    if (can) {
      let t = 0;
      li.addEventListener('touchstart', () => { t = setTimeout(() => { t = 0; selMode = true; choose(r.id, true); navigator.vibrate?.(15); }, 550); }, { passive: true });
      for (const ev of ['touchend', 'touchmove', 'touchcancel']) li.addEventListener(ev, () => { clearTimeout(t); }, { passive: true });
      li.addEventListener('contextmenu', e => { if (matchMedia('(pointer: coarse)').matches) e.preventDefault(); });
    }
    LibActions.dragRow(li, r);                // N2b: גרירה — קובץ או שורה על השורה, ושורה כמקור
    items.set(r.id, li);
    return li;
  }

  // ── בחירה מרובה ───────────────────────────────────────────
  let selBar = null;
  function choose(id, on) { on ? chosen.add(id) : chosen.delete(id); redraw(id); drawSel(); }
  function drawSel() {
    if (!selBar) return;
    document.body.classList.toggle('lib-selecting', selMode || chosen.size > 0);
    selBar.hidden = !chosen.size;
    if (!chosen.size) return;
    const rows = [...chosen].map(byId).filter(Boolean);
    const all = filtered().filter(r => r.state !== 'none' && r.rec);
    selBar.replaceChildren(
      h('b', {}, `נבחרו ${num(chosen.size)}`),
      all.some(r => !chosen.has(r.id)) ? h('button', { type: 'button', class: 'btn ghost sm', onclick: () => { all.forEach(r => chosen.add(r.id)); draw(); drawSel(); } }, `בחר את כל המסוננות (${num(all.length)})`) : null,
      h('button', { type: 'button', class: 'btn ghost sm', onclick: () => LibActions.download(rows) }, '⬇️ הורדה'),
      h('button', { type: 'button', class: 'btn ghost sm', onclick: () => LibActions.disconnect(rows) }, '⛓️ ניתוק'),
      h('button', { type: 'button', class: 'btn ghost sm danger', onclick: () => LibActions.remove(rows) }, '🗑️ מחיקה'),
      h('button', { type: 'button', class: 'icon-btn', 'aria-label': 'ביטול הבחירה', onclick: clearSel }, '✕'));
  }
  function clearSel() { chosen.clear(); selMode = false; if (D && listEl) draw(); drawSel(); }
  function redraw(id) {
    const old = items.get(id), r = D && byId(id);
    if (old?.isConnected && r) old.replaceWith(row(r));
  }

  function draw() {
    if (st === 'trash') { LibActions.trashView(listEl); moreEl.replaceChildren(); return; }
    const all = filtered();
    items = new Map();
    const lis = [];
    let prevSha = null;
    for (const r of all.slice(0, shown)) {           // כפולות — כותרת לכל קבוצה, ו"השאר את זו" בכל שורה
      if (st === 'dups' && r.file.sha !== prevSha) {
        prevSha = r.file.sha;
        const n = all.filter(x => x.file.sha === prevSha).length;
        lis.push(h('li', { class: 'lib-group' }, h('b', {}, `${n} עותקים זהים`), ' · ', h('small', { dir: 'ltr' }, 'sha256 ' + prevSha.slice(0, 12) + '…')));
      }
      const li = row(r);
      if (st === 'dups') li.append(h('button', { type: 'button', class: 'btn ghost sm lib-keep', onclick: () => LibActions.keepOne(r) }, 'השאר את זו'));
      lis.push(li);
    }
    listEl.replaceChildren(...(all.length
      ? [h('ul', { class: 'list lib-list' }, lis)]
      : [h('p', { class: 'empty' }, st === 'none' ? 'לכל המילים יש הקלטה.' : st === 'loose' ? 'אין הקלטות שאינן מחוברות למילה.' : st === 'dups' ? 'אין כפולות — כל הקבצים שונים.'
        : D.counts.audio || D.counts.loose ? 'לא נמצאו הקלטות.' : 'עוד אין הקלטות. ייבוא המילים מהאתר — ב-⚙️ הגדרות.')]));
    const left = all.length - shown;
    moreEl.replaceChildren(...[
      left > 0 ? h('button', { type: 'button', class: 'btn ghost w-more', onclick: more }, `עוד ${Math.min(PAGE, left)} (נשארו ${num(left)})`) : null,
      h('p', { class: 'hint', style: 'text-align:center;margin-top:8px' }, `מוצגות ${num(Math.min(shown, all.length))} מתוך ${num(all.length)}`),
    ].filter(Boolean));
    if (left > 0) watch.observe(moreEl.firstChild);
  }
  function more() { shown += PAGE; draw(); }
  // גלילה לסוף הרשימה טוענת את החלק הבא
  const watch = new IntersectionObserver(es => { if (es.some(e => e.isIntersecting)) { watch.disconnect(); more(); } }, { rootMargin: '300px' });

  /** מביא שורה לתצוגה (גם אם עוד לא נטענה לרשימה). */
  function reveal(id) {
    const i = filtered().findIndex(r => r.id === id);
    if (i < 0) return false;
    if (i >= shown) { shown = Math.ceil((i + 1) / PAGE) * PAGE; draw(); }
    items.get(id)?.scrollIntoView({ block: 'center' });
    return true;
  }

  // ── פעולות ────────────────────────────────────────────────
  /** "החלף / הקלט" — הפעולה של 📚 מילים (words.js), מופעלת מכאן. ההקלטה שבאתר אינה נמחקת ואינה משתנה. */
  async function replace(id) {
    try {
      const r = byId(id);
      if (!r) return;
      const [f] = await Media.pick({ kind: 'audio' });
      if (!f) return;
      await api('rec_replace', r.rec ? { rec_id: r.rec, file_id: f.id } : { word_id: widOf(r), field: r.field, file_id: f.id });
      if (P.id === id) stop();
      failed.delete(id); dur.delete(id);
      const dlg = $('#sheet'); if (dlg.open) dlg.close();
      await reload(true);
      toast(r.state === 'none' ? 'ההקלטה נשמרה' : 'ההקלטה הוחלפה — הקודמת נשמרה כגרסה');
    } catch (e) { toast(e.message, true); }
  }

  function details(r) {
    const bad = failed.get(r.id) || (r.missing ? 'הקובץ חסר בכלי' : '');
    const f = r.file;
    const cell = (k, v, attrs = {}) => v ? h('div', {}, h('dt', {}, k), h('dd', attrs, v)) : null;
    sheet(r.loose ? r.word : `#${widOf(r)} · ${r.word}`,
      bad ? h('p', { class: 'error' }, 'לא נטען — ' + bad) : null,
      r.meaning ? h('p', { dir: 'auto' }, r.meaning) : null,
      h('dl', { class: 'cells' },
        r.loose ? cell(r.list ? 'פריט' : 'מילה', 'לא מחוברת') : r.list ? cell(`מזהה הפריט ב${r.list_label || r.list}`, String(widOf(r)), { dir: 'ltr' }) : cell('מזהה המילה', String(widOf(r)), { dir: 'ltr' }),
        multi() && r.field_label ? cell('שדה', r.field_label + (r.internal ? ' · פנימי (אינו מתפרסם)' : '')) : null,
        r.dropped ? cell('שדה', r.gone || `משדה שנמחק: ${r.dropped}`) : null,
        r.path ? cell('בעץ הקוד', r.path, { dir: 'ltr' }) : null,
        cell('מצב', STATE[r.state][0]),
        cell('נושאים', r.topics.join(', ')),
        cell('מספר מהאקסל', r.external_id, { dir: 'ltr' }),
        f ? cell('שם הקובץ', f.name, { dir: 'auto', class: 'lib-wrap' }) : null,
        f ? cell('גודל', Media.mb(f.size)) : null,
        f ? cell('משך', dur.has(r.id) ? clock(dur.get(r.id)) : f.duration != null ? clock(f.duration) : 'יוצג אחרי השמעה') : null,
        r.fetch ? cell('ההבאה לכלי', 'נכשלה — ' + r.fetch.reason) : null,
        f ? cell('נוסף', `${f.by} · ${date(f.at)}`) : null),
      r.shared?.length ? h('p', { class: 'hint' }, 'אותה הקלטה משמשת גם את: ',
        r.shared.flatMap((id, i) => [i ? ', ' : '', h('a', { href: '#/files', onclick: e => { e.preventDefault(); $('#sheet').close(); show(id); } }, `#${id} ${byId(id)?.word || ''}`)])) : null,
      r.state === 'site' ? h('label', { class: 'f' }, h('span', {}, 'הכתובת באתר (מכאן משמיעים)'), h('input', { readonly: true, dir: 'ltr', value: r.src })) : null,
      f?.origin ? h('label', { class: 'f' }, h('span', {}, 'עותק של ההקלטה שבאתר'), h('input', { readonly: true, dir: 'ltr', value: f.origin })) : null,
      r.state === 'site' ? h('p', { class: 'hint' }, 'ההקלטה נמצאת באתר ומתנגנת משם. אפשר להחליף אותה מכאן — ההקלטה החדשה תישמר בכלי, והמקורית באתר לא נמחקת. שינוי שם, מחיקה והורדה ייפתחו אחרי שהקבצים יובאו לכלי.') : null,
      r.name && r.name !== r.word ? h('p', { class: 'hint' }, 'השם לתצוגה: ', h('b', {}, r.name)) : null,
      h('div', { class: 'row-btns wrap' },
        r.state !== 'none' ? h('button', { type: 'button', class: 'btn primary', onclick: () => { $('#sheet').close(); play(r.id); } }, '▶ השמעה') : null,
        h('button', { type: 'button', class: 'btn ghost', onclick: () => replace(r.id) }, r.state === 'none' ? '🎙️ הקלט / בחר קובץ' : '🔁 החלף / הקלט'),
        r.rec ? h('button', { type: 'button', class: 'btn ghost', onclick: () => LibActions.menu(r) }, '⋯ כל הפעולות') : null,
        r.state === 'none' && !r.loose && D.loose?.length ? h('button', { type: 'button', class: 'btn ghost', onclick: () => LibActions.pickFor(r) }, '🔗 חבר הקלטה מהספרייה') : null,
        r.loose ? null : h('button', { type: 'button', class: 'btn ghost', onclick: () => { $('#sheet').close(); r.list ? Words.openList(r.list) : Words.open(widOf(r)); } }, r.list ? `📚 פתח את הרשימה "${r.list_label || r.list}"` : '📚 פתח את המילה')));
  }

  // ── המסכים ────────────────────────────────────────────────
  function audioView(box) {
    const c = D.counts;
    const pick = v => { st = v; shown = PAGE; render(); };
    const stat = (n, label, v) => h('button', { type: 'button', class: 'lib-stat' + (st === v ? ' on' : ''), 'aria-pressed': String(st === v), onclick: () => pick(st === v ? '' : v) },
      h('b', {}, num(n)), ' ', label);
    const search = h('input', { type: 'search', class: 'w-search', placeholder: 'חיפוש מילה, פירוש או מזהה…', value: q, 'aria-label': 'חיפוש',
      oninput: e => { q = e.target.value; shown = PAGE; draw(); } });
    const sel = (label, value, opts, on) => h('select', { 'aria-label': label, onchange: e => on(e.target.value) },
      opts.map(([v, l]) => h('option', { value: v, selected: v === value }, l)));
    listEl = h('div', {}); moreEl = h('div', {});
    selBar = h('div', { class: 'lib-selbar', role: 'region', 'aria-label': 'פעולות על הנבחרות', hidden: true });
    const inTool = D.all.filter(r => r.state === 'tool' && !r.missing).length;
    box.replaceChildren(
      h('div', { class: 'summary' },
        h('div', { class: 'lib-stats' },
          h('span', { class: 'lib-total' }, h('b', {}, num(c.audio)), ' הקלטות'),
          stat(c.site, 'באתר', 'site'), stat(c.tool, 'בכלי', 'tool'), stat(c.none, multi() ? (c.none === 1 ? 'חסרה' : 'חסרות') : c.none === 1 ? 'מילה בלי הקלטה' : 'מילים בלי הקלטה', 'none'),
          c.loose ? stat(c.loose, 'לא מחוברות', 'loose') : null,
          // N2b: מונה לכל שדה ("הקלטה 1,480 · בצרפתית 312") — לחיצה מסננת לשדה
          ...(multi() ? D.fields.map(f => h('button', { type: 'button', class: 'lib-stat lib-fstat' + (fieldF === f.key ? ' on' : ''), 'aria-pressed': String(fieldF === f.key),
            title: `${f.label}: ${num(c.fields?.[f.key]?.audio || 0)} הקלטות · ${num(c.fields?.[f.key]?.none || 0)} חסרות`,
            onclick: () => { fieldF = fieldF === f.key ? '' : f.key; shown = PAGE; render(); } }, f.short, ' ', h('b', {}, num(c.fields?.[f.key]?.audio || 0)))) : []),
          c.failed ? stat(c.failed, 'נכשלו בהבאה', 'failed') : null,
          stat(c.trash, '🗑️ בסל', 'trash')),
        c.missing ? h('p', { class: 'hint lib-bad', style: 'margin:0' }, `${num(c.missing)} מההקלטות שבכלי מצביעות על קובץ חסר — מסומנות "לא נטען".`) : null,
        c.site ? h('p', { class: 'hint', style: 'margin:0' }, 'הקלטה "באתר" מתנגנת ישירות מהאתר. 🔁 מחליף אותה בהקלטה חדשה שנשמרת בכלי; שינוי שם ומחיקה — אחרי הבאת הקבצים.') : null,
        isOwner && (c.site || c.tool) && typeof LibraryFetch !== 'undefined' ? LibraryFetch.panel(c, reload) : null,
        measureBtn(),
        c.audio ? h('p', { class: 'hint', style: 'margin:0' }, D.fields?.some(f => f.list && f.list !== 'words') ? 'מוצגות ההקלטות של המילים ושל הרשימות. קובצי שמע אחרים שבאתר עדיין אינם מוצגים.' : 'מוצגות ההקלטות של המילים. קובצי שמע אחרים שבאתר עדיין אינם מוצגים.') : null,
        h('div', { class: 'row-btns wrap lib-tools' },
          LibActions.addButton(),
          inTool ? h('button', { type: 'button', class: 'btn ghost', onclick: () => LibActions.download(null) }, `⬇️ הורדת כולן (${num(inTool)})`) : null,
          h('button', { type: 'button', class: 'btn ghost', onclick: () => { st = 'dups'; shown = PAGE; render(); } }, '👯 כפולות')),
        LibActions.versionsLine()),
      h('div', { class: 'w-filters lib-filters' + (multi() ? ' lib-f-field' : '') }, search,
        sel('מצב', st, [['', 'כל ההקלטות'], ...(multi() ? [['all', 'הכול — גם חסרות (לגרירה)']] : []), ['site', 'באתר'], ['tool', 'בכלי'], ['none', multi() ? 'חסרות' : 'מילים בלי הקלטה'], ['loose', 'לא בשימוש (לא מחוברות)'],
          ['dups', 'כפולות (אותו קובץ)'], ['failed', 'נכשלו בהבאה'], ['trash', '🗑️ הסל']], pick),
        multi() ? sel('שדה', fieldF, [['', 'כל השדות'], ...D.fields.map(f => [f.key, f.label + (f.published ? '' : ' (פנימי)')])], v => { fieldF = v; shown = PAGE; render(); }) : null,
        sel('נושא', topic, [['', 'כל הנושאים'], ...D.topics.map(t => [t.name, `${t.name} (${t.count})`])], v => { topic = v; shown = PAGE; draw(); }),
        sel('משך', durF, Object.entries(DURS), v => { durF = v; shown = PAGE; draw(); }),
        sel('גודל', sizeF, Object.entries(SIZES), v => { sizeF = v; shown = PAGE; draw(); }),
        sel('מיון', sort, Object.entries(SORTS), v => { sort = v; shown = PAGE; draw(); })),
      selBar, listEl, moreEl);
    LibActions.dropZone(box);
    draw();
    drawSel();
  }

  /** "⏱️ מדוד את כולן" — המשך של ההקלטות שבכלי שעוד לא נמדדו (library-fetch.js). */
  function measureBtn() {
    const n = D.all.filter(r => r.state === 'tool' && r.file && r.file.duration == null && !r.missing).length;
    if (!n || typeof LibraryFetch === 'undefined') return null;
    const b = h('button', { type: 'button', class: 'btn ghost lib-measure', onclick: () => LibraryFetch.measureAll(D.all, m => {
      b.textContent = m.on ? `⏹ מודד… ${m.done} מתוך ${m.total}` : `✓ נמדדו ${m.done}`;
      if (!m.on) for (const r of D.all) if (r.file?.duration != null && !dur.has(r.id)) { dur.set(r.id, r.file.duration); redraw(r.id); }
    }) }, `⏱️ מדוד את כולן (${n})`);
    return b;
  }

  /** אחרי הבאה — הנתונים מחדש, בלי לאבד את מקום הגלילה. */
  let reloadSeq = 0;               // T1: רק התשובה האחרונה נכנסת — תשובה ישנה שמגיעה אחרי חדשה (עומס) לא מחזירה מצב קודם
  async function reload(loud = false) {
    if (!host?.isConnected || location.hash !== '#/files') return;
    const my = ++reloadSeq;
    try { const keep = shown, d = await api('library'); if (my !== reloadSeq) return; setD(d); shown = keep; if (tab === 'audio') render(); }
    catch (e) { if (loud && my === reloadSeq) toast(e.message, true); /* נשאר המצב הקודם */ }
  }

  function imagesView(box) {
    const sub = h('div', { class: 'lib-files lib-images' });
    box.replaceChildren(
      D.images.length ? h('h2', { class: 'sec-title' }, `באתר · ${D.images.length}`) : null,
      D.images.length ? h('ul', { class: 'list' }, D.images.map(im => {
        const src = safeSrc(im.url);
        return h('li', {}, h('button', { type: 'button', class: 'use-row', onclick: () => sheet(im.label,
          src ? h('img', { class: 'file-preview', src, alt: im.label, referrerpolicy: 'no-referrer' }) : h('p', { class: 'error' }, 'הכתובת אינה כתובת https באתר'),
          h('label', { class: 'f' }, h('span', {}, 'הכתובת באתר'), h('input', { readonly: true, dir: 'ltr', value: im.url })),
          h('p', { class: 'hint' }, im.replaced ? 'בעמוד מוצגת עכשיו תמונה אחרת, מהכלי. התמונה הזו נשארת באתר.' : 'התמונה נמצאת באתר ומוצגת משם. מחליפים אותה ב-🎨 סטודיו ← המילון ← רקע.')) },
          src ? h('img', { class: 'thumb', src, alt: '', loading: 'lazy', referrerpolicy: 'no-referrer' }) : h('span', { class: 'use-ic', 'aria-hidden': 'true' }, '🖼️'),
          h('span', { class: 'use-tx' }, h('b', { class: 'one' }, im.label), h('small', { dir: 'ltr' }, im.url.replace(/^https:\/\//, ''))),
          h('span', { class: 'chip' }, 'באתר'), h('span', { class: 'use-go', 'aria-hidden': 'true' }, '‹')));
      })) : null,
      h('h2', { class: 'sec-title' }, 'בכלי'), sub);
    sessionStorage.setItem('kol-files-kind', 'image');
    Media.view(sub, isOwner);
  }

  /** N2b: במחשב — ההקלטות על כל הרוחב (body.lib-wide מסיר את max-width של main; app.css, כללי .lib-*). */
  function wide(on) { document.body.classList.toggle('lib-wide', !!on); }
  window.addEventListener('hashchange', () => { if (location.hash !== '#/files') wide(false); });

  function render() {
    wide(tab === 'audio');
    const tabs = [['audio', '🎙️ הקלטות', D.counts.audio], ['images', '🖼️ תמונות', D.images.length], ['files', '🗂️ קבצים', '']];
    const box = h('div', {});
    host.replaceChildren(
      h('div', { class: 'seg-tabs', role: 'tablist' }, tabs.map(([k, l, n]) =>
        h('button', { type: 'button', role: 'tab', 'aria-selected': String(tab === k), onclick: () => {
          if (k === 'files' && tab === 'images') sessionStorage.removeItem('kol-files-kind');
          tab = k; sessionStorage.setItem('kol-lib-tab', k); if (k !== 'audio') stop(); render();
        } }, l, ' ', h('small', {}, n ? num(n) : '')))),
      box);
    if (tab === 'audio') audioView(box);
    else if (tab === 'images') imagesView(box);
    else Media.view(box, isOwner);
  }

  /** owner — בעל האתר (הבאה מהאתר, תקרת דיסק); page — בעל העמוד ומעלה (מחיקה סופית, ניקוי גרסאות — page_owner ב-ACTION_ROLES). */
  async function view(el, owner, page = owner) {
    host = el; isOwner = owner; pageOwner = page || owner;
    host.replaceChildren(h('p', { class: 'empty' }, 'טוען…'));
    try { setD(await api('library')); } catch (e) { host.replaceChildren(h('p', { class: 'error' }, e.message)); return; }
    // ברירת המחדל: "הקלטות" כשיש הקלטות
    tab = sessionStorage.getItem('kol-lib-tab') || (D.counts.audio ? 'audio' : 'files');
    shown = PAGE;
    const id = pending; pending = 0;
    if (id && byId(id)) {
      tab = 'audio'; q = ''; topic = ''; st = byId(id).state === 'none' ? 'none' : '';
      render();
      if (reveal(id)) { items.get(id)?.classList.add('flash'); }
      return;
    }
    render();
  }

  /** מ-📚 מילים: "הצג בספרייה". */
  function show(id) { pending = id; if (location.hash === '#/files') view(host || main, isOwner, pageOwner); else location.hash = '#/files'; }

  /** A3 — מה ש-library-actions.js צריך מבפנים. */
  const ctx = {
    get D() { return D; }, get isOwner() { return isOwner; }, get pageOwner() { return pageOwner; }, byId, safeSrc, reload, clearSel, play, stop, redraw, dur, clock, date, num,
    widOf, multi, fieldOf, primaryKey, slotRow, get fieldF() { return fieldF; },
    replace, focusTrash: () => { st = 'trash'; render(); }, titleOf: id => items.get(id)?.querySelector('.lib-title'),
    stoppedIf: id => { if (P.id === id) stop(); },
  };
  return { view, show, errors, logPlaybackError, ctx };
})();
