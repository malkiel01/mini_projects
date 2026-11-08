# מסלולי Claude Code — מה כתוב בתיעוד הרשמי

תאריך מחקר: 2026-11-08. מקורות: code.claude.com/docs/en/{headless, sessions, agent-teams, cross-session-messaging, claude-code-on-the-web, routines, github-actions, hooks, authentication, costs, agent-sdk/overview, agent-sdk/sessions(קטע חיפוש רשמי)}. לא נקראו במלואם: agent-sdk/hooks, agent-sdk/permissions, agent-view, channels, workflows, sub-agents.

## 1. Interactive
[מאומת] טרמינל, Desktop, VS Code. `--resume`/`--continue`, `/branch`, `/rename`, `/remote-control`, `/schedule`. מצבי הרשאה: default/Manual, acceptEdits, plan, auto, dontAsk, bypassPermissions.

## 2. `claude -p` (headless)  — https://code.claude.com/docs/en/headless
- [מאומת] `-p` = לא-אינטראקטיבי; יוצא 0 בהצלחה ולא-0 בכשל. `--output-format text|json|stream-json`; `json` כולל `result`, `session_id`, `total_cost_usd` (הערכה בצד לקוח); `--json-schema` → `structured_output`.
- [מאומת] `--bare` מדלג על hooks/skills/plugins/MCP/CLAUDE.md/auto memory ועל OAuth/keychain — דורש `ANTHROPIC_API_KEY` או `apiKeyHelper`; מומלץ ל-CI ו-SDK ויהפוך לברירת מחדל ב-`-p` בעתיד.
- [מאומת] בלי `--bare`, `-p` מריץ hooks של `.claude/settings.json` ושרתי `.mcp.json` גם בתיקייה שלא אושרה (ללא דיאלוג אמון).
- [מאומת] הרשאות: ב-`-p` קריאה שדורשת אישור נדחית אלא אם `--allowedTools`/`permissions.allow`/hook/`--permission-prompt-tool`; מ-v2.1.302 `-p` מתחיל ב-Manual אם דבר לא קבע אחרת. `--permission-prompts none` ללא משגיח (v2.1.259+).
- [מאומת] SIGTERM → יציאה 143; להשלים turn — SIGINT או `interrupt()` של ה-SDK. ה-turn שנקטע לא מושלם ב-resume אלא אם `CLAUDE_CODE_RESUME_INTERRUPTED_TURN=1`.
- [מאומת] עבודת רקע: ריצת `-p` ממתינה לרקע עד 10 דקות idle (ברירת מחדל); `--bare` מבטל משימות רקע לגמרי.
- [מאומת] אירוע `system/api_retry` עם `attempt`, `max_retries`, `retry_delay_ms`, `error_status`, ו-`error` (`rate_limit`, `overloaded`, `authentication_failed`, `billing_error`, `server_error`...).
- [מאומת] stdin מוגבל ל-10MB. סשנים של `-p`/SDK **לא** מופיעים בבורר ו-`--continue` אך אפשר `--resume <id>`; `claude -p --continue` כן כולל אותם.
- [מאומת] `--cloud` עם `-p` ומזהה סשן = שליחת הודעה לסשן ענן קיים; `--bg` ו-`--cloud` עם תיאור משימה נדחים עם `-p`.

## 3. Agent SDK (Python/TypeScript)  — agent-sdk/overview.md, agent-sdk/sessions
- [מאומת] ספרייה שמריצה את בינארי Claude Code; יכולות: built-in tools, hooks, subagents, MCP, permissions, sessions, skills/commands/memory, plugins.
- [מאומת] השוואה רשמית: Agent SDK (אתה מארח, בתהליך שלך) מול CLI מול Client SDK (Messages API) מול **Managed Agents** (Anthropic מארחת, דרך Claude API) — ארבע מוצרים שונים. (agent-sdk/overview)
- [מאומת] Sessions: `continue` (האחרון בתיקייה), `resume` (לפי id), `fork` (חדש עם עותק היסטוריה; המקור לא משתנה; "forking branches the conversation history, not the filesystem"). הסשן נכתב לדיסק (JSONL); חייב להתקיים על אותה מכונה, אלא אם משקפים עם `SessionStore` adapter. `persistSession:false` (TS).
- [מאומת] אימות: "Unless previously approved, Anthropic does not allow third party developers to offer claude.ai login or rate limits for their products, including agents built on the Claude Agent SDK" → להשתמש ב-API key. (agent-sdk/overview)
- [מאומת] רישיון: תנאי Commercial Terms.
- [לא אומת] דפי agent-sdk/hooks ו-agent-sdk/permissions לא נקראו; הסקה לפי overview בלבד ש-hooks ו-`canUseTool` callbacks קיימים (headless מזכיר `canUseTool`).

## 4. Resume / continue / fork ב-CLI  — sessions.md
- [מאומת] `claude --continue`, `--resume [id|name|path]`, `/resume`, `--fork-session`, `/branch`.
- [מאומת] מה משוחזר: היסטוריה, מודל, agent, מצב הרשאה (חוץ ממקרים מוגדרים), goal פעיל, scheduled tasks שלא פגו; **לא משוחזרים**: Bash/monitor רקע; כלי שהיה באמצע ריצה בקריסה — לא יסתיים ולא ירוץ שוב, Claude מקבל סימון "נחתך" ונדרש לבדוק אם השפיע.
- [מאומת] דגלים כ-`--mcp-config`, `--settings`, `--add-dir` לא משוחזרים — חייבים להעביר שוב.
- [מאומת] תמלולים נשמרים ב-`~/.claude/projects/<project>/<session-id>.jsonl`; שמירה 30 יום ברירת מחדל (`cleanupPeriodDays`); פורמט פנימי ומשתנה — לא לפרסר ישירות; לתכנות — `-p --output-format json` או SDK.
- [מאומת] resume של סשן רקע שרץ → מצרף אליו (`claude attach`) במקום להעתיק.

## 5. Hooks  — hooks.md (נקרא עד תחילת SessionStart; ~3000 שורות נחתכו)
- [מאומת] סוגי handlers: command, http, mcp_tool, prompt, agent. אירועים (טבלה): SessionStart/End, UserPromptSubmit, PreToolUse, PermissionRequest/Denied, PostToolUse(+Failure,+Batch), Notification, SubagentStart/Stop, Stop, StopFailure, TeammateIdle, TaskCreated/Completed, PreCompact/PostCompact, **PreModelSwitch/PostModelSwitch**, Elicitation, Worktree*, Config/Cwd/File changed ועוד.
- [מאומת] exit 2 = חסימה (באירועים שניתנים לחסימה); exit 0 + JSON לשליטה מדויקת; כל קוד אחר = שגיאה לא חוסמת, אלא אם `onFailure:"block"` (v2.1.295+). hook שמסתיים בתקלה כברירת מחדל **לא חוסם** — לא להסתמך עליו כשער אכיפה.
- [מאומת] אותם אירועי hooks רצים גם ב-cloud sessions, אך cloud sessions לא קוראים `~/.claude/settings.json` מקומי.
- [מאומת] הבעיה לתכנון: hooks שמתחילים תהליך רקע — פלט שמודפס אחרי ש-Claude Code הפסיק לקרוא לא חוסם; יש להדפיס החלטה לפני.
- [הסקה] אין hook שנועד ל"הודעה נכנסת לסשן" — כניסת הודעות עוברת cross-session messaging/Channels, לא hooks; `UserPromptSubmit` רץ גם ב"turns שClaude Code מתחיל מעצמו" (שורה בטבלה).

## 6. Cross-session messaging  — cross-session-messaging.md
- [מאומת] Claude (דרך `ListAgents`/`SendMessage`) שולח טקסט פשוט בין סשנים שלך. v2.1.224+ (macOS/Linux/WSL2), v2.1.234+ (Windows).
- [מאומת] מקומי: socket לכל סשן (Unix socket/named pipe), לא עובר דרך שרתי Anthropic. בין מכונות/ענן: דרך שרתי Anthropic ודרך חיבור Remote Control. **סשנים מעבר למכונה דורשים סשן שמחובר ל-RC ולכן claude.ai login — לא עובד עם API key/Bedrock וכו'.**
- [מאומת] קבלה: הודעה נקראת בין tool calls בתור פעיל; בסשן idle מתחיל turn חדש. נספרת לשימוש כמו prompt רגיל.
- [מאומת] שליטה: `crossSessionInbound` = accept/hold/refuse; ברירת מחדל לפי מצב הרשאה (סשן ב-bypass מחזיק הודעות לאישור). `-p` לא יכול להציג דיאלוג אישור — מחזיק הודעה עד `dialogExpiry` (5 דק') ואז מפיל; `crossSessionInbound: accept` ב-`--settings` מאפשר worker לא משגיח. `--bare` לא קושר socket — לא ברשימה.
- [מאומת] הודעה אינה יכולה לאשר permission, לשנות config, או להריץ פקודות slash. מגבלות: ~מיליון תווים, burst מוגבל, עד 50 הודעות בתור, הגנת לולאות.
- [מאומת] `CLAUDE_CODE_MESSAGING_SOCKET` + `CLAUDE_CODE_MESSAGING_TOKEN` חשופים ל-hooks/Bash; סקריפט יכול לפרסם ל-socket של הסשן שלו (תיעוד "own-child messages").
- [הסקה] זה **ערוץ בין סשני Claude**, לא API לאפליקציה חיצונית. אין endpoint ציבורי לשלוח הודעה לסשן מקומי חי מבחוץ (חוץ מ-Channels, לא נקרא).

## 7. Agent teams  — agent-teams.md
- [מאומת] ניסיוני, כבוי כברירת מחדל (`CLAUDE_CODE_EXPERIMENTAL_AGENT_TEAMS=1`). lead + teammates (כל אחד סשן Claude עצמאי), רשימת משימות משותפת, mailbox (`~/.claude/teams/...`).
- [מאומת] **דורש סשן אינטראקטיבי**: ב-`-p`/Agent SDK לא נוצרים teammates (subagent בשם ירוץ כ-subagent רגיל).
- [מאומת] מגבלות: אין resume ל-in-process teammates; סשן אחד = צוות אחד; אין nested teams; lead קבוע; עלות טוקנים גבוהה (כ-7x במצב plan לפי costs.md).
- [מאומת] הרשאות teammate מועברות ל-lead; hooks `TeammateIdle`/`TaskCreated`/`TaskCompleted` לאכיפה.

## 8. Cloud sessions (Claude Code on the web)  — claude-code-on-the-web.md
- [מאומת] מנוי נדרש (Pro/Max/Team/Enterprise-seat); רץ על תשתית Anthropic או self-hosted environment; "continues after you close your laptop"; נספר במגבלות המנוי, אין חיוב VM נפרד.
- [מאומת] הפעלה: claude.ai/code, אפליקציה, `claude --cloud "task"`. **teleport חד-כיווני**: `--teleport` מושך סשן ענן לטרמינל (העתק; עבודה חדשה נשארת מקומית); אי אפשר לדחוף סשן טרמינל קיים לענן מה-CLI (Desktop כן יכול).
- [מאומת] שליחת follow-up: `claude -p "msg" --cloud <session-id>` — הודעה אחת, יוצא בלי לחכות; לא זמין ל-Bedrock/Vertex; דורש `claude auth login` ו-policy `allow_remote_sessions`.
- [מאומת] סשן ענן פג אחרי תקופת חוסר פעילות; פתיחה מחדש מספקת VM חדש: שיחה משוחזרת, **תהליכי רקע/subagents/scheduled tasks לא**. מגבלות: rate limits משותפים למנוי; IP allowlist של הארגון שובר סשנים; GitHub בלבד לשכפול ו-PR.
- [מאומת] סשני ענן **תמיד משתמשים בהרשאות המנוי**; `ANTHROPIC_API_KEY` בסביבת הענן לא עוקף. (authentication)

## 9. Scheduled tasks / Routines  — routines.md (research preview)
- [מאומת] פועלים כסשני ענן מלאים; טריגרים: Schedule (שעתי+, cron עם מינימום פעם בשעה), **API** (POST ל-`/v1/claude_code/routines/<trig_id>/fire` עם bearer token וה-header `anthropic-beta: experimental-cc-routine-2026-04-01`), GitHub events.
- [מאומת] שדה `text` ב-fire נעטף כ"נתון לא אמין" (`<routine-fire-payload>`); הפרומפט השמור חייב להתייחס אליו במפורש.
- [מאומת] מגבלות: ריצות מתוזמנות 100/שעה לחשבון; Run now + API 30/שעה לכל task; API fires 100/שעה לחשבון; **אין overage**. נוגסות במכסת המנוי; במכסה שנגמרה — credits אם הופעלו, אחרת נדחות.
- [מאומת] Task שייך לחשבון הבודד, לא משותף. ללא session API נוסף. נקודת ה-/fire היא "claude.ai users only, not part of Claude Platform API".
- [מאומת] `/schedule` לא זמין עם Console API key/ספק ענן.

## 10. GitHub Action  — github-actions.md
- [מאומת] `anthropics/claude-code-action@v1`; מצב אינטראקטיבי (@claude) ואוטומציה (`prompt`). אימות: `ANTHROPIC_API_KEY` או `CLAUDE_CODE_OAUTH_TOKEN` (מ-`claude setup-token`, מנוי Pro/Max/Team/Enterprise) או workload identity federation. לארגון: מפתח API מומלץ כי OAuth token קשור למנוי של מי שיצר אותו.
- [מאומת] עלות: דקות GitHub Actions + טוקנים; בטוקן OAuth — על המנוי.
- [מאומת] הגנות: דורש write access של הטריגר; bots נחסמים אלא אם ב-`allowed_bots`.

## 11. Background sessions / agent view
- [מאומת עקיף] מופיעים כ"background session" ב-`claude agents`, `claude attach <id>`; `/resume` מעביר שיחה לרקע; סשן רקע שהסתיים נפתח עם `--continue`. (sessions.md) — דף agent-view עצמו [לא אומת/לא נקרא].
- [מאומת] סשן רקע/RC מפסיק להתקדם כשהתחברות המנוי פגה ולא מתאושש עד `/login` (authentication.md) → חשוב לתהליכי לילה.

## 12. Model switch ב-Claude Code
- [מאומת] `/model` באמצע סשן (גם מ-RC ו-`-p`); hooks `PreModelSwitch`/`PostModelSwitch`. סשן שמשוחזר ממשיך על המודל שלו, מלבד חריגות. (remote-control, sessions, hooks) — בניגוד ל-Managed Agents שבו המודל נעול (ראו platform-managed-agents.md §16).
