# מטריצת יכולות — תיעוד רשמי בלבד

תאריך מחקר: 2026-11-08. מקרא סטטוס: **Native** = יכולת מובנית במוצר · **API/SDK** = מתועד כ-API/SDK ציבורי · **אינטגרציה** = דורש בנייה שלנו מעל · **לא נתמך** = התיעוד אומר/מרמז שלא · **לא אומת** = לא נמצא/לא נקרא.
קיצורי URL: MA = https://platform.claude.com/docs/en/managed-agents/ ; CC = https://code.claude.com/docs/en/

## א. Claude Managed Agents (Platform)

| יכולת | סטטוס | URL | משפט תומך |
|---|---|---|---|
| Agents (משאב) | API/SDK | MA agent-setup | "reusable, versioned configuration" |
| גרסאות סוכן + pin | API/SDK | MA agent-setup, sessions | session יכול להצמיד `{"type":"agent","id","version"}`; עדכון בלי version = last-write-wins |
| Environments (cloud) | API/SDK | MA environments | sandbox נפרד לכל session; networking limited/unrestricted |
| Environments self-hosted | API/SDK | MA overview | "self-hosted sandbox on your own infrastructure" (דף self-hosted לא נקרא) |
| Sessions | API/SDK | MA sessions | "agent instance within an environment" |
| Override מודל ביצירה | API/SDK | MA sessions | `agent_with_overrides` מחליף model/system/tools/mcp/skills |
| **החלפת מודל בסשן קיים** | **לא נתמך** | MA session-operations | "Only the agent's `tools` and `mcp_servers` can change after a session is created" |
| הנחיית system באמצע סשן | API/SDK (חלקי) | MA events-and-streaming | `system.message` מוסיף הקשר; לא מחליף את ה-system של הסוכן; מודלים נתמכים בלבד |
| Deployments מתוזמנים | API/SDK | MA scheduled-deployments | "run an agent on a recurring cron schedule"; 1,000 לארגון |
| Resources: files/GitHub/memory | API/SDK | MA scheduled-deployments | deployment מקבל files, GitHub, memory stores (דפים עצמם לא נקראו) |
| Vaults | API/SDK | MA vaults | "register credentials ... once and reference them by ID" |
| MCP | API/SDK | MA mcp-connector | שרתים ב-agent, auth ב-session דרך `vault_ids` |
| **"Connected" ⇒ כלי זמין?** | **לא אומת (הסקה: לא)** | MA mcp-connector | "Session creation does not validate MCP connectivity or credentials" |
| הרשאות (policies) | API/SDK | MA permission-policies | always_allow / always_ask / auto + `user.tool_confirmation` |
| Events (SSE) | API/SDK | MA events-and-streaming | "stream events ... in real-time"; חייבים לפתוח stream לפני שליחה |
| Streaming deltas | API/SDK | MA reference | `event_start`/`event_delta` stream-only, opt-in |
| Webhooks | API/SDK (Console לרישום) | MA webhooks | "return the event `type` and `id`, not the full object"; 3 ניסיונות ואז נזרק |
| Subagents / threads | API/SDK | MA multiagent-orchestration | עד 25 child threads; שיתוף sandbox ו-vault |
| Workflows דינמיים | API/SDK | MA multiagent-orchestration | "dynamic workflows" — `workflow_run` ברקע; בלי API הפעלה נפרד |
| Outcomes | API/SDK | MA reference | `user.define_outcome` + rubric (הדף define-outcomes לא נקרא) |
| Advisor | API/SDK | MA multiagent-orchestration | `advisor: {type: enabled, model}` |
| Budgets | API/SDK | MA budgets | "hard spend ceiling"; רק ביצירה |
| Usage/עלות | API/SDK | MA session-observability | `usage.list_cost`, `active_seconds`, `session.usage` |
| Rate limits | Native | MA reference | create 300/דקה, read 1,200/דקה לארגון |
| sandbox שורד idle | API/SDK (filesystem בלבד) | MA events-and-streaming | "its sandbox is checkpointed" — filesystem/חבילות/קבצים; 30 יום |
| **תהליך רקע חי שורד idle** | **לא אומת** | MA events-and-streaming | התיעוד מזכיר רק filesystem state |
| **tool call רץ שעובר בין מוצרים** | **לא אומת / אין מנגנון** | — | אין התייחסות; MA ו-Claude Code הם מוצרים נפרדים |
| אימות מנוי | **לא נתמך** | MA overview | נדרש "Claude API key" מ-Console |
| ZDR / HIPAA | לא נתמך | MA overview | "Not ZDR eligible / Not HIPAA eligible" |

## ב. Claude Code

| יכולת | סטטוס | URL | משפט תומך |
|---|---|---|---|
| Interactive | Native | CC overview (לא נקרא) / sessions | סשנים נשמרים כתמלולים מקומיים |
| `-p` / headless | Native + CLI | CC headless | "Add the `-p` flag to any `claude` command to run it non-interactively" |
| Agent SDK | API/SDK (Py/TS) | CC agent-sdk/overview | "Embed Claude Code's agent in your own ... application" |
| resume / continue / fork | Native + SDK | CC sessions, agent-sdk/sessions | `--resume`, `--continue`, `--fork-session`; fork = היסטוריה, לא filesystem |
| החלפת מודל באמצע סשן | Native | CC remote-control, hooks | `/model`; hooks PreModelSwitch/PostModelSwitch |
| Hooks | Native | CC hooks | "user-defined shell commands, HTTP endpoints, MCP tool calls, LLM prompts, or subagents" |
| Permissions | Native + SDK | CC headless, permission-modes | `--allowedTools`, `--permission-mode`, `--permission-prompts none` |
| Remote Control | Native (CLI/אפליקציה בלבד, אין API ציבורי) | CC remote-control | "your computer has to stay on and the `claude` process has to keep running" |
| API ציבורי ל-RC | **לא אומת / לא קיים בתיעוד** | CC remote-control | מתועדים רק CLI ו-claude.ai/אפליקציה |
| Cloud sessions (רקע) | Native | CC claude-code-on-the-web | "keeps running after you close your laptop" |
| שליחת הודעה תכנותית לסשן ענן | CLI בלבד | CC claude-code-on-the-web | `claude -p "msg" --cloud <session-id>` |
| teleport ענן→טרמינל | Native (חד-כיווני) | CC claude-code-on-the-web | "you can't push an existing terminal session to the cloud" מה-CLI |
| Scheduled tasks / Routines | Native (research preview) + HTTP fire | CC routines | טריגרי schedule/API/GitHub; header `experimental-cc-routine-2026-04-01` |
| Background sessions | Native | CC sessions | `claude attach`, `/resume` מעביר לרקע (דף agent-view לא נקרא) |
| Cross-session messaging | Native (בין סשני אותו משתמש בלבד) | CC cross-session-messaging | "A message is a piece of text one Claude writes to another" |
| Agent teams | Native, ניסיוני, interactive בלבד | CC agent-teams | ב-`-p`/SDK "Claude doesn't spawn teammates" |
| Subagents | Native | CC sub-agents (לא נקרא), headless | הודעות subagent נראות ב-stream עם `parent_tool_use_id` |
| GitHub Action | Native (כלי רשמי) | CC github-actions | `anthropics/claude-code-action@v1` |
| Channels | לא אומת (לא נקרא) | CC remote-control (טבלה) | "Push events from a chat app ... or your own server" |

## ג. אימות וחיוב

| נושא | סטטוס | URL | משפט תומך |
|---|---|---|---|
| מנוי מכסה Claude Code הרשמי (כולל `-p`/SDK כשנכנסים עם המנוי) | Native | support.claude.com/en/articles/15036540 | "still draw from your subscription limits" |
| מנוי מכסה אפליקציית צד שלישי/מוצר שלנו | **לא נתמך** | CC legal-and-compliance | אסור "to route requests through Free, Pro, or Max plan credentials on behalf of their users" |
| מנוי מכסה Managed Agents / Messages API | **לא נתמך** (נדרש API key) | MA overview; support 15036540 | credits חודשיים של התוכנית חלים רק עם API key מארגון Console מקושר |
| `claude setup-token` / `CLAUDE_CODE_OAUTH_TOKEN` | Native (model-only) | CC authentication | "can only make model requests" — לא RC |
| GitHub Action עם מנוי/מפתח | Native | CC github-actions | `CLAUDE_CODE_OAUTH_TOKEN` או `ANTHROPIC_API_KEY` |
| חיוב MA | API/SDK | MA session-observability, budgets | טוקנים + $0.08/שעה ריצה + web search |
| עלות idle MA | **לא אומת** (הסקה: ללא runtime) | MA session-observability | runtime מתומחר על `active_seconds` |
| קודי 429/529 והמלצת backoff | Native | https://platform.claude.com/docs/en/api/errors | SDK מנסה שוב פעמיים עם exponential backoff ומכבד `retry-after` |

## ד. החלטות מרכזיות לאפיון
1. **החלפת מודל בסשן MA**: לא קיימת — מתכננים סשן/thread חדש או `system.message`.
2. **תהליכי רקע ב-sandbox**: אין התחייבות; לא לבנות על שרידות תהליך — לבנות על filesystem + אירועים + webhooks/polling.
3. **"Connected"**: לא אות אמין; לבדוק בריצה (`session.error`, שימוש בפועל).
4. **RC**: דורש תהליך חי ו-login מלא של מנוי; אין API ציבורי — לא משתלב כ-backend.
5. **מנוי מול API**: אפליקציה שלנו מול Platform = API key.
