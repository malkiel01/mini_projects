<?php
/**
 * שיתוף והרשאות (שלב 5, החלטה 4): מסמך שייך לחשבון אחד (docs.space = 'a:<id>' — הבעלים), ואפשר לשתף אותו עם
 * מייל אחר בתפקיד: view (צפייה), comment (צפייה והערות), edit (עריכה). השיתוף לפי מייל — פעיל גם לפני שבעל
 * המייל נכנס לעורך בפעם הראשונה (החשבון שלו נוצר עם אותו מייל — users.php, account_for_identity).
 * רק הבעלים משתף, משנה שם, מוחק ומשחזר מהסל; עורך — שומר, מעלה תמונות ומשחזר גרסה; מעיר — שומר רק הערות.
 */

const SHARE_ROLES = ['view' => 1, 'comment' => 2, 'edit' => 3, 'owner' => 4];
const SHARE_LABELS = ['view' => 'צפייה', 'comment' => 'הערות', 'edit' => 'עריכה', 'owner' => 'בעלים'];
const SHARE_MAX = 50;   // שיתופים למסמך

/** התפקיד של החשבון במסמך: owner / edit / comment / view, או null — אין גישה. */
function share_role(array $row, array $acc): ?string
{
    if ($row['space'] === 'a:' . $acc['id']) return 'owner';
    if (!str_starts_with($row['space'], 'a:') || empty($acc['email'])) return null;
    $st = db()->prepare('SELECT role FROM shares WHERE doc = ? AND email = ? COLLATE NOCASE');
    $st->execute([$row['id'], $acc['email']]);
    $r = $st->fetchColumn();
    $st->closeCursor();
    return isset(SHARE_ROLES[$r]) && $r !== 'owner' ? $r : null;
}

/**
 * המסמך והתפקיד — רק כשהתפקיד לפחות $min. מסמך שאין אליו גישה — "לא נמצא" (לא מגלים שהוא קיים);
 * יש גישה אבל לא מספיקה — 403 עם הסבר.
 */
function doc_access($id, array $acc, string $min = 'view', bool $trashed = false): array
{
    if (!is_string($id) || !preg_match('/^[a-z0-9]{6,40}$/', $id)) throw new ApiError('המסמך לא נמצא', 'not_found', 404);
    $st = db()->prepare('SELECT * FROM docs WHERE id = ? AND deleted_at IS ' . ($trashed ? 'NOT NULL' : 'NULL'));
    $st->execute([$id]);
    $row = $st->fetch();
    $st->closeCursor();
    $role = $row ? share_role($row, $acc) : null;
    // בסל — רק הבעלים רואה
    if (!$row || !$role || ($trashed && $role !== 'owner')) throw new ApiError('המסמך לא נמצא', 'not_found', 404);
    if (SHARE_ROLES[$role] < SHARE_ROLES[$min]) {
        throw new ApiError($min === 'owner' ? 'רק בעל המסמך יכול לעשות את זה' : 'ההרשאה שלך במסמך: ' . SHARE_LABELS[$role], 'forbidden', 403);
    }
    return [$row, $role];
}

/** השיתופים של מסמך: [{email, role, name, joined}] — joined: כבר יש חשבון עם המייל. */
function shares_of(string $doc): array
{
    $st = db()->prepare('SELECT s.email, s.role, a.name, a.id AS acc FROM shares s LEFT JOIN accounts a ON a.email = s.email COLLATE NOCASE
        WHERE s.doc = ? ORDER BY s.created_at');
    $st->execute([$doc]);
    return array_map(fn($r) => ['email' => $r['email'], 'role' => $r['role'], 'name' => (string)($r['name'] ?? ''), 'joined' => $r['acc'] !== null], $st->fetchAll());
}

/** שיתוף / שינוי תפקיד / ביטול ('' — ביטול). רק הבעלים (doc_access עם 'owner' לפני הקריאה). */
function share_set(array $row, array $owner, $email, $role): void
{
    $email = strtolower(trim((string)$email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 200) throw new ApiError('כתובת מייל לא תקינה', 'bad_email');
    if ($email === strtolower((string)($owner['email'] ?? ''))) throw new ApiError('זה המייל שלך — המסמך כבר שלך', 'bad_email');
    if ($role === '' || $role === null) {
        db()->prepare('DELETE FROM shares WHERE doc = ? AND email = ? COLLATE NOCASE')->execute([$row['id'], $email]);
        wp_log('share_remove', "{$row['id']} ✕ $email");
        return;
    }
    if (!in_array($role, ['view', 'comment', 'edit'], true)) throw new ApiError('תפקיד לא תקין', 'bad_role');
    $st = db()->prepare('SELECT COUNT(*) FROM shares WHERE doc = ? AND email != ? COLLATE NOCASE');
    $st->execute([$row['id'], $email]);
    $n = (int)$st->fetchColumn();
    $st->closeCursor();   // לפני הכתיבה — אחרת היא נכשלת מיד אם בקשה אחרת כתבה בינתיים (db.php)
    if ($n >= SHARE_MAX) throw new ApiError('אפשר לשתף מסמך עם עד ' . SHARE_MAX . ' אנשים', 'too_many');
    db()->prepare('INSERT INTO shares (doc, email, role, by_account, created_at) VALUES (?, ?, ?, ?, ?)
        ON CONFLICT (doc, email) DO UPDATE SET role = excluded.role')->execute([$row['id'], $email, $role, (int)$owner['id'], time()]);
    wp_log('share_set', "{$row['id']} → $email ($role)");
}

/** המסמכים ששותפו עם החשבון (לא בסל): [{doc, title, version, updated_at, updated_by, role, owner}]. */
function shared_with(array $acc): array
{
    if (empty($acc['email'])) return [];
    $st = db()->prepare("SELECT d.id, d.title, d.version, d.updated_at, d.updated_by, s.role, o.name AS owner, o.email AS owner_email
        FROM shares s JOIN docs d ON d.id = s.doc AND d.deleted_at IS NULL LEFT JOIN accounts o ON 'a:' || o.id = d.space
        WHERE s.email = ? COLLATE NOCASE AND d.space != ? ORDER BY d.updated_at DESC LIMIT 500");
    $st->execute([$acc['email'], 'a:' . $acc['id']]);
    return array_map(fn($r) => ['doc' => $r['id'], 'title' => $r['title'], 'version' => (int)$r['version'], 'updated_at' => (int)$r['updated_at'],
        'updated_by' => $r['updated_by'], 'role' => $r['role'], 'owner' => (string)($r['owner'] ?: $r['owner_email'] ?: '')], $st->fetchAll());
}

/**
 * מעיר (comment) שומר רק הערות: המסמך החדש, בלי ההערות (סימון comment ו-attrs.comments), זהה לישן בלי ההערות.
 * ההשוואה אחרי נרמול — בלי ערכי ברירת מחדל (null / false / 0 / ריק), כי העורך כותב אותם והשרת לא תמיד.
 */
function only_comments_changed(array $old, array $new): bool
{
    return json_encode(share_norm($old)) === json_encode(share_norm($new));
}

function share_norm($v, ?string $key = null)
{
    if (!is_array($v)) return $v;
    $list = array_is_list($v);
    $out = [];
    foreach ($v as $k => $x) {
        if (!$list && in_array($k, ['comments', 'schema'], true) && $key === 'attrs') continue;   // השרשורים, וגרסת המבנה (העורך מעדכן)
        // מזהה פסקה (חבילת היצירה) — השרת והעורך משלימים אותו; לא שינוי של המעיר
        if (!$list && $k === 'attrs' && is_array($x) && in_array($v['type'] ?? null, ['paragraph', 'heading'], true)) unset($x['id']);
        if ($key === 'marks' && is_array($x) && ($x['type'] ?? '') === 'comment') continue;
        $x = share_norm($x, is_string($k) ? $k : $key);
        if (!$list && ($x === null || $x === false || $x === 0 || $x === '' || $x === [])) continue;
        $out[$k] = $x;
    }
    if ($list) {
        // הערה מפצלת טקסט לכמה צמתים; בלי הסימון — חוזרים לצומת אחד (טקסט צמוד עם אותם סימונים)
        $merged = [];
        foreach ($out as $x) {
            $last = count($merged) - 1;
            if ($last >= 0 && is_array($x) && ($x['type'] ?? '') === 'text' && ($merged[$last]['type'] ?? '') === 'text'
                && ($x['marks'] ?? null) == ($merged[$last]['marks'] ?? null)) $merged[$last]['text'] .= $x['text'] ?? '';
            else $merged[] = $x;
        }
        return $merged;
    }
    ksort($out);
    return $out;
}
