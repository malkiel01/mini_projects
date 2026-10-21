<?php
/**
 * השער ותכנית העבודה — בעורך התמלילים (word-processor/docs/integration.md). כל חלק מסמך משלו (WP_PARTS).
 *
 * המסמך עצמו, התמונות והגרסאות — בעורך. אצלנו בהצעה, לכל חלק: <part>_doc (המזהה), ועותק אחרון של הפלט —
 * <part>_html (למסך ולהדפסה) ו-<part>_ooxml (לשילוב ב-Word), כדי שהפלט יעבוד גם כשהעורך לא זמין.
 * אקסל ואומדן העמודים — מבלוקים שנגזרים מה-HTML השמור.
 *
 * פתיחה ראשונה: התכנית — מהטקסט הישן (work_plan); השער — מהשער שנבנה מהשדות, כקובץ Word עם הלוגו.
 * הצעה "על בסיס" — העתק של המסמכים, עם החלפת השנה; כשהעורך לא זמין ברגע היצירה — <part>_src נשמר,
 * וההעתקה נעשית בפתיחה הבאה.
 *
 * המפתח הפרטי — בטבלה wp_embed_keys במסד שלנו (data/, מוחרג מהפריסה). WordProcessorClient.php הוא עותק
 * מ-word-processor/client — לא עורכים אותו כאן; כשהעורך מעדכן — מעתיקים שוב.
 */

declare(strict_types=1);

require_once __DIR__ . '/budgets.php';
require_once __DIR__ . '/WordProcessorClient.php';

const WP_URL = 'https://mbe-plus.com/mini_projects/org-budget-proposals/';
const WP_FONTS = ['David', 'Frank Ruehl', 'Arial', 'Narkisim', 'Miriam', 'Times New Roman', 'Calibri', 'Tahoma'];

/** הלקוח. בבדיקות — $GLOBALS['ob_wp'] (לקוח מדומה, בלי רשת). */
function wp(): object
{
    static $wp = null;
    return $GLOBALS['ob_wp'] ?? ($wp ??= new WordProcessorClient(db(), 'org-budget-proposals', 'הצעות תקציב לארגונים',
        WP_URL . 'api.php?action=embed_key', ['https://mbe-plus.com']));
}

/** שגיאה של העורך → הודעה למשתמש. */
function wp_try(callable $fn): mixed
{
    try {
        return $fn();
    } catch (WordProcessorError $e) {
        error_log('org-budget-proposals: word-processor ' . $e->codeName . ' ' . $e->getMessage());
        throw new ApiError(match ($e->codeName) {
            'pending' => 'עורך התמלילים ממתין לאישור החיבור של האפליקציה (בעורך: 🔗 לקוחות הטמעה ← אישור)',
            'offline', 'server' => 'עורך התמלילים לא זמין כרגע — נסו שוב בעוד רגע',
            default => 'עורך התמלילים: ' . $e->getMessage(),
        }, $e->codeName === 'pending' ? 503 : 502);
    }
}

/** הגדרות העמוד למסמך חדש — מעיצוב המסמך של הארגון. אחר כך הן של המסמך (משתנות בעורך). */
function plan_page(array $org): array
{
    $st = org_doc_style($org)['style'];
    return [
        'size' => 'A4',
        'margins' => ['top' => $st['margin_top'], 'bottom' => $st['margin_bottom'], 'right' => $st['margin_side'], 'left' => $st['margin_side']],
        'font' => ['family' => in_array($st['font'], WP_FONTS, true) ? $st['font'] : 'Arial', 'size' => $st['size_text']],
        'styles' => ['h1' => ['size' => $st['size_head'], 'bold' => true], 'h2' => ['size' => $st['size_text'] + 1, 'bold' => true]],
        'numbering' => ['show' => (bool)$st['page_numbers'], 'position' => 'bottom', 'align' => 'center', 'format' => 'n', 'start' => 2],
    ];
}

/** הטקסט הישן (# פרק עם אות, ## כותרת, - תבליט) → פורמט lines של העורך. */
function plan_lines(string $text): string
{
    $out = [];
    foreach (plan_blocks($text) as $b) {
        $out[] = match ($b['type']) {
            'heading' => (($b['level'] ?? 2) === 1 ? '# ' : '## ') . $b['text'],
            'center' => '## ' . $b['text'],
            'bullet' => '- ' . $b['text'],
            default => $b['text'],
        };
    }
    return implode("\n", $out);
}

const WP_PARTS = ['cover', 'plan'];   // שער, תכנית עבודה — כל אחד מסמך משלו בעורך

function wp_part(mixed $p): string
{
    return in_array($p, WP_PARTS, true) ? $p : 'plan';
}

function part_title(array $bud, string $part): string
{
    $what = $part === 'cover' ? 'שער – הצעת תקציב' : 'תכנית עבודה';
    return mb_substr("$what {$bud['year']} – {$bud['org']['name']}", 0, 120);
}

/** המסמך של החלק: doc (המזהה), src (מקור להעתקה), html / ooxml (העותק השמור), at. */
function part_row(int $id, string $part): array
{
    $p = wp_part($part);
    $st = db()->prepare("SELECT {$p}_doc AS doc, {$p}_src AS src, {$p}_html AS html, {$p}_ooxml AS ooxml, {$p}_at AS at FROM budgets WHERE id = ?");
    $st->execute([$id]);
    return $st->fetch() ?: [];
}

/**
 * המסמך של החלק — קיים, העתק מההצעה הקודמת, או חדש: תכנית — מהטקסט הישן; שער — מהשער שנבנה מהשדות
 * (שם הארגון, הלוגו, הכותרת, מוגשת ל…, חותמים), כקובץ Word שהעורך מייבא.
 */
function part_ensure_doc(array $bud, string $part, ?array $s = null): string
{
    $p = wp_part($part);
    $row = part_row((int)$bud['id'], $p);
    if ($row['doc']) return $row['doc'];
    $src = json_decode((string)$row['src'], true);
    if ($src) {
        $doc = wp_try(fn() => wp()->copy($src['doc'], part_title($bud, $p),
            (int)$src['year'] !== (int)$bud['year'] ? [[(string)$src['year'], (string)$bud['year']]] : []));
    } elseif ($p === 'cover') {
        $page = plan_page($bud['org']);
        $page['numbering']['show'] = false;
        $bytes = cover_docx_bytes($s ?? throw new ApiError('נדרשת כניסה', 401), (int)$bud['id']);
        $doc = wp_try(fn() => wp()->create(part_title($bud, $p), $page, ['format' => 'docx', 'content' => base64_encode($bytes)]));
    } else {
        $text = plan_lines((string)$bud['work_plan']);
        $doc = wp_try(fn() => wp()->create(part_title($bud, $p), plan_page($bud['org']), $text !== '' ? ['format' => 'lines', 'content' => $text] : null));
    }
    db()->prepare("UPDATE budgets SET {$p}_doc = ?, {$p}_src = NULL WHERE id = ?")->execute([$doc, $bud['id']]);
    try {
        part_store((int)$bud['id'], $p, $doc);
    } catch (Throwable) {
        // העותק יתעדכן בשמירה הראשונה
    }
    return $doc;
}

/** עותק הפלט האחרון — אחרי כל שמירה בעורך. */
function part_store(int $id, string $part, string $doc): array
{
    $p = wp_part($part);
    $h = wp()->export($doc, 'html');
    $o = wp()->export($doc, 'ooxml');
    $html = ['html' => (string)($h['html'] ?? ''), 'css' => (string)($h['css'] ?? '')];
    db()->prepare("UPDATE budgets SET {$p}_html = ?, {$p}_ooxml = ?, {$p}_at = ? WHERE id = ?")
        ->execute([json_encode($html, JSON_UNESCAPED_UNICODE), json_encode($o, JSON_UNESCAPED_UNICODE), now_iso(), $id]);
    return $html;
}

/** כתובת חד-פעמית לעורך (60 שניות). ההרשאה — אצלנו: עורך בטיוטה עורך, כל השאר צופים. */
function editor_session(array $s, array $b): array
{
    $bud = budget_access($s, (int)($b['budget_id'] ?? 0));
    $p = wp_part($b['part'] ?? 'plan');
    $can = canEditRole($bud['org']['role']) && $bud['status'] === 'draft' ? 'edit' : 'view';
    $doc = part_ensure_doc($bud, $p, $s);
    $r = wp_try(fn() => wp()->embedSession($doc, ['id' => (string)$s['id'], 'name' => (string)(($s['display_name'] ?? '') ?: ($s['email'] ?? $s['id']))],
        $can, 'section', null, part_title($bud, $p)));
    return ['ok' => true, 'embed_url' => $r['embed_url'], 'doc' => $r['doc'] ?? $doc, 'can' => $can];
}

/** אחרי שמירה בעורך: מושכים את הפלט ושומרים אצלנו. */
function plan_sync(array $s, array $b): array
{
    $bud = budget_access($s, (int)($b['budget_id'] ?? 0));
    $p = wp_part($b['part'] ?? 'plan');
    $doc = part_row((int)$bud['id'], $p)['doc'] ?? null;
    if (!$doc) return ['ok' => true, 'view' => null];
    return ['ok' => true, 'view' => wp_try(fn() => part_store((int)$bud['id'], $p, $doc))];
}

/**
 * הצעה חדשה מהצעה קיימת (על בסיס / עותק): לכל חלק — העותק של הפלט עובר מיד, והמסמך מועתק בעורך.
 * העורך לא זמין — <part>_src נשאר, וההעתקה בפתיחה הבאה (part_ensure_doc).
 */
function plan_copy_from(array $base, int $toId): void
{
    foreach (WP_PARTS as $p) {
        $from = $base["{$p}_doc"] ? ['doc' => $base["{$p}_doc"], 'year' => (int)$base['year']] : json_decode((string)($base["{$p}_src"] ?? ''), true);
        if (!$from) continue;
        db()->prepare("UPDATE budgets SET {$p}_src = ?, {$p}_html = ?, {$p}_ooxml = ?, {$p}_at = ? WHERE id = ?")
            ->execute([json_encode($from), $base["{$p}_html"], $base["{$p}_ooxml"], $base["{$p}_at"], $toId]);
        try {
            $st = db()->prepare('SELECT * FROM budgets WHERE id = ?');
            $st->execute([$toId]);
            $bud = $st->fetch();
            $bud['org'] = org_row((int)$bud['org_id']);
            part_ensure_doc($bud, $p);
        } catch (Throwable) {
            // נשאר <part>_src
        }
    }
}

/** ה-HTML השמור → בלוקים (אקסל, אומדן עמודים). */
function plan_html_blocks(string $html): array
{
    if (trim($html) === '') return [];
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8"><body>' . $html . '</body>', LIBXML_NONET);
    $out = [];
    $n = [];
    foreach ((new DOMXPath($dom))->query('//h1|//h2|//h3|//h4|//p[not(ancestor::li) and not(ancestor::td)]|//li|//tr') as $el) {
        $t = trim(preg_replace('/\s+/u', ' ', $el->textContent));
        $tag = $el->nodeName;
        if ($tag === 'tr') $t = implode(' | ', array_map(fn($c) => trim($c->textContent), iterator_to_array((new DOMXPath($dom))->query('td|th', $el))));
        if ($t === '') {
            if ($tag === 'p' && $el->getElementsByTagName('img')->length) $out[] = ['type' => 'image', 'text' => ''];
            continue;
        }
        if ($tag === 'li') {
            $list = $el->parentNode;
            $ordered = $list->nodeName === 'ol';
            $k = spl_object_id($list);
            $n[$k] = ($n[$k] ?? 0) + 1;
            $out[] = ['type' => 'bullet', 'text' => $t, 'n' => $ordered ? $n[$k] : null];
        } else {
            $out[] = ['type' => $tag[0] === 'h' ? 'heading' : 'para', 'text' => $t];
        }
    }
    return $out;
}

/** org לפי מזהה, בלי בדיקת הרשאה (נקרא אחרי שההרשאה כבר נבדקה). */
function org_row(int $id): array
{
    $st = db()->prepare('SELECT * FROM orgs WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: [];
}
