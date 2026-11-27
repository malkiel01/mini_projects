# vendor — ספריות צד שלישי של "שמאות מקרקעין"

הספרייה היחידה כאן היא **mPDF**, שמפיקה את ה-PDF של הדוח (פרוסה 18; ההכרעה: `team/decisions.md`, ש-38).
ה-Word אינו משתמש בספרייה — הוא נכתב ישירות (`lib/export_docx.php`).

## מה מצורף

| חבילה | גרסה | רישיון | מה נשאר ממנה |
| --- | --- | --- | --- |
| `mpdf/mpdf` | 8.3.1 | GPL-2.0-only | `src/`, `data/`, `LICENSE.txt`, `CREDITS.txt` (שני הגופנים שבשימוש עברו ל-`lib/fonts/`) |
| `setasign/fpdi` | 2.6.10 | MIT | `src/FpdiTrait.php` בלבד (המחלקה הראשית של mPDF משתמשת ב-trait), `LICENSE.txt` |
| `psr/log` | 3.0.2 | MIT | שש המחלקות שנטענות (`LoggerInterface`, `LoggerAwareInterface`, `LoggerTrait`, `AbstractLogger`, `NullLogger`, `LogLevel`), `LICENSE` |
| `mpdf/psr-log-aware-trait` | 3.0.0 | MIT (מוצהר ב-`composer.json` שלה; אין בחבילה קובץ רישיון) | `src/` (שני קבצים), `composer.json` |

**הגופן:** DejaVu Sans, רגיל ומודגש — מתוך `ttfonts/` של mPDF, אבל יושב ב-**`lib/fonts/`** ולא כאן: גם כותב ה-Word מטמיע
אותו, והוא אינו תלוי בספרייה. הרישיון (Bitstream Vera / DejaVu, מתיר הטמעה והפצה): `lib/fonts/LICENSE-DejaVu.txt`.
הגופן מוטמע ב-PDF (כתת-קבוצה) וב-DOCX (שלם, בערפול שהתקן דורש).

## מה קוצץ

- מ-`mpdf/mpdf`: `tests/`, `utils/`, `tmp/`, קובצי התצורה של הכלים, וכל `ttfonts/` (88MB במקור; שני הגופנים שבשימוש — ב-`lib/fonts/`).
- מ-`setasign/fpdi`: כל קורא ה-PDF (`PdfParser/`, `PdfReader/`, `Tcpdf/`, `Tfpdf/` …) — האפליקציה אינה מייבאת PDF לתוך PDF.
- חבילות שאינן נטענות כלל, ולכן אינן כאן: `myclabs/deep-copy` (רק תוכן העניינים של mPDF משתמש בה, ואין לנו כזה),
  `psr/http-message`, `mpdf/psr-http-message-shim` (רק להבאת תמונה מכתובת — חסום אצלנו), `paragonie/random_compat`
  (ריקה ב-PHP 7 ומעלה).
- ה-autoload של composer הוחלף ב-`autoload.php` קצר משלנו (PSR-4 לארבעת מרחבי השמות).

הרשימה נקבעה מהרצה: `get_included_files()` אחרי הפקת מסמך עם כל סוגי הבלוקים, כותרת, תחתית, סימן מים ותמונות.
`tests/v-export.test.php` מפיק מסמך כזה בכל הרצה — מחלקה חסרה מפילה אותו.

## הכללים

- **הקוד כאן אינו נערך.** עדכון גרסה: `composer require mpdf/mpdf:^8.3` בתיקייה זמנית, העתקה של אותם חלקים,
  `php -l` על הכול, והרצת הבדיקות.
- **הפריסה מריצה `php -l` על כל קובץ `.php` בריפו, כולל כאן.** קובץ אחד שנופל עוצר את הפריסה של כל הריפו —
  `tests/a-boundaries.test.php` מריץ את אותה בדיקה.
- **התיקייה חסומה לדפדפן** (`.htaccess`, כמו `lib/`), ונטענת רק מ-`lib/export_pdf.php`.
- **שום כתיבה לכאן.** `tempDir` של mPDF (מטמון הגופנים) הוא `data/mpdf/` — מוחרג מהפריסה. ברירת המחדל של הספרייה
  (`vendor/mpdf/mpdf/tmp`) אינה בשימוש, והתיקייה אינה קיימת.
- **mPDF אינה מביאה דבר מהרשת ואינה קוראת קובץ לפי נתיב שבתוכן:** `whitelistStreamWrappers` ריק, התמונות נמסרות לה
  מהזיכרון (`var:`), וכל טקסט של משתמש עובר `htmlspecialchars` לפני שהוא נכנס ל-HTML.

## הרישיון של mPDF

GPL-2.0-only. האפליקציה ניתנת כשירות ואינה מופצת כקוד, ולכן אין לרישיון השלכה על שאר הקוד (הוצג לבעלים).
**אם האפליקציה תופץ כקוד** (התקנה אצל לקוח, מכירה של הקוד) — לבדוק שוב לפני כן.
