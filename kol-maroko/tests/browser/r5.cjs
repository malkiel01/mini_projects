/**
 * R5 — איפוס נקודות השחזור ונקודת "מקור" (הבעלים, 15.11). Chromium, Playwright, ב-1280 וב-390:
 *   node kol-maroko/tests/browser/r5.cjs
 * בעל האתר רואה "🧹 איפוס"; בעלת העמוד — לא. נקודה בלי קובצי העמוד מסומנת ⚠️ ברשימה, בגיליון ובאישור השחזור.
 * האיפוס: הטקסט עם N, ואחריו — נקודה אחת "📌 מקור" בראש; שחזור אליה מחזיר מילה.
 * R6 ("לחצתי ולא קרה כלום"): הכפתור תמיד לחיץ ולחיצה בלי אישור אומרת מה חסר; RLM/ניקוד/רווח קשיח מנורמלים; Enter = לחיצה;
 * 423 מהשרת — גלוי בחלון; ההצלחה — "נמחקו N · נקודת מקור #X" בחלון; כל לחיצה ותוצאה — ביומן המפתח (KolUi).
 */
'use strict';
const { chromium } = require('playwright');
const { spawn, execFileSync } = require('child_process');
const fs = require('fs'), os = require('os'), path = require('path'), net = require('net');
const ROOT = path.resolve(__dirname, '..', '..');
const SHOTS = path.join(os.tmpdir(), 'kol-r5-shots');
const OWNER = { sub: 'u-owner', name: 'Owner Test', email: 'owner@example.test', email_verified: true, platform_admin: true };
const PAGE = { sub: '502', name: 'בעלת העמוד', email: 'page@example.test', email_verified: true };
let failed = 0, ran = 0;
const ok = (cond, what, got) => { ran++; if (cond) console.log('✅ ' + what); else { failed++; console.log('❌ ' + what + (got !== undefined ? '\n   התקבל: ' + JSON.stringify(got).slice(0, 600) : '')); } };
const freePort = () => new Promise(r => { const s = net.createServer().listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => r(p)); }); });
const sleep = ms => new Promise(r => setTimeout(r, ms));

(async () => {
  const data = fs.mkdtempSync(path.join(os.tmpdir(), 'kol-r5-'));
  fs.mkdirSync(SHOTS, { recursive: true });
  execFileSync('php', [path.join(__dirname, 'f3-seed.php'), data, path.join(data, 'old-render.html')]);
  execFileSync('php', ['-r', `
    define('KOL_DATA_DIR', $argv[1]);
    foreach (['members', 'site', 'restore', 'ai', 'agent', 'dev'] as $l) require_once $argv[2] . "/lib/$l.php";
    db()->prepare("INSERT OR IGNORE INTO users (sub, email, name, role, status, created_at) VALUES ('502', 'page@example.test', 'בעלת העמוד', 'page_owner', 'active', ?)")->execute([now_iso()]);
  `, data, ROOT]);
  /** נקודה "ישנה" — בלי project, כמו נקודה מלפני F2a */
  const oldPoint = () => execFileSync('php', ['-r', `
    $p = new PDO("sqlite:" . $argv[1] . "/kol.sqlite");
    $s = $p->query("SELECT parts FROM snapshots ORDER BY id DESC LIMIT 1")->fetchColumn();
    $parts = json_decode($s, true); unset($parts['project']);
    $p->prepare("INSERT INTO snapshots (at, sub, name, reason, kind, parts, meta) VALUES ('2026-11-13T08:00:00Z', 'system', 'אוטומטי', 'נקודה ישנה בלי קבצים', 'daily', ?, '{}')")->execute([json_encode($parts)]);
    echo $p->lastInsertId();
  `, data]).toString().trim();
  const port = await freePort(), base = `http://127.0.0.1:${port}`;
  const php = spawn('php', ['-S', `127.0.0.1:${port}`, path.join(ROOT, 'tests', 'router.php')], { cwd: ROOT, stdio: ['ignore', 'ignore', process.env.R5_LOG ? 'inherit' : 'ignore'],
    env: { ...process.env, KOL_TEST_DATA: data, PHP_CLI_SERVER_WORKERS: '4' } });
  let browser;
  const errors = [];
  try {
    for (let i = 0; i < 100; i++) { try { await fetch(base + '/api.php?action=embed_key'); break; } catch { await sleep(50); } }
    browser = await chromium.launch();
    const open = async (who, width) => {
      const ctx = await browser.newContext({ viewport: { width, height: width === 390 ? 844 : 900 }, extraHTTPHeaders: { 'X-Test-User': JSON.stringify(who) } });
      const page = await ctx.newPage();
      page.on('pageerror', e => errors.push(e.message));
      page.dialogs = [];
      page.on('dialog', d => { page.dialogs.push(d.message()); d.accept(); });
      page.uiLog = [];                                                      // R6: KolUi → dev-client-error (kind: ui)
      page.on('request', r => { if (r.url().includes('action=dev-client-error')) { try { const b = JSON.parse(r.postData()); if (b.kind === 'ui') page.uiLog.push(b); } catch {} } });
      return page;
    };
    const call = (page, a, b) => page.evaluate(([a, b]) => api(a, b).catch(e => ({ error: e.message })), [a, b]);
    const toPoints = async page => {
      await page.goto(base + '/#/restore');
      await page.evaluate(() => sessionStorage.setItem('kol-restore-tab', 'points'));
      await page.goto(base + '/?r=' + Date.now() + '#/restore');
      await page.waitForSelector('[data-snap]');
    };

    // הכנה: הפרויקט, ונקודה חדשה (עם קבצים)
    const setup = await open(OWNER, 1280);
    await setup.goto(base + '/#/project'); await setup.waitForSelector('.pj-tree .pj-row');
    await call(setup, 'pf_migrate', {});
    const w = await call(setup, 'word_save', { word: 'r5-מילה', meaning: 'במקור' });
    ok(w && w.id, 'מילה לבדיקה', w);
    await call(setup, 'snapshot_create', { note: 'עם קבצים' });
    await setup.context().close();

    for (const width of [1280, 390]) {
      const tag = `[${width}] `;
      const old = oldPoint();
      const page = await open(OWNER, width);
      await toPoints(page);

      // ⚠️ נקודה בלי קובצי העמוד
      const oldRow = page.locator(`[data-snap="${old}"]`);
      ok(await oldRow.locator('[data-snap-project="false"]').innerText().then(t => t.includes('בלי קובצי העמוד')).catch(() => false), tag + 'ברשימה: הנקודה הישנה מסומנת "⚠️ בלי קובצי העמוד"');
      const withRow = page.locator('[data-snap]').filter({ has: page.locator('[data-snap-project="true"]') }).first();
      ok(await withRow.count() === 1 && (await withRow.innerText()).includes('כולל את קובצי העמוד'), tag + 'ברשימה: נקודה רגילה — "כולל את קובצי העמוד"');
      const ow = await oldRow.evaluate(e => e.scrollWidth <= e.clientWidth + 1);
      ok(ow, tag + 'השורה לא גולשת לרוחב');
      await page.screenshot({ path: path.join(SHOTS, `r5-list-${width}.png`) });
      await oldRow.locator('button').click();
      await page.waitForSelector('#sheet[open] [data-snap-sheet-project]');
      ok((await page.locator('[data-snap-sheet-project]').innerText()).includes('שחזור לא ישנה את העמוד'), tag + 'בגיליון: "⚠️ בלי קובצי העמוד"');
      page.dialogs.length = 0;
      page.once('dialog', () => {});
      await page.evaluate(() => { window.__c = window.confirm; window.confirm = m => { window.__msg = m; return false; }; });
      await page.locator('#sheet button.primary', { hasText: 'שחזור לנקודה הזו' }).click();
      const msg = await page.evaluate(() => { const m = window.__msg; window.confirm = window.__c; return m; });
      ok(msg && msg.includes('בלי קובצי העמוד'), tag + 'באישור השחזור: האזהרה', msg);
      await page.keyboard.press('Escape');

      // 🧹 איפוס
      const total = await page.evaluate(() => api('snapshots').then(d => d.total));
      ok(total >= 3, tag + `יש ${total} נקודות לפני האיפוס`);
      await page.click('[data-snap-reset]');
      await page.waitForSelector('#sheet[open] [data-snap-reset-text]');
      ok((await page.locator('[data-snap-reset-text]').innerText()).includes(`כל ${total} הנקודות יימחקו`), tag + 'הטקסט: "כל N הנקודות יימחקו…"');
      ok(await page.locator('#sheet a[href="api.php?action=backup"]').count() === 1, tag + 'קישור "💾 הורד גיבוי קודם"');
      // R6: הכפתור תמיד לחיץ; בלי אישור — שורה אדומה בתוך החלון, ושום בקשה לא יוצאת
      const go = page.locator('[data-snap-reset-go]'), st = page.locator('[data-snap-reset-status]'), field = page.locator('[data-snap-reset-type]');
      const resets = [];
      const onReq = r => { if (r.url().includes('action=snapshots_reset')) resets.push(r.url()); };
      page.on('request', onReq);
      ok(!(await go.isDisabled()), tag + 'R6: הכפתור לחיץ גם לפני ההקלדה');
      ok(await field.getAttribute('placeholder') === null, tag + 'R6: אין placeholder שנראה כמו ערך');
      await go.click();
      ok(await st.getAttribute('data-snap-reset-status') === 'need' && (await st.innerText()).includes('כתבו בשדה את המילה: איפוס')
        && await st.locator('.error').count() === 1 && await page.locator('#sheet[open]').count() === 1, tag + 'R6: לחיצה בלי הקלדה — שורה אדומה "מה חסר", החלון פתוח', await st.innerText());
      await field.fill('אולי');
      await go.click();
      ok((await st.innerText()).includes('כתבתם "אולי"'), tag + 'R6: טקסט אחר — אומר מה הוקלד ומה צריך', await st.innerText());
      ok(resets.length === 0, tag + 'R6: בלי אישור לא נשלחה בקשה לשרת', resets);
      await page.screenshot({ path: path.join(SHOTS, `r6-need-${width}.png`) });

      // R6: שגיאה מהשרת (423) — גלויה בתוך החלון, עם הקוד והסיבה
      await page.route('**/api.php?action=snapshots_reset*', r => r.fulfill({ status: 423, contentType: 'application/json', body: JSON.stringify({ ok: false, error: 'הטיוטה בעריכה אצל מישהו אחר' }) }), { times: 1 });
      await field.fill('איפוס');
      await go.click();
      await page.waitForFunction(() => document.querySelector('[data-snap-reset-status]').dataset.snapResetStatus === 'error');
      ok((await st.innerText()).includes('423') && (await st.innerText()).includes('בעריכה אצל'), tag + 'R6: 423 — "האיפוס לא בוצע" עם הקוד והסיבה, בתוך החלון', await st.innerText());
      ok(!(await go.isDisabled()), tag + 'R6: אחרי שגיאה — אפשר לנסות שוב');

      // R6: תווים בלתי נראים מהמקלדת (RLM/LRM, ניקוד, רווח קשיח) — עדיין "איפוס". ב-390: Enter במקלדת במקום לחיצה
      await field.fill(width === 390 ? '‎אִיפוּס ' : '‏איפוס ‏');
      await page.screenshot({ path: path.join(SHOTS, `r5-reset-${width}.png`) });
      if (width === 390) await field.press('Enter'); else await go.click();
      await page.waitForFunction(() => document.querySelector('[data-snap-reset-status]')?.dataset.snapResetStatus === 'ok' && document.querySelectorAll('[data-snap]').length === 1, null, { timeout: 15000 }).catch(async e => { console.log('   בחלון: ' + await st.innerText()); throw e; });
      const okText = await st.innerText();
      ok(new RegExp(`נמחקו ${total} נקודות · נקודת מקור #\\d+`).test(okText) && await page.locator('#sheet[open]').count() === 1,
        tag + `R6: ${width === 390 ? 'Enter' : 'לחיצה'} עם תווים נסתרים — "נמחקו N · נקודת מקור #X" בתוך החלון`, okText);
      ok(await page.evaluate(() => { const s = document.querySelector('#sheet'); return s.scrollWidth <= s.clientWidth + 1; }), tag + 'R6: החלון לא גולש לרוחב');
      await page.screenshot({ path: path.join(SHOTS, `r6-ok-${width}.png`) });
      await go.click();                                                     // "✓ סגירה"
      await page.waitForFunction(() => !document.querySelector('#sheet[open]'));
      page.off('request', onReq);
      ok(resets.length === 2, tag + 'R6: בדיוק שתי בקשות (423 ואז ההצלחה)', resets);
      await sleep(300);
      const logged = page.uiLog.map(x => x.action);
      ok(['snapshots_reset.open', 'snapshots_reset.blocked', 'snapshots_reset.error', 'snapshots_reset.click', 'snapshots_reset.ok'].every(a => logged.includes(a))
        && page.uiLog.find(x => x.action === 'snapshots_reset.error').status === 423, tag + 'R6: יומן המפתח — פתיחה, חסימה, שגיאה (423), לחיצה והצלחה', page.uiLog);
      const first = page.locator('[data-snap]').first();
      ok((await first.innerText()).includes('📌 מקור') && await first.evaluate(e => e.classList.contains('snap-origin')), tag + 'אחרי: נקודה אחת "📌 מקור"', await first.innerText());
      const d = await page.evaluate(() => api('snapshots'));
      ok(d.total === 1 && d.snapshots[0].kind === 'origin' && d.snapshots[0].project === true, tag + 'בשרת: נקודה אחת, origin, כוללת את קובצי העמוד', d);
      const originId = d.snapshots[0].id;

      // נקודה חדשה — המקור עדיין ראשון; ושחזור אליו
      await call(page, 'word_save', { id: w.id, word: 'r5-מילה', meaning: `שונה ${width}` });
      await call(page, 'snapshot_create', { note: 'אחרי המקור' });
      await toPoints(page);
      ok(await page.locator('[data-snap]').first().getAttribute('data-snap') === String(originId), tag + 'המקור בראש הרשימה גם אחרי נקודה חדשה');
      await page.locator('[data-snap]').first().locator('button').click();
      await page.waitForSelector('#sheet[open] [data-snap-sheet-project="true"]');
      await page.locator('#sheet button.primary', { hasText: 'שחזור לנקודה הזו' }).click();
      await page.waitForFunction(() => !document.querySelector('#sheet[open]'));
      const back = await page.evaluate(id => api('words').then(r => (r.words || r.items || []).find(x => x.id === id)), w.id);
      ok(back && back.meaning === 'במקור', tag + 'שחזור למקור החזיר את המילה', back);
      await page.screenshot({ path: path.join(SHOTS, `r5-after-${width}.png`) });
      await page.context().close();

      // בעלת העמוד — אין כפתור, והשרת דוחה
      const po = await open(PAGE, width);
      await toPoints(po);
      ok(await po.locator('[data-snap-reset]').count() === 0, tag + 'בעלת העמוד: אין "🧹 איפוס"');
      const r = await call(po, 'snapshots_reset', { confirm: true });
      ok(r.error && /בעל האתר/.test(r.error), tag + 'בעלת העמוד: השרת דוחה', r);
      await po.context().close();
    }
    ok(errors.length === 0, 'אין שגיאות JS', errors);
  } catch (e) {
    failed++; console.log('❌ ' + e.stack);
  } finally {
    if (browser) await browser.close();
    php.kill();
    console.log(`\n${ran - failed}/${ran} · צילומים: ${SHOTS}`);
    process.exit(failed ? 1 : 0);
  }
})();
