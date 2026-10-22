<?php
/**
 * מעקב שינויים (שלב 4): סימוני ins (נוסף) ו-del (נמחק) על טקסט, עם attrs.author ו-attrs.date.
 * בעורך — assets/js/track.js. כאן: איך הם נראים מחוץ לעורך.
 */

const TRACK_COLORS = ['#c00000', '#2e75b6', '#538135', '#7030a0', '#c55a11', '#00808a'];

/** הצבע של מחבר — אותו חישוב כמו authorColor ב-track.js. */
function track_color(string $author): string
{
    $h = 0;
    foreach (mb_str_split($author) as $ch) $h = ($h * 31 + mb_ord($ch)) & 0xFFFFFFFF;
    return TRACK_COLORS[$h % count(TRACK_COLORS)];
}

/** עובר על כל צומתי הטקסט / השורה; $fn(node) מחזיר את הצומת (או null — להשמיט). */
function track_walk(array $json, callable $fn): array
{
    $walk = function (array $nodes) use (&$walk, $fn) {
        $out = [];
        foreach ($nodes as $n) {
            if (!is_array($n)) continue;
            if (is_array($n['content'] ?? null)) $n['content'] = $walk($n['content']);
            if (array_key_exists('marks', $n) && ($n = $fn($n)) === null) continue;
            $out[] = $n;
        }
        return $out;
    };
    if (is_array($json['content'] ?? null)) $json['content'] = $walk($json['content']);
    return $json;
}

/** כמו בעורך (ובהדפסה): תוספת — קו תחתון, מחיקה — קו חוצה, בצבע של המחבר. ל-PDF ול-HTML. */
function track_visual(array $json): array
{
    return track_view($json);
}

const TRACK_VIEWS = ['all', 'final', 'original'];
/** סימוני העיצוב שנרשמים במעקב (כמו FORMAT_MARKS ב-track.js). */
const TRACK_FORMAT = ['bold', 'italic', 'underline', 'strike', 'textStyle', 'highlight', 'superscript', 'subscript'];

/**
 * המסמך כמו בתצוגת השינויים של העורך (כמו ב-Word): all — סימון מלא; final — סופי (כאילו הכול אושר: המחיקות
 * יוצאות, התוספות — טקסט רגיל); original — המקור (כאילו הכול נדחה). $hide — מחברים שהשינויים שלהם מוסתרים
 * בסימון המלא (מוצגים כמו בסופי). השינויים שנשארים — בצבע המחבר, קו תחתון / חוצה. ל-PDF.
 */
function track_view(array $json, string $view = 'all', array $hide = []): array
{
    $view = in_array($view, TRACK_VIEWS, true) ? $view : 'all';
    return track_walk($json, function (array $n) use ($view, $hide) {
        $marks = $n['marks'] ?? [];
        foreach ($marks as $m) {
            $t = $m['type'] ?? '';
            if ($t === 'fmt') {
                // שינוי עיצוב: במקור — העיצוב הקודם במקום הנוכחי; אחרת — העיצוב הנוכחי (בלי סימון נוסף)
                $rest = array_values(array_filter($marks, fn($x) => ($x['type'] ?? '') !== 'fmt'));
                if ($view === 'original') $rest = array_merge(array_values(array_filter($rest, fn($x) => !in_array($x['type'] ?? '', TRACK_FORMAT, true))),
                    array_values(array_filter(is_array($m['attrs']['old'] ?? null) ? $m['attrs']['old'] : [], fn($x) => in_array($x['type'] ?? '', TRACK_FORMAT, true))));
                $marks = $rest;
                continue;
            }
            if ($t !== 'ins' && $t !== 'del') continue;
            $plain = $view === 'final' || ($view === 'all' && in_array((string)($m['attrs']['author'] ?? ''), $hide, true)) ? 'final' : ($view === 'original' ? 'original' : null);
            if ($plain !== null) {
                // סופי: מחיקה יוצאת; מקור: תוספת יוצאת; השאר — בלי הסימון
                if (($plain === 'final') === ($t === 'del')) return null;
                $n['marks'] = array_values(array_filter($marks, fn($x) => ($x['type'] ?? '') !== $t));
                return $n;
            }
            $line = $t === 'ins' ? 'underline' : 'strike';
            $marks = array_values(array_filter($marks, fn($x) => ($x['type'] ?? '') !== $line));
            $style = null;
            foreach ($marks as $i => $x) if (($x['type'] ?? '') === 'textStyle') $style = $i;
            $color = track_color((string)($m['attrs']['author'] ?? ''));
            if ($style === null) $marks[] = ['type' => 'textStyle', 'attrs' => ['color' => $color]];
            else $marks[$style]['attrs'] = ['color' => $color] + ($marks[$style]['attrs'] ?? []);
            $marks[] = ['type' => $line];
            break;
        }
        $n['marks'] = $marks;
        return $n;
    });
}

/** המסמך אחרי קבלת כל השינויים: מה שנמחק — יוצא, מה שנוסף — טקסט רגיל. לטקסט פשוט. */
function track_final(array $json): array
{
    return track_walk($json, function (array $n) {
        $types = array_column($n['marks'] ?? [], 'type');
        if (in_array('del', $types, true)) return null;
        $n['marks'] = array_values(array_filter($n['marks'], fn($m) => ($m['type'] ?? '') !== 'ins'));
        return $n;
    });
}
