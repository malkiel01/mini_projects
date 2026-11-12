/**
 * פרוסה N1 — בדיקות בדפדפן (Chromium, Playwright):   node kol-maroko/tests/browser/n1.cjs
 *
 * data/words.json בעץ (מקושר לטבלה) ← פתיחה בעורך ← עריכה (פירוש + מילה בלי id) ← Ctrl+S ← "מה ישתנה" ← החל ← הטבלה,
 * הקובץ (עם המזהה החדש) והתצוגה החיה. שגיאה — עם שורה, מסומנת בעורך, והטבלה לא משתנה. נושא חדש — "צור נושא".
 * גרירה בטבלה ו"העבר ל…" ← הקובץ השתנה. אקסל: ייצוא ← ייבוא = "0 שינויים". מחיקה — רק הסבר. 1280px ו-390px.
 * רץ מול tests/router.php ומסד זמני — data/ לא נפתחת.
 */
'use strict';
const { chromium } = require('playwright');
const { spawn } = require('child_process');
const fs = require('fs'), os = require('os'), path = require('path'), net = require('net');

const ROOT = path.resolve(__dirname, '..', '..');
const OWNER = { sub: 'u-owner', name: 'Owner Test', email: 'owner@example.test', email_verified: true, platform_admin: true };
let failed = 0, ran = 0;
const ok = (cond, what, extra) => { ran++; if (cond) console.log('✅ ' + what); else { failed++; console.log('❌ ' + what + (extra !== undefined ? ' — ' + JSON.stringify(extra).slice(0, 300) : '')); } };
const freePort = () => new Promise(r => { const s = net.createServer().listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => r(p)); }); });
const sleep = ms => new Promise(r => setTimeout(r, ms));

(async () => {
  const data = fs.mkdtempSync(path.join(os.tmpdir(), 'kol-n1-'));
  const port = await freePort(), base = `http://127.0.0.1:${port}`;
  const php = spawn('php', ['-S', `127.0.0.1:${port}`, path.join(ROOT, 'tests', 'router.php')], { cwd: ROOT, env: { ...process.env, KOL_TEST_DATA: data, PHP_CLI_SERVER_WORKERS: '4' }, stdio: 'ignore' });
  let browser;
  try {
    for (let i = 0; i < 100; i++) { try { await fetch(base + '/api.php?action=embed_key'); break; } catch { await sleep(50); } }
    browser = await chromium.launch();
    const foreign = [];
    const mk = async (width, height) => {
      const ctx = await browser.newContext({ viewport: { width, height }, extraHTTPHeaders: { 'X-Test-User': JSON.stringify(OWNER) }, acceptDownloads: true });
      ctx.on('request', r => { const u = r.url(); if (!u.startsWith(base) && !/^(data|blob):/.test(u)) foreign.push(u); });
      return ctx;
    };
    const call = (page, action, body, query = '') => page.evaluate(([a, b, q]) => api(a, b ?? undefined, q), [action, body ?? null, query]);
    const words = async page => (await call(page, 'words')).words;
    const file = async page => JSON.parse((await call(page, 'pf_read', null, '&id=-1')).content);

    const ctx = await mk(1280, 860), page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));
    let promptAnswer = null;
    page.on('dialog', d => (d.type() === 'prompt' ? d.accept(promptAnswer ?? '') : d.accept()));
    await page.goto(base + '/#/project');
    await page.waitForSelector('.pj-tree .pj-row');
    // מילים לבדיקה (גם עוינת: HTML במילה)
    for (const [w, m, t] of [['שלום', '<p>ברכה</p>', ['ברכות']], ['בסלאמה', '<p>לשלום</p>', ['ברכות']], ['כסכסו', '<p>אוכל</p>', ['אוכל']], ['<img src=x onerror=window.pwned=1>', '', []],
      ['מימונה', '<p>חג</p>', []], ['אבא', '', ['משפחה']]]) await call(page, 'word_save', { word: w, meaning: m, topics: t });
    await page.reload();
    await page.waitForSelector('.pj-tree .pj-row');

    // ── הקובץ בעץ ────────────────────────────────────────────
    const wrow = page.locator('.pj-tree .pj-row', { has: page.locator('[data-linked="words"]') });
    ok(await wrow.count() === 1, 'data/words.json בעץ, עם סמל "מקושר לטבלה"');
    ok((await wrow.locator('[data-linked]').getAttribute('title')) === 'נבנה מטבלת המילים — שמירה מעדכנת את הטבלה', 'והתווית');
    ok((await wrow.getAttribute('data-dir')) === 'data', 'בתיקייה data/');
    await wrow.locator('.pj-name').click();
    await page.waitForSelector('.pj-ed .monaco-editor .view-line');
    await page.waitForFunction(() => window.monaco && monaco.editor.getModels().some(m => m.getValue().includes('"items"')));
    ok((await page.textContent('.pj-status')).includes('נבנה מטבלת המילים'), 'שורת המצב: מקושר לטבלה');

    const setText = t => page.evaluate(t => monaco.editor.getModels().find(m => m.getValue().includes('"items"')).setValue(t), t);
    const getText = () => page.evaluate(() => monaco.editor.getModels().find(m => m.getValue().includes('"items"')).getValue());
    const save = async () => { await page.click('.pj-ed .monaco-editor .view-line'); await page.keyboard.press('Control+s'); };

    // ── עריכה ושמירה: פירוש של מילה, ומילה בלי id ────────────
    const j = JSON.parse(await getText());
    const target = j.items.find(x => x.word === 'כסכסו');
    target.meaning = '<p>מאכל חג</p>';
    j.items.push({ word: 'מילה מהקובץ', meaning: '<p>חדשה</p>', topics: [], audio: '' });
    await setText(JSON.stringify(j, null, 2));
    await sleep(2200);                                                   // שמירה אוטומטית — אסור שתקרה
    ok(!(await words(page)).some(w => w.word === 'מילה מהקובץ'), 'אין שמירה אוטומטית לקובץ של הטבלה');
    ok((await page.textContent('.pj-status')).includes('לא נשמר'), 'מסומן "לא נשמר"');
    await save();
    await page.waitForSelector('#sheet [data-words-summary]');
    const summary = await page.textContent('#sheet [data-words-summary]');
    ok(summary.includes('תשתנה מילה אחת') && summary.includes('תתווסף 1') && summary.includes('יעברו לסל 0'), '"מה ישתנה" לפני הביצוע', summary);
    ok(!(await words(page)).some(w => w.word === 'מילה מהקובץ'), 'לפני "החל" — הטבלה לא השתנתה');
    await page.click('#sheet details.pj-plan summary');
    ok((await page.textContent('#sheet')).includes('מאכל חג'), 'לפני ← אחרי, לפי שדה');
    await page.click('#sheet [data-words-apply]');
    await page.waitForFunction(() => !document.querySelector('#sheet').open);
    const ws = await words(page);
    const added = ws.find(w => w.word === 'מילה מהקובץ');
    ok(added && ws.find(w => w.word === 'כסכסו').meaning === '<p>מאכל חג</p>', 'הטבלה התעדכנה');
    ok(added && JSON.parse(await getText()).items.find(x => x.word === 'מילה מהקובץ')?.id === added.id, 'הקובץ בעורך חזר עם המזהה החדש');
    ok(ws[ws.length - 1].id === added?.id, 'מילה חדשה — בסוף');
    ok((await page.textContent('.pj-status')).includes('נבנה מטבלת המילים'), 'אחרי ההחלה — נשמר');

    // ── התצוגה החיה ← אותם נתונים ──────────────────────────────
    const tok = await call(page, 'pv_token');
    const live = await page.evaluate(u => fetch(u).then(r => r.json()), base + '/preview/' + tok.token + '/data/words.json');
    ok(JSON.stringify(live.items.map(x => x.id)) === JSON.stringify(ws.map(w => w.id)) && live.items.some(x => x.word === 'מילה מהקובץ'), 'התצוגה החיה — אותן מילים, אותו סדר');

    // ── שגיאה: JSON שבור — שורה, סימון בעורך, והטבלה לא משתנה ─
    const before = JSON.stringify(await words(page));
    const broken = (await getText()).replace('"מאכל חג', '"מאכל חג\n');
    await setText(broken.replace(/("word": "אבא",)/, '$1 "color": "red",'));
    await save();
    await page.waitForSelector('#sheet [data-words-errors]');
    const errText = await page.textContent('#sheet [data-words-errors]');
    ok(/שורה \d+, עמודה \d+/.test(errText), 'השגיאה עם שורה ועמודה', errText);
    const marks = await page.evaluate(() => monaco.editor.getModelMarkers({ owner: 'kol-words' }).length);
    ok(marks >= 1, 'מסומן בעורך (Monaco)');
    ok(JSON.stringify(await words(page)) === before, 'הטבלה לא השתנתה');
    await page.click('#sheet [data-close]');

    // ── נושא שאינו קיים — "צור נושא" ──────────────────────────
    const j2 = JSON.parse(JSON.stringify(await file(page)));
    j2.items[0].topics = ['proverbs'];
    j2.topics.push({ id: 'proverbs', label: 'פתגמים' });
    await setText(JSON.stringify(j2, null, 2));
    await save();
    await page.waitForSelector('#sheet [data-create-topic="proverbs"]');
    await page.click('#sheet [data-create-topic="proverbs"]');
    await page.waitForSelector('#sheet [data-words-summary]');
    ok((await page.textContent('#sheet')).includes('פתגמים (proverbs)'), 'נושא חדש בתוכנית');
    await page.click('#sheet [data-words-apply]');
    await page.waitForFunction(() => !document.querySelector('#sheet').open);
    ok((await words(page))[0].topics.includes('פתגמים'), 'הנושא נוצר, והמילה בו');

    // ── העלאת קובץ באותו נתיב — אותו מנוע ("מה ישתנה") ──────────
    const up = await file(page);
    up.items[1].meaning = '<p>מהעלאה</p>';
    const upInput = page.waitForEvent('filechooser');
    await page.locator('.pj-tree .pj-dir[data-dir="data"] .pj-more').click();
    await page.click('#sheet .pj-menu button:has-text("העלאה לכאן")');
    await (await upInput).setFiles({ name: 'words.json', mimeType: 'application/json', buffer: Buffer.from(JSON.stringify(up, null, 2)) });
    await page.waitForSelector('#sheet [data-words-summary]');
    ok((await page.textContent('#sheet [data-words-summary]')).includes('תשתנה מילה אחת'), 'העלאה באותו נתיב — "מה ישתנה"');
    await page.click('#sheet [data-words-apply]');
    await page.waitForFunction(() => !document.querySelector('#sheet').open);
    ok((await words(page)).some(w => w.meaning === '<p>מהעלאה</p>'), 'והטבלה התעדכנה');

    // ── מחיקה במנהל הקבצים — רק הסבר; שום מילה לא נמחקת ─────────
    const n0 = (await words(page)).length;
    await wrow.locator('.pj-more').click();
    ok(!(await page.locator('#sheet .pj-menu button:has-text("שינוי שם")').count()), 'אין "שינוי שם" (המיקום ב-kol.json)');
    await page.click('#sheet .pj-menu button:has-text("הסרה מהעמוד")');
    await page.waitForSelector('#sheet [data-words-unlink]');
    ok((await page.textContent('#sheet')).includes('אינה מוחקת מילים'), 'ההסבר: מחיקה אינה מוחקת מילים');
    await page.click('#sheet [data-close]');
    ok((await words(page)).length === n0, 'המילים במקומן');

    // ── הטבלה: גרירה ו"העבר ל…" ← הקובץ השתנה ─────────────────
    const rev0 = (await call(page, 'pf_read', null, '&id=-1')).file.rev;
    await page.goto(base + '/#/words');
    await page.waitForSelector('.w-table tbody tr');
    const ids = (await words(page)).map(w => w.id);
    const last = ids[ids.length - 1];
    await page.locator(`tr[data-word="${last}"] .w-grip`).dragTo(page.locator(`tr[data-word="${ids[0]}"]`), { targetPosition: { x: 20, y: 2 } });
    await page.waitForFunction(id => document.querySelector('.w-table tbody tr')?.getAttribute('data-word') === String(id), last);
    ok((await words(page))[0].id === last, 'גרירה למקום הראשון — נשמרה בשרת');
    ok((await file(page)).items[0].id === last, 'והקובץ data/words.json השתנה (הסדר)');
    ok((await call(page, 'pf_read', null, '&id=-1')).file.rev !== rev0, 'ה-rev של הקובץ השתנה');
    promptAnswer = '3';
    await page.click(`tr[data-word="${last}"] .w-place`);
    await page.waitForFunction(id => document.querySelectorAll('.w-table tbody tr')[2]?.getAttribute('data-word') === String(id), last);
    ok((await words(page))[2].id === last, '"העבר ל…" 3');
    // בחירה מרובה
    const two = (await words(page)).slice(-2).map(w => w.id);
    for (const id of two) await page.check(`tr[data-word="${id}"] input[type=checkbox]`);
    ok((await page.textContent('.w-bulk')).includes('2 נבחרו'), 'בחירה מרובה');
    promptAnswer = '1';
    await page.click('.w-bulk button:has-text("העבר ל")');
    await page.waitForFunction(ids => [...document.querySelectorAll('.w-table tbody tr')].slice(0, 2).map(r => +r.getAttribute('data-word')).join() === ids.join(), two);
    ok(JSON.stringify((await words(page)).slice(0, 2).map(w => w.id)) === JSON.stringify(two), 'שתיים יחד למקום הראשון');
    ok(await page.evaluate(() => !window.pwned), 'מילה עוינת מוצגת כטקסט');

    // ── הקובץ הפתוח בעורך (בלי שינויים שלו) מתעדכן כשהטבלה השתנתה ─
    await page.evaluate(() => { location.hash = '#/project'; });
    await page.waitForSelector('.pj-ed .monaco-editor .view-line');
    await page.waitForFunction(id => { const m = monaco.editor.getModels().find(m => m.getValue().includes('"items"')); try { return JSON.parse(m.getValue()).items[0].id === id; } catch { return false; } }, two[0]);
    ok(true, 'הקובץ הפתוח בעורך — התעדכן לסדר החדש מהטבלה');
    await page.evaluate(() => { location.hash = '#/words'; });
    await page.waitForSelector('.w-table tbody tr');

    // ── אקסל: ייצוא ← ייבוא = 0 שינויים ───────────────────────
    const csv = await page.evaluate(() => fetch('api.php?action=words_csv').then(r => r.arrayBuffer()).then(b => Array.from(new Uint8Array(b))));
    await page.setInputFiles('input[type=file][accept=".csv,text/csv"]', { name: 'words.csv', mimeType: 'text/csv', buffer: Buffer.from(csv) });
    await page.waitForSelector('#sheet [data-csv-summary]');
    const cs = await page.textContent('#sheet [data-csv-summary]');
    ok(cs.includes('ישתנו 0 מילים') && cs.includes('יתווספו 0') && cs.includes('ישתנה הסדר של 0'), 'ייבוא הקובץ שיצא — 0 שינויים', cs);
    ok((await page.getAttribute('#sheet [data-csv-encoding]', 'data-csv-encoding')).startsWith('UTF-8'), 'זוהה קידוד');
    ok(await page.isDisabled('#sheet [data-csv-apply]'), 'אין מה להחיל');
    await page.click('#sheet [data-close]');

    // ── טלפון ──────────────────────────────────────────────────
    const pctx = await mk(390, 800), phone = await pctx.newPage();
    phone.on('pageerror', e => errors.push('phone: ' + e.message));
    await phone.goto(base + '/#/words');
    await phone.waitForSelector('.w-table tbody tr .w-grip');
    const over = await phone.evaluate(() => document.documentElement.scrollWidth - innerWidth);
    ok(over <= 1, 'טלפון: בלי גלילה לרוחב בעמוד', over);
    ok(await phone.isVisible('.w-table tbody tr .w-place'), 'טלפון: "העבר ל…" (מקום) גלוי — בלי גרירה במגע');
    await pctx.close();

    ok(!errors.length, 'אין שגיאות JS', errors);
    ok(!foreign.length, 'אין פנייה לכתובת חיצונית', foreign);
  } catch (e) {
    failed++; console.log('❌ ' + (e && e.stack || e));
  } finally {
    if (browser) await browser.close();
    php.kill();
    fs.rmSync(data, { recursive: true, force: true });
  }
  console.log(`\n${failed ? `נכשלו ${failed} מתוך ${ran}` : `כל ${ran} הבדיקות עברו`}`);
  process.exit(failed ? 1 : 0);
})();
