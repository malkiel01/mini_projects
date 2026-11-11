<?php
/**
 * תמונות הביקור (אפיון 13 §7, §6.3). כל תמונה עוברת את צינור הקליטה של פרוסה 06 (apr_file_ingest) ונשמרת ב-files; השורה
 * ב-visit_photos נכתבת **באותה טרנזקציה** — אין רגע שבו התמונה היא "קובץ שלא שויך" שכל משתתף רואה.
 *
 *  · **נתוני EXIF מוסרים מהקובץ לפני שהוא נשמר** — מיקום, מכשיר, תמונה ממוזערת מוטמעת, XMP, הערות; נשאר רק כיוון התמונה.
 *    בלי פענוח של התמונה (בלי GD): עוברים על מקטעי הקובץ, כך שאין אובדן איכות ואין תלות בהרחבה.
 *  · **הרשאת שימוש** (usage): private — המבקר והשמאי האחראי בלבד; case / report — מי שרואה את התיק. apr_visit_file_rule() הוא
 *    מה ש-apr_file_visible() שואל, ולכן גם file / file-thumb הכלליים, מקור של נתון וקובץ מצורף מכבדים אותה.
 *  · "לדוח" רק עם usage = report, שהמבקר אישר ("צולמה בידי / יש לי רשות להשתמש בה בדוח") — גם CHECK במסד.
 *  · נמען בפורטל לעולם אינו רואה תמונת ביקור: אין לה request_id, ו-portal-file מגיש רק קבצים של הבקשה.
 */

declare(strict_types=1);

require_once __DIR__ . '/visits.php';
require_once __DIR__ . '/documents.php';

const APR_PHOTO_MIMES = ['image/jpeg', 'image/png', 'image/webp'];
const APR_PHOTO_USAGES = ['private', 'case', 'report'];
const APR_PHOTO_SELECT = 'SELECT p.*, f.pid AS file_pid, f.original_name, f.mime, f.size, v.pid AS visit_pid, v.visitor_user_id, v.status AS visit_status, v.seq, v.case_id, u.name AS created_name
    FROM visit_photos p JOIN files f ON f.id = p.file_id JOIN visits v ON v.id = p.visit_id LEFT JOIN users u ON u.id = p.created_by';

// ── הסרת נתונים מהקובץ ────────────────────────────────────────

/** כיוון התמונה (1–8) מתוך מקטע EXIF, או 0. קורא רק את IFD0 — לא ניגש ל-GPS ולא לשום דבר אחר. */
function apr_exif_orientation(string $tiff): int
{
    if (strlen($tiff) < 14) return 0;
    $le = substr($tiff, 0, 2) === 'II';
    if (!$le && substr($tiff, 0, 2) !== 'MM') return 0;
    $u16 = fn(int $o) => $o + 2 <= strlen($tiff) ? unpack($le ? 'v' : 'n', substr($tiff, $o, 2))[1] : 0;
    $u32 = fn(int $o) => $o + 4 <= strlen($tiff) ? unpack($le ? 'V' : 'N', substr($tiff, $o, 4))[1] : 0;
    $ifd = $u32(4);
    $n = min($u16($ifd), 64);
    for ($i = 0; $i < $n; $i++) {
        $e = $ifd + 2 + $i * 12;
        if ($u16($e) === 0x0112) { $o = $u16($e + 8); return $o >= 1 && $o <= 8 ? $o : 0; }
    }
    return 0;
}

/** JPEG: רק מה שנחוץ לפענוח נשאר (JFIF, פרופיל צבע, Adobe, טבלאות וסריקות). כל מה שאחרי סוף התמונה (תמונות נלוות) נחתך. */
function apr_photo_strip_jpeg(string $d): string
{
    $n = strlen($d);
    $out = '';
    $orient = 0;
    $i = 2;
    $done = false;
    while ($i + 2 <= $n) {
        if ($d[$i] !== "\xFF") apr_fail('photo_broken');
        $m = ord($d[$i + 1]);
        if ($m === 0xFF) { $i++; continue; }
        if ($m === 0xD9) { $out .= "\xFF\xD9"; $done = true; break; }
        if (($m >= 0xD0 && $m <= 0xD7) || $m === 0x01) { $out .= substr($d, $i, 2); $i += 2; continue; }
        if ($i + 4 > $n) apr_fail('photo_broken');
        $len = unpack('n', substr($d, $i + 2, 2))[1];
        if ($len < 2 || $i + 2 + $len > $n) apr_fail('photo_broken');
        $body = substr($d, $i + 4, $len - 2);
        $keep = true;
        if ($m === 0xE1) {
            if (str_starts_with($body, "Exif\0\0") && !$orient) $orient = apr_exif_orientation(substr($body, 6));
            $keep = false;
        } elseif ($m === 0xE0) $keep = str_starts_with($body, "JFIF\0");
        elseif ($m >= 0xE2 && $m <= 0xEF) $keep = ($m === 0xE2 && str_starts_with($body, "ICC_PROFILE\0")) || ($m === 0xEE && str_starts_with($body, 'Adobe'));
        elseif ($m === 0xFE) $keep = false;
        if ($keep) $out .= substr($d, $i, 2 + $len);
        $i += 2 + $len;
        if ($m === 0xDA) {
            // נתוני הסריקה: עד הסמן הבא שאינו מילוי (FF00), אתחול (RSTn) או ריפוד (FFFF)
            $j = $i;
            while (($j = strpos($d, "\xFF", $j)) !== false) {
                $b = $j + 1 < $n ? ord($d[$j + 1]) : -1;
                if ($b === 0 || ($b >= 0xD0 && $b <= 0xD7)) { $j += 2; continue; }
                if ($b === 0xFF) { $j++; continue; }
                break;
            }
            if ($j === false) apr_fail('photo_broken');
            $out .= substr($d, $i, $j - $i);
            $i = $j;
        }
    }
    if (!$done) apr_fail('photo_broken');
    // כיוון התמונה בלבד, במקטע EXIF מינימלי: בלעדיו תמונה שצולמה לאורך הייתה מוצגת שוכבת
    $exif = '';
    if ($orient > 1) {
        $body = "Exif\0\0MM\0\x2A\0\0\0\x08\0\x01\x01\x12\0\x03\0\0\0\x01" . pack('n', $orient) . "\0\0\0\0\0\0";
        $exif = "\xFF\xE1" . pack('n', strlen($body) + 2) . $body;
    }
    return "\xFF\xD8" . $exif . $out;
}

/** PNG: בלי מקטעי טקסט, EXIF וזמן; נעצר ב-IEND. */
function apr_photo_strip_png(string $d): string
{
    $n = strlen($d);
    $out = substr($d, 0, 8);
    for ($i = 8; $i + 12 <= $n;) {
        $len = unpack('N', substr($d, $i, 4))[1];
        $type = substr($d, $i + 4, 4);
        if ($len > $n || $i + 12 + $len > $n) apr_fail('photo_broken');
        if (!in_array($type, ['eXIf', 'tEXt', 'zTXt', 'iTXt', 'tIME'], true)) $out .= substr($d, $i, 12 + $len);
        $i += 12 + $len;
        if ($type === 'IEND') return $out;
    }
    apr_fail('photo_broken');
}

/** WebP: בלי מקטעי EXIF ו-XMP, והדגלים שלהם בכותרת המורחבת כבויים. */
function apr_photo_strip_webp(string $d): string
{
    $n = strlen($d);
    $body = '';
    for ($i = 12; $i + 8 <= $n;) {
        $cc = substr($d, $i, 4);
        $len = unpack('V', substr($d, $i + 4, 4))[1];
        $full = 8 + $len + ($len & 1);
        if ($len > $n || $i + 8 + $len > $n) apr_fail('photo_broken');
        $chunk = str_pad(substr($d, $i, $full), $full, "\0");
        if ($cc === 'VP8X' && $len >= 1) $chunk[8] = chr(ord($chunk[8]) & ~0x0C);
        if ($cc !== 'EXIF' && $cc !== 'XMP ') $body .= $chunk;
        $i += $full;
    }
    if ($body === '') apr_fail('photo_broken');
    return 'RIFF' . pack('V', strlen($body) + 4) . 'WEBP' . $body;
}

/** מנקה את הקובץ במקומו. סוג שאינו JPEG / PNG / WebP ← photo_type; קובץ שבור ← photo_broken. */
function apr_photo_strip(string $path): void
{
    $d = (string)file_get_contents($path);
    if (str_starts_with($d, "\xFF\xD8")) $out = apr_photo_strip_jpeg($d);
    elseif (str_starts_with($d, "\x89PNG\r\n\x1a\n")) $out = apr_photo_strip_png($d);
    elseif (str_starts_with($d, 'RIFF') && substr($d, 8, 4) === 'WEBP') $out = apr_photo_strip_webp($d);
    else apr_fail('photo_type');
    if (file_put_contents($path, $out) !== strlen($out)) apr_fail('storage_full');
}

// ── מי רואה ───────────────────────────────────────────────────

/** $p: usage, deleted_at, visitor_user_id. private — המבקר והשמאי האחראי בלבד (13-C1). תמונה שנמחקה — אף אחד. */
function apr_visit_photo_visible(array $ctx, array $c, array $p): bool
{
    if ($p['deleted_at'] !== null || empty($c['perms']['view'])) return false;
    if ($p['usage'] !== 'private') return true;
    $me = (int)$ctx['user']['id'];
    return $me === (int)$p['visitor_user_id'] || $me === (int)$c['responsible_user_id'];
}

/** לקובץ שהוא תמונת ביקור: האם מותר לי לראות אותו. null — הקובץ אינו תמונת ביקור. נשאל מתוך apr_file_visible(). */
function apr_visit_file_rule(array $ctx, array $c, array $f): ?bool
{
    $p = apr_row('SELECT p.usage, p.deleted_at, v.visitor_user_id FROM visit_photos p JOIN visits v ON v.id = p.visit_id WHERE p.file_id = ?', [$f['id'] ?? 0]);
    return $p ? apr_visit_photo_visible($ctx, $c, $p) : null;
}

/** תמונת ביקור אינה "מסמך": הפעולות הכלליות על קבצים (שיוך לדרישה, עריכה, מחיקה) אינן חלות עליה — 404. */
function apr_visit_not_photo(array $f): void
{
    if (apr_val('SELECT 1 FROM visit_photos WHERE file_id = ?', [$f['id']])) apr_fail('not_found');
}

/** מה מוסתר ממני ביומן הפעילות של התיק: מזהי קבצים ותמונות של תמונות ביקור שאינני רשאי לראות (כולל שנמחקו). */
function apr_visit_hidden_pids(array $ctx, array $c): array
{
    $out = [];
    foreach (apr_rows('SELECT p.pid, p.usage, p.deleted_at, f.pid AS file_pid, v.visitor_user_id FROM visit_photos p JOIN files f ON f.id = p.file_id JOIN visits v ON v.id = p.visit_id WHERE v.case_id = ?', [$c['id']]) as $p) {
        if (!apr_visit_photo_visible($ctx, $c, ['deleted_at' => null] + $p)) $out[$p['pid']] = $out[$p['file_pid']] = true;
    }
    return $out;
}

function apr_visit_photo_out(array $p): array
{
    return ['pid' => $p['pid'], 'file' => $p['file_pid'], 'field' => $p['field_key'], 'row' => (int)$p['row_no'], 'caption' => $p['caption'], 'place' => $p['place'], 'sort' => (int)$p['sort'],
        'for_report' => (bool)$p['for_report'], 'usage' => $p['usage'], 'taken_at' => $p['taken_at'], 'geo' => $p['geo_lat'] === null ? null : ['lat' => (float)$p['geo_lat'], 'lng' => (float)$p['geo_lng']],
        'rev' => (int)$p['rev'], 'by' => $p['created_name'], 'created_at' => $p['created_at'], 'size' => (int)$p['size']];
}

/** התמונות של ביקור שמותר לי לראות, לפי הסדר. */
function apr_visit_photos_out(array $ctx, array $c, array $v): array
{
    $out = [];
    foreach (apr_rows(APR_PHOTO_SELECT . ' WHERE p.visit_id = ? AND p.deleted_at IS NULL ORDER BY p.sort, p.id', [$v['id']]) as $p) {
        if (apr_visit_photo_visible($ctx, $c, $p)) $out[] = apr_visit_photo_out($p);
    }
    return $out;
}

/**
 * התמונה והתיק שלה. תמונה של משרד אחר, של תיק שאינני משתתף בו, תמונה שנמחקה, **ותמונה פרטית שאינני המבקר או האחראי** — אותו
 * 404 (13-C1, 13-C5). $need: view · manage (המבקר או האחראי, עם visit — §10).
 */
function apr_visit_photo_get(array $ctx, mixed $pid, string $need = 'view'): array
{
    $casePid = apr_is_pid($pid) ? apr_val('SELECT c.pid FROM visit_photos p JOIN visits v ON v.id = p.visit_id JOIN cases c ON c.id = v.case_id WHERE p.pid = ?', [$pid]) : false;
    $c = apr_case_get($ctx, $casePid ?: null);
    $p = apr_row(APR_PHOTO_SELECT . ' WHERE p.pid = ? AND v.case_id = ?', [$pid, $c['id']]);
    if (!$p || !apr_visit_photo_visible($ctx, $c, $p)) apr_fail('not_found');
    if ($need === 'view') return [$c, $p];
    if (!apr_visit_is_filler($ctx, $c, $p)) apr_fail('forbidden');
    apr_case_writable($c);
    return [$c, $p];
}

// ── העלאה ─────────────────────────────────────────────────────

/**
 * העלאת תמונה לביקור — גוף גולמי; בכתובת: visit, field?, row?, name, key, caption?, place?, taken_at?, ולמיקום: consent=1, lat, lng, acc?.
 * **לפני שהגוף נקרא**: זהות ו-CSRF (הנתב), המבקר או האחראי עם visit, ביקור פתוח, שדה תמונה של הטופס, קצב ומכסה (הצינור).
 * אחרי הקריאה ולפני כל דבר אחר: הנתונים מוסרים מהקובץ (apr_photo_strip) — ה-hash, הגודל והקובץ השמור הם של הקובץ הנקי (13-C4).
 * מיקום נשמר רק כשהגדרת המשרד דולקת **וגם** המבקר הסכים במכשיר (consent=1) — ואף פעם לא מתוך הקובץ.
 */
function apr_visit_photo_upload(array $ctx, array $q, array $env): array
{
    $uid = (int)$ctx['user']['id'];
    $st = [];
    $reader = $env['upload_reader'] ?? null;
    $direct = $env['upload'] ?? null;
    $env['upload_reader'] = function () use ($reader, $direct) {
        $r = $reader ? $reader() : ['upload' => $direct];
        if (($r['upload_error'] ?? '') === '' && is_string($r['upload'] ?? null) && is_file($r['upload'])) {
            if (filesize($r['upload']) > apr_file_max()) return ['upload_error' => 'file_too_large'] + $r;
            apr_photo_strip($r['upload']);
        }
        return $r;
    };
    $caption = apr_clean_text($q['caption'] ?? null, 300) ?? '';
    $place = apr_clean_text($q['place'] ?? null, 80) ?? '';
    $taken = $q['taken_at'] ?? null;
    if ($taken !== null && $taken !== '' && (!is_string($taken) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?\z/', $taken))) apr_fail('visit_when');
    $res = apr_file_ingest(function () use ($ctx, $q, &$st) {
        [$c, $v] = apr_visit_get($ctx, $q['visit'] ?? null, 'fill');
        if ($v['status'] !== 'in_progress') apr_fail('visit_not_open');
        $form = apr_visit_form($c, $v);
        $field = null;
        $row = 0;
        if (($q['field'] ?? '') !== '') {
            $f = apr_visit_field($form, $q['field']);
            if ($f['type'] !== 'photo') apr_fail('visit_field');
            $row = apr_visit_row_no($v, $form, $f, ($q['row'] ?? '') === '' || $q['row'] === '0' ? null : (ctype_digit((string)$q['row']) ? (int)$q['row'] : -1));
            $field = $f['key'];
        }
        $geo = null;
        if (($q['consent'] ?? '') === '1' && apr_val('SELECT photo_geo FROM office_visit_settings WHERE office_id = ?', [$c['office_id']])
            && is_numeric($q['lat'] ?? null) && is_numeric($q['lng'] ?? null) && abs((float)$q['lat']) <= 90 && abs((float)$q['lng']) <= 180) {
            $geo = [(float)$q['lat'], (float)$q['lng'], is_numeric($q['acc'] ?? null) ? (float)$q['acc'] : null];
        }
        $st = ['c' => $c, 'v' => $v, 'field' => $field, 'row' => $row, 'geo' => $geo];
        return $c;
    }, ['kind' => 'user', 'uid' => $uid, 'channel' => 'other', 'rate_key' => (string)$uid, 'key' => fn() => apr_upload_key($ctx, $q['key'] ?? null), 'mimes' => APR_PHOTO_MIMES,
        // בתוך הטרנזקציה של הצינור, מיד אחרי השורה ב-files: התמונה נולדת כתמונת ביקור פרטית
        'after' => function (int $fileId, string $filePid) use (&$st, $uid, $caption, $place, $taken, $q) {
            if (apr_val('SELECT status FROM visits WHERE id = ?', [$st['v']['id']]) !== 'in_progress') apr_fail('visit_not_open');
            $pid = apr_pid();
            apr_insert('visit_photos', ['pid' => $pid, 'visit_id' => $st['v']['id'], 'file_id' => $fileId, 'field_key' => $st['field'], 'row_no' => $st['row'], 'caption' => $caption, 'place' => $place,
                'sort' => (int)apr_val('SELECT COALESCE(MAX(sort), 0) + 1 FROM visit_photos WHERE visit_id = ?', [$st['v']['id']]), 'taken_at' => $taken ?: null,
                'geo_lat' => $st['geo'][0] ?? null, 'geo_lng' => $st['geo'][1] ?? null, 'geo_acc' => $st['geo'][2] ?? null, 'client_op_id' => is_string($q['key'] ?? null) ? $q['key'] : null,
                'created_by' => $uid, 'created_at' => apr_now(), 'updated_at' => apr_now()]);
            apr_case_audit($st['c'], 'visit.photo', 'visit_photo', $pid, ['seq' => (int)$st['v']['seq'], 'geo' => $st['geo'] !== null]);
        }],
        ['dup' => '1', 'requirement' => null, 'relation' => null, 'source_note' => null, 'doc_date' => null, 'name' => $q['name'] ?? 'photo.jpg'], $env);
    $p = apr_row(APR_PHOTO_SELECT . ' WHERE f.pid = ?', [$res['file'] ?? '']) ?? apr_fail('not_found');
    $c = $st['c'];
    $stt = apr_visit_state($c, $st['v'], apr_visit_form($c, $st['v']));
    return ['photo' => apr_visit_photo_out($p), 'repeat' => !empty($res['repeat']), 'geo_recorded' => $p['geo_lat'] !== null, 'progress' => $stt['progress'], 'missing' => $stt['missing']];
}

/** הגשת תמונה: photo, thumb=1 לממוזערת. אותן הרשאות לשתיהן (13-C1). הממוזערת נוצרת מהקובץ השמור — הנקי. */
function apr_visit_photo_serve(array $ctx, array $q): array
{
    [, $p] = apr_visit_photo_get($ctx, $q['photo'] ?? null);
    return ($q['thumb'] ?? '') === '1' ? apr_file_thumb($ctx, ['file' => $p['file_pid']]) : apr_file_serve($ctx, ['file' => $p['file_pid'], 'inline' => '1']);
}

/**
 * עדכון תמונה: photo, rev + caption?, place?, usage?, for_report?, confirm_rights?. המבקר או האחראי; בביקור פתוח או שהושלם.
 * usage = report דורש confirm_rights = true ("צולמה בידי / יש לי רשות להשתמש בה בדוח"); for_report בלי usage = report ← נדחה (13-C2).
 */
function apr_visit_photo_update(array $ctx, array $in): array
{
    [$c, $p] = apr_visit_photo_get($ctx, $in['photo'] ?? null, 'manage');
    $rev = apr_visit_rev($in['rev'] ?? null);
    if (!in_array($p['visit_status'], ['in_progress', 'completed'], true)) apr_fail('visit_not_open');
    $set = [];
    if (array_key_exists('caption', $in)) $set['caption'] = apr_clean_text($in['caption'], 300) ?? '';
    if (array_key_exists('place', $in)) $set['place'] = apr_clean_text($in['place'], 80) ?? '';
    $usage = $p['usage'];
    if (array_key_exists('usage', $in)) {
        $usage = apr_clean_choice($in['usage'], APR_PHOTO_USAGES) ?? apr_fail('photo_usage');
        if ($usage !== $p['usage']) {
            $set['usage'] = $usage;
            if ($usage === 'report') {
                if (($in['confirm_rights'] ?? null) !== true) apr_fail('photo_rights');
                $set += ['rights_by' => (int)$ctx['user']['id'], 'rights_at' => apr_now()];
            }
        }
    }
    $report = (bool)$p['for_report'];
    if (array_key_exists('for_report', $in)) {
        if (!is_bool($in['for_report'])) apr_fail('photo_usage');
        $report = $in['for_report'];
        if ($report !== (bool)$p['for_report']) $set['for_report'] = (int)$report;
    }
    if ($report && $usage !== 'report') apr_fail('photo_report_usage');
    if (!$set) apr_fail('nothing_to_change');
    return apr_tx(function () use ($c, $p, $rev, $set) {
        apr_case_still_open($c);
        apr_visit_photo_set($p, $rev, $set);
        apr_case_audit($c, 'visit.photo_update', 'visit_photo', $p['pid'], ['seq' => (int)$p['seq'], 'fields' => array_keys(array_diff_key($set, ['rights_by' => 0, 'rights_at' => 0]))]
            + (isset($set['usage']) ? ['usage' => $set['usage']] : []));
        apr_case_touch($c);
        return ['photo' => apr_visit_photo_out(apr_row(APR_PHOTO_SELECT . ' WHERE p.id = ?', [$p['id']]))];
    });
}

function apr_visit_photo_set(array $p, int $rev, array $set): void
{
    $set += ['updated_at' => apr_now()];
    if (apr_exec('UPDATE visit_photos SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($set))) . ', rev = rev + 1 WHERE id = ? AND rev = ? AND deleted_at IS NULL', [...array_values($set), $p['id'], $rev]) === 1) return;
    $cur = apr_row(APR_PHOTO_SELECT . ' WHERE p.id = ?', [$p['id']]);
    throw new AprVisitError('visit_changed', ['current' => $cur['deleted_at'] === null ? apr_visit_photo_out($cur) : null]);
}

/** סדר התמונות: visit, order [pid, …] — כל התמונות שמותר לי לראות. */
function apr_visit_photo_order(array $ctx, array $in): array
{
    [$c, $v] = apr_visit_get($ctx, $in['visit'] ?? null, 'fill');
    if (!in_array($v['status'], ['in_progress', 'completed'], true)) apr_fail('visit_not_open');
    $order = $in['order'] ?? null;
    if (!is_array($order) || !array_is_list($order) || count($order) > 500 || count($order) !== count(array_unique($order))) apr_fail('visit_value');
    return apr_tx(function () use ($ctx, $c, $v, $order) {
        apr_case_still_open($c);
        $rows = array_column(apr_rows(APR_PHOTO_SELECT . ' WHERE p.visit_id = ? AND p.deleted_at IS NULL', [$v['id']]), null, 'pid');
        foreach ($order as $i => $pid) {
            $p = is_string($pid) ? ($rows[$pid] ?? null) : null;
            if (!$p || !apr_visit_photo_visible($ctx, $c, $p)) apr_fail('not_found');
            if ((int)$p['sort'] !== $i + 1) apr_exec('UPDATE visit_photos SET sort = ?, rev = rev + 1, updated_at = ? WHERE id = ?', [$i + 1, apr_now(), $p['id']]);
        }
        return ['photos' => apr_visit_photos_out($ctx, $c, $v)];
    });
}

/** מחיקה רכה: photo, rev. הקובץ נשאר בדיסק (מחזור החיים — פרוסה 21), ואינו מוגש עוד לאיש. */
function apr_visit_photo_delete(array $ctx, array $in): array
{
    [$c, $p] = apr_visit_photo_get($ctx, $in['photo'] ?? null, 'manage');
    $rev = apr_visit_rev($in['rev'] ?? null);
    if (!in_array($p['visit_status'], ['in_progress', 'completed'], true)) apr_fail('visit_not_open');
    return apr_tx(function () use ($ctx, $c, $p, $rev) {
        apr_case_still_open($c);
        apr_visit_photo_set($p, $rev, ['deleted_at' => apr_now(), 'deleted_by' => (int)$ctx['user']['id'], 'for_report' => 0]);
        apr_exec('UPDATE files SET deleted_at = ?, deleted_by = ?, delete_reason = ? WHERE id = ? AND deleted_at IS NULL', [apr_now(), (int)$ctx['user']['id'], 'תמונת ביקור נמחקה', $p['file_id']]);
        apr_case_audit($c, 'visit.photo_delete', 'visit_photo', $p['pid'], ['seq' => (int)$p['seq'], 'was_for_report' => (bool)$p['for_report']]);
        apr_case_touch($c);
        return ['deleted' => true];
    });
}
