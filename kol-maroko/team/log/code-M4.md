# יומן — צ'אט הקוד M4-BC

- 2026-11-12 — התחלה. ענף `claude/kol-maroko-m4` מ-main. הבעלים: ש-1 = ג (העמוד כחלון בתוך האתר — framed ברירת המחדל לבחירה), ש-2 = א (M4-D **לא** נבנית).
  ההוראה אמרה לדווח לבקרה (4); `team/sessions.json` ב-main כבר מצביע על בקרה (5) `session_018g4NYCik48sdEyBfXuc6it` — מדווח לשם.
- 2026-11-12 — **M4-BC נבנתה.** מה:
  - **התוסף 2.1.0** — `v2-mount.php` (חדש): הפעולה החתומה `mount` {full · framed · bar} ← `kol_maroko_v2_mount` (יומן התוסף, ניקוי מטמון);
    מסמך המעטפת (`template_redirect` עדיפות 0 + `pre_handle_404`; ערכה קלאסית ובלוקים); ה-iframe עם sandbox בלי allow-same-origin;
    `<head>` — title/description/og:*/twitter:*/article:* כטקסט (esc_html/esc_attr), http-equiv לא; הגשר — 3 הודעות, source+origin "null"+
    בדיוק 2 מפתחות+סוג+גודל; path רק לדף HTML שבמניפסט. `v2-serve.php` מחליט על מעטפת לפני ניקוי הפלט/העוגיות: כתובת "יפה" של דף HTML,
    אופן ≠ full, ו-`Sec-Fetch-Dest` ≠ iframe. fallback לדפדפן בלי Sec-Fetch-Dest: המעטפת בתוך מסגרת ← `location.replace` לקובץ עצמו.
    ping: `v2_mount` (שדה חדש; שמות קיימים לא שונו — M3). מסך התוסף: שורת "אופן ההצבה".
  - **kol-data.js 1.3.0** (הזרע): height (ResizeObserver + rAF, יורד גם למטה; תקרה 50,000), title (MutationObserver), path (יחסי לשורש
    החבילה לפי ה-src של הסקריפט; בטעינה וב-popstate) — רק כש-`window.parent !== window`.
  - **הכלי:** `mount` / `mount_set` (בעלים, CSRF) ב-`lib/build.php` + `api.php`; דורש ping עם `v2_mount` (אחרת 409 plugin_old);
    ממשק — "🪟 איך העמוד מוצג באתר" במסך "🔗 חיבור לאתר" (`assets/js/publish.js`), עם "מה העמוד מפסיד" לכל אופן ואישור לפני שינוי.
    **חריגה מטבלת §2.2:** מסך "⚙️ בעל האתר" (P1) עוד לא קיים — השורה נכנסה למסך ההגדרות הקיים. כשיהיה P1 — להעביר.
  - `KOL_PLUGIN_EXPECTED` = 2.1.0.
  - **ערכת ההתקפה:** H16 (16 הודעות מזויפות + 3 מ-iframe פנימי) ו-H17 (top.location.href, parent.document.cookie, parent.history,
    parent.localStorage, frameElement, top.location.replace) ב-`tests/attack-kit/kit.js`. `assets/js/attack-kit.js` (F4) לא נגעתי — אין בו מעטפת.
  - **בדיקות:** `tests/plugin/60-mount_test.php` (6), `tests/cases/99-mount.php` (3), `tests/browser/m4bc.cjs` (43), m2.cjs + סעיף ה (framed/bar,
    H1–H17 בתוך החלון). `tests/plugin/wp.php` — template_redirect וערכת נושא מדומה. `tests/fake-wp.php` — `mount` בחוזה (mock + real).
  - בדיקת מוטציה: הסרת בדיקת ה-source/origin והתקרה בגשר ← m4bc ו-m2 נכשלים (7 ו-5 בדיקות). הוחזר.
  - תיקון בדיקה: m2.cjs `setOpt` — מרוץ ידוע (השרת כותב לאותו קובץ במקביל, "כתיבה אבודה") הפיל פעם אחת את סעיף ד (M2b). עכשיו כותב ובודק.
- **מה לא נבדק:** Firefox / Safari; ערכת נושא אמיתית של maghreb (מה בדיוק `get_header()` מדפיס, [ש-24] — כרגע כל הכותרת ב-bar);
  LiteSpeed/Cloudflare מול המעטפת; תוסף SEO באתר (Yoast וכו') שמוסיף og:/title משלו — כפילויות אפשריות. כל אלה — באתר (מטלה).
