<?php
declare(strict_types=1);

/**
 * פרוסה 10 — הפעולות של api.php לזיהוי וקישור (link_*). החיבור ב-api.php — שתי שורות.
 * כל פעולה עוברת את השער, ה-session וה-CSRF של api.php; ההרשאות — need()/can() של 01, בלי הרשאות חדשות ב-PERMS
 * (פרוסה 11 משנה את התפקידים במקביל):
 *   שאלות — רואה: case.view; עונה: case.edit. כללים — רואה: case.view; משנה בארגון: members.manage; בשכבה המשותפת: dev.
 *   הרצה ידנית — dropbox.manage.
 */
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }
require_once __DIR__ . '/lib.php';

const LINK_ACTIONS = ['link_questions', 'link_answer', 'link_rules', 'link_rule_save', 'link_rule_delete', 'link_run', 'link_undo'];

function linkHandles(string $action): bool { return in_array($action, LINK_ACTIONS, true); }

function linkAction(PDO $pdo, string $action, string $method): never {
    $orgId = scope();
    $c = ctx();
    linkEnsureSchema($pdo);
    $want = fn(string $m) => $method === $m ? null : reply(405, ['error' => 'שיטה לא מורשית']);
    switch ($action) {
        case 'link_questions':
            $want('GET');
            need('case.view');
            reply(200, ['questions' => linkQuestionsOpen($pdo, $orgId), 'stats' => linkStats($pdo, $orgId), 'can_answer' => can('case.edit')]);

        case 'link_answer':
            $want('POST');
            need('case.edit');
            $in = body();
            $org = dbxOrg($pdo, $orgId);
            $r = linkAnswer($pdo, $org, (int)($in['id'] ?? 0), (string)($in['answer'] ?? ''), $c['actor']);
            if (!$r['ok']) reply((int)$r['status'], ['error' => $r['error']]);
            // התשובה פותחת את מה שחיכה לה (ילדי תיקיית חודש, קבצים בתיקייה) — בלי קריאה לספק, כדי לענות מהר
            $run = linkRun($pdo, $orgId, ['read' => false, 'budget' => 5]);
            reply(200, ['ok' => true, 'rule' => $r['rule'], 'run' => $run, 'questions' => linkQuestionsOpen($pdo, $orgId)]);

        case 'link_undo':
            // L2א: ביטול בלחיצה של קישור אוטומטי (case_id). התיקייה חוזרת לשאלה
            $want('POST');
            need('case.edit');
            $in = body();
            $r = linkUndo($pdo, dbxOrg($pdo, $orgId), (string)($in['case_id'] ?? ''), $c['actor']);
            if (!$r['ok']) reply((int)$r['status'], ['error' => $r['error']]);
            reply(200, ['ok' => true, 'questions' => linkQuestionsOpen($pdo, $orgId)]);
        case 'link_rules':
            $want('GET');
            need('case.view');
            $st = $pdo->prepare('SELECT * FROM ai_rules WHERE org_id IN (0, ?) ORDER BY kind, pattern, org_id');
            $st->execute([$orgId]);
            $rows = array_map(static fn($r) => ['id' => (int)$r['id'], 'layer' => (int)$r['org_id'] === 0 ? 'shared' : 'org', 'kind' => $r['kind'],
                'kind_label' => LINK_RULE_KINDS[$r['kind']] ?? $r['kind'], 'pattern' => $r['pattern'], 'value' => $r['value'],
                'text' => (string)$r['value'] === '' ? 'כבוי בארגון: "' . $r['pattern'] . '"' : linkRuleText($r),
                'auto' => (int)$r['auto'] === 1, 'uses' => (int)$r['uses'], 'created_by' => $r['created_by'], 'created_at' => $r['created_at']], $st->fetchAll());
            reply(200, ['rules' => $rows, 'can_manage' => can('members.manage'), 'can_shared' => can('dev'), 'stats' => linkStats($pdo, $orgId)]);

        case 'link_rule_save':
            $want('POST');
            $in = body();
            $layer = ($in['layer'] ?? 'org') === 'shared' ? 0 : $orgId;
            need($layer === 0 ? 'dev' : 'members.manage');
            $kind = (string)($in['kind'] ?? '');
            $pattern = cleanText($in['pattern'] ?? '', 120);
            $value = cleanText($in['value'] ?? '', 120);
            if (!isset(LINK_RULE_KINDS[$kind])) reply(400, ['error' => 'סוג כלל לא מוכר']);
            if (linkNorm($pattern) === '') reply(400, ['error' => 'נדרש תוכן לכלל']);
            if ($kind === 'month' && $value !== '' && !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $value)) reply(400, ['error' => 'חודש בצורה YYYY-MM']);
            if ($kind === 'file_kind' && $value !== '' && !in_array($value, linkDocKinds($pdo, $layer === 0 ? 0 : $orgId), true)) reply(400, ['error' => 'סוג מסמך לא מוכר']);
            if ($value === '' && $layer === 0) reply(400, ['error' => 'כלל משותף צריך ערך (למחיקה — מחיקה)']);
            // "מאשר לבד" — רק בכלל סוג מסמך, ורק כשאדם מסמן (כאן)
            $auto = $kind === 'file_kind' ? !empty($in['auto']) : false;
            if (!empty($in['id'])) {
                $old = $pdo->prepare('SELECT * FROM ai_rules WHERE id = ? AND org_id = ?');
                $old->execute([(int)$in['id'], $layer]);
                $o = dbxRow($old) ?? reply(404, ['error' => 'הכלל לא נמצא']);
                if ($o['kind'] !== $kind || $o['pattern'] !== linkNorm($pattern)) $pdo->prepare('DELETE FROM ai_rules WHERE id = ?')->execute([$o['id']]);
            }
            $id = linkRulePut($pdo, $layer, $kind, $pattern, $value, $c['actor'], $auto, 'manual');
            recordEvent($layer === 0 ? null : $orgId, null, $c['actor'], 'ui', 'ai_rule', ['rule.' . $id => [null, "$kind: " . linkNorm($pattern) . ' → ' . $value . ($auto ? ' (מאשר לבד)' : '')]]);
            reply(200, ['ok' => true, 'id' => $id]);

        case 'link_rule_delete':
            $want('POST');
            $in = body();
            $st = $pdo->prepare('SELECT * FROM ai_rules WHERE id = ? AND org_id IN (0, ?)');
            $st->execute([(int)($in['id'] ?? 0), $orgId]);
            $r = dbxRow($st) ?? reply(404, ['error' => 'הכלל לא נמצא']);
            need((int)$r['org_id'] === 0 ? 'dev' : 'members.manage');
            $pdo->prepare('DELETE FROM ai_rules WHERE id = ?')->execute([$r['id']]);
            recordEvent((int)$r['org_id'] === 0 ? null : $orgId, null, $c['actor'], 'ui', 'ai_rule', ['rule.' . $r['id'] => [linkRuleText($r), null]]);
            reply(200, ['ok' => true]);

        case 'link_run':
            $want('POST');
            need('dropbox.manage');
            if (session_status() === PHP_SESSION_ACTIVE) session_write_close();   // הקריאה לספק עשויה לקחת זמן
            reply(200, ['run' => linkRun($pdo, $orgId, ['read' => true, 'budget' => 20]), 'stats' => linkStats($pdo, $orgId)]);
    }
    reply(404, ['error' => 'פעולה לא מוכרת']);
}
