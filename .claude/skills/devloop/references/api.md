# DevLoop — הפקודות של Claude

הכתובת והאסימון — מ-`.devloop.json` (`api`, `token_env`). האסימון בכותרת `X-Dev-Token` (למשל `pmd_…`); עד 300 בקשות
בשעה; הוא פותח רק את המטלות והיומן שלהן — לא שום דבר אחר באפליקציה.

```bash
API=$(jq -r '.apps[0].api' .devloop.json)          # או האפליקציה הנכונה מתוך apps
T="X-Dev-Token: ${!TOKEN_ENV:-$DEVLOOP_TOKEN}"      # האסימון — מהסביבה, או מה שהמפתח הדביק

curl -s -H "$T" "$API?action=devapi-feed"              # מה חדש (הערות של המפתח שלא נענו)
curl -s -H "$T" "$API?action=devapi-feed&all=1"        # הכול
curl -s -H "$T" "$API?action=devapi-feed&code=PM-018"  # אחת — כולל log: השורות שלה ביומן
curl -s -H "$T" "$API?action=devapi-feed&mark=1" >/dev/null   # לסמן כנקרא — אחרי שענית

# תשובה, מצב, ראיות וצעדים — הכול רשות חוץ מ-code
curl -s -H "$T" -H 'Content-Type: application/json' "$API?action=devapi-comment" -d '{
  "code": "PM-018", "body": "תיקנתי … איך בודקים: …", "status": "verify",
  "evidence": [{"kind": "pr", "ref": "#295", "url": "https://github.com/OWNER/REPO/pull/295", "note": ""}],
  "steps": ["פתחו …", "לחצו …", "רואים …"]
}'
# status: open | in_progress | verify | question (לא closed — של המפתח)
# evidence.kind: pr | commit | deploy | version | test | note
# steps — במטלה מהמסך; במטלה מהקובץ — בקובץ. מטלה סגורה לא חוזרת ל-verify ("status_skipped": true)

curl -s -H "$T" "$API?action=devapi-file&id=12" -o shot.jpg     # צילום שהמפתח צירף (files[].id)
curl -s -H "$T" -H 'Content-Type: image/png' --data-binary @after.png "$API?action=devapi-attach&code=PM-018&name=after.png"
```

## מה יש בפיד

`items[]`: `code`, `title`, `status`, `kind` (bug · feature · question · research · task), `priority`, `detail`,
`acceptance`, `steps`, `questions`, `comments[]` (`author`: user / claude, `new`), `files[]`, `evidence[]`,
`context` (🐞 / שגיאה: url, version, `requests[]` עם `id` = מזהה הבקשה, `errors[]`), `occurrences` (שגיאה מהיומן:
`count`, `last_at`, `ignored`), `waiting` (user — מחכה למפתח; claude — מחכה לך), `source` (code — מהקובץ; user — מהמסך).
בראש: `next_code` — הקוד הבא למטלה חדשה בקובץ. עם `code=` — גם `log[]` (ראה `log.md`).

## בלי רשת לאפליקציה

אם הסביבה חוסמת את המארח: בקש מהמפתח "📋 העתקה לצ'אט" ותענה בצ'אט; את המצב והצעדים — בקובץ המטלות, והם יגיעו
עם הפריסה.
