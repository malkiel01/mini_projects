# DevLoop — החוזה ב-HTTP (לממש בכל שפה)

כל הפעולות על כתובת אחת (`api` ב-`.devloop.json`) עם `?action=…`. תשובה: JSON; שגיאה — קוד 4xx/5xx ו-`{"error": "…"}`.
כל תשובה מחזירה כותרת `X-Request-Id` — אותו מזהה שנרשם ביומן.

## באסימון (Claude, CI) — כותרת `X-Dev-Token`

| פעולה | בקשה | תשובה |
| --- | --- | --- |
| `GET devapi-feed` | `code`, `all=1`, `mark=1` | `{items[], next_code, read_upto, updated, log[]?}` — הפורמט ב-`api.md` |
| `POST devapi-comment` | `{code, body?, status?, evidence?[], steps?[], by?}` | `{ok, id?, status_skipped?}` |
| `GET devapi-file` | `id` | הקובץ |
| `POST devapi-attach` | `code`, `name` + גוף הבקשה = הקובץ | `{ok, id}` |

אסימון: נשמר רק כגיבוב (sha256), שייך למפתח שיצר אותו, ומפסיק לעבוד כשהמפתח כבר לא פעיל; עד 300 בקשות בשעה.

## למפתח (בהרשאה של האפליקציה)

`dev-items` · `dev-task-create {title, kind, priority, area, detail, acceptance}` · `dev-task-set {code, status | ignore}` ·
`dev-comment {code, body}` · `dev-comment-delete {id}` · `dev-verify {code, ok, note}` · `dev-report {title, detail, kind,
priority, context}` · `dev-attach ?code&name&comment_id` · `dev-file ?id` · `dev-tokens` · `dev-token-create {label}` ·
`dev-token-revoke {id}` · `dev-item-log ?code` → `{rows[], reqs[]}` · `dev-log-links {ids[], reqs[]}` → `{ids:{id: code},
reqs:{req: code}}` · `dev-task-from-log {log_id}` → `{code, created}` · `dev-log ?level&q&req&before` (יומן מובנה).

## הכללים שחייבים להישמר

- מצבים: `triage, open, in_progress, verify, question, closed`; `closed` — רק `dev-verify` של המפתח (או המסך שלו).
- שגיאה ביומן (`level=error`) → חתימה (קטגוריה, אירוע, פעולה, ההודעה בלי מספרים ומחרוזות) → מטלה `triage` אחת לחתימה,
  עם מונה ומופעים; חוזרת אחרי סגירה — שוב `triage`; "להתעלם" — רק נספרת. בפעם הראשונה מתחילים מעכשיו.
- `status: verify` מבחוץ לא מחזיר מטלה סגורה (`status_skipped`).
- מטלה בקובץ גוברת על מטלה מהמסך עם אותו קוד; מצב מבחוץ גובר על הקובץ עד `status_at` חדש יותר.
