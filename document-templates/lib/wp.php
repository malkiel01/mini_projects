<?php
/**
 * 15.8 — עורך התמלילים בתוך תבניות מסמכים (לפי word-processor/docs/integration.md).
 *
 * - תבנית Word: "📝 עריכה בעורך התמלילים" — בבחירה. בפתיחה הראשונה הקובץ של התבנית מיובא לעורך (מסמך חדש
 *   שם, המזהה ב-templates.wp_doc). אחרי שמירה / סגירה (tpl_wp_sync) ה-Word שהעורך מוציא הופך לקובץ של התבנית —
 *   כמו סימון שדה (גרסה חדשה כשכבר הונפקו מסמכים), והשדות נגזרים מה-{{סימונים}} שבו. הקובץ המקורי לא משתנה
 *   עד הסנכרון הראשון, והגרסאות הקודמות נשמרות.
 * - PDF ממסמך Word (doc_pdf): רק אחרי נעילה (15.1). ה-Word הממולא (1:1, docx_fill) מיובא לעותק זמני בעורך,
 *   ממנו PDF, והעותק נמחק.
 * - פרטיות: התוכן של תבנית שנערכה בעורך נשמר גם שם, וב-PDF — גם הערכים, לרגע. המשתמש מאשר לפני הפתיחה הראשונה.
 * - lib/WordProcessorClient.php — עותק של word-processor/client/. לא עורכים כאן; כשהעורך מעדכן — מעתיקים שוב.
 *   המפתח הפרטי — בטבלה wp_embed_keys במסד שלנו (data/, מוחרג מהפריסה).
 */

declare(strict_types=1);

require_once __DIR__ . '/WordProcessorClient.php';

const WP_CLIENT = 'document-templates';
const WP_ORIGINS = ['https://mbe-plus.com', 'https://www.mbe-plus.com'];

function wp(): object
{
    static $wp = null;
    return $GLOBALS['dt_wp'] ?? ($wp ??= new WordProcessorClient(db(), WP_CLIENT, 'תבניות מסמכים',
        'https://mbe-plus.com/mini_projects/document-templates/api.php?action=embed_key', WP_ORIGINS));
}

/** שגיאה של העורך → הודעה למשתמש. */
function wp_try(callable $fn): mixed
{
    try {
        return $fn();
    } catch (WordProcessorError $e) {
        error_log('document-templates: word-processor ' . $e->codeName . ' ' . $e->getMessage());
        throw new ApiError(match ($e->codeName) {
            'pending' => 'החיבור לעורך התמלילים ממתין לאישור של מנהל העורך',
            'offline' => 'עורך התמלילים לא זמין כרגע — נסו שוב בעוד רגע',
            'not_found' => 'המסמך לא נמצא בעורך התמלילים',
            default => 'עורך התמלילים החזיר שגיאה (' . $e->codeName . ')',
        }, $e->codeName === 'not_found' ? 404 : 502);
    }
}

/** המפתח הציבורי — ציבורי (ב-app.json), העורך מוריד אותו בלי חשבון. */
function wp_embed_key(): never
{
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode(wp()->publicKeys(), JSON_UNESCAPED_SLASHES);
    exit;
}

function tpl_word_asset(array $t): array
{
    $a = tpl_asset_row((int)$t['version_id']);
    if ($a['mime'] !== DOCX_MIME) throw new ApiError('עריכה בעורך התמלילים — רק בתבנית Word', 422);
    return $a;
}

/**
 * כתובת חד-פעמית לעורך (60 שניות). עורך ומעלה — עריכה; מתחת — צפייה. פתיחה ראשונה: הקובץ מיובא לעורך.
 */
function tpl_wp_session(array $user, array $b): array
{
    $t = tpl_owned((int)($b['id'] ?? 0), $user, 'view');
    $a = tpl_word_asset($t);
    $can = LEVELS[$t['access']] >= LEVELS['edit'] ? 'edit' : 'view';
    $doc = (string)($t['wp_doc'] ?? '');
    $name = (string)unseal($t['name_enc']);
    if ($doc === '') {
        if ($can !== 'edit') throw new ApiError('התבנית עוד לא נפתחה בעורך התמלילים', 409);
        $doc = wp_try(function () use ($name, $a) {
            $d = wp()->create($name);
            wp()->import($d, 'docx', asset_bytes($a));
            return $d;
        });
        db()->prepare('UPDATE templates SET wp_doc = ? WHERE id = ?')->execute([$doc, $t['id']]);
    }
    // האתר נפתח גם מ-www — ההודעות מהעורך צריכות לחזור למקור שממנו המשתמש גולש
    $origin = in_array($_SERVER['HTTP_ORIGIN'] ?? '', WP_ORIGINS, true) ? $_SERVER['HTTP_ORIGIN'] : WP_ORIGINS[0];
    $s = wp_try(fn() => wp()->embedSession($doc, ['id' => 'u' . $user['id'], 'name' => (string)($user['display_name'] ?? '')], $can, 'document', null, $name, $origin));
    return ['ok' => true, 'embed_url' => $s['embed_url'], 'can' => $can];
}

/**
 * אחרי שמירה בעורך: ה-Word מהעורך הופך לקובץ של התבנית. שדה שכבר מוגדר (לפי הסימון) — נשאר עם ההגדרות שלו;
 * סימון חדש — שדה חדש; שדה שהסימון שלו נמחק מהטקסט — יוצא. בלי שינוי בקובץ — לא נוגעים.
 */
function tpl_wp_sync(array $user, array $b): array
{
    $t = tpl_owned((int)($b['id'] ?? 0), $user, 'edit');
    $a = tpl_word_asset($t);
    $doc = (string)($t['wp_doc'] ?? '');
    if ($doc === '') return ['ok' => true, 'changed' => false];
    $bytes = wp_try(fn() => wp()->export($doc, 'docx'));
    if (!is_string($bytes) || !is_docx($bytes)) throw new ApiError('עורך התמלילים לא החזיר קובץ Word', 502);
    $bytes = docx_normalize($bytes, $names);
    if (hash('sha256', $bytes) === $a['sha256']) return ['ok' => true, 'changed' => false];

    $schema = json_decode((string)unseal($t['schema_enc']), true) ?: [];
    $schema['pages'] = $schema['pages'] ?: [['w' => 595, 'h' => 842]];
    $old = $schema['fields'] ?? [];
    $byPh = [];
    foreach ($old as $f) if (!empty($f['placeholder'])) $byPh[$f['placeholder']] = $f;
    $keys = array_column($old, 'key');
    $ids = array_column($old, 'id');
    $fields = [];
    $added = 0;
    // שדות בלי סימון בקובץ (מערכת / מחושב שלא מופיעים בטקסט) — נשארים
    foreach ($old as $f) if (empty($f['placeholder'])) $fields[] = $f;
    $n = 1;
    foreach (docx_fields(array_values(array_unique($names))) as $f) {
        // סימון של עמודה בטבלה חוזרת ({{פריטים.כמות}}) — שייך לשדה הטבלה, לא שדה בפני עצמו
        if (str_contains((string)$f['placeholder'], '.')) continue;
        if (isset($byPh[$f['placeholder']])) { $fields[] = $byPh[$f['placeholder']]; continue; }
        while (in_array('f' . $n, $keys, true) || in_array('f' . $n, $ids, true)) $n++;
        $f['key'] = $f['id'] = 'f' . $n;
        $keys[] = $f['key'];
        $fields[] = $f;
        $added++;
    }
    // טבלה חוזרת שהסימונים שלה עדיין בקובץ — נשארת
    foreach ($old as $f) {
        if (($f['type'] ?? '') === 'table' && !empty($f['placeholder']) && !in_array($f, $fields, true)) {
            foreach ($names as $nm) if (str_starts_with($nm, $f['placeholder'] . '.')) { $fields[] = $f; break; }
        }
    }
    $removed = count(array_filter($old, fn($f) => !empty($f['placeholder']) && !in_array($f, $fields, true)));
    $schema['fields'] = $fields;
    $json = json_encode($schema, JSON_UNESCAPED_UNICODE);
    if (strlen($json) > TPL_MAX_SCHEMA) throw new ApiError('התבנית גדולה מדי', 413);
    $version = tpl_replace_asset($t, $a, $bytes, $json);
    return ['ok' => true, 'changed' => true, 'added' => $added, 'removed' => $removed, 'version' => $version];
}

/** הקובץ וההגדרות החדשים: במקום (תבנית שלא בשימוש) או בגרסה חדשה. מחזיר את מספר הגרסה. */
function tpl_replace_asset(array $t, array $a, string $bytes, string $json): int
{
    if (function_exists('assert_room')) assert_room((int)$t['owner_id'], strlen($bytes));
    $path = store_file($bytes);
    $old = null;
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $version = (int)$t['current_version'];
        if (!$t['used']) {
            $old = (string)$a['path'];
            $pdo->prepare('UPDATE template_versions SET schema_enc = ? WHERE id = ?')->execute([seal($json), $t['version_id']]);
            $pdo->prepare('UPDATE template_assets SET path = ?, size = ?, sha256 = ? WHERE template_version_id = ?')
                ->execute([$path, strlen($bytes), hash('sha256', $bytes), $t['version_id']]);
        } else {
            $version++;
            $pdo->prepare('INSERT INTO template_versions (template_id, version, schema_enc, created_at) VALUES (?, ?, ?, ?)')
                ->execute([$t['id'], $version, seal($json), now_iso()]);
            $pdo->prepare('INSERT INTO template_assets (template_version_id, path, mime, size, sha256) VALUES (?, ?, ?, ?, ?)')
                ->execute([(int)$pdo->lastInsertId(), $path, DOCX_MIME, strlen($bytes), hash('sha256', $bytes)]);
            $pdo->prepare('UPDATE templates SET current_version = ? WHERE id = ?')->execute([$version, $t['id']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        delete_file($path);
        throw $e;
    }
    if ($old !== null && $old !== $path) {
        $st = db()->prepare('SELECT COUNT(*) FROM template_assets WHERE path = ?');
        $st->execute([$old]);
        if (!(int)$st->fetchColumn()) delete_file($old);
    }
    return $version;
}

/**
 * PDF ממסמך Word — רק אחרי נעילה (15.1). ה-Word הממולא מיובא לעותק זמני בעורך, ממנו PDF, והעותק נמחק.
 */
function doc_pdf(array $user, int $id): never
{
    [$d, $bytes, $name] = doc_docx_bytes($user, $id);
    if ($d['status'] !== 'completed') throw new ApiError('PDF — אחרי שהמסמך ננעל. עד אז: תצוגה מקדימה', 409);
    $pdf = wp_try(function () use ($bytes, $name) {
        $tmp = wp()->create('זמני — ' . $name);
        try {
            wp()->import($tmp, 'docx', $bytes);
            return wp()->export($tmp, 'pdf');
        } finally {
            try { wp()->delete($tmp); } catch (Throwable $e) { error_log('document-templates: wp temp delete ' . $e->getMessage()); }
        }
    });
    if (!is_string($pdf) || !str_starts_with($pdf, '%PDF')) throw new ApiError('עורך התמלילים לא החזיר PDF', 502);
    log_event($id, 'user:' . $user['id'], 'downloaded', ['format' => 'pdf']);
    header('Content-Type: application/pdf');
    header('Content-Length: ' . strlen($pdf));
    header("Content-Disposition: attachment; filename=\"document.pdf\"; filename*=UTF-8''" . rawurlencode(preg_replace('/\.docx$/', '.pdf', $name)));
    header('Cache-Control: private, no-store');
    echo $pdf;
    exit;
}
