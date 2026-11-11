/*
 * 🛡️ ערכת ההתקפה מול הסטודיו (פרוסה F4; 00 §8: S1, S3–S5). רצה ממסך המפתח (🩺 תקינות), מול השרת האמיתי.
 *
 * יוצרת בפרויקט קובץ זמני שמנסה, **מתוך התצוגה החיה**, לגעת בסטודיו: לקרוא עוגייה ואחסון, להגיע למסמך של ההורה,
 * לנווט את הלשונית, לפנות ל-api.php עם הסשן, לכתוב לקובץ, ולשלוח להורה הודעות שאינן מהסוגים המותרים. אחר כך בודקת
 * **מהצד של הסטודיו** מה קרה בפועל. ולכל בדיקה — ביקורת חיובית: אותו קוד, במקור של הסטודיו, כן מצליח לגעת;
 * כלומר הבדיקה מסוגלת להיכשל. בסוף הקבצים הזמניים נמחקים.
 */
'use strict';
const AttackKit = (() => {
  const HOSTILE = '<img src=x onerror="top.pwned=1;parent.pwned=1;window.pwned=1">';

  /**
   * הקוד התוקף. מוזרק כטקסט לקובץ הפרויקט (ורץ שם במקור אטום), ונקרא גם כאן, במקור של הסטודיו, כביקורת חיובית
   * (dry: בלי ניווט ובלי כתיבה). אסור לו להישען על דבר מחוץ לעצמו.
   */
  async function probe(app, canary, hostile, dry) {
    const r = { origin: self.origin };
    try { void document.cookie; r.cookie = 'read'; } catch { r.cookie = 'throw'; }
    try { localStorage.getItem('x'); r.storage = 'read'; } catch { r.storage = 'throw'; }
    try { void parent.document.title; r.parent = 'read'; } catch { r.parent = 'throw'; }
    try {
      const res = await fetch(app + 'api.php?action=me', { credentials: 'include' });
      const j = await res.json();
      r.api = j && j.ok && j.user ? 'AUTH' : 'anon';
    } catch { r.api = 'blocked'; }
    if (dry) return r;
    try { top.location = app + 'index.html#pwned'; r.topnav = 'set'; } catch { r.topnav = 'throw'; }
    // כתיבה בבקשה "פשוטה" (בלי preflight) — כזו מגיעה לשרת גם ממקור אחר, ולכן השרת הוא שצריך לדחות אותה
    for (const type of ['text/plain', 'application/x-www-form-urlencoded']) {
      try {
        await fetch(app + 'api.php?action=pf_write', { method: 'POST', mode: 'no-cors', credentials: 'include', headers: { 'Content-Type': type },
          body: JSON.stringify({ id: canary, rev: 1, content: 'PWNED' }) });
      } catch { /* נחסם בדפדפן */ }
    }
    // הודעות שאינן מהסוגים המותרים — אל ההורה (מסך התצוגה) ואל הלשונית כולה (הסטודיו)
    const bad = [
      { kol: 'pv', t: 'save' }, { kol: 'pv', t: 'publish' }, { kol: 'pv', t: 'navigate', url: app + 'index.html#pwned' }, { kol: 'pv', t: '__proto__' },
      { kol: 'pv', t: 'constructor' }, { kol: 'pv' }, { kol: 'render', html: hostile, css: '' }, { kol: 'ready' }, { kol: 'pick', id: 'x' },
      { t: 'open', path: 'kol.json', line: 1 }, { t: 'close' }, { t: 'saved', path: 'index.html' }, { kolCode: 'x', h: 99999 }, 'publish', 42, null, [1, 2],
    ];
    for (const m of bad) for (const w of [parent, top]) { try { w.postMessage(m, '*'); } catch { /* */ } }
    // ומהסוגים המותרים — עם תוכן עוין: חייב להופיע כטקסט בלבד, ונתיב שמטפס החוצה חייב להידחות
    const hot = [
      { t: 'title', title: hostile }, { t: 'console', level: 'error', text: hostile, file: '../../api.php', line: '1;alert(1)' },
      { t: 'console', level: hostile, text: [hostile], file: hostile, line: -5 }, { t: 'ready', path: '../../api.php' }, { t: 'ready', path: '//evil.example/x' },
      { t: 'pick', tag: 'img', id: hostile, classes: hostile, file: '../x', line: 1e9, w: 'x', h: {} },
    ];
    for (const m of hot) { try { parent.postMessage(Object.assign({ kol: 'pv' }, m), '*'); } catch { /* */ } }
    console.log(hostile);
    r.bad = bad.length;
    return r;
  }

  const page = (app, canary) => `<!doctype html>\n<meta charset="utf-8">\n<title>kol attack kit</title>\n<p>ערכת ההתקפה — קובץ זמני, נמחק בסוף הריצה.</p>\n`
    + `<script>(${probe})(${JSON.stringify(app)}, ${canary}, ${JSON.stringify(HOSTILE)}, false).then(r => console.log('KOLKIT ' + JSON.stringify(r)));</scr` + `ipt>\n`;

  const wait = async (fn, ms) => { const t = Date.now(); for (;;) { try { const v = fn(); if (v) return v; } catch { /* עוד לא */ } if (Date.now() - t > ms) return null; await new Promise(r => setTimeout(r, 100)); } };

  /** כותרות הבידוד של כתובת התצוגה — בבקשה אמיתית מהדפדפן: 200, 404, ו-403 בלי אסימון. */
  async function headers(tok, path) {
    const out = [];
    const want = 'sandbox allow-scripts';
    for (const [name, url, status] of [['קובץ', `preview/${tok}/${path}`, 200], ['404', `preview/${tok}/__no-such-file.html`, 404], ['בלי אסימון', `preview/x/${path}`, 403], ['אסימון שגוי', `preview/${tok.slice(0, -4)}AAAA/${path}`, 403]]) {
      const res = await fetch(url, { cache: 'no-store', credentials: 'omit' });
      const csp = res.headers.get('content-security-policy') || '';
      const good = res.status === status && csp.startsWith(want) && !csp.includes('allow-same-origin') && res.headers.get('x-content-type-options') === 'nosniff'
        && res.headers.get('access-control-allow-origin') === '*';
      out.push({ name, ok: good, text: `${res.status}${res.status === status ? '' : ' (ציפיתי ל-' + status + ')'} · ${csp ? csp.slice(0, 28) + '…' : 'בלי CSP!'}` });
    }
    return out;
  }

  /** מריצה את הערכה. מחזירה { ok, checks: [{ name, ok, text }], control }. */
  async function run() {
    const app = new URL('.', location.href).href;
    const dir = '__kol-attack-' + Math.random().toString(36).slice(2, 8);
    const secret = 'CANARY-' + Math.random().toString(36).slice(2);
    const checks = [];
    const add = (name, ok, text) => checks.push({ name, ok: !!ok, text: String(text) });
    let frame = null;
    try {
      const canary = (await api('pf_create', { path: dir + '/canary.txt', content: secret })).file.id;
      await api('pf_create', { path: dir + '/attack.html', content: page(app, canary) });
      const hash0 = location.hash;
      frame = document.createElement('iframe');
      frame.style.cssText = 'position:fixed;left:-9999px;top:0;width:480px;height:600px;border:0';
      frame.setAttribute('aria-hidden', 'true');
      frame.src = 'live.php?path=' + encodeURIComponent(dir + '/attack.html');
      document.body.append(frame);
      const lw = () => frame.contentWindow;
      const kitLine = await wait(() => [...lw().Live.state.lines, ...lw().Live.state.pending].find(l => l.text.startsWith('KOLKIT ')), 20000);
      if (!kitLine) throw new Error('הקובץ התוקף לא דיווח תוך 20 שניות — התצוגה החיה לא נטענה');
      await new Promise(r => setTimeout(r, 700));                 // ההודעות וה-fetch-ים האחרונים
      const r = JSON.parse(kitLine.text.slice(7)), st = lw().Live.state, doc = lw().document;
      const sb = doc.querySelector('.lv-iframe').getAttribute('sandbox') || '';

      add('המסגרת: sandbox בלי allow-same-origin ובלי allow-top-navigation', /\ballow-scripts\b/.test(sb) && !/allow-same-origin|allow-top-navigation/.test(sb), sb);
      add('קוד העמוד רץ במקור אטום (S1)', r.origin === 'null', 'origin = ' + r.origin);
      add('document.cookie — חריגה', r.cookie === 'throw', r.cookie);
      add('localStorage — חריגה', r.storage === 'throw', r.storage);
      add('parent.document — חריגה', r.parent === 'throw', r.parent);
      add('top.location = … — נחסם', r.topnav === 'throw' && location.hash === hash0 && lw().location.pathname.endsWith('/live.php'), r.topnav + ' · הלשונית לא זזה');
      add('fetch ל-api.php עם credentials — נכשל, או נענה כמי שאינו מחובר', r.api === 'blocked' || r.api === 'anon', r.api);
      const now = await api('pf_read', undefined, '&id=' + canary);
      add('כתיבה ל-api.php מתוך התצוגה — הקובץ לא השתנה', now.content === secret && now.file.rev === 1, now.content === secret ? 'התוכן והגרסה כמו שהיו' : 'הקובץ נדרס!');
      add('הודעות שאינן מהסוגים המותרים — הסטודיו מתעלם', st.ignored >= r.bad && st.path === dir + '/attack.html', `${st.ignored} נדחו מתוך ${r.bad} שנשלחו · הנתיב נשאר ${st.path}`);
      const shown = doc.getElementById('lv-title').textContent === HOSTILE && st.lines.some(l => l.text === HOSTILE);
      const ran = window.pwned !== undefined || lw().pwned !== undefined || doc.querySelector('.lv img, .lv [onerror]') !== null;
      add('מחרוזת HTML מהעמוד (כותרת, מסוף, כלי בדיקה) — מוצגת כטקסט, ואינה רצה', shown && !ran, shown ? (ran ? 'הקוד רץ!' : 'מופיעה כטקסט, ושום img לא נוצר') : 'המחרוזת לא הגיעה למסך');
      add('שורת מסוף עם נתיב שמטפס החוצה — בלי "פתח בקוד"', st.lines.filter(l => l.text === HOSTILE && l.level === 'error').every(l => l.file === '' && l.line === 0), 'file ו-line נדחו');
      for (const h of await headers(lw().Live.state.tok.token, dir + '/attack.html')) add('כותרות הבידוד · ' + h.name, h.ok, h.text);

      // ביקורת חיובית: אותו קוד, במקור של הסטודיו — חייב להצליח לגעת. אחרת הבדיקות למעלה אינן מוכיחות דבר
      const c = await probe(app, 0, HOSTILE, true);
      const control = c.cookie === 'read' && c.storage === 'read' && c.parent === 'read' && c.api === 'AUTH' && c.origin !== 'null';
      add('ביקורת חיובית: אותו קוד במקור של הסטודיו כן קורא עוגייה, אחסון ו-api.php', control, `cookie ${c.cookie} · storage ${c.storage} · parent ${c.parent} · api ${c.api}`);
    } catch (e) {
      add('הריצה עצמה', false, e.message);
    } finally {
      frame?.remove();
      try {
        await api('pf_delete', { path: dir });
        const gone = (await api('pf_trash')).items.filter(i => i.path === dir || i.path.startsWith(dir + '/')).map(i => i.id);
        if (gone.length) await api('pf_purge', { ids: gone, confirm: true }).catch(() => {});      // מחיקה סופית — רק לבעלים; אחרת נשאר בסל
      } catch { /* לא נוצר */ }
    }
    const ok = checks.length > 1 && checks.every(c => c.ok);
    const bad = checks.filter(c => !c.ok);
    let last = null;
    try { last = (await api('dev-attack-save', { ok, note: ok ? `כל ${checks.length} הבדיקות עברו` : 'נכשלו: ' + bad.map(c => c.name).join(' | ') })).attack; } catch { /* התוצאה מוצגת גם בלי שנשמרה */ }
    return { ok, checks, last };
  }

  return { run, headers, probe, page, HOSTILE };
})();
