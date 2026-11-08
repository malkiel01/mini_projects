# הצעת מודל גישה מאוחד — claude-tasks (הצעה בלבד, 2026-11-08; לא מימוש)

כל שמות ה-MCP/API/טבלאות להלן הם **ממשקים מוצעים**, לא קיימים. עיקרון מנחה: לא להוסיף אישורים לפעולות תמימות (קריאה, צפייה, דיון, עריכה בענף עבודה). אישור רק בגבולות שיש בהם נזק בלתי הפיך או הוצאה: merge ל-main, deploy, הוצאה מעל תקרה, שינוי מדיניות, חשיפת סוד.
ממצאי הבסיס: `access-audit.md` (F-n); מפת הנתיבים: `platform-paths.md`.

## 1. ישויות ובעלות

```
User ─┬─ Credential (משאב: github-app-install | ai-key | routine-token) — בעלים: user, מוצפן, לא יוצא החוצה
      └─ Membership(project, level)
Project (עץ; שורש = יחידת בידוד)
 ├─ Repo binding: repo_id (GitHub numeric id, לא owner/name), default_branch, path
 ├─ Credential grants: (project, credential, purpose) — מי רשאי להשתמש במה ולאיזו מטרה
 ├─ Policy (type → allow|deny|ask, locked?)  — ראו §5
 └─ Session/Run: (project, branch, executor, initiator_user, payer_credential, agent_version, gate_version)
```
- **בעלות מפורשת**: `project.owner_user_id` (היום רק `created_by`, ו"משלם" נגזר ממנו: `usage.php:216`). מטלה יתומה (בלי פרויקט) — `created_by` נבדק (F-13).
- **יחידת בידוד = פרויקט-שורש.** אסימונים, מדיניות-ברירת-מחדל, תקציב והסכמות אוטומציה נגזרים משם; אחים מתחת לאותו שורש מופרדים ע"י **סשן-scope** ולא ע"י אסימון-שורש משותף (F-14).
- **ענף ו-סשן**: לכל Run/Session שדה `branch` (`agent/task-N`, `session/<id>`); הרשאת כתיבה ברירת מחדל = ענפי-עבודה בלבד; `main`/מוגנים = פעולת-שער (קיים: `push-protected`).

## 2. מי ביצע בפועל — זהות executor מונפקת בשרת
היום: `X-Worker-Name/Route` מוצהרים עצמית (F-15). הצעה: ל-Run יש `executor` שהשרת קובע לפי **סוג האסימון שהונפק**, לא לפי כותרת:

| executor_kind | מקור | זקיפה | מוצג |
|---|---|---|---|
| `completion` | נתיב A (Messages API ישיר, `answers.php`) | credential של המשלם | "☁️ <מודל>" (קיים ב-`ai_used`) |
| `loop-agent` | `run.mjs` ב-Actions | credential שנבחר ל-Run | "🤖 loop · <ספק/מודל>" |
| `cc-actions` | Claude Code CLI ב-Actions | credential שנבחר ל-Run | "🤖 Claude Code · Actions · run#" |
| `cc-session` | סשן Claude Code/Routine מחובר (`ch_`) | מנוי (לא מדוד) | "💬 <שם ערוץ>" |
| `managed-agent` (עתידי, [לא ידוע] תמיכה) | session/agent מנוהל אצל ספק | credential של הפרויקט | "🧩 agent vX · session" |

כלל: **`executor_kind`, `agent_version`, `gate_version`, `payer_credential_id`, `initiator_user_id` נכתבים על ה-Run בעת הנפקת האסימון** ואינם ניתנים לדיווח עצמי. כותרות הן מידע בלבד.

## 3. בחירה והפרדה של אישורים (credentials)
- **שלושה סוגי נכס נפרדים** ולא "חיבור" אחד: (1) גישה לריפו (GitHub), (2) מפתח AI, (3) טוקן Routine. כל אחד עם `purpose` (`answer|develop|monitor|launch`).
- **Grant מפורש** `project ↔ credential ↔ purpose`: כברירת מחדל מפתח AI של משתמש משמש רק פרויקטים שהוא `admin` בהם או שסימן בהם; **אף פעם לא נבחר "בשקט" ע"י חבר אחר** (תיקון F-3, F-12).
- **שלוש שאלות נפרדות בכל Run** והתשובות נרשמות עליו: *מי הפעיל* (initiator) · *מפתח של מי שילם* (payer credential) · *אישורי GitHub של מי נכתב* (repo credential). חשבונאות והמכסה נבדקות על **payer credential** (F-3).
- **אין fallback גלובלי שקט** (F-4): חיבור "system" חייב להיות Credential רגיל עם `user_id=system`, מוצפן, נמדד, וגלוי בגרף.
- **failover בין אישורים** (קיים: `aiChain`): נשמר, אבל רק בין Credentials שמותרים לאותו purpose/project; מעבר ספק אחר = אירוע גלוי, לא שקט (כבר כך ב-`aipref.php:264`). הוספה: מעבר מ-credential של משתמש A ל-B אסור לעולם.

## 4. GitHub כמשאב-ריפו מול GitHub MCP
| גישה | יתרון | סיכון (מאומת/הסקה) |
|---|---|---|
| **PAT classic `repo`+`workflow`** (היום, `platform.js:178`) | פשוט | [מאומת] גישה לכל הריפואים של המשתמש; `workflow` = יכולת לערוך deploy. רחב מדי לפרויקט אחד |
| **Fine-grained PAT / GitHub App install לריפו אחד** (הצעה) | scope = ריפו, הרשאות מפורטות, ניתן לביטול לפי ריפו | דורש התקנה; [לא ידוע] זמינות App ברמת משתמש — נדרש אימות מול התיעוד |
| **GitHub MCP בסשן** | כלי מוכן לסוכן; פעולות שמות-כלי יציבות | מפתח ה-MCP = טוקן שמוחזק בסשן; `always` על כלי MCP רחב (F-9) |

המלצה: **Repo = משאב של הפרויקט** (`repo_id` + credential ברמת ריפו, ללא PAT כללי). הסוכן **אינו מחזיק** טוקן GitHub רחב; מקבל טוקן *קצר-מועד* שהמנפיק (controller) יוצר לריפו+ענף+פעולות מותרות (§6). GitHub MCP מותר לקריאה/דיון/PR; `merge`/`deploy` נשארים מאחורי שער (קיים: `merge-own-pr`).

## 5. מטריצת פעולות (בלי אישור לתמימות)
| פעולה | read | write | admin | owner-board | שער |
|---|---|---|---|---|---|
| צפייה בקוד/מטלות, דיון, שאלה ל-☁️ (לפי הגדרת משלם) | ✔ | ✔ | ✔ | ✔ | — |
| עריכה + commit בענף עבודה, פתיחת PR | — | ✔ | ✔ | ✔ | — (ללא אישור) |
| push לענף מוגן / force | — | — | — | — | `push-protected` — תמיד שואל/חסום |
| merge ל-main | — | — | `ask` (או allow קבוע ע"י admin) | ✔ | `merge-own-pr` |
| deploy / שינוי workflows | — | — | — | ✔ | `change-deploy` — בלי "תמיד" |
| שינוי מדיניות (always/never) | — | — | ✔ | ✔ | F-2: מ-write → admin |
| הוצאה מעל תקרת Run/יום | — | ✔ עד תקרה | ✔ | ✔ | כרטיס הוצאה (קיים: `ai_card`, `aipref.php:439`) |
| גישה לסוד | — | — | — | — | `secrets` — תמיד שואל |

כלל מדיניות חדש (F-1): **`locked`** — כלל `deny` שנקבע ברמה R אינו ניתן לביטול ברמה נמוכה מ-R אלא ע"י מי שרשאי ב-R. כך `owner_only` ו-policy מתנהגים אותו דבר.

## 6. Controller + אסימוני Run קצרי-מועד (במקום מפתח API אצל הסוכן)
**בעיה (F-7, F-17):** הסוכן רץ עם `ANTHROPIC_API_KEY`+`GH_TOKEN`+`BOARD_TOKEN` ב-env וכלי Bash.
**הצעה:** ה-controller (הלוח) הוא היחיד שמחזיק Credentials. לכל Run הוא מנפיק:
- `run_token` — תוקף קצר (למשל = תקרת זמן ה-Run), קשור ל-`(project, task, branch, executor)`, חד-שימושי להרשמה, **נשלל בסיום**.
- גישה ל-AI **דרך proxy של הלוח** (`/ai-proxy` מוצע): הסוכן שולח בקשות עם `run_token`, הלוח מחבר את המפתח, אוכף תקרת הוצאה/מודל, רושם usage ב-Run **בצד השרת** (פותר F-6 — אין דיווח עצמי). [לא ידוע] תאימות של Claude Code CLI ל-`ANTHROPIC_BASE_URL` מותאם ולאסימון קצר — דורש אימות; חלופה: workspace key נפרד עם תקרה.
- GitHub: installation token קצר לריפו+ענף (או Fine-grained עם תפוגה).

### ממשקי MCP מוצעים לסוכן שמפעיל controller בלי מפתח API
(שמות מוצעים בלבד; כולם נוסעים עם `run_token`, **ללא** מפתח בשום מקום)
| כלי מוצע | תפקיד | שער |
|---|---|---|
| `tasks_claim_next` / `tasks_get` | משיכת מטלה בתחום ה-Run (בלי owner_only-leak, F-5) | — |
| `run_report_progress` | סטטוס/הערה/`session_url` | — |
| `run_complete` / `run_block` | סיום (+`pr_url`) / שאלה לאדם | — |
| `decisions_ask` / `decisions_status` | **החלטה ממתינה** (§7): יוצרת כרטיס; מחזירה `pending|answered` | — |
| `repo_read` / `repo_diff` | קריאה בריפו דרך controller (ללא PAT אצל הסוכן) | — |
| `repo_commit_branch` / `repo_open_pr` | commit/PR לענף העבודה בלבד | — |
| `repo_merge_request` | בקשת merge — לא מבצע; פותח החלטה | `merge-own-pr` |
| `deploy_request` | בקשת deploy — החלטה בלבד | `change-deploy` |
| `usage_get` | מכסה נותרת/תקרת Run (מידע, לסוכן להתאים עצמו) | — |
| `launch_child_session` | פתיחת סשן-צאצא באותו פרויקט בלבד | — |

כלל: אין כלי "שלח הודעה לסשן אחר" חוצה-פרויקט (F-9); קשר בין סשנים רק דרך הלוח, באותו פרויקט-שורש.

## 7. החלטות ממתינות שנשמרות בין החלפות מנוע
בעיה: היום החלטה ממתינה מקושרת למסלול (`platform-hold`, `choices` של Platform, `permission` של ערוץ, `approval` של מטלה — ארבעה מנגנונים נפרדים: `aipref.php:458`, `choices.php`, `permissions.php:136`, `approvals.php:266`).
הצעה — **ישות Decision אחת** שאינה תלויה ב-executor:
```
decisions(id, project_id, run_id?, task_id?, type, summary, detail, options, status, decided_by, decided_at, expires_at, resume_hint)
```
- נוצרת ע"י כל executor (`decisions_ask`), נשמרת ב-DB; **החלפת מנוע (☁️↔💬↔Actions) לא מבטלת אותה** — היורש קורא `decisions_status(task)` ומקבל `resume_hint` (מה נאמר, מה הוכרע, איפה עצרנו).
- `expires_at` ברור; פקיעה = `deny` מפורש + רישום, כמו היום בשער לריצה ללא השגחה (`gate.php:424-434`).
- ההחלטה נקשרת ל-`(project, task, type)` ולא ל-`channel` → ערוץ שהוחלף (`channels.php replaced`) לא "מאבד" שאלות.
- בהחלפת מנוע באמצע Run: ה-controller מסמן Run ישן `superseded`, ולא מעביר אסימון-Run; Run חדש מקבל אסימון חדש + רשימת Decisions פתוחות.

## 8. גרסת agent מול pinned deployment
- `agent_version` (תוכן `run.mjs`/`claude-code.yml`/`hook-gate.sh`/`classify.php`, hash) ו-`cli_version` נרשמים ב-Run (F-18). 
- התקנה לריפו = **commit מוצמד ל-SHA** ופרמטר `gate_sha` ב-workflow; ה-workflow מושך את השער מ-ref קבוע, לא מה-work tree (F-7).
- "pinned deployment": ל-Run מצורף `deployment_id` (מה היה פרוס/אילו workflows). אם הריפו שינה את workflow המנוע מאז ההתקנה — הלוח מזהה drift (hash שונה) ודורש "התקנה מחדש" לפני Run; בלי זה אין Run (במקום "עדכון מנוע" אוטומטי).
- אם בעתיד ייעשה שימוש ב-Managed Agents: `agent_id`+`agent_version`+`environment` נשמרים ב-Run; [לא ידוע] תמיכת pinning — נדרש מחקר נפרד (לא עוסק בו כאן).

## 9. בידוד אוטומציה בין פרויקטים
- **dispatcher לכל פרויקט-שורש** (היום `dispatcher` יחיד `id=1`, `dispatch.php:25`); או רשימת יעדים מותרת לכל dispatcher.
- hooks/settings ברמת הפרויקט, לא root המונו-ריפו (F-20); `.claude/settings.json` של פרויקט א' לא מתיר `create_trigger` לפרויקט ב'.
- אסימון הרשמה: **scoped לעלה + תפוגה** במקום אסימון-שורש לכל הסביבה (F-14).
- Routine/Launcher: טוקן Routine **פר-פרויקט-שורש** (היום `launchers.user_id` אחד לכל המשתמש, `launcher.php:370`): `launcherFor` נופל ל"הראשון של המשתמש" — לחסום fallback חוצה-פרויקט.
- תקציב/מכסה ב-**שני ממדים**: משתמש (קיים) + פרויקט-שורש (חדש), כדי שפרויקט אחד לא ישרוף את הכול.

## 10. סדר מומלץ למימוש עתידי (אחרי שהאפליקציה תחזור)
1. סגירת F-7 (הוצאת מפתחות מה-env של הסוכן + default-deny בריצות ללא השגחה) ו-F-1/F-2 (locked + admin-only למדיניות).
2. Run כישות: executor/payer/initiator/agent_version + זקיפה נכונה (F-3, F-6, F-15).
3. Decision אחידה (§7) במקום ארבעת המנגנונים.
4. Controller-proxy + אסימוני Run (§6) — דורש אימות תאימות [לא ידוע].
5. הקשחת בידוד (F-5, F-9, F-14, F-20).

## שאלות פתוחות
- האם `PLATFORM_USER.email` מאומת בשער (F-10)? המסמך `platform/docs/auth.md` לא בריפו הזה.
- האם Claude Code CLI תומך ב-proxy/אסימון קצר-מועד כפי שהוצע; ואילו תקרות הוצאה קיימות למפתח אצל הספק.
- האם יש כוונה להרחיב ל-Managed Agents (משנה את §2/§8).
- מדיניות רצויה ל-`read`: האם מותר להם לשאול את ☁️ על חשבון המשלם (F-12)?
