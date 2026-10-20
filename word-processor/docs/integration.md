# עורך התמלילים בתוך האפליקציה שלך — מדריך ההתממשקות

המדריך הזה הוא **כל מה שאפליקציה צריכה** כדי להציג את עורך התמלילים בתוך המסך שלה: המשתמש שלה עורך
מסמך כמו ב-Word (עמודים, כותרות, רשימות, טבלאות, תמונות, מספור עמודים) — **בלי להתחבר לעורך** — והאפליקציה
מקבלת את התוצאה כ-HTML, כגוף Word לשילוב ב-docx שלה, כ-docx או PDF שלמים, או כטקסט.

- הפרוטוקול עצמו (החוק): `platform/docs/embed.md`. המדריך הזה הוא הדרך המעשית לממש אותו.
- העורך: https://mbe-plus.com/mini_projects/word-processor/ · הקוד: `word-processor/` · README שם.
- שאלה, באג או בקשה לעורך — לצ'אט של עורך התמלילים (דרך בעל המערכת). **לא עורכים קבצים בתוך `word-processor/`**.

---

## בדקה אחת

```
השרת שלך                                   עורך התמלילים                     הדפדפן
1. מעתיק client/WordProcessorClient.php
2. מפרסם api.php?action=embed_key  ──────▶ מוריד את המפתח הציבורי שלך
3. $wp->embedSession($doc, $user)  ──────▶ חתימה ✓ → embed_url (60 שניות)
                                                                           4. host.js: iframe ← embed_url
                                           ◀═══════════ postMessage (saved, change, close…) ═══════════▶
5. $wp->export($doc, 'html'|'ooxml'|'pdf'…) ─▶ התוכן
```

אין סוד להעתיק, אין סיסמה, אין משתמש בעורך. הזהות של האפליקציה היא זוג מפתחות שהיא יוצרת לעצמה, והמשתמש
שלה מגיע לעורך עם כתובת חד-פעמית שהשרת שלה ביקש.

---

## 1. הקמה (פעם אחת)

### 1.1 העתקת הלקוח
```
cp word-processor/client/WordProcessorClient.php  <האפליקציה-שלך>/lib/WordProcessorClient.php
```
**מעתיקים — לא `require` מתוך `word-processor/`** (אפליקציה לא טוענת קבצים מחוץ לתיקייה שלה). הקובץ עצמאי:
PHP 8.1+, `sodium`, `curl`, `PDO`. כשהעורך מעדכן את הלקוח — מעתיקים שוב (הוא נשאר תואם לאחור).

### 1.2 מופע אחד
```php
require_once __DIR__ . '/lib/WordProcessorClient.php';

function wp(): WordProcessorClient
{
    static $wp = null;
    return $wp ??= new WordProcessorClient(
        db(),                                   // ה-PDO שלך. המפתח הפרטי נשמר בטבלה wp_embed_keys שהלקוח יוצר
        'org-budget-proposals',                 // מזהה = שם התיקייה שלך
        'הצעות תקציב לארגונים',                  // שם להצגה אצל מנהל העורך
        'https://mbe-plus.com/mini_projects/org-budget-proposals/api.php?action=embed_key',
        ['https://mbe-plus.com'],               // המקורות שמהם תציג את העורך (iframe)
    );
}
```

- **`key_url` חייב להיות בתיקייה שלך** (`/mini_projects/<המזהה-שלך>/…`). כך העורך יודע שרק האפליקציה שלכם שולטת בו — ואז
  הרישום **פעיל מיד**. מפתח בכתובת אחרת (או בדומיין אחר) → הרישום ממתין לאישור של מנהל העורך.
- המסד — **מוחרג בפריסה** אצלך (`deploy.yml`), אחרת המפתח יידרס והחתימות ייכשלו.

### 1.3 פרסום המפתח הציבורי
ב-`api.php` שלך:
```php
case 'embed_key':
    header('Content-Type: application/json');
    echo json_encode(wp()->publicKeys());   // {"client":"…","keys":[{"kid","alg":"Ed25519","key"}]} — ציבורי, אין בו סוד
    exit;
```
וב-`app.json` שלך — **הכתובת ציבורית** (העורך מוריד אותה בלי חשבון בפלטפורמה):
```json
"public": ["api.php?action=embed_key", "…"]
```
⚠️ בלי זה השער מחזיר לעורך 401 — והרישום נכשל ב-`key_fetch_failed`.

### 1.4 רישום
אין צורך לקרוא לו במפורש: הקריאה הראשונה של הלקוח נרשמת לבד (וגם אחרי שהעורך "שכח" אותך). אם רוצים לבדוק:
```php
$status = wp()->register();   // 'active' | 'pending'
```
`pending` = מנהל העורך צריך לאשר (בעורך: 🔗 לקוחות הטמעה ← "אישור"). זה קורה רק כשהמפתח לא בתיקייה שלך,
או כששינית `key_url` / `origins` של לקוח קיים (אף פעם לא דריסה שקטה).

---

## 2. פתיחת העורך

### 2.1 בשרת: פעולה שמחזירה כתובת
פעולה **של האפליקציה**, מאחורי הכניסה שלה (המשתמש מחובר אצלה; העורך סומך על מה שהיא אומרת לו):
```php
case 'editor_session':
    $user = require_login();                          // המשתמש שלך
    $proposal = load_proposal((int)$_GET['id']);      // מה שעורכים
    require_can_edit($user, $proposal);               // ההרשאה — אצלך. view = צפייה בלבד
    $s = wp()->embedSession(
        $proposal['plan_doc'] ?: null,                // מזהה המסמך שלך, או null → מסמך חדש
        ['id' => (string)$user['id'], 'name' => $user['name']],   // id יציב (לנעילה ולגרסאות), name — "נערך על ידי"
        'edit',                                       // 'edit' | 'view'
        'document',                                   // 'document' | 'section' (פרק 4)
    );
    if (!$proposal['plan_doc']) save_plan_doc($proposal['id'], $s['doc']);   // שומרים את המזהה החדש — מיד
    json_out(['embed_url' => $s['embed_url'], 'doc' => $s['doc']]);
```

- **`embed_url` תקף 60 שניות ולשימוש אחד** — מבקשים אותו ברגע הפתיחה, לא מראש ולא במטמון.
- **`doc`** — מחרוזת עד 40 תווים. זה כל מה ששומרים אצלכם; המסמך, התמונות והגרסאות — בעורך.
- **`return_origin`** — ברירת המחדל היא המקור הראשון שרשמת. מציגים מכמה מקורות? מעבירים את הנוכחי (פרמטר אחרון).

### 2.2 בדפדפן: host.js
```html
<div id="plan-editor" style="block-size: 100dvh"></div>
<script src="https://mbe-plus.com/mini_projects/word-processor/assets/js/host.js"></script>
<script>
  const ed = WordProcessor.mount(document.getElementById('plan-editor'), {
    session: () => fetch('api.php?action=editor_session&id=' + proposalId).then(r => r.json()),
    onSaved: e => refreshPreview(e.doc),           // אחרי כל שמירה: למשל לבקש מהשרת export html
    onChange: dirty => markUnsaved(dirty),
    onClose: e => closeEditorScreen(),             // המשתמש לחץ "סגירה" בעורך
    onLock: l => toast('פתוח לעריכה אצל ' + l.by), // מישהו אחר עורך — נפתח לקריאה בלבד
    onError: e => console.warn(e.code, e.message),
  });
</script>
```
`host.js` עושה בשבילך את מה שהפרוטוקול דורש:
- בודק שכל הודעה הגיעה **מה-iframe הזה ומהמקור של העורך** — אחרת מתעלם;
- על `expired` (סשן של 8 שעות פג, או שה-iframe רוענן) — קורא שוב ל-`session()` ומחליף את הכתובת;
- `height: 'auto'` — העורך גדל עם המסמך (בלי גלילה פנימית). בלי — ממלא את התיבה שנתת לו.

| שיטה | מה |
| --- | --- |
| `await ed.save()` | שומר עכשיו. `true` כשנשמר (או שלא היה מה), `false` אחרי 5 שניות. **לפני ניווט או סגירה של המסך — תמיד** |
| `ed.dirty` | יש שינוי שלא נשמר |
| `ed.setPage(settings)` | הגדרות עמוד מהמארחת (פרק 4) |
| `ed.readonly(true/false)` | קריאה בלבד / חזרה לעריכה |
| `ed.focus()` · `ed.reload()` · `ed.destroy()` | |

**סגירה נכונה:**
```js
async function closeEditor() {
  if (!(await ed.save())) { if (!confirm('השינויים האחרונים לא נשמרו. לסגור בכל זאת?')) return; }
  ed.destroy();
}
```

**פריסה:** בטלפון — מסך מלא (`100dvh`, בלי שוליים); במחשב — חלון רחב (לפחות 900px) או מסך מלא. לעורך יש כותרת
משלו (שם המסמך, מצב שמירה, "סגירה") וסרגל כלים — לא צריך להוסיף מעליו כפתורי שמירה.

**CSP:** אם לדף שלך יש `Content-Security-Policy`, הוסיפו `frame-src https://mbe-plus.com` ו-
`script-src https://mbe-plus.com`. העורך שולח `frame-ancestors` עם המקורות שרשמת — מקור אחר לא יוכל להציג אותו.

### 2.3 אירועים (אם לא משתמשים ב-host.js)
הפירוט המלא: `platform/docs/embed.md`, פרק 6. בקצרה: כל הודעה `{source:"word-processor", type, …}`; העורך שולח רק
ל-`return_origin`; מקבלים רק מ-`https://mbe-plus.com` ומה-`contentWindow` של ה-iframe.

---

## 3. משתמשים, הרשאות, נעילה וגרסאות

- **העורך לא פותח חשבון למשתמש שלך.** `user.name` מוצג ב"נערך על ידי" ובגרסאות; `user.id` מזהה אותו לנעילה.
  `id` חייב להיות **יציב** (אותו אדם = אותו id), ולא חייב להיות מספר.
- **ההרשאה אצלך:** האפליקציה מחליטה `edit` או `view` לפני כל `embedSession`. העורך לא בודק מי המשתמש — הוא בודק שהבקשה
  חתומה בידי האפליקציה שלך.
- **נעילה:** הראשון שפותח לעריכה נועל (2 דקות בלי דופק). השני רואה לקריאה בלבד עם "פתוח לעריכה אצל X" (אירוע
  `lock`); כשהנעילה מתפנה — בעורך מופיע "מעבר לעריכה". אותו `user.id` בשתי לשוניות — מותר.
- **גרסאות:** כל שמירה = גרסה, מאוחדות לגרסה אחת לכל משתמש בכל 10 דקות, עד 100. הצפייה והשחזור — בתוך העורך
  (🕘). `export(..., version: N)` מחזיר גרסה מסוימת.
- **שמירה:** אוטומטית (שנייה וחצי אחרי הקלדה), וב-`ed.save()`. התוכן והגדרות העמוד נשמרים יחד, באותה גרסה.
- **ניתוק:** כל שינוי נכתב קודם לטיוטה בדפדפן של המשתמש (IndexedDB). אין חיבור — העורך מציג "מנותק", ממשיך
  לעבוד ומנסה שוב לבד; `ed.save()` מחזיר `false`. נסגר לפני שנשמר — בפתיחה הבאה של אותו מסמך, באותו דפדפן ולאותו
  `user.id`, העורך מציע לשחזר.

---

## 4. קטע בתוך מסמך גדול (`mode: 'section'`)

כשהמסמך שלך הוא חלק ממסמך שהאפליקציה בונה (למשל ההקדמה של הצעת תקציב, בין השער לטבלאות):

```php
$s = wp()->embedSession($doc, $user, 'edit', 'section', [
    'size' => 'A4', 'margins' => ['top' => 25, 'bottom' => 25, 'right' => 20, 'left' => 20],   // מ"מ
    'font' => ['family' => 'David', 'size' => 12],
    'numbering' => ['show' => true, 'position' => 'bottom', 'align' => 'center', 'format' => 'n', 'start' => 3],
]);
```
- העורך מציג את הקטע **בהגדרות העמוד שלך**, ו**מאפשר לשנות אותן** — כל שינוי מגיע באירוע `onPage(settings)`
  ונשמר במסמך (`wp()->get($doc)['page']`). כך יש מקום אחד לעימוד: לוקחים ממנו את ההגדרות ל-Word/PDF שלכם.
- `ed.setPage(settings)` מהמארחת — נכנס למסמך ונשמר איתו (כמו שינוי מהתפריט; גם Ctrl+Z בעורך מבטל אותו).
- שדה שלא נשלח — ברירת המחדל של העורך. הפורמט המלא: `platform/docs/embed.md`, פרק 5.
- גופנים: `David`, `Frank Ruehl`, `Arial`, `Narkisim`, `Miriam`, `Times New Roman`, `Calibri`, `Tahoma`, `David Libre`,
  `Frank Ruhl Libre`, `Miriam Libre`, `Heebo`, `Assistant`, `Rubik`, `Alef`.

---

## 5. הוצאת התוכן

```php
$h    = wp()->export($doc, 'html');    // ['html' => '<div class="wp-doc" dir="rtl">…', 'css' => '.wp-doc{…}']
$o    = wp()->export($doc, 'ooxml');   // גוף WordprocessingML לשילוב ב-docx שלך (5.2)
$docx = wp()->export($doc, 'docx');    // הבתים של קובץ Word שלם
$pdf  = wp()->export($doc, 'pdf');     // הבתים של PDF שלם (עמודים, כותרות, מספור — כמו בעורך)
$t    = wp()->export($doc, 'text');    // ['text' => …] — פסקאות בשורות, תבליט "- ", ממוספר "1. "
$j    = wp()->export($doc, 'json');    // ['json' => …, 'page' => …] — המקור, לגיבוי ולהתנתקות
```

### 5.1 HTML למסך שלך
```php
echo '<style>' . $h['css'] . '</style>' . $h['html'];
```
- ה-CSS ממוקד ב-`.wp-doc` — לא נוגע בשאר הדף. התמונות בכתובות חתומות של העורך, תקפות כל עוד המסמך קיים.
- **שמרו אצלכם את ה-HTML האחרון** אחרי כל `onSaved` (בקשה לשרת שלך שקוראת ל-`export`). כך המסך שלך עובד גם כשהעורך
  לא זמין (פרק 7), ולא צריך לפנות לעורך בכל צפייה.

### 5.2 שילוב בתוך docx שהאפליקציה בונה (`ooxml`)
```php
$o = wp()->export($doc, 'ooxml');
// $o['body']            — <w:p>…</w:p><w:tbl>… : להכניס ל-word/document.xml במקום הקטע
// $o['styles']          — <w:style …> : להוסיף ל-word/styles.xml (מזהים בקידומת wp: wpNormal, wpHeading1…)
// $o['numbering_parts'] — ['abstract' => '<w:abstractNum…>', 'num' => '<w:num…>'] : ל-word/numbering.xml —
//                         כל ה-abstract לפני כל ה-num (Word דורש). המזהים מתחילים ב-9001 — לא יתנגשו בשלך
// $o['rels']            — <Relationship Id="wpImg1" … Target="media/wp_1.png"/> : ל-word/_rels/document.xml.rels
// $o['media']           — [['name' => 'wp_1.png', 'type' => 'image/png', 'base64' => …]] : לכתוב ל-word/media/<name>
//                         (ולוודא Default Extension ל-png/jpg/gif ב-[Content_Types].xml)
// $o['namespaces']      — ה-xmlns שהגוף משתמש בהם (w, r, wp, a, pic) — לוודא שהם על <w:document> שלך
```
הגוף כתוב מימין לשמאל (`<w:bidi/>`), עם `<w:rtl/>` רק על עברית — מספרים, תאריכים ו-₪ לא מתהפכים.

### 5.3 קובץ להורדה
```php
header('Content-Type: application/pdf');
header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode('תכנית עבודה.pdf'));
echo wp()->export($doc, 'pdf');
```

---

## 6. יצירה, ייבוא, העתקה, מחיקה

```php
$doc = wp()->create('תכנית עבודה 2027');                                   // מסמך ריק
$doc = wp()->create('תכנית עבודה', null, ['format' => 'lines', 'content' => $oldPlanText]);
wp()->import($doc, 'lines', "# מבוא\nהעמותה פועלת…\n- סעיף\n## יעדים");   // מחליף את התוכן (גרסה חדשה)
wp()->import($doc, 'docx', file_get_contents($uploadedDocx));              // ייבוא Word (בסיסי)
$new = wp()->copy($doc, 'תכנית עבודה 2027', [['2026', '2027'], ['תשפ"ו', 'תשפ"ז']]);   // החלפה בטקסט בלבד
wp()->delete($doc);
$all = wp()->listAll();                                                    // כל המסמכים שלך — לגיבוי
```
- **`lines`:** `# ` כותרת 1, `## ` כותרת 2, `- ` תבליט, כל שורה אחרת — פסקה, שורה ריקה מפרידה. אות פרק (א., ב.)
  **לא נוספת** — רוצים אותה? שולחים `# א. מבוא`.
- **`copy`** מעתיק גם את התמונות; ההחלפות לפי הסדר, רק בטקסט (לא בעיצוב).
- **מחיקה** — סופית, כולל התמונות והגרסאות.

---

## 7. כשהעורך לא זמין

`WordProcessorError` עם `code: 'offline'` (או 5xx) → מציגים את ה-HTML האחרון ששמרת, לקריאה בלבד, עם "העורך לא זמין
כרגע — נסו שוב בעוד רגע". הפלט שלך (Word/PDF) — מהעותק האחרון ששמרת (או `ooxml` שמור).

## 8. שגיאות

| code | מתי | מה עושים |
| --- | --- | --- |
| `pending` | האפליקציה נרשמה וממתינה לאישור מנהל העורך | לבקש מבעל המערכת לאשר (עורך ← 🔗) |
| `unknown_client` | העורך לא מכיר אותך | הלקוח נרשם לבד ומנסה שוב |
| `bad_signature` | המפתח שבמסד שלך שונה ממה שמפורסם ב-`embed_key` | לבדוק ש-`embed_key` ציבורי ומחזיר את המפתח מאותו מסד |
| `stale` | השעון בשרת שלך רחוק ביותר מ-5 דקות | — |
| `replay` | אותה בקשה חתומה פעמיים | הלקוח יוצר nonce חדש בכל קריאה — לא לשמור בקשות |
| `not_found` (404) | המסמך לא קיים — או של אפליקציה אחרת | להסיר את המזהה אצלך / ליצור חדש |
| `bad_origin` | `return_origin` לא ברשימה שרשמת | להוסיף ל-`origins` (→ אישור מחדש) |
| `expired` | הקישור נוצל / פג | `host.js` מטפל לבד |
| `conflict` (409) | נשמר במקום אחר בינתיים | לא אמור לקרות עם נעילה; העורך מציע טעינה מחדש |
| `offline` | אין חיבור לעורך | פרק 7 |

## 9. אבטחה — בקצרה

- **הסוד היחיד הוא המפתח הפרטי, והוא במסד שלך.** לא בקוד, לא בגיט, לא בדפדפן. הדפדפן מקבל רק `embed_url`.
- `embed_url` = טוקן חד-פעמי ל-60 שניות, קשור למסמך, למשתמש ולהרשאה שביקשת. הסשן שנפתח ממנו — בזיכרון
  ה-iframe בלבד (לא עוגייה), 8 שעות, למסמך הזה בלבד.
- האפליקציה רואה **רק את המסמכים שלה**: מזהה של אפליקציה אחרת מחזיר 404, גם אם מנחשים אותו.
- `editor_session` שלך — **מאחורי הכניסה וההרשאה שלך**. מי שיכול לקרוא לה יכול לערוך.

## 10. רשימת בדיקה לפני פריסה

- [ ] `WordProcessorClient.php` הועתק לתיקייה שלך, וה-`db()` שלך מוחרג בפריסה.
- [ ] `api.php?action=embed_key` מחזיר JSON עם `keys`, **ונמצא ב-`public`** ב-`app.json`.
- [ ] `wp()->register()` מחזיר `active`.
- [ ] `editor_session` מאחורי הכניסה שלך, שומר את `doc` החדש מיד.
- [ ] הדף טוען `host.js` מ-`https://mbe-plus.com/mini_projects/word-processor/assets/js/host.js`.
- [ ] לפני סגירה/ניווט: `await ed.save()`.
- [ ] אחרי `onSaved`: שומרים אצלך את ה-HTML (מסך) ו/או את ה-OOXML (Word) האחרונים.
- [ ] כשהעורך לא זמין — המסך שלך מציג את העותק האחרון.

**בדיקה חיה של העורך עצמו:** `https://mbe-plus.com/mini_projects/word-processor/api.php?action=selftest` — העורך נרשם
כלקוח של עצמו ועובר את כל המסלול (רישום, חתימה, iframe, שמירה, כל הפורמטים, replay, הפרדה). רץ אחרי כל פריסה שלו.

---

## נספח: בלי הלקוח (שפה אחרת / שרת אחר)

כל קריאה: `POST https://mbe-plus.com/mini_projects/word-processor/api.php?action=<פעולה>`, גוף JSON, ו-4 כותרות:
```
X-Embed-Client:    <המזהה>
X-Embed-Time:      <שניות UTC>
X-Embed-Nonce:     <16 בתים אקראיים, hex>
X-Embed-Signature: <kid>.<base64 של חתימת Ed25519 על: action \n time \n nonce \n sha256-hex של הגוף>
Cookie:            humans_21909=1        ← השרת מציג לפעמים אתגר ללקוח שאינו דפדפן
```
רישום (בלי חתימה): `POST …?action=client_register` עם `{client, name, key_url, origins}`. הפירוט: `platform/docs/embed.md`.
