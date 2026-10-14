<?php
/**
 * גופנים: המשתמש מעלה גופן (TTF / OTF), ובוחר אותו לכל שדה על הדף. אותו קובץ
 * משמש לתצוגה על המסך ולהטמעה ב-PDF שיוצא — כך המסך והפלט זהים.
 *
 * הקובץ נשמר מוצפן כמו כל קובץ אחר. מי שמקבל אותו: הבעלים, ומי שרשאי לראות
 * תבנית או מסמך שמשתמשים בו (כולל ממלא בקישור — כדי לראות את השדה בגופן שלו).
 */

declare(strict_types=1);

const FONT_MAX_BYTES = 5 * 1024 * 1024;
const FONT_MAX_COUNT = 30;

function fonts_migrate(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS fonts (
            id          INTEGER PRIMARY KEY,
            owner_id    INTEGER NOT NULL REFERENCES users(id),
            name_enc    TEXT NOT NULL,
            path        TEXT NOT NULL,
            size        INTEGER NOT NULL,
            format      TEXT NOT NULL,
            created_at  TEXT NOT NULL,
            deleted_at  TEXT
        );
        CREATE INDEX IF NOT EXISTS idx_fonts_owner ON fonts(owner_id);
        SQL);
}

/** סוג הקובץ לפי החתימה שבתחילתו — לא לפי השם. */
function font_format(string $bytes): ?string
{
    $sig = substr($bytes, 0, 4);
    if ($sig === "\x00\x01\x00\x00" || $sig === 'true') return 'ttf';
    if ($sig === 'OTTO') return 'otf';
    return null;
}

function font_list(array $user): array
{
    $st = db()->prepare('SELECT id, name_enc, size, format, created_at FROM fonts WHERE owner_id = ? AND deleted_at IS NULL ORDER BY id');
    $st->execute([$user['id']]);
    return ['ok' => true, 'fonts' => array_map(fn($f) => ['id' => (int)$f['id'], 'name' => unseal($f['name_enc']),
        'size' => (int)$f['size'], 'format' => $f['format'], 'created_at' => $f['created_at']], $st->fetchAll())];
}

function font_upload(array $user, array $post, array $files): array
{
    $f = $files['file'] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new ApiError('לא נבחר קובץ', 422);
    if ($f['size'] > FONT_MAX_BYTES) throw new ApiError('קובץ גופן עד 5MB', 413);
    $bytes = (string)file_get_contents($f['tmp_name']);
    $format = font_format($bytes);
    if (!$format) throw new ApiError('אפשר להעלות גופן TTF או OTF בלבד (זה מה שאפשר להטמיע ב-PDF)', 415);
    $n = db()->prepare('SELECT COUNT(*) FROM fonts WHERE owner_id = ? AND deleted_at IS NULL');
    $n->execute([$user['id']]);
    if ((int)$n->fetchColumn() >= FONT_MAX_COUNT) throw new ApiError('עד ' . FONT_MAX_COUNT . ' גופנים', 409);
    $name = mb_substr(trim((string)($post['name'] ?? '')), 0, 60)
        ?: mb_substr(preg_replace('/\.(ttf|otf)$/i', '', (string)($f['name'] ?? 'גופן')), 0, 60);
    $path = store_file($bytes);
    db()->prepare('INSERT INTO fonts (owner_id, name_enc, path, size, format, created_at) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$user['id'], seal($name), $path, strlen($bytes), $format, now_iso()]);
    return ['ok' => true, 'font' => ['id' => (int)db()->lastInsertId(), 'name' => $name, 'size' => strlen($bytes), 'format' => $format]];
}

/** מחיקה רכה: תבניות ומסמכים שכבר משתמשים בגופן ממשיכים לקבל אותו. */
function font_delete(array $user, array $b): array
{
    $st = db()->prepare('UPDATE fonts SET deleted_at = ? WHERE id = ? AND owner_id = ? AND deleted_at IS NULL');
    $st->execute([now_iso(), (int)($b['id'] ?? 0), $user['id']]);
    if (!$st->rowCount()) throw new ApiError('הגופן לא נמצא', 404);
    return ['ok' => true];
}

/** מזהי הגופנים שמשמשים במבנה. */
function schema_fonts(array $schema): array
{
    return array_values(array_unique(array_filter(array_map(fn($f) => (int)($f['font'] ?? 0), $schema['fields'] ?? []))));
}

/** גופנים שמותר לתבנית להפנות אליהם: של מי ששומר, ומה שכבר היה בה. */
function allowed_fonts(array $user, array $t): array
{
    $st = db()->prepare('SELECT id FROM fonts WHERE owner_id = ? AND deleted_at IS NULL');
    $st->execute([$user['id']]);
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    return array_values(array_unique(array_merge($ids, schema_fonts(json_decode((string)unseal($t['schema_enc']), true) ?: []))));
}

/** השמות של הגופנים שבמבנה — לתצוגה בבחירה (גם לשותף שאינו הבעלים). */
function fonts_info(array $schema): array
{
    $ids = schema_fonts($schema);
    if (!$ids) return [];
    $st = db()->prepare('SELECT id, name_enc FROM fonts WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
    $st->execute($ids);
    return array_map(fn($f) => ['id' => (int)$f['id'], 'name' => unseal($f['name_enc'])], $st->fetchAll());
}

function font_send(int $id): never
{
    $st = db()->prepare('SELECT path, format FROM fonts WHERE id = ?');
    $st->execute([$id]);
    $f = $st->fetch() ?: throw new ApiError('הגופן לא נמצא', 404);
    $bytes = load_file($f['path']);
    header('Content-Type: ' . ($f['format'] === 'otf' ? 'font/otf' : 'font/ttf'));
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: private, max-age=86400');
    echo $bytes;
    exit;
}

/** הקובץ — לבעלים, או למי שרשאי לראות תבנית/מסמך שמשתמשים בו. */
function font_file(array $user, array $q): never
{
    $id = (int)($q['id'] ?? 0);
    $st = db()->prepare('SELECT owner_id FROM fonts WHERE id = ?');
    $st->execute([$id]);
    $owner = $st->fetchColumn();
    if ($owner === false) throw new ApiError('הגופן לא נמצא', 404);
    if ((int)$owner !== (int)$user['id']) {
        $schema = !empty($q['doc'])
            ? doc_schema(doc_owned((int)$q['doc'], $user, 'view'))
            : (json_decode((string)unseal(tpl_owned((int)($q['tpl'] ?? 0), $user, 'view')['schema_enc']), true) ?: []);
        if (!in_array($id, schema_fonts($schema), true)) throw new ApiError('הגופן לא נמצא', 404);
    }
    font_send($id);
}

/** לממלא בקישור: רק גופן שמשמש במסמך שלו. */
function fill_font(array $q): never
{
    [$p, $d, $schema] = fill_context();
    if (needs_otp($p)) throw new ApiError('נדרש אימות', 401);
    $id = (int)($q['id'] ?? 0);
    if (!in_array($id, schema_fonts($schema), true)) throw new ApiError('הגופן לא נמצא', 404);
    font_send($id);
}
