<?php
/**
 * מיזוג פנים שחזרו (docs/spec/hub-api.md 2.3–2.4, סעיף 3): reconcile מחזיר תוכנית ולא נוגע ב-$f; applyMerge מחיל אותה.
 * אותו קוד כמו src/js/merge.js — הפירוט שם.
 *
 *   require_once __DIR__ . '/mbe/merge.php';
 *   $plan = MbeMerge::reconcile($f, ['docId' => $id['docId'], 'base' => $id['base'], 'baseDocument' => $id['document'] ?? null,
 *                                    'flows' => ['f1' => $pmDocFromWord], 'app' => 'external:word', 'who' => 'המגיה']);
 *   MbeMerge::applyMerge($f, $plan, ['f1/p17' => 'theirs']);   // ואחר כך Mbe::save — נרשמים return ו-merge
 */

declare(strict_types=1);

require_once __DIR__ . '/mbe.php';

final class MbeMerge
{
    public const SPAN = 5000;       // קטע בלי עוגן גדול מזה — רק עוגנים (hub-api.md סעיף 6)
    private const DICE_WINDOW = 50;  // כמה בלוקים קדימה מחפשים פסקה שנערכה
    private const WS = '/[\s\x{00a0}\x{1680}\x{2000}-\x{200b}\x{2028}\x{2029}\x{202f}\x{205f}\x{3000}\x{feff}]+/u';

    private static function same(mixed $a, mixed $b): bool { return MbeCanonical::encode($a) === MbeCanonical::encode($b); }
    private static function issue(string $code, string $message): array { return ['level' => 'error', 'code' => $code, 'message' => $message]; }

    private static function blockKey(mixed $node, int $depth = 0): ?string
    {
        if (!is_array($node) || $depth > 200) return null;
        if (in_array($node['type'] ?? null, ['paragraph', 'heading'], true) && is_array($node['attrs'] ?? null) && is_string($node['attrs']['id'] ?? null)) return $node['attrs']['id'];
        if (is_array($node['content'] ?? null)) foreach ($node['content'] as $c) { $k = self::blockKey($c, $depth + 1); if ($k !== null) return $k; }
        return null;
    }

    private static function setKey(mixed &$node, string $key, int $depth = 0): bool
    {
        if (!is_array($node) || $depth > 200) return false;
        if (in_array($node['type'] ?? null, ['paragraph', 'heading'], true)) {
            if (!is_array($node['attrs'] ?? null)) $node['attrs'] = [];
            $node['attrs']['id'] = $key;
            return true;
        }
        if (is_array($node['content'] ?? null)) foreach ($node['content'] as &$c) if (self::setKey($c, $key, $depth + 1)) return true;
        return false;
    }

    private static function textOf(mixed $node, int $depth = 0): string
    {
        if (!is_array($node) || $depth > 200) return '';
        $s = is_string($node['text'] ?? null) ? $node['text'] : '';
        if (is_array($node['content'] ?? null)) foreach ($node['content'] as $c) { $t = self::textOf($c, $depth + 1); if ($t !== '') $s .= ($s !== '' ? ' ' : '') . $t; }
        return $s;
    }

    private static function content(mixed $flow): ?array
    {
        return is_array($flow) && is_array($flow['doc'] ?? null) && is_array($flow['doc']['content'] ?? null) ? $flow['doc']['content'] : null;
    }

    /** רצף בלוקים → ['seq' => [key], 'map' => [key => block]]. בלוק בלי פסקה — מפתח לפי התוכן (כמו ב-JS). */
    private static function index(array $blocks): array
    {
        $seq = []; $map = []; $n = [];
        foreach ($blocks as $b) {
            $k = self::blockKey($b);
            if ($k === null || array_key_exists($k, $map)) {
                $c = '~' . MbeCanonical::encode($b);
                $n[$c] = ($n[$c] ?? 0) + 1;
                $k = $c . '#' . $n[$c];
            }
            $seq[] = $k; $map[$k] = $b;
        }
        return ['seq' => $seq, 'map' => $map];
    }

    // ---- התאמה לפי תוכן ----

    private static function words(string $t): array
    {
        $m = []; $n = 0;
        foreach (preg_split(self::WS, $t) ?: [] as $w) if ($w !== '') { $m[$w] = ($m[$w] ?? 0) + 1; $n++; }
        return ['m' => $m, 'n' => $n];
    }

    private static function similar(array $a, array $b): bool
    {
        if ($a['n'] === 0 || $b['n'] === 0) return false;
        if (5 * min($a['n'], $b['n']) < 2 * max($a['n'], $b['n'])) return false; // Dice ≥ 0.6 דורש יחס אורכים ≥ 3/7
        $common = 0;
        foreach ($a['m'] as $w => $c) if (isset($b['m'][$w])) $common += min($c, $b['m'][$w]);
        return 10 * $common >= 3 * ($a['n'] + $b['n']);
    }

    /** LIS על [0] (רצף ממוין לפי [1]). */
    private static function lis(array $pairs): array
    {
        $tails = []; $prev = [];
        foreach ($pairs as $i => $p) {
            $lo = 0; $hi = count($tails);
            while ($lo < $hi) { $mid = ($lo + $hi) >> 1; if ($pairs[$tails[$mid]][0] < $p[0]) $lo = $mid + 1; else $hi = $mid; }
            $prev[$i] = $lo > 0 ? $tails[$lo - 1] : -1;
            $tails[$lo] = $i;
        }
        $out = [];
        for ($i = $tails ? $tails[count($tails) - 1] : -1; $i >= 0; $i = $prev[$i]) $out[] = $pairs[$i];
        return array_reverse($out);
    }

    /** @return array{pairs: list<array{int,int}>, limited: bool} */
    public static function alignByContent(array $A, array $B): array
    {
        $pairs = []; $limited = false;
        $wa = array_map([self::class, 'words'], $A); $wb = array_map([self::class, 'words'], $B);
        $code = [];
        $ca = []; foreach ($A as $t) { $code[$t] ??= count($code); $ca[] = $code[$t]; }
        $cb = []; foreach ($B as $t) { $code[$t] ??= count($code); $cb[] = $code[$t]; }

        $dice = function (int $a0, int $a1, int $b0, int $b1) use (&$pairs, $wa, $wb) {
            $a = $a0;
            for ($j = $b0; $j < $b1 && $a < $a1; $j++) {
                for ($i = $a, $e = min($a1, $a + self::DICE_WINDOW); $i < $e; $i++) {
                    if (self::similar($wa[$i], $wb[$j])) { $pairs[] = [$i, $j]; $a = $i + 1; break; }
                }
            }
        };
        $lcs = function (int $a0, int $a1, int $b0, int $b1) use (&$pairs, $ca, $cb, $dice) {
            $n = $a1 - $a0; $m = $b1 - $b0;
            $dir = str_repeat("\0", $n * $m);
            $next = array_fill(0, $m + 1, 0); $row = $next;
            for ($i = $n - 1; $i >= 0; $i--) {
                $row[$m] = 0;
                $x = $ca[$a0 + $i];
                for ($j = $m - 1; $j >= 0; $j--) {
                    if ($x === $cb[$b0 + $j]) { $row[$j] = $next[$j + 1] + 1; $dir[$i * $m + $j] = "\1"; }
                    elseif ($next[$j] >= $row[$j + 1]) { $row[$j] = $next[$j]; $dir[$i * $m + $j] = "\2"; }
                    else { $row[$j] = $row[$j + 1]; $dir[$i * $m + $j] = "\3"; }
                }
                [$next, $row] = [$row, $next];
            }
            $i = 0; $j = 0; $la = 0; $lb = 0;
            while ($i < $n && $j < $m) {
                $d = $dir[$i * $m + $j];
                if ($d === "\1") { $dice($a0 + $la, $a0 + $i, $b0 + $lb, $b0 + $j); $pairs[] = [$a0 + $i, $b0 + $j]; $i++; $j++; $la = $i; $lb = $j; }
                elseif ($d === "\2") $i++; else $j++;
            }
            $dice($a0 + $la, $a1, $b0 + $lb, $b1);
        };
        $rec = function (int $a0, int $a1, int $b0, int $b1) use (&$rec, &$pairs, &$limited, $ca, $cb, $lcs) {
            while ($a0 < $a1 && $b0 < $b1 && $ca[$a0] === $cb[$b0]) $pairs[] = [$a0++, $b0++];
            while ($a1 > $a0 && $b1 > $b0 && $ca[$a1 - 1] === $cb[$b1 - 1]) $pairs[] = [--$a1, --$b1];
            if ($a0 >= $a1 || $b0 >= $b1) return;
            $cnt = [];
            for ($i = $a0; $i < $a1; $i++) { $cnt[$ca[$i]] ??= [0, 0, $i]; $cnt[$ca[$i]][0]++; }
            for ($j = $b0; $j < $b1; $j++) if (isset($cnt[$cb[$j]])) $cnt[$cb[$j]][1]++;
            $cand = [];
            for ($j = $b0; $j < $b1; $j++) { $e = $cnt[$cb[$j]] ?? null; if ($e && $e[0] === 1 && $e[1] === 1) $cand[] = [$e[2], $j]; }
            $anchors = self::lis($cand);
            if ($anchors) {
                $pa = $a0; $pb = $b0;
                foreach ($anchors as [$i, $j]) { $rec($pa, $i, $pb, $j); $pairs[] = [$i, $j]; $pa = $i + 1; $pb = $j + 1; }
                $rec($pa, $a1, $pb, $b1);
                return;
            }
            if ($a1 - $a0 > self::SPAN || $b1 - $b0 > self::SPAN) { $limited = true; return; }
            $lcs($a0, $a1, $b0, $b1);
        };
        $rec(0, count($A), 0, count($B));
        // פסקה שהוזזה ממקומה — לא נכנסת לרצף; מעבר שני: טקסט זהה, בלי קשר לסדר
        $usedA = []; $usedB = [];
        foreach ($pairs as [$i, $j]) { $usedA[$i] = true; $usedB[$j] = true; }
        $free = [];
        foreach ($ca as $i => $c) if (!isset($usedA[$i])) $free[$c][] = $i;
        foreach ($cb as $j => $c) {
            if (isset($usedB[$j]) || empty($free[$c]) || $A[$free[$c][0]] === '') continue;
            $pairs[] = [array_shift($free[$c]), $j];
        }
        usort($pairs, fn($x, $y) => $x[0] <=> $y[0]);
        return ['pairs' => $pairs, 'limited' => $limited];
    }

    // ---- reconcile ----

    /** תוכנית מיזוג. לא משנה את $f. */
    public static function reconcile(MbeFile $f, array $o): array
    {
        $docId = $o['docId'] ?? null; $base = $o['base'] ?? null;
        $plan = ['status' => 'diverged', 'docId' => $docId, 'base' => $base, 'app' => $o['app'] ?? 'external', 'who' => $o['who'] ?? null,
            'changes' => [], 'conflicts' => [], 'gaps' => [], 'issues' => [], 'flows' => []];
        if (!$docId || $docId !== $f->id()) {
            $plan['status'] = 'other-doc';
            $plan['issues'][] = self::issue('face-unknown-doc', 'זה מסמך אחר — המזהה שבפנים אינו של הקובץ הזה');
            return $plan;
        }
        $ours = $f->document;
        $ourSha = hash('sha256', MbeCanonical::encode($ours));
        $baseDoc = null;
        if ($base && $base === $ourSha) $baseDoc = $ours;
        elseif ($base) $baseDoc = $f->version($base);
        $bd = $o['baseDocument'] ?? null;
        if ($baseDoc === null && is_array($bd) && $base && hash('sha256', MbeCanonical::encode($bd)) === $base) {
            $baseDoc = $bd;
            $plan['baseDocument'] = $bd; // לא בזיכרון — נשמר כגרסה עם שלב return
        }
        if (!is_array($baseDoc)) {
            $plan['issues'][] = self::issue('face-base-missing', 'הבסיס של מה שחזר לא נמצא — לא בהיסטוריה ולא בפנים; ממשיכים כייבוא רגיל');
            unset($plan['flows']);
            return $plan;
        }
        $ff = $base === $ourSha;
        $returned = $baseDoc;
        if (!is_array($returned['flows'] ?? null)) $returned['flows'] = [];
        $byContent = 0;
        $flows = $o['flows'] ?? [];
        $fids = array_map('strval', array_keys($flows));
        sort($fids, SORT_STRING);

        foreach ($fids as $fid) {
            $tdoc = $flows[$fid];
            $oursFlow = $ours['flows'][$fid] ?? null; $baseFlow = $baseDoc['flows'][$fid] ?? null;
            if (!is_array($tdoc) || !is_array($tdoc['content'] ?? null)) { $plan['gaps'][] = "flow:$fid — מה שחזר אינו מסמך ProseMirror"; continue; }
            if (self::content($oursFlow) === null) { $plan['gaps'][] = "flow:$fid — " . ($baseFlow ? 'נמחק אצלנו' : 'לא קיים במסמך') . '; לא מוזג'; continue; }
            $B = self::index(self::content($baseFlow) ?? []); $O = self::index(self::content($oursFlow));
            $theirs = $tdoc['content'];

            $seen = []; $loose = [];
            foreach ($theirs as $i => $b) { $k = self::blockKey($b); if ($k !== null && !isset($seen[$k])) $seen[$k] = true; else $loose[] = $i; }
            $missing = array_values(array_filter($B['seq'], fn($k) => !isset($seen[$k])));
            if ($loose && $missing) {
                $r = self::alignByContent(array_map(fn($k) => self::textOf($B['map'][$k]), $missing), array_map(fn($i) => self::textOf($theirs[$i]), $loose));
                foreach ($r['pairs'] as [$ia, $ib]) if (self::setKey($theirs[$loose[$ib]], $missing[$ia])) { $seen[$missing[$ia]] = true; $byContent++; }
                if ($r['limited']) $plan['gaps'][] = "merge:$fid — קטע של יותר מ-" . self::SPAN . ' פסקאות בלי עוגן; הותאם רק לפי טקסט זהה';
            }
            $tdocFixed = $tdoc;
            $tdocFixed['content'] = $theirs;
            Mbe::ensureIds($tdocFixed); // חסר / כפול → מזהה חדש
            $theirs = $tdocFixed['content'];
            $T = self::index($theirs);
            $rf = is_array($returned['flows'][$fid] ?? null) ? $returned['flows'][$fid] : $oursFlow;
            $rf['doc'] = $tdocFixed;
            $returned['flows'][$fid] = $rf;

            $keep = []; $conflict = [];
            $all = array_values(array_unique(array_map('strval', array_merge($B['seq'], $O['seq'], $T['seq']))));
            foreach ($all as $k) {
                $b = $B['map'][$k] ?? null; $ov = $O['map'][$k] ?? null; $t = $T['map'][$k] ?? null;
                $change = function (string $op) use (&$plan, $fid, $k) { $plan['changes'][] = ['flow' => $fid, 'para' => $k, 'op' => $op, 'side' => 'theirs']; };
                $clash = function (string $kind) use (&$plan, &$conflict, $fid, $k, $b, $ov, $t) {
                    $conflict[$k] = true;
                    $plan['conflicts'][] = ['flow' => $fid, 'para' => $k, 'kind' => $kind, 'base' => $b, 'ours' => $ov, 'theirs' => $t];
                };
                if ($b !== null && $ov !== null && $t !== null) {
                    if (self::same($t, $b) || self::same($ov, $t)) $keep[$k] = $ov;
                    elseif (self::same($ov, $b)) { $keep[$k] = $t; $change('edit'); }
                    else $clash('both-edited');
                } elseif ($b !== null && $ov !== null) {
                    if (self::same($ov, $b)) $change('delete'); else $clash('edited-deleted');
                } elseif ($b !== null && $t !== null) {
                    if (!self::same($t, $b)) $clash('edited-deleted');
                } elseif ($ov !== null && $t !== null) {
                    if (self::same($ov, $t)) $keep[$k] = $ov; else $clash('both-edited');
                } elseif ($ov !== null) $keep[$k] = $ov;
                elseif ($t !== null) { $keep[$k] = $t; $change('insert'); }
            }

            $inAll = fn($k) => array_key_exists($k, $B['map']) && array_key_exists($k, $O['map']) && array_key_exists($k, $T['map']);
            $bc = array_values(array_filter($B['seq'], $inAll)); $oc = array_values(array_filter($O['seq'], $inAll)); $tc = array_values(array_filter($T['seq'], $inAll));
            $ourMoved = $oc !== $bc; $theirMoved = $tc !== $bc;
            $useTheirs = $theirMoved && !$ourMoved;
            $primary = $useTheirs ? $T['seq'] : $O['seq']; $other = $useTheirs ? $O['seq'] : $T['seq'];
            if ($theirMoved && $ourMoved && $oc !== $tc) $plan['gaps'][] = "order:$fid — הסדר השתנה בשני הצדדים; נשאר הסדר שלנו";
            if ($useTheirs) {
                $pos = array_flip($bc);
                $stay = [];
                foreach (self::lis(array_map(fn($j) => [$pos[$tc[$j]], $j], array_keys($tc))) as [, $j]) $stay[$tc[$j]] = true;
                foreach ($tc as $k) if (!isset($stay[$k])) $plan['changes'][] = ['flow' => $fid, 'para' => $k, 'op' => 'move', 'side' => 'theirs'];
            }
            $live = fn($k) => array_key_exists($k, $keep) || isset($conflict[$k]);
            $order = array_values(array_filter($primary, $live));
            $placed = array_fill_keys($order, true);
            $last = null;
            foreach ($other as $k) {
                if (isset($placed[$k])) { $last = $k; continue; }
                if (!$live($k)) continue;
                $at = $last === null ? 0 : array_search($last, $order, true) + 1;
                array_splice($order, $at, 0, [$k]);
                $placed[$k] = true;
                $last = $k;
            }
            $blocks = [];
            foreach ($keep as $k => $b) $blocks[(string)$k] = $b;
            $plan['flows'][$fid] = ['order' => $order, 'blocks' => $blocks];
        }
        if ($byContent) $plan['gaps'][] = "merge:by-content — $byContent פסקאות הותאמו לפי תוכן (בלי סימנייה)";

        $styles = $o['styles'] ?? null;
        $names = is_array($styles) ? (array_is_list($styles) ? $styles : array_map('strval', array_keys($styles))) : [];
        $ourStyles = is_array($ours['styles'] ?? null) ? $ours['styles'] : [];
        foreach ($names as $s) if (is_string($s) && !array_key_exists($s, $ourStyles)) $plan['gaps'][] = "style:$s — לא קיים במסמך";
        $walk = function ($layers, int $depth = 0) use (&$walk, &$plan, $f) {
            if (!is_array($layers) || $depth > 200) return;
            foreach ($layers as $l) {
                if (!is_array($l)) continue;
                foreach ([$l['src'] ?? null, $l['source'] ?? null, is_array($l['mask'] ?? null) ? ($l['mask']['src'] ?? null) : null] as $ref) {
                    if (is_string($ref) && !isset($f->files[$ref])) $plan['gaps'][] = 'media:' . ($l['type'] ?? 'layer') . ' ' . ($l['id'] ?? '') . ' — לא בזיכרון';
                }
                $walk($l['children'] ?? null, $depth + 1);
            }
        };
        foreach (is_array($ours['surfaces'] ?? null) ? $ours['surfaces'] : [] as $s) $walk(is_array($s) ? ($s['layers'] ?? null) : null);

        $plan['returned'] = $returned;
        $plan['status'] = !$ff ? 'diverged' : ($plan['changes'] ? 'fast-forward' : 'same');
        return $plan;
    }

    // ---- applyMerge ----

    /** מחיל תוכנית: $answers — ['f1/p17' => 'ours'|'theirs'|'both'] לכל התנגשות. משנה את $f->document; return ו-merge — בשמירה הבאה. */
    public static function applyMerge(MbeFile $f, array $plan, array $answers = []): MbeFile
    {
        $errors = array_values(array_filter($plan['issues'] ?? [], fn($i) => ($i['level'] ?? null) === 'error'));
        if (($plan['status'] ?? null) === 'other-doc' || $errors) throw new MbeError('אי אפשר למזג: ' . implode(', ', array_column($errors, 'code')), $errors);
        $missing = [];
        foreach ($plan['conflicts'] as $c) {
            $p = "{$c['flow']}/{$c['para']}";
            if (!in_array($answers[$p] ?? null, ['ours', 'theirs', 'both'], true)) $missing[] = $p;
        }
        if ($missing) {
            throw new MbeError('יש התנגשויות בלי תשובה: ' . implode(', ', $missing), array_map(fn($p) => self::issue('merge-unanswered', "אין תשובה להתנגשות $p"), $missing));
        }
        if ($plan['status'] === 'same') return $f;
        $byKey = [];
        foreach ($plan['conflicts'] as $c) $byKey["{$c['flow']}/{$c['para']}"] = $c;
        foreach ($plan['flows'] as $fid => $fl) {
            $out = [];
            foreach ($fl['order'] as $k) {
                $c = $byKey["$fid/$k"] ?? null;
                if ($c === null) { $out[] = $fl['blocks'][$k]; continue; }
                $a = $answers["$fid/$k"];
                if ($a !== 'theirs' && $c['ours'] !== null) $out[] = $c['ours'];
                if ($a !== 'ours' && $c['theirs'] !== null) $out[] = $c['theirs'];
            }
            $doc = $f->document['flows'][$fid]['doc'];
            $doc['content'] = $out;
            Mbe::ensureIds($doc); // 'both' על אותה פסקה — השנייה מקבלת מזהה חדש
            $f->document['flows'][$fid]['doc'] = $doc;
        }
        $retBytes = MbeCanonical::encode($plan['returned']);
        $ret = hash('sha256', $retBytes);
        $snapshots = [$ret => $retBytes];
        if (isset($plan['baseDocument'])) $snapshots[$plan['base']] = MbeCanonical::encode($plan['baseDocument']);
        $f->pending[] = ['kind' => 'return', 'app' => $plan['app'], 'who' => $plan['who'], 'note' => null, 'base' => $plan['base'], 'result' => $ret, 'snapshots' => $snapshots];
        $f->pending[] = ['kind' => 'merge', 'who' => $plan['who'], 'note' => $plan['conflicts'] ? count($plan['conflicts']) . ' התנגשויות' : null];
        return $f;
    }
}
