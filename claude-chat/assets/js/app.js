(() => {
  'use strict';
  const $ = id => document.getElementById(id);
  const msgsEl = $('msgs'), logEl = $('log'), form = $('form'), text = $('text');
  const sendBtn = $('send'), stopBtn = $('stop'), modelEl = $('model'), setupEl = $('setup');
  const STORE = 'claude-chat:v1';
  const MAX_CHARS = 55000, MAX_MSGS = 40;
  let state = { model: 'claude-sonnet-5-5', messages: [] };
  let ctrl = null;

  // ---- שמירה בדפדפן (לא קריטי: הכול עובד גם בלעדיה) ----
  try {
    const s = JSON.parse(localStorage.getItem(STORE) || 'null');
    if (s && Array.isArray(s.messages)) state = { model: s.model || state.model, messages: s.messages };
  } catch (e) { /* אין אחסון — ממשיכים בלי */ }
  const save = () => { try { localStorage.setItem(STORE, JSON.stringify(state)); } catch (e) { /* ignore */ } };
  modelEl.value = state.model;
  if (modelEl.value !== state.model) state.model = modelEl.value;
  modelEl.addEventListener('change', () => { state.model = modelEl.value; save(); });

  // ---- תצוגה: הוצאת HTML בטוחה, ואז בלוקי קוד, קוד בשורה ופסקאות ----
  const esc = s => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  function render(md) {
    const parts = esc(md).split(/```/);
    return parts.map((p, i) => {
      if (i % 2 === 1) {                           // בתוך גדר קוד (גם אם לא נסגרה עדיין)
        const code = p.replace(/^[^\n]*\n/, '');
        return '<pre><code>' + code.replace(/\n$/, '') + '</code></pre>';
      }
      return p.split(/\n{2,}/).filter(x => x.trim()).map(x =>
        '<p>' + x.replace(/`([^`\n]+)`/g, '<code>$1</code>').replace(/\n/g, '<br>') + '</p>').join('');
    }).join('');
  }

  function bubble(role, content, extra) {
    const el = document.createElement('div');
    el.className = 'msg ' + role + (extra ? ' ' + extra : '');
    if (role === 'user') el.textContent = content; else el.innerHTML = render(content);
    $('empty')?.remove();
    msgsEl.appendChild(el);
    logEl.scrollTop = logEl.scrollHeight;
    return el;
  }
  function paint() {
    msgsEl.innerHTML = '<div class="empty" id="empty">שאל כל דבר. השיחה נשמרת בדפדפן הזה בלבד.</div>';
    state.messages.forEach(m => bubble(m.role, m.content));
  }

  // ---- בדיקת מצב: האם המפתח בשרת ----
  fetch('api.php?action=health', { credentials: 'same-origin', cache: 'no-store' })
    .then(r => r.ok ? r.json() : Promise.reject(r.status))
    .then(h => {
      if (h.key_file && h.key_valid) return;
      setupEl.hidden = false;
      setupEl.textContent = h.key_file
        ? 'הקובץ claude-secrets/anthropic.key נמצא אבל התוכן לא נראה כמו מפתח (אמור להתחיל ב-sk-ant-, שורה אחת, בלי רווחים).'
        : 'מפתח ה-API לא נמצא בשרת. צור את הקובץ claude-secrets/anthropic.key בתיקיית הבית.';
    })
    .catch(() => { /* השגיאה תוצג בשליחה */ });

  // ---- שליחה וקבלת זרם ----
  function setBusy(b) {
    sendBtn.hidden = b; stopBtn.hidden = !b; text.disabled = b; modelEl.disabled = b;
  }
  function trimmed() {
    const m = state.messages.slice(-MAX_MSGS);
    let total = m.reduce((n, x) => n + x.content.length, 0);
    while (m.length > 1 && (total > MAX_CHARS || m[0].role !== 'user')) total -= m.shift().content.length;
    return m;
  }

  async function send(content) {
    state.messages.push({ role: 'user', content });
    bubble('user', content);
    setBusy(true);
    ctrl = new AbortController();
    const el = bubble('assistant', '', 'cursor');
    let acc = '', failed = null;
    try {
      const res = await fetch('api.php?action=chat', {
        method: 'POST', credentials: 'same-origin', signal: ctrl.signal,
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'claude-chat' },
        body: JSON.stringify({ model: state.model, messages: trimmed() }),
      });
      if (!res.ok || !(res.headers.get('content-type') || '').includes('text/event-stream')) {
        let msg = 'שגיאה ' + res.status;
        try { msg = (await res.json()).error || msg; } catch (e) { /* לא JSON */ }
        throw new Error(msg);
      }
      const reader = res.body.getReader(), dec = new TextDecoder();
      let buf = '';
      for (;;) {
        const { done, value } = await reader.read();
        if (done) break;
        buf += dec.decode(value, { stream: true });
        let i;
        while ((i = buf.indexOf('\n\n')) >= 0) {
          const block = buf.slice(0, i); buf = buf.slice(i + 2);
          const ev = (block.match(/^event: (.*)$/m) || [])[1];
          const dm = block.match(/^data: (.*)$/m);
          if (!dm) continue;
          let d; try { d = JSON.parse(dm[1]); } catch (e) { continue; }
          if (ev === 'content_block_delta' && d.delta && d.delta.type === 'text_delta') {
            acc += d.delta.text; el.innerHTML = render(acc);
            logEl.scrollTop = logEl.scrollHeight;
          } else if (ev === 'error') {
            failed = (d.error && d.error.message) || d.message || 'שגיאה מול Claude';
          }
        }
      }
    } catch (e) {
      if (e.name !== 'AbortError') failed = e.message || 'שגיאת רשת';
    }
    el.classList.remove('cursor');
    if (failed) {
      // לא שומרים תשובה שנכשלה; ההודעה חוזרת לתיבה כדי שאפשר יהיה לשלוח שוב
      state.messages.pop();
      msgsEl.lastElementChild.previousElementSibling?.remove();
      el.className = 'msg assistant err'; el.textContent = failed;
      text.value = content;
    } else if (acc) {
      state.messages.push({ role: 'assistant', content: acc });
    } else {
      el.remove();                                   // נעצר לפני שהתחיל תוכן
      state.messages.pop(); msgsEl.lastElementChild?.remove(); text.value = content;
    }
    save(); ctrl = null; setBusy(false); grow(); text.focus();
  }

  form.addEventListener('submit', ev => {
    ev.preventDefault();
    const v = text.value.trim();
    if (!v || ctrl) return;
    text.value = ''; grow(); send(v);
  });
  stopBtn.addEventListener('click', () => ctrl && ctrl.abort());
  $('new').addEventListener('click', () => {
    if (ctrl) ctrl.abort();
    if (state.messages.length && !confirm('למחוק את השיחה?')) return;
    state.messages = []; save(); paint();
  });

  // Enter שולח במחשב; בטלפון — שורה חדשה
  text.addEventListener('keydown', ev => {
    if (ev.key === 'Enter' && !ev.shiftKey && matchMedia('(pointer: fine)').matches) {
      ev.preventDefault(); form.requestSubmit();
    }
  });
  function grow() { text.style.height = 'auto'; text.style.height = Math.min(text.scrollHeight + 2, innerHeight * 0.4) + 'px'; }
  text.addEventListener('input', grow);

  paint(); grow();
})();
