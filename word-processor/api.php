<?php
/**
 * ה-API של עורך התמלילים. שלוש קבוצות:
 *  1. שרת-לשרת, חתום (platform/docs/embed.md, פרק 3): client_register, embed_session, doc_*.
 *  2. ה-iframe המוטמע, בסשן Bearer (פרק 4): embed_open/load/save/upload/lock/page. וגם media (כתובת חתומה).
 *  3. האפליקציה עצמה, למשתמשי האפליקציה (השער / כניסה עם הכלים הדיגיטליים): me, my_*, admin_*.
 *  4. זר עם קישור עריכה (lib/links.php): guest_open/lock/save/upload — מסמך אחד, לפי הטוקן שבקישור.
 * קבוצות 1–2 ו-4 ב-"public" של app.json (פרק 7) — ההגנה שלהן היא החתימה, הטוקן והסשן; קבוצה 3 עוברת בשער.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/embed.php';
require_once __DIR__ . '/lib/export.php';
require_once __DIR__ . '/lib/import.php';
require_once __DIR__ . '/lib/pdf.php';
require_once __DIR__ . '/lib/compare.php';
require_once __DIR__ . '/lib/users.php';
require_once __DIR__ . '/lib/share.php';
require_once __DIR__ . '/lib/links.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

$action = (string)($_GET['action'] ?? '');
$raw = (string)file_get_contents('php://input');
$in = str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'json') ? (json_decode($raw, true) ?: []) : $_POST;

function out(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function need_post(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new ApiError('נדרש POST', 'method', 405);
}

function request_headers(): array
{
    $h = [];
    foreach ($_SERVER as $k => $v) if (str_starts_with($k, 'HTTP_')) $h[str_replace('_', '-', substr($k, 5))] = $v;
    return $h;
}

function send_file(string $bytes, string $type, string $name): never
{
    header('Content-Type: ' . $type);
    header('Content-Length: ' . strlen($bytes));
    header("Content-Disposition: attachment; filename=\"document\"; filename*=UTF-8''" . rawurlencode($name));
    header('Cache-Control: no-store');
    echo $bytes;
    exit;
}

/** export לפי פורמט. $absolute — כתובות תמונות מלאות (למארחת). */
function do_export(array $row, string $format, $version, bool $absolute, array $track = []): array
{
    if ($version !== null && $version !== '') {
        $v = version_by_number($row, (int)$version);
        $json = json_decode($v['json'], true);
        $page = page_normalize(json_decode($v['page'], true) ?: []);
        $title = $v['title'];
    } else {
        $json = doc_json($row);
        $page = doc_page($row);
        $title = $row['title'];
    }
    switch ($format) {
        case 'html': return export_html($json, $page, $absolute);
        case 'text': return ['text' => export_text($json)];
        case 'ooxml': return export_ooxml($json, $page);
        case 'json': return ['json' => $json, 'page' => $page];
        case 'docx':
            send_file(export_docx($json, $page, $title), 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', ($title ?: 'מסמך') . '.docx');
        case 'pdf':
            send_file(export_pdf($json, $page, $title, $track), 'application/pdf', ($title ?: 'מסמך') . '.pdf');
        default: throw new ApiError('פורמט לא נתמך: html, ooxml, docx, pdf, text, json', 'bad_format');
    }
}

/** ייבוא לפורמט → ['json', 'page']. docx — base64 בגוף (שרת-לשרת) או קובץ שהועלה. */
function do_import(string $format, $content, string $docId): array
{
    if (!is_string($content)) throw new ApiError('content חסר', 'bad_content');
    return match ($format) {
        'lines' => import_lines($content),
        'html' => import_html($content, $docId),
        'docx' => import_docx(base64_decode($content, true) ?: $content, $docId),
        default => throw new ApiError('פורמט ייבוא: lines, docx, html', 'bad_format'),
    };
}

function doc_public(array $row, bool $withJson = true): array
{
    $o = ['doc' => $row['id'], 'title' => $row['title'], 'version' => (int)$row['version'], 'updated_at' => (int)$row['updated_at'],
        'updated_by' => $row['updated_by'], 'page' => doc_page($row)];
    if ($withJson) $o['json'] = editor_json(doc_json($row), $o['page']);   // לעורך: הגדרות העמוד בתוך המסמך
    return $o;
}

/** העלאה: קובץ (multipart, שדה file) או data URL בגוף JSON. */
function uploaded_bytes(array $in): string
{
    if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) return (string)file_get_contents($_FILES['file']['tmp_name']);
    if (is_string($in['data'] ?? null) && preg_match('/^data:[\w\/+.-]+;base64,(.+)$/s', $in['data'], $m)) return (string)base64_decode($m[1]);
    throw new ApiError('לא התקבל קובץ', 'bad_file');
}

try {
    switch ($action) {
        // ================= 1. שרת-לשרת =================
        case 'client_register':
            need_post();
            out(client_register($in));

        case 'embed_session':
        case 'doc_create':
        case 'doc_copy':
        case 'doc_get':
        case 'doc_export':
        case 'doc_import':
        case 'doc_delete':
        case 'doc_list':
            need_post();
            $client = verify_signed($action, $raw, request_headers());
            $space = 'c:' . $client['id'];
            $who = ['id' => 'client:' . $client['id'], 'name' => $client['name'] ?: $client['id']];
            switch ($action) {
                case 'embed_session':
                    out(embed_session($client, $in));
                case 'doc_create':
                    $id = doc_new($space, (string)($in['title'] ?? ''), null, $in['page'] ?? null, $who['id'], $who['name']);
                    if (is_array($in['import'] ?? null)) {
                        $imp = do_import((string)($in['import']['format'] ?? ''), $in['import']['content'] ?? null, $id);
                        $row = doc_find($id, $space);
                        if ($imp['page']) doc_set_page($row, $imp['page']);
                        doc_save(doc_find($id, $space), $imp['json'], null, $who['id'], $who['name']);
                    }
                    out(['doc' => $id]);
                case 'doc_copy':
                    $from = doc_find($in['from'] ?? '', $space);
                    out(['doc' => doc_copy($from, $space, clean_title($in['title'] ?? ''), is_array($in['replace'] ?? null) ? $in['replace'] : [], $who['id'], $who['name'])]);
                case 'doc_get':
                    $row = doc_find($in['doc'] ?? '', $space);
                    $o = doc_public($row, false);
                    if ($l = lock_public(lock_get($row['id']))) $o['lock'] = $l;
                    out($o);
                case 'doc_export':
                    $row = doc_find($in['doc'] ?? '', $space);
                    out(do_export($row, (string)($in['format'] ?? ''), $in['version'] ?? null, true));
                case 'doc_import':
                    $row = doc_find($in['doc'] ?? '', $space);
                    $imp = do_import((string)($in['format'] ?? ''), $in['content'] ?? null, $row['id']);
                    $row = doc_save($row, $imp['json'], null, $who['id'], $who['name'], null, $imp['page']);
                    out(['doc' => $row['id'], 'version' => (int)$row['version']]);
                case 'doc_delete':
                    doc_delete(doc_find($in['doc'] ?? '', $space));
                    out(['ok' => true]);
                case 'doc_list':
                    $after = is_string($in['after'] ?? null) ? $in['after'] : '';
                    $st = db()->prepare('SELECT id, title, version, updated_at FROM docs WHERE space = ? AND id > ? AND deleted_at IS NULL ORDER BY id LIMIT 200');
                    $st->execute([$space, $after]);
                    $docs = array_map(fn($r) => ['doc' => $r['id'], 'title' => $r['title'], 'version' => (int)$r['version'], 'updated_at' => (int)$r['updated_at']], $st->fetchAll());
                    $o = ['docs' => $docs];
                    if (count($docs) === 200) $o['next'] = end($docs)['doc'];
                    out($o);
            }
            break;

        // ================= 2. ה-iframe =================
        case 'embed_open':
            need_post();
            $s = token_consume((string)($in['t'] ?? ''));
            $row = doc_find($s['doc'], 'c:' . $s['client']);
            out(['session' => $s['session'], 'mode' => $s['mode'], 'return_origin' => $s['return_origin'],
                'client' => $s['client'], 'user' => ['id' => $s['user_id'], 'name' => $s['user_name']]] + embed_state($s, $row, $s['session']));

        case 'embed_load':
            need_post();
            $s = embed_auth();
            $row = doc_find($s['doc'], 'c:' . $s['client']);
            $op = (string)($in['op'] ?? '');
            if ($op === 'versions') out(['versions' => versions_list($row)]);
            if ($op === 'version') {
                $v = version_get($row, $in['id'] ?? 0);
                $vp = page_normalize(json_decode($v['page'], true) ?: []);
                out(['json' => editor_json(json_decode($v['json'], true) ?: empty_doc_json(), $vp), 'page' => $vp, 'title' => $v['title'], 'version' => (int)$v['version']]);
            }
            out(embed_state($s, $row, $s['token']));

        case 'embed_save':
            need_post();
            $s = embed_auth();
            $row = doc_find($s['doc'], 'c:' . $s['client']);
            require_edit($s['can'], $row['id'], $s['user_id'], $s['user_name'], $s['token']);
            $row = doc_save($row, $in['json'] ?? null, isset($in['title']) ? (string)$in['title'] : null, $s['user_id'], $s['user_name'], $in['base_version'] ?? null);
            out(['doc' => $row['id'], 'version' => (int)$row['version'], 'updated_at' => (int)$row['updated_at']]);

        case 'embed_version_restore':   // שחזור גרסה — פעולה אחת בשרת (doc_restore_version)
            need_post();
            $s = embed_auth();
            $row = doc_find($s['doc'], 'c:' . $s['client']);
            require_edit($s['can'], $row['id'], $s['user_id'], $s['user_name'], $s['token']);
            $row = doc_restore_version($row, $in['id'] ?? 0, $s['user_id'], $s['user_name'], $in['base_version'] ?? null);
            out(['doc' => $row['id'], 'version' => (int)$row['version'], 'json' => editor_json(doc_json($row), doc_page($row)), 'page' => doc_page($row)]);

        case 'embed_page':
            need_post();
            $s = embed_auth();
            $row = doc_find($s['doc'], 'c:' . $s['client']);
            require_edit($s['can'], $row['id'], $s['user_id'], $s['user_name'], $s['token']);
            out(['page' => doc_page(doc_set_page($row, $in['page'] ?? null))]);

        case 'embed_upload':
            need_post();
            $s = embed_auth();
            $row = doc_find($s['doc'], 'c:' . $s['client']);
            require_edit($s['can'], $row['id'], $s['user_id'], $s['user_name'], $s['token']);
            out(media_store($row['id'], uploaded_bytes($in)));

        case 'embed_lock':
            need_post();
            $s = embed_auth();
            $row = doc_find($s['doc'], 'c:' . $s['client']);
            out(lock_op((string)($in['op'] ?? 'beat'), $s['can'], $row['id'], $s['user_id'], $s['user_name'], $s['token']));

        case 'media':
            $id = (string)($_GET['id'] ?? '');
            $m = preg_match('/^[a-z0-9]{24}$/', $id) && hash_equals(media_sig($id), (string)($_GET['s'] ?? '')) ? media_row($id) : null;
            if (!$m || !is_file($f = media_path($m['id'], $m['type']))) { http_response_code(404); exit; }
            header('Content-Type: ' . $m['type']);
            header('Content-Length: ' . filesize($f));
            header('Cache-Control: public, max-age=31536000, immutable');
            header('Access-Control-Allow-Origin: *');
            header('Cross-Origin-Resource-Policy: cross-origin');
            readfile($f);
            exit;

        // המפתח הציבורי של העורך עצמו — העורך רשום כלקוח של עצמו, לבדיקה החיה (selftest)
        case 'embed_key':
            $k = own_keypair();
            out(['client' => APP_ID, 'keys' => [['kid' => $k['kid'], 'alg' => 'Ed25519', 'key' => base64_encode($k['public'])]]]);

        case 'selftest':
            require_once __DIR__ . '/lib/selftest.php';
            out(selftest());

        // ================= 4. זר עם קישור עריכה =================
        case 'guest_open':
        case 'guest_lock':
        case 'guest_save':
        case 'guest_upload':
            guest_action($action, $in);

        // ================= 3. האפליקציה =================
        case 'sso_start':
            sso_start();
        case 'sso_callback':
            sso_callback();
        case 'logout':
            clear_app_session();
            out(['ok' => true]);

        default:
            app_action($action, $in);
    }
} catch (ApiError $e) {
    out(['error' => $e->getMessage(), 'code' => $e->codeName], $e->status);
} catch (Throwable $e) {
    error_log('word-processor: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    out(['error' => 'שגיאה בשרת', 'code' => 'server'], 500);
}

/** מצב המסמך ל-iframe: התוכן, ההרשאה בפועל (view אם מישהו אחר נועל), והנעילה. */
function embed_state(array $s, array $row, string $holder): array
{
    $can = $s['can'];
    $lock = null;
    if ($can === 'edit') {
        $r = lock_take($row['id'], $s['user_id'], $s['user_name'], hash('sha256', $holder));
        if (!$r['ok']) { $can = 'view'; $lock = ['by' => $r['by'], 'since' => $r['since']]; }
    }
    return doc_public($row) + ['can' => $can, 'lock' => $lock, 'requested' => $s['can']];
}

function require_edit(string $can, string $doc, string $uid, string $uname, string $holder): void
{
    if ($can !== 'edit') throw new ApiError('צפייה בלבד', 'readonly', 403);
    $r = lock_take($doc, $uid, $uname, hash('sha256', $holder));
    if (!$r['ok']) throw new ApiError('המסמך פתוח לעריכה אצל ' . $r['by'], 'locked', 423);
}

/** דופק / לקיחה / שחרור. מחזיר {ok, can, lock}. */
function lock_op(string $op, string $can, string $doc, string $uid, string $uname, string $holder): array
{
    if ($can !== 'edit') return ['ok' => false, 'can' => 'view', 'lock' => lock_public(lock_get($doc))];
    if ($op === 'release') { lock_release($doc, $uid); return ['ok' => true, 'can' => 'edit', 'lock' => null]; }
    $r = lock_take($doc, $uid, $uname, hash('sha256', $holder));
    return $r['ok'] ? ['ok' => true, 'can' => 'edit', 'lock' => null] : ['ok' => false, 'can' => 'view', 'lock' => ['by' => $r['by'], 'since' => $r['since']]];
}

/**
 * הזר (lib/links.php): כל בקשה נושאת את הטוקן (k), את המזהה מהמכשיר (gid) ואת השם — ונבדקת מחדש: קישור שבוטל
 * או פג מפסיק לעבוד מיד, גם באמצע עריכה. עורך ושומר כמו עורך רגיל, בלי שינוי שם המסמך.
 */
function guest_action(string $action, array $in): never
{
    need_post();
    if (($_SERVER['HTTP_X_WP'] ?? '') !== '1') throw new ApiError('בקשה לא תקינה', 'csrf', 403);
    // JSON, או טופס (העלאת תמונה) — שם $in הוא $_POST
    $k = $in['k'] ?? '';
    $row = guest_doc($k);
    [$uid, $uname] = guest_identity($k, $in['gid'] ?? '', $in['name'] ?? '');
    $holder = 'guest:' . $in['gid'];
    switch ($action) {
        case 'guest_open':
        case 'guest_lock':
            $l = lock_op((string)($in['op'] ?? 'beat'), 'edit', $row['id'], $uid, $uname, $holder);
            if ($action === 'guest_lock') out($l);
            out(doc_public($row) + ['can' => $l['can'], 'lock' => $l['lock'], 'user' => ['id' => $uid, 'name' => $uname]]);
        case 'guest_save':
            require_edit('edit', $row['id'], $uid, $uname, $holder);
            $row = doc_save($row, $in['json'] ?? null, null, $uid, $uname, $in['base_version'] ?? null);
            out(['doc' => $row['id'], 'version' => (int)$row['version'], 'updated_at' => (int)$row['updated_at']]);
        case 'guest_upload':
            require_edit('edit', $row['id'], $uid, $uname, $holder);
            out(media_store($row['id'], uploaded_bytes($in)));
    }
    throw new ApiError('פעולה לא מוכרת', 'unknown_action', 404);
}

function app_action(string $action, array $in): never
{
    $acc = current_account();
    if ($action === 'me') {
        out(['user' => $acc ? ['id' => (int)$acc['id'], 'name' => $acc['name'], 'email' => $acc['email'], 'admin' => $acc['role'] === 'admin'] : null,
            'login' => $acc ? null : 'api.php?action=sso_start']);
    }
    if (!$acc) throw new ApiError('נדרשת כניסה', 'login', 401);
    $space = 'a:' . $acc['id'];
    $uid = 'a:' . $acc['id'];
    $uname = $acc['name'] ?: ($acc['email'] ?? 'משתמש');
    $holder = 'app:' . ($_SERVER['HTTP_X_TAB'] ?? '');
    $isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
    if (!$isPost && !in_array($action, ['my_export'], true)) throw new ApiError('נדרש POST', 'method', 405);
    if ($isPost && ($_SERVER['HTTP_X_WP'] ?? '') !== '1') throw new ApiError('בקשה לא תקינה', 'csrf', 403);   // אתר זר לא יכול לשלוח כותרת כזו

    switch ($action) {
        case 'my_docs':
            trash_purge($space);
            $st = db()->prepare('SELECT id, title, version, updated_at, updated_by FROM docs WHERE space = ? AND deleted_at IS NULL ORDER BY updated_at DESC LIMIT 500');
            $st->execute([$space]);
            // ושותפו איתי (שלב 5) — ברשימה נפרדת, עם התפקיד והבעלים
            out(['docs' => array_map(fn($r) => ['doc' => $r['id'], 'title' => $r['title'], 'version' => (int)$r['version'],
                'updated_at' => (int)$r['updated_at'], 'updated_by' => $r['updated_by']], $st->fetchAll()), 'shared' => shared_with($acc)]);
        case 'my_new':
            $id = doc_new($space, (string)($in['title'] ?? ''), null, $in['page'] ?? null, $uid, $uname);
            if (!empty($_FILES['file']['tmp_name']) || is_string($in['import'] ?? null)) {
                $bytes = !empty($_FILES['file']['tmp_name']) ? uploaded_bytes($in) : base64_decode((string)$in['import']);
                // Word, ODT, HTML או טקסט — לפי התוכן (import_file)
                $imp = import_file($bytes, (string)($_FILES['file']['name'] ?? ''), $id);
                $row = doc_find($id, $space);
                if ($imp['page']) $row = doc_set_page($row, $imp['page']);
                doc_save($row, $imp['json'], null, $uid, $uname);
                out(['doc' => $id, 'report' => $imp['report']]);
            }
            out(['doc' => $id]);
        case 'my_open':
        case 'my_lock':
            // צפייה — בלי נעילה; מעיר ועורך — לוקחים את הנעילה (מעיר כותב הערות למסמך)
            [$row, $role] = doc_access($in['doc'] ?? '', $acc);
            $op = (string)($in['op'] ?? 'beat');
            $l = lock_op($op, $role === 'view' ? 'view' : 'edit', $row['id'], $uid, $uname, $holder);
            if ($l['can'] === 'edit' && $role === 'comment') $l['can'] = 'comment';
            if ($action === 'my_lock') out($l);
            out(doc_public($row) + ['can' => $l['can'], 'lock' => $l['lock'], 'role' => $role,
                'shares' => $role === 'owner' ? count(shares_of($row['id'])) : null]);
        case 'my_save':
            [$row, $role] = doc_access($in['doc'] ?? '', $acc, 'comment');
            require_edit('edit', $row['id'], $uid, $uname, $holder);
            if ($role === 'comment') {
                // מעיר: רק הערות — והגדרות העמוד שבתוך המסמך לא נשמרות ממנו
                if (is_array($in['json'] ?? null)) unset($in['json']['attrs']['page']);
                if (isset($in['title']) || !is_array($in['json'] ?? null) || !only_comments_changed(doc_json($row), $in['json'])) {
                    throw new ApiError('ההרשאה שלך במסמך: הערות בלבד — אפשר להוסיף הערות, לא לשנות את הטקסט', 'forbidden', 403);
                }
            }
            $row = doc_save($row, $in['json'] ?? null, isset($in['title']) ? (string)$in['title'] : null, $uid, $uname, $in['base_version'] ?? null);
            out(['doc' => $row['id'], 'version' => (int)$row['version'], 'updated_at' => (int)$row['updated_at']]);
        case 'my_version_restore':   // שחזור גרסה — פעולה אחת בשרת (doc_restore_version)
            [$row] = doc_access($in['doc'] ?? '', $acc, 'edit');
            require_edit('edit', $row['id'], $uid, $uname, $holder);
            $row = doc_restore_version($row, $in['id'] ?? 0, $uid, $uname, $in['base_version'] ?? null);
            out(['doc' => $row['id'], 'version' => (int)$row['version'], 'json' => editor_json(doc_json($row), doc_page($row)), 'page' => doc_page($row)]);
        case 'my_page':
            [$row] = doc_access($in['doc'] ?? '', $acc, 'edit');
            require_edit('edit', $row['id'], $uid, $uname, $holder);
            out(['page' => doc_page(doc_set_page($row, $in['page'] ?? null))]);
        case 'my_rename':
            doc_rename(doc_access($in['doc'] ?? '', $acc, 'edit')[0], (string)($in['title'] ?? ''));
            out(['ok' => true]);
        case 'my_delete':
            // מחיקה מהאפליקציה — לסל המחזור (ליקוי ה' באפיון). מחיקה לצמיתות — רק מתוך הסל (my_purge)
            doc_trash(doc_access($in['doc'] ?? '', $acc, 'owner')[0]);
            out(['ok' => true, 'trash' => true, 'days' => TRASH_DAYS]);
        case 'my_trash':
            trash_purge($space);
            $st = db()->prepare('SELECT id, title, updated_at, deleted_at FROM docs WHERE space = ? AND deleted_at IS NOT NULL ORDER BY deleted_at DESC LIMIT 500');
            $st->execute([$space]);
            out(['days' => TRASH_DAYS, 'docs' => array_map(fn($r) => ['doc' => $r['id'], 'title' => $r['title'], 'updated_at' => (int)$r['updated_at'],
                'deleted_at' => (int)$r['deleted_at'], 'purge_at' => (int)$r['deleted_at'] + TRASH_DAYS * 86400], $st->fetchAll())]);
        case 'my_restore':
            doc_restore(doc_find($in['doc'] ?? '', $space, true));
            out(['ok' => true]);
        case 'my_purge':
            doc_delete(doc_find($in['doc'] ?? '', $space, true));
            out(['ok' => true]);
        case 'my_copy':
            // העתק — אצלי (גם ממסמך ששותף איתי לצפייה)
            [$from] = doc_access($in['doc'] ?? '', $acc);
            out(['doc' => doc_copy($from, $space, clean_title(($in['title'] ?? '') ?: $from['title'] . ' (העתק)'), [], $uid, $uname)]);
        case 'my_compare':
            // השוואת גרסאות (כמו ב-Word): מסמך חדש — הגרסה מול המצב הנוכחי, ההבדלים כמעקב שינויים
            [$row] = doc_access($in['doc'] ?? '', $acc);
            $v = version_get($row, $in['id'] ?? 0);
            $cmp = track_compare(json_decode($v['json'], true) ?: empty_doc_json(), doc_json($row));
            $title = clean_title(mb_substr('השוואה: ' . ($row['title'] ?: 'מסמך') . ' — גרסה ' . (int)$v['version'] . ' מול הנוכחית', 0, 200));
            out(['doc' => doc_copy($row, $space, $title, [], $uid, $uname, $cmp)]);
        case 'my_upload':
            [$row] = doc_access($in['doc'] ?? ($_POST['doc'] ?? ''), $acc, 'edit');
            out(media_store($row['id'], uploaded_bytes($in)));
        case 'my_versions':
            out(['versions' => versions_list(doc_access($in['doc'] ?? '', $acc)[0])]);
        case 'my_version':
            $v = version_get(doc_access($in['doc'] ?? '', $acc)[0], $in['id'] ?? 0);
            $vp = page_normalize(json_decode($v['page'], true) ?: []);
            out(['json' => editor_json(json_decode($v['json'], true) ?: empty_doc_json(), $vp), 'page' => $vp, 'title' => $v['title'], 'version' => (int)$v['version']]);
        case 'my_export':
            [$row] = doc_access($_GET['doc'] ?? '', $acc);
            $fmt = (string)($_GET['format'] ?? 'docx');
            if ($fmt === 'text') send_file(export_text(doc_json($row)), 'text/plain; charset=utf-8', ($row['title'] ?: 'מסמך') . '.txt');
            if ($fmt === 'json') send_file(json_encode(['title' => $row['title'], 'json' => doc_json($row), 'page' => doc_page($row)], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), 'application/json', ($row['title'] ?: 'מסמך') . '.json');
            // PDF בתצוגת השינויים שבעורך: track=final|original, hide=מחברים (JSON) — כמו שההדפסה מהעורך נראית
            $hide = json_decode((string)($_GET['hide'] ?? '[]'), true);
            do_export($row, $fmt === 'pdf' ? 'pdf' : 'docx', null, true, ['view' => (string)($_GET['track'] ?? 'all'), 'hide' => is_array($hide) ? array_slice($hide, 0, 50) : []]);
        // שיתוף (שלב 5): רק הבעלים רואה ומשנה
        case 'my_shares':
            [$row] = doc_access($in['doc'] ?? '', $acc, 'owner');
            out(['shares' => shares_of($row['id']), 'link' => link_public(link_row($row['id']))]);
        case 'my_share':
            [$row] = doc_access($in['doc'] ?? '', $acc, 'owner');
            share_set($row, $acc, $in['email'] ?? '', $in['role'] ?? '');
            out(['shares' => shares_of($row['id'])]);
        // קישור עריכה לזר (lib/links.php): op = request (חדש / חידוש) | revoke
        case 'my_link':
            [$row] = doc_access($in['doc'] ?? '', $acc, 'owner');
            $op = (string)($in['op'] ?? '');
            if ($op === 'request') out(['link' => link_request($row, $acc)]);
            if ($op === 'revoke') { link_revoke($row['id']); out(['link' => null]); }
            throw new ApiError('פעולה לא תקינה', 'bad_op');
        case 'admin_links':
            need_admin($acc);
            out(['links' => links_all()]);
        case 'admin_link_set':
            need_admin($acc);
            link_decide((string)($in['doc'] ?? ''), !empty($in['approve']), $acc);
            out(['links' => links_all()]);
        case 'admin_clients':
            need_admin($acc);
            $rows = db()->query('SELECT id, name, key_url, origins, status, created_at, updated_at FROM clients ORDER BY created_at')->fetchAll();
            out(['clients' => array_map(function ($r) {
                $st = db()->prepare('SELECT COUNT(*) FROM docs WHERE space = ?');
                $st->execute(['c:' . $r['id']]);
                return ['id' => $r['id'], 'name' => $r['name'], 'key_url' => $r['key_url'], 'origins' => json_decode($r['origins'], true),
                    'status' => $r['status'], 'docs' => (int)$st->fetchColumn(), 'updated_at' => (int)$r['updated_at']];
            }, $rows)]);
        case 'admin_client_set':
            need_admin($acc);
            $c = client_get((string)($in['client'] ?? ''));
            if (!$c) throw new ApiError('הלקוח לא נמצא', 'not_found', 404);
            $status = $in['status'] ?? '';
            if (!in_array($status, ['active', 'pending', 'disabled'], true)) throw new ApiError('סטטוס לא תקין', 'bad_status');
            db()->prepare('UPDATE clients SET status = ?, updated_at = ? WHERE id = ?')->execute([$status, time(), $c['id']]);
            wp_log('client_status', "{$c['id']} → $status (על ידי {$acc['id']})");
            out(['ok' => true]);
    }
    throw new ApiError('פעולה לא מוכרת', 'unknown_action', 404);
}

function need_admin(array $acc): void
{
    if ($acc['role'] !== 'admin') throw new ApiError('רק מנהל העורך', 'forbidden', 403);
}
