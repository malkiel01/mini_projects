<?php
/**
 * ספריית החומרים, סוגי המוצרים ותעריפי הנגר — צד השרת.
 *
 * הספרייה בשרת היא אותו diff שהדפדפן שמר עד שלב 3: רק מה ששונה מהזריעה
 * שבקוד (materials.js). הזריעה עצמה חיה ב-JS, והשרת לא מכיר אותה — הוא
 * שומר שורות ומגיש אותן. התמונות — קבצים ב-data/media, מוגשים ישירות.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

// ---- חומרים ----

function materialsGet(): array {
    $rows = db()->query('SELECT id, data, image_mm, has_image FROM materials')->fetchAll();
    $diff = []; $images = [];
    foreach ($rows as $r) {
        $d = jsonArr($r['data']);
        if ($d) $diff[] = ['id' => $r['id']] + $d;
        if ((int) $r['has_image'] === 1) {
            $images[$r['id']] = ['url' => 'data/media/' . imageFile($r['id']), 'imageMm' => (int) ($r['image_mm'] ?: 600)];
        }
    }
    // מפה ריקה חייבת להישאר אובייקט ב-JSON ({}), אחרת הדפדפן מקבל מערך.
    return ['diff' => $diff, 'images' => (object) $images];
}

/** מחליף את ה-diff כולו. שומר על תמונות של שורות שנשארו. */
function materialsSave(array $diff): void {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $keep = [];
        $up = $pdo->prepare('INSERT INTO materials (id, data, updated_at) VALUES (?,?,?)
                             ON CONFLICT(id) DO UPDATE SET data = excluded.data, updated_at = excluded.updated_at');
        foreach ($diff as $m) {
            if (!is_array($m) || !isset($m['id']) || !is_string($m['id']) || !preg_match('/^[a-z]+:[a-z0-9._-]+$/', $m['id'])) continue;
            $id = $m['id'];
            unset($m['id'], $m['image'], $m['seed']);
            $up->execute([$id, jsonStr($m), nowIso()]);
            $keep[] = $id;
        }
        // שורות שאינן ב-diff החדש: בלי תמונה — נמחקות; עם תמונה — נשארות, בלי שינויים.
        $notIn = $keep ? ' AND id NOT IN (' . implode(',', array_fill(0, count($keep), '?')) . ')' : '';
        $pdo->prepare('DELETE FROM materials WHERE has_image = 0' . $notIn)->execute($keep);
        $pdo->prepare("UPDATE materials SET data = '{}' WHERE has_image = 1" . $notIn)->execute($keep);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * אביזרים: מחליף רק את שורות הפרזול (hw:) ב-diff — ידיות, כפתורים, צירים
 * וגלגלים שהנגר מנהל במסך "אביזרים". שאר הספרייה לא נגועה, ולכן נגר (לא רק
 * מנהל) רשאי. שורות hw: שאינן ברשימה נמחקות (לפרזול אין תמונות).
 */
function accessoriesSave(array $diff): void { prefixSave($diff, 'hw'); }

/** דוגמאות חירוץ CNC (מסך "🛠 CNC"): כמו האביזרים — רק שורות cnc:, ונגר רשאי. */
function cncSave(array $diff): void { prefixSave($diff, 'cnc'); }

// ---- מעבדת המוצרים: התאמות לתבניות ----

/** כל ההתאמות: { templateKey: {...}, _limits: {...} } (אובייקט ריק אם אין). */
function rulesGet(): object {
    $out = [];
    foreach (db()->query('SELECT key, data FROM template_rules')->fetchAll() as $r) {
        $d = jsonArr($r['data']);
        if ($d) $out[$r['key']] = $d;
    }
    return (object) $out;
}

/**
 * מחליף את כל ההתאמות. מפתח: אותיות קטנות וקו תחתון (מפתח תבנית או '_limits');
 * ערך: אובייקט. מפתח שאינו ברשימה — נמחק (= חזרה לתבנית שבקוד). עד 200KB.
 */
function rulesSave(array $rules): void {
    if (strlen(json_encode($rules)) > 200000) throw new AppError('ההתאמות גדולות מדי', 400);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->exec('DELETE FROM template_rules');
        $up = $pdo->prepare('INSERT INTO template_rules (key, data, updated_at) VALUES (?,?,?)');
        foreach ($rules as $k => $v) {
            if (!is_string($k) || !preg_match('/^[a-z_]{1,40}$/', $k) || !is_array($v) || !$v) continue;
            $up->execute([$k, jsonStr($v), nowIso()]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** מחליף רק את שורות הספרייה עם הקידומת `$prefix:` (שאינן ב-diff — נמחקות). */
function prefixSave(array $diff, string $prefix): void {
    if (!preg_match('/^[a-z]+$/', $prefix)) throw new AppError('קידומת לא חוקית', 400);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $keep = [];
        $up = $pdo->prepare('INSERT INTO materials (id, data, updated_at) VALUES (?,?,?)
                             ON CONFLICT(id) DO UPDATE SET data = excluded.data, updated_at = excluded.updated_at');
        foreach ($diff as $m) {
            if (!is_array($m) || !isset($m['id']) || !is_string($m['id']) || !preg_match('/^' . $prefix . ':[a-z0-9._-]+$/', $m['id'])) continue;
            $id = $m['id'];
            unset($m['id'], $m['image'], $m['seed']);
            $up->execute([$id, jsonStr($m), nowIso()]);
            $keep[] = $id;
        }
        $notIn = $keep ? ' AND id NOT IN (' . implode(',', array_fill(0, count($keep), '?')) . ')' : '';
        $pdo->prepare("DELETE FROM materials WHERE id LIKE '" . $prefix . ":%'" . $notIn)->execute($keep);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function imageFile(string $id): string {
    return preg_replace('/[^a-z0-9._-]/', '_', $id) . '.jpg';
}

/**
 * תמונה לחומר: מגיעה כ-data URL (הדפדפן כבר הקטין ל-1024 וכיווץ ל-JPEG).
 * נבדקת עם getimagesize ולא לפי הסיומת — רק JPEG/PNG/WebP נשמרים, וכולם
 * כ-.jpg כדי שה-.htaccess של media/ יגיש אותם ולא יריץ דבר.
 */
function materialImageSave(string $id, string $dataUrl, int $imageMm): array {
    if (!preg_match('/^[a-z]+:[a-z0-9._-]+$/', $id)) throw new AppError('מזהה חומר לא תקין');
    $imageMm = max(50, min(5000, $imageMm ?: 600));
    // בלי תמונה חדשה — רק קנה המידה של התמונה הקיימת משתנה.
    if ($dataUrl === '') {
        $st = db()->prepare('UPDATE materials SET image_mm = ? WHERE id = ? AND has_image = 1');
        $st->execute([$imageMm, $id]);
        if ($st->rowCount() === 0) throw new AppError('לחומר הזה אין תמונה', 404);
        return ['url' => 'data/media/' . imageFile($id), 'imageMm' => $imageMm];
    }
    if (!preg_match('#^data:image/(jpeg|png|webp);base64,(.+)$#s', $dataUrl, $m)) throw new AppError('התמונה חייבת להיות JPEG, PNG או WebP');
    $bin = base64_decode($m[2], true);
    if ($bin === false || strlen($bin) > 3 * 1024 * 1024) throw new AppError('התמונה גדולה מדי (עד 3MB)');
    $info = @getimagesizefromstring($bin);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) throw new AppError('הקובץ אינו תמונה תקינה');

    if (!is_dir(MEDIA_DIR) && !@mkdir(MEDIA_DIR, 0775, true) && !is_dir(MEDIA_DIR)) throw new AppError('לא ניתן ליצור את תיקיית המדיה', 500);
    // PNG/WebP מומרים ל-JPEG אם GD זמין; אחרת נשמרים כמו שהם תחת .jpg (הדפדפן מזהה לפי התוכן).
    if ($info[2] !== IMAGETYPE_JPEG && function_exists('imagecreatefromstring')) {
        $im = @imagecreatefromstring($bin);
        if ($im) { ob_start(); imagejpeg($im, null, 85); $bin = ob_get_clean(); imagedestroy($im); }
    }
    file_put_contents(MEDIA_DIR . '/' . imageFile($id), $bin);
    db()->prepare('INSERT INTO materials (id, data, image_mm, has_image, updated_at) VALUES (?, ?, ?, 1, ?)
                   ON CONFLICT(id) DO UPDATE SET image_mm = excluded.image_mm, has_image = 1, updated_at = excluded.updated_at')
        ->execute([$id, '{}', $imageMm, nowIso()]);
    return ['url' => 'data/media/' . imageFile($id), 'imageMm' => $imageMm];
}

function materialImageDelete(string $id): void {
    @unlink(MEDIA_DIR . '/' . imageFile($id));
    db()->prepare("UPDATE materials SET has_image = 0, image_mm = NULL WHERE id = ?")->execute([$id]);
    db()->prepare("DELETE FROM materials WHERE id = ? AND data = '{}'")->execute([$id]);
}

// ---- סוגי מוצרים ----

function typesList(bool $includeInactive): array {
    $sql = 'SELECT * FROM product_types' . ($includeInactive ? '' : ' WHERE active = 1') . ' ORDER BY name';
    return array_map(fn ($t) => [
        'id' => (int) $t['id'], 'template_key' => $t['template_key'], 'name' => $t['name'],
        'description' => $t['description'], 'defaults' => jsonArr($t['defaults']),
        'active' => (int) $t['active'] === 1, 'updated_at' => $t['updated_at'],
    ], db()->query($sql)->fetchAll());
}

function typeSave(array $admin, array $in): array {
    $id = (int) ($in['id'] ?? 0);
    $key = is_string($in['template_key'] ?? null) ? $in['template_key'] : '';
    if (!preg_match('/^[a-z][a-z0-9-]{1,30}$/', $key)) throw new AppError('מפתח תבנית לא תקין');
    $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 60);
    if ($name === '') throw new AppError('לסוג מוצר צריך שם');
    $desc = mb_substr(trim((string) ($in['description'] ?? '')), 0, 200);
    $defaults = is_array($in['defaults'] ?? null) ? $in['defaults'] : [];
    $active = array_key_exists('active', $in) ? ($in['active'] ? 1 : 0) : 1;
    $now = nowIso();
    if ($id) {
        db()->prepare('UPDATE product_types SET template_key=?, name=?, description=?, defaults=?, active=?, updated_at=? WHERE id=?')
            ->execute([$key, $name, $desc, jsonStr($defaults), $active, $now, $id]);
    } else {
        db()->prepare('INSERT INTO product_types (template_key, name, description, defaults, active, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$key, $name, $desc, jsonStr($defaults), $active, $admin['id'], $now, $now]);
        $id = (int) db()->lastInsertId();
    }
    foreach (typesList(true) as $t) if ($t['id'] === $id) return $t;
    throw new AppError('סוג המוצר לא נמצא', 404);
}

function typeDelete(int $id): void {
    // פרויקטים שמצביעים על הסוג נשארים (ON DELETE SET NULL) — יש להם template_key משלהם.
    db()->prepare('DELETE FROM product_types WHERE id = ?')->execute([$id]);
}

// ---- תעריפים ----

function ratesGet(int $userId): array {
    $st = db()->prepare('SELECT key, value FROM rates WHERE user_id = ?');
    $st->execute([$userId]);
    $out = ['laborHour' => null, 'markup' => null, 'materials' => []];
    foreach ($st->fetchAll() as $r) {
        if ($r['key'] === 'labor_hour') $out['laborHour'] = (float) $r['value'];
        elseif ($r['key'] === 'markup') $out['markup'] = (float) $r['value'];
        elseif (str_starts_with($r['key'], 'material:')) $out['materials'][substr($r['key'], 9)] = (float) $r['value'];
    }
    return $out;
}

/** מחליף את כל התעריפים של המשתמש. null/חסר = בלי דריסה (ברירת המחדל). */
function ratesSave(int $userId, array $in): array {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM rates WHERE user_id = ?')->execute([$userId]);
        $ins = $pdo->prepare('INSERT INTO rates (user_id, key, value) VALUES (?,?,?)');
        if (isset($in['laborHour']) && is_numeric($in['laborHour'])) $ins->execute([$userId, 'labor_hour', (float) $in['laborHour']]);
        if (isset($in['markup']) && is_numeric($in['markup'])) $ins->execute([$userId, 'markup', (float) $in['markup']]);
        foreach ((array) ($in['materials'] ?? []) as $id => $price) {
            if (is_string($id) && preg_match('/^[a-z]+:[a-z0-9._-]+$/', $id) && is_numeric($price)) $ins->execute([$userId, 'material:' . $id, (float) $price]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return ratesGet($userId);
}
