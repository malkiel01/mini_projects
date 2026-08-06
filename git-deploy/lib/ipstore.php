<?php
/**
 * ניהול רשימת ה-IPs המורשים + היסטוריה + הגנת brute-force.
 *
 * הרשימה עצמה נשארת ב-settings.allowed_ips כמערך מחרוזות שטוח, בדיוק כפי
 * ש-Auth::ipAllowed() מצפה — כדי שהשער עצמו לא ישתנה. המטא-דאטה (תווית,
 * מי הוסיף ומתי) יושבת בנפרד ב-ip_meta וממופתחת לפי כלל.
 */

require_once __DIR__ . '/config.php';

class IpStore {

    /** כמה אירועים לשמור בהיסטוריה לפני שהישנים נזרקים. */
    const HISTORY_MAX = 300;

    /** כישלונות רצופים מאותו IP לפני נעילה. */
    const LOCK_AFTER = 5;

    /** משך הנעילה הראשונה; כל כישלון נוסף מכפיל, עד תקרה. */
    const LOCK_BASE = 900;      // 15 דקות
    const LOCK_MAX  = 86400;    // יממה

    /* ── רשימת הכללים ────────────────────────────────────────────── */

    public static function rules() {
        $list = Config::get('settings')['allowed_ips'] ?? [];
        return is_array($list) ? array_values($list) : [];
    }

    public static function meta() {
        $m = Config::get('ip_meta', []);
        return is_array($m) ? $m : [];
    }

    /**
     * בודק ומנרמל כלל שהוזן ביד.
     * @return array{ok:bool, rule?:string, error?:string}
     */
    public static function validateRule($raw) {
        $rule = trim((string)$raw);

        if ($rule === '') {
            return ['ok' => false, 'error' => 'לא הוזן כלל.'];
        }
        if (strlen($rule) > 64) {
            return ['ok' => false, 'error' => 'הכלל ארוך מדי.'];
        }
        if ($rule === '*') {
            return ['ok' => true, 'rule' => '*'];
        }

        // CIDR — Auth::cidrMatch תומך ב-IPv4 בלבד, אז כלל IPv6 היה נכשל בשקט.
        if (strpos($rule, '/') !== false) {
            $parts = explode('/', $rule);
            if (count($parts) !== 2) {
                return ['ok' => false, 'error' => 'תחביר CIDR שגוי.'];
            }
            list($subnet, $bits) = $parts;
            if (!filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return ['ok' => false, 'error' => 'CIDR נתמך ל-IPv4 בלבד (' . htmlspecialchars($subnet) . ' אינו כתובת IPv4).'];
            }
            if (!ctype_digit($bits) || (int)$bits < 0 || (int)$bits > 32) {
                return ['ok' => false, 'error' => 'מסכת CIDR חייבת להיות בין 0 ל-32.'];
            }
            return ['ok' => true, 'rule' => $subnet . '/' . (int)$bits];
        }

        // Wildcard — Auth::ipMatch מזהה רק סיומת ".*"
        if (strpos($rule, '*') !== false) {
            if (substr($rule, -2) !== '.*' || substr_count($rule, '*') !== 1) {
                return ['ok' => false, 'error' => 'wildcard נתמך רק בסוף הכלל, בצורה 1.2.3.* או 1.2.*'];
            }
            $prefix = substr($rule, 0, -2);
            if (!preg_match('/^\d{1,3}(\.\d{1,3})*$/', $prefix)) {
                return ['ok' => false, 'error' => 'תחילית ה-wildcard חייבת להיות מספרי IPv4.'];
            }
            foreach (explode('.', $prefix) as $octet) {
                if ((int)$octet > 255) {
                    return ['ok' => false, 'error' => 'אוקטט מחוץ לטווח בתחילית.'];
                }
            }
            return ['ok' => true, 'rule' => $prefix . '.*'];
        }

        // כתובת בודדת. IPv6 מנורמל, אחרת שתי כתיבות של אותה כתובת לא היו מזוהות כזהות.
        if (filter_var($rule, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return ['ok' => true, 'rule' => $rule];
        }
        if (filter_var($rule, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed = @inet_pton($rule);
            return ['ok' => true, 'rule' => $packed === false ? $rule : inet_ntop($packed)];
        }

        return ['ok' => false, 'error' => 'לא כתובת IP, CIDR או wildcard תקינים.'];
    }

    /** @return array{ok:bool, error?:string, rule?:string} */
    public static function addRule($raw, $label, $byIp) {
        $v = self::validateRule($raw);
        if (!$v['ok']) return $v;
        $rule = $v['rule'];

        $cfg = Config::load();
        $rules = $cfg['settings']['allowed_ips'] ?? [];
        if (in_array($rule, $rules, true)) {
            return ['ok' => false, 'error' => 'הכלל כבר קיים ברשימה.'];
        }

        $rules[] = $rule;
        $cfg['settings']['allowed_ips'] = array_values($rules);
        $cfg['ip_meta'][$rule] = [
            'label'    => mb_substr(trim((string)$label), 0, 60),
            'added_at' => time(),
            'added_by' => $byIp,
        ];
        Config::save($cfg);

        self::log('add', ['rule' => $rule, 'label' => $label, 'ip' => $byIp]);
        return ['ok' => true, 'rule' => $rule];
    }

    /** @return array{ok:bool, error?:string} */
    public static function removeRule($rule, $byIp) {
        $cfg = Config::load();
        $rules = $cfg['settings']['allowed_ips'] ?? [];
        $idx = array_search($rule, $rules, true);
        if ($idx === false) {
            return ['ok' => false, 'error' => 'הכלל לא נמצא ברשימה.'];
        }

        array_splice($rules, $idx, 1);
        $cfg['settings']['allowed_ips'] = array_values($rules);
        unset($cfg['ip_meta'][$rule]);
        Config::save($cfg);

        self::log('remove', ['rule' => $rule, 'ip' => $byIp]);
        return ['ok' => true];
    }

    /**
     * האם ה-IP הזה יעבור את השער אם הרשימה תהיה כפי שנמסרה.
     * משמש כדי להזהיר לפני מחיקה שנועלת אותך בחוץ.
     */
    public static function wouldAllow($ip, array $rules) {
        require_once __DIR__ . '/auth.php';
        return Auth::ipAllowedBy($ip, $rules);
    }

    /* ── היסטוריה ────────────────────────────────────────────────── */

    public static function log($event, array $data = []) {
        $cfg = Config::load();
        $history = $cfg['ip_history'] ?? [];
        if (!is_array($history)) $history = [];

        array_unshift($history, [
            'ts'    => time(),
            'event' => $event,
            'rule'  => isset($data['rule']) ? (string)$data['rule'] : '',
            'label' => isset($data['label']) ? (string)$data['label'] : '',
            'ip'    => isset($data['ip']) ? (string)$data['ip'] : '',
            'ua'    => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 120),
        ]);

        $cfg['ip_history'] = array_slice($history, 0, self::HISTORY_MAX);
        Config::save($cfg);
    }

    public static function history($limit = 60) {
        $h = Config::get('ip_history', []);
        if (!is_array($h)) return [];
        return array_slice($h, 0, $limit);
    }

    public static function clearHistory($byIp) {
        $cfg = Config::load();
        $cfg['ip_history'] = [];
        Config::save($cfg);
        self::log('history_cleared', ['ip' => $byIp]);
    }

    /* ── הגנת brute-force ────────────────────────────────────────── */

    /**
     * הדף הזה חשוף לאינטרנט (זו כל הנקודה שלו), ולכן סיסמה לבדה לא מספיקה.
     * @return array{locked:bool, until:int, fails:int}
     */
    public static function throttle($ip) {
        $all = Config::get('ip_throttle', []);
        $entry = is_array($all) && isset($all[$ip]) ? $all[$ip] : null;
        if (!$entry) return ['locked' => false, 'until' => 0, 'fails' => 0];

        $until = (int)($entry['until'] ?? 0);
        return [
            'locked' => $until > time(),
            'until'  => $until,
            'fails'  => (int)($entry['fails'] ?? 0),
        ];
    }

    public static function noteFailure($ip) {
        $cfg = Config::load();
        $all = isset($cfg['ip_throttle']) && is_array($cfg['ip_throttle']) ? $cfg['ip_throttle'] : [];

        // ניקוי רשומות מתות, אחרת הקובץ תופח ללא הגבלה מסריקות אוטומטיות.
        $cutoff = time() - self::LOCK_MAX;
        foreach ($all as $key => $row) {
            if ((int)($row['seen'] ?? 0) < $cutoff && (int)($row['until'] ?? 0) < time()) {
                unset($all[$key]);
            }
        }

        $fails = (int)($all[$ip]['fails'] ?? 0) + 1;
        $until = 0;
        if ($fails >= self::LOCK_AFTER) {
            $factor = 1 << min($fails - self::LOCK_AFTER, 10);
            $until = time() + min(self::LOCK_BASE * $factor, self::LOCK_MAX);
        }

        $all[$ip] = ['fails' => $fails, 'until' => $until, 'seen' => time()];
        $cfg['ip_throttle'] = $all;
        Config::save($cfg);

        self::log($until > 0 ? 'locked' : 'login_fail', ['ip' => $ip]);
        return ['locked' => $until > time(), 'until' => $until, 'fails' => $fails];
    }

    public static function clearFailures($ip) {
        $cfg = Config::load();
        if (isset($cfg['ip_throttle'][$ip])) {
            unset($cfg['ip_throttle'][$ip]);
            Config::save($cfg);
        }
    }
}
