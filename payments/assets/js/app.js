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
  ['סולק אמיתי ראשון', 'Morning והסליקה שלה, בסביבת בדיקות', 'now'],
  ['חשבוניות', 'Morning — קבלות, חשבוניות מס/קבלה, זיכויים', ''],
  ['ספריות, קישורים וכללים', 'ספריות קוד, קישורי תשלום, כפתור, מנוע הכללים, תיעוד', ''],
  ['מנויים מאושרים', 'אזור ניהול לכל מי שהמנהל מאשר', ''],
  ['מגדלור מתחבר', 'האפליקציה הראשונה, בצ\'אט שלה', ''],
  ['עלייה לאוויר', 'תשלום אמיתי קטן והחזר, בדיקה משפטית', ''],
  ['התרחבות', 'סולקים ואפליקציות נוספים, ביט, הוראת קבע, דוחות', ''],
];
loaders.overview = async () => {
  const o = await api('overview');
  const cell = (k, v) => `<div><dt>${k}</dt><dd>${v}</dd></div>`;
  $('#ov-cells').innerHTML = cell('מערכות', o.clients) + cell('לקוחות', o.customers) + cell('מנויים פעילים', o.subscriptions)
    + cell('יתרות בארנקים (חי)', nis(o.wallets)) + cell('שימוש החודש (חי)', nis(o.usage_month)) + cell('עוסקים', o.merchants);
  const alerts = [];
  if (!o.merchants) alerts.push(chip('אין עוסק — הגדרות ‹', 'warn'));
  if (o.webhooks_failed) alerts.push(chip(o.webhooks_failed + ' הודעות למערכות נכשלו', 'bad'));
  if (o.pending_tenants) alerts.push(chip(o.pending_tenants + ' ממתינים לאישור', 'warn'));
  $('#ov-alerts').innerHTML = alerts.join('');
  $('#stages').innerHTML = STAGES.map(([t, s, st], i) => `<li class="card"><span class="stage-n" aria-hidden="true">${st === 'done' ? '✓' : i + 1}</span>
    <div class="body"><div class="l1"><span>${esc(t)}</span></div><div class="l2">${esc(s)}</div></div>
    ${st === 'now' ? chip('עכשיו', 'warn') : st === 'done' ? chip('הושלם', 'ok') : ''}</li>`).join('');
};

// ---------- עוסקים ----------
async function loadMerchants() { cache.merchants = (await api('merchants')).data; return cache.merchants; }
function merchantForm(m = {}) {
  const opt = (v, l) => `<label><input type="radio" name="tax_status" value="${v}" ${(m.tax_status || 'exempt') === v ? 'checked' : ''}><span>${l}</span></label>`;
  openSheet(m.id ? 'עריכת עוסק' : 'עוסק חדש', `
    <div class="fields">
      <label class="f wide"><span>שם העוסק (כפי שיופיע בקבלה) *</span><input name="name" required maxlength="120" value="${esc(m.name)}"></label>
      <label class="f"><span>ע"מ / ח"פ</span><input name="tax_id" inputmode="numeric" dir="ltr" value="${esc(m.tax_id)}"></label>
      <label class="f"><span>מייל</span><input name="email" type="email" dir="ltr" value="${esc(m.email)}"></label>
      <label class="f wide"><span>כתובת</span><input name="address" value="${esc(m.address)}"></label>
    </div>
    <fieldset class="seg" aria-label="סוג העוסק">${opt('exempt', 'פטור')}${opt('licensed', 'מורשה')}${opt('company', 'חברה')}</fieldset>
    <p class="hint">פטור — קבלה בלי מע"מ. מורשה וחברה — חשבונית מס/קבלה עם מע"מ 18%.</p>`,
  async fd => {
    await api('merchant_save', { id: m.id || '', ...Object.fromEntries(fd) });
    toast('נשמר'); loaders.settings();
  });
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
    await api('client_update', { id, name: o.name, merchant: o.merchant, webhook_url: o.webhook_url, key_url: o.key_url, origins: o.origins,
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
  $('#add-prod').addEventListener('click', () => productSheet(id, null));
  $('#wh-test')?.addEventListener('click', async () => {
    try { const { event } = await api('webhook_test', { client: id, env: 'test' }); toast(event.status === 'delivered' ? 'נמסר ✓' : 'לא נמסר (קוד ' + (event.response_code || 0) + ')', event.status !== 'delivered'); clientSheet(id); } catch (e) { fail(e); }
  });
  for (const b of body.querySelectorAll('[data-retry]')) b.addEventListener('click', async () => { await api('webhook_retry', { id: b.dataset.retry }).catch(fail); clientSheet(id); });
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
        <div class="l2">${nis(r.amount)}${r.refunded > 0 ? ' · הוחזרו ' + nis(r.refunded) : ''} · ${esc(r.provider)}${r.failure_message ? ' · ' + esc(r.failure_message) : ''}${r.env === 'test' ? ' · בדיקות' : ''}</div></div>
        ${chip(...(PST[r.status] || [r.status, '']))}</li>`).join('') : empty('עוד אין תשלומים.');
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
  openSheet('החזר כספי — ' + (p.customer_name || p.external_id || ''), `
    <p class="hint">שולם ${nis(p.amount)}${p.refunded ? ', הוחזרו כבר ' + nis(p.refunded) : ''}. אפשר להחזיר עד ${nis(left)}. ההחזר יוצא דרך חברת הסליקה, לאותו אמצעי.</p>
    <div class="fields">
      <label class="f"><span>סכום (₪)</span><input name="amount" inputmode="decimal" dir="ltr" value="${(left / 100).toFixed(2)}"></label>
      <label class="f wide"><span>סיבה * (נשמרת ונשלחת ללקוח)</span><input name="reason" required maxlength="240"></label>
    </div>`,
  async fd => {
    const ag = Math.round(Number(String(fd.get('amount')).replace(',', '.')) * 100);
    if (!confirm(`להחזיר ${nis(ag)}?`)) return false;
    await api('refund', { payment: p.id, agorot: ag, reason: fd.get('reason') });
    toast('ההחזר בוצע'); loaders.money();
  }, 'החזר');
}

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
