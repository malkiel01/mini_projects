# התקדמות לומד — אפיון ותשתית (שלב ראשון)

הוחלט על ידי מנהל 00 (2026-11-23), דרך הבקרה. **בשלב הזה: תשתית + אפיון + בדיקות.** אין סנכרון מ-Issues, אין הרחבה של
אסימון GitHub, אין מיגרציה של דוחות המורים, ואין ממשק התקדמות אישי מלא.

## מה כבר קיים, ומה לא

| | מצב |
| --- | --- |
| דוחות מורים (Issues #1–#4 במאגר האקדמיה) | טקסט חופשי, לא מובנה. **לא נקראים** — האסימון הוא `Contents: Read` בלבד, ואסור להרחיב אותו |
| `assessments` / `evidence` בחוזה v2 | שייכים ל**תוכנית**, לא ללומד. לא שמים שם נתוני תלמיד |
| `curriculum.php` | תוכנית האקדמיה בלבד. **לא קורא נתוני לומד ולא מחזיר שדה לומד** (נבדק לפי מפתחות) |
| התקדמות לומד | **חדש** — `lib/progress.php`, `lib/progress-api.php`, `api/progress.php`, `api/progress-write.php` |

## אחסון — מחוץ ל-GitHub

- ברירת מחדל: `cyber-academy/data/progress/` — חסום מהדפדפן (`data/.htaccess`), מוחרג מהפריסה (`/cyber-academy/data/*`
  ב-`deploy.yml`) ומגיט (`cyber-academy/.gitignore`).
- אפשר גם מחוץ לאתר: `progress_dir` בקונפיג השרת — נתיב **מוחלט, מחוץ ל-public_html**. אחרת → 500 `progress_dir_unsafe`.
- קבצים (0640):
  - `learners.json` — `{ "<sub של הפלטפורמה>": "<learnerId>" }`. מזהה פנימי; אין שם או מייל.
  - `learner-<learnerId>.json` — קובץ אחד לכל לומד (מבנה למטה).
  - `audit.log` — יומן ביקורת, שורת JSON לכל שינוי.

### המבנה `progress-1`

```json
{
  "schema": "progress-1", "learnerId": "lrn-001", "updatedAt": "2026-11-23T12:00:00Z",
  "skills":  { "<skillId>": { "status": "<7 הסטטוסים>", "updatedAt": "…|null", "assessmentIds": [], "evidenceIds": [] } },
  "lessons": { "<lessonId>": { "completed": false, "evidenceIds": [], "approvedBy": null, "approvedAt": null } },
  "assessments": [ { "id", "skillIds": [], "status": "planned|in_progress|evaluated",
                     "assistance": "none|hints|guided|full", "assessor", "assessedAt", "evidenceIds": [], "approvedBy", "approvedAt" } ],
  "evidence":    [ { "id", "kind", "reference", "assessor", "createdAt", "approvedBy", "approvedAt" } ],
  "reports":     [ { "id", "at", "reporter", "skillIds": [], "summary" } ]
}
```

`skillId` / `lessonId` — המזהים מתוכנית האקדמיה (v2). שדה זר → נדחה.

## שלוש קטגוריות — לא מתמזגות

| קטגוריה | מתי | מוצג |
| --- | --- | --- |
| `verified` | סטטוס שמבוסס על הערכה `evaluated` מאושרת (`approvedBy`+`approvedAt`) | הסטטוס |
| `reported` | יש דיווח מורה, ואין עדיין אימות | "ממתין לאימות" — **לא נספר כשליטה** |
| `not_assessed` | ברירת המחדל לכל מיומנות | "לא הוערך" — **לא 0** |

"נלמד" (`theory_demonstrated`) ו"הוכח" (`practice_demonstrated` + `mastered`) — שתי ספירות נפרדות, **רק ממה שאומת**. אין אחוז.

## כללי מצב — נאכפים בשרת (`academy_progress_validate`)

- **ראיה מאומתת** = `reference` בטוח (יחסי או https; בלי `..`, נתיב מוחלט, סוד או קובץ רגיש) + `assessor` + `approvedBy` + `approvedAt`.
- `gap_identified` / `theory_demonstrated` / `practice_demonstrated` / `reassessment_required` / `mastered` — רק עם הערכה
  `evaluated` על אותה מיומנות, עם `assessedAt` + `approvedBy` + `approvedAt`.
- `mastered` — בנוסף: הערכה **עצמאית** (`assistance: "none"`) **וגם** ראיה מאומתת.
- שיעור `completed: true` — רק עם ראיה מאומתת + `approvedBy` + `approvedAt`. לעולם לא אוטומטית.
- דיווח מורה (`reports`) — **אין בו `status`**. הוא לא משנה סטטוס, רק נוסף למעקב.
- כל כתיבה בודקת את הקובץ **כולו** אחרי השינוי. כשל → 422 עם השגיאות, והקובץ לא משתנה.

## גישה

| מי | איך נקבע | מה מותר |
| --- | --- | --- |
| אנונימי / בלי `granted` | אין `PLATFORM_USER.granted` מהשער | **403** |
| לומד (`viewer`) | מחובר ומורשה | `GET api/progress.php` — **רק את עצמו** (`sub` → `learnerId`). לומד אחר → 403. לא רשום / אין קובץ → `progress: null` / ריק |
| מנהל (`manager`) | `sub` שמופיע ב-`progress.managers` בקונפיג השרת (ברירת מחדל ריקה) | `GET …?learner=<id>` לכל לומד; `POST api/progress-write.php` |

`api/progress-write.php` — POST בלבד, `Content-Type: application/json` בלבד (טופס → 415), מנהל בלבד:
- `{"action":"register","learnerId","sub"}` — מיפוי משתמש פלטפורמה ללומד.
- `{"action":"report","learnerId","report":{…}}` — דיווח מורה.
- `{"action":"apply","learnerId","evidence":[…],"assessments":[…],"skills":{…},"lessons":{…}}` — שינוי, כפוף לכל הכללים.

## יומן ביקורת

`audit.log` — שורה לכל: `register`, `report`, `skill_status` (`from` → `to`), `lesson_completed`, `evidence_added`. בכל שורה:
`at`, `by` (sub של המנהל), `learnerId`, `target`, ומזהי הראיות/ההערכות. **בלי `reference` ובלי תוכן ראיה.** דחייה — לא נרשמת.

## מה נדרש מהמנהל בשרת (בלי נתוני תלמיד כאן)

1. בקונפיג השרת (`cyber-academy/data/config.json`): `"progress": {"managers": ["<sub של המנהל בפלטפורמה>"]}`.
   אופציונלי: `"progress_dir": "/home2/<user>/academy-progress"` (מחוץ ל-public_html, תיקייה 700).
2. התיקייה נוצרת בכתיבה הראשונה (0750, קבצים 0640). רישום לומד — `register`, ואז `apply` / `report`.
3. **לא בשלב הזה:** מיגרציה של דוחות #1–#4 — אחרי אישור נפרד של מנהל 00, ידנית ובמבנה למעלה (דוח לא מאומת = `report`, לא סטטוס).

## לא נבדק

- בשרת: הרשאות התיקייה, `PLATFORM_USER.sub` אמיתי מהשער, וחסימת `data/progress/` מהדפדפן.
- אין ממשק משתמש להתקדמות בשלב הזה — רק ה-API.
