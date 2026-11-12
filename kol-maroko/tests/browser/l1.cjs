/**
 * L1 — הסטודיו על כל המסך במחשב (Chromium, Playwright):   node kol-maroko/tests/browser/l1.cjs
 *
 * עמוד המילון (הזריעה של F3) — העמודה המיובאת היא display:contents, והרכיב התכווץ לרוחב התוכן (~717 מתוך 1580).
 * ב-1920 וב-1280: המסגרת + הפאנל = כל הרוחב, המסגרת עד תחתית המסך, והרכיב שבתוכה ממלא אותה.
 * "טלפון" — 390 ממורכז; טלפון אמיתי (390×844) — בלי פאנל, המסגרת לכל הרוחב. צילומים ל-dev/l1/.
 */
'use strict';
const { chromium } = require('playwright');
const { spawn, execFileSync } = require('child_process');
const fs = require('fs'), os = require('os'), path = require('path'), net = require('net');

const ROOT = path.resolve(__dirname, '..', '..');
const SHOTS = path.join(ROOT, 'dev', 'l1');
const OWNER = { sub: 'u-owner', name: 'Owner Test', email: 'owner@example.test', email_verified: true, platform_admin: true };
let failed = 0, ran = 0;
const ok = (cond, what) => { ran++; if (cond) console.log('✅ ' + what); else { failed++; console.log('❌ ' + what); } return !!cond; };
const freePort = () => new Promise(r => { const s = net.createServer().listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => r(p)); }); });
const sleep = ms => new Promise(r => setTimeout(r, ms));
const until = async (fn, ms = 10000) => { const t = Date.now(); for (;;) { try { const v = await fn(); if (v) return v; } catch { /* עוד לא */ } if (Date.now() - t > ms) return null; await sleep(100); } };

(async () => {
  const data = fs.mkdtempSync(path.join(os.tmpdir(), 'kol-l1-'));
  execFileSync('php', [path.join(__dirname, 'f3-seed.php'), data, path.join(data, 'old.html')]);
  const port = await freePort(), base = `http://127.0.0.1:${port}`;
  const php = spawn('php', ['-S', `127.0.0.1:${port}`, path.join(ROOT, 'tests', 'router.php')], { cwd: ROOT, env: { ...process.env, KOL_TEST_DATA: data, PHP_CLI_SERVER_WORKERS: '4' }, stdio: 'ignore' });
  let browser;
  try {
    for (let i = 0; i < 100; i++) { try { await fetch(base + '/api.php?action=embed_key'); break; } catch { await sleep(50); } }
    fs.mkdirSync(SHOTS, { recursive: true });
    browser = await chromium.launch();
    const open = async (W, H) => {
      const ctx = await browser.newContext({ viewport: { width: W, height: H } });
      await ctx.addCookies([{ name: 'kol_test_user', value: Buffer.from(JSON.stringify(OWNER)).toString('base64'), url: base, httpOnly: true, sameSite: 'Lax' }]);
      const page = await ctx.newPage();
      await page.goto(base + '/index.php#/studio');
      await page.waitForSelector('.st-frame');
      const fr = await until(() => page.frames().find(f => f !== page.mainFrame() && f.url() === 'about:srcdoc'));
      await until(() => fr.$('.kol-w'));
      await sleep(400);
      return { ctx, page, fr };
    };
    const box = (page, sel) => page.$eval(sel, e => { const r = e.getBoundingClientRect(); return { x: r.x, w: r.width, top: r.top, bottom: r.bottom, vis: getComputedStyle(e).display !== 'none' }; });
    const inner = fr => fr.evaluate(() => ({ iw: innerWidth, w: Math.round(document.querySelector('.kol-w').getBoundingClientRect().width) }));

    for (const [W, H] of [[1920, 1080], [1280, 800]]) {
      console.log(`\n── ${W}×${H} ──`);
      const { ctx, page, fr } = await open(W, H);
      const f = await box(page, '.st-frame'), s = await box(page, '.st-side'), bar = await box(page, '.st-bar');
      ok(Math.abs(s.w - 340) < 1, `הפאנל ברוחב קבוע 340 (${s.w})`);
      ok(f.w + s.w >= W - 4, `מסגרת + פאנל = כל הרוחב (${f.w} + ${s.w} ≥ ${W - 4})`);
      ok(f.top >= bar.bottom - 1 && f.top - bar.bottom < 2 && f.bottom >= H - 2, `המסגרת מתחת לסרגל ועד תחתית המסך (${f.top}–${f.bottom} מתוך ${H})`);
      const i = await inner(fr);
      ok(i.w >= i.iw - 2, `הרכיב ממלא את המסגרת — אין ריק (${i.w} מתוך ${i.iw})`);
      await page.screenshot({ path: path.join(SHOTS, `studio-${W}.png`) });
      await page.click('.st-device input[value=phone]');
      await sleep(300);
      const p = await box(page, '.st-frame'), c = await box(page, '.st-canvas');
      ok(Math.abs(p.w - 390) < 1 && Math.abs((p.x + p.w / 2) - (c.x + c.w / 2)) < 2, `"טלפון": 390 ממורכז (${p.w})`);
      const pi = await inner(fr);
      ok(pi.w >= pi.iw - 2, `"טלפון": הרכיב ממלא את 390 (${pi.w} מתוך ${pi.iw})`);
      if (W === 1920) await page.screenshot({ path: path.join(SHOTS, 'studio-1920-phone.png') });
      await ctx.close();
    }

    console.log('\n── טלפון 390×844 ──');
    const { ctx, page, fr } = await open(390, 844);
    const f = await box(page, '.st-frame'), s = await box(page, '.st-side');
    ok(s.w < 1 || s.top >= 844, `בטלפון הפאנל אינו בצד (${s.w}×, top ${s.top})`);
    ok(f.w >= 388, `המסגרת לכל הרוחב (${f.w})`);
    ok(f.bottom >= 842, `המסגרת עד תחתית המסך (${f.bottom})`);
    const i = await inner(fr);
    ok(i.w >= i.iw - 2, `הרכיב ממלא את המסגרת (${i.w} מתוך ${i.iw})`);
    await page.screenshot({ path: path.join(SHOTS, 'studio-390.png') });
    await ctx.close();
  } catch (e) { failed++; console.log('❌ חריגה: ' + (e.stack || e)); }
  finally { if (browser) await browser.close(); php.kill(); fs.rmSync(data, { recursive: true, force: true }); }
  console.log(`\n${ran - failed}/${ran} עברו`);
  process.exit(failed ? 1 : 0);
})();
