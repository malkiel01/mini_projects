<?php
// קריאה בלבד: לכל מסד של אפליקציה — טבלאות עם platform_sub: סה"כ, מקושרים, יתומים, ומצב "כפול".
$plat = new PDO('sqlite:platform/data/platform.sqlite', null, null, [PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
$pusers = [];
foreach ($plat->query('SELECT id, lower(coalesce(email, username)) e, display_name n, status FROM users') as $u) $pusers[(string)$u['id']] = $u;
$byEmail = [];
foreach ($pusers as $id => $u) if ($u['e']) $byEmail[$u['e']] = $id;
$out = ['platform_users' => count($pusers), 'apps' => []];
foreach (array_merge(glob('*/data/*.sqlite') ?: [], glob('*/data/*/*.sqlite') ?: []) as $f) {
    if (str_starts_with($f, 'platform/')) continue;
    try { $db = new PDO('sqlite:' . $f, null, null, [PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]); } catch (Throwable $e) { continue; }
    try { $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) { continue; }
    foreach ($tables as $t) {
        $cols = array_column($db->query("PRAGMA table_info(\"$t\")")->fetchAll(PDO::FETCH_ASSOC), 'name');
        if (!in_array('platform_sub', $cols, true)) continue;
        $ecol = in_array('email', $cols, true) ? 'email' : (in_array('login', $cols, true) ? 'login' : (in_array('username', $cols, true) ? 'username' : null));
        $ncol = in_array('display_name', $cols, true) ? 'display_name' : (in_array('name', $cols, true) ? 'name' : ($ecol ?? 'rowid'));
        $rows = $db->query("SELECT rowid rid, coalesce(platform_sub,'') sub, " . ($ecol ? "lower(coalesce($ecol,''))" : "''") . " e, coalesce($ncol,'') n FROM \"$t\"")->fetchAll(PDO::FETCH_ASSOC);
        $linkedSubs = []; $r = ['file' => $f, 'table' => $t, 'email_col' => $ecol, 'total' => count($rows), 'linked' => 0, 'orphan' => 0, 'broken' => 0,
              'orphan_match_email' => 0, 'duplicate' => [], 'multi_linked_same_sub' => [], 'orphans' => []];
        foreach ($rows as $x) if ($x['sub'] !== '') $linkedSubs[$x['sub']][] = $x;
        foreach ($rows as $x) {
            if ($x['sub'] !== '') { isset($pusers[$x['sub']]) ? $r['linked']++ : $r['broken']++; continue; }
            $r['orphan']++;
            $m = $x['e'] !== '' ? ($byEmail[$x['e']] ?? null) : null;
            $o = ['id' => $x['rid'], 'name' => $x['n'], 'email' => $x['e'], 'match_platform_user' => $m];
            if ($m !== null) {
                $r['orphan_match_email']++;
                if (isset($linkedSubs[$m])) { $o['dup_with'] = array_map(fn($y) => ['id' => $y['rid'], 'name' => $y['n'], 'email' => $y['e']], $linkedSubs[$m]); $r['duplicate'][] = $o; }
            }
            $r['orphans'][] = $o;
        }
        foreach ($linkedSubs as $sub => $list) if (count($list) > 1) $r['multi_linked_same_sub'][] = ['sub' => $sub, 'ids' => array_column($list, 'rid')];
        $out['apps'][] = $r;
    }
}
echo json_encode($out, JSON_UNESCAPED_UNICODE);
