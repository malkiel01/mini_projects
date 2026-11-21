/**
 * V11 — שני המימושים של הקשירה, אותו DOM:   node kol-maroko/tests/editor/bind-parity.mjs
 *
 * js/kol-data.js של הכלי (assets/project-seed — מה שרץ אצל הגולש) ו-assets/js/editor/bind.js (מה שמצייר את הכרטיסים בקנבס) רצים
 * כאן על אותו DOM מינימלי (mini-dom.mjs), עם אותם נתונים ואותה תבנית — 30 תבניות שמכסות את כל התכונות של 02 §8.2: text, html,
 * attr-*, if (כל צורות התנאי), class-*, class הכללית, each (ערכים, אובייקטים, "."), {שדה} בטקסט מעורב, שורשים מרובים, וההעתק
 * של הכרטיס האמיתי (fixtures/index.html). ובנוסף: מיון (שדה, יורד, ידני, שפה), חיפוש וסינון (אותם פריטים), כפתורי הסינון.
 */
import fs from 'node:fs'; import path from 'node:path'; import vm from 'node:vm'; import { fileURLToPath } from 'node:url';
import { docFrom, serialize, decode } from './mini-dom.mjs';
const here = path.dirname(fileURLToPath(import.meta.url)), root = path.resolve(here, '../..');
const KD = fs.readFileSync(path.join(root, 'assets/project-seed/kol-data.js'), 'utf8');
vm.runInThisContext(fs.readFileSync(path.join(root, 'assets/js/editor/bind.js'), 'utf8'), { filename: 'bind.js' });
const B = globalThis.KolBind;
let failed = 0, ran = 0;
const ok = (cond, what, got) => { ran++; if (cond) console.log('✅ ' + what); else { failed++; console.log('❌ ' + what + (got !== undefined ? '\n   התקבל: ' + JSON.stringify(got).slice(0, 1600) : '')); } };

const DATA = {
  topics: [{ id: 'food', label: 'אוכל' }, { id: 'family', label: 'משפחה' }, { id: 'home', label: 'בית' }],
  kind: [{ id: 'song', label: 'שיר' }, { id: 'say', label: 'אמרה' }],
  items: [
    { id: 6, word: 'خبز', meaning: '<p>לחם <b>טרי</b> &amp; חם</p>', topics: ['food', 'home'], audio: 'media/audio/6.mp3', kind: 'song', n: 3, ok: true, parts: [{ name: 'א' }, { name: 'ב' }], tags: ['x', 'y'], obj: { a: { b: 'עמוק' } }, wp_id: 61 },
    { id: 2, word: 'Bonjour', meaning: 'שלום', topics: ['family'], audio: '', kind: 'say', n: 0, ok: false, parts: [], tags: [] },
    { id: 11, word: 'אבא', meaning: '', topics: [], kind: 'song', n: 12, tags: ['z'] },
    { id: 4, word: 'ماء', meaning: '<i>מים</i>', topics: ['food'], audio: 'media/audio/4.mp3', n: 1, obj: { a: {} } },
    { id: 9, word: 'zeit', meaning: 'שמן "זית" <script>x</script>', topics: ['home', 'food', 'family'], audio: 'https://example.test/a.mp3', kind: 'say', ok: true },
  ],
};
const T = [
  ['text', '<h3 data-kol-text="word"></h3>'],
  ['html', '<div data-kol-html="meaning"></div>'],
  ['attr-src + if', '<audio data-kol-if="audio" data-kol-attr-src="audio"></audio>'],
  ['if !audio', '<span data-kol-if="!audio">בלי הקלטה</span>'],
  ['if topics.count=1', '<b data-kol-if="topics.count=1">נושא אחד</b>'],
  ['if kind=song', '<i data-kol-if="kind=song">♪</i>'],
  ['if kind!=song', '<i data-kol-if="kind!=song">—</i>'],
  ['class-is-single', '<div class="t" data-kol-class-is-single="topics.count=1">x</div>'],
  ['class כללית (אות גדולה, שתיים)', '<div data-kol-class="isActive: audio; has-topics: topics; bad; : x">x</div>'],
  ['each topics label', '<ul><li data-kol-each="topics" data-kol-text="label"></li></ul>'],
  ['each topics {id} בתכונה', '<p><a data-kol-each="topics" data-kol-attr-href="#t-{id}" data-kol-text="label"></a></p>'],
  ['טקסט מעורב', '<button data-kol-attr-aria-label="השמע את {word}" data-kol-text="השמע את {word} (#{id})"></button>'],
  ['#{id}', '<small data-kol-text="#{id}"></small>'],
  ['{חסר} ← ריק', '<span data-kol-attr-title="[{nope}]" data-kol-text="{nope}{word}"></span>'],
  ['each אובייקטים', '<ol><li data-kol-each="parts" data-kol-text="{name}·{word}"></li></ol>'],
  ['each "."', '<ul><li data-kol-each="tags" data-kol-text="."></li></ul>'],
  ['html מחליף ילדים', '<div data-kol-html="meaning"><b>לא יופיע</b></div>'],
  ['text מחליף ילדים', '<div data-kol-text="word"><b>לא יופיע</b> טקסט</div>'],
  ['כמה שורשים', '<h4 data-kol-text="word"></h4><p data-kol-text="meaning"></p>'],
  ['if על השורש', '<article data-kol-if="audio"><h3 data-kol-text="word"></h3></article>'],
  ['each על השורש', '<span data-kol-each="topics" data-kol-text="label"></span>'],
  ['מערך בתכונה (join)', '<article data-kol-attr-data-topics="topics" data-kol-attr-data-id="id"></article>'],
  ['topics.count', '<em data-kol-text="topics.count"></em>'],
  ['0 ריק', '<span data-kol-if="n">n</span><span data-kol-if="!n">אין n</span>'],
  ['bool', '<span data-kol-if="ok">✓</span>'],
  ['a.b.c', '<span data-kol-text="obj.a.b"></span>'],
  ['class שלילה', '<div data-kol-class-no-audio="!audio" data-kol-class-many="topics.count=3">x</div>'],
  ['רווחים והערות', '\n  <article>\n    <!-- הערה -->\n    <h3 data-kol-text="word"></h3>\n  </article>\n'],
  ['הכרטיס האמיתי', null],
  ['קטע N3', '<article class="kol-list-x__item"><h3 data-kol-text="word"></h3><div data-kol-html="meaning"></div><img data-kol-if="img" data-kol-attr-src="img" alt=""><button type="button" data-kol-if="audio" data-kol-play="audio">▶ השמעה</button><a data-kol-if="url" data-kol-attr-href="url" data-kol-text="url"></a><ul data-kol-if="tags"><li data-kol-each="tags" data-kol-text="."></li></ul><span data-kol-if="ok">✓ כן</span></article>'],
];
{ const fx = fs.readFileSync(path.join(here, 'fixtures/index.html'), 'utf8'), m = /<div data-kol-list="words"[^>]*>\s*<template>([\s\S]*?)<\/template>/.exec(fx); T[28][1] = m[1]; }
ok(T.length === 30 && T.every(t => t[1]), '30 תבניות (כולל הכרטיס של העמוד המוסב)');

/** מריץ את kol-data.js על מסמך, ומחזיר את המסמך ו-KolData אחרי הטעינה. */
async function runKd(body, data) {
  const doc = docFrom(body), win = { document: doc, setTimeout, console, Intl, Date, URL, Audio: class {}, location: { href: 'http://t.test/index.html', pathname: '/index.html' }, fetch: async () => ({ ok: true, json: async () => JSON.parse(JSON.stringify(data)) }) };
  win.window = win; win.parent = win;
  vm.runInNewContext(KD, Object.assign(Object.create(null), win, { window: win }), { filename: 'kol-data.js' });
  for (let i = 0; i < 20; i++) await new Promise(r => setTimeout(r, 0));
  return { doc, KD: win.KolData };
}
const tplDoc = (tpl, attrs = '') => '<div data-kol-list="words"' + attrs + '><template>' + tpl + '</template></div>';
const shown = c => c.childNodes.filter(n => !(n.nodeType === 1 && n.localName === 'template')).map(serialize).join('');
/** bind.js: אותה רשימה, אותו מיון ואותו סינון — כרטיס לכל פריט אחרי התבנית. */
function runBind(body, data, opt = {}) {
  const doc = docFrom(body), c = doc.querySelector('[data-kol-list]'), tpl = B.templateOf(c), state = { items: B.itemsOf(JSON.parse(JSON.stringify(data))), labels: B.labelsOf(data) };
  const list = B.sorted(B.matched(state, opt, decode), c.getAttribute('data-kol-sort'), c.getAttribute('data-kol-sort-lang'));
  for (const it of list) for (const n of B.build(tpl, [it], state)) c.appendChild(n);
  return { doc, c, list };
}

for (const [name, tpl] of T) {
  const body = tplDoc(tpl), a = await runKd(body, DATA), b = runBind(body, DATA);
  const ka = shown(a.doc.querySelector('[data-kol-list]')), kb = shown(b.c);
  ok(ka === kb && ka.length > 0, 'זהה: ' + name, ka === kb ? undefined : { kolData: ka.slice(0, 700), bind: kb.slice(0, 700) });
}

// ── מיון ──
for (const [sort, lang] of [['word', ''], ['-id', ''], ['manual', ''], ['word', 'ar'], ['-word', 'fr'], ['n', '']]) {
  const body = tplDoc('<i data-kol-text="id"></i>', ' data-kol-sort="' + sort + '"' + (lang ? ' data-kol-sort-lang="' + lang + '"' : ''));
  const a = await runKd(body, DATA), b = runBind(body, DATA);
  const ka = shown(a.doc.querySelector('[data-kol-list]')), kb = shown(b.c);
  ok(ka === kb, 'מיון זהה: ' + sort + (lang ? ' (' + lang + ')' : '') + ' → ' + kb.replace(/<\/?i>/g, ' ').trim().replace(/\s+/g, ','), ka === kb ? undefined : { ka, kb });
}

// ── חיפוש וסינון: אותם פריטים ──
{
  const body = tplDoc('<i data-kol-text="id"></i>') + '<input data-kol-search="words" data-kol-search-fields="word meaning topics">';
  const a = await runKd(body, DATA), st = a.KD.lists.words;
  for (const [q, ff, fv] of [['', '', ''], ['לחם', '', ''], ['טרי', '', ''], ['אוכל', '', ''], ['bon', '', ''], ['זית', '', ''], ['&', '', ''], ['', 'topics', 'food'], ['', 'kind', 'say'], ['ש', 'topics', 'family'], ['לא קיים', '', '']]) {
    st.query = q; st.filterField = ff; st.filterValue = fv; a.KD.refresh('words');
    const want = st.matched.map(x => x.id), got = B.matched({ items: B.itemsOf(DATA), labels: B.labelsOf(DATA) }, { query: q, fields: 'word meaning topics', filterField: ff, filterValue: fv }, decode).map(x => x.id);
    ok(JSON.stringify(want) === JSON.stringify(got), 'חיפוש/סינון זהה: "' + q + '"' + (ff ? ' · ' + ff + '=' + fv : '') + ' → [' + got + ']', { want, got });
  }
}

// ── כפתורי הסינון מהתבנית ──
{
  const body = '<div data-kol-filter="words:topics"><button data-kol-filter-value="">הכל</button><template><button data-kol-attr-data-t="id"><span data-kol-text="label"></span> <small data-kol-text="count"></small></button></template></div>' + tplDoc('<i></i>');
  const a = await runKd(body, DATA), f = a.doc.querySelector('[data-kol-filter]');
  const st = { items: B.itemsOf(DATA), labels: B.labelsOf(DATA) }, d = docFrom(body), f2 = d.querySelector('[data-kol-filter]'), tpl = B.templateOf(f2);
  for (const layer of B.filterLayers(st, 'topics')) for (const n of B.build(tpl, [layer], st)) { if (!n.hasAttribute('data-kol-filter-value')) n.setAttribute('data-kol-filter-value', layer.value); f2.appendChild(n); }
  B.markActive(f2, f2.querySelectorAll('[data-kol-filter-value]'), '');
  const ka = shown(f), kb = shown(f2);
  ok(ka === kb && /אוכל/.test(kb), 'כפתורי הסינון זהים (תווית, מספר, סדר טבלת הנושאים)', { ka, kb });
}

// ── hook: מיפוי כל חלק של הכרטיס לצומת שבתבנית (העורך) ──
{
  const body = tplDoc(T[28][1]), d = docFrom(body), c = d.querySelector('[data-kol-list]'), tpl = B.templateOf(c), st = { items: B.itemsOf(DATA), labels: B.labelsOf(DATA) };
  const map = new Map(); const pair = (x, o) => { map.set(x, o); const xs = x.children, os = o.children; for (let i = 0; i < Math.min(xs.length, os.length); i++) pair(xs[i], os[i]); };
  const nodes = B.build(tpl, [st.items[0]], st, { copy: (x, o) => pair(x, map.get(o) || o) });
  let all = 0, mapped = 0; const go = e => { all++; let x = e; while (x && !map.has(x)) x = x.parentNode; if (x && tpl.content.querySelectorAll('*').includes(map.get(x))) mapped++; for (const k of e.children) go(k); };
  nodes.forEach(go);
  const topicEls = nodes[0].querySelectorAll('.kadisha-word-topic');
  ok(all > 5 && mapped === all && topicEls.length === 2 && topicEls.every(t => map.get(t) === tpl.content.querySelector('[data-kol-each]')), 'מיפוי: כל אלמנט בכרטיס ← צומת בתבנית; כל חזרה של each ← אותו צומת', { all, mapped, topics: topicEls.length });
}

// ── עזרים לפאנל ──
ok(JSON.stringify(B.fieldsIn('השמע את {word} {obj.a}')) === '["word","obj"]' && JSON.stringify(B.fieldsIn('meaning')) === '["meaning"]' && B.fieldsIn('.').length === 0, 'fieldsIn — השדות שבתבנית');
ok(JSON.stringify(B.classPairs('isActive: audio; x; : y; a b: c;is-on:kind=song')) === '[["isActive","audio"],["is-on","kind=song"]]', 'classPairs — צורה כללית, חלקים פגומים מדולגים');

console.log('\n' + (failed ? '❌ ' + failed + ' נכשלו' : '✅ הכול עבר') + ' (' + ran + ' בדיקות)');
process.exit(failed ? 1 : 0);
