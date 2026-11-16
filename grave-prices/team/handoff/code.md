# מה צ'אט הקוד הבא צריך לדעת

מצב: פרוסות 01, 02 ו-03 בנויות. 03 (`docs/spec/03-per-audience.md`) גוברת על 00–02: **מחירון שייך לקבוצה אחת, ולכל
שורה מחיר אחד.** מה שנשאר — מה שהבעלים יבקש מעבר לזה, או תיקונים אחרי האימות שלו (GP-02, GP-03).

## המבנה

- `api.php` — מתג אחד `"METHOD action" => …`. שגיאה: `ApiError(msg, code, extra)`; `extra` נוסף לתשובה
  (כך יוצא `{"error", "need_confirm": true}`).
- `lib/db.php` — המיגרציות ב-`gp_migrations()`, מוסיפים בסוף בלבד. 1 טבלאות · 2 נתוני התחלה (רק כשאין מחירונים) ·
  3 `rows.pid` · **4 (03)**: `editions` נבנית מחדש (+`audience`, +`subtitle`, `UNIQUE(audience, effective_from)`, אותם
  id/pid), `migrate-abroad`, ונתוני ההתחלה של חו"ל 15/06/2026 וישראל 15/06/2026 (כל אחד רק אם התאריך פנוי בקבוצה).
  גרסה ב-`PRAGMA user_version`.
- **`gp_migrate`**: `BEGIN IMMEDIATE` ובדיקה חוזרת של הגרסה בתוך הנעילה (בקשות מקבילות אחרי פריסה); `foreign_key_check`
  ריק לפני כל commit; מיגרציה ב-`GP_REBUILD_MIGRATIONS` רצה עם `foreign_keys = OFF` (נקבע **לפני** הטרנזקציה — בתוכה
  SQLite מתעלם). לפני מיגרציה על מסד קיים — `gp_backup`: `VACUUM INTO data/app.sqlite.v<גרסה>-<זמן>.bak`.
- **מיגרציה שבונה טבלה מחדש** — רק כך. ניסיתי בראש: עם `foreign_keys = ON` גם `defer_foreign_keys` לא עוזר (ה-DROP
  רושם הפרות נדחות, וה-RENAME לא מנקה אותן), ו-`legacy_alter_table` לא מונע שכתוב של ה-REFERENCES.
- `lib/editions.php` — קריאה: `gp_edition` מחזיר `audience`, `audience_name`, `subtitle`, `future`, ולכל שורה `pid`,
  `priced` ו-`price` (רק רשומת המחיר בקבוצה של המחירון). `gp_editions(audience)` — `in_effect` לכל קבוצה בנפרד.
- `lib/edit.php` — כל הכתיבה. כללים: `gp_guard` (מחירון שתאריכו ≤ היום — 409 בלי `confirm_in_effect: true`),
  `gp_tx` (טרנזקציה), וכל שינוי ביומן עם הערך הקודם (`price` עם `label` כדי שיישאר קריא גם אחרי מחיקת השורה;
  `row-delete` עם המחירים; `edition-delete` עם עותק מלא של המחירון).
- `assets/js/app.js` — `edit()` עוטף כתיבה: 409 `need_confirm` → שאלה → שליחה חוזרת, והאישור נשמר עד סוף מצב העריכה.
  `pick(editions, aud)` — ברירת המחדל להדפסה (בתוקף היום, אחרת העתידי הקרוב).
- `assets/js/print.js` — `print.html?il=&abroad=&mode=one|split|both`; `e=` הישן ממופה ב-`resolve()`. כל מידות התוכן
  ב-`print.css` ב-em מבסיס 10.5pt — כך `fit()` מקטין הכול יחד (ב-01 המידות היו ב-pt, וההקטנה לא השפיעה). `both` (הכרעת
  הבעלים): `.cols` — זה לצד זה, ישראל מימין; עד 70%, ואז שני עמודים כמו `split`. היום נכנס בעמוד אחד ב-100%.
- **חתימות — סט אחד** בטבלת `settings` (`key='signatures'`), במסך ⚙️ הגדרות (`#/settings`, `GET settings`,
  `POST signatures-set` בלי `pid`). `gp_edition()['signatures']` מחזיר את הסט הגלובלי, כך שכל הדפסה משתמשת בו.
  `editions.signatures` נשאר במסד, מועתק למחירון חדש ולא נקרא. אתחול במיגרציה 4 (`gp_settings_init`, לפני נתוני
  ההתחלה): מחירון ישראל האחרון שיש בו שלוש חתימות, כולל עריכות. `body[data-pages]` — מספר העמודים, לבדיקות.

## החלטות טכניות שלי (02)

- `edition-create.raise` ממופה לפי ה-`pid` של שורת ה-`section` (לא `sort` כמו בטבלת ה-API באפיון) — `sort` משתנה
  בהזזות; `pid` יציב. ו-`"fee"` לתשלומים.
- מזהה של מחירון חדש = 1 + המקסימום במחירונים **וביומן**, כדי שמזהה של מחירון שנמחק לא יחזור ויצרף לחדש את היומן שלו.
- `price-set` בלי שינוי (אותו ערך) — לא נרשם ביומן.
- שינוי `kind` משורת מחיר לשורה בלי מחיר לא מוחק את המחירים במסד — הם רק לא מוצגים; חזרה לשורת מחיר מחזירה אותם.
- אחוז מותר: מעל ‎-100 ועד 1000 (גם הורדה).

## החלטות טכניות (03)

- רשומות `prices` של הקבוצה השנייה (מלפני 03) נשארות במסד ולא נקראות. `row-delete` רושם ביומן `price` וגם `prices`
  (כל הרשומות), ו-`edition-delete` שומר אותן ב-`copy.other_prices` — כך גם הן לא אובדות.
- `migrate-abroad` נוצר רק ממחיר `abroad` בשורת `item`/`fee` (מחיר נסתר בשורה שהפכה לכותרת — לא יוצר מחירון), ומדלג אם
  כבר יש חו"ל באותו תאריך. מזהי מחירונים חדשים — `gp_next_edition_id` (גם במיגרציה).
- `edition-update`: תאריך — רק עתידי (403); `subtitle` — לכל מחירון, עם `gp_guard` (409). ביומן `subtitle`.
- `raise` — מספר לכל מפתח; המבנה הישן `{il, abroad}` → 400.
- `print-log` — שורה אחת, `edition_id` של ישראל (או של היחיד), ובפרטים `mode` ותאריכי שני המחירונים.
- חתימות בהדפסה: `.sigs{direction:ltr}` — הסט מוצג משמאל לימין, כמו המקור (ביקורת 03 סבב 1); במסך ⚙️ באותו סדר.
- הדפסת חו"ל: פס `band` אפור; "תשלומי אגרות" (subsection) עם קו תחתון ותשלומים בכתב רגיל — רק ב-`.pl.abroad`, כמו במקור.

## פרוסה 04

- `gp_layout(rows)` ב-`lib/editions.php` — מקור האמת לגבולות מקטע (`ends`), `fee_start` ומיקומי ➕ (`add_row`, `add_block`,
  `add_fee` — אינדקס השורה שלפניה מוצג הכפתור). `gp_edition` מחזיר `layout` (בלי `ends`) ו-`block` בכל כותרת. `heading` —
  רק השורות המוזחות; כותרת באזור האגרות — היא בלבד. ההחלטות — `team/log/code.md`.
- `block-add` / `block-delete` ב-`lib/edit.php`; היומן של `block-delete` שומר את כל השורות עם המחירים ואת `after`.
- חתימות בהדפסה במידות קבועות (mm/pt) מחוץ ל-`.content`, כך ש-`fit()` מקטין רק את השורות.

## בדיקות

`php grave-prices/tests/run.php` — 194 בדיקות. `$legacy(name, version, edit)` בונה מסד ישן (01/02) עם עריכות, ואז
`gp_migrate`: כל השורות, רשומות המחיר, המחירונים והיומן — זהים; `migrate-abroad`; ישראל 2026 של הבעלים לא נדרס; גיבוי;
פריסה שנייה בלי כפילויות; כשל באמצע → הכול חוזר ו-`foreign_keys` חוזר לפעול. בדיקת דפדפן (Playwright, 390px): שרת
`php -S` עם router שמציב `PLATFORM_USER` ו-`GP_DATA_DIR` זמני; ההדפסות ב-`page.pdf` מול `team/source/`.
