<?php
/**
 * שכלול שיטה — הצעת שינוי פרמטר והחלה (spec-stage-a 18.7, S7, ✅24, 2ב.2–3).
 *
 * - **המערכת לא משנה פרמטר לבד.** אין חיפוש אוטומטי של ערכים (grid search / אופטימיזציה): המנהל מזין ערך מועמד אחד,
 *   והמערכת רק מודדת.
 * - **המדידה:** אותם נרות (הטווח של הבדיקה לאחור, TS_BT_RANGE), בשני חלקים בלי חפיפה — 70% הראשונים "בתוך הדגימה",
 *   30% האחרונים "מחוץ לדגימה" [שלנו, 18.7]. כל חלק רץ מאפס (מצב מנוע חדש), כך ששום נר מחוץ לדגימה לא משפיע על התוצאה
 *   שבתוכה ולהפך. כל חלק נמדד פעמיים — בערך הנוכחי ובערך המועמד.
 * - **בלי עסקה מחוץ לדגימה — אין הצעה** (קריטריון 3): ההצעה לא נשמרת.
 * - **החלה — רק המנהל הראשי** (= הבעלים, ✅24). מנהל אחר רואה ושומר הצעות, ו"החל" חסום אצלו גם בשרת.
 *   "החל" כותב את הערך ל-methods.params, מעלה את methods.tune (הגרסה = גרסת הקוד + tune, lib/methods/base.php),
 *   ונרשם ב-admin_log עם שתי התוצאות. הגרסה החדשה מחשבת מחדש את הבדיקות לאחור, ורשומות היומן (18.6) נושאות אותה.
 * - **הצעה ישנה לא מוחלת:** אם הגרסה של השיטה או הערך הנוכחי של הפרמטר זזו מאז ההצעה — היא מסומנת stale. לא נמחקת.
 */

declare(strict_types=1);

require_once __DIR__ . '/analysis.php';

const TS_TUNE_OUT_SHARE = 0.3;   // [שלנו, 18.7] — 30% האחרונים של הטווח מחוץ לדגימה

/** תוצאה מקוצרת של חלק אחד */
function ts_tune_summary(array $s): array
{
    $r = ts_bt_result($s);
    return ['n' => $r['n'], 'wins' => $r['wins'], 'win_rate' => $r['win_rate'], 'certainty' => ts_certainty($r['wins'], $r['n']),
        'expectancy_r' => $r['expectancy_r'], 'max_dd' => $r['max_dd'], 'from_t' => $r['from_t'], 'to_t' => $r['to_t'], 'bars' => $r['bars']];
}

/**
 * הנרות (float, ממוינים) → [בתוך הדגימה, מחוץ לדגימה, זמן הנר הראשון מחוץ לדגימה]. טהורה.
 * החלוקה לפי מספר נרות: floor(70%) הראשונים ← בתוך; השאר ← מחוץ. בלי חפיפה.
 */
function ts_tune_split(array $candles): array
{
    $n = count($candles);
    $cut = (int)floor($n * (1 - TS_TUNE_OUT_SHARE));
    $in = array_slice($candles, 0, $cut);
    $out = array_slice($candles, $cut);
    return [$in, $out, $out ? $out[0]['t'] : null];
}

/**
 * הביצועים בערך הנוכחי ובערך המועמד, בתוך הדגימה ומחוצה לה. טהורה (בלי מסד ובלי רשת).
 * מחזיר {split_t, current: {in, out}, candidate: {in, out}}.
 */
function ts_tune_evaluate(TsMethod $m, array $pCur, array $pCand, array $candles, array $cost, ?float $deadline = null): array
{
    [$in, $out, $splitT] = ts_tune_split($candles);
    $res = ['split_t' => $splitT];
    foreach (['current' => $pCur, 'candidate' => $pCand] as $k => $p) {
        $res[$k] = ['in' => ts_tune_summary(ts_bt_run($m, $p, $in, $cost, null, $deadline)),
            'out' => ts_tune_summary(ts_bt_run($m, $p, $out, $cost, null, $deadline))];
    }
    return $res;
}

/** ערך של פרמטר מהקלט — לפי ההגדרה שלו ב-meta (כמו ts_admin_method_save) */
function ts_tune_value(array $d, mixed $v): int|float
{
    if (is_string($v) && is_numeric(trim($v))) $v = trim($v) + 0;
    if ((!is_int($v) && !is_float($v)) || !is_finite((float)$v)) throw new TsInvalid("{$d['label']}: מספר לא תקין");
    if (!empty($d['int']) && (float)$v !== floor((float)$v)) throw new TsInvalid("{$d['label']}: מספר שלם");
    if ($v < $d['min'] || $v > $d['max']) throw new TsInvalid("{$d['label']}: בין {$d['min']} ל-{$d['max']}");
    return !empty($d['int']) ? (int)$v : (float)$v;
}

/**
 * הצעה חדשה (מנהל). $in = {method, param, value, asset, tf, note?}. מחשבת, ושומרת רק כשיש עסקאות מחוץ לדגימה.
 */
function ts_tune_propose(int $uid, array $in, ?int $now = null): array
{
    $now ??= ts_now();
    $id = $in['method'] ?? null;
    if (!is_string($id) || !ts_method_exists($id)) throw new TsNotFound('שיטה לא מוכרת');
    $m = ts_method($id);
    $meta = $m->meta();
    $param = $in['param'] ?? null;
    if (!is_string($param) || !isset($meta['params'][$param])) throw new TsInvalid('פרמטר לא מוכר');
    $d = $meta['params'][$param];
    $set = ts_method_settings($id);
    $pCur = $m->params($set['params']);
    $cand = ts_tune_value($d, $in['value'] ?? null);
    if ((float)$cand === (float)$pCur[$param]) throw new TsInvalid('הערך המועמד זהה לערך הנוכחי');
    $pCand = $m->params([$param => $cand] + $set['params']);
    if ($err = $m->validate($pCand)) throw new TsInvalid($err);

    $asset = $in['asset'] ?? null;
    ts_asset_split($asset);
    $tf = $in['tf'] ?? null;
    if (!is_string($tf) || !isset(TS_TF_SECONDS[$tf])) throw new TsInvalid('מסגרת זמן לא תקינה');
    if (!in_array($tf, $meta['timeframes'], true)) throw new TsInvalid('השיטה לא תומכת במסגרת הזמן הזו');
    $note = ts_text($in['note'] ?? '', 500, true);

    try {
        [$rows] = ts_bt_history($uid, $asset, $tf, $now - TS_BT_RANGE[$tf], $now);
    } catch (TsBudget $e) {
        throw new TsInvalid($e->getMessage());
    } catch (TsProviderError $e) {
        throw new TsInvalid('הנתונים לא התקבלו מהספק — ' . $e->getMessage());
    }
    $dec = ts_tick_decimals($rows);
    $cost = ts_cost_for(ts_asset_market($asset));
    try {
        $res = ts_tune_evaluate($m, ts_params_for_asset($m, $pCur, $dec), ts_params_for_asset($m, $pCand, $dec), ts_bt_floats($rows), $cost,
            microtime(true) + 2 * TS_BT_MAX_SECONDS);
    } catch (TsBacktestTimeout) {
        throw new TsInvalid('החישוב לא הושלם בזמן — נסו מסגרת זמן גדולה יותר');
    }
    // קריטריון 3: בלי חלק מחוץ לדגימה — אין הצעה
    if ($res['split_t'] === null || ($res['current']['out']['n'] === 0 && $res['candidate']['out']['n'] === 0)) {
        throw new TsInvalid('אין עסקאות מחוץ לדגימה (30% האחרונים של הטווח) — אין הצעה. נסו נכס או מסגרת זמן אחרים');
    }
    $db = ts_db();
    $db->prepare('INSERT INTO method_proposals (method_id, method_version, param, current_value, candidate_value, asset, tf, split_t, results, note, created_by, created_at)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$id, $meta['version'], $param, json_encode($pCur[$param]), json_encode($cand), $asset, $tf, $res['split_t'],
            json_encode($res), $note, $uid, $now]);
    $pid = (int)$db->lastInsertId();
    ts_admin_log($uid, 'method_propose', "$id #$pid $param: " . json_encode($pCur[$param]) . ' → ' . json_encode($cand) . " $asset $tf");
    return ts_tune_get($pid);
}

/** הצעה אחת, בצורה של הדפדפן. stale מחושב: גרסה או ערך שזזו מאז */
function ts_tune_row(array $r): array
{
    $status = $r['status'];
    if ($status === 'open' && ts_method_exists($r['method_id'])) {
        $m = ts_method($r['method_id']);
        $set = ts_method_settings($r['method_id']);
        $seg = (string)($r['segment'] ?? '');
        // הצעה לפלח (L5) — מול הערך של הפלח, אם הוחל; אחרת הכללי
        $cur = $m->params(($seg !== '' ? ($set['seg_params'][$seg] ?? []) : []) + $set['params'])[$r['param']] ?? null;
        if ((int)$m->meta()['version'] !== (int)$r['method_version'] || json_encode($cur) !== $r['current_value']) $status = 'stale';
    } elseif ($status === 'open') {
        $status = 'stale';
    }
    return ['id' => (int)$r['id'], 'method' => $r['method_id'], 'method_version' => (int)$r['method_version'], 'param' => $r['param'],
        'current' => json_decode($r['current_value']), 'candidate' => json_decode($r['candidate_value']),
        'asset' => $r['asset'], 'tf' => $r['tf'], 'split_t' => (int)$r['split_t'], 'results' => json_decode($r['results'], true),
        'note' => $r['note'], 'status' => $status, 'created_by' => $r['created_name'] ?? null, 'created_at' => (int)$r['created_at'],
        'applied_by' => $r['applied_name'] ?? null, 'applied_at' => $r['applied_at'] !== null ? (int)$r['applied_at'] : null,
        'new_version' => $r['new_version'] !== null ? (int)$r['new_version'] : null,
        'segment' => (string)($r['segment'] ?? ''), 'origin' => (string)($r['origin'] ?? 'admin'),
        'stats' => isset($r['stats']) && $r['stats'] !== null ? json_decode($r['stats'], true) : null];
}

const TS_TUNE_SELECT = 'SELECT p.*, a.display_name AS created_name, b.display_name AS applied_name FROM method_proposals p
    LEFT JOIN users a ON a.id = p.created_by LEFT JOIN users b ON b.id = p.applied_by';

function ts_tune_get(int $id): array
{
    $st = ts_db()->prepare(TS_TUNE_SELECT . ' WHERE p.id = ?');
    $st->execute([$id]);
    $r = $st->fetch() ?: throw new TsNotFound('ההצעה לא נמצאה');
    return ts_tune_row($r);
}

/** ההצעות (מנהל), החדשות קודם. $method — רק של שיטה אחת */
function ts_tune_list(mixed $method = null): array
{
    $sql = TS_TUNE_SELECT;
    $args = [];
    if (is_string($method) && $method !== '') { $sql .= ' WHERE p.method_id = ?'; $args[] = $method; }
    $st = ts_db()->prepare($sql . ' ORDER BY p.id DESC LIMIT 100');
    $st->execute($args);
    return array_map('ts_tune_row', $st->fetchAll());
}

/**
 * "החל" — רק המנהל הראשי (✅24). כותב את הערך, מעלה את הגרסה ורושם ב-admin_log עם שתי התוצאות. מחזיר את ההצעה.
 */
function ts_tune_apply(int $uid, mixed $id, ?int $now = null): array
{
    ts_require_chief();
    $now ??= ts_now();
    if (!is_int($id)) throw new TsInvalid('הצעה לא תקינה');
    $db = ts_db();
    $db->beginTransaction();
    try {
        $st = $db->prepare('SELECT * FROM method_proposals WHERE id = ?');
        $st->execute([$id]);
        $r = $st->fetch() ?: throw new TsNotFound('ההצעה לא נמצאה');
        $row = ts_tune_row($r);
        if ($row['status'] === 'applied') throw new TsInvalid('ההצעה כבר הוחלה');
        if ($row['status'] !== 'open') {
            $db->prepare("UPDATE method_proposals SET status = 'stale' WHERE id = ?")->execute([$id]);
            $db->commit();
            throw new TsInvalid('ההצעה ישנה — הגרסה של השיטה או הערך הנוכחי השתנו מאז. צריך הצעה חדשה');
        }
        $mid = $r['method_id'];
        $m = ts_method($mid);
        $old = (int)$m->meta()['version'];
        $set = ts_method_settings($mid);
        $params = $set['params'];
        $segp = $set['seg_params'];
        $seg = (string)($r['segment'] ?? '');
        // הצעה לפלח (L5, 23.9): הערך נשמר לפלח — השיטה משתמשת בו בשוק × מסגרת הזה, ובכללי בשאר
        if ($seg === '') $params[$r['param']] = json_decode($r['candidate_value']);
        else $segp[$seg][$r['param']] = json_decode($r['candidate_value']);
        // שורה בטבלת methods — אם עוד אין (שיטת קוד בברירות המחדל), עם ההגדרות הנוכחיות שלה
        $db->prepare('INSERT INTO methods (id, enabled, markets, timeframes, params, note, tune, seg_params) VALUES (?, ?, ?, ?, ?, ?, 1, ?)
                      ON CONFLICT(id) DO UPDATE SET params = excluded.params, seg_params = excluded.seg_params, tune = methods.tune + 1')
            ->execute([$mid, $set['enabled'] ? 1 : 0, json_encode($set['markets']), json_encode($set['timeframes']), json_encode((object)$params), $set['note'],
                json_encode((object)$segp)]);
        ts_method_forget();
        $new = (int)ts_method($mid)->meta()['version'];
        $db->prepare("UPDATE method_proposals SET status = 'applied', applied_by = ?, applied_at = ?, new_version = ? WHERE id = ?")
            ->execute([$uid, $now, $new, $id]);
        // admin_log.target עד 300 תווים — שתי התוצאות בקיצור; המלאות נשארות בהצעה (method_proposals.results)
        $res = json_decode($r['results'], true);
        $part = fn(array $x) => $x['n'] . ' עסק׳ ' . ($x['win_rate'] === null ? '—' : round(100 * $x['win_rate'], 1) . '%') . ' '
            . ($x['expectancy_r'] === null ? '—' : round($x['expectancy_r'], 3) . 'R');
        $both = fn(array $v) => 'בתוך ' . $part($v['in']) . ', מחוץ ' . $part($v['out']);
        ts_admin_log($uid, 'method_tune', "$mid #$id {$r['param']}" . ($seg !== '' ? "@$seg" : '') . ": {$r['current_value']} → {$r['candidate_value']} v$old → v$new · נוכחי: "
            . $both($res['current']) . ' · מועמד: ' . $both($res['candidate']));
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    return ts_tune_get($id);
}

/**
 * הצעה מהלמידה (23.9, L5) — אותה מדידה כמו הצעה של מנהל (S7): הנוכחי מול המועמד, 70% בתוך הדגימה ו-30% מחוצה לה, על נרות
 * הנכס והמסגרת. **אין חיפוש** — רק שני הערכים. created_by = 0 (המערכת), origin = learning, stats — הסטטיסטיקה. לכל היותר הצעה
 * פתוחה אחת לפרמטר ולפלח: הקודמת → stale. בלי עסקאות מחוץ לדגימה — אין הצעה (null). "החל" — רק העורך הראשי (ts_tune_apply).
 */
function ts_tune_learned(TsMethod $m, string $param, string $segment, float $candidate, array $stats, string $asset, string $tf, array $candles, int $now): ?int
{
    $meta = $m->meta();
    $id = $meta['id'];
    $d = $meta['params'][$param];
    $set = ts_method_settings($id);
    $pCur = $m->params(($segment !== '' ? ($set['seg_params'][$segment] ?? []) : []) + $set['params']);
    $cand = !empty($d['int']) ? (int)round($candidate) : (float)$candidate;
    $pCand = $m->params([$param => $cand] + ($segment !== '' ? ($set['seg_params'][$segment] ?? []) : []) + $set['params']);
    if ((float)$pCand[$param] === (float)$pCur[$param] || $m->validate($pCand)) return null;
    $dec = ts_tick_decimals($candles);
    $res = ts_tune_evaluate($m, ts_params_for_asset($m, $pCur, $dec), ts_params_for_asset($m, $pCand, $dec), ts_bt_floats($candles),
        ts_cost_for(ts_asset_market($asset)), microtime(true) + 2 * TS_BT_MAX_SECONDS);
    if ($res['split_t'] === null || ($res['current']['out']['n'] === 0 && $res['candidate']['out']['n'] === 0)) return null;
    $db = ts_db();
    $db->prepare("UPDATE method_proposals SET status = 'stale' WHERE method_id = ? AND param = ? AND segment = ? AND status = 'open'")->execute([$id, $param, $segment]);
    $note = sprintf('מהלמידה: %s %s (רווח סמך %s–%s, N = %d), על 70%% הראשונים של העסקאות', $stats['stat'] === 'stop' ? 'אחוזון 80 של MAE במנצחות'
        : ($stats['stat'] === 'target' ? 'חציון MFE' : 'חציון רוחב האזור במנצחות'), round($stats['raw'], 3), $stats['ci'][0], $stats['ci'][1], $stats['n']);
    $db->prepare('INSERT INTO method_proposals (method_id, method_version, param, current_value, candidate_value, asset, tf, split_t, results, note,
                  created_by, created_at, segment, origin, stats) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?)')
        ->execute([$id, $meta['version'], $param, json_encode($pCur[$param]), json_encode($pCand[$param]), $asset, $tf, $res['split_t'], json_encode($res),
            $note, $now, $segment, 'learning', json_encode($stats)]);
    return (int)$db->lastInsertId();
}
