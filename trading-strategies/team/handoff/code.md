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
> **עדכון 2026-11-16 (צ'אט A2):** A2 — התחברות עם אפשרות ניתוק — בנוי וממוזג (#1623). הסעיף "A2 — התחברות" בסוף. **מיגרציה הבאה — 15.** הבא בתור — לפי `team/board.md`.
> ~~הבא בתור: S3~~ (בנוי). קריאת חובה: `docs/spec-stage-a.md` 18.3, 3.5 ו-✅23 ב-`docs/owner-brief.md` 2ה.

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

- **שעון קבוע בבדיקות ממשק:** `X-Dev-Now` לשרת **וגם** `ctx.clock.setFixedTime(NOW * 1000)` לדפדפן (ראו `tests/forecast-ui.mjs`, `open()`).
  - בלי השעון בדפדפן, הבדיקה נכשלת לבד שבוע אחרי NOW.
  - **ל-S3 זה רגיש:** שעון קבוע (`setFixedTime`) לא מקדם טיימרים. לבדיקת רענון צריך `ctx.clock.install()` ו-`runFor()`, או `fastForward`.
- **`market_candles` ב-`api.php` לא מעביר `ts_now()`**, בניגוד ל-`method_overlay`. בפיתוח זה אומר שהנרות לפי הזמן האמיתי ו-`X-Dev-Now` לא חל עליהם. הבקרה רשמה את זה ("לא דחוף"). אם S3 צריך זמן קבוע בבדיקה — לתקן שם: `ts_now()` כפרמטר האחרון.
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
