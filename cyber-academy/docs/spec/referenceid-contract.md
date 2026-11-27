# `timeline[].referenceId` — חוזה ההפניה

> **מעמד:** הכרעה של **פרויקט הדשבורד** (אושרה בכפוף לתיעוד על ידי מנהל 00), **לא** של החוזה בשרת האקדמיה.
> החוזה v2 (`2.0.0`) מגדיר את `referenceId` כ-`string | null` בלי לומר לאן הוא מפנה. כדי שזה יחייב גם את מי שמייצר את
> הנתונים — **המנהל צריך לאשר את הטבלה ולהכניס אותה לחוזה** (הצעה: `reference: {type, id}` — `docs/schema-gaps.md`).
> עד אז, הדשבורד אוכף את הכללים כאן, ונתונים שמפרים אותם נדחים ב-422.

## כללים
1. `referenceId` הוא `null`, או **מזהה בלבד**: תואם `ACADEMY_V2_ID` (`^[a-zA-Z0-9][a-zA-Z0-9._-]*$`), ובנוסף בלי `..`.
   כלומר: בלי `/` ו-`\`, לא מתחיל בנקודה, לא נתיב ולא כתובת.
2. המזהה **חייב להפנות לישות קיימת** בתמונת המצב — לפי הטבלה. אחרת → 422 (`contract_invalid`).
3. בתשובה לצופה `referenceId` יוצא רק כמזהה תקין; כל ערך אחר יוצא `null` (`_av2_safe_refid` ב-`lib/model-v2.php`,
   הגנה נוספת מעבר לולידציה).

## מיפוי `kind` → ישויות מותרות

ראיה (`evidence[].id`) מותרת **בכל** `kind`. בנוסף:

| `kind` | ישויות מותרות (מעבר לראיה) | בדיקה ב-`tests/run.php` |
| --- | --- | --- |
| `task` | `tasks` | "referenceId: משימה ב-kind task → מתקבל" |
| `assessment` | `assessments` | "referenceId: הערכה קיימת → מתקבל"; "מזהה של רשימה אחרת (task ב-kind assessment) → נדחה" |
| `curriculum` | `stages`, `tracks`, `modules`, `lessons`, `topics`, `skills` | "referenceId: מסלול ב-kind curriculum → מתקבל" |
| `decision` | כל ישויות הסילבוס (כמו `curriculum`) + `assessments` | "referenceId: ראיה קיימת → מתקבל (כל kind)" |
| (כל kind) | `evidence` | "referenceId: ראיה קיימת → מתקבל (כל kind)" |

בדיקות נוספות לכללים 1–3:
- "referenceId: נתיב/לא-מזהה … → נדחה" — `evidence/x.md`, `../x`, `/etc/passwd`, `.hidden`, `a..b`, `C:\x`, `~/x`, `""`.
- "referenceId: מזהה לא קיים → נדחה"; "referenceId: null → מתקבל".
- "referenceId: נתיב → snapshot 422"; "referenceId: בתשובת הצופה — רק מזהה תקין";
  "referenceId: המודל מסנן נתיב גם בלי ולידציה".
- "נתיבים: curriculum.php / snapshot v2 — אין מחרוזת דמוית נתיב" — סריקה רקורסיבית של כל מחרוזת בפלט.

המימוש: `ACADEMY_V2_TIMELINE_TARGETS` וטיפוס `refid?` ב-`lib/validate-v2.php`.

## שינוי
**אין לשנות את משמעות השדה או את הטבלה בלי לעדכן את הבדיקות שלמעלה** (ואת המסמך הזה) באותו קומיט.
