<?php
/**
 * הילד והמדיניות שלו — המקום היחיד שמשנה אותם.
 *
 * עד שלב 3 הלוגיקה ישבה בתוך admin/user.php וקראה את $_POST ישירות. עכשיו
 * גם אפליקציית ההורה משנה הרשאות, ולכן היא כאן: פונקציות שמקבלות מערך
 * ומחזירות שגיאה (או ''), ושני הממשקים קוראים להן. כלל יוטיוב שמנותב
 * לרשימת יוטיוב, למשל, לא יכול להיות מתוחזק בשני עותקים.
 *
 * הבדיקה "הילד הזה שייך להורה הזה" אינה כאן — היא ב-childOf, והקורא חייב
 * לעבור דרכה לפני כל אחת מהפונקציות.
 */

declare(strict_types=1);

require_once __DIR__ . '/accounts.php';
require_once __DIR__ . '/platforms.php';
require_once __DIR__ . '/adblock.php';

/** הילד — רק אם הוא בחשבון הזה. כל קריאה של הורה עוברת כאן. */
function childOf(int $accountId, int $childId): ?array {
    if ($accountId <= 0 || $childId <= 0) return null;
    return one('SELECT * FROM users WHERE id = ? AND account_id = ? AND is_admin = 0', [$childId, $accountId]);
}

/* ── יצירה, מחיקה, פרטי כניסה ─────────────────────────────────── */

/**
 * ילד חדש בחשבון. מוחזר [id, שם משתמש, סיסמה, שגיאה].
 *
 * עד הצימוד ב-QR (שלב 4) המכשיר נכנס בשם משתמש וסיסמה, ולכן ההורה מקבל
 * אותם פעם אחת. אחרי שלב 4 הם יישארו רק כגיבוי.
 */
function childCreate(int $accountId, string $name): array {
    $a = accountRow($accountId);
    if (!$a) return [0, '', '', 'החשבון לא נמצא'];
    $name = trim($name);
    if ($name === '' || mb_strlen($name) > 40) return [0, '', '', 'שם הילד: 1–40 תווים'];

    $max = (int) $a['max_children'];
    $have = (int) one('SELECT COUNT(*) n FROM users WHERE account_id = ? AND is_admin = 0', [$accountId])['n'];
    if ($max > 0 && $have >= $max) return [0, '', '', "הגעתם למספר הילדים בחשבון ($max). אפשר לבקש הגדלה ממנהל המערכת."];

    do { $username = 'k' . random_int(100000, 999999); }
    while (one('SELECT id FROM users WHERE username = ?', [$username]));
    $pw = tempPassword();

    db()->beginTransaction();
    q('INSERT INTO users (username, display_name, password_hash, status, created_at, approved_at, account_id)
       VALUES (?, ?, ?, ?, ?, ?, ?)', [$username, $name, hashPassword($pw), 'active', nowIso(), nowIso(), $accountId]);
    $id = (int) db()->lastInsertId();
    // ברירת מחדל בטוחה: קיוסק, הכול חסום, יוטיוב כבוי. ההורה פותח מכאן.
    q('INSERT INTO policies (user_id, mode, posture, ad_block, updated_at) VALUES (?, ?, ?, ?, ?)',
      [$id, MODE_KIOSK, POSTURE_DENY, implode(',', array_keys(adBlockCatalog())), nowIso()]);
    db()->commit();
    return [$id, $username, $pw, ''];
}

/** סיסמה חדשה למכשירים של הילד; כל המכשירים שכבר מחוברים מתנתקים. */
function childNewPassword(int $childId): string {
    $pw = tempPassword();
    q('UPDATE users SET password_hash = ? WHERE id = ?', [hashPassword($pw), $childId]);
    q('DELETE FROM devices WHERE user_id = ?', [$childId]);
    return $pw;
}

/** מחיקה מלאה — כולל היומן וההתרעות. ילד שנמחק לא משאיר אחריו היסטוריה. */
function childDelete(int $childId): void {
    db()->beginTransaction();
    foreach (['audit', 'alerts', 'traces'] as $t) q("DELETE FROM $t WHERE user_id = ?", [$childId]);
    q('DELETE FROM users WHERE id = ?', [$childId]);   // השאר — ON DELETE CASCADE
    db()->commit();
}

/* ── צימוד מכשיר ─────────────────────────────────────────────── */

const PAIR_TTL_MIN = 10;

/** הקוד כפי שמוקלד: אותיות קטנות, בלי רווחים ומקפים. */
function cleanPairCode(string $code): string {
    return strtolower(preg_replace('/[\s-]+/', '', $code));
}

/**
 * קוד צימוד חדש לילד. מוחזר [קוד, תוקף]. קוד קודם שלא נוצל — מבוטל:
 * רק הקוד שמוצג עכשיו על המסך של ההורה צריך לעבוד.
 */
function pairCreate(int $childId): array {
    q("DELETE FROM pair_codes WHERE child_id = ? AND used_at = ''", [$childId]);
    q('DELETE FROM pair_codes WHERE expires_at < ?', [nowIso()]);
    $code = tempPassword(8);
    $exp = gmdate('Y-m-d\TH:i:s\Z', time() + PAIR_TTL_MIN * 60);
    q('INSERT INTO pair_codes (code_hash, child_id, created_at, expires_at) VALUES (?, ?, ?, ?)',
      [hash('sha256', $code), $childId, nowIso(), $exp]);
    return [$code, $exp];
}

/**
 * מכשיר מצטרף בקוד. מוחזר [ילד, אסימון, שגיאה].
 *
 * אותה שרשרת בדיקות כמו בכניסה בסיסמה: הילד פעיל, החשבון לא חוסם,
 * ומכסת המכשירים של החשבון. הקוד נשרף רק אחרי שהכול עבר — קוד שנכשל
 * בגלל מכסה עדיין תקף אחרי שההורה ניתק מכשיר ישן.
 */
function pairRedeem(string $code, string $deviceName, string $deviceId): array {
    $code = cleanPairCode($code);
    if (!preg_match('/^[a-z0-9]{8}$/', $code)) return [null, '', 'הקוד לא תקין — 8 אותיות וספרות'];
    $row = one("SELECT * FROM pair_codes WHERE code_hash = ? AND used_at = '' AND expires_at > ?",
               [hash('sha256', $code), nowIso()]);
    if (!$row) return [null, '', 'הקוד לא נמצא או שפג תוקפו. בקשו מההורה קוד חדש.'];
    $u = one('SELECT * FROM users WHERE id = ?', [$row['child_id']]);
    if (!$u) return [null, '', 'הקוד לא נמצא או שפג תוקפו. בקשו מההורה קוד חדש.'];
    if ($u['status'] !== 'active') return [null, '', 'הילד הזה מושהה. ההורה צריך להחזיר אותו קודם.'];
    if ($block = accountBlock($u)) return [null, '', $block[1]];
    if (accountDeviceQuotaFull((int) $u['account_id'], $deviceId)) {
        return [null, '', 'הגעתם למספר המכשירים המרבי בחשבון. נתקו מכשיר ישן באפליקציית ההורה.'];
    }
    $token = registerDevice((int) $u['id'], $deviceName, $deviceId, (int) policyFor((int) $u['id'])['max_devices']);
    q('UPDATE pair_codes SET used_at = ? WHERE code_hash = ?', [nowIso(), $row['code_hash']]);
    return [$u, $token, ''];
}

/* ── מדיניות ───────────────────────────────────────────────────── */

/**
 * עדכון מדיניות — חלקי: רק המפתחות שנשלחו משתנים.
 * כך מסך "זמנים" לא מאפס את "גישה" שנשמר במסך אחר.
 */
function childPolicySave(int $childId, array $in): string {
    $p = policyFor($childId);
    $set = [];

    if (array_key_exists('mode', $in)) {
        if (!in_array($in['mode'], MODES, true)) return 'מצב לא מוכר';
        $set['mode'] = $in['mode'];
    }
    if (array_key_exists('posture', $in)) {
        if (!in_array($in['posture'], POSTURES, true)) return 'ברירת מחדל לא מוכרת';
        $set['posture'] = $in['posture'];
    }
    if (array_key_exists('days', $in)) {
        $mask = 0;
        foreach ((array) $in['days'] as $d) { $d = (int) $d; if ($d >= 0 && $d <= 6) $mask |= (1 << $d); }
        $set['days_mask'] = $mask;
    }
    if (array_key_exists('window_start', $in) || array_key_exists('window_end', $in)) {
        $ws = trim((string) ($in['window_start'] ?? $p['window_start']));
        $we = trim((string) ($in['window_end'] ?? $p['window_end']));
        foreach ([$ws, $we] as $t) {
            if ($t !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t)) return 'שעה לא תקינה (HH:MM)';
        }
        // חצי חלון הוא שגיאה שקטה — שדה אחד ריק מבטל את שניהם.
        if ($ws === '' || $we === '') { $ws = ''; $we = ''; }
        $set['window_start'] = $ws; $set['window_end'] = $we;
    }
    foreach (['daily_quota_min' => 1440, 'session_max_min' => 1440] as $k => $cap) {
        if (!array_key_exists($k, $in)) continue;
        $n = (int) $in[$k];
        if ($n < 0 || $n > $cap) return 'מספר דקות לא תקין';
        $set[$k] = $n;
    }
    if (array_key_exists('max_devices', $in)) $set['max_devices'] = max(1, min(20, (int) $in['max_devices']));
    if (array_key_exists('timezone', $in)) {
        $tz = (string) $in['timezone'];
        $set['timezone'] = in_array($tz, timezone_identifiers_list(), true) ? $tz : 'Asia/Jerusalem';
    }
    if (array_key_exists('types', $in)) {
        $set['blocked_types'] = implode(',', array_values(array_intersect((array) $in['types'], array_keys(contentTypeCatalog()))));
    }
    if (array_key_exists('ads', $in)) {
        $set['ad_block'] = implode(',', array_values(array_intersect((array) $in['ads'], array_keys(adBlockCatalog()))));
    }
    foreach (['allow_downloads', 'block_screenshots', 'keep_history', 'allow_pip', 'allow_background'] as $k) {
        if (array_key_exists($k, $in)) $set[$k] = !empty($in[$k]) ? 1 : 0;
    }

    if ($set) {
        $set['updated_at'] = nowIso();
        upsert('policies', ['user_id' => $childId], $set);
    }

    if (array_key_exists('display_name', $in)) {
        $name = trim((string) $in['display_name']);
        if ($name === '' || mb_strlen($name) > 40) return 'שם הילד: 1–40 תווים';
        q('UPDATE users SET display_name = ? WHERE id = ?', [$name, $childId]);
    }

    // קטגוריות: שורה רק למי שיש עליו החלטה. "ללא" פירושו למחוק.
    if (array_key_exists('categories', $in)) {
        q('DELETE FROM category_rules WHERE user_id = ?', [$childId]);
        foreach ((array) $in['categories'] as $cat => $act) {
            if (!isset(categoryCatalog()[$cat]) || !in_array($act, ['allow', 'deny'], true)) continue;
            q('INSERT INTO category_rules (user_id, category, action, created_at) VALUES (?,?,?,?)',
              [$childId, $cat, $act, nowIso()]);
        }
    }
    return '';
}

/* ── יוטיוב ────────────────────────────────────────────────────── */

/** אילו מצבי יוטיוב החשבון רשאי לתת (זכאות yt_filtered מהמפתח). */
function youTubeModesFor(int $accountId): array {
    $mods = moduleList((string) (accountRow($accountId)['modules'] ?? ''));
    return in_array('yt_filtered', $mods, true) ? ['off', 'restricted', 'full'] : ['off'];
}

function childYouTubeSave(int $childId, string $mode, bool $search, bool $shorts, array $allowedModes): string {
    if (!in_array($mode, ['off', 'restricted', 'full'], true)) return 'מצב יוטיוב לא מוכר';
    if (!in_array($mode, $allowedModes, true)) return 'יוטיוב לא פתוח בחשבון הזה. אפשר לבקש ממנהל המערכת.';
    upsert('platform_rules', ['user_id' => $childId, 'platform' => PLATFORM_YOUTUBE],
           ['mode' => $mode, 'allow_search' => $search ? 1 : 0, 'allow_shorts' => $shorts ? 1 : 0],
           ['created_at' => nowIso()]);
    return '';
}

const YT_KIND_NAMES = ['channel' => 'ערוץ', 'handle' => 'ערוץ', 'video' => 'סרטון', 'playlist' => 'פלייליסט'];

/**
 * ערוץ, סרטון או פלייליסט. מוחזר [הודעה, שגיאה].
 *
 * ההודעה אומרת מה זוהה, לא רק "נוסף": קלט מקוצר הוא ניחוש מושכל,
 * ומי שרואה "סרטון" במקום "ערוץ" מתקן מיד.
 */
function childYouTubeAdd(int $childId, string $paste, string $label = '', string $action = 'allow'): array {
    $p = normalizeYouTubeInput($paste);
    if ($p['kind'] === 'shorts') $p['kind'] = 'video';
    if (!isset(YT_KIND_NAMES[$p['kind']]) || $p['id'] === '') {
        return ['', 'לא זיהיתי ערוץ, סרטון או פלייליסט. אפשר להדביק קישור, או לכתוב רק @שם-הערוץ'];
    }
    $label = trim($label);
    // סרטון: שואלים את יוטיוב למי הוא שייך, כדי שיהיה שם ברשימה.
    if ($p['kind'] === 'video' && $label === '') {
        $owner = one('SELECT title FROM video_owner WHERE platform = ? AND video_id = ?', [PLATFORM_YOUTUBE, $p['id']]);
        if (!$owner) {
            youTubeOwner($p['id']);
            $owner = one('SELECT title FROM video_owner WHERE platform = ? AND video_id = ?', [PLATFORM_YOUTUBE, $p['id']]);
        }
        $label = (string) ($owner['title'] ?? '');
    }
    upsert('platform_items',
           ['user_id' => $childId, 'platform' => PLATFORM_YOUTUBE, 'kind' => $p['kind'], 'item_id' => $p['id']],
           ['label' => mb_substr($label, 0, 120), 'action' => $action === 'deny' ? 'deny' : 'allow'],
           ['created_at' => nowIso()]);
    return ['נוסף כ' . YT_KIND_NAMES[$p['kind']] . ': ' . ($p['kind'] === 'handle' ? '@' : '') . $p['id'], ''];
}

function childYouTubeDelete(int $childId, int $itemId): void {
    q('DELETE FROM platform_items WHERE id = ? AND user_id = ?', [$itemId, $childId]);
}

/* ── כללי כתובות ───────────────────────────────────────────────── */

/**
 * כלל כתובת. מוחזר [הודעה, שגיאה].
 *
 * כתובת יוטיוב מנותבת לרשימת יוטיוב: youtu.be/ID מפנה מיד ל-youtube.com —
 * דומיין אחר, שהכלל כבר אינו חל עליו — וההורה היה רואה "חסום" על משהו
 * שהוא בטוח שהתיר. הקלט נשמר במקום שבו הוא באמת נאכף.
 */
function childRuleAdd(int $childId, string $pattern, string $scope = 'domain', string $label = '',
                      string $action = 'allow', bool $showTile = true): array {
    $pattern = trim($pattern);
    $parsed = normalizeUrl($pattern);
    // ‏normalizeUrl סלחני (גם "a b" עובר בו כשם שרת) — כאן, בקלט מהורה, דורשים שם שרת אמיתי.
    if (!$parsed || !preg_match('/^[a-z0-9.-]+\.[a-z0-9-]+$/i', $parsed['host'])) {
        return ['', 'הכתובת אינה תקינה'];
    }
    if (platformOf($parsed['host']) === PLATFORM_YOUTUBE) {
        [$msg, $err] = childYouTubeAdd($childId, $pattern, $label, $action);
        if ($err) return ['', 'זו כתובת יוטיוב שלא זיהיתי כערוץ, סרטון או פלייליסט. נסו להוסיף אותה באזור יוטיוב.'];
        return ['זו כתובת יוטיוב, ולכן היא נוספה לרשימת יוטיוב — שם היא נאכפת. ' . $msg, ''];
    }
    q('INSERT INTO rules (user_id, label, pattern, scope, action, show_tile, sort_order, created_at)
       VALUES (?,?,?,?,?,?,?,?)',
      [$childId, mb_substr(trim($label), 0, 80), $pattern, in_array($scope, SCOPES, true) ? $scope : 'domain',
       $action === 'deny' ? 'deny' : 'allow', $showTile ? 1 : 0, 0, nowIso()]);
    return ['הכלל נוסף', ''];
}

function childRuleDelete(int $childId, int $ruleId): void {
    q('DELETE FROM rules WHERE id = ? AND user_id = ?', [$ruleId, $childId]);
}

function childRuleToggle(int $childId, int $ruleId): void {
    q('UPDATE rules SET enabled = 1 - enabled WHERE id = ? AND user_id = ?', [$ruleId, $childId]);
}

/* ── מכשירים וזמן ──────────────────────────────────────────────── */

function childDeviceDelete(int $childId, int $deviceId): void {
    q('DELETE FROM devices WHERE id = ? AND user_id = ?', [$deviceId, $childId]);
}

function childUsageReset(int $childId): void {
    q('DELETE FROM usage WHERE user_id = ? AND day = ?', [$childId, todayIn(policyFor($childId)['timezone'])]);
}

/* ── תצוגה ─────────────────────────────────────────────────────── */

/** חסימות היום — מספר בלבד, לכרטיס בבית. */
function childBlockedToday(int $childId, string $tz): int {
    $start = (new DateTimeImmutable('today', zoneOf($tz)))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    return (int) one("SELECT COUNT(*) n FROM audit WHERE user_id = ? AND allowed = 0 AND kind IN ('nav','check')
                      AND at >= ?", [$childId, $start])['n'];
}

/** הכרטיס בבית: מי, מחובר?, כמה זמן היום, מה המצב עכשיו. */
function childCard(array $u): array {
    $id = (int) $u['id'];
    $policy = policyFor($id);
    $tz = (string) $policy['timezone'];
    $used = usedTodaySeconds($id, $tz);
    $live = evaluate($u, $policy, ruleSetFor($id), nowIn($tz), ['url' => '', 'used_today' => $used]);
    $quota = (int) $policy['daily_quota_min'];
    return [
        'id'            => $id,
        'name'          => $u['display_name'] ?: $u['username'],
        'status'        => $u['status'],
        'last_seen_at'  => $u['last_seen_at'],
        'devices'       => (int) one('SELECT COUNT(*) n FROM devices WHERE user_id = ?', [$id])['n'],
        'used_sec'      => $used,
        'quota_min'     => $quota,
        'allowed_now'   => (bool) $live['allow'],
        'reason_now'    => $live['allow'] ? '' : ($live['reason'] ?: $live['code']),
        'blocked_today' => childBlockedToday($id, $tz),
        'mode'          => $policy['mode'],
        'posture'       => $policy['posture'],
    ];
}

/** כל מה שגיליון הילד צריך. */
function childDetail(array $u, array $allowedYt): array {
    $id = (int) $u['id'];
    $p = policyFor($id);
    $mask = (int) $p['days_mask'];
    return childCard($u) + [
        'username' => $u['username'],
        'policy'   => [
            'mode' => $p['mode'], 'posture' => $p['posture'], 'timezone' => $p['timezone'],
            'days' => array_values(array_filter(range(0, 6), fn($d) => ($mask >> $d) & 1)),
            'window_start' => $p['window_start'], 'window_end' => $p['window_end'],
            'daily_quota_min' => (int) $p['daily_quota_min'], 'session_max_min' => (int) $p['session_max_min'],
            'max_devices' => (int) $p['max_devices'],
            'types' => array_values(array_filter(explode(',', (string) $p['blocked_types']))),
            'ads'   => array_values(array_filter(explode(',', (string) $p['ad_block']))),
            'allow_downloads' => (bool) $p['allow_downloads'], 'block_screenshots' => (bool) $p['block_screenshots'],
            'keep_history' => (bool) $p['keep_history'], 'allow_pip' => (bool) $p['allow_pip'],
            'allow_background' => (bool) $p['allow_background'],
        ],
        'categories' => (object) categoryRulesFor($id),
        'rules' => array_map(fn($r) => [
            'id' => (int) $r['id'], 'label' => $r['label'], 'pattern' => $r['pattern'], 'scope' => $r['scope'],
            'action' => $r['action'], 'show_tile' => (bool) $r['show_tile'], 'enabled' => (bool) $r['enabled'],
        ], all('SELECT * FROM rules WHERE user_id = ? ORDER BY sort_order, id', [$id])),
        'youtube' => (function () use ($id, $allowedYt) {
            $r = platformRulesFor($id)[PLATFORM_YOUTUBE] ?? ['mode' => 'off', 'allow_search' => 0, 'allow_shorts' => 0];
            return [
                'mode' => $r['mode'], 'allow_search' => (bool) $r['allow_search'], 'allow_shorts' => (bool) $r['allow_shorts'],
                'allowed_modes' => $allowedYt,
                'items' => array_map(fn($i) => [
                    'id' => (int) $i['id'], 'kind' => $i['kind'], 'item_id' => $i['item_id'],
                    'label' => $i['label'], 'action' => $i['action'],
                ], all('SELECT * FROM platform_items WHERE user_id = ? AND platform = ? ORDER BY kind, id',
                       [$id, PLATFORM_YOUTUBE])),
            ];
        })(),
        'device_list' => array_map(fn($d) => [
            'id' => (int) $d['id'], 'name' => $d['device_name'], 'created_at' => $d['created_at'],
            'last_seen_at' => $d['last_seen_at'],
        ], all('SELECT * FROM devices WHERE user_id = ? ORDER BY last_seen_at DESC', [$id])),
    ];
}

/** הקטלוגים לממשק — שמות ואייקונים, פעם אחת. */
function childCatalogs(): array {
    return [
        'categories' => array_map(fn($c) => ['name' => $c[0], 'icon' => $c[1]], categoryCatalog()),
        'types'      => array_map(fn($t) => ['name' => $t['label'], 'icon' => $t['icon']], contentTypeCatalog()),
        'ads'        => array_map(fn($a) => ['name' => $a[0], 'icon' => $a[1], 'hint' => $a[2]], adBlockCatalog()),
    ];
}
