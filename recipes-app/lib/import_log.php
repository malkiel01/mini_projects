<?php
/**
 * יומן ייבוא — כל הבאה של דף מהרשת, עם מה שהיה בדף ומה שחולץ ממנו.
 *
 * למה יומן נפרד מ-app_log: היומן הכללי שומר בכוונה רק מזהים — לא טקסט של
 * מתכונים. כאן הטקסט הוא כל העניין: כדי לאבחן למה מדף מסוים לא חולצו
 * שלבים, צריך לראות גם מה היה בדף (אילו JSON-LD, אילו כותרות, איזו צורה
 * ל-recipeInstructions) וגם מה יצא בפועל (הרכיבים, השלבים, הפענוח). לכל
 * שורה יש קישור למקור — כך משווים את השניים.
 *
 * מה נרשם: כל importPreview (ייבוא מהעורך, תצוגה מקדימה בסורק, ייבוא
 * בסורק, משיכה חוזרת של תיעוד), גם כשנכשל — עם האבחון אם הדף הובא, ועם
 * השגיאה. וגם שמירה מהעורך של טיוטה שיובאה (editor-save): כמה רכיבים
 * ושלבים נשמרו בסוף, מול כמה חולצו.
 *
 * נמחק אחרי 60 יום או 3000 שורות. למפתח בלבד, וגם דרך טוקן הצפייה
 * (logs.php?token=…&view=import&format=text — להדבקה בצ'אט).
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/errors.php';

const IMPORT_LOG_KINDS     = ['preview', 'scout-preview', 'scout-import', 'refresh', 'editor-save', 'web-open', 'web-save'];
const IMPORT_LOG_KEEP_DAYS = 60;
const IMPORT_LOG_KEEP_ROWS = 3000;
const IMPORT_LOG_MAX_JSON  = 200000;   // תקרה לשדה אחד — דף חריג לא ינפח את המסד

// ─────────────────────────────────────────────────────────────
// אבחון: מה היה בדף
// ─────────────────────────────────────────────────────────────

/**
 * מה בדף שיכול לשמש לחילוץ — בלי לחלץ: כמה JSON-LD ואילו @type, האם יש
 * Recipe ובאיזו צורה ההוראות שלו, Microdata, הכותרות (h1–h4), כמה רשימות,
 * og:title. זה מה שמאפשר להבין "למה לא חולצו שלבים" בלי להביא את הדף שוב.
 */
function importDiagnose(DOMDocument $doc, string $html): array {
    $xp = new DOMXPath($doc);
    $types = []; $blocks = 0; $bad = 0; $recipe = null;
    foreach ($doc->getElementsByTagName('script') as $script) {
        if (stripos((string) $script->getAttribute('type'), 'ld+json') === false) continue;
        $blocks++;
        $json = json_decode(trim($script->textContent), true);
        if (!is_array($json)) { $bad++; continue; }
        $stack = [$json]; $guard = 0;
        while ($stack && $guard++ < 400) {
            $n = array_pop($stack);
            if (!is_array($n)) continue;
            if (isset($n['@type'])) foreach ((array) $n['@type'] as $t) if (is_string($t) && count($types) < 30 && !in_array($t, $types, true)) $types[] = $t;
            foreach ($n as $v) if (is_array($v)) $stack[] = $v;
        }
        $recipe ??= importFindRecipeNode($json);
    }
    $ld = ['blocks' => $blocks, 'invalid' => $bad, 'types' => $types, 'recipe' => $recipe !== null];
    if ($recipe) {
        $ri = $recipe['recipeInstructions'] ?? null;
        if ($ri === null)        $shape = 'none';
        elseif (is_string($ri))  $shape = 'string(' . mb_strlen($ri) . ')';
        elseif (is_array($ri)) {
            $first = $ri[0] ?? null;
            $kind = is_array($first) ? (string) ($first['@type'] ?? 'object') : (is_string($first) ? 'string' : gettype($first));
            $shape = 'array(' . count($ri) . '): ' . $kind;
        } else $shape = gettype($ri);
        $ld['instructions'] = $shape;
        if (is_string($ri)) $ld['instructions_excerpt'] = mb_substr(importText($ri), 0, 300);
        $ld['ingredients'] = count((array) ($recipe['recipeIngredient'] ?? $recipe['ingredients'] ?? []));
        $ld['keys'] = array_slice(array_keys($recipe), 0, 40);
    }
    $heads = [];
    foreach ($xp->query('//h1|//h2|//h3|//h4') as $h) {
        $t = importText($h->textContent);
        if ($t === '') continue;
        $heads[] = $h->tagName . ': ' . mb_substr($t, 0, 60);
        if (count($heads) >= 30) break;
    }
    $meta = fn(string $p) => ($n = $xp->query('//meta[@property="' . $p . '" or @name="' . $p . '"]')->item(0)) ? importText($n->getAttribute('content')) : '';
    return [
        'html_bytes' => strlen($html),
        'jsonld'     => $ld,
        'microdata'  => [
            'scopes'       => $xp->query('//*[contains(@itemtype, "schema.org/Recipe")]')->length,
            'ingredients'  => $xp->query('//*[@itemprop="recipeIngredient"]')->length,
            'instructions' => $xp->query('//*[@itemprop="recipeInstructions"]')->length,
        ],
        'headings'   => $heads,
        'lists'      => ['ol' => $xp->query('//ol')->length, 'ul' => $xp->query('//ul')->length],
        'og_title'   => mb_substr($meta('og:title'), 0, 120),
        'site_name'  => mb_substr($meta('og:site_name'), 0, 80),
        'title_tag'  => mb_substr(importText(($n = $doc->getElementsByTagName('title')->item(0)) ? $n->textContent : ''), 0, 120),
    ];
}

// ─────────────────────────────────────────────────────────────
// כתיבה
// ─────────────────────────────────────────────────────────────

function importLogJson($v): ?string {
    if ($v === null || $v === [] ) return null;
    $s = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    if ($s === false) return null;
    return strlen($s) > IMPORT_LOG_MAX_JSON ? json_encode(['truncated' => true, 'bytes' => strlen($s)]) : $s;
}

/** שורה ביומן הייבוא. אף פעם לא זורק — יומן שמפיל ייבוא גרוע מיומן חסר. מחזיר id או 0. */
function importLogWrite(array $r): int {
    try {
        $kind = in_array($r['kind'] ?? '', IMPORT_LOG_KINDS, true) ? $r['kind'] : 'preview';
        $url = (string) ($r['source_url'] ?? '');
        $st = db()->prepare('INSERT INTO import_log (at, user_id, username, kind, source_url, final_url, host, ok, error, method, title,
                                 ingredients_n, steps_n, sections_n, images_n, videos_n, warnings, diag, extract, duration_ms, recipe_id, scout_id)
                             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $u = $r['user'] ?? null;
        $st->execute([
            nowIso(), $u ? (int) $u['id'] : null, $u ? ($u['username'] ?? null) : null, $kind,
            mb_substr($url, 0, 1000), isset($r['final_url']) && $r['final_url'] !== $url ? mb_substr((string) $r['final_url'], 0, 1000) : null,
            strtolower(parse_url($url, PHP_URL_HOST) ?: '?'),
            !empty($r['ok']) ? 1 : 0, isset($r['error']) ? mb_substr((string) $r['error'], 0, 500) : null,
            $r['method'] ?? null, isset($r['title']) ? mb_substr((string) $r['title'], 0, 200) : null,
            (int) ($r['ingredients_n'] ?? 0), (int) ($r['steps_n'] ?? 0), (int) ($r['sections_n'] ?? 0),
            (int) ($r['images_n'] ?? 0), (int) ($r['videos_n'] ?? 0),
            importLogJson($r['warnings'] ?? null), importLogJson($r['diag'] ?? null), importLogJson($r['extract'] ?? null),
            isset($r['duration_ms']) ? (int) $r['duration_ms'] : null,
            isset($r['recipe_id']) ? (int) $r['recipe_id'] : null, isset($r['scout_id']) ? (int) $r['scout_id'] : null,
        ]);
        importLogPrune();
        return (int) db()->lastInsertId();
    } catch (Throwable $e) {
        error_log('recipes-app import log: ' . $e->getMessage());
        return 0;
    }
}

/** ספירות מהטיוטה — אותן ספירות גם לחילוץ וגם לשמירה מהעורך. */
function importLogCounts(array $sections): array {
    $ings = 0; $steps = 0;
    foreach ($sections as $s) { $ings += count($s['ingredients'] ?? []); $steps += count($s['steps'] ?? []); }
    return ['ingredients_n' => $ings, 'steps_n' => $steps, 'sections_n' => count($sections)];
}

/** הצלחה: טיוטה שחולצה. $diag מ-importDiagnose. */
function importLogDraft(string $kind, string $url, array $draft, ?array $user, array $diag, int $durationMs, array $ctx = []): int {
    $parsed = [];
    foreach ($draft['sections'] as $s) foreach ($s['ingredients'] as $i) {
        $parsed[] = ['text' => $i['free_text'] ?? '', 'product' => $i['product'] ?? null,
                     'amount' => $i['amount_min'] ?? null, 'unit' => $i['unit'] ?? null];
    }
    $snap = $draft['snapshot'] ?? [];
    if (isset($snap['description'])) $snap['description'] = mb_substr((string) $snap['description'], 0, 500);
    return importLogWrite([
        'kind' => $kind, 'source_url' => $url, 'final_url' => $draft['source_url'] ?? $url, 'user' => $user, 'ok' => true,
        'method' => $draft['extracted_by'] ?? null, 'title' => $draft['title'] ?? '',
        'images_n' => count($draft['pending_media']['images'] ?? []), 'videos_n' => count($draft['pending_media']['videos'] ?? []),
        'warnings' => $draft['warnings'] ?? [], 'diag' => $diag,
        'extract' => ['raw' => $snap, 'parsed' => $parsed, 'servings' => $draft['servings'] ?? null, 'yield_text' => $draft['yield_text'] ?? null,
                      'work_minutes' => $draft['work_minutes'] ?? null, 'wait_minutes' => $draft['wait_minutes'] ?? null,
                      'tag_ids' => $draft['tag_ids'] ?? []],
        'duration_ms' => $durationMs,
    ] + importLogCounts($draft['sections'] ?? []) + $ctx);
}

/** כשל: הבאה או חילוץ. $diag כשהדף הובא ורק החילוץ נכשל. */
function importLogFailure(string $kind, string $url, string $error, ?array $user, ?array $diag, int $durationMs, array $ctx = []): int {
    return importLogWrite(['kind' => $kind, 'source_url' => $url, 'user' => $user, 'ok' => false, 'error' => $error,
                           'diag' => $diag, 'duration_ms' => $durationMs] + $ctx);
}

/** שמירה מהעורך של טיוטה שיובאה: מה נשמר בסוף (אחרי תיקוני המשתמש). */
function importLogSaved(string $url, int $recipeId, array $in, array $user, string $kind = 'editor-save'): int {
    $sections = is_array($in['sections'] ?? null) ? $in['sections'] : [];
    return importLogWrite(['kind' => $kind, 'source_url' => $url, 'user' => $user, 'ok' => true,
                           'title' => (string) ($in['title'] ?? ''), 'recipe_id' => $recipeId,
                           'method' => is_array($in['snapshot'] ?? null) ? ($in['snapshot']['method'] ?? null) : null]
                          + importLogCounts($sections));
}

/** אחרי שהייבוא נשמר כמתכון — מקשרים את השורה אליו. */
function importLogSetRecipe(int $logId, int $recipeId): void {
    if ($logId <= 0) return;
    try { db()->prepare('UPDATE import_log SET recipe_id = ? WHERE id = ?')->execute([$recipeId, $logId]); } catch (Throwable $e) {}
}

function importLogPrune(bool $force = false): void {
    if (!$force && random_int(1, 50) !== 1) return;
    try {
        $pdo = db();
        $pdo->prepare('DELETE FROM import_log WHERE at < ?')->execute([gmdate('Y-m-d\TH:i:s\Z', time() - IMPORT_LOG_KEEP_DAYS * 86400)]);
        $pdo->exec('DELETE FROM import_log WHERE id <= (SELECT id FROM import_log ORDER BY id DESC LIMIT 1 OFFSET ' . IMPORT_LOG_KEEP_ROWS . ')');
    } catch (Throwable $e) { error_log('recipes-app import log prune: ' . $e->getMessage()); }
}

// ─────────────────────────────────────────────────────────────
// קריאה
// ─────────────────────────────────────────────────────────────

/** $filters: host, kind, ok ('1'/'0'), q (בשם/בכתובת/בשגיאה), before (id), since. מהחדש לישן. */
function importLogList(array $filters = [], int $limit = 50): array {
    $where = []; $params = [];
    if (!empty($filters['host']))  { $where[] = 'host = ?';  $params[] = mb_substr((string) $filters['host'], 0, 120); }
    if (!empty($filters['kind']) && in_array($filters['kind'], IMPORT_LOG_KINDS, true)) { $where[] = 'kind = ?'; $params[] = $filters['kind']; }
    if (isset($filters['ok']) && $filters['ok'] !== '' && $filters['ok'] !== null) { $where[] = 'ok = ?'; $params[] = (int) (bool) (int) $filters['ok']; }
    if (!empty($filters['before'])) { $where[] = 'id < ?'; $params[] = (int) $filters['before']; }
    if (!empty($filters['since']))  { $where[] = 'at >= ?'; $params[] = mb_substr((string) $filters['since'], 0, 25); }
    if (!empty($filters['recipe_id'])) { $where[] = 'recipe_id = ?'; $params[] = (int) $filters['recipe_id']; }
    if (!empty($filters['q'])) {
        $where[] = '(title LIKE ? OR source_url LIKE ? OR error LIKE ?)';
        $like = '%' . mb_substr((string) $filters['q'], 0, 80) . '%';
        array_push($params, $like, $like, $like);
    }
    $limit = max(1, min(200, $limit));
    $st = db()->prepare('SELECT * FROM import_log' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY id DESC LIMIT ' . $limit);
    $st->execute($params);
    return array_map('importLogRow', $st->fetchAll());
}

function importLogRow(array $r): array {
    $row = [
        'id' => (int) $r['id'], 'at' => $r['at'], 'user_id' => $r['user_id'] !== null ? (int) $r['user_id'] : null,
        'username' => $r['username'], 'kind' => $r['kind'], 'source_url' => $r['source_url'], 'final_url' => $r['final_url'],
        'host' => $r['host'], 'ok' => (bool) $r['ok'], 'error' => $r['error'], 'method' => $r['method'], 'title' => $r['title'],
        'ingredients_n' => (int) $r['ingredients_n'], 'steps_n' => (int) $r['steps_n'], 'sections_n' => (int) $r['sections_n'],
        'images_n' => (int) $r['images_n'], 'videos_n' => (int) $r['videos_n'],
        'warnings' => $r['warnings'] !== null ? (json_decode($r['warnings'], true) ?: []) : [],
        'diag' => $r['diag'] !== null ? json_decode($r['diag'], true) : null,
        'extract' => $r['extract'] !== null ? json_decode($r['extract'], true) : null,
        'duration_ms' => $r['duration_ms'] !== null ? (int) $r['duration_ms'] : null,
        'recipe_id' => $r['recipe_id'] !== null ? (int) $r['recipe_id'] : null,
        'scout_id' => $r['scout_id'] !== null ? (int) $r['scout_id'] : null,
    ];
    $row['text'] = importLogEntryText($row);
    return $row;
}

function importLogStats(): array {
    $pdo = db();
    $day = gmdate('Y-m-d\TH:i:s\Z', time() - 86400);
    $st = $pdo->prepare('SELECT COUNT(*) FROM import_log WHERE at >= ? AND ok = 0'); $st->execute([$day]);
    return [
        'rows'        => (int) $pdo->query('SELECT COUNT(*) FROM import_log')->fetchColumn(),
        'failed_24h'  => (int) $st->fetchColumn(),
        'hosts'       => array_column($pdo->query('SELECT host FROM import_log GROUP BY host ORDER BY host')->fetchAll(), 'host'),
        'kinds'       => IMPORT_LOG_KINDS,
        'keep_days'   => IMPORT_LOG_KEEP_DAYS,
        'keep_rows'   => IMPORT_LOG_KEEP_ROWS,
    ];
}

// ─────────────────────────────────────────────────────────────
// טקסט — להדבקה בצ'אט
// ─────────────────────────────────────────────────────────────

const IMPORT_LOG_KIND_HE = ['preview' => 'ייבוא מהעורך', 'scout-preview' => 'תצוגה מקדימה בסורק', 'scout-import' => 'ייבוא בסורק',
                            'refresh' => 'משיכה חוזרת', 'editor-save' => 'שמירה מהעורך',
                            'web-open' => 'פתיחה מהחיפוש', 'web-save' => 'שמירה מהחיפוש'];

/** שורה אחת ביומן → בלוק טקסט: כותרת, מקור, אבחון, ואז הרכיבים והשלבים כלשונם. */
function importLogEntryText(array $r): string {
    $o = [];
    $o[] = sprintf('=== #%d  %s  %s  %s  %s%s', $r['id'], str_replace('T', ' ', substr($r['at'], 0, 19)),
                   IMPORT_LOG_KIND_HE[$r['kind']] ?? $r['kind'], $r['ok'] ? 'הצליח' : 'נכשל',
                   $r['username'] ?? '-', $r['duration_ms'] !== null ? ' (' . $r['duration_ms'] . 'ms)' : '');
    $o[] = 'מקור: ' . $r['source_url'];
    if ($r['final_url']) $o[] = 'הופנה אל: ' . $r['final_url'];
    if (!$r['ok']) $o[] = 'שגיאה: ' . $r['error'];
    if ($r['recipe_id']) $o[] = 'מתכון: #' . $r['recipe_id'] . ($r['scout_id'] ? '  (מועמד בסורק #' . $r['scout_id'] . ')' : '');
    elseif ($r['scout_id']) $o[] = 'מועמד בסורק: #' . $r['scout_id'];
    if (in_array($r['kind'], ['editor-save', 'web-save'], true)) {
        $o[] = sprintf('נשמר: "%s" · %d רכיבים · %d שלבים ב-%d חלקים', $r['title'], $r['ingredients_n'], $r['steps_n'], $r['sections_n']);
        return implode("\n", $o) . "\n";
    }
    if ($r['ok']) {
        $x = $r['extract'] ?? [];
        $yield = $x['servings'] ?? null ? $x['servings'] . ' מנות' : (($x['yield_text'] ?? '') !== '' ? $x['yield_text'] : 'בלי מנות');
        $o[] = sprintf('חולץ ב-%s: "%s" · %d רכיבים · %d שלבים ב-%d חלקים · %d תמונות · %d סרטונים · %s · עבודה %s · המתנה %s',
                       $r['method'] ?? '?', $r['title'], $r['ingredients_n'], $r['steps_n'], $r['sections_n'], $r['images_n'], $r['videos_n'],
                       $yield, $x['work_minutes'] ?? '-', $x['wait_minutes'] ?? '-');
        if ($r['warnings']) $o[] = 'אזהרות: ' . implode(' · ', $r['warnings']);
    }
    if ($d = $r['diag']) {
        $ld = $d['jsonld'] ?? [];
        $o[] = sprintf('בדף: HTTP %s · %s בתים · JSON-LD: %d בלוקים%s, @type=[%s]%s · Microdata: %d/%d/%d · כותרות: %d · רשימות: ol %d, ul %d · og:title="%s"',
                       $d['http_status'] ?? '?', number_format((int) ($d['html_bytes'] ?? 0)), (int) ($ld['blocks'] ?? 0),
                       !empty($ld['invalid']) ? ' (' . $ld['invalid'] . ' לא תקינים)' : '',
                       implode(', ', $ld['types'] ?? []),
                       !empty($ld['recipe']) ? sprintf(', Recipe: instructions=%s, ingredients=%d', $ld['instructions'] ?? '?', (int) ($ld['ingredients'] ?? 0)) : ', בלי Recipe',
                       (int) ($d['microdata']['scopes'] ?? 0), (int) ($d['microdata']['ingredients'] ?? 0), (int) ($d['microdata']['instructions'] ?? 0),
                       count($d['headings'] ?? []), (int) ($d['lists']['ol'] ?? 0), (int) ($d['lists']['ul'] ?? 0), $d['og_title'] ?? '');
        if (!empty($ld['instructions_excerpt'])) $o[] = 'ההוראות במקור (תחילה): ' . $ld['instructions_excerpt'];
        if (!empty($d['headings'])) $o[] = 'כותרות בדף: ' . implode(' | ', $d['headings']);
    }
    if ($r['ok'] && !empty($r['extract'])) {
        $x = $r['extract'];
        $raw = $x['raw'] ?? [];
        if (!empty($raw['author']) || !empty($raw['publisher'])) $o[] = 'קרדיט: ' . trim(($raw['author'] ?? '') . ' · ' . ($raw['publisher'] ?? ''), ' ·');
        if (!empty($raw['description'])) $o[] = 'תיאור: ' . $raw['description'];
        if (!empty($x['parsed'])) {
            $o[] = 'רכיבים (כלשונם → הפענוח):';
            foreach ($x['parsed'] as $i) {
                $calc = $i['product'] !== null && $i['product'] !== '' ? ' → ' . $i['product'] . ($i['amount'] !== null ? ' ' . rtrim(rtrim(number_format((float) $i['amount'], 2, '.', ''), '0'), '.') . ' ' . ($i['unit'] ?? '') : '') : ' → (לא פוענח)';
                $o[] = '  - ' . $i['text'] . $calc;
            }
        } else $o[] = 'רכיבים: אין';
        $secs = $raw['sections'] ?? [];
        if ($secs) {
            $o[] = 'שלבים (כלשונם):';
            foreach ($secs as $s) {
                if (($s['name'] ?? '') !== '') $o[] = '  [' . $s['name'] . ']';
                foreach ($s['steps'] ?? [] as $n => $st) $o[] = '  ' . ($n + 1) . '. ' . $st;
            }
        } else $o[] = 'שלבים: אין';
        if (!empty($raw['images'])) $o[] = 'תמונות: ' . implode(' , ', array_slice($raw['images'], 0, 5));
        if (!empty($raw['videos'])) $o[] = 'סרטונים: ' . implode(' , ', $raw['videos']);
        if (!empty($raw['categories'])) $o[] = 'קטגוריות במקור: ' . implode(', ', $raw['categories']);
    }
    return implode("\n", $o) . "\n";
}

/** כל היומן כטקסט — כרונולוגי, בלוק לכל הבאה. */
function importLogAsText(array $rows): string {
    $out = [];
    foreach (array_reverse($rows) as $r) $out[] = $r['text'] ?? importLogEntryText($r);
    return implode("\n", $out);
}
