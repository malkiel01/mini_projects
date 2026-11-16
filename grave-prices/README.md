# מחירוני קברים

מחירוני חברה קדישא לעדת המערבים — לתושבי ישראל ולתושבי חו"ל, לפי "בתוקף החל מ-". צפייה, והדפסה בעמוד A4 שנראה
כמו המחירון המקורי: לכל קבוצה בנפרד, דף נפרד לכל קבוצה בהדפסה אחת, או שתיהן בדף אחד.

**מצב: פרוסה 01 — תשתית, נתוני ההתחלה, צפייה והדפסה.** עריכה ומחירון חדש — פרוסה 02.

| מה | איפה |
| --- | --- |
| הדרישה במילים של הבעלים | `docs/owner-brief.md` |
| האפיון | `docs/spec/` |
| כללי הצוות | `team/PROTOCOL.md` |
| ה-API (`api.php?action=…`) | `api.php`; הלוגיקה ב-`lib/` (חסומה בדפדפן) |
| המסכים | `index.html` + `assets/js/app.js` — רשימה (`#/`), מחירון (`#/e/<pid>`), מפתח (`#/dev/…`) |
| ההדפסה | `print.html?e=<pid>&mode=il\|abroad\|split\|both` + `assets/js/print.js`, `assets/css/print.css` (`&preview=1` — בלי חלון ההדפסה) |
| נתוני ההתחלה | `lib/seed/editions.json` — העתק של `docs/spec/seed/editions.json`; נטען במיגרציה רק כשאין מחירונים |
| נייר המכתבים | `assets/img/letterhead-top.jpg`, `letterhead-bottom.jpg` |
| נתוני ריצה בשרת | `data/` — `app.sqlite`, `sessions/`, `devloop/`; מוחרגת מהפריסה וחסומה מהדפדפן |
| בדיקות | `php grave-prices/tests/run.php` — גם שלב בפריסה |
| DevLoop | קידומת `GP-`, אסימון `gpd_`, היומן המובנה; מפת הדרכים — `dev/open-items.json` |

## התחברות

מחוברת (השער): הזהות מ-`PLATFORM_USER`, חשבון נפתח בכניסה ראשונה, מפתח = `platform_admin`. מנותקת: "כניסה עם הכלים
הדיגיטליים" (`api.php?action=sso_start` → `sso_callback`, חתימת Ed25519). עוגייה משלה: `gp_session`. אין סיסמה משלה.

## מה טרם הוכרע

השאלות לבעלים — `docs/open-questions.md`.
