// קול מרוקו — המילים של המילון (lib/lexicon.php). מנוהלות כאן בלבד; העמוד מציג אותן ברכיב "מילון".
// רשימה עם חיפוש וסינון לפי נושא, וגיליון עריכה: המילה, הפירוש, הנושאים וההקלטה (הקלטה חדשה / קובץ / זו שבאתר).
'use strict';

const Words = (() => {
  const PAGE = 150;                       // שורות בכל פעם — 1,500 מילים בבת אחת כבדות בטלפון
  let D = null;                           // { words, topics }
  let q = '', topic = '', shown = PAGE;
  let player = null;

  const plain = html => { const d = document.createElement('div'); d.innerHTML = html; return d.textContent.replace(/\s+/g, ' ').trim(); };
  const norm = s => s.toLowerCase().replace(/[֑-ׇ]/g, '');          // בלי ניקוד וטעמים

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

  function draw(host) {
    const search = h('input', { type: 'search', class: 'w-search', placeholder: 'חיפוש מילה או פירוש…', value: q, 'aria-label': 'חיפוש',
      oninput: e => { q = e.target.value; shown = PAGE; list(); } });
    const sel = h('select', { 'aria-label': 'נושא', onchange: e => { topic = e.target.value; shown = PAGE; list(); } },
      h('option', { value: '' }, `כל הנושאים`), D.topics.map(t => h('option', { value: t.name, selected: t.name === topic }, `${t.name} (${t.count})`)));
    const withAudio = D.words.filter(w => w.audio).length;
    const box = h('div', { class: 'w-list' });
    host.replaceChildren(
      h('div', { class: 'toolbar' }, h('p', { class: 'hint', style: 'flex:1;margin:0' }, 'המילים של העמוד. כל שינוי כאן נכנס לאתר בפרסום הבא.'),
        h('button', { type: 'button', class: 'btn primary', onclick: () => edit(null, host) }, h('span', { 'aria-hidden': 'true' }, '+'), ' מילה')),
      h('div', { class: 'summary' }, h('div', { class: 'line' }, h('b', {}, String(D.words.length)), h('span', { class: 'muted' }, 'מילים'),
        h('span', { class: 'muted' }, '·'), h('b', {}, String(withAudio)), h('span', { class: 'muted' }, 'עם הקלטה'),
        h('span', { class: 'muted' }, '·'), h('b', {}, String(D.topics.length)), h('span', { class: 'muted' }, 'נושאים'))),
      h('div', { class: 'w-filters' }, search, sel),
      box);
    function list() {
      const s = norm(q.trim());
      const rows = D.words.filter(w => (!topic || w.topics.includes(topic)) && (!s || norm(w.word).includes(s) || norm(plain(w.meaning)).includes(s)));
      const more = rows.length > shown;
      box.replaceChildren(
        rows.length ? h('ul', { class: 'list' }, rows.slice(0, shown).map(w => row(w, host))) : h('p', { class: 'empty' }, D.words.length ? 'לא נמצאו מילים.' : 'עוד אין מילים. "+ מילה", או ייבוא מהאתר ב-⚙️ הגדרות.'),
        more ? h('button', { type: 'button', class: 'btn ghost w-more', onclick: () => { shown += PAGE; list(); } }, `עוד (${rows.length - shown})`) : null,
        h('p', { class: 'hint', style: 'text-align:center' }, rows.length === D.words.length ? '' : `${rows.length} מתוך ${D.words.length}`));
    }
    list();
  }

  function row(w, host) {
    const btn = w.audio_src
      ? h('button', { type: 'button', class: 'icon-btn w-play', 'aria-label': `השמעה: ${w.word}`, onclick: e => { e.stopPropagation(); play(w.audio_src, e.currentTarget); } }, '▶')
      : h('span', { class: 'w-play none', role: 'img', 'aria-label': 'בלי הקלטה', title: 'בלי הקלטה' });
    const sub = [w.topics.join(' · '), plain(w.meaning).slice(0, 90)].filter(Boolean).join(' — ');
    return h('li', { class: 'w-row' }, btn,
      h('button', { type: 'button', class: 'use-row', onclick: () => edit(w, host) },
        h('span', { class: 'use-tx' }, h('b', { class: 'one' }, w.word), h('small', { class: 'one' }, sub || 'בלי פירוש')),
        w.audio_kind === 'missing' ? h('span', { class: 'chip bad' }, 'הקלטה חסרה') : null,
        h('span', { class: 'use-go', 'aria-hidden': 'true' }, '‹')));
  }

  // ── עריכה ───────────────────────────────────────────────
  function rich(html) {
    const ed = h('div', { class: 'rte', contenteditable: 'true', role: 'textbox', 'aria-multiline': 'true', 'aria-label': 'הפירוש', dir: 'auto' });
    ed.innerHTML = html;
    ed.addEventListener('paste', e => { e.preventDefault(); document.execCommand('insertText', false, e.clipboardData.getData('text/plain')); });
    const b = (ic, lbl, c) => h('button', { type: 'button', class: 'icon-btn', 'aria-label': lbl, title: lbl, onmousedown: e => e.preventDefault(),
      onclick: () => { ed.focus(); document.execCommand(c); } }, ic);
    const box = h('div', { class: 'rte-box' }, h('div', { class: 'rte-bar' },
      b(h('b', {}, 'B'), 'מודגש', 'bold'), b(h('i', {}, 'I'), 'נטוי', 'italic'), b('•', 'רשימה', 'insertUnorderedList'), b('⌫', 'ניקוי עיצוב', 'removeFormat')), ed);
    return { box, value: () => ed.innerHTML };
  }

  function edit(w, host) {
    const cur = w ? { ...w, topics: [...w.topics] } : { id: 0, word: '', meaning: '', topics: [], audio: '', audio_src: '', audio_kind: '', external_id: '' };
    const word = h('input', { value: cur.word, maxlength: 200, required: true, dir: 'auto' });
    const meaning = rich(cur.meaning);
    const dl = h('datalist', { id: 'w-topics' }, D.topics.map(t => h('option', { value: t.name })));
    const chips = h('div', { class: 'w-chips' });
    const addTopic = h('input', { list: 'w-topics', placeholder: 'נושא — Enter להוספה', maxlength: 80,
      onkeydown: e => { if (e.key === 'Enter' || e.key === ',') { e.preventDefault(); pushTopic(); } }, onchange: () => pushTopic() });
    const pushTopic = () => {
      const t = addTopic.value.trim().replace(/,$/, '');
      if (t && !cur.topics.includes(t)) cur.topics.push(t);
      addTopic.value = '';
      drawChips();
    };
    const drawChips = () => chips.replaceChildren(...cur.topics.map((t, i) => h('span', { class: 'chip' }, t,
      h('button', { type: 'button', class: 'chip-x', 'aria-label': `הסרת ${t}`, onclick: () => { cur.topics.splice(i, 1); drawChips(); } }, '✕'))));
    drawChips();
    const audioBox = h('div', { class: 'w-audio' });
    const drawAudio = () => audioBox.replaceChildren(...[                   // replaceChildren(null) כותב "null"
      cur.audio_src ? h('audio', { controls: true, preload: 'none', src: cur.audio_src }) : null,
      h('small', { class: 'muted' }, { site: 'ההקלטה שבאתר', file: 'הקלטה חדשה — תעלה לאתר בפרסום', missing: 'הקובץ חסר — הקליטו או בחרו מחדש' }[cur.audio_kind] || 'בלי הקלטה'),
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
      h('div', { class: 'f' }, h('span', {}, 'נושאים'), chips, addTopic, dl),
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
        D = await api('word_save', { id: cur.id, word: word.value, meaning: meaning.value(), topics: cur.topics, audio: cur.audio, external_id: ext.value });
        dlg.close(); toast('נשמרה'); draw(host);
      } catch (e) { toast(e.message, true); save.disabled = false; }
    };
    if (!w) word.focus();
  }

  return { view };
})();
