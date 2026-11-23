# יומן — code


## 2026-11-23 · פרוסה ראשונה — הדשבורד כולו, קריאה בלבד (PR #1808)

**נבנה:** שלוש שכבות (אינטגרציה `lib/academy-source.php`; נתונים `lib/validate.php` + `lib/model.php` + `api/snapshot.php`;
UI `index.html` + `assets/js/*`), `lib/access.php` (viewer), נתוני דוגמה (11 מסלולים), 8 מסכים, `tests/run.php` (124 בדיקות),
`docs/spec/00-overview.md`, `docs/schema-gaps.md`, README, app.json (🛡️, v0.1).

**ברירות מחדל שלקחתי (הפיכות, בלי לעצור):**
- מקור github נעול גם בקבוע קוד `ACADEMY_GITHUB_PHASE_ALLOWED=false` — לא רק בדגלי הקונפיג. הדלקה = PR.
- "נלמד" = learning + theory_demonstrated; "הוכח" = practice_demonstrated + mastered (לפי owner-brief 2.4), ספירות ולא אחוז.
- מסלול "הוכח" = יש בו כשירויות וכולן הוכחו; "התחיל" = כשירות שהוערכה או משימה שאינה planned. מסומן "נגזר".
- `planned` מוצג כ"פתוחות".
- האימות דוחה גם הפניות שבורות, מזהה כפול ומעגל דרישות קדם (לא בסכמה; ב-schema-gaps §8).
- נתיב ה-snapshot במאגר: `dashboard/data/snapshot.json` ב-main — הנחה בקונפיג (schema-gaps §10).
- `evidenceRefs` מוצגים כטקסט, לא כקישור.

**נדרש מהבקרה:** deploy.yml — שלב בדיקה `php cyber-academy/tests/run.php` (כמו sforno), ו-`--exclude='/cyber-academy/tests/'`
(רשות: יש `tests/.htaccess` ו-run.php מחזיר 404 מחוץ ל-CLI).

## 2026-11-23 · פרוסה 02 — מוצע/מאושר, מצבים מובחנים, דוגמה/אמת (PR #1808, אותו ענף)

לפי ההודעה של הבקרה. מאגר האקדמיה פרטי — עבדתי רק מול `cc85ac1`.
- `curriculumStatus: "proposed"` + `curriculumStatusSource: "not_in_contract_v1"` מהמודל; `approval` נקרא רק מחוזה שאינו v1.
- `code` יציב לכל כשל (`lib/api.php`); מיפוי GitHub ב-`academy_github_error()`; מסכי מצב ב-`assets/js/states.js`; שלד טעינה.
- github קורא קודם `commits/<ref>` ואז את הקובץ באותו קומיט → `sourceInfo.commit` לפס "נתוני אמת".
- `fallback_to_sample` (ברירת מחדל true) — false נותן `source_disabled`.
- "לא דווח" לשדה אופציונלי חסר. בדוגמה: `prerequisites: []` למסלול 1 (ייצוג בלבד — בלי שינוי תוכן או סטטוס).
- בדיקות 124 → 265. בדיקות ה-github הישנות (קריאה אחת ל-transport) נכתבו מחדש לשתי קריאות — אותן טענות.
- `tests/browser/check.js` — 80 צילומים, 0 בעיות.
- הערה: האסימונים המזויפים בבדיקות נבנים בזמן ריצה, כדי שסריקת `ghp_`/`github_pat_` בקבצים תהיה נקייה.

## 2026-11-23 · תיקון ביקורת פרוסה 02 — שמות נחתכים בטלפון

- שם בשורת רשימה (`.use-tx > b`) עובר שורה ולא נחתך. התגים עברו לשורה מתחת לשם (`meta` ב-`row()`): בסילבוס 📘/🧪/מוצע, ובכשירויות, במשימות, בציר הזמן ובראיות — תג הסטטוס. שורת המשנה עדיין נחתכת ב-….
- `tests/browser/check.js`: בכל מסך ומצב, ובכל רוחב, אף `.use-tx > b` לא נחתך (`scrollWidth ≤ clientWidth` ובלי ellipsis). 410 שמות נבדקו. וידאתי שהבדיקה תופסת את הבאג: מול ה-CSS הקודם היא מדווחת 44 בעיות.
- **forbidden מהשער — נבדק בקריאת קוד, לא בבדיקה חיה:** `deny()` ב-`platform/lib/gate.php` מחזיר JSON עם 401/403 כשה-Accept לא כולל text/html. ה-fetch שלנו שולח `Accept: application/json`, ולכן הבקשה לא מופנית, והקוד 401/403 ממופה ל-forbidden ב-`states.js`.

## 2026-11-23 · פרוסה 03 — snapshot אמת ריק (c4e7a8d)

- `measure: null` כשאין כשירויות. אחת מבדיקות פרוסה 01 ("מודל ריק מסומן empty") ציפתה ל-`measure.total === 0`, ועודכנה ל-`null`. זה שינוי מכוון: אין "0 מתוך 0".
- אמת + ריק → `CA.realEmpty(name)` בכל מסך, ופס "מאגר מחובר · snapshot ריק · קומיט". דוגמה ריקה לא השתנתה.
- fixture `tests/fixtures/academy-snapshot-c4e7a8d.json` הוא שחזור לפי תיאור (README). הבדיקה מכסה את שני המקרים של `updatedAt`: null ותאריך.
- `samples/config.example.json`: הנתיב `dashboard/data/snapshot.json` — אומת. המנעולים לא שונו.
- run.php: 265 → 281. check.js: 80 → 113 צילומים (11 מסכים × 3 רוחבים של אמת-ריק). וידאתי שהבדיקה נכשלת בלי התיקון: 51 בעיות, כולל מונים בלשוניות המשימות.

## 2026-11-23 · v2 — שלב א: פערים בין הדשבורד (v1) לחוזה v2

מקור: התוכן שהבקרה העבירה מהמאגר (schema v2, migration-and-validation-proposal, החלטת מנהל 00, CURRICULUM-REVIEW, ותיאור
ה-fixture 49f4b2d). לא נקרא ישירות — `add_repo` נדחה בסשן הקוד. Issue #6 לא נגיש, ותוכנו לא הונח.

**מקרא:** ✅ קיים · ➕ חסר — לבנות · ⛔ חוסם / לא ניתן להכריע מהקוד.

| ישות | קיים (v1: model / validate / screens) | חסר ל-v2 | חוסם |
| --- | --- | --- | --- |
| **curriculum** | ✅ `curriculumStatus: proposed` קבוע (`not_in_contract_v1`) | ➕ לקרוא `status`/`version`/`approvedAt`/`approvedBy`; כלל approved⇔שניהם מלאים, proposed⇔שניהם null | — |
| **stages** | אין — רק "אין בחוזה" | ➕ ישות, `trackIds`, סדר, קיבוץ מסלולים לפי שלב | ⛔ לא הוכרע אם stage הוא שלב התקדמות או קבוצת נושא (שאלה פתוחה במסמך המעבר). הדשבורד יציג "קבוצת מסלולים" |
| **tracks** | ✅ id/title/order/prerequisites | ➕ `stageId`, `approvalStatus` לכל מסלול, `instructorId`, `description`; מסלול מאושר רק כשגם `curriculum.status=approved` | — |
| **modules** | אין | ➕ ישות, `learningObjectives`, `labRequired`, `lessonIds`, prerequisites | — |
| **lessons** | אין | ➕ ישות: `kind` theory/analysis/lab, `assessmentMode`, `authorizedLabRequired` (כתג), `learningObjectives` (≥1) | ⚪ במסמך המעבר כתוב "module.lessonIds בלי מערך lessons — פער". ב-schema שהועבר יש `lessons` — הפער נסגר בגרסה הזו |
| **topics** | אין | ➕ ישות תחת module | — |
| **skills** | אין | ➕ ישות תחת module, `assessmentCriteria` | — |
| **competencies** | ✅ status (7), evidenceRefs, lastAssessedAt | ➕ `skillId` במקום `trackId` (המסלול נגזר: skill→module→track); **אין `title`** — מוצג לפי ה-skill; כל השדות חובה (אין "לא דווח") | ⚪ `mastered` דורש הערכה עצמאית + ראיות + אישור — נאכף כהפניה להערכה `evaluated` עם `approvedBy` |
| **tasks** | ✅ status (4), mode, competencyIds | ➕ `skillIds` במקום `competencyIds`; `dependsOn` (בלי עצמה, בלי מחזור); `authorizedLab` | ⚪ סיבת חסימה — עדיין אין |
| **assessments** | אין (רק "אין בחוזה") | ➕ ישות: status planned/in_progress/evaluated, `rubric`, `skillIds`, `evidenceRefs`, `assessedAt`, `approvedBy`. אין הערכות → "אין הערכות מתועדות", לא 0% | ⛔ rubric לכל מיומנות ומדדי עצמאות — לא מאופיינים (שאלה פתוחה) |
| **evidence** | רק `evidenceRefs` כמחרוזות | ➕ ישות: `kind`, `reference` (מסונן — בלי מפתחות, סיסמאות או נתיבים רגישים), `createdAt`, `visibility` | ⚪ "הפניה מאושרת" לא מוגדרת — הסינון שמרני: יחסית או https, בלי `..` ובלי תבניות סוד |
| **timeline** | נגזר מ-updatedAt + lastAssessedAt | ➕ אירועים אמיתיים: kind curriculum/task/assessment/decision, `referenceId` | ⚪ לא מוגדר לאיזו ישות `referenceId` מצביע — לא נאכף |

**רוחבי:**
- `validate.php` מכיר רק `1.0.0`. ➕ ניתוב לפי `schemaVersion`: 1.0.0 / 2.0.0, וכל השאר → `contract_version`.
- `model.php` שטוח (track → competency). ➕ מודל v2: stage → track → module → lesson, ו-topics/skills תחת module. "נלמד" ו"הוכח" עדיין שני מדדים, דרך skill→module→track. אין אחוזים.
- `screens.js` בנוי ל-v1. ➕ מסכי v2 (שלב, מסלול, מודול עם שיעורים). מסכי v1 נשארים ל-snapshot v1.
- מקור github: ➕ `v2_path` (ברירת מחדל ריקה → fail-closed), וסינון "סילבוס מאושר בלבד": רק כש-`curriculum.status=approved`, ורק tracks מאושרים עם הישויות שתחתם. **בלי** competencies/tasks/assessments/evidence/timeline (מידע לימודי/אישי).
- ⛔ **הנתיב של v2 במאגר לא ידוע.** `dashboard/data/snapshot.json` נשאר v1 (לפי מסמך המעבר). לכן אין חיבור v2 בלי הכרעה.
- ⛔ **החוזה עצמו "PROPOSED ONLY"** — v2 לא מאושר לייצור. הדשבורד יקרא אותו, אבל המקור כבוי.
- היום ב-fixture: כל המסלולים `proposed` → סינון "מאושר בלבד" יחזיר **0 מסלולים**. זה צפוי.

## 2026-11-23 · v2 — שלבים ב–ד: תמיכה בחוזה v2, GitHub מסלולים מאושרים בלבד (כבוי), בדיקות

- **ענף:** `claude/cyber-academy-dashboard`, אחרי fast-forward ל-`origin/main` (2dd682d). PR טיוטה חדש. לא `claude/cyber-academy-v2` (אין לי הרשאה לענף הזה).
- **קבצים חדשים:**
  - `lib/validate-v2.php`, `lib/model-v2.php`
  - `assets/js/screens-v2.js`
  - `tests/fixtures/v2/` (קטע 49f4b2d, fixture סינתטי מלא, 64 שבורים, README)
  - `tests/browser/v2-bodies.php`
- **קבצים ששונו:** `lib/api.php` (ניתוב 1.0.0/2.0.0, סינון github), `lib/academy-source.php` (`contract`, `github.v2_path`), `assets/js/app.js`, `screens.js` (realEmpty עם `filter`), `index.html`, `samples/config.example.json`, `tests/run.php`, `tests/browser/check.js`, המסמכים.
- **בדיקה מול מנוע אמיתי:** jsonschema 4.26 (Draft 2020-12), ה-schema שהועבר נשמר רק ב-scratchpad:
  - הקטע וה-fixture המלא — 0 שגיאות.
  - כל `schema--*` נכשל, כל `rule--*` עובר את ה-schema.
- **שינוי בבדיקה קיימת:** "גרסת חוזה אחרת → contract_version" השתמשה ב-2.0.0, ש**נתמך עכשיו**. הוחלף ל-3.0.0.
- **בדיקות:** run.php 281 → 564. check.js 113 → 224 צילומים, 536 שמות שאינם נחתכים.
- **לא נבדק:** חסימת שער אנונימית ו-`data/` בשרת (צריך פריסה); GitHub אמיתי (אין גישה); Issue #6 (403); ה-fixture המלא של 49f4b2d (204 שיעורים) — לא הגיע אליי.

## 2026-11-23 · v2 — עדכון לפי הודעות הבקרה (Issue #6, הכרעות מנהל 00, הסילבוס המאושר 419417a)

ההודעות נשלחו לפני ה-PR, אבל הגיעו אליי אחריו. מה ששונה:
- **קונפיג:** `github.snapshot_path` (v1), `github.curriculum_path` = `dashboard/data/curriculum-v2-approved.json`, `github.contract_v2_path` (לעיון בלבד).
  - זה מחליף את `path`/`v2_path`. שתי בדיקות v2 שלי עודכנו לשמות החדשים.
  - נתיב ריק → `source_disabled`. המנעולים לא שונו.
- **"מאושר" רק מהקובץ:** הסילבוס מאושר רק כש-`status=approved` וגם `approvedAt`/`approvedBy` מלאים. מסלול מאושר רק כשגם הוא `approved`.
  - לא הוספתי את השורה "אושרה מקצועית — נתוני האישור טרם עודכנו". הודעה 4 החליפה אותה: הקובץ המאושר קיים, והסטטוס מגיע ממנו.
- **`api/curriculum.php`** — חדש (Issue #6, משימה 4): תוכנית מאושרת בלבד, למשתמש מחובר ומורשה, בלי נתוני לומד.
- **`lib/access.php`:** בשרת אמיתי דורש `PLATFORM_USER.granted === true` (fail-closed). CLI ו-`php -S` → viewer של פיתוח. **שינוי התנהגות גם ל-`api/snapshot.php`.**
- **"secrets" בטקסט הסילבוס:** הסינון חל רק על `evidence.reference`. נוספה בדיקה ש-"least privilege ו-secrets" עובר.
- **timeout:** stub → `source_unavailable`.
- **תצוגה:** מסך הסילבוס ב-v2 נקרא "תוכנית האקדמיה". נוסף מצב "מאושר" לבדיקת הדפדפן.
- **בדיקות:** run.php 564 → 596. check.js 224 → 242 צילומים.

## 2026-11-23 · v2 — תיקון אבטחה: האסימון רק מקובץ מחוץ לאתר (חוסם מיזוג של #1809, מנהל 00)

- `github.token_file`, נתיב מוחלט. מפתח `token` הוסר מהקונפיג ומהתבנית. אם הוא קיים, גם ריק → `source_disabled`/`token_in_config`, והקובץ לא נפתח.
- **בתוך עץ האתר → `secret_in_webroot`.** נבדקים: שורש mini_projects, cyber-academy, DOCUMENT_ROOT, וכל `public_html`/`www`/`htdocs`, לפי הנתיב המבוקש וגם לפי realpath (לחסימת קישור סימבולי).
  - שיניתי מהבקשה: הבקרה הציעה `ACADEMY_DIR/../..`. זה תיקייה אחת מעל השורש, וזה עלול לחסום נתיב תקין בבית המשתמש. במקומו — השורש עצמו ועוד הכללים למעלה.
- **הרשאות:** o+r → `secret_insecure_perms`.
- **תוכן:** אחרי trim, ערך קצר מ-20 או עם רווח → `source_unauthorized`/`token_invalid`.
- **מתי נפתח:** רק כשכל המנעולים פתוחים. נבדק עם reader שרושם קריאות.
- **`.gitignore`:** של האפליקציה (`cyber-academy/.gitignore`), לא השורשי — הוא משותף לכל האפליקציות, ומחוץ לתחום שלי. מכסה `data/config.json`, `data/cache/`, `*.token`.
- **בדיקה שעודכנה:** "config.example: אסימון ריק" → "אין אסימון", בכוונה.
- **בדיקות:** run.php 596 → 623.
- **לבקרה:** `docs/owner-brief.md` סעיף 2 עדיין אומר "אסימון … ב-`data/config.json`". הקובץ שלכם — לא שיניתי.

## 2026-11-23 · הכנת הפעלת מקור GitHub — PR נפרד מ-main (63ecd71), טיוטה, בלי הדלקה

- `ACADEMY_GITHUB_PHASE_ALLOWED = true` — מנעול הקוד בלבד. התבנית וברירת המחדל כבויות.
- **החלטה שלי, fail-closed:** מ-GitHub נקרא **רק** `contract: "v2"` (curriculum_path). v1 → `source_disabled`/`v1_github_blocked`, כי snapshot v1 כולל נתוני לומד בלי סינון. זה סוגר את השאלה הפתוחה על חסימת v1 ממקור github.
- `api/curriculum.php`:
  - לא דורס יותר את `source` — נדלק רק כשהשרת קבע `source: "github"`.
  - דורש role ≠ null עם `curriculum.read` וגם `snapshot.read`.
  - `$opts` (server/sapi/reader/transport) — לבדיקות בלבד.
- **מטמון:** קבצים ב-0640.
- **ממצא בבדיקות:** בדיקה בלי `cache_ttl: 0` כתבה מטמון ל-`data/cache` של הריפו, והחזירה את התשובה הראשונה לכל השאר. תוקן (TTL 0 בבדיקות), ונוספה בדיקה שלא נשאר מטמון בריפו. הקובץ שנכתב לא הכיל אסימון.
- **בדיקות שעודכנו בכוונה** (הנחו שהמנעול סגור):
  - "קבוע השלב: github אסור" → "המנעול פתוח".
  - "גם עם שני הדגלים — עדיין local" → "v1 נחסם".
  - "snapshot_path ריק" ו-"v1 קורא את snapshot.json" → "v1 חסום".
  - "המנעול בקוד סגור" → פרמטר `phase=false`.
  - "ברירת המחדל של הקוד (היום)" → ברירת המחדל והתבנית עם המנעול פתוח.
  - `$cfg` לבדיקות ההסתרה: `github_enabled` → false.
- **בדיקות:** run.php 623 → 660.
- **URLs שה-stub רשם (מקצה לקצה):**
  - `https://api.github.com/repos/malkiel01/offensive-cyber-academy/commits/main`
  - `https://api.github.com/repos/malkiel01/offensive-cyber-academy/contents/dashboard/data/curriculum-v2-approved.json?ref=<sha>`
- `docs/spec/github-activation.md` — מה מגדירים בשרת, ומה בודקים אחרי.

## 2026-11-23 · התקדמות לומד — תשתית, אפיון ובדיקות (PR טיוטה, בלי מיזוג)

- **חדש:**
  - `lib/progress.php` — אחסון, אימות כללי המצב, תצוגה בשלוש קטגוריות, מי השואל.
  - `lib/progress-api.php`.
  - `api/progress.php` (GET), `api/progress-write.php` (POST, מנהל).
  - `docs/spec/student-progress.md`.
  - `tests/fixtures/progress/` — סינתטי בלבד, 18 שבורים.
- **שונה:**
  - `access.php` — תפקיד `manager`, ו-`progress.read.self` ל-viewer.
  - `academy-source.php` — `progress_dir`, `progress.managers` בברירות המחדל, אחרת `academy_load_config` היה זורק אותם.
  - `config.example`, `.gitignore` (`data/progress/`).
  - `api.php` — `curriculum.php` מסיר `competencyStatus` מהמיומנויות. המפתח היה שם, תמיד null; זה שדה לומד.
- **בדיקה שעודכנה בכוונה:** "אין שום הרשאת כתיבה" → "viewer בלי כתיבה, והיחידה במערכת היא progress.write של manager".
- **מנהל = sub ב-`progress.managers`.** ברירת המחדל ריקה, ואין מנהל עד שהשרת מגדיר. platform_admin לא הופך למנהל אוטומטית.
- **בדיקות:** run.php 660 → 772. אין שינוי UI, ולכן check.js רץ כבדיקת רגרסיה בלבד.

## 2026-11-23 · #1811 — נתוני תלמיד רק מתיקייה מחוץ לאתר (הוראת מנהל 00)

- **אין יותר ברירת מחדל `data/progress/`**: לא בקוד, לא בתבנית, לא בתיעוד ולא ב-`.gitignore`.
- **חסימה:** כש-`progress_dir` לא תקין — 503 `progress_unavailable`, עם `reason`: `progress_dir_missing` / `_not_absolute` / `_unsafe` / `_insecure`.
  - חסום גם בקריאה וגם בכתיבה, לפני שנפתח קובץ כלשהו.
  - הבדיקה רצה על הנתיב המבוקש וגם על realpath.
- **אין `mkdir`.** התיקייה ידנית. כתיבה אטומית עם ניקוי הקובץ הזמני בכשל (גם ל-`learners.json`).
- **הוסר `$opts['dir']`.** הבדיקות עוברות תמיד דרך `academy_progress_dir`.
- **שגיאות כתיבה:** 500 בלי נתיב. ללומד — קובץ לא תקין מחזיר 422 בלי פירוט ובלי תוכן.
- **בדיקות:** run.php 772 → 819.
  - נוספו: ברירת מחדל, יחסי, שורש, cyber-academy, public_html, ‎/www/‎, `..`, DOCUMENT_ROOT מדומה, קישור סימבולי, תיקייה חסרה (לא נוצרת), הרשאות 0755/0644/0666/0703, תיקייה חוקית, כשל כתיבה בלי קובץ חלקי, תוכן מזויף שלא דולף.
  - בסוף: הריפו זהה לרשימה שלפני, ואין בו קובץ לומד.

## 2026-11-23 · #1811 — רמת סיוע `hint`, ושדות חדשים בדיווח מורה

- **רמת סיוע בהערכות:** `hints` → `hint`. שונה ב-`ACADEMY_PROGRESS_ASSISTANCE`, ב-19 ה-fixtures ובאפיון.
- **דיווח מורה — שדות חדשים:**
  - `lessonIds` — אופציונלי.
  - `assistanceLevel` — חובה: `none | hint | guided | full | unknown`. ה-fixtures עודכנו ל-`unknown`.
- **ספירות בתצוגה:** `reportsByAssistance` ו-`reportAssistance` לכל מיומנות. `unknown` נספר בנפרד, לעולם לא כ-`none`.
- **mastered:** דיווח (גם עם `none`) לא משנה סטטוס ולא מאפשר mastered. נבדק.
- **בדיקות:** run.php 819 → 829.

## 2026-11-23 · #1811 — התצוגה של הלומד: רק מאומת, בלי נתיב, מעריך או דיווחים

- **`academy_progress_learner_view`:**
  - הערכות מאומתות — `{id, skillIds, at, result, approvedAt}`. `result` = `{skillId: status}` מהמיומנויות שמפנות להערכה, כי אין שדה result בחוזה.
  - ראיות מאומתות — `{id, kind, createdAt}`.
  - מיומנויות — `{category, status, updatedAt}`.
- **מנהל** — התצוגה המלאה, כמו קודם.
- **בדיקות:** run.php 829 → 838. כוללות סריקת מפתחות רקורסיבית, בדיקה שאין נתיב או שם מעריך בשום ערך, ובדיקה שרק המאומת מופיע.

## 2026-11-23 · #1811 — `reports[].at`: תאריך בלבד או תאריך-שעה (מנהל 00)

- **סוג חדש `date_or_dt` ב-`_av2_type`:** `YYYY-MM-DD` תקין (checkdate), או RFC 3339 עם אזור זמן. הערך נשמר כמו שנשלח.
- **רק ב-`reports[].at`.** `createdAt`, `assessedAt`, `approvedAt` ו-`updatedAt` נשארים `dt`/`dt?`.
- **בדיקות:** run.php 838 → 854.

## 2026-11-23 · #1811 — תיקוני ביקורת צוות 1 (docs/audit/progress-audit-2026-11-23.md בענף claude/aca-security-audit)

תוקנו F1–F10. F11 נשאר — מידע בלבד (php -S לפיתוח), ותועד באפיון.

| ממצא | התיקון |
| --- | --- |
| F1 | קובץ פגום → 503 `progress_file_corrupt`. לא "ריק", ולא נכתב מעליו |
| F2 | `flock` על `.lock` סביב כל כתיבה. נבדק עם 3 תהליכים × 20 כתיבות במקביל: 60/60 |
| F3 | היומן נכתב לפני השמירה, בלי `@`. כשל → 500 בלי שמירה |
| F4 | 409 על מיפוי קיים, `force: true` בלבד. היומן רושם `from`/`fromSub` |
| F5 | same-origin בלבד (Sec-Fetch-Site/Origin), ו-media type מדויק |
| F6–F10 | `JSON_THROW_ON_ERROR`, `assessmentId` כמחרוזת, `reason` רק למנהל, בלי `learnerId` בלוג, umask 027 |

**בדיקה שעודכנה בכוונה:** "כשל כתיבה → 500" — יעד שהוא תיקייה נחשב עכשיו קובץ פגום (503, F1). נוספה בדיקה ישירה של כשל rename.
**בדיקות:** run.php 854 → 906.

## 2026-11-23 · #1811 — היומן לעולם לא מתעד שינוי שלא נשמר

- **`academy_progress_commit`:** קובץ זמני → יומן (עם `tx`) → rename, שהוא נקודת ההתחייבות.
  - כשל ב-rename → ftruncate של היומן לגודל שלפני. אם גם הקיצוץ נכשל → שורת `rollback` עם אותו `tx`.
  - שלושת מסלולי הכתיבה (register / report / apply) עוברים דרכה.
- **`$opts['rename']`** — להזרקת כשל, בבדיקות בלבד.
- **בדיקות:**
  - כשל rename ב-report, apply ו-register → 500. הקובץ והיומן זהים בייט-לבייט, ואין קובץ זמני.
  - `tx` בשורה מוצלחת.
  - `force` ממי שאינו מנהל → 403.
  - F11: ב-litespeed בלי `granted` אין תפקיד. cli-server מתעלם מ-`PLATFORM_USER` — מתועד, ולא שונה.
- **מקביליות — הבדיקה הוכחה:** כשהורדתי זמנית את `flock`, הבדיקה נכשלה (54/60 — 6 עדכונים אבדו). עם הנעילה — 60/60.
- **בדיקות:** run.php 906 → 923.

## 2026-11-23 · #1811 — יומן חלקי, tx פתוח, F11 צומצם

- **יומן חלקי:** בכתיבה חלקית `academy_progress_audit` מקצץ לגודל שלפני וזורק חריגה, ולכן 500 ובלי שמירה. `$opts['auditWrite']` משמש להזרקה.
- **סיכון שנותר — מתועד:** אחרי rename מוצלח נרשמת שורת `commit` עם ה-tx. `academy_progress_scan_open_tx` רץ בכל כתיבה ומסמן בלוג tx פתוחים, בלי לשנות נתונים ובלי מזהה לומד.
- **F11:** רק `cli` מקבל viewer בלי זהות. `cli-server` דורש `PLATFORM_USER.granted`. נוסף `tests/browser/dev-identity.php` לבדיקת הדפדפן המקומית.
- **בדיקות שעודכנו בכוונה:**
  - "CLI ו-php -S → viewer" → `cli-server` בלי זהות מחזיר null.
  - "tx בשורה האחרונה" → שתי השורות האחרונות: השינוי, ואחריו commit.
  - "F11: cli-server → viewer גם עם PLATFORM_USER" → שלוש בדיקות חדשות.
- **בדיקות:** run.php 923 → 936. check.js ✓ (עם dev-identity).

## הסרת `reference` מתצוגת תוכנית האקדמיה / תמונת המצב (v2)
- **הכרעת בקרה:** `model-v2.php` החזיר `evidence[].reference` לכל צופה. עכשיו הראיה יוצאת כ-`{id, kind, createdAt, visibility}` בלבד.
- **הולידציה לא שונתה** — `reference` לא בטוח עדיין נדחה (`/etc/passwd`, `..`, תבניות סוד).
- **UI:** מסך "מבחנים וראיות" כבר לא מציג את ההפניה (סוג, תאריך ונראות בלבד).
- **בדיקות חדשות:** סריקה רקורסיבית — אין מפתח `reference` בתגובת `curriculum.php` ובתמונת המצב v2 עם ראיה מדומה; מפתחות הראיה במודל בדיוק `id, kind, createdAt, visibility`; `visibility` נשאר; הולידציה דוחה הפניה לא בטוחה.
- **בדיקות:** run.php 937 → 944. check.js ✓ (עם dev-identity).
