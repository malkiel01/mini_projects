<?php
/**
 * התפקידים וההרשאות (P1, אפיון 08 §3–§4).
 *
 * מי המשתמש — lib/login.php (המקום היחיד שמכיר את הפלטפורמה). כאן: חמשת התפקידים, והטבלה האחת שמסווגת כל פעולה
 * ב-api.php (ACTION_ROLES). פעולה שאין לה שורה — נדחית (404), ובדיקת המטריצה (tests/cases/21-roles.php) נכשלת.
 *
 *   content_editor 1 — מילים, הקלטות, פניות: כתיבה. עיצוב, קוד, קבצי הפרויקט, פרסום — קריאה.
 *   editor         2 — כל העריכה של העמוד (קבצים, קוד, טיוטה), בלי פרסום.
 *   publisher      3 — עורך + פרסום לאתר.
 *   page_owner     4 — מפרסם + האנשים (עוזרים, בקשות, הזמנות), היומן, מחיקה סופית.
 *   site_owner     5 — בעל האתר (platform_admin): החיבור לאתר, המפסק, המכסות, המפתחות והסודות, מסך המפתח, DevLoop.
 *
 * CSRF — אסימון של ה-session (lib/login.php). בקשה משנה חייבת גם X-Kol וגם X-CSRF תואם.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/login.php';

const ROLES = ['content_editor' => 'עורך תוכן', 'editor' => 'עורך', 'publisher' => 'מפרסם', 'page_owner' => 'בעל העמוד', 'site_owner' => 'בעל האתר'];
const ROLE_RANK = ['content_editor' => 1, 'editor' => 2, 'publisher' => 3, 'page_owner' => 4, 'site_owner' => 5];
/** מה מוסבר למי שאין לו הרשאה — לפי הדרגה שחסרה */
const ROLE_WHY = [
    'content_editor' => 'אין לך תפקיד בכלי. בעל העמוד יראה שביקשת גישה',
    'editor' => 'התפקיד שלך: עורך תוכן — מילים, הקלטות ופניות. לשינוי עיצוב ופרסום — פנה לבעל העמוד',
    'publisher' => 'פרסום לאתר — רק למפרסם ומעלה. פנה לבעל העמוד',
    'page_owner' => 'רק בעל העמוד',
    'site_owner' => 'רק בעל האתר',
];

/**
 * כל פעולה ב-api.php (וב-dev_route) ← מי רשאי. 'public' — בלי משתמש (חייבת להופיע ב-"public" של app.json);
 * 'any' — כל משתמש מזוהה, גם בלי תפקיד; אחרת — הדרגה המינימלית.
 * ⚠️ פעולה חדשה — שורה כאן. בלעדיה היא נדחית, והבדיקה נכשלת.
 */
const ACTION_ROLES = [
    // ── ציבוריות / כל מי שנכנס
    'embed_key' => 'public', 'media' => 'public', 'platform_key' => 'public',
    'devapi-feed' => 'public', 'devapi-comment' => 'public', 'devapi-file' => 'public', 'devapi-attach' => 'public',
    'me' => 'any', 'dev-client-error' => 'any',
    // ── עורך תוכן: קריאה של כל העמוד; כתיבה — מילים, הקלטות, פריטי רשימות, פניות, הודעות
    'draft' => 'content_editor', 'render' => 'content_editor', 'revisions' => 'content_editor', 'files' => 'content_editor', 'file' => 'content_editor',
    'versions' => 'content_editor', 'version_preview' => 'content_editor', 'words' => 'content_editor', 'library' => 'content_editor',
    'words_csv' => 'content_editor', 'fields' => 'content_editor', 'topics' => 'content_editor', 'field_key_preview' => 'content_editor',
    'lists' => 'content_editor', 'list_key_preview' => 'content_editor', 'list_items' => 'content_editor', 'list_csv' => 'content_editor',
    'snapshots' => 'content_editor', 'snapshot_preview' => 'content_editor', 'trash' => 'content_editor',
    'code' => 'content_editor', 'code_history' => 'content_editor', 'code_history_get' => 'content_editor',
    'font_google_list' => 'content_editor', 'pf_tree' => 'content_editor', 'pf_read' => 'content_editor', 'pf_versions' => 'content_editor', 'pf_raw' => 'content_editor', 'pf_trash' => 'content_editor',
    'pf_version' => 'content_editor', 'pf_search' => 'content_editor', 'pf_refs' => 'content_editor', 'pf_zip' => 'content_editor', 'pv_token' => 'content_editor',
    'builds' => 'content_editor', 'build_get' => 'content_editor', 'build_file' => 'content_editor', 'ai_status' => 'content_editor', 'longtexts' => 'content_editor',
    'word_save' => 'content_editor', 'word_delete' => 'content_editor', 'topic_op' => 'content_editor', 'words_csv_import' => 'content_editor', 'word_move' => 'content_editor',
    'list_item_save' => 'content_editor', 'list_item_delete' => 'content_editor', 'list_item_move' => 'content_editor', 'list_csv_import' => 'content_editor',
    'upload_init' => 'content_editor', 'upload_chunk' => 'content_editor', 'upload_done' => 'content_editor',       // ספריית המדיה (הקלטה חדשה), לא קבצי הפרויקט
    'rec_duration' => 'content_editor', 'rec_replace' => 'content_editor', 'rec_versions' => 'content_editor', 'rec_revert' => 'content_editor',
    'rec_rename' => 'content_editor', 'rec_connect' => 'content_editor', 'rec_move' => 'content_editor', 'rec_disconnect' => 'content_editor',
    'rec_delete' => 'content_editor', 'rec_restore' => 'content_editor', 'rec_match' => 'content_editor', 'rec_add' => 'content_editor',
    'rec_dedupe' => 'content_editor', 'rec_zip_start' => 'content_editor', 'rec_zip_step' => 'content_editor', 'rec_zip_get' => 'content_editor',
    'inbox' => 'content_editor', 'inbox_mark' => 'content_editor', 'inbox_csv' => 'content_editor',
    'notices' => 'content_editor', 'notice_read' => 'content_editor', 'notices_read' => 'content_editor', 'owner_settings' => 'content_editor',
    'dev-report' => 'content_editor', 'dev-attach' => 'content_editor', 'dev-my-reports' => 'content_editor', 'dev-items' => 'content_editor',
    // ── עורך: כל מה שכותב לקבצי העמוד (pf_*, code_*, הטיוטה), המבנה, הבנייה
    'draft_save' => 'editor', 'lock' => 'editor', 'revision_restore' => 'editor', 'file_delete' => 'editor', 'editor_session' => 'editor',
    'longtext_sync' => 'editor', 'longtext_link' => 'editor', 'longtext_open' => 'editor',
    'field_save' => 'editor', 'field_delete' => 'editor', 'topic_save' => 'editor', 'topic_merge' => 'editor', 'field_key_apply' => 'editor',
    'list_create' => 'editor', 'list_save' => 'editor', 'list_delete' => 'editor', 'list_key_apply' => 'editor', 'list_place' => 'editor', 'list_place_undo' => 'editor',
    'snapshot_create' => 'editor', 'snapshot_restore' => 'editor', 'trash_restore' => 'editor',
    'code_create' => 'editor', 'code_save' => 'editor', 'code_reset' => 'editor',
    'agent_tokens' => 'editor', 'agent_token_create' => 'editor', 'agent_token_revoke' => 'editor',
    'font_upload' => 'editor', 'font_google_add' => 'editor', 'font_fetch' => 'editor',      // V9 — כמו pf_create (אושר בידי הבקרה)
    'pf_create' => 'editor', 'pf_write' => 'editor', 'pf_mkdir' => 'editor', 'pf_link' => 'editor', 'pf_rename' => 'editor', 'pf_delete' => 'editor',
    'pf_restore' => 'editor', 'pf_copy' => 'editor', 'pf_version_restore' => 'editor', 'pf_replace' => 'editor', 'pf_zip_import' => 'editor',
    'build_start' => 'editor', 'build_page' => 'editor', 'build_fail' => 'editor', 'build_finish' => 'editor',
    'ai_suggest' => 'editor', 'ai_suggest_files' => 'editor', 'ai_apply_files' => 'editor', 'forms_settings' => 'editor',
    // ── מפרסם
    'publish_preview' => 'publisher', 'publish' => 'publisher', 'republish' => 'publisher', 'version_send' => 'publisher',
    'site_ping' => 'publisher', 'site_switch' => 'publisher', 'build_send' => 'publisher', 'inbox_delete' => 'publisher', 'inbox_purge' => 'publisher',
    'cutover' => 'publisher',
    // ── בעל העמוד: האנשים, היומן, מחיקה סופית, ההסבה והגיבוי
    'people' => 'page_owner', 'person_invite' => 'page_owner', 'person_role' => 'page_owner', 'person_remove' => 'page_owner', 'person_retry' => 'page_owner',
    'request_dismiss' => 'page_owner', 'audit' => 'page_owner', 'backup' => 'page_owner',
    'trash_purge' => 'page_owner', 'rec_purge' => 'page_owner', 'rec_versions_clean' => 'page_owner', 'pf_purge' => 'page_owner',
    'pf_migrate' => 'page_owner', 'pf_migrate_last' => 'page_owner', 'pf_blank' => 'page_owner', 'kd_seed' => 'page_owner', 'kd_update' => 'page_owner',
    // ── בעל האתר: החיבור, המפסק, המכסות, המפתחות, הסודות, מסך המפתח, DevLoop (08 §4)
    'owner_set' => 'site_owner', 'publish_decide' => 'site_owner',
    'disk_cap' => 'site_owner', 'publish_settings' => 'site_owner', 'key_rotate' => 'site_owner', 'key_activate' => 'site_owner', 'site_set' => 'site_owner',
    'import_run' => 'site_owner', 'import_info' => 'site_owner', 'audio_import' => 'site_owner', 'mount' => 'site_owner', 'mount_set' => 'site_owner', 'plugin_zip' => 'site_owner',
    'rec_status' => 'site_owner', 'rec_fetch_plan' => 'site_owner', 'rec_scan_start' => 'site_owner', 'rec_scan_step' => 'site_owner',
    'rec_fetch_start' => 'site_owner', 'rec_fetch_step' => 'site_owner', 'rec_job_op' => 'site_owner', 'rec_settings_set' => 'site_owner',
    'cutover_evidence' => 'site_owner', 'cutover_evidence_revoke' => 'site_owner', 'cutover_snapshot' => 'site_owner', 'cutover_ready' => 'site_owner',
    'pf_limits' => 'site_owner', 'pf_limits_set' => 'site_owner', 'ai_admin' => 'site_owner', 'secret_set' => 'site_owner', 'ai_quota_set' => 'site_owner',
    'mail_test' => 'site_owner', 'csp-reports' => 'site_owner',
    'dev-task-create' => 'site_owner', 'dev-task-set' => 'site_owner', 'dev-comment' => 'site_owner', 'dev-comment-delete' => 'site_owner', 'dev-verify' => 'site_owner',
    'dev-file' => 'site_owner', 'dev-tokens' => 'site_owner', 'dev-token-create' => 'site_owner', 'dev-token-revoke' => 'site_owner', 'dev-item-log' => 'site_owner',
    'dev-log-links' => 'site_owner', 'dev-task-from-log' => 'site_owner', 'dev-log' => 'site_owner', 'dev-audit' => 'site_owner', 'dev-health' => 'site_owner',
    'dev-state' => 'site_owner', 'dev-attack-save' => 'site_owner', 'dev-forms' => 'site_owner', 'dev-spike-fixture' => 'site_owner',
];

function role_at_least(?array $user, string $min): bool
{
    return $user && !empty($user['role']) && isset(ROLE_RANK[$user['role']]) && ROLE_RANK[$user['role']] >= ROLE_RANK[$min];
}

function require_role(?array $user, string $min): array
{
    if (!$user) throw new ApiError('נדרשת כניסה דרך הכלים הדיגיטליים', 401);
    if (empty($user['role']) || !isset(ROLE_RANK[$user['role']])) throw new ApiError(ROLE_WHY['content_editor'], 403);
    if (ROLE_RANK[$user['role']] < ROLE_RANK[$min]) throw new ApiError(ROLE_WHY[$min] ?? 'אין לך הרשאה לפעולה הזו', 403);
    return $user;
}

/** ההרשאה לפעולה — לפי ACTION_ROLES בלבד. מחזיר את המשתמש (null בפעולה ציבורית). */
function authorize(string $action, ?array $user): ?array
{
    $min = ACTION_ROLES[$action] ?? null;
    if ($min === null) throw new ApiError('פעולה לא מוכרת', 404);
    if ($min === 'public') return null;
    if (!$user) throw new ApiError('נדרשת כניסה דרך הכלים הדיגיטליים', 401);
    if ($min === 'any') return $user;
    return require_role($user, $min);
}

/** כתיבה ליומן הפעולות. source: studio · agent · import · ai · system. */
function audit_write(?array $who, string $action, string $detail = '', ?string $source = null): void
{
    $source ??= !empty($who['agent']) ? 'agent' : 'studio';
    db()->prepare('INSERT INTO audit (at, sub, name, action, detail, user_id, source) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([now_iso(), (string)($who['sub'] ?? ''), (string)($who['name'] ?? ''), $action, mb_substr($detail, 0, 500), isset($who['id']) ? (int)$who['id'] : null, $source]);
}

function audit_as(array $who, string $action, string $detail = ''): void
{
    audit_write($who, $action, $detail);
}
