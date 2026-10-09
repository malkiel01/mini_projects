<?php
/**
 * סריקת מתכון מתמונה — פתקים בכתב יד, דפים מודפסים, גזירים מעיתון.
 *
 * המחזור:
 *   1. משתמש מעלה עד SCAN_MAX_IMAGES תמונות (scan.php), עם הערה ופרטי/ציבורי.
 *      הסריקה נשמרת כ"ממתינה" (scans.status = 'pending'). התמונות ב-data/scans/
 *      — מחוץ למה שהשרת מגיש ישירות (data/.htaccess), ומוחרגות מהפריסה.
 *   2. טעינת קרדיטים לבינה — עדיין בפיתוח. לכן כל סריקה ממתינה לאישור המפתח.
 *   3. המפתח, ב"סריקות ממתינות":
 *      - "סרוק בבינה" (scanRunAi) — כשיש מפתח API וקרדיט;
 *      - או ידנית: מוריד קובץ (scanExport, התמונות בתוכו), שולח לקלוד בצ'אט,
 *        ומעלה את הקובץ שחזר (scanApply). לקלוד אפשר גם דרך טוקן היומן
 *        (logs.php?view=scans);
 *      - או דוחה, עם סיבה (scanReject).
 *   4. המתכון נוצר אצל מי שסרק, בפרטיות שהוא בחר, ותמונות הסריקה מצורפות
 *      אליו (אם ביקש). קבצי הסריקה נמחקים — הם נשארים רק כמדיה של המתכון.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/errors.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/recipes.php';
require_once __DIR__ . '/media.php';
require_once __DIR__ . '/importer.php';
require_once __DIR__ . '/ai.php';
require_once __DIR__ . '/secrets.php';
require_once __DIR__ . '/log.php';

const SCAN_FORMAT        = 'recipes-scan-1';
const SCAN_MAX_IMAGES    = 6;
const SCAN_MAX_BYTES     = 10 * 1024 * 1024;   // לתמונה; הדפדפן מקטין לפני ההעלאה, זו רק תקרה
const SCAN_MAX_PENDING   = 5;                  // סריקות ממתינות למשתמש — עד שהמפתח מטפל
const SCAN_EXPORT_BATCH  = 10;                 // סריקות בקובץ אחד (התמונות בתוכו)
const SCAN_TYPES         = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];

const SCAN_INSTRUCTIONS = 'בכל פריט — תמונות של מתכון: פתק בכתב יד, דף מודפס, גזיר. קרא את הכתב (גם כתב יד) ובנה מתכון. ' .
    'כתוב בדיוק מה שכתוב — אל תמציא כמויות, יחידות, שלבים או רכיבים. כמות בלי יחידה ("1 קמח") — כמו שכתוב. ' .
    'מילה שלא ברור מה היא: הניחוש הסביר ואחריו [?]. שברים ככתוב: "2½ כפות שמרים". קיצורים — במלואם ("כ\' ק\'" → "כפית"). ' .
    'כותרת משנה בפתק ("לבצק:", "תיבול:", "לקישוט:") — חלק נפרד (sections) בשם הזה. ' .
    'פתק עם רכיבים בלבד, בלי הוראות הכנה — steps ריק, ובלי להמציא שלבים. ' .
    'כמה תמונות באותו פריט — אותו מתכון (המשך, צד שני של הפתק), אלא אם ההערה אומרת אחרת; ' .
    'ואם בבירור יש בתמונות כמה מתכונים שונים — כמה ב-recipes. השם: מהכותרת בפתק; אין — שם קצר לפי הרכיבים. ' .
    'החזר JSON: {"format":"' . SCAN_FORMAT . '","items":[{"id":"s:12","recipes":[{"title":"…","servings":null,"yield_text":"","difficulty":null,' .
    '"work_minutes":null,"wait_minutes":null,"tips":"","sections":[{"name":"","ingredients":["2 כוסות קמח"],"steps":["…"]}]}]}]}. ' .
    'אי אפשר לקרוא, או שאין בתמונות מתכון — {"id":"s:12","reject":"הסיבה, בעברית, למשתמש"}. difficulty: easy / medium / hard או null.';

function scanStoreDir(): string {
    $d = dirname(DB_FILE) . '/scans';
    if (!is_dir($d) && !@mkdir($d, 0775, true) && !is_dir($d)) throw new AppError('תיקיית הסריקות אינה זמינה', 500);
    return $d;
}

function scanRow(int $id): ?array {
    $st = db()->prepare('SELECT s.*, u.username, u.display_name FROM scans s JOIN users u ON u.id = s.user_id WHERE s.id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    $st->closeCursor();
    return $r ?: null;
}

/** הסריקה למי שמותר לו: הבעלים או המפתח. */
function scanFor(int $id, array $user): array {
    $r = scanRow($id);
    if (!$r || ((int) $r['user_id'] !== (int) $user['id'] && !isDeveloper($user))) throw new AppError('הסריקה אינה קיימת', 404);
    return $r;
}

function scanPublic(array $r, bool $withUser = false): array {
    $imgs = json_decode((string) $r['images'], true) ?: [];
    $out = [
        'id' => (int) $r['id'], 'status' => $r['status'], 'visibility' => $r['visibility'], 'note' => (string) $r['note'],
        'attach' => (bool) $r['attach'], 'images' => count($imgs), 'recipe_id' => $r['recipe_id'] !== null ? (int) $r['recipe_id'] : null,
        'recipe_ids' => array_map('intval', json_decode((string) ($r['recipe_ids'] ?? ''), true) ?: ($r['recipe_id'] !== null ? [(int) $r['recipe_id']] : [])),
        'message' => $r['message'], 'method' => $r['method'], 'created_at' => $r['created_at'], 'done_at' => $r['done_at'],
    ];
    if ($withUser) $out['user'] = ['id' => (int) $r['user_id'], 'username' => $r['username'], 'display_name' => $r['display_name']];
    return $out;
}

// ─────────────────────────────────────────────────────────────
// המשתמש: העלאה, רשימה, ביטול
// ─────────────────────────────────────────────────────────────

/** $files — מערך של [tmp_name, size, error] (מ-$_FILES, אחרי נרמול). */
function scanCreate(array $user, array $files, string $note, string $visibility, bool $attach): array {
    $files = array_values(array_filter($files, fn($f) => ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE));
    if (!$files) throw new AppError('לא נבחרו תמונות');
    if (count($files) > SCAN_MAX_IMAGES) throw new AppError('עד ' . SCAN_MAX_IMAGES . ' תמונות לסריקה אחת');
    $st = db()->prepare("SELECT COUNT(*) FROM scans WHERE user_id = ? AND status = 'pending'");
    $st->execute([(int) $user['id']]);
    if ((int) $st->fetchColumn() >= SCAN_MAX_PENDING) {
        throw new AppError('יש לך כבר ' . SCAN_MAX_PENDING . ' סריקות שממתינות לאישור. אפשר להעלות עוד כשהן יטופלו', 429);
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $checked = [];
    foreach ($files as $f) {
        if (($f['error'] ?? 0) !== UPLOAD_ERR_OK) throw new AppError('אחת התמונות לא הגיעה בשלמותה — לנסות שוב');
        if ((int) $f['size'] > SCAN_MAX_BYTES) throw new AppError('תמונה גדולה מדי (עד ' . humanBytes(SCAN_MAX_BYTES) . ')', 413);
        $mime = (string) $finfo->file($f['tmp_name']);
        if (!isset(SCAN_TYPES[$mime])) throw new AppError('רק תמונות (JPG, PNG, WEBP)');
        $checked[] = [$f['tmp_name'], SCAN_TYPES[$mime]];
    }
    $dir = scanStoreDir();
    $names = [];
    try {
        foreach ($checked as [$tmp, $ext]) {
            $name = bin2hex(random_bytes(16)) . '.' . $ext;
            if (!(is_uploaded_file($tmp) ? move_uploaded_file($tmp, "$dir/$name") : copy($tmp, "$dir/$name"))) throw new AppError('שמירת התמונה נכשלה', 500);
            @chmod("$dir/$name", 0640);
            $names[] = $name;
        }
        db()->prepare('INSERT INTO scans (user_id, status, visibility, note, attach, images, created_at) VALUES (?,?,?,?,?,?,?)')
            ->execute([(int) $user['id'], 'pending', $visibility === 'public' ? 'public' : 'private', mb_substr(trim($note), 0, 1000),
                       $attach ? 1 : 0, json_encode($names), nowIso()]);
    } catch (Throwable $e) {
        foreach ($names as $n) @unlink("$dir/$n");
        throw $e;
    }
    $id = (int) db()->lastInsertId();
    logEvent('info', 'scan-create', count($names) . ' תמונות', ['scan_id' => $id, 'images' => count($names)], $user);
    return scanPublic(scanRow($id));
}

function scansMine(array $user): array {
    $st = db()->prepare('SELECT s.*, u.username, u.display_name FROM scans s JOIN users u ON u.id = s.user_id WHERE s.user_id = ? ORDER BY s.id DESC LIMIT 50');
    $st->execute([(int) $user['id']]);
    return ['scans' => array_map('scanPublic', $st->fetchAll()), 'max_pending' => SCAN_MAX_PENDING, 'max_images' => SCAN_MAX_IMAGES];
}

/** ביטול סריקה שעוד ממתינה — של הבעלים בלבד. */
function scanCancel(int $id, array $user): void {
    $r = scanRow($id);
    if (!$r || (int) $r['user_id'] !== (int) $user['id']) throw new AppError('הסריקה אינה קיימת', 404);
    if ($r['status'] !== 'pending') throw new AppError('הסריקה כבר טופלה');
    scanDeleteFiles($r);
    db()->prepare('DELETE FROM scans WHERE id = ?')->execute([$id]);
}

function scanDeleteFiles(array $r): void {
    foreach (json_decode((string) $r['images'], true) ?: [] as $n) @unlink(scanStoreDir() . '/' . basename((string) $n));
}

/** קובץ תמונה אחד של סריקה: [נתיב, mime]. */
function scanImagePath(array $r, int $i): array {
    $imgs = json_decode((string) $r['images'], true) ?: [];
    if (!isset($imgs[$i])) throw new AppError('אין תמונה כזו', 404);
    $path = scanStoreDir() . '/' . basename((string) $imgs[$i]);
    if (!is_file($path)) throw new AppError('התמונה כבר נמחקה (הסריקה טופלה)', 404);
    $mime = array_search(pathinfo($path, PATHINFO_EXTENSION), SCAN_TYPES, true) ?: 'application/octet-stream';
    return [$path, $mime];
}

// ─────────────────────────────────────────────────────────────
// המפתח: התור, דחייה, בינה, ייצוא והחלה ידניים
// ─────────────────────────────────────────────────────────────

function scansPending(array $developer): array {
    requireDeveloper($developer);
    $rows = db()->query("SELECT s.*, u.username, u.display_name FROM scans s JOIN users u ON u.id = s.user_id WHERE s.status = 'pending' ORDER BY s.id")->fetchAll();
    $done = db()->query("SELECT s.*, u.username, u.display_name FROM scans s JOIN users u ON u.id = s.user_id WHERE s.status != 'pending' ORDER BY s.done_at DESC LIMIT 20")->fetchAll();
    return ['pending' => array_map(fn($r) => scanPublic($r, true), $rows), 'recent' => array_map(fn($r) => scanPublic($r, true), $done),
            'ai_available' => aiAvailable()];
}

function scanPendingRow(int $id, array $developer): array {
    requireDeveloper($developer);
    $r = scanRow($id);
    if (!$r) throw new AppError('הסריקה אינה קיימת', 404);
    if ($r['status'] !== 'pending') throw new AppError('הסריקה כבר טופלה');
    return $r;
}

function scanReject(int $id, string $reason, array $developer, string $method = 'manual'): array {
    $r = scanPendingRow($id, $developer);
    $reason = mb_substr(trim($reason), 0, 300) ?: 'לא הצלחנו לקרוא מתכון מהתמונות';
    scanDeleteFiles($r);
    db()->prepare("UPDATE scans SET status = 'rejected', message = ?, method = ?, done_at = ? WHERE id = ?")->execute([$reason, $method, nowIso(), $id]);
    logEvent('info', 'scan-reject', $reason, ['scan_id' => $id], $developer);
    return scanPublic(scanRow($id), true);
}

/** ייצוא לניסוח בחוץ. $inline — התמונות בתוך הקובץ (base64); אחרת $urlFor נותן קישור לכל תמונה. */
function scanExport(array $developer, bool $inline = true, ?callable $urlFor = null): array {
    requireDeveloper($developer);
    $rows = db()->query("SELECT s.*, u.username, u.display_name FROM scans s JOIN users u ON u.id = s.user_id WHERE s.status = 'pending' ORDER BY s.id LIMIT " . SCAN_EXPORT_BATCH)->fetchAll();
    $total = (int) db()->query("SELECT COUNT(*) FROM scans WHERE status = 'pending'")->fetchColumn();
    $items = [];
    foreach ($rows as $r) {
        $imgs = [];
        foreach (array_keys(json_decode((string) $r['images'], true) ?: []) as $i) {
            try { [$path, $mime] = scanImagePath($r, $i); } catch (AppError $e) { continue; }
            $imgs[] = $inline ? ['mime' => $mime, 'data' => base64_encode((string) file_get_contents($path))]
                              : ['mime' => $mime, 'url' => $urlFor ? $urlFor((int) $r['id'], $i) : null];
        }
        $items[] = ['id' => 's:' . $r['id'], 'user' => $r['display_name'] ?: $r['username'], 'visibility' => $r['visibility'],
                    'note' => (string) $r['note'], 'created_at' => $r['created_at'], 'images' => $imgs];
    }
    return ['format' => SCAN_FORMAT, 'instructions' => SCAN_INSTRUCTIONS, 'exported_at' => nowIso(), 'total_waiting' => $total, 'items' => $items];
}

/** החלה של קובץ שחזר: לכל פריט — מתכון (נוצר אצל מי שסרק) או דחייה. */
function scanApply(array $data, array $developer): array {
    requireDeveloper($developer);
    if (($data['format'] ?? '') !== SCAN_FORMAT || !is_array($data['items'] ?? null)) {
        throw new AppError('זה לא קובץ סריקות של האפליקציה (חסר format: ' . SCAN_FORMAT . ' או items)');
    }
    $results = [];
    $applied = 0;
    foreach (array_slice($data['items'], 0, 50) as $it) {
        $id = (string) ($it['id'] ?? '');
        $res = ['id' => $id];
        if (!preg_match('/^s:(\d+)$/', $id, $m)) { $results[] = $res + ['status' => 'missing', 'message' => 'מזהה לא מוכר']; continue; }
        try {
            if (isset($it['reject'])) {
                scanReject((int) $m[1], (string) $it['reject'], $developer);
                $results[] = $res + ['status' => 'rejected', 'message' => 'נדחתה: ' . mb_substr((string) $it['reject'], 0, 120)];
                continue;
            }
            $list = is_array($it['recipes'] ?? null) ? $it['recipes'] : (is_array($it['recipe'] ?? null) ? [$it['recipe']] : []);
            $rids = scanFinish((int) $m[1], $list, $developer, 'manual');
            $applied++;
            $results[] = $res + ['status' => 'applied', 'recipe_ids' => $rids,
                                 'title' => implode(' · ', array_map(fn($x) => (string) ($x['title'] ?? ''), $list)),
                                 'message' => count($rids) > 1 ? count($rids) . ' מתכונים נוצרו' : 'המתכון נוצר'];
        } catch (AppError $e) {
            $results[] = $res + ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
    $waiting = (int) db()->query("SELECT COUNT(*) FROM scans WHERE status = 'pending'")->fetchColumn();
    logEvent('info', 'scan-apply', "סריקות: $applied מתוך " . count($results), ['applied' => $applied, 'items' => count($results)], $developer);
    return ['results' => $results, 'applied' => $applied, 'waiting' => $waiting];
}

/**
 * מתכונים מסריקה (בדרך כלל אחד; פתק עם שני מתכונים — שניים) → נשמרים אצל מי
 * שסרק, בפרטיות שבחר. התמונות מצורפות לכל אחד (אם ביקש ויש לו מקום), וקבצי
 * הסריקה נמחקים. הכול נבדק לפני ששומרים משהו — כך לא נשאר חצי.
 */
function scanFinish(int $id, array $recipes, array $developer, string $method): array {
    $r = scanPendingRow($id, $developer);
    if (!$recipes) throw new AppError('בפריט אין מתכון');
    $drafts = array_map('scanToDraft', array_slice(array_values($recipes), 0, 5));
    $st = db()->prepare('SELECT id, username, email, display_name, role, email_verified, blocked, storage_used FROM users WHERE id = ?');
    $st->execute([(int) $r['user_id']]);
    $owner = $st->fetch();
    $st->closeCursor();
    if (!$owner) throw new AppError('מי שסרק כבר אינו במערכת', 404);

    $rids = [];
    foreach ($drafts as $draft) {
        $draft['visibility'] = $r['visibility'];
        $rid = saveRecipe($draft, $owner);
        $rids[] = $rid;
        if ($r['attach']) {
            foreach (array_keys(json_decode((string) $r['images'], true) ?: []) as $i) {
                try { [$path, $mime] = scanImagePath($r, $i); scanAttachImage($rid, $owner, $path, $mime); }
                catch (AppError $e) { break; }   // נגמר המקום — המתכון נשמר, בלי שאר התמונות
            }
        }
    }
    scanDeleteFiles($r);
    db()->prepare("UPDATE scans SET status = 'done', recipe_id = ?, recipe_ids = ?, method = ?, message = NULL, done_at = ? WHERE id = ?")
        ->execute([$rids[0], json_encode($rids), $method, nowIso(), $id]);
    logEvent('info', 'scan-done', implode(' · ', array_column($drafts, 'title')), ['scan_id' => $id, 'recipe_ids' => $rids, 'method' => $method], $developer);
    return $rids;
}

/** המתכון מהקובץ/מהבינה → מבנה ש-saveRecipe מקבל. רכיבים מפוענחים כמו בייבוא (הטקסט החופשי נשמר). */
function scanToDraft(array $in): array {
    $title = mb_substr(trim((string) ($in['title'] ?? '')), 0, 120);
    if ($title === '') throw new AppError('למתכון אין שם');
    $sections = [];
    foreach (array_slice((array) ($in['sections'] ?? []), 0, 12) as $s) {
        $ings = array_values(array_filter(array_map(fn($x) => mb_substr(trim((string) $x), 0, 300), (array) ($s['ingredients'] ?? [])), fn($x) => $x !== ''));
        $steps = array_values(array_filter(array_map(fn($x) => mb_substr(trim((string) $x), 0, 2000), (array) ($s['steps'] ?? [])), fn($x) => $x !== ''));
        if (!$ings && !$steps) continue;
        $sections[] = ['name' => mb_substr(trim((string) ($s['name'] ?? '')), 0, 80),
                       'ingredients' => array_map('importParseIngredient', array_slice($ings, 0, 80)),
                       'steps' => array_map(fn($t) => ['text' => $t], array_slice($steps, 0, AI_MAX_STEPS))];
    }
    if (!$sections) throw new AppError('במתכון אין רכיבים ולא שלבים');
    $int = fn($v) => is_numeric($v) && (int) $v > 0 ? (int) $v : null;
    return [
        'title' => $title, 'visibility' => 'private',
        'servings' => $int($in['servings'] ?? null), 'yield_text' => mb_substr(trim((string) ($in['yield_text'] ?? '')), 0, 80),
        'difficulty' => in_array($in['difficulty'] ?? null, DIFFICULTIES, true) ? $in['difficulty'] : null,
        'work_minutes' => $int($in['work_minutes'] ?? null), 'wait_minutes' => $int($in['wait_minutes'] ?? null),
        'tips' => mb_substr(trim((string) ($in['tips'] ?? '')), 0, 2000),
        'tag_ids' => importGuessTags([$title]), 'sections' => $sections, 'comments_open' => true,
    ];
}

/** תמונת סריקה → מדיה של המתכון (נספרת במקום של מי שסרק, כמו העלאה רגילה). */
function scanAttachImage(int $recipeId, array $owner, string $path, string $mime): void {
    $bytes = (int) filesize($path);
    if (storageUsedReal((int) $owner['id']) + $bytes > effectiveLimit($owner, 'quota_bytes')) throw new AppError('אין מקום', 507);
    if (mediaCount($recipeId, 'image') >= MAX_IMAGES_PER_RECIPE) throw new AppError('הגבול לתמונות', 409);
    if (!is_dir(MEDIA_DIR)) @mkdir(MEDIA_DIR, 0775, true);
    $name = bin2hex(random_bytes(16)) . '.' . (SCAN_TYPES[$mime] ?? 'jpg');
    if (!copy($path, MEDIA_DIR . '/' . $name)) throw new AppError('העתקת התמונה נכשלה', 500);
    @chmod(MEDIA_DIR . '/' . $name, 0644);
    $pdo = db();
    $st = $pdo->prepare('SELECT COALESCE(MAX(position),0)+1 FROM media WHERE recipe_id = ?');
    $st->execute([$recipeId]);
    $pos = (int) $st->fetchColumn();
    $st->closeCursor();
    $pdo->prepare('INSERT INTO media (recipe_id, uploader_id, kind, source, path_or_url, mime, bytes, position, created_at) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$recipeId, (int) $owner['id'], 'image', 'upload', $name, $mime, $bytes, $pos, nowIso()]);
    $mid = (int) $pdo->lastInsertId();
    $pdo->prepare('UPDATE recipes SET main_media_id = COALESCE(main_media_id, ?) WHERE id = ?')->execute([$mid, $recipeId]);
    $pdo->prepare('UPDATE users SET storage_used = ? WHERE id = ?')->execute([storageUsedReal((int) $owner['id']), (int) $owner['id']]);
}

// ─────────────────────────────────────────────────────────────
// סריקה בבינה — כשיש מפתח וקרדיט
// ─────────────────────────────────────────────────────────────

function scanRunAi(int $id, array $developer): array {
    $r = scanPendingRow($id, $developer);
    $apiKey = secretGet('anthropic_api_key');
    if ($apiKey === null) throw new AppError('אין מפתח API לבינה (הגדרות ציבוריות). אפשר לסרוק ידנית — הורדת קובץ ושליחה לקלוד', 409);
    $content = [];
    foreach (array_keys(json_decode((string) $r['images'], true) ?: []) as $i) {
        [$path, $mime] = scanImagePath($r, $i);
        $content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => base64_encode((string) file_get_contents($path))]];
    }
    $content[] = ['type' => 'text', 'text' => SCAN_INSTRUCTIONS . "\n\nהפריט: s:$id. הערת המשתמש: " . ((string) $r['note'] ?: '(אין)') .
                  "\nהחזר רק את הפריט — {\"recipes\":[…],\"reject\":null} או {\"recipes\":[],\"reject\":\"…\"}."];
    $str = ['type' => 'string'];
    $int = ['type' => ['integer', 'null']];
    $body = [
        'model' => AI_MODEL, 'max_tokens' => AI_MAX_TOKENS, 'fallbacks' => 'default',
        'output_config' => ['effort' => 'medium', 'format' => ['type' => 'json_schema', 'schema' => [
            'type' => 'object', 'additionalProperties' => false,
            'properties' => [
                'reject' => ['type' => ['string', 'null']],
                'recipes' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false,
                    'required' => ['title', 'servings', 'yield_text', 'difficulty', 'work_minutes', 'wait_minutes', 'tips', 'sections'],
                    'properties' => [
                        'title' => $str, 'servings' => $int, 'yield_text' => $str, 'difficulty' => ['type' => ['string', 'null']],
                        'work_minutes' => $int, 'wait_minutes' => $int, 'tips' => $str,
                        'sections' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false,
                            'required' => ['name', 'ingredients', 'steps'],
                            'properties' => ['name' => $str, 'ingredients' => ['type' => 'array', 'items' => $str], 'steps' => ['type' => 'array', 'items' => $str]]]],
                    ]]],
            ],
            'required' => ['reject', 'recipes'],
        ]]],
        'messages' => [['role' => 'user', 'content' => $content]],
    ];
    $t0 = microtime(true);
    $response = aiCall($body, $apiKey);
    if (($response['stop_reason'] ?? '') === 'refusal') throw new AppError('הבינה סירבה לקרוא את התמונות', 422);
    $text = '';
    foreach ((array) ($response['content'] ?? []) as $block) if (($block['type'] ?? '') === 'text') { $text = (string) ($block['text'] ?? ''); break; }
    $out = json_decode($text, true);
    if (!is_array($out)) throw new AppError('התשובה מהבינה לא בצורה הצפויה', 502);
    $usage = $response['usage'] ?? [];
    logEvent('info', 'scan-ai', '', ['scan_id' => $id, 'in_tokens' => (int) ($usage['input_tokens'] ?? 0), 'out_tokens' => (int) ($usage['output_tokens'] ?? 0)],
             $developer, (int) round((microtime(true) - $t0) * 1000));
    if (!empty($out['reject']) || empty($out['recipes'])) return ['status' => 'rejected', 'scan' => scanReject($id, (string) ($out['reject'] ?? ''), $developer, 'ai')];
    return ['status' => 'done', 'recipe_ids' => scanFinish($id, $out['recipes'], $developer, 'ai')];
}
