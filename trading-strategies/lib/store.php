<?php
/**
 * הנתונים של הסוחר: אסטרטגיות וגרסאות, נכסים, עסקאות ויציאות, הגדרות וייצוא.
 *
 * כל פונקציה מקבלת את $uid של ה-session (lib/auth.php) — לא מזהה משתמש מהדפדפן — וכל שאילתה מסוננת
 * לפיו (אפיון עיקרון 3, פרק 4). רשומה של משתמש אחר נראית בדיוק כמו רשומה שלא קיימת: "לא נמצא".
 * שאילתות עם פרמטרים בלבד.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/calc.php';

final class TsInvalid extends RuntimeException {}
final class TsNotFound extends RuntimeException {}

const TS_TIMEFRAMES = ['m1' => 'דקה', 'm5' => '5 דקות', 'm15' => '15 דקות', 'm30' => '30 דקות', 'h1' => 'שעה',
    'h4' => '4 שעות', 'd1' => 'יום', 'w1' => 'שבוע', 'mn1' => 'חודש'];
const TS_STYLES = ['scalp' => 'סקאלפ', 'day' => 'יומי', 'swing' => 'סווינג', 'position' => 'פוזיציה'];
const TS_STATUSES = ['draft' => 'טיוטה', 'active' => 'פעילה', 'archived' => 'בארכיון'];
const TS_MOODS = ['calm' => 'רגוע', 'confident' => 'בטוח', 'anxious' => 'לחוץ', 'fearful' => 'חושש',
    'greedy' => 'חמדן', 'bored' => 'משועמם', 'tired' => 'עייף'];
const TS_PLAN = ['yes' => 'כן', 'no' => 'לא', 'partial' => 'חלקית'];
const TS_EXIT_KEYS = ['stop', 'target', 'partial', 'time', 'trailing'];
const TS_RISK_KEYS = ['risk_pct', 'max_daily_loss', 'max_open', 'min_rr'];
const TS_MAX_ROWS = 100;      // כללים ברשימה, יציאות בעסקה
const TS_MAX_TEXT = 4000;

// ── קלט ──────────────────────────────────────────────────

function ts_text(mixed $v, int $max = 200, bool $multiline = false): string
{
    if ($v === null) return '';
    if (!is_string($v) && !is_int($v)) throw new TsInvalid('שדה טקסט לא תקין');
    $s = trim((string)$v);
    $s = preg_replace($multiline ? '/[^\P{C}\n\t]/u' : '/\p{C}/u', '', $s) ?? '';   // תווי בקרה החוצה
    if (mb_strlen($s) > $max) throw new TsInvalid("טקסט ארוך מדי (עד $max תווים)");
    return $s;
}

function ts_enum(mixed $v, array $allowed, string $what, bool $nullable = false): ?string
{
    if ($nullable && ($v === null || $v === '')) return null;
    if (!is_string($v) || !array_key_exists($v, $allowed)) throw new TsInvalid("$what לא תקין");
    return $v;
}

/** מספר עשרוני חיובי (או אפס כש-$zero) */
function ts_num(mixed $v, string $what, bool $nullable = false, bool $zero = true, int $scale = TS_DEC_MAX_SCALE): ?string
{
    if ($nullable && ($v === null || $v === '')) return null;
    $d = dec_parse($v, $scale);
    if ($d === null) throw new TsInvalid("$what: מספר לא תקין");
    if (dec_sign($d) < 0 || (!$zero && dec_sign($d) === 0)) throw new TsInvalid("$what חייב להיות " . ($zero ? 'אפס או יותר' : 'גדול מאפס'));
    return $d;
}

function ts_currency(mixed $v): string
{
    $c = strtoupper(trim((string)(is_string($v) ? $v : '')));
    if (!preg_match('/^[A-Z0-9]{2,6}$/', $c)) throw new TsInvalid('מטבע לא תקין (למשל USD, ILS, USDT)');
    return $c;
}

function ts_tz(mixed $v): string
{
    if (!is_string($v) || !in_array($v, DateTimeZone::listIdentifiers(), true)) throw new TsInvalid('אזור זמן לא תקין');
    return $v;
}

/** זמן מקומי מהטופס ("2026-11-07T14:30") → UTC במסד ("2026-11-07T12:30:00Z") */
function ts_time_in(mixed $v, string $tz, string $what): string
{
    if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/', $v)) throw new TsInvalid("$what: תאריך ושעה לא תקינים");
    $d = DateTimeImmutable::createFromFormat(strlen($v) === 16 ? 'Y-m-d\TH:i' : 'Y-m-d\TH:i:s', $v, new DateTimeZone($tz));
    $err = DateTimeImmutable::getLastErrors();
    if (!$d || ($err && ($err['warning_count'] || $err['error_count']))) throw new TsInvalid("$what: תאריך ושעה לא תקינים");
    return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
}

function ts_time_out(?string $utc, string $tz): ?string
{
    return $utc ? (new DateTimeImmutable($utc))->setTimezone(new DateTimeZone($tz))->format('Y-m-d\TH:i') : null;
}

/** תאריך מקומי ("2026-11-07") → גבול UTC: תחילת היום, או תחילת היום שאחריו */
function ts_day_bound(mixed $v, string $tz, bool $end): ?string
{
    if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return null;
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v, new DateTimeZone($tz));
    if (!$d) return null;
    if ($end) $d = $d->modify('+1 day');
    return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
}

function ts_list(mixed $v, string $what): array
{
    if ($v === null) return [];
    if (!is_array($v) || !array_is_list($v)) throw new TsInvalid("$what לא תקין");
    if (count($v) > TS_MAX_ROWS) throw new TsInvalid("$what: יותר מדי שורות");
    return $v;
}

function ts_json(mixed $v): string
{
    return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function ts_unjson(?string $s, mixed $default = []): mixed
{
    $v = $s === null ? null : json_decode($s, true);
    return $v ?? $default;
}

// ── הגדרות ────────────────────────────────────────────────

function ts_settings_out(array $u): array
{
    return [
        'display_name' => $u['display_name'],
        'email' => $u['email'],
        'base_currency' => $u['base_currency'],
        'timezone' => $u['timezone'],
        'account_size' => $u['account_size'],
        'default_risk_pct' => $u['default_risk_pct'],
    ];
}

function ts_settings_save(int $uid, array $in): void
{
    $pct = ts_num($in['default_risk_pct'] ?? '', 'סיכון לעסקה', false, false, 4);
    if (dec_cmp($pct, '100') > 0) throw new TsInvalid('סיכון לעסקה — עד 100%');
    ts_db()->prepare('UPDATE users SET base_currency = ?, timezone = ?, account_size = ?, default_risk_pct = ? WHERE id = ?')
        ->execute([ts_currency($in['base_currency'] ?? ''), ts_tz($in['timezone'] ?? ''),
            ts_num($in['account_size'] ?? null, 'גודל חשבון', true, false, 2), $pct, $uid]);
}

// ── אסטרטגיות ─────────────────────────────────────────────

function ts_strategy_row(int $uid, int $id): array
{
    $st = ts_db()->prepare('SELECT * FROM strategies WHERE id = ? AND user_id = ?');
    $st->execute([$id, $uid]);
    return $st->fetch() ?: throw new TsNotFound('האסטרטגיה לא נמצאה');
}

function ts_version_out(array $v): array
{
    return [
        'id' => (int)$v['id'],
        'version_no' => (int)$v['version_no'],
        'thesis' => $v['thesis'],
        'setup' => ts_unjson($v['setup']),
        'entry_rules' => ts_unjson($v['entry_rules']),
        'exit_rules' => ts_unjson($v['exit_rules'], (object)[]),
        'risk' => ts_unjson($v['risk'], (object)[]),
        'notes' => $v['notes'],
        'created_at' => (int)$v['created_at'],
    ];
}

function ts_strategy_out(array $s): array
{
    return [
        'id' => (int)$s['id'],
        'name' => $s['name'],
        'summary' => $s['summary'],
        'markets' => ts_unjson($s['markets']),
        'examples' => $s['examples'],
        'timeframe' => $s['timeframe'],
        'style' => $s['style'],
        'tags' => ts_unjson($s['tags']),
        'status' => $s['status'],
        'current_version_id' => $s['current_version_id'] !== null ? (int)$s['current_version_id'] : null,
        'updated_at' => (int)$s['updated_at'],
    ];
}

/** הרשימה (אפיון 2.1) — עם מספר עסקאות, אחוז הצלחה ותוחלת ב-R של כל האסטרטגיה */
function ts_strategies_list(int $uid, string $tz): array
{
    $db = ts_db();
    $st = $db->prepare('SELECT s.*, v.version_no FROM strategies s LEFT JOIN strategy_versions v ON v.id = s.current_version_id
                        WHERE s.user_id = ? ORDER BY s.status = \'archived\', s.updated_at DESC');
    $st->execute([$uid]);
    $rows = $st->fetchAll();
    $byStrategy = [];
    foreach (ts_trades_load($uid, $tz) as $t) {
        if ($t['strategy_id'] !== null) $byStrategy[$t['strategy_id']][] = $t;
    }
    return array_map(function ($s) use ($byStrategy) {
        $all = $byStrategy[(int)$s['id']] ?? [];
        $closed = array_values(array_filter($all, fn($t) => $t['calc']['status'] === 'closed'));
        return ts_strategy_out($s) + [
            'version_no' => $s['version_no'] !== null ? (int)$s['version_no'] : null,
            'trades' => count($all),
        ] + ['stats' => ts_summary($closed)];
    }, $rows);
}

/** אסטרטגיה מלאה, עם כל הגרסאות וסיכום לכל גרסה */
function ts_strategy_get(int $uid, int $id, string $tz): array
{
    $s = ts_strategy_row($uid, $id);
    $st = ts_db()->prepare('SELECT * FROM strategy_versions WHERE strategy_id = ? ORDER BY version_no');
    $st->execute([$id]);
    $byVersion = []; $closedAll = [];
    foreach (ts_trades_load($uid, $tz, ['strategy_id' => $id]) as $t) {
        $byVersion[$t['strategy_version_id']][] = $t;
        if ($t['calc']['status'] === 'closed') $closedAll[] = $t;
    }
    $versions = array_map(function ($v) use ($byVersion) {
        $all = $byVersion[(int)$v['id']] ?? [];
        $closed = array_values(array_filter($all, fn($t) => $t['calc']['status'] === 'closed'));
        return ts_version_out($v) + ['trades' => count($all), 'stats' => ts_summary($closed)];
    }, $st->fetchAll());
    return ts_strategy_out($s) + ['versions' => $versions, 'stats' => ts_summary($closedAll)];
}

/** החלק שבגרסה — "הכללים" (אפיון 2.2). שינוי בהם, כשלגרסה יש עסקאות, פותח גרסה חדשה. */
function ts_rules_in(array $in): array
{
    $setup = [];
    foreach (ts_list($in['setup'] ?? [], 'תנאי מקדים') as $line) {
        $line = ts_text($line, 500);
        if ($line !== '') $setup[] = $line;
    }
    $entry = [];
    foreach (ts_list($in['entry_rules'] ?? [], 'כללי כניסה') as $r) {
        if (!is_array($r)) throw new TsInvalid('כלל כניסה לא תקין');
        $text = ts_text($r['text'] ?? '', 500);
        if ($text !== '') $entry[] = ['text' => $text, 'required' => ($r['required'] ?? true) === true];
    }
    $exitIn = is_array($in['exit_rules'] ?? null) ? $in['exit_rules'] : [];
    $exit = [];
    foreach (TS_EXIT_KEYS as $k) $exit[$k] = ts_text($exitIn[$k] ?? '', 1000, true);
    $riskIn = is_array($in['risk'] ?? null) ? $in['risk'] : [];
    $risk = [
        'risk_pct' => ts_num($riskIn['risk_pct'] ?? null, 'סיכון לעסקה', true, false, 4),
        'max_daily_loss' => ts_num($riskIn['max_daily_loss'] ?? null, 'הפסד יומי מרבי', true, false, 4),
        'max_open' => ts_num($riskIn['max_open'] ?? null, 'פוזיציות פתוחות מרבי', true, false, 0),
        'min_rr' => ts_num($riskIn['min_rr'] ?? null, 'יחס סיכוי/סיכון מינימלי', true, false, 2),
    ];
    if ($risk['risk_pct'] !== null && dec_cmp($risk['risk_pct'], '100') > 0) throw new TsInvalid('סיכון לעסקה — עד 100%');
    if ($risk['max_daily_loss'] !== null && dec_cmp($risk['max_daily_loss'], '100') > 0) throw new TsInvalid('הפסד יומי מרבי — עד 100%');
    return [
        'thesis' => ts_text($in['thesis'] ?? '', TS_MAX_TEXT, true),
        'setup' => ts_json($setup),
        'entry_rules' => ts_json($entry),
        'exit_rules' => ts_json($exit),
        'risk' => ts_json($risk),
    ];
}

function ts_meta_in(array $in): array
{
    $name = ts_text($in['name'] ?? '', 120);
    if ($name === '') throw new TsInvalid('חסר שם לאסטרטגיה');
    $markets = [];
    foreach (ts_list($in['markets'] ?? [], 'שווקים') as $m) $markets[] = ts_enum($m, TS_MARKETS, 'שוק');
    $tags = [];
    foreach (ts_list($in['tags'] ?? [], 'תגיות') as $t) {
        $t = ts_text($t, 40);
        if ($t !== '' && !in_array($t, $tags, true)) $tags[] = $t;
    }
    return [
        'name' => $name,
        'summary' => ts_text($in['summary'] ?? '', 300),
        'markets' => ts_json(array_values(array_unique($markets))),
        'examples' => ts_text($in['examples'] ?? '', 300),
        'timeframe' => (string)ts_enum($in['timeframe'] ?? '', TS_TIMEFRAMES, 'מסגרת זמן', true),
        'style' => (string)ts_enum($in['style'] ?? '', TS_STYLES, 'סגנון', true),
        'tags' => ts_json($tags),
        'status' => ts_enum($in['status'] ?? 'draft', TS_STATUSES, 'מצב'),
    ];
}

function ts_version_has_trades(int $versionId): bool
{
    $st = ts_db()->prepare('SELECT 1 FROM trades WHERE strategy_version_id = ? LIMIT 1');
    $st->execute([$versionId]);
    return (bool)$st->fetchColumn();
}

/**
 * שמירה. מחזיר [id, האם נפתחה גרסה חדשה].
 * גרסאות (אפיון 2.2): שינוי בכללים כשלגרסה הנוכחית יש עסקאות → גרסה חדשה, והעסקאות הקודמות נשארות על
 * הגרסה שלהן. בלי עסקאות — הגרסה הנוכחית מתעדכנת במקום (אין עוד מה לשמר). ההערות אינן כללים — מתעדכנות
 * תמיד בגרסה הנוכחית.
 */
function ts_strategy_save(int $uid, array $in): array
{
    $db = ts_db();
    $meta = ts_meta_in($in);
    $rules = ts_rules_in($in);
    $notes = ts_text($in['notes'] ?? '', TS_MAX_TEXT, true);
    $now = time();
    $db->beginTransaction();
    try {
        $id = (int)($in['id'] ?? 0);
        $newVersion = false;
        if ($id === 0) {
            $db->prepare('INSERT INTO strategies (user_id, name, summary, markets, examples, timeframe, style, tags, status, created_at, updated_at)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([$uid, ...array_values($meta), $now, $now]);
            $id = (int)$db->lastInsertId();
            ts_version_insert($id, 1, $rules, $notes, $now);
        } else {
            $s = ts_strategy_row($uid, $id);
            $db->prepare('UPDATE strategies SET name = ?, summary = ?, markets = ?, examples = ?, timeframe = ?, style = ?, tags = ?,
                          status = ?, updated_at = ? WHERE id = ? AND user_id = ?')
                ->execute([...array_values($meta), $now, $id, $uid]);
            $st = $db->prepare('SELECT * FROM strategy_versions WHERE id = ? AND strategy_id = ?');
            $st->execute([(int)$s['current_version_id'], $id]);
            $cur = $st->fetch();
            $changed = !$cur || array_diff_assoc($rules, array_intersect_key($cur, $rules));
            if ($cur && $changed && ts_version_has_trades((int)$cur['id'])) {
                $max = $db->prepare('SELECT MAX(version_no) FROM strategy_versions WHERE strategy_id = ?');
                $max->execute([$id]);
                ts_version_insert($id, (int)$max->fetchColumn() + 1, $rules, $notes, $now);
                $newVersion = true;
            } elseif ($cur) {
                $db->prepare('UPDATE strategy_versions SET thesis = ?, setup = ?, entry_rules = ?, exit_rules = ?, risk = ?, notes = ? WHERE id = ?')
                    ->execute([...array_values($rules), $notes, $cur['id']]);
            } else {
                ts_version_insert($id, 1, $rules, $notes, $now);
            }
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return [$id, $newVersion];
}

function ts_version_insert(int $strategyId, int $no, array $rules, string $notes, int $now): int
{
    $db = ts_db();
    $db->prepare('INSERT INTO strategy_versions (strategy_id, version_no, thesis, setup, entry_rules, exit_rules, risk, notes, created_at)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$strategyId, $no, ...array_values($rules), $notes, $now]);
    $vid = (int)$db->lastInsertId();
    $db->prepare('UPDATE strategies SET current_version_id = ? WHERE id = ?')->execute([$vid, $strategyId]);
    return $vid;
}

/** שכפול: אסטרטגיה חדשה (טיוטה), גרסה 1 = הגרסה הנוכחית של המקור */
function ts_strategy_duplicate(int $uid, int $id): int
{
    $db = ts_db();
    $s = ts_strategy_row($uid, $id);
    $st = $db->prepare('SELECT * FROM strategy_versions WHERE id = ? AND strategy_id = ?');
    $st->execute([(int)$s['current_version_id'], $id]);
    $v = $st->fetch() ?: [];
    $now = time();
    $db->beginTransaction();
    try {
        $db->prepare('INSERT INTO strategies (user_id, name, summary, markets, examples, timeframe, style, tags, status, created_at, updated_at)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'draft\', ?, ?)')
            ->execute([$uid, mb_substr($s['name'] . ' (עותק)', 0, 120), $s['summary'], $s['markets'], $s['examples'], $s['timeframe'],
                $s['style'], $s['tags'], $now, $now]);
        $nid = (int)$db->lastInsertId();
        $rules = [];
        foreach (['thesis' => '', 'setup' => '[]', 'entry_rules' => '[]', 'exit_rules' => '{}', 'risk' => '{}'] as $k => $d) $rules[$k] = $v[$k] ?? $d;
        ts_version_insert($nid, 1, $rules, $v['notes'] ?? '', $now);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return $nid;
}

function ts_strategy_set_status(int $uid, int $id, mixed $status): void
{
    ts_strategy_row($uid, $id);
    ts_db()->prepare('UPDATE strategies SET status = ?, updated_at = ? WHERE id = ? AND user_id = ?')
        ->execute([ts_enum($status, TS_STATUSES, 'מצב'), time(), $id, $uid]);
}

/** מחיקה — רק לאסטרטגיה בלי עסקאות באף גרסה; לאחרות — ארכיון (אפיון 2.2) */
function ts_strategy_delete(int $uid, int $id): void
{
    ts_strategy_row($uid, $id);
    $st = ts_db()->prepare('SELECT 1 FROM trades t JOIN strategy_versions v ON v.id = t.strategy_version_id WHERE v.strategy_id = ? LIMIT 1');
    $st->execute([$id]);
    if ($st->fetchColumn()) throw new TsInvalid('לאסטרטגיה יש עסקאות ביומן — אפשר להעביר לארכיון, לא למחוק');
    ts_db()->prepare('DELETE FROM strategies WHERE id = ? AND user_id = ?')->execute([$id, $uid]);
}

// ── נכסים ("נכסים שלי", אפיון 3.2) ─────────────────────

function ts_option_in(mixed $o, string $tz): ?array
{
    if (!is_array($o)) throw new TsInvalid('באופציה חסרים: Call/Put, מחיר מימוש, פקיעה ונכס בסיס');
    $exp = $o['expiry'] ?? '';
    if (!is_string($exp) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $exp) || !DateTimeImmutable::createFromFormat('!Y-m-d', $exp)) {
        throw new TsInvalid('פקיעה: תאריך לא תקין');
    }
    $under = ts_text($o['underlying'] ?? '', 40);
    if ($under === '') throw new TsInvalid('חסר נכס בסיס לאופציה');
    return [
        'type' => ts_enum($o['type'] ?? '', ['call' => 1, 'put' => 1], 'Call/Put'),
        'strike' => ts_num($o['strike'] ?? '', 'מחיר מימוש', false, false),
        'expiry' => $exp,
        'underlying' => strtoupper($under),
    ];
}

/**
 * נכס של המשתמש לפי שוק וסמל. נוצר אם אין; $defaults — גם מתעדכן לערכים שהוזנו (רק בעסקה חדשה: עריכה של
 * עסקה ישנה לא דורסת את ברירות המחדל). מחזיר [שורת הנכס, הערכים שהוזנו — multiplier, currency].
 */
function ts_instrument_upsert(int $uid, array $in, string $tz, bool $defaults = true): array
{
    $market = ts_enum($in['market'] ?? '', TS_MARKETS, 'שוק');
    $symbol = strtoupper(ts_text($in['symbol'] ?? '', 40));
    if ($symbol === '') throw new TsInvalid('חסר נכס (סמל)');
    $currency = ts_currency($in['currency'] ?? '');
    $m = $in['multiplier'] ?? '';
    if ($m === '' || $m === null) $m = TS_MARKETS[$market]['multiplier'] ?? '';   // חוזים עתידיים — אין ברירת מחדל
    if ($m === '') throw new TsInvalid('בחוזה עתידי צריך להזין מכפיל (למשל ES = 50)');
    $mult = ts_num($m, 'מכפיל', false, false);
    $unit = ts_text($in['unit_label'] ?? '', 20) ?: TS_MARKETS[$market]['unit'];
    $opt = $market === 'options' ? ts_json(ts_option_in($in['option'] ?? null, $tz)) : null;
    $db = ts_db();
    $db->prepare('INSERT INTO instruments (user_id, symbol, market, currency, multiplier, unit_label, option_meta) VALUES (?, ?, ?, ?, ?, ?, ?)
                  ON CONFLICT(user_id, market, symbol) DO ' . ($defaults ? 'UPDATE SET currency = excluded.currency, multiplier = excluded.multiplier,
                      unit_label = excluded.unit_label, option_meta = excluded.option_meta' : 'NOTHING'))
        ->execute([$uid, $symbol, $market, $currency, $mult, $unit, $opt]);
    $st = $db->prepare('SELECT * FROM instruments WHERE user_id = ? AND market = ? AND symbol = ?');
    $st->execute([$uid, $market, $symbol]);
    return [$st->fetch(), ['multiplier' => $mult, 'currency' => $currency]];
}

function ts_instrument_out(array $i): array
{
    return [
        'id' => (int)$i['id'],
        'symbol' => $i['symbol'],
        'market' => $i['market'],
        'currency' => $i['currency'],
        'multiplier' => $i['multiplier'],
        'unit_label' => $i['unit_label'],
        'option' => ts_unjson($i['option_meta'], null),
    ];
}

function ts_instruments_list(int $uid): array
{
    $st = ts_db()->prepare('SELECT i.*, (SELECT COUNT(*) FROM trades t WHERE t.instrument_id = i.id) AS trades
                            FROM instruments i WHERE i.user_id = ? ORDER BY i.symbol');
    $st->execute([$uid]);
    return array_map(fn($i) => ts_instrument_out($i) + ['trades' => (int)$i['trades']], $st->fetchAll());
}

/** עריכה מ"נכסים שלי" — ברירות המחדל לעסקאות הבאות; עסקאות שנרשמו שומרות את המכפיל והמטבע שלהן */
function ts_instrument_save(int $uid, array $in, string $tz): void
{
    $id = (int)($in['id'] ?? 0);
    $st = ts_db()->prepare('SELECT * FROM instruments WHERE id = ? AND user_id = ?');
    $st->execute([$id, $uid]);
    $cur = $st->fetch() ?: throw new TsNotFound('הנכס לא נמצא');
    $mult = ts_num($in['multiplier'] ?? '', 'מכפיל', false, false);
    $opt = $cur['market'] === 'options' ? ts_json(ts_option_in($in['option'] ?? null, $tz)) : null;
    ts_db()->prepare('UPDATE instruments SET currency = ?, multiplier = ?, unit_label = ?, option_meta = ? WHERE id = ? AND user_id = ?')
        ->execute([ts_currency($in['currency'] ?? ''), $mult, ts_text($in['unit_label'] ?? '', 20) ?: TS_MARKETS[$cur['market']]['unit'],
            $opt, $id, $uid]);
}

function ts_instrument_delete(int $uid, int $id): void
{
    $st = ts_db()->prepare('SELECT (SELECT COUNT(*) FROM trades WHERE instrument_id = i.id) FROM instruments i WHERE i.id = ? AND i.user_id = ?');
    $st->execute([$id, $uid]);
    $n = $st->fetchColumn();
    if ($n === false) throw new TsNotFound('הנכס לא נמצא');
    if ((int)$n > 0) throw new TsInvalid('יש עסקאות על הנכס הזה — אי אפשר למחוק');
    ts_db()->prepare('DELETE FROM instruments WHERE id = ? AND user_id = ?')->execute([$id, $uid]);
}

// ── עסקאות ───────────────────────────────────────────────

/**
 * עסקאות המשתמש, עם היציאות והערכים המחושבים. מסננים (אפיון 2.4, 2.6):
 * strategy_id, version_id, market, symbol, from/to (תאריך מקומי — לפי פתיחה, או לפי סגירה עם by=closed),
 * result (win|loss|be|open), plan (yes|no|partial|none), currency, id.
 */
function ts_trades_load(int $uid, string $tz, array $f = []): array
{
    $sql = 'SELECT t.*, i.symbol, i.market, i.unit_label, i.option_meta, v.version_no, v.strategy_id, s.name AS strategy_name
            FROM trades t JOIN instruments i ON i.id = t.instrument_id
            LEFT JOIN strategy_versions v ON v.id = t.strategy_version_id
            LEFT JOIN strategies s ON s.id = v.strategy_id
            WHERE t.user_id = :uid';
    $p = [':uid' => $uid];
    if (!empty($f['id'])) { $sql .= ' AND t.id = :id'; $p[':id'] = (int)$f['id']; }
    if (!empty($f['strategy_id'])) {
        if ($f['strategy_id'] === 'none') $sql .= ' AND t.strategy_version_id IS NULL';
        else { $sql .= ' AND v.strategy_id = :sid'; $p[':sid'] = (int)$f['strategy_id']; }
    }
    if (!empty($f['version_id'])) { $sql .= ' AND t.strategy_version_id = :vid'; $p[':vid'] = (int)$f['version_id']; }
    if (!empty($f['market']) && is_string($f['market'])) { $sql .= ' AND i.market = :m'; $p[':m'] = $f['market']; }
    if (!empty($f['symbol']) && is_string($f['symbol'])) { $sql .= ' AND i.symbol = :sym'; $p[':sym'] = strtoupper($f['symbol']); }
    if (!empty($f['currency']) && is_string($f['currency'])) { $sql .= ' AND t.currency = :cur'; $p[':cur'] = strtoupper($f['currency']); }
    if (!empty($f['plan']) && is_string($f['plan'])) {
        if ($f['plan'] === 'none') $sql .= ' AND t.followed_plan IS NULL';
        else { $sql .= ' AND t.followed_plan = :plan'; $p[':plan'] = $f['plan']; }
    }
    $byClosed = ($f['by'] ?? '') === 'closed';
    if (!$byClosed) {
        if ($from = ts_day_bound($f['from'] ?? null, $tz, false)) { $sql .= ' AND t.opened_at >= :from'; $p[':from'] = $from; }
        if ($to = ts_day_bound($f['to'] ?? null, $tz, true)) { $sql .= ' AND t.opened_at < :to'; $p[':to'] = $to; }
    }
    $sql .= ' ORDER BY t.opened_at DESC, t.id DESC';
    $st = ts_db()->prepare($sql);
    $st->execute($p);
    $rows = $st->fetchAll();
    if (!$rows) return [];

    $ids = array_map(fn($r) => (int)$r['id'], $rows);
    $exits = [];
    foreach (array_chunk($ids, 500) as $chunk) {
        $q = ts_db()->prepare('SELECT * FROM trade_exits WHERE trade_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ') ORDER BY exited_at, id');
        $q->execute($chunk);
        foreach ($q->fetchAll() as $x) $exits[(int)$x['trade_id']][] = $x;
    }

    $from = $byClosed ? ts_day_bound($f['from'] ?? null, $tz, false) : null;
    $to = $byClosed ? ts_day_bound($f['to'] ?? null, $tz, true) : null;
    $out = [];
    foreach ($rows as $r) {
        $r['exits'] = $exits[(int)$r['id']] ?? [];
        $r['calc'] = ts_trade_calc($r);
        $r['strategy_id'] = $r['strategy_id'] !== null ? (int)$r['strategy_id'] : null;
        $r['strategy_version_id'] = $r['strategy_version_id'] !== null ? (int)$r['strategy_version_id'] : null;
        $res = $f['result'] ?? '';
        if ($res === 'open' && $r['calc']['status'] !== 'open') continue;
        if (in_array($res, ['win', 'loss', 'be'], true)
            && ($r['calc']['status'] !== 'closed' || ts_outcome($r['calc']['net']) !== $res)) continue;
        if ($byClosed) {
            $c = $r['calc']['closed_at'];
            if ($c === null || ($from && strcmp($c, $from) < 0) || ($to && strcmp($c, $to) >= 0)) continue;
        }
        $out[] = $r;
    }
    return $out;
}

function ts_trade_out(array $t, string $tz): array
{
    return [
        'id' => (int)$t['id'],
        'instrument_id' => (int)$t['instrument_id'],
        'symbol' => $t['symbol'],
        'market' => $t['market'],
        'unit_label' => $t['unit_label'],
        'option' => ts_unjson($t['option_meta'], null),
        'currency' => $t['currency'],
        'multiplier' => $t['multiplier'],
        'direction' => $t['direction'],
        'strategy_id' => $t['strategy_id'],
        'strategy_name' => $t['strategy_name'],
        'strategy_version_id' => $t['strategy_version_id'],
        'version_no' => $t['version_no'] !== null ? (int)$t['version_no'] : null,
        'opened_at' => ts_time_out($t['opened_at'], $tz),
        'entry_price' => $t['entry_price'],
        'quantity' => $t['quantity'],
        'entry_fees' => $t['entry_fees'],
        'planned_stop' => $t['planned_stop'],
        'planned_target' => $t['planned_target'],
        'exits' => array_map(fn($x) => [
            'exited_at' => ts_time_out($x['exited_at'], $tz), 'price' => $x['price'], 'quantity' => $x['quantity'], 'fees' => $x['fees'],
        ], $t['exits']),
        'followed_plan' => $t['followed_plan'],
        'checklist' => ts_unjson($t['checklist']),
        'mood' => $t['mood'],
        'went_well' => $t['went_well'],
        'to_improve' => $t['to_improve'],
        'rating' => $t['rating'] !== null ? (int)$t['rating'] : null,
        'calc' => array_merge($t['calc'], ['closed_at' => ts_time_out($t['calc']['closed_at'], $tz)]),
    ];
}

function ts_trade_get(int $uid, int $id, string $tz): array
{
    $rows = ts_trades_load($uid, $tz, ['id' => $id]);
    if (!$rows) throw new TsNotFound('העסקה לא נמצאה');
    return ts_trade_out($rows[0], $tz);
}

/** גרסת אסטרטגיה — רק של המשתמש. מחזיר את השורה (עם entry_rules). */
function ts_version_owned(int $uid, int $vid): array
{
    $st = ts_db()->prepare('SELECT v.* FROM strategy_versions v JOIN strategies s ON s.id = v.strategy_id WHERE v.id = ? AND s.user_id = ?');
    $st->execute([$vid, $uid]);
    return $st->fetch() ?: throw new TsNotFound('גרסת האסטרטגיה לא נמצאה');
}

/** שמירת עסקה (אפיון 2.5). מחזיר id. */
function ts_trade_save(int $uid, array $in, string $tz): int
{
    $db = ts_db();
    $id = (int)($in['id'] ?? 0);
    $existing = null;
    if ($id) {
        $st = $db->prepare('SELECT * FROM trades WHERE id = ? AND user_id = ?');
        $st->execute([$id, $uid]);
        $existing = $st->fetch() ?: throw new TsNotFound('העסקה לא נמצאה');
    }

    $direction = ts_enum($in['direction'] ?? '', ['long' => 1, 'short' => 1], 'כיוון');
    $opened = ts_time_in($in['opened_at'] ?? '', $tz, 'זמן כניסה');
    $entry = ts_num($in['entry_price'] ?? '', 'מחיר כניסה', false, false);
    $qty = ts_num($in['quantity'] ?? '', 'כמות', false, false);
    $entryFees = ts_num($in['entry_fees'] ?? '0', 'עמלות כניסה') ?? '0';
    $stop = ts_num($in['planned_stop'] ?? null, 'סטופ מתוכנן', true, false);
    $target = ts_num($in['planned_target'] ?? null, 'יעד מתוכנן', true, false);
    if ($stop !== null) {
        $c = dec_cmp($stop, $entry);
        if ($c === 0) throw new TsInvalid('הסטופ שווה למחיר הכניסה — אין סיכון לחשב ממנו R');
        if (($direction === 'long' && $c > 0) || ($direction === 'short' && $c < 0)) {
            throw new TsInvalid($direction === 'long' ? 'בלונג הסטופ צריך להיות מתחת למחיר הכניסה' : 'בשורט הסטופ צריך להיות מעל מחיר הכניסה');
        }
    }
    if ($target !== null) {
        $c = dec_cmp($target, $entry);
        if (($direction === 'long' && $c <= 0) || ($direction === 'short' && $c >= 0)) {
            throw new TsInvalid($direction === 'long' ? 'בלונג היעד צריך להיות מעל מחיר הכניסה' : 'בשורט היעד צריך להיות מתחת למחיר הכניסה');
        }
    }

    $exits = []; $sum = '0';
    foreach (ts_list($in['exits'] ?? [], 'יציאות') as $n => $x) {
        if (!is_array($x)) throw new TsInvalid('יציאה לא תקינה');
        $label = 'יציאה ' . ($n + 1);
        $e = [
            'exited_at' => ts_time_in($x['exited_at'] ?? '', $tz, "$label — זמן"),
            'price' => ts_num($x['price'] ?? '', "$label — מחיר", false, false),
            'quantity' => ts_num($x['quantity'] ?? '', "$label — כמות", false, false),
            'fees' => ts_num($x['fees'] ?? '0', "$label — עמלות") ?? '0',
        ];
        if (strcmp($e['exited_at'], $opened) < 0) throw new TsInvalid("$label לפני זמן הכניסה");
        $sum = dec_add($sum, $e['quantity']);
        $exits[] = $e;
    }
    if (dec_cmp($sum, $qty) > 0) throw new TsInvalid('סכום כמויות היציאה גדול מכמות הכניסה');
    $status = dec_cmp($sum, $qty) === 0 ? 'closed' : 'open';

    $vid = null; $checklist = [];
    if (!empty($in['strategy_version_id'])) {
        $v = ts_version_owned($uid, (int)$in['strategy_version_id']);
        $vid = (int)$v['id'];
        $met = is_array($in['checklist'] ?? null) ? $in['checklist'] : [];
        foreach (ts_unjson($v['entry_rules']) as $k => $rule) {
            $checklist[] = ['text' => $rule['text'], 'required' => (bool)$rule['required'], 'met' => ($met[$k] ?? false) === true];
        }
    }
    $rating = $in['rating'] ?? null;
    if ($rating !== null && $rating !== '') {
        if (!is_int($rating) && !(is_string($rating) && ctype_digit($rating))) throw new TsInvalid('דירוג לא תקין');
        $rating = (int)$rating;
        if ($rating < 1 || $rating > 5) throw new TsInvalid('דירוג — 1 עד 5');
    } else {
        $rating = null;
    }

    $now = time();
    $db->beginTransaction();
    try {
        [$inst, $entered] = ts_instrument_upsert($uid, $in, $tz, $existing === null);
        $vals = [
            'instrument_id' => (int)$inst['id'],
            'strategy_version_id' => $vid,
            'direction' => $direction,
            'opened_at' => $opened,
            'entry_price' => $entry,
            'quantity' => $qty,
            'entry_fees' => $entryFees,
            'multiplier' => $entered['multiplier'],   // מה שהוזן בעסקה — לא ברירת המחדל של הנכס
            'currency' => $entered['currency'],
            'planned_stop' => $stop,
            'planned_target' => $target,
            'status' => $status,
            'followed_plan' => ts_enum($in['followed_plan'] ?? null, TS_PLAN, 'שמרתי על התוכנית', true),
            'checklist' => ts_json($checklist),
            'mood' => (string)ts_enum($in['mood'] ?? null, TS_MOODS, 'מצב רוח', true),
            'went_well' => ts_text($in['went_well'] ?? '', TS_MAX_TEXT, true),
            'to_improve' => ts_text($in['to_improve'] ?? '', TS_MAX_TEXT, true),
            'rating' => $rating,
        ];
        if ($existing) {
            $set = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($vals)));
            $db->prepare("UPDATE trades SET $set, updated_at = :now WHERE id = :id AND user_id = :uid")
                ->execute(array_combine(array_map(fn($k) => ":$k", array_keys($vals)), array_values($vals)) + [':now' => $now, ':id' => $id, ':uid' => $uid]);
            $db->prepare('DELETE FROM trade_exits WHERE trade_id = ?')->execute([$id]);
        } else {
            $cols = implode(', ', array_keys($vals));
            $ph = implode(', ', array_fill(0, count($vals), '?'));
            $db->prepare("INSERT INTO trades (user_id, $cols, created_at, updated_at) VALUES (?, $ph, ?, ?)")
                ->execute([$uid, ...array_values($vals), $now, $now]);
            $id = (int)$db->lastInsertId();
        }
        $ins = $db->prepare('INSERT INTO trade_exits (trade_id, exited_at, price, quantity, fees) VALUES (?, ?, ?, ?, ?)');
        foreach ($exits as $e) $ins->execute([$id, $e['exited_at'], $e['price'], $e['quantity'], $e['fees']]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return $id;
}

function ts_trade_delete(int $uid, int $id): void
{
    $st = ts_db()->prepare('DELETE FROM trades WHERE id = ? AND user_id = ?');
    $st->execute([$id, $uid]);
    if (!$st->rowCount()) throw new TsNotFound('העסקה לא נמצאה');
}

// ── ייצוא (אפיון 2.7, עיקרון 3) ─────────────────────────

function ts_export_json(int $uid, array $user): array
{
    $tz = $user['timezone'];
    $strategies = [];
    $st = ts_db()->prepare('SELECT id FROM strategies WHERE user_id = ? ORDER BY id');
    $st->execute([$uid]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $sid) $strategies[] = ts_strategy_get($uid, (int)$sid, $tz);
    return [
        'app' => 'trading-strategies',
        'exported_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'timezone' => $tz,
        'settings' => ts_settings_out($user),
        'strategies' => $strategies,
        'instruments' => ts_instruments_list($uid),
        'trades' => array_map(fn($t) => ts_trade_out($t, $tz), array_reverse(ts_trades_load($uid, $tz))),
        'watchlist' => function_exists('ts_watchlist') ? array_column(ts_watchlist($uid), 'asset') : [],
        'drawings' => function_exists('ts_drawing_out') ? ts_drawings_all($uid) : [],
    ];
}

/** תא CSV מטקסט חופשי — בלי נוסחה שגיליון יריץ (=, +, -, @ בתחילת התא) */
function ts_csv_text(?string $s): string
{
    $s = (string)$s;
    return $s !== '' && strpbrk($s[0], "=+-@\t\r") !== false ? "'" . $s : $s;
}

function ts_export_csv(int $uid, array $user): string
{
    $tz = $user['timezone'];
    $h = fopen('php://temp', 'w+');
    fwrite($h, "\u{FEFF}");   // כדי שאקסל יזהה UTF-8 בעברית
    fputcsv($h, ['id', 'opened_at', 'closed_at', 'symbol', 'market', 'direction', 'currency', 'strategy', 'version', 'entry_price',
        'quantity', 'multiplier', 'entry_fees', 'planned_stop', 'planned_target', 'exits', 'avg_exit', 'status', 'gross', 'fees', 'net',
        'risk', 'r', 'return_pct', 'pips', 'followed_plan', 'mood', 'rating', 'went_well', 'to_improve'], ',', '"', '');
    foreach (array_reverse(ts_trades_load($uid, $tz)) as $t) {
        $c = $t['calc'];
        fputcsv($h, [$t['id'], ts_time_out($t['opened_at'], $tz), ts_time_out($c['closed_at'], $tz), ts_csv_text($t['symbol']), $t['market'],
            $t['direction'], $t['currency'], ts_csv_text($t['strategy_name']), $t['version_no'], $t['entry_price'], $t['quantity'],
            $t['multiplier'], $t['entry_fees'], $t['planned_stop'], $t['planned_target'], count($t['exits']), $c['avg_exit'], $c['status'],
            $c['gross'], $c['fees'], $c['net'], $c['risk'], $c['r'], $c['return_pct'], $c['pips'], $t['followed_plan'], $t['mood'],
            $t['rating'], ts_csv_text($t['went_well']), ts_csv_text($t['to_improve'])], ',', '"', '');
    }
    rewind($h);
    return (string)stream_get_contents($h);
}
