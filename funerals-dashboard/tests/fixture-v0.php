<?php
// בדיקות בלבד: מסד בגרסה 0 — הסכמה הישנה בדיוק, 22 תיקים באוקטובר ו-30 שורות audit, ו-config.php עם hash של אסימון.
// php tests/fixture-v0.php <data-dir> <token>
declare(strict_types=1);
[$_, $dir, $token] = $argv;
@mkdir($dir, 0700, true);
$p = new PDO('sqlite:' . $dir . '/funerals.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$p->exec('CREATE TABLE IF NOT EXISTS funerals (id TEXT PRIMARY KEY, month TEXT NOT NULL, name TEXT NOT NULL, body TEXT NOT NULL, updated_at TEXT NOT NULL)');
$p->exec('CREATE INDEX IF NOT EXISTS idx_funerals_month ON funerals(month)');
$p->exec('CREATE TABLE IF NOT EXISTS audit (id INTEGER PRIMARY KEY AUTOINCREMENT, funeral_id TEXT NOT NULL, at TEXT NOT NULL, actor TEXT NOT NULL, action TEXT NOT NULL)');
$ins = $p->prepare('INSERT INTO funerals VALUES (?,?,?,?,?)');
$aud = $p->prepare('INSERT INTO audit(funeral_id, at, actor, action) VALUES (?,?,?,?)');
for ($i = 1; $i <= 22; $i++) {
    $date = sprintf('2026-10-%02d', $i);
    $name = 'נפטר בדיקה ' . $i;
    $id = 'fd_' . substr(hash('sha256', '2026-10|' . $name . '|' . $date), 0, 24);
    $body = ['id' => $id, 'month' => '2026-10', 'name' => $name, 'date' => $date, 'time' => '10:00', 'origin' => 'בית הלוויות', 'team' => 'צוות א',
        'grave' => 'גוש ' . $i, 'notes' => '', 'folder_url' => '', 'documents' => [],
        'checks' => ['license_number' => ['status' => 'document_present', 'value' => (string)(1000 + $i), 'source' => 'scan.pdf', 'note' => '']],
        'payments' => [['amount' => 100.5 * $i, 'purpose' => 'קבר', 'reference' => 'R' . $i, 'source' => '']], 'manual_fields' => []];
    $ins->execute([$id, '2026-10', $name, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), '2026-11-01T00:00:00+00:00']);
    $aud->execute([$id, '2026-11-01T00:00:00+00:00', 'api', 'import']);
    if ($i <= 8) $aud->execute([$id, '2026-11-02T00:00:00+00:00', 'owner@example.com', 'save']);
}
file_put_contents($dir . '/config.php', "<?php\nreturn " . var_export(['api_token_sha256' => hash('sha256', $token), 'dropbox_app_key' => ''], true) . ";\n");
chmod($dir . '/config.php', 0600);
