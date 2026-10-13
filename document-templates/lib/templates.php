<?php
/**
 * תבניות — שלב 3: PDF נעול או תמונה, עם שדות שממוקמים עליהם.
 *
 * מיקום שדה נשמר כשבר מגודל הדף (0..1), לא בפיקסלים — כך הוא נכון בכל רזולוציה
 * ובכל מסך, וגם ב-PDF הסופי.
 *
 * גרסאות: כל עוד לא הונפק מהתבנית מסמך (used = 0), שמירה מעדכנת את הגרסה
 * הנוכחית. מהרגע שהונפק — שמירה יוצרת גרסה חדשה, והמסמכים שכבר הונפקו
 * נשארים על הגרסה שלהם.
 *
 * גישה: רק הבעלים (שיתוף — שלב 7). כל שאילתה מסוננת לפי owner_id; תבנית של
 * אחר נראית בדיוק כמו תבנית שאינה קיימת (404), כדי לא לגלות שהיא קיימת.
 */

declare(strict_types=1);

require_once __DIR__ . '/contacts.php';
require_once __DIR__ . '/docx.php';

const TPL_MAX_FILE_MB = 20;
const TPL_MAX_SCHEMA  = 512 * 1024;
const TPL_MAX_FIELDS  = 500;
const TPL_MIME = [
    'application/pdf' => 'pdf_locked',
    'image/png'       => 'image',
    'image/jpeg'      => 'image',
    'image/webp'      => 'image',
    DOCX_MIME         => 'docx',
];
const FIELD_TYPES = ['text', 'textarea', 'number', 'date', 'checkbox', 'signature', 'file'];
const FILE_KINDS  = ['pdf', 'image', 'office'];
const OVERFLOW    = ['shrink', 'annex', 'block'];

function valid_tpl_name($n): string
{
    $n = trim(preg_replace('/\s+/u', ' ', (string)$n));
    if ($n === '' || mb_strlen($n) > 120) throw new ApiError('שם התבנית: 1 עד 120 תווים', 422);
    return $n;
}

/** תבנית של המשתמש, עם הגרסה הנוכחית. אחרת 404. */
/** תבנית שיש למשתמש גישה אליה ברמה $min לפחות (שלב 7: גם משותפת). מוסיף 'access'. */
function tpl_owned(int $id, array $user, string $min = 'owner'): array
{
    $st = db()->prepare(
        'SELECT t.*, v.id AS version_id, v.schema_enc
         FROM templates t JOIN template_versions v ON v.template_id = t.id AND v.version = t.current_version
         WHERE t.id = ? AND t.deleted_at IS NULL'
    );
    $st->execute([$id]);
    $t = $st->fetch() ?: null;
    $t['access'] = require_level($t ? access_level('template', $id, (int)$t['owner_id'], $user) : null, $min, 'התבנית לא נמצאה');
    return $t;
}

function tpl_asset_row(int $versionId): array
{
    $st = db()->prepare('SELECT * FROM template_assets WHERE template_version_id = ? ORDER BY id LIMIT 1');
    $st->execute([$versionId]);
    $a = $st->fetch();
    if (!$a) throw new ApiError('קובץ התבנית חסר', 404);
    return $a;
}

function tpl_summary(array $t, ?array $user = null): array
{
    $schema = json_decode((string)unseal($t['schema_enc']), true) ?: [];
    return [
        'id' => (int)$t['id'],
        'name' => unseal($t['name_enc']),
        'source' => $t['source'],
        'version' => (int)$t['current_version'],
        'used' => (bool)$t['used'],
        'pages' => count($schema['pages'] ?? []),
        'fields' => count($schema['fields'] ?? []),
        'archived' => $t['archived_at'] !== null,
        'created_at' => $t['created_at'],
        'access' => $user ? access_level('template', (int)$t['id'], (int)$t['owner_id'], $user) : 'owner',
        'tags' => tags_of($t['tags_enc']),
        'owner_name' => $user && (int)$t['owner_id'] !== (int)$user['id'] ? user_name((int)$t['owner_id']) : null,
    ];
}

function tpl_list(array $user): array
{
    $st = db()->prepare(
        'SELECT t.*, v.schema_enc
         FROM templates t JOIN template_versions v ON v.template_id = t.id AND v.version = t.current_version
         WHERE ' . mine_or_shared_sql('t', 'template') . ' AND t.deleted_at IS NULL ORDER BY t.created_at DESC'
    );
    $st->execute([':uid' => $user['id']]);
    return ['ok' => true, 'templates' => array_map(fn($t) => tpl_summary($t, $user), $st->fetchAll()),
        'usage' => function_exists('usage_view') ? usage_view((int)$user['id']) : null];
}

/** קובץ חדש → תבנית בגרסה 1, בלי שדות. מבנה הדפים נקבע בשמירה הראשונה מהדפדפן. */
function tpl_create(array $user, array $post, array $files): array
{
    $name = valid_tpl_name($post['name'] ?? '');
    $f = $files['file'] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) throw new ApiError('לא נבחר קובץ', 422);
    if (in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
        throw new ApiError('הקובץ גדול מהמותר בשרת (' . ini_get('upload_max_filesize') . ')', 413);
    }
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name']) && PHP_SAPI !== 'cli') {
        throw new ApiError('ההעלאה נכשלה', 400);
    }
    if ($f['size'] > TPL_MAX_FILE_MB * 1024 * 1024) throw new ApiError('הקובץ גדול מ-' . TPL_MAX_FILE_MB . 'MB', 413);

    $bytes = (string)file_get_contents($f['tmp_name']);
    // הסוג נקבע מהתוכן, לא מהשם או ממה שהדפדפן הצהיר.
    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
    // Word הוא ZIP; finfo לא תמיד מזהה אותו בשמו. בודקים את המבנה עצמו.
    if (!isset(TPL_MIME[$mime]) && is_docx($bytes)) $mime = DOCX_MIME;
    if (!isset(TPL_MIME[$mime])) throw new ApiError('סוג קובץ לא נתמך. PDF, Word (docx), PNG, JPG או WEBP', 415);
    $schema = ['pages' => [], 'fields' => []];
    if ($mime === DOCX_MIME) {
        $bytes = docx_normalize($bytes, $names);
        if (!$names) throw new ApiError('לא נמצאו שדות בקובץ. מסמנים שדה כך: {{שם_הלקוח}}', 422);
        $schema = ['pages' => [['w' => 595, 'h' => 842]], 'fields' => docx_fields($names)];
    }

    if (function_exists('assert_room')) assert_room((int)$user['id'], strlen($bytes));
    $path = store_file($bytes);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $now = now_iso();
        $pdo->prepare('INSERT INTO templates (owner_id, name_enc, source, current_version, created_at) VALUES (?, ?, ?, 1, ?)')
            ->execute([$user['id'], seal($name), TPL_MIME[$mime], $now]);
        $tid = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO template_versions (template_id, version, schema_enc, created_at) VALUES (?, 1, ?, ?)')
            ->execute([$tid, seal(json_encode($schema, JSON_UNESCAPED_UNICODE)), $now]);
        $pdo->prepare('INSERT INTO template_assets (template_version_id, path, mime, size, sha256) VALUES (?, ?, ?, ?, ?)')
            ->execute([(int)$pdo->lastInsertId(), $path, $mime, strlen($bytes), hash('sha256', $bytes)]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        delete_file($path);
        throw $e;
    }
    return ['ok' => true, 'id' => $tid];
}

function tpl_get(array $user, int $id): array
{
    $t = tpl_owned($id, $user, 'view');
    $a = tpl_asset_row((int)$t['version_id']);
    return ['ok' => true, 'template' => tpl_summary($t, $user) + [
        'mime' => $a['mime'],
        'schema' => json_decode((string)unseal($t['schema_enc']), true),
        'numbering' => json_decode((string)$t['numbering_json'], true),
    ]];
}

/** מגיש את קובץ הרקע — מפוענח, רק לבעלים. */
function tpl_asset(array $user, int $id): never
{
    $t = tpl_owned($id, $user, 'view');
    $a = tpl_asset_row((int)$t['version_id']);
    $bytes = load_file($a['path']);
    header('Content-Type: ' . $a['mime']);
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    echo $bytes;
    exit;
}

/** מנקה את מה שהדפדפן שלח: רק סוגים מוכרים, מספרים בטווח, מחרוזות באורך סביר. */
function clean_schema($s): array
{
    if (!is_array($s)) throw new ApiError('מבנה תבנית לא תקין', 422);
    $num = fn($v, $min, $max) => (float)(is_numeric($v) ? max($min, min($max, (float)$v)) : $min);
    $str = fn($v, $len) => mb_substr(trim((string)$v), 0, $len);

    $pages = [];
    foreach (array_slice((array)($s['pages'] ?? []), 0, 500) as $p) {
        $pages[] = ['w' => $num($p['w'] ?? 0, 1, 20000), 'h' => $num($p['h'] ?? 0, 1, 20000)];
    }
    if (!$pages) throw new ApiError('לתבנית אין דפים', 422);

    // משתתפים (שלב 5). "owner" — מי שמנפיק — תמיד קיים ותמיד ראשון.
    $roles = [['key' => 'owner', 'label' => 'אני (המנפיק)', 'auth' => 'none']];
    foreach (array_slice((array)($s['roles'] ?? []), 0, 20) as $r) {
        $key = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($r['key'] ?? '')));
        if ($key === '' || $key === 'owner' || in_array($key, array_column($roles, 'key'), true)) continue;
        $roles[] = [
            'key' => $key,
            'label' => $str($r['label'] ?? '', 60) ?: 'משתתף',
            'auth' => in_array($r['auth'] ?? '', ['none', 'email'], true) ? $r['auth'] : 'none',
        ];
    }
    $roleKeys = array_column($roles, 'key');

    $fields = [];
    $keys = [];
    foreach ((array)($s['fields'] ?? []) as $f) {
        if (count($fields) >= TPL_MAX_FIELDS) throw new ApiError('יותר מדי שדות', 422);
        $type = in_array($f['type'] ?? '', FIELD_TYPES, true) ? $f['type'] : 'text';
        $key = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($f['key'] ?? '')));
        if ($key === '' || isset($keys[$key])) $key = 'field_' . (count($fields) + 1) . '_' . bin2hex(random_bytes(2));
        $keys[$key] = true;
        // קודם הגודל, ואז המיקום — כך שדה תמיד נשאר כולו בתוך הדף.
        $w = $num($f['w'] ?? 0.2, 0.005, 1);
        $h = $num($f['h'] ?? 0.03, 0.005, 1);
        $fields[] = [
            'id' => $str($f['id'] ?? $key, 40) ?: $key,
            'key' => $key,
            'type' => $type,
            'label' => $str($f['label'] ?? '', 120) ?: 'שדה',
            'help' => $str($f['help'] ?? '', 300),
            'required' => !empty($f['required']),
            'default' => $str($f['default'] ?? '', 1000),
            'page' => (int)$num($f['page'] ?? 0, 0, count($pages) - 1),
            'x' => $num($f['x'] ?? 0, 0, 1 - $w),
            'y' => $num($f['y'] ?? 0, 0, 1 - $h),
            'w' => $w,
            'h' => $h,
            'fontSize' => (int)$num($f['fontSize'] ?? 12, 6, 72),
            'align' => in_array($f['align'] ?? '', ['right', 'center', 'left'], true) ? $f['align'] : 'right',
            'overflow' => in_array($f['overflow'] ?? '', OVERFLOW, true) ? $f['overflow'] : 'shrink',
            'role' => in_array($f['role'] ?? '', $roleKeys, true) ? $f['role'] : 'owner',
            // שלב 9: בתבנית Word — הסימון בקובץ שהשדה ממלא ({{...}}).
            'placeholder' => $str($f['placeholder'] ?? '', 60),
            // שלב 8: מתמלא מאיש הקשר שמקושר למסמך.
            'contactField' => in_array($f['contactField'] ?? '', CONTACT_FIELDS, true) && $type !== 'file' && $type !== 'signature'
                ? $f['contactField'] : '',
        ];
        if ($type === 'file') {
            // צירוף קובץ (שלב 6): אילו סוגים, גודל מרבי לקובץ, ואחד או כמה.
            $kinds = array_values(array_intersect(FILE_KINDS, (array)($f['accept'] ?? [])));
            $fields[count($fields) - 1] += [
                'accept' => $kinds ?: ['pdf', 'image'],
                'maxMb' => (int)$num($f['maxMb'] ?? 10, 1, 50),
                'multiple' => !empty($f['multiple']),
            ];
        }
    }
    return ['pages' => $pages, 'fields' => $fields, 'roles' => $roles, 'sequential' => !empty($s['sequential'])];
}

function tpl_save(array $user, array $b): array
{
    $t = tpl_owned((int)($b['id'] ?? 0), $user, 'edit');
    $json = json_encode(clean_schema($b['schema'] ?? null), JSON_UNESCAPED_UNICODE);
    if (strlen($json) > TPL_MAX_SCHEMA) throw new ApiError('התבנית גדולה מדי', 413);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        if (array_key_exists('numbering', $b)) {
            require_once __DIR__ . '/documents.php';
            $pdo->prepare('UPDATE templates SET numbering_json = ? WHERE id = ?')
                ->execute([json_encode(clean_numbering($b['numbering']), JSON_UNESCAPED_UNICODE), $t['id']]);
        }
        if (array_key_exists('tags', $b)) {
            $pdo->prepare('UPDATE templates SET tags_enc = ? WHERE id = ?')
                ->execute([seal(json_encode(clean_tags($b['tags']), JSON_UNESCAPED_UNICODE)), $t['id']]);
        }
        if (array_key_exists('name', $b)) {
            $pdo->prepare('UPDATE templates SET name_enc = ? WHERE id = ?')->execute([seal(valid_tpl_name($b['name'])), $t['id']]);
        }
        $version = (int)$t['current_version'];
        if (!$t['used']) {
            $pdo->prepare('UPDATE template_versions SET schema_enc = ? WHERE id = ?')->execute([seal($json), $t['version_id']]);
        } else {
            // הונפקו ממנה מסמכים — גרסה חדשה, על אותו קובץ רקע.
            $version++;
            $now = now_iso();
            $pdo->prepare('INSERT INTO template_versions (template_id, version, schema_enc, created_at) VALUES (?, ?, ?, ?)')
                ->execute([$t['id'], $version, seal($json), $now]);
            $vid = (int)$pdo->lastInsertId();
            $pdo->prepare(
                'INSERT INTO template_assets (template_version_id, path, mime, size, sha256)
                 SELECT ?, path, mime, size, sha256 FROM template_assets WHERE template_version_id = ?'
            )->execute([$vid, $t['version_id']]);
            $pdo->prepare('UPDATE templates SET current_version = ? WHERE id = ?')->execute([$version, $t['id']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return ['ok' => true, 'version' => $version];
}

function tpl_archive(array $user, array $b): array
{
    $t = tpl_owned((int)($b['id'] ?? 0), $user, 'co_owner');
    db()->prepare('UPDATE templates SET archived_at = ? WHERE id = ?')
        ->execute([empty($b['restore']) ? now_iso() : null, $t['id']]);
    return ['ok' => true];
}

/**
 * מחיקה אמיתית — רק לתבנית שלא הונפק ממנה אף מסמך. אחרת ארכיון בלבד
 * (חוק ברזל 4 באפיון: מה שהשתמשו בו לא נמחק לעולם).
 */
function tpl_delete(array $user, array $b): array
{
    $t = tpl_owned((int)($b['id'] ?? 0), $user, 'owner');
    if ($t['used']) throw new ApiError('הונפקו מהתבנית מסמכים — אפשר רק להעביר לארכיון', 409);

    $pdo = db();
    $st = $pdo->prepare(
        'SELECT a.path FROM template_assets a JOIN template_versions v ON v.id = a.template_version_id WHERE v.template_id = ?'
    );
    $st->execute([$t['id']]);
    $paths = array_unique($st->fetchAll(PDO::FETCH_COLUMN));

    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM template_assets WHERE template_version_id IN (SELECT id FROM template_versions WHERE template_id = ?)')
        ->execute([$t['id']]);
    $pdo->prepare('DELETE FROM template_versions WHERE template_id = ?')->execute([$t['id']]);
    $pdo->prepare('DELETE FROM counters WHERE template_id = ?')->execute([$t['id']]);
    $pdo->prepare("DELETE FROM shares WHERE object_type = 'template' AND object_id = ?")->execute([$t['id']]);
    $pdo->prepare('DELETE FROM templates WHERE id = ?')->execute([$t['id']]);
    $pdo->commit();
    foreach ($paths as $p) delete_file($p);
    return ['ok' => true];
}
