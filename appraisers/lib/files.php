<?php
/**
 * קבצים (אפיון 06 §4): הצינור שכל קובץ עובר — גודל, זיהוי סוג **לפי התוכן**, תוכן פעיל, סורק, שמירה — והגשה.
 *
 * - הקובץ נשמר ב-data/files/<2 תווים>/<32 תווים אקראיים>, בלי סיומת. השם שמסר המעלה אינו משפיע על הנתיב לעולם.
 * - data/ חסומה מהדפדפן (data/.htaccess) ומוחרגת מהפריסה. קובץ יוצא רק דרך api.php, אחרי בדיקת ההרשאה בתיק.
 * - שום קובץ אינו מורץ ואינו מוגש כ-HTML: תמיד nosniff + Content-Disposition + Content-Security-Policy: sandbox;
 *   תצוגה מקדימה (inline) רק ל-PDF ולתמונה. ZIP נשמר כמו שהוא ואינו נפתח — קוראים רק את רשימת השמות שבסופו.
 * - זיהוי תוכן פעיל הוא **סינון שמרני, לא ערובה**: PDF — סריקה של כל הקובץ ושל זרמי Flate לשמות פעילים (apr_sniff_pdf);
 *   Office — vbaProject או סוג תוכן macroEnabled. הסורק (clamscan), אם קיים בשרת, רץ בנוסף.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

const APR_FILE_MAX = 25 * 1024 * 1024;
const APR_MIME_DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
const APR_MIME_XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
/** הרשימה המותרת: mime => [סיומת, סוג לתצוגה]. */
const APR_FILE_TYPES = [
    'application/pdf' => ['pdf', 'pdf'], 'image/jpeg' => ['jpg', 'image'], 'image/png' => ['png', 'image'], 'image/webp' => ['webp', 'image'],
    'image/heic' => ['heic', 'image'], APR_MIME_DOCX => ['docx', 'office'], APR_MIME_XLSX => ['xlsx', 'office'], 'application/zip' => ['zip', 'package'],
];
/** מה מותר להציג בתוך הדף. כל השאר — הורדה בלבד. */
const APR_FILE_PREVIEW = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];
const APR_FILE_THUMB = ['image/jpeg', 'image/png', 'image/webp'];

/** קובץ שנדחה בצינור. $why נרשם ביומן התיק יחד עם ה-hash; הקובץ עצמו אינו נשמר. */
class AprFileRejected extends AprError
{
    public function __construct(string $code, public readonly string $why)
    {
        parent::__construct($code);
    }
}

function apr_ini_bytes(string $v): int
{
    $v = trim($v);
    $n = (int)$v;
    return match (strtolower(substr($v, -1))) { 'g' => $n << 30, 'm' => $n << 20, 'k' => $n << 10, default => $n };
}

/** post_max_size של השרת, בבתים (0 = בלי הגבלה). לאפליקציה אין .user.ini משלה — השער של הפלטפורמה יוצר אותו — ולכן זה מה שהשרת נותן. */
function apr_post_max(): int
{
    return (int)($GLOBALS['apr_post_max'] ?? apr_ini_bytes((string)ini_get('post_max_size')));
}

/**
 * הגודל המרבי לקובץ: 25MB, או פחות — post_max_size של השרת פחות מרווח של 64KB. גוף שעובר את post_max_size נזרק בידי PHP
 * לפני שהקוד רץ, ולכן התקרה שלנו חייבת להיות מתחתיו: כך הדחייה היא 413 ברור, והמסך מציג את התקרה האמיתית.
 */
function apr_file_max(): int
{
    if (isset($GLOBALS['apr_file_max'])) return (int)$GLOBALS['apr_file_max'];
    $post = apr_post_max();
    return $post > 0 ? max(1, min(APR_FILE_MAX, $post - 65536)) : APR_FILE_MAX;
}

function apr_files_dir(): string
{
    return apr_data_dir() . '/files';
}

/** תיקייה לקבצים זמניים של העלאה — באותה מערכת קבצים, כדי שהשמירה תהיה rename. */
function apr_upload_tmp_dir(): string
{
    $d = apr_data_dir() . '/tmp';
    if (!is_dir($d)) @mkdir($d, 0700, true);
    return $d;
}

/** הנתיב בדיסק של מפתח אחסון. המפתח נוצר רק בשרת; כל צורה אחרת — לא נמצא. */
function apr_file_path(string $key, string $base = 'files'): string
{
    if (preg_match('~^[a-f0-9]{2}/[a-f0-9]{32}\z~', $key) !== 1) apr_fail('not_found');
    return apr_data_dir() . "/$base/$key";
}

/** מעביר קובץ זמני לאחסון, תחת מפתח אקראי. מחזיר את המפתח. */
function apr_file_store(string $tmp, string $base = 'files'): string
{
    $key = bin2hex(random_bytes(16));
    $key = substr($key, 0, 2) . '/' . $key;
    $path = apr_file_path($key, $base);
    if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0700, true) && !is_dir(dirname($path))) apr_fail('storage_full');
    if (!@rename($tmp, $path) && !(@copy($tmp, $path) && @unlink($tmp))) {
        @unlink($path);
        apr_fail('storage_full');
    }
    @chmod($path, 0600);
    return $key;
}

/** שם להצגה: בלי נתיב, בלי תווי בקרה ובלי תווי כיוון נסתרים, עד 150 תווים. אינו משמש לאחסון. */
function apr_file_clean_name(mixed $v): string
{
    $s = is_string($v) ? $v : '';
    if (!mb_check_encoding($s, 'UTF-8')) $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
    $s = (string)preg_replace('/[\x00-\x1F\x7F\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $s);
    $s = (string)preg_replace('~^.*[/\\\\]~su', '', $s);
    $s = trim(mb_substr(trim($s, " .\t"), 0, 150));
    return $s === '' ? 'קובץ' : $s;
}

/** שם ההורדה: השם להצגה, עם הסיומת **שלנו** (מהסוג שזוהה) במקום זו שמסר המעלה. */
function apr_file_download_name(string $name, string $ext): string
{
    $base = (string)preg_replace('/\.[A-Za-z0-9]{1,8}\z/', '', $name);
    return ($base === '' ? 'file' : $base) . '.' . $ext;
}

// ── זיהוי סוג לפי התוכן ───────────────────────────────────────

/**
 * מזהה את הסוג מהתוכן ובודק תוכן פעיל. מחזיר ['mime', 'encrypted', 'pages'] או זורק AprFileRejected.
 * הסיומת והסוג שמסר המעלה אינם נקראים כאן כלל.
 */
function apr_file_sniff(string $path): array
{
    $head = (string)file_get_contents($path, false, null, 0, 64);
    $out = ['encrypted' => false, 'pages' => null];
    if (str_starts_with($head, '%PDF-')) {
        $out = apr_sniff_pdf($path) + ['mime' => 'application/pdf'];
    } elseif (str_starts_with($head, "\xFF\xD8\xFF")) {
        $out['mime'] = apr_sniff_image($path, IMAGETYPE_JPEG, 'image/jpeg');
    } elseif (str_starts_with($head, "\x89PNG\r\n\x1a\n")) {
        $out['mime'] = apr_sniff_image($path, IMAGETYPE_PNG, 'image/png');
    } elseif (str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP') {
        $out['mime'] = apr_sniff_image($path, IMAGETYPE_WEBP, 'image/webp');
    } elseif (substr($head, 4, 4) === 'ftyp' && in_array(substr($head, 8, 4), ['heic', 'heix', 'hevc', 'hevx', 'heim', 'heis', 'hevm', 'hevs', 'mif1', 'msf1', 'heif'], true)) {
        $out['mime'] = 'image/heic';
    } elseif (str_starts_with($head, "PK\x03\x04")) {
        $out['mime'] = apr_sniff_zip($path);
    } else {
        throw new AprFileRejected('file_type', 'unknown');
    }
    apr_sniff_crosscheck($path, $out['mime']);
    return $out;
}

/** דעה שנייה (finfo): קובץ שהחתימה שלו אומרת דבר אחד ו-finfo מזהה בו סוג אחר לגמרי — נדחה. */
function apr_sniff_crosscheck(string $path, string $mime): void
{
    if (!class_exists('finfo')) return;
    $seen = (string)@(new finfo(FILEINFO_MIME_TYPE))->file($path);
    $ok = match ($mime) {
        'application/pdf' => ['application/pdf'],
        'image/jpeg', 'image/png', 'image/webp' => [$mime],
        'image/heic' => ['image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence', 'video/mp4', 'application/octet-stream'],
        default => ['application/zip', 'application/octet-stream', 'application/x-zip-compressed', APR_MIME_DOCX, APR_MIME_XLSX],
    };
    if ($seen !== '' && !in_array($seen, $ok, true)) throw new AprFileRejected('file_mismatch', 'finfo:' . substr($seen, 0, 60));
}

function apr_sniff_image(string $path, int $type, string $mime): string
{
    $i = @getimagesize($path);
    if (!$i || $i[2] !== $type || $i[0] < 1 || $i[1] < 1) throw new AprFileRejected('file_mismatch', 'image');
    return $mime;
}

/** שמות ב-PDF שמעידים על תוכן פעיל או על קידוד שאיננו בודקים. שם ארוך — מספיק שיופיע; שם קצר — רק עם ערך אחריו. */
const APR_PDF_GAP = '(?:\s|%[^\r\n]*[\r\n])*+';   // רווחים והערות בין שם לערך; possessive — בלי נסיגה
const APR_PDF_ACTIVE = [
    '~/(JavaScript|EmbeddedFiles?|Launch|SubmitForm|ImportData|XFA|RichMedia|ASCIIHexDecode|ASCII85Decode|LZWDecode|RunLengthDecode|AHx|A85|LZW)(?![A-Za-z0-9])~',
    '~/(JS|AA)' . APR_PDF_GAP . '(?:\(|<|\d+\s+\d+\s+R)~',
    '~/(OpenAction)' . APR_PDF_GAP . '(?!\[)~',       // יעד פתיחה (מערך) מותר; פעולה או הפניה — לא
    // מערך פילטרים עם Flate פעמיים: שכבה שנייה שאיננו פורסים
    '~\[[^\]]{0,300}?/(?:FlateDecode|Fl)(?![A-Za-z0-9])[^\]]{0,300}?/(FlateDecode|Fl)(?![A-Za-z0-9])~',
];

/** סורק טקסט לשמות הפעילים, אחרי פענוח #xx (‎/J#53 = /JS). מחזיר את השם שנמצא, או null. כשל של ה-regex = נמצא. */
function apr_pdf_active(string $text): ?string
{
    if (str_contains($text, '#')) $text = (string)preg_replace_callback('/#([0-9A-Fa-f]{2})/', fn($m) => chr((int)hexdec($m[1])), $text);
    foreach (APR_PDF_ACTIVE as $re) {
        $n = preg_match($re, $text, $m);
        if ($n === false) return 'scan';
        if ($n === 1) return $m[1];
    }
    return null;
}

/** תקרת העבודה על זרמי Flate ב-PDF אחד: בתים שנקראו ונפרסו (ועוד 1,024 לזרם), ושניות. מעבר לה — הקובץ נדחה. */
const APR_PDF_BUDGET = 800_000_000;
const APR_PDF_SECONDS = 12.0;

/**
 * הזנב שעובר מחלון סריקה לחלון הבא, כדי ששם שנחתך בגבול החלון לא יחמוק: 4KB אחרונים, או — אם החלון מסתיים בשם שאחריו
 * רק רווחים והערות — מאותו שם. "רווח" ארוך מ-1MB אחרי שם אינו PDF סביר — נדחה.
 */
function apr_pdf_tail(string $win): string
{
    $keep = min(strlen($win), 4096);
    // גם הערה שטרם נסגרה, וגם הפניה שנחתכה באמצע ("/JS   12 0" — ה-R בחלון הבא)
    $n = preg_match('~/[A-Za-z0-9#]{1,40}' . APR_PDF_GAP . '(?:\d{1,10}\s*+(?:\d{1,10}\s*+)?)?(?:%[^\r\n]*+)?\z~', $win, $m);
    if ($n === false) throw new AprFileRejected('file_unscannable', 'pdf:scan');
    if ($n === 1) $keep = max($keep, strlen($m[0]));
    if ($keep > 1 << 20) throw new AprFileRejected('file_unscannable', 'pdf:gap');
    return substr($win, -$keep);
}

/** כותרת zlib תקינה במקום $at: שיטת deflate, חלון עד 32KB, ובדיקת ה-FCHECK. חלון קטן מ-15 נותן בית ראשון שאינו 0x78. */
function apr_zlib_head(string $data, int $at): bool
{
    if (!isset($data[$at + 1])) return false;
    $cmf = ord($data[$at]);
    return ($cmf & 0x0F) === 8 && ($cmf >> 4) <= 7 && (($cmf << 8) | ord($data[$at + 1])) % 31 === 0;
}

/** האם התחילית הזו באמת נפרסת כ-zlib (ולא רק נראית כך במקרה): נפרס ממנה פלט בלי שגיאה. */
function apr_zlib_inflates(string $head): bool
{
    $z = inflate_init(ZLIB_ENCODING_DEFLATE);
    $out = $z ? @inflate_add($z, $head) : false;
    return $out !== false && $out !== '';
}

/**
 * PDF: חייב להסתיים כ-PDF (%%EOF), ובלי שמות של תוכן פעיל. **סינון שמרני, לא ניתוח מבנה ולא ערובה:** סורקים את כל הקובץ
 * הגולמי — מילונים, מחרוזות, הערות וזרמים שאינם דחוסים — ואת כל מה שנפרס מזרמי Flate (בחלונות, עם זנב חופף). תקרת נפח וזמן: מי שחורג ממנה נדחה (pdf:budget) — לא מתקבל בלי סריקה. התאמה = דחייה,
 * גם אם ה-PDF תמים. זרם בקידוד שאיננו פורסים (ASCIIHex, ASCII85, LZW, RunLength) — נדחה. ההגנה האמיתית היא ההגשה:
 * attachment, nosniff ו-sandbox. הכול במעבר אחד: כל בית נקרא מספר קבוע של פעמים.
 * PDF מוצפן נשמר ומסומן — את תוכנו אי אפשר לבדוק.
 */
function apr_sniff_pdf(string $path): array
{
    $data = (string)file_get_contents($path);
    if (strrpos($data, '%%EOF') === false) throw new AprFileRejected('file_mismatch', 'pdf-truncated');
    $hit = apr_pdf_active($data);
    if ($hit !== null) throw new AprFileRejected('file_active', 'pdf:' . $hit);
    $pages = (int)preg_match_all('~/Type\s*/Page(?![A-Za-z0-9])~', $data);
    // תקציב: כל בית שנקרא או נפרס מזרם, ועוד קבוע קטן לזרם. נגמר התקציב או הזמן ונשארו זרמים — הקובץ נדחה, לא מתקבל בשקט.
    $budget = $GLOBALS['apr_pdf_budget'] ?? APR_PDF_BUDGET;
    $deadline = microtime(true) + APR_PDF_SECONDS;
    $len = strlen($data);
    for ($pos = 0; ($s = strpos($data, 'stream', $pos)) !== false; $pos = $b) {
        $b = $s + 6;
        $b += strspn($data, " \t", $b);                     // קוראי PDF סובלים רווחים אחרי המילה stream
        if (($data[$b] ?? '') === "\r") $b++;
        if (($data[$b] ?? '') === "\n") $b++;
        if (!apr_zlib_head($data, $b)) continue;            // לא zlib (כל גודל חלון, לא רק 0x78)
        if ($budget <= 0 || microtime(true) > $deadline) throw new AprFileRejected('file_unscannable', 'pdf:budget');
        $z = inflate_init(ZLIB_ENCODING_DEFLATE);
        $budget -= 1024;
        $win = '';                                         // חלון הסריקה: מה שנפרס וטרם נסרק, ועוד הזנב של החלון הקודם
        $first = true;
        // צעדים של עד 4KB: נתח קלט אחד נפרס לכל היותר לכ-4MB, כך ש"פצצת דחיסה" אינה ממלאת את הזיכרון לפני שהתקציב עוצר אותה
        for ($o = $b, $step = 512; $z && $o < $len; $o += $step, $step = min($step * 2, 4096)) {
            $in = substr($data, $o, $step);
            $chunk = @inflate_add($z, $in);
            $budget -= strlen($in);
            if ($chunk === false) break;
            $budget -= strlen($chunk);
            if ($first && $chunk !== '') {
                // Flate בתוך Flate: זרם שהפלט שלו הוא בעצמו zlib תקין — קידוד שאיננו בודקים, כמו ASCIIHex. נדחה, לא נסרק "בערך".
                if (apr_zlib_head($chunk, 0) && apr_zlib_inflates(substr($chunk, 0, 4096))) throw new AprFileRejected('file_unscannable', 'pdf:nested');
                $first = false;
            }
            $win .= $chunk;
            $end = inflate_get_status($z) === ZLIB_STREAM_END;
            if ($end) $budget += strlen($in) - max(0, inflate_get_read_len($z) - ($o - $b));   // הזרם נגמר באמצע הנתח: מחייבים רק מה שנקרא
            if ($end || strlen($win) >= 1 << 20) {
                $hit = apr_pdf_active($win);
                if ($hit !== null) throw new AprFileRejected('file_active', 'pdf:' . $hit);
                $pages += (int)preg_match_all('~/Type\s*/Page(?![A-Za-z0-9])~', $win);
                $win = $end ? '' : apr_pdf_tail($win);
            }
            if ($end) break;
            if ($budget <= 0 || microtime(true) > $deadline) throw new AprFileRejected('file_unscannable', 'pdf:budget');
        }
        if ($win !== '') {                                 // זרם שנקטע באמצע: מה שנפרס ממנו נסרק
            $hit = apr_pdf_active($win);
            if ($hit !== null) throw new AprFileRejected('file_active', 'pdf:' . $hit);
            $pages += (int)preg_match_all('~/Type\s*/Page(?![A-Za-z0-9])~', $win);
        }
    }
    $encrypted = preg_match('~/Encrypt(?![A-Za-z0-9])~', $data) === 1;
    return ['encrypted' => $encrypted, 'pages' => $pages > 0 && !$encrypted ? $pages : null];
}

/**
 * רשימת השמות שב-ZIP, מהספרייה המרכזית שבסוף הקובץ — בלי לפרוס דבר. name => [method, csize, offset].
 * null — אין ספרייה תקינה. ZIP64 (מעל 4GB / 65535 רשומות) — רשימה ריקה: נשמר כחבילה, בלי זיהוי Office.
 */
function apr_zip_entries(string $path): ?array
{
    $size = (int)filesize($path);
    $tail = (string)file_get_contents($path, false, null, max(0, $size - 66000));
    $p = strrpos($tail, "PK\x05\x06");
    if ($p === false || strlen($tail) - $p < 22) return null;
    $eocd = unpack('vdisk/vcddisk/vhere/vtotal/Vsize/Voffset', substr($tail, $p + 4, 16));
    if ($eocd['offset'] === 0xFFFFFFFF || $eocd['total'] === 0xFFFF) return [];
    if ($eocd['offset'] + $eocd['size'] > $size || $eocd['size'] > 8_000_000) return null;
    $cd = (string)file_get_contents($path, false, null, $eocd['offset'], $eocd['size']);
    $out = [];
    for ($i = 0, $n = 0; $n < $eocd['total']; $n++) {
        if (substr($cd, $i, 4) !== "PK\x01\x02" || strlen($cd) - $i < 46) return null;
        $h = unpack('vmethod', substr($cd, $i + 10, 2)) + unpack('Vcsize', substr($cd, $i + 20, 4)) + unpack('vname/vextra/vcomment', substr($cd, $i + 28, 6)) + unpack('Voffset', substr($cd, $i + 42, 4));
        $out[substr($cd, $i + 46, $h['name'])] = [$h['method'], $h['csize'], $h['offset']];
        $i += 46 + $h['name'] + $h['extra'] + $h['comment'];
    }
    return $out;
}

/** תוכן של רשומה אחת קטנה מתוך ZIP (עד 1MB דחוס, 4MB פרוס) — בשביל ‎[Content_Types].xml בלבד. */
function apr_zip_read(string $path, array $entry): ?string
{
    [$method, $csize, $offset] = $entry;
    if ($csize > 1_000_000) return null;
    $lh = (string)file_get_contents($path, false, null, $offset, 30);
    if (strlen($lh) < 30 || !str_starts_with($lh, "PK\x03\x04")) return null;
    $l = unpack('vname/vextra', substr($lh, 26, 4));
    $raw = (string)file_get_contents($path, false, null, $offset + 30 + $l['name'] + $l['extra'], $csize);
    if ($method === 0) return $raw;
    $inf = $method === 8 ? @gzinflate($raw, 4_000_000) : false;
    return is_string($inf) ? $inf : null;
}

/** ZIP: חבילה רגילה (נשמרת כמו שהיא), או מסמך Office — רק DOCX ו-XLSX, ובלי מאקרו. */
function apr_sniff_zip(string $path): string
{
    $entries = apr_zip_entries($path);
    if ($entries === null) throw new AprFileRejected('file_mismatch', 'zip');
    if (!isset($entries['[Content_Types].xml'])) return 'application/zip';
    foreach (array_keys($entries) as $name) {
        if (preg_match('~(^|/)(vbaProject\.bin|vbaData\.xml)\z~i', (string)$name)) throw new AprFileRejected('file_active', 'office:macro');
    }
    $types = apr_zip_read($path, $entries['[Content_Types].xml']);
    if ($types === null) throw new AprFileRejected('file_mismatch', 'office:types');
    if (stripos($types, 'macroEnabled') !== false || stripos($types, 'vbaProject') !== false) throw new AprFileRejected('file_active', 'office:macro');
    if (isset($entries['word/document.xml'])) return APR_MIME_DOCX;
    if (isset($entries['xl/workbook.xml'])) return APR_MIME_XLSX;
    throw new AprFileRejected('file_type', 'office:other');
}

// ── סורק ──────────────────────────────────────────────────────

/** הנתיב של סורק שזמין בשרת, או null. clamdscan (מהיר) לפני clamscan. */
function apr_scanner_bin(): ?string
{
    if (array_key_exists('apr_scanner_bin', $GLOBALS)) return $GLOBALS['apr_scanner_bin'];
    if (!function_exists('exec') || in_array('exec', array_map('trim', explode(',', (string)ini_get('disable_functions'))), true)) return null;
    foreach (['clamdscan', 'clamscan'] as $name) {
        foreach (['/usr/bin', '/usr/local/bin', '/usr/local/cpanel/3rdparty/bin'] as $dir) {
            if (@is_executable("$dir/$name")) return "$dir/$name";
        }
    }
    return null;
}

/** clean / rejected / not_scanned. בלי סורק — not_scanned, ושום מסך אינו כותב "נסרק". */
function apr_file_scan(string $path): string
{
    if (isset($GLOBALS['apr_scanner'])) return ($GLOBALS['apr_scanner'])($path);   // בדיקות
    $bin = apr_scanner_bin();
    if ($bin === null) return 'not_scanned';
    @exec(escapeshellarg($bin) . ' --no-summary ' . escapeshellarg($path) . ' 2>/dev/null', $o, $code);
    return $code === 0 ? 'clean' : ($code === 1 ? 'rejected' : 'not_scanned');
}

// ── הגשה ──────────────────────────────────────────────────────

/**
 * התשובה להגשת קובץ: ['_file' => ['path', 'headers']] — api.php שולח את הכותרות ואת התוכן. הבדיקות קוראות את הכותרות.
 * $inline — רק לסוג שמותר להציג; כל השאר יורד כקובץ. תמיד nosniff ו-sandbox: גם אם הדפדפן יפתח את התשובה כדף,
 * שום סקריפט לא ירוץ בה.
 */
function apr_file_response(string $path, string $mime, string $name, bool $inline): array
{
    if (!is_file($path)) apr_fail('not_found');
    $inline = $inline && in_array($mime, APR_FILE_PREVIEW, true);
    $ascii = (string)preg_replace('/[^A-Za-z0-9._-]+/', '_', $name);
    if (preg_match('/[A-Za-z0-9]/', pathinfo($ascii, PATHINFO_FILENAME)) !== 1) $ascii = 'file.' . pathinfo($ascii, PATHINFO_EXTENSION);   // שם עברי: file.pdf ולא _.pdf
    return ['_file' => ['path' => $path, 'headers' => [
        'Content-Type' => $mime,
        'Content-Length' => (string)filesize($path),
        'Content-Disposition' => ($inline ? 'inline' : 'attachment') . '; filename="' . (trim($ascii, '_.') === '' ? 'file' : $ascii) . '"; filename*=UTF-8\'\'' . rawurlencode($name),
        'X-Content-Type-Options' => 'nosniff',
        'Content-Security-Policy' => "sandbox; default-src 'none'; style-src 'unsafe-inline'",
        'Cache-Control' => 'private, no-store',
    ]]];
}

// ── תמונה ממוזערת ─────────────────────────────────────────────

/** יוצר JPEG ממוזער (עד 320px) לתמונה. null — אי אפשר (אין GD, סוג אחר, או תמונה מעל 16 מגה-פיקסל). נוצר פעם אחת ונשמר (file_thumbs). */
function apr_thumb_make(string $src, string $mime): ?array
{
    if (!function_exists('imagecreatetruecolor') || !in_array($mime, APR_FILE_THUMB, true)) return null;
    $i = @getimagesize($src);
    if (!$i || $i[0] * $i[1] > 16_000_000) return null;   // GD מחזיק 4–5 בתים לפיקסל, מחוץ ל-memory_limit
    $im = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($src),
        'image/png' => @imagecreatefrompng($src),
        default => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($src) : false,
    };
    if (!$im) return null;
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $deg = match ((int)(@exif_read_data($src)['Orientation'] ?? 1)) { 3 => 180, 6 => 270, 8 => 90, default => 0 };
        if ($deg) $im = imagerotate($im, $deg, 0) ?: $im;
    }
    [$w, $h] = [imagesx($im), imagesy($im)];
    $k = min(1, 320 / max($w, $h));
    [$tw, $th] = [max(1, (int)round($w * $k)), max(1, (int)round($h * $k))];
    $t = imagecreatetruecolor($tw, $th);
    imagefill($t, 0, 0, imagecolorallocate($t, 255, 255, 255));
    imagecopyresampled($t, $im, 0, 0, 0, 0, $tw, $th, $w, $h);
    $tmp = tempnam(apr_upload_tmp_dir(), 'th');
    if ($tmp === false || !imagejpeg($t, $tmp, 78)) return null;
    return ['key' => apr_file_store($tmp, 'thumbs'), 'w' => $tw, 'h' => $th];
}
