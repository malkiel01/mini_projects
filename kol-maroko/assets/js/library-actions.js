// קול מרוקו — הפעולות על הקלטה בספרייה (פרוסה A3 · docs/spec-v2/slices/A3-audio-actions.md):
// תפריט שורה (כל הפעולות — גם בטלפון), גרסאות, שינוי שם בשורה, חיבור / ניתוק, מחיקה לסל ושחזור, הורדה (אחת — הקובץ
// המקורי; רבות — ZIP שנבנה בחלקים), הוספה של אחד או רבים (גם בגרירה) עם מסך אישור לפני כל חיבור, כפולות, וגרסאות ישנות.
// הקלטה שעדיין "באתר": הפעולות שדורשות קובץ מושבתות עם הסבר, ו"החלף" נשאר. השרת בודק הכול שוב (lib/rec_actions.php).
// N2b: כל פעולה — על מקום (מילה + שדה): "חבר ל…" עם בחירת שדה, "העבר לשדה…", "חבר הקלטה מהספרייה" משורה חסרה, השדה במסך
// ההעלאה ("להוסיף ל:"), וגרירה — קובץ על שורה (החלפה / חיבור), שורה על שורה חסרה (חיבור / העברה, באישור). Esc מבטל גרירה.
'use strict';

const LibActions = (() => {
  const L = () => Library.ctx;
  const mb = b => Media.mb(b);
  const SITE_ONLY = 'תיפתח אחרי ההבאה לכלי';
  const AUDIO_EXT = /\.(mp3|m4a|aac|ogg|oga|opus|wav|webm|weba|flac)$/i;

  /** שאלה בגיליון. check — תיבת "אני מבין" שבלעדיה אי אפשר לאשר. מחזיר true / false. */
  function ask(title, body, okLabel, { danger = false, check = '' } = {}) {
    return new Promise(resolve => {
      let done = false;
      const end = v => { if (done) return; done = true; resolve(v); const d = $('#sheet'); if (d.open) d.close(); };
      const okBtn = h('button', { type: 'button', class: 'btn ' + (danger ? 'danger' : 'primary'), disabled: !!check, onclick: () => end(true) }, okLabel);
      const box = check ? h('label', { class: 'check' }, h('input', { type: 'checkbox', onchange: e => { okBtn.disabled = !e.target.checked; } }), ' ', check) : null;
      const dlg = sheet(title, ...[body].flat(), box,
        h('div', { class: 'row-btns' }, okBtn, h('button', { type: 'button', class: 'btn ghost', onclick: () => end(false) }, 'ביטול')));
      dlg.addEventListener('close', () => end(false), { once: true });
    });
  }

  const linked = r => !r.loose && r.state !== 'none';
  const hasFile = r => r.state === 'tool' && r.file && !r.missing;
  const fshort = k => L().fieldOf(k)?.short || k;
  const label = r => r.loose ? (r.name || r.word) : `#${L().widOf(r)} ${r.word}${L().multi() && r.field ? ' · ' + fshort(r.field) : ''}`;
  /** "ההקלטה בצרפתית של 'בסטילה'" — המקום במילים. */
  const slotText = r => `${L().multi() && r.field ? L().fieldOf(r.field)?.label || r.field_label : 'ההקלטה'} של "${r.word}"`;

  // ── תפריט השורה ─────────────────────────────────────────
  function menu(r) {
    const file = hasFile(r);
    const b = (icon, text, fn, { need = true, cls = 'ghost' } = {}) => h('button', { type: 'button', class: `btn ${cls} lib-act`, disabled: need && !file,
      title: need && !file ? SITE_ONLY : null, onclick: () => fn(r) }, icon + ' ' + text, need && !file ? h('small', {}, ' — ' + SITE_ONLY) : null);
    const moveOk = L().multi() && linked(r) && file;
    sheet(r.loose ? r.word : `#${L().widOf(r)} · ${r.word}${L().multi() && r.field ? ' · ' + fshort(r.field) : ''}`,
      r.state === 'site' ? h('p', { class: 'hint' }, 'ההקלטה עדיין באתר (רק כתובת). שינוי שם, גרסאות, חיבור, ניתוק, הורדה ומחיקה ייפתחו אחרי ההבאה לכלי. "החלף" זמין כבר עכשיו — ההקלטה שבאתר לא נמחקת.') : null,
      h('div', { class: 'lib-acts' },
        b('▶', 'השמעה', x => { $('#sheet').close(); L().play(x.id); }, { need: false }),
        b('🔁', 'החלף / הקלט', x => L().replace(x.id), { need: false }),
        b('🕘', `גרסאות${r.versions ? ` (${r.versions})` : ''}`, versions),
        b('✏️', 'שינוי שם', rename),
        b('🔗', r.loose ? (L().multi() ? 'חבר ל…' : 'חבר למילה…') : 'העבר למילה אחרת…', connect),
        moveOk ? b('↔️', 'העבר לשדה…', moveField) : null,
        r.loose ? null : b('⛓️', 'נתק מהמילה', x => disconnect([x])),
        b('⬇️', 'הורדה (הקובץ המקורי)', x => download([x])),
        b('🗑️', 'מחיקה לסל', x => remove([x]), { cls: 'ghost danger' }),
        r.loose ? null : b('📚', 'פתח את המילה', x => { $('#sheet').close(); Words.open(L().widOf(x)); }, { need: false })));
  }

  // ── שינוי שם — בשורה עצמה ───────────────────────────────
  function rename(r) {
    const dlg = $('#sheet'); if (dlg.open) dlg.close();
    const t = L().titleOf(r.id);
    const save = async v => {
      v = v.trim();
      if (!v || v === (r.name || r.word)) { L().redraw(r.id); return; }
      try { await api('rec_rename', { rec_id: r.rec, name: v }); toast('השם נשמר'); await L().reload(true); }
      catch (e) { toast(e.message, true); L().redraw(r.id); }
    };
    if (!t) {                                  // השורה לא מוצגת (מסוננת) — בגיליון
      const inp = h('input', { value: r.name || r.word, maxlength: 200, 'aria-label': 'השם לתצוגה' });
      ask('✏️ שינוי שם', [h('label', { class: 'f' }, h('span', {}, 'השם לתצוגה (הנתיב והמזהה אינם משתנים)'), inp)], 'שמירה').then(ok => ok && save(inp.value));
      return;
    }
    let done = false;
    const inp = h('input', { class: 'lib-rename', value: r.name || r.word, maxlength: 200, 'aria-label': `שם חדש: ${r.word}`,
      onkeydown: e => { if (e.key === 'Enter') { e.preventDefault(); done = true; save(inp.value); } else if (e.key === 'Escape') { done = true; L().redraw(r.id); } },
      onblur: () => { if (!done) { done = true; save(inp.value); } },
      onclick: e => e.stopPropagation() });
    t.closest('.lib-main').replaceWith(h('div', { class: 'lib-main lib-editing' }, inp, h('small', { class: 'hint' }, 'Enter — שמירה · Esc — ביטול. הנתיב והמזהה אינם משתנים')));
    inp.focus(); inp.select();
  }

  // ── גרסאות ──────────────────────────────────────────────
  async function versions(r) {
    let v;
    try { v = await api('rec_versions', undefined, `&rec_id=${r.rec}`); } catch (e) { toast(e.message, true); return; }
    const player = src => src ? h('audio', { controls: true, preload: 'none', src, class: 'lib-vplay' }) : h('small', { class: 'lib-bad' }, 'לא ניתן להשמיע');
    const cur = r.file;
    sheet(`🕘 גרסאות · ${label(r)}`,
      h('p', { class: 'hint' }, 'החלפה אינה מוחקת: כל הקלטה קודמת נשמרת כאן. "חזור לגרסה הזו" שומר את הנוכחית כגרסה — אפשר לחזור הלוך ושוב. הנתיב והמזהה אינם משתנים.'),
      h('ul', { class: 'list lib-versions' },
        cur ? h('li', { class: 'lib-ver on' }, h('div', {}, h('b', {}, 'הנוכחית'), ' · ', cur.name, ' · ', mb(cur.size)), player(L().safeSrc(r.src))) : null,
        v.versions.map(x => h('li', { class: 'lib-ver' },
          h('div', {}, h('b', {}, x.site ? 'ההקלטה שבאתר' : x.file ? x.file.name : 'קובץ שנמחק'),
            x.file ? ' · ' + mb(x.file.size) : '', ` · הוחלפה ${L().date(x.at)}${x.by ? ' · ' + x.by : ''}`),
          x.gone ? h('small', { class: 'lib-bad' }, 'הקובץ של הגרסה הזו כבר נמחק') : player(x.site ? L().safeSrc(x.source) : x.file.url),
          x.gone ? null : h('button', { type: 'button', class: 'btn ghost sm', onclick: async () => {
            try { await api('rec_revert', { rec_id: r.rec, version_id: x.id }); $('#sheet').close(); L().stoppedIf(r.id); L().dur.delete(r.id); await L().reload(true); toast('חזרנו לגרסה — הנוכחית נשמרה כגרסה'); }
            catch (e) { toast(e.message, true); }
          } }, '↩️ חזור לגרסה הזו')))),
      v.versions.length ? null : h('p', { class: 'empty' }, 'אין גרסאות קודמות — ההקלטה לא הוחלפה.'));
  }

  // ── חיבור / ניתוק ───────────────────────────────────────
  /** הבקשה לחיבור, עם "החלף" באישור כשהמקום תפוס. מחזיר true אם חוברה. */
  async function link(recId, wid, word, field, { act = 'rec_connect', body = null } = {}) {
    try {
      await api(act, body || { rec_id: recId, word_id: wid, field });
      return true;
    } catch (e) {
      if (e.status === 409 && e.data?.conflict) {
        const c = e.data.conflict;
        const what = L().multi() ? `${c.field_label || 'הקלטה'}` : 'הקלטה';
        if (await ask(`למילה "${c.word}" כבר יש ${what}`, h('p', {}, `ההקלטה הקיימת («${c.name}») תישאר בספרייה תחת "לא מחוברות" — היא לא נמחקת.`), 'החלף (הנוכחית תהפוך ללא מחוברת)')) {
          return link(recId, wid, word, field, { act, body: { ...(body || { rec_id: recId, word_id: wid, field }), replace: true } });
        }
        return false;
      }
      toast(e.message, true);
      return false;
    }
  }

  /** בורר שדה (רק כשיש יותר משדה שמע אחד). */
  function fieldSelect(value, on, label = 'שדה') {
    if (!L().multi()) return null;
    return h('label', { class: 'f lib-fieldsel' }, h('span', {}, label),
      h('select', { 'aria-label': label, onchange: e => on(e.target.value) },
        L().D.fields.map(f => h('option', { value: f.key, selected: f.key === value }, f.label + (f.published ? '' : ' (פנימי)')))));
  }

  /** "חבר ל…": חיפוש מילה ובחירת שדה (ברירת מחדל — השדה שבסינון, אחרת הראשי). */
  function connect(r) {
    let field = L().fieldF || (r.loose ? '' : r.field) || L().primaryKey();
    if (!r.loose && r.field && !L().fieldF) field = r.field;
    const list = h('ul', { class: 'list lib-pickword' });
    let qv = '';
    const draw = () => {
      const s = qv.trim().replace(/^#/, ''), n = s.toLowerCase();
      const words = L().D.rows.filter(w => (w.field ?? L().primaryKey()) === field);
      const hits = words.filter(w => !(L().widOf(w) === L().widOf(r) && w.field === r.field && !r.loose) && (!s || String(L().widOf(w)) === s || w.word.toLowerCase().includes(n))).slice(0, 40);
      list.replaceChildren(...(hits.length ? hits.map(w => h('li', {}, h('button', { type: 'button', class: 'use-row', onclick: () => go(w) },
        h('span', { class: 'use-tx' }, h('b', { class: 'one' }, `#${L().widOf(w)} ${w.word}`), h('small', { class: 'one' }, w.state === 'none' ? 'בלי הקלטה' : 'יש לה הקלטה — תתבקשו לאשר')),
        h('span', { class: 'chip' + (w.state === 'none' ? ' warn' : '') }, w.state === 'none' ? 'בלי הקלטה' : 'יש הקלטה')))) : [h('p', { class: 'empty' }, 'לא נמצאה מילה')]));
    };
    const go = async w => {
      if (!await link(r.rec, L().widOf(w), w.word, field)) return;
      $('#sheet').close();
      await L().reload(true);
      toast(`חוברה ל-#${L().widOf(w)} ${w.word}${L().multi() ? ' · ' + fshort(field) : ''}`);
    };
    const inp = h('input', { type: 'search', placeholder: 'חיפוש מילה או מזהה…', 'aria-label': 'חיפוש מילה', oninput: e => { qv = e.target.value; draw(); } });
    sheet(`🔗 חיבור${L().multi() ? '' : ' למילה'} · ${r.name || r.word}`,
      r.loose ? null : h('p', { class: 'hint' }, L().multi() ? `${label(r)} — המקום הזה יישאר "בלי הקלטה".` : `המילה #${r.id} ${r.word} תישאר "בלי הקלטה".`),
      fieldSelect(field, v => { field = v; draw(); }, 'לשדה'), inp, list);
    draw();
    inp.focus();
  }

  /** "העבר לשדה…": אותה מילה, שדה אחר — שם, משך וגרסאות נשמרים. */
  function moveField(r) {
    const others = L().D.fields.filter(f => f.key !== r.field);
    sheet(`↔️ העבר לשדה · ${label(r)}`,
      h('p', { class: 'hint' }, 'אותה מילה, שדה אחר. השם, המשך והגרסאות עוברים עם ההקלטה.'),
      h('ul', { class: 'list lib-pickword' }, others.map(f => {
        const t = L().slotRow(L().widOf(r), f.key);
        return h('li', {}, h('button', { type: 'button', class: 'use-row', onclick: async () => {
          if (!await link(r.rec, L().widOf(r), r.word, f.key, { act: 'rec_move', body: { rec_id: r.rec, field: f.key } })) return;
          $('#sheet').close(); L().stoppedIf(r.id); await L().reload(true); toast(`הועברה ל${f.label}`);
        } }, h('span', { class: 'use-tx' }, h('b', { class: 'one' }, f.label), h('small', { class: 'one' }, t && t.state !== 'none' ? 'יש כבר הקלטה — תתבקשו לאשר' : 'ריק')),
          h('span', { class: 'chip' + (t && t.state !== 'none' ? '' : ' warn') }, t && t.state !== 'none' ? 'תפוס' : 'ריק')));
      })));
  }

  /** משורה "חסרה": "🔗 חבר הקלטה מהספרייה" — בחירת הקלטה לא מחוברת. */
  function pickFor(r) {
    const loose = (L().D.loose || []).filter(x => x.state === 'tool' && !x.missing);
    const list = h('ul', { class: 'list lib-pickword' });
    const draw = q => {
      const n = q.trim().toLowerCase();
      const hits = loose.filter(x => !n || (x.name || x.word).toLowerCase().includes(n)).slice(0, 60);
      list.replaceChildren(...(hits.length ? hits.map(x => h('li', {}, h('button', { type: 'button', class: 'use-row', onclick: async () => {
        if (!await link(x.rec, L().widOf(r), r.word, r.field)) return;
        $('#sheet').close(); await L().reload(true); toast(`חוברה ל${slotText(r)}`);
      } }, h('span', { class: 'use-tx' }, h('b', { class: 'one', dir: 'auto' }, x.name || x.word),
        h('small', { class: 'one' }, [x.dropped ? `משדה שנמחק: ${x.dropped}` : '', x.file ? mb(x.file.size) : ''].filter(Boolean).join(' · '))),
        x.file ? h('audio', { controls: true, preload: 'none', src: x.file && L().safeSrc(x.src), class: 'lib-vplay', onclick: e => e.stopPropagation() }) : null))) : [h('p', { class: 'empty' }, 'אין הקלטות לא מחוברות')]));
    };
    const inp = h('input', { type: 'search', placeholder: 'חיפוש הקלטה…', 'aria-label': 'חיפוש הקלטה', oninput: e => draw(e.target.value) });
    sheet(`🔗 חבר הקלטה · ${slotText(r)}`, inp, list);
    draw('');
    inp.focus();
  }

  // ── גרירה (N2b): קובץ על שורה — החלפה / חיבור למקום; שורה על שורה חסרה — חיבור / העברה ──
  let dragRec = null;                          // השורה שנגררת (גרירה מתוך הרשימה)
  let tipEl = null;                            // נוצר בגרירה הראשונה (h עוד לא קיים כשהקובץ נטען)
  const tip = () => tipEl ||= h('div', { class: 'lib-dragtip', role: 'status', 'aria-live': 'polite', hidden: true });
  const clearDrag = () => { document.querySelectorAll('.lib-target').forEach(x => x.classList.remove('lib-target')); if (tipEl) tipEl.hidden = true; };
  document.addEventListener('keydown', e => { if (e.key === 'Escape' && (dragRec || (tipEl && !tipEl.hidden))) { dragRec = null; clearDrag(); } });
  function dragRow(li, r) {
    const isFiles = e => [...(e.dataTransfer?.types || [])].includes('Files');
    if (r.rec && hasFile(r)) {
      li.draggable = true;
      li.addEventListener('dragstart', e => { dragRec = r; e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('application/x-kol-rec', String(r.rec)); li.classList.add('lib-dragging'); });
      li.addEventListener('dragend', () => { dragRec = null; li.classList.remove('lib-dragging'); clearDrag(); });
    }
    const what = e => {
      if (isFiles(e)) return r.loose ? (r.rec ? `↻ החלף את «${r.name || r.word}»` : '') : r.state === 'none' ? `＋ חבר ל${slotText(r)}` : `↻ החלף ${slotText(r)}`;
      if (dragRec && dragRec !== r && r.state === 'none' && !r.loose) return dragRec.loose ? `🔗 חבר ל${slotText(r)}` : `↔️ העבר ל${slotText(r)}`;
      return '';
    };
    li.addEventListener('dragover', e => {
      const t = what(e);
      if (!t) return;
      e.preventDefault();
      e.dataTransfer.dropEffect = isFiles(e) ? 'copy' : 'move';
      if (!li.classList.contains('lib-target')) { clearDrag(); li.classList.add('lib-target'); }
      const el = tip();
      el.textContent = t + ' · Esc — ביטול';
      if (!el.isConnected) document.body.append(el);
      el.hidden = false;
    });
    li.addEventListener('dragleave', e => { if (!li.contains(e.relatedTarget)) { li.classList.remove('lib-target'); if (tipEl) tipEl.hidden = true; } });
    li.addEventListener('drop', async e => {
      const t = what(e);
      if (!t) return;
      e.preventDefault();
      e.kolRow = true;                         // dropZone של הרשימה לא יוסיף את הקובץ כהוספה מרובה
      clearDrag();
      const src = dragRec; dragRec = null;
      try {
        if (isFiles(e)) {
          const file = [...e.dataTransfer.files][0];
          if (!file) return;
          if (!AUDIO_EXT.test(file.name) && !(file.type || '').startsWith('audio/')) { toast(`"${file.name}" אינו קובץ שמע`, true); return; }
          if (e.dataTransfer.files.length > 1) toast('על שורה — קובץ אחד. כמה קבצים — גררו על הרשימה (הוספה מרובה)');
          const pr = Media.progressRow();
          const box = sheet(`⬆️ ${t.replace(/^\S+\s/, '')}`, pr.el);
          const f = await Media.upload(file, (got, tot, left, note) => pr.set(file.name, got, tot, left, note));
          await api('rec_replace', r.rec ? { rec_id: r.rec, file_id: f.id } : { word_id: L().widOf(r), field: r.field, file_id: f.id });
          if (box.open) box.close();
          L().stoppedIf(r.id); L().dur.delete(r.id);
          await L().reload(true);
          toast(r.state === 'none' ? `חוברה ל${slotText(r)}` : 'ההקלטה הוחלפה — הקודמת נשמרה כגרסה');
        } else if (src) {
          if (!src.loose && !await ask(`להעביר מ${slotText(src)} ל${slotText(r)}?`, h('p', {}, `${label(src)} יישאר בלי הקלטה. השם, המשך והגרסאות עוברים עם ההקלטה.`), 'העבר')) return;
          if (!await link(src.rec, L().widOf(r), r.word, r.field)) return;
          L().stoppedIf(src.id);
          await L().reload(true);
          toast(`${src.loose ? 'חוברה' : 'הועברה'} ל${slotText(r)}`);
        }
      } catch (err) { toast(err.message, true); const d = $('#sheet'); if (d.open) d.close(); }
    });
  }

  async function disconnect(rows) {
    const ok = rows.filter(r => linked(r) && hasFile(r));
    const skip = rows.length - ok.length;
    if (!ok.length) { toast(rows.some(r => r.state === 'site') ? 'הקלטה שעדיין באתר — ' + SITE_ONLY : 'אין בבחירה הקלטה שמחוברת למילה', true); return; }
    if (!await ask(ok.length === 1 ? `לנתק את ההקלטה מ-${label(ok[0])}?` : `לנתק ${ok.length} הקלטות מהמילים?`,
      [h('p', {}, 'המילים יישארו, מסומנות "בלי הקלטה". ההקלטות יישארו בספרייה תחת "לא מחוברות" — אפשר לחבר אותן שוב.'),
        skip ? h('p', { class: 'hint' }, `${skip} מהנבחרות אינן מחוברות למילה או עדיין באתר — לא ינותקו.`) : null], 'נתק')) return;
    try { const res = await api('rec_disconnect', { ids: ok.map(r => r.rec) }); L().clearSel(); await L().reload(true); toast(`נותקו ${res.done}`); }
    catch (e) { toast(e.message, true); }
  }

  // ── מחיקה לסל, הסל ──────────────────────────────────────
  async function remove(rows) {
    const ok = rows.filter(hasFile);
    const site = rows.length - ok.length;
    if (!ok.length) { toast('הקלטה שעדיין באתר — ' + SITE_ONLY, true); return; }
    const used = ok.filter(linked);
    if (!await ask(ok.length === 1 ? `למחוק את «${ok[0].name || ok[0].word}»?` : `למחוק ${ok.length} הקלטות?`, [
      used.length ? h('p', { class: 'warn-box' }, '⚠️ ', used.length === 1 ? `ההקלטה משמשת את המילה ${label(used[0])}` : `${used.length} מההקלטות משמשות מילים: ${used.slice(0, 8).map(label).join(', ')}${used.length > 8 ? ` ועוד ${used.length - 8}` : ''}`,
        ' — ', used.length === 1 ? 'המילה תישאר "בלי הקלטה".' : 'המילים יישארו "בלי הקלטה".') : null,
      h('p', {}, 'ההקלטות עוברות לסל. "שחזור" מהסל מחזיר גם את החיבור למילה. ההקלטה שבאתר אינה נמחקת ואינה משתנה.'),
      site ? h('p', { class: 'hint' }, `${site} מהנבחרות עדיין באתר — לא יימחקו.`) : null], 'מחיקה לסל', { danger: true })) return;
    try {
      ok.forEach(r => L().stoppedIf(r.id));
      const res = await api('rec_delete', { ids: ok.map(r => r.rec) });
      L().clearSel(); await L().reload(true);
      toast(`${res.done} הועברו לסל — אפשר לשחזר`);
    } catch (e) { toast(e.message, true); }
  }

  function trashView(el) {
    const T = L().D.trash || [];
    el.replaceChildren(
      h('p', { class: 'hint' }, 'הקלטות שנמחקו. "שחזור" מחזיר את ההקלטה, ואם המילה עדיין בלי הקלטה — גם את החיבור אליה.', L().isOwner ? ' מחיקה סופית — רק כאן, באישור מפורש.' : ' מחיקה סופית — לבעל העמוד.'),
      T.length ? h('ul', { class: 'list lib-trash' }, T.map(t => h('li', { class: 'lib-ver' },
        h('div', {}, h('b', {}, t.name), t.word_id ? ` · מהמילה #${t.word_id}${t.word ? ' ' + t.word : ' (נמחקה)'}${t.primary === false ? ' · ' + t.field_label : ''}` : ' · לא הייתה מחוברת',
          h('br'), h('small', { class: 'hint' }, `נמחקה ${L().date(t.at)}${t.by ? ' · ' + t.by : ''}${t.file ? ' · ' + mb(t.file.size) : ''}`)),
        t.file ? h('audio', { controls: true, preload: 'none', src: t.file.url, class: 'lib-vplay' }) : null,
        h('div', { class: 'row-btns' },
          h('button', { type: 'button', class: 'btn ghost sm', onclick: async () => {
            try { const res = await api('rec_restore', { id: t.rec }); await L().reload(true); toast(res.note || (res.word_id ? `שוחזרה — וחוברה שוב ל-#${res.word_id}${t.primary === false ? ' · ' + t.field_label : ''}` : 'שוחזרה')); }
            catch (e) { toast(e.message, true); }
          } }, '♻️ שחזור'),
          L().isOwner ? h('button', { type: 'button', class: 'btn ghost sm danger', onclick: async () => {
            if (!await ask(`למחוק לצמיתות את «${t.name}»?`, h('p', {}, 'הקובץ והגרסאות הקודמות שלו יימחקו מהשרת (אלא אם משהו אחר עדיין משתמש בהם). אי אפשר לבטל. ההקלטה שבאתר אינה נמחקת.'),
              'מחיקה סופית', { danger: true, check: 'אני מבין שזה סופי' })) return;
            try { const res = await api('rec_purge', { id: t.rec, confirm: true }); await L().reload(true); toast(`נמחקה לצמיתות${res.freed ? ' · התפנו ' + mb(res.freed) : ''}`); }
            catch (e) { toast(e.message, true); }
          } }, 'מחיקה סופית') : null)))) : h('p', { class: 'empty' }, 'הסל ריק.'));
  }

  // ── הורדה ───────────────────────────────────────────────
  /** אחת — הקובץ המקורי כמו שהועלה. רבות / null (כולן) — ZIP שנבנה בחלקים, עם התקדמות. */
  async function download(rows) {
    if (rows && rows.length === 1) {
      const r = rows[0];
      if (!hasFile(r)) { toast('הקלטה שעדיין באתר — ' + SITE_ONLY, true); return; }
      const a = h('a', { href: `api.php?action=file&id=${r.file.id}&download=1`, download: r.file.name, hidden: true });
      document.body.append(a); a.click(); a.remove();
      const d = $('#sheet'); if (d.open) d.close();
      return;
    }
    const ids = rows ? rows.filter(hasFile).map(r => r.rec) : null;
    if (rows && !ids.length) { toast('אין בבחירה הקלטות "בכלי" — רק הן יורדות', true); return; }
    const bar = h('span', { style: 'inline-size:0%' });
    const txt = h('p', { class: 'hint', 'aria-live': 'polite' }, 'מכין את רשימת הקבצים…');
    const out = h('div', {});
    let stop = false;
    const dlg = sheet('⬇️ הורדה כ-ZIP', txt, h('span', { class: 'track', role: 'progressbar', 'aria-label': 'התקדמות' }, bar), out,
      h('p', { class: 'hint' }, 'בארכיון: כל קובץ בדיוק כמו שהועלה (בלי המרה), ו-recordings.csv — מזהה, מילה, שם הקובץ, משך.'));
    dlg.addEventListener('close', () => { stop = true; }, { once: true });
    try {
      let z = await api('rec_zip_start', ids ? { ids } : { all: true });
      while (!z.done && !stop) {
        txt.textContent = `בונה… ${L().num(z.i)} מתוך ${L().num(z.count)} קבצים · ${mb(z.written)} מתוך ${mb(z.bytes)}`;
        bar.style.inlineSize = Math.floor(z.written / Math.max(1, z.bytes) * 100) + '%';
        z = await api('rec_zip_step', { token: z.token });
        if (z.busy) await new Promise(res => setTimeout(res, 700));
      }
      if (stop) return;
      bar.style.inlineSize = '100%';
      txt.textContent = `מוכן: ${L().num(z.count)} קבצים · ${mb(z.written)}`;
      const a = h('a', { class: 'btn primary', href: `api.php?action=rec_zip_get&token=${z.token}`, download: '' }, '⬇️ שמירת הקובץ');
      out.replaceChildren(a);                  // לחיצה של המשתמש: הורדה אוטומטית אחרי המתנה נחסמת בחלק מהדפדפנים
      a.focus();
    } catch (e) { txt.textContent = e.message; txt.className = 'error'; }
  }

  // ── כפולות ──────────────────────────────────────────────
  async function keepOne(r) {
    const same = L().D.all.filter(x => x.file?.sha === r.file.sha && x.id !== r.id && !x.missing);
    const words = same.filter(linked), loose = same.filter(x => x.loose);
    if (!await ask(`להשאיר את «${r.name || r.word}»?`, [
      words.length ? h('p', {}, `${words.map(label).join(', ')} — ${words.length === 1 ? 'תעבור' : 'יעברו'} להשתמש בעותק הזה. התוכן זהה בבייט, ולכן שום דבר לא נשמע אחרת. העותק הקודם נשמר כגרסה.`) : null,
      loose.length ? h('p', {}, `${loose.length} ${loose.length === 1 ? 'עותק לא מחובר עובר' : 'עותקים לא מחוברים עוברים'} לסל.`) : null], 'השאר את זו')) return;
    try { const res = await api('rec_dedupe', { keep: r.rec }); await L().reload(true); toast(`נשארה אחת · ${res.moved} מילים עברו אליה · ${res.trashed} לסל`); }
    catch (e) { toast(e.message, true); }
  }

  // ── גרסאות: כמה מקום, וניקוי ────────────────────────────
  function versionsLine() {
    const v = L().D.versions;
    if (!v?.count) return null;
    return h('p', { class: 'hint lib-vline', style: 'margin:0' }, `🕘 גרסאות קודמות: ${L().num(v.count)} · ${mb(v.bytes)} מתוך תקרת הדיסק`,
      L().isOwner ? h('button', { type: 'button', class: 'btn ghost sm', onclick: clean }, 'נקה גרסאות ישנות מ-…') : null);
  }
  async function clean() {
    const d = new Date(Date.now() - 30 * 864e5).toISOString().slice(0, 10);
    const inp = h('input', { type: 'date', value: d, max: new Date(Date.now() + 864e5).toISOString().slice(0, 10), 'aria-label': 'מתאריך' });
    if (!await ask('🧹 ניקוי גרסאות ישנות', [h('label', { class: 'f' }, h('span', {}, 'גרסאות שהוחלפו לפני'), inp),
      h('p', {}, 'הקבצים של הגרסאות האלה יימחקו מהשרת (אלא אם משהו אחר עדיין משתמש בהם), והמקום יתפנה. ההקלטות הנוכחיות אינן נפגעות. אי אפשר לבטל.')],
      'נקה', { danger: true, check: 'אני מבין שזה סופי' })) return;
    try { const res = await api('rec_versions_clean', { before: inp.value, confirm: true }); await L().reload(true); toast(`נוקו ${res.removed} · התפנו ${mb(res.freed)}`); }
    catch (e) { toast(e.message, true); }
  }

  // ── הוספה: אחת או רבות, גם בגרירה; התאמה לפי שם הקובץ; מסך אישור ──
  function addButton() {
    const input = h('input', { type: 'file', multiple: true, hidden: true, accept: 'audio/*,.mp3,.m4a,.aac,.ogg,.opus,.wav,.webm,.flac', onchange: () => { const f = [...input.files]; input.value = ''; if (f.length) add(f); } });
    return h('span', {}, input, h('button', { type: 'button', class: 'btn primary lib-add', onclick: () => input.click() }, '➕ הוספת הקלטות'));
  }
  function dropZone(box) {
    let n = 0;
    const has = e => [...(e.dataTransfer?.types || [])].includes('Files');
    box.addEventListener('dragenter', e => { if (!has(e)) return; n++; box.classList.add('lib-drop'); });
    box.addEventListener('dragleave', () => { if (--n <= 0) { n = 0; box.classList.remove('lib-drop'); } });
    box.addEventListener('dragover', e => { if (has(e)) e.preventDefault(); });
    box.addEventListener('drop', e => { if (!has(e)) return; e.preventDefault(); n = 0; box.classList.remove('lib-drop'); if (e.kolRow) return; const f = [...e.dataTransfer.files]; if (f.length) add(f); });
  }

  async function add(files) {
    const lim = L().D.limits || { file_max: 50 << 20, free: Infinity };
    const bad = [], good = [];
    let total = 0;
    for (const f of files) {
      if (!AUDIO_EXT.test(f.name) && !(f.type || '').startsWith('audio/')) bad.push([f.name, 'אינו קובץ שמע. סוגים: mp3, m4a / aac, ogg, opus, wav, webm, flac']);
      else if (!f.size) bad.push([f.name, 'הקובץ ריק']);
      else if (f.size > lim.file_max) bad.push([f.name, `גדול מהתקרה לקובץ (${Math.round(lim.file_max / 1048576)}MB). את התקרה קובע בעל העמוד — 🎙️ הקלטות ← הבאה מהאתר ← הגדרות`]);
      else { good.push(f); total += f.size; }
    }
    // N2b: "להוסיף ל:" — השדה לקבצים בלי סיומת שדה (ברירת מחדל — השדה שבסינון); מפתח / שם שדה אחרי מקף גובר
    let field = L().fieldF || L().primaryKey();
    let rows = [], matched = [], clash = [], none = [];
    const match = async () => {
      let items = [];
      if (good.length) items = (await api('rec_match', { names: good.map(f => f.name), field })).items;
      // כל קובץ: ההחלטה — connect (חבר) · replace (החלף את הקיימת) · loose (הוסף כלא מחוברת) · skip (דלג)
      rows = items.map(it => ({ it, file: good[it.i], choice: it.word_id ? (it.conflict || it.dup !== undefined ? 'replace' : 'connect') : 'loose' }));
      matched = rows.filter(x => x.it.word_id && !x.it.conflict && x.it.dup === undefined);
      clash = rows.filter(x => x.it.word_id && (x.it.conflict || x.it.dup !== undefined));
      none = rows.filter(x => !x.it.word_id);
    };
    try { await match(); } catch (e) { toast(e.message, true); return; }
    const fcol = x => L().multi() && x.it.field ? ` · ${fshort(x.it.field)}${x.it.by_field ? ' (משם הקובץ)' : ''}` : '';
    const choose = (x, opts) => h('select', { 'aria-label': `מה לעשות: ${x.file.name}`, onchange: e => { x.choice = e.target.value; count(); } },
      opts.map(([v, l]) => h('option', { value: v, selected: v === x.choice }, l)));
    const line = (x, ...rest) => h('li', { class: 'lib-mrow' }, h('span', { class: 'use-tx' }, h('b', { class: 'one', dir: 'auto' }, x.file.name),
      h('small', { class: 'one' }, ...rest)), h('small', { class: 'muted' }, mb(x.file.size)));
    const goBtn = h('button', { type: 'button', class: 'btn primary' });
    const count = () => { const n = rows.filter(x => x.choice !== 'skip').length; goBtn.textContent = `✓ אישור — העלאה של ${n}`; goBtn.disabled = !n; };
    const bulk = v => { clash.forEach(x => { x.choice = v; }); draw(); };
    const sec = (title, list, fn) => list.length ? [h('h3', { class: 'sec-title' }, `${title} · ${list.length}`), h('ul', { class: 'list lib-match' }, list.map(fn))] : [];
    const draw = () => {
      body.replaceChildren(...[
        h('p', { class: 'hint' }, 'ההתאמה לפי שם הקובץ: מזהה המילה (למשל 6.mp3) או המילה עצמה. עוד לא עלה ולא חובר דבר — רק אחרי האישור.'),
        fieldSelect(field, async v => { field = v; try { await match(); } catch (e) { toast(e.message, true); } draw(); }, 'להוסיף ל:'),
        L().multi() ? h('p', { class: 'hint' }, 'שדה אחר לקובץ מסוים — אחרי מקף בשם הקובץ: 12-audio_fr.mp3 או "בסטילה - בצרפתית.mp3".') : null,
        total > lim.free ? h('p', { class: 'error' }, `אין מקום בתקרת הדיסק: נדרשים ${mb(total)}, פנויים ${mb(lim.free)}. בעל העמוד מגדיל אותה ב-🗂️ קבצים ← תקרת הקבצים, או מפנה מקום (סל, גרסאות ישנות).`) : null,
        ...sec('✅ הותאמו', matched, x => line(x, `→ #${x.it.word_id} ${x.it.word}${fcol(x)} · לפי ${x.it.by === 'id' ? 'המזהה' : 'המילה'}`, ' ', choose(x, [['connect', 'חבר'], ['loose', 'הוסף כלא מחוברת'], ['skip', 'דלג']]))),
        clash.length ? h('div', { class: 'row-btns' }, h('button', { type: 'button', class: 'btn ghost sm', onclick: () => bulk('replace') }, 'החלף בכולן'),
          h('button', { type: 'button', class: 'btn ghost sm', onclick: () => bulk('skip') }, 'דלג על כולן')) : null,
        ...sec('⚠️ מתנגשים עם הקלטה קיימת', clash, x => line(x, `→ #${x.it.word_id} ${x.it.word}${fcol(x)} · `,
          x.it.conflict ? `יש כבר: «${x.it.conflict.name}»${x.it.conflict.state === 'site' ? ' (באתר)' : ''}` : x.it.note, ' ',
          choose(x, [['replace', 'החלף (הקיימת נשמרת כגרסה)'], ['skip', 'דלג']]))),
        ...sec('❔ לא הותאמו', none, x => line(x, x.it.note || 'אין מילה בשם הזה', ' ', choose(x, [['loose', 'הוסף כ"לא מחוברת"'], ['skip', 'דלג']]))),
        ...sec('⛔ נדחו', bad, ([n, why]) => h('li', { class: 'lib-mrow' }, h('span', { class: 'use-tx' }, h('b', { class: 'one', dir: 'auto' }, n), h('small', { class: 'lib-bad' }, why)))),
        rows.length ? h('div', { class: 'row-btns' }, goBtn, h('button', { type: 'button', class: 'btn ghost', onclick: () => $('#sheet').close() }, 'ביטול')) : null,
        ups].filter(Boolean));                 // replaceChildren(null) כותב "null"
      count();
    };
    const body = h('div', { class: 'lib-addflow' });
    const ups = h('div', { class: 'ups' });
    sheet(`➕ הוספת הקלטות · ${files.length}`, body);
    draw();
    goBtn.onclick = async () => {
      if (total > lim.free) { toast('אין מקום בתקרת הדיסק', true); return; }
      body.querySelectorAll('select, button').forEach(b => { b.disabled = true; });
      const res = { connect: 0, replace: 0, loose: 0, fail: [] };
      for (const x of rows.filter(r => r.choice !== 'skip')) {
        const pr = Media.progressRow();
        ups.append(pr.el);
        try {
          const f = await Media.upload(x.file, (got, tot, left, note) => pr.set(x.file.name, got, tot, left, note));
          await api('rec_add', x.choice === 'loose' ? { file_id: f.id } : { file_id: f.id, word_id: x.it.word_id, field: x.it.field, on_conflict: x.choice === 'replace' ? 'replace' : 'skip' });
          res[x.choice]++;
          pr.el.remove();
        } catch (e) {
          res.fail.push(x.file.name);
          pr.el.classList.add('error');
          pr.el.querySelector('.line .muted').textContent = e.message;
        }
      }
      await L().reload(true);
      const msg = [res.connect && `חוברו ${res.connect}`, res.replace && `הוחלפו ${res.replace}`, res.loose && `נוספו כלא מחוברות ${res.loose}`, res.fail.length && `נכשלו ${res.fail.length}`].filter(Boolean).join(' · ');
      if (!res.fail.length) { const d = $('#sheet'); if (d.open) d.close(); }
      toast(msg || 'לא נוסף דבר', !!res.fail.length);
    };
  }

  return { menu, rename, versions, connect, moveField, pickFor, dragRow, disconnect, remove, trashView, download, keepOne, versionsLine, addButton, dropZone, add, ask };
})();
