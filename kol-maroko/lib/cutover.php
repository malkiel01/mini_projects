<?php
/**
 * 🔁 המעבר (פרוסה M3 · docs/spec-v2/slices/M3-cutover.md §2; 09-migration.md §6; 07-publish-and-mount.md §8).
 *
 * המסך הזה **אינו מדליק דבר באתר** (G7). הוא רק:
 *   1. מחשב את התנאים שהכלי יודע (הקלטות בכלי, פרסום בפורמט החדש, התוסף, המשוב, נקודת שחזור);
 *   2. מקבל ראיה מהבעלים לתנאים שרק הוא יודע (קובץ / קישור / הערה, עם שם ותאריך);
 *   3. כשהכול ✓ — מציג את ההוראה לבעל האתר, ופותח מטלה (DevLoop) עם צעדי ההדלקה של §2.
 * ההדלקה עצמה — בעמוד התוסף בוורדפרס, בידי בעל האתר בלבד. כאן היא רק **נקראת** מה-ping (v2_on).
 *
 * הרשאות: קריאה — מפרסם ומעלה; כל שינוי (ראיה, נקודת שחזור, "מוכן למעבר") — בעלים בלבד. נבדק כאן ב-require_role,
 * לא רק בממשק (tests/cases/95-cutover.php).
 *
 * הנתונים: cutover_evidence ו-cutover_marks ב-data/kol.sqlite (מיגרציה 20); קובצי ראיה — במאגר הקבצים הקיים
 * (data/files/, דרך upload_init/chunk/done הרגיל). אין קובץ נתונים חדש, ולכן אין --exclude חדש.
 *
 * cutover_eval() — פונקציה טהורה: מקבלת את העובדות ומחזירה את השורות. כך כל ✓ / ✗ / ⏳ נבדק בלי אתר ובלי מסד.
 */

declare(strict_types=1);

require_once __DIR__ . '/library.php';
require_once __DIR__ . '/build.php';
require_once __DIR__ . '/restore.php';
require_once __DIR__ . '/dev.php';

const CUTOVER_SNAP_HOURS = 24;
/** התנאים שרק הבעלים יודע — ראיה. המפתח — שם התנאי בקוד. */
const CUTOVER_EVIDENCE = ['attack_kit', 'site_compare', 'q15', 'q4', 'owner_checklist'];
/** ההשוואה מול האתר: כמה צילומים (קבצים) לפחות — טלפון ומחשב. */
const CUTOVER_SITE_SHOTS = 2;
/** הסדר במסך = הסדר של 09 §6.1. */
const CUTOVER_ORDER = ['site_compare', 'build', 'plugin', 'attack_kit', 'feedback', 'recordings', 'q15', 'q4', 'backup', 'owner_checklist'];
const CUTOVER_SWITCH_PATH = 'וורדפרס ← קול מרוקו ← העמוד החדש ← הדלק';
const CUTOVER_KILL_RULE = 'משהו לא בסדר — מכבים את "העמוד החדש", והעמוד המקורי חוזר. לא מתקנים על האוויר: התיקון — בכלי, ואז שוב מצעד 2 (כתובת הבדיקה).';

// ── העובדות ───────────────────────────────────────────────

/** ה-ping, מנורמל: התוסף האמיתי של M2 (v2_live / v2_on) והמקבל המדומה (live כש-protocol 2). שגיאה — error. */
function cutover_ping(): array
{
    try {
        $p = site_call('ping', []);
    } catch (ApiError $e) {
        return ['error' => $e->getMessage()];
    }
    $proto = (int)($p['protocol'] ?? 0);
    return [
        'plugin' => (string)($p['plugin'] ?? ''), 'protocol' => $proto,
        'v2_live' => (int)($p['v2_live'] ?? ($proto >= 2 ? ($p['live'] ?? 0) : 0)),
        'v2_on' => (bool)($p['v2_on'] ?? false),
        'stats' => is_array($p['v2_stats'] ?? null) ? $p['v2_stats'] : null,    // המונה אחרי ההדלקה — כשהתוסף יוסיף אותו
    ];
}

/**
 * המשוב (M5, lib/forms.php): הטפסים בפרויקט (forms_draft — כל data-kol-form), והטפסים שהגרסה שבאתר רושמת (forms_live —
 * רק אליהם form.php מקבל פניות). ✓ = יש טופס בפרויקט, והגרסה שבאתר רושמת אותו — כלומר פנייה מהעמוד מגיעה לתיבה.
 */
function cutover_feedback_facts(): array
{
    if (!is_file(__DIR__ . '/forms.php')) return ['inbox' => null, 'forms' => [], 'live' => []];
    require_once __DIR__ . '/forms.php';
    $n = (int)db()->query('SELECT COUNT(*) FROM form_entries')->fetchColumn();
    return ['inbox' => ['ok' => true, 'detail' => "$n פניות בתיבה"], 'forms' => array_keys(forms_draft()), 'live' => array_keys(forms_live())];
}

/** הראיות הפעילות לכל תנאי (החדשה ראשונה). */
function cutover_evidence_rows(): array
{
    $out = array_fill_keys(CUTOVER_EVIDENCE, []);
    $files = [];
    foreach (db()->query('SELECT * FROM cutover_evidence WHERE revoked_at IS NULL ORDER BY id DESC')->fetchAll() as $r) {
        if (!isset($out[$r['cond']])) continue;
        $file = null;
        if ($r['file_id']) {
            $files[$r['file_id']] ??= db()->query('SELECT id, name, deleted_at FROM files WHERE id = ' . db()->quote($r['file_id']))->fetch() ?: null;
            $f = $files[$r['file_id']];
            $file = ['id' => $r['file_id'], 'name' => $f['name'] ?? '', 'missing' => !$f || !empty($f['deleted_at'])];
        }
        $out[$r['cond']][] = ['id' => (int)$r['id'], 'note' => $r['note'], 'url' => $r['url'], 'file' => $file, 'by' => $r['by_name'], 'at' => $r['at']];
    }
    return $out;
}

/** כל העובדות שהמסך צריך. $ping — לבדיקות (אחרת: ping אמיתי לאתר). */
function cutover_facts(?array $ping = null): array
{
    $recs = rec_rows();
    $words = [];
    foreach (db()->query('SELECT id, word FROM words')->fetchAll() as $w) $words[(int)$w['id']] = $w['word'];
    $missing = [];
    $tool = 0;
    foreach ($recs as $wid => $r) {
        if ($r['state'] === 'tool') { $tool++; continue; }
        $missing[] = ['word_id' => $wid, 'word' => $words[$wid] ?? '', 'state' => $r['state']];
    }
    $images = array_values(array_filter(library_site_images(), fn($i) => empty($i['replaced'])));
    $live = build_live();
    $snap = db()->query('SELECT id, at, kind FROM snapshots ORDER BY id DESC LIMIT 1')->fetch() ?: null;
    return [
        'now' => time(),
        'rec' => ['total' => count($recs), 'tool' => $tool, 'missing' => array_slice($missing, 0, 50), 'missing_n' => count($missing), 'images' => $images],
        'build' => $live ? ['version' => (int)$live['version'], 'sent_at' => $live['sent_at'], 'sent_by' => $live['sent_by'], 'rev' => (int)$live['rev'],
            'current' => $live['print'] === build_print()] : null,
        'rev' => build_rev(),
        'ping' => $ping ?? cutover_ping(),
        'feedback' => cutover_feedback_facts(),
        'snap' => $snap ? ['id' => (int)$snap['id'], 'at' => $snap['at']] : null,
        'snap_verified' => json_decode((string)setting('cutover_snap_verified', 'null'), true),
        'evidence' => cutover_evidence_rows(),
    ];
}

// ── ההערכה (טהורה) ───────────────────────────────────────

function cutover_row(string $key, string $title, string $status, string $source, string $detail, string $todo = '', array $more = []): array
{
    return ['key' => $key, 'title' => $title, 'status' => $status, 'source' => $source, 'detail' => $detail, 'todo' => $status === 'ok' ? '' : $todo] + $more;
}

/** העובדות ← השורות של 09 §6.1, וכל שורה: מצב (ok / bad / wait), מאיפה המצב בא, ומה לעשות. */
function cutover_eval(array $f): array
{
    $rows = [];
    $n = fn(int $x) => number_format($x);

    // הקלטות ותמונה "בכלי" (A2)
    $r = $f['rec'];
    $allRec = $r['total'] > 0 && $r['tool'] === $r['total'];
    $ok = $allRec && !$r['images'];
    $detail = $n($r['tool']) . ' מתוך ' . $n($r['total']) . ' בכלי' . ($r['images'] ? ' · ' . count($r['images']) . ' תמונות עדיין באתר' : ' · התמונות בכלי');
    $rows['recordings'] = cutover_row('recordings', 'כל ההקלטות ותמונת הרקע "בכלי"', $ok ? 'ok' : 'bad', 'מחושב · הספרייה (A2)', $detail,
        $r['total'] === 0 ? 'אין עדיין הקלטות בכלי — ההסבה (F3) והבאת ההקלטות (A2)' : 'להביא את החסרות: 🗂️ קבצים ← 🌐 הבאה מהאתר. בלי זה העמוד החדש עדיין תלוי בקבצים של וורדפרס',
        ['missing' => $r['missing'], 'missing_n' => $r['missing_n'], 'images' => $r['images'], 'link' => '#/files']);

    // פרסום ראשון בפורמט החדש (M1)
    $b = $f['build'];
    $rows['build'] = cutover_row('build', 'פרסום בפורמט החדש, מהפרויקט כמו שהוא עכשיו', $b && $b['current'] ? 'ok' : 'bad', 'מחושב · 📁 פרסום מהפרויקט (M1)',
        $b ? "גרסה {$b['version']} · {$b['sent_by']} · " . $b['sent_at'] . ($b['current'] ? '' : " · הפרויקט השתנה מאז (rev {$b['rev']} ← {$f['rev']})") : 'עוד לא נשלחה גרסה בפורמט החדש',
        $b ? 'לבנות ולשלוח שוב — "🏗️ בנה גרסה מהפרויקט" למעלה' : 'לבנות ולשלוח — "🏗️ בנה גרסה מהפרויקט" למעלה', ['sent_at' => $b['sent_at'] ?? null]);

    // התוסף (M2)
    $p = $f['ping'];
    if (isset($p['error'])) {
        $rows['plugin'] = cutover_row('plugin', 'התוסף 2.0 באתר, עם גרסה בפורמט החדש', 'wait', 'מחושב · ping לאתר (M2)', 'האתר לא ענה: ' . $p['error'],
            'לבדוק את החיבור לאתר (⚙️ הגדרות ← בדיקת חיבור), ולפתוח שוב');
    } else {
        $parts = ['תוסף ' . ($p['plugin'] ?: '?'), 'protocol ' . $p['protocol'], $p['v2_live'] ? "v2 באוויר: גרסה {$p['v2_live']}" : 'אין v2 באוויר', 'המפסק: ' . ($p['v2_on'] ? 'דולק' : 'כבוי')];
        $todo = '';
        if ($p['protocol'] < 2) $todo = 'להתקין את התוסף 2.0 (מטלה לבעלים: 1.x ← 2.0)';
        elseif (!$p['v2_live']) $todo = 'לשלוח גרסה בפורמט החדש — עד אז אין לתוסף מה להגיש';
        elseif ($b && $p['v2_live'] !== $b['version']) $todo = "באתר גרסה {$p['v2_live']}, ובכלי האחרונה שנשלחה היא {$b['version']} — לשלוח שוב";
        $rows['plugin'] = cutover_row('plugin', 'התוסף 2.0 באתר, עם גרסה בפורמט החדש', $todo === '' ? 'ok' : 'bad', 'מחושב · ping לאתר (M2)', implode(' · ', $parts), $todo,
            ['on' => $p['v2_on']]);
    }

    // המשוב (M5)
    $fb = $f['feedback'];
    if ($fb['inbox'] === null) {
        $rows['feedback'] = cutover_row('feedback', 'המשוב מגיע לתיבת הפניות', 'wait', 'מחושב · תיבת הפניות (M5)', 'תיבת הפניות עוד לא בנויה', 'ממתין לפרוסה M5');
    } else {
        $live = $fb['live'] ?? [];
        $missing = array_values(array_diff($fb['forms'], $live));
        $ok = $fb['forms'] && !$missing;
        $todo = !$fb['forms'] ? 'להוסיף לטופס המשוב בפרויקט data-kol-form'
            : 'לבנות ולשלוח — הגרסה שבאתר אינה רושמת את ' . implode(', ', $missing) . ', ולכן פנייה ממנו לא תתקבל';
        $rows['feedback'] = cutover_row('feedback', 'המשוב מגיע לתיבת הפניות', $ok ? 'ok' : 'bad', 'מחושב · תיבת הפניות (M5)',
            ($fb['forms'] ? 'טפסים בפרויקט: ' . implode(', ', $fb['forms']) : 'אין בפרויקט טופס עם data-kol-form') . ' · ' .
            ($live ? 'רשומים בגרסה שבאתר: ' . implode(', ', $live) : 'הגרסה שבאתר אינה רושמת טופס') . ' · ' . ($fb['inbox']['detail'] ?? ''), $todo);
    }

    // גיבוי ונקודת שחזור
    $s = $f['snap'];
    $fresh = fn(?string $at) => $at !== null && $f['now'] - strtotime($at) <= CUTOVER_SNAP_HOURS * 3600;
    $v = $f['snap_verified'];
    $snapOk = $s && ($fresh($s['at']) || (is_array($v) && (int)($v['id'] ?? 0) === $s['id'] && $fresh($v['at'] ?? null)));
    $rows['backup'] = cutover_row('backup', 'גיבוי מלא ונקודת שחזור מהשעות האחרונות', $snapOk ? 'ok' : 'bad', 'מחושב · 🛟 נקודות שחזור',
        $s ? "נקודה #{$s['id']} · " . $s['at'] . ($snapOk && !$fresh($s['at']) ? ' · אומתה כזהה למצב הנוכחי ב-' . $v['at'] : '') : 'אין נקודת שחזור',
        'ללחוץ "🛟 צור עכשיו" (ולהוריד גיבוי מלא)', ['snap' => $s]);

    // ראיה מהבעלים
    $ev = [
        // הכרעת הבקרה (2026-11-12): מול האתר האמיתי — אישור הבעלים עם צילומים, כמו ב-F3 (Cloudflare חוסם את הסקריפט מכאן)
        'site_compare' => ['השוואה מול האתר — אישור הבעלים עם צילומים (טלפון + מחשב)', 'לפתוח את העמוד החי ואת כתובת הבדיקה בטלפון ובמחשב, להשוות (שורות 09 §4), ולצרף לפחות שני צילומים — טלפון ומחשב — עם הערה מה נבדק'],
        'attack_kit' => ['ערכת ההתקפה ירוקה, בשלושה דפדפנים (M2 §4)', 'להריץ את ערכת ההתקפה על כתובת הבדיקה ב-Chrome, Firefox ו-Safari, ולצרף צילום / קישור'],
        'q15' => ['[ש-15] ההשוואה מול וורדפרס הוכרעה', 'לכתוב את ההכרעה על כל הבדל (06 §5), או לקשר אליה'],
        'q4' => ['[ש-4] הוכרע מה שהאתר מציג בכל עמוד', 'לכתוב מה הוכרע: מה עובר לעמוד, ומי בונה. ⚠️ רכיבים שהאתר מציג היום בכל עמוד (נגישות, עוגיות, סטטיסטיקה) **אינם** בעמוד החדש עד שבעל העמוד מוסיף אותם בעצמו'],
        'owner_checklist' => ['רשימת הבדיקה של הבעלים — החלק של העמוד החדש', 'לעבור על acceptance/owner-checklist.md בכתובת הבדיקה, ולאשר'],
    ];
    foreach ($ev as $k => [$title, $todo]) {
        $list = $f['evidence'][$k] ?? [];
        $last = $list[0] ?? null;
        $ok = (bool)$last;
        $detail = $last ? 'אושר ע"י ' . $last['by'] . ' · ' . $last['at'] : 'אין עדיין ראיה';
        if ($k === 'site_compare') {
            $shots = count(array_filter($list, fn($e) => $e['file'] && !$e['file']['missing']));
            $ok = $shots >= CUTOVER_SITE_SHOTS;
            if ($last) $detail .= " · $shots צילומים" . ($ok ? '' : ' (נדרשים ' . CUTOVER_SITE_SHOTS . ': טלפון ומחשב)');
        }
        $rows[$k] = cutover_row($k, $title, $ok ? 'ok' : 'bad', 'ראיה מהבעלים', $detail, $todo, ['evidence' => $list, 'evidence_kind' => true]);
    }

    $rows = array_map(fn($k) => $rows[$k], CUTOVER_ORDER);
    $ready = !array_filter($rows, fn($r) => $r['status'] !== 'ok');
    return ['rows' => $rows, 'ready' => $ready, 'on' => !isset($p['error']) && !empty($p['v2_on']), 'v2_live' => $p['v2_live'] ?? 0, 'stats' => $p['stats'] ?? null];
}

// ── המעקב: כל שינוי — ביומן; ✓ שנפל — אדום, והודעה לבעלים ─────

function cutover_track(array $user, array $ev): array
{
    $pdo = db();
    $marks = [];
    foreach ($pdo->query('SELECT * FROM cutover_marks')->fetchAll() as $m) $marks[$m['cond']] = $m;
    $now = now_iso();
    $set = $pdo->prepare('INSERT INTO cutover_marks (cond, status, since, fell_at, detail) VALUES (?, ?, ?, ?, ?)
        ON CONFLICT(cond) DO UPDATE SET status = excluded.status, since = excluded.since, fell_at = excluded.fell_at, detail = excluded.detail');
    $sys = ['sub' => 'system', 'name' => 'המעבר'];
    $fell = [];
    foreach ($ev['rows'] as &$r) {
        $m = $marks[$r['key']] ?? null;
        $fellAt = $m['fell_at'] ?? null;
        // "⏳" (האתר לא ענה, פרוסה שטרם נבנתה) — אינו אירוע: המצב הקודם נשמר, ואין "נפל"
        if ($r['status'] === 'wait') { $r['fell_at'] = null; continue; }
        if (!$m || $m['status'] !== $r['status']) {
            if ($m && $m['status'] === 'ok') {
                $fellAt = $now;
                notice_add('owner', 'cutover', "🔁 המעבר: התנאי \"{$r['title']}\" היה ✓ ונפל — " . $r['detail'], null, $sys);
            }
            if ($r['status'] === 'ok') $fellAt = null;
            if ($m || $r['status'] === 'ok') audit_as($sys, 'cutover_cond', "{$r['title']}: " . ($m['status'] ?? '—') . " ← {$r['status']} · " . $r['detail']);
            $set->execute([$r['key'], $r['status'], $now, $fellAt, mb_substr($r['detail'], 0, 300)]);
        }
        $r['fell_at'] = $fellAt;
        if ($fellAt && $r['status'] !== 'ok') $fell[] = $r['key'];
    }
    unset($r);
    $ev['fell'] = $fell;

    // המפסק: נקרא בלבד. "באוויר מאז" — הפעם הראשונה שה-ping החזיר דולק
    $on = json_decode((string)setting('cutover_on', 'null'), true);
    if ($ev['on'] && !$on) {
        $on = ['since' => $now, 'version' => $ev['v2_live']];
        setting_set('cutover_on', json_encode($on));
        audit_as($sys, 'cutover_on', "האתר מדווח: העמוד החדש דולק · גרסה {$ev['v2_live']}");
        notice_add('owner', 'cutover', "🔁 העמוד החדש דולק באתר (גרסה {$ev['v2_live']}). עכשיו — הבדיקה המיידית שבמטלה", null, $sys);
    } elseif (!$ev['on'] && $on && !isset($ev['ping_error'])) {
        setting_set('cutover_on', 'null');
        audit_as($sys, 'cutover_off', 'האתר מדווח: העמוד החדש כבוי — הגולשים רואים את העמוד המקורי');
        $on = null;
    }
    $ev['live'] = $on;
    return $ev;
}

// ── הפעולות ──────────────────────────────────────────────

/** המסך. קריאה — מפרסם ומעלה; השינויים — בעלים בלבד (can_edit). */
function cutover_state(array $user, ?array $ping = null): array
{
    $f = cutover_facts($ping);
    $ev = cutover_eval($f);
    if (isset($f['ping']['error'])) $ev['ping_error'] = $f['ping']['error'];
    $ev = cutover_track($user, $ev);
    $task = setting('cutover_task');
    return $ev + [
        'can_edit' => $user['role'] === 'owner',
        'task' => $task ?: null,
        'instruction' => CUTOVER_SWITCH_PATH,
        'kill_rule' => CUTOVER_KILL_RULE,
        'immediate' => cutover_steps()[3],
    ];
}

function cutover_evidence_add(array $owner, array $b): array
{
    $cond = (string)($b['cond'] ?? '');
    if (!in_array($cond, CUTOVER_EVIDENCE, true)) throw new ApiError('תנאי לא מוכר, או תנאי שהכלי מחשב בעצמו', 422);
    if (($b['confirm'] ?? null) !== true) throw new ApiError('צריך לסמן "אני מאשר/ת"', 422);
    $note = str($b['note'] ?? '', 2000);
    $url = trim((string)($b['url'] ?? ''));
    if ($url !== '' && (!preg_match('#^https?://[^\s<>"]+$#i', $url) || mb_strlen($url) > 500)) throw new ApiError('קישור — רק http או https', 422);
    $fid = trim((string)($b['file_id'] ?? ''));
    if ($fid !== '') {
        if (!preg_match('/^[0-9a-f]{32}$/', $fid)) throw new ApiError('קובץ לא מוכר', 422);
        $f = file_row($fid);
        if (!empty($f['deleted_at'])) throw new ApiError('הקובץ בסל', 422);
    }
    if ($note === '' && $url === '' && $fid === '') throw new ApiError('ראיה: קובץ, קישור או הערה — לפחות אחד', 422);
    db()->prepare('INSERT INTO cutover_evidence (cond, note, url, file_id, by_sub, by_name, at) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$cond, $note, $url, $fid ?: null, $owner['sub'], $owner['name'] ?? '', now_iso()]);
    audit_as($owner, 'cutover_evidence', "ראיה ל-$cond" . ($fid ? ' · קובץ' : '') . ($url ? " · $url" : '') . ($note ? ' · ' . mb_substr($note, 0, 120) : ''));
    return cutover_state($owner);
}

function cutover_evidence_revoke(array $owner, array $b): array
{
    $id = (int)($b['id'] ?? 0);
    $st = db()->prepare('UPDATE cutover_evidence SET revoked_at = ?, revoked_by = ? WHERE id = ? AND revoked_at IS NULL');
    $st->execute([now_iso(), $owner['name'] ?? '', $id]);
    if (!$st->rowCount()) throw new ApiError('הראיה לא נמצאה', 404);
    $cond = (string)db()->query("SELECT cond FROM cutover_evidence WHERE id = $id")->fetchColumn();
    audit_as($owner, 'cutover_evidence_revoke', "ראיה #$id ל-$cond בוטלה");
    return cutover_state($owner);
}

/** "🛟 צור עכשיו". מצב זהה לנקודה האחרונה — אין נקודה שנייה; הנקודה הקיימת מאומתת כזהה למצב עכשיו. */
function cutover_snapshot(array $owner): array
{
    $id = snapshot_create($owner, 'לפני המעבר (M3)', 'manual');
    setting_set('cutover_snap_verified', json_encode(['id' => $id, 'at' => now_iso()]));
    audit_as($owner, 'snapshot', "נקודה #$id · לפני המעבר");
    return cutover_state($owner);
}

/** הצעדים של §2 "ההדלקה" — המטלה שנפתחת מ"מוכן למעבר". */
function cutover_steps(): array
{
    return [
        '1. פרסום אחרון מהכלי: "🏗️ בנה גרסה מהפרויקט" ← שליחה. הגולשים עדיין רואים את העמוד הישן',
        '2. כתובת הבדיקה באתר: השוואה מול העמוד החי בטלפון ובמחשב, עם צילומים (שורות 09 §4) + ערכת ההתקפה — על האתר האמיתי',
        '3. בעל האתר: ' . CUTOVER_SWITCH_PATH . ' (אישור כפול; המטמון מתנקה)',
        '4. בדיקה מיידית: הכתובת האמיתית מול הצילומים של צעד 2 (טלפון + מחשב) — אותה תמונה; ובדיקה ידנית קצרה: נטען, חיפוש, השמעה, משוב',
        '5. אחרי יום: מונה ה-404 והשגיאות בעמוד התוסף, תיבת הפניות, ו"הצג מקור" של העמוד — המילים בקוד המקור',
    ];
}

/** "מוכן למעבר": רק כשכל השורות ✓ (נבדק כאן, לא בממשק). אינו מדליק דבר — פותח מטלה ומחזיר את ההוראה. */
function cutover_ready(array $owner): array
{
    $s = cutover_state($owner);
    if (!$s['ready']) {
        $bad = array_map(fn($r) => $r['title'], array_filter($s['rows'], fn($r) => $r['status'] !== 'ok'));
        throw new ApiError('עוד לא מוכן למעבר: ' . implode(' · ', $bad), 409, ['code' => 'not_ready']);
    }
    return cutover_open_task($owner) + ['state' => cutover_state($owner)];
}

/** המטלה — פעם אחת: מטלה פתוחה קיימת חוזרת כמו שהיא. */
function cutover_open_task(array $owner): array
{
    dev_setup();                                    // DevLoop — כמו ב-dev_route (idempotent)
    $code = setting('cutover_task');
    if ($code) {
        try {
            $t = devloop_task_get($code);
            if (!in_array($t['status'] ?? '', ['closed'], true)) return ['code' => $code, 'instruction' => CUTOVER_SWITCH_PATH, 'existing' => true];
        } catch (Throwable) { /* נמחקה — פותחים חדשה */ }
    }
    $r = devloop_task_create(dev_user($owner), [
        'title' => '🔁 המעבר: העמוד החדש עולה לאוויר באתר', 'kind' => 'task', 'priority' => 'high', 'area' => 'M3',
        'detail' => "כל התנאים במסך \"🔁 מעבר\" ✓. הכלי אינו מדליק דבר: בעל האתר מדליק בעמוד התוסף — " . CUTOVER_SWITCH_PATH . ".\n\n⚠️ כלל הכיבוי: " . CUTOVER_KILL_RULE,
        'steps' => cutover_steps(),
        'acceptance' => 'צעד 4 עבר (אותן תוצאות כמו בכתובת הבדיקה), וצעד 5 — אחרי יום — בלי 404 ובלי שגיאות חדשות.',
    ]);
    setting_set('cutover_task', $r['code']);
    audit_as($owner, 'cutover_ready', "מוכן למעבר · נפתחה מטלה {$r['code']}");
    return ['code' => $r['code'], 'instruction' => CUTOVER_SWITCH_PATH, 'existing' => false];
}
