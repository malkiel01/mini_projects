# inventory — מחסנים, תהליכים ומנגנונים (claude-tasks)
2026-11-08 · קריאה בלבד · נתיבים יחסיים ל-`claude-tasks/` · מצבים כמו ב-architecture.md §4.

## A. מחסני נתונים (מי כותב / מי קורא / ניקוי)
| מחסן | תוכן | כותבים | ניקוי/תפוגה | הערה |
|---|---|---|---|---|
| `data/tasks.sqlite` (WAL, busy_timeout 5000) db.php:23-40 | users, projects, tasks, notes, topics, channels, messages, channel_state, dispatcher, dispatch_requests, events, config, skills, login_locks, … | api.php (אדם/עובד/ערוץ) | אין cron; עצלני בלבד | **לא קיים בריפו** (`data/` ריק — `.gitignore`,`.htaccess` בלבד); מצב ייצור [לא ידוע] |
| `messages` (in/out) | טקסט + כרטיסי הרשאה/בחירה/lane/ai (kinds) | channelPost/channelInsertIn/permissionPost/choicePost | אין מחיקה; `permissionsExpire` עצל | מקור אמת **היחיד** לשיחה בלוח |
| `channel_state` | counts/waiting/last_by_thread JSON | `channelStateRefresh` (באותה טרנזקציה) | נכתב מחדש | נגזרת, לא מקור [מאומת silence.php:79-107] |
| `dispatcher` (id=1) / `dispatch_requests` | lease מנהל; תור העירות | dispatch.php | `sent` לא פג (F-05) | — |
| `events` | יומן פעולות | `logEvent` | אין | — |
| `data/bell/<channel>` | "פעמון" ל-long-poll (תוכן=uniqid) | `ringBell` | אין ניקוי | רלוונטי רק עם `long_poll_max>0` (ברירת מחדל 0) channels.php:329 |
| `data/throttle/*` | תקציב 1/10s לטוקן; מדי meter | `bridgeBudget`, `meterBump` | `meterSweep` עצל (אחרי 120 דק') | throttle.php, listen.php:91-111 |
| config (`bridge_paused`, `listeners_mode`, `long_poll_max`…) | הגדרות מערכת | `configSet` | — | שני כותבים ל-bridge_paused (known-issues §1) |
| `.claude/board-link.json` (בצ'אט, מחוץ ל-git) | טוקן ערוץ, cookie, pending_reply_to, pending_choice(+questions,+answer), backoff_*, listen_state, allow_seen, health_at | link.sh, hooks, listen.sh (`link_set`: mktemp+`cat >`, **לא אטומי**, lib.sh `link_set`) | נמחק ב-`archive`; אבד בהחלפת קונטיינר | כתיבה מקבילית של hooks+listener ללא lock [הסקה: lib.sh link_set] |
| `.claude/board-pending.json`, `board-listen.log` | גיבוי הודעות המאזין; יומן | listen.sh | `ack` מנקה pending; לוג ללא סיבוב | — |
| `$TMPDIR/claude-tasks-perm-<hash>/` | סמני open-/resolved-/gate- | hook-permission/resolved/gate | `find -mmin +60 -delete` ב-hook-resolved | — |
| `~/.claude-tasks-bridge/` (+`.updated`) | עותק ריצה של bridge מ-`origin/main` | update.sh (החלפה אטומית mv) | `.old` נמחק | **קוד שרץ בצ'אט מגיע מ-main, לא מהענף** — hook.sh:7-8; link.sh:23-27 (supply-chain פנימי: מי שממזג ל-main משנה התנהגות כל הצ'אטים) |
| localStorage/דפדפן | state UI, תרגום (מודל ~147MB) | assets/js | — | לא נבדק לעומק |

## B. תהליכים/מנגנוני ריצה
| מנגנון | קובץ | תדירות | נשרף | מצב |
|---|---|---|---|---|
| poll שיחה פתוחה | chats.js:17,695 | 4s (לשונית גלויה) | בקשת PHP+SQLite | פעיל |
| רענון לוח | app.js:1533 | 20s | `refresh`+`channels` (⇒ silenceCheck כותב) | פעיל |
| מאזין | bridge/listen.sh | 30±10s / 10s אחרי הודעה; עד 6900s; backoff 1h-6h | curl, 0 טוקנים עד exit | רדום |
| מנהל (סשן מודל) | dispatch.php:140-152 | אירועי העירה | **תור מודל** לכל העירה | רדום |
| hooks | bridge/hook*.sh (12 אירועים) | לכל prompt/stop/כלי | curl ≤5-8s | **כבוי** |
| update אוטומטי | hook.sh:21-30 | ≤ פעם בשעה, ברקע, timeout 90 | git fetch | כבוי עם ה-hooks |
| גלאי שתיקה | silence.php:241 | בפתיחת רשימה + channel-send | כתיבות DB; אולי קריאת Platform | פעיל בקוד |
| ניטור | monitor.php (`monitorLazy`) | בפתיחה אחרי שעה | קרדיט Platform | פעיל בקוד (מיושן-סותר) |
| בדיקה שעתית (trigger) | link.sh:204-219 | שעה | תור מודל לצ'אט | **נאסר**, שאריות |
| claim/heartbeat מטלות | db.php:362, api.php:1510 | 30 דק' lease | — | רדום |
| deploy | .github/workflows/deploy.yml | כל מיזוג ל-main | — | פעיל (F-20) |

## C. נקודות כניסה ב-API לפי קהל (api.php; מספרי שורות `case`)
- **אדם (cookie/SSO + same-origin):** channel-send 1712, choice-answer 1661, permission-answer 1697, wake/wake-all 1856/1859, lane-*, handoff-*, channels 1900, channel-messages 1950, channel-archive 1953…
- **אסימון הרשמה (worker):** channel-register 1522, chat-env, routine-alert.
- **אסימון ערוץ (`ch_…`):** channel-post 1648, channel-inbox 1807, channel-ack 1818, channel-wait 1824, channel-health 1561, channel-limit 1572, channel-digest, channel-show, channel-waiting 1624, gate-check 1676, permission-status 1685, permission-close 1691, dispatch-pending 1842, dispatch-done 1849, channel-checkin 1873.
- **worker מטלות:** claim, heartbeat 1510, block 1298, complete 1460, start 1492, ask-approval.
- **שמורות בלמים (לפני auth):** channel-wait, channel-inbox, permission-status, gate-check (throttle.php:17).

## D. קבצי bridge — תפקיד ← אירוע ← נכתב אל
| קובץ | אירוע Claude Code | פעולה | כשל |
|---|---|---|---|
| hook.sh | כל האירועים | dispatcher; יוצא אלא אם `CLAUDE_TASKS_BRIDGE=on` | exit 0 |
| hook-prompt.sh | UserPromptSubmit | mirror owner/system; reply_to לבחירה | שקט |
| hook-stop.sh | Stop | mirror chat reply; סוגר open-permissions; health/5min | שקט |
| hook-stopfail.sh | StopFailure | `channel-limit` (LIMIT_ERRORS בלבד) | שקט |
| hook-notify.sh | Notification | `channel-waiting` | שקט |
| hook-permission.sh | PermissionRequest | כרטיס + סקר ≤600s | timeout⇒ללא החלטה |
| hook-resolved.sh | PostToolUse/PermissionDenied | סמן resolved | — |
| hook-gate.sh | PreToolUse | gate-check; fail-closed unattended | deny |
| hook-choice.sh / -done | PreToolUse(Ask…) / PostToolUse | כרטיס בחירה; דוחה את הכלי ב-`board` | deny + pending_choice=local |
| hook-allow.sh | PreToolUse | allow ל-6 כלי גשר (לפי סיומת) | — |
| link.sh | ידני | register/status/update/pending/ack/show/post/handoff/checkin/dispatch-*/archive | die |
| listen.sh | ידני (רקע) | channel-wait | exit 0/3/4/5 |
| update.sh | ע"י hook/ידני | רענון עותק ריצה | exit 1 |
| lib.sh | משותף | board_call (cookie anti-bot humans_NNNN, retry אחד על 409), link_get/set | rc 0/1/2 |

## E. סקילים ותיעוד (מקור הוראות למודל)
| קובץ | מצב | הערה |
|---|---|---|
| .claude/skills/claude-tasks-live/SKILL.md | מעודכן לניתוק (שורות 8-11), אך 250 שורות של נוהל פעיל | המודל מקבל גם הוראות מנהל/מאזין |
| .claude/skills/claude-tasks-worker/SKILL.md | נוהל תור מטלות; מזכיר סוגי אישור חלקיים (known-issues §6) | — |
| docs/spec.md (2208 שורות) | מיושן חלקית (SUPERSEDED מסומן ב-§5.1,5.4,5.6,5.9.5,5.10.1,20,21.6) | audit גובר בסתירה |
| docs/audit-2026-11.md | "הדוח קובע"; מושהה 2026-11-08 | ש1,ש2,ש3-גיבוי בוצעו; ש4-ש9 לא |
| docs/known-issues.md | תיעוד מצב קוד, grep-based, לא הורצו בדיקות | — |
