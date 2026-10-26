<?php
/**
 * בדיקות הליבה ב-PHP (בלי Node). php creative-suite/mbe-core/tests/run.php
 * הבדיקות בין השפות — ב-tests/js.test.mjs.
 */

declare(strict_types=1);
require __DIR__ . '/../src/php/mbe.php';
require __DIR__ . '/../src/php/face.php';

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

// יומן שלבים וגרסאות (1.1)
$doc = fn(string $t) => ['surfaces' => [], 'flows' => ['f1' => ['engine' => 'prosemirror', 'doc' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $t]]]]]]]];
$f = Mbe::create('text', $doc('א'));
$f->setRendition(Mbe::PREVIEW, Mbe::open($fx('valid/text.mbe'))->files[Mbe::PREVIEW]);
Mbe::save($f, ['app' => 'word-processor']);
$j = $f->journal();
check(count($j) === 1 && $j[0]['kind'] === 'create' && $j[0]['base'] === null, 'יומן: שמירה ראשונה — create');
$v1 = $j[0]['result'];
$f->document = $doc('ב'); Mbe::save($f, ['app' => 'word-processor']);
$f->document = $doc('ג'); Mbe::save($f, ['app' => 'word-processor']);
$j = $f->journal();
$snaps = array_values(array_filter(array_keys($f->files), fn($p) => (bool)preg_match(Mbe::SNAP_RE, (string)$p)));
check(count($j) === 1 && $j[0]['result'] === $f->documentSha256() && $snaps === ['history/' . $f->documentSha256() . '.json'], 'יומן: שמירות רצופות — שלב אחד, עותקי הביניים נמחקו');
Mbe::save($f, ['app' => 'word-processor']);
check(count($f->journal()) === 1, 'יומן: שמירה בלי שינוי — לא נוגעת');
$f->document = $doc('ד'); Mbe::save($f, ['app' => 'layout-studio', 'who' => 'המעמד']);
$j = $f->journal();
check(count($j) === 2 && $j[1]['kind'] === 'edit' && $j[1]['who'] === 'המעמד' && $j[1]['id'] === 's2' && $j[1]['base'] === $j[0]['result'], 'יומן: אפליקציה אחרת — שלב חדש');
$f->stage('export', 'המגיה', 'נשלח להגהה'); Mbe::save($f, ['app' => 'layout-studio']);
$last = $f->journal()[count($f->journal()) - 1];
check($last['kind'] === 'export' && $last['who'] === 'המגיה' && $last['note'] === 'נשלח להגהה', 'יומן: שלב מפורש');
$f->restore('s1');
check($f->document['flows']['f1']['doc']['content'][0]['content'][0]['text'] === 'ג', 'יומן: restore מחזיר את המסמך');
Mbe::save($f, ['app' => 'layout-studio']);
$j = $f->journal(); $last = $j[count($j) - 1];
check($last['kind'] === 'restore' && $last['note'] === 'שוחזר מ-s1' && $last['result'] === $j[0]['result'], 'יומן: שלב restore');
check($f->version($v1) === null && $f->version($j[1]['result'])['flows']['f1']['doc']['content'][0]['content'][0]['text'] === 'ד', 'יומן: version');
check($f->prune(1) >= 1, 'יומן: prune מוחק');
Mbe::save($f, ['app' => 'layout-studio']);
$hist = array_values(array_filter(array_column($f->issues, 'code'), fn($c) => str_starts_with($c, 'history')));
check(in_array(true, array_map(fn($s) => !empty($s['pruned']), $f->journal()), true) && $hist === [], 'יומן: pruned, בלי אזהרות היסטוריה');
try { $f->restore('s2'); check(false, 'restore לגרסה שנגזמה'); } catch (MbeError) { check(true, 'יומן: restore לגרסה שנגזמה — שגיאה'); }
$f = Mbe::open($fx('hostile/history-invalid.mbe'));
$before = $f->files[Mbe::JOURNAL];
$f->document['x'] = 1;
Mbe::save($f, ['app' => 'x']);
check($f->files[Mbe::JOURNAL] === $before && $f->journal() === [], 'יומן פגום — נשמר כמו שהוא');

// מזהה פסקה (1.1)
check((bool)preg_match('/^p[A-Za-z0-9]{10}$/', Mbe::paraId()) && Mbe::paragraphBookmark('p1') === '_mbe_pp1' && Mbe::bookmarkParaId('_mbe_pp1') === 'p1' && Mbe::bookmarkParaId('_GoBack') === null, 'מזהה פסקה: paraId / סימנייה');
$para = fn($id, $t) => ['type' => 'paragraph'] + ($id === null ? [] : ['attrs' => ['id' => $id]]) + ['content' => [['type' => 'text', 'text' => $t]]];
$f = Mbe::create('text', ['surfaces' => [], 'flows' => ['f1' => ['engine' => 'prosemirror', 'doc' => ['type' => 'doc', 'content' => [
    $para('keep_1', 'א'), $para(null, 'ב'), $para('bad-id', 'ג'),
    ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [$para('keep_1', 'ד')]]]],
    ['type' => 'heading', 'attrs' => new stdClass(), 'content' => []]]]]]]);
$f->setRendition(Mbe::PREVIEW, Mbe::open($fx('valid/text.mbe'))->files[Mbe::PREVIEW]);
Mbe::save($f, ['app' => 'x']);
$c = $f->document['flows']['f1']['doc']['content'];
$ids = [$c[0]['attrs']['id'], $c[1]['attrs']['id'], $c[2]['attrs']['id'], $c[3]['content'][0]['content'][0]['attrs']['id'], $c[4]['attrs']['id']];
$fresh = array_filter(array_slice($ids, 1), fn($id) => (bool)preg_match('/^p[A-Za-z0-9]{10}$/', $id));
check($ids[0] === 'keep_1' && count($fresh) === 4 && count(array_unique($ids)) === 5, 'מזהה פסקה: save משלים, מחליף פגום וכפול, שומר תקין');
check(!array_filter(array_column($f->issues, 'code'), fn($x) => str_starts_with($x, 'para-id')), 'מזהה פסקה: אחרי save — בלי אזהרות');

// פנים: stamp / identify (hub-api.md 2.1–2.2)
$f = Mbe::open($fx('valid/text.mbe'));
$saved = Mbe::save($f, ['app' => 'php-face']);
$docx = (string)file_get_contents(__DIR__ . '/faces/plain.docx');
$out = MbeFace::stamp($f, 'docx', $docx);
$id = MbeFace::identify($out);
check($id['type'] === 'face' && $id['format'] === 'docx' && $id['docId'] === $f->id() && $id['base'] === $f->documentSha256() && $id['model'] === true && $id['issues'] === [], 'פנים docx: identify');
check(MbeCanonical::encode($id['document']) === MbeCanonical::encode($f->document), 'פנים docx: המודל ב-customXml זהה');
$partsOf = function (string $b): array { $z = MbeZip::readZip($b); $i = []; $o = []; foreach ($z['entries'] as $e) $o[$e['name']] = MbeZip::read($b, $e, $i); return $o; };
$a = $partsOf($docx); $b = $partsOf($out);
$same = true;
foreach ($a as $k => $v) if (!in_array($k, ['[Content_Types].xml', '_rels/.rels', 'word/_rels/document.xml.rels'], true) && $b[$k] !== $v) $same = false;
check($same, 'פנים docx: שאר החלקים בית-לבית');
$c = $partsOf(MbeFace::stamp($f, 'docx', $out));
check(array_keys($c) === array_keys($b) && substr_count($c['docProps/custom.xml'], 'mbe-doc-id') === 1, 'פנים docx: החתמה חוזרת מחליפה');
$png = MbeFace::stamp($f, 'png', $f->files[Mbe::PREVIEW]);
$id = MbeFace::identify($png);
check($id['type'] === 'face' && $id['format'] === 'png' && $id['docId'] === $f->id() && $id['base'] === $f->documentSha256() && MbeFace::stamp($f, 'png', $png) === $png, 'פנים png');
$id = MbeFace::identify("%PDF-1.7\n" . MbeFace::xmpPacket($f) . "\n%%EOF");
check($id['type'] === 'face' && $id['format'] === 'pdf' && $id['docId'] === $f->id(), 'פנים pdf: xmpPacket → identify');
$id = MbeFace::identify($saved);
check($id['type'] === 'mbe' && $id['docId'] === $f->id() && $id['base'] === $f->documentSha256(), 'identify: ‎.mbe');
foreach ([$docx, 'שלום', '', "PK\x03\x04junk", $f->files[Mbe::PREVIEW]] as $i => $x) check(MbeFace::identify($x)['type'] === 'foreign', "identify: זר #$i");
Mbe::save($f, ['app' => 'php-face']);
check(array_column(array_filter($f->journal(), fn($s) => $s['kind'] === 'export'), 'note') === ['docx', 'png', 'pdf'], 'פנים: שלבי export ביומן');
$f->document['x'] = 1;
try { MbeFace::stamp($f, 'docx', $docx); check(false, 'face-not-saved'); } catch (MbeError $e) { check($e->issues[0]['code'] === 'face-not-saved', 'פנים: face-not-saved'); }

echo $fail ? "\n$fail מתוך $n בדיקות נכשלו\n" : "mbe-core PHP: $n בדיקות עברו\n";
exit($fail ? 1 : 0);
