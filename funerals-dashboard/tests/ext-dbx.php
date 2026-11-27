<?php
// בדיקות 05 בלבד: files / file_text מול החתימות של 03 (dropboxCaseFiles, dropboxFileText, DbxError), עם תחליף מקומי.
// כשהקוד האמיתי של 03 קיים (dropbox/actions.php) — הבדיקה הזו מדלגת; את 03 בודקות הבדיקות של 03. שורת פקודה בלבד.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../lib.php';
require __DIR__ . '/../ext-lib.php';
if (is_file(__DIR__ . '/../dropbox/actions.php')) { echo "ext-dbx: דילוג — 03 קיימת\n"; exit(0); }
final class DbxError extends RuntimeException { public function __construct(public string $tag) { parent::__construct($tag); } }
function dropboxCaseFiles(PDO $pdo, array $case): array {
    if (($case['org_id'] ?? null) !== 1) throw new LogicException('org_id חסר');
    return [['id' => 5, 'name' => 'רישיון.pdf', 'kind' => 'pdf'], ['id' => 6, 'name' => 'הערות.txt', 'kind' => 'text']];
}
function dropboxFileText(PDO $pdo, int $orgId, int $fileId): ?string {
    return match ($fileId) { 6 => 'רישיון קבורה 12345', 5 => throw new DbxError('not_text'), 7 => throw new DbxError('not_connected'), default => null };
}
$fail = static function (string $m): never { fwrite(STDERR, "FAIL (05/dbx): $m\n"); exit(1); };
$pdo = db();
extSetContext(extMember($pdo, 7, 1) ?? $fail('אין חבר'));
$r = extDo($pdo, 'files', ['id' => 'fd_case_org1_cohen']);
if (count($r['files']) !== 2) $fail('files');
$r = extDo($pdo, 'case', ['id' => 'fd_case_org1_cohen']);
if (count($r['files'] ?? []) !== 2 || $r['files_note'] !== null) $fail('case.files');
if (extDo($pdo, 'file_text', ['file' => '6'])['text'] !== 'רישיון קבורה 12345') $fail('file_text');
foreach ([['5', 422], ['7', 501], ['99', 404], ['abc', 400], ['0', 400]] as [$f, $want]) {
    try { extDo($pdo, 'file_text', ['file' => $f]); $fail("file_text $f לא נכשל"); }
    catch (ExtError $e) { if ($e->getCode() !== $want) $fail("file_text $f → {$e->getCode()} במקום $want"); }
}
echo "ext-dbx: files / file_text מול החתימות של 03 — תקין\n";
