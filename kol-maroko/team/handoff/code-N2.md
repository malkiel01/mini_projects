# העברה — צ'אט הקוד של N2 (שדות מותאמים, נושאים מורחבים)

N2 מוזגה: PR #1469 ← `041813a`, מיגרציה 18. היומן המלא: `team/log/code-N2.md`. התיעוד: `README.md`, הסעיף "🧩 שדות מותאמים…".

## איפה הקוד
- `lib/lexicon.php` — הקטעים "N2: שדות מותאמים", "N2: נושאים מורחבים", ו"N2: מבנה המילה". המודל: `fields_all` (ארבעת המובנים
  בזיכרון עד פעולת מבנה ראשונה; `fields_fill` כותב אותם), `field_parse` (11 הסוגים, הדלת `$csv`), `field_out` / `field_cell`
  (הערך בקובץ / בתא אקסל), `topics_all` / `topics_fill` / `topics_rename` / `topic_attrs`. המנוע של N1 (`words_file_items` →
  `words_plan` → `words_apply`) מטפל גם ב-`extra`, בעדכוני נושאים, וב"צור שדה" / "הוסף אפשרות".
- `lib/fields_bind.php` — הסריקה של הקשירות (`fields_bind_scan`), `field_key_preview` / `field_key_apply` (kind=field|topic).
- `lib/build.php` — `build_words`: רק שדות שמתפרסמים; קבצים לשדות image/audio ולתמונות הנושאים.
- `assets/js/fields.js` — נטען מ-`words.js` לפי הצורך (`fieldsReady`), בלי שורה ב-`studio.html`.
- בדיקות: `tests/cases/96-fields.php`, `tests/cases/97-topics.php`, `tests/browser/n2.cjs`.

## ההכרעות הטכניות (ל-decisions.md)
1. `topic_trash.row` (עמודה נוספת במיגרציה 18, הוספה בלבד) — בלעדיה שחזור של נושא שהוסר / מוזג מחזיר slug חדש (kt-md5).
2. שדה מובנה אינו נמחק (הערך בעמודה קבועה); מסתירים אותו ב"מתפרסם". הסוג שלו — רק במשפחה (`FIELD_COL_TYPES`).
3. ההרשאות לפי 08 §3 (#1467): המבנה — עורך; "רוקן את הנכשלים" ומחיקת שדה שיש לו ערכים — מפרסם (נבדק בפונקציות).
4. התווית "שדות פנימיים" — בתוך `note` של הקובץ (מוצגת ב-`project.js` בלי לשנות אותו); השינוי ב-`lib/project.php` — רק
   `PF_WORDS_AUDIT`, `pf_words_internal` ותוויות הגרסאות.
5. צורת ה-`counts` של N1 נשמרת: מפתחות N2 (`topic_changes`, `new_fields`, `new_options`) — רק כשיש. בשורת הסיכום — רק שדות מותאמים.
6. `extra` נשמר תמיד ממוין לפי מפתח (`ksort`) — כך ההשוואה בתוכנית (`!==`) יציבה.
7. ערך של "פירוש" עובר תמיד דרך `sanitize_rich`, גם כשהסוג שלו text/longtext (קשירה `data-kol-html` — בטוח).

## שלוש ההשלמות הפתוחות (לצ'אט הבא)
1. **כפתורי "צור שדה" / "הוסף אפשרות" בעורך הקוד** (`assets/js/project.js`, באישור הבקרה): בשגיאת 422 של שמירת `data/words.json`
   השרת מחזיר `errors[].propose_field` / `errors[].propose_option` (וגם `field_proposals` / `option_proposals`); השמירה החוזרת
   שולחת `create_fields: [key]` / `create_options: ["key:value"]` — בדיוק כמו `create_topics` של N1. הצד של השרת נבדק ב-96-fields.php.
2. **אזהרת השכפול בטוסט**: `pf_copy` של הקובץ המקושר מחזיר `warning` ("העותק כולל … שדות פנימיים, והוא יתפרסם כקובץ רגיל") —
   להציג ב-`project.js` אחרי השכפול.
3. **הגנה לעומק ב-`assets/js/fields.js:27`** (`show`, סוג rich): `document.createElement('div').innerHTML = v` — `<img onerror>` רץ
   גם בדיב מנותק. להחליף ב-`new DOMParser().parseFromString(v, 'text/html').body.textContent`. (הערך כבר עבר `sanitize_rich`
   בשרת, ולכן זו הגנה לעומק.) כדאי לבדוק גם את `plain()` ב-`words.js` — אותה תבנית, מ-N1.

## מה עוד לא נבנה (בגלוי, לפי הפרוסה)
- "תצוגת שינויים" כשסוג "פירוש" עובר ל-text — לא נבנתה; הסוג של מובנה משנה רק את הממשק.
- הקלטה שנייה בספריית ההקלטות — N2b (אחרי A3); גרירת שדה לכרטיס — V11; רשימה שנייה — N3.
- `a2.cjs` נפלה פעם אחת (timeout ב-`.lf-panel`) ועברה פעמיים אחרי — רעועה, לא קשורה ל-N2.
