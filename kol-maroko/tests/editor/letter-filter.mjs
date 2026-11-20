/**
 * S2 — סינון לפי אות ראשונה (א-ב) ולפי נושא, מצטברים:   node kol-maroko/tests/editor/letter-filter.mjs   (רץ גם מ-tests/cases/99-s2-filters.php)
 *
 * הבעלים: "אני רוצה שיהיה באתר גם אופציית סינון לפי נושאים או לפי אלף בית."
 * kol-data.js (מה שרץ אצל הגולש) ו-bind.js (הקנבס בעורך) רצים כאן על אותו DOM מינימלי ואותם נתונים:
 *   · האות הראשונה: בלי ניקוד ותנועות, סופיות מקופלות (ך←כ…), תו שאינו אות — מדולג, לטינית — גדולה ובלי סימן.
 *   · הכפתורים: רק אותיות שיש להן מילים, בסדר ה-Collator של הרשימה, עם {count} — זהים בשני המימושים.
 *   · נושא + אות + חיפוש — מצטברים; "הכול" מאפס רק את המכל שלו; aria-pressed בכל מכל לפי הסינון שלו.
 *   · רשימה שנכתבה מראש (data-kol-prerendered) — הכרטיסים הכתובים מוחלפים, והסינון עובד.
 *   · הבלוק שהעורך מוסיף ("➕ סינון לפי א-ב") על ההעתק של העמוד האמיתי — עובד עם kol-data.js בלי שינוי נוסף.
 */
import fs from 'node:fs'; import path from 'node:path'; import vm from 'node:vm'; import { fileURLToPath } from 'node:url';
import { docFrom, serialize, decode } from './mini-dom.mjs';
const here = path.dirname(fileURLToPath(import.meta.url)), root = path.resolve(here, '../..');
const KD = fs.readFileSync(path.join(root, 'assets/project-seed/kol-data.js'), 'utf8');
vm.runInThisContext(fs.readFileSync(path.join(root, 'assets/js/editor/bind.js'), 'utf8'), { filename: 'bind.js' });
globalThis.window = globalThis;
vm.runInThisContext(fs.readFileSync(path.join(root, 'assets/js/editor/data.js'), 'utf8'), { filename: 'data.js' });
const B = globalThis.KolBind, DP = globalThis.KolDataPanel;
let failed = 0, ran = 0;
const ok = (cond, what, got) => { ran++; if (cond) console.log('✅ ' + what); else { failed++; console.log('❌ ' + what + (got !== undefined ? '\n   התקבל: ' + JSON.stringify(got).slice(0, 1200) : '')); } };
const J = x => JSON.stringify(x);

const DATA = {
  topics: [{ id: 'food', label: 'אוכל' }, { id: 'family', label: 'משפחה' }, { id: 'home', label: 'בית' }],
  items: [
    { id: 1, word: 'אַבָּא', meaning: 'אבא', topics: ['family'] },
    { id: 2, word: 'אִמָּא', meaning: 'אמא', topics: ['family', 'home'] },
    { id: 3, word: 'בַּיִת', meaning: 'בית', topics: ['home'] },
    { id: 4, word: '"גמל"', meaning: 'מרכאות בהתחלה', topics: ['food'] },
    { id: 5, word: 'ךְ', meaning: 'כף סופית', topics: [] },
    { id: 6, word: 'מִן', meaning: '', topics: ['food'] },
    { id: 7, word: 'ם-סופית', meaning: '', topics: ['food'] },
    { id: 8, word: '12 לחם', meaning: 'ספרה בהתחלה', topics: ['food', 'home'] },
    { id: 9, word: '   ', meaning: 'אין אות', topics: ['food'] },
    { id: 10, word: 'בָּשָׂר', meaning: 'אוכל', topics: ['food'] },
  ],
};

async function runKd(body, data = DATA) {
  const doc = docFrom(body), win = { document: doc, setTimeout, console, Intl, Date, URL, Audio: class {}, location: { href: 'http://t.test/index.html', pathname: '/index.html' }, fetch: async () => ({ ok: true, json: async () => JSON.parse(JSON.stringify(data)) }) };
  win.window = win; win.parent = win;
  vm.runInNewContext(KD, Object.assign(Object.create(null), win, { window: win }), { filename: 'kol-data.js' });
  for (let i = 0; i < 20; i++) await new Promise(r => setTimeout(r, 0));
  const click = el => doc.listeners.click.forEach(fn => fn({ target: el }));
  const type = (el, v) => { el.value = v; doc.listeners.input.forEach(fn => fn({ target: el })); };
  return { doc, KD: win.KolData, click, type };
}
const shownIds = doc => doc.querySelectorAll('[data-kol-list="words"] i').map(e => +e.textContent);
const btns = (doc, sel) => doc.querySelectorAll(sel + ' [data-kol-filter-value]');

// ── 1. גרסה ──
{
  const a = await runKd('<div></div>');
  ok(a.KD.version === '1.6.0', 'kol-data.js בגרסה 1.6.0 (כך מופיע "🔄 עדכן את kol-data.js לגרסה 1.6.0")', a.KD.version);
  ok(/גרסה 1\.6\.0\)/.test(KD.split('\n')[1]) && KD.includes('data-kol-filter="words:word@letter"'), 'מתועד בראש הקובץ: הגרסה והתכונה word@letter');
}

// ── 2. האות הראשונה ──
{
  const a = await runKd('<div></div>');
  const cases = [['שָׁלוֹם', 'ש'], ['ךְ', 'כ'], ['ם-סופית', 'מ'], ['ןוּן', 'נ'], ['ףא', 'פ'], ['ץ', 'צ'], ['"גמל"', 'ג'], ["'אבא", 'א'], ['12 לחם', 'ל'], ['   ', ''], ['', ''], [null, ''], ['123', ''],
    ['‏שלום', 'ש'], ['<b>בית</b>', 'ב'], ['élan', 'E'], ['zeit', 'Z'], ['أهلا', 'ا'], ['مَاءٌ', 'م'], ['ﬠין', 'ע']];
  for (const [v, want] of cases) {
    const k = a.KD.firstLetter(v), b = B.firstLetter(v);
    ok(k === want && b === want, 'אות ראשונה: ' + J(v) + ' → "' + want + '"', { kolData: k, bind: b });
  }
}

const FILTERS = '<div class="t" data-kol-filter="words:topics" data-kol-active-class="is-active"><button data-kol-filter-value="">הכול</button><template><button><span data-kol-text="label"></span> <small data-kol-text="count"></small></button></template></div>'
  + '<div class="l" data-kol-filter="words:word@letter" data-kol-active-class="is-active"><button data-kol-filter-value="">הכול</button><template><button><span data-kol-text="label"></span> <small data-kol-text="count"></small></button></template></div>'
  + '<input data-kol-search="words" data-kol-search-fields="word meaning">';
const LIST = (attrs = '') => '<div data-kol-list="words"' + attrs + '><template><i data-kol-text="id"></i></template></div>';

// ── 3. כפתורי האותיות: רק מה שיש, בסדר ה-א"ב, עם מספר — זהים ב-bind.js ──
{
  const body = FILTERS + LIST(' data-kol-sort-lang="he"'), a = await runKd(body);
  const letters = btns(a.doc, '.l').map(b => b.getAttribute('data-kol-filter-value'));
  ok(J(letters) === J(['', 'א', 'ב', 'ג', 'כ', 'ל', 'מ']), 'אותיות: "הכול" ואחריו רק אותיות שיש להן מילים, לפי הסדר (ך←כ, ם←מ, ספרה ומרכאות מדולגות)', letters);
  const counts = btns(a.doc, '.l').slice(1).map(b => b.querySelector('small').textContent);
  ok(J(counts) === J(['2', '2', '1', '1', '1', '2']), '{count} לכל אות', counts);
  const st = { items: B.itemsOf(DATA), labels: B.labelsOf(DATA) }, d2 = docFrom(body), f2 = d2.querySelector('.l'), tpl = B.templateOf(f2);
  for (const layer of B.filterLayers(st, 'word@letter', 'he')) for (const n of B.build(tpl, [layer], st)) { if (!n.hasAttribute('data-kol-filter-value')) n.setAttribute('data-kol-filter-value', layer.value); f2.appendChild(n); }
  B.markActive(f2, f2.querySelectorAll('[data-kol-filter-value]'), '');
  const ka = a.doc.querySelector('.l').childNodes.filter(n => !(n.nodeType === 1 && n.localName === 'template')).map(serialize).join('');
  const kb = f2.childNodes.filter(n => !(n.nodeType === 1 && n.localName === 'template')).map(serialize).join('');
  ok(ka === kb, 'bind.js מצייר בקנבס את אותם כפתורי אותיות (תווית, מספר, סדר, aria-pressed)', { ka, kb });
  // שפה אחרת — סדר אחר, אותו ה-Collator בשני המימושים
  const LAT = { items: [{ id: 1, word: 'zeit' }, { id: 2, word: 'Ábaco' }, { id: 3, word: 'ماء' }, { id: 4, word: 'бык' }, { id: 5, word: 'bon' }] };
  const c = await runKd('<div class="l" data-kol-filter="words:word@letter" data-kol-sort-lang="fr"><template><b data-kol-text="label"></b></template></div>' + LIST(), LAT);
  const got = c.doc.querySelectorAll('.l b').map(b => b.textContent), want = B.filterLayers({ items: LAT.items, labels: {} }, 'word@letter', 'fr').map(l => l.label);
  ok(J(got) === J(want) && got.indexOf('A') < got.indexOf('B') && got.indexOf('B') < got.indexOf('Z'), 'data-kol-sort-lang על המכל — ה-Collator של השפה (fr), אותו סדר ב-bind.js', { got, want });
}

// ── 4. מצטברים: נושא + אות + חיפוש; "הכול" מאפס רק את שלו ──
{
  const a = await runKd(FILTERS + LIST()), st = a.KD.lists.words, T = v => btns(a.doc, '.t').find(b => b.getAttribute('data-kol-filter-value') === v), L = v => btns(a.doc, '.l').find(b => b.getAttribute('data-kol-filter-value') === v);
  const pressed = sel => btns(a.doc, sel).filter(b => b.getAttribute('aria-pressed') === 'true').map(b => b.getAttribute('data-kol-filter-value'));
  ok(J(shownIds(a.doc)) === J([1, 2, 3, 4, 5, 6, 7, 8, 9, 10]) && J(pressed('.t')) === '[""]' && J(pressed('.l')) === '[""]', 'בהתחלה — הכול, ו"הכול" לחוץ בשני המכלים');
  a.click(L('ב'));
  ok(J(shownIds(a.doc)) === J([3, 10]) && J(pressed('.l')) === '["ב"]' && J(pressed('.t')) === '[""]', 'אות ב ← בית, בשר; aria-pressed רק על ב', shownIds(a.doc));
  ok(L('ב').classList.contains('is-active') && !L('').classList.contains('is-active'), 'data-kol-active-class על הכפתור הפעיל');
  a.click(T('food'));
  ok(J(shownIds(a.doc)) === J([10]) && J(pressed('.t')) === '["food"]' && J(pressed('.l')) === '["ב"]', 'אות ב + נושא אוכל ← רק בשר (מצטבר), ושני הכפתורים לחוצים', shownIds(a.doc));
  a.type(a.doc.querySelector('[data-kol-search]'), 'שר');
  ok(J(shownIds(a.doc)) === J([10]), 'ועם חיפוש "שר" (בלי ניקוד) — עדיין בשר');
  a.type(a.doc.querySelector('[data-kol-search]'), 'בית');
  ok(J(shownIds(a.doc)) === J([]), 'חיפוש "בית" + ב + אוכל ← אין (שלושתם מצטברים)');
  a.type(a.doc.querySelector('[data-kol-search]'), '');
  a.click(L(''));
  ok(J(shownIds(a.doc)) === J([4, 6, 7, 8, 9, 10]) && J(pressed('.l')) === '[""]' && J(pressed('.t')) === '["food"]', '"הכול" של האותיות מאפס רק את האות — הנושא נשאר', shownIds(a.doc));
  a.click(L('מ'));
  ok(J(shownIds(a.doc)) === J([6, 7]), 'אוכל + מ ← מִן, ם-סופית (סופית מקופלת)', shownIds(a.doc));
  a.click(T(''));
  ok(J(shownIds(a.doc)) === J([6, 7]) && J(pressed('.t')) === '[""]', '"הכול" של הנושאים — האות נשארת');
  // bind.js — אותם פריטים לכל צירוף
  for (const [q, f] of [['', { topics: 'food', 'word@letter': 'ב' }], ['א', { 'word@letter': 'א' }], ['', { topics: 'home' }], ['לחם', { topics: 'home', 'word@letter': 'ל' }], ['', { 'word@letter': 'כ' }], ['', { topics: '', 'word@letter': '' }]]) {
    st.filters = Object.assign({}, f); st.filterField = ''; st.filterValue = ''; st.query = q; a.KD.refresh('words');
    const want = st.matched.map(x => x.id), got = B.matched({ items: B.itemsOf(DATA), labels: B.labelsOf(DATA) }, { query: q, fields: 'word meaning', filters: f }, decode).map(x => x.id);
    ok(J(want) === J(got), 'bind.js = kol-data.js: "' + q + '" ' + J(f) + ' → [' + got + ']', { want, got });
  }
  // הצורה של 1.5 (filterField / filterValue) עדיין עובדת
  st.filters = {}; st.query = ''; st.filterField = 'topics'; st.filterValue = 'family'; a.KD.refresh('words');
  ok(J(st.matched.map(x => x.id)) === J([1, 2]), 'תאימות: state.filterField/filterValue של 1.5 עדיין מסנן');
}

// ── 5. רשימה שנכתבה מראש ──
{
  const pre = '<div data-kol-list="words" data-kol-prerendered><template><i data-kol-text="id"></i></template><i data-kol-pre>1</i><i data-kol-pre>2</i><p class="mine">שלי</p></div>';
  const a = await runKd(FILTERS + pre), L = v => btns(a.doc, '.l').find(b => b.getAttribute('data-kol-filter-value') === v);
  ok(J(shownIds(a.doc)) === J([1, 2, 3, 4, 5, 6, 7, 8, 9, 10]) && a.doc.querySelectorAll('[data-kol-pre]').length === 0 && a.doc.querySelector('.mine'), 'prerender: הכרטיסים הכתובים מוחלפים, בלי כפילות; מה שכתבתם נשאר');
  a.click(L('א'));
  ok(J(shownIds(a.doc)) === J([1, 2]), 'prerender: סינון לפי אות עובד אחרי ההחלפה', shownIds(a.doc));
}

// ── 6. הבלוק שהעורך מוסיף, על ההעתק של העמוד האמיתי ──
{
  const blockL = DP.filterBlock('words', 'word@letter', 'letters'), blockT = DP.filterBlock('words', 'topics', 'topics'), css = DP.filterCss();
  ok(/^<div class="kol-filter kol-filter--letters" data-kol-filter="words:word@letter" data-kol-active-class="is-active" role="group" aria-label="סינון לפי אות">/.test(blockL)
    && /data-kol-filter-value="">הכול<\/button>/.test(blockL) && /<template>[\s\S]*data-kol-text="label"[\s\S]*data-kol-text="count"[\s\S]*<\/template>/.test(blockL), 'הבלוק: HTML רגיל — מחלקות, role/aria-label, "הכול" כטקסט בקובץ, ותבנית כפתור עם {label} ו-{count}');
  ok(/data-kol-filter="words:topics"/.test(blockT) && /aria-label="סינון לפי נושא"/.test(blockT), 'בלוק הנושאים: words:topics');
  ok(/\.kol-filter--letters \{[^}]*flex-wrap: nowrap;[^}]*overflow-x: auto/.test(css) && /@media \(min-width: 720px\) \{\s*\.kol-filter--letters \{ flex-wrap: wrap/.test(css) && /min-height: 40px/.test(css) && /:focus-visible/.test(css),
    'ה-CSS: טלפון קודם — אותיות בשורה עם גלילה אופקית, נשברות ממסך רחב; כפתור 40px; מיקוד גלוי');
  ok((css.match(/\{/g) || []).length === (css.match(/\}/g) || []).length, 'ה-CSS מאוזן');
  const fx = fs.readFileSync(path.join(here, 'fixtures/index.html'), 'utf8'), body = /<body[^>]*>([\s\S]*)<\/body>/.exec(fx)[1].replace(/<script[\s\S]*?<\/script>/g, '');
  const at = body.indexOf('<div class="kadisha-lexicon-count"'), page = body.slice(0, at) + blockL + '\n      ' + body.slice(at);
  const items = DATA.items.map(x => Object.assign({ audio: '', wp_id: 0 }, x));
  const a = await runKd(page, { topics: DATA.topics, items }), cards = () => a.doc.querySelectorAll('.kadisha-word-card').map(c => c.getAttribute('data-id'));
  const lb = btns(a.doc, '.kol-filter--letters');
  ok(lb.length === 7 && lb[0].getAttribute('aria-pressed') === 'true', 'בעמוד האמיתי: בלוק האותיות נבנה (הכול + 6 אותיות) ליד סינון הנושאים הקיים', lb.map(b => b.getAttribute('data-kol-filter-value')));
  a.click(lb.find(b => b.getAttribute('data-kol-filter-value') === 'ב'));
  a.click(btns(a.doc, '.kadisha-lexicon-topic-filter').find(b => b.getAttribute('data-kol-filter-value') === 'home'));
  ok(J(cards()) === J(['3']) && a.doc.querySelector('[id="kadisha-lex-shown"]').textContent === '1', 'בעמוד האמיתי: ב + "בית" (הנושאים הקיימים) ← כרטיס אחד, והמונה 1', { cards: cards(), shown: a.doc.querySelector('[id="kadisha-lex-shown"]').textContent });
}

console.log('\n' + (failed ? '❌ ' + failed + ' נכשלו' : '✅ הכול עבר') + ' (' + ran + ' בדיקות)');
process.exit(failed ? 1 : 0);
