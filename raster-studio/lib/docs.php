<?php
// ספריית המסמכים בשרת — רשימה, יצירה ומחיקה. התוכן (שכבות, ‎.mbe) — בשלב השמירה.

require_once __DIR__ . '/db.php';

const RS_TITLE_MAX = 120;
// גבולות שפיות בשרת בלבד. התקרה האמיתית דינמית — הדפדפן מחשב אותה מתקציב הזיכרון של המכשיר.
const RS_SIDE_MIN = 1;
const RS_SIDE_MAX = 30000;

/** גדלים מוכנים, בפיקסלים. */
function rs_canvas_presets(): array
{
    return [
        'screen' => ['label' => 'מסך', 'px' => [1920, 1080]],
        'square' => ['label' => 'ריבוע', 'px' => [1080, 1080]],
        'story' => ['label' => 'סטורי', 'px' => [1080, 1920]],
        'a4' => ['label' => 'A4', 'px' => [1240, 1754]],   // 150dpi
    ];
}

function rs_doc_row(array $r): array
{
    return [
        'id' => (int)$r['id'],
        'title' => $r['title'],
        'size' => [(int)$r['width'], (int)$r['height']],
        'created_at' => $r['created_at'],
        'updated_at' => $r['updated_at'],
    ];
}

function rs_docs_list(int $owner): array
{
    $q = rs_db()->prepare('SELECT * FROM docs WHERE owner_id = ? AND deleted_at IS NULL ORDER BY updated_at DESC, id DESC');
    $q->execute([$owner]);
    return array_map('rs_doc_row', $q->fetchAll());
}

/** מסמך אחד של הבעלים, או null. */
function rs_doc_get(int $owner, int $id): ?array
{
    $q = rs_db()->prepare('SELECT * FROM docs WHERE id = ? AND owner_id = ? AND deleted_at IS NULL');
    $q->execute([$id, $owner]);
    $r = $q->fetch();
    return $r ? rs_doc_row($r) : null;
}

/** @throws InvalidArgumentException עם קוד שגיאה קצר */
function rs_doc_create(int $owner, string $title, string $preset, ?array $customPx = null): array
{
    $title = trim(preg_replace('/\s+/u', ' ', $title));
    if ($title === '') throw new InvalidArgumentException('title_required');
    if (mb_strlen($title) > RS_TITLE_MAX) throw new InvalidArgumentException('title_too_long');

    $presets = rs_canvas_presets();
    if ($preset === 'custom') {
        if (!is_array($customPx) || count($customPx) !== 2) throw new InvalidArgumentException('bad_size');
        $vals = array_values($customPx);
        foreach ($vals as $v) {
            // רק מספרים שלמים — "12.5" או "abc" נדחים, לא מעוגלים בשקט
            if (!(is_int($v) || (is_string($v) && ctype_digit($v)))) throw new InvalidArgumentException('bad_size');
        }
        [$w, $h] = array_map('intval', $vals);
    } elseif (isset($presets[$preset])) {
        [$w, $h] = $presets[$preset]['px'];
    } else {
        throw new InvalidArgumentException('bad_size');
    }
    foreach ([$w, $h] as $v) {
        if ($v < RS_SIDE_MIN || $v > RS_SIDE_MAX) throw new InvalidArgumentException('bad_size');
    }

    $now = rs_now();
    $db = rs_db();
    $db->prepare('INSERT INTO docs (owner_id, title, width, height, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$owner, $title, $w, $h, $now, $now]);
    $q = $db->prepare('SELECT * FROM docs WHERE id = ?');
    $q->execute([(int)$db->lastInsertId()]);
    return rs_doc_row($q->fetch());
}

/** מחיקה רכה (deleted_at). רק הבעלים. מחזיר false אם אין מסמך כזה שלו. */
function rs_doc_delete(int $owner, int $id): bool
{
    $q = rs_db()->prepare('UPDATE docs SET deleted_at = ? WHERE id = ? AND owner_id = ? AND deleted_at IS NULL');
    $q->execute([rs_now(), $id, $owner]);
    return $q->rowCount() === 1;
}
