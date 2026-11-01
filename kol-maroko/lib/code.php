<?php
/**
 * קבצי הקוד של העמוד (הכרעת בעל המערכת, 2026-10-25: "כל הקוד ניתן לעריכה ולשינוי לחלוטין, כמו ב-VS Code";
 * JS — כל עורך).
 *
 * שלושה קבצים: lexicon.html — תבנית (Mustache: {{משתנה}}, {{{HTML}}}, {{#רשימה}}…{{/רשימה}}, {{^ריק}}…{{/ריק}}),
 * lexicon.css (גם הוא תבנית — הגדלים מהפאנל), lexicon.js. בלי הקבצים — רכיב המילון מרונדר מהתבנית המובנית,
 * והאתר טוען את ה-CSS וה-JS של ערכת הנושא. עם הקבצים — שלנו במקומם: התוסף מוריד את של ערכת הנושא בעמוד הזה
 * (data-kol-own) ומריץ את שלנו.
 *
 * התבנית אינה קוד שרת: אין בה ביטויים, קריאות או גישה לשום דבר מעבר לנתונים שהיא מקבלת. ה-JS רץ בעמוד באתר.
 * כל שמירה נכנסת להיסטוריה; כל גרסה שומרת עותק של הקבצים ("פרסום מחדש" מחזיר גם אותם).
 */

declare(strict_types=1);

const CODE_FILES = ['lexicon.html' => 'html', 'lexicon.css' => 'css', 'lexicon.js' => 'javascript'];
const CODE_FILE_MAX = 400_000;
const CODE_HISTORY = 50;                 // לכל קובץ
const LEX_CSS_MARK = '/* ── הגדלים מהפאנל (סטודיו ← המילון) ── */';

// ── התבנית ────────────────────────────────────────────────

/** עץ: מחרוזות, ['v', שם, raw], ['s', שם, inverted, ילדים]. שגיאה — עם מספר שורה. */
function tpl_tree(string $t): array
{
    static $cache = [];
    $key = md5($t);
    if (isset($cache[$key])) return $cache[$key];
    $parts = preg_split('/(\{\{\{\s*[\w.]+\s*\}\}\}|\{\{![\s\S]*?\}\}|\{\{[#^\/]?\s*[\w.]+\s*\}\})/u', $t, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_OFFSET_CAPTURE);
    $root = [];
    $stack = [[null, &$root]];
    foreach ($parts as $i => [$s, $off]) {
        $top = &$stack[count($stack) - 1][1];
        if ($i % 2 === 0) { if ($s !== '') $top[] = $s; unset($top); continue; }
        $line = substr_count(substr($t, 0, $off), "\n") + 1;
        if (str_starts_with($s, '{{!')) { unset($top); continue; }
        if (str_starts_with($s, '{{{')) { $top[] = ['v', trim($s, "{} \t"), true]; unset($top); continue; }
        $tag = trim(substr($s, 2, -2));
        $kind = $tag[0];
        $name = trim(in_array($kind, ['#', '^', '/'], true) ? substr($tag, 1) : $tag);
        if ($kind === '#' || $kind === '^') {
            $node = ['s', $name, $kind === '^', []];
            $top[] = $node;
            $stack[] = [[$name, $line], &$top[count($top) - 1][3]];
        } elseif ($kind === '/') {
            $open = $stack[count($stack) - 1][0];
            if (!$open || $open[0] !== $name) throw new ApiError("התבנית: שורה $line — {{/$name}} בלי {{#$name}} פתוח" . ($open ? " (פתוח: {{#{$open[0]}}} משורה {$open[1]})" : ''));
            array_pop($stack);
        } else {
            $top[] = ['v', $name, false];
        }
        unset($top);
    }
    if (count($stack) > 1) {
        $open = $stack[count($stack) - 1][0];
        throw new ApiError("התבנית: {{#{$open[0]}}} משורה {$open[1]} לא נסגר");
    }
    return $cache[$key] = $root;
}

function tpl_render(string $t, array $ctx): string
{
    return tpl_run(tpl_tree($t), [$ctx]);
}

function tpl_get(string $name, array $stack): mixed
{
    if ($name === '.') return end($stack);
    for ($i = count($stack) - 1; $i >= 0; $i--) {
        if (is_array($stack[$i]) && array_key_exists($name, $stack[$i])) return $stack[$i][$name];
    }
    return null;
}

function tpl_run(array $nodes, array $stack): string
{
    $out = '';
    foreach ($nodes as $n) {
        if (is_string($n)) { $out .= $n; continue; }
        $v = tpl_get($n[1], $stack);
        if ($n[0] === 'v') {
            $s = is_scalar($v) ? (is_bool($v) ? ($v ? '1' : '') : (string)$v) : '';
            $out .= $n[2] ? $s : e($s);
            continue;
        }
        $empty = $v === null || $v === false || $v === '' || $v === 0 || $v === [];
        if ($n[2]) { if ($empty) $out .= tpl_run($n[3], $stack); continue; }
        if ($empty) continue;
        if (is_array($v) && array_is_list($v)) {
            foreach ($v as $item) $out .= tpl_run($n[3], [...$stack, $item]);
        } else {
            $out .= tpl_run($n[3], [...$stack, $v]);
        }
    }
    return $out;
}

// ── הקבצים ────────────────────────────────────────────────

/** null — אין קבצים (התבנית המובנית). בגרסה שמורה — העותק שלה. */
function code_files(): ?array
{
    if (array_key_exists('kol_code', $GLOBALS)) return $GLOBALS['kol_code'];
    $rows = db()->query('SELECT path, content, updated_at, updated_by FROM code_files')->fetchAll();
    if (!$rows) return null;
    $out = [];
    foreach ($rows as $r) $out[$r['path']] = $r['content'];
    return $out;
}

function code_snapshot(): ?string
{
    $c = code_files();
    return $c === null ? null : json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function code_state(): array
{
    $files = [];
    foreach (db()->query('SELECT path, content, updated_at, updated_by FROM code_files')->fetchAll() as $r) {
        $files[$r['path']] = ['content' => $r['content'], 'rev' => $r['updated_at'], 'by' => $r['updated_by'], 'lang' => CODE_FILES[$r['path']] ?? 'plaintext'];
    }
    return ['active' => (bool)$files, 'files' => $files, 'help' => LEX_TPL_HELP];
}

/** יצירת הקבצים מהעמוד כמו שהוא: התבנית המובנית, ה-CSS וה-JS של ערכת הנושא (מהייבוא) והגדלים. */
function code_create(array $user): array
{
    if (code_files() !== null) throw new ApiError('הקבצים כבר קיימים');
    $files = ['lexicon.html' => LEX_TPL, 'lexicon.css' => theme_lexicon_css() . "\n\n" . LEX_CSS_MARK . "\n" . LEX_TPL_SIZES, 'lexicon.js' => (string)setting('preview_js', '')];
    $st = db()->prepare('INSERT INTO code_files (path, content, updated_at, updated_by) VALUES (?, ?, ?, ?)');
    foreach ($files as $p => $c) $st->execute([$p, $c, now_iso(), $user['name']]);
    audit_as($user, 'code_create', 'קבצי הקוד של העמוד');
    return code_state();
}

/** ה-CSS של המילון מערכת הנושא. ייבוא חדש שומר אותו בנפרד; בייבוא ישן הוא בסוף preview_css (אחרי style.css). */
function theme_lexicon_css(): string
{
    if (($c = setting('theme_lexicon_css')) !== null) return $c;
    $all = (string)setting('preview_css', '');
    $at = mb_strpos($all, '/**' . "\n" . ' * קול מרוקו - עיצוב עמוד המילון');
    return $at === false ? "/* ה-CSS של המילון לא נמצא בייבוא. אפשר להדביק כאן את lexicon.css מערכת הנושא. */" : mb_substr($all, $at);
}

function code_save(array $user, array $b): array
{
    $path = (string)($b['path'] ?? '');
    if (!isset(CODE_FILES[$path])) throw new ApiError('קובץ לא מוכר');
    $content = (string)($b['content'] ?? '');
    if (strlen($content) > CODE_FILE_MAX) throw new ApiError('הקובץ גדול מ-400KB');
    if (!mb_check_encoding($content, 'UTF-8')) throw new ApiError('הקובץ אינו UTF-8');
    if ($path !== 'lexicon.js') tpl_tree($content);               // תבנית שבורה — לא נשמרת (עם מספר השורה)
    $pdo = db();
    $st = $pdo->prepare('SELECT content, updated_at, updated_by FROM code_files WHERE path = ?');
    $st->execute([$path]);
    $cur = $st->fetch();
    if (!$cur) throw new ApiError('אין עדיין קבצי קוד — "יצירת הקבצים" קודם', 409);
    if (isset($b['rev']) && $b['rev'] !== $cur['updated_at']) {
        throw new ApiError("הקובץ השתנה בינתיים ({$cur['updated_by']}). טענו מחדש לפני שמירה", 409, ['rev' => $cur['updated_at']]);
    }
    if ($cur['content'] === $content) return ['rev' => $cur['updated_at']];
    $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO code_history (path, content, at, by_name) VALUES (?, ?, ?, ?)')->execute([$path, $cur['content'], $cur['updated_at'], $cur['updated_by']]);
    $pdo->prepare('DELETE FROM code_history WHERE path = ? AND id NOT IN (SELECT id FROM code_history WHERE path = ? ORDER BY id DESC LIMIT ' . CODE_HISTORY . ')')->execute([$path, $path]);
    $rev = now_iso() . '.' . substr(bin2hex(random_bytes(3)), 0, 4);         // שתי שמירות באותה שנייה — rev שונה
    $pdo->prepare('UPDATE code_files SET content = ?, updated_at = ?, updated_by = ? WHERE path = ?')->execute([$content, $rev, $user['name'], $path]);
    $pdo->commit();
    audit_as($user, 'code_save', $path);
    return ['rev' => $rev];
}

function code_history(array $q): array
{
    $st = db()->prepare('SELECT id, at, by_name, length(content) AS size FROM code_history WHERE path = ? ORDER BY id DESC');
    $st->execute([(string)($q['path'] ?? '')]);
    return ['history' => $st->fetchAll()];
}

function code_history_get(array $q): array
{
    $st = db()->prepare('SELECT path, content, at, by_name FROM code_history WHERE id = ?');
    $st->execute([(int)($q['id'] ?? 0)]);
    return ['item' => $st->fetch() ?: throw new ApiError('לא נמצא', 404)];
}

/** חזרה לתבנית המובנית: הקבצים נמחקים (ההיסטוריה שלהם נשארת), והאתר חוזר לקבצים של ערכת הנושא בפרסום הבא. */
function code_reset(array $user): array
{
    snapshot_create($user, 'לפני חזרה לתבנית המובנית');
    $pdo = db();
    $pdo->beginTransaction();
    foreach ($pdo->query('SELECT path, content, updated_at, updated_by FROM code_files')->fetchAll() as $r) {
        $pdo->prepare('INSERT INTO code_history (path, content, at, by_name) VALUES (?, ?, ?, ?)')->execute([$r['path'], $r['content'], $r['updated_at'], $r['updated_by']]);
    }
    $pdo->exec('DELETE FROM code_files');
    $pdo->commit();
    audit_as($user, 'code_reset', 'חזרה לתבנית המובנית');
    return code_state();
}

// ── הנתונים שהתבנית מקבלת ─────────────────────────────────

/** ההקשר של התבנית: ההגדרות מהפאנל, הגדלים המחושבים, המבוא, הנושאים והמילים. */
function lexicon_context(array $p, array $words, array $topics, array &$map, callable $audioUrl, string $hero, bool $editor): array
{
    $sub = $p['subtitle_size'];
    $main = $p['subtitle_main_size'] > 0 ? $p['subtitle_main_size'] : max(14, (int)round($p['title_size'] * 0.6));
    $gates = [];
    if ($p['intro_before'] !== '') $gates[] = ['label' => $p['gate_before'], 'html' => $p['intro_before']];
    if ($p['intro_after'] !== '') $gates[] = ['label' => $p['gate_after'], 'html' => $p['intro_after']];
    if (count($gates) === 1 && $p['intro_after'] === '') $gates[0]['label'] = $p['gate_after'];
    foreach ($gates as $i => &$g) { $g['i'] = $i; $g['more'] = $i === 0 && count($gates) > 1; }
    unset($g);
    $low = fn(string $t) => mb_strtolower(html_entity_decode($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), 'UTF-8');
    $list = [];
    foreach ($editor ? array_slice($words, 0, LEX_PREVIEW_CARDS) : $words as $w) {
        $plain = trim(preg_replace('/\s+/u', ' ', strip_tags(str_replace(['</p>', '<br>'], [' </p>', ' <br>'], $w['meaning']))));
        $list[] = [
            'id' => $w['id'], 'wp_id' => $w['wp_id'] ?? '', 'data_id' => (string)($w['wp_id'] ?? 'k' . $w['id']), 'word' => $w['word'],
            'search' => $low($w['word']), 'search_full' => $low($w['word'] . ' ' . $plain . ' ' . implode(' ', $w['topics'])),
            'slugs' => implode(' ', array_map(fn($t) => topic_slug($t, $map), $w['topics'])),
            'topics' => array_map(fn($t) => ['name' => $t], $w['topics']), 'has_topics' => (bool)$w['topics'], 'single' => count($w['topics']) === 1,
            'meaning' => $w['meaning'], 'audio' => $w['audio'] === '' ? '' : (file_id($w['audio']) ? $audioUrl($w['audio']) : $w['audio']),
            'ext_id' => $p['show_ids'] ? $w['external_id'] : '', 'feedback_id' => $p['feedback'] && $w['wp_id'] ? (string)$w['wp_id'] : '',
        ];
    }
    $n = count($words);
    return $p + [
        'hero' => $hero, 'eyebrow' => $p['title_fr'] !== '' || $p['title_en'] !== '',
        'gates' => $gates, 'has_gates' => (bool)$gates, 'intro_style' => $p['intro_max_width'] > 0 ? "max-width:{$p['intro_max_width']}px;" : '',
        'topics' => $topics, 'has_topics' => (bool)$topics, 'count' => $n, 'empty' => !$words, 'words' => $list, 'editor' => $editor,
        'preview_note' => $editor && $n > LEX_PREVIEW_CARDS
            ? '<p class="kol-missing">כאן בתצוגה — ' . LEX_PREVIEW_CARDS . ' המילים הראשונות. באתר יופיעו כל ' . $n . ' המילים. עריכת המילים: 📚 מילים</p>' : '',
        // ל-CSS
        'hero_min_css' => $p['hero_min_height'] > 0 ? "min-height:{$p['hero_min_height']}px;display:flex;align-items:center;justify-content:center;" : '',
        'credit_size' => max(11, (int)round($sub * 0.75)), 'main_size' => $main,
        'm_title_size' => max(20, (int)round($p['title_size'] * 0.7)), 'm_main_size' => max(14, (int)round($main * 0.8)),
        'm_subtitle_size' => max(12, (int)round($sub * 0.85)), 'm_title_en_size' => max(10, (int)round($p['title_en_size'] * 0.8)),
        'm_credit_size' => max(10, (int)round($sub * 0.85 * 0.75)),
    ];
}

// ── התבנית המובנית — אותו HTML כמו page-kadisha-lexicon.php של ערכת הנושא ───────

const LEX_TPL = <<<'HTML'
<div class="kadisha-page kadisha-lexicon-page" dir="rtl" lang="he">
<section class="kadisha-hero{{#hero}} kadisha-hero--has-image{{/hero}}" aria-labelledby="lexicon-hero-title"{{#hero}} style="background-image:url('{{hero}}'); --ka-hero-overlay:{{hero_overlay}};"{{/hero}}>
  <div class="kadisha-container">
    <div class="kadisha-hero-inner">
      <h1 id="lexicon-hero-title" class="kadisha-hero-title">{{title_he}}</h1>
      {{#subtitle_main_he}}<p class="kadisha-hero-subtitle-main">{{subtitle_main_he}}</p>{{/subtitle_main_he}}
      {{#eyebrow}}<span class="kadisha-hero-eyebrow kadisha-hero-eyebrow--under" dir="ltr">{{#title_fr}}<span class="kadisha-hero-eyebrow-line">{{title_fr}}</span>{{/title_fr}}{{#title_en}}<span class="kadisha-hero-eyebrow-line">{{title_en}}</span>{{/title_en}}</span>{{/eyebrow}}
      {{#subtitle_he}}<p class="kadisha-hero-subtitle">{{subtitle_he}}</p>{{/subtitle_he}}
      {{#credit_he}}<p class="kadisha-hero-subtitle kadisha-hero-credit">{{credit_he}}</p>{{/credit_he}}
      {{#has_gates}}
      <div class="kadisha-lex-hero-actions">
        {{#gates}}<button type="button" class="kadisha-lex-hero-btn kadisha-lex-gate" data-panel="kadisha-lex-panel-{{i}}" aria-expanded="false" aria-controls="kadisha-lex-panel-{{i}}"><span class="kadisha-lex-hero-btn-icon" aria-hidden="true">📖</span><span class="kadisha-lex-hero-btn-text">{{label}}</span></button>{{/gates}}
      </div>
      {{/has_gates}}
    </div>
  </div>
</section>

<section class="kadisha-section" id="lexicon-main">
  <div class="kadisha-container">
    {{#gates}}
    <div class="kadisha-lexicon-intro kadisha-lex-section-panel" id="kadisha-lex-panel-{{i}}" style="{{intro_style}}" hidden>
      <div class="kadisha-lexicon-intro-teaser">{{{html}}}</div>
      {{#more}}<button type="button" class="kadisha-lexicon-intro-toggle kadisha-lex-gate" data-panel="kadisha-lex-panel-1" aria-expanded="false" aria-controls="kadisha-lex-panel-1" data-more-text="{{more_label}}" data-less-text="{{less_label}}">{{more_label}} <span aria-hidden="true">↓</span></button>{{/more}}
    </div>
    {{/gates}}

    {{#feedback}}
    <div class="kadisha-lex-feedback-modal" id="kadisha-lex-feedback-modal" hidden role="dialog" aria-modal="true" aria-labelledby="kadisha-lex-feedback-title">
      <div class="kadisha-lex-feedback-backdrop" data-klf-close="1"></div>
      <div class="kadisha-lex-feedback-window">
        <header class="kadisha-lex-feedback-head">
          <h2 id="kadisha-lex-feedback-title">שלח משוב על המילה</h2>
          <button type="button" class="kadisha-lex-feedback-close" data-klf-close="1" aria-label="סגור">×</button>
        </header>
        <form class="kadisha-lex-feedback-form" id="kadisha-lex-feedback-form" novalidate>
          <div class="kadisha-lex-feedback-word"><span class="kadisha-lex-feedback-word-label">המילה:</span> <strong id="kadisha-lex-feedback-word-name"></strong></div>
          <label class="kadisha-lex-feedback-field"><span>שם (אופציונלי)</span><input type="text" name="name" id="kadisha-lex-feedback-name" autocomplete="name" maxlength="120"></label>
          <label class="kadisha-lex-feedback-field"><span>אימייל (אופציונלי — אם תרצו תשובה)</span><input type="email" name="email" id="kadisha-lex-feedback-email" autocomplete="email" dir="ltr" maxlength="160"></label>
          <label class="kadisha-lex-feedback-field"><span>ההערה / התיקון / התוספת <em>*</em></span><textarea name="message" id="kadisha-lex-feedback-message" rows="5" required minlength="3" maxlength="3000" placeholder="כתבו כאן הערה לתיקון או תוספת על המילה..."></textarea></label>
          <div class="kadisha-lex-feedback-honeypot" aria-hidden="true"><label>אל תמלאו שדה זה<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
          <footer class="kadisha-lex-feedback-foot">
            <button type="button" class="kadisha-lex-feedback-cancel" data-klf-close="1">ביטול</button>
            <button type="submit" class="kadisha-lex-feedback-send" id="kadisha-lex-feedback-send"><span aria-hidden="true">📨</span> שלח משוב</button>
            <span class="kadisha-lex-feedback-status" id="kadisha-lex-feedback-status" aria-live="polite"></span>
          </footer>
        </form>
      </div>
    </div>
    {{/feedback}}

    <div class="kadisha-lexicon-controls" id="kadisha-lex-panel-search" role="search">
      <label for="kadisha-lex-search" class="screen-reader-text">חיפוש מילה</label>
      <div class="kadisha-lexicon-search-wrap">
        <input id="kadisha-lex-search" type="search" autocomplete="off" placeholder="חפש מילה..." class="kadisha-lexicon-search">
        <span class="kadisha-lexicon-search-icon" aria-hidden="true">🔎</span>
      </div>
      <label class="kadisha-lex-scope"><input type="checkbox" id="kadisha-lex-scope-full"><span>הרחב את החיפוש גם לפירוש ולנושאים</span></label>
      {{#has_topics}}
      <div class="kadisha-lexicon-topic-filter" role="group" aria-label="סינון לפי נושא">
        <button type="button" class="kadisha-lex-topic-btn is-active" data-topic="">הכל</button>
        {{#topics}}<button type="button" class="kadisha-lex-topic-btn" data-topic="{{slug}}">{{name}} <span class="kadisha-lex-topic-count">{{count}}</span></button>{{/topics}}
      </div>
      {{/has_topics}}
      <div class="kadisha-lexicon-count" aria-live="polite"><span id="kadisha-lex-shown">{{count}}</span> מתוך <span id="kadisha-lex-total">{{count}}</span> מילים</div>
    </div>

    {{#empty}}<p class="kadisha-lexicon-empty">המאגר עוד ריק. מילים יתווספו בהקדם.</p>{{/empty}}

    <div class="kadisha-lexicon-grid" id="kadisha-lex-grid">
      {{#words}}
      <article class="kadisha-word-card" data-id="{{data_id}}" data-search="{{search}}" data-search-full="{{search_full}}" data-topics="{{slugs}}">
        <header class="kadisha-word-head">
          <h3 class="kadisha-word-title">{{word}}</h3>
          {{#ext_id}}<span class="kadisha-word-extid" title="ID מהאקסל (זמני לפיתוח)">#{{ext_id}}</span>{{/ext_id}}
          <div class="kadisha-word-actions">
            {{#audio}}<button type="button" class="kadisha-word-audio-btn" data-audio-src="{{audio}}" aria-label="השמע את המילה {{word}}" title="השמע"><span class="kadisha-word-audio-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor"><path d="M8 5v14l11-7z"/></svg></span></button>{{/audio}}
          </div>
        </header>
        {{#has_topics}}<div class="kadisha-word-topics{{#single}} is-single{{/single}}">{{#topics}}<span class="kadisha-word-topic">{{name}}</span>{{/topics}}</div>{{/has_topics}}
        <div class="kadisha-word-meaning">{{{meaning}}}</div>
        {{#feedback_id}}<footer class="kadisha-word-footer"><button type="button" class="kadisha-word-feedback-btn" data-word-id="{{feedback_id}}" data-word-title="{{word}}" aria-label="שלח משוב על המילה {{word}}"><span aria-hidden="true">💬</span><span class="kadisha-word-feedback-label">שלח משוב</span></button></footer>{{/feedback_id}}
      </article>
      {{/words}}
    </div>
    {{{preview_note}}}
    <p class="kadisha-lexicon-empty" id="kadisha-lex-no-matches" style="display:none;">לא נמצאו מילים להתאמת החיפוש.</p>
  </div>
</section>
</div>
HTML;

const LEX_TPL_SIZES = <<<'CSS'
.kadisha-lexicon-page .kadisha-hero { {{{hero_min_css}}} }
.kadisha-lexicon-page .kadisha-hero-title,
.kadisha-lexicon-page .kadisha-hero h1 { font-size: {{title_size}}px !important; font-weight: {{title_weight}} !important; line-height: 1.15; margin-bottom: 0 !important; }
.kadisha-lexicon-page .kadisha-hero-subtitle { font-size: {{subtitle_size}}px !important; font-weight: {{title_weight}} !important; }
.kadisha-lexicon-page .kadisha-hero-credit { font-size: {{credit_size}}px !important; margin-top: 0 !important; line-height: 1.25 !important; opacity: .92; }
.kadisha-lexicon-page .kadisha-hero-subtitle:has(+ .kadisha-hero-credit) { margin-bottom: 0 !important; line-height: 1.25 !important; }
@supports not selector(:has(*)) { .kadisha-lexicon-page .kadisha-hero-subtitle { margin-bottom: 0 !important; } }
.kadisha-lexicon-page .kadisha-hero-subtitle-main {
    font-family: 'Frank Ruhl Libre', 'David Libre', serif !important; font-size: {{main_size}}px !important; font-weight: {{title_weight}} !important;
    line-height: 1.1 !important; color: var(--ka-cream, #f5ebd6) !important; text-shadow: 0 2px 8px rgba(0,0,0,.3); margin: -.1em 0 1.1rem !important; padding: 0 !important; letter-spacing: .01em;
}
.kadisha-lexicon-page .kadisha-hero-eyebrow--under { font-size: {{title_en_size}}px !important; margin-top: 0; margin-bottom: .6rem; padding: .15rem 1.5rem; }
@media (max-width: 600px) {
    .kadisha-lexicon-page .kadisha-hero-title, .kadisha-lexicon-page .kadisha-hero h1 { font-size: {{m_title_size}}px !important; }
    .kadisha-lexicon-page .kadisha-hero-subtitle-main { font-size: {{m_main_size}}px !important; }
    .kadisha-lexicon-page .kadisha-hero-subtitle { font-size: {{m_subtitle_size}}px !important; }
    .kadisha-lexicon-page .kadisha-hero-eyebrow--under { font-size: {{m_title_en_size}}px !important; }
    .kadisha-lexicon-page .kadisha-hero-credit { font-size: {{m_credit_size}}px !important; }
}
CSS;

const LEX_TPL_HELP = [
    'כללי' => '{{שם}} — טקסט (מוגן). {{{שם}}} — HTML כמו שהוא. {{#שם}}…{{/שם}} — רשימה (חוזר לכל פריט) או "אם יש". {{^שם}}…{{/שם}} — "אם אין". {{! הערה }}.',
    'מהפאנל' => 'title_he, subtitle_main_he, title_fr, title_en, subtitle_he, credit_he, gate_before, gate_after, more_label, less_label, hero_overlay, hero_min_height, title_size, subtitle_size, title_en_size, title_weight, intro_max_width, feedback, show_ids',
    'מחושב' => 'hero (כתובת התמונה), eyebrow, has_gates, intro_style, count, empty, has_topics, editor (בסטודיו), preview_note; ל-CSS: hero_min_css, main_size, credit_size, m_title_size, m_main_size, m_subtitle_size, m_title_en_size, m_credit_size',
    'gates (המבוא)' => 'i, label, html, more',
    'topics (הנושאים)' => 'slug, name, count',
    'words (המילים)' => 'id, wp_id, data_id, word, search, search_full, slugs, topics (name), has_topics, single, meaning (HTML), audio (כתובת), ext_id, feedback_id',
];

// ── אבטחה: מה שנכתב בכלי לא רץ באתר (הכרעה 2026-10-25, סגירת החור שמצא צ'אט האפיון) ─────────
//
// HTML ו-CSS מהקבצים נשארים בעמוד (גוגל סורק את המילים), אבל מנוקים: בלי סקריפט, בלי on*, בלי javascript:, בלי
// מסגרות, ו-CSS שחל רק בתוך המילון. JS שנערך בכלי — לעולם לא באתר עצמו: המילון כולו עובר למסגרת מבודדת
// (sandbox בלי allow-same-origin), ושם אין גישה לעוגיות, ל-parent, ל-wp-admin או ל-admin-ajax. הגשר היחיד
// החוצה — שליחת משוב על מילה, בשדות קבועים (התוסף, wordpress-plugin).

const TPL_DROP = ['script', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'base', 'meta', 'link', 'style', 'noscript', 'template', 'portal'];
const TPL_URL_ATTRS = ['href', 'src', 'action', 'formaction', 'poster', 'background', 'xlink:href', 'data', 'cite', 'srcset', 'ping'];

/** ה-HTML שהתבנית הפיקה — בלי שום דרך להריץ קוד בדף של האתר. */
function tpl_clean_html(string $html): string
{
    // {{KOL_ASSETS}} — saveHTML מקודד { } ב-src/href; כתובת זמנית, ובחזרה בסוף
    $tok = 'https://kol-maroko-assets.invalid';
    $html = str_replace('{{KOL_ASSETS}}', $tok, $html);
    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><body><div id="kol-tpl-root">' . $html . '</div></body>', LIBXML_NONET | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    $root = $doc->getElementById('kol-tpl-root');
    if (!$root) return '';
    $xp = new DOMXPath($doc);
    foreach (iterator_to_array($xp->query('.//comment()', $root)) as $c) $c->parentNode->removeChild($c);
    foreach (iterator_to_array($root->getElementsByTagName('*')) as $el) {
        /** @var DOMElement $el */
        if (!$el->parentNode) continue;
        $tag = strtolower($el->localName ?? $el->tagName);
        if (in_array($tag, TPL_DROP, true)) { $el->parentNode->removeChild($el); continue; }
        foreach (iterator_to_array($el->attributes) as $a) {
            $n = strtolower($a->name);
            $v = $a->value;
            $bad = str_starts_with($n, 'on') || in_array($n, ['srcdoc', 'formaction', 'http-equiv', 'is'], true)
                || ($tag === 'form' && in_array($n, ['action', 'method', 'target'], true))
                || (in_array($n, TPL_URL_ATTRS, true) && tpl_bad_url($v))
                || ($n === 'style' && preg_match('/expression\s*\(|javascript:|vbscript:|behavior\s*:|-moz-binding|@import|url\s*\(\s*["\']?+\s*(?!https:|#|data:image\/|\{\{KOL_ASSETS\}\}|api\.php\?action=media&)/i', $v));
            if ($bad) { $el->removeAttribute($a->name); continue; }
            // ה-JS של ערכת הנושא מכניס ערכי data-* ב-innerHTML (קרא עוד / הסתר) — רק טקסט
            if (str_starts_with($n, 'data-') && preg_match('/[<>]/', $v)) $el->setAttribute($a->name, str_replace(['<', '>'], '', $v));
        }
        if ($tag === 'a' && $el->getAttribute('target') === '_blank') $el->setAttribute('rel', 'noopener noreferrer');
    }
    $out = '';
    foreach ($root->childNodes as $n) $out .= $doc->saveHTML($n);
    return str_replace($tok, '{{KOL_ASSETS}}', $out);
}

function tpl_bad_url(string $v): bool
{
    $u = strtolower(preg_replace('/[\x00-\x20\x7f]+/', '', html_entity_decode($v, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    if (preg_match('/^data:image\/(png|jpe?g|gif|webp);/', $u)) return false;
    return (bool)preg_match('/^(javascript|vbscript|data|blob|filesystem):/', $u);
}

/**
 * CSS שחל רק בתוך המילון: כל בורר מקבל את המכל בתחילתו; html / body / :root הופכים למכל עצמו. @import נופל,
 * ובלי expression / javascript: / url() שאינו https.
 */
function css_scope(string $css, string $scope = '.kadisha-lexicon-page'): string
{
    $css = preg_replace('~/\*.*?\*/~s', '', $css);
    $css = preg_replace('/@import[^;]*;?/i', '', $css);
    $css = preg_replace('/expression\s*\(|javascript:|vbscript:|behavior\s*:|-moz-binding/i', '', $css);
    $css = preg_replace_callback('/url\(\s*(["\']?)([^)"\']*)\1\s*\)/i', fn($m) => preg_match('~^(https://|data:(image|font)/|#|\{\{KOL_ASSETS\}\}|api\.php\?action=media&)~i', trim($m[2])) ? $m[0] : 'none', $css);
    return css_scope_block($css, $scope);
}

function css_scope_block(string $css, string $scope): string
{
    $out = '';
    $i = 0;
    $len = strlen($css);
    while ($i < $len) {
        $open = strpos($css, '{', $i);
        if ($open === false) break;
        $sel = trim(substr($css, $i, $open - $i));
        // סוף הבלוק — לפי איזון סוגריים
        $depth = 0;
        for ($j = $open; $j < $len; $j++) {
            if ($css[$j] === '{') $depth++;
            elseif ($css[$j] === '}' && --$depth === 0) break;
        }
        $body = substr($css, $open + 1, $j - $open - 1);
        $i = $j + 1;
        if ($sel === '') continue;
        if (preg_match('/^@(media|supports|container|layer)\b/i', $sel)) {
            $out .= $sel . '{' . css_scope_block($body, $scope) . '}';
        } elseif (preg_match('/^@(font-face|keyframes|-webkit-keyframes|property|counter-style)\b/i', $sel)) {
            $out .= $sel . '{' . $body . '}';
        } elseif ($sel[0] === '@') {
            continue;                                                   // @page, @namespace… — לא נחוצים כאן
        } else {
            $sels = array_map(function ($s) use ($scope) {
                $s = trim($s);
                if ($s === '') return '';
                if (str_starts_with($s, $scope)) return $s;
                // body.x → המכל.x; body .x → המכל .x
                if (preg_match('/^(?:html|body|:root)(?![\w-])(\s*)(.*)$/is', $s, $m)) return $m[2] === '' ? $scope : $scope . ($m[1] !== '' ? ' ' : '') . $m[2];
                return $scope . ' ' . $s;
            }, explode(',', $sel));
            $out .= implode(',', array_filter($sels)) . '{' . $body . '}';
        }
    }
    return $out;
}

/** ה-CSS של ערכת הנושא (style.css) — רק למסגרת המבודדת, שאין בה את מה שהאתר טוען. */
function theme_style_css(): string
{
    if (($c = setting('theme_style_css')) !== null) return $c;
    $all = (string)setting('preview_css', '');
    $from = mb_strpos($all, 'חברה קדישא המערבית - CSS');
    if ($from === false) return '';
    $from = (int)mb_strrpos(mb_substr($all, 0, $from), '/*');
    $to = mb_strpos($all, '/**' . "\n" . ' * קול מרוקו - עיצוב עמוד המילון');
    return $to === false || $to < $from ? mb_substr($all, $from) : mb_substr($all, $from, $to - $from);
}

/**
 * המילון במסגרת מבודדת — כשה-JS נערך בכלי. sandbox בלי allow-same-origin: מקור אטום, בלי עוגיות, בלי parent/top,
 * בלי localStorage. בפנים: העיצוב של ערכת הנושא, הקבצים, וגשר קבוע (לא נערך): localStorage בזיכרון, kadishaLex שמפנה
 * את שליחת המשוב להודעה לדף (התוסף שולח אותה בשדות קבועים), גובה, ומיקום החלונות הקופצים מול המסך.
 */
function lexicon_frame(string $id, string $html, string $css, string $js, array $p): string
{
    $cfg = json_encode(['id' => $id, 'feedback' => $p['feedback'] ? 1 : 0, 'more' => $p['more_label'], 'less' => $p['less_label']], JSON_UNESCAPED_UNICODE);
    $esc = fn(string $s) => str_ireplace(['</script', '</style'], ['<\\/script', '<\\/style'], $s);
    $doc = '<!doctype html><html lang="he" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<base target="_blank"><link rel="stylesheet" href="' . LEX_FONTS . '">'
        . '<style>html,body{margin:0;background:transparent}' . $esc(theme_style_css()) . "\n" . $esc($css)
        . '.kadisha-lex-feedback-modal,.kadisha-lex-modal{position:absolute!important;top:var(--kol-vt,0)!important;height:var(--kol-vh,100vh)!important;bottom:auto!important}</style>'
        . '<script>' . str_replace('__CFG__', $cfg, LEX_FRAME_SHIM) . '</script></head><body class="page-kadisha">' . $html
        . ($js !== '' ? '<script>' . $esc($js) . '</script>' : '') . '</body></html>';
    return '<iframe class="kol-lex-frame" data-kol-frame="kadisha-lexicon" data-kol-id="' . e($id) . '" title="קול מרוקו"'
        . ' sandbox="allow-scripts allow-popups allow-popups-to-escape-sandbox allow-forms" referrerpolicy="no-referrer"'
        . ' style="display:block;width:100%;border:0;height:2400px" srcdoc="' . e($doc) . '"></iframe>';
}

const LEX_FRAME_SHIM = <<<'JS'
(function(C){
  var mem={};
  try{Object.defineProperty(window,'localStorage',{configurable:true,value:{getItem:function(k){return Object.prototype.hasOwnProperty.call(mem,k)?mem[k]:null},setItem:function(k,v){mem[k]=String(v)},removeItem:function(k){delete mem[k]},clear:function(){mem={}}}});}catch(e){}
  window.kadishaLex={ajaxurl:'kol-bridge:feedback',canEdit:0,nonce:'',canEditIntro:0,introNonce:'',introSections:[],introMoreAfter:-1,
    introMoreLabel:C.more,introLessLabel:C.less,feedbackEnabled:C.feedback,feedbackNonce:'-'};
  var n=0,wait={},of=window.fetch;
  window.fetch=function(u,o){
    if(String(u).indexOf('kol-bridge:')!==0)return of.apply(this,arguments);
    var x={};if(o&&o.body&&o.body.forEach)o.body.forEach(function(v,k){x[k]=String(v)});
    var rid='r'+(++n);parent.postMessage({kolFeedback:{word_id:x.word_id,name:x.name,email:x.email,message:x.message,website:x.website},rid:rid,frame:C.id},'*');
    return new Promise(function(ok){wait[rid]=function(d){ok(new Response(d.body||'{}',{status:d.status||502,headers:{'Content-Type':'application/json'}}))}});
  };
  addEventListener('message',function(e){var d=e.data||{};
    if(d.kolFeedbackReply&&wait[d.kolFeedbackReply]){wait[d.kolFeedbackReply](d);delete wait[d.kolFeedbackReply];}
    if(d.kolView){var r=document.documentElement.style;r.setProperty('--kol-vt',Math.max(0,+d.kolView.top||0)+'px');r.setProperty('--kol-vh',Math.max(200,+d.kolView.h||0)+'px');}
  });
  var last=0,send=function(){var h=Math.ceil(document.body?document.body.getBoundingClientRect().height:0);if(h&&h!==last){last=h;parent.postMessage({kolFrame:C.id,h:h},'*');}};
  addEventListener('load',send);addEventListener('DOMContentLoaded',function(){send();if(window.ResizeObserver)new ResizeObserver(send).observe(document.body);});
  setInterval(send,1500);
})(__CFG__);
JS;
