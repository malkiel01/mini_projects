<?php
/**
 * שלב 18 (docs/spec.md §15.7, §15.11, §15.11a, §15.12.3) — שאר ההגדרות, אישור המנוע, והתקרה.
 *
 * התקרה החודשית למעברים אוטומטיים: למשתמש (בהגדרות הכלליות) ולכל פרויקט / תת-פרויקט (בעל הלוח). היא חלק מ-quota_usd.
 * אכיפה דו-צדדית: תקרה למטה לא עולה על זו שמעליה (הורה, ואחריו המשתמש, ואחריו המכסה); ותקרה למעלה לא יורדת מתחת
 * לזו שכבר הוגדרה למטה — השרת מסרב ואומר איזה פרויקט חוסם. בלי תקרה בכלל — אין מעבר אוטומטי שעולה כסף (כרטיס).
 *
 * עקיפה לפרויקט (בעל הלוח): projects.lane_prefs — אותם מפתחות, הקרוב ביותר גובר על ההגדרה של המשלם.
 * מטלת מנוע שנוצרה בזמן מעבר (origin: failover) — רק אם ההגדרה מתירה, תמיד עם השער (Claude Code), והריצה הראשונה
 * שלה דורשת כרטיס אישור (engine-failover, בלי "תמיד").
 */
declare(strict_types=1);

/** מפתחות שפרויקט רשאי לעקוף (ההגדרות של אופן הטיפול; "השיטה המועדפת" נשארת של המשתמש). */
const PROJECT_LANE_KEYS = ['on_stop', 'return_preferred', 'silence_minutes', 'engine'];

function migrateLaneSettings(PDO $pdo): void {
    addColumn($pdo, 'projects', 'lane_prefs', "TEXT NOT NULL DEFAULT ''");
    addColumn($pdo, 'tasks', 'origin', "TEXT NOT NULL DEFAULT ''");   // '' | failover
}

/** ההגדרות שחלות על ערוץ בפרויקט: של המשלם, ועליהן העקיפה הקרובה ביותר בשרשרת הפרויקטים. */
function laneEffectivePrefs(int $payer, ?int $projectId): array {
    $p = lanePrefs($payer);
    if (!$projectId) return $p;
    $st = db()->prepare('SELECT parent_id, lane_prefs FROM projects WHERE id = ?');
    $chain = [];
    for ($id = $projectId, $i = 0; $id && $i < 20; $i++) {
        $st->execute([$id]);
        $r = $st->fetch();
        if (!$r) break;
        $chain[] = json_decode((string) $r['lane_prefs'], true) ?: [];
        $id = (int) ($r['parent_id'] ?? 0);
    }
    // מהרחוק לקרוב — הקרוב דורס
    foreach (array_reverse($chain) as $o) {
        foreach ($o as $k => $v) {
            if (in_array($k, PROJECT_LANE_KEYS, true) && in_array((string) $v, LANE_PREFS_VALUES[$k], true)) $p[$k] = (string) $v;
        }
    }
    return $p;
}

function projectLanePrefs(int $projectId): array {
    $st = db()->prepare('SELECT lane_prefs, auto_ceiling_usd FROM projects WHERE id = ?');
    $st->execute([$projectId]);
    $r = $st->fetch() ?: ['lane_prefs' => '', 'auto_ceiling_usd' => 0];
    return ['prefs' => array_intersect_key(json_decode((string) $r['lane_prefs'], true) ?: [], array_flip(PROJECT_LANE_KEYS)),
            'ceiling' => (float) $r['auto_ceiling_usd']];
}

/** בעל הלוח בלבד. ‏'' — מבטל עקיפה (חוזרים להגדרה של המשלם). */
function setProjectLanePrefs(array $user, int $projectId, array $in): array {
    if (authorRole($user) !== 'owner') throw new AppError('עקיפה לפרויקט — בעל הלוח בלבד', 403);
    requireProject($projectId, $user, 'read');
    $cur = projectLanePrefs($projectId)['prefs'];
    foreach ($in as $k => $v) {
        if (!in_array($k, PROJECT_LANE_KEYS, true)) continue;
        $v = (string) $v;
        if ($v === '') { unset($cur[$k]); continue; }
        if (!in_array($v, LANE_PREFS_VALUES[$k], true)) throw new InvalidArgumentException("ערך לא מוכר ל-$k");
        $cur[$k] = $v;
    }
    db()->prepare('UPDATE projects SET lane_prefs = ? WHERE id = ?')->execute([$cur ? json_encode($cur) : '', $projectId]);
    logEvent($user, 'lanes.project_prefs', "project:$projectId", true, json_encode($cur));
    return projectLanePrefs($projectId);
}

/* ── התקרה — אכיפה דו-צדדית ───────────────────────────────── */

/** התקרה הקרובה מעל פרויקט: הורה עם תקרה, אחרת של המשלם, אחרת המכסה. [סכום, מי]. */
function ceilingAbove(int $projectId, int $payer): array {
    $st = db()->prepare('SELECT parent_id FROM projects WHERE id = ?');
    $st->execute([$projectId]);
    $parent = (int) ($st->fetchColumn() ?: 0);
    $p = db()->prepare('SELECT id, name, parent_id, auto_ceiling_usd FROM projects WHERE id = ?');
    for ($id = $parent, $i = 0; $id && $i < 20; $i++) {
        $p->execute([$id]);
        $r = $p->fetch();
        if (!$r) break;
        if ((float) $r['auto_ceiling_usd'] > 0) return [(float) $r['auto_ceiling_usd'], (string) $r['name']];
        $id = (int) ($r['parent_id'] ?? 0);
    }
    $u = db()->prepare('SELECT auto_ceiling_usd, quota_usd FROM users WHERE id = ?');
    $u->execute([$payer]);
    $r = $u->fetch() ?: ['auto_ceiling_usd' => 0, 'quota_usd' => 0];
    if ((float) $r['auto_ceiling_usd'] > 0) return [(float) $r['auto_ceiling_usd'], 'התקרה של המשלם'];
    return [(float) $r['quota_usd'], 'המכסה החודשית'];
}

/** התקרה הגבוהה ביותר שכבר הוגדרה מתחת (בתתי-הפרויקטים). [סכום, שם הפרויקט] או [0, '']. */
function ceilingBelow(array $projectIds): array {
    if (!$projectIds) return [0.0, ''];
    $in = implode(',', array_fill(0, count($projectIds), '?'));
    $st = db()->prepare("SELECT name, auto_ceiling_usd FROM projects WHERE id IN ($in) AND auto_ceiling_usd > 0 ORDER BY auto_ceiling_usd DESC LIMIT 1");
    $st->execute(array_values($projectIds));
    $r = $st->fetch();
    return $r ? [(float) $r['auto_ceiling_usd'], (string) $r['name']] : [0.0, ''];
}

/** הפרויקטים שהמשתמש משלם עליהם (שורשים שהוא יצר, וכל מה שתחתם). */
function payerProjects(int $userId): array {
    $ids = [];
    foreach (db()->query('SELECT id FROM projects WHERE parent_id IS NULL AND archived = 0')->fetchAll(PDO::FETCH_COLUMN) as $root) {
        if (projectPayer((int) $root) === $userId) $ids = array_merge($ids, projectSubtree((int) $root));
    }
    return $ids;
}

/** התקרה של המשתמש (בהגדרות הכלליות). כל משתמש — רק של עצמו. 0 = אין. */
function setUserCeiling(array $user, float $usd): array {
    if ($usd < 0 || $usd > 100000) throw new InvalidArgumentException('סכום לא תקין');
    $uid = (int) $user['id'];
    $usd = round($usd, 2);
    if ($usd > 0) {
        $q = quotaState($uid);
        if ($q['quota'] > 0 && $usd > $q['quota']) {
            throw new AppError(sprintf('התקרה היא חלק מהמכסה החודשית ($%.2f) — לא יכולה לעבור אותה', $q['quota']), 422);
        }
        [$below, $name] = ceilingBelow(payerProjects($uid));
        if ($below > $usd) throw new AppError(sprintf('אי אפשר להוריד מתחת ל-$%.2f — זו התקרה של הפרויקט "%s". מורידים אותה קודם.', $below, $name), 422);
    }
    db()->prepare('UPDATE users SET auto_ceiling_usd = ? WHERE id = ?')->execute([$usd, $uid]);
    logEvent($user, 'lanes.ceiling', "user:$uid", true, (string) $usd);
    return ceilingInfo($uid, null);
}

/** תקרה לפרויקט / תת-פרויקט (בעל הלוח). 0 = אין (חלה זו שמעליו). */
function setProjectCeiling(array $user, int $projectId, float $usd): array {
    if (authorRole($user) !== 'owner') throw new AppError('תקרה לפרויקט — בעל הלוח בלבד', 403);
    requireProject($projectId, $user, 'read');
    if ($usd < 0 || $usd > 100000) throw new InvalidArgumentException('סכום לא תקין');
    $usd = round($usd, 2);
    $payer = (int) projectPayer($projectId);
    if ($usd > 0) {
        [$above, $by] = ceilingAbove($projectId, $payer);
        if ($above > 0 && $usd > $above) throw new AppError(sprintf('לא יכולה לעבור את $%.2f — %s', $above, $by === 'המכסה החודשית' || $by === 'התקרה של המשלם' ? $by : "התקרה של \"$by\""), 422);
        [$below, $name] = ceilingBelow(array_values(array_diff(projectSubtree($projectId), [$projectId])));
        if ($below > $usd) throw new AppError(sprintf('אי אפשר להוריד מתחת ל-$%.2f — זו התקרה של תת-הפרויקט "%s". מורידים אותה קודם.', $below, $name), 422);
    }
    db()->prepare('UPDATE projects SET auto_ceiling_usd = ? WHERE id = ?')->execute([$usd, $projectId]);
    logEvent($user, 'lanes.ceiling', "project:$projectId", true, (string) $usd);
    return ceilingInfo($payer, $projectId);
}

/** לתצוגה: התקרה כאן, מה מעליה, וכמה נשאר החודש. */
function ceilingInfo(int $payer, ?int $projectId): array {
    $own = 0.0;
    if ($projectId) { $own = projectLanePrefs($projectId)['ceiling']; [$above, $by] = ceilingAbove($projectId, $payer); }
    else {
        $u = db()->prepare('SELECT auto_ceiling_usd FROM users WHERE id = ?');
        $u->execute([$payer]);
        $own = (float) $u->fetchColumn();
        $q = quotaState($payer);
        [$above, $by] = [(float) $q['quota'], 'המכסה החודשית'];
    }
    $left = $projectId ? autoCeilingLeft($payer, $projectId) : null;
    return ['ceiling' => $own, 'above' => $above, 'above_by' => $by, 'spent' => round(autoSpent($payer, $projectId ? projectSubtree($projectId) : null), 4),
            'left' => $left ? $left['left'] : null];
}

/* ── מטלת מנוע בזמן מעבר (origin: failover) ───────────────── */

/** נוצרה מתשובה של Platform בזמן שהצ'אט לא ענה? אז היא "מעבר". */
function taskOriginFromMessage(?int $messageId): string {
    if (!$messageId) return '';
    $st = db()->prepare("SELECT m.author_role, c.unresponsive_since, c.lane_decision FROM messages m JOIN channels c ON c.id = m.channel_id WHERE m.id = ?");
    $st->execute([$messageId]);
    $r = $st->fetch();
    if (!$r || $r['author_role'] !== 'platform') return '';
    return (int) $r['unresponsive_since'] > 0 || $r['lane_decision'] === 'platform' ? 'failover' : '';
}

/**
 * לפני ריצה של מטלה במנוע. מטלה רגילה — null (ממשיכים). מטלת מעבר: ההגדרה "רק תשובות" → נדחה עם הסבר;
 * "מנוע אחרי כרטיס" → בלי אישור שנתן אדם — נפתח כרטיס engine-failover ומחזירים needs_approval; ותמיד Claude Code (השער).
 */
function failoverRunCheck(array $user, array $task, array $project): ?array {
    if (($task['origin'] ?? '') !== 'failover') return null;
    $payer = (int) projectPayer((int) $project['id']);
    if (laneEffectivePrefs($payer, (int) $project['id'])['engine'] !== 'card') {
        throw new AppError('מטלה מתוך מעבר ל-☁️: לפי ההגדרה Platform עונה רק בטקסט, בלי מנוע. הגדרות ← 🔀 מסלולים ← "Platform יכול גם להריץ את המנוע".', 409);
    }
    if (projectEngine($project) !== 'claude-code') {
        throw new AppError('מטלה מתוך מעבר רצה רק במנוע Claude Code — עם השער של הלוח. הפרויקט מוגדר למנוע אחר.', 409);
    }
    $st = db()->prepare("SELECT body FROM notes WHERE task_id = ? AND kind = 'decision' ORDER BY id DESC");
    $st->execute([$task['id']]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $b) {
        $d = json_decode((string) $b, true) ?: [];
        if (($d['type'] ?? '') === 'engine-failover') {
            if (($d['decision'] ?? '') === 'once') return null;
            throw new AppError('בעל הלוח דחה את הריצה הזו במנוע.', 409);
        }
    }
    if ($task['status'] === 'awaiting_approval') return ['needs_approval' => true, 'task_id' => (int) $task['id']];
    $ch = $task['asked_channel_id'] ? getChannel((int) $task['asked_channel_id']) : null;
    askApproval($task, 'board', ['type' => 'engine-failover',
        'summary' => "להריץ במנוע (Claude Code, עם השער) את המטלה שנוצרה מתשובה של Platform בזמן שהצ'אט לא ענה: {$task['title']}",
        'detail' => 'הריצה על חשבון המשלם. בלי "תמיד" — כל מטלת מעבר מאושרת בנפרד.'], null);
    return ['needs_approval' => true, 'task_id' => (int) $task['id']];
}
