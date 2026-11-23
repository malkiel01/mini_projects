<?php
/**
 * בדיקות דפדפן בלבד (CLI): תשובות API לחוזה v2 מתוך ה-fixtures, דרך אותו קוד של השרת — כדי ש-check.js יזריק
 * אותן ב-page.route. לא נגיש מהרשת (tests/.htaccess, ו-404 מחוץ ל-CLI).
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../../lib/api.php';

final class V2FixtureSource implements AcademySource {
    public function __construct(private array $d, private string $n) {}
    public function name(): string { return $this->n; }
    public function isSample(): bool { return $this->n === 'local'; }
    public function fetch(): mixed { return $this->d; }
    public function info(): array { return ['commit' => $this->n === 'github' ? '49f4b2d000000000000000000000000000000000' : null, 'collectedAt' => '2026-11-23T10:00:00Z']; }
}
$fx = fn(string $n) => json_decode((string) file_get_contents(__DIR__ . "/../fixtures/v2/$n.json"), true);
$out = [];
foreach (['excerpt' => ['excerpt-49f4b2d', 'local'], 'full' => ['synthetic-full', 'local'],
          'github-full' => ['synthetic-full', 'github'], 'github-excerpt' => ['excerpt-49f4b2d', 'github']] as $k => [$f, $src]) {
    [$st, $body] = academy_api_snapshot(academy_default_config(), [], new V2FixtureSource($fx($f), $src));
    if ($st !== 200) { fwrite(STDERR, "$k: $st\n"); exit(1); }
    $out[$k] = $body;
}
echo json_encode($out, JSON_UNESCAPED_UNICODE);
