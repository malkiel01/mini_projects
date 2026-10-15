<?php
/**
 * ייצוא וייבוא של תבנית — קובץ אחד (README: "ייצוא תבנית").
 *
 * הקובץ (.dt.json) מכיל את כל מה שצריך כדי לבנות את התבנית מחדש אצל משתמש אחר:
 * הקובץ המקורי (PDF / Word / תמונה), המבנה (שדות, טבלאות, נוסחאות, סגנונות, ממלאים),
 * המספור והתגיות, רשימות הערכים שקשורות אליו (עם השורות), והגופנים שהועלו.
 * JSON קריא — אפשר גם לפתוח ולבדוק אותו (למשל להעביר ל-Claude בצ'אט).
 *
 * מה לא עובר — כי הוא פרטי או סודי: אנשי קשר קבועים של ממלאים, ומקורות נתונים חיצוניים
 * (יש בהם כתובות ואסימונים). שדה שמשך ממקור — עובר כבחירה בלי אפשרויות, ונרשם ב-notes.
 */

declare(strict_types=1);

const PORT_FORMAT  = 'dt-template';
const PORT_VERSION = 1;
const PORT_MAX_MB  = 40;

function tpl_export(array $user, int $id): never
{
    $out = tpl_export_data($user, $id);
    $json = json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $file = preg_replace('/[\\\\\/:*?"<>|]+/u', '_', $out['name']) ?: 'תבנית';
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Length: ' . strlen($json));
    header("Content-Disposition: attachment; filename=\"template.dt.json\"; filename*=UTF-8''" . rawurlencode($file . '.dt.json'));
    header('Cache-Control: private, no-store');
    echo $json;
    exit;
}

/** התוכן של קובץ הייצוא (בלי לשלוח) — גם לבדיקות. */
function tpl_export_data(array $user, int $id): array
{
    $t = tpl_owned($id, $user, 'edit');
    $schema = json_decode((string)unseal($t['schema_enc']), true) ?: [];
    $a = tpl_asset_row((int)$t['version_id']);
    $notes = [];

    // רשימות: מזהה ← שם (אצל המייבא נוצרת / נמצאת רשימה בשם הזה)
    $lists = lists_for($schema);
    $byId = fn($ref) => isset($lists[(string)$ref['id']]) ? ['name' => $lists[(string)$ref['id']]['name'], 'level' => (int)$ref['level']] : null;
    foreach ($schema['fields'] ?? [] as $i => $f) {
        if (!empty($f['list'])) { $r = $byId($f['list']); if ($r) $schema['fields'][$i]['list'] = $r; else unset($schema['fields'][$i]['list']); }
        foreach ($f['columns'] ?? [] as $j => $c) {
            if (!empty($c['list'])) { $r = $byId($c['list']); if ($r) $schema['fields'][$i]['columns'][$j]['list'] = $r; else unset($schema['fields'][$i]['columns'][$j]['list']); }
        }
        if (!empty($f['source'])) {
            unset($schema['fields'][$i]['source']);
            $notes[] = "השדה \"{$f['label']}\" משך ממקור נתונים חיצוני — המקור לא עובר (יש בו כתובת והרשאות); מחברים אותו מחדש.";
        }
    }
    foreach ($schema['roles'] ?? [] as $i => $r) {
        if (!empty($r['contact'])) { unset($schema['roles'][$i]['contact']); $notes[] = "לממלא \"{$r['label']}\" היה איש קשר קבוע — לא עובר (פרטי)."; }
    }

    // גופנים שהועלו — עם הקובץ
    $fonts = [];
    foreach (schema_fonts($schema) as $fid) {
        $st = db()->prepare('SELECT * FROM fonts WHERE id = ?');
        $st->execute([$fid]);
        if (!($fr = $st->fetch())) continue;
        try { $fonts[] = ['ref' => (int)$fid, 'name' => (string)unseal($fr['name_enc']), 'format' => $fr['format'], 'data' => base64_encode(load_file($fr['path']))]; }
        catch (Throwable) { $notes[] = 'גופן אחד לא נמצא — השדות שלו יוצגו בגופן ברירת המחדל.'; }
    }

    $name = (string)unseal($t['name_enc']);
    $out = [
        'format' => PORT_FORMAT, 'version' => PORT_VERSION, 'exported_at' => now_iso(),
        'name' => $name, 'source' => $t['source'],
        'numbering' => json_decode((string)$t['numbering_json'], true),
        'tags' => tags_of($t['tags_enc']),
        'schema' => $schema,
        'lists' => array_values($lists),
        'fonts' => $fonts,
        'asset' => $a['path'] ? ['mime' => $a['mime'], 'data' => base64_encode(asset_bytes($a))] : null,
        'notes' => array_values(array_unique($notes)),
    ];
    return $out;
}

/** קובץ שיוצא ← תבנית חדשה אצל המשתמש. $post['name'] — שם אחר (לא חובה). */
function tpl_import(array $user, array $post, array $files): array
{
    $f = $files['file'] ?? null;
    if (!$f || ($f['error'] ?? 1) !== 0) throw new ApiError('לא התקבל קובץ', 422);
    if ($f['size'] > PORT_MAX_MB * 1024 * 1024) throw new ApiError('הקובץ גדול מדי', 413);
    $p = json_decode((string)file_get_contents($f['tmp_name']), true);
    if (!is_array($p) || ($p['format'] ?? '') !== PORT_FORMAT) throw new ApiError('זה לא קובץ תבנית שיוצא מהמערכת (.dt.json)', 415);
    if ((int)($p['version'] ?? 0) > PORT_VERSION) throw new ApiError('הקובץ יוצא מגרסה חדשה יותר של המערכת', 422);
    $schema = is_array($p['schema'] ?? null) ? $p['schema'] : throw new ApiError('בקובץ אין מבנה תבנית', 422);
    $name = valid_tpl_name(trim((string)($post['name'] ?? '')) ?: (string)($p['name'] ?? 'תבנית מיובאת'));

    // התבנית עצמה: מהקובץ המקורי, או "נכתבה בכלי"
    if (($p['source'] ?? '') === 'authored' || empty($p['asset'])) {
        $tid = tpl_create_authored($user, ['name' => $name])['id'];
    } else {
        $bytes = base64_decode((string)($p['asset']['data'] ?? ''), true);
        if ($bytes === false || $bytes === '') throw new ApiError('הקובץ המקורי של התבנית פגום', 422);
        $tid = tpl_create_bytes($user, $name, $bytes, ($p['source'] ?? '') === 'pdf_parsed' ? 'parse' : '')['id'];
    }

    // רשימות: אותה רשימה (שם, רמות ושורות) — משתמשים בקיימת; אחרת נוצרת חדשה
    $mine = [];
    foreach (list_index($user)['lists'] as $l) $mine[$l['name']] = $l;
    $ids = [];
    foreach ((array)($p['lists'] ?? []) as $l) {
        if (!is_array($l) || trim((string)($l['name'] ?? '')) === '') continue;
        $ln = mb_substr(trim((string)$l['name']), 0, 80);
        $levels = list_levels_clean($l['levels'] ?? []);
        $rows = list_rows_clean((array)($l['rows'] ?? []), count($levels));
        if (isset($mine[$ln])) {
            $have = list_get($user, ['id' => $mine[$ln]['id']])['list'];
            if ($have['levels'] === $levels && $have['rows'] === $rows) { $ids[$l['name']] = $have['id']; continue; }
            for ($k = 2; isset($mine[$ln . " ($k)"]); $k++);
            $ln .= " ($k)";
        }
        $new = list_save($user, ['name' => $ln, 'levels' => $levels, 'rows' => $rows])['list'];
        $mine[$ln] = $new;
        $ids[$l['name']] = $new['id'];
    }
    $bind = function (array $o) use ($ids): array {
        if (isset($o['list']['name'])) {
            if (isset($ids[$o['list']['name']])) $o['list'] = ['id' => $ids[$o['list']['name']], 'level' => (int)($o['list']['level'] ?? 0)];
            else unset($o['list']);
        } elseif (isset($o['list'])) unset($o['list']);   // מזהה של משתמש אחר — לא שייך כאן
        return $o;
    };

    // גופנים: נשמרים אצל המשתמש; המזהה הישן ← החדש
    $fontIds = [];
    foreach ((array)($p['fonts'] ?? []) as $fo) {
        $bytes = base64_decode((string)($fo['data'] ?? ''), true);
        if (!$bytes || !font_format($bytes)) continue;
        try {
            $tmp = tempnam(sys_get_temp_dir(), 'dtf');
            file_put_contents($tmp, $bytes);
            $r = font_upload($user, ['name' => (string)($fo['name'] ?? 'גופן')], ['file' => ['tmp_name' => $tmp, 'error' => 0, 'size' => strlen($bytes), 'name' => 'font.ttf']]);
            $fontIds[(int)($fo['ref'] ?? 0)] = $r['font']['id'];
        } catch (ApiError) { /* מגבלת גופנים — השדה בגופן ברירת המחדל */ }
        finally { if (isset($tmp) && is_file($tmp)) @unlink($tmp); }
    }

    foreach ($schema['fields'] ?? [] as $i => $fd) {
        if (!is_array($fd)) continue;
        $fd = $bind($fd);
        foreach ($fd['columns'] ?? [] as $j => $c) if (is_array($c)) $fd['columns'][$j] = $bind($c);
        if (!empty($fd['font'])) { if (isset($fontIds[(int)$fd['font']])) $fd['font'] = $fontIds[(int)$fd['font']]; else unset($fd['font']); }
        unset($fd['source']);
        $schema['fields'][$i] = $fd;
    }
    foreach ($schema['styles'] ?? [] as $i => $sty) {
        if (!empty($sty['font'])) { if (isset($fontIds[(int)$sty['font']])) $schema['styles'][$i]['font'] = $fontIds[(int)$sty['font']]; else unset($schema['styles'][$i]['font']); }
    }
    foreach ($schema['roles'] ?? [] as $i => $r) unset($schema['roles'][$i]['contact']);
    $schema['parsed'] = true;   // לא לפרק שוב את שדות הטופס — המבנה כבר כאן

    $save = ['id' => $tid, 'schema' => $schema];
    if (is_array($p['numbering'] ?? null)) $save['numbering'] = $p['numbering'];
    if (is_array($p['tags'] ?? null)) $save['tags'] = $p['tags'];
    try {
        tpl_save($user, $save);
    } catch (ApiError $e) {
        throw new ApiError('התבנית נוצרה, אבל המבנה שלה לא נשמר: ' . $e->getMessage(), $e->status);
    }
    return ['ok' => true, 'id' => $tid, 'lists' => count($ids), 'fonts' => count($fontIds), 'notes' => array_values((array)($p['notes'] ?? []))];
}
