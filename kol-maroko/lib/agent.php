<?php
/**
 * עבודה מצ'אט (הכרעת בעל המערכת, 2026-10-25): "סקיל לצ'אט, ובתוכו שם משתמש וסיסמה לעריכת האתר הזה בלבד, בלי
 * יכולת לפרוץ החוצה" — ו"רק טיוטה".
 *
 * כל חבר יוצר לעצמו כניסה (שם משתמש + סיסמה), והיא מקבלת סקיל מוכן להעתקה. הכניסה עובדת רק מול agent.php, ושם רק
 * פעולות של עורך על קבצי הפרויקט והמילים (X1 — במקום הטיוטה הישנה). אין פרסום, חברים, הגדרות, מפתחות, ייבוא או
 * גישה לאתר. בשרת נשמר רק גיבוב של הסיסמה; כניסה פגה אחרי 90 יום, ואפשר לבטל אותה בכל רגע. אחרי 10 ניסיונות
 * כושלים מאותה כתובת — חסימה ל-15 דקות. כל פעולה נכנסת ליומן עם 🤖.
 */

declare(strict_types=1);

require_once __DIR__ . '/project.php';
require_once __DIR__ . '/preview.php';
require_once __DIR__ . '/ai.php';

const AGENT_DAYS = 90;
const AGENT_FAIL_MAX = 10;
const AGENT_FAIL_WINDOW = 900;
const AGENT_RATE = 120;                     // בקשות בדקה לכל כניסה (X1 §2.2)
const AGENT_GONE = ['draft', 'draft_save', 'render', 'page', 'code', 'code_create', 'code_save', 'code_history', 'publish_preview'];

function agent_token_create(array $user, array $b): array
{
    require_role($user, 'editor');
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
    $all = role_at_least($user, 'page_owner');
    $st = db()->prepare('SELECT id, sub, name, label, created_at, expires_at, last_used FROM agent_tokens WHERE revoked = 0' . ($all ? '' : ' AND sub = ?') . ' ORDER BY created_at DESC');
    $st->execute($all ? [] : [$user['sub']]);
    return ['tokens' => array_map(fn($t) => $t + ['mine' => $t['sub'] === $user['sub'], 'expired' => $t['expires_at'] < now_iso()], $st->fetchAll())];
}

function agent_token_revoke(array $user, array $b): array
{
    $st = db()->prepare('UPDATE agent_tokens SET revoked = 1 WHERE id = ? AND (sub = ? OR ? = 1)');
    $st->execute([(string)($b['id'] ?? ''), $user['sub'], role_at_least($user, 'page_owner') ? 1 : 0]);
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
    // P1: מי שיצר את הכניסה הוסר או איבד את התפקיד — גם הכניסות שלו לא עובדות
    $creator = user_by_sub((string)$t['sub']);
    if (!$creator || $creator['status'] !== 'active' || !$creator['role']) throw new ApiError('המשתמש כבר לא חבר בכלי', 403);
    if ($f) setting_set($key, '{}');
    if (!$t['last_used'] || strtotime($t['last_used']) < time() - 60) {
        db()->prepare('UPDATE agent_tokens SET last_used = ? WHERE id = ?')->execute([now_iso(), $t['id']]);
    }
    $row = agent_user_row($t, $creator);
    // התפקיד: של השורה שלו ב-users (ברירת מחדל: עורך), ולעולם לא מעל מי שיצר אותו
    $role = ROLE_RANK[$row['role'] ?? 'editor'] <= ROLE_RANK[$creator['role']] ? ($row['role'] ?? 'editor') : $creator['role'];
    return ['id' => (int)$row['id'], 'sub' => $t['sub'], 'name' => $t['name'] . ' 🤖', 'email' => null, 'role' => $role, 'agent' => $t['id'], 'agent_label' => $t['label']];
}

/** P1 (ש-19): כל כניסה מצ'אט היא שורה ב-users (agent = מזהה הכניסה), בתפקיד עורך כברירת מחדל. */
function agent_user_row(array $t, array $creator): array
{
    $st = db()->prepare('SELECT * FROM users WHERE agent = ?');
    $st->execute([$t['id']]);
    if ($row = $st->fetch()) return $row;
    db()->prepare("INSERT OR IGNORE INTO users (name, role, status, created_at, invited_by, agent) VALUES (?, 'editor', 'active', ?, ?, ?)")
        ->execute([$t['name'] . ' 🤖 ' . $t['label'], now_iso(), $creator['id'], $t['id']]);
    $st->execute([$t['id']]);
    return $st->fetch();
}

/** כל פעולה של agent.php ← הפעולה המקבילה ב-ACTION_ROLES; נבדקת כמו כל משתמש. */
const AGENT_ACTIONS = [
    'help' => 'pf_tree', 'tree' => 'pf_tree', 'read' => 'pf_read', 'search' => 'pf_search', 'versions' => 'pf_versions', 'preview_url' => 'pv_token',
    'words' => 'words', 'write' => 'pf_write', 'edit' => 'pf_write', 'create' => 'pf_create',
    'words_save' => 'words_csv_import', 'word_save' => 'word_save', 'word_delete' => 'word_delete', 'topic_op' => 'topic_op',
];

function agent_authorize(string $action, array $user): void
{
    if (!isset(AGENT_ACTIONS[$action])) return;                 // פעולה לא מוכרת — 404 ב-match
    require_role($user, ACTION_ROLES[AGENT_ACTIONS[$action]]);
}

function agent_base_url(): string
{
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'mbe-plus.com');
    $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/mini_projects/kol-maroko/api.php'))), '/');
    return 'https://' . $host . $dir . '/agent.php';
}

/** מגבלת קצב לכניסה: 120 בקשות בדקה (חלון של דקה שלמה). מעל — 429, בלי לגעת בשום דבר. */
function agent_rate(array $user): void
{
    $key = 'agent_rate_' . $user['agent'];
    $min = intdiv(time(), 60);
    $r = json_decode((string)setting($key, '{}'), true) ?: [];
    $n = ($r['m'] ?? 0) === $min ? (int)($r['n'] ?? 0) + 1 : 1;
    setting_set($key, json_encode(['m' => $min, 'n' => $n]));
    x1_stat('agent:' . pf_label((string)($user['agent_label'] ?? $user['agent']), 40) . ' (' . $user['agent'] . ')');
    if ($n > AGENT_RATE) throw new ApiError('יותר מדי בקשות (' . AGENT_RATE . ' בדקה לכל כניסה). נסו שוב בעוד דקה', 429);
}

/** הערת הגרסה ומקור הפעולה: "🤖 <שם הכניסה>". */
function agent_note(array $user, string $what = ''): string
{
    return '🤖 ' . pf_label((string)($user['agent_label'] ?? $user['agent']), 40) . ($what !== '' ? ' · ' . $what : '');
}

function agent_audit(array $user, string $action, string $detail): void
{
    audit_as($user, $action, "source: agent ({$user['agent']}) · $detail");
}

/** קובץ בעץ לפי נתיב (בלי מזהים מספריים לצ'אט). */
function agent_row(string $path): array
{
    $path = pf_path($path);
    return pf_by_path($path) ?? throw new ApiError("\"$path\" לא נמצא בפרויקט. העץ: ?action=tree", 404);
}

function agent_tree(array $user): array
{
    $t = pf_tree($user);
    return ['files' => array_map(fn($f) => ['path' => $f['path'], 'kind' => $f['kind'], 'size' => $f['size'], 'rev' => $f['rev']] + (isset($f['linked']) ? ['linked' => $f['linked']] : []), $t['files']),
        'kol' => $t['kol']['config'], 'kol_valid' => $t['kol']['valid']];
}

function agent_read(string $path): array
{
    $path = pf_path($path);
    if ($path === pf_words_path() && !pf_by_path($path)) {
        $json = words_json();
        return ['path' => $path, 'rev' => words_rev($json), 'linked' => 'words', 'content' => $json, 'note' => 'שמירה — דרך words_save (תצוגה ואז החלה)'];
    }
    $r = agent_row($path);
    if ($r['kind'] !== 'file') return ['path' => $path, 'kind' => $r['kind'], 'rev' => (int)$r['rev'], 'note' => $r['kind'] === 'media' ? 'קובץ מדיה — אין תוכן טקסט' : 'תיקייה'];
    return ['path' => $path, 'rev' => (int)$r['rev']] + pf_payload(pf_blob_get($r['sha']));
}

/** כתיבה מלאה. rev ישן — 409 עם הגרסה הנוכחית (pf_write), כמו בעורך. */
function agent_write(array $user, array $b): array
{
    $path = pf_path($b['path'] ?? null);
    if ($path === pf_words_path() && !pf_by_path($path)) throw new ApiError('זה הקובץ של טבלת המילים — שמירה דרך words_save', 400);
    $r = agent_row($path);
    if (!is_string($b['content'] ?? null)) throw new ApiError('חסר content (טקסט)');
    $out = pf_write($user, ['id' => (int)$r['id'], 'rev' => (int)($b['rev'] ?? 0), 'content' => $b['content'], 'tx' => agent_note($user)]);
    agent_audit($user, 'agent_write', $path);
    return ['path' => $path, 'rev' => $out['file']['rev']];
}

/** עריכה: old שמופיע בדיוק פעם אחת ← new. אחרת — 422 עם הסיבה, ושום דבר לא נכתב. */
function agent_edit(array $user, array $b): array
{
    $path = pf_path($b['path'] ?? null);
    $r = agent_row($path);
    if ($r['kind'] !== 'file') throw new ApiError('אי אפשר לערוך כאן תוכן');
    if (!is_string($b['old'] ?? null) || $b['old'] === '' || !is_string($b['new'] ?? null)) throw new ApiError('חסרים old (לא ריק) ו-new');
    if ((int)($b['rev'] ?? 0) !== (int)$r['rev']) {
        throw new ApiError('הקובץ השתנה בינתיים', 409, ['conflict' => ['path' => $path, 'rev' => (int)$r['rev']] + pf_payload(pf_blob_get($r['sha']))]);
    }
    $s = pf_blob_get($r['sha']);
    $n = substr_count($s, $b['old']);
    if ($n !== 1) throw new ApiError($n === 0 ? 'הקטע (old) לא נמצא בקובץ כמו שהוא' : "הקטע (old) נמצא $n פעמים — הוסיפו הקשר כדי שיהיה ייחודי", 422, ['count' => $n]);
    $s = substr_replace($s, $b['new'], strpos($s, $b['old']), strlen($b['old']));
    $out = pf_write($user, ['id' => (int)$r['id'], 'rev' => (int)$r['rev'], 'content' => $s, 'tx' => agent_note($user)]);
    agent_audit($user, 'agent_edit', $path);
    return ['path' => $path, 'rev' => $out['file']['rev']];
}

function agent_create(array $user, array $b): array
{
    pf_seed($user);
    pf_guard($user);
    $path = pf_path($b['path'] ?? null);
    if (pf_key($path) === 'media' || str_starts_with(pf_key($path), 'media/')) throw new ApiError('media/ — רק דרך הספרייה בממשק');
    if (!is_string($b['content'] ?? null)) throw new ApiError('חסר content (טקסט)');
    $bytes = $b['content'];
    if (strlen($bytes) > ($max = pf_limit('text'))) pf_over('הקובץ גדול מדי', strlen($bytes), $max);
    $r = pf_tx(function () use ($path, $bytes, $user) {
        pf_free($path);
        if (($n = pf_count()) >= ($max = pf_limit('files'))) pf_over('יותר מדי קבצים בפרויקט', $n + 1, $max);
        return pf_insert($path, 'file', $bytes, $user, null, agent_note($user, 'נוצר'));
    });
    agent_audit($user, 'agent_create', $path);
    return ['path' => $path, 'rev' => (int)$r['rev']];
}

function agent_versions(string $path): array
{
    $path = pf_path($path);
    if ($path === pf_words_path() && !pf_by_path($path)) return pf_words_versions();
    $v = pf_versions(['id' => (int)agent_row($path)['id']]);
    return ['path' => $path, 'versions' => array_map(fn($x) => array_diff_key($x, ['hid' => 1]), $v['versions'])];
}

/** התצוגה החיה (F4): כתובת עם אסימון, תקפה 30 דקות. קריאה בלבד — אינה מפרסמת. */
function agent_preview_url(array $user): array
{
    $t = pv_token($user);
    $base = preg_replace('~agent\.php$~', '', agent_base_url());
    return ['url' => $base . 'preview/' . $t['token'] . '/' . $t['entry'], 'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $t['exp']), 'ttl' => $t['ttl']];
}

/**
 * המילים — דרך המנוע של N1: בלי apply — "מה ישתנה" (כטקסט, עם token); עם apply: true + token — ההחלה (נקודת שחזור כמו N1).
 * rev — מ-words. שגיאות בקובץ — 422 עם שורה ועמודה.
 */
function agent_words_save(array $user, array $b): array
{
    if (!is_string($b['content'] ?? null)) throw new ApiError('חסר content (התוכן המלא של data/words.json)');
    $rev = (string)($b['rev'] ?? '');
    $apply = ($b['apply'] ?? false) === true;
    $r = pf_words_write($user, ['id' => PF_WORDS_ID, 'content' => $b['content'], 'rev' => $rev] + ($apply ? ['apply' => true, 'token' => (string)($b['token'] ?? ''),
        'create_topics' => $b['create_topics'] ?? []] : ['create_topics' => $b['create_topics'] ?? []]));
    if (!$apply) {
        $p = $r['plan'];
        return ['preview' => $p['summary'], 'token' => $p['token'], 'counts' => $p['counts'], 'changed' => array_slice($p['changed'], 0, 50),
            'added' => array_slice($p['added'], 0, 50), 'trashed' => array_slice($p['trashed'], 0, 50), 'next' => 'לשלוח שוב את אותו content עם apply: true ו-token'];
    }
    agent_audit($user, 'agent_words', $r['plan']['summary'] ?? 'words.json');
    return ['done' => true, 'rev' => $r['file']['rev'] ?? null, 'summary' => $r['plan']['summary'] ?? ''];
}

/** מבנה הפרויקט — מהקבצים עצמם: kol.json, העץ, והכותרת של js/kol-data.js (רשימת תכונות data-kol-*). */
function agent_project_text(): string
{
    $kol = pf_kol();
    $kolJson = $kol['config'] !== null ? json_encode($kol['config'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '(אין kol.json תקין)';
    $paths = array_column(db()->query("SELECT path FROM pf_files WHERE deleted_at IS NULL AND kind != 'dir' ORDER BY path_key LIMIT 200")->fetchAll(), 'path');
    if (($w = pf_words_path()) !== null && !in_array($w, $paths, true)) $paths[] = $w . '   ← טבלת המילים כקובץ';
    $kd = pf_by_path('js/kol-data.js');
    $src = $kd && $kd['kind'] === 'file' ? pf_blob_get($kd['sha']) : (string)@file_get_contents(dirname(__DIR__) . '/assets/project-seed/kol-data.js');
    $head = preg_match('~^\s*/\*(.*?)\*/~s', $src, $m) ? trim(preg_replace('/^\s*\* ?/m', '', $m[1])) : '';
    $head = mb_substr($head, 0, 6000);
    $tree = implode("\n", array_map(fn($p) => "- `$p`", $paths));
    return "### kol.json\n\n```json\n$kolJson\n```\n\n### הקבצים\n\n$tree\n\n### תכונות data-kol-* (מהכותרת של js/kol-data.js)\n\n```\n$head\n```";
}

/** הסקיל להעתקה לצ'אט. נבנה מהקבצים עצמם בכל קריאה. */
function agent_skill(string $id, string $secret, string $name): string
{
    $url = agent_base_url();
    $project = agent_project_text();
    return <<<MD
---
name: kol-maroko-site
description: עריכת עמוד "קול מרוקו" (maghreb.co.il/lexicon) דרך הסטודיו — קבצי הפרויקט (HTML, CSS, JS) והמילים. השתמש בזה כשמבקשים לשנות טקסט, עיצוב, קוד או מילים בעמוד קול מרוקו.
---

# קול מרוקו — עריכה מצ'אט

העמוד הוא פרויקט של קבצים, כמו כל אתר: HTML, CSS, JS ו-`data/words.json`. אתה עורך את הקבצים דרך הסטודיו. כל שמירה נכנסת
להיסטוריה של הקובץ עם 🤖, ו{$name} רואה ומבטל בלחיצה. **אין פרסום**: {$name} מפרסם לאתר בעצמו. אין לך גישה לאתר, לשרת או
לשום דבר אחר — רק לפעולות שכאן.

## כניסה

- כתובת: `{$url}`
- שם משתמש: `{$id}`
- סיסמה: `{$secret}`

בכל בקשה — שתי כותרות: `X-Kol-User` ו-`X-Kol-Password`. בקשות POST — גוף JSON. עד 120 בקשות בדקה.

```bash
curl -s "{$url}?action=tree" -H "X-Kol-User: {$id}" -H "X-Kol-Password: {$secret}"
```

הסיסמה סודית: לא להדפיס אותה למשתמשים אחרים ולא לשמור אותה בקוד או בריפו.

## מה אפשר

| פעולה | איך | מה |
| --- | --- | --- |
| `help` | GET | התיאור הזה, עם מבנה הפרויקט העדכני |
| `tree` | GET | כל הקבצים: path, kind, size, rev — ו-kol.json |
| `read` | GET `&path=` | התוכן ו-`rev` |
| `edit` | POST `{path, old, new, rev}` | החלפה של קטע מדויק (`old` — בדיוק פעם אחת בקובץ). הדרך המועדפת |
| `write` | POST `{path, content, rev}` | כתיבה של הקובץ כולו |
| `create` | POST `{path, content}` | קובץ חדש (לא דורס; לא ב-media/) |
| `search` | GET `&q=` (`&regex=1`, `&case=1`) | חיפוש בכל הקבצים |
| `versions` | GET `&path=` | הגרסאות של קובץ |
| `preview_url` | GET | כתובת לתצוגה החיה (30 דקות) — לראות את התוצאה |
| `words` | GET | `data/words.json` — המילים כקובץ, ו-`rev` |
| `words_save` | POST `{content, rev}` ואז `{content, rev, apply: true, token}` | שמירת המילים: קודם "מה ישתנה" (חוזר כטקסט + token), ואז החלה |
| `word_save` | POST `{id?, word, meaning, topics[], audio?}` | מילה אחת: עדכון (עם id) או חדשה |
| `word_delete` | POST `{id}` | מחיקה (לסל) |
| `topic_op` | POST `{op: rename\|delete, name, to?}` | נושא |

`rev` ישן — 409 עם התוכן הנוכחי ב-`conflict`: לקרוא שוב, למזג, ולשלוח שוב. אין דריסה.

## מבנה הפרויקט

$project

## כללים

1. קודם לקרוא (`tree` / `read`), אחר כך לשנות — ולשלוח את ה-`rev` שקיבלת. עריכה קטנה ומדויקת (`edit`) עדיפה על כתיבה מלאה.
2. **כל כיתוב בעמוד הוא תוכן של אלמנט ב-HTML** — לא ב-CSS ולא ב-JS. העמוד בעברית ומימין לשמאל.
3. תכונות `data-kol-*` מחברות אלמנטים לנתונים — לא לשנות את שמן. אזור `data-kol-longtext` מגיע מעורך התמלילים — לא לערוך אותו כאן.
4. `js/kol-data.js` הוא קובץ מערכת: מותר לשנות, והוא יסומן "שונה".
5. אחרי שמירה — `preview_url` כדי לוודא. בסוף — לומר למשתמש מה השתנה, ושצריך לפרסם בסטודיו (🚀) כדי שזה יגיע לאתר.
6. לפני הפעולה הראשונה שלך נשמרת נקודת שחזור — בעל האתר יכול לבטל בלחיצה את כל מה שעשית. זו לא סיבה לעבוד ברישול.
7. הקוד רץ באתר רק בתוך העמוד המבודד. אל תנסה לעקוף את זה — זה מכוון.
MD;
}

/** "help" לצ'אט: הסקיל בלי הסיסמה. */
function agent_help(array $user): array
{
    $skill = preg_replace('/^- סיסמה: .*$/m', '- סיסמה: (מה שקיבלת)', agent_skill((string)$user['agent'], '***', $user['name']));
    return ['skill' => $skill, 'you' => ['name' => $user['name'], 'role' => 'עורך — קבצים ומילים, בלי פרסום']];
}
