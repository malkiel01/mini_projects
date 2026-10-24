'use strict';
// תוסף התשלומים — ממשק הניהול. מדבר רק עם admin.php (מאחורי השער). בלי ספריות.

const $ = (s, el = document) => el.querySelector(s);
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const signed = (v, opts) => (v < 0 ? '‎-' : '') + '₪' + Math.abs(v).toLocaleString('he-IL', opts);
const nis = ag => signed(Number(ag || 0) / 100, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const nisMicros = m => signed(Number(m || 0) / 1e6, { maximumFractionDigits: 6 });
const when = t => t ? new Date(t * 1000).toLocaleString('he-IL', { dateStyle: 'short', timeStyle: 'short' }) : '—';
const KIND = { internal: 'אפליקציה שלי', server: 'אתר / מערכת עם שרת', static: 'אתר בלי שרת', mobile: 'אפליקציה לטלפון' };
const TAX = { exempt: 'עוסק פטור', licensed: 'עוסק מורשה', company: 'חברה' };
const PTYPE = { plan: 'מנוי', seat: 'כמות במנוי', unit: 'לפי יחידה', metered: 'שימוש מדוד', credit_pack: 'חבילת קרדיט', addon: 'תוספת' };
const INTERVAL = { month: 'לחודש', year: 'לשנה', week: 'לשבוע', none: '' };
const STATE = { active: ['פעיל', 'ok'], trialing: ['ניסיון', 'ok'], past_due: ['באיחור', 'warn'], paused: ['מושהה', 'warn'], canceled: ['בוטל', 'bad'], none: ['אין מנוי', ''] };

let me = null;
const cache = { merchants: [] };

async function api(action, body = {}) {
  const r = await fetch('admin.php?action=' + action, {
    method: 'POST', credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body),
  });
  let j = {};
  try { j = await r.json(); } catch { j = { error: 'תשובה לא תקינה מהשרת (' + r.status + ')' }; }
  if (!r.ok) throw Object.assign(new Error(j.error || 'שגיאה'), { code: j.code });
  return j;
}

function toast(msg, err = false) {
  const el = $('.toast'); el.textContent = msg; el.classList.toggle('err', err); el.hidden = false;
  clearTimeout(toast.t); toast.t = setTimeout(() => { el.hidden = true; }, 2800);
}
const fail = e => toast(e.message || String(e), true);
const chip = (txt, cls = '') => `<span class="chip ${cls}">${esc(txt)}</span>`;
const empty = txt => `<li><p class="empty">${esc(txt)}</p></li>`;

// ---------- גיליון (חלון עריכה / פרטים) ----------
const sheet = $('#sheet');
let onSave = null;
function openSheet(title, html, save = null, saveLabel = 'שמירה') {
  $('#sheet-title').textContent = title;
  $('#sheet-body').innerHTML = html;
  const b = $('#sheet-save'); b.hidden = !save; b.textContent = saveLabel;
  onSave = save;
  if (!sheet.open) sheet.showModal();
}
sheet.addEventListener('submit', async e => {
  e.preventDefault();
  if (!onSave) return sheet.close();
  const btn = $('#sheet-save'); btn.disabled = true;
  try { if ((await onSave(new FormData($('#sheet form')))) !== false) sheet.close(); } catch (err) { fail(err); } finally { btn.disabled = false; }
});
document.addEventListener('click', e => { if (e.target.closest('[data-close]')) e.target.closest('dialog').close(); });
(() => { const vv = window.visualViewport; if (!vv) return;
  const set = () => document.documentElement.style.setProperty('--vvh', Math.round(vv.height) + 'px');
  vv.addEventListener('resize', set); set(); })();

// ---------- לשוניות ----------
const loaders = {};
function show() {
  if (sheet.open) sheet.close();
  const id = (location.hash || '#overview').slice(1);
  const target = $('#p-' + id) ? id : 'overview';
  for (const p of document.querySelectorAll('.panel')) p.hidden = p.id !== 'p-' + target;
  for (const a of document.querySelectorAll('.tabs a')) {
    if (a.getAttribute('href') === '#' + target) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current');
  }
  loaders[target]?.().catch(fail);
}
addEventListener('hashchange', show);

// ---------- סקירה ----------
const STAGES = [
  ['שלד', 'רישום, מסך, מסד ריק, החרגה בפריסה', 'done'],
  ['ליבה בלי כסף', 'מערכות וקודים, לקוחות, מוצרים ומחירים, זכאות, שימוש, ארנק', 'done'],
  ['דף תשלום ו"החשבון שלי"', 'מנויים חודשיים ושנתיים, ניסיון, מבצעים, ביטול, הודעות לפי החוק, סולק מדומה', 'done'],
  ['סולק אמיתי ראשון', 'מורנינג והסליקה שלה — כולל הקבלה או החשבונית עם כל תשלום', 'done'],
  ['חשבוניות', 'מסמכים לתשלומים שלא דרך מורנינג (מזומן, העברה, סולקים אחרים), זיכויים', 'done'],
  ['ספריות, קישורים וכללים', 'ספריות קוד, קישורי תשלום, כפתור, מנוע הכללים, תיעוד', 'done'],
  ['מנויים מאושרים', 'אזור ניהול לכל מי שהמנהל מאשר', ''],
  ['מגדלור מתחבר', 'האפליקציה הראשונה, בצ\'אט שלה', ''],
  ['עלייה לאוויר', 'תשלום אמיתי קטן והחזר, בדיקה משפטית — הרשימה למעלה', 'now'],
  ['התרחבות', 'קארדקום ✓ · סולקים ואפליקציות נוספים, הוראת קבע, דוחות', 'now'],
];
loaders.overview = async () => {
  const o = await api('overview');
  const cell = (k, v) => `<div><dt>${k}</dt><dd>${v}</dd></div>`;
  $('#ov-cells').innerHTML = cell('מערכות', o.clients) + cell('לקוחות', o.customers) + cell('מנויים פעילים', o.subscriptions)
    + cell('יתרות בארנקים (חי)', nis(o.wallets)) + cell('שימוש החודש (חי)', nis(o.usage_month)) + cell('עוסקים', o.merchants);
  const alerts = [];
  if (!o.merchants) alerts.push(chip('אין עוסק — הגדרות ‹', 'warn'));
  if (o.webhooks_failed) alerts.push(chip(o.webhooks_failed + ' הודעות למערכות נכשלו', 'bad'));
  if (o.refunds_pending) alerts.push(`<a href="#money">${chip(o.refunds_pending + ' החזרים לביצוע בממשק הסולק', 'bad')}</a>`);
  if (o.pending_tenants) alerts.push(chip(o.pending_tenants + ' ממתינים לאישור', 'warn'));
  $('#ov-alerts').innerHTML = alerts.join('');
  $('#stages').innerHTML = STAGES.map(([t, s, st], i) => `<li class="card"><span class="stage-n" aria-hidden="true">${st === 'done' ? '✓' : i + 1}</span>
    <div class="body"><div class="l1"><span>${esc(t)}</span></div><div class="l2">${esc(s)}</div></div>
    ${st === 'now' ? chip('עכשיו', 'warn') : st === 'done' ? chip('הושלם', 'ok') : ''}</li>`).join('');
  renderReady(await api('readiness'));
};
function renderReady(r) {
  $('#ready-n').textContent = r.ready ? '✓ הכול מוכן' : `${r.open} פתוחים`;
  const ICON = { ok: '✓', todo: '○', warn: '!' };
  $('#ready').innerHTML = r.items.map(i => `<li class="card"><span class="stage-n" aria-hidden="true">${ICON[i.state]}</span>
    <div class="body"><div class="l1" style="white-space:normal"><span>${esc(i.title)}</span></div>${i.todo ? `<div class="l2" style="white-space:normal">${esc(i.todo)}</div>` : ''}</div>
    ${i.manual ? `<label class="switch"><input type="checkbox" data-ready="${esc(i.key)}" aria-label="בוצע: ${esc(i.title)}" ${i.state === 'ok' ? 'checked' : ''}><span></span></label>`
      : i.state === 'ok' ? chip('מוכן', 'ok') : chip(i.state === 'warn' ? 'מומלץ' : 'חסר', i.state === 'warn' ? 'warn' : 'bad')}</li>`).join('');
  for (const b of document.querySelectorAll('[data-ready]')) b.addEventListener('change', async () => {
    try { renderReady(await api('readiness_mark', { key: b.dataset.ready, done: b.checked })); } catch (e) { fail(e); }
  });
}

// ---------- עוסקים ----------
async function loadMerchants() { cache.merchants = (await api('merchants')).data; return cache.merchants; }
function merchantForm(m = {}) {
  const opt = (v, l) => `<label><input type="radio" name="tax_status" value="${v}" ${(m.tax_status || 'exempt') === v ? 'checked' : ''}><span>${l}</span></label>`;
  openSheet(m.id ? 'עריכת עוסק' : 'עוסק חדש', `
    <div class="fields">
      <label class="f wide"><span>שם העוסק (כפי שיופיע בקבלה) *</span><input name="name" required maxlength="120" value="${esc(m.name)}"></label>
      <label class="f"><span>ע"מ / ח"פ</span><input name="tax_id" inputmode="numeric" dir="ltr" value="${esc(m.tax_id)}"></label>
      <label class="f"><span>מייל</span><input name="email" type="email" dir="ltr" value="${esc(m.email)}"></label>
      <label class="f"><span>טלפון</span><input name="phone" type="tel" dir="ltr" value="${esc(m.phone)}"></label>
      <label class="f wide"><span>כתובת</span><input name="address" value="${esc(m.address)}"></label>
    </div>
    <fieldset class="seg" aria-label="סוג העוסק">${opt('exempt', 'פטור')}${opt('licensed', 'מורשה')}${opt('company', 'חברה')}</fieldset>
    <p class="hint">פטור — קבלה בלי מע"מ. מורשה וחברה — חשבונית מס/קבלה עם מע"מ 18%.</p>
    ${m.id ? `<div class="group"><h3>חברת סליקה <small>הכסף נכנס ישר לחשבון שלך אצלה</small></h3>
      <ul class="list" id="pa-list"><li><p class="empty">טוען…</p></li></ul>
      <div class="row-btns wrap"><button type="button" class="btn sm ghost" data-pa-new="morning:test">+ מורנינג · בדיקות</button>
      <button type="button" class="btn sm ghost" data-pa-new="cardcom:test">+ קארדקום · בדיקות</button>
      ${me.role === 'owner' ? '<button type="button" class="btn sm ghost" data-pa-new="morning:live">+ מורנינג · כסף אמיתי</button><button type="button" class="btn sm ghost" data-pa-new="cardcom:live">+ קארדקום · כסף אמיתי</button>' : ''}</div>
      <p class="hint">בלי חיבור — סביבת הבדיקות עובדת מול הסולק המדומה, וכסף אמיתי לא נגבה.</p></div>` : ''}`,
  async fd => {
    await api('merchant_save', { id: m.id || '', ...Object.fromEntries(fd) });
    toast('נשמר'); loaders.settings();
  });
  if (m.id) loadProviders(m);
}

const PROV_METHODS = { card: '💳 כרטיס', bit: 'ביט', apple: 'אפל', google: 'גוגל' };
const PROV_NAME = { morning: 'מורנינג', cardcom: 'קארדקום' };
const PROV_FIELDS = {
  morning: [['client_id', 'מזהה הממשק', false], ['client_secret', 'סוד הממשק', true], ['webhook_secret', 'סוד ההודעות (מומלץ)', true]],
  cardcom: [['terminal', 'מספר מסוף', false], ['api_name', 'שם משתמש לממשק', false], ['api_password', 'סיסמת ממשק — להחזרים מכאן', true]],
};
const PROV_HINT = {
  morning: live => live ? 'הקודים מחשבון מורנינג האמיתי שלך (תוכנית "בסט" ומעלה): כלי פיתוח ← מפתחות ממשק.' : 'הקודים מסביבת הניסוי של מורנינג למפתחים — שם לא נגבה כסף אמיתי.',
  cardcom: live => live ? 'בממשק קארדקום: הגדרות ← הגדרות חברה ← ניהול מפתחות ממשק. מספר המסוף — בראש המסך.' : 'מסוף בדיקות מקארדקום (מבקשים מהם) — שם לא נגבה כסף אמיתי.',
};
async function loadProviders(m) {
  const { data } = await api('providers', { merchant: m.id });
  const box = $('#pa-list');
  if (!box) return;
  box.innerHTML = data.length ? data.map(p => `<li class="card" data-pa="${esc(p.id)}" tabindex="0" role="button"><span class="use-ic" aria-hidden="true">🏦</span>
      <div class="body"><div class="l1"><span>${PROV_NAME[p.provider] || esc(p.provider)} · ${p.env === 'live' ? 'כסף אמיתי' : 'בדיקות'}</span></div>
      <div class="l2">${(p.settings.methods || []).map(x => PROV_METHODS[x] || x).join(' · ')}${p.provider === 'cardcom' ? ' · מסמך: ' + (p.settings.documents === 'morning' ? 'מורנינג' : 'קארדקום') : ''}${p.checked_at ? ' · נבדק ' + when(p.checked_at) : ''}</div></div>
      ${p.status !== 'active' ? chip('כבוי', '') : !p.checked_at ? chip('לא נבדק', 'warn') : p.check_error ? chip('תקלה', 'bad') : chip('תקין', 'ok')}</li>`).join('')
    : empty('עוד אין חיבור');
  for (const li of box.querySelectorAll('[data-pa]')) li.addEventListener('click', () => providerSheet(m, data.find(x => x.id === li.dataset.pa)));
  for (const b of document.querySelectorAll('[data-pa-new]')) b.addEventListener('click', () => {
    const [provider, env] = b.dataset.paNew.split(':');
    providerSheet(m, { provider, env, fields: {}, settings: { methods: ['card', 'bit'], documents: 'cardcom' } });
  });
}

function providerSheet(m, p) {
  const isNew = !p.id;
  const live = p.env === 'live';
  const kind = p.provider || 'morning';
  const f = p.fields || {};
  const field = ([k, label, secret]) => `<label class="f wide"><span>${label}${f[k] ? (secret ? ' (שמור)' : ' (שמור: ' + esc(f[k]) + ')') : ''}</span>
    <input name="${k}" dir="ltr" ${secret ? 'type="password" autocomplete="new-password"' : 'autocomplete="off"'} ${k === 'terminal' ? 'inputmode="numeric"' : ''} placeholder="${f[k] ? 'ריק — בלי שינוי' : ''}"></label>`;
  openSheet(`${PROV_NAME[kind]} · ${live ? 'כסף אמיתי' : 'בדיקות'} — ${m.name}`, `
    <p class="hint">${PROV_HINT[kind](live)}</p>
    <div class="fields">${PROV_FIELDS[kind].map(field).join('')}</div>
    <div class="chips" aria-label="אמצעי תשלום">${Object.entries(PROV_METHODS).map(([k, l]) => `<label><input type="checkbox" name="m_${k}" ${(p.settings.methods || []).includes(k) || k === 'card' ? 'checked' : ''} ${k === 'card' ? 'disabled' : ''}><span>${l}</span></label>`).join('')}</div>
    ${kind === 'cardcom' ? `<fieldset class="seg" aria-label="מי מפיק את הקבלה">
        <label><input type="radio" name="documents" value="cardcom" ${p.settings.documents !== 'morning' ? 'checked' : ''}><span>הקבלה מקארדקום</span></label>
        <label><input type="radio" name="documents" value="morning" ${p.settings.documents === 'morning' ? 'checked' : ''}><span>הקבלה ממורנינג</span></label></fieldset>
      <p class="hint">קארדקום — רק אם במסוף יש מודול מסמכים. מורנינג — צריך גם חיבור מורנינג לעוסק (הסליקה תישאר בקארדקום).</p>` : ''}
    ${!isNew ? `<label class="switch"><input type="checkbox" name="active" ${p.status === 'active' ? 'checked' : ''}><span>פעיל</span></label>
    <div class="group"><h3>בדיקת חיבור</h3>
      ${p.check_error ? `<p class="error">${esc(p.check_error)}</p>` : p.checked_at ? '<p class="hint">✓ החיבור עובד.</p>' : ''}
      <div class="row-btns"><button type="button" class="btn sm primary" id="pa-check">בדיקה עכשיו</button></div></div>
    ${kind === 'morning' ? `<div class="group"><h3>הודעות ממורנינג <small>פעם אחת, בממשק מורנינג</small></h3>
      <p class="hint">כלי פיתוח ← וובהוקים ← הוספה: להדביק את הכתובת, לסמן "מסמך נוצר" ו"תשלום התקבל", ולהגדיר סוד — אותו סוד שבשדה למעלה. כך תשלום נקלט גם אם הלקוח סגר את החלון.</p>
      <input readonly dir="ltr" value="${esc(p.webhook_url)}" aria-label="כתובת ההודעות" onclick="this.select()">
      <div class="row-btns"><button type="button" class="btn sm ghost" id="pa-copy">📋 העתקה</button></div></div>`
      : '<p class="hint">הודעות מקארדקום מגיעות לבד — כתובת ההודעות נשלחת עם כל דף תשלום.</p>'}` : ''}`,
  async fd => {
    const methods = Object.keys(PROV_METHODS).filter(k => k === 'card' || fd.get('m_' + k));
    const creds = Object.fromEntries(PROV_FIELDS[kind].map(([k]) => [k, fd.get(k)]));
    const r = await api('provider_save', { merchant: m.id, provider: kind, env: p.env, ...creds, methods, documents: fd.get('documents') || '',
      ...(isNew ? {} : { status: fd.get('active') ? 'active' : 'disabled' }) });
    const checked = (await api('provider_check', { id: r.provider.id })).provider;
    toast(checked.check_error ? 'נשמר, אבל הבדיקה נכשלה' : 'נשמר והחיבור עובד ✓', !!checked.check_error);
    merchantForm(m);
    return false;
  });
  $('#pa-check')?.addEventListener('click', async () => {
    try { const r = (await api('provider_check', { id: p.id })).provider; toast(r.check_error || 'החיבור עובד ✓', !!r.check_error); providerSheet(m, r); } catch (e) { fail(e); }
  });
  $('#pa-copy')?.addEventListener('click', () => navigator.clipboard.writeText(p.webhook_url).then(() => toast('הועתק')));
}
$('#add-merchant').addEventListener('click', () => merchantForm());

// ---------- הגדרות ----------
loaders.settings = async () => {
  const ms = await loadMerchants();
  $('#mer-list').innerHTML = ms.length ? ms.map(m => `<li class="card" data-mer="${esc(m.id)}" tabindex="0" role="button">
      <span class="use-ic" aria-hidden="true">🏢</span>
      <div class="body"><div class="l1"><span>${esc(m.name)}</span></div><div class="l2">${esc(TAX[m.tax_status])}${m.tax_id ? ' · ' + esc(m.tax_id) : ''}${m.vat_rate > 0 ? ' · מע"מ ' + m.vat_rate / 100 + '%' : ''}</div></div>
      <span class="use-go" aria-hidden="true">‹</span></li>`).join('') : empty('עוד אין עוסק. בלי עוסק אי אפשר לפתוח לקוחות.');
  for (const li of document.querySelectorAll('[data-mer]')) li.addEventListener('click', () => merchantForm(ms.find(m => m.id === li.dataset.mer)));

  $('#tenants-box').hidden = me.role !== 'owner';
  if (me.role === 'owner') {
    const ts = (await api('tenants')).data;
    const ST = { active: ['פעיל', 'ok'], pending: ['ממתין', 'warn'], suspended: ['מושהה', 'bad'], closed: ['סגור', ''] };
    $('#ten-list').innerHTML = ts.map(t => `<li class="card">
        <span class="avatar" aria-hidden="true">${esc((t.name || '?').slice(0, 2))}</span>
        <div class="body"><div class="l1"><span>${esc(t.name || t.email)}</span></div><div class="l2">${esc(t.email)}${t.role === 'owner' ? ' · הבעלים' : ''}</div></div>
        ${chip(ST[t.status][0], ST[t.status][1])}
        ${t.role !== 'owner' ? `<button class="btn sm ghost" data-ten="${esc(t.id)}" data-to="${t.status === 'active' ? 'suspended' : 'active'}">${t.status === 'active' ? 'השהיה' : 'אישור'}</button>` : ''}</li>`).join('');
    for (const b of document.querySelectorAll('[data-ten]')) b.addEventListener('click', async () => {
      if (b.dataset.to === 'suspended' && !confirm('להשהות? כל קודי הגישה שלו ייעצרו מיד.')) return;
      await api('tenant_status', { id: b.dataset.ten, status: b.dataset.to }).catch(fail); loaders.settings();
    });
  }
  loadClock().catch(fail);
  loadRules().catch(fail);
  loadBackups().catch(fail);
  const au = (await api('audit')).data;
  $('#audit-list').innerHTML = au.length ? au.map(a => `<li class="card"><div class="body">
      <div class="l1"><span>${esc(a.action)}</span><span class="t">${when(a.created_at)}</span></div>
      <div class="l2">${esc(a.object_type)} ${esc(a.object_id)}</div></div></li>`).join('') : empty('עוד אין פעולות.');
};

// ---------- מערכות ----------
loaders.systems = async () => {
  const cs = (await api('clients')).data;
  $('#client-list').innerHTML = cs.length ? cs.map(c => `<li class="card" data-client="${esc(c.id)}" tabindex="0" role="button">
      <span class="use-ic" aria-hidden="true">${c.kind === 'internal' ? '🧩' : c.kind === 'mobile' ? '📱' : '🌐'}</span>
      <div class="body"><div class="l1"><span>${esc(c.name)}</span></div>
        <div class="l2">${esc(c.id)} · ${esc(c.merchant_name || 'בלי עוסק')} · ${c.customers} לקוחות · ${c.products} מוצרים</div></div>
      ${c.status !== 'active' ? chip('מושבתת', 'bad') : c.live_ok ? chip('חי', 'ok') : chip('בדיקות', 'warn')}</li>`).join('')
    : empty('עוד אין מערכות. "+ מערכת" — ותקבל קוד גישה.');
  for (const li of document.querySelectorAll('[data-client]')) li.addEventListener('click', () => clientSheet(li.dataset.client).catch(fail));
};

$('#add-client').addEventListener('click', async () => {
  const ms = cache.merchants.length ? cache.merchants : await loadMerchants();
  openSheet('מערכת חדשה', `
    <div class="fields">
      <label class="f"><span>שם *</span><input name="name" required maxlength="120" placeholder="מגדלור"></label>
      <label class="f"><span>מזהה (אנגלית) *</span><input name="id" required dir="ltr" pattern="[a-z0-9][a-z0-9-]{1,59}" placeholder="guarded-browser"></label>
      <label class="f"><span>סוג</span><select name="kind">${Object.entries(KIND).map(([k, l]) => `<option value="${k}">${l}</option>`).join('')}</select></label>
      <label class="f"><span>עוסק</span><select name="merchant"><option value="">— בהמשך —</option>${ms.map(m => `<option value="${esc(m.id)}">${esc(m.name)}</option>`).join('')}</select></label>
    </div>
    <p class="hint">המזהה קבוע. אחרי השמירה — קוד גישה לסביבת בדיקות.</p>`,
  async fd => {
    const { client } = await api('client_create', Object.fromEntries(fd));
    toast('נוצרה'); loaders.systems(); clientSheet(client.id); return false;
  });
});

async function clientSheet(id, newKey = null) {
  const [d, ms] = await Promise.all([api('client_get', { id }), cache.merchants.length ? cache.merchants : loadMerchants()]);
  const c = d.client;
  const keyRow = k => `<li class="card"><div class="body"><div class="l1"><span dir="ltr">${esc(k.id.slice(0, 14))}…${esc(k.last4)}</span><span class="t">${when(k.created_at)}</span></div>
      <div class="l2">${{ sk: 'קוד סודי', pk: 'קוד גלוי', whsec: 'סוד webhook' }[k.kind]} · ${k.env === 'live' ? 'חי' : 'בדיקות'}${k.last_used ? ' · שימוש אחרון ' + when(k.last_used) : ''}${k.expires_at && k.status === 'active' ? ' · פג ' + when(k.expires_at) : ''}</div></div>
      ${k.status === 'active' ? `<button type="button" class="icon-btn" data-revoke="${esc(k.id)}" aria-label="ביטול הקוד">🗑️</button>` : chip(k.status === 'expired' ? 'פג' : 'בוטל', 'bad')}</li>`;
  const priceTxt = (p, pr) => p.type === 'metered'
    ? `${nisMicros(pr.amount_micros)} לכל ${Number(pr.package_size).toLocaleString('he-IL')} ${esc(p.unit_label || 'יחידות')}${pr.prepaid ? ' · מראש' : ' · בדיעבד'}${pr.included ? ' · כלול ' + pr.included : ''}`
    : `${nis(pr.amount)} ${INTERVAL[pr.interval]}${pr.intro_amount !== null ? ' · מבצע ' + nis(pr.intro_amount) + ' × ' + pr.intro_periods : ''}${pr.trial_days ? ' · ניסיון ' + pr.trial_days + ' ימים' : ''}${pr.renewal === 'consent' ? ' · חידוש באישור' : ''}`;
  const prodRow = p => `<li class="card" data-prod="${esc(p.id)}" tabindex="0" role="button"><div class="body">
      <div class="l1"><span>${esc(p.name)}</span><span class="t" dir="ltr">${esc(p.code)}</span></div>
      <div class="l2">${esc(PTYPE[p.type])} · ${p.prices.length ? priceTxt(p, p.prices[0]) + (p.prices.length > 1 ? ` · עוד ${p.prices.length - 1} גרסאות פעילות` : '') : 'אין מחיר'}</div></div>
      <span class="use-go" aria-hidden="true">‹</span></li>`;
  openSheet(c.name, `
    <div class="fields">
      <label class="f"><span>שם</span><input name="name" value="${esc(c.name)}"></label>
      <label class="f"><span>עוסק</span><select name="merchant"><option value="">— אין —</option>${ms.map(m => `<option value="${esc(m.id)}" ${m.id === c.merchant ? 'selected' : ''}>${esc(m.name)}</option>`).join('')}</select></label>
      <label class="f wide"><span>כתובת התנאים (מופיעה בדף התשלום) — חובה לכסף אמיתי</span><input name="terms_url" dir="ltr" type="url" value="${esc(c.terms_url)}" placeholder="https://…/terms"></label>
      <label class="f wide"><span>כתובת מדיניות הפרטיות</span><input name="privacy_url" dir="ltr" type="url" value="${esc(c.privacy_url)}"></label>
      <label class="f wide"><span>כתובת webhook (לאן נשלחות הודעות)</span><input name="webhook_url" dir="ltr" type="url" value="${esc(c.webhook_url)}" placeholder="https://…/api.php?action=payments_webhook"></label>
      <label class="f wide"><span>כתובת מפתח Ed25519 (רק למי שחותם כך)</span><input name="key_url" dir="ltr" type="url" value="${esc(c.key_url)}"></label>
      <label class="f wide"><span>אתרים מותרים לקוד הגלוי</span><input name="origins" dir="ltr" value="${esc((c.origins || []).join(' '))}" placeholder="https://example.co.il"></label>
    </div>
    <label class="switch"><input type="checkbox" name="active" ${c.status === 'active' ? 'checked' : ''}><span>פעילה</span></label>
    ${me.role === 'owner' ? `<label class="switch"><input type="checkbox" name="live_ok" ${c.live_ok ? 'checked' : ''}><span>מאושרת לכסף אמיתי (חי)</span></label>` : ''}

    <div class="group"><h3>קודי גישה</h3>
      <div class="row-btns wrap">
        <button type="button" class="btn sm ghost" data-newkey="sk:test">+ סודי · בדיקות</button>
        <button type="button" class="btn sm ghost" data-newkey="sk:live" ${c.live_ok ? '' : 'disabled'}>+ סודי · חי</button>
        <button type="button" class="btn sm ghost" data-newkey="pk:test">+ גלוי</button>
        <button type="button" class="btn sm ghost" data-newkey="whsec:test">+ סוד webhook</button>
      </div>
      <div id="new-key"></div>
      <ul class="list">${d.keys.length ? d.keys.map(keyRow).join('') : empty('אין קודים')}</ul>
    </div>

    <div class="group"><h3>מוצרים ומחירים</h3>
      <ul class="list">${d.products.length ? d.products.map(prodRow).join('') : empty('אין מוצרים')}</ul>
      <div class="row-btns"><button type="button" class="btn sm ghost" id="add-prod">+ מוצר</button></div>
    </div>

    <div class="group"><h3>קישורי תשלום <small>בלי קוד — לוואטסאפ, למייל, לאתר</small></h3>
      <ul class="list" id="link-list"><li><p class="empty">טוען…</p></li></ul>
      <div class="row-btns"><button type="button" class="btn sm ghost" id="add-link">+ קישור</button></div>
    </div>

    <div class="group"><h3>הודעות למערכת <small>${d.webhooks.length}</small></h3>
      <div class="row-btns"><button type="button" class="btn sm ghost" id="wh-test" ${c.webhook_url ? '' : 'disabled'}>שליחת בדיקה</button></div>
      <ul class="list">${d.webhooks.length ? d.webhooks.map(w => `<li class="card"><div class="body"><div class="l1"><span dir="ltr">${esc(w.type)}</span><span class="t">${when(w.created_at)}</span></div>
        <div class="l2">ניסיון ${w.attempt} · קוד ${w.response_code || '—'}</div></div>
        ${w.status === 'delivered' ? chip('נמסר', 'ok') : w.status === 'failed' ? `<button type="button" class="btn sm ghost" data-retry="${esc(w.id)}">שוב</button>` : chip('בתור', 'warn')}</li>`).join('') : empty('עוד לא נשלחו')}</ul>
    </div>

    <div class="group"><h3>יומן קריאות <small>${d.log.length}</small></h3>
      <ul class="list">${d.log.length ? d.log.map(l => `<li class="card"><div class="body"><div class="l1"><span dir="ltr">${esc(l.action)}</span><span class="t">${when(l.created_at)}</span></div>
        <div class="l2">${l.env === 'live' ? 'חי' : 'בדיקות'} · ${l.ms}ms${l.code ? ' · ' + esc(l.code) : ''}</div></div>${chip(l.status, l.status < 300 ? 'ok' : 'bad')}</li>`).join('') : empty('עוד אין קריאות')}</ul>
    </div>`,
  async fd => {
    const o = Object.fromEntries(fd);
    await api('client_update', { id, name: o.name, merchant: o.merchant, webhook_url: o.webhook_url, key_url: o.key_url, origins: o.origins, terms_url: o.terms_url, privacy_url: o.privacy_url,
      status: fd.get('active') ? 'active' : 'disabled', ...(me.role === 'owner' ? { live_ok: !!fd.get('live_ok') } : {}) });
    toast('נשמר'); loaders.systems();
  });

  const body = $('#sheet-body');
  for (const b of body.querySelectorAll('[data-newkey]')) b.addEventListener('click', async () => {
    const [kind, env] = b.dataset.newkey.split(':');
    if (!confirm('ליצור קוד חדש? הקודם מאותו סוג ימשיך לעבוד עוד 7 ימים.')) return;
    try {
      const { key } = await api('key_create', { client: id, kind, env });
      await clientSheet(id, key);
    } catch (e) { fail(e); }
  });
  if (newKey) {
    $('#new-key').innerHTML = `<div class="summary"><div class="line"><b>הקוד מוצג פעם אחת בלבד</b></div>
      <input readonly dir="ltr" value="${esc(newKey.key)}" onclick="this.select()" aria-label="הקוד">
      <div class="row-btns"><button type="button" class="btn sm primary" id="copy-key">📋 העתקה</button></div></div>`;
    $('#copy-key').addEventListener('click', () => navigator.clipboard.writeText(newKey.key).then(() => toast('הועתק')));
    $('#new-key').scrollIntoView({ block: 'center' });
  }
  for (const b of body.querySelectorAll('[data-revoke]')) b.addEventListener('click', async () => {
    if (!confirm('לבטל את הקוד? מערכת שמשתמשת בו תיעצר מיד.')) return;
    await api('key_revoke', { id: b.dataset.revoke }).catch(fail); clientSheet(id);
  });
  for (const li of body.querySelectorAll('[data-prod]')) li.addEventListener('click', () => productSheet(id, d.products.find(p => p.id === li.dataset.prod)));
  loadLinks(c, d.products);
  $('#add-prod').addEventListener('click', () => productSheet(id, null));
  $('#wh-test')?.addEventListener('click', async () => {
    try { const { event } = await api('webhook_test', { client: id, env: 'test' }); toast(event.status === 'delivered' ? 'נמסר ✓' : 'לא נמסר (קוד ' + (event.response_code || 0) + ')', event.status !== 'delivered'); clientSheet(id); } catch (e) { fail(e); }
  });
  for (const b of body.querySelectorAll('[data-retry]')) b.addEventListener('click', async () => { await api('webhook_retry', { id: b.dataset.retry }).catch(fail); clientSheet(id); });
}

async function loadLinks(c, products) {
  const { data } = await api('links', { client: c.id });
  const box = $('#link-list');
  if (!box) return;
  box.innerHTML = data.length ? data.map(l => `<li class="card" data-link="${esc(l.id)}" tabindex="0" role="button"><span class="use-ic" aria-hidden="true">🔗</span>
      <div class="body"><div class="l1"><span>${esc(l.title)}</span><span class="t">${l.uses}${l.max_uses ? '/' + l.max_uses : ''} שימושים</span></div>
      <div class="l2">${l.amount_mode === 'custom' ? 'סכום לבחירה ' + nis(l.min_amount) + '–' + nis(l.max_amount) : esc(l.product) + (l.quantity > 1 ? ' × ' + l.quantity : '')}${l.env === 'test' ? ' · בדיקות' : ''}</div></div>
      ${l.active ? '' : chip('כבוי', '')}</li>`).join('') : empty('אין קישורים');
  for (const li of box.querySelectorAll('[data-link]')) li.addEventListener('click', () => linkSheet(c, products, data.find(x => x.id === li.dataset.link)));
  $('#add-link').onclick = () => linkSheet(c, products, null);
}

function linkSheet(c, products, l) {
  const sellable = products.filter(p => ['unit', 'credit_pack'].includes(p.type) && p.prices.length);
  if (!l && !sellable.length) return toast('צריך קודם מוצר לפי יחידה או חבילה, עם מחיר', true);
  const isNew = !l;
  l = l || { title: '', description: '', product: sellable[0].code, quantity: 1, amount_mode: 'fixed', min_amount: 1000, max_amount: 100000, fields: ['name', 'email', 'phone'], max_uses: 0, env: 'test', active: true };
  const FIELDS = { name: 'שם', email: 'מייל', phone: 'טלפון', tax_id: 'ח״פ', note: 'הערה' };
  const embed = l.url ? `<script src="${location.origin + location.pathname.replace(/[^/]*$/, '')}assets/embed.js" async></script>\n<a class="mbe-pay" href="${l.url}">לתשלום</a>` : '';
  openSheet(isNew ? 'קישור תשלום חדש' : l.title, `
    ${l.url ? `<div class="summary"><div class="line"><b>הקישור</b></div>
      <input readonly dir="ltr" value="${esc(l.url)}" onclick="this.select()" aria-label="הקישור">
      <div id="qr" style="display:grid;place-items:center;padding:8px;background:#fff;border-radius:10px"></div>
      <div class="row-btns wrap"><button type="button" class="btn sm primary" id="copy-link">📋 העתקת הקישור</button>
        <a class="btn sm ghost" href="https://wa.me/?text=${encodeURIComponent(l.title + ' ' + l.url)}" target="_blank" rel="noopener">וואטסאפ</a>
        <button type="button" class="btn sm ghost" id="copy-embed">כפתור לאתר</button></div></div>` : ''}
    <div class="fields">
      <label class="f wide"><span>כותרת *</span><input name="title" required maxlength="120" value="${esc(l.title)}" placeholder="תרומה לבית הכנסת"></label>
      <label class="f wide"><span>תיאור</span><textarea name="description" rows="2">${esc(l.description)}</textarea></label>
      <label class="f"><span>מוצר</span><select name="product">${sellable.map(p => `<option value="${esc(p.code)}" ${p.code === l.product ? 'selected' : ''}>${esc(p.name)} — ${nis(p.prices[0].amount)}</option>`).join('')}</select></label>
      <label class="f"><span>כמות</span><input name="quantity" inputmode="numeric" dir="ltr" value="${l.quantity}"></label>
    </div>
    <fieldset class="seg" aria-label="סכום"><label><input type="radio" name="amount_mode" value="fixed" ${l.amount_mode === 'fixed' ? 'checked' : ''}><span>מחיר המוצר</span></label>
      <label><input type="radio" name="amount_mode" value="custom" ${l.amount_mode === 'custom' ? 'checked' : ''}><span>סכום לבחירה</span></label></fieldset>
    <div class="fields two">
      <label class="f"><span>מינימום (₪)</span><input name="min" inputmode="decimal" dir="ltr" value="${l.min_amount / 100}"></label>
      <label class="f"><span>מקסימום (₪)</span><input name="max" inputmode="decimal" dir="ltr" value="${l.max_amount / 100}"></label>
    </div>
    <p class="hint">מה הלקוח ממלא:</p>
    <div class="chips">${Object.entries(FIELDS).map(([k, t]) => `<label><input type="checkbox" name="f_${k}" ${l.fields.includes(k) ? 'checked' : ''}><span>${t}</span></label>`).join('')}</div>
    <div class="fields">
      <label class="f"><span>מגבלת שימושים (0 — בלי)</span><input name="max_uses" inputmode="numeric" dir="ltr" value="${l.max_uses}"></label>
      <label class="f"><span>אחרי התשלום — לכתובת (רשות)</span><input name="success_url" type="url" dir="ltr" value="${esc(l.success_url || '')}"></label>
    </div>
    ${isNew && c.live_ok ? `<fieldset class="seg" aria-label="סביבה"><label><input type="radio" name="env" value="test" checked><span>בדיקות</span></label><label><input type="radio" name="env" value="live"><span>כסף אמיתי</span></label></fieldset>` : ''}
    ${!isNew ? `<label class="switch"><input type="checkbox" name="active" ${l.active ? 'checked' : ''}><span>פעיל</span></label>` : ''}`,
  async fd => {
    const r = await api('link_save', { id: l.id || '', client: c.id, env: fd.get('env') || l.env, title: fd.get('title'), description: fd.get('description'),
      product: fd.get('product'), quantity: fd.get('quantity'), amount_mode: fd.get('amount_mode'),
      min_amount: Math.round(Number(fd.get('min')) * 100), max_amount: Math.round(Number(fd.get('max')) * 100),
      fields: Object.keys(FIELDS).filter(k => fd.get('f_' + k)), max_uses: fd.get('max_uses') || 0, success_url: fd.get('success_url'),
      active: isNew ? true : !!fd.get('active') });
    toast('נשמר'); linkSheet(c, products, r.link); return false;
  });
  if (l.url) {
    try {
      const qr = qrcode(0, 'M'); qr.addData(l.url); qr.make();
      $('#qr').innerHTML = qr.createSvgTag({ cellSize: 5, margin: 2, scalable: true }).replace('<svg ', '<svg style="width:180px;height:180px" role="img" aria-label="קוד לסריקה" ');
    } catch (e) { $('#qr').hidden = true; }
    $('#copy-link').onclick = () => navigator.clipboard.writeText(l.url).then(() => toast('הועתק'));
    $('#copy-embed').onclick = () => navigator.clipboard.writeText(embed).then(() => toast('קוד הכפתור הועתק — להדביק באתר'));
  }
}

function productSheet(clientId, p) {
  const isNew = !p;
  p = p || { code: '', name: '', type: 'plan', unit_label: '', grants: {}, prices: [] };
  const typeOpts = Object.entries(PTYPE).map(([k, l]) => `<option value="${k}" ${p.type === k ? 'selected' : ''}>${l}</option>`).join('');
  openSheet(isNew ? 'מוצר חדש' : p.name, `
    <div class="fields">
      <label class="f"><span>שם *</span><input name="name" required value="${esc(p.name)}"></label>
      <label class="f"><span>קוד (אנגלית) *</span><input name="code" required dir="ltr" value="${esc(p.code)}" ${isNew ? '' : 'readonly'}></label>
      <label class="f"><span>סוג</span><select name="type">${typeOpts}</select></label>
      <label class="f"><span>יחידה (לשימוש מדוד)</span><input name="unit_label" value="${esc(p.unit_label)}" placeholder="טוקנים"></label>
      <label class="f wide"><span>מה המוצר נותן (JSON)</span><textarea name="grants" rows="3" dir="ltr" placeholder='{"limits":{"devices":3},"features":{"apps":true}}'>${esc(Object.keys(p.grants || {}).length ? JSON.stringify(p.grants) : '')}</textarea></label>
    </div>
    ${isNew ? '' : `<div class="group"><h3>מחירים <small>מחיר לא נערך — כל שינוי הוא גרסה חדשה</small></h3>
      <ul class="list">${p.prices.length ? p.prices.map(pr => `<li class="card"><div class="body"><div class="l1"><span>גרסה ${pr.version}</span></div>
        <div class="l2">${p.type === 'metered' ? nisMicros(pr.amount_micros) + ' / ' + pr.package_size + (pr.prepaid ? ' · מראש' : ' · בדיעבד') + ' · ' + pr.aggregate : nis(pr.amount) + ' ' + INTERVAL[pr.interval] + (pr.intro_amount !== null ? ' · מבצע ' + nis(pr.intro_amount) + ' × ' + pr.intro_periods : '') + (pr.trial_days ? ' · ניסיון ' + pr.trial_days + ' ימים' : '') + (pr.renewal === 'consent' ? ' · חידוש באישור' : '')}</div></div>
        <button type="button" class="btn sm ghost" data-deact="${esc(pr.id)}">הפסקה</button></li>`).join('') : empty('אין מחיר פעיל')}</ul>
      <div class="fields">
        ${p.type === 'metered' ? `
          <label class="f"><span>מחיר לחבילה (₪)</span><input name="price_nis" inputmode="decimal" dir="ltr" placeholder="0.004"></label>
          <label class="f"><span>גודל חבילה</span><input name="package_size" inputmode="numeric" dir="ltr" value="1000"></label>
          <label class="f"><span>כלול בחודש</span><input name="included" inputmode="numeric" dir="ltr" value="0"></label>
          <label class="f"><span>סיכום</span><select name="aggregate"><option value="sum">סכום</option><option value="max">שיא</option><option value="last">אחרון</option><option value="unique">ייחודיים</option></select></label>
          <label class="switch wide"><input type="checkbox" name="prepaid" checked><span>יורד מהקרדיט (מראש). כבוי — חיוב בדיעבד</span></label>`
        : `
          <label class="f"><span>מחיר (₪)</span><input name="price_nis" inputmode="decimal" dir="ltr"></label>
          ${['plan', 'seat', 'addon'].includes(p.type) ? `<label class="f"><span>מחזור</span><select name="interval"><option value="month">חודשי</option><option value="year">שנתי (חידוש רק באישור הלקוח)</option></select></label>
          <label class="f"><span>ימי ניסיון</span><input name="trial_days" inputmode="numeric" dir="ltr" value="0"></label>
          <label class="f"><span>מחיר מבצע (₪, ריק — בלי)</span><input name="intro_nis" inputmode="decimal" dir="ltr"></label>
          <label class="f"><span>מבצע — כמה מחזורים</span><input name="intro_periods" inputmode="numeric" dir="ltr" value="0"></label>` : ''}`}
      </div>
      <div class="row-btns"><button type="button" class="btn sm primary" id="add-price">+ מחיר</button></div></div>`}`,
  async fd => {
    const o = Object.fromEntries(fd);
    await api('product_save', { client: clientId, code: o.code, name: o.name, type: o.type, unit_label: o.unit_label, grants: o.grants || '{}' });
    toast('נשמר'); clientSheet(clientId);
    return false;
  });
  $('#add-price')?.addEventListener('click', async () => {
    const fd = new FormData($('#sheet form'));
    const nisVal = Number(String(fd.get('price_nis') || '').replace(',', '.'));
    if (!(nisVal >= 0) || fd.get('price_nis') === '') return toast('חסר מחיר', true);
    const body = p.type === 'metered'
      ? { amount_micros: Math.round(nisVal * 1e6), package_size: fd.get('package_size'), included: fd.get('included'), aggregate: fd.get('aggregate'), prepaid: !!fd.get('prepaid') }
      : { amount: Math.round(nisVal * 100), interval: fd.get('interval') || 'none', trial_days: fd.get('trial_days') || 0,
          ...(fd.get('intro_nis') ? { intro_amount: Math.round(Number(String(fd.get('intro_nis')).replace(',', '.')) * 100), intro_periods: fd.get('intro_periods') } : {}) };
    try { await api('price_create', { product: p.id, ...body }); toast('מחיר נוסף'); clientSheet(clientId); } catch (e) { fail(e); }
  });
  for (const b of document.querySelectorAll('[data-deact]')) b.addEventListener('click', async () => {
    if (!confirm('להפסיק את המחיר? מנויים קיימים לא מושפעים.')) return;
    await api('price_deactivate', { id: b.dataset.deact }).catch(fail); clientSheet(clientId);
  });
}

// ---------- לקוחות ----------
let qTimer;
$('#cus-q').addEventListener('input', () => { clearTimeout(qTimer); qTimer = setTimeout(() => loaders.customers().catch(fail), 250); });
loaders.customers = async () => {
  const rows = (await api('customers', { q: $('#cus-q').value })).data;
  $('#cus-list').innerHTML = rows.length ? rows.map(r => `<li class="card" data-cus="${esc(r.id)}" tabindex="0" role="button">
      <span class="avatar" aria-hidden="true">${esc((r.name || r.external_id).slice(0, 2))}</span>
      <div class="body"><div class="l1"><span>${esc(r.name || r.external_id)}</span><span class="t">${nis(r.balance)}</span></div>
        <div class="l2">${esc(r.client)} · <span dir="ltr">${esc(r.external_id)}</span>${r.email ? ' · ' + esc(r.email) : ''}</div></div>
      ${r.env === 'test' ? chip('בדיקות', 'warn') : ''}</li>`).join('') : empty($('#cus-q').value ? 'לא נמצא' : 'עוד אין לקוחות. הם נוצרים כשמערכת מחוברת פונה לתוסף.');
  for (const li of document.querySelectorAll('[data-cus]')) li.addEventListener('click', () => customerSheet(li.dataset.cus).catch(fail));
};

async function customerSheet(id) {
  const d = await api('customer_detail', { id });
  const e = d.entitlement;
  const [stTxt, stCls] = STATE[e.state] || [e.state, ''];
  const TX = { topup: 'טעינה', grant: 'מתנה', usage: 'שימוש', reserve: 'שריון', release: 'שחרור', expire: 'פקיעה', refund: 'החזר', adjust: 'תיקון' };
  openSheet(d.customer.name || d.customer.external_id, `
    <div class="summary">
      <div class="line"><span class="muted">יתרה</span> <b>${nis(d.account.balance)}</b>${chip(stTxt, stCls)}${d.customer.env === 'test' ? chip('בדיקות', 'warn') : ''}</div>
      <div class="muted" style="font-size:.85rem">${esc(d.client.name)} · <span dir="ltr">${esc(d.customer.external_id)}</span>${d.customer.email ? ' · ' + esc(d.customer.email) : ''}</div>
      ${Object.keys(e.limits).length ? `<div class="chips">${Object.entries(e.limits).map(([k, v]) => chip(k + ': ' + v)).join('')}</div>` : ''}
    </div>
    <div class="tiles">
      <button type="button" class="tile" data-w="topup"><span class="tile-ic" aria-hidden="true">💰</span>טעינה</button>
      <button type="button" class="tile" data-w="grant"><span class="tile-ic" aria-hidden="true">🎁</span>מתנה</button>
      <button type="button" class="tile" data-w="adjust"><span class="tile-ic" aria-hidden="true">✏️</span>תיקון</button>
      <button type="button" class="tile" id="grant-sub"><span class="tile-ic" aria-hidden="true">📦</span>הענקת מנוי</button>
      <button type="button" class="tile" id="manual-pay"><span class="tile-ic" aria-hidden="true">💵</span>תשלום ידני</button>
    </div>
    <div class="group"><h3>מנויים</h3><ul class="list">${d.subscriptions.length ? d.subscriptions.map(s => `<li class="card"><div class="body">
      <div class="l1"><span>${s.items.map(i => esc(i.name) + (i.quantity > 1 ? ' ×' + i.quantity : '')).join(' + ')}</span></div>
      <div class="l2">${s.source === 'manual' ? 'ידני' : esc(s.source)} · מ-${when(s.current_period_start)}${s.current_period_end ? ' עד ' + when(s.current_period_end) : ''}</div></div>
      ${chip(...(STATE[s.status] || [s.status, '']))}${s.status !== 'canceled' ? `<button type="button" class="icon-btn" data-cancel="${esc(s.id)}" aria-label="ביטול המנוי">🗑️</button>` : ''}</li>`).join('') : empty('אין מנויים')}</ul></div>
    <div class="group"><h3>ארנק</h3><ul class="list">${d.wallet.length ? d.wallet.map(t => `<li class="card"><div class="body">
      <div class="l1"><span>${TX[t.kind] || esc(t.kind)}${t.note ? ' · ' + esc(t.note) : ''}</span><span class="t">${when(t.created_at)}</span></div></div>
      <b dir="ltr" style="color:${t.amount < 0 ? 'var(--danger)' : 'var(--ok)'}">${nisMicros(t.amount)}</b></li>`).join('') : empty('אין תנועות')}</ul></div>
    <div class="group"><h3>שימוש אחרון</h3><ul class="list">${d.usage.length ? d.usage.map(u => `<li class="card"><div class="body">
      <div class="l1"><span dir="ltr">${esc(u.code)}</span><span class="t">${when(u.occurred_at)}</span></div>
      <div class="l2">${Number(u.quantity).toLocaleString('he-IL')}${u.cost_micros ? ' · ' + nisMicros(u.cost_micros) : ''}</div></div></li>`).join('') : empty('אין שימוש')}</ul></div>`);

  const body = $('#sheet-body');
  for (const b of body.querySelectorAll('[data-w]')) b.addEventListener('click', () => walletSheet(d, b.dataset.w));
  $('#manual-pay').addEventListener('click', () => manualPaySheet(d));
  for (const b of body.querySelectorAll('[data-cancel]')) b.addEventListener('click', async () => {
    if (!confirm('לבטל את המנוי עכשיו?')) return;
    await api('subscription_cancel', { id: b.dataset.cancel }).catch(fail); customerSheet(id);
  });
  $('#grant-sub').addEventListener('click', async () => {
    const cd = await api('client_get', { id: d.client.id });
    const plans = cd.products.filter(p => ['plan', 'seat', 'addon'].includes(p.type) && p.prices.length);
    if (!plans.length) return toast('למערכת אין מוצר מנוי עם מחיר', true);
    openSheet('הענקת מנוי — בלי חיוב', `<p class="hint">לבדיקות ולמקרים מיוחדים. הלקוח מקבל את מה שהמוצרים נותנים, בלי תשלום.</p>
      <div class="fields">${plans.map(p => `<label class="f"><span>${esc(p.name)} — כמות</span><input name="q_${esc(p.code)}" inputmode="numeric" dir="ltr" value="0"></label>`).join('')}
      <label class="f"><span>עד תאריך (ריק — ללא הגבלה)</span><input name="until" type="date"></label></div>`,
    async fd => {
      const items = plans.map(p => ({ product: p.code, quantity: Number(fd.get('q_' + p.code) || 0) })).filter(i => i.quantity > 0);
      const until = fd.get('until') ? Math.floor(new Date(fd.get('until') + 'T23:59:59').getTime() / 1000) : '';
      await api('subscription_grant', { customer: id, items, period_end: until });
      toast('הוענק'); customerSheet(id); return false;
    }, 'הענקה');
  });
}

const MANUAL = { cash: 'מזומן', transfer: 'העברה', cheque: 'צ׳ק', bit: 'ביט', other: 'אחר' };
async function manualPaySheet(d) {
  const inv = (await api('invoices')).data.filter(i => i.account === d.account.id && i.status === 'open');
  openSheet('תשלום ידני — ' + (d.customer.name || d.customer.external_id), `
    <p class="hint">כסף שהגיע ישירות אליך. הקבלה או החשבונית יוצאות לבד דרך מורנינג (אם לעוסק יש חיבור).</p>
    <fieldset class="seg grid" style="--cols:5" aria-label="אמצעי">${Object.entries(MANUAL).map(([k, l], i) => `<label><input type="radio" name="method" value="${k}" ${i === 0 ? 'checked' : ''}><span>${l}</span></label>`).join('')}</fieldset>
    ${inv.length ? `<label class="f"><span>על מה</span><select name="purpose"><option value="topup">טעינת יתרה</option>${inv.map(i => `<option value="${esc(i.id)}">חשבונית פתוחה — ${nis(i.total)}</option>`).join('')}</select></label>` : '<input type="hidden" name="purpose" value="topup">'}
    <div class="fields">
      <label class="f"><span>סכום (₪) — לטעינה</span><input name="amount" inputmode="decimal" dir="ltr"></label>
      <label class="f"><span>אסמכתא (העברה / צ׳ק)</span><input name="reference" dir="ltr"></label>
      <label class="f wide"><span>הערה</span><input name="note" maxlength="240"></label>
    </div>`,
  async fd => {
    const purpose = fd.get('purpose');
    const body = { account: d.account.id, method: fd.get('method'), reference: fd.get('reference'), note: fd.get('note') };
    if (purpose === 'topup') {
      body.purpose = 'topup'; body.agorot = Math.round(Number(String(fd.get('amount') || '').replace(',', '.')) * 100);
      if (!body.agorot) { toast('חסר סכום', true); return false; }
    } else { body.purpose = 'invoice'; body.invoice = purpose; }
    if (!confirm('לרשום את התשלום?')) return false;
    await api('manual_payment', body);
    toast('נרשם'); customerSheet(d.customer.id); return false;
  }, 'רישום');
}

function walletSheet(d, kind) {
  const title = { topup: 'טעינה ידנית', grant: 'מתנה', adjust: 'תיקון יתרה' }[kind];
  openSheet(title + ' — ' + (d.customer.name || d.customer.external_id), `
    <div class="fields">
      <label class="f"><span>סכום (₪)${kind === 'adjust' ? ' — מינוס להורדה' : ''}</span><input name="amount" inputmode="decimal" dir="ltr" required></label>
      <label class="f wide"><span>סיבה * (נשמרת ביומן)</span><input name="note" required maxlength="240" placeholder="${kind === 'topup' ? 'העברה בנקאית / מזומן / ביט — אסמכתא' : ''}"></label>
    </div><p class="hint">יתרה נוכחית: ${nis(d.account.balance)}</p>`,
  async fd => {
    const ag = Math.round(Number(String(fd.get('amount')).replace(',', '.')) * 100);
    if (!ag) { toast('סכום לא תקין', true); return false; }
    if (!confirm(`${title}: ${nis(ag)}?`)) return false;
    await api('wallet_adjust', { account: d.account.id, agorot: ag, kind, note: fd.get('note') });
    toast('נרשם'); customerSheet(d.customer.id); return false;
  });
}

// ---------- כספים ----------
let moneyView = 'payments';
for (const b of document.querySelectorAll('[data-money]')) b.addEventListener('click', () => {
  moneyView = b.dataset.money;
  for (const x of document.querySelectorAll('[data-money]')) x.setAttribute('aria-selected', String(x === b));
  loaders.money().catch(fail);
});
const PST = { succeeded: ['עבר', 'ok'], failed: ['נכשל', 'bad'], refunded: ['הוחזר', ''], partially_refunded: ['הוחזר חלקית', 'warn'] };
const IST = { paid: ['שולמה', 'ok'], open: ['פתוחה', 'warn'], uncollectible: ['לא נגבתה', 'bad'], void: ['בוטלה', ''], draft: ['טיוטה', ''] };
loaders.money = async () => {
  const [p, i] = await Promise.all([api('payments'), api('invoices')]);
  $('#n-pay').textContent = p.data.length || ''; $('#n-inv').textContent = i.data.length || '';
  $('#money-hint').textContent = moneyView === 'payments' ? 'כל תשלום שעבר או נכשל. לחיצה על תשלום שעבר — החזר כספי.' : 'חשבוניות פנימיות. המסמך החשבונאי (קבלה / חשבונית מס) — בשלב 5, דרך מורנינג.';
  if (moneyView === 'payments') {
    $('#money-list').innerHTML = p.data.length ? p.data.map(r => `<li class="card" ${['succeeded', 'partially_refunded'].includes(r.status) ? `data-pay="${esc(r.id)}" tabindex="0" role="button"` : ''}>
        <div class="body"><div class="l1"><span>${esc(r.customer_name || r.external_id || '—')}</span><span class="t">${when(r.created_at)}</span></div>
        <div class="l2">${nis(r.amount)}${r.refunded > 0 ? ' · הוחזרו ' + nis(r.refunded) : ''} · ${r.provider === 'fake' ? 'סולק מדומה' : PROV_NAME[r.provider_type] || esc(r.provider)}${r.doc_number ? ' · ' + (Number(r.doc_type) === 400 ? 'קבלה' : 'חשבונית') + ' ' + esc(r.doc_number) : ''}${r.failure_message ? ' · ' + esc(r.failure_message) : ''}${r.env === 'test' ? ' · בדיקות' : ''}</div></div>
        ${chip(...(PST[r.status] || [r.status, '']))}${r.status !== 'failed' && !Number(r.has_doc) && !r.self_documented && r.amount > 0 ? chip(r.doc_error ? 'מסמך נכשל' : 'חסר מסמך', r.doc_error ? 'bad' : 'warn') : ''}${r.refund_pending > 0 ? chip('להחזיר ' + nis(r.refund_pending), 'bad') : ''}</li>`).join('') : empty('עוד אין תשלומים.');
    for (const li of document.querySelectorAll('[data-pay]')) li.addEventListener('click', () => refundSheet(p.data.find(x => x.id === li.dataset.pay)));
  } else {
    $('#money-list').innerHTML = i.data.length ? i.data.map(r => `<li class="card"><div class="body">
        <div class="l1"><span>${esc(r.description || '—')}</span><span class="t">${when(r.created_at)}</span></div>
        <div class="l2">${esc(r.customer_name || '')} · ${nis(r.total)}${r.vat ? ' · מע"מ ' + nis(r.vat) : ''}${r.attempts > 1 ? ' · ' + r.attempts + ' ניסיונות' : ''}</div></div>
        ${chip(...(IST[r.status] || [r.status, '']))}</li>`).join('') : empty('עוד אין חשבוניות.');
  }
};
function refundSheet(p) {
  const left = p.amount - p.refunded;
  const pendLine = p.refund_pending > 0 ? `<p class="error">↩️ הלקוח ביטל (${esc(p.refund_pending_reason)}): להחזיר ${nis(p.refund_pending)} בממשק הסולק, ואז "רישום החזר" כאן.</p>` : '';
  const docLine = Number(p.has_doc) ? `<p class="hint">🧾 ${Number(p.doc_type) === 400 ? 'קבלה' : 'חשבונית מס/קבלה'} ${esc(p.doc_number || '')}</p>`
    : p.self_documented ? '' : `<p class="${p.doc_error ? 'error' : 'hint'}">${p.doc_error ? 'המסמך החשבונאי נכשל: ' + esc(p.doc_error) : 'עוד אין מסמך חשבונאי. הוא נוצר לבד דרך מורנינג, אם לעוסק יש חיבור.'}</p>
      <div class="row-btns"><button type="button" class="btn sm ghost" id="doc-retry">הפקת המסמך עכשיו</button></div>`;
  openSheet('החזר כספי — ' + (p.customer_name || p.external_id || ''), pendLine + docLine + `
    <p class="hint">שולם ${nis(p.amount)}${p.refunded ? ', הוחזרו כבר ' + nis(p.refunded) : ''}. אפשר להחזיר עד ${nis(left)}. ההחזר יוצא דרך חברת הסליקה, לאותו אמצעי.</p>
    <div class="fields">
      <label class="f"><span>סכום (₪)</span><input name="amount" inputmode="decimal" dir="ltr" value="${((p.refund_pending > 0 ? Math.min(left, p.refund_pending) : left) / 100).toFixed(2)}"></label>
      <label class="f wide"><span>סיבה * (נשמרת ונשלחת ללקוח)</span><input name="reason" required maxlength="240" value="${esc(p.refund_pending > 0 ? p.refund_pending_reason : '')}"></label>
    </div>
    ${p.manual_refund ? `<p class="hint">ב${PROV_NAME[p.provider_type] || 'סולק'} ההחזר נעשה בממשק שלהם (שם נוצרת גם חשבונית הזיכוי). בצע אותו שם, ואז רשום כאן.</p>
      <label class="switch"><input type="checkbox" name="manual" checked disabled><span>ההחזר כבר בוצע אצלם — רק לרשום</span></label>` : ''}`,
  async fd => {
    const ag = Math.round(Number(String(fd.get('amount')).replace(',', '.')) * 100);
    if (!confirm(`${p.manual_refund ? 'לרשום החזר' : 'להחזיר'} ${nis(ag)}?`)) return false;
    await api('refund', { payment: p.id, agorot: ag, reason: fd.get('reason'), manual: !!p.manual_refund });
    toast('ההחזר בוצע'); loaders.money();
  }, 'החזר');
  $('#doc-retry')?.addEventListener('click', async () => {
    try { const r = await api('doc_retry', { payment: p.id }); toast(r.issued ? 'המסמך הופק ✓' : 'עדיין לא — בדוק את חיבור מורנינג של העוסק', !r.issued); loaders.money(); sheet.close(); } catch (e) { fail(e); }
  });
}

const MODES = { payment: 'קנייה', subscription: 'מנוי', topup: 'טעינה', invoice: 'חשבונית פתוחה' };
const RMETH = { card: 'כרטיס', bit: 'ביט', apple: 'אפל', google: 'גוגל' };
async function loadRules() {
  const { data } = await api('rules');
  const desc = r => [
    r.cond.modes ? r.cond.modes.map(m => MODES[m] || m).join('/') : 'כל קנייה',
    r.cond.min_amount !== undefined ? 'מעל ' + nis(r.cond.min_amount) : '', r.cond.max_amount !== undefined ? 'עד ' + nis(r.cond.max_amount) : '',
    r.cond.customer_type ? (r.cond.customer_type === 'business' ? 'עסק' : 'פרטי') : '',
  ].filter(Boolean).join(' · ') + ' ← ' + [
    r.act.block ? 'חסימה' : '', r.act.hide_methods ? 'בלי ' + r.act.hide_methods.map(m => RMETH[m] || m).join(', ') : '',
    r.act.only_methods ? 'רק ' + r.act.only_methods.map(m => RMETH[m] || m).join(', ') : '',
  ].filter(Boolean).join(' · ');
  $('#rule-list').innerHTML = data.length ? data.map(r => `<li class="card" data-rule="${esc(r.id)}" tabindex="0" role="button"><div class="body">
      <div class="l1"><span>${esc(r.name)}</span><span class="t">${r.priority}</span></div><div class="l2">${esc(desc(r))}</div></div>${r.active ? '' : chip('כבוי', '')}</li>`).join('')
    : empty('אין כללים — הכול לפי ברירת המחדל.');
  for (const li of document.querySelectorAll('[data-rule]')) li.addEventListener('click', () => ruleSheet(data.find(x => x.id === li.dataset.rule)));
}
$('#add-rule')?.addEventListener('click', () => ruleSheet(null));
$('#test-rules')?.addEventListener('click', () => ruleTestSheet());

function ruleSheet(r) {
  const isNew = !r;
  r = r || { name: '', priority: 100, cond: {}, act: {}, active: true };
  const ck = (name, on) => on ? 'checked' : '';
  openSheet(isNew ? 'כלל חדש' : r.name, `
    <p class="hint">כש… (כל מה שמילאת חייב להתקיים) ← אז…</p>
    <label class="f"><span>שם *</span><input name="name" required value="${esc(r.name)}" placeholder="ביט רק עד 500 ₪"></label>
    <div class="group"><h3>כש…</h3>
      <div class="chips">${Object.entries(MODES).map(([k, t]) => `<label><input type="checkbox" name="m_${k}" ${ck(k, (r.cond.modes || []).includes(k))}><span>${t}</span></label>`).join('')}</div>
      <div class="fields two">
        <label class="f"><span>סכום מעל (₪)</span><input name="min" inputmode="decimal" dir="ltr" value="${r.cond.min_amount !== undefined ? r.cond.min_amount / 100 : ''}"></label>
        <label class="f"><span>סכום עד (₪)</span><input name="max" inputmode="decimal" dir="ltr" value="${r.cond.max_amount !== undefined ? r.cond.max_amount / 100 : ''}"></label>
      </div>
      <label class="f"><span>סוג לקוח</span><select name="ctype"><option value="">כל לקוח</option><option value="private" ${r.cond.customer_type === 'private' ? 'selected' : ''}>פרטי</option><option value="business" ${r.cond.customer_type === 'business' ? 'selected' : ''}>עסק</option></select></label>
    </div>
    <div class="group"><h3>אז…</h3>
      <p class="hint">להסתיר:</p>
      <div class="chips">${Object.entries(RMETH).map(([k, t]) => `<label><input type="checkbox" name="h_${k}" ${ck(k, (r.act.hide_methods || []).includes(k))}><span>${t}</span></label>`).join('')}</div>
      <p class="hint">או רק:</p>
      <div class="chips">${Object.entries(RMETH).map(([k, t]) => `<label><input type="checkbox" name="o_${k}" ${ck(k, (r.act.only_methods || []).includes(k))}><span>${t}</span></label>`).join('')}</div>
      <label class="f"><span>או חסימה — ההודעה ללקוח</span><input name="block" value="${esc(r.act.block || '')}" placeholder="לסכום כזה — צור קשר"></label>
    </div>
    <div class="fields two"><label class="f"><span>סדר (קטן קודם)</span><input name="priority" inputmode="numeric" dir="ltr" value="${r.priority}"></label></div>
    ${!isNew ? `<label class="switch"><input type="checkbox" name="active" ${r.active ? 'checked' : ''}><span>פעיל</span></label>` : ''}`,
  async fd => {
    const nisOrEmpty = v => v === '' || v === null ? '' : Math.round(Number(String(v).replace(',', '.')) * 100);
    await api('rule_save', { id: r.id || '', name: fd.get('name'), priority: fd.get('priority'), active: isNew ? true : !!fd.get('active'),
      cond: { modes: Object.keys(MODES).filter(k => fd.get('m_' + k)), min_amount: nisOrEmpty(fd.get('min')), max_amount: nisOrEmpty(fd.get('max')), customer_type: fd.get('ctype') },
      act: { hide_methods: Object.keys(RMETH).filter(k => fd.get('h_' + k)), only_methods: Object.keys(RMETH).filter(k => fd.get('o_' + k)), block: fd.get('block') } });
    toast('נשמר'); loaders.settings();
  });
}

function ruleTestSheet() {
  openSheet('בדיקת כללים — עסקה דמיונית', `
    <div class="fields two">
      <label class="f"><span>סוג</span><select name="mode">${Object.entries(MODES).map(([k, t]) => `<option value="${k}">${t}</option>`).join('')}</select></label>
      <label class="f"><span>סכום (₪)</span><input name="amount" inputmode="decimal" dir="ltr" value="100"></label>
      <label class="f"><span>סוג לקוח</span><select name="ctype"><option value="private">פרטי</option><option value="business">עסק</option></select></label>
    </div>
    <div id="rule-result"></div>`,
  async fd => {
    const r = await api('rule_test', { mode: fd.get('mode'), amount: Math.round(Number(fd.get('amount')) * 100), customer_type: fd.get('ctype') });
    $('#rule-result').innerHTML = `<div class="summary">${r.matched.length ? '<div class="line"><b>חלים:</b> ' + r.matched.map(m => esc(m.name)).join(', ') + '</div>' : '<div class="line">אף כלל לא חל.</div>'}
      ${r.block ? `<p class="error">נחסם: ${esc(r.block)}</p>` : ''}${r.hide.length ? '<div class="line">מוסתרים: ' + r.hide.map(m => RMETH[m] || m).join(', ') + '</div>' : ''}
      ${r.only ? '<div class="line">רק: ' + r.only.map(m => RMETH[m] || m).join(', ') + '</div>' : ''}</div>`;
    return false;
  }, 'בדיקה');
}

function download(name, blob) {
  const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = name; document.body.append(a); a.click();
  setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
}
$('#exp-month').value = new Date().toISOString().slice(0, 7);
$('#exp-btn').addEventListener('click', async () => {
  try {
    const period = $('#exp-month').value;
    const { csv } = await api('export_month', { period });
    download(`תשלומים-${period}.csv`, new Blob([csv], { type: 'text/csv;charset=utf-8' }));
  } catch (e) { fail(e); }
});
async function loadBackups() {
  $('#backup-box').hidden = me.role !== 'owner';
  if (me.role !== 'owner') return;
  const { data } = await api('backups');
  $('#backup-list').innerHTML = data.length ? data.slice(0, 7).map(b => `<li class="card"><div class="body"><div class="l1"><span>${when(b.at)}</span><span class="t">${Math.round(b.size / 1024)} ק״ב</span></div></div>
      <button type="button" class="icon-btn" data-bk="${esc(b.name)}" aria-label="הורדה">⬇️</button></li>`).join('') : empty('עוד אין גיבוי — ייווצר היום.');
  for (const b of document.querySelectorAll('[data-bk]')) b.addEventListener('click', async () => {
    try {
      const r = await api('backup_get', { name: b.dataset.bk });
      const bin = Uint8Array.from(atob(r.base64), c => c.charCodeAt(0));
      download(r.name, new Blob([bin], { type: 'application/x-sqlite3' }));
    } catch (e) { fail(e); }
  });
}
$('#backup-now').addEventListener('click', async () => { try { await api('backup_now'); toast('נוצר גיבוי'); loadBackups(); } catch (e) { fail(e); } });

async function loadClock() {
  const c = await api('clock');
  const ago = t => t ? Math.round((c.now - t) / 60) + ' דק׳' : 'אף פעם';
  const cmd = `curl -s -b 'humans_21909=1' "${c.url}" >/dev/null`;
  const cronOk = c.cron && c.now - c.cron < 20 * 60 || c.external && c.now - c.external < 20 * 60;
  $('#clock').innerHTML = `<div class="line"><span class="muted">ריצה אחרונה</span> <b>${ago(c.last)}</b>${chip(cronOk ? 'משימה מתוזמנת פועלת ✓' : 'אין משימה מתוזמנת', cronOk ? 'ok' : 'warn')}</div>
    ${cronOk ? '' : `<p class="hint">בלוח הבקרה של האחסון: "Cron Jobs" ← כל 5 דקות ← להדביק בשדה הפקודה:</p>
    <input readonly dir="ltr" value="${esc(cmd)}" aria-label="הפקודה" onclick="this.select()">`}
    <div class="row-btns">${cronOk ? '' : '<button class="btn sm primary" id="copy-cmd">📋 העתקת הפקודה</button>'}<button class="btn sm ghost" id="tick-now">הרצה עכשיו</button></div>`;
  $('#copy-cmd')?.addEventListener('click', () => navigator.clipboard.writeText(cmd).then(() => toast('הועתק')));
  $('#tick-now').addEventListener('click', async () => { try { const r = await api('tick_now'); toast('רץ: ' + Object.entries(r).filter(([, v]) => v).map(([k, v]) => k + ' ' + v).join(', ') || 'אין מה לעשות'); loadClock(); } catch (e) { fail(e); } });
  const NST = { sent: ['נשלח', 'ok'], pending: ['בתור', 'warn'], failed: ['נכשל', 'bad'], no_provider: ['אין ספק מסרונים', 'bad'] };
  $('#ntf-list').innerHTML = c.notifications.length ? c.notifications.map(n => `<li class="card"><div class="body">
      <div class="l1"><span>${n.channel === 'sms' ? '📱' : '✉️'} ${esc(n.template)}</span><span class="t">${when(n.created_at)}</span></div>
      <div class="l2" dir="ltr" style="text-align:end">${esc(n.to_addr || '—')}</div></div>${chip(...(NST[n.status] || [n.status, '']))}</li>`).join('') : empty('עוד לא נשלחו הודעות.');
}

// ---------- התחלה ----------
(async () => {
  try {
    me = (await api('me')).tenant;
    $('#who').textContent = (me.name || me.email || '') + (me.role === 'owner' ? ' · בעלים' : '');
    if (me.status !== 'active') {
      $('.tabs').hidden = true;
      $('#main').innerHTML = '<p class="empty">⏳ הבקשה שלך לגישה לתוסף ממתינה לאישור המנהל.</p>';
      return;
    }
    show();
  } catch (e) {
    $('#fatal').textContent = e.message; $('#fatal').hidden = false;
  }
})();
