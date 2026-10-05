<?php
/**
 * "מה יש לי בבית" — המזווה של המשתמש, ומה אפשר להכין ממנו.
 *
 * המשתמש מזין מוצרים (בהקלדה, או מצילום שהבינה מזהה), והמערכת מדרגת את
 * כל המתכונים שהוא רואה (שלו + ציבוריים) לפי כיסוי: כמה מהרכיבים שאינם
 * רשות יש לו. "מוצרי יסוד" (מלח, שמן, מים…) נחשבים קיימים תמיד — רשימה
 * שהמשתמש יכול לכבות פריט-פריט.
 *
 * ההתאמה היא טקסטואלית ולא קטלוגית — כי הרכיב נכתב כטקסט חופשי ("2 כוסות
 * קמח") ורק לפעמים עם מוצר מהקטלוג. לכן: נרמול, הסרת סיומות ריבוי
 * (עגבניות↔עגבנייה), והכלה של מילה שלמה. זה ניחוש טוב, לא מושלם, והמסך
 * אומר "חסר: X" כדי שהמשתמש יראה ויתקן.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/errors.php';
require_once __DIR__ . '/ai.php';
require_once __DIR__ . '/log.php';
require_once __DIR__ . '/recipes.php';

const PANTRY_MAX_ITEMS = 200;
const PANTRY_STAPLES = ['מלח', 'פלפל שחור', 'מים', 'שמן', 'שמן זית', 'סוכר', 'קמח', 'אבקת אפייה', 'סודה לשתייה',
                        'וניל', 'חומץ', 'שום', 'בצל', 'קינמון', 'פפריקה', 'כמון'];
const PANTRY_PHOTO_MAX_BYTES = 6 * 1024 * 1024;

// ─────────────────────────────────────────────────────────────
// נרמול והתאמה
// ─────────────────────────────────────────────────────────────

/** מילה → גזע גס: נרמול, והסרת סיומות ריבוי/נקבה. "עגבניות" → "עגבני", "ביצים" → "ביצ". */
function pantryStem(string $word): string {
    $w = normalizeText($word);
    // בלי הסרת תחיליות: "בצל"→"צל" ו"ביצה"→"יצה" היו הורסים יותר משהיו עוזרים.
    // רק "ה" הידיעה, וכשהמילה ארוכה מספיק ("הקמח"→"קמח", אבל "הל" נשאר).
    if (mb_strlen($w) >= 4 && str_starts_with($w, 'ה')) $w = mb_substr($w, 1);
    foreach (['ויות', 'יות', 'ייה', 'ים', 'ות', 'יה', 'ית', 'ה', 'ת'] as $suf) {
        if (mb_strlen($w) > mb_strlen($suf) + 2 && str_ends_with($w, $suf)) { $w = mb_substr($w, 0, -mb_strlen($suf)); break; }
    }
    // "עגבני" (מ"עגבנייה") ו"עגבנ" (מ"עגבניות") — י' סופית מתיישרת
    if (mb_strlen($w) >= 4 && str_ends_with($w, 'י')) $w = mb_substr($w, 0, -1);
    return $w;
}

/** ביטוי (אולי כמה מילים) → רשימת גזעים של מילים משמעותיות. */
function pantryStems(string $phrase): array {
    $stop = ['של', 'עם', 'או', 'כוס', 'כוסות', 'כף', 'כפות', 'כפית', 'כפיות', 'גרם', 'קג', 'מל', 'ליטר', 'יחידה', 'יחידות',
             'חבילה', 'קורט', 'מעט', 'קצת', 'לפי', 'הטעם', 'טרי', 'טריות', 'טריים', 'גדול', 'גדולה', 'קטן', 'קטנה', 'בינוני',
             'בינונית', 'חצי', 'רבע', 'שליש', 'ל', 'ב', 'מ'];
    $out = [];
    foreach (preg_split('/\s+/u', normalizeText($phrase)) ?: [] as $w) {
        if ($w === '' || is_numeric(str_replace(['%', '.', ','], '', $w)) || in_array($w, $stop, true)) continue;
        $s = pantryStem($w);
        if (mb_strlen($s) >= 2) $out[] = $s;
    }
    return array_values(array_unique($out));
}

/**
 * האם מוצר מהמזווה "מכסה" רכיב. $have: גזעי המזווה; $ing: free_text + product.
 * כלל: כל גזע של שם המוצר במזווה חייב להופיע ברכיב (כך "שמן זית" לא
 * מכסה "שמן קנולה", אבל "שמן" כן מכסה "שמן קנולה").
 */
function pantryCovers(array $pantryItemStems, array $ingredientStems): bool {
    if (!$pantryItemStems || !$ingredientStems) return false;
    foreach ($pantryItemStems as $s) {
        $found = false;
        foreach ($ingredientStems as $i) {
            if ($i === $s || (mb_strlen($s) >= 3 && mb_strlen($i) >= 3 && (str_starts_with($i, $s) || str_starts_with($s, $i)))) { $found = true; break; }
        }
        if (!$found) return false;
    }
    return true;
}

// ─────────────────────────────────────────────────────────────
// המזווה במסד
// ─────────────────────────────────────────────────────────────

function pantryList(array $user): array {
    $st = db()->prepare('SELECT id, name, source, created_at FROM pantry_items WHERE user_id = ? ORDER BY id DESC');
    $st->execute([$user['id']]);
    $items = array_map(fn($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'source' => $r['source'], 'created_at' => $r['created_at']], $st->fetchAll());
    $st = db()->prepare('SELECT name FROM pantry_staples_off WHERE user_id = ?');
    $st->execute([$user['id']]);
    $off = array_column($st->fetchAll(), 'name');
    return ['items' => $items, 'staples' => array_map(fn($s) => ['name' => $s, 'on' => !in_array($s, $off, true)], PANTRY_STAPLES)];
}

/** הוספה של כמה שמות בבת אחת (מהקלדה או מזיהוי בתמונה). כפילויות נבלעות. */
function pantryAdd(array $names, string $source, array $user): array {
    if (!in_array($source, ['text', 'photo'], true)) $source = 'text';
    $pdo = db();
    $count = (int) $pdo->query('SELECT COUNT(*) FROM pantry_items WHERE user_id = ' . (int) $user['id'])->fetchColumn();
    $ins = $pdo->prepare('INSERT OR IGNORE INTO pantry_items (user_id, name, norm, source, created_at) VALUES (?,?,?,?,?)');
    $added = 0;
    foreach ($names as $n) {
        $n = mb_substr(trim((string) $n), 0, 60);
        $norm = normalizeText($n);
        if ($norm === '' || mb_strlen($norm) < 2) continue;
        if ($count + $added >= PANTRY_MAX_ITEMS) throw new AppError('המזווה מלא (עד ' . PANTRY_MAX_ITEMS . ' פריטים)');
        $ins->execute([$user['id'], $n, $norm, $source, nowIso()]);
        $added += $ins->rowCount();
    }
    return ['added' => $added] + pantryList($user);
}

function pantryRemove(int $id, array $user): array {
    db()->prepare('DELETE FROM pantry_items WHERE id = ? AND user_id = ?')->execute([$id, $user['id']]);
    return pantryList($user);
}

function pantryClear(array $user): array {
    db()->prepare('DELETE FROM pantry_items WHERE user_id = ?')->execute([$user['id']]);
    return pantryList($user);
}

function pantryStapleToggle(string $name, bool $on, array $user): array {
    if (!in_array($name, PANTRY_STAPLES, true)) throw new AppError('מוצר יסוד לא מוכר');
    if ($on) db()->prepare('DELETE FROM pantry_staples_off WHERE user_id = ? AND name = ?')->execute([$user['id'], $name]);
    else db()->prepare('INSERT OR IGNORE INTO pantry_staples_off (user_id, name) VALUES (?,?)')->execute([$user['id'], $name]);
    return pantryList($user);
}

// ─────────────────────────────────────────────────────────────
// מה אפשר להכין
// ─────────────────────────────────────────────────────────────

/**
 * כל המתכונים שהמשתמש רואה, מדורגים לפי כיסוי. $maxMissing מסנן (0 = רק
 * מה שאפשר להכין עכשיו). רכיבי רשות אינם נספרים כחסרים.
 */
function pantryMatch(array $user, int $maxMissing = 2, int $limit = 50): array {
    $pantry = pantryList($user);
    $have = [];
    foreach ($pantry['items'] as $it) $have[] = ['name' => $it['name'], 'stems' => pantryStems($it['name'])];
    foreach ($pantry['staples'] as $s) if ($s['on']) $have[] = ['name' => $s['name'], 'stems' => pantryStems($s['name']), 'staple' => true];
    $have = array_values(array_filter($have, fn($h) => $h['stems']));
    if (!array_filter($have, fn($h) => empty($h['staple']))) return ['recipes' => [], 'pantry_count' => 0];

    $uid = (int) $user['id'];
    $rows = db()->prepare("SELECT r.id, r.title, r.owner_id, r.difficulty, r.work_minutes, r.wait_minutes, r.source_name,
                                  u.display_name AS owner_name, m.path_or_url AS main_path, m.source AS main_source
                             FROM recipes r JOIN users u ON u.id = r.owner_id
                             LEFT JOIN media m ON m.id = r.main_media_id AND m.kind = 'image'
                            WHERE r.visibility = 'public' OR r.owner_id = ?");
    $rows->execute([$uid]);
    $ingSt = db()->prepare('SELECT i.free_text, i.optional, p.name AS product FROM ingredients i
                              JOIN sections s ON s.id = i.section_id
                              LEFT JOIN products p ON p.id = i.product_id
                             WHERE s.recipe_id = ?');
    $out = [];
    foreach ($rows->fetchAll() as $r) {
        $ingSt->execute([$r['id']]);
        $ings = $ingSt->fetchAll();
        if (!$ings) continue;
        $need = 0; $got = 0; $missing = [];
        foreach ($ings as $ing) {
            $label = $ing['product'] ?: $ing['free_text'];
            $stems = array_values(array_unique(array_merge(pantryStems($ing['product'] ?? ''), pantryStems($ing['free_text']))));
            $covered = false;
            foreach ($have as $h) if (pantryCovers($h['stems'], $stems)) { $covered = true; break; }
            if ((int) $ing['optional'] === 1) continue;   // רשות — לא נספר לא כחסר ולא כקיים
            $need++;
            if ($covered) $got++; else $missing[] = mb_substr($label, 0, 40);
        }
        if ($need === 0) continue;
        $miss = count($missing);
        if ($miss > $maxMissing) continue;
        $out[] = [
            'id' => (int) $r['id'], 'title' => $r['title'], 'owner_name' => $r['owner_name'],
            'is_mine' => (int) $r['owner_id'] === $uid, 'difficulty' => $r['difficulty'],
            'work_minutes' => $r['work_minutes'] !== null ? (int) $r['work_minutes'] : null,
            'wait_minutes' => $r['wait_minutes'] !== null ? (int) $r['wait_minutes'] : null,
            'thumb' => mediaThumbUrl($r['main_path'], $r['main_source']),
            'source_name' => $r['source_name'],
            'need' => $need, 'have' => $got, 'coverage' => round($got / $need, 2),
            'missing' => array_values(array_unique($missing)),
        ];
    }
    usort($out, fn($a, $b) => [count($a['missing']), -$a['coverage'], $a['title']] <=> [count($b['missing']), -$b['coverage'], $b['title']]);
    return ['recipes' => array_slice($out, 0, $limit), 'pantry_count' => count(array_filter($have, fn($h) => empty($h['staple'])))];
}

// ─────────────────────────────────────────────────────────────
// זיהוי מוצרים בתמונה — Claude vision
// ─────────────────────────────────────────────────────────────

const PANTRY_VISION_PROMPT = <<<'TXT'
בתמונה מוצרי מזון שיש למשתמש בבית — מקרר, מזווה, שקית קניות או שולחן. זהה אותם והחזר רשימה.

כללים: שמות בעברית, כלליים וקצרים ("עגבנייה", "גבינה צהובה", "שמן זית", "קמח"), ביחיד, בלי מותגים ובלי כמויות. רק מה שרואים בבירור. אם משהו לא ברור — לא לכלול. בלי כפילויות. עד 40 פריטים. אם אין מוצרי מזון — רשימה ריקה.
TXT;

/** תמונה (בייטים + MIME) → רשימת שמות מוצרים. לא שומר דבר — המשתמש מאשר ואז מוסיף. */
function pantryRecognize(string $bytes, string $mime, ?array $user = null): array {
    $apiKey = secretGet('anthropic_api_key');
    if ($apiKey === null) throw new AppError('זיהוי מוצרים בתמונה דורש מפתח API (הגדרות ציבוריות). אפשר להקליד את המוצרים', 409);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) throw new AppError('סוג תמונה לא נתמך');
    if (strlen($bytes) > PANTRY_PHOTO_MAX_BYTES) throw new AppError('התמונה גדולה מדי (עד 6MB)');

    $body = [
        'model'      => AI_MODEL,
        'max_tokens' => 2000,
        'fallbacks'  => 'default',
        'output_config' => [
            'effort' => 'low',
            'format' => ['type' => 'json_schema', 'schema' => [
                'type' => 'object',
                'properties' => ['products' => ['type' => 'array', 'items' => ['type' => 'string']]],
                'required' => ['products'], 'additionalProperties' => false,
            ]],
        ],
        'messages' => [['role' => 'user', 'content' => [
            ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => base64_encode($bytes)]],
            ['type' => 'text', 'text' => PANTRY_VISION_PROMPT],
        ]]],
    ];
    $t0 = microtime(true);
    $response = aiCall($body, $apiKey);
    if (($response['stop_reason'] ?? '') === 'refusal') throw new AppError('הבינה סירבה לנתח את התמונה. אפשר להקליד', 422);
    $text = '';
    foreach ((array) ($response['content'] ?? []) as $block) if (($block['type'] ?? '') === 'text') { $text = (string) ($block['text'] ?? ''); break; }
    $parsed = json_decode($text, true);
    $products = is_array($parsed['products'] ?? null) ? $parsed['products'] : null;
    if ($products === null) throw new AppError('התשובה מהבינה לא בצורה הצפויה', 502);
    $clean = [];
    foreach ($products as $p) {
        $p = mb_substr(trim((string) $p), 0, 60);
        if ($p !== '' && !in_array($p, $clean, true)) $clean[] = $p;
    }
    $usage = $response['usage'] ?? [];
    logEvent('info', 'pantry-photo', count($clean) . ' מוצרים זוהו', [
        'bytes' => strlen($bytes), 'in_tokens' => (int) ($usage['input_tokens'] ?? 0), 'out_tokens' => (int) ($usage['output_tokens'] ?? 0),
    ], $user, (int) round((microtime(true) - $t0) * 1000));
    return array_slice($clean, 0, 40);
}
