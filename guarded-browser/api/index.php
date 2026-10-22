<?php
/**
 * נקודת הקצה היחידה של האפליקציה.
 *
 * ‏?do=register | login | pair | policy | check | yt_allowed | heartbeat | ping | request | requests
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
require_once __DIR__ . '/../lib/children.php';
require_once __DIR__ . '/../lib/push.php';
require_once __DIR__ . '/../lib/activity.php';
require_once __DIR__ . '/../lib/appversion.php';
require_once __DIR__ . '/../lib/search.php';
require_once __DIR__ . '/../lib/appcontrol.php';
require_once __DIR__ . '/../lib/signing.php';
require_once __DIR__ . '/../lib/devicesettings.php';
require_once __DIR__ . '/../lib/heartbeat.php';
require_once __DIR__ . '/../lib/enforce.php';
require_once __DIR__ . '/../lib/apprequests.php';
require_once __DIR__ . '/../lib/history.php';
require_once __DIR__ . '/../lib/ytlearn.php';
require_once __DIR__ . '/../lib/selftest.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function out(array $payload, int $code = 200): never {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * חוזה הסירוב, אחיד לכל הפעולות (סקירה, 2026-10-20 — שלב 7):
 *
 *   ‏ok=false תמיד נושא allowed=false ו-reason, חוץ משגיאה זמנית (שרת, עומס) — שם retry=true ואין
 *   allowed: "לא הצלחנו לשאול", לא "נשאלנו ונענינו לא". לפני זה סירוב (חשבון מושעה, 403) הגיע בלי
 *   allowed, וגרסאות שקראו optBoolean("allowed", true) המשיכו לגלוש בחשבון מושעה.
 */
function bad(string $message, string $code = 'error', int $http = 400): never {
    $out = ['ok' => false, 'error' => $message, 'code' => $code];
    if ($http >= 500 || $http === 429) $out['retry'] = true;
    else $out += ['allowed' => false, 'reason' => $message];
    out($out, $http);
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

/** המשתמש המחובר, או 401. חשבון מושעה חוסם את כל הילדים שבו — 403 עם הסיבה. */
function requireUser(): array {
    $u = userByToken(bearerToken());
    if (!$u) bad('נדרשת כניסה מחדש', 'unauthorized', 401);
    if ($block = accountBlock($u)) bad($block[1], $block[0], 403);
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
    $dev   = (int) ($user['device_row_id'] ?? 0);
    if ($block = upgradeBlock($dev)) $state = decision(false, 'app_update', $block);

    // שחרור (SPEC סעיף 5): המינוי של ההורה הסתיים — המכשיר פותח הכול, אבל רק מול אסימון
    // חתום (סעיף 13). "אין חיבור" אינו "פג תוקף": בלי האסימון החתום הזה הוא ממשיך לאכוף.
    $acct    = (int) ($user['account_id'] ?? 0);
    $release = ($state['code'] ?? '') === 'released' ? releaseToken($acct, $uid) : null;

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
            'allow_pip'         => (bool) $policy['allow_pip'],
            'allow_background'  => (bool) $policy['allow_background'],
            // מצב לא מקוון מתוכנן (שלב 12 ו) — נאכף במכשיר (OfflineMath).
            'offline_grace_h'   => (int) ($policy['offline_grace_h'] ?? 24),
            'offline_content'   => (string) ($policy['offline_content'] ?? 'all'),
            // חיפוש גוגל (lib/policy.php, googleSearchAllowed) — אותה הכרעה גם במכשיר.
            'search_engine'     => (string) ($policy['search_engine'] ?? 'migdalor'),
        ],
        'rules' => array_map(fn($r) => [
            'label'     => $r['label'],
            'pattern'   => $r['pattern'],
            'scope'     => $r['scope'],
            'action'    => $r['action'],
            'show_tile' => (bool) $r['show_tile'],
            'expires_at' => (string) ($r['expires_at'] ?? ''),
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
        // כתובת השרת הקבועה (data/config.json). האפליקציה עוברת אליה רק
        // אחרי שבדקה ב-ping שהיא באמת עונה — כתובת שגויה לא תנתק מכשירים.
        'server_url'     => serverUrl(),
        // זהות החשבון, ואסימון השחרור החתום (רק כשמשוחרר). המכשיר מאמת שהחתימה
        // תקפה וש-acct תואם — ורק אז מסיר מגבלות ומציג "המנוי של ההורים הסתיים".
        'account_id'     => $acct,
        // מזהה הילד — לקוד ה-QR של "העברה בלי אינטרנט" (offlineToken), שמיועד לילד אחד בלבד.
        'child_id'       => $uid,
        'release'        => $release,
        // המפתח הציבורי לאימות חתימות. המכשיר מצמיד אותו בפעם הראשונה (TOFU) ומאמת מולו.
        'sign_pubkey'    => signingPublicKey(),
        // טביעת האצבע של המדיניות — הפעימה משווה אליה (policyRev).
        'rev'            => policyRev($uid),
        // רצף התשובות (מילישניות של השרת): המכשיר דוחה תשובה ישנה מזו שכבר החיל — תשובה איטית
        // שהגיעה אחרי חדשה לא תדרוס מדיניות חדשה (PolicySync.kt).
        'issued_at'      => (int) floor(microtime(true) * 1000),
        'categories'     => $set['categories'],
        'domain_map'     => $slim,
        'platforms'      => array_map(fn($p) => [
            'mode'         => $p['mode'],
            'allow_search' => (bool) $p['allow_search'],
            'allow_shorts' => (bool) $p['allow_shorts'],
        ], $set['platforms']),
        'platform_items' => $set['platform_items'],
        // יש גרסה חדשה — עדכון חובה (גרסה עם עדכון חובה ננעלת עד ההתקנה; ישנות יותר מציעות לילד).
        'update' => $dev ? deviceUpdateOffer($dev) : null,
        // ניהול אפליקציות המכשיר (שלב 11) — manage=false לדפדפן בלבד.
        // + היתרים זמניים והחלטות על בקשות פתיחה (lib/apprequests.php) — המכשיר מחיל ומאשר ביצוע.
        'apps' => ($apps = appsPayload($user))['manage']
            ? $apps + ['temp' => appTempGrants($uid), 'decisions' => appDecisions($uid)] : $apps,
        // הגדרות המכשיר שההורה קבע — {מפתח: חסום?}. נאכף רק ב"בעל מכשיר" (Enforcer.kt).
        'device' => deviceSettings($uid),
        // בדיקת הגנה שההורה ביקש ועוד לא נענתה (lib/selftest.php) — המכשיר בודק את עצמו ושולח do=selftest.
        'selftest' => $dev ? selftestFor($user) : null,
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

    /* ── בדיקת חיים ────────────────────────────────────────────────
     * לפני מעבר לכתובת שרת חדשה האפליקציה שואלת אותה: "אתה מגדלור?"
     */
    if ($do === 'ping') {
        out(['ok' => true, 'app' => 'migdalor']);
    }

    /* ── המפתח הציבורי להזמנת הורים לפלטפורמה (app.json → "provision", platform/docs/sso.md סעיף 6) ── */
    if ($do === 'platform_key') {
        out(provisionKeyPayload());
    }

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
        // עד הצימוד (שלב 4) — נרשם לחשבון הראשון, וממתין שם לאישור.
        $accountId = (int) (one('SELECT MIN(id) m FROM accounts')['m'] ?? 0);
        q('INSERT INTO users (username, email, display_name, password_hash, status, created_at, account_id)
           VALUES (?, ?, ?, ?, ?, ?, ?)',
          [$username, mb_substr(field('email'), 0, 200), mb_substr(field('name'), 0, 80),
           hashPassword($password), 'pending', nowIso(), $accountId]);
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

        if ($block = accountBlock($u)) {
            audit((int) $u['id'], 'login_denied', false, '', $block[0]);
            bad($block[1], $block[0], 403);
        }
        if (accountDeviceQuotaFull((int) $u['account_id'], field('device_id'))) {
            audit((int) $u['id'], 'login_denied', false, '', 'account_devices');
            bad('הגעתם למספר המכשירים המרבי בחשבון המשפחה. נתקו מכשיר ישן בפאנל ונסו שוב.',
                'account_devices', 403);
        }

        $max   = (int) policyFor((int) $u['id'])['max_devices'];
        $token = registerDevice((int) $u['id'], field('device_name'), field('device_id'), $max);
        audit((int) $u['id'], 'login', true, '', 'ok', field('device_name'));

        out(['ok' => true, 'token' => $token] + policyPayload($u));
    }

    /* ── צימוד בקוד (שלב 4) ──────────────────────────────────────
     * ההורה מציג QR או קוד; המכשיר שולח אותו ומקבל אסימון — בלי סיסמה.
     * הגבלת ניסיונות לפי IP: קוד בן 8 תווים, תקף 10 דקות, ו-8 ניסיונות
     * ברבע שעה — ניחוש אינו אפשרי מעשית.
     */
    if ($do === 'pair') {
        $ipKey = 'pair:' . ($_SERVER['REMOTE_ADDR'] ?? '');
        if (tooManyFailures($ipKey)) bad('יותר מדי ניסיונות. נסו שוב בעוד רבע שעה.', 'rate_limited', 429);
        [$u, $token, $err] = pairRedeem(field('code'), field('device_name'), field('device_id'));
        if ($err) {
            audit(0, 'login_fail', false, '', 'pair', $ipKey);
            bad($err, 'pair_failed', 400);
        }
        audit((int) $u['id'], 'paired', true, '', 'ok', field('device_name'));
        out(['ok' => true, 'token' => $token] + policyPayload($u));
    }

    /* ── רענון מדיניות ─────────────────────────────────────────── */
    if ($do === 'policy') {
        $user = requireUser();
        $payload = policyPayload($user);
        // "התקבל במכשיר" — המכשיר משך את המדיניות הזו. ההחלה עצמה — בדוח האכיפה (enforce_report).
        q('UPDATE devices SET policy_rev = ?, policy_rev_at = ? WHERE id = ?',
          [(string) $payload['rev'], nowIso(), (int) $user['device_row_id']]);
        receiptReceived((int) $user['id'], (int) $user['device_row_id']);   // קבלה: התקבל במכשיר (lib/history.php)
        out($payload);
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
        // מה נשמר — לפי רמת הדיווח שההורה בחר (lib/activity.php). חסימה — תמיד.
        if ($main) activityNav($uid, $url, $d, $tz);

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

        if ($block = upgradeBlock((int) $user['device_row_id'])) $d = decision(false, 'app_update', $block);
        out(['ok' => true, 'allowed' => $d['allow'], 'code' => $d['code'], 'reason' => $d['reason']]);
    }

    /* ── רישום אבחון מהמכשיר ────────────────────────────────────
     * נשמרים 30 האחרונים בלבד: זהו מאגר אבחון, לא יומן קבוע.
     */
    if ($do === 'trace') {
        $user = requireUser();
        $body = mb_substr((string) (body()['body'] ?? ''), 0, 60000);
        if (trim($body) === '') bad('רישום ריק', 'empty');

        q('INSERT INTO traces (user_id, at, label, device, sdk, body) VALUES (?,?,?,?,?,?)',
          [(int) $user['id'], nowIso(), mb_substr(field('label'), 0, 40),
           mb_substr(field('device'), 0, 80), (int) (body()['sdk'] ?? 0), $body]);

        q('DELETE FROM traces WHERE id NOT IN (SELECT id FROM traces ORDER BY id DESC LIMIT 30)');
        out(['ok' => true]);
    }

    /* ── סינון הצעות ביוטיוב (שלב 5) ─────────────────────────────
     * הדף שולח את מזהי הסרטונים שמוצגים בו; השרת עונה מי מהם מערוץ
     * מאושר. מה שלא אושר — נעלם מהדף, ולא רק נחסם כשלוחצים עליו.
     */
    if ($do === 'yt_allowed') {
        $user = requireUser();
        $ids = body()['ids'] ?? [];
        out(['ok' => true, 'verdicts' => (object) youTubeVideoVerdicts(ruleSetFor((int) $user['id']), is_array($ids) ? $ids : [])]);
    }

    /* ── בקש גישה (שלב 6) ─────────────────────────────────────────
     * הילד נחסם ומבקש; ההורים בחשבון מקבלים צלצול — אחרי שהתשובה כבר
     * יצאה לילד, כדי ששירות ההתראות לא יעכב אותו.
     */
    if ($do === 'request') {
        $user = requireUser();
        [$id, $msg, $err] = requestCreate($user, field('url'), field('note'));
        if ($err) bad($err, 'request_refused');
        pushAfterResponse();
        out(['ok' => true, 'id' => $id, 'message' => $msg]);
    }

    /* ── בקשת פתיחה לאפליקציה חסומה, ואישור הביצוע במכשיר (lib/apprequests.php) ── */
    if ($do === 'app_request') {
        $user = requireUser();
        [$id, $msg, $err] = appRequestCreate($user, field('package'), field('note'));
        if ($err) bad($err, 'request_refused');
        pushAfterResponse();
        out(['ok' => true, 'id' => $id, 'message' => $msg]);
    }
    if ($do === 'app_request_applied') {
        $user = requireUser();
        $b = body();
        if ($err = appRequestApplied($user, (int) ($b['id'] ?? 0), !empty($b['ok']), (string) ($b['error'] ?? ''), (int) ($b['ends_ms'] ?? 0))) {
            bad($err, 'request_inactive', 409);
        }
        out(['ok' => true]);
    }

    /* ── האם ההורה ענה ── המכשיר שואל כל עוד יש לו בקשה ממתינה. */
    if ($do === 'requests') {
        $user = requireUser();
        out(['ok' => true, 'requests' => requestsOfChild((int) $user['id']), 'rev' => policyRev((int) $user['id'])]);
    }

    /* ── דיווח האפליקציות המותקנות (שלב 11) ───────────────────────
     * המכשיר שולח אילו אפליקציות (עם מסך פתיחה) מותקנות, כדי שההורה יכריע עליהן.
     */
    if ($do === 'apps') {
        $user = requireUser();
        $list = body()['apps'] ?? [];
        out(['ok' => true, 'saved' => accountAppsAllowed((int) $user['account_id']) && is_array($list)
             ? appsReport((int) $user['id'], $list, (int) ($user['device_row_id'] ?? 0)) : 0]);
    }

    /* ── חיפוש (שלב 8) ───────────────────────────────────────────
     * אינדקס משלנו או Brave — לפי ברירת המחדל של הילד; כל תוצאה מסוננת אצלו.
     * אחרי התשובה — צעד סריקה קטן, כך שהאינדקס מתעדכן מהשימוש עצמו.
     */
    if ($do === 'search') {
        $user = requireUser();
        $r = searchFor($user, field('q'));
        searchTickAfterResponse(4);
        out(['ok' => true] + $r);
    }

    /* ── פעימה ─────────────────────────────────────────────────────
     * צוברת זמן צפייה ומחזירה את המצב. זה גם ערוץ הניתוק: מנהל
     * שמשעה חשבון או מוחק מכשיר — הפעימה הבאה מחזירה סירוב.
     */
    /* ── דוח אכיפה: מה הוחל בפועל במכשיר ומה נכשל (lib/enforce.php) ── */
    if ($do === 'enforce_report') {
        $user = requireUser();
        enforceReportSave((int) $user['device_row_id'], (array) body());
        receiptApplied((int) $user['device_row_id'], (array) body());        // קבלה: הוחל / נכשל (lib/history.php)
        out(['ok' => true]);
    }

    /* ── תוצאות בדיקת ההגנה (lib/selftest.php) — הפסיקה בשרת, מהנתונים הגולמיים ── */
    if ($do === 'selftest') {
        $user = requireUser();
        if ($err = selftestSave((int) $user['device_row_id'], (array) body())) bad($err, 'selftest_inactive', 409);
        out(['ok' => true]);
    }

    /* ── מצב למידה ביוטיוב: מה הסקריפט בדף אימת (lib/ytlearn.php) ── */
    if ($do === 'yt_learn_report') {
        $user = requireUser();
        ytLearnReportSave((int) $user['device_row_id'], (array) body());
        out(['ok' => true]);
    }

    /* ── זמן מסך לאפליקציות: הזמן שנצבר היום במכשיר (lib/apptime.php) ── */
    if ($do === 'app_usage') {
        $user = requireUser();
        out(['ok' => true, 'saved' => accountAppsAllowed((int) $user['account_id']) ? appUsageSave((int) $user['id'], (array) body()) : 0]);
    }

    /* ── מצב העדכון במכשיר (שלב 9) — ההורה רואה עדכון שנכשל או נתקע ── */
    if ($do === 'update_state') {
        $user = requireUser();
        if (!updateStateSave((int) $user['device_row_id'], body())) bad('מצב לא מוכר', 'bad_state');
        out(['ok' => true]);
    }

    if ($do === 'heartbeat') {
        $user = requireUser();
        $b = body()['batches'] ?? null;
        $out = heartbeatPayload($user, (int) (body()['seconds'] ?? 0), field('url'), (int) (body()['session_sec'] ?? 0),
                                is_array($b) ? $b : null);
        if (mt_rand(1, 500) === 1) activityCleanup();
        if (mt_rand(1, 40) === 1) searchTickAfterResponse(4);
        out($out);
    }

    bad('פעולה לא מוכרת', 'unknown_action', 404);

} catch (Throwable $e) {
    error_log('guarded-browser api: ' . $e->getMessage());
    bad('שגיאת שרת', 'server', 500);
}
