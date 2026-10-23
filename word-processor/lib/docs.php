<?php
/**
 * מסמכים, גרסאות, נעילות ותמונות. משותף לאפליקציה (מרחב 'a:<חשבון>') ולהטמעה (מרחב 'c:<לקוח>').
 * לקוח רואה רק את המרחב שלו — כל חיפוש מסמך עובר דרך doc_find עם המרחב (embed.md, פרק 1).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/page.php';

const DOC_MAX_BYTES = 8 * 1024 * 1024;
const VERSION_MERGE_SECONDS = 600;
const VERSION_MAX = 100;
const LOCK_SECONDS = 120;
const MEDIA_MAX_BYTES = 10 * 1024 * 1024;
const MEDIA_TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp'];
/** גרסת המבנה של ה-JSON (attrs.schema). אותו מספר ב-assets/js/extensions.js; ההמרות — כאן וב-assets/js/schema.js. */
const SCHEMA_VERSION = 9;

class ApiError extends Exception
{
    public function __construct(string $message, public string $codeName = 'error', public int $status = 400)
    {
        parent::__construct($message);
    }
}

function empty_doc_json(): array
{
    return ['type' => 'doc', 'content' => [['type' => 'paragraph']]];
}

/** מקבל JSON של המנוע (מערך או מחרוזת), בודק צורה וגודל. */
function doc_json_valid($json): array
{
    if (is_string($json)) $json = json_decode($json, true);
    if (!is_array($json) || ($json['type'] ?? '') !== 'doc' || !is_array($json['content'] ?? null)) {
        throw new ApiError('תוכן המסמך אינו תקין', 'bad_json');
    }
    if (!$json['content']) $json['content'] = [['type' => 'paragraph']];
    if (strlen(json_encode($json)) > DOC_MAX_BYTES) throw new ApiError('המסמך גדול מדי', 'too_large', 413);
    return doc_migrate($json);
}

/**
 * המרה לגרסת המבנה הנוכחית. מסמך מגרסה חדשה מהשרת (לשונית עם קוד חדש, ושרת שעוד לא עודכן) — נדחה,
 * כדי שקוד ישן לא ישמור מבנה שהוא לא מבין.
 *   0 → 1: attrs נוסף לצומת השורש; הגדרות העמוד עוברות בו (attrs.page) בדרך מהשרת ואליו — במסד הן בעמודה page.
 *   1 → 2: קישורים (link) וסימניות (bookmark) — אין מה להמיר.
 *   2 → 3: תוכן עניינים, כיתובים והפניות (seq / xref), הערות על טקסט (comment, attrs.comments), הערות סיום — אין מה להמיר.
 *   3 → 4: מעקב שינויים (סימונים ins / del, attrs.track) — אין מה להמיר.
 */
function doc_migrate(array $json): array
{
    $attrs = is_array($json['attrs'] ?? null) ? $json['attrs'] : [];
    $v = (int)($attrs['schema'] ?? 0);
    if ($v > SCHEMA_VERSION) throw new ApiError('המסמך נשמר בגרסה חדשה יותר של העורך — רעננו את הדף', 'schema_newer', 409);
    if ($v < SCHEMA_VERSION) $v = SCHEMA_VERSION;   // אין צעד המרה של ממש: מבנה ישן תקף גם בגרסה הנוכחית
    $attrs['schema'] = $v;
    $json['attrs'] = $attrs;
    return $json;
}

/**
 * הגרסה המינימלית של עורך שמותר לו לשמור על המסמך: לפי מה שבאמת יש בו — לא לפי המספר שנרשם (מסמכים נשמרו
 * עם מבנה של גרסה 3 עוד כשהמספר היה 2). צומת או סימון של גרסה 3 → 3; קישור / סימניה → 2; אחרת 0.
 * מאפיינים שנוספו לצמתים קיימים (למשל סגנון קו תחתון) — לא נבדקים כאן; מעכשיו כל תוספת כזו מעלה את המספר.
 * 4 — מעקב שינויים; 5 — מעבר טור; 6 — עצירות טאב (attrs.tabs בפסקה); 7 — שינוי עיצוב במעקב (fmt); 8 — כותרת עשירה
 * (header / footer כפסקאות — בהגדרות העמוד של המסמך, $page, או של מעבר מקטע).
 */
function doc_schema_need(array $json, ?array $page = null): int
{
    $hfRich = fn($p) => is_array($p) && (bool)array_filter(['header', 'footer', 'header_even', 'footer_even'], fn($k) => is_array($p[$k] ?? null) && $p[$k]);
    $need = !empty($json['attrs']['track']) ? 4 : (empty($json['attrs']['comments']) ? 0 : 3);
    if ($hfRich($page) || $hfRich($json['attrs']['page'] ?? null)) $need = 8;   // ממשיכים לתוכן — בו יכולה להיות תמונה מעוגנת (9)
    $walk = function (array $nodes) use (&$walk, &$need, $hfRich) {
        foreach ($nodes as $n) {
            if (!is_array($n) || $need >= SCHEMA_VERSION) return;
            $t = $n['type'] ?? '';
            // תמונה שמעוגנת לעמוד — כותב ישן היה מאבד את העיגון (והתמונה הייתה קופצת לפסקה)
            if ($t === 'image' && ($n['attrs']['anchor'] ?? null) === 'page') { $need = 9; return; }
            if ($t === 'sectionBreak' && $hfRich($n['attrs']['page'] ?? null)) { $need = max($need, 8); continue; }
            if (in_array('fmt', array_column(array_filter(is_array($n['marks'] ?? null) ? $n['marks'] : [], 'is_array'), 'type'), true)) { $need = max($need, 7); continue; }
            if (!empty($n['attrs']['tabs'])) $need = max($need, 6);   // ממשיכים לתוכן — בו יכול להיות שינוי עיצוב (7)
            if ($t === 'columnBreak') { $need = max($need, 5); continue; }
            $marks = array_column(array_filter(is_array($n['marks'] ?? null) ? $n['marks'] : [], 'is_array'), 'type');
            if (in_array('ins', $marks, true) || in_array('del', $marks, true)) { $need = max($need, 4); continue; }
            if (in_array($t, ['toc', 'seq', 'xref'], true) || ($t === 'footnote' && !empty($n['attrs']['end'])) || in_array('comment', $marks, true)) { $need = max($need, 3); continue; }
            if ($t === 'bookmark' || in_array('link', $marks, true)) $need = max($need, 2);
            if (is_array($n['content'] ?? null)) $walk($n['content']);
        }
    };
    $walk($json['content'] ?? []);
    return $need;
}

/** הגדרות העמוד שבתוך ה-JSON — מוציאים לעמודה page (או null), ומסירים מה-JSON שנשמר. */
function take_page(array &$json): ?array
{
    $p = $json['attrs']['page'] ?? null;
    unset($json['attrs']['page']);
    return is_array($p) ? $p : null;
}

/** ה-JSON כפי שהעורך טוען אותו: מומר, עם הגדרות העמוד בפנים. */
function editor_json(array $json, array $page): array
{
    $json = doc_migrate($json);
    $json['attrs']['page'] = $page;
    return with_media_src($json);
}

function clean_title($t): string
{
    $t = is_string($t) ? trim(preg_replace('/\s+/u', ' ', $t)) : '';
    return mb_substr($t, 0, 200);
}

function doc_new(string $space, string $title, ?array $json, $page, string $userId, string $userName): string
{
    $id = rand_id(20);
    $json = $json ? doc_json_valid($json) : doc_migrate(empty_doc_json());
    $page = take_page($json) ?? $page;
    $now = time();
    db()->prepare('INSERT INTO docs (id, space, title, json, page, version, created_at, updated_at, updated_by, updated_by_id)
        VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, ?)')
        ->execute([$id, $space, clean_title($title), json_encode(strip_media_src($json), JSON_UNESCAPED_UNICODE),
            json_encode(page_normalize($page), JSON_UNESCAPED_UNICODE), $now, $now, $userName, $userId]);
    version_record(doc_find($id, $space), $userId, $userName);
    return $id;
}

/**
 * המסמך במרחב — או 404. מזהה של מרחב אחר נראה בדיוק כמו מזהה שלא קיים. מסמך בסל המחזור — לא נמצא
 * (פתיחה, שמירה, ייצוא); $trashed — רק מסמך שבסל (שחזור, מחיקה לצמיתות).
 */
function doc_find($id, string $space, bool $trashed = false): array
{
    if (!is_string($id) || !preg_match('/^[a-z0-9]{6,40}$/', $id)) throw new ApiError('המסמך לא נמצא', 'not_found', 404);
    $st = db()->prepare('SELECT * FROM docs WHERE id = ? AND space = ? AND deleted_at IS ' . ($trashed ? 'NOT NULL' : 'NULL'));
    $st->execute([$id, $space]);
    $row = $st->fetch(); $st->closeCursor();
    if (!$row) throw new ApiError('המסמך לא נמצא', 'not_found', 404);
    return $row;
}

function doc_json(array $row): array
{
    return json_decode($row['json'], true) ?: empty_doc_json();
}

function doc_page(array $row): array
{
    return page_normalize(json_decode($row['page'], true) ?: []);
}

/**
 * שמירה. $base — הגרסה שעליה נערך; גרסה אחרת בשרת → 409 conflict (embed.md, פרק 4).
 * מחזיר את השורה המעודכנת.
 */
function doc_save(array $row, $json, ?string $title, string $userId, string $userName, $base = null, $page = null): array
{
    if ($base !== null && (int)$base !== (int)$row['version']) {
        throw new ApiError('המסמך השתנה בינתיים — טענו אותו מחדש', 'conflict', 409);
    }
    // כותב ישן (לשונית שנפתחה לפני עדכון) על מסמך שיש בו מבנה שהוא לא מכיר — היה מוחק אותו בשקט. נדחה (ביקורת 04, F02)
    $in = is_string($json) ? json_decode($json, true) : $json;
    // (בלי מספר בכלל — לא עורך: קריאת API של מארחת, שהמבנה שלה לא נבדק כאן)
    $writer = is_array($in) && isset($in['attrs']['schema']) ? (int)$in['attrs']['schema'] : null;
    if ($writer !== null && $writer < doc_schema_need(doc_json($row), doc_page($row))) throw new ApiError('המסמך נשמר בגרסה חדשה יותר של העורך — רעננו את הדף', 'schema_newer', 409);
    $json = doc_json_valid($json);
    $page = take_page($json) ?? $page;   // הגדרות העמוד שבתוך המסמך — באותה שמירה ובאותה גרסה
    $now = time();
    $sets = 'json = ?, version = version + 1, updated_at = ?, updated_by = ?, updated_by_id = ?';
    $args = [json_encode(strip_media_src($json), JSON_UNESCAPED_UNICODE), $now, $userName, $userId];
    if ($title !== null) { $sets .= ', title = ?'; $args[] = clean_title($title); }
    if ($page !== null) { $sets .= ', page = ?'; $args[] = json_encode(page_normalize($page, doc_page($row)), JSON_UNESCAPED_UNICODE); }
    $args[] = $row['id'];
    $args[] = (int)$row['version'];
    $st = db()->prepare("UPDATE docs SET $sets WHERE id = ? AND version = ?");
    $st->execute($args);
    if ($st->rowCount() !== 1) throw new ApiError('המסמך השתנה בינתיים — טענו אותו מחדש', 'conflict', 409);
    $row = doc_find($row['id'], $row['space']);
    version_record($row, $userId, $userName);
    return $row;
}

/**
 * הגדרות עמוד בלבד — בלי גרסה חדשה של התוכן. לתאימות (embed_page, my_page של לשונית ישנה);
 * העורך שולח היום את ההגדרות בתוך המסמך, באותה שמירה (doc_save).
 */
function doc_set_page(array $row, $page): array
{
    $p = page_normalize($page, doc_page($row));
    db()->prepare('UPDATE docs SET page = ?, updated_at = ? WHERE id = ?')
        ->execute([json_encode($p, JSON_UNESCAPED_UNICODE), time(), $row['id']]);
    return doc_find($row['id'], $row['space']);
}

function doc_rename(array $row, string $title): void
{
    db()->prepare('UPDATE docs SET title = ? WHERE id = ?')->execute([clean_title($title), $row['id']]);
}

// ---------- סל המחזור ----------
const TRASH_DAYS = 30;

/** מחיקה מהאפליקציה: לסל המחזור (אפשר לשחזר עד TRASH_DAYS ימים). הנעילות על המסמך משתחררות. */
function doc_trash(array $row): void
{
    db()->prepare('UPDATE docs SET deleted_at = ? WHERE id = ?')->execute([time(), $row['id']]);
    db()->prepare('DELETE FROM locks WHERE doc = ?')->execute([$row['id']]);
}

function doc_restore(array $row): void
{
    db()->prepare('UPDATE docs SET deleted_at = NULL WHERE id = ?')->execute([$row['id']]);
}

/** מה שבסל יותר מ-TRASH_DAYS ימים — נמחק לצמיתות (נקרא כשפותחים את הרשימה או את הסל). */
function trash_purge(string $space): void
{
    $st = db()->prepare('SELECT * FROM docs WHERE space = ? AND deleted_at IS NOT NULL AND deleted_at < ?');
    $st->execute([$space, time() - TRASH_DAYS * 86400]);
    foreach ($st->fetchAll() as $row) doc_delete($row);
}

/** מחיקה לצמיתות: התמונות, הגרסאות (cascade) והמסמך. */
function doc_delete(array $row): void
{
    $st = db()->prepare('SELECT id, type FROM media WHERE doc = ?');
    $st->execute([$row['id']]);
    foreach ($st->fetchAll() as $m) @unlink(media_path($m['id'], $m['type']));
    db()->prepare('DELETE FROM media WHERE doc = ?')->execute([$row['id']]);
    db()->prepare('DELETE FROM docs WHERE id = ?')->execute([$row['id']]);
}

/** העתק במרחב, עם החלפות בטקסט בלבד (לפי הסדר), ועם עותק של כל תמונה. */
function doc_copy(array $row, string $space, string $title, array $replace, string $userId, string $userName): string
{
    $json = doc_json($row);
    $pairs = [];
    foreach ($replace as $p) {
        if (is_array($p) && count($p) === 2 && is_string($p[0]) && is_string($p[1]) && $p[0] !== '') $pairs[] = [$p[0], $p[1]];
    }
    $id = doc_new($space, $title !== '' ? $title : $row['title'], null, doc_page($row), $userId, $userName);
    $map = [];
    // תמונות בכותרות העשירות (בהגדרות העמוד) — עותק למסמך החדש, כמו בגוף
    $page = doc_page($row);
    $hfImg = false;
    foreach (['header', 'footer', 'header_even', 'footer_even'] as $k) {
        if (!is_array($page[$k])) continue;
        foreach ($page[$k] as &$b) if (is_array($b['content'] ?? null)) foreach ($b['content'] as &$x) {
            if (($x['type'] ?? '') !== 'image' || empty($x['attrs']['media'])) continue;
            $old = $x['attrs']['media'];
            $map[$old] ??= media_copy($old, $id);
            if ($map[$old]) { $x['attrs']['media'] = $map[$old]; $x['attrs']['src'] = media_url($map[$old]); $hfImg = true; }
        }
        unset($b, $x);
    }
    if ($hfImg) db()->prepare('UPDATE docs SET page = ? WHERE id = ?')->execute([json_encode($page, JSON_UNESCAPED_UNICODE), $id]);
    $json = walk_nodes($json, function (array $n) use ($pairs, &$map, $id) {
        if (($n['type'] ?? '') === 'text' && isset($n['text'])) {
            foreach ($pairs as [$a, $b]) $n['text'] = str_replace($a, $b, $n['text']);
        }
        if (($n['type'] ?? '') === 'image' && !empty($n['attrs']['media'])) {
            $old = $n['attrs']['media'];
            $map[$old] ??= media_copy($old, $id);
            if ($map[$old]) $n['attrs']['media'] = $map[$old];
        }
        return $n;
    });
    $new = doc_find($id, $space);
    db()->prepare('UPDATE docs SET json = ? WHERE id = ?')->execute([json_encode($json, JSON_UNESCAPED_UNICODE), $id]);
    db()->prepare('UPDATE versions SET json = ? WHERE doc = ?')->execute([json_encode($json, JSON_UNESCAPED_UNICODE), $new['id']]);
    return $id;
}

/** מפעיל $fn על כל צומת (מלמטה למעלה), ומחזיר את העץ החדש. */
function walk_nodes(array $node, callable $fn): array
{
    if (isset($node['content']) && is_array($node['content'])) {
        foreach ($node['content'] as $i => $child) {
            if (is_array($child)) $node['content'][$i] = walk_nodes($child, $fn);
        }
    }
    return $fn($node);
}

/** במקור נשמר רק מזהה התמונה (attrs.media); הכתובת החתומה נבנית בכל טעינה. */
function strip_media_src(array $json): array
{
    return walk_nodes($json, function (array $n) {
        if (($n['type'] ?? '') === 'image' && !empty($n['attrs']['media'])) unset($n['attrs']['src']);
        return $n;
    });
}

function with_media_src(array $json, bool $absolute = false): array
{
    return walk_nodes($json, function (array $n) use ($absolute) {
        if (($n['type'] ?? '') === 'image' && !empty($n['attrs']['media'])) $n['attrs']['src'] = media_url($n['attrs']['media'], $absolute);
        return $n;
    });
}

// ---------- גרסאות ----------

/** כל שמירה = גרסה; שמירה של אותו משתמש תוך 10 דקות מתחילת הגרסה האחרונה — מעדכנת אותה. */
/**
 * רישום גרסה. רגילה ($kind '') — מתאחדת עם הגרסה הקודמת של אותו משתמש בחלון 10 הדקות; גרסת שחזור ('restore') —
 * תמיד שורה חדשה, ואף שמירה לא מתאחדת לתוכה. כך המצב שלפני שחזור והמצב המשוחזר נשמרים שניהם (ביקורת 03 / 04, F06 / R07).
 */
function version_record(array $row, string $userId, string $userName, string $kind = ''): void
{
    $now = time();
    $st = db()->prepare('SELECT id, user_id, started_at, kind FROM versions WHERE doc = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$row['id']]);
    $last = $st->fetch(); $st->closeCursor();
    if ($kind === '' && $last && $last['kind'] === '' && $last['user_id'] === $userId && $now - (int)$last['started_at'] < VERSION_MERGE_SECONDS) {
        db()->prepare('UPDATE versions SET version = ?, title = ?, json = ?, page = ?, user_name = ?, saved_at = ? WHERE id = ?')
            ->execute([$row['version'], $row['title'], $row['json'], $row['page'], $userName, $now, $last['id']]);
        return;
    }
    db()->prepare('INSERT INTO versions (doc, version, title, json, page, user_id, user_name, started_at, saved_at, kind) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$row['id'], $row['version'], $row['title'], $row['json'], $row['page'], $userId, $userName, $now, $now, $kind]);
    db()->prepare('DELETE FROM versions WHERE doc = ? AND id NOT IN (SELECT id FROM versions WHERE doc = ? ORDER BY id DESC LIMIT ' . VERSION_MAX . ')')
        ->execute([$row['id'], $row['id']]);
}

function versions_list(array $row): array
{
    $st = db()->prepare('SELECT id, version, title, user_name, started_at, saved_at, kind FROM versions WHERE doc = ? ORDER BY id DESC');
    $st->execute([$row['id']]);
    return array_map(fn($v) => ['id' => (int)$v['id'], 'version' => (int)$v['version'], 'title' => $v['title'],
        'by' => $v['user_name'], 'started_at' => (int)$v['started_at'], 'saved_at' => (int)$v['saved_at'], 'kind' => $v['kind']], $st->fetchAll());
}

/**
 * שחזור גרסה — פעולה אחת בשרת (ביקורת 03 / 04, F06 / R07): בבסיס הנוכחי בלבד (אחרת התנגשות), המצב הנוכחי נשאר כגרסה
 * (הגרסה האחרונה — היא המצב הנוכחי; מעכשיו לא מתאחדים לתוכה), והתוכן המשוחזר נרשם כגרסת 'restore' נפרדת.
 */
function doc_restore_version(array $row, $versionId, string $userId, string $userName, $base): array
{
    if ($base !== null && (int)$base !== (int)$row['version']) throw new ApiError('המסמך השתנה בינתיים — טענו אותו מחדש', 'conflict', 409);
    $v = version_get($row, $versionId);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // המצב הנוכחי — כגרסה בפני עצמה, אם עוד לא רשום בדיוק כך (מסמך שנשמר לפני שהיו גרסאות, או בלי רישום)
        $st = $pdo->prepare('SELECT version, json FROM versions WHERE doc = ? ORDER BY id DESC LIMIT 1');
        $st->execute([$row['id']]);
        $last = $st->fetch(); $st->closeCursor();
        if (!$last || (int)$last['version'] !== (int)$row['version'] || $last['json'] !== $row['json']) {
            $now = time();
            $pdo->prepare('INSERT INTO versions (doc, version, title, json, page, user_id, user_name, started_at, saved_at, kind) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$row['id'], $row['version'], $row['title'], $row['json'], $row['page'], (string)$row['updated_by_id'], (string)$row['updated_by'], $now, $now, 'before']);
        } else {
            // הגרסה האחרונה היא המצב שלפני השחזור — נסגרת: שמירות הבאות לא יתאחדו לתוכה
            $pdo->prepare("UPDATE versions SET kind = 'before' WHERE id = (SELECT id FROM versions WHERE doc = ? ORDER BY id DESC LIMIT 1) AND kind = ''")->execute([$row['id']]);
        }
        $now = time();
        $st = $pdo->prepare('UPDATE docs SET json = ?, page = ?, title = ?, version = version + 1, updated_at = ?, updated_by = ?, updated_by_id = ? WHERE id = ? AND version = ?');
        $st->execute([$v['json'], $v['page'], $v['title'], $now, $userName, $userId, $row['id'], (int)$row['version']]);
        if ($st->rowCount() !== 1) throw new ApiError('המסמך השתנה בינתיים — טענו אותו מחדש', 'conflict', 409);
        $row = doc_find($row['id'], $row['space']);
        version_record($row, $userId, $userName, 'restore');
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $row;
}

function version_get(array $row, $id): array
{
    $st = db()->prepare('SELECT * FROM versions WHERE doc = ? AND id = ?');
    $st->execute([$row['id'], (int)$id]);
    $v = $st->fetch(); $st->closeCursor();
    if (!$v) throw new ApiError('הגרסה לא נמצאה', 'not_found', 404);
    return $v;
}

/** גרסה לפי מספר הגרסה של המסמך (doc_export עם version). */
function version_by_number(array $row, int $version): array
{
    $st = db()->prepare('SELECT * FROM versions WHERE doc = ? AND version >= ? ORDER BY version ASC LIMIT 1');
    $st->execute([$row['id'], $version]);
    $v = $st->fetch(); $st->closeCursor();
    if (!$v || (int)$v['version'] !== $version) {
        // גרסאות מאוחדות: מספר שנבלע בגרסה מאוחדת — אין אותו בדיוק
        throw new ApiError('הגרסה לא נשמרה בנפרד (גרסאות מאוחדות בכל 10 דקות)', 'not_found', 404);
    }
    return $v;
}

// ---------- נעילה ----------

/** מחזיר את הנעילה הפעילה (או null). */
function lock_get(string $doc): ?array
{
    $st = db()->prepare('SELECT * FROM locks WHERE doc = ?');
    $st->execute([$doc]);
    $l = $st->fetch(); $st->closeCursor();
    if (!$l || time() - (int)$l['beat'] > LOCK_SECONDS) return null;
    return $l;
}

/**
 * לוקח או מחדש נעילה. $holder — מזהה הסשן (כמה לשוניות של אותו משתמש — אותו משתמש, אז מותר).
 * מחזיר ['ok' => true] או ['ok' => false, 'by' => שם, 'since' => זמן].
 */
function lock_take(string $doc, string $userId, string $userName, string $holder): array
{
    $now = time();
    $l = lock_get($doc);
    if ($l && $l['user_id'] !== $userId) return ['ok' => false, 'by' => $l['user_name'], 'since' => (int)$l['since']];
    $since = $l ? (int)$l['since'] : $now;
    db()->prepare('INSERT INTO locks (doc, user_id, user_name, holder, since, beat) VALUES (?, ?, ?, ?, ?, ?)
        ON CONFLICT(doc) DO UPDATE SET user_id = excluded.user_id, user_name = excluded.user_name, holder = excluded.holder,
        since = excluded.since, beat = excluded.beat')->execute([$doc, $userId, $userName, $holder, $since, $now]);
    return ['ok' => true, 'since' => $since];
}

function lock_release(string $doc, string $userId): void
{
    db()->prepare('DELETE FROM locks WHERE doc = ? AND user_id = ?')->execute([$doc, $userId]);
}

function lock_public(?array $l): ?array
{
    return $l ? ['by' => $l['user_name'], 'since' => (int)$l['since']] : null;
}

// ---------- תמונות ----------

function media_path(string $id, string $type): string
{
    return wp_media_dir() . '/' . $id . '.' . (MEDIA_TYPES[$type] ?? 'bin');
}

/** שומר תמונה שהועלתה. בודק את התוכן עצמו (getimagesize), לא את מה שהדפדפן אמר. */
function media_store(string $doc, string $bytes): array
{
    if (strlen($bytes) === 0) throw new ApiError('הקובץ ריק', 'bad_file');
    if (strlen($bytes) > MEDIA_MAX_BYTES) throw new ApiError('התמונה גדולה מ-10MB', 'too_large', 413);
    $info = @getimagesizefromstring($bytes);
    $type = $info['mime'] ?? '';
    if (!isset(MEDIA_TYPES[$type])) throw new ApiError('רק תמונות PNG, JPEG, GIF או WebP', 'bad_type', 415);
    $id = rand_id(24);
    file_put_contents(media_path($id, $type), $bytes);
    db()->prepare('INSERT INTO media (id, doc, type, size, width, height, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$id, $doc, $type, strlen($bytes), (int)$info[0], (int)$info[1], time()]);
    return ['id' => $id, 'url' => media_url($id), 'width' => (int)$info[0], 'height' => (int)$info[1]];
}

function media_row(string $id): ?array
{
    $st = db()->prepare('SELECT m.* FROM media m JOIN docs d ON d.id = m.doc WHERE m.id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function media_copy(string $id, string $toDoc): ?string
{
    $m = media_row($id);
    if (!$m || !is_file(media_path($m['id'], $m['type']))) return null;
    $new = rand_id(24);
    copy(media_path($m['id'], $m['type']), media_path($new, $m['type']));
    db()->prepare('INSERT INTO media (id, doc, type, size, width, height, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$new, $toDoc, $m['type'], $m['size'], $m['width'], $m['height'], time()]);
    return $new;
}

/** תמונה של מסמך — לפי מזהה, רק אם התמונה שייכת למסמך הזה (ייבוא, העתקה). */
function media_of_doc(string $id, string $doc): ?array
{
    $m = media_row($id);
    return $m && $m['doc'] === $doc ? $m : null;
}

function media_sig(string $id): string
{
    return rtrim(strtr(base64_encode(substr(hash_hmac('sha256', 'media:' . $id, app_secret(), true), 0, 16)), '+/', '-_'), '=');
}

/** כתובת חתומה — תקפה כל עוד המסמך קיים (embed.md, פרק 3). */
function media_url(string $id, bool $absolute = false): string
{
    $q = 'api.php?action=media&id=' . $id . '&s=' . media_sig($id);
    return $absolute ? base_url() . $q : $q;
}

/** https://mbe-plus.com/mini_projects/word-processor/ — מהבקשה, או WP_BASE_URL בבדיקות. */
function base_url(): string
{
    if ($b = getenv('WP_BASE_URL')) return rtrim($b, '/') . '/';
    $host = $_SERVER['HTTP_HOST'] ?? 'mbe-plus.com';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return 'https://' . $host . $dir . '/';
}

function origin_of(string $url): string
{
    $p = parse_url($url);
    if (!$p || empty($p['scheme']) || empty($p['host'])) return '';
    return strtolower($p['scheme']) . '://' . strtolower($p['host']) . (isset($p['port']) ? ':' . $p['port'] : '');
}
