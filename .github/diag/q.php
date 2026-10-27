<?php
// זמני: קריאה בלבד — יומן הבחירה מאנשי הקשר (רק מספרים ושם שגיאה, בלי פרטים) ושגיאות דף מהשעתיים האחרונות.
$p = new PDO('sqlite:platform/data/platform.sqlite');
$since = gmdate('Y-m-d\TH:i:s\Z', time() - 6 * 3600);
$r = $p->prepare("SELECT at, event, path, ua, info FROM diag_log WHERE at > ? AND (event LIKE '%contacts%' OR event LIKE '%error%' OR event LIKE '%reject%') ORDER BY id DESC LIMIT 40");
$r->execute([$since]);
$rows = $r->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as &$x) $x['info'] = preg_replace('/[^\s"@]+@[^\s"]+/', '<mail>', (string)$x['info']);
$ev = $p->prepare("SELECT event, COUNT(*) n FROM diag_log WHERE at > ? AND path LIKE '%admin%' GROUP BY event ORDER BY n DESC LIMIT 30");
$ev->execute([$since]);
echo json_encode(['rows' => $rows, 'admin_events' => $ev->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
