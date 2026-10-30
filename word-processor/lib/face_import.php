<?php
/**
 * docx שחזר מ-Word — "פנים" של מסמך שלנו (mbe-format.md סעיף 7). במקום ייבוא כמסמך חדש: מיזוג תלת-כיווני דרך הליבה
 * (MbeMerge::reconcile / applyMerge) — הבסיס (הגרסה שיצאה), שלנו (המסמך עכשיו) ומה שחזר — לפי מזהי הפסקה (הסימניות
 * _mbe_p<id>, ובלעדיהן לפי תוכן). פסקה שהשתנתה בשני הצדדים — שאלה למשתמש: שלי / מ-Word / שתיהן.
 *
 * מי שאין לו הרשאת עריכה במסמך, docx בלי מזהה, או מזהה של מסמך שלא אצלנו — null: ייבוא רגיל כמסמך חדש.
 */

require_once __DIR__ . '/mbe_export.php';
require_once __DIR__ . '/mbe/merge.php';
require_once __DIR__ . '/import.php';
require_once __DIR__ . '/share.php';   // doc_access

/**
 * $answers — ['f1/p…' => 'ours'|'theirs'|'both'] לכל התנגשות (מהפעם הקודמת, אחרי שהמשתמש ענה).
 * מחזיר null (לא פנים שלנו), או ['doc', 'status' => same|merged|conflicts, 'changes', 'conflicts' => [...]].
 */
function face_import(string $bytes, array $acc, string $uid, string $uname, array $answers = []): ?array
{
    $id = MbeFace::identify($bytes);
    if ($id['type'] !== 'face' || $id['format'] !== 'docx' || !($doc = face_index_get((string)$id['docId']))) return null;
    try { [$row] = doc_access($doc, $acc, 'edit'); } catch (ApiError $e) { return null; }

    // "שלנו" — המסמך כמו שהוא עכשיו: הזיכרון מתעדכן אליו (היומן וההיסטוריה שבו נשמרים — שם הבסיס)
    $src = mbe_source_path($row['id']);
    $flow = (string)(mbe_source_meta($row['id'])['flow'] ?? 'f1');
    $f = Mbe::open(export_mbe($row, true));
    if ($f->id() !== $id['docId']) return null;   // הזיכרון הוחלף (למשל ‎.mbe אחר יובא לאותו מסמך)

    // מה שחזר — ה-docx כשטף, במבנה של הזיכרון (תמונות ל-media/<sha256>); מזהי הפסקה מהסימניות (import_docx)
    $imp = import_docx($bytes, $row['id']);
    $gaps = [];
    $theirs = mbe_flow_doc($imp['json'], doc_page($row), $f, $row['id'], [], $gaps);
    $plan = MbeMerge::reconcile($f, ['docId' => $id['docId'], 'base' => $id['base'], 'baseDocument' => $id['document'] ?? null,
        'flows' => [$flow => $theirs], 'app' => 'external:word', 'who' => $uname]);
    if ($plan['status'] === 'other-doc' || !isset($plan['flows'])) return null;   // בלי בסיס — אין מול מה למזג
    if ($plan['status'] === 'same') return ['doc' => $row['id'], 'status' => 'same', 'changes' => 0, 'conflicts' => []];

    $open = array_values(array_filter($plan['conflicts'], fn($c) => !in_array($answers["{$c['flow']}/{$c['para']}"] ?? null, ['ours', 'theirs', 'both'], true)));
    if ($open) {
        $txt = fn($b) => $b === null ? null : mb_substr(face_block_text($b), 0, 600);
        return ['doc' => $row['id'], 'title' => $row['title'], 'status' => 'conflicts', 'changes' => count($plan['changes']),
            'conflicts' => array_map(fn($c) => ['key' => "{$c['flow']}/{$c['para']}", 'kind' => $c['kind'],
                'base' => $txt($c['base']), 'ours' => $txt($c['ours']), 'theirs' => $txt($c['theirs'])], $plan['conflicts'])];
    }

    MbeMerge::applyMerge($f, $plan, $answers);
    $merged = Mbe::save($f, ['app' => MBE_APP, 'appVersion' => MBE_APP_VERSION, 'who' => $uname]);
    // חזרה למסמך שלנו כמו פתיחה של ‎.mbe: התמונות לאחסון, הגדרות העמוד מ-attrs.page ומ-layout; הזיכרון נשמר ליד המסמך
    $back = import_mbe($merged, $row['id']);
    doc_save($row, $back['json'], null, $uid, $uname, null, $back['page'] ?: null);
    activity($row['id'], $uid, $uname, 'merge', count($plan['changes']) . ' שינויים' . ($plan['conflicts'] ? ', ' . count($plan['conflicts']) . ' התנגשויות' : ''));
    return ['doc' => $row['id'], 'status' => 'merged', 'changes' => count($plan['changes']), 'conflicts' => [], 'answered' => count($plan['conflicts']),
        'gaps' => array_values(array_filter($plan['gaps'], 'is_string'))];
}

/** הטקסט של בלוק (לשאלה למשתמש): כל צומתי הטקסט בסדר, שבירת שורה — רווח. */
function face_block_text($b): string
{
    if (!is_array($b)) return '';
    if (($b['type'] ?? '') === 'text') return (string)($b['text'] ?? '');
    if (($b['type'] ?? '') === 'hardBreak') return ' ';
    $out = '';
    foreach (is_array($b['content'] ?? null) ? $b['content'] : [] as $c) $out .= face_block_text($c);
    return $out;
}
