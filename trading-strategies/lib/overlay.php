<?php
/**
 * חוזה ה-overlay (spec-stage-a 17.2.3) — מה שיטה מחזירה לשכבת התחזית על הגרף (17.2.2). float לתצוגה בלבד (עיקרון 6).
 *
 * overlay = {as_of, range: {from, to}, items[], projection: {bars, scenarios[{id, score, points, label}]}}
 * items — חמישה סוגים בלבד:
 *   label    {t, price, text}                       תווית על נר
 *   polyline {points: [{t, price}…], style}        קו; style: solid | dashed
 *   zone     {t1, t2, p1, p2, text}                אזור מחירים
 *   hline    {price, text, role}                   קו אופקי; role: stop | target | invalidation
 *   marker   {t, side, text}                       סימון על נר; side: long | short
 * לכל פריט מותר גם scenario (מזהה) ו-primary (bool) — הספירה המובילה מודגשת, החלופות עמומות (17.1.7).
 *
 * זמנים: עד as_of — זמני נרות; אחריו — t + k·tf בלבד (17.2.2), ועד projection.bars נרות קדימה.
 */

declare(strict_types=1);

final class TsOverlayInvalid extends LogicException {}

const TS_OVERLAY_KINDS = ['label', 'polyline', 'zone', 'hline', 'marker'];
const TS_OVERLAY_ROLES = ['stop', 'target', 'invalidation'];
const TS_OVERLAY_TEXT_MAX = 200;
const TS_OVERLAY_ITEMS_MAX = 200;

/** overlay ריק — כשאין תרחיש */
function ts_overlay_empty(array $candles, int $bars, ?string $note = null): array
{
    $last = $candles ? $candles[count($candles) - 1]['t'] : null;
    return ['as_of' => $last, 'range' => ['from' => $candles ? $candles[0]['t'] : null, 'to' => $last], 'items' => [],
        'projection' => ['bars' => $bars, 'scenarios' => []]] + ($note !== null ? ['note' => $note] : []);
}

/**
 * בודק overlay מול החוזה. $tf — אורך הנר בשניות. זורק TsOverlayInvalid (באג בשיטה — לא קלט של משתמש).
 * מחזיר את ה-overlay כמו שהוא — כך השרת לא מגיש לגרף פריט שהגרף לא יודע לצייר.
 */
function ts_overlay_check(array $ov, int $tf): array
{
    $bad = fn(string $m) => throw new TsOverlayInvalid('overlay: ' . $m);
    foreach (['as_of', 'range', 'items', 'projection'] as $k) if (!array_key_exists($k, $ov)) $bad("חסר $k");
    $asOf = $ov['as_of'];
    $bars = $ov['projection']['bars'] ?? null;
    if (!is_int($bars) || $bars < 0) $bad('projection.bars');
    if (!is_array($ov['items']) || !array_is_list($ov['items']) || count($ov['items']) > TS_OVERLAY_ITEMS_MAX) $bad('items');
    $num = fn($v) => (is_int($v) || is_float($v)) && is_finite((float)$v);
    $time = function ($t) use ($asOf, $tf, $bars, $bad) {
        if (!is_int($t)) $bad('זמן לא שלם');
        if ($asOf !== null && $t > $asOf) {
            if (($t - $asOf) % $tf !== 0) $bad("זמן עתידי לא מיושר למסגרת ($t)");
            if (($t - $asOf) / $tf > $bars) $bad('זמן עתידי מעבר ל-projection.bars');
        }
    };
    $text = function ($s, bool $need = true) use ($bad) {
        if ($s === null && !$need) return;
        if (!is_string($s) || ($need && $s === '') || mb_strlen($s) > TS_OVERLAY_TEXT_MAX) $bad('טקסט');
    };
    $points = function ($pts) use ($time, $num, $bad) {
        if (!is_array($pts) || !array_is_list($pts) || count($pts) < 2) $bad('points');
        foreach ($pts as $p) { $time($p['t'] ?? null); if (!$num($p['price'] ?? null)) $bad('מחיר'); }
    };
    foreach ($ov['items'] as $it) {
        $k = $it['kind'] ?? null;
        if (!in_array($k, TS_OVERLAY_KINDS, true)) $bad('סוג לא מוכר: ' . json_encode($k));
        if (isset($it['primary']) && !is_bool($it['primary'])) $bad('primary');
        switch ($k) {
            case 'label': $time($it['t'] ?? null); if (!$num($it['price'] ?? null)) $bad('label.price'); $text($it['text'] ?? null); break;
            case 'polyline': $points($it['points'] ?? null); if (!in_array($it['style'] ?? null, ['solid', 'dashed'], true)) $bad('style'); break;
            case 'zone':
                $time($it['t1'] ?? null); $time($it['t2'] ?? null);
                if (!$num($it['p1'] ?? null) || !$num($it['p2'] ?? null) || $it['t2'] < $it['t1']) $bad('zone');
                $text($it['text'] ?? null, false);
                break;
            case 'hline': if (!$num($it['price'] ?? null) || !in_array($it['role'] ?? null, TS_OVERLAY_ROLES, true)) $bad('hline'); $text($it['text'] ?? null); break;
            case 'marker': $time($it['t'] ?? null); if (!in_array($it['side'] ?? null, ['long', 'short'], true)) $bad('marker.side'); $text($it['text'] ?? null); break;
        }
    }
    $sc = $ov['projection']['scenarios'] ?? null;
    if (!is_array($sc) || !array_is_list($sc)) $bad('scenarios');
    foreach ($sc as $s) {
        if (!is_string($s['id'] ?? null)) $bad('scenario.id');
        if (($s['score'] ?? null) !== null && !$num($s['score'])) $bad('scenario.score');
        $text($s['label'] ?? null);
        $points($s['points'] ?? null);
    }
    return $ov;
}
