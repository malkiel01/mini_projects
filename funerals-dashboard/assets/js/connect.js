// מסך "חיבור ל-ChatGPT" — פרוסה 08: שלושה מסלולים. ראשון (09) — חיבור ישיר (MCP + OAuth): הכתובת, הצעדים והחיבורים הפעילים.
// שני — פרויקט ChatGPT (📋 ההנחיות לפרויקט, 📤 ייצוא, ואז 📥 במסך התיקים).
// בקפל "מתקדם": אסימונים אישיים (tokens.php) וההעתקות של GPT עם Actions — ההנחיות, קובץ ה-API והאסימון.
(function () {
  'use strict';
  const $ = id => document.getElementById(id);
  let csrf = '', data = null, freshToken = '';

  function node(tag, cls, text) {
    const e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text !== undefined && text !== null) e.textContent = String(text);
    return e;
  }
  function toast(text, error = false) {
    const el = $('toast'); el.textContent = text; el.classList.toggle('err', error); el.hidden = false;
    clearTimeout(toast.t); toast.t = setTimeout(() => { el.hidden = true; }, error ? 5000 : 2600);
  }
  async function request(action, body = null) {
    const params = new URLSearchParams({action});
    const opts = {method: body ? 'POST' : 'GET', credentials: 'same-origin', headers: {}};
    if (body) { opts.headers['Content-Type'] = 'application/json'; opts.headers['X-CSRF-Token'] = csrf; opts.body = JSON.stringify(body); }
    const res = await fetch('tokens.php?' + params, opts);
    const out = await res.json().catch(() => ({error: 'תגובת שרת לא תקינה'}));
    if (!res.ok) throw new Error(out.error || 'שגיאה בבקשה');
    return out;
  }
  async function copy(text, what) {
    try { await navigator.clipboard.writeText(text); }
    catch (e) {
      const t = node('textarea'); t.value = text; t.setAttribute('readonly', ''); t.style.position = 'fixed'; t.style.opacity = '0';
      document.body.append(t); t.select(); const ok = document.execCommand('copy'); t.remove();
      if (!ok) { toast('ההעתקה נכשלה — לסמן ולהעתיק ידנית', true); return; }
    }
    toast(what + ' הועתק');
  }
  const fmt = iso => iso ? new Date(iso).toLocaleDateString('he-IL', {day: 'numeric', month: 'numeric', year: '2-digit'}) : '—';
  const stateChip = {active: ['פעיל', 'ok'], expired: ['פג', 'warn'], revoked: ['בוטל', 'bad']};

  function render() {
    $('topSub').textContent = data.org.name + ' · ' + data.role_name;
    $('newForm').hidden = !data.can_create;
    $('noRole').hidden = data.can_create;
    $('listTitle').textContent = data.can_manage ? 'האסימונים בארגון' : 'האסימונים שלי';
    const list = $('tokens'); list.replaceChildren();
    if (!data.tokens.length) list.append(node('li', 'empty', 'אין עדיין אסימונים'));
    for (const t of data.tokens) {
      const li = node('li', 'card tok' + (t.state === 'active' ? '' : ' off'));
      const body = node('div', 'body');
      const l1 = node('div', 'l1'); l1.append(node('span', '', t.name));
      const [label, cls] = stateChip[t.state]; l1.append(node('span', 'chip ' + cls, label));
      const parts = [];
      if (!t.mine) parts.push(t.owner || '');
      parts.push('מסתיים ב-' + t.hint, 'שימוש אחרון ' + fmt(t.last_used_at), t.state === 'revoked' ? 'בוטל ' + fmt(t.revoked_at) : 'עד ' + fmt(t.expires_at));
      body.append(l1, node('div', 'l2', parts.filter(Boolean).join(' · ')));
      li.append(body);
      if (t.state === 'active') {
        const b = node('button', 'btn sm ghost danger', 'ביטול'); b.type = 'button';
        b.setAttribute('aria-label', 'ביטול האסימון ' + t.name);
        b.addEventListener('click', () => revoke(t));
        li.append(b);
      }
      list.append(li);
    }
    $('mcpUrl').textContent = data.mcp_url;
    const conns = $('connections'); conns.replaceChildren();
    if (!data.connections.length) conns.append(node('li', 'empty', 'אין חיבורים פעילים'));
    for (const g of data.connections) {
      const li = node('li', 'card tok');
      const body = node('div', 'body');
      const l1 = node('div', 'l1'); l1.append(node('span', '', g.client_name));
      const parts = [data.org.name];
      if (!g.mine) parts.push(g.owner || '');
      parts.push('שימוש אחרון ' + fmt(g.last_used_at), 'מ-' + fmt(g.created_at));
      body.append(l1, node('div', 'l2', parts.filter(Boolean).join(' · ')));
      const b = node('button', 'btn sm ghost danger', 'ניתוק'); b.type = 'button';
      b.setAttribute('aria-label', 'ניתוק ' + g.client_name);
      b.addEventListener('click', () => disconnect(g));
      li.append(body, b);
      conns.append(li);
    }
    const ing = $('ingest');
    ing.hidden = !data.ingest;
    if (data.ingest) ing.textContent = data.ingest.exists
      ? 'הקליטה הישנה (ingest.php) פעילה · שימוש אחרון: ' + fmt(data.ingest.last_used_at)
      : 'הקליטה הישנה (ingest.php) — אין אסימון';
  }
  async function load() {
    try {
      data = await request('me');
      csrf = data.csrf;
      $('app').hidden = false; $('fatal').hidden = true;
      render();
    } catch (e) { $('fatal').textContent = e.message; $('fatal').hidden = false; $('app').hidden = true; }
  }
  async function revoke(t) {
    if (!confirm('לבטל את "' + t.name + '"? צ\'אט שמשתמש בו יפסיק לעבוד מיד.')) return;
    try { await request('revoke', {id: t.id}); toast('האסימון בוטל'); await load(); } catch (e) { toast(e.message, true); }
  }
  async function disconnect(g) {
    if (!confirm('לנתק את "' + g.client_name + '"? הצ\'אט יפסיק לגשת לתיקים מיד, ואפשר להתחבר מחדש.')) return;
    try { await request('disconnect', {id: g.id}); toast('החיבור נותק'); await load(); } catch (e) { toast(e.message, true); }
  }
  $('newForm').addEventListener('submit', async ev => {
    ev.preventDefault();
    const name = $('tName').value.trim();
    if (!name) { toast('נדרש שם לאסימון', true); $('tName').focus(); return; }
    const days = +document.querySelector('input[name="days"]:checked').value;
    $('createBtn').disabled = true;
    try {
      const out = await request('create', {name, days});
      freshToken = out.token;
      $('freshToken').textContent = freshToken; $('fresh').hidden = false; $('copyToken').disabled = false;
      $('tName').value = '';
      await load();
      $('fresh').scrollIntoView({block: 'center', behavior: 'smooth'});
    } catch (e) { toast(e.message, true); }
    finally { $('createBtn').disabled = false; }
  });
  $('copyMcp').addEventListener('click', () => data && copy(data.mcp_url, 'הכתובת'));
  $('copyProject').addEventListener('click', () => data && copy(data.project_prompt, 'ההנחיות לפרויקט'));
  const now = new Date();
  $('expMonth').value = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0');
  // 📤 — אותו ייצוא של מסך התיקים (api.php?action=export, הרשאת case.export), כדי שכל הצעדים יהיו במסך אחד
  $('exportMonth').addEventListener('click', async () => {
    const month = $('expMonth').value;
    if (!/^\d{4}-\d{2}$/.test(month)) { toast('לבחור חודש', true); return; }
    $('exportMonth').disabled = true;
    try {
      const res = await fetch('api.php?' + new URLSearchParams({action: 'export', month}), {credentials: 'same-origin'});
      const out = await res.json().catch(() => ({error: 'תגובת שרת לא תקינה'}));
      if (!res.ok) throw new Error(res.status === 403 ? 'אין לך הרשאת ייצוא בארגון הזה' : (out.error || 'הייצוא נכשל'));
      const blob = new Blob([JSON.stringify(out, null, 2)], {type: 'application/json'});
      const url = URL.createObjectURL(blob), a = node('a'); a.href = url; a.download = 'funerals-' + month + '.json';
      document.body.append(a); a.click(); a.remove(); URL.revokeObjectURL(url);
      toast(out.records.length ? 'הורד: ' + out.records.length + ' תיקים — להעלות לפרויקט' : 'אין תיקים בחודש הזה');
    } catch (e) { toast(e.message, true); }
    finally { $('exportMonth').disabled = false; }
  });
  $('copyPrompt').addEventListener('click', () => data && copy(data.prompt, 'ההנחיות'));
  $('copyApi').addEventListener('click', () => data && copy(data.openapi_url, 'קובץ ה-API'));
  $('copyToken').addEventListener('click', () => freshToken ? copy(freshToken, 'האסימון') : toast('קודם ליצור אסימון', true));
  $('copyFresh').addEventListener('click', () => copy(freshToken, 'האסימון'));
  load();
})();
