/**
 * פרוסה M2 (+ M4-BC: H16–H17 בתוך המעטפת) — ערכת ההתקפה מול התוסף, בדפדפן (Chromium, Playwright):   node kol-maroko/tests/browser/m2.cjs
 *
 * מפרסם את tests/attack-kit/ לאתר המדומה (tests/plugin/wp.php — טוען את קובץ התוסף האמיתי), בדיוק כמו הכלי:
 * asset_has ← asset_put ← manifest, חתום ב-Ed25519. מדליק את "העמוד החדש", מתחבר "כמנהל" (עוגייה HttpOnly, בלי
 * SameSite — כמו וורדפרס), ופותח את העמוד: כמסמך עליון, בתוך מסגרת של עמוד של האתר, ובחלון שנפתח מעמוד של האתר.
 * לכל בדיקה שמסתמכת על העוגייה — ביקורת חיובית: אותו fetch מעמוד של האתר כן מחובר (כלומר הבדיקה מסוגלת להיכשל).
 * מבחוץ: H5, H6, H7, H9, H10, H11, H14, ומה שהשרת קיבל ב-H2/H3/H15.
 * **לא כאן:** H12, H13 (דורשות וורדפרס אמיתי), Firefox ו-Safari, Apache/LiteSpeed (ה-.htaccess) — מטלות באתר.
 */
'use strict';
const { chromium } = require('playwright');
const { spawn } = require('child_process');
const crypto = require('crypto');
const fs = require('fs'), os = require('os'), path = require('path'), net = require('net');

const ROOT = path.resolve(__dirname, '..', '..');
const KIT = path.join(ROOT, 'tests', 'attack-kit');
const CSP = 'sandbox allow-scripts allow-forms allow-popups allow-popups-to-escape-sandbox allow-modals allow-downloads allow-presentation';
let failed = 0, ran = 0;
const ok = (cond, what, extra) => { ran++; if (cond) console.log('✅ ' + what); else { failed++; console.log('❌ ' + what + (extra ? ' · ' + extra : '')); } return !!cond; };
const freePort = () => new Promise(r => { const s = net.createServer().listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => r(p)); }); });
const sleep = ms => new Promise(r => setTimeout(r, ms));
const until = async (fn, ms = 8000) => { const t = Date.now(); for (;;) { try { const v = await fn(); if (v) return v; } catch { /* עוד לא */ } if (Date.now() - t > ms) return null; await sleep(100); } };
const sha = b => crypto.createHash('sha256').update(b).digest('hex');
/** בקשה גולמית — הנתיב יוצא בדיוק כמו שהוא (fetch מנרמל ../ לפני השליחה, וכך גם דפדפן). */
const raw = (base, p) => new Promise((res, rej) => {
  const u = new URL(base);
  const q = require('http').request({ host: u.hostname, port: u.port, path: p, method: 'GET' }, r => { const b = []; r.on('data', d => b.push(d)); r.on('end', () => res({ status: r.statusCode, headers: { get: k => { const v = r.headers[k]; return v === undefined ? null : String(v); } }, text: Buffer.concat(b).toString() })); });
  q.on('error', rej);
  q.end();
});
const MIME = { html: 'text/html', css: 'text/css', js: 'text/javascript', svg: 'image/svg+xml', jpg: 'image/jpeg' };

(async () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'kol-m2-'));
  const { publicKey, privateKey } = crypto.generateKeyPairSync('ed25519');
  const pub = publicKey.export({ format: 'der', type: 'spki' }).subarray(-32).toString('base64');
  const optsFile = path.join(dir, 'options.json');
  // האתר המדומה כותב את הקובץ אטומית (rename); כאן — אותו דבר, וקריאה חוזרת למקרה נדיר של קובץ בדרך
  const opts = () => { for (let i = 0; ; i++) { try { return JSON.parse(fs.readFileSync(optsFile, 'utf8')); } catch (e) { if (i > 50) throw e; } } };
  // השרת כותב לאותו קובץ במקביל (test_hits, nonce, מונה) — כתיבה שלו יכולה לדרוס את שלנו. לכן: כותבים, ובודקים שנשאר
  const setOpt1 = (k, v) => { const o = opts(); if (v === null) delete o[k]; else o[k] = v; fs.writeFileSync(optsFile + '.n', JSON.stringify(o)); fs.renameSync(optsFile + '.n', optsFile); };
  const setOpt = (k, v) => {
    for (let i = 0; i < 40; i++) {
      setOpt1(k, v);
      const t = Date.now(); while (Date.now() - t < 30) { /* רגע לכתיבה מתחרה */ }
      if (JSON.stringify(opts()[k] ?? null) === JSON.stringify(v)) return;
    }
    throw new Error('setOpt ' + k + ' לא נשמר');
  };
  fs.writeFileSync(optsFile, JSON.stringify({ kol_maroko_keys: 'k-m2:' + pub, kol_maroko_page_id: 7 }));
  fs.mkdirSync(path.join(dir, 'uploads'));
  const port = await freePort(), base = `http://127.0.0.1:${port}`;
  const php = spawn('php', ['-S', `127.0.0.1:${port}`, path.join(ROOT, 'tests', 'plugin', 'wp.php')], { cwd: ROOT, env: { ...process.env, KOL_TEST_WP: dir, PHP_CLI_SERVER_WORKERS: '6' }, stdio: 'ignore' });
  let browser;

  const signed = async (action, payload) => {
    const body = JSON.stringify({ ...payload, action, site: '127.0.0.1', page: 'lexicon', time: Math.floor(Date.now() / 1000), nonce: crypto.randomBytes(16).toString('hex') });
    const sig = crypto.sign(null, Buffer.from(body), privateKey).toString('base64');
    const r = await fetch(`${base}/wp-json/kol-maroko/v1/${action}`, { method: 'POST', body, headers: { 'Content-Type': 'application/json', 'X-Kol-Kid': 'k-m2', 'X-Kol-Signature': sig } });
    return { status: r.status, json: await r.json().catch(() => null), body, sig };
  };

  try {
    for (let i = 0; i < 100; i++) { try { await fetch(base + '/'); break; } catch { await sleep(50); } }

    // ════ א. פרסום הערכה — כמו הכלי ════
    console.log('── א. פרסום הערכה ──');
    const files = {};
    for (const f of fs.readdirSync(KIT)) if (f !== 'README.md') files[f] = fs.readFileSync(path.join(KIT, f));
    ok(['x.php', '.htaccess', '.user.ini', 'x.phtml', 'x.php.jpg', 'x.svg', 'index.html', 'probe.html', '404.html'].every(f => files[f]), 'הערכה: כל קבצי H6 ועמודי הבדיקה בחבילה');
    const ping = await signed('ping', {});
    ok(ping.json && ping.json.protocol === 2, 'ping ← protocol 2', JSON.stringify(ping.json));
    const has = await signed('asset_has', { sha256: Object.values(files).map(sha) });
    ok(has.status === 200 && has.json.have.length === 0, 'asset_has ← כלום');
    for (const [p, b] of Object.entries(files)) {
      const ext = p.split('.').pop();
      const r = await signed('asset_put', { sha256: sha(b), size: b.length, type: MIME[ext] || 'application/octet-stream', part: 0, parts: 1, data: b.toString('base64') });
      if (r.status !== 200) ok(false, 'asset_put ' + p, r.status + ' ' + JSON.stringify(r.json));
    }
    const mk = (version, extra = {}) => ({ site: '127.0.0.1', page: 'lexicon', version, time: Math.floor(Date.now() / 1000), nonce: crypto.randomBytes(16).toString('hex'),
      entry: 'index.html', published_by: 'm2.cjs', files: Object.fromEntries(Object.entries(files).map(([p, b]) => [p, { sha256: sha(b), size: b.length, type: MIME[p.split('.').pop()] || 'application/octet-stream' }])), ...extra });
    const publish = (man, sigOver) => {
      const bytes = JSON.stringify(man);
      return signed('manifest', { manifest: bytes, manifest_sig: sigOver || crypto.sign(null, Buffer.from(bytes), privateKey).toString('base64'), kid: 'k-m2' });
    };
    const pub1 = await publish(mk(1));
    ok(pub1.status === 200 && pub1.json.version === 1, 'manifest ← גרסה 1 באוויר', JSON.stringify(pub1.json));

    // H9 — בקשה חוזרת, גרסה יורדת, חתימה שגויה, site אחר, זמן ישן
    const again = await fetch(`${base}/wp-json/kol-maroko/v1/manifest`, { method: 'POST', body: pub1.body, headers: { 'Content-Type': 'application/json', 'X-Kol-Kid': 'k-m2', 'X-Kol-Signature': pub1.sig } }).then(r => r.json());
    ok(again.code === 'replay', 'H9: אותה בקשה פעמיים ← replay');
    ok((await publish(mk(1))).json.code === 'old_version', 'H9: גרסה שאינה עולה ← old_version');
    ok((await publish(mk(2), crypto.sign(null, Buffer.from('other'), privateKey).toString('base64'))).json.code === 'bad_signature', 'H9: חתימה שגויה ← bad_signature');
    ok((await publish(mk(2, { site: 'evil.example' }))).json.code === 'wrong_site', 'H9: site אחר ← wrong_site');
    ok((await publish(mk(2, { time: 1 }))).json.code === 'stale', 'H9: זמן ישן ← stale');
    // H8 — מעל מכסה
    setOpt('kol_maroko_v2_quota', { files: 3 });
    const q = await publish(mk(2));
    ok(q.status === 413 && q.json.code === 'quota' && q.json.quota === 'files', 'H8: מעל מכסת הקבצים ← quota (413)');
    setOpt('kol_maroko_v2_quota', null);
    ok(opts().kol_maroko_v2_live === 1, 'H8/H9: הגרסה שבאוויר לא זזה');

    // H14 / G7 — כבוי: העמוד המקורי
    let r = await fetch(base + '/lexicon/').then(r => r.text());
    ok(r.includes('WP-SITE-PAGE'), 'H14: המפסק כבוי ← העמוד המקורי של האתר');
    setOpt('kol_maroko_v2_on', true);
    r = await fetch(base + '/lexicon/').then(r => r.text());
    ok(r.includes('ערכת ההתקפה') && !r.includes('WP-SITE-PAGE'), 'H14: דולק ← הערכה');

    // ════ ב. מבחוץ: H6, H7, H10 ════
    console.log('\n── ב. מבחוץ ──');
    const want = { 'index.html': 'text/html; charset=utf-8', 'kit.js': 'text/javascript; charset=utf-8', 'kit.css': 'text/css; charset=utf-8', 'x.svg': 'image/svg+xml; charset=utf-8',
      'x.php': 'application/octet-stream', 'x.phtml': 'application/octet-stream', '.htaccess': 'application/octet-stream', '.user.ini': 'application/octet-stream', 'x.php.jpg': 'image/jpeg',
      'probe.html': 'text/html; charset=utf-8', '404.html': 'text/html; charset=utf-8' };
    const iso = (res, label) => {
      const h = res.headers;
      const bad = [];
      if (h.get('content-security-policy') !== CSP) bad.push('CSP');
      if (h.get('x-content-type-options') !== 'nosniff') bad.push('nosniff');
      if (h.get('access-control-allow-origin') !== '*') bad.push('ACAO');
      if (h.get('cross-origin-resource-policy') !== 'cross-origin') bad.push('CORP');
      if (h.get('referrer-policy') !== 'strict-origin-when-cross-origin') bad.push('Referrer-Policy');
      if (h.get('cross-origin-opener-policy') !== 'same-origin') bad.push('COOP');
      if (h.get('set-cookie')) bad.push('Set-Cookie');
      return ok(!bad.length, 'H10: ' + label + ' — sandbox בלי allow-same-origin, nosniff, CORS, CORP, בלי Set-Cookie', bad.join(','));
    };
    for (const [p, type] of Object.entries(want)) {
      const res = await fetch(base + '/lexicon/' + p, { headers: { Cookie: 'wordpress_logged_in_test=1' } });
      const body = Buffer.from(await res.arrayBuffer());
      iso(res, p);
      ok(res.status === 200 && res.headers.get('content-type') === type, `H10: ${p} ← ${type}`, res.status + ' ' + res.headers.get('content-type'));
      if (/php|htaccess|user\.ini/.test(p)) ok(body.equals(files[p]) && !body.toString().includes('RAN-42'), `H6: ${p} — טקסט דומם, לא רץ`);
    }
    for (const p of ['../../wp-config.php', '..%2f..%2fwp-config.php', '%2e%2e/%2e%2e/wp-config.php', 'nope.html', 'x/../index.html', '/x.php', 'x.php%00.html', 'a%5c..%5cwp-config.php']) {
      const res = await raw(base, '/lexicon/' + p);
      const t = res.text;
      ok(res.status === 404 && t.includes('KOLKIT-404') && !t.includes('WP-SITE-PAGE'), 'H7: ' + p + ' ← 404 (של החבילה)', res.status);
      iso(res, '404 ' + p);
    }
    iso(await fetch(base + '/lexicon/index.html', { headers: { 'If-None-Match': '"' + sha(files['index.html']) + '"' } }), '304');
    iso(await fetch(base + '/lexicon/index.html', { method: 'HEAD' }), 'HEAD');

    // ════ ג. בדפדפן ════
    console.log('\n── ג. בדפדפן ──');
    browser = await chromium.launch();
    const ctx = await browser.newContext();
    const page = await ctx.newPage();
    const lines = [];
    ctx.on('console', m => lines.push(m.text()));
    await page.goto(base + '/__login');
    ok((await ctx.cookies()).some(c => c.name === 'wordpress_logged_in_test' && c.httpOnly), 'מחובר "כמנהל" (עוגייה HttpOnly)');

    // ביקורת חיובית: אותו fetch מעמוד של האתר — כן מחובר
    await page.goto(base + '/__site/');
    const ctl = await until(() => lines.find(l => l.startsWith('SITECTL ')), 8000);
    const c = ctl ? JSON.parse(ctl.slice(8)) : {};
    ok(c.me === true && c.ajax === true, 'ביקורת חיובית: מעמוד של האתר, users/me ו-admin-ajax כן מחזירים "מחובר"', ctl);
    setOpt('test_hits', null);

    // 1. מסמך עליון
    lines.length = 0;
    await page.goto(base + '/lexicon/');
    const top = await until(() => lines.find(l => l.startsWith('KOLKIT2 ')), 15000);
    ok(top, 'הערכה רצה ודיווחה (מסמך עליון)');
    const T = top ? JSON.parse(top.slice(8)) : { checks: [] };
    for (const k of T.checks) ok(k.ok, `בתוך העמוד · ${k.id} · ${k.name}`, k.text);
    ok(T.checks.length >= 10 && !T.framed, 'מסמך עליון, ' + T.checks.length + ' בדיקות');
    ok(await page.$$eval('#kit-table tr.ok', t => t.length) === T.checks.filter(k => k.ok).length && await page.textContent('#kit-sum').then(s => s.startsWith('✓')), 'הטבלה בעמוד: ✓ בכל שורה');
    const hits = () => (opts().test_hits || []).filter(h => h.origin === 'null' || h.action === 'kolkit_h15');
    await until(() => hits().some(h => h.action === 'kolkit_h15'), 4000);
    const H = hits();
    ok(H.some(h => h.path.endsWith('users/me')) && H.some(h => h.action === 'heartbeat') && H.some(h => h.action === 'kolkit_h15'), 'H2/H3/H15: הבקשות מהעמוד הגיעו לשרת', JSON.stringify(H));
    ok(H.length > 0 && H.every(h => h.cookie === false), 'H2/H3/H15: אף אחת מהן לא נשאה את העוגייה של המחובר (מבחינת השרת)', JSON.stringify(H));
    ok(H.filter(h => h.origin !== null).every(h => h.origin === 'null'), 'H2/H3: נשלחו כמקור זר (Origin: null)');

    // 2. בתוך מסגרת של עמוד של האתר — parent.document, top.location, target=_top
    lines.length = 0;
    await page.goto(base + '/__site/frame');
    const fr = await until(() => lines.find(l => l.startsWith('KOLKIT2 ')), 15000);
    const F = fr ? JSON.parse(fr.slice(8)) : { checks: [] };
    ok(fr && F.framed, 'הערכה רצה בתוך מסגרת');
    for (const k of F.checks.filter(k => k.id === 'H4' || k.id === 'H15' || k.id === 'H1')) ok(k.ok, `במסגרת · ${k.id} · ${k.name}`, k.text);
    await sleep(800);
    ok(page.url() === base + '/__site/frame' && !page.url().includes('pwned'), 'H4/H15: העמוד של האתר שמסביב לא נווט (top.location, target=_top)', page.url());
    ok(await page.isVisible('#site-marker'), 'H5: העמוד של האתר שמסביב — עדיין נראה');

    // 3. חלון שנפתח מעמוד של האתר — window.opener
    lines.length = 0;
    await page.goto(base + '/__site/');
    const [pop] = await Promise.all([ctx.waitForEvent('page'), page.evaluate(() => { window.open('/lexicon/', '_blank'); })]);
    const op = await until(() => lines.find(l => l.startsWith('KOLKIT2 ')), 15000);
    const O = op ? JSON.parse(op.slice(8)) : { checks: [] };
    ok(op, 'הערכה רצה בחלון שנפתח מעמוד של האתר');
    ok(op && O.opener === false, 'H4: window.opener מנותק (Cross-Origin-Opener-Policy) — העמוד לא יכול לנווט את הלשונית של האתר', op);
    for (const k of O.checks.filter(k => k.name.includes('opener'))) ok(k.ok, `opener · ${k.name}`, k.text);
    await sleep(500);
    ok(page.url() === base + '/__site/' && await page.isVisible('#site-marker'), 'H4: החלון שפתח לא נווט ולא השתנה (H5)');
    await pop.close();

    // H11 — קובץ מהחבילה בלשונית משלו; H6 — x.svg ישירות
    lines.length = 0;
    const p2 = await ctx.newPage();
    await p2.goto(base + '/lexicon/probe.html');
    const pr = await until(() => lines.find(l => l.startsWith('KOLKIT-PROBE ')), 8000);
    const P = pr ? JSON.parse(pr.slice(13)) : {};
    ok(P.origin === 'null' && P.cookie === 'throw' && P.storage === 'throw', 'H11: probe.html בלשונית משלו — מקור אטום, בלי עוגייה ואחסון', pr);
    await p2.goto(base + '/lexicon/x.svg');
    const sv = await until(() => lines.find(l => l.startsWith('KOLKIT-SVG ')), 8000);
    const S = sv ? JSON.parse(sv.slice(11)) : {};
    ok(S.origin === 'null' && S.cookie === 'throw', 'H6: x.svg עם סקריפט, בכתובת ישירה — רץ במקור אטום בלבד', sv);
    const dl = await Promise.all([p2.waitForEvent('download', { timeout: 5000 }).catch(() => null), p2.goto(base + '/lexicon/x.php').catch(() => null)]);
    ok(dl[0], 'H6: x.php בכתובת ישירה ← הורדה (לא דף, לא הרצה)');
    await p2.close();

    // H5 — שאר האתר ללא שינוי אחרי שהערכה הזריקה סגנון עוין
    const p3 = await ctx.newPage();
    await p3.goto(base + '/__site/other');
    ok(await p3.isVisible('#site-marker'), 'H5: עמוד אחר של האתר — נראה כרגיל');
    await p3.close();

    // H14 — כיבוי בזמן שהעמוד פתוח: טעינה מחדש ← המקורי; הדלקה ← הערכה
    setOpt('kol_maroko_v2_on', false);
    await page.goto(base + '/lexicon/');
    ok((await page.content()).includes('WP-SITE-PAGE'), 'H14: כיבוי ← העמוד המקורי מיד');
    setOpt('kol_maroko_v2_on', true);
    await page.goto(base + '/lexicon/');
    ok((await page.content()).includes('ערכת ההתקפה'), 'H14: הדלקה ← החדש חוזר');

    // ════ ד. M2b — כתובת הבדיקה: המפסק כבוי, העמוד נפתח באסימון, והקבצים שהוא טוען (ממקור אטום) מגיעים דרך העוגייה ════
    console.log('\n── ד. כתובת הבדיקה ──');
    setOpt('kol_maroko_v2_on', false);
    const tok = crypto.randomBytes(16).toString('hex');
    setOpt('kol_maroko_v2_preview_hash', crypto.createHash('sha256').update(tok).digest('hex'));
    const pv = await browser.newContext();
    const pvLines = [];
    pv.on('console', m => pvLines.push(m.text()));
    const pp = await pv.newPage();
    const loaded = [];
    pp.on('response', r => loaded.push([new URL(r.url()).pathname, r.status()]));
    await pp.goto(base + '/lexicon/?kol_preview=' + tok);
    const pvk = await until(() => pvLines.find(l => l.startsWith('KOLKIT2 ')), 15000);
    ok(pvk, 'בכתובת הבדיקה הערכה נטענה ורצה — kit.js הגיע דרך העוגייה', JSON.stringify(loaded));
    ok(loaded.some(([p, st]) => p === '/lexicon/kit.css' && st === 200), 'kit.css — 200 (עוגיית התוסף נשלחה גם מהמקור האטום)', JSON.stringify(loaded));
    const PV = pvk ? JSON.parse(pvk.slice(8)) : { checks: [] };
    ok(PV.checks.length && PV.checks.every(k => k.ok), 'כל בדיקות הערכה ✓ גם בכתובת הבדיקה', JSON.stringify(PV.checks.filter(k => !k.ok)));
    const ck = (await pv.cookies()).find(c => c.name === 'kol_preview');
    ok(ck && ck.httpOnly && ck.path === '/lexicon/' && ck.sameSite === 'None', 'העוגייה: HttpOnly, לתחום בלבד', JSON.stringify(ck));
    const site = await pv.newPage();
    await site.goto(base + '/lexicon/');
    ok((await site.content()).includes('ערכת ההתקפה'), 'אותו דפדפן, בלי פרמטר — העוגייה ממשיכה להציג את החדש');
    const other = await browser.newContext();
    const op2 = await other.newPage();
    await op2.goto(base + '/lexicon/');
    ok((await op2.content()).includes('WP-SITE-PAGE'), 'דפדפן אחר (בלי אסימון) — העמוד הישן');
    await pv.close();
    await other.close();

    // ════ ה. M4-BC — הערכה בתוך המעטפת (חלון בתוך האתר / פס האתר): H1–H15 מבפנים, H16–H17, והמעטפת מבחוץ ════
    console.log('\n── ה. בתוך המעטפת (M4-BC) ──');
    setOpt('kol_maroko_v2_on', true);
    setOpt('kol_maroko_v2_preview_hash', null);
    const KIT_TITLE = '🛡️ ערכת ההתקפה — קול מרוקו 2.0';
    for (const mode of ['framed', 'bar']) {
      const ms = await signed('mount', { mount: mode });
      ok(ms.status === 200 && ms.json.mount === mode, `mount=${mode} (חתום)`, JSON.stringify(ms.json));
      setOpt('test_hits', null);
      lines.length = 0;
      await page.goto(base + '/lexicon/');
      const w = await until(() => lines.find(l => l.startsWith('KOLKIT2 ')), 15000);
      const W = w ? JSON.parse(w.slice(8)) : { checks: [] };
      ok(w && W.framed, `${mode}: הערכה רצה בתוך החלון`);
      for (const k of W.checks) ok(k.ok, `${mode} · בתוך החלון · ${k.id} · ${k.name}`, k.text);
      ok(['H16', 'H17'].every(id => W.checks.some(k => k.id === id)), `${mode}: H16 ו-H17 רצו`);
      await sleep(800);
      ok(page.url() === base + '/lexicon/', `${mode} · H16/H17: הכתובת של המעטפת לא זזה (לא wp-admin, לא probe.html, לא pwned)`, page.url());
      ok(await page.title() === KIT_TITLE, `${mode} · H16: הכותרת של הלשונית — של הדף, לא PWNED`, await page.title());
      const fr = await page.evaluate(() => { const f = document.getElementById('kol-maroko-frame'); return { h: f.getBoundingClientRect().height, style: f.style.height, sandbox: f.getAttribute('sandbox') }; });
      ok(fr.h < 50000 && !['1000000000px', '321px', '4321px', '77px', '-5px', '0px'].includes(fr.style), `${mode} · H16: גובה החלון לא נקבע מהודעה מזויפת`, JSON.stringify(fr));
      ok(!/allow-same-origin|allow-top-navigation/.test(fr.sandbox), `${mode}: sandbox בלי allow-same-origin`, fr.sandbox);
      ok(await page.isVisible('#site-header'), `${mode} · H5: הכותרת של האתר — נראית`);
      ok((await page.$('#site-footer')) !== null === (mode === 'framed'), `${mode}: התחתית של האתר — ${mode === 'framed' ? 'יש' : 'אין'}`);
      await until(() => hits().some(h => h.action === 'kolkit_h15'), 4000);
      const HH = hits();
      ok(HH.length > 0 && HH.every(h => h.cookie === false), `${mode} · H2/H3/H15: מהחלון — אף בקשה לא נשאה את העוגייה של המחובר`, JSON.stringify(HH));
      ok(page.context().pages().length >= 1 && !(await page.content()).includes('LOGGED-IN-USER-DATA'), `${mode}: נתוני המחובר לא הגיעו למעטפת`);
    }
    await signed('mount', { mount: 'full' });
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
