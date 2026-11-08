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

/** טקסט → gzip → base64 (הגוף של "🧪 הרץ על העותק שלי": 100,000 נרות הם ~8MB טקסט, ומכווצים — מעט) */
export async function gzipB64(text) {
  const stream = new Blob([text]).stream().pipeThrough(new CompressionStream('gzip'));
  const buf = new Uint8Array(await new Response(stream).arrayBuffer());
  let bin = '';
  for (let i = 0; i < buf.length; i += 0x8000) bin += String.fromCharCode.apply(null, buf.subarray(i, i + 0x8000));
  return btoa(bin);
}

export const METHODS_PATH = ['אסטרטגיות למסחר', 'שיטות'];
export const APP_FOLDER = 'אסטרטגיות למסחר';   // = name ב-Drives (api.php): ב-files.app זו "התיקייה של האפליקציה" בענן

/** 📁 דלוק — המודול מחשב: כל היקף קבצים (files / files.app) = layers.files (mbe-cloud/docs/spec-3-files-scopes.md) */
export const filesOn = st => !!(st?.connected && st.layers?.files);
/** חיבור files.app (בלי files המלא) — כתיבה בלי parent נכנסת לתיקייה של האפליקציה */
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

  /**
   * "☁️ חיבור לענן MBE" — רק כשלא מחובר (ו"נסו שוב" אחרי שגיאה). אילו שכבות — נקבע בענן בידי המנהל
   * (mbe-cloud/docs/spec-6-admin-layers.md): לאפליקציה אין מה לבקש, ולמשתמש אין מתגים ואין ניתוק.
   */
  async function connect() {
    try { await (await getDrives()).connect(); } catch (e) { toast(e.message, true); }
  }

  function offerConnect(why) {
    const s = sheet('☁️ ענן MBE', { saveLabel: 'חיבור', onSave: async () => { await connect(); return false; } });
    s.body.append(h('p', {}, why), h('p', { class: 'hint' }, 'החיבור הוא לענן שלכם, באותו חשבון. בלי חיבור — כל האפליקציה עובדת כרגיל.'));
  }

  /** 📁 כבוי באפליקציה הזו — בידי מנהל הענן. הודעה בלבד, בלי כפתור (אין למשתמש מה להדליק) */
  const LAYER_OFF_MSG = '📁 הקבצים בענן MBE לא פעילים באפליקציה הזו — מנהל הענן קובע את זה.';

  /** לא אפשר לכתוב לענן: לא מחובר — מציעים חיבור; מחובר והשכבה כבויה — הודעה בלי כפתור */
  function cannotUse(st, why) {
    if (!st?.connected) offerConnect(why);
    else toast(LAYER_OFF_MSG, true);
  }

  /**
   * העותק של נכס ומסגרת (3.7) — הקובץ בענן, או null: לא מחובר, אין עותק, או שאין גישה. **לא יוצר תיקיות** ולא כותב.
   * רק בפעולה של המשתמש (פתיחת גרף בטווח ארוך, ניתוח) — לא ברקע.
   */
  async function findCopy({ provider, symbol, tf }) {
    try {
      const st = await status();
      if (!filesOn(st)) return null;
      const s = (await getDrives()).suite;
      const { folders, file } = cloudPath(provider, symbol, tf);
      let parent = null, path = folders;
      if (appScoped(st)) {
        const app = (await s.list(null)).items.find(n => n.kind === 'folder' && n.name === APP_FOLDER);
        if (!app) return null;
        parent = app.id; path = folders.slice(1);
      }
      for (const name of path) {
        const hit = (await s.list(parent)).items.find(n => n.kind === 'folder' && n.name === name);
        if (!hit) return null;
        parent = hit.id;
      }
      return (await s.list(parent)).items.find(n => n.kind === 'file' && n.name === file) || null;
    } catch { return null; }
  }

  /** הטקסט של העותק — פעם אחת לכל גרסה (rev) בזמן שהדף פתוח */
  const copyText = new Map();
  async function readCopy(node) {
    const key = node.id + ':' + node.rev;
    if (!copyText.has(key)) {
      const text = await (await (await getDrives()).suite.read(node.id)).text();
      copyText.clear();
      copyText.set(key, text);
    }
    return copyText.get(key);
  }

  /** 💾 שמור לענן שלי (3.7): ממזג עם העותק הקיים, בלי לשנות נר קיים */
  async function saveCandles({ provider, symbol, tf, sec, rows }) {
    let st;
    try { st = await status(); } catch (e) { toast(e.message, true); return; }
    if (!filesOn(st)) { cannotUse(st, 'כדי לשמור עותק אישי של הנרות — חברו את הענן MBE.'); return; }
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
      if (e.code === 'not-connected') offerConnect('הענן MBE לא מחובר. חברו אותו כדי לשמור.');
      else if (e.code === 'layer-off') toast(LAYER_OFF_MSG, true);
      else if (e.code === 'conflict') toast('הקובץ בענן השתנה בינתיים — נסו לשמור שוב', true);
      else toast(e.message, true);
    }
  }

  /** שורה לקריאה בלבד: מה מצב 📁 באפליקציה הזו (spec-6 §5.3). אין מתג — המנהל קובע לכל המשתמשים */
  function filesLine(st) {
    const off = { error: 'החיבור לענן נכשל', no_drive: 'אין לך כונן בענן MBE' }[st.hold];
    const text = filesOn(st) ? '📁 קבצים · פעיל' : st.connected || st.policy_empty || st.hold === 'off' ? '📁 קבצים · לא פעיל באפליקציה הזו'
      : off ? '📁 קבצים · ' + off : '📁 קבצים · לא מחובר';
    return h('div', { class: 'line', 'data-cloud-files': filesOn(st) ? 'on' : 'off' }, h('span', {}, text),
      st.managed ? h('small', { class: 'muted' }, '🔒 נקבע בידי מנהל הענן') : null);
  }

  /**
   * "☁️ ענן MBE" במסך "עוד" — לקריאה בלבד. בפתיחת המסך — drives.ensure() פעם אחת: לא מחובר והמנהל הדליק משהו → הפניה
   * אחת לענן וחזרה מחוברת (spec-6 3.3; המודול מגן מלולאות). לא בכל טעינה של האפליקציה, ובלי polling.
   */
  function section() {
    const box = h('div', { class: 'block stack', 'aria-live': 'polite' }, h('p', { class: 'loading', style: 'padding:0' }, 'בודק את החיבור…'));
    const draw = async (first = false) => {
      let st;
      try {
        if (first) {
          const r = await (await getDrives()).ensure();
          if (r.redirected) { box.replaceChildren(h('p', { class: 'loading', style: 'padding:0' }, 'מתחבר לענן MBE…')); return; }
          st = r.status.suite;
        } else st = await status();
      } catch (e) {
        box.replaceChildren(h('div', { class: 'line' }, h('b', {}, '☁️ ענן MBE')), h('p', { class: 'error', style: 'margin:0' }, e.message),
          h('div', {}, h('button', { class: 'btn ghost sm', type: 'button', on: { click: () => draw() } }, 'נסו שוב')));
        return;
      }
      // חיבור ידני — רק כשלא מחובר ויש מה לחבר (המנהל הדליק שכבה): אחרי שגיאה ("נסו שוב"), או כשההפניה האוטומטית לא קרתה
      const canConnect = !st.connected && !st.policy_empty && st.hold !== 'off' && st.hold !== 'no_drive';
      box.replaceChildren(...[   // Element.replaceChildren כותב null כטקסט — מסננים
        h('div', { class: 'line' }, h('b', {}, '☁️ ענן MBE'), h('span', { class: 'muted' }, st.connected ? ' · מחובר כ-' + (st.name || '') : ' · לא מחובר')),
        filesLine(st),
        canConnect ? h('div', {}, h('button', { class: 'btn ghost sm', type: 'button', on: { click: connect } }, st.hold === 'error' ? 'נסו שוב' : '☁️ חיבור לענן MBE')) : null,
        h('p', { class: 'hint', style: 'margin:0' }, '"💾 שמור לענן שלי" בגרף — לענן שלכם, באותו חשבון. בלי חיבור — כל האפליקציה עובדת כרגיל.')].filter(Boolean));
    };
    draw(true);
    return box;
  }

  /** החזרה מהענן: ?cloud=connected | denied (api-guide 4), ?cloud=picked | pick-cancelled (spec-3 4) */
  function handleReturn() {
    const u = new URL(location.href);
    const v = u.searchParams.get('cloud');
    if (!v) return;
    const fromPicker = v === 'picked' || v === 'pick-cancelled';
    const ours = sessionStorage.getItem('ts-pick') === 'methods';
    if (fromPicker) sessionStorage.removeItem('ts-pick');
    // ביטול בבורר — בלי שינוי: התיקייה שנבחרה קודם (אם יש) נשארת
    if (fromPicker && (v === 'pick-cancelled' || !ours)) {
      u.searchParams.delete('cloud');
      history.replaceState(null, '', u.pathname + (u.search || '') + (ours ? '#/method-files' : location.hash));
      if (ours) { toast('הבחירה בוטלה — לא שונה דבר'); ctx.reload(); }
      return;
    }
    // מהבורר: drives.picked() קורא את הבחירה (פעם אחת, אחרי stat) ומנקה את ?cloud=picked בעצמו — לכן קודם הוא
    if (fromPicker) {
      (async () => {
        try {
          const [node] = await (await getDrives()).picked();
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
    if (v === 'connected') toast('☁️ הענן MBE מחובר');
    else if (v === 'no_drive') toast('אין לך כונן בענן MBE', true);
    else if (v === 'denied' || v === 'error' || v === 'account_mismatch') toast('החיבור לענן MBE לא הושלם', true);
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
      out.replaceChildren(...(st.connected ? [h('p', { class: 'empty' }, LAYER_OFF_MSG)]
        : [h('p', { class: 'empty' }, 'הענן לא מחובר.'), h('div', {}, h('button', { class: 'btn primary', type: 'button', on: { click: connect } }, '☁️ חיבור לענן MBE'))]));
      return;
    }
    const s = (await getDrives()).suite;
    // ב-files.app האפליקציה לא בוחרת לבד: המנהל בוחר את התיקייה בבורר של הענן (spec-3 4), ושומרים רק את ה-id
    const choose = async () => {
      try {
        sessionStorage.setItem('ts-pick', 'methods');
        await (await getDrives()).pick({ kind: 'folder', multiple: false });
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
      // 📁 כובה בידי המנהל — הודעה בלבד
      if (e.code === 'layer-off') { out.replaceChildren(h('p', { class: 'empty' }, LAYER_OFF_MSG)); return; }
      // הגישה בוטלה ב"🔗 חיבורים", או שהתיקייה נמחקה — בלי שגיאה גולמית
      const lost = ['not_found', 'forbidden'].includes(e.code);
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

  return { saveCandles, section, handleReturn, viewMethodFiles, findCopy, readCopy };
}
