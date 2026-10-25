<?php
/**
 * עבודה מצ'אט (הכרעת בעל המערכת, 2026-10-25): "סקיל לצ'אט, ובתוכו שם משתמש וסיסמה לעריכת האתר הזה בלבד, בלי
 * יכולת לפרוץ החוצה" — ו"רק טיוטה".
 *
 * כל חבר יוצר לעצמו כניסה (שם משתמש + סיסמה), והיא מקבלת סקיל מוכן להעתקה. הכניסה עובדת רק מול agent.php, ושם רק
 * פעולות של עורך: הטיוטה, המילים, קבצי הקוד, תצוגה ובדיקת "מה ישתנה". אין פרסום, חברים, הגדרות, מפתחות, ייבוא או
 * גישה לאתר. בשרת נשמר רק גיבוב של הסיסמה; כניסה פגה אחרי 90 יום, ואפשר לבטל אותה בכל רגע. אחרי 10 ניסיונות
 * כושלים מאותה כתובת — חסימה ל-15 דקות. כל פעולה נכנסת ליומן עם 🤖.
 */

declare(strict_types=1);

const AGENT_DAYS = 90;
const AGENT_FAIL_MAX = 10;
const AGENT_FAIL_WINDOW = 900;

function agent_token_create(array $user, array $b): array
{
    if (!in_array($user['role'], ['editor', 'publisher', 'owner'], true)) throw new ApiError('אין הרשאה', 403);
    $id = 'kol-' . bin2hex(random_bytes(4));
    $secret = rtrim(strtr(base64_encode(random_bytes(24)), '+/', 'Az'), '=');
    $label = str($b['label'] ?? '', 60) ?: 'צ\'אט';
    $exp = gmdate('Y-m-d\TH:i:s\Z', time() + AGENT_DAYS * 86400);
    db()->prepare('INSERT INTO agent_tokens (id, sub, name, label, hash, created_at, expires_at, creator_role) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$id, $user['sub'], $user['name'], $label, password_hash($secret, PASSWORD_DEFAULT), now_iso(), $exp, $user['role']]);
    audit_as($user, 'agent_create', "$label ($id)");
    return ['user' => $id, 'password' => $secret, 'expires_at' => $exp, 'skill' => agent_skill($id, $secret, $user['name'])] + agent_tokens($user);
}

/** הכניסות שלי (בעלים — של כולם). הסיסמה לא חוזרת לעולם — רק בעת היצירה. */
function agent_tokens(array $user): array
{
    $all = $user['role'] === 'owner';
    $st = db()->prepare('SELECT id, sub, name, label, created_at, expires_at, last_used FROM agent_tokens WHERE revoked = 0' . ($all ? '' : ' AND sub = ?') . ' ORDER BY created_at DESC');
    $st->execute($all ? [] : [$user['sub']]);
    return ['tokens' => array_map(fn($t) => $t + ['mine' => $t['sub'] === $user['sub'], 'expired' => $t['expires_at'] < now_iso()], $st->fetchAll())];
}

function agent_token_revoke(array $user, array $b): array
{
    $st = db()->prepare('UPDATE agent_tokens SET revoked = 1 WHERE id = ? AND (sub = ? OR ? = 1)');
    $st->execute([(string)($b['id'] ?? ''), $user['sub'], $user['role'] === 'owner' ? 1 : 0]);
    if (!$st->rowCount()) throw new ApiError('הכניסה לא נמצאה', 404);
    audit_as($user, 'agent_revoke', (string)$b['id']);
    return agent_tokens($user);
}

/**
 * הכניסה בבקשה מצ'אט: X-Kol-User + X-Kol-Password (או Basic). מחזיר משתמש כמו current_user, בתפקיד עורך לכל
 * היותר — גם לבעלים: מצ'אט רק טיוטה.
 */
function agent_auth(): array
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $key = 'agent_fail_' . hash('sha256', $ip);
    $f = json_decode((string)setting($key, '{}'), true) ?: [];
    if (($f['n'] ?? 0) >= AGENT_FAIL_MAX && ($f['at'] ?? 0) > time() - AGENT_FAIL_WINDOW) {
        throw new ApiError('יותר מדי ניסיונות כניסה. נסו שוב בעוד 15 דקות', 429);
    }
    $u = (string)($_SERVER['HTTP_X_KOL_USER'] ?? $_SERVER['PHP_AUTH_USER'] ?? '');
    $p = (string)($_SERVER['HTTP_X_KOL_PASSWORD'] ?? $_SERVER['PHP_AUTH_PW'] ?? '');
    if ($u === '' && preg_match('/^Basic\s+(\S+)/i', (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''), $m)) {
        [$u, $p] = array_pad(explode(':', (string)base64_decode($m[1]), 2), 2, '');
    }
    $st = db()->prepare('SELECT * FROM agent_tokens WHERE id = ? AND revoked = 0');
    $st->execute([$u]);
    $t = $st->fetch();
    $ok = $t && password_verify($p, $t['hash']);
    if (!$ok) {
        $f = ($f['at'] ?? 0) > time() - AGENT_FAIL_WINDOW ? ['n' => ($f['n'] ?? 0) + 1, 'at' => time()] : ['n' => 1, 'at' => time()];
        setting_set($key, json_encode($f));
        throw new ApiError('שם המשתמש או הסיסמה שגויים', 401);
    }
    if ($t['expires_at'] < now_iso()) throw new ApiError('הכניסה פגה. צרו חדשה בכלי (✨ ← עבודה מצ\'אט)', 401);
    // החבר הוסר מהכלי — גם הכניסות שלו לא עובדות. בעלים (מנהל בפלטפורמה) אינו שורה בחברים
    if (!member_by_sub($t['sub']) && $t['creator_role'] !== 'owner') throw new ApiError('המשתמש כבר לא חבר בכלי', 403);
    if ($f) setting_set($key, '{}');
    if (!$t['last_used'] || strtotime($t['last_used']) < time() - 60) {
        db()->prepare('UPDATE agent_tokens SET last_used = ? WHERE id = ?')->execute([now_iso(), $t['id']]);
    }
    return ['sub' => $t['sub'], 'name' => $t['name'] . ' 🤖', 'email' => null, 'role' => 'editor', 'agent' => $t['id']];
}

function agent_base_url(): string
{
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'mbe-plus.com');
    $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/mini_projects/kol-maroko/api.php'))), '/');
    return 'https://' . $host . $dir . '/agent.php';
}

/** הסקיל להעתקה לצ'אט. */
function agent_skill(string $id, string $secret, string $name): string
{
    $url = agent_base_url();
    return <<<MD
---
name: kol-maroko-site
description: עריכת עמוד "קול מרוקו" (maghreb.co.il/lexicon) דרך הסטודיו — הטיוטה, המילים וקבצי הקוד. השתמש בזה כשמבקשים לשנות טקסט, עיצוב, קוד או מילים בעמוד קול מרוקו.
---

# קול מרוקו — עריכה מצ'אט

אתה עורך את עמוד המילון "קול מרוקו" דרך הסטודיו שלו. **רק טיוטה**: כל מה שתשנה נשמר בסטודיו, ו{$name} מפרסם
לאתר בעצמו (🚀 פרסום). אין לך גישה לאתר, לשרת או לשום דבר אחר — רק לפעולות שכאן.

## כניסה

- כתובת: `{$url}`
- שם משתמש: `{$id}`
- סיסמה: `{$secret}`

בכל בקשה — שתי כותרות: `X-Kol-User` ו-`X-Kol-Password`. בקשות POST — גוף JSON.

```bash
curl -s "{$url}?action=help" -H "X-Kol-User: {$id}" -H "X-Kol-Password: {$secret}"
```

הסיסמה סודית: לא להדפיס אותה למשתמשים אחרים ולא לשמור אותה בקוד או בריפו.

## מה אפשר

| פעולה | איך | מה |
| --- | --- | --- |
| `help` | GET | התיאור הזה + המשתנים של התבנית |
| `draft` | GET | העמוד (sections → columns → widgets) ו-`rev` |
| `draft_save` | POST `{page, rev}` | שמירת העמוד. `rev` מה-`draft` האחרון; 409 — מישהו שמר בינתיים: לקרוא שוב ולמזג |
| `render` | POST `{page?}` | ה-HTML וה-CSS כמו בתצוגה (בלי `page` — הטיוטה) |
| `words` | GET | כל המילים (id, word, meaning — HTML, topics, audio) והנושאים |
| `word_save` | POST `{id?, word, meaning, topics[], audio?}` | עדכון (עם id) או מילה חדשה. `audio` — להשאיר כמו שקיבלת |
| `word_delete` | POST `{id}` | מחיקה |
| `topic_op` | POST `{op: rename\|delete, name, to?}` | שינוי שם / איחוד / הסרה של נושא |
| `code` | GET | קבצי הקוד: lexicon.html (תבנית Mustache), lexicon.css, lexicon.js — עם `rev` לכל קובץ |
| `code_create` | POST `{}` | יצירת הקבצים מהעמוד הנוכחי (אם עוד אין) |
| `code_save` | POST `{path, content, rev}` | שמירת קובץ. תבנית שבורה נדחית עם מספר השורה |
| `code_history` | GET `&path=` | השמירות הקודמות של קובץ |
| `files` | GET | הקבצים בספרייה (תמונות, הקלטות) — למזהים |
| `publish_preview` | GET | מה ישתנה באתר בפרסום הבא, ומה חוסם |

## כללים

1. קודם לקרוא (`draft` / `words` / `code`), אחר כך לשנות — ולשלוח את ה-`rev` שקיבלת.
2. שינוי קטן ומדויק בכל פעם. אחרי שמירה — `render` או `publish_preview` כדי לוודא.
3. העמוד בעברית ומימין לשמאל. ההודעות למשתמש — בעברית.
4. בסוף — לומר למשתמש מה השתנה, ושצריך לפרסם בסטודיו (🚀 פרסום) כדי שזה יגיע לאתר.
5. JS בקבצי הקוד רץ בעמוד באתר אחרי פרסום — לבדוק אותו היטב, ולא להכניס קוד שפונה לשרתים חיצוניים.
MD;
}

/** "help" לצ'אט: הסקיל בלי הסיסמה, והמשתנים של התבנית. */
function agent_help(array $user): array
{
    $skill = preg_replace('/^- סיסמה: .*$/m', '- סיסמה: (מה שקיבלת)', agent_skill((string)$user['agent'], '***', $user['name']));
    return ['skill' => $skill, 'template' => LEX_TPL_HELP, 'you' => ['name' => $user['name'], 'role' => 'עורך — טיוטה בלבד']];
}
