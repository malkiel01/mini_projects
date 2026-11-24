# צ'אט ↔ Claude Code — סקירה וארכיטקטורה

נמדד מול `docs/owner-brief.md`. ברירות המחדל — לפי `docs/open-questions.md` (ש1–ש9) עד שהבעלים מכריע.
אומת מול code.claude.com/docs (headless, sessions, agent-sdk/overview, agent-sdk/permissions) ב-2026-11-24.

## 1. התמונה

```
טלפון/דפדפן ──HTTPS──► claude-tasks (PHP משותף) ◄──HTTPS, יוצא בלבד── גשר (Node, המחשב של הבעלים)
     ▲                     │  מסד: data/chat.sqlite                      │
     │                     └──"צלצול" בלי תוכן──► ntfy.sh/<נושא סודי> ──►│ (חיבור פתוח אצל ntfy, לא אצלנו)
     └──────────── צלצול "יש תשובה" ◄────────────────────────────────────┤
                                                                         ▼
                                                     claude -p --resume <session> (Claude Code מקומי)
```

1. הבעלים כותב → `chat-api.php` שומר הודעה במצב `queued` ושולח צלצול ל-ntfy (POST אחד, בלי תוכן).
2. הגשר, שמחובר לזרם של ntfy, מקבל צלצול → `bridge-next` → **תופס** את ההודעה (מבצע אחד, §4).
3. הגשר מריץ `claude -p --resume <session_id> --output-format stream-json --verbose`, הטקסט ב-stdin.
4. בסיום — `bridge-done` עם התשובה, `session_id`, עלות. השרת שולח צלצול → הדף מושך את התשובה.

## 2. אילוצים שהארכיטקטורה מכבדת

| אילוץ | המקור | איך |
| --- | --- | --- |
| אין long-poll על ה-PHP המשותף | תקרית 429, 2.11 | כל בקשה לשרת קצרה. החיבור הפתוח — אצל ntfy |
| פניות לפי אירועים, לא לפי זמן | מחקר §16.5 | צלצול על כל הודעה. גיבוי כשהצלצול לא זמין: בדיקה פעם בדקה (גשר אחד), ודופק פעם ב-5 דק' |
| מבצע אחד להודעה | F-03 | תפיסה אטומית; תפיסה שפגה **לא** חוזרת לתור לבד (§4) |
| בלי פורט פתוח במחשב | brief §2.3 | הגשר רק פונה החוצה |
| אפליקציה עצמאית מהמעטפת | CLAUDE.md | הכול תחת `claude-tasks/`, מסד משלה; כניסה דרך השער |
| נתונים בשרת רק ב-`data/` | PROTOCOL §8 | `data/chat.sqlite` — כבר מוחרג (`claude-tasks/data/*.sqlite*`). אין נתיב חדש |
| אותו סשן בשני תהליכים בו-זמנית — הודעות נעלמות | תיעוד sessions (אזהרה רשמית) | תור סדרתי לכל שיחה בגשר; נעילה מול הטרמינל (פרוסה 03) |

## 3. ישויות (`data/chat.sqlite`)

- **bridge** — `id, name, token_hash, ntfy_topic, last_seen, version, cwd_label`. מחשב מחובר אחד בשלב הזה.
- **conversation** — `id, title, cc_session_id (null עד התשובה הראשונה), bridge_id, created_at, updated_at`.
- **message** — `id, conversation_id, seq, role (owner|claude|terminal_owner|terminal_claude|system), body,
  state (queued|claimed|done|failed|interrupted|stale), claim_id, claimed_at, cost_usd, duration_ms, error, created_at`.

## 4. מבצע אחד להודעה

- `bridge-next`: `UPDATE message SET state='claimed', claim_id=?, claimed_at=? WHERE id=? AND state='queued'` —
  רק שורה אחת משתנה, ורק הגשר שקיבל `changes()=1` מריץ. בשיחה עם הודעה `claimed` — לא נמסרת הבאה.
- `bridge-done` / `bridge-fail` מתקבלים רק עם אותו `claim_id`.
- `claimed` בלי סיום יותר מ-30 דק' (או גשר שעלה מחדש ומדווח שאינו מחזיק אותה) → `interrupted`. **לא נשלחת שוב לבד**:
  בצ'אט מופיע "⚠ נקטע — לשלוח שוב?", והבעלים מחליט. כך עבודה בריפו לא רצה פעמיים.
- הגשר מריץ שיחה אחת בכל פעם (ברירת מחדל; מקבילות בין שיחות — הגדרה מקומית, מאוחר יותר).

## 5. אבטחה

- **מי כותב:** רק בעל האפליקציה (משתמש אפליקציה עם תפקיד owner), דרך השער, עם בדיקת Origin (`sameOriginRequest`).
- **הגשר:** אסימון `br_…` שמתקבל בצימוד חד-פעמי (קוד 8 ספרות, תוקף 10 דק', נוצר במסך "חיבור מחשב"). בשרת — רק גיבוב.
  במחשב — בקובץ הגדרות שרק המשתמש קורא. אסימון הגשר מורשה **רק** לפעולות `bridge-*`.
- **מה Claude רשאי** (ש2 — הוכרע: "הכול", כולל push, **כהגדרה בגשר ולא קבוע בקוד**): שדה `permissions` בקובץ ההגדרות
  שבמחשב, נקרא מחדש בכל הודעה. הפלטפורמה לא יכולה לשנות אותו. שלושה פרופילים מוכנים + דגלים חופשיים:
  - `full` (**ברירת המחדל**): `--permission-mode acceptEdits` + `--allowedTools "Read,Edit,Write,Glob,Grep,Bash,WebFetch,WebSearch"`.
  - `repo`: כמו full, אבל Bash לרשימה (`git status/diff/log/add/commit`, בדיקות) ו-`--disallowedTools "Bash(git push *)"`.
  - `read`: `Read,Glob,Grep` בלבד.
  - `extra_args`: דגלים נוספים שהבעלים כותב בעצמו.
  בכולם: `--permission-prompts none` (מה שדורש אישור — נדחה, הריצה ממשיכה), `--max-budget-usd` לכל הודעה (ברירת מחדל 2),
  cwd = התיקייה שנבחרה (ש8). `bridge.mjs config` מציג ומשנה את ההגדרות.
- **הטקסט מהפלטפורמה** עובר ב-stdin, לא בשורת הפקודה — בלי בעיות ציטוט ובלי הזרקת פקודה (גם ב-Windows).
- **ntfy:** הצלצול לא נושא תוכן (`{"t":"msg"}`/`{"t":"reply","c":<id>}`). שם הנושא — 128 ביט אקראי. דליפה שלו מאפשרת
  רק לצלצל, וכל צלצול גורם לבקשה אחת מוגבלת-קצב — לא חושף נתונים.
- **חשבון** (ש1 — הוכרע: הגדרה שמשתנה בכל רגע, ברירת מחדל API): `auth: api|subscription`, נקרא בכל הודעה. `api` — הגשר
  מעביר `ANTHROPIC_API_KEY` מקובץ ההגדרות; `subscription` — בלי המשתנה, ה-`claude` המחובר. אפשר להחליף גם מהדף (מתג,
  פרוסה 02): הבחירה נשלחת עם ההודעה — היא לא סוד. **המפתח לעולם לא עובר לשרת.**

## 6. החלטות טכניות (של הצוות — להעתיק ל-`team/decisions.md` בידי הבקרה)

1. **CLI ולא Agent SDK** בפרוסות הראשונות: `claude -p` עובד עם שני סוגי החשבון (ש1), בלי תלות npm, ו-`--resume`
   מאפשר להמשיך את אותו סשן בטרמינל (ש3). SDK — רק אם יידרש כרטיס הרשאות חי (פרוסה 04).
2. **הגשר ב-Node 20+** בלי תלויות (`fetch` מובנה, קריאת זרם ntfy ב-`fetch`). **macOS קודם** (ש4); הקוד לא תלוי במערכת.
3. **לא `--bare`**: כדי ש-CLAUDE.md וה-hooks של הריפו יחולו כמו בטרמינל. הגשר מסמן את הריצות שלו ב-`CT_BRIDGE_RUN=1`.
4. **מסד נפרד** `data/chat.sqlite` — לא נוגעים בטבלאות הלוח הקיים (ש9).

## 7. קוד קיים שממחזרים

| מה | מאיפה | לשם מה |
| --- | --- | --- |
| כניסה ומשתמש | `lib/auth.php` (`sessionStart`, `currentUser`, `sameOriginRequest`) | כתיבה לצ'אט |
| מסד SQLite ומיגרציה | הדפוס של `lib/db.php` (`db()`, `addColumn`, `nowTs`) | `lib/chat/db.php` |
| הצפנה וגיבוב | `lib/crypto.php` | אסימון הגשר |
| בלמי קצב, נסיגה על 429/5xx | `lib/throttle.php`, הכללים שבראש `bridge/listen.sh` | בצד השרת ובגשר |
| עוגיית הגנת הבוטים | README "הגנת הבוטים" (`humans_21909=1`) | כל בקשה של הגשר |
| עיצוב | הסקיל `phone-first-design` | מסך הצ'אט |

**לא ממחזרים:** ערוצים (`lib/channels.php`), `bridge/*.sh`, `agent/run.mjs`, ה-dispatcher — הם בנויים על צ'אט ענן
שמאזין, וזה המודל שהוחלף.

## 8. הפרוסות

| # | פרוסה | תלויה בשאלות |
| --- | --- | --- |
| [01](01-prototype.md) | אב-טיפוס מקצה לקצה: הודעה → גשר → Claude Code → תשובה | ש1, ש2, ש4 (ברירות מחדל) |
| [02](02-conversations.md) | רשימת שיחות, עדכון חי בדף, מחשב כבוי, עלות | ש5, ש6, ש7 |
| [03](03-terminal.md) | אותה שיחה גם בטרמינל — **⏸ לא לבנות עד הכרעה בש3** | ש3 |
| [04](04-install.md) | התקנה, הפעלה עם המחשב, עדכון הגשר | ש4 |
| [05](05-permissions.md) | כרטיס "לאשר / לדחות" לפעולה שנחסמה | ש2 |
