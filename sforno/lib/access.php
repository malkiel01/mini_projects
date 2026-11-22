<?php
/**
 * ההרשאות — מקום אחד (אפיון 01 §6). הנתב קורא ל-sf_access() לפני כל פעולה; המסכים רק מסתירים כפתורים.
 *
 *   public — בלי משתמש (וגם אז הפעולה עצמה מגינה: health מחזיר רק ok ו-version, devapi-* באסימון sfd_)
 *   reader — הקריאה הציבורית (read-*): בלי זיהוי מנהל, ומחזירה רק תוכן ציבורי שפורסם (lib/content.php). **לא** ב-"public"
 *            של app.json: בזמן הפיתוח השער מגביל אותה לצוות, ובהשקה (11) היא נפתחת לכולם
 *   staff  — כל מנהל פעיל (§3: הרשאות מלאות)
 *   dev    — is_developer (= platform_admin שבזהות)
 *
 * 'm' — השיטה היחידה שמותרת. פעולה משנה = POST (וגם CSRF); GET לפעולה משנה → 405.
 * 'raw' — התשובה יוצאת כמו שהיא, בלי עטיפת {"ok": true} (health, DevLoop).
 * 'body' — POST שגופו אינו JSON (קובץ מצורף למטלה): המודול קורא אותו בעצמו.
 * 'max' — גוף JSON גדול מ-1MB (מקור של ספר). ברירת המחדל ב-api.php.
 */

declare(strict_types=1);

const SF_ACTIONS = [
    'health'               => ['m' => 'GET',  'who' => 'public', 'raw' => true],
    'devapi-feed'          => ['m' => 'GET',  'who' => 'public', 'raw' => true],
    'devapi-comment'       => ['m' => 'POST', 'who' => 'public', 'raw' => true],
    'devapi-file'          => ['m' => 'GET',  'who' => 'public', 'raw' => true],
    'devapi-attach'        => ['m' => 'POST', 'who' => 'public', 'raw' => true, 'body' => true],

    'read-titles'          => ['m' => 'GET',  'who' => 'reader'],
    'read-edition'         => ['m' => 'GET',  'who' => 'reader'],
    // הקורא (04) — קריאה בלבד, בלי עוגייה ובלי מידע אישי (lib/reader.php)
    'read-catalog'         => ['m' => 'GET',  'who' => 'reader'],
    'read-toc'             => ['m' => 'GET',  'who' => 'reader'],
    'read-revision'        => ['m' => 'GET',  'who' => 'reader'],
    'read-search'          => ['m' => 'GET',  'who' => 'reader'],
    'read-file'            => ['m' => 'GET',  'who' => 'reader', 'raw' => true],
    'read-pages'           => ['m' => 'GET',  'who' => 'reader', 'raw' => true],

    'me'                   => ['m' => 'GET',  'who' => 'staff'],
    'logout'               => ['m' => 'POST', 'who' => 'staff'],
    'staff-list'           => ['m' => 'GET',  'who' => 'staff'],
    'staff-suspend'        => ['m' => 'POST', 'who' => 'staff'],
    'staff-unsuspend'      => ['m' => 'POST', 'who' => 'staff'],
    'devloop-client-error' => ['m' => 'POST', 'who' => 'staff', 'raw' => true],
    // מנוע התוכן (02) — כל המנהלים
    'content-titles'       => ['m' => 'GET',  'who' => 'staff'],
    'content-title'        => ['m' => 'GET',  'who' => 'staff'],
    'content-title-save'   => ['m' => 'POST', 'who' => 'staff'],
    'content-title-public' => ['m' => 'POST', 'who' => 'staff'],
    'content-edition-save' => ['m' => 'POST', 'who' => 'staff'],
    'content-edition'      => ['m' => 'GET',  'who' => 'staff'],
    'content-revision'     => ['m' => 'GET',  'who' => 'staff'],
    'content-check'        => ['m' => 'POST', 'who' => 'staff', 'max' => 8_000_000],
    'content-save'         => ['m' => 'POST', 'who' => 'staff', 'max' => 8_000_000],
    'content-publish'      => ['m' => 'POST', 'who' => 'staff'],
    'content-restore'      => ['m' => 'POST', 'who' => 'staff'],
    'content-discard'      => ['m' => 'POST', 'who' => 'staff'],
    'content-aliases'      => ['m' => 'GET',  'who' => 'staff'],
    'content-alias-add'    => ['m' => 'POST', 'who' => 'staff'],
    'content-alias-remove' => ['m' => 'POST', 'who' => 'staff'],
    'content-file'         => ['m' => 'GET',  'who' => 'staff', 'raw' => true],
    // ייבוא (03) — כל המנהלים. import-upload: הגוף הוא הקובץ עצמו (לא JSON)
    'import-list'          => ['m' => 'GET',  'who' => 'staff'],
    'import-status'        => ['m' => 'GET',  'who' => 'staff'],
    'import-upload'        => ['m' => 'POST', 'who' => 'staff', 'body' => true],
    'import-run'           => ['m' => 'POST', 'who' => 'staff'],
    'import-retry'         => ['m' => 'POST', 'who' => 'staff'],
    'import-pdf-text'      => ['m' => 'POST', 'who' => 'staff', 'max' => 8_000_000],
    'import-map'           => ['m' => 'POST', 'who' => 'staff'],
    'import-apply'         => ['m' => 'POST', 'who' => 'staff'],
    'import-text-checked'  => ['m' => 'POST', 'who' => 'staff'],
    'import-source'        => ['m' => 'GET',  'who' => 'staff', 'raw' => true],
    // הקורא (04) — הניהול: מנהגים, רשימת הסידור, בדיקת שילובים, עמודים לקוראים, תוכן דוגמה
    'customs-list'         => ['m' => 'GET',  'who' => 'staff'],
    'custom-save'          => ['m' => 'POST', 'who' => 'staff'],
    'siddur-items'         => ['m' => 'GET',  'who' => 'staff'],
    'siddur-item-save'     => ['m' => 'POST', 'who' => 'staff'],
    'siddur-item-move'     => ['m' => 'POST', 'who' => 'staff'],
    'siddur-item-available' => ['m' => 'POST', 'who' => 'staff'],
    'customs-checks'       => ['m' => 'GET',  'who' => 'staff'],
    'customs-check-approve' => ['m' => 'POST', 'who' => 'staff'],
    'content-pages-set'    => ['m' => 'POST', 'who' => 'staff'],
    'content-sample'       => ['m' => 'POST', 'who' => 'staff'],

    'dev-health'           => ['m' => 'GET',  'who' => 'dev'],
    'dev-mail-test'        => ['m' => 'POST', 'who' => 'dev'],
    'dev-audit'            => ['m' => 'GET',  'who' => 'dev'],
    'dev-jobs'             => ['m' => 'GET',  'who' => 'dev'],
    'dev-job-retry'        => ['m' => 'POST', 'who' => 'dev'],
    'dev-job-cancel'       => ['m' => 'POST', 'who' => 'dev'],
    'dev-cron-rotate'      => ['m' => 'POST', 'who' => 'dev'],
    // DevLoop — אותם שמות כמו בכל אפליקציה (.claude/skills/devloop/references/install.md)
    'dev-items'            => ['m' => 'GET',  'who' => 'dev', 'raw' => true],
    'dev-task-create'      => ['m' => 'POST', 'who' => 'dev', 'raw' => true],
    'dev-task-set'         => ['m' => 'POST', 'who' => 'dev', 'raw' => true],
    'dev-comment'          => ['m' => 'POST', 'who' => 'dev', 'raw' => true],
    'dev-comment-delete'   => ['m' => 'POST', 'who' => 'dev', 'raw' => true],
    'dev-verify'           => ['m' => 'POST', 'who' => 'dev', 'raw' => true],
    'dev-report'           => ['m' => 'POST', 'who' => 'dev', 'raw' => true],
    'dev-attach'           => ['m' => 'POST', 'who' => 'dev', 'raw' => true, 'body' => true],
    'dev-file'             => ['m' => 'GET',  'who' => 'dev', 'raw' => true],
    'dev-tokens'           => ['m' => 'GET',  'who' => 'dev', 'raw' => true],
    'dev-token-create'     => ['m' => 'POST', 'who' => 'dev', 'raw' => true],
    'dev-token-revoke'     => ['m' => 'POST', 'who' => 'dev', 'raw' => true],
    'dev-item-log'         => ['m' => 'GET',  'who' => 'dev', 'raw' => true],
    'dev-log-links'        => ['m' => 'POST', 'who' => 'dev', 'raw' => true],
    'dev-task-from-log'    => ['m' => 'POST', 'who' => 'dev', 'raw' => true],
    'dev-log'              => ['m' => 'GET',  'who' => 'dev', 'raw' => true],
];

/** ההגדרה של הפעולה, אחרי בדיקת שם ושיטה. 404 / 405. */
function sf_action_spec(string $method, string $action): array
{
    $spec = SF_ACTIONS[$action] ?? sf_fail('unknown_action');
    if ($method !== $spec['m']) sf_fail('method');
    return $spec;
}

/** ההכרעה: מותר למנהל הזה? (אחרי sf_authenticate — המנהל פעיל.) */
function sf_access(array $spec, ?array $staff): void
{
    if ($spec['who'] === 'public' || $spec['who'] === 'reader') return;
    if ($staff === null || $staff['status'] !== 'active') sf_fail('login_required');
    if ($spec['who'] === 'dev' && !(int)$staff['is_developer']) sf_fail('forbidden');
}
