<?php
// תפקידים והרשאות לכל פעולה ב-API, דרך api.php האמיתי.

declare(strict_types=1);

// P1: מטריצת ההרשאות המלאה (ACTION_ROLES × חמשת התפקידים) — tests/cases/21-roles.php

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
