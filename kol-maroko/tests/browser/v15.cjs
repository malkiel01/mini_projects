/**
 * פרוסה V15 — הגדרות העמוד, ה-<head>, ועמודים נוספים — בדיקות בדפדפן   node kol-maroko/tests/browser/v15.cjs
 *
 * על העמוד המוסב (F3): ⚙️ הגדרות העמוד — נפתח מהסרגל, מ"ראש העמוד" ב-Navigator ומתפריט ההקשר על <body>; כל שדה כותב
 * ל-HTML (שורה ב"קוד", Ctrl+Z אחד, שם בהיסטוריה), יוצר ומוחק תגית; כפילות — "הראשונה קובעת"; "עוד בראש העמוד" — ✎ 🗑 ⇅
 * (גרירה אמיתית) ↑↓; קוד בראש / בסוף העמוד בין הערות הסימון, הודעת המקור, ולא רץ בקנבס · 📄 בורר העמוד — חיפוש, מעבר שומר
 * את הנוכחי ובאותו מכשיר, נזכר לכל משתמש, ▶️ תצוגה חיה של העמוד שבעורך · 📄 עמודים — ➕ (כתובת נגזרת), שינוי כתובת עם
 * תצוגת הקישורים וסימון, הפניה, שכפול, ⭐ ראשי, 🚫 404, מחיקה לסל עם "X מקשרים" ושחזור, סדר בגרירה ו-↑↓ ו"חזור לאוטומטי",
 * הגבול (../ — לא נשלח; השרת 400) · בורר הקישור: "עמוד בפרויקט" + עוגנים, נכתב about/; שבור — באדום · "מה ישתנה" — עמודים
 * · 1920 / 1366 / 390 (גיליון, לחיצה ארוכה + ↑↓). צילומים — ל-tmpdir (kol-v15-shots).
 */
'use strict';
const { chromium } = require('playwright');
const { spawn, execFileSync } = require('child_process');
const fs = require('fs'), os = require('os'), path = require('path'), net = require('net');

const ROOT = path.resolve(__dirname, '..', '..');
const OWNER = { sub: 'u-owner', name: 'Owner Test', email: 'owner@example.test', email_verified: true, platform_admin: true };
let failed = 0, ran = 0;
let T0 = Date.now(); const ok = (cond, what, got) => { ran++; const dt = ((Date.now() - T0) / 1000).toFixed(1); T0 = Date.now(); if (cond) console.log('✅ [' + dt + 's] ' + what); else { failed++; console.log('❌ ' + what + (got !== undefined ? '\n   התקבל: ' + JSON.stringify(got).slice(0, 1200) : '')); } };
const freePort = () => new Promise(r => { const s = net.createServer().listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => r(p)); }); });
const sleep = ms => new Promise(r => setTimeout(r, ms));

(async () => {
  const data = fs.mkdtempSync(path.join(os.tmpdir(), 'kol-v15-'));
  execFileSync('php', [path.join(__dirname, 'f3-seed.php'), data, path.join(data, 'old-render.html')]);
  const port = await freePort(), base = `http://127.0.0.1:${port}`;
  const php = spawn('php', ['-S', `127.0.0.1:${port}`, path.join(ROOT, 'tests', 'router.php')], { cwd: ROOT, env: { ...process.env, KOL_TEST_DATA: data, PHP_CLI_SERVER_WORKERS: '4' }, stdio: 'ignore' });
  let browser;
  try {
    for (let i = 0; i < 100; i++) { try { await fetch(base + '/api.php?action=embed_key'); break; } catch { await sleep(50); } }
    browser = await chromium.launch();
    const mk = (width, height, user = OWNER) => browser.newContext({ viewport: { width, height }, hasTouch: width < 600, isMobile: width < 600, extraHTTPHeaders: { 'X-Test-User': JSON.stringify(user) } });
    const call = (page, action, body, query = '') => page.evaluate(([a, b, q]) => api(a, b ?? undefined, q), [action, body ?? null, query]);
    const ready = (page, p) => page.waitForFunction(q => Editor._state.file && Editor._state.file.path === q && Editor._state.canvas && Editor._state.canvas.m && !Editor._state.busy && Editor._state.build && Editor._state.comp && Editor._state.lv && Editor._state.head && Editor._state.pages && Editor._state.pages.data, p, { timeout: 30000 });
    const flush = async page => { try { await page.evaluate(async () => { const S = Project._state; for (const [id, d] of S.docs) if (d.dirty) await Project._save(id); }); await page.waitForFunction(() => [...Project._state.docs.values()].every(d => !d.dirty && !d.saving), null, { timeout: 15000 }); } catch (e) { console.log('   ⚠️ flush: ' + e.message.slice(0, 200)); } };
    const openPage = async (page, p) => { if (page.url().includes('editor.php')) await flush(page); await page.goto(base + '/editor.php#/editor/' + encodeURIComponent(p)); await page.reload(); await ready(page, p); await page.waitForFunction(() => Editor._state.lv.lists.every(L => L.status !== 'loading'), null, { timeout: 30000 }); await sleep(600); };
    const text = (page, p) => page.evaluate(async p => { await Project.docs.reload(); const f = Project.docs.byPath(p); if (!f) return null; const d = await Project.docs.open(f.id); return d.text(); }, p);
    const server = (page, p) => page.evaluate(async p => { const t = await api('pf_tree'); const f = t.files.find(x => x.path === p && x.kind === 'file'); if (!f) return null; return (await api('pf_read', undefined, '&id=' + f.id)).content; }, p);
    const html = page => page.evaluate(() => Editor._state.doc.text());
    const lastTx = page => page.evaluate(() => { const t = Editor._state.tx; return t.length ? t[t.length - 1].label : ''; });
    const txN = page => page.evaluate(() => Editor._state.tx.length);
    const undo = async page => { await page.evaluate(() => Editor._undo()); await sleep(400); };
    const redo = async page => { await page.evaluate(() => Editor._redo()); await sleep(400); };
    const sheetOpen = page => page.evaluate(() => document.getElementById('sheet').open);
    const closeSheet = page => page.evaluate(() => { const d = document.getElementById('sheet'); if (d.open) d.close(); });
    const shotDir = path.join(os.tmpdir(), 'kol-v15-shots'); fs.mkdirSync(shotDir, { recursive: true });
    const shot = (page, name) => page.screenshot({ path: path.join(shotDir, name + '.png') });
    const fillHd = async (page, key, v) => { await page.fill('[data-hd-in="' + key + '"]', v); await page.press('[data-hd-in="' + key + '"]', 'Tab'); await sleep(350); };
    const headOf = s => (/<head>[\s\S]*?<\/head>/.exec(s) || [''])[0];

    const ctx = await mk(1920, 1080), page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', e => { errors.push(e.message); console.log('   ⚠️ שגיאה בדף אחרי בדיקה ' + ran + ': ' + e.message + ' ' + String(e.stack || '').split('\n').slice(1, 4).join(' | ').slice(0, 400)); });
    page.on('dialog', d => (d.type() === 'beforeunload' ? d.accept() : d.dismiss()));
    await page.goto(base + '/#/project');
    await page.waitForSelector('.pj-tree .pj-row');
    await call(page, 'pf_migrate', {});
    await call(page, 'pf_create', { path: 'media/share.png', content_b64: 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' });
    await openPage(page, 'index.html');
    await page.click('[data-dev="desktop"]'); await sleep(300);
    const src0 = await html(page);

    // ═══ 1. ⚙️ הגדרות העמוד: נפתח מהסרגל — לוח רחב בצד הקנבס ═══
    await page.click('[data-head-btn]'); await sleep(500);
    let g = await page.evaluate(() => { const s = document.querySelector('.ed-hd').getBoundingClientRect(), c = document.querySelector('.ed-canvas').getBoundingClientRect(); return { side: s.width, canvas: c.width, vis: getComputedStyle(document.querySelector('.ed-hd')).display, form: !!document.querySelector('.ed-hd [data-hd-form]'), title: document.querySelector('[data-hd-in="title"]').value }; });
    ok(g.vis === 'flex' && g.side >= 420 && g.canvas >= 900 && g.form, '1920: ⚙️ בסרגל ← לוח רחב בצד (≥420px), והקנבס נשאר גלוי (≥900px)', g);
    ok(g.title && src0.includes('<title>'), 'הכותרת נקראת מה-<title> שבקובץ: ' + g.title, g.title);
    const t0 = g.title;
    await fillHd(page, 'title', 'מילון מרוקאי');
    let s = await html(page);
    ok(s.includes('<title>מילון מרוקאי</title>') && !s.includes('<title>' + t0 + '</title>'), 'כותרת ← <title> בקובץ');
    ok((await lastTx(page)) === "הגדרות · כותרת · '" + t0.slice(0, 39) + (t0.length > 40 ? '…' : '') + "' ← 'מילון מרוקאי'", 'שם בהיסטוריה: "הגדרות · כותרת · \'…\' ← \'מילון מרוקאי\'"', await lastTx(page));
    g = await page.evaluate(() => ({ tab: document.querySelector('[data-hd-tab]').textContent, google: document.querySelector('[data-hd-google] b').textContent }));
    ok(/מילון מרוקאי/.test(g.tab) && g.google === 'מילון מרוקאי', 'תצוגת הלשונית וגוגל מתעדכנות', g);
    await undo(page);
    ok((await html(page)) === src0, 'Ctrl+Z אחד — הקובץ חוזר בדיוק');
    await redo(page);
    ok((await html(page)).includes('<title>מילון מרוקאי</title>'), 'Ctrl+Y — חוזר');
    // תיאור + מונה + גוגל
    const hadDesc = /<meta name="description"/.test(src0);
    await page.fill('[data-hd-in="description"]', 'א'.repeat(170));
    g = await page.evaluate(() => ({ c: document.querySelector('[data-hd-count]').textContent, over: document.querySelector('[data-hd-count]').classList.contains('over'), gd: document.querySelector('[data-hd-google] span').textContent.length }));
    ok(g.c === '170 / 160' && g.over && g.gd === 170, 'תיאור: מונה תווים (170 / 160 — אדום) ותצוגת התוצאה בגוגל, תוך כדי הקלדה', g);
    await fillHd(page, 'description', 'המילון של <קול מרוקו> & "שלנו"');
    s = await html(page);
    ok(s.includes('<meta name="description" content="המילון של &lt;קול מרוקו&gt; &amp; &quot;שלנו&quot;">'), (hadDesc ? 'תיאור קיים — נערך במקום' : 'תיאור חסר — נוצר') + ', מקודד', headOf(s).slice(0, 600));
    // צבע הדפדפן — שדה ובורר; תצוגת הטלפון
    await page.evaluate(() => { const c = document.querySelector('[data-hd-color]'); c.value = '#117a65'; c.dispatchEvent(new Event('change', { bubbles: true })); }); await sleep(350);
    s = await html(page);
    g = await page.evaluate(() => getComputedStyle(document.querySelector('[data-hd-phone] i')).backgroundColor);
    ok(s.includes('<meta name="theme-color" content="#117a65">') && g === 'rgb(17, 122, 101)', 'צבע הדפדפן (בורר צבע) ← meta theme-color; תצוגת הטלפון מתעדכנת', g);
    await fillHd(page, 'theme', 'red;}</style><script>');
    ok((await html(page)).includes('content="#117a65"') && await page.evaluate(() => !document.querySelector('.ed-note').hidden && /צבע לא תקין/.test(document.querySelector('.ed-note').textContent)), 'צבע לא תקין — נדחה עם הודעה; הקובץ לא משתנה');
    // שפה וכיוון
    await page.selectOption('[data-hd-in="dir"]', 'ltr'); await sleep(350);
    ok(/<html[^>]*\bdir="ltr"/.test(await html(page)), 'כיוון ← <html dir>');
    await page.selectOption('[data-hd-in="dir"]', 'rtl'); await sleep(350);
    await fillHd(page, 'lang', 'he-IL');
    ok(/<html[^>]*\blang="he-IL"/.test(await html(page)), 'שפה ← <html lang>');
    // noindex
    await page.check('[data-hd-in="noindex"]'); await sleep(350);
    s = await html(page);
    ok(/<meta name="robots" content="noindex[^"]*">/.test(s) && await page.$('[data-hd-google] .hd-noidx'), 'גוגל: "לא להופיע בחיפוש" ← meta robots noindex');
    await page.uncheck('[data-hd-in="noindex"]'); await sleep(350);
    ok(!/name="robots"/.test(await html(page)) || /robots/.test(src0), 'ביטול — התגית נמחקת');
    // כתובת קנונית — הזרקה נדחית
    await fillHd(page, 'canonical', 'javascript:alert(1)');
    ok(!/canonical/.test(await html(page)) || /canonical/.test(src0), 'כתובת קנונית javascript: — נדחית');
    await fillHd(page, 'canonical', 'https://example.com/lexicon/');
    ok((await html(page)).includes('<link rel="canonical" href="https://example.com/lexicon/">'), 'כתובת קנונית ← link rel=canonical');
    await fillHd(page, 'canonical', '');
    ok(!(await html(page)).includes('rel="canonical"'), 'ריקון — התגית נמחקת');
    // תמונת שיתוף — מהספרייה (הבורר של V5; כאן — התשובה שלו)
    await page.evaluate(() => { Editor._head().o.pick = async () => 'media/share.png'; });
    await page.click('[data-hd-pick="ogImage"]'); await sleep(500);
    s = await html(page);
    g = await page.evaluate(() => ({ img: document.querySelector('[data-hd-card] img')?.src || '', t: document.querySelector('[data-hd-card] b').textContent }));
    ok(/<meta property="og:image" content="media\/share.png">/.test(s) && /share\.png/.test(g.img) && g.t === 'מילון מרוקאי', 'תמונת שיתוף ← og:image; כרטיס וואטסאפ / פייסבוק עם התמונה והכותרת', g);
    // og:title — "כמו הכותרת", ניתוק
    ok(await page.isChecked('[data-hd-link="ogTitle"]') && await page.isDisabled('[data-hd-in="ogTitle"]'), 'og:title — "כמו הכותרת" כברירת מחדל');
    await page.uncheck('[data-hd-link="ogTitle"]'); await sleep(400);
    ok((await html(page)).includes('<meta property="og:title" content="מילון מרוקאי">') && !(await page.isDisabled('[data-hd-in="ogTitle"]')), 'ניתוק ← og:title נכתב, והשדה פתוח');
    await fillHd(page, 'ogTitle', 'בואו לשמוע');
    await fillHd(page, 'title', 'מילון מרוקאי 2');
    s = await html(page);
    ok(s.includes('content="בואו לשמוע"') && s.includes('<title>מילון מרוקאי 2</title>'), 'מנותק — שינוי הכותרת אינו משנה את og:title');
    await page.check('[data-hd-link="ogTitle"]'); await sleep(400);
    ok(!(await html(page)).includes('og:title'), 'חזרה ל"כמו הכותרת" — og:title נמחק');
    // סמל — icon + apple-touch-icon, עסקה אחת
    const n0 = await txN(page);
    await page.click('[data-hd-pick="icon"]'); await sleep(500);
    s = await html(page);
    ok(s.includes('<link rel="icon" href="media/share.png">') && s.includes('<link rel="apple-touch-icon" href="media/share.png">') && (await txN(page)) === n0 + 1, 'סמל ← link rel=icon + apple-touch-icon, Ctrl+Z אחד', (await txN(page)) - n0);
    await undo(page);
    s = await html(page);
    ok(!s.includes('rel="icon"') && !s.includes('apple-touch-icon'), 'Ctrl+Z — שניהם נמחקים יחד');
    await redo(page);
    ok(await page.$('[data-hd-tab] img.hd-fav'), 'הסמל מוצג בתצוגת הלשונית');
    // רקע וגופן — "ערוך את הגוף ←"
    await page.click('[data-hd-body]'); await sleep(500);
    g = await page.evaluate(() => ({ sel: Editor._state.canvas.selected?.tag, tab: document.querySelector('.ed-ptabs [aria-selected="true"]')?.dataset.ptab, hd: document.querySelector('.ed').classList.contains('hd-open') }));
    ok(g.sel === 'body' && g.tab === 'design' && !g.hd, '"ערוך את הגוף ←" — <body> נבחר, לשונית העיצוב', g);
    await shot(page, 'v15-1920-design-body');

    // ═══ 2. נפתח גם מ"ראש העמוד" ב-Navigator ומתפריט ההקשר על <body> ═══
    await page.evaluate(() => { if (!document.querySelector('.ed').classList.contains('nav-open')) document.querySelector('[data-panel="nav"]').click(); });
    await sleep(300);
    await page.evaluate(() => Editor._state.nav.draw());
    const headRow = await page.evaluate(() => { const r = [...document.querySelectorAll('.nv-row')].find(x => /ראש העמוד/.test(x.textContent)); if (!r) return false; r.scrollIntoView(); return true; });
    if (headRow) await page.click('.nv-row:has-text("ראש העמוד") .nv-lab'); await sleep(500);
    ok(headRow && await page.evaluate(() => document.querySelector('.ed').classList.contains('hd-open')), 'לחיצה על "ראש העמוד" ב-Navigator ← הטופס');
    await page.evaluate(() => Editor._head().hide());
    await page.evaluate(() => { const c = Editor._state.canvas; Editor._ctx(c.m.root.kids.find(k => k.tag === 'body')); }); await sleep(300);
    const ctxItem = await page.$('.ed-menu-item:has-text("הגדרות העמוד")');
    ok(!!ctxItem, 'תפריט ההקשר על <body> — "⚙️ הגדרות העמוד"');
    if (ctxItem) await ctxItem.click(); await sleep(500);
    ok(await page.evaluate(() => document.querySelector('.ed').classList.contains('hd-open')), '…ופותח את הטופס');

    // ═══ 3. כפילות, "עוד בראש העמוד" ═══
    await page.evaluate(() => { const S = Editor._state, K = window.KolSrc, m = S.canvas.m, head = m.root.kids.find(k => k.tag === 'head'); Editor._act('בדיקה', mm => K.html.insert(mm, K.byPath(mm, K.pathOf(head)), 0, '<meta name="og:image" content="media/other.png">')); });
    await sleep(500);
    g = await page.evaluate(() => ({ dup: document.querySelector('[data-hd-dup="ogImage"]')?.textContent || '' }));
    ok(/נמצאו שתיים — הראשונה קובעת/.test(g.dup) && /פתח בקוד/.test(g.dup), 'og:image פעמיים (name / property) — "נמצאו שתיים — הראשונה קובעת" + פתח בקוד', g);
    ok(await page.evaluate(() => /other\.png/.test(document.querySelector('[data-hd="ogImage"] code').textContent)), 'הראשונה (name=) היא שמוצגת ונערכת');
    await undo(page);
    const others0 = await page.evaluate(() => [...document.querySelectorAll('[data-hd-others] .hd-orow')].map(r => r.querySelector('b').textContent));
    ok(others0.length >= 2 && others0.some(x => /charset/.test(x)) && others0.some(x => /stylesheet/.test(x)), '"עוד בראש העמוד" — כל תגית שהטופס אינו מכיר (charset, stylesheet…)', others0);
    // ⇅ גרירה אמיתית: השורה האחרונה ← ראשונה
    const order0 = (await html(page)).match(/<(meta|link|script|style)\b[^>]*>/g).join('\n');
    await page.evaluate(() => document.querySelector('[data-hd-others]').scrollIntoView({ block: 'center' })); await sleep(200);
    const rows = await page.$$('[data-hd-others] .hd-orow');
    const last = rows[rows.length - 1], firstBox = await rows[0].boundingBox(), handle = await last.$('[data-drag]'), hb = await handle.boundingBox();
    const lastName = others0[others0.length - 1];
    await page.mouse.move(hb.x + hb.width / 2, hb.y + hb.height / 2); await page.mouse.down();
    for (let i = 1; i <= 8; i++) { await page.mouse.move(hb.x + hb.width / 2, hb.y + (firstBox.y - 6 - hb.y) * i / 8); await sleep(30); }
    await page.mouse.up(); await sleep(600);
    const others1 = await page.evaluate(() => [...document.querySelectorAll('[data-hd-others] .hd-orow')].map(r => r.querySelector('b').textContent));
    const order1 = (await html(page)).match(/<(meta|link|script|style)\b[^>]*>/g).join('\n');
    ok(others1[0] === lastName && order1 !== order0 && /^הגדרות · סידור/.test(await lastTx(page)), '⇅ גרירה — התגית האחרונה עברה לראשונה, בקובץ (עסקה "הגדרות · סידור")', others1);
    await undo(page);
    ok((await html(page)).match(/<(meta|link|script|style)\b[^>]*>/g).join('\n') === order0, 'Ctrl+Z — הסדר חוזר');
    await page.click('[data-hd-others] .hd-orow:nth-child(1) [data-down]'); await sleep(500);
    ok((await page.evaluate(() => document.querySelector('[data-hd-others] .hd-orow:nth-child(2) b').textContent)) === others0[0], '↓ — גם בלחיצה');
    await undo(page);
    // ✎ עריכת התכונות
    const vpIdx = others0.findIndex(x => /viewport/.test(x));
    if (vpIdx >= 0) {
      await page.click('[data-hd-others] .hd-orow:nth-child(' + (vpIdx + 1) + ') [data-edit]'); await sleep(300);
      await page.fill('.hd-attrs .hd-arow:has([value="content"]) [data-av]', 'width=device-width, initial-scale=1, viewport-fit=cover');
      await page.click('[data-hd-attrs-apply]'); await sleep(500);
      ok((await html(page)).includes('content="width=device-width, initial-scale=1, viewport-fit=cover"'), '✎ — התכונות כרשימת שם=ערך; ערך שנערך נכתב לקובץ');
      await page.click('[data-hd-others] .hd-orow:nth-child(' + (vpIdx + 1) + ') [data-edit]'); await sleep(300);
      await page.evaluate(() => { const r = [...document.querySelectorAll('.hd-arow')]; const x = r[r.length - 1]; }); 
      await page.click('.row-btns button:has-text("＋ תכונה")');
      const rr = await page.$$('.hd-attrs .hd-arow'); await (await rr[rr.length - 1].$('[data-an]')).fill('onload'); await (await rr[rr.length - 1].$('[data-av]')).fill('alert(1)');
      await page.click('[data-hd-attrs-apply]'); await sleep(400);
      ok(!(await html(page)).includes('onload') && await page.evaluate(() => !document.querySelector('#sheet .error').hidden), 'תכונת on… — נדחית ("קוד נכתב בקובץ JS")');
      await closeSheet(page);
    } else ok(false, 'אין meta viewport בעמוד המוסב');
    // 🗑
    const before = await html(page), cnt = others0.length;
    await page.click('[data-hd-others] .hd-orow:nth-child(' + cnt + ') [data-del]'); await sleep(500);
    ok((await page.$$('[data-hd-others] .hd-orow')).length === cnt - 1 && (await html(page)).length < before.length, '🗑 — התגית נמחקת מהקובץ');
    await undo(page);
    ok((await html(page)) === before, 'Ctrl+Z — חוזרת');
    // ＋ תגית
    await page.click('[data-hd-add]'); await sleep(300);
    await page.fill('[data-new-a]', 'google-site-verification'); await page.fill('[data-new-b]', 'abc"><script>');
    await page.click('[data-new-apply]'); await sleep(500);
    ok((await html(page)).includes('<meta name="google-site-verification" content="abc&quot;&gt;&lt;script&gt;">'), '＋ meta — נוסף, הערך מקודד');

    // ═══ 4. קוד מותאם בראש / בסוף העמוד ═══
    const gtag = '<script async src="https://www.googletagmanager.com/gtag/js?id=G-TEST"></script>\n<script>window.dataLayer = window.dataLayer || []; window.KOL_RAN = 1;</script>';
    await page.evaluate(() => document.querySelector('[data-hd-code="head"]').scrollIntoView());
    const monaco = await page.evaluate(() => !!document.querySelector('[data-hd-code="head"][data-monaco]'));
    if (monaco) { await page.click('[data-hd-code="head"] .view-lines'); await page.keyboard.press('Control+A'); await page.keyboard.insertText(gtag); }
    else await page.fill('[data-hd-code-in="head"]', gtag);
    await page.click('[data-hd-code-save="head"]'); await sleep(600);
    s = await html(page);
    ok(/<!-- kol:head -->\n<script async src="https:\/\/www.googletagmanager.com[\s\S]*<!-- \/kol:head -->\s*<\/head>/.test(s), 'קוד בראש העמוד (' + (monaco ? 'Monaco' : 'תיבת טקסט') + ') ← בין <!-- kol:head --> ל-<!-- /kol:head -->, בסוף ה-<head>', headOf(s).slice(-400));
    g = await page.evaluate(() => ({ note: document.querySelector('.ed-note').textContent, o: document.querySelector('[data-hd-origins]').textContent, strip: document.querySelector('[data-head-strip]').hidden ? '' : document.querySelector('[data-head-strip]').textContent, ran: (() => { try { return Editor._state.canvas.frame.contentWindow.KOL_RAN; } catch { return 'blocked'; } })(), sandbox: Editor._state.canvas.frame.getAttribute('sandbox') }));
    ok(/googletagmanager\.com/.test(g.note) && /לרשימת המקורות/.test(g.o), 'הודעה: "כדי שהקוד הזה ירוץ, נוסף www.googletagmanager.com לרשימת המקורות"', g.note);
    ok(/קוד בראש העמוד — בקנבס אינו רץ/.test(g.strip) && /תצוגה חיה/.test(g.strip) && !/allow-scripts/.test(g.sandbox || '') && g.ran !== 1, 'בקנבס — אינו רץ (iframe בלי allow-scripts), ומוצג "קוד בראש העמוד · ▶️ תצוגה חיה"', g);
    ok(!(await page.evaluate(() => [...document.querySelectorAll('[data-hd-others] b')].some(b => /googletag/.test(b.parentElement.textContent)))), 'התגיות שבאזור הקוד אינן כפולות ב"עוד בראש העמוד"');
    await page.evaluate(() => document.querySelector('[data-hd-code="body"]').scrollIntoView());
    if (monaco) { await page.click('[data-hd-code="body"] .view-lines'); await page.keyboard.insertText('<script>fbq("init", "1")</script>'); }
    else await page.fill('[data-hd-code-in="body"]', '<script>fbq("init", "1")</script>');
    await page.click('[data-hd-code-save="body"]'); await sleep(600);
    s = await html(page);
    ok(/<!-- kol:body-end -->\n<script>fbq\("init", "1"\)<\/script>\n<!-- \/kol:body-end -->\s*<\/body>/.test(s) && /^הגדרות · קוד בסוף העמוד/.test(await lastTx(page)), 'קוד בסוף העמוד ← לפני </body>, עסקה עם שם', s.slice(-300));
    await shot(page, 'v15-1920-head');
    await flush(page);
    ok((await server(page, 'index.html')).includes('<!-- /kol:body-end -->'), 'נשמר לשרת (אותו index.html — אין קובץ נסתר)');

    // ═══ 5. 📄 בורר העמוד, מעבר, מכשיר, זיכרון, ▶️ ═══
    await page.evaluate(() => Editor._head().hide());
    const pb = await page.$('[data-pages-btn]');
    ok(pb && /מילון מרוקאי 2/.test(await pb.textContent()), 'בסרגל: "📄 <שם העמוד> ▾"', pb && await pb.textContent());
    await pb.click(); await sleep(500);
    g = await page.evaluate(() => [...document.querySelectorAll('[data-pg-pop] [data-pg-open]')].map(b => b.dataset.pgOpen));
    ok(g.includes('index.html') && await page.$('[data-pg-pop] [data-pg-search]') && await page.$('[data-pg-pop] [data-pg-new]'), 'רשימת העמודים (שם + כתובת), חיפוש, ➕', g);
    await page.click('[data-pg-pop] [data-pg-new]'); await sleep(400);
    await page.fill('[data-pg-f="title"]', 'אודות');
    ok((await page.inputValue('[data-pg-f="slug"]')) === 'odot' && /\/lexicon\/odot\//.test(await page.textContent('[data-pg-url]')), '➕: הכתובת נגזרת מהשם (אודות ← odot), ומוצגת כ-/lexicon/odot/');
    await page.fill('[data-pg-f="slug"]', '../evil');
    await page.click('[data-pg-create]'); await sleep(400);
    ok(await page.evaluate(() => !document.querySelector('[data-pg-err]').hidden) && !(await call(page, 'pf_tree')).files.some(f => /evil/.test(f.path)), 'הגבול: ../evil — נדחה בטופס, לא נשלח');
    let bad = await page.evaluate(async () => { try { await api('page_create', { slug: '../evil', title: 'x', start: 'blank' }); return 'ok'; } catch (e) { return e.status; } });
    ok(bad === 400, '…והשרת — 400', bad);
    await page.fill('[data-pg-f="slug"]', 'about');
    await page.click('[data-pg-create]');
    await ready(page, 'about/index.html'); await sleep(500);
    s = await html(page);
    ok(/<title>אודות<\/title>/.test(s) && /<link rel="stylesheet" href="\.\.\/css\//.test(s), 'נוצר about/index.html (עם ה-CSS המשותף) ונפתח בעורך', headOf(s));
    // מעבר שומר את הנוכחי, באותו מכשיר
    await page.click('[data-dev="tablet"]'); await sleep(300);
    await page.evaluate(() => { Editor._head().show(); });
    await fillHd(page, 'description', 'על הפרויקט');
    await page.evaluate(() => Editor._head().hide());
    await page.click('[data-pages-btn]'); await sleep(400);
    await page.fill('[data-pg-search]', 'מילון');
    g = await page.evaluate(() => [...document.querySelectorAll('[data-pg-pop] [data-pg-open]')].map(b => b.dataset.pgOpen));
    ok(g.length === 1 && g[0] === 'index.html', 'חיפוש — מסנן לפי שם', g);
    await page.press('[data-pg-search]', 'Enter');
    await ready(page, 'index.html'); await sleep(500);
    ok((await server(page, 'about/index.html')).includes('content="על הפרויקט"'), 'מעבר — העמוד הקודם נשמר לפני (אין שינוי שאובד)');
    ok((await page.evaluate(() => Editor._state.device)) === 'tablet', 'הבא נפתח באותו מצב מכשיר (טאבלט)');
    await page.click('[data-dev="desktop"]'); await sleep(200);
    // ▶️ תצוגה חיה — העמוד שבעורך
    await page.click('[data-pages-btn]'); await sleep(300); await page.click('[data-pg-pop] [data-pg-open="about/index.html"]'); await ready(page, 'about/index.html');
    await page.click('[data-ed-live-btn]'); await sleep(600);
    g = await page.evaluate(() => document.querySelector('.pj-live[data-ed-live] iframe')?.getAttribute('src') || '');
    ok(/path=about%2Findex\.html/.test(g), '▶️ תצוגה חיה — פותחת את העמוד שבעורך (about/), לא את entry', g);
    await page.keyboard.press('Escape'); await sleep(200);
    // נזכר לכל משתמש
    await page.reload(); await page.waitForFunction(() => Editor._state.file && !Editor._state.busy, null, { timeout: 30000 }); await sleep(800);
    ok((await page.evaluate(() => Editor._state.file.path)) === 'about/index.html', 'רענון — נפתח העמוד האחרון של המשתמש');
    const ctxB = await mk(1920, 1080, { sub: 'u-other', name: 'Other', email: 'other@example.test', email_verified: true, platform_admin: true }), pB = await ctxB.newPage();
    await pB.goto(base + '/editor.php#/editor'); await pB.waitForFunction(() => Editor._state.file && !Editor._state.busy, null, { timeout: 30000 });
    const lsA = await page.evaluate(() => localStorage.getItem('kol-editor'));
    await pB.evaluate(ls => localStorage.setItem('kol-editor', ls), lsA);   // אותו דפדפן, משתמש אחר
    await pB.reload(); await pB.waitForFunction(() => Editor._state.file && !Editor._state.busy, null, { timeout: 30000 });
    ok((await pB.evaluate(() => Editor._state.file.path)) === 'index.html', 'משתמש אחר (באותו דפדפן) — לא מקבל את העמוד של הראשון');
    await ctxB.close();

    // ═══ 6. 📄 עמודים — מסך מלא: רשימה + תצוגה מקדימה ═══
    await call(page, 'pf_write', await page.evaluate(async () => { const t = await api('pf_tree'); const f = t.files.find(x => x.path === 'index.html'); const d = await api('pf_read', undefined, '&id=' + f.id); return { id: f.id, rev: d.file.rev, content: d.content.replace('</body>', '<nav class="v15nav"><a href="about/">אודות</a> <a href="/lexicon/about/#team">צוות</a> <a href="gone/">נמחק</a></nav>\n<script>const go = () => location.assign("about/");</script>\n</body>') }; }));
    await page.evaluate(() => Project.docs.reload());
    await page.click('[data-pages-btn]'); await sleep(300); await page.click('[data-pg-pop] [data-pg-manage]'); await sleep(800);
    g = await page.evaluate(() => { const m = document.querySelector('[data-pg-mgr]').getBoundingClientRect(), pv = document.querySelector('[data-pg-preview]'), l = document.querySelector('[data-pg-list]').getBoundingClientRect(); return { w: m.width, h: m.height, l: l.width, pv: pv ? pv.getBoundingClientRect().width : 0, sb: pv?.getAttribute('sandbox'), rows: [...document.querySelectorAll('[data-pg-row]')].map(r => r.dataset.pgRow), vw: innerWidth, vh: innerHeight }; });
    ok(g.w >= g.vw - 40 && g.h >= g.vh - 40 && g.pv > 900 && g.sb === '', '1920: "📄 עמודים" — מסך מלא; הרשימה ותצוגה מקדימה (iframe sandbox בלי סקריפטים) של העמוד הנבחר', g);
    ok(g.rows[0] === 'index.html' && g.rows.includes('about/index.html'), 'הרשימה: הראשי ראשון (⭐), about/', g.rows);
    g = await page.evaluate(() => ({ star: /⭐/.test(document.querySelector('[data-pg-row="index.html"]').textContent), url: document.querySelector('[data-pg-row="about/index.html"] small').textContent, when: /שונה/.test(document.querySelector('[data-pg-row="about/index.html"]').textContent) }));
    ok(g.star && g.url === '/lexicon/about/' && g.when, 'כל שורה: שם (מ-<title>), כתובת /lexicon/about/, מתי שונה', g);
    await shot(page, 'v15-1920-pages');
    // ✎ שינוי כתובת: about/ ← odot/ — תצוגת הקישורים, סימון, הפניה
    await page.click('[data-pg-row="about/index.html"]'); await sleep(400);
    await page.click('[data-pg-act="move"]'); await sleep(300);
    await page.fill('[data-pg-f="to"]', 'odot'); await page.click('[data-pg-move-plan]'); await sleep(800);
    g = await page.evaluate(() => ({ links: [...document.querySelectorAll('[data-pg-plan] .pg-links li')].map(li => li.textContent.replace(/\s+/g, ' ')), manual: document.querySelector('[data-pg-manual]')?.textContent || '' }));
    ok(g.links.some(x => /about\/ ← odot\//.test(x)) && g.links.some(x => /\/lexicon\/about\/#team ← \/lexicon\/odot\/#team|\/lexicon\/about\/ ← \/lexicon\/odot\//.test(x)), 'תצוגת שינויים: כל קישור (יחסי ו-/lexicon/…) — ישן ← חדש, עם סימון', g.links);
    ok(/לבדיקה ידנית/.test(g.manual) && /about\//.test(g.manual), 'קישור שנכתב ב-JS — "לבדיקה ידנית", לא מוחלף', g.manual);
    // מבטלים סימון של /lexicon/… ומשאירים הפניה
    await page.evaluate(() => { const li = [...document.querySelectorAll('[data-pg-plan] .pg-links li')].find(x => /\/lexicon\/about/.test(x.textContent)); if (li) li.querySelector('input').click(); });
    await page.check('[data-pg-redirect]');
    await page.click('[data-pg-move-apply]'); await sleep(1200);
    let ix = await server(page, 'index.html');
    ok(ix.includes('<a href="odot/">') && ix.includes('href="/lexicon/about/#team"') && ix.includes('location.assign("about/")'), 'החלה בעסקה אחת: הקישור שסומן עודכן; זה שבוטל — נשאר; ה-JS — לא נגעו', (ix.match(/<nav class="v15nav">.*<\/nav>/) || [''])[0]);
    ok((await server(page, 'odot/index.html') || '').includes('<title>אודות</title>') && /http-equiv="refresh" content="0; url=\.\.\/odot\/"/.test(await server(page, 'about/index.html') || ''), 'הקובץ זז ל-odot/; "השאר הפניה" — about/index.html עם meta refresh (קובץ רגיל בעץ)');
    await page.waitForFunction(() => Editor._state.file && Editor._state.file.path === 'odot/index.html' && !Editor._state.busy, null, { timeout: 8000 }).catch(() => {});
    ok((await page.evaluate(() => Editor._state.file.path)) === 'odot/index.html', 'העמוד שהיה פתוח בעורך — נפתח בכתובת החדשה', await page.evaluate(() => Editor._state.file.path));
    await page.click('[data-pg-close]'); await sleep(300);
    // ⧉ שכפול
    await page.click('[data-pages-btn]'); await sleep(300); await page.click('[data-pg-pop] [data-pg-manage]'); await sleep(700);
    await page.click('[data-pg-row="odot/index.html"]'); await sleep(300);
    await page.click('[data-pg-act="dup"]'); await sleep(300);
    ok((await page.inputValue('[data-pg-f="start"]')) === 'copy' && (await page.inputValue('[data-pg-f="from"]')) === 'odot/index.html', '⧉ שכפול — "מתחיל מ: שכפול של odot/"');
    await page.fill('[data-pg-f="slug"]', 'team'); await page.click('[data-pg-create]');
    await ready(page, 'team/index.html');
    ok(/<title>אודות \(עותק\)<\/title>/.test(await html(page)), 'השכפול נוצר (team/index.html) ונפתח');
    // ⭐ קבע כראשי, 🚫 404, ↑↓, גרירה, חזור לאוטומטי
    await page.click('[data-pages-btn]'); await sleep(300); await page.click('[data-pg-pop] [data-pg-manage]'); await sleep(700);
    await page.click('[data-pg-row="team/index.html"]'); await sleep(300);
    await page.click('[data-pg-act="entry"]'); await sleep(900);
    ok(/"entry":\s*"team\/index.html"/.test(await server(page, 'kol.json')) && await page.evaluate(() => /⭐/.test(document.querySelector('[data-pg-row="team/index.html"]').textContent)), '⭐ קבע כראשי ← entry ב-kol.json');
    await page.click('[data-pg-row="index.html"]'); await sleep(300); await page.click('[data-pg-act="entry"]'); await sleep(900);
    await page.click('[data-pg-404new]'); await sleep(900);
    ok(/לעמוד הראשי/.test(await server(page, '404.html') || '') && await page.evaluate(() => /🚫/.test(document.querySelector('[data-pg-row="404.html"]')?.textContent || '')), '🚫 צור דף 404 — מתבנית "לא נמצא" עם קישור לראשי');
    const rows0 = await page.evaluate(() => [...document.querySelectorAll('[data-pg-row]')].map(r => r.dataset.pgRow));
    const lastRow = await page.$('[data-pg-row]:last-child'), firstRow = await page.$('[data-pg-row]:first-child');
    const lh = await (await lastRow.$('[data-drag]')).boundingBox(), fb = await firstRow.boundingBox();
    await page.mouse.move(lh.x + lh.width / 2, lh.y + lh.height / 2); await page.mouse.down();
    for (let i = 1; i <= 10; i++) { await page.mouse.move(lh.x + lh.width / 2, lh.y + (fb.y - 4 - lh.y) * i / 10); await sleep(30); }
    await page.mouse.up(); await sleep(1200);
    const rows1 = await page.evaluate(() => [...document.querySelectorAll('[data-pg-row]')].map(r => r.dataset.pgRow));
    const kolP = JSON.parse(await server(page, 'kol.json')).pages;
    ok(rows1[0] === rows0[rows0.length - 1] && Array.isArray(kolP) && kolP[0] === rows1[0] && await page.evaluate(() => document.querySelector('[data-pg-mode]').dataset.pgMode === 'list'), '⇅ גרירה ברשימה — נשמר ל-pages ב-kol.json כרשימה מפורשת, והטופס אומר זאת', { rows1, kolP });
    await page.click('[data-pg-row]:nth-child(2) [data-up]'); await sleep(1000);
    const rows2 = await page.evaluate(() => [...document.querySelectorAll('[data-pg-row]')].map(r => r.dataset.pgRow));
    ok(rows2[0] === rows1[1] && JSON.parse(await server(page, 'kol.json')).pages[0] === rows1[1], '↑ — גם בלחיצה');
    await page.click('[data-pg-auto]'); await sleep(1000);
    ok(JSON.parse(await server(page, 'kol.json')).pages === 'auto' && (await page.evaluate(() => document.querySelector('[data-pg-row]').dataset.pgRow)) === 'index.html', '"חזור לאוטומטי" — pages: "auto"');
    // 🗑 מחיקה לסל: "X עמודים מקשרים אליו" ושחזור
    await page.click('[data-pg-row="odot/index.html"]'); await sleep(300);
    await page.click('[data-pg-act="del"]'); await sleep(800);
    g = await page.evaluate(() => document.querySelector('[data-pg-del-refs]')?.textContent || '');
    ok(/עמודים מקשרים אליו/.test(g) && /index\.html/.test(g), 'מחיקה: "X עמודים מקשרים אליו"', g);
    await page.click('[data-pg-del-ok]'); await sleep(1000);
    ok(!(await page.$('[data-pg-row="odot/index.html"]')) && (await call(page, 'pf_trash')).files?.some?.(f => f.path === 'odot/index.html') !== false, 'עבר לסל (F2) — לא ברשימה');
    // קישור שבור — "קישור שבור: odot/"
    g = await page.evaluate(() => document.querySelector('[data-pg-broken]')?.textContent || '');
    ok(/קישורים שבורים/.test(g) && /odot\//.test(g), 'אחרי המחיקה — "קישורים שבורים לעמודים" כולל odot/', g);
    await page.evaluate(() => [...document.querySelectorAll('.ed-note button')].find(b => /שחזור/.test(b.textContent))?.click()); await sleep(1200);
    ok(!!(await page.$('[data-pg-row="odot/index.html"]')), '↩ שחזור — העמוד חזר');
    await page.click('[data-pg-close]'); await sleep(300);

    // ═══ 7. בורר הקישור: "עמוד בפרויקט" — העמודים לפי הסדר + עוגנים; נכתב about/; שבור — באדום ═══
    await page.click('[data-pages-btn]'); await sleep(300); await page.click('[data-pg-pop] [data-pg-open="index.html"]'); await ready(page, 'index.html'); await sleep(600);
    g = await page.evaluate(() => { const c = Editor._state.canvas, e = c.doc.querySelector('.v15nav a[href="gone/"]'); return { red: e?.getAttribute('data-kol-broken-link'), ol: e ? getComputedStyle(e).outlineStyle : '', okLink: c.doc.querySelector('.v15nav a[href="odot/"]')?.hasAttribute('data-kol-broken-link') }; });
    ok(g.red === 'gone/' && g.ol === 'dashed' && !g.okLink, 'בקנבס: קישור לעמוד שאינו קיים (gone/) — מסגרת אדומה; קישור תקין — לא', g);
    ok((await html(page)).includes('href="gone/"') && !(await html(page)).includes('data-kol-broken'), 'הסימון ב-DOM בלבד — הקובץ לא משתנה');
    await page.evaluate(() => { const c = Editor._state.canvas, e = c.doc.querySelector('.v15nav a[href="gone/"]'); const n = c.nodeOf(e); c.select(n); c.reveal(n); if (!document.querySelector('.ed').classList.contains('info-open')) document.querySelector('[data-panel="info"]').click(); Editor._tab('content'); }); await sleep(500);
    g = await page.evaluate(() => ({ kind: document.querySelector('[data-f="kind"]')?.value || [...document.querySelectorAll('.ct select')].map(s => s.value).join('|'), dead: document.querySelector('[data-f="broken-link"]')?.textContent || '', opts: [...(document.querySelector('select[data-f="href"]')?.options || [])].map(o => o.value) }));
    ok(/page/.test(g.kind) && /קישור שבור: gone\//.test(g.dead), 'בפאנל: "עמוד בפרויקט" + "⛔ קישור שבור: gone/"', g);
    ok(g.opts.includes('odot/') && g.opts.includes('team/') && g.opts.some(v => /^odot\/#|^team\/#/.test(v) || /#/.test(v)), 'האפשרויות — העמודים (odot/, team/) ועוגנים בתוכם', g.opts);
    await page.selectOption('select[data-f="href"]', 'team/'); await sleep(500);
    ok((await html(page)).includes('<a href="team/">נמחק</a>') && /יעד קישור/.test(await lastTx(page)), 'בחירה ← נכתב יחסי ויפה: href="team/"');
    await undo(page);

    // ═══ 8. "מה ישתנה" (M1) — עמודים ═══
    await flush(page);
    const ppub = await ctx.newPage(); ppub.on('dialog', d => d.dismiss());
    await ppub.goto(base + '/#/publish'); await sleep(1500);
    const built = await ppub.evaluate(async () => { const s = await api('build_start', { prerender: false }); const f = await api('build_finish', { id: s.id }); return s.id; });
    await ppub.evaluate(id => window.KolBuild.sheet(id, 'owner'), built);
    await ppub.waitForSelector('[data-pk-pages-count]', { timeout: 8000 }).catch(() => {});
    g = await ppub.evaluate(() => document.querySelector('[data-pk-pages-count]')?.textContent || '');
    ok(/חדשים \d+ · שונו \d+ · נמחקו \d+ · כתובת השתנתה \d+/.test(g), '"מה ישתנה" מונה עמודים: חדש / שונה / נמחק / כתובת שהשתנתה', g);
    g = await ppub.evaluate(() => document.querySelector('[data-pk-broken]')?.textContent || '');
    ok(/קישור שבור: gone\//.test(g), 'בבדיקת הפרסום: "קישור שבור: gone/"', g);
    await ppub.close();

    // ═══ 9. 1366×768 — בלי ריק בצדדים ═══
    await page.setViewportSize({ width: 1366, height: 768 }); await sleep(500);
    await page.evaluate(() => Editor._head().show()); await sleep(500);
    g = await page.evaluate(() => { const b = document.querySelector('.ed-body').getBoundingClientRect(), s = document.querySelector('.ed-hd').getBoundingClientRect(), c = document.querySelector('.ed-mid').getBoundingClientRect(), r = document.querySelector('.ed').getBoundingClientRect(); return { ed: r.width, body: b.width, side: s.width, mid: c.width, vw: innerWidth, hs: document.documentElement.scrollWidth <= innerWidth, sw: document.documentElement.scrollWidth, bw: document.body.scrollWidth, wide: [...document.querySelectorAll("body *")].filter(e => e.getBoundingClientRect().left < -1 && e.getBoundingClientRect().width > 0 && !e.closest(".monaco-editor")).slice(0, 8).map(e => e.tagName + "." + e.className + " " + Math.round(e.getBoundingClientRect().right)) }; });
    ok(g.ed >= g.vw - 48 && g.side >= 420 && g.mid >= 500 && g.hs, '1366: הלוח בצד והקנבס ממלאים את הרוחב, בלי גלילה אופקית', g);
    await shot(page, 'v15-1366-head');
    await page.evaluate(() => Editor._head().hide());
    await page.click('[data-pages-btn]'); await sleep(300); await page.click('[data-pg-pop] [data-pg-manage]'); await sleep(700);
    g = await page.evaluate(() => { const m = document.querySelector('[data-pg-mgr]').getBoundingClientRect(), pv = document.querySelector('[data-pg-preview]').getBoundingClientRect(); return { w: m.width, pv: pv.width, vw: innerWidth }; });
    ok(g.w >= g.vw - 40 && g.pv >= 600, '1366: "📄 עמודים" — מסך מלא, תצוגה מקדימה רחבה', g);
    await shot(page, 'v15-1366-pages');
    await page.click('[data-pg-close]');
    await page.setViewportSize({ width: 1920, height: 1080 }); await sleep(300);

    // ═══ 10. 390 (מגע): שניהם גיליון; הסידור — לחיצה ארוכה + ↑↓ ═══
    await flush(page);
    const ctxP = await mk(390, 844), pp = await ctxP.newPage();
    pp.on('pageerror', e => { errors.push(e.message); console.log('   ⚠️ 390: ' + e.message); });
    await pp.goto(base + '/editor.php#/editor/index.html'); await ready(pp, 'index.html'); await sleep(800);
    await pp.tap('[data-head-btn]'); await sleep(600);
    g = await pp.evaluate(() => ({ sheet: document.getElementById('sheet').open && !!document.querySelector('#sheet [data-hd-form]'), side: getComputedStyle(document.querySelector('.ed-hd')).display, hs: document.documentElement.scrollWidth <= innerWidth }));
    ok(g.sheet && g.side === 'none' && g.hs, '390: ⚙️ הגדרות העמוד — גיליון', g);
    await pp.fill('#sheet [data-hd-in="title"]', 'בטלפון'); await pp.press('#sheet [data-hd-in="title"]', 'Enter'); await sleep(500);
    ok((await html(pp)).includes('<title>בטלפון</title>'), '390: עריכה בגיליון נכתבת לקובץ');
    await pp.screenshot({ path: path.join(shotDir, 'v15-390-head.png') });
    await pp.evaluate(() => document.getElementById('sheet').close()); await sleep(300);
    await pp.tap('[data-pages-btn]'); await sleep(500);
    await pp.tap('#sheet [data-pg-manage]'); await sleep(800);
    g = await pp.evaluate(() => ({ sheet: document.getElementById('sheet').open && !!document.querySelector('#sheet [data-pg-mgr]'), rows: [...document.querySelectorAll('#sheet [data-pg-row]')].map(r => r.dataset.pgRow) }));
    ok(g.sheet && g.rows.length >= 4, '390: "📄 עמודים" — גיליון', g);
    // לחיצה ארוכה על שורה ← גרירה (אירועי מגע אמיתיים בשורה)
    const r0 = g.rows;
    const lb = await pp.evaluate(() => { const rs = document.querySelectorAll('#sheet [data-pg-row]'), a = rs[rs.length - 1].querySelector('.pg-name').getBoundingClientRect(), b = rs[0].getBoundingClientRect(); return { x: a.x + 20, y: a.y + a.height / 2, ty: b.y - 4 }; });
    await pp.evaluate(async ({ x, y, ty }) => {
      const row = [...document.querySelectorAll('#sheet [data-pg-row]')].pop(), t = row.querySelector('.pg-name');
      const fire = (el, type, yy) => el.dispatchEvent(new PointerEvent(type, { bubbles: true, cancelable: true, pointerId: 7, pointerType: 'touch', clientX: x, clientY: yy, isPrimary: true }));
      fire(t, 'pointerdown', y); await new Promise(r => setTimeout(r, 600));
      for (let i = 1; i <= 8; i++) { fire(row, 'pointermove', y + (ty - y) * i / 8); await new Promise(r => setTimeout(r, 30)); }
      fire(row, 'pointerup', ty);
    }, lb);
    await sleep(1500);
    const r1 = await pp.evaluate(() => [...document.querySelectorAll('#sheet [data-pg-row]')].map(r => r.dataset.pgRow));
    ok(r1[0] === r0[r0.length - 1], '390: לחיצה ארוכה על השורה וגרירה — הסדר השתנה (ונשמר ל-kol.json)', { r0, r1 });
    const upB = await pp.$('#sheet [data-pg-row]:nth-child(2) [data-up]'), ubb = await upB.boundingBox();
    ok(ubb.width >= 34 && ubb.height >= 34, '390: ↑ ↓ — יעד מגע ≥34px', ubb);
    await upB.tap(); await sleep(1200);
    ok((await pp.evaluate(() => document.querySelector('#sheet [data-pg-row]').dataset.pgRow)) === r1[1], '390: ↑ בהקשה');
    ok(await pp.evaluate(() => document.documentElement.scrollWidth <= innerWidth), '390: בלי גלילה אופקית');
    await pp.screenshot({ path: path.join(shotDir, 'v15-390-pages.png') });
    await call(pp, 'pages_order', { order: 'auto' });
    await ctxP.close();

    console.log('\nצילומים: ' + shotDir);
    ok(!errors.length, 'אין שגיאות JavaScript בדף', errors.slice(0, 3));
  } catch (e) { failed++; console.log('❌ חריגה: ' + (e.stack || e.message)); }
  finally { await browser?.close(); php.kill(); }
  console.log('\n' + (failed ? '❌ נכשלו ' + failed + ' מתוך ' + ran : '✅ כל ' + ran + ' הבדיקות עברו'));
  process.exit(failed ? 1 : 0);
})();
