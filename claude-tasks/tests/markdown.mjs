// Markdown בבועות הצ'אט (assets/js/markdown.js) — הפענוח, בלי DOM.
//
//   node claude-tasks/tests/markdown.mjs

import { parseMarkdown as md, inline } from '../assets/js/markdown.js';

let pass = 0, fail = 0;
function check(name, got, want) {
  const g = JSON.stringify(got), w = JSON.stringify(want);
  if (g === w) { pass++; console.log(`  ok   ${name}`); return; }
  fail++;
  console.log(`  FAIL ${name}\n       got:  ${g}\n       want: ${w}`);
}
const types = (src) => md(src).map((b) => b.t);

console.log('\n— בלוקים —');
check('כותרות ברמות', md('# א\n### ג').map((b) => [b.t, b.level]), [['h', 1], ['h', 3]]);
check('#בלי רווח — לא כותרת (תגית)', types('#1120 מוזג'), ['p']);
check('פסקה — Enter נשמר כבירת שורה', md('שורה א\nשורה ב')[0].c, ['שורה א', { t: 'br' }, 'שורה ב']);
check('שורה ריקה — שתי פסקאות', types('א\n\nב'), ['p', 'p']);
check('קו מפריד', types('א\n\n---\n\nב'), ['p', 'hr', 'p']);
check('ציטוט — הסיכום בסוף כל הודעה', md('> **סיכום:** נעשה X.\n> הרחבה למעלה.')[0],
      { t: 'quote', c: [{ t: 'p', c: [{ t: 'b', c: ['סיכום:'] }, ' נעשה X.', { t: 'br' }, 'הרחבה למעלה.'] }] });
check('ציטוט מקונן', md('> א\n> > ב')[0].c.map((b) => b.t), ['p', 'quote']);

const ul = md('- אחת\n- **שתיים**\n  - פנימית\n- שלוש')[0];
check('רשימה — שלושה פריטים', [ul.t, ul.items.length], ['ul', 3]);
check('…פריט פשוט — בלי פסקה סביבו', ul.items[0].c, ['אחת']);
check('…תת-רשימה בתוך הפריט', ul.items[1].blocks.map((b) => b.t), ['p', 'ul']);
const ol = md('3. ג\n4. ד')[0];
check('רשימה ממוספרת — ממשיכה מהמספר', [ol.t, ol.start, ol.items.length], ['ol', 3, 2]);
check('ממוספרת אחרי תבליטים — שתי רשימות', types('- א\n1. ב'), ['ul', 'ol']);
check('רשימת משימות', md('- [x] נעשה\n- [ ] פתוח')[0].items.map((i) => i.task), [true, false]);
check('פסקה ואז רשימה בלי שורה ריקה', types('מה ממתין:\n- א\n- ב'), ['p', 'ul']);

const tb = md('| PR | שלב | מצב |\n| --- | :---: | ---: |\n| #1113 | 8 | ✅ `success` |\n| #1115 | 9 |')[0];
check('טבלה — כותרת ושורות', [tb.t, tb.head.length, tb.rows.length], ['table', 3, 2]);
check('…יישור עמודות', tb.align, ['', 'center', 'end']);
check('…תא חסר — ריק, לא קורס', tb.rows[1][2], []);
check('…קוד בתוך תא', tb.rows[0][2], ['✅ ', { t: 'code', text: 'success' }]);
check('קו אנכי בלי שורת מפריד — לא טבלה', types('a | b'), ['p']);

const code = md('```bash\nphp x.php | grep **y**\n<b>\n```')[0];
check('בלוק קוד — כמו שהוא, בלי עיצוב', code, { t: 'code', lang: 'bash', text: 'php x.php | grep **y**\n<b>' });
check('בלוק קוד שלא נסגר — נסגר בסוף', md('```\nא')[0].text, 'א');

console.log('\n— בתוך שורה —');
check('מודגש, נטוי, קו חוצה', inline('**מ** *נ* ~~ק~~'),
      [{ t: 'b', c: ['מ'] }, ' ', { t: 'i', c: ['נ'] }, ' ', { t: 's', c: ['ק'] }]);
check('נטוי בתוך מודגש', inline('**א *ב* ג**'), [{ t: 'b', c: ['א ', { t: 'i', c: ['ב'] }, ' ג'] }]);
check('snake_case — לא נטוי', inline('CLAUDE_TASKS_TOKEN ו-board_link'), ['CLAUDE_TASKS_TOKEN ו-board_link']);
check('כוכבית בודדת — נשארת', inline('5 * 3 = 15'), ['5 * 3 = 15']);
check('קוד — בלי עיצוב בפנים', inline('`**x**`'), [{ t: 'code', text: '**x**' }]);
check('קישור', inline('[ה-PR](https://github.com/a/b/pull/1)'), [{ t: 'a', href: 'https://github.com/a/b/pull/1', c: ['ה-PR'] }]);
check('כתובת חשופה — בלי הנקודה שבסוף', inline('ראו https://mbe-plus.com/x.'), ['ראו ', { t: 'a', href: 'https://mbe-plus.com/x', c: ['https://mbe-plus.com/x'] }, '.']);
check('תו מוגן', inline('\\*לא נטוי\\*'), ['*לא נטוי*']);

console.log('\n— בטיחות —');
check('javascript: בקישור — טקסט בלבד, בלי href', inline('[לחץ](javascript:alert(1))'), [{ t: 'span', c: ['לחץ'] }, ')']);
check('data: בקישור — בלי href', inline('[x](data:text/html,hi)')[0].t, 'span');
check('HTML גולמי — טקסט, לא תגית', inline('<img src=x onerror=alert(1)>'), ['<img src=x onerror=alert(1)>']);
check('כתובת עם מירכאות — נעצרת לפניהן', inline('https://a.b/"onmouseover=x')[0].href, 'https://a.b/');
check('קלט ריק / null', [md('').length, md(null).length], [0, 0]);
const deep = '>'.repeat(200) + ' x';
check('קינון עמוק — לא קורס', md(deep)[0].t, 'quote');
const big = '**a '.repeat(5000);
const t0 = Date.now(); inline(big);
check('טקסט ארוך בלי סוגר — מהיר', Date.now() - t0 < 500, true);

console.log(`\n════ עברו: ${pass} · נכשלו: ${fail} ════`);
process.exit(fail ? 1 : 0);
