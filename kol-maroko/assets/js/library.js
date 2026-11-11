// קול מרוקו — הספרייה (פרוסה A1): כל ההקלטות של המילון — גם אלה שעדיין רק כתובת באתר — כשורות שאפשר להשמיע.
// המפתח של הקלטה הוא מזהה המילה. ההשמעה היא של הדפדפן, ישירות מהכתובת; שום דבר לא יורד ולא נשמר.
// "קבצים" ו"תמונות" — המסך הקיים (media.js), בתוך לשונית.
'use strict';

const Library = (() => {
  const PAGE = 60;                         // שורות בכל פעם
  const TIMEOUT = 15000;                   // כמה מחכים להקלטה לפני "תם הזמן"
  const STATE = { site: ['באתר', ''], tool: ['בכלי', 'ok'], none: ['בלי הקלטה', 'warn'] };
  const SORTS = { id: 'לפי מזהה', ab: 'לפי א"ב', state: 'לפי מצב' };
  const ORDER = { tool: 0, site: 1, none: 2 };
  let D = null, host = null, isOwner = false;
  let tab = '', q = '', st = '', topic = '', sort = 'id', shown = PAGE;
  let pending = 0;                         // מילה להציג כשהמסך נטען ("הצג בספרייה")
  let listEl = null, moreEl = null, items = new Map();
  const failed = new Map();                // id → למה לא נטען
  const dur = new Map();                   // id → משך בשניות (נודע רק אחרי שהושמע)

  const norm = s => String(s).toLowerCase().replace(/[֑-ׇ]/g, '');
  const num = n => n.toLocaleString('he-IL');
  const clock = s => isFinite(s) ? `${Math.floor(s / 60)}:${String(Math.floor(s % 60)).padStart(2, '0')}` : '';
  const date = iso => new Date(iso).toLocaleDateString('he-IL', { day: 'numeric', month: 'short', year: '2-digit', timeZone: 'Asia/Jerusalem' });
  const byId = id => D.rows.find(r => r.id === id);

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
    a.addEventListener('loadedmetadata', () => { if (isFinite(a.duration)) { dur.set(P.id, a.duration); redraw(P.id); } });
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
    logPlaybackError({ word_id: P.id, word: r?.word || '', url: r?.src || '', state: r?.state || '', reason, code });
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
    p.title.textContent = `#${r.id} · ${r.word}`;
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
    const rows = D.rows.filter(r =>
      (st === 'none' ? r.state === 'none' : r.state !== 'none' && (!st || r.state === st)) &&
      (!topic || r.topics.includes(topic)) &&
      (!s || norm(r.word).includes(s) || norm(r.meaning).includes(s) || String(r.id) === idq));
    if (sort === 'id') rows.sort((a, b) => a.id - b.id);
    else if (sort === 'state') rows.sort((a, b) => ORDER[a.state] - ORDER[b.state] || a.id - b.id);
    return rows;                             // 'ab' — הסדר שהשרת החזיר (א"ב עברי)
  }

  function row(r) {
    const bad = failed.get(r.id) || (r.missing ? 'הקובץ חסר בכלי' : '');
    const [label, cls] = bad ? ['לא נטען', 'bad'] : STATE[r.state];
    const f = r.file;
    const l2 = [r.meaning, r.topics.join(', ')].filter(Boolean).join(' · ');
    const l3 = bad || (f ? [f.name, Media.mb(f.size), clock(dur.get(r.id) ?? NaN), date(f.at)].filter(Boolean).join(' · ') : '');
    const li = h('li', { class: 'lib-row' + (P.id === r.id ? ' on' : ''), 'data-id': r.id },
      r.state === 'none'
        ? h('span', { class: 'lib-noplay', role: 'img', 'aria-label': 'בלי הקלטה' }, '—')
        : h('button', { type: 'button', class: 'icon-btn lib-play', 'aria-label': `השמעה: ${r.word}`, onclick: () => play(r.id) },
          P.id === r.id && P.audio && !P.audio.paused ? '⏸' : '▶'),
      h('button', { type: 'button', class: 'lib-main', 'aria-label': `פרטים: ${r.word}`, onclick: () => details(r) },
        h('span', { class: 'lib-l1' }, h('span', { class: 'lib-id', dir: 'ltr' }, '#' + r.id), h('b', { class: 'one' }, r.word)),
        l2 ? h('small', { class: 'one' }, l2) : null,
        l3 ? h('small', { class: 'one' + (bad ? ' lib-bad' : '') }, l3) : null),
      h('span', { class: `chip ${cls}` }, label),
      r.state === 'none'
        ? h('button', { type: 'button', class: 'icon-btn', 'aria-label': `עריכת המילה: ${r.word}`, title: 'עריכת המילה', onclick: () => Words.open(r.id) }, '✏️')
        : null,
      h('button', { type: 'button', class: 'icon-btn lib-rep', title: r.state === 'none' ? 'הקלט / בחר קובץ' : 'החלף / הקלט',
        'aria-label': `${r.state === 'none' ? 'הקלט' : 'החלף / הקלט'}: ${r.word}`, onclick: () => replace(r.id) }, r.state === 'none' ? '🎙️' : '🔁'));
    items.set(r.id, li);
    return li;
  }
  function redraw(id) {
    const old = items.get(id), r = D && byId(id);
    if (old?.isConnected && r) old.replaceWith(row(r));
  }

  function draw() {
    const all = filtered();
    items = new Map();
    listEl.replaceChildren(...(all.length
      ? [h('ul', { class: 'list lib-list' }, all.slice(0, shown).map(row))]
      : [h('p', { class: 'empty' }, st === 'none' ? 'לכל המילים יש הקלטה.' : D.counts.audio ? 'לא נמצאו הקלטות.' : 'עוד אין הקלטות. ייבוא המילים מהאתר — ב-⚙️ הגדרות.')]));
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
      if (!await Words.replaceAudio(id)) return;
      if (P.id === id) stop();
      failed.delete(id); dur.delete(id);
      const keep = shown;
      D = await api('library');
      shown = keep;
      const dlg = $('#sheet'); if (dlg.open) dlg.close();
      render();
      toast('ההקלטה הוחלפה — עכשיו בכלי');
    } catch (e) { toast(e.message, true); }
  }

  function details(r) {
    const bad = failed.get(r.id) || (r.missing ? 'הקובץ חסר בכלי' : '');
    const f = r.file;
    const cell = (k, v, attrs = {}) => v ? h('div', {}, h('dt', {}, k), h('dd', attrs, v)) : null;
    sheet(`#${r.id} · ${r.word}`,
      bad ? h('p', { class: 'error' }, 'לא נטען — ' + bad) : null,
      r.meaning ? h('p', { dir: 'auto' }, r.meaning) : null,
      h('dl', { class: 'cells' },
        cell('מזהה המילה', String(r.id), { dir: 'ltr' }),
        cell('מצב', STATE[r.state][0]),
        cell('נושאים', r.topics.join(', ')),
        cell('מספר מהאקסל', r.external_id, { dir: 'ltr' }),
        f ? cell('שם הקובץ', f.name, { dir: 'auto', class: 'lib-wrap' }) : null,
        f ? cell('גודל', Media.mb(f.size)) : null,
        f ? cell('משך', dur.has(r.id) ? clock(dur.get(r.id)) : 'יוצג אחרי השמעה') : null,
        f ? cell('נוסף', `${f.by} · ${date(f.at)}`) : null),
      r.shared?.length ? h('p', { class: 'hint' }, 'אותה הקלטה משמשת גם את: ',
        r.shared.map((id, i) => [i ? ', ' : '', h('a', { href: '#/files', onclick: e => { e.preventDefault(); $('#sheet').close(); show(id); } }, `#${id} ${byId(id)?.word || ''}`)])) : null,
      r.state === 'site' ? h('label', { class: 'f' }, h('span', {}, 'הכתובת באתר (מכאן משמיעים)'), h('input', { readonly: true, dir: 'ltr', value: r.src })) : null,
      f?.origin ? h('label', { class: 'f' }, h('span', {}, 'עותק של ההקלטה שבאתר'), h('input', { readonly: true, dir: 'ltr', value: f.origin })) : null,
      r.state === 'site' ? h('p', { class: 'hint' }, 'ההקלטה נמצאת באתר ומתנגנת משם. אפשר להחליף אותה מכאן — ההקלטה החדשה תישמר בכלי, והמקורית באתר לא נמחקת. שינוי שם, מחיקה והורדה ייפתחו אחרי שהקבצים יובאו לכלי.') : null,
      h('div', { class: 'row-btns wrap' },
        r.state !== 'none' ? h('button', { type: 'button', class: 'btn primary', onclick: () => { $('#sheet').close(); play(r.id); } }, '▶ השמעה') : null,
        h('button', { type: 'button', class: 'btn ghost', onclick: () => replace(r.id) }, r.state === 'none' ? '🎙️ הקלט / בחר קובץ' : '🔁 החלף / הקלט'),
        h('button', { type: 'button', class: 'btn ghost', onclick: () => { $('#sheet').close(); Words.open(r.id); } }, '📚 פתח את המילה')));
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
    box.replaceChildren(
      h('div', { class: 'summary' },
        h('div', { class: 'lib-stats' },
          h('span', { class: 'lib-total' }, h('b', {}, num(c.audio)), ' הקלטות'),
          stat(c.site, 'באתר', 'site'), stat(c.tool, 'בכלי', 'tool'), stat(c.none, c.none === 1 ? 'מילה בלי הקלטה' : 'מילים בלי הקלטה', 'none')),
        c.missing ? h('p', { class: 'hint lib-bad', style: 'margin:0' }, `${num(c.missing)} מההקלטות שבכלי מצביעות על קובץ חסר — מסומנות "לא נטען".`) : null,
        c.site ? h('p', { class: 'hint', style: 'margin:0' }, 'הקלטה "באתר" מתנגנת ישירות מהאתר. 🔁 מחליף אותה בהקלטה חדשה שנשמרת בכלי; שינוי שם ומחיקה — אחרי הבאת הקבצים.') : null),
      h('div', { class: 'w-filters lib-filters' }, search,
        sel('מצב', st, [['', 'כל ההקלטות'], ['site', 'באתר'], ['tool', 'בכלי'], ['none', 'מילים בלי הקלטה']], pick),
        sel('נושא', topic, [['', 'כל הנושאים'], ...D.topics.map(t => [t.name, `${t.name} (${t.count})`])], v => { topic = v; shown = PAGE; draw(); }),
        sel('מיון', sort, Object.entries(SORTS), v => { sort = v; shown = PAGE; draw(); })),
      listEl, moreEl);
    draw();
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

  function render() {
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

  async function view(el, owner) {
    host = el; isOwner = owner;
    host.replaceChildren(h('p', { class: 'empty' }, 'טוען…'));
    try { D = await api('library'); } catch (e) { host.replaceChildren(h('p', { class: 'error' }, e.message)); return; }
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
  function show(id) { pending = id; if (location.hash === '#/files') view(host || main, isOwner); else location.hash = '#/files'; }

  return { view, show, errors, logPlaybackError };
})();
