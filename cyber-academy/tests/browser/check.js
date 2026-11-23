/*
 * בדיקת דפדפן — כל המסכים וכל המצבים, ב-320 / 390 / 1280.
 * מקומית בלבד (בשרת אין Node):
 *   php -S 127.0.0.1:8765 -t . -d auto_prepend_file=$PWD/cyber-academy/tests/browser/dev-identity.php   (משורש הריפו; נתיב מוחלט)
 *   (מאז F11 גם php -S דורש זהות מורשית — dev-identity.php נותן אותה, ורק ב-cli-server)
 *   node cyber-academy/tests/browser/check.js [תיקיית-צילומים]
 * מצבי שגיאה מוזרקים רק כאן, דרך page.route של Playwright — לא דרך פרמטר בפרודקשן.
 * יוצא בקוד 1 על שגיאת קונסול, גלילה אופקית, או פס/מסך לא צפוי.
 */
'use strict';
const { chromium } = require('playwright');
const path = require('path');
const fs = require('fs');
const { execFileSync } = require('child_process');
// תשובות v2 — מאותו קוד שרת, מה-fixtures (tests/browser/v2-bodies.php)
const V2 = JSON.parse(execFileSync('php', [path.join(__dirname, 'v2-bodies.php')]).toString());

const BASE = process.env.CA_BASE || 'http://127.0.0.1:8765/cyber-academy/';
const OUT = process.argv[2] || null;
if (OUT) fs.mkdirSync(OUT, { recursive: true });
const WIDTHS = [[320, 700], [390, 860], [1280, 900]];
const SCREENS = ['', 'syllabus', 'track/sample-networks', 'progress', 'tasks/planned', 'tasks/active', 'tasks/blocked', 'tasks/completed', 'evidence', 'gaps', 'timeline'];
const contract = { schemaVersion: '1.0.0', commit: 'cc85ac1' };
const err = (status, code, extra = {}) => ({ status, body: { ok: false, code, error: 'הודעת בדיקה', source: 'github', isSample: false, contract, ...extra } });
const STATES = {
  forbidden: err(403, 'forbidden'),
  'forbidden-gate-html': { status: 403, raw: '<html><body>403</body></html>', expect: 'forbidden' },
  source_disabled: err(503, 'source_disabled'),
  source_unauthorized: err(502, 'source_unauthorized'),
  source_not_found: err(502, 'source_not_found'),
  source_unavailable: err(502, 'source_unavailable'),
  contract_invalid: err(422, 'contract_invalid', { source: 'local', isSample: true, errorCount: 60,
    errors: Array.from({ length: 50 }, (_, i) => `$.competencies[${i}].status: ערך לא מוכר "done_with_a_really_long_value_${i}" (מותר: not_assessed, gap_identified, learning, theory_demonstrated, practice_demonstrated, reassessment_required, mastered)`) }),
  contract_version: err(422, 'contract_version', { receivedVersion: '2.0.0' }),
  internal: err(500, 'internal'),
  offline: { abort: true, expect: 'offline' },
};

(async () => {
  const browser = await chromium.launch();
  const problems = [];
  let shots = 0;
  let titlesChecked = 0;
  // הנתונים האמיתיים של הדוגמה — לבסיס של "נתוני אמת" מזויפים
  const ctx0 = await browser.newContext();
  const sample = await (await ctx0.request.get(BASE + 'api/snapshot.php')).json();
  const emptySample = await (await ctx0.request.get(BASE + 'api/snapshot.php?demo=empty')).json();
  await ctx0.close();
  if (!sample.ok || sample.isSample !== true) problems.push('API: הדוגמה לא מסומנת isSample');

  async function page(w, h, scheme) {
    const p = await browser.newPage({ viewport: { width: w, height: h }, colorScheme: scheme || 'light' });
    p.on('pageerror', (e) => problems.push(`${w}: pageerror ${e.message}`));
    p.on('console', (m) => { if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) problems.push(`${w}: console ${m.text()}`); });
    return p;
  }
  async function verify(p, label, expect) {
    await p.waitForFunction(() => document.getElementById('main').getAttribute('aria-busy') === 'false', null, { timeout: 5000 })
      .catch(() => problems.push(`${label}: לא סיים לטעון`));
    const r = await p.evaluate(() => ({
      overflow: document.documentElement.scrollWidth > window.innerWidth,
      sample: !document.getElementById('sample-bar').hidden,
      real: !document.getElementById('real-bar').hidden,
      state: (document.querySelector('[data-state]') || {}).dataset?.state || null,
      retry: !!document.querySelector('[data-retry]'),
      // שם בשורת רשימה — לא נחתך (phone-first: השם הוא העיקר)
      cut: [...document.querySelectorAll('.use-tx > b')].filter((b) =>
        b.scrollWidth > b.clientWidth + 1 || getComputedStyle(b).textOverflow === 'ellipsis').map((b) => b.textContent.slice(0, 40)),
      titles: document.querySelectorAll('.use-tx > b').length,
    }));
    if (r.cut.length) problems.push(`${label}: ${r.cut.length} שמות נחתכים — ${r.cut.slice(0, 3).join(' | ')}`);
    titlesChecked += r.titles;
    if (r.overflow) problems.push(`${label}: גלילה אופקית`);
    if (expect.sample !== undefined && r.sample !== expect.sample) problems.push(`${label}: פס דוגמה ${r.sample}, צפוי ${expect.sample}`);
    if (expect.real !== undefined && r.real !== expect.real) problems.push(`${label}: פס אמת ${r.real}, צפוי ${expect.real}`);
    if (r.sample && r.real) problems.push(`${label}: שני הפסים יחד`);
    if (expect.state !== undefined && r.state !== expect.state) problems.push(`${label}: מצב ${r.state}, צפוי ${expect.state}`);
    if (expect.state && expect.state !== 'real-empty' && !r.retry) problems.push(`${label}: אין "נסה שוב"`);
    if (expect.noNumbers) {
      // אמת ריק: אין מונה, מדד או ספרה — לא בתוכן ולא בלשוניות; הפס הוא פס "ריק"
      const n = await p.evaluate(() => ({
        digits: (document.getElementById('main').innerText + document.getElementById('tabs').innerText).match(/[0-9٠-٩]/g) || [],
        bar: document.getElementById('real-bar').textContent,
        barEmpty: document.getElementById('real-bar').classList.contains('empty'),
        measures: document.querySelectorAll('.measure, .dist, .seg-tabs, .tiles, .track').length,
      }));
      if (n.digits.length) problems.push(`${label}: ספרות בדף (${n.digits.join('')})`);
      if (!n.barEmpty || !/ה-snapshot ריק|אין עדיין סילבוס מאושר/.test(n.bar) || /נתוני אמת/.test(n.bar)) problems.push(`${label}: פס לא נכון: ${n.bar}`);
      if (n.measures) problems.push(`${label}: ${n.measures} רכיבי מדד/מונה`);
    }
    if (expect.title) {
      const t = await p.textContent('#title');
      if (t !== expect.title) problems.push(`${label}: כותרת "${t}", צפוי "${expect.title}"`);
    }
    if (expect.has || expect.lacks) {
      const txt = await p.evaluate(() => document.getElementById('main').innerText);
      for (const h of expect.has || []) if (!txt.includes(h)) problems.push(`${label}: חסר "${h}"`);
      for (const l of expect.lacks || []) if (txt.includes(l)) problems.push(`${label}: לא אמור להופיע "${l}"`);
    }
    if (OUT) { await p.screenshot({ path: path.join(OUT, label.replace(/[^\w.-]+/g, '_') + '.png'), fullPage: true }); shots++; }
  }

  for (const [w, h] of WIDTHS) {
    // 1. כל המסכים — נתוני הדוגמה האמיתיים מה-API
    const p = await page(w, h);
    for (const s of SCREENS) {
      await p.goto(BASE + '#/' + s);
      await verify(p, `${w}-screen-${s || 'home'}`, { sample: true, real: false, state: null });
    }
    // מצב ריק
    await p.goto(BASE + '?demo=empty#/');
    await verify(p, `${w}-empty`, { sample: true, real: false, state: null });
    await p.close();

    // 2. נתוני אמת (מוזרק) — פס אמת, בלי פס דוגמה
    const pr = await page(w, h);
    await pr.route('**/api/snapshot.php*', (r) => r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({
      ...sample, source: 'github', isSample: false, sourceInfo: { commit: 'abcdef0123456789abcdef0123456789abcdef01', collectedAt: '2026-11-23T10:00:00Z' } }) }));
    for (const s of ['', 'syllabus', 'track/sample-networks']) {
      await pr.goto(BASE + '#/' + s);
      await verify(pr, `${w}-real-${s || 'home'}`, { sample: false, real: true, state: null });
    }
    const realText = await pr.textContent('#real-bar');
    if (!/קומיט abcdef0/.test(realText) || !/נאסף ב-/.test(realText)) problems.push(`${w}: טקסט פס האמת: ${realText}`);
    await pr.close();

    // 2א. אמת ריק (c4e7a8d) — מאגר מחובר, snapshot ריק: לא התקדמות ולא סילבוס
    const pz = await page(w, h);
    await pz.route('**/api/snapshot.php*', (r) => r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({
      ...emptySample, source: 'github', isSample: false, sourceInfo: { commit: 'c4e7a8dedabfff552a161005e166ab5c25273375', collectedAt: '2026-11-23T10:00:00Z' } }) }));
    for (const s of SCREENS) {
      await pz.goto(BASE + '#/' + s);
      await verify(pz, `${w}-real-empty-${s || 'home'}`, { sample: false, real: true, state: 'real-empty', noNumbers: true });
    }
    await pz.close();

    // 2ב. חוזה v2 — מוזרק
    const v2 = async (key, routes, expectFor) => {
      const pv = await page(w, h);
      await pv.route('**/api/snapshot.php*', (r) => r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(V2[key]) }));
      for (const s of routes) { await pv.goto(BASE + '#/' + s); await verify(pv, `${w}-v2-${key}-${s.replace(/\//g, '_') || 'home'}`, expectFor(s)); }
      await pv.close();
    };
    const NO_PCT = ['%', '0 מתוך'];
    await v2('excerpt', ['', 'syllabus', 'track/01', 'module/01.01', 'progress', 'tasks', 'evidence', 'gaps', 'timeline'], (s) => ({
      sample: true, real: false, state: null, lacks: [...NO_PCT, 'סילבוס מאושר'],
      has: s === '' || s === 'progress' || s === 'evidence' ? ['אין הערכות מתועדות']
        : s === 'syllabus' || s === 'track/01' ? ['מוצע · לא אושר'] : s === 'module/01.01' ? ['מעבדה מורשית בלבד', 'מעבדה', 'ניתוח', 'תאוריה'] : [] }));
    await v2('full', ['', 'syllabus', 'track/tr-1', 'track/tr-2', 'module/m-1', 'progress', 'tasks/blocked', 'evidence', 'gaps', 'timeline'], (s) => ({
      sample: true, real: false, state: null, lacks: ['%'], has: s === 'track/tr-2' ? ['מוצע · לא אושר'] : s === 'tasks/blocked' ? ['ממתינה ל'] : [] }));
    await v2('github-full', ['', 'syllabus', 'track/tr-1', 'module/m-1', 'tasks', 'evidence'], (s) => ({
      sample: false, real: true, state: null, lacks: ['%', 'fixture · track 2', 'evidence/tr-1'] }));
    await v2('github-approved', ['', 'syllabus', 'track/01', 'module/01.01', 'progress', 'evidence'], (s) => ({
      sample: false, real: true, state: null, lacks: ['%', '0 מתוך', 'מוצע · לא אושר'],
      has: s === '' || s === 'progress' || s === 'evidence' ? ['אין הערכות מתועדות'] : s === 'syllabus' ? ['סילבוס מאושר'] : [], title: s === 'syllabus' ? 'תוכנית האקדמיה' : undefined }));
    await v2('github-excerpt', SCREENS.concat(['module/01.01']), () => ({ sample: false, real: true, state: 'real-empty', noNumbers: true, has: ['אין עדיין סילבוס מאושר'] }));

    // 3. מצבי שגיאה
    for (const [name, st] of Object.entries(STATES)) {
      const pe = await page(w, h);
      await pe.route('**/api/snapshot.php*', (r) => st.abort ? r.abort('internetdisconnected')
        : r.fulfill({ status: st.status, contentType: st.raw ? 'text/html' : 'application/json', body: st.raw || JSON.stringify(st.body) }));
      await pe.goto(BASE + '#/');
      await verify(pe, `${w}-state-${name}`, { state: st.expect || name, real: false });
      await pe.close();
    }

    // 4. טעינה — שלד, לא "טוען…" ריק
    const pl = await page(w, h);
    await pl.route('**/api/snapshot.php*', () => {}); // לא עונה לעולם
    await pl.goto(BASE + '#/');
    await pl.waitForSelector('.skel', { timeout: 3000 }).catch(() => problems.push(`${w}: אין שלד טעינה`));
    if (OUT) { await pl.screenshot({ path: path.join(OUT, `${w}-state-loading.png`) }); shots++; }
    await pl.close();
  }

  // 5. מצב כהה — טלפון
  const pd = await page(390, 860, 'dark');
  for (const s of ['', 'track/sample-networks']) { await pd.goto(BASE + '#/' + s); await verify(pd, `390-dark-${s || 'home'}`, { sample: true }); }
  await pd.close();

  await browser.close();
  console.log(problems.length ? '✗ ' + problems.length + ' בעיות:\n' + problems.join('\n') : `✓ אין בעיות (${shots} צילומים, ${titlesChecked} שמות ברשימות נבדקו שאינם נחתכים)`);
  process.exit(problems.length ? 1 : 0);
})();
