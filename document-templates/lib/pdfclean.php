<?php
/**
 * ניקוי ההגדרות המוכנות מתוך קובץ המקור של תבנית PDF.
 *
 * PDF "עם הגדרות מוכנות" נושא קובץ מצורף (dt-template.json): השדות, הנוסחאות והממלאים. העורך קורא
 * אותו פעם אחת — בפתיחה הראשונה — ומאז הוא מיותר. אבל הקובץ המקורי נשלח לכל מי שממלא, וכך ההגדרות
 * היו נגישות ללקוח. כאן התוכן של הקובץ המצורף מוחלף ב-"{}" — באותו אורך בדיוק, כך שמבנה ה-PDF
 * (טבלת המיקומים) לא זז ולא צריך לבנות אותו מחדש. מצורפים אחרים (לא שלנו) — לא נוגעים.
 */

declare(strict_types=1);

/** ה-PDF בלי תוכן ההגדרות שלנו, או null אם אין מה לנקות. */
function pdf_strip_settings(string $pdf): ?string
{
    if (!str_contains($pdf, '/EmbeddedFile')) return null;
    $changed = false;
    $off = 0;
    while (preg_match('#/Type\s*/EmbeddedFile(?![A-Za-z])#', $pdf, $m, PREG_OFFSET_CAPTURE, $off)) {
        $at = $m[0][1];
        $off = $at + 1;
        $objStart = strrpos(substr($pdf, 0, $at), ' obj');
        $streamKw = strpos($pdf, 'stream', $at);
        $endobj = strpos($pdf, 'endobj', $at);
        if ($objStart === false || $streamKw === false || ($endobj !== false && $endobj < $streamKw)) continue;
        $dict = substr($pdf, $objStart, $streamKw - $objStart);
        $start = $streamKw + 6;
        if (substr($pdf, $start, 2) === "\r\n") $start += 2; elseif (in_array($pdf[$start] ?? '', ["\n", "\r"], true)) $start += 1;
        $end = strpos($pdf, 'endstream', $start);
        if ($end === false) continue;
        $len = preg_match('#/Length\s+(\d+)(?!\s+\d+\s+R)#', $dict, $l) ? (int)$l[1] : -1;
        if ($len < 0 || $start + $len > $end) $len = strlen(rtrim(substr($pdf, $start, $end - $start), "\r\n"));
        $raw = substr($pdf, $start, $len);
        $flate = str_contains($dict, '/FlateDecode');
        $plain = $flate ? @zlib_decode($raw) : $raw;
        if (!is_string($plain) || !pdf_is_settings($plain)) continue;
        $blank = $flate ? gzcompress('{}') : '{}';
        if (strlen($blank) > $len) continue;
        $pdf = substr_replace($pdf, str_pad($blank, $len, $flate ? "\0" : ' '), $start, $len);
        $changed = true;
    }
    return $changed ? $pdf : null;
}

/** ההגדרות שלנו: ייצוא מלא (format dt-template), או הגדרות מוכנות (fields). */
function pdf_is_settings(string $json): bool
{
    $j = json_decode($json, true);
    return is_array($j) && (($j['format'] ?? '') === 'dt-template' || isset($j['fields']) && is_array($j['fields']));
}

/** מנקה את קובץ המקור (המשותף לכל הגרסאות שמצביעות עליו). מחזיר אם נוקה. */
function asset_clean_settings(string $path): bool
{
    $clean = pdf_strip_settings(load_file($path));
    if ($clean === null) return false;
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $tmp = DT_FILES_DIR . '/' . $path . '.tmp';
    if (file_put_contents($tmp, $nonce . sodium_crypto_secretbox($clean, $nonce, secret_key())) === false || !rename($tmp, DT_FILES_DIR . '/' . $path)) {
        @unlink($tmp);
        return false;
    }
    db()->prepare('UPDATE template_assets SET size = ?, sha256 = ? WHERE path = ?')->execute([strlen($clean), hash('sha256', $clean), $path]);
    return true;
}

/** תבנית שכבר קיבלה את ההגדרות (יש לה שדות) — הקובץ שלה מנוקה. */
function tpl_clean_asset(int $versionId, array $schema): void
{
    if (empty($schema['fields']) && empty($schema['parsed'])) return;   // העורך עוד לא קרא את ההגדרות
    $st = db()->prepare("SELECT path FROM template_assets WHERE template_version_id = ? AND mime = 'application/pdf'");
    $st->execute([$versionId]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $path) {
        try { asset_clean_settings($path); } catch (Throwable) { /* קובץ חסר / פגום — לא עוצרים שמירה */ }
    }
}

/** פעם אחת: כל התבניות הקיימות. מסומן בקובץ בתיקיית הנתונים. */
function pdf_settings_sweep(): void
{
    $mark = DT_DATA_DIR . '/pdf-settings-cleaned';
    if (is_file($mark)) return;
    $rows = db()->query(
        "SELECT a.path, v.schema_enc FROM template_assets a
         JOIN template_versions v ON v.id = a.template_version_id
         JOIN templates t ON t.id = v.template_id AND v.version = t.current_version
         WHERE a.mime = 'application/pdf'"
    )->fetchAll();
    $cleaned = 0;
    foreach ($rows as $r) {
        $schema = json_decode((string)unseal($r['schema_enc']), true) ?: [];
        if (empty($schema['fields']) && empty($schema['parsed'])) continue;
        try { $cleaned += (int)asset_clean_settings($r['path']); } catch (Throwable) { /* קובץ חסר — ממשיכים */ }
    }
    @file_put_contents($mark, json_encode(['at' => now_iso(), 'cleaned' => $cleaned]));
}

/**
 * לאזור המפתח: מתי רץ הניקוי החד-פעמי, וכמה קבצים עדיין נושאים הגדרות — נבדק עכשיו, קובץ-קובץ.
 * waiting: תבניות שהעורך עוד לא פתח (ההגדרות עוד נחוצות — יתנקו בשמירה הראשונה).
 */
function pdf_settings_status(): array
{
    $mark = DT_DATA_DIR . '/pdf-settings-cleaned';
    $raw = is_file($mark) ? (string)file_get_contents($mark) : '';
    $j = json_decode($raw, true);
    $out = ['sweep_at' => is_array($j) ? $j['at'] : ($raw ?: null), 'sweep_cleaned' => is_array($j) ? (int)$j['cleaned'] : null,
        'files' => 0, 'clean' => 0, 'dirty' => 0, 'waiting' => 0, 'missing' => 0];
    $rows = db()->query(
        "SELECT a.path, MAX(v.schema_enc) AS schema_enc FROM template_assets a
         JOIN template_versions v ON v.id = a.template_version_id
         JOIN templates t ON t.id = v.template_id AND v.version = t.current_version AND t.deleted_at IS NULL
         WHERE a.mime = 'application/pdf' GROUP BY a.path"
    )->fetchAll();
    foreach ($rows as $r) {
        $out['files']++;
        try { $dirty = pdf_strip_settings(load_file($r['path'])) !== null; }
        catch (Throwable) { $out['missing']++; continue; }
        if (!$dirty) { $out['clean']++; continue; }
        $schema = json_decode((string)unseal($r['schema_enc']), true) ?: [];
        $out[empty($schema['fields']) && empty($schema['parsed']) ? 'waiting' : 'dirty']++;
    }
    return $out;
}
