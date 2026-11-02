// 12e (docs/spec.md §5.11.2): פירוק לקטעים — קוד, כתובות ומזהים לא מתורגמים; זיהוי "כנראה לא בעברית".
//   node claude-tasks/tests/translate.mjs
import { segment, assemble, restore, needsTranslation } from '../assets/js/translate-text.js';

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
  '| Shared labels are visible | Private ones stay hidden |',
].join('\n');
const out = fake(md);
check('בלוק קוד — כפי שהוא', out.includes('```bash\necho "Do not translate me."\n```'), true);
check('קוד בשורה — כפי שהוא', out.includes('`php tests/labels.php`'), true);
check('כתובת — כפי שהיא', out.includes('https://mbe-plus.com/x?y=1'), true);
check('נתיב, שם קובץ ומזהים — כפי שהם', ['claude-tasks/lib/search.php', 'README.md', '#1142', 'session_018g4NYG'].every((s) => out.includes(s)), true);
check('קידומות Markdown נשארות', ['## ', '- ', '> '].every((s) => out.split('\n').some((l) => l.startsWith(s))), true);
check('טבלה: קווים ומפריד נשארים; תא של 3 מילים ומעלה מתורגם, תא קצר נשאר', out.split('\n').slice(-3),
      ['| Name | Status |', '| --- | --- |', '| SHARED LABELS ARE VISIBLE | PRIVATE ONES STAY HIDDEN |']);
check('הטקסט סביב המוגנים — מתורגם', out.includes('RUN') && out.includes('NOW.'), true);
check('שום דבר מהקוד לא נשלח למודל — רק מסמנים', trs(md).some((t) => /translate me|labels\.php|mbe-plus|#1142|session_/.test(t)), false);

console.log('\n— איכות (12e.6): משפטים, לא קטעים —');
check('מזהה באמצע משפט — המשפט נשלח שלם, עם מסמן', trs('I fixed the bug in `lib/labels.php` and deployed it.'), ['I fixed the bug in XX0 and deployed it.']);
check('המזהה חוזר למקומו', restore({ map: ['`lib/labels.php`'], heading: false }, 'תיקנתי את הבאג ב-XX0 ופרסתי.'), 'תיקנתי את הבאג ב-`lib/labels.php` ופרסתי.');
check('המודל השמיט מסמן — המזהה בסוף, לא הולך לאיבוד', restore({ map: ['#1141'], heading: false }, 'ראו את הפרטים.'), 'ראו את הפרטים. #1141');
check('"See #1141 and lib/labels.php." — לא נשלח לבד (פחות מ-3 מילים), מצטרף לקודם',
      trs('Please refresh the board once. See #1141 and lib/labels.php.'), ['Please refresh the board once. See XX0 and XX1.']);
check('קטע שכולו מזהה / מספר — לא נשלח', trs('`npm test`\n\n#1142 · 3.2'), []);
check('קטע קצר לבד — נשאר במקור', fake('Done.'), 'Done.');
check('מקף ארוך — מסמן, והמשפט נשאר שלם', trs('Its tasks will not come to you — and that is deliberate.'), ['Its tasks will not come to you XX0 and that is deliberate.']);
check('הדגשה בתוך משפט — הכוכביות לא נשלחות', trs('Start it **once** if it is not running.'), ['Start it once if it is not running.']);
check('שורה שנשברה באמצע משפט — משפט אחד', trs('make sure the listener is\nrunning before you stop.'), ['make sure the listener is running before you stop.']);
check('…אבל פריט חדש ברשימה — לא מצטרף', trs('- first item here now\n- second item here now'), ['first item here now', 'second item here now']);
check('מסמן ששוכפל — לא נשאר על המסך', restore({ map: ['`status`'], heading: false }, 'XX0 פועל. XX0 נרשם.'), '`status` פועל. נרשם.');
check('כותרת — נשלחת עם נקודה, והנקודה מוסרת', [trs('## Deploy finished now')[0], fake('## Deploy finished now')], ['Deploy finished now.', '## DEPLOY FINISHED NOW']);
check('כותרת קצרה (2 מילים) — בכל זאת מתורגמת', fake('## Deploy finished'), '## DEPLOY FINISHED');

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
