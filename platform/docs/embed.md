# הטמעת אפליקציה באפליקציה — פרוטוקול

אפליקציה **מארחת** (למשל הצעות תקציב) מציגה בתוך המסך שלה אפליקציה **מוטמעת** (למשל עורך התמלילים),
**בלי שהמשתמש שלה יתחבר למוטמעת**. ההזדהות היא בין השרתים; הדפדפן מקבל רק כתובת חד-פעמית.

המסמך נכתב לעורך התמלילים (`word-processor/`, הצרכן הראשון — `org-budget-proposals/`), אבל הפרוטוקול
כללי: כל אפליקציה שתרצה להיות מוטמעת — אותם רישום, חתימה, טוקן ואירועים.

האפיון המקורי: `org-budget-proposals/docs/word-processor-request.md`.

---

## 0. ההכרעות (2026-10-20)

| שאלה (פרק ו באפיון) | הכרעה | למה |
| --- | --- | --- |
| 1. איפה המסמכים | **בעורך.** במרחב של הלקוח (`<client>:<doc>`); המארחת שומרת רק את מזהה המסמך | מקום אחד לעימוד, לתמונות ולפלט. `doc_list` + `doc_export` מאפשרים למארחת לקחת הכול ולהתנתק |
| 2. רישום לקוחות | **המארחת רושמת את עצמה, בלי סוד להעתיק.** היא יוצרת זוג מפתחות Ed25519, מפרסמת את המפתח הציבורי בכתובת שלה, ונרשמת בקריאה אחת. לקוח מאותו דומיין כמו העורך — מאושר מיד; מדומיין אחר — ממתין לאישור במסך המנהל של העורך | כמו `sso.md`: "בלי קודים ובלי סודות להעתיק". לא ב-`app.json` של המארחת ולא בפלטפורמה — שתי האפליקציות עצמאיות מהמעטפת ויכולות לצאת לדומיין משלהן |
| 3. מצב קטע | **העורך משנה את הגדרות העמוד ומחזיר אותן** (אירוע `page`, ובשדה `page` של `doc_get`) | מקום אחד לעימוד. "🖋️ עיצוב המסמך" במארחת מצטמצם לקישור לעורך |
| 4. עריכה במקביל | **עכשיו: נעילה.** הראשון עורך; השני רואה לקריאה בלבד עם "פתוח לעריכה אצל X". הנעילה פגה תוך 2 דקות בלי דופק. שיתוף בזמן אמת — בהמשך | פשוט ובטוח; מונע דריסה שקטה |
| 5. גרסאות | **כן, בשלב הראשון.** כל שמירה = גרסה (מי, מתי), מאוחדת לגרסה אחת לכל משתמש בכל 10 דקות, עד 100 לכל מסמך; שחזור מתוך העורך | הגנה מטעויות ב-Word "אמיתי" היא חלק מהמוצר |

**ההחלטות הטכניות:**

- **המנוע:** ProseMirror דרך TipTap (MIT) — רק החלקים החינמיים, מועתקים ל-`word-processor/vendor/`
  (לא CDN). העימוד לעמודים, מספור העמודים וגלישת התמונות — הרחבות משלנו (ההרחבות בתשלום של TipTap
  אסורות).
- **המקור:** JSON של המנוע. HTML, OOXML, ‏docx וטקסט — **נגזרים בשרת (PHP)**, כי ה-API שרת-לשרת צריך
  אותם בלי דפדפן. PDF בשלב הראשון — הדפסה מהעורך בדפדפן; PDF מהשרת — בהמשך.
- **אין שרת מסמכים** (OnlyOffice / Collabora) — לא רץ ב-cPanel.

---

## 1. רישום — פעם אחת, אוטומטית

### המארחת

1. בפעם הראשונה שהיא צריכה את העורך: יוצרת זוג מפתחות (`sodium_crypto_sign_keypair()`) ושומרת אותו
   **במסד שלה** (לא בקובץ בגיט; המסד כבר מוחרג בפריסה).
2. מפרסמת את המפתח הציבורי בכתובת ציבורית שלה (ב-`"public"` של ה-`app.json` שלה):

   ```
   GET <המארחת>/api.php?action=embed_key
   → {"client":"org-budget-proposals","keys":[{"kid":"2026-10","alg":"Ed25519","key":"<base64>"}]}
   ```

   בהחלפת מפתח — שניהם ברשימה עד שהישן יוצא משימוש.
3. נרשמת:

   ```
   POST word-processor/api.php?action=client_register
   {"client":"org-budget-proposals","name":"הצעות תקציב לארגונים",
    "key_url":"https://mbe-plus.com/mini_projects/org-budget-proposals/api.php?action=embed_key",
    "origins":["https://mbe-plus.com"]}
   → {"status":"active"}            // או "pending" — ממתין לאישור המנהל של העורך
   ```

   קריאה חוזרת עם אותם פרטים — בלי שינוי (אפשר לקרוא לה בכל עלייה).

### העורך

- מוריד את המפתח מ-`key_url` בעצמו. **המפתח לא נשלח ברישום** — כך רק מי ששולט בכתובת של המארחת יכול
  להירשם בשמה. `key_url` חייב להיות https ותחת אחד ה-`origins`.
- `key_url` באותו origin כמו העורך → `active`. אחרת → `pending`, ומופיע אצל המנהל של העורך (מתג).
- לקוח קיים שנרשם עם `key_url` או `origins` אחרים → חוזר ל-`pending`. אף פעם לא דריסה שקטה.
- המפתח נשמר במטמון שעה; חתימה שנכשלת → הורדה אחת מחדש (החלפת מפתח) ואז דחייה.
- לקוח רואה **רק את המסמכים שלו**. מזהה מסמך של לקוח אחר → 404.

---

## 2. חתימה — כל קריאה שרת-לשרת

כל פעולה בפרק 3 נשלחת `POST` עם גוף JSON ו-4 כותרות:

```
X-Embed-Client:    org-budget-proposals
X-Embed-Time:      1760947200                 (שניות, UTC)
X-Embed-Nonce:     <16 בתים אקראיים, hex>
X-Embed-Signature: <kid>.<base64 של חתימת Ed25519>
```

על המחרוזת (שורות מופרדות ב-`\n`):

```
<action>
<X-Embed-Time>
<X-Embed-Nonce>
<sha256 hex של הגוף בדיוק כפי שנשלח>
```

העורך דוחה (401 `{"error":"...","code":"bad_signature|stale|replay|unknown_client|pending"}`): זמן מחוץ
ל-±300 שניות, nonce שכבר נראה ב-10 הדקות האחרונות, לקוח לא פעיל.

ב-PHP, אצל המארחת:

```php
$body = json_encode($payload, JSON_UNESCAPED_UNICODE);
$time = (string)time(); $nonce = bin2hex(random_bytes(16));
$sig  = sodium_crypto_sign_detached("$action\n$time\n$nonce\n" . hash('sha256', $body), $secretKey);
// כותרות: X-Embed-Client, X-Embed-Time, X-Embed-Nonce, X-Embed-Signature: "$kid." . base64_encode($sig)
```

---

## 3. ה-API — שרת-לשרת (חתום)

`POST word-processor/api.php?action=<פעולה>`. תשובה: JSON. שגיאה: `{"error":"<עברית>","code":"..."}`.

| פעולה | גוף | תשובה |
| --- | --- | --- |
| `embed_session` | `{doc, user:{id,name}, can:"edit"\|"view", mode:"document"\|"section", page?, return_origin}` | `{embed_url, expires_in:60, doc}` |
| `doc_create` | `{title, page?, import?:{format,content}}` | `{doc}` |
| `doc_copy` | `{from, title, replace?:[["2026","2027"],["תשפ\"ו","תשפ\"ז"]]}` | `{doc}` |
| `doc_get` | `{doc}` | `{doc, title, version, updated_at, updated_by, page, lock?:{by,since}}` |
| `doc_export` | `{doc, format:"html"\|"ooxml"\|"docx"\|"text"\|"json", version?}` | לפי הפורמט — למטה |
| `doc_import` | `{doc, format:"lines"\|"docx"\|"html", content}` | `{doc, version}` |
| `doc_delete` | `{doc}` | `{ok:true}` |
| `doc_list` | `{after?}` | `{docs:[{doc,title,version,updated_at}], next?}` |

- `doc` — מזהה שהעורך נותן (מחרוזת עד 40 תווים). המארחת שומרת אותו אצלה.
- `embed_session` על `doc` ריק → יוצר מסמך חדש ומחזיר את המזהה.
- `page` — הגדרות עמוד (פרק 5). ב-`embed_session` במצב `section` הן נכנסות למסמך אם שונות.
- **`doc_export`:**
  - `html` → `{html, css}`. HTML נקי, מימין לשמאל, תמונות בכתובות חתומות של העורך (`api.php?action=media&…`)
    שתקפות כל עוד המסמך קיים.
  - `ooxml` → `{body, styles, numbering, media:[{name, type, base64}], rels}`. **גוף** WordprocessingML
    (`<w:p>`…) לשילוב בתוך docx שהמארחת בונה, ולצדו מה שהגוף מפנה אליו.
  - `docx` → קובץ (`Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document`).
  - `text` → `{text}` — פסקאות בשורות, תבליט כ-`- `, כותרת בלי סימון.
  - `json` → `{json, page}` — המקור, לגיבוי ולהתנתקות.
- **`doc_import` / `lines`:** `# ` פרק (כותרת 1), `## ` כותרת 2, `- ` תבליט, שורה ריקה = פסקה חדשה. בדיוק
  הפורמט של תכנית העבודה היום בהצעות תקציב.
- **`doc_copy` / `replace`:** החלפה בטקסט בלבד (לא בעיצוב), לפי הסדר.

---

## 4. ההטמעה בדפדפן

```
דפדפן (מארחת)               שרת המארחת                         עורך התמלילים
  "עריכה" ─────────────────▶ │
                              │── embed_session (חתום) ─────────▶ │
                              │◀── {embed_url, doc} ──────────────│
  ◀── embed_url ──────────────│
  <iframe src=embed_url> ───────────────────────────────────────▶ │ embed.php: הטוקן → CSP frame-ancestors
                                                                   │ embed_open: הטוקן → סשן (Bearer, בזיכרון)
  ◀═══════════════ postMessage (פרק 6) ═══════════════════════════▶│
```

- `embed_url` = `https://…/word-processor/embed.php?t=<טוקן>`. הטוקן: חד-פעמי, 60 שניות, קשור ללקוח,
  למסמך, למשתמש ולהרשאה.
- `embed.php` שולח `Content-Security-Policy: frame-ancestors <origins של הלקוח>` ו-`Referrer-Policy:
  no-referrer`, ובדף עצמו מחליף את הטוקן בסשן (`embed_open`) ומוחק אותו מהכתובת.
- **הסשן בזיכרון בלבד** (`Authorization: Bearer`), לא עוגייה: עובד גם כשהמארחת בדומיין אחר ועוגיות צד
  שלישי חסומות. 8 שעות, מתחדש בכל פעולה. רענון ה-iframe = טוקן חדש מהמארחת (אירוע `expired`).
- **השער:** לעורך `"gate": true`; ההטמעה ב-`"public"` (פרק 7). המשתמש של המארחת אינו משתמש פלטפורמה —
  ההגנה היא החתימה והטוקן.
- **"נערך על ידי"** — `user.name` מהמארחת. העורך לא פותח לו חשבון. `user.id` — מזהה יציב אצל המארחת,
  לנעילה ולגרסאות.
- **נעילה:** `embed_open` עם `can:"edit"` לוקח נעילה אם פנויה (2 דקות, דופק כל 30 שניות). תפוסה ע"י
  משתמש אחר → נפתח לקריאה בלבד ואירוע `lock`. כשהנעילה מתפנה — הודעה בעורך, ומעבר לעריכה בלחיצה.
- **שמירה:** אוטומטית (שנייה וחצי אחרי הקלדה) ובאירוע `save`. כל שמירה נושאת את הגרסה שעליה נערכה;
  גרסה אחרת בשרת → 409 ואירוע `error {code:"conflict"}` (לא אמור לקרות עם נעילה).

---

## 5. הגדרות עמוד (`page`)

```json
{
  "size": "A4", "orientation": "portrait",
  "margins": {"top": 25, "bottom": 25, "right": 25, "left": 25},
  "font": {"family": "David", "size": 12},
  "styles": {"h1": {"size": 16, "bold": true, "color": "#1f3864"}, "h2": {}, "h3": {}, "title": {}, "normal": {}},
  "numbering": {"show": true, "position": "bottom", "align": "center",
                "format": "n|page_n|n_of_m|dash_n|hebrew", "start": 1, "first_page": false},
  "header": "", "footer": ""
}
```

מילימטרים, גדלים בנקודות. שדה שלא נשלח — ברירת המחדל של העורך. מצב `section`: העורך מציג את הקטע עם
ההגדרות האלה **ומאפשר לשנות אותן**; כל שינוי — אירוע `page` ונשמר במסמך (`doc_get` מחזיר אותו).

---

## 6. אירועי postMessage

כל הודעה: `{"source":"word-processor","type":"<סוג>", ...}`. העורך שולח רק ל-`return_origin` שנרשם
בסשן; המארחת מקבלת רק מ-origin העורך (`https://mbe-plus.com`). הודעה מ-origin אחר — מתעלמים.

| כיוון | סוג | שדות | מתי |
| --- | --- | --- | --- |
| עורך → מארחת | `ready` | `{doc, can, version}` | העורך נטען |
| | `change` | `{dirty:true\|false}` | שינוי שלא נשמר / נשמר |
| | `saved` | `{doc, version, updated_at}` | אחרי כל שמירה |
| | `height` | `{px}` | גובה התוכן השתנה (כשהמארחת לא נותנת גובה קבוע) |
| | `page` | `{settings}` | הגדרות העמוד שונו (מצב `section`) |
| | `lock` | `{by, since}` | נפתח לקריאה בלבד כי מישהו אחר עורך |
| | `expired` | — | הסשן פג: המארחת מבקשת `embed_session` חדש ומחליפה את ה-src |
| | `close` | `{saved:true\|false}` | המשתמש לחץ "סגירה" בעורך |
| | `error` | `{code, message}` | `conflict`, `offline`, `save_failed` ועוד |
| מארחת → עורך | `save` | — | לשמור עכשיו (לפני ניווט / סגירה) |
| | `set_page` | `{settings}` | המארחת שינתה הגדרות עמוד |
| | `readonly` | `{value:true\|false}` | |
| | `focus` | — | |

המארחת **לא סוגרת** את ה-iframe לפני `saved` (או `change {dirty:false}`) אחרי `save`, או 5 שניות.

---

## 7. מה ב-`app.json` של העורך

```json
"gate": true,
"public": [
  "embed.php", "assets/**", "vendor/**",
  "api.php?action=client_register|embed_session|embed_open|embed_load|embed_save|embed_upload|embed_lock|embed_page|media|doc_create|doc_copy|doc_get|doc_export|doc_import|doc_delete|doc_list"
]
```

הנתונים בשרת — `word-processor/data/` (מסד ותמונות), מוחרגים בפריסה (`deploy.yml`).

## 8. כשהעורך לא זמין

המארחת שומרת אצלה את ה-HTML האחרון (`doc_export html` אחרי כל `saved`) ומציגה אותו לקריאה; עריכה —
"העורך לא זמין כרגע". הפלט שלה (Word / PDF) נבנה מהעותק האחרון ששמרה.
