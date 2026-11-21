/**
 * 📄 עמודים (V15) — החלק הטהור של assets/js/editor/pages.js:   node kol-maroko/tests/editor/pages.mjs
 * כתובת נגזרת מהשם (תמיד בתוך SLUG), קישור יחסי ויפה בין עמודים, וזיהוי קישור לעמוד / לעמוד שאינו קיים.
 */
import fs from 'node:fs'; import path from 'node:path'; import vm from 'node:vm'; import { fileURLToPath } from 'node:url';
const here = path.dirname(fileURLToPath(import.meta.url)), root = path.resolve(here, '../..');
vm.runInThisContext(fs.readFileSync(path.join(root, 'assets/js/editor/pages.js'), 'utf8'));
const P = globalThis.KolPages;
let failed = 0, ran = 0;
const ok = (cond, what, got) => { ran++; if (cond) console.log('✅ ' + what); else { failed++; console.log('❌ ' + what + (got !== undefined ? '\n   התקבל: ' + JSON.stringify(got) : '')); } };
const eq = (want, got, what) => ok(JSON.stringify(want) === JSON.stringify(got), what, got);

eq('about-us', P.slugify('About Us!'), 'שם לטיני ← kebab-case');
eq('odot', P.slugify('אודות'), 'עברית ← תעתיק');
eq('', P.slugify('../../etc'.replace(/[a-z]/g, '')), 'רק סימנים ← ריק (הטופס דורש כתובת)');
for (const s of ['שלום עולם', '../../x', 'A/B', 'a//b', '  -x- ', 'קפה ☕ 2', '/etc/passwd']) { const v = P.slugify(s); ok(v === '' || P.SLUG.test(v), 'נגזרת תמיד בתוך הגבול: ' + JSON.stringify(s) + ' ← ' + v); }
eq('about/', P.prettyRel('index.html', 'about/index.html'), 'index ← about/');
eq('../', P.prettyRel('about/index.html', 'index.html'), 'about/ ← הראשי');
eq('../odot/', P.prettyRel('about/index.html', 'odot/index.html'), 'אחים');
eq('dana/', P.prettyRel('team/index.html', 'team/dana/index.html'), 'ילד');
eq('../404.html', P.prettyRel('about/index.html', '404.html'), 'קובץ שאינו index');
eq('./', P.prettyRel('index.html', 'index.html'), 'אותו עמוד');
const paths = ['index.html', 'about/index.html', '404.html', 'x.html'];
eq('about/index.html', P.pageOf('index.html', 'about/', paths, '/lexicon/'), 'about/ ← עמוד');
eq('about/index.html', P.pageOf('index.html', '/lexicon/about/#team', paths, '/lexicon/'), '/lexicon/about/#team ← עמוד');
eq('index.html', P.pageOf('about/index.html', '../', paths, '/lexicon/'), '../ ← הראשי');
eq(null, P.pageOf('index.html', 'gone/', paths, '/lexicon/'), 'gone/ ← שבור');
eq(null, P.pageOf('index.html', 'nope.html', paths, '/lexicon/'), 'nope.html ← שבור');
eq(undefined, P.pageOf('index.html', 'css/a.css', paths, '/lexicon/'), 'קובץ שאינו עמוד — לא נבדק כאן');
eq(undefined, P.pageOf('index.html', 'https://x.com/a/', paths, '/lexicon/'), 'חיצוני');
eq(undefined, P.pageOf('index.html', '#top', paths, '/lexicon/'), 'עוגן');
eq(undefined, P.pageOf('index.html', '/wp-admin/', paths, '/lexicon/'), 'מחוץ ל-/lexicon/ — לא שלנו');
eq(undefined, P.pageOf('index.html', '../../x/', paths, '/lexicon/'), 'מעל השורש — לא שלנו');
console.log((failed ? '❌ ' : '✅ ') + (ran - failed) + '/' + ran);
process.exit(failed ? 1 : 0);
