// ענן MBE — spec-stage-a 3.7 ("💾 שמור לענן שלי") ו-9 ("📚 קובצי שיטות"), דרך מודול הכוננים (assets/js/drives — עותק
// מסונכרן, לא עורכים). הדפדפן מדבר ישירות עם הענן של המשתמש עצמו, באסימון קצר שהשרת שלנו מנפיק רק למשתמש שבשער.
// המודול נטען רק כשמשתמש פותח מסך שצריך אותו או לוחץ — אין בקשות ברקע, אין טיימרים ואין מאזינים (✅13).

// ── CSV (3.7) — טהור, נבדק ב-tests/cloud-csv.mjs ─────────────────────
// t = זמן פתיחה בשניות UTC; o,h,l,c,v — המחרוזות כמו שהספק נתן (בלי float, בלי אובדן ספרות); ממוין, בלי כפילויות.

export const CSV_HEAD = 't,o,h,l,c,v';
const NUM = /^-?\d+(\.\d+)?$/;

/** שורות → טקסט CSV, עם שורת ההערה בראש */
export function csvFromRows(rows, { provider, symbol, tf, saved }) {
  const lines = [`# provider=${provider}; symbol=${symbol}; tf=${tf}; saved=${saved}`, CSV_HEAD];
  for (const r of [...rows].sort((a, b) => a.t - b.t)) lines.push([r.t, r.o, r.h, r.l, r.c, r.v].join(','));
  return lines.join('\n') + '\n';
}

/** טקסט CSV → { meta, rows }. שורות לא תקינות — מדולגות (לא "מתוקנות") */
export function parseCsv(text) {
  const meta = {};
  const rows = [];
  for (const line of String(text).split(/\r?\n/)) {
    if (!line || line === CSV_HEAD) continue;
    if (line.startsWith('#')) {
      for (const kv of line.slice(1).split(';')) { const [k, v] = kv.split('=').map(x => x?.trim()); if (k) meta[k] = v ?? ''; }
      continue;
    }
    const [t, o, h, l, c, v] = line.split(',');
    if (!/^\d+$/.test(t || '') || ![o, h, l, c, v].every(x => NUM.test(x || ''))) continue;
    rows.push({ t: +t, o, h, l, c, v });
  }
  return { meta, rows };
}

/**
 * מיזוג (שמירה חוזרת): נר שכבר קיים — לא משתנה (נר סגור קבוע); נר חדש — נוסף. הנר הפתוח (t + sec > now) — לא נשמר.
 * מחזיר { rows, added }.
 */
export function mergeRows(existing, incoming, { sec, now }) {
  const by = new Map(existing.map(r => [r.t, r]));
  let added = 0;
  for (const r of incoming) {
    if (r.t + sec > now) continue;
    if (!by.has(r.t)) { by.set(r.t, { t: r.t, o: r.o, h: r.h, l: r.l, c: r.c, v: r.v }); added++; }
  }
  return { rows: [...by.values()].sort((a, b) => a.t - b.t), added };
}

/** הנתיב בענן: אסטרטגיות למסחר/נתונים/<ספק>/<סימול>/<מסגרת>.csv — סימול עם '/' או ':' הופך לתו מותר בשם */
export function cloudPath(provider, symbol, tf) {
  const safe = s => String(s).replace(/[\\/:*?"<>|]/g, '_');
  return { folders: ['אסטרטגיות למסחר', 'נתונים', safe(provider), safe(symbol)], file: `${safe(tf)}.csv` };
}

export const METHODS_PATH = ['אסטרטגיות למסחר', 'שיטות'];
export const APP_FOLDER = 'אסטרטגיות למסחר';   // = name ב-Drives (api.php): ב-files.app זו "התיקייה של האפליקציה" בענן

/** 📁 דלוק — files המלא, או files.app (mbe-cloud/docs/spec-3-files-scopes.md) */
export const filesOn = st => !!(st?.connected && (st.layers?.files || (st.scope || []).some(x => x === 'files' || x === 'files.app')));
export const appScoped = st => (st?.scope || []).includes('files.app') && !(st?.scope || []).includes('files');

// ── המודול והממשק ────────────────────────────────────────────────

export function mountCloud(ctx) {
  const { h, api, toast, setHead, render, sheet, sep } = ctx;
  let drives = null;
  const getDrives = async () => {
    if (!drives) { const m = await import('./drives/drives.js'); drives = m.createDrives({ endpoint: 'api.php' }); }
    return drives;
  };
  const fmtSize = n => (n == null ? '' : n < 1024 ? `${n} B` : n < 1048576 ? `${(n / 1024).toFixed(1)} KB` : `${(n / 1048576).toFixed(1)} MB`);
  const fmtDate = s => { try { return new Date(String(s).replace(' ', 'T') + (/Z|[+-]\d\d:?\d\d$/.test(String(s)) ? '' : 'Z')).toLocaleString('he-IL', { day: 'numeric', month: 'numeric', year: '2-digit', hour: '2-digit', minute: '2-digit' }); } catch { return ''; } };

  /** תיקייה בנתיב — קיימת או נוצרת (mkdir לכל רמה). לא מחליפים תיקייה קיימת */
  async function ensureFolders(s, names, parent = null) {
    for (const name of names) {
      const { items } = await s.list(parent);
      const hit = items.find(n => n.kind === 'folder' && n.name === name);
      parent = hit ? hit.id : (await s.mkdir(name, parent, 'fail')).id;
    }
    return parent;
  }

  /**
   * התיקייה של הקובץ. files המלא — הנתיב מהשורש. files.app — האפליקציה רואה רק מה שיצרה: כתיבה בלי parent נכנסת
   * ל"תיקייה של האפליקציה" (📁 אסטרטגיות למסחר, spec-3 3.3), ו-list() בלי folder מחזיר את שורשי ההענקות. לכן מוצאים
   * את התיקייה של האפליקציה בין השורשים, או יוצרים את הרמה הבאה בלי parent — והענן יוצר אותה סביבה.
   */
  async function dataFolder(s, st, folders) {
    if (!appScoped(st)) return ensureFolders(s, folders);
    const [, first, ...rest] = folders;
    const { items } = await s.list(null);
    const appDir = items.find(n => n.kind === 'folder' && n.name === APP_FOLDER);
    let base;
    if (appDir) base = await ensureFolders(s, [first], appDir.id);
    else base = (await s.mkdir(first, null, 'fail')).id;
    return ensureFolders(s, rest, base);
  }

  async function status() {
    const d = await getDrives();
    return (await d.status()).suite;
  }

  /** "☁️ חיבור לענן MBE" — מעבר למסך האישור בענן (שכבת הקבצים בלבד) */
  async function connect() {
    try { await (await getDrives()).connect({ layers: ['files'] }); } catch (e) { toast(e.message, true); }
  }

  function offerConnect(why) {
    const s = sheet('☁️ ענן MBE', { saveLabel: 'חיבור', onSave: async () => { await connect(); return false; } });
    s.body.append(h('p', {}, why), h('p', { class: 'hint' }, 'החיבור הוא לענן שלכם, באותו חשבון. אפשר לנתק בכל רגע — הקבצים נשארים בענן.'));
  }

  /** 💾 שמור לענן שלי (3.7): ממזג עם העותק הקיים, בלי לשנות נר קיים */
  async function saveCandles({ provider, symbol, tf, sec, rows }) {
    let st;
    try { st = await status(); } catch (e) { toast(e.message, true); return; }
    if (!filesOn(st)) { offerConnect('כדי לשמור עותק אישי של הנרות — חברו את 📁 הקבצים בענן MBE.'); return; }
    const d = await getDrives();
    const s = d.suite;
    const { folders, file } = cloudPath(provider, symbol, tf);
    try {
      const parent = await dataFolder(s, st, folders);
      const { items } = await s.list(parent);
      const cur = items.find(n => n.kind === 'file' && n.name === file);
      const old = cur ? parseCsv(await (await s.read(cur.id)).text()).rows : [];
      const { rows: merged, added } = mergeRows(old, rows, { sec, now: Math.floor(Date.now() / 1000) });
      const csv = csvFromRows(merged, { provider, symbol, tf, saved: new Date().toISOString() });
      await s.upload(new File([csv], file, { type: 'text/csv' }), { parent, ...(cur ? { ifRev: cur.rev, onConflict: 'replace' } : { onConflict: 'fail' }) });
      toast(cur ? `נשמר לענן · ${added} נרות חדשים · ${merged.length} בסך הכול` : `נשמר לענן · ${merged.length} נרות`);
    } catch (e) {
      if (e.code === 'layer-off' || e.code === 'not-connected') offerConnect('📁 הקבצים בענן MBE כבויים. חברו אותם כדי לשמור.');
      else if (e.code === 'conflict') toast('הקובץ בענן השתנה בינתיים — נסו לשמור שוב', true);
      else toast(e.message, true);
    }
  }

  /** "☁️ ענן MBE" במסך "עוד" — מתג לשכבת הקבצים (api-guide 4). נטען רק כשנפתח המסך */
  function section() {
    const box = h('div', { class: 'block stack', 'aria-live': 'polite' }, h('p', { class: 'loading', style: 'padding:0' }, 'בודק את החיבור…'));
    const draw = async () => {
      let st;
      try { st = await status(); } catch (e) {
        box.replaceChildren(h('div', { class: 'line' }, h('b', {}, '☁️ ענן MBE')), h('p', { class: 'error', style: 'margin:0' }, e.message),
          h('div', {}, h('button', { class: 'btn ghost sm', type: 'button', on: { click: draw } }, 'נסו שוב')));
        return;
      }
      const sw = h('input', { type: 'checkbox', 'aria-label': '📁 קבצים בענן MBE' });
      sw.checked = filesOn(st);
      sw.addEventListener('change', async () => {
        sw.disabled = true;
        try {
          if (sw.checked) await connect();
          else ctx.confirmSheet('ניתוק ענן MBE', 'לנתק את ענן MBE? הקבצים והנתונים נשארים בענן, ואפשר לחבר שוב בכל רגע.', 'ניתוק',
            async () => { await (await getDrives()).disconnect({ layers: ['files'] }); toast('נותק'); draw(); });
        } finally { sw.disabled = false; sw.checked = filesOn(st); }
      });
      box.replaceChildren(
        h('div', { class: 'line' }, h('b', {}, '☁️ ענן MBE'), h('span', { class: 'muted' }, st.connected ? ' · מחובר כ-' + (st.name || '') : ' · לא מחובר')),
        h('label', { class: 'switch' }, sw, h('span', {}, '📁 קבצים'), h('small', { class: 'muted' }, ' — "💾 שמור לענן שלי" בגרף')),
        h('p', { class: 'hint', style: 'margin:0' }, 'החיבור לענן שלכם, באותו חשבון. בלי חיבור — כל האפליקציה עובדת כרגיל.'));
    };
    draw();
    return box;
  }

  /** החזרה מהענן: ?cloud=connected | denied (api-guide 4) */
  function handleReturn() {
    const u = new URL(location.href);
    const v = u.searchParams.get('cloud');
    if (!v) return;
    // מהבורר (spec-3 4): drives.picked() קורא את הבחירה ומנקה את ?cloud=picked בעצמו — לכן קודם הוא, ואחר כך הכתובת
    if (v === 'picked' && sessionStorage.getItem('ts-pick') === 'methods') {
      sessionStorage.removeItem('ts-pick');
      (async () => {
        try {
          const d = await getDrives();
          const [node] = typeof d.picked === 'function' ? await d.picked() : [];
          const clean = new URL(location.href);
          clean.searchParams.delete('cloud');
          history.replaceState(null, '', clean.pathname + (clean.search || '') + '#/method-files');
          if (!node || node.kind !== 'folder') { toast('לא נבחרה תיקייה', true); ctx.reload(); return; }
          await api('admin_method_files_folder', { id: node.id, path: node.name });
          toast('תיקיית השיטות נבחרה');
          ctx.reload();
        } catch (e) { toast(e.message, true); }
      })();
      return;
    }
    u.searchParams.delete('cloud');
    history.replaceState(null, '', u.pathname + (u.search || '') + '#/more');
    if (v === 'connected') toast('☁️ 📁 הקבצים בענן MBE מחוברים');
    else if (v === 'denied') toast('לא אושר', true);
  }

  /** 📚 קובצי שיטות (9.3) — מנהל בלבד (השרת מחזיר 403 לאחרים). הקבצים בענן של המנהל; אצלנו רק מזהה התיקייה */
  async function viewMethodFiles() {
    setHead('📚 קובצי שיטות', 'בענן MBE שלכם', { back: true });
    const { folder } = await api('admin_method_files');
    const out = h('div', { class: 'stack' }, h('p', { class: 'loading' }, 'טוען…'));
    render(h('p', { class: 'hint' }, 'הספרים והקבצים של שיטות המסחר — בענן שלכם, לא בריפו ולא באתר. כאן רואים ומעלים. צ\'אט האפיון קורא אותם רק דרך שיתוף לקריאה בענן (לחשבון השירות של Claude).'), out);
    let st;
    try { st = await status(); } catch (e) { out.replaceChildren(h('p', { class: 'error' }, e.message)); return; }
    if (!filesOn(st)) {
      out.replaceChildren(h('p', { class: 'empty' }, 'הענן לא מחובר.'), h('div', {}, h('button', { class: 'btn primary', type: 'button', on: { click: connect } }, '☁️ חיבור לענן MBE')));
      return;
    }
    const s = (await getDrives()).suite;
    // ב-files.app האפליקציה לא בוחרת לבד: המנהל בוחר את התיקייה בבורר של הענן (spec-3 4), ושומרים רק את ה-id
    const choose = async () => {
      const d = await getDrives();
      if (typeof d.pick !== 'function') { toast('בורר התיקיות עוד לא זמין במודול הכוננים — ממתינים לסנכרון מהענן', true); return; }
      try {
        sessionStorage.setItem('ts-pick', 'methods');
        await d.pick({ kind: 'folder', multiple: false });
      } catch (e) { sessionStorage.removeItem('ts-pick'); toast(e.message, true); }
    };
    if (!folder) {
      out.replaceChildren(h('p', {}, `עוד לא נבחרה תיקייה. בוחרים בענן את ${METHODS_PATH.join('/')} (או יוצרים אותה שם, ואז בוחרים).`),
        h('div', {}, h('button', { class: 'btn primary', type: 'button', on: { click: choose } }, '📁 בחירת תיקיית השיטות בענן')));
      return;
    }
    const input = h('input', { type: 'file', multiple: true, hidden: true });
    const progress = h('p', { class: 'hint', 'aria-live': 'polite' });
    input.addEventListener('change', async () => {
      for (const f of input.files) {
        progress.textContent = `מעלה ${f.name}…`;
        try { await s.upload(f, { parent: folder.id, onProgress: (got, total) => { progress.textContent = `מעלה ${f.name} · ${Math.round((got / Math.max(1, total)) * 100)}%`; } }); }
        catch (e) { toast(`${f.name}: ${e.message}`, true); }
      }
      progress.textContent = '';
      ctx.reload();
    });
    let list;
    try { list = await s.list(folder.id); } catch (e) {
      // הגישה בוטלה ב"🔗 חיבורים", או שהתיקייה נמחקה — בלי שגיאה גולמית
      const lost = ['not_found', 'forbidden', 'layer-off'].includes(e.code);
      out.replaceChildren(h('p', { class: lost ? 'empty' : 'error' }, lost ? 'אין גישה לתיקיית השיטות שנבחרה (הגישה בוטלה או שהיא נמחקה). בחרו שוב את תיקיית השיטות.' : e.message),
        h('div', {}, h('button', { class: 'btn primary', type: 'button', on: { click: choose } }, '📁 בחירה מחדש')));
      return;
    }
    out.replaceChildren(
      h('div', { class: 'toolbar' }, h('button', { class: 'btn primary', type: 'button', on: { click: () => input.click() } }, '⬆️ העלאה לענן'), input,
        h('span', { class: 'muted one' }, folder.path)),
      progress,
      list.items.length ? h('ul', { class: 'list' }, list.items.map(n => h('li', { class: 'card', style: 'cursor:default' },
        h('span', { class: 'use-ic', 'aria-hidden': 'true' }, n.kind === 'folder' ? '📁' : '📄'),
        h('div', { class: 'body' }, h('div', { class: 'l1' }, h('span', {}, n.name)),
          h('div', { class: 'l2' }, n.kind === 'folder' ? 'תיקייה' : fmtSize(n.size), sep(), fmtDate(n.updated_at))))))
        : h('p', { class: 'empty' }, 'התיקייה ריקה. מעלים את הקבצים של השיטה (ספר, סיכום) — תיקייה לכל שיטה.'),
      h('p', { class: 'hint' }, 'אין כאן הורדה או תצוגה של התוכן — הקבצים נשארים בענן. לשיתוף עם Claude: בדף הענן, שיתוף לקריאה של התיקייה לחשבון השירות.'));
  }

  return { saveCandles, section, handleReturn, viewMethodFiles };
}
