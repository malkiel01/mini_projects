/**
 * פרוסה X1 — בדיקות בדפדפן (Chromium, Playwright):   node kol-maroko/tests/browser/x1.cjs
 *
 * ✨ על קובץ פתוח ב-📁 ← diff ← "בטל" (לא נשמר) ← שוב ← "החל" ← הקובץ בעורך התעדכן, גרסה "✨ <הבקשה>" בהיסטוריה, ו-Ctrl+Z בעורך מחזיר.
 * עריכה שלא נמצאה — "לא הוחלו" עם הסיבה. ✨ על טווח שורות — הטווח נשלח. ✨ ב-📚 על המילים — "תשתנה מילה אחת" ← החל ← הטבלה.
 * תוכן עוין מהבינה מוצג כטקסט ולא רץ. 1280px ו-390px. שירות הבינה מדומה (שרת מקומי כאן), דרך tests/browser/x1-router.php.
 */
'use strict';
const { chromium } = require('playwright');
const { spawn } = require('child_process');
const http = require('http');
const fs = require('fs'), os = require('os'), path = require('path'), net = require('net');

const ROOT = path.resolve(__dirname, '..', '..');
const OWNER = { sub: 'u-owner', name: 'Owner Test', email: 'owner@example.test', email_verified: true, platform_admin: true };
let failed = 0, ran = 0;
const ok = (cond, what, extra) => { ran++; if (cond) console.log('✅ ' + what); else { failed++; console.log('❌ ' + what + (extra !== undefined ? ' — ' + JSON.stringify(extra).slice(0, 300) : '')); } };
const freePort = () => new Promise(r => { const s = net.createServer().listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => r(p)); }); });
const sleep = ms => new Promise(r => setTimeout(r, ms));

// שירות הבינה המדומה: מה שהבדיקה קובעת ב-next הוא התשובה הבאה; כל בקשה נשמרת ב-seen
const AI = { next: null, seen: [] };
const aiServer = http.createServer((req, res) => {
  let b = '';
  req.on('data', c => { b += c; });
  req.on('end', () => {
    const body = JSON.parse(b || '{}');
    AI.seen.push(body);
    const out = typeof AI.next === 'function' ? AI.next(body) : AI.next;
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ model: body.model, stop_reason: 'end_turn', usage: { input_tokens: 120, output_tokens: 30 },
      content: [{ type: 'text', text: JSON.stringify(out || { summary: '', edits: [] }) }] }));
  });
});

(async () => {
  const data = fs.mkdtempSync(path.join(os.tmpdir(), 'kol-x1-'));
  const aiPort = await freePort();
  await new Promise(r => aiServer.listen(aiPort, '127.0.0.1', r));
  const port = await freePort(), base = `http://127.0.0.1:${port}`;
  const php = spawn('php', ['-S', `127.0.0.1:${port}`, path.join(ROOT, 'tests', 'browser', 'x1-router.php')], { cwd: ROOT,
    env: { ...process.env, KOL_TEST_DATA: data, KOL_TEST_AI: `http://127.0.0.1:${aiPort}/`, PHP_CLI_SERVER_WORKERS: '4' }, stdio: 'ignore' });
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

    const ctx = await mk(1280, 860), page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', e => errors.push(e.message));
    page.on('dialog', d => d.accept());
    await page.goto(base + '/#/project');
    await page.waitForSelector('.pj-tree .pj-row');
    await call(page, 'secret_set', { key: 'anthropic_api_key', value: 'sk-ant-test-0000000000000000000000000000' });
    const PAGE = '<!doctype html>\n<html lang="he" dir="rtl">\n<head>\n  <meta charset="utf-8">\n  <title>t</title>\n</head>\n<body>\n  <main class="page">\n    <h1>קול מרוקו</h1>\n    <p>שורה שנייה</p>\n  </main>\n</body>\n</html>\n';
    const created = await call(page, 'pf_create', { path: 'x1.html', content: PAGE });
    const fid = created.file.id;
    await page.reload();
    await page.waitForSelector('.pj-tree .pj-row');
    const row = name => page.locator('.pj-tree .pj-row', { has: page.locator('.pj-name .one', { hasText: new RegExp('^' + name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '$') }) }).first();
    await row('x1.html').locator('.pj-name').click();
    await page.waitForFunction(() => window.monaco && monaco.editor.getModels().some(m => m.getValue().includes('שורה שנייה')));
    const model = () => page.evaluate(() => monaco.editor.getModels().find(m => m.getValue().includes('<main class="page">')).getValue());

    // ── ✨ על הקובץ: diff, "בטל" ────────────────────────────────
    const HOSTILE = '<img src=x onerror="window.pwned=1"><script>window.pwned=2</script>';
    AI.next = { summary: 'החלפתי את הכותרת ' + HOSTILE, edits: [
      { path: 'x1.html', old: '<h1>קול מרוקו</h1>', new: '<h1>קול מרוקו — המילון</h1>' },
      { path: 'x1.html', old: 'לא קיים בקובץ', new: 'x' },
    ] };
    ok(await page.isVisible('.pj-bar [data-ai]'), '✨ בסרגל של הקובץ הפתוח');
    await page.click('.pj-bar [data-ai]');
    await page.waitForSelector('#sheet [data-ai-prompt]');
    ok((await page.textContent('#sheet .ai-ctx')).includes('x1.html'), 'ההקשר: הקובץ הפתוח');
    await page.fill('#sheet [data-ai-prompt]', 'תחליף את הכותרת הראשית');
    await page.click('#sheet [data-ai-go]');
    await page.waitForSelector('#sheet [data-ai-apply]');
    const sent = AI.seen[AI.seen.length - 1].messages[0].content;
    ok(sent.includes(PAGE) && sent.includes('תחליף את הכותרת הראשית'), 'הקובץ נשלח כמו שהוא, עם הבקשה');
    ok(await page.locator('#sheet [data-ai-file="x1.html"] .pj-dl.add').count() === 1 && await page.locator('#sheet [data-ai-file="x1.html"] .pj-dl.del').count() === 1, 'diff: שורה הוסרה ושורה נוספה');
    ok((await page.textContent('#sheet [data-ai-file="x1.html"] .pj-dl.add')).includes('המילון'), 'ה-diff מראה את השינוי');
    ok((await page.textContent('#sheet [data-ai-rejected]')).includes('לא נמצא'), '"לא הוחלו" — עם הסיבה');
    ok((await page.textContent('#sheet [data-ai-summary]')).includes('<script>') && await page.evaluate(() => window.pwned === undefined), 'תוכן עוין מהבינה — טקסט, לא רץ');
    await page.click('#sheet [data-ai-cancel]');
    ok((await page.textContent('#sheet .ai-out')).includes('לא נשמר כלום'), '"בטל"');
    ok((await call(page, 'pf_read', null, `&id=${fid}`)).content === PAGE, 'בטל — הקובץ לא השתנה');

    // ── ✨ שוב — "החל" ─────────────────────────────────────────
    await page.click('#sheet [data-ai-go]');
    await page.waitForSelector('#sheet [data-ai-apply]:not([disabled])');
    await page.click('#sheet [data-ai-apply]');
    await page.waitForSelector('#sheet [data-ai-done]');
    const saved = (await call(page, 'pf_read', null, `&id=${fid}`)).content;
    ok(saved.includes('<h1>קול מרוקו — המילון</h1>'), 'החל — נכתב בשרת');
    await page.waitForFunction(() => monaco.editor.getModels().some(m => m.getValue().includes('קול מרוקו — המילון')), null, { timeout: 5000 }).catch(() => {});
    ok((await model()).includes('קול מרוקו — המילון'), 'הקובץ הפתוח בעורך התעדכן');
    const vers = (await call(page, 'pf_versions', null, `&id=${fid}`)).versions;
    ok(vers[0].note === '✨ תחליף את הכותרת הראשית', 'גרסה בהיסטוריה: "✨ <הבקשה>"', vers[0]);
    ok(vers.length === 2, 'גרסה אחת לקובץ (שתי העריכות — אחת הוחלה, אחת נדחתה)', vers.length);
    await page.click('#sheet [data-close]');
    // Ctrl+Z בעורך — מחזיר לתוכן שלפני
    await page.click('.pj-ed .monaco-editor .view-line');
    await page.keyboard.press('Control+z');
    await page.waitForFunction(() => monaco.editor.getModels().some(m => m.getValue().includes('<h1>קול מרוקו</h1>')), null, { timeout: 3000 }).catch(() => {});
    ok((await model()).includes('<h1>קול מרוקו</h1>'), 'Ctrl+Z בעורך מבטל את מה שהעוזר החיל');
    await page.keyboard.press('Control+Shift+z');
    await page.waitForFunction(() => monaco.editor.getModels().some(m => m.getValue().includes('המילון')), null, { timeout: 3000 }).catch(() => {});
    ok((await model()).includes('המילון'), 'וחזרה (Ctrl+Shift+Z)');
    // ↩️ גרסאות: הגרסה הקודמת שמורה
    const prev = await call(page, 'pf_version', null, `&id=${fid}&hid=${vers[1].hid}`);
    ok(prev.content === PAGE, 'בגרסאות — התוכן שלפני ✨, לשחזור');

    // ── ✨ על טווח שורות ───────────────────────────────────────
    await page.evaluate(() => { const ed = monaco.editor.getEditors().find(e => e.getModel()?.getValue().includes('<main')); ed.setSelection(new monaco.Selection(10, 1, 11, 1)); ed.focus(); });
    AI.next = { summary: 'אין', edits: [] };
    await page.click('.pj-bar [data-ai]');
    await page.waitForSelector('#sheet [data-ai-prompt]');
    ok((await page.textContent('#sheet .ai-ctx')).includes('שורות 10–11'), 'ההקשר: טווח השורות שסומן');
    await page.fill('#sheet [data-ai-prompt]', 'בדיקת טווח');
    await page.click('#sheet [data-ai-go]');
    await page.waitForSelector('#sheet .ai-out .empty');
    ok(AI.seen[AI.seen.length - 1].messages[0].content.includes('השורות 10–11'), 'הטווח נשלח לבינה', AI.seen[AI.seen.length - 1].messages[0].content.slice(-300));
    await page.click('#sheet [data-close]');

    // ── ✨ ב-📚 על המילים ──────────────────────────────────────
    for (const [w, m] of [['שלום', '<p>ברכה</p>'], ['מימונה', '<p>חג</p>']]) await call(page, 'word_save', { word: w, meaning: m, topics: [] });
    await page.goto(base + '/#/words');
    await page.waitForSelector('[data-ai-words]');
    AI.next = body => {
      const c = body.messages[0].content;
      return { summary: 'תיקנתי', edits: [{ path: 'data/words.json', old: '"meaning": "<p>חג</p>"', new: '"meaning": "<p>חַג</p>"' }], seen: c.includes('"items"') };
    };
    await page.click('[data-ai-words]');
    await page.waitForSelector('#sheet [data-ai-prompt]');
    ok((await page.textContent('#sheet .ai-ctx')).includes('data/words.json'), '📚: ההקשר — data/words.json');
    await page.fill('#sheet [data-ai-prompt]', 'תתקן את הניקוד בפירוש של מימונה');
    await page.click('#sheet [data-ai-go]');
    await page.waitForSelector('#sheet [data-ai-plan]');
    ok((await page.getAttribute('#sheet [data-ai-plan]', 'data-ai-plan')) === '1' && (await page.textContent('#sheet [data-ai-plan]')).includes('תשתנה מילה אחת'), '📚: "תשתנה מילה אחת" — התצוגה של N1');
    ok((await call(page, 'words')).words.find(w => w.word === 'מימונה').meaning === '<p>חג</p>', 'ההצעה לא שינתה את הטבלה');
    await page.click('#sheet [data-ai-apply]');
    await page.waitForSelector('#sheet [data-ai-done]');
    ok((await call(page, 'words')).words.find(w => w.word === 'מימונה').meaning === '<p>חַג</p>', '📚: החל — הטבלה התעדכנה (words_apply)');
    await page.click('#sheet [data-close]');

    // ── טלפון ──────────────────────────────────────────────────
    const pctx = await mk(390, 800), phone = await pctx.newPage();
    phone.on('pageerror', e => errors.push('phone: ' + e.message));
    phone.on('dialog', d => d.accept());
    await phone.goto(base + '/#/project');
    await phone.waitForSelector('.pj-bar');
    await phone.waitForFunction(() => Project._state.loaded);
    await phone.evaluate(id => Project._open(id), fid);
    await phone.waitForSelector('.pj-bar [data-ai]');
    AI.next = { summary: 'טלפון', edits: [{ path: 'x1.html', old: '<p>שורה שנייה</p>', new: '<p>שורה מהטלפון</p>' }] };
    await phone.click('.pj-bar [data-ai]');
    await phone.fill('#sheet [data-ai-prompt]', 'שינוי מהטלפון');
    await phone.click('#sheet [data-ai-go]');
    await phone.waitForSelector('#sheet [data-ai-apply]');
    const over = await phone.evaluate(() => document.documentElement.scrollWidth - innerWidth);
    ok(over <= 1, 'טלפון: בלי גלילה לרוחב, גם עם ה-diff', over);
    await phone.click('#sheet [data-ai-apply]');
    await phone.waitForSelector('#sheet [data-ai-done]');
    ok((await call(phone, 'pf_read', null, `&id=${fid}`)).content.includes('שורה מהטלפון'), 'טלפון: החל');
    await pctx.close();

    ok(!errors.length, 'אין שגיאות JS', errors);
    ok(!foreign.length, 'אין פנייה לכתובת חיצונית מהדפדפן', foreign);
  } catch (e) {
    failed++; console.log('❌ ' + (e && e.stack || e));
  } finally {
    if (browser) await browser.close();
    php.kill();
    aiServer.close();
    fs.rmSync(data, { recursive: true, force: true });
  }
  console.log(`\n${failed ? `נכשלו ${failed} מתוך ${ran}` : `כל ${ran} הבדיקות עברו`}`);
  process.exit(failed ? 1 : 0);
})();
