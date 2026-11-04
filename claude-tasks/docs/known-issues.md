# מטלות לקלוד — בעיות ידועות, לפי נושאים

**מה זה:** פלט שלב 1 (תיקון תיעוד). סריקה של הקוד והתיעוד מול הגרסה העדכנית ב-`main` (538ba09, 2026-11-04), בשלוש סריקות נפרדות.
**מה זה לא:** לא שינוי קוד. כל פריט כאן הוא **ממצא לטיפול בשלב 3**, לפי אבני הדרך של שלב 2. תיעוד שגוי תוקן באותו PR, וסומן כאן ✅.

**איך קוראים:** כל נושא = אבן דרך אפשרית. לכל פריט: מה, איפה, רמת ביטחון (גבוהה / בינונית / נמוכה), ופעולה מוצעת.
"גבוהה" = אין שום הפניה בקוד (נבדק גם שמות דינמיים). "בינונית" = מופיע רק בבדיקות, ב-README או בהערה. "נמוכה" = החלטת מוצר או שייתכן שימוש דינמי.
מספרי שורה מתייחסים ל-`538ba09` ויזוזו עם השינויים — לחפש לפי השם.

**כלל לשלב 3:** לפני מחיקה, `git grep -w` על השם בכל התיקייה, ובדיקה ש-`tests/` עדיין עוברים. לא מוחקים פריט "נמוכה" בלי אישור של בעל הלוח.

---

## 1. גשר וצ'אטים (רישום, hooks, העירה, מנהל)

### קוד מת ושיטות ישנות
| איפה | מה | ביטחון | פעולה |
| --- | --- | --- | --- |
| `bridge/link.sh` `checkin <trigger_id>` (ענף היצירה) | יוצר בדיקה עצמית שעתית. הסקיל אוסר בדיוק את זה | גבוהה | להשאיר רק `checkin --clear` |
| `api.php` `channel-checkin`; `lib/listen.php` עמודות `next_check_at`, `check_trigger_id`; `old_check_hint` ב-`bridge/lib.sh` | שאריות הבדיקה השעתית. נחוצות רק כל עוד יש צ'אטים ישנים עם בדיקה קיימת | בינונית | לתכנן הסרה אחרי שהלוח מראה `has_check = false` בכל מקום |
| `bridge/lib.sh` `BRIDGE_RUNTIME` | מוגדר ולא נקרא; שלושה סקריפטים כותבים `$HOME/.claude-tasks-bridge` ידנית | גבוהה | למחוק או להשתמש בו בשלושתם |
| `bridge/listen.sh` שורה עם `listen_state paused;` לפני `back_off` | `back_off` דורס אותו מיד | גבוהה | למחוק |
| `bridge/listen.sh` `CLAUDE_TASKS_POLL` | משתנה סביבה לא מתועד שעוקף את הכלל "לא מקצרים את המרווח" | בינונית | למחוק (בטיחות) |
| `bridge/listen.sh` הודעות יציאה 18 ו-102: "הבדיקה העצמית בודקת את הלוח" | אין יותר בדיקה עצמית. ההודעה מבטיחה משהו שלא קיים | גבוהה | ניסוח מחדש (הערת טקסט, אין שינוי התנהגות) |
| `bridge/listen.sh` תבנית `429\|508\|5??` | 508 ו-429 כבר מכוסים | נמוכה | לפשט |
| `bridge/link.sh` `silent_register` מול `register` | שני כותבים של אותו קובץ קישור. `silent_register` **לא** כותב `project_folder`, ולכן אחרי החלפת קונטיינר `hook-gate.sh` מקבל `project_path` ריק | בינונית (סיכון התנהגות) | לאחד ל-`write_link()` אחד. **זו גם תקלה אמיתית** |
| `lib/listen.php` `LISTENERS_MODES` (`paused/hourly/on`), `LISTENERS_CAP`, ענפי `listeners_off` ו-`slots_full` ב-`listenGate`, `setListenersMode` | מתג שלושה מצבים, תקרה של 5 ומד, שנבנו לפני שהוחלט שרק המנהל מאזין | נמוכה (החלטה) | להשאיר ⏸ והמד. לפשט את השאר. הערת הביקורת §2.4 |
| `lib/throttle.php` `bridgeSetPaused` מול `lib/listen.php` `setListenersMode('paused')` | שני כותבים של `config.bridge_paused` | גבוהה | לאחד |
| `assets/js/chats.js` מצבי `checking`, `stale`, `no_check`, `old_bridge` | מייצגים את עולם הבדיקה השעתית. `wontWake` ו-`wake-all` נשענים עליהם | נמוכה | לבנות מחדש בלי מצבי checkin ואז למחוק |
| `lib/templates.php` `NOTIFY_SESSION_OLD_DEFAULT` (מזהה סשן קשיח), עמודת `notify_session`, שדה בטופס (`assets/js/newchat.js`), שורת הדפסה ב-`link.sh` | הודעת הצטרפות — לא נשלחת כברירת מחדל | בינונית | למחוק מקצה לקצה אחרי ניקוי חד-פעמי של המסד |
| `lib/bridgever.php` `BRIDGE_UPDATE_TEXT`, `bridgeSendUpdate`; `api.php` `bridge-send-update`; מסנני "עדכון הגשר ללוח (חד-פעמי)" ב-`answers.php`, `lanelog.php`, `silence.php` | כפתור "📨 שלח הוראת עדכון" הוסר מהממשק. נשארו הפונקציה, הפעולה והמסננים | בינונית | למחוק פעולה ופונקציה. את המסננים להשאיר עד שאין שורות ישנות במסד |
| `bridge/hook-notify.sh` | מוסיף הודעת "ממתין לאישור" גם כש-`hook-permission.sh` כבר יוצר כרטיס | נמוכה | לבדוק במצב `app`; אם אין כפילות — להשאיר |
| `agent/run.mjs`, `agent/finish.mjs` `BOARD_COOKIE` | עקיפת סביבה שאף workflow לא מגדיר | נמוכה | למחוק |

### כפילויות ובלבול
- `bridge/hook.sh`, `bridge/link.sh`, `bridge/update.sh` — שלוש דרכים להריץ עדכון (העתק מקומי / `origin/main` / טקסט משובץ). תקין, אבל לתעד.
- `lib/classify.php` (סיווג נושאים) ו-`bridge/classify.php` (סיווג פעולות שער) — אותו שם, שני קבצים שונים. לשקול שינוי שם.
- `.claude/settings.json`: 12 hooks שחוזרים על אותו חיפוש-נתיב ארוך; רשימת `allow` עם שני כתיבי שם לאותם שישה כלים (`mcp__claude-code-remote__…` ו-`mcp__Claude_Code_Remote__…`).
- **סיכון:** `hook-allow.sh` מתיר `create_trigger` עם `persistent_session_id`, ו-`hook-gate.sh` מסווג אותו `send-team-message`. כש-Claude מחבר החלטות, החמורה מנצחת — ייתכן שהשער "ישאל" במנהל. **לבדוק** (בצעד 3 של הנושא הזה).

### תיעוד (תוקן ✅ או נשאר)
- ✅ `spec.md` §5.1, §5.4, §5.6, §5.9.5, §5.10.1, §20, §21.6: הערות "SUPERSEDED". ✅ שורות §14 המטעות סומנו.
- ✅ `permission-timeout` → `permission-close` (`how=timeout`).
- ✅ README: בצ'אט רגיל אין מאזין; ההצטרפות; ה-`status` שהוסר.
- ✅ `claude-tasks-worker` הפסיק לומר שצ'אט רשום "מפעיל מאזין ברקע".
- נשאר: `.claude/skills/claude-tasks-live/SKILL.md` — סדר הסעיפים 1,2,3,4,6,5,7, ו"עדכון הגשר ללוח (חד-פעמי)" בסעיף 4 מיושן (הסקריפטים מעבירים לעותק הריצה לבד). **סקיל משנה התנהגות של צ'אטים, לכן לא נגעתי בו בשלב 1 בלי אישור.**
- נשאר: כותרות בקוד — `bridge/link.sh` (חסרים `--launch`, `--replaces-dispatcher`, `checkin`, `dispatch-*` ברשימת הפקודות), `lib/listen.php` שורות 1–8, `lib/answers.php` שורות 11 ו-82, `lib/bridgever.php` שורות 5–6.

---

## 2. שיחה וממשק

| איפה | מה | ביטחון | פעולה |
| --- | --- | --- | --- |
| `assets/js/app.js` `toggleBridgePause()` + `api.php` `bridge-pause` | פונקציה שאף אחד לא קורא לה. הכפתור קורא ל-`openListeners` | גבוהה | למחוק את שניהם (ואת `bridgeSetPaused` אם הבדיקות מסכימות) |
| `assets/js/core.js` `PLATFORM_HINT` | "Platform עוד לא עונה בעצמו (שלב 8)" — **שקר** מאז שלב 8 | גבוהה | למחוק את הקבוע ואת שתי השורות שמשתמשות בו (`app.js`, `platform.js`) |
| `assets/js/newchat.js` `isBoardOwner`; `assets/js/translate.js` `TR_CREDIT` | מיוצאים ולא בשימוש | גבוהה | למחוק |
| `index.html` `#pvMeta`; מזהים `tokenRow`, `catalogHint` | אלמנטים/מזהים שאף אחד לא מתייחס אליהם | גבוהה / בינונית | למחוק |
| `core.js`/`platform.js` `state.defaultProvider` | נכתב ולא נקרא | בינונית | למחוק |
| `assets/css/app.css`: `.chip-info`, `.conns-inner`, `.provider__head`, `.tr-state`, `.drow--bad`, `.badge--high`, `.badge--topic` | אין שימוש | גבוהה | למחוק |
| `assets/css/app.css`: בלוקים `:root[data-theme="dark"]` (4) | שום קוד לא קובע `data-theme`; לא ניתנים להגעה | גבוהה | למחוק; לפשט את `:not([data-theme="light"])` |
| `assets/css/app.css`: כללים כפולים (`#setTabs`, `.bubble`, `.check`, `.crumbs`, `.danger-text`, `.lane-pref .seg button`, `.nc-wait`) | אותו בורר מוגדר פעמיים | בינונית | לאחד; לבדוק קסקדה |
| `index.html` כיתות `app`, `chat-wrap`, `msg-menu` | אין להן כלל CSS | נמוכה | להסיר את התכונות |
| `assets/js/app.js` `TYPE_LABEL` | 11 סוגי אישור בלבד; חסרים `push-protected`, `change-gate`, `run-remote-script`, `engine-failover`, `other` — מוצג המפתח הגולמי | נמוכה | לקרוא את התוויות מהשרת (`approval-types`) |
| `index.html` טקסט "צ'אט נרשם בעצמו עם הסקיל… באסימון מ-⚙" | מרמז על רישום עצמאי בלי הפרומפט של הלוח | נמוכה | ניסוח מחדש |
| מצבים בשם `hourly` (תווית "💤 כבויים") | שם ישן למשמעות "כבוי" | נמוכה | לשנות ל-`off` בשרת וב-JS יחד |
| `assets/js/*.js` `export` מיותרים (`EFFORT_LABEL`, `shortModel`, `DECISION_LABEL`, `LANE_ICON`, `parseMarkdown`…) | מיוצאים ומשמשים רק בתוך הקובץ | נמוכה | קוסמטי |
| `service-worker.js` `SHELL`, `CACHE_VERSION = 'claude-tasks-v1'` | מונה 3 מתוך 18 קבצי JS; הגרסה לא השתנתה | בינונית | להוסיף את החסרים או להפסיק לטעון שהרשימה מלאה |

### כפילויות
- **העתקה ללוח** — ששה עותקים של `navigator.clipboard.writeText` (`app.js`, `chats.js` ×1, `launch.js`, `newchat.js` ×2, `platform.js`) → פונקציה אחת ב-`core.js`.
- **"לפני כמה זמן"** — `core.js` `timeAgo`, `platform.js` `ago`, `chats.js` `ago`.
- **HH:MM** — `chats.js` `hm`, `permissions.js` `hhmm`, `policy.js` `time0`.
- **בורר מסלול** — `app.js` `renderRouteBar` ו-`platform.js` `renderRouteSheet`, ושתי מפות תוויות (`ROUTE_NAME`, `ROUTE_LABEL`).

---

## 3. מסלולים ו-Platform

| איפה | מה | ביטחון | פעולה |
| --- | --- | --- | --- |
| `lib/monitor.php` `monitorLazy`, `monitorAfterEvent`; `api.php` `monitor-lazy`, `monitor-tick`; `MONITOR_STALE`, `MONITOR_MIN_GAP` | ניטור אוטומטי שמבזבז קרדיט Platform בכל פתיחת לוח אחרי שעה. הביקורת §4 #5: רק לפי דרישה | נמוכה (החלטה) | להחליף בכפתור "📝 סיכום"; העמודות נשארות |
| `lib/answers.php` `PLATFORM_RELAY_PREFIX` | קבוע שרק בדיקה משתמשת בו. המסנן ב-`chats.js` הוא regex | בינונית | למחוק את הקבוע |
| `lib/answers.php`, `lib/config.php` `aiConn`/`aiStatus` (מפתח מערכת כללי) | שכבת מפתח כללי לצד חיבורים אישיים; הממשק עדיין מציג "מפתח API כללי" | נמוכה (החלטה) | להחליט אם נשארת |
| `lib/silence.php`, `spec.md` §15.4 | הגדרת "שתיקה" = הודעה לא נענתה **ו**הצ'אט לא מאזין. מאחר שצ'אט ישן הוא המצב הרגיל, הכרטיס יקפוץ על כל צ'אט ישן | בינונית | **לתקן** (ש4 בדוח הביקורת): "שתיקה" = הועיר ולא ענה. ✅ הערה ב-§15.4 |
| `spec.md` §15.4 (a) | שדה `limit` מ-`rate_limit_info` — **לא נבנה** | – | ✅ סומן |
| `lib/engine.php` שני מנועים (`run.mjs` מול `claude-code.yml`) | מכוון, אבל שני משטחי תחזוקה. המנוע לא הותקן ולא רץ אף פעם בריפו הזה | נמוכה | החלטת מוצר |
| `lib/config.php` `aiKey()`, `aiModel()` | רק `tests/catalog.php` | בינונית | למחוק; הבדיקות ישתמשו ב-`aiConn()` |

---

## 4. חיבורים ומפתחות

| איפה | מה | ביטחון | פעולה |
| --- | --- | --- | --- |
| `api.php` `connect-provider`, `disconnect-provider`, `set-default-provider`, `test-provider`, `connect-github`, `disconnect-github` + `setUserProvider`, `removeUserProvider`, `setDefaultProvider` (`lib/projects.php`) | שכבת תאימות לממשק הישן. הממשק עבר ל-`connection-*` | גבוהה (README בלבד מציין אותם) | למחוק; לקצץ שלושה בלוקים ב-`tests/platform.php` |
| `lib/projects.php` `setUserSecret`, `userSecret` | נשארו מעידן האסימונים ב-`users` | גבוהה | למחוק |
| `users.github_token`, `github_login`, `github_scopes`, `default_provider`; טבלאות `user_providers`, `meta` (רק מפתח `connections_migrated`) | נקראים רק על ידי ההגירה החד-פעמית ו-`mergeInto` | בינונית | **אחרי** אישור שהדגל `connections_migrated` דלוק בייצור — למחוק עמודות, הגירה ושורות `mergeInto` |
| `api.php` `connection-effort`, `project-launcher`; `lib/launcher.php` `setProjectLauncher` | אין קורא. הממשק משתמש ב-`connection-update` ו-`launcher-save` | גבוהה | למחוק את שתי הפעולות |
| `api.php` `ai-status`, `me`, `release`, `repo-write`, `policy-revoke` | אין קורא (README בלבד) | גבוהה/בינונית | למחוק; ✅ אין תיעוד לתקן מלבד README |
| `lib/auth.php` שדות `github_login`, `github_scopes`, `default_provider`, `platform_linked` ב-`currentUser` | אף JS לא קורא | בינונית | לקצץ מה-SELECT |
| README: "מחליף על 401/403/429/5xx, לא על 400" | 400 עם הודעת אשראי **כן** עובר (`credit_exhausted`) | – | לתקן את ה-README (נשאר) |

---

## 5. מכסה, שימוש ותקרות

הסריקות לא מצאו קוד מת מובהק בנושא הזה — רק פריטים קטנים:
- `lib/db.php` `statusCounts()`, קבועים `PICKABLE`, `TOPIC_SOURCES` — אין שימוש (גבוהה).
- `lib/listen.php` `CHECK_STALE_SECONDS`, `lib/permissions.php` `PERMISSION_POLL_EVERY` — קבועים ללא שימוש (גבוהה).
- `spec.md` §21.2 #6 טען "30 שנ' בין העירות" — **לא קיים בקוד** (רק 10 דק' לערוץ ו-20 בשעה). ✅ תוקן.
- נבדקו ותואמים לקוד: הערכת 80%/100%, ניטור עד 20%, 402 ב-`run-task`, 600 שנ' לחיבור שנכשל, מגבלות משיק (10 לשעה, 30 דק'), מגבלות העירה.

---

## 6. הרשאות ושער

| איפה | מה | ביטחון | פעולה |
| --- | --- | --- | --- |
| `spec.md` §5.13.1 שורת "לא רשום ולא מפוקח" | טענה: חוסמים כל סוג ממופה שאינו `always`. בפועל `GATE_UNREGISTERED_BLOCK` הוא **תת-קבוצה** (14.1) | – | להתאים את הטבלה (נשאר; יש הערה ב-§5.13.5) |
| `spec.md` §5.13.4 "Done when": "עם `delete-files: always`, `rm` רץ" | `delete-files` הוא `always:'no'` בקוד — הבדיקה לא יכולה לעבור | – | להחליף ב-`push-own-branch` (נשאר) |
| `spec.md` §5.12.1: סקר כל 10 שנ' | בקוד 8 שנ' (מונה נפרד ב-`lib/permissions.php` / `lib/throttle.php`) | – | לתקן ל-8 (נשאר) |
| `claude-tasks-worker` רשימת 11 סוגי אישור | חסרים `push-protected`, `change-gate`, `run-remote-script`, `engine-failover` | – | להוסיף (נשאר; סקיל) |
| `lib/approvals.php` | נבדק: קטלוג הסוגים תואם לשער | – | – |

---

## 7. פרויקטים, מטלות ושלבים

| איפה | מה | ביטחון | פעולה |
| --- | --- | --- | --- |
| `lib/db.php` `claimNextTask` פרמטר `$skipProjectIds` + `lib/projects.php` `ownerOnlyProjectIds()` | הייצור תמיד מעביר `[]`. ✅ ה-README תוקן | בינונית | למחוק פרמטר, בלוק ופונקציה |
| `tasks.run_url` | נכתב ב-`api.php` (`start`) ואף אחד לא קורא | גבוהה | להפסיק לקבל את השדה ולמחוק עמודה |
| ארבע עמודות בכתיבה בלבד: `dispatch_requests.requested_by`, `dispatch_requests.done_at`, `message_pins.pinned_by`, `channels.silence_card_id` | נכתבות ולא נקראות | גבוהה / בינונית | למחוק (`silence_card_id` — יחד עם `tests/lanes16.php`) |
| `lib/projects.php` ו-10 מקומות נוספים: לולאה ידנית "עלה במעלה `parent_id`" (תקרות 8 ו-16) | כפילות; תקרות שונות עלולות להתנהג שונה | בינונית | פונקציה אחת `projectAncestors()` |
| `lib/*.php` 17 קבצים: `implode(',', array_fill(0, count($ids), '?'))` | כפילות | נמוכה | פונקציה `inList()` |
| `lib/classify.php` `stemWord`, `wordForms`; `lib/monitor.php` `questionWords` | שלושה מפרקי מילים | נמוכה | לאחד |
| `lib/auth.php`, `lib/platform.php` | אותה מחרוזת bcrypt דמה פעמיים | נמוכה | קבוע אחד |
| `lib/channels.php` `channelByToken()` | אין שימוש | גבוהה | למחוק |
| README: טבלת מצבי מטלה | חסר `awaiting_approval` | – | ✅ להוסיף (נשאר) |
| README: מספר הפעולות והבדיקות | "9 פעולות גשר, 13 קבצי בדיקה" — בפועל כ-60 פעולות `channel-*` ו-36 קבצי בדיקה | – | לעדכן או להסיר את המספרים (נשאר) |
| `function_exists('channelListen'/'wakeInfo'/'autoWake'/'isDispatcherChannel'/'meterBump')` (6 מקומות) | `db.php` טוען את כל המודולים; התנאים תמיד אמת | גבוהה | להסיר את הבדיקות |

---

## 8. מנוע הפיתוח

- `agent/workflow.yml` + `run.mjs` מול `agent/claude-code.yml` + `finish.mjs`: מכוון (`engine.php`), אבל כל אחד הוא משטח תחזוקה נפרד. **שלב 22ג (Platform מפתח) לא נבנה**, והמנוע לא הותקן ב-`mini_projects`.
- `spec.md` §18.4 ו-§18.5 מתארים תכנון בלשון הווה. ✅ סומנו "NOT BUILT".
- אין להסיר את נתיב `run-task`: הכפתור "הרצה בגיטהאב" **פעיל** בממשק (נבדק מחדש ב-`origin/main`).

---

## 9. תרגום, חיפוש ותוויות

תקין ולא שורף טוקנים. פריטי תיעוד בלבד:
- `spec.md` §5.11.1: הטבלה מדברת על he↔en (80–100MB, CDN של Hugging Face). **בפועל:** en→he ו-en→es, כ-147MB, מוגש מ-`models/` שלנו. **הטבלה לא נערכה** (נשאר).
- `spec.md` §5.9.2: "לבדוק אם `claude.ai/code` מקבל `?q=`" — התוצאה ידועה מ-`newchat.js`: לא נתמך (נשאר).

---

## 10. הגדרות ומסכים

- `spec.md` §10: "Usage · Connections · Account (+Users)" — בפועל שישה טאבים (`index.html`: usage, conn, lanes, me, users, sys). נשאר.
- `platform/README.md` (שורות על claude-tasks): "רק health.php ציבורי" ו"שומר סיסמה משלו" — `app.json` מגדיר `gate:true` ו-SSO. **זה קובץ בפרויקט אחר** — לא נגעתי (כלל CLAUDE.md: צ'אט לא נוגע בפרויקט אחר). לדווח לבעל הלוח.
- `lib/templates.php` ו-`lib/answers.php` הערות ראש מיושנות. נשאר (הערות בקוד).

---

## 11. תיעוד שנשאר לתקן (מרוכז)

מתוך ממצאי הסחף שנבדקו (כ-46), תוקנו הפריטים שסומנו ✅ למעלה: הערות "SUPERSEDED" / "NOT BUILT", מספר ה-PR של שלב 25, שם הפעולה `permission-close`, `silence_minutes`, ברירת המחדל של `long_poll_max`, "30 שנ'" ב-§21.2, והפסקאות ב-README. נשארו:
1. `spec.md` §5.7, §5.2 (סוגי הודעה — 12 בקוד), §15.6 (חסר `platform_down`), §5.13.1/5.13.4/5.12.1, §10, §5.11.1, §5.9.2.
2. `spec.md` §14: שורות 2 ו-3 חסרות "(היסטורי)"; סדר השורות 22א/23/24א/25/ש1 הפוך.
3. `spec.md` הוראות "סדר" ו"stage 16 waits" ב-§18/§19/§20/§21: שלב 16 כבר מוזג. כל אלה בתוך §20 שסומן; ב-§18.2#8, §19.5, §21.5 נשאר.
4. הסקילים והכותרות בקוד — ראו נושא 1.
5. `cleanup-plan.md` — נסגר (לא מוזג); ✅ אין מה לתקן.
6. `translate-quality.md` — להוסיף תאריך ("תמונת מצב").

**מה לא נבדק:** סימוני `known-issues` מבוססים על grep ועל קריאה. לא הרצתי את הבדיקות ולא את האפליקציה.
