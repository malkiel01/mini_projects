// קול מרוקו — קבצים: העלאה בחלקים, הקלטה, בוחר קבצים ומסך הקבצים (docs/spec.md, פרק 9).
'use strict';

const Media = (() => {
  const KINDS = { image: ['🖼️', 'תמונות'], audio: ['🎙️', 'שמע'], video: ['🎬', 'וידאו'], doc: ['📄', 'מסמכים'] };
  const ACCEPT = {
    image: 'image/jpeg,image/png,image/webp,image/gif',
    audio: 'audio/*,.m4a,.mp3,.wav,.ogg,.webm',
    video: 'video/mp4,video/webm,video/quicktime',
    doc: '.pdf,.docx,.xlsx,.pptx,.zip,.txt',
  };
  const MAX = 50 * 1024 * 1024;

  const mb = b => b >= 1073741824 ? +(b / 1073741824).toFixed(1) + 'GB' : b >= 1048576 ? (b / 1048576).toFixed(1) + 'MB' : Math.max(1, Math.round(b / 1024)) + 'KB';
  const date = iso => new Date(iso).toLocaleDateString('he-IL', { day: 'numeric', month: 'short', timeZone: 'Asia/Jerusalem' });
  const wait = ms => new Promise(r => setTimeout(r, ms));
  // שם בלי סיומת (סיומת לטינית בסוף שם עברי מתבלגנת בכיוון), והסוג בשורת המשנה
  const base = name => name.replace(/\.[a-z0-9]{1,5}$/i, '');
  const meta = f => `${(f.name.match(/\.([a-z0-9]{1,5})$/i)?.[1] || f.kind).toUpperCase()} · ${mb(f.size)} · ${f.by} · ${date(f.at)}`;

  // ── העלאה בחלקים ────────────────────────────────────────
  // חלק שנכשל ברשת — עד 5 ניסיונות (1, 2, 4, 8, 16 שניות). 413 — חצי חלק. 409 — ממשיכים מהמקום שהשרת אומר.
  // purpose: 'import' — ZIP של העמוד מהאתר (עד 3GB); מחזיר את מזהה ההעלאה, בלי upload_done
  async function upload(file, onProgress = () => {}, purpose = 'file') {
    if (purpose === 'file' && file.size > MAX) throw new Error(`"${file.name}" גדול מ-50MB`);
    if (!file.size) throw new Error(`"${file.name}" ריק`);
    const init = await api('upload_init', { name: file.name, size: file.size, purpose });
    let chunk = init.chunk, off = 0, tries = 0;
    const t0 = Date.now();
    onProgress(0, file.size, null);
    while (off < file.size) {
      let res, data;
      try {
        res = await fetch(`api.php?action=upload_chunk&id=${init.id}&offset=${off}`, {
          method: 'POST', body: file.slice(off, off + chunk),
          headers: { 'Content-Type': 'application/octet-stream', 'X-Kol': '1', 'X-CSRF': state.csrf },
        });
        data = await res.json().catch(() => ({}));
      } catch {
        if (++tries > 5) throw new Error('החיבור נפל. נסו שוב כשיש רשת');
        onProgress(off, file.size, null, `אין חיבור — ניסיון ${tries} מתוך 5…`);
        await wait(1000 * 2 ** (tries - 1));
        continue;
      }
      if (res.status === 413 && chunk > 256 * 1024) { chunk = Math.floor(chunk / 2); continue; }
      if (res.status === 409 && typeof data.received === 'number') { off = data.received; continue; }
      if (!data.ok) throw new Error(data.error || `העלאה נכשלה (${res.status})`);
      off = data.received; tries = 0;
      const rate = off / Math.max(1, Date.now() - t0);                          // בתים למילישנייה
      onProgress(off, file.size, rate > 0 ? Math.round((file.size - off) / rate / 1000) : null);
    }
    if (purpose === 'import') return init.id;
    const done = await api('upload_done', { id: init.id, type: file.type });
    return done.file;
  }

  function progressRow() {
    const bar = h('span', { style: 'inline-size:0%' });
    const txt = h('span', { class: 'muted' }, 'מתחיל…');
    const name = h('b', { class: 'one' });
    const el = h('div', { class: 'summary up-row' }, h('div', { class: 'line' }, name, txt),
      h('span', { class: 'track', role: 'progressbar', 'aria-valuemin': 0, 'aria-valuemax': 100 }, bar));
    return {
      el,
      set(fileName, got, total, left, note) {
        name.textContent = fileName;
        const pct = Math.floor(got / total * 100);
        bar.style.inlineSize = pct + '%';
        el.querySelector('.track').setAttribute('aria-valuenow', pct);
        txt.textContent = note || `${mb(got)} מתוך ${mb(total)}${left != null && got < total ? ` · נשארו ~${left < 60 ? left + ' שנ׳' : Math.ceil(left / 60) + ' דק׳'}` : ''}`;
      },
    };
  }

  async function uploadMany(files, box) {
    const out = [];
    for (const f of files) {
      const row = progressRow();
      box.append(row.el);
      try {
        out.push(await upload(f, (got, total, left, note) => row.set(f.name, got, total, left, note)));
        row.el.remove();
      } catch (e) {
        row.el.classList.add('error');
        row.el.querySelector('.line .muted').textContent = e.message;
      }
    }
    return out;
  }

  // ── הקלטה ────────────────────────────────────────────────
  function recorderType() {
    if (!window.MediaRecorder) return null;
    for (const t of ['audio/mp4', 'audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus']) if (MediaRecorder.isTypeSupported(t)) return t;
    return '';
  }

  /** גיליון הקלטה. מחזיר File או null. */
  function record(dlg) {
    return new Promise(resolve => {
      const type = recorderType();
      if (type === null) { toast('הדפדפן הזה לא תומך בהקלטה. אפשר להעלות קובץ', true); resolve(null); return; }
      let rec, stream, chunks = [], size = 0, t0 = 0, timer, file = null;
      const time = h('div', { class: 'rec-time', 'aria-live': 'polite' }, '00:00');
      const dot = h('span', { class: 'rec-dot', 'aria-hidden': 'true' });
      const main = h('button', { type: 'button', class: 'btn primary rec-main' }, '● התחלת הקלטה');
      const player = h('audio', { controls: true, hidden: true });
      const save = h('button', { type: 'button', class: 'btn primary', hidden: true }, '✓ שמירה');
      const again = h('button', { type: 'button', class: 'btn ghost', hidden: true }, '↻ מחדש');
      const note = h('p', { class: 'hint' }, type.startsWith('audio/webm') || type.startsWith('audio/ogg')
        ? 'ההקלטה נשמרת כ-WebM. באייפון ישן ייתכן שלא תתנגן — אפשר להקליט מאייפון, או להעלות m4a / mp3.'
        : 'ההקלטה נשמרת כ-m4a — מתנגנת בכל מכשיר.');
      const box = h('div', { class: 'rec' }, h('div', { class: 'rec-head' }, dot, time), main, player, h('div', { class: 'row-btns' }, again, save), note);
      const tick = () => { const s = Math.floor((Date.now() - t0) / 1000); time.textContent = `${String(Math.floor(s / 60)).padStart(2, '0')}:${String(s % 60).padStart(2, '0')} · ${mb(size)}`; };
      const stopAll = () => { clearInterval(timer); stream?.getTracks().forEach(t => t.stop()); dot.classList.remove('on'); };

      main.onclick = async () => {
        if (rec?.state === 'recording') { rec.stop(); return; }
        try { stream = await navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true } }); }
        catch { toast('אין גישה למיקרופון. צריך לאשר בדפדפן', true); return; }
        chunks = []; size = 0;
        rec = new MediaRecorder(stream, type ? { mimeType: type } : undefined);
        rec.ondataavailable = e => {
          chunks.push(e.data); size += e.data.size;
          if (size > MAX - 1048576 && rec.state === 'recording') { rec.stop(); toast('הגעתם ל-50MB — ההקלטה נעצרה'); }
        };
        rec.onstop = () => {
          stopAll();
          const mime = (rec.mimeType || type || 'audio/webm').split(';')[0];
          const ext = mime === 'audio/mp4' ? 'm4a' : mime === 'audio/ogg' ? 'ogg' : 'webm';
          // "הקלטה 25.10 13.25" — בלי מקפים, שמתבלגנים בכיוון מימין לשמאל
          const t = new Date().toLocaleString('he-IL', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false, timeZone: 'Asia/Jerusalem' });
          file = new File(chunks, `הקלטה ${t.replace(',', '').replace(':', '.')}.${ext}`, { type: mime });
          player.src = URL.createObjectURL(file); player.hidden = false;
          main.hidden = true; save.hidden = false; again.hidden = false;
        };
        rec.start(1000);
        t0 = Date.now(); timer = setInterval(tick, 500); tick();
        dot.classList.add('on');
        main.textContent = '■ עצירה';
      };
      again.onclick = () => { file = null; player.hidden = true; save.hidden = true; again.hidden = true; main.hidden = false; main.textContent = '● התחלת הקלטה'; time.textContent = '00:00'; };
      save.onclick = () => { finish(file); };
      const body = dlg.querySelector('.sheet-body');
      const prev = [...body.childNodes];
      const title = dlg.querySelector('h2'), prevTitle = title.textContent;
      title.textContent = '🎙️ הקלטה';
      body.replaceChildren(box);
      const onClose = () => finish(null);
      dlg.addEventListener('close', onClose, { once: true });
      function finish(f) {
        dlg.removeEventListener('close', onClose);
        if (rec?.state === 'recording') { rec.onstop = null; rec.stop(); }
        stopAll();
        if (dlg.open) { body.replaceChildren(...prev); title.textContent = prevTitle; }
        resolve(f);
      }
    });
  }

  // ── בוחר קבצים ───────────────────────────────────────────
  let pickerDlg = null;
  function picker() {
    if (pickerDlg) return pickerDlg;
    pickerDlg = h('dialog', { class: 'picker', 'aria-labelledby': 'picker-title' },
      h('form', { method: 'dialog', novalidate: true },
        h('header', { class: 'sheet-head' },
          h('button', { type: 'button', class: 'icon-btn', 'aria-label': 'סגירה', onclick: () => pickerDlg.close() }, '✕'),
          h('h2', { id: 'picker-title' }), h('span', { class: 'picker-act' })),
        h('div', { class: 'sheet-body' })));
    document.body.append(pickerDlg);
    return pickerDlg;
  }

  function thumb(f) {
    return f.kind === 'image'
      ? h('img', { class: 'thumb', src: f.url, alt: '', loading: 'lazy' })
      : h('span', { class: 'use-ic', 'aria-hidden': 'true' }, KINDS[f.kind][0]);
  }

  /** בחירת קובץ מהספרייה או העלאה / הקלטה חדשה. מחזיר מערך קבצים (ריק — בוטל). */
  function pick({ kind, multiple = false }) {
    return new Promise(async resolve => {
      const dlg = picker();
      const body = dlg.querySelector('.sheet-body');
      const act = dlg.querySelector('.picker-act');
      const chosen = new Set();
      let done = false;
      const finish = list => { if (done) return; done = true; resolve(list); if (dlg.open) dlg.close(); };
      dlg.addEventListener('close', () => finish([]), { once: true });
      dlg.querySelector('h2').textContent = `${KINDS[kind][0]} בחירת ${{ image: multiple ? 'תמונות' : 'תמונה', audio: 'שמע', video: 'וידאו', doc: 'קובץ' }[kind]}`;
      const ok = h('button', { type: 'button', class: 'btn primary', hidden: !multiple, disabled: true, onclick: () => finish(all.filter(f => chosen.has(f.id))) }, 'בחירה');
      act.replaceChildren(ok);
      const input = h('input', { type: 'file', accept: ACCEPT[kind], multiple: multiple || null, hidden: true,
        onchange: async () => {
          const got = await uploadMany([...input.files], ups);
          input.value = '';
          if (!got.length) return;
          if (!multiple && got.length === 1) { finish(got); return; }
          got.forEach(f => chosen.add(f.id));
          load();
        } });
      const ups = h('div', { class: 'ups' });
      const tiles = h('div', { class: 'tiles' },
        h('button', { type: 'button', class: 'tile', onclick: () => input.click() }, h('span', { class: 'tile-ic', 'aria-hidden': 'true' }, '⬆️'), 'העלאה מהמכשיר'),
        kind === 'audio' ? h('button', { type: 'button', class: 'tile', onclick: async () => {
          const f = await record(dlg);
          if (!f) return;
          const got = await uploadMany([f], ups);
          if (got.length) finish(got);
        } }, h('span', { class: 'tile-ic', 'aria-hidden': 'true' }, '🎙️'), 'הקלטה') : null);
      const list = h('div', { class: 'pick-list' }, h('p', { class: 'empty' }, 'טוען…'));
      body.replaceChildren(input, tiles, ups, h('h3', { class: 'sec-title' }, 'מהספרייה'), list);
      if (!dlg.open) dlg.showModal();
      let all = [];
      async function load() {
        try { all = (await api('files')).files.filter(f => f.kind === kind); }
        catch (e) { list.replaceChildren(h('p', { class: 'error' }, e.message)); return; }
        ok.disabled = !chosen.size; ok.textContent = chosen.size ? `בחירה (${chosen.size})` : 'בחירה';
        if (!all.length) { list.replaceChildren(h('p', { class: 'empty' }, 'עוד אין כאן קבצים. העלו מהמכשיר.')); return; }
        const items = all.map(f => {
          const on = chosen.has(f.id);
          const click = () => {
            if (!multiple) { finish([f]); return; }
            chosen.has(f.id) ? chosen.delete(f.id) : chosen.add(f.id);
            load();
          };
          return kind === 'image'
            ? h('button', { type: 'button', class: 'pick-img' + (on ? ' on' : ''), 'aria-pressed': multiple ? String(on) : null, title: f.name, onclick: click },
              thumb(f), multiple ? h('span', { class: 'pick-check', 'aria-hidden': 'true' }, on ? '✓' : '') : null)
            : h('button', { type: 'button', class: 'use-row' + (on ? ' on' : ''), onclick: click }, thumb(f),
              h('span', { class: 'use-tx' }, h('b', { class: 'one' }, base(f.name)), h('small', {}, meta(f))),
              h('span', { class: 'use-go', 'aria-hidden': 'true' }, '‹'));
        });
        list.replaceChildren(kind === 'image' ? h('div', { class: 'pick-grid' }, items) : h('div', { class: 'list' }, items));
      }
      load();
    });
  }

  // ── מסך הקבצים ───────────────────────────────────────────
  async function view(host, isOwner) {
    host.replaceChildren(h('p', { class: 'empty' }, 'טוען…'));
    let d, filter = sessionStorage.getItem('kol-files-kind') || '';
    try { d = await api('files'); } catch (e) { host.replaceChildren(h('p', { class: 'error' }, e.message)); return; }
    const input = h('input', { type: 'file', multiple: true, hidden: true, accept: Object.values(ACCEPT).join(','),
      onchange: async () => { await uploadMany([...input.files], ups); input.value = ''; view(host, isOwner); } });
    const ups = h('div', { class: 'ups' });
    const pct = Math.min(100, Math.round(d.used / d.cap * 100));
    const summary = h('div', { class: 'summary' },
      h('div', { class: 'line' }, h('span', { class: 'muted' }, 'בשימוש'), h('b', {}, mb(d.used)), h('span', { class: 'muted' }, `מתוך ${mb(d.cap)}`),
        h('span', { class: 'pct', style: pct > 85 ? 'color:var(--danger)' : null }, pct + '%')),
      h('span', { class: 'track', role: 'progressbar', 'aria-valuemin': 0, 'aria-valuemax': 100, 'aria-valuenow': pct },
        h('span', { style: `inline-size:${pct}%;${pct > 85 ? 'background:var(--danger)' : ''}` })),
      h('div', { class: 'muted', style: 'font-size:.8rem' }, `עד ${mb(d.max)} לקובץ.`,
        isOwner ? h('button', { type: 'button', class: 'btn sm ghost', style: 'margin-inline-start:8px', onclick: () => capSheet(d, host) }, '✏️ תקרה') : null));
    const counts = Object.fromEntries(Object.keys(KINDS).map(k => [k, d.files.filter(f => f.kind === k).length]));
    const tabs = h('div', { class: 'seg-tabs', role: 'tablist' },
      [['', 'הכול', d.files.length], ...Object.entries(KINDS).map(([k, [ic]]) => [k, ic, counts[k]])].map(([k, l, n]) =>
        h('button', { type: 'button', role: 'tab', 'aria-selected': String(filter === k), 'aria-label': k ? KINDS[k][1] : 'הכול',
          onclick: () => { sessionStorage.setItem('kol-files-kind', k); view(host, isOwner); } }, l, ' ', h('small', {}, n ? String(n) : ''))));
    const rows = d.files.filter(f => !filter || f.kind === filter);
    host.replaceChildren(
      h('div', { class: 'toolbar' }, h('p', { class: 'hint', style: 'flex:1;margin:0' }, 'כל הקבצים של העמוד. קובץ שבשימוש לא נמחק.'),
        h('button', { type: 'button', class: 'btn primary', onclick: () => input.click() }, h('span', { 'aria-hidden': 'true' }, '⬆️'), ' העלאה')),
      input, summary, ups, tabs,
      rows.length ? h('ul', { class: 'list' }, rows.map(f => h('li', {}, h('button', { type: 'button', class: 'use-row', onclick: () => fileSheet(f, host, isOwner) },
        thumb(f), h('span', { class: 'use-tx' }, h('b', { class: 'one' }, base(f.name)), h('small', {}, meta(f))),
        f.in_use ? h('span', { class: 'chip ok' }, 'בשימוש') : null, h('span', { class: 'use-go', 'aria-hidden': 'true' }, '‹')))))
        : h('p', { class: 'empty' }, filter ? 'אין קבצים מהסוג הזה.' : 'עוד אין קבצים. "⬆️ העלאה" כדי להתחיל.'));
  }

  function fileSheet(f, host, isOwner) {
    const preview = f.kind === 'image' ? h('img', { class: 'file-preview', src: f.url, alt: f.name })
      : f.kind === 'audio' ? h('audio', { controls: true, src: f.url, preload: 'metadata', style: 'width:100%' })
        : f.kind === 'video' ? h('video', { controls: true, src: f.url, preload: 'metadata', playsinline: true, class: 'file-preview' })
          : h('div', { class: 'empty' }, KINDS.doc[0], ' ', f.name);
    const dlg = sheet(f.name, preview,
      h('div', { class: 'row-btns' },
        h('a', { class: 'btn ghost', href: `api.php?action=file&id=${f.id}&download=1`, download: f.name }, '⬇️ הורדה'),
        h('button', { type: 'button', class: 'btn ghost danger', disabled: f.in_use, title: f.in_use ? 'בשימוש בעמוד' : null, onclick: async () => {
          if (!confirm(`להעביר את "${f.name}" לסל המחזור? (שחזור — ב-↩️ שחזור)`)) return;
          try { await api('file_delete', { id: f.id }); dlg.close(); toast('הועבר לסל'); view(host, isOwner); } catch (e) { toast(e.message, true); }
        } }, '🗑️ מחיקה')),
      h('dl', { class: 'cells' },
        h('div', {}, h('dt', {}, 'גודל'), h('dd', {}, mb(f.size))),
        f.w ? h('div', {}, h('dt', {}, 'מידות'), h('dd', { dir: 'ltr' }, `${f.w}×${f.h}`)) : null,
        h('div', {}, h('dt', {}, 'הועלה'), h('dd', {}, `${f.by} · ${date(f.at)}`)),
        h('div', {}, h('dt', {}, 'מצב'), h('dd', {}, f.in_use ? 'בשימוש בעמוד' : 'לא בשימוש'))));
  }

  function capSheet(d, host) {
    const inp = h('input', { type: 'number', min: 0.5, max: 200, step: 0.5, value: +(d.cap / 1073741824).toFixed(1), dir: 'ltr', inputmode: 'decimal' });
    const dlg = sheet('תקרת הקבצים', h('p', { class: 'hint' }, 'כמה מקום מותר לכל הקבצים של הכלי יחד. מעליה — העלאה נחסמת.'),
      h('label', { class: 'f' }, h('span', {}, 'תקרה (GB)'), inp),
      h('button', { type: 'button', class: 'btn primary', onclick: async () => {
        try { await api('disk_cap', { gb: +inp.value }); dlg.close(); toast('נשמר'); view(host, true); } catch (e) { toast(e.message, true); }
      } }, 'שמירה'));
  }

  return { pick, view, upload, mb, progressRow };
})();
