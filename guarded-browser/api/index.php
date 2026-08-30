<?php
/**
 * נקודת הקצה היחידה של האפליקציה.
 *
 * ‏?do=register | login | policy | check | heartbeat
 *
 * ריכוז בקובץ אחד ולא חמישה: כל הבקשות חולקות אימות, טיפול בשגיאות
 * ופורמט תשובה, והפיצול היה מייצר חמישה עותקים של אותה חמישייה.
 *
 * ‏האכיפה כפולה בכוונה. האפליקציה מקבלת את המדיניות ואוכפת אותה
 * מקומית כדי שכל לחיצה לא תדרוש רשת; השרת אוכף שוב בכל רענון. לקוח
 * שנפרץ יכול להיתקע על מדיניות ישנה, לא להמציא לעצמו חדשה.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/alerts.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function out(array $payload, int $code = 200): never {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function bad(string $message, string $code = 'error', int $http = 400): never {
    out(['ok' => false, 'error' => $message, 'code' => $code], $http);
}

/** גוף הבקשה כ-JSON, או מערך ריק. */
function body(): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    return $cache = is_array($data) ? $data : [];
}

function field(string $name, string $default = ''): string {
    $v = body()[$name] ?? $_POST[$name] ?? $_GET[$name] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

/** המשתמש המחובר, או 401. */
function requireUser(): array {
    $u = userByToken(bearerToken());
    if (!$u) bad('נדרשת כניסה מחדש', 'unauthorized', 401);
    return $u;
}

/**
 * המדיניות כפי שהאפליקציה צריכה אותה — הגדרות, כללים, ומצב הרגע.
 *
 * הכללים נשלחים במלואם כדי שהאכיפה המקומית תוכל להכריע בלי רשת.
 * הם אינם סוד: הם רשימת מה שמותר למשתמש הזה ממילא.
 */
function policyPayload(array $user): array {
    $uid    = (int) $user['id'];
    $policy = policyFor($uid);
    $tz     = (string) $policy['timezone'];
    $set    = ruleSetFor($uid);

    $used  = usedTodaySeconds($uid, $tz);
    $state = evaluate($user, $policy, $set, nowIn($tz), ['url' => '', 'used_today' => $used]);

    $quota = (int) $policy['daily_quota_min'];

    // הקטגוריות נשלחות כמפה מצומצמת: רק דומיינים ששייכים לקטגוריה
    // שיש עליה כלל. אין טעם לשלוח למכשיר קטלוג של מאות דומיינים
    // שאיש לא הגדיר עליהם דבר.
    $active = array_keys($set['categories']);
    $slim   = [];
    foreach ($set['domain_map'] as $domain => $cats) {
        $hit = array_values(array_intersect($cats, $active));
        if ($hit) $slim[$domain] = $hit;
    }

    return [
        'ok'   => true,
        'user' => [
            'id'       => $uid,
            'username' => $user['username'],
            'name'     => $user['display_name'] ?: $user['username'],
            'status'   => $user['status'],
        ],
        'policy' => [
            'mode'              => $policy['mode'],
            'posture'           => $policy['posture'],
            'blocked_types'     => $policy['blocked_types'],
            'ad_block'          => $policy['ad_block'],
            'timezone'          => $tz,
            'days_mask'         => (int) $policy['days_mask'],
            'window_start'      => $policy['window_start'],
            'window_end'        => $policy['window_end'],
            'daily_quota_min'   => $quota,
            'session_max_min'   => (int) $policy['session_max_min'],
            'allow_downloads'   => (bool) $policy['allow_downloads'],
            'block_screenshots' => (bool) $policy['block_screenshots'],
            'keep_history'      => (bool) $policy['keep_history'],
        ],
        'rules' => array_map(fn($r) => [
            'label'     => $r['label'],
            'pattern'   => $r['pattern'],
            'scope'     => $r['scope'],
            'action'    => $r['action'],
            'show_tile' => (bool) $r['show_tile'],
        ], $set['rules']),
        /*
         * אריחים — מה שמוצג במסך הפתיחה.
         *
         * נפרד מ-rules בכוונה: rules הוא רשימת אכיפה, ופריט יוטיוב
         * מאושר אינו כלל כתובת. אילו הוזרק לשם, האפליקציה הייתה
         * מתייחסת אליו כהיתר גורף לדומיין ועוקפת את כללי הפלטפורמה.
         */
        'tiles'          => tilesFor($set),
        // הרשימות נשלחות למכשיר כדי שהחסימה תקרה שם, לפני הטעינה.
        // חסימה שמחכה לשרת היא פרסומת שכבר ירדה.
        'ad_hosts'       => adHosts(),
        'ad_css'         => adCssSelectors(),
        'categories'     => $set['categories'],
        'domain_map'     => $slim,
        'platforms'      => array_map(fn($p) => [
            'mode'         => $p['mode'],
            'allow_search' => (bool) $p['allow_search'],
            'allow_shorts' => (bool) $p['allow_shorts'],
        ], $set['platforms']),
        'platform_items' => $set['platform_items'],
        'state' => [
            'allowed'        => $state['allow'],
            'code'           => $state['code'],
            'reason'         => $state['reason'],
            'used_today_sec' => $used,
            'quota_left_sec' => $quota > 0 ? max(0, $quota * 60 - $used) : -1,
        ],
    ];
}


/**
 * האריחים שהמשתמש יראה: כללי כתובות שסומנו להצגה, ובנוסף כל פריט
 * יוטיוב שאושר.
 *
 * בלי החלק השני, ערוץ מאושר אינו נגיש כלל במצב קיוסק — אין שורת
 * כתובת, ואין אריח שמוביל אליו.
 */
function tilesFor(array $set): array {
    $tiles = [];

    foreach ($set['rules'] as $r) {
        if ($r['action'] !== 'allow' || !$r['show_tile']) continue;
        $tiles[] = ['label' => $r['label'] ?: $r['pattern'], 'url' => $r['pattern'],
                    'kind' => 'url'];
    }

    foreach (($set['platform_items'][PLATFORM_YOUTUBE] ?? []) as $kind => $items) {
        foreach ($items as $id => $action) {
            if ($action !== 'allow') continue;
            $url = youTubeItemUrl((string) $kind, (string) $id);
            if ($url === '') continue;
            $tiles[] = ['label' => youTubeItemFallbackLabel((string) $kind, (string) $id),
                        'url' => $url, 'kind' => 'youtube'];
        }
    }
    return $tiles;
}

try {
    $do = $_GET['do'] ?? '';

    /* ── הרשמה עצמית ──────────────────────────────────────────────
     * נוצר חשבון pending בלבד. אין אסימון ואין גישה עד שהמנהל מאשר.
     */
    if ($do === 'register') {
        $username = field('username');
        $password = field('password');

        if ($e = usernameProblem($username)) bad($e, 'bad_username');
        if ($e = passwordProblem($password)) bad($e, 'bad_password');

        if (one('SELECT id FROM users WHERE username = ?', [$username])) {
            bad('שם המשתמש הזה כבר תפוס', 'taken');
        }

        db()->beginTransaction();
        q('INSERT INTO users (username, email, display_name, password_hash, status, created_at)
           VALUES (?, ?, ?, ?, ?, ?)',
          [$username, mb_substr(field('email'), 0, 200), mb_substr(field('name'), 0, 80),
           hashPassword($password), 'pending', nowIso()]);
        $uid = (int) db()->lastInsertId();
        // שורת מדיניות נוצרת מיד, כדי שהפאנל יראה משתמש שלם לעריכה.
        q('INSERT INTO policies (user_id, updated_at) VALUES (?, ?)', [$uid, nowIso()]);
        db()->commit();

        audit($uid, 'register', true, '', 'pending', $username);
        out(['ok' => true, 'status' => 'pending',
             'message' => 'ההרשמה נקלטה. החשבון ייפתח לאחר אישור המנהל.']);
    }

    /* ── כניסה ─────────────────────────────────────────────────────
     * מחזירה אסימון מכשיר. חשבון שאינו active מקבל סירוב עם הסיבה,
     * ולא אסימון שאי אפשר להשתמש בו.
     */
    if ($do === 'login') {
        $username = field('username');
        $password = field('password');

        if (tooManyFailures($username)) {
            bad('יותר מדי ניסיונות. נסו שוב בעוד רבע שעה.', 'rate_limited', 429);
        }

        $u = one('SELECT * FROM users WHERE username = ?', [$username]);

        // אותה הודעה לשם שגוי ולסיסמה שגויה: הודעה מפורטת מאשרת
        // לתוקף אילו שמות קיימים.
        if (!$u || !password_verify($password, $u['password_hash'])) {
            audit((int) ($u['id'] ?? 0), 'login_fail', false, '', 'bad_credentials', $username);
            bad('שם משתמש או סיסמה שגויים', 'bad_credentials', 401);
        }

        $state = accountState($u, nowIn((string) policyFor((int) $u['id'])['timezone']));
        if (!$state['allow']) {
            audit((int) $u['id'], 'login_denied', false, '', $state['code']);
            bad($state['reason'], $state['code'], 403);
        }

        $max   = (int) policyFor((int) $u['id'])['max_devices'];
        $token = registerDevice((int) $u['id'], field('device_name'), field('device_id'), $max);
        audit((int) $u['id'], 'login', true, '', 'ok', field('device_name'));

        out(['ok' => true, 'token' => $token] + policyPayload($u));
    }

    /* ── רענון מדיניות ─────────────────────────────────────────── */
    if ($do === 'policy') {
        out(policyPayload(requireUser()));
    }

    /* ── בדיקת כתובת ───────────────────────────────────────────────
     * האפליקציה כבר הכריעה מקומית. זו ההכרעה המחייבת.
     */
    if ($do === 'check') {
        $user   = requireUser();
        $uid    = (int) $user['id'];
        $policy = policyFor($uid);
        $tz     = (string) $policy['timezone'];

        $url  = field('url');
        $main = (string) (body()['main_frame'] ?? '1') !== '0';

        $d = evaluate($user, $policy, ruleSetFor($uid), nowIn($tz), [
            'url'          => $url,
            'main_frame'   => $main,
            'content_type' => (string) (body()['content_type'] ?? ''),
            'used_today'   => usedTodaySeconds($uid, $tz),
            'session'      => (int) (body()['session_sec'] ?? 0),
        ]);

        // ניווט בלבד נרשם. משאב נלווה היה מציף את היומן באלפי שורות.
        if ($main) audit($uid, 'nav', $d['allow'], $url, $d['code']);

        /*
         * מה שהאפליקציה החליטה, כדי שאפשר יהיה להשוות.
         *
         * ‏client_allowed מגיע מהאפליקציה עצמה, ולכן אינו ראיה —
         * לקוח שנפרץ לגמרי פשוט לא ישלח אותו. אבל פריצה חלקית,
         * שבה האכיפה המקומית נשברה והדיווח נשאר, נתפסת כאן מיד.
         * וגם בלעדיו, כל ניווט עדיין נבדק בשרת.
         */
        if ($main) {
            $claimed = body()['client_allowed'] ?? null;
            if ($claimed !== null) {
                $host = normalizeUrl($url)['host'] ?? '';
                checkEnforcementGap($uid, $url, (bool) $claimed, $d, platformOf($host));
            }
            if (!$d['allow']) checkProbing($uid, $url);
        }

        out(['ok' => true, 'allowed' => $d['allow'], 'code' => $d['code'], 'reason' => $d['reason']]);
    }

    /* ── פעימה ─────────────────────────────────────────────────────
     * צוברת זמן צפייה ומחזירה את המצב. זה גם ערוץ הניתוק: מנהל
     * שמשעה חשבון או מוחק מכשיר — הפעימה הבאה מחזירה סירוב.
     */
    if ($do === 'heartbeat') {
        $user   = requireUser();
        $uid    = (int) $user['id'];
        $policy = policyFor($uid);
        $tz     = (string) $policy['timezone'];

        // חסם עליון לפעימה: דיווח מנופח לא יוכל לשרוף מכסה של יום.
        $seconds = max(0, min(300, (int) (body()['seconds'] ?? 0)));
        addUsage($uid, $tz, $seconds);

        $used = usedTodaySeconds($uid, $tz);
        $d = evaluate($user, $policy, ruleSetFor($uid), nowIn($tz), [
            'url' => '', 'used_today' => $used,
            'session' => (int) (body()['session_sec'] ?? 0),
        ]);

        $quota = (int) $policy['daily_quota_min'];
        out(['ok' => true, 'allowed' => $d['allow'], 'code' => $d['code'], 'reason' => $d['reason'],
             'used_today_sec' => $used,
             'quota_left_sec' => $quota > 0 ? max(0, $quota * 60 - $used) : -1]);
    }

    bad('פעולה לא מוכרת', 'unknown_action', 404);

} catch (Throwable $e) {
    error_log('guarded-browser api: ' . $e->getMessage());
    bad('שגיאת שרת', 'server', 500);
}
