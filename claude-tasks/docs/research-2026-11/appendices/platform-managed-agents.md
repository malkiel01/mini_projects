# Claude Managed Agents — מה כתוב בתיעוד הרשמי (נקרא ב-web_fetch)

תאריך מחקר: 2026-11-08. כל URL תחת `https://platform.claude.com/docs/en/managed-agents/` אלא אם צוין אחרת.
תיוג: [מאומת] = נקרא בדף, [הסקה] = מסקנה שלי מהדף, [לא אומת] = לא נמצא/לא נקרא.
הערה: זה "Managed Agents" (API בטא, header `managed-agents-2026-04-01`) — לא Messages API ולא Agent SDK.

## 1. מושגי ליבה  (overview)
- [מאומת] ארבעה מושגים: Agent (מודל, system, tools, MCP, skills), Environment, Session, Events. (overview)
- [מאומת] בטא; נדרש header `managed-agents-2026-04-01`; מופעל כברירת מחדל לכל חשבונות API. (overview)
- [מאומת] לא זכאי ZDR ולא HIPAA; תמלולים נשמרים עד מחיקה. (overview)
- [מאומת] זמין גם ב-Claude Platform on AWS וב-Claude Models on Google Cloud (Anthropic managed), עם הבדלים. (overview)
- [מאומת] אימות: מפתח API מ-Console (overview "Beta access"). אין בדף אזכור לאימות מנוי.

## 2. Agents וגרסאות  (agent-setup)
- [מאומת] סוכן = משאב ניתן לגרסאות; `version` מתחיל ב-1 ועולה בכל עדכון שמשנה קונפיגורציה; no-op לא יוצר גרסה.
- [מאומת] `version` אופציונלי בעדכון: איתו → 409 אם לא תואם (optimistic concurrency); בלעדיו → last write wins.
- [מאומת] List versions קיים; Archive = read-only, בלתי הפיך; sessions קיימים ממשיכים, חדשים לא יכולים להפנות אליו.
- [מאומת] `model` נתמך: Claude 4.5 ומעלה; אובייקט model תומך ב-`speed`, `effort`, `inference_geo`.
- [מאומת] session יכול להצמיד גרסה (`{"type":"agent","id","version"}`) או לקבל מחרוזת id = הגרסה האחרונה. (sessions)

## 3. Environments  (environments, cloud-sandboxes-reference)
- [מאומת] Environment = קונפיגורציית sandbox; session מקבל sandbox נפרד (קונטיינר Linux טרי) גם כשמשתפים environment; אין שיתוף מערכת קבצים בין sessions.
- [מאומת] סוגים: `cloud` (Anthropic) ו-`self_hosted` (תשתית שלך, worker). Environments לא ממוספרי גרסאות.
- [מאומת] Networking: `limited` (allowed_hosts, allow_package_managers, allow_mcp_servers) או `unrestricted`; **יצירה ב-API בלי networking = unrestricted**.
- [מאומת] מפרט: Ubuntu 24.04, x86_64, עד 8GB זיכרון, עד 10GB דיסק. PostgreSQL/Redis מותקנים ו**לא רצים כברירת מחדל**; tmux מותקן.
- [מאומת] `packages` (apt/cargo/gem/go/npm/pip) מותקנים לפני הסוכן ומקושים בין sessions של אותו environment.

## 4. Sessions  (sessions, session-operations)
- [מאומת] שני שלבים: create → שליחת `user.message`; או `initial_events` (עד 50, רק `user.message`/`user.define_outcome`) — מתחיל ישר ב-`running`.
- [מאומת] סטטוסים: `idle` (ברירת מחדל ללא initial_events), `running`, `rescheduling` (ניסיון חוזר אוטומטי לשגיאה חולפת), `terminated` (שגיאה בלתי הפיכה או archive). סשן שסיים עבודה → `idle`, לא terminated.
- [מאומת] Overrides בזמן יצירה בלבד (`agent_with_overrides`): `model`, `system`, `tools`, `mcp_servers`, `skills`; לא מתמזג — מחליף במלואו.
- [מאומת] **שינוי באמצע סשן: רק `agent.tools` ו-`agent.mcp_servers`** (מחליף מערך; חייב להיות idle; 400 אם workflow run פתוח). "Only the agent's tools and mcp_servers can change after a session is created" — `model`, `system`, `skills` ו-`inference_geo` נעולים. (session-operations)
- [מאומת] `system.message` event מוסיף הנחיית system באמצע סשן (רק במודלים נתמכים: Opus 5.5/5/4.8, Sonnet 5.5, Haiku 5.5, Fable/Mythos 5/5.1) — לא מחליף את ה-system של הסוכן. (events-and-streaming)
- [מאומת] Budget: רק ביצירה; `max_list_cost` בסנטים כמחרוזת; לא ניתן להוסיף אחר כך; ניתן לשנות/להסיר. (sessions, budgets)
- [מאומת] Archive/Delete: אסור על `running` — שולחים `user.interrupt` לבד וממתינים ל-idle. Delete מוחק רשומה, events ו-sandbox; קבצים שהסשן יצר נמחקים איתו.
- [מאומת] רשימת sessions: pagination עם `next_page`/`prev_page`, `order`, פילטר `agent_id`.

## 5. Events ו-streaming  (events-and-streaming, reference)
- [מאומת] תקשורת מבוססת אירועים; **SSE stream** ("streamed back through server-sent events"). Stream מקבל רק אירועים שנפלטו אחרי שנפתח → לפתוח stream לפני השליחה.
- [מאומת] Reconnect בלי לאבד אירועים: פותחים stream חדש, מושכים history (`events.list`) ומסננים כפילויות לפי id.
- [מאומת] אירועי משתמש: `user.message`, `user.interrupt`, `user.custom_tool_result`, `user.tool_confirmation`, `user.define_outcome`, `user.tool_result` (self-hosted בלבד). אירועי סוכן: `agent.message`, `agent.tool_use`, `agent.mcp_tool_use`, `agent.custom_tool_use`, `agent.thread_*`. אירועי session: `session.status_*`, `session.error`, `session.usage`.
- [מאומת] `agent.message` מגיע buffered (אחרי סיום בקשת המודל); לתצוגה תוך כדי יצירה — event deltas (opt-in, stream-only, לא נשמר).
- [מאומת] `session.status_idle` עם `stop_reason`: `end_turn`, `requires_action` (+`event_ids`), `budget_reached`.
- [מאומת] Interrupt: `user.interrupt` + `user.message` באותה קריאה; מסיים turn עם `end_turn`; עשוי להתעכב בזמן tool calls.
- [מאומת] המתנה ל-`requires_action` — "המסשן מחכה ללא הגבלת זמן" (permission-policies).

## 6. Webhooks  (webhooks)
- [מאומת] ה-webhook נושא רק `type` ו-`id` (לא האובייקט) — חובה GET. אירועי session: `session.status_run_started`, `status_idled`, `status_rescheduled`, `status_terminated`, `budget_reached`, `thread_*`, `outcome_evaluation_ended`, `updated`, `deleted`. גם vault/agent/deployment/environment/memory_store.
- [מאומת] שמות שונים מה-stream (`status_idled` מול `status_idle`).
- [מאומת] רישום ב-Console בלבד (עמוד Webhooks); URL חייב HTTPS פורט 443 ציבורי; secret `whsec_` נראה פעם אחת; חתימה standardwebhooks (`webhook-id/timestamp/signature`), `unwrap()` דוחה payload ישן מ-5 דקות.
- [מאומת] עד 3 ניסיונות, backoff מג'יטר 5–120 שניות, אחר כך האירוע **נזרק בלי איתות**. אין סדר מובטח. כפילויות אפשריות — dedupe לפי `event.id`. אין backfill למנוי מאוחר. 3xx → ה-endpoint מושבת מיד; כשלים ממושכים → disabled.
- [הסקה] webhooks אינם מקור אמת — חובה reconcile דרך list/GET.

## 7. Subagents / threads / workflows / outcomes / advisor  (multiagent-orchestration)
- [מאומת] `multiagent` בהגדרת הסוכן, סוג `multiagent_20261001`: `subagents`, `workflows` (דינמיים), `advisor` (כבוי כברירת מחדל). נתמך גם `coordinator` הישן.
- [מאומת] subagents: עומק רמה אחת; עד 20 סוכנים מוגדרים; עד 25 child threads במקביל; כל thread הוא session thread עם הקשר מבודד; **כולם חולקים sandbox, מערכת קבצים ואישורי vault**. thread של subagent נשאר זמין לפולואפ עד archive.
- [מאומת] workflows: הסוכן כותב תוכנית שהשרת מריץ ברקע כ-`workflow_run`; אין API נפרד להפעלה; אירועי `workflow_run.*`; מומלץ budget; הסוכן לא יכול לשלוח פולואפ ל-threads של run.
- [מאומת] advisor: מודל שהסשן מתייעץ בו; מחויב בתעריף מודל ה-advisor; ייתכן שתוכנו `redacted` ללקוח.
- [מאומת] outcomes: `user.define_outcome` עם rubric; אירועי `span.outcome_evaluation_*`; תוצאות `satisfied/needs_revision/max_iterations_reached/failed/interrupted`. (reference; הדף define-outcomes עצמו לא נקרא)
- [לא אומת] session-threads, workflow-runs, define-outcomes — לא נקראו במלואם.

## 8. Deployments (מתוזמנים)  (scheduled-deployments)
- [מאומת] Deployment = agent + environment + `schedule` (cron POSIX, IANA timezone, דיוק דקה) + לפחות initial event אחד. מגבלה 1,000 לארגון. ג'יטר בהרצה עד 15% מהמרווח (מינ' 5 שנ', מקס' 9 דק').
- [מאומת] `run` endpoint להרצה ידנית; pause/unpause/archive; unpause לא ממלא ריצות שהוחמצו. כל ניסיון מייצר `deployment_run` (כולל `session_rate_limited_error` — נרשם ללא retry, הניסיון הבא בטריגר הבא).
- [מאומת] DST: התאמת שעון קיר; 2:00 שלא קיימת — לא רצה; שעה כפולה — רצה פעמיים.
- [מאומת] budget לכל ריצה בנפרד.

## 9. Resources / קבצים / GitHub / זיכרון
- [מאומת] Deployment מקבל files, GitHub, memory stores, vaults; `file`/`github_repository` דורשים environment ענן. (scheduled-deployments)
- [לא אומת] הדפים files, github, memory לא נקראו.

## 10. Vaults  (vaults)
- [מאומת] workspace-scoped; אוסף credentials לכל משתמש-קצה; עד 20 credentials בוואלט. סוגים: `mcp_oauth` (Anthropic מרעננת טוקן), `static_bearer`, `environment_variable` (placeholder ב-sandbox, החלפה ב-egress; הסוכן לא רואה את הערך).
- [מאומת] התאמה לפי `mcp_server_url` (מנורמל); אין התאמה → ניסיון לא מאומת. הוואלט הראשון עם התאמה מנצח. credentials לא מאומתים עד runtime.
- [מאומת] סיבוב/ארכוב/מחיקה נקלטים בסשנים רצים (re-resolve תקופתי); ארכוב וואלט: סשנים רצים ממשיכים, חדשים נכשלים.
- [מאומת] `mcp_oauth_validate` מאבחן כשל ריענון: `valid`/`invalid`/`unknown`.

## 11. MCP  (mcp-connector, reference)
- [מאומת] שרתי MCP מוצהרים בסוכן (`mcp_servers`, עד 20, URL עד 2,048 תווים) ו-`mcp_toolset` תואם; האימות נוסף בסשן דרך `vault_ids`.
- [מאומת] תמיכה ב-MCP מרוחק עם streamable HTTP; SSE הישן עובד דרך fallback. פרטיים דרך MCP tunnels (research preview).
- [מאומת] **יצירת סשן לא בודקת קישוריות/אישורי MCP**; כשל → סשן מתחיל בכל זאת ונפלט `session.error` (`mcp_connection_failed_error` / `mcp_authentication_failed_error`) עם `retry_status`; חיבור מנוסה שוב במעבר idle→running. ב-environment `limited` — 400 אם host לא ב-allowed_hosts (אלא אם `allow_mcp_servers: true`).
- [מאומת] פלט MCP מעל 100,000 תווים נכתב לקובץ ב-sandbox עם preview.
- [הסקה] מכאן: "Connected"/סטטוס בקונסולה **אינו מוכיח** שכלי זמין בריצה; ההוכחה היחידה בתיעוד היא היעדר `session.error` ושימוש מוצלח בפועל. הדף לא מזכיר תווית "Connected" בקונסולה → [לא אומת] מה בדיוק היא מציגה.

## 12. הרשאות  (permission-policies)
- [מאומת] `always_allow` (ברירת מחדל ל-agent toolset), `always_ask` (ברירת מחדל ל-MCP), `auto` (השרת מעריך: allow/deny/ask). `user.tool_confirmation` עם allow/deny + `deny_message`. 400 אם מאשרים קריאה שלא `ask`. כלים מותאמים (custom) לא כפופים למדיניות.
- [מאומת] `evaluated_permission` + `evaluation` על כל `agent.tool_use`/`agent.mcp_tool_use`.
- [מאומת] `auto` אינו checkpoint אנושי — קריאה בטוחה רצה לפני שמישהו רואה.

## 13. כלי המערכת
- [מאומת] bash, read, write, edit, glob, grep, web_fetch, web_search; custom tools (שם לא יתחיל ב-`mcp__`/`ant__`).

## 14. עלויות, חיוב, ו-idle  (session-observability, budgets, reference)
- [מאומת] `usage` על הסשן: טוקנים, `list_cost` (מחיר רשימה, סנטים), `active_seconds`, `server_tool_use`. `session.usage` נפלט לפני כל מעבר ל-idle.
- [מאומת] list cost = טוקנים במחיר מודל + חיפוש רשת $10/1,000 + **זמן ריצת סשן $0.08 לשעה**. ("runtime cost is priced on this duration" של `active_seconds` = זמן שבו ל-thread אחד לפחות רץ.)
- [הסקה] מכאן, זמן idle אינו מחויב בעלות runtime (active_seconds סופר רק זמן ריצה). הדפים לא אומרים במפורש "idle בחינם" → סימון הסקה. עלות אחסון checkpoint — [לא אומת] שאינה מצוינת.
- [מאומת] Budget: תקרה קשה בין בקשות מודל; הבקשה שחוצה את התקרה מסתיימת (חריגה קלה); הסשן עובר idle עם `budget_reached`; בזמן התקרה רק settle events מתקבלים; `user.message` → 400. שינוי/הסרת budget ממשיך אוטומטית.
- [מאומת] Rate limits (ארגון): create (agents/sessions/environments) 300 בדקה; read (retrieve/list/stream) 1,200 בדקה; בנוסף spend limits ו-usage tiers.
- [מאומת] שגיאות/backoff: ראו billing-auth.md §5.
- [מאומת] שימוש נספר ב-Usage/Cost ב-Console; ניתן לפלח לפי `agent_id`/`session_id` ב-Usage and Cost Analytics API.

## 15. מצב idle וקיום sandbox  (events-and-streaming "Resume an idle session")
- [מאומת] היסטוריית שיחה נשמרת עד מחיקה מפורשת. "When a session goes idle, its sandbox is checkpointed" — נשמרים filesystem, חבילות מותקנות, קבצים.
- [מאומת] מצב ה-sandbox נשמר **30 ימים מיצירת ה-sandbox, פעילות לא מאריכה**; אחרי כן sandbox טרי.
- **[לא אומת] האם תהליך רקע (process) בתוך sandbox שורד idle** — הדף מתאר checkpoint של filesystem/חבילות/קבצים ולא מזכיר תהליכים חיים. [הסקה]: אין להניח שתהליך ממשיך לרוץ; אין התחייבות תיעודית. PostgreSQL/Redis מוכנים אבל כבויים כברירת מחדל.

## 16. שאלות שנבדקו במפורש
| שאלה | תשובה |
|---|---|
| להחליף מודל בסשן קיים? | **לא נתמך** [מאומת, session-operations]. רק override ביצירה; באמצע — `system.message` להנחיה בלבד. פתרון עקיף: סשן חדש (או thread חדש עם מודל אחר דרך predefined agent) [הסקה]. |
| tool call רץ שעובר בין מוצרים? | לא מתועד [לא אומת]. אין מנגנון להעברת סשן MA ל-Claude Code או להפך. |
| תהליך רקע ב-sandbox שורד idle? | [לא אומת] — ראו §15. |
| "Connected" בקונסולה מוכיח זמינות כלי? | [הסקה] לא; יצירת סשן לא מאמתת MCP. |
