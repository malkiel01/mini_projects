# מצב — הזיכרון הנייד של הבקרה

עודכן: 2026-11-25, **בקרה 6** (`session_018g4NYEMXfmbD3B4CjhKViH`, Sonnet).

## מצב

- האפיון החדש אושר (#1884). הפירוק לפרוסות — `team/slices.md`; לוח — `board.md` ("סבב חדש").
- **בעבודה:** קוד 10 (בינה, `session_018g4NYFG5mjiidbMUvjvA8E`) וקוד 11 (הגדרות, `session_018g4NYH1GqpeXaWBXCCakDy`) במקביל. אחריהם: 12 (ממשק, אחרי 11), 13 (קישור 22, אחרי 10).
- **כלל מיזוג:** diff + `tests/run.sh` + ביקורת ב-`team/reviews/` לפני כל מיזוג; אחרי — `conclusion: success`.
- `--exclude` ל-`funerals-dashboard/dev/`: לא נוסף — `dev/open-items.json` נקרא בלבד ומגיע מהריפו. להוסיף (ממוקד, לא לכל `dev/`) רק כשפרוסה כותבת שם.

## פתוח

- **אצל הבעלים:** דוגמה לדוח ביטוח לאומי (חוסמת רק את הדוח).
- **שעון:** `trig_018AZvr3yfhJafuYGjJa36fM` (דקה 22) — ממתינים להודעת מנהל הצוותים עם המזהה; אז לעדכן `sessions.json` (`control.clock`) ולהעביר לבקרה 6 אם צריך. לא ליצור שעון נוסף.
- לא הומרה ה-`persistent_session_id` של השעון ידנית — המנהל יצר אותו עבורי.
