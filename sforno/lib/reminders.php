<?php
/**
 * התזכורות (אפיון 08) — התכנון והשליחה בשרת.
 *
 *   התכנון  — **אותו אלגוריתם כמו assets/js/reminders-core.js** (sf_rem_plan ↔ plan). שינוי באחד = אותו שינוי בשני;
 *             l-reminders משווה את שניהם. ההשתקה (שבת וחג) מחושבת כאן, בעת יצירת המופעים — לא במכשיר (§3).
 *   בעלים   — 'r:<reader id>' (ההגדרות מהסנכרון של 05: personal_items) או 'g:<push device id>' (אורח: guest_data מוצפן
 *             במכשיר ה-Push האנונימי — בלי מייל, בלי חשבון, בלי פרט אישי).
 *   מופעים  — 48 שעות קדימה (reminder_occurrences). כל שינוי ← sf_rem_schedule: **קודם מבטל** את העתידיים שכבר לא
 *             נכונים, ורק אחר כך יוצר חדשים; dedupe_key ייחודי, כך שתכנון חוזר לא מכפיל. בלי שם ובלי תוכן.
 *   שליחה   — רק מ-cron.php (01, משימה כל דקה): לוקחת מופע ב-UPDATE … WHERE status = 'pending' (אותו מופע לא יוצא פעמיים
 *             גם אם המתזמן רץ פעמיים), ומופע שעבר זמנו ביותר מ-15 דקות — missed, לא נשלח באיחור. "נשלח" = הספק קיבל;
 *             **לא** "המשתמש ראה" (§5).
 *   Push    — Web Push עם VAPID (RFC 8292) ותוכן מוצפן (RFC 8291, aes128gcm) — openssl בלבד, בלי ספרייה. המפתחות —
 *             data/vapid.json (נוצר בפעם הראשונה; לא בקוד, לא ביומן). endpoint — רק של ספקי Push ידועים (לא SSRF).
 *   לוג     — reminder_send_log: מזהה מופע, ערוץ, תוצאה, קוד HTTP. **בלי שם תזכורת ובלי שם תפילה** (קריטריון 10).
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/readers.php';
require_once __DIR__ . '/calendar.php';
require_once __DIR__ . '/daily.php';
require_once __DIR__ . '/jobs.php';

const SF_REM_TYPES = ['fixed', 'relative', 'before_end', 'minyan'];
const SF_REM_HORIZON = 48 * 3600 * 1000;
const SF_REM_GRACE = 15 * 60;            // מופע שאיחר יותר מזה — missed
const SF_REM_MUTE = ['test' => true, 'name' => 'שיטת בדיקה: מ-40 דקות לפני השקיעה בערב עד 72 דקות אחרי השקיעה במוצאי היום', 'before' => 40, 'after' => 72, 'depth' => 0.833];
const SF_REM_EXT_MAX = 180;
const SF_REM_MAX = 100;                  // תזכורות לבעלים (אורח וגם קורא) — מעבר לזה לא מתוכננות
const SF_PUSH_MAX = 10;                  // מכשירי Push פעילים לבעלים
const SF_REM_GUEST_BYTES = 64_000;
const SF_PUSH_COOKIE = 'sf_push';
const SF_PUSH_TTL = 400 * 86400;
/** ספקי ה-Push של הדפדפנים — שום כתובת אחרת לא מקבלת POST מהשרת */
const SF_PUSH_HOSTS = ['fcm.googleapis.com', 'android.googleapis.com', 'updates.push.services.mozilla.com', 'push.services.mozilla.com', 'web.push.apple.com'];
const SF_PUSH_HOST_SUFFIX = ['.push.apple.com', '.notify.windows.com', '.push.services.mozilla.com'];

// ── התכנון (המראה של reminders-core.js) ─────────────────────

function sf_rem_int(mixed $v, int $lo, int $hi): bool { return is_int($v) && $v >= $lo && $v <= $hi; }
function sf_rem_str(mixed $v, int $max): bool { return is_string($v) && trim($v) !== '' && mb_strlen($v) <= $max; }
function sf_rem_okid(mixed $v): bool { return is_string($v) && preg_match('/^[A-Za-z0-9._-]{1,100}\z/', $v) === 1; }
function sf_rem_date(mixed $v): bool { return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}\z/', $v) === 1; }

/** clean() */
function sf_rem_clean(mixed $r): ?array
{
    if (!is_array($r) || !sf_rem_okid($r['id'] ?? null) || !in_array($r['type'] ?? null, SF_REM_TYPES, true)) return null;
    $o = ['id' => $r['id'], 'name' => sf_rem_str($r['name'] ?? null, 60) ? trim($r['name']) : '', 'type' => $r['type'],
        'active' => ($r['active'] ?? true) !== false, 'created_at' => is_string($r['created_at'] ?? null) ? substr($r['created_at'], 0, 30) : ''];
    $p = $r['prayer'] ?? null;
    $sid = is_array($p) ? ($p['sid'] ?? null) : null;
    if (is_array($p) && sf_is_pid($p['edition'] ?? null) && ($sid === null || (is_string($sid) && preg_match('/^[A-Za-z0-9_-]{1,80}\z/', $sid)))) {
        $o['prayer'] = ['edition' => $p['edition'], 'sid' => $sid ?: null, 'name' => sf_rem_str($p['name'] ?? null, 80) ? trim($p['name']) : ''];
    } else $o['prayer'] = null;
    if ($r['type'] === 'fixed' || $r['type'] === 'minyan') {
        if (!is_string($r['time'] ?? null) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d\z/', $r['time'])) return null;
        $o['time'] = $r['time'];
    } else {
        if (!is_string($r['base'] ?? null) || !preg_match('/^[a-z][a-z0-9_]{0,31}\z/', $r['base'])) return null;
        $o['base'] = $r['base'];
        if ($r['type'] === 'relative') { if (!sf_rem_int($r['offset'] ?? null, -300, 300)) return null; $o['offset'] = $r['offset']; }
        else { if (!sf_rem_int($r['before'] ?? null, 0, 300)) return null; $o['before'] = $r['before']; }
    }
    if ($r['type'] === 'minyan' && $o['name'] === '') return null;
    $days = array_values(array_unique(array_filter(is_array($r['days'] ?? null) ? $r['days'] : [], fn($d) => sf_rem_int($d, 0, 6))));
    sort($days);
    if (!$days) return null;
    $o['days'] = $days;
    $o['from'] = sf_rem_date($r['from'] ?? null) ? $r['from'] : null;
    $o['to'] = sf_rem_date($r['to'] ?? null) ? $r['to'] : null;
    $c = $r['channels'] ?? null;
    $o['channels'] = is_array($c) ? ['push' => ($c['push'] ?? null) === true, 'inapp' => ($c['inapp'] ?? null) === true, 'email' => ($c['email'] ?? null) === true] : null;
    $o['devices'] = is_array($r['devices'] ?? null) ? array_slice(array_values(array_unique(array_filter($r['devices'], 'sf_is_pid'))), 0, 20) : null;
    return $o;
}

/** prefs() */
function sf_rem_prefs(mixed $p): array
{
    $p = is_array($p) ? $p : [];
    $c = is_array($p['channels'] ?? null) ? $p['channels'] : [];
    $ext = fn($v) => sf_rem_int($v, 0, SF_REM_EXT_MAX) ? $v : 0;
    return ['channels' => ['push' => ($c['push'] ?? null) !== false, 'inapp' => ($c['inapp'] ?? null) !== false, 'email' => ($c['email'] ?? null) === true],
        'extend' => ['before' => $ext($p['extend']['before'] ?? null), 'after' => $ext($p['extend']['after'] ?? null)],
        'tz' => is_string($p['tz'] ?? null) && strlen($p['tz']) <= 64 ? $p['tz'] : null];
}

function sf_rem_channels(array $rem, array $prefs): array
{
    return $rem['channels'] ?? $prefs['channels'];
}

function sf_rem_yomtov(array $h, bool $israel): bool
{
    $d = $h['d'];
    if ($h['m'] === 7) return in_array($d, [1, 2, 10, 15, 22], true) || (!$israel && ($d === 16 || $d === 23));
    if ($h['m'] === 1) return $d === 15 || $d === 21 || (!$israel && ($d === 16 || $d === 22));
    if ($h['m'] === 3) return $d === 6 || (!$israel && $d === 7);
    return false;
}

function sf_rem_kodesh(int $f, bool $israel): bool
{
    return sf_cal_weekday($f) === 6 || sf_rem_yomtov(sf_cal_heb_from_fixed($f), $israel);
}

function sf_rem_israel(?array $place, ?string $tz): bool
{
    if ($place && !empty($place['cc'])) return $place['cc'] === 'IL';
    return ($place ? $place['tz'] : $tz) === 'Asia/Jerusalem';
}

function sf_rem_sunset(?array $place, int $f): ?int
{
    if (!$place) return null;
    $g = sf_cal_greg_from_fixed($f);
    return sf_cal_sun_at((float)$place['lat'], (float)$place['lon'], (float)sf_cal_zoned_to_utc($place['tz'], $g['y'], $g['m'], $g['d'], 12, 0), SF_REM_MUTE['depth'], 'pm');
}

function sf_rem_local_fixed(string $tz, int $ms): int
{
    $p = sf_cal_local_parts($tz, $ms);
    return sf_cal_fixed_from_greg($p['y'], $p['m'], $p['d']);
}

/** muteWindows() */
function sf_rem_mute(array $ctx, int $fromMs, int $toMs): array
{
    $tz = $ctx['tz'];
    $f0 = sf_rem_local_fixed($tz, $fromMs) - 3;
    $f1 = sf_rem_local_fixed($tz, $toMs) + 3;
    $ext = $ctx['extend'] ?? ['before' => 0, 'after' => 0];
    $il = (bool)$ctx['israel'];
    $out = [];
    for ($f = $f0; $f <= $f1; $f++) {
        if (!sf_rem_kodesh($f, $il) || sf_rem_kodesh($f - 1, $il)) continue;
        $n = $f;
        while (sf_rem_kodesh($n + 1, $il)) $n++;
        $s = sf_rem_sunset($ctx['place'], $f - 1);
        $e = sf_rem_sunset($ctx['place'], $n);
        $approx = false;
        if ($s !== null && $e !== null) {
            $start = $s - SF_REM_MUTE['before'] * 60000;
            $end = $e + SF_REM_MUTE['after'] * 60000;
        } else {
            $ge = sf_cal_greg_from_fixed($f - 1);
            $ga = sf_cal_greg_from_fixed($n + 1);
            $start = sf_cal_zoned_to_utc($tz, $ge['y'], $ge['m'], $ge['d'], 12, 0);
            $end = sf_cal_zoned_to_utc($tz, $ga['y'], $ga['m'], $ga['d'], 1, 0);
            $approx = true;
        }
        $start -= $ext['before'] * 60000;
        $end += $ext['after'] * 60000;
        if ($end > $fromMs && $start < $toMs) $out[] = ['start' => $start, 'end' => $end, 'approx' => $approx];
        $f = $n;
    }
    return $out;
}

/** plan() — {occ: [{rem, ms, muted}], mute, need} */
function sf_rem_plan(array $ctx, int $fromMs, int $toMs): array
{
    $tz = $ctx['tz'];
    $mute = sf_rem_mute($ctx, $fromMs, $toMs);
    $need = ['place' => false, 'method' => false];
    $f0 = sf_rem_local_fixed($tz, $fromMs) - 1;
    $f1 = sf_rem_local_fixed($tz, $toMs) + 1;
    $zc = [];
    $occ = [];
    foreach ($ctx['reminders'] as $r) {
        if (!$r || !$r['active']) continue;
        $rel = $r['type'] === 'relative' || $r['type'] === 'before_end';
        if ($rel) {
            if (!$ctx['place']) { $need['place'] = true; continue; }
            if (!$ctx['def'] || !in_array($r['base'], array_column($ctx['def']['zmanim'], 'key'), true)) { $need['method'] = true; continue; }
        }
        for ($f = $f0; $f <= $f1; $f++) {
            if (!in_array(sf_cal_weekday($f), $r['days'], true)) continue;
            $g = sf_cal_greg_from_fixed($f);
            $day = sprintf('%04d-%02d-%02d', $g['y'], $g['m'], $g['d']);
            if (($r['from'] && $day < $r['from']) || ($r['to'] && $day > $r['to'])) continue;
            if ($rel) {
                $zc[$day] ??= array_column(sf_cal_zmanim($ctx['def'], $ctx['place'], $g['y'], $g['m'], $g['d']), 'ms', 'key');
                $b = $zc[$day][$r['base']] ?? null;
                if ($b === null) continue;
                $ms = $b + ($r['type'] === 'relative' ? $r['offset'] : -$r['before']) * 60000;
            } else {
                [$hh, $mm] = array_map('intval', explode(':', $r['time']));
                $ms = sf_cal_zoned_to_utc($tz, $g['y'], $g['m'], $g['d'], $hh, $mm);
            }
            if ($ms < $fromMs || $ms >= $toMs) continue;
            $muted = false;
            foreach ($mute as $w) if ($ms >= $w['start'] && $ms < $w['end']) { $muted = true; break; }
            $occ[] = ['rem' => $r['id'], 'ms' => $ms, 'muted' => $muted];
        }
    }
    usort($occ, fn($a, $b) => $a['ms'] <=> $b['ms'] ?: strcmp($a['rem'], $b['rem']));
    return ['occ' => $occ, 'mute' => $mute, 'need' => $need];
}

// ── ההקשר של בעלים: התזכורות, ההעדפות, המקום והשיטה ─────────

function sf_rem_cities(): array
{
    static $c = null;
    if ($c === null) {
        $j = json_decode((string)@file_get_contents(sf_root() . '/assets/data/cities.json'), true);
        $c = [];
        foreach ($j['cities'] ?? [] as $x) $c[(int)$x['id']] = $x;
    }
    return $c;
}

/** place() של daily-core.js, עם cc (לישראל / חו"ל) */
function sf_rem_place(mixed $loc): ?array
{
    if (!is_array($loc)) return null;
    if (($loc['kind'] ?? null) === 'city' && is_int($loc['id'] ?? null)) {
        $c = sf_rem_cities()[$loc['id']] ?? null;
        return $c ? ['lat' => (float)$c['lat'], 'lon' => (float)$c['lon'], 'tz' => $c['tz'], 'cc' => $c['cc'] ?? null] : null;
    }
    if (($loc['kind'] ?? null) === 'device' && is_numeric($loc['lat'] ?? null) && is_numeric($loc['lon'] ?? null)
        && abs((float)$loc['lat']) <= 90 && abs((float)$loc['lon']) <= 180 && sf_cal_valid_tz($loc['tz'] ?? null)) {
        return ['lat' => (float)$loc['lat'], 'lon' => (float)$loc['lon'], 'tz' => $loc['tz'], 'cc' => null];
    }
    return null;
}

/** pickMethod(): של הקורא אם מאושרת, אחרת ברירת המחדל של המנהל, אחרת null. שיטת בדיקה — לעולם לא לקוראים */
function sf_rem_method(mixed $chosen): ?array
{
    $rows = sf_rows("SELECT * FROM zmanim_methods WHERE status = 'approved' AND test = 0");
    $pick = null;
    foreach ($rows as $m) if ($m['pid'] === $chosen) $pick = $m;
    if (!$pick) foreach ($rows as $m) if ((int)$m['is_default']) $pick = $m;
    return $pick ? sf_zm_def((int)$pick['id'], (int)$pick['version']) : null;
}

/** ctx מנתונים גולמיים (של קורא או של אורח) */
function sf_rem_ctx(array $raw): array
{
    $prefs = sf_rem_prefs($raw['prefs'] ?? null);
    $place = sf_rem_place($raw['loc'] ?? null);
    $tz = $place['tz'] ?? (sf_cal_valid_tz($prefs['tz']) ? $prefs['tz'] : 'Asia/Jerusalem');
    $rems = [];
    foreach (is_array($raw['reminders'] ?? null) ? $raw['reminders'] : [] as $r) {
        if (count($rems) >= SF_REM_MAX) break;   // תקרה: גם קורא עם אלפי פריטי reminder לא מעמיס על התכנון והתור
        if ($c = sf_rem_clean($r)) $rems[$c['id']] = $c;
    }
    return ['reminders' => array_values($rems), 'byId' => $rems, 'prefs' => $prefs, 'tz' => $tz, 'place' => $place,
        'def' => sf_rem_method($raw['zmethod'] ?? null), 'israel' => sf_rem_israel($place, $tz), 'extend' => $prefs['extend']];
}

function sf_rem_item(int $rid, string $pid): mixed
{
    $j = sf_val('SELECT data FROM personal_items WHERE reader_id = ? AND pid = ? AND deleted_at IS NULL', [$rid, $pid]);
    return $j === null || $j === false ? null : json_decode((string)$j, true);
}

/** ההקשר של בעלים, או null (קורא שנמחק, מכשיר אורח שבוטל) */
function sf_rem_owner_ctx(string $owner): ?array
{
    [$k, $id] = explode(':', $owner, 2) + [1 => '0'];
    $id = (int)$id;
    if ($k === 'r') {
        $r = sf_row("SELECT * FROM readers WHERE id = ? AND status = 'active' AND deleted_at IS NULL", [$id]);
        if (!$r) return null;
        $rems = array_map(fn($j) => json_decode((string)$j, true),
            array_column(sf_rows("SELECT data FROM personal_items WHERE reader_id = ? AND kind = 'reminder' AND deleted_at IS NULL", [$id]), 'data'));
        $ctx = sf_rem_ctx(['reminders' => $rems, 'prefs' => sf_rem_item($id, 'pref:remprefs'), 'loc' => sf_rem_item($id, 'pref:loc'), 'zmethod' => sf_rem_item($id, 'pref:zmethod')]);
        $ctx['email'] = $r['email_verified_at'] !== null ? $r['email'] : null;
        $ctx['push'] = sf_rows('SELECT p.* FROM push_devices p JOIN devices d ON d.id = p.device_id AND d.reader_id = p.reader_id
            WHERE p.reader_id = ? AND p.revoked_at IS NULL AND p.gone_at IS NULL AND d.revoked_at IS NULL ORDER BY p.id', [$id]);
        return $ctx;
    }
    if ($k === 'g') {
        $p = sf_row('SELECT * FROM push_devices WHERE id = ? AND reader_id IS NULL AND revoked_at IS NULL', [$id]);
        if (!$p) return null;
        $raw = $p['guest_data'] !== null ? json_decode((string)sf_unseal((string)$p['guest_data']), true) : [];
        $ctx = sf_rem_ctx(is_array($raw) ? $raw : []);
        $ctx['email'] = null;   // אורח — אין מייל (§2)
        $ctx['push'] = $p['gone_at'] === null ? [$p] : [];
        return $ctx;
    }
    return null;
}

/** הבעלים של מכשיר Push */
function sf_rem_owner_of(array $p): string
{
    return $p['reader_id'] !== null ? 'r:' . (int)$p['reader_id'] : 'g:' . (int)$p['id'];
}

// ── יצירת המופעים ───────────────────────────────────────────

function sf_rem_ms(): int
{
    return sf_time() * 1000;
}

function sf_rem_iso_ms(int $ms): string
{
    return sf_iso(intdiv($ms, 1000));
}

/**
 * מתכנן מחדש את 48 השעות הבאות של בעלים אחד. **סדר:** מבטלים את העתידיים שכבר לא מתוכננים, ואז יוצרים / מחזירים את
 * החסרים — הכול בטרנזקציה אחת. מופע שנשלח לא נוגעים בו; dedupe_key זהה = אותו מופע (לא נוצר שוב).
 */
function sf_rem_schedule(string $owner): array
{
    return sf_tx(function () use ($owner) {
        $ctx = sf_rem_owner_ctx($owner);
        $now = sf_rem_ms();
        $want = [];
        if ($ctx) {
            $plan = sf_rem_plan($ctx, $now, $now + SF_REM_HORIZON);
            $push = [];
            foreach ($ctx['push'] as $p) $push[$p['pid']] = (int)$p['id'];
            foreach ($plan['occ'] as $o) {
                if ($o['muted']) continue;   // §3: מושתק — אף ערוץ
                $rem = $ctx['byId'][$o['rem']];
                $ch = sf_rem_channels($rem, $ctx['prefs']);
                if ($ch['push']) {
                    foreach ($push as $ppid => $pidN) {
                        if ($rem['devices'] !== null && !in_array($ppid, $rem['devices'], true)) continue;
                        $want[hash('sha256', "$owner|{$o['rem']}|push|$ppid|{$o['ms']}")] = ['reminder' => $o['rem'], 'ms' => $o['ms'], 'channel' => 'push', 'dev' => $pidN];
                    }
                }
                if ($ch['email'] && $ctx['email'] !== null) {
                    $want[hash('sha256', "$owner|{$o['rem']}|email||{$o['ms']}")] = ['reminder' => $o['rem'], 'ms' => $o['ms'], 'channel' => 'email', 'dev' => null];
                }
            }
        }
        $nowIso = sf_now();
        $cancelled = $created = 0;
        foreach (sf_rows("SELECT id, dedupe_key FROM reminder_occurrences WHERE owner = ? AND status = 'pending' AND fire_at >= ?", [$owner, $nowIso]) as $row) {
            if (!isset($want[$row['dedupe_key']])) {
                sf_exec("UPDATE reminder_occurrences SET status = 'cancelled', updated_at = ? WHERE id = ? AND status = 'pending'", [$nowIso, $row['id']]);
                $cancelled++;
            }
        }
        foreach ($want as $key => $w) {
            $cur = sf_row('SELECT id, status FROM reminder_occurrences WHERE dedupe_key = ?', [$key]);
            if ($cur) {
                if ($cur['status'] === 'cancelled') { sf_exec("UPDATE reminder_occurrences SET status = 'pending', updated_at = ? WHERE id = ?", [$nowIso, $cur['id']]); $created++; }
                continue;
            }
            sf_insert('reminder_occurrences', ['owner' => $owner, 'reminder' => $w['reminder'], 'fire_at' => sf_rem_iso_ms($w['ms']), 'channel' => $w['channel'],
                'push_device_id' => $w['dev'], 'dedupe_key' => $key, 'status' => 'pending', 'attempts' => 0, 'created_at' => $nowIso, 'updated_at' => $nowIso]);
            $created++;
        }
        return ['cancelled' => $cancelled, 'created' => $created];
    });
}

/** כל הבעלים שיש להם תזכורות (או מופעים שממתינים) */
function sf_rem_owners(): array
{
    $o = [];
    foreach (sf_rows("SELECT DISTINCT p.reader_id FROM personal_items p JOIN readers r ON r.id = p.reader_id
        WHERE p.kind = 'reminder' AND p.deleted_at IS NULL AND r.status = 'active'") as $r) $o['r:' . $r['reader_id']] = true;
    foreach (sf_rows('SELECT id FROM push_devices WHERE reader_id IS NULL AND revoked_at IS NULL AND guest_data IS NOT NULL') as $p) $o['g:' . $p['id']] = true;
    foreach (sf_rows("SELECT DISTINCT owner FROM reminder_occurrences WHERE status = 'pending'") as $r) $o[$r['owner']] = true;
    return array_keys($o);
}

// ── Web Push: VAPID והצפנה ──────────────────────────────────

function sf_b64u(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

function sf_b64u_dec(mixed $s): ?string
{
    if (!is_string($s) || preg_match('/^[A-Za-z0-9_-]+={0,2}\z/', $s) !== 1) return null;
    $r = base64_decode(strtr(rtrim($s, '='), '-_', '+/') . str_repeat('=', (4 - strlen(rtrim($s, '=')) % 4) % 4), true);
    return $r === false ? null : $r;
}

function sf_push_ready(): bool
{
    return function_exists('openssl_pkey_new') && function_exists('openssl_pkey_derive') && in_array('aes-128-gcm', openssl_get_cipher_methods(), true);
}

/** נקודה ציבורית (65 בתים) ממפתח EC */
function sf_ec_point(OpenSSLAsymmetricKey $k): string
{
    $d = openssl_pkey_get_details($k)['ec'];
    return "\x04" . str_pad($d['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['y'], 32, "\0", STR_PAD_LEFT);
}

/** נקודה ציבורית (65 בתים) → מפתח של openssl (SubjectPublicKeyInfo של P-256) */
function sf_ec_pub(string $point): OpenSSLAsymmetricKey|false
{
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $point;
    return openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n");
}

/** data/vapid.json — נוצר בפעם הראשונה (קובץ זמני + link, כמו secret.key). ['public' => b64url, 'pem' => …] */
function sf_vapid(): array
{
    $f = sf_data_dir() . '/vapid.json';
    if (isset($GLOBALS['sf_vapid'][$f])) return $GLOBALS['sf_vapid'][$f];
    if (!is_file($f)) {
        if (!sf_push_ready()) throw new SfError('server', 'Web Push לא זמין בשרת (openssl)');
        $k = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if (!$k || !openssl_pkey_export($k, $pem)) throw new SfError('server');
        $dir = sf_data_dir();
        if (!is_dir($dir)) @mkdir($dir, 0700, true);
        $tmp = $f . '.' . bin2hex(random_bytes(6));
        if (@file_put_contents($tmp, json_encode(['public' => sf_b64u(sf_ec_point($k)), 'pem' => $pem])) !== false) {
            @chmod($tmp, 0600);
            @link($tmp, $f);
        }
        @unlink($tmp);
    }
    $j = json_decode((string)@file_get_contents($f), true);
    if (!is_array($j) || !is_string($j['public'] ?? null) || !is_string($j['pem'] ?? null)) throw new SfError('storage');
    return $GLOBALS['sf_vapid'][$f] = $j;
}

/** חתימת ES256 (DER) → r‖s (64 בתים) */
function sf_ecdsa_raw(string $der): ?string
{
    $p = 0;
    $len = function () use ($der, &$p): int {
        $l = ord($der[$p++]);
        if ($l & 0x80) { $n = $l & 0x7f; $l = 0; for ($i = 0; $i < $n; $i++) $l = ($l << 8) | ord($der[$p++]); }
        return $l;
    };
    if (($der[$p++] ?? '') !== "\x30") return null;
    $len();
    $out = '';
    for ($i = 0; $i < 2; $i++) {
        if (($der[$p++] ?? '') !== "\x02") return null;
        $l = $len();
        $v = ltrim(substr($der, $p, $l), "\0");
        $p += $l;
        if (strlen($v) > 32) return null;
        $out .= str_pad($v, 32, "\0", STR_PAD_LEFT);
    }
    return $out;
}

/** כותרת Authorization של VAPID (RFC 8292) לספק של ה-endpoint */
function sf_vapid_header(string $endpoint): string
{
    $v = sf_vapid();
    $u = parse_url($endpoint);
    $aud = $u['scheme'] . '://' . $u['host'];
    $h = sf_b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $c = sf_b64u(json_encode(['aud' => $aud, 'exp' => sf_time() + 12 * 3600, 'sub' => sf_self_url('')], JSON_UNESCAPED_SLASHES));
    if (!openssl_sign("$h.$c", $der, $v['pem'], OPENSSL_ALGO_SHA256) || ($raw = sf_ecdsa_raw($der)) === null) throw new SfError('server');
    return "vapid t=$h.$c." . sf_b64u($raw) . ', k=' . $v['public'];
}

/** הצפנת התוכן ל-aes128gcm (RFC 8291 + RFC 8188, רשומה אחת). $p256dh — 65 בתים, $auth — 16 */
function sf_push_encrypt(string $plain, string $p256dh, string $auth): string
{
    $ua = sf_ec_pub($p256dh);
    $as = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if (!$ua || !$as) throw new SfError('server');
    $asPub = sf_ec_point($as);
    $ecdh = openssl_pkey_derive($ua, $as, 32);
    if ($ecdh === false) throw new SfError('server');
    $ikm = hash_hkdf('sha256', $ecdh, 32, "WebPush: info\0" . $p256dh . $asPub, $auth);
    $salt = random_bytes(16);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
    $ct = openssl_encrypt($plain . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($ct === false) throw new SfError('server');
    return $salt . pack('N', 4096) . chr(65) . $asPub . $ct . $tag;
}

/** endpoint מותר: https, ספק Push ידוע, בלי פורט אחר ובלי משתמש */
function sf_push_endpoint_ok(mixed $e): bool
{
    if (!is_string($e) || strlen($e) > 1000 || !str_starts_with($e, 'https://')) return false;
    $u = parse_url($e);
    if (!$u || isset($u['user']) || isset($u['pass']) || (isset($u['port']) && $u['port'] !== 443) || empty($u['host'])) return false;
    $h = strtolower($u['host']);
    if (in_array($h, SF_PUSH_HOSTS, true)) return true;
    foreach (SF_PUSH_HOST_SUFFIX as $s) if (str_ends_with($h, $s)) return true;
    return false;
}

/** {endpoint, keys: {p256dh, auth}} מהדפדפן → נקי, או 400 */
function sf_push_sub_in(mixed $s): array
{
    if (!is_array($s) || !sf_push_endpoint_ok($s['endpoint'] ?? null)) sf_fail('bad_input', 'כתובת ה-Push אינה של ספק מוכר');
    $p = sf_b64u_dec($s['keys']['p256dh'] ?? null);
    $a = sf_b64u_dec($s['keys']['auth'] ?? null);
    // p256dh — נקודה אמיתית על P-256 (openssl דוחה נקודה שאינה על העקום), כדי שלא יישמר מנוי שאי אפשר להצפין אליו
    if ($p === null || strlen($p) !== 65 || $p[0] !== "\x04" || $a === null || strlen($a) !== 16 || !sf_ec_pub($p)) sf_fail('bad_input', 'מפתחות ה-Push אינם תקינים');
    return ['endpoint' => $s['endpoint'], 'p256dh' => sf_b64u($p), 'auth' => sf_b64u($a)];
}

/**
 * POST לספק. בבדיקות — $GLOBALS['sf_push_http'](url, headers, body) → status. מחזיר קוד HTTP, או 0 (אין חיבור).
 */
function sf_push_post(string $url, array $headers, string $body): int
{
    if (isset($GLOBALS['sf_push_http'])) return (int)($GLOBALS['sf_push_http'])($url, $headers, $body);
    if (!function_exists('curl_init')) return 0;
    $c = curl_init($url);
    curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER => array_map(fn($k, $v) => "$k: $v", array_keys($headers), $headers)]);
    curl_exec($c);
    $code = (int)curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    curl_close($c);
    return $code;
}

/** שליחה למכשיר Push אחד. מחזיר קוד HTTP (201 = הספק קיבל; 404/410 = המנוי לא קיים עוד) */
function sf_push_send(array $pdev, array $msg, int $ttl = 900): int
{
    $sub = json_decode((string)sf_unseal((string)$pdev['sub']), true);
    if (!is_array($sub) || !sf_push_endpoint_ok($sub['endpoint'] ?? null)) return 410;
    $body = sf_push_encrypt(json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), (string)sf_b64u_dec($sub['p256dh']), (string)sf_b64u_dec($sub['auth']));
    return sf_push_post($sub['endpoint'], ['Authorization' => sf_vapid_header($sub['endpoint']), 'TTL' => (string)$ttl, 'Urgency' => 'high',
        'Content-Encoding' => 'aes128gcm', 'Content-Type' => 'application/octet-stream'], $body);
}

// ── השליחה ──────────────────────────────────────────────────

function sf_rem_log(int $occ, string $channel, string $result, ?int $http = null): void
{
    sf_insert('reminder_send_log', ['occurrence_id' => $occ, 'channel' => $channel, 'result' => $result, 'http' => $http, 'at' => sf_now()]);
}

/** "06:30" — באזור הזמן של המקום (או של המכשיר) */
function sf_rem_at(array $ctx, int $ms): string
{
    $p = sf_cal_local_parts($ctx['tz'], $ms);
    return sprintf('%02d:%02d', $p['hh'], $p['mm']);
}

/** מה שמוצג בהתראה / במייל. השם — של המשתמש עצמו (מוצפן בדרך אליו); בלוג ובמסד — לא נשמר */
function sf_rem_message(array $rem, array $ctx, int $ms, int $occId): array
{
    $pr = $rem['prayer']['name'] ?? '';
    $at = sf_rem_at($ctx, $ms);
    $url = $rem['prayer'] ? '#/r/' . $rem['prayer']['edition'] . ($rem['prayer']['sid'] ? '/' . $rem['prayer']['sid'] : '') : '#/reminders';
    return ['t' => $rem['name'] !== '' ? $rem['name'] : ($pr !== '' ? $pr : 'תזכורת'), 'b' => trim(($pr !== '' && $rem['name'] !== '' ? $pr . ' · ' : '') . $at), 'u' => $url, 'tag' => 'sf-' . $occId];
}

/**
 * סבב שליחה (משימה כל דקה, מ-cron.php). כל מופע נלקח ב-UPDATE מותנה — שתי הרצות לא שולחות אותו פעמיים (קריטריון 4).
 * לפני שליחה בודקים שוב: התזכורת קיימת ופעילה, המכשיר לא בוטל (05 — קריטריון 8), והמייל מאומת.
 */
function sf_rem_send_due(int $limit = 200): array
{
    $now = sf_time();
    $nowIso = sf_iso($now);
    $n = ['sent' => 0, 'failed' => 0, 'missed' => 0, 'skipped' => 0];
    // החמצה: עבר זמנו (cron לא רץ, השרת נפל) — לא שולחים באיחור (§3: גם לא מה שהוחמץ בהשתקה)
    $n['missed'] = sf_exec("UPDATE reminder_occurrences SET status = 'missed', updated_at = ? WHERE status = 'pending' AND fire_at < ?", [$nowIso, sf_iso($now - SF_REM_GRACE)]);
    $ctxs = [];
    foreach (sf_rows("SELECT * FROM reminder_occurrences WHERE status = 'pending' AND fire_at <= ? ORDER BY fire_at, id LIMIT $limit", [$nowIso]) as $o) {
        if (sf_exec("UPDATE reminder_occurrences SET status = 'sending', attempts = attempts + 1, updated_at = ? WHERE id = ? AND status = 'pending'", [$nowIso, $o['id']]) !== 1) continue;
        $id = (int)$o['id'];
        $fin = function (string $status, string $result, ?int $http = null) use ($id, $o, &$n) {
            sf_exec('UPDATE reminder_occurrences SET status = ?, sent_at = ?, updated_at = ? WHERE id = ?', [$status, $status === 'sent' ? sf_now() : null, sf_now(), $id]);
            sf_rem_log($id, $o['channel'], $result, $http);
            $n[$result === 'sent' ? 'sent' : ($result === 'skipped' ? 'skipped' : 'failed')]++;
        };
        try {
            $ctx = $ctxs[$o['owner']] ??= sf_rem_owner_ctx($o['owner']);
            $rem = $ctx['byId'][$o['reminder']] ?? null;
            if (!$ctx || !$rem || !$rem['active']) { $fin('cancelled', 'skipped'); continue; }
            $ms = (int)strtotime($o['fire_at']) * 1000;
            foreach (sf_rem_mute($ctx, $ms - 1, $ms + 1) as $w) if ($ms >= $w['start'] && $ms < $w['end']) { $fin('cancelled', 'skipped'); continue 2; }
            $msg = sf_rem_message($rem, $ctx, $ms, $id);
            if ($o['channel'] === 'push') {
                $p = null;
                foreach ($ctx['push'] as $x) if ((int)$x['id'] === (int)$o['push_device_id']) $p = $x;
                if (!$p) { $fin('cancelled', 'skipped'); continue; }
                $http = sf_push_send($p, $msg);
                if ($http >= 200 && $http < 300) { $fin('sent', 'sent', $http); continue; }
                if ($http === 404 || $http === 410) {
                    sf_exec('UPDATE push_devices SET gone_at = ? WHERE id = ?', [sf_now(), $p['id']]);
                    $fin('failed', 'gone', $http);
                    continue;
                }
                // 429 / 5xx / אין חיבור — עוד ניסיון בסבב הבא, כל עוד לא עבר זמנו
                if ((int)$o['attempts'] + 1 < 3) { $fin('pending', 'retry', $http); continue; }
                $fin('failed', 'failed', $http);
            } elseif ($o['channel'] === 'email') {
                if ($ctx['email'] === null) { $fin('cancelled', 'skipped'); continue; }
                $ok = sf_reader_mail($ctx['email'], 'ספורנו — תזכורת', sf_rem_mail_body($rem, $msg, sf_rem_at($ctx, $ms)));
                $fin($ok ? 'sent' : 'failed', $ok ? 'sent' : 'failed');
            } else $fin('cancelled', 'skipped');
        } catch (Throwable $e) {
            $fin('failed', 'error');
            if (function_exists('devloop_log_write')) devloop_log_write('error', 'job', 'reminder.send', ['message' => get_class($e) . ' ב-' . basename($e->getFile()) . ':' . $e->getLine(), 'data' => ['occurrence' => $id]]);
        }
    }
    return $n;
}

/** גוף המייל: בלי טקסט חופשי של המשתמש (05) — שם התפילה (תוכן של ספורנו), השעה והקישור */
function sf_rem_mail_body(array $rem, array $msg, string $at): string
{
    $pr = $rem['prayer']['name'] ?? '';
    return "שלום,\n\nתזכורת מספורנו" . ($pr !== '' ? " לתפילה: {$pr}" : '') . " — בשעה {$at}.\n\n"
        . 'לפתיחה: ' . sf_self_url('index.html' . $msg['u']) . "\n\n"
        . "התזכורות נקבעות באפליקציה, במסך התזכורות. שם אפשר גם לבטל את הערוץ הזה.\n";
}

// ── זהות של מכשיר Push (אורח) ───────────────────────────────

function sf_set_push_cookie(string $token): void
{
    $GLOBALS['sf_push_cookie_out'] = $token;
    if (PHP_SAPI === 'cli' || headers_sent()) return;
    setcookie(SF_PUSH_COOKIE, $token, ['expires' => $token === '' ? 1 : time() + SF_PUSH_TTL, 'path' => sf_base_path(),
        'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
}

/** מכשיר ה-Push של אורח מהעוגייה sf_push, או null */
function sf_push_guest(array $env): ?array
{
    $c = (string)($env['push_cookie'] ?? '');
    if (preg_match('/^[a-f0-9]{64}$/', $c) !== 1) return null;
    return sf_row('SELECT * FROM push_devices WHERE guest_hash = ? AND reader_id IS NULL AND revoked_at IS NULL', [hash('sha256', $c)]);
}

/** הבעלים של הבקשה: קורא מחובר, אחרת מכשיר אורח, אחרת null */
function sf_rem_req_owner(array $env): ?string
{
    if ($env['_reader']) return 'r:' . (int)$env['_reader']['reader']['id'];
    $g = sf_push_guest($env);
    return $g ? 'g:' . (int)$g['id'] : null;
}

/** מכשירי ה-Push של הבעלים — רק שלו (§21.15) */
function sf_rem_owner_devices(?string $owner): array
{
    if ($owner === null) return [];
    [$k, $id] = explode(':', $owner, 2);
    $rows = $k === 'r'
        ? sf_rows('SELECT p.* FROM push_devices p JOIN devices d ON d.id = p.device_id AND d.reader_id = p.reader_id
            WHERE p.reader_id = ? AND p.revoked_at IS NULL AND d.revoked_at IS NULL ORDER BY p.id', [(int)$id])
        : sf_rows('SELECT * FROM push_devices WHERE id = ? AND reader_id IS NULL AND revoked_at IS NULL', [(int)$id]);
    return $rows;
}

function sf_rem_device_out(array $p): array
{
    return ['pid' => $p['pid'], 'label' => $p['label'], 'created_at' => $p['created_at'], 'gone' => $p['gone_at'] !== null];
}

// ── הפעולות ─────────────────────────────────────────────────

/** push-key: המפתח הציבורי (לרישום בדפדפן), ומכשירי ה-Push של הבעלים */
function sf_push_key(array $env): array
{
    $owner = sf_rem_req_owner($env);
    return ['key' => sf_push_ready() ? sf_vapid()['public'] : null, 'owner' => $owner === null ? null : $owner[0],
        'devices' => array_map('sf_rem_device_out', sf_rem_owner_devices($owner))];
}

/**
 * push-subscribe: {sub: {endpoint, keys}}. קורא — המנוי נקשר למכשיר שלו (05); אורח — מכשיר אנונימי עם עוגייה sf_push.
 * אותו endpoint שכבר רשום — עובר לבעלים הנוכחי (הדפדפן הזה מקבל רק את מה שלו). עד 20 בשעה מאותה כתובת.
 */
function sf_push_subscribe(array $in, array $env): array
{
    if (!sf_push_ready()) sf_fail('server', 'Web Push לא זמין בשרת');
    $sub = sf_push_sub_in($in['sub'] ?? null);
    sf_rate('push-sub', sf_reader_ip($env), 20, 3600);
    $ra = $env['_reader'];
    $guest = $ra ? null : sf_push_guest($env);
    $hash = hash('sha256', $sub['endpoint']);
    $label = sf_device_label((string)($env['ua'] ?? ''));
    [$pid, $old, $owner] = sf_tx(function () use ($sub, $ra, $guest, $hash, $label) {
        $now = sf_now();
        $cur = sf_row('SELECT * FROM push_devices WHERE endpoint_hash = ?', [$hash]);
        $old = $cur && $cur['revoked_at'] === null ? sf_rem_owner_of($cur) : null;
        // endpoint שכבר רשום עובר לבעלים אחר רק עם אותם מפתחות (הדפדפן עצמו); endpoint שדלף לא מספיק כדי להשתלט
        if ($cur && $cur['revoked_at'] === null && $cur['gone_at'] === null) {
            $was = json_decode((string)sf_unseal((string)$cur['sub']), true);
            if (!is_array($was) || !hash_equals((string)($was['p256dh'] ?? ''), $sub['p256dh']) || !hash_equals((string)($was['auth'] ?? ''), $sub['auth'])) sf_fail('bad_input', 'מפתחות ה-Push אינם תואמים למנוי הרשום');
        }
        // תקרה: עד 10 מכשירי Push פעילים לבעלים
        $mine = $ra ? (int)sf_val('SELECT COUNT(*) FROM push_devices WHERE reader_id = ? AND revoked_at IS NULL AND gone_at IS NULL AND endpoint_hash <> ?', [(int)$ra['reader']['id'], $hash]) : 0;
        if ($mine >= SF_PUSH_MAX) sf_fail('state', 'הגעתם למספר המרבי של מכשירים עם התראות (' . SF_PUSH_MAX . '). אפשר לכבות באחד מהם');
        $set = ['sub' => sf_seal(json_encode($sub, JSON_UNESCAPED_SLASHES)), 'label' => $label, 'last_seen_at' => $now, 'revoked_at' => null, 'gone_at' => null];
        $token = null;
        if ($ra) {
            $set += ['reader_id' => (int)$ra['reader']['id'], 'device_id' => (int)$ra['session']['device_id'], 'guest_hash' => null, 'guest_data' => null];
        } elseif ($guest && (!$cur || (int)$cur['id'] === (int)$guest['id'])) {
            $set += ['reader_id' => null, 'device_id' => null];
        } else {
            $token = bin2hex(random_bytes(32));
            $set += ['reader_id' => null, 'device_id' => null, 'guest_hash' => hash('sha256', $token), 'guest_data' => $guest['guest_data'] ?? null];
        }
        if ($guest && !$ra && $cur && (int)$cur['id'] !== (int)$guest['id']) {
            // הדפדפן קיבל endpoint חדש: המכשיר הישן של האורח מתבטל, ההגדרות עוברות
            sf_exec('UPDATE push_devices SET revoked_at = ?, guest_hash = NULL WHERE id = ?', [$now, $guest['id']]);
        }
        if ($cur) {
            sf_exec('UPDATE push_devices SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($set))) . ' WHERE id = ?', [...array_values($set), $cur['id']]);
            $id = (int)$cur['id'];
            $pid = $cur['pid'];
        } elseif ($guest && !$ra) {
            sf_exec('UPDATE push_devices SET endpoint_hash = ?, ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($set))) . ' WHERE id = ?', [$hash, ...array_values($set), $guest['id']]);
            $id = (int)$guest['id'];
            $pid = $guest['pid'];
        } else {
            $pid = sf_pid();
            $id = sf_insert('push_devices', $set + ['pid' => $pid, 'endpoint_hash' => $hash, 'created_at' => $now]);
        }
        if ($token !== null) sf_set_push_cookie($token);
        $row = sf_row('SELECT * FROM push_devices WHERE id = ?', [$id]);
        return [$pid, $old, sf_rem_owner_of($row)];
    });
    if ($old !== null && $old !== $owner) sf_rem_schedule($old);
    sf_rem_schedule($owner);
    return ['device' => $pid];
}

function sf_push_own(array $env, mixed $pid): array
{
    if (!sf_is_pid($pid)) sf_fail('not_found');
    foreach (sf_rem_owner_devices(sf_rem_req_owner($env)) as $p) if ($p['pid'] === $pid) return $p;
    sf_fail('not_found');
}

/** push-unsubscribe: {device} — רק של הבעלים */
function sf_push_unsubscribe(array $in, array $env): array
{
    $p = sf_push_own($env, $in['device'] ?? null);
    sf_exec('UPDATE push_devices SET revoked_at = ? WHERE id = ?', [sf_now(), $p['id']]);
    sf_rem_schedule(sf_rem_owner_of($p));
    return ['revoked' => $p['pid']];
}

/** push-test: התראת ניסיון עכשיו (גם בלי cron) — לבדיקה על מכשיר אמיתי (dev/push-report.md). עד 10 בשעה למכשיר */
function sf_push_test(array $in, array $env): array
{
    $p = sf_push_own($env, $in['device'] ?? null);
    sf_rate('push-test', $p['pid'], 10, 3600);
    $http = sf_push_send($p, ['t' => 'ספורנו', 'b' => 'התראת ניסיון — אם היא הופיעה, ה-Push עובד במכשיר הזה', 'u' => '#/reminders', 'tag' => 'sf-test'], 300);
    sf_rem_log(0, 'push-test', $http >= 200 && $http < 300 ? 'sent' : ($http === 404 || $http === 410 ? 'gone' : 'failed'), $http);
    if ($http === 404 || $http === 410) sf_exec('UPDATE push_devices SET gone_at = ? WHERE id = ?', [sf_now(), $p['id']]);
    return ['http' => $http, 'sent' => $http >= 200 && $http < 300];
}

/**
 * reminders-guest-sync: אורח ששמר תזכורות עם Push — ההגדרות שלו (תזכורות, העדפות, מיקום, שיטה) נשמרות מוצפנות במכשיר
 * ה-Push האנונימי שלו, כדי שהשרת יוכל לשלוח כשהאפליקציה סגורה. **רק עם עוגיית sf_push** ובלי חשבון (לקורא — הסנכרון).
 * עד 64KB ו-100 תזכורות; עד 120 בשעה.
 */
function sf_rem_guest_sync(array $in, array $env): array
{
    if ($env['_reader']) sf_fail('state', 'מחוברים לחשבון — התזכורות מסתנכרנות דרך החשבון');
    $g = sf_push_guest($env) ?? sf_fail('not_found', 'המכשיר לא רשום להתראות');
    sf_rate('rem-guest', $g['pid'], 120, 3600);
    $rems = $in['reminders'] ?? [];
    if (!is_array($rems) || !array_is_list($rems) || count($rems) > SF_REM_MAX) sf_fail('bad_input');
    $data = ['reminders' => array_values(array_filter(array_map('sf_rem_clean', $rems))), 'prefs' => sf_rem_prefs($in['prefs'] ?? null),
        'loc' => is_array($in['loc'] ?? null) ? $in['loc'] : null, 'zmethod' => sf_is_pid($in['zmethod'] ?? null) ? $in['zmethod'] : null];
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || strlen($json) > SF_REM_GUEST_BYTES) sf_fail('too_large');
    sf_exec('UPDATE push_devices SET guest_data = ?, last_seen_at = ? WHERE id = ?', [sf_seal($json), sf_now(), $g['id']]);
    return sf_rem_schedule('g:' . (int)$g['id']);
}

/**
 * reminders-status: מצב הערוצים כמו שהשרת רואה אותו (§2: "מצב אמיתי"), והמופעים שממתינים ב-48 השעות הבאות — בלי שמות
 * (המכשיר מצמיד את השם מהתזכורת שאצלו).
 */
function sf_rem_status(array $env): array
{
    $owner = sf_rem_req_owner($env);
    $ok = sf_meta('cron_last_ok_at');
    $rows = $owner === null ? [] : sf_rows("SELECT o.*, p.pid AS dev FROM reminder_occurrences o LEFT JOIN push_devices p ON p.id = o.push_device_id
        WHERE o.owner = ? AND o.status = 'pending' AND o.fire_at >= ? ORDER BY o.fire_at LIMIT 200", [$owner, sf_now()]);
    $ra = $env['_reader'];
    return ['cron' => ['ok' => $ok !== null && sf_time() - (int)strtotime($ok) <= 900, 'last_ok_at' => $ok],
        'push' => sf_push_ready(), 'email' => $ra ? ['verified' => $ra['reader']['email_verified_at'] !== null] : null,
        'mute' => ['name' => SF_REM_MUTE['name'], 'test' => SF_REM_MUTE['test']],
        'upcoming' => array_map(fn($o) => ['reminder' => $o['reminder'], 'at' => $o['fire_at'], 'channel' => $o['channel'], 'device' => $o['dev']], $rows)];
}

/** dev-reminders: המונים ולוג השליחה — בלי שום תוכן (מזהה מופע, ערוץ, תוצאה, קוד) */
function sf_rem_dev(): array
{
    return ['push' => sf_push_ready(), 'vapid' => is_file(sf_data_dir() . '/vapid.json'), 'mute' => SF_REM_MUTE,
        'devices' => ['reader' => (int)sf_val('SELECT COUNT(*) FROM push_devices WHERE reader_id IS NOT NULL AND revoked_at IS NULL'),
            'guest' => (int)sf_val('SELECT COUNT(*) FROM push_devices WHERE reader_id IS NULL AND revoked_at IS NULL'),
            'gone' => (int)sf_val('SELECT COUNT(*) FROM push_devices WHERE gone_at IS NOT NULL AND revoked_at IS NULL')],
        'counts' => sf_rows('SELECT channel, status, COUNT(*) AS n FROM reminder_occurrences GROUP BY channel, status ORDER BY channel, status'),
        'log' => sf_rows('SELECT occurrence_id, channel, result, http, at FROM reminder_send_log ORDER BY id DESC LIMIT 50')];
}

// ── שירותי הרקע ─────────────────────────────────────────────

sf_job_register('reminders.send', 'שליחת התזכורות שהגיע זמנן (Push ומייל) — כל דקה', function (): void { sf_rem_send_due(); }, every: 60);
sf_job_register('reminders.plan', 'תכנון מחדש של 48 השעות הבאות לכל מי שיש לו תזכורות, וניקוי מופעים ישנים — כל שעה', function (): void {
    foreach (sf_rem_owners() as $o) sf_rem_schedule($o);
    sf_exec("DELETE FROM reminder_occurrences WHERE status <> 'pending' AND fire_at < ?", [sf_iso(sf_time() - 30 * 86400)]);
    sf_exec('DELETE FROM reminder_send_log WHERE at < ?', [sf_iso(sf_time() - 90 * 86400)]);
    sf_rem_guest_cleanup();
}, every: 3600);

/**
 * ניקוי אורחים: מכשיר אורח שבוטל, שהספק ביטל (gone), או שלא נראה מעבר לתוקף העוגייה — נמחק עם ה-endpoint, ההגדרות (מיקום,
 * שמות) והמופעים שלו. מחזיר כמה נמחקו.
 */
function sf_rem_guest_cleanup(): int
{
    $rows = sf_rows('SELECT id FROM push_devices WHERE reader_id IS NULL AND (revoked_at IS NOT NULL OR gone_at IS NOT NULL OR last_seen_at < ?)', [sf_iso(sf_time() - SF_PUSH_TTL)]);
    foreach ($rows as $r) {
        sf_tx(function () use ($r) {
            sf_exec('DELETE FROM reminder_occurrences WHERE owner = ?', ['g:' . (int)$r['id']]);
            sf_exec('DELETE FROM push_devices WHERE id = ? AND reader_id IS NULL', [(int)$r['id']]);
        });
    }
    return count($rows);
}

sf_health_register('push', 'התראות (Web Push)', function () {
    if (!sf_push_ready()) return ['bad', 'openssl עם EC ו-aes-128-gcm חסר — Push לא יעבוד (08)'];
    $gone = (int)sf_val('SELECT COUNT(*) FROM push_devices WHERE gone_at IS NOT NULL AND revoked_at IS NULL');
    return ['ok', 'openssl מוכן · מפתחות VAPID ' . (is_file(sf_data_dir() . '/vapid.json') ? 'קיימים ב-data/' : 'ייווצרו ברישום הראשון') . ($gone ? " · $gone מנויים שהספק ביטל" : '')];
}, true);

sf_health_register('reminders', 'תזכורות', function () {
    $late = (int)sf_val("SELECT COUNT(*) FROM reminder_occurrences WHERE status = 'pending' AND fire_at < ?", [sf_iso(sf_time() - 300)]);
    $missed = (int)sf_val("SELECT COUNT(*) FROM reminder_occurrences WHERE status = 'missed' AND fire_at > ?", [sf_iso(sf_time() - 86400)]);
    if ($late || $missed) return ['warn', "$late ממתינות באיחור, $missed הוחמצו ביממה האחרונה — המתזמן (cron) לא רץ בזמן"];
    return ['ok', (int)sf_val("SELECT COUNT(*) FROM reminder_occurrences WHERE status = 'pending'") . ' מופעים ממתינים · ' . SF_REM_MUTE['name'] . ' (לבדיקה)'];
});
