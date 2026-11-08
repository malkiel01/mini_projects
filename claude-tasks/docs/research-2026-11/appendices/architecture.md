# claude-tasks — ארכיטקטורה נוכחית ומחזור חיים
תאריך מחקר: 2026-11-08 · בסיס: עץ העבודה ב-/workspace/mini_projects (HEAD 5893e4c). קריאה בלבד; שום סקריפט לא הורץ.
נתיבים יחסיים ל-`claude-tasks/` אלא אם צוין אחרת. תגיות: [מאומת] [הסקה] [השערה] [לא ידוע].

## 0. מילון — שש מילים, שמונה דברים (מקור הבלבול)
| מונח בשיח | מה זה בקוד | טבלה/זהות | הערה |
|---|---|---|---|
| project (פרויקט) | עץ של ריפו/תיקיות; יחידת הרשאה, מסלול, תבנית | `projects` (db.php:112-127), `parent_id` | [מאומת] |
| task (מטלה) | יחידת עבודה בתור, מצבים open/in_progress/blocked/answered/done/awaiting_approval | `tasks` (db.php:63), `claimNextTask` db.php:362 | מנגנון **ראשון**, קדם לגשר |
| topic (נושא) | קטלוג ישן של מטלות | `topics` | נשאר לצד projects [מאומת] |
| channel (ערוץ) | "שיחה חיה עם צ'אט אחד" — שורה לכל **session_id** (UNIQUE) | `channels` db.php:231-244; channels.php:3-9 | מכונה בממשק "צ'אט" |
| chat / session | תהליך Claude Code בענן; זהות = `CLAUDE_CODE_REMOTE_SESSION_ID` | bridge/lib.sh `session_id()` | הלוח לא שולט בו, רק מזהה [מאומת] |
| message | שורה ב-`messages`, direction in (לוח←צ'אט) / out (צ'אט←לוח) | channels.php:30-34 | אותה טבלה נושאת גם כרטיסי הרשאה, שאלות בחירה, lane_card, ai_card (kinds) |
| agent / worker | אסימון עובד (`X-Worker-Token`) — cron/Actions/סשן שמושך מטלות | api.php:96, `workerScope()` | **אותו** אסימון-עובד משמש כרישום ערוצים: `channel-register` דורש `$worker` (api.php:1523) |
| lane / route | 💬 chat / ☁️ platform (קריאת Messages API ע"י השרת) | routes.php:76 `channelRoute` | ערוץ גובר על פרויקט; + `lane_decision` זמני |
| dispatcher (מנהל) | ערוץ בתפקיד `dispatcher`, מאזין יחיד | dispatch.php, טבלה `dispatcher` (id=1) | [מאומת] |

מסקנה [מאומת]: "task" ו-"channel" הם שני תור/שיחה נפרדים שמתחברים רק דרך `tasks.asked_channel_id` (api.php:1298-1318 `block`, channels.php:435-451). "שיחה" אינה ישות — היא `channel`, ומחזור חייה נקבע ע"י `status`(active/archived/replaced) + שדות מצב צדדיים (`listening_until`, `listen_state`, `unresponsive_since`, `lane_decision`, `limit_status`, `waiting_at`, `check_trigger_id`…) — מצב מפוזר על ≥10 עמודות במקום מכונת מצבים אחת. [הסקה]

## 1. מפת רכיבים (נוכחי)
```mermaid
flowchart LR
  subgraph Browser["אפליקציית הדפדפן (assets/js)"]
    UI[chats.js: poll 4s\napp.js: refresh 20s]
  end
  subgraph Server["api.php + lib/* (PHP, SQLite WAL)"]
    API[api.php switch(action)]
    DB[(data/tasks.sqlite\nusers,projects,tasks,channels,messages,\nchannel_state,dispatcher,dispatch_requests,events)]
    FILES[(data/bell/<ch>\ndata/throttle/*)]
    SIL[silence.php: גלאי עצל]
    LANE[lanes/answers: Platform reply]
    DSP[dispatch.php]
  end
  subgraph Chat["סשן Claude Code (ענן)"]
    HK[hooks: UserPromptSubmit/Stop/\nPreToolUse gate+choice/PermissionRequest…]
    LNK[link.sh pending/ack/register]
    LST[listen.sh - רק אם התבקש]
    LINKF[(.claude/board-link.json\nטוקן ערוץ, pending_reply_to,\npending_choice, backoff)]
  end
  subgraph Disp["סשן 'מנהל'"]
    DL[listen.sh + dispatch-pending\n+ create_trigger]
  end
  Platform[(Platform: Messages API\nמפתח המשלם)]
  UI -->|channel-send| API
  UI -->|platform-reply| API
  API --> DB
  API --> FILES
  HK -->|channel-post out| API
  LNK -->|channel-inbox / channel-ack| API
  LST -->|channel-wait כל 30±10s| API
  API -->|ringBell| FILES
  UI -. channels פותח .-> SIL --> LANE --> Platform
  API -->|queueWake→ringBell(dispatcher)| DSP
  DL -->|channel-wait, dispatch-pending| API
  DL -->|create_trigger(persistent_session_id)| Chat
```
מצב בפועל ב-2026-11-08: **החצים של hooks/listen/dispatch כבויים** (§3). נשארו: UI↔API↔DB, ו-Platform reply מהדפדפן.

## 2. מחזורי חיים — צעד אחר צעד (עם קוד)

### 2.1 יצירת פרויקט/מטלה
פרויקט נוצר ב-api.php (projects.php) עם `agent_token` משלו (db.php:122-124 הערה "נשלח לתוך הריפו כסוד"). מטלה: `create-task`; נלקחת ע"י `claim` → `claimNextTask` (db.php:362-421) בטרנזקציה, `UPDATE … WHERE status IN ('answered','open')` ובדיקת `rowCount` — **בטוח לתחרות** [מאומת]. נעילה 30 דק' (`CLAIM_MINUTES`, db.php:19), `heartbeat` מאריך (api.php:1510), `releaseExpiredClaims` db.php:341 משחרר. שחרור מתבצע **עצלנית** (ראה 5.x) — ראה F-19.

### 2.2 יצירת שיחה (channel) ורישום
1. **מהלוח "➕ צ'אט חדש"** (launcher.php): נוצרת רשומת launch `L-…` ← הפרומפט כולל `link.sh register --launch`. [מאומת: link.sh:5,95,100-102; api.php:1531-1534]
2. הסשן מריץ `link.sh register` (link.sh:72-128): דורש `CLAUDE_TASKS_TOKEN` (אסימון הרשמה **משותף לכל הסביבה/שורש**), `--project` מהפרומפט, `session_id` מהסביבה. → `channel-register` → `registerChannel` (channels.php:121-183):
   - אותו `session_id` ⇒ אותה שורה, `token_hash` חדש, הישן פסול מיידית (שורות 145-157).
   - חדש ⇒ INSERT עם `route = preferredLane(project)`.
   - מחזיר `channel_token` (פעם אחת), נכתב ל-`.claude/board-link.json` (link.sh:116-122, chmod 600).
3. אחרי החלפת קונטיינר: `silent_register` (link.sh:53-68) — רישום מחדש **בלי** `project_folder` (ראה F-12).
4. מנהל: `role=dispatcher` ⇒ `dispatcherPrecheck/Claim` (api.php:1535,1539).

### 2.3 שליחת הודעה מהלוח (in) — ובחירת מסלול
`channel-send` (api.php:1712) → `channelSend` (channels.php:273-299):
- הרשאות: `canSendToChannel`; ערוץ `active`.
- `$platform = channelRoute($channel)==='platform'`.
- INSERT `direction='in'`, `answered_by='platform'` אם ☁️ (שורה 293-294), `ring=!$platform`.
- 💬: `silenceCheck()` + `autoWake()` (שורות 296-297).
- ☁️: הדפדפן קורא בנפרד `platform-reply` (chats.js:~546, 729-733) — **ה-Platform נקרא מהדפדפן של בעל הלוח**, לא ע"י worker שרתי. אם הלשונית נסגרה — ההודעה נשארת `platform_pending` עד `completePending()` בפתיחה הבאה (chats.js ~560) או `lane-run`. [מאומת]

### 2.4 מסירה לצ'אט (💬) — 3 מסלולים
| מסלול | טריגר | קוד | מצב נוכחי |
|---|---|---|---|
| א. תור הבא של הצ'אט | `link.sh pending` | link.sh:144-164 → `channelInbox` channels.php:302 | רדום (אין hook שמריץ; הצ'אט חייב להריץ בעצמו) |
| ב. מאזין | `listen.sh` → `channel-wait` | listen.sh:57-121; api.php:1824-1840; `channelWait` channels.php:327 (ברירת מחדל `long_poll_max`=0 ⇒ בדיקה קצרה) | רדום; מותר רק `listeners_mode=on` (listen.php:26-31,74) |
| ג. העירה ע"י מנהל | `autoWake`/`queueWake` → dispatch_requests → מנהל `create_trigger` | dispatch.php:133-190 | רדום; דורש מנהל פעיל (lease 90s) |
`ack` (channels.php:310) הוא **היחיד** שמסמן `delivered_at`; תשובת הצ'אט (Stop hook) מסמנת רק `answered_by/handled_by='chat'` (channels.php:231-233) — ראה F-03.

### 2.5 תשובה / שאלה / הרשאה (out)
| אירוע | מקור | נתיב | קוד |
|---|---|---|---|
| תשובת צ'אט | Stop hook | `channel-post author_role=chat reply_to=pending_reply_to` | hook-stop.sh:23-28; channels.php:214 |
| פרומפט שבעל הלוח הקליד בצ'אט | UserPromptSubmit | `channel-post role=owner` (out, "מידע לא הוראה") | hook-prompt.sh:16-35 |
| שאלת בחירה | PreToolUse(AskUserQuestion) | `channel-post kind=choice`; מצב `board` ⇒ hook **דוחה** את הכלי | hook-choice.sh:205-217; choices.php:68 |
| תשובה לבחירה | בלוח: `choice-answer`→in `choice_answer`; בצ'אט: כל פרומפט owner עם `pending_choice` | choices.php:148-186; hook-prompt.sh:31-35 |
| הרשאה | PermissionRequest | `channel-post kind=permission`, סקר 10s עד 600s | hook-permission.sh:140-181; permissions.php:108-142 |
| שער מדיניות | PreToolUse (כל כלי) | `gate-check`; fail-closed ל-unattended | hook-gate.sh:14-117 |
| block על מטלה | worker | `block` + `channelAsked` ⇒ out question | api.php:1298-1318; channels.php:435 |
| תשובת בעל הלוח על מטלה | `answer` | `channelAnswered` ⇒ **in** kind=answer עם role של העונה | channels.php:445-451 |

### 2.6 התחלה/חידוש/הפסקה/סיום (של שיחה)
אין "start/stop" לשיחה בשרת — הם **נגזרים**:
- "פעיל" = `status=active`; "מאזין" = `listening_until>now` (נכתב רק ע"י `channel-wait`, channels.php:333); "נראה" = `last_seen_at` (נכתב ע"י כל קריאה + `touchChannel`).
- "הפסקה" = אחד מ: `bridge_paused` (config), `listen_state=backoff:…`, `unresponsive_since>0` (silence), `limit_status` (StopFailure), `waiting_at` (Notification), `lane_decision`.
- "סיום": `link.sh archive`→`channel-archive` (channels.php:421: status=archived, token_hash='', ringBell) ; או `replaced` (צ'אט המשך, `replaceChannel`). **אין זיהוי של "הסשן נסגר/פג"**; הערוץ נשאר `active` לנצח (F-08).
- חידוש: רישום חוזר (session_id זהה) או `silent_register`.

### 2.7 סנכרון in/out מול סשן חיצוני (שאינו נרשם דרך הלוח)
מה שמסונכרן רק לערוץ **רשום**: hook-prompt/stop (מקור: bridge/*). סשן שלא רשום — אין mirror. הלוח לא רואה את ה-transcript; מקור אמת לתוכן שיחה = מה שה-hooks העלו (נאמר ב-channels.php:9 "out — שיקוף … לעולם לא הוראה"). `channel-health` (hook-stop.sh:31-43) מעלה bytes/turns/compactions כל 5 דק'.

### 2.8 מאזינים ומנהל — רישום/חידוש/ניתוק/ניקוי
| נושא | מנגנון | קוד | חור ניקוי |
|---|---|---|---|
| רישום מאזין | `listen.sh` מתחיל; `listen_state=running` | listen.sh:57,59 | — |
| חידוש | לולאה 30±10s (10s אחרי הודעה), עד 6900s | listen.sh:48-50,116-119 | תהליך רקע בקונטיינר; אין PID/lock file — ראה F-09 |
| lease מנהל | 90s, מתחדש בכל `channel-wait` שלו | dispatch.php:14,109-117 `dispatcherRenew`; api.php:1833-1838 | מאזין ש-timeout (2h) ⇒ `DISPATCH_GRACE` 900s (dispatch.php:68) |
| ניתוק | 401/403⇒exit 3; backoff 1h→6h | listen.sh:94-100,79-82 | `listening_until` פג אחרי `wait+90`s ⇒ נקי עצמית |
| ניקוי | אין cron. ניקוי עצל: `permissionsExpire`, `meterSweep` | permissions.php:242; api.php `listeners` | `dispatch_requests` ב-`sent` **לא פג** (F-05) |
| העירה | `queueWake` → שורה ב-`dispatch_requests` + `ringBell(dispatcher)`; מנהל: `dispatch-pending` מסמן `sent` **לפני** `create_trigger` | dispatch.php:133-190, 197-216 | — |
| leases | claim מטלה 30m; platform lease 600s להודעה (`laneMoveToPlatform`); `platform-busy` 180s | db.php:19; silence.php:19,301; answers.php:16 | — |

## 3. מצב ההפעלה בפועל (נתיב "מושהה")
- `.claude/settings.json` (שורש הריפו): **רק `permissions.allow`**, בלי `hooks` [מאומת, .claude/settings.json:1-16].
- `bridge/hook.sh:11-13`: יוצא מיד אלא אם `CLAUDE_TASKS_BRIDGE=on` [מאומת].
- SKILL `claude-tasks-live` שורות 8-11: אסור להירשם/להאזין.
- **אבל:** (א) ענפים ישנים/עותק-ריצה ישן ב-`~/.claude-tasks-bridge` עדיין עשויים לרשום hooks [הסקה, מההערה ב-hook.sh:8-10]; (ב) `allow` ל-`create_trigger`/`send_later`/`list_sessions` נשאר לכל צ'אט בריפו, לא רק לגשר; (ג) `.github/workflows/deploy.yml` עדיין מפרסם את claude-tasks ל-ייצור (app.json `gate:true`, `public:["api.php",…]`) [מאומת app.json] — ה-API חי ושדה ה-`channel-register` נגיש לאסימון הרשמה; (ד) מצב הנתונים בייצור [לא ידוע] (`data/` ריק בריפו).
- היסטוריית git של `settings.json` רדודה: הקובץ "נוצר" ב-6512610 (קומיט שכותרתו על מחיקת ענפי מסחר) — אי אפשר לשחזר מתי הוסרו ה-hooks [מאומת מ-git show].

## 4. טבלת מצבים (רכיב ← מצב)
מקרא: פעיל · ממומש-רדום · חלקי · מאופיין-לא-ממומש · מיושן-סותר · לא-ידוע.

| רכיב | מצב | קבצים | הערות |
|---|---|---|---|
| UI ↔ API: פרויקטים/מטלות/ערוצים/הודעות, poll 4s/20s | **פעיל** (בכפוף לפריסת ייצור [לא ידוע]) | app.js:1533; chats.js:17,695; api.php | — |
| תור מטלות worker (claim/heartbeat/block/complete) | **ממומש-רדום** (אין worker מוגדר) | db.php:362; api.php:1298,1460,1492; skills/claude-tasks-worker | claim אטומי [מאומת] |
| Platform עונה (☁️) מהדפדפן | **פעיל**-תנאי (דורש חיבור ספק+מכסה) | answers.php:194; chats.js:546 | נקרא מהדפדפן |
| Hooks גשר (prompt/stop/choice/permission/gate…) | **ממומש-רדום** (כבוי ב-settings + hook.sh) | bridge/hook*.sh | F-10 |
| `link.sh register/pending/ack/status/update` | ממומש-רדום | link.sh | — |
| `listen.sh` מאזין בצ'אט רגיל | **מיושן-סותר** (נאסר ע"י audit §2.4, ש1; מתג ברירת מחדל 'hourly'=כבוי) אך הקוד וה-UI מלאים | listen.sh; listen.php:26-31 | known-issues §1 |
| בדיקה שעתית (`check_trigger_id`, `next_check_at`, `checkin`) | **מיושן-סותר** — שאריות | link.sh:204-219; listen.php:21-22,132-139; lib.sh `old_check_hint` | מסומן להסרה |
| `listen.sh` הודעות יציאה 18,102 "הבדיקה העצמית בודקת" | **מיושן-סותר** | listen.sh:18,98,102 | טקסט מבטיח מה שלא קיים |
| צ'אט מנהל (dispatcher, lease, dispatch_requests, wake) | **חלקי/ממומש-רדום**: נבנה (#1249), לא הוכח מקצה לקצה [השערה — audit §2.4 "מחייב בדיקה"] | dispatch.php; link.sh:222-246; api.php:1842-1871 | F-04,05 |
| המסך "🔗 חיבור לחשבון Claude" | **מאופיין-לא-ממומש** | audit §5, §6 ש3 | הוחלף בגיבוי מנהל |
| ש4 שתיקה = "הוער ולא ענה" | **מאופיין-לא-ממומש**; המימוש הנוכחי "לא מאזין ויש הודעה" | silence.php:241-288; audit §2.5 | F-02 |
| ש5 (Platform קורא קוד), ש6 (שימוש מנוי), ש7 (Platform מפתח), ש8 (חיווי "עובד…"), ש9 | **מאופיין-לא-ממומש** | audit §6 | — |
| ניטור אוטומטי (`monitorLazy`) | **ממומש, מיושן-סותר** (שורף קרדיט בפתיחה) | monitor.php | known-issues §3 |
| מנוע פיתוח (run.mjs / claude-code.yml) | **ממומש-רדום**, "לא הותקן ולא רץ" | agent/*, engine.php | known-issues §8 |
| בלמים: bridge_paused, תקציב 10s/טוקן, מד | **פעיל בקוד** (לפני auth) | throttle.php:17-75 | — |
| שער מדיניות/אישורים (gate-check, permission*) | **ממומש-רדום** | gate.php, permissions.php, hook-gate.sh | F-11 |
| תרגום/חיפוש/תוויות | פעיל, צד-לקוח | translate*.js | לא נבדק לעומק |
| `bridge-send-update` + `BRIDGE_UPDATE_TEXT` | **מיושן-סותר** (כפתור הוסר, פעולה נשארה) | bridgever.php:20; api.php:1630 | — |
| מצב ייצור (נתונים, מי רשום, מנהל פעיל?) | **לא-ידוע** | — | אסור לגשת |

## 5. מכונות מצבים כפי שהן ממומשות (פרשנות idle/finished/blocked/interrupted/terminated)
אין מצבים כאלה לערוץ. מה שיש, בפועל:
| המילה בשיח | ההגדרה בקוד | הבעיה |
|---|---|---|
| idle/ישן | `listening_until<=now` וללא `last_seen` טרי; בממשק: `old_bridge`/`no_check`/`stale`/`checking` (listen.php:124-140) | מצב הכי נפוץ (ברירת מחדל) מסומן "ישן/לא יתעורר לבד" |
| finished | אין. `channel-archive` ידני | סשן שהסתיים נשאר active |
| blocked (מטלה) | `tasks.status='blocked'` (api.php:1306) | **שונה** מ-"chat לא עונה" (`unresponsive_since`) ומ-"ממתין לאישור" (`waiting_at`) ומ-`limit_status` — ארבע משמעויות של "תקוע" ב-4 מקומות |
| interrupted | `StopFailure` hook → `channel-limit` רק לשגיאות LIMIT_ERRORS (silence.php:25,419) | עומס/שגיאת שרת לא נרשם; "השתיקה תתפוס" |
| terminated | אין הבדלה בין `/exit`, פג-תוקף קונטיינר, קריסה | נראה כ-idle |

## 6. תרשים רצף — הודעה לצ'אט ישן (💬, מנהל פעיל) [תיאורטי, כרגע רדום]
```
בעל הלוח ─channel-send→ messages(in, delivered NULL) ─silenceCheck(עצל)─┐
                         │ autoWake→queueWake→dispatch_requests(queued)  │
                         └ringBell(dispatcher)                            │
מנהל.listen.sh ─channel-wait(30s)→ {dispatch:1} exit0 → מודל מופעל (תור!)
 → link.sh dispatch-pending → status=sent  → create_trigger(persistent_session_id)
 → dispatch-done ok
צ'אט ישן: מתעורר בטקסט "📥 בדיקת לוח…" → UserPromptSubmit hook-prompt:
   הטקסט לא מסונן ("<" / "📥 הודעה מהלוח") ⇒ משוקף כ-owner/out   ← F-07
 → link.sh update; pending → מטפל → Stop hook ⇒ channel-post(chat, reply_to=pending_reply_to)
 → ack (ידני) ⇒ delivered_at.   ללא ack: ראה F-03
במקביל אחרי N דק' (silence_minutes, ברירת מחדל 10) בלי ack ⇒ silenceCheck ⇒ כרטיס/העברה ל-☁️ ← F-02
```
