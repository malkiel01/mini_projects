<?php
// בדיקות 05 בלבד: extDo() בהקשר של משתמש מחובר עם via 'ai' (כמו שה-AI של 06 יקרא לה), בלי HTTP.
// רץ מתוך tests/ext.sh על אותו מסד. שורת פקודה בלבד.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../lib.php';
require __DIR__ . '/../ext-lib.php';
$fail = static function (string $m): never { fwrite(STDERR, "FAIL (05/ai): $m\n"); exit(1); };
$pdo = db();
$m = extMember($pdo, 7, 1) ?? $fail('אין חבר');
extSetContext($m);
$GLOBALS['fd_ctx']['actor']['actor'] = 'tester@example.com';   // כמו resolveAccess: בלי סיומת — extDo מוסיף "(AI)"
$r = extDo($pdo, 'case_update', ['id' => 'fd_case_org1_cohen', 'checks' => ['identity_document' => ['status' => 'verified', 'value' => 'ת"ז 1']]], 'ai');
$c = $r['saved']['checks']['identity_document'] ?? [];
if (($c['status'] ?? '') !== 'document_present' || ($c['via'] ?? '') !== 'ai' || ($r['converted'][0]['requested'] ?? '') !== 'verified') $fail('ai: מאומת לא הומר ל"לאימות": ' . json_encode($r, JSON_UNESCAPED_UNICODE));
$e = $pdo->query("SELECT source, actor FROM events WHERE case_id='fd_case_org1_cohen' ORDER BY id DESC LIMIT 1")->fetch();
if ($e['source'] !== 'api' || !str_ends_with($e['actor'], '(AI)')) $fail('ai: אירוע ' . json_encode($e, JSON_UNESCAPED_UNICODE));
// תיק של ארגון אחר: existing() של 01 עונה 404 בעצמו (reply) — נבדק ב-HTTP ב-E2.
