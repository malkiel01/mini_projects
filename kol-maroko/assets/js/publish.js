// קול מרוקו — פרסום לאתר והגדרות (docs/spec.md, פרק 6; docs/install.md, docs/cloudflare.md).
'use strict';

const Publish = (() => {
  const STATUS = { live: ['באתר', 'ok'], failed: ['נכשלה', 'bad'], pending: ['לא נשלחה', 'warn'], superseded: ['הוחלפה', ''] };
  const TYPE_NAMES = { heading: 'כותרת', text: 'טקסט', longtext: 'טקסט ארוך', button: 'כפתור', divider: 'קו', spacer: 'רווח', image: 'תמונה',
    gallery: 'גלריה', audio: 'הקלטה', video: 'וידאו', file: 'קובץ', code: 'קוד', raw: 'קטע מקורי' };
  const DOCS = 'https://github.com/malkiel01/mini_projects/blob/main/kol-maroko/docs/';
  const when = iso => new Date(iso).toLocaleString('he-IL', { dateStyle: 'short', timeStyle: 'short', timeZone: 'Asia/Jerusalem' });

  // ── פרסום ────────────────────────────────────────────────
  async function view(host, role) {
    host.replaceChildren(h('p', { class: 'empty' }, 'טוען…'));
    let d;
    try { d = await api('versions'); } catch (e) { host.replaceChildren(h('p', { class: 'error' }, e.message)); return; }
    const canPublish = role === 'publisher' || role === 'owner';
    const live = d.live;
    host.replaceChildren(
      h('div', { class: 'summary' },
        h('div', { class: 'line' }, live
          ? [h('span', { class: 'muted' }, 'באתר עכשיו:'), h('b', {}, `גרסה ${live.n}`), h('span', { class: 'muted' }, `· ${live.name} · ${when(live.at)}`)]
          : [h('b', {}, 'עוד לא פורסם')]),
        canPublish
          ? h('button', { type: 'button', class: 'btn primary', onclick: () => publishSheet(host, role) }, '🚀 פרסום הטיוטה לאתר')
          : h('p', { class: 'hint' }, 'רק מפרסם יכול לשלוח לאתר. הטיוטה שלך נשמרת.')),
      h('h2', { class: 'sec-title' }, 'גרסאות'),
      d.versions.length
        ? h('ul', { class: 'list' }, d.versions.map(v => {
          const [label, cls] = STATUS[v.status] || [v.status, ''];
          return h('li', {}, h('button', { type: 'button', class: 'use-row', onclick: () => versionSheet(v, host, role, d.versions[0].n) },
            h('span', { class: 'use-ic', 'aria-hidden': 'true' }, String(v.n)),
            h('span', { class: 'use-tx' }, h('b', { class: 'one' }, v.note || `גרסה ${v.n}`), h('small', {}, `${v.name} · ${when(v.at)} · ${v.files} קבצים`)),
            h('span', { class: `chip ${cls}` }, label), h('span', { class: 'use-go', 'aria-hidden': 'true' }, '‹')));
        }))
        : h('p', { class: 'empty' }, 'כל פרסום יוצר כאן גרסה קפואה. אפשר לחזור לכל אחת.'));
  }

  async function publishSheet(host, role) {
    const body = h('div', { class: 'pub' }, h('p', { class: 'empty' }, 'בודק את הטיוטה…'));
    const dlg = sheet('🚀 פרסום לאתר', body);
    let p;
    try { p = await api('publish_preview'); } catch (e) { body.replaceChildren(h('p', { class: 'error' }, e.message)); return; }
    const c = p.checks, df = p.diff;
    const names = list => list.map(x => TYPE_NAMES[x.type] || x.type).reduce((m, t) => (m[t] = (m[t] || 0) + 1, m), {});
    const line = (ic, label, list) => list.length ? h('li', {}, h('span', { 'aria-hidden': 'true' }, ic), ` ${label}: `,
      Object.entries(names(list)).map(([t, n]) => `${t}${n > 1 ? ' ×' + n : ''}`).join(', ')) : null;
    const diffBox = df.first
      ? h('p', { class: 'hint' }, 'הפרסום הראשון — כל העמוד נשלח.')
      : h('ul', { class: 'diff' },
        line('➕', 'נוספו', df.added), line('✏️', 'השתנו', df.changed), line('➖', 'נמחקו', df.removed),
        df.layout ? h('li', {}, '🧱 סדר הפסים או העמודות השתנה') : null, df.settings ? h('li', {}, '⚙️ הגדרות העמוד השתנו') : null);
    if (!df.first && !df.added.length && !df.changed.length && !df.removed.length && !df.layout && !df.settings) {
      diffBox.replaceChildren(h('li', {}, '✓ אין שינויים מאז הגרסה שבאתר'));
    }
    const note = h('input', { maxlength: 200, placeholder: 'מה השתנה (לא חובה)' });
    const go = h('button', { type: 'button', class: 'btn primary', disabled: !!c.errors.length }, '🚀 פרסום');
    const status = h('p', { class: 'hint', role: 'status' });
    go.onclick = async () => {
      go.disabled = true; status.textContent = `שולח ${c.files} קבצים (${Media.mb(c.bytes)}) והגרסה… זה יכול לקחת דקה.`;
      try {
        const r = await api('publish', { note: note.value });
        dlg.close(); toast(`גרסה ${r.n} באתר ✓`); view(host, role);
      } catch (e) {
        go.disabled = false;
        status.replaceChildren(h('span', { class: 'error' }, e.message), ' ', helpLink(e));
      }
    };
    body.replaceChildren(...[
      h('h3', { class: 'sec-title' }, 'מה ישתנה'), diffBox,
      c.errors.length ? h('div', { class: 'error' }, h('b', {}, 'חוסם פרסום:'), h('ul', {}, c.errors.map(x => h('li', {}, x.msg)))) : null,
      c.warnings.length ? h('div', { class: 'warnbox' }, h('b', {}, 'כדאי לדעת:'), h('ul', {}, c.warnings.map(x => h('li', {}, x.msg)))) : null,
      h('p', { class: 'hint' }, `${c.files} קבצים · ${Media.mb(c.bytes)}. קבצים שכבר באתר לא נשלחים שוב.`),
      h('label', { class: 'f' }, h('span', {}, 'הערה לגרסה'), note), go, status].filter(Boolean));
  }

  function helpLink(e) {
    const doc = { cloudflare: 'cloudflare.md', no_plugin: 'install.md', no_key: 'install.md', bad_signature: 'install.md' }[e.data?.code];
    return doc ? h('a', { href: DOCS + doc, target: '_blank', rel: 'noopener' }, 'איך מתקנים') : '';
  }

  function versionSheet(v, host, role, newest) {
    const frame = h('iframe', { class: 'ver-frame', sandbox: 'allow-scripts', title: `תצוגה של גרסה ${v.n}` });
    const canPublish = role === 'publisher' || role === 'owner';
    const btns = [];
    if (canPublish && (v.status === 'failed' || v.status === 'pending') && v.n === newest) {
      btns.push(h('button', { type: 'button', class: 'btn primary', onclick: () => run('version_send', { n: v.n }, 'נשלחה שוב') }, '↻ שליחה שוב'));
    }
    if (canPublish && v.status === 'superseded') {
      btns.push(h('button', { type: 'button', class: 'btn primary', onclick: () => {
        if (confirm(`לפרסם שוב את התוכן של גרסה ${v.n}? היא תישלח כגרסה חדשה.`)) run('republish', { n: v.n }, 'פורסמה מחדש');
      } }, '⤴️ פרסום מחדש'));
    }
    const status = h('p', { class: 'hint', role: 'status' });
    const dlg = sheet(`גרסה ${v.n}`,
      h('dl', { class: 'cells' },
        h('div', {}, h('dt', {}, 'מצב'), h('dd', {}, (STATUS[v.status] || [v.status])[0])),
        h('div', {}, h('dt', {}, 'מי ומתי'), h('dd', {}, `${v.name} · ${when(v.at)}`))),
      v.error ? h('p', { class: 'error' }, v.error) : null,
      btns.length ? h('div', { class: 'row-btns' }, btns) : null, status, frame);
    async function run(action, body, ok) {
      status.textContent = 'שולח…';
      try { await api(action, body); dlg.close(); toast(ok); view(host, role); }
      catch (e) { status.replaceChildren(h('span', { class: 'error' }, e.message), ' ', helpLink(e)); }
    }
    api('version_preview', undefined, `&n=${v.n}`).then(r => {
      frame.srcdoc = `<!doctype html><html lang="he" dir="rtl"><head><meta charset="utf-8"><style>body{margin:0;font-family:system-ui,Arial,sans-serif}${r.css.replace(/<\/style/gi, '')}</style></head><body>${r.html}</body></html>`;
    }).catch(e => frame.replaceWith(h('p', { class: 'error' }, e.message)));
  }

  // ── הגדרות (בעלים) ───────────────────────────────────────
  async function settings(host) {
    host.replaceChildren(h('p', { class: 'empty' }, 'טוען…'));
    let s, imp;
    try { [s, imp] = await Promise.all([api('publish_settings'), api('import_info')]); }
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
      h('p', { class: 'hint' }, 'לכלי אין גישה לאתר. הוא רק שולח גרסה חתומה, והתוסף באתר בודק אותה מול המפתח הציבורי הזה.'),
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
      h('p', { class: 'hint endpoint', dir: 'ltr' }, 'POST ' + s.endpoint),
      h('div', { class: 'row-btns wrap' },
        h('button', { type: 'button', class: 'btn primary', onclick: async () => {
          ping.textContent = 'בודק…';
          try {
            const r = (await api('site_ping', {})).site;
            ping.replaceChildren(h('b', { style: 'color:var(--ok)' }, '✓ האתר ענה. '),
              `גרסה אחרונה שהתקבלה: ${r.version || 'אין'} · ${r.enabled ? 'מוצגת בעמוד' : 'התוסף כבוי — העמוד מציג את התוכן המקורי'}${r.page_set ? '' : ' · לא נבחר עמוד בתוסף'}`);
          } catch (e) {
            ping.replaceChildren(h('span', { class: 'error' }, e.message), ' ', helpLink(e),
              e.data?.server_ip ? h('div', { class: 'hint' }, `כתובת ה-IP של השרת שלנו (לכלל ב-Cloudflare): `, h('code', { dir: 'ltr' }, e.data.server_ip)) : '');
          }
        } }, '📡 בדיקת חיבור'),
        h('a', { class: 'btn ghost', href: DOCS + 'install.md', target: '_blank', rel: 'noopener' }, '📘 מדריך התקנה'),
        h('a', { class: 'btn ghost', href: DOCS + 'cloudflare.md', target: '_blank', rel: 'noopener' }, '☁️ Cloudflare')),
      ping,
      importBlock(host, imp.imported),
      aiBlock(host));
  }

  function copyBtn(text) {
    return h('button', { type: 'button', class: 'btn ghost', onclick: async () => {
      try { await navigator.clipboard.writeText(text); toast('הועתק'); } catch { toast('לא הצלחתי להעתיק — סמנו והעתיקו ידנית', true); }
    } }, '📋 העתקה');
  }

  function importBlock(host, last) {
    const ups = h('div', { class: 'ups' });
    const result = h('div');
    const input = h('input', { type: 'file', accept: '.zip,application/zip', hidden: true, onchange: async () => {
      const f = input.files[0];
      input.value = '';
      if (!f) return;
      if (!confirm('הייבוא מחליף את הטיוטה בעמוד מהאתר. הטיוטה הנוכחית נשמרת בהיסטוריה (🕘). להמשיך?')) return;
      const row = Media.progressRow();
      ups.replaceChildren(row.el);
      try {
        const id = await Media.upload(f, (got, total, left, note) => row.set(f.name, got, total, left, note), 'import');
        row.set(f.name, 1, 1, null, 'ממיר את העמוד ומכניס את הקבצים… זה יכול לקחת כמה דקות.');
        const r = await api('import_run', { id });
        ups.replaceChildren();
        const st = r.stats;
        result.replaceChildren(h('div', { class: 'summary' },
          h('div', { class: 'line' }, h('b', { style: 'color:var(--ok)' }, '✓ העמוד יובא לטיוטה')),
          h('p', {}, `${st.sections} פסים · ${Object.values(st.widgets).reduce((a, b) => a + b, 0)} רכיבים · ${st.files} קבצים`),
          h('p', { class: 'hint' }, Object.entries(st.widgets).map(([t, n]) => `${TYPE_NAMES[t] || t} ${n}`).join(' · ')),
          st.raw ? h('p', { class: 'hint' }, `${st.raw} קטעים מקוריים (🏛️) — מוצגים כמו באתר, ונערכים כ-HTML.`) : null,
          st.missing.length ? h('div', { class: 'warnbox' }, h('b', {}, `${st.missing.length} קבצים לא נמצאו בייצוא:`), h('ul', {}, st.missing.slice(0, 20).map(m => h('li', { dir: 'ltr' }, m)))) : null,
          h('a', { class: 'btn primary', href: '#/studio' }, '🎨 לסטודיו')));
      } catch (e) {
        ups.replaceChildren(h('p', { class: 'error' }, e.message));
      }
    } });
    return h('section', {},
      h('h2', { class: 'sec-title' }, '📥 ייבוא העמוד הקיים מהאתר'),
      h('p', { class: 'hint' }, 'כל התוכן, ההקלטות והעיצוב של העמוד היום — לטיוטה, לעריכה. בוורדפרס: הגדרות ← קול מרוקו ← "⬇️ ייצוא העמוד", ואת הקובץ שירד מעלים כאן.'),
      last ? h('p', { class: 'hint' }, `ייבוא אחרון: ${when(last.at)} · ${last.meta?.url || ''}`) : null,
      input, h('button', { type: 'button', class: 'btn primary', onclick: () => input.click() }, '📥 העלאת קובץ הייצוא'), ups, result);
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

  return { view, settings };
})();
