# חיוב, אימות, מגבלות ו-ToS — לפי תיעוד רשמי

תאריך מחקר: 2026-11-08. הקשר חשוב: "Platform" = שלושה דברים שונים (Messages API / Agent SDK / Managed Agents). הטבלה מפרידה.
תיוג: [מאומת] נקרא בדף רשמי · [הסקה] · [לא אומת]. אתרי צד שלישי שנראו בחיפוש **לא** משמשים כעובדה (ראו sources.md).

## 1. טבלת מסלולים: מי משלם ועל מה מוגבל

| מסלול | אימות | חיוב | מגבלות | מקור |
|---|---|---|---|---|
| Managed Agents (Platform) | **מפתח API** מ-Console (header בטא) | טוקנים במחיר רשימה + $0.08/שעת ריצה (active_seconds) + $10/1,000 web search | 300 create/דקה, 1,200 read/דקה לארגון + tiers/spend limits | overview, budgets, reference |
| Messages API ישיר | מפתח API | לפי טוקנים | RPM/ITPM/OTPM לפי tier ולפי מחלקת מודל; spend cap חודשי | api/errors, api/rate-limits (קטע) |
| Agent SDK עם API key | מפתח API | כמו Messages API (בצד שלך) | כמו Messages API | agent-sdk/overview |
| Agent SDK / `claude -p` עם התחברות מנוי | OAuth מנוי | **נספר במגבלות המנוי** כרגע | חלון סשן 5 שעות + שבועי משותפים | Help Center 15036540 |
| Claude Code אינטראקטיבי | מנוי או API key/Console | מנוי: מכסה; API: טוקנים | כנ"ל | authentication, costs |
| Cloud sessions / Routines | מנוי בלבד | נספר במגבלות המנוי; אין חיוב VM; מעבר למכסה — usage credits אם הופעלו | rate limits משותפים; Routines 100/שעה וכו' | claude-code-on-the-web, routines |
| Remote Control | מנוי claude.ai בלבד (לא API, לא setup-token) | ריצה מקומית; מנוי | — | remote-control |
| GitHub Action | API key / OAuth token של מנוי / federation | דקות Actions + טוקנים (או מנוי עם OAuth) | — | github-actions |
| Bedrock/Vertex/Foundry | אישורי ענן | חיוב ספק הענן | — | authentication, costs |

## 2. האם מנוי מכסה שימוש ב-API/SDK של צד שלישי?  (ToS)
- [מאומת] Legal and compliance (https://code.claude.com/docs/en/legal-and-compliance): OAuth של מנוי "intended exclusively for purchasers of Claude Free, Pro, Max, Team, and Enterprise subscription plans" ונועד ל"ordinary use of Claude Code and other native Anthropic applications".
- [מאומת] אותו דף: מפתחים שבונים מוצרים/שירותים, **כולל Agent SDK**, "should use API key authentication through Claude Console or a supported cloud provider". Anthropic **לא מתירה** לצד שלישי להציע התחברות Claude.ai באפליקציות שלו, או "to route requests through Free, Pro, or Max plan credentials on behalf of their users". וכן אסור לאסוף/לאחסן/לתווך credentials או session tokens של Claude.ai — הכניסה חייבת להסתיים בזרימה של Anthropic.
- [מאומת] מותר במפורש: משתמש-קצה שנכנס עם המנוי שלו לבינארי Claude Code **לא מושנה** (גם כשפלטפורמה מארחת את Claude Code). מותר לקבוע מפתח API במכונה/secrets manager לשימוש משתמשים מורשים של הלקוח כשהחיוב לבעל המפתח.
- [מאומת] הארחת Claude Code במוצר שלך דורשת Commercial Terms: אסור לשנות את הבינארי; אסור להסיר/להשבית שיטת אימות מובנית; אסור לשלם/למכור/לתווך שימוש בשם משתמשי הקצה; כל משתמש מאמת עם אישוריו.
- [מאומת] "Advertised usage limits for Pro and Max plans assume ordinary, individual usage of Claude Code and the Agent SDK."
- [מאומת] Agent SDK overview: "Unless previously approved, Anthropic does not allow third party developers to offer claude.ai login or rate limits for their products".
- [מאומת] Anthropic שומרת את הזכות לאכוף "without prior notice"; לשאלות — contact sales.
- [מאומת] Help Center 15036540 ("Use the Claude Agent SDK with your Claude plan"): "Update October 7, 2026: ... Nothing changes for your plan: when you're signed in with your Claude plan, the Claude Agent SDK and `claude -p` still draw from your subscription limits." ו-"Update June 15, 2026: We've paused the previously-announced changes to Claude Agent SDK usage... Claude Agent SDK, `claude -p`, and third-party app usage still draw from your subscription limits."
- [מאומת] אותו דף: תוכניות Max, Ultra, Team כוללות כעת **monthly API credits** המכסים Claude API, Managed Agents, Agent SDK ו-`claude -p` **כשרצים עם API key מארגון Console מקושר**. (סכומים/תנאים: במאמר 17154008 — [לא נקרא].)
- [הסקה] שילוב: (א) סקריפט/עובד אישי שמריץ את `claude` הרשמי עם התחברות המנוי שלך — נראה מותר ונספר במנוי; (ב) אפליקציה שלנו שקוראת ל-Managed Agents/Messages API — מחייבת API key (אפשר להיעזר ב-credits של התוכנית); (ג) אפליקציה שאוספת OAuth של משתמשים אחרים או מנתבת דרך מנוי — אסור. מצב "מוצר שאתה בעלים ומשתמש יחיד" — אפור: התיעוד מדבר על "building products or services" ו"ordinary, individual usage"; ללא התחייבות שהכל מותר. לשאלות אישור — sales.
- [לא אומת] עמדה מפורשת על "אוטומציה אישית של בעל המנוי נגד Claude Code בלילה" מעבר לאמור למעלה.
- הערה: נוסח עמוד ה-legal השתנה לאורך 2026 לפי מקורות צד שלישי — אין בידי השוואת גרסאות רשמית; הנוסח לעיל הוא מה שנקרא ב-2026-11-08.

## 3. אימות ב-Claude Code  (authentication.md)
- [מאומת] סדר עדיפויות: (1) ספק ענן (`CLAUDE_CODE_USE_*`), (2) `ANTHROPIC_AUTH_TOKEN`, (3) `ANTHROPIC_API_KEY` (ב-`-p` תמיד נעשה בו שימוש כשקיים; באינטראקטיבי נדרש אישור), (4) `apiKeyHelper`, (5) `CLAUDE_CODE_OAUTH_TOKEN`, (6) פרופילי Anthropic/federation, (7) OAuth מנוי מ-`/login`. סשן gateway מנצח את כולם.
- [מאומת] `claude setup-token` מוציא טוקן OAuth לשנה (לא נשמר; אתה מעתיק); דורש Pro/Max/Team/Enterprise; **רק בקשות מודל** — לא Remote Control ולא claude.ai connectors; `--bare` לא קורא אותו.
- [מאומת] `ANTHROPIC_API_KEY` ו-`ANTHROPIC_AUTH_TOKEN` חלים על CLI, VS Code, Agent SDK ו-GitHub Actions; Claude Desktop וcloud sessions לא קוראים אותם (OAuth).
- [מאומת] כש-`ANTHROPIC_API_KEY` מוגדר ובמקביל יש מנוי — המפתח מנצח (אחרי אישור) → חיוב API במקום מנוי; `/status` מראה מה פעיל.
- [מאומת] התחברות מנוי מתקרבת לתפוגה → אזהרה 3 ימים מראש; כשפגה: כל בקשת מודל נכשלת (`Login expired`). **סשן רקע או Remote Control שהתחברותו פגה מפסיק להתקדם ואינו מתאושש עד `/login`** — רלוונטי לריצות לילה.
- [מאומת] אחסון credentials: macOS Keychain / `~/.claude/.credentials.json` (0600); `CLAUDE_CONFIG_DIR` לריבוי חשבונות.
- [מאומת] `forceLoginMethod`, `forceLoginOrgUUID`, `allowedProviders` בהגדרות מנוהלות.

## 4. חיוב Managed Agents ועלות idle
- [מאומת] מחיר רשימה = טוקנים לפי מודל + web search + ריצה ($0.08/שעה על `active_seconds`). `list_cost` מעוגל לסנטים; החיוב בפועל יכול להיות נמוך (הנחות חוזיות).
- [הסקה] סשן idle: `active_seconds` סופר זמן בו thread רץ → idle לא צובר עלות runtime. עלות אחסון checkpoint/sandbox — לא מצוינת [לא אומת].
- [מאומת] אחסון: sandbox נשמר 30 יום מיצירתו; היסטוריה עד מחיקה.
- [מאומת] cache: TTL 5 דקות ברירת מחדל — turn אחרי הפסקה ארוכה משלם cache-miss (session-observability). ב-Claude Code: ההודעה הראשונה אחרי הפסקה גדולה מ-cache lifetime מעבדת מחדש את כל ההקשר (costs).
- [מאומת] מקורות עלות נסתרים ב-Claude Code (costs.md): scheduled tasks בתוך סשן שורפים הקשר מלא גם ב-idle; הודעות cross-session שמתחילות turn ב-idle; goal check-ins (עד 3 ב-idle); subagents/workflows/teammates; compaction.
- [מאומת] שליטה: Managed Agents `budget`; Claude Code `--max-budget-usd`, `--max-turns`; `/usage`; Console Usage/Cost; OpenTelemetry.

## 5. שגיאות, קודי rate limit ו-backoff  (platform.claude.com/docs/en/api/errors, rate-limits; code.claude.com errors)
- [מאומת] טבלת שגיאות API: 400 invalid_request_error; 401 authentication_error; 402 billing_error; 403 permission_error; 404 not_found_error; 409 conflict_error; 413 request_too_large; **429 rate_limit_error**; 500 api_error; 504 timeout_error; **529 overloaded_error**. (api/errors)
- [מאומת] 429: ארגון חרג ממגבלת קצב / תקרת הוצאה חודשית של ה-tier / spend limit על workspace של Claude Code. **429 של spend cap אין לו `retry-after` ונמשך עד שהגישה חוזרת**; בגוף `error.details.error_code = "enforced_spend_limit_reached"` (rate-limits). קצב: RPM/ITPM/OTPM לפי מחלקת מודל; `retry-after` מציין שניות להמתין.
- [מאומת] 529 = עומס בכל המשתמשים; 429 עקב acceleration limits בעקבות זינוק חד בתעבורה — "increase your traffic gradually".
- [מאומת] ה-SDK הרשמי מנסה שוב אוטומטית (שגיאות חיבור, rate limits, 5xx) עם exponential backoff, **פעמיים כברירת מחדל**, ומכבד `retry-after`; `max_retries` ניתן להגדרה. כל תגובה כוללת `request-id` (גם בגוף `request_id`) — לכלול בפנייה לתמיכה.
- [מאומת] stream SSE: שגיאה יכולה להגיע אחרי 200 כאירוע error.
- [מאומת] ב-Claude Code: `system/api_retry`; 529 "לא נספר מול המכסה"; מומלץ `/model` למודל אחר כי קיבולת נמדדת למודל; 429 של spend limit ב-gateway מסומן `x-should-retry: false`. הודעות "You've hit your session/weekly limit" = חלון מנוי משותף לכל המודלים (מעבר Opus→Sonnet פותר רק במגבלות ספציפיות למשפחה) (costs.md).
- [מאומת] Managed Agents: 300 create/דקה ו-1,200 read/דקה לארגון; deployment: rate limit ביצירת session נרשם `session_rate_limited_error` ללא retry עד הטריגר הבא; webhooks: 3 ניסיונות 5–120 ש' ואז נזרק.
- המלצת backoff מהתיעוד: exponential backoff, honor `retry-after`, ramp הדרגתי; אין בדף ההמלצה על jitter מפורש ("jitter" מופיע רק בהקשר ג'יטר webhooks/deployments) — jitter מומלץ כהסקה הנדסית [הסקה].
- המלצת קיבולת Claude Code (costs): טבלת TPM/RPM למשתמש לפי גודל צוות (למשל 1–5 משתמשים 200k–300k TPM).

## 6. עלות מנוי ו-credits
- [מאומת] Claude Code בצוותים: ניצול מקצה בסיס של "seat allowance" בחלון מתגלגל של 5 שעות + שבועי, משותף עם Claude chat ו-Cowork; עודף דרך usage credits והגדרת spend limits. `/usage-credits` (אין עם API key).
- [מאומת] Cloud sessions: אין חיוב נפרד ל-VM; נספרים מול המגבלות.
- [מאומת] Routines: אין overage על מגבלות השעתיות; עם usage credits מופעל — המשך על metered overage.
- [מאומת] Enterprise Analytics API / Claude Code Analytics API / OpenTelemetry לניטור.

## 7. תוצאות לאפיון
1. לבנות על מפתח API (Platform) כל מה שהוא "מוצר/שירות" — זה ההנחיה הרשמית.
2. שימוש במנוי: רק כהפעלת בינארי Claude Code הרשמי על ידי בעליו (אינטראקטיבי, `-p`, RC, ענן, routines).
3. עלות idle ב-Managed Agents: runtime לא מחויב ב-idle (הסקה); עלות הטוקנים רק בעבודה בפועל; budget לריצות לילה.
4. אימות לילה: להגדיר התראה על פקיעת login; `setup-token` מתאים לריצות model-only אך לא RC.
