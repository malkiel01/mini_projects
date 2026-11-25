<?php
declare(strict_types=1);

/**
 * פרוסה 10 — זיהוי בלי תבנית קבועה: פענוח שמות תיקיות וקבצים, וניקוד התאמה לתיק. **טהור** — בלי מסד, בלי רשת.
 * האפיון: docs/spec/03-dropbox.md ("זיהוי תיקיות"), 02-case.md ("מניעת כפילות"). נבדק ב-tests/link.test.php.
 *
 * הכלל של הקובץ: מה שאינו ודאי — לא מוחלט כאן. linkDecide() מחזיר "קישור" רק כשיש מועמד אחד חזק בלבד;
 * כל שאר המצבים — "שאלה" (עם מועמדים) או "תיק חדש" כשאין אף מועמד, ואף פעם לא תיק חדש כשיש מועמד כלשהו.
 */
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }

const LINK_HE_MONTHS = ['ינואר' => 1, 'פברואר' => 2, 'מרץ' => 3, 'מרס' => 3, 'אפריל' => 4, 'מאי' => 5, 'יוני' => 6, 'יולי' => 7,
    'אוגוסט' => 8, 'ספטמבר' => 9, 'אוקטובר' => 10, 'נובמבר' => 11, 'דצמבר' => 12];
const LINK_EN_MONTHS = ['january' => 1, 'jan' => 1, 'february' => 2, 'feb' => 2, 'march' => 3, 'mar' => 3, 'april' => 4, 'apr' => 4,
    'may' => 5, 'june' => 6, 'jun' => 6, 'july' => 7, 'jul' => 7, 'august' => 8, 'aug' => 8, 'september' => 9, 'sep' => 9, 'sept' => 9,
    'october' => 10, 'oct' => 10, 'november' => 11, 'nov' => 11, 'december' => 12, 'dec' => 12];
/** חודשים עבריים → מספר החודש של jewishtojd (1 = תשרי … 13 = אלול; 6 = אדר א', 7 = אדר / אדר ב'). */
const LINK_HEB_MONTHS = ['תשרי' => 1, 'חשון' => 2, 'חשוון' => 2, 'מרחשון' => 2, 'מרחשוון' => 2, 'כסלו' => 3, 'כסליו' => 3, 'טבת' => 4,
    'שבט' => 5, 'אדר' => 7, 'אדרא' => 6, 'אדרב' => 7, 'ניסן' => 8, 'אייר' => 9, 'איר' => 9, 'סיון' => 10, 'סיוון' => 10, 'תמוז' => 11,
    'אב' => 12, 'מנחםאב' => 12, 'אלול' => 13];
/** מילים שאינן חלק משם הנפטר. */
const LINK_NOISE = ['זל', 'ז', 'ל', 'הידי', 'הי', 'ד', 'ע', 'ה', 'עה', 'נע', 'נ', 'תנצבה', 'נפטר', 'נפטרה', 'לוויה', 'לויה', 'תיק', 'מנוח',
    'מנוחה', 'המנוח', 'המנוחה', 'בן', 'בת', 'ר', 'הרב', 'רבי', 'מר', 'גב', 'גברת', 'פטירה', 'קבורה', 'folder', 'case'];

/** נרמול להשוואה: בלי ניקוד, בלי גרשיים ופיסוק, אותיות קטנות, רווח יחיד. */
function linkNorm(string $s): string {
    $s = (string)preg_replace('/[\x{0591}-\x{05C7}]/u', '', $s);
    $s = str_replace(['״', '׳', '"', "'", '`', '”', '“', '’', '‘'], '', $s);
    $s = (string)preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);
    return trim(mb_strtolower($s));
}
/** מילים (אותיות בלבד, בלי מילות רעש). */
function linkWords(string $s): array {
    $out = [];
    foreach (explode(' ', linkNorm((string)preg_replace('/\d+/u', ' ', $s))) as $w) {
        if ($w !== '' && !in_array($w, LINK_NOISE, true)) $out[] = $w;
    }
    return $out;
}

/** ת"ז ישראלית: 5–9 ספרות, משלימים לתשע, ספרת ביקורת. */
function linkValidTz(string $d): bool {
    if (!preg_match('/^\d{5,9}$/D', $d)) return false;
    $d = str_pad($d, 9, '0', STR_PAD_LEFT);
    if ($d === '000000000') return false;
    $sum = 0;
    for ($i = 0; $i < 9; $i++) { $n = (int)$d[$i] * ($i % 2 + 1); $sum += $n > 9 ? $n - 9 : $n; }
    return $sum % 10 === 0;
}
function linkTzNorm(string $d): string { return str_pad(ltrim($d, '0') === '' ? '0' : $d, 9, '0', STR_PAD_LEFT); }

/** גימטריה של שנה עברית ("תשפ"ז" → 5787). null — לא שנה. */
function linkHebYear(string $w): ?int {
    $w = str_replace(['״', '"', '׳', "'"], '', $w);
    if (mb_strlen($w) < 2 || mb_strlen($w) > 5) return null;
    $v = ['א' => 1, 'ב' => 2, 'ג' => 3, 'ד' => 4, 'ה' => 5, 'ו' => 6, 'ז' => 7, 'ח' => 8, 'ט' => 9, 'י' => 10, 'כ' => 20, 'ך' => 20, 'ל' => 30,
        'מ' => 40, 'ם' => 40, 'נ' => 50, 'ן' => 50, 'ס' => 60, 'ע' => 70, 'פ' => 80, 'ף' => 80, 'צ' => 90, 'ץ' => 90, 'ק' => 100, 'ר' => 200, 'ש' => 300, 'ת' => 400];
    $n = 0;
    foreach (mb_str_split($w) as $ch) { if (!isset($v[$ch])) return null; $n += $v[$ch]; }
    return $n >= 700 && $n <= 899 ? 5000 + $n : null;   // תש"ס–תתצ"ט: טווח סביר לתיקיות
}

/**
 * תיקיית חודש? מחזיר:
 *   ['month' => 'YYYY-MM'] — חודש לועזי ודאי ("2026-10", "10.2026", "אוקטובר 2026", "Oct-26", ובתוך תיקיית שנה גם "אוקטובר");
 *   ['ask' => 'hebrew'|'year', 'options' => ['YYYY-MM', …]] — חודש עברי ("חשון", "חשון תשפ"ז") או שם חודש בלי שנה: לא ודאי,
 *     נשאל (או כלל שנלמד);
 *   null — לא תיקיית חודש.
 * $parentYear — תיקיית ההורה היא שנה ("2026").
 */
function linkParseMonth(string $name, ?int $parentYear = null, ?DateTimeImmutable $now = null): ?array {
    $n = linkNorm($name);
    if ($n === '') return null;
    $ok = static fn(int $y, int $m) => $y >= 1990 && $y <= 2100 && $m >= 1 && $m <= 12 ? ['month' => sprintf('%04d-%02d', $y, $m)] : null;
    if (preg_match('/^(\d{4}) (\d{1,2})$/D', $n, $m)) return $ok((int)$m[1], (int)$m[2]);
    if (preg_match('/^(\d{1,2}) (\d{4})$/D', $n, $m)) return $ok((int)$m[2], (int)$m[1]);
    if (preg_match('/^(\d{4})(\d{2})$/D', $n, $m)) return $ok((int)$m[1], (int)$m[2]);
    $parts = explode(' ', $n);
    $mon = null; $nums = []; $heb = null; $hebYear = null;
    foreach ($parts as $p) {
        if (isset(LINK_HE_MONTHS[$p])) $mon = LINK_HE_MONTHS[$p];
        elseif (isset(LINK_EN_MONTHS[$p])) $mon = LINK_EN_MONTHS[$p];
        elseif (isset(LINK_HEB_MONTHS[$p])) $heb = $p;
        elseif (in_array($p, ['א', 'ב'], true) && $heb === 'אדר') $heb = 'אדר' . $p;
        elseif (preg_match('/^\d{1,4}$/D', $p)) $nums[] = $p;
        elseif (($y = linkHebYear($p)) !== null) $hebYear = $y;
        else return null;    // מילה נוספת — כנראה שם, לא חודש
    }
    $yearOf = static fn(string $d) => strlen($d) === 4 ? (int)$d : (strlen($d) === 2 ? 2000 + (int)$d : null);
    if ($heb !== null) {
        if ($mon !== null || $nums) return null;
        return ['ask' => 'hebrew', 'options' => linkHebOptions(LINK_HEB_MONTHS[$heb], $hebYear, $now)];
    }
    if ($mon !== null) {
        if (count($nums) > 1) return null;
        $year = $nums ? $yearOf($nums[0]) : $parentYear;
        if ($year !== null) return $ok($year, $mon);
        if ($nums) return null;
        // שם חודש בלי שנה: השנה הנוכחית או הקודמת — נשאל (או כלל)
        $now ??= new DateTimeImmutable('now');
        $y = (int)$now->format('Y');
        return ['ask' => 'year', 'options' => [sprintf('%04d-%02d', $mon > (int)$now->format('n') ? $y - 1 : $y, $mon),
            sprintf('%04d-%02d', $mon > (int)$now->format('n') ? $y - 2 : $y - 1, $mon)]];
    }
    return null;
}
/** החודשים הלועזיים שחודש עברי נופל בהם (ההופעה הקרובה לפני היום, או בשנה שצוינה). בלי הרחבת calendar — שני חודשים סביב היום. */
function linkHebOptions(int $hm, ?int $hy, ?DateTimeImmutable $now = null): array {
    $now ??= new DateTimeImmutable('now');
    if (!function_exists('jewishtojd')) {
        return [$now->modify('first day of last month')->format('Y-m'), $now->format('Y-m')];
    }
    $cands = [];
    if ($hy !== null) $cands[] = $hy;
    else {
        $cur = (int)explode('/', jdtojewish(gregoriantojd((int)$now->format('n'), (int)$now->format('j'), (int)$now->format('Y'))))[2];
        $cands = [$cur, $cur - 1];
    }
    foreach ($cands as $y) {
        $start = jewishtojd($hm, 1, $y);
        $end = jewishtojd($hm, 29, $y);
        $s = DateTimeImmutable::createFromFormat('!m/d/Y', jdtogregorian($start));
        if ($hy === null && $s > $now) continue;
        $e = DateTimeImmutable::createFromFormat('!m/d/Y', jdtogregorian($end));
        return array_values(array_unique([$s->format('Y-m'), $e->format('Y-m')]));
    }
    return [$now->format('Y-m')];
}

/**
 * תיקיית נפטר (או שם קובץ): שם, תאריכים ות"ז, בכל סדר.
 * $month — חודש ההקשר (תיקיית החודש שמעל), להשלמת "14.10" ו-"14".
 * @return array{words:string[], dates:string[], tz:string[], partial_dates:string[]}
 */
function linkParseName(string $name, ?string $month = null): array {
    $s = (string)preg_replace('/\.(pdf|jpe?g|png|heic|docx?|txt)$/iu', '', $name);
    $s = (string)preg_replace('/\s\(\d+\)$/u', '', $s);       // "(2)" של כפילות
    $dates = []; $partial = []; $tz = [];
    $add = static function (int $y, int $m, int $d) use (&$dates): void {
        if ($y < 100) $y += 2000;
        if ($y >= 1990 && $y <= 2100 && checkdate($m, $d, $y)) $dates[] = sprintf('%04d-%02d-%02d', $y, $m, $d);
    };
    $s = (string)preg_replace_callback('/(?<!\d)(\d{4})[-_.\/](\d{1,2})[-_.\/](\d{1,2})(?!\d)/u', function ($m) use ($add) { $add((int)$m[1], (int)$m[2], (int)$m[3]); return ' '; }, $s);
    $s = (string)preg_replace_callback('/(?<!\d)(\d{1,2})[-_.\/](\d{1,2})[-_.\/](\d{2}|\d{4})(?!\d)/u', function ($m) use ($add) { $add((int)$m[3], (int)$m[2], (int)$m[1]); return ' '; }, $s);
    $s = (string)preg_replace_callback('/(?<!\d)(\d{1,2})[-_.\/](\d{1,2})(?!\d)/u', function ($m) use (&$partial) {
        $partial[] = sprintf('%02d-%02d', (int)$m[2], (int)$m[1]);   // MM-DD
        return ' ';
    }, $s);
    $s = (string)preg_replace_callback('/(?<!\d)(\d{8})(?!\d)/u', function ($m) use ($add, &$tz) {
        $y = (int)substr($m[1], 0, 4);
        if ($y >= 1990 && $y <= 2100) { $add($y, (int)substr($m[1], 4, 2), (int)substr($m[1], 6, 2)); return ' '; }
        if (linkValidTz($m[1])) $tz[] = linkTzNorm($m[1]);
        return ' ';
    }, $s);
    $s = (string)preg_replace_callback('/(?<!\d)(\d{9})(?!\d)/u', function ($m) use (&$tz) { if (linkValidTz($m[1])) $tz[] = $m[1]; return ' '; }, $s);
    if ($month !== null && preg_match('/^(\d{4})-(\d\d)$/D', $month, $mm)) {
        foreach ($partial as $p) {
            [$pm, $pd] = array_map('intval', explode('-', $p));
            if ($pm === (int)$mm[2]) $add((int)$mm[1], $pm, $pd);
            elseif ($pd === (int)$mm[2] && $pm <= 31) $add((int)$mm[1], $pd, $pm);   // נכתב חודש.יום
        }
        // יום בודד ("14") בתוך תיקיית חודש
        if (!$dates && !$partial && preg_match_all('/(?<!\d)(\d{1,2})(?!\d)/u', $s, $dd) && count($dd[1]) === 1) $add((int)$mm[1], (int)$mm[2], (int)$dd[1][0]);
    }
    return ['words' => array_values(array_diff(linkWords($s), LINK_GENERIC)), 'dates' => array_values(array_unique($dates)), 'tz' => array_values(array_unique($tz)),
        'partial_dates' => $partial];
}

/**
 * ניקוד של תיק מול תיקייה. מחזיר null כשהתיק נשלל (ת"ז אחרת, או תאריך רחוק ושם לא זהה), אחרת:
 * ['score' => 0–100, 'name_match' => full|partial|surname|none, 'date_match' => same|near|none|differs, 'tz' => bool].
 * $case: ['id','name','date','month','tz' => string[]]
 */
function linkScore(array $p, array $case): ?array {
    $tzHit = false;
    if ($p['tz'] && !empty($case['tz'])) {
        if (array_intersect($p['tz'], $case['tz'])) $tzHit = true;
        else return null;   // שתי ת"ז שונות — אדם אחר
    }
    $cw = linkWords((string)$case['name']);
    $fw = $p['words'];
    $name = 'none';
    if ($cw && $fw) {
        $sc = $cw; sort($sc); $sf = $fw; sort($sf);
        if ($sc === $sf) $name = 'full';
        else {
            // כל מילה בתיקייה היא תחילה של מילה אחרת בתיק (כליפה א ← כליפה אטון), ויש לפחות מילה שלמה אחת
            $used = []; $allPrefix = true; $whole = 0;
            foreach ($fw as $w) {
                $hit = false;
                foreach ($cw as $i => $c) {
                    if (isset($used[$i])) continue;
                    if ($c === $w) { $used[$i] = true; $hit = true; $whole++; break; }
                }
                if (!$hit) foreach ($cw as $i => $c) {
                    if (!isset($used[$i]) && str_starts_with($c, $w)) { $used[$i] = true; $hit = true; break; }
                }
                if (!$hit) { $allPrefix = false; break; }
            }
            if ($allPrefix && $whole >= 1) $name = 'partial';
            elseif (array_intersect($fw, $cw)) $name = 'surname';
            elseif ($allPrefix && count($cw) === count($fw)) $name = 'partial';
        }
    }
    $date = 'none';
    $cd = (string)($case['date'] ?? '');
    if ($p['dates'] && $cd !== '') {
        $best = PHP_INT_MAX;
        foreach ($p['dates'] as $d) $best = min($best, abs((int)((strtotime($d) - strtotime($cd)) / 86400)));
        $date = $best === 0 ? 'same' : ($best <= 3 ? 'near' : 'differs');
    }
    if (!$tzHit && $date === 'differs' && $name !== 'full') return null;
    if (!$tzHit && $name === 'none' && $date !== 'same') return null;
    $score = ($tzHit ? 60 : 0) + ['full' => 30, 'partial' => 18, 'surname' => 10, 'none' => 0][$name]
        + ['same' => 10, 'near' => 5, 'none' => 0, 'differs' => -10][$date];
    return ['score' => max(1, min(100, $score)), 'name_match' => $name, 'date_match' => $date, 'tz' => $tzHit];
}

/** "בטוח": ת"ז זהה (ושם לא סותר), או שם מלא + תאריך זהה. */
function linkStrong(array $s): bool {
    return ($s['tz'] && $s['name_match'] !== 'none') || ($s['tz'] && $s['date_match'] === 'same') || ($s['name_match'] === 'full' && $s['date_match'] === 'same');
}

/**
 * ההחלטה לתיקייה אחת. $cases — תיקי הארגון (עם 'linked' => bool, 'tz' => []).
 * @return array{decision: 'link'|'question'|'new', case_id?: string, candidates: array, why: string}
 *  - link: מועמד חזק אחד בלבד, שעוד לא מקושר לתיקייה אחרת, ואין מועמד חזק שני.
 *  - new: אין אף מועמד (גם לא חלש, גם לא מקושר) — ויש מספיק כדי לפתוח תיק (שם + תאריך או חודש).
 *  - question: כל השאר. לעולם לא new כשיש מועמד כלשהו (אין כפילות).
 */
function linkDecide(array $p, array $cases, ?string $month): array {
    $cands = [];
    foreach ($cases as $c) {
        $s = linkScore($p, $c);
        if ($s === null) continue;
        // שם מלא בלי תאריך בתיקייה: חזק רק אם החודש של התיק הוא החודש של התיקייה
        if ($s['name_match'] === 'full' && $s['date_match'] === 'none' && !$s['tz'] && $month !== null && ($c['month'] ?? '') === $month) $s['month'] = true;
        $cands[] = ['id' => $c['id'], 'name' => $c['name'], 'date' => $c['date'], 'linked' => !empty($c['linked'])] + $s;
    }
    usort($cands, static fn($a, $b) => $b['score'] <=> $a['score']);
    $strong = array_values(array_filter($cands, static fn($c) => linkStrong($c) || !empty($c['month'])));
    $top = array_slice($cands, 0, 4);
    if (count($strong) === 1 && !$strong[0]['linked']) {
        // תיקייה בלי תאריך ושם מלא — מספיקה רק אם אין עוד תיק עם אותו שם
        $sameName = array_filter($cands, static fn($c) => $c['name_match'] === 'full');
        if (!empty($strong[0]['month']) && !linkStrong($strong[0]) && count($sameName) > 1) {
            return ['decision' => 'question', 'candidates' => $top, 'why' => 'כמה תיקים באותו שם'];
        }
        return ['decision' => 'link', 'case_id' => $strong[0]['id'], 'candidates' => $top,
            'why' => $strong[0]['tz'] ? 'ת"ז זהה' : ($strong[0]['date_match'] === 'same' ? 'שם ותאריך זהים' : 'שם זהה בחודש')];
    }
    if ($cands) {
        $why = count($strong) > 1 ? 'כמה תיקים מתאימים' : ($strong && $strong[0]['linked'] ? 'התיק כבר מקושר לתיקייה אחרת' : 'התאמה חלקית');
        return ['decision' => 'question', 'candidates' => $top, 'why' => $why];
    }
    if (!$p['words']) return ['decision' => 'question', 'candidates' => [], 'why' => 'אין שם בתיקייה'];
    // תיק חדש רק כשיש עוגן: תאריך או ת"ז. שם לבד ("סריקות", "מסמכים") — שאלה
    if (!$p['dates'] && !$p['tz']) return ['decision' => 'question', 'candidates' => [], 'why' => 'אין תאריך בשם התיקייה'];
    return ['decision' => 'new', 'candidates' => [], 'why' => 'אין תיק לנפטר הזה'];
}

/** מילים של שמות קבצים שאינן אומרות דבר על התוכן (מצלמה, סורק). כלל רק מהן — לא נוצר. */
const LINK_GENERIC = ['img', 'image', 'dsc', 'dcim', 'photo', 'pic', 'scan', 'scanned', 'adobe', 'camscanner', 'whatsapp', 'at', 'pm', 'am',
    'document', 'doc', 'file', 'new', 'copy', 'screenshot', 'צילום', 'סריקה', 'סרוק', 'מסמך', 'קובץ', 'תמונה', 'העתק', 'pdf', 'jpg'];
/** מילות המפתח של שם קובץ לכלל סוג ("Adobe Scan 2026-10-14 רישיון.pdf" → "adobe scan רישיון"). '' — אין מילה של תוכן. */
function linkFilePattern(string $fileName): string {
    $w = array_values(array_filter(linkWords((string)preg_replace('/\.[a-z0-9]{2,5}$/iu', '', $fileName)), static fn($x) => mb_strlen($x) >= 2));
    if (!array_diff($w, LINK_GENERIC)) return '';
    return implode(' ', array_slice($w, 0, 5));
}
/** כלל סוג חל על קובץ: כל מילות התבנית מופיעות בשם (כמילה או תחילת מילה). */
function linkPatternHits(string $pattern, string $fileName): bool {
    $pw = array_filter(explode(' ', linkNorm($pattern)));
    if (!$pw) return false;
    $fw = linkWords((string)preg_replace('/\.[a-z0-9]{2,5}$/iu', '', $fileName));
    foreach ($pw as $w) {
        $hit = false;
        foreach ($fw as $f) if ($f === $w || (mb_strlen($w) >= 3 && str_starts_with($f, $w))) { $hit = true; break; }
        if (!$hit) return false;
    }
    return true;
}
