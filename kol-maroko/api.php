<?php
/**
 * נקודת הכניסה לשרת. כל פעולה משנה — POST עם JSON, X-Kol ו-X-CSRF.
 *
 * ⚠️ כל פעולה ב-PUBLIC_ACTIONS חייבת להופיע גם ב-"public" של app.json — אחרת השער חוסם אותה.
 */

declare(strict_types=1);

require_once __DIR__ . '/lib/members.php';
require_once __DIR__ . '/lib/import.php';
require_once __DIR__ . '/lib/ai.php';
require_once __DIR__ . '/lib/agent.php';
require_once __DIR__ . '/lib/library.php';
require_once __DIR__ . '/lib/dev.php';
require_once __DIR__ . '/lib/preview.php';
require_once __DIR__ . '/lib/migrate.php';
require_once __DIR__ . '/lib/build.php';
require_once __DIR__ . '/lib/fields_bind.php';          // N2: שינוי מפתח — הקשירות בקבצי הפרויקט
require_once __DIR__ . '/lib/forms.php';
require_once __DIR__ . '/lib/fonts.php';          // V9: גופנים — העלאה, Google Fonts, "הבא לפרויקט"

devlog_begin('api');        // מזהה בקשה, אזהרות PHP, ושורת הבקשה ביומן (lib/devlog.php)

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
require_once __DIR__ . '/lib/csp.php';
csp_api_headers();          // C1: תשובת API לעולם אינה מסמך — default-src 'none'; frame-ancestors 'none'; sandbox

/** 🔁 המעבר (M3) — נטען רק בפעולות שלו, כדי שלא להוסיף זמן לכל בקשה אחרת. */
function cutover_lib(): void
{
    require_once __DIR__ . '/lib/cutover.php';
}

/** ⬇️ הורדת התוסף (T1) — נטען רק בפעולה שלו. */
function plugin_lib(): void
{
    require_once __DIR__ . '/lib/plugin_zip.php';
}

function json_out(array $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/** מי אני: המשתמש, התפקיד, האם מפתח, והגרסה. כניסה נרשמת ביומן הפעולות. */
function me_state(array $user): array
{
    if ($user['role']) audit_login($user);
    $removed = !$user['role'] && (user_get((int)$user['id'])['status'] ?? '') === 'removed';
    $me = array_diff_key($user, ['csrf' => 1]) + ['developer' => is_developer($user), 'removed' => $removed];
    return ['user' => $me, 'csrf' => csrf_token($user), 'roles' => ROLES, 'rank' => ROLE_RANK, 'version' => dev_app_version()];
}

$action = (string)($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// embed_key — המפתח הציבורי לעורך התמלילים (שלב 4). אין בו סוד.
// media — קובץ בכתובת חתומה לזמן מוגבל (התצוגה המבודדת בסטודיו לא שולחת עוגייה). בלי חתימה — 403.
// platform_key — המפתח הציבורי של provision / grant_status (P1, sso.md §6). אין בו סוד.
const PUBLIC_ACTIONS = ['embed_key', 'media', 'platform_key'];
const POST_ACTIONS = ['person_invite', 'person_role', 'person_remove', 'person_retry', 'request_dismiss', 'notices_read', 'owner_set', 'publish_decide', 'lock', 'draft_save', 'render', 'revision_restore',
                      'upload_init', 'upload_chunk', 'upload_done', 'file_delete', 'disk_cap', 'editor_session', 'longtext_sync',
                      'publish', 'republish', 'version_send', 'key_rotate', 'key_activate', 'site_set', 'site_ping', 'import_run',
                      'ai_suggest', 'secret_set', 'ai_quota_set', 'word_save', 'word_delete', 'site_switch', 'topic_op', 'words_csv_import', 'audio_import', 'word_move',
                      'field_save', 'field_delete', 'topic_save', 'topic_merge', 'field_key_apply',
                      'list_create', 'list_save', 'list_delete', 'list_key_apply', 'list_item_save', 'list_item_delete', 'list_item_move', 'list_csv_import', 'list_place', 'list_place_undo',
                      'code_create', 'code_save', 'code_reset', 'agent_token_create', 'agent_token_revoke',
                      'snapshot_create', 'snapshot_restore', 'trash_restore', 'trash_purge',
                      'pf_create', 'pf_write', 'pf_mkdir', 'pf_link', 'pf_rename', 'pf_delete', 'pf_restore', 'pf_purge', 'pf_limits_set', 'pf_migrate', 'pf_blank',
                      'pf_copy', 'pf_version_restore', 'pf_replace', 'pf_zip_import',
                      'rec_duration', 'rec_scan_start', 'rec_scan_step', 'rec_fetch_start', 'rec_fetch_step', 'rec_job_op', 'rec_settings_set',
                      'rec_replace', 'rec_revert', 'rec_rename', 'rec_connect', 'rec_move', 'rec_disconnect', 'rec_delete', 'rec_restore', 'rec_purge',
                      'rec_versions_clean', 'rec_match', 'rec_add', 'rec_dedupe', 'rec_zip_start', 'rec_zip_step',
                      'build_start', 'build_page', 'build_fail', 'build_finish', 'build_send', 'kd_update', 'notice_read', 'mount_set', 'outbox_resend', 'site_wake', 'pull_line', 'pub_channel_set',
                      'inbox_mark', 'inbox_delete', 'inbox_purge', 'mail_test',
                      'ai_suggest_files', 'ai_apply_files', 'longtext_link', 'longtext_open',   // X1
                      'cutover_evidence', 'cutover_evidence_revoke', 'cutover_snapshot', 'cutover_ready',
                      'font_upload', 'font_google_add', 'font_fetch',   // V9
                      'kit_copy', 'kit_undo', 'kit_update', 'kit_revert'];   // V16

try {
    if (in_array($action, POST_ACTIONS, true) && $method !== 'POST') throw new ApiError('נדרש POST', 405);
    // upload_chunk ו-dev-attach — גוף גולמי (חלק מקובץ / צילום), לא JSON
    $body = $method === 'POST' && !in_array($action, ['upload_chunk', 'pf_zip_import'], true) && !in_array($action, DEV_RAW_BODY, true) ? (json_decode((string)file_get_contents('php://input'), true) ?: []) : [];
    if (!is_array($body)) $body = [];

    // devapi-… — Claude, באסימון (X-Dev-Token) ובלי משתמש מהשער
    $user = in_array($action, PUBLIC_ACTIONS, true) || in_array($action, DEV_TOKEN_ACTIONS, true) ? null : current_user();
    devlog_user($user);
    if ($user && $method === 'POST') check_csrf($user);
    // P1: ההרשאה — רק לפי ACTION_ROLES (lib/auth.php). $u — המשתמש אחרי הבדיקה (null בפעולה ציבורית)
    $u = authorize($action, $user);
    // בדיקת המטריצה (tests/cases/21-roles.php): רק ההרשאה, בלי להריץ את הפעולה. הדגל נקבע רק בנתב של שרת הבדיקות
    if (!empty($GLOBALS['kol_test_authorize_only'])) json_out(['authorized' => $action]);
    if ($user && $user['role']) snapshot_daily();                 // נקודת שחזור יומית — בבקשה הראשונה של היום

    $result = dev_route($action, $method, $user, $body) ?? match ($action) {
        'embed_key' => wp()->publicKeys(),
        'csp-reports' => ($u ? csp_reports($_GET) : []),     // C1: הפרות ה-CSP, מקובצות עם מונה
        'me' => $user
            ? me_state($user)
            : throw new ApiError('נדרשת כניסה דרך הכלים הדיגיטליים', 401),
        'platform_key' => platform_key_public(),
        // P1 — 👥 אנשים (lib/members.php), ⚙️ בעל האתר והפעמון (lib/build.php)
        'people' => people_state($u),
        'person_invite' => person_invite($u, $body),
        'person_role' => person_role($u, $body),
        'person_remove' => person_remove($u, $body),
        'person_retry' => person_retry($u, $body),
        'request_dismiss' => request_dismiss($u, $body),
        'owner_settings' => owner_settings($u),
        'owner_set' => owner_set($u, $body),
        'publish_decide' => publish_decide($u, $body),
        'notices_read' => notices_read_all($u),
        'draft' => draft_get($u),
        'draft_save' => draft_save($u, $body),
        'lock' => lock_op($u, $body),
        'render' => ($u ? render_preview($body) : []),
        'revisions' => ($u ? revisions_list() : []),
        'revision_restore' => revision_restore($u, $body),
        'files' => ($u ? files_list() : []),
        'upload_init' => upload_init($u, $body),
        'upload_chunk' => upload_chunk($u, $_GET),
        'upload_done' => upload_done($u, $body),
        'file_delete' => file_delete($u, $body),
        'disk_cap' => disk_cap_set($u, $body),
        'file' => file_send(file_row((string)($u ? ($_GET['id'] ?? '') : '')), isset($_GET['download'])),
        'media' => file_send(media_check($_GET)),
        'editor_session' => editor_session($u, $body),
        'longtext_sync' => longtext_sync($u, $body),
        'longtexts' => ($u ? longtexts_list() : []),          // X1: 🔗 חבר למסמך קיים
        'longtext_link' => longtext_link($u, $body),
        'longtext_open' => longtext_open($u, $body),
        'publish_preview' => publish_preview($u),
        'publish' => publish($u, $body),
        'republish' => republish($u, $body),
        'version_send' => version_send($u, (int)($body['n'] ?? 0)),
        'versions' => ($u ? versions_list($_GET) : []),
        'version_preview' => ($u ? version_preview($_GET) : []),
        'publish_settings' => ($u ? publish_settings() : []),
        'key_rotate' => key_rotate($u),
        'key_activate' => key_activate($u),
        'site_set' => site_set($u, $body),
        'site_ping' => site_ping($u),
        'site_switch' => site_switch($u, $body),
        'import_run' => import_run($u, $body),
        'words' => ($u ? words_list() : []),
        'library' => ($u ? library_list() : []),
        // הבאת ההקלטות מהאתר (A2, lib/recordings.php) — בעל האתר בלבד. המשך — לכל עורך (נמדד בהשמעה)
        'rec_duration' => rec_duration($u, $body),
        'rec_status' => rec_status($u),
        'rec_fetch_plan' => rec_fetch_plan($u),
        'rec_scan_start' => rec_scan_start($u),
        'rec_scan_step' => rec_scan_step($u),
        'rec_fetch_start' => rec_fetch_start($u, $body),
        'rec_fetch_step' => rec_fetch_step($u),
        'rec_job_op' => rec_job_op($u, $body),
        'rec_settings_set' => rec_settings_set($u, $body),
        // A3 — הפעולות על הקלטה (lib/rec_actions.php): עורך ומעלה; מחיקה סופית וניקוי גרסאות — בעל העמוד
        'rec_replace' => rec_replace($u, $body),
        'rec_versions' => ($u ? rec_versions($_GET) : []),
        'rec_revert' => rec_revert($u, $body),
        'rec_rename' => rec_rename($u, $body),
        'rec_connect' => rec_connect($u, $body),
        'rec_move' => rec_move($u, $body),          // N2b: אותה מילה, שדה אחר
        'rec_disconnect' => rec_disconnect($u, $body),
        'rec_delete' => rec_delete($u, $body),
        'rec_restore' => rec_restore($u, $body),
        'rec_purge' => rec_purge($u, $body),
        'rec_versions_clean' => rec_versions_clean($u, $body),
        'rec_match' => ($u ? rec_match($body) : []),
        'rec_add' => rec_add($u, $body),
        'rec_dedupe' => rec_dedupe($u, $body),
        'rec_zip_start' => rec_zip_start($u, $body),
        'rec_zip_step' => rec_zip_step($u, $body),
        'rec_zip_get' => rec_zip_get($u, $_GET),
        'word_save' => word_save($u, $body),
        'word_delete' => word_delete($u, $body),
        'topic_op' => topic_op($u, $body),
        'words_csv' => ($u ? words_csv() : []),
        'words_csv_import' => words_csv_import($u, $body),
        'word_move' => word_move($u, $body),          // N1: גרירה / "העבר ל…" בטבלה
        // N2: "מבנה המילה" — עורך (08 §3); "רוקן נכשלים" ומחיקה עם ערכים — מפרסם, בפונקציה. N3: עם list=<key> — המבנה של אותה רשימה
        'fields' => ($u = $u) && ($lk = list_param($_GET)) ? list_fields_state($u, $lk) : fields_state($u),
        'field_save' => ($u = $u) && ($lk = list_param($body)) ? list_field_save($u, $lk, $body) : field_save($u, $body),
        'field_delete' => ($u = $u) && ($lk = list_param($body)) ? list_field_delete($u, $lk, $body) : field_delete($u, $body),
        'topics' => topics_state($u),                // N2: מסך הנושאים
        'topic_save' => topic_save($u, $body),
        'topic_merge' => topic_merge($u, $body),
        'field_key_preview' => ($u = $u) && ($lk = list_param($_GET + $body)) ? list_field_key($u, $lk, $_GET + $body, false) : field_key_preview($u, $_GET + $body),     // kind=field (מפתח שדה) / topic (slug)
        'field_key_apply' => ($u = $u) && ($lk = list_param($body)) ? list_field_key($u, $lk, $body, true) : field_key_apply($u, $body),
        // N3: רשימות נוספות (lib/lists.php). יצירה / שינוי / מחיקה / שדות — עורך; מחיקת רשימה עם פריטים ו"החלף הכול" — מפרסם (בפונקציה)
        'lists' => lists_state($u),
        'list_create' => list_create($u, $body),
        'list_save' => list_save($u, $body),
        'list_delete' => list_delete($u, $body),
        'list_key_preview' => list_key_preview($u, $_GET + $body),
        'list_key_apply' => list_key_apply($u, $body),
        'list_items' => ($u ? list_items_state(list_param($_GET) ?? throw new ApiError('בחרו רשימה')) : []),
        'list_item_save' => list_item_save($u, $body),
        'list_item_delete' => list_item_delete($u, $body),
        'list_item_move' => list_item_move($u, $body),
        'list_csv' => ($u ? list_csv(list_param($_GET) ?? throw new ApiError('בחרו רשימה')) : []),
        'list_csv_import' => ($u = $u) ? list_csv_import($u, list_param($body) ?? throw new ApiError('בחרו רשימה'), $body) : [],
        'list_place' => list_place($u, $body),
        'list_place_undo' => list_place_undo($u, $body),     // N3b: Ctrl+Z / "↶ בטל" אחרי "➕ הוסף לעמוד"
        'audio_import' => audio_import($u, $body),
        'snapshots' => ($u ? snapshots_list($_GET) : []),
        'snapshot_create' => snapshot_manual($u, $body),
        'snapshot_restore' => snapshot_restore($u, $body),
        'snapshot_preview' => ($u ? snapshot_preview($_GET) : []),
        'trash' => ($u ? trash_list() : []),
        'trash_restore' => trash_restore($u, $body),
        'trash_purge' => trash_purge($u, $body),
        'backup' => backup_download($u),
        'code' => ($u ? code_state() : []),
        'code_create' => code_create($u),
        'code_save' => code_save($u, $body),
        'code_reset' => code_reset($u),
        'code_history' => ($u ? code_history($_GET) : []),
        'code_history_get' => ($u ? code_history_get($_GET) : []),
        'agent_tokens' => agent_tokens($u),
        'agent_token_create' => agent_token_create($u, $body),
        'agent_token_revoke' => agent_token_revoke($u, $body),
        // מאגר הפרויקט (F2a, lib/project.php). pf_raw — הבייטים עצמם, תמיד בארגז חול
        'pf_tree' => pf_tree($u),
        'pf_read' => ($u ? pf_read($_GET) : []),
        'pf_versions' => ($u ? pf_versions($_GET) : []),
        'pf_raw' => ($u ? pf_raw($_GET) : []),
        'pf_create' => pf_create($u, $body),
        // V9: גופנים (lib/fonts.php)
        'font_upload' => font_upload($u, $body),
        'font_google_list' => font_google_list(),
        'font_google_add' => font_google_add($u, $body),
        'font_fetch' => font_fetch($u, $body),
        'pf_write' => pf_write($u, $body),
        'pf_mkdir' => pf_mkdir($u, $body),
        'pf_link' => pf_link($u, $body),
        'pf_rename' => pf_rename($u, $body),
        'pf_delete' => pf_delete($u, $body),
        'pf_trash' => ($u ? pf_trash() : []),
        'pf_restore' => pf_restore($u, $body),
        'pf_purge' => pf_purge($u, $body),
        // F2b (lib/project_ops.php). pf_zip_import — גוף גולמי (ה-ZIP עצמו)
        'pf_copy' => pf_copy($u, $body),
        'pf_version' => ($u ? pf_version($_GET) : []),
        'pf_version_restore' => pf_version_restore($u, $body),
        'pf_search' => ($u ? pf_search($_GET) : []),
        'pf_replace' => pf_replace($u, $body),
        'pf_refs' => ($u ? pf_refs($_GET) : []),
        // V16: ספריית הרכיבים המוכנים (lib/project_ops.php)
        'kit_list' => ($u ? kit_list() : []),
        'kit_get' => ($u ? kit_get($_GET) : []),
        'kit_copy' => kit_copy($u, $body),
        'kit_undo' => kit_undo($u, $body),
        'kit_update' => kit_update($u, $body),
        'kit_revert' => kit_revert($u, $body),
        'pf_zip' => pf_zip($u),
        'pf_zip_import' => pf_zip_import($u, $_GET, (string)file_get_contents('php://input')),
        // ההסבה (F3, lib/migrate.php) — בעלים בלבד
        'pf_migrate' => pf_migrate($u, $body),
        'pf_migrate_last' => ($u ? pf_migrate_last() : []),
        'pf_blank' => pf_blank($u),
        // התצוגה החיה (F4, lib/preview.php): אסימון חתום וקצר-מועד לכתובת המבודדת — רק למי שמורשה לקרוא את הפרויקט
        'pv_token' => pv_token($u),
        // בנייה ופרסום של פרויקט הקבצים (M1, lib/build.php): בנייה — עורך ומעלה; שליחה — מפרסם, רק גרסה שבנה בעצמו
        'builds' => builds_list($u),
        'build_get' => build_get($u, $_GET),
        'build_file' => ($u ? build_file($_GET) : []),
        'build_start' => build_start($u, $body),
        'build_page' => build_page($u, $body),
        'build_fail' => build_fail($u, $body),
        'build_finish' => build_finish($u, $body),
        'build_send' => build_send($u, $body),
        'kd_seed' => ($u ? build_kd_seed() : []),
        'kd_update' => build_kd_update($u, $body),
        // אופן ההצבה באתר (M4-BC): עמוד מלא / חלון בתוך האתר / פס האתר — בעלים בלבד, פעולה חתומה לתוסף
        'mount' => ($u ? mount_state() : []),
        'mount_set' => mount_set($u, $body),
        // M7 — התור היוצא, הדופק, שורת החיבור והערוץ (lib/outbox.php)
        'outbox' => outbox_list($u),
        'outbox_resend' => outbox_resend($u, $body),
        'site_wake' => site_wake($u),
        'pull_line' => pull_line($u),
        'pub_channel_set' => pub_channel_set($u, $body),
        'plugin_zip' => plugin_lib() ?? plugin_zip($u),          // T1: תוסף הוורדפרס כ-ZIP — בעל האתר
        'notices' => notices_list($u),
        'notice_read' => notice_read($u, $body),
        // 📬 פניות (M5, lib/forms.php): קריאה, סימון וייצוא — עורך ומעלה; מחיקה ו"מחק ישנות" — מפרסם; מגבלות ומייל — המפתח
        'inbox' => inbox_list($u, $_GET),
        'inbox_mark' => inbox_mark($u, $body),
        'inbox_delete' => inbox_delete($u, $body),
        'inbox_purge' => inbox_purge($u, $body),
        'inbox_csv' => inbox_csv($u, $_GET),
        'forms_settings' => ($method === 'POST' ? forms_settings($u, $body, is_developer($user)) : forms_settings($u, [], false)),
        'mail_test' => forms_mail_test($u, $body),
        // 🔁 המעבר (M3, lib/cutover.php): קריאה — מפרסם ומעלה; ראיה, נקודת שחזור ו"מוכן למעבר" — בעלים בלבד. אינו מדליק דבר באתר
        'cutover' => cutover_lib() ?? cutover_state($u),
        'cutover_evidence' => cutover_lib() ?? cutover_evidence_add($u, $body),
        'cutover_evidence_revoke' => cutover_lib() ?? cutover_evidence_revoke($u, $body),
        'cutover_snapshot' => cutover_lib() ?? cutover_snapshot($u),
        'cutover_ready' => cutover_lib() ?? cutover_ready($u),
        'pf_limits' => ($u ? pf_limits() : []),
        'pf_limits_set' => pf_limits_set($u, $body),
        'import_info' => ($u ? ['imported' => json_decode((string)setting('imported_from', 'null'), true)] : []),
        'ai_status' => ai_status($u),
        'ai_suggest' => ai_suggest($u, $body),
        'ai_suggest_files' => ai_suggest_files($u, $body),          // X1: ✨ על קבצי הפרויקט
        'ai_apply_files' => ai_apply_files($u, $body),
        'ai_admin' => ($u ? ai_admin() : []),
        'secret_set' => secret_set($u, $body),
        'ai_quota_set' => ai_quota_set($u, $body),
        'audit' => audit_list($u, $_GET),
        default => throw new ApiError('פעולה לא מוכרת', 404),
    };
    json_out(['ok' => true] + $result);
} catch (ApiError $e) {
    devlog_exception($e);
    $code = $e->getCode() ?: 400;
    // 5xx: המשתמש רואה את מזהה הבקשה — אותו מזהה ביומן ובכותרת X-Request-Id
    json_out(['ok' => false, 'error' => $e->getMessage() . ($code >= 500 ? ' · מזהה ' . devlog_req() : ''), 'req' => devlog_req()] + $e->extra, $code);
} catch (Throwable $e) {
    devlog_exception($e);
    error_log('kol-maroko [' . devlog_req() . ']: ' . $e->getMessage());
    json_out(['ok' => false, 'error' => 'תקלה בשרת · מזהה ' . devlog_req(), 'req' => devlog_req()], 500);
}
