<?php
/**
 * גשר לבדיקות בין השפות (tests/js.test.mjs קורא לו):
 *   php tests/cli.php validate <file> [limits-json]   → {"ok":bool,"codes":[…]}
 *   php tests/cli.php resave <in> <out> <app>          → פותח ושומר ב-PHP
 *   php tests/cli.php canon <json-file>                → JSON קנוני
 *   php tests/cli.php stamp <mbe> <format> <in> <out>  → פנים מוחתמות (או {"error":code})
 *   php tests/cli.php identify <file>                  → התוצאה של identify
 */

declare(strict_types=1);
require __DIR__ . '/../src/php/mbe.php';
require __DIR__ . '/../src/php/face.php';

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
    case 'stamp':
        $f = Mbe::open((string)file_get_contents($argv[2]));
        try {
            file_put_contents($argv[5], MbeFace::stamp($f, $argv[3], (string)file_get_contents($argv[4])));
            echo json_encode(['ok' => true]);
        } catch (MbeError $e) {
            echo json_encode(['error' => $e->issues[0]['code'] ?? null]);
        }
        break;
    case 'identify':
        echo MbeCanonical::encode(MbeFace::identify((string)file_get_contents($argv[2])));
        break;
    default:
        fwrite(STDERR, "usage: validate|resave|canon|stamp|identify\n");
        exit(2);
}
