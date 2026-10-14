// סריקה עיצובית של כל המסכים — טלפון ומחשב: גלישה הצידה, טקסט שנחתך או בורח
// מהמסגרת, אלמנטים מחוץ למסך, "null"/"undefined" על המסך, תיבות מכווצות, דף שלא צויר.
//
// הרצה (מתיקיית השורש של הריפו, עם Playwright):
//   export E2E_DATA=$(mktemp -d) OUT=$(mktemp -d) PW=$(npm root -g)/playwright
//   php -S 127.0.0.1:8766 document-templates/tests/ui/router.php &
//   node document-templates/tests/ui/audit.js
// כל מסך: שורה עם ✓, או רשימת התקלות. צילומי מסך נשמרים ב-$OUT/audit. ONLY=לוג — רק מסכים בשם הזה.
const { chromium } = require(process.env.PW);
const fs = require('fs');
const BASE = 'http://127.0.0.1:8766/document-templates/';
const OUT = process.env.OUT + '/audit';
const HERE = __dirname;
const CRM = 'http://127.0.0.1:8767';
const mails = () => fs.readFileSync(process.env.E2E_DATA + '/mail.log', 'utf8').trim().split('\n').map(JSON.parse);
const ONLY = process.env.ONLY ? process.env.ONLY.split(',') : null;

function scan() {
  const W = document.documentElement.clientWidth;
  const out = [];
  const desc = (n) => {
    const cls = typeof n.className === 'string' && n.className ? '.' + n.className.trim().split(/\s+/).slice(0, 2).join('.') : '';
    const t = (n.innerText || n.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 40);
    return n.tagName.toLowerCase() + cls + (t ? ` "${t}"` : '');
  };
  const scroller = (n) => n.closest('.table-wrap, .zoom-stage, .seg-tabs, .tabs, pre, .pages, .seg, .legend, [data-scroll]');
  if (document.documentElement.scrollWidth > W + 1) out.push(`הדף גולש הצידה: ${document.documentElement.scrollWidth} > ${W}`);
  const root = document.querySelector('dialog[open]') || document.body;
  for (const n of root.querySelectorAll('*')) {
    if (n.closest('[hidden]') || n.tagName === 'OPTION' || n.closest('svg')) continue;
    const st = getComputedStyle(n);
    if (st.display === 'none' || st.visibility === 'hidden') continue;
    const r = n.getBoundingClientRect();
    if (!r.width || !r.height) continue;
    if (!scroller(n) && (r.right > W + 1 || r.left < -1) && !n.closest('.user-menu')) out.push('מחוץ למסך: ' + desc(n));
    // תוכן שבורח מהקופסה שלו (או נחתך בלי "…")
    const own = [...n.childNodes].some(c => c.nodeType === 3 && c.textContent.trim());
    if ((own || n.matches('.tile, button, .badge, .chip, td, th, dd, label, .use-tx')) && n.scrollWidth > n.clientWidth + 2
        && st.overflowX !== 'auto' && st.overflowX !== 'scroll' && st.textOverflow !== 'ellipsis' && !scroller(n)
        && !['INPUT', 'TEXTAREA', 'SELECT'].includes(n.tagName)) {
      out.push((st.overflowX === 'hidden' ? 'נחתך: ' : 'בורח מהמסגרת: ') + desc(n) + ` (${n.scrollWidth}>${n.clientWidth})`);
    }
  }
  // תיבת קלט שנדחקה — רואים רק כמה פיקסלים ממה שכותבים
  for (const i of root.querySelectorAll('input:not([type=checkbox]):not([type=radio]):not([type=file]):not([type=color]), select, textarea')) {
    if (!i.offsetParent || i.closest('.pages, .zoom-stage, .visually-hidden, .grid')) continue;
    const w = i.getBoundingClientRect().width;
    if (w > 0 && w < 70) out.push(`תיבת קלט צרה מדי (${Math.round(w)}px): ` + (i.getAttribute('aria-label') || i.name || i.placeholder || i.tagName.toLowerCase()));
  }
  for (const ta of root.querySelectorAll('.block-text')) if (ta.offsetParent && ta.offsetHeight < 12) out.push('תיבת טקסט מכווצת לגובה 0');
  for (const c of root.querySelectorAll('.page canvas')) if (c.offsetParent && c.getBoundingClientRect().width < 50) out.push('דף לא צויר (רוחב 0)');
  for (const o of root.querySelectorAll('.fill output.on-page')) if (o.scrollWidth > o.clientWidth + 2) out.push('ערך על הדף לא נכנס בשדה: ' + o.textContent);
  const text = root.innerText || '';
  for (const bad of ['null', 'undefined', 'NaN']) if (new RegExp('(^|[^\\w])' + bad + '([^\\w]|$)').test(text)) out.push(`מופיע "${bad}" על המסך`);
  const obj = /\[object [A-Za-z]+\]/.exec(text);
  if (obj) out.push(`מופיע "${obj[0]}" על המסך`);
  return [...new Set(out)].slice(0, 15);
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const crm = require('child_process').spawn('php', ['-S', '127.0.0.1:8767', HERE + '/../fixtures/crm.php'], { env: { ...process.env, HOOK_LOG: '/dev/null' }, stdio: 'ignore' });
  await new Promise(r => setTimeout(r, 500));
  const b = await chromium.launch();
  if (!fs.existsSync(process.env.OUT + '/test.pdf')) {   // PDF לדוגמה, מיוצר בכרום עצמו
    const g = await b.newPage();
    await g.setContent('<html dir="rtl"><body style="font:16px Arial"><h1>הסכם שכירות</h1><p>שם השוכר: ____________</p><p>טלפון: __________</p></body></html>');
    fs.writeFileSync(process.env.OUT + '/test.pdf', await g.pdf({ format: 'A4' }));
    await g.close();
  }
  const ctx = await b.newContext({ viewport: { width: 1280, height: 900 } });
  const p = await ctx.newPage();
  const errs = []; p.on('pageerror', e => errs.push(e.message));
  // ── נתונים: שמות ארוכים בכוונה ──
  await p.goto(BASE + '#register');
  await p.fill('[name=name]', 'מלכיאל בן אברהם הכהן'); await p.fill('[name=email]', 'malkiel.long.address@example.com');
  await p.fill('[name=password]', 'pass12345'); await p.fill('[name=password2]', 'pass12345');
  await p.click('button[type=submit]'); await p.waitForSelector('.notice.ok');
  await p.goto(BASE + mails().pop().body.match(/(#verify=[0-9a-f]+)/)[1]); await p.waitForSelector('.notice.ok');
  await p.fill('[name=email]', 'malkiel.long.address@example.com'); await p.fill('[name=password]', 'pass12345');
  await p.click('button[type=submit]'); await p.waitForSelector('text=+ תבנית חדשה');
  const ids = await p.evaluate(async (pdf64) => {
    const api = window.DT.api;
    const fd = new FormData(); fd.append('name', 'הסכם שכירות לדירת מגורים ארוכת טווח עם נספחים'); fd.append('mode', 'locked');
    fd.append('file', new Blob([Uint8Array.from(atob(pdf64), c => c.charCodeAt(0))], { type: 'application/pdf' }), 'a.pdf');
    const tid = (await api('tpl_create', fd)).id;
    const F = (key, type, label, y, x = {}) => ({ key, type, label, page: 0, x: 0.45, y, w: 0.3, h: 0.03, ...x });
    await api('tpl_save', { id: tid, numbering: { scheme: 'prefix_year_seq', prefix: 'שכ', digits: 4 }, tags: ['נדל"ן', 'חוזים ארוכים'], schema: { pages: [{ w: 595, h: 842 }],
      roles: [{ key: 'owner', label: 'אני' }, { key: 'client', label: 'השוכר הראשי של הדירה' }],
      fields: [F('num', 'system', 'מספר המסמך', 0.05, { source: 'number' }), F('name', 'text', 'שם מלא של השוכר כפי שמופיע בתעודת הזהות', 0.1, { required: true, role: 'client' }),
        F('size', 'select', 'גודל הדירה', 0.15, { options: [{ value: 'S', label: 'קטנה' }, { value: 'L', label: 'גדולה מאוד עם מרפסת' }] }),
        F('price', 'number', 'מחיר', 0.2), F('vat', 'calc', 'מע"מ', 0.25, { formula: 'מחיר * 0.18', format: 'money' }),
        F('sig', 'signature', 'חתימת השוכר', 0.3, { role: 'client', h: 0.06 }), F('agree', 'checkbox', 'מאשר', 0.4)] } });
    const did = (await api('doc_create', { template_id: tid, title: 'הסכם שכירות — משפחת כהן־לוי, רחוב הנביאים 123 דירה 4' })).id;
    await api('doc_save', { id: did, values: { price: '4500', size: 'L' } });
    await api('contact_save', { name: 'יהונתן בן־ציון אלמוג־רוזנטל', phone: '050-1234567', email: 'yehonatan.long@example.com', tags: ['דיירים', 'ספקים'] });
    const aid = (await api('tpl_create_authored', { name: 'הצעת מחיר לשיפוץ' })).id;
    await api('src_save', { name: 'לקוחות מה-CRM של החברה', url: 'http://127.0.0.1:8767/customers', path: 'data.items', headers: 'Authorization: Bearer s3cret', value_key: 'id', label_key: 'name' });
    await api('key_create', { name: 'מערכת ההזמנות של האתר', webhook: 'http://127.0.0.1:8767/hook' });
    const parts = (await api('doc_get', undefined, { id: did })).document.participants;
    await api('part_contact', { id: did, participant_id: parts[0].id, name: 'דנה', email: 'dana@example.com' });
    const link = (await api('part_link', { id: did, participant_id: parts[0].id, via: 'link' })).url;
    try { await api('doc_get', undefined, { id: 9999 }); } catch (e) { /* שורת שגיאה ללוג */ }
    return { tid, did, aid, link };
  }, fs.readFileSync(process.env.OUT + '/test.pdf').toString('base64'));

  const go = (h) => async () => { await p.goto(BASE + '#' + h); await p.reload(); await p.waitForTimeout(1000); };
  const click = (sel) => async () => { await p.click(sel); await p.waitForTimeout(500); };
  const S = [
    ['בית', go('home')],
    ['בית — פעולות תבנית', click('.list li button[aria-label="פעולות"], [aria-label="פעולות"]')],
    ['בית — תבנית חדשה', async () => { await go('home')(); await p.click('text=+ תבנית חדשה'); await p.waitForTimeout(400); }],
    ['מסמכים', go('docs')],
    ['מסמכים — סינון', click('[aria-label="סינון"]')],
    ['מסמך — על הדף', async () => { await go('doc=' + ids.did)(); await p.waitForSelector('.page canvas', { timeout: 15000 }).catch(() => {}); }],
    ['מסמך — טופס', click('.mode-seg label:has-text("טופס")')],
    ['מסמך — יומן', click('[aria-label="יומן"]')],
    ['מסמך — משתתפים', click('[aria-label="משתתפים"]')],
    ['מסמך — שיתוף', click('[aria-label="שיתוף"]')],
    ['אנשי קשר', go('contacts')],
    ['איש קשר — כרטיס, ממלא במסמך', async () => { await p.click('.list.contacts .use-row:has-text("דנה")'); await p.waitForTimeout(600); }],
    ['אנשי קשר — ייבוא', async () => { await p.keyboard.press('Escape'); await p.waitForTimeout(200); await p.click('button:has-text("ייבוא")'); await p.waitForTimeout(300); }],
    ['מסמך — משתתפים', async () => { await p.keyboard.press('Escape'); await go('doc=' + ids.did)(); await p.click('[aria-label="משתתפים"]'); await p.waitForTimeout(500); }],
    ['חיבורים — מקורות', async () => { await go('connections')(); await p.click('.seg-tabs [data-tab=sources]'); await p.waitForTimeout(500); }],
    ['חיבורים — מקור', click('.card-list .use-row')],
    ['חיבורים — מפתחות', async () => { await go('connections')(); await p.click('.seg-tabs [data-tab=keys]'); await p.waitForTimeout(500); }],
    ['חיבורים — מפתח', click('.card-list .use-row')],
    ['חיבורים — תיעוד', async () => { await go('connections')(); await p.click('.seg-tabs [data-tab=docs]'); await p.waitForTimeout(500);
      await p.selectOption('.docs-api select', String(ids.tid)); await p.waitForTimeout(500); }],
    ['עורך — עיצוב', async () => { await go('tpl=' + ids.tid)(); await p.waitForSelector('.page canvas', { timeout: 15000 }).catch(() => {}); await p.click('.seg-tabs [data-tab=design]'); }],
    ['עורך — + שדה', click('.design-bar button.primary')],
    ['עורך — שדות', async () => { await p.keyboard.press('Escape'); await p.click('.seg-tabs [data-tab=fields]'); await p.waitForTimeout(300); }],
    ['עורך — הגדרות שדה', click('.field-list button:has-text("שם מלא")')],
    ['עורך — מבנה תצוגה', async () => { await p.keyboard.press('Escape'); await p.click('.field-list button:has-text("מחיר")'); await p.waitForTimeout(300);
      await p.selectOption('dialog select[aria-label="מבנה מוכן"]', '*'); await p.click('dialog .fmt-help summary'); await p.waitForTimeout(200); }],
    ['עורך — בחירה', async () => { await p.keyboard.press('Escape'); await p.click('.field-list button:has-text("גודל")'); await p.waitForTimeout(400); }],
    ['עורך — ממלאים', async () => { await p.keyboard.press('Escape'); await p.click('.seg-tabs [data-tab=roles]'); await p.waitForTimeout(300); }],
    ['עורך — הגדרות', click('.seg-tabs [data-tab=settings]')],
    ['עורך — נפתח על לשונית אחרת', async () => { await p.evaluate(() => sessionStorage.setItem('dt-editor-tab', 'settings')); await go('tpl=' + ids.tid)();
      await p.click('.seg-tabs [data-tab=design]'); await p.waitForTimeout(1500); }],
    ['עורך — נכתב בכלי, מלשונית אחרת', async () => { await p.evaluate(() => sessionStorage.setItem('dt-editor-tab', 'fields')); await go('tpl=' + ids.aid)();
      await p.click('.seg-tabs [data-tab=design]'); await p.waitForTimeout(500); }],
    ['עורך — נכתב בכלי', async () => { await go('tpl=' + ids.aid)(); await p.click('.seg-tabs [data-tab=design]'); await p.waitForTimeout(400); }],
    ['פיתוח — משתמשים', go('dev?tab=users')],
    ['פיתוח — משתמש', click('.list .use-row')],
    ['פיתוח — מטלות', go('dev?tab=items')],
    ['פיתוח — מטלה עם שיח', async () => {
      await p.evaluate(async () => { const r = await window.DT.api('dev-items'); const c = r.items.find(x => x.status !== 'closed').code;
        await window.DT.api('dev-comment', { code: c, body: 'הערה ארוכה כדי לבדוק שבירת שורות: ' + 'מילה '.repeat(40) });
        location.hash = '#dev?tab=items&code=' + c; });
      await p.waitForTimeout(800); }],
    ['פיתוח — מטלה חדשה', async () => { await go('dev?tab=items')(); await p.click('button:has-text("+ מטלה חדשה")'); await p.waitForTimeout(300); }],
    ['פיתוח — העתקה לצ\'אט', async () => { await p.keyboard.press('Escape'); await p.click('button:has-text("העתקה לצ\'אט")'); await p.waitForTimeout(300); }],
    ['פיתוח — גישה ל-Claude', async () => { await p.keyboard.press('Escape'); await p.click('button:has-text("גישה ל-Claude")');
      await p.click('dialog button:has-text("+ אסימון חדש")'); await p.waitForTimeout(500); }],
    ['פיתוח — לוג', async () => { await p.keyboard.press('Escape'); await go('dev?tab=log')(); }],
    ['פיתוח — אכיפה', go('dev?tab=force')],
    ['פיתוח — אכיפה פעולות', click('.dev-body .card-list .use-row')],
    ['פיתוח — מצב', go('dev?tab=health')],
    ['תפריט משתמש', async () => { await go('home')(); await p.click('#me-btn'); await p.waitForTimeout(300); }],
    ['הגדרות משתמש', click('.user-menu .menu-item:has-text("הגדרות משתמש")')],
    ['הנפקה מאקסל', async () => { await go('home')(); await p.click('[aria-label="פעולות"]'); await p.waitForTimeout(300); await p.click('dialog .tile:has-text("מאקסל")'); await p.waitForTimeout(400); }],
    ['ממלא בקישור', async () => { await p.goto(ids.link.replace(/^https?:\/\/[^/]+\/[^#]*?fill\.html/, BASE + 'fill.html')); await p.waitForTimeout(1500); }],
  ];
  const report = [];
  for (const [vw, vh, tag] of [[390, 844, 'טלפון'], [1280, 900, 'מחשב']]) {
    await p.setViewportSize({ width: vw, height: vh });
    let i = 0;
    for (const [name, act] of S) {
      i++;
      if (ONLY && !ONLY.some(o => name.includes(o))) continue;
      try { await act(); } catch (e) { report.push(`[${tag}] ${name}: ⚠ לא נפתח (${e.message.split('\n')[0].slice(0, 80)})`); continue; }
      const issues = await p.evaluate(scan);
      await p.screenshot({ path: `${OUT}/${tag}-${String(i).padStart(2, '0')}.png` });
      report.push(`[${tag}] ${name}: ` + (issues.length ? '\n    - ' + issues.join('\n    - ') : '✓'));
    }
  }
  console.log(report.join('\n'));
  console.log(errs.length ? 'שגיאות JS: ' + [...new Set(errs)].join(' | ') : 'אין שגיאות JS');
  process.exitCode = report.some(l => !l.endsWith('✓')) || errs.length ? 1 : 0;
  await b.close(); crm.kill();
})().catch(e => { console.error('FAIL', e.message); process.exit(1); });
