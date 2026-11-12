<?php
/**
 * סטודיו — קורן אחד, פעם בדקה, לכל השיחות יחד (רשת הביטחון; docs/studio.md §4).
 *
 * מה הוא עושה: מושך אירועים לשיחות שרצות ולא סונכרנו בדקה האחרונה — כלומר
 * כשהדף סגור וה-webhook לא הגיע. שיחה ש"רצה" זמן רב בלי אירוע חדש — נבדקת
 * מול מצב הסשן האמיתי.
 *
 * העומס קבוע: תהליך אחד בדקה, בלי קשר למספר השיחות. כשאין ריצה פעילה הוא
 * עושה שאילתה אחת למסד ויוצא — בלי אף פנייה החוצה.
 *
 *   php claude-tasks/bin/studio-cron.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../lib/studio/studio.php';

const STUDIO_CRON_STALE = 45;      // שניות בלי סנכרון לפני שהקורן מתערב
const STUDIO_CRON_QUIET = 900;     // שניות בלי אירוע חדש לפני בדיקה מול הסשן עצמו
const STUDIO_CRON_MAX   = 10;      // שיחות לכל ריצה
const STUDIO_CRON_BUDGET = 40;     // שניות לכל הריצה — שלא תיגרר אל הדקה הבאה

function studioCronRun(): array {
    studioMigrate();
    $now = nowTs();
    $st = db()->prepare("SELECT * FROM studio_convs WHERE session_id <> '' AND archived = 0
                          AND (status = 'running' OR more = 1) AND synced_at <= ? ORDER BY synced_at LIMIT " . STUDIO_CRON_MAX);
    $st->execute([$now - STUDIO_CRON_STALE]);
    $rows = $st->fetchAll();
    $out = ['checked' => 0, 'errors' => 0];
    $started = microtime(true);
    foreach ($rows as $conv) {
        if (microtime(true) - $started > STUDIO_CRON_BUDGET) break;
        $out['checked']++;
        try {
            $key = studioKey((int) $conv['user_id']);
            if ($key === '') continue;
            $quiet = (int) $conv['last_event_at'] > 0 && $now - (int) $conv['last_event_at'] > STUDIO_CRON_QUIET;
            if ($quiet && $conv['status'] === 'running') studioReconcile($conv, $key);
            else studioSyncIfDue($conv, $key, STUDIO_CRON_STALE);
        } catch (MaError $e) {
            $out['errors']++;
            // 429 / 402 / 5xx: לא מנסים שוב בלולאה. דוחים את השיחה הזו לסיבוב הבא.
            studioUpdateConv((int) $conv['id'], ['synced_at' => $now + max(0, min(600, $e->retryAfter))]);
            fwrite(STDERR, date('c') . " conv {$conv['id']}: {$e->getMessage()}\n");
        } catch (Throwable $e) {
            $out['errors']++;
            fwrite(STDERR, date('c') . " conv {$conv['id']}: {$e->getMessage()}\n");
        }
    }
    return $out;
}

if (!defined('STUDIO_CRON_NO_RUN')) {
    $r = studioCronRun();
    if ($r['checked'] > 0) echo date('c') . " checked={$r['checked']} errors={$r['errors']}\n";
}
