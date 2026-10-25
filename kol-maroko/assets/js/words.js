// קול מרוקו — המילים של המילון (lib/lexicon.php). מנוהלות כאן בלבד; העמוד מציג אותן ברכיב "מילון".
// טבלה כמו באקסל: המילה והנושאים נערכים ישר בתא (נשמר ביציאה מהתא), הפירוש וההקלטה — בכרטיס. סינון לפי נושא
// ולפי הקלטה, ניהול נושאים (שינוי שם / איחוד / הסרה), וייצוא / ייבוא לאקסל.
'use strict';

const Words = (() => {
  const PAGE = 100;                       // שורות בכל פעם — 1,500 שורות עם שדות בבת אחת כבדות בטלפון
  const AUDIO = { '': 'כל ההקלטות', yes: 'עם הקלטה', no: 'בלי הקלטה', new: 'הקלטה חדשה', missing: 'הקלטה חסרה' };
  const KIND = { site: ['באתר', ''], copy: ['בכלי', 'ok'], file: ['חדשה', 'warn'], missing: ['חסרה', 'bad'] };
  let D = null;                           // { words, topics }
  let q = '', topic = '', audio = '', shown = PAGE;
  let player = null;

  const plain = html => { const d = document.createElement('div'); d.innerHTML = html.replace(/<\/(p|li|h\d)>|<br\s*\/?>/gi, ' $&'); return d.textContent.replace(/\s+/g, ' ').trim(); };
  const norm = s => s.toLowerCase().replace(/[֑-ׇ]/g, '');          // בלי ניקוד וטעמים
  const splitTopics = s => [...new Set(s.split(/[;,،]/).map(t => t.trim().replace(/\s+/g, ' ')).filter(Boolean))];

  function play(src, btn) {
    if (player && !player.paused && player.dataset.src === src) { player.pause(); return; }
    player?.pause();
    player = new Audio(src);
    player.dataset.src = src;
    btn.textContent = '⏸';
    const reset = () => { btn.textContent = '▶'; };
    player.onended = reset; player.onpause = reset;
    player.onerror = () => { reset(); toast('ההקלטה לא נטענה', true); };
    player.play().catch(reset);
  }

  async function view(host) {
    host.replaceChildren(h('p', { class: 'empty' }, 'טוען…'));
    try { D = await api('words'); } catch (e) { host.replaceChildren(h('p', { class: 'error' }, e.message)); return; }
    shown = PAGE;
    draw(host);
  }

  // שמירה של שורה (מהתא או מהכרטיס). light — השרת מחזיר רק את השורה
  async function saveRow(w, patch, light = true) {
    const body = { id: w.id, word: w.word, meaning: w.meaning, topics: w.topics, audio: w.audio, external_id: w.external_id, ...patch, light };
    const r = await api('word_save', body);
    if (!light) { D = r; return; }
    const i = D.words.findIndex(x => x.id === r.id);
    if (i >= 0) D.words[i] = r.word; else D.words.unshift(r.word);
  }

  function draw(host) {
    topicsList();
    const search = h('input', { type: 'search', class: 'w-search', placeholder: 'חיפוש מילה או פירוש…', value: q, 'aria-label': 'חיפוש',
      oninput: e => { q = e.target.value; shown = PAGE; list(); } });
    const topicSel = h('select', { 'aria-label': 'נושא', onchange: e => { topic = e.target.value; shown = PAGE; list(); } },
      h('option', { value: '' }, 'כל הנושאים'), h('option', { value: '-', selected: topic === '-' }, 'בלי נושא'),
      D.topics.map(t => h('option', { value: t.name, selected: t.name === topic }, `${t.name} (${t.count})`)));
    const audioSel = h('select', { 'aria-label': 'הקלטה', onchange: e => { audio = e.target.value; shown = PAGE; list(); } },
      Object.entries(AUDIO).map(([v, l]) => h('option', { value: v, selected: v === audio }, l)));
    const n = k => D.words.filter(w => w.audio_kind === k).length;
    const csvIn = h('input', { type: 'file', accept: '.csv,text/csv', hidden: true, onchange: () => importCsv(csvIn, host) });
    const box = h('div', { class: 'w-box' });
    host.replaceChildren(
      h('div', { class: 'toolbar wrap' },
        h('button', { type: 'button', class: 'btn primary', onclick: () => edit(null, host) }, h('span', { 'aria-hidden': 'true' }, '+'), ' מילה'),
        h('button', { type: 'button', class: 'btn ghost', onclick: () => topicsSheet(host) }, '🏷️ נושאים'),
        h('a', { class: 'btn ghost', href: 'api.php?action=words_csv', download: '' }, '⬇️ לאקסל'),
        h('button', { type: 'button', class: 'btn ghost', onclick: () => csvIn.click() }, '⬆️ מאקסל'), csvIn),
      h('div', { class: 'summary' }, h('div', { class: 'line wrap' },
        h('b', {}, String(D.words.length)), h('span', { class: 'muted' }, 'מילים ·'),
        h('b', {}, String(D.words.filter(w => w.audio).length)), h('span', { class: 'muted' }, 'עם הקלטה ·'),
        h('b', {}, String(n('copy') + n('file'))), h('span', { class: 'muted' }, 'מהן בכלי ·'),
        h('b', {}, String(D.topics.length)), h('span', { class: 'muted' }, 'נושאים')),
        n('site') ? h('p', { class: 'hint', style: 'margin:0' }, `${n('site')} הקלטות עדיין רק באתר. להעתקה לכלי: ⚙️ הגדרות ← "ייבוא ההקלטות".`) : null),
      h('div', { class: 'w-filters' }, search, topicSel, audioSel),
      box);

    function rows() {
      const s = norm(q.trim());
      return D.words.filter(w =>
        (!topic || (topic === '-' ? !w.topics.length : w.topics.includes(topic))) &&
        (!audio || (audio === 'yes' ? !!w.audio : audio === 'no' ? !w.audio : audio === 'new' ? w.audio_kind === 'file' : w.audio_kind === 'missing')) &&
        (!s || norm(w.word).includes(s) || norm(plain(w.meaning)).includes(s)));
    }
    function list() {
      const all = rows();
      const table = h('table', { class: 'w-table' },
        h('thead', {}, h('tr', {}, h('th', { class: 'w-c-word' }, 'מילה'),
          h('th', {}, 'פירוש'), h('th', { class: 'w-c-topics' }, 'נושאים'), h('th', { class: 'w-c-audio' }, 'הקלטה'), h('th', { class: 'w-c-act' }, h('span', { class: 'sr' }, 'עריכה')))),
        h('tbody', {}, all.slice(0, shown).map(w => row(w, host))));
      box.replaceChildren(...[
        all.length ? h('div', { class: 'w-table-wrap' }, table) : h('p', { class: 'empty' }, D.words.length ? 'לא נמצאו מילים.' : 'עוד אין מילים. "+ מילה", או ייבוא מהאתר ב-⚙️ הגדרות.'),
        all.length > shown ? h('button', { type: 'button', class: 'btn ghost w-more', onclick: () => { shown += PAGE; list(); } }, `עוד ${Math.min(PAGE, all.length - shown)} (נשארו ${all.length - shown})`) : null,
        all.length !== D.words.length ? h('p', { class: 'hint', style: 'text-align:center' }, `${all.length} מתוך ${D.words.length}`) : null,
      ].filter(Boolean));
    }
    list();
  }

  // תא שנערך במקום: נשמר ביציאה ממנו (או Enter), Esc מחזיר
  function cell(value, label, onSave, attrs = {}) {
    const inp = h('input', { class: 'w-cell', value, 'aria-label': label, dir: 'auto', ...attrs });
    let saved = value;
    const commit = async () => {
      if (inp.value.trim() === saved.trim()) return;
      inp.classList.add('saving');
      try { await onSave(inp.value); saved = inp.value; inp.classList.remove('err'); }
      catch (e) { inp.classList.add('err'); toast(e.message, true); }
      inp.classList.remove('saving');
    };
    inp.addEventListener('change', commit);
    inp.addEventListener('keydown', e => {
      if (e.key === 'Enter') { e.preventDefault(); inp.blur(); }
      if (e.key === 'Escape') { inp.value = saved; inp.blur(); }
    });
    return inp;
  }

  function row(w, host) {
    const [kl, kc] = KIND[w.audio_kind] || ['', ''];
    // ההשמעה בתוך תא המילה — שניהם נשארים במקום כשגוללים את הטבלה לרוחב
    return h('tr', {},
      h('td', { class: 'w-c-word' }, h('div', { class: 'w-wordcell' }, w.audio_src
        ? h('button', { type: 'button', class: 'icon-btn w-play', 'aria-label': `השמעה: ${w.word}`, onclick: e => play(w.audio_src, e.currentTarget) }, '▶')
        : h('span', { class: 'w-play none', role: 'img', 'aria-label': 'בלי הקלטה', title: 'בלי הקלטה' }),
        cell(w.word, 'המילה', v => saveRow(w, { word: v }), { maxlength: 200 }))),
      h('td', { class: 'w-c-mean' }, h('button', { type: 'button', class: 'w-mean', title: 'עריכת הפירוש', onclick: () => edit(w, host, 'meaning') },
        plain(w.meaning).slice(0, 140) || h('span', { class: 'muted' }, '+ פירוש'))),
      h('td', { class: 'w-c-topics' }, cell(w.topics.join(', '), 'נושאים', v => saveRow(w, { topics: splitTopics(v) }), { list: 'w-topics', placeholder: '—' })),
      h('td', { class: 'w-c-audio' }, h('button', { type: 'button', class: 'w-aud', title: 'הקלטה / החלפה', onclick: () => pickAudio(w, host) },
        kl ? h('span', { class: `chip ${kc}` }, kl) : h('span', { class: 'muted' }, '🎙️ +'))),
      h('td', { class: 'w-c-act' }, h('button', { type: 'button', class: 'icon-btn', 'aria-label': `עריכה: ${w.word}`, onclick: () => edit(w, host) }, '✏️')));
  }

  async function pickAudio(w, host) {
    const [f] = await Media.pick({ kind: 'audio' });
    if (!f) return;
    try { await saveRow(w, { audio: f.id }); toast('ההקלטה הוחלפה'); draw(host); } catch (e) { toast(e.message, true); }
  }

  // ── נושאים: שינוי שם (גם איחוד עם נושא קיים) והסרה ─────────
  function topicsSheet(host) {
    const run = async (body, ok) => {
      try { D = await api('topic_op', body); toast(ok); topicsSheet(host); draw(host); } catch (e) { toast(e.message, true); }
    };
    sheet('🏷️ נושאים',
      h('p', { class: 'hint' }, 'שינוי שם חל על כל המילים בנושא. שם של נושא קיים — מאחד את השניים.'),
      D.topics.length ? h('ul', { class: 'list' }, D.topics.map(t => h('li', { class: 'w-topic' },
        h('span', { class: 'use-tx' }, h('b', { class: 'one' }, t.name), h('small', {}, `${t.count} מילים`)),
        h('button', { type: 'button', class: 'btn sm ghost', onclick: () => {
          const to = prompt(`שם חדש לנושא "${t.name}"`, t.name);
          if (to && to.trim() && to.trim() !== t.name) run({ op: 'rename', name: t.name, to: to.trim() }, 'נשמר');
        } }, '✏️ שם'),
        h('button', { type: 'button', class: 'btn sm ghost danger', onclick: () => {
          if (confirm(`להסיר את הנושא "${t.name}" מ-${t.count} מילים? המילים עצמן נשארות.`)) run({ op: 'delete', name: t.name }, 'הוסר');
        } }, '🗑️')))) : h('p', { class: 'empty' }, 'אין נושאים.'));
  }

  // ── אקסל ────────────────────────────────────────────────
  async function importCsv(input, host) {
    const f = input.files[0];
    input.value = '';
    if (!f) return;
    if (!confirm('לעדכן את המילים לפי הקובץ? שורה עם מזהה — מעדכנת את המילה, בלי מזהה — מוסיפה. מחיקה וההקלטות — לא מהקובץ.')) return;
    const buf = await f.arrayBuffer();
    let text;
    try { text = new TextDecoder('utf-8', { fatal: true }).decode(buf); } catch { text = new TextDecoder('windows-1255').decode(buf); }
    try {
      const r = await api('words_csv_import', { csv: text });
      D = r;
      const s = r.stats;
      sheet('⬆️ מאקסל', h('div', { class: 'summary' },
        h('p', {}, h('b', {}, `${s.updated} עודכנו · ${s.added} נוספו`), ` · ${s.same} בלי שינוי`),
        s.errors.length ? h('div', { class: 'warnbox' }, h('b', {}, `${s.errors.length} שורות לא נקלטו:`), h('ul', {}, s.errors.slice(0, 30).map(x => h('li', {}, x)))) : null));
      draw(host);
    } catch (e) { toast(e.message, true); }
  }

  // ── כרטיס עריכה: הכול, כולל הפירוש המעוצב וההקלטה ──────────
  function rich(html) {
    const ed = h('div', { class: 'rte', contenteditable: 'true', role: 'textbox', 'aria-multiline': 'true', 'aria-label': 'הפירוש', dir: 'auto' });
    ed.innerHTML = html;
    ed.addEventListener('paste', e => { e.preventDefault(); document.execCommand('insertText', false, e.clipboardData.getData('text/plain')); });
    const b = (ic, lbl, c) => h('button', { type: 'button', class: 'icon-btn', 'aria-label': lbl, title: lbl, onmousedown: e => e.preventDefault(),
      onclick: () => { ed.focus(); document.execCommand(c); } }, ic);
    const box = h('div', { class: 'rte-box' }, h('div', { class: 'rte-bar' },
      b(h('b', {}, 'B'), 'מודגש', 'bold'), b(h('i', {}, 'I'), 'נטוי', 'italic'), b('•', 'רשימה', 'insertUnorderedList'), b('⌫', 'ניקוי עיצוב', 'removeFormat')), ed);
    return { box, ed, value: () => ed.innerHTML };
  }

  function edit(w, host, focus) {
    const cur = w ? { ...w, topics: [...w.topics] } : { id: 0, word: '', meaning: '', topics: [], audio: '', audio_src: '', audio_kind: '', external_id: '' };
    const word = h('input', { value: cur.word, maxlength: 200, required: true, dir: 'auto' });
    const meaning = rich(cur.meaning);
    const chips = h('div', { class: 'w-chips' });
    const addTopic = h('input', { list: 'w-topics', placeholder: 'נושא — Enter להוספה', maxlength: 80,
      onkeydown: e => { if (e.key === 'Enter' || e.key === ',') { e.preventDefault(); pushTopic(); } }, onchange: () => pushTopic() });
    const pushTopic = () => {
      splitTopics(addTopic.value).forEach(t => { if (!cur.topics.includes(t)) cur.topics.push(t); });
      addTopic.value = '';
      drawChips();
    };
    const drawChips = () => chips.replaceChildren(...cur.topics.map((t, i) => h('span', { class: 'chip' }, t,
      h('button', { type: 'button', class: 'chip-x', 'aria-label': `הסרת ${t}`, onclick: () => { cur.topics.splice(i, 1); drawChips(); } }, '✕'))));
    drawChips();
    const audioBox = h('div', { class: 'w-audio' });
    const drawAudio = () => audioBox.replaceChildren(...[                   // replaceChildren(null) כותב "null"
      cur.audio_src ? h('audio', { controls: true, preload: 'none', src: cur.audio_src }) : null,
      h('small', { class: 'muted' }, { site: 'ההקלטה שבאתר', copy: 'ההקלטה מהאתר (עותק בכלי)', file: 'הקלטה חדשה — תעלה לאתר בפרסום', missing: 'הקובץ חסר — הקליטו או בחרו מחדש' }[cur.audio_kind] || 'בלי הקלטה'),
      h('div', { class: 'row-btns' },
        h('button', { type: 'button', class: 'btn sm ghost', onclick: async () => {
          const [f] = await Media.pick({ kind: 'audio' });
          if (!f) return;
          Object.assign(cur, { audio: f.id, audio_src: f.url, audio_kind: 'file' });
          drawAudio();
        } }, cur.audio ? '🔁 החלפה / הקלטה' : '🎙️ הקלטה / בחירה'),
        cur.audio ? h('button', { type: 'button', class: 'btn sm ghost danger', onclick: () => { Object.assign(cur, { audio: '', audio_src: '', audio_kind: '' }); drawAudio(); } }, '✕ הסרה') : null),
    ].filter(Boolean));
    drawAudio();
    const ext = h('input', { value: cur.external_id, maxlength: 40, dir: 'ltr', inputmode: 'numeric' });
    const save = h('button', { type: 'button', class: 'btn primary' }, '✓ שמירה');
    const dlg = sheet(w ? `✏️ ${w.word}` : '+ מילה חדשה',
      h('label', { class: 'f' }, h('span', {}, 'המילה *'), word),
      h('div', { class: 'f' }, h('span', {}, 'הפירוש'), meaning.box),
      h('div', { class: 'f' }, h('span', {}, 'נושאים'), chips, addTopic),
      h('div', { class: 'f' }, h('span', {}, 'הקלטה'), audioBox),
      h('label', { class: 'f' }, h('span', {}, 'מספר מהאקסל (לא חובה)'), ext),
      h('div', { class: 'row-btns' }, save,
        w ? h('button', { type: 'button', class: 'btn ghost danger', onclick: async () => {
          if (!confirm(`למחוק את "${w.word}"? (באתר היא תיעלם בפרסום הבא)`)) return;
          try { D = await api('word_delete', { id: w.id }); dlg.close(); toast('נמחקה'); draw(host); } catch (e) { toast(e.message, true); }
        } }, '🗑️ מחיקה') : null));
    save.onclick = async () => {
      pushTopic();
      if (!word.value.trim()) { word.focus(); toast('חסרה המילה', true); return; }
      save.disabled = true;
      try {
        await saveRow(cur, { word: word.value, meaning: meaning.value(), topics: cur.topics, audio: cur.audio, external_id: ext.value }, !w ? false : true);
        dlg.close(); toast('נשמרה'); draw(host);
      } catch (e) { toast(e.message, true); save.disabled = false; }
    };
    if (!w) word.focus();
    else if (focus === 'meaning') meaning.ed.focus();
  }

  // הצעות לנושאים — לתאים ולכרטיס
  function topicsList() {
    let dl = document.getElementById('w-topics');
    if (!dl) { dl = h('datalist', { id: 'w-topics' }); document.body.append(dl); }
    dl.replaceChildren(...(D?.topics || []).map(t => h('option', { value: t.name })));
  }
  return { view };
})();
