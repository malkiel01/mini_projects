# יומן צ'אט הקוד — פרוסה N1 (data/words.json כפנים של הטבלה; סדר ידני; אקסל לפי id)

- 2026-11-12 · יחידה 1 (שרת): מיגרציה 19 (`sort_order`), `words_json()` (אחת — pv_words קוראת לה), מנוע שמירה אחד לקובץ ולאקסל
  (`words_file_save` / `words_csv_import` → `words_plan` → `words_apply`), `word_move`, הקובץ הווירטואלי בעץ (`lib/project.php`, id = -1),
  ווים מוספים ב-`lib/project_ops.php` (ZIP, שכפול — שאלה לבקרה נשלחה). בדיקות: `tests/cases/95-words-file.php` (14).
  - הכרעות טכניות: `rev` של הקובץ = חותמת התוכן (sha256 של words_json) — מזהה גם שינוי סדר ונושאים. קריאה לעולם אינה כותבת:
    sort_order ריק מחושב בזיכרון (lex_sort, כמו לפני N1) ונכתב רק בפעולה שמשנה סדר. ההקלטה בקובץ = `media/audio/<id>.<ext>` (כמו בחבילה של M1).
  - שינויים בבדיקות קיימות (הכרחיים): `10-db.php` — איפוס לגרסה 11 מסיר גם את העמודה החדשה; `20-api.php` — שורה ל-`word_move`.
