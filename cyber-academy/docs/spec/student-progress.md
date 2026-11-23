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
                     "assistance": "none|hint|guided|full", "assessor", "assessedAt", "evidenceIds": [], "approvedBy", "approvedAt" } ],
  "evidence":    [ { "id", "kind", "reference", "assessor", "createdAt", "approvedBy", "approvedAt" } ],
  "reports":     [ { "id", "at", "reporter", "skillIds": [], "lessonIds": [], "assistanceLevel": "none|hint|guided|full|unknown", "summary" } ]
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
- דיווח מורה (`reports`) — **אין בו `status`**. הוא לא משנה סטטוס, רק נוסף למעקב. `at` — תאריך בלבד (`YYYY-MM-DD`, כשהשעה לא ידועה; לא ממציאים חצות) או תאריך-שעה RFC 3339 עם אזור זמן, ונשמר כמו שנשלח (שאר שדות הזמן — תאריך-שעה בלבד). `assistanceLevel` חובה; `unknown` נשמר כ"לא ידוע" ולעולם לא נספר כ-`none`. גם דיווח עם `none` **לא** מאפשר `mastered` — רק הערכה מאושרת עם `assistance: "none"`.
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

## מה הלומד רואה (`academy_progress_learner_view`)

רק מה שאומת, בלי נתיבים, מעריכים או דיווחים:
- `skills` — `category`, `status`, `updatedAt` לכל מיומנות. `counts`, `learned`, `proven`, `lessonsCompleted`.
- `assessments` — רק `evaluated` עם `assessedAt` + `approvedBy` + `approvedAt`: `{id, skillIds, at, result, approvedAt}`.
  `result` = הסטטוס המאומת של כל מיומנות שמפנה להערכה (`{skillId: status}`) — אין שדה `result` בהערכה עצמה.
- `evidence` — רק מאומתת: `{id, kind, createdAt}`. **בלי `reference`.**
- **אין** `reports`, `assistanceLevel`, `pendingReports`, `reportsByAssistance`, `assessor`, `assistance`, `approvedBy`.

המנהל (`progress.read.all`) מקבל את התצוגה המלאה, כולל דיווחי המורים לפי רמת סיוע.

## עמידות ואבטחת כתיבה (ביקורת צוות 1, 2026-11-23)

- **קובץ פגום ≠ ריק (F1):** קובץ לומד או `learners.json` שקיים אבל לא נקרא / לא JSON / לא אובייקט → 503
  `progress_unavailable` (`reason: progress_file_corrupt`, רק למנהל). **לא נכתב שום דבר מעליו.** רק קובץ שאינו קיים = ריק.
- **נעילה (F2):** כל `register` / `report` / `apply` רץ תחת `flock` על `.lock` בתיקייה — קריאה-שינוי-שמירה בלי לאבד עדכון.
- **היומן לעולם לא מתעד שינוי שלא נשמר (F3 + תיקון):** `academy_progress_commit` — (1) הקובץ החדש לקובץ זמני; (2) שורות
  היומן (עם `tx`), בלי `@`; (3) `rename` — נקודת ההתחייבות. כשל ב-1 או ב-2 → 500, הקובץ הקודם בתוקף ואין שורה. כשל ב-3 →
  היומן מקוצץ לגודלו שלפני (ftruncate); אם גם זה נכשל — שורת `rollback` עם אותו `tx`. הכול בתוך הנעילה.
- **`register` (F4):** `sub` שכבר ממופה ללומד אחר, או לומד שכבר ממופה ל-`sub` אחר → **409** `conflict`. שינוי רק עם
  `"force": true` (בוליאני), והיומן רושם `from` (הלומד הקודם) ו-`fromSub` (ה-sub הקודם של הלומד).
- **CSRF (F5):** כתיבה רק `same-origin`: `Sec-Fetch-Site` (אם נשלח) = `same-origin`, ו-`Origin` (אם נשלח) = המארח. בלי
  שתיהן — נדחה (403), חוץ מ-CLI (בדיקות). `Content-Type` — בדיוק `application/json` (פרמטרים כמו `charset` מותרים).
  **השלכה:** כתיבה מ-`curl` בשרת חייבת לשלוח `Origin` תואם; עדיף ממשק בדפדפן.
- **F6–F10:** `json_encode` נבדק; `assessmentId` ביומן — מחרוזת (ההערכה האחרונה של המיומנות); `reason` של 503 — רק למנהל;
  בלי `learnerId` ב-`error_log`; הקבצים נוצרים ב-umask 027 (0640 מההתחלה).
- **F11 — צומצם (הכרעת הבקרה):** רק `cli` (בדיקות) מקבל viewer בלי זהות. `cli-server` (`php -S`) מכבד `PLATFORM_USER` כמו
  השרת: בלי זהות או עם `granted: false` → אין תפקיד (403). לבדיקת דפדפן מקומית — `tests/browser/dev-identity.php` (auto_prepend,
  פועל רק ב-cli-server).
- **כתיבה חלקית של היומן:** אם נכתב פחות מהשורות המלאות — היומן מקוצץ לגודלו שלפני (בלי שורה שבורה), 500, ושום דבר לא נשמר.
- **סיכון שנותר (מתועד, לא מתוקן — הכרעת הבקרה):** תהליך שנהרג **בין** רישום היומן ל-`rename` משאיר ביומן `tx` בלי
  `commit`/`rollback` (שינוי שלא נשמר). תיקון מלא דורש יומן טרנזקציונלי. בינתיים: אחרי כל שמירה מוצלחת נרשמת שורת
  `{"action":"commit","tx"}`, ובכל כתיבה `academy_progress_scan_open_tx` מסמן בלוג השרת tx פתוחים (ספירה בלבד, בלי מזהה לומד),
  **בלי לשנות נתונים**. tx בלי commit = שינוי שלא נשמר ויש לבדוק ידנית.

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
