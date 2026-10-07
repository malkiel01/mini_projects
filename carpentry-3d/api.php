<?php
/**
 * API של נגרות בתלת מימד.
 *
 * כל בקשה היא POST עם JSON (ה-action בגוף או ב-query), פרט ל-GET של `me`
 * ושל `view` (צפיית לקוח לפי אסימון). החלוקה לפי action ולא לפי נתיב —
 * כך שכל הקובץ נקרא במבט אחד.
 *
 * קבוצות: כניסה וחשבונות, משתמשים (מנהל), סוגי מוצרים, ספריית חומרים,
 * תעריפים, פרויקטים, וצפיית לקוח.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/library.php';
require_once __DIR__ . '/lib/projects.php';
require_once __DIR__ . '/lib/clients.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

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

function s(array $in, string $key, int $max = 200): string {
    $v = $in[$key] ?? '';
    return is_string($v) ? mb_substr(trim($v), 0, $max) : '';
}

$in     = body();
$action = $_GET['action'] ?? (is_string($in['action'] ?? null) ? $in['action'] : '');

try {
    switch ($action) {
        // ---- כניסה וחשבונות ----
        case 'me': {
            $u = currentUser();
            ok(['user' => $u ? publicUser($u) : null, 'setup' => userCount() === 0]);
        }
        case 'setup':
            ok(['user' => setupAdmin(s($in, 'email'), (string) ($in['password'] ?? ''), s($in, 'name', 60))]);
        case 'login':
            ok(['user' => login(s($in, 'email'), (string) ($in['password'] ?? ''))]);
        case 'logout':
            logout();
            ok();
        case 'change-password': {
            $u = requireUser();
            changePassword($u, (string) ($in['current'] ?? ''), (string) ($in['new'] ?? ''));
            ok();
        }

        // ---- משתמשים (מנהל) ----
        case 'users-list':
            requireAdmin();
            ok(['users' => listUsers()]);
        case 'user-create':
            requireAdmin();
            ok(['user' => createUser(s($in, 'email'), (string) ($in['password'] ?? ''), s($in, 'name', 60), s($in, 'role', 20) ?: 'carpenter')]);
        case 'user-update': {
            $admin = requireAdmin();
            ok(['user' => adminUpdateUser($admin, (int) ($in['id'] ?? 0), is_array($in['patch'] ?? null) ? $in['patch'] : [])]);
        }

        // ---- סוגי מוצרים ----
        case 'types-list': {
            $u = requireUser();
            ok(['types' => typesList($u['role'] === 'admin')]);
        }
        case 'type-save':
            ok(['type' => typeSave(requireAdmin(), $in)]);
        case 'type-delete':
            requireAdmin();
            typeDelete((int) ($in['id'] ?? 0));
            ok();

        // ---- ספריית חומרים ----
        case 'materials-get':
            requireUser();
            ok(materialsGet());
        case 'materials-save':
            requireAdmin();
            materialsSave(is_array($in['diff'] ?? null) ? $in['diff'] : []);
            ok(materialsGet());
        case 'material-image':
            requireAdmin();
            ok(['image' => materialImageSave(s($in, 'id', 80), (string) ($in['image'] ?? ''), (int) ($in['imageMm'] ?? 600))]);
        case 'material-image-delete':
            requireAdmin();
            materialImageDelete(s($in, 'id', 80));
            ok();

        // ---- תעריפים ----
        case 'rates-get': {
            $u = requireEditor();
            ok(['rates' => ratesGet((int) $u['id'])]);
        }
        case 'rates-save': {
            $u = requireEditor();
            ok(['rates' => ratesSave((int) $u['id'], is_array($in['rates'] ?? null) ? $in['rates'] : [])]);
        }

        // ---- פרויקטים ----
        case 'projects-list':
            ok(['projects' => projectsList(requireUser(), isset($in['client_id']) ? (int) $in['client_id'] : null)]);
        case 'project-get':
            ok(['project' => projectRow(projectGet(requireUser(), (int) ($in['id'] ?? 0)), true)]);
        case 'project-save':
            ok(['project' => projectSave(requireEditor(), $in)]);
        case 'project-delete':
            projectDelete(requireEditor(), (int) ($in['id'] ?? 0));
            ok();
        case 'project-duplicate':
            ok(['project' => projectDuplicate(requireEditor(), (int) ($in['id'] ?? 0))]);
        case 'project-share':
            ok(['project' => projectShare(requireEditor(), (int) ($in['id'] ?? 0), (bool) ($in['on'] ?? true))]);

        // ---- לקוחות ----
        case 'clients-list':
            requireUser();
            ok(['clients' => clientsList()]);
        case 'client-save':
            ok(['client' => clientSave(requireEditor(), $in)]);
        case 'client-delete':
            requireEditor();
            clientDelete((int) ($in['id'] ?? 0));
            ok();

        // ---- הרכבות: כמה אלמנטים של לקוח יחד ----
        case 'assemblies-list':
            ok(['assemblies' => assembliesList(requireUser(), isset($in['client_id']) ? (int) $in['client_id'] : null)]);
        case 'assembly-get': {
            $u = requireUser();
            ok(['assembly' => assemblyFull($u, assemblyGet($u, (int) ($in['id'] ?? 0)))]);
        }
        case 'assembly-save':
            ok(['assembly' => assemblySave(requireEditor(), $in)]);
        case 'assembly-delete':
            assemblyDelete(requireEditor(), (int) ($in['id'] ?? 0));
            ok();
        case 'assembly-share':
            ok(['assembly' => assemblyShare(requireEditor(), (int) ($in['id'] ?? 0), (bool) ($in['on'] ?? true))]);
        case 'view-assembly': {
            $a = assemblyByToken((string) ($_GET['t'] ?? $in['t'] ?? ''));
            $lib = materialsGet();
            foreach ($lib['diff'] as &$m) unset($m['price'], $m['priceUnit']);
            ok(['assembly' => $a, 'materials' => $lib]);
        }

        // ---- צפיית לקוח: בלי כניסה, לפי אסימון. מחזיר גם את הספרייה (בלי מחירים) ----
        case 'view': {
            $p = projectByToken((string) ($_GET['t'] ?? $in['t'] ?? ''));
            $lib = materialsGet();
            foreach ($lib['diff'] as &$m) unset($m['price'], $m['priceUnit']);
            ok(['project' => $p, 'materials' => $lib]);
        }

        default:
            fail('פעולה לא מוכרת: ' . $action, 404);
    }
} catch (AppError $e) {
    fail($e->getMessage(), $e->status);
} catch (Throwable $e) {
    error_log('carpentry-3d api: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    fail('שגיאת שרת', 500);
}
