<?php
/**
 * התראות (spec-stage-a פרק 8).
 *
 * התראה = נכס + מסגרת + כיוון (לונג / שורט / שניהם) + תנאי:
 *   method — "שיטה X עברה לכיוון הזה, בודאות ≥ הסף שלי" (ודאות מהבדיקה לאחור, הסף מהפרופיל)
 *   blend  — אותו דבר לשקלול של כמה שיטות
 *   price  — "המחיר חצה רמה" (לונג — חצה מעלה, שורט — מטה, שניהם — כל חצייה). הרמה — מחרוזת עשרונית, וההשוואה
 *            בחשבון העשרוני: זו רמה שהמשתמש קבע, ולכן כמו כסף.
 * שיטה שלא מותרת בהתראות (meta alerts: false — ובכלל זה שיטת הדוגמה) — לא נכנסת.
 *
 * הבדיקה — **רק ב-cron** (bin/alerts-cron.php, כל 15 דקות, CLI בלבד). אין כתובת רשת שמפעילה אותה, ואין בדיקה מהדפדפן.
 * בכל ריצה: נעילה (ריצה לא מתחילה כשקודמת עוד רצה) → התראות שפג תוקפן — expired → קיבוץ לפי (נכס, מסגרת, היקף מפתח)
 * → פנייה אחת לספק לכל קבוצה → כל ההתראות של הקבוצה נבדקות מהמאגר. תקציב לספק בריצה (התקרה לדקה שלו), השאר נדחה;
 * ריצה שעוברת 4 דקות נעצרת. **בלי כפילות:** נשלח רק במעבר מ"לא מתקיים" ל"מתקיים"; ההתראה הראשונה אחרי יצירה רק
 * קובעת את נקודת ההתחלה (כדי ש"עברה לכיוון" ו"חצה" יהיו מעבר אמיתי, לא מצב שכבר היה).
 *
 * מסירה (8.3): תמיד לתיבה באפליקציה; פוש — אם בחר בפרופיל, ולא בשעות השקטות. מעבר למקסימום ליום (בפרופיל) — לא
 * נשלח (נספר). בלי מייל (✅8). כל הודעה עם ההסתייגות בקצרה וקישור לכרטיס הניתוח.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/analysis.php';
require_once __DIR__ . '/push.php';

const TS_ALERTS_PER_USER = 20;        // ✅7
const TS_ALERTS_SYSTEM = 500;         // ✅7
const TS_ALERT_DAYS_DEFAULT = 30;     // 8.1
const TS_CRON_MAX_SECONDS = 240;      // 8.2 — 4 דקות
const TS_ALERT_DISCLAIMER = 'ניתוח אוטומטי לפי כללי שיטות — אינו ייעוץ השקעות.';
const TS_ALERT_SIDES = ['long' => 'לונג', 'short' => 'שורט', 'both' => 'לונג או שורט'];

// ── ניהול ההתראות של המשתמש ─────────────────────────────

function ts_alert_out(array $r): array
{
    return ['id' => (int)$r['id'], 'asset' => $r['asset'], 'tf' => $r['tf'], 'side' => $r['side'], 'kind' => $r['kind'],
        'method_id' => $r['method_id'], 'methods' => json_decode($r['methods'], true) ?: [], 'level' => $r['level'],
        'status' => $r['status'], 'last_state' => $r['last_state'],
        'last_checked_at' => $r['last_checked_at'] !== null ? (int)$r['last_checked_at'] : null,
        'last_fired_at' => $r['last_fired_at'] !== null ? (int)$r['last_fired_at'] : null,
        'expires_at' => (int)$r['expires_at'], 'created_at' => (int)$r['created_at']];
}

function ts_alerts_list(int $uid): array
{
    ts_alerts_expire(time());
    $st = ts_db()->prepare("SELECT * FROM alerts WHERE user_id = ? ORDER BY status = 'expired', id DESC");
    $st->execute([$uid]);
    return array_map('ts_alert_out', $st->fetchAll());
}

/** השיטות שמותרות בהתראות למשתמש (מופעלות, alerts: true, לא דוגמה) */
function ts_alert_methods(int $uid): array
{
    return array_values(array_filter(ts_methods_visible($uid), fn($m) => $m['alerts']));
}

function ts_alert_save(int $uid, array $in): array
{
    [$provider] = ts_asset_split($in['asset'] ?? null);
    $asset = $in['asset'];
    if (!ts_access($uid, $provider)) throw new TsInvalid('אין לכם חיבור לספק של הנכס הזה');
    $tf = $in['tf'] ?? null;
    if (!is_string($tf) || !isset(ts_provider($provider)->meta()['tfs'][$tf])) throw new TsInvalid('מסגרת זמן לא תקינה לספק הזה');
    $side = ts_enum($in['side'] ?? '', TS_ALERT_SIDES, 'כיוון');
    $kind = ts_enum($in['kind'] ?? '', ['method' => 1, 'blend' => 1, 'price' => 1], 'סוג התראה');
    $allowed = array_column(ts_alert_methods($uid), null, 'id');
    $methodId = null; $methods = []; $level = null;
    if ($kind === 'method') {
        $methodId = $in['method_id'] ?? null;
        if (!is_string($methodId) || !isset($allowed[$methodId])) throw new TsInvalid('השיטה לא זמינה להתראות');
        if (!in_array($tf, $allowed[$methodId]['timeframes'], true)) throw new TsInvalid('השיטה לא מוגדרת למסגרת הזמן הזו');
    } elseif ($kind === 'blend') {
        $methods = $in['methods'] ?? null;
        if (!is_array($methods) || !array_is_list($methods) || count($methods) < 2 || count($methods) > 10) throw new TsInvalid('שקלול — 2 עד 10 שיטות');
        foreach ($methods as $m) if (!is_string($m) || !isset($allowed[$m])) throw new TsInvalid('שיטה בשקלול לא זמינה להתראות');
        $methods = array_values(array_unique($methods));
    } else {
        $level = ts_num($in['level'] ?? null, 'רמת המחיר', false, false, 10);
    }
    $days = ts_int_in($in['days'] ?? TS_ALERT_DAYS_DEFAULT, 1, 90, 'תוקף בימים');

    $db = ts_db();
    ts_alerts_expire(time());
    $st = $db->prepare("SELECT COUNT(*) FROM alerts WHERE user_id = ? AND status != 'expired'");
    $st->execute([$uid]);
    if ((int)$st->fetchColumn() >= TS_ALERTS_PER_USER) throw new TsInvalid('עד ' . TS_ALERTS_PER_USER . ' התראות למשתמש. מחקו התראה כדי להוסיף.');
    if ((int)$db->query("SELECT COUNT(*) FROM alerts WHERE status != 'expired'")->fetchColumn() >= TS_ALERTS_SYSTEM) {
        throw new TsInvalid('המערכת הגיעה לתקרה של ' . TS_ALERTS_SYSTEM . ' התראות. נסו שוב מאוחר יותר.');
    }
    $now = time();
    $db->prepare('INSERT INTO alerts (user_id, asset, tf, side, kind, method_id, methods, level, status, expires_at, created_at)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'active\', ?, ?)')
        ->execute([$uid, $asset, $tf, $side, $kind, $methodId, json_encode($methods), $level, $now + $days * 86400, $now]);
    return ts_alert_get($uid, (int)$db->lastInsertId());
}

function ts_alert_get(int $uid, int $id): array
{
    $st = ts_db()->prepare('SELECT * FROM alerts WHERE id = ? AND user_id = ?');
    $st->execute([$id, $uid]);
    return ts_alert_out($st->fetch() ?: throw new TsNotFound('ההתראה לא נמצאה'));
}

/** השהיה / הפעלה מחדש. הפעלה מחדש מאפסת את נקודת ההתחלה (לא שולחים על מה שקרה בהשהיה) */
function ts_alert_status(int $uid, mixed $id, mixed $status): array
{
    $id = is_int($id) ? $id : 0;
    $cur = ts_alert_get($uid, $id);
    $status = ts_enum($status, ['active' => 1, 'paused' => 1], 'מצב');
    if ($cur['status'] === 'expired') throw new TsInvalid('ההתראה פגה. צרו חדשה.');
    ts_db()->prepare('UPDATE alerts SET status = ?, last_state = CASE WHEN ? = \'active\' THEN NULL ELSE last_state END WHERE id = ? AND user_id = ?')
        ->execute([$status, $status, $id, $uid]);
    return ts_alert_get($uid, $id);
}

function ts_alert_delete(int $uid, mixed $id): void
{
    $st = ts_db()->prepare('DELETE FROM alerts WHERE id = ? AND user_id = ?');
    $st->execute([is_int($id) ? $id : 0, $uid]);
    if (!$st->rowCount()) throw new TsNotFound('ההתראה לא נמצאה');
}

function ts_alerts_expire(int $now): int
{
    $st = ts_db()->prepare("UPDATE alerts SET status = 'expired' WHERE status != 'expired' AND expires_at < ?");
    $st->execute([$now]);
    return $st->rowCount();
}

// ── התיבה ────────────────────────────────────────────────

function ts_event_out(array $r): array
{
    return ['id' => (int)$r['id'], 'alert_id' => $r['alert_id'] !== null ? (int)$r['alert_id'] : null, 'fired_at' => (int)$r['fired_at'],
        'payload' => json_decode($r['payload'], true) ?: [], 'delivered' => json_decode($r['delivered'], true) ?: (object)[],
        'read' => $r['read_at'] !== null];
}

function ts_inbox(int $uid, int $limit = 100): array
{
    $st = ts_db()->prepare('SELECT * FROM alert_events WHERE user_id = ? ORDER BY id DESC LIMIT ?');
    $st->execute([$uid, $limit]);
    return array_map('ts_event_out', $st->fetchAll());
}

function ts_inbox_unread(int $uid): int
{
    $st = ts_db()->prepare('SELECT COUNT(*) FROM alert_events WHERE user_id = ? AND read_at IS NULL');
    $st->execute([$uid]);
    return (int)$st->fetchColumn();
}

/** סימון כנקרא: רשימת מזהים, או הכול */
function ts_inbox_read(int $uid, mixed $ids): void
{
    if ($ids === 'all') {
        ts_db()->prepare('UPDATE alert_events SET read_at = ? WHERE user_id = ? AND read_at IS NULL')->execute([time(), $uid]);
        return;
    }
    if (!is_array($ids) || count($ids) > 200) throw new TsInvalid('רשימה לא תקינה');
    $st = ts_db()->prepare('UPDATE alert_events SET read_at = ? WHERE id = ? AND user_id = ? AND read_at IS NULL');
    foreach ($ids as $i) if (is_int($i)) $st->execute([time(), $i, $uid]);
}

// ── הבדיקה (cron בלבד) ───────────────────────────────────

/** האם התנאי מתקיים עכשיו. מחזיר [state, details] — state '0' כשלא מתקיים, אחרת הכיוון ('long'/'short'/'above'/'below') */
function ts_alert_eval(array $a, int $uid, int $now): array
{
    if ($a['kind'] === 'price') {
        $r = ts_market_candles($uid, $a['asset'], $a['tf'], $now - 3 * TS_TF_SECONDS[$a['tf']], $now, $now);
        $last = end($r['candles']) ?: null;
        if (!$last) return [null, ['calls' => $r['provider_calls']]];
        $state = dec_cmp((string)$last['c'], $a['level']) >= 0 ? 'above' : 'below';
        return [$state, ['price' => $last['c'], 'level' => $a['level'], 'calls' => $r['provider_calls']]];
    }
    $prof = ts_profile($uid);
    $ids = $a['kind'] === 'method' ? $a['method_id'] : implode(',', json_decode($a['methods'], true) ?: []);
    $an = ts_analysis($uid, $a['asset'], $a['tf'], $ids, $now);
    if ($a['kind'] === 'method') {
        $c = $an['cards'][0];
        $cert = $c['certainty'];
        $dir = $c['bias'];
        $name = $c['method']['name'];
    } else {
        $b = $an['blend'];
        $w = array_sum(array_column($b['included'], 'weight'));
        $byId = array_column(array_map(fn($c) => ['id' => $c['method']['id'], 'cert' => $c['certainty']], $an['cards']), 'cert', 'id');
        $cert = $w > 0 ? (int)round(array_sum(array_map(fn($x) => $x['weight'] * $byId[$x['id']], $b['included'])) / $w) : null;
        $dir = $b['lean'] ?? 'none';
        $name = 'שקלול השיטות';
    }
    $ok = $cert !== null && $cert >= $prof['min_certainty'] && in_array($dir, $a['side'] === 'both' ? ['long', 'short'] : [$a['side']], true);
    return [$ok ? $dir : '0', ['certainty' => $cert, 'direction' => $dir, 'name' => $name, 'calls' => $an['provider_calls'],
        'min_certainty' => $prof['min_certainty']]];
}

/** האם המעבר מ-$prev ל-$state הוא "התקיים" */
function ts_alert_fires(array $a, ?string $prev, ?string $state): bool
{
    if ($prev === null || $state === null || $prev === $state) return false;   // הפעם הראשונה — נקודת התחלה בלבד
    if ($a['kind'] !== 'price') return $state !== '0';
    return $a['side'] === 'both' || ($a['side'] === 'long' && $state === 'above') || ($a['side'] === 'short' && $state === 'below');
}

function ts_in_quiet(?array $q, string $tz, int $now): bool
{
    if (!$q) return false;
    try { $hm = (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone($tz))->format('H:i'); } catch (Throwable) { return false; }
    return $q['from'] <= $q['to'] ? ($hm >= $q['from'] && $hm < $q['to']) : ($hm >= $q['from'] || $hm < $q['to']);
}

/** הודעה לתיבה (ופוש). בלי "קנה/מכור" — "לפי שיטה X", עם ההסתייגות בקצרה וקישור לכרטיס (6.6) */
function ts_alert_deliver(array $a, string $state, array $d, int $now): array
{
    $uid = (int)$a['user_id'];
    $db = ts_db();
    $u = $db->prepare('SELECT timezone FROM users WHERE id = ?');
    $u->execute([$uid]);
    $tz = (string)$u->fetchColumn() ?: 'UTC';
    $prof = ts_profile($uid);
    try { $dayStart = (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone($tz))->setTime(0, 0)->getTimestamp(); } catch (Throwable) { $dayStart = $now - $now % 86400; }
    $cnt = $db->prepare('SELECT COUNT(*) FROM alert_events WHERE user_id = ? AND fired_at >= ?');
    $cnt->execute([$uid, $dayStart]);
    if ((int)$cnt->fetchColumn() >= $prof['max_alerts_per_day']) return ['capped' => true];

    $symbol = explode(':', $a['asset'], 2)[1];
    $tfName = TS_TIMEFRAMES[$a['tf']];
    if ($a['kind'] === 'price') {
        $title = "$symbol חצה את {$a['level']}";
        $body = 'המחיר חצה ' . ($state === 'above' ? 'מעלה' : 'מטה') . " את הרמה {$a['level']} (נר של $tfName). מחיר אחרון: {$d['price']}.";
    } else {
        $side = ['long' => 'לונג', 'short' => 'שורט'][$state];
        $title = "$symbol · {$d['name']}: תנאי ה$side מתקיימים";
        $body = "לפי {$d['name']}, תנאי ה$side מתקיימים על $symbol (נר של $tfName). ודאות {$d['certainty']}% מהבדיקה לאחור — של השיטה על הנכס בעבר, לא של העסקה הבאה.";
    }
    $payload = ['title' => $title, 'body' => $body . ' ' . TS_ALERT_DISCLAIMER, 'asset' => $a['asset'], 'tf' => $a['tf'],
        'kind' => $a['kind'], 'state' => $state, 'link' => '#/analysis/' . rawurlencode($a['asset'])] + array_intersect_key($d, ['certainty' => 1, 'price' => 1, 'level' => 1]);
    $delivered = ['inbox' => true];
    if (in_array('push', $prof['alert_channels'], true)) {
        $delivered['push'] = ts_in_quiet($prof['quiet_hours'], $tz, $now) ? 'quiet' : ts_push_user($uid, $now);
    }
    $db->prepare('INSERT INTO alert_events (alert_id, user_id, fired_at, payload, delivered) VALUES (?, ?, ?, ?, ?)')
        ->execute([$a['id'], $uid, $now, json_encode($payload, JSON_UNESCAPED_UNICODE), json_encode($delivered)]);
    return $delivered;
}

function ts_cron_lock_path(): string
{
    return defined('TS_DB_PATH') ? TS_DB_PATH . '.alerts.lock' : dirname(__DIR__) . '/data/alerts.lock';
}

/**
 * ריצה אחת של ה-cron. מחזיר סיכום. $now — לבדיקות. $budget — דריסה של התקציב לספק (לבדיקות).
 */
function ts_alerts_run(?int $now = null, ?array $budget = null): array
{
    $now ??= time();
    $t0 = microtime(true);
    $lock = fopen(ts_cron_lock_path(), 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return ['locked' => true];
    $sum = ['locked' => false, 'expired' => 0, 'groups' => 0, 'deferred' => 0, 'checked' => 0, 'fired' => 0, 'capped' => 0,
        'errors' => 0, 'calls' => [], 'stopped' => false, 'cleanup' => false];
    try {
        $sum['expired'] = ts_alerts_expire($now);
        $rows = ts_db()->query("SELECT * FROM alerts WHERE status = 'active' ORDER BY COALESCE(last_checked_at, 0), id")->fetchAll();
        // קיבוץ לפי (נכס, מסגרת, היקף מפתח): ספק משלי — היקף של המשתמש, ציבורי — משותף
        $groups = [];
        foreach ($rows as $a) {
            [$provider] = explode(':', $a['asset'], 2);
            $acc = ts_access((int)$a['user_id'], $provider);
            if (!$acc) continue;   // החיבור לספק נמחק — ההתראה מחכה
            $groups["$a[asset]|$a[tf]|$acc[scope]"][] = $a;
        }
        $used = [];
        foreach ($groups as $key => $list) {
            [$asset, $tf] = explode('|', $key);
            $provider = explode(':', $asset, 2)[0];
            $cap = $budget[$provider] ?? ts_provider_limits($provider)['per_min'];
            if (microtime(true) - $t0 > TS_CRON_MAX_SECONDS) { $sum['stopped'] = true; $sum['deferred'] += count($list); continue; }
            if (($used[$provider] ?? 0) >= $cap) { $sum['deferred'] += count($list); continue; }   // לריצה הבאה
            $sum['groups']++;
            $first = (int)$list[0]['user_id'];
            try {
                // הפנייה האחת לספק לקבוצה — מרעננת את המאגר; כל ההתראות אחריה נבדקות ממנו
                $r = ts_market_candles($first, $asset, $tf, $now - 3 * TS_TF_SECONDS[$tf], $now, $now);
                $used[$provider] = ($used[$provider] ?? 0) + $r['provider_calls'];
            } catch (Throwable $e) {
                $sum['errors']++;
                error_log('trading-strategies alerts group ' . $key . ': ' . $e->getMessage());
                continue;
            }
            foreach ($list as $a) {
                try {
                    [$state, $d] = ts_alert_eval($a, (int)$a['user_id'], $now);
                    $used[$provider] = ($used[$provider] ?? 0) + ($d['calls'] ?? 0);
                } catch (Throwable $e) {
                    $sum['errors']++;
                    error_log('trading-strategies alert ' . $a['id'] . ': ' . $e->getMessage());
                    continue;
                }
                $sum['checked']++;
                $fire = ts_alert_fires($a, $a['last_state'], $state);
                ts_db()->prepare('UPDATE alerts SET last_state = ?, last_checked_at = ? WHERE id = ?')->execute([$state ?? $a['last_state'], $now, $a['id']]);
                if (!$fire) continue;
                $del = ts_alert_deliver($a, $state, $d, $now);
                if (!empty($del['capped'])) { $sum['capped']++; continue; }
                ts_db()->prepare('UPDATE alerts SET last_fired_at = ? WHERE id = ?')->execute([$now, $a['id']]);
                $sum['fired']++;
            }
        }
        $sum['calls'] = $used;
        // ניקוי המאגר — פעם ביום, מכאן (✅4)
        $before = (int)ts_setting('cache_cleanup_at', '0');
        ts_cache_cleanup_daily($now);
        $sum['cleanup'] = (int)ts_setting('cache_cleanup_at', '0') !== $before;
        ts_setting_set('alerts_cron', json_encode(['at' => $now, 'seconds' => round(microtime(true) - $t0, 2)] + $sum));
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    return $sum;
}

/** למנהל: מצב ה-cron והתקרות */
function ts_alerts_admin_stats(): array
{
    $db = ts_db();
    return ['active' => (int)$db->query("SELECT COUNT(*) FROM alerts WHERE status = 'active'")->fetchColumn(),
        'total' => (int)$db->query("SELECT COUNT(*) FROM alerts WHERE status != 'expired'")->fetchColumn(),
        'system_cap' => TS_ALERTS_SYSTEM, 'per_user_cap' => TS_ALERTS_PER_USER,
        'last_run' => json_decode((string)ts_setting('alerts_cron', 'null'), true)];
}
