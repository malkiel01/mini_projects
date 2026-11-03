<?php
/**
 * בלמים לגשר החי — docs/spec.md §5.6, "Incident 2026-11-02 10:15".
 *
 * באחסון משותף, מאזינים רבים שכל אחד מחזיק תהליך PHP ומיד חוזר — האחסון חוסם את כל הדומיין
 * (429 לכולם, לכל האפליקציות). לכן שני בלמים, ושניהם רצים לפני ההזדהות ובלי לגעת במסד:
 *
 *  1. ⏸ עצירת המאזינים (config.bridge_paused) — בעל הלוח, בהגדרות → מערכת. כל channel-wait /
 *     channel-inbox מקבל 503 + Retry-After: 3600, והמאזין יוצא מעצמו תוך מחזור אחד.
 *  2. תקציב לכל אסימון ערוץ — channel-wait אחד לכל 10 שניות. מעבר לזה: 429 + Retry-After.
 *     נספר בקובץ קטן ב-data/throttle (לא במסד), כדי שמאזין שיצא משליטה לא יעמיס על שאר האתר.
 */
declare(strict_types=1);

if (!defined('THROTTLE_DIR')) define('THROTTLE_DIR', __DIR__ . '/../data/throttle');

const BRIDGE_GUARDED   = ['channel-wait', 'channel-inbox', 'permission-status', 'gate-check'];
const BRIDGE_PAUSE_RETRY = 3600;
const BRIDGE_WAIT_EVERY  = 10;   // שניות בין שתי בקשות channel-wait של אותו אסימון

/**
 * ההכרעה — בלי תופעות לוואי, כדי שאפשר לבדוק אותה.
 * מחזיר null (לעבור), או [status, retryAfter, code, message].
 */
function bridgeVerdict(string $action, array $config, ?int $budgetRetry): ?array {
    if (!in_array($action, BRIDGE_GUARDED, true)) return null;
    if (!empty($config['bridge_paused'])) {
        return [503, BRIDGE_PAUSE_RETRY, 'bridge_paused', 'המאזינים מושהים בידי בעל הלוח — המאזין יוצא; הפעלה מחדש רק אחרי שהעצירה תוסר'];
    }
    if (in_array($action, ['channel-wait', 'permission-status'], true) && $budgetRetry !== null && $budgetRetry > 0) {
        return [429, $budgetRetry, 'rate_limited', 'יותר מדי בקשות מהערוץ הזה — בקשה אחת לכל ' . BRIDGE_WAIT_EVERY . ' שניות'];
    }
    return null;
}

/**
 * תקציב לאסימון: מחזיר 0 אם מותר (ורושם את הבקשה), אחרת כמה שניות לחכות.
 * בקשה שנדחתה לא נרשמת — החלון לא מתארך בגלל מאזין שממשיך לדפוק.
 */
function bridgeBudget(string $token, ?int $now = null, int $every = BRIDGE_WAIT_EVERY): int {
    $now ??= time();
    if (!is_dir(THROTTLE_DIR)) @mkdir(THROTTLE_DIR, 0700, true);
    $file = THROTTLE_DIR . '/' . substr(hash('sha256', $token), 0, 24);
    $fh = @fopen($file, 'c+');
    if (!$fh) return 0;   // אין דיסק — לא חוסמים בגלל זה
    try {
        flock($fh, LOCK_EX);
        $last = (int) stream_get_contents($fh);
        if ($last > 0 && $now - $last < $every) return $every - ($now - $last);
        ftruncate($fh, 0); rewind($fh); fwrite($fh, (string) $now);
        return 0;
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

/** רץ ב-api.php לפני ההזדהות. בקשה שנבלמה — יוצאת כאן, בלי מסד. */
function bridgeGuard(string $action): void {
    if (!in_array($action, BRIDGE_GUARDED, true)) return;
    $config = config();
    $retry = null;
    if (empty($config['bridge_paused'])) {
        $token = (string) ($_SERVER['HTTP_X_WORKER_TOKEN'] ?? '');
        // מונה נפרד לכל סוג (§5.12.1): המתנה להרשאה לא גוזלת מה-channel-wait של המאזין
        if ($token !== '' && $action === 'channel-wait') $retry = bridgeBudget($token);
        if ($token !== '' && $action === 'permission-status') $retry = bridgeBudget("$token:perm", null, 8);
    }
    $v = bridgeVerdict($action, $config, $retry);
    if (!$v) return;
    [$status, $after, $code, $message] = $v;
    header('Retry-After: ' . $after);
    fail($message, $status, $code);
}

/** ⏸ / ▶ — בעל הלוח בלבד. */
function bridgeSetPaused(array $user, bool $paused): array {
    if (authorRole($user) !== 'owner') throw new AppError('עצירת המאזינים — בעל הלוח בלבד', 403);
    configSet(['bridge_paused' => $paused ? time() : null]);
    logEvent($user, $paused ? 'bridge.pause' : 'bridge.resume', 'bridge');
    return ['bridge_paused' => $paused ? (int) config()['bridge_paused'] : 0];
}
