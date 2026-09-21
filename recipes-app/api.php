<?php
/**
 * API של אפליקציית המתכונים.
 *
 * כל בקשה היא POST עם JSON, פרט ל-GET של `me` ו-`tags`. ה-action מגיע
 * בגוף או ב-query.
 *
 * שלוש קבוצות: חשבונות, מתכונים, ואבחון למנהל. החלוקה לפי action ולא
 * לפי נתיב — כך שכל הקובץ נקרא במבט אחד, ואין ניתוב להבין.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/recipes.php';
require_once __DIR__ . '/lib/social.php';
require_once __DIR__ . '/lib/diag.php';
require_once __DIR__ . '/lib/media.php';
require_once __DIR__ . '/lib/settings.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

function fail(string $message, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function ok(array $payload = []): never {
    echo json_encode(['success' => true] + $payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function body(): array {
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function str_field(array $in, string $key, int $max): string {
    $v = $in[$key] ?? '';
    if (!is_string($v)) return '';
    return mb_substr(trim($v), 0, $max);
}

$in     = body();
$action = $_GET['action'] ?? (is_string($in['action'] ?? null) ? $in['action'] : '');

// נקודות קצה שאינן דורשות התחברות — הן כל מה שמוביל אליה.
// נקודות קצה שאינן דורשות התחברות — הן כל מה שמוביל אליה, ובנוסף
// עיון במתכונים ציבוריים: אורח שמגיע מקישור צריך לראות מתכון.
$public = ['register', 'login', 'me', 'request-reset', 'resend-verification', 'tags', 'search', 'recipe'];

$user = currentUser();
if (!in_array($action, $public, true) && !$user) {
    fail('נדרשת התחברות', 401);
}

try {
    switch ($action) {

    case 'me':
        // הדף שואל את זה בטעינה כדי לדעת אם להציג כניסה או את האפליקציה.
        // מחזיר success גם לאורח — "אינך מחובר" אינו שגיאה.
        ok(['user' => $user ? [
            'id'           => (int) $user['id'],
            'username'     => $user['username'],
            'display_name' => $user['display_name'],
            'role'         => $user['role'],
            'is_developer' => isDeveloper($user),
        ] : null]);

    case 'register': {
        $res = createUser(
            str_field($in, 'username', 32),
            str_field($in, 'email', 254),
            (string) ($in['password'] ?? ''),
            str_field($in, 'display_name', 60)
        );
        // האסימון עצמו לעולם אינו חוזר לדפדפן. אם היה חוזר, כל נרשם היה
        // מאמת את עצמו והאימות היה חסר משמעות.
        if ($res['verified']) {
            // המשתמש הראשון — בעל האתר. נכנס מיד; הדוא"ל שנשלח הוא רק
            // בדיקה של mail(), והתוצאה שלה תופיע במסך האבחון.
            $msg = 'החשבון נוצר ואתה המנהל. אפשר להיכנס.'
                 . ($res['mail_sent'] ? '' : ' שים לב: שליחת הדוא"ל נכשלה — ראה במסך האבחון.');
        } else {
            $msg = $res['mail_sent']
                ? 'נשלח אליך דוא"ל לאישור הכתובת. יש ללחוץ על הקישור שבו כדי להיכנס.'
                : 'החשבון נוצר, אך שליחת הדוא"ל נכשלה. יש לפנות למנהל כדי לאמת את החשבון.';
        }
        ok(['mail_sent' => $res['mail_sent'], 'verified' => $res['verified'], 'message' => $msg]);
    }

    case 'login': {
        $res = login(str_field($in, 'username', 254), (string) ($in['password'] ?? ''));
        if (!$res) fail('שם משתמש או סיסמה שגויים', 401);
        ok(['user' => $res]);
    }

    case 'logout':
        logout();
        ok();

    case 'request-reset':
        requestPasswordReset(str_field($in, 'email', 254));
        // אותה תשובה גם לכתובת שאינה רשומה — אחרת הטופס בודק מי רשום כאן.
        ok(['message' => 'אם הכתובת רשומה אצלנו, נשלח אליה קישור לאיפוס.']);

    case 'resend-verification': {
        // אותה תשובה לכל מצב — קיים / לא קיים / כבר מאומת / קירור. הטופס הזה
        // פתוח לאורח, ולכן אסור שיגלה מי רשום.
        resendVerification(str_field($in, 'username', 254));
        ok(['message' => 'אם החשבון קיים וטרם אומת, נשלח אליו דוא"ל חדש. אפשר לבקש שוב בעוד שתי דקות.']);
    }

    case 'tags': {
        // הצירים הסגורים. נדרשים לטופס המתכון, ונפתחים גם לאורח כדי
        // שדף ציבורי יוכל להציג סינון לפני התחברות.
        $rows = db()->query('SELECT id, axis, name FROM tags ORDER BY axis, position')->fetchAll();
        $byAxis = [];
        foreach ($rows as $row) {
            $byAxis[$row['axis']][] = ['id' => (int) $row['id'], 'name' => $row['name']];
        }
        ok(['tags' => $byAxis]);
    }

    // ───────── מתכונים ─────────

    case 'search': {
        // תיבה אחת לשלי ולציבורי יחד, עם סימון לכל תוצאה (סעיף 5).
        ok(['recipes' => searchRecipes($user, str_field($in, 'q', 120), [
            'difficulty'  => $in['difficulty'] ?? null,
            'max_minutes' => $in['max_minutes'] ?? null,
            'tag_ids'     => $in['tag_ids'] ?? null,
        ])]);
    }

    case 'recipe': {
        $id = (int) ($in['id'] ?? $_GET['id'] ?? 0);
        $recipe = $id > 0 ? loadRecipe($id, $user) : null;
        // אותה תשובה למתכון שאינו קיים ולמתכון פרטי של אחר. הפרדה
        // ביניהם הייתה מגלה לזר אילו מזהים קיימים.
        if (!$recipe) fail('המתכון אינו קיים או שאינו זמין לך', 404);
        // התגובות מגיעות עם המתכון — גם לאורח, במתכון ציבורי. הפתק
        // והמועדף הם של המשתמש, ולכן רק כשיש כזה.
        ok([
            'recipe'      => $recipe,
            'comments'    => listComments($id, $user),
            'note'        => $user ? getNote($id, $user) : null,
            'is_favorite' => isFavorite($id, $user),
        ]);
    }

    // ───────── תגובות, פתק פרטי, מועדפים (6–7) ─────────

    case 'comment-add': {
        $rid = (int) ($in['recipe_id'] ?? 0);
        $parent = isset($in['parent_id']) && (int) $in['parent_id'] > 0 ? (int) $in['parent_id'] : null;
        $cid = addComment($rid, $parent, str_field($in, 'text', COMMENT_MAX_CHARS + 1), $user);
        ok(['id' => $cid, 'comments' => listComments($rid, $user)]);
    }

    case 'comment-edit': {
        $c = commentRow((int) ($in['id'] ?? 0));
        editComment((int) $c['id'], str_field($in, 'text', COMMENT_MAX_CHARS + 1), $user);
        ok(['comments' => listComments((int) $c['recipe_id'], $user)]);
    }

    case 'comment-delete': {
        $c = commentRow((int) ($in['id'] ?? 0));
        deleteComment((int) $c['id'], $user);
        ok(['comments' => listComments((int) $c['recipe_id'], $user)]);
    }

    case 'recipe-comments-open': {
        $rid = (int) ($in['recipe_id'] ?? 0);
        setCommentsOpen($rid, (bool) ($in['open'] ?? true), $user);
        ok(['comments_open' => (bool) ($in['open'] ?? true)]);
    }

    case 'note-save': {
        $rid = (int) ($in['recipe_id'] ?? 0);
        ok(['note' => setNote($rid, str_field($in, 'text', NOTE_MAX_CHARS + 1), $user)]);
    }

    case 'favorite-toggle': {
        $rid = (int) ($in['recipe_id'] ?? 0);
        ok(['is_favorite' => toggleFavorite($rid, $user)]);
    }

    case 'favorites':
        ok(['favorites' => listFavorites($user)]);

    case 'favorite-remove': {
        removeFavorite((int) ($in['fav_id'] ?? 0), $user);
        ok(['favorites' => listFavorites($user)]);
    }

    case 'recipe-save': {
        $id = (int) ($in['id'] ?? 0);
        if ($id > 0) requireOwnRecipe($id, $user);
        $saved = saveRecipe($in, $user, $id > 0 ? $id : null);
        ok(['id' => $saved, 'recipe' => loadRecipe($saved, $user)]);
    }

    case 'recipe-delete': {
        $id = (int) ($in['id'] ?? 0);
        if ($id <= 0) fail('חסר מזהה מתכון');
        ok(['deleted_comments' => deleteRecipe($id, $user)]);
    }

    case 'products': {
        // השלמה אוטומטית לשם מוצר. הקטלוג גדל מעצמו, ולכן זו גם הדרך
        // שבה שמות מתכנסים במקום להתפזר (סעיף 3.2).
        $q = normalizeText(str_field($in, 'q', 60));
        $st = db()->prepare('SELECT name FROM products WHERE name_norm LIKE ?
                              ORDER BY length(name) LIMIT 10');
        $st->execute(['%' . $q . '%']);
        ok(['products' => array_column($st->fetchAll(), 'name')]);
    }

    // ───────── מדיה ─────────
    // העלאת קובץ עצמה ב-upload.php (multipart). כאן רק מה שהוא JSON.

    case 'media-link':
        ok(['media' => storeLink((int) ($in['recipe_id'] ?? 0), $user, str_field($in, 'url', 500))]);

    case 'media-delete':
        deleteMedia((int) ($in['id'] ?? 0), $user);
        ok(['limits' => mediaLimits($user)]);

    case 'media-main':
        setMainMedia((int) ($in['recipe_id'] ?? 0), (int) ($in['id'] ?? 0), $user);
        ok();

    case 'media-limits':
        // התקרות בפועל, לפני שהמשתמש בוחר קובץ — כדי שהדפדפן יגיד "עד 8MB"
        // ולא "עד 20MB" כשהשרת חוסם ב-8.
        ok(['limits' => mediaLimits($user)]);

    // ───────── הגדרות ─────────

    case 'settings-public':
        // קריאה פתוחה לכל מחובר — העורך מציג "עד 20MB" ממנה. כתיבה — מפתח בלבד.
        ok(['settings' => allAppSettings(), 'is_developer' => isDeveloper($user)]);

    case 'settings-public-save': {
        $values = is_array($in['values'] ?? null) ? $in['values'] : [];
        foreach ($values as $key => $value) {
            setAppSetting((string) $key, (int) $value, $user);
        }
        ok(['settings' => allAppSettings()]);
    }

    case 'settings-private':
        // ההגדרות הפרטיות של המשתמש עצמו. כרגע: המגבלות שחלות עליו (לקריאה),
        // והמקום שבו ייכנסו הגדרות שהוא קובע לעצמו.
        ok(['limits' => mediaLimits($user), 'display_name' => $user['display_name']]);

    case 'settings-private-save': {
        $name = str_field($in, 'display_name', 60);
        if ($name === '') fail('שם התצוגה לא יכול להיות ריק');
        $st = db()->prepare('UPDATE users SET display_name = ? WHERE id = ?');
        $st->execute([$name, $user['id']]);
        ok();
    }

    // ───────── ניהול משתמשים (מפתח) ─────────

    case 'users':
        ok(['users' => listUsers($user)]);

    case 'user-limit': {
        // value=null מחזיר את המשתמש לברירת המחדל הציבורית
        $value = array_key_exists('value', $in) && $in['value'] !== null && $in['value'] !== ''
                 ? (int) $in['value'] : null;
        setUserLimit((int) ($in['user_id'] ?? 0), str_field($in, 'key', 40), $value, $user);
        ok(['users' => listUsers($user)]);
    }

    case 'user-block':
        setUserBlocked((int) ($in['user_id'] ?? 0), !empty($in['blocked']), $user);
        ok(['users' => listUsers($user)]);

    case 'user-resend': {
        $sent = resendVerificationFor((int) ($in['user_id'] ?? 0), $user);
        ok(['mail_sent' => $sent, 'users' => listUsers($user)]);
    }

    case 'user-verify':
        setUserVerified((int) ($in['user_id'] ?? 0), $user);
        ok(['users' => listUsers($user)]);

    // ───────── אבחון ─────────

    case 'diag':
        requireAdmin();
        ok(['diag' => diagnostics()]);

    default:
        fail('פעולה לא מוכרת: ' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8'), 404);
    }
} catch (AppError $e) {
    fail($e->getMessage(), $e->status);
} catch (Throwable $e) {
    // הפרטים ליומן, לא למשתמש. באתר פתוח לאינטרנט הודעת שגיאה מפורטת
    // היא מקור מידע על המערכת.
    error_log('recipes-app: ' . $e->getMessage());
    fail('שגיאת שרת', 500);
}
