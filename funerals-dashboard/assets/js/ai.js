/* פרוסה 06 — AI מובנה: 💬 בתיק ובמסך הראשי, כרטיסי הצעה עם ✓ / ✗, ולשונית "בינה מלאכותית" ב-⚙️ הגדרות.
 * עצמאי מ-app.js: מוסיף את הכפתורים והחלונות בעצמו, וקורא ל-api.php (ai_*) עם CSRF מ-me.
 * את מזהה התיק הפתוח הוא מקבל מהאירוע fd:case של app.js. שום כתיבה לתיק לא קורית כאן — רק ✓ שולח ai_decide. */
(() => {
  'use strict';
  const $ = id => document.getElementById(id);
  const node = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text !== undefined) n.textContent = text; return n; };
  let csrf = '', status = null, caseId = '', thread = null, ctxCase = '', busy = false;

  async function api(action, data = null, params = {}) {
    if (data !== null && !csrf) { const me = await api('me'); csrf = me.csrf || ''; }
    const opts = {method: data === null ? 'GET' : 'POST', credentials: 'same-origin', headers: {}};
    if (data !== null) { opts.headers['Content-Type'] = 'application/json'; opts.headers['X-CSRF-Token'] = csrf; opts.body = JSON.stringify(data); }
    const res = await fetch('api.php?' + new URLSearchParams({action, ...params}), opts);
    const body = await res.json().catch(() => ({error: 'תגובת שרת לא תקינה'}));
    if (!res.ok) { const e = new Error(body.error || 'שגיאה בבקשה'); e.status = res.status; e.body = body; throw e; }
    return body;
  }

  /* ===== החלון ===== */
  const dlg = node('dialog'); dlg.id = 'aiDialog'; dlg.setAttribute('aria-labelledby', 'aiTitle');
  dlg.innerHTML = `<form method="dialog" class="ai-form">
    <header class="sheet-head">
      <button type="button" class="icon-btn" data-close aria-label="סגירה">✕</button>
      <h2 id="aiTitle">💬 עוזר</h2>
      <button type="button" class="btn ghost sm" id="aiNew">שיחה חדשה</button>
    </header>
    <div class="sheet-body ai-body" id="aiLog" aria-live="polite"></div>
    <div class="ai-compose">
      <div class="ai-meta"><span id="aiCount"></span></div>
      <div class="ai-row">
        <textarea id="aiText" rows="2" maxlength="4000" placeholder="למשל: מה חסר בתיק? / תמלא מהקבצים מה שאפשר" aria-label="הודעה לעוזר"></textarea>
        <button type="button" class="btn primary" id="aiSend" aria-label="שליחה">שליחה</button>
      </div>
    </div>
  </form>`;
  document.body.append(dlg);
  const log = $('aiLog');

  function bubble(role, text) {
    const b = node('div', 'ai-msg ' + (role === 'user' ? 'me' : 'bot'), text);
    log.append(b); return b;
  }
  function note(text, cls = '') { const n = node('p', 'ai-note ' + cls, text); log.append(n); return n; }
  function scrollEnd() { log.scrollTop = log.scrollHeight; }

  /** כרטיס הצעה: לפני ← אחרי, מאיזה קובץ, ✓ / ✗. */
  function card(p) {
    const c = node('section', 'ai-card'); c.dataset.id = p.id;
    c.append(node('h3', '', p.card.title || 'הצעת עדכון'));
    if (p.card.from_file) c.append(node('p', 'ai-warn', '⚠️ מבוסס על קובץ שהעוזר קרא — לבדוק מול המסמך לפני אישור.'));
    if (p.card.source) { const src = node('p', 'muted ai-src', 'מקור: '); const b = node('bdi', '', p.card.source); b.dir = 'auto'; src.append(b); c.append(src); }
    const t = node('div', 'ai-rows');
    (p.card.rows || []).forEach(r => {
      const row = node('div', 'ai-r');
      row.append(node('span', 'ai-l', r.label), node('span', 'ai-b', r.before || '—'), node('span', 'ai-arrow', '←'), node('span', 'ai-a', r.after || '—'));
      t.append(row);
    });
    c.append(t);
    (p.card.converted || []).forEach(x => c.append(node('p', 'ai-warn', '⚠️ ' + x.name + ': ' + x.reason)));
    const foot = node('div', 'ai-actions');
    paintCard(c, foot, p);
    c.append(foot);
    log.append(c);
  }
  function paintCard(c, foot, p) {
    foot.replaceChildren();
    const label = {applied: '✓ נשמר — לאימות במשרד', rejected: '✗ נדחה', deciding: '…',
      stale: '⚠️ התיק השתנה מאז ההצעה — לא נשמר. אפשר לבקש הצעה חדשה.'}[p.status];
    if (p.status === 'failed') foot.append(node('span', 'ai-state failed', '⛔ לא נשמר' + (p.message ? ': ' + p.message : '') + ' · '));
    if (p.status !== 'pending' && p.status !== 'failed') { foot.append(node('span', 'ai-state ' + p.status, label || p.status)); return; }
    const yes = node('button', 'btn primary sm', p.status === 'failed' ? '↻ לנסות שוב' : '✓ אישור'); yes.type = 'button';
    const no = node('button', 'btn ghost sm', '✗ דחייה'); no.type = 'button';
    const decide = async approve => {
      yes.disabled = no.disabled = true;
      try {
        const r = await api('ai_decide', {id: p.id, approve});
        if (r.status >= 400 && r.result && r.result.error) r.proposal.message = r.result.error;
        paintCard(c, foot, r.proposal);
        if (approve && r.proposal.status === 'applied') document.dispatchEvent(new CustomEvent('fd:changed', {detail: {id: p.case_id}}));
      } catch (e) {
        if (e.status === 409 && e.body && e.body.proposal) { paintCard(c, foot, e.body.proposal); return; }
        yes.disabled = no.disabled = false; note(e.message, 'err');
      }
    };
    yes.addEventListener('click', () => decide(true)); no.addEventListener('click', () => decide(false));
    foot.append(yes, no);
  }

  function paintCount() {
    $('aiCount').textContent = status ? `${status.used} מתוך ${status.cap} שיחות החודש` : '';
  }
  function paintNoKey() {
    log.replaceChildren();
    const p = node('div', 'empty');
    p.append(node('p', '', 'לא הוגדר מפתח — פנה למנהל'));
    if (status && status.can_manage) {
      const b = node('button', 'btn ghost sm', '⚙️ הגדרות בינה מלאכותית'); b.type = 'button';
      b.addEventListener('click', () => { dlg.close(); openSettingsAi(); });
      p.append(b);
    }
    log.append(p);
    $('aiText').disabled = $('aiSend').disabled = true;
  }

  async function open(forCase) {
    ctxCase = forCase || '';
    $('aiTitle').textContent = ctxCase ? '💬 עוזר — התיק הזה' : '💬 עוזר — החודש';
    log.replaceChildren(); thread = null;
    dlg.showModal();
    try { status = await api('ai_status'); } catch (e) { note(e.message, 'err'); return; }
    paintCount();
    if (!status.has_key) { paintNoKey(); return; }
    $('aiText').disabled = $('aiSend').disabled = false;
    if (!status.tools_ready) note('העוזר עונה, אבל עוד לא מחובר לתיקים (ממתין לחיבור ה-API).', 'muted');
    try {
      const r = await api('ai_thread', null, ctxCase ? {case: ctxCase} : {});
      if (r.thread) { thread = r.thread.id; r.thread.messages.forEach(m => bubble(m.role, m.text)); (r.thread.proposals || []).forEach(card); }
    } catch (e) { note(e.message, 'err'); }
    if (!log.children.length) note(ctxCase ? 'שאלו על התיק: "מה חסר?", "תמלא מהקבצים מה שאפשר".' : 'שאלו על החודש: "מה חסר בכהן?", "אילו תיקים לא הושלמו?"', 'muted');
    scrollEnd(); $('aiText').focus();
  }

  async function send() {
    const ta = $('aiText'); const text = ta.value.trim();
    if (!text || busy) return;
    busy = true; $('aiSend').disabled = true;
    bubble('user', text); ta.value = '';
    const wait = note('…חושב', 'muted'); scrollEnd();
    try {
      const r = await api('ai_chat', {text, thread: thread || '', case_id: thread ? '' : ctxCase});
      wait.remove();
      if (!r.ok) { note(r.message, 'err'); if (r.code === 'no_key') paintNoKey(); }
      else {
        thread = r.thread;
        if (r.reply) bubble('bot', r.reply);
        (r.proposals || []).forEach(card);
        if (status) { status.used = r.used; paintCount(); }
      }
    } catch (e) { wait.remove(); note(e.message, 'err'); ta.value = ta.value || text; }
    busy = false; $('aiSend').disabled = false; scrollEnd();
  }
  $('aiSend').addEventListener('click', send);
  $('aiText').addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); send(); } });
  $('aiNew').addEventListener('click', () => { thread = null; log.replaceChildren(); note('שיחה חדשה.', 'muted'); $('aiText').focus(); });

  /* ===== 💬 במסך הראשי ובתיק ===== */
  const mainBtn = node('button', 'icon-btn', '💬'); mainBtn.type = 'button'; mainBtn.id = 'aiBtn';
  mainBtn.setAttribute('aria-label', 'עוזר AI — החודש'); mainBtn.title = 'עוזר AI';
  const top = document.querySelector('header.top'); if (top) top.insertBefore(mainBtn, $('settingsBtn'));
  mainBtn.addEventListener('click', () => open(''));
  const caseBtn = node('button', 'icon-btn', '💬'); caseBtn.type = 'button'; caseBtn.id = 'aiCaseBtn'; caseBtn.hidden = true;
  caseBtn.setAttribute('aria-label', 'עוזר AI — התיק הזה'); caseBtn.title = 'עוזר AI לתיק';
  const head = document.querySelector('#editDialog .sheet-head'); if (head) head.insertBefore(caseBtn, head.querySelector('[type="submit"]'));
  caseBtn.addEventListener('click', () => { if (caseId) open(caseId); });
  document.addEventListener('fd:case', e => { caseId = (e.detail && e.detail.id) || ''; caseBtn.hidden = !caseId; });

  /* ===== הצעת סוג לקובץ חדש (06 §2.3) — על שורת הקובץ ב"קבצים ב-Dropbox" ===== */
  document.addEventListener('fd:files', async e => {
    const d = e.detail || {}; const want = d.id;
    if (!want || !(d.files || []).some(f => !f.kind)) return;
    try { if (!status) status = await api('ai_status'); } catch (_) { return; }
    if (!status.has_key) return;
    let r; try { r = await api('ai_file_kinds', {case_id: want}); } catch (_) { return; }
    if (want !== caseId) return;
    Object.entries(r.suggestions || {}).forEach(([id, sg]) => {
      if (sg.status !== 'pending') return;
      const li = document.querySelector(`#dbxFiles li[data-file-id="${CSS.escape(id)}"]`); if (!li || li.querySelector('.ai-kind')) return;
      const bar = node('div', 'ai-kind');
      bar.append(node('span', '', `🤖 נראה כמו ${sg.label} — לשייך?`));
      if (sg.reason) bar.title = sg.reason;
      if (r.can_decide) {
        const yes = node('button', 'btn primary sm', '✓'); yes.type = 'button'; yes.setAttribute('aria-label', 'לשייך כ' + sg.label);
        const no = node('button', 'btn ghost sm', '✗'); no.type = 'button'; no.setAttribute('aria-label', 'לא לשייך');
        const go = async approve => {
          yes.disabled = no.disabled = true;
          try {
            const res = await api('ai_file_kind_decide', {file: Number(id), approve});
            bar.replaceChildren(node('span', 'muted', approve ? `✓ שויך: ${res.kind}` + (res.check && res.check.ok ? ` · "${res.check.label}" — לאימות` : '') : '✗ לא שויך'));
            if (approve && res.check && res.check.ok) document.dispatchEvent(new CustomEvent('fd:changed', {detail: {id: want}}));
          } catch (err) { yes.disabled = no.disabled = false; bar.title = err.message; }
        };
        yes.addEventListener('click', () => go(true)); no.addEventListener('click', () => go(false));
        bar.append(yes, no);
      }
      li.append(bar);
    });
  });

  /* ===== ⚙️ הגדרות ← בינה מלאכותית (מנהל) ===== */
  const tabs = document.querySelector('#settingsDialog .seg-tabs');
  const tab = node('button', '', 'בינה מלאכותית'); tab.type = 'button'; tab.setAttribute('role', 'tab'); tab.dataset.aitab = '1'; tab.hidden = true;
  const sec = node('section'); sec.id = 'sAi'; sec.hidden = true;
  if (tabs) { tabs.append(tab); tabs.parentNode.append(sec); }
  const others = () => [...document.querySelectorAll('#settingsDialog .sheet-body > section')].filter(s => s !== sec);
  function showAiTab(on) {
    sec.hidden = !on;
    if (on) { others().forEach(s => s.hidden = true); document.querySelectorAll('#settingsDialog [data-stab]').forEach(b => b.setAttribute('aria-selected', 'false')); }
    tab.setAttribute('aria-selected', String(on));
    if (on) paintSettings();
  }
  tab.addEventListener('click', () => showAiTab(true));
  document.querySelectorAll('#settingsDialog [data-stab]').forEach(b => b.addEventListener('click', () => showAiTab(false)));
  $('settingsBtn') && $('settingsBtn').addEventListener('click', async () => {
    showAiTab(false);
    try { status = await api('ai_status'); tab.hidden = !status.can_manage; } catch (e) { tab.hidden = true; }
  });
  function openSettingsAi() { $('settingsBtn').click(); setTimeout(() => { if (!tab.hidden) showAiTab(true); }, 300); }

  async function paintSettings() {
    sec.replaceChildren(node('p', 'muted', 'טוען…'));
    let s; try { s = await api('ai_settings'); } catch (e) { sec.replaceChildren(node('p', 'ai-note err', e.message)); return; }
    const g = node('div', 'group');
    g.append(node('h3', '', 'הספק והמודל'));
    const f = node('div', 'fields two');
    const prov = node('select'); s.providers.forEach(p => { const o = node('option', '', p.name); o.value = p.id; o.selected = p.id === s.provider; prov.append(o); });
    const model = node('select');
    const fillModels = () => { model.replaceChildren(); (s.providers.find(p => p.id === prov.value) || s.providers[0]).models.forEach(m => { const o = node('option', '', m.name); o.value = m.id; o.selected = m.id === s.model; model.append(o); }); };
    fillModels(); prov.addEventListener('change', fillModels);
    const lab = (t, el, wide) => { const l = node('label', 'f' + (wide ? ' wide' : '')); l.append(node('span', '', t), el); return l; };
    f.append(lab('ספק', prov), lab('מודל', model));
    g.append(f, node('p', 'hint', 'מומלץ: Claude Opus 5.5. מודל זול יותר — תשובות מהירות, פחות מדויקות.'));

    g.append(node('h3', '', 'מפתח API'));
    const kh = node('p', 'hint');
    if (s.has_key) {
      const tail = node('bdi', '', '…' + s.key_hint); tail.dir = 'ltr';
      kh.append('מוגדר, מסתיים ב-', tail, s.key_check ? ' · בדיקה אחרונה: ' + ({ok: 'תקין', invalid: 'לא תקין', no_credit: 'אין יתרה', error: 'שגיאה'}[s.key_check] || s.key_check) : '');
      if (s.key_source === 'owner') kh.append(' · משתמשים במפתח ברירת המחדל של הבעלים. מפתח של הארגון שיוגדר כאן — יגבר עליו.');
    } else kh.textContent = 'לא הוגדר מפתח. המפתח נשמר מוצפן, ולא מוצג שוב.';
    g.append(kh);
    const key = node('input'); key.type = 'password'; key.autocomplete = 'off'; key.dir = 'ltr'; key.placeholder = s.has_key ? 'מפתח חדש (ריק = להשאיר)' : 'sk-ant-…';
    key.setAttribute('aria-label', 'מפתח API');
    const kf = node('div', 'fields'); kf.append(lab('מפתח', key, true)); g.append(kf);
    const test = node('button', 'btn ghost sm', 'בדיקת מפתח'); test.type = 'button'; test.disabled = !s.has_key;
    const del = node('button', 'btn ghost sm danger', 'הסרת המפתח'); del.type = 'button'; del.hidden = !s.has_key;
    const testOut = node('span', 'muted');
    const tr = node('div', 'row-btns ai-test'); tr.append(test, del, testOut); g.append(tr);
    del.hidden = !s.has_key || s.key_source !== 'org';
    del.addEventListener('click', async () => {
      if (!confirm('להסיר את המפתח? העוזר יפסיק לעבוד עד שיוגדר מפתח חדש.')) return;
      try { await api('ai_key_remove', {}); await paintSettings(); } catch (e) { testOut.textContent = e.message; }
    });

    g.append(node('h3', '', 'שימוש'));
    const cap = node('input'); cap.type = 'number'; cap.min = '0'; cap.max = '100000'; cap.value = s.monthly_cap; cap.inputMode = 'numeric';
    const cf = node('div', 'fields two'); cf.append(lab('תקרה חודשית (שיחות)', cap), node('div', 'f', '')); cf.lastChild.append(node('span', '', 'החודש'), node('b', '', `${s.used} מתוך ${s.monthly_cap}`));
    g.append(cf);
    const tcap = node('input'); tcap.type = 'number'; tcap.min = '0'; tcap.step = '100000'; tcap.value = s.token_cap; tcap.inputMode = 'numeric';
    const tf = node('div', 'fields two'); tf.append(lab('תקרת טוקנים בחודש', tcap), node('div', 'f', ''));
    tf.lastChild.append(node('span', '', 'החודש'), node('b', '', `${Number(s.tokens).toLocaleString('he-IL')} מתוך ${Number(s.token_cap).toLocaleString('he-IL')}`));
    g.append(tf);
    const auto = node('input'); auto.type = 'checkbox'; auto.checked = s.auto_approve;
    const al = node('label', 'ai-check'); al.append(auto, node('span', '', 'אישור אוטומטי — לשמור הצעות בלי ✓ (עדיין "לאימות", לא "אומת"). הצעה שמבוססת על קובץ — תמיד בכרטיס.'));
    g.append(al);
    if (!s.tools_ready) g.append(node('p', 'hint', 'העוזר עוד לא מחובר לתיקים — ממתין לחיבור ה-API החיצוני.'));
    const save = node('button', 'btn primary', 'שמירה'); save.type = 'button';
    const sr = node('div', 'row-btns'); sr.append(save); g.append(sr);
    // פרוסה 12: מפתח ברירת המחדל של הבעלים (א11) — לכל ארגון שלא הגדיר מפתח משלו. רק למפתח (owner_key_hint מגיע רק אליו).
    const parts = [g];
    if (s.owner_key_hint !== undefined) {
      const o = node('div', 'group'); o.append(node('h3', '', 'מפתח ברירת המחדל (לכל הארגונים)'));
      const oh = node('p', 'hint');
      if (s.owner_key_hint) { const t = node('bdi', '', '…' + s.owner_key_hint); t.dir = 'ltr'; oh.append('מוגדר, מסתיים ב-', t, '. ארגון בלי מפתח משלו משתמש בו.'); }
      else oh.textContent = 'לא הוגדר. ארגון בלי מפתח משלו — העוזר והזיהוי לא יעבדו בו.';
      const ok = node('input'); ok.type = 'password'; ok.autocomplete = 'off'; ok.dir = 'ltr'; ok.placeholder = 'sk-ant-…'; ok.setAttribute('aria-label', 'מפתח ברירת המחדל');
      const of = node('div', 'fields'); of.append(lab('מפתח חדש', ok, true));
      const osave = node('button', 'btn ghost', 'שמירת המפתח'); osave.type = 'button';
      const odel = node('button', 'btn ghost sm danger', 'הסרה'); odel.type = 'button'; odel.hidden = !s.owner_key_hint;
      const oout = node('span', 'muted');
      const orow = node('div', 'row-btns'); orow.append(osave, odel, oout);
      o.append(oh, of, orow); parts.push(o);
      osave.addEventListener('click', async () => {
        if (!ok.value.trim()) { oout.textContent = 'נדרש מפתח'; return; }
        osave.disabled = true;
        try { await api('ai_owner_key_save', {key: ok.value.trim()}); ok.value = ''; await paintSettings(); }
        catch (e) { osave.disabled = false; oout.textContent = e.message; }
      });
      odel.addEventListener('click', async () => {
        if (!confirm('להסיר את מפתח ברירת המחדל? ארגונים בלי מפתח משלהם יפסיקו לקבל בינה.')) return;
        try { await api('ai_owner_key_remove', {}); await paintSettings(); } catch (e) { oout.textContent = e.message; }
      });
    }
    sec.replaceChildren(...parts);

    save.addEventListener('click', async () => {
      save.disabled = true;
      try {
        await api('ai_settings_save', {provider: prov.value, model: model.value, monthly_cap: Number(cap.value), auto_approve: auto.checked, token_cap: Number(tcap.value), key: key.value.trim()});
        key.value = ''; await paintSettings();
      } catch (e) { save.disabled = false; testOut.textContent = e.message; }
    });
    test.addEventListener('click', async () => {
      test.disabled = true; testOut.textContent = 'בודק…';
      try { const r = await api('ai_key_test', {}); testOut.textContent = ({ok: '✅ ', invalid: '⛔ ', no_credit: '⚠️ '}[r.result] || '⛔ ') + r.message; }
      catch (e) { testOut.textContent = e.message; }
      test.disabled = false;
    });
  }
})();
