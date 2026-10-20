<?php
/**
 * כניסה דרך הפלטפורמה: קונסולת המפתח (dev/), הפאנל הישן (admin/) ואפליקציית ההורים (parent/).
 *
 * החוק: platform/docs/auth.md; המדריך: platform/docs/sso.md, סעיפים 0 ו-6. כל ההורים נכנסים דרך הפלטפורמה
 * (בעל המערכת, 2026-10-20). רק הילדים (אפליקציית האנדרואיד) נשארים מחוץ לה — ה-API שלה ב-"public".
 *
 * מחוברת: השער כותב את הזהות ל-$_SERVER['PLATFORM_USER'] בכל בקשת PHP, רק למי שיש לו הרשאה. בממשקים
 * שאינם ציבוריים זהות חסרה פירושה שהאפליקציה מנותקת מהשער — ואז הכניסה בסיסמה חוזרת לעבוד.
 *
 * קישור: לפי sub, ואם אין — לפי מייל. אין התאמה ויש חשבונות שעוד לא קושרו — מסך חד-פעמי ("יש לי כבר
 * חשבון" / "אין לי חשבון"). אין חשבונות כאלה בכלל — נפתח חשבון חדש מיד, פעיל: ההרשאה בפלטפורמה היא
 * האישור. הורה — אף פעם לא נפתח לבד: חשבון משפחה פותח מנהל המערכת, ומגדלור מזמין את ההורה לפלטפורמה
 * (platformProvision). הסיסמה הישנה לא נמחקת — היא רק חסומה כל עוד האפליקציה מחוברת.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

const PLATFORM_CLIENT = 'guarded-browser';
const PLATFORM_CONFIG_URL = 'https://mbe-plus.com/mini_projects/platform/sso.php?action=config&client=' . PLATFORM_CLIENT;

/** לכל ממשק — הטבלה שלו, אילו שורות בה הן חשבונות של הממשק, ועמודות שם הכניסה והמייל. */
const PLATFORM_KINDS = [
    'dev'    => ['table' => 'developers', 'where' => '1 = 1',        'user' => 'username', 'email' => 'email'],
    'admin'  => ['table' => 'users',      'where' => 'is_admin = 1', 'user' => 'username', 'email' => 'email'],
    // הורה: שם הכניסה הוא בדרך כלל המייל עצמו.
    'parent' => ['table' => 'parents',    'where' => '1 = 1',        'user' => 'login',    'email' => 'login'],
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
    ['table' => $t, 'where' => $w, 'email' => $e] = PLATFORM_KINDS[$kind];
    $row = one("SELECT * FROM $t WHERE $w AND platform_sub = ?", [$id['sub']]);
    if ($row) return $row;
    if ($id['email'] === '') return null;
    $row = one("SELECT * FROM $t WHERE $w AND platform_sub = '' AND $e <> '' AND LOWER($e) = ? ORDER BY id LIMIT 1",
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
    ['table' => $t, 'where' => $w, 'user' => $u, 'email' => $e] = PLATFORM_KINDS[$kind];
    $key = "link:$kind:" . $id['sub'];
    if (tooManyFailures($key, 5, 15)) return 'יותר מדי ניסיונות. נסו שוב בעוד רבע שעה.';
    $old = one("SELECT * FROM $t WHERE $w AND $u = ?", [$kind === 'parent' ? strtolower(trim($username)) : trim($username)]);
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
    if ($e === 'email') {
        q("UPDATE $t SET platform_sub = ?, email = CASE WHEN email = '' THEN ? ELSE email END WHERE id = ?",
          [$id['sub'], $id['email'], $old['id']]);
    } else {
        q("UPDATE $t SET platform_sub = ? WHERE id = ?", [$id['sub'], $old['id']]);
    }
    db()->commit();
    audit((int) $old['id'], 'platform_link', true, '', $kind, 'password:' . $old['id']);
    return '';
}

/**
 * מחוברת: החשבון של מי שנכנס דרך הפלטפורמה, וה-session שלו בממשק הזה. ה-session שייך לחשבון אחר
 * (מישהו אחר נכנס לפלטפורמה) — מוחלף. אין חשבון ואין מה לקשר — נפתח מיד (לא להורה: חשבון משפחה פותח
 * מנהל המערכת). null = להציג את המסך החד-פעמי.
 */
function platformSession(string $kind, array $id): ?array {
    $row = platformLinked($kind, $id)
        ?? ($kind !== 'parent' && platformUnlinkedCount($kind) === 0 ? platformCreate($kind, $id) : null);
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

/* ── הזמנת הורים לפלטפורמה (platform/docs/sso.md, סעיף 6) ─────────────────────
 *
 * מגדלור פותחת להורה חשבון בפלטפורמה עם הרשאה למגדלור בלבד, והפלטפורמה שולחת לו מייל לבחירת סיסמה.
 * הבקשה חתומה במפתח Ed25519 של מגדלור (נפרד ממפתח החתימה של המכשירים, lib/signing.php). הפלטפורמה
 * מורידה את המפתח הציבורי מ-api/index.php?do=platform_key (app.json → "provision").
 */

const PLATFORM_PROVISION_URL = 'https://mbe-plus.com/mini_projects/platform/provision.php';
const PLATFORM_KID = 'mg1';

/** המפתח הסודי להזמנות — נוצר בפעם הראשונה ונשמר במסד (שורד פריסות). */
function provisionSecret(): string {
    $row = one("SELECT value FROM app_meta WHERE key = 'provision_sk'");
    $sk = $row ? base64_decode((string) $row['value'], true) : false;
    if (is_string($sk) && strlen($sk) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) return $sk;
    $sk = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
    q("INSERT OR REPLACE INTO app_meta (key, value, updated_at) VALUES ('provision_sk', ?, ?)", [base64_encode($sk), nowIso()]);
    return $sk;
}

/** מה ש-key_url מחזיר לפלטפורמה. */
function provisionKeyPayload(): array {
    return ['client' => PLATFORM_CLIENT, 'keys' => [['kid' => PLATFORM_KID, 'alg' => 'Ed25519',
            'key' => base64_encode(sodium_crypto_sign_publickey_from_secretkey(provisionSecret()))]]];
}

/**
 * הזמנה (grant=true) או הסרת ההרשאה (grant=false). מוחזר [status, sub, שגיאה]; status: invited / granted /
 * blocked / revoked / unknown. ‏$send — להחלפה בבדיקות.
 */
function platformProvision(string $email, string $name, bool $grant, ?callable $send = null): array {
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return ['', '', 'אין מייל — אי אפשר להזמין לפלטפורמה'];
    // השם בפלטפורמה: 1–60 תווים (validate_display_name) — אצלנו עד 80.
    $name = mb_substr(trim($name) !== '' ? trim($name) : $email, 0, 60);
    $payload = ['email' => $email, 'name' => $name, 'grant' => $grant,
                'next' => '/mini_projects/' . PLATFORM_CLIENT . '/parent/'];
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $time = (string) time();
    $nonce = bin2hex(random_bytes(16));
    $sig = sodium_crypto_sign_detached("provision\n$time\n$nonce\n" . hash('sha256', $body), provisionSecret());
    $headers = ['Content-Type: application/json', 'X-Embed-Client: ' . PLATFORM_CLIENT, "X-Embed-Time: $time",
                "X-Embed-Nonce: $nonce", 'X-Embed-Signature: ' . PLATFORM_KID . '.' . base64_encode($sig)];
    $send ??= function (string $body, array $headers): array {
        $ch = curl_init(PLATFORM_PROVISION_URL);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers,
                                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 6]);
        $out = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => $out];
    };
    $r = $send($body, $headers);
    $j = json_decode((string) ($r['body'] ?? ''), true);
    if (($r['status'] ?? 0) !== 200 || !is_string($j['status'] ?? null)) {
        logProblem('platform provision: HTTP ' . ($r['status'] ?? 0) . ' ' . substr((string) ($r['body'] ?? ''), 0, 300));
        return ['', '', 'הפלטפורמה לא ענתה: ' . ((string) ($j['error'] ?? '') ?: 'נסו שוב')];
    }
    audit(0, 'platform_provision', true, '', $j['status'], $email);
    return [$j['status'], (string) ($j['sub'] ?? ''), ''];
}

/**
 * הזמנת הורה קיים: חשבון בפלטפורמה + הרשאה, וה-sub נשמר אצלו (הקישור מיידי). שם כניסה שאינו מייל — אי
 * אפשר; ההורה יקושר במסך החד-פעמי כשייפתח לו חשבון. מוחזר [status, שגיאה].
 */
function parentInvite(int $parentId, ?callable $send = null): array {
    $p = one('SELECT * FROM parents WHERE id = ?', [$parentId]);
    if (!$p) return ['', 'ההורה לא נמצא'];
    [$status, $sub, $err] = platformProvision((string) $p['login'], (string) $p['name'], true, $send);
    if ($err) return ['', $err];
    if ($sub !== '' && $status !== 'blocked') {
        // ‏sub שכבר שייך להורה אחר — לא מעבירים בשקט: אותו אדם לא יכול להיות שני הורים שונים.
        $other = one('SELECT id FROM parents WHERE platform_sub = ? AND id <> ?', [$sub, $parentId]);
        if (!$other) q('UPDATE parents SET platform_sub = ? WHERE id = ?', [$sub, $parentId]);
    }
    return [$status, ''];
}
