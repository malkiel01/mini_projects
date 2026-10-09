<?php
/**
 * ניסוח ידני — במקום קרדיט ל-API.
 *
 * כשהבינה לא זמינה (אין מפתח, נגמר הקרדיט), מתכונים מהרשת נשמרים בלי ניסוח.
 * המחזור כאן מאפשר לנסח אותם מחוץ לשרת:
 *   1. התור (rewriteQueue): מתכונים שנפתחו מהחיפוש ולא נוסחו (web_cache),
 *      ומתכונים של המפתח שיובאו בלי ניסוח (recipes.source_rewritten = 0).
 *   2. ייצוא (rewriteExport): JSON עם השלבים כלשונם והוראות הניסוח — המפתח
 *      מוריד אותו, או שקלוד קורא אותו דרך טוקן היומן (logs.php?view=rewrite).
 *   3. הניסוח נעשה בחוץ (בצ'אט), ומוחזר קובץ באותו מבנה.
 *   4. החלה (rewriteApply): השרת בודק כל פריט — קיים, אותו מספר חלקים, לא קרוב
 *      מדי למקור (aiSimilarity, אותו סף כמו בבינה) — ורק אז מחליף את השלבים
 *      ומסמן "נוסח". פריט שלא עבר — נשאר בתור, עם הסיבה.
 *
 * למפתח בלבד. לא נוגע במתכונים של משתמשים אחרים.
 */

declare(strict_types=1);

require_once __DIR__ . '/web_index.php';

const REWRITE_FORMAT = 'recipes-rewrite-1';
const REWRITE_BATCH  = 40;

const REWRITE_INSTRUCTIONS = 'נסח מחדש את שלבי ההכנה בעברית, במילים שלך: אותם עקרונות, אותו סדר, אותן כמויות, ' .
    'זמנים וטמפרטורות — אבל ניסוח אחר, לא העתקה. בלי להוסיף שלבים שלא קיימים ובלי להשמיט מידע. ' .
    'החזר את אותו JSON בדיוק, ושנה רק את מערכי steps (מותר לאחד או לפצל שלבים). אל תשנה id, title או name.';

/** הפריטים שממתינים לניסוח. כל פריט: id ('w:<rowid>' / 'r:<recipe>'), כותרת, מקור, חלקים עם שלבים. */
function rewriteQueue(array $developer, int $limit = REWRITE_BATCH): array {
    requireDeveloper($developer);
    $items = [];
    $rows = db()->query('SELECT rowid, url, draft FROM web_cache WHERE rewritten = 0 ORDER BY created_at DESC')->fetchAll();
    foreach ($rows as $r) {
        $d = json_decode($r['draft'], true);
        if (!is_array($d)) continue;
        $secs = array_map(fn($s) => ['name' => (string) ($s['name'] ?? ''), 'steps' => array_values(array_column($s['steps'] ?? [], 'text'))], $d['sections'] ?? []);
        if (!array_sum(array_map(fn($s) => count($s['steps']), $secs))) continue;
        $items[] = ['id' => 'w:' . $r['rowid'], 'title' => (string) ($d['title'] ?? ''), 'source' => $r['url'], 'sections' => $secs];
    }
    $st = db()->prepare("SELECT id FROM recipes WHERE owner_id = ? AND source_url IS NOT NULL AND source_url != '' AND COALESCE(source_rewritten, 0) = 0 ORDER BY id DESC");
    $st->execute([(int) $developer['id']]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $rid) {
        $r = loadRecipe((int) $rid, $developer);
        $secs = array_map(fn($s) => ['name' => (string) ($s['name'] ?? ''), 'steps' => array_values(array_column($s['steps'], 'text'))], $r['sections']);
        if (!array_sum(array_map(fn($s) => count($s['steps']), $secs))) continue;
        $items[] = ['id' => 'r:' . (int) $rid, 'title' => $r['title'], 'source' => $r['source']['url'] ?? '', 'sections' => $secs];
    }
    return ['total' => count($items), 'items' => array_slice($items, 0, max(1, min(200, $limit)))];
}

function rewriteExport(array $developer, int $limit = REWRITE_BATCH): array {
    $q = rewriteQueue($developer, $limit);
    return ['format' => REWRITE_FORMAT, 'instructions' => REWRITE_INSTRUCTIONS, 'exported_at' => nowIso(),
            'total_waiting' => $q['total'], 'items' => $q['items']];
}

/**
 * החלה של קובץ מנוסח. $data — הקובץ (מערך, אחרי json_decode). מחזיר תוצאה לכל
 * פריט: applied / too_close / mismatch / missing / empty.
 */
function rewriteApply(array $data, array $developer): array {
    requireDeveloper($developer);
    if (($data['format'] ?? '') !== REWRITE_FORMAT || !is_array($data['items'] ?? null)) {
        throw new AppError('זה לא קובץ ניסוח של האפליקציה (חסר format: ' . REWRITE_FORMAT . ' או items)');
    }
    $results = [];
    $applied = 0;
    foreach (array_slice($data['items'], 0, 200) as $it) {
        $id = (string) ($it['id'] ?? '');
        $new = array_map(fn($s) => ['name' => (string) ($s['name'] ?? ''),
                                    'steps' => array_values(array_filter(array_map(fn($x) => trim((string) $x), (array) ($s['steps'] ?? [])), fn($x) => $x !== ''))],
                         is_array($it['sections'] ?? null) ? $it['sections'] : []);
        $res = ['id' => $id, 'title' => mb_substr((string) ($it['title'] ?? ''), 0, 120)];
        if (!array_sum(array_map(fn($s) => count($s['steps']), $new))) { $results[] = $res + ['status' => 'empty', 'message' => 'אין שלבים']; continue; }

        if (preg_match('/^w:(\d+)$/', $id, $m)) {
            $st = db()->prepare('SELECT rowid, url, draft FROM web_cache WHERE rowid = ?');
            $st->execute([(int) $m[1]]);
            $row = $st->fetch();
            $st->closeCursor();
            if (!$row) { $results[] = $res + ['status' => 'missing', 'message' => 'כבר לא במטמון (עבר חודש?)']; continue; }
            $draft = json_decode($row['draft'], true);
            $orig = array_map(fn($s) => ['name' => (string) ($s['name'] ?? ''), 'steps' => array_values(array_column($s['steps'] ?? [], 'text'))], $draft['sections'] ?? []);
        } elseif (preg_match('/^r:(\d+)$/', $id, $m)) {
            $st = db()->prepare('SELECT owner_id FROM recipes WHERE id = ?');
            $st->execute([(int) $m[1]]);
            $owner = $st->fetchColumn();
            $st->closeCursor();
            if ((int) $owner !== (int) $developer['id']) { $results[] = $res + ['status' => 'missing', 'message' => 'המתכון אינו קיים או אינו שלך']; continue; }
            $recipe = loadRecipe((int) $m[1], $developer);
            $orig = array_map(fn($s) => ['name' => (string) ($s['name'] ?? ''), 'steps' => array_values(array_column($s['steps'], 'text'))], $recipe['sections']);
        } else { $results[] = $res + ['status' => 'missing', 'message' => 'מזהה לא מוכר']; continue; }

        if (count($new) !== count($orig)) { $results[] = $res + ['status' => 'mismatch', 'message' => 'מספר החלקים השתנה (' . count($orig) . ' ← ' . count($new) . ')']; continue; }
        $sim = aiSimilarity($orig, $new);
        if ($sim > AI_SIMILARITY_MAX) { $results[] = $res + ['status' => 'too_close', 'message' => 'קרוב מדי למקור (' . round($sim * 100) . '% זהה)']; continue; }

        if (isset($draft)) {
            foreach ($new as $i => $s) $draft['sections'][$i]['steps'] = array_map(fn($t) => ['text' => $t], $s['steps']);
            $draft['source_rewritten'] = true;
            $draft['rewrite_note'] = null;
            db()->prepare('UPDATE web_cache SET draft = ?, rewritten = 1, created_at = ? WHERE rowid = ?')
                ->execute([json_encode($draft, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), time(), (int) $row['rowid']]);
            unset($draft);
        } else {
            $sections = [];
            foreach ($recipe['sections'] as $i => $s) {
                $sections[] = ['name' => $s['name'], 'ingredients' => $s['ingredients'],
                               'steps' => array_map(fn($t) => ['text' => $t], $new[$i]['steps'])];
            }
            saveRecipe(['title' => $recipe['title'], 'visibility' => $recipe['visibility'], 'servings' => $recipe['servings'],
                        'yield_text' => $recipe['yield_text'] ?? '', 'difficulty' => $recipe['difficulty'],
                        'work_minutes' => $recipe['work_minutes'], 'wait_minutes' => $recipe['wait_minutes'], 'tips' => $recipe['tips'],
                        'tag_ids' => array_column($recipe['tags'], 'id'), 'comments_open' => $recipe['comments_open'],
                        'sections' => $sections, 'source_rewritten' => true], $developer, (int) $recipe['id']);
        }
        $applied++;
        $results[] = $res + ['status' => 'applied', 'message' => 'נוסח (' . round($sim * 100) . '% זהה למקור)'];
    }
    logEvent('info', 'rewrite-apply', "ניסוח ידני: $applied מתוך " . count($results), ['applied' => $applied, 'items' => count($results)], $developer);
    return ['results' => $results, 'applied' => $applied, 'waiting' => rewriteQueue($developer, 1)['total']];
}
