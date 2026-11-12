# יומן צ'אט הקוד — N2b (הקלטה שנייה, וכל שדה שמע, בספרייה)

- 2026-11-12 · התחלה. הענף `claude/kol-maroko-n2b` מ-origin/main (041813a). N3 לא מוזגה — §2.6 עוברת אליה (דרך `rec_slot_get/set`, `rec_sync`).
- 2026-11-12 · יחידה 1 (שרת): **מיגרציה 20** — `recordings` נבנית מחדש בעסקה אחת (CREATE `recordings_n2b` → INSERT SELECT של העמודות
  המשותפות → DROP → RENAME), ספירה + sha256 של כל העמודות המשותפות ושל `rec_versions` לפני ואחרי; לא תואם — `RuntimeException`
  והעסקה מתבטלת (הלולאה של `migrate` עברה ל-try/rollBack, ובודקת `user_version` שוב בתוך העסקה). העמודות: `list`, `field`,
  `deleted_list`, `deleted_field`; `UNIQUE (list, word_id, field)`; אינדקס על `file_id`. הסכמה עברה מ-`rec_db` למיגרציה.
  - הכרעה טכנית (ל-decisions.md): `word_id` נשאר בשמו (מזהה הפריט בתוך `list`); `field` שומר את **המפתח**; הראשי — השדה שה-col שלו audio.
  - `rec_slot_get/set` במקום `rec_word_audio`; מותאם — `field_parse` + `words_extra_write` + `updated_at/by` (ה-rev נגזר מהתוכן).
    כתובת באתר — רק בשדה הראשי (400 בשדה מותאם).
  - `rec_sync` — מכל שדה audio; `rec_rows()` — הראשי בלבד (זהה); `rec_rows_custom()`. החלפה / התרוקן / חזר — לכל מקום.
  - פעולות: `field` ב-`rec_replace` / `rec_connect` / `rec_add` / `rec_match` / `rec_duration`; `rec_move` (חדש, עורך); סל לפי מקום;
    ZIP `<id>-<key>.<ext>` + עמודות `list,field,field_label` (בסוף, כדי שהבדיקות של A3 יישארו); `rec_tree_path` — `media/audio/<id>-<key>`.
  - hooks: `rec_field_renamed` — **ב-`lib/fields_bind.php`** (שם יושב `field_key_apply` מאז N2, לא ב-lexicon.php; שורה אחת, באותה עסקה);
    `rec_field_dropped` (ספירה לאישור) ב-`field_delete`. שחזור שדה / מילה — `rec_sync` מחבר לפי מזהה הקובץ (בלי hook).
  - בדיקות: `tests/cases/98-rec-slots.php` (12), כולל מיגרציה על 1,500 הקלטות + 200 גרסאות + 40 בסל (זהים: words.json, rec_rows,
    rec_trash, files_in_use, rec_versions, כל השורות, שורות הספרייה) ו-ROLLBACK על checksum שאינו תואם. run.php 212 ✓.
- 2026-11-12 · מוזג origin/main (3973282: M5, V4, X1, N2c). M5 לקחה את 19 — **המיגרציה של N2b היא 20** (KOL_SCHEMA_VERSION = 20). run.php 256 ✓.
