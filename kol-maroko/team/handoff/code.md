
## מ-F2b (2026-11-12)
- הקוד ב-`lib/project_ops.php`. כל פעולה שכותבת לקבצים אחרים — דרך `pf_put` (rev+1 ושורת היסטוריה).
- `pf_refs_plan` / `pf_ref_scan` — מקור אחד ל"מי מפנה למי" (href/src/srcset/url()/@import/import/kol.json entry); שמיש ל-F3/F4/M1 לבדיקת הפניות שבורות.
- ערך שנכתב לקובץ: `pf_ref_enc` לכתובות; מחרוזת JSON ל-kol.json — כל קוד חדש שכותב לקובץ לפי אותו כלל.
- בממשק: `syncOpen()` מעדכן קובץ פתוח שהשרת שינה, כצעד שאפשר לבטל.
- מטלה לבעלים (שרת חי): להוריד ZIP, להעלות חזרה (הכול "זהה"), ZIP של עשרות MB (post_max_size).

## מ-N1 (2026-11-12, נרשם בידי בקרה (4))
- data/words.json הוא קובץ וירטואלי (id -1, lib/project.php: pf_words_*), ונבנה מ-words_json() ב-lib/lexicon.php. אין לו שורה ב-pf_files. כל פעולה חדשה על העץ לפי id צריכה לטפל ב-pf_is_words($b).
- מנוע אחד לשינויים בטבלה: words_plan / words_apply (תוכנית לפי id, אסימון "<חותמת הטבלה>.<חותמת התוכנית>", עסקה אחת, סל, ונקודת שחזור מעל 20 או במחיקה). N2/N3/V11 עוברות דרכו, ולא כותבות ישירות ל-words.
- sort_order ריק = סדר מחושב (lex_sort). words_order_fill(true) לפני כל פעולה שמשנה סדר, בתוך העסקה. בעסקה שנפתחה ב-exec('BEGIN IMMEDIATE'), PDO::inTransaction() מחזירה false.
- mbstring לא מכיר Windows-1255 (נזרק ValueError). להשתמש ב-cp1255_utf8().
- פתוח: PV_WORDS קבוע בתצוגה ובבנייה (שינוי שם דרך kol.json משפיע רק על העץ); חיפוש בכל הקבצים לא כולל את הקובץ; kol-data.js עדיין 1.1.0.
