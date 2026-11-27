<?php
/**
 * פרוסה 18 — ייצוג הביניים של מסמך הדוח (docs/spec/18-export-delivery.md §4.1). **שני הקבצים נכתבים ממנו**: lib/export_docx.php
 * ו-lib/export_pdf.php מקבלים את אותו מערך, ולכן התוכן זהה בשניהם. ה-PDF אינו מופק מה-DOCX, וה-DOCX אינו מופק מה-PDF.
 *
 * - **גרסה שאושרה נקראת מה-snapshot בלבד** (17-C6, 18-A4): הפרקים, מי אישר, מספר התיק, שם המשרד והעיצוב שלו — כולם כפי שנשמרו באישור.
 *   שום ערך של הרגע אינו נכנס למסמך, ולכן הפקה חוזרת נותנת אותו תוכן. טיוטה — מהערכים של הרגע.
 * - **הכול טקסט.** אין HTML ואין סימון בבלוקים; כל כותב מקודד בעצמו (XML / HTML) את כל מה שהוא מקבל מכאן. תווי בקרה ותווי היפוך
 *   כיוון נסתרים מוסרים כאן, פעם אחת, לשני הקבצים.
 * - **תמונות — רק מקובצי התיק**: בלוק image מפנה לתמונת ביקור של התיק; הקובץ נקרא מהאחסון, מוקטן ומקודד מחדש ל-JPEG. שום נתיב
 *   ושום כתובת מתוך הטקסט אינם נקראים.
 * - סימוני המצב (§4.4) הם חלק מהמסמך: 'banner' (כותרת כל עמוד), 'watermark', 'stamp' (העמוד הראשון), 'footer', 'fingerprint' (העמוד האחרון).
 *   **אין "חתום" בשום מצב.**
 */

declare(strict_types=1);

const APR_EXPORT_FORMATS = ['docx', 'pdf'];
const APR_EXPORT_IMAGE_MAX = 1600;                 // הצלע הארוכה של תמונה בקובץ, בפיקסלים (מקרה קצה 3)
const APR_EXPORT_IMAGE_PIXELS = 40_000_000;        // מעל זה GD אינו מפענח בזיכרון סביר — "התמונה לא זמינה"
const APR_EXPORT_DRAFT_MARK = 'טיוטה — לא מאושר';
const APR_EXPORT_SUPERSEDED_MARK = 'גרסה שהוחלפה';
const APR_EXPORT_AI_MARK = 'נוסח בבינה — טרם נסקר';
const APR_EXPORT_STATE_LABELS = ['draft' => APR_EXPORT_DRAFT_MARK, 'in_review' => 'בבדיקה — לא מאושר', 'approved' => 'אושר', 'delivered' => 'אושר', 'superseded' => 'הוחלפה'];
const APR_EXPORT_BRANDING_KEYS = ['header_text' => 200, 'footer_text' => 200, 'appraiser_details' => 600];

/** טקסט נקי לקובץ: בלי תווי בקרה (XML אינו מתיר אותם), ובלי תווי הטמעה / היפוך / בידוד של כיוון (מסתירים סדר אחר ממה שנראה). */
function apr_export_text(mixed $v): string
{
    $s = is_scalar($v) ? (string)$v : '';
    if (!mb_check_encoding($s, 'UTF-8')) $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
    return trim((string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{80}-\x{9F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FFFE}\x{FFFF}]/u', '', $s));
}

/**
 * תא של טבלה או ערך של נתון: טקסט, ועוד נטרול של תא שנראה כמו נוסחה (`=`, `@`, או `+` / `-` שאחריהם אינו מספר) — מי שמעתיק טבלה
 * מהדוח לגיליון לא יריץ נוסחה שהגיעה ממסמך או ממשתמש. מספר שלילי או חיובי ("-5%", "-50,000 ₪") נשאר כמו שהוא.
 */
function apr_export_cell(mixed $v): string
{
    $s = apr_export_text($v);
    if ($s === '' || !str_contains('=+-@', $s[0])) return $s;
    if (($s[0] === '+' || $s[0] === '-') && preg_match('/^[+-]\s?\d[\d.,]*\s*(%|₪|\$|€)?(\s[^=+@|!()]*)?\z/u', $s) === 1) return $s;
    return "'" . $s;
}

/** העיצוב של המשרד (§3 office_branding), מנוקה: כותרת, תחתית, פרטי השמאי, והלוגו ({key, sha256} | null). */
function apr_export_branding(int $officeId): array
{
    $s = json_decode((string)apr_val('SELECT settings FROM offices WHERE id = ?', [$officeId]), true) ?: [];
    $b = is_array($s['branding'] ?? null) ? $s['branding'] : [];
    $out = [];
    foreach (APR_EXPORT_BRANDING_KEYS as $k => $max) $out[$k] = is_string($b[$k] ?? null) ? mb_substr($b[$k], 0, $max) : '';
    $logo = $b['logo'] ?? null;
    $out['logo'] = is_array($logo) && is_string($logo['key'] ?? null) && preg_match('~^[a-f0-9]{2}/[a-f0-9]{32}\z~', $logo['key']) === 1
        ? ['key' => $logo['key'], 'sha256' => (string)($logo['sha256'] ?? '')] : null;
    return $out;
}

/** מה שנכנס ל-snapshot באישור, לצד הפרקים: מספר התיק, שם המשרד והעיצוב — כדי שהפקה מגרסה שאושרה לא תקרא שום ערך של הרגע. */
function apr_export_snapshot_office(array $c): array
{
    return ['name' => (string)apr_val('SELECT name FROM offices WHERE id = ?', [$c['office_id']]), 'branding' => apr_export_branding((int)$c['office_id'])];
}

/** תאריך לקריאה, בשעון ישראל. */
function apr_export_date(?string $iso): string
{
    if ($iso === null || $iso === '') return '';
    try {
        return (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone(APR_CASE_TZ))->format('d.m.Y');
    } catch (Throwable) {
        return '';
    }
}

/**
 * תמונה של התיק ← JPEG מוקטן (עד 1,600 פיקסלים), או null כשאי אפשר (קובץ חסר, סוג ש-GD אינו מפענח — HEIC —, תמונה ענקית).
 * $filePid — ה-pid של הקובץ ב-files, **בתוך התיק הזה בלבד**. הקידוד מחדש מוחק כל מה שאינו פיקסלים.
 */
function apr_export_image(array $c, mixed $filePid): ?array
{
    if (!apr_is_pid($filePid) || !function_exists('imagecreatetruecolor')) return null;
    // רק קובץ שהוא תמונת ביקור של התיק — לא כל קובץ של התיק, וודאי לא קובץ של תיק אחר
    $f = apr_row('SELECT f.storage_key, f.mime FROM files f JOIN visit_photos p ON p.file_id = f.id JOIN visits v ON v.id = p.visit_id
        WHERE f.pid = ? AND f.case_id = ? AND v.case_id = ? AND f.deleted_at IS NULL', [$filePid, $c['id'], $c['id']]);
    if (!$f) return null;
    try {
        $path = apr_file_path($f['storage_key']);
    } catch (Throwable) {
        return null;
    }
    $i = is_file($path) ? @getimagesize($path) : false;
    if (!$i || $i[0] < 1 || $i[1] < 1 || $i[0] * $i[1] > APR_EXPORT_IMAGE_PIXELS) return null;
    $im = match ($i[2]) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
        IMAGETYPE_PNG => @imagecreatefrompng($path),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
        default => false,
    };
    if (!$im) return null;
    if ($i[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $deg = match ((int)(@exif_read_data($path)['Orientation'] ?? 1)) { 3 => 180, 6 => 270, 8 => 90, default => 0 };
        if ($deg) $im = imagerotate($im, $deg, 0) ?: $im;
    }
    [$w, $h] = [imagesx($im), imagesy($im)];
    $k = min(1, APR_EXPORT_IMAGE_MAX / max($w, $h));
    [$tw, $th] = [max(1, (int)round($w * $k)), max(1, (int)round($h * $k))];
    $t = imagecreatetruecolor($tw, $th);
    imagefill($t, 0, 0, imagecolorallocate($t, 255, 255, 255));          // שקיפות ← רקע לבן
    imagecopyresampled($t, $im, 0, 0, 0, 0, $tw, $th, $w, $h);
    ob_start();
    $ok = imagejpeg($t, null, 82);
    $data = (string)ob_get_clean();
    return $ok && $data !== '' ? ['data' => $data, 'w' => $tw, 'h' => $th] : null;
}

/** הלוגו של המשרד, כפי שנשמר (PNG שקודד מחדש בהעלאה): ['data', 'w', 'h'] או null. */
function apr_export_logo(?array $logo): ?array
{
    if ($logo === null) return null;
    $path = apr_data_dir() . '/branding/' . $logo['key'];
    $data = is_file($path) ? (string)file_get_contents($path) : '';
    if ($data === '' || ($logo['sha256'] !== '' && !hash_equals($logo['sha256'], hash('sha256', $data)))) return null;
    $i = @getimagesizefromstring($data);
    return $i && $i[2] === IMAGETYPE_PNG ? ['data' => $data, 'w' => $i[0], 'h' => $i[1]] : null;
}

/**
 * המסמך של גרסה: ['meta' => …, 'blocks' => […], 'images' => [id => ['data', 'w', 'h']], 'content_hash' => …].
 * $c — שורת התיק (id, pid, number, office_id …); $v — שורת הגרסה כולה. בלי הקשר של משתמש: מי רשאי להפיק מוכרע לפני כן (lib/export.php).
 *
 * blocks (לפי הסדר): title · stamp · details · toc · h (level 1 = פרק, 2 = כותרת בתוך פרק) · p (mark? — סימון בינה שלא נסקרה) · kv (נתון)
 * · missing · list · table (head | null, rows, caption | null) · image (id, caption) · image_missing (caption) · fingerprint.
 */
function apr_export_doc(array $c, array $v): array
{
    $final = in_array($v['state'], APR_REPORT_FINAL, true);
    $c += ['perms' => [], 'my_role' => 'system', 'share' => null];
    if ($final) {
        $snap = json_decode((string)$v['snapshot'], true) ?: [];
        $sections = $snap['sections'] ?? [];
        $office = is_array($snap['office'] ?? null) ? $snap['office'] : apr_export_snapshot_office($c);       // גרסה שאושרה לפני 0.18: אין בצילום משרד
        $caseNo = is_string($snap['case']['number'] ?? null) ? $snap['case']['number'] : (string)$c['number'];
        $approvedBy = is_string($snap['approved_by'] ?? null) ? $snap['approved_by'] : '';
    } else {
        $sections = array_map(fn($s) => ['key' => $s['key'], 'title' => $s['title'], 'origin' => $s['origin'], 'ai_reviewed' => (int)$s['ai_reviewed'] === 1, 'blocks' => $s['view']],
            apr_report_sections(['user' => null, 'office' => null], $c, $v));
        $office = apr_export_snapshot_office($c);
        $caseNo = (string)$c['number'];
        $approvedBy = '';
    }
    $brand = (is_array($office['branding'] ?? null) ? $office['branding'] : []) + ['header_text' => '', 'footer_text' => '', 'appraiser_details' => '', 'logo' => null];
    $caseNo = apr_export_text($caseNo);
    $number = (int)$v['number'];
    $officeName = apr_export_text($office['name'] ?? '');

    $blocks = [];
    $images = [];
    $title = 'דוח שמאות — תיק ' . $caseNo;
    $blocks[] = ['t' => 'title', 'text' => $title, 'sub' => 'גרסה ' . $number];
    // העמוד הראשון (§4.4): מאושרת — מי אישר ומתי; "הוחלפה" — גם זה, מתחת לסימון שבכותרת כל עמוד. **לא "חתום"**
    $banner = null;
    $newer = null;
    if ($v['state'] === 'superseded') {
        $newer = (int)apr_val("SELECT COALESCE(MAX(number), 0) FROM report_versions WHERE report_id = ? AND number > ? AND deleted_at IS NULL AND state IN ('approved', 'delivered', 'superseded')", [$v['report_id'], $number]);
        $banner = APR_EXPORT_SUPERSEDED_MARK . ($newer > 0 ? ' — קיימת גרסה ' . $newer : '');
    } elseif (!$final) {
        $banner = APR_EXPORT_DRAFT_MARK;
    }
    if ($final) $blocks[] = ['t' => 'stamp', 'text' => 'אושר בידי ' . apr_export_text($approvedBy) . ', ' . apr_export_date($v['approved_at'])];
    if ($banner !== null) $blocks[] = ['t' => 'stamp', 'text' => $banner];
    $details = apr_export_text($brand['appraiser_details']);
    if ($details !== '') $blocks[] = ['t' => 'details', 'text' => $details];

    $titles = array_values(array_filter(array_map(fn($s) => apr_export_text($s['title'] ?? ''), $sections), fn($t) => $t !== ''));
    if (count($titles) >= 3) $blocks[] = ['t' => 'toc', 'items' => $titles];

    foreach ($sections as $s) {
        $blocks[] = ['t' => 'h', 'level' => 1, 'text' => apr_export_text($s['title'] ?? '')];
        $unreviewed = ($s['origin'] ?? '') === 'ai' && empty($s['ai_reviewed']);
        foreach (is_array($s['blocks'] ?? null) ? $s['blocks'] : [] as $b) {
            switch ($b['type'] ?? '') {
                case 'p':
                    $blocks[] = ['t' => 'p', 'text' => apr_export_text($b['text'] ?? '')] + ($unreviewed && !empty($b['ai']) ? ['mark' => APR_EXPORT_AI_MARK] : []);
                    break;
                case 'h':
                    $blocks[] = ['t' => 'h', 'level' => 2, 'text' => apr_export_text($b['text'] ?? '')];
                    break;
                case 'note_missing':
                    $blocks[] = ['t' => 'missing', 'text' => 'לא ידוע: ' . apr_export_text($b['what'] ?? '')];
                    break;
                case 'list':
                    $blocks[] = ['t' => 'list', 'items' => array_map('apr_export_text', is_array($b['items'] ?? null) ? array_values($b['items']) : [])];
                    break;
                case 'table':
                    $blocks[] = ['t' => 'table', 'head' => null, 'caption' => null, 'rows' => apr_export_rows($b['rows'] ?? null)];
                    break;
                case 'data_table':
                    $blocks[] = ['t' => 'table', 'head' => array_map('apr_export_text', is_array($b['cols'] ?? null) ? array_values($b['cols']) : []),
                        'caption' => apr_export_text($b['label'] ?? ''), 'rows' => apr_export_rows($b['rows'] ?? null)];
                    break;
                case 'fact':
                    $val = is_array($b['value'] ?? null) ? apr_report_fact_stamp($b['value']) : null;
                    $blocks[] = ['t' => 'kv', 'label' => apr_export_text($b['label'] ?? ($b['attr'] ?? '')), 'value' => $val === null || $val === '' ? 'לא ידוע' : apr_export_cell($val),
                        'note' => $val !== null && $val !== '' && ($b['verified'] ?? null) === false ? 'לא אומת' : null];
                    break;
                case 'image':
                    $img = ($b['state'] ?? '') === 'ok' ? apr_export_image($c, $b['file'] ?? null) : null;
                    $cap = apr_export_text($b['caption'] ?? '');
                    if ($img === null) { $blocks[] = ['t' => 'image_missing', 'caption' => $cap]; break; }
                    $id = 'img' . (count($images) + 1);
                    $images[$id] = $img;
                    $blocks[] = ['t' => 'image', 'id' => $id, 'caption' => $cap];
                    break;
            }
        }
    }

    // ה-hash של התוכן שממנו נכתב הקובץ: לגרסה שאושרה — ה-content_hash שלה (sha256 של ה-snapshot כפי שנשמר); לטיוטה — של הבלוקים
    // כפי שהם עכשיו (בלי התמונות עצמן), כדי שאפשר יהיה לומר אחר כך "הופק לפני שינויים" (מקרה קצה 1)
    $hash = $final ? (string)$v['content_hash'] : apr_report_hash(['blocks' => $blocks, 'images' => array_map(fn($i) => hash('sha256', $i['data']), $images)]);
    $blocks[] = ['t' => 'fingerprint', 'text' => ($final ? 'טביעת האצבע של הגרסה: ' : 'טביעת האצבע של הטיוטה בזמן ההפקה: ') . substr($hash, 0, 12)];

    $state = APR_EXPORT_STATE_LABELS[$v['state']];
    $date = apr_export_date(apr_now());
    return [
        'meta' => [
            // מטא-דאטה של הקובץ (§4.5): כותרת — מספר התיק והגרסה — ושם המשרד. לא שם משתמש, ולא נתיב
            'title' => $title . ' · גרסה ' . $number, 'office' => $officeName, 'app' => 'שמאות מקרקעין',
            'case_number' => $caseNo, 'version' => $number, 'state' => $v['state'], 'state_label' => $state, 'date' => $date,
            'banner' => $banner, 'watermark' => $final ? null : APR_EXPORT_DRAFT_MARK,
            // כותרת תחתונה, תמיד: מספר התיק · גרסה N · תאריך ההפקה · מצב האישור · עמוד X מתוך Y (העמודים — של הכותב)
            'footer' => 'תיק ' . $caseNo . ' · גרסה ' . $number . ' · ' . $date . ' · ' . $state,
            'header_text' => apr_export_text($brand['header_text']) !== '' ? apr_export_text($brand['header_text']) : $officeName,
            'footer_text' => apr_export_text($brand['footer_text']), 'logo' => apr_export_logo(is_array($brand['logo']) ? $brand['logo'] : null),
        ],
        'blocks' => $blocks, 'images' => $images, 'content_hash' => $hash,
    ];
}

/** שורות של טבלה: כל תא מחרוזת מנוטרלת; שורות באורך אחיד (תא חסר — ריק). */
function apr_export_rows(mixed $rows): array
{
    $rows = is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    $w = $rows ? max(array_map('count', $rows)) : 0;
    return array_map(fn($r) => array_pad(array_map('apr_export_cell', array_values($r)), $w, ''), $rows);
}

/** כל הטקסט של המסמך, שורה לכל בלוק — לבדיקות (מה אמור להופיע בשני הקבצים), ולא לשום דבר אחר. */
function apr_export_doc_lines(array $doc): array
{
    $out = [];
    foreach ($doc['blocks'] as $b) {
        switch ($b['t']) {
            case 'title': $out[] = $b['text']; $out[] = $b['sub']; break;
            case 'toc': case 'list': foreach ($b['items'] as $i) $out[] = $i; break;
            case 'kv': $out[] = $b['label'] . ': ' . $b['value'] . ($b['note'] !== null ? ' (' . $b['note'] . ')' : ''); break;
            case 'table':
                if ($b['caption'] !== null && $b['caption'] !== '') $out[] = $b['caption'];
                foreach (array_merge($b['head'] === null ? [] : [$b['head']], $b['rows']) as $r) foreach ($r as $cell) if ($cell !== '') $out[] = $cell;
                break;
            case 'image': case 'image_missing': if ($b['t'] === 'image_missing') $out[] = 'התמונה לא זמינה'; if ($b['caption'] !== '') $out[] = $b['caption']; break;
            case 'p': $out[] = (isset($b['mark']) ? '[' . $b['mark'] . '] ' : '') . $b['text']; break;
            default: $out[] = $b['text'];
        }
    }
    return $out;
}
