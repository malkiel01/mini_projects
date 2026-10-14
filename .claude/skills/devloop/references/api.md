# DevLoop — הפקודות (מנהל הפרויקטים)

הכתובת: `https://mbe-plus.com/mini_projects/project-manager/api.php`. האסימון בכותרת `X-Dev-Token: pmd_…`.
עד 300 בקשות בשעה לאסימון. האסימון פותח רק את המטלות.

```bash
API=https://mbe-plus.com/mini_projects/project-manager/api.php
T="X-Dev-Token: $PM_DEVLOOP_TOKEN"

# מה חדש (הערות של המשתמש שלא נענו) · הכול · אחת
curl -s -H "$T" "$API?action=devapi-feed"
curl -s -H "$T" "$API?action=devapi-feed&all=1"
curl -s -H "$T" "$API?action=devapi-feed&code=PM-018"
# לסמן כנקרא — אחרי שענית
curl -s -H "$T" "$API?action=devapi-feed&mark=1" > /dev/null

# תשובה בשיח, מצב, ראיות וצעדי בדיקה — הכול אופציונלי חוץ מ-code
curl -s -H "$T" -H 'Content-Type: application/json' "$API?action=devapi-comment" -d '{
  "code": "PM-018",
  "body": "תיקנתי … איך בודקים: …",
  "status": "verify",
  "evidence": [{"kind": "pr", "ref": "#295", "url": "https://github.com/malkiel01/mini_projects/pull/295", "note": ""}],
  "steps": ["פתחו …", "לחצו …", "רואים …"]
}'
# status: open | in_progress | verify | question  (לא closed — זה של המשתמש)
# evidence.kind: pr | commit | deploy | version | test | note
# steps — רק במטלה שנפתחה מהמסך; במטלה מהקובץ — steps בקובץ
# מטלה סגורה לא חוזרת ל-verify ("status_skipped": true) — רק if_open: 0 מכריח

# צילום שהמשתמש צירף (המזהה — files[].id בפיד)
curl -s -H "$T" "$API?action=devapi-file&id=12" -o shot.jpg
# לצרף קובץ כראיה (צילום מסך מבדיקה)
curl -s -H "$T" -H 'Content-Type: image/png' --data-binary @shot.png "$API?action=devapi-attach&code=PM-018&name=after.png"
```

## מה יש בפיד

`items[]`: `code`, `title`, `status`, `kind` (bug · feature · question · research · task), `priority`, `detail`,
`acceptance`, `steps`, `questions`, `comments[]` (`author`: user / claude, `new`), `files[]`, `evidence[]`,
`context` (בדיווח 🐞 / שגיאה: url, version, requests עם `id` = X-Request-Id, errors), `occurrences` (בשגיאה מהיומן:
`count`, `last_at`, `ignored`), `waiting` (user — מחכה למשתמש; claude — מחכה לך), `source` (code — מהקובץ; user — מהמסך).
ובראש: `next_code` — הקוד הבא למטלה חדשה בקובץ.

## בלי רשת לשרת

אם הסביבה חוסמת את המארח — בקש מהמשתמש "📋 העתקה לצ'אט" ותענה בצ'אט; את המצב והצעדים עדכן בקובץ
`dev/open-items.json`, והם יגיעו עם הפריסה.
