<?php
/**
 * שני סוגי משתמשים: עם כונן ובלי כונן (docs/spec-5-account-tiers.md).
 *
 *   עם כונן — יש לו הרשאה ל-mbe-cloud בפלטפורמה. בלי כונן — משתמש פלטפורמה מחובר, בלי ההרשאה (או שנחסם מהענן במפורש).
 *   בלי כונן: 📁 ו-🤖 — 403 no_drive. 🗂️ — תמיד. 📚 — קריאה; font_add — no_drive (הוא שומר קובץ).
 *
 * מאיפה יודעים (סעיף 4):
 *   - בקשה עם עוגייה (connect.php, me.php — "signed_in" ב-app.json): PLATFORM_USER.granted, שהשער כותב.
 *   - בקשה חתומה או עם אסימון (בלי עוגייה): grant_status בפלטפורמה, שרת-לשרת, חתום ב-Ed25519 בידי הענן. במטמון 60 שניות לכל sub.
 *   - הפלטפורמה לא ענתה — סגור: 📁 ו-🤖 → 503 platform_unavailable. 🗂️ ו-📚 ממשיכים (לא תלויים בהרשאה).
 * בלי require מחוץ לתיקייה (CLAUDE.md, "אפליקציה פנימית עצמאית") — רק HTTPS.
 */

declare(strict_types=1);

require_once __DIR__ . '/clients.php';

const GRANT_TTL = 60;
/** השכבות שדורשות כונן. 🗂️ ו-📚 — לכל משתמש. */
const DRIVE_LAYERS = ['files', 'ai'];
const NO_DRIVE_MSG = 'אין לך כונן בענן — פנו למנהל';

function migrateTier(PDO $pdo): void {
    // drive — מה שידוע לאחרונה (1 / 0 / NULL — עוד לא ידוע): לתצוגה בניהול בלבד. ההחלטה תמיד מהשער או מ-grant_status
    $cols = array_column($pdo->query('PRAGMA table_info(accounts)')->fetchAll(), 'name');
    if (!in_array('drive', $cols, true)) $pdo->exec('ALTER TABLE accounts ADD COLUMN drive INTEGER');
    $pdo->exec('CREATE TABLE IF NOT EXISTS grant_cache (sub TEXT PRIMARY KEY, allowed INTEGER NOT NULL, at INTEGER NOT NULL)');
}

/** granted מהשער, לבקשה הזו — רק אם הזהות היא של אותו חשבון. null — אין זהות כזו בבקשה. */
function identityGranted(array $a): ?bool {
    $id = platformIdentity();
    if (!$id || ($a['platform_sub'] ?? null) === null || !hash_equals((string) $a['platform_sub'], (string) $id['sub'])) return null;
    // שער מלפני PF-002 לא כותב granted — מי שעבר אותו בנתיב רגיל, עבר בהרשאה
    return array_key_exists('granted', $id) ? $id['granted'] === true : true;
}

/** האם לחשבון יש כונן עכשיו. null — לא ידוע (הפלטפורמה לא ענתה). */
function accountGranted(array $a): ?bool {
    $g = identityGranted($a);
    if ($g === null) {
        $sub = $a['platform_sub'] ?? null;
        if ($sub === null || $sub === '') return false;   // חשבון בלי sub (חשבון שירות) — אין לו כונן לשכבות של אפליקציה
        $g = grantStatus((string) $sub);
        if ($g === null) return null;
    }
    if ((int) ($a['drive'] ?? -1) !== ($g ? 1 : 0)) db()->prepare('UPDATE accounts SET drive = ? WHERE id = ?')->execute([$g ? 1 : 0, $a['id']]);
    return $g;
}

/** 📁 / 🤖 בלי כונן — 403 no_drive. לא ידוע — 503 platform_unavailable (סגור). */
function requireDrive(array $a, string $layer): void {
    $g = accountGranted($a);
    if ($g === null) throw new AppError('הפלטפורמה לא עונה כרגע — נסו שוב בעוד רגע', 503, 'platform_unavailable', ['layer' => $layer]);
    if (!$g) throw new AppError(NO_DRIVE_MSG, 403, 'no_drive', ['layer' => $layer]);
}

/** מה זמין לחשבון לפי הסוג שלו: {files, libs, appdata, ai}. לא ידוע — 📁 ו-🤖 false. */
function accountAvailable(array $a): array {
    $g = accountGranted($a) === true;
    return ['files' => $g, 'libs' => true, 'appdata' => true, 'ai' => $g];
}

/** ההיקף בפועל לחשבון הזה: מדיניות ∩ הצהרה (spec-6) ∩ סוג המשתמש (spec-5 סעיף 2). */
function accountScope(array $client, array $a): array {
    require_once __DIR__ . '/policy.php';
    $av = accountAvailable($a);
    return array_values(array_filter(effectiveScope($client), fn($s) => $av[scopeLayer($s)] ?? false));
}

/** grant_status בפלטפורמה (PF-002). במטמון 60 שניות. null — לא ענתה (לא נשמר במטמון). */
function grantStatus(string $sub): ?bool {
    $st = db()->prepare('SELECT allowed, at FROM grant_cache WHERE sub = ?');
    $st->execute([$sub]);
    $row = $st->fetch();
    if ($row && (int) $row['at'] > time() - GRANT_TTL) return (int) $row['allowed'] === 1;
    $allowed = grantStatusAsk($sub);
    if ($allowed === null) return null;
    db()->prepare('INSERT INTO grant_cache (sub, allowed, at) VALUES (?,?,?) ON CONFLICT(sub) DO UPDATE SET allowed = excluded.allowed, at = excluded.at')
        ->execute([$sub, $allowed ? 1 : 0, time()]);
    return $allowed;
}

/** הקריאה עצמה: POST provision.php?action=grant_status, חתום כמו docs/sso.md §6. בבדיקות — $GLOBALS['PLATFORM_GRANT']. */
function grantStatusAsk(string $sub): ?bool {
    if (isset($GLOBALS['PLATFORM_GRANT'])) return ($GLOBALS['PLATFORM_GRANT'])($sub);
    $body = json_encode(['sub' => $sub]);
    $time = (string) time();
    $nonce = bin2hex(random_bytes(16));
    $k = cloudSigningKey();
    $sig = sodium_crypto_sign_detached("grant_status\n$time\n$nonce\n" . hash('sha256', $body), $k['sk']);
    $ch = curl_init(platformBase() . 'provision.php?action=grant_status');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Embed-Client: mbe-cloud', "X-Embed-Time: $time", "X-Embed-Nonce: $nonce",
                               'X-Embed-Signature: ' . $k['kid'] . '.' . base64_encode($sig)],
    ]);
    $out = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $j = is_string($out) ? json_decode($out, true) : null;
    if ($code !== 200 || !is_array($j) || !is_bool($j['allowed'] ?? null)) {
        error_log("mbe-cloud: grant_status — הפלטפורמה לא ענתה ($code)");   // בלי sub ביומן
        return null;
    }
    return $j['allowed'];
}

/** הכתובת של הפלטפורמה — לצד התיקייה של הענן (…/mini_projects/platform/). */
function platformBase(): string {
    return preg_replace('#[^/]+/$#', '', cloudIssuer()) . 'platform/';
}

/** platform_key (ב-public): המפתח הציבורי שהפלטפורמה מאמתת מולו את grant_status ("provision.key_url" ב-app.json). */
function platformKeyJson(): array {
    $k = cloudSigningKey();
    return ['client' => 'mbe-cloud', 'keys' => [['kid' => $k['kid'], 'alg' => 'Ed25519', 'key' => base64_encode($k['pk'])]]];
}
