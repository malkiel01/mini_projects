/* מייצר את parts.pdf או angles.pdf מתוך הדף המתאים — אותו דף, אותו מודל, ולכן אותם מספרים.
   ה-PDF וקטורי: ה-SVG והטקסט נשארים חדים בכל הגדלה.

   הרצה (מתיקיית הריפו, אחרי שהתקנת playwright-core ויש Chromium):
     python3 -m http.server 8765 &      # הדף טוען קבצים יחסיים; מקובץ מקומי הוא לא ייטען
     node sukkah-sketch/build-pdf.js parts  [נתיב ל-Chromium]
     node sukkah-sketch/build-pdf.js angles [נתיב ל-Chromium]
   מחייב הרצה מחדש אחרי כל שינוי במודל או במצגת, ואת ה-PDF מכניסים ל-git. */
var chromium = require('playwright-core').chromium;
var path = require('path');
(async function () {
  var name = process.argv[2] || 'parts';
  if (name !== 'parts' && name !== 'angles') { console.error('דף לא מוכר: ' + name + ' (parts או angles)'); process.exit(2); }
  var exe = process.argv[3] || process.env.CHROMIUM || '/opt/pw-browsers/chromium';
  var out = path.join(__dirname, name + '.pdf');
  var browser = await chromium.launch({ executablePath: exe });
  var page = await browser.newPage({ viewport: { width: 1500, height: 1000 } });
  await page.goto('http://127.0.0.1:8765/sukkah-sketch/' + name + '.html', { waitUntil: 'networkidle' });
  await page.waitForTimeout(800);   // הגופן מגוגל, אם יש רשת
  var n = await page.evaluate(function () { return document.querySelectorAll('.slide').length; });
  await page.emulateMedia({ media: 'print' });
  await page.pdf({ path: out, format: 'A3', landscape: true, printBackground: true, preferCSSPageSize: true });
  console.log(name + '.pdf: ' + n + ' שקופיות → ' + out);
  await browser.close();
})().catch(function (e) { console.error('נכשל: ' + e.message); process.exit(1); });
