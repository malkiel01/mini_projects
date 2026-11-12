# קול מרוקו — מסירה בין צ'אטי הקוד

מה שצ'אט הקוד הבא צריך לדעת. מוסיפים בסוף, לפי פרוסה.

## מ-V3 (נמסר דרך בקרה (3), 2026-11-12)

1. כל כתיבת עיצוב — `writeStyle(per, label)` ב-`screen.js`: `Map<אלמנט, [[מאפיין פיזי, ערך | null, יעד]]>`; כל אלמנט / יעד
   מתוכנן מחדש על הטקסט העדכני (`resolveTarget`); כל הקבצים — עסקה אחת (`pushTx`); ביטול / החזרה — `undoTx` / `redoTx`.
   גם `commit()` ו-`remove()` רושמים עסקה.
2. `Styler` (`style.js`): `targets`, `source`, `visible`, `plan(n, t, sets)` ← תיקונים לכל קובץ. **`checkValue` (דוחה `<`,
   `CSS.supports`) — חובה בכל מסלול כתיבה חדש.** כתיבה: `planRule`, `setComponent`, `planNewRule`, `planInline`.
3. תצוגה בזמן גרירה — DOM בלבד (`preview` / `unpreview`, `style` עם `!important` בקנבס, מוחזר בשחרור). לקובץ — רק `writeStyle`.
4. ידיות (`spacing.js`) — רק בלשונית "מתקדם" או 📏 / `M`. להרחבה: `targetFor`, `gapOf`, `whyNode`.
5. בחירה מרובה: `canvas.picked()`, `toggleMulti`.
6. ערך מ-`@media` נערך שם (`Design.effTarget`).
7. פתוח: `url()` בתוך `var()`; הביטול המשותף נשבר אחרי עריכה בעורך הקוד באמצע; Safari / Firefox / iOS לא נבדקו.

## מ-V5 (2026-11-12)

1. רקע — `editor/background.js`: חלק טהור (`KolBg.*`, נבדק ב-`tests/editor/background.mjs`) ו-`Bg` בדפדפן (`S.bg`).
   **כל url() שנכתב — דרך `urlCss` / `checkUrl`**, לעולם לא בשרשור.
2. כתיבה שאינה "מאפיין ← ערך" (הסרת שכבה, מחיקת כלל, HTML + CSS): `Bg.ruleOps(r, fn)` ← `screen.writeOps(list, label)`.
3. `covered('background')` מחזיר עכשיו את כל הרכיבים — `planRule` על רכיב של קיצור כותב הצהרה מפורשת אחריו.
4. `details.collect(n, { pseudo: 'before' })` — הכללים של פסאודו-אלמנט.
5. כשהפאנל סגור (טלפון) `Design.t` לא מאותחל — לפני `D.put` מחוץ לפאנל: `Bg.ready(n)`.

## מ-F2b (2026-11-12)
- הקוד ב-`lib/project_ops.php`. כל פעולה שכותבת לקבצים אחרים — דרך `pf_put` (rev+1 ושורת היסטוריה).
- `pf_refs_plan` / `pf_ref_scan` — מקור אחד ל"מי מפנה למי" (href/src/srcset/url()/@import/import/kol.json entry); שמיש ל-F3/F4/M1 לבדיקת הפניות שבורות.
- ערך שנכתב לקובץ: `pf_ref_enc` לכתובות; מחרוזת JSON ל-kol.json — כל קוד חדש שכותב לקובץ לפי אותו כלל.
- בממשק: `syncOpen()` מעדכן קובץ פתוח שהשרת שינה, כצעד שאפשר לבטל.
- מטלה לבעלים (שרת חי): להוריד ZIP, להעלות חזרה (הכול "זהה"), ZIP של עשרות MB (post_max_size).

## מ-N1 (2026-11-12, נרשם בידי בקרה (4))
- data/words.json הוא קובץ וירטואלי (id -1, lib/project.php: pf_words_*), ונבנה מ-words_json() ב-lib/lexicon.php. אין לו שורה ב-pf_files. כל פעולה חדשה על העץ לפי id צריכה לטפל ב-pf_is_words($b).
- מנוע אחד לשינויים בטבלה: words_plan / words_apply (תוכנית לפי id, אסימון "<חותמת הטבלה>.<חותמת התוכנית>", עסקה אחת, סל, ונקודת שחזור מעל 20 או במחיקה). N2/N3/V11 עוברות דרכו, ולא כותבות ישירות ל-words.
- sort_order ריק = סדר מחושב (lex_sort). words_order_fill(true) לפני כל פעולה שמשנה סדר, בתוך העסקה. בעסקה שנפתחה ב-exec('BEGIN IMMEDIATE'), PDO::inTransaction() מחזירה false.
- mbstring לא מכיר Windows-1255 (נזרק ValueError). להשתמש ב-cp1255_utf8().
- פתוח: PV_WORDS קבוע בתצוגה ובבנייה (שינוי שם דרך kol.json משפיע רק על העץ); חיפוש בכל הקבצים לא כולל את הקובץ; kol-data.js עדיין 1.1.0.

## מ-A3 (2026-11-12, נרשם בידי בקרה (4))
- מילה ↔ הקלטה = words.audio; כל פעולה כותבת אותו ו-rec_sync. לא מחוברת = recordings.word_id NULL. סל = deleted_at. גרסאות = rec_versions (גרסה בלי קובץ = הכתובת שבאתר).
- הנתיב היציב media/audio/<id>.<ext> מתעדכן בהחלפה (rec_tree_point) רק כשהעץ קיים; הסיומת נשארת המקורית גם כשהסוג משתנה → B1 בודקת Content-Type.
- files_in_use כולל recordings ו-rec_versions; מחיקה מ-🗂️ קבצים של קובץ הקלטה נחסמת.
- ZIP: בלי ZIP64 (תקרה ~4GB); צעד = 192MB או 4 שניות. לא נבדק בשרת החי.
- מרוץ studio.js: תוקן ב-mount (mine = S).

## מ-N2b (2026-11-12, נרשם בידי בקרה (5))
- `recordings` נוצרת רק במיגרציה 20 (`REC_SCHEMA` ב-`lib/db.php`), ולא ב-`rec_db`. שינוי בטבלה — רק במיגרציה, עם אותה בדיקה (`migrate_sum`).
- `migrate()` מקבל גם שלב PHP: ערך שמתחיל ב-`migrate_` הוא שם של פונקציה, והיא רצה בתוך העסקה של השלב. חריגה בתוכה מבטלת את כל השלב.
- הקלטה בשדה מותאם נכתבת רק דרך `rec_slot_set`. כתיבה ישירה ל-`words.extra` תגרום ל-`rec_sync` לחשוב שההקלטה הוחלפה מחוץ לספרייה, והוא ישמור גרסה.
- שינוי מפתח של שדה שמע חייב לקרוא ל-`rec_field_renamed` באותה עסקה. אחרת נוצרת רשומה כפולה במפתח החדש.
- N3 §2.6: להוסיף `list != 'words'` ב-`rec_slot_get` / `rec_slot_set`, ב-`rec_sync` (היום הוא קורא רק את המילים) וב-`rec_tree_path`. כל השאילתות כבר מסננות לפי `list = 'words'`.
- בספרייה, השורה של שדה מותאם מזוהה כ-`"<מזהה>:<מפתח>"`. המילה עצמה תמיד ב-`r.wid`, דרך `widOf` ב-`library.js`.

## מ-M3 (2026-11-12, נרשם בידי בקרה (5))
- מסך 🔁 מעבר קורא מה-ping של התוסף (M2b): `protocol`, `plugin`, `v2_live`, `v2_on`, ומונה `v2_stats` (אובייקט, מוצג כמו שהוא). כשהתוסף יחזיר אותם — אין צורך לשנות את המסך.
- שורת המשוב ✓ = `forms_draft()` לא ריק, וכל טופס בו רשום ב-`forms_live()` (M5).
- `m3-fidelity.cjs --self-test` — בלי האתר (~3.5 דק'); מול האתר האמיתי — רק מדפדפן שעובר את Cloudflare.
- פתוח (לא של M3): `a2.cjs` #3 לא יציב גם ב-main (מרוץ בעליית הסטודיו — העורך עובר ל-#/files והסטודיו ממשיך; התיקון של A3 אינו מכסה) · `l1.cjs` כותב את `dev/l1/*.png` בריפו בכל הרצה · חבילת M1 שמישה בטלפון אחרי ~6 שניות, מול ~3.5 בתצוגה החיה · `pkg_site_blobs` אחרי `asset_missing` (`lib/build.php`).

## מ-M4-BC (2026-11-12, נרשם בידי בקרה (5))
- "איך העמוד מוצג באתר" — היום ב-`assets/js/publish.js` (`mountBlock`); כש-P1 ("⚙️ בעל האתר") תיבנה — להעביר לשם.
- ping מחזיר `v2_mount`; מסך הבעלים מזהה תוסף ישן לפי היעדרו (409 `plugin_old`).
- הבדיקות: `tests/plugin/60-mount_test.php` · `tests/cases/99-mount.php` · `tests/browser/m4bc.cjs` · `m2.cjs` סעיף ה.
- באתר (מטלה): ערכת ההתקפה H1–H17 ב-Chrome/Firefox/Safari; מה `get_header()` מדפיס בערכה האמיתית (ש-24) — האם העמוד ברוחב מלא; כפילות og/title מתוסף SEO; LiteSpeed מול המעטפת.
## מ-V6 (2026-11-12, חלקי — ראו team/log/code-V6.md "❌")

1. `editor/position.js` (`S.pos`) — מיקום חופשי ושכבות; `editor/layout.js` (`S.lay`) — פריסה, גודל, ידיות גודל. שניהם
   מוסיפים קבוצות ללשונית "מתקדם" דרך `S.adv.extra`.
2. `screen.joinTx(k, label)` — מאחד k עסקאות אחרונות (HTML שהוזז דרך `act` + עיצוב דרך `writeStyle`).
3. גיאומטריה למיקום — **אחרי** שהאלמנט יצא מהזרימה (preview של position:absolute), כי הייחוס עשוי להתכווץ.
4. ידיות הגודל — `prepend` לשכבה (מתחת ל-`.sp` של V3); אחרת הן מכסות את ידית הרווח (v3.cjs נכשל).

## מ-V6b (2026-11-12, נרשם בידי בקרה (5))
- בדיקות: גרירה מעל הקנבס (iframe sandbox) — `page.mouse` אינו מסתיים, ולכן PointerEvent ידני; בידיות שבמעטפת — `page.mouse` אמיתי; במגע — אירועים אל הידית עצמה.
- ידית שכותבת לאלמנט אחד מתוך כלל משותף: `layout.ownTarget(n, taken)` → `newclass`.
- ערך כתוב מחוץ לפאנל: `layout.decl(n, phys)`. `D.curDeclared` משתמש במטמון של הפאנל, שבטלפון סגור ולכן אינו מתעדכן.
- `v6.cjs`: ה-390 נפתח רק אחרי שהדסקטופ שמר את הביטול — השמירה האוטומטית משותפת לשני ההקשרים.

## מ-P1 (2026-11-12, נרשם בידי בקרה (5))
- **פעולה חדשה = שורה ב-`ACTION_ROLES` (`lib/auth.php`)**, בלי `require_role` ב-api.php. בלי שורה — 404, ו-`tests/cases/21-roles.php` נכשלת.
- זהות — רק דרך `lib/login.php`. התפקידים: `content_editor` / `editor` / `publisher` / `page_owner` / `site_owner` (**'owner' כבר לא קיים**).
- בדיקת דפדפן חדשה — הזהות גם בעוגייה `kol_test_user` (בקשת מדיה בלי זהות מוחקת את ה-session).

## מ-T1 (2026-11-12, נרשם בידי בקרה (6))
- `plugin_zip` (בעל האתר) — `lib/plugin_zip.php`; `wordpress-plugin/` נפרס עכשיו לשרת (מוגן ב-`.htaccess` וב-`ABSPATH`). קובץ חדש בתוסף = בתוך ה-ZIP אוטומטית.
- `lock_op` ב-`BEGIN IMMEDIATE`. כל עסקה שקוראת ואז כותבת — אותו דבר (DEFERRED נכשלת ב-SQLITE_BUSY_SNAPSHOT בעומס).
- בדיקות דפדפן כותבות צילומים ל-tmpdir בלבד (`/tmp/kol-<פרוסה>-shots/`) — לא לריפו.
- `Library.view(el, owner, page)` — `pageOwner` לפעולות של בעל העמוד ומעלה.

## מ-V7 (2026-11-12, נרשם בידי בקרה (6))
- כתיבה לפי מכשיר: `St.dev(t)` לפני `writeStyle` — אותו בורר בתוך `@media (max-width: <max>px)`; `planDevice` בונה/מוצא את הבלוק אחרי הכלל הבסיסי. **V8 (פסאודו-מחלקות):** `planDevice` מקבל בורר — `:hover` במכשיר = אותו בורר עם הפסאודו בתוך ה-@media.
- `Styler.perDevice / devOwn / ownDecl / isDev(max)`; `DEVICES` מ-kol.json (`kolChanged`); `canvas.devHidden / markDev`; `editor/devices.js` (נראות לפי מכשיר).
- ❌ פתוח: `Bg.ruleOps` כותב לבסיס גם במכשיר · הזזת HTML במיקום חופשי ביחס לאב רחוק — לכל המכשירים · Safari/Firefox/iOS לא נבדקו.
- בטלפון (<1000px) שדה הרוחב החופשי מוסתר (⚙ נשאר) — כדי שהסרגל לא יגדל בשורה ויכסה את המקלדת (v2).
- 🟡 בפאנל העיצוב ב-1920 חלק מהערכים חתוכים בתוך השדה ("norma"). התוויות שלמות.

## מ-V8 (2026-11-12, נרשם בידי בקרה (6))
- מצב: `Styler.st(t, {pc, of})` מוסיף `t.state.sel`; הכתיבה — `planState` (מיד אחרי הבסיס) או `planDevice` עם בסיס = כלל המצב. `details.collect(n, {state})` — המפל במצב.
- הקנבס במצב (`states.js`): `data-kol-st` ועותקי כללים עם `[data-kol-st~=…]`; `details.collect` מדלג עליהם.
- דירוג במפל — **רק** `KolDetails.rankOf` (8 רכיבים, כולל שכבה וקרבה); כלל חדש — `rankNew` ב-style.js. לא בונים דירוג ביד.
- `identity.js`: `checkAttr/checkClass/checkId`, `renameHtml/renameCss`, `setAttr` (מקודד < >). **כל כתיבת תכונה — דרכם.**
- `extra.js`: `CONTROLLED` — מאפיין שמקבל שליטה ייעודית (V9/V10) נוסף לרשימה, אחרת יופיע גם ב"מאפיינים נוספים".
- בבדיקות: כפתורי מצב — `.sg-state-seg [data-state]` (`[data-state]` קיים גם על שורות `sg-dev`).
- `.sg` (עיצוב/מתקדם) — עכשיו גולל (לא היה overflow גם ב-main).
- ❌ "לא חל על אף אלמנט" · "מאפיינים נוספים" ב"תוכן"/"פרטים" · `::before`+מצב · Safari/Firefox.
