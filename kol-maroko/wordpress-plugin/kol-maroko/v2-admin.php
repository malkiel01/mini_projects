<?php
/**
 * קול מרוקו 2.0 — עמוד התוסף (בעל האתר בלבד: manage_options + nonce של וורדפרס) ו"🔎 אבחון" המורחב (פרוסה M2 §2).
 *
 * המפסק "העמוד החדש" (כבוי כברירת מחדל), הגרסות השמורות עם "החזר לגרסה זו", הנפח, המכסות, ההגשה הישירה של מדיה
 * (כבויה; נדלקת רק אם הבדיקה העצמית מוכיחה את הכותרות), ומחיקה מפורשת של כל חבילות 2.0.
 *
 * הבדיקה העצמית היא הבקשה היחידה שהתוסף שולח, והיא **לאתר עצמו בלבד** (home_url, בלי הפניות): kol_maroko_v2_loopback
 * מסרבת לכל כתובת שהמארח שלה אינו המארח של האתר.
 */

if (!defined('ABSPATH')) exit;

const KOL_MAROKO_V2_AUDIO = ['mp3', 'm4a', 'ogg', 'opus', 'wav', 'webm', 'mp4'];

add_action('admin_post_kol_maroko_v2', function () {
    if (!current_user_can('manage_options')) wp_die('אין הרשאה');
    check_admin_referer('kol_maroko_v2');
    $op = sanitize_key($_POST['op'] ?? '');
    $msg = 'נשמר.';
    $pull = strpos($op, 'pull_') === 0 ? kol_maroko_pull_admin_op($op) : null;     // M7 — שורת החיבור והמשיכה (v2-pull.php)
    if ($pull !== null) {
        $msg = $pull;
    } elseif ($op === 'on') {
        // אישור כפול (M3 §2): הסימון בטופס, ומספר הגרסה שהאישור ניתן עליה — אם הגרסה שבאוויר התחלפה בינתיים, לא מדליקים
        $live = (int)kol_maroko_opt('v2_live', 0);
        if (!$live) $msg = 'אין גרסה 2.0 באוויר — אין מה להדליק.';
        elseif (empty($_POST['confirm']) || (int)($_POST['version'] ?? 0) !== $live) $msg = 'לא הודלק: חסר אישור, או שהגרסה שבאוויר התחלפה מאז שהמסך נטען.';
        else {
            kol_maroko_set('v2_on', true);
            kol_maroko_v2_stats_reset();
            kol_maroko_v2_purge();
            kol_maroko_v2_log('on', $live);
            $msg = 'העמוד החדש דולק — גרסה ' . $live . '.';
        }
    } elseif ($op === 'off') {                           // כיבוי — לחיצה אחת, בלי אישור (G7)
        kol_maroko_set('v2_on', false);
        kol_maroko_v2_purge();
        kol_maroko_v2_log('off', (int)kol_maroko_opt('v2_live', 0));
        $msg = 'העמוד החדש כבוי — האתר מגיש את העמוד כמו קודם.';
    } elseif ($op === 'preview_new') {
        // כתובת הבדיקה: 128 ביט אקראי. נשמר רק ה-hash; הכתובת מוצגת פעם אחת, בתשובה הזו — לא נשמרת בשום מקום
        $tok = bin2hex(random_bytes(16));
        kol_maroko_set('v2_preview_hash', hash('sha256', $tok));
        kol_maroko_v2_log('preview_new', (int)kol_maroko_opt('v2_live', 0));
        $url = home_url(kol_maroko_v2_scope()) . '?kol_preview=' . $tok;
        echo '<!doctype html><html lang="he" dir="rtl"><meta charset="utf-8"><title>כתובת בדיקה</title><body style="font:16px system-ui;margin:24px">'
            . '<h1>כתובת הבדיקה</h1><p>מוצגת <b>פעם אחת</b> — העתיקו אותה עכשיו. היא מציגה את העמוד החדש לפני שהגולשים רואים אותו; '
            . 'כפתור "כתובת חדשה" מבטל אותה.</p><p><input readonly dir="ltr" size="90" value="' . esc_attr($url) . '" onclick="this.select()"></p>'
            . '<p><a href="' . esc_url(admin_url('options-general.php?page=kol-maroko#kol-v2')) . '">חזרה לעמוד התוסף</a></p></body></html>';
        exit;
    } elseif ($op === 'preview_off') {
        delete_option('kol_maroko_v2_preview_hash');
        kol_maroko_v2_log('preview_off', 0);
        $msg = 'כתובת הבדיקה בוטלה.';
    } elseif ($op === 'activate') {
        $r = kol_maroko_v2_activate_n((int)($_POST['n'] ?? 0));
        if ($r === true) kol_maroko_v2_log('activate', (int)$_POST['n']);
        $msg = $r === true ? 'הגרסה ' . (int)$_POST['n'] . ' באוויר.' : 'לא הוחזר: ' . $r->data['message'];
    } elseif ($op === 'quota') {
        $q = [];
        foreach (KOL_MAROKO_V2_QUOTA_DEFAULT as $k => $d) {
            $v = (int)($_POST['q'][$k] ?? $d);
            $q[$k] = $v > 0 ? min($v, $k === 'files' ? 100000 : 100000) : $d;
        }
        kol_maroko_set('v2_quota', $q);
    } elseif ($op === 'direct') {
        $want = !empty($_POST['direct']);
        kol_maroko_set('v2_direct_media', $want);
        kol_maroko_v2_lock_files(true);
        if ($want) {                                      // רק אם הבדיקה העצמית מוכיחה שהשרת שולח את הכותרות — אחרת חוזר לכבוי
            $t = kol_maroko_v2_selftest();
            $direct = array_values(array_filter($t['rows'], fn($r) => $r['id'] === 'direct'));
            if (!$direct || !$direct[0]['ok']) {
                kol_maroko_set('v2_direct_media', false);
                kol_maroko_v2_lock_files(true);
                $msg = 'ההגשה הישירה לא הודלקה: השרת לא שלח את הכותרות הנדרשות (ראו "🔎 אבחון").';
            }
        }
    } elseif ($op === 'selftest') {
        kol_maroko_v2_selftest();
        $msg = 'הבדיקה העצמית הסתיימה.';
    } elseif ($op === 'wipe' && !empty($_POST['confirm'])) {
        kol_maroko_v2_wipe();
        $msg = 'כל חבילות 2.0 נמחקו. ההגדרות של 1.x לא נגעו.';
    }
    set_transient('kol_maroko_v2_msg', $msg, 120);
    wp_safe_redirect(admin_url('options-general.php?page=kol-maroko#kol-v2'));
    exit;
});

/** היומן של התוסף: מי, מתי, מה, איזו גרסה. 100 האחרונים. */
function kol_maroko_v2_log($op, $version)
{
    $u = function_exists('wp_get_current_user') ? wp_get_current_user() : null;
    $who = $u && !empty($u->user_login) ? sanitize_text_field((string)$u->user_login) : 'studio';
    $log = (array)kol_maroko_opt('v2_log', []);
    array_unshift($log, ['at' => time(), 'who' => substr($who, 0, 60), 'op' => (string)$op, 'version' => (int)$version]);
    kol_maroko_set('v2_log', array_slice($log, 0, 100));
}

/** המונה מתחיל מאפס בכל הדלקה. */
function kol_maroko_v2_stats_reset()
{
    $root = kol_maroko_v2_ensure();
    @file_put_contents("$root/stats.json", json_encode(kol_maroko_v2_stats_new()), LOCK_EX);
}

/** מחיקה מפורשת (כפתור בלבד — לא בהסרת התוסף): החבילות, המניפסטים והמצב של 2.0. */
function kol_maroko_v2_wipe()
{
    kol_maroko_set('v2_on', false);
    $root = kol_maroko_v2_dir();
    foreach (['blobs', 'parts'] as $d) foreach (glob("$root/$d/*") ?: [] as $f) if (is_file($f) && basename($f) !== 'index.html') @unlink($f);
    foreach (glob("$root/{manifest-*.json,live.json,live.json.tmp*,rate.json,publish.lock,stats.json}", GLOB_BRACE) ?: [] as $f) @unlink($f);
    kol_maroko_v2_log('wipe', 0);
    foreach (['v2_live', 'v2_last', 'v2_versions', 'v2_nonces', 'v2_selftest', 'v2_selftest_result', 'v2_purge', 'v2_preview_hash', 'v2_mount'] as $k) delete_option('kol_maroko_' . $k);
    kol_maroko_v2_lock_files(true);
    kol_maroko_v2_purge();
}

/** קטע 2.0 בעמוד ההגדרות. */
function kol_maroko_v2_admin_section()
{
    if (!current_user_can('manage_options')) return;
    $post = esc_url(admin_url('admin-post.php'));
    $nonce = wp_nonce_field('kol_maroko_v2', '_wpnonce', true, false) . '<input type="hidden" name="action" value="kol_maroko_v2">';
    $on = (bool)kol_maroko_opt('v2_on', false);
    $live = (int)kol_maroko_opt('v2_live', 0);
    $saved = kol_maroko_v2_saved();
    $info = (array)kol_maroko_opt('v2_versions', []);
    $q = kol_maroko_v2_quota();
    $msg = get_transient('kol_maroko_v2_msg');
    if ($msg) delete_transient('kol_maroko_v2_msg');

    echo '<h2 id="kol-v2">העמוד החדש (2.0)</h2>';
    if ($msg) echo '<div class="notice notice-info"><p>' . esc_html($msg) . '</p></div>';
    echo '<p>חבילה חתומה מהסטודיו, מוגשת בכתובת <code dir="ltr">' . esc_html(kol_maroko_v2_scope()) . '</code> לפני וורדפרס — בלי ערכת הנושא, בלי עוגיות, '
        . 'ובמקור אטום (ארגז חול). כל עוד המפסק כבוי, הגולשים רואים את העמוד כמו היום.</p>';
    $mount_names = ['full' => 'עמוד מלא (M-A) — העמוד הוא כל המסך', 'framed' => 'חלון בתוך האתר (M-C) — הכותרת והתחתית של האתר, והעמוד כחלון ביניהן',
        'bar' => 'פס האתר (M-B) — הכותרת של האתר, והעמוד ממלא את שאר המסך'];
    echo '<p id="kol-v2-mount">אופן ההצבה: <b>' . esc_html($mount_names[kol_maroko_v2_mount()]) . '</b> — נקבע מהכלי ("🔗 חיבור לאתר"). בכל אופן הקוד של העמוד '
        . 'רץ במקור אטום בלבד; בחלון — המעטפת היא קוד קבוע של התוסף.</p>';
    $info_live = (array)($info[$live] ?? []);
    $confirm_text = 'הגולשים יראו מעכשיו את הגרסה ' . $live . ', שפורסמה ב-' . (isset($info_live['at']) ? wp_date('d.m.Y H:i', (int)$info_live['at']) : '?')
        . ' ע"י ' . (string)($info_live['by'] ?? '?') . '. להדליק?';
    echo '<table class="form-table"><tr><th>המפסק</th><td><b>' . ($on ? '🟢 דולק' : '⚪ כבוי') . '</b>'
        . ($on && !$live ? ' — אבל עוד לא התקבלה גרסה, ולכן העמוד הישן מוגש.' : '');
    if ($on) {
        echo '<form method="post" action="' . $post . '" style="display:inline;margin-inline-start:12px">' . $nonce
            . '<input type="hidden" name="op" value="off"><button class="button">כיבוי — חזרה לעמוד הישן</button></form>';
    } elseif ($live) {
        echo '<form method="post" action="' . $post . '" style="margin-top:8px" onsubmit="return confirm(this.dataset.q)" data-q="' . esc_attr($confirm_text) . '">' . $nonce
            . '<input type="hidden" name="op" value="on"><input type="hidden" name="version" value="' . (int)$live . '">'
            . '<label><input type="checkbox" name="confirm" value="1" required> ' . esc_html($confirm_text) . '</label> '
            . '<button class="button button-primary">הדלקה</button></form>';
    }
    echo '</td></tr>';
    echo '<tr><th>באוויר</th><td>' . ($live ? 'גרסה ' . $live : 'אין') . ' · האחרונה שהתקבלה: ' . (int)kol_maroko_opt('v2_last', 0)
        . ' · נפח: ' . esc_html(size_format(kol_maroko_v2_usage())) . ' · פרוטוקול ' . KOL_MAROKO_PROTOCOL . '</td></tr></table>';

    // כתובת הבדיקה
    echo kol_maroko_pull_admin_html($post, $nonce);     // M7 — שורת החיבור והמשיכה
    echo '<h3>כתובת בדיקה</h3><p>העמוד החדש, באתר האמיתי, לפני שהגולשים רואים אותו (רק כשהמפסק כבוי). הכתובת מוצגת פעם אחת כשנוצרת; '
        . 'מה שנשמר כאן — רק טביעה שלה. ' . (kol_maroko_opt('v2_preview_hash', '') ? '<b>יש כתובת פעילה.</b>' : 'אין כתובת פעילה.') . '</p>'
        . '<form method="post" action="' . $post . '" style="display:inline">' . $nonce . '<input type="hidden" name="op" value="preview_new"><button class="button">'
        . (kol_maroko_opt('v2_preview_hash', '') ? 'כתובת חדשה (הקודמת מתבטלת)' : 'יצירת כתובת בדיקה') . '</button></form> ';
    if (kol_maroko_opt('v2_preview_hash', '')) {
        echo '<form method="post" action="' . $post . '" style="display:inline">' . $nonce . '<input type="hidden" name="op" value="preview_off"><button class="button">ביטול הכתובת</button></form>';
    }

    // המונה מאז ההדלקה
    $st = kol_maroko_v2_stats();
    if ($on && $st) {
        echo '<h3>מאז ההדלקה (' . esc_html(wp_date('d.m.Y H:i', (int)$st['since'])) . ')</h3><p>בקשות: ' . (int)$st['requests'] . ' · 404: ' . (int)$st['not_found']
            . ' · שגיאות: ' . (int)$st['errors'] . '</p>';
        foreach (['last404' => '404 אחרונים', 'last_errors' => 'שגיאות אחרונות'] as $k => $label) {
            if (empty($st[$k])) continue;
            echo '<details><summary>' . esc_html($label) . '</summary><ul dir="ltr">';
            foreach ((array)$st[$k] as $row) echo '<li><code>' . esc_html((string)($row['path'] ?? '')) . '</code> · ' . esc_html(wp_date('d.m H:i', (int)($row['at'] ?? 0))) . '</li>';
            echo '</ul></details>';
        }
    }

    // היומן
    $log = (array)kol_maroko_opt('v2_log', []);
    if ($log) {
        $names = ['on' => 'הדלקה', 'off' => 'כיבוי', 'activate' => 'חזרה לגרסה', 'preview_new' => 'כתובת בדיקה חדשה', 'preview_off' => 'ביטול כתובת בדיקה', 'wipe' => 'מחיקת החבילות'];
        echo '<details><summary>יומן (' . count($log) . ')</summary><table class="widefat striped" style="max-width:700px"><tbody>';
        foreach (array_slice($log, 0, 30) as $row) {
            echo '<tr><td>' . esc_html(wp_date('d.m.Y H:i', (int)($row['at'] ?? 0))) . '</td><td>' . esc_html((string)($row['who'] ?? '')) . '</td><td>'
                . esc_html($names[$row['op'] ?? ''] ?? (string)($row['op'] ?? '')) . '</td><td>' . ((int)($row['version'] ?? 0) ?: '') . '</td></tr>';
        }
        echo '</tbody></table></details>';
    }

    if ($saved) {
        echo '<table class="widefat striped" style="max-width:760px"><thead><tr><th>גרסה</th><th>מתי</th><th>מי</th><th>קבצים</th><th>נפח</th><th></th></tr></thead><tbody>';
        foreach ($saved as $n => $f) {
            $m = (array)($info[$n] ?? []);
            echo '<tr><td>' . (int)$n . ($n === $live ? ' <b>(באוויר)</b>' : '') . '</td><td>' . esc_html(isset($m['at']) ? wp_date('d.m.Y H:i', (int)$m['at']) : '') . '</td><td>'
                . esc_html((string)($m['by'] ?? '')) . '</td><td>' . (int)($m['files'] ?? 0) . '</td><td>' . esc_html(size_format((int)($m['bytes'] ?? 0))) . '</td><td>';
            if ($n !== $live) {
                echo '<form method="post" action="' . $post . '" style="margin:0">' . $nonce . '<input type="hidden" name="op" value="activate"><input type="hidden" name="n" value="' . (int)$n
                    . '"><button class="button">החזר לגרסה זו</button></form>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }

    echo '<h3>מכסות</h3><form method="post" action="' . $post . '">' . $nonce . '<input type="hidden" name="op" value="quota"><p>';
    foreach (['file_mb' => 'קובץ (MB)', 'total_mb' => 'חבילה (MB)', 'files' => 'קבצים בחבילה', 'keep' => 'גרסאות שמורות', 'rate' => 'בקשות קבלה בדקה'] as $k => $label) {
        echo '<label style="margin-inline-end:14px">' . esc_html($label) . ' <input type="number" min="1" name="q[' . esc_attr($k) . ']" value="' . (int)$q[$k] . '" style="width:90px"></label>';
    }
    echo '</p>';
    submit_button('שמירת המכסות', 'secondary', 'submit', false);
    echo '</form>';

    $direct = (bool)kol_maroko_opt('v2_direct_media', false);
    echo '<h3>הגשה ישירה של מדיה (שיפור ביצועים)</h3><form method="post" action="' . $post . '">' . $nonce . '<input type="hidden" name="op" value="direct">'
        . '<label><input type="checkbox" name="direct" value="1"' . checked($direct, true, false) . '> השרת מגיש בעצמו שמע, תמונות וגופנים</label>'
        . '<p class="description">כבוי כברירת מחדל — התוסף מגיש הכול, כולל Range. נדלק רק אם הבדיקה העצמית מוכיחה שהשרת שולח את כותרות הבידוד.</p>';
    submit_button('שמירה', 'secondary', 'submit', false);
    echo '</form>';

    echo '<h3>מחיקת כל חבילות 2.0</h3><form method="post" action="' . $post . '">' . $nonce . '<input type="hidden" name="op" value="wipe">'
        . '<label><input type="checkbox" name="confirm" value="1" required> כן, למחוק את כל הגרסות של 2.0 (ההגדרות והגרסות של 1.x נשארות)</label> ';
    submit_button('מחיקה', 'delete', 'submit', false);
    echo '</form>';
}

/** קטע 2.0 בדוח האבחון. */
function kol_maroko_v2_diagnose()
{
    return ['protocol' => KOL_MAROKO_PROTOCOL, 'on' => (bool)kol_maroko_opt('v2_on', false), 'live' => (int)kol_maroko_opt('v2_live', 0),
        'last' => (int)kol_maroko_opt('v2_last', 0), 'last_v1' => (int)kol_maroko_opt('last', 0), 'scope' => kol_maroko_v2_scope(),
        'saved' => array_keys(kol_maroko_v2_saved()), 'bytes' => kol_maroko_v2_usage(), 'quota' => kol_maroko_v2_quota(),
        'direct_media' => (bool)kol_maroko_opt('v2_direct_media', false), 'purge' => kol_maroko_opt('v2_purge', null),
        'selftest' => kol_maroko_opt('v2_selftest_result', null)];
}

/** בקשה לאתר עצמו בלבד. מחזיר [status, headers (lowercase → string|array)] או null. */
function kol_maroko_v2_loopback($url, array $headers = [])
{
    $host = wp_parse_url(home_url(), PHP_URL_HOST);
    if (!$host || wp_parse_url($url, PHP_URL_HOST) !== $host) return null;
    $r = wp_remote_request($url, ['method' => 'GET', 'timeout' => 15, 'redirection' => 0, 'headers' => $headers, 'limit_response_size' => 65536]);
    if (is_wp_error($r)) return ['error' => $r->get_error_message()];
    $h = [];
    foreach (wp_remote_retrieve_headers($r) as $k => $v) $h[strtolower($k)] = $v;
    return ['status' => (int)wp_remote_retrieve_response_code($r), 'headers' => $h];
}

/** כל הכותרות של I1–I7 מול מה שיצא בפועל. מחזיר [ok, פירוט]. */
function kol_maroko_v2_check_headers(array $h, $type)
{
    $csp = (string)($h['content-security-policy'] ?? '');
    $bad = [];
    if (strpos($csp, 'sandbox') !== 0 || strpos($csp, 'allow-same-origin') !== false || strpos($csp, 'allow-top-navigation') !== false) $bad[] = 'CSP: ' . ($csp ?: 'חסר');
    if (strtolower((string)($h['x-content-type-options'] ?? '')) !== 'nosniff') $bad[] = 'nosniff חסר';
    if ((string)($h['access-control-allow-origin'] ?? '') !== '*') $bad[] = 'ACAO חסר';
    if ((string)($h['cross-origin-resource-policy'] ?? '') !== 'cross-origin') $bad[] = 'CORP חסר';
    if (isset($h['set-cookie'])) $bad[] = 'יש Set-Cookie!';
    if ($type !== null && stripos((string)($h['content-type'] ?? ''), $type) !== 0) $bad[] = 'Content-Type: ' . ($h['content-type'] ?? 'חסר');
    return [!$bad, $bad ? implode(' · ', $bad) : 'CSP sandbox · nosniff · ACAO · CORP · בלי Set-Cookie · ' . ($h['content-type'] ?? '')];
}

/**
 * הבדיקה העצמית: entry, קובץ CSS, שמע עם Range — דרך הכתובת האמיתית (גם כשהמפסק כבוי: אסימון חד-פעמי לשתי דקות,
 * שרק הבקשה הזו נושאת), וגוש טקסט בכתובת ישירה (חייב להיחסם). התוצאה נשמרת ומוצגת באבחון.
 */
function kol_maroko_v2_selftest()
{
    $man = kol_maroko_v2_live_manifest();
    $rows = [];
    $add = function ($id, $name, $ok, $text) use (&$rows) { $rows[] = ['id' => $id, 'name' => $name, 'ok' => (bool)$ok, 'text' => (string)$text]; };
    if (!$man) {
        $add('live', 'יש גרסה v2 באוויר', false, 'עוד לא התקבלה גרסה — אין מה לבדוק');
    } else {
        $tok = bin2hex(random_bytes(16));
        kol_maroko_set('v2_selftest', ['t' => $tok, 'exp' => time() + 120]);
        $base = home_url(kol_maroko_v2_scope());
        $paths = array_keys($man['files']);
        $css = current(array_filter($paths, fn($p) => substr($p, -4) === '.css')) ?: null;
        $audio = current(array_filter($paths, fn($p) => in_array(strtolower(pathinfo($p, PATHINFO_EXTENSION)), KOL_MAROKO_V2_AUDIO, true))) ?: null;
        $tests = [['entry', 'נקודת הכניסה', '', [], 200, 'text/html'], ['404', 'נתיב שאינו בחבילה', '__kol-no-such-file__', [], 404, null],
            ['traverse', 'נתיב שמטפס החוצה', '..%2f..%2fwp-config.php', [], 404, null]];
        if ($css) $tests[] = ['css', 'קובץ CSS', $css, [], 200, 'text/css'];
        if ($audio) $tests[] = ['audio', 'שמע עם Range', $audio, ['Range' => 'bytes=0-1'], 206, null];
        foreach ($tests as [$id, $name, $p, $hdr, $want, $type]) {
            $enc = implode('/', array_map('rawurlencode', explode('/', $p)));
            if ($id === 'traverse') $enc = $p;
            $r = kol_maroko_v2_loopback($base . $enc, $hdr + ['X-Kol-Selftest' => $tok]);
            if (!$r || isset($r['error'])) { $add($id, $name, false, 'הבקשה נכשלה: ' . ($r['error'] ?? 'כתובת לא של האתר')); continue; }
            [$ok, $text] = kol_maroko_v2_check_headers($r['headers'], $type);
            $add($id, $name, $ok && $r['status'] === $want, $r['status'] . ($r['status'] === $want ? '' : " (ציפיתי ל-$want)") . ' · ' . $text);
        }
        kol_maroko_set('v2_selftest', null);
        // גוש טקסט בכתובת ישירה — חייב להיחסם
        $text_sha = null;
        foreach ($man['files'] as $p => $f) { $b = kol_maroko_v2_blob($f['sha256']); if ($b && strpos(basename($b), '.') === false) { $text_sha = $f['sha256']; break; } }
        if ($text_sha) {
            $r = kol_maroko_v2_loopback(kol_maroko_dir()['url'] . '/v2/blobs/' . $text_sha);
            $st = $r['status'] ?? 0;
            $add('blobs', 'blobs/ חסומה מבחוץ (גוש טקסט)', in_array($st, [403, 404], true), $st ? (string)$st : ($r['error'] ?? 'נכשל'));
        }
        // הגשה ישירה של מדיה — רק כשההגדרה דולקת
        if (kol_maroko_opt('v2_direct_media', false)) {
            $media = null;
            foreach ($man['files'] as $p => $f) { $b = kol_maroko_v2_blob($f['sha256']); if ($b && strpos(basename($b), '.') !== false) { $media = $b; break; } }
            if ($media) {
                $r = kol_maroko_v2_loopback(kol_maroko_dir()['url'] . '/v2/blobs/' . basename($media), ['Range' => 'bytes=0-1']);
                [$ok, $text] = kol_maroko_v2_check_headers((array)($r['headers'] ?? []), null);
                $add('direct', 'הגשה ישירה של מדיה', $ok && in_array($r['status'] ?? 0, [200, 206], true), ($r['status'] ?? 0) . ' · ' . $text);
            } else {
                $add('direct', 'הגשה ישירה של מדיה', false, 'אין קובץ מדיה בחבילה לבדיקה');
            }
        }
    }
    $res = ['at' => time(), 'ok' => $rows && !in_array(false, array_column($rows, 'ok'), true), 'rows' => $rows];
    kol_maroko_set('v2_selftest_result', $res);
    return $res;
}

/** האם הבקשה הנוכחית היא הבדיקה העצמית (אסימון חד-פעמי, שתי דקות). */
function kol_maroko_v2_selftest_req()
{
    $t = (array)kol_maroko_opt('v2_selftest', []);
    $h = (string)($_SERVER['HTTP_X_KOL_SELFTEST'] ?? '');
    return $h !== '' && !empty($t['t']) && (int)($t['exp'] ?? 0) >= time() && hash_equals((string)$t['t'], $h);
}

/** טבלת הבדיקה העצמית במסך — ✓ / ✗ לכל שורה. */
function kol_maroko_v2_selftest_html()
{
    $post = esc_url(admin_url('admin-post.php'));
    echo '<h3>בדיקה עצמית של העמוד החדש</h3><p>בקשות לאתר עצמו: נקודת הכניסה, קובץ CSS, שמע עם Range, ונתיב עוין — והכותרות כפי שהן יוצאות בפועל. '
        . 'עובד גם כשהמפסק כבוי.</p><form method="post" action="' . $post . '">' . wp_nonce_field('kol_maroko_v2', '_wpnonce', true, false)
        . '<input type="hidden" name="action" value="kol_maroko_v2"><input type="hidden" name="op" value="selftest">';
    submit_button('🔎 בדיקה עצמית', 'secondary', 'submit', false);
    echo '</form>';
    $r = kol_maroko_opt('v2_selftest_result', null);
    if (!is_array($r)) return;
    echo '<table class="widefat striped" style="max-width:900px"><tbody>';
    foreach ((array)$r['rows'] as $row) {
        echo '<tr><td>' . ($row['ok'] ? '✓' : '✗') . '</td><td>' . esc_html($row['name']) . '</td><td dir="ltr" style="font:12px monospace">' . esc_html($row['text']) . '</td></tr>';
    }
    echo '</tbody></table><p class="description">' . esc_html(wp_date('d.m.Y H:i', (int)$r['at'])) . '</p>';
}
