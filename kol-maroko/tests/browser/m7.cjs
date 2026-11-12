/**
 * פרוסה M7 — משיכה במקום דחיפה, בדפדפן (Chromium, Playwright):   node kol-maroko/tests/browser/m7.cjs [תיקיית-צילומים]
 *
 *  א. בעל האתר (1920): ⚙️ הגדרות — הערוץ "📥 משיכה", "📋 יצירת שורת חיבור" ← השורה מוצגת פעם אחת; היא מודבקת בתוסף האמיתי
 *     (tests/fake-wp.php) — כמו שהבעלים מדביק בוורדפרס.
 *  ב. 🚀 פרסום — "📡 הדרך לאתר": הדופק אדום ("עוד לא משך"); בנייה ← "לתור" ← "⏳ ממתין למשיכה", והבנייה **אינה** "באתר".
 *  ג. "🔄 בדוק עכשיו" ← האתר מושך (התוסף האמיתי, /__pull) ← הדופק ירוק, "✓ הוחל באתר", הבנייה באתר — בלי רענון.
 *  ד. אופן ההצבה (⚙️ בעל האתר) ← "⏳ בתור" ← משיכה ← באתר.
 *  ה. פקודה שפג תוקפה ← "⌛ פג תוקף" + "↻ שלח שוב" ← seq חדש בתור.
 *  ו. 1920 — השורות ממלאות את הרוחב (עמודות); 390 — כרטיסים, בלי גלילה אופקית; 0 שגיאות קונסול. צילומים לבדיקה בעין.
 */
'use strict';
const { chromium } = require('playwright');
const { spawn, execFileSync } = require('child_process');
const fs = require('fs'), os = require('os'), path = require('path'), net = require('net');

const ROOT = path.resolve(__dirname, '..', '..');
const SHOTS = process.argv[2] || null;
const OWNER = { sub: 'u-owner', name: 'Owner Test', email: 'owner@example.test', email_verified: true, platform_admin: true };
let failed = 0, ran = 0;
const ok = (cond, what, detail = '') => { ran++; if (cond) console.log('✅ ' + what + (detail ? ' — ' + detail : '')); else { failed++; console.log('❌ ' + what + (detail ? ' — ' + detail : '')); } return !!cond; };
const freePort = () => new Promise(r => { const s = net.createServer().listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => r(p)); }); });
const sleep = ms => new Promise(r => setTimeout(r, ms));
const until = async (fn, ms = 8000) => { const t = Date.now(); for (;;) { try { const v = await fn(); if (v) return v; } catch { /* עוד לא */ } if (Date.now() - t > ms) return null; await sleep(100); } };

(async () => {
  const data = fs.mkdtempSync(path.join(os.tmpdir(), 'kol-m7-'));
  const wpDir = fs.mkdtempSync(path.join(os.tmpdir(), 'kol-m7wp-'));
  execFileSync('php', [path.join(__dirname, 'f3-seed.php'), data, path.join(data, 'old.html')]);
  const [port, wpPort] = [await freePort(), await freePort()];
  const base = `http://127.0.0.1:${port}`, wp = `http://127.0.0.1:${wpPort}`;
  const php = (code, ...args) => execFileSync('php', ['-r', `define('KOL_DATA_DIR', $argv[1]); foreach (['members', 'import', 'ai', 'agent', 'dev', 'preview', 'migrate', 'build'] as $l) require_once $argv[2] . "/lib/$l.php"; ${code}`, data, ROOT, ...args]).toString();
  php(`setting_set('site_url', $argv[3]); setting_set('pub_channel', 'pull'); setting_set('pull_url', $argv[4]);
       file_put_contents($argv[5] . '/options.json', json_encode(['kol_maroko_keys' => pub_key_line(pub_key())]));`, wp, base + '/pull.php', wpDir);
  const env = { ...process.env, PHP_CLI_SERVER_WORKERS: '6' };
  const srv = spawn('php', ['-S', `127.0.0.1:${port}`, path.join(ROOT, 'tests', 'router.php')], { cwd: ROOT, env: { ...env, KOL_TEST_DATA: data }, stdio: 'ignore' });
  const wpPhp = spawn('php', ['-S', `127.0.0.1:${wpPort}`, path.join(ROOT, 'tests', 'fake-wp.php')], { cwd: ROOT, env: { ...env, KOL_TEST_WP: wpDir }, stdio: 'ignore' });
  const wpOpt = (k, v) => { const f = path.join(wpDir, 'options.json'); const o = JSON.parse(fs.readFileSync(f, 'utf8')); if (v === undefined) delete o[k]; else o[k] = v; fs.writeFileSync(f, JSON.stringify(o)); };
  const wpGet = k => JSON.parse(fs.readFileSync(path.join(wpDir, 'options.json'), 'utf8'))[k];
  const sitePull = async () => (await fetch(wp + '/__pull')).json();         // "🔄 עדכן עכשיו" / wp-cron בתוסף האמיתי
  let browser;
  const errors = [];
  const shot = async (page, name) => { if (SHOTS) { fs.mkdirSync(SHOTS, { recursive: true }); await page.screenshot({ path: path.join(SHOTS, name), fullPage: false }); } };
  try {
    for (let i = 0; i < 100; i++) { try { await fetch(base + '/api.php?action=embed_key'); await fetch(wp + '/'); break; } catch { await sleep(50); } }
    browser = await chromium.launch({ args: ['--site-per-process'] });
    const open = async width => {
      const ctx = await browser.newContext({ viewport: { width, height: width > 500 ? 1080 : 844 }, ...(width < 500 ? { isMobile: true, hasTouch: true } : {}) });
      await ctx.addCookies([{ name: 'kol_test_user', value: Buffer.from(JSON.stringify(OWNER)).toString('base64'), url: base, httpOnly: true, sameSite: 'Lax' }]);
      await ctx.grantPermissions(['clipboard-read', 'clipboard-write'], { origin: base }).catch(() => {});
      const page = await ctx.newPage();
      page.on('pageerror', e => errors.push(width + ': ' + e.message));
      page.on('console', m => { if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) errors.push(width + ': ' + m.text()); });
      page.on('dialog', d => d.accept());
      return page;
    };
    const page = await open(1920);
    const call = (action, body, query = '') => page.evaluate(([a, b, q]) => api(a, b ?? undefined, q), [action, body ?? null, query]);

    // ════ א. שורת החיבור ════
    console.log('\n── א. שורת החיבור ──');
    await page.goto(base + '/#/settings');
    await page.waitForSelector('.ob-channel', { timeout: 15000 }).catch(async e => { throw new Error(e.message + ' · ' + (await page.textContent('#main').catch(() => '')).slice(0, 600) + ' · ' + errors.join(' / ')); });
    ok(await page.getAttribute('.ob-channel button:has-text("📥 משיכה")', 'aria-pressed') === 'true', 'הערוץ — משיכה');
    await page.click('.ob-channel button:has-text("שורת חיבור")');
    const line = (await (await page.waitForSelector('.ob-line')).textContent()).trim();
    const m = line.match(/^kol-maroko:2:([^:]+):([^:]+):(.+):([A-Za-z0-9_-]{43})$/);
    ok(m && m[3] === base + '/pull.php', 'השורה: kol-maroko:2:<kid>:<מפתח>:<כתובת>:<אסימון>', line.slice(0, 60) + '…');
    await page.$eval('.ob-channel', e => e.scrollIntoView({ block: 'center' }));
    await shot(page, 'm7-settings-1920.png');
    wpOpt('kol_maroko_pull_url', m[3]);
    wpOpt('kol_maroko_pull_token', m[4]);
    await page.reload();
    await page.waitForSelector('.ob-channel');
    ok(!(await page.textContent('#main')).includes(m[4]), 'אחרי רענון — האסימון אינו מוצג שוב');

    // ════ ב. התור ════
    console.log('\n── ב. פרסום ← ממתין למשיכה ──');
    await page.goto(base + '/#/publish');
    await page.waitForSelector('.ob .ob-pulse', { timeout: 20000 }).catch(async e => { throw new Error(e.message + ' · ' + (await page.textContent('#main').catch(() => '')).slice(0, 500) + ' · ' + errors.join(' / ')); });
    ok(await page.$('.ob-pulse.ob-bad') && /עוד לא משך/.test(await page.textContent('.ob-pulse')), 'הדופק — אדום: האתר עוד לא משך');
    await call('pf_migrate', {});
    await page.reload();
    await page.waitForSelector('.pk .btn.primary', { timeout: 15000 });
    await page.click('.pk .btn.primary');
    ok(await until(() => page.$('#sheet[open] .pk-sheet .chip.ok'), 30000), 'נבנתה גרסה');
    const b1 = (await call('builds')).builds[0];
    const go = await until(() => page.$('#sheet .pk-send button:not([disabled])'), 10000);
    ok(go && /לתור/.test(await go.textContent()), 'הכפתור — "לתור", גם לפני שהאתר משך', go ? await go.textContent() : '');
    ok(/האתר עוד לא משך/.test(await page.textContent('#sheet .pk-send')), 'הסבר: הגרסה תחכה בתור');
    await go.click();
    ok(await until(async () => (await call('build_get', undefined, '&id=' + b1.id)).build.status === 'sending', 15000), 'הבנייה — בתור, **לא** באתר');
    await page.keyboard.press('Escape');
    await page.goto(base + '/#/publish');
    await page.reload();
    const qRow = await until(() => page.$('.ob-row.ob-queued'), 10000);
    ok(qRow && /ממתין למשיכה/.test(await qRow.textContent()) && /גרסה/.test(await qRow.textContent()), '"⏳ ממתין למשיכה" בשורה של הגרסה');
    ok(/ממתינות למשיכה/.test(await page.textContent('.ob')) && /עדכן עכשיו/.test(await page.textContent('.ob')), 'ההוראה: וורדפרס ← 🔄 עדכן עכשיו');
    await shot(page, 'm7-queued-1920.png');
    // 1920 — השורות ממלאות את הרוחב, בעמודות
    const geo = await page.evaluate(() => {
      const r = document.querySelector('.ob-row').getBoundingClientRect(), mm = document.querySelector('#main').getBoundingClientRect();
      return { w: r.width, main: mm.width, cols: getComputedStyle(document.querySelector('.ob-row')).gridTemplateColumns.split(' ').length, sw: document.documentElement.scrollWidth, iw: innerWidth };
    });
    ok(geo.cols === 5 && geo.w >= geo.main * 0.9, '1920: שורה אחת לכל פקודה, חמש עמודות, ברוחב המסך', JSON.stringify(geo));
    ok(geo.sw <= geo.iw, '1920: בלי גלילה אופקית');

    // ════ ג. בדוק עכשיו ← המשיכה ════
    console.log('\n── ג. בדוק עכשיו ← הוחל ──');
    await page.click('.ob-pulse button:has-text("בדוק עכשיו")');
    ok(/מחכה לדופק/.test(await page.textContent('.ob-wait')), '"דפקתי על האתר — מחכה לדופק"');
    ok(await until(() => wpGet('kol_maroko_pull_wake_at'), 5000), 'הדפיקה הגיעה לתוסף (?kol-maroko-wake=1)');
    const run = await sitePull();
    ok(run.ok && run.applied === 1, 'התוסף האמיתי משך והחיל', JSON.stringify(run).slice(0, 160));
    const applied = await until(() => page.$('.ob-row.ob-applied'), 20000);
    ok(applied && /הוחל באתר/.test(await applied.textContent()), '"✓ הוחל באתר" — בלי רענון');
    ok(/ייתכן מטמון ב-CDN/.test(await page.textContent('.ob-row.ob-applied')), '"☁️ ייתכן מטמון ב-CDN" + Purge');
    ok(await page.$('.ob-pulse.ob-ok') && /לפני פחות מדקה/.test(await page.textContent('.ob-pulse')) && /תוסף 2\.2\.0/.test(await page.textContent('.ob-pulse')), 'הדופק — ירוק, "לפני פחות מדקה · תוסף 2.2.0"', await page.textContent('.ob-pulse'));
    ok((await call('build_get', undefined, '&id=' + b1.id)).build.status === 'live', 'הבנייה באתר — רק אחרי applied');
    const pkLive = await until(async () => /באתר/.test(await page.textContent('.pk')) && !/ממתין למשיכה/.test(await page.textContent('.pk')), 8000);
    ok(pkLive, 'גם ברשימת הבניות — "באתר", בלי רענון', pkLive ? '' : (await page.textContent('#main')).slice(0, 400) + ' / ' + errors.join(' / '));
    ok(!/null/.test(await page.textContent('.ob')), 'אין "null" במסך');
    ok(+wpGet('kol_maroko_v2_live') === (await call('build_get', undefined, '&id=' + b1.id)).build.version, 'בתוסף: הגרסה באוויר');
    await shot(page, 'm7-applied-1920.png');

    // ════ ד. אופן ההצבה דרך התור ════
    console.log('\n── ד. אופן ההצבה ──');
    await page.goto(base + '/#/settings');
    await page.waitForSelector('#mount-block input[value="framed"]', { timeout: 15000 });
    await page.check('#mount-block input[value="framed"]');
    await page.click('#mount-block button:has-text("שמירה באתר")');
    ok(await until(async () => /בתור/.test(await page.textContent('#mount-block [role=status]')), 8000), '"⏳ בתור" — לא "✓ באתר"', await page.textContent('#mount-block [role=status]'));
    ok((await sitePull()).applied === 1, 'משיכה');
    ok(wpGet('kol_maroko_v2_mount') === 'framed' && (await call('mount')).mount === 'framed', 'באתר, ובכלי אחרי applied');

    // ════ ה. פג תוקף ← שלח שוב ════
    console.log('\n── ה. פג תוקף ──');
    const sw = await call('site_switch', { enabled: false });
    php(`db()->prepare('UPDATE site_outbox SET expires = ? WHERE seq = ?')->execute([time() - 1, (int)$argv[3]]);`, String(sw.site.seq));
    await page.goto(base + '/#/publish');
    const exp = await until(() => page.$(`.ob-row.ob-expired[data-seq="${sw.site.seq}"]`), 10000);
    ok(exp && /פג תוקף/.test(await exp.textContent()) && /שלח שוב/.test(await exp.textContent()), '"⌛ פג תוקף" + הסבר + "↻ שלח שוב"');
    await shot(page, 'm7-expired-1920.png');
    await page.click(`.ob-row.ob-expired[data-seq="${sw.site.seq}"] .ob-resend`);
    ok(await until(async () => { const o = await call('outbox'); return o.outbox[0].seq > sw.site.seq && o.outbox[0].state === 'queued' && o.outbox[0].action === 'switch'; }, 8000), 'נשלחה שוב — seq חדש בתור');

    // ════ ו. 390 ════
    console.log('\n── ו. טלפון ──');
    const phone = await open(390);
    await phone.goto(base + '/#/publish');
    await phone.waitForSelector('.ob .ob-row', { timeout: 15000 });
    const pg = await phone.evaluate(() => ({ sw: document.documentElement.scrollWidth, iw: innerWidth,
      cols: getComputedStyle(document.querySelector('.ob-row')).gridTemplateColumns.split(' ').length,
      over: [...document.querySelectorAll('.ob *')].filter(e => e.getBoundingClientRect().right > innerWidth + 1).map(e => e.className).slice(0, 5) }));
    ok(pg.sw <= pg.iw, '390: בלי גלילה אופקית', JSON.stringify(pg));
    ok(pg.cols <= 2 && !pg.over.length, '390: כרטיסים (לא עמודות), שום דבר לא חורג', JSON.stringify(pg));
    await shot(phone, 'm7-publish-390.png');
    await phone.goto(base + '/#/settings');
    await phone.waitForSelector('.ob-channel');
    ok(await phone.evaluate(() => document.documentElement.scrollWidth <= innerWidth), '390 הגדרות: בלי גלילה אופקית');
    await shot(phone, 'm7-settings-390.png');

    ok(!errors.length, '0 שגיאות קונסול', errors.slice(0, 3).join(' | '));
  } catch (e) {
    failed++;
    console.log('❌ חריגה: ' + (e.stack || e.message));
  } finally {
    if (browser) await browser.close();
    srv.kill(); wpPhp.kill();
    fs.rmSync(data, { recursive: true, force: true });
    fs.rmSync(wpDir, { recursive: true, force: true });
  }
  console.log(`\n${failed ? `נכשלו ${failed} מתוך ${ran}` : `כל ${ran} הבדיקות עברו`}`);
  process.exit(failed ? 1 : 0);
})();
