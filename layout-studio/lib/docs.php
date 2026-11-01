<?php
// ספריית המסמכים בשרת — רשימה, יצירה, פתיחה, שמירה ומחיקה. מבנה המסמך עצמו — lib/model.php.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/model.php';
require_once __DIR__ . '/media.php';

const LS_TITLE_MAX = 120;
// גבולות גודל עמוד, ב-pt: מ-5 ס"מ ועד 120 ס"מ.
const LS_PAGE_MIN = 141.73;
const LS_PAGE_MAX = 3401.57;

function ls_mm_to_pt(float $mm): float
{
    return round($mm * 72 / 25.4, 2);
}

/** גדלים מוכנים — במ"מ, כמו שמדברים עליהם בדפוס. */
function ls_page_presets(): array
{
    return [
        'a4' => ['label' => 'A4', 'mm' => [210, 297]],
        'a5' => ['label' => 'A5', 'mm' => [148, 210]],
        'b5' => ['label' => 'B5', 'mm' => [176, 250]],
        '17x24' => ['label' => '17×24', 'mm' => [170, 240]],
    ];
}

function ls_doc_row(array $r): array
{
    return [
        'id' => (int)$r['id'],
        'title' => $r['title'],
        'page' => [(float)$r['page_w'], (float)$r['page_h']],
        'created_at' => $r['created_at'],
        'updated_at' => $r['updated_at'],
    ];
}

function ls_docs_list(int $owner): array
{
    $q = ls_db()->prepare('SELECT * FROM docs WHERE owner_id = ? AND deleted_at IS NULL ORDER BY updated_at DESC, id DESC');
    $q->execute([$owner]);
    return array_map('ls_doc_row', $q->fetchAll());
}

/** @throws InvalidArgumentException עם קוד שגיאה קצר */
function ls_doc_create(int $owner, string $title, string $preset, ?array $customMm = null): array
{
    $title = trim(preg_replace('/\s+/u', ' ', $title));
    if ($title === '') throw new InvalidArgumentException('title_required');
    if (mb_strlen($title) > LS_TITLE_MAX) throw new InvalidArgumentException('title_too_long');

    $presets = ls_page_presets();
    if ($preset === 'custom') {
        if (!is_array($customMm) || count($customMm) !== 2) throw new InvalidArgumentException('bad_size');
        [$w, $h] = array_map(fn($v) => is_numeric($v) ? ls_mm_to_pt((float)$v) : 0.0, array_values($customMm));
    } elseif (isset($presets[$preset])) {
        [$w, $h] = array_map('ls_mm_to_pt', $presets[$preset]['mm']);
    } else {
        throw new InvalidArgumentException('bad_size');
    }
    foreach ([$w, $h] as $v) {
        if ($v < LS_PAGE_MIN || $v > LS_PAGE_MAX) throw new InvalidArgumentException('bad_size');
    }

    $now = ls_now();
    $db = ls_db();
    $model = json_encode(ls_model_new($w, $h), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $db->prepare('INSERT INTO docs (owner_id, title, page_w, page_h, model, rev, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 1, ?, ?)')
        ->execute([$owner, $title, $w, $h, $model, $now, $now]);
    $q = $db->prepare('SELECT * FROM docs WHERE id = ?');
    $q->execute([(int)$db->lastInsertId()]);
    return ls_doc_row($q->fetch());
}

/** שינוי שם. @throws InvalidArgumentException not_found / title_required / title_too_long */
function ls_doc_rename(int $owner, int $id, string $title): array
{
    $title = trim(preg_replace('/\s+/u', ' ', preg_replace('/\.mbe$/i', '', trim($title))));
    if ($title === '') throw new InvalidArgumentException('title_required');
    if (mb_strlen($title) > LS_TITLE_MAX) throw new InvalidArgumentException('title_too_long');
    if (!ls_doc_find($owner, $id)) throw new InvalidArgumentException('not_found');
    ls_db()->prepare('UPDATE docs SET title = ?, updated_at = ? WHERE id = ? AND owner_id = ?')->execute([$title, ls_now(), $id, $owner]);
    return ls_doc_row(ls_doc_find($owner, $id));
}

/** "ייצוא כל הנתונים שלי": החשבון, והמסמכים עם המודל שלהם (התמונות — בהורדה כ-‎.mbe של כל מסמך). */
function ls_account_export(int $owner): array
{
    $acc = ls_db()->prepare('SELECT name, email, role, created_at FROM accounts WHERE id = ?');
    $acc->execute([$owner]);
    $q = ls_db()->prepare('SELECT * FROM docs WHERE owner_id = ? AND deleted_at IS NULL ORDER BY id');
    $q->execute([$owner]);
    return ['account' => $acc->fetch() ?: null, 'docs' => array_map(fn($r) => ls_doc_row($r) + ['model' => json_decode((string)$r['model'])], $q->fetchAll())];
}

/** "מחיקת החשבון": המסמכים (גם שבסל), החשבון, והקבצים בשרת (תמונות וקובצי מקור). */
function ls_account_delete(int $owner): void
{
    $db = ls_db();
    $db->prepare('DELETE FROM docs WHERE owner_id = ?')->execute([$owner]);
    $db->prepare('DELETE FROM accounts WHERE id = ?')->execute([$owner]);
    $dir = ls_media_dir($owner);
    if (is_dir($dir)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        rmdir($dir);
    }
}

/** מחיקה רכה (deleted_at). רק הבעלים. מחזיר false אם אין מסמך כזה שלו. */
function ls_doc_delete(int $owner, int $id): bool
{
    $q = ls_db()->prepare('UPDATE docs SET deleted_at = ? WHERE id = ? AND owner_id = ? AND deleted_at IS NULL');
    $q->execute([ls_now(), $id, $owner]);
    return $q->rowCount() === 1;
}

function ls_doc_find(int $owner, int $id): ?array
{
    $q = ls_db()->prepare('SELECT * FROM docs WHERE id = ? AND owner_id = ? AND deleted_at IS NULL');
    $q->execute([$id, $owner]);
    return $q->fetch() ?: null;
}

/** המסמך עם המודל שלו. מסמך מלפני המודל (גרסת מסד 1) — מקבל מודל חדש בגודל שלו. */
function ls_doc_get(int $owner, int $id): ?array
{
    $r = ls_doc_find($owner, $id);
    if (!$r) return null;
    $model = $r['model'] !== null ? json_decode($r['model']) : null;
    if ($model === null) {
        $model = ls_model_new((float)$r['page_w'], (float)$r['page_h']);
        $r['model'] = json_encode($model, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $r['rev'] = (int)$r['rev'] + 1;
        ls_db()->prepare('UPDATE docs SET model = ?, rev = ? WHERE id = ?')->execute([$r['model'], $r['rev'], $r['id']]);
        $model = json_decode($r['model']);
    }
    // json_decode בלי assoc — כך {} נשאר {} ולא הופך ל-[] בדרך חזרה
    return ['doc' => ls_doc_row($r), 'model' => $model, 'rev' => (int)$r['rev']];
}

/**
 * שמירת המודל. $rev — הגרסה שהדפדפן ערך; לא תואמת → conflict (נשמר בינתיים ממקום אחר).
 * מחזיר את ה-rev החדש. @throws InvalidArgumentException (not_found / conflict / bad_model:… / too_big)
 * $mediaExists(src) — האם התמונה שמסגרת מפנה אליה קיימת אצל המשתמש (בלי — לא נבדק).
 */
function ls_doc_save(int $owner, int $id, int $rev, $modelJson, ?callable $mediaExists = null): int
{
    if (!is_string($modelJson)) throw new InvalidArgumentException('bad_model:root');
    if (strlen($modelJson) > LS_MODEL_MAX_BYTES) throw new InvalidArgumentException('too_big');
    $m = json_decode($modelJson, true);
    [$w, $h] = ls_model_check($m, $mediaExists);
    $db = ls_db();
    $q = $db->prepare('UPDATE docs SET model = ?, page_w = ?, page_h = ?, rev = rev + 1, updated_at = ?
        WHERE id = ? AND owner_id = ? AND deleted_at IS NULL AND rev = ?');
    $q->execute([$modelJson, $w, $h, ls_now(), $id, $owner, $rev]);
    if ($q->rowCount() === 1) return $rev + 1;
    if (!ls_doc_find($owner, $id)) throw new InvalidArgumentException('not_found');
    throw new InvalidArgumentException('conflict');
}
