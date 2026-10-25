# Cloudflare — לאשר את נקודת הקבלה של קול מרוקו

maghreb.co.il יושב מאחורי Cloudflare. בבדיקה מהשרת שלנו הוא החזיר **403** — כלומר Cloudflare חוסם בקשות
מהשרת. צריך לאשר **נתיב אחד בלבד**, ל-`POST` בלבד: `/wp-json/kol-maroko/v1/`. שאר האתר נשאר מוגן בדיוק כמו היום.

> הבקשות האלה חתומות. גם אם מישהו אחר יגיע לנתיב הזה, התוסף ידחה אותו בלי מפתח.

## הכלל

1. נכנסים ל-https://dash.cloudflare.com ← בוחרים את **maghreb.co.il**.
2. בתפריט הצד: **Security** ← **Security rules** (בחשבונות ישנים: **Security ← WAF ← Custom rules**).
3. **Create rule** ← **Custom rule**.
4. **Rule name**: `kol-maroko publish`
5. **If incoming requests match…** ← **Edit expression**, ומדביקים:

   ```
   (http.request.uri.path wildcard "/wp-json/kol-maroko/v1/*" and http.request.method eq "POST")
   ```

6. **Then take action…**: **Skip**.
7. מסמנים את כל מה שמופיע תחת **WAF components to skip**:
   - All remaining custom rules
   - All rate limiting rules
   - All managed rules
   - All Super Bot Fight Mode Rules (אם מופיע)

   ומתחת, ב-**More components to skip**: Browser Integrity Check, Security Level, User Agent Blocking, Hotlink Protection.
8. **Place at**: **First**. ← **Deploy**.

## אם עדיין 403: Bot Fight Mode

בתוכנית החינמית אי אפשר לדלג על **Bot Fight Mode** בכלל. אם הוא דלוק:

- **Security ← Bots**: אם **Bot Fight Mode** דלוק — אפשר לכבות אותו, **או**:
- **Security ← WAF ← Tools ← IP Access Rules**: מוסיפים את כתובת ה-IP של השרת שלנו (mbe-plus.com) עם הפעולה
  **Allow**, ובשדה Zone — **This website**. הכתובת: בסטודיו ← ⚙️ הגדרות ← "בדיקת חיבור" מציג אותה כשהבדיקה נכשלת.

## מטמון

אם העמוד לא מתעדכן אחרי פרסום: **Caching ← Configuration ← Purge Cache ← Custom purge** ← הכתובת
`https://maghreb.co.il/lexicon/`. אם זה חוזר בכל פרסום — כלל **Cache Rules** ← "Bypass cache" לנתיב `/lexicon/`.

## בדיקה

בסטודיו: ⚙️ הגדרות ← **בדיקת חיבור**.

| התשובה | המשמעות |
| --- | --- |
| ✓ האתר ענה | הכול תקין |
| Cloudflare חסם | הכלל לא פעיל, או שצריך את IP Access Rule |
| באתר אין את נקודת הקבלה | התוסף לא מותקן או לא מופעל |
| לא הודבק המפתח הציבורי | צעד 2 במדריך ההתקנה |
| החתימה לא תקינה | המפתח בתוסף לא זהה למפתח שבסטודיו |
