# פערי החוזה v1 — מה הדרישה צריכה ואין בסכמה

**לבעלים ולמנהל האקדמיה.** החוזה: `dashboard/data/schema/v1/academy.schema.json`, קומיט `cc85ac1`, `schemaVersion 1.0.0`.
הדשבורד לא ממציא: כל פער כאן מוצג במסך כ"אין עדיין נתונים בחוזה v1". **ההצעות למטה הן הצעה בלבד** — הדשבורד לא
כותב למאגר האקדמיה, ושינוי בחוזה עובר דרך הבעלים למנהל האקדמיה.

## הצעה כוללת: `schemaVersion: "2.0.0"`

הכלל: כל אובייקט חדש — רשימה ברמה העליונה עם `id`, והפניות לפי מזהה (כמו היום). `additionalProperties: false` נשאר.

### 1. שלבים, מודולים, נושאים, מיומנויות
הדרישה: "פירוק כל מסלול לשלבים, מודולים, נושאים ומיומנויות" ו"מעקב התקדמות בכל רמה". היום: מסלול ← כשירות בלבד.

```json
"stages":  [{ "id", "trackId", "title", "order" }],
"modules": [{ "id", "stageId", "title", "order", "prerequisites": ["<moduleId>"] }],
"topics":  [{ "id", "moduleId", "title", "order" }],
"skills":  [{ "id", "topicId", "title" }]
```
ובכשירות: `"skillIds": ["…"]` (או `topicId`) — כך הסטטוס נשאר על הכשירות, וההתקדמות ברמות העליונות מחושבת ממנה.

### 2. מבחן, הערכה וראיה כאובייקט
היום: `evidenceRefs` (מחרוזות בלי משמעות מוגדרת) ו-`lastAssessedAt`. אין סוג, תוצאה, מעריך או מועד לכל ראיה.

```json
"assessments": [{
  "id", "competencyId", "kind": "theory_quiz|practical_lab|capstone|reassessment",
  "at": "date-time", "result": "passed|failed|partial", "assessor": "<roleId>",
  "environment": "authorized_lab|ctf|…", "evidenceIds": ["…"]
}],
"evidence": [{ "id", "kind": "report|writeup|recording|artifact", "title", "ref": "<נתיב יחסי במאגר>", "createdAt" }]
```
`evidenceRefs` הישן → `evidence[].id`. הסטטוס `practice_demonstrated` יוכל להצביע על ההערכה שהוכיחה אותו.

### 3. ציר זמן — אירועים
היום רואים רק מצב אחרון; אי אפשר לדעת "ממה למה".

```json
"events": [{ "id", "at", "kind": "status_change|task_status|assessment|note",
             "subject": { "type": "competency|task|track", "id" }, "from", "to" }]
```

### 4. משימות — תלות וסיבת חסימה
היום: `blocked` בלי סיבה, ואין תלות בין משימות.

```json
"tasks[]": { …, "dependsOn": ["<taskId>"], "blockedReason": "string|null",
             "dueAt": "date-time|null", "updatedAt": "date-time" }
```

### 5. המלצות
היום הדשבורד גוזר המלצות לבד (מסומן "נגזר"). המלצה של מדריך צריכה שדה משלה:

```json
"recommendations": [{ "id", "title", "reason", "subject": { "type", "id" }, "priority": 1, "by": "<roleId>", "at" }]
```

### 6. משתמשים ותפקידים
הדרישה: "בעתיד כמה משתמשים ותפקידים". היום ה-snapshot הוא של לומד אחד, בלי מזהה.
הצעה: snapshot **לכל לומד** (קובץ לכל לומד, או `learnerId` ברמה העליונה), ו-`roles` מוגדרים בדשבורד ולא במאגר.
**לא להכניס פרטים אישיים (שם, מייל) לחוזה** — רק מזהה אטום.

### 7. כותרות בכמה שפות
היום `title` מחרוזת אחת. הצעה: `title` נשאר (ברירת מחדל), ונוסף `"titleI18n": { "he": "…", "en": "…" }` — אופציונלי.

### 8. כללים שהדשבורד כבר אוכף ואין בסכמה
הדשבורד דוחה גם את אלה (JSON Schema לא יכול לבטא אותם):
- הפניות: `competency.trackId`, `task.competencyIds`, `track.prerequisites` — חייבים מזהה קיים.
- מזהים ייחודיים בכל רשימה.
- אין מעגל בדרישות קדם.
הצעה: לכתוב אותם בתיעוד החוזה (`dashboard/README.md`), כדי שמי שמפיק את ה-snapshot יידע.

### 9. מה "סיום מסלול" ו"דרישת קדם הושלמה"
לא מוגדר בחוזה. הדשבורד מגדיר (ומסמן "נגזר"): מסלול הוכח = יש בו כשירויות וכולן `practice_demonstrated`/`mastered`.
הצעה: שהאקדמיה תקבע — `tracks[].completionRule` או סטטוס למסלול.

### 10. קובץ ה-snapshot במאגר
החוזה מגדיר תבנית אבל לא **איפה** נמצא ה-snapshot האמיתי. הדשבורד מניח `dashboard/data/snapshot.json` בענף `main`
(ניתן לשינוי בקונפיג). צריך הכרעה של מנהל האקדמיה.

### 11. סילבוס מוצע מול מאושר
v1 לא מגדיר אישור סילבוס, וה-roadmap במאגר הוא "proposal". לכן הדשבורד מציג **כל מסלול כ"סילבוס מוצע · לא אושר"**
(`curriculumStatus: "proposed"`, `curriculumStatusSource: "not_in_contract_v1"`) ולעולם לא מסיק "מאושר" בעצמו.

```json
"curriculum": { "status": "proposed|approved", "approvedAt": "date-time|null", "approvedBy": "<roleId>|null", "version": "string" },
"tracks[]":   { …, "approval": "proposed|approved" }
```
הדשבורד כבר קורא את `tracks[].approval` — רק מחוזה שאינו v1, וכשהערך אחד משני אלה. ב-v1 השדה נדחה באימות.

### 12. שדה אופציונלי חסר מול ריק
ב-v1 `evidenceRefs`, `lastAssessedAt` ו-`prerequisites` אופציונליים. הדשבורד מבחין: חסר = **"לא דווח"**, ריק / `null` =
"אין" / "טרם הוערך". הצעה: ב-v2 להפוך אותם לחובה (`[]` / `null` במפורש), כדי שהיעדר מידע לא ייראה כמו מידע.

## v2 (הצעה, 2026-11-23) — מה נסגר ומה עדיין פתוח

v2 סוגר את רוב הסעיפים 1–6 ו-11 למעלה (שלבים, מודולים, שיעורים, נושאים, מיומנויות, הערכות, ראיות, אירועים,
`dependsOn`, אישור סילבוס). עדיין פתוח (הערות לחוזה, מהדשבורד):
- **competency בלי `title`** — הדשבורד מציג את שם ה-skill. אם אפשר כמה כשירויות לאותה מיומנות, צריך title.
- **`event.referenceId`** — לא מוגדר לאיזו ישות הוא מצביע, ולכן לא נאכף. הצעה: `reference: {type, id}`.
- **`blockedReason`** למשימה — עדיין אין.
- **`stages`** — שלב התקדמות או קבוצת נושא? הדשבורד מציג "קבוצת מסלולים".
- **`approvedBy` (הערכה / סילבוס)** — מחרוזת חופשית. הצעה: מזהה תפקיד מוגדר, לא שם אדם.
- **`evidence.reference`** — "הפניה מאושרת" לא מוגדרת. הדשבורד מתיר רק נתיב יחסי או https.
- **`format: date-time`** — מנוע JSON Schema בלי בודק פורמט לא אוכף אותו. הדשבורד כן אוכף.
- **נתיב v2 במאגר** — לא נקבע (`snapshot.json` נשאר v1).
