<?php
// זמני: קריאה בלבד — תוצאות דף הבדיקה של אנשי הקשר וניסיונות הבחירה מאז 08:35 (רק מספרים).
$p = new PDO('sqlite:platform/data/platform.sqlite');
$r = $p->query("SELECT at, event, path, ua, info FROM diag_log WHERE at > '2026-10-27T08:35:00Z' AND (event LIKE '%contacts%' OR path LIKE '%probe-contacts%') ORDER BY id ASC LIMIT 60");
echo json_encode($r->fetchAll(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
