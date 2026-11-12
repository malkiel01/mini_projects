/**
 * פרוסה N2 — בדיקות בדפדפן (Chromium, Playwright):   node kol-maroko/tests/browser/n2.cjs
 *
 * "🧩 מבנה המילה" ← הוספת שדה (מפתח נגזר מהשם) ← העמודה בטבלה בלי רענון ← ערך בתא ← בקובץ data/words.json ← בתצוגה החיה.
 * שדה פנימי — לא בתצוגה החיה. שינוי סוג: "יומרו / ייכשלו" ו"רוקן". 🔑 שינוי מפתח עם הקשירות. 🏷️ נושאים: שם בלי שינוי slug,
 * מוסתר. "הצג עמודות" נזכר. ערך עוין — טקסט. 1280px ו-390px. רץ מול tests/router.php ומסד זמני — data/ לא נפתחת.
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
  const data = fs.mkdtempSync(path.join(os.tmpdir(), 'kol-n2-'));
  const port = await freePort(), base = `http://127.0.0.1:${port}`;
  const php = spawn('php', ['-S', `127.0.0.1:${port}`, path.join(ROOT, 'tests', 'router.php')], { cwd: ROOT, env: { ...process.env, KOL_TEST_DATA: data, PHP_CLI_SERVER_WORKERS: '4' }, stdio: 'ignore' });
  let browser;
  try {
    for (let i = 0; i < 100; i++) { try { await fetch(base + '/api.php?action=embed_key'); break; } catch { await sleep(50); } }
    browser = await chromium.launch();
    const foreign = [];
    const mk = async (width, height) => {
      const ctx = await browser.newContext({ viewport: { width, height }, extraHTTPHeaders: { 'X-Test-User': JSON.stringify(OWNER) } });
      ctx.on('request', r => { const u = r.url(); if (!u.startsWith(base) && !/^(data|blob):/.test(u)) foreign.push(u); });
      return ctx;
    };
    const call = (page, action, body, query = '') => page.evaluate(([a, b, q]) => api(a, b ?? undefined, q), [action, body ?? null, query]);
    const words = async page => (await call(page, 'words')).words;
    const file = async page => JSON.parse((await call(page, 'pf_read', null, '&id=-1')).content);
    const live = async page => { const tok = await call(page, 'pv_token'); return page.evaluate(u => fetch(u).then(r => r.text()), base + '/preview/' + tok.token + '/data/words.json'); };

    const ctx = await mk(1280, 860), page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));
    let promptAnswer = null;
    page.on('dialog', d => (d.type() === 'prompt' ? d.accept(promptAnswer ?? '') : d.accept()));
    await page.goto(base + '/#/project');                               // הפרויקט נוצר (kol.json עם data.words)
    await page.waitForSelector('.pj-tree .pj-row');
    for (const [w, t] of [['שלום', ['ברכות']], ['בסלאמה', ['ברכות']], ['כסכסו', ['אוכל']], ['אבא', []]]) await call(page, 'word_save', { word: w, meaning: '<p>פ</p>', topics: t });
    await page.evaluate(() => { location.hash = '#/words'; });
    await page.waitForSelector('.w-table tbody tr');
    await page.evaluate(() => { window.__noReload = 1; });

    // ── הוספת שדה ────────────────────────────────────────────
    await page.click('[data-structure]');
    await page.waitForSelector('#sheet .f-list .f-row');
    ok((await page.$$('#sheet .f-list .f-row')).length === 4, 'ארבעת השדות המובנים ברשימה');
    await page.click('#sheet [data-add-field]');
    await page.fill('#sheet label.f:has-text("השם") input', 'תעתיק לטיני');
    const derived = await page.inputValue('#sheet [data-f-key]');
    ok(/^[a-z][a-z0-9_]*$/.test(derived), 'מפתח נגזר מהשם (תעתיק לטיני)', derived);
    await page.fill('#sheet [data-f-key]', 'translit');
    await page.click('#sheet [data-f-save]');
    await page.waitForSelector('#sheet .f-row[data-key="translit"]');
    ok((await page.textContent('#sheet .f-row[data-key="translit"]')).includes('מתפרסם'), '"מתפרסם" כברירת מחדל');
    await page.click('#sheet-close, #sheet [data-close], #sheet .sheet-head .icon-btn').catch(() => page.keyboard.press('Escape'));
    await page.waitForSelector('.w-table th[data-col="translit"]');
    ok(await page.evaluate(() => window.__noReload === 1), 'העמודה מופיעה בטבלה — בלי רענון');

    // ── ערך בתא ← קובץ ← תצוגה חיה ────────────────────────────
    const first = (await words(page)).find(w => w.word === 'כסכסו');
    const cellSel = `tr[data-word="${first.id}"] [data-field="translit"]`;
    await page.fill(cellSel, 'ksksu');
    await page.press(cellSel, 'Enter');
    await page.waitForFunction(async id => (await api('words')).words.find(w => w.id === id).extra.translit === 'ksksu', first.id);
    ok(true, 'ערך בתא — נשמר');
    ok((await file(page)).items.find(x => x.id === first.id).translit === 'ksksu', 'טבלה ← data/words.json');
    ok((await live(page)).includes('"translit": "ksksu"') || (await live(page)).includes('"translit":"ksksu"'), 'טבלה ← תצוגה חיה');
    // ערך עוין
    await page.fill(cellSel, '<img src=x onerror=window.pwned=1>');
    await page.press(cellSel, 'Enter');
    await page.waitForFunction(async id => (await api('words')).words.find(w => w.id === id).extra.translit.startsWith('<img'), first.id);
    await page.reload();
    await page.waitForSelector(cellSel);
    ok(await page.evaluate(() => !window.pwned), 'ערך עוין — מוצג כטקסט');
    ok((await page.inputValue(cellSel)).startsWith('<img'), 'ונשמר כמו שהוא');

    // ── שדה פנימי ────────────────────────────────────────────
    await call(page, 'field_save', { key: 'notes', label: 'הערות', type: 'text', published: false });
    await call(page, 'word_save', { id: first.id, word: first.word, meaning: first.meaning, topics: first.topics, audio: '', external_id: '', extra: { notes: 'סוד-פנימי' }, light: true });
    ok((await file(page)).items.find(x => x.id === first.id).notes === 'סוד-פנימי', 'שדה פנימי — בקובץ של העורך');
    ok(!(await live(page)).includes('סוד-פנימי'), 'שדה פנימי — לא בתצוגה החיה');

    // ── שינוי סוג: "יומרו / ייכשלו" ו"רוקן" ───────────────────
    await call(page, 'field_save', { key: 'num', label: 'מספר', type: 'text' });
    const ws = await words(page);
    await call(page, 'word_save', { id: ws[0].id, word: ws[0].word, meaning: ws[0].meaning, topics: ws[0].topics, audio: ws[0].audio, external_id: '', extra: { num: '12' }, light: true });
    await call(page, 'word_save', { id: ws[1].id, word: ws[1].word, meaning: ws[1].meaning, topics: ws[1].topics, audio: ws[1].audio, external_id: '', extra: { num: 'שתים' }, light: true });
    await page.click('[data-structure]');
    await page.click('#sheet [data-edit="num"]');
    await page.selectOption('#sheet [data-f-type]', 'number');
    await page.click('#sheet [data-f-save]');
    await page.waitForSelector('#sheet [data-convert]');
    ok((await page.textContent('#sheet [data-convert]')).includes('יומרו 1, ייכשלו 1'), '"יומרו 1, ייכשלו 1" עם הרשימה', await page.textContent('#sheet [data-convert]'));
    ok((await words(page)).find(w => w.id === ws[1].id).extra.num === 'שתים', 'לפני האישור — שום ערך לא נמחק');
    await page.click('#sheet [data-clear-failed]');
    await page.waitForSelector('#sheet .f-row[data-key="num"]');
    const after = await words(page);
    ok(after.find(w => w.id === ws[0].id).extra.num === 12 && after.find(w => w.id === ws[1].id).extra.num === undefined, 'הומר; הנכשל רוקן רק אחרי "רוקן"');

    // ── 🔑 שינוי מפתח עם הקשירות ─────────────────────────────
    await call(page, 'pf_create', { path: 'n2.html', content: '<b data-kol-text="translit"></b><i data-kol-text="{translit}!"></i>' });
    promptAnswer = 'latin';
    await page.click('#sheet [data-rekey="translit"]');
    await page.waitForSelector('#sheet [data-rekey-apply]');
    ok((await page.textContent('#sheet')).includes('2 קשירות'), 'התצוגה: 2 קשירות בקבצים', await page.textContent('#sheet p'));
    ok((await page.$$('#sheet .f-hit .f-after')).length === 2, 'לפני ← אחרי לכל שורה');
    await page.click('#sheet [data-rekey-apply]');
    await page.waitForSelector('#sheet .f-row[data-key="latin"]');
    const tree = await call(page, 'pf_tree');
    const hid = tree.files.find(f => f.path === 'n2.html').id;
    ok((await call(page, 'pf_read', null, '&id=' + hid)).content === '<b data-kol-text="latin"></b><i data-kol-text="{latin}!"></i>', 'הקבצים עודכנו');
    ok('latin' in (await file(page)).items.find(x => x.id === first.id), 'והמפתח בקובץ');
    await page.keyboard.press('Escape');

    // ── 🏷️ נושאים: שם בלי שינוי slug, מוסתר ───────────────────
    const slug0 = (await file(page)).topics.find(t => t.label === 'ברכות').id;
    await page.click('[data-topics]');
    await page.waitForSelector(`#sheet [data-t-name="${slug0}"]`);
    await page.fill(`#sheet [data-t-name="${slug0}"]`, 'ברכות טובות');
    await page.press(`#sheet [data-t-name="${slug0}"]`, 'Tab');
    await page.waitForFunction(async s => JSON.parse((await api('pf_read', undefined, '&id=-1')).content).topics.some(t => t.id === s && t.label === 'ברכות טובות'), slug0);
    ok(true, 'שינוי שם — אותו slug בקובץ');
    await page.check(`#sheet [data-t-hidden="${slug0}"]`);
    await page.waitForFunction(async s => JSON.parse((await api('pf_read', undefined, '&id=-1')).content).topics.some(t => t.id === s && t.hidden === true), slug0);
    ok(!JSON.parse(await live(page)).topics.some(t => t.id === slug0), 'מוסתר — לא ברשימה בתצוגה החיה');
    await page.keyboard.press('Escape');

    // ── "הצג עמודות" — נזכר ───────────────────────────────────
    await page.reload();
    await page.waitForSelector('.w-table th[data-col="latin"]');
    await page.click('[data-cols]');
    await page.uncheck('#sheet [data-col-toggle="latin"]');
    await page.waitForSelector('.w-table th[data-col="latin"]', { state: 'detached' });
    await page.keyboard.press('Escape');
    await page.reload();
    await page.waitForSelector('.w-table tbody tr');
    ok(!(await page.$('.w-table th[data-col="latin"]')) && !!(await page.$('.w-table th[data-col="num"]')), '"הצג עמודות" — נזכר אחרי רענון');
    // סינון לפי שדה
    await page.selectOption('[data-ffilter]', 'num:has');
    ok((await page.$$('.w-table tbody tr')).length === 1, 'סינון: "יש ערך"');
    await page.selectOption('[data-ffilter]', '');

    // ── אקסל: עמודה בלי שדה — "לא תיובא" + "צור שדה" ─────────
    const csv = await page.evaluate(() => fetch('api.php?action=words_csv').then(r => r.text()));
    const lines = csv.replace(/^\uFEFF/, '').trim().split(/\r?\n/);
    const csv2 = [lines[0] + ',region', ...lines.slice(1).map((l, i) => l + (i === 0 ? ',צפון' : ','))].join('\n') + '\n';
    await page.setInputFiles('input[type=file][accept=".csv,text/csv"]', { name: 'w.csv', mimeType: 'text/csv', buffer: Buffer.from(csv2) });
    await page.waitForSelector('#sheet [data-make-field="region"]');
    ok((await page.textContent('#sheet [data-field-proposals]')).includes('לא ייובאו'), 'עמודה בלי שדה — "לא תיובא"');
    await page.click('#sheet [data-make-field="region"]');
    await page.waitForFunction(() => document.querySelector('#sheet [data-csv-summary]')?.textContent.includes('שדות חדשים: 1'));
    await page.click('#sheet [data-csv-apply]');
    await page.waitForSelector('.w-table th[data-col="region"]');
    ok((await words(page)).some(w => w.extra.region === 'צפון'), '"צור שדה" מאקסל — השדה והערך');

    // ── N2c: "צור שדה" בעורך הקוד, אזהרת השכפול, DOMParser ─────
    await page.goto(base + '/#/project');
    await page.waitForSelector('.pj-tree .pj-row');
    await page.locator('.pj-tree .pj-row', { has: page.locator('[data-linked="words"]') }).locator('.pj-name').click();
    await page.waitForFunction(() => window.monaco && monaco.editor.getModels().some(m => m.getValue().includes('"items"')));
    const model = () => page.evaluate(() => monaco.editor.getModels().find(m => m.getValue().includes('"items"')).getValue());
    const jf = JSON.parse(await model());
    jf.items[0].dialect = 'פאס';
    await page.evaluate(t => monaco.editor.getModels().find(m => m.getValue().includes('"items"')).setValue(t), JSON.stringify(jf, null, 2));
    await page.click('.pj-ed .monaco-editor .view-line'); await page.keyboard.press('Control+s');
    await page.waitForSelector('#sheet [data-create-field="dialect"]');
    ok(true, 'N2c: שדה שאינו קיים בקובץ — כפתור "צור שדה" בעורך הקוד');
    await page.click('#sheet [data-create-field="dialect"]');
    await page.waitForSelector('#sheet [data-words-summary]');
    ok((await page.textContent('#sheet')).includes('שדות חדשים: dialect'), 'N2c: השדה החדש בתוכנית "מה ישתנה"');
    await page.click('#sheet [data-words-apply]');
    await page.waitForFunction(() => !document.querySelector('#sheet').open);
    ok((await words(page)).some(w => w.extra && w.extra.dialect === 'פאס'), 'N2c: "צור שדה" ← השדה והערך בטבלה');
    const wid = (await call(page, 'pf_tree')).files.find(f => f.path === 'data/words.json');
    const cp = await call(page, 'pf_copy', { id: wid ? wid.id : -1, to: 'data/words-copy.json' });
    ok(typeof cp.warning === 'string' && cp.warning.length > 0, 'N2c: pf_copy של הקובץ המקושר מחזיר אזהרה', cp);
    const exec = await page.evaluate(() => {
      window.__n2c = 0;
      const a = Fields.show({ type: 'rich' }, '<img src=x onerror="window.__n2c=1">טקסט<script>window.__n2c=2</script>');
      return new Promise(r => setTimeout(() => r([a, window.__n2c]), 300));
    });
    ok(exec[0].startsWith('טקסט') && !exec[0].includes('<img') && exec[1] === 0, 'N2c: Fields.show (rich) — DOMParser; <img onerror> ו-<script> אינם רצים', exec);
    const pexec = await page.evaluate(() => new Promise(r => {
      window.__n2c = 0;
      const doc = new DOMParser().parseFromString('<img src=x onerror="window.__n2c=3">', 'text/html');
      setTimeout(() => r(window.__n2c + ':' + doc.body.textContent), 300);
    }));
    ok(pexec === '0:', 'N2c: מסמך של DOMParser אינו מריץ onerror (הבסיס של plain() ב-words.js)', pexec);
    ok(/new DOMParser\(\)\.parseFromString/.test(require('fs').readFileSync(require('path').join(ROOT, 'assets/js/words.js'), 'utf8')) && !/createElement\('div'\); d\.innerHTML = html/.test(require('fs').readFileSync(require('path').join(ROOT, 'assets/js/words.js'), 'utf8')), 'N2c: plain() ב-words.js — DOMParser, לא innerHTML על דיב');

    // ── טלפון ──────────────────────────────────────────────────
    const pctx = await mk(390, 800), phone = await pctx.newPage();
    phone.on('pageerror', e => errors.push('phone: ' + e.message));
    await phone.goto(base + '/#/words');
    await phone.waitForSelector('.w-table tbody tr [data-field="num"]');
    ok(await phone.evaluate(() => document.documentElement.scrollWidth - innerWidth) <= 1, 'טלפון: בלי גלילה לרוחב בעמוד');
    await phone.click('[data-structure]');
    await phone.waitForSelector('#sheet .f-row[data-key="num"]');
    ok(await phone.evaluate(() => document.querySelector('#sheet').scrollWidth <= innerWidth + 1), 'טלפון: "מבנה המילה" בלי גלילה לרוחב');
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
