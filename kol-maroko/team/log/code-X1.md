# יומן צ'אט הקוד — פרוסה X1 (עוזר הבינה, עבודה מצ'אט וטקסט ארוך — על פרויקט הקבצים)

- 2026-11-12 · יחידה 1 (שרת ✨): `lib/ai.php` — ההקשר מקבצים (`paths` / `range` / `element`, תקרה `ai_context_max` = 200KB), הסכימה החדשה
  `{path, old, new}` / `{path, create}`, `ai_plan` (old בדיוק פעם אחת, אחרת "לא הוחלה" עם סיבה), `ai_suggest_files` / `ai_apply_files`
  (ההצעה נשמרת בשרת `ai_prop_<rid>`, "החל" מחיל אותה; rev ≠ ← 409 ושום דבר לא נכתב; גרסה אחת לקובץ "✨ <הבקשה>"; המילים דרך
  `pf_words_write` ← `words_file_save` של N1). המודל הישן נמחק; `ai_suggest` ← 410 "העוזר עבר ל-📁 פרויקט". המודל: `ai_model()` (הגדרה
  `ai_model`, ברירת מחדל AI_MODEL). בדיקות: `tests/cases/96-ai-files.php` (13). שורה ב-`20-api.php` לשתי הפעולות.
  - הכרעה טכנית: בלי מיגרציה — ההצעה הממתינה ב-settings (נמחקת אחרי יום).
- 2026-11-12 · יחידה 2 (🤖): `agent.php` — help · tree · read · write · edit · create · search · versions · preview_url · words · words_save
  (דרך `pf_words_write` של N1: תצוגה כטקסט + token, ואז apply) · word_save / word_delete / topic_op. נמחקו: draft, draft_save, render, page,
  code*, publish_preview (`AGENT_GONE` → 404). כל כתיבה — גרסה "🤖 <שם הכניסה>"; audit `agent_*` עם `source: agent (<id>)` בפירוט (אין
  עמודת source בטבלה — P1 לא מוזגה). מגבלת קצב 120/דקה (`agent_rate`, ב-settings). הסקיל נבנה מ-kol.json, מהעץ ומהכותרת של
  `js/kol-data.js`. בדיקות: `tests/cases/97-agent-files.php` (9).
- 2026-11-12 · יחידה 3 (📝): `lib/longtext.php` — `sanitize_doc` עבר לרשימה מותרת (תגיות + תכונות; מסוכנות נמחקות עם התוכן, לא מוכרות נפרקות;
  בלי data-* / id / הערות; href https|mailto|tel|#, src https; מנתחים ב-<body> ולא בתוך מכל — `</div>` עודף לא בורח ממנו). `sanitize_css` —
  תוקן חור: `@import` ברמה העליונה עבר. אזור `data-kol-longtext`: `longtext_open` (חדש בסוף <main> / קיים), `longtext_sync` עם path
  (CSS ל-`css/longtext/<doc>.css` + `<link>` ב-head פעם אחת, גרסה "📝 <שם>"; hash ≠ ← 409 עם שני הצדדים, `mode: overwrite | import`),
  `longtexts` + `longtext_link` ("🔗 חבר למסמך קיים"). בלי מיגרציה: path + hash ב-settings (`lt_link_<doc>`), לא עמודות ב-longtexts.
  `longtext_sync` בלי path — הטיוטה הישנה, כמו היום (עד M6). בדיקות: `tests/cases/98-longtext-files.php` (5, כולל payload עוין).
- 2026-11-12 · יחידה 4 (ממשק): `assets/js/ai-sheet.js` (חדש) — בקשה, הקשר, diff לכל קובץ (`Project.diffView`), סימון לכל עריכה, "לא הוחלו" עם
  סיבה, במילים — התצוגה של N1; "החל" / "בטל". `project.js` — ✨ בסרגל (קובץ פתוח / שורות שסומנו), וב-⋯: 📝 ערוך בעורך התמלילים (אזור
  שבו הסמן, או חדש), 🔗 חבר למסמך קיים (על הבחירה), חלון התנגשות עם שלוש האפשרויות; `Project.ai()` טוען את ai-sheet.js רק כשצריך
  (בלי לגעת ב-studio.html). `words.js` — שורה אחת: ✨ עוזר ב-📚. CSS ב-`project.css`. `tests/browser/x1.cjs` (26) + `x1-router.php`
  (שירות בינה מדומה, בלי לגעת ב-router.php). נמצא ותוקן: `"$from–$to"` ב-PHP — ה-– נבלע בשם המשתנה (טווח השורות נשלח חסר).
  - ❗ פתוח: `frame-src` ב-editor.php (lib/csp.php, C1) לא מתיר את עורך התמלילים — 📝 ב-📁 ייחסם עד שתתווסף KOL_WP_URL. נשאל בבקרה.
