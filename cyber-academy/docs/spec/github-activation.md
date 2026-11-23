# הפעלת מקור GitHub — מה מגדירים בשרת

**המצב בקוד:** `ACADEMY_GITHUB_PHASE_ALLOWED = true` (מנעול הקוד פתוח, הכרעת מנהל 00). **זה לא מדליק כלום לבד.**
התבנית (`samples/config.example.json`) והקונפיג בברירת המחדל נשארים כבויים: `source: "local"`, `contract: "v1"`,
`github_enabled: false`, `privacy_confirmed: false`. בלי שינוי מפורש בקונפיג **שבשרת** — המקור כבוי.

## מה המנהל מגדיר בשרת

הקובץ `cyber-academy/data/config.json` בשרת (מוחרג מהפריסה ומגיט, חסום מהדפדפן). **רק** אלה, ובלי מפתח `token`:

```json
{
  "source": "github",
  "contract": "v2",
  "github_enabled": true,
  "privacy_confirmed": true,
  "github": { "token_file": "/home2/<user>/academy-secrets/github-token" }
}
```

שאר הערכים — מברירת המחדל: `owner`/`repo` = `malkiel01/offensive-cyber-academy`, `ref` = `main`,
`curriculum_path` = `dashboard/data/curriculum-v2-approved.json`, `cache_ttl` = 120.

## חובה — כל אחד בנפרד, ואחרת כבוי

| תנאי | אם חסר |
| --- | --- |
| `source: "github"` | נתוני דוגמה (פס "דוגמה"); `api/curriculum.php` → 503 `source_disabled` |
| `contract: "v2"` | `source_disabled` / `v1_github_blocked` — snapshot v1 לא נקרא מ-GitHub בכלל |
| `github_enabled: true` **ו**-`privacy_confirmed: true` (בוליאני, לא מחרוזת) | כבוי; קובץ האסימון לא נפתח |
| `github.token_file` — נתיב מוחלט, מחוץ ל-public_html, chmod 600 | `source_disabled` (`token_file_missing` / `secret_in_webroot` / `secret_insecure_perms`) |
| אין `github.token` בקונפיג | `source_disabled` / `token_in_config` |
| אסימון fine-grained, `Contents: Read`, מאגר האקדמיה בלבד | 401/403 → `source_unauthorized` |

## מה נקרא מהמאגר — ורק זה

1. `GET https://api.github.com/repos/malkiel01/offensive-cyber-academy/commits/main` → SHA.
2. `GET https://api.github.com/repos/malkiel01/offensive-cyber-academy/contents/dashboard/data/curriculum-v2-approved.json?ref=<SHA>`.

אין issues, comments, pulls או קובץ אחר. הקובץ עובר `academy_validate_v2`; אחר כך רק מסלולים `approved` בסילבוס
`approved` (עם `approvedAt`+`approvedBy`), בלי כשירויות, משימות, הערכות, ראיות או ציר זמן. כל כשל (401/403/404/429/5xx,
JSON שבור, timeout, קובץ לא תקין) → שגיאה, בלי תוכן ובלי דוגמה. מטמון 120 שניות ב-`data/cache/` (0640).

## בדיקה אחרי הפעלה (בשרת — לא נבדק מכאן)

- `api/curriculum.php` כמשתמש מורשה → 200, `isSample: false`, 11 מסלולים / 68 מודולים / 204 שיעורים, `curriculumStatus: approved`.
- אותה כתובת בלי התחברות → נחסמת בשער; עם משתמש בלי הרשאה → 403.
- הדשבורד: פס "נתוני אמת ממאגר האקדמיה · קומיט …", "תוכנית האקדמיה" מאושרת, "אין הערכות מתועדות".
- אין אסימון בתשובות, ב-`error_log` או ב-`data/cache/`.
- **כיבוי:** `github_enabled: false` (או מחיקת `config.json`) → חוזר מיד לכבוי.
