<?php
/** פרוסה V0 — ספייק מנוע העורך: קבצי הבדיקה מוגשים כנתונים, למפתח בלבד. */

declare(strict_types=1);

test('V0: קובץ בדיקה של הספייק — למפתח בלבד, מרשימה סגורה, והבייטים מגיעים כמו שהם', function () {
    $r = http('GET', '/api.php?action=dev-spike-fixture&name=handwritten.html', ['user' => U_OWNER]);
    eq(200, $r['status']);
    $raw = (string)base64_decode((string)$r['json']['b64'], true);
    eq("\xEF\xBB\xBF", substr($raw, 0, 3), 'ה-BOM נשמר');
    ok(str_contains($raw, "\r\n"), 'ה-CRLF נשמר');
    eq(strlen($raw), $r['json']['bytes']);
    eq(404, http('GET', '/api.php?action=dev-spike-fixture&name=' . rawurlencode('../../lib/dev.php'), ['user' => U_OWNER])['status'], 'אין קריאה מחוץ לרשימה');
    eq(404, http('GET', '/api.php?action=dev-spike-fixture&name=lexicon.base.css', ['user' => U_OWNER])['status']);
    eq(403, http('GET', '/api.php?action=dev-spike-fixture&name=handwritten.html', ['user' => U_EDITOR])['status'], 'עורך אינו מפתח');
});
