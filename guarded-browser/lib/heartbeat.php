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

function heartbeatPayload(array $user, int $seconds, string $url, int $sessionSec): array {
    $uid    = (int) $user['id'];
    $policy = policyFor($uid);
    $tz     = (string) $policy['timezone'];

    // חסם עליון לפעימה: דיווח מנופח לא יוכל לשרוף מכסה של יום.
    $seconds = max(0, min(300, $seconds));
    addUsage($uid, $tz, $seconds);
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
            'server_url' => serverUrl(), 'rev' => policyRev($uid)];
    if ($d['allow'] && $url !== '') {
        $p = evaluate($user, $policy, $set, $now,
                      ['url' => $url, 'main_frame' => true, 'used_today' => $used, 'session' => $sessionSec]);
        $out['page'] = ['allowed' => $p['allow'], 'code' => $p['code'], 'reason' => $p['reason']];
    }
    return $out;
}
