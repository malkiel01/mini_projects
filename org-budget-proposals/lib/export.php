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
        if (str_starts_with($t, '## ')) $out[] = ['type' => 'heading', 'text' => mb_substr($t, 3)];
        elseif (str_starts_with($t, '# ')) $out[] = ['type' => 'heading', 'text' => ($letters[$n++] ?? (string)$n) . '. ' . mb_substr($t, 2)];
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
    $plan = plan_blocks((string)$b['work_plan']);
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

    return [
        'budget_id' => $b['id'], 'year' => $y, 'status' => $b['status'], 'expense_first' => $b['expense_first'],
        'file' => preg_replace('/[\\\\\/:*?"<>|]+/u', '', "הצעת תקציב $y – {$org['name']}"),
        'cover' => [
            'org' => $root['name'], 'subtitle' => $org['parent_id'] ? $org['name'] : $org['subtitle'],
            'has_logo' => org_logo_path($org) !== null, 'logo_org' => (int)$org['id'],
            'title' => "הצעת תקציב לשנת $y", 'submitted_to' => $b['submitted_to'], 'signers' => $b['signers'],
        ],
        'plan' => ['title' => "תכנית עבודה לשנת $y", 'blocks' => $plan],
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
        . '<w:jc w:val="' . (['right' => 'right', 'center' => 'center', 'left' => 'left'][$o['align'] ?? 'right']) . '"/></w:pPr>';
    $runs = '';
    foreach (explode("\n", $text) as $i => $line) {
        if ($i) $runs .= '<w:r><w:br/></w:r>';
        $runs .= w_r($line, $o);
    }
    return '<w:p>' . $ppr . ($o['bookmark'] ?? '') . $runs . ($o['bookmark_end'] ?? '') . ($o['tail'] ?? '') . '</w:p>';
}

function w_r(string $text, array $o = []): string
{
    $sz = (int)round(($o['size'] ?? 11) * 2);
    $rpr = '<w:rPr><w:rtl/>' . (!empty($o['bold']) ? '<w:b/><w:bCs/>' : '') . (!empty($o['u']) ? '<w:u w:val="single"/>' : '')
        . "<w:sz w:val=\"$sz\"/><w:szCs w:val=\"$sz\"/></w:rPr>";
    return '<w:r>' . $rpr . '<w:t xml:space="preserve">' . xml($text) . '</w:t></w:r>';
}

/** שדה PAGEREF לסימנייה — Word מחשב את מספר העמוד ($cached — עד העדכון). */
function w_pageref(string $bookmark, int $cached): string
{
    $r = fn($inner) => '<w:r><w:rPr><w:rtl/><w:sz w:val="20"/><w:szCs w:val="20"/></w:rPr>' . $inner . '</w:r>';
    return $r('<w:fldChar w:fldCharType="begin"/>') . $r('<w:instrText xml:space="preserve"> PAGEREF ' . $bookmark . ' \h </w:instrText>')
        . $r('<w:fldChar w:fldCharType="separate"/>') . $r('<w:t>' . $cached . '</w:t>') . $r('<w:fldChar w:fldCharType="end"/>');
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
                    'size' => $c['size'] ?? 10, 'after' => 0]))
                . '</w:tc>';
        }
        $out .= '</w:tr>';
    }
    return $out . '</w:tbl>';
}

/** תא סכום: הסכום, ומתחתיו ההערה (קטנה). */
function w_amount($v, string $note = '', bool $bold = false): array
{
    return ['xml' => w_p(n0($v), ['align' => 'center', 'size' => 10, 'after' => 0, 'bold' => $bold])
        . ($note !== '' ? w_p($note, ['align' => 'center', 'size' => 8, 'after' => 0]) : '')];
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

function export_docx(array $s, int $id): never
{
    $d = doc_model($s, $id);
    $c = $d['cover'];
    $body = '';

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
    $body .= w_p($c['org'], ['bold' => true, 'size' => 24, 'align' => 'center', 'before' => 1200, 'after' => 200]);
    if ($c['subtitle']) $body .= w_p($c['subtitle'], ['bold' => true, 'size' => 18, 'align' => 'center', 'after' => 200]);
    $body .= $logo ? w_logo($logo) : w_p('', ['after' => 1600]);
    $body .= w_p($c['title'], ['bold' => true, 'size' => 26, 'align' => 'center', 'before' => 400, 'after' => 400]);
    if ($c['submitted_to']) $body .= w_p($c['submitted_to'], ['bold' => true, 'size' => 12, 'align' => 'center', 'after' => 800]);
    foreach ($c['signers'] as $sg) {
        $body .= w_p($sg['name'], ['bold' => true, 'size' => 12, 'align' => 'center', 'after' => 0]);
        foreach ($sg['titles'] as $t) $body .= w_p($t, ['bold' => true, 'size' => 12, 'align' => 'center', 'after' => 0]);
        $body .= w_p('', ['after' => 200]);
    }

    // תכנית עבודה
    if ($d['plan']['blocks']) {
        $body .= w_p($d['plan']['title'], ['bold' => true, 'u' => true, 'size' => 14, 'align' => 'center', 'break' => true, 'after' => 200]);
        foreach ($d['plan']['blocks'] as $bl) {
            $body .= match ($bl['type']) {
                'center' => w_p($bl['text'], ['bold' => true, 'u' => true, 'size' => 13, 'align' => 'center', 'after' => 160]),
                'heading' => w_p($bl['text'], ['bold' => true, 'u' => true, 'size' => 12, 'before' => 160, 'keep' => true]),
                'bullet' => w_p('•  ' . $bl['text'], ['size' => 11]),
                default => w_p($bl['text'], ['size' => 11, 'after' => 120]),
            };
        }
    }

    // דף ריכוז
    $body .= w_p($d['summary']['title'], ['bold' => true, 'u' => true, 'size' => 14, 'align' => 'center', 'break' => true, 'after' => 200]);
    foreach ($d['summary']['sections'] as $sec) {
        $body .= w_p($sec['title'], ['bold' => true, 'u' => true, 'size' => 12, 'align' => 'center', 'before' => 200, 'keep' => true]);
        $rows = [['', $d['cols']['prev'], $d['cols']['actual'], $d['cols']['cur'], 'פירוט']];
        foreach ($sec['rows'] as $r) {
            $ref = '';
            if ($r['pages']) {
                [$a, $z] = $r['pages'];
                $fa = $fz = null;
                foreach ($d['funds'] as $f) if (in_array($f['id'], $r['funds'], true)) { $fa ??= $f['id']; $fz = $f['id']; }
                // "עמ' 7 עד 12": מילה בין שני השדות — כך הסדר נכון בכל מעבד תמלילים (מקף בין שני שדות מתהפך בפסקה מימין לשמאל)
                $ref = w_r("עמ' ", ['size' => 10]) . w_pageref("fund$fa", $a)
                    . ($fz !== $fa ? w_r(' עד ', ['size' => 10]) . w_pageref("fund$fz", $z) : '');
            }
            $rows[] = [['text' => $r['label'], 'bold' => true], w_amount($r['prev']), w_amount($r['actual']), w_amount($r['cur']),
                ['xml' => '<w:p><w:pPr><w:bidi/><w:jc w:val="center"/><w:spacing w:after="0"/></w:pPr>' . $ref . '</w:p>']];
        }
        $rows[] = [['text' => 'סה"כ', 'bold' => true, 'fill' => 'F2F2F2'], w_amount($sec['total']['prev'], '', true) + ['fill' => 'F2F2F2'],
            w_amount($sec['total']['actual'], '', true) + ['fill' => 'F2F2F2'], w_amount($sec['total']['cur'], '', true) + ['fill' => 'F2F2F2'], ['text' => '', 'fill' => 'F2F2F2']];
        $body .= w_table([34, 17, 17, 17, 15], $rows, ['header' => true]);
    }
    if ($d['summary']['note']) $body .= w_p($d['summary']['note'], ['bold' => true, 'size' => 10, 'before' => 200]);

    // דף לכל קופה
    foreach ($d['funds'] as $i => $f) {
        $bm = '<w:bookmarkStart w:id="' . ($i + 10) . '" w:name="fund' . $f['id'] . '"/>';
        $body .= w_p($f['title'], ['bold' => true, 'u' => true, 'size' => 14, 'align' => 'center', 'break' => true, 'after' => 60,
            'bookmark' => $bm, 'bookmark_end' => '<w:bookmarkEnd w:id="' . ($i + 10) . '"/>']);
        if ($f['address']) $body .= w_p($f['address'], ['bold' => true, 'u' => true, 'size' => 13, 'align' => 'center', 'after' => 200]);
        foreach ($f['tables'] as $t) {
            $body .= w_p($t['caption'], ['bold' => true, 'u' => true, 'size' => 11, 'align' => 'center', 'before' => 240, 'keep' => true]);
            $rows = [['', DIRECTIONS[$t['dir']], $d['cols']['prev'], $d['cols']['actual'], $d['cols']['cur']]];
            foreach ($t['rows'] as $r) {
                $rows[] = [['text' => $r['n'] . '.', 'align' => 'center'],
                    ['xml' => w_p($r['title'], ['bold' => true, 'size' => 10, 'after' => 0]) . ($r['subtitle'] ? w_p($r['subtitle'], ['size' => 9, 'after' => 0]) : '')],
                    w_amount($r['prev']), w_amount($r['actual']), w_amount($r['cur'], $r['note'])];
            }
            $g = ['fill' => 'F2F2F2'];
            $rows[] = [['text' => '', 'fill' => 'F2F2F2'], ['text' => 'סה"כ', 'bold' => true, 'fill' => 'F2F2F2'],
                w_amount($t['total']['prev'], '', true) + $g, w_amount($t['total']['actual'], '', true) + $g, w_amount($t['total']['cur'], '', true) + $g];
            $body .= w_table([7, 39, 18, 18, 18], $rows, ['header' => true]);
            foreach ($t['notes'] as $n) $body .= w_p($n, ['size' => 10, 'bold' => true, 'before' => 120]);
        }
        foreach ($f['notes'] as $n) $body .= w_p($n, ['size' => 10, 'bold' => true, 'before' => 120]);
    }

    // נספח שכירויות
    if ($d['appendix']) {
        $ac = $d['appendix_cols'];
        foreach ($d['appendix']['groups'] as $gi => $grp) {
            $body .= w_p($d['appendix']['title'], ['bold' => true, 'u' => true, 'size' => 13, 'align' => 'center', 'break' => $gi === 0, 'before' => $gi ? 400 : 0]);
            $body .= w_p($grp['title'], ['bold' => true, 'u' => true, 'size' => 13, 'align' => 'center', 'after' => 160, 'keep' => true]);
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

    $sect = '<w:sectPr><w:footerReference w:type="default" r:id="rFooter"/><w:pgSz w:w="11906" w:h="16838"/>'
        . '<w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134" w:header="567" w:footer="567" w:gutter="0"/><w:titlePg/><w:bidi/></w:sectPr>';
    $ns = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
        . 'xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"';
    $doc = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document ' . $ns . '><w:body>' . $body . $sect . '</w:body></w:document>';
    $footer = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:ftr ' . $ns . '><w:p><w:pPr><w:bidi/><w:jc w:val="center"/></w:pPr>'
        . '<w:r><w:fldChar w:fldCharType="begin"/></w:r><w:r><w:instrText xml:space="preserve"> PAGE </w:instrText></w:r>'
        . '<w:r><w:fldChar w:fldCharType="separate"/></w:r><w:r><w:t>1</w:t></w:r><w:r><w:fldChar w:fldCharType="end"/></w:r></w:p></w:ftr>';
    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        . '<w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:cs="Arial" w:eastAsia="Arial"/>'
        . '<w:sz w:val="22"/><w:szCs w:val="22"/><w:lang w:val="he-IL" w:bidi="he-IL"/></w:rPr></w:rPrDefault>'
        . '<w:pPrDefault><w:pPr><w:bidi/><w:spacing w:after="60" w:line="264" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults>'
        . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/></w:style></w:styles>';
    $settings = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:settings xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        . '<w:updateFields w:val="true"/><w:defaultTabStop w:val="720"/><w:compat><w:compatSetting w:name="compatibilityMode" w:uri="http://schemas.microsoft.com/office/word" w:val="15"/></w:compat></w:settings>';
    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '<Relationship Id="rSettings" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/settings" Target="settings.xml"/>'
        . '<Relationship Id="rFooter" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer" Target="footer1.xml"/>'
        . ($logo ? '<Relationship Id="rLogo" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/logo.' . ($logo['ext'] === 'png' ? 'png' : 'jpg') . '"/>' : '')
        . '</Relationships>';
    $types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
        . '<Default Extension="png" ContentType="image/png"/><Default Extension="jpg" ContentType="image/jpeg"/>'
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
    $zip->close();
    if (defined('OB_EXPORT_SINK')) { rename($tmp, OB_EXPORT_SINK . '.docx'); throw new RuntimeException('sink'); }
    send_download($tmp, $d['file'] . '.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
}

// ── אקסל ─────────────────────────────────────────────────────

function col(int $i): string
{
    $s = '';
    for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s;
    return $s;
}

/** גיליון: שורות של תאים ['v' => ערך, 'f' => נוסחה, 's' => סגנון]. */
final class Sheet
{
    public array $rows = [];
    public array $merges = [];
    public int $r = 0;

    public function __construct(public string $name, public array $widths) {}

    public function add(array $cells, ?float $height = null): int
    {
        $this->rows[++$this->r] = ['cells' => $cells, 'h' => $height];
        return $this->r;
    }

    public function skip(): void { $this->r++; }

    public function title(string $text, int $style = 2): void
    {
        $r = $this->add([['v' => $text, 's' => $style]], 22);
        $this->merges[] = 'A' . $r . ':' . col(count($this->widths) - 1) . $r;
    }

    public function xml(): string
    {
        $cols = '';
        foreach ($this->widths as $i => $w) $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
        $data = '';
        foreach ($this->rows as $r => $row) {
            $data .= '<row r="' . $r . '"' . ($row['h'] ? ' ht="' . $row['h'] . '" customHeight="1"' : '') . '>';
            foreach ($row['cells'] as $i => $c) {
                if ($c === null) continue;
                $ref = col($i) . $r;
                $s = (int)($c['s'] ?? 0);
                if (isset($c['f'])) {
                    $data .= "<c r=\"$ref\" s=\"$s\"><f>" . xml(ltrim($c['f'], '=')) . '</f>' . (isset($c['v']) ? '<v>' . (int)$c['v'] . '</v>' : '') . '</c>';
                } elseif (is_int($c['v'] ?? null) || is_float($c['v'] ?? null)) {
                    $data .= "<c r=\"$ref\" s=\"$s\"><v>" . $c['v'] . '</v></c>';
                } elseif (($c['v'] ?? '') !== '' || $s) {
                    $data .= "<c r=\"$ref\" s=\"$s\" t=\"inlineStr\"><is><t xml:space=\"preserve\">" . xml((string)($c['v'] ?? '')) . '</t></is></c>';
                }
            }
            $data .= '</row>';
        }
        $merge = $this->merges ? '<mergeCells count="' . count($this->merges) . '">' . implode('', array_map(fn($m) => "<mergeCell ref=\"$m\"/>", $this->merges)) . '</mergeCells>' : '';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>'
            . '<sheetViews><sheetView rightToLeft="1" workbookViewId="0"/></sheetViews><sheetFormatPr defaultRowHeight="16"/>'
            . "<cols>$cols</cols><sheetData>$data</sheetData>$merge"
            . '<printOptions horizontalCentered="1"/><pageMargins left="0.5" right="0.5" top="0.6" bottom="0.6" header="0.3" footer="0.3"/>'
            . '<pageSetup paperSize="9" orientation="portrait" fitToWidth="1" fitToHeight="0"/>'
            . '<headerFooter><oddFooter>&amp;C&amp;P</oddFooter></headerFooter></worksheet>';
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

function export_xlsx(array $s, int $id): never
{
    $d = doc_model($s, $id);
    $used = [];
    // סגנונות (cellXfs): 0 רגיל · 1 מודגש · 2 כותרת גדולה במרכז · 3 כותרת טבלה · 4 טקסט במסגרת · 5 מספר במסגרת
    // 6 סה"כ טקסט · 7 סה"כ מספר · 8 הערה קטנה · 9 כותרת קטע במרכז, קו תחתון · 10 מספר במרכז (מספור)
    $sheets = [];
    $cells = [];   // line id → [sheet, row]  (העמודה: D קודם · E ניצול · F מוצע)

    $sumName = sheet_name('ריכוז', $used);
    $fundSheets = [];
    foreach ($d['funds'] as $f) {
        $sh = new Sheet(sheet_name(preg_replace('/^(הכנסות והוצאות|הכנסות|הוצאות) – /u', '', $f['title']), $used), [6, 34, 30, 15, 17, 15, 26]);
        $sh->title($f['title']);
        if ($f['address']) $sh->title($f['address'], 9);
        $totals = [];
        foreach ($f['tables'] as $t) {
            $sh->skip();
            $sh->title($t['caption'], 9);
            $sh->add([['v' => '#', 's' => 3], ['v' => DIRECTIONS[$t['dir']], 's' => 3], ['v' => 'פירוט', 's' => 3],
                ['v' => str_replace("\n", ' ', $d['cols']['prev']), 's' => 3], ['v' => str_replace("\n", ' ', $d['cols']['actual']), 's' => 3],
                ['v' => str_replace("\n", ' ', $d['cols']['cur']), 's' => 3], ['v' => 'הערה', 's' => 3]], 32);
            $first = $sh->r + 1;
            foreach ($t['rows'] as $r) {
                $row = $sh->r + 1;
                $cells[$r['id']] = [$sh->name, $row];
                $cur = ['v' => (int)$r['cur'], 's' => 5];
                if ($r['calc']) $cur['f'] = calc_formula($r['calc']);
                $sh->add([['v' => $r['n'], 's' => 10], ['v' => $r['title'], 's' => 4], ['v' => (string)$r['subtitle'], 's' => 4],
                    ['v' => $r['prev'] === null ? null : (int)$r['prev'], 's' => 5], ['v' => $r['actual'] === null ? null : (int)$r['actual'], 's' => 5],
                    $cur, ['v' => $r['note'], 's' => 8]]);
            }
            $last = $sh->r;
            $sh->add([['v' => '', 's' => 6], ['v' => 'סה"כ', 's' => 6], ['v' => '', 's' => 6],
                ['f' => "=SUM(D$first:D$last)", 'v' => $t['total']['prev'], 's' => 7], ['f' => "=SUM(E$first:E$last)", 'v' => $t['total']['actual'], 's' => 7],
                ['f' => "=SUM(F$first:F$last)", 'v' => $t['total']['cur'], 's' => 7], ['v' => '', 's' => 6]]);
            $totals[$t['dir']] = $sh->r;
            foreach ($t['notes'] as $n) $sh->add([null, ['v' => $n, 's' => 8]]);
        }
        if (isset($totals['income'], $totals['expense'])) {
            $sh->skip();
            $sh->add([null, ['v' => 'עודף / (גירעון)', 's' => 1], null, null, null,
                ['f' => "=F{$totals['income']}-F{$totals['expense']}", 's' => 7]]);
        }
        foreach ($f['notes'] as $n) { $sh->skip(); $sh->add([null, ['v' => $n, 's' => 8]]); }
        $fundSheets[$f['id']] = $sh;
        $sheets[] = $sh;
    }

    // שורות "רק בריכוז" — בגיליון הקופה שלהן, מתחת לטבלאות
    foreach ($d['summary_only'] as $l) {
        $sh = $fundSheets[$l['fund_id']] ?? null;
        if (!$sh) continue;
        $sh->skip();
        $row = $sh->add([['v' => '', 's' => 4], ['v' => $l['title'] . ' (רק בריכוז)', 's' => 4], ['v' => (string)$l['subtitle'], 's' => 4],
            ['v' => $l['prev'] === null ? null : (int)$l['prev'], 's' => 5], ['v' => $l['actual'] === null ? null : (int)$l['actual'], 's' => 5],
            ['v' => (int)$l['cur'], 's' => 5]]);
        $cells[$l['id']] = [$sh->name, $row];
    }

    // העברות: שורה מקבלת = סכום המעבירות (כשכולן בחוברת הזו)
    foreach ($d['funds'] as $f) foreach ($f['tables'] as $t) foreach ($t['rows'] as $r) {
        if ($r['transfer_role'] !== 'income' || !$r['transfer']) continue;
        $refs = array_values(array_filter(array_map(fn($gid) => $cells[$gid] ?? null, $r['transfer'])));
        if (count($refs) !== count($r['transfer'])) continue;
        [$shName, $row] = $cells[$r['id']];
        $sh = array_values(array_filter($sheets, fn($x) => $x->name === $shName))[0];
        foreach (['D' => 3, 'E' => 4, 'F' => 5] as $L => $ci) {
            $sh->rows[$row]['cells'][$ci]['f'] = '=' . implode('+', array_map(fn($x) => ($x[0] === $shName ? '' : q($x[0]) . '!') . $L . $x[1], $refs));
        }
    }

    // דף הריכוז: כל שורה = הפניות לתאים של השורות שלה
    $sum = new Sheet($sumName, [40, 18, 20, 18]);
    $sum->title($d['summary']['title']);
    $byRow = [];
    foreach ($d['line_row'] as $lid => $key) if (isset($cells[$lid])) $byRow[$key][] = $cells[$lid];
    foreach ($d['summary']['sections'] as $sec) {
        $sum->skip();
        $sum->title($sec['title'], 9);
        $sum->add([['v' => '', 's' => 3], ['v' => str_replace("\n", ' ', $d['cols']['prev']), 's' => 3],
            ['v' => str_replace("\n", ' ', $d['cols']['actual']), 's' => 3], ['v' => str_replace("\n", ' ', $d['cols']['cur']), 's' => 3]], 32);
        $first = $sum->r + 1;
        foreach ($sec['rows'] as $r) {
            $refs = $byRow[$r['key']] ?? [];
            $f = fn($L) => $refs ? '=' . implode('+', array_map(fn($x) => q($x[0]) . "!$L{$x[1]}", $refs)) : null;
            $sum->add([['v' => $r['label'], 's' => 4],
                ['f' => $f('D'), 'v' => $r['prev'], 's' => 5], ['f' => $f('E'), 'v' => $r['actual'], 's' => 5], ['f' => $f('F'), 'v' => $r['cur'], 's' => 5]]);
            foreach ([1, 2, 3] as $ci) if ($sum->rows[$sum->r]['cells'][$ci]['f'] === null) unset($sum->rows[$sum->r]['cells'][$ci]['f']);
        }
        $last = $sum->r;
        $sum->add([['v' => 'סה"כ', 's' => 6], ['f' => "=SUM(B$first:B$last)", 'v' => $sec['total']['prev'], 's' => 7],
            ['f' => "=SUM(C$first:C$last)", 'v' => $sec['total']['actual'], 's' => 7], ['f' => "=SUM(D$first:D$last)", 'v' => $sec['total']['cur'], 's' => 7]]);
    }
    if ($d['summary']['note']) { $sum->skip(); $sum->add([['v' => $d['summary']['note'], 's' => 1]]); }
    array_unshift($sheets, $sum);

    // נספח
    if ($d['appendix']) {
        $ap = new Sheet(sheet_name('נספח שכירויות', $used), [28, 28, 18, 18, 22]);
        $ap->title($d['appendix']['title']);
        foreach ($d['appendix']['groups'] as $g) {
            $ap->skip();
            $ap->title($g['title'], 9);
            $c = $d['appendix_cols'];
            $ap->add([['v' => 'נכס', 's' => 3], ['v' => $c['tenant'], 's' => 3], ['v' => str_replace("\n", ' ', $c['monthly']), 's' => 3],
                ['v' => str_replace("\n", ' ', $c['annual']), 's' => 3], ['v' => $c['period'], 's' => 3]], 32);
            $first = $ap->r + 1;
            $lastProp = null;
            foreach ($g['rows'] as $r) {
                $m = str_replace(',', '', $r['monthly']);
                $ap->add([['v' => $r['property'] === $lastProp ? '' : str_replace("\n", ' – ', $r['property']), 's' => 4], ['v' => $r['tenant'], 's' => 4],
                    is_numeric($m) ? ['v' => (int)$m, 's' => 5] : ['v' => $r['monthly'], 's' => 4], ['v' => (int)$r['annual'], 's' => 5], ['v' => $r['period'], 's' => 4]]);
                $lastProp = $r['property'];
            }
            $last = $ap->r;
            $ap->add([['v' => 'סה"כ ' . $g['title'], 's' => 6], ['v' => '', 's' => 6], ['v' => $g['monthly'], 's' => 7],
                ['f' => "=SUM(D$first:D$last)", 'v' => $g['annual'], 's' => 7], ['v' => '', 's' => 6]]);
        }
        $sheets[] = $ap;
    }

    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0;(#,##0);0"/></numFmts>'
        . '<fonts count="4"><font><sz val="11"/><name val="Arial"/></font><font><b/><sz val="11"/><name val="Arial"/></font>'
        . '<font><b/><u/><sz val="14"/><name val="Arial"/></font><font><sz val="9"/><color rgb="FF555555"/><name val="Arial"/></font></fonts>'
        . '<fills count="4"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFE7E6E6"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFF2F2F2"/></patternFill></fill></fills>'
        . '<borders count="2"><border/><border><left style="thin"/><right style="thin"/><top style="thin"/><bottom style="thin"/></border></borders>'
        . '<cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="11">'
        . '<xf/>'
        . '<xf fontId="1" applyFont="1"/>'
        . '<xf fontId="2" applyFont="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        . '<xf fontId="1" fillId="2" borderId="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
        . '<xf borderId="1" applyBorder="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>'
        . '<xf numFmtId="164" borderId="1" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        . '<xf fontId="1" fillId="3" borderId="1" applyFont="1" applyFill="1" applyBorder="1"/>'
        . '<xf numFmtId="164" fontId="1" fillId="3" borderId="1" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center"/></xf>'
        . '<xf fontId="3" applyFont="1" applyAlignment="1"><alignment wrapText="1" vertical="center"/></xf>'
        . '<xf fontId="1" applyFont="1" applyAlignment="1"><alignment horizontal="center"/></xf>'
        . '<xf borderId="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        . '</cellXfs></styleSheet>';
    $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><bookViews><workbookView rightToLeft="1"/></bookViews><sheets>';
    $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    $types = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
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
    foreach ($sheets as $i => $sh) $zip->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml', $sh->xml());
    $zip->close();
    if (defined('OB_EXPORT_SINK')) { rename($tmp, OB_EXPORT_SINK . '.xlsx'); throw new RuntimeException('sink'); }
    send_download($tmp, $d['file'] . '.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
}
