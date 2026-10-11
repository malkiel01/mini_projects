<?php
/**
 * הניתוח היומי של הטעם — יומן השימוש של הלקוחות, ובחזרה: מה כל לקוח אוהב.
 *
 *   המסלול: צ'אט של קלוד עם שעון (Routine, פעם ביום) קורא את היצוא דרך טוקן היומן
 *   (logs.php?view=taste), מנתח לכל לקוח את המכנה המשותף של מה שחיפש, שמר, פתח, בישל
 *   והסיר — "אוהב בשר", "אוהב קליל ומהיר", "אוהב מיוחד" — ומחזיר (logs.php?view=taste-import)
 *   תקציר, משקלות ובחירות. בשבילך (lib/foryou.php) משתמש בהם מהרגע שנכתבו.
 *
 *   בלי קרדיט: הניתוח הוא של הצ'אט, לא של ה-API. ובלי גיט: הריפו ציבורי, וטעם של לקוח
 *   הוא מידע אישי — הוא עובר ישירות לשרת, ולא נשמר בשום קובץ בריפו.
 *
 *   פרטיות: רק לקוחות שהתאמה אישית דלוקה אצלם. ביצוא — מזהה בלבד, בלי שם ובלי דוא"ל.
 *   הלקוח רואה את התקציר ב"בשבילך" ויכול לאפס; כיבוי ההתאמה האישית מוחק הכול.
 *   ההוראות לצ'אט: tools/daily-taste.md.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/errors.php';
require_once __DIR__ . '/recipes.php';
require_once __DIR__ . '/catalog.php';
require_once __DIR__ . '/privacy.php';
require_once __DIR__ . '/foryou.php';
require_once __DIR__ . '/log.php';

const TASTE_VERSION      = 1;
const TASTE_DAYS         = 90;     // כמה אחורה ביומן
const TASTE_MAX_EVENTS   = 250;    // ללקוח, החדשים ראשונים
const TASTE_MAX_USERS    = 300;
const TASTE_REFRESH_DAYS = 7;      // גם בלי פעילות חדשה — פעם בשבוע (המועמדים מתחלפים)
const TASTE_CANDIDATES   = 40;
const TASTE_MAX_PICKS    = 10;
const TASTE_TRAITS = [
    'quick'   => 'קליל ומהיר: עד חצי שעה, קל, מעט רכיבים (1) — מול מושקע וארוך (-1)',
    'special' => 'מיוחד ומפתיע: מטבח זר, שילוב לא שגרתי, אירוח (1) — מול ביתי ומוכר (-1)',
];

// ─────────────────────────────────────────────────────────────
// יצוא — מה הצ'אט קורא
// ─────────────────────────────────────────────────────────────

/** מתכון בקצרה: שם, תגים, זמן, קושי, רכיבים — מזה מזהים "בשרי", "קליל", "מיוחד". */
function tasteRecipeBrief(int $rid): ?array {
    static $cache = [];
    if (array_key_exists($rid, $cache)) return $cache[$rid];
    $st = db()->prepare('SELECT id, title, difficulty, work_minutes, wait_minutes FROM recipes WHERE id = ?');
    $st->execute([$rid]);
    $r = $st->fetch();
    $st->closeCursor();
    if (!$r) return $cache[$rid] = null;
    $st = db()->prepare('SELECT t.name FROM recipe_tags rt JOIN tags t ON t.id = rt.tag_id WHERE rt.recipe_id = ?');
    $st->execute([$rid]);
    $tags = $st->fetchAll(PDO::FETCH_COLUMN);
    $st = db()->prepare('SELECT COALESCE(p.name, i.free_text) FROM ingredients i JOIN sections s ON s.id = i.section_id
                           LEFT JOIN products p ON p.id = i.product_id WHERE s.recipe_id = ? ORDER BY s.position, i.position LIMIT 12');
    $st->execute([$rid]);
    $ings = array_map(fn($x) => mb_substr((string) $x, 0, 30), $st->fetchAll(PDO::FETCH_COLUMN));
    $min = ($r['work_minutes'] !== null || $r['wait_minutes'] !== null) ? (int) $r['work_minutes'] + (int) $r['wait_minutes'] : null;
    return $cache[$rid] = array_filter(['title' => $r['title'], 'tags' => $tags, 'min' => $min, 'd' => $r['difficulty'], 'ings' => $ings],
                                        fn($v) => $v !== null && $v !== []);
}

function tasteEntryBrief(int $eid): ?array {
    $st = db()->prepare('SELECT e.title, s.name AS site FROM index_entries e JOIN index_sites s ON s.id = e.site_id WHERE e.id = ?');
    $st->execute([$eid]);
    $e = $st->fetch();
    $st->closeCursor();
    return $e ? ['title' => $e['title'], 'site' => $e['site'], 'web' => true] : null;
}

/** הלקוחות שיש מה לנתח אצלם: התאמה אישית דלוקה, ופעילות מאז הניתוח האחרון (או שבוע בלי ניתוח). */
function tasteUsersDue(): array {
    $since = gmdate('Y-m-d\TH:i:s\Z', time() - TASTE_DAYS * 86400);
    $refresh = gmdate('Y-m-d\TH:i:s\Z', time() - TASTE_REFRESH_DAYS * 86400);
    $st = db()->prepare("SELECT u.id,
                                (SELECT MAX(created_at) FROM user_events e WHERE e.user_id = u.id) AS last_event,
                                (SELECT MAX(ci.added_at) FROM collection_items ci JOIN collections c ON c.id = ci.collection_id WHERE c.owner_id = u.id) AS last_saved,
                                (SELECT MAX(updated_at) FROM recipes r WHERE r.owner_id = u.id) AS last_own,
                                a.updated_at AS analyzed_at
                           FROM users u LEFT JOIN user_prefs p ON p.user_id = u.id LEFT JOIN ai_taste a ON a.user_id = u.id
                          WHERE u.blocked = 0 AND u.email_verified = 1 AND COALESCE(p.personalize, 1) = 1");
    $st->execute();
    $due = [];
    foreach ($st->fetchAll() as $r) {
        $last = max((string) $r['last_event'], (string) $r['last_saved'], (string) $r['last_own']);
        if ($last === '' || $last < $since) continue;                         // אין פעילות ב-90 יום
        $an = (string) $r['analyzed_at'];
        if ($an !== '' && $last <= $an && $an > $refresh) continue;            // כבר נותח, ואין חדש
        $due[] = ['id' => (int) $r['id'], 'last' => $last];
    }
    usort($due, fn($a, $b) => strcmp($b['last'], $a['last']));
    return array_slice($due, 0, TASTE_MAX_USERS);
}

/** מה שהצ'אט קורא: לכל לקוח — היומן, מה ששמר ויצר, מה שהצהיר, הניתוח הקודם, ומועמדים לבחירה. */
function tasteExport(): array {
    $tagsByAxis = [];
    foreach (db()->query('SELECT axis, name FROM tags ORDER BY axis, name')->fetchAll() as $t) $tagsByAxis[$t['axis']][] = $t['name'];
    $topicName = array_flip(catalogTagIds('topic'));
    $dietName = array_flip(catalogTagIds('suitable'));
    $since = gmdate('Y-m-d\TH:i:s\Z', time() - TASTE_DAYS * 86400);

    $users = [];
    foreach (tasteUsersDue() as $due) {
        $uid = $due['id'];
        $user = db()->query("SELECT id, username, display_name, role FROM users WHERE id = $uid")->fetch();

        $st = db()->prepare('SELECT kind, recipe_id, entry_id, value, created_at FROM user_events WHERE user_id = ? AND created_at > ? ORDER BY created_at DESC, id DESC LIMIT ' . TASTE_MAX_EVENTS);
        $st->execute([$uid, $since]);
        $events = [];
        foreach ($st->fetchAll() as $e) {
            $row = ['at' => substr($e['created_at'], 0, 16), 'kind' => $e['kind']];
            if ($e['kind'] === 'less_topic') { $row['topic'] = $topicName[(int) $e['value']] ?? null; $events[] = $row; continue; }
            $val = $e['value'] !== null ? json_decode($e['value'], true) : null;
            if (is_array($val)) $row += $val;
            if ($e['recipe_id'] !== null && ($b = tasteRecipeBrief((int) $e['recipe_id']))) $row['recipe'] = $b;
            elseif ($e['entry_id'] !== null && ($b = tasteEntryBrief((int) $e['entry_id']))) $row['recipe'] = $b;
            $events[] = $row;
        }

        $st = db()->prepare('SELECT ci.recipe_id, ci.title, ci.site, c.name AS coll FROM collection_items ci JOIN collections c ON c.id = ci.collection_id
                              WHERE c.owner_id = ? ORDER BY ci.added_at DESC LIMIT 60');
        $st->execute([$uid]);
        $saved = [];
        foreach ($st->fetchAll() as $s) {
            $b = $s['recipe_id'] !== null ? tasteRecipeBrief((int) $s['recipe_id']) : ['title' => $s['title'], 'site' => $s['site'], 'web' => true];
            if ($b) $saved[] = $b + ['in' => $s['coll']];
        }
        $st = db()->prepare('SELECT id FROM recipes WHERE owner_id = ? ORDER BY updated_at DESC LIMIT 30');
        $st->execute([$uid]);
        $own = array_values(array_filter(array_map(fn($id) => tasteRecipeBrief((int) $id), $st->fetchAll(PDO::FETCH_COLUMN))));

        $taste = fyTaste($user);
        $prev = aiTaste($uid);
        // מועמדים: מה שבשבילך היה מציע עכשיו (בלי המלצות מחברים) — מהם בוחרים
        $cands = [];
        foreach (forYou($user, TASTE_CANDIDATES)['items'] as $c) {
            if ($c['kind'] === 'reco') continue;
            $key = $c['kind'] === 'web' ? 'w:' . $c['entry_id'] : 'r:' . $c['id'];
            $cands[] = array_filter(['key' => $key, 'title' => $c['title'], 'by' => $c['by'], 'topic' => $c['topic'],
                                     'recipe' => $c['kind'] === 'recipe' ? tasteRecipeBrief((int) $c['id']) : null], fn($v) => $v !== null);
        }

        $users[] = [
            'user_id'   => $uid,
            'declared'  => $taste ? ['topics' => array_values(array_filter(array_map(fn($t) => $topicName[$t] ?? null, $taste['topics']))),
                                     'diet' => array_values(array_filter(array_map(fn($t) => $dietName[$t] ?? null, $taste['diet'])))] : null,
            'previous'  => $prev ? ['summary' => $prev['summary'], 'profile' => $prev['profile'], 'at' => $prev['updated_at']] : null,
            'own'       => $own,
            'saved'     => $saved,
            'events'    => $events,
            'candidates'=> $cands,
        ];
    }
    return [
        'version'      => TASTE_VERSION,
        'generated_at' => nowIso(),
        'instructions' => 'recipes-app/tools/daily-taste.md',
        'vocabulary'   => ['tags' => $tagsByAxis, 'traits' => TASTE_TRAITS],
        'limits'       => ['summary' => 160, 'tags' => 25, 'keywords' => 12, 'avoid' => 12, 'picks' => TASTE_MAX_PICKS, 'reason' => 80],
        'users'        => $users,
    ];
}

// ─────────────────────────────────────────────────────────────
// יבוא — מה הצ'אט מחזיר
// ─────────────────────────────────────────────────────────────

/** טקסט קצר ונקי: בלי תגיות, בלי שורות, עד $max תווים. */
function tasteText($v, int $max): string {
    $s = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $v)));
    return mb_substr($s, 0, $max);
}

/** מילים לחיפוש בשם: 2–30 תווים, בלי כפולים. */
function tasteWords($list, int $max): array {
    $out = [];
    foreach (is_array($list) ? $list : [] as $w) {
        $w = tasteText($w, 30);
        if (mb_strlen($w) >= 2 && !in_array($w, $out, true)) $out[] = $w;
        if (count($out) >= $max) break;
    }
    return $out;
}

/**
 * מחיל את מה שהצ'אט החזיר. כל לקוח בנפרד — שגיאה אצל אחד לא עוצרת את האחרים.
 * מחזיר: updated, skipped [{user_id, reason}], warnings [].
 */
function tasteImport(array $data): array {
    if ((int) ($data['version'] ?? 0) !== TASTE_VERSION) throw new AppError('גרסה לא מוכרת — צפוי version ' . TASTE_VERSION);
    if (!is_array($data['users'] ?? null)) throw new AppError('חסר users');
    $allTags = [];
    foreach (db()->query('SELECT name FROM tags')->fetchAll(PDO::FETCH_COLUMN) as $n) $allTags[$n] = true;

    $updated = 0; $skipped = []; $warnings = [];
    foreach (array_slice($data['users'], 0, TASTE_MAX_USERS) as $u) {
        $uid = (int) ($u['user_id'] ?? 0);
        $user = $uid ? db()->query("SELECT id, username, display_name, role, blocked FROM users WHERE id = $uid")->fetch() : null;
        if (!$user || (int) $user['blocked'] === 1) { $skipped[] = ['user_id' => $uid, 'reason' => 'אין לקוח כזה']; continue; }
        if (!userPrefs($uid)['personalize']) { $skipped[] = ['user_id' => $uid, 'reason' => 'התאמה אישית כבויה']; continue; }

        $p = is_array($u['profile'] ?? null) ? $u['profile'] : [];
        $tags = [];
        foreach (is_array($p['tags'] ?? null) ? $p['tags'] : [] as $name => $w) {
            if (!isset($allTags[$name])) { $warnings[] = "לקוח $uid: תג לא מוכר \"$name\" — דילגתי"; continue; }
            if (!is_numeric($w)) continue;
            $tags[$name] = max(-3.0, min(3.0, round((float) $w, 1)));
            if (count($tags) >= 25) break;
        }
        $profile = ['tags' => $tags];
        foreach (array_keys(TASTE_TRAITS) as $t) if (isset($p[$t]) && is_numeric($p[$t])) $profile[$t] = max(-1.0, min(1.0, round((float) $p[$t], 2)));
        $profile['keywords'] = tasteWords($p['keywords'] ?? [], 12);
        $profile['avoid'] = tasteWords($p['avoid'] ?? [], 12);

        $picks = [];
        foreach (is_array($u['picks'] ?? null) ? $u['picks'] : [] as $pk) {
            $key = (string) ($pk['key'] ?? '');
            if (!preg_match('/^([rw]):(\d+)$/', $key, $m)) { $warnings[] = "לקוח $uid: מפתח \"$key\" לא תקין"; continue; }
            if ($m[1] === 'r') {
                $st = db()->prepare('SELECT * FROM recipes WHERE id = ?');
                $st->execute([(int) $m[2]]);
                $r = $st->fetch();
                $st->closeCursor();
                if (!$r || !canView($r, $user) || (int) $r['owner_id'] === $uid) { $warnings[] = "לקוח $uid: $key אינו זמין לו"; continue; }
            } else {
                $st = db()->prepare('SELECT 1 FROM index_entries WHERE id = ? AND skip = 0');
                $st->execute([(int) $m[2]]);
                $ok = $st->fetchColumn();
                $st->closeCursor();
                if (!$ok) { $warnings[] = "לקוח $uid: $key אינו באינדקס"; continue; }
            }
            $picks[] = ['key' => $key, 'reason' => tasteText($pk['reason'] ?? '', 80)];
            if (count($picks) >= TASTE_MAX_PICKS) break;
        }

        db()->prepare('INSERT INTO ai_taste (user_id, summary, profile, picks, updated_at) VALUES (?,?,?,?,?)
                       ON CONFLICT(user_id) DO UPDATE SET summary = excluded.summary, profile = excluded.profile, picks = excluded.picks, updated_at = excluded.updated_at')
            ->execute([$uid, tasteText($u['summary'] ?? '', 160), json_encode($profile, JSON_UNESCAPED_UNICODE), json_encode($picks, JSON_UNESCAPED_UNICODE), nowIso()]);
        $updated++;
    }
    logEvent('info', 'taste-import', "ניתוח הטעם היומי: עודכנו $updated", ['updated' => $updated, 'skipped' => count($skipped), 'warnings' => count($warnings)]);
    return ['updated' => $updated, 'skipped' => $skipped, 'warnings' => array_slice($warnings, 0, 100)];
}
