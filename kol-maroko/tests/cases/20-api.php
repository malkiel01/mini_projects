<?php
// תפקידים והרשאות לכל פעולה ב-API, דרך api.php האמיתי.

declare(strict_types=1);

/** כל פעולה ב-API והתפקיד המינימלי שלה. פעולה חדשה ב-api.php בלי שורה כאן — הבדיקה נופלת. */
const API_MIN_ROLE = [
    'members' => 'owner', 'member_add' => 'owner', 'member_set' => 'owner', 'member_remove' => 'owner', 'request_dismiss' => 'owner',
    'draft' => 'editor', 'draft_save' => 'editor', 'lock' => 'editor', 'render' => 'editor', 'revisions' => 'editor', 'revision_restore' => 'editor',
    'files' => 'editor', 'upload_init' => 'editor', 'upload_chunk' => 'editor', 'upload_done' => 'editor', 'file_delete' => 'editor', 'disk_cap' => 'owner',
    'file' => 'editor', 'editor_session' => 'editor', 'longtext_sync' => 'editor',
    'publish_preview' => 'publisher', 'publish' => 'publisher', 'republish' => 'publisher', 'version_send' => 'publisher',
    'versions' => 'editor', 'version_preview' => 'editor', 'publish_settings' => 'owner', 'key_rotate' => 'owner', 'key_activate' => 'owner',
    'site_set' => 'owner', 'site_ping' => 'publisher', 'site_switch' => 'publisher', 'import_run' => 'owner',
    'words' => 'editor', 'word_save' => 'editor', 'word_delete' => 'editor', 'topic_op' => 'editor', 'words_csv' => 'editor', 'words_csv_import' => 'editor', 'word_move' => 'editor',
    'audio_import' => 'owner', 'snapshots' => 'editor', 'snapshot_create' => 'editor', 'snapshot_restore' => 'editor', 'snapshot_preview' => 'editor',
    'trash' => 'editor', 'trash_restore' => 'editor', 'trash_purge' => 'owner', 'backup' => 'owner',
    'code' => 'editor', 'code_create' => 'editor', 'code_save' => 'editor', 'code_reset' => 'editor', 'code_history' => 'editor', 'code_history_get' => 'editor',
    'agent_tokens' => 'editor', 'agent_token_create' => 'editor', 'agent_token_revoke' => 'editor', 'import_info' => 'owner',
    'ai_status' => 'editor', 'ai_suggest' => 'editor', 'ai_admin' => 'owner', 'secret_set' => 'owner', 'ai_quota_set' => 'owner', 'audit' => 'owner',
    'library' => 'editor',
    // פרוסה A2 — הבאת ההקלטות
    'rec_duration' => 'editor', 'rec_status' => 'owner', 'rec_fetch_plan' => 'owner', 'rec_scan_start' => 'owner', 'rec_scan_step' => 'owner',
    'rec_fetch_start' => 'owner', 'rec_fetch_step' => 'owner', 'rec_job_op' => 'owner', 'rec_settings_set' => 'owner',
    // פרוסה F1
    'dev-report' => 'editor', 'dev-attach' => 'editor', 'dev-my-reports' => 'editor', 'dev-items' => 'editor',
    'dev-task-create' => 'owner', 'dev-task-set' => 'owner', 'dev-comment' => 'owner', 'dev-comment-delete' => 'owner', 'dev-verify' => 'owner',
    'dev-file' => 'owner', 'dev-tokens' => 'owner', 'dev-token-create' => 'owner', 'dev-token-revoke' => 'owner', 'dev-item-log' => 'owner',
    // פרוסה F2a — מאגר הפרויקט
    'pf_tree' => 'editor', 'pf_read' => 'editor', 'pf_versions' => 'editor', 'pf_raw' => 'editor', 'pf_create' => 'editor', 'pf_write' => 'editor', 'pf_mkdir' => 'editor', 'pf_link' => 'editor',
    'pv_token' => 'editor',
    'builds' => 'editor', 'build_get' => 'editor', 'build_file' => 'editor', 'build_start' => 'editor', 'build_page' => 'editor', 'build_fail' => 'editor', 'build_finish' => 'editor',
    'build_send' => 'publisher', 'kd_seed' => 'owner', 'kd_update' => 'owner', 'notices' => 'owner', 'notice_read' => 'owner',
    'pf_rename' => 'editor', 'pf_delete' => 'editor', 'pf_trash' => 'editor', 'pf_restore' => 'editor', 'pf_purge' => 'owner', 'pf_migrate' => 'owner', 'pf_migrate_last' => 'owner', 'pf_blank' => 'owner', 'pf_limits' => 'owner', 'pf_limits_set' => 'owner',
    // פרוסה F2b
    'pf_copy' => 'editor', 'pf_version' => 'editor', 'pf_version_restore' => 'editor', 'pf_search' => 'editor', 'pf_replace' => 'editor', 'pf_refs' => 'editor', 'pf_zip' => 'editor', 'pf_zip_import' => 'editor',
    // פרוסה N2 — שדות מותאמים ונושאים
    'fields' => 'editor', 'field_save' => 'publisher', 'field_delete' => 'publisher',
    'dev-log-links' => 'owner', 'dev-task-from-log' => 'owner', 'dev-log' => 'owner', 'dev-audit' => 'owner', 'dev-health' => 'owner', 'dev-spike-fixture' => 'owner', 'dev-state' => 'owner', 'dev-attack-save' => 'owner',
];
const API_GET_DEV = ['dev-items', 'dev-my-reports', 'dev-file', 'dev-tokens', 'dev-item-log', 'dev-log', 'dev-audit', 'dev-health', 'dev-state', 'dev-spike-fixture'];

function api_method(string $action): string
{
    $src = (string)file_get_contents(KOL_ROOT . '/api.php');
    preg_match('/const POST_ACTIONS = \[(.*?)\];/s', $src, $m);
    preg_match_all("/'([a-z_]+)'/", $m[1], $mm);
    if (str_starts_with($action, 'dev')) return in_array($action, API_GET_DEV, true) ? 'GET' : 'POST';
    return in_array($action, $mm[1], true) ? 'POST' : 'GET';
}

test('הרשאות: הטבלה בבדיקה מכסה כל פעולה שב-api.php וב-lib/dev.php', function () {
    preg_match_all("/^        '([a-z_]+)' => /m", (string)file_get_contents(KOL_ROOT . '/api.php'), $m);
    $known = array_merge(array_keys(API_MIN_ROLE), ['embed_key', 'media', 'me'], DEV_TOKEN_ACTIONS, ['dev-client-error']);
    eq([], array_values(array_diff($m[1], $known)), 'פעולות ב-api.php בלי שורה בטבלה');
    eq([], array_values(array_diff(DEV_ACTIONS, $known)), 'פעולות dev בלי שורה בטבלה');
    ok(count($m[1]) > 50, 'הביטוי מצא את הפעולות');
});

test('הרשאות: בלי זהות — 401 לכל פעולה; לא-חבר — 403 לכל פעולה, והוא נרשם ב"ביקשו גישה"', function () {
    foreach (array_keys(API_MIN_ROLE) as $a) {
        $m = api_method($a);
        eq(401, http($m, "/api.php?action=$a", $m === 'POST' ? ['json' => []] : [])['status'], "בלי זהות · $a");
        eq(403, http($m, "/api.php?action=$a", ['user' => U_STRANGER] + ($m === 'POST' ? ['json' => []] : []))['status'], "לא-חבר · $a");
    }
    eq(1, (int)db()->query("SELECT COUNT(*) FROM access_requests WHERE sub = 'u-stranger'")->fetchColumn());
    $me = http('GET', '/api.php?action=me', ['user' => U_STRANGER]);
    eq(null, $me['json']['user']['role'], 'לזר אין תפקיד');
});

test('הרשאות: עורך לא מפרסם ולא מנהל; מפרסם לא מנהל; כל תפקיד נחסם מעל הדרגה שלו', function () {
    $rank = ['editor' => 1, 'publisher' => 2, 'owner' => 3];
    foreach ([['editor', U_EDITOR], ['publisher', U_PUBLISHER]] as [$role, $u]) {
        foreach (API_MIN_ROLE as $a => $min) {
            if ($rank[$min] <= $rank[$role]) continue;
            $m = api_method($a);
            $r = http($m, "/api.php?action=$a", ['user' => $u] + ($m === 'POST' ? ['json' => []] : []));
            eq(403, $r['status'], "$role · $a");
        }
    }
    eq(200, http('GET', '/api.php?action=words', ['user' => U_EDITOR])['status'], 'עורך קורא מילים');
    eq(200, http('GET', '/api.php?action=publish_preview', ['user' => U_PUBLISHER])['status'], 'מפרסם רואה תצוגת פרסום');
    eq(200, http('GET', '/api.php?action=members', ['user' => U_OWNER])['status'], 'הבעלים רואה חברים');
});

test('הרשאות: פעולה משנה בלי X-CSRF נדחית, וב-GET נדחית', function () {
    eq(403, http('POST', '/api.php?action=word_save', ['user' => U_EDITOR, 'csrf' => false, 'json' => ['word' => 'x', 'meaning' => 'y']])['status']);
    eq(405, http('GET', '/api.php?action=word_save', ['user' => U_EDITOR])['status']);
    eq(405, http('GET', '/api.php?action=dev-report', ['user' => U_EDITOR])['status']);
});

test('me: מחזיר האם המשתמש מפתח (רק הבעלים), והכניסה נרשמת ביומן הפעולות פעם אחת', function () {
    $o = http('GET', '/api.php?action=me', ['user' => U_OWNER])['json'];
    eq(true, $o['user']['developer']);
    ok($o['version'] !== '', 'גרסת האפליקציה');
    eq(false, http('GET', '/api.php?action=me', ['user' => U_EDITOR])['json']['user']['developer']);
    http('GET', '/api.php?action=me', ['user' => U_OWNER]);
    eq(1, (int)db()->query("SELECT COUNT(*) FROM audit WHERE sub = 'u-owner' AND action = 'login'")->fetchColumn());
});
