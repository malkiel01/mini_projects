<?php
/**
 * שרטוטים על הגרף (spec-stage-a פרק 7). לכל משתמש ונכס, מעוגנים בזמן ומחיר — ולכן נראים בכל מסגרת זמן, אלא אם
 * סומן "רק במסגרת הזו" (tf). נשמרים אחרי שינוי (הדפדפן שולח בסוף גרירה, לא בכל תזוזה). אין שיתוף.
 *
 * כל פעולה מסוננת לפי user_id של ה-session: שרטוט של משתמש אחר נראה כמו שרטוט שלא קיים ("לא נמצא").
 * המחיר בעוגן — float: זה סימון על גרף, לא כסף (עיקרון 6). כשהתראה תשתמש ברמה מסומנת (פרק 8) — היא תעגל אותה.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/market.php';

const TS_DRAWINGS_MAX = 500;   // לנכס, למשתמש (פרק 7)
/** סוג → מספר נקודות העיגון */
const TS_DRAW_KINDS = ['trend' => 2, 'hline' => 1, 'ray' => 2, 'rect' => 2, 'fib' => 2, 'text' => 1, 'arrow' => 2];

function ts_drawing_out(array $r): array
{
    return ['id' => (int)$r['id'], 'asset' => $r['asset'], 'tf' => $r['tf'], 'kind' => $r['kind'],
        'points' => json_decode($r['points'], true) ?: [], 'style' => json_decode($r['style'], true) ?: (object)[],
        'updated_at' => (int)$r['updated_at']];
}

function ts_drawings_list(int $uid, mixed $asset): array
{
    ts_asset_split($asset);
    $st = ts_db()->prepare('SELECT * FROM drawings WHERE user_id = ? AND asset = ? ORDER BY id');
    $st->execute([$uid, $asset]);
    return array_map('ts_drawing_out', $st->fetchAll());
}

/** קלט שרטוט → [asset, tf|null, kind, points json, style json] — או TsInvalid */
function ts_drawing_in(array $in): array
{
    $asset = $in['asset'] ?? null;
    ts_asset_split($asset);
    $tf = $in['tf'] ?? null;
    if ($tf !== null && (!is_string($tf) || !isset(TS_TF_SECONDS[$tf]))) throw new TsInvalid('מסגרת זמן לא תקינה');
    $kind = $in['kind'] ?? '';
    if (!is_string($kind) || !isset(TS_DRAW_KINDS[$kind])) throw new TsInvalid('סוג שרטוט לא מוכר');
    $pts = $in['points'] ?? null;
    if (!is_array($pts) || !array_is_list($pts) || count($pts) !== TS_DRAW_KINDS[$kind]) throw new TsInvalid('מספר נקודות לא תקין לסוג השרטוט');
    $points = [];
    foreach ($pts as $p) {
        $t = $p['t'] ?? null; $v = $p['p'] ?? null;
        if (!is_int($t) || $t < 0 || $t > 4_102_444_800) throw new TsInvalid('זמן לא תקין בנקודת עיגון');
        if ((!is_int($v) && !is_float($v)) || !is_finite((float)$v) || abs((float)$v) > 1e15) throw new TsInvalid('מחיר לא תקין בנקודת עיגון');
        $points[] = ['t' => $t, 'p' => $v];
    }
    $s = is_array($in['style'] ?? null) ? $in['style'] : [];
    $color = $s['color'] ?? '#2d4a8a';
    if (!is_string($color) || !preg_match('/^#[0-9a-fA-F]{6}$/', $color)) throw new TsInvalid('צבע לא תקין');
    $width = $s['width'] ?? 2;
    if (!is_int($width) || $width < 1 || $width > 4) throw new TsInvalid('עובי — 1 עד 4');
    $style = ['color' => strtolower($color), 'width' => $width];
    if ($kind === 'text') {
        $text = ts_text($s['text'] ?? '', 200);
        if ($text === '') throw new TsInvalid('חסר טקסט');
        $style['text'] = $text;
    }
    return [$asset, $tf, $kind, json_encode($points), json_encode($style, JSON_UNESCAPED_UNICODE)];
}

/** יצירה (בלי id) או עדכון (עם id, רק של המשתמש). מחזיר את השרטוט. */
function ts_drawing_save(int $uid, array $in): array
{
    [$asset, $tf, $kind, $points, $style] = ts_drawing_in($in);
    $db = ts_db();
    $id = $in['id'] ?? null;
    if ($id !== null) {
        if (!is_int($id)) throw new TsInvalid('מזהה לא תקין');
        $st = $db->prepare('UPDATE drawings SET asset = ?, tf = ?, kind = ?, points = ?, style = ?, updated_at = ? WHERE id = ? AND user_id = ?');
        $st->execute([$asset, $tf, $kind, $points, $style, time(), $id, $uid]);
        if (!$st->rowCount()) throw new TsNotFound('השרטוט לא נמצא');
    } else {
        $st = $db->prepare('SELECT COUNT(*) FROM drawings WHERE user_id = ? AND asset = ?');
        $st->execute([$uid, $asset]);
        if ((int)$st->fetchColumn() >= TS_DRAWINGS_MAX) throw new TsInvalid('הגעתם ל-' . TS_DRAWINGS_MAX . ' שרטוטים בנכס הזה. מחקו כמה כדי להוסיף.');
        $db->prepare('INSERT INTO drawings (user_id, asset, tf, kind, points, style, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$uid, $asset, $tf, $kind, $points, $style, time()]);
        $id = (int)$db->lastInsertId();
    }
    $st = $db->prepare('SELECT * FROM drawings WHERE id = ? AND user_id = ?');
    $st->execute([$id, $uid]);
    return ts_drawing_out($st->fetch());
}

function ts_drawing_delete(int $uid, mixed $id): void
{
    $st = ts_db()->prepare('DELETE FROM drawings WHERE id = ? AND user_id = ?');
    $st->execute([is_int($id) ? $id : 0, $uid]);
    if (!$st->rowCount()) throw new TsNotFound('השרטוט לא נמצא');
}

/** כל השרטוטים של המשתמש — לייצוא המלא */
function ts_drawings_all(int $uid): array
{
    $st = ts_db()->prepare('SELECT * FROM drawings WHERE user_id = ? ORDER BY asset, id');
    $st->execute([$uid]);
    return array_map('ts_drawing_out', $st->fetchAll());
}
