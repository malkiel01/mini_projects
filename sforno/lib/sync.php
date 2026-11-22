<?php
/**
 * הסנכרון של המידע האישי (אפיון 05 §4, §5). כל פריט — שורה ב-personal_items, עם pid שנוצר במכשיר (ייחודי לקורא).
 *
 *   rev      — מונה של השרת (readers.sync_rev): כל שינוי מקבל את הבא. "השינוי האחרון קובע" = האחרון שהגיע לשרת;
 *              **שעון המכשיר לא נקרא בכלל** — גם updated_at נקבע כאן.
 *   מחיקה    — tombstone (deleted_at, data = NULL). עריכה שמבוססת על rev ישן מה-tombstone — נדחית ("gone"), כך שמכשיר
 *              שהיה מנותק לא מחזיר פריט שנמחק.
 *   אידמפוטנטי — אותו pid עם אותו תוכן ← "same", בלי rev חדש ובלי שורה חדשה.
 *   create_only — מיזוג אורח (§5): נוצר רק אם אין כזה בחשבון; קיים ← "kept" (של החשבון קובע).
 *   הצפנה    — note ו-prayer_name נשמרים מוצפנים (lib/readers.php sf_seal); מפוענחים רק בתשובה לבעל המידע.
 *
 * כל שאילתה כאן מסוננת לפי reader_id של ה-session — **אין דרך לגעת בפריט של קורא אחר** (pid זהה אצל קורא אחר הוא פריט
 * אחר לגמרי).
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/readers.php';

const SF_SYNC_KINDS = ['pref', 'custom', 'bookmark', 'position', 'highlight', 'note', 'favorite', 'tehillim_list', 'prayer_name', 'reminder'];
const SF_SYNC_SEALED = ['note', 'prayer_name'];
const SF_SYNC_BATCH = 500;
const SF_SYNC_ITEM_MAX = 32_000;
const SF_SYNC_READER_MAX = 20_000;
const SF_SYNC_READER_BYTES = 5_000_000;   // כל המידע של קורא, כפי שנשמר (אחרי הצפנה)
const SF_SYNC_PUSH_PER_MIN = 120;
/** פריטים שהתזכורות בשרת תלויות בהם (08) */
const SF_SYNC_REPLAN = ['pref:loc', 'pref:zmethod', 'pref:remprefs'];

/** שורה → מה שיוצא לבעל המידע. */
function sf_sync_out(array $row): array
{
    $data = null;
    if ($row['data'] !== null) {
        $json = in_array($row['kind'], SF_SYNC_SEALED, true) ? sf_unseal((string)$row['data']) : (string)$row['data'];
        $data = $json !== null ? json_decode($json, true) : null;
    }
    return ['pid' => $row['pid'], 'kind' => $row['kind'], 'ref' => $row['ref'], 'data' => $data, 'deleted' => $row['deleted_at'] !== null,
        'rev' => (int)$row['rev'], 'updated_at' => $row['updated_at']];
}

/** פריט שנשלח מהמכשיר — בדיקה ונרמול. מחזיר null אם הוא פגום (נענה ב-"bad" ולא מפיל את כל המנה). */
function sf_sync_in(mixed $it): ?array
{
    if (!is_array($it)) return null;
    $pid = $it['pid'] ?? null;
    $kind = $it['kind'] ?? null;
    if (!is_string($pid) || preg_match('/^[A-Za-z0-9][A-Za-z0-9:._-]{0,119}\z/', $pid) !== 1) return null;
    if (!is_string($kind) || !in_array($kind, SF_SYNC_KINDS, true)) return null;
    $ref = $it['ref'] ?? null;
    if ($ref !== null && (!is_string($ref) || preg_match('/^[A-Za-z0-9:._-]{1,160}\z/', $ref) !== 1)) return null;
    $deleted = ($it['deleted'] ?? false) === true;
    $json = null;
    if (!$deleted) {
        if (!array_key_exists('data', $it) || (!is_array($it['data']) && !is_scalar($it['data']))) return null;
        $json = json_encode($it['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || strlen($json) > SF_SYNC_ITEM_MAX) return null;
    }
    $base = $it['base'] ?? 0;
    return ['pid' => $pid, 'kind' => $kind, 'ref' => $ref, 'json' => $json, 'deleted' => $deleted,
        'base' => is_int($base) && $base >= 0 ? $base : 0, 'create_only' => ($it['create_only'] ?? false) === true];
}

function sf_sync_next_rev(int $readerId): int
{
    sf_exec('UPDATE readers SET sync_rev = sync_rev + 1 WHERE id = ?', [$readerId]);
    return (int)sf_val('SELECT sync_rev FROM readers WHERE id = ?', [$readerId]);
}

/**
 * sync-push: {items: [{pid, kind, ref, data, deleted, base, create_only}]} — עד 500 בבת אחת, בטרנזקציה אחת.
 * מחזיר לכל פריט status (new · saved · same · kept · gone · bad · full) ואת המצב שלו בשרת אחרי ההחלטה.
 * full — הפריט היה מעביר את הקורא מעל 5MB (מחיקות תמיד עוברות). עד 120 שליחות בדקה לקורא (429).
 */
function sf_sync_push(array $ra, array $in): array
{
    $items = $in['items'] ?? null;
    if (!is_array($items) || !array_is_list($items)) sf_fail('bad_input');
    if (count($items) > SF_SYNC_BATCH) sf_fail('too_large');
    $rid = (int)$ra['reader']['id'];
    $dev = (int)$ra['session']['device_id'];
    sf_rate('sync', (string)$ra['reader']['pid'], SF_SYNC_PUSH_PER_MIN, 60);
    $res = sf_tx(function () use ($items, $rid, $dev) {
        $now = sf_now();
        $out = [];
        $bytes = (int)sf_val('SELECT COALESCE(SUM(LENGTH(data)), 0) FROM personal_items WHERE reader_id = ?', [$rid]);
        foreach ($items as $raw) {
            $it = sf_sync_in($raw);
            if ($it === null) { $out[] = ['pid' => is_array($raw) && is_string($raw['pid'] ?? null) ? mb_substr($raw['pid'], 0, 120) : null, 'status' => 'bad']; continue; }
            $cur = sf_row('SELECT * FROM personal_items WHERE reader_id = ? AND pid = ?', [$rid, $it['pid']]);
            $sealed = in_array($it['kind'], SF_SYNC_SEALED, true);
            $store = $it['json'] === null ? null : ($sealed ? sf_seal($it['json']) : $it['json']);
            $grow = strlen((string)$store) - ($cur ? strlen((string)$cur['data']) : 0);
            $full = $grow > 0 && $bytes + $grow > SF_SYNC_READER_BYTES;
            if (!$cur) {
                if ($full) { $out[] = ['pid' => $it['pid'], 'status' => 'full']; continue; }
                if ((int)sf_val('SELECT COUNT(*) FROM personal_items WHERE reader_id = ?', [$rid]) >= SF_SYNC_READER_MAX) sf_fail('too_large', 'הגעתם למספר הפריטים המרבי בחשבון');
                $rev = sf_sync_next_rev($rid);
                sf_insert('personal_items', ['pid' => $it['pid'], 'reader_id' => $rid, 'kind' => $it['kind'], 'ref' => $it['ref'], 'data' => $store,
                    'rev' => $rev, 'created_at' => $now, 'updated_at' => $now, 'deleted_at' => $it['deleted'] ? $now : null, 'device_id' => $dev]);
                $status = 'new';
                $bytes += $grow;
            } elseif ($cur['kind'] !== $it['kind']) {
                $status = 'bad';
            } elseif ($it['create_only']) {
                $status = 'kept';
            } elseif ($cur['deleted_at'] !== null && !$it['deleted'] && $it['base'] < (int)$cur['rev']) {
                $status = 'gone';
            } else {
                $curJson = $cur['data'] === null ? null : ($sealed ? sf_unseal((string)$cur['data']) : (string)$cur['data']);
                $same = $it['deleted'] === ($cur['deleted_at'] !== null) && $it['ref'] === $cur['ref']
                    && ($it['deleted'] || ($curJson !== null && json_decode($curJson, true) === json_decode((string)$it['json'], true)));
                if ($same) {
                    $status = 'same';
                } elseif ($full) {
                    $status = 'full';
                } else {
                    $rev = sf_sync_next_rev($rid);
                    sf_exec('UPDATE personal_items SET ref = ?, data = ?, rev = ?, updated_at = ?, deleted_at = ?, device_id = ? WHERE id = ?',
                        [$it['ref'], $store, $rev, $now, $it['deleted'] ? $now : null, $dev, $cur['id']]);
                    $status = 'saved';
                    $bytes += $grow;
                }
            }
            $row = sf_row('SELECT * FROM personal_items WHERE reader_id = ? AND pid = ?', [$rid, $it['pid']]);
            $out[] = ['pid' => $it['pid'], 'status' => $status] + ($row ? ['item' => sf_sync_out($row)] : []);
        }
        return ['results' => $out, 'cursor' => (int)sf_val('SELECT sync_rev FROM readers WHERE id = ?', [$rid])];
    });
    // 08: תזכורת, מיקום, שיטה או העדפות התזכורות השתנו ← תכנון מחדש מיד (קודם מבטל את המופעים הישנים)
    foreach ($res['results'] as $r) {
        $k = $r['item']['kind'] ?? null;
        if (in_array($r['status'], ['new', 'saved'], true) && ($k === 'reminder' || in_array($r['pid'], SF_SYNC_REPLAN, true))) {
            if (function_exists('sf_rem_schedule')) sf_rem_schedule('r:' . $rid);
            break;
        }
    }
    return $res;
}

/** sync-pull?since=N — כל מה שהשתנה אחרי N (כולל tombstones), לפי rev, עד 500. more — יש עוד; cursor — ה-rev האחרון שהוחזר. */
function sf_sync_pull(array $ra, array $get): array
{
    $since = is_string($get['since'] ?? null) && ctype_digit($get['since']) ? (int)$get['since'] : 0;
    $rid = (int)$ra['reader']['id'];
    $rows = sf_rows('SELECT * FROM personal_items WHERE reader_id = ? AND rev > ? ORDER BY rev LIMIT ' . (SF_SYNC_BATCH + 1), [$rid, $since]);
    $more = count($rows) > SF_SYNC_BATCH;
    $rows = array_slice($rows, 0, SF_SYNC_BATCH);
    return ['items' => array_map('sf_sync_out', $rows), 'more' => $more,
        'cursor' => $rows ? (int)end($rows)['rev'] : $since];
}
