<?php
/**
 * תכנית העבודה בעורך התמלילים — עם לקוח מדומה (בלי רשת): מעבר מהטקסט, הרשאה, עותק הפלט, Word ואקסל,
 * והעתקה בהצעה "על בסיס" (גם כשהעורך לא זמין).
 * הרצה: php org-budget-proposals/tests/plan_doc.php
 */

declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/ob-plan-' . bin2hex(random_bytes(4));
mkdir($tmp);
define('OB_DATA_DIR', $tmp);
define('OB_MAIL_SINK', $tmp . '/mail.jsonl');
define('OB_EXPORT_SINK', $tmp . '/out');
$_SERVER['REMOTE_ADDR'] = '10.0.0.5';

require __DIR__ . '/../lib/export.php';

$fails = 0;
function check(bool $ok, string $what): void
{
    global $fails;
    echo ($ok ? '  ✓ ' : '  ✕ ') . $what . "\n";
    if (!$ok) $fails++;
}

/** העורך המדומה: מסמכים בזיכרון, ו-offline לבדיקת נפילה. */
final class FakeWp
{
    public array $docs = [];
    public array $calls = [];
    public bool $offline = false;
    private int $n = 0;

    private function on(string $what): void
    {
        $this->calls[] = $what;
        if ($this->offline) throw new WordProcessorError('אין חיבור', 'offline');
    }
    public function create(string $title, ?array $page = null, ?array $import = null): string
    {
        $this->on('create');
        $id = 'd' . ++$this->n;
        $this->docs[$id] = ['title' => $title, 'page' => $page, 'text' => $import['content'] ?? ''];
        return $id;
    }
    public function copy(string $from, string $title, array $replace = []): string
    {
        $this->on('copy');
        $id = 'd' . ++$this->n;
        $text = $this->docs[$from]['text'];
        foreach ($replace as [$a, $b]) $text = str_replace($a, $b, $text);
        $this->docs[$id] = ['title' => $title, 'page' => $this->docs[$from]['page'], 'text' => $text];
        return $id;
    }
    public function embedSession(?string $doc, array $user, string $can = 'edit', string $mode = 'document', ?array $page = null, string $title = ''): array
    {
        $this->on('embed');
        $this->docs[$doc]['last'] = compact('user', 'can', 'mode');
        return ['embed_url' => "https://mbe-plus.com/mini_projects/word-processor/embed.php?t=x&doc=$doc", 'doc' => $doc];
    }
    public function export(string $doc, string $format): array
    {
        $this->on("export:$format");
        $lines = array_filter(explode("\n", $this->docs[$doc]['text']));
        if ($format === 'html') {
            $h = '';
            foreach ($lines as $l) {
                $h .= str_starts_with($l, '# ') ? '<h1>' . htmlspecialchars(substr($l, 2)) . '</h1>'
                    : (str_starts_with($l, '## ') ? '<h2>' . htmlspecialchars(substr($l, 3)) . '</h2>'
                    : (str_starts_with($l, '- ') ? '<ul><li>' . htmlspecialchars(substr($l, 2)) . '</li></ul>' : '<p>' . htmlspecialchars($l) . '</p>'));
            }
            return ['html' => '<div class="wp-doc" dir="rtl">' . $h . '<ol><li>ראשון</li><li>שני</li></ol></div>', 'css' => '.wp-doc{font-size:12pt}'];
        }
        return [
            'body' => '<w:p><w:pPr><w:pStyle w:val="wpNormal"/><w:numPr><w:ilvl w:val="0"/><w:numId w:val="9001"/></w:numPr></w:pPr><w:r><w:t>מהעורך</w:t></w:r></w:p>'
                . '<w:p><w:r><w:drawing><wp:inline><a:graphic><a:graphicData><pic:pic><pic:blipFill><a:blip r:embed="wpImg1"/></pic:blipFill></pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>',
            'styles' => '<w:style w:type="paragraph" w:styleId="wpNormal"><w:name w:val="wp Normal"/></w:style>',
            'numbering_parts' => ['abstract' => '<w:abstractNum w:abstractNumId="9001"><w:lvl w:ilvl="0"><w:numFmt w:val="decimal"/></w:lvl></w:abstractNum>',
                'num' => '<w:num w:numId="9001"><w:abstractNumId w:val="9001"/></w:num>'],
            'media' => [['name' => 'wp_1.gif', 'type' => 'image/gif', 'rid' => 'wpImg1', 'base64' => base64_encode('GIF89a')]],
            'rels' => '<Relationship Id="wpImg1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/wp_1.gif"/>',
            'namespaces' => 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"',
        ];
    }
}
$wp = $GLOBALS['ob_wp'] = new FakeWp();

function sink(callable $fn, array $s, int $id, string $ext): string
{
    try { $fn($s, $id); } catch (RuntimeException $e) { if ($e->getMessage() !== 'sink') throw $e; }
    return OB_EXPORT_SINK . ".$ext";
}
function fails_with(callable $fn, int $status): bool
{
    try { $fn(); return false; } catch (ApiError $e) { return $e->status === $status; }
}

$mk = function (string $email, string $name) {
    db()->prepare("INSERT INTO users (email, password_hash, display_name, status, created_at) VALUES (?, ?, ?, 'active', ?)")
        ->execute([$email, password_hash('secret-123', PASSWORD_DEFAULT), $name, now_iso()]);
    return current_session(auth_login(['email' => $email, 'password' => 'secret-123'])['token']);
};
$s = $mk('a@example.com', 'גזבר');
$v = $mk('v@example.com', 'צופה');

$r = org_import($s, ['data' => ['format' => IMPORT_FORMAT, 'org' => [
    'name' => 'ארגון', 'funds' => [['key' => 'ops', 'kind' => 'operating', 'name' => 'שוטף']],
    'budgets' => [['year' => 2026, 'work_plan' => "תכנית עבודה לשנת 2026\nמבוא\n# הקדשות\nפסקה לשנת 2026\n- תבליט\n## כותרת בלי אות",
        'lines' => [['fund' => 'ops', 'direction' => 'income', 'title' => 'הכנסה', 'amount' => 100]]]]]]]);
$orgId = $r['org_id'];
$bid = budget_list($s, $orgId)['budgets'][0]['id'];
member_add($s, ['org_id' => $orgId, 'email' => 'v@example.com', 'role' => 'viewer']);

echo "המפתח הציבורי\n";
$real = new WordProcessorClient(db(), 'org-budget-proposals', 'הצעות תקציב לארגונים', WP_URL . 'api.php?action=embed_key');
$k = $real->publicKeys();
check($k['client'] === 'org-budget-proposals' && count($k['keys']) === 1 && $k['keys'][0]['alg'] === 'Ed25519', 'embed_key: מפתח Ed25519 אחד, נוצר במסד');
check($real->publicKeys() === $k, 'קריאה שנייה — אותו מפתח (לא נוצר חדש)');
check(!str_contains(json_encode($k), db()->query('SELECT secret FROM wp_embed_keys')->fetchColumn()), 'הסוד לא יוצא');
$app = json_decode(file_get_contents(__DIR__ . '/../app.json'), true);
check((bool)array_filter($app['public'], fn($p) => preg_match('/^api\.php\?action=.*\bembed_key\b/', $p)), 'embed_key ציבורי ב-app.json');

echo "פתיחה ראשונה: הטקסט עובר לעורך\n";
$e = editor_session($s, ['budget_id' => $bid]);
$doc = $e['doc'];
check($e['can'] === 'edit' && str_starts_with($e['embed_url'], 'https://mbe-plus.com/'), 'עורך בטיוטה — edit, וכתובת לעורך');
check($wp->docs[$doc]['text'] === "## מבוא\n# א. הקדשות\nפסקה לשנת 2026\n- תבליט\n## כותרת בלי אות", 'lines: פרק עם אות = #, כותרת = ##, בלי כותרת השנה');
check($wp->docs[$doc]['page']['font']['family'] === 'Arial' && $wp->docs[$doc]['page']['margins']['right'] === 20 && $wp->docs[$doc]['page']['numbering']['start'] === 2,
    'הגדרות העמוד — מעיצוב המסמך של הארגון');
check($wp->docs[$doc]['last']['mode'] === 'section' && $wp->docs[$doc]['last']['user'] === ['id' => (string)$s['id'], 'name' => 'גזבר'], 'section, ומשתמש יציב עם שם');
$b = budget_get($s, $bid)['budget'];
check($b['plan_doc'] === true && str_contains($b['plan_view']['html'], '<h1>א. הקדשות</h1>'), 'המזהה נשמר מיד, ועותק HTML נשמר');
editor_session($s, ['budget_id' => $bid]);
check(count(array_filter($wp->calls, fn($c) => $c === 'create')) === 1, 'פתיחה שנייה — אותו מסמך, לא נוצר חדש');

echo "הרשאה\n";
check(editor_session($v, ['budget_id' => $bid])['can'] === 'view', 'צופה — view');
budget_status($s, ['budget_id' => $bid, 'status' => 'submitted']);
check(editor_session($s, ['budget_id' => $bid])['can'] === 'view', 'הצעה שהוגשה — view גם לעורך');
budget_status($s, ['budget_id' => $bid, 'status' => 'draft']);
check(fails_with(fn() => editor_session($mk('x@example.com', 'זר'), ['budget_id' => $bid]), 404) || fails_with(fn() => editor_session(current_session(auth_login(['email' => 'x@example.com', 'password' => 'secret-123'])['token']), ['budget_id' => $bid]), 403),
    'מי שלא בארגון — נחסם');

echo "שמירה בעורך → העותק אצלנו\n";
$wp->docs[$doc]['text'] .= "\nפסקה חדשה";
$r = plan_sync($s, ['budget_id' => $bid]);
check(str_contains($r['plan_view']['html'], 'פסקה חדשה'), 'plan_sync מחזיר ושומר את ה-HTML החדש');
$d = doc_model($s, $bid);
$types = array_column($d['plan']['blocks'], 'type');
check($d['plan']['view'] !== null && $d['plan']['blocks'][1] === ['type' => 'heading', 'text' => 'א. הקדשות'] && in_array('bullet', $types, true), 'מודל המסמך: בלוקים מה-HTML (כותרת, תבליט)');
$ol = array_values(array_filter($d['plan']['blocks'], fn($x) => ($x['n'] ?? null) !== null));
check(array_column($ol, 'n') === [1, 2], 'רשימה ממוספרת — מספרים');

echo "Word\n";
$z = new ZipArchive();
$z->open(sink('export_docx', $s, $bid, 'docx'));
$docXml = $z->getFromName('word/document.xml');
$dd = new DOMDocument();
check(@$dd->loadXML($docXml) !== false, 'document.xml תקין עם הגוף מהעורך');
check(str_contains($docXml, 'מהעורך') && !str_contains($docXml, 'פסקה לשנת 2026'), 'הגוף מהעורך במקום הטקסט הישן');
check(str_contains($docXml, 'xmlns:pic=') && substr_count($docXml, 'xmlns:w=') === 1, 'מרחבי שמות: נוספו a/pic, בלי כפילות');
check(str_contains($z->getFromName('word/styles.xml'), 'w:styleId="wpNormal"'), 'הסגנונות של העורך ב-styles.xml');
check(str_contains((string)$z->getFromName('word/numbering.xml'), 'w:abstractNumId="9001"'), 'numbering.xml');
check($z->getFromName('word/media/wp_1.gif') === 'GIF89a', 'התמונה בקובץ');
$rels = $z->getFromName('word/_rels/document.xml.rels');
$ct = $z->getFromName('[Content_Types].xml');
check(str_contains($rels, 'Id="wpImg1"') && str_contains($rels, 'numbering.xml') && str_contains($ct, 'Extension="gif"') && str_contains($ct, '/word/numbering.xml'),
    'קשרים וסוגי תוכן');
foreach (['word/styles.xml', 'word/numbering.xml', 'word/_rels/document.xml.rels', '[Content_Types].xml'] as $p) {
    check(@(new DOMDocument())->loadXML($z->getFromName($p)) !== false, "$p תקין");
}
$z->close();

echo "אקסל\n";
$z->open(sink('export_xlsx', $s, $bid, 'xlsx'));
$all = '';
for ($i = 0; $i < $z->numFiles; $i++) $all .= $z->getFromIndex($i);
check(str_contains($all, 'פסקה חדשה') && str_contains($all, '1.  ראשון'), 'תכנית העבודה מהעורך באקסל, עם מספור');
$z->close();

echo "על בסיס השנה הקודמת\n";
$n = budget_create($s, ['org_id' => $orgId, 'year' => 2027, 'based_on_id' => $bid])['id'];
$nb = budget_get($s, $n)['budget'];
$nd = plan_row($n)['plan_doc'];
check($nd && $nd !== $doc && str_contains($wp->docs[$nd]['text'], 'פסקה לשנת 2027'), 'המסמך הועתק בעורך, עם החלפת השנה');
check(str_contains($nb['plan_view']['html'], 'לשנת 2027'), 'והעותק של ההצעה החדשה כבר מעודכן');

$wp->offline = true;
$m = budget_create($s, ['org_id' => $orgId, 'year' => 2028, 'based_on_id' => $n])['id'];
$row = plan_row($m);
check(!$row['plan_doc'] && json_decode($row['plan_src'], true) === ['doc' => $nd, 'year' => 2027] && $row['plan_html'] !== null,
    'העורך לא זמין ביצירה — ההצעה נוצרת, המקור נשמר, והעותק עובר');
check(fails_with(fn() => editor_session($s, ['budget_id' => $m]), 502), 'פתיחה כשהעורך לא זמין — הודעה, לא קריסה');
$wp->offline = false;
editor_session($s, ['budget_id' => $m]);
$row = plan_row($m);
check($row['plan_doc'] && !$row['plan_src'] && str_contains($wp->docs[$row['plan_doc']]['text'], 'פסקה לשנת 2028'), 'בפתיחה הבאה — ההעתקה נעשית (2027 → 2028)');

$c = budget_duplicate($s, ['budget_id' => $bid])['id'];
$cd = plan_row($c)['plan_doc'];
check($cd && $cd !== $doc && $wp->docs[$cd]['text'] === $wp->docs[$doc]['text'], 'עותק לאותה שנה — מסמך נפרד, אותו תוכן');

echo "הצעה בלי תכנית\n";
$e2 = budget_create($s, ['org_id' => $orgId, 'year' => 2030])['id'];
check(plan_row($e2)['plan_doc'] === null && doc_model($s, $e2)['plan']['view'] === null, 'בלי פתיחה — אין מסמך');
editor_session($s, ['budget_id' => $e2]);
check($wp->docs[plan_row($e2)['plan_doc']]['text'] === '', 'פתיחה — מסמך ריק');

echo $fails ? "\n✕ $fails נכשלו\n" : "\n✓ הכול עבר\n";
exit($fails ? 1 : 0);
