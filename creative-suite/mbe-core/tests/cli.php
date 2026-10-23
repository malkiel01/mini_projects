<?php
/**
 * גשר לבדיקות בין השפות (tests/js.test.mjs קורא לו):
 *   php tests/cli.php validate <file> [limits-json]   → {"ok":bool,"codes":[…]}
 *   php tests/cli.php resave <in> <out> <app>          → פותח ושומר ב-PHP
 *   php tests/cli.php canon <json-file>                → JSON קנוני
 */

declare(strict_types=1);
require __DIR__ . '/../src/php/mbe.php';

[$_, $cmd] = $argv + [null, null];
switch ($cmd) {
    case 'validate':
        $limits = isset($argv[3]) ? array_merge(MbeZip::LIMITS, json_decode($argv[3], true)) : MbeZip::LIMITS;
        $f = Mbe::open((string)file_get_contents($argv[2]), $limits);
        $codes = array_values(array_unique(array_column($f->issues, 'code')));
        sort($codes);
        echo json_encode(['ok' => $f->ok(), 'codes' => $codes]);
        break;
    case 'resave':
        $f = Mbe::open((string)file_get_contents($argv[2]));
        if (!$f->ok()) { fwrite(STDERR, json_encode($f->issues, JSON_UNESCAPED_UNICODE)); exit(1); }
        file_put_contents($argv[3], Mbe::save($f, ['app' => $argv[4] ?? 'php-test', 'now' => new DateTimeImmutable('2026-10-23T10:00:00Z')]));
        break;
    case 'canon':
        echo MbeCanonical::encode(MbeCanonical::decode((string)file_get_contents($argv[2])));
        break;
    default:
        fwrite(STDERR, "usage: validate|resave|canon\n");
        exit(2);
}
