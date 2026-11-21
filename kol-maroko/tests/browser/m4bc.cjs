/**
 * פרוסה M4-BC — העמוד כחלון בתוך האתר, בדפדפן (Chromium, Playwright):   node kol-maroko/tests/browser/m4bc.cjs
 *
 * מפרסם חבילה עם js/kol-data.js **של הזרע** (1.3.0) לאתר המדומה tests/plugin/wp.php (התוסף האמיתי + ערכת נושא מדומה
 * עם SITE-HEADER / SITE-FOOTER), קובע את האופן בפעולה החתומה `mount`, ובודק את הגשר משני הצדדים:
 *   framed — גובה החלון = גובה התוכן (גדל וקטן, בלי פס גלילה כפול, גם בטלפון), הכותרת, הנתיב (מעבר דף בתוך החלון ←
 *            שורת הכתובת), כתובת עמוקה, תקרת 50,000;
 *   bar    — החלון ממלא את המסך מתחת לכותרת וגולל בתוכו;
 *   full   — kol-data.js אינו שולח דבר;
 *   H16 / H17 — הודעות מזויפות (מהחלון, מהמעטפת עצמה) נזרקות, ובקרה חיובית: הודעה תקינה כן עוברת.
 * **לא כאן:** Firefox ו-Safari, ערכת נושא אמיתית — מטלה באתר.
 */
'use strict';
const { chromium, devices } = require('playwright');
const { spawn } = require('child_process');
const crypto = require('crypto');
const fs = require('fs'), os = require('os'), path = require('path'), net = require('net');

const ROOT = path.resolve(__dirname, '..', '..');
let failed = 0, ran = 0;
const ok = (cond, what, extra) => { ran++; if (cond) console.log('✅ ' + what); else { failed++; console.log('❌ ' + what + (extra !== undefined ? ' · ' + extra : '')); } return !!cond; };
const freePort = () => new Promise(r => { const s = net.createServer().listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => r(p)); }); });
const sleep = ms => new Promise(r => setTimeout(r, ms));
const until = async (fn, ms = 8000) => { const t = Date.now(); for (;;) { try { const v = await fn(); if (v) return v; } catch { /* עוד לא */ } if (Date.now() - t > ms) return null; await sleep(80); } };
const sha = b => crypto.createHash('sha256').update(b).digest('hex');
const MIME = { html: 'text/html', css: 'text/css', js: 'text/javascript' };

const KD = fs.readFileSync(path.join(ROOT, 'assets', 'project-seed', 'kol-data.js'));
const page = (title, body, kd) => Buffer.from(`<!doctype html><html lang="he" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">`
  + `<title>${title}</title><meta name="description" content="תיאור ${title}"><link rel="stylesheet" href="${kd.replace('js/kol-data.js', 'style.css')}"></head>`
  + `<body>${body}<script src="${kd}"></script></body></html>`);
const FILES = {
  'index.html': page('בית', '<h1>בית</h1><div id="grow" style="height:400px;background:#eef">גובה</div><p><a id="to-about" href="about/">אודות</a> · <a id="to-he" href="מילים/">מילים</a></p>', 'js/kol-data.js'),
  'about/index.html': page('אודות', '<h1>אודות</h1><p>ABOUT-PAGE</p><a id="home" href="../">בית</a>', '../js/kol-data.js'),
  'מילים/index.html': page('מילים', '<h1>מילים</h1><p>HE-PAGE</p>', '../js/kol-data.js'),
  'style.css': Buffer.from('body{margin:0;font-family:sans-serif}h1{margin:0;padding:16px}'),
  'js/kol-data.js': KD,
};

(async () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'kol-m4-'));
  const { publicKey, privateKey } = crypto.generateKeyPairSync('ed25519');
  const pub = publicKey.export({ format: 'der', type: 'spki' }).subarray(-32).toString('base64');
  const optsFile = path.join(dir, 'options.json');
  fs.writeFileSync(optsFile, JSON.stringify({ kol_maroko_keys: 'k-m4:' + pub, kol_maroko_page_id: 7 }));
  const setOpt = (k, v) => { const o = JSON.parse(fs.readFileSync(optsFile, 'utf8')); if (v === null) delete o[k]; else o[k] = v; fs.writeFileSync(optsFile + '.n', JSON.stringify(o)); fs.renameSync(optsFile + '.n', optsFile); };
  fs.mkdirSync(path.join(dir, 'uploads'));
  const port = await freePort(), base = `http://127.0.0.1:${port}`;
  const php = spawn('php', ['-S', `127.0.0.1:${port}`, path.join(ROOT, 'tests', 'plugin', 'wp.php')], { cwd: ROOT, env: { ...process.env, KOL_TEST_WP: dir, PHP_CLI_SERVER_WORKERS: '6' }, stdio: 'ignore' });
  let browser;
  const signed = async (action, payload) => {
    const body = JSON.stringify({ ...payload, action, site: '127.0.0.1', page: 'lexicon', time: Math.floor(Date.now() / 1000), nonce: crypto.randomBytes(16).toString('hex') });
    const sig = crypto.sign(null, Buffer.from(body), privateKey).toString('base64');
    const r = await fetch(`${base}/wp-json/kol-maroko/v1/${action}`, { method: 'POST', body, headers: { 'Content-Type': 'application/json', 'X-Kol-Kid': 'k-m4', 'X-Kol-Signature': sig } });
    return { status: r.status, json: await r.json().catch(() => null) };
  };
  const frameOf = p => until(() => p.frames().find(f => f !== p.mainFrame() && /\/lexicon\//.test(f.url())), 8000);
  const fh = p => p.evaluate(() => document.getElementById('kol-maroko-frame').getBoundingClientRect().height);

  try {
    for (let i = 0; i < 100; i++) { try { await fetch(base + '/'); break; } catch { await sleep(50); } }
    for (const [p, b] of Object.entries(FILES)) {
      const r = await signed('asset_put', { sha256: sha(b), size: b.length, type: MIME[p.split('.').pop()], part: 0, parts: 1, data: b.toString('base64') });
      if (r.status !== 200) ok(false, 'asset_put ' + p, JSON.stringify(r.json));
    }
    const man = { site: '127.0.0.1', page: 'lexicon', version: 1, time: Math.floor(Date.now() / 1000), nonce: crypto.randomBytes(16).toString('hex'), entry: 'index.html',
      published_by: 'm4bc', files: Object.fromEntries(Object.entries(FILES).map(([p, b]) => [p, { sha256: sha(b), size: b.length, type: MIME[p.split('.').pop()] }])) };
    const bytes = JSON.stringify(man);
    const pr = await signed('manifest', { manifest: bytes, manifest_sig: crypto.sign(null, Buffer.from(bytes), privateKey).toString('base64'), kid: 'k-m4' });
    ok(pr.status === 200, 'החבילה באוויר', JSON.stringify(pr.json));
    setOpt('kol_maroko_v2_on', true);
    ok((await signed('mount', { mount: 'framed' })).json.mount === 'framed', 'mount=framed');

    browser = await chromium.launch();
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 800 } });
    const errors = [];
    ctx.on('weberror', e => errors.push(String(e.error())));
    const p = await ctx.newPage();

    // ════ framed: גובה ════
    console.log('── framed ──');
    await p.goto(base + '/lexicon/');
    let f = await frameOf(p);
    ok(f && await until(() => f.evaluate(() => window.KolData && window.KolData.version >= '1.3.0')), 'kol-data.js 1.3.0 רץ בתוך החלון');
    ok(await p.isVisible('#site-header') && await p.isVisible('#site-footer'), 'הכותרת והתחתית של האתר — סביב החלון');
    const inner = () => f.evaluate(() => ({ h: Math.ceil(document.documentElement.getBoundingClientRect().height), sh: document.documentElement.scrollHeight, ih: innerHeight, sw: document.documentElement.scrollWidth, iw: innerWidth }));
    let fit = await until(async () => { const i = await inner(); const h = await fh(p); return Math.abs(h - i.h) <= 1 && h < 800 ? { i, h } : null; });
    ok(fit, 'גובה החלון = גובה התוכן (קטן מהמסך — לא 100vh)', JSON.stringify(fit || { i: await inner(), h: await fh(p) }));
    const noDouble = async () => { const i = await inner(); return i.sh <= i.ih + 1 && i.sw <= i.iw; };
    ok(await noDouble(), 'בלי פס גלילה בתוך החלון (רק של הדף)', JSON.stringify(await inner()));
    await f.evaluate(() => { document.getElementById('grow').style.height = '3000px'; });
    ok(await until(async () => (await fh(p)) >= 3000), 'התוכן גדל ← החלון גדל', await fh(p));
    ok(await until(noDouble), 'עדיין בלי פס גלילה כפול', JSON.stringify(await inner()));
    ok(await p.evaluate(() => document.documentElement.scrollHeight > 3000), 'הדף כולו גולל (פס אחד)');
    await f.evaluate(() => { document.getElementById('grow').style.height = '120px'; });
    ok(await until(async () => (await fh(p)) < 600), 'התוכן קטן ← החלון קטן', await fh(p));
    await f.evaluate(() => { document.getElementById('grow').style.height = '70000px'; });
    ok(await until(async () => (await fh(p)) === 50000), 'תקרה: 50,000px', await fh(p));
    await f.evaluate(() => { document.getElementById('grow').style.height = '400px'; });
    await until(async () => (await fh(p)) < 800);

    // ════ כותרת ════
    ok(await p.title() === 'בית', 'הכותרת של הלשונית — מה-<title> של הדף (בשרת)', await p.title());
    ok(await p.$eval('meta[name=description]', m => m.content) === 'תיאור בית', 'התיאור — מועתק כטקסט');
    await f.evaluate(() => { document.title = 'כותרת חדשה <b>"x"</b>'; });
    ok(await until(async () => (await p.title()) === 'כותרת חדשה <b>"x"</b>'), 'document.title בעמוד ← הכותרת של הלשונית (טקסט)', await p.title());

    // ════ נתיב: מעבר דף בתוך החלון ════
    await f.click('#to-about');
    ok(await until(() => p.url() === base + '/lexicon/about/'), 'מעבר לדף אחר בתוך החלון ← שורת הכתובת /lexicon/about/', p.url());
    f = await frameOf(p);
    ok(await until(async () => (await f.content()).includes('ABOUT-PAGE')), 'בחלון — הדף עצמו');
    ok(!(await f.content()).includes('SITE-HEADER') && !(await f.content()).includes('kol-maroko-frame'), 'בלי מעטפת בתוך מעטפת (Sec-Fetch-Dest: iframe)');
    ok(await until(async () => (await p.title()) === 'אודות'), 'הכותרת — של הדף החדש', await p.title());
    ok(await p.$$eval('iframe', l => l.length) === 1, 'iframe אחד בדף');
    await f.click('#home');
    ok(await until(() => p.url() === base + '/lexicon/'), 'חזרה לדף הבית ← /lexicon/', p.url());
    f = await frameOf(p);
    await f.click('#to-he');
    ok(await until(() => p.url() === base + '/lexicon/' + encodeURIComponent('מילים') + '/'), 'נתיב בעברית — מקודד', p.url());

    // ════ כתובת עמוקה ════
    await p.goto(base + '/lexicon/about/');
    ok(await p.$eval('#kol-maroko-frame', e => e.getAttribute('src')) === '/lexicon/about/index.html', 'כתובת עמוקה ← src של about/index.html');
    ok(await p.title() === 'אודות', 'כתובת עמוקה — הכותרת של הדף');
    await p.goto(base + '/lexicon/');
    f = await frameOf(p);
    await until(() => f.evaluate(() => !!window.KolData));

    // ════ H16 / H17: הודעות מזויפות נזרקות; בקרה חיובית — הודעה תקינה עוברת ════
    console.log('── H16 / H17 ──');
    await until(async () => (await p.title()) === 'בית');
    // הגובה הלגיטימי (מ-kol-data.js) מתייצב קודם — כדי שהשוואה אחרי ההודעות המזויפות תמדוד רק אותן
    const settled = async () => { const c = await f.evaluate(() => Math.ceil(document.documentElement.getBoundingClientRect().height)); return Math.abs((await fh(p)) - c) <= 1 ? c : null; };
    await until(settled);
    await f.evaluate(() => {
      for (const m of [{ kol: 'path', p: '../wp-admin/' }, { kol: 'path', p: '/wp-admin/' }, { kol: 'path', p: 'nope/index.html' }, { kol: 'height', h: 1e9 }, { kol: 'height', h: 60000 },
        { kol: 'height', h: '333' }, { kol: 'title', t: 'x'.repeat(201) }, { kol: 'title', t: 'PWNED', more: 1 }, { kol: 'other' }, 'title']) parent.postMessage(m, '*');
    });
    await p.evaluate(() => { postMessage({ kol: 'title', t: 'SELF' }, '*'); postMessage({ kol: 'path', p: 'about/index.html' }, '*'); });
    await sleep(600);
    ok(p.url() === base + '/lexicon/', 'H16: נתיב החוצה / שאינו בחבילה — נזרק', p.url());
    ok(await until(settled, 2000) && (await fh(p)) < 2000, 'H16: גובה 1e9 / 60,000 / מחרוזת — נזרק (החלון בגובה התוכן)', await fh(p));
    ok(await p.title() === 'בית', 'H16: כותרת ארוכה / צורה לא מדויקת / הודעה מהמעטפת עצמה — נזרקו', await p.title());
    await f.evaluate(() => parent.postMessage({ kol: 'title', t: 'בקרה' }, '*'));
    ok(await until(async () => (await p.title()) === 'בקרה'), 'בקרה חיובית: הודעה תקינה מהחלון — עוברת (כלומר הבדיקה מסוגלת להיכשל)');
    const h17 = await f.evaluate(() => {
      const r = {};
      try { r.href = top.location.href; } catch (e) { r.href = e.name; }
      try { r.doc = !!parent.document; } catch (e) { r.doc = e.name; }
      try { r.cookie = document.cookie; } catch (e) { r.cookie = e.name; }
      try { top.location = '/__pwned/'; r.nav = 'set'; } catch (e) { r.nav = e.name; }
      return r;
    });
    await sleep(500);
    ok(h17.href === 'SecurityError' && h17.doc === 'SecurityError' && h17.cookie === 'SecurityError', 'H17: top.location.href / parent.document / document.cookie — חריגה', JSON.stringify(h17));
    ok(p.url() === base + '/lexicon/', 'H17: top.location = … — העמוד שמסביב לא נווט', p.url());

    // ════ טלפון ════
    console.log('── טלפון ──');
    const phone = await browser.newContext({ ...devices['Pixel 5'] });
    const pp = await phone.newPage();
    await pp.goto(base + '/lexicon/');
    const pf = await frameOf(pp);
    await until(() => pf.evaluate(() => !!window.KolData));
    const pi = async () => pf.evaluate(() => ({ h: Math.ceil(document.documentElement.getBoundingClientRect().height), sh: document.documentElement.scrollHeight, ih: innerHeight, sw: document.documentElement.scrollWidth, iw: innerWidth }));
    ok(await until(async () => { const i = await pi(); return Math.abs((await fh(pp)) - i.h) <= 1 && i.sh <= i.ih + 1; }), 'טלפון: גובה החלון = התוכן, בלי פס גלילה כפול', JSON.stringify(await pi()));
    ok(await pp.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'טלפון: בלי גלילה לרוחב');
    await pf.evaluate(() => { document.getElementById('grow').style.height = '2500px'; });
    ok(await until(async () => (await fh(pp)) >= 2500 && (await pi()).sh <= (await pi()).ih + 1), 'טלפון: תוכן ארוך ← החלון גדל, הגלילה אחת', JSON.stringify(await pi()));
    await phone.close();

    // ════ bar ════
    console.log('── bar ──');
    ok((await signed('mount', { mount: 'bar' })).json.mount === 'bar', 'mount=bar — מיידי, בלי פרסום');
    await p.goto(base + '/lexicon/');
    f = await frameOf(p);
    await until(() => f.evaluate(() => !!window.KolData));
    await f.evaluate(() => { document.getElementById('grow').style.height = '3000px'; });
    await sleep(400);
    const bar = await p.evaluate(() => { const fr = document.getElementById('kol-maroko-frame'); const r = fr.getBoundingClientRect(); return { top: r.top, h: r.height, ih: innerHeight, sh: document.documentElement.scrollHeight }; });
    ok(Math.abs(bar.top - 120) <= 1 && Math.abs(bar.top + bar.h - bar.ih) <= 2, 'bar: החלון ממלא את המסך מתחת לכותרת', JSON.stringify(bar));
    ok(bar.sh <= bar.ih + 2, 'bar: הדף עצמו לא גולל', JSON.stringify(bar));
    ok(await f.evaluate(() => document.documentElement.scrollHeight > innerHeight), 'bar: הגלילה — בתוך החלון');
    ok(!(await p.$('#site-footer')), 'bar: בלי התחתית של האתר');
    await p.setViewportSize({ width: 1000, height: 600 });
    ok(await until(async () => Math.abs((await fh(p)) - (600 - 120)) <= 2), 'bar: שינוי גודל המסך ← החלון מתאים', await fh(p));
    await f.click('#to-about');
    ok(await until(() => p.url() === base + '/lexicon/about/'), 'bar: נתיב — גם כאן');

    // ════ full: kol-data.js אינו שולח דבר ════
    console.log('── full ──');
    await signed('mount', { mount: 'full' });
    const p2 = await ctx.newPage();
    await p2.addInitScript(() => { window.__sent = 0; const o = window.postMessage.bind(window); window.postMessage = (...a) => { window.__sent++; return o(...a); }; });
    await p2.goto(base + '/lexicon/');
    ok(await until(() => p2.evaluate(() => window.KolData && window.KolData.version >= '1.3.0')), 'full: העמוד הוא המסמך העליון');
    await sleep(300);
    ok(await p2.evaluate(() => window.__sent) === 0, 'full: kol-data.js לא שלח אף הודעה');
    ok(!(await p2.content()).includes('SITE-HEADER'), 'full: בלי הכותרת של האתר (M-A)');
    ok(errors.length === 0, 'בלי שגיאות JS', errors.join(' | '));
  } catch (e) {
    ok(false, 'הריצה עצמה', e.stack);
  } finally {
    if (browser) await browser.close();
    php.kill();
    fs.rmSync(dir, { recursive: true, force: true });
  }
  console.log(`\n${failed ? `נכשלו ${failed} מתוך ${ran}` : `כל ${ran} הבדיקות עברו`}`);
  process.exit(failed ? 1 : 0);
})();
