# 09 — חיבור ישיר של ChatGPT (ו-Claude): שרת MCP מרוחק עם OAuth

תלויה ב-: 05 (`extActions()` / `extDo()`), 01 (משתמשים, חברים, `events`). דרישה: D4. שאלה: ש7.
הקשר: `08-fixes-project.md` (מסך 🤖, המסלול "Plugin — בקרוב"); `team/decisions.md`, השורה על ש5 (24.11).

## 1. המטרה

הבעלים מוסיף ב-ChatGPT (בדפדפן) "שרת MCP מותאם" עם כתובת אחת, מתחבר פעם אחת עם החשבון שלו באפליקציה — ומאותו רגע
כל שיחה יכולה "תוסיף לכהן: רישיון קבורה 12345", בלי קובץ JSON ובלי 📥. אותו שרת עובד גם כ-connector של Claude.

## 2. מה ידוע על ChatGPT (נבדק מול התיעוד הרשמי, 24.11)

| | מה | מקור |
| --- | --- | --- |
| ✅ | "Authentication options include OAuth, No authentication, and OAuth or no authentication" | developers.openai.com/api/docs/guides/developer-mode |
| ✅ | **אסימון קבוע / API key — לא נתמך:** ChatGPT "does not support machine-to-machine OAuth grants … nor can it present custom API keys" | developers.openai.com/apps-sdk/build/auth |
| ✅ | לקוחות: CIMD (Client ID Metadata Document), DCR, לקוח מוגדר מראש; PKCE עם `S256` חובה | שם |
| ✅ | פרוטוקול: Streamable HTTP או SSE; כתובת ציבורית "including the /mcp path" | developer-mode |
| ✅ | ההגדרה: ChatGPT בדפדפן ← chatgpt.com/plugins ← ➕ ← Add custom MCP server | developer-mode |
| ✅ | אין חובה לכלים `search` / `fetch`; כלי כתיבה דורש אישור משתמש ב-ChatGPT, אלא אם מסומן `readOnlyHint` | developer-mode |
| ✅ | 512 התווים הראשונים של `instructions` בשרת הם הקובעים | developer-mode |
| ❓ | אילו מנויים (Plus / Pro / Business) רשאים להוסיף שרת מותאם | לא נמצא בתיעוד — **מטלה לבעלים: לבדוק שהכפתור קיים אצלו** |
| ❓ | "Plugin אחד ↔ שרת MCP אחד" | לא נמצא בתיעוד; לא משנה לנו — יש לנו שרת אחד |
| ❓ | תאריך הסגירה של Custom GPT (11.12.2026) | מאמר משני, לא דף של OpenAI |

**המסקנה:** ChatGPT לא יודע לשלוח את אסימון `fdx_` של 05. כדי שיכתוב באפליקציה — **OAuth**. (ש7)

## 3. מה בונים

### 3.1 שרת ה-MCP — `mcp.php`

1. **כתובת:** `https://mbe-plus.com/mini_projects/funerals-dashboard/mcp` (כלל rewrite ל-`mcp.php`; גם `mcp.php` עונה).
2. **Streamable HTTP, בלי SSE:** `POST` עם JSON-RPC 2.0 (בקשה אחת או מערך) → תשובה `application/json`. `GET` → 405.
   `notifications/*` → 202 בלי גוף. אין session בשרת (`Mcp-Session-Id` לא נדרש). בדיקת `Origin`: אם קיימת ואינה
   `https://chatgpt.com` / `https://claude.ai` / `https://claude.com` / הדומיין שלנו → 403.
3. **שיטות:** `initialize` (גרסאות פרוטוקול נתמכות: `2025-06-18` ו-`2025-11-25`; עונים בגרסה שהלקוח ביקש אם נתמכת,
   אחרת בחדשה), `ping`, `tools/list`, `tools/call`. כל השאר → `-32601`.
4. **`instructions`:** גרסה קצרה של `extPrompt()` — 512 התווים הראשונים: מה האפליקציה, `find` לפני עדכון, אף פעם לא
   לנחש, "מאומת" נשמר "לאימות", עברית.
5. **הכלים = `extActions()`**, מקור אחד (כמו `openapi.json`):
   - שם הכלי = שם הפעולה (`find`, `case`, `case_update`…), חוץ מ-`prompt` (לא כלי — הוא ה-`instructions`).
   - `inputSchema` = `query` + `body` של הפעולה, ב-JSON Schema; `description` = `en` ואז `he`.
   - `annotations`: `GET` → `readOnlyHint: true`; `POST` → `readOnlyHint: false`, `destructiveHint: false`,
     `idempotentHint: false`, `openWorldHint: false`.
   - `tools/call` → `extDo($pdo, $action, $args, 'api')` — **אותן הרשאות, אותו "לאימות", אותו יומן**. אין כלי מחיקה.
   - תוצאה: `structuredContent` = מה ש-`extDo` החזיר, ו-`content: [{type:"text", text:<אותו JSON>}]`.
     `ExtError` → `isError: true` עם ההודעה בעברית (לא שגיאת JSON-RPC), כדי שהצ'אט יראה ויסביר.
6. **הרשאה:** `initialize`, `ping` ו-`tools/list` — גם בלי אסימון (ChatGPT קורא אותם לפני ההתחברות). `tools/call` בלי
   אסימון תקין → **HTTP 401** עם
   `WWW-Authenticate: Bearer resource_metadata="<base>/.well-known/oauth-protected-resource"`.
   אסימון תקין בלי הרשאה לפעולה → `isError` עם ההודעה של `extDo` (403).
7. יומן הבקשות של 01: `mcp:<method>:<tool>`, סטטוס, זמן, משתמש — אף פעם לא האסימון.
8. 60 קריאות לדקה לחיבור (כמו 05); מעבר → `isError` "יותר מדי בקשות, נסה בעוד דקה".

### 3.2 שרת ההרשאה (OAuth 2.1) — בתוך התיקייה

| כתובת | ציבורית? | מה |
| --- | --- | --- |
| `.well-known/oauth-protected-resource` | כן | RFC 9728: `resource` = כתובת ה-MCP, `authorization_servers` = [`<base>`], `scopes_supported` = [`cases`] |
| `.well-known/oauth-authorization-server` ו-`.well-known/openid-configuration` | כן | RFC 8414: `issuer` = `<base>`, נקודות הקצה למטה, `code_challenge_methods_supported: ["S256"]`, `grant_types_supported: ["authorization_code","refresh_token"]`, `token_endpoint_auth_methods_supported: ["none"]`, `client_id_metadata_document_supported: true` |
| `oauth/register.php` | כן | DCR (RFC 7591): לקוח ציבורי בלבד; `redirect_uris` רק `https`; עד 20 רישומים לשעה לכתובת IP |
| `oauth/authorize.php` | **לא — מאחורי השער** | ראו 3.3 |
| `oauth/token.php` | כן | `authorization_code` (עם `code_verifier`) ו-`refresh_token` |
| `oauth/revoke.php` | כן | RFC 7009 |

`<base>` = `https://mbe-plus.com/mini_projects/funerals-dashboard`.

**⚠️ סיכון — מיקום המטא-נתונים:** לפי RFC 8414, ל-issuer עם נתיב המטא-נתונים יושבים ב-
`https://mbe-plus.com/.well-known/oauth-authorization-server/mini_projects/funerals-dashboard` — **מחוץ לתיקייה**. לקוחות
MCP מנסים גם `<issuer>/.well-known/openid-configuration` (בתוך התיקייה), ו-`resource_metadata` שבכותרת 401 מכוון ישר
אלינו. אם ChatGPT לא מוצא אותם (ק11) — **בקשה לפלטפורמה** (`.claude/teams/requests/`, של הבקרה): הפניה ב-
`/.well-known/oauth-authorization-server/mini_projects/<app>` אל `<app>/.well-known/oauth-authorization-server`, כללית
לכל אפליקציה. לא נוגעים בזה מכאן.

**לקוחות:**

- **CIMD** — `client_id` שהוא כתובת `https`: השרת מוריד את המסמך (רק `https`, 5 שניות, 64KB, בלי כתובות IP פרטיות או
  מקומיות, בלי הפניות), בודק ש-`client_id` במסמך זהה לכתובת וש-`redirect_uri` ברשימה שלו. מטמון שעה.
- **DCR** — `client_id` אקראי (`fdc_…`) שנשמר עם `client_name` ו-`redirect_uris`.
- **לקוח מוגדר מראש** — לא בפרוסה הזו.

### 3.3 מסך ההסכמה — `oauth/authorize.php`

1. מאחורי השער: מי שלא מחובר לפלטפורמה — עובר בכניסה הרגילה וחוזר לכאן עם כל הפרמטרים.
2. הזהות — מ-`PLATFORM_USER` → המשתמש באפליקציה (01). **המשתמשים של האפליקציה, לא של הפלטפורמה:** משתמש בלי חברות
   פעילה בארגון → "אין לך ארגון באפליקציה" ובלי קוד.
3. בדיקות לפני המסך: `response_type=code`; `client_id` מוכר (DCR) או מסמך CIMD תקין; `redirect_uri` תואם **בדיוק**;
   `code_challenge` + `S256`; `state` קיים; `resource` (אם נשלח) = כתובת ה-MCP. כשל → דף שגיאה, **בלי** הפניה ל-
   `redirect_uri` שלא אומת.
4. **המסך** (phone-first): "**<client_name>** (`<host של redirect_uri>`) מבקש גישה לתיקים של **<ארגון>** בשמך:
   לקרוא תיקים וקבצים, ולעדכן — עדכון נשמר 'לאימות'. אין מחיקה." בורר ארגון אם יש יותר מאחד. "אישור" / "ביטול".
   ההרשאות = התפקיד שלך בארגון, ברגע כל בקשה (כמו `fdx_`).
5. אישור → קוד חד-פעמי (60 שניות), קשור ללקוח, `redirect_uri`, משתמש, ארגון, `code_challenge` ו-`resource`.
   ביטול → `error=access_denied`. אישור נרשם ב-`events` (`action: oauth_grant`).
6. CSRF: הטופס נשלח עם אסימון CSRF של ה-session (01).

### 3.4 אסימונים

- **גישה:** `fdo_…`, שעה. **רענון:** `fdr_…`, 30 יום, מתחלף בכל שימוש; שימוש חוזר ברענון ישן → כל המשפחה מבוטלת.
- נשמרים רק כ-SHA-256, בטבלה `oauth_grants` (משתמש, ארגון, לקוח, `client_name`, נוצר, שימוש אחרון, בוטל) ו-
  `oauth_tokens` (hash, סוג, grant, תפוגה).
- אסימון תקף רק ל-`resource` של ה-MCP. `mcp.php` מקבל **גם** `fdx_` של 05 (ל-Inspector ולבדיקות) — זה לא מחליש,
  כי `fdx_` כבר מקנה את אותו דבר ב-`ext.php`.
- משתמש שהושבת / הוצא מהארגון → כל ה-grants שלו בארגון לא עובדים מיד (נבדק בכל בקשה, כמו 05).
- **ביומן:** `via: api`, `actor` = "<שם> (<client_name>)" — למשל "משה כהן (ChatGPT)".

### 3.5 מסך 🤖 (מ-08 P1)

המסלול "Plugin — בקרוב" נהיה פעיל: הכתובת `…/funerals-dashboard/mcp` עם 📋, וחמישה צעדים: ChatGPT בדפדפן ←
chatgpt.com/plugins ← ➕ Add custom MCP server ← להדביק את הכתובת, Authentication: **OAuth** ← "Connect" ומסך ההסכמה
שלנו. אותו דבר ל-Claude: Settings ← Connectors ← Add custom connector ← אותה כתובת.
מתחת — "חיבורים פעילים": `client_name`, ארגון, שימוש אחרון, "ניתוק" (מבטל את ה-grant). מנהל רואה ומנתק את כל
החיבורים בארגון. מסלול הפרויקט + 📥 נשאר, שני.

### 3.6 `app.json`

ל-`"public"` נוספים: `mcp`, `mcp.php`, `.well-known/oauth-protected-resource`, `.well-known/oauth-authorization-server`,
`.well-known/openid-configuration`, `oauth/register.php`, `oauth/token.php`, `oauth/revoke.php`. **לא** `oauth/authorize.php`.
בודקים מול השער של הפלטפורמה שנתיב שמתחיל בנקודה ונתיב בלי סיומת עוברים (`platform/README.md`, "השער"); אם לא —
rewrite לשמות `.php` בתוך התיקייה, והכתובות במטא-נתונים בהתאם.

## 4. מה לא בונים כאן

לקוח OAuth מוגדר מראש; SSE; כלי כתיבת קבצים ל-Dropbox (כמו 05 §3); הגשה לחנות ה-Plugins של OpenAI; widgets
(Apps SDK UI).

## 5. מסלול בדיקה בלי ChatGPT

1. **אוטומטי** (`tests/run.sh`, curl מול `php -S` עם `gate-prepend.php`) — כל הזרימה: מטא-נתונים ← `register` ← `authorize`
   (הזהות מהשער המדומה, POST עם CSRF) ← קוד ← `token` ← `initialize` ← `tools/list` ← `tools/call find` ← `case_update`
   ← `refresh` ← `revoke`.
2. **MCP Inspector** (מחשב עם Node, אחרי פריסה): `npx @modelcontextprotocol/inspector` ← Transport: Streamable HTTP ←
   הכתובת ← "Open Auth Settings" ← Quick OAuth Flow. מראה כל שלב בגילוי וב-OAuth — כאן יתגלה הסיכון של 3.2.
3. **curl בשרת** (בלי OAuth): `curl -sX POST <base>/mcp -H 'Authorization: Bearer fdx_…' -H 'Content-Type: application/json'
   -d '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"whoami","arguments":{}}}'`.

## 6. קריטריוני קבלה

| # | הקריטריון |
| --- | --- |
| K1 | `initialize` בלי אסימון → `protocolVersion`, `capabilities.tools`, ו-`instructions` באורך ≤ 512 תווים בתחילתו |
| K2 | `tools/list` → בדיוק הפעולות של `extActions()` חוץ מ-`prompt`; `GET` עם `readOnlyHint: true`; אין כלי בשם שמכיל delete / remove |
| K3 | `tools/call` בלי אסימון → HTTP 401 עם `WWW-Authenticate` שמכיל `resource_metadata=`; הכתובת שבו מחזירה JSON תקין לפי RFC 9728 |
| K4 | זרימת OAuth מלאה (DCR + PKCE S256) → אסימון → `tools/call whoami` מחזיר את המשתמש והארגון שאושרו |
| K5 | `code_verifier` שגוי / קוד בשימוש שני / קוד מעל 60 שניות / `redirect_uri` שונה → `invalid_grant` |
| K6 | `authorize` עם `redirect_uri` שלא רשום → דף שגיאה, **בלי** הפניה; בלי `code_challenge` או עם `plain` → שגיאה |
| K7 | CIMD: מסמך תקין → מסך ההסכמה עם `client_name`; `client_id` שמצביע ל-`http://`, ל-`127.0.0.1` או לכתובת פרטית → נדחה בלי בקשה יוצאת |
| K8 | `case_update` דרך MCP עם `verified` → נשמר "לאימות"; `events` עם `via: api` ו-actor "<שם> (<client_name>)" |
| K9 | אסימון של עובד שטח → `case_update` לתשלום → `isError: true` עם הודעת ה-403 של `extDo`; תיק של ארגון אחר → `isError` 404 |
| K10 | רענון מחליף את אסימון הרענון; שימוש חוזר בישן → כל ה-grant מבוטל, ואסימון הגישה שלו 401 |
| K11 | משתמש שהושבת → אסימון הגישה שלו 401 מיד; "ניתוק" במסך 🤖 → 401 מיד |
| K12 | `fdx_` של 05 עובד ב-`mcp.php`; `fdo_` לא עובד ב-`ext.php` (רק ב-MCP) |
| K13 | `Origin: https://evil.example` → 403 |
| K14 | שום אסימון (`fdo_`, `fdr_`, `fdx_`, קוד) לא מופיע ביומן הבקשות, ב-`events` או בתשובת שגיאה |
| K15 | `app.json`: הכתובות של 3.6 ב-`"public"`, `oauth/authorize.php` לא; `php platform/check-apps.php` עובר |
| K16 | (ידני, אחרי פריסה) MCP Inspector: Quick OAuth Flow עובר עד `tools/list` |
| K17 | (ידני, הבעלים) ChatGPT בדפדפן: Add custom MCP server ← OAuth ← מסך ההסכמה ← "תוסיף ל<שם>: הערה בדיקה" → ההערה בתיק, ביומן "(ChatGPT)" |
| K18 | (ידני) אותה כתובת כ-custom connector ב-Claude — עובד עד `whoami` |

## 7. הכרעות (טורבו)

| # | הכרעה | למה |
| --- | --- | --- |
| ה17 | השרת בתוך `funerals-dashboard/`, PHP, בלי SDK | אין Node בשרת; JSON-RPC קטן; עצמאי מהמעטפת |
| ה18 | Streamable HTTP בלי SSE ובלי session | אין התראות מהשרת; LiteSpeed לא אוהב חיבור פתוח |
| ה19 | OAuth מול משתמשי האפליקציה, הזהות דרך השער | הכלל: משתמשי האפליקציה ≠ משתמשי הפלטפורמה; השער רק מזהה |
| ה20 | הכלים = `extActions()` / `extDo()` — בלי שכבה שנייה | הרשאות, "לאימות" ויומן במקום אחד |
| ה21 | `tools/list` פתוח בלי אסימון | ChatGPT קורא אותו לפני ההתחברות; אין בו נתונים |
