<?php
/**
 * בדיקות 15.8: עורך התמלילים — עריכת תבנית Word בעורך, סנכרון השדות, ו-PDF ממסמך Word (עורך מדומה).
 * הרצה: php document-templates/tests/wp.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tmp = sys_get_temp_dir() . '/dt-wp-' . bin2hex(random_bytes(4));
define('DT_DATA_DIR', $tmp);
define('DT_MAIL_SINK', "$tmp/mail.log");
require __DIR__ . '/../lib/documents.php';
ensure_data_dir($tmp);

$fail = 0;
function check(string $name, bool $ok): void
{
    global $fail;
    echo ($ok ? '✓ ' : '✗ ') . $name . "\n";
    if (!$ok) $fail++;
}
function throws(string $name, callable $fn, int $status): void
{
    try { $fn(); check($name, false); }
    catch (ApiError $e) { check($name . " ({$e->getMessage()})", $e->status === $status); }
}
function make_docx(string $body): string
{
    global $tmp;
    $w = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"';
    $p = "$tmp/t-" . bin2hex(random_bytes(4)) . '.docx';
    $z = new ZipArchive();
    $z->open($p, ZipArchive::CREATE);
    $z->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="xml" ContentType="application/xml"/><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
    $z->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="r1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
    $z->addFromString('word/document.xml', "<?xml version=\"1.0\" encoding=\"UTF-8\"?><w:document $w><w:body>$body<w:sectPr/></w:body></w:document>");
    $z->close();
    $b = (string)file_get_contents($p);
    unlink($p);
    return $b;
}
$P = fn(string $t) => '<w:p><w:r><w:t xml:space="preserve">' . htmlspecialchars($t) . '</w:t></w:r></w:p>';

/** העורך המדומה: מסמכים בזיכרון; docx שמיובא — נשמר; export docx מחזיר את מה שנשמר ("נערך" — next). */
final class FakeWp
{
    public array $docs = [];
    public array $calls = [];
    public ?string $next = null;   // מה שהמשתמש "כתב" בעורך: ה-docx שייצא בפעם הבאה
    private int $n = 0;
    public function publicKeys(): array { return ['client' => 'document-templates', 'keys' => [['kid' => 'k1', 'alg' => 'Ed25519', 'key' => 'AAAA']]]; }
    public function create(string $title, ?array $page = null, ?array $import = null): string { $this->calls[] = 'create'; $d = 'd' . (++$this->n); $this->docs[$d] = ''; return $d; }
    public function import(string $doc, string $format, string $content): array { $this->calls[] = "import:$format"; $this->docs[$doc] = $content; return ['ok' => true]; }
    public function embedSession(?string $doc, array $user, string $can = 'edit', string $mode = 'document', ?array $page = null, string $title = '', ?string $ro = null): array
    { $this->calls[] = "session:$can"; return ['embed_url' => 'https://mbe-plus.com/mini_projects/word-processor/embed#t', 'doc' => $doc]; }
    public function export(string $doc, string $format, ?int $version = null): array|string
    {
        $this->calls[] = "export:$format";
        if ($format === 'pdf') return '%PDF-1.7 ' . strlen($this->docs[$doc]);
        if ($this->next !== null) { $this->docs[$doc] = $this->next; $this->next = null; }
        return $this->docs[$doc];
    }
    public function delete(string $doc): void { $this->calls[] = 'delete'; unset($this->docs[$doc]); }
}
$wp = $GLOBALS['dt_wp'] = new FakeWp();

db()->prepare("INSERT INTO users (email, password_hash, display_name, status, created_at) VALUES ('a@x.com', '!', 'אבי', 'active', ?)")->execute([now_iso()]);
db()->prepare("INSERT INTO users (email, password_hash, display_name, status, created_at) VALUES ('b@x.com', '!', 'בני', 'active', ?)")->execute([now_iso()]);
$a = db()->query('SELECT * FROM users WHERE id = 1')->fetch();
$b = db()->query('SELECT * FROM users WHERE id = 2')->fetch();

$up = function (string $bytes) use ($tmp) { file_put_contents("$tmp/u.docx", $bytes); return ['file' => ['tmp_name' => "$tmp/u.docx", 'error' => 0, 'size' => strlen($bytes)]]; };
$tid = tpl_create($a, ['name' => 'סיכום אימון'], $up(make_docx($P('שם: {{שם}}') . $P('הערות: {{הערות}}'))))['id'];
// מגדירים את השדה "שם" כחובה — ההגדרה צריכה לשרוד את הסנכרון
$t = tpl_get($a, $tid)['template'];
$t['schema']['fields'][0]['required'] = true;
tpl_save($a, ['id' => $tid, 'schema' => $t['schema']]);

// ── פתיחה ראשונה: הקובץ מיובא לעורך, והמזהה נשמר ──
check('לפני הפתיחה: לא נערכה בעורך', tpl_get($a, $tid)['template']['wp'] === false);
throws('תבנית לא שלי — 404', fn() => tpl_wp_session($b, ['id' => $tid]), 404);
$s = tpl_wp_session($a, ['id' => $tid]);
check('פתיחה: נוצר מסמך בעורך, הקובץ יובא, ונפתח לעריכה', $s['can'] === 'edit' && $wp->calls === ['create', 'import:docx', 'session:edit'] && str_starts_with($s['embed_url'], 'https://'));
check('המזהה נשמר בתבנית', tpl_get($a, $tid)['template']['wp'] === true);
$wp->calls = [];
tpl_wp_session($a, ['id' => $tid]);
check('פתיחה שנייה — בלי ייבוא מחדש', $wp->calls === ['session:edit']);

// ── סנכרון: בלי שינוי — לא נוגעים ──
// העורך מחזיר את הקובץ כמו שהוא — אחרי נרמול זה אותו קובץ
$r = tpl_wp_sync($a, ['id' => $tid]);
check('סנכרון בלי שינוי — הקובץ לא מוחלף', $r['changed'] === false && tpl_get($a, $tid)['template']['version'] === 1);

// ── סנכרון: שדה חדש בכל מקום, שדה שנמחק מהטקסט, וההגדרות של הקיים נשמרות ──
$wp->next = make_docx($P('שם: {{שם}} · תאריך המפגש: {{תאריך}}') . $P('מטרה אימונית: {{מטרה}}'));
$r = tpl_wp_sync($a, ['id' => $tid]);
$f = tpl_get($a, $tid)['template']['schema']['fields'];
$ph = array_column($f, null, 'placeholder');
check('סנכרון: 2 נוספו, 1 יצא', $r['changed'] === true && $r['added'] === 2 && $r['removed'] === 1);
check('השדות לפי הקובץ מהעורך: שם, תאריך, מטרה', array_keys($ph) === ['שם', 'תאריך', 'מטרה']);
check('"שם" שומר את ההגדרות שלו (חובה)', $ph['שם']['required'] === true);
check('ניחוש סוגים: תאריך, ו"מטרה" — טקסט ארוך', $ph['תאריך']['type'] === 'date' && $ph['מטרה']['type'] === 'textarea');
check('תבנית שלא בשימוש — אותה גרסה', $r['version'] === 1);

// ── תבנית בשימוש: גרסה חדשה; המסמך הקיים נשאר על הקובץ הקודם ──
$d = doc_create($a, ['template_id' => $tid]);
doc_save($a, ['id' => $d['id'], 'values' => [$ph['שם']['key'] => 'משה']]);
$wp->next = make_docx($P('שם: {{שם}}') . $P('סיכום: {{סיכום}}'));
$r = tpl_wp_sync($a, ['id' => $tid]);
check('תבנית בשימוש — גרסה 2', $r['changed'] && $r['version'] === 2);
check('המסמך הקיים נשאר עם השדות של גרסה 1', count(doc_get($a, $d['id'])['document']['schema']['fields']) === 3);

// ── שיתוף: צופה — רק צפייה, לא סנכרון ──
db()->prepare("INSERT INTO shares (object_type, object_id, user_id, level, granted_by, created_at) VALUES ('template', ?, 2, 'view', 1, ?)")->execute([$tid, now_iso()]);
$wp->calls = [];
check('צופה — נפתח לקריאה בלבד', tpl_wp_session($b, ['id' => $tid])['can'] === 'view' && $wp->calls === ['session:view']);
throws('צופה — לא מסנכרן', fn() => tpl_wp_sync($b, ['id' => $tid]), 403);

// ── רק תבנית Word ──
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
file_put_contents("$tmp/i.png", $png);
$img = tpl_create($a, ['name' => 'תמונה'], ['file' => ['tmp_name' => "$tmp/i.png", 'error' => 0, 'size' => strlen($png)]])['id'];
throws('תבנית שאינה Word — 422', fn() => tpl_wp_session($a, ['id' => $img]), 422);

// ── PDF ממסמך Word: רק אחרי נעילה ──
throws('PDF בטיוטה — 409 (15.1: רק אחרי נעילה)', fn() => doc_pdf($a, $d['id']), 409);
[$dd, $bytes, $name] = doc_docx_bytes($a, $d['id']);
check('ה-Word הממולא לשליחה לעורך — עם הערך', str_contains(text_of_docx($bytes), 'שם: משה') && str_ends_with($name, '.docx'));

// ── שגיאות של העורך → הודעה למשתמש ──
$GLOBALS['dt_wp'] = new class {
    public function create(string $t, ?array $p = null, ?array $i = null): string { throw new WordProcessorError('x', 'offline', 0); }
};
$t2 = tpl_create($a, ['name' => 'אחרת'], $up(make_docx($P('{{א}}'))))['id'];
throws('העורך לא זמין — 502 עם הסבר', fn() => tpl_wp_session($a, ['id' => $t2]), 502);
check('...והתבנית לא סומנה כנערכת', tpl_get($a, $t2)['template']['wp'] === false);

function text_of_docx(string $docx): string
{
    global $tmp;
    file_put_contents("$tmp/r.docx", $docx);
    $z = new ZipArchive();
    $z->open("$tmp/r.docx");
    $x = (string)$z->getFromName('word/document.xml');
    $z->close();
    preg_match_all('#<w:t[^>]*>([^<]*)</w:t>#u', $x, $m);
    return implode('', array_map('html_entity_decode', $m[1]));
}

foreach (array_merge(glob("$tmp/files/*") ?: [], array_filter(glob("$tmp/{,.}*", GLOB_BRACE) ?: [], 'is_file')) as $p) @unlink($p);
@rmdir("$tmp/files");
@rmdir($tmp);
echo $fail ? "\n$fail נכשלו\n" : "\nהכול עבר\n";
exit($fail ? 1 : 0);
