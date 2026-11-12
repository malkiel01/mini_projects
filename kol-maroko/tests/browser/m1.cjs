/**
 * פרוסה M1 — בנייה ופרסום של פרויקט הקבצים, בדפדפן (Chromium, Playwright):   node kol-maroko/tests/browser/m1.cjs
 *
 *  א. הפרויקט המוסב (f3-seed.php + 🪄 ההסבה): "🏗️ בנה גרסה מהפרויקט" מהלשונית — כתיבה מראש במסגרת המבודדת, מתחת ל-20 שניות.
 *  ב. "מה ישתנה": פרסום ראשון, תצוגה של הגרסה הבנויה, ו"התוסף באתר הוא 1.x" (התוסף האמיתי, tests/fake-wp.php).
 *  ג. שליחה למקבל המדומה v2, ו**החבילה מתיקייה סטטית**: המילים נראות עם JS כבוי; עם JS — בלי כפילות, החיפוש עובד,
 *     ההקלטה מתנגנת, 0 בקשות שנכשלו, 0 שגיאות קונסול. זו ההוכחה ש"החבילה עומדת בפני עצמה" (R2).
 *  ד. כשל בנייה: שגיאת JS ← אין גרסה, השגיאה עם קובץ ושורה; "בנה בלי כתיבה מראש" ← גרסה.
 *
 * רץ מול tests/router.php ומסד זמני — data/ לא נפתחת. האתר המדומה — בתיקייה זמנית.
 */
'use strict';
const { chromium } = require('playwright');
const { spawn, execFileSync } = require('child_process');
const fs = require('fs'), os = require('os'), path = require('path'), net = require('net'), http = require('http');

const ROOT = path.resolve(__dirname, '..', '..');
const OWNER = { sub: 'u-owner', name: 'Owner Test', email: 'owner@example.test', email_verified: true, platform_admin: true };
let failed = 0, ran = 0;
const ok = (cond, what, detail = '') => { ran++; if (cond) console.log('✅ ' + what + (detail ? ' — ' + detail : '')); else { failed++; console.log('❌ ' + what + (detail ? ' — ' + detail : '')); } return !!cond; };
const freePort = () => new Promise(r => { const s = net.createServer().listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => r(p)); }); });
const sleep = ms => new Promise(r => setTimeout(r, ms));
const until = async (fn, ms = 10000) => { const t = Date.now(); for (;;) { try { const v = await fn(); if (v) return v; } catch { /* עוד לא */ } if (Date.now() - t > ms) return null; await sleep(100); } };

(async () => {
  const data = fs.mkdtempSync(path.join(os.tmpdir(), 'kol-m1-'));
  const wpDir = fs.mkdtempSync(path.join(os.tmpdir(), 'kol-m1wp-'));
  execFileSync('php', [path.join(__dirname, 'f3-seed.php'), data, path.join(data, 'old.html')]);
  const [port, wpPort, stPort] = [await freePort(), await freePort(), await freePort()];
  const base = `http://127.0.0.1:${port}`, wp = `http://127.0.0.1:${wpPort}`, st = `http://127.0.0.1:${stPort}`;
  // שתי הקלטות בספרייה (WAV קצר), והאתר המדומה כיעד: המפתח הציבורי מודבק בו
  execFileSync('php', ['-r', `
    define('KOL_DATA_DIR', $argv[1]);
    foreach (['members', 'import', 'ai', 'agent', 'dev', 'preview', 'migrate', 'build'] as $l) require_once $argv[2] . "/lib/$l.php";
    $n = 3200; $pcm = str_repeat(pack('v', 0), $n);
    $wav = 'RIFF' . pack('V', 36 + strlen($pcm)) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16) . 'data' . pack('V', strlen($pcm)) . $pcm;
    foreach ([1, 2] as $w) { $t = KOL_DATA_DIR . "/rec$w.wav"; file_put_contents($t, $wav);
      $id = file_store($t, "rec$w.wav", ['sub' => 'u-owner', 'name' => 'Owner Test'], 'audio/wav');
      db()->prepare('UPDATE words SET audio = ? WHERE id = ?')->execute([$id, $w]); }
    setting_set('site_url', $argv[3]);
    file_put_contents($argv[4] . '/options.json', json_encode(['kol_maroko_keys' => pub_key_line(pub_key())]));
  `, data, ROOT, wp, wpDir]);
  const env = { ...process.env, PHP_CLI_SERVER_WORKERS: '6' };
  const php = spawn('php', ['-S', `127.0.0.1:${port}`, path.join(ROOT, 'tests', 'router.php')], { cwd: ROOT, env: { ...env, KOL_TEST_DATA: data }, stdio: 'ignore' });
  const wpPhp = spawn('php', ['-S', `127.0.0.1:${wpPort}`, path.join(ROOT, 'tests', 'fake-wp.php')], { cwd: ROOT, env: { ...env, KOL_TEST_WP: wpDir }, stdio: 'ignore' });
  const wpOpt = (k, v) => { const f = path.join(wpDir, 'options.json'); const o = JSON.parse(fs.readFileSync(f, 'utf8')); if (v === undefined) delete o[k]; else o[k] = v; fs.writeFileSync(f, JSON.stringify(o)); };
  // השרת הסטטי: מגיש את החבילה שבאוויר אצל המקבל המדומה — רק את הקבצים שבמניפסט, מהגושים שהתקבלו. בלי PHP, בלי הכלי.
  const stat = http.createServer((req, res) => {
    let man;
    try { man = JSON.parse(fs.readFileSync(path.join(wpDir, 'v2', 'live.json'), 'utf8')); } catch { res.writeHead(503); return res.end(); }
    let p = decodeURIComponent(new URL(req.url, st).pathname.slice(1));
    if (p === '' || p.endsWith('/')) p = p ? p + 'index.html' : man.entry;
    const f = man.files[p];
    if (!f) { res.writeHead(404, { 'Content-Type': 'text/plain' }); return res.end('404'); }
    const body = fs.readFileSync(path.join(wpDir, 'v2', 'blobs', f.sha256));
    res.writeHead(200, { 'Content-Type': f.type + (/^text\/|json/.test(f.type) ? '; charset=utf-8' : ''), 'Content-Length': body.length, 'Cache-Control': 'no-store' });
    res.end(body);
  }).listen(stPort, '127.0.0.1');
  let browser;
  try {
    for (let i = 0; i < 100; i++) { try { await fetch(base + '/api.php?action=embed_key'); await fetch(wp + '/'); break; } catch { await sleep(50); } }
    browser = await chromium.launch({ args: ['--site-per-process'] });
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    await ctx.addCookies([{ name: 'kol_test_user', value: Buffer.from(JSON.stringify(OWNER)).toString('base64'), url: base, httpOnly: true, sameSite: 'Lax' }]);
    const page = await ctx.newPage();
    const errors = [];
    page.on('pageerror', e => { if (!e.message.includes('בום בבנייה')) errors.push(e.message); });   // "בום" — השגיאה המכוונת במסגרת הבנייה (ד)
    page.on('dialog', d => d.accept());
    // הכפתור של הבנייה; כשלא מופיע — מה שהמסך מציג במקומו
    const ready = () => page.waitForSelector('.pk .btn.primary', { timeout: 15000 }).catch(async e => { throw new Error(e.message + ' · ' + (await page.textContent('#main').catch(() => ''))); });
    const call = (action, body, query = '') => page.evaluate(([a, b, q]) => api(a, b ?? undefined, q), [action, body ?? null, query]);

    // ════ א. הבנייה מהלשונית ════
    console.log('\n── א. בנייה ──');
    wpOpt('kol_wp_plugin_v1', true);           // M2: התוסף בריפו הוא 2.0 — "תוסף 1.x" הוא העותק הקפוא של 1.6.0
    await page.goto(base + '/#/publish');
    await ready();
    await call('pf_migrate', {});
    await page.reload();
    await ready();
    ok(!(await page.$('.pk .warnbox')), 'הפרויקט המוסב: kol-data.js 1.1.0 — אין הודעת "אינו תומך"');
    const t0 = Date.now();
    await page.click('.pk .btn.primary');
    const built = await until(() => page.$('#sheet[open] .pk-sheet .chip.ok'), 30000);
    const ms = Date.now() - t0;
    ok(built, 'נבנתה גרסה (מוכנה)');
    ok(ms < 20000, 'הבנייה מתחת ל-20 שניות', `${(ms / 1000).toFixed(1)} שניות`);
    const list = await call('builds');
    const b1 = list.builds[0];
    ok(b1 && b1.status === 'built', 'בנייה מוכנה ברשימה');
    const g = await call('build_get', undefined, '&id=' + b1.id);
    const srcOf = Object.fromEntries(g.files.map(f => [f.path, f.src]));
    ok(srcOf['index.html'] === 'prerender', 'index.html נכתב מראש');
    ok(srcOf['data/words.json'] === 'data', 'data/words.json מהטבלה');
    ok(!('kol.json' in srcOf), 'kol.json אינו בחבילה');
    ok(Object.keys(srcOf).filter(p => p.startsWith('media/audio/')).length === 2, 'שתי ההקלטות שבספרייה — בתוך החבילה');
    ok(!(await page.$('.bd-frame')), 'מסגרת הבנייה הוסרה');

    // ════ ב. מה ישתנה ════
    console.log('\n── ב. מה ישתנה ──');
    const sheetText = await page.textContent('#sheet .pk-sheet');
    ok(sheetText.includes('פרסום ראשון: כל הקבצים חדשים'), 'פרסום ראשון');
    ok(await page.$('#sheet .pk-compare iframe[src*="preview/b"]'), 'השוואה חזותית: הגרסה הבנויה במסגרת');
    ok(/1494 הקלטות|1496 מילים/.test(sheetText), 'נתונים: מילים והקלטות', (sheetText.match(/📚[^·]*·[^·]*/) || [''])[0]);
    ok(sheetText.includes('הקלטות עדיין באתר'), 'אזהרה: הקלטות שעדיין באתר');
    ok(sheetText.includes('משאבים חיצוניים'), 'משאבים חיצוניים');
    const v1x = await until(async () => /התוסף באתר הוא 1\.6\.0 — לשליחה נדרש 2\.0/.test(await page.textContent('#sheet .pk-send')), 8000);
    ok(v1x, 'תוסף 1.x ← "לשליחה נדרש 2.0"');
    ok(await page.$eval('#sheet .pk-send button', b => b.disabled), 'כפתור השליחה מושבת');
    // תצוגה של הגרסה הבנויה
    await page.click('#sheet button:has-text("▶️ תצוגה של הגרסה הבנויה")');
    const vf = await until(() => page.frames().find(f => /\/preview\/b[0-9a-z]+-v-/.test(f.url())), 8000);
    ok(vf, 'התצוגה הבנויה נפתחת במסגרת');
    if (vf) {
      const n = await until(() => vf.$$eval('[data-kol-list="words"] > :not(template)', els => els.length).then(c => c >= 1496 && c), 15000);
      ok(n === 1496, 'בתצוגה הבנויה — 1496 כרטיסים, בלי כפילות', String(n));
      ok(await vf.evaluate(() => { try { return document.cookie, false; } catch { return true; } }), 'מקור אטום (document.cookie זורק)');
    }

    // ════ ג. שליחה, והחבילה מתיקייה סטטית ════
    console.log('\n── ג. שליחה והחבילה ──');
    wpOpt('kol_v2_mock', true);
    await page.evaluate(id => KolBuild.sheet(id, 'owner'), b1.id);
    const enabled = await until(() => page.$eval('#sheet .pk-send button', b => !b.disabled), 8000);
    ok(enabled, 'מקבל v2 ← "שלח לאתר" פעיל');
    await page.click('#sheet .pk-send button');
    const sent = await until(async () => (await call('build_get', undefined, '&id=' + b1.id)).build.status === 'live', 60000);
    ok(sent, 'נשלחה — באוויר');
    const puts = JSON.parse(fs.readFileSync(path.join(wpDir, 'options.json'), 'utf8')).v2_puts || [];
    ok(puts.length === new Set(g.files.map(f => f.path)).size || puts.length > 0, 'הגושים התקבלו', `${puts.length} גושים`);
    for (const js of [false, true]) {
      const c = await browser.newContext({ viewport: { width: 1280, height: 900 }, javaScriptEnabled: js });
      const failedReq = [], cerr = [];
      await c.route('**/*', r => (r.request().url().startsWith(st) ? r.continue() : r.abort()));      // רק החבילה; כל דבר חיצוני — חסום
      const p = await c.newPage();
      p.on('requestfailed', r => { if (r.url().startsWith(st)) failedReq.push(r.url()); });
      p.on('response', r => { if (r.url().startsWith(st) && r.status() >= 400) failedReq.push(r.status() + ' ' + r.url()); });
      p.on('console', m => { if (m.type() === 'error' && !/net::ERR_FAILED|ERR_BLOCKED/.test(m.text() + JSON.stringify(m.location()))) cerr.push(m.text()); });
      p.on('pageerror', e => cerr.push(e.message));
      await p.goto(st + '/');
      if (!js) {
        const n = await p.$$eval('[data-kol-list="words"] > [data-kol-pre]', els => els.length);
        ok(n === 1496, 'JS כבוי: כל המילים בעמוד (כתיבה מראש)', String(n));
        const first = await p.$eval('[data-kol-list="words"] > [data-kol-pre]', e => e.textContent.trim().slice(0, 30));
        ok(/\d/.test(first), 'הכרטיס הראשון עם תוכן', first);
      } else {
        await p.waitForSelector('[data-kol-list="words"][data-kol-ready]', { state: 'attached', timeout: 15000 });
        const n = await p.$$eval('[data-kol-list="words"] > :not(template)', els => els.length);
        const pre = await p.$$eval('[data-kol-list="words"] > [data-kol-pre]', els => els.length);
        ok(n === 1496 && pre === 0, 'JS: בלי כפילות — הרשימה הכתובה הוחלפה בחיה', `${n} כרטיסים, ${pre} כתובים`);
        const q = await p.$('[data-kol-search]');
        if (ok(q, 'יש חיפוש')) {
          await q.fill('1234');
          const shown = await until(() => p.$$eval('[data-kol-list="words"] > :not(template)', els => els.filter(e => e.offsetParent !== null).length).then(x => x > 0 && x < 50 && x), 5000);
          ok(shown, 'החיפוש מסנן', String(shown));
          await q.fill('');
        }
        const play = await p.$('[data-kol-list="words"] > :not(template) [data-kol-play]');
        if (ok(play, 'יש כפתור השמעה')) {
          const resp = p.waitForResponse(r => r.url().includes('/media/audio/'), { timeout: 8000 }).catch(() => null);
          // ההקלטה של מילה 1 או 2 — מחפשים את הכרטיס שלהן
          await p.evaluate(() => {
            const items = window.KolData.lists.words.items;
            const it = items.find(x => String(x.audio).startsWith('media/'));
            const card = [...document.querySelectorAll('[data-kol-list="words"] > :not(template)')].find(e => e.__kolItem === it);
            card.querySelector('[data-kol-play]').click();
          });
          const r = await resp;
          ok(r && r.status() === 200, 'ההקלטה מתנגנת — נטענת מתוך החבילה', r ? r.url().replace(st, '') : 'אין בקשה');
        }
      }
      ok(failedReq.length === 0, `${js ? 'JS' : 'בלי JS'}: 0 בקשות שנכשלו לחבילה`, failedReq.slice(0, 3).join(' | '));
      ok(cerr.length === 0, `${js ? 'JS' : 'בלי JS'}: 0 שגיאות קונסול`, cerr.slice(0, 3).join(' | '));
      await c.close();
    }

    // ════ ד. כשל בנייה ════
    console.log('\n── ד. כשל בנייה ──');
    const tree = await call('pf_tree');
    const pj = tree.files.find(f => f.path === 'js/page.js');
    const pjText = (await call('pf_read', undefined, '&id=' + pj.id)).content;
    await call('pf_write', { id: pj.id, rev: pj.rev, content: '// שורה 1\nthrow new Error("בום בבנייה");\n' });
    await page.evaluate(() => $('#sheet').open && $('#sheet').close());
    await page.goto(base + '/#/publish');
    await ready();
    await page.click('.pk .btn.primary');
    const err = await until(() => page.$('#sheet[open] .pk-err'), 30000);
    ok(err, 'שגיאת JS ← "הבנייה נכשלה — אין גרסה"');
    const errText = err ? await err.textContent() : '';
    ok(/js\/page\.js:2/.test(errText) && errText.includes('בום בבנייה'), 'השגיאה עם קובץ ושורה', errText.replace(/\s+/g, ' ').slice(0, 120));
    ok((await call('builds')).builds[0].status === 'failed', 'הבנייה נכשלה — לא נוצרה גרסה מוכנה');
    await page.click('#sheet .pk-err button:has-text("בנה בלי כתיבה מראש")');
    const nb = await until(async () => { const l = await call('builds'); return l.builds[0].status === 'built' && l.builds[0]; }, 30000);
    ok(nb && nb.opts.prerender === false, '"בנה בלי כתיבה מראש" ← גרסה מוכנה');
    const pj2 = (await call('pf_tree')).files.find(f => f.path === 'js/page.js');
    await call('pf_write', { id: pj2.id, rev: pj2.rev, content: pjText });

    ok(errors.length === 0, '0 שגיאות בסטודיו', errors.slice(0, 3).join(' | '));
  } catch (e) {
    failed++;
    console.log('❌ ' + (e.stack || e.message));
  } finally {
    if (browser) await browser.close();
    php.kill(); wpPhp.kill(); stat.close();
    fs.rmSync(data, { recursive: true, force: true }); fs.rmSync(wpDir, { recursive: true, force: true });
  }
  console.log(`\n${failed ? `נכשלו ${failed} מתוך ${ran}` : `כל ${ran} הבדיקות עברו`}`);
  process.exit(failed ? 1 : 0);
})();
