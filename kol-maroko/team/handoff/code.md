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

## מ-V9 (2026-11-12, נרשם בידי בקרה (6))
- `KolGlobals` (globals.js) — **כל כתיבה גלובלית דרכו:** planNewVar / planSetVar / planRename / planReplaceValue / planFontFace / planLinkInsert / planLinkDrop. כל ערך — `checkRaw` (+ `checkValue` / `CSS.supports`); שם משפחה — `FAMILY`, שם משתנה — `VAR_NAME`.
- פעולת שרת שהיא חלק מעסקה (קובץ שנוצר) — `attachFx({undo, redo})` מיד אחרי writeOps; `joinTx` שומר אותה.
- "⛓" (קישור למשתנה) — דרך `D.varBtn` / `D.varChip` בשורת המקור (srcLine). סוג שליטה חדש שצריך משתנה — להוסיף ל-`kindOfProp`.
- בבדיקות: לשונית `.ed-ptabs [data-ptab="glob"]`; הכנה שעוקפת עסקאות — רק לפני תמונת המצב ההתחלתית.
- **בקשה יוצאת חדשה — רק דרך `font_http`** (או אותו מבנה: רשימת מארחים, https, בלי הפניות, IP נבדק ומוצמד), עם בדיקות ב-tests/cases.
- ❌ ראו reviews/V9.md.

## מ-M7 (2026-11-12, נרשם בידי בקרה (6))
- **כל מה שיוצא לאתר — רק דרך `site_call(action, payload|Closure(seq,exp), user, meta)`.** פקודה חדשה = שורה ב-`OUTBOX_ACTIONS` + `OUTBOX_TTL` + טיפול ב-`v2-pull.php` (`kol_maroko_pull_apply`) + ב-`outbox_effect` אם הכלי צריך לדעת שהוחלה.
- בערוץ המשיכה אין תשובה מיידית: שום מצב "באתר" לפני `applied`; ping = הדופק (`pull_pulse` / `outbox_ping`).
- התוסף: הסמן (`pull_cursor`) מתקדם רק על פקודה חתומה; מצב התוסף לכלי — `kol_maroko_ping_data()` (מסונן ב-`PULL_STATE_KEYS` — שדה חדש = שורה שם). דיווח שנחסם (429) — ניסיון חוזר בעוד דקה.
- מטמון: `immutable` רק לפי hash; אחרת `max-age=0` + ETag + `CDN-Cache-Control: no-store`.
- בדיקות: `tests/plugin/fake-pull.php` (כלי מדומה וזדוני); מקביליות אמיתית = שני שרתי `php -S` על אותה תיקייה (H28). בדיקה שסופרת בקשות — לאפס את מונה הקצב.
- `publish.js` נטען לפני `app.js`: בלי `h()` בקוד שרץ בטעינה.

## מ-V10 (2026-11-12, נרשם בידי בקרה (6))
- כתיבה: `KolFx.checkSets` (checkFx ← checkValue) ← `Design.put`; מכשיר, מצב, ⟲ ו-Ctrl+Z — בחינם.
- `css/motion.css` דרך `Motion.ensureCss(need)` (`KolFx.planMotion` מוסיף בסוף רק מה שחסר); `js/kol-motion.js` דרך `Motion.ensureJs`; קובץ שנוצר בשרת — לביטול דרך `fxFor`.
- **מחלקות מערכת** (`kol-in*`, `kol-sc`, `bg-slides-N`, `kol-slides-*`) — כל מחלקה חדשה כזו ל-`SYS` ב-`Styler.targets`, אחרת תהפוך ליעד.
- `covered('border'|'border-radius')` מחזירים את השם ואחריו הרכיבים; "כל הצדדים" כותב `border`, צד/פינה — לוגי.
- ידיות חדשות על הקנבס — `fx-tip` (לא `sz-tip`); ידית של קבוצה — רק כשהקבוצה פתוחה. הרחבת ידית של V6 — מאזין capture על השכבה, בלי לגעת ב-layout.js.

## מ-V11 (2026-11-12, נרשם בידי בקרה (7))
- הכרטיסים בקנבס: `listview.js` (`KolList.ListView`) מצייר מ-`<template>` + קובץ הנתונים (`pf_read`), מסומנים `data-kol-canvas="card"` — **לא במודל, לא בקובץ**. כל קוד חדש שעובר על הקנבס — לסנן `:not([data-kol-canvas])` (ראו `REAL` / `kidsOf` ב-canvas.js).
- `bind.js` = המימוש של kol-data.js בצד העורך; **שינוי בקשירה — בשניהם**, ו-`tests/editor/bind-parity.mjs` משווה (30 תבניות). kol-data.js עכשיו **1.4.0** (`data-kol-class="NAME: תנאי; …"`).
- "המילה הזו" נשמרת דרך `pf_write` (plan → apply עם token) — אותו מנוע של N1; אין API חדש. "התבנית" — דרך המודל ו-Ctrl+Z כרגיל.
- פתוח: `sanitize_rich` מסיר `lang=` (לאפיון); "החלף הקלטה" ו"נשלח/שגיאה" לא נבדקו בדפדפן; רשימה מקוננת בתבנית — לא מצוירת; קטע N3 — העתק בצד הלקוח של `list_snippet` (שינוי באחד — גם בשני).
- v1a/v1b (390 — כפתורים ≥34px; תפריט הקשר של עורך הקוד) נכשלים **גם ב-main** — לא לייחס לפרוסה.

## מ-V12 (2026-11-13, נרשם בידי בקרה (7))
- רכיב = `components/<שם>/{template.html, component.json, style.css, script.js}`; מופע = ה-HTML המלא בעמוד עם `data-kol-component`. הלוגיקה הטהורה — `component-state.js` (נבדקת ב-`tests/editor/component-state.mjs`); המסך — `components.js`.
- כל הגדרה של מופע עוברת `checkField` / `checkAttrName` / `checkUrl` / `checkVarValue` — **יעד חדש = בדיקה חדשה שם**.
- יצירת קבצים בשרת = `pf_create` + `attachFx` לביטול (כמו V9). `build_is_config` מוציא `template.html` של רכיב מהחבילה.
- פתוח: עריכת תבנית בלי CSS העמוד (נראה שבור — אייקון על placeholder); רכיב מקונן — רמה אחת; repeater — מבנה פריט; שינוי שם רכיב — אין פעולה.

## מ-V13 (2026-11-13, נרשם בידי בקרה (7))
- תבניות = `templates/<שם>/{template.html, style.css, template.json, thumb.png}`; `window.KolTemplates` / `Editor._state.tpl` (load, read, insert, save, exportZip, importZip; טהורים: plan, renameHtml, renameCss, dropSame, zipBuild, zipList, checkZip). `build_is_config` מוציא `templates/` מהחבילה.
- תפריט ההקשר צף: `.ed-ctx` (לא `#sheet .ed-menu`). היסטוריה — 500 צעדים, עם שמות.
- פתוח (ב-T2): scope של ייבוא ZIP נאכף רק בלקוח; `build.clean` לא בודק `<animate>`/`<set>` על href.

## מ-V14 (2026-11-13, נרשם בידי בקרה (7))
- `touch.js` (`window.KolTouch.Touch`, `S.touch`) — עוטף setViewport / drawTools / onSelect / onDraw / onChange / onListen; מותקן אחרי `c.onContext` (הצורה של V13, 3 ארגומנטים). מודול חדש שעוטף אותם — לשים לב לסדר ההרכבה.
- במגע: גיליונות בחצי גובה, לחיצה ארוכה = תפריט ההקשר, 🔎 בסרגל = Ctrl+E, צביטה = הגדלה. בלוק CSS `/* V14 */` בסוף editor.css (`@media (max-width: 999px)`).
- פתוח (V14b): אזורי מגע 34–40px, מעט קנבס ב-768 ובמצב רוחב, גלילה בקצוות, safe-area.

## מ-V15 (2026-11-13, נרשם בידי בקרה (7))
- עמוד = שורה ב-pf_files (קובץ html), לא תיקייה חדשה. פעולות `page_*` (publisher) + `pages_list` (content_editor); `page_slug` הוא השער לכתובות. head.js כותב ל-<head> רק דרך setText/setAttr (מקודד).
- "קבע כדף 404" ממיר הפניות יחסיות ל-/lexicon/ (pf_exists_in + pf_ref_enc). פתוח (V15b): ?query/#frag, תבניות ברשימה, og:image מוחלט.

## מ-V16a (2026-11-13, נרשם בידי בקרה (7))
- רכיבים מוכנים = `assets/kit/components/<שם>/` (בכלי) → מועתקים ל-`components/<תיקייה>/` בפרויקט. פעולות `kit_*` (editor; list/get — content_editor). undo/revert בודקים rev מול `after` (409 אם השתנה); ב-kol.json — רק המפתח של הרכיב.
- רכיב שטוען תוכן חיצוני — `external` ב-kit.json, נטען רק בלחיצה (video-embed).
- editor.css: בלוק V16 **לפני** סמן V14 (v14 בודקת שכל מה שאחריו — לטלפון). שער איזון CSS — ב-T2.

## מ-T2 (2026-11-13, נרשם בידי בקרה (8))
- **כפתור שמוסתר בטלפון — לא `display:none` בתוך `.ed-bar`.** כפתור 0×0 מפיל את v1a/v1b ("כפתורי הסרגל ≥34px"). מוציאים מה-DOM ב-matchMedia ומחזירים למקום (סמן הערה) — הבלוק `/* T2 */` ב-screen.js (▶, ⚙️, ▶️). כפתור חדש שלא צריך בטלפון — להוסיף ל-`items`.
- **בסרגל הטלפון (שורה אחת שגוללת, V14) — שום `position:absolute`.** v1b בודקת בחמישה מצבי גלילה ש-elementFromPoint במרכז כל כפתור מחזיר אותו.
- **בלוק בתוך שורה:** `KolStruct.blockyIn(par, tags, moving)` — בלוק מבחוץ ל-`span/b/i/…/label/button` בלי בלוקים — נדחה (`code: 'inline'`), הגרירה יוצאת לצד השורה (`withValid`). סידור מחדש באותו הורה, או יעד שכבר מכיל בלוקים — מותר. `<a>` — לא נחסם.
- **`replaceChildren(...)` — בלי ארגומנט null/false**; עוטפים ב-`filter`. נאכף ב-`tests/editor/replace-children.mjs`.
- **שער CSS:** `tests/cases/99-css-balance.php` — @media פתוח עוצר את הפריסה. לפני מחיקת בלוק פתוח — לבדוק מה היה בתוכו (V15b מחקה את 📄 44px).
- **ייבוא ZIP חלקי:** `pf_zip_import` מקבל `scope` (קידומות, בפסיק); השרת דוחה כל ZIP עם משהו מחוצה לו.
- **`note(text, err, action, soft)`** — `soft`: רמז שנסגר בלחיצה הבאה או אחרי 6ש'.
- `sanitize_rich` שומר `lang=` (BCP47 קצר). `build.clean` מסירה `<animate>/<set>` שמכוונים href/src ל-javascript:.
- **פתוח:** בעריכת תבנית — `<style>` שבעמוד ו-`lang` לא נטענים; Safari / Firefox / iOS — לא נבדקו.

## מ-V16 (2026-11-13, נרשם בידי בקרה (8))
- **מפה:** רכיבים — `assets/kit/components/<שם>/` (kit.json גרסה; שינוי ברכיב שכבר ב-main ← להעלות גרסה, אחרת אין "⬆ יש גרסה חדשה"). עורך: `assets/js/editor/kit.js` (ספרייה, מצבים, actions, drag, תבניות), `kit-form.js` (בונה הטופס, כפתור "שמור" של ה-webhook). שרת: `lib/project_ops.php` kit_*, `lib/forms.php` forms_hook_*, **`lib/net.php` — כל בקשה החוצה חדשה עוברת דרכו** (net_host / net_ip_public).
- **מגע 44:** בלוק `@media (pointer: coarse)` בסוף style.css של הרכיב; אזור קטן בעין — `::after` עם inset שלילי (נקודות המצגת). בדיקה: v16 קטע 10.
- **בדיקות:** `tests/browser/v16.cjs`, `tests/cases/99-kit.php`, `99-kit-forms.php`, `tests/editor/kit-rules.mjs` (רץ על כל רכיב חדש). ב-99-kit — לנקות רכיב כבד בסוף (kt_reset), אחרת M7 "קצה לקצה" נשבר.
- **מלכודות:** בתצוגה החיה `location.origin === "null"` — להשוות למקור הסקריפט; בדיקה שתלויה בטיימר — לדגום, לא רגע אחד; דף שנסגר באותו context משנה `document.hidden`; v9 פונה ל-Google Fonts ברשת.
- **פתוח (⚪ מבקרה 8):** מגע 40–42 ב-social-icons/style.css:2, form/style.css:22 (כפתורים), map/style.css:11 (קישור), נקודות מצגת 12×12 ואקורדיון 42; תוויות חופפות בעורך (social/share/slides צמודים); כפתור "שמור" של ה-webhook (kit-form.js:190) נראה כטקסט.
- יומן מלא: `team/log/code-V16.md`. מיגרציה 26 — לא נוצלה.

## מ-V14 (2026-11-13, נרשם בידי בקרה (8))
1. **touch.js (KolTouch):** גיליון 4 גבהים (row/third/half/full, `kol-editor-sheet`); ⛶ מסך מלא (<1000px, `kol-editor-fs`; ברוחב תמיד); הקנבס מתקצר עד ראש הגיליון (`--ts-cover`), הנבחר נגלל מעליו 420ms אחרי הבחירה; visualViewport (`--ts-kb`; בבדיקות `fakeKeyboard(px)`); ⬆⬇ בסרגל הנבחר; ↕ ב-Navigator; −/+ חוזר בלחיצה ארוכה; צביטה עד 300% (`setZoom`); 🔎 חיפוש פעולה; `c.realW`. touchmove לא-passive — רק מ-drag.start עד drag.end.
2. **מגע בקנבס (canvas.js):** הקשה = שחרור בלי תזוזה >8px; תזוזה לפני לחיצה ארוכה = גלילה (`p.scrolled`); לחיצה ארוכה (400ms) מחמשת גרירה ובוחרת; contextmenu של הדפדפן אחרי מגע — מתעלם (`lastPT`); הקשה על אותו אלמנט אחרי לחיצה ארוכה — נשארת (`held`); ה-click של השחרור נבלע (`swallowClick`, 350ms). **החלקה = תמיד גלילה** (הבעלים) — משנה את spec §2.2.
3. **Navigator:** ROW חי (`rowH()`, matchMedia), 44 במגע; התפריט בשחרור. בבדיקות — `window.KolNavigator.ROW`, לא 30.
4. **CSS:** בלוק V14 בסוף editor.css, כל כלל תחת max-width:999px או pointer:coarse (בדיקה סטטית ב-v14). `#sheet` — רק תחת `body:has(.ed)`.
5. **loadMonaco:** `data-monaco-loader`; התג מוסר רק ב-error (`data-failed`), לא ב-timeout.
6. **בדיקות:** v14.cjs. **כל שינוי מגע — ב-CDP `Input.dispatchTouchEvent`** (cdpOf, realDrag, swipe), לא PointerEvent סינתטי: touch-action, click אחרי touchend, pointercancel וגלילה ב-iframe מופיעים רק כך (שתי רגרסיות 🔴 נתפסו רק בבדיקה כזו).
7. **לא נעשה:** שורת מקשים לקוד + חלופה כש-Monaco לא נטען; ✕ לביטול גרירה; "100%" לטאבלט/מחשב בטלפון; תרחיש 7.3 ב-390; מכשירים אמיתיים (iOS Safari, Chrome אנדרואיד) — מטלה לבעלים.
