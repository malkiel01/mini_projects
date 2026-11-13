/**
 * פרוסה F2a — בדיקות בדפדפן (Chromium, Playwright):   node kol-maroko/tests/browser/f2a.cjs
 *
 * בדיקת ההתקפה של S4: קובץ פרויקט x.html / x.svg עם סקריפט שקורא ל-api.php — פתיחת הכתובת שלו בדפדפן אינה מריצה
 * אותו במקור של הפלטפורמה. וכן: שם קובץ שהוא HTML מוצג כטקסט; העורך נטען מהאפליקציה (שום בקשה לשרת אחר);
 * והמסך עובד ב-390px וב-1280px. רץ מול tests/router.php ומסד זמני — data/ לא נפתחת.
 */
'use strict';
const { chromium } = require('playwright');
const { spawn } = require('child_process');
const fs = require('fs'), os = require('os'), path = require('path'), net = require('net');

const ROOT = path.resolve(__dirname, '..', '..');
const SHOTS = process.env.KOL_SHOTS || '';
const OWNER = { sub: 'u-owner', name: 'Owner Test', email: 'owner@example.test', email_verified: true, platform_admin: true };
let failed = 0, ran = 0;
const ok = (cond, what) => { ran++; if (cond) console.log('✅ ' + what); else { failed++; console.log('❌ ' + what); } };
const freePort = () => new Promise(r => { const s = net.createServer().listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => r(p)); }); });
const sleep = ms => new Promise(r => setTimeout(r, ms));

(async () => {
  const data = fs.mkdtempSync(path.join(os.tmpdir(), 'kol-f2a-'));
  const port = await freePort(), base = `http://127.0.0.1:${port}`;
  const php = spawn('php', ['-S', `127.0.0.1:${port}`, path.join(ROOT, 'tests', 'router.php')], { cwd: ROOT, env: { ...process.env, KOL_TEST_DATA: data, PHP_CLI_SERVER_WORKERS: '4' }, stdio: 'ignore' });
  let browser;
  try {
    for (let i = 0; i < 100; i++) { try { await fetch(base + '/api.php?action=embed_key'); break; } catch { await sleep(50); } }
    browser = await chromium.launch();
    const foreign = [], pwned = [];
    const mk = async (width, height) => {
      const ctx = await browser.newContext({ viewport: { width, height }, extraHTTPHeaders: { 'X-Test-User': JSON.stringify(OWNER) }, acceptDownloads: true });
      ctx.on('request', r => { const u = r.url(); if (!u.startsWith(base) && !/^(data|blob):/.test(u)) foreign.push(u); if (u.includes('pwned')) pwned.push(u); });
      return ctx;
    };
    const call = (page, action, body, query = '') => page.evaluate(([a, b, q]) => api(a, b ?? undefined, q), [action, body ?? null, query]);

    // ── 1280px: המסך, העורך, שמירה ─────────────────────────
    const ctx = await mk(1280, 800), page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));
    page.on('dialog', d => d.accept());
    await page.goto(base + '/#/project');
    await page.waitForSelector('.pj-tree .pj-row');
    ok((await page.textContent('.pj-note')).includes('מ-🚀 פרסום') && !(await page.textContent('.pj-note')).includes('אינם מתפרסמים'), 'הסרגל הקבוע: השינויים מגיעים לאתר מ-🚀 פרסום (T2 — לא עוד "אינם מתפרסמים")');
    const names = await page.$$eval('.pj-tree .pj-name .one', els => els.map(e => e.textContent));
    ok(['css', 'from-current', 'js', 'index.html', 'kol.json'].every(n => names.includes(n)), 'העץ מציג את השלד ואת from-current/');
    await page.click('.pj-tree .pj-name:has-text("index.html")');
    await page.waitForSelector('.pj-ed .monaco-editor .view-line');
    ok(await page.evaluate(() => !!window.monaco && [...document.scripts].every(s => !s.src || s.src.startsWith(location.origin))), 'Monaco נטען מהאפליקציה');
    ok(await page.evaluate(() => monaco.editor.getModels()[0].getLanguageId()) === 'html', 'הדגשת תחביר לפי סיומת (html)');

    // קוד שבור נשמר כמו שהוא — Ctrl+S
    const broken = '<div><script>if (</scr' + 'ipt>\n<p onclick="x(">לא סגור\n{{#a}}';
    await page.evaluate(t => monaco.editor.getModels()[0].setValue(t), broken);
    ok(await page.$('.pj-tab.on .pj-dot') !== null && (await page.textContent('.pj-status')).includes('לא נשמר'), 'סימון "לא נשמר" בלשונית');
    await page.keyboard.press('Control+s');
    await page.waitForFunction(() => document.querySelector('.pj-status').textContent.includes('✓'));
    const idIndex = await page.evaluate(() => Project._state.cur);
    ok((await call(page, 'pf_read', null, `&id=${idIndex}`)).content === broken, 'קוד שבור נשמר כמו שהוא (Ctrl+S)');
    // שמירה אוטומטית
    await page.evaluate(t => monaco.editor.getModels()[0].setValue(t), broken + '\n<!-- auto -->');
    await page.waitForFunction(() => document.querySelector('.pj-status').textContent.includes('✓'), null, { timeout: 8000 });
    ok((await call(page, 'pf_read', null, `&id=${idIndex}`)).content.endsWith('<!-- auto -->'), 'שמירה אוטומטית אחרי השהיה');

    // שתי לשוניות; נשמרות בין ביקורים, עם המיקום
    await page.click('.pj-tree .pj-name:has-text("kol.json")');
    await page.waitForFunction(() => document.querySelectorAll('.pj-tab').length === 2);
    await page.evaluate(() => { const e = Project._state.editor; e.setPosition({ lineNumber: 4, column: 3 }); e.revealLine(4); });
    await page.reload();
    await page.waitForSelector('.pj-ed .monaco-editor .view-line');
    ok(await page.$$eval('.pj-tab', t => t.length) === 2 && (await page.textContent('.pj-tab.on')).includes('kol.json'), 'הלשוניות נשמרות בין ביקורים');
    ok(await page.evaluate(() => Project._state.editor.getPosition().lineNumber) === 4, 'המיקום בקובץ נשמר');

    // kol.json לא תקין: מסומן, והמסך ממשיך
    await page.evaluate(() => Project._state.editor.getModel().setValue('{\n  "entry": "index.html",\n  שבור\n}'));
    await page.keyboard.press('Control+s');
    await page.waitForSelector('.pj-warn:not([hidden])');
    const warn = await page.textContent('.pj-warn');
    ok(warn.includes('kol.json לא תקין') && warn.includes('הגרסה התקינה האחרונה'), 'kol.json לא תקין: מדווח, וממשיכים עם הגרסה התקינה האחרונה');
    await page.waitForFunction(() => monaco.editor.getModelMarkers({}).length > 0, null, { timeout: 8000 });
    ok(await page.evaluate(() => monaco.editor.getModelMarkers({})[0].startLineNumber) === 3, 'השורה השבורה מסומנת בעורך (שורה 3)');
    ok(await page.$$eval('.pj-tree .pj-row', r => r.length) > 5, 'העץ ממשיך לעבוד');
    await page.evaluate(() => Project._state.editor.getModel().setValue('{"version":1,"entry":"index.html"}'));
    await page.keyboard.press('Control+s');
    await page.waitForSelector('.pj-warn', { state: 'hidden' });

    // ── ההתקפה (S4) ────────────────────────────────────────
    const evilHtml = `<!doctype html><title>x</title><script>fetch('/api.php?action=me&pwned=html').then(r=>r.json()).then(d=>fetch('/api.php?action=pf_create',{method:'POST',headers:{'Content-Type':'application/json','X-Kol':'1','X-CSRF':d.csrf},body:JSON.stringify({path:'pwned-by-html.txt',content:'x'})}))</scr` + `ipt>`;
    const evilSvg = `<svg xmlns="http://www.w3.org/2000/svg" onload="fetch('/api.php?action=me&pwned=svg')"><script>fetch('/api.php?action=me&pwned=svg-script')</scr` + `ipt><circle r="9"/></svg>`;
    const xssName = 'attack/<img src=x onerror="window.__xss=1">.html';
    const hId = (await call(page, 'pf_create', { path: 'attack/x.html', content: evilHtml })).file.id;
    const sId = (await call(page, 'pf_create', { path: 'attack/x.svg', content: evilSvg })).file.id;
    const nId = (await call(page, 'pf_create', { path: xssName, content: '<script>window.__xss=2</scr' + 'ipt><img src=x onerror="window.__xss=3">' })).file.id;

    // ביקורת: אותו HTML, כשהוא מוגש כמסמך רגיל במקור של הפלטפורמה — כן מריץ. כך יודעים שהבדיקה מסוגלת לתפוס
    const ctl = await ctx.newPage();
    await ctl.route('**/__control.html', r => r.fulfill({ contentType: 'text/html', body: evilHtml }));
    await ctl.goto(base + '/__control.html'); await sleep(600);
    ok(pwned.some(u => u.includes('pwned=html')), 'ביקורת: אותו קובץ כמסמך רגיל במקור הפלטפורמה — הסקריפט רץ (הבדיקה תופסת)');
    await ctl.close();
    const made = (await call(page, 'pf_tree')).files.find(f => f.path === 'pwned-by-html.txt');
    if (made) await call(page, 'pf_delete', { id: made.id });
    pwned.length = 0;

    for (const [id, label] of [[hId, 'x.html'], [sId, 'x.svg']]) {
      for (const dl of ['', '&download=1']) {
        const p = await ctx.newPage();
        const res = await p.goto(`${base}/api.php?action=pf_raw&id=${id}${dl}`).catch(() => null);   // הורדה מבטלת את הניווט
        await sleep(500);
        if (res) {
          const hd = res.headers();
          ok(/(^|;)\s*sandbox/.test(hd['content-security-policy'] || '') && hd['x-content-type-options'] === 'nosniff', `${label}${dl}: נפתח עם CSP sandbox ו-nosniff`);
          ok(await p.evaluate(() => window.origin).catch(() => 'null') === 'null', `${label}${dl}: המסמך אינו במקור של הפלטפורמה (origin = null)`);
        } else ok(true, `${label}${dl}: הדפדפן מוריד את הקובץ ואינו פותח אותו כמסמך`);
        await p.close();
      }
      // וגם כשהוא מוטמע בדף של הפלטפורמה
      await page.evaluate(src => new Promise(r => { const f = document.createElement('iframe'); f.src = src; f.onload = f.onerror = () => setTimeout(r, 400); document.body.append(f); setTimeout(r, 1500); }), `api.php?action=pf_raw&id=${id}`);
      await page.evaluate(src => new Promise(r => { const o = document.createElement('object'); o.data = src; document.body.append(o); const i = new Image(); i.onload = i.onerror = () => setTimeout(r, 400); i.src = src; document.body.append(i); }), `api.php?action=pf_raw&id=${id}`);
    }
    await sleep(500);
    ok(pwned.length === 0, 'התקפה: x.html ו-x.svg — שום סקריפט לא רץ ולא קרא ל-api.php' + (pwned.length ? ' · ' + pwned.join(', ') : ''));
    ok(!(await call(page, 'pf_tree')).files.some(f => f.path === 'pwned-by-html.txt'), 'התקפה: הקובץ לא יצר דבר בשם המשתמש');

    // שם ותוכן כטקסט בלבד
    await page.reload();
    await page.waitForSelector('.pj-tree .pj-row');
    const row = page.locator('.pj-tree .pj-name', { hasText: '<img src=x onerror' });
    ok(await row.count() === 1, 'שם קובץ שהוא HTML מוצג בעץ כטקסט');
    await row.click();
    await page.waitForFunction(() => document.querySelector('.pj-tab.on')?.textContent.includes('<img'));
    await page.click('.pj-tree .pj-row.on .pj-more');
    await page.waitForSelector('#sheet[open]');
    ok((await page.textContent('#sheet-title')).includes('<img src=x'), 'גם בכותרת התפריט — טקסט');
    await page.click('#sheet .pj-menu .btn.danger');                               // מחיקה לסל (confirm)
    await page.waitForFunction(() => !document.querySelector('#sheet[open]'));
    await sleep(600);
    ok(await page.evaluate(() => window.__xss === undefined && !document.querySelector('.pj img[src="x"], #sheet img[src="x"]')), 'שום אלמנט לא נוצר מהשם או מהתוכן, ושום קוד לא רץ');

    // קובץ של 2MB נפתח ונערך
    const big = ('/* line */ .a { color: red; }\n').repeat(70000).slice(0, 2 * 1024 * 1024 - 64);
    const bigId = (await call(page, 'pf_create', { path: 'big/2mb.css', content: big })).file.id;
    await page.reload(); await page.waitForSelector('.pj-tree .pj-row');
    const t0 = Date.now();
    await page.evaluate(id => { window.__t = performance.now(); return 0; }, bigId);
    await page.click('.pj-tree .pj-name:has-text("2mb.css")');
    await page.waitForFunction(() => Project._state.editor?.getModel()?.getValueLength() > 2000000, null, { timeout: 20000 });
    const openMs = Date.now() - t0;
    await page.click('.pj-ed .monaco-editor');
    const t1 = Date.now();
    await page.keyboard.type('/* x */');
    await page.waitForFunction(() => Project._state.editor.getModel().getValue().startsWith('/* x */') || Project._state.editor.getModel().getValue().includes('/* x */'));
    const typeMs = Date.now() - t1;
    await page.keyboard.press('Control+s');
    await page.waitForFunction(() => document.querySelector('.pj-status').textContent.includes('✓'), null, { timeout: 20000 });
    ok(openMs < 8000 && typeMs < 3000, `קובץ של 2MB נפתח (${openMs}ms), נערך (${typeMs}ms) ונשמר`);
    if (SHOTS) await page.screenshot({ path: path.join(SHOTS, 'f2a-1280.png') });

    // תצוגה לקובץ בינארי
    const png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
    await call(page, 'pf_create', { path: 'media/dot.png', content_b64: png });
    await page.reload(); await page.waitForSelector('.pj-tree .pj-row');
    await page.click('.pj-tree .pj-name:has-text("dot.png")');
    await page.waitForSelector('.pj-bin img');
    await page.waitForFunction(() => document.querySelector('.pj-bin img').naturalWidth === 1);
    ok((await page.textContent('.pj-bin')).includes('B') && await page.$('.pj-bin .pj-dl') !== null, 'קובץ בינארי נפתח כתצוגה: התמונה, הגודל, הסוג והורדה');
    ok(errors.length === 0, 'אין שגיאות JavaScript בדף' + (errors.length ? ' · ' + errors.join(' | ') : ''));
    await ctx.close();

    // ── 390px: העץ כגיליון, כל הפעולות מתפריט השורה ─────────
    const m = await mk(390, 760), mp = await m.newPage();
    const merr = [];
    mp.on('pageerror', e => merr.push(e.message));
    mp.on('dialog', d => d.accept());
    await mp.goto(base + '/#/project');
    await mp.waitForSelector('.pj-files-btn');
    // ממצא הבעלים (טלפון): בלי קובץ פתוח רואים את העץ עצמו, לא מסך ריק שמפנה לכפתור
    await mp.waitForSelector('.pj-side', { state: 'visible' });
    ok(await mp.isVisible('.pj-tree .pj-row'), '390px: בלי קובץ פתוח — העץ מוצג מיד בכניסה למסך');
    ok((await mp.textContent('.pj-files-btn')).includes('קבצי הקוד'), '390px: הכפתור נקרא "📂 קבצי הקוד" (לא כשם הלשונית "🗂️ קבצים")');
    ok(await mp.evaluate(() => document.documentElement.scrollWidth <= innerWidth), '390px: אין גלילה אופקית');
    await mp.click('.pj-side-x');
    ok(!(await mp.isVisible('.pj-side')) && await mp.isVisible('.pj-view .btn.primary'), '390px: אחרי סגירת העץ — כפתור ברור "📂 קבצי הקוד — בחירת קובץ" במקום ההודעה');
    await mp.click('.pj-view .btn.primary');
    await mp.waitForSelector('.pj-side', { state: 'visible' });
    if (SHOTS) await mp.screenshot({ path: path.join(SHOTS, 'f2a-390-tree.png') });
    await mp.click('.pj-side-btns .btn:has-text("+ קובץ")');
    await mp.fill('#sheet input', 'phone/חדש.html');
    await mp.click('#sheet .btn.primary');
    await mp.waitForFunction(() => document.querySelector('.pj-tab.on')?.textContent.includes('חדש.html'));
    ok(true, '390px: קובץ חדש נוצר ונפתח בלשונית');
    const menuDo = async (rowText, item) => {
      if (!(await mp.isVisible('.pj-side'))) await mp.click('.pj-files-btn');
      await mp.click(`.pj-tree .pj-row:has(.pj-name:has-text("${rowText}")) .pj-more`);
      await mp.waitForSelector('#sheet[open] .pj-menu');
      await mp.click(`#sheet .pj-menu .btn:has-text("${item}")`);
    };
    await menuDo('חדש.html', 'שינוי שם');
    await mp.fill('#sheet input', 'phone/שונה.html');
    await mp.click('#sheet .btn.primary');
    await mp.waitForSelector('.pj-tree .pj-name:has-text("שונה.html")');
    ok(true, '390px: שינוי שם מתפריט השורה');
    await menuDo('phone', 'תיקייה חדשה כאן');
    await mp.fill('#sheet input', 'phone/תת');
    await mp.click('#sheet .btn.primary');
    await mp.waitForSelector('.pj-tree .pj-name:has-text("תת")');
    ok(true, '390px: תיקייה חדשה בתוך תיקייה');
    const [dl] = await Promise.all([mp.waitForEvent('download'), menuDo('index.html', 'הורדה')]);
    ok(dl.suggestedFilename() === 'index.html' && fs.readFileSync(await dl.path(), 'utf8') === '{"version":1,"entry":"index.html"}'.replace(/.*/, broken + '\n<!-- auto -->'), '390px: הורדה של קובץ — בשמו, ובייט בבייט · ' + dl.suggestedFilename());
    const [dl2] = await Promise.all([mp.waitForEvent('download'), menuDo('שונה.html', 'הורדה')]);
    console.log('   (שם בעברית בהורדה, בדפדפן הבדיקה: ' + dl2.suggestedFilename() + ')');
    await mp.waitForFunction(() => !document.querySelector('#sheet[open]'));
    await menuDo('שונה.html', 'מחיקה לסל');
    await mp.waitForFunction(() => ![...document.querySelectorAll('.pj-tree .one')].some(e => e.textContent === 'שונה.html'));
    ok(true, '390px: מחיקה לסל');
    await mp.click('.pj-side-btns .btn:has-text("סל")');
    await mp.waitForSelector('#sheet[open] .pj-trash');
    if (SHOTS) await mp.screenshot({ path: path.join(SHOTS, 'f2a-390-trash.png') });
    await mp.click('#sheet .pj-trash:has-text("שונה.html") .btn:has-text("שחזור")');
    await mp.waitForSelector('.pj-tree .pj-name:has-text("שונה.html")');
    ok(true, '390px: שחזור מהסל');
    const inTrash = await mp.$$eval('#sheet .pj-trash', x => x.length);
    await mp.click('#sheet .pj-trash .btn:has-text("מחיקה סופית") >> nth=0');
    await mp.waitForFunction(n => document.querySelectorAll('#sheet .pj-trash').length === n - 1, inTrash, { timeout: 5000 });
    ok(true, '390px: מחיקה סופית מהסל, באישור');
    await mp.click('#sheet [data-close]');
    // העלאה: קובץ טקסט וקובץ שאינו UTF-8
    const [chooser] = await Promise.all([mp.waitForEvent('filechooser'), mp.click('.pj-side-btns .btn:has-text("העלאה")')]);
    await chooser.setFiles([
      { name: 'up.html', mimeType: 'text/html', buffer: Buffer.from('﻿<script>x</scr' + 'ipt>\r\nעברית') },
      { name: 'font.woff2', mimeType: 'font/woff2', buffer: Buffer.from([0, 1, 2, 255, 254, 128]) },
    ]);
    await mp.waitForSelector('.pj-tree .pj-name:has-text("font.woff2")');
    const tree = (await call(mp, 'pf_tree')).files;
    const up = await call(mp, 'pf_read', null, `&id=${tree.find(f => f.path === 'up.html').id}`);
    const bin = await call(mp, 'pf_read', null, `&id=${tree.find(f => f.path === 'font.woff2').id}`);
    ok(up.content === '﻿<script>x</scr' + 'ipt>\r\nעברית' && bin.b64 === Buffer.from([0, 1, 2, 255, 254, 128]).toString('base64'), '390px: העלאה של קבצים — בייט בבייט (BOM, CRLF, בינארי)');
    // מדיה: נכנסת לספריית המדיה, ומופיעה בעץ כהפניה
    const [ch2] = await Promise.all([mp.waitForEvent('filechooser'), mp.click('.pj-side-btns .btn:has-text("העלאה")')]);
    await ch2.setFiles([{ name: 'pic.png', mimeType: 'image/png', buffer: Buffer.from(png, 'base64') }]);
    await mp.waitForSelector('.pj-tree .pj-row:has-text("pic.png") .chip:has-text("מדיה")');
    ok((await call(mp, 'pf_tree')).files.find(f => f.path === 'pic.png').kind === 'media', '390px: תמונה שהועלתה נכנסה לספריית המדיה ומופיעה בעץ כהפניה');
    await mp.click('.pj-tree .pj-name:has-text("pic.png")');
    await mp.waitForFunction(() => document.querySelector('.pj-bin img')?.naturalWidth > 0);
    ok(true, '390px: הפניה למדיה נפתחת כתצוגה');

    // התנגשות rev: מישהו שמר בינתיים — שתי הגרסאות מוצגות, ושום דבר לא נדרס בשקט
    await mp.click('.pj-files-btn');
    await mp.click('.pj-tree .pj-name:has-text("up.html")');
    await mp.waitForSelector('.pj-ed .monaco-editor .view-line');
    const upId = await mp.evaluate(() => Project._state.cur);
    await call(mp, 'pf_write', { id: upId, rev: 1, content: 'מישהו אחר שמר' });
    await mp.evaluate(() => Project._state.editor.getModel().setValue('הגרסה שלי'));
    await mp.keyboard.press('Control+s');
    await mp.waitForSelector('#sheet[open] .pj-diff');
    ok((await mp.textContent('#sheet-title')) === 'הקובץ השתנה בינתיים' && (await call(mp, 'pf_read', null, `&id=${upId}`)).content === 'מישהו אחר שמר', '390px: rev ישן — "הקובץ השתנה בינתיים", עם שתי הגרסאות; השרת לא נדרס');
    await mp.click('#sheet .btn:has-text("לשמור את שלי")');
    await mp.waitForFunction(() => document.querySelector('.pj-status').textContent.includes('✓'));
    ok((await call(mp, 'pf_read', null, `&id=${upId}`)).content === 'הגרסה שלי', '390px: אחרי בחירה מפורשת — נשמר');

    // כלי העורך: חיפוש, מעבר לשורה, עטיפה, גופן, בטל
    const tool = async name => { await mp.click('.pj-bar [aria-label="כלי העורך"]'); await mp.waitForSelector('#sheet[open] .pj-menu'); await mp.click(`#sheet .pj-menu .btn:has-text("${name}")`); await mp.waitForFunction(() => !document.querySelector('#sheet[open]')); };
    const font0 = await mp.evaluate(() => Project._state.editor.getOption(monaco.editor.EditorOption.fontSize));
    await tool('גופן גדול יותר');
    ok(await mp.evaluate(() => Project._state.editor.getOption(monaco.editor.EditorOption.fontSize)) === font0 + 1, '390px: גודל גופן');
    await tool('בלי עטיפת שורות');
    ok(await mp.evaluate(() => Project._state.editor.getOption(monaco.editor.EditorOption.wordWrap)) === 'off', '390px: עטיפת שורות');
    await tool('חיפוש והחלפה');
    await mp.waitForSelector('.monaco-editor .find-widget.visible', { timeout: 5000 });
    ok(await mp.isVisible('.monaco-editor .find-widget .replace-part'), '390px: חיפוש והחלפה בקובץ');
    await mp.keyboard.press('Escape');
    await mp.evaluate(() => { const e = Project._state.editor; e.focus(); e.executeEdits('t', [{ range: new monaco.Range(1, 1, 1, 1), text: 'XX' }]); e.pushUndoStop(); });
    await tool('בטל');
    ok(await mp.evaluate(() => Project._state.editor.getModel().getValue()) === 'הגרסה שלי', '390px: בטל');
    await tool('חזור');
    ok(await mp.evaluate(() => Project._state.editor.getModel().getValue()) === 'XXהגרסה שלי', '390px: חזור');
    await mp.waitForFunction(() => document.querySelector('.pj-status').textContent.includes('✓'), null, { timeout: 8000 });

    // 📏 מגבלות במסך המפתח, והלשונית הישנה </> קוד עם Monaco מקומי
    await mp.goto(base + '/#/dev/limits');
    await mp.waitForSelector('.pj-limit');
    ok(await mp.$$eval('.pj-limit input', i => i.slice(0, 4).map(x => +x.value).join()) === '200,2097152,2000,200', '390px: 📏 מגבלות במסך המפתח — ארבע ההגדרות של 02 §3 (ואחריהן שתי מגבלות ה-ZIP של F2b)');
    await call(mp, 'code_create', {});
    await mp.goto(base + '/#/code');
    await mp.waitForSelector('.code-ed .monaco-editor .view-line', { timeout: 20000 });
    ok(true, 'הלשונית הישנה </> קוד נטענת עם Monaco מהאפליקציה');
    await mp.goto(base + '/#/project');
    await mp.waitForSelector('.pj-ed .monaco-editor .view-line', { timeout: 20000 });
    await tool('חיפוש בקובץ');
    await mp.waitForSelector('.monaco-editor .find-widget.visible', { timeout: 5000 });
    ok(true, 'חזרה למסך הפרויקט אחרי הלשונית הישנה — העורך וכליו עובדים');
    if (SHOTS) await mp.screenshot({ path: path.join(SHOTS, 'f2a-390-editor.png') });
    ok(merr.length === 0, '390px: אין שגיאות JavaScript' + (merr.length ? ' · ' + merr.join(' | ') : ''));
    await m.close();

    ok(foreign.length === 0, 'שום בקשה לשרת אחר (Monaco מהאפליקציה, לא מ-CDN)' + (foreign.length ? ' · ' + [...new Set(foreign)].slice(0, 3).join(', ') : ''));
  } catch (e) {
    failed++;
    console.log('❌ הבדיקה נעצרה: ' + e.message.split('\n')[0]);
  } finally {
    if (browser) await browser.close();
    php.kill();
    fs.rmSync(data, { recursive: true, force: true });
  }
  console.log(`\n${failed ? `נכשלו ${failed} מתוך ${ran}` : `כל ${ran} הבדיקות עברו`}`);
  process.exit(failed ? 1 : 0);
})();
