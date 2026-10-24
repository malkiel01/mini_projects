<?php
// ספריית המסמכים בשרת — רשימה, יצירה ומחיקה. התוכן (עמודים, מסגרות, שטפים) — בשלב העמודים.

require_once __DIR__ . '/db.php';

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
    $db->prepare('INSERT INTO docs (owner_id, title, page_w, page_h, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$owner, $title, $w, $h, $now, $now]);
    $q = $db->prepare('SELECT * FROM docs WHERE id = ?');
    $q->execute([(int)$db->lastInsertId()]);
    return ls_doc_row($q->fetch());
}

/** מחיקה רכה (deleted_at). רק הבעלים. מחזיר false אם אין מסמך כזה שלו. */
function ls_doc_delete(int $owner, int $id): bool
{
    $q = ls_db()->prepare('UPDATE docs SET deleted_at = ? WHERE id = ? AND owner_id = ? AND deleted_at IS NULL');
    $q->execute([ls_now(), $id, $owner]);
    return $q->rowCount() === 1;
}
