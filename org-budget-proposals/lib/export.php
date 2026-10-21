<?php
/**
 * שלב 5 — הפלט: המסמך במבנה של ההצעות שמוגשות היום.
 *
 *   doc_model()    מודל אחד של המסמך: שער · תכנית עבודה · דף ריכוז · דף לכל קופה · נספח שכירויות.
 *                  ממנו נבנים שלושת הפלטים, כדי שלא ייפרדו:
 *   export_docx()  Word — לעריכה. מספרי העמודים בדף הריכוז הם שדות PAGEREF (Word מעדכן בפתיחה).
 *   export_xlsx()  אקסל — עם הלוגיקה: סה"כ = SUM, שורה חודשית = נוסחה, שורה מקבלת = סכום המעבירות,
 *                  שורת ריכוז = הפניות לתאים בגיליונות הקופות. מימין לשמאל, A4, התאמה לרוחב.
 *   PDF            בדפדפן: מסך ההדפסה (#/budget/X/print) ← "שמירה כ-PDF". הוא מקבל את אותו מודל.
 *
 * לוגו הארגון: data/files/logo-<org>.<ext> (מוחרג מהפריסה). תת-ארגון בלי לוגו — של הארגון הראשי.
 */

declare(strict_types=1);

require_once __DIR__ . '/import.php';
require_once __DIR__ . '/plan_doc.php';   // תכנית העבודה בעורך התמלילים

const LOGO_MAX_BYTES = 1_500_000;
const LOGO_TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg'];

// ── לוגו ─────────────────────────────────────────────────────

function logo_dir(): string
{
    $dir = OB_DATA_DIR . '/files';
    ensure_data_dir(OB_DATA_DIR);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir;
}

function logo_file(int $orgId): ?string
{
    foreach (LOGO_TYPES as $ext) {
        $f = logo_dir() . "/logo-$orgId.$ext";
        if (is_file($f)) return $f;
    }
    return null;
}

/** הלוגו של הארגון, או של הארגון הראשי שלו. */
function org_logo_path(array $org): ?string
{
    return logo_file((int)$org['id']) ?? (!empty($org['parent_id']) ? logo_file((int)$org['parent_id']) : null);
}

function org_logo_upload(array $s, array $post, array $files): array
{
    $org = org_access($s, (int)($post['org_id'] ?? 0), 'editor');
    if (!empty($post['remove'])) {
        foreach (LOGO_TYPES as $ext) @unlink(logo_dir() . "/logo-{$org['id']}.$ext");
        return ['ok' => true];
    }
    $f = $files['logo'] ?? null;
    if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) throw new ApiError('לא התקבל קובץ', 422);
    if ($f['size'] > LOGO_MAX_BYTES) throw new ApiError('הלוגו גדול מדי (עד 1.5MB)', 422);
    $type = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $ext = LOGO_TYPES[$type] ?? throw new ApiError('לוגו: PNG או JPG בלבד', 422);
    if (!getimagesize($f['tmp_name'])) throw new ApiError('הקובץ אינו תמונה תקינה', 422);
    foreach (LOGO_TYPES as $e) @unlink(logo_dir() . "/logo-{$org['id']}.$e");
    if (!move_uploaded_file($f['tmp_name'], logo_dir() . "/logo-{$org['id']}.$ext")
        && !rename($f['tmp_name'], logo_dir() . "/logo-{$org['id']}.$ext")) {
        throw new ApiError('שמירת הלוגו נכשלה', 500);
    }
    org_event((int)$org['root_id'], $s, 'org_update', ['id' => (int)$org['id'], 'name' => $org['name'], 'logo' => true]);
    return ['ok' => true];
}

/** הגשת הלוגו (לחבר בארגון). יוצא ב-exit. */
function org_logo_serve(array $s, int $orgId): never
{
    $org = org_access($s, $orgId);
    $f = org_logo_path($org) ?? throw new ApiError('אין לוגו', 404);
    header('Content-Type: ' . (str_ends_with($f, '.png') ? 'image/png' : 'image/jpeg'));
    header('Cache-Control: private, max-age=300');
    header('Content-Length: ' . filesize($f));
    readfile($f);
    exit;
}

// ── מודל המסמך ───────────────────────────────────────────────

// ── עיצוב המסמך: גופן, גדלים, כותרות, שוליים, עימוד ואילו חלקים נכנסים ──

/** גופנים שיש להם עברית ב-Word. במסך / PDF — הגופן אם מותקן, ואחריו Arial. */
const DOC_FONTS = ['Arial', 'David', 'Frank Ruehl', 'Narkisim', 'Miriam', 'Times New Roman', 'Tahoma', 'Calibri', 'Gisha', 'Noto Sans Hebrew'];

/** ברירת המחדל — כמו ההצעות שמוגשות היום. גדלים בנקודות, שוליים במ"מ. */
const DOC_STYLE = [
    'font' => 'Arial',
    'size_text' => 11, 'size_table' => 10, 'size_title' => 14, 'size_head' => 12, 'size_cover' => 24,
    'underline' => true, 'title_align' => 'center', 'line' => 1.1,
    'margin_top' => 20, 'margin_bottom' => 20, 'margin_side' => 20,
    'page_numbers' => true, 'fund_new_page' => true,
    'show_cover' => true, 'show_plan' => true, 'show_summary' => true, 'show_appendix' => true,
];
const DOC_LIMITS = ['size_text' => [7, 20], 'size_table' => [6, 16], 'size_title' => [9, 30], 'size_head' => [8, 24], 'size_cover' => [12, 48],
    'line' => [1, 2.5], 'margin_top' => [5, 50], 'margin_bottom' => [5, 50], 'margin_side' => [5, 50]];

/** קלט → רק מה ששונה מברירת המחדל, בגבולות. */
function doc_style_in(array $in): array
{
    $out = [];
    foreach (DOC_STYLE as $k => $def) {
        if (!array_key_exists($k, $in) || $in[$k] === null || $in[$k] === '') continue;
        $v = $in[$k];
        if (is_bool($def)) $v = (bool)$v;
        elseif ($k === 'font') $v = in_array($v, DOC_FONTS, true) ? $v : throw new ApiError('גופן לא מוכר', 422);
        elseif ($k === 'title_align') $v = in_array($v, ['center', 'right'], true) ? $v : throw new ApiError('יישור לא תקין', 422);
        else {
            if (!is_numeric($v)) throw new ApiError('מספר לא תקין בעיצוב המסמך', 422);
            [$lo, $hi] = DOC_LIMITS[$k];
            $v = round(max($lo, min($hi, (float)$v)) * 2) / 2;   // חצאי נקודה / מ"מ
            if ($k === 'line') $v = round(max($lo, min($hi, (float)$in[$k])), 2);
            elseif ($v == (int)$v) $v = (int)$v;
        }
        if (is_bool($def) || is_string($def) ? $v !== $def : (float)$v !== (float)$def) $out[$k] = $v;
    }
    return $out;
}

/** העיצוב בפועל של ארגון: שלו, ואם אין — של הארגון הראשי, ועל זה ברירת המחדל. */
function org_doc_style(array $org): array
{
    $own = json_decode((string)($org['doc_style'] ?? ''), true);
    $from = is_array($own) ? 'own' : 'default';
    if (!is_array($own) && !empty($org['parent_id'])) {
        $st = db()->prepare('SELECT doc_style FROM orgs WHERE id = ?');
        $st->execute([$org['parent_id']]);
        $own = json_decode((string)$st->fetchColumn(), true);
        if (is_array($own)) $from = 'parent';
    }
    return ['style' => array_merge(DOC_STYLE, is_array($own) ? $own : []), 'from' => $from];
}

/** שמירת העיצוב של ארגון. style = null — חזרה לברירת המחדל (בתת-ארגון: לעיצוב של הארגון הראשי). */
function org_style_set(array $s, array $b): array
{
    $org = org_access($s, (int)($b['org_id'] ?? 0), 'editor');
    $style = is_array($b['style'] ?? null) ? doc_style_in($b['style']) : null;
    db()->prepare('UPDATE orgs SET doc_style = ?, updated_at = ? WHERE id = ?')
        ->execute([$style === null ? null : json_encode($style, JSON_UNESCAPED_UNICODE), now_iso(), $org['id']]);
    org_event($org['root_id'], $s, 'org_update', ['id' => (int)$org['id'], 'name' => $org['name'], 'doc_style' => $style === null ? 'reset' : 'set']);
    $st = db()->prepare('SELECT * FROM orgs WHERE id = ?');
    $st->execute([$org['id']]);
    return ['ok' => true] + org_doc_style($st->fetch());
}

/** העיצוב של ייצוא Word שרץ עכשיו (נקבע ב-export_docx). */
function doc_st(?array $set = null): array
{
    static $cur = DOC_STYLE;
    if ($set !== null) $cur = $set;
    return $cur;
}

function fmt_day(?string $d): string
{
    return $d ? implode('.', array_reverse(explode('-', $d))) : '';
}

/** תכנית העבודה כבלוקים: "# " פרק עם אות, "## " כותרת, "- " תבליט, "מבוא" — כותרת במרכז. */
function plan_blocks(string $text): array
{
    $letters = ['א', 'ב', 'ג', 'ד', 'ה', 'ו', 'ז', 'ח', 'ט', 'י', 'כ', 'ל', 'מ', 'נ', 'ס', 'ע', 'פ', 'צ', 'ק', 'ר', 'ש', 'ת'];
    $n = 0;
    $out = [];
    foreach (preg_split('/\R/u', $text) as $raw) {
        $t = trim($raw);
        if ($t === '' || preg_match('/^תכנית עבודה לשנת \d{4}$/u', $t)) continue;   // הכותרת נכתבת ממילא
        if (str_starts_with($t, '## ')) $out[] = ['type' => 'heading', 'text' => mb_substr($t, 3), 'level' => 2];
        elseif (str_starts_with($t, '# ')) $out[] = ['type' => 'heading', 'text' => ($letters[$n++] ?? (string)$n) . '. ' . mb_substr($t, 2), 'level' => 1];
        elseif (str_starts_with($t, '- ')) $out[] = ['type' => 'bullet', 'text' => mb_substr($t, 2)];
        elseif (in_array(rtrim($t, ':'), ['מבוא'], true)) $out[] = ['type' => 'center', 'text' => rtrim($t, ':')];
        else $out[] = ['type' => 'para', 'text' => $t];
    }
    return $out;
}

/**
 * המסמך כולו, מתוך budget_get (+ שם הארגון הראשי ולוגו). כל הפלטים משתמשים בו.
 * עמודים: page — הערכה (Word מחשב מחדש בשדות; מסך ההדפסה מודד בפועל).
 */
function doc_model(array $s, int $budgetId): array
{
    $g = budget_get($s, $budgetId);
    $b = $g['budget'];
    $y = $b['year'];
    $orgRow = db()->prepare('SELECT * FROM orgs WHERE id = ?');
    $orgRow->execute([$g['org']['id']]);
    $org = $orgRow->fetch();
    $root = $org;
    if ($org['parent_id']) {
        $orgRow->execute([$org['parent_id']]);
        $root = $orgRow->fetch();
    }
    $cols = [
        'prev' => "הצעת תקציב\n" . ($y - 1),
        'actual' => 'ניצול תקציב' . ($b['prev_actual_as_of'] ? "\nעד " . fmt_day($b['prev_actual_as_of']) : "\n" . ($y - 1)),
        'cur' => "הצעת תקציב\n$y",
    ];

    // דף לכל קופה: טבלאות (בסדר שנקבע להצעה), בלי שורות "רק בריכוז"
    $dirs = $b['expense_first'] ? ['expense', 'income'] : ['income', 'expense'];
    $funds = [];
    foreach ($g['funds'] as $f) {
        $tables = [];
        foreach ($dirs as $dir) {
            $rows = [];
            $tot = ['prev' => 0, 'actual' => 0, 'cur' => 0];
            foreach ($g['lines'] as $l) {
                if ($l['fund_id'] !== $f['id'] || $l['direction'] !== $dir || $l['summary_only']) continue;
                $rows[] = ['id' => $l['id'], 'n' => count($rows) + 1, 'title' => $l['title'], 'subtitle' => $l['subtitle'],
                    'prev' => $l['prev_budget'], 'actual' => $l['prev_actual'], 'cur' => $l['amount'],
                    'note' => implode(' · ', array_filter([$l['calc_note'], $l['cell_note']])), 'calc' => $l['calc'],
                    'transfer' => $l['transfer'] ? array_map(fn($p) => $p['line_id'], array_filter($l['transfer'], fn($p) => $p['same_budget'])) : [],
                    'transfer_role' => $l['transfer_role']];
                $tot['prev'] += (int)$l['prev_budget'];
                $tot['actual'] += (int)$l['prev_actual'];
                $tot['cur'] += (int)$l['amount'];
            }
            // ההערות של כיוון — מיד אחרי הטבלה שלו, כמו במסמך
            $dn = array_values(array_filter(array_map('trim', preg_split('/\R/u', (string)($f["notes_$dir"] ?? '')))));
            if ($rows) $tables[] = ['dir' => $dir, 'caption' => DIRECTIONS[$dir] . ' – ' . $f['name'], 'rows' => $rows, 'total' => $tot, 'notes' => $dn];
            elseif ($dn) $orphan = array_merge($orphan ?? [], $dn);
        }
        $notes = $orphan ?? [];
        $orphan = null;
        if (!$tables && !$notes) continue;
        $kinds = array_column($tables, 'dir');
        $head = count($kinds) === 2 ? 'הכנסות והוצאות' : DIRECTIONS[$kinds[0] ?? 'expense'];
        $funds[] = ['id' => $f['id'], 'title' => "$head – {$f['name']}", 'address' => $f['address'], 'tables' => $tables, 'notes' => $notes];
    }

    // הערכת עמודים: שער 1, תכנית עבודה לפי אורך, ריכוז, וכל קופה בדף חדש
    // תכנית העבודה: מהעורך (העותק השמור), ובהצעה שעוד לא עברה לעורך — מהטקסט
    $planView = $b['plan_view'] ?? null;
    $plan = $planView ? plan_html_blocks($planView['html']) : plan_blocks((string)$b['work_plan']);
    $page = 2;
    $planPages = $plan ? max(1, (int)ceil((array_sum(array_map(fn($x) => 1 + intdiv(mb_strlen($x['text']), 90), $plan)) + 3) / 32)) : 0;
    $page += $planPages;
    $sumRows = array_sum(array_map(fn($x) => count($x['rows']) + 4, $g['summary']['sections']));
    $page += max(1, (int)ceil($sumRows / 34));
    foreach ($funds as &$f) {
        $f['page'] = $page;
        $lines = array_sum(array_map(fn($t) => count($t['rows']) * 1.4 + 5, $f['tables'])) + count($f['notes']) + 3;
        $page += max(1, (int)ceil($lines / 36));
    }
    unset($f);
    $pageOf = array_column($funds, 'page', 'id');

    $sections = array_map(fn($sec) => [
        'title' => $sec['title'], 'direction' => $sec['direction'],
        'rows' => array_map(function ($r) use ($pageOf) {
            $pages = array_values(array_filter(array_map(fn($id) => $pageOf[$id] ?? null, $r['funds'])));
            return ['label' => $r['label'], 'prev' => $r['prev_budget'], 'actual' => $r['prev_actual'], 'cur' => $r['amount'],
                'funds' => $r['funds'], 'pages' => $pages ? [min($pages), max($pages)] : null, 'key' => $r['key']];
        }, $sec['rows']),
        'total' => ['prev' => $sec['total']['prev_budget'], 'actual' => $sec['total']['prev_actual'], 'cur' => $sec['total']['amount']],
    ], $g['summary']['sections']);

    // שיוך שורות לשורות ריכוז — לנוסחאות באקסל
    $fundById = array_column($g['funds'], null, 'id');
    $rowsById = array_column($g['rows'], null, 'id');
    $lineRow = [];
    foreach ($g['lines'] as $l) {
        $f = $fundById[$l['fund_id']];
        $rid = $l['summary_row_id'] ?? $f['row_' . $l['direction']];
        $lineRow[$l['id']] = $rid !== null && isset($rowsById[$rid]) && $rowsById[$rid]['direction'] === $l['direction']
            ? "row:$rid" : auto_row($f, $l['direction'])['key'];
    }

    $ds = org_doc_style($org);
    return [
        'budget_id' => $b['id'], 'year' => $y, 'status' => $b['status'], 'expense_first' => $b['expense_first'],
        'style' => $ds['style'], 'style_from' => $ds['from'], 'org_id' => (int)$org['id'], 'fonts' => DOC_FONTS, 'style_default' => DOC_STYLE,
        'can_edit' => canEditRole($g['org']['role']), 'org_name' => $org['name'], 'is_sub' => (bool)$org['parent_id'],
        'file' => preg_replace('/[\\\\\/:*?"<>|]+/u', '', "הצעת תקציב $y – {$org['name']}"),
        'cover' => [
            'org' => $root['name'], 'subtitle' => $org['parent_id'] ? $org['name'] : $org['subtitle'],
            'has_logo' => org_logo_path($org) !== null, 'logo_org' => (int)$org['id'],
            'title' => "הצעת תקציב לשנת $y", 'submitted_to' => $b['submitted_to'], 'signers' => $b['signers'],
            'view' => $b['cover_view'] ?? null, 'blocks' => !empty($b['cover_view']) ? plan_html_blocks($b['cover_view']['html']) : [],
        ],
        'plan' => ['title' => "תכנית עבודה לשנת $y", 'blocks' => $plan, 'view' => $planView],
        'summary' => ['title' => 'דף ריכוז', 'sections' => $sections, 'note' => $b['summary_note']],
        'funds' => $funds,
        'summary_only' => array_values(array_map(fn($l) => ['id' => $l['id'], 'fund_id' => $l['fund_id'], 'title' => $l['title'],
            'subtitle' => $l['subtitle'], 'prev' => $l['prev_budget'], 'actual' => $l['prev_actual'], 'cur' => $l['amount']],
            array_filter($g['lines'], fn($l) => $l['summary_only']))),
        'line_row' => $lineRow,
        'appendix' => $g['appendix'] ? ['title' => "דוח הכנסות – נכסים והקדשות לשנת $y", 'groups' => $g['appendix']] : null,
        'cols' => $cols,
        'appendix_cols' => ['property' => '', 'tenant' => 'שם השוכר', 'monthly' => "שכירות לחודש\nלא כולל מע\"מ",
            'annual' => "סה\"כ שכירות לשנה\nלא כולל מע\"מ", 'period' => 'תקופת השכירות'],
    ];
}

function budget_doc(array $s, int $id): array
{
    return ['ok' => true, 'doc' => doc_model($s, $id)];
}

/** הורדת קובץ: כותרות, שם בעברית, ו-exit. */
function send_download(string $path, string $name, string $mime): never
{
    header('Content-Type: ' . $mime);
    // filename — גיבוי באנגלית (עם סיומת) לדפדפן שלא מכיר filename*; filename* — השם בעברית
    $ascii = 'budget' . (preg_match('/(\d{4})/', $name, $m) ? '-' . $m[1] : '') . '.' . pathinfo($name, PATHINFO_EXTENSION);
    header("Content-Disposition: attachment; filename=\"$ascii\"; filename*=UTF-8''" . rawurlencode($name));
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: no-store');
    readfile($path);
    @unlink($path);
    exit;
}

function xml(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function n0($v): string
{
    return $v === null ? '' : number_format((int)$v);
}

// ── Word ─────────────────────────────────────────────────────

/** פסקה מימין לשמאל. $o: bold, size (נקודות), align (right|center|left), u, break (עמוד חדש לפני), keep. */
function w_p(string $text, array $o = []): string
{
    $ppr = '<w:pPr>' . (!empty($o['break']) ? '<w:pageBreakBefore/>' : '') . (!empty($o['keep']) ? '<w:keepNext/>' : '')
        . ($o['numbered'] ?? '') . '<w:bidi/><w:spacing w:before="' . ($o['before'] ?? 0) . '" w:after="' . ($o['after'] ?? 60) . '"/>'
        // ימין = תחילת פסקה מימין לשמאל, ולכן בלי jc: Word מפרש left/right בפסקת bidi הפוך מ-LibreOffice
        . (($o['align'] ?? 'right') === 'right' ? '' : '<w:jc w:val="' . (['center' => 'center', 'left' => 'left'][$o['align']]) . '"/>') . '</w:pPr>';
    $runs = '';
    foreach (explode("\n", $text) as $i => $line) {
        if ($i) $runs .= '<w:r><w:br/></w:r>';
        $runs .= w_r($line, $o);
    }
    return '<w:p>' . $ppr . ($o['bookmark'] ?? '') . $runs . ($o['bookmark_end'] ?? '') . ($o['tail'] ?? '') . '</w:p>';
}

/**
 * קטעים משמאל לימין בתוך טקסט עברי: מספרים (‎-1,000‏, 50%, 30.09.2025, 01-04/2026) ומילים באנגלית ("AI", "Word PDF").
 * רווח נבלע רק בין שתי מילים באנגלית — מספרים מופרדים ברווח נשארים בסדר של עברית.
 */
const LTR_SPAN = "~(?<![\\p{L}\\p{N}])[-+]?(?=[A-Za-z0-9])[A-Za-z0-9]+(?:[.,:/\\-+&_'’][A-Za-z0-9]+)*%?(?: (?=[A-Za-z])[A-Za-z0-9]+(?:[.,:/\\-+&_'’][A-Za-z0-9]+)*)*~u";

/** הטקסט בקטעים [טקסט, משמאל-לימין?] — כל השאר (עברית, רווחים, סוגריים, ₪) מימין לשמאל. */
function bidi_split(string $text): array
{
    $out = [];
    $at = 0;
    preg_match_all(LTR_SPAN, $text, $m, PREG_OFFSET_CAPTURE);
    foreach ($m[0] as [$s, $pos]) {
        if ($pos > $at) $out[] = [substr($text, $at, $pos - $at), false];
        $out[] = [$s, true];
        $at = $pos + strlen($s);
    }
    if ($at < strlen($text)) $out[] = [substr($text, $at), false];
    return $out ?: [['', false]];
}

/**
 * ריצות טקסט כמו ש-Word כותב כשמקלידים עברית מעורבת: <w:rtl/> רק על העברית, ומספרים ואנגלית בריצה משמאל לימין.
 * <w:rtl/> על ריצה של מספרים גורם ל-Word להפוך תאריכים וטווחים (2026/01-04) ולהעביר מינוס לצד השני.
 */
function w_r(string $text, array $o = []): string
{
    $sz = (int)round(($o['size'] ?? 11) * 2);
    $fmt = (!empty($o['bold']) ? '<w:b/><w:bCs/>' : '') . (!empty($o['u']) ? '<w:u w:val="single"/>' : '') . "<w:sz w:val=\"$sz\"/><w:szCs w:val=\"$sz\"/>";
    $out = '';
    foreach (bidi_split($text) as [$s, $ltr]) {
        $out .= '<w:r><w:rPr>' . ($ltr ? '' : '<w:rtl/>') . $fmt . '</w:rPr><w:t xml:space="preserve">' . xml($s) . '</w:t></w:r>';
    }
    return $out;
}

/** שדה PAGEREF לסימנייה — Word מחשב את מספר העמוד ($cached — עד העדכון). */
function w_pageref(string $bookmark, int $cached): string
{
    $sz = (int)round(doc_st()['size_table'] * 2);
    $r = fn($inner) => '<w:r><w:rPr><w:rtl/><w:sz w:val="' . $sz . '"/><w:szCs w:val="' . $sz . '"/></w:rPr>' . $inner . '</w:r>';
    return $r('<w:fldChar w:fldCharType="begin"/>') . $r('<w:instrText xml:space="preserve"> PAGEREF ' . $bookmark . ' \h </w:instrText>')
        . $r('<w:fldChar w:fldCharType="separate"/>') . '<w:r><w:rPr><w:sz w:val="' . $sz . '"/><w:szCs w:val="' . $sz . '"/></w:rPr><w:t>' . $cached . '</w:t></w:r>'
        . $r('<w:fldChar w:fldCharType="end"/>');
}

/**
 * טבלה מימין לשמאל (bidiVisual: התא הראשון — הימני). $widths באחוזים × 50 (pct). $rows: [[cell...], ...];
 * תא: ['text' => …, 'bold' => …, 'align' => …, 'fill' => 'D9D9D9', 'xml' => תוכן מוכן].
 */
function w_table(array $widths, array $rows, array $o = []): string
{
    $grid = implode('', array_map(fn($w) => '<w:gridCol w:w="' . (int)($w * 90) . '"/>', $widths));
    $b = '<w:top w:val="single" w:sz="4" w:color="000000"/><w:left w:val="single" w:sz="4" w:color="000000"/>'
        . '<w:bottom w:val="single" w:sz="4" w:color="000000"/><w:right w:val="single" w:sz="4" w:color="000000"/>'
        . '<w:insideH w:val="single" w:sz="4" w:color="000000"/><w:insideV w:val="single" w:sz="4" w:color="000000"/>';
    $out = '<w:tbl><w:tblPr><w:bidiVisual/><w:tblW w:w="5000" w:type="pct"/><w:tblBorders>' . $b . '</w:tblBorders>'
        . '<w:tblLayout w:type="fixed"/><w:tblCellMar><w:left w:w="70" w:type="dxa"/><w:right w:w="70" w:type="dxa"/></w:tblCellMar></w:tblPr><w:tblGrid>' . $grid . '</w:tblGrid>';
    foreach ($rows as $i => $row) {
        $head = $i === 0 && !empty($o['header']);
        $out .= '<w:tr>' . ($head ? '<w:trPr><w:tblHeader/><w:cantSplit/></w:trPr>' : '<w:trPr><w:cantSplit/></w:trPr>');
        foreach ($row as $j => $c) {
            $c = is_array($c) ? $c : ['text' => (string)$c];
            $fill = $c['fill'] ?? ($head ? 'E7E6E6' : null);
            $out .= '<w:tc><w:tcPr><w:tcW w:w="' . (int)($widths[$j] * 50) . '" w:type="pct"/>'
                . ($fill ? '<w:shd w:val="clear" w:color="auto" w:fill="' . $fill . '"/>' : '') . '<w:vAlign w:val="center"/></w:tcPr>'
                . ($c['xml'] ?? w_p((string)($c['text'] ?? ''), ['bold' => $head || !empty($c['bold']), 'align' => $c['align'] ?? ($head ? 'center' : 'right'),
                    'size' => $c['size'] ?? doc_st()['size_table'], 'after' => 0]))
                . '</w:tc>';
        }
        $out .= '</w:tr>';
    }
    return $out . '</w:tbl>';
}

/** תא סכום: הסכום, ומתחתיו ההערה (קטנה). */
function w_amount($v, string $note = '', bool $bold = false): array
{
    $t = doc_st()['size_table'];
    return ['xml' => w_p(n0($v), ['align' => 'center', 'size' => $t, 'after' => 0, 'bold' => $bold])
        . ($note !== '' ? w_p($note, ['align' => 'center', 'size' => max(6, $t - 2), 'after' => 0]) : '')];
}

function w_logo(array $img): string
{
    [$cx, $cy] = $img['emu'];
    return '<w:p><w:pPr><w:bidi/><w:jc w:val="center"/><w:spacing w:before="400" w:after="400"/></w:pPr><w:r><w:drawing>'
        . '<wp:inline distT="0" distB="0" distL="0" distR="0"><wp:extent cx="' . $cx . '" cy="' . $cy . '"/><wp:docPr id="1" name="logo"/>'
        . '<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
        . '<pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:nvPicPr><pic:cNvPr id="1" name="logo"/><pic:cNvPicPr/></pic:nvPicPr>'
        . '<pic:blipFill><a:blip r:embed="rLogo"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
        . '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic>'
        . '</a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>';
}

const OOXML_MEDIA = ['jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml', 'bmp' => 'image/bmp'];

/**
 * העותק השמור של גוף ה-Word מהעורך. $prefix / $numOffset — לחלק השני באותו קובץ: מזהי הסגנונות, המספור,
 * התמונות והקשרים של העורך זהים בין מסמכים (wpNormal, 9001, wp_1.png, wpImg1), ולכן מקבלים קידומת אחרת.
 */
function part_ox(int $id, string $part, string $prefix = 'wp', int $numOffset = 0): ?array
{
    $ox = json_decode((string)(part_row($id, $part)['ooxml'] ?? ''), true);
    if (!is_array($ox) || !isset($ox['body'])) return null;
    if ($prefix === 'wp' && !$numOffset) return $ox;
    $ren = function (string $x) use ($prefix, $numOffset): string {
        $x = preg_replace('/(w:styleId|w:val)="wp([A-Z]\w*)"/', '$1="' . $prefix . '$2"', $x);
        $x = preg_replace('/r:(embed|id|link)="wpImg/', 'r:$1="' . $prefix . 'Img', $x);
        $x = preg_replace('/Id="wpImg/', 'Id="' . $prefix . 'Img', $x);
        $x = preg_replace('/Target="media\/wp_/', 'Target="media/' . $prefix . '_', $x);
        return preg_replace_callback('/(w:numId w:val|w:abstractNumId w:val|w:abstractNumId|w:numId)="(\d+)"/', fn($m) => $m[1] . '="' . ((int)$m[2] + $numOffset) . '"', $x);
    };
    foreach (['body', 'styles', 'rels'] as $k) $ox[$k] = $ren((string)($ox[$k] ?? ''));
    foreach (['abstract', 'num'] as $k) if (isset($ox['numbering_parts'][$k])) $ox['numbering_parts'][$k] = $ren($ox['numbering_parts'][$k]);
    foreach ($ox['media'] ?? [] as $i => $m) $ox['media'][$i]['name'] = preg_replace('/^wp_/', $prefix . '_', (string)$m['name']);
    return $ox;
}

/** כמה גופים מהעורך בקובץ אחד: סגנונות, מספור (כל ה-abstract לפני כל ה-num), תמונות, קשרים ומרחבי שמות. */
function ooxml_join(array $oxs): ?array
{
    if (!$oxs) return null;
    $j = ['styles' => '', 'numbering_parts' => ['abstract' => '', 'num' => ''], 'media' => [], 'rels' => '', 'namespaces' => ''];
    foreach ($oxs as $o) {
        $j['styles'] .= $o['styles'] ?? '';
        $j['numbering_parts']['abstract'] .= $o['numbering_parts']['abstract'] ?? '';
        $j['numbering_parts']['num'] .= $o['numbering_parts']['num'] ?? '';
        $j['media'] = array_merge($j['media'], $o['media'] ?? []);
        $j['rels'] .= $o['rels'] ?? '';
        $j['namespaces'] .= ' ' . ($o['namespaces'] ?? '');
    }
    return $j;
}

/** מרחבי השמות של הגוף מהעורך — מה שחסר נוסף ל-<w:document> שלנו. */
function ooxml_ns_merge(string $ours, string $theirs): string
{
    preg_match_all('/xmlns:(\w+)="([^"]*)"/', $ours, $a);
    preg_match_all('/xmlns:(\w+)="([^"]*)"/', $theirs, $b);
    foreach ($b[1] as $i => $p) {
        if (in_array($p, $a[1], true)) continue;
        $ours .= ' xmlns:' . $p . '="' . xml($b[2][$i]) . '"';
        $a[1][] = $p;
    }
    return $ours;
}

function export_docx(array $s, int $id): never
{
    [$tmp, $d] = docx_build($s, $id);
    if (defined('OB_EXPORT_SINK')) { rename($tmp, OB_EXPORT_SINK . '.docx'); throw new RuntimeException('sink'); }
    send_download($tmp, $d['file'] . '.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
}

/** השער שנבנה מהשדות, כ-docx לבד — כדי לפתוח ממנו את מסמך השער בעורך (lib/plan_doc.php). */
function cover_docx_bytes(array $s, int $id): string
{
    [$tmp] = docx_build($s, $id, true);
    $bytes = (string)file_get_contents($tmp);
    unlink($tmp);
    return $bytes;
}

/** בונה את ה-docx לקובץ זמני → [נתיב, מודל המסמך]. $coverOnly — רק השער מהשדות, בלי מספרי עמודים. */
function docx_build(array $s, int $id, bool $coverOnly = false): array
{
    $d = doc_model($s, $id);
    $c = $d['cover'];
    $body = '';
    $ds = doc_st($d['style']);
    if ($coverOnly) $ds = ['show_cover' => true, 'show_plan' => false, 'show_summary' => false, 'show_appendix' => false, 'page_numbers' => false] + $ds;
    $oxs = [];   // גופים מהעורך (שער, תכנית) — הסגנונות, המספור והתמונות שלהם נכנסים לחבילה
    [$X, $T, $H, $TI, $C] = [$ds['size_text'], $ds['size_table'], $ds['size_head'], $ds['size_title'], $ds['size_cover']];
    $u = $ds['underline'];
    $al = $ds['title_align'];
    $first = true;   // החלק הראשון במסמך לא מתחיל בעמוד חדש
    $brk = function () use (&$first) { $b = !$first; $first = false; return $b; };

    // שער
    $logo = null;
    if ($c['has_logo']) {
        $st = db()->prepare('SELECT * FROM orgs WHERE id = ?');
        $st->execute([$c['logo_org']]);
        $path = org_logo_path($st->fetch());
        if ($path && ($sz = getimagesize($path))) {
            $w = 5.0; $h = $w * $sz[1] / max(1, $sz[0]);   // ס"מ
            if ($h > 6) { $w *= 6 / $h; $h = 6; }
            $logo = ['path' => $path, 'ext' => str_ends_with($path, '.png') ? 'png' : 'jpeg', 'emu' => [(int)($w * 360000), (int)($h * 360000)]];
        }
    }
    $cv = !$coverOnly && $ds['show_cover'] && $c['view'] ? part_ox($id, 'cover', 'wc', 1000) : null;
    if ($cv) {
        $first = false;
        $body .= $cv['body'];   // השער מהעורך
        $oxs[] = $cv;
    } elseif ($ds['show_cover']) {
        $first = false;
        $body .= w_p($c['org'], ['bold' => true, 'size' => $C, 'align' => 'center', 'before' => 1200, 'after' => 200]);
        if ($c['subtitle']) $body .= w_p($c['subtitle'], ['bold' => true, 'size' => round($C * .75 * 2) / 2, 'align' => 'center', 'after' => 200]);
        $body .= $logo ? w_logo($logo) : w_p('', ['after' => 1600]);
        $body .= w_p($c['title'], ['bold' => true, 'size' => $C + 2, 'align' => 'center', 'before' => 400, 'after' => 400]);
        if ($c['submitted_to']) $body .= w_p($c['submitted_to'], ['bold' => true, 'size' => $X + 1, 'align' => 'center', 'after' => 800]);
        foreach ($c['signers'] as $sg) {
            $body .= w_p($sg['name'], ['bold' => true, 'size' => $X + 1, 'align' => 'center', 'after' => 0]);
            foreach ($sg['titles'] as $t) $body .= w_p($t, ['bold' => true, 'size' => $X + 1, 'align' => 'center', 'after' => 0]);
            $body .= w_p('', ['after' => 200]);
        }
    }

    // תכנית עבודה
    if ($ds['show_plan'] && $d['plan']['blocks']) {
        $body .= w_p($d['plan']['title'], ['bold' => true, 'u' => $u, 'size' => $TI, 'align' => $al, 'break' => $brk(), 'after' => 200]);
        $ox = $d['plan']['view'] ? part_ox($id, 'plan') : null;
        if ($ox) { $body .= $ox['body']; $oxs[] = $ox; }   // הגוף מהעורך — עם העיצוב, הרשימות והתמונות שלו
        else foreach ($d['plan']['blocks'] as $bl) {
            $body .= match ($bl['type']) {
                'center' => w_p($bl['text'], ['bold' => true, 'u' => $u, 'size' => $H + 1, 'align' => $al, 'after' => 160]),
                'heading' => w_p($bl['text'], ['bold' => true, 'u' => $u, 'size' => $H, 'before' => 160, 'keep' => true]),
                'bullet' => w_p((($bl['n'] ?? null) ? $bl['n'] . '.  ' : '•  ') . $bl['text'], ['size' => $X]),
                default => w_p($bl['text'], ['size' => $X, 'after' => 120]),
            };
        }
    }

    // דף ריכוז
    if ($ds['show_summary']) {
    $body .= w_p($d['summary']['title'], ['bold' => true, 'u' => $u, 'size' => $TI, 'align' => $al, 'break' => $brk(), 'after' => 200]);
    foreach ($d['summary']['sections'] as $sec) {
        $body .= w_p($sec['title'], ['bold' => true, 'u' => $u, 'size' => $H, 'align' => $al, 'before' => 200, 'keep' => true]);
        $rows = [['', $d['cols']['prev'], $d['cols']['actual'], $d['cols']['cur'], 'פירוט']];
        foreach ($sec['rows'] as $r) {
            $ref = '';
            if ($r['pages']) {
                [$a, $z] = $r['pages'];
                $fa = $fz = null;
                foreach ($d['funds'] as $f) if (in_array($f['id'], $r['funds'], true)) { $fa ??= $f['id']; $fz = $f['id']; }
                // "עמ' 7 עד 12": מילה בין שני השדות — כך הסדר נכון בכל מעבד תמלילים (מקף בין שני שדות מתהפך בפסקה מימין לשמאל)
                $ref = w_r("עמ' ", ['size' => $T]) . w_pageref("fund$fa", $a)
                    . ($fz !== $fa ? w_r(' עד ', ['size' => $T]) . w_pageref("fund$fz", $z) : '');
            }
            $rows[] = [['text' => $r['label'], 'bold' => true], w_amount($r['prev']), w_amount($r['actual']), w_amount($r['cur']),
                ['xml' => '<w:p><w:pPr><w:bidi/><w:jc w:val="center"/><w:spacing w:after="0"/></w:pPr>' . $ref . '</w:p>']];
        }
        $rows[] = [['text' => 'סה"כ', 'bold' => true, 'fill' => 'F2F2F2'], w_amount($sec['total']['prev'], '', true) + ['fill' => 'F2F2F2'],
            w_amount($sec['total']['actual'], '', true) + ['fill' => 'F2F2F2'], w_amount($sec['total']['cur'], '', true) + ['fill' => 'F2F2F2'], ['text' => '', 'fill' => 'F2F2F2']];
        $body .= w_table([34, 17, 17, 17, 15], $rows, ['header' => true]);
    }
    if ($d['summary']['note']) $body .= w_p($d['summary']['note'], ['bold' => true, 'size' => $T, 'before' => 200]);
    }

    // דף לכל קופה
    foreach ($coverOnly ? [] : $d['funds'] as $i => $f) {
        $bm = '<w:bookmarkStart w:id="' . ($i + 10) . '" w:name="fund' . $f['id'] . '"/>';
        $nb = $brk();
        $body .= w_p($f['title'], ['bold' => true, 'u' => $u, 'size' => $TI, 'align' => $al, 'break' => $nb && ($ds['fund_new_page'] || $i === 0),
            'before' => $nb && !$ds['fund_new_page'] && $i ? 480 : 0, 'after' => 60, 'keep' => true,
            'bookmark' => $bm, 'bookmark_end' => '<w:bookmarkEnd w:id="' . ($i + 10) . '"/>']);
        if ($f['address']) $body .= w_p($f['address'], ['bold' => true, 'u' => $u, 'size' => $H + 1, 'align' => $al, 'after' => 200, 'keep' => true]);
        foreach ($f['tables'] as $t) {
            $body .= w_p($t['caption'], ['bold' => true, 'u' => $u, 'size' => $X, 'align' => $al, 'before' => 240, 'keep' => true]);
            $rows = [['', DIRECTIONS[$t['dir']], $d['cols']['prev'], $d['cols']['actual'], $d['cols']['cur']]];
            foreach ($t['rows'] as $r) {
                $rows[] = [['text' => $r['n'] . '.', 'align' => 'center'],
                    ['xml' => w_p($r['title'], ['bold' => true, 'size' => $T, 'after' => 0]) . ($r['subtitle'] ? w_p($r['subtitle'], ['size' => max(6, $T - 1), 'after' => 0]) : '')],
                    w_amount($r['prev']), w_amount($r['actual']), w_amount($r['cur'], $r['note'])];
            }
            $g = ['fill' => 'F2F2F2'];
            $rows[] = [['text' => '', 'fill' => 'F2F2F2'], ['text' => 'סה"כ', 'bold' => true, 'fill' => 'F2F2F2'],
                w_amount($t['total']['prev'], '', true) + $g, w_amount($t['total']['actual'], '', true) + $g, w_amount($t['total']['cur'], '', true) + $g];
            $body .= w_table([7, 39, 18, 18, 18], $rows, ['header' => true]);
            foreach ($t['notes'] as $n) $body .= w_p($n, ['size' => $T, 'bold' => true, 'before' => 120]);
        }
        foreach ($f['notes'] as $n) $body .= w_p($n, ['size' => $T, 'bold' => true, 'before' => 120]);
    }

    // נספח שכירויות
    if ($ds['show_appendix'] && $d['appendix']) {
        $ac = $d['appendix_cols'];
        foreach ($d['appendix']['groups'] as $gi => $grp) {
            $body .= w_p($d['appendix']['title'], ['bold' => true, 'u' => $u, 'size' => $H + 1, 'align' => $al, 'break' => $gi === 0 && $brk(), 'before' => $gi ? 400 : 0, 'keep' => true]);
            $body .= w_p($grp['title'], ['bold' => true, 'u' => $u, 'size' => $H + 1, 'align' => $al, 'after' => 160, 'keep' => true]);
            $rows = [[$ac['property'], $ac['tenant'], $ac['monthly'], $ac['annual'], $ac['period']]];
            $last = null;
            foreach ($grp['rows'] as $r) {
                $rows[] = [['text' => $r['property'] === $last ? '' : $r['property'], 'bold' => true], $r['tenant'],
                    ['text' => str_replace(' / ', "\n", $r['monthly']), 'align' => 'center'], ['text' => n0($r['annual']), 'align' => 'center'],
                    ['text' => str_replace(', ', "\n", $r['period']), 'align' => 'center']];
                $last = $r['property'];
            }
            $g = ['fill' => 'F2F2F2', 'bold' => true];
            $rows[] = [['text' => 'סה"כ ' . $grp['title']] + $g, ['text' => 'סה"כ'] + $g, ['text' => n0($grp['monthly']), 'align' => 'center'] + $g,
                ['text' => n0($grp['annual']), 'align' => 'center'] + $g, ['text' => ''] + $g];
            $body .= w_table([22, 24, 18, 18, 18], $rows, ['header' => true]);
        }
    }

    $tw = fn($mm) => (int)round($mm * 56.7);   // מ"מ → twips
    $sect = '<w:sectPr>' . ($ds['page_numbers'] ? '<w:footerReference w:type="default" r:id="rFooter"/>' : '') . '<w:pgSz w:w="11906" w:h="16838"/>'
        . '<w:pgMar w:top="' . $tw($ds['margin_top']) . '" w:right="' . $tw($ds['margin_side']) . '" w:bottom="' . $tw($ds['margin_bottom']) . '" w:left="' . $tw($ds['margin_side'])
        . '" w:header="567" w:footer="' . $tw(min(10, $ds['margin_bottom'] / 2)) . '" w:gutter="0"/>' . ($ds['show_cover'] ? '<w:titlePg/>' : '') . '<w:bidi/></w:sectPr>';
    $ns = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
        . 'xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"';
    $ox = ooxml_join($oxs);
    if ($ox) $ns = ooxml_ns_merge($ns, (string)($ox['namespaces'] ?? ''));
    $doc = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document ' . $ns . '><w:body>' . $body . $sect . '</w:body></w:document>';
    $footer = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:ftr ' . $ns . '><w:p><w:pPr><w:bidi/><w:jc w:val="center"/></w:pPr>'
        . '<w:r><w:fldChar w:fldCharType="begin"/></w:r><w:r><w:instrText xml:space="preserve"> PAGE </w:instrText></w:r>'
        . '<w:r><w:fldChar w:fldCharType="separate"/></w:r><w:r><w:t>1</w:t></w:r><w:r><w:fldChar w:fldCharType="end"/></w:r></w:p></w:ftr>';
    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        . '<w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="' . xml($ds['font']) . '" w:hAnsi="' . xml($ds['font']) . '" w:cs="' . xml($ds['font']) . '" w:eastAsia="' . xml($ds['font']) . '"/>'
        . '<w:sz w:val="' . (int)round($X * 2) . '"/><w:szCs w:val="' . (int)round($X * 2) . '"/><w:lang w:val="he-IL" w:bidi="he-IL"/></w:rPr></w:rPrDefault>'
        . '<w:pPrDefault><w:pPr><w:bidi/><w:spacing w:after="60" w:line="' . (int)round(240 * $ds['line']) . '" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults>'
        . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/></w:style>' . ($ox['styles'] ?? '') . '</w:styles>';
    $np = $ox['numbering_parts'] ?? null;
    $numbering = $np && ($np['abstract'] ?? '') !== '' ? '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:numbering xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        . $np['abstract'] . $np['num'] . '</w:numbering>' : null;
    $media = $ox['media'] ?? [];
    $exts = array_diff(array_unique(array_map(fn($m) => strtolower(pathinfo($m['name'], PATHINFO_EXTENSION)), $media)), ['png', 'jpg', '']);
    $settings = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:settings xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        . '<w:updateFields w:val="true"/><w:defaultTabStop w:val="720"/><w:compat><w:compatSetting w:name="compatibilityMode" w:uri="http://schemas.microsoft.com/office/word" w:val="15"/></w:compat></w:settings>';
    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '<Relationship Id="rSettings" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/settings" Target="settings.xml"/>'
        . '<Relationship Id="rFooter" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer" Target="footer1.xml"/>'
        . ($logo ? '<Relationship Id="rLogo" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/logo.' . ($logo['ext'] === 'png' ? 'png' : 'jpg') . '"/>' : '')
        . ($numbering ? '<Relationship Id="rNumbering" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/numbering" Target="numbering.xml"/>' : '')
        . ($ox['rels'] ?? '') . '</Relationships>';
    $types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
        . '<Default Extension="png" ContentType="image/png"/><Default Extension="jpg" ContentType="image/jpeg"/>'
        . implode('', array_map(fn($e) => '<Default Extension="' . xml($e) . '" ContentType="' . xml(OOXML_MEDIA[$e] ?? 'application/octet-stream') . '"/>', $exts))
        . ($numbering ? '<Override PartName="/word/numbering.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.numbering+xml"/>' : '')
        . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
        . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
        . '<Override PartName="/word/settings.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.settings+xml"/>'
        . '<Override PartName="/word/footer1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/>'
        . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/></Types>';
    $root = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="r1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
        . '<Relationship Id="r2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/></Relationships>';
    $core = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
        . 'xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
        . '<dc:title>' . xml($d['file']) . '</dc:title><dc:creator>הצעות תקציב</dc:creator>'
        . '<dcterms:created xsi:type="dcterms:W3CDTF">' . now_iso() . '</dcterms:created></cp:coreProperties>';

    $tmp = tempnam(sys_get_temp_dir(), 'obdocx');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', $types);
    $zip->addFromString('_rels/.rels', $root);
    $zip->addFromString('docProps/core.xml', $core);
    $zip->addFromString('word/document.xml', $doc);
    $zip->addFromString('word/styles.xml', $styles);
    $zip->addFromString('word/settings.xml', $settings);
    $zip->addFromString('word/footer1.xml', $footer);
    $zip->addFromString('word/_rels/document.xml.rels', $rels);
    if ($logo) $zip->addFile($logo['path'], 'word/media/logo.' . ($logo['ext'] === 'png' ? 'png' : 'jpg'));
    if ($numbering) $zip->addFromString('word/numbering.xml', $numbering);
    foreach ($media as $m) {
        $name = basename((string)$m['name']);
        if ($name !== '' && $name !== 'logo.png' && $name !== 'logo.jpg') $zip->addFromString('word/media/' . $name, (string)base64_decode((string)$m['base64']));
    }
    $zip->close();
    return [$tmp, $d];
}

// ── אקסל ─────────────────────────────────────────────────────

function col(int $i): string
{
    $s = '';
    for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s;
    return $s;
}

/**
 * גיליון: שורות של תאים ['v' => ערך, 'f' => נוסחה, 's' => סגנון, 'rich' => [[טקסט, פונט], …]].
 * גובה כל שורה נקבע כאן (ולא נמדד באקסל) — כך אפשר לדעת מראש איפה כל עמוד A4 נגמר:
 * מספרי העמודים בדף הריכוז, ההמשך שלהם בין גיליונות, ומעבר עמוד לפני כותרת שלא תיתקע בתחתית.
 */
final class Sheet
{
    public array $rows = [];
    public array $merges = [];
    public array $breaks = [];     // מעבר עמוד אחרי השורה הזו
    public int $r = 0;
    public int $firstPage = 1;
    public bool $footer = true;
    public ?array $image = null;   // ['row' => …, 'emu' => [w, h], 'off' => emu מימין, 'path' => …, 'ext' => …]
    /** @var array<int,int> שורה → עמוד (יחסית לגיליון, מ-0) */
    public array $pageOf = [];

    public function __construct(public string $name, public array $widths, public float $pageH) {}

    public function add(array $cells, float $height, bool $keepNext = false): int
    {
        $this->rows[++$this->r] = ['cells' => $cells, 'h' => round($height, 1), 'keep' => $keepNext];
        return $this->r;
    }

    public function gap(float $h = 8): void { $this->add([], $h); }

    /** שורה ממוזגת על כל הרוחב. */
    public function line(array $cell, float $height, bool $keepNext = false): int
    {
        $r = $this->add([$cell], $height, $keepNext);
        $this->merges[] = 'A' . $r . ':' . col(count($this->widths) - 1) . $r;
        return $r;
    }

    /** מחשב עמודים: שורות בגובה ידוע, ומעבר עמוד לפני קבוצת "keep" שלא נכנסת (כותרת + כותרת טבלה + שורה). */
    public function paginate(): int
    {
        $page = 0; $used = 0.0;
        $rows = $this->rows;
        $keys = array_keys($rows);
        foreach ($keys as $i => $r) {
            $h = $rows[$r]['h'];
            // קבוצה: השורה הזו וכל השורות שאחריה שמסומנות keep, ועוד שורה אחת
            $group = $h;
            for ($j = $i; $j < count($keys) - 1 && $rows[$keys[$j]]['keep']; $j++) $group += $rows[$keys[$j + 1]]['h'];
            if ($used > 0 && $used + ($rows[$r]['keep'] ? $group : $h) > $this->pageH) {
                $this->breaks[] = $r - 1;
                $page++; $used = 0.0;
                // שורת רווח בראש עמוד — לא נספרת
                if (!$rows[$r]['cells']) { $this->pageOf[$r] = $page; continue; }
            }
            $used += $h;
            $this->pageOf[$r] = $page;
        }
        return $page + 1;
    }

    public function xml(?string $drawingRel = null): string
    {
        $cols = '';
        foreach ($this->widths as $i => $w) $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
        $data = '';
        foreach ($this->rows as $r => $row) {
            $data .= '<row r="' . $r . '" ht="' . $row['h'] . '" customHeight="1">';
            foreach ($row['cells'] as $i => $c) {
                if ($c === null) continue;
                $ref = col($i) . $r;
                $s = (int)($c['s'] ?? 0);
                if (isset($c['f'])) {
                    $data .= "<c r=\"$ref\" s=\"$s\"><f>" . xml(ltrim($c['f'], '=')) . '</f>' . (isset($c['v']) ? '<v>' . (int)$c['v'] . '</v>' : '') . '</c>';
                } elseif (isset($c['rich'])) {
                    $runs = '';
                    foreach ($c['rich'] as [$t, $font]) $runs .= '<r><rPr>' . $font . '</rPr><t xml:space="preserve">' . xml($t) . '</t></r>';
                    $data .= "<c r=\"$ref\" s=\"$s\" t=\"inlineStr\"><is>$runs</is></c>";
                } elseif (is_int($c['v'] ?? null) || is_float($c['v'] ?? null)) {
                    $data .= "<c r=\"$ref\" s=\"$s\"><v>" . $c['v'] . '</v></c>';
                } elseif (($c['v'] ?? '') !== '' || $s) {
                    // טקסט בלי עברית ("01-04/2026, 05-12/2026") — משמאל לימין, אחרת הגיליון הימני הופך את סדר החלקים
                    if ($s === XS_TEXT && !preg_match('/\p{Hebrew}/u', (string)$c['v']) && preg_match('/\d/', (string)$c['v'])) $s = XS_LTR;
                    $data .= "<c r=\"$ref\" s=\"$s\" t=\"inlineStr\"><is><t xml:space=\"preserve\">" . xml((string)($c['v'] ?? '')) . '</t></is></c>';
                }
            }
            $data .= '</row>';
        }
        $st = doc_st();
        $in = fn($mm) => round($mm / 25.4, 3);   // מ"מ → אינץ'
        $merge = $this->merges ? '<mergeCells count="' . count($this->merges) . '">' . implode('', array_map(fn($m) => "<mergeCell ref=\"$m\"/>", $this->merges)) . '</mergeCells>' : '';
        $breaks = $this->breaks ? '<rowBreaks count="' . count($this->breaks) . '" manualBreakCount="' . count($this->breaks) . '">'
            . implode('', array_map(fn($b) => '<brk id="' . $b . '" max="16383" man="1"/>', $this->breaks)) . '</rowBreaks>' : '';
        $footer = $st['page_numbers'] && $this->footer ? '<headerFooter><oddFooter>&amp;C&amp;P</oddFooter></headerFooter>' : '';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>'
            . '<sheetViews><sheetView rightToLeft="1" showGridLines="0" workbookViewId="0"/></sheetViews><sheetFormatPr defaultRowHeight="16"/>'
            . "<cols>$cols</cols><sheetData>$data</sheetData>$merge"
            . '<printOptions horizontalCentered="1"/><pageMargins left="' . $in($st['margin_side']) . '" right="' . $in($st['margin_side']) . '" top="' . $in($st['margin_top'])
            . '" bottom="' . $in($st['margin_bottom']) . '" header="0.2" footer="' . $in(min(10, $st['margin_bottom'] / 2)) . '"/>'
            // רוחב = עמוד אחד; גובה — כמה עמודים שצריך. העמודות מחושבות כך שלא יהיה צורך להקטין
            . '<pageSetup paperSize="9" orientation="portrait" fitToWidth="1" fitToHeight="0" firstPageNumber="' . $this->firstPage . '" useFirstPageNumber="1"/>'
            . $footer . $breaks . ($drawingRel ? '<drawing r:id="' . $drawingRel . '"/>' : '') . '</worksheet>';
    }
}

/** שם גיליון: עד 31 תווים, בלי תווים אסורים, ייחודי. */
function sheet_name(string $n, array &$used): string
{
    $n = mb_substr(trim(preg_replace('/[\[\]:*?\/\\\\]/u', ' ', $n)), 0, 28) ?: 'גיליון';
    $base = $n;
    for ($i = 2; in_array($n, $used, true); $i++) $n = mb_substr($base, 0, 26) . " $i";
    $used[] = $n;
    return $n;
}

function q(string $sheet): string
{
    return "'" . str_replace("'", "''", $sheet) . "'";
}

/** נוסחה לשורה חודשית: =135000*4+141750*8 */
function calc_formula(array $c): string
{
    $from = $c['from'] ?? 1; $to = $c['to'] ?? 12;
    if (!empty($c['change_month']) && $c['change_month'] > $from && $c['change_month'] <= $to) {
        $after = (int)round($c['monthly'] * (1 + $c['change_pct'] / 100));
        return '=' . $c['monthly'] . '*' . ($c['change_month'] - $from) . '+' . $after . '*' . ($to - $c['change_month'] + 1);
    }
    return '=' . $c['monthly'] . '*' . ($to - $from + 1);
}

// סגנונות התאים באקסל (cellXfs) — המספרים נבנים ב-xlsx_styles() באותו סדר
const XS_TITLE = 1, XS_HEAD = 2, XS_TEXT = 3, XS_NUM = 4, XS_TOT_T = 5, XS_TOT_N = 6, XS_NOTE = 7, XS_CAP = 8, XS_SEC = 9,
    XS_IDX = 10, XS_LTR = 11, XS_ADDR = 12, XS_C_ORG = 13, XS_C_SUB = 14, XS_C_TITLE = 15, XS_C_SMALL = 16, XS_PARA = 17,
    XS_P_HEAD = 18, XS_P_CENTER = 19, XS_BULLET = 20, XS_LABEL = 21, XS_REF = 22, XS_TOT_W = 23;

function xlsx_styles(array $st): string
{
    $f = xml($st['font']);
    $u = $st['underline'] ? '<u/>' : '';
    $font = fn(float $sz, bool $b = false, bool $und = false, string $color = '') => '<font>' . ($b ? '<b/>' : '') . ($und ? $u : '')
        . '<sz val="' . $sz . '"/>' . ($color ? '<color rgb="' . $color . '"/>' : '') . '<name val="' . $f . '"/></font>';
    [$X, $T, $H, $TI, $C] = [$st['size_text'], $st['size_table'], $st['size_head'], $st['size_title'], $st['size_cover']];
    $fonts = [$font($T), $font($T, true), $font($TI, true, true), $font(max(6, $T - 2), false, false, 'FF555555'), $font($H, true, true),
        $font($X, true, true), $font($C, true), $font(round($C * .75 * 2) / 2, true), $font($C + 2, true), $font($X + 1, true),
        $font($X), $font($H + 1, true, true), $font($T, true)];
    $al = $st['title_align'] === 'right' ? 'right' : 'center';
    $xf = fn(int $font, string $align, int $fill = 0, int $border = 0, int $num = 0, string $extra = '') => '<xf' . ($num ? ' numFmtId="' . $num . '" applyNumberFormat="1"' : '')
        . ' fontId="' . $font . '" fillId="' . $fill . '" borderId="' . $border . '" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment ' . $align . $extra . '/></xf>';
    $rtl = ' readingOrder="2"';
    $xfs = [
        '<xf/>',
        $xf(2, "horizontal=\"$al\" vertical=\"center\"$rtl"),                                   // 1 כותרת עמוד
        $xf(1, 'horizontal="center" vertical="center" wrapText="1"' . $rtl, 2, 1),              // 2 כותרת טבלה
        $xf(0, 'horizontal="right" vertical="center" wrapText="1"' . $rtl, 0, 1),               // 3 טקסט במסגרת
        $xf(0, 'horizontal="center" vertical="center"', 0, 1, 164),                             // 4 מספר
        $xf(1, 'horizontal="right" vertical="center"' . $rtl, 3, 1),                            // 5 סה"כ — טקסט
        $xf(1, 'horizontal="center" vertical="center"', 3, 1, 164),                             // 6 סה"כ — מספר
        $xf(12, 'horizontal="right" vertical="top" wrapText="1"' . $rtl),                       // 7 הערה אחרי טבלה
        $xf(5, "horizontal=\"$al\" vertical=\"bottom\"$rtl"),                                   // 8 כותרת טבלה בקופה
        $xf(4, "horizontal=\"$al\" vertical=\"bottom\"$rtl"),                                   // 9 מקטע בריכוז
        $xf(0, 'horizontal="center" vertical="center"', 0, 1),                                  // 10 מספור
        $xf(0, 'horizontal="center" vertical="center" wrapText="1" readingOrder="1"', 0, 1),    // 11 טקסט בלי עברית
        $xf(11, "horizontal=\"$al\" vertical=\"center\"$rtl"),                                  // 12 כתובת הקופה
        $xf(6, 'horizontal="center" vertical="center" wrapText="1"' . $rtl),                    // 13 שער: הארגון
        $xf(7, 'horizontal="center" vertical="center" wrapText="1"' . $rtl),                    // 14 שער: תת-כותרת
        $xf(8, 'horizontal="center" vertical="center"' . $rtl),                                 // 15 שער: הכותרת
        $xf(9, 'horizontal="center" vertical="center" wrapText="1"' . $rtl),                    // 16 שער: מוגשת / חותמים
        $xf(10, 'horizontal="right" vertical="top" wrapText="1"' . $rtl),                       // 17 פסקה
        $xf(4, 'horizontal="right" vertical="bottom"' . $rtl),                                  // 18 כותרת בתכנית
        $xf(11, "horizontal=\"$al\" vertical=\"center\"$rtl"),                                  // 19 "מבוא"
        $xf(10, 'horizontal="right" vertical="top" wrapText="1" indent="1"' . $rtl),            // 20 תבליט
        $xf(1, 'horizontal="right" vertical="center" wrapText="1"' . $rtl, 0, 1),               // 21 שורת ריכוז
        $xf(0, 'horizontal="center" vertical="center"' . $rtl, 0, 1),                           // 22 "עמ' X עד Y"
        $xf(1, 'horizontal="right" vertical="center" wrapText="1"' . $rtl, 3, 1),               // 23 סה"כ — טקסט ארוך
    ];
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0;(#,##0);0"/></numFmts>'
        . '<fonts count="' . count($fonts) . '">' . implode('', $fonts) . '</fonts>'
        . '<fills count="4"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFE7E6E6"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFF2F2F2"/></patternFill></fill></fills>'
        . '<borders count="2"><border/><border><left style="thin"/><right style="thin"/><top style="thin"/><bottom style="thin"/></border></borders>'
        . '<cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="' . count($xfs) . '">' . implode('', $xfs) . '</cellXfs></styleSheet>';
}

/**
 * אקסל — כמו ה-Word: שער · תכנית עבודה · דף ריכוז · גיליון לכל קופה · נספח, כל אחד בעמודי A4,
 * באותן עמודות, כותרות וגדלים (עיצוב המסמך). והנוסחאות: סה"כ = SUM, שורה חודשית = נוסחה,
 * שורה מקבלת = סכום המעבירות, ריכוז = הפניות לתאים בגיליונות הקופות. מספרי העמודים ממשיכים בין הגיליונות.
 */
function export_xlsx(array $s, int $id): never
{
    $d = doc_model($s, $id);
    $st = doc_st($d['style']);
    [$X, $T, $H, $TI, $C] = [$st['size_text'], $st['size_table'], $st['size_head'], $st['size_title'], $st['size_cover']];
    $used = [];
    $pt = fn(float $size, int $lines = 1) => round($size * 1.32 * $lines + 4, 1);          // גובה שורה בנקודות
    $pageH = (297 - $st['margin_top'] - $st['margin_bottom']) / 25.4 * 72 - 6;
    // רוחב: התוכן של עמוד A4 (פחות השוליים) ביחידות של אקסל (תו ≈ 7.5px ב-96dpi), עם מרווח — כדי שלא יוקטן בהדפסה
    $units = ((210 - 2 * $st['margin_side']) / 25.4 * 96 * .94 - 30) / 7.6;
    $W = fn(array $pct) => array_map(fn($p) => round($units * $p / 100, 1), $pct);
    /** כמה שורות תופס טקסט בעמודה ברוחב $w (יחידות אקסל) בגופן $size. */
    // אות עברית ≈ חצי מגובה הגופן; יחידת רוחב ≈ 7.5px — כ-10 אותיות ליחידה בגופן 11 (עם מרווח ביטחון: חיתוך גרוע מרווח)
    $lines = fn(string $t, float $w, float $size) => max(1, array_sum(array_map(fn($p) => max(1, (int)ceil(mb_strlen($p) / max(1, $w * 10 / $size))), explode("\n", $t))));
    $runFont = fn(float $sz, bool $b = false, string $color = '') => ($b ? '<b/>' : '') . '<sz val="' . $sz . '"/>' . ($color ? '<color rgb="' . $color . '"/>' : '') . '<rFont val="' . xml($st['font']) . '"/>';

    $sheets = [];
    $cells = [];   // line id → [sheet, row]  (העמודה: C קודם · D ניצול · E מוצע)
    $total = array_sum($W([100]));

    // ── שער ──
    $cover = null;
    if ($st['show_cover']) {
        $cover = new Sheet(sheet_name('שער', $used), [round($units / .94, 1)], $pageH);   // שער — כל רוחב העמוד, כמו ב-Word
        $cover->footer = false;
        $c = $d['cover'];
        $logo = function (float $gapNo) use ($c, $cover, $st) {
            $q = db()->prepare('SELECT * FROM orgs WHERE id = ?');
            $q->execute([$c['logo_org']]);
            $path = $c['has_logo'] ? org_logo_path($q->fetch()) : null;
            if (!$path || !($sz = getimagesize($path))) { if ($gapNo) $cover->gap($gapNo); return; }
            $w = 5.0; $h = $w * $sz[1] / max(1, $sz[0]);   // ס"מ, כמו ב-Word
            if ($h > 6) { $w *= 6 / $h; $h = 6; }
            $cover->gap(20);
            $row = $cover->add([], $h / 2.54 * 72 + 4);
            $widthEmu = (int)(((210 - 2 * $st['margin_side']) / 10) * 360000 * .94);
            $cover->image = ['row' => $row, 'emu' => [(int)($w * 360000), (int)($h * 360000)], 'off' => max(0, (int)(($widthEmu - $w * 360000) / 2)),
                'path' => $path, 'ext' => str_ends_with($path, '.png') ? 'png' : 'jpeg'];
            $cover->gap(20);
        };
        if ($c['blocks']) {
            // השער מהעורך: הטקסט לפי הסדר, במרכז; התמונה הראשונה — הלוגו של הארגון
            $cover->gap(40);
            $img = false;
            foreach ($c['blocks'] as $bl) {
                if ($bl['type'] === 'image') { if (!$img) $logo(0); $img = true; continue; }
                $big = $bl['type'] === 'heading';
                $cover->line(['v' => $bl['text'], 's' => $big ? XS_C_TITLE : XS_C_SMALL], $big ? $pt($C, $lines($bl['text'], $total, $C)) : $pt($X + 1, $lines($bl['text'], $total, $X + 1)));
                $cover->gap($big ? 10 : 4);
            }
        } else {
        $cover->gap(60);
        $cover->line(['v' => $c['org'], 's' => XS_C_ORG], $pt($C, $lines($c['org'], $total, $C)));
        if ($c['subtitle']) { $cover->gap(10); $cover->line(['v' => $c['subtitle'], 's' => XS_C_SUB], $pt($C * .75, $lines($c['subtitle'], $total, $C * .75))); }
        $logo(110);
        $cover->line(['v' => $c['title'], 's' => XS_C_TITLE], $pt($C + 2));
        if ($c['submitted_to']) { $cover->gap(14); $cover->line(['v' => $c['submitted_to'], 's' => XS_C_SMALL], $pt($X + 1, $lines($c['submitted_to'], $total, $X + 1))); }
        $cover->gap(40);
        foreach ($c['signers'] as $sg) {
            $t = implode("\n", array_merge([$sg['name']], $sg['titles']));
            $cover->line(['v' => $t, 's' => XS_C_SMALL], $pt($X + 1, count($sg['titles']) + 1));
            $cover->gap(12);
        }
        }
        $sheets[] = $cover;
    }

    // ── תכנית עבודה ──
    if ($st['show_plan'] && $d['plan']['blocks']) {
        $pl = new Sheet(sheet_name('תכנית עבודה', $used), $W([100]), $pageH);
        $pl->line(['v' => $d['plan']['title'], 's' => XS_TITLE], $pt($TI) + 6, true);
        foreach ($d['plan']['blocks'] as $bl) {
            match ($bl['type']) {
                'center' => [$pl->gap(4), $pl->line(['v' => $bl['text'], 's' => XS_P_CENTER], $pt($H + 1), true)],
                'heading' => [$pl->gap(6), $pl->line(['v' => $bl['text'], 's' => XS_P_HEAD], $pt($H, $lines($bl['text'], $total, $H)), true)],
                'bullet' => $pl->line(['v' => (($bl['n'] ?? null) ? $bl['n'] . '.  ' : '•  ') . $bl['text'], 's' => XS_BULLET], $pt($X, $lines($bl['text'], $total - 3, $X))),
                default => $pl->line(['v' => $bl['text'], 's' => XS_PARA], $pt($X, $lines($bl['text'], $total, $X)) + 2),
            };
        }
        $sheets[] = $pl;
    }

    // ── קופות: כמו ב-Word — # · סעיף (ושורה שנייה, והערת התא) · קודם · ניצול · מוצע ──
    $fw = $W([7, 39, 18, 18, 18]);
    $sumName = sheet_name('ריכוז', $used);
    $fundSheets = [];
    $colH = $pt($T, max(array_map(fn($t) => $lines($t, $fw[2], $T + 1), [$d['cols']['prev'], $d['cols']['actual'], $d['cols']['cur']])));
    foreach ($d['funds'] as $f) {
        $sh = new Sheet(sheet_name(preg_replace('/^(הכנסות והוצאות|הכנסות|הוצאות) – /u', '', $f['title']), $used), $fw, $pageH);
        $sh->line(['v' => $f['title'], 's' => XS_TITLE], $pt($TI) + 4, true);
        if ($f['address']) $sh->line(['v' => $f['address'], 's' => XS_ADDR], $pt($H + 1) + 4, true);
        foreach ($f['tables'] as $t) {
            $sh->gap(10);
            $sh->line(['v' => $t['caption'], 's' => XS_CAP], $pt($X) + 4, true);
            $sh->add([['v' => '', 's' => XS_HEAD], ['v' => DIRECTIONS[$t['dir']], 's' => XS_HEAD], ['v' => $d['cols']['prev'], 's' => XS_HEAD],
                ['v' => $d['cols']['actual'], 's' => XS_HEAD], ['v' => $d['cols']['cur'], 's' => XS_HEAD]], $colH, true);
            $first = $sh->r + 1;
            foreach ($t['rows'] as $r) {
                $row = $sh->r + 1;
                $cells[$r['id']] = [$sh->name, $row];
                $cur = ['v' => (int)$r['cur'], 's' => XS_NUM];
                if ($r['calc']) $cur['f'] = calc_formula($r['calc']);
                $rich = [[$r['title'], $runFont($T, true)]];
                $n = $lines($r['title'], $fw[1], $T);
                if ($r['subtitle']) { $rich[] = ["\n" . $r['subtitle'], $runFont(max(6, $T - 1))]; $n += $lines($r['subtitle'], $fw[1], $T - 1); }
                if ($r['note']) { $rich[] = ["\n" . $r['note'], $runFont(max(6, $T - 2), false, 'FF555555')]; $n += $lines($r['note'], $fw[1], $T - 2); }
                $sh->add([['v' => $r['n'] . '.', 's' => XS_IDX], ['rich' => $rich, 's' => XS_TEXT],
                    ['v' => $r['prev'] === null ? null : (int)$r['prev'], 's' => XS_NUM], ['v' => $r['actual'] === null ? null : (int)$r['actual'], 's' => XS_NUM], $cur],
                    $pt($T, $n));
            }
            $last = $sh->r;
            $sh->add([['v' => '', 's' => XS_TOT_T], ['v' => 'סה"כ', 's' => XS_TOT_T],
                ['f' => "=SUM(C$first:C$last)", 'v' => $t['total']['prev'], 's' => XS_TOT_N], ['f' => "=SUM(D$first:D$last)", 'v' => $t['total']['actual'], 's' => XS_TOT_N],
                ['f' => "=SUM(E$first:E$last)", 'v' => $t['total']['cur'], 's' => XS_TOT_N]], $pt($T));
            foreach ($t['notes'] as $nt) $sh->line(['v' => $nt, 's' => XS_NOTE], $pt($T, $lines($nt, $total, $T)) + 4);
        }
        foreach ($f['notes'] as $nt) { $sh->gap(6); $sh->line(['v' => $nt, 's' => XS_NOTE], $pt($T, $lines($nt, $total, $T)) + 4); }
        $fundSheets[$f['id']] = $sh;
    }

    // העברות: שורה מקבלת = סכום המעבירות (כשכולן בחוברת הזו)
    $byName = [];
    foreach ($fundSheets as $sh) $byName[$sh->name] = $sh;
    foreach ($d['funds'] as $f) foreach ($f['tables'] as $t) foreach ($t['rows'] as $r) {
        if ($r['transfer_role'] !== 'income' || !$r['transfer']) continue;
        $refs = array_values(array_filter(array_map(fn($gid) => $cells[$gid] ?? null, $r['transfer'])));
        if (count($refs) !== count($r['transfer'])) continue;
        [$shName, $row] = $cells[$r['id']];
        foreach (['C' => 2, 'D' => 3, 'E' => 4] as $L => $ci) {
            $byName[$shName]->rows[$row]['cells'][$ci]['f'] = '=' . implode('+', array_map(fn($x) => ($x[0] === $shName ? '' : q($x[0]) . '!') . $L . $x[1], $refs));
        }
    }

    // ── מספרי עמודים: לפי הסדר במסמך — שער · תכנית · ריכוז · קופות · נספח ──
    // הריכוז צריך את העמודים של הקופות, והקופות — את מספר העמודים של הריכוז. הריכוז נבנה פעמיים: פעם למדידה, פעם עם המספרים
    $order = $sheets;   // שער ותכנית עבודה
    $buildSummary = function (array $pageOfFund) use ($d, $st, $sumName, $W, $pt, $T, $TI, $H, $lines, $cells, $pageH): Sheet {
        $sw = $W([34, 17, 17, 17, 15]);
        $sum = new Sheet($sumName, $sw, $pageH);
        $sum->line(['v' => $d['summary']['title'], 's' => XS_TITLE], $pt($TI) + 6, true);
        $byRow = [];
        foreach ($d['line_row'] as $lid => $key) if (isset($cells[$lid])) $byRow[$key][] = $cells[$lid];
        $only = [];
        foreach ($d['summary_only'] as $l) $only[$d['line_row'][$l['id']] ?? ''][] = $l;
        foreach ($d['summary']['sections'] as $sec) {
            $sum->gap(10);
            $sum->line(['v' => $sec['title'], 's' => XS_SEC], $pt($H) + 4, true);
            $sum->add([['v' => '', 's' => XS_HEAD], ['v' => $d['cols']['prev'], 's' => XS_HEAD], ['v' => $d['cols']['actual'], 's' => XS_HEAD],
                ['v' => $d['cols']['cur'], 's' => XS_HEAD], ['v' => 'פירוט', 's' => XS_HEAD]], $pt($T, 2), true);
            $first = $sum->r + 1;
            foreach ($sec['rows'] as $r) {
                $refs = $byRow[$r['key']] ?? [];
                // שורה "רק בריכוז" (ההלוואה בחבר"ק) — אין לה תא בגיליון קופה: הסכום שלה נכתב כאן
                $const = fn($k) => array_sum(array_map(fn($l) => (int)$l[$k], $only[$r['key']] ?? []));
                $f = fn($L, $k) => $refs ? '=' . implode('+', array_map(fn($x) => q($x[0]) . "!$L{$x[1]}", $refs)) . ($const($k) ? '+' . $const($k) : '') : null;
                $pages = array_values(array_filter(array_map(fn($fid) => $pageOfFund[$fid] ?? null, $r['funds'])));
                $ref = $pages ? (min($pages) === max($pages) ? "עמ' " . min($pages) : "עמ' " . min($pages) . ' עד ' . max($pages)) : '';
                $cellsRow = [['v' => $r['label'], 's' => XS_LABEL]];
                foreach ([['C', 'prev', 'prev'], ['D', 'actual', 'prev_actual'], ['E', 'cur', 'amount']] as [$L, $k, $lk]) {
                    $fx = $f($L, $k === 'prev' ? 'prev' : ($k === 'actual' ? 'actual' : 'cur'));
                    $cellsRow[] = $fx ? ['f' => $fx, 'v' => $r[$k], 's' => XS_NUM] : ['v' => $r[$k] === null ? null : (int)$r[$k], 's' => XS_NUM];
                }
                $cellsRow[] = ['v' => $ref, 's' => XS_REF];
                $sum->add($cellsRow, $pt($T, $lines($r['label'], $sw[0], $T)));
            }
            $last = $sum->r;
            $sum->add([['v' => 'סה"כ', 's' => XS_TOT_T], ['f' => "=SUM(B$first:B$last)", 'v' => $sec['total']['prev'], 's' => XS_TOT_N],
                ['f' => "=SUM(C$first:C$last)", 'v' => $sec['total']['actual'], 's' => XS_TOT_N], ['f' => "=SUM(D$first:D$last)", 'v' => $sec['total']['cur'], 's' => XS_TOT_N],
                ['v' => '', 's' => XS_TOT_T]], $pt($T));
        }
        if ($d['summary']['note']) { $sum->gap(10); $sum->line(['v' => $d['summary']['note'], 's' => XS_NOTE], $pt($T) + 4); }
        return $sum;
    };
    // בדף הריכוז העמודות זזות באחת (אין #): B קודם · C ניצול · D מוצע — ההפניות לקופות נשארות C/D/E
    $start = 1;
    foreach ($order as $sh) { $sh->firstPage = $start; $start += $sh->paginate(); }
    $sum = $st['show_summary'] ? $buildSummary([]) : null;
    $sumPages = $sum ? $sum->paginate() : 0;
    $pageOfFund = [];
    $p = $start + $sumPages;
    foreach ($fundSheets as $fid => $sh) { $sh->firstPage = $p; $pageOfFund[$fid] = $p; $p += $sh->paginate(); }
    if ($sum) {
        $sum = $buildSummary($pageOfFund);
        $sum->firstPage = $start;
        $sum->paginate();
        $order[] = $sum;
    }
    // שורות "רק בריכוז" כשאין דף ריכוז — לא נעלמות: בגיליון הקופה שלהן, מתחת לטבלאות
    if (!$sum) foreach ($d['summary_only'] as $l) {
        $sh = $fundSheets[$l['fund_id']] ?? null;
        if (!$sh) continue;
        $sh->gap(10);
        $sh->add([['v' => '', 's' => XS_TEXT], ['v' => $l['title'] . ' (רק בריכוז)', 's' => XS_TEXT],
            ['v' => $l['prev'] === null ? null : (int)$l['prev'], 's' => XS_NUM], ['v' => $l['actual'] === null ? null : (int)$l['actual'], 's' => XS_NUM],
            ['v' => (int)$l['cur'], 's' => XS_NUM]], $pt($T));
    }
    foreach ($fundSheets as $sh) $order[] = $sh;

    // ── נספח ──
    if ($st['show_appendix'] && $d['appendix']) {
        $aw = $W([22, 24, 18, 18, 18]);
        $ap = new Sheet(sheet_name('נספח שכירויות', $used), $aw, $pageH);
        $ap->firstPage = $p;
        $c = $d['appendix_cols'];
        foreach ($d['appendix']['groups'] as $gi => $g) {
            if ($gi) $ap->gap(16);
            $ap->line(['v' => $d['appendix']['title'], 's' => XS_ADDR], $pt($H + 1) + 4, true);
            $ap->line(['v' => $g['title'], 's' => XS_ADDR], $pt($H + 1) + 4, true);
            $hl = max(array_map(fn($t, $w) => $lines($t, $w, $T + 1), [$c['property'], $c['tenant'], $c['monthly'], $c['annual'], $c['period']], $aw));
            $ap->add([['v' => $c['property'], 's' => XS_HEAD], ['v' => $c['tenant'], 's' => XS_HEAD], ['v' => $c['monthly'], 's' => XS_HEAD],
                ['v' => $c['annual'], 's' => XS_HEAD], ['v' => $c['period'], 's' => XS_HEAD]], $pt($T, $hl), true);
            $first = $ap->r + 1;
            $lastProp = null;
            foreach ($g['rows'] as $r) {
                $m = str_replace(',', '', $r['monthly']);
                $prop = $r['property'] === $lastProp ? '' : $r['property'];
                $n = max($lines($prop, $aw[0], $T), substr_count($r['monthly'], ' / ') + 1, substr_count($r['period'], ', ') + 1, $lines((string)$r['tenant'], $aw[1], $T));
                $ap->add([$prop === '' ? ['v' => '', 's' => XS_TEXT] : ['rich' => [[$prop, $runFont($T, true)]], 's' => XS_TEXT], ['v' => $r['tenant'], 's' => XS_TEXT],
                    is_numeric($m) ? ['v' => (int)$m, 's' => XS_NUM] : ['v' => str_replace(' / ', "\n", $r['monthly']), 's' => XS_LTR],
                    ['v' => (int)$r['annual'], 's' => XS_NUM], ['v' => str_replace(', ', "\n", $r['period']), 's' => XS_LTR]], $pt($T, $n));
                $lastProp = $r['property'];
            }
            $last = $ap->r;
            $ap->add([['v' => 'סה"כ ' . $g['title'], 's' => XS_TOT_W], ['v' => 'סה"כ', 's' => XS_TOT_T], ['v' => $g['monthly'], 's' => XS_TOT_N],
                ['f' => "=SUM(D$first:D$last)", 'v' => $g['annual'], 's' => XS_TOT_N], ['v' => '', 's' => XS_TOT_T]], $pt($T, $lines('סה"כ ' . $g['title'], $aw[0], $T + 1)));
        }
        $ap->paginate();
        $order[] = $ap;
    }
    $sheets = $order;
    $styles = xlsx_styles($st);
    $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><bookViews><workbookView rightToLeft="1"/></bookViews><sheets>';
    $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    $types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
    if (array_filter($sheets, fn($x) => $x->image)) {
        $types .= '<Default Extension="png" ContentType="image/png"/><Default Extension="jpeg" ContentType="image/jpeg"/>'
            . '<Override PartName="/xl/drawings/drawing1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>';
    }
    foreach ($sheets as $i => $sh) {
        $n = $i + 1;
        $wb .= '<sheet name="' . xml($sh->name) . '" sheetId="' . $n . '" r:id="rs' . $n . '"/>';
        $wbRels .= '<Relationship Id="rs' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
        $types .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    }
    // fullCalcOnLoad — אקסל מחשב את הנוסחאות בפתיחה (הערכים השמורים הם רק לתצוגה מקדימה)
    $wb .= '</sheets><calcPr calcId="191029" fullCalcOnLoad="1"/></workbook>';
    $wbRels .= '<Relationship Id="rst" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
    $types .= '</Types>';

    $tmp = tempnam(sys_get_temp_dir(), 'obxlsx');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', $types);
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="r1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', $wb);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);
    $zip->addFromString('xl/styles.xml', $styles);
    foreach ($sheets as $i => $sh) {
        $zip->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml', $sh->xml($sh->image ? 'rDraw' : null));
        if (!$sh->image) continue;
        // הלוגו בשער: תמונה מעוגנת לשורה שלה, באמצע הרוחב
        $im = $sh->image;
        [$cx, $cy] = $im['emu'];
        $zip->addFromString('xl/worksheets/_rels/sheet' . ($i + 1) . '.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rDraw" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing1.xml"/></Relationships>');
        $zip->addFromString('xl/drawings/drawing1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<xdr:oneCellAnchor><xdr:from><xdr:col>0</xdr:col><xdr:colOff>' . $im['off'] . '</xdr:colOff><xdr:row>' . ($im['row'] - 1) . '</xdr:row><xdr:rowOff>25400</xdr:rowOff></xdr:from>'
            . '<xdr:ext cx="' . $cx . '" cy="' . $cy . '"/><xdr:pic><xdr:nvPicPr><xdr:cNvPr id="2" name="logo"/><xdr:cNvPicPr/></xdr:nvPicPr>'
            . '<xdr:blipFill><a:blip r:embed="rImg"/><a:stretch><a:fillRect/></a:stretch></xdr:blipFill>'
            . '<xdr:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></xdr:spPr></xdr:pic><xdr:clientData/></xdr:oneCellAnchor></xdr:wsDr>');
        $zip->addFromString('xl/drawings/_rels/drawing1.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rImg" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/logo.' . $im['ext'] . '"/></Relationships>');
        $zip->addFile($im['path'], 'xl/media/logo.' . $im['ext']);
    }
    $zip->close();
    if (defined('OB_EXPORT_SINK')) { rename($tmp, OB_EXPORT_SINK . '.xlsx'); throw new RuntimeException('sink'); }
    send_download($tmp, $d['file'] . '.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
}
