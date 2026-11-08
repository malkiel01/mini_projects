# מקורות — נקראו בפועל ב-web_fetch (תאריך מחקר 2026-11-08)

## Platform (Managed Agents / API) — נקראו במלואם
- https://platform.claude.com/docs/en/managed-agents/overview
- .../managed-agents/sessions
- .../managed-agents/session-operations
- .../managed-agents/events-and-streaming
- .../managed-agents/webhooks
- .../managed-agents/multiagent-orchestration
- .../managed-agents/mcp-connector
- .../managed-agents/agent-setup
- .../managed-agents/environments
- .../managed-agents/cloud-sandboxes-reference
- .../managed-agents/scheduled-deployments
- .../managed-agents/vaults
- .../managed-agents/permission-policies
- .../managed-agents/reference (event types, rate limits)
- .../managed-agents/session-observability
- .../managed-agents/budgets
- .../managed-agents/tools
- https://platform.claude.com/docs/en/api/errors

## Claude Code / Agent SDK — נקראו
- https://code.claude.com/docs/en/remote-control (מלא)
- https://code.claude.com/docs/en/headless
- https://code.claude.com/docs/en/agent-teams.md
- https://code.claude.com/docs/en/cross-session-messaging.md
- https://code.claude.com/docs/en/claude-code-on-the-web.md
- https://code.claude.com/docs/en/routines.md
- https://code.claude.com/docs/en/sessions.md
- https://code.claude.com/docs/en/github-actions.md
- https://code.claude.com/docs/en/costs.md
- https://code.claude.com/docs/en/authentication.md
- https://code.claude.com/docs/en/legal-and-compliance
- https://code.claude.com/docs/en/agent-sdk/overview.md
- https://code.claude.com/docs/en/hooks.md — **נקרא חלקית** (הכלי חתך ~2,996 שורות; נקראו: lifecycle, תצורה, matcher, handlers, exit codes, onFailure, JSON output, decision control; **לא נקראו** סעיפי האירועים הפרטניים)
- https://support.claude.com/en/articles/15036540-use-the-claude-agent-sdk-with-your-claude-plan (Help Center רשמי; הדף כולו קצר: עדכונים 15 ביוני ו-7 באוקטובר 2026)

## נקראו רק כקטעי תוצאות חיפוש מהמקור הרשמי (לא כדף מלא)
- https://code.claude.com/docs/en/agent-sdk/sessions ו-https://platform.claude.com/docs/en/agent-sdk/sessions — קטע על continue/resume/fork/SessionStore
- https://platform.claude.com/docs/en/api/rate-limits — קטעים על RPM/ITPM/OTPM, retry-after, acceleration limits, `enforced_spend_limit_reached`
- https://code.claude.com/docs/en/errors — קטעי 529/429/spend limit

## לא נקראו / לא ניתן היה לקרוא (לא אומת)
- ניסיון ראשון נכשל ב-`url_not_in_prior_context` (נחסם ע"י הכלי): managed-agents/overview (כשלא היה בהקשר), code.claude.com/docs/en/headless ו-agent-teams בלי `.md` — נפתרו אחר כך דרך חיפוש/קישורים.
- Managed Agents: choosing-an-environment, self-hosted-sandboxes (+ reference/custom-tools), session-threads, workflow-runs, define-outcomes, github, files, memory, skills, event-deltas, secure-deployment*, quickstart, migration, onboarding, tools-web-restrictions.
- Claude Code: agent-view, channels, workflows, sub-agents, permission-modes, settings-reference, env-vars, cli-reference, cloud-environments, mcp, agent-sdk/{hooks,permissions,python,typescript,quickstart,cost-tracking}.
- Help Center 17154008 (Monthly API credits) — פרטי הסכומים והתנאים לא נקראו.
- platform.claude.com/docs/en/api/rate-limits כדף מלא; platform .../manage-claude/authentication; Console עצמו (אין גישה) — לכן **"Connected" בקונסולה** לא ניתן לאימות.
- דף ToS צרכני/Commercial (anthropic.com/legal/*) — לא נקראו; נשענתי על דף Legal של Claude Code.

## נראו בתוצאות חיפוש ולא שימשו כעובדה (צד שלישי)
VentureBeat, The Register, The New Stack, winbuzzer, gigazine, autonomee.ai, yage.ai, dev.to, runpane, apidog, totalum, developersdigest, startupfortune, buildthisnow, claudefolio, ofox.ai, aifreeapi, rapidevelopers, hidekazu-konishi, amux.io, binh-ai, github mirrors וכו'. הערה: חלקם מדווחים על שינויי נוסח היסטוריים ב-legal page ועל סכומי credits — אינם נמצאים בדפים הרשמיים שנקראו ולכן **לא נכללו**.

## מגבלות כלליות
- מבנה ה-docs משתנה (תיעוד מצביע על שינויים בגרסאות Claude Code, למשל v2.1.2xx–v2.1.309). נוסחים אלה נכונים ל-2026-11-08.
- המחקר קרא תיעוד; לא הופעל שום API ולא נבדק Console.
