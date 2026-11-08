# מה פירוש "Platform" בכל נתיב קוד — claude-tasks (מחקר 2026-11-08, קריאת קוד בלבד)

סימון: [מאומת] = נקרא בקוד (נתיב:שורה) · [הסקה] · [השערה] · [לא ידוע]. כל הנתיבים יחסיים ל-`claude-tasks/`.

## ממצא-על
**אין שימוש ב-Managed Agents וב-Agent SDK בשום מקום.** [מאומת] חיפוש `managed.agent|agent.sdk|claude-agent-sdk` בכל התיקייה — אפס תוצאות.
המילה "Platform" משמשת בקוד לשלושה דברים שונים לחלוטין, ושלושתם אינם Managed Agents:

| # | המשמעות | איפה |
|---|---|---|
| A | **"☁️ Platform" = מסלול תשובה ישיר ל-Messages API/Chat Completions/generateContent (בלי כלים, בלי סוכן)** — "אינך כותב קוד ואינך משנה קבצים" | `lib/answers.php:130-134`, `lib/ai.php` |
| B | **"Platform" בשער = ריצת Claude Code CLI בתוך GitHub Actions** (`CT_GATE_KIND=platform`) | `agent/claude-code.yml:96`, `lib/gate.php:310,385`, `lib/engine.php:39` |
| C | **"הכלים הדיגיטליים" (`platform.php`) = SSO/שער זהות חיצוני**, לא קשור ל-AI | `lib/platform.php:1-20` |

בנוסף: מסלול ה"מנוע" `loop` (`agent/run.mjs`) = לולאת סוכן ביתית ב-Node שקוראת ישירות ל-API של הספק; וה"משיק" (`lib/launcher.php`) = **Routine/trigger של claude.ai Code** (beta `experimental-cc-routine-2026-04-01`) — גם הוא לא Managed Agents ולא SDK.

## טבלת נתיבים

| נתיב | מה זה בפועל | מי מאמת | איזה מפתח | איפה נשמר | איך נרשם שימוש | איך מוצג מי ביצע |
|---|---|---|---|---|---|---|
| **A. Platform-answer** `lib/answers.php:194 platformReply` → `lib/ai.php aiComplete` | קריאת HTTP ישירה מ-PHP (curl) ל-`api.anthropic.com/v1/messages` / `api.openai.com/v1/chat/completions` / `generativelanguage…:generateContent` (`ai.php:176-212`). בלי tools, בלי session, בלי state אצל הספק. ההקשר נבנה כל פעם מחדש (`answers.php:90-133`) | משתמש מחובר בעוגייה/זהות-שער, **רק `read` בפרויקט** (`answers.php:200`). המפתח אינו של המבקש | מפתח ה-**משלם** = `created_by` של פרויקט-השורש (`usage.php:216 projectPayer`), לא המשתמש הקורא. fallback: `aiConn()` גלובלי (`projects.php:487`) | טבלת `connections.secret` מוצפן libsodium/AES-GCM (`crypto.php:260`); המפתח הגלובלי ב-`data/config.json` **בטקסט גלוי** (`api.php:642`, `config.php:56`) | `recordUsage` לפי `usage` של תשובת הספק + מחירון בקוד (`usage.php:414,514`), kind=`answer`. מכסה חודשית ל-`user_id` של המשלם (`answers.php:232`) | `messages.ai_used` (provider/model/effort/tokens/cost/label/fell_back_from) מ-`$GLOBALS['CT_AI_LAST']` (`ai.php:311`, `aipref.php:237`); בועה "☁️ Platform · מודל". author_role=`platform` |
| **A2. Platform-monitor/catalog** `monitor.php`, `userConn()` | אותה קריאה, kind=`monitor`, עד 20% מהמכסה (`usage.php:175`) | משתמש/cron-בעצלות | מפתח המשתמש; אין מפתח → `aiConn()` גלובלי **בלי user_id** | כנ"ל | **לא נרשם** כשאין `user_id` (`ai.php:253 if(!empty($conn['user_id']))`) → הוצאה על המפתח הגלובלי אינה נמדדת [מאומת] | `source:'system'` |
| **B. Engine `claude-code`** `lib/engine.php:164 dispatchTask`, `agent/claude-code.yml` | `workflow_dispatch` ב-GitHub Actions; מתקין `npm i -g @anthropic-ai/claude-code` ומריץ `claude -p … --permission-mode acceptEdits --allowedTools "Bash,Read,Edit,Write,Glob,Grep"` (`claude-code.yml:62,121-126`). CLI בתהליך — לא SDK, לא Managed Agents | GitHub: טוקן ה-**GitHub של מי שלחץ "הרצה"** (`engine.php:165 requireGithubToken($user,$project)`). הלוח: `X-Worker-Token` = אסימון-פרויקט (`auth.php:146`) | `ANTHROPIC_API_KEY` = מפתח ה-Anthropic של **מי שהתקין** (admin בפרויקט) — secret בריפו (`engine.php:150`) | סוד ריפו GitHub (`ghSetSecret`), + `AGENT_BOARD_TOKEN`. בלוח: `projects.agent_token` מוצפן (`engine.php:102`) | `finish.mjs` → `report-usage` עם `total_cost_usd` שמחושב אצל ה-CLI (`finish.mjs:40-48`), נזקף ל-`tasks.run_user_id` = **מי שהריץ** (`api.php:1138,1385`), kind=`develop` | הערות בשם `X-Worker-Name: claude-code`, `X-Worker-Route: platform` — **מוצהרים עצמית** (`auth.php:125`, `routes.php:101`); `session_url`=URL הריצה; PR |
| **B2. Engine `loop`** `agent/run.mjs` (+`workflow.yml`) | לולאת סוכן ביתית ב-Node: קוראת ישירות ל-API של הספק (Anthropic/OpenAI/Gemini, `run.mjs:79-120`), מפעילה כלים משלה, פותחת PR דרך GitHub REST (`run.mjs:193`) | כנ"ל | `AGENT_AI_KEY` (secret בריפו) של המתקין | כנ"ל | `report-usage` עם tokens (`run.mjs:120`) + חישוב מחיר בשרת | worker name = `actions/<provider>` (לפי הערת `auth.php:126`) |
| **C. SSO "הכלים הדיגיטליים"** `lib/platform.php` | **לא AI.** השער חיצוני כותב `$_SERVER['PLATFORM_USER']` (sub,name,email); קישור לפי sub ואז לפי **מייל** (`platform.php:131-140`) | השער (mbe-plus.com/platform) | — | `users.platform_sub` | `logEvent` | `users.display_name` |
| **D. Launcher** `lib/launcher.php` | POST ל-`api.anthropic.com/v1/claude_code/routines/<trig>/fire` עם Bearer של טוקן-משימה (`sk-ant-…`) + `anthropic-beta` (`launcher.php:239`). פותח סשן **Claude Code ב-claude.ai** שמנוהל במנוי | טוקן ה-Routine, מוצפן, בעלות `launchers.user_id`; רק `authorRole==owner` (`launcher.php:315,441`) | טוקן-Routine (לא מפתח API רגיל) | `launchers.secret` מוצפן (`launcher.php:329`) | **אין** `usage` — עלות על מנוי claude.ai; מגבלה: 10 פתיחות/שעה (`launcher.php:242`) | `launches.session_url` = `claude_code_session_url` מתגובת Anthropic; `channels.session_id` |
| **E. Chat lane** (bridge) `lib/channels.php`, `bridge/*.sh` | סשן Claude Code אינטראקטיבי/Routine שרץ מחוץ ללוח ומתחבר כ"ערוץ" עם `ch_…` token (`channels.php:140`). hooks מקומיים משקפים שיחה | `X-Worker-Token: ch_…` → `channels.token_hash` sha256 (`auth.php:136-143`) | אין מפתח בלוח (מנוי Claude של בעל הסשן) | token_hash בלבד; הטוקן הגלוי ב-`~/.claude-tasks-bridge` אצל הסשן | אין `usage` בלוח | `session_url` שהסשן דיווח (נבדק רק כ-http/https, `api.php:1524`) |
| **F. aipref / lanes / connections** | **שכבת בחירה, לא ביצוע**: `resolveAi` (`aipref.php:344`) בוחר ספק+מודל+effort: משימה › הודעה › ערוץ › חיבור. `lanes.php` = מפסק (breaker) לכל משלם: כשל שרשרת → ☁️ "נעצר", ואז handover לצ'אט / המתנה / שאלה (`lanes.php:75-161`). `connections.php:267 aiChain` = שרשרת failover לפי `priority`, מדלג על חיבור `failing` 10 דק' (`:23`); רק `bad_request` עוצר מעבר (`:303`) | — | כל חיבור: מפתח מוצפן, `user_id` | `connections` | `usage` + `ai_used` | `ai_used.fell_back_from`, הערת "↪️ עבר ל-…" על המטלה (`connections.php:330`) |
| **G. מחיר/מכסה** `lib/usage.php` | מחירון בקוד (`PRICES`, `usage.php:414`), מודל לא ידוע → המחיר הגבוה של הספק (`:476`). `quota_usd` חודשי למשתמש; 0=כבוי; 80% אזהרה; 100% → ☁️ נחשב 💬 | — | — | `usage`, `users.quota_usd` | `recordUsage` (`:514`) | גרף `usage-summary` |

## הבחנות שחייבים לשמר במחקר/תכנון
1. **ביצוע מול בחירה**: A/B/B2/D/E הם נתיבי ביצוע שונים במהות; F/G הם בקרה. אין להשוות A מול Managed Agents — A אינו סוכן אלא completion חד-פעמי עם הקשר שהשרת מרכיב.
2. **שלושה מודלי "מי משלם" שונים באותה מערכת** [מאומת]:
   - A (תשובות): **משלם = יוצר פרויקט-השורש** (`usage.php:216`), גם אם המבקש חבר `read`.
   - B/B2 (מנוע): **המפתח הפיזי = של המתקין** (`engine.php:150`), **הרישום והמכסה = של המריץ** (`api.php:1133,1138`). ← פער חשבונאי, ראו F-3.
   - D/E: מנוי claude.ai של בעל הלוח; לא מדוד.
3. **"מי ביצע בפועל"** מוצג מנתונים שהתהליך עצמו מדווח (`X-Worker-Name`, `X-Worker-Route`, `total_cost_usd`) — לא מאומת מצד השרת, למעט `ai_used` של נתיב A (נוצר בשרת).
4. **מצב נוכחי**: הלוח מושהה; `.claude/settings.json` בלי hooks (סקיל `claude-tasks-live` שורות 8-11), לכן E אינו פעיל כעת.
