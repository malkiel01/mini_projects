# מטלות לבעלים

של צ'אט המטלות. רק דברים לבדוק או לעשות — לא שאלות על האפליקציה.

| # | מטלה | מי ביקש | מצב | תוצאה |
| --- | --- | --- | --- | --- |
| T1 | וורדפרס ← הגדרות ← קול מרוקו: האם התוסף מותקן, איזו גרסה, והאם "פעיל" מסומן | קוד (`dev/audit.md` פרק 7, שורה 1) | נענה 2026-11-11 | מותקן · "0.14" · פעיל. **שים לב:** האבחון (T3) מדווח `plugin: 1.4.0`; בריפו 1.6.0 |
| T2 | וורדפרס ← "ייצוא ההקלטות": כמה הקלטות, גודל כולל, כמה חלקים | קוד (שורה 4) | נענה חלקית 2026-11-11 | מספרים לא נמסרו (כנראה אין אזור כזה ב-1.4.0). הבעלים הסביר את ההתאמה — ראה "תשובות מלאות" |
| T3 | וורדפרס ← "🔎 הפקת אבחון": להעתיק את כל הטקסט שבתיבה | קוד (שורה 6) | נענה 2026-11-11 | האבחון המלא למטה |
| T4 | סטודיו ← ⚙️ הגדרות: מה כתוב ב"ייבוא אחרון" | קוד (שורה 2) | נשאל שוב 2026-11-11 | הבעלים לא מצא את המקום; הוסבר מחדש |
| T5 | סטודיו ← 📚 מילים ← מסנן "הקלטה": כמה "באתר" מול "עותק בכלי" | קוד (שורה 3) | נענה 2026-11-11 | 1496 מילים · 1494 עם הקלטה · 0 מהן בכלי · 24 נושאים |
| T6 | סטודיו ← `</> קוד`: שלושה קבצים או כפתור "יצירת הקבצים" | קוד (שורה 5) | נענה 2026-11-11 | רואים 3 קבצים |
| T7 | סטודיו ← ⚙️: להוריד "גיבוי מלא" ולדווח רק את גודל הקובץ | קוד (שורה 8) | נענה 2026-11-11 | 603.62 KB |
| T8 | סטודיו ← 🗂️ קבצים ← "הקלטות": בדיקת פרוסה A1 (מונה, ניגון 5 הקלטות, הבא/הקודם, סינון "מילים בלי הקלטה", "🔁 החלף / הקלט", ומה נשאר שאי אפשר לשנות) | בקרה (`team/reviews/A1.md`) | הוצג לבעלים 2026-11-11 (פריסה אושרה: run 1497, 84f3cd0) | — |
| T9 | סטודיו: בדיקת פרוסה F1 (תשתית המפתח) — 🐞 בכל מסך ודיווח ניסיון, מסך המפתח ("בדיקות תקינות", "מצב הנתונים", יומן), יצירת אסימון והכנסתו כסוד `KM_DEVLOOP_TOKEN` ב-GitHub, והופעה בדשבורד הפיתוח של הפלטפורמה | בקרה (`team/reviews/F1.md`) | הוצג לבעלים 2026-11-11 (פריסה אושרה: run 1499, 257ae7b) | — |

קישורים: וורדפרס — `https://maghreb.co.il/wp-admin/options-general.php?page=kol-maroko` · סטודיו — `https://mbe-plus.com/mini_projects/kol-maroko/`

לא נרשם כמטלה: גישת קריאה לריפו `maghreb-kadisha` (שורה 7 בסקר) — הכרעה, שייכת לבקרה.

## תשובות מלאות — 2026-11-11 (מילות הבעלים)

**T2:** "אנחנו שמנו id לכל שם של מילה ולפי אותו הid השמע מזהה. כלומר נניח למילה עם איי די שש, אנחנו נפעיל את שמע מספר שש. אם עדיין לא ברור לך משהו תשאל שוב, אבל פשוט תסתכל במערכת ותראה את זה."

**T5 (הטקסט שעל המסך):** "1496 מילים · 1494 עם הקלטה · 0 מהן בכלי · 24 נושאים. 1494 הקלטות עדיין רק באתר. להעתקה לכלי: ⚙️ הגדרות ← "ייבוא ההקלטות"."

**הצעה של הבעלים (הועברה לבקרה, לא מטלה):** "אולי אתן לך שם וסיסמה ותיכנס אתה לראות באמצעות הדפדפן שלך."

**T3 — האבחון, כלשונו (מצומצם רווחים בלבד):**

```json
{"plugin":"1.4.0","wp":"6.9.11","php":"8.3.36","site":"https://maghreb.co.il","theme":"astra-child (parent astra)","block_theme":false,
"page":{"id":2012,"title":"קול מרוקו","slug":"lexicon","url":"https://maghreb.co.il/lexicon/","status":"publish","template":"page-kadisha-lexicon.php","elementor_mode":"","elementor_data_len":0,"content_len":435,"shortcodes":[]},
"active_plugins":["admin-menu-editor","aryo-activity-log","crocoblock-wizard","elementor-pro","elementor","google-site-kit","jet-blocks","jet-blog","jet-elements","jet-engine","kol-maroko","pojo-accessibility","post-types-order","the-plus-addons-for-elementor-page-builder","theplus_elementor_addon","wordpress-seo-premium","wordpress-seo","wp-mail-smtp"],
"post_types":[{"name":"e-floating-buttons","label":"Floating Elements","publish":0,"public":true},{"name":"elementor_library","label":"הטמפלטים שלי","publish":23,"public":true},{"name":"theplus_team_member","label":"theplus_team_member","publish":4,"public":true},{"name":"kadisha_halacha","label":"הנחיות הלכתיות","publish":5,"public":true},{"name":"kadisha_tariff","label":"תעריפים","publish":2,"public":false},{"name":"kadisha_regulation","label":"תקנות והנחיות","publish":0,"public":false},{"name":"kadisha_form","label":"טפסים להורדה","publish":0,"public":false},{"name":"kadisha_news","label":"חדשות","publish":6,"public":true},{"name":"kadisha_course","label":"קורסים","publish":4,"public":true},{"name":"kadisha_word","label":"מילים","publish":1496,"public":false},{"name":"jet-engine","label":"Listing Items/Components","publish":0,"public":true},{"name":"elementor_font","label":"גופנים מותאמים","publish":0,"public":false},{"name":"elementor_icons","label":"ניהול אייקונים","publish":0,"public":false}],
"entry_types":["kadisha_word"],
"templates":[{"id":1693,"title":"Course_Register","type":"page","conditions":null},{"id":1319,"title":"פוסט חדשות","type":"single-post","conditions":null},{"id":1294,"title":"תבנית קורס","type":"section","conditions":null},{"id":792,"title":"404","type":"error-404","conditions":["include/singular/not_found404"]},{"id":748,"title":"School_Step_3","type":"section","conditions":null},{"id":741,"title":"School_Step_2","type":"section","conditions":null},{"id":738,"title":"School_Step_1","type":"section","conditions":null},{"id":734,"title":"Passover_Step_3","type":"section","conditions":null},{"id":731,"title":"Passover_Step_2","type":"section","conditions":null},{"id":728,"title":"Passover_Step_1","type":"section","conditions":null},{"id":684,"title":"Member_Step_3","type":"section","conditions":null},{"id":681,"title":"Member_Step_2","type":"section","conditions":null},{"id":676,"title":"Member_Step_1","type":"section","conditions":null},{"id":404,"title":"Tires &#038; Mechanics Contact Page","type":"page","conditions":null},{"id":363,"title":"Tires &#038; Mechanics About Page","type":"page","conditions":null},{"id":316,"title":"Woodworking Thank You Page","type":"page","conditions":null},{"id":250,"title":"Post","type":"single-post","conditions":["include/singular/post"]},{"id":253,"title":"Blog Post Template &#8211; Image Cover Single Post Page","type":"single","conditions":null},{"id":239,"title":"Blog","type":"archive","conditions":["include/archive"]},{"id":109,"title":"Amota_Forms","type":"page","conditions":null},{"id":49,"title":"Header","type":"header","conditions":["include/general"]},{"id":35,"title":"Home_Amtoa","type":"page","conditions":null},{"id":5,"title":"קיט ברירת מחדל","type":"kit","conditions":null}],
"render":{"source":"loopback","error":null,"bytes":3726152,"audio":0,"img":3,"elementor_docs":["header#49"],"widgets":{"image.default":1,"nav-menu.default":1,"button.default":1}}}
```
