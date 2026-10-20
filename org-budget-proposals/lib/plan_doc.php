<?php
/**
 * תכנית העבודה — בעורך התמלילים (word-processor/docs/integration.md).
 *
 * המסמך עצמו, התמונות והגרסאות — בעורך. אצלנו בהצעה: budgets.plan_doc (המזהה), ועותק אחרון של הפלט —
 * plan_html (למסך ולהדפסה) ו-plan_ooxml (לשילוב ב-Word), כדי שהפלט יעבוד גם כשהעורך לא זמין.
 * אקסל ואומדן העמודים — מבלוקים שנגזרים מה-HTML השמור.
 *
 * הצעה ישנה (טקסט ב-work_plan) עוברת לעורך בפתיחה הראשונה. הצעה "על בסיס" — העתק של המסמך, עם החלפת השנה;
 * כשהעורך לא זמין ברגע היצירה — plan_src נשמר, וההעתקה נעשית בפתיחה הבאה.
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

function plan_title(array $bud): string
{
    return mb_substr("תכנית עבודה {$bud['year']} – {$bud['org']['name']}", 0, 120);
}

function plan_row(int $id): array
{
    $st = db()->prepare('SELECT plan_doc, plan_src, plan_html, plan_ooxml, plan_at FROM budgets WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: [];
}

/** המסמך של ההצעה — קיים, העתק מההצעה הקודמת, או חדש מהטקסט הישן. */
function plan_ensure_doc(array $bud): string
{
    $row = plan_row((int)$bud['id']);
    if ($row['plan_doc']) return $row['plan_doc'];
    $src = json_decode((string)$row['plan_src'], true);
    if ($src) {
        $doc = wp_try(fn() => wp()->copy($src['doc'], plan_title($bud),
            (int)$src['year'] !== (int)$bud['year'] ? [[(string)$src['year'], (string)$bud['year']]] : []));
    } else {
        $text = plan_lines((string)$bud['work_plan']);
        $doc = wp_try(fn() => wp()->create(plan_title($bud), plan_page($bud['org']), $text !== '' ? ['format' => 'lines', 'content' => $text] : null));
    }
    db()->prepare('UPDATE budgets SET plan_doc = ?, plan_src = NULL WHERE id = ?')->execute([$doc, $bud['id']]);
    try {
        plan_store((int)$bud['id'], $doc);
    } catch (Throwable) {
        // העותק יתעדכן בשמירה הראשונה
    }
    return $doc;
}

/** עותק הפלט האחרון — אחרי כל שמירה בעורך. */
function plan_store(int $id, string $doc): array
{
    $h = wp()->export($doc, 'html');
    $o = wp()->export($doc, 'ooxml');
    $html = ['html' => (string)($h['html'] ?? ''), 'css' => (string)($h['css'] ?? '')];
    db()->prepare('UPDATE budgets SET plan_html = ?, plan_ooxml = ?, plan_at = ? WHERE id = ?')
        ->execute([json_encode($html, JSON_UNESCAPED_UNICODE), json_encode($o, JSON_UNESCAPED_UNICODE), now_iso(), $id]);
    return $html;
}

/** כתובת חד-פעמית לעורך (60 שניות). ההרשאה — אצלנו: עורך בטיוטה עורך, כל השאר צופים. */
function editor_session(array $s, array $b): array
{
    $bud = budget_access($s, (int)($b['budget_id'] ?? 0));
    $can = canEditRole($bud['org']['role']) && $bud['status'] === 'draft' ? 'edit' : 'view';
    $doc = plan_ensure_doc($bud);
    $r = wp_try(fn() => wp()->embedSession($doc, ['id' => (string)$s['id'], 'name' => (string)(($s['display_name'] ?? '') ?: ($s['email'] ?? $s['id']))],
        $can, 'section', null, plan_title($bud)));
    return ['ok' => true, 'embed_url' => $r['embed_url'], 'doc' => $r['doc'] ?? $doc, 'can' => $can];
}

/** אחרי שמירה בעורך: מושכים את הפלט ושומרים אצלנו. */
function plan_sync(array $s, array $b): array
{
    $bud = budget_access($s, (int)($b['budget_id'] ?? 0));
    $doc = plan_row((int)$bud['id'])['plan_doc'] ?? null;
    if (!$doc) return ['ok' => true, 'plan_view' => null];
    return ['ok' => true, 'plan_view' => wp_try(fn() => plan_store((int)$bud['id'], $doc))];
}

/**
 * הצעה חדשה מהצעה קיימת (על בסיס / עותק): העותק של הפלט עובר מיד, והמסמך מועתק בעורך.
 * העורך לא זמין — plan_src נשאר, וההעתקה בפתיחה הבאה (plan_ensure_doc).
 */
function plan_copy_from(array $base, int $toId): void
{
    $from = $base['plan_doc'] ? ['doc' => $base['plan_doc'], 'year' => (int)$base['year']] : json_decode((string)($base['plan_src'] ?? ''), true);
    if (!$from) return;
    db()->prepare('UPDATE budgets SET plan_src = ?, plan_html = ?, plan_ooxml = ?, plan_at = ? WHERE id = ?')
        ->execute([json_encode($from), $base['plan_html'], $base['plan_ooxml'], $base['plan_at'], $toId]);
    try {
        $st = db()->prepare('SELECT * FROM budgets WHERE id = ?');
        $st->execute([$toId]);
        $bud = $st->fetch();
        $bud['org'] = org_row((int)$bud['org_id']);
        plan_ensure_doc($bud);
    } catch (Throwable) {
        // נשאר plan_src
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
        if ($t === '') continue;
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
