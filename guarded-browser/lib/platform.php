<?php
/**
 * כניסה דרך הפלטפורמה — רק לממשקי הניהול: קונסולת המפתח (dev/) והפאנל הישן (admin/).
 *
 * החוק: platform/docs/auth.md; המדריך: platform/docs/sso.md, סעיף 0. הורים וילדים (אפליקציית ההורה,
 * אפליקציית הילד) נשארים מחוץ לפלטפורמה, כמו משתתפים מבחוץ — הכתובות שלהם ב-"public" של app.json.
 *
 * מחוברת: השער כותב את הזהות ל-$_SERVER['PLATFORM_USER'] בכל בקשת PHP, רק למי שיש לו הרשאה. ב-admin/
 * וב-dev/ (שאינם ציבוריים) זהות חסרה פירושה שהאפליקציה מנותקת מהשער — ואז הכניסה בסיסמה חוזרת לעבוד.
 *
 * קישור: לפי sub, ואם אין — לפי מייל. אין התאמה ויש חשבונות שעוד לא קושרו — מסך חד-פעמי ("יש לי כבר
 * חשבון" / "אין לי חשבון"). אין חשבונות כאלה בכלל — נפתח חשבון חדש מיד, פעיל: ההרשאה בפלטפורמה היא
 * האישור. הסיסמה הישנה לא נמחקת — היא רק חסומה כל עוד האפליקציה מחוברת.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

const PLATFORM_CLIENT = 'guarded-browser';
const PLATFORM_CONFIG_URL = 'https://mbe-plus.com/mini_projects/platform/sso.php?action=config&client=' . PLATFORM_CLIENT;

/** לכל ממשק — הטבלה שלו, ואילו שורות בה הן חשבונות של הממשק. */
const PLATFORM_KINDS = [
    'dev'   => ['table' => 'developers', 'where' => '1 = 1'],
    'admin' => ['table' => 'users',      'where' => 'is_admin = 1'],
];

/** הזהות מהשער: ['sub', 'name', 'email'], או null כשהאפליקציה מנותקת. */
function platformIdentity(): ?array {
    $id = json_decode((string) ($_SERVER['PLATFORM_USER'] ?? ''), true);
    $sub = (string) ($id['sub'] ?? '');
    if ($sub === '') return null;
    return ['sub' => $sub, 'name' => trim((string) ($id['name'] ?? '')),
            'email' => mb_strtolower(trim((string) ($id['email'] ?? '')))];
}

/**
 * החשבון המקושר לזהות: לפי sub, ואם אין — לפי מייל (ואז הוא מתקשר). null = אין התאמה.
 * "המייל קובע" (sso.md, סעיף 4): אותו מייל = אותו אדם.
 */
function platformLinked(string $kind, array $id): ?array {
    ['table' => $t, 'where' => $w] = PLATFORM_KINDS[$kind];
    $row = one("SELECT * FROM $t WHERE $w AND platform_sub = ?", [$id['sub']]);
    if ($row) return $row;
    if ($id['email'] === '') return null;
    $row = one("SELECT * FROM $t WHERE $w AND platform_sub = '' AND email <> '' AND LOWER(email) = ? ORDER BY id LIMIT 1",
               [$id['email']]);
    if (!$row) return null;
    q("UPDATE $t SET platform_sub = ? WHERE id = ?", [$id['sub'], $row['id']]);
    audit(0, 'platform_link', true, '', $kind, 'email:' . $row['id']);
    return one("SELECT * FROM $t WHERE id = ?", [$row['id']]);
}

/** כמה חשבונות מהסוג הזה עוד לא קושרו — אם אין, אין מה להציע במסך החד-פעמי. */
function platformUnlinkedCount(string $kind): int {
    ['table' => $t, 'where' => $w] = PLATFORM_KINDS[$kind];
    return (int) one("SELECT COUNT(*) n FROM $t WHERE $w AND platform_sub = ''")['n'];
}

/** שם משתמש פנוי לחשבון חדש — מהמייל, ואם תפוס או לא תקין — עם סיומת. */
function platformUsername(string $table, array $id): string {
    $base = preg_replace('/[^a-zA-Z0-9._\-]/', '', strstr($id['email'], '@', true) ?: '');
    if (strlen($base) < 3) $base = 'platform' . preg_replace('/\D/', '', $id['sub']);
    $base = substr($base, 0, 24);
    for ($i = 0, $name = $base; one("SELECT id FROM $table WHERE username = ?", [$name]); $i++) {
        $name = $base . '-' . ($i + 2);
    }
    return $name;
}

/**
 * חשבון חדש — כמו מי שנפתח לבד בממשק הזה (developerSetup / admin/setup.php): פעיל, בלי אישור נוסף.
 * בלי סיסמה (hash ריק — password_verify לעולם לא מצליח מולו): הכניסה היא דרך הפלטפורמה.
 */
function platformCreate(string $kind, array $id): array {
    $now = nowIso();
    $name = mb_substr($id['name'] !== '' ? $id['name'] : 'מנהל', 0, 80);
    if ($kind === 'dev') {
        q('INSERT INTO developers (username, name, email, password_hash, platform_sub, created_at, last_login_at)
           VALUES (?, ?, ?, ?, ?, ?, ?)',
          [platformUsername('developers', $id), $name, $id['email'], '', $id['sub'], $now, $now]);
        $newId = (int) db()->lastInsertId();
    } else {
        db()->beginTransaction();
        q('INSERT INTO users (username, display_name, email, password_hash, platform_sub, status, is_admin, created_at, approved_at)
           VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)',
          [platformUsername('users', $id), $name, $id['email'], '', $id['sub'], 'active', $now, $now]);
        $newId = (int) db()->lastInsertId();
        q('INSERT OR IGNORE INTO policies (user_id, updated_at) VALUES (?, ?)', [$newId, $now]);
        db()->commit();
    }
    audit(0, 'platform_create', true, '', $kind, (string) $newId);
    return one('SELECT * FROM ' . PLATFORM_KINDS[$kind]['table'] . ' WHERE id = ?', [$newId]);
}

/**
 * "יש לי כבר חשבון": שם המשתמש והסיסמה הישנים, פעם אחת. החשבון הישן מתקשר ל-sub, ומי שהיה מקושר
 * קודם (חשבון שנפתח דרך הפלטפורמה) — נשאר, לא מקושר לאיש. 5 ניסיונות → נעילה של רבע שעה.
 * מוחזרת שגיאה, או '' בהצלחה.
 */
function platformLinkOld(string $kind, array $id, string $username, string $password): string {
    ['table' => $t, 'where' => $w] = PLATFORM_KINDS[$kind];
    $key = "link:$kind:" . $id['sub'];
    if (tooManyFailures($key, 5, 15)) return 'יותר מדי ניסיונות. נסו שוב בעוד רבע שעה.';
    $old = one("SELECT * FROM $t WHERE $w AND username = ?", [trim($username)]);
    if (!$old || !password_verify($password, (string) $old['password_hash'])) {
        audit(0, 'login_fail', false, '', 'link', $key);
        return 'שם משתמש או סיסמה שגויים';
    }
    if ($old['platform_sub'] !== '') {
        return $old['platform_sub'] === $id['sub'] ? '' : 'החשבון הזה כבר מחובר לחשבון אחר בפלטפורמה';
    }
    if (($old['status'] ?? 'active') !== 'active') return 'החשבון הזה מושעה';

    db()->beginTransaction();
    q("UPDATE $t SET platform_sub = '' WHERE platform_sub = ?", [$id['sub']]);
    // הסיסמה נשארת: חסומה כל עוד מחוברת, ועובדת שוב כשמנותקת (auth.md, סעיף 4).
    q("UPDATE $t SET platform_sub = ?, email = CASE WHEN email = '' THEN ? ELSE email END WHERE id = ?",
      [$id['sub'], $id['email'], $old['id']]);
    db()->commit();
    audit((int) $old['id'], 'platform_link', true, '', $kind, 'password:' . $old['id']);
    return '';
}

/**
 * מחוברת: החשבון של מי שנכנס דרך הפלטפורמה, וה-session שלו בממשק הזה. ה-session שייך לחשבון אחר
 * (מישהו אחר נכנס לפלטפורמה) — מוחלף. אין חשבון ואין מה לקשר — נפתח מיד. null = להציג את המסך החד-פעמי.
 */
function platformSession(string $kind, array $id): ?array {
    $row = platformLinked($kind, $id) ?? (platformUnlinkedCount($kind) === 0 ? platformCreate($kind, $id) : null);
    $s = sessionRow($kind);
    if (!$row) {
        if ($s) endSession($kind);
        return null;
    }
    if (!$s || (int) $s['user_id'] !== (int) $row['id']) {
        if ($s) endSession($kind);
        startSession($kind, (int) $row['id']);
    }
    return $row;
}

/**
 * מנותקת: האם הכניסה בסיסמה של האפליקציה פתוחה (⚙️ ← אפליקציות בפלטפורמה, sso.php?action=config).
 * נשמרת לדקה. הפלטפורמה לא זמינה — ההגדרה האחרונה; אף פעם לא נקראה — פתוחה, כדי שאפליקציה שיצאה
 * לדרך עצמאית לא תינעל.
 */
function passwordLoginOpen(): bool {
    $cached = json_decode((string) (one("SELECT value FROM app_meta WHERE key = 'platform_login'")['value'] ?? ''), true);
    $login = $cached['login'] ?? null;
    if (!$cached || time() - (int) ($cached['at'] ?? 0) > 60) {
        $ctx = stream_context_create(['http' => ['timeout' => 4, 'ignore_errors' => true]]);
        $raw = PHP_SAPI === 'cli' ? false : @file_get_contents(PLATFORM_CONFIG_URL, false, $ctx);
        $config = $raw ? json_decode($raw, true) : null;
        if (is_array($config['login'] ?? null)) $login = $config['login'];
        q("INSERT OR REPLACE INTO app_meta (key, value, updated_at) VALUES ('platform_login', ?, ?)",
          [json_encode(['at' => time(), 'login' => $login]), nowIso()]);
    }
    return !is_array($login) || !empty($login['password']);
}
