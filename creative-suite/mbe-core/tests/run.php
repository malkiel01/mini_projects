<?php
/**
 * בדיקות הליבה ב-PHP (בלי Node). php creative-suite/mbe-core/tests/run.php
 * הבדיקות בין השפות — ב-tests/js.test.mjs.
 */

declare(strict_types=1);
require __DIR__ . '/../src/php/mbe.php';

$fail = 0;
$n = 0;
function check(bool $ok, string $what): void
{
    global $fail, $n;
    $n++;
    if (!$ok) { $fail++; echo "✗ $what\n"; }
}
$fx = fn(string $p) => (string)file_get_contents(__DIR__ . "/fixtures/$p");
$codes = function (MbeFile $f): array { $c = array_values(array_unique(array_column($f->issues, 'code'))); sort($c); return $c; };
$docOf = function (string $bytes): string {
    $z = MbeZip::readZip($bytes);
    $issues = [];
    foreach ($z['entries'] as $e) if ($e['name'] === 'document.json') return (string)MbeZip::read($bytes, $e, $issues);
    return '';
};

// JSON קנוני
foreach (json_decode((string)file_get_contents(__DIR__ . '/canonical-vectors.json'), true) as $t) {
    check(MbeCanonical::encode(MbeCanonical::decode($t['in'])) === $t['out'], "canonical: {$t['note']}");
}
check(MbeCanonical::encode(MbeCanonical::decode('{"a":{},"b":[],"c":{"0":1}}')) === '{"a":{},"b":[],"c":{"0":1}}', '{} ו-[] נשמרים');

// כל kind: נפתח תקין, נשמר ב-PHP — אותו document.json ואותם חלקים
foreach (['text', 'layout', 'raster', 'vector'] as $k) {
    $orig = $fx("valid/$k.mbe");
    $f = Mbe::open($orig);
    check($f->issues === [], "$k נפתח בלי בעיות: " . json_encode($codes($f)));
    check($f->kind() === $k, "$k: kind");
    $files = $f->files;
    $out = Mbe::save($f, ['app' => 'php-test']);
    $g = Mbe::open($out);
    check($g->issues === [], "$k אחרי שמירה: " . json_encode($codes($g)));
    check($docOf($out) === $docOf($orig), "$k: document.json זהה בית-לבית");
    check($g->files == $files && array_keys($g->files) == array_keys($files), "$k: כל החלקים זהים בית-לבית");
    check(($g->manifest['x-future-manifest'] ?? null) == ['keep' => true, 'empty' => new stdClass()], "$k: שדה לא מוכר ב-manifest נשמר");
    check(($g->manifest['created']['app'] ?? '') === 'mbe-core-tests' && $g->manifest['modified']['app'] === 'php-test', "$k: created נשמר, modified עודכן");
    $za = new ZipArchive();
    $tmp = tempnam(sys_get_temp_dir(), 'mbe');
    file_put_contents($tmp, $out);
    check($za->open($tmp, ZipArchive::CHECKCONS) === true && $za->getNameIndex(0) === 'mimetype' && $za->getFromName('mimetype') === Mbe::MIMETYPE, "$k: ZipArchive פותח את הפלט");
    $za->close();
    unlink($tmp);
    check(substr($out, 30, 8 + strlen(Mbe::MIMETYPE)) === 'mimetype' . Mbe::MIMETYPE, "$k: החתימה בהיסט 30");
}

// עוינים
foreach (json_decode($fx('hostile.json'), true) as $c) {
    $f = Mbe::open($fx($c['file']), array_merge(MbeZip::LIMITS, $c['limits'] ?? []));
    foreach ($c['codes'] as $code) check(in_array($code, $codes($f), true), "{$c['file']}: חסר $code (יש: " . implode(',', $codes($f)) . ')');
    check($f->ok() === ($c['ok'] ?? false), "{$c['file']}: ok");
}

// ייצוגים מיושנים
$f = Mbe::open($fx('valid/text.mbe'));
$f->document['flows']['f1']['doc']['content'][0]['content'][0]['text'] = 'טקסט חדש';
$f->setRendition(Mbe::PREVIEW, $f->files[Mbe::PREVIEW], ['type' => 'image/png']);
Mbe::save($f, ['app' => 'word-processor']);
$stale = array_column($f->renditions(), 'stale', 'path');
check($stale === ['renditions/document.pdf' => true, Mbe::PREVIEW => false], 'שינוי במסמך → pdf מיושן, preview שחודש לא');

// save נעצר
$f = Mbe::create('text', ['surfaces' => []]);
try { Mbe::save($f, ['app' => 'x']); check(false, 'save בלי preview'); } catch (MbeError $e) { check($e->issues[0]['code'] === 'preview-missing', 'save בלי preview — preview-missing'); }
$f->setRendition(Mbe::PREVIEW, Mbe::open($fx('valid/text.mbe'))->files[Mbe::PREVIEW]);
$f->document['surfaces'][] = ['id' => 'a', 'type' => 'page', 'layers' => [['id' => 'a', 'type' => 'raster', 'src' => 'https://x/y.png']]];
try { Mbe::save($f, ['app' => 'x']); check(false, 'save עם מסמך פגום'); } catch (MbeError $e) {
    $c = array_column($e->issues, 'code');
    check(in_array('external-ref', $c, true) && in_array('duplicate-id', $c, true), 'save עם מסמך פגום — נעצר');
}
$g = Mbe::open($fx('valid/text.mbe'));
Mbe::save($g, ['app' => 'x', 'lite' => true]);
check(array_column($g->renditions(), 'path') === [Mbe::PREVIEW], 'קובץ קל — preview בלבד');

// גרסאות
$f = Mbe::open($fx('hostile/version-newer-minor.mbe'));
$out = Mbe::open(Mbe::save($f, ['app' => 'x']));
check($out->manifest['version'] === '1.7' && $out->manifest['x-from-1.7'] == ['a' => []], 'minor חדש — הגרסה והשדות נשמרים');
[, $m] = Mbe::migrate(['surfaces' => []], ['version' => '1.0'], 2);
check($m['version'] === '2.0', 'הממיר 1→2 קיים');

// media לפי sha256
$f = Mbe::open($fx('valid/raster.mbe'));
check(count($f->media()) === 2, 'אותו משאב פעמיים — נשמר פעם אחת');
check($f->addMedia('abc', 'image/png') === 'media/' . hash('sha256', 'abc') . '.png', 'addMedia — שם לפי sha256');

// manifest.id (1.1)
$f = Mbe::create('text', ['surfaces' => []]);
check($f->id() === null, 'id: מסמך חדש — בלי id עד השמירה');
$f->setRendition(Mbe::PREVIEW, Mbe::open($fx('valid/text.mbe'))->files[Mbe::PREVIEW]);
$a = Mbe::open(Mbe::save($f, ['app' => 'x']));
check((bool)preg_match(Mbe::ID_RE, (string)$a->id()) && $a->manifest['version'] === '1.1', 'id: נוצר בשמירה הראשונה, גרסה 1.1');
$b = Mbe::open(Mbe::save($a, ['app' => 'y']));
check($b->id() === $a->id(), 'id: נשמר בשמירה חוזרת');
$c = Mbe::open(Mbe::save($b, ['app' => 'z', 'asCopy' => true, 'now' => new DateTimeImmutable('2030-01-01T00:00:00Z')]));
check($c->id() !== $a->id() && (bool)preg_match(Mbe::ID_RE, (string)$c->id()) && $c->manifest['created']['at'] === '2030-01-01T00:00:00Z', 'id: שמירה כעותק — id ו-created חדשים');
$old = Mbe::open($fx('hostile/preview-too-big.mbe'));
check($old->id() === null && (bool)preg_match(Mbe::ID_RE, (string)Mbe::open(Mbe::save($old, ['app' => 'x']))->id()), 'id: קובץ 1.0 מקבל id בשמירה');
check(Mbe::open($fx('valid/text.mbe'))->id() === '11111111-1111-4111-8111-111111111111', 'id: נקרא מקובץ הדוגמה');

echo $fail ? "\n$fail מתוך $n בדיקות נכשלו\n" : "mbe-core PHP: $n בדיקות עברו\n";
exit($fail ? 1 : 0);
