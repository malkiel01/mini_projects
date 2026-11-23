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

## אחסון — רק תיקייה מחוץ לאתר (הוראת מנהל 00)

**אין ברירת מחדל.** נתוני תלמיד נקראים ונכתבים **רק** מ-`progress_dir` בקונפיג השרת. בלי תיקייה תקינה — `api/progress.php`
ו-`api/progress-write.php` מחזירים **503 `progress_unavailable`** (עם `reason`, בלי נתיב), ושום קובץ לא נפתח ולא נוצר.

| המצב של `progress_dir` | `reason` |
| --- | --- |
| ריק / לא מוגדר | `progress_dir_missing` |
| נתיב יחסי | `progress_dir_not_absolute` |
| בתוך העץ: שורש mini_projects, `cyber-academy/`, `DOCUMENT_ROOT`, כל `/public_html/`, `/www/`, `/htdocs/`, או `..` — לפי הנתיב המבוקש **וגם** לפי `realpath` (קישור סימבולי לתוך העץ נחסם) | `progress_dir_unsafe` |
| התיקייה לא קיימת (לא נוצרת אוטומטית) | `progress_dir_missing` |
| קריאה או כתיבה לכולם (o+r / o+w) | `progress_dir_insecure` |

- התיקייה נוצרת **ידנית** על ידי המנהל: למשל `/home2/<user>/academy-progress`, `chmod 700`.
- קבצים (0640), כתיבה אטומית (קובץ זמני + rename; בכשל הזמני נמחק — לא נשאר קובץ חלקי):
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

1. ליצור ידנית תיקייה מחוץ ל-public_html, `chmod 700`: למשל `/home2/<user>/academy-progress`.
2. בקונפיג השרת (`cyber-academy/data/config.json`): `"progress_dir": "<הנתיב המוחלט>"` **וגם**
   `"progress": {"managers": ["<sub של המנהל בפלטפורמה>"]}`. בלי שניהם — אין קריאה וכתיבה של נתוני תלמיד.
3. רישום לומד — `register`, ואז `apply` / `report`.
3. **לא בשלב הזה:** מיגרציה של דוחות #1–#4 — אחרי אישור נפרד של מנהל 00, ידנית ובמבנה למעלה (דוח לא מאומת = `report`, לא סטטוס).

## לא נבדק

- בשרת: התיקייה בפועל והרשאותיה, `PLATFORM_USER.sub` אמיתי מהשער.
- אין ממשק משתמש להתקדמות בשלב הזה — רק ה-API.

## ממשק — "ההתקדמות שלי" (צוות 2)

מסך `#/learner` (חוזה v2 בלבד; ב-v1 מוצגת הודעה). נטען מ-`api/progress.php` — רק הלומד המחובר, בלי מזהה מהלקוח. שמות השיעורים והמיומנויות — מהסילבוס.

| חלק במסך | מה מוצג | כלל |
| --- | --- | --- |
| מצב המיומנויות | מאומת / ממתין לאימות / לא הוערך — שלוש שורות נפרדות | "מאומת" בלי הערכה מאושרת → "עדיין אין הערכות מאומתות", לא 0 |
| נלמד ו"הוכח במעשה" | שני מדדים, רק מתוך המיומנויות שאומתו | בלי מאומתות — אין מדדים |
| הערכות וראיות מאושרות | מיומנויות מאומתות בלבד | פירוט ההערכות והראיות טרם מוחזר ב-API |
| פערי ידע | `gap_identified` / `reassessment_required` (מאומתות בלבד) | בלי פערים — הודעה, לא רשימה ריקה |
| המשימה הבאה | נגזרת: קודם פער שסומן, אחרת השיעור הבא בסדר הסילבוס המאושר | מסומנת "נגזר" |
| שיעורים | הושלם — רק עם ראיה מאומתת ואישור; השאר "לא הושלם" (מתקפל) | — |

קבצים: `assets/js/learner.js` (הלוגיקה והתצוגה), `assets/js/api.js` (`CA.api.progress`), בדיקות: `tests/browser/check.js` (נתונים סינתטיים דרך `page.route` בלבד).
