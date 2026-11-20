/**
 * פרוסה S2 — "➕ סינון לפי נושאים" / "➕ סינון לפי א-ב" בעורך — בדיקות בדפדפן   node kol-maroko/tests/browser/s2.cjs
 *
 * על העמוד המוסב (F3 — ההעתק של /lexicon/, שכבר יש בו סינון נושאים):
 *   · "➕ סינון לפי נושאים" — כבר יש: לא משוכפל, הקיים נבחר, אין עסקה.
 *   · "➕ סינון לפי א-ב" — בלוק HTML רגיל אחרי סינון הנושאים, ה-CSS בסוף קובץ העיצוב של העמוד, עסקה אחת עם שם בהיסטוריה;
 *     בקנבס — כפתור לכל אות; לחיצה שנייה — לא משכפלת; Ctrl+Z — שני הקבצים חוזרים בדיוק.
 *   · בלי סינון נושאים (נמחק) — "➕ סינון לפי נושאים" מוסיף אחד אחרי עטיפת החיפוש (לא בתוכה).
 *   · הכפתורים בפאנל של הרשימה; 390 — האותיות בשורה אחת עם גלילה אופקית, בלי גלילה אופקית של העמוד.
 */
'use strict';
const { chromium } = require('playwright');
const { spawn, execFileSync } = require('child_process');
const fs = require('fs'), os = require('os'), path = require('path'), net = require('net');

const ROOT = path.resolve(__dirname, '..', '..');
const OWNER = { sub: 'u-owner', name: 'Owner Test', email: 'owner@example.test', email_verified: true, platform_admin: true };
let failed = 0, ran = 0;
let T0 = Date.now(); const ok = (cond, what, got) => { ran++; const dt = ((Date.now() - T0) / 1000).toFixed(1); T0 = Date.now(); if (cond) console.log('✅ [' + dt + 's] ' + what); else { failed++; console.log('❌ ' + what + (got !== undefined ? '\n   התקבל: ' + JSON.stringify(got).slice(0, 900) : '')); } };
const freePort = () => new Promise(r => { const s = net.createServer().listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => r(p)); }); });
const sleep = ms => new Promise(r => setTimeout(r, ms));

(async () => {
  const data = fs.mkdtempSync(path.join(os.tmpdir(), 'kol-s2-'));
  execFileSync('php', [path.join(__dirname, 'f3-seed.php'), data, path.join(data, 'old-render.html')]);
  const port = await freePort(), base = `http://127.0.0.1:${port}`;
  const php = spawn('php', ['-S', `127.0.0.1:${port}`, path.join(ROOT, 'tests', 'router.php')], { cwd: ROOT, env: { ...process.env, KOL_TEST_DATA: data, PHP_CLI_SERVER_WORKERS: '4' }, stdio: 'ignore' });
  let browser;
  try {
    for (let i = 0; i < 100; i++) { try { await fetch(base + '/api.php?action=embed_key'); break; } catch { await sleep(50); } }
    browser = await chromium.launch();
    const mk = (width, height) => browser.newContext({ viewport: { width, height }, hasTouch: width < 600, isMobile: width < 600, extraHTTPHeaders: { 'X-Test-User': JSON.stringify(OWNER) } });
    const call = (page, action, body) => page.evaluate(([a, b]) => api(a, b ?? undefined), [action, body ?? null]);
    const openPage = async (page, p) => { await page.goto(base + '/editor.php#/editor/' + encodeURIComponent(p)); await page.reload(); await page.waitForFunction(q => Editor._state.file && Editor._state.file.path === q && Editor._state.canvas && Editor._state.canvas.m && !Editor._state.busy && Editor._state.build && Editor._state.lv && Editor._state.data, p, { timeout: 30000 }); await page.waitForFunction(() => Editor._state.lv.lists.length && Editor._state.lv.lists.every(L => L.status !== 'loading'), null, { timeout: 30000 }); await sleep(300); };
    const files = page => page.evaluate(() => { const S = Editor._state, o = { [S.file.path]: S.doc.text() }; for (const x of S.sheets.values()) o[x.path] = x.doc.text(); return o; });
    const info = page => page.evaluate(() => { const S = Editor._state, t = S.tx, n = S.canvas.selected, cls = n && n.attrs.find(a => a.name === 'class'); return { tx: t.length, last: t.length ? t[t.length - 1].label : '', sel: n ? n.tag + '.' + (cls ? cls.value.replace(/\s+/g, '.') : '') : '', note: (document.querySelector('.ed-note') || {}).textContent || '' }; });
    const lib = async (page, id) => { await page.click('[data-ntab="lib"]'); await sleep(100); await page.click(`.ed-lib-item[data-lib="${id}"]`); await sleep(700); await page.waitForFunction(() => !Editor._state.lv.busy, null, { timeout: 15000 }); };
    const undo = async page => { await page.evaluate(() => Editor._undo()); await sleep(900); };
    const shotDir = path.join(os.tmpdir(), 'kol-s2-shots'); fs.mkdirSync(shotDir, { recursive: true });

    const ctx = await mk(1920, 1080), page = await ctx.newPage(), errors = [];
    page.on('pageerror', e => { errors.push(e.message); console.log('   ⚠️ שגיאה בדף: ' + e.message); });
    page.on('dialog', d => d.type() === 'beforeunload' ? d.accept() : d.dismiss());
    await page.goto(base + '/#/project');
    await page.waitForSelector('.pj-tree .pj-row');
    await call(page, 'pf_migrate', {});
    await openPage(page, 'index.html');
    const o0 = await files(page), i0 = await info(page);
    ok(/data-kol-filter="words:topics"/.test(o0['index.html']) && !/@letter/.test(o0['index.html']), 'העמוד המוסב: יש סינון נושאים, אין סינון א-ב');

    // ═══ 1. "➕ סינון לפי נושאים" — כבר יש: לא משכפלים ═══
    let b = await page.$$eval('.ed-lib-item', xs => xs.map(x => x.getAttribute('data-lib')));
    ok(b.includes('kl-add-topics') && b.includes('kl-add-letters'), 'בספרייה: "➕ סינון לפי נושאים" ו-"➕ סינון לפי א-ב"', b.filter(x => /^kl/.test(x)));
    await lib(page, 'kl-add-topics');
    let st = await info(page), now = await files(page);
    ok(JSON.stringify(now) === JSON.stringify(o0) && st.tx === i0.tx && /kadisha-lexicon-topic-filter/.test(st.sel) && /כבר יש בעמוד סינון לפי נושאים/.test(st.note), 'נושאים — כבר יש: הקבצים לא השתנו, אין עסקה, הקיים נבחר והודעה', st);

    // ═══ 2. "➕ סינון לפי א-ב" ═══
    await lib(page, 'kl-add-letters');
    st = await info(page); now = await files(page);
    const html = now['index.html'], cssPath = Object.keys(now).find(p => p !== 'index.html' && now[p] !== o0[p]);
    const iT = html.indexOf('data-kol-filter="words:topics"'), iL = html.indexOf('data-kol-filter="words:word@letter"'), iC = html.indexOf('kadisha-lexicon-count');
    ok(iL > iT && iL < iC && /class="kol-filter kol-filter--letters"/.test(html) && /aria-label="סינון לפי אות"/.test(html), 'הבלוק נכנס אחרי סינון הנושאים ולפני המונה, עם מחלקות ו-aria-label', { iT, iL, iC });
    ok(cssPath && now[cssPath].startsWith(o0[cssPath]) && /\.kol-filter \{/.test(now[cssPath].slice(o0[cssPath].length)) && /overflow-x: auto/.test(now[cssPath]), 'ה-CSS נוסף בסוף קובץ העיצוב של העמוד (' + cssPath + '), בלי לגעת במה שהיה', cssPath);
    ok(st.tx === i0.tx + 1 && st.last === 'הוספה · סינון לפי א-ב' && /kol-filter--letters/.test(st.sel), 'עסקה אחת בשם "הוספה · סינון לפי א-ב" (שני קבצים), והבלוק החדש נבחר', st);
    const hist = await page.evaluate(() => { const S = Editor._state; return S.tx[S.tx.length - 1].docs.length; });
    ok(hist === 2, 'העסקה כוללת את שני הקבצים (HTML ו-CSS) — ביטול אחד', hist);
    let cv = await page.evaluate(() => { const d = Editor._state.canvas.doc, f = d.querySelector('.kol-filter--letters'); if (!f) return null; const bs = [...f.querySelectorAll('[data-kol-filter-value]')]; return { n: bs.length, first: bs[0] && bs[0].classList.contains('is-active'), letters: bs.filter(x => x.hasAttribute('aria-pressed')).length, vals: bs.slice(0, 6).map(x => x.getAttribute('data-kol-filter-value')), style: getComputedStyle(f).display }; });
    ok(cv && cv.n >= 5 && cv.first === true && cv.letters === cv.n - 1 && cv.vals[0] === '' && cv.style === 'flex', 'בקנבס: "הכול" לחוץ, וכפתור לכל אות (מצויר מהנתונים, מעוצב)', cv);
    await page.screenshot({ path: path.join(shotDir, 's2-letters-1920.png') });
    await lib(page, 'kl-add-letters');
    let again = await files(page);
    ok(JSON.stringify(again) === JSON.stringify(now) && /כבר יש בעמוד סינון לפי א-ב/.test((await info(page)).note), 'לחיצה שנייה — לא משכפלת', (await info(page)).note);

    // ═══ 3. Ctrl+Z ═══
    await undo(page);
    ok(JSON.stringify(await files(page)) === JSON.stringify(o0), 'Ctrl+Z — העמוד וקובץ העיצוב חוזרים בדיוק');

    // ═══ 4. בלי סינון נושאים — נוסף אחרי עטיפת החיפוש ═══
    await page.evaluate(() => { const S = Editor._state, c = S.canvas, n = c.nodeOf(c.doc.querySelector('[data-kol-filter="words:topics"]')); c.select(n); Editor._remove(); });
    await sleep(900);
    const noTopics = await files(page);
    ok(!/data-kol-filter=/.test(noTopics['index.html']), 'סינון הנושאים נמחק (כדי לבדוק הוספה כשאין)');
    await lib(page, 'kl-add-topics');
    now = await files(page); st = await info(page);
    const h2 = now['index.html'], iW = h2.indexOf('kadisha-lexicon-search-wrap'), iWend = h2.indexOf('</div>', iW), iT2 = h2.indexOf('data-kol-filter="words:topics"');
    ok(iT2 > iWend && iT2 < h2.indexOf('kadisha-lexicon-count') && /kol-filter--topics/.test(h2) && st.last === 'הוספה · סינון לפי נושאים', 'נושאים — נוסף אחרי עטיפת החיפוש (לא בתוכה) ולפני המונה', { iW, iWend, iT2, last: st.last });
    cv = await page.evaluate(() => [...Editor._state.canvas.doc.querySelectorAll('.kol-filter--topics [data-kol-filter-value]')].map(x => x.textContent.trim()).slice(0, 3));
    ok(cv.length >= 3 && cv[0] === 'הכול', 'בקנבס: כפתורי הנושאים עם שם ומספר', cv);
    await undo(page); await undo(page);
    ok(JSON.stringify(await files(page)) === JSON.stringify(o0), 'Ctrl+Z ×2 — חזרה למצב המקורי');

    // ═══ 5. בפאנל של הרשימה ═══
    await page.evaluate(() => { const S = Editor._state, c = S.canvas, n = c.nodeOf(c.doc.querySelector('[data-kol-list="words"]')); c.select(n); c.reveal(n); if (!document.querySelector('.ed').classList.contains('info-open')) document.querySelector('.ed-bar [data-panel="info"]').click(); Editor._tab('content'); });
    await sleep(400);
    b = await page.$$eval('[data-dp-add-filter]', xs => xs.map(x => x.textContent));
    ok(b.includes('➕ סינון לפי נושאים') && b.includes('➕ סינון לפי א-ב'), 'בפאנל "📊 נתונים · הרשימה words": שני הכפתורים', b);
    await page.click('[data-dp-add-filter="letters"]'); await sleep(900);
    ok(/words:word@letter/.test((await files(page))['index.html']), 'מהפאנל — נוסף סינון א-ב');

    // ═══ 6. 390 — האותיות בשורה אחת עם גלילה אופקית ═══
    await page.evaluate(() => Editor._save && Editor._save()); await sleep(1500);
    const ctxP = await mk(390, 844), pp = await ctxP.newPage();
    pp.on('pageerror', e => errors.push(e.message));
    await pp.goto(base + '/#/project'); await pp.waitForSelector('.pj-tree .pj-row');
    await openPage(pp, 'index.html');
    st = await pp.evaluate(() => { const d = Editor._state.canvas.doc, f = d.querySelector('.kol-filter--letters'); if (!f) return null; const cs = getComputedStyle(f), bs = [...f.querySelectorAll('button')], tops = new Set(bs.map(x => Math.round(x.getBoundingClientRect().top))); return { wrap: cs.flexWrap, ox: cs.overflowX, rows: tops.size, scrolls: f.scrollWidth > f.clientWidth, page: d.documentElement.scrollWidth <= d.documentElement.clientWidth + 1, h: Math.round(bs[1].getBoundingClientRect().height) }; });
    ok(st && st.wrap === 'nowrap' && st.ox === 'auto' && st.rows === 1 && st.scrolls && st.page && st.h >= 40, '390: האותיות בשורה אחת עם גלילה אופקית, כפתור ≥40px, העמוד עצמו בלי גלילה אופקית', st);
    await pp.screenshot({ path: path.join(shotDir, 's2-letters-390.png') });
    await ctxP.close();

    ok(!errors.length, 'אין שגיאות JavaScript בדף', errors.slice(0, 3));
    console.log('\nצילומים: ' + shotDir);
  } catch (e) { failed++; console.log('❌ חריגה: ' + (e.stack || e.message)); }
  finally { await browser?.close(); php.kill(); }
  console.log('\n' + (failed ? '❌ נכשלו ' + failed + ' מתוך ' + ran : '✅ כל ' + ran + ' הבדיקות עברו'));
  process.exit(failed ? 1 : 0);
})();
