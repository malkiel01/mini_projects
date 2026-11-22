<?php
/**
 * ההרשאות — מקום אחד (אפיון 01 §6). הנתב קורא ל-sf_access() לפני כל פעולה; המסכים רק מסתירים כפתורים.
 *
 *   public — בלי משתמש (וגם אז הפעולה עצמה מגינה: health מחזיר רק ok ו-version, devapi-* באסימון sfd_)
 *   staff  — כל מנהל פעיל (§3: הרשאות מלאות)
 *   dev    — is_developer (= platform_admin שבזהות)
 *
 * 'm' — השיטה היחידה שמותרת. פעולה משנה = POST (וגם CSRF); GET לפעולה משנה → 405.
 * 'raw' — התשובה יוצאת כמו שהיא, בלי עטיפת {"ok": true} (health, DevLoop).
 * 'body' — POST שגופו אינו JSON (קובץ מצורף למטלה): המודול קורא אותו בעצמו.
 */

declare(strict_types=1);

const SF_ACTIONS = [
    'health'               => ['m' => 'GET',  'who' => 'public', 'raw' => true],
    'devapi-feed'          => ['m' => 'GET',  'who' => 'public', 'raw' => true],
    'devapi-comment'       => ['m' => 'POST', 'who' => 'public', 'raw' => true],
    'devapi-file'          => ['m' => 'GET',  'who' => 'public', 'raw' => true],
    'devapi-attach'        => ['m' => 'POST', 'who' => 'public', 'raw' => true, 'body' => true],

    'me'                   => ['m' => 'GET',  'who' => 'staff'],
    'logout'               => ['m' => 'POST', 'who' => 'staff'],
    'staff-list'           => ['m' => 'GET',  'who' => 'staff'],
    'staff-suspend'        => ['m' => 'POST', 'who' => 'staff'],
    'staff-unsuspend'      => ['m' => 'POST', 'who' => 'staff'],
    'devloop-client-error' => ['m' => 'POST', 'who' => 'staff', 'raw' => true],

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
    if ($spec['who'] === 'public') return;
    if ($staff === null || $staff['status'] !== 'active') sf_fail('login_required');
    if ($spec['who'] === 'dev' && !(int)$staff['is_developer']) sf_fail('forbidden');
}
