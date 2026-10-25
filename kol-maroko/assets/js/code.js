// קול מרוקו — קבצי הקוד של העמוד (lib/code.php): העורך של VS Code (Monaco), תצוגה, היסטוריה ועזרה למשתני התבנית.
// ועבודה מצ'אט (lib/agent.php): יצירת כניסה וסקיל להעתקה.
'use strict';

const Code = (() => {
  const MONACO = 'https://cdn.jsdelivr.net/npm/monaco-editor@0.52.2/min/vs';
  const NAMES = { 'lexicon.html': 'HTML (תבנית)', 'lexicon.css': 'CSS', 'lexicon.js': 'JS' };
  let C = null;                 // מצב המסך: { host, files, models, cur, editor, frame }

  // ── Monaco ─────────────────────────────────────────────
  let monaco = null;
  function loadMonaco() {
    if (monaco) return Promise.resolve(monaco);
    return new Promise((ok, fail) => {
      const t = setTimeout(() => fail(new Error('העורך לא נטען')), 15000);
      // ה-workers מכתובת אחרת — דרך blob, אחרת הדפדפן חוסם
      window.MonacoEnvironment = { getWorkerUrl: () => URL.createObjectURL(new Blob(
        [`self.MonacoEnvironment={baseUrl:'${MONACO}/../'};importScripts('${MONACO}/base/worker/workerMain.js');`], { type: 'text/javascript' })) };
      const s = document.createElement('script');
      s.src = MONACO + '/loader.js';
      s.onerror = () => { clearTimeout(t); fail(new Error('העורך לא נטען')); };
      s.onload = () => {
        window.require.config({ paths: { vs: MONACO } });
        window.require(['vs/editor/editor.main'], m => { clearTimeout(t); monaco = m; ok(m); }, e => { clearTimeout(t); fail(e); });
      };
      document.head.append(s);
    });
  }

  const dark = () => matchMedia('(prefers-color-scheme: dark)').matches;
  const isDirty = p => C && C.models[p] && C.models[p].get() !== C.files[p].content;

  // מודל לקובץ: Monaco, או תיבת טקסט אם העורך לא נטען
  function makeModel(path, content) {
    if (monaco) {
      const m = monaco.editor.createModel(content, { 'lexicon.html': 'html', 'lexicon.css': 'css', 'lexicon.js': 'javascript' }[path]);
      m.onDidChangeContent(() => bar());
      return { get: () => m.getValue(), set: v => m.setValue(v), m };
    }
    const ta = h('textarea', { class: 'code-area code-fallback', dir: 'ltr', spellcheck: 'false', oninput: () => bar() });
    ta.value = content;
    return { get: () => ta.value, set: v => { ta.value = v; bar(); }, ta };
  }

  async function view(host) {
    unmount();
    host.replaceChildren(h('p', { class: 'empty' }, 'טוען…'));
    let st;
    try { st = await api('code'); } catch (e) { host.replaceChildren(h('p', { class: 'error' }, e.message)); return; }
    if (!st.active) { intro(host); return; }
    C = { host, files: st.files, help: st.help, models: {}, cur: sessionStorage.getItem('kol-code-file') || 'lexicon.html' };
    if (!C.files[C.cur]) C.cur = 'lexicon.html';
    const edBox = h('div', { class: 'code-ed', dir: 'ltr' });
    const frame = h('iframe', { class: 'code-frame', sandbox: 'allow-scripts', title: 'תצוגה' });
    C.frame = frame;
    C.root = h('div', { class: 'code-wrap' },
      h('div', { class: 'code-bar' },
        h('div', { class: 'seg code-files', role: 'tablist' }),
        h('button', { type: 'button', class: 'btn primary', 'data-a': 'save', onclick: () => save() }, '💾 שמירה'),
        h('span', { class: 'chip code-st' }),
        h('button', { type: 'button', class: 'icon-btn', title: 'היסטוריה', 'aria-label': 'היסטוריה', onclick: () => history() }, '🕘'),
        h('button', { type: 'button', class: 'icon-btn', title: 'המשתנים של התבנית', 'aria-label': 'עזרה', onclick: () => help() }, '❓'),
        h('button', { type: 'button', class: 'icon-btn code-pv-btn', title: 'תצוגה', 'aria-label': 'תצוגה', onclick: () => { C.root.classList.toggle('show-pv'); refresh(); } }, '👁️'),
        h('button', { type: 'button', class: 'icon-btn', title: 'חזרה לתבנית המובנית', 'aria-label': 'חזרה לתבנית המובנית', onclick: () => reset() }, '↩️')),
      h('div', { class: 'code-main' }, edBox, h('div', { class: 'code-pv' }, frame)));
    host.replaceChildren(h('p', { class: 'hint' }, 'הקוד של העמוד. ',
      h('b', {}, 'שמירה'), ' — לטיוטה (Ctrl+S); לאתר — בפרסום. אחרי פרסום האתר טוען את הקבצים האלה במקום של ערכת הנושא. JS רץ בעמוד באתר — לבדוק בתצוגה לפני פרסום.'), C.root);
    C.root.style.height = `calc(100dvh - ${Math.round(C.root.getBoundingClientRect().top + scrollY) + 8}px)`;
    try {
      await loadMonaco();
      if (!C || C.host !== host) return;
      for (const p of Object.keys(C.files)) C.models[p] = makeModel(p, C.files[p].content);
      C.editor = monaco.editor.create(edBox, { model: C.models[C.cur].m, theme: dark() ? 'vs-dark' : 'vs', automaticLayout: true, minimap: { enabled: innerWidth > 900 },
        fontSize: 14, wordWrap: 'on', tabSize: 2, scrollBeyondLastLine: false });
      C.editor.addCommand(monaco.KeyMod.CtrlCmd | monaco.KeyCode.KeyS, () => save());
    } catch {
      if (!C) return;
      for (const p of Object.keys(C.files)) C.models[p] = makeModel(p, C.files[p].content);
      edBox.replaceChildren(C.models[C.cur].ta);
      toast('העורך המלא לא נטען — עורך פשוט', true);
    }
    addEventListener('keydown', onKey);
    addEventListener('beforeunload', onLeave);
    tabs(); bar(); refresh();
  }

  function onKey(e) { if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's' && C) { e.preventDefault(); save(); } }
  function onLeave(e) { if (C && Object.keys(C.files).some(isDirty)) { e.preventDefault(); e.returnValue = ''; } }

  function intro(host) {
    host.replaceChildren(h('section', { class: 'summary code-intro' },
      h('h2', {}, '</> הקוד של העמוד'),
      h('p', {}, 'שלושה קבצים: התבנית (HTML), העיצוב (CSS) והתנהגות (JS) — כמו ב-VS Code. נוצרים מהעמוד כמו שהוא עכשיו, וממשיכים לקבל את ההגדרות מהסטודיו ואת המילים מ-📚 מילים.'),
      h('p', { class: 'hint' }, 'עד שיוצרים אותם — העמוד משתמש בתבנית המובנית ובקבצים של ערכת הנושא באתר. אפשר תמיד לחזור לזה (↩️).'),
      h('button', { type: 'button', class: 'btn primary', onclick: async e => {
        e.target.disabled = true;
        try { await api('code_create', {}); toast('הקבצים נוצרו'); view(host); } catch (err) { toast(err.message, true); e.target.disabled = false; }
      } }, '📝 יצירת קבצי הקוד מהעמוד')));
  }

  function tabs() {
    C.root.querySelector('.code-files').replaceChildren(...Object.keys(NAMES).filter(p => C.files[p]).map(p =>
      h('button', { type: 'button', role: 'tab', class: 'code-tab' + (p === C.cur ? ' on' : ''), 'aria-selected': String(p === C.cur), onclick: () => open(p) },
        h('span', { dir: 'ltr' }, p), isDirty(p) ? h('span', { class: 'dot', 'aria-label': 'לא נשמר' }, '●') : null)));
  }

  function open(p) {
    C.cur = p;
    sessionStorage.setItem('kol-code-file', p);
    if (C.editor) C.editor.setModel(C.models[p].m);
    else C.root.querySelector('.code-ed').replaceChildren(C.models[p].ta);
    tabs(); bar();
  }

  function bar() {
    if (!C?.root) return;
    const st = C.root.querySelector('.code-st');
    const d = isDirty(C.cur);
    st.textContent = C.saving ? 'שומר…' : d ? 'לא נשמר' : 'נשמר ✓';
    st.className = 'chip code-st ' + (d ? 'warn' : 'ok');
    tabs();
  }

  async function save(path = C?.cur, quiet = false) {
    if (!C || !isDirty(path)) return true;
    const content = C.models[path].get();
    C.saving = true; bar();
    try {
      const r = await api('code_save', { path, content, rev: C.files[path].rev });
      C.files[path] = { ...C.files[path], content, rev: r.rev };
      if (!quiet) { toast('נשמר'); refresh(); }
      return true;
    } catch (e) {
      toast(e.message, true);
      return false;
    } finally { if (C) { C.saving = false; bar(); } }
  }

  // התצוגה: הטיוטה עם הקבצים השמורים (אותו רינדור כמו בסטודיו), כולל ה-JS
  async function refresh() {
    if (!C?.frame || (!C.root.classList.contains('show-pv') && innerWidth < 1000)) return;
    try {
      const d = await api('draft');
      const r = await api('render', { page: d.page });
      if (!C) return;
      const esc = s => (s || '').replace(/<\/(script|style)/gi, '<\\/$1');
      C.frame.srcdoc = `<!doctype html><html lang="he" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">`
        + (r.fonts ? `<link rel="stylesheet" href="${r.fonts}">` : '')
        + `<style>body{margin:0;font-family:system-ui,Arial,sans-serif}${esc(r.css)}</style></head><body>${r.html}`
        + (r.js ? `<script>${esc(r.js)}<\/script>` : '') + '</body></html>';
    } catch (e) { toast(e.message, true); }
  }

  async function history() {
    const p = C.cur;
    const body = h('div', {}, h('p', { class: 'empty' }, 'טוען…'));
    sheet(`🕘 ${p}`, body);
    try {
      const { history: list } = await api('code_history', undefined, `&path=${encodeURIComponent(p)}`);
      body.replaceChildren(
        h('p', { class: 'hint' }, 'כל שמירה קודמת. "טעינה" מחזירה את התוכן לעורך — ואז שמירה.'),
        list.length ? h('ul', { class: 'list' }, list.map(x => h('li', {}, h('button', { type: 'button', class: 'use-row', onclick: async () => {
          try {
            const { item } = await api('code_history_get', undefined, `&id=${x.id}`);
            C.models[p].set(item.content);
            $('#sheet').close(); toast('נטען — לשמור כדי שייכנס'); bar();
          } catch (e) { toast(e.message, true); }
        } }, h('span', { class: 'use-tx' }, h('b', {}, new Date(x.at.slice(0, 20)).toLocaleString('he-IL', { dateStyle: 'short', timeStyle: 'short', timeZone: 'Asia/Jerusalem' })),
          h('small', {}, `${x.by_name} · ${Math.round(x.size / 1024)}KB`)), h('span', { class: 'use-go', 'aria-hidden': 'true' }, '‹')))))
          : h('p', { class: 'empty' }, 'עוד אין שמירות קודמות.'));
    } catch (e) { body.replaceChildren(h('p', { class: 'error' }, e.message)); }
  }

  function help() {
    sheet('❓ המשתנים של התבנית', h('p', { class: 'hint' }, 'ב-lexicon.html וב-lexicon.css. ההגדרות מגיעות מהפאנל של המילון בסטודיו, והמילים מ-📚 מילים.'),
      h('dl', { class: 'code-help' }, Object.entries(C.help).flatMap(([k, v]) => [h('dt', {}, k), h('dd', { dir: 'ltr' }, v)])));
  }

  async function reset() {
    if (!confirm('לחזור לתבנית המובנית ולקבצים של ערכת הנושא? הקבצים נשמרים בהיסטוריה, ואפשר ליצור אותם שוב.')) return;
    try { await api('code_reset', {}); toast('חזר לתבנית המובנית'); const host = C.host; C = null; view(host); } catch (e) { toast(e.message, true); }
  }

  // יציאה מהלשונית: מה שלא נשמר — נשמר (תבנית שבורה לא נשמרת, ומקבלים הודעה)
  function unmount() {
    if (!C) return;
    Object.keys(C.files).filter(isDirty).forEach(p => save(p, true));
    C.editor?.dispose();
    Object.values(C.models).forEach(m => m.m?.dispose());
    removeEventListener('keydown', onKey);
    removeEventListener('beforeunload', onLeave);
    C = null;
  }

  return { view, unmount };
})();

// ── עבודה מצ'אט ──────────────────────────────────────────
const Agent = (() => {
  async function sheetView() {
    const body = h('div', { class: 'agent' }, h('p', { class: 'empty' }, 'טוען…'));
    sheet('🤖 עבודה מצ\'אט', body);
    let d;
    try { d = await api('agent_tokens'); } catch (e) { body.replaceChildren(h('p', { class: 'error' }, e.message)); return; }
    const label = h('input', { maxlength: 60, placeholder: 'למשל: Claude במחשב' });
    const out = h('div');
    const draw = tokens => body.replaceChildren(
      h('p', {}, 'סקיל שמעתיקים לצ\'אט (Claude). יש בו שם משתמש וסיסמה שעובדים רק כאן: הצ\'אט עורך את הטיוטה, המילים וקבצי הקוד — ',
        h('b', {}, 'בלי פרסום'), ', בלי גישה לאתר ובלי שום דבר אחר. הפרסום נשאר אצלכם.'),
      h('div', { class: 'toolbar' }, h('label', { class: 'f', style: 'flex:1' }, h('span', {}, 'שם לכניסה'), label),
        h('button', { type: 'button', class: 'btn primary', style: 'align-self:end', onclick: create }, '➕ יצירת סקיל')),
      out,
      h('h3', { class: 'sec-title' }, 'כניסות פעילות'),
      tokens.length ? h('ul', { class: 'list' }, tokens.map(t => h('li', { class: 'w-topic' },
        h('span', { class: 'use-tx' }, h('b', { class: 'one' }, `${t.label} · ${t.id}`),
          h('small', {}, `${t.mine ? '' : t.name + ' · '}נוצרה ${when(t.created_at)} · ${t.last_used ? 'שימוש אחרון ' + when(t.last_used) : 'עוד לא בשימוש'}${t.expired ? ' · פגה' : ''}`)),
        h('button', { type: 'button', class: 'btn sm ghost danger', onclick: async () => {
          if (!confirm(`לבטל את "${t.label}"? הצ'אט שמשתמש בה יפסיק לעבוד מיד.`)) return;
          try { draw((await api('agent_token_revoke', { id: t.id })).tokens); toast('בוטלה'); } catch (e) { toast(e.message, true); }
        } }, '✕ ביטול')))) : h('p', { class: 'hint' }, 'אין כניסות פעילות.'),
      h('p', { class: 'hint' }, 'כל כניסה פגה אחרי 90 יום. כל מה שהצ\'אט עושה — ביומן, עם 🤖 ליד השם.'));
    async function create() {
      try {
        const r = await api('agent_token_create', { label: label.value });
        const ta = h('textarea', { class: 'code-area', dir: 'auto', rows: 10, readonly: true }, r.skill);
        out.replaceChildren(h('div', { class: 'summary' },
          h('p', {}, h('b', { style: 'color:var(--ok)' }, '✓ נוצרה. '), 'הסיסמה מוצגת ', h('b', {}, 'רק עכשיו'), ' — להעתיק את הסקיל ולשמור אותו.'),
          h('dl', { class: 'cells' }, h('div', {}, h('dt', {}, 'שם משתמש'), h('dd', { dir: 'ltr' }, r.user)), h('div', {}, h('dt', {}, 'סיסמה'), h('dd', { dir: 'ltr' }, r.password))),
          h('div', { class: 'row-btns wrap' },
            h('button', { type: 'button', class: 'btn primary', onclick: async () => {
              try { await navigator.clipboard.writeText(r.skill); toast('הסקיל הועתק — להדביק בצ\'אט'); } catch { ta.select(); toast('סמנו והעתיקו ידנית', true); }
            } }, '📋 העתקת הסקיל'),
            h('button', { type: 'button', class: 'btn ghost', onclick: () => {
              const a = h('a', { href: URL.createObjectURL(new Blob([r.skill], { type: 'text/markdown' })), download: 'SKILL.md' });
              a.click(); URL.revokeObjectURL(a.href);
            } }, '⬇️ SKILL.md')),
          ta));
        label.value = '';
        draw(r.tokens);                     // out — אותו אלמנט, עם הסקיל שבתוכו
      } catch (e) { toast(e.message, true); }
    }
    draw(d.tokens);
  }
  return { sheet: sheetView };
})();
