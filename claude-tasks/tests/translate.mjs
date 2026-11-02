// 12e (docs/spec.md §5.11.2): פירוק לקטעים — קוד, כתובות ומזהים לא מתורגמים; זיהוי "כנראה לא בעברית".
//   node claude-tasks/tests/translate.mjs
import { segment, assemble, needsTranslation } from '../assets/js/translate-text.js';

let pass = 0, fail = 0;
const check = (name, got, want) => {
  const g = JSON.stringify(got), w = JSON.stringify(want);
  if (g === w) { pass++; console.log(`  ok   ${name}`); } else { fail++; console.log(`  FAIL ${name}\n       got:  ${g}\n       want: ${w}`); }
};
const trs = (md) => segment(md).parts.filter((p) => 'tr' in p).map((p) => p.tr);
// "מתרגם" מזויף: כל משפט באותיות גדולות — כך רואים בדיוק מה נשלח ומה לא
const fake = (md) => { const { parts } = segment(md); return assemble(parts, parts.filter((p) => 'tr' in p).map((p) => p.tr.toUpperCase())); };

console.log('\n— משפטים —');
check('שני משפטים בשורה — שתי יחידות', trs('I merged it. The deploy finished!'), ['I merged it.', 'The deploy finished!']);
check('המקור חוזר בדיוק כשאין תרגום', assemble(segment('One. Two.\n\nThree').parts, []), 'One. Two.\n\nThree');

console.log('\n— מה לא נוגעים בו —');
const md = [
  '## Deploy finished',
  '',
  'Run `php tests/labels.php` and open https://mbe-plus.com/x?y=1 now.',
  '- See claude-tasks/lib/search.php and README.md for #1142.',
  '> Ask session_018g4NYG about it.',
  '',
  '```bash',
  'echo "Do not translate me."',
  '```',
  '| Name | Status |',
  '| --- | --- |',
  '| Labels | Ready |',
].join('\n');
const out = fake(md);
check('בלוק קוד — כפי שהוא', out.includes('```bash\necho "Do not translate me."\n```'), true);
check('קוד בשורה — כפי שהוא', out.includes('`php tests/labels.php`'), true);
check('כתובת — כפי שהיא', out.includes('https://mbe-plus.com/x?y=1'), true);
check('נתיב, שם קובץ ומזהים — כפי שהם', ['claude-tasks/lib/search.php', 'README.md', '#1142', 'session_018g4NYG'].every((s) => out.includes(s)), true);
check('קידומות Markdown נשארות', ['## ', '- ', '> '].every((s) => out.split('\n').some((l) => l.startsWith(s))), true);
check('טבלה: קווים ומפריד נשארים, תאים מתורגמים', out.split('\n').slice(-3), ['| NAME | STATUS |', '| --- | --- |', '| LABELS | READY |']);
check('הטקסט סביב המוגנים — מתורגם', out.includes('RUN') && out.includes('NOW.'), true);
check('שום דבר מהקוד לא נשלח למודל', trs(md).some((t) => /translate me|labels\.php|mbe-plus|#1142|session_/.test(t)), false);

console.log('\n— קישור Markdown —');
check('טקסט הקישור מתורגם, הכתובת לא', fake('Open [the board](https://x.y/z) please.'), 'OPEN [THE BOARD](https://x.y/z) PLEASE.');

console.log('\n— כנראה לא בעברית? —');
check('תשובה באנגלית', needsTranslation('I merged the pull request and the deploy finished successfully.'), true);
check('תשובה בעברית', needsTranslation('מיזגתי את ה-PR והפריסה הסתיימה בהצלחה, הכול עובד.'), false);
check('עברית עם הרבה קוד ונתיבים', needsTranslation('תיקנתי את `claude-tasks/lib/search.php`:\n```php\nfunction channelSearch(array $user) { return []; }\n```\nעכשיו עובד.'), false);
check('קצר מדי', needsTranslation('OK, done.'), false);
check('אנגלית עם מעט עברית', needsTranslation('The board shows "המאזינים מושהים" until you resume the listeners in settings.'), true);

console.log('\n— יעד ספרדית —');
check('אנגלית → כן', needsTranslation('I merged the pull request and the deploy finished successfully.', 'es'), true);
check('ספרדית → לא', needsTranslation('Fusioné la petición de retirada y el despliegue terminó con éxito.', 'es'), false);
check('עברית → לא (המקור רק אנגלית)', needsTranslation('מיזגתי את ה-PR והפריסה הסתיימה בהצלחה, הכול עובד.', 'es'), false);

console.log(`\n════ עברו: ${pass} · נכשלו: ${fail} ════`);
process.exit(fail ? 1 : 0);
