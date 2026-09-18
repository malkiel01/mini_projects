<?php
/**
 * API של אפליקציית המתכונים.
 *
 * כל בקשה היא POST עם JSON, פרט ל-GET של `me` ו-`tags`. ה-action מגיע
 * בגוף או ב-query.
 *
 * השלב הזה מכיל את החשבונות בלבד: הרשמה, כניסה, אימות ואיפוס. המתכונים
 * עצמם נכנסים בשלב הבא, ולכן הקובץ קצר — ובכוונה לא נבנתה כאן תשתית
 * ל"עוד מאה נקודות קצה" שאף אחד עדיין לא צריך.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';

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
$public = ['register', 'login', 'me', 'request-reset', 'tags'];

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
        ok([
            'mail_sent' => $res['mail_sent'],
            'message'   => $res['mail_sent']
                ? 'נשלח אליך דוא"ל לאישור הכתובת. יש ללחוץ על הקישור שבו כדי להיכנס.'
                : 'החשבון נוצר, אך שליחת הדוא"ל נכשלה. יש לפנות למנהל כדי לאמת את החשבון.',
        ]);
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
