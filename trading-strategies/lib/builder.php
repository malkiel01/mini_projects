<?php
/**
 * בונה השיטות — מנהל בלבד (spec-stage-a 17.2, ✅19; spec-method-builder.md 3–4, 6). כל פעולה כאן נקראת רק אחרי
 * ts_require_admin (api.php, admin_*), וכל שמירה נרשמת ב-admin_log.
 *
 * - הגדרה (JSON) → TsDefinedMethod (lib/methods/defined.php): התחביר, ההפניות והטווחים נבדקים שם — בלי eval.
 * - מזהה שמתנגש עם שיטת קוד — נדחה. שיטה מוגדרת חדשה — **כבויה** עד הפעלה, ומסומנת "מוגדרת ידנית" עד שיש לה בדיקה
 *   לאחור עם N ≥ 30 (17.2.1).
 * - כל שמירה שמשנה את ההגדרה מעלה את הגרסה (definition_version), שומרת אותה ב-method_versions, והבדיקות לאחור של
 *   השיטה מחושבות מחדש בפתיחה הבאה (6.7 — לפי method_version).
 * - "🧪 נסה" (dry run): ההגדרה רצה על הנרות האחרונים של נכס — בלי לשמור דבר.
 * - **נפח:** הבדיקה לאחור מקבלת נרות בלי נפח (ts_bt_floats), ולכן אינדיקטור נפח בהגדרה **נדחה** כאן, עם הסבר — אחרת
 *   תנאי עליו לא היה מתקיים אף פעם בבדיקה לאחור, בשקט.
 */

declare(strict_types=1);

require_once __DIR__ . '/analysis.php';

const TS_BUILDER_DRY_BARS = 1000;   // "🧪 נסה" — עד כמה נרות אחרונים

/** הגדרה מהדפדפן → TsDefinedMethod, או TsInvalid עם הודעה בעברית (בלי להחזיר את הקלט) */
function ts_builder_parse(mixed $def, int $version = 1): TsDefinedMethod
{
    if (!is_array($def) || array_is_list($def)) throw new TsInvalid('ההגדרה חייבת להיות אובייקט JSON');
    unset($def['version']);   // הגרסה — של השרת, לא של הדפדפן
    if (ts_method_is_code($def['id'] ?? null)) throw new TsInvalid('המזהה תפוס על ידי שיטת קוד — בחרו מזהה אחר');
    foreach ($def['indicators'] ?? [] as $ind) {
        if (is_array($ind) && ($ind['type'] ?? null) === 'volume') {
            throw new TsInvalid('נפח עוד לא נתמך בשיטה מוגדרת: הבדיקה לאחור מקבלת נרות בלי נפח, ותנאי על נפח לא היה מתקיים בה אף פעם');
        }
    }
    try {
        return new TsDefinedMethod(['version' => $version] + $def);
    } catch (TsExprError $e) {
        throw new TsInvalid($e->getMessage());
    }
}

/** "בדיקה" — רק תחביר והפניות */
function ts_builder_validate(mixed $def): array
{
    $m = ts_builder_parse($def);
    $meta = $m->meta();
    return ['id' => $meta['id'], 'name' => $meta['name'], 'params' => $meta['params'], 'horizon_bars' => $meta['horizon_bars'], 'warmup' => $meta['warmup']];
}

/**
 * שמירת הגדרה (חדשה או עדכון). שינוי בהגדרה → גרסה חדשה; אותה הגדרה בדיוק → בלי גרסה חדשה. שיטה חדשה — כבויה, עם
 * השווקים והמסגרות של ההגדרה. מחזיר {id, version, created, changed}.
 */
function ts_builder_save(int $uid, mixed $def): array
{
    $m = ts_builder_parse($def);
    $id = $m->meta()['id'];
    $clean = $def;
    unset($clean['version']);
    $json = json_encode($clean, JSON_UNESCAPED_UNICODE);
    $db = ts_db();
    $db->beginTransaction();
    try {
        $st = $db->prepare('SELECT kind, definition, definition_version FROM methods WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch() ?: null;
        if ($row && $row['kind'] !== 'defined') throw new TsInvalid('המזהה תפוס — בחרו מזהה אחר');
        $created = $row === null;
        $changed = $created || $row['definition'] !== $json;
        $version = $created ? 1 : (int)$row['definition_version'] + ($changed ? 1 : 0);
        if ($created) {
            $db->prepare("INSERT INTO methods (id, enabled, markets, timeframes, params, note, kind, definition, definition_version)
                          VALUES (?, 0, ?, ?, '{}', '', 'defined', ?, ?)")
                ->execute([$id, json_encode($clean['markets']), json_encode($clean['timeframes']), $json, $version]);
        } elseif ($changed) {
            // הפרמטרים שהמנהל קבע נשמרים רק אם עדיין קיימים בהגדרה; שווקים ומסגרות — בתוך מה שההגדרה תומכת בו
            $cur = $db->prepare('SELECT markets, timeframes, params FROM methods WHERE id = ?');
            $cur->execute([$id]);
            $c = $cur->fetch();
            $keep = fn(string $col, array $all) => array_values(array_intersect($all, json_decode($c[$col], true) ?: [])) ?: $all;
            $params = array_intersect_key(json_decode($c['params'], true) ?: [], $clean['params'] ?? []);
            $db->prepare('UPDATE methods SET definition = ?, definition_version = ?, markets = ?, timeframes = ?, params = ? WHERE id = ?')
                ->execute([$json, $version, json_encode($keep('markets', $clean['markets'])), json_encode($keep('timeframes', $clean['timeframes'])),
                    json_encode((object)$params), $id]);
        }
        if ($changed) {
            $db->prepare('INSERT INTO method_versions (method_id, version, definition, saved_by, saved_at) VALUES (?, ?, ?, ?, ?)')
                ->execute([$id, $version, $json, $uid, time()]);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    ts_method_forget();
    if ($changed) ts_admin_log($uid, $created ? 'method_define' : 'method_redefine', "$id v$version");
    return ['id' => $id, 'version' => $version, 'created' => $created, 'changed' => $changed];
}

/** היסטוריית הגרסאות של שיטה מוגדרת (חדשה קודם) */
function ts_builder_versions(mixed $id): array
{
    if (!is_string($id) || !ts_method_exists($id) || ts_method_is_code($id)) throw new TsNotFound('שיטה מוגדרת לא נמצאה');
    $st = ts_db()->prepare('SELECT v.version, v.definition, v.saved_at, u.display_name AS saved_by FROM method_versions v
                            LEFT JOIN users u ON u.id = v.saved_by WHERE v.method_id = ? ORDER BY v.version DESC');
    $st->execute([$id]);
    return array_map(fn($r) => ['version' => (int)$r['version'], 'definition' => json_decode($r['definition'], true),
        'saved_at' => (int)$r['saved_at'], 'saved_by' => $r['saved_by']], $st->fetchAll());
}

/**
 * "🧪 נסה" — ההגדרה על הנרות הסגורים האחרונים של נכס (עד TS_BUILDER_DRY_BARS). לא שומר דבר — לא הגדרה ולא בדיקה לאחור.
 * מחזיר את האותות האחרונים (עד 20), כמה אותות בסך הכול, והתנאים במצב הנוכחי.
 */
function ts_builder_dry_run(int $uid, mixed $def, mixed $asset, mixed $tf, ?int $now = null): array
{
    $m = ts_builder_parse($def);
    if (!is_string($tf) || !isset(TS_TF_SECONDS[$tf])) throw new TsInvalid('מסגרת זמן לא תקינה');
    $now ??= ts_now();
    $sec = TS_TF_SECONDS[$tf];
    $r = ts_market_candles($uid, $asset, $tf, $now - TS_BUILDER_DRY_BARS * $sec, $now, $now);
    $rows = array_values(array_filter($r['candles'], fn($c) => $c['t'] + $sec <= $now));
    $fl = ts_bt_floats($rows);
    $p = $m->params();
    if ($err = $m->validate($p)) throw new TsInvalid($err);
    $dec = ts_tick_decimals($rows);
    $sig = $m->signals($fl, $p);
    $a = $m->analyze($fl, $p);
    $str = fn($v) => $v === null ? null : ts_level_str((float)$v, $dec);
    return ['candles' => count($fl), 'signals_total' => count($sig),
        'signals' => array_map(fn($s) => ['t' => $s['t'], 'side' => $s['side'], 'entry' => $str($s['entry']), 'stop' => $str($s['stop']), 'target' => $str($s['target'])], array_slice($sig, -20)),
        'bias' => $a['bias'], 'conditions' => array_map(fn($c) => ['id' => $c['id'], 'text' => $c['text'], 'side' => $c['side'], 'met' => (bool)$c['met']], $a['conditions']),
        'as_of' => $a['as_of'], 'provider_calls' => $r['provider_calls'], 'note' => 'ניסיון בלבד — לא נשמר, ובלי בדיקה לאחור'];
}

