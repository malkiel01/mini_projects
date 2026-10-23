<?php
$p = new PDO('sqlite:platform/data/platform.sqlite', null, null, [PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
$out = ['apk' => [], 'nav' => [], 'visits' => []];
foreach (['file-merger', 'pdf-merger'] as $a) {
    $st = $p->prepare('SELECT COUNT(*) n, COUNT(DISTINCT user_id) users, MAX(at) last, MIN(at) first FROM visits WHERE app = ?');
    $st->execute([$a]);
    $out['visits'][$a] = $st->fetch(PDO::FETCH_ASSOC);
    $st = $p->prepare("SELECT COUNT(*) n FROM visits WHERE app = ? AND at >= ?");
    $st->execute([$a, gmdate('Y-m-d\TH:i:s\Z', time() - 30 * 86400)]);
    $out['visits'][$a]['last30'] = $st->fetchColumn();
}
echo json_encode($out, JSON_UNESCAPED_UNICODE);
