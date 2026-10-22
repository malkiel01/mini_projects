<?php
/**
 * כיתובים ממוספרים והפניות צולבות (שלב 4) — כמו ב-Word:
 * - seq (שדה SEQ): המספר של הכיתוב — attrs.label ("איור", "טבלה"…; גם שם הרצף) ו-attrs.id (המזהה להפניות).
 *   המספר = המקום של הכיתוב בין הכיתובים עם אותה תווית, לפי הסדר במסמך. התווית עצמה — טקסט רגיל לפניו.
 * - xref (שדה REF): הפניה לכיתוב — attrs.target (ה-id), attrs.show: 'full' ("איור 2") או 'num' ("2").
 * משותף ל-Word (export/import), ל-PDF, ל-HTML ולטקסט. בעורך — אותו חישוב ב-assets/js/caption.js.
 */

const SEQ_LABELS = ['איור', 'טבלה', 'משוואה'];
const XREF_MISSING = 'שגיאה! מקור ההפניה לא נמצא.';

/** תווית תקינה: אותיות בלבד (גם שם רצף ב-Word), עד 20. */
function seq_label($v): string
{
    $s = (string)$v;
    return preg_match('/^\p{L}{1,20}$/u', $s) ? $s : SEQ_LABELS[0];
}

function seq_id($v): ?string
{
    $s = (string)$v;
    return preg_match('/^[A-Za-z0-9]{1,30}$/', $s) ? $s : null;
}

/** [id => ['label' => …, 'n' => …]] — לכל הכיתובים במסמך, לפי הסדר. */
function seq_numbers(array $json): array
{
    $count = [];
    $out = [];
    $walk = function (array $nodes) use (&$walk, &$count, &$out) {
        foreach ($nodes as $n) {
            if (!is_array($n)) continue;
            if (($n['type'] ?? '') === 'seq') {
                $label = seq_label($n['attrs']['label'] ?? '');
                $count[$label] = ($count[$label] ?? 0) + 1;
                $id = seq_id($n['attrs']['id'] ?? '');
                if ($id !== null && !isset($out[$id])) $out[$id] = ['label' => $label, 'n' => $count[$label]];
                continue;
            }
            if (is_array($n['content'] ?? null)) $walk($n['content']);
        }
    };
    $walk($json['content'] ?? []);
    return $out;
}

/** המספר של כל צומת seq לפי הסדר (גם בלי id). */
function seq_text_list(array $json): array
{
    $count = [];
    $out = [];
    $walk = function (array $nodes) use (&$walk, &$count, &$out) {
        foreach ($nodes as $n) {
            if (!is_array($n)) continue;
            if (($n['type'] ?? '') === 'seq') {
                $label = seq_label($n['attrs']['label'] ?? '');
                $out[] = (string)($count[$label] = ($count[$label] ?? 0) + 1);
            } elseif (is_array($n['content'] ?? null)) $walk($n['content']);
        }
    };
    $walk($json['content'] ?? []);
    return $out;
}

/** הטקסט של הפניה צולבת. */
function xref_text(array $attrs, array $nums): string
{
    $t = $nums[seq_id($attrs['target'] ?? '') ?? ''] ?? null;
    if (!$t) return XREF_MISSING;
    return ($attrs['show'] ?? 'full') === 'num' ? (string)$t['n'] : $t['label'] . ' ' . $t['n'];
}

/**
 * המסמך עם seq ו-xref כטקסט רגיל (עם העיצוב שלהם) — ל-PDF, HTML וטקסט, שלא צריכים את השדה עצמו.
 */
function seq_flatten(array $json): array
{
    $nums = seq_numbers($json);
    $list = seq_text_list($json);
    $i = 0;
    $walk = function (array $nodes) use (&$walk, $nums, $list, &$i) {
        $out = [];
        foreach ($nodes as $n) {
            if (!is_array($n)) { $out[] = $n; continue; }
            $t = $n['type'] ?? '';
            if ($t === 'seq' || $t === 'xref') {
                $text = $t === 'seq' ? ($list[$i++] ?? '1') : xref_text($n['attrs'] ?? [], $nums);
                $out[] = ['type' => 'text', 'text' => $text] + (!empty($n['marks']) ? ['marks' => $n['marks']] : []);
                continue;
            }
            if (is_array($n['content'] ?? null)) $n['content'] = $walk($n['content']);
            $out[] = $n;
        }
        return $out;
    };
    if (is_array($json['content'] ?? null)) $json['content'] = $walk($json['content']);
    return $json;
}
