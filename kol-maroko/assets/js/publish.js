// קול מרוקו — 🚀 פרסום: הדרך לאתר (M7), 🏗️ בנה גרסה מהפרויקט (M1, build.js), 🔁 מעבר (M3, cutover.js) ו-🗄️ ארכיון (M6);
// והגדרות החיבור לאתר (⚙️ בעל האתר). M6: פרסום הטיוטה (1.x) והייבוא מהאתר — נמחקו (docs/spec-v2/slices/M6-remove-old-studio.md).
'use strict';

const Publish = (() => {
  const STATUS = { live: ['הייתה באתר', 'ok'], failed: ['נכשלה', 'bad'], pending: ['לא נשלחה', 'warn'], superseded: ['הוחלפה', ''] };
  const DOCS = 'https://github.com/malkiel01/mini_projects/blob/main/kol-maroko/docs/';
  const when = iso => new Date(iso).toLocaleString('he-IL', { dateStyle: 'short', timeStyle: 'short', timeZone: 'Asia/Jerusalem' });

  // M1 — "📁 פרסום מפרויקט הקבצים" (assets/js/build.js): נטען כאן, כדי שלא לגעת ב-index.html
  let buildJs = null;
  // createElement ולא h(): הסקריפט הזה נטען (defer) לפני app.js, וה-setTimeout למטה עשוי לרוץ לפניו — ו-Promise שנדחה היה נשמר לתמיד
  const buildReady = () => buildJs ??= new Promise((ok, fail) => {
    if (window.KolBuild) return ok();
    const sc = document.createElement('script');
    sc.src = 'assets/js/build.js';
    sc.onload = ok;
    sc.onerror = () => { buildJs = null; fail(new Error('build.js לא נטען')); };
    document.head.append(sc);
  });
  // M7 — הסגנון של התור (assets/css/publish.css; אותו קובץ ש-build.js טוען)
  if (!document.querySelector('link[data-kol-publish-css]')) {          // h() של app.js עוד לא קיים כאן (הסקריפט נטען לפניו)
    const l = document.createElement('link');
    l.rel = 'stylesheet'; l.href = 'assets/css/publish.css'; l.setAttribute('data-kol-publish-css', '');
    document.head.append(l);
  }
  if (document.readyState !== 'loading') setTimeout(() => buildReady().then(() => KolBuild.notices()).catch(() => {}), 0);
  else document.addEventListener('DOMContentLoaded', () => buildReady().then(() => KolBuild.notices()).catch(() => {}));

  // ── פרסום ────────────────────────────────────────────────
  async function view(host, role) {
    host.replaceChildren(h('p', { class: 'empty' }, 'טוען…'));
    const canPublish = role === 'publisher' || role === 'owner';
    let pk;
    try { await buildReady(); pk = KolBuild.block(host, role); } catch (e) { pk = h('p', { class: 'error' }, e.message); }
    host.replaceChildren(...[                                   // replaceChildren(null) כותב "null"
      canPublish ? outboxBlock(host, role) : null,                 // M7 — הדרך לאתר: התור והדופק
      pk,                                                          // M1 — 🏗️ בנה גרסה מהפרויקט
      canPublish ? (el => (window.KolCutover ? KolCutover.mount(el) : document.head.append(h('script', { src: 'assets/js/cutover.js', onload: () => KolCutover.mount(el) })), el))(h('section', {})) : null,   // M3 — 🔁 מעבר (assets/js/cutover.js)
      // M6: המפסק של 1.x ("להציג באתר את הגרסה מהסטודיו") הוסר — הוא הדליק את הגרסה הישנה. המפסק "העמוד החדש" — בוורדפרס (🔁 מעבר מציג את מצבו)
      archiveBlock(),                                              // M6 — 🗄️ ארכיון
    ].filter(Boolean));
  }

  // ── M6: 🗄️ ארכיון — הגרסאות שפורסמו בפורמט הישן, לקריאה בלבד. אין כאן אף כפתור שמשנה משהו ──
  function archiveBlock() {
    const list = h('div', {}, h('p', { class: 'empty' }, 'טוען…'));
    const box = h('details', { class: 'arch', 'data-archive': true },
      h('summary', {}, h('h2', { class: 'sec-title', style: 'display:inline' }, '🗄️ ארכיון — הגרסאות בפורמט הישן')),
      h('p', { class: 'hint' }, 'מה שפורסם מהסטודיו הישן, לפני המעבר לפרויקט הקבצים — לקריאה בלבד. אפשר לראות כל גרסה ולהוריד אותה כ-HTML. העמוד שבאתר נבנה מ-📁 פרויקט.'),
      list);
    let loaded = false;
    const load = async (more = []) => {
      let d;
      try { d = await api('archive', undefined, `&offset=${more.length}`); } catch (e) { list.replaceChildren(h('p', { class: 'error' }, e.message)); return; }
      const all = [...more, ...d.versions];
      if (!all.length) { list.replaceChildren(h('p', { class: 'empty' }, 'אין גרסאות בפורמט הישן.')); return; }
      list.replaceChildren(
        h('ul', { class: 'list' }, all.map(v => {
          const [label, cls] = STATUS[v.status] || [v.status, ''];
          return h('li', {}, h('button', { type: 'button', class: 'use-row', 'data-archive-n': v.n, onclick: () => archiveSheet(v) },
            h('span', { class: 'use-ic', 'aria-hidden': 'true' }, String(v.n)),
            h('span', { class: 'use-tx' }, h('b', { class: 'one' }, v.note || `גרסה ${v.n}`), h('small', {}, `${v.name} · ${when(v.at)}`)),
            h('span', { class: `chip ${v.n === d.last_live ? 'ok' : cls}` }, v.n === d.last_live ? 'האחרונה שהייתה באתר' : label), h('span', { class: 'use-go', 'aria-hidden': 'true' }, '‹')));
        })),
        all.length < d.total ? h('button', { type: 'button', class: 'btn ghost w-more', onclick: () => load(all) }, `עוד גרסאות (${d.total - all.length})`) : null);
    };
    box.addEventListener('toggle', () => { if (box.open && !loaded) { loaded = true; load(); } });
    return box;
  }

  function archiveSheet(v) {
    const frame = h('iframe', { class: 'ver-frame', sandbox: 'allow-scripts', title: `תצוגה של גרסה ${v.n}` });
    sheet(`🗄️ גרסה ${v.n} (הפורמט הישן)`,
      h('dl', { class: 'cells' },
        h('div', {}, h('dt', {}, 'מי ומתי'), h('dd', {}, `${v.name} · ${when(v.at)}`)),
        h('div', {}, h('dt', {}, 'הערה'), h('dd', {}, v.note || '—'))),
      h('div', { class: 'row-btns' }, h('a', { class: 'btn ghost', href: `api.php?action=archive_html&n=${v.n}`, download: `kol-maroko-v${v.n}.html`, 'data-archive-download': true }, '⬇️ הורד כ-HTML')),
      h('p', { class: 'hint' }, 'לקריאה בלבד. אין מכאן חזרה לגרסה הזו באתר — העמוד שבאתר נבנה מ-📁 פרויקט.'),
      frame);
    api('archive_view', undefined, `&n=${v.n}`).then(r => {
      frame.srcdoc = `<!doctype html><html lang="he" dir="rtl"><head><meta charset="utf-8">${r.fonts ? `<link rel="stylesheet" href="${r.fonts}">` : ''}<style>body{margin:0;font-family:system-ui,Arial,sans-serif}${r.css.replace(/<\/style/gi, '')}</style></head><body>${r.html}</body></html>`;
    }).catch(e => frame.replaceWith(h('p', { class: 'error' }, e.message)));
  }

  function helpLink(e) {
    const doc = { cloudflare: 'cloudflare.md', no_plugin: 'install.md', no_key: 'install.md', bad_signature: 'install.md' }[e.data?.code];
    return doc ? h('a', { href: DOCS + doc, target: '_blank', rel: 'noopener' }, 'איך מתקנים') : '';
  }

  // ── הגדרות (בעלים) ───────────────────────────────────────
  async function settings(host) {
    host.replaceChildren(h('p', { class: 'empty' }, 'טוען…'));
    let s;
    try { s = await api('site_settings'); }
    catch (e) { host.replaceChildren(h('p', { class: 'error' }, e.message)); return; }
    const keyBox = h('code', { class: 'keybox', dir: 'ltr' }, s.key);
    const ping = h('p', { class: 'hint', role: 'status' });
    const site = h('input', { value: s.site, dir: 'ltr', inputmode: 'url' });
    const pending = h('div');
    const showPending = key => pending.replaceChildren(h('div', { class: 'warnbox' },
      h('b', {}, 'מפתח חדש — ממתין'), h('p', {}, 'מוסיפים אותו בתוסף כשורה שנייה (בלי למחוק את הישן), שומרים, ואז:'),
      h('code', { class: 'keybox', dir: 'ltr' }, key), copyBtn(key),
      h('button', { type: 'button', class: 'btn primary', onclick: async () => {
        if (!confirm('הדבקת את המפתח החדש בתוסף ושמרת?')) return;
        try { await api('key_activate', {}); toast('המפתח החדש פעיל. אפשר למחוק את הישן מהתוסף'); settings(host); } catch (e) { toast(e.message, true); }
      } }, '✓ הדבקתי — להפעיל')));
    host.replaceChildren(
      h('h2', { class: 'sec-title' }, '🔗 חיבור לאתר'),
      h('p', { class: 'hint' }, 'לכלי אין גישה לאתר. כל מה שיוצא אליו חתום, והתוסף באתר בודק אותו מול המפתח הציבורי הזה.'),
      h('div', { class: 'summary' },
        h('div', { class: 'line' }, h('b', {}, 'המפתח הציבורי'), h('span', { class: 'muted' }, '— להדביק בוורדפרס: הגדרות ← קול מרוקו')),
        keyBox, h('div', { class: 'row-btns' }, copyBtn(s.key),
          h('button', { type: 'button', class: 'btn ghost', onclick: async () => {
            if (!confirm('ליצור מפתח חדש? הישן ממשיך לעבוד עד שתפעילו את החדש.')) return;
            try { showPending((await api('key_rotate', {})).pending); } catch (e) { toast(e.message, true); }
          } }, '🔁 החלפת מפתח'))),
      pending,
      h('div', { class: 'toolbar' }, h('label', { class: 'f', style: 'flex:1' }, h('span', {}, 'האתר'), site),
        h('button', { type: 'button', class: 'btn ghost', style: 'align-self:end', onclick: async () => {
          try { await api('site_set', { site: site.value }); toast('נשמר'); } catch (e) { toast(e.message, true); }
        } }, 'שמירה')),
      channelBlock(host, s),
      h('div', { class: 'row-btns wrap' },
        h('button', { type: 'button', class: 'btn primary', onclick: async () => {
          ping.textContent = 'בודק…';
          try {
            const r = (await api('site_ping', {})).site;
            if (r.channel === 'pull') { ping.replaceChildren(h('b', { style: `color:var(--${r.pulse.level === 'ok' ? 'ok' : 'star'})` }, '📡 '), r.pulse.text); return; }
            ping.replaceChildren(h('b', { style: 'color:var(--ok)' }, '✓ האתר ענה. '),
              `${r.plugin ? 'תוסף ' + r.plugin + ' · ' : ''}${r.enabled ? 'העמוד מהכלי מוצג' : 'המפסק כבוי — העמוד מציג את התוכן המקורי'}${r.page_set ? '' : ' · לא נבחר עמוד בתוסף'}`);
          } catch (e) {
            ping.replaceChildren(h('span', { class: 'error' }, e.message), ' ', helpLink(e),
              e.data?.server_ip ? h('div', { class: 'hint' }, `כתובת ה-IP של השרת שלנו (לכלל ב-Cloudflare): `, h('code', { dir: 'ltr' }, e.data.server_ip)) : '');
          }
        } }, '📡 בדיקת חיבור'),
        h('a', { class: 'btn ghost', href: DOCS + 'install.md', target: '_blank', rel: 'noopener' }, '📘 מדריך התקנה'),
        h('a', { class: 'btn ghost', href: DOCS + 'cloudflare.md', target: '_blank', rel: 'noopener' }, '☁️ Cloudflare')),
      ping,
      audioBlock(),
      aiBlock(host));
  }

  // ── M7: הערוץ ושורת החיבור (בעל האתר) ─────────────────────
  function channelBlock(host, s) {
    const lineBox = h('div');
    let tokenTx;
    const bad = s.bad && s.bad.count ? h('p', { class: 'hint' }, `אסימון שגוי ב-pull.php: ${s.bad.count} פעמים · האחרונה ${s.bad.at ? when(s.bad.at) : ''} · ${s.bad.ip || ''}`) : null;
    const setCh = async c => {
      if (c === s.channel) return;
      if (!confirm(c === 'pull' ? 'לעבור לערוץ משיכה? מעכשיו כל פרסום נכנס לתור, והאתר מושך אותו (התוסף 2.2 עם שורת חיבור).'
        : 'לעבור לערוץ דחיפה? הכלי ישלח ישירות לאתר — זה נחסם כשיש Cloudflare בדרך.')) return;
      try { await api('pub_channel_set', { channel: c }); toast('נשמר'); settings(host); } catch (e) { toast(e.message, true); }
    };
    return h('div', { class: 'summary ob-channel' },
      h('div', { class: 'line' }, h('b', {}, 'הערוץ'), h('span', { class: 'muted' }, s.channel === 'pull'
        ? '📥 משיכה — האתר יוצא אל הכלי ומושך (אין בקשה נכנסת לאתר, ולכן Cloudflare אינו בדרך)'
        : '📤 דחיפה — הכלי שולח לאתר (POST ל-wp-json)')),
      h('div', { class: 'row-btns wrap' },
        h('button', { type: 'button', class: `btn ${s.channel === 'pull' ? 'primary' : 'ghost'}`, 'aria-pressed': String(s.channel === 'pull'), onclick: () => setCh('pull') }, '📥 משיכה'),
        h('button', { type: 'button', class: `btn ${s.channel === 'push' ? 'primary' : 'ghost'}`, 'aria-pressed': String(s.channel === 'push'), onclick: () => setCh('push') }, '📤 דחיפה')),
      h('div', { class: 'line' }, h('b', {}, '📋 שורת חיבור לתוסף'), tokenTx = h('span', { class: 'muted' }, s.token_at
        ? `— אסימון נוצר ${when(s.token_at)}. השורה מוצגת רק כשנוצרת; שורה חדשה מבטלת את הקודמת`
        : '— עוד לא נוצרה. בלעדיה התוסף אינו יכול למשוך')),
      h('button', { type: 'button', class: 'btn primary', style: 'justify-self:start', onclick: async () => {
        if (s.token_at && !confirm('שורה חדשה מבטלת את האסימון הקודם — התוסף יפסיק למשוך עד שתדביקו בו את החדשה. להמשיך?')) return;
        try {
          const r = await api('pull_line', {});
          s.token_at = r.token_at;
          tokenTx.textContent = '— נוצרה עכשיו; הקודמת (אם הייתה) בוטלה';
          lineBox.replaceChildren(h('div', { class: 'warnbox' },
            h('b', {}, 'השורה — מוצגת פעם אחת. להעתיק עכשיו:'),
            h('code', { class: 'keybox ob-line', dir: 'ltr' }, r.line), copyBtn(r.line),
            h('p', {}, 'בוורדפרס: הגדרות ← קול מרוקו ← 📥 משיכה מהכלי ← "שורת חיבור" ← שמירה ובדיקה.')));
        } catch (e) { toast(e.message, true); }
      } }, s.token_at ? '🔁 שורה חדשה (מבטלת את הקודמת)' : '📋 יצירת שורת חיבור'),
      lineBox, bad,
      h('p', { class: 'hint', dir: 'ltr' }, 'GET ' + s.pull_url));
  }

  function copyBtn(text) {
    return h('button', { type: 'button', class: 'btn ghost', onclick: async () => {
      try { await navigator.clipboard.writeText(text); toast('הועתק'); } catch { toast('לא הצלחתי להעתיק — סמנו והעתיקו ידנית', true); }
    } }, '📋 העתקה');
  }

  // אופן ההצבה באתר (M4-BC) — עבר ל"⚙️ בעל האתר" (P1, assets/js/people.js: People.mountBlock)

  // ההקלטות מהאתר → עותק בכלי. הקבצים מהתוסף (1.3): "ייצוא ההקלטות", כמה חלקים — אפשר לבחור את כולם יחד
  function audioBlock() {
    const ups = h('div', { class: 'ups' });
    const out = h('div');
    const input = h('input', { type: 'file', accept: '.zip,application/zip', multiple: true, hidden: true, onchange: async () => {
      const files = [...input.files];
      input.value = '';
      const tot = { files: 0, linked: 0, failed: [], unmatched: [] };
      let remaining = null;
      for (const f of files) {
        const row = Media.progressRow();
        ups.append(row.el);
        try {
          const id = await Media.upload(f, (got, total, left, note) => row.set(f.name, got, total, left, note), 'import');
          row.set(f.name, 1, 1, null, 'מכניס את ההקלטות ומקשר למילים…');
          const st = (await api('audio_import', { id })).stats;
          row.set(f.name, 1, 1, null, `✓ חלק ${st.part} מתוך ${st.parts}: ${st.files} הקלטות${st.already ? ` · ${st.already} כבר היו` : ''}`);
          tot.files += st.files; tot.linked += st.linked; tot.failed.push(...st.failed); tot.unmatched.push(...(st.unmatched_list || []));
          remaining = st.remaining;
        } catch (e) {
          row.el.classList.add('error');
          row.el.querySelector('.line .muted').textContent = e.message;
        }
      }
      out.replaceChildren(h('div', { class: 'summary' },
        h('p', {}, h('b', { style: 'color:var(--ok)' }, `✓ ${tot.files} הקלטות נכנסו לכלי`), ` · ${tot.linked} מילים קושרו`),
        remaining ? h('p', { class: 'hint' }, `${remaining} מילים עדיין עם הקלטה רק באתר — כנראה חסר חלק. העלו גם אותו.`) : h('p', { class: 'hint' }, 'כל ההקלטות בכלי. רואים אותן ב-🗂️ קבצים וב-📚 מילים.'),
        tot.failed.length ? h('div', { class: 'warnbox' }, h('b', {}, `${tot.failed.length} לא נכנסו:`), h('ul', {}, tot.failed.slice(0, 30).map(x => h('li', {}, x)))) : null,
        // A2: מה שלא הותאם לאף מילה (לא לפי המזהה ולא לפי הכתובת) — מוצג, לא מדולג בשקט
        tot.unmatched.length ? h('div', { class: 'warnbox' }, h('b', {}, `${tot.unmatched.length} לא הותאמו לאף מילה (לא נכנסו):`),
          h('ul', {}, tot.unmatched.slice(0, 100).map(x => h('li', {}, x.word_id ? `#${x.word_id} ` : '', x.word || '', ' — ', h('span', { dir: 'ltr' }, x.url || x.file))))) : null));
    } });
    return h('section', {},
      h('h2', { class: 'sec-title' }, '🎙️ ייבוא ההקלטות'),
      h('p', { class: 'hint' }, 'עותק של כל ההקלטות של המילים בכלי — לשמיעה, להחלפה ולהורדה. בוורדפרס: הגדרות ← קול מרוקו ← "ייצוא ההקלטות" (כמה קבצים). מעלים כאן את כולם. באתר הן נשארות ולא נשלחות שוב.'),
      input, h('button', { type: 'button', class: 'btn primary', onclick: () => input.click() }, '🎙️ העלאת קובצי ההקלטות'), ups, out);
  }

  function aiBlock(host) {
    const box = h('section', {}, h('h2', { class: 'sec-title' }, '✨ עוזר הבינה'), h('p', { class: 'empty' }, 'טוען…'));
    api('ai_admin').then(d => {
      const s = d.secret.anthropic_api_key;
      const key = h('input', { type: 'password', dir: 'ltr', autocomplete: 'off', placeholder: s.set ? `מוגדר ${s.hint}` : 'sk-ant-…' });
      const quota = h('input', { type: 'number', min: 0, step: 10000, dir: 'ltr', value: d.quota_default });
      const k = n => n >= 1000 ? Math.round(n / 1000) + 'K' : String(n);
      box.replaceChildren(
        h('h2', { class: 'sec-title' }, '✨ עוזר הבינה'),
        h('p', { class: 'hint' }, `המודל: ${d.model}. המפתח נשמר רק בשרת, ולא חוזר לדפדפן לעולם.`),
        h('div', { class: 'toolbar' }, h('label', { class: 'f', style: 'flex:1' }, h('span', {}, s.set ? `מפתח API · מוגדר ${s.hint}` : 'מפתח API של Anthropic'), key),
          h('button', { type: 'button', class: 'btn ghost', style: 'align-self:end', onclick: async () => {
            if (!key.value.trim() && !confirm('להסיר את המפתח? העוזר יפסיק לעבוד.')) return;
            try { await api('secret_set', { key: 'anthropic_api_key', value: key.value }); toast('נשמר'); Publish.settings(host); } catch (e) { toast(e.message, true); }
          } }, 'שמירה')),
        h('div', { class: 'toolbar' }, h('label', { class: 'f', style: 'flex:1' }, h('span', {}, 'מכסה לחודש לכל חבר (טוקנים)'), quota),
          h('button', { type: 'button', class: 'btn ghost', style: 'align-self:end', onclick: async () => {
            try { await api('ai_quota_set', { tokens: +quota.value }); toast('נשמר'); } catch (e) { toast(e.message, true); }
          } }, 'שמירה')),
        h('p', { class: 'hint' }, 'מכסה אישית — במסך החברים. לבעלים אין מכסה.'),
        d.month.length ? h('ul', { class: 'list' }, d.month.map(m => h('li', { class: 'card' },
          h('div', { class: 'body' }, h('div', { class: 'l1' }, h('span', {}, m.name || 'בלי שם'), h('span', { class: 't' }, `${k(+m.tokens)} טוקנים`)),
            h('div', { class: 'l2' }, `${m.calls} בקשות החודש`)))))
          : h('p', { class: 'hint' }, 'עוד לא היה שימוש החודש.'));
    }).catch(e => box.replaceChildren(h('p', { class: 'error' }, e.message)));
    return box;
  }

  // ── M7: "📡 הדרך לאתר" — הדופק והתור ──────────────────────
  const OB_STATE = {
    queued: ['⏳', 'ממתין למשיכה', 'warn'], delivered: ['📥', 'נמשך', 'warn'], applied: ['✓', 'הוחל באתר', 'ok'],
    failed: ['✗', 'נכשל', 'bad'], expired: ['⌛', 'פג תוקף', 'bad'], superseded: ['↷', 'הוחלפה בחדשה', ''],
  };
  const Outbox = { box: null, host: null, role: null, timer: null, refresh() { if (Outbox.box && Outbox.box.isConnected) fillOutbox(Outbox.box); } };
  const time = iso => iso ? new Date(iso).toLocaleTimeString('he-IL', { hour: '2-digit', minute: '2-digit', timeZone: 'Asia/Jerusalem' }) : '';

  function outboxBlock(host, role) {
    const box = h('section', { class: 'ob', 'aria-labelledby': 'ob-t' }, h('p', { class: 'empty' }, 'טוען את הדרך לאתר…'));
    Object.assign(Outbox, { box, host, role });
    fillOutbox(box);
    return box;
  }

  async function fillOutbox(box) {
    let d;
    try { d = await api('outbox'); } catch (e) { box.replaceChildren(h('p', { class: 'error' }, e.message)); return; }
    if (d.channel !== 'pull' && !d.outbox.length) { box.replaceChildren(); box.hidden = true; return; }
    box.hidden = false;
    const p = d.pulse;
    const status = h('p', { class: 'hint ob-wait', role: 'status' });
    const check = h('button', { type: 'button', class: 'btn ghost', onclick: async () => {
      check.disabled = true;
      const before = p.at || 0;
      status.textContent = 'דפקתי על האתר — מחכה לדופק (עד 60 שניות)…';
      try { await api('site_wake', {}); } catch (e) { status.textContent = e.message; check.disabled = false; return; }
      const t0 = Date.now();
      const poll = async () => {
        let o;
        try { o = await api('outbox'); } catch { o = null; }
        // האתר משך — כל הלשונית מתרעננת (גם "📁 פרסום מפרויקט הקבצים": הגרסה עוברת ל"באתר" רק עכשיו)
        if (o && (o.pulse.at || 0) > before) { toast('📡 האתר משך עכשיו'); if (Outbox.host && Outbox.host.isConnected) view(Outbox.host, Outbox.role); else fillOutbox(box); return; }
        if (Date.now() - t0 > 60000) { status.textContent = 'האתר לא משך בתוך דקה — ממתין לדופק הבא (wp-cron, או "🔄 עדכן עכשיו" בוורדפרס).'; check.disabled = false; return; }
        Outbox.timer = setTimeout(poll, 5000);
      };
      Outbox.timer = setTimeout(poll, 3000);
    } }, '🔄 בדוק עכשיו');
    const rows = d.outbox.slice(0, 12).map(r => {
      const [ic, label, cls] = OB_STATE[r.state] || ['•', r.state, ''];
      const at = r.state === 'applied' ? time(r.applied_at) : r.state === 'delivered' ? time(r.delivered_at) : '';
      const resend = (r.state === 'failed' || r.state === 'expired') ? h('button', { type: 'button', class: 'btn ghost ob-resend', onclick: async ev => {
        ev.target.disabled = true;
        try { await api('outbox_resend', { seq: r.seq }); toast('נשלח שוב — בתור'); fillOutbox(box); if (Outbox.host) view(Outbox.host, Outbox.role); }
        catch (e) { toast(e.message, true); ev.target.disabled = false; }
      } }, '↻ שלח שוב') : null;
      return h('li', { class: `ob-row ob-${r.state}`, 'data-seq': r.seq },
        h('span', { class: `chip ${cls} ob-st` }, `${ic} ${label}${at ? ' ' + at : ''}`),
        h('span', { class: 'ob-what' }, h('b', {}, r.label), r.what && !r.label.includes(r.what) ? h('span', { class: 'muted' }, ' · ' + r.what) : null),
        h('small', { class: 'muted ob-meta' }, `#${r.seq} · ${r.created_by || ''} · ${when(r.created_at)}`),
        h('span', { class: 'ob-why' }, r.reason ? h('span', { class: 'error' }, r.reason) : r.state === 'queued' ? h('span', { class: 'muted' }, 'האתר בודק כל 5 דקות')
          : r.cdn ? h('small', { class: 'muted ob-cdn' }, '☁️ ' + r.cdn) : null),
        resend || h('span', { class: 'ob-act' }));
    });
    box.replaceChildren(...[                                    // replaceChildren(null) כותב "null"
      h('h2', { class: 'sec-title', id: 'ob-t' }, '📡 הדרך לאתר'),
      h('div', { class: `ob-pulse ob-${p.level}` },
        h('span', { class: 'ob-dot', 'aria-hidden': 'true' }),
        h('span', { class: 'ob-pulse-tx' }, h('b', {}, 'דופק האתר: '), p.text),
        check),
      p.level === 'warn' || p.level === 'bad' ? h('p', { class: 'hint ob-cron' }, p.never
        ? 'בעל האתר: ⚙️ הגדרות ← 🔗 חיבור לאתר ← 📋 שורת חיבור, ולהדביק אותה בתוסף.'
        : `ייתכן שאין ביקורים באתר, ו-wp-cron לא רץ. ${d.cron}`) : null,
      d.waiting ? h('p', { class: 'hint' }, `${d.waiting} ממתינות למשיכה. ${d.apply_now}`) : null,
      rows.length ? h('ol', { class: 'ob-rows' }, rows) : h('p', { class: 'empty' }, 'אין עדיין פקודות לאתר. כל פרסום, מפסק ואופן הצבה יופיעו כאן עם המצב שלהם.'),
      status].filter(Boolean));
  }

  return { view, settings };
})();
