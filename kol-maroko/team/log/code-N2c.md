# יומן צ'אט הקוד — N2c (שלוש ההשלמות של N2, מ-team/handoff/code-N2.md בענף N2)

- 2026-11-12 — (1) `project.js`: בשגיאת 422 של `data/words.json` — כפתורי "צור שדה" (`data-create-field`) ו"הוסף אפשרות" (`data-create-option`)
  מ-`field_proposals` / `option_proposals`; השמירה החוזרת וה"החל" שולחים `create_fields` / `create_options` (כמו `create_topics`). שגיאות השדה נשארות
  ברשימה עם השורה (כמו שהיה — n1.cjs נשען על זה). בתוכנית: "שדות חדשים" / "אפשרויות חדשות". (2) שכפול הקובץ המקושר — `warning` בטוסט אחרי "שוכפל".
  (3) `fields.js` `show` (rich) ו-`plain()` ב-`words.js` — `DOMParser` במקום `innerHTML` על דיב מנותק.
  בדיקות: n2.cjs +6 (35/35), n1.cjs 39/39, run.php 215/215. ❌ לא נבדק בדפדפן: הטוסט עצמו של אזהרת השכפול (נבדקה התשובה של `pf_copy`), וכפתור "הוסף אפשרות" (רק "צור שדה").
