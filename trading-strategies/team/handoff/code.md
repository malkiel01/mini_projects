# מה צ'אט הקוד הבא צריך לדעת

> נכתב ב-2026-11-15 בסוף הצ'אט שבנה את 4א/5ב, S2, תיקון forecast-ui, 4א/5ג ו-S1 (#1585, #1586, #1591, #1592, #1595).
> **עדכון 2026-11-15 (צ'אט S3):** S3 (#1598) ו-S1.1 בנויים — הסעיפים למטה. הבא בתור: **F1** (18.1.2, גרסה 2.16) — רק אחרי ש-S1.1 ממוזג.
> **עדכון 2026-11-15 (צ'אט F1):** F1 בנוי וממוזג (#1601) — הסעיף "F1 — מה נבנה" למטה.
> **עדכון 2026-11-15 (צ'אט S1.2):** F1 (#1601) ו-S1.2 (#1603) ממוזגים — הסעיף בסוף. הבא בתור — לפי `team/board.md` (18.8: S4).
> **עדכון 2026-11-16 (צ'אט S4):** S4 בנוי וממוזג (#1605) — הסעיף "S4 — מה נבנה" בסוף. הבא בתור — לפי `team/board.md` (18.8: S5).
> **עדכון 2026-11-16 (צ'אט S5):** S5 בנוי וממוזג (#1608) — הסעיף "S5 — מה נבנה" בסוף. הבא בתור — לפי `team/board.md` (18.8).
> **עדכון 2026-11-16 (צ'אט S6):** S6 בנוי וממוזג (#1611) — הסעיף "S6 — מה נבנה" בסוף. הבא בתור — לפי `team/board.md` (18.8: S7, שנשען על היומן).
> **עדכון 2026-11-16 (צ'אט S7):** S7 ו-method_colors (2.20) בנויים וממוזגים (#1615) — הסעיף "S7 — מה נבנה" בסוף. הבא בתור — לפי `team/board.md`.
> **עדכון 2026-11-16 (צ'אט A1):** DevLoop מחובר וממוזג (#1618) — הסעיף "A1 — DevLoop" בסוף. הבא בתור — לפי `team/board.md`.
> **עדכון 2026-11-16 (צ'אט A2):** A2 — התחברות עם אפשרות ניתוק — בנוי וממוזג (#1623). הסעיף "A2 — התחברות" בסוף. ~~מיגרציה הבאה — 15~~ (נלקחה ב-21.2). הבא בתור — לפי `team/board.md`.
> **עדכון 2026-11-16 (צ'אט 21):** 21.1 (#1627, תיקון הבדיקה #1629) ו-21.2 (#1630) בנויים וממוזגים — הסעיפים "21.1" ו-"21.2" בסוף. **מיגרציה הבאה — 16.** הבא בתור — לפי `team/board.md`.
> **עדכון 2026-11-16 (צ'אט 21.3):** 21.3 (#1642) בנוי וממוזג — הסעיף "21.3" בסוף. **מיגרציה הבאה — 17 (`app.sqlite`); `cache.sqlite` — 5.** הבא בתור — לפי `team/board.md`.
> **עדכון 2026-11-16 (צ'אט הבדיקה הרועדת):** מפתחות בדיקה שמחפשים את "דליפתם" בתשובות (חלון 5 תווים, `tests/market-api.php`) — בכל חלון של 5 תווים חייב להיות תו שאינו hex, אחרת מזהה אקראי בתשובה תואם במקרה (ריצה 1711). assert בתחילת הבדיקה אוכף את זה.
> **עדכון 2026-11-16 (צ'אט החובות):** ארבעת החובות מ-STATE 8 — הסעיף "חובות — מה תוקן" בסוף.

> **עדכון 2026-11-16 (צ'אט err500):** 500 בהפעלת שיטה — נעילת SQLite בבקשות מקבילות; `TsStmt` סוגר סמנים, וההודעה לפי GET/POST. הסעיף "תקלת 500 בהפעלת שיטה" בסוף. **אין יותר לולאת `while` על `fetch()`.**
> **עדכון 2026-11-16 (צ'אט F-1):** פיבונאצ'י F-1 (פרק 22) בנוי — הסעיף "F-1 — פיבונאצ'י" בסוף. PR טיוטה, ממתין לביקורת. **מיגרציה הבאה — עדיין 17; `cache.sqlite` — 5.**
> **עדכון 2026-11-16 (צ'אט bt-incomplete):** בדיקה לאחור חלקית נשמרת (`bt_partial`) וממשיכה. **`cache.sqlite` — המיגרציה הבאה 6.** הסעיף "בדיקה לאחור שלא הספיקה" בסוף.
> **עדכון 2026-11-16 (צ'אט T1):** חלונית ההסבר על הגרף (18.1.6) — הסעיף "T1 — חלונית הסבר" בסוף.
> **עדכון 2026-11-16 (צ'אט L1):** למידה עצמית — `method_outcomes` ו-backtest. **`app.sqlite` — המיגרציה הבאה 18; `cache.sqlite` — 6.** הסעיף "L1 — למידה עצמית" בסוף.
> **עדכון 2026-11-16 (צ'אט L6):** L6 בנוי — הסעיף "L6 — בנוי" בתוך "התחל כאן — למידה". **בלי מיגרציה — `app.sqlite` הבאה עדיין 23.** פרק 23 (L1–L6) גמור בקוד.
> **עדכון 2026-11-16 (צ'אט R1):** עורך ראשי / עורך משני / משתמש (פרק 24) — הסעיף "R1 — תפקידים" בסוף. **מיגרציה 18 = `app_roles` (R1, אחרי 17 של L1). הבאה — 19 (`app.sqlite`); `cache.sqlite` — 6.**
> ~~הבא בתור: S3~~ (בנוי). קריאת חובה: `docs/spec-stage-a.md` 18.3, 3.5 ו-✅23 ב-`docs/owner-brief.md` 2ה.


> **עדכון 2026-11-16 (צ'אט הלמידה — err500, bt-incomplete, L1, L3, L4, L5, L5b; נסגר ב-~780K):** הסעיף הבא, "התחל כאן — למידה", הוא
> ההעברה המלאה. ~~המיגרציה הבאה 22~~ — נלקחה ב-L2 (`learn_live`). **`app.sqlite` — הבאה 23; `cache.sqlite` — 6.** ~~הבא בתור: L6~~ — בנוי (צ'אט L6).

## התחל כאן — למידה (פרק 23): מה בנוי, מה הבא, מה חייבים

### מה בנוי וממוזג (או ב-PR האחרון)

| פרוסה | PR | הקבצים | הלב |
| --- | --- | --- | --- |
| err500 | #1686 | `lib/pdo.php`, `api.php` | `TsStmt` סוגר סמן אחרי `fetch` / `fetchColumn` — **אין `while ($r = $st->fetch())`**, רק `fetchAll` + `foreach`. `ts_server_error` — 500 עם פעולה ושיטה ביומן |
| bt-incomplete | #1692 | `lib/analysis.php`, `lib/cache.php` (מיג' 5) | בדיקה לאחור שלא הספיקה נשמרת ב-`bt_partial` וממשיכה מאותה נקודה; לריצה יש חלון זמן משלה (`$runUntil`) |
| L1 | #1699 | `lib/learn.php`, `lib/learn/holidays.json` (+ `holidays-build.php`), מיג' 17 | `method_outcomes` — שורה לכל עסקה שהסתיימה (`INSERT OR IGNORE`); `ts_bt_run(..., $onTrade)` נותן גם `mae_r`, `mfe_r`, `atr`, `features`; התגיות (23.5). `users.learn_off` כבר קיים — **ל-L2** |
| L3 | #1703 | `lib/learn.php`, `api.php` (`admin_learn`, `admin_learn_csv`), `assets/js/analysis.js` (`learnPanel`) | הלשונית "📚 למידה": N לפי גרסה ומקור, טבלת פלחים עם N ≥ 30, CSV (גרש לפני `= + - @`; `user_id` רק לראשי) |
| L4 | #1706 | `lib/learn.php`, מיג' 19 (`learning_findings`) | שינוי דפוס: פלח מול השאר ו-30 האחרונות; שני-פרופורציות + Welch, p < 0.01; ממצא חריג → DevLoop בלי כפילות |
| L5 | #1707 | `lib/tune.php`, `lib/analysis.php`, מיג' 20 | התשתית: `methods.seg_params` + `ts_params_for_segment`, הצעה לפלח (`method_proposals.segment/origin/stats`), `ts_seg_param_clear` (↺ בטל) |
| L5b | #1709 | `lib/learn.php`, `lib/alerts.php`, מיג' 21 | השוואת שכנים (2.37) — ב-cron בלבד. פירוט למטה ("L5 / L5b") |

### איך הלמידה רצה היום

- **כתיבה:** רק `backtest`, מתוך `ts_backtest` (בקשת `analysis` של המשתמש) — השורות, ואחר כך `ts_learn_findings_sync` (L4). שום דבר
  יקר לא רץ שם.
- **cron** (`bin/alerts-cron.php` → `ts_alerts_run`, כל 15 דקות, 240 שניות): התראות → יומן (S6) → מילוי (21.2) → **`ts_learn_cron`**
  (עד 90 שניות, אותו `$used` / `$budget` לדקה) → ניקוי. הסיכום ב-`settings.alerts_cron` ובלוג `data/alerts-cron.log` (`learn: {evaluated,
  proposals, edges, runs_in, runs_out, deferred, too_big, errors, log[]}`).
- **סדר הוגן** בלמידה: תור אחד מכל השיטות, `learning_values.at` הישן קודם; דחייה בגלל זמן → לסוף התור ו-`deferred + 1`; שלוש ברצף →
  `too_big` + פריט DevLoop אחד, וחוזר ל-due רק אחרי 30 שורות חדשות. דחייה בגלל תקציב — לא נספרת.
- **מי מצהיר מה:** `sample-ma-cross` (stop/target, צעד 0.5), `elliott-w3` (`w3_mult` — ערכי הספר). **פיבונאצ'י — עוד לא** (ההצהרה אצל
  קוד פיבונאצ'י; השורה המדויקת בסעיף L5/L5b למטה).

### L2 — בנוי (`live` ו-`trade`, מיגרציה 22)

- **live:** `ts_learn_live_cron` — ב-cron אחרי המילוי ולפני `ts_learn_cron`. המצב ב-`learn_live` (מפתח כמו `backtests`); `base_t` — עסקה
  שהאות שלה עד שם שייכת לבדיקה לאחור. גרסה / hash אחרים → מדלג (הכרטיס הבא יחשב בדיקה, ואז מתחיל מחדש). רק `cred_scope = ''`.
  בסיכום ה-cron: `learn_live: {checked, advanced, written, skipped, private, deferred, errors}`.
- **trade:** `ts_learn_trade($uid, $tradeId)` מ-`ts_trade_save`. `params_hash = ''`; `t_signal` = `as_of` של התמונה; בלי תמונה — אין שורה.
- **המתג:** `learn_off_set`, `me.user.learn_off`, `learnBlock()` בפרופיל (`analysis.js`).
- **ל-L6:** שורות `live` לשיטה וגרסה, ב-`market × tf` — `SELECT … FROM method_outcomes WHERE source = 'live' AND method_id = ? AND method_version = ? AND market = ? AND tf = ?`.
  L4 / L5b עדיין רצים רק על `backtest`.

### היה: L2 — `live` ו-`trade` (23.4, קריטריונים L2 1–4)

- **`live`:** ב-cron — אות של השיטה **אחרי** הבדיקה לאחור, על נרות סגורים, שהגיע ליציאה. אותו מנוע (`ts_bt_run` עם `$onTrade`), אותו R.
  - המקור הטבעי: מצב המנוע השמור (`backtests.state`, מצטבר) — להמשיך ממנו על הנרות החדשים, ולכתוב רק עסקאות עם `t_signal` אחרי
    `to_t` של הבדיקה. `ts_outcomes_write` עם `source = 'live'` (המפתח הייחודי מונע כפילות).
  - לרוץ **באותו תקציב** (`$used`, `$budget`) — כמו `ts_journal_resolve`. לשים לפני `ts_learn_cron` (הלמידה אחרונה).
  - קריטריון 1: אותו R כמו המנוע — בדיקה שמשווה לריצה מלאה על אותם נרות.
- **`trade`:** בסגירת עסקה ביומן העסקאות עם `method_id` ו-`planned_stop` → שורה `trade` (R לפי `planned_stop`). בלי `planned_stop` — אין.
  - `users.learn_off = 1` → אין שורה (ל3 / ✅36). צריך מתג בהגדרות המשתמש (אין עדיין UI).
  - `user_id` — רק בשורת `trade`, **לא מוצג לאיש מלבד העורך הראשי** (`ts_learn_csv(..., $withUser)` כבר מכבד את זה; כל תשובה חדשה —
    לבדוק).
- **הלשונית כבר מציגה כל מקור** (`select[name="learn-source"]`), ו-L4 / L5b רצים היום רק על `backtest`.

### היה: L6 — ההסתברות בכרטיס (23.7, קריטריונים L6 1–3) — **בנוי, הסעיף "L6 — בנוי" למטה** — **צ'אט קוד חדש** (צ'אט R1+L2 נסגר ב-~520K)

**על מה זה נשען (L2, #1711, ממוזג):**
- **שורות `live`** — `method_outcomes` עם `source = 'live'`, `params_hash` של הבדיקה לאחור, `cred_scope = ''`, ו-`market`/`tf` של הנכס.
  נכתבות ב-cron (`ts_learn_live_cron`) — רק אותות אחרי `base_t` (ה-to_t של הבדיקה כשההמשך התחיל). לא נוצרות בבדיקות ממשק — בבדיקה
  של L6 לכתוב אותן ישר (`ts_outcomes_write([... 'source' => 'live' ...])`), או להריץ את ה-cron עם `$load` (ראו `tests/learn-live.php`).
- **⚪ מהבקרה — חפיפה backtest / live:** כשהבדיקה לאחור מחושבת מחדש (to_t מתקדם), עסקה שכבר נכתבה כ-`live` נכתבת **גם** כ-`backtest`
  (מקור אחר — מפתח אחר). לכן: "ראשונית" — רק `backtest`; "בפועל מאז" — רק `live`; **אף פעם לא לחבר את שני המקורות** (23.7 "לא
  מערבבים"). מי שרוצה "בפועל מאז הבדיקה הנוכחית" — לסנן `live` לפי `t_signal > backtests.to_t`, ולרשום את ההחלטה.
- **השהיה ב-`learn_live`:** `state = ''` = ה-hash לא תאם (פרמטרים / עמלות שהשתנו) — ממתין לבדיקה לאחור חדשה. אין שורות live חדשות
  לבדיקה כזו עד אז.
- **שורות `trade`** (`params_hash = ''`) — לא נכנסות ל-L6 (23.7 מדבר על `live`).

**מה לבנות (23.7):**
- רמת הפלח — `market × tf` של הגרף; N < 30 שם → השיטה וגרסתה בכל השווקים, עם "כללי — לא לשוק הזה" (ל2).
- הנוסחה — Wilson כמו היום (`ts_certainty`, N ≥ 30). 29 → אין שורה שנייה.
- "החלף לבפועל" — הצעה לעורך ראשי בלבד (`ts_require_chief`), כמו הצעות S7 (`method_proposals`, `origin`), כשרווחי הסמך לא חופפים.
  שורה ב-`$ROWS` של `tests/roles-api.php` (24.2).

- רק `backtest` → "ראשונית — מבדיקה לאחור (N = …)". 30 שורות `live` → שורה שנייה "בפועל מאז: X% (N = 30)"; 29 → אין.

- רק `backtest` → "ראשונית — מבדיקה לאחור (N = …)". 30 שורות `live` → שורה שנייה "בפועל מאז: X% (N = 30)"; 29 → אין.
- המספר הראשי לא מתחלף לבד. רווחי סמך שלא חופפים → הצעה "החלף לבפועל" אצל העורך הראשי (בדומה להצעות S7).
- הכרטיס: `ts_analysis_cards` (`lib/analysis.php`) ו-`chart_refresh` (`lib/refresh.php`); הוודאות — `ts_certainty` (Wilson).

### חובות ודברים פתוחים

- **פיבונאצ'י לא מצהיר `learn_params`** — עד שקוד פיבונאצ'י יוסיף, ההשוואה לא רצה עליו (הצהרה ישנה עם `stat` — מדולגת בשקט).
- **ההשוואה מתחילה מאפס בכל ריצה** (נרות + הרצות). פלח כללי גדול → `too_big`. אם זה יקרה בשרת: לשמור התקדמות בין ריצות (מועמד
  שהסתיים → `detail`), או נרות בזיכרון מטמון. היום — רק דיווח (DevLoop).
- **זוג ממפתח אישי לא רץ אף פעם** בלמידה (`private_pairs` ב-`detail`). פלח שכולו כזה → `no_pairs`. אם יהיו משתמשים כאלה רבים — שאלה
  לאפיון.
- **`learning_findings.kind = 'value'`** משמש גם לקצה הטווח (`…|edge|…`) וגם ל-`too_big` (`…|too_big|…`) — המפתח מבחין ביניהם.
- **בדיקות דפדפן במקביל:** `chart-f1-ui` ו-`method-colors-ui` נכשלות לפעמים כשרצות 4 בבת אחת (עומס), ועוברות לבד. לא של הלמידה.
- **לא לגעת** (צ'אטים אחרים): `lib/fibonacci*`, `lib/methods/fibonacci.php`, `assets/js/forecast.js`; הרשאות (`app_roles`, מיג' 18).
- **`lib/devloop.php` הוא עותק** — עורכים רק ב-`devloop/src` + `php devloop/tools/sync.php` (שער בפריסה).

### L6 — בנוי (23.7, ענף `claude/trading-strategies-learning-l6`)

- **השורה השנייה:** `ts_learn_live_line($method, $version, $market, $tf)` (`lib/learn.php`) — רק `live`, `cred_scope = ''`, בלי `skipped_gap`.
  30 בשוק × מסגרת → `scope: segment`; פחות — השיטה וגרסתה **בכל השווקים ובכל המסגרות** (`scope: general`, `segment: ''`); פחות מ-30
  גם שם → `null`. **כל** שורות ה-live של הגרסה, בלי סינון לפי `backtests.to_t` — הן אחרי `base_t` של הבדיקה שלהן מעצם הכתיבה.
- **הכרטיס** (`ts_analysis_cards` → `analysis` ו-`chart_refresh`): `certainty_basis` (`backtest` | `live`) ו-`live` (`n`, `wins`, `win_pct`,
  `certainty`, `ci`, `scope`, `segment`, `switched`). `certainty` הוא המספר הראשי — מ-live **רק** כש-`switched`. `backtest.certainty`
  נשאר תמיד. בממשק — `liveLine` / `probability` ב-`assets/js/card.js` (`.basis-line`, `.live-line`).
- **"החלף לבפועל":** `ts_learn_switch_sync($now)` ב-`ts_alerts_run`, אחרי `learn_live` ולפני `learn` (שאילתות בלבד). לכל שיטה בגרסה
  הנוכחית — כל שוק × מסגרת עם 30 live, והרמה הכללית: Wilson [תחתון, עליון] של live מול backtest **של אותה רמה**, מאגר משותף, **בלי
  עסקאות שיש להן גם שורת live** (אותו מפתח, מקור אחר — `ts_learn_backtest_apart`). לא חופפים → הצעה ב-`method_proposals` עם
  `param = '@certainty'` (`TS_CERT_PARAM`), `origin = learning`, `results = {backtest, live}`; חופפים שוב → `stale`; כבר הוחלף → לא שוב.
- **ההחלה:** `ts_tune_apply` — ענף מיוחד ל-`@certainty`: `status = applied`, `new_version = NULL`, `admin_log` `method_cert_live`. **לא נוגע
  ב-`methods` ולא מעלה גרסה** (העלאה הייתה מאפסת את ה-live). "הוחלף" = הצעה `applied` לאותה שיטה, גרסה ורמה (`ts_learn_switched`) — לכן
  **אין מיגרציה**. גרסה חדשה → מתחילה שוב מ"ראשונית". `ts_tune_row`: `@certainty` פתוחה → stale כשהגרסה זזה או כשכבר הוחלף.
- **במסך השיטות:** `certCard` ב-`assets/js/analysis.js` (`.cert-prop`) — טבלה בדיקה לאחור מול בפועל (N, הצלחה, רווח סמך 95%), "החלף לבפועל".
- ~~חוב: שורת הסיכום ב-`forecast.js`~~ — תוקן (L6.1): ה-`brief` מציג `certainty` ומקורו, "ראשונית" / "בפועל מאז". `forecast.js` משותף, לא של פיבונאצ'י (בקרה 8).
- **אין דרך לבטל** "החלף לבפועל" באותה גרסה (רק גרסה חדשה). אם יידרש — שאלה לאפיון.

### איך בודקים

- `php trading-strategies/tests/<שם>.php` — כל אחד עצמאי (מסד זמני). הלמידה: `learn.php`, `learn-api.php`, `learn-stats-api.php`,
  `learn-findings.php`, `learn-values.php`, `learn-values-api.php`; בדפדפן `node trading-strategies/tests/learn-ui.mjs` (SHOTS=<תיקייה> — צילומים).
- שיטות בדיקה: `TS_TEST_METHODS=tests/fixtures/methods/learning.php` (`test-learn`, `test-neighbors`); הסטים הקבועים — `tests/fixtures/learn/sets.php`.
- `ts_learn_cron(..., $load, $seconds)` — `$load` מזריק נרות בבדיקה, בלי רשת.
- L6: `learn-card.php`, `learn-card-api.php`, `node trading-strategies/tests/learn-card-ui.mjs`.
- לפני push: כל `tests/*.php` + `php platform/check-apps.php`.

## על מה S3 נשען — כבר בנוי

- **המטמון פונה לספק רק על החסר** (S2, `lib/market.php` → `ts_market_candles`):
  - `coverage` — טווחים סגורים שכבר נמשכו.
  - `tails` — הנר הפתוח, עם תוקף לפי מסגרת (`ts_tail_ttl`: מינימום 60 שניות, מקסימום 5 דקות).
  - בתוך התוקף — 0 פניות לספק. **רענון אוטומטי כל 30–60 שניות לא יגרום לפנייה בכל פעם** — זה בדיוק מה ש-S3 צריך.
- **מדידה:** `fetch_log` ב-`data/cache.sqlite`, מוצג במנהל ← 🗄️ מאגר ← "יומן בקשות נרות". `provider_candles` חוזר בכל תשובה של `market_candles`. בעזרתו מודדים ש-S3 לא מעמיס על הספק.
- **תקרת 30 בקשות לדקה למשתמש** (`TS_DATA` ב-`api.php`): כל קריאה ל-`market_candles` או ל-`analysis` נספרת. רענון כל 30 שניות בגרף + ניתוח = 4 לדקה. להישאר מתחת לתקרה גם עם כמה לשוניות פתוחות.
- **כפתור "↻ רענן"** קיים ב-`viewChart` (`assets/js/market.js`, `load(true)`), ונשאר (19.2).

## מה נמחק — לא לחפש

- **העותק בענן MBE** — `lib/copy.php`, `analysis_copy*`, `cloud.js`, קריאת העותק ב-`load()` (#1590).
- **`tests/no-polling.mjs`** — נמחק, כי סתר את ✅23. **S3 צריך בדיקה חדשה** שמוכיחה את ההפך: יש רענון כשהניתוח פעיל, ואין כשהלשונית מוסתרת או הניתוח כבוי — לפי 18.3.

## מוסכמות שגיליתי

- **ציר הזמן — לפי שעון המשתמש** (`assets/js/chartaxis.js`): סימון חדש כש-`tickKey(t, step, kind, offset)` משתנה; `offset = tzOffset(ME().user.timezone)`.
  **לא לחזור ל-`Math.floor(t / step)` או ל-`getUTC*` בלי offset** — בישראל זה מציג 02:00 / 14:00. התווית של הנר האחרון — `fmtTime(t, 'last')`,
  נרשמת לפני שאר הסימונים ושומרת לה מקום. `tsAxis().last` — לבדיקות. בדיקות: `chartaxis-js.mjs`, `chart-axis-tz-ui.mjs`.

- **שעון קבוע בבדיקות ממשק:** `X-Dev-Now` לשרת **וגם** `ctx.clock.setFixedTime(NOW * 1000)` לדפדפן (ראו `tests/forecast-ui.mjs`, `open()`).
  - בלי השעון בדפדפן, הבדיקה נכשלת לבד שבוע אחרי NOW.
  - **ל-S3 זה רגיש:** שעון קבוע (`setFixedTime`) לא מקדם טיימרים. לבדיקת רענון צריך `ctx.clock.install()` ו-`runFor()`, או `fastForward`.
- ~~`market_candles` ב-`api.php` לא מעביר `ts_now()`~~ — תוקן (צ'אט החובות). `X-Dev-Now` חל עליו.
- **זוגות בשם (S1):** כל נכס מוצג דרך `label` (`ts_pair_label`). לא להחזיר סימול לטקסט הגלוי — `tests/pairs-ui.mjs` בודק את זה.
  - זוג מחושב `provider:1/SYMBOL` מחזיר `stream: null`. רענון אוטומטי הוא הדרך היחידה שלו להתעדכן.
- **ריצת כל הבדיקות:** `tests/*.php` רצות מהר. `tests/*.mjs` לוקחות כמה דקות — להריץ ברקע.
- **יומן הקוד** (`team/log/code.md`) מתנגש בכל מיזוג, כי כל PR מוסיף לסופו. הפתרון: למזג את `main` ולשמור את שני הצדדים.

## פתוח, לא בהיקף שלי

- `ts_ew_triangle_kind($p, $tol)` עוד לא מחובר למנוע (`lib/elliott.php`). כשיחובר — להעביר את `$prm['fib_tolerance']` (הערת הבקרה על #1592).
- היומן (19.4, ממתין לבעלים) עדיין מציג סימול שהמשתמש מקליד — לא נכס מספק.

## S3 — מה נבנה (#1598)

- **הגרף טוען הכול דרך `chart_refresh`** (`lib/refresh.php`): נרות + תוצאת כל שיטה (`methods=a,b`, עד 8) בבקשה אחת. מעביר `ts_now()` — כך `X-Dev-Now` חל על הגרף.
  - **לכן כל בדיקת UI שפותחת גרף עם `X-Dev-Now` צריכה גם `ctx.clock.setFixedTime(NOW * 1000)`**, אחרת הטווח מהדפדפן אחרי ה"עכשיו" של השרת → 422.
- **מטמון תוצאות:** `method_results` + `method_runs` ב-`data/cache.sqlite` (גרסה 3). מפתח: נכס, מסגרת, היקף, נרות סגורים (ראשון/אחרון/מספר), שיטה+גרסה, פרמטרים. "נבדקה לאחור" מחוץ למפתח.
  - S6 (יומן — "רשומה לנר") יכול להישען על זה: ריצה אמיתית = נר סגור חדש. `ts_method_result()` מחזיר `[res, ran]`.
- **`forecast.js` לא פונה לשרת:** `methods()`, `onResult(r)`, `onError(e)`; בחירת שיטה → `onChange` → `load('pick')`. ב-S5 (כמה שיטות) — `methods()` מחזיר כמה, והשרת כבר תומך.
- **`load(mode)` ב-`market.js`:** `open` / `change` / `manual` / `pick` / `auto`. רק `open`/`change`/`manual` עושים `fitContent` וסוגרים את ה-WebSocket.
- **בדיקות:** `tests/refresh-api.php`, `tests/refresh-ui.mjs`. בשעון מזויף (`clock.install` + `pauseAt`) — הטיימר הבא נקבע רק אחרי שהתשובה חזרה, לכן מקדמים מחזור-מחזור (`advance(p, ms, step)`).
- **`api()` ב-`app.js`** — לשגיאה יש עכשיו `e.status`.
- **הוכרע עם האפיון:** רענון רק בגרף (לא במסך "🧭 ניתוח"); הקצב ב-localStorage.

## S1.1 — תווית זוג (18.1.1)

- **השרת:** `ts_pair_label()` מחזיר `label` ("BTC ⇄ USDT", טקסט), `name` ("ביטקוין ⇄ טתר"), `base`/`quote` ({code, name}). בתשובות שיש בהן כבר `name` — `ts_pair_pick()` (שם → `pair_name`). הפוש והתיבה — "BTC ⇄ USDT".
- **הדפדפן (`app.js`, דרך `ctx`):** `pairLabel(p, {sub})`, `pairMain`, `pairSub`, `withVs(text)` (התו ⇄ → אייקון), `assetPicker` (כפתורים במקום `<select>`). האייקון — `assets/icon-vs.svg#vs` דרך `<use>`, בצבע הטקסט.
- **אסור בתווית:** המילה "מול" והסימול המחובר. `tests/pairs-ui.mjs` בודק את זה בכל מסך (`rules()`), וגם ניגוד האייקון בבהיר ובכהה.
- `setHead(title, sub, {docTitle})` — מקבל עכשיו אלמנט.

## F1 — מה נבנה (#1601)

- **🔮 = תפריט נפתח** (`.fc-pop` בתוך `.chart-wrap`, `forecast.js`): תיבות סימון, כמה שיטות יחד.
  - הבחירה: `chosen = [{id, color}]`, ב-`localStorage` `ts-forecast-methods`.
  - הצבע: הפנוי הראשון מ-`PALETTE` (`--brand`, `--m2`…`--m8` ב-`app.css`, בבהיר ובכהה).
  - `methods()` מחזיר את כל המזהים. שיטה שחוזרת `unavailable` יוצאת מהבחירה בשקט.
  - `state()` מחזיר עכשיו `{methods: [{id, color, selected, asOf, items}]}`, ולא `{methodId, …}`.
- **ל-S5:** המקרא ובחירת הצבע עוד לא נבנו. לכל שיטה יש בלוק `.forecast-panel[data-method]` עם `--mc` (הצבע שלה) ו-`.fc-dot` — שם לבנות.
- **שיטת קוד מהספר מופעלת בלי שורה** (`ts_method_on_by_default`).
  - גם שיטות הבדיקה של `TS_TEST_METHODS` (`test-cross-a/b`) נחשבות "מהספר", ולכן מופעלות.
  - בבדיקה שצריכה שיטה אחת, המנהל מכבה במפורש את השאר (ראו `journal-ui`, `analysis-ui`).
- **הצירים בתוך הגרף** (`assets/js/chartaxis.js`):
  - `rightPriceScale` ו-`timeScale` של הספרייה מוסתרים, והרשת שלה כבויה.
  - `mountAxis` מצייר את המספרים, את הרשת ושבב לסמן.
  - `fmtNum` — מפריד אלפים, בלי אפסים מיותרים.
- **`labelBoard()`** — לוח תוויות משותף לשיטות ולשרטוטים.
  - מי שמצייר ראשון גובר. ה-forecast נבנה ב-`viewChart` לפני ה-drawings, ולכן השיטות גוברות.
  - מצייר חדש על הגרף: `board.begin(owner)` בתחילת כל ציור, ו-`board.place(rect)` לכל תווית.
- **⛶ מסך מלא** — `fsEnter`/`fsLeave` ב-`viewChart`.
  - `history.pushState({tsFs})` באותה כתובת.
  - ה-Fullscreen API אסינכרוני; יציאה לפני שהבקשה הסתיימה כבר מטופלת.
- **`logicalX`** (`drawings.js`) — להשתמש בו תמיד במקום `logicalToCoordinate` לאינדקס שבור (LWC 4 מחזיר 0).
- **בבדיקות ממשק — מה זמין:**
  - על `.chart-box:not(.osc)`: `box.tsLabels()` (מלבני התוויות) ו-`box.tsAxis()` (`{price, time}`).
  - נתונים שבהם אליוט מוצאת ספירה: `TS_KLINES_SHAPE=wave` (סביב 82,000).
  - במסך מלא — לחכות ש-`document.fullscreenElement` יתאפס (`fsGone` ב-`tests/chart-f1-ui.mjs`).
  - יש שני `.chart-box` (גם `.osc`) — לבחור במפורש.
- **פתוח:**
  - קריטריון 9 (הסבר לכל תווית) — חלקי, עד המקרא של S5.
  - ~~בטלפון הגרף יורד בשורה כשהניתוח נדלק~~ — תוקן ב-S4 (הבורר עבר לפס).
  - שורת המשנה של כותרת הגרף נחתכת בטלפון ("…ce · ביטקוין ⇄ טתר").

## לקראת F1 — התוויות הירוקות (נענה ב-#1601)

ה"0.618 (75,979.99)" הירוקות והחופפות הן **שרטוטי פיבונאצ'י של המשתמש** (כלי ✏️ ← פיבו, `assets/js/drawings.js:118`): `${lv} (${fmtPrice(price)})`, בצבע שהמשתמש בחר (ירוק מ-`COLORS`), בצד השמאלי של השרטוט (`x1 + 3`). כמה שרטוטים על אותן נקודות → תוויות זו על זו. זה **לא** מאליוט. הבקרה ביקשה לרשום את התשובה ב-PR של F1.

## S1.2 — בחירה מרשימה ולשוניות (18.1.3, 18.1.4, #1603)

- **combobox** — `assets/js/combobox.js`:
  - `mountCombo(ctx, {onPick, recent, label, name})` ו-`assetField(ctx, {value, labels, recent})` (שדה עם `.value` ואירוע `change`).
  - הקטלוג (`api pairs`) נטען פעם אחת לדף. `invalidateCatalog()` — אחרי חיבור או ניתוק ספק (כבר מחובר ב-`market.js`).
  - הספק נשאל רק כשהקטלוג המקומי לא ענה. **רכיב חדש שבוחר נכס — דרכו, לא `<input>` חופשי.**
  - מסך "🧭 ניתוח" נמחק ב-S4. `assetPicker` נשאר — ההתראות משתמשות בו.
- **לשוניות** — `lib/tabs.php`, `lib/tab-lists.php`:
  - הפעולות `market_tabs`, `market_tabs_save`, `market_tab`.
  - נשמר ב-`users.market_tabs` (מיגרציה 9). `ts_profile()['market_tabs']`.
  - `ts_tabs_clean()` מכניס את "⭐ מועדפים" ראשונה תמיד.
- **מחיר בשורה** — `ts_cached_quote($asset, $scope)`: מהמאגר בלבד, בלי ספק. S4/S5 יכולים להשתמש בו.
- **תוויות** — `ts_pair_label` מחזיר תווית לנכס מהרשימות הקבועות (מניה, מדד, סחורה) גם בלי `asset_meta`.
- **ציר המחירים** — `axis.priceWidth()`. תווית של קו אופקי נצמדת משמאל לו, ומתקצרת ב-"…" כשאין מקום (`forecast.js`, `text()`).
- **הכותרת המשנית** — `setHead` עם אלמנט מוסיף ל-`#subtitle` את המחלקה `parts`. מה שצריך להישאר שלם — בתוך `.sub-tail`.
- **בדיקות:**
  - `tests/tabs-api.php`, `tests/market-tabs-ui.mjs`.
  - מסך השוק כבר בלי `<form>`. בדיקה שנכנסת לשוק מחכה ל-`main [role="combobox"]`.
  - `builder-api` בודק גרסת מסד ≥ 8.
- **צילומים** — רק עם `SHOTS=<תיקייה מחוץ לריפו>`. `tests/shots/` ב-`.gitignore`.
- **⚪ מהבקרה (#1603):** בהרצה רצופה של כל הבדיקות, `market-tabs-ui.mjs` נעצר פעם אחת בבדיקה 1 מתוך 38, ואחר כך עבר פעמיים. החתימה לא נשמרה. אם זה חוזר — להריץ לבד, ולבדוק את ההמתנה הראשונה (`combo(a).waitFor()`) מול זמן העלייה של `php -S`: הבדיקה ממתינה לשרת שנייה אחת קבועה.
- **פתוח:** חלק מסימולי המדדים והסחורות של Twelve Data לא נבדקו מול הספק (פירוט ב-#1603). הרכב המדדים מתעדכן ידנית (ש5).

## S4 — מה נבנה (18.4, #1605)

- **השרת — ממצא ותרחיש בשדות נפרדים:** `ts_overlay_compute` → `overlay` (ממצא) + `forecast` ({as_of, bars, items, scenarios}).
  - `ts_forecast_split` מחליט מה תרחיש (`ts_forecast_item`): זמן אחרי as_of, polyline מקווקו, או hline של `invalidation`. stop/target — ממצא.
  - התרחיש נחתך ל-as_of (`ts_forecast_clip`); `projection` לא קיים יותר בממצא — התרחישים ב-`forecast.scenarios`.
  - `TS_RESULT_FORMAT` (עכשיו 2) נכנס למפתח החישוב. **שינוי בצורת התוצאה = להעלות אותו**, אחרת המטמון מחזיר צורה ישנה.
- **הכרטיס ב-`chart_refresh`:** `analysis` = {cards, blend, profile, account_size, decimals, last_t, disclaimer}.
  - `ts_analysis_cards($uid, $asset, $tf, $prepared, $now, $runBt)` — הליבה המשותפת; `ts_analysis` = runBt true.
  - `ts_backtest_stored` — בלי חישוב; `missing`, או השמורה עם `stale`.
  - `ts_card_analyze` שומר את הניתוח ב-`method_results` (מפתח `card|…` + `computed_at` של הבדיקה לאחור). לא נספר ב-`method_runs`.
  - `ts_method_key` עבר ל-`lib/analysis.php`.
- **הבדיקה לאחור — רק בפעולה של המשתמש:** `market.js` → `runBacktest()` אחרי `load()` שאינו `auto`, כש-`forecast.needsBacktest()`. פעם אחת לכל (מסגרת, שיטות); "↻" מכריח.
- **הדפדפן:**
  - `assets/js/card.js` — `cardParts(ctx)`: `finding`, `probability`, `horizon`, `actions`, `blendCard`, `biasWord`.
  - `forecast.js` — הפס: `.an-panel` → `.an-bar` (כותרת + `tools`: בורר הקצב ו"עודכן לפני"), `.an-tfs`, שקלול, ו-`.forecast-panel.analysis-card[data-method]` לכל שיטה עם `.an-sec.finding/.scenario/.prob`.
  - `chosen` = `[{id, color, hide, fold}]` — "הסתר תרחיש" והקיפול נשמרים ב-`ts-forecast-methods`.
  - `mountForecast` מקבל עכשיו `ctx, asset, pair, tools, tfs, setTf`; מחזיר גם `needsBacktest`, `backtesting`, `onAnalysis`.
  - ~~ל-S5: המקרא ובחירת הצבע~~ — נבנו ב-S5 (#1608): הבחירה בתפריט, המקרא מעל הגרף.
- **הוסר:** 🧭, `viewAnalysis`, הלשונית "ניתוח" (4 לשוניות), `method_overlay`/`ts_method_overlay`. `#/analysis/X` → `#/chart/X`; קישור ההתראות → `#/chart/`.
- **בדיקות:**
  - `tests/analysis-ui.mjs` — נכתב מחדש ל-S4 (נתוני `wave`, שעון קבוע). שם: הגרף לא זז כשהניתוח נדלק, רענון 30 שניות בלי `analysis`, קיפול, שקלול, כהה.
  - `box.tsForecast()` על `.chart-box:not(.osc)` — `{methods: [{id, finding, scenario, hide, …}]}`.
  - בדיקה שפותחת גרף עם שיטה: `localStorage ts-forecast-methods` + `sessionStorage ts-chart:<asset>` ({tf}) ואז reload; לחכות ש-`.an-sec.prob` בלי "מריץ בדיקה לאחור" (ראו `journal-ui`).
  - בנתוני `wave` לשיטת הדוגמה אין אות פעיל — אין לה תרחיש. לתרחיש — אליוט.
- **פתוח:** ⚪ `alerts-ui.mjs` ("mark all read — no badge") נכשל לפעמים בהרצה מקבילה של כל הבדיקות, גם ב-main לפני S4. לבד — עובר.

## S5 — מה נבנה (18.5, #1608)

- **השרת — צבע לכל שיטה בפרופיל:**
  - `lib/method-colors.php`: `TS_METHOD_PALETTE` (= `PALETTE` ב-`forecast.js`), `ts_method_colors($uid)` (תמיד אובייקט — `{}` ב-JSON), `ts_method_colors_save`.
  - `users.method_colors` — מיגרציה 10, ALTER בלבד. `ts_profile()['method_colors']`, ולכן גם ב-`me`.
  - הפעולה `method_colors_save` {colors}. המפה נשמרת שלמה: צבע לשיטה אחת בלבד (422), רק מהפלטה, עד 8.
- **הדפדפן (`forecast.js`):**
  - **הפרופיל הוא האמת.** `reconcile()` בעלייה, `colorFor()` בסימון, `remember()` שולח רק כשהמפה השתנתה. צבע ששמור לשיטה אחרת (לא על הגרף) יורד ממנה.
  - **בתפריט:** `.fc-row` = `.fc-opt` (התווית עם תיבת הסימון — כמו קודם) + `.fc-colors` (רדיו `name="fc-color-<id>"`, `value="--m5"`). תפוס — `disabled`.
  - **המקרא:** `.fc-legend` / `.fc-leg[data-method]` — שכבה בראש `.chart-wrap`. רשום בלוח התוויות (`by: 'legend'`) לפני שהשיטות מציירות.
  - **הסתרה במקרא:** `off` ב-`ts-forecast-methods`. `shown()` — מה שמצויר; `methods()` — עדיין כל המסומנות.
  - **כל הציור בצבע השיטה** — גם רמות (היו אדום/ירוק לפי role) וגם סימונים על נרות. הכיוון — בצורת החץ.
  - **כפתור הפס "הסתרה" → "הסרה"** (מבטל סימון).
- **בדיקות:**
  - `tests/method-colors-api.php`, `tests/method-colors-ui.mjs`.
  - `box.tsForecast()` מחזיר עכשיו גם `drawn` ({m, k, c, alt} לכל ציור — כולל `marker` ו-`text`) ו-`colors` (המפה מהפרופיל).
  - **נתונים שבהם גם לאליוט וגם לשיטת הדוגמה יש ציור:** `wave` עם `NOW = 1794094200 + 5 * 86400`. ב-NOW הרגיל לשיטת הדוגמה אין אות.
  - **חלופות של אליוט** לא יוצאות בנתוני `wave`. הבדיקה מוסיפה חלופה לתשובה דרך `page.route`.
- **מוסכמה חדשה — חובה בכל בדיקת UI שקוראת ל-`post()`:** לחכות ל-`main [role="combobox"]` ולא ל-`main`.
  - אחרת `post()` קורא ל-`me` לפני שה-`me` של הדף חזר, ונפתחו שני sessions. הדף נשאר עם csrf ישן, וכל כתיבה מהדף מקבלת 403.
  - עכשיו הגרף כותב (שמירת צבע), ולכן זה צץ.
- **פתוח:**
  - **⚪ `market-tabs-ui`** נכשלה פעם אחת בהרצה מקבילה (ההמתנה הראשונה ל-combobox), כמו ב-#1603. לבד — עוברת 2/2.
  - **הפלטה** לא שונתה מ-F1. הניגוד ≥ 3:1 נבדק, אבל `--brand` ו-`--m8` (כחול ואפור-כחול) קרובים זה לזה בעין.

## S6 — מה נבנה (18.6, #1611)

- **הטבלה:** `method_journal` ב-`data/app.sqlite` (מיגרציה 11). **לא** במאגר הנרות — הוא מתרוקן ומתנקה.
  - `UNIQUE (method_id, method_version, asset, tf, cred_scope, as_of, kind)` + `INSERT OR IGNORE` — רשומה לא משוכתבת לעולם.
  - `outcome`: `NULL` = עוד לא ידוע; `zone` / `invalidated` / `neither`; `none` = אין תרחיש (וכל רשומת `backtest`).
  - המחירים (`zone_lo`, `zone_hi`, `invalidation`) — מחרוזות עשרוניות לפי `tick_decimals`.
  - **בלי `user_id`** (✅27). מי רואה — לפי `cred_scope`.
- **הכתיבה (`lib/journal.php`):**
  - `ts_journal_analysis` — נקרא רק מ-`ts_method_result` כש-`ran` (נר סגור חדש). נרשם התרחיש המוביל (`primary`).
  - `ts_journal_backtest` — מ-`ts_backtest`, רק בחישוב חדש.
  - שתיהן בולעות שגיאות (`error_log`) — לא מפילות רענון.
  - **שינוי בצורת `forecast` (S4) ישבור את `ts_journal_said`** — לעדכן אותה יחד.
- **התוצאה:** `ts_journal_resolve` נקרא מ-`ts_alerts_run` (אחרי ההתראות, לפני הניקוי), עם אותו `$used` ותקרת זמן; `$sum['journal']` בפלט ה-cron.
  - רק כש-`until_t + נר <= now`. הנרות — `ts_market_candles` במשתמש של ההיקף (`ts_journal_uid_for`: `''`→0, `u:N`→N, `c:N`→משויך ראשון).
  - הכלל — `ts_journal_outcome` (טהורה): הראשון שנגע; שניהם באותו נר → `invalidated` + `same_candle`.
- **הקריאה:** `method_journal` (GET) → `ts_journal_list`: עמוד של 50 (`before`), `summary` על כל מה שהמשתמש רואה, סינון `method`/`version`/`outcome`/`kind`. `scope_kind` — רק למנהל.
- **המסך:** `assets/js/method-journal.js` — `view(host)` ו-`viewMethodJournal()`. `#/method-journal` ("עוד") ולשונית `journal` בניהול (`ctx.journalView`).
- **בדיקות:** `tests/method-journal-api.php` (כולל בדיקה עצמאית של התוצאה מנוסחת `wave`), `tests/method-journal-ui.mjs`.
  - ה-cron בבדיקה: `bin/alerts-cron.php --now=<until_t + 3660>` עם אותו `TS_DB_PATH`.
- **ל-S7:** היומן הוא מקור הראיות. הסיכום לפי גרסה כבר שם (`summary[].zone_pct` מתוך מה שהוכרע).
- **פתוח:** ההכרעות שלי (README, "הכרעות של כותב הקוד (S6)") לא אושרו מול האפיון — במיוחד שהמסך פתוח גם למשתמש רגיל.

## S7 — מה נבנה (18.7 + 18.5/2.20, #1615)

- **ספירות על סט קבוע:** `tests/elliott-counts.php` ← `tests/fixtures/elliott/sets.php` מול `tests/fixtures/elliott/counts.json`.
  - נבדקים: ספירות אליוט (ברירות המחדל + רגישות 2) ואותות elliott-w3, במספרים בעיגול קבוע (6 ספרות).
  - **כל שינוי במנוע אליוט שמשנה ספירה מכשיל את הבדיקה.** מעדכנים עם `UPDATE_COUNTS=1 php trading-strategies/tests/elliott-counts.php`, ובתיאור ה-PR כותבים "משנה ספירה: כן". הבקרה לא ממזגת PR כזה בלי אישור הבעלים.
  - לסט **מוסיפים**, לא משנים.
- **הגרסה של שיטה = גרסת הקוד (או ההגדרה) + `methods.tune`** (`TsMethod::$tune`, נטען ב-`ts_method()`).
  - **שיטה חדשה בקוד:** `'version' => 1 + $this->tune`. בלי זה, "החל" לא יעלה לה גרסה.
  - בלי קובץ מסד (בדיקות טהורות) — `tune` = 0, ושום מסד לא נפתח.
- **הצעה והחלה — `lib/tune.php`** (מיגרציה 12: `methods.tune`, `method_proposals`):
  - הפעולות `admin_tune_list`, `admin_tune_propose` (נספרת בתקרת 30), `admin_tune_apply` (רק הראשי).
  - החלוקה: `ts_tune_split` — 70/30 לפי מספר נרות. כל חלק רץ מאפס, ב-`ts_bt_run`.
  - `admin_log`: `method_propose`, `method_tune`. `target` עד 300 תווים — לכן שתי התוצאות שם בקיצור, והמלאות ב-`method_proposals.results`.
  - הצעה "ישנה" (הגרסה או הערך זזו) — מחושב ב-`ts_tune_row`, ולא מוחלת.
- **`admin_method_save` עם פרמטרים אחרים** — רק הראשי (403 לאחר), ומעלה `tune`.
  - הפעלה, שווקים ומסגרות לבד — לא משנים גרסה.
  - בבדיקות שמכבות או מפעילות שיטה עם `params: []` אחרי ששונו פרמטרים — זו החזרה לברירת מחדל, והיא מעלה גרסה.
- **צבעים `{id: {color, at}}`:**
  - השרת (`ts_method_colors_clean`) אוכף צבע אחד רק בין שיטות **זמינות** (`ts_methods_visible`). עד 64 רשומות. מקבל גם את המבנה הישן.
  - הדפדפן (`forecast.js`): `colorFor` (פנוי ← ואם אין, "לא סומנה הכי הרבה זמן"), `remember(marked)`, `available()`, `.fc-max`.
  - `box.tsForecast().colors` מחזיר עכשיו `{id: {color, at}}`, לא מחרוזת.
- **בדיקות:**
  - `tests/tune-api.php`, `tests/tune-ui.mjs`, `tests/method-colors-many-ui.mjs`.
  - `tests/fixtures/methods/many.php` — 9 שיטות בדיקה (t1…t9), דרך `TS_TEST_METHODS`.
  - **מלכודת שגיליתי:** אחרי `post()` ששומר משהו בפרופיל, `me` של הדף ישן. מעבר hash לא טוען אותו מחדש — צריך `reload()`.
- **פתוח:** הכרעה שלי לבדוק מול האפיון — שינוי פרמטר ישיר רק לראשי (README, "הכרעות של כותב הקוד (S7)").

## A1 — DevLoop (#1618, PROTOCOL 7, app-rules 4)

- **המודול המשותף:** `lib/devloop.php`, `assets/js/devloop.js`, `assets/css/devloop.css` — **עותקים, לא עורכים**. שינוי —
  ב-`devloop/src`, ואז `php devloop/tools/sync.php` (השער בפריסה עוצר עותק ששונה). החיבור — `lib/devtasks.php`.
- **המפתח = המנהל הראשי בלבד.** `users.platform_admin` (מיגרציה 13) מתעדכן ב-`ts_user_for` בכל בקשה — בשביל אסימון
  של Claude, שמגיע בלי משתמש. **מיגרציה הבאה — 14.**
- **ב-`api.php`:** `devapi-*` מטופלות **לפני** `ts_session()` (באסימון, ב-`"public"`); `dev-*` ו-`devloop-client-error` —
  מיד אחרי ה-session, לפני `TS_WRITE`. `devloop_log_begin(null)` בראש, ו-`devloop_log_error($e)` בכל `catch` — **פעולה
  חדשה שזורקת חריגה משלה: להוסיף `devloop_log_error` גם ב-catch שלה**, אחרת היא לא תגיע ל"⚠️ לבירור".
- **`api()` ב-`app.js`** מדווח כל בקשה ל-`DevLoop.noteRequest` (ההקשר של 🐞). 🐞 יושב לפני `#head-actions` — `setHead` לא
  מוחק אותו.
- **נתונים:** `data/_dev` (צילומים, `log.sqlite`) + טבלאות `dev_*` במסד. בבדיקות — `TS_DB_PATH.dev`.
- **בדיקות:** `tests/devloop-api.php`, `tests/devloop-ui.mjs`. במסך המטלות ברירת המחדל היא "ממתינות לך" — בבדיקה לוחצים
  "הכול" לפני שמחפשים מטלה.
- **ביקורת:** `team/reviews/a1.md` — בלי 🔴/🟡. ראיות פריסה ל-`TS-` ב-`deploy.yml` — נכנסו ב-#1620 (A3); השורה ב-
  `devloop/README.md` — בקשה לפלטפורמה; האסימון `tsd_…` כ-`TS_DEVLOOP_TOKEN` — מטלה לבעלים, אצל הבקרה.

## A2 — התחברות עם אפשרות ניתוק (#1623; spec-stage-a 20.1, גרסה 2.23; app-rules 2)

- **הקבצים:**
  - `lib/sso.php` — המצב מ-`sso.php?action=config`, במטמון לדקה ב-`data/platform-config.json`. אחד מ: `connected`, `detached`, `unknown`.
    - הזרימה `sso_start` / `sso_callback`, עם כל הבדיקות של sso.md §2 ועוד iss ו-jti.
    - `platform_key` ו-`ts_grant_status()` — בקשה חתומה. המפתח הפרטי במסד, ב-`settings.platform_sign_key`, מוצפן ב-`lib/secret.php`.
  - `lib/auth.php` — `ts_session()` לפי המצב, החשבון (20.1 ה'), והסיסמה.
- **כל הכתובות נגזרות מ-`app.json` (`sso.return`) — לעולם לא מהבקשה.** בפיתוח: `TS_APP_URL`.
- **`sessions.via`:**
  - `gate` — לא עובר למצב מנותק (ק11).
  - `sso` / `password` — רק במנותקת. ההרשאה נבדקת שוב כל 5 דקות (`grant_checked_at`).
    - `false` → ה-session נמחק (`TsNoAccess`).
    - אין תשובה → 503 (`TsUnavailable`). לא נמחק.
- **חשבון:**
  - אותו sub → אותו חשבון.
  - אותו מייל עם sub אחר → `TsConflict` (403, או `#/login/conflict`), ו-`account_conflict` ב-`admin_log`.
  - אחרת → חשבון חדש.
  - מייל שתפוס בשורה אחרת לא מועתק בעדכון.
  - **אין "קישור לשורה בלי sub"** (2.23 — `platform_sub` הוא NOT NULL). לא להוסיף.
- **המנהל הראשי במנותקת:** `users.platform_admin` השמור. רק השער כותב אותו (`ts_user_for`, `platform_admin: null` מ-SSO).
  - `ts_is_primary_admin()` — לפי `ts_current()['via']`.
  - ה-token של DevLoop במנותקת — `ts_dev_granted()` ב-`lib/devtasks.php`, `grant_status` שמור 5 דקות.
- **סיסמה לאפליקציה:**
  - `password_hash`, עד 72 בתים.
  - נקבעת בפרופיל (`password_set`). החלפה סוגרת כניסות אחרות בסיסמה.
  - איפוס — `admin_password_reset`, רק הראשי.
  - הנעילה: `login_locks`, אטומית (`ON CONFLICT … fails + 1`), לפי `email:` ולפי `ip:`.
  - יותר משורה אחת עם סיסמה לאותו מייל → 409.
- **בבדיקות (`tests/dev-prepend.php`):**
  - `X-Dev-Platform` (או העוגייה `dev_platform`) = `connected` (ברירת מחדל) / `detached` / `unknown` / `live`.
    - `live` שואל את "המעטפת" דרך `TS_HTTP_FIXTURES`.
  - `X-Dev-Password: 1` — `login.password`.
  - grant_status — fixture על `provision.php?action=grant_status` (ראו `grant()` ב-`tests/sso-api.php`).
- **בדיקות:** `tests/sso-api.php` (107), `tests/login-ui.mjs` (20).
  - **ב-Playwright אין ניתוב לבקשות בתוך שרשרת הפניה** — לכן `login-ui` מיירט את `sso_start` עצמו (`route.fetch({maxRedirects: 0})`).
- **ביקורת:** `team/reviews/a2.md`. כל ה-🟡 וה-⚪ תוקנו בסבב 2.
  - ⚪ לבעלים, לפי האפיון: מנהל ראשי שהודח בזמן ניתוק נשאר מנהל עד הכניסה הבאה דרך השער.
- **פתוח, לא בפרוסה:** Google (ש8), שכחתי סיסמה במייל (ש9), קישור ידני של `account_conflict` (ש2).

## 21.1 — בונה השיטות נמחק (#1627, #1629; spec-stage-a 21.1, גרסה 2.24, ✅29)

- **אין יותר שיטה מוגדרת.** שיטה חדשה = קובץ ב-`lib/methods/` ושורה ב-`registry.php`. `ts_method_ids()` / `ts_method_exists()`
  — שיטות הקוד בלבד (ו-`TS_TEST_METHODS` בבדיקות).
- **הנתונים לא נמחקו:** `methods.kind = 'defined'`, `definition`, `definition_version`, `method_versions` — נשארו במסד, לא נטענים.
  **לא לבנות עליהם ולא למחוק אותם** בלי הכרעה.
- **"שיטה שהוסרה"** — כל `method_id` שאינו בקוד:
  - התראה (גם שקלול שכולל אותה) → `paused` ב-`ts_alerts_pause_removed` (מה-cron ומ-`alerts`), `method_removed: true`, 422 בהפעלה.
  - יומן השיטה — לא מוצג (גם למנהל, `ts_journal_scope_sql`), וה-cron לא מכריע.
  - עסקה — `ts_trade_method_name()`: "`<id>` (שיטה שהוסרה)".
- `admin_method_save` עם `definition` → 422. `admin_method_list/validate/dry_run/versions` — לא קיימות (404).
- **מלכודת שגיליתי (#1629):** בדיקה שבודקת **יומן שגיאות ריק לגמרי** נופלת בפריסה — ה-runner כותב בעלייה
  `JIT … disabled. in Unknown on line 0`. לסנן את `in Unknown on line 0` ולחפש רק `PHP (Fatal error|Parse error|Warning|Notice|Deprecated)`
  (ראו הסוף של `tests/builder-removed-api.php`).
- `dev/open-items.json`: TS-009 (4ב) — `cancelled`.

## 21.2 — מילוי איטי ברקע (#1630; spec-stage-a 21.2, גרסה 2.24, ✅30, ש10 — ברירת המחדל)

- **`lib/prefill.php`** → `ts_prefill_run($now, &$used, $budget, $t0, $usedKey)` מתוך `ts_alerts_run`, אחרי היומן, בתוך try/catch.
  - `ts_prefill_keys` — מפתח לכל ספק משותף (`uid 0`, `scope ''`), ולכל משתמש עם מפתח **אישי** (`source own`) ו-`prefill_off = 0`.
  - `ts_prefill_key` — מפתח אחד; חריגה בו → `key_errors`, ממשיכים לבא.
  - `TS_PREFILL_EVERY` — Binance 0, ECB יום, Twelve Data 3 ימים. ספק שאינו שם — לא ממולא (Finnhub לא).
  - תקרות: `intdiv(perMin, 2)` בניכוי מה שההתראות לקחו (משותף — `$used[ספק]`, אישי — `$usedKey["ספק|היקף"]`); 25% ליום ב-
    `provider_usage` עם `key = prefill:<usage>`, לפי `gmdate('Y-m-d', $now)`.
- **מצב:** `prefill_state` (מיגרציה 15): `filled_at`, `failed_at` (שעה המתנה), `empty` (הספק ענה בלי נרות). נכס שהמאגר שלו
  נוקה — נחשב לא מולא (`ts_prefill_cached`).
- **`fetch_log`:** המילוי **לא נרשם** (`ts_fetch_as_system`) — בלי זה הוא הציף את 2,000 השורות. "נפתחו לאחרונה" = `user_id > 0`.
- **בממשק:** `age_days` בשורה של `market_tab` (שלם, ≥ 1; `ts_market_tab($uid, $id, $now)`), `.mq-age` ב-`market.js`.
  `own.prefill` ב-`ts_providers_for` + `prefill_set {on}`; המתג `input[aria-label="מילוי ברקע במפתח שלי"]`.
- **בדיקות:** `tests/prefill-api.php` (52), `tests/prefill-ui.mjs` (18). `tests/fixtures/prefill` — הספקים המדומים, ו-MSFT
  ב-Twelve Data → 429.
  - **מלכודת:** `NOW` = 23:30 UTC. `NOW + n·86400 + 1800` הוא **חצות של היום הבא** — יום חדש לתקרה היומית.
  - עם מפתח Twelve Data, גם מט"ח נפתר אצל המשתמש ל-Twelve Data (`ts_pair_asset`). בבדיקה שתלויה בסדר — לקבוע לו לשוניות.
- **פתוח, לא בפרוסה:** "לפני N ימים" לפי פתיחת הנר (⚪ בביקורת — לפי סגירה היה סותר את קריטריון 6); 25% לא מתחשבים בשימוש
  של המשתמש עצמו (⚪, רשות). ⚪ `market-tabs-ui` רועדת גם ב-main (בדיקה 38 / ההמתנה הראשונה).
  ⚪ (ביקורת סבב 2, לעתיד) הפניות של `ts_journal_resolve` לא נספרות ב-`$usedKey` — מפתח אישי יכול לקבל מהיומן ועוד חצי מהמילוי.

## 21.3 — Twelve Data במאגר משותף (#1642; spec-stage-a 21.3, גרסאות 2.25–2.26, ✅31)

- **מאגר אחד:** `twelvedata.php` → `share: true`. בספק משותף ההיקף נקבע לפי הספק — `''` בכל מפתח (`ts_access_chain`, `ts_scope_for`).
  Finnhub — בלי שינוי (`u:`/`c:`). **מה שעוד מניח "Twelve Data = אישי" — שגוי.** בדיקות הבידוד ("מאגר ורישוי") עברו ל-Finnhub.
- **הדרכים לספק — `ts_access_chain($uid, $p)`, לפי הסדר:** מפתח האפליקציה (`source: app`, `user_limit` 100 ליום, לא ל-uid 0) ← `own` ← `shared`.
  `ts_access()` = הראשונה. `ts_provider_call_chain` עובר לדרך הבאה על `TsCapError` (התקרה שלנו) ועל `TsRateError` (429 מהספק).
  - **מלכודת:** `ts_app_key()` כבר תפוס ב-`lib/sso.php` — שלנו נקרא `ts_app_cred()`.
  - `ts_provider_call` שומר את מחלקת השגיאה (`$e::class`), אחרת `TsRateError` היה הופך ל-`TsProviderError` רגיל.
- **בלי שום דרך** (`ts_pool_only`): `ts_market_candles` מגיש מה שבמאגר, עם `source: pool`.
  - כשאין במה להשלים (תקרה, 429 או אין מפתח) — התשובה מחזירה `notice`, ובגרף מוצג `.chart-notice`.
  - בלי נרות בכלל — שגיאה, כמו קודם.
  - **מי שקורא ל-`ts_market_candles` ומכריע משהו — חייב לבדוק `notice`.** המילוי ברקע מסמן כשל; היומן מחכה.
- **מפתח האפליקציה:** `admin_app_key {provider, cred_id|null, user_limit?}` — רק המנהל הראשי. ההגדרות `app_key:<p>` ו-`app_key_limit:<p>`.
  מחיקת המפתח מנקה. מפתח שלא מתפענח — מדלגים עליו (כאילו אין). מסך: "🤝 לחלוקה", `.app-key`.
- **זוגות (קריטריון 10):**
  - `ts_pair_asset` — דרך מפתח האפליקציה בלבד, רק כשאין לזוג ספק חינמי.
  - `ts_pair_tf_asset` — מסגרת שהספק לא נותן (ECB — יומי) עוברת לנכס אחר באותו זוג, בבקשה בלבד (`ts_market_candles`, `chart_refresh`).
  - `pair_labels` מחזיר `tfs`, והגרף מציע אותן.
  - המילוי ברקע מסנן את הזוגות האלה (`ts_pair_has_free`).
- **המילוי ברקע:** Twelve Data רק במפתח האפליקציה (uid 0); בלי מפתח אפליקציה — לא ממולא. `prefill_set` והמתג הוסרו;
  `users.prefill_off` נשארת, בלי תפקיד. **פתוח לבחירה:** הפרמטרים `$mine`/`$ownTabs` ב-`ts_prefill_order`/`ts_prefill_pool` כבר לא בשימוש.
- **נתונים ישנים:**
  - בדיקות לאחור — `ts_bt_read_scopes`: קודם `''`, ואם אין — `u:<uid>` של המשתמש עצמו.
  - היומן ב-`u:N` מוכרע מהמאגר, ונשאר רק לבעליו.
  - היומן ב-`''` בלי מפתח אפליקציה — מוכרע מהמאגר, רק כשהוא שלם.
- **מיגרציות:** `cache.sqlite` 4 — `ts_cache_pool_merge` (נרות `u:%`/`c:%` → `''`, המאוחר גובר, מחיקת המקור והטווחים/קצוות/חיפושים שלו);
  `app.sqlite` 16 — `prefill_state` של Twelve Data ב-`u:%`.
- **בדיקות:** `tests/shared-pool.php` (43 — הכול כאן, יחידה בלי שרת), `prefill-api.php` (55), `prefill-ui.mjs` (35).
  ב-`fixtures/market/twelvedata.php`: מפתח `good-rate…` מחזיר 429.
- ~~⚪ `market-tabs-ui` — עדיין רועדת~~ — הסיבה נמצאה ותוקנה (צ'אט החובות, הסעיף בסוף).
- **התראות** ב-cron נספרות ב-100 היומיות של המשתמש שיצר אותן (ביקורת ⚪6) — בכוונה.

## F-1 — פיבונאצ'י, ממצא ואותות (spec-stage-a פרק 22, 2.27)

- **הקבצים:** `lib/fibonacci.php` — טהור: `ts_fib_grid($win, $pivots, $p)` (שני הצדדים), `ts_fib_side` (צד אחד בכיוון תמיכה), `ts_fib_signal`, `ts_fib_grid_of($candles, $p)` (לבדיקות ולסט הקבוע).
  `lib/methods/fibonacci.php` — `TsMethodFibonacci`. המצב: ATR, zigzag (של אליוט), חלון `w` של `lookback_bars` נרות עם ה-ATR שלהם, `piv` שבחלון, `g` (הרשת מנרות ≤ האחרון), `x` (האות האחרון ותוקפו).
- **בלי הצצה לעתיד:** ב-`step` האות נבדק מול `g` של הנר הקודם, ורק אחר כך הנר נכנס לחלון והרשת מחושבת מחדש.
- **התנגדות = תמיכה במראה** (מחירים × −1, H ↔ L). שינוי בכלל — רק ב-`ts_fib_side`.
- **סוגי נקודות סיום (`kinds`):** `pivot`, `gap`, `strong`, `longest` — אותו מחיר משני מקורות = נקודה אחת עם כמה סוגים. הציון: רמות + 1 לכל טווח עם gap/strong/longest.
- **`meta.default_off`** — חדש: `ts_method_on_by_default` מכבה שיטת קוד מהספר עד שמנהל מפעיל. שיטה חדשה שהאפיון קובע לה "כבויה" — להוסיף אותו.
- **בגרף (`forecast.js`):** `zone.extend` / `polyline.extend` — עד קצה הגרף; `polyline.single` — דהויה (0.35), ו-`chosen[].solo` מסתיר אותה (כפתור `.fc-solo` בממצא, רק כשיש רמות בודדות).
  `drawn` מחזיר גם `faint` ו-`extend`.
- **הסט הקבוע:** `tests/fixtures/fibonacci/sets.php` + `zones.json`. כל שינוי ברשת או באותות מכשיל את `fibonacci-zones.php` — `UPDATE_ZONES=1` ו"משנה אזורים: כן" ב-PR.
- **נתוני wave:** בשעה, ב-`1794094200` אין לפיבונאצ'י אזור. 70 שעות קודם — יש אזור ורמות בודדות (`fibonacci-api.php`, `fibonacci-ui.mjs`).
- **הנעילה (הכרעת הבקרה "ג" על 22.3(7)):** `ts_fib_side(..., $prev)` — אותו start → מחזיר את הצד הקודם פחות טווחים שנשברו אחרי `built_t` (S17), והאזורים מחדש (`ts_fib_zones`, ATR של הבנייה). `ts_fib_grid_of` משחזר את הזרימה על lookback_bars + 1 הנרות האחרונים (זהה לריצה מנר 0 — נבדק).
- **ביקורת סבב 1 (#1662):** H = הגוף הגבוה ביותר מנר הקיצון עד נר האישור (`ct` בנקודות המפנה); S17 אחיד לכל סוג; `max_ranges` אחרי הפסילה; אות רק כשגם הנר הקודם נסגר מעבר לאזור.
- **מקובל ב-F-1 (הבקרה):** בברירות המחדל, על 5,000 נרות שעה: 33 נגיעות באזור בציון ≥ 3, לכולן אין אזור התנגדות מעל → 0 אותות (יעד של 22.6 לפני F-2). min_score 2 + min_rr 1 → 5; ועוד רגישות 2 → 23. ב-F-2 תתווסף דרישה ל-> 0 בברירות המחדל.
- **ביצועים:** נפתר ב-F-2 (`ts_fib_side_next`) — ראו F-2.
- **לא בפרוסה:** F-2 (22.5 — A-B-X, קופסת מראה, Z6, יעד חלופי), F-3 (מתרחב/מתכווץ, ואקום), עמ' 102.

## F-2 — פיבונאצ'י, התרחיש (spec-stage-a 22.5, 2.31)

- **הקבצים:** הכול ב-`lib/fibonacci.php` — `ts_fib_abx`, `ts_fib_pivot_body`, `ts_fib_box`, `ts_fib_project` (Z6 + `box` לכל אזור),
  `ts_fib_scenario` (פריטי התרחיש), `ts_fib_advance`/`ts_fib_init` (הזרימה — אותו קוד ל-step ול-`ts_fib_grid_of`).
- **המצב:** `k` — שלוש נקודות המפנה האחרונות, במחיר הגוף (לא נחתכות בחלון). הצד שומר `move` (M0) ו-`pv`; הרשת שומרת `pl`
  (הנקודה האחרונה בחלון) ו-`abx`. אזור: `base` (ציון F-1), `z6`, `score`, `box {m0, mid, T, levels}`.
- **הנתיב המהיר (`ts_fib_side_next`):** רק כש-`prev.t` הוא הנר הקודם ו-`pl` לא השתנה. כל שינוי בבחירת `H` או בנעילה — לעדכן גם שם,
  ו-`tests/fibonacci-f2.php` ("performance") יתפוס אם לא. 5,000 נרות: 0.05 שניות; lookback 1000: 0.13.
- **גרסת השיטה — 2.** אותות: `via` (zone/box), `z6`, `box_t`.
- **`box_target` (2.32):** 0.5 / 0.618 / 1 — `ts_fib_box_target`; ב-1 מחזיר `T` עצמו (בלי עיגול). `validate` דוחה ערך אחר.
- **Z6 (ביקורת #1685 🟡1):** רק A-B-X 100% ו-`T` של האזור המוביל בכל צד (`ts_fib_best_zone`, לפי `base` והסגירה).
- **`$scenario`** (מאפיין של השיטה) — `false` מחזיר overlay בלי תרחיש; קריטריון 9 משווה איתו.
- **הסטופ (פ10, הבעלים):** `min(zone.lo, l_c) − stop_atr × ATR`; שורט — `max(zone.hi, h_c) + …`. ב-`ts_fib_signal`.
- **6.6:** בתוויות של התרחיש — "הקרנה", לא "יעד". `fibonacci-api.php` ו-`fibonacci-f2.php` בודקים.
- **בגרף:** פריטי התרחיש מסומנים `scen` ב-`drawn`; `bold` — יעד בתוך אזור; `single` — דהוי (גם `zone`). `primary` — ליומן.
- **אותות בברירות המחדל על 5,000 נרות שעה: 63** (23 לונג, 40 שורט, כולם עם יעד קופסה). בבדיקה לאחור: 24 עסקאות, 1 רווח, −25.8R —
  דווח לאפיון. לא שינינו ברירת מחדל (S7).
- **לא בפרוסה:** F-3 (S25, ואקום), עמ' 102.

## F-3 — פיבונאצ'י, מידע (S25, ואקום)

- `ts_fib_market` / `ts_fib_s25_classify` / `ts_fib_vacuums` ב-`lib/fibonacci.php` — טהורות, נקראות רק מ-`conditions()` (info) ומ-`overlay()`
  (label). לא נוגעות ברשת. בכרטיס: שדה `info` (`lib/analysis.php` מעביר, `card.js` מציג, `data-info`).
- אם `conditions()` של שיטה אחרת תרצה מידע שאינו תנאי — אותו `info`.

## פיבונאצ'י — למידה (פרק 23.3, L1)

- `meta().learn` — `score` (ציון האזור, כולל Z6), `zone_atr` (רוחב האזור ב-ATR של הרשת שהאות נבדק מולה), `rr`. האות מצרף
  `features` אחרי ש-`x` נקבע — לא נכנס למצב. `fibonacci-api.php` בודק: שורה לכל עסקה, features, בלי כפילות בהרצה חוזרת.

## פיבונאצ'י — tip לכל פריט (18.1.6, T1 קריטריון 3)

- בממצא: `ts_fib_zone_tip` (כותרת "אזור פיבונאצ'י · ציון N", הסבר — היחסים "61.8% של 200→150"), `ts_fib_single_tip` (R8), ואקום, ואות
  (סימון / סטופ / רמת היציאה ברווח — ביטול בסטופ). בתרחיש — בתוך `ts_fib_scenario` (ביטול: X של A-B-X בכיוון, אחרת הסטופ).
- `ts_fib_px` (מחיר לטקסט), `ts_fib_fit` (עד 200 תווים — `TS_OVERLAY_TEXT_MAX`). ניסוח 6.6 — נבדק ב-`fibonacci-f3.php`.
- `chart-tip-ui.mjs` קריטריון 8 — הפריט "בלי tip" הוא אזור פיבונאצ'י שה-tip נמחק ממנו בתשובה (`route`), וקריטריון 3 — עם ה-tip האמיתי.

## L1 — מסך מלא לגובה / לרוחב (18.1.5, 2.30) — מה נבנה

- **`market.js` → `viewChart`, "מסך מלא":** `fsEnter(mode)`, `fsSetMode` (⟳), `fsLock`, `fsApply` (⟳ והסיבוב), `fsLeave`.
  - **ה-Fullscreen API על `document.documentElement`**, לא על הגרף: על האלמנט שב-Fullscreen הדפדפן כופה `transform: none !important`.
    לכן בבדיקות: `document.fullscreenElement` הוא ה-`<html>`.
  - התפריט ו-⟳ — רק כשהמסך לאורך (`innerHeight > innerWidth`) בכניסה.
  - "לרוחב": `lock('landscape')` בתוך Fullscreen; נדחה/אין — `.fs-turn`. "לגובה" מ-⟳ אחרי נעילה — `lock('portrait')`. כל יציאה — `unlock()` אם ניסינו.
- **`assets/js/rotate.js`** — `setTurned(el)`: כל עוד שכבה מסובבת, `getBoundingClientRect` ו-`clientX/Y`/`pageX/Y`/`x/y` של אירועים
  ושל Touch בתוכה — במערכת השכבה. **מחליף getters על `Element.prototype`, `MouseEvent.prototype`, `Touch.prototype`** (פעם אחת,
  ופעיל רק כשיש שכבה). מי שמוסיף קוד שמחשב מיקום על המסך בתוך הגרף (למשל `elementFromPoint` עם clientX) — לזכור את זה.
- **`lockVisibleTimeRangeOnResize: true`** ב-`base.timeScale` — כל שינוי רוחב שומר את הטווח (לא את רוחב הנר).
- **בדיקות ממשק שנכנסות למסך מלא בטלפון לאורך:** ⛶ ואז `menuitem` "לגובה" (ראו `fsEnter` ב-`chart-f1-ui.mjs`).
- **הדמיה ב-Playwright** (`chart-landscape-ui.mjs`):
  - סיבוב — CDP `Emulation.setDeviceMetricsOverride` (`setViewportSize` נכשל בתוך Fullscreen: `Browser.setWindowBounds`).
  - צילום אחרי סיבוב — CDP `Page.captureScreenshot` (`page.screenshot` מבטל את הסיבוב); בלי סיבוב — `page.screenshot` (CDP בתוך Fullscreen יוצא 800×600).
  - גרירת אצבע — CDP `Input.dispatchTouchEvent`; אחרי גרירה לחכות לגלילה הקינטית (~1.5 ש').
- **לא בפרוסה:** זום אנכי של המחיר (L1 סעיף 5).

## חובות — מה תוקן (2026-11-16)

- **`ts_journal_resolve(..., &$usedKey)`** סופר גם לפי מפתח (`"ספק|היקף"` של הדרך שענתה), כמו ההתראות. מי שמוסיף שלב ל-cron
  שפונה לספק לפני המילוי — לעדכן גם את `$used` וגם את `$usedKey`, אחרת המילוי לוקח ממפתח אישי יותר מחצי לדקה.
- **ניווט — מונה דור (`GEN`) ב-`app.js`**, לא תור (הבקרה: תור = המתנה, והבעלים שונא חיכוי). כל `route()` מתחיל מיד.
  בקשת `api()` שנשלחה בזמן שמסך נטען (`LOADING`) שייכת לדור שלו; אם התחיל ניווט חדש — התשובה **לא מגיעה** (הבטחה שלא
  מתקיימת), והמסך הישן נעצר. מכאן: מסך שמחכה למשהו שאינו `api()` לפני שהוא מצייר (למשל `fetch` ישיר, טיימר) — לא מוגן.
  להעביר דרך `api()`, או לבדוק `GEN` בעצמו.
- **שורת הלשוניות בשוק:** `aria-busy="true"` מהשחרור של גרירה עד שהסדר נשמר והכפתורים נבנו מחדש. בדיקה שגוררת — מחכה לזה.
- **בדיקות ממשק אחרי `ctx.reload()`:** המסך עובר קודם דרך "טוען…". מחכים למסך החדש (`!main .loading` + האלמנט), לא להיעלמות
  של משהו מהמסך הקודם.
- **איך מוצאים רעידה:** 6 הרצות מקבילות של אותה בדיקה (העומס חושף את המירוץ). כך שתי הרעידות שוחזרו בפעם הראשונה.

## תקלת 500 בהפעלת שיטה (16.11) — מה למדנו (ענף `claude/trading-strategies-err500`)

- **SQLite + WAL + סמן פתוח = 500 אקראי בבקשות מקבילות.** שאילתה שקראו ממנה שורה אחת ולא סגרו מחזיקה את החיבור על תמונת
  מצב ישנה; אחרי כתיבה של תהליך אחר — הכתיבה הבאה באותו חיבור נכשלת מיד ב-`database is locked`, **בלי** busy_timeout.
  עכשיו `TsStmt` (`lib/pdo.php`) סוגר אחרי `fetch` / `fetchColumn` בשני המסדים. **לכן: אין לולאת `while ($r = $st->fetch())`** —
  רשימה רק ב-`fetchAll()` או `foreach ($st as $r)`. חיבור חדש ל-SQLite — דרך `ts_sqlite_open`.
- **בדיקת מקבילות:** `php -S` בלי `PHP_CLI_SERVER_WORKERS` הוא תהליך אחד — הבקשות בתור, ותקלות כאלה לא נראות.
  לשחזור: `PHP_CLI_SERVER_WORKERS=6` + `curl_multi`. `tests/sqlite-lock.php` מדגים בלי שרת (שני חיבורים).
- **הודעת 500:** `ts_server_error` (`api.php`) — GET: "נסו שוב בעוד רגע", POST: "הפעולה לא נשמרה". ביומן (🐞 ← יומן) שורה
  `exception.context`: סוג החריגה, קובץ ושורה, ופרמטרי ה-URL (סודות → `***`).
- **ממצא צדדי, לא תוקן:** בדיקה לאחור של `h1` מושכת שנתיים (`TS_BT_RANGE`), ובחיבור איטי לספק המשיכה לבד עוברת את
  `TS_BT_MAX_SECONDS` (20 ש') → `incomplete`, לא נשמרת, ו-`analysis` הבא מושך שוב הכול (~30 ש' לבקשה). מקומית, מול Binance
  האמיתי — כך בכל ניסיון. בשרת לא נמדד. כדאי שהבקרה תחליט אם לפתוח פרוסה.

## בדיקה לאחור שלא הספיקה (16.11, ענף `claude/trading-strategies-bt-incomplete`)

- `ts_bt_history(..., $deadline)` מחזיר `[rows, calls, complete]`; עוצר רק לפני פנייה, ותמיד אחרי פנייה אחת לפחות — כך כל קריאה מתקדמת.
- `ts_backtest`: לא הושלם → `bt_partial` (מפתח: שיטה+גרסה, ספק, נכס, מסגרת, היקף, hash, וה-`to_t` של התוצאה השמורה שממנה ממשיכים).
  הבא ממשיך מ-`to_t + 1`. שיטה לא מצטברת — לא נשמר חלקי.
- **הריצה מקבלת זמן משלה** (לא מה שנשאר מהמשיכה). אחרת המשיכה גומרת את התקרה, הריצה בודקת 2000 נרות ועוצרת, והשאר נמשך שוב.
- בבדיקות: `TS_BT_MAX_SECONDS` (רק עם `TS_DEV`) ו-`TS_KLINES_DELAY_MS` ב-fixture של Binance.
- גם ריצה מלאה אחת מבקשת ~140 נרות פעמיים (חפיפה בגבולות החלונות והמאגר) — לכן הבדיקה משווה לריצה אחת, לא ל"אפס כפילויות".

## T1 — חלונית הסבר על נקודת תחזית (18.1.6)

- **`assets/js/charttip.js`** — `mountChartTip` (מחובר ב-`viewChart`), ו-`tipContent` (התוכן מפריט — טהור).
- **`tip` בחוזה ה-overlay** — שדה רשות לכל פריט: `{title, sub?, explain?, invalidation?{price, side, rule?}, links?[{label, href}]}`.
  `ts_overlay_check` בודק אותו; `href` — רק `#/…`. **שיטה חדשה — למלא `tip`** בכל פריט, אחרת החלונית מראה רק את `text`.
  - אליוט — ממלא (`lib/elliott.php`, `TS_EW_POINT_RULE`). **פיבונאצ'י — עדיין לא** (קריטריון 3 של 18.1.6 פתוח, בצ'אט שלו).
- **`forecast.js`:** `hitsAt(x, y, W)` — כל מה שעד 24px, הקטן קודם; `relocate(hit, W)` — אותו פריט אחרי ציור מחדש (מפתח בלי
  מחירים — `keyOf`); `onPaint(fn)` — נקרא בסוף כל `paint()`. מי שמוסיף סוג פריט — להוסיף אותו ל-`RANK` ול-`anchorOf`.
- **המיקום:** `offsetLeft/offsetTop` (פריסה) — עובד גם בשכבה המסובבת. גבוהה מהמקום בשני הצדדים — רק `.ctip-body` נגלל.
- **בדיקות:** `tests/chart-tip-ui.mjs` — הספירה בנרות האחרונים, בקצה; `center()` גורר את הגרף שמאלה. חלון שני עם "עכשיו"
  אחר — **משתמש אחר**, אחרת `method_colors_save` מחזיר 422 (זמן הצבע "בעתיד").
- **צילומים:** `team/shots/chart-tip/` (מוחרג מהפריסה עם כל `team/`).
## L1 — למידה עצמית: המסד, התגיות ו-backtest (פרק 23, 2.35)

- **`lib/learn.php`:** `ts_learn_tags`, `ts_learn_holiday_regions`, `ts_outcomes_write` (INSERT OR IGNORE, מחזיר כמה חדשות), `ts_outcomes($methodId, $f)` —
  תמיד שיטה אחת, ובלי `user_id`.
- **המנוע:** `ts_bt_run(m, p, candles, cost, state, deadline, $onTrade)`. בלי `$onTrade` — שום שינוי במצב. עם — `$s['atr']`, `pos.lo/hi/atr/features`,
  `pend.atr/features`. מצב ישן בלי השדות — ממשיך (ATR מתחמם מחדש, MAE/MFE מהכניסה).
- **שיטה שרוצה `features`:** `meta()['learn'] = [שם => תיאור]`, והאות מחזיר `'features' => [שם => מספר]`. לא להכניס אותן למצב השיטה.
- **L2 צריך:** cron ל-`live` (אותו `$onTrade` על נרות סגורים אחרי `to_t`), `trade` בסגירת עסקה עם `method_id` + `planned_stop`, ודילוג על
  `users.learn_off = 1`. ההגדרה בממשק — עוד לא.
- **החגים:** לבנות מחדש — `php lib/learn/holidays-build.php` (מחליף את `holidays.json`).

## L3 — לשונית "למידה" (פרק 23)

- `admin_learn&method=&version=&source=` → `counts`, `total`, `segments [{dim, value, n, enough, win_pct, expectancy_r, certainty}]`, `gaps`, `rows`, `size`.
  ‏N < 30 — שאר השדות `null`. L4/L5 צריכים לקרוא את אותן שורות — כדאי לבנות על `ts_learn_stats` / אותה שאילתה.
- המסך — `learnPanel(m)` ב-`analysis.js`. L4 (ממצאים) ו-L5 (מיקומים, הצעות) נכנסים לאותו `<details class="learn">`.
## R1 — תפקידים (פרק 24, ענף `claude/trading-strategies-roles-r1`)

- **הרשאה חדשה = שורה אחת:** `ts_require_editor($uid)` (כל העורכים) או `ts_require_chief()` (עורך ראשי). הכלל מ-24.2: משנה
  ספירה, הסתברות או גישה של אחרים — ראשי; השאר — כל העורכים. כל `admin_*` כבר עובר `ts_require_editor` ב-`api.php`.
  **הלמידה (L1)** — `"החל"` והחלטה על ממצא חריג: `ts_require_chief()`; הלשונית וההצעות: `ts_require_editor`. ולהוסיף שורה ל-`$ROWS` ב-`tests/roles-api.php`.
- **בממשק — `ME.is_chief` / `ME.is_editor`** (וב-`admin_state`: `is_chief`, `me_id`, `editors`). `is_admin` / `is_primary_admin` עדיין
  חוזרים — רק בשביל קוד שעוד לא עבר (`forecast.js` → `isAdmin` ב-`market.js`). קוד חדש — בשמות החדשים.
- **אין "מנהל" באף מחרוזת שמוצגת** — `tests/roles-api.php` (קריטריון 7) סורק את `assets/js/*.js` בלי הערות, ונכשל על "מנהל".
- **עורך ראשי ממונה** — מ-`app_roles` בלי תלות בשער (גם במנותקת). **העורך מהשער** — `users.platform_admin`; לא שורה ב-`app_roles`,
  ולא ניתן לביטול או לשינוי (422).
- **DevLoop:** `ts_dev_allow` (`lib/devtasks.php`) — לפני `ts_dev_dispatch`. פעולת dev חדשה במודול → להחליט לאיזו רשימה היא
  נכנסת (`TS_DEV_EDITOR`, `TS_DEV_USER`), אחרת היא לעורך ראשי בלבד.
- **משתמש שאינו עורך (סבב 2):** תקרה לשעה — 10 דיווחים, 30 צילומים (429); הדיווח שלו מסונן ל-title/detail/context
  (`ts_dev_input`). עורך ראשי ממונה — האסימון שלו כפוף ל-`grant_status` במנותקת, כמו העורך מהשער. הסיסמה של העורך מהשער
  לא מתאפסת מהאפליקציה.
- **משתמש עם 🐞:** אחרי דיווח המודול קורא `dev-items` → 403 שקט (בלי מסך). לא נוגע במשתמש; לשנות רק במקור (`devloop/src`).

## L4 — שינוי דפוס (פרק 23)

- `ts_learn_findings($m, $v, $source)` — טהור; כל ממצא עם `key`, `scope` (method/owner), המספרים ו-p. `ts_learn_findings_sync` — הצד (DevLoop).
- **מתי רץ:** אחרי בדיקה לאחור שהוסיפה שורות. L2 (live) — לקרוא ל-sync גם אחרי כתיבת `live`, עם `source = 'live'`.
- **L5 צריך:** ממצאים עם `scope = method` הם המקום להצעה לפלח. מיגרציה הבאה ב-`app.sqlite` — 20.

## L5 / L5b — איפה למקם: השוואת שכנים (פרק 23, 2.37)

- **שיטה שרוצה ללמוד פרמטר:** `meta()['learn_params'] = [param => ['step' => 0.25]]` או `[param => ['values' => [..]]]`. הטווח — min/max של
  הפרמטר. פרמטר "מהספר" (`ours` לא true) — רק `values`. אליוט: `w3_mult` → `[1.0, 1.618, 2.618]`.
- **פיבונאצ'י — הממשק מוכן, ההצהרה אצל קוד פיבונאצ'י** (לא נגעתי ב-`lib/methods/fibonacci.php`). מה שצריך שם, לפי 23.9:
  `'learn_params' => ['stop_atr' => ['step' => 0.25], 'box_target' => ['values' => [0.5, 0.618, 1]], 'zone_atr' => ['step' => 0.1]]`
  (ולמחוק את ה-`stat` הישנים, אם הוכנסו — הצהרה בלי step/values פשוט מדולגת). גודל הקופסה ב-features — לדיון/CSV בלבד, לא נדרש להשוואה.
- **רץ רק ב-cron:** `ts_learn_cron` (מ-`ts_alerts_run`, אחרי המילוי). הנרות — `ts_bt_history(0, …)` בשם המערכת, תקציב לדקה משותף
  (`$used`/`$budget`). זוג ממפתח אישי (`cred_scope` ≠ '') — נספר (`private_pairs`), לא רץ. אין יותר "🔍 חשב הצעות" / `admin_learn_propose`,
  ואין קריאה מתוך `ts_backtest`.
- **הסטים הקבועים:** `tests/fixtures/learn/sets.php` (widen / narrow) + שיטת `test-neighbors` (`tests/fixtures/methods/learning.php`) —
  אות לפי זמן הנר, כך שאותם אותות גם כשההרצה מתחילה ב-`t_cut`.
- **ערך לפלח:** `methods.seg_params`, נקרא ב-`ts_method_settings`, ומוחל ב-`ts_params_for_segment`. כל מקום חדש שמריץ שיטה על נכס+מסגרת —
  לעבור דרכה (היום: `ts_analysis_cards`, `chart_refresh`).
- **מיגרציה הבאה ב-`app.sqlite` — 22.**
- **עלות:** בפלח הכללי ההרצה עוברת על כל הזוגות שבשורות. `TS_LEARN_CRON_SECONDS` (90) עוצר; דחייה בגלל זמן → לסוף התור, ושלוש ברצף →
  `too_big` (`TS_LEARN_MAX_DEFERRALS`, `ts_learn_defer`) + פריט DevLoop, עד 30 שורות חדשות. `learning_values.deferred` (מיג' 21).

## תנועות מגע על הגרף (gestures.js) — מה נבנה

- `mountGestures({ chart, series: candle, box })` ב-`viewChart`. הספרייה: `vertTouchDrag: false`, `pinch: false`; הצביטה שלנו (capture על `box`, שתי אצבעות).
- **ציר הנרות הוא `candle.priceScale()`**. `chart.priceScale('right')` לא משפיע על הנרות (שני הצירים מוסתרים, ו-`priceScaleId` של הסדרה ריק) — מי שמשנה שוליים/מצב לציר המחיר: דרך הסדרה.
- `box.tsHeights()` לבדיקות: גובה הנרות הנראים, גובה הנפח, `k`, והאם האינדיקטורים על אותו ציר.
- **בדיקת מגע:** CDP `Input.dispatchTouchEvent` עם `id` לכל אצבע; גלילת הדף נמדדת כסכום `scrollY` ו-`scrollTop` של האבות. אחרי כל תנועה ~1.5 ש' (קינטי).
