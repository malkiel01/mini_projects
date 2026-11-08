// לשונית "התראות" — spec-stage-a פרק 8. התיבה, ההתראות שלי, ופוש.
// הבדיקה עצמה רצה רק ב-cron בשרת. כאן — בלי polling ובלי טיימרים: פנייה לשרת רק מפעולה של המשתמש (פתיחת המסך,
// שמירה, השהיה, מחיקה). פוש — Service Worker (sw.js) שמושך את ההודעה מהתיבה רק כשמגיע פוש.
const TF_LABEL = { m1: 'דקה', m5: '5 ד׳', m15: '15 ד׳', m30: '30 ד׳', h1: 'שעה', h4: '4 ש׳', d1: 'יום', w1: 'שבוע', mn1: 'חודש' };
const SIDES = [['long', 'לונג'], ['short', 'שורט'], ['both', 'שניהם']];
const KINDS = [['price', 'מחיר חצה רמה'], ['method', 'שיטה'], ['blend', 'שקלול']];
const STATUS = { active: ['ok', 'פעילה'], paused: ['warn', 'מושהית'], expired: ['', 'פגה'] };

export function mountAlerts(ctx) {
  const { h, api, sheet, confirmSheet, toast, setHead, render, field, numInput, seg, segVal, select, sep } = ctx;
  const ME = () => ctx.me();
  const when = t => { try { return new Date(t * 1000).toLocaleString('he-IL', { timeZone: ME().user.timezone, day: 'numeric', month: 'numeric', hour: '2-digit', minute: '2-digit' }); } catch { return ''; } };
  const sym = a => a.slice(a.indexOf(':') + 1);
  const setBadge = n => {
    const el = document.querySelector('.bottom-nav a[data-tab="alerts"] .badge');
    if (el) { el.textContent = n ? String(n) : ''; el.hidden = !n; }
  };

  function describe(a, methods) {
    const name = id => methods.find(m => m.id === id)?.name || id;
    const side = SIDES.find(s => s[0] === a.side)?.[1];
    if (a.kind === 'price') return `המחיר חוצה ${a.side === 'long' ? 'מעלה' : a.side === 'short' ? 'מטה' : ''} את ${a.level}`.replace('  ', ' ');
    if (a.kind === 'method') return `${name(a.method_id)} עוברת ל${side === 'שניהם' ? 'לונג או שורט' : side}, בודאות מעל הסף שלך`;
    return `השקלול (${a.methods.map(name).join(' + ')}) עובר ל${side === 'שניהם' ? 'לונג או שורט' : side}`;
  }

  async function viewAlerts() {
    setHead('התראות', 'נבדקות בשרת כל 15 דקות');
    const [{ alerts, methods, per_user, push_devices }, inbox] = await Promise.all([api('alerts'), api('inbox')]);
    setBadge(inbox.unread);
    let tab = sessionStorage.getItem('ts-alerts-tab') || 'inbox';
    const bar = h('div', { class: 'seg-tabs', role: 'tablist' }, [['inbox', '📥 תיבה', inbox.unread], ['mine', '🔔 ההתראות שלי', alerts.filter(a => a.status !== 'expired').length]].map(([k, l, c]) =>
      h('button', { role: 'tab', type: 'button', 'aria-selected': String(k === tab), on: { click: () => { sessionStorage.setItem('ts-alerts-tab', k); ctx.reload(); } } }, l, ' ', h('small', {}, c ? String(c) : ''))));

    const inboxView = () => h('div', { class: 'stack' },
      inbox.unread ? h('div', {}, h('button', { class: 'btn ghost sm', type: 'button', on: { click: async () => { await api('inbox_read', { ids: 'all' }); ctx.reload(); } } }, '✓ סימון הכול כנקרא')) : null,
      inbox.events.length ? h('ul', { class: 'list' }, inbox.events.map(e => h('li', {},
        h('a', { class: 'use-row' + (e.read ? '' : ' unread'), href: e.payload.link || '#/alerts', on: { click: () => { if (!e.read) api('inbox_read', { ids: [e.id] }).catch(() => {}); } } },
          h('span', { class: 'use-ic', 'aria-hidden': 'true' }, e.payload.kind === 'price' ? '📏' : '🧭'),
          h('span', { class: 'use-tx' }, h('b', {}, e.payload.title), h('small', { class: 'wrap' }, e.payload.body),
            h('small', {}, when(e.fired_at), e.delivered.push === 'quiet' ? ' · בשעות השקטות — בלי פוש' : '')),
          h('span', { class: 'use-go', 'aria-hidden': 'true' }, '‹')))))
        : h('p', { class: 'empty' }, 'אין עדיין הודעות. כשתנאי של התראה מתקיים — ההודעה תופיע כאן.'));

    const mineView = () => h('div', { class: 'stack' },
      h('div', { class: 'toolbar' }, h('button', { class: 'btn primary', type: 'button', on: { click: () => newAlert({}) } }, '+ התראה'),
        h('span', { class: 'muted' }, `${alerts.filter(a => a.status !== 'expired').length} מתוך ${per_user}`)),
      pushBlock(push_devices),
      alerts.length ? h('ul', { class: 'list' }, alerts.map(a => {
        const [cls, label] = STATUS[a.status];
        return h('li', { class: 'card', style: 'cursor:default;flex-wrap:wrap' },
          h('div', { class: 'body' }, h('div', { class: 'l1' }, h('span', { class: 'num' }, sym(a.asset)), h('span', { class: 't' }, TF_LABEL[a.tf])),
            h('div', { class: 'l2 wrap' }, describe(a, methods)),
            h('div', { style: 'margin-top:4px' }, h('span', { class: 'chip ' + cls }, label), ' ',
              h('small', { class: 'muted' }, a.status === 'expired' ? '' : 'עד ' + when(a.expires_at), a.last_fired_at ? [sep(), 'נשלחה ' + when(a.last_fired_at)] : null))),
          h('div', { class: 'row-btns' },
            a.status === 'expired' ? null : h('button', { class: 'btn ghost sm', type: 'button', on: { click: async () => {
              try { await api('alert_status', { id: a.id, status: a.status === 'active' ? 'paused' : 'active' }); ctx.reload(); } catch (e) { toast(e.message, true); }
            } } }, a.status === 'active' ? '⏸ השהיה' : '▶ הפעלה'),
            h('button', { class: 'btn ghost sm danger', type: 'button', on: { click: () => confirmSheet('מחיקת התראה', describe(a, methods), 'מחיקה', async () => { await api('alert_delete', { id: a.id }); ctx.reload(); }) } }, 'מחיקה')));
      })) : h('p', { class: 'empty' }, 'אין התראות. יוצרים כאן, או מכרטיס ניתוח ("🔔 התראה מזה").'));

    render(bar, tab === 'inbox' ? inboxView() : mineView(),
      h('p', { class: 'disclaimer' }, '⚖️ ' + ME().disclaimer, ' התראה לא נשלחת כשהוודאות מתחת לסף שבפרופיל.'));
  }

  // ── פוש במכשיר הזה ──
  function pushBlock(devices) {
    const box = h('div', { class: 'block stack' });
    const supported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
    const wanted = ME().profile.alert_channels.includes('push');
    const draw = async () => {
      if (!supported) { box.replaceChildren(h('p', { class: 'hint', style: 'margin:0' }, 'הדפדפן הזה לא תומך בפוש. ההודעות יגיעו לתיבה.')); return; }
      const reg = await navigator.serviceWorker.getRegistration('./');
      const sub = reg ? await reg.pushManager.getSubscription() : null;
      box.replaceChildren(
        h('div', { class: 'line' }, h('b', {}, '📲 פוש'), ' ', h('span', { class: 'chip ' + (sub ? 'ok' : '') }, sub ? 'מופעל במכשיר הזה' : 'כבוי במכשיר הזה'),
          devices ? h('small', { class: 'muted' }, ` · ${devices} מכשירים`) : null),
        wanted ? null : h('p', { class: 'hint', style: 'margin:0' }, 'פוש כבוי בפרופיל — ההפעלה כאן מדליקה אותו גם שם.'),
        h('div', {}, sub
          ? h('button', { class: 'btn ghost sm', type: 'button', on: { click: async () => { await api('push_unsubscribe', { endpoint: sub.endpoint }); await sub.unsubscribe(); toast('פוש כובה במכשיר הזה'); draw(); } } }, 'כיבוי במכשיר הזה')
          : h('button', { class: 'btn ghost sm', type: 'button', on: { click: () => enablePush().then(draw).catch(e => toast(e.message, true)) } }, 'הפעלה במכשיר הזה')));
    };
    draw();
    return box;
  }

  async function enablePush() {
    if (await Notification.requestPermission() !== 'granted') throw new Error('לא ניתנה הרשאה להתראות בדפדפן');
    const reg = await navigator.serviceWorker.register('sw.js', { scope: './' });
    await navigator.serviceWorker.ready;
    const { key } = await api('push_key');
    const raw = Uint8Array.from(atob(key.replace(/-/g, '+').replace(/_/g, '/') + '='.repeat((4 - key.length % 4) % 4)), c => c.charCodeAt(0));
    const sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: raw });
    const j = sub.toJSON();
    await api('push_subscribe', { endpoint: j.endpoint, keys: j.keys });
    const p = ME().profile;
    if (!p.alert_channels.includes('push')) {
      await api('profile_save', { ...p, alert_channels: ['inbox', 'push'] });
      ctx.setMe(await api('me'));
    }
    toast('פוש הופעל במכשיר הזה');
  }

  // ── התראה חדשה — גם מכרטיס ניתוח (pre) ──
  async function newAlert(pre = {}) {
    const [{ methods }, { watchlist }, { providers }] = await Promise.all([api('alerts'), api('watchlist'), api('market_providers')]);
    const assets = [...new Set([...(pre.asset ? [pre.asset] : []), ...watchlist.map(w => w.asset)])];
    if (!assets.length) { toast('בחרו קודם נכס ב"שוק" או הוסיפו נכס למעקב', true); return; }
    const assetSel = select('asset', assets.map(a => [a, '⁦' + sym(a) + '⁩ · ' + (providers.find(p => p.id === a.split(':')[0])?.name || '')]), pre.asset || assets[0], { 'aria-label': 'נכס' });
    const tfBox = h('div');
    const level = numInput('level', pre.level || '', 'למשל 65000');
    const days = h('input', { type: 'number', min: 1, max: 90, value: 30, inputmode: 'numeric', dir: 'ltr' });
    const methodSel = select('method', methods.map(m => [m.id, m.name]), pre.method_id || methods[0]?.id || '', { 'aria-label': 'שיטה' });
    const blendBox = h('div', { class: 'chips', role: 'group', 'aria-label': 'שיטות לשקלול' }, methods.map(m => {
      const c = h('input', { type: 'checkbox', value: m.id }); c.checked = (pre.methods || []).includes(m.id);
      return h('label', {}, c, h('span', {}, m.name));
    }));
    let kind = methods.length ? (pre.kind || 'price') : 'price';
    const kindBox = h('div');
    const tfs = () => (providers.find(p => p.id === assetSel.value.split(':')[0])?.tfs || []);
    const drawTf = () => { tfBox.replaceChildren(select('tf', tfs().map(t => [t, TF_LABEL[t]]), tfs().includes(pre.tf) ? pre.tf : (tfs().includes('h1') ? 'h1' : tfs()[0]), { 'aria-label': 'מסגרת זמן' })); };
    const drawKind = () => kindBox.replaceChildren(
      kind === 'price' ? field('רמת מחיר', level, true) : kind === 'method' ? field('שיטה', methodSel, true) : h('div', { class: 'f wide' }, h('span', {}, 'שיטות (2 לפחות)'), blendBox));
    assetSel.addEventListener('change', drawTf);
    drawTf(); drawKind();
    const sh = sheet('התראה חדשה', { saveLabel: 'שמירה', onSave: async () => {
      const body = { asset: assetSel.value, tf: tfBox.querySelector('select').value, side: segVal(sh.body, 'side'), kind, days: +days.value };
      if (kind === 'price') body.level = level.value.trim();
      if (kind === 'method') body.method_id = methodSel.value;
      if (kind === 'blend') body.methods = [...blendBox.querySelectorAll('input:checked')].map(i => i.value);
      await api('alert_save', body);
      toast('ההתראה נשמרה');
      sessionStorage.setItem('ts-alerts-tab', 'mine');
      if (location.hash.startsWith('#/alerts')) ctx.reload(); else location.hash = '#/alerts';
    } });
    sh.body.append(
      h('div', { class: 'fields two' }, field('נכס', assetSel, true), h('label', { class: 'f' }, h('span', {}, 'מסגרת זמן'), tfBox), field('תוקף (ימים)', days)),
      h('div', { class: 'f' }, h('span', {}, 'כיוון'), seg('side', SIDES, pre.side || 'both', { grid: 3, label: 'כיוון' })),
      methods.length ? h('div', { class: 'f' }, h('span', {}, 'תנאי'), seg('kind', KINDS, kind, { grid: 3, label: 'תנאי', onChange: v => { kind = v; drawKind(); } }))
        : h('p', { class: 'hint' }, 'אין עדיין שיטות שמותרות בהתראות — רק התראת מחיר.'),
      kindBox,
      h('p', { class: 'hint' }, 'נבדק בשרת כל 15 דקות. נשלח פעם אחת כשהתנאי מתקיים, ולא שוב עד שהוא מתבטל ומתקיים מחדש. התראת שיטה — רק בודאות מעל הסף שבפרופיל.'));
  }

  return { viewAlerts, newAlert, setBadge };
}
