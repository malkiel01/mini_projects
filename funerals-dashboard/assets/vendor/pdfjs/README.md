# pdf.js 4.10.38 — עותק מקומי

הצופה בתוך האפליקציה (U1, `assets/js/dropbox.js`) מצייר PDF ו-Word (שהשרת ממיר ל-PDF) כעמודים.
- **למה מקומי:** האפליקציה צריכה לעבוד בלי CDN (יציאה לדרך עצמאית), וכך אין צורך ב-SRI. ביקורת 12b 🔴1/🟡2.
- **למה 4.10.38:** גרסה 3.11.174 פגיעה ל-CVE-2024-4367 (תוקן ב-4.2.67). בנוסף ל-`getDocument` מועבר `isEvalSupported: false`.
- **מקור:** `https://cdnjs.cloudflare.com/ajax/libs/pdf.js/4.10.38/pdf.min.mjs` ו-`pdf.worker.min.mjs`. השמות שונו ל-`.js`,
  כדי שהשרת יגיש אותם כ-JavaScript (ייבוא מודול דורש סוג MIME נכון). הגיבוב: `SHA256SUMS`.
- רישיון: Apache-2.0 (Mozilla).
