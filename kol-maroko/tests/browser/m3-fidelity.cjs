/**
 * פרוסה M3 — בדיקת הנאמנות (docs/spec-v2/09-migration.md §4; M3-cutover.md §2):
 *
 *   node kol-maroko/tests/browser/m3-fidelity.cjs <כתובת-ישנה> <כתובת-בדיקה> [--out dir] [--widths 390,768,1280,1920] [--max-height N] [--label x]
 *   node kol-maroko/tests/browser/m3-fidelity.cjs --self-test
 *
 * משווה שתי כתובות — העמוד החי של היום מול כתובת הבדיקה (או, אחרי ההדלקה, הכתובת האמיתית מול הדוח של כתובת הבדיקה) —
 * בכל שורות 09 §4: מראה (צילום מלא ב-4 רוחבים, **אזור העמוד בלבד**: `.kadisha-lexicon-page`, כך שמסגרת האתר נחתכת; הפרש
 * ≤ 0.5% מהפיקסלים, ומפת הפרשים כתמונה) · מספרים (מילים, כפתורי השמעה, נושאים — והרשימה כולה, באותו סדר) · 20 שאילתות ·
 * כל נושא ושילוב · המונה והודעות הריק · השמעה · המבוא · המשוב (נפתח עם המילה; **לא נשלח** — כל בקשה שאינה GET נחסמת) ·
 * סדר Tab ותוויות · קישור עמוק · זמני טעינה בטלפון מדומה.
 *
 * הפלט: <out>/fidelity-<תאריך>.json + .html (דוח קריא, עם הצילומים בתיקייה לידו), ושורה אחת: "עבר N/N".
 * כל הפרש מופיע, גם קטן, עם הצעה: "הסבר" / "תיקון בפרויקט" / "שורה חדשה לרשימה". יציאה 0 — רק כשהכול עבר.
 * ברירת המחדל ל-out: kol-maroko/dev/m3/ (בריפו; לא בשרת).
 *
 * ⚠️ צריך גישה לאתר הציבורי. מסביבת צ'אט הקוד האתר מחזיר אתגר Cloudflare (403, cf-mitigated: challenge), ואין workflow
 *    (הכרעת הבקרה, 2026-11-12). מול האתר האמיתי — שורת "השוואה מול האתר — אישור הבעלים עם צילומים" במסך המעבר.
 *    הסקריפט נבדק בלי האתר: --self-test.
 *
 * --self-test: שתי כתובות מקומיות. הצד "הישן" — הפרויקט המוסב בתצוגה החיה (F4, preview/); הצד "החדש" — החבילה של M1
 *   (בנייה + שליחה למקבל המדומה), מוגשת מתיקייה סטטית. (from-current/ הוא תבניות Mustache ואינו עמוד שאפשר להגיש,
 *   והרינדור הישן — בלי ה-JS של ערכת הנושא, f3 §1 — ולכן אינו יכול לשמש צד להתנהגות.) מצופה: "עבר". ואז חבילה ששונתה
 *   בכוונה (מילה חסרה ב-data/words.json, צבע בכותרת הכרטיס) — מצופה: "נכשל", בשורות "מילים" ו"מראה".
 */
'use strict';
const { chromium } = require('playwright');
const fs = require('fs'), path = require('path');

const SEL = {
  page: '.kadisha-lexicon-page', card: '.kadisha-word-card', title: '.kadisha-word-title', meaning: '.kadisha-word-meaning', topicOf: '.kadisha-word-topic',
  play: '.kadisha-word-audio-btn', topic: '.kadisha-lex-topic-btn', search: '#kadisha-lex-search', scope: '#kadisha-lex-scope-full',
  count: '.kadisha-lexicon-count', shown: '#kadisha-lex-shown', none: '#kadisha-lex-no-matches', heroBtn: '.kadisha-lex-hero-btn',
  panel: '.kadisha-lex-section-panel', introToggle: '.kadisha-lexicon-intro-toggle', fbBtn: '.kadisha-word-feedback-btn',
  fbModal: '#kadisha-lex-feedback-modal', fbWord: '#kadisha-lex-feedback-word-name',
};
const QUERIES = [['', 0], ['כסכסו', 0], ['כסכ', 0], ['מימונה 12', 0], ['בְּסְלָאמָה', 0], ['בסלאמה', 0], ['ZOHRA', 1], ['zohra', 1], ['bslama', 1], ['k&sksu', 1],
  ["s'ouda", 1], ['البركة', 1], ['حبيبي', 1], ['פתגמים', 1], ['בית הכנסת', 1], ['נאמר בכניסה', 1], ['1496', 0], ['  שבת   7 ', 0], ['אין-מילה-כזאת', 1], ['פירוש המילה', 0]];
const SUGGEST = { explain: 'הסבר', fix: 'תיקון בפרויקט', row: 'שורה חדשה לרשימה' };
const CHUNK = 4000;
const sleep = ms => new Promise(r => setTimeout(r, ms));
const esc = s => String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

// ── עזרים בדף ────────────────────────────────────────────

/** מחכה שהעמוד יתייצב: [data-kol-ready] אם יש, ומספר הכרטיסים הגלויים קבוע 400ms. */
async function settle(p, ms = 30000) {
  const t = Date.now();
  await p.waitForSelector(SEL.card, { state: 'attached', timeout: ms }).catch(() => {});
  let last = null, since = Date.now();
  while (Date.now() - t < ms) {
    const cur = await p.evaluate(s => {
      // kol-data.js בעמוד: מחכים ל-data-kol-ready של כל רשימה. בלעדיו (ערכת הנושא) — רק ליציבות
      const busy = !!document.querySelector('script[src*="kol-data"]') && !!document.querySelector('[data-kol-list]:not([data-kol-ready])');
      return (busy ? 'busy:' : '') + [...document.querySelectorAll(s)].filter(c => c.getClientRects().length).map(c => c.textContent.length).join(',');
    }, SEL.card).catch(() => 'x');
    if (cur === last && !cur.startsWith('busy:')) { if (Date.now() - since >= 400) return; } else { last = cur; since = Date.now(); }
    await sleep(100);
  }
}
const visible = p => p.evaluate(s => [...document.querySelectorAll(s.card)].filter(c => c.getClientRects().length && getComputedStyle(c).visibility !== 'hidden').map(c => c.querySelector(s.title)?.textContent.trim() ?? ''), SEL);
const text = (p, s) => p.evaluate(q => { const e = document.querySelector(q); return e ? e.textContent.replace(/\s+/g, ' ').trim() : null; }, s);
const isShown = (p, s) => p.evaluate(q => { const e = document.querySelector(q); return !!e && !!e.getClientRects().length && getComputedStyle(e).visibility !== 'hidden' && !e.hidden; }, s);
const fakeAudio = () => {
  window.__aud = [];
  const A = function (src) { const a = { src, paused: true, currentTime: 0, play() { a.paused = false; window.__aud.forEach(x => x !== a && x.__el && (x.paused = true)); return Promise.resolve(); }, pause() { a.paused = true; }, addEventListener() {}, removeEventListener() {} }; window.__aud.push(a); return a; };
  window.Audio = A;
  const P = HTMLMediaElement.prototype;
  P.play = function () { if (!this.__rec) { this.__rec = { paused: true, __el: true }; window.__aud.push(this.__rec); } this.__rec.paused = false; Object.defineProperty(this, 'paused', { configurable: true, get: () => this.__rec.paused }); return Promise.resolve(); };
  P.pause = function () { if (this.__rec) this.__rec.paused = true; };
};

/** צילום של אזור העמוד בחלקים. מחזיר [{y, h, buf}], ואת הגובה והרוחב של האזור. */
async function shots(p, w, maxH) {
  const r = await p.evaluate(s => { const e = document.querySelector(s) || document.body; const b = e.getBoundingClientRect(); return { x: Math.max(0, Math.round(b.left + scrollX)), y: Math.max(0, Math.round(b.top + scrollY)), w: Math.round(b.width), h: Math.round(e.scrollHeight > b.height ? e.scrollHeight : b.height), crop: !!document.querySelector(s) }; }, SEL.page);
  const H = Math.min(r.h, maxH), out = [];
  for (let y = 0; y < H; y += CHUNK) {
    const h = Math.min(CHUNK, H - y);
    out.push({ y, h, buf: await p.screenshot({ clip: { x: r.x, y: r.y + y, width: Math.max(1, Math.min(r.w, w)), height: h }, fullPage: true, animations: 'disabled', caret: 'hide' }) });
  }
  return { parts: out, height: r.h, width: r.w, crop: r.crop, covered: H };
}

/** השוואת שני PNG בדף עזר (canvas). מחזיר מספר פיקסלים שונים, והמפה (PNG) כשיש הפרש. */
function differ(tool) {
  return (a, b) => tool.evaluate(async ([x, y]) => {
    const img = s => new Promise(r => { const i = new Image(); i.onload = () => r(i); i.onerror = () => r(null); i.src = 'data:image/png;base64,' + s; });
    const [p, q] = [await img(x), await img(y)];
    const w = Math.max(p ? p.width : 0, q ? q.width : 0), h = Math.max(p ? p.height : 0, q ? q.height : 0);
    if (!w || !h) return { n: 0, total: 0 };
    const px = i => { const c = document.createElement('canvas'); c.width = w; c.height = h; const g = c.getContext('2d'); g.fillStyle = '#fff'; g.fillRect(0, 0, w, h); if (i) g.drawImage(i, 0, 0); return g.getImageData(0, 0, w, h); };
    const d1 = px(p), d2 = px(q), m = new ImageData(w, h); let n = 0, first = -1;
    for (let i = 0; i < d1.data.length; i += 4) {
      const dd = Math.abs(d1.data[i] - d2.data[i]) + Math.abs(d1.data[i + 1] - d2.data[i + 1]) + Math.abs(d1.data[i + 2] - d2.data[i + 2]);
      if (dd > 24) { n++; if (first < 0) first = Math.floor(i / 4 / w); m.data[i] = 230; m.data[i + 1] = 0; m.data[i + 2] = 0; m.data[i + 3] = 255; }
      else { const g = (d1.data[i] + d1.data[i + 1] + d1.data[i + 2]) / 3; m.data[i] = m.data[i + 1] = m.data[i + 2] = 255 - (255 - g) * 0.25; m.data[i + 3] = 255; }
    }
    let map = null;
    if (n) { const c = document.createElement('canvas'); c.width = w; c.height = h; c.getContext('2d').putImageData(m, 0, 0); map = c.toDataURL('image/png').split(',')[1]; }
    return { n, total: w * h, first, map };
  }, [a.toString('base64'), b.toString('base64')]);
}

// ── ההשוואה ──────────────────────────────────────────────

/**
 * oldUrl מול newUrl. opts: { out, label, widths, maxH, hermetic (חוסם כל מה שמחוץ לשתי הכתובות), log }.
 * מחזיר את הדוח; כותב JSON + HTML + צילומים ל-opts.out.
 */
async function compare(oldUrl, newUrl, opts = {}) {
  const widths = opts.widths || [390, 768, 1280, 1920];
  const maxH = opts.maxH || Infinity;
  const log = opts.log || (() => {});
  const stamp = new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19) + (opts.label ? '-' + opts.label : '');
  const outDir = opts.out, shotDir = path.join(outDir, `fidelity-${stamp}`);
  fs.mkdirSync(shotDir, { recursive: true });
  const rows = [];
  const row = (id, title, pass, detail, diffs = [], suggest = 'fix') => {
    rows.push({ id, title, pass: !!pass, detail, diffs: diffs.map(d => ({ suggest: SUGGEST[d.suggest || suggest], ...d })) });
    log((pass ? '✅ ' : '❌ ') + title + (detail ? ' — ' + detail : ''));
  };
  const origins = [new URL(oldUrl).origin, new URL(newUrl).origin];
  const browser = await chromium.launch();
  const errs = { old: [], new: [] }, posts = [];
  try {
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 800 }, locale: 'he-IL', timezoneId: 'Asia/Jerusalem' });
    await ctx.addInitScript(fakeAudio);
    await ctx.route('**/*', r => {
      const req = r.request(), u = req.url();
      if (u.startsWith('data:') || u.startsWith('blob:')) return r.continue();
      // המשוב אינו נשלח, ושום דבר אינו נכתב באתר: כל בקשה שאינה GET / HEAD — נחסמת ונרשמת
      if (!['GET', 'HEAD'].includes(req.method())) { posts.push(req.method() + ' ' + u); return r.abort(); }
      if (opts.hermetic && !origins.some(o => u.startsWith(o))) return r.abort();
      return r.continue();
    });
    const A = await ctx.newPage(), B = await ctx.newPage(), tool = await ctx.newPage();
    const watch = (p, k) => { p.on('pageerror', e => errs[k].push(e.message)); p.on('console', m => { if (m.type() === 'error' && !/Failed to load resource|ERR_FAILED|ERR_BLOCKED/.test(m.text())) errs[k].push(m.text()); }); };
    watch(A, 'old'); watch(B, 'new');
    const diff = differ(tool);
    const openBoth = async (w, h, qa = '', qb = qa) => {
      await A.setViewportSize({ width: w, height: h }); await B.setViewportSize({ width: w, height: h });
      await Promise.all([A.goto(oldUrl + qa, { waitUntil: 'load', timeout: 60000 }), B.goto(newUrl + qb, { waitUntil: 'load', timeout: 60000 })]);
      await Promise.all([settle(A), settle(B)]);
    };
    const shotFiles = [];

    // מראה
    for (const w of widths) {
      await openBoth(w, w <= 400 ? 844 : w <= 800 ? 1024 : w <= 1300 ? 800 : 1080);
      await A.evaluate(() => document.fonts && document.fonts.ready); await B.evaluate(() => document.fonts && document.fonts.ready);
      const [sa, sb] = [await shots(A, w, maxH), await shots(B, w, maxH)];
      let n = 0, total = 0, first = -1, maps = 0;
      const k = Math.max(sa.parts.length, sb.parts.length);
      for (let i = 0; i < k; i++) {
        const pa = sa.parts[i], pb = sb.parts[i];
        if (!pa || !pb) { const only = pa || pb; total += only.h * w; n += only.h * w; if (first < 0) first = only.y; continue; }
        const d = await diff(pa.buf, pb.buf);
        n += d.n; total += d.total;
        if (d.n && first < 0) first = pa.y + d.first;
        if (i === 0 || (d.n && maps < 6)) {
          const base = `${w}-${String(pa.y).padStart(6, '0')}`;
          fs.writeFileSync(path.join(shotDir, `${base}-old.png`), pa.buf); fs.writeFileSync(path.join(shotDir, `${base}-new.png`), pb.buf);
          if (d.map) { fs.writeFileSync(path.join(shotDir, `${base}-diff.png`), Buffer.from(d.map, 'base64')); maps++; }
          shotFiles.push({ width: w, y: pa.y, old: `${base}-old.png`, new: `${base}-new.png`, diff: d.map ? `${base}-diff.png` : null, ratio: d.total ? d.n / d.total : 0 });
        }
      }
      const ratio = total ? n / total : 0;
      const sizeNote = sa.height !== sb.height || sa.width !== sb.width ? ` · גודל האזור ${sa.width}×${sa.height} מול ${sb.width}×${sb.height}` : '';
      const cropNote = (sa.crop ? '' : ' · ⚠️ בישן אין ' + SEL.page + ' — צולם body') + (sb.crop ? '' : ' · ⚠️ בחדש אין ' + SEL.page + ' — צולם body');
      const covered = Math.min(sa.covered, sb.covered) < Math.max(sa.height, sb.height) ? ` · ${Math.min(sa.covered, sb.covered)}px העליונים (--max-height)` : '';
      const pass = ratio <= 0.005;
      row(`visual-${w}`, `מראה ב-${w}px`, pass, `הפרש ${(ratio * 100).toFixed(4)}% מהפיקסלים (עד 0.5%)${first >= 0 ? ' · הראשון בשורה ' + first : ''}${sizeNote}${cropNote}${covered}`,
        n ? [{ what: `${n} פיקסלים שונים ב-${w}px`, old: sa.height + 'px', new: sb.height + 'px', suggest: pass ? 'explain' : 'fix' }] : []);
    }

    // מספרים וסדר (1280)
    await openBoth(1280, 800);
    const listOf = p => p.evaluate(s => [...document.querySelectorAll(s.card)].map(c => [c.querySelector(s.title)?.textContent.trim() ?? '', (c.querySelector(s.meaning)?.textContent ?? '').replace(/\s+/g, ' ').trim(),
      [...c.querySelectorAll(s.topicOf)].map(t => t.textContent.trim()).join(','), c.querySelector(s.play) ? 'play:' + (c.querySelector(s.play).getAttribute('aria-label') || '') : '', c.querySelector(s.fbBtn) ? 'fb' : ''].join('¦')), SEL);
    const [la, lb] = [await listOf(A), await listOf(B)];
    const plays = l => l.filter(x => x.split('¦')[3]).length;
    row('count-words', 'מספרים: מילים', la.length === lb.length, `${la.length} / ${lb.length}`, la.length === lb.length ? [] : [{ what: 'מספר המילים', old: la.length, new: lb.length }]);
    row('count-plays', 'מספרים: כפתורי השמעה', plays(la) === plays(lb), `${plays(la)} / ${plays(lb)}`, plays(la) === plays(lb) ? [] : [{ what: 'כפתורי השמעה', old: plays(la), new: plays(lb) }]);
    const topicsOf = p => p.evaluate(s => [...document.querySelectorAll(s.topic)].map(b => b.textContent.replace(/\s+/g, ' ').trim()), SEL);
    const [ta, tb] = [await topicsOf(A), await topicsOf(B)];
    row('count-topics', 'מספרים: נושאים (עם המונים, באותו סדר)', JSON.stringify(ta) === JSON.stringify(tb), `${ta.length} / ${tb.length}`,
      JSON.stringify(ta) === JSON.stringify(tb) ? [] : [{ what: 'הנושאים', old: ta.join(' | '), new: tb.join(' | ') }]);
    const listDiffs = [];
    for (let i = 0; i < Math.max(la.length, lb.length) && listDiffs.length < 30; i++) if (la[i] !== lb[i]) listDiffs.push({ what: `כרטיס ${i + 1}`, old: la[i] ?? '(אין)', new: lb[i] ?? '(אין)' });
    row('list', 'הרשימה כולה: מילה, פירוש, נושאים, השמעה, משוב — באותו סדר', listDiffs.length === 0, listDiffs.length ? `${listDiffs.length >= 30 ? '30+' : listDiffs.length} כרטיסים שונים` : `${la.length} כרטיסים זהים`, listDiffs);

    // המונה, והודעות הריק
    const cA = await text(A, SEL.count), cB = await text(B, SEL.count);
    row('counter', 'המונה (בפתיחה)', cA === cB, cB ?? '(אין)', cA === cB ? [] : [{ what: 'המונה', old: cA, new: cB }]);

    // 20 שאילתות
    const hasScope = async p => !!(await p.$(SEL.scope));
    const search = async (p, q, full) => { if (await hasScope(p)) await p.setChecked(SEL.scope, !!full).catch(() => {}); await p.fill(SEL.search, q); await settle(p, 10000); return visible(p); };
    const qd = [];
    let emptyRow = null;
    for (const [q, full] of QUERIES) {
      const [ra, rb] = [await search(A, q, full), await search(B, q, full)];
      if (JSON.stringify(ra) !== JSON.stringify(rb)) qd.push({ what: `"${q}"${full ? ' (הרחב את החיפוש)' : ''}`, old: `${ra.length}: ${ra.slice(0, 5).join(', ')}`, new: `${rb.length}: ${rb.slice(0, 5).join(', ')}` });
      if (q === 'אין-מילה-כזאת') {
        const s = [await isShown(A, SEL.none), await isShown(B, SEL.none), await text(A, SEL.none), await text(B, SEL.none), await text(A, SEL.shown), await text(B, SEL.shown), await text(A, SEL.count), await text(B, SEL.count)];
        emptyRow = s;
      }
    }
    row('queries', '20 שאילתות (עברית, ניקוד, ערבית, לטינית, חלקית, ריקה, רווחים, בלי תוצאות; רגיל ו"הרחב")', qd.length === 0, qd.length ? `${qd.length} שאילתות שונות` : '20 זהות', qd);
    if (emptyRow) {
      const [va, vb, ta2, tb2, sa2, sb2, ca2, cb2] = emptyRow;
      const same = va === vb && ta2 === tb2 && sa2 === sb2 && ca2 === cb2;
      row('empty', 'בלי תוצאות: הודעת הריק והמונה', same, `הודעה ${vb ? 'מוצגת' : 'לא מוצגת'} · "${tb2 ?? ''}" · מונה ${cb2 ?? sb2 ?? ''}`,
        same ? [] : [{ what: 'הודעת הריק / המונה', old: `${va} · ${ta2} · ${sa2} · ${ca2}`, new: `${vb} · ${tb2} · ${sb2} · ${cb2}` }]);
    }
    const scopeLabel = p => p.evaluate(s => { const e = document.querySelector(s); if (!e) return null; const l = e.closest('label') || document.querySelector(`label[for="${e.id}"]`); return (l ? l.textContent.replace(/\s+/g, ' ').trim() : '') + '|' + e.checked; }, SEL.scope);
    await search(A, '', 0); await search(B, '', 0);
    const [sla, slb] = [await scopeLabel(A), await scopeLabel(B)];
    row('scope', '"הרחב את החיפוש": אותו טקסט, אותו מצב', sla === slb, slb ?? '(אין)', sla === slb ? [] : [{ what: '"הרחב את החיפוש"', old: sla, new: slb }]);

    // כל נושא, ושילוב נושא + חיפוש
    const fd = [];
    const nT = Math.min(ta.length, tb.length);
    for (let i = 1; i < nT; i++) {
      await A.click(`${SEL.topic} >> nth=${i}`, { timeout: 5000 }).catch(() => {}); await B.click(`${SEL.topic} >> nth=${i}`, { timeout: 5000 }).catch(() => {});
      await Promise.all([settle(A, 10000), settle(B, 10000)]);
      const [ra, rb] = [await visible(A), await visible(B)];
      if (JSON.stringify(ra) !== JSON.stringify(rb)) fd.push({ what: `נושא ${tb[i]}`, old: ra.length, new: rb.length });
      if (i === nT - 1) {
        const [qa, qb] = [await search(A, 'a', 1), await search(B, 'a', 1)];
        if (JSON.stringify(qa) !== JSON.stringify(qb)) fd.push({ what: `נושא ${tb[i]} + חיפוש "a" (הרחב)`, old: qa.length, new: qb.length });
        await search(A, '', 0); await search(B, '', 0);
      }
    }
    if (nT) { await A.click(`${SEL.topic} >> nth=0`); await B.click(`${SEL.topic} >> nth=0`); await Promise.all([settle(A), settle(B)]); }
    row('topics', 'סינון: כל נושא, ושילוב נושא + חיפוש', fd.length === 0 && nT > 0, nT ? `${nT - 1} נושאים` : 'אין כפתורי נושא', fd);

    // השמעה: מתנגן; השנייה עוצרת את הראשונה; בלי הקלטה — בלי כפתור (ברשימה למעלה)
    const playTest = async p => {
      await p.evaluate(() => { window.__aud.length = 0; });
      const btns = await p.$$(`${SEL.card} ${SEL.play}`);
      if (btns.length < 2) return 'פחות משני כפתורים';
      await btns[0].click(); await sleep(150); await btns[1].click(); await sleep(150);
      return (await p.evaluate(() => window.__aud.map(a => (a.paused ? 'p' : 'on')).join(' '))) + ' · ' + (await btns[1].evaluate(b => b.className.replace(/\s+/g, ' ').trim()));
    };
    const [pa, pb] = [await playTest(A), await playTest(B)];
    row('audio', 'השמעה: מתנגן; הקלטה שנייה עוצרת את הראשונה', pa === pb && / on/.test(pb) && !/on .*on/.test(pb.split(' · ')[0]), pb, pa === pb ? [] : [{ what: 'השמעה', old: pa, new: pb }]);

    // המבוא: שני הכפתורים; "קרא עוד" / "הסתר"
    const intro = async p => {
      const st = () => p.evaluate(s => [[...document.querySelectorAll(s.panel)].map(x => x.hidden || !x.getClientRects().length).join(), document.querySelector(s.introToggle)?.textContent.trim() ?? '', document.querySelector(s.introToggle)?.getAttribute('aria-expanded') ?? ''].join('/'), SEL);
      // הרצף של f3: הכפתור הראשון פותח את הקטע; "קרא עוד" (גלוי רק אז) — ושוב; והכפתור הראשון סוגר. כפתור שאינו לחיץ — "✗"
      const tap = async (q, n = 0) => (await p.click(`${q} >> nth=${n}`, { timeout: 3000 }).then(() => true, () => false)) ? (await sleep(150), await st()) : '✗ ' + q;
      const seq = [await st(), await tap(SEL.heroBtn, 0), await tap(SEL.introToggle), await tap(SEL.introToggle), await tap(SEL.heroBtn, 0)];
      if ((await p.$$(SEL.heroBtn)).length > 1) seq.push(await tap(SEL.heroBtn, 1), await tap(SEL.heroBtn, 1));
      return seq.join(' → ');
    };
    const [ia, ib] = [await intro(A), await intro(B)];
    row('intro', 'המבוא: הכפתורים פותחים וסוגרים; "קרא עוד" / "הסתר"', ia === ib, ib.slice(0, 160), ia === ib ? [] : [{ what: 'רצף המצבים', old: ia, new: ib }]);

    // המשוב: נפתח מכרטיס, עם המילה. לא נשלח (וכל POST חסום ממילא)
    const fb = async p => {
      const b = await p.$(`${SEL.card} ${SEL.fbBtn}`);
      if (!b) return 'אין כפתור משוב';
      const w = await p.$eval(`${SEL.card} ${SEL.title}`, e => e.textContent.trim());
      if (!(await b.click({ timeout: 5000 }).then(() => true, () => false))) return 'כפתור המשוב אינו לחיץ';
      await sleep(250);
      const r = [await isShown(p, SEL.fbModal), (await text(p, SEL.fbWord)) === w].join(',');
      await p.keyboard.press('Escape'); await sleep(150);
      return r + ',' + (await isShown(p, SEL.fbModal));
    };
    const [fa, fbb] = [await fb(A), await fb(B)];
    row('feedback', 'המשוב: נפתח מכרטיס עם המילה, Escape סוגר (בלי לשלוח)', fa === fbb && fbb === 'true,true,false', fbb, fa === fbb ? [] : [{ what: 'המשוב', old: fa, new: fbb }]);

    // מקלדת: סדר Tab ותוויות (40 הראשונים)
    const tabs = p => p.evaluate(s => {
      const root = document.querySelector(s) || document.body;
      return [...root.querySelectorAll('a[href], button, input, textarea, select, [tabindex]')].filter(e => e.tabIndex >= 0 && e.getClientRects().length).slice(0, 40)
        .map(e => e.tagName + '.' + String(e.className || e.id).replace(/\bis-[a-z-]+/g, '').replace(/\s+/g, ' ').trim() + '[' + (e.getAttribute('aria-label') || e.labels?.[0]?.textContent.trim() || e.textContent.trim()).slice(0, 40) + ']');
    }, SEL.page);
    await openBoth(1280, 800);
    const [ka, kb] = [await tabs(A), await tabs(B)];
    const kd = [];
    for (let i = 0; i < Math.max(ka.length, kb.length) && kd.length < 10; i++) if (ka[i] !== kb[i]) kd.push({ what: `Tab ${i + 1}`, old: ka[i] ?? '(אין)', new: kb[i] ?? '(אין)' });
    row('keyboard', 'מקלדת וקורא מסך: סדר Tab ותוויות (40 הראשונים)', kd.length === 0, `${kb.length} אלמנטים`, kd);

    // קישור עמוק: רק אם הישן תומך (חיפוש / נושא בכתובת) — גם החדש
    const base0 = (await visible(A)).length;
    const deep = [];
    for (const q of ['?s=zohra', '?q=zohra', '?search=zohra', '#zohra', '?topic=' + encodeURIComponent((ta[1] || '').replace(/\s*\(\d+\)\s*$/, ''))]) {
      await openBoth(1280, 800, q);
      const [ra, rb] = [await visible(A), await visible(B)];
      if (ra.length !== base0) deep.push({ q, old: ra.length, new: rb.length, same: JSON.stringify(ra) === JSON.stringify(rb) });
    }
    row('deeplink', 'קישור עמוק (חיפוש / נושא בכתובת)', deep.every(d => d.same), deep.length ? deep.map(d => `${d.q}: ${d.old}/${d.new}`).join(' · ') : 'הישן אינו מגיב לאף אחת מהצורות שנבדקו — אין מה להשוות',
      deep.filter(d => !d.same).map(d => ({ what: `הכתובת ${d.q}`, old: d.old, new: d.new })).concat(deep.length ? [] : [{ what: 'אם יש בישן צורה אחרת של קישור עמוק — להוסיף אותה לבדיקה', old: '', new: '', suggest: 'row' }]).filter(d => d.what), deep.length ? 'fix' : 'row');
    // שורה "נכשלה" רק בהפרש אמיתי; ההצעה להוסיף שורה — מידע
    if (!deep.length) rows[rows.length - 1].pass = true;

    // מהירות: טלפון מדומה, מעבד ×4
    const perf = async url => {
      const c = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2, locale: 'he-IL' });
      await c.route('**/*', r => (!['GET', 'HEAD'].includes(r.request().method()) || (opts.hermetic && !origins.some(o => r.request().url().startsWith(o))) ? r.abort() : r.continue()));
      const P = await c.newPage();
      const cdp = await c.newCDPSession(P); await cdp.send('Emulation.setCPUThrottlingRate', { rate: 4 });
      const t0 = Date.now();
      await P.goto(url, { waitUntil: 'commit', timeout: 90000 });
      await P.waitForSelector(SEL.card, { timeout: 90000 }).catch(() => {});
      const first = Date.now() - t0;
      await P.waitForSelector(SEL.search, { timeout: 90000 }).catch(() => {});
      await settle(P, 90000);
      const usable = Date.now() - t0;
      await c.close();
      return { first, usable };
    };
    const [qa, qb] = [await perf(oldUrl), await perf(newUrl)];
    const slow = (a, b) => b > a * 1.2 + 300;
    row('speed', 'מהירות בטלפון מדומה (390px, מעבד ×4): כרטיס ראשון ועד חיפוש שמיש — לא גרוע מהיום', !slow(qa.first, qb.first) && !slow(qa.usable, qb.usable),
      `כרטיס ראשון ${qa.first}ms ← ${qb.first}ms · שמיש ${qa.usable}ms ← ${qb.usable}ms (סף: ×1.2 + 300ms)`,
      slow(qa.first, qb.first) || slow(qa.usable, qb.usable) ? [{ what: 'זמני טעינה', old: `${qa.first}/${qa.usable}ms`, new: `${qb.first}/${qb.usable}ms` }] : []);

    row('console', 'בלי שגיאות JS בעמוד החדש (בישן — לידיעה)', errs.new.length === 0, `חדש ${errs.new.length} · ישן ${errs.old.length}`,
      errs.new.slice(0, 10).map(e => ({ what: 'שגיאה בחדש', old: '', new: e })));
    if (posts.length) log('ℹ️ בקשות שנחסמו (לא נשלחו): ' + posts.slice(0, 5).join(' · '));

    const report = { old: oldUrl, new: newUrl, at: new Date().toISOString(), widths, maxHeight: Number.isFinite(maxH) ? maxH : null, rows, shots: shotFiles, blocked: posts, errors: errs,
      passed: rows.filter(r => r.pass).length, total: rows.length };
    report.line = `${report.passed === report.total ? 'עבר' : 'נכשל: עבר'} ${report.passed}/${report.total}`;
    const jsonFile = path.join(outDir, `fidelity-${stamp}.json`), htmlFile = path.join(outDir, `fidelity-${stamp}.html`);
    fs.writeFileSync(jsonFile, JSON.stringify(report, null, 2));
    fs.writeFileSync(htmlFile, html(report, path.basename(shotDir)));
    report.files = { json: jsonFile, html: htmlFile, shots: shotDir };
    return report;
  } finally {
    await browser.close();
  }
}

function html(r, dir) {
  const rows = r.rows.map(x => `<tr class="${x.pass ? 'ok' : 'bad'}"><td>${x.pass ? '✓' : '✗'}</td><td><b>${esc(x.title)}</b><div class="d">${esc(x.detail)}</div>${x.diffs.length ? `<table class="df"><tr><th>מה</th><th>ישן</th><th>חדש</th><th>הצעה</th></tr>${x.diffs.map(d => `<tr><td>${esc(d.what)}</td><td>${esc(d.old ?? '')}</td><td>${esc(d.new ?? '')}</td><td>${esc(d.suggest)}</td></tr>`).join('')}</table>` : ''}</td></tr>`).join('');
  const shots = r.shots.map(s => `<figure><figcaption>${s.width}px · מ-${s.y}px · הפרש ${(s.ratio * 100).toFixed(4)}%</figcaption><div class="trio"><img src="${dir}/${s.old}" alt="ישן" loading="lazy"><img src="${dir}/${s.new}" alt="חדש" loading="lazy">${s.diff ? `<img src="${dir}/${s.diff}" alt="מפת הפרשים" loading="lazy">` : '<p>אין הפרש</p>'}</div></figure>`).join('');
  return `<!doctype html><html lang="he" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>דוח נאמנות</title>
<style>:root{color-scheme:light dark}body{font:15px/1.5 system-ui,sans-serif;margin:0 auto;padding:16px;max-width:1200px}h1{font-size:1.3rem}.line{font-size:1.2rem;font-weight:700}
table{border-collapse:collapse;width:100%}td,th{border-bottom:1px solid #8884;padding:6px;vertical-align:top;text-align:start}tr.ok>td:first-child{color:#1e7b3a;font-weight:700}tr.bad>td:first-child{color:#b3261e;font-weight:700}
.d{color:#777;font-size:.88rem;overflow-wrap:anywhere}.df{margin-top:6px;font-size:.85rem}.df td{overflow-wrap:anywhere;max-width:30ch}
.trio{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}.trio img{width:100%;border:1px solid #8884}figure{margin:16px 0}@media(max-width:700px){.trio{grid-template-columns:1fr}}</style></head>
<body><h1>דוח נאמנות (09 §4)</h1><p class="line">${esc(r.line)}</p><p>ישן: <code>${esc(r.old)}</code><br>חדש: <code>${esc(r.new)}</code><br>${esc(r.at)}${r.maxHeight ? ` · צולמו ${r.maxHeight}px העליונים` : ' · צילום מלא'}</p>
<table><tr><th></th><th>שורה</th></tr>${rows}</table><h2>צילומים (ישן · חדש · מפת הפרשים)</h2>${shots}
${r.blocked.length ? `<p>בקשות שנחסמו (לא נשלחו): ${esc(r.blocked.slice(0, 20).join(' · '))}</p>` : ''}</body></html>`;
}

// ── בדיקה עצמית — בלי האתר ─────────────────────────────────

async function selfTest() {
  const { spawn, execFileSync } = require('child_process');
  const os = require('os'), net = require('net'), http = require('http');
  const ROOT = path.resolve(__dirname, '..', '..');
  const OWNER = { sub: 'u-owner', name: 'Owner Test', email: 'owner@example.test', email_verified: true, platform_admin: true };
  const freePort = () => new Promise(r => { const s = net.createServer().listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => r(p)); }); });
  let failed = 0, ran = 0;
  const ok = (c, what, d = '') => { ran++; if (!c) failed++; console.log((c ? '✅ ' : '❌ ') + what + (d ? ' — ' + d : '')); return !!c; };
  const data = fs.mkdtempSync(path.join(os.tmpdir(), 'kol-m3f-')), wpDir = fs.mkdtempSync(path.join(os.tmpdir(), 'kol-m3fwp-')), out = fs.mkdtempSync(path.join(os.tmpdir(), 'kol-m3f-out-'));
  execFileSync('php', [path.join(__dirname, 'f3-seed.php'), data, path.join(data, 'old.html')]);
  const [port, wpPort, stPort, mutPort] = [await freePort(), await freePort(), await freePort(), await freePort()];
  const base = `http://127.0.0.1:${port}`, wp = `http://127.0.0.1:${wpPort}`;
  execFileSync('php', ['-r', `
    define('KOL_DATA_DIR', $argv[1]);
    foreach (['members', 'import', 'ai', 'agent', 'dev', 'preview', 'migrate', 'build'] as $l) require_once $argv[2] . "/lib/$l.php";
    setting_set('site_url', $argv[3]);
    file_put_contents($argv[4] . '/options.json', json_encode(['kol_maroko_keys' => pub_key_line(pub_key()), 'kol_v2_mock' => true]));
  `, data, ROOT, wp, wpDir]);
  const env = { ...process.env, PHP_CLI_SERVER_WORKERS: '6' };
  const procs = [spawn('php', ['-S', `127.0.0.1:${port}`, path.join(ROOT, 'tests', 'router.php')], { cwd: ROOT, env: { ...env, KOL_TEST_DATA: data }, stdio: 'ignore' }),
    spawn('php', ['-S', `127.0.0.1:${wpPort}`, path.join(ROOT, 'tests', 'fake-wp.php')], { cwd: ROOT, env: { ...env, KOL_TEST_WP: wpDir }, stdio: 'ignore' })];
  // החבילה שבאוויר אצל המקבל המדומה — מתיקייה סטטית; mutate: מילה חסרה ב-words.json, וצבע בכותרת הכרטיס
  const pkgServer = (port, mutate) => http.createServer((req, res) => { try { serve(req, res, mutate); } catch (e) { res.writeHead(500); res.end(String(e)); } }).listen(port, '127.0.0.1');
  const serve = (req, res, mutate) => {
    let man;
    try { man = JSON.parse(fs.readFileSync(path.join(wpDir, 'v2', 'live.json'), 'utf8')); } catch { res.writeHead(503); return res.end(); }
    let p = decodeURIComponent(new URL(req.url, 'http://x').pathname.slice(1));
    if (p === '' || p.endsWith('/')) p = p ? p + 'index.html' : man.entry;
    const f = man.files[p];
    if (!f) { res.writeHead(404); return res.end('404'); }
    let body = fs.readFileSync(path.join(wpDir, 'v2', 'blobs', f.sha256));
    if (mutate && p === 'data/words.json') { const j = JSON.parse(body); j.items.splice(4, 1); body = Buffer.from(JSON.stringify(j)); }
    if (mutate && /\.css$/.test(p)) body = Buffer.concat([body, Buffer.from('\n.kadisha-word-title { color: #c00 !important; }\n')]);
    res.writeHead(200, { 'Content-Type': f.type + (/^text\/|json/.test(f.type) ? '; charset=utf-8' : ''), 'Cache-Control': 'no-store' });
    res.end(body);
  };
  const servers = [pkgServer(stPort, false), pkgServer(mutPort, true)];
  // כל יציאה — גם שגיאה שלא נתפסה — הורגת את שרתי ה-PHP (אחרת הם נשארים יתומים)
  process.on('exit', () => procs.forEach(p => { try { p.kill(); } catch { /* כבר מת */ } }));
  let browser;
  try {
    for (let i = 0; i < 100; i++) { try { await fetch(base + '/api.php?action=embed_key'); await fetch(wp + '/'); break; } catch { await sleep(50); } }
    // ההסבה, והבנייה + השליחה של M1 — כמו בלשונית
    browser = await chromium.launch();
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    await ctx.addCookies([{ name: 'kol_test_user', value: Buffer.from(JSON.stringify(OWNER)).toString('base64'), url: base, httpOnly: true, sameSite: 'Lax' }]);
    const page = await ctx.newPage();
    page.on('dialog', d => d.accept());
    await page.goto(base + '/#/publish');
    await page.waitForSelector('.pk .btn.primary', { timeout: 60000 });
    await page.evaluate(() => api('pf_migrate', {}));
    await page.reload();
    await page.waitForSelector('.pk .btn.primary', { timeout: 60000 });
    const built = await page.evaluate(() => KolBuild.run({ prerender: true }));
    const sent = await page.evaluate(id => api('build_send', { id }), built.id);
    ok(sent.build.status === 'live', 'הכנה: הפרויקט הוסב, נבנה ונשלח למקבל המדומה', `גרסה ${sent.build.version}`);
    const tok = (await page.evaluate(() => api('pv_token'))).token;
    await browser.close(); browser = null;
    const OLD = `${base}/preview/${tok}/`, NEW = `http://127.0.0.1:${stPort}/`, BAD = `http://127.0.0.1:${mutPort}/`;
    const quick = { out, widths: [390, 768, 1280, 1920], maxH: 3000, hermetic: true };

    console.log('\n── עבר: התצוגה החיה של הפרויקט מול החבילה של M1 ──');
    const a = await compare(OLD, NEW, { ...quick, label: 'same', log: s => console.log('   ' + s) });
    console.log('   ' + a.line);
    // המהירות כאן — התצוגה החיה מול החבילה, לא העמוד של היום מול החדש; לכן לידיעה בלבד (ונמסרת לבקרה)
    const sp = a.rows.find(r => r.id === 'speed');
    console.log('   ℹ️ מהירות (לידיעה): ' + sp.detail);
    const rest = a.rows.filter(r => r.id !== 'speed');
    ok(rest.every(r => r.pass), 'אותו פרויקט ← כל השורות עוברות (חוץ מהמהירות — לידיעה)', `${rest.filter(r => r.pass).length}/${rest.length}`);
    ok(a.rows.length >= 18, 'כל שורות 09 §4 רצו', `${a.rows.length} שורות: ${a.rows.map(r => r.id).join(', ')}`);
    ok(fs.existsSync(a.files.json) && fs.existsSync(a.files.html) && fs.readdirSync(a.files.shots).some(f => f.endsWith('-old.png')), 'הדוח: JSON + HTML + צילומים');
    ok(/עבר \d+\/\d+/.test(fs.readFileSync(a.files.html, 'utf8')), 'הדוח הקריא — עם השורה "עבר N/N"');

    console.log('\n── נכשל: חבילה ששונתה בכוונה (מילה חסרה, צבע) ──');
    const b = await compare(OLD, BAD, { ...quick, label: 'mutated', log: s => console.log('   ' + s) });
    console.log('   ' + b.line);
    const bad = new Set(b.rows.filter(r => !r.pass).map(r => r.id));
    ok(b.passed < b.total && b.line.startsWith('נכשל'), 'השינוי ← "נכשל"', b.line);
    ok(bad.has('count-words'), 'השורה הנכונה: "מספרים: מילים" (1,496 ← 1,495)', b.rows.find(r => r.id === 'count-words').detail);
    ok(bad.has('list'), 'השורה הנכונה: הרשימה כולה — מכרטיס 5', (b.rows.find(r => r.id === 'list').diffs[0] || {}).what || '');
    ok([...bad].some(k => k.startsWith('visual-')), 'השורה הנכונה: מראה (הצבע)', [...bad].filter(k => k.startsWith('visual-')).join(', '));
    ok(b.rows.filter(r => !r.pass).every(r => r.diffs.length && r.diffs.every(d => d.suggest)), 'לכל הפרש — הצעה');
    ok(fs.readdirSync(b.files.shots).some(f => f.endsWith('-diff.png')), 'מפת הפרשים כתמונה');
  } catch (e) {
    failed++; console.log('❌ ' + (e.stack || e.message));
  } finally {
    if (browser) await browser.close();
    servers.forEach(s => s.close()); procs.forEach(p => p.kill());
    for (const d of [data, wpDir]) fs.rmSync(d, { recursive: true, force: true });
    if (!process.env.KOL_KEEP) fs.rmSync(out, { recursive: true, force: true }); else console.log('הדוחות: ' + out);
  }
  console.log(`\n${failed ? `נכשלו ${failed} מתוך ${ran}` : `כל ${ran} הבדיקות עברו`}`);
  process.exit(failed ? 1 : 0);
}

// ── שורת הפקודה ───────────────────────────────────────────

if (require.main === module) {
  const argv = process.argv.slice(2);
  if (argv.includes('--self-test')) { selfTest(); }
  else {
    const flag = (k, d) => { const i = argv.indexOf(k); return i >= 0 ? argv.splice(i, 2)[1] : d; };
    const out = flag('--out', path.resolve(__dirname, '..', '..', 'dev', 'm3'));
    const widths = flag('--widths', '390,768,1280,1920').split(',').map(Number);
    const maxH = Number(flag('--max-height', 0)) || Infinity;
    const label = flag('--label', '');
    const [oldUrl, newUrl] = argv;
    if (!oldUrl || !newUrl) { console.error('שימוש: node m3-fidelity.cjs <כתובת-ישנה> <כתובת-בדיקה> [--out dir] [--widths 390,768] [--max-height N] [--label x]\n        node m3-fidelity.cjs --self-test'); process.exit(2); }
    compare(oldUrl, newUrl, { out, widths, maxH, label, log: s => console.log(s) }).then(r => {
      console.log('\n' + r.line + '\n' + r.files.html);
      process.exit(r.passed === r.total ? 0 : 1);
    }).catch(e => { console.error('❌ ' + (e.stack || e.message)); process.exit(2); });
  }
}

module.exports = { compare };
