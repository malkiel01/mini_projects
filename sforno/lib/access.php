<?php
/**
 * ההרשאות — מקום אחד (אפיון 01 §6). הנתב קורא ל-sf_access() לפני כל פעולה; המסכים רק מסתירים כפתורים.
 *
 *   public — בלי משתמש (וגם אז הפעולה עצמה מגינה: health מחזיר רק ok ו-version, devapi-* באסימון sfd_)
 *   reader — הקריאה הציבורית (read-*): בלי זיהוי מנהל, ומחזירה רק תוכן ציבורי שפורסם (lib/content.php). **לא** ב-"public"
 *            של app.json: בזמן הפיתוח השער מגביל אותה לצוות, ובהשקה (11) היא נפתחת לכולם
 *   guest  — עולם הקוראים (05), בלי חשבון: הרשמה, כניסה, אימות ואיפוס. עוגיית sf_reader בלבד — לא נוגעים בזהות המנהל
 *   account — קורא מחובר (עוגיית sf_reader + כותרת X-Reader-CSRF בכל POST): החשבון, המכשירים והסנכרון. מחזירה רק את
 *            המידע של הקורא עצמו. guest ו-account — כמו reader, **לא** ב-"public" של app.json עד ההשקה (11)
 *   staff  — כל מנהל פעיל (§3: הרשאות מלאות)
 *   dev    — is_developer (= platform_admin שבזהות)
 *
 *   launch — המצב המנותק (11, lib/sso.php): בלי משתמש, וקיימת רק כשיש data/launch.json. **לא** ב-"public" של app.json —
 *            בסאב-דומיין אין שער; בתוך הכלים הדיגיטליים השער מגביל אותה לצוות, והיא מחזירה unknown_action
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
    // 09: WooCommerce → ספורנו. האימות — HMAC של הגוף (lib/store.php); הגוף נקרא במודול, עד 2MB
    'store-webhook'        => ['m' => 'POST', 'who' => 'public', 'raw' => true, 'body' => true],
    // 11: המצב המנותק (lib/sso.php) — קיימות רק כשיש data/launch.json; בלעדיו — unknown_action, כמו פעולה שלא קיימת.
    // sso-*: POST מהדפדפן שלנו (סוג מדיה + Sec-Fetch-Site), state חד-פעמי בעוגיית sf_sso. platform-key — המפתח הציבורי בלבד
    'sso-start'            => ['m' => 'POST', 'who' => 'launch', 'max' => 2_000],
    'sso-finish'           => ['m' => 'POST', 'who' => 'launch', 'max' => 8_000],
    'platform-key'         => ['m' => 'GET',  'who' => 'launch', 'raw' => true],

    'read-titles'          => ['m' => 'GET',  'who' => 'reader'],
    'read-edition'         => ['m' => 'GET',  'who' => 'reader'],
    // הקורא (04) — קריאה בלבד, בלי עוגייה ובלי מידע אישי (lib/reader.php)
    'read-catalog'         => ['m' => 'GET',  'who' => 'reader'],
    'read-toc'             => ['m' => 'GET',  'who' => 'reader'],
    'read-revision'        => ['m' => 'GET',  'who' => 'reader'],
    'read-search'          => ['m' => 'GET',  'who' => 'reader'],
    'read-file'            => ['m' => 'GET',  'who' => 'reader', 'raw' => true],
    'read-pages'           => ['m' => 'GET',  'who' => 'reader', 'raw' => true],
    // הורדה לקריאה בלי רשת (06) — רק הרשימה וה-hash-ים; הקבצים עצמם — read-revision / read-file / read-pages
    'read-package'         => ['m' => 'GET',  'who' => 'reader'],
    // לוח שנה, זמני היום והלכה יומית (07) — השיטות המאושרות וההלכה שהגיעה. החישוב — בדפדפן; המיקום לא מגיע לכאן
    'read-zmanim-methods'  => ['m' => 'GET',  'who' => 'reader'],
    'read-halacha'         => ['m' => 'GET',  'who' => 'reader'],
    'read-halacha-archive' => ['m' => 'GET',  'who' => 'reader'],
    // החנות (09) — הקטלוג מהמטמון (WooCommerce הוא מקור האמת)
    'read-store-catalog'   => ['m' => 'GET',  'who' => 'reader'],
    'read-store-product'   => ['m' => 'GET',  'who' => 'reader'],
    // 10: דרכי הקשר (מייל / וואטסאפ של התמיכה) וסוגי הדיווח
    'read-support'         => ['m' => 'GET',  'who' => 'reader'],

    // חשבונות הקוראים והסנכרון (05) — עולם נפרד מהמנהלים (lib/readers.php, lib/sync.php)
    'account-me'           => ['m' => 'GET',  'who' => 'guest'],
    'account-register'     => ['m' => 'POST', 'who' => 'guest'],
    'account-login'        => ['m' => 'POST', 'who' => 'guest'],
    'account-google'       => ['m' => 'POST', 'who' => 'guest'],
    'account-verify'       => ['m' => 'POST', 'who' => 'guest'],
    'account-reset-request' => ['m' => 'POST', 'who' => 'guest'],
    'account-reset'        => ['m' => 'POST', 'who' => 'guest'],
    'account-verify-resend' => ['m' => 'POST', 'who' => 'account'],
    'account-logout'       => ['m' => 'POST', 'who' => 'account'],
    'account-devices'      => ['m' => 'GET',  'who' => 'account'],
    'account-device-revoke' => ['m' => 'POST', 'who' => 'account'],
    'account-export'       => ['m' => 'GET',  'who' => 'account'],
    'account-delete'       => ['m' => 'POST', 'who' => 'account'],
    'sync-push'            => ['m' => 'POST', 'who' => 'account', 'max' => 4_000_000],
    'sync-pull'            => ['m' => 'GET',  'who' => 'account'],
    // תזכורות (08) — קורא מחובר או אורח (מכשיר Push אנונימי, עוגיית sf_push). rcsrf — קורא מחובר שולח גם X-Reader-CSRF
    'push-key'             => ['m' => 'GET',  'who' => 'guest'],
    'push-subscribe'       => ['m' => 'POST', 'who' => 'guest', 'rcsrf' => true, 'max' => 8_000],
    'push-unsubscribe'     => ['m' => 'POST', 'who' => 'guest', 'rcsrf' => true, 'max' => 2_000],
    'push-test'            => ['m' => 'POST', 'who' => 'guest', 'rcsrf' => true, 'max' => 2_000],
    'reminders-guest-sync' => ['m' => 'POST', 'who' => 'guest', 'max' => 80_000],
    'reminders-status'     => ['m' => 'GET',  'who' => 'guest'],
    // 09: "לרכישה באתר" נלחץ — נספר (10). guest: POST בלי אסימון — סוג מדיה מדויק + Sec-Fetch-Site / Origin בנתב
    'store-out'            => ['m' => 'POST', 'who' => 'guest', 'max' => 2_000],
    // 10: דיווח על טעות / טופס קשר, ומונה פתיחות — guest: POST בלי אסימון (סוג מדיה + Sec-Fetch-Site בנתב). **לא מצרפים את הקורא**
    'report-create'        => ['m' => 'POST', 'who' => 'guest', 'max' => 8_000],
    'metrics-open'         => ['m' => 'POST', 'who' => 'guest', 'max' => 2_000],

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
    // 07 — הניהול: שיטות הזמנים (כל שמירה = גרסה), ההלכה היומית לפי תאריך עברי
    'zmanim-method-list'   => ['m' => 'GET',  'who' => 'staff'],
    'zmanim-method-save'   => ['m' => 'POST', 'who' => 'staff'],
    'zmanim-method-status' => ['m' => 'POST', 'who' => 'staff'],
    'zmanim-method-default' => ['m' => 'POST', 'who' => 'staff'],
    'halacha-list'         => ['m' => 'GET',  'who' => 'staff'],
    'halacha-get'          => ['m' => 'GET',  'who' => 'staff'],
    'halacha-save'         => ['m' => 'POST', 'who' => 'staff'],
    'halacha-publish'      => ['m' => 'POST', 'who' => 'staff'],
    'halacha-unpublish'    => ['m' => 'POST', 'who' => 'staff'],
    // 09 — הניהול: מצב הסנכרון, המיפוי, סנכרון עכשיו, נתוני דוגמה, קישור כותר ↔ מוצר
    'store-admin'          => ['m' => 'GET',  'who' => 'staff'],
    'store-map-save'       => ['m' => 'POST', 'who' => 'staff'],
    'store-sync-now'       => ['m' => 'POST', 'who' => 'staff'],
    'store-sample'         => ['m' => 'POST', 'who' => 'staff'],
    'store-link-set'       => ['m' => 'POST', 'who' => 'staff'],
    // 10 — התפעול: לוח הבקרה, מדדים, דיווחים, תמיכה בקורא (ספירות בלבד — בלי תוכן אישי)
    'ops-dashboard'        => ['m' => 'GET',  'who' => 'staff'],
    'ops-metrics'          => ['m' => 'GET',  'who' => 'staff'],
    'ops-reader'           => ['m' => 'GET',  'who' => 'staff'],
    'reports-list'         => ['m' => 'GET',  'who' => 'staff'],
    'report-get'           => ['m' => 'GET',  'who' => 'staff'],
    'report-set'           => ['m' => 'POST', 'who' => 'staff'],

    'dev-health'           => ['m' => 'GET',  'who' => 'dev'],
    'dev-mail-test'        => ['m' => 'POST', 'who' => 'dev'],
    'dev-audit'            => ['m' => 'GET',  'who' => 'dev'],
    'dev-jobs'             => ['m' => 'GET',  'who' => 'dev'],
    'dev-job-retry'        => ['m' => 'POST', 'who' => 'dev'],
    'dev-job-cancel'       => ['m' => 'POST', 'who' => 'dev'],
    'dev-cron-rotate'      => ['m' => 'POST', 'who' => 'dev'],
    'dev-google-set'       => ['m' => 'POST', 'who' => 'dev'],
    'dev-reminders'        => ['m' => 'GET',  'who' => 'dev'],
    // 09: החיבור לחנות — המפתחות נכנסים כאן ולא יוצאים לעולם (data/woo.json)
    'dev-store-set'        => ['m' => 'POST', 'who' => 'dev', 'max' => 4_000],
    'dev-store-test'       => ['m' => 'POST', 'who' => 'dev'],
    // 10: הגיבויים (data/backups/ — לא נגיש מהדפדפן; ההורדה רק כאן, נרשמת ביומן) ודרכי הקשר של התמיכה
    'dev-backups'          => ['m' => 'GET',  'who' => 'dev'],
    'dev-backup-now'       => ['m' => 'POST', 'who' => 'dev'],
    'dev-backup-verify'    => ['m' => 'POST', 'who' => 'dev'],
    'dev-backup-download'  => ['m' => 'POST', 'who' => 'dev', 'raw' => true, 'max' => 2_000],   // הגוף: {backup, password} — מוצפן
    'dev-ops-set'          => ['m' => 'POST', 'who' => 'dev', 'max' => 2_000],
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

/** הרמות שלא עוברות דרך זיהוי המנהל. */
const SF_NOT_STAFF = ['public', 'reader', 'guest', 'account', 'launch'];

/** ההכרעה: מותר למנהל הזה? (אחרי sf_authenticate — המנהל פעיל.) guest / account — נבדקו בנתב מול עוגיית הקורא. */
function sf_access(array $spec, ?array $staff): void
{
    if (in_array($spec['who'], SF_NOT_STAFF, true)) return;
    if ($staff === null || $staff['status'] !== 'active') sf_fail('login_required');
    if ($spec['who'] === 'dev' && !(int)$staff['is_developer']) sf_fail('forbidden');
}
