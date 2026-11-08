# Remote Control (Claude Code) — מה ציבורי, ומה דורש תהליך חי

תאריך מחקר: 2026-11-08. מקור עיקרי: https://code.claude.com/docs/en/remote-control (נקרא במלואו). משלים: authentication, claude-code-on-the-web, cross-session-messaging.

## 1. מהו
- [מאומת] Remote Control מחבר את claude.ai/code ואפליקציית Claude (iOS/Android) ל**סשן Claude Code שרץ על המכונה שלך**. Claude ממשיך לרוץ מקומית כל הזמן; ביצוע קוד וגישה לקבצים נשארים על המכונה.
- [מאומת] "Your computer has to stay on and the `claude` process has to keep running" — הממשק web/mobile הוא חלון לסשן המקומי.
- [מאומת] שונה מ-cloud sessions (רצים בתשתית ענן). RC ≠ `--cloud`.

## 2. דרישות
- [מאומת] מנוי Pro/Max/Team/Enterprise; **מפתחות API לא נתמכים**. בצוות/Enterprise Owner חייב להפעיל toggle.
- [מאומת] התחברות claude.ai דרך `/login`. אם `ANTHROPIC_API_KEY`/`ANTHROPIC_AUTH_TOKEN`/`apiKeyHelper` בשימוש → שגיאה "Remote Control requires claude.ai subscription auth".
- [מאומת] **טוקן ארוך-טווח (`claude setup-token` / `CLAUDE_CODE_OAUTH_TOKEN`) לא מספיק** — "Remote Control requires a full-scope login token"; הטוקן "can only make model requests".
- [מאומת] לא זמין עם Bedrock/Vertex(Agent Platform)/Foundry, או כש-`ANTHROPIC_BASE_URL` מצביע ל-host שאינו api.anthropic.com (LLM gateway/proxy), או Claude apps gateway.
- [מאומת] לא זמין כש-`CLAUDE_CODE_DISABLE_NONESSENTIAL_TRAFFIC` או `DISABLE_GROWTHBOOK` מוגדרים. ארגוני ZDR/HIPAA לא יכולים להפעיל.
- [מאומת] Workspace trust נדרש; כשאין טרמינל (stdin/stdout לא TTY) הפקודה `claude remote-control` נכשלת עם `Workspace not trusted`.

## 3. מצבי הפעלה
- [מאומת] Server mode: `claude remote-control` — תהליך חי בטרמינל; ברירת מחדל עד 32 סשנים במקביל (`--capacity`), `--spawn same-dir|worktree|session`. נופל לאחר ~10 דקות ניתוק רשת (server mode); בסשן אינטראקטיבי מנסה מחדש כל עוד הניתוק נמשך.
- [מאומת] סשן אינטראקטיבי: `claude --remote-control` / `/remote-control`; סשן RC אחד לכל תהליך אינטראקטיבי.
- [מאומת] פקודות `-c/--continue`, `--session-id` להחזרת סשנים אחרי עצירה — עובדות כ-4 שעות אחרי שהשרת נעצר.
- [מאומת] `remoteControlAtStartup` להפעלה אוטומטית; ב-project/local settings נשמע רק `false`.

## 4. ארכיטקטורה ואבטחה
- [מאומת] הסשן יוצא ב-HTTPS בלבד, לא פותח פורטים נכנסים; נרשם ב-Anthropic API ו-polling לעבודה; השרת מנתב הודעות בין client לסשן המקומי בחיבור streaming.
- [מאומת] התמלול (הודעות, תגובות, פעילות כלים) **נשמר בשרתי Anthropic** כל עוד RC מחובר.
- [מאומת] Trusted Devices (בטא) — אופציונלי, דורש device enrollment וכניסה טרייה (עד 18 שעות).

## 5. מה נראה/נשלט מהמכשיר המרוחק
- [מאומת] שיחה, התקדמות subagents ו-dynamic workflows, diff, שינוי מודל (v2.1.238+) ו-effort; פקודות טקסט (`/compact`,`/clear`,`/context`,`/usage`,`/model`,`/effort`,`/mcp` וכו') עובדות, `/plugin` ו-`/resume` רק מקומית.
- [מאומת] קבצים/תמונות מהטלפון מורדים למכונה כהפניות `@`.
- [מאומת] push notifications לטלפון (Claude מחליט מתי; אפשר לבקש).
- [מאומת] authorize מחדש ל-connector דרך `claude mcp login "claude.ai X" --no-browser` בטרמינל של המכונה.
- [מאומת] דיאלוגים מועברים פג תוקף אחרי 5 דקות (`dialogExpiry`), פרט ל-permission prompts ו-AskUserQuestion שנשארים פתוחים עד תשובה.

## 6. מגבלות ידועות (מהדף)
- סשן אחד לתהליך אינטראקטיבי; מקומי חייב להישאר חי; סשן שקרס ב-server mode ממשיך לעבוד אחרי הודעה (v2.1.238+).
- 403 מרשת/VPN: ניסיונות עד 3 דקות ואז ניתוק; heartbeat שנכשל כ-30 דקות → ניתוק.
- סשן RC לא זמין בארגון עם `disableRemoteControl`.
- פקודת `/resume` מחליפה שיחה: המכשיר המרוחק לא מקבל כותרת/היסטוריה של השיחה שהוחלפה אליה (הודעות חדשות כן).

## 7. מה "ציבורי" (ומה לא)
- [מאומת] אין בדף API ציבורי/REST/SDK ל-RC: אין תיעוד של endpoint לרישום סשן RC ולא של פרוטוקול. הממשק הוא CLI + claude.ai/code + אפליקציה.
- [מאומת] טוקן `setup-token` לא יכול ליצור סשן RC — כלומר אינטגרציה צד שלישי עם מפתח/טוקן חיי לא תתמוך ב-RC.
- [הסקה] כל חיבור תכנותי אל RC יהיה שימוש בלתי מתועד → אסור לפי כללי המחקר ואין לתכנן עליו.
- [מאומת] המסלול התכנותי המתועד לשליחת הודעה לסשן קיים: **cloud sessions בלבד**: `claude -p "msg" --cloud <session-id>` — מתחיל מ-CLI מחובר claude.ai, מחזיר JSON `{ok, session_id, url}` עם `--output-format json`; לא ממתין לתשובה. (claude-code-on-the-web)
- [מאומת] לסשן RC (מקומי) אין מקביל מתועד ל"שלח הודעה" מבחוץ, מלבד cross-session messaging בין סשנים של אותו משתמש (ראו claude-code-routes.md §6) — שעובר דרך חיבור ה-RC לסשנים במכונות אחרות.

## 8. Remote Control מול חלופות (טבלת הדף)
Dispatch (מובייל→Desktop), Remote Control (שליטה בסשן רץ), Channels (Telegram/Discord/שרת שלך → סשן CLI), Slack, Self-hosted environments, Scheduled tasks (CLI/Desktop/ענן). [מאומת]

## 9. שאלות פתוחות
- האם tool call רץ ב-RC עובר בין מוצרים — [לא אומת].
- כיצד Channels (דף channels / channels-reference) מאפשר "הדחפת" אירועים מהשרת שלנו לסשן CLI חי — לא נקרא; מועמד לבדיקה נוספת.
