# יומן צ'אט הקוד — פרוסה N3 (רשימות נוספות: טבלה, קובץ, שדות ואקסל לכל רשימה)

- 2026-11-12 · התחלה מ-origin/main (3973282). אין `team/handoff/code-N1.md` / `code-N2.md` ב-main באותו רגע — נקראו היומנים
  `code-N1.md`, `code-N2.md`, `code-N2c.md`.
- יחידה 1 (שרת): מיגרציה (lists / list_fields / list_items / list_trash, `IF NOT EXISTS`), `lib/lists.php` — מודל, `list_json` (טבלת
  תוויות לכל שדה בחירה + items), תוכנית/החלה לפי id בעסקה (כמו N1), אקסל (+ "מאקסל כרשימה חדשה"), סדר (`words_order_assign`),
  סל (פריט / שדה / רשימה), מבנה לכל רשימה (`field_input`, המרת סוג), 🔑 מפתח שדה ומפתח רשימה עם הקשירות, `list_place`, שורה
  למסך המפתח. `project.php`: `pf_list_paths()` / `pf_list_of()` — קובץ וירטואלי לכל רשימה (קריאה, raw, שמירה, גרסאות, "מחיקה" =
  הסרה מ-kol.json, שכפול = עותק סטטי, ZIP). `preview.php` — הגשה; `build.php` — `build_lists` (prerender — כבר כללי לכל
  `data-kol-list`). `restore.php` — `snap_state.lists`, הסל. `api.php` — 13 פעולות, ו-`fields`/`field_*` עם `list`.
  - הכרעה טכנית: `lists.fid` — מזהה מספרי קבוע לקובץ בעץ (`-(1000+fid)`), לא חוזר (`setting lists_fid`).
  - הכרעה טכנית: id שנמחק אינו חוזר גם מקובץ / אקסל — `id < next_id` שאינו קיים = שגיאה ("שחזור מהסל").
  - הכרעה טכנית: במילים — טבלת תוויות לשדה בחירה בראש `words.json` (§2.2); בלי שדה כזה — הקובץ זהה בבייטים (נבדק).
- יחידה 2 (ממשק): `words.js` — בורר ב"📚 נתונים" (`app.js` — רק שם הלשונית); `lists.js` (חדש, נטען לפי הצורך כמו `fields.js`);
  `fields.js` — `structure(onChange, list)`, `rekey(kind='list')`; `restore.js` — "רשימות" בסל; CSS — כל רוחב המסך במחשב.
- מוזג origin/main (e5169de: N2b — מיגרציה 20, M3 — 21). המיגרציה של N3 — **22**. `IF NOT EXISTS` — כי בדיקות N2b מריצות מחדש מ-19.
- שינויי בדיקות של אחרים (הכרחיים): `10-db.php` (DROP של ארבע הטבלאות), `20-api.php` (13 שורות), `40-devloop.php` (בדיקת "lists").
