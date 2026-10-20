<?php
// אפליקציה מארחת לבדיקות — בנויה לפי word-processor/docs/integration.md (כאן require ללקוח, כי זו בדיקה ולא אפליקציה)
require_once __DIR__ . '/../../../client/WordProcessorClient.php';
function db(): PDO { static $p; return $p ??= new PDO('sqlite:' . getenv('HOST_E2E_DATA') . '/host.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); }
function wp(): WordProcessorClient {
    static $wp = null;
    $me = getenv('HOST_E2E_URL');
    return $wp ??= new WordProcessorClient(db(), 'e2e-host', 'מארחת בדיקה', $me . 'api.php?action=embed_key', [rtrim($me, '/')], getenv('WP_E2E_BASE'));
}
db()->exec('CREATE TABLE IF NOT EXISTS proposals (id INTEGER PRIMARY KEY, plan_doc TEXT)');
db()->exec('INSERT OR IGNORE INTO proposals (id) VALUES (1)');
header('Content-Type: application/json');
try {
    switch ($_GET['action'] ?? '') {
        case 'embed_key': echo json_encode(wp()->publicKeys()); exit;
        case 'register': echo json_encode(['status' => wp()->register()]); exit;
        case 'editor_session':
            $p = db()->query('SELECT * FROM proposals WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
            $s = wp()->embedSession($p['plan_doc'] ?: null, ['id' => $_GET['u'] ?? 'u1', 'name' => 'רינה'], 'edit');
            if (!$p['plan_doc']) db()->prepare('UPDATE proposals SET plan_doc = ? WHERE id = 1')->execute([$s['doc']]);
            echo json_encode(['embed_url' => $s['embed_url'], 'doc' => $s['doc']]); exit;
        case 'export':
            $p = db()->query('SELECT * FROM proposals WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
            $r = wp()->export($p['plan_doc'], $_GET['f'] ?? 'text');
            echo json_encode(is_string($r) ? ['bytes' => strlen($r)] : $r, JSON_UNESCAPED_UNICODE); exit;
    }
} catch (WordProcessorError $e) {
    http_response_code(500); echo json_encode(['error' => $e->getMessage(), 'code' => $e->codeName], JSON_UNESCAPED_UNICODE);
}
