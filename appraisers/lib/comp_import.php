<?php
/**
 * פרוסה 15 — ייבוא עסקאות מקובץ או מהדבקה (docs/spec/15-comparables.md §4.2): טבלה ← מיפוי עמודות ידני ← תצוגה מקדימה ← ייבוא.
 * **המערכת אינה מניחה פורמט ייצוא של אתר כלשהו**: אין זיהוי עמודות לפי שמן, ורק מיפוי שהמשרד עצמו שמר מוצע שוב.
 *
 *   XLSX — עולה דרך צינור הקליטה של פרוסה 06 (comp-import-upload: סוג לפי התוכן, סורק, מכסות) ונשמר כקובץ של התיק (file_id).
 *   CSV / הדבקה — הדפדפן שולח את הטקסט. צינור הקליטה מזהה סוג לפי התוכן, ולטקסט אין חתימה — ולכן הוא אינו נשמר כקובץ;
 *                 נשמרים שמו, טביעת התוכן (sha256) ומספר השורות, וכל שורה — כפי שהגיעה — ב-comps.raw.
 * כל שורה נשמרת ב-raw בשלמותה, גם עמודות שלא מופו. ערך שאי אפשר לפענח ← השדה ריק (NULL), לא ניחוש (§10.9).
 * אין ייבוא בלי אישור "יש לי זכות להשתמש בנתונים האלה" — גם ה-CHECK במיגרציה אוכף זאת.
 */

declare(strict_types=1);

require_once __DIR__ . '/comps.php';
require_once __DIR__ . '/documents.php';

const APR_COMP_IMPORT_MAX = 2000;                 // שורות לקובץ (§10.10); מעבר לזה — לסנן קודם
const APR_COMP_IMPORT_COLS = 60;
const APR_COMP_IMPORT_CELL = 500;
/** לאן אפשר למפות עמודה. extra — "מאפיין נוסף", נשמר עם שם העמודה. סוג השטח, דיוק המיקום והמטבע נקבעים לכל הייבוא, לא מעמודה. */
const APR_COMP_IMPORT_TARGETS = ['deal_date', 'price', 'address_text', 'locality', 'gush', 'helka', 'tat_helka', 'distance_m', 'property_type', 'area', 'rooms',
    'floor', 'floors_total', 'build_year', 'condition', 'attachments', 'source_ref', 'extra'];

// ── טבלה מטקסט ומקובץ ─────────────────────────────────────────

/** מנקה טבלה גולמית: תאים קצוצים, בלי שורות ריקות, עד התקרות. */
function apr_comp_table_clean(array $rows): array
{
    $out = [];
    foreach ($rows as $r) {
        $r = array_map(fn($x) => mb_substr(trim((string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string)$x)), 0, APR_COMP_IMPORT_CELL), array_values($r));
        while ($r && end($r) === '') array_pop($r);
        if (!$r) continue;
        if (count($r) > APR_COMP_IMPORT_COLS) apr_fail('comp_import_format');
        $out[] = $r;
        if (count($out) > APR_COMP_IMPORT_MAX + 1) apr_fail('comp_import_too_many');
    }
    if (!$out) apr_fail('comp_import_empty');
    return $out;
}

/** CSV או שורות שהודבקו מטבלה. המפריד נקבע מהשורה הראשונה: טאב (הדבקה), אחרת ; או , — מה שמופיע יותר. */
function apr_comp_table_text(string $text): array
{
    if (str_starts_with($text, "\xEF\xBB\xBF")) $text = substr($text, 3);
    if ($text === '' || !mb_check_encoding($text, 'UTF-8')) apr_fail('comp_import_format');
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $first = strtok($text, "\n") ?: '';
    $delim = str_contains($first, "\t") ? "\t" : (substr_count($first, ';') > substr_count($first, ',') ? ';' : ',');
    $h = fopen('php://memory', 'w+b');
    fwrite($h, $text);
    rewind($h);
    $rows = [];
    while (($r = fgetcsv($h, null, $delim, '"', '')) !== false) {
        if ($r === [null]) continue;
        $rows[] = $r;
        if (count($rows) > APR_COMP_IMPORT_MAX + 200) break;      // שורות ריקות אינן נספרות; התקרה עצמה — ב-apr_comp_table_clean
    }
    fclose($h);
    return apr_comp_table_clean($rows);
}

/** XML של Office, בלי DTD ובלי ישויות — או comp_xlsx. */
function apr_comp_xml(?string $xml): SimpleXMLElement
{
    if ($xml === null || !function_exists('simplexml_load_string') || stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) apr_fail('comp_xlsx');
    $prev = libxml_use_internal_errors(true);
    $x = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_COMPACT);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    return $x instanceof SimpleXMLElement ? $x : apr_fail('comp_xlsx');
}

/** הגיליון הראשון של XLSX כטבלה של מחרוזות. תא מספרי מוחזר כפי שנשמר בקובץ (תאריך — כמספר סידורי; apr_comp_import_date מפענח). */
function apr_comp_table_xlsx(string $path): array
{
    $entries = apr_zip_entries($path);
    if (!$entries) apr_fail('comp_xlsx');
    $sheets = array_values(array_filter(array_keys($entries), fn($n) => preg_match('~^xl/worksheets/sheet\d+\.xml\z~', (string)$n) === 1));
    if (!$sheets) apr_fail('comp_xlsx');
    usort($sheets, fn($a, $b) => (int)preg_replace('/\D/', '', $a) <=> (int)preg_replace('/\D/', '', $b));
    $strings = [];
    if (isset($entries['xl/sharedStrings.xml'])) {
        foreach (apr_comp_xml(apr_zip_read($path, $entries['xl/sharedStrings.xml']))->si as $si) {
            $t = isset($si->t) ? (string)$si->t : '';
            foreach ($si->r as $run) $t .= (string)$run->t;
            $strings[] = $t;
        }
    }
    $rows = [];
    foreach (apr_comp_xml(apr_zip_read($path, $entries[$sheets[0]]))->sheetData->row as $row) {
        $cells = [];
        $next = 0;
        foreach ($row->c as $cell) {
            $col = $next;
            if (preg_match('/^([A-Z]{1,3})\d+\z/', (string)$cell['r'], $m) === 1) {
                $col = 0;
                foreach (str_split($m[1]) as $ch) $col = $col * 26 + (ord($ch) - 64);
                $col--;
            }
            if ($col >= APR_COMP_IMPORT_COLS) apr_fail('comp_import_format');
            $type = (string)$cell['t'];
            $cells[$col] = match ($type) {
                's' => $strings[(int)$cell->v] ?? '',
                'inlineStr' => (string)$cell->is->t,
                default => (string)$cell->v,
            };
            $next = $col + 1;
        }
        if (!$cells) continue;
        $line = array_fill(0, max(array_keys($cells)) + 1, '');
        foreach ($cells as $i => $v) $line[$i] = $v;
        $rows[] = $line;
        if (count($rows) > APR_COMP_IMPORT_MAX + 200) break;
    }
    return apr_comp_table_clean($rows);
}

// ── פענוח ערכים ───────────────────────────────────────────────

/**
 * מספר מעמודה ← מחרוזת עשרונית נקייה עם עד $scale ספרות אחרי הנקודה, או null. מסיר ₪, רווחים ומפרידי אלפים ("1,250,000").
 * מספר ארוך מדי אחרי הנקודה (כך Excel שומר 78.4: ‎78.400000000000006) מעוגל פעם אחת, בחשבון שלמים — בלי נקודה צפה.
 */
function apr_comp_import_num(string $s, int $scale, bool $negOk = false): ?string
{
    $s = (string)preg_replace('/[\s\x{00A0}\x{200E}\x{200F}₪]|ש"ח|ש״ח/u', '', $s);
    if (preg_match('/^(-?)(\d{1,3}(?:,\d{3})+|\d+)(?:\.(\d+))?\z/', $s, $m) !== 1) return null;
    if ($m[1] === '-' && !$negOk) return null;
    $int = ltrim(str_replace(',', '', $m[2]), '0');
    $frac = $m[3] ?? '';
    if (strlen($int) > 12) return null;
    $n = (int)(($int === '' ? '0' : $int) . str_pad(substr($frac, 0, $scale), $scale, '0'));
    if (strlen($frac) > $scale && (int)$frac[$scale] >= 5) $n++;
    $out = apr_comp_dec_out($n, $scale);
    return $m[1] === '-' && $n !== 0 ? '-' . $out : $out;
}

/**
 * תאריך מעמודה ← YYYY-MM-DD, או null כשאי אפשר לפענח (§10.9). $order: dmy (ברירת המחדל) או mdy — לתאריך בצורה 03/04/2025.
 * מספר סידורי של Excel (20000–80000) מפוענח כתאריך.
 */
function apr_comp_import_date(string $s, string $order = 'dmy'): ?string
{
    $s = trim($s);
    [$y, $mo, $d] = [0, 0, 0];
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[T ].*)?\z/', $s, $m) === 1) {
        [$y, $mo, $d] = [(int)$m[1], (int)$m[2], (int)$m[3]];
    } elseif (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{4}|\d{2})\z/', $s, $m) === 1) {
        [$d, $mo] = $order === 'mdy' ? [(int)$m[2], (int)$m[1]] : [(int)$m[1], (int)$m[2]];
        $y = strlen($m[3]) === 4 ? (int)$m[3] : ((int)$m[3] < 70 ? 2000 + (int)$m[3] : 1900 + (int)$m[3]);
    } elseif (preg_match('/^(\d{5})(?:\.\d+)?\z/', $s, $m) === 1 && (int)$m[1] >= 20000 && (int)$m[1] <= 80000) {
        return gmdate('Y-m-d', ((int)$m[1] - 25569) * 86400);
    }
    return $y >= 1900 && $y <= 2200 && checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
}

/** המיפוי מהקלט: {אינדקס עמודה: יעד}. יעד (חוץ מ-extra) מופיע פעם אחת לכל היותר, וצריך לפחות שדה מזהה אחד. */
function apr_comp_import_mapping(mixed $v, int $cols): array
{
    if (!is_array($v)) apr_fail('comp_import_mapping');
    $out = [];
    foreach ($v as $i => $target) {
        if ($target === null || $target === '') continue;
        if (!(is_int($i) || ctype_digit((string)$i)) || (int)$i >= $cols || !is_string($target) || !in_array($target, APR_COMP_IMPORT_TARGETS, true)) apr_fail('comp_import_mapping');
        if ($target !== 'extra' && in_array($target, $out, true)) apr_fail('comp_import_mapping');
        $out[(int)$i] = $target;
    }
    if (!array_intersect($out, ['deal_date', 'price', 'address_text', 'gush'])) apr_fail('comp_import_mapping');
    ksort($out);
    return $out;
}

/** מה שנקבע לכל הייבוא: סוג השטח של עמודת השטח, דיוק המיקום, המטבע, וסדר התאריך. */
function apr_comp_import_opts(array $in): array
{
    $d = is_array($in['defaults'] ?? null) ? $in['defaults'] : [];
    return ['area_kind' => apr_comp_enum($d['area_kind'] ?? null, APR_AREA_KINDS, 'unknown'), 'location_precision' => apr_comp_enum($d['location_precision'] ?? null, APR_COMP_PRECISIONS, 'unknown'),
        'currency' => apr_comp_enum($d['currency'] ?? null, APR_COMP_CURRENCIES, 'ILS'), 'date_order' => apr_comp_enum($in['date_order'] ?? null, ['dmy', 'mdy'], 'dmy')];
}

/** שורה אחת ← [ערכים, extra, raw, שדות שלא פוענחו]. ערך שלא פוענח ← NULL, והשדה נרשם באזהרות. */
function apr_comp_import_row(array $cells, array $headers, array $mapping, array $opt): array
{
    $raw = [];
    foreach ($headers as $i => $hd) $raw[$hd] = $cells[$i] ?? '';
    $in = ['currency' => $opt['currency'], 'location_precision' => $opt['location_precision'], 'area_kind' => $opt['area_kind']];
    $extra = [];
    $warn = [];
    foreach ($mapping as $i => $target) {
        $cell = $cells[$i] ?? '';
        if ($cell === '') continue;
        if ($target === 'extra') {
            $extra[$headers[$i]] = $cell;
            continue;
        }
        $val = match ($target) {
            'deal_date' => apr_comp_import_date($cell, $opt['date_order']),
            'price', 'area', 'rooms' => apr_comp_import_num($cell, 2),
            'distance_m', 'floors_total', 'build_year' => apr_comp_import_num($cell, 0),
            'floor' => apr_comp_import_num($cell, 0, true),
            default => mb_substr($cell, 0, APR_COMP_TEXT_MAX[$target]),
        };
        try {
            $in[$target] = $val === null ? null : apr_comp_clean_field($target, $val);
        } catch (AprError) {
            $in[$target] = null;
        }
        if ($in[$target] === null) $warn[] = $target;
    }
    $v = [];
    foreach (APR_COMP_FIELDS as $f) $v[$f] = $in[$f] ?? null;
    if (!in_array($v['location_precision'], APR_COMP_PRECISE, true)) $v['distance_m'] = null;
    if ($v['area'] === null) $v['area_kind'] = 'unknown';
    return [$v, $extra, $raw, $warn];
}

// ── הפעולות ───────────────────────────────────────────────────

/** הטבלה של הייבוא, מהקובץ (file — XLSX של התיק) או מהטקסט (text). מחזיר [שורות, שם, sha256 | null, file_id | null]. */
function apr_comp_import_table(array $ctx, array $c, array $in): array
{
    if (($in['file'] ?? null) !== null && $in['file'] !== '') {
        $f = apr_is_pid($in['file']) ? apr_row('SELECT * FROM files WHERE pid = ? AND case_id = ? AND deleted_at IS NULL', [$in['file'], $c['id']]) : null;
        if (!$f || !apr_file_visible($ctx, $c, $f)) apr_fail('not_found');
        if ($f['mime'] !== APR_MIME_XLSX) apr_fail('comp_import_type');
        return [apr_comp_table_xlsx(apr_file_path($f['storage_key'])), $f['original_name'], null, (int)$f['id']];
    }
    if (!is_string($in['text'] ?? null)) apr_fail('comp_import_empty');
    return [apr_comp_table_text($in['text']), apr_clean_text($in['name'] ?? '', 200), hash('sha256', $in['text']), null];
}

/** [כותרות, שורות הנתונים]. בלי שורת כותרת — "עמודה 1", "עמודה 2" … כותרת ריקה או כפולה מקבלת את מספר העמודה. */
function apr_comp_import_split(array $table, bool $hasHeader): array
{
    $cols = max(array_map('count', $table));
    $headers = [];
    for ($i = 0; $i < $cols; $i++) {
        $hd = $hasHeader ? ($table[0][$i] ?? '') : '';
        if ($hd === '' || in_array($hd, $headers, true)) $hd = 'עמודה ' . ($i + 1) . ($hd === '' ? '' : ' · ' . $hd);
        $headers[] = $hd;
    }
    $data = $hasHeader ? array_slice($table, 1) : $table;
    if (!$data) apr_fail('comp_import_empty');
    if (count($data) > APR_COMP_IMPORT_MAX) apr_fail('comp_import_too_many');
    return [$headers, $data];
}

function apr_comp_import_sig(array $headers): string
{
    return hash('sha256', json_encode($headers, JSON_UNESCAPED_UNICODE));
}

/** comp-import-upload — גוף גולמי (XLSX); בכתובת: case, name, key. אותו צינור קליטה של פרוסה 06; ההרשאה נבדקת לפני שהגוף נקרא. */
function apr_comp_import_upload(array $ctx, array $q, array $env): array
{
    $uid = (int)$ctx['user']['id'];
    $out = apr_file_ingest(fn() => apr_comp_case($ctx, $q['case'] ?? null, 'write'), ['kind' => 'user', 'uid' => $uid, 'channel' => 'internal', 'rate_key' => (string)$uid,
        'key' => fn() => apr_upload_key($ctx, $q['key'] ?? null), 'mimes' => [APR_MIME_XLSX], 'mimes_error' => 'comp_import_type'],
        ['requirement' => null, 'source_note' => 'ייבוא עסקאות השוואה'] + $q, $env);
    // אותו קובץ כבר בתיק — מייבאים ממנו, בלי עותק שני
    return ['file' => $out['file'] ?? $out['duplicate']['file']];
}

/**
 * comp-import-preview {case, file | text, name?, has_header?, mapping?, date_order?, defaults?} — אינה כותבת דבר.
 * מחזיר את העמודות (כותרת ודוגמאות), את המיפוי שהמשרד שמר לכותרות האלה (אם יש), ועם mapping — תצוגה מקדימה של 20 השורות
 * הראשונות כפי שייובאו, וכמה שורות יש בהן ערך שלא פוענח.
 */
function apr_comp_import_preview(array $ctx, array $in): array
{
    $c = apr_comp_case($ctx, $in['case'] ?? null, 'write');
    [$table] = apr_comp_import_table($ctx, $c, $in);
    [$headers, $data] = apr_comp_import_split($table, ($in['has_header'] ?? true) !== false);
    $saved = apr_val('SELECT mapping FROM comp_import_maps WHERE office_id = ? AND sig = ?', [$c['office_id'], apr_comp_import_sig($headers)]);
    $out = ['columns' => array_map(fn($hd, $i) => ['index' => $i, 'header' => $hd, 'samples' => array_values(array_filter(array_map(fn($r) => $r[$i] ?? '', array_slice($data, 0, 3)), fn($x) => $x !== ''))], $headers, array_keys($headers)),
        'total' => count($data), 'saved_mapping' => is_string($saved) ? (json_decode($saved, true) ?: null) : null, 'targets' => APR_COMP_IMPORT_TARGETS, 'max' => APR_COMP_IMPORT_MAX];
    if (($in['mapping'] ?? null) === null) return $out;
    $mapping = apr_comp_import_mapping($in['mapping'], count($headers));
    $opt = apr_comp_import_opts($in);
    $rows = [];
    $warnRows = 0;
    $empty = 0;
    foreach ($data as $n => $cells) {
        [$v, $extra, , $warn] = apr_comp_import_row($cells, $headers, $mapping, $opt);
        if ($warn) $warnRows++;
        $blank = !array_filter(array_diff_key($v, array_flip(['currency', 'location_precision', 'area_kind'])), fn($x) => $x !== null);
        if ($blank) $empty++;
        if ($n < 20) {
            $vals = [];
            foreach ($v as $f => $x) $vals[$f] = apr_comp_field_out($f, $x);
            $rows[] = ['line' => $n + 1, 'values' => $vals, 'extra' => (object)$extra, 'unparsed' => $warn, 'empty' => $blank];
        }
    }
    return $out + ['rows' => $rows, 'warn_rows' => $warnRows, 'empty_rows' => $empty];
}

/**
 * comp-import {case, search, file | text, name?, has_header?, mapping, date_order?, defaults?, rights: true} — מייבא את כל השורות
 * לחיפוש שנרשם. בלי rights = true אין ייבוא. חיפוש מקבל ייבוא אחד. שורה שכל הערכים הממופים בה ריקים — מדולגת, ונספרת.
 * חשד לכפילות מסומן לכל שורה (dup_of) — ושתי העסקאות נשארות.
 */
function apr_comp_import(array $ctx, array $in): array
{
    $c = apr_comp_case($ctx, $in['case'] ?? null, 'write');
    if (($in['rights'] ?? null) !== true) apr_fail('comp_import_rights');
    [$table, $name, $sha, $fileId] = apr_comp_import_table($ctx, $c, $in);
    [$headers, $data] = apr_comp_import_split($table, ($in['has_header'] ?? true) !== false);
    $mapping = apr_comp_import_mapping($in['mapping'] ?? null, count($headers));
    $opt = apr_comp_import_opts($in);
    $uid = (int)$ctx['user']['id'];
    return apr_tx(function () use ($c, $in, $headers, $data, $mapping, $opt, $name, $sha, $fileId, $uid) {
        apr_case_still_open($c);
        $s = apr_comp_search_row($c, $in['search'] ?? null);
        if ($s['file_id'] !== null || $s['import_sha256'] !== null) apr_fail('comp_import_done');
        $done = 0;
        $skipped = 0;
        $dups = 0;
        $warned = 0;
        foreach ($data as $cells) {
            [$v, $extra, $raw, $warn] = apr_comp_import_row($cells, $headers, $mapping, $opt);
            if (!array_filter(array_diff_key($v, array_flip(['currency', 'location_precision', 'area_kind'])), fn($x) => $x !== null)) {
                $skipped++;
                continue;
            }
            [, , $dup] = apr_comp_insert($c, $v, 'import', ['row' => $raw] + ($warn ? ['unparsed' => $warn] : []), (int)$s['id'], $extra, $uid);
            $done++;
            if ($dup !== null) $dups++;
            if ($warn) $warned++;
        }
        if ($done === 0) apr_fail('comp_import_empty');
        $now = apr_now();
        apr_exec('UPDATE comp_searches SET file_id = ?, import_name = ?, import_sha256 = ?, import_rows = ?, rights_by = ?, rights_at = ?, updated_at = ? WHERE id = ?',
            [$fileId, $name, $fileId === null ? $sha : null, $done, $uid, $now, $now, $s['id']]);
        apr_exec('INSERT INTO comp_import_maps (office_id, sig, mapping, updated_by, updated_at) VALUES (?, ?, ?, ?, ?)
            ON CONFLICT (office_id, sig) DO UPDATE SET mapping = excluded.mapping, updated_by = excluded.updated_by, updated_at = excluded.updated_at',
            [$c['office_id'], apr_comp_import_sig($headers), json_encode(['mapping' => (object)$mapping, 'date_order' => $opt['date_order'], 'defaults' => array_diff_key($opt, ['date_order' => 1])], JSON_UNESCAPED_UNICODE), $uid, $now]);
        apr_case_audit($c, 'comp.import', 'comp_search', $s['pid'], ['rows' => $done, 'skipped' => $skipped, 'dups' => $dups, 'unparsed_rows' => $warned, 'kind' => $fileId !== null ? 'xlsx' : 'text']);
        apr_case_touch($c);
        return ['imported' => $done, 'skipped' => $skipped, 'dups' => $dups, 'unparsed_rows' => $warned];
    });
}
