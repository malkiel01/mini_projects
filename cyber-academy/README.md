# אקדמיית סייבר התקפי · דשבורד

**מצב: בפיתוח · קריאה בלבד · נתוני דוגמה בלבד.**

דשבורד לבעל האקדמיה: תמונת מצב, סילבוס, התקדמות — **נלמד** מול **הוכח במעשה** כשני מדדים נפרדים — משימות, מבחנים
וראיות, פערים והמלצות, וציר זמן. מקור האמת הוא מאגר האקדמיה (`malkiel01/offensive-cyber-academy`); הדשבורד לא כותב אליו.

| מה | איפה |
| --- | --- |
| הדרישה במילים של הבעלים | `docs/owner-brief.md` |
| שכבות, מסכים, מקורות, קודי כשל | `docs/spec/00-overview.md` |
| מה חסר בחוזה v1, והצעה ל-v2 | `docs/schema-gaps.md` |
| כללי הצוות | `team/PROTOCOL.md` |
| אינטגרציה / נתונים / הרשאות | `lib/academy-source.php` · `lib/validate.php`, `lib/model.php` · `lib/access.php` |
| ה-API (GET בלבד) | `api/snapshot.php` |
| נתוני דוגמה | `samples/demo-snapshot.json` (11 מסלולים לפי ה-roadmap, כל כותרת "דוגמה · …"), `samples/empty-snapshot.json` |
| בדיקות | `php cyber-academy/tests/run.php` · דפדפן: `node cyber-academy/tests/browser/check.js` (מקומי) |
| נתוני ריצה בשרת | `data/` — מוחרגת מהפריסה וחסומה מהדפדפן (`config.json`, `cache/`) |

## החוזה

`dashboard/data/schema/v1/academy.schema.json`, קומיט **`cc85ac1`**, `schemaVersion 1.0.0`. snapshot שאינו תואם —
נדחה עם רשימת שגיאות, לא מוצג.

## מקור GitHub — כבוי

`data/config.json` (תבנית: `samples/config.example.json`). נדלק רק עם `github_enabled` + `privacy_confirmed` **וגם**
`ACADEMY_GITHUB_PHASE_ALLOWED` בקוד — היום `false`. האסימון (fine-grained, `Contents: Read`, מאגר אחד) נשאר בשרת.

## מה טרם הוכרע

- הנתיב של ה-snapshot האמיתי במאגר האקדמיה (`docs/schema-gaps.md` §10).
- כל מה שב-`docs/schema-gaps.md` — תלוי בחוזה v2 של מנהל האקדמיה.
- השאלות לבעלים — `docs/open-questions.md`.
