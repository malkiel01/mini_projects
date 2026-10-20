<?php
/**
 * הפעימה של אפליקציית הילד (api/index.php, do=heartbeat) — מופרד כדי שאפשר לבדוק את החוזה.
 *
 *   ‏allowed/code/reason — מצב החשבון והזמן (השעיה, חלון שעות, מכסה, ישיבה). ‏false = לעצור הכול.
 *   ‏page — הכרעה על הדף שעל המסך עכשיו (סקירה, 2026-10-20: הפעימה בדקה רק זמן עם כתובת ריקה, ולכן
 *   היתר זמני שפג, או אתר שההורה הסיר, המשיכו להיות מוצגים עד ניווט הבא). ‏page.allowed=false — לחזור
 *   מהדף, לא לסגור הכול. חסר (אין כתובת) — אין הכרעה על דף.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/activity.php';
require_once __DIR__ . '/appversion.php';

/** מנה אחת לכל היותר: 6 שעות. מנה ארוכה מזו אינה שימוש אמיתי בין שתי פעימות, גם אחרי ניתוק ארוך. */
const USAGE_BATCH_MAX = 6 * 3600;

/**
 * מנות שימוש מהמכשיר — [{id, day, sec}]. כל מנה נספרת פעם אחת (usage_batches), ליום שבו נצברה —
 * אבל רק היום או אתמול לפי אזור הזמן של המדיניות (שימוש שנצבר לפני חצות ונשלח אחריה). מוחזרים
 * המזהים שהתקבלו — גם כאלה שכבר נספרו — כדי שהמכשיר יוכל למחוק אותם.
 */
function usageBatchesApply(int $uid, string $tz, array $batches): array {
    $today = todayIn($tz);
    $yesterday = (new DateTimeImmutable($today, zoneOf($tz)))->modify('-1 day')->format('Y-m-d');
    $acked = [];
    foreach (array_slice($batches, 0, 50) as $b) {
        $id  = is_array($b) ? (string) ($b['id'] ?? '') : '';
        if (!preg_match('/^[A-Za-z0-9-]{8,64}$/', $id)) continue;
        $sec = max(0, min(USAGE_BATCH_MAX, (int) ($b['sec'] ?? 0)));
        $day = (string) ($b['day'] ?? '');
        if ($day !== $today && $day !== $yesterday) $day = $today;
        $ins = db()->prepare('INSERT OR IGNORE INTO usage_batches (user_id, batch, seconds, day, at) VALUES (?, ?, ?, ?, ?)');
        $ins->execute([$uid, $id, $sec, $day, nowIso()]);
        if ($ins->rowCount() === 1) addUsage($uid, $tz, $sec, $day);
        $acked[] = $id;
    }
    if (mt_rand(1, 200) === 1) q('DELETE FROM usage_batches WHERE at < ?', [gmdate('Y-m-d\\TH:i:s\\Z', time() - 7 * 86400)]);
    return $acked;
}

function heartbeatPayload(array $user, int $seconds, string $url, int $sessionSec, ?array $batches = null): array {
    $uid    = (int) $user['id'];
    $policy = policyFor($uid);
    $tz     = (string) $policy['timezone'];

    // גרסה חדשה שולחת מנות עם מזהה (נספרות פעם אחת, גם אחרי ניתוק); ישנה — שניות, עם חסם עליון.
    $acked = [];
    if ($batches !== null) {
        $acked = usageBatchesApply($uid, $tz, $batches);
        $seconds = 0;
        foreach ($batches as $b) $seconds += is_array($b) ? max(0, (int) ($b['sec'] ?? 0)) : 0;
        $seconds = min(300, $seconds);   // ליומן הפעילות לפי אתר — רק מה שנראה כפעימה אחת
    } else {
        // חסם עליון לפעימה: דיווח מנופח לא יוכל לשרוף מכסה של יום.
        $seconds = max(0, min(300, $seconds));
        addUsage($uid, $tz, $seconds);
    }
    // הכתובת שעל המסך — כדי שההורה יראה כמה זמן בכל אתר (שלב 7).
    activityBeat($uid, $url, $seconds, $tz);

    $used = usedTodaySeconds($uid, $tz);
    $now  = nowIn($tz);
    $set  = ruleSetFor($uid);
    $d = evaluate($user, $policy, $set, $now, ['url' => '', 'used_today' => $used, 'session' => $sessionSec]);
    $device = (int) ($user['device_row_id'] ?? 0);
    if ($device && ($block = upgradeBlock($device))) $d = decision(false, 'app_update', $block);

    $out = ['ok' => true, 'allowed' => $d['allow'], 'code' => $d['code'], 'reason' => $d['reason'],
            'update' => $device ? deviceUpdateOffer($device) : null,
            'used_today_sec' => $used,
            'quota_left_sec' => (int) $policy['daily_quota_min'] > 0
                ? max(0, (int) $policy['daily_quota_min'] * 60 - $used) : -1,
            'server_url' => serverUrl(), 'rev' => policyRev($uid),
            // היום שאליו used_today_sec שייך, ושעון השרת — המכשיר מחשב מהם זמן אמין (TrustedClock).
            'day' => todayIn($tz), 'server_ms' => (int) floor(microtime(true) * 1000)];
    if ($batches !== null) $out['acked'] = $acked;
    if ($d['allow'] && $url !== '') {
        $p = evaluate($user, $policy, $set, $now,
                      ['url' => $url, 'main_frame' => true, 'used_today' => $used, 'session' => $sessionSec]);
        $out['page'] = ['allowed' => $p['allow'], 'code' => $p['code'], 'reason' => $p['reason']];
    }
    return $out;
}
