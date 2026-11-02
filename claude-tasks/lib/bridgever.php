<?php
/**
 * גרסת הגשר בצ'אטים, וצ'אט שממתין לאישור באפליקציה — docs/spec.md §5.10.1.
 *
 * ‏bridge_version: הצ'אט מדווח אותה ב-channel-health. ערוץ פעיל שגרסתו ישנה (או לא דווחה — צ'אט
 * שנפתח לפני התיקון) מסומן "⚠ גרסה ישנה", ובעל הלוח שולח לו מ-⚙ הוראת עדכון חד-פעמית.
 * ‏waiting: hook ה-Notification מדווח חלון הרשאה שפתוח באפליקציה — "⏸" בשורת הערוץ, עד שהצ'אט
 * שולח משהו שוב.
 */
declare(strict_types=1);

/** הגרסה הנוכחית — לעדכן יחד עם BRIDGE_VERSION ב-bridge/lib.sh. */
const BRIDGE_VERSION_CURRENT = '2026-11-02.2';

/** ההוראה שנשלחת לצ'אט ישן. הסקיל הישן כבר פועל לפי הודעת owner; הצ'אט לא מבצע commit לקובץ. */
const BRIDGE_UPDATE_TEXT = "עדכון הגשר ללוח (חד-פעמי). הרץ בדיוק את זה, ואל תבצע commit לשום קובץ:\n\n"
    . "```\n"
    . "git fetch origin main && git show origin/main:.claude/settings.json > .claude/settings.json && bash <(git show origin/main:claude-tasks/bridge/update.sh)\n"
    . "```\n\n"
    . "מעכשיו: את link.sh ואת המאזין מריצים מהעותק המעודכן — `bash ~/.claude-tasks-bridge/link.sh …` ו-"
    . "`bash ~/.claude-tasks-bridge/listen.sh` (לא מ-claude-tasks/bridge שבענף שלך, שהוא ישן). את המאזין מפעילים רק "
    . "כשבעל הלוח החזיר את המאזינים. ההוראות המעודכנות: `git show origin/main:.claude/skills/claude-tasks-live/SKILL.md`.\n\n"
    . "אחר כך: bash ~/.claude-tasks-bridge/link.sh status, וענה בשורה אחת מה הודפס.";

function migrateBridgeVersion(PDO $pdo): void {
    addColumn($pdo, 'channels', 'bridge_version', "TEXT NOT NULL DEFAULT ''");
    addColumn($pdo, 'channels', 'waiting_tool', "TEXT NOT NULL DEFAULT ''");
    addColumn($pdo, 'channels', 'waiting_at', 'INTEGER NOT NULL DEFAULT 0');
}

/** ערוץ פעיל שהגשר שלו לא בגרסה הנוכחית. */
function bridgeOutdated(array $channel): bool {
    return ($channel['status'] ?? '') === 'active' && (string) ($channel['bridge_version'] ?? '') !== BRIDGE_VERSION_CURRENT;
}

function channelSetVersion(array $channel, string $version): void {
    $version = preg_match('/^[0-9A-Za-z.:_-]{1,40}$/', $version) ? $version : '';
    if ($version === '') return;
    db()->prepare('UPDATE channels SET bridge_version = ? WHERE id = ?')->execute([$version, $channel['id']]);
}

/**
 * ⏸ חלון הרשאה באפליקציה (Notification hook). הודעת system אחת לכל כלי — לא שוב על אותו כלי
 * כל עוד הסימון דולק. מחזיר האם נשלחה הודעה.
 */
function channelWaiting(array $channel, string $tool): bool {
    $tool = mb_substr(trim(preg_replace('/[^\p{L}\p{N} _.:\/-]/u', '', $tool)), 0, 80) ?: 'כלי';
    $fresh = getChannel((int) $channel['id']);
    if ($fresh['waiting_at'] > 0 && $fresh['waiting_tool'] === $tool) return false;
    db()->prepare('UPDATE channels SET waiting_tool = ?, waiting_at = ? WHERE id = ?')->execute([$tool, nowTs(), $channel['id']]);
    $link = $fresh['session_url'] ? "\n🔗 " . $fresh['session_url'] : '';
    channelPost($fresh, 'system', "⏸ הצ'אט ממתין לאישור באפליקציה: $tool$link", null, null, 'status');
    return true;
}

/** הצ'אט שלח משהו — כבר לא ממתין לאישור. */
function channelWaitingClear(int $channelId): void {
    db()->prepare("UPDATE channels SET waiting_tool = '', waiting_at = 0 WHERE id = ? AND waiting_at > 0")->execute([$channelId]);
}

/** 📨 שלח הוראת עדכון — בעל הלוח, לערוץ פעיל. */
function bridgeSendUpdate(array $user, int $channelId): array {
    if (authorRole($user) !== 'owner') throw new AppError('הוראת עדכון — בעל הלוח בלבד', 403);
    $c = channelForUser($user, $channelId);
    if ($c['status'] !== 'active') throw new AppError('הערוץ אינו פעיל', 409);
    $id = channelInsertIn($channelId, 'owner', (int) $user['id'], BRIDGE_UPDATE_TEXT);
    return ['id' => $id];
}

/** אחרי החלפת קונטיינר: הסשן כבר רשום — הרישום מחדש לוקח ממנו פרויקט, שם ותפקיד. */
function sessionChannelDefaults(string $sessionId): ?array {
    $st = db()->prepare('SELECT project_id, name, role FROM channels WHERE session_id = ?');
    $st->execute([trim($sessionId)]);
    $r = $st->fetch();
    return $r ? ['project_id' => (int) $r['project_id'], 'name' => (string) $r['name'], 'role' => (string) $r['role']] : null;
}
