<?php
/**
 * יומן השיטה (spec-stage-a 18.6, S6, ✅27): לכל שיטה — מה היא אמרה, מתי, ומה קרה אחר כך.
 *
 * **רשומה** (method_journal, data/app.sqlite, מיגרציה 11): שיטה וגרסה, נכס, מסגרת, היקף מפתח, as_of (הנר האחרון שנסגר),
 * סוג (analysis / backtest), מה נאמר (ממצא, תרחיש — אזור, טווח זמן, רמת ביטול), והתוצאה.
 *
 * **מתי נכתבת:** רק כשהשיטה **באמת רצה** (ts_method_result → ran, 18.3) — כלומר נר סגור חדש. רענון בלי נר חדש מחזיר את
 * השמור ולא מגיע לכאן. וגם אם שני מפתחות חישוב שונים (טווח נרות אחר) רצים על אותו נר אחרון — ה-UNIQUE של הטבלה
 * והכתיבה ב-INSERT OR IGNORE משאירים רשומה אחת, הראשונה. **רשומה לא משוכתבת לעולם** — גם לא בגרסה חדשה של השיטה:
 * הגרסה בתוך המפתח, ולכן גרסה חדשה כותבת רשומה משלה.
 *
 * **התוצאה — רק ב-cron** (bin/alerts-cron.php, 8.2), מנרות סגורים בלבד, ורק אחרי שעבר טווח הזמן של התרחיש
 * (until_t + נר אחד, כדי שהנר האחרון בטווח ייסגר). עד אז — NULL, "עוד לא ידוע". ראו ts_journal_outcome.
 *
 * **של מי (✅27, ✅2):** ברמת המערכת, בלי user_id. נכס שנמשך במפתח אישי (cred_scope 'u:N') — רק לבעליו; ספק מהמנהל
 * ('c:N') — למי ששויך לו; משותף ('') — לכולם. המנהל רואה הכול.
 */

declare(strict_types=1);

require_once __DIR__ . '/analysis.php';
require_once __DIR__ . '/pairs.php';

const TS_JOURNAL_OUTCOMES = ['zone' => 'נכנס לאזור', 'invalidated' => 'הגיע לרמת הביטול', 'neither' => 'אף אחד מהם', 'none' => 'אין תרחיש'];
/** כמה רשומות לכל היותר נבדקות בריצת cron אחת — השאר בריצה הבאה */
const TS_JOURNAL_RESOLVE_MAX = 200;
const TS_JOURNAL_PAGE = 50;

// ── כתיבה ────────────────────────────────────────────────

/**
 * מה השיטה אמרה, מתוך התוצאה של ts_overlay_compute (S4): הממצא, והתרחיש **המוביל** (primary; אם אין סימון — הראשון).
 * מחזיר [said, zone_lo, zone_hi, invalidation, until_t] — מחירים כמחרוזות עשרוניות לפי דיוק הנכס.
 */
function ts_journal_said(array $res, string $tf): array
{
    $dec = (int)($res['tick_decimals'] ?? 8);
    $fc = is_array($res['forecast'] ?? null) ? $res['forecast'] : ['items' => [], 'scenarios' => [], 'bars' => 0, 'as_of' => null];
    $items = $fc['items'] ?? [];
    $prim = array_values(array_filter($items, fn($it) => ($it['primary'] ?? null) === true));
    $pool = $prim ?: $items;
    $zone = null; $inv = null;
    foreach ($pool as $it) {
        if (!$zone && $it['kind'] === 'zone') $zone = $it;
        if (!$inv && $it['kind'] === 'hline' && ($it['role'] ?? '') === 'invalidation') $inv = $it;
    }
    $scen = null;
    foreach ($fc['scenarios'] ?? [] as $s) if (!empty($s['primary'])) { $scen = $s; break; }
    $scen ??= ($fc['scenarios'][0] ?? null);

    // הממצא: התווית של הספירה המובילה (אליוט), או הסימון האחרון של השיטה, או ההערה שלה
    $finding = $scen['label'] ?? null;
    if ($finding === null) {
        foreach (array_reverse($res['overlay']['items'] ?? []) as $it) if (($it['kind'] ?? '') === 'marker') { $finding = $it['text']; break; }
    }
    $finding ??= $res['overlay']['note'] ?? null;

    $asOf = $fc['as_of'] ?? ($res['overlay']['as_of'] ?? null);
    $lo = $hi = $iv = null; $until = null;
    if ($zone) {
        $lo = ts_level_str((float)min($zone['p1'], $zone['p2']), $dec);
        $hi = ts_level_str((float)max($zone['p1'], $zone['p2']), $dec);
        $until = (int)$zone['t2'];
    }
    if ($inv) {
        $iv = ts_level_str((float)$inv['price'], $dec);
        // רמת ביטול בלי אזור — הטווח הוא אופק התרחיש של השיטה
        if ($until === null && $asOf !== null && (int)($fc['bars'] ?? 0) > 0) $until = (int)$asOf + (int)$fc['bars'] * TS_TF_SECONDS[$tf];
    }
    if ($until !== null && $asOf !== null && $until <= (int)$asOf) $until = null;   // תרחיש שכבר הסתיים — אין מה לבדוק
    $scenario = ($lo !== null || $iv !== null) && $until !== null ? [
        'text' => $zone['text'] ?? ($scen['label'] ?? null),
        'zone' => $lo !== null ? ['lo' => $lo, 'hi' => $hi] : null,
        'until' => $until,
        'invalidation' => $iv,
        'invalidation_text' => $inv['text'] ?? null,
    ] : null;
    if (!$scenario) { $lo = $hi = $iv = null; $until = null; }
    return [['finding' => $finding, 'scenario' => $scenario], $lo, $hi, $iv, $until];
}

/** רשומת ניתוח — מ-ts_method_result, רק כשהשיטה רצה (נר סגור חדש). כשל כאן לא מפיל את הרענון */
function ts_journal_analysis(TsMethod $m, string $asset, string $tf, string $scope, array $res, int $now): void
{
    try {
        if (($res['status'] ?? '') !== 'ok') return;
        $asOf = $res['forecast']['as_of'] ?? ($res['overlay']['as_of'] ?? null);
        if (!is_int($asOf)) return;   // אין נרות סגורים — אין מה לרשום
        [$said, $lo, $hi, $iv, $until] = ts_journal_said($res, $tf);
        $meta = $m->meta();
        ts_db()->prepare("INSERT OR IGNORE INTO method_journal (method_id, method_version, asset, tf, cred_scope, as_of, kind, said,
                zone_lo, zone_hi, invalidation, until_t, outcome, resolved_at, created_at) VALUES (?, ?, ?, ?, ?, ?, 'analysis', ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$meta['id'], (int)$meta['version'], $asset, $tf, $scope, $asOf,
                json_encode($said, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
                $lo, $hi, $iv, $until, $said['scenario'] ? null : 'none', $said['scenario'] ? null : $now, $now]);
    } catch (Throwable $e) {
        error_log('trading-strategies method_journal: ' . $e->getMessage());
    }
}

/** רשומת בדיקה לאחור — מ-ts_backtest, רק כשחושבה מחדש. as_of = הנר הסגור האחרון שנבדק */
function ts_journal_backtest(array $meta, string $asset, string $tf, string $scope, array $r, int $now): void
{
    try {
        $asOf = isset($r['to_t']) ? (int)$r['to_t'] : 0;
        if ($asOf <= 0) return;
        $said = ['backtest' => ['n' => (int)$r['n'], 'wins' => (int)$r['wins'], 'expectancy_r' => $r['expectancy_r'] ?? null,
            'max_dd' => $r['max_dd'] ?? null, 'from_t' => isset($r['from_t']) ? (int)$r['from_t'] : null, 'to_t' => $asOf]];
        ts_db()->prepare("INSERT OR IGNORE INTO method_journal (method_id, method_version, asset, tf, cred_scope, as_of, kind, said, outcome,
                resolved_at, created_at) VALUES (?, ?, ?, ?, ?, ?, 'backtest', ?, 'none', ?, ?)")
            ->execute([$meta['id'], (int)$meta['version'], $asset, $tf, $scope, $asOf,
                json_encode($said, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), $now, $now]);
    } catch (Throwable $e) {
        error_log('trading-strategies method_journal: ' . $e->getMessage());
    }
}

// ── התוצאה (cron) ────────────────────────────────────────

/** השוואת מחירים בחשבון העשרוני; מחרוזת שאינה עשרונית תקינה — כמספר */
function ts_journal_cmp(string $a, string $b): int
{
    $x = dec_parse($a, 20); $y = dec_parse($b, 20);
    return $x !== null && $y !== null ? dec_cmp($x, $y) : ((float)$a <=> (float)$b);
}

/**
 * התוצאה של רשומה מהנרות הסגורים שאחרי as_of ועד until_t, לפי הסדר: הנר הראשון שנגע באזור (שפל ≤ הגבוה של האזור
 * וגבוה ≥ הנמוך שלו) → zone; הנר הראשון שהגיע לרמת הביטול → invalidated. רמת ביטול מתחת לאזור — נגיעה בשפל; מעליו —
 * בגבוה. **שניהם באותו נר** — אי אפשר לדעת מה קודם מנר אחד, ולכן invalidated (השמרני), עם הערה same_candle.
 * אף אחד מהם עד סוף הטווח → neither. מחזיר [outcome, t של הנר המכריע או null, note או null].
 */
function ts_journal_outcome(array $row, array $candles): array
{
    $lo = $row['zone_lo']; $hi = $row['zone_hi']; $iv = $row['invalidation'];
    $side = null;
    if ($iv !== null) $side = $lo !== null ? (ts_journal_cmp($iv, $lo) < 0 ? 'below' : (ts_journal_cmp($iv, $hi) > 0 ? 'above' : 'inside')) : null;
    foreach ($candles as $c) {
        $h = (string)$c['h']; $l = (string)$c['l'];
        $inZone = $lo !== null && ts_journal_cmp($l, $hi) <= 0 && ts_journal_cmp($h, $lo) >= 0;
        $hitInv = false;
        if ($iv !== null) {
            $hitInv = match ($side) {
                'below' => ts_journal_cmp($l, $iv) <= 0,
                'above' => ts_journal_cmp($h, $iv) >= 0,
                // רמת ביטול בלי אזור (או בתוכו): נגיעה מכל כיוון
                default => ts_journal_cmp($l, $iv) <= 0 && ts_journal_cmp($h, $iv) >= 0,
            };
        }
        if ($inZone && $hitInv) return ['invalidated', (int)$c['t'], 'same_candle'];
        if ($hitInv) return ['invalidated', (int)$c['t'], null];
        if ($inZone) return ['zone', (int)$c['t'], null];
    }
    return ['neither', null, $candles ? null : 'no_candles'];
}

/** המשתמש שבמפתח שלו נבדקת רשומה: 'u:N' → N; 'c:N' → מי ששויך למפתח; '' → 0 (ספק בלי מפתח, משותף) */
function ts_journal_uid_for(string $scope): ?int
{
    if ($scope === '') return 0;
    if (str_starts_with($scope, 'u:')) return (int)substr($scope, 2);
    if (str_starts_with($scope, 'c:')) {
        $st = ts_db()->prepare('SELECT user_id FROM provider_grants WHERE cred_id = ? ORDER BY user_id LIMIT 1');
        $st->execute([(int)substr($scope, 2)]);
        $u = $st->fetchColumn();
        return $u === false ? null : (int)$u;
    }
    return null;
}

/**
 * ממלא את התוצאה של רשומות שהטווח שלהן עבר. נקרא מ-ts_alerts_run, בתוך הנעילה ובאותו תקציב לספק ($used, $cap) ובאותה
 * תקרת זמן. רשומה שאין לה עכשיו גישה לנרות (המפתח נמחק), או שהספק נכשל — נשארת "עוד לא ידוע" לריצה הבאה.
 */
function ts_journal_resolve(int $now, array &$used, ?array $budget, float $t0): array
{
    $sum = ['checked' => 0, 'resolved' => 0, 'deferred' => 0, 'skipped' => 0, 'errors' => 0];
    $db = ts_db();
    $st = $db->prepare('SELECT * FROM method_journal WHERE outcome IS NULL AND until_t IS NOT NULL ORDER BY until_t, id LIMIT ' . TS_JOURNAL_RESOLVE_MAX);
    $st->execute();
    $upd = $db->prepare('UPDATE method_journal SET outcome = ?, outcome_t = ?, outcome_note = ?, resolved_at = ? WHERE id = ? AND outcome IS NULL');
    foreach ($st->fetchAll() as $row) {
        $sec = TS_TF_SECONDS[$row['tf']] ?? null;
        if (!$sec || !ts_method_exists($row['method_id'])) { $sum['skipped']++; continue; }   // שיטה שהוסרה (21.1) — נשארת כמו שהיא
        $until = (int)$row['until_t'];
        if ($until + $sec > $now) continue;            // הנר האחרון בטווח עוד לא נסגר — "עוד לא ידוע"
        $sum['checked']++;
        if (microtime(true) - $t0 > TS_CRON_MAX_SECONDS) { $sum['deferred']++; continue; }
        [$provider] = explode(':', $row['asset'], 2);
        $cap = $budget[$provider] ?? ts_provider_limits($provider)['per_min'];
        if (($used[$provider] ?? 0) >= $cap) { $sum['deferred']++; continue; }
        $uid = ts_journal_uid_for($row['cred_scope']);
        $acc = $uid === null ? null : ts_access($uid, $provider);
        if (!$acc || (string)$acc['scope'] !== $row['cred_scope']) { $sum['skipped']++; continue; }
        try {
            $r = ts_market_candles($uid, $row['asset'], $row['tf'], (int)$row['as_of'] + $sec, $until + $sec, $now);
            $used[$provider] = ($used[$provider] ?? 0) + $r['provider_calls'];
        } catch (Throwable $e) {
            $sum['errors']++;
            error_log('trading-strategies journal ' . $row['id'] . ': ' . $e->getMessage());
            continue;
        }
        $closed = array_values(array_filter($r['candles'], fn($c) => $c['t'] > (int)$row['as_of'] && $c['t'] <= $until && $c['t'] + $sec <= $now));
        [$out, $t, $note] = ts_journal_outcome($row, $closed);
        $upd->execute([$out, $t, $note, $now, $row['id']]);
        $sum['resolved']++;
    }
    return $sum;
}

// ── קריאה ────────────────────────────────────────────────

/** תנאי ה-SQL של מה שהמשתמש רואה (✅27, ✅2) + הפרמטרים שלו. מנהל — הכול */
function ts_journal_scope_sql(int $uid): array
{
    // שיטה שהוסרה (21.1) — הרשומות שלה נשארות במסד, ולא מוצגות גם למנהל
    $ids = ts_is_admin($uid) ? ts_method_ids() : array_column(ts_methods_visible($uid), 'id');
    $in = $ids ? implode(',', array_fill(0, count($ids), '?')) : "''";
    if (ts_is_admin($uid)) return ["method_id IN ($in)", $ids];
    $grants = ts_shared_enabled() ? "OR cred_scope IN (SELECT 'c:' || cred_id FROM provider_grants WHERE user_id = ?)" : '';
    return ["method_id IN ($in) AND (cred_scope = '' OR cred_scope = ? $grants)", [...$ids, "u:$uid", ...($grants ? [$uid] : [])]];
}

function ts_journal_method_name(string $id): string
{
    static $names = [];
    if (!isset($names[$id])) {
        try {
            $names[$id] = ts_method_exists($id) ? (string)ts_method($id)->meta()['name'] : $id;
        } catch (Throwable) {
            $names[$id] = $id;
        }
    }
    return $names[$id];
}

function ts_journal_out(array $r, bool $admin): array
{
    return ['id' => (int)$r['id'], 'method' => ['id' => $r['method_id'], 'name' => ts_journal_method_name($r['method_id']), 'version' => (int)$r['method_version']],
        'asset' => $r['asset'], 'tf' => $r['tf'], 'as_of' => (int)$r['as_of'], 'kind' => $r['kind'],
        'said' => json_decode($r['said'], true) ?: (object)[],
        'zone_lo' => $r['zone_lo'], 'zone_hi' => $r['zone_hi'], 'invalidation' => $r['invalidation'],
        'until_t' => $r['until_t'] !== null ? (int)$r['until_t'] : null,
        'outcome' => $r['outcome'], 'outcome_text' => $r['outcome'] !== null ? TS_JOURNAL_OUTCOMES[$r['outcome']] : 'עוד לא ידוע',
        'outcome_t' => $r['outcome_t'] !== null ? (int)$r['outcome_t'] : null, 'outcome_note' => $r['outcome_note'],
        'resolved_at' => $r['resolved_at'] !== null ? (int)$r['resolved_at'] : null, 'created_at' => (int)$r['created_at']]
        + ($admin ? ['scope_kind' => ts_scope_kind((string)$r['cred_scope'])] : [])
        + ts_pair_pick(ts_pair_label((string)$r['asset']));
}

/**
 * היומן כפי שהמשתמש רואה אותו: רשומות (העדכניות קודם, עמוד של 50, before = id) + סיכום לכל (שיטה, גרסה) על **כל**
 * הרשומות שהוא רואה — לא רק העמוד. סינון: method, version, outcome (pending | zone | invalidated | neither | none), kind.
 */
function ts_journal_list(int $uid, array $q): array
{
    $admin = ts_is_admin($uid);
    [$where, $args] = ts_journal_scope_sql($uid);
    $sumRows = ts_db()->prepare("SELECT method_id, method_version, COUNT(*) AS n, SUM(outcome IS NULL) AS pending,
            SUM(outcome = 'zone') AS zone, SUM(outcome = 'invalidated') AS invalidated, SUM(outcome = 'neither') AS neither,
            SUM(outcome = 'none') AS none, MIN(as_of) AS first_t, MAX(as_of) AS last_t
        FROM method_journal WHERE kind = 'analysis' AND $where GROUP BY method_id, method_version ORDER BY method_id, method_version DESC");
    $sumRows->execute($args);
    $summary = array_map(function ($r) {
        $decided = (int)$r['zone'] + (int)$r['invalidated'] + (int)$r['neither'];
        return ['method' => ['id' => $r['method_id'], 'name' => ts_journal_method_name($r['method_id']), 'version' => (int)$r['method_version']],
            'total' => (int)$r['n'], 'pending' => (int)$r['pending'], 'zone' => (int)$r['zone'], 'invalidated' => (int)$r['invalidated'],
            'neither' => (int)$r['neither'], 'none' => (int)$r['none'], 'decided' => $decided,
            'zone_pct' => $decided ? (int)round(100 * (int)$r['zone'] / $decided) : null,
            'first_t' => (int)$r['first_t'], 'last_t' => (int)$r['last_t']];
    }, $sumRows->fetchAll());

    $cond = [$where]; $p = $args;
    if (isset($q['method']) && is_string($q['method']) && $q['method'] !== '') { $cond[] = 'method_id = ?'; $p[] = $q['method']; }
    if (isset($q['version']) && is_numeric($q['version'])) { $cond[] = 'method_version = ?'; $p[] = (int)$q['version']; }
    if (isset($q['kind']) && in_array($q['kind'], ['analysis', 'backtest'], true)) { $cond[] = 'kind = ?'; $p[] = $q['kind']; }
    if (isset($q['outcome']) && is_string($q['outcome'])) {
        if ($q['outcome'] === 'pending') $cond[] = 'outcome IS NULL';
        elseif (isset(TS_JOURNAL_OUTCOMES[$q['outcome']])) { $cond[] = 'outcome = ?'; $p[] = $q['outcome']; }
    }
    if (isset($q['before']) && is_numeric($q['before'])) { $cond[] = 'id < ?'; $p[] = (int)$q['before']; }
    $st = ts_db()->prepare('SELECT * FROM method_journal WHERE ' . implode(' AND ', $cond) . ' ORDER BY id DESC LIMIT ' . (TS_JOURNAL_PAGE + 1));
    $st->execute($p);
    $rows = $st->fetchAll();
    $more = count($rows) > TS_JOURNAL_PAGE;
    $rows = array_slice($rows, 0, TS_JOURNAL_PAGE);
    return ['rows' => array_map(fn($r) => ts_journal_out($r, $admin), $rows), 'more' => $more, 'summary' => $summary,
        'outcomes' => TS_JOURNAL_OUTCOMES, 'is_admin' => $admin];
}
